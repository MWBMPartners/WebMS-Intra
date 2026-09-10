<?php
// Path: _core/Migrator.php
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
        // 🛡️ Both queries below are wrapped in try/catch — under PHP 8.1+
        //    strict mysqli mode, mysqli::query() throws mysqli_sql_exception
        //    on error rather than returning false, so the `=== false` checks
        //    are effectively dead code. We catch the exception and rethrow
        //    as RuntimeException with a useful message so the upgrade page
        //    (which calls this) can surface what went wrong.
        try {
            // 🔍 Check if tblMigrations already exists to avoid unnecessary DDL
            $result = $this->db->query("SHOW TABLES LIKE 'tblMigrations'");
            if ($result !== false && $result->num_rows > 0) {
                $result->free();
                return;
            }
            if ($result !== false) {
                $result->free();
            }

            // 📝 Create the table inline (mirrors _sql/000_create_migrations_table.sql)
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
        } catch (\mysqli_sql_exception $e) {
            throw new RuntimeException(
                'Failed to ensure tblMigrations exists: ' . $e->getMessage(),
                0,
                $e
            );
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

        // 📂 Read the numbered migration files from the directory.
        //
        //    ⚠️ The three-digit prefix is REQUIRED, not decoration. Two files
        //    in this folder are not migrations and must never appear in the
        //    pending list:
        //
        //      full_schema.sql — the complete database, used once when the
        //          portal is first installed. Running it again on a live site
        //          would re-apply every default value.
        //
        //      demo_data.sql — sample people, announcements and events for
        //          training. Its own header says it is loaded only from
        //          Admin → Maintenance → Demo data, and only when demo mode is
        //          switched on. Running it on a real site puts made-up members
        //          and events in front of everybody.
        //
        //    Neither is ever recorded in tblMigrations, so before this filter
        //    existed both were listed as "pending" on a perfectly up-to-date
        //    portal — and "Run all pending" would have run them. This matches
        //    the pattern the installer already uses when it replays migrations
        //    (web/_install/index.php: glob('[0-9][0-9][0-9]_*.sql')).
        while (($entry = readdir($handle)) !== false) {
            if (preg_match('/^\d{3}_.*\.sql$/i', $entry) !== 1) {
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
        // 🛡️ Only ever run a numbered migration. The same rule as allFiles(),
        //    repeated here on purpose: this method can be reached by posting a
        //    filename straight to /admin/migrations, so it must not rely on
        //    the listing having filtered anything out. Without this, an
        //    administrator (or anyone who could forge that request) could run
        //    demo_data.sql and drop sample members and events into a real site.
        if (preg_match('/^\d{3}_.*\.sql$/i', $filename) !== 1) {
            return [
                'success'  => false,
                'filename' => $filename,
                'error'    => 'Not a migration file. Only numbered files such as '
                            . '"186_example.sql" can be run here. full_schema.sql is '
                            . 'for first-time installation, and demo_data.sql is loaded '
                            . 'from Admin → Maintenance → Demo data.',
            ];
        }

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

        // 🚀 Run the file. A migration may hold many statements, so they are
        //    sent together.
        //
        //    ⚠️ The try/catch matters. This portal asks the database driver to
        //    throw on error, so a failing statement does NOT quietly set an
        //    error number for the checks below — it throws, straight past
        //    them, out of this method, past the migrations page, and the
        //    visitor gets a blank error page with nothing useful on it.
        //
        //    That hid real information. Migration 187, for example, stops
        //    deliberately if it finds the database in a shape it cannot safely
        //    fix, and says why. Without this, that explanation never reached
        //    the person who needed it. Catching here turns any failure back
        //    into an ordinary result the page already knows how to display.
        try {
            if ($this->db->multi_query($sql) === true) {
                // 🔄 Read every result through, or the connection is left
                //    mid-conversation and the next query fails with
                //    "commands out of sync".
                //    See: https://www.php.net/manual/en/mysqli.multi-query.php
                do {
                    $result = $this->db->store_result();
                    if ($result !== false) {
                        $result->free();
                    }
                } while ($this->db->next_result());

                // ✅ For drivers that report by error number rather than by
                //    throwing.
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
        } catch (\mysqli_sql_exception $e) {
            // 🧹 Drain anything still queued, so the connection stays usable
            //    for the rest of the request (the page still has to render).
            try {
                while ($this->db->more_results() === true && $this->db->next_result() === true) {
                    $drained = $this->db->store_result();
                    if ($drained !== false) {
                        $drained->free();
                    }
                }
            } catch (\Throwable $ignored) {
                // Already failing; nothing useful to add.
            }

            return [
                'success'  => false,
                'filename' => $filename,
                'error'    => 'SQL error during migration: ' . $e->getMessage(),
            ];
        }

        // 📌 Record the migration as executed.
        //
        //    "or leave it alone if it is already recorded" matters here. Every
        //    migration in this project ends by recording itself, because the
        //    installer replays them all and each one has to be safe to run
        //    twice. So by the time we get here the row usually exists already.
        //    A plain INSERT would hit the "no two rows the same" rule on
        //    `filename` and throw — reporting a failure for a migration that
        //    had in fact just succeeded, and stopping the rest of the run.
        //    That affected every migration from 180 onwards.
        $stmt = $this->db->prepare(
            'INSERT INTO tblMigrations (filename, executedByID) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE executedByID = COALESCE(VALUES(executedByID), executedByID)'
        );
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
