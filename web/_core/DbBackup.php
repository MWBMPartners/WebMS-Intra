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
        //    TRUNCATE is used deliberately, and it has two known limits that
        //    are LEFT ALONE here on purpose:
        //
        //      1. It commits immediately and cannot be undone, so the
        //         transaction below cannot recover the old contents if a row
        //         fails part way through.
        //      2. MySQL refuses it outright on any table that other tables
        //         point at — 71 of the 209 tables here. Restoring one of those
        //         has therefore never worked.
        //
        //    Both are real faults and both predate this change. An earlier
        //    version of this work swapped in DELETE with the database's
        //    related-record checking switched off, which fixed them — and
        //    introduced something worse: with that checking off, a restore
        //    could leave records pointing at things that are no longer there,
        //    and report success. Properly fixing restore means validating
        //    relationships before committing and treating a full restore as
        //    one all-or-nothing operation, which is its own piece of work and
        //    does not belong in a settings change. It is written up as its own
        //    issue.
        //
        //    Nothing points at tblSettings, so TRUNCATE works fine for the one
        //    table this change actually needs to be restorable.
        try {
            $this->db->begin_transaction();
            $this->db->query('TRUNCATE TABLE `' . $table . '`');

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
