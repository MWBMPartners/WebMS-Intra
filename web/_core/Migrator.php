<?php
// Path: core/Migrator.php
/**
 * -----------------------------------------------------------------------------
 * Web-Based SQL Migration Runner 🔄
 * -----------------------------------------------------------------------------
 * Reads SQL migration files from the sql/ directory, tracks which have been
 * executed in tblMigrations, and runs pending ones in filename order. Designed
 * for environments without CLI access (e.g. DreamHost shared hosting).
 *
 * Usage:
 *   $migrator = new Migrator($mysqli);
 *   $pending  = $migrator->pending();       // list pending migration filenames
 *   $results  = $migrator->runAll($userId); // execute all pending migrations
 *
 * The migrations table (tblMigrations) is auto-created on first use if it does
 * not exist, using sql/000_create_migrations_table.sql or inline DDL fallback.
 *
 * @see       https://dev.mysql.com/doc/refman/8.0/en/sql-syntax.html
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;
use RuntimeException;

class Migrator
{
    /** @var mysqli Database connection */
    private mysqli $db;

    /** @var string Absolute path to the sql/ directory */
    private string $sqlDir;

    /**
     * Constructor.
     *
     * @param mysqli $db    Active MySQLi connection
     * @param string $sqlDir Override path to sql/ directory (optional, defaults to PORTAL_ROOT/sql)
     */
    public function __construct(mysqli $db, string $sqlDir = '')
    {
        $this->db = $db;

        // 📂 Determine _sql/ directory path using platform-neutral constants
        if ($sqlDir !== '') {
            $this->sqlDir = rtrim($sqlDir, DIRECTORY_SEPARATOR);
        } else {
            $this->sqlDir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_sql';
        }

        // 🛡️ Ensure the migrations tracking table exists
        $this->ensureMigrationsTable();
    }

    /**
     * Ensure tblMigrations exists in the database.
     * Uses a simple CREATE TABLE IF NOT EXISTS so this works even before
     * the first migration (000) has been run.
     *
     * @return void
     */
    private function ensureMigrationsTable(): void
    {
        // 🔍 Check if tblMigrations already exists to avoid unnecessary DDL
        $result = $this->db->query("SHOW TABLES LIKE 'tblMigrations'");
        if ($result !== false && $result->num_rows > 0) {
            $result->free();
            return;
        }
        if ($result !== false) {
            $result->free();
        }

        // 📝 Create the table inline (mirrors sql/000_create_migrations_table.sql)
        $ddl = "CREATE TABLE IF NOT EXISTS `tblMigrations` (
            `migrationID`   INT          NOT NULL AUTO_INCREMENT,
            `filename`      VARCHAR(255) NOT NULL,
            `executedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `executedByID`  INT          DEFAULT NULL,
            PRIMARY KEY (`migrationID`),
            UNIQUE KEY `uq_filename` (`filename`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        if ($this->db->query($ddl) === false) {
            throw new RuntimeException('Failed to create tblMigrations: ' . $this->db->error);
        }
    }

    /**
     * Get list of all SQL migration files in the sql/ directory.
     * Files are sorted by filename (which is why they use numeric prefixes).
     *
     * @return array<string> Sorted list of filenames (not full paths)
     */
    public function allFiles(): array
    {
        // 🔍 Check that the sql/ directory exists and is readable
        if (is_dir($this->sqlDir) === false || is_readable($this->sqlDir) === false) {
            return [];
        }

        $files = [];
        $handle = opendir($this->sqlDir);
        if ($handle === false) {
            return [];
        }

        // 📂 Read all .sql files from the directory
        while (($entry = readdir($handle)) !== false) {
            // Skip non-SQL files, hidden files, and directories
            if (str_ends_with(strtolower($entry), '.sql') === false) {
                continue;
            }
            $files[] = $entry;
        }
        closedir($handle);

        // 🔤 Sort alphabetically (numeric prefixes ensure correct order)
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Get list of migration files that have already been executed.
     *
     * @return array<string> List of executed filenames
     */
    public function executed(): array
    {
        $executed = [];

        /** @var \mysqli_stmt $stmt */
        $stmt = $this->db->prepare('SELECT filename FROM tblMigrations ORDER BY filename');
        if ($stmt === false) {
            return [];
        }

        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $executed[] = $row['filename'];
        }
        $stmt->close();

        return $executed;
    }

    /**
     * Get list of pending (not yet executed) migration files.
     *
     * @return array<string> List of filenames still to be run
     */
    public function pending(): array
    {
        $all      = $this->allFiles();
        $executed = $this->executed();

        // 🔍 Filter out already-executed migrations
        return array_values(array_diff($all, $executed));
    }

    /**
     * Execute a single migration file.
     *
     * @param string   $filename The migration filename (e.g. '001_create_tblErrors.sql')
     * @param int|null $userId   UserID of the admin running the migration
     *
     * @return array{success: bool, filename: string, error: string} Result
     */
    public function runOne(string $filename, ?int $userId = null): array
    {
        $filePath = $this->sqlDir . DIRECTORY_SEPARATOR . $filename;

        // 🛡️ Validate the file exists and is readable
        if (is_readable($filePath) === false) {
            return [
                'success'  => false,
                'filename' => $filename,
                'error'    => 'Migration file not found or not readable: ' . $filename,
            ];
        }

        // 📝 Read the SQL content
        $sql = file_get_contents($filePath);
        if ($sql === false || trim($sql) === '') {
            return [
                'success'  => false,
                'filename' => $filename,
                'error'    => 'Migration file is empty or unreadable: ' . $filename,
            ];
        }

        // 🚀 Execute the SQL (multi_query supports multiple statements)
        // We use multi_query because migration files may contain multiple statements
        if ($this->db->multi_query($sql) === true) {
            // 🔄 Consume all result sets from multi_query to prevent "commands out of sync"
            // See: https://www.php.net/manual/en/mysqli.multi-query.php
            do {
                $result = $this->db->store_result();
                if ($result !== false) {
                    $result->free();
                }
            } while ($this->db->next_result());

            // ✅ Check for errors after consuming all results
            if ($this->db->errno !== 0) {
                return [
                    'success'  => false,
                    'filename' => $filename,
                    'error'    => 'SQL error during migration: ' . $this->db->error,
                ];
            }
        } else {
            return [
                'success'  => false,
                'filename' => $filename,
                'error'    => 'SQL execution failed: ' . $this->db->error,
            ];
        }

        // 📌 Record the migration as executed
        $stmt = $this->db->prepare('INSERT INTO tblMigrations (filename, executedByID) VALUES (?, ?)');
        if ($stmt !== false) {
            $stmt->bind_param('si', $filename, $userId);
            $stmt->execute();
            $stmt->close();
        }

        return [
            'success'  => true,
            'filename' => $filename,
            'error'    => '',
        ];
    }

    /**
     * Execute all pending migrations in order.
     *
     * @param int|null $userId UserID of the admin running the migrations
     *
     * @return array<array{success: bool, filename: string, error: string}> Results for each migration
     */
    public function runAll(?int $userId = null): array
    {
        $pending = $this->pending();
        $results = [];

        foreach ($pending as $filename) {
            $result = $this->runOne($filename, $userId);
            $results[] = $result;

            // 🛑 Stop on first failure to prevent cascading errors
            if ($result['success'] === false) {
                break;
            }
        }

        return $results;
    }
}
