<?php
// Path: _core/AssetRegister.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Register + Audit Choke-Point 📦🔐
 * -----------------------------------------------------------------------------
 * Service class for the Asset Tracker app (slug `assets`, #393). Two
 * responsibilities:
 *
 *   1. AUDIT CHOKE-POINT (#395). Every Asset Tracker mutation — today and in
 *      every later sub-issue (loans/maintenance/licences/labels/
 *      found-reports/…) — routes through `self::audit()` so
 *      `tblAssetAudit` (immutable, no-FK, mirrors tblAuditTrail) and the
 *      existing platform logs (`Logger::activity()` always;
 *      `Logger::audit()` on create/update/delete) stay in lock-step. Sensitive
 *      fields (`licenseKey`, `publicToken`) are redacted before anything
 *      touches a log row — see createAsset()/updateAsset()'s inline comments
 *      for the one subtlety this pass discovered: that redaction is airtight
 *      for THIS class's own `tblAssetAudit` write, but the mirrored
 *      `Logger::audit()` call serialises whatever raw `$old`/`$new` arrays
 *      it's handed with no redaction pass of its own — so every caller in
 *      this file that touches `licenseKey` passes a marker string, never the
 *      plaintext or ciphertext, into `self::audit()`'s change-set arguments.
 *
 *      `audit()` is deliberately PUBLIC, not private: the public lost-and-
 *      found page (`_apps/assets/tag.php`) is a legitimate external caller
 *      — it records a `token`/`scan` event on every valid public view — and
 *      every mutating controller in `_apps/assets/` calls it (indirectly, via
 *      this class's own methods) too. The "choke point" property is about
 *      there being exactly ONE audit-writing code path, not about
 *      language-level visibility.
 *
 *   2. Register CRUD + reference data + resources (#394, this pass).
 *      `createAsset()` / `updateAsset()` / `softDeleteAsset()` are the ONLY
 *      supported way to mutate `tblAssets` — callers (`_apps/assets/save.php`
 *      / `delete.php`) are responsible for validating/coercing raw input
 *      first; these methods trust their caller's types completely. Category
 *      and location reference data (`listCategories()`/`saveCategory()`/
 *      `toggleCategoryActive()` and the location equivalents) log via a
 *      plain `Logger::activity()` call rather than `self::audit()` — that
 *      choke-point is asset-scoped (every row requires a real assetID) and
 *      categories/locations are site-wide, not owned by any one asset.
 *      Resource attachments (`listResources()`/`addResource()`/
 *      `deleteResource()`) DO route through `self::audit()` (entityType
 *      `'resource'`) since every resource belongs to exactly one asset.
 *      Plus `listForSite()`/`get()` (read helpers — `get()` is site-scoped
 *      via `Site::id()`), `generatePublicToken()`, `validateIdentifier()` /
 *      `isResponsibleFor()` (leaned on by later sub-issues + this one's
 *      confidential-asset access gates), and `decryptLicenseKey()` (the
 *      manager-only reveal on `_apps/assets/item.php`).
 *
 * All queries are MySQLi prepared statements via `App::db()` — never
 * string-interpolated user input (house rule, .claude/CLAUDE.md → Code Style).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/394
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
     * 📋 Allow-lists (#394) — the single source of truth for every ENUM
     * column tblAssets/tblAssetResources define. Shared between save.php's
     * validation and edit.php's dropdown rendering so the two can never
     * silently drift apart.
     * ======================================================================== */

    /** @var string[] tblAssets.assetKind */
    public const ASSET_KINDS = ['physical', 'digital'];

    /** @var string[] tblAssets.conditionState / tblAssetLoans.conditionOut|conditionIn */
    public const CONDITION_STATES = ['new', 'excellent', 'good', 'fair', 'poor', 'broken'];

    /** @var string[] tblAssets.status */
    public const ASSET_STATUSES = [
        'in-service', 'in-repair', 'on-loan', 'borrowed', 'in-storage',
        'retired', 'disposed', 'lost', 'stolen',
    ];

    /** @var string[] tblAssets.depreciationMethod */
    public const DEPRECIATION_METHODS = ['none', 'straight-line', 'reducing-balance'];

    /** @var string[] tblAssetResources.resourceType */
    public const RESOURCE_TYPES = [
        'manual', 'guide', 'video', 'photo', 'receipt',
        'ownership-agreement', 'insurance', 'legal', 'other',
    ];

    /**
     * Resource types that may EVER be shown on the public /a/{token}
     * lost-and-found page (isPublic=1). Ownership agreements, insurance,
     * legal docs and receipts are NEVER public — they are the confidential
     * "ownership & legal" vault and must never leak to an anonymous scanner,
     * regardless of what an isPublic checkbox/tampered POST says.
     *
     * @var string[]
     */
    public const PUBLIC_ELIGIBLE_RESOURCE_TYPES = ['manual', 'guide', 'photo'];

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
                // 🔒 Redact sensitive fields BEFORE handing the raw before/
                //    after rows to Logger::audit() — unlike buildChangeSet()
                //    (which redacts tblAssetAudit above), Logger::audit()
                //    serialises tblAuditTrail's oldValue/newValue with no
                //    redaction of its own. Centralising it here makes the
                //    choke-point safe even if a caller forgets to pre-mask.
                Logger::audit(
                    $table,
                    $entityID > 0 ? $entityID : $assetID,
                    $action,
                    self::redactForLog($old),
                    self::redactForLog($new),
                    $actorUserID,
                    $apiKeyId
                );
            }
        }
    }

    /**
     * Return a copy of a raw before/after row with REDACTED_FIELDS values
     * replaced by the redaction marker, so secrets (licenseKey ciphertext,
     * publicToken) never reach the platform audit trail. Null passes
     * through unchanged.
     *
     * @param array<string, mixed>|null $row
     *
     * @return array<string, mixed>|null
     */
    private static function redactForLog(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach (self::REDACTED_FIELDS as $field) {
            if (array_key_exists($field, $row) === true) {
                $row[$field] = self::REDACTED_MARKER;
            }
        }
        return $row;
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
     * 🔒 Site-scoped via `Site::id()` (#394 hardening) — every other
     * AssetRegister read/write is scoped to the active site (listForSite(),
     * softDeleteAsset(), updateAsset(), …); this one originally wasn't,
     * which would have let a valid assetID from ANOTHER site's register be
     * read cross-tenant by any caller that didn't separately re-check
     * `siteID` itself. Every current caller (`_apps/assets/item.php`,
     * `edit.php`, `save.php` via updateAsset(), `resource-save.php`,
     * `resource-download.php`) now also gets this for free.
     *
     * @return array<string, mixed>|null
     */
    public static function get(int $assetId): ?array
    {
        if ($assetId <= 0) {
            return null;
        }
        $db = App::db();
        $siteId = Site::id();
        $stmt = $db->prepare('SELECT * FROM tblAssets WHERE assetID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $assetId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== false && $row !== null ? $row : null;
    }

    /* ==========================================================================
     * 📦 Asset CRUD (#394)
     * ======================================================================== */

    /**
     * Build a table-driven {column => [value, mysqliBindType]} map into its
     * three parallel pieces (columns / placeholders / types / params) for an
     * INSERT or UPDATE SET clause. Centralising this avoids the classic bug
     * of a hand-counted bind_param() type string silently drifting out of
     * sync with the column list as fields get added/reordered — every
     * mutating method below builds its column set through this helper
     * instead of writing the type string by hand.
     *
     * @param array<string, array{0: mixed, 1: string}> $fields
     *
     * @return array{columns: string[], types: string, params: mixed[]}
     */
    private static function splitFields(array $fields): array
    {
        return [
            'columns' => array_keys($fields),
            'types'   => implode('', array_column($fields, 1)),
            'params'  => array_column($fields, 0),
        ];
    }

    /**
     * Create a new asset (physical or digital). Generates the public
     * lost-and-found token, encrypts `licenseKey` when supplied (digital
     * assets — libsodium via `encrypt_setting()`, see bootstrap.php), and
     * records a `create` row via `self::audit()`.
     *
     * $data keys (all optional except `name`; anything omitted falls back
     * to the column's schema default) — see migration 159_asset_tracker.sql
     * for the authoritative column list:
     *   assetKind, name, description, categoryID, locationID, manufacturer,
     *   model, serialNumber, assetTagCode, features, conditionState, status,
     *   purchaseDate, purchaseStore, purchaseCostPence, currency,
     *   warrantyExpiry, warrantyDetails, licenseKey (PLAINTEXT — this method
     *   encrypts it), licenseSeats, renewalDate, accessUrl,
     *   depreciationMethod, usefulLifeMonths, salvageValuePence,
     *   isConfidential, publicPageEnabled, parentAssetID.
     *
     * Caller contract: every field must already be validated/coerced to its
     * correct PHP type (int|string|null, ENUM values checked against this
     * class's allow-list constants) — see `_apps/assets/save.php`, which is
     * the one intended caller. This method does NOT re-validate ENUM/FK
     * values; it only handles persistence, token generation, encryption,
     * and audit logging.
     *
     * @param array<string, mixed> $data
     *
     * @return int New assetID, or 0 on failure
     */
    public static function createAsset(array $data, int $actorUserId): int
    {
        $db     = App::db();
        $siteId = Site::id();

        // 🔐 licenseKey — encrypt when supplied, else leave the column NULL.
        // The column NEVER holds plaintext (schema comment + class header).
        $rawLicenseKey       = trim((string) ($data['licenseKey'] ?? ''));
        $licenseKeyProvided  = $rawLicenseKey !== '';
        $licenseKeyCipher    = $licenseKeyProvided === true ? encrypt_setting($rawLicenseKey) : null;

        $publicToken = self::generatePublicToken();

        $fields = [
            'siteID'             => [$siteId, 'i'],
            'assetKind'          => [(string) ($data['assetKind'] ?? 'physical'), 's'],
            'name'               => [(string) ($data['name'] ?? ''), 's'],
            'description'        => [$data['description'] ?? null, 's'],
            'categoryID'         => [$data['categoryID'] ?? null, 'i'],
            'locationID'         => [$data['locationID'] ?? null, 'i'],
            'manufacturer'       => [$data['manufacturer'] ?? null, 's'],
            'model'              => [$data['model'] ?? null, 's'],
            'serialNumber'       => [$data['serialNumber'] ?? null, 's'],
            'assetTagCode'       => [$data['assetTagCode'] ?? null, 's'],
            'features'           => [$data['features'] ?? null, 's'],
            'conditionState'     => [(string) ($data['conditionState'] ?? 'good'), 's'],
            'status'             => [(string) ($data['status'] ?? 'in-service'), 's'],
            'purchaseDate'       => [$data['purchaseDate'] ?? null, 's'],
            'purchaseStore'      => [$data['purchaseStore'] ?? null, 's'],
            'purchaseCostPence'  => [$data['purchaseCostPence'] ?? null, 'i'],
            'currency'           => [(string) ($data['currency'] ?? 'GBP'), 's'],
            'warrantyExpiry'     => [$data['warrantyExpiry'] ?? null, 's'],
            'warrantyDetails'    => [$data['warrantyDetails'] ?? null, 's'],
            'licenseKey'         => [$licenseKeyCipher, 's'],
            'licenseSeats'       => [$data['licenseSeats'] ?? null, 'i'],
            'renewalDate'        => [$data['renewalDate'] ?? null, 's'],
            'accessUrl'          => [$data['accessUrl'] ?? null, 's'],
            'depreciationMethod' => [(string) ($data['depreciationMethod'] ?? 'none'), 's'],
            'usefulLifeMonths'   => [$data['usefulLifeMonths'] ?? null, 'i'],
            'salvageValuePence'  => [$data['salvageValuePence'] ?? null, 'i'],
            'isConfidential'     => [(int) ($data['isConfidential'] ?? 0), 'i'],
            'publicToken'        => [$publicToken, 's'],
            'publicPageEnabled'  => [(int) ($data['publicPageEnabled'] ?? 1), 'i'],
            'parentAssetID'      => [$data['parentAssetID'] ?? null, 'i'],
            'createdByID'        => [$actorUserId, 'i'],
        ];

        ['columns' => $columns, 'types' => $types, 'params' => $params] = self::splitFields($fields);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $stmt = $db->prepare('INSERT INTO tblAssets (`' . implode('`, `', $columns) . '`) VALUES (' . $placeholders . ')');
        if ($stmt === false) {
            error_log('AssetRegister::createAsset() prepare failed: ' . $db->error);
            return 0;
        }

        try {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            // 🪞 Most likely cause: a duplicate assetTagCode within this
            // site (uq_asset_tag) — mysqli_report(MYSQLI_REPORT_STRICT) is
            // enabled repo-wide (bootstrap.php), so a constraint violation
            // throws here rather than returning false.
            error_log('AssetRegister::createAsset() insert failed: ' . $e->getMessage());
            $stmt->close();
            return 0;
        }
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        if ($newId <= 0) {
            return 0;
        }

        // 📜 Audit the create. licenseKey/publicToken are NEVER placed in
        // the change-set as plaintext, ciphertext, or the raw token — see
        // updateAsset()'s matching comment for why: AssetRegister::audit()
        // redacts by FIELD NAME for its own tblAssetAudit row, but the
        // mirrored tblAuditTrail row (via Logger::audit()) serialises
        // whatever raw arrays we hand it with no redaction pass of its own.
        $auditNew = [];
        foreach ($fields as $col => $pair) {
            if ($col === 'publicToken') {
                continue; // internal secret token — omit entirely, never logged
            }
            $auditNew[$col] = $pair[0];
        }
        $auditNew['licenseKey'] = $licenseKeyProvided === true ? '(set)' : null;

        self::audit('asset', $newId, $newId, 'create', null, $auditNew);

        return $newId;
    }

    /**
     * Update an existing asset. `licenseKey` follows a "leave blank to
     * keep" convention — edit.php never pre-fills this field with the
     * decrypted value (it's write-only in the UI), so an empty submission
     * means "don't touch the stored licence key", not "clear it".
     *
     * Same caller contract as createAsset(): $data must already be
     * validated/coerced by the caller (`_apps/assets/save.php`).
     *
     * @param array<string, mixed> $data
     *
     * @return bool True on success, false if the asset doesn't exist (or
     *              isn't on this site) or the update failed
     */
    public static function updateAsset(int $assetId, array $data, int $actorUserId): bool
    {
        $db     = App::db();
        $siteId = Site::id();

        $old = self::get($assetId);
        if ($old === null || (int) $old['siteID'] !== $siteId) {
            return false;
        }

        $rawLicenseKey      = trim((string) ($data['licenseKey'] ?? ''));
        $licenseKeyChanged  = $rawLicenseKey !== '';
        $licenseKeyCipher   = $licenseKeyChanged === true ? encrypt_setting($rawLicenseKey) : null;

        $fields = [
            'assetKind'          => [(string) ($data['assetKind'] ?? $old['assetKind']), 's'],
            'name'               => [(string) ($data['name'] ?? $old['name']), 's'],
            'description'        => [$data['description'] ?? null, 's'],
            'categoryID'         => [$data['categoryID'] ?? null, 'i'],
            'locationID'         => [$data['locationID'] ?? null, 'i'],
            'manufacturer'       => [$data['manufacturer'] ?? null, 's'],
            'model'              => [$data['model'] ?? null, 's'],
            'serialNumber'       => [$data['serialNumber'] ?? null, 's'],
            'assetTagCode'       => [$data['assetTagCode'] ?? null, 's'],
            'features'           => [$data['features'] ?? null, 's'],
            'conditionState'     => [(string) ($data['conditionState'] ?? $old['conditionState']), 's'],
            'status'             => [(string) ($data['status'] ?? $old['status']), 's'],
            'purchaseDate'       => [$data['purchaseDate'] ?? null, 's'],
            'purchaseStore'      => [$data['purchaseStore'] ?? null, 's'],
            'purchaseCostPence'  => [$data['purchaseCostPence'] ?? null, 'i'],
            'currency'           => [(string) ($data['currency'] ?? $old['currency']), 's'],
            'warrantyExpiry'     => [$data['warrantyExpiry'] ?? null, 's'],
            'warrantyDetails'    => [$data['warrantyDetails'] ?? null, 's'],
            'licenseSeats'       => [$data['licenseSeats'] ?? null, 'i'],
            'renewalDate'        => [$data['renewalDate'] ?? null, 's'],
            'accessUrl'          => [$data['accessUrl'] ?? null, 's'],
            'depreciationMethod' => [(string) ($data['depreciationMethod'] ?? $old['depreciationMethod']), 's'],
            'usefulLifeMonths'   => [$data['usefulLifeMonths'] ?? null, 'i'],
            'salvageValuePence'  => [$data['salvageValuePence'] ?? null, 'i'],
            'isConfidential'     => [(int) ($data['isConfidential'] ?? 0), 'i'],
            'publicPageEnabled'  => [(int) ($data['publicPageEnabled'] ?? 0), 'i'],
            'parentAssetID'      => [$data['parentAssetID'] ?? null, 'i'],
        ];
        if ($licenseKeyChanged === true) {
            $fields['licenseKey'] = [$licenseKeyCipher, 's'];
        }

        ['columns' => $columns, 'types' => $types, 'params' => $params] = self::splitFields($fields);
        $setClause = implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = ?', $columns));
        $types    .= 'ii';
        $params[]  = $assetId;
        $params[]  = $siteId;

        $stmt = $db->prepare('UPDATE tblAssets SET ' . $setClause . ' WHERE assetID = ? AND siteID = ?');
        if ($stmt === false) {
            error_log('AssetRegister::updateAsset() prepare failed: ' . $db->error);
            return false;
        }

        try {
            $stmt->bind_param($types, ...$params);
            $ok = $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            // 🪞 Most likely cause: a duplicate assetTagCode within this
            // site (uq_asset_tag) — see createAsset()'s matching comment.
            error_log('AssetRegister::updateAsset() update failed: ' . $e->getMessage());
            $stmt->close();
            return false;
        }
        $stmt->close();

        if ($ok === false) {
            return false;
        }

        // 📜 Audit — restrict the diff to just the editable fields we
        // touched (avoids a misleading "cleared" diff against columns like
        // createdAt/isDeleted/publicToken that $data never mentions).
        // licenseKey is NEVER placed in either side as plaintext OR
        // ciphertext — see createAsset()'s comment for why a marker string
        // is the only safe payload to hand to self::audit().
        $auditOld = array_intersect_key($old, $fields);
        $auditNew = [];
        foreach ($fields as $col => $pair) {
            $auditNew[$col] = $pair[0];
        }
        if ($licenseKeyChanged === true) {
            $auditOld['licenseKey'] = '(previous value)';
            $auditNew['licenseKey'] = '(new value)';
        }

        self::audit('asset', $assetId, $assetId, 'update', $auditOld, $auditNew);

        return true;
    }

    /**
     * Soft-delete an asset (isDeleted = 1) — never a hard DELETE, so every
     * child row (resources/owners/loans/…) and every audit trail entry
     * keeps a valid assetID to point back at.
     *
     * @return bool True if a row was actually deleted, false if the asset
     *              didn't exist, wasn't on this site, or was already deleted
     */
    public static function softDeleteAsset(int $assetId, int $actorUserId): bool
    {
        $db     = App::db();
        $siteId = Site::id();

        $stmt = $db->prepare('UPDATE tblAssets SET isDeleted = 1 WHERE assetID = ? AND siteID = ? AND isDeleted = 0');
        if ($stmt === false) {
            error_log('AssetRegister::softDeleteAsset() prepare failed: ' . $db->error);
            return false;
        }
        $stmt->bind_param('ii', $assetId, $siteId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($ok === false || $affected <= 0) {
            return false;
        }

        self::audit('asset', $assetId, $assetId, 'delete', ['isDeleted' => 0], ['isDeleted' => 1]);

        return true;
    }

    /* ==========================================================================
     * 🏷️ Categories + 📍 Locations — reference data (#394)
     * ------------------------------------------------------------------------
     * Deliberately NOT routed through self::audit() — that choke-point is
     * asset-scoped (every row requires an assetID), and categories/
     * locations are site-wide reference data with no owning asset. A small
     * Logger::activity() call is enough to keep them visible in the shared
     * admin activity log without forcing an artificial assetID=0 through
     * the asset audit trail.
     * ======================================================================== */

    /**
     * List a site's asset categories, optionally active-only.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listCategories(int $siteId, bool $activeOnly = false): array
    {
        $db = App::db();
        $sql = 'SELECT * FROM tblAssetCategories WHERE siteID = ?'
            . ($activeOnly === true ? ' AND isActive = 1' : '')
            . ' ORDER BY sortOrder ASC, categoryName ASC';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            error_log('AssetRegister::listCategories() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('i', $siteId);
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
     * Create or update an asset category. $categoryId = 0 creates a new
     * row; a positive id updates that row (scoped to $siteId).
     *
     * $data keys: categoryName (required), icon (optional, Font Awesome
     * class), sortOrder (optional, default 0).
     *
     * @param array<string, mixed> $data
     *
     * @return int The category's id (new or existing), or 0 on failure /
     *             validation error (blank name)
     */
    public static function saveCategory(int $siteId, int $categoryId, array $data, int $actorUserId): int
    {
        $db   = App::db();
        $name = trim((string) ($data['categoryName'] ?? ''));
        if ($name === '') {
            return 0;
        }
        $icon = trim((string) ($data['icon'] ?? ''));
        $icon = $icon !== '' ? $icon : null;
        $sortOrder = (int) ($data['sortOrder'] ?? 0);

        try {
            if ($categoryId > 0) {
                $stmt = $db->prepare(
                    'UPDATE tblAssetCategories SET categoryName = ?, icon = ?, sortOrder = ? WHERE categoryID = ? AND siteID = ?'
                );
                if ($stmt === false) {
                    return 0;
                }
                $stmt->bind_param('ssiii', $name, $icon, $sortOrder, $categoryId, $siteId);
                $ok = $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                if ($ok === false || $affected < 0) {
                    return 0;
                }
                Logger::activity('AssetCategorySaved', 'Updated asset category: ' . $name, $actorUserId);
                return $categoryId;
            }

            $stmt = $db->prepare('INSERT INTO tblAssetCategories (siteID, categoryName, icon, sortOrder) VALUES (?, ?, ?, ?)');
            if ($stmt === false) {
                return 0;
            }
            $stmt->bind_param('issi', $siteId, $name, $icon, $sortOrder);
            $ok = $stmt->execute();
            $newId = (int) $stmt->insert_id;
            $stmt->close();
            if ($ok === false || $newId <= 0) {
                return 0;
            }
            Logger::activity('AssetCategorySaved', 'Created asset category: ' . $name, $actorUserId);
            return $newId;
        } catch (\mysqli_sql_exception $e) {
            error_log('AssetRegister::saveCategory() failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Flip a category's isActive flag (1 → 0 or 0 → 1) in a single
     * round-trip. Inactive categories stay selectable on already-assigned
     * assets (see listCategories()'s $activeOnly param) — this only hides
     * them from the "create/edit asset" dropdown.
     */
    public static function toggleCategoryActive(int $categoryId, int $siteId, int $actorUserId): bool
    {
        $db = App::db();
        $stmt = $db->prepare('UPDATE tblAssetCategories SET isActive = 1 - isActive WHERE categoryID = ? AND siteID = ?');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $categoryId, $siteId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($ok === true && $affected > 0) {
            Logger::activity('AssetCategoryToggled', 'Toggled active state for asset category #' . $categoryId, $actorUserId);
        }
        return $ok === true && $affected > 0;
    }

    /**
     * List a site's asset locations, optionally active-only.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listLocations(int $siteId, bool $activeOnly = false): array
    {
        $db = App::db();
        $sql = 'SELECT * FROM tblAssetLocations WHERE siteID = ?'
            . ($activeOnly === true ? ' AND isActive = 1' : '')
            . ' ORDER BY locationName ASC';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            error_log('AssetRegister::listLocations() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('i', $siteId);
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
     * Create or update an asset location. Supports self-nesting via
     * parentLocationID (e.g. Building → Room → Cupboard) — a single-hop
     * self-reference (a location naming itself as its own parent) is
     * rejected by falling back to NULL; deeper cycles can't occur because
     * the parent dropdown only ever offers locations that already exist.
     *
     * $data keys: locationName (required), details (optional),
     * parentLocationID (optional, int or blank).
     *
     * @param array<string, mixed> $data
     *
     * @return int The location's id (new or existing), or 0 on failure /
     *             validation error (blank name)
     */
    public static function saveLocation(int $siteId, int $locationId, array $data, int $actorUserId): int
    {
        $db   = App::db();
        $name = trim((string) ($data['locationName'] ?? ''));
        if ($name === '') {
            return 0;
        }
        $details = trim((string) ($data['details'] ?? ''));
        $details = $details !== '' ? $details : null;
        $parentLocationId = (int) ($data['parentLocationID'] ?? 0);
        $parentLocationId = $parentLocationId > 0 ? $parentLocationId : null;
        if ($parentLocationId !== null && $parentLocationId === $locationId) {
            $parentLocationId = null; // 🔁 can't be its own parent
        }

        try {
            if ($locationId > 0) {
                $stmt = $db->prepare(
                    'UPDATE tblAssetLocations SET locationName = ?, details = ?, parentLocationID = ? WHERE locationID = ? AND siteID = ?'
                );
                if ($stmt === false) {
                    return 0;
                }
                $stmt->bind_param('ssiii', $name, $details, $parentLocationId, $locationId, $siteId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok === false) {
                    return 0;
                }
                Logger::activity('AssetLocationSaved', 'Updated asset location: ' . $name, $actorUserId);
                return $locationId;
            }

            $stmt = $db->prepare('INSERT INTO tblAssetLocations (siteID, locationName, details, parentLocationID) VALUES (?, ?, ?, ?)');
            if ($stmt === false) {
                return 0;
            }
            $stmt->bind_param('issi', $siteId, $name, $details, $parentLocationId);
            $ok = $stmt->execute();
            $newId = (int) $stmt->insert_id;
            $stmt->close();
            if ($ok === false || $newId <= 0) {
                return 0;
            }
            Logger::activity('AssetLocationSaved', 'Created asset location: ' . $name, $actorUserId);
            return $newId;
        } catch (\mysqli_sql_exception $e) {
            error_log('AssetRegister::saveLocation() failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Flip a location's isActive flag. See toggleCategoryActive() — same
     * shape, same "stays selectable on already-assigned assets" rationale.
     */
    public static function toggleLocationActive(int $locationId, int $siteId, int $actorUserId): bool
    {
        $db = App::db();
        $stmt = $db->prepare('UPDATE tblAssetLocations SET isActive = 1 - isActive WHERE locationID = ? AND siteID = ?');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $locationId, $siteId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($ok === true && $affected > 0) {
            Logger::activity('AssetLocationToggled', 'Toggled active state for asset location #' . $locationId, $actorUserId);
        }
        return $ok === true && $affected > 0;
    }

    /* ==========================================================================
     * 📎 Resources (#394)
     * ======================================================================== */

    /**
     * List an asset's attached resources (manuals/guides/photos/receipts/…),
     * newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listResources(int $assetId): array
    {
        $db = App::db();
        $stmt = $db->prepare('SELECT * FROM tblAssetResources WHERE assetID = ? ORDER BY createdAt DESC');
        if ($stmt === false) {
            error_log('AssetRegister::listResources() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('i', $assetId);
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
     * Attach a resource to an asset — either an external link OR an
     * uploaded file (the caller, `_apps/assets/resource-save.php`, is
     * responsible for enforcing exactly-one-of and for the upload
     * allow-list/MIME-sniff/size-cap/safe-filename work; this method only
     * persists whatever it's handed).
     *
     * $data keys: resourceType (validated against RESOURCE_TYPES by the
     * caller), title, linkUrl|null, fileName|null, filePath|null (relative
     * to _uploads/assets/), fileSize|null, mimeType|null, isPublic (0|1,
     * optional).
     *
     * @param array<string, mixed> $data
     *
     * @return int New resourceID, or 0 on failure
     */
    public static function addResource(int $assetId, array $data, int $actorUserId): int
    {
        $db     = App::db();
        $siteId = Site::id();

        // 🔒 Only manual/guide/photo may ever be public — an ownership
        // agreement / insurance / legal / receipt attachment must NEVER be
        // exposed on the public /a/{token} page, even if a tampered form
        // submits isPublic=1 for one. Enforced at the model so EVERY caller
        // (this pass's resource-save.php and any future one) is covered.
        $resType  = (string) ($data['resourceType'] ?? 'other');
        $isPublic = (in_array($resType, self::PUBLIC_ELIGIBLE_RESOURCE_TYPES, true) === true
            && (int) ($data['isPublic'] ?? 0) === 1) ? 1 : 0;

        $fields = [
            'siteID'       => [$siteId, 'i'],
            'assetID'      => [$assetId, 'i'],
            'resourceType' => [$resType, 's'],
            'title'        => [(string) ($data['title'] ?? ''), 's'],
            'linkUrl'      => [$data['linkUrl'] ?? null, 's'],
            'fileName'     => [$data['fileName'] ?? null, 's'],
            'filePath'     => [$data['filePath'] ?? null, 's'],
            'fileSize'     => [$data['fileSize'] ?? null, 'i'],
            'mimeType'     => [$data['mimeType'] ?? null, 's'],
            'isPublic'     => [$isPublic, 'i'],
            'uploadedByID' => [$actorUserId, 'i'],
        ];

        ['columns' => $columns, 'types' => $types, 'params' => $params] = self::splitFields($fields);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $stmt = $db->prepare('INSERT INTO tblAssetResources (`' . implode('`, `', $columns) . '`) VALUES (' . $placeholders . ')');
        if ($stmt === false) {
            error_log('AssetRegister::addResource() prepare failed: ' . $db->error);
            return 0;
        }

        try {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            error_log('AssetRegister::addResource() insert failed: ' . $e->getMessage());
            $stmt->close();
            return 0;
        }
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        if ($newId <= 0) {
            return 0;
        }

        // 📜 No sensitive fields here (unlike tblAssets) — the full row is
        // safe to log as-is.
        $auditNew = [];
        foreach ($fields as $col => $pair) {
            $auditNew[$col] = $pair[0];
        }
        self::audit('resource', $newId, $assetId, 'create', null, $auditNew);

        return $newId;
    }

    /**
     * Delete a resource — removes the DB row AND, for a file-backed
     * resource, the underlying file under _uploads/assets/. Site-scoped via
     * Site::id() so a resourceID from another tenant can never be reached
     * even if guessed. The file is unlinked BEFORE the DB row so a failed
     * unlink still leaves a recoverable DB record rather than an orphaned,
     * un-referenced file.
     *
     * @return bool True if the resource existed (on this site) and was
     *              removed
     */
    public static function deleteResource(int $resourceId, int $actorUserId): bool
    {
        $db     = App::db();
        $siteId = Site::id();

        $stmt = $db->prepare('SELECT * FROM tblAssetResources WHERE resourceID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $resourceId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null || $row === false) {
            return false;
        }

        $assetId = (int) $row['assetID'];

        if (($row['filePath'] ?? null) !== null && (string) $row['filePath'] !== '') {
            // 🔒 basename() — the same path-safety rule as
            // _apps/assets/resource-download.php's stream; filePath is
            // always server-generated (see resource-save.php) but this
            // stays defensive rather than trusting that invariant blindly.
            $diskPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'assets'
                . DIRECTORY_SEPARATOR . basename((string) $row['filePath']);
            if (is_file($diskPath) === true) {
                @unlink($diskPath);
            }
        }

        $delStmt = $db->prepare('DELETE FROM tblAssetResources WHERE resourceID = ? AND siteID = ?');
        if ($delStmt === false) {
            return false;
        }
        $delStmt->bind_param('ii', $resourceId, $siteId);
        $ok = $delStmt->execute();
        $affected = $delStmt->affected_rows;
        $delStmt->close();

        if ($ok === false || $affected <= 0) {
            return false;
        }

        self::audit('resource', $resourceId, $assetId, 'delete', $row, null);

        return true;
    }

    /* ==========================================================================
     * 🔓 Licence key reveal (#394)
     * ======================================================================== */

    /**
     * Decrypt a stored `tblAssets.licenseKey` ciphertext for display.
     * Callers MUST gate this behind a manager-only check themselves (see
     * `_apps/assets/item.php`) — this method performs no authorisation of
     * its own, matching `decrypt_setting()`'s own contract (bootstrap.php).
     *
     * @param string|null $cipher Base64-encoded ciphertext (as stored in
     *                            tblAssets.licenseKey), or null/empty
     *
     * @return string Decrypted plaintext, or '' when empty/unset/tampered
     */
    public static function decryptLicenseKey(?string $cipher): string
    {
        if ($cipher === null || $cipher === '') {
            return '';
        }
        try {
            return decrypt_setting($cipher);
        } catch (\Throwable $e) {
            error_log('AssetRegister::decryptLicenseKey() failed: ' . $e->getMessage());
            return '';
        }
    }
}
