<?php
// Path: _core/AssetRegister.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Register + Audit Choke-Point 📦🔐
 * -----------------------------------------------------------------------------
 * Foundation service class for the Asset Tracker app (slug `assets`, #393).
 * Two responsibilities in this pass:
 *
 *   1. AUDIT CHOKE-POINT (#395). Every Asset Tracker mutation — today and in
 *      every later sub-issue (save/delete/loans/maintenance/licences/labels/
 *      found-reports/…) — is meant to route through `self::audit()` so
 *      `tblAssetAudit` (immutable, no-FK, mirrors tblAuditTrail) and the
 *      existing platform logs (`Logger::activity()` always;
 *      `Logger::audit()` on create/update/delete) stay in lock-step. Sensitive
 *      fields (`licenseKey`, `publicToken`) are redacted before anything
 *      touches a log row.
 *
 *      `audit()` is deliberately PUBLIC, not private, despite this class
 *      being the only intended writer for now: the public lost-and-found
 *      page (`_apps/assets/tag.php`) is a legitimate external caller today
 *      — it records a `token`/`scan` event on every valid public view — and
 *      every later sub-issue's save/delete/loan-action/etc handlers will
 *      call it directly too, since their own AssetRegister methods don't
 *      exist yet in this foundation pass. The "choke point" property is
 *      about there being exactly ONE audit-writing code path, not about
 *      language-level visibility.
 *
 *   2. Minimal read helpers (`listForSite()`, `get()`) for the register
 *      index page, plus `generatePublicToken()` (used when an asset is
 *      created) and `validateIdentifier()` / `isResponsibleFor()`, which
 *      later sub-issues (identifiers-save, item.php's access gate) will
 *      lean on.
 *
 * All queries are MySQLi prepared statements via `App::db()` — never
 * string-interpolated user input (house rule, .claude/CLAUDE.md → Code Style).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/395
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class AssetRegister
{
    /**
     * Change-set field names that must NEVER appear in plaintext inside a
     * log row — even an admin-only one. `licenseKey` is libsodium
     * ciphertext (still not something to echo into logs wholesale) and
     * `publicToken` is the secret that gates the public lost-and-found
     * page, so leaking it via a log export would be equivalent to leaking
     * the page's access control.
     *
     * @var string[]
     */
    private const REDACTED_FIELDS = ['licenseKey', 'publicToken'];

    /** Marker written in place of a redacted field's old/new value. */
    private const REDACTED_MARKER = '••• redacted •••';

    /* ==========================================================================
     * 🔑 Public token
     * ======================================================================== */

    /**
     * Generate a fresh public token for an asset's lost-and-found page.
     * 32 lowercase-hex characters (128 bits of entropy) — matches the
     * `CHAR(32)` column and the `^[a-f0-9]{32}$` pattern
     * `Router::handleSpecialRoutes()` matches for the `/a/{token}` route.
     *
     * @return string 32-char lowercase-hex token
     */
    public static function generatePublicToken(): string
    {
        // 🎲 bin2hex(random_bytes(16)) — 16 random bytes → 32 hex chars.
        // See: https://www.php.net/manual/en/function.random-bytes.php
        return bin2hex(random_bytes(16));
    }

    /* ==========================================================================
     * 📜 Audit choke-point (#395)
     * ======================================================================== */

    /**
     * Record an Asset Tracker action to the audit trail. See the class
     * header comment for why this is public.
     *
     * @param string      $entityType One of tblAssetAudit.entityType's ENUM values
     * @param int         $entityID   PK of the affected child row (0 when N/A, e.g. a token scan)
     * @param int         $assetID    The owning asset — always required, even for child-entity actions
     * @param string      $action     Free-form verb: create/update/delete/scan/approve/decline/link/release/…
     * @param array|null  $old        Previous field values (null for create/scan/…)
     * @param array|null  $new        New field values (null for delete)
     * @param array       $meta       Free-form extra context stored in tblAssetAudit.meta (JSON)
     * @param string      $actorType  'user' (default) | 'system' | 'public'
     *
     * @return void
     */
    public static function audit(
        string $entityType,
        int $entityID,
        int $assetID,
        string $action,
        ?array $old = null,
        ?array $new = null,
        array $meta = [],
        string $actorType = 'user'
    ): void {
        $db = App::db();

        // 📋 1. Build the {field:{old,new}} change-set, skipping unchanged
        //    fields and redacting sensitive ones. Only meaningful when both
        //    sides are supplied (an update); create/delete/scan-style calls
        //    typically pass just one side (or neither).
        $changeSet = self::buildChangeSet($old, $new);

        // 🌐 2. Context — site, actor, IP.
        $siteId = Site::id();
        $actorUserID = null;
        if ($actorType === 'user') {
            // 🪞 Mirrors the house convention used across every controller
            //    (e.g. web/_apps/documents/categories.php) rather than
            //    Auth::user() — avoids an extra DB round-trip when we only
            //    need the id.
            $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
            $actorUserID = $sessionUserId > 0 ? $sessionUserId : null;
        }

        // 🔌 API-key attribution, same auto-resolve convention as
        //    Logger::audit() — ApiAuth doesn't exist yet for Asset Tracker
        //    endpoints (foundation pass ships no api/assets/* handlers),
        //    so this is a forward-compatible no-op today.
        $apiKeyId = null;
        if (class_exists('Portal\\Core\\ApiAuth') === true
            && method_exists('Portal\\Core\\ApiAuth', 'apiKeyId') === true
        ) {
            $apiKeyId = \Portal\Core\ApiAuth::apiKeyId();
        }

        $ipHash = self::ipHash();
        $changeSetJson = $changeSet !== null ? json_encode($changeSet, JSON_UNESCAPED_UNICODE) : null;
        $metaJson = count($meta) > 0 ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

        // 💾 3. Write tblAssetAudit. No FK constraints on this table by
        //    design (see migration 159 header) — the INSERT can never fail
        //    on a dangling reference, which matters because this method
        //    must be safe to call even mid-delete (e.g. auditing the
        //    delete of the very asset the row is about).
        $stmt = $db->prepare(
            'INSERT INTO tblAssetAudit '
            . '(siteID, assetID, entityType, entityID, action, changeSet, meta, actorType, actorUserID, apiKeyID, ipHash) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            error_log('AssetRegister::audit() prepare failed: ' . $db->error);
        } else {
            $stmt->bind_param(
                'iisissssiis',
                $siteId,
                $assetID,
                $entityType,
                $entityID,
                $action,
                $changeSetJson,
                $metaJson,
                $actorType,
                $actorUserID,
                $apiKeyId,
                $ipHash
            );
            $stmt->execute();
            $stmt->close();
        }

        // 📓 4. ALWAYS mirror into the platform activity log — every asset
        //    action, not just create/update/delete, shows up in the shared
        //    admin activity log alongside every other app's events.
        $studlyEntity = self::studly($entityType);
        $studlyAction = self::studly($action);
        $summary = sprintf(
            'Asset #%d — %s %s (entity #%d)%s',
            $assetID,
            $studlyEntity,
            strtolower($studlyAction),
            $entityID,
            $actorType !== 'user' ? ' [' . $actorType . ']' : ''
        );
        Logger::activity('Asset' . $studlyEntity . $studlyAction, $summary, $actorUserID);

        // 📋 5. On create/update/delete, ALSO write the platform's generic
        //    before/after audit trail (tblAuditTrail via Logger::audit())
        //    so admin's existing "Audit Trail" screen shows Asset Tracker
        //    changes too, not just tblAssetAudit's app-specific view.
        if (in_array($action, ['create', 'update', 'delete'], true) === true) {
            $table = self::TABLE_FOR_ENTITY[$entityType] ?? null;
            if ($table !== null) {
                Logger::audit($table, $entityID > 0 ? $entityID : $assetID, $action, $old, $new, $actorUserID, $apiKeyId);
            }
        }
    }

    /**
     * Map tblAssetAudit.entityType → the real table Logger::audit() should
     * attribute create/update/delete rows to. Entities with no table of
     * their own yet (label generation, event-link, stocktake, kiosk — all
     * later sub-issues) are omitted on purpose; audit() simply skips the
     * Logger::audit() call for those (the tblAssetAudit row above still
     * captures the action either way).
     *
     * @var array<string, string>
     */
    private const TABLE_FOR_ENTITY = [
        'asset'        => 'tblAssets',
        'owner'        => 'tblAssetOwners',
        'loan'         => 'tblAssetLoans',
        'maintenance'  => 'tblAssetMaintenance',
        'resource'     => 'tblAssetResources',
        'identifier'   => 'tblAssetIdentifiers',
        'license'      => 'tblAssetLicenseAssignments',
        'found-report' => 'tblAssetFoundReports',
    ];

    /**
     * Diff $old vs $new into `{field: {old, new}}`, skipping unchanged
     * fields and redacting REDACTED_FIELDS. Returns null when there's
     * nothing meaningful to diff (both sides null/empty, or a pure
     * create/delete/scan call that only ever supplies one side — those
     * are still fully captured by $old/$new individually if a caller
     * wants that; the change-set is specifically the update-diff view).
     *
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     *
     * @return array<string, array{old: mixed, new: mixed}>|null
     */
    private static function buildChangeSet(?array $old, ?array $new): ?array
    {
        if ($old === null && $new === null) {
            return null;
        }
        $old ??= [];
        $new ??= [];
        $fields = array_unique(array_merge(array_keys($old), array_keys($new)));
        $diff = [];
        foreach ($fields as $field) {
            $oldVal = $old[$field] ?? null;
            $newVal = $new[$field] ?? null;
            // 🪞 String-compare, same convention as Logger::audit() — avoids
            //    false positives from type juggling (e.g. int 1 vs string '1').
            if ((string) ($oldVal ?? '') === (string) ($newVal ?? '')) {
                continue; // unchanged — skip
            }
            if (in_array($field, self::REDACTED_FIELDS, true) === true) {
                $diff[$field] = ['old' => self::REDACTED_MARKER, 'new' => self::REDACTED_MARKER];
                continue;
            }
            $diff[$field] = ['old' => $oldVal, 'new' => $newVal];
        }
        return count($diff) > 0 ? $diff : null;
    }

    /**
     * Salted SHA-256 of the client IP. Salted with the same per-install key
     * file `encrypt_setting()`/`decrypt_setting()` use (bootstrap.php,
     * `_auth_keys/enc.key`) so the hash is stable for THIS install (lets an
     * admin correlate repeat scans/reports from the same visitor) but not
     * reversible or comparable across installs — no raw IP is ever stored.
     *
     * @return string 64-char hex SHA-256 digest
     */
    private static function ipHash(): string
    {
        $ip = self::clientIp();
        $keyPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'enc.key';
        // 🛟 Fall back to the portal version string when the key file isn't
        //    readable (e.g. very early in the installer flow) — still a
        //    per-codebase-version salt rather than an unsalted hash, and
        //    this path should never be hit in a fully-installed portal.
        $salt = is_readable($keyPath) === true
            ? (string) file_get_contents($keyPath)
            : (defined('PORTAL_VERSION') ? (string) PORTAL_VERSION : 'webms-intra');
        return hash('sha256', $salt . '|' . $ip);
    }

    /**
     * Client IP resolution — mirrors Logger::clientIp() (private on that
     * class, so re-implemented here rather than reached into). Honours
     * Cloudflare / standard proxy headers.
     */
    private static function clientIp(): string
    {
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) === true) {
            return (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) === true) {
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /**
     * 'found-report' → 'FoundReport', 'update' → 'Update'. Used to build
     * both the Logger::activity() type string and the human summary.
     */
    private static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    /* ==========================================================================
     * 🔢 Identifier validation (non-blocking)
     * ======================================================================== */

    /**
     * Validate a GS1/barcode/RFID identifier value against its scheme's
     * known format/check-digit rules. NEVER blocks a save — the caller
     * decides whether to surface `warnings` and let the user proceed
     * anyway (e.g. hand-keyed labels with an OCR typo are still worth
     * recording).
     *
     * @return array{valid: bool, warnings: string[]}
     */
    public static function validateIdentifier(string $typeCode, string $value): array
    {
        $warnings = [];
        $value = trim($value);

        if ($value === '') {
            return ['valid' => false, 'warnings' => ['Value is empty.']];
        }

        $db = App::db();
        $stmt = $db->prepare(
            'SELECT formatRegex, checkDigitScheme, label FROM tblAssetIdentifierTypes '
            . 'WHERE typeCode = ? AND isActive = 1 LIMIT 1'
        );
        if ($stmt === false) {
            return ['valid' => true, 'warnings' => ['Could not look up identifier type — validation skipped.']];
        }
        $stmt->bind_param('s', $typeCode);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null || $row === false) {
            // 🪞 Soft reference (see class + migration header) — an unknown
            //    typeCode is a WARNING, never a hard failure.
            return ['valid' => true, 'warnings' => ['Unknown identifier type "' . $typeCode . '" — no format validation performed.']];
        }

        $valid = true;

        // 📐 Format regex, when the type defines one.
        $formatRegex = (string) ($row['formatRegex'] ?? '');
        if ($formatRegex !== '' && @preg_match($formatRegex, $value) !== 1) {
            $valid = false;
            $warnings[] = 'Value does not match the expected format for ' . (string) $row['label'] . '.';
        }

        // 🔢 Check-digit scheme.
        $scheme = (string) ($row['checkDigitScheme'] ?? 'none');
        if ($scheme === 'gs1-mod10') {
            $result = self::gs1Mod10Check($value);
            if ($result === false) {
                $valid = false;
                $warnings[] = 'GS1 mod-10 check digit does not match — double-check the value.';
            } elseif ($result === null) {
                $warnings[] = 'Value is not purely numeric — GS1 mod-10 check digit could not be verified.';
            }
        } elseif ($scheme === 'gmn-mod1021') {
            // 🚧 Stub — see gmnMod1021Check() doc comment.
            $warnings[] = 'GMN check-character validation is not implemented yet — value accepted without verification.';
        }

        return ['valid' => $valid, 'warnings' => $warnings];
    }

    /**
     * GS1 "mod 10" check-digit algorithm — alternating weight 3/1 counted
     * from the RIGHTMOST digit of the value (the check digit itself is the
     * final digit and is excluded from the weighting pass). Used by GTIN,
     * GLN, SSCC, GSRN, GSIN, GDTI, GCN, GRAI, and the retail-barcode family
     * (EAN/UPC/ITF-14), which all share this scheme.
     *
     * @see https://www.gs1.org/services/how-calculate-check-digit-manually
     *
     * @return bool|null true = matches, false = mismatch, null = value
     *                    wasn't purely numeric so the digit couldn't be
     *                    computed at all
     */
    private static function gs1Mod10Check(string $value): ?bool
    {
        if (preg_match('/^\d{2,}$/', $value) !== 1) {
            return null;
        }
        $digits = str_split($value);
        $checkDigit = (int) array_pop($digits); // last digit = the check digit itself
        if (count($digits) === 0) {
            return null;
        }
        $sum = 0;
        $weight = 3; // rightmost of the REMAINING digits is weighted 3
        for ($i = count($digits) - 1; $i >= 0; $i--) {
            $sum += ((int) $digits[$i]) * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }
        $calculated = (10 - ($sum % 10)) % 10;
        return $calculated === $checkDigit;
    }

    /* ==========================================================================
     * 👥 Responsibility check
     * ======================================================================== */

    /**
     * Is the given user (default: current session user) an owner-party for
     * this asset — directly, or via a department/group that IS an
     * owner-party? Modelled on `Auth::isEventTeamMember()`'s shape
     * (short-circuit direct match, then widen through membership tables).
     * Does NOT implicitly grant admins — callers combine this with
     * `App::isAdmin()` themselves (see `_apps/assets/tag.php`), mirroring
     * how `Auth::isCoordinatorOf()` keeps its own admin bypass separate
     * from the membership checks it composes.
     */
    public static function isResponsibleFor(int $assetId, ?int $userId = null): bool
    {
        if ($assetId <= 0) {
            return false;
        }
        if ($userId === null) {
            if (Auth::check() === false) {
                return false;
            }
            $userId = (int) ($_SESSION['user_id'] ?? 0);
        }
        if ($userId <= 0) {
            return false;
        }

        $db = App::db();

        // 👤 Direct ownership row.
        $stmt = $db->prepare(
            'SELECT 1 FROM tblAssetOwners '
            . 'WHERE assetID = ? AND partyType = "user" AND userID = ? LIMIT 1'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $assetId, $userId);
            $stmt->execute();
            $hit = (bool) $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($hit === true) {
                return true;
            }
        }

        // 🏢 Department ownership — the asset is owned by a dept this user
        //    belongs to (tblUserDepts).
        $stmt = $db->prepare(
            'SELECT 1 FROM tblAssetOwners o '
            . 'JOIN tblUserDepts ud ON ud.deptID = o.deptID '
            . 'WHERE o.assetID = ? AND o.partyType = "dept" AND ud.userID = ? LIMIT 1'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $assetId, $userId);
            $stmt->execute();
            $hit = (bool) $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($hit === true) {
                return true;
            }
        }

        // 👥 Group ownership — the asset is owned by a group this user
        //    belongs to (tblUserGroups).
        $stmt = $db->prepare(
            'SELECT 1 FROM tblAssetOwners o '
            . 'JOIN tblUserGroups ug ON ug.groupID = o.groupID '
            . 'WHERE o.assetID = ? AND o.partyType = "group" AND ug.userID = ? LIMIT 1'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $assetId, $userId);
            $stmt->execute();
            $hit = (bool) $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($hit === true) {
                return true;
            }
        }

        return false;
    }

    /* ==========================================================================
     * 📖 Minimal read helpers (register index page)
     * ======================================================================== */

    /**
     * List non-deleted assets for a site, newest-updated first. Supports a
     * small set of optional filters — enough for the foundation index page;
     * later sub-issues can extend this without changing the signature.
     *
     * Recognised $filters keys (all optional): 'status', 'categoryID',
     * 'assetKind', 'search' (matches name/serialNumber/assetTagCode).
     *
     * @param array{status?: string, categoryID?: int, assetKind?: string, search?: string} $filters
     * @param bool $includeConfidential Whether confidential assets (isConfidential = 1)
     *             are included in the results. Defaults to false — pass true only for
     *             callers who have already verified the viewer is an admin/asset_manager
     *             (#395 access gate; see _apps/assets/index.php's $canManage check).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listForSite(int $siteId, array $filters = [], bool $includeConfidential = false): array
    {
        $db = App::db();

        $where  = ['a.siteID = ?', 'a.isDeleted = 0'];
        $types  = 'i';
        $params = [$siteId];

        if ($includeConfidential === false) {
            // 🔒 Literal condition — no bound parameter needed since there's
            //    nothing user-supplied here, just a constant gate.
            $where[] = 'a.isConfidential = 0';
        }

        if (isset($filters['status']) === true && $filters['status'] !== '') {
            $where[]  = 'a.status = ?';
            $types   .= 's';
            $params[] = (string) $filters['status'];
        }
        if (isset($filters['categoryID']) === true && (int) $filters['categoryID'] > 0) {
            $where[]  = 'a.categoryID = ?';
            $types   .= 'i';
            $params[] = (int) $filters['categoryID'];
        }
        if (isset($filters['assetKind']) === true && $filters['assetKind'] !== '') {
            $where[]  = 'a.assetKind = ?';
            $types   .= 's';
            $params[] = (string) $filters['assetKind'];
        }
        if (isset($filters['search']) === true && trim((string) $filters['search']) !== '') {
            $where[]  = '(a.name LIKE ? OR a.serialNumber LIKE ? OR a.assetTagCode LIKE ?)';
            $like     = '%' . trim((string) $filters['search']) . '%';
            $types   .= 'sss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = 'SELECT a.assetID, a.assetKind, a.name, a.description, a.categoryID, a.locationID, '
             . '       a.manufacturer, a.model, a.serialNumber, a.assetTagCode, a.conditionState, '
             . '       a.status, a.isConfidential, a.publicToken, a.publicPageEnabled, a.updatedAt, '
             . '       c.categoryName, l.locationName '
             . 'FROM tblAssets a '
             . 'LEFT JOIN tblAssetCategories c ON c.categoryID = a.categoryID '
             . 'LEFT JOIN tblAssetLocations l ON l.locationID = a.locationID '
             . 'WHERE ' . implode(' AND ', $where) . ' '
             . 'ORDER BY a.updatedAt DESC, a.name ASC';

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            error_log('AssetRegister::listForSite() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Fetch a single non-deleted asset by id, or null if it doesn't exist
     * (or is soft-deleted).
     *
     * @return array<string, mixed>|null
     */
    public static function get(int $assetId): ?array
    {
        if ($assetId <= 0) {
            return null;
        }
        $db = App::db();
        $stmt = $db->prepare('SELECT * FROM tblAssets WHERE assetID = ? AND isDeleted = 0 LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== false && $row !== null ? $row : null;
    }
}
