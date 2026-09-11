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
 * @version   1.0.1
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

    /**
     * @var array<string, array<string>>|null Cache of table name → the names
     * of its derived columns. Null until the first lookup.
     */
    private ?array $generatedColumnCache = null;

    public function __construct(mysqli $db, string $backupsRoot = '')
    {
        $this->db          = $db;
        $this->backupsRoot = $backupsRoot !== ''
            ? rtrim($backupsRoot, DIRECTORY_SEPARATOR)
            : PORTAL_ROOT . DIRECTORY_SEPARATOR . '_backups';
    }

    /**
     * The names of any columns in this table whose value the database works
     * out for itself.
     *
     * Such a column cannot be written to. Trying to include one in an INSERT
     * is refused outright with "The value specified for generated column …
     * is not allowed", which would abandon the whole restore.
     *
     * `tblSettings.siteScope` (added in migration 187) is the first of these
     * in this product, but the lookup is deliberately generic so any future
     * one is handled without anybody having to remember this.
     *
     * The answer is read once per table and kept for the life of this object;
     * a snapshot touches every table, so re-asking each time would be wasteful.
     *
     * @param string $table Table name, already validated by the caller.
     *
     * @return array<string>|null Column names to leave out of any INSERT, or
     *                            null when the database could not be asked. A
     *                            caller that is about to destroy data MUST
     *                            treat null as "stop", because guessing wrong
     *                            here means either losing a real column or
     *                            failing part-way through a restore.
     */
    private function generatedColumns(string $table): ?array
    {
        if ($this->generatedColumnCache === null) {
            $this->generatedColumnCache = [];

            try {
                // ⚠️ Test GENERATION_EXPRESSION, NOT `EXTRA LIKE '%GENERATED%'`.
                //
                //    That looks like the obvious test and is badly wrong. MySQL
                //    puts the word GENERATED in EXTRA for ordinary columns too:
                //    a column declared `DEFAULT CURRENT_TIMESTAMP` gets
                //    EXTRA = 'DEFAULT_GENERATED', and one that also has
                //    `ON UPDATE CURRENT_TIMESTAMP` gets
                //    'DEFAULT_GENERATED on update CURRENT_TIMESTAMP'.
                //
                //    Matching on the word alone would therefore treat every
                //    `createdAt` and `updatedAt` column in the product as
                //    something to skip — quietly dropping them from every
                //    backup and every restore, across all 209 tables. That
                //    would be far worse than the problem this method exists to
                //    solve. (Confirmed on MySQL 8.0.36.)
                //
                //    GENERATION_EXPRESSION holds the formula, and is empty for
                //    everything except a genuinely derived column, so it is the
                //    precise test.
                $rs = $this->db->query(
                    'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS '
                    . 'WHERE TABLE_SCHEMA = DATABASE() '
                    . "AND GENERATION_EXPRESSION IS NOT NULL "
                    . "AND GENERATION_EXPRESSION <> ''"
                );
                if ($rs === false) {
                    // Not every mysqli setup throws on failure; some return
                    // false instead. Treat that the same way — do not record an
                    // empty answer as if the question had been answered.
                    $this->generatedColumnCache = null;
                    return null;
                }
                while (($row = $rs->fetch_assoc()) !== null) {
                    $t = (string) $row['TABLE_NAME'];
                    $this->generatedColumnCache[$t][] = (string) $row['COLUMN_NAME'];
                }
                $rs->free();
            } catch (\mysqli_sql_exception $e) {
                // Do NOT cache a failure as "there are none". Leaving the cache
                // unset means the next call tries again, and returning null
                // tells the caller to stop rather than act on a guess.
                $this->generatedColumnCache = null;
                return null;
            }
        }

        return $this->generatedColumnCache[$table] ?? [];
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

        // 🚫 Do not record columns the database works out for itself. They
        //    cannot be written back on a restore, and keeping them would only
        //    make the snapshot bigger and invite confusion.
        $generated = $this->generatedColumns($table);
        if ($generated === null) {
            $rs->free();
            return [
                'success' => false,
                'columns' => [],
                'rows'    => 0,
                'sha256'  => '',
                'error'   => 'Could not read the shape of ' . $table
                           . ' from the database, so this table was not backed up.',
            ];
        }
        if (count($generated) > 0) {
            $columns = array_values(array_diff($columns, $generated));
        }

        $rows = [];
        while (($row = $rs->fetch_assoc()) !== null) {
            foreach ($generated as $genCol) {
                unset($row[$genCol]);
            }
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
    /**
     * 🔗 Work out which tables point at which, straight from the live database.
     *
     * Read from the database itself rather than from the schema file on disk,
     * because what matters during a restore is how THIS database is actually
     * put together — which may differ from the file if a migration has not been
     * run, or if somebody has changed something by hand.
     *
     * @return array<string, array<int, string>>|null  Each table, mapped to the
     *         tables it points AT (its parents). Null if the database would not
     *         say, in which case the caller must refuse to restore rather than
     *         guess at an order.
     */
    private function foreignKeyGraph(): ?array
    {
        try {
            $rs = $this->db->query(
                'SELECT TABLE_NAME, REFERENCED_TABLE_NAME '
                . 'FROM information_schema.KEY_COLUMN_USAGE '
                . 'WHERE TABLE_SCHEMA = DATABASE() '
                . '  AND REFERENCED_TABLE_NAME IS NOT NULL'
            );
        } catch (\mysqli_sql_exception $e) {
            return null;
        }
        if ($rs === false) {
            return null;
        }

        $graph = [];
        while ($row = $rs->fetch_assoc()) {
            $child  = (string) $row['TABLE_NAME'];
            $parent = (string) $row['REFERENCED_TABLE_NAME'];
            if ($child === $parent) {
                // A table pointing at itself does not affect the ORDER tables
                // are handled in — it only constrains rows within one table.
                continue;
            }
            $graph[$child][] = $parent;
        }
        $rs->free();
        return $graph;
    }

    /**
     * 📚 Put tables in an order where every parent comes before its children.
     *
     * Emptying and refilling tables in a sensible order is what makes a restore
     * work at all. A child row cannot be written before the parent row it
     * points at exists, and a parent cannot be emptied while children still
     * point at it.
     *
     * So: EMPTY in reverse of this order (children first), and REFILL in this
     * order (parents first).
     *
     * @param array<int, string>                 $tables The tables to order.
     * @param array<string, array<int, string>>  $graph  From foreignKeyGraph().
     *
     * @return array<int, string>|null  The ordered tables, or null if they
     *         cannot be ordered because some point at each other in a circle.
     */
    private function restoreOrder(array $tables, array $graph): ?array
    {
        $remaining = array_fill_keys($tables, true);
        $ordered   = [];

        // Peel off, repeatedly, every table whose parents are all already
        // placed. If a pass places nothing, the rest form a circle.
        while ($remaining !== []) {
            $ready = [];
            foreach (array_keys($remaining) as $table) {
                $parents = $graph[$table] ?? [];
                $waiting = false;
                foreach ($parents as $parent) {
                    if (isset($remaining[$parent]) === true && $parent !== $table) {
                        $waiting = true;
                        break;
                    }
                }
                if ($waiting === false) {
                    $ready[] = $table;
                }
            }

            if ($ready === []) {
                // Tables pointing at each other in a circle. The live database
                // has none today, but a future one might, and guessing an order
                // would mean a restore that half works.
                return null;
            }

            sort($ready);
            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($remaining[$table]);
            }
        }

        return $ordered;
    }

    /**
     * 🧾 Check a snapshot file is exactly the file that was written.
     *
     * Every snapshot has recorded a checksum for each table since the day this
     * class was written — and until now nothing has ever looked at one. A file
     * that has been truncated by a failed copy, damaged in storage, or edited
     * by hand would have been fed into the database without a murmur.
     *
     * @param string $file        The snapshot file for one table.
     * @param array  $manifestRow That table's entry in the snapshot's manifest.
     *
     * @return string  An empty string when the file is sound, otherwise a
     *                 sentence explaining what is wrong with it.
     */
    private function checksumProblem(string $file, array $manifestRow): string
    {
        $expected = (string) ($manifestRow['sha256'] ?? '');
        if ($expected === '') {
            // Snapshots taken by a much older version may not have recorded
            // one. Not being able to check is not the same as failing a check.
            return '';
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            return 'The file could not be read.';
        }

        $actual = hash('sha256', $raw);
        if (hash_equals($expected, $actual) === false) {
            return 'This file is not the file that was saved. It has been changed '
                 . 'or damaged since the snapshot was taken, so it is not safe to '
                 . 'put back into the database.';
        }
        return '';
    }

    /**
     * 🔄 Put a WHOLE snapshot back, as one all-or-nothing operation.
     *
     * This is the method to use for a real recovery. Putting tables back one at
     * a time cannot work, for two reasons that only show up on a real database:
     *
     *   1. Tables point at each other. A row in one table refers to a row in
     *      another, and the database refuses to let you empty the second while
     *      the first still points at it. Sixty-nine of the tables here are
     *      pointed at by something. Restoring them individually has never been
     *      possible.
     *
     *   2. Doing them one at a time means each is finished before the next
     *      begins. If the twentieth fails, the first nineteen are already done
     *      and cannot be taken back. You are left with a database that is part
     *      one thing and part another - arguably worse than the state you were
     *      trying to recover from, and much harder to reason about.
     *
     * So this does the lot inside a SINGLE transaction. Everything is checked
     * before anything is touched; every table is emptied and refilled; and only
     * then is any of it made permanent. If ANYTHING goes wrong at any point,
     * the whole thing is abandoned and the database is exactly as it was.
     *
     * The order matters and is worked out rather than assumed. Tables are
     * emptied children-first, so nothing is left pointing at a row that has
     * gone, and refilled parents-first, so nothing is written before the row it
     * points at exists.
     *
     * @param string $snapshotPath Folder holding the snapshot.
     * @param bool   $dryRun       When true, check everything and report what
     *                             WOULD happen, changing nothing at all. Worth
     *                             doing first: a recovery is a bad moment to
     *                             discover the snapshot is damaged.
     *
     * @return array{success: bool, tables_restored: int, rows_restored: int,
     *               error: string, warnings: array<int, string>}
     */
    public function restoreAll(string $snapshotPath, bool $dryRun = false): array
    {
        $fail = static function (string $why): array {
            return [
                'success'         => false,
                'tables_restored' => 0,
                'rows_restored'   => 0,
                'error'           => $why,
                'warnings'        => [],
            ];
        };

        // 🛡️ Same containment guard as the single-table restore: resolve the
        //    path and refuse anything that does not land inside the backups
        //    folder.
        $resolvedRoot     = realpath($this->backupsRoot);
        $resolvedSnapshot = realpath(rtrim($snapshotPath, DIRECTORY_SEPARATOR));
        if ($resolvedRoot === false
            || $resolvedSnapshot === false
            || str_starts_with($resolvedSnapshot . DIRECTORY_SEPARATOR, $resolvedRoot . DIRECTORY_SEPARATOR) === false
        ) {
            return $fail('That snapshot is not in the backups folder, so it was not opened. '
                       . 'Nothing has been changed.');
        }

        $manifestFile = $resolvedSnapshot . DIRECTORY_SEPARATOR . '_manifest.json';
        if (is_readable($manifestFile) === false) {
            return $fail('This snapshot has no manifest, so there is no way to know what it '
                       . 'should contain. Nothing has been changed.');
        }
        $manifest = json_decode((string) file_get_contents($manifestFile), true);
        if (is_array($manifest) === false || isset($manifest['tables']) === false) {
            return $fail('This snapshot\'s manifest could not be read. Nothing has been '
                       . 'changed.');
        }

        $tables = array_keys((array) $manifest['tables']);
        if ($tables === []) {
            return $fail('This snapshot contains no tables. Nothing has been changed.');
        }

        // ---------------------------------------------------------------
        // 🔍 CHECK EVERYTHING FIRST. Nothing below touches the database.
        // ---------------------------------------------------------------
        // A damaged file found half way through a restore is a disaster. Found
        // beforehand, it costs nothing at all.
        $warnings = [];
        $payloads = [];

        foreach ($tables as $table) {
            $table = (string) $table;
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
                return $fail('This snapshot names a table in a form that is not valid: '
                           . $table . '. Nothing has been changed.');
            }

            $file = $resolvedSnapshot . DIRECTORY_SEPARATOR . $table . '.json';
            if (is_readable($file) === false) {
                return $fail('The snapshot is incomplete: it lists ' . $table . ' but that '
                           . 'file is missing. Nothing has been changed.');
            }

            $problem = $this->checksumProblem($file, (array) $manifest['tables'][$table]);
            if ($problem !== '') {
                return $fail('The saved copy of ' . $table . ' is damaged. ' . $problem
                           . ' Nothing has been changed.');
            }

            $payload = json_decode((string) file_get_contents($file), true);
            if (is_array($payload) === false || is_array($payload['rows'] ?? null) === false) {
                return $fail('The saved copy of ' . $table . ' could not be read. Nothing '
                           . 'has been changed.');
            }
            $payloads[$table] = $payload;
        }

        // 🔗 Work out the order, from how the LIVE database is put together
        //    rather than from the snapshot - the database is what the writes
        //    have to satisfy.
        $graph = $this->foreignKeyGraph();
        if ($graph === null) {
            return $fail('The database would not say how its tables are linked together, so '
                       . 'there is no safe order to put them back in. Nothing has been '
                       . 'changed.');
        }

        $order = $this->restoreOrder($tables, $graph);
        if ($order === null) {
            return $fail('These tables point at each other in a circle, so there is no order '
                       . 'that works. This needs a person to look at it. Nothing has been '
                       . 'changed.');
        }

        if ($dryRun === true) {
            $rowTotal = 0;
            foreach ($payloads as $payload) {
                $rowTotal += count($payload['rows']);
            }
            return [
                'success'         => true,
                'tables_restored' => count($order),
                'rows_restored'   => $rowTotal,
                'error'           => '',
                'warnings'        => ['This was a check only. Nothing was changed.'],
            ];
        }

        // ---------------------------------------------------------------
        // 🔄 One transaction. All of it, or none of it.
        // ---------------------------------------------------------------
        $tablesDone = 0;
        $rowsDone   = 0;

        try {
            $this->db->begin_transaction();

            // Empty children first, so nothing is ever left pointing at a row
            // that has just gone.
            foreach (array_reverse($order) as $table) {
                $this->db->query('DELETE FROM `' . $table . '`');
            }

            // Refill parents first, so nothing is written before the row it
            // points at exists.
            foreach ($order as $table) {
                $rows = $payloads[$table]['rows'];
                if ($rows === []) {
                    $tablesDone++;
                    continue;
                }

                // The database fills some columns in for itself and refuses an
                // INSERT that names one. Work out which, per table.
                $generated = $this->generatedColumns($table);
                if ($generated === null) {
                    throw new \RuntimeException(
                        'Could not read the shape of ' . $table . '.'
                    );
                }

                foreach ($rows as $row) {
                    if (is_array($row) === false) {
                        continue;
                    }
                    $cols = array_values(array_diff(array_keys($row), $generated));
                    if ($cols === []) {
                        continue;
                    }

                    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                    $quoted       = '`' . implode('`, `', $cols) . '`';
                    $stmt = $this->db->prepare(
                        'INSERT INTO `' . $table . '` (' . $quoted . ') VALUES (' . $placeholders . ')'
                    );
                    if ($stmt === false) {
                        throw new \RuntimeException('Could not prepare an insert for ' . $table . '.');
                    }

                    $values = [];
                    foreach ($cols as $col) {
                        $values[] = $row[$col];
                    }
                    $stmt->bind_param(str_repeat('s', count($values)), ...$values);
                    $stmt->execute();
                    $stmt->close();
                    $rowsDone++;
                }
                $tablesDone++;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            // 🔙 Put everything back. Because nothing was made permanent along the
            //    way, this really does return the database to exactly the state
            //    it was in before this method was called.
            try {
                $this->db->rollback();
            } catch (\Throwable $ignored) {
                unset($ignored);
            }

            return $fail('The restore was stopped and everything has been put back as it '
                       . 'was. Nothing in the database has changed. The reason it stopped: '
                       . $e->getMessage());
        }

        return [
            'success'         => true,
            'tables_restored' => $tablesDone,
            'rows_restored'   => $rowsDone,
            'error'           => '',
            'warnings'        => $warnings,
        ];
    }

    public function restoreTable(string $snapshotPath, string $table): array
    {
        // 🛡️ Validate the table identifier FIRST — before it is ever used
        //     to build a filesystem path or interpolated into SQL. Moved
        //     ahead of the file read (was previously checked only after
        //     file_get_contents(), which is too late to matter for this
        //     value but was the wrong order to reason about safely).
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            return [
                'success'       => false,
                'rows_restored' => 0,
                'error'         => 'Invalid table identifier: ' . $table,
            ];
        }

        // 🛡️ Path-traversal guard — resolve $snapshotPath with realpath()
        //     and reject anything that doesn't land inside backupsRoot
        //     (e.g. a forged `name` containing `../../_auth_keys`) before
        //     any file is opened.
        $resolvedRoot     = realpath($this->backupsRoot);
        $resolvedSnapshot = realpath(rtrim($snapshotPath, DIRECTORY_SEPARATOR));
        if ($resolvedRoot === false
            || $resolvedSnapshot === false
            || str_starts_with($resolvedSnapshot . DIRECTORY_SEPARATOR, $resolvedRoot . DIRECTORY_SEPARATOR) === false
        ) {
            return [
                'success'       => false,
                'rows_restored' => 0,
                'error'         => 'Invalid snapshot path.',
            ];
        }

        $file = $resolvedSnapshot . DIRECTORY_SEPARATOR . $table . '.json';
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

        // 🧾 Is this the file that was actually saved?
        //
        //    Every snapshot has recorded a checksum for each table since the day
        //    this class was written, and until now nothing ever looked at one. A
        //    file cut short by a failed copy, damaged sitting on a disk, or
        //    edited by hand would have been fed straight into the database
        //    without a murmur - during a recovery, which is exactly when
        //    somebody is least able to cope with a second problem.
        //
        //    Checked BEFORE anything is emptied, so a damaged file costs
        //    nothing. A snapshot from a much older version may not have recorded
        //    a checksum at all; not being able to check is not the same as
        //    failing a check, so that case is allowed through.
        $manifestFile = $resolvedSnapshot . DIRECTORY_SEPARATOR . '_manifest.json';
        if (is_readable($manifestFile) === true) {
            $manifestRaw = file_get_contents($manifestFile);
            $manifest    = $manifestRaw === false ? null : json_decode($manifestRaw, true);
            if (is_array($manifest) === true && isset($manifest['tables'][$table]) === true) {
                $problem = $this->checksumProblem($file, (array) $manifest['tables'][$table]);
                if ($problem !== '') {
                    return [
                        'success'       => false,
                        'rows_restored' => 0,
                        'error'         => $problem . ' Nothing has been changed.',
                    ];
                }
            }
        }

        // 🚫 Work out which columns the database fills in for itself BEFORE
        //    anything is emptied. Two reasons this has to happen here rather
        //    than only when the snapshot is taken: an older snapshot, made
        //    before this existed, still contains such a column; and the
        //    database refuses an INSERT that names one, which would abandon
        //    the restore half way through.
        //
        //    Doing it first also means a failure to read the table's shape
        //    stops us before we have destroyed anything.
        $generated = $this->generatedColumns($table);
        if ($generated === null) {
            return [
                'success'       => false,
                'rows_restored' => 0,
                'error'         => 'Could not read the shape of ' . $table
                                 . ' from the database, so the restore was not '
                                 . 'started. Nothing has been changed.',
            ];
        }

        // 🧹 Empty the table, then put the snapshot's rows back.
        //
        //    THIS USED TO SAY `TRUNCATE`, AND THAT WAS THE WHOLE BUG (#472).
        //
        //    `TRUNCATE` looks like a faster way to empty a table, and it is.
        //    But it commits by itself. The moment it runs, the old contents are
        //    gone for good and the transaction wrapped around it is already
        //    over. So when a row failed half way through the refill, there was
        //    nothing left to go back to: the table was left part-filled with the
        //    original contents destroyed. A restore could leave somebody worse
        //    off than the problem they were trying to recover from.
        //
        //    It had a second fault too. MySQL refuses `TRUNCATE` outright on any
        //    table that other tables point at - 69 of the 209 here - so
        //    restoring any of those had never worked at all.
        //
        //    `DELETE FROM` fixes both. It is slower, which does not matter in a
        //    recovery, and it takes part in the transaction properly: if
        //    anything goes wrong afterwards everything is put back exactly as it
        //    was, and the table is left untouched.
        //
        //    An earlier attempt switched the database's own relationship
        //    checking OFF to make this work. That fixed these two faults and
        //    introduced a worse one - a restore could leave records pointing at
        //    rows that no longer existed and still report success. It was
        //    reverted, rightly. Nothing here switches that checking off.
        //
        //    Instead, a table that other tables point at is refused for a
        //    single-table restore, because emptying it on its own genuinely is
        //    unsafe: depending on how the link was set up the database will
        //    either refuse outright, or quietly delete the linked rows in those
        //    other tables as well. Restoring the whole snapshot handles those
        //    tables properly, in an order that keeps the links intact.
        $graph = $this->foreignKeyGraph();
        if ($graph === null) {
            return [
                'success'       => false,
                'rows_restored' => 0,
                'error'         => 'The database would not say how its tables are linked '
                                 . 'together, so the restore was not started. Nothing has '
                                 . 'been changed.',
            ];
        }

        $pointedAtBy = [];
        foreach ($graph as $child => $parents) {
            foreach ($parents as $parent) {
                $pointedAtBy[$parent][] = $child;
            }
        }

        if (isset($pointedAtBy[$table]) === true) {
            $others = array_unique($pointedAtBy[$table]);
            sort($others);
            return [
                'success'       => false,
                'rows_restored' => 0,
                'error'         => 'This table cannot be put back on its own, because other '
                                 . 'tables point at it: ' . implode(', ', array_slice($others, 0, 6))
                                 . (count($others) > 6 ? ', and others' : '') . '. Emptying it '
                                 . 'by itself would either be refused by the database, or would '
                                 . 'silently delete the linked rows in those other tables too. '
                                 . 'Restore the whole snapshot instead - that puts every table '
                                 . 'back in an order that keeps the links intact. Nothing has '
                                 . 'been changed.',
            ];
        }

        try {
            $this->db->begin_transaction();
            $this->db->query('DELETE FROM `' . $table . '`');

            $rows = $payload['rows'];

            // 📑 A snapshot of the settings table taken before migration 187
            //    contains duplicate portal-wide rows. Only one of each can go
            //    back in, so the order they are offered in decides which one
            //    survives — and the snapshot has no order at all.
            //
            //    Sort them by the same rule migration 187 uses, so the row an
            //    administrator actually changed goes in first and the leftover
            //    seed is the one turned away. Without this, whichever copy
            //    happened to be listed first would win, which could quietly
            //    replace a real setting with an untouched default.
            if ($table === 'tblSettings') {
                // Must match migration 187's rule EXACTLY, or the row kept
                // here differs from the row the migration would have kept.
                // Two details matter and are easy to get wrong:
                //
                //   * "no value at all" and "an empty value" are different
                //     things. Casting both to text would make them look the
                //     same, and a row whose value is empty while its default
                //     is absent HAS been edited.
                //   * a missing timestamp counts as the very beginning of
                //     1970, the same stand-in the migration uses, so the two
                //     agree about which row is newer.
                $looksEdited = static function (array $r): int {
                    $v = $r['settingValue'] ?? null;
                    $d = $r['defaultValue'] ?? null;
                    if ($v === null || $d === null) {
                        return ($v === $d) ? 0 : 1;
                    }
                    // Exact text, so a change of capitalisation counts.
                    return ((string) $v === (string) $d) ? 0 : 1;
                };
                $stamp = static function (array $r): string {
                    $u = $r['updatedAt'] ?? null;
                    return ($u === null || $u === '') ? '1970-01-01 00:00:00' : (string) $u;
                };
                usort($rows, static function ($a, $b) use ($looksEdited, $stamp): int {
                    if (is_array($a) === false || is_array($b) === false) {
                        return 0;
                    }
                    return [$looksEdited($b), $stamp($b), (int) ($b['settingID'] ?? 0)]
                       <=> [$looksEdited($a), $stamp($a), (int) ($a['settingID'] ?? 0)];
                });
            }
            $inserted = 0;
            $skippedDuplicates = 0;

            foreach ($rows as $row) {
                if (is_array($row) === false || count($row) === 0) {
                    continue;
                }
                foreach ($generated as $genCol) {
                    unset($row[$genCol]);
                }
                if (count($row) === 0) {
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

                // 🔁 A snapshot taken BEFORE a "no two rows may be the same"
                //    rule was added can contain rows that the rule now refuses.
                //    The pre-upgrade snapshot is exactly that case: it is taken
                //    before the migrations run, so a settings snapshot from
                //    before migration 187 still holds the duplicate rows that
                //    migration was written to remove.
                //
                //    Refusing the whole restore would leave an administrator
                //    with no way back at the very moment they need one. So a
                //    row the rule rejects is counted and skipped, and the count
                //    is reported at the end. Every other kind of failure still
                //    stops the restore.
                //
                //    1062 is the database's number for "that would be a
                //    duplicate"; 1586 is the same thing worded differently.
                try {
                    $stmt->execute();
                    $inserted++;
                } catch (\mysqli_sql_exception $rowError) {
                    if ($rowError->getCode() === 1062 || $rowError->getCode() === 1586) {
                        $skippedDuplicates++;
                    } else {
                        // Any other failure stops the restore. Be careful what
                        // you take that to mean: the rollback below CANNOT put
                        // the table's old contents back, because emptying it
                        // used TRUNCATE, which commits straight away and cannot
                        // be undone. The table is left part-restored. That is a
                        // long-standing fault in this restore, tracked as its
                        // own issue — not something introduced here.
                        $stmt->close();
                        throw $rowError;
                    }
                }
                $stmt->close();
            }
            $this->db->commit();

            $result = ['success' => true, 'rows_restored' => $inserted];
            if ($skippedDuplicates > 0) {
                $result['skipped_duplicates'] = $skippedDuplicates;
                $result['notice'] = $skippedDuplicates . ' row(s) in this snapshot '
                    . 'were left out because the database no longer allows two rows '
                    . 'the same. This is expected when restoring a snapshot taken '
                    . 'before that rule was introduced — the duplicates were never '
                    . 'meant to be there. Everything else was restored.';
            }

            return $result;
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
