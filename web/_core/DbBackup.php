<?php
// Path: _core/DbBackup.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — Database Backup & Restore 📦
 * -----------------------------------------------------------------------------
 * JSON-per-table snapshot engine. Used by:
 *
 *   • _install/upgrade.php — auto-backup BEFORE running migrations on
 *     the upgrade path so the admin has a safety net if a migration
 *     misbehaves.
 *   • admin/maintenance/backup — list / inspect / restore historical
 *     snapshots from the UI.
 *
 * Why JSON per-table (not single mysqldump-style file):
 *   • Programmatically inspectable — a future per-migration "rescue
 *     script" can read the affected table's JSON and re-insert under
 *     a new schema without parsing SQL.
 *   • Per-table granularity — admin can restore a single table without
 *     touching others.
 *   • Self-describing — each snapshot includes column metadata, row
 *     count, snapshot timestamp, and a checksum.
 *
 * Layout:
 *   web/_backups/upgrade-YYYYMMDD-HHMMSS/
 *     ├── _manifest.json     # snapshot-wide metadata + table list
 *     ├── tblUsers.json      # { meta: {columns, count, …}, rows: [...] }
 *     ├── tblSettings.json
 *     └── ...
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;
use RuntimeException;

class DbBackup
{
    private mysqli $db;
    private string $backupsRoot;

    public function __construct(mysqli $db, string $backupsRoot = '')
    {
        $this->db          = $db;
        $this->backupsRoot = $backupsRoot !== ''
            ? rtrim($backupsRoot, DIRECTORY_SEPARATOR)
            : PORTAL_ROOT . DIRECTORY_SEPARATOR . '_backups';
    }

    /**
     * Take a JSON-per-table snapshot of every `tbl*` table.
     *
     * @param string $reason  Short human-readable label embedded in the
     *                        manifest (e.g. 'pre-upgrade-1.0.1-to-1.1.0').
     *
     * @return array{success: bool, directory: string, tables: int,
     *                rows: int, error?: string}
     */
    public function snapshot(string $reason = ''): array
    {
        // 🗂️ Ensure the backups root exists.
        if (is_dir($this->backupsRoot) === false) {
            if (mkdir($this->backupsRoot, 0750, true) === false
                && is_dir($this->backupsRoot) === false
            ) {
                return [
                    'success'   => false,
                    'directory' => '',
                    'tables'    => 0,
                    'rows'      => 0,
                    'error'     => 'Could not create backups root: ' . $this->backupsRoot,
                ];
            }
        }

        $stamp = date('Ymd-His');
        $dir   = $this->backupsRoot . DIRECTORY_SEPARATOR . 'upgrade-' . $stamp;
        if (mkdir($dir, 0750, true) === false && is_dir($dir) === false) {
            return [
                'success'   => false,
                'directory' => '',
                'tables'    => 0,
                'rows'      => 0,
                'error'     => 'Could not create snapshot directory: ' . $dir,
            ];
        }

        $manifest = [
            'created_at'   => date('c'),
            'reason'       => $reason,
            'php_version'  => PHP_VERSION,
            'mysql_server' => $this->db->server_info,
            'tables'       => [],
        ];
        $totalRows = 0;

        // 🔍 Enumerate portal tables.
        try {
            $rs = $this->db->query("SHOW TABLES LIKE 'tbl%'");
        } catch (\mysqli_sql_exception $e) {
            return [
                'success'   => false,
                'directory' => $dir,
                'tables'    => 0,
                'rows'      => 0,
                'error'     => 'Table enumeration failed: ' . $e->getMessage(),
            ];
        }
        if ($rs === false) {
            return [
                'success'   => false,
                'directory' => $dir,
                'tables'    => 0,
                'rows'      => 0,
                'error'     => 'SHOW TABLES returned false.',
            ];
        }

        $tableCount = 0;
        while (($row = $rs->fetch_array(MYSQLI_NUM)) !== null) {
            $table = (string) $row[0];
            $written = $this->dumpTable($dir, $table);
            if ($written['success'] === false) {
                $rs->free();
                return [
                    'success'   => false,
                    'directory' => $dir,
                    'tables'    => $tableCount,
                    'rows'      => $totalRows,
                    'error'     => 'Failed to dump ' . $table . ': ' . $written['error'],
                ];
            }
            $manifest['tables'][$table] = [
                'columns' => $written['columns'],
                'rows'    => $written['rows'],
                'sha256'  => $written['sha256'],
            ];
            $totalRows += $written['rows'];
            $tableCount++;
        }
        $rs->free();

        // 📝 Write manifest last so a half-finished snapshot is obvious
        //    (manifest absent → snapshot incomplete).
        $manifestJson = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($manifestJson === false) {
            return [
                'success'   => false,
                'directory' => $dir,
                'tables'    => $tableCount,
                'rows'      => $totalRows,
                'error'     => 'Manifest serialisation failed.',
            ];
        }
        $manifestPath = $dir . DIRECTORY_SEPARATOR . '_manifest.json';
        if (file_put_contents($manifestPath, $manifestJson) === false) {
            return [
                'success'   => false,
                'directory' => $dir,
                'tables'    => $tableCount,
                'rows'      => $totalRows,
                'error'     => 'Could not write manifest.',
            ];
        }

        return [
            'success'   => true,
            'directory' => $dir,
            'tables'    => $tableCount,
            'rows'      => $totalRows,
        ];
    }

