<?php
// Path: _core/AssetRegister.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Register + Audit Choke-Point 📦🔐
 * -----------------------------------------------------------------------------
 * Service class for the Asset Tracker app (slug `assets`, #393). Four
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
 *   3. Co-ownership + external orgs + agreement vault (#396, this pass).
 *      `listOwners()`/`addOwner()`/`removeOwner()`/`setOwnerAuthority()`
 *      manage `tblAssetOwners` — UNLIKE createAsset()/updateAsset(),
 *      `addOwner()` does NOT trust its caller's validation; it re-checks
 *      partyType/roleKind ENUMs, the "exactly one of userID/deptID/groupID/
 *      orgID" rule (no SQL constraint enforces this — see migration 159's
 *      table comment), FK existence/site-scope, and the sharePercent range
 *      itself (see that method's own doc for why). `listOrgs()`/
 *      `saveOrg()`/`toggleOrgActive()` manage the external-organisation
 *      register (`tblAssetOrgs`) with the same site-wide/`Logger::
 *      activity()`-only convention as categories/locations. `owner`
 *      mutations DO route through `self::audit()` (entityType `'owner'`);
 *      `updateOwnershipTerms()` writes `tblAssets.ownershipTerms` via
 *      entityType `'asset'`, same as updateAsset(). `listAgreementDocs()`
 *      is a restricted view over the SAME `tblAssetResources` table
 *      `listResources()` reads — see `AGREEMENT_VAULT_RESOURCE_TYPES`'s doc
 *      and `_apps/assets/item.php`'s header for the access-control split
 *      between the general Resources panel and the confidential vault
 *      panel.
 *
 *   4. Global identifiers (#397, this pass). `listIdentifierTypes()` reads
 *      the GLOBAL `tblAssetIdentifierTypes` vocabulary (21 standard GS1/
 *      barcode/RFID schemes seeded by migration 159 — no admin screen to
 *      manage that vocabulary ships in this pass, see #397's scope note).
 *      `listIdentifiers()`/`addIdentifier()`/`removeIdentifier()`/
 *      `setPrimaryIdentifier()` manage `tblAssetIdentifiers` — `identifier`
 *      mutations DO route through `self::audit()`. Reuses
 *      `validateIdentifier()` (added in the #394 pass above) unchanged —
 *      format/check-digit validation is NON-BLOCKING, so `addIdentifier()`
 *      always saves and only ever downgrades the row's `isVerified` flag
 *      or surfaces a warning string, never rejects. Single-primary-per-
 *      asset enforcement has no SQL constraint backing it (same "PHP, not
 *      schema" convention as tblAssetOwners' exactly-one-party-FK rule in
 *      #396 above) and is wrapped in an explicit DB transaction so a
 *      mid-way failure can't leave an asset with zero primary identifiers.
 *
 *   5. Loan register (#398, this pass). `canApproveLoan()` is the lending-
 *      authority gate every mutating loan action checks — true for an
 *      admin/asset_manager OR for a user with `isLendingAuthority = 1` on a
 *      `tblAssetOwners` row for this asset (direct, or via a dept/group
 *      they belong to — modelled on `isResponsibleFor()`'s own joins, just
 *      filtered to the lending-authority flag). Unlike `isResponsibleFor()`,
 *      this method DOES fold the admin/manager bypass in itself (see its
 *      own doc for why that bypass is only applied when checking the
 *      CURRENT session user, never an arbitrary `$userId` a caller passes
 *      in). `listLoans()`/`listLoansForAsset()` are read helpers (the
 *      former site-wide for `_apps/assets/loans.php`'s register, the
 *      latter asset-scoped for `item.php`'s Loans panel) that resolve a
 *      human `counterpartyDisplayName` and compute `isOverdue` in PHP
 *      (status='active' AND dueDate in the past — MySQL 8 has no reliable
 *      timezone-aware "is this DATE column before today" that doesn't need
 *      the same `CURDATE()` PHP would otherwise duplicate, so the flag is
 *      computed once, in one place, right after the fetch). `tblAssetLoans`
 *      mutations DO route through `self::audit()` (entityType 'loan') —
 *      `createLoanRequest()` audits 'create'; `loanAction()`'s five private
 *      per-verb helpers (`loanApprove()`/`loanDecline()`/`loanCheckout()`/
 *      `loanCheckin()`/`loanCancel()`) each audit their own verb. The
 *      state-machine (requested → approved/declined; approved/requested →
 *      active; active → returned; requested/approved → cancelled) is
 *      enforced by a `WHERE … AND status = '…'` guard on every UPDATE
 *      (an affected-rows check catches a state that moved between the
 *      read and the write, same race-safety idiom as `setOwnerAuthority()`'s
 *      no-op check) — no action ever reaches a state its current status
 *      doesn't legally allow, and every rejection returns a friendly
 *      `msg` rather than throwing. `loanCheckout()`/`loanCheckin()` are the
 *      only two loan actions that ALSO touch `tblAssets` (status, and for
 *      check-in, `conditionState` too) — both wrap their loan-row UPDATE
 *      and their `tblAssets` UPDATE in one `App::beginTransaction()`/
 *      `commit()`/`rollback()` unit, same convention as `addIdentifier()`'s
 *      transactional pair above, so a mid-way failure can never leave the
 *      loan record and the asset's own status column disagreeing about
 *      whether the item is out/in.
 *
 *   6. Maintenance log + depreciation (#399, this pass). `canManageMaintenance()`
 *      is an EXACT mirror of `canApproveLoan()` (point 5 above) — same
 *      session-scoped admin/asset_manager bypass, same direct/dept/group
 *      `tblAssetOwners` joins — narrowed to the `isMaintenanceAuthority`
 *      flag instead of `isLendingAuthority`. `listMaintenance()` (per-asset,
 *      newest performed/created first) and `listUpcomingMaintenance()`
 *      (site-wide, every SCHEDULED entry with a `nextDueDate` set, soonest
 *      first — feeds `maintenance.php`'s no-`assetID` view) both resolve a
 *      `performedByDisplay` name (the linked portal user, or the free-text
 *      `performedByName`, or null) and compute `isOverdue`/`isUpcoming` in
 *      PHP once per row, same rationale as `listLoans()`'s `isOverdue` (see
 *      point 5). `addMaintenance()`/`updateMaintenance()` re-validate
 *      `maintType`/`status` against this class's ENUM allow-lists,
 *      `costPence` (int ≥ 0 or null — the CALLER converts a pounds input to
 *      pence, mirroring every other money field in this app), both dates
 *      via the shared `parseOptionalDate()` helper, and `performedByUserID`
 *      via `partyExistsOnSite()` (reused unchanged from #396) when supplied
 *      — exactly one of a real portal user OR free-text `performedByName`
 *      is persisted per row, though (unlike `addOwner()`'s party FK or
 *      `createLoanRequest()`'s counterparty) BOTH may legitimately be blank
 *      (unattended/self-service maintenance with no named performer).
 *      `updateMaintenance()`/`deleteMaintenance()` IDOR-guard on
 *      `maintID` + `assetID` + `siteID` before ever touching a row, same
 *      shape as `loanAction()`'s read-then-mutate pattern. Every mutation
 *      routes through `self::audit()` (entityType `'maintenance'`, already
 *      wired into `TABLE_FOR_ENTITY` since the #395 audit choke-point pass).
 *      `computeStraightLineValue()` is a PURE helper (no DB, no site/audit
 *      context) — display-only "as of today" estimate for `item.php`'s
 *      Depreciation readout; it clamps at the salvage value (never
 *      negative-depreciates below it) and at the purchase cost (never
 *      appreciates above it, even against a mis-entered salvage value
 *      exceeding cost), and returns null rather than guessing whenever any
 *      required input is missing or the method isn't `'straight-line'`.
 *      Reducing-balance and PERSISTING `tblAssets.currentValuePence`/
 *      `valuationDate` are explicitly out of scope here — Phase-3 cron work
 *      per #399's spec.
 *
 * All queries are MySQLi prepared statements via `App::db()` — never
 * string-interpolated user input (house rule, .claude/CLAUDE.md → Code Style).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/394
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/395
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/396
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/397
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/398
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/399
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

    /**
     * Resource types that make up the confidential "Ownership & legal vault"
     * (#396) — a strict subset of RESOURCE_TYPES, and the exact complement
     * of the vault-panel restriction: `_apps/assets/item.php` renders these
     * ONLY inside the admin/asset_manager/isResponsibleFor()-gated vault
     * panel, and filters them OUT of the general (any-logged-in-viewer)
     * Resources panel — see listAgreementDocs() below and item.php's own
     * header comment. Every one of these types is already excluded from
     * PUBLIC_ELIGIBLE_RESOURCE_TYPES above, so addResource() can never mark
     * one isPublic=1 regardless of what a tampered form posts.
     *
     * @var string[]
     */
    public const AGREEMENT_VAULT_RESOURCE_TYPES = ['ownership-agreement', 'insurance', 'legal'];

    /** @var string[] tblAssetOwners.partyType */
    public const OWNER_PARTY_TYPES = ['user', 'dept', 'group', 'org'];

    /** @var string[] tblAssetOwners.roleKind */
    public const OWNER_ROLE_KINDS = ['owner', 'co-owner', 'custodian', 'stakeholder'];

    /** @var string[] tblAssetLoans.direction — 'out' = we lend; 'in' = we borrow (#398) */
    public const LOAN_DIRECTIONS = ['out', 'in'];

    /** @var string[] tblAssetLoans.counterpartyType (#398) */
    public const LOAN_COUNTERPARTY_TYPES = ['user', 'org', 'other'];

    /** @var string[] tblAssetLoans.status — the full loan lifecycle (#398) */
    public const LOAN_STATUSES = ['requested', 'approved', 'declined', 'active', 'returned', 'cancelled'];

    /**
     * Statuses a loan may be created into via {@see createLoanRequest()} or
     * transitioned OUT OF by {@see loanAction()}'s `approve`/`decline`/
     * `checkout`/`cancel` verbs — i.e. every status that still represents
     * an unresolved/in-flight loan. Used by both createLoanRequest()'s
     * "one unresolved loan at a time per asset" guard and loans.php's
     * `overdueOnly`-adjacent "active" filter shorthand.
     *
     * @var string[]
     */
    public const LOAN_OPEN_STATUSES = ['requested', 'approved', 'active'];

    /**
     * The two tblAssetOwners boolean columns setOwnerAuthority() is allowed
     * to flip. Deliberately a closed allow-list — see that method's inline
     * comment for why a validated column name from a small constant set is
     * safe to interpolate into an UPDATE's SET clause (never user input
     * directly), unlike every VALUE in this class which always travels via
     * a bound parameter.
     *
     * @var string[]
     */
    public const OWNER_AUTHORITY_FIELDS = ['isLendingAuthority', 'isMaintenanceAuthority'];

    /** @var string[] tblAssetMaintenance.maintType (#399) */
    public const MAINTENANCE_TYPES = ['service', 'repair', 'inspection', 'calibration', 'upgrade', 'other'];

    /** @var string[] tblAssetMaintenance.status (#399) */
    public const MAINTENANCE_STATUSES = ['scheduled', 'completed', 'cancelled'];

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
     * 🆔 Identifiers (#397) — GS1 family / EPC-RFID identifiers per asset.
     * ------------------------------------------------------------------------
     * `tblAssetIdentifiers` mutations DO route through self::audit()
     * (entityType 'identifier', maps to the table via TABLE_FOR_ENTITY) —
     * same convention as owners/resources above. `listIdentifierTypes()` is
     * a read over the GLOBAL, site-agnostic `tblAssetIdentifierTypes`
     * vocabulary seeded by migration 159 (21 standard schemes) — this pass
     * ships NO admin screen to manage that vocabulary (see #397's scope
     * note); users pick from what's already seeded.
     *
     * Single-primary enforcement (addIdentifier()'s isPrimary branch and
     * setPrimaryIdentifier()) has no SQL constraint backing it — same
     * "enforced in PHP, not in the schema" convention as tblAssetOwners'
     * exactly-one-party-FK rule (#396 section above) — so both methods wrap
     * their "clear every other row" + "write this row" pair in an
     * App::beginTransaction()/commit()/rollback() unit (mirrors
     * attendance/api/create.php's shape) rather than letting a failure
     * between the two steps leave an asset with zero primary identifiers.
     * ======================================================================== */

    /**
     * List every ACTIVE identifier-scheme type (tblAssetIdentifierTypes),
     * ordered by sortOrder. Migration 159's seed data puts GIAI/GRAI first
     * (the two GS1 keys purpose-built for identifying assets), then the
     * rest of the GS1-key family, retail barcodes, RFID/EPC carriers, and
     * classification codes. GLOBAL reference data — no siteID column
     * (mirrors tblRoles, see migration 159's header) — so no site filter
     * applies. Feeds the add-identifier `<select>`'s `<optgroup>` grouping
     * on item.php; `category` is the grouping key.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listIdentifierTypes(): array
    {
        $db = App::db();
        // 🪞 No user input in this query (a static, unparameterised read of
        // global reference data) — a plain query() is safe and matches the
        // house convention already used for the equally-global tblGroups
        // read in _apps/assets/item.php's owner-picker.
        $result = $db->query(
            'SELECT * FROM tblAssetIdentifierTypes WHERE isActive = 1 ORDER BY sortOrder ASC, label ASC'
        );
        $rows = [];
        if ($result !== false) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * List an asset's recorded identifiers, LEFT JOINed to their type
     * (label/category/checkDigitScheme). `typeCode` is a SOFT reference
     * (migration 159's header) — a row whose type was later deactivated,
     * or was never a real seeded code to begin with, still lists, with the
     * join columns null; this method fills a friendly fallback so item.php
     * never has to special-case a missing join itself (mirrors
     * listOwners()'s partyName fallback for the identical reason).
     *
     * Ordered primary-first, then by the type's category (GS1 keys →
     * retail barcodes → carriers → classification → other) and sortOrder
     * — matching the add-form's optgroup order in item.php.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listIdentifiers(int $assetId): array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT i.*, t.label AS typeLabel, t.category AS typeCategory, '
            . '      t.checkDigitScheme AS typeCheckDigitScheme '
            . 'FROM tblAssetIdentifiers i '
            . 'LEFT JOIN tblAssetIdentifierTypes t ON t.typeCode = i.typeCode '
            . 'WHERE i.assetID = ? '
            . "ORDER BY i.isPrimary DESC, "
            . "FIELD(t.category, 'gs1-key', 'retail-barcode', 'carrier', 'classification', 'other') ASC, "
            . 't.sortOrder ASC, i.identifierID ASC'
        );
        if ($stmt === false) {
            error_log('AssetRegister::listIdentifiers() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            // 🪞 Soft-reference fallback — see method doc above.
            $row['typeLabel']    = $row['typeLabel']    ?? (string) $row['typeCode'];
            $row['typeCategory'] = $row['typeCategory'] ?? 'other';
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Add a GS1/barcode/RFID identifier to an asset. Runs
     * `self::validateIdentifier()` (format regex + check-digit — reused
     * unchanged, never re-implemented here) and ALWAYS saves — validation
     * only ever produces non-blocking warnings that the caller
     * (`identifiers-save.php`) surfaces via a flash message. `isVerified`
     * is set to 1 only when validation passed with ZERO warnings — a known
     * type whose format/check-digit both confirm cleanly; an unknown type,
     * a non-numeric check-digit target, or the stubbed GMN scheme all
     * still SAVE, just unverified (no ✔ badge on item.php).
     *
     * $data keys: typeCode (required, ≤20 chars — VARCHAR(20) column),
     * value (required, ≤255 chars), subScheme (optional, ≤30 chars),
     * isPrimary (bool-ish), notes (optional, ≤500 chars).
     *
     * Single-primary enforcement: when isPrimary is requested, every OTHER
     * identifier on this asset is cleared to isPrimary=0 first, in the
     * SAME transaction as the insert — see this section's header comment
     * for why a plain sequential UPDATE-then-INSERT (as tblAssetOwners'
     * exactly-one-FK rule gets away with, since that rule has no
     * multi-statement race) isn't quite enough here: a duplicate-key
     * failure on the insert must not leave every other identifier cleared
     * with no replacement primary written.
     *
     * Duplicate guard: `uq_asset_ident (assetID, typeCode, value)` — a
     * repeat submission of the same type+value for this asset throws
     * `mysqli_sql_exception` (MYSQLI_REPORT_STRICT is enabled repo-wide,
     * bootstrap.php), caught here and turned into a friendly "already
     * recorded" warning with id 0 rather than a 500 — mirrors
     * createAsset()/updateAsset()'s own duplicate-catch shape for
     * uq_asset_tag.
     *
     * @param array<string, mixed> $data
     *
     * @return array{id: int, warnings: string[]} id is 0 on any failure
     *         (asset not found, blank required field, or a caught
     *         duplicate) — warnings is always populated with a
     *         human-readable reason in that case too, not just on a
     *         successful-but-imperfect save.
     */
    public static function addIdentifier(int $assetId, array $data, int $actorUserId): array
    {
        $db     = App::db();
        $siteId = Site::id();

        // 🔒 Asset must exist and be on this site — get() is itself
        // site-scoped via Site::id().
        if (self::get($assetId) === null) {
            error_log('AssetRegister::addIdentifier() asset not found on this site: #' . $assetId);
            return ['id' => 0, 'warnings' => ['Asset not found.']];
        }

        // 🔢 typeCode — SOFT reference (migration 159 header): any
        // non-empty value up to the column's 20-char cap is accepted.
        // Whether it names a real, active type is exactly what
        // validateIdentifier() below already checks and warns about — not
        // duplicated here.
        $typeCode = trim((string) ($data['typeCode'] ?? ''));
        if ($typeCode === '') {
            return ['id' => 0, 'warnings' => ['An identifier type is required.']];
        }
        $typeCode = mb_substr($typeCode, 0, 20);

        // 📋 value — required, ≤255 (VARCHAR(255) column).
        $value = trim((string) ($data['value'] ?? ''));
        if ($value === '') {
            return ['id' => 0, 'warnings' => ['A value is required.']];
        }
        $value = mb_substr($value, 0, 255);

        $subScheme = trim((string) ($data['subScheme'] ?? ''));
        $subScheme = $subScheme !== '' ? mb_substr($subScheme, 0, 30) : null;

        $notes = trim((string) ($data['notes'] ?? ''));
        $notes = $notes !== '' ? mb_substr($notes, 0, 500) : null;

        $isPrimary = ((bool) ($data['isPrimary'] ?? false)) === true ? 1 : 0;

        // ✅ Non-blocking validation — see this section's header + the
        // dedicated "Identifier validation" section above for the
        // algorithm. NEVER rejects; only informs $warnings and
        // $isVerified below.
        $validation = self::validateIdentifier($typeCode, $value);
        $warnings   = $validation['warnings'];
        // 🏅 "Cleanly" verified = valid AND zero warnings — an unknown
        // type, a non-numeric mod-10 target, or the GMN stub all still
        // save, but none of them earn the ✔ verified badge (see method
        // doc above).
        $isVerified = ($validation['valid'] === true && count($warnings) === 0) ? 1 : 0;

        $fields = [
            'siteID'      => [$siteId, 'i'],
            'assetID'     => [$assetId, 'i'],
            'typeCode'    => [$typeCode, 's'],
            'subScheme'   => [$subScheme, 's'],
            'value'       => [$value, 's'],
            'isPrimary'   => [$isPrimary, 'i'],
            'isVerified'  => [$isVerified, 'i'],
            'notes'       => [$notes, 's'],
            'createdByID' => [$actorUserId, 'i'],
        ];
        ['columns' => $columns, 'types' => $types, 'params' => $params] = self::splitFields($fields);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        // 💾 Transaction — "clear other primaries" + "insert" are one
        // atomic unit whenever isPrimary is requested. See this section's
        // header comment for why.
        App::beginTransaction();
        $newId = 0;
        try {
            if ($isPrimary === 1) {
                $clearStmt = $db->prepare('UPDATE tblAssetIdentifiers SET isPrimary = 0 WHERE assetID = ? AND siteID = ?');
                if ($clearStmt === false) {
                    throw new \RuntimeException('Failed to prepare primary-clear: ' . $db->error);
                }
                $clearStmt->bind_param('ii', $assetId, $siteId);
                $clearStmt->execute();
                $clearStmt->close();
            }

            $stmt = $db->prepare('INSERT INTO tblAssetIdentifiers (`' . implode('`, `', $columns) . '`) VALUES (' . $placeholders . ')');
            if ($stmt === false) {
                throw new \RuntimeException('Failed to prepare identifier insert: ' . $db->error);
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $newId = (int) $stmt->insert_id;
            $stmt->close();

            App::commit();
        } catch (\mysqli_sql_exception $e) {
            // 🪞 Most likely cause: uq_asset_ident (assetID, typeCode,
            // value) — this exact type+value is already recorded for this
            // asset. A friendly, non-fatal warning rather than a 500 — see
            // method doc.
            App::rollback();
            error_log('AssetRegister::addIdentifier() insert failed: ' . $e->getMessage());
            return ['id' => 0, 'warnings' => ['This identifier is already recorded on this asset.']];
        } catch (\Throwable $e) {
            App::rollback();
            error_log('AssetRegister::addIdentifier() failed: ' . $e->getMessage());
            return ['id' => 0, 'warnings' => ['Could not save the identifier — please try again.']];
        }

        if ($newId <= 0) {
            return ['id' => 0, 'warnings' => ['Could not save the identifier — please try again.']];
        }

        $auditNew = [];
        foreach ($fields as $col => $pair) {
            $auditNew[$col] = $pair[0];
        }
        self::audit('identifier', $newId, $assetId, 'create', null, $auditNew);

        return ['id' => $newId, 'warnings' => $warnings];
    }

    /**
     * Remove an identifier row. IDOR guard: the row must belong to BOTH
     * $assetId AND the current site before it's touched — mirrors
     * removeOwner()'s own "confirm it belongs to this asset first"
     * pattern.
     *
     * @return bool True if a row existed (for this asset, on this site)
     *              and was removed
     */
    public static function removeIdentifier(int $identifierId, int $assetId, int $actorUserId): bool
    {
        $db     = App::db();
        $siteId = Site::id();

        $stmt = $db->prepare('SELECT * FROM tblAssetIdentifiers WHERE identifierID = ? AND assetID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iii', $identifierId, $assetId, $siteId);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($old === null || $old === false) {
            return false;
        }

        $delStmt = $db->prepare('DELETE FROM tblAssetIdentifiers WHERE identifierID = ? AND assetID = ? AND siteID = ?');
        if ($delStmt === false) {
            return false;
        }
        $delStmt->bind_param('iii', $identifierId, $assetId, $siteId);
        $ok = $delStmt->execute();
        $affected = $delStmt->affected_rows;
        $delStmt->close();

        if ($ok === false || $affected <= 0) {
            return false;
        }

        self::audit('identifier', $identifierId, $assetId, 'delete', $old, null);

        return true;
    }

    /**
     * Promote one identifier to the asset's single primary, clearing every
     * other one first — same single-primary rule as addIdentifier()'s
     * isPrimary branch, wrapped in the same transaction shape so a
     * mid-way failure can't leave the asset primary-less. IDOR-guarded
     * like removeIdentifier() above.
     *
     * @return bool True if the row existed (for this asset, on this site)
     *              and is now primary (including the already-primary
     *              no-op case)
     */
    public static function setPrimaryIdentifier(int $identifierId, int $assetId, int $actorUserId): bool
    {
        $db     = App::db();
        $siteId = Site::id();

        $stmt = $db->prepare('SELECT * FROM tblAssetIdentifiers WHERE identifierID = ? AND assetID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iii', $identifierId, $assetId, $siteId);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($old === null || $old === false) {
            return false;
        }

        if ((int) $old['isPrimary'] === 1) {
            return true; // 🔁 no-op — already primary
        }

        App::beginTransaction();
        try {
            $clearStmt = $db->prepare('UPDATE tblAssetIdentifiers SET isPrimary = 0 WHERE assetID = ? AND siteID = ?');
            if ($clearStmt === false) {
                throw new \RuntimeException('Failed to prepare primary-clear: ' . $db->error);
            }
            $clearStmt->bind_param('ii', $assetId, $siteId);
            $clearStmt->execute();
            $clearStmt->close();

            $setStmt = $db->prepare('UPDATE tblAssetIdentifiers SET isPrimary = 1 WHERE identifierID = ? AND assetID = ? AND siteID = ?');
            if ($setStmt === false) {
                throw new \RuntimeException('Failed to prepare primary-set: ' . $db->error);
            }
            $setStmt->bind_param('iii', $identifierId, $assetId, $siteId);
            $setStmt->execute();
            $setStmt->close();

            App::commit();
        } catch (\Throwable $e) {
            App::rollback();
            error_log('AssetRegister::setPrimaryIdentifier() failed: ' . $e->getMessage());
            return false;
        }

        self::audit('identifier', $identifierId, $assetId, 'update', ['isPrimary' => 0], ['isPrimary' => 1]);

        return true;
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
     * 👥 Owners + custodianship (#396)
     * ------------------------------------------------------------------------
     * `tblAssetOwners` deliberately has NO SQL-level constraint enforcing
     * "exactly one of userID/deptID/groupID/orgID" (see migration 159's
     * table comment) — that integrity rule lives entirely in addOwner()
     * below, which is why (unlike createAsset()/updateAsset(), which trust
     * their caller completely) addOwner() re-validates partyType, roleKind,
     * the exactly-one-FK rule, FK existence/site-scope, and the
     * sharePercent range itself rather than delegating that to
     * owners-save.php. Every mutation here routes through self::audit()
     * with entityType 'owner' (maps to tblAssetOwners via TABLE_FOR_ENTITY).
     * ======================================================================== */

    /**
     * List an asset's owner/custodian rows with the party's display name
     * resolved via a LEFT JOIN against whichever table partyType points at
     * (at most one of the four joins will ever match a given row, since
     * exactly one FK is populated per row — see addOwner()). A party whose
     * underlying row was itself hard-deleted out from under an ON DELETE
     * CASCADE race is defensively labelled rather than left blank — in
     * practice this should never happen since every FK here is
     * ON DELETE CASCADE (the owner row disappears alongside its party), but
     * the fallback costs nothing and avoids ever rendering an empty name.
     *
     * Ordered by roleKind (owner first, then co-owner/custodian/
     * stakeholder) so the primary owner(s) always list first regardless of
     * insertion order.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listOwners(int $assetId): array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT o.*, '
            . '      u.fullName   AS userName, '
            . '      d.deptName   AS deptName, '
            . '      g.groupName  AS groupName, '
            . '      org.orgName  AS orgName '
            . 'FROM tblAssetOwners o '
            . 'LEFT JOIN tblUsers u      ON u.userID = o.userID '
            . 'LEFT JOIN tblDepts d      ON d.deptID = o.deptID '
            . 'LEFT JOIN tblGroups g     ON g.groupID = o.groupID '
            . 'LEFT JOIN tblAssetOrgs org ON org.orgID = o.orgID '
            . 'WHERE o.assetID = ? '
            . "ORDER BY FIELD(o.roleKind, 'owner', 'co-owner', 'custodian', 'stakeholder'), o.ownerID ASC"
        );
        if ($stmt === false) {
            error_log('AssetRegister::listOwners() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            // 🏷️ Resolve a single display name from whichever join matched
            // this row's partyType — see method doc for the fallback.
            $row['partyName'] = match ((string) $row['partyType']) {
                'user'  => $row['userName']  ?? '(deleted user)',
                'dept'  => $row['deptName']  ?? '(deleted department)',
                'group' => $row['groupName'] ?? '(deleted group)',
                'org'   => $row['orgName']   ?? '(deleted organisation)',
                default => 'Unknown party',
            };
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Confirm a party id genuinely exists AND (for site-scoped party types)
     * belongs to the current site, before addOwner() ever inserts a row
     * pointing at it. Mirrors save.php's own FK-existence pattern but lives
     * here because addOwner() owns its full validation contract — see this
     * section's header comment for why that's a deliberate deviation from
     * createAsset()/updateAsset()'s "caller validates" convention.
     *
     * tblGroups carries no siteID column (global reference data, like
     * tblRoles — see full_schema.sql) so a group is checked for existence
     * only, with no site filter; every other party type is scoped to
     * $siteId.
     */
    private static function partyExistsOnSite(string $partyType, int $partyId, int $siteId): bool
    {
        if ($partyId <= 0) {
            return false;
        }
        $db = App::db();

        switch ($partyType) {
            case 'user':
                // 👤 A user "exists on this site" via an active tblUserSites
                // row — mirrors _apps/leadership/assign.php's own picker
                // query.
                $stmt = $db->prepare(
                    'SELECT 1 FROM tblUsers u '
                    . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
                    . 'WHERE u.userID = ? AND u.isActive = 1 LIMIT 1'
                );
                if ($stmt === false) {
                    return false;
                }
                $stmt->bind_param('ii', $siteId, $partyId);
                break;

            case 'dept':
                $stmt = $db->prepare('SELECT 1 FROM tblDepts WHERE deptID = ? AND siteID = ? LIMIT 1');
                if ($stmt === false) {
                    return false;
                }
                $stmt->bind_param('ii', $partyId, $siteId);
                break;

            case 'group':
                // 🌐 Global — no siteID column on tblGroups, see doc above.
                $stmt = $db->prepare('SELECT 1 FROM tblGroups WHERE groupID = ? LIMIT 1');
                if ($stmt === false) {
                    return false;
                }
                $stmt->bind_param('i', $partyId);
                break;

            case 'org':
                $stmt = $db->prepare('SELECT 1 FROM tblAssetOrgs WHERE orgID = ? AND siteID = ? LIMIT 1');
                if ($stmt === false) {
                    return false;
                }
                $stmt->bind_param('ii', $partyId, $siteId);
                break;

            default:
                return false;
        }

        $stmt->execute();
        $hit = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();
        return $hit;
    }

    /**
     * Add an owner/custodian row for an asset. UNLIKE createAsset()/
     * updateAsset(), this method does NOT trust its caller's validation —
     * see this section's header comment. Every one of the following is
     * enforced here, and the row is rejected (return 0) if any fails:
     *
     *   - The asset exists and is on the current site.
     *   - partyType ∈ OWNER_PARTY_TYPES.
     *   - roleKind ∈ OWNER_ROLE_KINDS (falls back to 'owner' if omitted).
     *   - EXACTLY ONE of userID/deptID/groupID/orgID is a positive int, and
     *     it is the one matching $data['partyType'] — a dept id supplied
     *     while partyType=user (e.g. a tampered form) is rejected outright
     *     rather than silently accepted under the wrong party type.
     *   - That one party id actually exists and (where applicable) belongs
     *     to the current site (partyExistsOnSite() above).
     *   - sharePercent, when supplied, is within 0–100 (matches the
     *     DECIMAL(5,2) column's intended range — a fractional ownership
     *     share can never be negative or exceed 100%).
     *
     * $data keys: partyType, userID|deptID|groupID|orgID (only the one
     * matching partyType need be set — the others are ignored), roleKind,
     * sharePercent (float|null), isLendingAuthority (bool-ish),
     * isMaintenanceAuthority (bool-ish), notes.
     *
     * @param array<string, mixed> $data
     *
     * @return int New ownerID, or 0 on validation failure or insert failure
     */
    public static function addOwner(int $assetId, array $data, int $actorUserId): int
    {
        $db     = App::db();
        $siteId = Site::id();

        // 🔒 The asset itself must exist and be on this site — get() is
        // already site-scoped via Site::id() (see that method's doc).
        if (self::get($assetId) === null) {
            error_log('AssetRegister::addOwner() asset not found on this site: #' . $assetId);
            return 0;
        }

        $partyType = (string) ($data['partyType'] ?? '');
        if (in_array($partyType, self::OWNER_PARTY_TYPES, true) === false) {
            error_log('AssetRegister::addOwner() invalid partyType: ' . $partyType);
            return 0;
        }

        $roleKind = (string) ($data['roleKind'] ?? 'owner');
        if (in_array($roleKind, self::OWNER_ROLE_KINDS, true) === false) {
            error_log('AssetRegister::addOwner() invalid roleKind: ' . $roleKind);
            return 0;
        }

        // 🔀 EXACTLY ONE of the four party FKs — the core integrity rule
        // this table has no SQL constraint for (migration 159's table
        // comment). Coerce every candidate to int|null first (0/''/
        // non-numeric ⇒ null) so a stray empty string can never be mistaken
        // for "this id is set".
        $partyIds = [
            'user'  => (int) ($data['userID']  ?? 0),
            'dept'  => (int) ($data['deptID']  ?? 0),
            'group' => (int) ($data['groupID'] ?? 0),
            'org'   => (int) ($data['orgID']   ?? 0),
        ];
        foreach ($partyIds as $k => $v) {
            $partyIds[$k] = $v > 0 ? $v : null;
        }
        $suppliedCount = count(array_filter($partyIds, static fn (?int $v): bool => $v !== null));
        if ($suppliedCount !== 1) {
            error_log('AssetRegister::addOwner() expected exactly one party FK, got ' . $suppliedCount);
            return 0;
        }
        if ($partyIds[$partyType] === null) {
            error_log('AssetRegister::addOwner() the supplied party FK does not match partyType=' . $partyType);
            return 0;
        }
        $partyId = $partyIds[$partyType];

        // 🔍 FK existence + site-scope — never trust a bare posted int.
        if (self::partyExistsOnSite($partyType, $partyId, $siteId) === false) {
            error_log('AssetRegister::addOwner() party not found on this site: ' . $partyType . '#' . $partyId);
            return 0;
        }

        // 📐 sharePercent 0–100 or null.
        $sharePercent = null;
        if (isset($data['sharePercent']) === true && $data['sharePercent'] !== null && $data['sharePercent'] !== '') {
            $sharePercent = (float) $data['sharePercent'];
            if ($sharePercent < 0.0 || $sharePercent > 100.0) {
                error_log('AssetRegister::addOwner() sharePercent out of range: ' . $sharePercent);
                return 0;
            }
        }

        $isLendingAuthority     = ((bool) ($data['isLendingAuthority'] ?? false)) === true ? 1 : 0;
        $isMaintenanceAuthority = ((bool) ($data['isMaintenanceAuthority'] ?? false)) === true ? 1 : 0;

        $notes = trim((string) ($data['notes'] ?? ''));
        $notes = $notes !== '' ? mb_substr($notes, 0, 500) : null;

        $fields = [
            'siteID'                 => [$siteId, 'i'],
            'assetID'                => [$assetId, 'i'],
            'partyType'              => [$partyType, 's'],
            'userID'                 => [$partyIds['user'], 'i'],
            'deptID'                 => [$partyIds['dept'], 'i'],
            'groupID'                => [$partyIds['group'], 'i'],
            'orgID'                  => [$partyIds['org'], 'i'],
            'roleKind'               => [$roleKind, 's'],
            'sharePercent'           => [$sharePercent, 'd'],
            'isLendingAuthority'     => [$isLendingAuthority, 'i'],
            'isMaintenanceAuthority' => [$isMaintenanceAuthority, 'i'],
            'notes'                  => [$notes, 's'],
        ];

        ['columns' => $columns, 'types' => $types, 'params' => $params] = self::splitFields($fields);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $stmt = $db->prepare('INSERT INTO tblAssetOwners (`' . implode('`, `', $columns) . '`) VALUES (' . $placeholders . ')');
        if ($stmt === false) {
            error_log('AssetRegister::addOwner() prepare failed: ' . $db->error);
            return 0;
        }

        try {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            error_log('AssetRegister::addOwner() insert failed: ' . $e->getMessage());
            $stmt->close();
            return 0;
        }
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        if ($newId <= 0) {
            return 0;
        }

        $auditNew = [];
        foreach ($fields as $col => $pair) {
            $auditNew[$col] = $pair[0];
        }
        self::audit('owner', $newId, $assetId, 'create', null, $auditNew);

        return $newId;
    }

    /**
     * Remove an owner/custodian row. IDOR guard: the row must belong to
     * BOTH $assetId AND the current site before it's touched — a bare
     * ownerID from a tampered form is never trusted on its own (mirrors
     * resource-save.php's delete-branch "confirm it belongs to this asset
     * first" pattern).
     *
     * @return bool True if a row existed (for this asset, on this site)
     *              and was removed
     */
    public static function removeOwner(int $ownerId, int $assetId, int $actorUserId): bool
    {
        $db     = App::db();
        $siteId = Site::id();

        $stmt = $db->prepare('SELECT * FROM tblAssetOwners WHERE ownerID = ? AND assetID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iii', $ownerId, $assetId, $siteId);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($old === null || $old === false) {
            return false;
        }

        $delStmt = $db->prepare('DELETE FROM tblAssetOwners WHERE ownerID = ? AND assetID = ? AND siteID = ?');
        if ($delStmt === false) {
            return false;
        }
        $delStmt->bind_param('iii', $ownerId, $assetId, $siteId);
        $ok = $delStmt->execute();
        $affected = $delStmt->affected_rows;
        $delStmt->close();

        if ($ok === false || $affected <= 0) {
            return false;
        }

        self::audit('owner', $ownerId, $assetId, 'delete', $old, null);

        return true;
    }

    /**
     * Flip ONE of the two independent authority flags (isLendingAuthority /
     * isMaintenanceAuthority) on an existing owner row. Deliberately
     * separate from addOwner()/a hypothetical updateOwner() — the design
     * treats these two flags as independent of roleKind (e.g. an
     * organisation can be a 'co-owner' while a department is the
     * isLendingAuthority for the same asset) and independent of each
     * other, so a single-field toggle is the natural shape for the UI
     * (item.php renders one small button per flag, per row).
     *
     * $field is checked against OWNER_AUTHORITY_FIELDS BEFORE it is
     * interpolated into the UPDATE's SET clause — this is the one place in
     * this class a column NAME (not a bound value) is built from caller
     * input, and it is only ever safe because that input is first
     * constrained to a two-item closed allow-list; every VALUE in the same
     * query still travels via a bound parameter.
     *
     * Same IDOR guard as removeOwner() — the row must belong to $assetId on
     * the current site.
     *
     * @return bool True if the row existed (for this asset, on this site)
     *              and was updated
     */
    public static function setOwnerAuthority(int $ownerId, int $assetId, string $field, bool $value, int $actorUserId): bool
    {
        if (in_array($field, self::OWNER_AUTHORITY_FIELDS, true) === false) {
            error_log('AssetRegister::setOwnerAuthority() invalid field: ' . $field);
            return false;
        }

        $db     = App::db();
        $siteId = Site::id();

        $stmt = $db->prepare('SELECT * FROM tblAssetOwners WHERE ownerID = ? AND assetID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iii', $ownerId, $assetId, $siteId);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($old === null || $old === false) {
            return false;
        }

        $newVal = $value === true ? 1 : 0;
        $oldVal = (int) $old[$field];
        if ($oldVal === $newVal) {
            return true; // no-op — already at the requested state
        }

        $updStmt = $db->prepare('UPDATE tblAssetOwners SET `' . $field . '` = ? WHERE ownerID = ? AND assetID = ? AND siteID = ?');
        if ($updStmt === false) {
            return false;
        }
        $updStmt->bind_param('iiii', $newVal, $ownerId, $assetId, $siteId);
        $ok = $updStmt->execute();
        $updStmt->close();

        if ($ok === false) {
            return false;
        }

        self::audit('owner', $ownerId, $assetId, 'update', [$field => $oldVal], [$field => $newVal]);

        return true;
    }

    /**
     * Set (or clear, with an empty string) an asset's free-text ownership/
     * agreement terms (tblAssets.ownershipTerms — e.g. "loaned in from
     * Riverside Trust under a 12-month renewable agreement; see vault for
     * the signed copy"). Audited under entityType 'asset' (like
     * updateAsset()) since this touches a tblAssets column, not a
     * tblAssetOwners row.
     *
     * @return bool True on success, false if the asset doesn't exist / isn't
     *              on this site
     */
    public static function updateOwnershipTerms(int $assetId, string $terms, int $actorUserId): bool
    {
        $db     = App::db();
        $siteId = Site::id();

        $old = self::get($assetId);
        if ($old === null) {
            return false;
        }

        $terms = trim($terms);
        $termsOrNull = $terms !== '' ? $terms : null;

        $stmt = $db->prepare('UPDATE tblAssets SET ownershipTerms = ? WHERE assetID = ? AND siteID = ?');
        if ($stmt === false) {
            error_log('AssetRegister::updateOwnershipTerms() prepare failed: ' . $db->error);
            return false;
        }
        $stmt->bind_param('sii', $termsOrNull, $assetId, $siteId);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok === false) {
            return false;
        }

        self::audit(
            'asset',
            $assetId,
            $assetId,
            'update',
            ['ownershipTerms' => $old['ownershipTerms'] ?? null],
            ['ownershipTerms' => $termsOrNull]
        );

        return true;
    }

    /* ==========================================================================
     * 🏢 External organisations register (#396)
     * ------------------------------------------------------------------------
     * Site-wide reference data (hire companies, partner charities,
     * suppliers, …) that owner rows and loan counterparties can point at.
     * Like categories/locations above, this is NOT asset-scoped, so it logs
     * via a plain Logger::activity() call rather than self::audit() — same
     * rationale as that section's header comment.
     * ======================================================================== */

    /**
     * List a site's external organisations, optionally active-only.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listOrgs(int $siteId, bool $activeOnly = false): array
    {
        $db = App::db();
        $sql = 'SELECT * FROM tblAssetOrgs WHERE siteID = ?'
            . ($activeOnly === true ? ' AND isActive = 1' : '')
            . ' ORDER BY orgName ASC';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            error_log('AssetRegister::listOrgs() prepare failed: ' . $db->error);
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
     * Create or update an external organisation. $orgId = 0 creates a new
     * row; a positive id updates that row (scoped to $siteId). Mirrors
     * saveCategory()/saveLocation()'s shape and soft-validation style
     * (blank name ⇒ 0/failure; everything else is trimmed/length-capped
     * rather than hard-rejected) — the intended caller (`_apps/assets/
     * orgs.php`) passes $_POST straight through, same as
     * categories.php/locations.php do for their own save methods.
     *
     * $data keys: orgName (required), contactName, contactEmail (must be a
     * valid email or it is silently dropped to NULL — mirrors
     * salvation/card-save.php's own soft-validation convention for an
     * optional email field), contactPhone, agreementRef, notes.
     *
     * @param array<string, mixed> $data
     *
     * @return int The organisation's id (new or existing), or 0 on failure /
     *             validation error (blank name)
     */
    public static function saveOrg(int $siteId, int $orgId, array $data, int $actorUserId): int
    {
        $db   = App::db();
        $name = trim((string) ($data['orgName'] ?? ''));
        if ($name === '') {
            return 0;
        }
        $name = mb_substr($name, 0, 255);

        $contactName = trim((string) ($data['contactName'] ?? ''));
        $contactName = $contactName !== '' ? mb_substr($contactName, 0, 150) : null;

        $contactEmail = trim((string) ($data['contactEmail'] ?? ''));
        $contactEmail = ($contactEmail !== '' && filter_var($contactEmail, FILTER_VALIDATE_EMAIL) !== false)
            ? mb_substr($contactEmail, 0, 255)
            : null;

        $contactPhone = trim((string) ($data['contactPhone'] ?? ''));
        $contactPhone = $contactPhone !== '' ? mb_substr($contactPhone, 0, 50) : null;

        $agreementRef = trim((string) ($data['agreementRef'] ?? ''));
        $agreementRef = $agreementRef !== '' ? mb_substr($agreementRef, 0, 100) : null;

        $notes = trim((string) ($data['notes'] ?? ''));
        $notes = $notes !== '' ? $notes : null;

        try {
            if ($orgId > 0) {
                $stmt = $db->prepare(
                    'UPDATE tblAssetOrgs SET orgName = ?, contactName = ?, contactEmail = ?, contactPhone = ?, agreementRef = ?, notes = ? '
                    . 'WHERE orgID = ? AND siteID = ?'
                );
                if ($stmt === false) {
                    return 0;
                }
                $stmt->bind_param('ssssssii', $name, $contactName, $contactEmail, $contactPhone, $agreementRef, $notes, $orgId, $siteId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok === false) {
                    return 0;
                }
                Logger::activity('AssetOrgSaved', 'Updated asset organisation: ' . $name, $actorUserId);
                return $orgId;
            }

            $stmt = $db->prepare(
                'INSERT INTO tblAssetOrgs (siteID, orgName, contactName, contactEmail, contactPhone, agreementRef, notes) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            if ($stmt === false) {
                return 0;
            }
            $stmt->bind_param('issssss', $siteId, $name, $contactName, $contactEmail, $contactPhone, $agreementRef, $notes);
            $ok = $stmt->execute();
            $newId = (int) $stmt->insert_id;
            $stmt->close();
            if ($ok === false || $newId <= 0) {
                return 0;
            }
            Logger::activity('AssetOrgSaved', 'Created asset organisation: ' . $name, $actorUserId);
            return $newId;
        } catch (\mysqli_sql_exception $e) {
            error_log('AssetRegister::saveOrg() failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Flip an organisation's isActive flag. See toggleCategoryActive() —
     * same shape, same "stays selectable/visible on already-linked owner/
     * loan rows" rationale (an org going inactive only hides it from the
     * "add owner"/"add org" pickers going forward).
     */
    public static function toggleOrgActive(int $orgId, int $siteId, int $actorUserId): bool
    {
        $db = App::db();
        $stmt = $db->prepare('UPDATE tblAssetOrgs SET isActive = 1 - isActive WHERE orgID = ? AND siteID = ?');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $orgId, $siteId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($ok === true && $affected > 0) {
            Logger::activity('AssetOrgToggled', 'Toggled active state for asset organisation #' . $orgId, $actorUserId);
        }
        return $ok === true && $affected > 0;
    }

    /* ==========================================================================
     * 🔄 Loan register (#398) — lend & borrow, approval, condition in/out.
     * ------------------------------------------------------------------------
     * `tblAssetLoans` mutations DO route through self::audit() (entityType
     * 'loan') — see this class's header comment (point 5) for the full
     * design rationale: the lending-authority gate (canApproveLoan()), the
     * read helpers' isOverdue/counterpartyDisplayName computation, the
     * per-verb state-machine guards, and the transactional loan+asset pair
     * checkout()/checkin() each wrap.
     * ======================================================================== */

    /**
     * Does the given user (default: current session user) have LENDING
     * authority for this asset? True when EITHER:
     *   - The user is an admin or holds the asset_manager role, OR
     *   - The user is a direct `tblAssetOwners` party for this asset with
     *     `isLendingAuthority = 1`, or belongs to a dept/group that IS such
     *     a party (modelled on isResponsibleFor()'s own three joins above,
     *     narrowed to the lending-authority flag).
     *
     * UNLIKE isResponsibleFor(), this method folds the admin/manager bypass
     * in itself (per #398's spec) — but `App::isAdmin()`/`App::hasRole()`
     * are both session-bound (they read `App::user()`, which is keyed off
     * `$_SESSION['user_id']` — see App.php), so that bypass is applied ONLY
     * when `$userId` is null (defaults to the session user) or explicitly
     * equals the current session user. A caller that passes some OTHER
     * user's id — e.g. checking a different user's authority than whoever
     * is logged in right now — never gets an admin "yes" borrowed from the
     * CURRENT session; it only ever gets a "yes" from that other user's own
     * isLendingAuthority ownership rows, checked directly in the DB below.
     * Every controller in this pass only ever calls this with the actor's
     * own id (== the session user), so this distinction changes no
     * observable behaviour today — it exists so the method stays correct
     * if a future caller ever asks about someone else's authority.
     */
    public static function canApproveLoan(int $assetId, ?int $userId = null): bool
    {
        if ($assetId <= 0) {
            return false;
        }

        $sessionUserId = Auth::check() === true ? (int) ($_SESSION['user_id'] ?? 0) : 0;
        $checkingSessionUser = ($userId === null) || ($userId === $sessionUserId && $sessionUserId > 0);

        if ($userId === null) {
            $userId = $sessionUserId;
        }
        if ($userId <= 0) {
            return false;
        }

        // 🛡️ Admin / asset_manager bypass — session-user-only, see doc above.
        if ($checkingSessionUser === true
            && (App::isAdmin() === true || App::hasRole('asset_manager') === true)
        ) {
            return true;
        }

        $db = App::db();

        // 👤 Direct lending-authority ownership row.
        $stmt = $db->prepare(
            'SELECT 1 FROM tblAssetOwners '
            . 'WHERE assetID = ? AND partyType = "user" AND userID = ? AND isLendingAuthority = 1 LIMIT 1'
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

        // 🏢 Department lending authority — the asset's lending authority is
        //    a dept this user belongs to (tblUserDepts), mirrors
        //    isResponsibleFor()'s dept join.
        $stmt = $db->prepare(
            'SELECT 1 FROM tblAssetOwners o '
            . 'JOIN tblUserDepts ud ON ud.deptID = o.deptID '
            . 'WHERE o.assetID = ? AND o.partyType = "dept" AND o.isLendingAuthority = 1 AND ud.userID = ? LIMIT 1'
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

        // 👥 Group lending authority — mirrors isResponsibleFor()'s group join.
        $stmt = $db->prepare(
            'SELECT 1 FROM tblAssetOwners o '
            . 'JOIN tblUserGroups ug ON ug.groupID = o.groupID '
            . 'WHERE o.assetID = ? AND o.partyType = "group" AND o.isLendingAuthority = 1 AND ug.userID = ? LIMIT 1'
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

    /**
     * List loans for a site — both directions, every status by default.
     * Resolves a human `counterpartyDisplayName` (the counterparty user's
     * name / org's name / the free-text name, depending on
     * `counterpartyType`) and computes `isOverdue` (status='active' AND
     * dueDate is in the past) in PHP once per row, right after the fetch,
     * rather than duplicating a `CURDATE()` condition across every caller.
     * Newest-created first (ordered by `loanID DESC` — tblAssetLoans has no
     * `createdAt` column, so the auto-increment PK is the closest available
     * proxy for insertion order, same convention `listOwners()` uses for
     * per-role ordering with no timestamp of its own to sort by).
     *
     * Recognised $filters keys (all optional): 'assetID' (int),
     * 'direction' ('out'|'in'), 'status' (one of LOAN_STATUSES),
     * 'overdueOnly' (bool — when true, forces status='active' AND an
     * elapsed dueDate regardless of any 'status' filter also supplied).
     *
     * @param array{assetID?: int, direction?: string, status?: string, overdueOnly?: bool} $filters
     * @param bool $includeConfidential Mirrors listForSite()'s own param —
     *             false (default) excludes loans belonging to a
     *             confidential asset; pass true only for a caller that has
     *             already verified the viewer may see confidential assets
     *             (e.g. _apps/assets/loans.php's $canManage check).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listLoans(int $siteId, array $filters = [], bool $includeConfidential = false): array
    {
        $db = App::db();

        $where  = ['l.siteID = ?'];
        $types  = 'i';
        $params = [$siteId];

        if ($includeConfidential === false) {
            $where[] = 'a.isConfidential = 0';
        }
        if (isset($filters['assetID']) === true && (int) $filters['assetID'] > 0) {
            $where[]  = 'l.assetID = ?';
            $types   .= 'i';
            $params[] = (int) $filters['assetID'];
        }
        if (isset($filters['direction']) === true && in_array((string) $filters['direction'], self::LOAN_DIRECTIONS, true) === true) {
            $where[]  = 'l.direction = ?';
            $types   .= 's';
            $params[] = (string) $filters['direction'];
        }
        if (isset($filters['status']) === true && in_array((string) $filters['status'], self::LOAN_STATUSES, true) === true) {
            $where[]  = 'l.status = ?';
            $types   .= 's';
            $params[] = (string) $filters['status'];
        }
        if ((bool) ($filters['overdueOnly'] ?? false) === true) {
            // 🕒 Literal condition, no bound parameter needed — CURDATE() is
            // a constant expression, not user input. Deliberately ANDed on
            // top of whatever 'status' filter was also supplied above
            // (e.g. status=returned + overdueOnly=true legitimately yields
            // zero rows rather than silently overriding one or the other).
            $where[] = "l.status = 'active' AND l.dueDate IS NOT NULL AND l.dueDate < CURDATE()";
        }

        $sql = 'SELECT l.*, a.name AS assetName, a.assetTagCode, a.isConfidential, '
             . '       u.fullName AS counterpartyUserName, org.orgName AS counterpartyOrgName, '
             . '       ru.fullName AS requestedByName, au.fullName AS approvedByName '
             . 'FROM tblAssetLoans l '
             . 'JOIN tblAssets a ON a.assetID = l.assetID '
             . 'LEFT JOIN tblUsers u ON u.userID = l.counterpartyUserID '
             . 'LEFT JOIN tblAssetOrgs org ON org.orgID = l.counterpartyOrgID '
             . 'LEFT JOIN tblUsers ru ON ru.userID = l.requestedByID '
             . 'LEFT JOIN tblUsers au ON au.userID = l.approvedByID '
             . 'WHERE ' . implode(' AND ', $where) . ' '
             . 'ORDER BY l.loanID DESC';

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            error_log('AssetRegister::listLoans() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        $today = date('Y-m-d');
        while ($row = $result->fetch_assoc()) {
            $row['counterpartyDisplayName'] = match ((string) $row['counterpartyType']) {
                'user'  => $row['counterpartyUserName'] ?? '(deleted user)',
                'org'   => $row['counterpartyOrgName']  ?? '(deleted organisation)',
                'other' => ($row['counterpartyName'] !== null && (string) $row['counterpartyName'] !== '')
                    ? (string) $row['counterpartyName']
                    : '(unspecified)',
                default => 'Unknown',
            };
            $row['isOverdue'] = (string) $row['status'] === 'active'
                && $row['dueDate'] !== null
                && (string) $row['dueDate'] < $today;
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * List a single asset's full loan history (every status), newest
     * first — thin wrapper over listLoans() scoped to one assetID. Always
     * includes confidential-asset loans regardless of the caller's own
     * privilege, because the CALLER (item.php) has already gated access to
     * the asset itself before ever reaching this point — there is nothing
     * left to additionally restrict at the loan-row level for a single,
     * already-authorised asset.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listLoansForAsset(int $assetId): array
    {
        return self::listLoans(Site::id(), ['assetID' => $assetId], true);
    }

    /**
     * Create a new loan REQUEST. Any logged-in user may call this — the
     * gated step is approval (canApproveLoan()), not the request itself,
     * per #398's spec. Always inserts status='requested' and NEVER touches
     * `tblAssets.status` — an asset only becomes on-loan/borrowed once
     * loanAction()'s `checkout` verb actually hands it over.
     *
     * Validates:
     *   - The asset exists and is on this site (self::get()).
     *   - direction ∈ LOAN_DIRECTIONS.
     *   - counterpartyType ∈ LOAN_COUNTERPARTY_TYPES, with the ONE matching
     *     field present: counterpartyUserID must exist on this site
     *     (partyExistsOnSite('user', …), reused unchanged from #396) /
     *     counterpartyOrgID must exist on this site (partyExistsOnSite
     *     ('org', …)) / counterpartyName must be non-empty free text.
     *   - dueDate, when supplied, is a real Y-m-d date.
     *   - conditionOut, when supplied, is one of CONDITION_STATES.
     *   - This asset has no other unresolved loan already in flight
     *     (status ∈ LOAN_OPEN_STATUSES) — prevents two people
     *     requesting/holding the same physical item at once and prevents a
     *     later checkout() from clobbering tblAssets.status while an
     *     earlier loan on the same asset is still active.
     *
     * $data keys: direction, counterpartyType, counterpartyUserID|
     * counterpartyOrgID|counterpartyName (only the one matching
     * counterpartyType need be set), counterpartyContact, dueDate
     * (Y-m-d|''), conditionOut (one of CONDITION_STATES|''), notes.
     *
     * @param array<string, mixed> $data
     *
     * @return int New loanID, or 0 on validation failure or insert failure
     */
    public static function createLoanRequest(int $assetId, array $data, int $actorUserId): int
    {
        $db     = App::db();
        $siteId = Site::id();

        if (self::get($assetId) === null) {
            error_log('AssetRegister::createLoanRequest() asset not found on this site: #' . $assetId);
            return 0;
        }

        $direction = (string) ($data['direction'] ?? '');
        if (in_array($direction, self::LOAN_DIRECTIONS, true) === false) {
            error_log('AssetRegister::createLoanRequest() invalid direction: ' . $direction);
            return 0;
        }

        $counterpartyType = (string) ($data['counterpartyType'] ?? '');
        if (in_array($counterpartyType, self::LOAN_COUNTERPARTY_TYPES, true) === false) {
            error_log('AssetRegister::createLoanRequest() invalid counterpartyType: ' . $counterpartyType);
            return 0;
        }

        // 🔀 Exactly the ONE field matching counterpartyType is populated —
        // mirrors addOwner()'s exactly-one-party-FK re-validation above
        // (this table has the analogous "no SQL constraint enforces it"
        // shape — see migration 159's tblAssetLoans column comments).
        $counterpartyUserId = null;
        $counterpartyOrgId  = null;
        $counterpartyName   = null;
        switch ($counterpartyType) {
            case 'user':
                $counterpartyUserId = (int) ($data['counterpartyUserID'] ?? 0);
                if ($counterpartyUserId <= 0 || self::partyExistsOnSite('user', $counterpartyUserId, $siteId) === false) {
                    error_log('AssetRegister::createLoanRequest() counterparty user not found on this site: #' . $counterpartyUserId);
                    return 0;
                }
                break;

            case 'org':
                $counterpartyOrgId = (int) ($data['counterpartyOrgID'] ?? 0);
                if ($counterpartyOrgId <= 0 || self::partyExistsOnSite('org', $counterpartyOrgId, $siteId) === false) {
                    error_log('AssetRegister::createLoanRequest() counterparty org not found on this site: #' . $counterpartyOrgId);
                    return 0;
                }
                break;

            case 'other':
                $counterpartyName = trim((string) ($data['counterpartyName'] ?? ''));
                if ($counterpartyName === '') {
                    error_log('AssetRegister::createLoanRequest() counterpartyName required for counterpartyType=other');
                    return 0;
                }
                $counterpartyName = mb_substr($counterpartyName, 0, 255);
                break;
        }

        $counterpartyContact = trim((string) ($data['counterpartyContact'] ?? ''));
        $counterpartyContact = $counterpartyContact !== '' ? mb_substr($counterpartyContact, 0, 255) : null;

        // 📅 dueDate — optional, must parse as a real Y-m-d calendar date
        // (createFromFormat + round-trip re-format catches e.g. '2025-02-30').
        $dueDate = null;
        $dueDateRaw = trim((string) ($data['dueDate'] ?? ''));
        if ($dueDateRaw !== '') {
            $parsed = \DateTime::createFromFormat('Y-m-d', $dueDateRaw);
            if ($parsed === false || $parsed->format('Y-m-d') !== $dueDateRaw) {
                error_log('AssetRegister::createLoanRequest() invalid dueDate: ' . $dueDateRaw);
                return 0;
            }
            $dueDate = $dueDateRaw;
        }

        // 🎨 conditionOut — optional, must be a recognised condition value.
        $conditionOut = null;
        $conditionOutRaw = trim((string) ($data['conditionOut'] ?? ''));
        if ($conditionOutRaw !== '') {
            if (in_array($conditionOutRaw, self::CONDITION_STATES, true) === false) {
                error_log('AssetRegister::createLoanRequest() invalid conditionOut: ' . $conditionOutRaw);
                return 0;
            }
            $conditionOut = $conditionOutRaw;
        }

        $notes = trim((string) ($data['notes'] ?? ''));
        $notes = $notes !== '' ? $notes : null;

        // 🚦 One unresolved loan at a time per asset — see method doc.
        $openPlaceholders = implode(', ', array_fill(0, count(self::LOAN_OPEN_STATUSES), '?'));
        $openStmt = $db->prepare(
            'SELECT 1 FROM tblAssetLoans WHERE assetID = ? AND siteID = ? AND status IN (' . $openPlaceholders . ') LIMIT 1'
        );
        if ($openStmt !== false) {
            $openTypes = 'ii' . str_repeat('s', count(self::LOAN_OPEN_STATUSES));
            $openStmt->bind_param($openTypes, $assetId, $siteId, ...self::LOAN_OPEN_STATUSES);
            $openStmt->execute();
            $hasOpenLoan = $openStmt->get_result()->fetch_assoc() !== null;
            $openStmt->close();
            if ($hasOpenLoan === true) {
                error_log('AssetRegister::createLoanRequest() asset already has an unresolved loan: #' . $assetId);
                return 0;
            }
        }

        $fields = [
            'siteID'               => [$siteId, 'i'],
            'assetID'              => [$assetId, 'i'],
            'direction'            => [$direction, 's'],
            'counterpartyType'     => [$counterpartyType, 's'],
            'counterpartyUserID'   => [$counterpartyUserId, 'i'],
            'counterpartyOrgID'    => [$counterpartyOrgId, 'i'],
            'counterpartyName'     => [$counterpartyName, 's'],
            'counterpartyContact'  => [$counterpartyContact, 's'],
            'status'               => ['requested', 's'],
            'conditionOut'         => [$conditionOut, 's'],
            'dueDate'              => [$dueDate, 's'],
            'requestedByID'        => [$actorUserId, 'i'],
            'notes'                => [$notes, 's'],
        ];
        ['columns' => $columns, 'types' => $types, 'params' => $params] = self::splitFields($fields);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $stmt = $db->prepare('INSERT INTO tblAssetLoans (`' . implode('`, `', $columns) . '`) VALUES (' . $placeholders . ')');
        if ($stmt === false) {
            error_log('AssetRegister::createLoanRequest() prepare failed: ' . $db->error);
            return 0;
        }

        try {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            error_log('AssetRegister::createLoanRequest() insert failed: ' . $e->getMessage());
            $stmt->close();
            return 0;
        }
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        if ($newId <= 0) {
            return 0;
        }

        $auditNew = [];
        foreach ($fields as $col => $pair) {
            $auditNew[$col] = $pair[0];
        }
        self::audit('loan', $newId, $assetId, 'create', null, $auditNew);

        return $newId;
    }

    /**
     * Dispatch a loan state-change action. ALWAYS re-loads the loan row
     * scoped to BOTH $loanId AND $assetId AND the current site FIRST (the
     * IDOR guard every per-verb helper below relies on — none of them
     * re-checks siteID/assetID themselves, because this method already
     * guarantees the row it hands them belongs to this asset on this
     * site). `$actorUserId`'s authority (canApproveLoan() / "is this the
     * original requester") is computed once, here, from the freshly-loaded
     * row, and passed down — the private per-verb helpers never re-derive
     * it, so there is exactly one place this gate can be gotten wrong.
     *
     * Recognised $action values: approve | decline | checkout | checkin |
     * cancel. An unrecognised value is rejected with ok=false rather than
     * silently no-op'ing.
     *
     * $data keys used, depending on $action: declineReason (decline),
     * conditionOut/conditionOutNotes (checkout — optional, confirms/
     * overrides what was recorded at request time), conditionIn/
     * conditionInNotes (checkin — conditionIn is REQUIRED).
     *
     * @param array<string, mixed> $data
     *
     * @return array{ok: bool, msg: string}
     */
    public static function loanAction(int $loanId, int $assetId, string $action, array $data, int $actorUserId): array
    {
        $db     = App::db();
        $siteId = Site::id();

        // 🔒 IDOR guard FIRST — before any authority check or mutation. See
        // method doc — every private helper below trusts this scoping.
        $stmt = $db->prepare('SELECT * FROM tblAssetLoans WHERE loanID = ? AND assetID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            error_log('AssetRegister::loanAction() prepare failed: ' . $db->error);
            return ['ok' => false, 'msg' => 'Could not load the loan — please try again.'];
        }
        $stmt->bind_param('iii', $loanId, $assetId, $siteId);
        $stmt->execute();
        $loan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($loan === null || $loan === false) {
            return ['ok' => false, 'msg' => 'Loan not found.'];
        }

        $isRequester = (int) $loan['requestedByID'] === $actorUserId;
        $canApprove  = self::canApproveLoan($assetId, $actorUserId);

        return match ($action) {
            'approve'  => self::loanApprove($loan, $canApprove, $actorUserId),
            'decline'  => self::loanDecline($loan, $data, $canApprove, $actorUserId),
            'checkout' => self::loanCheckout($loan, $data, $canApprove, $actorUserId),
            'checkin'  => self::loanCheckin($loan, $data, $canApprove, $isRequester),
            'cancel'   => self::loanCancel($loan, $canApprove, $isRequester),
            default    => ['ok' => false, 'msg' => 'Unrecognised loan action.'],
        };
    }

    /**
     * `requested` → `approved`. Requires canApproveLoan(). The `WHERE …
     * AND status = 'requested'` clause on the UPDATE is the state-machine
     * enforcement itself (not just the earlier read-time check) — if the
     * status moved between loanAction()'s read and this write (a race), 0
     * rows are affected and this correctly reports failure rather than
     * silently approving a loan that's no longer 'requested'.
     *
     * @param array<string, mixed> $loan Freshly-loaded, IDOR-checked row
     *
     * @return array{ok: bool, msg: string}
     */
    private static function loanApprove(array $loan, bool $canApprove, int $actorUserId): array
    {
        if ($canApprove === false) {
            return ['ok' => false, 'msg' => 'You do not have lending authority for this asset.'];
        }
        if ((string) $loan['status'] !== 'requested') {
            return ['ok' => false, 'msg' => 'Only a requested loan can be approved.'];
        }

        $db      = App::db();
        $loanId  = (int) $loan['loanID'];
        $assetId = (int) $loan['assetID'];
        $siteId  = (int) $loan['siteID'];

        $stmt = $db->prepare(
            "UPDATE tblAssetLoans SET status = 'approved', approvedByID = ?, approvedAt = NOW() "
            . " WHERE loanID = ? AND assetID = ? AND siteID = ? AND status = 'requested'"
        );
        if ($stmt === false) {
            error_log('AssetRegister::loanApprove() prepare failed: ' . $db->error);
            return ['ok' => false, 'msg' => 'Could not approve the loan — please try again.'];
        }
        $stmt->bind_param('iiii', $actorUserId, $loanId, $assetId, $siteId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($ok === false || $affected <= 0) {
            return ['ok' => false, 'msg' => 'Could not approve the loan — it may have already changed state.'];
        }

        self::audit(
            'loan',
            $loanId,
            $assetId,
            'approve',
            ['status' => 'requested'],
            ['status' => 'approved', 'approvedByID' => $actorUserId]
        );

        return ['ok' => true, 'msg' => 'Loan approved.'];
    }

    /**
     * `requested` → `declined`. Requires canApproveLoan(). Same
     * WHERE-guarded-UPDATE state-machine enforcement as loanApprove().
     *
     * @param array<string, mixed> $loan Freshly-loaded, IDOR-checked row
     * @param array<string, mixed> $data 'declineReason' (optional, ≤500 chars)
     *
     * @return array{ok: bool, msg: string}
     */
    private static function loanDecline(array $loan, array $data, bool $canApprove, int $actorUserId): array
    {
        if ($canApprove === false) {
            return ['ok' => false, 'msg' => 'You do not have lending authority for this asset.'];
        }
        if ((string) $loan['status'] !== 'requested') {
            return ['ok' => false, 'msg' => 'Only a requested loan can be declined.'];
        }

        $reason = trim((string) ($data['declineReason'] ?? ''));
        $reason = $reason !== '' ? mb_substr($reason, 0, 500) : null;

        $db      = App::db();
        $loanId  = (int) $loan['loanID'];
        $assetId = (int) $loan['assetID'];
        $siteId  = (int) $loan['siteID'];

        $stmt = $db->prepare(
            "UPDATE tblAssetLoans SET status = 'declined', approvedByID = ?, approvedAt = NOW(), declineReason = ? "
            . " WHERE loanID = ? AND assetID = ? AND siteID = ? AND status = 'requested'"
        );
        if ($stmt === false) {
            error_log('AssetRegister::loanDecline() prepare failed: ' . $db->error);
            return ['ok' => false, 'msg' => 'Could not decline the loan — please try again.'];
        }
        $stmt->bind_param('isiii', $actorUserId, $reason, $loanId, $assetId, $siteId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($ok === false || $affected <= 0) {
            return ['ok' => false, 'msg' => 'Could not decline the loan — it may have already changed state.'];
        }

        self::audit(
            'loan',
            $loanId,
            $assetId,
            'decline',
            ['status' => 'requested'],
            ['status' => 'declined', 'declineReason' => $reason]
        );

        return ['ok' => true, 'msg' => 'Loan declined.'];
    }

    /**
     * `requested`|`approved` → `active` — the physical (or digital) hand-
     * over. Requires canApproveLoan() (an approver may check a loan out
     * directly from 'requested', skipping a separate approve step — the
     * approvedByID/approvedAt columns are still backfilled via COALESCE so
     * the record always shows who authorised it either way). Sets
     * `dateOut = NOW()` and captures/confirms conditionOut(+Notes) at the
     * point of hand-over — a caller may leave these blank to keep whatever
     * was recorded at request time, or supply new values to override them.
     *
     * TRANSACTIONAL: the loan-row UPDATE and the `tblAssets.status` UPDATE
     * (→ 'on-loan' for direction='out', 'borrowed' for direction='in') are
     * one atomic unit via App::beginTransaction()/commit()/rollback() — see
     * this class's header comment (point 5) for why a mid-way failure must
     * never leave the loan and the asset's own status column disagreeing.
     *
     * @param array<string, mixed> $loan Freshly-loaded, IDOR-checked row
     * @param array<string, mixed> $data 'conditionOut'/'conditionOutNotes' (both optional)
     *
     * @return array{ok: bool, msg: string}
     */
    private static function loanCheckout(array $loan, array $data, bool $canApprove, int $actorUserId): array
    {
        if ($canApprove === false) {
            return ['ok' => false, 'msg' => 'You do not have lending authority for this asset.'];
        }
        $currentStatus = (string) $loan['status'];
        if (in_array($currentStatus, ['requested', 'approved'], true) === false) {
            return ['ok' => false, 'msg' => 'Only a requested or approved loan can be checked out.'];
        }

        $loanId    = (int) $loan['loanID'];
        $assetId   = (int) $loan['assetID'];
        $siteId    = (int) $loan['siteID'];
        $direction = (string) $loan['direction'];

        // 🎨 conditionOut/Notes — confirm-or-override at hand-over; falls
        // back to whatever was recorded at request time (may be null) when
        // the checkout form leaves these blank.
        $conditionOut = $loan['conditionOut'];
        $conditionOutRaw = trim((string) ($data['conditionOut'] ?? ''));
        if ($conditionOutRaw !== '') {
            if (in_array($conditionOutRaw, self::CONDITION_STATES, true) === false) {
                return ['ok' => false, 'msg' => 'Invalid condition value.'];
            }
            $conditionOut = $conditionOutRaw;
        }
        $conditionOutNotesRaw = trim((string) ($data['conditionOutNotes'] ?? ''));
        $conditionOutNotes = $conditionOutNotesRaw !== ''
            ? mb_substr($conditionOutNotesRaw, 0, 500)
            : ($loan['conditionOutNotes'] ?? null);

        $newAssetStatus = $direction === 'out' ? 'on-loan' : 'borrowed';

        $db = App::db();
        App::beginTransaction();
        try {
            // approvedByID/approvedAt are backfilled via COALESCE so a
            // direct requested→active checkout (the approver skipping the
            // separate 'approve' step) still records who authorised it.
            $stmt = $db->prepare(
                "UPDATE tblAssetLoans SET status = 'active', dateOut = NOW(), "
                . 'conditionOut = ?, conditionOutNotes = ?, '
                . 'approvedByID = COALESCE(approvedByID, ?), approvedAt = COALESCE(approvedAt, NOW()) '
                . " WHERE loanID = ? AND assetID = ? AND siteID = ? AND status IN ('requested', 'approved')"
            );
            if ($stmt === false) {
                throw new \RuntimeException('Failed to prepare loan checkout: ' . $db->error);
            }
            $stmt->bind_param('ssiiii', $conditionOut, $conditionOutNotes, $actorUserId, $loanId, $assetId, $siteId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected <= 0) {
                // 🏁 Race — status moved since loanAction()'s read. Thrown
                // here so the catch block below rolls back cleanly and
                // reports the same friendly message as every other guard.
                throw new \RuntimeException('Loan row did not update — status already changed');
            }

            $assetStmt = $db->prepare('UPDATE tblAssets SET status = ? WHERE assetID = ? AND siteID = ?');
            if ($assetStmt === false) {
                throw new \RuntimeException('Failed to prepare asset status update: ' . $db->error);
            }
            $assetStmt->bind_param('sii', $newAssetStatus, $assetId, $siteId);
            $assetStmt->execute();
            $assetStmt->close();

            App::commit();
        } catch (\Throwable $e) {
            App::rollback();
            error_log('AssetRegister::loanCheckout() failed: ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'Could not check out this loan — it may have already changed state.'];
        }

        self::audit(
            'loan',
            $loanId,
            $assetId,
            'checkout',
            ['status' => $currentStatus],
            ['status' => 'active', 'conditionOut' => $conditionOut, 'assetStatus' => $newAssetStatus]
        );

        return ['ok' => true, 'msg' => 'Loan checked out — condition and dates recorded.'];
    }

    /**
     * `active` → `returned` — the item comes back. Requires canApproveLoan()
     * OR the ORIGINAL requester (the person who took the item out is
     * allowed to record its return themselves, without needing a manager
     * to do it for them — an approver can still do it too). conditionIn is
     * REQUIRED (unlike conditionOut, which is optional throughout the rest
     * of the lifecycle) — the point of check-in is precisely to capture the
     * item's condition on return, so this is the one place that value isn't
     * allowed to be silently skipped.
     *
     * TRANSACTIONAL, same shape as loanCheckout(): the loan-row UPDATE and
     * the `tblAssets` UPDATE — status → 'in-service' AND conditionState ←
     * conditionIn (the returned item's condition becomes the asset's
     * current recorded condition) — are one atomic unit.
     *
     * @param array<string, mixed> $loan Freshly-loaded, IDOR-checked row
     * @param array<string, mixed> $data 'conditionIn' (required), 'conditionInNotes' (optional)
     *
     * @return array{ok: bool, msg: string}
     */
    private static function loanCheckin(array $loan, array $data, bool $canApprove, bool $isRequester): array
    {
        if ($canApprove === false && $isRequester === false) {
            return ['ok' => false, 'msg' => 'Only the original requester or someone with lending authority can check this loan in.'];
        }
        if ((string) $loan['status'] !== 'active') {
            return ['ok' => false, 'msg' => 'Only an active loan can be checked in.'];
        }

        $conditionInRaw = trim((string) ($data['conditionIn'] ?? ''));
        if ($conditionInRaw === '' || in_array($conditionInRaw, self::CONDITION_STATES, true) === false) {
            return ['ok' => false, 'msg' => "Please record the item's condition on return."];
        }
        $conditionIn = $conditionInRaw;

        $conditionInNotesRaw = trim((string) ($data['conditionInNotes'] ?? ''));
        $conditionInNotes = $conditionInNotesRaw !== '' ? mb_substr($conditionInNotesRaw, 0, 500) : null;

        $loanId  = (int) $loan['loanID'];
        $assetId = (int) $loan['assetID'];
        $siteId  = (int) $loan['siteID'];

        $db = App::db();
        App::beginTransaction();
        try {
            $stmt = $db->prepare(
                "UPDATE tblAssetLoans SET status = 'returned', dateIn = NOW(), conditionIn = ?, conditionInNotes = ? "
                . " WHERE loanID = ? AND assetID = ? AND siteID = ? AND status = 'active'"
            );
            if ($stmt === false) {
                throw new \RuntimeException('Failed to prepare loan checkin: ' . $db->error);
            }
            $stmt->bind_param('ssiii', $conditionIn, $conditionInNotes, $loanId, $assetId, $siteId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected <= 0) {
                throw new \RuntimeException('Loan row did not update — status already changed');
            }

            $assetStmt = $db->prepare('UPDATE tblAssets SET status = ?, conditionState = ? WHERE assetID = ? AND siteID = ?');
            if ($assetStmt === false) {
                throw new \RuntimeException('Failed to prepare asset status update: ' . $db->error);
            }
            $inService = 'in-service';
            $assetStmt->bind_param('ssii', $inService, $conditionIn, $assetId, $siteId);
            $assetStmt->execute();
            $assetStmt->close();

            App::commit();
        } catch (\Throwable $e) {
            App::rollback();
            error_log('AssetRegister::loanCheckin() failed: ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'Could not check in this loan — it may have already changed state.'];
        }

        self::audit(
            'loan',
            $loanId,
            $assetId,
            'checkin',
            ['status' => 'active'],
            ['status' => 'returned', 'conditionIn' => $conditionIn, 'assetStatus' => 'in-service']
        );

        return ['ok' => true, 'msg' => 'Loan checked in — asset marked in-service.'];
    }

    /**
     * `requested`|`approved` → `cancelled`. Allowed for the ORIGINAL
     * requester (they may withdraw their own request/approved-but-not-yet-
     * collected loan) OR canApproveLoan(). An `active` loan can NEVER be
     * cancelled — once the item has actually changed hands, the only way
     * back is `checkin` (a real return), not a cancellation that would
     * otherwise leave `tblAssets.status` untouched while the loan record
     * silently vanished from the open list.
     *
     * @param array<string, mixed> $loan Freshly-loaded, IDOR-checked row
     *
     * @return array{ok: bool, msg: string}
     */
    private static function loanCancel(array $loan, bool $canApprove, bool $isRequester): array
    {
        if ($canApprove === false && $isRequester === false) {
            return ['ok' => false, 'msg' => 'Only the requester or someone with lending authority can cancel this loan.'];
        }
        $currentStatus = (string) $loan['status'];
        if (in_array($currentStatus, ['requested', 'approved'], true) === false) {
            return ['ok' => false, 'msg' => 'Only a requested or approved loan can be cancelled.'];
        }

        $db      = App::db();
        $loanId  = (int) $loan['loanID'];
        $assetId = (int) $loan['assetID'];
        $siteId  = (int) $loan['siteID'];

        $stmt = $db->prepare(
            "UPDATE tblAssetLoans SET status = 'cancelled' "
            . " WHERE loanID = ? AND assetID = ? AND siteID = ? AND status IN ('requested', 'approved')"
        );
        if ($stmt === false) {
            error_log('AssetRegister::loanCancel() prepare failed: ' . $db->error);
            return ['ok' => false, 'msg' => 'Could not cancel the loan — please try again.'];
        }
        $stmt->bind_param('iii', $loanId, $assetId, $siteId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($ok === false || $affected <= 0) {
            return ['ok' => false, 'msg' => 'Could not cancel the loan — it may have already changed state.'];
        }

        self::audit('loan', $loanId, $assetId, 'cancel', ['status' => $currentStatus], ['status' => 'cancelled']);

        return ['ok' => true, 'msg' => 'Loan cancelled.'];
    }

    /* ==========================================================================
     * 🔧 Maintenance log + 💷 depreciation (#399)
     * ------------------------------------------------------------------------
     * `tblAssetMaintenance` mutations DO route through self::audit()
     * (entityType 'maintenance', already wired into TABLE_FOR_ENTITY since
     * the #395 audit choke-point pass). See this class's header comment
     * (point 6) for the full design rationale — canManageMaintenance()'s
     * exact mirror of canApproveLoan(), the read helpers'
     * performedByDisplay/isOverdue/isUpcoming computation, and
     * computeStraightLineValue()'s pure-function contract.
     * ======================================================================== */

    /**
     * Does the given user (default: current session user) have MAINTENANCE
     * authority for this asset? EXACT mirror of {@see canApproveLoan()} —
     * see that method's own doc for the full rationale behind the
     * session-scoped admin/asset_manager bypass and why `$userId` is
     * treated the way it is; every line below is identical to that method
     * except the ownership flag it checks. True when EITHER:
     *   - The user is an admin or holds the asset_manager role, OR
     *   - The user is a direct `tblAssetOwners` party for this asset with
     *     `isMaintenanceAuthority = 1`, or belongs to a dept/group that IS
     *     such a party.
     */
    public static function canManageMaintenance(int $assetId, ?int $userId = null): bool
    {
        if ($assetId <= 0) {
            return false;
        }

        $sessionUserId = Auth::check() === true ? (int) ($_SESSION['user_id'] ?? 0) : 0;
        $checkingSessionUser = ($userId === null) || ($userId === $sessionUserId && $sessionUserId > 0);

        if ($userId === null) {
            $userId = $sessionUserId;
        }
        if ($userId <= 0) {
            return false;
        }

        // 🛡️ Admin / asset_manager bypass — session-user-only, see
        // canApproveLoan()'s doc for why.
        if ($checkingSessionUser === true
            && (App::isAdmin() === true || App::hasRole('asset_manager') === true)
        ) {
            return true;
        }

        $db = App::db();

        // 👤 Direct maintenance-authority ownership row.
        $stmt = $db->prepare(
            'SELECT 1 FROM tblAssetOwners '
            . 'WHERE assetID = ? AND partyType = "user" AND userID = ? AND isMaintenanceAuthority = 1 LIMIT 1'
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

        // 🏢 Department maintenance authority — mirrors canApproveLoan()'s
        //    dept join, narrowed to isMaintenanceAuthority.
        $stmt = $db->prepare(
            'SELECT 1 FROM tblAssetOwners o '
            . 'JOIN tblUserDepts ud ON ud.deptID = o.deptID '
            . 'WHERE o.assetID = ? AND o.partyType = "dept" AND o.isMaintenanceAuthority = 1 AND ud.userID = ? LIMIT 1'
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

        // 👥 Group maintenance authority — mirrors canApproveLoan()'s group join.
        $stmt = $db->prepare(
            'SELECT 1 FROM tblAssetOwners o '
            . 'JOIN tblUserGroups ug ON ug.groupID = o.groupID '
            . 'WHERE o.assetID = ? AND o.partyType = "group" AND o.isMaintenanceAuthority = 1 AND ug.userID = ? LIMIT 1'
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

    /**
     * List an asset's maintenance/service history, newest performed (or,
     * for a scheduled entry with no performedAt yet, newest created) first.
     * Resolves a single `performedByDisplay` name — the linked portal
     * user's name when `performedByUserID` is set, else the free-text
     * `performedByName`, else null (caller renders a muted "—") — and
     * computes `isOverdue`/`isUpcoming` in PHP once per row (status =
     * 'scheduled' AND nextDueDate in the past/future respectively — same
     * "compute once here, not per-caller" rationale as listLoans()'s
     * isOverdue, see this class's header point 5).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listMaintenance(int $assetId): array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT m.*, u.fullName AS performedByUserName '
            . 'FROM tblAssetMaintenance m '
            . 'LEFT JOIN tblUsers u ON u.userID = m.performedByUserID '
            . 'WHERE m.assetID = ? '
            . 'ORDER BY COALESCE(m.performedAt, DATE(m.createdAt)) DESC, m.maintID DESC'
        );
        if ($stmt === false) {
            error_log('AssetRegister::listMaintenance() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        $today = date('Y-m-d');
        while ($row = $result->fetch_assoc()) {
            $row['performedByDisplay'] = self::maintenancePerformedByDisplay($row);
            $row['isOverdue']  = (string) $row['status'] === 'scheduled'
                && $row['nextDueDate'] !== null
                && (string) $row['nextDueDate'] < $today;
            $row['isUpcoming'] = (string) $row['status'] === 'scheduled'
                && $row['nextDueDate'] !== null
                && (string) $row['nextDueDate'] >= $today;
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Site-wide "maintenance due" view — every SCHEDULED entry with a
     * nextDueDate set, across every asset on this site, soonest due first.
     * Feeds `maintenance.php`'s site-wide view (rendered when no
     * `?assetID=` is supplied). Confidential-asset filtering mirrors
     * listLoans()/listForSite()'s own `$includeConfidential` param exactly
     * (false = excluded; true = a caller that has already verified the
     * viewer is privileged for confidential assets).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listUpcomingMaintenance(int $siteId, bool $includeConfidential = false): array
    {
        $db = App::db();

        $where = ['m.siteID = ?', "m.status = 'scheduled'", 'm.nextDueDate IS NOT NULL'];
        if ($includeConfidential === false) {
            $where[] = 'a.isConfidential = 0';
        }

        $sql = 'SELECT m.*, a.name AS assetName, a.assetTagCode, a.isConfidential, '
             . '       u.fullName AS performedByUserName '
             . 'FROM tblAssetMaintenance m '
             . 'JOIN tblAssets a ON a.assetID = m.assetID AND a.isDeleted = 0 '
             . 'LEFT JOIN tblUsers u ON u.userID = m.performedByUserID '
             . 'WHERE ' . implode(' AND ', $where) . ' '
             . 'ORDER BY m.nextDueDate ASC';

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            error_log('AssetRegister::listUpcomingMaintenance() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        $today = date('Y-m-d');
        while ($row = $result->fetch_assoc()) {
            $row['performedByDisplay'] = self::maintenancePerformedByDisplay($row);
            $row['isOverdue'] = (string) $row['nextDueDate'] < $today;
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Shared performedByDisplay resolution for listMaintenance()/
     * listUpcomingMaintenance() — factored out so the two read helpers
     * can't drift on this fallback logic. Requires the row to already
     * carry a LEFT JOINed `performedByUserName` column (both callers'
     * SQL provides this).
     *
     * @param array<string, mixed> $row
     */
    private static function maintenancePerformedByDisplay(array $row): ?string
    {
        if ($row['performedByUserID'] !== null) {
            return $row['performedByUserName'] ?? '(deleted user)';
        }
        if ($row['performedByName'] !== null && (string) $row['performedByName'] !== '') {
            return (string) $row['performedByName'];
        }
        return null;
    }

    /**
     * Validate + normalise an optional Y-m-d date string. Returns:
     *   - null   when $raw is empty/absent (a legitimate "not set" value)
     *   - string the normalised Y-m-d value when $raw parses as a real
     *            calendar date
     *   - false  when $raw is non-empty but not a valid Y-m-d date (the
     *            caller should reject the whole operation)
     *
     * Factored out of createLoanRequest()'s inline dueDate validation
     * (which stays as-is, unchanged, to avoid touching #398 behaviour)
     * since addMaintenance()/updateMaintenance() each need the identical
     * check twice (performedAt + nextDueDate).
     *
     * @return string|false|null
     */
    private static function parseOptionalDate(mixed $raw): string|false|null
    {
        $value = trim((string) ($raw ?? ''));
        if ($value === '') {
            return null;
        }
        $parsed = \DateTime::createFromFormat('Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            return false;
        }
        return $value;
    }

    /**
     * Add a maintenance/service log entry to an asset. Validates:
     *   - The asset exists and is on this site (self::get()).
     *   - maintType ∈ MAINTENANCE_TYPES.
     *   - title is non-empty, ≤255 chars (VARCHAR(255) column).
     *   - status ∈ MAINTENANCE_STATUSES (defaults to 'completed', matching
     *     the column's own schema default).
     *   - costPence, when supplied, is an int ≥ 0 — the CALLER
     *     (maintenance-save.php) is responsible for converting a pounds
     *     form input to pence before this is reached, same convention as
     *     every other money field in this app (#266).
     *   - performedAt / nextDueDate, when supplied, are real Y-m-d dates
     *     (via the shared parseOptionalDate() helper).
     *   - performedByUserID, when supplied (a positive int), must exist on
     *     this site (partyExistsOnSite('user', …), reused unchanged from
     *     #396) — otherwise the free-text performedByName is used instead.
     *     UNLIKE addOwner()'s party FK or createLoanRequest()'s
     *     counterparty, BOTH may legitimately be blank at once (e.g.
     *     unattended/self-service maintenance with no named performer) —
     *     this is not an "exactly one of" rule.
     *
     * $data keys: maintType, title, details, performedByUserID (int|0),
     * performedByName, costPence (int|null — already pence), performedAt
     * (Y-m-d|''), nextDueDate (Y-m-d|''), status.
     *
     * @param array<string, mixed> $data
     *
     * @return int New maintID, or 0 on validation failure or insert failure
     */
    public static function addMaintenance(int $assetId, array $data, int $actorUserId): int
    {
        $db     = App::db();
        $siteId = Site::id();

        if (self::get($assetId) === null) {
            error_log('AssetRegister::addMaintenance() asset not found on this site: #' . $assetId);
            return 0;
        }

        $maintType = (string) ($data['maintType'] ?? '');
        if (in_array($maintType, self::MAINTENANCE_TYPES, true) === false) {
            error_log('AssetRegister::addMaintenance() invalid maintType: ' . $maintType);
            return 0;
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            error_log('AssetRegister::addMaintenance() title is required');
            return 0;
        }
        $title = mb_substr($title, 0, 255);

        $status = (string) ($data['status'] ?? 'completed');
        if (in_array($status, self::MAINTENANCE_STATUSES, true) === false) {
            error_log('AssetRegister::addMaintenance() invalid status: ' . $status);
            return 0;
        }

        $details = trim((string) ($data['details'] ?? ''));
        $details = $details !== '' ? $details : null;

        $costPence = null;
        if (isset($data['costPence']) === true && $data['costPence'] !== null && $data['costPence'] !== '') {
            $costPence = (int) $data['costPence'];
            if ($costPence < 0) {
                error_log('AssetRegister::addMaintenance() costPence must be >= 0');
                return 0;
            }
        }

        $performedAt = self::parseOptionalDate($data['performedAt'] ?? null);
        if ($performedAt === false) {
            error_log('AssetRegister::addMaintenance() invalid performedAt date');
            return 0;
        }
        $nextDueDate = self::parseOptionalDate($data['nextDueDate'] ?? null);
        if ($nextDueDate === false) {
            error_log('AssetRegister::addMaintenance() invalid nextDueDate date');
            return 0;
        }

        // 👤 performedByUserID — see method doc: not an "exactly one of"
        // rule like addOwner()/createLoanRequest(), both may be blank.
        $performedByUserId = (int) ($data['performedByUserID'] ?? 0);
        $performedByName = null;
        if ($performedByUserId > 0) {
            if (self::partyExistsOnSite('user', $performedByUserId, $siteId) === false) {
                error_log('AssetRegister::addMaintenance() performedByUserID not found on this site: #' . $performedByUserId);
                return 0;
            }
        } else {
            $performedByUserId = null;
            $freeText = trim((string) ($data['performedByName'] ?? ''));
            $performedByName = $freeText !== '' ? mb_substr($freeText, 0, 255) : null;
        }

        $fields = [
            'siteID'            => [$siteId, 'i'],
            'assetID'           => [$assetId, 'i'],
            'maintType'         => [$maintType, 's'],
            'title'             => [$title, 's'],
            'details'           => [$details, 's'],
            'performedByUserID' => [$performedByUserId, 'i'],
            'performedByName'   => [$performedByName, 's'],
            'costPence'         => [$costPence, 'i'],
            'performedAt'       => [$performedAt, 's'],
            'nextDueDate'       => [$nextDueDate, 's'],
            'status'            => [$status, 's'],
            'createdByID'       => [$actorUserId, 'i'],
        ];

        ['columns' => $columns, 'types' => $types, 'params' => $params] = self::splitFields($fields);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $stmt = $db->prepare('INSERT INTO tblAssetMaintenance (`' . implode('`, `', $columns) . '`) VALUES (' . $placeholders . ')');
        if ($stmt === false) {
            error_log('AssetRegister::addMaintenance() prepare failed: ' . $db->error);
            return 0;
        }

        try {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            error_log('AssetRegister::addMaintenance() insert failed: ' . $e->getMessage());
            $stmt->close();
            return 0;
        }
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        if ($newId <= 0) {
            return 0;
        }

        $auditNew = [];
        foreach ($fields as $col => $pair) {
            $auditNew[$col] = $pair[0];
        }
        self::audit('maintenance', $newId, $assetId, 'create', null, $auditNew);

        return $newId;
    }

    /**
     * Update an existing maintenance entry. IDOR guard: the row must
     * belong to BOTH $assetId AND the current site before anything is
     * read or touched — mirrors loanAction()'s "load scoped to all three,
     * then mutate" pattern. Re-runs every validation rule addMaintenance()
     * does (this method does NOT trust its caller's validation, matching
     * addOwner()'s "re-validate everything" convention rather than
     * createAsset()/updateAsset()'s "caller validates" one — a maintenance
     * entry is a security-relevant record of who-did-what-when, same
     * rationale as the loan register).
     *
     * $data keys: identical shape to addMaintenance()'s.
     *
     * @param array<string, mixed> $data
     *
     * @return bool True on success, false if the row doesn't exist (for
     *              this asset, on this site) or any validation rule fails
     */
    public static function updateMaintenance(int $maintId, int $assetId, array $data, int $actorUserId): bool
    {
        $db     = App::db();
        $siteId = Site::id();

        // 🔒 IDOR guard FIRST — before any validation or mutation.
        $stmt = $db->prepare('SELECT * FROM tblAssetMaintenance WHERE maintID = ? AND assetID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            error_log('AssetRegister::updateMaintenance() prepare failed: ' . $db->error);
            return false;
        }
        $stmt->bind_param('iii', $maintId, $assetId, $siteId);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($old === null || $old === false) {
            return false;
        }

        $maintType = (string) ($data['maintType'] ?? '');
        if (in_array($maintType, self::MAINTENANCE_TYPES, true) === false) {
            error_log('AssetRegister::updateMaintenance() invalid maintType: ' . $maintType);
            return false;
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            error_log('AssetRegister::updateMaintenance() title is required');
            return false;
        }
        $title = mb_substr($title, 0, 255);

        $status = (string) ($data['status'] ?? 'completed');
        if (in_array($status, self::MAINTENANCE_STATUSES, true) === false) {
            error_log('AssetRegister::updateMaintenance() invalid status: ' . $status);
            return false;
        }

        $details = trim((string) ($data['details'] ?? ''));
        $details = $details !== '' ? $details : null;

        $costPence = null;
        if (isset($data['costPence']) === true && $data['costPence'] !== null && $data['costPence'] !== '') {
            $costPence = (int) $data['costPence'];
            if ($costPence < 0) {
                error_log('AssetRegister::updateMaintenance() costPence must be >= 0');
                return false;
            }
        }

        $performedAt = self::parseOptionalDate($data['performedAt'] ?? null);
        if ($performedAt === false) {
            error_log('AssetRegister::updateMaintenance() invalid performedAt date');
            return false;
        }
        $nextDueDate = self::parseOptionalDate($data['nextDueDate'] ?? null);
        if ($nextDueDate === false) {
            error_log('AssetRegister::updateMaintenance() invalid nextDueDate date');
            return false;
        }

        $performedByUserId = (int) ($data['performedByUserID'] ?? 0);
        $performedByName = null;
        if ($performedByUserId > 0) {
            if (self::partyExistsOnSite('user', $performedByUserId, $siteId) === false) {
                error_log('AssetRegister::updateMaintenance() performedByUserID not found on this site: #' . $performedByUserId);
                return false;
            }
        } else {
            $performedByUserId = null;
            $freeText = trim((string) ($data['performedByName'] ?? ''));
            $performedByName = $freeText !== '' ? mb_substr($freeText, 0, 255) : null;
        }

        $fields = [
            'maintType'         => [$maintType, 's'],
            'title'             => [$title, 's'],
            'details'           => [$details, 's'],
            'performedByUserID' => [$performedByUserId, 'i'],
            'performedByName'   => [$performedByName, 's'],
            'costPence'         => [$costPence, 'i'],
            'performedAt'       => [$performedAt, 's'],
            'nextDueDate'       => [$nextDueDate, 's'],
            'status'            => [$status, 's'],
        ];

        ['columns' => $columns, 'types' => $types, 'params' => $params] = self::splitFields($fields);
        $setClause = implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = ?', $columns));
        $types    .= 'iii';
        $params[]  = $maintId;
        $params[]  = $assetId;
        $params[]  = $siteId;

        $stmt = $db->prepare('UPDATE tblAssetMaintenance SET ' . $setClause . ' WHERE maintID = ? AND assetID = ? AND siteID = ?');
        if ($stmt === false) {
            error_log('AssetRegister::updateMaintenance() prepare failed: ' . $db->error);
            return false;
        }

        try {
            $stmt->bind_param($types, ...$params);
            $ok = $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            error_log('AssetRegister::updateMaintenance() update failed: ' . $e->getMessage());
            $stmt->close();
            return false;
        }
        $stmt->close();

        if ($ok === false) {
            return false;
        }

        // 📜 Audit — restrict the diff to just the editable fields we
        // touched, same convention as updateAsset()'s own audit call.
        $auditOld = array_intersect_key($old, $fields);
        $auditNew = [];
        foreach ($fields as $col => $pair) {
            $auditNew[$col] = $pair[0];
        }
        self::audit('maintenance', $maintId, $assetId, 'update', $auditOld, $auditNew);

        return true;
    }

    /**
     * Delete a maintenance entry. IDOR guard: the row must belong to BOTH
     * $assetId AND the current site before it's touched — mirrors
     * removeOwner()/removeIdentifier()'s own "confirm it belongs to this
     * asset first" pattern.
     *
     * @return bool True if a row existed (for this asset, on this site)
     *              and was removed
     */
    public static function deleteMaintenance(int $maintId, int $assetId, int $actorUserId): bool
    {
        $db     = App::db();
        $siteId = Site::id();

        $stmt = $db->prepare('SELECT * FROM tblAssetMaintenance WHERE maintID = ? AND assetID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iii', $maintId, $assetId, $siteId);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($old === null || $old === false) {
            return false;
        }

        $delStmt = $db->prepare('DELETE FROM tblAssetMaintenance WHERE maintID = ? AND assetID = ? AND siteID = ?');
        if ($delStmt === false) {
            return false;
        }
        $delStmt->bind_param('iii', $maintId, $assetId, $siteId);
        $ok = $delStmt->execute();
        $affected = $delStmt->affected_rows;
        $delStmt->close();

        if ($ok === false || $affected <= 0) {
            return false;
        }

        self::audit('maintenance', $maintId, $assetId, 'delete', $old, null);

        return true;
    }

    /**
     * Pure straight-line depreciation estimate — NO DATABASE ACCESS, no
     * site/audit context. Given an asset row (anything with
     * depreciationMethod/purchaseCostPence/purchaseDate/usefulLifeMonths/
     * salvageValuePence keys — i.e. whatever self::get() returns), computes
     * the asset's estimated current book value in pence AS OF a given date
     * (defaults to today).
     *
     * Returns null (display-only "not computable" — the caller must NEVER
     * invent a value) when:
     *   - depreciationMethod !== 'straight-line' (reducing-balance is
     *     explicitly Phase-3 cron work, per #399's spec — this method makes
     *     no attempt at it), OR
     *   - purchaseCostPence, usefulLifeMonths, or purchaseDate is missing,
     *     OR usefulLifeMonths <= 0.
     *
     * Otherwise: linearly depreciates from purchaseCostPence down to
     * salvageValuePence (defaults to 0 pence when not set) over
     * usefulLifeMonths WHOLE calendar months from purchaseDate, using
     * DateTime::diff() (not a naive day-count/30) so a calendar month
     * always counts as one month regardless of its actual length. Clamped
     * at BOTH ends:
     *   - elapsed time is floored at 0 — an $asOfDate before purchaseDate
     *     (clock skew, a back-dated valuation request) can never produce
     *     NEGATIVE depreciation (a value ABOVE purchase cost);
     *   - elapsed months are capped at usefulLifeMonths — once fully
     *     depreciated the value sits at salvage and never falls further;
     *   - a salvageValuePence that (bad data entry) exceeds
     *     purchaseCostPence is itself clamped down to purchaseCostPence
     *     first, so the formula can never depreciate UPWARDS.
     * A final belt-and-braces clamp after the arithmetic guards against any
     * rounding drift pushing the result fractionally outside
     * [salvage, purchaseCost].
     *
     * @param array<string, mixed> $asset
     *
     * @return int|null Estimated current value in pence, or null when not
     *                   computable / method isn't 'straight-line'
     */
    public static function computeStraightLineValue(array $asset, ?string $asOfDate = null): ?int
    {
        if ((string) ($asset['depreciationMethod'] ?? 'none') !== 'straight-line') {
            return null;
        }

        $purchaseCostPenceRaw = $asset['purchaseCostPence'] ?? null;
        $usefulLifeMonthsRaw  = $asset['usefulLifeMonths'] ?? null;
        $purchaseDateRaw      = $asset['purchaseDate'] ?? null;

        if ($purchaseCostPenceRaw === null || $usefulLifeMonthsRaw === null
            || $purchaseDateRaw === null || (string) $purchaseDateRaw === ''
        ) {
            return null;
        }

        $purchaseCostPence = (int) $purchaseCostPenceRaw;
        $usefulLifeMonths  = (int) $usefulLifeMonthsRaw;
        if ($purchaseCostPence < 0 || $usefulLifeMonths <= 0) {
            return null;
        }

        $salvageValuePenceRaw = $asset['salvageValuePence'] ?? null;
        $salvageValuePence = $salvageValuePenceRaw !== null ? (int) $salvageValuePenceRaw : 0;
        if ($salvageValuePence < 0) {
            $salvageValuePence = 0;
        }
        // 🛟 A mis-entered salvage value ABOVE the purchase cost would
        // otherwise make the formula below depreciate UPWARDS — clamp it
        // down first so the value can never appreciate. See method doc.
        if ($salvageValuePence > $purchaseCostPence) {
            $salvageValuePence = $purchaseCostPence;
        }

        try {
            $purchaseDate = new \DateTime((string) $purchaseDateRaw);
        } catch (\Throwable $e) {
            return null; // 🛟 Unparseable purchaseDate — never guess.
        }

        $asOf = null;
        if ($asOfDate !== null) {
            try {
                $asOf = new \DateTime($asOfDate);
            } catch (\Throwable $e) {
                $asOf = null; // 🛟 Bad override — fall through to "today".
            }
        }
        if ($asOf === null) {
            $asOf = new \DateTime('today');
        }

        // 📅 Elapsed WHOLE calendar months since purchase — floored at 0 (an
        // $asOf before purchaseDate must never produce negative elapsed
        // time). DateTime::diff()'s y/m fields already count only whole
        // completed months; a partial month in progress (diff->d > 0) is
        // deliberately NOT rounded up, matching usefulLifeMonths' own
        // whole-month unit.
        $elapsedMonths = 0;
        if ($asOf >= $purchaseDate) {
            $diff = $purchaseDate->diff($asOf);
            $elapsedMonths = ($diff->y * 12) + $diff->m;
        }

        // 🔒 Cap at the useful life — once fully depreciated the value sits
        // at salvage and never falls further (see method doc).
        $depreciableMonths = min($elapsedMonths, $usefulLifeMonths);

        $depreciableAmount = $purchaseCostPence - $salvageValuePence;
        $depreciatedSoFar  = (int) round(($depreciableAmount * $depreciableMonths) / $usefulLifeMonths);

        $currentValue = $purchaseCostPence - $depreciatedSoFar;

        // 🔒 Final belt-and-braces clamp against rounding drift — see
        // method doc's closing paragraph.
        if ($currentValue < $salvageValuePence) {
            $currentValue = $salvageValuePence;
        }
        if ($currentValue > $purchaseCostPence) {
            $currentValue = $purchaseCostPence;
        }

        return $currentValue;
    }

    /* ==========================================================================
     * 📜 Ownership & legal vault (#396)
     * ------------------------------------------------------------------------
     * A restricted VIEW over the same tblAssetResources table listResources()
     * already reads — not a separate table. See item.php's header for the
     * access-control split: the general Resources panel (any logged-in
     * viewer who can see the asset) EXCLUDES these types entirely; only the
     * admin/asset_manager/isResponsibleFor()-gated vault panel calls this
     * method. isPublic is already forced to 0 for every type in
     * AGREEMENT_VAULT_RESOURCE_TYPES by addResource() (see
     * PUBLIC_ELIGIBLE_RESOURCE_TYPES's doc) — this method is an additional,
     * independent layer restricting who ever SEES these rows at all, public
     * or not.
     * ======================================================================== */

    /**
     * List an asset's ownership-agreement/insurance/legal resources —
     * the confidential vault's contents. Callers MUST gate rendering of
     * both this method's result and the fact that it was called at all
     * behind a privileged check (see class header) — this method performs
     * no authorisation of its own, matching decryptLicenseKey()'s contract.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listAgreementDocs(int $assetId): array
    {
        $db    = App::db();
        $types = self::AGREEMENT_VAULT_RESOURCE_TYPES;
        $placeholders = implode(', ', array_fill(0, count($types), '?'));

        $stmt = $db->prepare(
            'SELECT * FROM tblAssetResources WHERE assetID = ? AND resourceType IN (' . $placeholders . ') '
            . 'ORDER BY createdAt DESC'
        );
        if ($stmt === false) {
            error_log('AssetRegister::listAgreementDocs() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('i' . str_repeat('s', count($types)), $assetId, ...$types);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
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
