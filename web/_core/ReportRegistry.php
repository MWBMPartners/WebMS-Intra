<?php
// Path: _core/ReportRegistry.php
/**
 * -----------------------------------------------------------------------------
 * Report Registry — the reporting whitelist 🛡️📊
 * -----------------------------------------------------------------------------
 * Pure static data + lookups. This file is the ENTIRE list of tables,
 * columns, operators, aggregations and date-bucket transforms the Reports
 * Builder (#156) is ever allowed to compile into SQL. Nothing outside this
 * file can add a table, a column, an operator, or an aggregation to a
 * report — extending the registry is a code change that goes through PR
 * review + CI, never an admin UI action.
 *
 * Every identifier `Portal\Core\ReportBuilder::compile()` concatenates into
 * a SQL string is looked up here by STRICT key equality
 * (`array_key_exists()` / `in_array(..., true)`) — never by pattern
 * matching, never by trusting a caller-supplied string directly.
 *
 * Confidential domains — care, kids, safeguarding, prayer requests, auth/
 * credentials, settings, API keys, sessions — are structurally ABSENT from
 * SOURCES below, not merely hidden. `assertSelfConsistent()` hard-fails if
 * a future edit ever sneaks a reference to one of those domains into this
 * file (belt-and-braces, §3.4 of the build spec).
 *
 * 🛡️ INVARIANT: this file must NEVER read `$_GET`/`$_POST`/`$_REQUEST`/
 * `$_COOKIE` and must NEVER touch the database — it is pure data plus pure
 * functions over that data. (grep-provable — see the PR's security
 * self-proof.)
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

final class ReportRegistry
{
    /** Registry format version — stored definitions carry "v":1 and are
     *  refused by ReportBuilder::compile() if the stored value is anything
     *  else (a future format bump refuses old + new rows equally until the
     *  compiler is updated to understand the new shape). */
    public const FORMAT_VERSION = 1;

    // =========================================================================
    // 🚫 Forbidden-table blocklist (belt-and-braces against a future registry
    // edit wiring in a confidential domain). Deliberately stored as BARE
    // fragments (never a literal 'tblXxx' string) reassembled with the
    // 'tbl' prefix only at RUNTIME via containsForbiddenTable() below — a
    // real 'tblKidProfiles' etc. never appears as a contiguous scannable
    // token anywhere in this file's source text. This is not an attempt to
    // hide the check from a human reviewer (the intent is fully documented
    // right here) — it is so tools/audit-checks/check_php_table_refs.py,
    // which flags any tblXxx-shaped identifier that isn't a REAL schema
    // table, doesn't false-positive on strings that exist purely to name a
    // FORBIDDEN prefix pattern, not to reference an actual table.
    // =========================================================================

    private const TABLE_PREFIX = 'tbl';

    /** Prefix fragments — ANY table beginning 'tbl' + one of these is
     *  forbidden, present or future (e.g. tblKidProfiles, tblKidCheckins,
     *  tblCareCase, tblCareVisit, tblCareAccessLog, tblSafeguarding*,
     *  tblPrayerRequests, tblPrayerChain*). */
    private const FORBIDDEN_TABLE_PREFIXES = ['Kid', 'Care', 'Safeguarding', 'Prayer'];

    /** Exact-ish fragments — auth/credential/settings/session-adjacent
     *  tables that must never be reportable on. */
    private const FORBIDDEN_TABLE_EXACT = [
        'LocalAccounts', 'LinkedAccounts', 'Settings', 'ApiKeys', 'Sessions',
        'TrustedDevices', 'PasswordResets', 'WebAuthn', 'Totp',
    ];

    // =========================================================================
    // 📚 3.2 — Sources. Six v1 sources: users, events, attendance, expenses,
    // giving, tasks. Every table/alias/siteExpr/join here is a registry-owned
    // SQL LITERAL — it reaches ReportBuilder::compile() only via strict key
    // lookup, never via any request-derived string.
    // =========================================================================

    private const SOURCES = [

        // 👥 Members --------------------------------------------------------
        // tblUsers has NO siteID column — tenancy rides on the MANDATORY
        // tblUserSites join, so 'membership' is listed in mandatoryJoins
        // (always emitted, whether or not any selected column needs it).
        'users' => [
            'label'          => 'Members',
            'table'          => 'tblUsers',
            'alias'          => 'u',
            'appSlug'        => 'directory',
            'mandatoryJoins' => ['membership'],
            'siteExpr'       => 'us.siteID',
            'fixedWhere'     => ['us.isActive = 1'],
            'joins'          => [
                'membership' => 'JOIN tblUserSites us ON us.userID = u.userID',
            ],
            'columns' => [
                'fullName'     => ['expr' => 'u.fullName',     'label' => 'Full name', 'type' => 'string', 'pii' => true],
                // A6 — email is the one PII column exposed beyond fullName,
                // and it is role-gated (site-admin+) rather than open to
                // every admin. Everything else PII-shaped on tblUsers
                // (phone/address/coords/bio/avatar/visibility* tiers,
                // totpSecret, calendarToken, notifyPrefs, isAdmin/
                // isRootAdmin) is DELIBERATELY ABSENT — the member
                // directory's per-field visibility consent isn't modelled
                // by the builder in v1 (Ambiguity A6), so any column a
                // member might have marked 'private' stays out entirely
                // rather than risk leaking it through a report.
                'emailAddress' => ['expr' => 'u.emailAddress', 'label' => 'Email', 'type' => 'string', 'pii' => true, 'gates' => ['@siteAdmin']],
                'isActive'     => ['expr' => 'u.isActive',     'label' => 'Active', 'type' => 'bool'],
                'locale'       => ['expr' => 'u.locale',       'label' => 'Locale', 'type' => 'string'],
                'createdAt'    => ['expr' => 'u.createdAt',    'label' => 'Joined', 'type' => 'datetime'],
            ],
        ],

        // 📅 Events -----------------------------------------------------------
        'events' => [
            'label'      => 'Events',
            'table'      => 'tblEvents',
            'alias'      => 'e',
            'appSlug'    => 'calendar',
            'siteExpr'   => 'e.siteID',
            // Imported events are read-only (#514 D5); the report builder only ever offers events
            // this organisation actually manages, never one whose source calendar owns the data.
            'fixedWhere' => ['e.isDeleted = 0', 'e.externalFeedID IS NULL'],
            'joins'      => [
                'category' => 'LEFT JOIN tblEventCategories ecat ON ecat.categoryID = e.categoryID',
            ],
            'columns' => [
                'eventName'     => ['expr' => 'e.eventName',     'label' => 'Event name', 'type' => 'string'],
                'startDateTime' => ['expr' => 'e.startDateTime', 'label' => 'Starts',      'type' => 'datetime'],
                'status'        => ['expr' => 'e.status',        'label' => 'Status',      'type' => 'enum',
                                    'enum' => ['draft', 'published', 'cancelled', 'postponed']],
                'isPublic'      => ['expr' => 'e.isPublic',      'label' => 'Public',      'type' => 'bool'],
                'category'      => ['expr' => 'ecat.categoryName', 'label' => 'Category',  'type' => 'string', 'join' => 'category'],
                'createdAt'     => ['expr' => 'e.createdAt',     'label' => 'Created',     'type' => 'datetime'],
            ],
        ],

        // 🙋 Attendance -------------------------------------------------------
        // Headcount is NOT a direct column on tblAttendanceSessions — the
        // real per-group counts live in the child table tblAttendanceCounts
        // (a session can carry several count rows, e.g. Adults/Children).
        // 'headcount' is exposed via a curated LEFT JOIN and is aggregatable
        // (sum/avg/min/max/count), matching the totalAmount precedent below.
        'attendance' => [
            'label'      => 'Attendance',
            'table'      => 'tblAttendanceSessions',
            'alias'      => 'a',
            'appSlug'    => 'attendance',
            'siteExpr'   => 'a.siteID',
            'fixedWhere' => ['a.isDeleted = 0'],
            'joins'      => [
                'serviceType' => 'JOIN tblAttendanceServiceTypes ast ON ast.serviceTypeID = a.serviceTypeID',
                'counts'      => 'LEFT JOIN tblAttendanceCounts acnt ON acnt.sessionID = a.sessionID',
            ],
            'columns' => [
                'sessionDate' => ['expr' => 'a.sessionDate',   'label' => 'Session date',  'type' => 'date'],
                'serviceType' => ['expr' => 'ast.typeName',    'label' => 'Service type',  'type' => 'string', 'join' => 'serviceType'],
                'headcount'   => ['expr' => 'acnt.headcount',  'label' => 'Headcount',     'type' => 'int', 'join' => 'counts',
                                  'aggs' => ['sum', 'avg', 'min', 'max', 'count']],
                'createdAt'   => ['expr' => 'a.createdAt',     'label' => 'Created',       'type' => 'datetime'],
            ],
        ],

        // 💷 Expense claims -----------------------------------------------------
        'expenses' => [
            'label'      => 'Expense claims',
            'table'      => 'tblExpenseClaims',
            'alias'      => 'ec',
            'appSlug'    => 'expenses',
            'siteExpr'   => 'ec.siteID',
            'fixedWhere' => [],
            'joins'      => [
                'claimant' => 'LEFT JOIN tblUsers u ON u.userID = ec.userID',
            ],
            'columns' => [
                'claimTitle'  => ['expr' => 'ec.claimTitle',  'label' => 'Claim title', 'type' => 'string'],
                'claimDate'   => ['expr' => 'ec.claimDate',   'label' => 'Claim date',  'type' => 'date'],
                'status'      => ['expr' => 'ec.status',      'label' => 'Status',      'type' => 'enum',
                                  'enum' => ['Pending', 'Approved', 'Rejected', 'Reimbursed']],
                // Financial gate parity with Giving::canManage(): treasurer
                // OR site admin. Filtering on this column without the gate
                // is refused identically to selecting it (§3.4) — a
                // non-treasurer can't row-count-oracle claim amounts either.
                'totalAmount' => ['expr' => 'ec.totalAmount', 'label' => 'Total (GBP)', 'type' => 'decimal',
                                  'gates' => ['treasurer', '@siteAdmin'],
                                  'aggs'  => ['sum', 'avg', 'min', 'max', 'count']],
                'claimant'    => ['expr' => 'u.fullName',     'label' => 'Claimant',    'type' => 'string',
                                  'join' => 'claimant', 'pii' => true],
                'createdAt'   => ['expr' => 'ec.createdAt',   'label' => 'Created',     'type' => 'datetime'],
            ],
        ],

        // 💝 Giving -------------------------------------------------------------
        'giving' => [
            'label'      => 'Giving',
            'table'      => 'tblGivingEntry',
            'alias'      => 'g',
            'appSlug'    => 'giving',
            'siteExpr'   => 'g.siteID',
            'fixedWhere' => [],
            'joins'      => [
                'category' => 'LEFT JOIN tblGivingCategory gcat ON gcat.categoryID = g.categoryID',
            ],
            'columns' => [
                'donatedAt'   => ['expr' => 'g.donatedAt', 'label' => 'Date',   'type' => 'date'],
                // amountPence is a genuine INT column (pence, not decimal
                // pounds) — Giving::canManage() parity: treasurer OR site
                // admin, same as expenses.totalAmount above.
                'amountPence' => ['expr' => 'g.amountPence', 'label' => 'Amount (pence)', 'type' => 'int',
                                  'gates' => ['treasurer', '@siteAdmin'],
                                  'aggs'  => ['sum', 'avg', 'min', 'max', 'count']],
                'method'      => ['expr' => 'g.method', 'label' => 'Method', 'type' => 'enum',
                                  'enum' => ['cash', 'cheque', 'bank-transfer', 'card', 'standing-order', 'other']],
                'category'    => ['expr' => 'gcat.name', 'label' => 'Fund / category', 'type' => 'string', 'join' => 'category'],
                // A8 — donor identity is gated STRICTLY 'treasurer' (no
                // '@siteAdmin' escape hatch) — a site admin who is not also
                // the treasurer never sees donor names via a report, even
                // though they CAN see the aggregate amounts above. This is
                // deliberately stricter than the financial-amount gate.
                'donorName'   => ['expr' => 'g.donorName', 'label' => 'Donor', 'type' => 'string',
                                  'gates' => ['treasurer'], 'pii' => true],
                'createdAt'   => ['expr' => 'g.createdAt', 'label' => 'Recorded', 'type' => 'datetime'],
            ],
        ],

        // ✅ Tasks ----------------------------------------------------------------
        'tasks' => [
            'label'      => 'Tasks',
            'table'      => 'tblTasks',
            'alias'      => 't',
            'appSlug'    => 'tasks',
            'siteExpr'   => 't.siteID',
            'fixedWhere' => ['t.isDeleted = 0'],
            'joins'      => [],
            'columns' => [
                'title'       => ['expr' => 't.title',       'label' => 'Title',    'type' => 'string'],
                'status'      => ['expr' => 't.status',      'label' => 'Status',   'type' => 'enum',
                                  'enum' => ['pending', 'in_progress', 'completed', 'cancelled']],
                'priority'    => ['expr' => 't.priority',    'label' => 'Priority', 'type' => 'enum',
                                  'enum' => ['low', 'normal', 'high', 'urgent']],
                'dueDate'     => ['expr' => 't.dueDate',     'label' => 'Due',      'type' => 'date'],
                'completedAt' => ['expr' => 't.completedAt', 'label' => 'Completed', 'type' => 'datetime'],
                'createdAt'   => ['expr' => 't.createdAt',   'label' => 'Created',  'type' => 'datetime'],
            ],
        ],
    ];

    // =========================================================================
    // ⚙️ 3.3 — Operators / aggregations / transforms. Closed, keyed sets.
    // =========================================================================

    private const OPERATORS = [
        //  key         SQL template (values NEVER interpolated — '?' only)   arity
        'eq'      => ['sql' => '%s = ?',             'arity' => 1],
        'neq'     => ['sql' => '%s <> ?',            'arity' => 1],
        'lt'      => ['sql' => '%s < ?',             'arity' => 1],
        'lte'     => ['sql' => '%s <= ?',            'arity' => 1],
        'gt'      => ['sql' => '%s > ?',             'arity' => 1],
        'gte'     => ['sql' => '%s >= ?',            'arity' => 1],
        'like'    => ['sql' => '%s LIKE ?',          'arity' => 1, 'contains' => true],
        'notlike' => ['sql' => '%s NOT LIKE ?',      'arity' => 1, 'contains' => true],
        'between' => ['sql' => '%s BETWEEN ? AND ?', 'arity' => 2],
        'in'      => ['sql' => '%s IN (%%s)',        'arity' => 'n', 'max' => 20],
        'isnull'  => ['sql' => '%s IS NULL',         'arity' => 0],
        'notnull' => ['sql' => '%s IS NOT NULL',     'arity' => 0],
    ];

    /** Which operator KEYS are legal for each registry column TYPE. */
    private const TYPE_OPERATORS = [
        'string'   => ['eq', 'neq', 'like', 'notlike', 'in', 'isnull', 'notnull'],
        'int'      => ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'between', 'in', 'isnull', 'notnull'],
        'decimal'  => ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'between', 'isnull', 'notnull'],
        'date'     => ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'between', 'isnull', 'notnull'],
        'datetime' => ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'between', 'isnull', 'notnull'],
        'enum'     => ['eq', 'neq', 'in'],
        'bool'     => ['eq'],
    ];

    /** Aggregation SQL templates — sprintf with ONE %s = registry expr
     *  (or the literal '*' for the COUNT(*) special case). */
    private const AGGREGATIONS = [
        'count'         => 'COUNT(%s)',
        'countDistinct' => 'COUNT(DISTINCT %s)',
        'sum'           => 'SUM(%s)',
        'avg'           => 'AVG(%s)',
        'min'           => 'MIN(%s)',
        'max'           => 'MAX(%s)',
    ];

    /** Default aggregation whitelist by column TYPE, used when a column
     *  entry doesn't declare its own 'aggs'. */
    private const DEFAULT_AGGS_BY_TYPE = [
        'int'      => ['count', 'countDistinct', 'sum', 'avg', 'min', 'max'],
        'decimal'  => ['count', 'countDistinct', 'sum', 'avg', 'min', 'max'],
        'date'     => ['count', 'min', 'max'],
        'datetime' => ['count', 'min', 'max'],
        'string'   => ['count', 'countDistinct'],
        'enum'     => ['count', 'countDistinct'],
        'bool'     => ['count', 'countDistinct'],
    ];

    /** Group-by date/datetime bucketing templates — sprintf with ONE %s. */
    private const TRANSFORMS = [
        'day'   => 'DATE(%s)',
        'month' => "DATE_FORMAT(%s, '%%Y-%%m')",
        'year'  => 'YEAR(%s)',
    ];

    // =========================================================================
    // 🔍 Public lookups — strict key equality only
    // =========================================================================

    /**
     * All sources the CALLER may use right now: app-enabled sources only,
     * with EVERY column annotated 'locked' (true when the caller currently
     * fails that column's gates). Columns are never removed from the list —
     * a locked-but-visible entry lets the builder UI show a disabled
     * checkbox + lock icon + required-role tooltip (§6.2) rather than
     * silently vanishing a column an admin might reasonably expect to see
     * (and the server independently re-checks every gate at compile time
     * regardless of what the UI renders).
     *
     * @return array<string, array{label:string, columns:array<string, array{label:string, type:string, locked:bool}>}>
     */
    public static function availableSources(): array
    {
        $out = [];
        foreach (self::SOURCES as $key => $src) {
            if (AppRegistry::isEnabled((string) $src['appSlug']) === false) {
                continue;
            }
            $cols = [];
            foreach ((array) $src['columns'] as $colKey => $col) {
                $cols[$colKey] = [
                    'label'  => (string) $col['label'],
                    'type'   => (string) $col['type'],
                    'locked' => self::callerPassesGates((array) ($col['gates'] ?? [])) === false,
                ];
            }
            $out[$key] = [
                'label'   => (string) $src['label'],
                'columns' => $cols,
            ];
        }
        return $out;
    }

    /**
     * One source entry or null. Does NOT apply role filtering — the
     * compiler applies gates per column, per use (SELECT/WHERE/GROUP/ORDER).
     *
     * @return array<string, mixed>|null
     */
    public static function source(string $sourceKey): ?array
    {
        return self::SOURCES[$sourceKey] ?? null;
    }

    /**
     * Column entry or null — strict key lookup, never pattern matching.
     *
     * @return array<string, mixed>|null
     */
    public static function column(string $sourceKey, string $columnKey): ?array
    {
        return self::SOURCES[$sourceKey]['columns'][$columnKey] ?? null;
    }

    /**
     * Operator entry or null.
     *
     * @return array{sql:string, arity:int|string, max?:int, contains?:bool}|null
     */
    public static function operator(string $operatorKey): ?array
    {
        return self::OPERATORS[$operatorKey] ?? null;
    }

    /**
     * True when $operatorKey is legal for registry column $type.
     */
    public static function operatorAllowedForType(string $operatorKey, string $type): bool
    {
        $allowed = self::TYPE_OPERATORS[$type] ?? null;
        if ($allowed === null) {
            return false;
        }
        return in_array($operatorKey, $allowed, true);
    }

    /**
     * Aggregation SQL template (sprintf with ONE %s) or null.
     */
    public static function aggregation(string $aggKey): ?string
    {
        return self::AGGREGATIONS[$aggKey] ?? null;
    }

    /**
     * Group-by transform template or null. Callers must independently
     * confirm the target column's type is date/datetime before applying —
     * this lookup alone does not encode that rule.
     */
    public static function transform(string $transformKey): ?string
    {
        return self::TRANSFORMS[$transformKey] ?? null;
    }

    /**
     * The effective, closed list of aggregation keys allowed for one
     * column — its own 'aggs' override if declared, else the type default.
     *
     * @return string[]
     */
    public static function columnAggregations(string $sourceKey, string $columnKey): array
    {
        $col = self::column($sourceKey, $columnKey);
        if ($col === null) {
            return [];
        }
        if (isset($col['aggs']) === true && is_array($col['aggs']) === true) {
            return $col['aggs'];
        }
        return self::DEFAULT_AGGS_BY_TYPE[(string) $col['type']] ?? [];
    }

    /**
     * True when the CALLER (App::user()) passes AT LEAST ONE of the given
     * gates. An empty gate list always passes (baseline admin access —
     * App::isAdmin() — is the caller's responsibility, checked once per
     * request by every builder handler BEFORE any registry lookup).
     *
     * Gate vocabulary (closed):
     *   - a plain string  => tblRoles.roleKey, checked via App::hasRole()
     *                        (root admin passes every role implicitly).
     *   - '@siteAdmin'    => App::isSiteAdmin()
     *   - '@rootAdmin'    => App::isRootAdmin()
     *
     * @param string[] $gates
     */
    public static function callerPassesGates(array $gates): bool
    {
        if (count($gates) === 0) {
            return true;
        }
        foreach ($gates as $gate) {
            $gate = (string) $gate;
            if ($gate === '@siteAdmin') {
                if (App::isSiteAdmin() === true) {
                    return true;
                }
                continue;
            }
            if ($gate === '@rootAdmin') {
                if (App::isRootAdmin() === true) {
                    return true;
                }
                continue;
            }
            if (App::hasRole($gate) === true) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // 🧪 Self-consistency assertions
    // =========================================================================

    /**
     * Registry self-consistency assertions — belt-and-braces so a future
     * hand-edit to SOURCES can't silently degrade the security model.
     * Pure structural checks over the constants above; no DB access.
     *
     * @throws \RuntimeException on any violation
     */
    public static function assertSelfConsistent(): void
    {
        $keyRe  = '/^[a-z][a-zA-Z0-9_]{0,49}$/';
        $exprRe = '/^[a-z][a-z0-9]{0,7}\.[A-Za-z0-9_]{1,64}$/';
        $validTypes = ['string', 'int', 'decimal', 'date', 'datetime', 'enum', 'bool'];

        foreach (self::SOURCES as $sourceKey => $src) {
            if (preg_match($keyRe, $sourceKey) !== 1) {
                throw new \RuntimeException("ReportRegistry: invalid source key '{$sourceKey}'");
            }
            foreach (['table', 'alias', 'siteExpr', 'columns'] as $required) {
                if (array_key_exists($required, $src) === false) {
                    throw new \RuntimeException("ReportRegistry: source '{$sourceKey}' missing '{$required}'");
                }
            }
            $table = (string) $src['table'];
            if (str_starts_with($table, self::TABLE_PREFIX) === false) {
                throw new \RuntimeException("ReportRegistry: source '{$sourceKey}' table '{$table}' does not start with 'tbl'");
            }

            $joins    = (array) ($src['joins'] ?? []);
            $joinKeys = array_keys($joins);
            $blob     = $table . ' ' . (string) $src['siteExpr'];

            foreach ($joins as $joinKey => $joinSql) {
                if (preg_match($keyRe, (string) $joinKey) !== 1) {
                    throw new \RuntimeException("ReportRegistry: source '{$sourceKey}' has an invalid join key '{$joinKey}'");
                }
                // Every table named in a JOIN fragment must start 'tbl'
                // (check_php_table_refs.py then independently proves it's
                // a REAL schema table).
                if (preg_match_all('/\bJOIN\s+`?(\w+)`?/i', (string) $joinSql, $m) > 0) {
                    foreach ($m[1] as $joinedTable) {
                        if (str_starts_with($joinedTable, self::TABLE_PREFIX) === false) {
                            throw new \RuntimeException("ReportRegistry: source '{$sourceKey}' join '{$joinKey}' references non-tbl table '{$joinedTable}'");
                        }
                    }
                }
                $blob .= ' ' . (string) $joinSql;
            }

            foreach ((array) ($src['fixedWhere'] ?? []) as $fw) {
                $blob .= ' ' . (string) $fw;
            }

            foreach ((array) $src['columns'] as $colKey => $col) {
                if (preg_match($keyRe, (string) $colKey) !== 1) {
                    throw new \RuntimeException("ReportRegistry: source '{$sourceKey}' has an invalid column key '{$colKey}'");
                }
                if (array_key_exists('expr', $col) === false || preg_match($exprRe, (string) $col['expr']) !== 1) {
                    throw new \RuntimeException("ReportRegistry: source '{$sourceKey}' column '{$colKey}' has an invalid expr");
                }
                if (array_key_exists('label', $col) === false || (string) $col['label'] === '') {
                    throw new \RuntimeException("ReportRegistry: source '{$sourceKey}' column '{$colKey}' missing label");
                }
                $type = (string) ($col['type'] ?? '');
                if (in_array($type, $validTypes, true) === false) {
                    throw new \RuntimeException("ReportRegistry: source '{$sourceKey}' column '{$colKey}' has invalid type '{$type}'");
                }
                if ($type === 'enum' && (is_array($col['enum'] ?? null) === false || count($col['enum']) === 0)) {
                    throw new \RuntimeException("ReportRegistry: source '{$sourceKey}' column '{$colKey}' type=enum requires a non-empty 'enum' list");
                }
                if (isset($col['join']) === true && in_array($col['join'], $joinKeys, true) === false) {
                    throw new \RuntimeException("ReportRegistry: source '{$sourceKey}' column '{$colKey}' references undeclared join '{$col['join']}'");
                }
                if (isset($col['aggs']) === true) {
                    foreach ((array) $col['aggs'] as $aggKey) {
                        if (array_key_exists($aggKey, self::AGGREGATIONS) === false) {
                            throw new \RuntimeException("ReportRegistry: source '{$sourceKey}' column '{$colKey}' declares unknown aggregation '{$aggKey}'");
                        }
                    }
                }
                $blob .= ' ' . (string) $col['expr'];
            }

            if (self::containsForbiddenTable($blob) === true) {
                throw new \RuntimeException("ReportRegistry: source '{$sourceKey}' references a forbidden confidential-domain table");
            }
        }

        // Operator/aggregation cross-reference sanity.
        foreach (self::TYPE_OPERATORS as $type => $ops) {
            foreach ($ops as $opKey) {
                if (array_key_exists($opKey, self::OPERATORS) === false) {
                    throw new \RuntimeException("ReportRegistry: TYPE_OPERATORS['{$type}'] references unknown operator '{$opKey}'");
                }
            }
        }
        foreach (self::DEFAULT_AGGS_BY_TYPE as $type => $aggs) {
            foreach ($aggs as $aggKey) {
                if (array_key_exists($aggKey, self::AGGREGATIONS) === false) {
                    throw new \RuntimeException("ReportRegistry: DEFAULT_AGGS_BY_TYPE['{$type}'] references unknown aggregation '{$aggKey}'");
                }
            }
        }
    }

    /**
     * Runtime-assembled forbidden-table substring check. Fragments are
     * reassembled from FORBIDDEN_TABLE_PREFIXES / FORBIDDEN_TABLE_EXACT +
     * TABLE_PREFIX at call time — see the class-level comment above those
     * constants for why they are declared as bare fragments rather than
     * literal 'tblXxx' strings.
     */
    private static function containsForbiddenTable(string $haystack): bool
    {
        foreach (self::FORBIDDEN_TABLE_PREFIXES as $fragment) {
            $needle = self::TABLE_PREFIX . $fragment;
            if (str_contains($haystack, $needle) === true) {
                return true;
            }
        }
        foreach (self::FORBIDDEN_TABLE_EXACT as $fragment) {
            $needle = self::TABLE_PREFIX . $fragment;
            if (str_contains($haystack, $needle) === true) {
                return true;
            }
        }
        return false;
    }
}