    /**
     * Dump a single table to `{dir}/{table}.json`.
     *
     * @return array{success: bool, columns: string[], rows: int,
     *                sha256: string, error?: string}
     */
    private function dumpTable(string $dir, string $table): array
    {
        // 🔍 Validate table identifier — we're about to inject it into
        //    SQL; tighter than is strictly needed since SHOW TABLES gave
        //    us the name, but defence-in-depth.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            return [
                'success' => false,
                'columns' => [],
                'rows'    => 0,
                'sha256'  => '',
                'error'   => 'Invalid table identifier: ' . $table,
            ];
        }

        try {
            $rs = $this->db->query('SELECT * FROM `' . $table . '`');
        } catch (\mysqli_sql_exception $e) {
            return [
                'success' => false,
                'columns' => [],
                'rows'    => 0,
                'sha256'  => '',
                'error'   => $e->getMessage(),
            ];
        }
        if ($rs === false) {
            return [
                'success' => false,
                'columns' => [],
                'rows'    => 0,
                'sha256'  => '',
                'error'   => 'SELECT * FROM ' . $table . ' returned false.',
            ];
        }

        $columns = [];
        $fields  = $rs->fetch_fields();
        foreach ($fields as $f) {
            $columns[] = (string) $f->name;
        }

        $rows = [];
        while (($row = $rs->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $rs->free();

        $payload = [
            'meta' => [
                'table'      => $table,
                'columns'    => $columns,
                'row_count'  => count($rows),
                'snapped_at' => date('c'),
            ],
            'rows' => $rows,
        ];

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            return [
                'success' => false,
                'columns' => $columns,
                'rows'    => count($rows),
                'sha256'  => '',
                'error'   => 'JSON encode failed: ' . json_last_error_msg(),
            ];
        }

        $file = $dir . DIRECTORY_SEPARATOR . $table . '.json';
        if (file_put_contents($file, $json) === false) {
            return [
                'success' => false,
                'columns' => $columns,
                'rows'    => count($rows),
                'sha256'  => '',
                'error'   => 'Could not write ' . $file,
            ];
        }

        return [
            'success' => true,
            'columns' => $columns,
            'rows'    => count($rows),
            'sha256'  => hash('sha256', $json),
        ];
    }

    /**
     * List all snapshot directories under the backups root, newest first.
     *
     * @return array<int, array{name: string, path: string,
     *                          created_at: string, tables: int, rows: int,
     *                          reason: string}>
     */
    public function listSnapshots(): array
    {
        if (is_dir($this->backupsRoot) === false) {
            return [];
        }
        $entries = scandir($this->backupsRoot, SCANDIR_SORT_DESCENDING);
        if ($entries === false) {
            return [];
        }
        $snapshots = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $this->backupsRoot . DIRECTORY_SEPARATOR . $name;
            if (is_dir($path) === false) {
                continue;
            }
            $manifestPath = $path . DIRECTORY_SEPARATOR . '_manifest.json';
            if (is_readable($manifestPath) === false) {
                // 🪞 No manifest → incomplete snapshot. Still list it so
                //    the admin can decide whether to delete it.
                $snapshots[] = [
                    'name'       => $name,
                    'path'       => $path,
                    'created_at' => date('c', (int) filemtime($path)),
                    'tables'     => 0,
                    'rows'       => 0,
                    'reason'     => '(incomplete — no manifest)',
                ];
                continue;
            }
            $raw = file_get_contents($manifestPath);
            if ($raw === false) {
                continue;
            }
            $manifest = json_decode($raw, true);
            if (is_array($manifest) === false) {
                continue;
            }
            $tableRows = 0;
            foreach ((array) ($manifest['tables'] ?? []) as $t) {
                $tableRows += (int) ($t['rows'] ?? 0);
            }
            $snapshots[] = [
                'name'       => $name,
                'path'       => $path,
                'created_at' => (string) ($manifest['created_at'] ?? ''),
                'tables'     => count((array) ($manifest['tables'] ?? [])),
                'rows'       => $tableRows,
                'reason'     => (string) ($manifest['reason'] ?? ''),
            ];
        }
        return $snapshots;
    }

    /**
     * Prune snapshots, keeping the most recent N. Pass 0 to disable.
     *
     * @return array{kept: int, pruned: int, errors: string[]}
     */
    public function prune(int $keep): array
    {
        if ($keep <= 0) {
            return ['kept' => 0, 'pruned' => 0, 'errors' => []];
        }
        $snapshots = $this->listSnapshots();
        $kept = 0;
        $pruned = 0;
        $errors = [];
        foreach ($snapshots as $i => $snap) {
            if ($i < $keep) {
                $kept++;
                continue;
            }
            // 🗑️ Recursive delete via our own helper to avoid pulling in
            //    a vendor dependency.
            if ($this->rmdirRecursive($snap['path']) === true) {
                $pruned++;
            } else {
                $errors[] = 'Could not delete ' . $snap['path'];
            }
        }
        return ['kept' => $kept, 'pruned' => $pruned, 'errors' => $errors];
    }

    /**
     * Restore a single table from a snapshot. The table is TRUNCATEd
     * first, then rows are re-INSERTed in the order they were dumped.
     *
     * @return array{success: bool, rows_restored: int, error?: string}
     */
    public function restoreTable(string $snapshotPath, string $table): array
    {
        $file = rtrim($snapshotPath, DIRECTORY_SEPARATOR)
              . DIRECTORY_SEPARATOR . $table . '.json';
        if (is_readable($file) === false) {
            return [
                'success'       => false,
                'rows_restored' => 0,
                'error'         => 'Snapshot file not found: ' . $file,
            ];
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            return [
                'success'       => false,
                'rows_restored' => 0,
                'error'         => 'Could not read ' . $file,
            ];
        }
        $payload = json_decode($raw, true);
        if (is_array($payload) === false
            || isset($payload['rows']) === false
            || is_array($payload['rows']) === false
        ) {
            return [
                'success'       => false,
                'rows_restored' => 0,
                'error'         => 'Snapshot file malformed: ' . $file,
            ];
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            return [
                'success'       => false,
                'rows_restored' => 0,
                'error'         => 'Invalid table identifier: ' . $table,
            ];
        }

        try {
            $this->db->begin_transaction();
            $this->db->query('TRUNCATE TABLE `' . $table . '`');

            $rows = $payload['rows'];
            $inserted = 0;
            foreach ($rows as $row) {
                if (is_array($row) === false || count($row) === 0) {
                    continue;
                }
                $cols   = array_keys($row);
                $values = array_values($row);
                $colSql = implode(', ', array_map(
                    static fn ($c) => '`' . $c . '`',
                    $cols
                ));
                $placeholders = implode(', ', array_fill(0, count($values), '?'));
                $stmt = $this->db->prepare(
                    'INSERT INTO `' . $table . '` (' . $colSql . ') '
                    . 'VALUES (' . $placeholders . ')'
                );
                if ($stmt === false) {
                    $this->db->rollback();
                    return [
                        'success'       => false,
                        'rows_restored' => $inserted,
                        'error'         => 'Prepare failed for ' . $table,
                    ];
                }
                // 🪶 Bind everything as strings — mysqli coerces to the
                //    column type at INSERT time, and JSON->PHP gave us
                //    primitives already (so nulls stay null).
                $types = str_repeat('s', count($values));
                $stmt->bind_param($types, ...array_map(
                    static fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v),
                    $values
                ));
                $stmt->execute();
                $stmt->close();
                $inserted++;
            }
            $this->db->commit();

            return ['success' => true, 'rows_restored' => $inserted];
        } catch (\mysqli_sql_exception $e) {
            try {
                $this->db->rollback();
            } catch (\Throwable $ignored) {
                // best effort
            }
            return [
                'success'       => false,
                'rows_restored' => 0,
                'error'         => $e->getMessage(),
            ];
        }
    }

    /**
     * @internal Recursive rmdir helper for snapshot pruning.
     */
    private function rmdirRecursive(string $dir): bool
    {
        if (is_dir($dir) === false) {
            return true;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $e;
            if (is_dir($path) === true) {
                if ($this->rmdirRecursive($path) === false) {
                    return false;
                }
            } else {
                if (unlink($path) === false) {
                    return false;
                }
            }
        }
        return rmdir($dir);
    }
}
