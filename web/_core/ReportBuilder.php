<?php
// Path: _core/ReportBuilder.php
/**
 * -----------------------------------------------------------------------------
 * Report Builder — definition compiler / executor / CSV / CRUD 🛡️📊
 * -----------------------------------------------------------------------------
 * `Portal\Core\ReportBuilder::compile()` is the ONE place in the entire
 * codebase that ever assembles Reports Builder SQL. A saved report is a
 * DEFINITION (registry keys + literal filter values) — never SQL, never a
 * stored siteID. Every identifier this class concatenates into a SQL
 * string is a `Portal\Core\ReportRegistry` constant reached by STRICT key
 * lookup (`array_key_exists()` / `in_array(..., true)`); every value is
 * bound via mysqli `bind_param()` with a types string built in lockstep
 * with the params array and an explicit `strlen() === count()` assert.
 *
 * `siteID = ?` from the CALLER's `Site::id()` is force-injected FIRST,
 * outside the user-filter parentheses, so no `OR` in a filter row can ever
 * bypass tenancy — see compile() step 5/6 below.
 *
 * 🛡️ INVARIANT: this file must NEVER read `$_GET`/`$_POST`/`$_REQUEST`/
 * `$_COOKIE` — every public method takes an already-decoded PHP array (the
 * request layer, i.e. the `_apps/admin/reports/builder/*.php` handlers,
 * validates + maps request input into that array BEFORE calling in here).
 * (grep-provable — see the PR's security self-proof.)
 *
 * A saved definition is re-validated against the registry on EVERY run —
 * compile() is always re-invoked from the stored JSON, never trusted from
 * a cache — so a row hand-edited in the database to reference an unknown
 * key, a now-removed column, or a role the runner doesn't hold fails
 * CLOSED at run time, never at the SQL layer.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/156
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class ReportBuilder
{
    // ── Limits (class constants, not settings — structural, not tunable) ──
    public const MAX_COLUMNS     = 20;
    public const MAX_FILTERS     = 15;
    public const MAX_IN_VALUES   = 20;   // must equal ReportRegistry operator('in')['max']
    public const MAX_AGGREGATES  = 6;
    public const MAX_VALUE_BYTES = 500;  // per bound filter value
    public const MAX_DEF_BYTES   = 16384; // stored/posted definition JSON cap

    /** Hard structural ceiling on a definition's own optional "limit" key —
     *  independent of (and always ANDed with) the per-site
     *  reports.builder.maxRows setting applied in run()/streamCsv(). */
    private const MAX_DEFINITION_LIMIT = 100000;

    /** Top-level keys a definition may contain — anything else is refused. */
    private const ALLOWED_TOP_KEYS = ['v', 'source', 'columns', 'filters', 'group', 'aggregates', 'sort', 'limit'];

    private const CONJUNCTIONS = ['AND', 'OR'];
    private const SORT_DIRS    = ['asc' => 'ASC', 'desc' => 'DESC'];

    // =========================================================================
    // 🛡️ THE compiler
    // =========================================================================

    /**
     * Validate + compile a decoded report definition into a bound SQL
     * statement. Pure — no DB execution, no session reads besides the
     * $siteId argument the CALLER supplies from Site::id() (never from the
     * definition itself, which has no siteID field at all).
     *
     * @param array<string, mixed> $definition Decoded definition (see class docblock)
     * @param int                  $siteId     Site::id() of the CALLER — never from $definition
     *
     * @return array{sql:string, types:string, params:array<int,int|float|string>,
     *               columns:array<int,array{key:string,label:string,type:string}>,
     *               definitionLimit:?int}
     *
     * @throws \InvalidArgumentException User-safe message on any unknown key,
     *         type/operator mismatch, gate failure, arity error, or size-cap breach.
     */
    public static function compile(array $definition, int $siteId): array
    {
        // ── 1. Shape gate ────────────────────────────────────────────────
        foreach (array_keys($definition) as $key) {
            if (in_array($key, self::ALLOWED_TOP_KEYS, true) === false) {
                throw new \InvalidArgumentException('Unknown report definition field.');
            }
        }
        if (array_key_exists('v', $definition) === false || $definition['v'] !== ReportRegistry::FORMAT_VERSION) {
            throw new \InvalidArgumentException('Unsupported report definition version.');
        }
        $encoded = json_encode($definition);
        if ($encoded === false || strlen($encoded) > self::MAX_DEF_BYTES) {
            throw new \InvalidArgumentException('Report definition too large.');
        }
        if (is_string($definition['source'] ?? null) === false || $definition['source'] === '') {
            throw new \InvalidArgumentException('A data source is required.');
        }

        // ── 2. Source resolve (V) ───────────────────────────────────────
        $sourceKey = $definition['source'];
        $src = ReportRegistry::source($sourceKey);
        if ($src === null) {
            throw new \InvalidArgumentException('Unknown data source.');
        }
        if (AppRegistry::isEnabled((string) $src['appSlug']) === false) {
            throw new \InvalidArgumentException('This data source is not available.');
        }

        $joinsNeeded = [];
        $selectList  = [];
        $outputKeys  = [];
        $outputMeta  = [];

        $groupDef = $definition['group'] ?? null;
        $isGrouped = $groupDef !== null;

        if ($isGrouped === true) {
            // ── 3/4/7. GROUP BY mode ────────────────────────────────────
            if (is_array($groupDef) === false
                || is_string($groupDef['col'] ?? null) === false
                || $groupDef['col'] === ''
            ) {
                throw new \InvalidArgumentException('A group column is required.');
            }
            $groupColKey = $groupDef['col'];
            $groupCol    = self::resolveColumn($sourceKey, $groupColKey);
            self::noteJoin($joinsNeeded, $groupCol);

            $transformKey = $groupDef['transform'] ?? null;
            $groupExpr    = $groupCol['expr'];
            if ($transformKey !== null) {
                if (is_string($transformKey) === false) {
                    throw new \InvalidArgumentException('Invalid group bucket.');
                }
                $template = ReportRegistry::transform($transformKey);
                if ($template === null) {
                    throw new \InvalidArgumentException('Unknown group bucket.');
                }
                if (in_array($groupCol['type'], ['date', 'datetime'], true) === false) {
                    throw new \InvalidArgumentException('Grouping buckets only apply to date/datetime columns.');
                }
                $groupExpr = sprintf($template, $groupExpr);
            }

            // Grouped mode forbids `columns` entries other than the group
            // column — refuse rather than silently drop (§4.3 step 7).
            if (isset($definition['columns']) === true) {
                if (is_array($definition['columns']) === false
                    || array_diff($definition['columns'], [$groupColKey]) !== []
                ) {
                    throw new \InvalidArgumentException('A grouped report cannot select additional plain columns.');
                }
            }

            $selectList[] = $groupExpr . ' AS `' . $groupColKey . '`';
            $outputKeys[] = $groupColKey;
            $outputMeta[] = ['key' => $groupColKey, 'label' => (string) $groupCol['label'], 'type' => (string) $groupCol['type']];

            $aggregates = $definition['aggregates'] ?? [];
            if (is_array($aggregates) === false || count($aggregates) === 0) {
                throw new \InvalidArgumentException('At least one aggregate is required for a grouped report.');
            }
            if (count($aggregates) > self::MAX_AGGREGATES) {
                throw new \InvalidArgumentException('Too many aggregates.');
            }
            foreach ($aggregates as $agg) {
                if (is_array($agg) === false || array_key_exists('fn', $agg) === false || is_string($agg['fn']) === false) {
                    throw new \InvalidArgumentException('Invalid aggregate.');
                }
                $fnKey = $agg['fn'];
                $template = ReportRegistry::aggregation($fnKey);
                if ($template === null) {
                    throw new \InvalidArgumentException('Unknown aggregate function.');
                }
                $aggColKeyRaw = $agg['col'] ?? null;

                if ($aggColKeyRaw === null) {
                    // Special case: {"col": null, "fn": "count"} -> COUNT(*)
                    if ($fnKey !== 'count') {
                        throw new \InvalidArgumentException('Only count() may omit a column.');
                    }
                    $aggExprSql = sprintf($template, '*');
                    $aggAlias   = 'count_all';
                    $aggType    = 'int';
                } else {
                    if (is_string($aggColKeyRaw) === false || $aggColKeyRaw === '') {
                        throw new \InvalidArgumentException('Invalid aggregate column.');
                    }
                    $aggCol = self::resolveColumn($sourceKey, $aggColKeyRaw);
                    $allowedAggs = ReportRegistry::columnAggregations($sourceKey, $aggColKeyRaw);
                    if (in_array($fnKey, $allowedAggs, true) === false) {
                        throw new \InvalidArgumentException('That aggregate is not available for this column.');
                    }
                    self::noteJoin($joinsNeeded, $aggCol);
                    $aggExprSql = sprintf($template, $aggCol['expr']);
                    $aggAlias   = $fnKey . '_' . $aggColKeyRaw;
                    $aggType    = self::aggregateOutputType($fnKey, $aggCol);
                }

                if (in_array($aggAlias, $outputKeys, true) === true) {
                    throw new \InvalidArgumentException('Duplicate aggregate.');
                }
                $selectList[] = $aggExprSql . ' AS `' . $aggAlias . '`';
                $outputKeys[] = $aggAlias;
                $outputMeta[] = ['key' => $aggAlias, 'label' => self::aggregateLabel($fnKey, $aggColKeyRaw), 'type' => $aggType];
            }
        } else {
            // ── 3/4. Plain SELECT mode ──────────────────────────────────
            $columns = $definition['columns'] ?? null;
            if (is_array($columns) === false || count($columns) === 0) {
                throw new \InvalidArgumentException('At least one column is required.');
            }
            if (count($columns) > self::MAX_COLUMNS) {
                throw new \InvalidArgumentException('Too many columns.');
            }
            foreach ($columns as $colKey) {
                if (is_string($colKey) === false || $colKey === '') {
                    throw new \InvalidArgumentException('Invalid column.');
                }
                if (in_array($colKey, $outputKeys, true) === true) {
                    throw new \InvalidArgumentException('Duplicate column.');
                }
                $col = self::resolveColumn($sourceKey, $colKey);
                self::noteJoin($joinsNeeded, $col);
                $selectList[] = $col['expr'] . ' AS `' . $colKey . '`';
                $outputKeys[] = $colKey;
                $outputMeta[] = ['key' => $colKey, 'label' => (string) $col['label'], 'type' => (string) $col['type']];
            }
        }

        // ── 5. WHERE — tenant scope FIRST (forced) ──────────────────────
        $where  = [(string) $src['siteExpr'] . ' = ?'];
        $types  = 'i';
        $params = [$siteId];
        foreach ((array) ($src['fixedWhere'] ?? []) as $fixed) {
            $where[] = (string) $fixed;
        }

        // ── 6. WHERE — user filter rows (V for col+op, B for vals) ──────
        $filtersDef = $definition['filters'] ?? null;
        if ($filtersDef !== null) {
            if (is_array($filtersDef) === false
                || is_string($filtersDef['conjunction'] ?? null) === false
                || is_array($filtersDef['rows'] ?? null) === false
            ) {
                throw new \InvalidArgumentException('Invalid filter block.');
            }
            $conjunction = $filtersDef['conjunction'];
            if (in_array($conjunction, self::CONJUNCTIONS, true) === false) {
                throw new \InvalidArgumentException('Invalid filter conjunction.');
            }
            $rows = $filtersDef['rows'];
            if (count($rows) > self::MAX_FILTERS) {
                throw new \InvalidArgumentException('Too many filters.');
            }

            $rowClauses = [];
            foreach ($rows as $row) {
                if (is_array($row) === false
                    || is_string($row['col'] ?? null) === false
                    || is_string($row['op'] ?? null) === false
                ) {
                    throw new \InvalidArgumentException('Invalid filter row.');
                }
                $col = self::resolveColumn($sourceKey, $row['col']);
                self::noteJoin($joinsNeeded, $col);

                $op = ReportRegistry::operator($row['op']);
                if ($op === null) {
                    throw new \InvalidArgumentException('Unknown filter operator.');
                }
                if (ReportRegistry::operatorAllowedForType($row['op'], (string) $col['type']) === false) {
                    throw new \InvalidArgumentException('That operator is not valid for this column type.');
                }

                $vals = $row['vals'] ?? [];
                if (is_array($vals) === false) {
                    throw new \InvalidArgumentException('Invalid filter values.');
                }
                $arity = $op['arity'];

                if ($arity === 0) {
                    if (count($vals) !== 0) {
                        throw new \InvalidArgumentException('This operator does not take a value.');
                    }
                    $rowClauses[] = sprintf((string) $op['sql'], $col['expr']);
                    continue;
                }

                if ($arity === 'n') {
                    $max = (int) ($op['max'] ?? self::MAX_IN_VALUES);
                    $n = count($vals);
                    if ($n < 1 || $n > $max) {
                        throw new \InvalidArgumentException('Invalid number of filter values.');
                    }
                    $placeholders = implode(', ', array_fill(0, $n, '?'));
                    $withColumn   = sprintf((string) $op['sql'], $col['expr']); // "expr IN (%s)"
                    $rowClauses[] = sprintf($withColumn, $placeholders);
                    foreach ($vals as $v) {
                        [$boundValue, $typeChar] = self::coerceValue($col, $v, false);
                        $params[] = $boundValue;
                        $types   .= $typeChar;
                    }
                    continue;
                }

                // Fixed arity (1 or 2).
                if (count($vals) !== (int) $arity) {
                    throw new \InvalidArgumentException('Invalid number of filter values.');
                }
                $rowClauses[] = sprintf((string) $op['sql'], $col['expr']);
                $contains = (bool) ($op['contains'] ?? false);
                foreach ($vals as $v) {
                    [$boundValue, $typeChar] = self::coerceValue($col, $v, $contains);
                    $params[] = $boundValue;
                    $types   .= $typeChar;
                }
            }

            if (count($rowClauses) > 0) {
                // 🛡️ Wrapped in its own parentheses and AND-ed onto the
                // tenant scope + fixedWhere above — an OR conjunction here
                // can only ever widen matches WITHIN this parenthesised
                // group, never escape it. Tenant scope can never be
                // OR-bypassed.
                $where[] = '(' . implode(' ' . $conjunction . ' ', $rowClauses) . ')';
            }
        }

        $whereSql = implode(' AND ', $where);

        // ── GROUP BY (grouped mode only) ────────────────────────────────
        $groupBySql = '';
        if ($isGrouped === true) {
            $groupBySql = ' GROUP BY `' . $outputKeys[0] . '`';
        }

        // ── 8. ORDER BY (V) ──────────────────────────────────────────────
        $orderSql = '';
        $sortDef  = $definition['sort'] ?? null;
        if ($sortDef !== null) {
            if (is_array($sortDef) === false || is_string($sortDef['col'] ?? null) === false) {
                throw new \InvalidArgumentException('Invalid sort.');
            }
            if (in_array($sortDef['col'], $outputKeys, true) === false) {
                throw new \InvalidArgumentException('Sort column must be one of the selected/aggregated columns.');
            }
            $dirKey = (string) ($sortDef['dir'] ?? 'asc');
            if (array_key_exists($dirKey, self::SORT_DIRS) === false) {
                throw new \InvalidArgumentException('Invalid sort direction.');
            }
            $orderSql = ' ORDER BY `' . $sortDef['col'] . '` ' . self::SORT_DIRS[$dirKey];
        } else {
            // Deterministic default ordering for stable pagination.
            $orderSql = ' ORDER BY `' . $outputKeys[0] . '` ASC';
        }

        // ── 9. JOINs (V) ─────────────────────────────────────────────────
        $joinSql = '';
        $emitted = [];
        foreach ((array) ($src['mandatoryJoins'] ?? []) as $mJoinKey) {
            $joinSql .= ' ' . (string) $src['joins'][$mJoinKey];
            $emitted[$mJoinKey] = true;
        }
        foreach (array_keys($joinsNeeded) as $jKey) {
            if (isset($emitted[$jKey]) === true) {
                continue;
            }
            $joinSql .= ' ' . (string) $src['joins'][$jKey];
            $emitted[$jKey] = true;
        }

        // ── Optional definition-level row cap (§4.2 "limit") ────────────
        $definitionLimit = null;
        if (array_key_exists('limit', $definition) === true) {
            $limitRaw = $definition['limit'];
            if (is_int($limitRaw) === false || $limitRaw < 1 || $limitRaw > self::MAX_DEFINITION_LIMIT) {
                throw new \InvalidArgumentException('Invalid row limit.');
            }
            $definitionLimit = $limitRaw;
        }

        // ── 10. Assemble ─────────────────────────────────────────────────
        // At this point every substring originated in ReportRegistry
        // constants or in generated placeholder text — the only user
        // contribution is inside $params.
        $sql = 'SELECT ' . implode(', ', $selectList)
             . ' FROM `' . (string) $src['table'] . '` ' . (string) $src['alias']
             . $joinSql
             . ' WHERE ' . $whereSql
             . $groupBySql
             . $orderSql;

        if (strlen($types) !== count($params)) {
            // 🧷 Belt-and-braces — should be unreachable given the lockstep
            // construction above, but fail loudly rather than silently.
            throw new \RuntimeException('Report compiler produced a bind arity mismatch.');
        }

        return [
            'sql'             => $sql,
            'types'           => $types,
            'params'          => $params,
            'columns'         => $outputMeta,
            'definitionLimit' => $definitionLimit,
        ];
    }

    /**
     * Shape-validate + compile without executing — used by save.php as the
     * pre-save gate. Throws exactly as compile() does.
     */
    public static function validateDefinition(array $definition, int $siteId): void
    {
        self::compile($definition, $siteId);
    }

    // =========================================================================
    // ▶️ Execution
    // =========================================================================

    /**
     * Execute a compiled report. Appends `LIMIT ? OFFSET ?` (both bound
     * 'i'). $limit is clamped 1..$compiled['definitionLimit'] (when set).
     * Callers (run.php/preview.php) additionally clamp against the
     * per-site `reports.builder.maxRows` setting before calling this.
     *
     * @param array{sql:string, types:string, params:array<int,mixed>, definitionLimit:?int} $compiled
     *
     * @return array{rows:array<int,array<string,mixed>>, hasMore:bool}
     */
    public static function run(array $compiled, int $limit, int $offset): array
    {
        $limit = max(1, $limit);
        if (isset($compiled['definitionLimit']) === true && $compiled['definitionLimit'] !== null) {
            $limit = min($limit, (int) $compiled['definitionLimit']);
        }
        $offset = max(0, $offset);
        $fetchCap = $limit + 1; // one extra row to derive hasMore without a COUNT(*) query

        $sql    = $compiled['sql'] . ' LIMIT ? OFFSET ?';
        $types  = $compiled['types'] . 'ii';
        $params = $compiled['params'];
        $params[] = $fetchCap;
        $params[] = $offset;

        if (strlen($types) !== count($params)) {
            throw new \RuntimeException('Report compiler produced a bind arity mismatch.');
        }

        $db = App::db();
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare report query.');
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $stmt->close();

        $hasMore = count($rows) > $limit;
        if ($hasMore === true) {
            array_pop($rows);
        }

        return ['rows' => $rows, 'hasMore' => $hasMore];
    }

    /**
     * 📊 Stream CSV via CsvExporter::download() (CWE-1236 handled there).
     * Row cap = min(reports.builder.maxRows, definitionLimit).
     *
     * @param array{sql:string, types:string, params:array<int,mixed>,
     *              columns:array<int,array{key:string,label:string,type:string}>,
     *              definitionLimit:?int} $compiled
     */
    public static function streamCsv(array $compiled, string $filename): never
    {
        $maxRows = (int) (App::settings('reports.builder.maxRows') ?? 10000);
        if ($maxRows <= 0) {
            $maxRows = 10000;
        }
        if (isset($compiled['definitionLimit']) === true && $compiled['definitionLimit'] !== null) {
            $maxRows = min($maxRows, (int) $compiled['definitionLimit']);
        }

        $sql    = $compiled['sql'] . ' LIMIT ?';
        $types  = $compiled['types'] . 'i';
        $params = $compiled['params'];
        $params[] = $maxRows;

        if (strlen($types) !== count($params)) {
            throw new \RuntimeException('Report compiler produced a bind arity mismatch.');
        }

        $db = App::db();
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare report query.');
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $headers = [];
        foreach ((array) $compiled['columns'] as $meta) {
            $headers[] = (string) $meta['label'];
        }

        $outRows = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $mapped = [];
            foreach ((array) $compiled['columns'] as $meta) {
                $mapped[(string) $meta['label']] = $row[(string) $meta['key']] ?? '';
            }
            $outRows[] = $mapped;
        }
        $stmt->close();

        CsvExporter::download($filename, $outRows, $headers);
    }

    // =========================================================================
    // 🧰 Request-layer helper (NOT a superglobal read — takes a raw string)
    // =========================================================================

    /**
     * Safe "definition JSON in, PHP array out" decode shared by every
     * handler that reads a report definition — from a POST body
     * (preview.php/save.php) OR from a stored `tblReportDefinitions.definition`
     * column (run.php/edit.php/export.php). Enforces the byte cap BEFORE
     * decoding and a shallow depth cap, exactly once, so every call site
     * gets the same defence-in-depth regardless of where the JSON came
     * from. Does not touch superglobals — the raw string is a parameter.
     *
     * @throws \InvalidArgumentException on any decode/shape failure
     */
    public static function decodeDefinitionJson(string $raw): array
    {
        if ($raw === '' || strlen($raw) > self::MAX_DEF_BYTES) {
            throw new \InvalidArgumentException('Report definition is missing or too large.');
        }
        $decoded = json_decode($raw, true, 8);
        if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
            throw new \InvalidArgumentException('Report definition is not valid JSON.');
        }
        return $decoded;
    }

    // =========================================================================
    // 🗂️ Saved-definition CRUD — all site-scoped, all prepared statements
    // =========================================================================

    /**
     * Create or update a saved report definition. Re-runs
     * validateDefinition() server-side even though save.php already did
     * (defence against a future alternate caller).
     *
     * @return int reportID (new or existing)
     */
    public static function save(
        int $siteId,
        int $userId,
        ?int $reportId,
        string $name,
        ?string $description,
        array $definition,
        bool $isShared
    ): int {
        self::validateDefinition($definition, $siteId);

        $defJson = json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($defJson === false || strlen($defJson) > self::MAX_DEF_BYTES) {
            throw new \InvalidArgumentException('Report definition too large to save.');
        }
        $sourceKey = (string) $definition['source'];
        $sharedInt = $isShared === true ? 1 : 0;

        $db = App::db();

        if ($reportId === null || $reportId <= 0) {
            $stmt = $db->prepare(
                'INSERT INTO tblReportDefinitions '
                . '(siteID, reportName, description, sourceKey, definition, isShared, createdByID, updatedByID) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if ($stmt === false) {
                throw new \RuntimeException('Failed to prepare report insert.');
            }
            $stmt->bind_param('issssiii', $siteId, $name, $description, $sourceKey, $defJson, $sharedInt, $userId, $userId);
            $stmt->execute();
            $newId = (int) $stmt->insert_id;
            $stmt->close();
            return $newId;
        }

        $stmt = $db->prepare(
            'UPDATE tblReportDefinitions '
            . 'SET reportName = ?, description = ?, sourceKey = ?, definition = ?, isShared = ?, updatedByID = ? '
            . 'WHERE reportID = ? AND siteID = ?'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare report update.');
        }
        $stmt->bind_param('ssssiiii', $name, $description, $sourceKey, $defJson, $sharedInt, $userId, $reportId, $siteId);
        $stmt->execute();
        $stmt->close();
        return $reportId;
    }

    /**
     * Fetch one saved report row (raw — `definition` stays a JSON string).
     * Cross-site is indistinguishable from missing (returns null either
     * way — Workflow::get() precedent).
     *
     * @return array<string, mixed>|null
     */
    public static function get(int $reportId, int $siteId): ?array
    {
        $db = App::db();
        $stmt = $db->prepare('SELECT * FROM tblReportDefinitions WHERE reportID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $reportId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row === null ? null : $row;
    }

    /**
     * List reports visible on this site to this caller: shared (isShared=1)
     * OR authored by $userId.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listForSite(int $siteId, int $userId): array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT * FROM tblReportDefinitions WHERE siteID = ? AND (isShared = 1 OR createdByID = ?) ORDER BY reportName'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ii', $siteId, $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Delete a saved report (site-scoped). Returns true when a row was
     * actually removed.
     */
    public static function delete(int $reportId, int $siteId): bool
    {
        $db = App::db();
        $stmt = $db->prepare('DELETE FROM tblReportDefinitions WHERE reportID = ? AND siteID = ?');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $reportId, $siteId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected > 0;
    }

    /**
     * Record a run (lastRunAt=NOW(), runCount+1). Best-effort — a failed
     * prepare is silently ignored, mirroring the house pattern elsewhere
     * for non-critical audit-adjacent counters.
     */
    public static function touchRun(int $reportId, int $siteId): void
    {
        $db = App::db();
        $stmt = $db->prepare('UPDATE tblReportDefinitions SET lastRunAt = NOW(), runCount = runCount + 1 WHERE reportID = ? AND siteID = ?');
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('ii', $reportId, $siteId);
        $stmt->execute();
        $stmt->close();
    }

    // =========================================================================
    // 🔒 Internal helpers
    // =========================================================================

    /**
     * Resolve one column by (sourceKey, columnKey) and enforce its gates —
     * shared by SELECT/GROUP/aggregate/WHERE resolution so gates bind
     * IDENTICALLY in every SQL position (§3.4 — filtering on a column you
     * cannot see is refused, not just selecting it).
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    private static function resolveColumn(string $sourceKey, string $columnKey): array
    {
        $col = ReportRegistry::column($sourceKey, $columnKey);
        if ($col === null) {
            throw new \InvalidArgumentException('Unknown column.');
        }
        if (ReportRegistry::callerPassesGates((array) ($col['gates'] ?? [])) === false) {
            throw new \InvalidArgumentException('This column requires an additional role.');
        }
        return $col;
    }

    /**
     * Record that a column's declared 'join' key is needed in the final
     * JOIN clause (a join referenced by no selected/filtered column is
     * never emitted).
     *
     * @param array<string, bool> $joinsNeeded
     */
    private static function noteJoin(array &$joinsNeeded, array $col): void
    {
        if (isset($col['join']) === true) {
            $joinsNeeded[(string) $col['join']] = true;
        }
    }

    /**
     * Validate + coerce one filter value for binding, per registry column
     * $col's TYPE (§4.3 step 6). Returns [boundValue, mysqli type char].
     * Values are bound via bind_param() regardless — this per-type check
     * is defence-in-depth, not the only guard.
     *
     * @param array<string, mixed> $col Resolved registry column entry
     *
     * @return array{0: int|float|string, 1: string}
     * @throws \InvalidArgumentException
     */
    private static function coerceValue(array $col, mixed $raw, bool $contains): array
    {
        // 🛡️ A filter value must be a scalar (string/int/float/bool) —
        // json_decode() can hand back an array/object/null for a
        // maliciously-shaped `vals` entry (e.g. `{"vals":[[1,2]]}` or
        // `{"vals":[null]}`); reject those before any type-specific
        // coercion touches them (avoids relying on filter_var()/is_numeric()
        // implicit-array-handling edge cases).
        if (is_scalar($raw) === false) {
            throw new \InvalidArgumentException('Invalid filter value.');
        }

        $type = (string) $col['type'];

        if ($type === 'int' || $type === 'bool') {
            $v = filter_var($raw, FILTER_VALIDATE_INT);
            if ($v === false) {
                throw new \InvalidArgumentException('Expected a whole number.');
            }
            return [$v, 'i'];
        }
        if ($type === 'decimal') {
            if (is_numeric($raw) === false) {
                throw new \InvalidArgumentException('Expected a number.');
            }
            return [(float) $raw, 'd'];
        }
        if ($type === 'date') {
            $s = (string) $raw;
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) !== 1
                || checkdate((int) $m[2], (int) $m[3], (int) $m[1]) === false
            ) {
                throw new \InvalidArgumentException('Expected a date (YYYY-MM-DD).');
            }
            return [$s, 's'];
        }
        if ($type === 'datetime') {
            $s = (string) $raw;
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $s);
            if ($dt === false) {
                $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $s);
            }
            if ($dt === false) {
                throw new \InvalidArgumentException('Expected a date/time (YYYY-MM-DD HH:MM[:SS]).');
            }
            return [$s, 's'];
        }
        if ($type === 'enum') {
            // Strict membership against THIS column's own declared enum
            // list — values outside it are refused, never silently coerced.
            $allowed = (array) ($col['enum'] ?? []);
            if (is_string($raw) === false || in_array($raw, $allowed, true) === false) {
                throw new \InvalidArgumentException('Invalid enum value.');
            }
            return [$raw, 's'];
        }
        // string
        $s = (string) $raw;
        if (mb_check_encoding($s, 'UTF-8') === false || strlen($s) > self::MAX_VALUE_BYTES) {
            throw new \InvalidArgumentException('Invalid text value.');
        }
        if ($contains === true) {
            $s = '%' . addcslashes($s, '%_\\') . '%';
        }
        return [$s, 's'];
    }

    /**
     * Aggregate output type for a header/format hint: sum/avg widen to
     * decimal; count/countDistinct are always int; min/max preserve the
     * underlying column's type.
     */
    private static function aggregateOutputType(string $fn, array $col): string
    {
        if ($fn === 'count' || $fn === 'countDistinct') {
            return 'int';
        }
        if ($fn === 'sum' || $fn === 'avg') {
            return 'decimal';
        }
        return (string) $col['type'];
    }

    private static function aggregateLabel(string $fn, ?string $colKey): string
    {
        $names = [
            'count' => 'Count', 'countDistinct' => 'Distinct count',
            'sum' => 'Sum', 'avg' => 'Average', 'min' => 'Minimum', 'max' => 'Maximum',
        ];
        $fnLabel = $names[$fn] ?? $fn;
        return $colKey === null ? $fnLabel : $fnLabel . ' of ' . $colKey;
    }
}
