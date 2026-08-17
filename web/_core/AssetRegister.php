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
 *   7. Per-device licence tracking (#400, this pass). `listLicenseAssignments()`
 *      resolves a single `assignedToDisplay` string per row (the linked
 *      device-asset's name / the linked portal user's name / the free-text
 *      `deviceName` — exactly one of the three is ever set per row, same
 *      "resolve once here" rationale as `listOwners()`'s `partyName` /
 *      `listLoans()`'s `counterpartyDisplayName`) plus `linkedByName`/
 *      `releasedByName`, active rows first. `activeSeatCount()`/
 *      `seatSummary()` are read helpers `item.php`'s header readout and
 *      `assignSeat()`'s own seat-enforcement both call — `seatSummary()`
 *      is null-safe throughout when the licence asset's `licenseSeats` is
 *      NULL (unlimited seats, no cap configured). `assignSeat()` re-
 *      validates everything from scratch (does NOT trust its caller,
 *      matching `addOwner()`'s/`createLoanRequest()`'s convention rather
 *      than `createAsset()`'s "caller validates" one — a seat assignment
 *      is a security-relevant record of who/what holds a licence): the
 *      target licence asset must exist on-site AND be `assetKind =
 *      'digital'`; exactly ONE of {`deviceAssetID` (a real `tblAssets` row
 *      on this site — re-uses `self::get()`, never a bare posted int),
 *      `userID` (`partyExistsOnSite('user', …)`, re-used unchanged from
 *      #396), `deviceName` (non-empty free text)} is required, mirroring
 *      `addOwner()`'s exactly-one-party-FK rule / `createLoanRequest()`'s
 *      exactly-one-counterparty rule (same "no SQL constraint enforces
 *      this" shape — see migration 159's table comment). Seat
 *      enforcement is WARN-by-default: once `activeSeatCount() >=
 *      licenseSeats`, the assignment still saves and a warning string is
 *      returned for the caller to flash, UNLESS the site has opted into
 *      `App::settings('assets.license_seat_block') === '1'`, in which
 *      case the assignment is hard-BLOCKED (id 0, no insert, no audit
 *      row) — the same two-tier "advisory vs enforced" pattern already
 *      used for identifier validation (non-blocking) vs the exactly-one-FK
 *      rules above (blocking). `releaseSeat()` is IDOR-guarded
 *      (`assignmentID` + `licenseAssetID` + `siteID`, read-then-mutate,
 *      mirrors `updateMaintenance()`'s pattern) and race-safe via a
 *      `WHERE … AND status = 'active'` guard on the UPDATE itself (0
 *      affected rows = already released between the read and the write —
 *      same idiom as `loanAction()`'s state-machine guards) — it NEVER
 *      hard-deletes; the row is its own permanent history, exactly like
 *      every other state-machine table in this class. Every mutation
 *      routes through `self::audit()` (entityType `'license'`, already
 *      wired into `TABLE_FOR_ENTITY` since the #395 audit choke-point
 *      pass) — `assignSeat()` audits `'link'`, `releaseSeat()` audits
 *      `'release'`. The licence KEY itself (`tblAssets.licenseKey`) is
 *      never read, decrypted, or referenced anywhere in this section —
 *      seat tracking is entirely about WHO/WHAT holds a seat, not the
 *      secret the licence asset carries; `decryptLicenseKey()` (#394)
 *      remains the one and only reveal path, unchanged by this pass.
 *
 *   8. Public QR page + lost-and-found flow (#401, this pass).
 *      `regeneratePublicToken()` sits alongside `generatePublicToken()`
 *      (point 1 below) — a manager-only rotation of an asset's public
 *      token (`_apps/assets/item.php`'s "Regenerate public token" control,
 *      folded into `save.php` via `action=regenerate-token` rather than a
 *      new route — see that controller's header). Audits entityType
 *      `'token'`, action `'regenerate'` — the SAME entityType tag.php's
 *      view-audit already uses for `'scan'`, and deliberately NOT in
 *      `TABLE_FOR_ENTITY` (no real "token" table — see that constant's own
 *      doc), so this never attempts a `tblAuditTrail` mirror, exactly like
 *      the scan event. `createFoundReport()`/`listFoundReports()`/
 *      `setFoundReportStatus()`/`purgeExpiredFoundReports()` are the
 *      `tblAssetFoundReports` CRUD for the public "I found this" form
 *      (`_apps/assets/tag.php`) and its admin triage queue
 *      (`_apps/assets/found-reports.php`). `createFoundReport()` is the
 *      ONLY method in this class ever called with NO authenticated
 *      session behind it — `_apps/assets/found-save.php` calls it only
 *      after its own captcha + CSRF + honeypot + per-IP rate-limit gate
 *      chain passes (mirroring `tag.php`'s own uniform-404 asset-lookup
 *      gates first) — so it trims/caps its own input rather than trusting
 *      a caller the way `createAsset()`/`updateAsset()` do, and audits
 *      with `actorType: 'public'` (no `actorUserID`) rather than resolving
 *      one from `$_SESSION`. `setFoundReportStatus()` is the ONLY manager
 *      action here and is IDOR-guarded (`reportID` + `siteID`) exactly
 *      like `removeIdentifier()`. `purgeExpiredFoundReports()` deletes
 *      reports past `assets.found_report_retention_days` (GDPR — a
 *      found-report row holds third-party PII with no consenting account
 *      behind it) but is not wired into any cron route by this pass — see
 *      that method's own doc for why. `publicIpHash()` is a thin public
 *      wrapper around the existing PRIVATE `ipHash()` (point 2 below) so
 *      `found-save.php`'s `RateLimiter::tooMany()`/`recordHit()` bucket
 *      key reuses the EXACT SAME salted-SHA-256 the found-report row's own
 *      `ipHash` column stores, rather than a second, divergent hashing
 *      scheme.
 *
 *   9. Label designer + PDF (QR) (#402, this pass). `assetsForLabels()` is
 *      the read helper both `_apps/assets/labels.php` (the designer/preview
 *      GET screen) and `_apps/assets/labels-pdf.php` (the POST PDF
 *      generator) call to resolve a batch of posted asset ids into the
 *      exact fields a label can show — site-scoped and non-deleted via the
 *      same `siteID`/`isDeleted` filter every other read in this class
 *      uses, so an id from another tenant (or a stale/deleted asset) is
 *      simply absent from the result rather than erroring. `labelPublicUrl()`
 *      builds the QR payload — ALWAYS `https://{our own request host}/a/
 *      {this asset's own stored publicToken}`, via the private
 *      `siteBaseUrl()` helper, which mirrors the existing house convention
 *      for absolute outbound links (`Newsletter::baseUrl()`, `PrayerChain`'s
 *      own inline scheme+host resolution, `calendar/export.php`,
 *      `account-feed.php`) — `$_SERVER['HTTP_HOST']`/`['HTTPS']` are set by
 *      the webserver/vhost from the connection itself, never from a
 *      request BODY, and the token component is re-validated against the
 *      same `^[a-f0-9]{32}$` shape `tag.php`/`Router` already enforce
 *      before it's ever embedded — a QR code generated by this pass can
 *      NEVER encode anything other than our own host plus a real,
 *      already-stored token. `recordLabelPrint()` writes one
 *      `audit('label', assetID, assetID, 'print', …)` row PER ASSET per
 *      batch (not one row for the whole batch) so item.php's per-asset
 *      "Recent activity" strip shows exactly when THAT asset's label was
 *      last printed; entityType `'label'` is deliberately NOT in
 *      `TABLE_FOR_ENTITY` (no `tblAssetLabels` table — a label print is an
 *      audit EVENT, never a persisted row of its own), mirroring the
 *      `'token'`/`'scan'` convention in point 8 above. `LABEL_PRESETS`
 *      (Avery L7160/L7163/L7165 mm-dimensioned sheet layouts, plus a
 *      house-only small QR+code tag) and `LABEL_FIELDS` are the single
 *      source of truth both the designer's live preview AND the PDF
 *      generator read from, so the two can never silently disagree about
 *      what a given preset/field means — `buildLabelSheets()` is the ONE
 *      rendering path both call (preview embeds its returned HTML/CSS
 *      directly into the portal page; the PDF generator embeds the exact
 *      same HTML/CSS into a standalone document for `Pdf::create()`, or
 *      into a print-CSS fallback page when dompdf isn't installed — see
 *      that method's own doc for why `position:absolute` + mm units, not
 *      CSS Grid/table, is the one layout strategy shared by both
 *      renderers). Every text value `buildLabelSheets()`/
 *      `renderLabelCellInner()` place into that HTML is
 *      `htmlspecialchars()`'d — the same output-escaping discipline as
 *      every other view in this app. `MAX_LABEL_COPIES`/`MAX_TOTAL_LABELS`
 *      are the shared caps `labels-pdf.php` enforces server-side (never
 *      trusting whatever the designer's own GET preview happened to
 *      render) to keep a single request from asking dompdf to lay out an
 *      unbounded number of label cells.
 *
 *  10. Asset ↔ event assignments (#409, Phase 2 Pass 2). `tblAssetEventAssignments`
 *      (migration 160) links an asset to a calendar event, with an OPTIONAL
 *      assignment window (`assignedFrom`/`assignedUntil`) distinct from the
 *      event's own start/end — NULL on either side means "use the event's
 *      own window" (see the migration's column comments; `item.php`/
 *      `event.php` render that fallback rather than inventing dates).
 *      `listEventAssignments()` (per-asset, newest-assigned first) and
 *      `listAssetsForEvent()` (per-event, alphabetical) are the two read
 *      directions `item.php`'s Assigned-events panel and the calendar
 *      event page's own Assigned-assets section respectively call.
 *      `assignToEvent()` does NOT trust its caller (same convention as
 *      `addOwner()`/`createLoanRequest()` — a cross-app link is a
 *      security-relevant record): it re-confirms the asset via `self::
 *      get()` AND independently re-validates the posted `eventID` exists
 *      on `Site::id()` (mirrors `_apps/documents/upload.php`'s own
 *      eventID re-validation — an event id from another tenant, or one
 *      that never existed, is rejected exactly like an unknown assetID
 *      would be, never trusted just because a form posted it), validates
 *      any supplied `assignedFrom`/`assignedUntil` as real datetimes with
 *      `assignedFrom` ≤ `assignedUntil` when both are given, then runs an
 *      ADVISORY overlap check (an active loan on this asset, OR another
 *      event assignment whose window intersects the one requested) —
 *      WARNS, never blocks, same two-tier "advisory vs enforced" pattern
 *      `assignSeat()` (point 7 above) uses for seat-cap enforcement. The
 *      `uq_astev_asset_event` unique key (one row per asset+event pair) is
 *      caught via `\mysqli_sql_exception` into a friendly "already
 *      assigned" warning, exactly like `addIdentifier()`'s own duplicate-
 *      catch shape (point 4). `unassignFromEvent()` is IDOR-guarded
 *      (`assignmentID` + `assetID` + `siteID`, read-then-delete) exactly
 *      like `removeOwner()`. Every mutation routes through `self::audit()`
 *      with entityType `'event-link'` — UNLIKE the `'token'`/`'label'`
 *      event-only entities (points 8-9 above), `'event-link'` NOW has a
 *      real backing table and IS wired into `TABLE_FOR_ENTITY`, so these
 *      rows also mirror into the platform's generic `tblAuditTrail` via
 *      `Logger::audit()`, not just `tblAssetAudit`.
 *
 *  11. Scan log + "my assets" + reminder-log foundation (#410, Phase 2
 *      Pass 2). `tblAssetScanLog` (migration 160) is a PURPOSE-BUILT,
 *      queryable log distinct from the existing `audit('token', …, 'scan')`
 *      EVENT tag.php already wrote (point 8 above) — both are written on
 *      every eligible view (`recordScan()` is called ALONGSIDE, not
 *      instead of, the existing audit() call), because `tblAssetAudit` is
 *      the generic append-only choke point while `tblAssetScanLog` exists
 *      specifically to be aggregated (`scanStats()`/`scanStatsForSite()`,
 *      the 30-day sparkbars on `item.php`'s manager-only analytics strip)
 *      without scanning the much busier generic audit table. `recordScan()`
 *      stores ONLY salted-SHA-256 hashes — `ipHash` via the existing
 *      private `ipHash()` (point 2 above, unchanged), `userAgentHash` via
 *      a new `saltedHash()` helper that factors out that SAME salt-file
 *      scheme so both hashes are computed identically — NEVER a raw IP or
 *      User-Agent string. It is wrapped in its own try/catch and NEVER
 *      throws: a public lost-and-found page view, or an internal re-scan,
 *      must never 500 because a log write failed. `purgeExpiredScanLog()`
 *      deletes rows older than `assets.scan_log_retention_days` (seeded by
 *      migration 160) — provided now, wired into the #405 reminder-sweep
 *      cron in a later pass, same "method ships now, caller lands later"
 *      precedent as `purgeExpiredFoundReports()` (point 8). `listForUser()`
 *      is the read behind `_apps/assets/my.php` — STRICTLY the session
 *      user's own assets, reached three ways (direct/dept/group
 *      `tblAssetOwners` rows, reusing `isResponsibleFor()`'s exact three
 *      joins; an active `tblAssetLoans` row with this user as
 *      `counterpartyUserID`; an active `tblAssetLicenseAssignments` seat)
 *      — de-duplicated by `assetID` with every matching reason folded into
 *      one `reasons[]` array per row, so an asset the viewer both owns AND
 *      currently has on loan appears once, not twice.
 *
 *  12. Reminder sweep + value dashboard (#405 / #408, Phase 2 Pass 3).
 *      `listDueMaintenanceReminders()`/`listExpiringWarranties()`/
 *      `listExpiringInsurance()` are the three "due within N days" read
 *      helpers behind the `#405` cron sweep — each takes an explicit
 *      `$siteId` (the cron iterates every site, calling
 *      `Site::forceContext()` before any audit-writing call, but these
 *      three reads never depend on ambient `Site::id()` themselves) and an
 *      explicit `$leadDays`, matching items whose relevant date falls in
 *      `[today, today+leadDays]` inclusive — never anything ALREADY
 *      overdue (maintenance's own overdue case stays visible via
 *      `listUpcomingMaintenance()`'s existing `isOverdue` flag; that's a
 *      management view, not a one-shot reminder). `listOverdueLoans()` is a
 *      thin wrapper over the existing `listLoans($siteId, ['overdueOnly'
 *      => true], true)` (point 5 above) — `$includeConfidential = true`
 *      because this is a system sweep with full visibility, mirroring
 *      `listLoansForAsset()`'s own "nothing left to restrict" rationale.
 *      `resolveReminderRecipients()` resolves a due item's audience from
 *      the ASSET'S OWN `siteID` (looked up fresh, never the caller's
 *      ambient site — safe to call regardless of which site the cron has
 *      currently forced) — direct/dept/group `tblAssetOwners` rows
 *      carrying the requested authority flag (`'maintenance'` →
 *      `isMaintenanceAuthority`, `'lending'` → `isLendingAuthority`; any
 *      other value skips the owner-authority join entirely) PLUS, always,
 *      every active site admin/root-admin/site-root-admin/asset_manager
 *      role-holder as a fallback audience — mirrors `found-save.php`'s own
 *      admin-notify query (see that file's header) widened to the full
 *      4-tier hierarchy `App::isAdmin()` checks. `resolveLoanCounterpartyEmail()`
 *      is a small companion helper — resolves ONE loan row's own
 *      borrower/lender contact address (`counterpartyUserID` →
 *      `tblUsers.emailAddress`, `counterpartyOrgID` →
 *      `tblAssetOrgs.contactEmail`, or the free-text `counterpartyContact`
 *      when `counterpartyType = 'other'`, each independently
 *      `FILTER_VALIDATE_EMAIL`'d) — the cron's loan-overdue reminder unions
 *      this with `resolveReminderRecipients($assetId, 'lending')` so the
 *      mail reaches the lending authority AND the actual counterparty,
 *      never a wider audience (see #405's security musts). Every reminder
 *      the cron sends is single-shot via `tblAssetReminderLog`'s
 *      `uq_astrl_ref` unique key (`refType`, `refID`, `dueDate`) — the cron
 *      checks-then-inserts, catching `\mysqli_sql_exception` on the INSERT
 *      as the concurrency backstop (same duplicate-catch shape as
 *      `addIdentifier()`/`assignToEvent()`) — and every send is audited via
 *      `self::audit()` with `actorType: 'system'`, action `'reminder'`
 *      (deliberately NOT in the `['create','update','delete']` set
 *      `audit()` mirrors into `tblAuditTrail` for — a reminder is an EVENT,
 *      same convention as the `'scan'`/`'print'` events in points 8-9
 *      above). `persistCurrentValues()` closes the "currentValuePence
 *      never written" gap `computeStraightLineValue()`'s own doc flagged
 *      as Phase-3 work (point 6 above) — for every `'straight-line'` asset
 *      on a site, computes today's value via that SAME pure helper and
 *      writes it to `tblAssets.currentValuePence`/`valuationDate` via a
 *      narrow two-column UPDATE (never the full `updateAsset()` field-set,
 *      which would misleadingly diff every OTHER column too) — an asset
 *      the pure helper can't compute a value for (missing cost/life/
 *      purchase-date, or not `'straight-line'`) is simply left untouched,
 *      never zeroed or guessed. No per-row audit call (routine bulk
 *      housekeeping — mirrors `purgeExpiredFoundReports()`/
 *      `purgeExpiredScanLog()`'s own no-audit convention, points 8/11
 *      above); the cron's own aggregate `Logger::activity()` call covers
 *      the run. `valueSummaryForSite()`/`depreciationReportRows()` feed
 *      the `#408` `_apps/assets/value-report.php` screen — both PREFER
 *      each asset's persisted `currentValuePence` (written by
 *      `persistCurrentValues()` above) and fall back to a live
 *      `computeStraightLineValue()` estimate only when nothing has been
 *      persisted yet, exactly like `item.php`'s own existing depreciation
 *      readout; an asset that simply isn't computable (reducing-balance,
 *      or a straight-line asset missing an input) is counted separately
 *      (`notValuedCount`) rather than folded into a total as if it were
 *      zero. `valueSummaryForSite()` also totals `insuredValuePence` and
 *      derives an insurance-gap figure (insured − current, only over
 *      assets where BOTH are known), grouped by category and by status.
 *      The four insurance columns feeding this section —
 *      `insurerName`/`insurancePolicyNumber`/`insuredValuePence`/
 *      `insuranceRenewalDate` (added to `tblAssets` by migration 160) —
 *      are ALSO now wired into `createAsset()`/`updateAsset()`'s `$fields`
 *      maps (this pass is the first to actually persist them) and into
 *      `item.php`'s `$privileged`-gated readout (admin/asset_manager/
 *      `isResponsibleFor()` — the SAME gate as the Ownership & legal
 *      vault, point 8 above, since a policy number/insured value sits at
 *      roughly that same sensitivity). Neither total in this section
 *      currency-converts — every pence figure is summed as-is, matching
 *      the register's existing single-reporting-currency assumption (see
 *      `_apps/assets/index.php`'s own CSV export, which hard-codes "(GBP)").
 *
 *  14. Kiosk self check-in/out (#414, Phase 3 Pass 5). Unattended, PUBLIC
 *      terminal access — `_apps/assets/kiosk.php`/`kiosk-action.php` carry
 *      NO login session; every method in this section is written for that
 *      threat model (see those files' own headers for the full gate
 *      chain). `mintKioskToken()`/`setKioskTokenActive()`/
 *      `deleteKioskToken()`/`listKioskTokens()` manage `tblAssetKioskTokens`
 *      (the per-DEVICE credential, entityType `'kiosk'`, now wired into
 *      `TABLE_FOR_ENTITY` above) — admin-only, session-scoped, mirroring
 *      `mintKioskToken()`'s own token-generation shape on
 *      `generatePublicToken()`/`regeneratePublicToken()` (point 8) and its
 *      duplicate-catch shape on `addIdentifier()`'s (point 4).
 *      `listKioskTokens()` NEVER selects the `token` column — a terminal's
 *      credential is shown to an admin exactly once, at mint time, in
 *      `mintKioskToken()`'s own return value. `resolveKioskTerminal()` is
 *      the PUBLIC-safe token→terminal lookup `kiosk.php`/`kiosk-action.php`
 *      call on EVERY request (re-validates the `^[a-f0-9]{32}$` shape
 *      itself, same defensive re-validation convention as `tag.php`/
 *      `found-save.php` point 8) — an unknown or revoked (`isActive = 0`)
 *      token returns null with no distinguishing signal between the two
 *      (no oracle), and a successful resolve bumps `lastSeenAt` via a
 *      separate, cheap UPDATE. `setKioskPin()`/`clearKioskPin()`/
 *      `hasKioskPin()` manage a member's OWN `tblAssetKioskPins` row (the
 *      per-USER credential) — `setKioskPin()` re-validates the PIN shape
 *      (`^\d{4,6}$`) and rejects a small weak-list/sequential-run/
 *      all-same-digit PIN (`isWeakKioskPin()`) before `password_hash()`-ing
 *      it, UPSERT-ing on `uq_astkp_site_user`; `clearKioskPin()` flips
 *      `isActive = 0` rather than deleting the row (re-setting a PIN later
 *      re-activates it via the same UPSERT). Neither routes through
 *      `self::audit()` (a PIN isn't asset-scoped) — both log via a plain
 *      `Logger::activity()` call instead, mirroring the site-wide
 *      reference-data convention (point 2). `resolveKioskUser()` resolves
 *      the SAME "username or email" identifier `Auth::loginLocal()` accepts
 *      (mirrored exactly, including the lower-cased/trimmed identifier),
 *      additionally scoped to an ACTIVE `tblUserSites` membership on the
 *      terminal's OWN site (`partyExistsOnSite('user', …)`'s own join
 *      shape, point 5) — never trusts a bare userID from the request.
 *      `verifyKioskPin()` is the `password_verify()` counterpart, bumping
 *      `lastUsedAt` on success; a missing PIN row simply verifies false,
 *      never short-circuiting differently from a wrong PIN (no oracle).
 *      `kioskCheckout()`/`kioskCheckin()` are the actual hand-over/return
 *      actions — both audit as entityType `'loan'` (NOT `'kiosk'` — a
 *      kiosk check-in/out IS a loan event, just one with a different actor
 *      type) via the new `actorType: 'kiosk'` + `actorUserIdOverride` pair
 *      (point 1's `audit()` change), attributing the row to the PIN-
 *      resolved user despite there being no session. `kioskCheckout()`
 *      rejects a confidential asset AND a wrong-status asset with the
 *      EXACT SAME message (no oracle distinguishing "confidential" from
 *      "not currently loanable") before separately reporting a genuine
 *      already-open loan (that leak is acceptable — only a real,
 *      already-public, non-confidential asset ever reaches that branch).
 *      Deliberately does NOT run `cascadeKitCheckout()` (#413, point
 *      unlisted above) — a kiosk hand-over is always a single asset.
 *      `kioskCheckin()` IDOR-guards on `assetID` + `siteID` +
 *      `counterpartyUserID` + `direction = 'out'` + `status = 'active'`
 *      — a kiosk user can only ever check in a loan THEY are the
 *      counterparty of — PLUS a defensive `isConfidential = 0` join
 *      (belt-and-braces: `kioskCheckout()` already prevents a confidential
 *      asset from EVER acquiring such a loan via the kiosk path, but this
 *      closes the same "never act on a confidential asset" gate against a
 *      hand-crafted POST targeting a confidential asset a NON-kiosk
 *      workflow separately loaned to that user). Both wrap their loan-row
 *      UPDATE and `tblAssets` UPDATE in one transaction, same shape as
 *      `loanCheckout()`/`loanCheckin()` (point 5). `kioskUserActiveLoans()`
 *      is the read behind `kiosk.php`'s own check-in list — STRICTLY the
 *      identified kiosk user's own active 'out' loans, additionally
 *      excluding confidential assets defensively (same rationale as
 *      `kioskCheckin()`'s own join, belt-and-braces since none should
 *      exist there in the first place).
 *
 * All queries are MySQLi prepared statements via `App::db()` — never
 * string-interpolated user input (house rule, .claude/CLAUDE.md → Code Style).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.10.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/394
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/395
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/396
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/397
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/398
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/399
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/400
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/401
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/402
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/404
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/405
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/408
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/409
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/410
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/414
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

    /** @var string[] tblAssetScanLog.scanContext (#404 Pass 2 / #410) */
    public const SCAN_CONTEXTS = ['public', 'internal'];

    /** @var string[] tblAssetStocktakes.status (#411) */
    public const STOCKTAKE_STATUSES = ['open', 'closed'];

    /** @var string[] tblAssetStocktakeItems.verifyStatus (#411) */
    public const STOCKTAKE_VERIFY_STATUSES = ['pending', 'present', 'missing', 'moved', 'unexpected'];

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

    /**
     * Rotate an asset's public lost-and-found token (#401). Manager-only —
     * the caller (`_apps/assets/save.php`'s `action=regenerate-token`
     * branch) re-checks the admin/asset_manager gate independently before
     * ever calling this, same "controller gates, model trusts the gate
     * already ran" convention as every other mutating method in this
     * class.
     *
     * EVERY printed label/QR code encoding the OLD token stops resolving
     * the instant this runs — item.php's confirm dialog says so — so this
     * is a deliberate, rare, disruptive action (a lost/compromised label,
     * or a manager who wants old photocopies to stop working), not
     * something to call casually.
     *
     * @return string|null The new 32-char token on success, or null if the
     *                      asset doesn't exist (or isn't on this site) or
     *                      the update failed
     */
    public static function regeneratePublicToken(int $assetId, int $actorUserId): ?string
    {
        $db     = App::db();
        $siteId = Site::id();

        // 🔒 Site-scoped existence check — self::get() already applies
        // Site::id() internally (see that method's own doc for why), same
        // guard every other single-asset mutator in this class opens with.
        $old = self::get($assetId);
        if ($old === null) {
            return null;
        }
        $oldToken = (string) ($old['publicToken'] ?? '');
        $newToken = self::generatePublicToken();

        $stmt = $db->prepare('UPDATE tblAssets SET publicToken = ? WHERE assetID = ? AND siteID = ?');
        if ($stmt === false) {
            error_log('AssetRegister::regeneratePublicToken() prepare failed: ' . $db->error);
            return null;
        }
        $stmt->bind_param('sii', $newToken, $assetId, $siteId);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok === false) {
            return null;
        }

        // 📜 Audit — entityType 'token' (NOT in TABLE_FOR_ENTITY, so no
        // tblAuditTrail mirror is attempted — same convention as tag.php's
        // own 'scan' event under this same entityType). self::audit()'s
        // buildChangeSet() redacts `publicToken` by FIELD NAME regardless
        // (REDACTED_FIELDS, class header point 1) — passing the raw
        // before/after token values here is safe; neither ever reaches a
        // log row in plaintext.
        self::audit('token', $assetId, $assetId, 'regenerate', ['publicToken' => $oldToken], ['publicToken' => $newToken]);

        return $newToken;
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
     * @param string      $actorType  'user' (default) | 'system' | 'public' | 'kiosk'
     * @param int|null    $actorUserIdOverride Explicit actor id (#414, Phase 3 Pass 5) — when
     *        non-null, ALWAYS used as actorUserID regardless of $actorType, bypassing the
     *        session-derived lookup below entirely. Lets a caller with no login session of its
     *        own (a public kiosk terminal, resolved to a real user via PIN — see
     *        `_apps/assets/kiosk-action.php`) still attribute the row to that resolved user
     *        rather than leaving actorUserID null.
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
        string $actorType = 'user',
        ?int $actorUserIdOverride = null
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
        if ($actorUserIdOverride !== null) {
            // 🎫 #414 override — a kiosk request has no $_SESSION['user_id']
            // of its own (see class header point 14), so the caller hands
            // us the PIN-resolved user id directly rather than relying on
            // the session-derived lookup below.
            $actorUserID = $actorUserIdOverride;
        } elseif ($actorType === 'user') {
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
     * their own yet (label generation, kiosk — later sub-issues) are
     * omitted on purpose; audit() simply skips the Logger::audit() call for
     * those (the tblAssetAudit row above still captures the action either
     * way). `'event-link'` USED to be one of those placeholders (see older
     * revisions of this comment) — #409 (Phase 2 Pass 2) gave it a real
     * backing table (`tblAssetEventAssignments`, migration 160), so it is
     * wired in below like every other entity with a table of its own.
     * `'stocktake'` was the same kind of placeholder until #411 (Phase 3
     * Pass 4) gave it `tblAssetStocktakes` — wired in below too. Its
     * per-scan child rows (`tblAssetStocktakeItems`) deliberately do NOT
     * get their own entry: every scan audit call uses action `'scan'`
     * (never create/update/delete), so this map is never consulted for
     * them — see startStocktake()/recordStocktakeScan()/closeStocktake()'s
     * own audit() calls for why that keeps the platform trail from being
     * flooded by high-volume scan events. `'kiosk'` was the LAST remaining
     * placeholder named in this comment's own older revisions — #414
     * (Phase 3 Pass 5) gives it `tblAssetKioskTokens` (terminal register/
     * revoke/reactivate/delete events), wired in below too. A kiosk
     * check-in/out itself is NOT an entityType of its own — it audits as
     * entityType `'loan'` (already mapped below) with `actorType: 'kiosk'`,
     * so those rows mirror into `tblAuditTrail` exactly like any other
     * loan checkout/checkin.
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
        'event-link'   => 'tblAssetEventAssignments',
        'stocktake'    => 'tblAssetStocktakes',
        'kiosk'        => 'tblAssetKioskTokens',
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
     * Salted SHA-256 of an arbitrary value, using the SAME per-install key
     * file `encrypt_setting()`/`decrypt_setting()` use (bootstrap.php,
     * `_auth_keys/enc.key`) as the salt — factored out of `ipHash()` (#404
     * Pass 2 / #410) so `ipHash()` AND the new `userAgentHash()` compute
     * their digests with the EXACT SAME scheme rather than two
     * independently-written (and liable to drift) implementations. Stable
     * for THIS install (lets an admin correlate repeat scans/reports from
     * the same visitor) but not reversible or comparable across installs —
     * the raw value passed in is NEVER stored anywhere.
     *
     * @return string 64-char hex SHA-256 digest
     */
    private static function saltedHash(string $value): string
    {
        $keyPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'enc.key';
        // 🛟 Fall back to the portal version string when the key file isn't
        //    readable (e.g. very early in the installer flow) — still a
        //    per-codebase-version salt rather than an unsalted hash, and
        //    this path should never be hit in a fully-installed portal.
        $salt = is_readable($keyPath) === true
            ? (string) file_get_contents($keyPath)
            : (defined('PORTAL_VERSION') ? (string) PORTAL_VERSION : 'webms-intra');
        return hash('sha256', $salt . '|' . $value);
    }

    /**
     * Salted SHA-256 of the client IP — see saltedHash() above for the
     * scheme. Output is BIT-IDENTICAL to this method's own pre-#410
     * implementation (the salt-loading logic simply moved into the shared
     * helper); every existing caller (createFoundReport(), audit()) is
     * unaffected.
     *
     * @return string 64-char hex SHA-256 digest
     */
    private static function ipHash(): string
    {
        return self::saltedHash(self::clientIp());
    }

    /**
     * Salted SHA-256 of the scanner's User-Agent header (#410) — same
     * saltedHash() scheme as ipHash() immediately above, so
     * `tblAssetScanLog.userAgentHash` is computed identically to
     * `tblAssetScanLog.ipHash`/`tblAssetAudit.ipHash`. A missing header
     * (some bots/tools omit it) hashes the empty string rather than being
     * skipped — recordScan() always writes a value into this NOT-quite-
     * mandatory-but-always-populated column.
     *
     * @return string 64-char hex SHA-256 digest
     */
    private static function userAgentHash(): string
    {
        return self::saltedHash((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    /**
     * Public-safe accessor for the private ipHash() above (#401). Exists
     * SOLELY so `_apps/assets/found-save.php` — the one caller in this
     * codebase that needs an ipHash outside this class, for its
     * `RateLimiter::tooMany()`/`recordHit()` bucket key — reuses the
     * EXACT SAME salted-SHA-256 computation the found-report row's own
     * `ipHash` column is populated with (via `createFoundReport()`
     * below), rather than a second, divergent implementation drifting out
     * of sync with this one over time.
     *
     * @return string 64-char hex SHA-256 digest — see ipHash()'s own doc
     */
    public static function publicIpHash(): string
    {
        return self::ipHash();
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
     * Row shape also carries `purchaseDate`/`purchaseCostPence` (added for
     * the register's CSV export — #403) alongside the display fields the
     * HTML index page already used; both are ordinary nullable columns, so
     * extending the SELECT here is safe for every existing caller.
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
             . '       a.purchaseDate, a.purchaseCostPence, '
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

    /**
     * Look up an existing, non-deleted asset on this site by an EXACT match
     * on `assetTagCode` OR `serialNumber` (whichever is supplied) — the
     * bulk CSV importer's (#407) one duplicate-detection primitive. A row
     * whose tag code collides with `uq_asset_tag` (siteID, assetTagCode)
     * would otherwise surface as an opaque `mysqli_sql_exception` from
     * `createAsset()` instead of a clear per-row "duplicate" dry-run
     * verdict; `serialNumber` has no DB-level uniqueness constraint at all
     * (see migration 159 — `idx_ast_serial` is a plain, non-unique index),
     * so this is the ONLY place that catches a serial-number collision
     * before import time.
     *
     * Tag code is checked FIRST (it's the actual UNIQUE-constrained
     * column, and cheapest to look up) — if both are supplied and only the
     * serial matches, that still counts as a duplicate: the caller only
     * needs "this row collides with an existing asset", not which column.
     *
     * @return array<string, mixed>|null The matching asset's assetID/name/
     *         assetTagCode/serialNumber, or null when both parameters are
     *         blank, or neither matches anything on this site.
     */
    public static function findByTagOrSerial(int $siteId, ?string $assetTagCode, ?string $serialNumber): ?array
    {
        $tag    = trim((string) $assetTagCode);
        $serial = trim((string) $serialNumber);
        if ($tag === '' && $serial === '') {
            return null;
        }

        $db = App::db();

        if ($tag !== '') {
            $stmt = $db->prepare(
                'SELECT assetID, name, assetTagCode, serialNumber FROM tblAssets '
                . 'WHERE siteID = ? AND isDeleted = 0 AND assetTagCode = ? LIMIT 1'
            );
            if ($stmt !== false) {
                $stmt->bind_param('is', $siteId, $tag);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row !== null) {
                    return $row;
                }
            }
        }

        if ($serial !== '') {
            $stmt = $db->prepare(
                'SELECT assetID, name, assetTagCode, serialNumber FROM tblAssets '
                . 'WHERE siteID = ? AND isDeleted = 0 AND serialNumber = ? LIMIT 1'
            );
            if ($stmt !== false) {
                $stmt->bind_param('is', $siteId, $serial);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row !== null) {
                    return $row;
                }
            }
        }

        return null;
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
     *   insurerName, insurancePolicyNumber, insuredValuePence (pence —
     *   caller converts pounds→pence, same house convention as
     *   purchaseCostPence/salvageValuePence), insuranceRenewalDate (#404
     *   columns, first persisted this pass — #408), isConfidential,
     *   publicPageEnabled, parentAssetID, labelSymbology (#404 — one of
     *   LABEL_SYMBOLOGIES; caller validates against that allow-list before
     *   ever reaching here, same contract as every other ENUM field in
     *   this list).
     *
     * Caller contract: every field must already be validated/coerced to its
     * correct PHP type (int|string|null, ENUM values checked against this
     * class's allow-list constants) — see `_apps/assets/save.php`, which is
     * one intended caller (the REST API's `_apps/assets/api/create.php` and
     * the bulk CSV importer's `_apps/assets/import.php` are the other two —
     * see `_apps/assets/api/_coerce.php` for the API's equivalent
     * coercion). This method does NOT re-validate ENUM/FK values; it only
     * handles persistence, token generation, encryption, and audit logging.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta Extra free-form context stored
     *        verbatim in `tblAssetAudit.meta` (JSON) — e.g. the bulk CSV
     *        importer (#407) passes `['importBatch' => $batchId]` so every
     *        asset a single import created can be traced back to that
     *        upload. Empty by default — every other caller (save.php, the
     *        REST API) leaves this unset.
     *
     * @return int New assetID, or 0 on failure
     */
    public static function createAsset(array $data, int $actorUserId, array $meta = []): int
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
            // 🛡️ Insurance (#404 columns, first persisted this pass — #408).
            // Same "caller already validated/coerced" contract as every
            // other field in this method — save.php converts pounds→pence
            // and validates the date before this array is ever built.
            'insurerName'           => [$data['insurerName'] ?? null, 's'],
            'insurancePolicyNumber' => [$data['insurancePolicyNumber'] ?? null, 's'],
            'insuredValuePence'     => [$data['insuredValuePence'] ?? null, 'i'],
            'insuranceRenewalDate'  => [$data['insuranceRenewalDate'] ?? null, 's'],
            'isConfidential'     => [(int) ($data['isConfidential'] ?? 0), 'i'],
            'publicToken'        => [$publicToken, 's'],
            'publicPageEnabled'  => [(int) ($data['publicPageEnabled'] ?? 1), 'i'],
            'labelSymbology'     => [(string) ($data['labelSymbology'] ?? 'qr'), 's'],
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

        self::audit('asset', $newId, $newId, 'create', null, $auditNew, $meta);

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
            // 🛡️ Insurance (#404 columns, first persisted this pass — #408)
            // — same doc as createAsset()'s matching block above.
            'insurerName'           => [$data['insurerName'] ?? null, 's'],
            'insurancePolicyNumber' => [$data['insurancePolicyNumber'] ?? null, 's'],
            'insuredValuePence'     => [$data['insuredValuePence'] ?? null, 'i'],
            'insuranceRenewalDate'  => [$data['insuranceRenewalDate'] ?? null, 's'],
            'isConfidential'     => [(int) ($data['isConfidential'] ?? 0), 'i'],
            'publicPageEnabled'  => [(int) ($data['publicPageEnabled'] ?? 0), 'i'],
            'labelSymbology'     => [(string) ($data['labelSymbology'] ?? $old['labelSymbology']), 's'],
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

            // 🧰 Kit cascade (#413) — a TOP-LEVEL checkout (this loan's own
            // parentLoanID is null) of an asset that has kit components
            // also sweeps every eligible, currently-available component
            // into its own chained active loan (parentLoanID = this
            // loan's id). A CHILD loan's own checkout never re-cascades —
            // the guard below is what stops that, since a swept-in child
            // loan always carries a non-null parentLoanID. Inside this
            // same transaction so a failure here rolls back the parent's
            // own checkout too — see cascadeKitCheckout()'s own doc.
            $kitSummary = ['swept' => [], 'skipped' => []];
            if ($loan['parentLoanID'] === null) {
                $kitSummary = self::cascadeKitCheckout($loan, $newAssetStatus, $actorUserId);
            }

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

        // 📣 Augment the friendly message with what the kit cascade above
        // actually did — never changes ok=true, purely informational.
        $msg = 'Loan checked out — condition and dates recorded.';
        if (count($kitSummary['swept']) > 0) {
            $msg .= ' ' . count($kitSummary['swept']) . ' kit component(s) checked out too.';
            if (count($kitSummary['skipped']) > 0) {
                $msg .= ' (skipped: ' . implode(', ', $kitSummary['skipped']) . ')';
            }
        }

        return ['ok' => true, 'msg' => $msg];
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

            // 🧰 Kit cascade (#413) — a TOP-LEVEL checkin (this loan's own
            // parentLoanID is null) of a kit parent also checks in every
            // one of its still-active swept-in component loans. A CHILD
            // loan's own checkin never re-cascades, for the same reason as
            // loanCheckout()'s mirror-image guard above. `childConditions`
            // (an optional array<int assetID, string condition> posted by
            // item.php's checkin form) lets the person checking the kit in
            // record a DIFFERENT return condition per component; any
            // component left unspecified falls back to the parent's own
            // $conditionIn — see cascadeKitCheckin()'s own doc.
            $kitReturned = ['returned' => []];
            if ($loan['parentLoanID'] === null) {
                $childConditions = is_array($data['childConditions'] ?? null) ? $data['childConditions'] : [];
                $kitReturned = self::cascadeKitCheckin($loan, $conditionIn, $childConditions);
            }

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

        // 📣 Augment the friendly message with what the kit cascade above
        // actually did — never changes ok=true, purely informational.
        $msg = 'Loan checked in — asset marked in-service.';
        if (count($kitReturned['returned']) > 0) {
            $msg .= ' ' . count($kitReturned['returned']) . ' kit component(s) returned too.';
        }

        return ['ok' => true, 'msg' => $msg];
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
     * 🧰 Parent/child asset kits + kit-aware loans (#413, Phase 3 Pass 3)
     * ------------------------------------------------------------------------
     * `tblAssets.parentAssetID` (a self-FK, pre-provisioned back in
     * migration 159 — see that migration's `fk_asset_parent` constraint)
     * lets one asset be flagged as a COMPONENT of another — a camera body
     * is the parent "kit" asset, its lens/battery/case are children. Kits
     * are deliberately ONE level deep only: a child can never itself be a
     * parent (enforced in `attachToKit()` below), so there is no recursive
     * tree to walk anywhere in this section — every read here is a single
     * flat `WHERE parentAssetID = ?` or `WHERE parentAssetID IS NULL`.
     *
     * `attachToKit()`/`detachFromKit()` are the ONLY supported way to
     * mutate `parentAssetID` post-creation (creation-time assignment via
     * `createAsset()`'s own `parentAssetID` field, #394, is untouched by
     * this pass) — both route through `self::audit()` (entityType
     * 'asset', action 'update', matching `updateOwnershipTerms()`'s own
     * convention for a single-column asset mutation that isn't the whole-
     * record `updateAsset()` path).
     *
     * The kit-AWARE LOAN behaviour — `cascadeKitCheckout()`/
     * `cascadeKitCheckin()` — is the more consequential half: loaning a
     * kit's PARENT asset out (or receiving it back) implicitly sweeps
     * every eligible component along with it, via its OWN chained loan
     * row (`tblAssetLoans.parentLoanID`, also pre-provisioned in migration
     * 159). Both cascade helpers are called from INSIDE loanCheckout()'s/
     * loanCheckin()'s own `App::beginTransaction()`/`commit()`/
     * `rollback()` block — see this class's header comment (point 5) for
     * why a mid-cascade failure must never leave a kit's parent loan
     * checked-out/in while its components silently didn't follow, or vice
     * versa. Both THROW `\RuntimeException` on any prepare/execute
     * failure (rather than returning a soft failure the caller might
     * ignore) specifically so that shared catch block rolls back the
     * WHOLE transaction, parent included.
     *
     * The `$loan['parentLoanID'] === null` guard inside loanCheckout()/
     * loanCheckin() (immediately before each cascade call) is what stops
     * infinite/re-entrant cascading — a swept-in CHILD loan always has a
     * non-null parentLoanID, so checking a child loan out/in on its own
     * (e.g. from item.php's Loans panel on the child asset's own page)
     * never itself tries to sweep further components. Kits being one
     * level deep (see above) means this single boolean check is sufficient
     * — there is no deeper chain to guard against.
     * ======================================================================== */

    /**
     * List the direct component ("child") assets currently attached to a
     * parent kit asset. Site-scoped via the caller-supplied `$siteId`
     * (not `Site::id()`) so a caller that already has the parent's own
     * site on hand (e.g. `cascadeKitCheckout()`, working from an
     * already-loaded loan row) never pays for a redundant lookup —
     * mirrors `listLoans()`'s own caller-supplied-`$siteId` convention.
     *
     * @return array<int, array<string, mixed>> assetID/name/assetTagCode/
     *         status/conditionState rows, empty when the parent has no
     *         components (or doesn't exist/isn't on this site).
     */
    public static function kitChildren(int $parentAssetId, int $siteId): array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT assetID, name, assetTagCode, status, conditionState FROM tblAssets '
            . 'WHERE parentAssetID = ? AND siteID = ? AND isDeleted = 0 ORDER BY name ASC'
        );
        if ($stmt === false) {
            error_log('AssetRegister::kitChildren() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('ii', $parentAssetId, $siteId);
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
     * Assets on this site that MAY be added as a component of the given
     * parent — i.e. everything EXCEPT: the parent asset itself, anything
     * already a component of ANY kit (`parentAssetID IS NOT NULL`),
     * anything that is ITSELF already a kit parent (kits are one level
     * deep — see this section's header note), and anything currently on
     * an unresolved loan (`LOAN_OPEN_STATUSES` — adding a mid-loan asset
     * to a kit would leave its own loan row orphaned from the kit
     * relationship it's about to join). Feeds the "Add component" picker
     * on item.php; deliberately excludes deleted/other-site rows the same
     * way `self::get()` does.
     *
     * @return array<int, array<string, mixed>> assetID/name/assetTagCode/
     *         status rows, ordered by name.
     */
    public static function eligibleKitChildCandidates(int $parentAssetId, int $siteId): array
    {
        $db = App::db();

        // 🚦 Open-loan placeholder list built from LOAN_OPEN_STATUSES, not
        // hard-coded — mirrors createLoanRequest()'s own probe (see this
        // class's header comment for the house convention this follows).
        $openPlaceholders = implode(', ', array_fill(0, count(self::LOAN_OPEN_STATUSES), '?'));

        $sql = 'SELECT assetID, name, assetTagCode, status FROM tblAssets '
             . 'WHERE siteID = ? AND isDeleted = 0 AND assetID <> ? AND parentAssetID IS NULL '
             . 'AND assetID NOT IN ('
             . '    SELECT DISTINCT parentAssetID FROM tblAssets WHERE parentAssetID IS NOT NULL AND siteID = ?'
             . ') '
             . 'AND assetID NOT IN ('
             . '    SELECT assetID FROM tblAssetLoans WHERE siteID = ? AND status IN (' . $openPlaceholders . ')'
             . ') '
             . 'ORDER BY name ASC';

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            error_log('AssetRegister::eligibleKitChildCandidates() prepare failed: ' . $db->error);
            return [];
        }
        // 🔢 FOUR bound integers precede the status strings: the outer
        // siteID + assetID, then the siteID inside EACH of the two
        // NOT IN sub-queries — so the type string is 'iiii', not 'iii'
        // (a 3-i string would leave bind_param one variable short of the
        // seven placeholders and fail at runtime).
        $types = 'iiii' . str_repeat('s', count(self::LOAN_OPEN_STATUSES));
        $stmt->bind_param($types, $siteId, $parentAssetId, $siteId, $siteId, ...self::LOAN_OPEN_STATUSES);
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
     * Attach `$childAssetId` as a component of `$parentAssetId`'s kit.
     * Never fatals — every guard below returns `ok=false` with a message
     * fit to flash straight back to the user; only an actual DB failure
     * (prepare returning false) logs via `error_log()` first. Guards, in
     * order (see this section's header note for the one-level-deep and
     * open-loan rationale):
     *   1. both assets exist on THIS site (`self::get()` is itself
     *      site-scoped via `Site::id()` — a cross-site or missing id
     *      fails here with the same message as "doesn't exist", never
     *      leaking which case it was).
     *   2. child !== parent.
     *   3. the parent is not itself a component of another kit.
     *   4. the child does not itself already have components (would
     *      create a two-level kit).
     *   5. the child has no existing parent (must be detached first).
     *   6. the child has no open loan (`LOAN_OPEN_STATUSES`).
     *
     * The final UPDATE's `AND parentAssetID IS NULL` clause is the
     * race-safety net — even if two concurrent requests both pass every
     * guard above (read-then-write race), only the FIRST write actually
     * lands; the second affects 0 rows and reports the generic "may have
     * changed" failure rather than silently overwriting a parent another
     * request just set.
     *
     * @return array{ok: bool, msg: string}
     */
    public static function attachToKit(int $childAssetId, int $parentAssetId, int $actorUserId): array
    {
        $child = self::get($childAssetId);
        if ($child === null) {
            return ['ok' => false, 'msg' => 'Component asset not found.'];
        }
        $parent = self::get($parentAssetId);
        if ($parent === null) {
            return ['ok' => false, 'msg' => 'Parent (kit) asset not found.'];
        }
        if ($childAssetId === $parentAssetId) {
            return ['ok' => false, 'msg' => 'An asset cannot be a component of itself.'];
        }
        if ($parent['parentAssetID'] !== null) {
            return ['ok' => false, 'msg' => 'The chosen parent is itself a component of another kit — kits are only one level deep.'];
        }

        $siteId = Site::id();

        if (count(self::kitChildren($childAssetId, $siteId)) > 0) {
            return ['ok' => false, 'msg' => "That asset already has its own components, so it can't become a component of another kit."];
        }
        if ($child['parentAssetID'] !== null) {
            return ['ok' => false, 'msg' => 'That asset is already part of a kit — detach it first.'];
        }

        $db = App::db();

        // 🚦 No open loan on the child — mirrors createLoanRequest()'s own
        // probe (LOAN_OPEN_STATUSES), same rationale as this section's
        // header note.
        $openPlaceholders = implode(', ', array_fill(0, count(self::LOAN_OPEN_STATUSES), '?'));
        $openStmt = $db->prepare(
            'SELECT 1 FROM tblAssetLoans WHERE assetID = ? AND siteID = ? AND status IN (' . $openPlaceholders . ') LIMIT 1'
        );
        if ($openStmt !== false) {
            $openTypes = 'ii' . str_repeat('s', count(self::LOAN_OPEN_STATUSES));
            $openStmt->bind_param($openTypes, $childAssetId, $siteId, ...self::LOAN_OPEN_STATUSES);
            $openStmt->execute();
            $hasOpenLoan = $openStmt->get_result()->fetch_assoc() !== null;
            $openStmt->close();
            if ($hasOpenLoan === true) {
                return ['ok' => false, 'msg' => "That asset has an open loan and can't be added to a kit right now."];
            }
        }

        // 🔒 Narrow, race-safe UPDATE — see method doc.
        $stmt = $db->prepare(
            'UPDATE tblAssets SET parentAssetID = ? WHERE assetID = ? AND siteID = ? AND parentAssetID IS NULL'
        );
        if ($stmt === false) {
            error_log('AssetRegister::attachToKit() prepare failed: ' . $db->error);
            return ['ok' => false, 'msg' => 'Could not attach that component — please try again.'];
        }
        $stmt->bind_param('iii', $parentAssetId, $childAssetId, $siteId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected <= 0) {
            return ['ok' => false, 'msg' => 'Could not attach that component — it may have changed since you loaded this page.'];
        }

        self::audit('asset', $childAssetId, $childAssetId, 'update', ['parentAssetID' => null], ['parentAssetID' => $parentAssetId]);

        return ['ok' => true, 'msg' => 'Component added to the kit.'];
    }

    /**
     * Detach `$childAssetId` from whichever kit it currently belongs to.
     * Never fatals — same "return ok=false with a friendly message"
     * contract as `attachToKit()`. Guards:
     *   1. the asset exists on this site.
     *   2. it currently HAS a parent (nothing to detach otherwise).
     *   3. it has no open loan — a component swept into an active kit
     *      loan (`parentLoanID` chained to the parent's own loan) can't be
     *      silently detached out from under that in-flight loan; the loan
     *      must be checked in (via the normal cascade) first.
     *
     * The final UPDATE's `AND parentAssetID IS NOT NULL` clause is the
     * same race-safety net `attachToKit()`'s own UPDATE uses, mirrored.
     *
     * @return array{ok: bool, msg: string}
     */
    public static function detachFromKit(int $childAssetId, int $actorUserId): array
    {
        $child = self::get($childAssetId);
        if ($child === null) {
            return ['ok' => false, 'msg' => 'Component asset not found.'];
        }
        if ($child['parentAssetID'] === null) {
            return ['ok' => false, 'msg' => "That asset isn't part of a kit."];
        }

        $siteId = Site::id();
        $db = App::db();

        // 🚦 Same open-loan guard as attachToKit() — see method doc.
        $openPlaceholders = implode(', ', array_fill(0, count(self::LOAN_OPEN_STATUSES), '?'));
        $openStmt = $db->prepare(
            'SELECT 1 FROM tblAssetLoans WHERE assetID = ? AND siteID = ? AND status IN (' . $openPlaceholders . ') LIMIT 1'
        );
        if ($openStmt !== false) {
            $openTypes = 'ii' . str_repeat('s', count(self::LOAN_OPEN_STATUSES));
            $openStmt->bind_param($openTypes, $childAssetId, $siteId, ...self::LOAN_OPEN_STATUSES);
            $openStmt->execute();
            $hasOpenLoan = $openStmt->get_result()->fetch_assoc() !== null;
            $openStmt->close();
            if ($hasOpenLoan === true) {
                return ['ok' => false, 'msg' => "That asset has an open loan and can't be detached right now."];
            }
        }

        $oldParentId = (int) $child['parentAssetID'];

        $stmt = $db->prepare(
            'UPDATE tblAssets SET parentAssetID = NULL WHERE assetID = ? AND siteID = ? AND parentAssetID IS NOT NULL'
        );
        if ($stmt === false) {
            error_log('AssetRegister::detachFromKit() prepare failed: ' . $db->error);
            return ['ok' => false, 'msg' => 'Could not detach that component — please try again.'];
        }
        $stmt->bind_param('ii', $childAssetId, $siteId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected <= 0) {
            return ['ok' => false, 'msg' => 'Could not detach that component — it may have changed since you loaded this page.'];
        }

        self::audit('asset', $childAssetId, $childAssetId, 'update', ['parentAssetID' => $oldParentId], ['parentAssetID' => null]);

        return ['ok' => true, 'msg' => 'Component detached from the kit.'];
    }

    /**
     * Child loans currently swept under a parent kit loan — i.e. rows
     * chained via `parentLoanID` that are still `active`. Feeds the
     * checkin-time "N component(s) will be checked in too" prompt on
     * item.php/loans.php; also the read half `cascadeKitCheckin()` itself
     * drives from below. Deliberately narrowed to `status = 'active'`
     * (not every child loan ever chained to this parent) — a component
     * that was independently checked in early (see `cascadeKitCheckin()`'s
     * own "already returned independently" note) has nothing left to
     * prompt for.
     *
     * @return array<int, array<string, mixed>> loanID/assetID/status/
     *         assetName/assetTagCode rows, ordered by asset name.
     */
    public static function activeKitChildLoans(int $parentLoanId, int $siteId): array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT l.loanID, l.assetID, l.status, a.name AS assetName, a.assetTagCode '
            . 'FROM tblAssetLoans l INNER JOIN tblAssets a ON a.assetID = l.assetID '
            . "WHERE l.parentLoanID = ? AND l.siteID = ? AND l.status = 'active' ORDER BY a.name ASC"
        );
        if ($stmt === false) {
            error_log('AssetRegister::activeKitChildLoans() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('ii', $parentLoanId, $siteId);
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
     * Sweep a kit parent's eligible components into their own chained
     * `active` loan rows at the moment the PARENT loan is checked out.
     * Called from INSIDE `loanCheckout()`'s own transaction (after its
     * `tblAssets` status UPDATE, before `App::commit()`) — see this
     * section's header note for why every DB failure here THROWS rather
     * than returning a soft failure: it must roll back the parent's own
     * checkout too, not just silently leave some components un-swept.
     *
     * Per component (`self::kitChildren($parentAssetId, $siteId)`):
     *   - SKIPPED (not an error) when the component's own `status` isn't
     *     `in-service`/`in-storage` (reason "not available" — a retired,
     *     already-on-loan, or in-repair component can't be handed over
     *     alongside its kit) or it already has an open loan of its own
     *     (reason "already on loan" — re-checked here rather than trusted
     *     from `kitChildren()`'s status column alone, since that column
     *     can lag a loan row created moments earlier by a concurrent
     *     request).
     *   - Otherwise, a new `tblAssetLoans` row is INSERTed copying the
     *     parent loan's own direction/counterparty/condition-out/due-date
     *     fields verbatim (see class-level note below on why this is the
     *     parent row's ORIGINALLY-LOADED values, not any override the
     *     checkout form supplied this same call), chained via
     *     `parentLoanID`, `status='active'` immediately (no separate
     *     request/approve step for a swept-in component — the parent loan
     *     already carries that authority), and the component's own
     *     `tblAssets.status` is updated to match the parent's new
     *     `$newAssetStatus`.
     *
     * NOTE: `conditionOut`/`conditionOutNotes`/`dueDate` are read from
     * `$parentLoan` (the row `loanAction()` loaded BEFORE this checkout's
     * own UPDATE ran) — if the checkout form supplied an OVERRIDE for the
     * parent's own condition-at-hand-over, that override is NOT re-read
     * back out for the components; they inherit whatever was recorded at
     * REQUEST time. This mirrors the orchestrator's spec verbatim (see
     * PR #413 design notes) — flagged here rather than silently changed,
     * since a future pass may want the components to inherit the
     * confirmed/overridden value instead.
     *
     * @param array<string, mixed> $parentLoan The parent's own freshly
     *        (pre-UPDATE) loaded loan row, exactly as loanCheckout()
     *        received it.
     *
     * @return array{swept: string[], skipped: string[]} Component asset
     *         names actually swept in, and names+reason for any skipped.
     */
    private static function cascadeKitCheckout(array $parentLoan, string $newAssetStatus, int $actorUserId): array
    {
        $db            = App::db();
        $parentAssetId = (int) $parentLoan['assetID'];
        $siteId        = (int) $parentLoan['siteID'];
        $parentLoanId  = (int) $parentLoan['loanID'];

        $swept   = [];
        $skipped = [];

        $openPlaceholders = implode(', ', array_fill(0, count(self::LOAN_OPEN_STATUSES), '?'));

        foreach (self::kitChildren($parentAssetId, $siteId) as $child) {
            $childAssetId = (int) $child['assetID'];
            $childName    = (string) $child['name'];
            $childStatus  = (string) $child['status'];

            if (in_array($childStatus, ['in-service', 'in-storage'], true) === false) {
                $skipped[] = $childName . ' (not available)';
                continue;
            }

            // 🚦 Re-check no open loan — see method doc on why kitChildren()'s
            // own status column alone isn't trusted for this.
            $openStmt = $db->prepare(
                'SELECT 1 FROM tblAssetLoans WHERE assetID = ? AND siteID = ? AND status IN (' . $openPlaceholders . ') LIMIT 1'
            );
            if ($openStmt === false) {
                throw new \RuntimeException('cascadeKitCheckout() prepare (open-loan probe) failed: ' . $db->error);
            }
            $openTypes = 'ii' . str_repeat('s', count(self::LOAN_OPEN_STATUSES));
            $openStmt->bind_param($openTypes, $childAssetId, $siteId, ...self::LOAN_OPEN_STATUSES);
            $openStmt->execute();
            $hasOpenLoan = $openStmt->get_result()->fetch_assoc() !== null;
            $openStmt->close();
            if ($hasOpenLoan === true) {
                $skipped[] = $childName . ' (already on loan)';
                continue;
            }

            // 📋 Copy the parent loan's shape via splitFields() (this
            // class's own table-driven column/type/param builder — see
            // that helper's doc for why a hand-counted bind_param() type
            // string is the bug this avoids), same convention
            // createLoanRequest() uses for its own INSERT.
            $fields = [
                'siteID'               => [$siteId, 'i'],
                'assetID'              => [$childAssetId, 'i'],
                'direction'            => [(string) $parentLoan['direction'], 's'],
                'counterpartyType'     => [(string) $parentLoan['counterpartyType'], 's'],
                'counterpartyUserID'   => [$parentLoan['counterpartyUserID'] !== null ? (int) $parentLoan['counterpartyUserID'] : null, 'i'],
                'counterpartyOrgID'    => [$parentLoan['counterpartyOrgID'] !== null ? (int) $parentLoan['counterpartyOrgID'] : null, 'i'],
                'counterpartyName'     => [$parentLoan['counterpartyName'], 's'],
                'counterpartyContact'  => [$parentLoan['counterpartyContact'], 's'],
                'status'               => ['active', 's'],
                'approvedByID'         => [$actorUserId, 'i'],
                'conditionOut'         => [$parentLoan['conditionOut'], 's'],
                'conditionOutNotes'    => [$parentLoan['conditionOutNotes'], 's'],
                'dueDate'              => [$parentLoan['dueDate'], 's'],
                'parentLoanID'         => [$parentLoanId, 'i'],
                'requestedByID'        => [(int) $parentLoan['requestedByID'], 'i'],
                'notes'                => ['Auto-added as part of kit loan #' . $parentLoanId, 's'],
            ];
            ['columns' => $columns, 'types' => $types, 'params' => $params] = self::splitFields($fields);

            // 🕒 approvedAt/dateOut are inline NOW() literals, appended
            // after the bound columns — not user data, so no placeholder
            // needed (same house convention loanCheckout()'s own UPDATE
            // uses for these two columns).
            $insertSql = 'INSERT INTO tblAssetLoans (`' . implode('`, `', $columns) . '`, `approvedAt`, `dateOut`) '
                       . 'VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ', NOW(), NOW())';
            $insertStmt = $db->prepare($insertSql);
            if ($insertStmt === false) {
                throw new \RuntimeException('cascadeKitCheckout() prepare (child loan insert) failed: ' . $db->error);
            }
            $insertStmt->bind_param($types, ...$params);
            $insertStmt->execute();
            $childLoanId = (int) $insertStmt->insert_id;
            $insertStmt->close();
            if ($childLoanId <= 0) {
                throw new \RuntimeException('cascadeKitCheckout() insert produced no id for child asset #' . $childAssetId);
            }

            $childAssetStmt = $db->prepare('UPDATE tblAssets SET status = ? WHERE assetID = ? AND siteID = ?');
            if ($childAssetStmt === false) {
                throw new \RuntimeException('cascadeKitCheckout() prepare (child asset status) failed: ' . $db->error);
            }
            $childAssetStmt->bind_param('sii', $newAssetStatus, $childAssetId, $siteId);
            $childAssetStmt->execute();
            $childAssetStmt->close();

            self::audit(
                'loan',
                $childLoanId,
                $childAssetId,
                'checkout',
                null,
                ['status' => 'active', 'parentLoanID' => $parentLoanId, 'assetStatus' => $newAssetStatus]
            );

            $swept[] = $childName;
        }

        return ['swept' => $swept, 'skipped' => $skipped];
    }

    /**
     * Check in every still-`active` component swept in under a kit
     * parent's loan, at the moment the PARENT loan is checked in. Called
     * from INSIDE `loanCheckin()`'s own transaction (after its
     * `tblAssets` status UPDATE, before `App::commit()`) — throws on any
     * DB failure for the same "roll back the whole cascade" reason as
     * `cascadeKitCheckout()`.
     *
     * Per active child loan (`self::activeKitChildLoans()`):
     *   - resolves ITS OWN return condition from `$childConditions`
     *     (keyed by assetID — posted by item.php's per-component checkin
     *     select) when supplied and valid, else falls back to the
     *     parent's own (already-validated) `$conditionIn`.
     *   - the loan UPDATE is WHERE-guarded to `status = 'active'` — if 0
     *     rows are affected the component must already have been checked
     *     in independently (e.g. a manager checked that one child in on
     *     its own page moments earlier); that's NOT an error, this method
     *     simply moves on to the next component rather than throwing.
     *   - on an actual update, the component's own `tblAssets.status`
     *     resets to `in-service` and `conditionState` picks up its
     *     resolved condition, mirroring `loanCheckin()`'s own parent-asset
     *     update exactly.
     *
     * @param array<string, mixed> $parentLoan       The parent's own
     *        freshly-loaded loan row, exactly as loanCheckin() received
     *        it.
     * @param string                $conditionIn      The parent's own
     *        already-validated return condition (fallback default).
     * @param array<int, string>    $childConditions  Optional per-child
     *        overrides, assetID => one of CONDITION_STATES.
     *
     * @return array{returned: string[]} Component asset names actually
     *         checked in by this cascade.
     */
    private static function cascadeKitCheckin(array $parentLoan, string $conditionIn, array $childConditions): array
    {
        $db           = App::db();
        $parentLoanId = (int) $parentLoan['loanID'];
        $siteId       = (int) $parentLoan['siteID'];

        $returned = [];

        foreach (self::activeKitChildLoans($parentLoanId, $siteId) as $childLoan) {
            $childLoanId  = (int) $childLoan['loanID'];
            $childAssetId = (int) $childLoan['assetID'];
            $childName    = (string) $childLoan['assetName'];

            // 🎨 Per-component override, falling back to the parent's own
            // (already-validated) condition — see method doc.
            $cond = $childConditions[$childAssetId] ?? $conditionIn;
            if (in_array($cond, self::CONDITION_STATES, true) === false) {
                $cond = $conditionIn;
            }

            $stmt = $db->prepare(
                "UPDATE tblAssetLoans SET status = 'returned', dateIn = NOW(), conditionIn = ? "
                . " WHERE loanID = ? AND siteID = ? AND status = 'active'"
            );
            if ($stmt === false) {
                throw new \RuntimeException('cascadeKitCheckin() prepare (loan) failed: ' . $db->error);
            }
            $stmt->bind_param('sii', $cond, $childLoanId, $siteId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected <= 0) {
                // ⏭️ Already returned independently — see method doc, not
                // an error condition.
                continue;
            }

            $assetStmt = $db->prepare('UPDATE tblAssets SET status = ?, conditionState = ? WHERE assetID = ? AND siteID = ?');
            if ($assetStmt === false) {
                throw new \RuntimeException('cascadeKitCheckin() prepare (asset) failed: ' . $db->error);
            }
            $inService = 'in-service';
            $assetStmt->bind_param('ssii', $inService, $cond, $childAssetId, $siteId);
            $assetStmt->execute();
            $assetStmt->close();

            self::audit(
                'loan',
                $childLoanId,
                $childAssetId,
                'checkin',
                ['status' => 'active'],
                ['status' => 'returned', 'conditionIn' => $cond, 'assetStatus' => 'in-service']
            );

            $returned[] = $childName;
        }

        return ['returned' => $returned];
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
     * Validate + normalise an optional DATETIME string for
     * assignToEvent()'s `assignedFrom`/`assignedUntil` (#409). Same
     * null/string/false contract as parseOptionalDate() above, but for a
     * DATETIME column rather than a DATE one, and accepts EITHER shape a
     * caller might hand it:
     *   - 'Y-m-d\TH:i[:s]' — what a browser's <input type="datetime-local">
     *     posts (the 'T' separator).
     *   - 'Y-m-d H:i[:s]'  — a plain space-separated value (e.g. a future
     *     API caller, or a value round-tripped from this same column).
     * Always returns the normalised 'Y-m-d H:i:s' shape MySQL's DATETIME
     * columns expect (never the 'T'-separated one — MySQL does not accept
     * that literal), so callers never insert an un-normalised value.
     *
     * @return string|false|null
     */
    private static function parseOptionalDateTime(mixed $raw): string|false|null
    {
        $value = trim((string) ($raw ?? ''));
        if ($value === '') {
            return null;
        }
        // 🌐 Normalise the datetime-local 'T' separator to a space BEFORE
        // parsing, so both accepted shapes above funnel through the same
        // two-format attempt below.
        $normalised = str_replace('T', ' ', $value);
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            $parsed = \DateTime::createFromFormat($format, $normalised);
            if ($parsed !== false && $parsed->format($format) === $normalised) {
                return $parsed->format('Y-m-d H:i:s');
            }
        }
        return false;
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

    /**
     * Pure reducing-balance (declining-balance) depreciation estimate — NO
     * DATABASE ACCESS, same contract shape as computeStraightLineValue()
     * immediately above (same input array, same "null means not computable,
     * NEVER invent a value" rule). Added for #412 Phase 3 Pass 2.
     *
     * THE RATE PROBLEM: a textbook reducing-balance schedule applies a
     * fixed annual/monthly PERCENTAGE rate — but `tblAssets` has no
     * `depreciationRate` column (see class header / #412 brief) and never
     * has. Given only cost, salvage and useful life, the only mathematically
     * defensible rate is the one that is IMPLIED by requiring the schedule
     * to land exactly on `salvageValuePence` at the end of
     * `usefulLifeMonths` — i.e. a constant-percentage GEOMETRIC decay from
     * cost to salvage:
     *
     *     value(elapsed) = cost × (salvage / cost) ^ (min(elapsed, life) / life)
     *
     * This is the standard "declining-balance with a target residual value"
     * construction (solve r such that cost × (1 − r)^life = salvage), just
     * expressed directly in terms of the salvage ratio rather than backing
     * out an explicit r first — same answer, fewer intermediate roundings.
     * At elapsed = 0 this is cost (the base of the exponent to the power
     * 0 = 1); at elapsed = life it is exactly salvage; in between it curves
     * — losing more value in early months than a straight-line schedule
     * would, which is the entire point of choosing reducing-balance over
     * straight-line for a given asset.
     *
     * WHY A ZERO/ABSENT SALVAGE IS REJECTED (the key difference from
     * computeStraightLineValue(), which happily defaults salvage to 0):
     * the formula above divides by `purchaseCostPence` inside the ratio and
     * raises it to a fractional power — with `salvage = 0` the ratio is 0
     * and the "implied rate" is 100% in month one (the curve would already
     * be at zero the instant elapsed > 0), which is not a reducing-balance
     * schedule at all, it is a cliff. A reducing-balance asset therefore
     * REQUIRES a positive salvage floor to be computable; one with no
     * salvage recorded is correctly "not computable yet", not "worth
     * nothing" — the caller must never invent the missing input.
     *
     * Returns null when:
     *   - depreciationMethod !== 'reducing-balance', OR
     *   - purchaseCostPence is missing or <= 0 (a zero-cost asset has no
     *     ratio to decay along), OR
     *   - usefulLifeMonths is missing or <= 0, OR
     *   - purchaseDate is missing/empty/unparseable, OR
     *   - salvageValuePence is missing or <= 0 (see above — the degenerate
     *     case straight-line silently tolerates, reducing-balance cannot).
     *
     * Clamps (mirrors computeStraightLineValue() exactly, see its own doc
     * for the full reasoning on each):
     *   - a salvageValuePence ABOVE purchaseCostPence is clamped down to
     *     purchaseCostPence first, so the curve can never appreciate;
     *   - elapsed WHOLE calendar months use the identical
     *     DateTime::diff()-based `($diff->y * 12) + $diff->m` logic,
     *     floored at 0 for an $asOfDate before purchaseDate;
     *   - the exponent's numerator is capped at usefulLifeMonths, so a
     *     fully-depreciated asset sits at salvage forever after;
     *   - a final belt-and-braces clamp of the rounded result back into
     *     [salvageValuePence, purchaseCostPence] guards against float
     *     drift from `**`/pow() before the cast to int.
     *
     * @param array<string, mixed> $asset
     *
     * @return int|null Estimated current value in pence, or null when not
     *                   computable / method isn't 'reducing-balance'
     */
    public static function computeReducingBalanceValue(array $asset, ?string $asOfDate = null): ?int
    {
        if ((string) ($asset['depreciationMethod'] ?? 'none') !== 'reducing-balance') {
            return null;
        }

        $purchaseCostPenceRaw = $asset['purchaseCostPence'] ?? null;
        $usefulLifeMonthsRaw  = $asset['usefulLifeMonths'] ?? null;
        $purchaseDateRaw      = $asset['purchaseDate'] ?? null;
        $salvageValuePenceRaw = $asset['salvageValuePence'] ?? null;

        if ($purchaseCostPenceRaw === null || $usefulLifeMonthsRaw === null
            || $purchaseDateRaw === null || (string) $purchaseDateRaw === ''
            || $salvageValuePenceRaw === null
        ) {
            return null;
        }

        $purchaseCostPence = (int) $purchaseCostPenceRaw;
        $usefulLifeMonths  = (int) $usefulLifeMonthsRaw;
        $salvageValuePence = (int) $salvageValuePenceRaw;

        // 🛟 Degenerate inputs — see method doc's "WHY A ZERO/ABSENT
        // SALVAGE IS REJECTED" paragraph. Never invent a rate.
        if ($purchaseCostPence <= 0 || $usefulLifeMonths <= 0 || $salvageValuePence <= 0) {
            return null;
        }

        // 🛟 A mis-entered salvage value ABOVE the purchase cost would
        // otherwise make the ratio (salvage / cost) exceed 1 and the curve
        // would appreciate upward — clamp it down first, same as
        // computeStraightLineValue()'s own guard.
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

        // 📅 Elapsed WHOLE calendar months since purchase — identical logic
        // to computeStraightLineValue(), floored at 0 for an $asOf before
        // purchaseDate (never negative elapsed time).
        $elapsedMonths = 0;
        if ($asOf >= $purchaseDate) {
            $diff = $purchaseDate->diff($asOf);
            $elapsedMonths = ($diff->y * 12) + $diff->m;
        }

        // 🔒 Cap the exponent at the useful life — once fully depreciated
        // the value sits at salvage and never falls further.
        $depreciableMonths = min($elapsedMonths, $usefulLifeMonths);

        // 📉 Constant-percentage geometric decay from cost to salvage — see
        // method doc's formula. `**` is PHP's exponentiation operator;
        // guarded by the clamps above so the base ratio is always in
        // (0, 1] and the exponent always in [0, 1], so no float weirdness
        // (NAN/INF) can arise here.
        $salvageRatio = $salvageValuePence / $purchaseCostPence;
        $exponent     = $depreciableMonths / $usefulLifeMonths;
        $currentValue = (int) round($purchaseCostPence * ($salvageRatio ** $exponent));

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

    /**
     * Thin dispatcher over the two pure per-method estimators above — picks
     * computeStraightLineValue() or computeReducingBalanceValue() based on
     * the asset's own `depreciationMethod`, so callers that don't care
     * WHICH method an asset uses (persistCurrentValues(), the live-fallback
     * readouts in valueSummaryForSite()/depreciationReportRows(), item.php's
     * depreciation card) can call one method regardless. `'none'` and any
     * unrecognised value both return null — NO DATABASE ACCESS, same
     * "never invent a value" contract as the two methods it dispatches to.
     *
     * @param array<string, mixed> $asset
     *
     * @return int|null Estimated current value in pence, or null when not
     *                   computable / method is 'none'/unrecognised
     */
    public static function computeCurrentValue(array $asset, ?string $asOfDate = null): ?int
    {
        $method = (string) ($asset['depreciationMethod'] ?? 'none');

        return match ($method) {
            'straight-line'    => self::computeStraightLineValue($asset, $asOfDate),
            'reducing-balance' => self::computeReducingBalanceValue($asset, $asOfDate),
            default            => null,
        };
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

    /* ==========================================================================
     * 🎟️ Per-device licence tracking (#400)
     * ------------------------------------------------------------------------
     * `tblAssetLicenseAssignments` — one row per seat handed to a device
     * asset / portal user / free-text device name, NEVER hard-deleted (a
     * released seat becomes its own permanent history row, status
     * 'released' — same convention as `tblAssetLoans`' state machine).
     * See class header point 7 above for the full design rationale.
     * ======================================================================== */

    /**
     * List a licence asset's seat assignments, active rows first (then
     * released, newest `linkedAt` first within each group). Resolves a
     * single `assignedToDisplay` string per row — see class header point 7
     * — plus `linkedByName`/`releasedByName` (LEFT JOINed; either may be
     * null when the acting user has since been deleted, same soft-reference
     * convention as `listMaintenance()`'s `performedByUserName`).
     *
     * No additional `siteID` filter is applied here beyond
     * `licenseAssetID` itself — mirrors `listMaintenance()`/
     * `listLoansForAsset()`'s own shape, since every caller only ever
     * reaches this method with a `$licenseAssetId` already resolved via
     * `self::get()` (itself site-scoped via `Site::id()`), so there is
     * nothing left to additionally restrict at the assignment-row level.
     *
     * @param bool $includeReleased When false, only status='active' rows
     *             are returned (e.g. a seat-count-only caller); defaults to
     *             true so item.php's panel can render both the active list
     *             and the collapsible released history from one call.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listLicenseAssignments(int $licenseAssetId, bool $includeReleased = true): array
    {
        $db = App::db();

        $sql = 'SELECT la.*, '
             . '       da.name AS deviceAssetName, '
             . '       u.fullName AS userName, '
             . '       lb.fullName AS linkedByName, '
             . '       rb.fullName AS releasedByName '
             . 'FROM tblAssetLicenseAssignments la '
             . 'LEFT JOIN tblAssets da ON da.assetID = la.deviceAssetID '
             . 'LEFT JOIN tblUsers u ON u.userID = la.userID '
             . 'LEFT JOIN tblUsers lb ON lb.userID = la.linkedByID '
             . 'LEFT JOIN tblUsers rb ON rb.userID = la.releasedByID '
             . 'WHERE la.licenseAssetID = ?'
             . ($includeReleased === false ? " AND la.status = 'active'" : '')
             // 🥇 Active first (MySQL sorts boolean-expression DESC true-
             // before-false), then newest-linked first within each group.
             . " ORDER BY (la.status = 'active') DESC, la.linkedAt DESC, la.assignmentID DESC";

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            error_log('AssetRegister::listLicenseAssignments() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('i', $licenseAssetId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            // 🏷️ Resolve a single display string from whichever target this
            // row was assigned to — exactly one of deviceAssetID/userID/
            // deviceName is ever set per row (assignSeat()'s exactly-one-
            // target rule below), mirrors listOwners()'s partyName /
            // listLoans()'s counterpartyDisplayName resolution above.
            if ($row['deviceAssetID'] !== null) {
                $row['assignedToDisplay'] = $row['deviceAssetName'] ?? '(deleted asset)';
            } elseif ($row['userID'] !== null) {
                $row['assignedToDisplay'] = $row['userName'] ?? '(deleted user)';
            } elseif ($row['deviceName'] !== null && (string) $row['deviceName'] !== '') {
                $row['assignedToDisplay'] = (string) $row['deviceName'];
            } else {
                // 🛟 Should be unreachable given assignSeat()'s validation,
                // but a display fallback is cheaper than a fatal here.
                $row['assignedToDisplay'] = 'Unknown';
            }
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Count a licence asset's currently ACTIVE seat assignments. Used by
     * both `seatSummary()` (display) and `assignSeat()` (enforcement) — a
     * single source of truth for "how many seats are in use right now" so
     * the two can never drift apart.
     */
    public static function activeSeatCount(int $licenseAssetId): int
    {
        $db = App::db();
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS cnt FROM tblAssetLicenseAssignments WHERE licenseAssetID = ? AND status = 'active'"
        );
        if ($stmt === false) {
            error_log('AssetRegister::activeSeatCount() prepare failed: ' . $db->error);
            return 0;
        }
        $stmt->bind_param('i', $licenseAssetId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null ? (int) $row['cnt'] : 0;
    }

    /**
     * Summarise a licence asset's seat usage for display (item.php's
     * header readout) and for `assignSeat()`'s own enforcement check.
     * NULL-safe throughout: when `$licenseSeats` is null (no cap
     * configured — unlimited seats), `seats`/`free` stay null and `over`
     * is always false, since there is no ceiling to exceed.
     *
     * @return array{seats: int|null, used: int, free: int|null, over: bool}
     */
    public static function seatSummary(int $licenseAssetId, ?int $licenseSeats): array
    {
        $used = self::activeSeatCount($licenseAssetId);

        if ($licenseSeats === null) {
            // ♾️ Unlimited — no seat cap configured on this licence asset.
            return ['seats' => null, 'used' => $used, 'free' => null, 'over' => false];
        }

        return [
            'seats' => $licenseSeats,
            'used'  => $used,
            // 🔒 Floored at 0 for display — an over-allocated licence still
            // shows "0 free" rather than a confusing negative number; the
            // `over` flag below is what actually communicates the
            // over-allocation state.
            'free'  => max(0, $licenseSeats - $used),
            'over'  => $used > $licenseSeats,
        ];
    }

    /**
     * Assign (link) a seat on a digital licence asset to a device asset, a
     * portal user, or a free-text device name. Does NOT trust its caller's
     * validation — see class header point 7 — every rule below is
     * re-checked from scratch:
     *
     *   - `$licenseAssetId` must exist and be on this site (`self::get()`)
     *     AND have `assetKind = 'digital'` — a seat can never be attached
     *     to a physical asset, regardless of what a tampered form posts.
     *   - EXACTLY ONE of `deviceAssetID` (a real `tblAssets` row on this
     *     site — re-uses `self::get()`, never a bare posted int),
     *     `userID` (`partyExistsOnSite('user', …)`), `deviceName`
     *     (non-empty free text) is required.
     *   - `seatLabel` (≤100 chars, VARCHAR(100)) and `notes` (≤500 chars,
     *     VARCHAR(500)) are optional free text.
     *
     * Seat enforcement (WARN-by-default, BLOCK behind a setting): when the
     * licence asset's own `licenseSeats` is set and `activeSeatCount() >=
     * licenseSeats`, the assignment would push (or keep) this licence
     * over-allocated. By default that's only a WARNING — the assignment
     * still saves, and the warning string is returned for the caller to
     * flash — but when the site has explicitly opted into
     * `App::settings('assets.license_seat_block') === '1'`, the assignment
     * is hard-BLOCKED instead (`id` 0, no insert, no audit row) — matching
     * `addOwner()`'s/`createLoanRequest()`'s "return 0 on the failing
     * branch, log why" shape rather than throwing.
     *
     * $data keys: deviceAssetID (int|0), userID (int|0), deviceName
     * (string, only the ONE matching field need be set — the others are
     * ignored, same convention as addOwner()'s partyType-matched FK),
     * seatLabel (optional), notes (optional).
     *
     * @param array<string, mixed> $data
     *
     * @return array{id: int, warnings: string[]} id is 0 on any validation
     *         failure, insert failure, OR a hard block — warnings is
     *         always populated with a human-readable reason in that case
     *         too, not just on a successful-but-over-allocated save.
     */
    public static function assignSeat(int $licenseAssetId, array $data, int $actorUserId): array
    {
        $db     = App::db();
        $siteId = Site::id();

        // 🔒 The licence asset itself must exist, be on this site, AND be
        // a 'digital' asset — self::get() is already site-scoped via
        // Site::id(). Seats can only ever be assigned to a licence, not a
        // physical asset.
        $licenseAsset = self::get($licenseAssetId);
        if ($licenseAsset === null) {
            error_log('AssetRegister::assignSeat() licence asset not found on this site: #' . $licenseAssetId);
            return ['id' => 0, 'warnings' => ['Licence asset not found.']];
        }
        if ((string) $licenseAsset['assetKind'] !== 'digital') {
            error_log('AssetRegister::assignSeat() asset is not digital: #' . $licenseAssetId);
            return ['id' => 0, 'warnings' => ['Only digital (licence) assets can have seats assigned.']];
        }

        // 🔀 EXACTLY ONE of {deviceAssetID, userID, deviceName} — the core
        // integrity rule this table has no SQL constraint for (mirrors
        // addOwner()'s exactly-one-party-FK rule / createLoanRequest()'s
        // exactly-one-counterparty rule — see class header point 7).
        $deviceAssetId = (int) ($data['deviceAssetID'] ?? 0);
        $deviceAssetId = $deviceAssetId > 0 ? $deviceAssetId : null;
        $targetUserId  = (int) ($data['userID'] ?? 0);
        $targetUserId  = $targetUserId > 0 ? $targetUserId : null;
        $deviceName    = trim((string) ($data['deviceName'] ?? ''));
        $deviceName    = $deviceName !== '' ? $deviceName : null;

        $targetsSupplied = (int) ($deviceAssetId !== null) + (int) ($targetUserId !== null) + (int) ($deviceName !== null);
        if ($targetsSupplied !== 1) {
            error_log('AssetRegister::assignSeat() expected exactly one target, got ' . $targetsSupplied);
            return ['id' => 0, 'warnings' => ['Choose exactly one target for this seat — a tracked device, a portal user, or a free-text device name.']];
        }

        if ($deviceAssetId !== null) {
            // 🔍 A real tblAssets row on this site — reuses self::get(),
            // never a bare posted int (per class header point 7's note on
            // this specific validation).
            if (self::get($deviceAssetId) === null) {
                error_log('AssetRegister::assignSeat() deviceAssetID not found on this site: #' . $deviceAssetId);
                return ['id' => 0, 'warnings' => ['That device asset was not found on this site.']];
            }
        } elseif ($targetUserId !== null) {
            // 🔍 FK existence + site-scope — never trust a bare posted int
            // (reuses partyExistsOnSite() unchanged from #396).
            if (self::partyExistsOnSite('user', $targetUserId, $siteId) === false) {
                error_log('AssetRegister::assignSeat() userID not found on this site: #' . $targetUserId);
                return ['id' => 0, 'warnings' => ['That user was not found on this site.']];
            }
        } else {
            $deviceName = mb_substr($deviceName, 0, 255);
        }

        $seatLabel = trim((string) ($data['seatLabel'] ?? ''));
        $seatLabel = $seatLabel !== '' ? mb_substr($seatLabel, 0, 100) : null;

        $notes = trim((string) ($data['notes'] ?? ''));
        $notes = $notes !== '' ? mb_substr($notes, 0, 500) : null;

        // 🪑 Seat enforcement — WARN by default, hard-BLOCK only behind the
        // admin-controlled 'assets.license_seat_block' site setting. See
        // this method's own doc + class header point 7 for the full
        // rationale. A NULL licenseSeats means "unlimited" — no check at
        // all in that case.
        $warnings = [];
        $licenseSeats = $licenseAsset['licenseSeats'] !== null ? (int) $licenseAsset['licenseSeats'] : null;
        if ($licenseSeats !== null) {
            $activeCount = self::activeSeatCount($licenseAssetId);
            if ($activeCount >= $licenseSeats) {
                $blockOverAllocation = (string) (App::settings('assets.license_seat_block') ?? '0') === '1';
                if ($blockOverAllocation === true) {
                    error_log('AssetRegister::assignSeat() blocked — licence #' . $licenseAssetId . ' has no seats remaining and assets.license_seat_block=1');
                    return ['id' => 0, 'warnings' => [
                        'This licence has no seats remaining (' . $activeCount . ' of ' . $licenseSeats . ' in use) — seat over-allocation is blocked for this site.',
                    ]];
                }
                $warnings[] = 'This licence is now over-allocated (' . ($activeCount + 1) . ' of ' . $licenseSeats . ' seats in use).';
            }
        }

        $fields = [
            'siteID'         => [$siteId, 'i'],
            'licenseAssetID' => [$licenseAssetId, 'i'],
            'seatLabel'      => [$seatLabel, 's'],
            'deviceAssetID'  => [$deviceAssetId, 'i'],
            'userID'         => [$targetUserId, 'i'],
            'deviceName'     => [$deviceName, 's'],
            'status'         => ['active', 's'],
            'linkedByID'     => [$actorUserId, 'i'],
            'notes'          => [$notes, 's'],
        ];
        ['columns' => $columns, 'types' => $types, 'params' => $params] = self::splitFields($fields);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $stmt = $db->prepare('INSERT INTO tblAssetLicenseAssignments (`' . implode('`, `', $columns) . '`) VALUES (' . $placeholders . ')');
        if ($stmt === false) {
            error_log('AssetRegister::assignSeat() prepare failed: ' . $db->error);
            return ['id' => 0, 'warnings' => ['Could not save the seat assignment — please try again.']];
        }

        try {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            error_log('AssetRegister::assignSeat() insert failed: ' . $e->getMessage());
            $stmt->close();
            return ['id' => 0, 'warnings' => ['Could not save the seat assignment — please try again.']];
        }
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        if ($newId <= 0) {
            return ['id' => 0, 'warnings' => ['Could not save the seat assignment — please try again.']];
        }

        // 📜 Audit the link. The licence KEY itself is never referenced
        // anywhere in this method — this change-set only ever describes
        // WHO/WHAT the seat was handed to, never tblAssets.licenseKey (see
        // class header point 7's closing note).
        $auditNew = [];
        foreach ($fields as $col => $pair) {
            $auditNew[$col] = $pair[0];
        }
        self::audit('license', $newId, $licenseAssetId, 'link', null, $auditNew);

        return ['id' => $newId, 'warnings' => $warnings];
    }

    /**
     * Release (unlink) an active seat assignment. IDOR guard: the row must
     * belong to BOTH `$assignmentId` AND `$licenseAssetId` AND the current
     * site before it's ever read or touched — mirrors
     * `updateMaintenance()`'s "load scoped to all three, then mutate"
     * pattern. NEVER hard-deletes — the row is its own permanent history
     * (same convention as every state-machine table in this class); this
     * method only ever flips `status` → 'released' and stamps
     * `releasedAt`/`releasedByID`.
     *
     * Race-safe: the actual state transition is guarded by
     * `WHERE … AND status = 'active'` on the UPDATE itself, so a row that
     * moved to 'released' between the SELECT above and this UPDATE (e.g. a
     * double-click, or two tabs racing) simply yields 0 affected rows and
     * a clean `false` return — same idiom as `loanAction()`'s per-verb
     * state-machine guards.
     *
     * @return bool True on success, false if the row doesn't exist (for
     *              this licence asset, on this site) or was already
     *              released
     */
    public static function releaseSeat(int $assignmentId, int $licenseAssetId, int $actorUserId): bool
    {
        $db     = App::db();
        $siteId = Site::id();

        // 🔒 IDOR guard FIRST — before any mutation.
        $stmt = $db->prepare(
            'SELECT * FROM tblAssetLicenseAssignments WHERE assignmentID = ? AND licenseAssetID = ? AND siteID = ? LIMIT 1'
        );
        if ($stmt === false) {
            error_log('AssetRegister::releaseSeat() prepare failed: ' . $db->error);
            return false;
        }
        $stmt->bind_param('iii', $assignmentId, $licenseAssetId, $siteId);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($old === null || $old === false) {
            return false;
        }

        // 🚦 State guard — WHERE ... AND status='active' on the UPDATE
        // itself (race-safe — see method doc above).
        $updStmt = $db->prepare(
            "UPDATE tblAssetLicenseAssignments SET status = 'released', releasedAt = NOW(), releasedByID = ? "
            . " WHERE assignmentID = ? AND licenseAssetID = ? AND siteID = ? AND status = 'active'"
        );
        if ($updStmt === false) {
            error_log('AssetRegister::releaseSeat() prepare failed: ' . $db->error);
            return false;
        }
        $updStmt->bind_param('iiii', $actorUserId, $assignmentId, $licenseAssetId, $siteId);
        $ok = $updStmt->execute();
        $affected = $updStmt->affected_rows;
        $updStmt->close();

        if ($ok === false || $affected <= 0) {
            return false;
        }

        // 📜 Audit — old/new capture just the status transition, matching
        // loanAction()'s own change-set shape for its state-machine
        // transitions. NEVER a hard delete — see method doc.
        self::audit('license', $assignmentId, $licenseAssetId, 'release', ['status' => 'active'], ['status' => 'released']);

        return true;
    }

    /* ==========================================================================
     * 🔍 Found reports (#401) — public "I found this" submissions
     * ------------------------------------------------------------------------
     * `tblAssetFoundReports` CRUD for the public lost-and-found form
     * (`_apps/assets/tag.php` → `_apps/assets/found-save.php`) and its
     * manager-only admin triage queue (`_apps/assets/found-reports.php`).
     * See class header point 8 for the full rationale — in short:
     * `createFoundReport()` is the one method in this class an anonymous
     * visitor's request ever reaches (only after found-save.php's own
     * captcha/CSRF/honeypot/rate-limit gate chain passes), so unlike
     * createAsset()/updateAsset() it does NOT trust its caller's input —
     * it trims/caps every field itself, exactly like addOwner()'s /
     * createLoanRequest()'s "re-validate from scratch" convention for
     * anything that isn't pure internal-manager input.
     * ======================================================================== */

    /**
     * Insert a public "I found this" submission. Trims/caps every field to
     * its column width, always writes `status = 'new'`, and audits with
     * `actorType: 'public'` (no `actorUserID` — there is no session behind
     * this call; see class header point 8). The caller (found-save.php)
     * is responsible for EVERY gate that must pass before this is ever
     * reached — captcha, CSRF, honeypot, per-IP rate limit, and the SAME
     * uniform-404 public-page eligibility check tag.php itself enforces —
     * this method assumes none of that already happened and re-checks only
     * the one thing it CAN cheaply re-check itself: that `$assetId` names
     * a real, non-deleted asset on the current site (`self::get()`).
     *
     * $data keys (all optional except that at least one of
     * reporterContact/message must be non-empty — found-save.php enforces
     * that before calling; this method does not re-reject on it, it simply
     * persists whatever non-empty subset it's given):
     *   reporterName (≤150 chars — VARCHAR(150) column), reporterContact
     *   (≤255 — VARCHAR(255)), message (≤4000 — TEXT column, capped for
     *   sanity rather than the column's true unbounded width, mirroring
     *   prayer-requests/anonymous-save.php's own `body` cap).
     *
     * @param array<string, mixed> $data
     * @param string                $ipHash Salted SHA-256 of the reporter's
     *                                      IP — see AssetRegister::ipHash()/
     *                                      publicIpHash(). NEVER the raw IP.
     *
     * @return int New reportID, or 0 if the asset doesn't exist (or isn't
     *             on this site) or the insert failed
     */
    public static function createFoundReport(int $assetId, array $data, string $ipHash): int
    {
        $db     = App::db();
        $siteId = Site::id();

        // 🔒 The asset must be a real, non-deleted, on-this-site row —
        // self::get() applies all three checks via Site::id() (see that
        // method's own doc). A found-report can never attach to a
        // dangling/foreign/soft-deleted assetID even if found-save.php's
        // own gate chain were somehow bypassed upstream.
        if (self::get($assetId) === null) {
            error_log('AssetRegister::createFoundReport() asset not found on this site: #' . $assetId);
            return 0;
        }

        $reporterName = trim((string) ($data['reporterName'] ?? ''));
        $reporterName = $reporterName !== '' ? mb_substr($reporterName, 0, 150) : null;

        $reporterContact = trim((string) ($data['reporterContact'] ?? ''));
        $reporterContact = $reporterContact !== '' ? mb_substr($reporterContact, 0, 255) : null;

        $message = trim((string) ($data['message'] ?? ''));
        $message = $message !== '' ? mb_substr($message, 0, 4000) : null;

        $stmt = $db->prepare(
            'INSERT INTO tblAssetFoundReports '
            . '(siteID, assetID, reporterName, reporterContact, message, ipHash, status) '
            . "VALUES (?, ?, ?, ?, ?, ?, 'new')"
        );
        if ($stmt === false) {
            error_log('AssetRegister::createFoundReport() prepare failed: ' . $db->error);
            return 0;
        }
        $stmt->bind_param('iissss', $siteId, $assetId, $reporterName, $reporterContact, $message, $ipHash);
        $ok    = $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        if ($ok === false || $newId <= 0) {
            return 0;
        }

        // 📜 Audit — entityType 'found-report' IS in TABLE_FOR_ENTITY (maps
        // to tblAssetFoundReports), so this ALSO writes the platform
        // tblAuditTrail mirror via Logger::audit() — actorType 'public'
        // means audit() records no actorUserID (see class header point 8
        // and audit()'s own doc for the actorType-driven attribution
        // branch).
        self::audit(
            'found-report',
            $newId,
            $assetId,
            'create',
            null,
            ['reporterName' => $reporterName, 'reporterContact' => $reporterContact, 'message' => $message, 'status' => 'new'],
            [],
            'public'
        );

        return $newId;
    }

    /**
     * List found-reports for the admin triage queue
     * (`_apps/assets/found-reports.php`), newest first, joined to the
     * owning asset's name. Site-scoped via the `$siteId` parameter (NOT
     * `Site::id()` internally — mirrors `listForSite()`'s own
     * caller-supplied-siteId convention, since found-reports.php already
     * resolves it once via `Site::id()` itself before calling in).
     *
     * Recognised $filters keys (both optional): 'status' (one of
     * new/actioned/closed), 'assetID'.
     *
     * @param array{status?: string, assetID?: int} $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listFoundReports(int $siteId, array $filters = []): array
    {
        $db = App::db();

        $where  = ['fr.siteID = ?'];
        $types  = 'i';
        $params = [$siteId];

        if (isset($filters['status']) === true && $filters['status'] !== '') {
            $where[]  = 'fr.status = ?';
            $types   .= 's';
            $params[] = (string) $filters['status'];
        }
        if (isset($filters['assetID']) === true && (int) $filters['assetID'] > 0) {
            $where[]  = 'fr.assetID = ?';
            $types   .= 'i';
            $params[] = (int) $filters['assetID'];
        }

        // 🪞 INNER JOIN tblAssets — a found-report's assetID is
        // ON DELETE CASCADE (migration 159), so a hard-deleted asset takes
        // its reports with it; a SOFT-deleted one (isDeleted=1) still joins
        // here on purpose (no isDeleted filter) — a manager triaging old
        // reports should still see one filed against an asset that's since
        // been retired/disposed, not have it silently vanish from the queue.
        $sql = 'SELECT fr.*, a.name AS assetName '
             . 'FROM tblAssetFoundReports fr '
             . 'INNER JOIN tblAssets a ON a.assetID = fr.assetID '
             . 'WHERE ' . implode(' AND ', $where) . ' '
             . 'ORDER BY fr.createdAt DESC';

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            error_log('AssetRegister::listFoundReports() prepare failed: ' . $db->error);
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
     * Transition a found-report's triage status (new → actioned/closed, or
     * any other member of the three-value ENUM — the admin triage UI does
     * not enforce a strict one-way state machine the way loans/maintenance
     * do; a manager may freely move a report back to 'new' if it turns out
     * to need another look). IDOR guard: the row must belong to BOTH
     * `$reportId` AND `$siteId` before it's ever read or touched — mirrors
     * `removeIdentifier()`'s own "confirm it belongs to this site first"
     * pattern.
     *
     * @return bool True on success, false if `$status` isn't a recognised
     *              value or the row doesn't exist (for this report, on
     *              this site)
     */
    public static function setFoundReportStatus(int $reportId, int $siteId, string $status, int $actorUserId): bool
    {
        if (in_array($status, ['new', 'actioned', 'closed'], true) === false) {
            return false;
        }

        $db = App::db();

        // 🔒 IDOR guard FIRST — before any mutation.
        $stmt = $db->prepare('SELECT * FROM tblAssetFoundReports WHERE reportID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            error_log('AssetRegister::setFoundReportStatus() prepare failed: ' . $db->error);
            return false;
        }
        $stmt->bind_param('ii', $reportId, $siteId);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($old === null || $old === false) {
            return false;
        }

        $updStmt = $db->prepare('UPDATE tblAssetFoundReports SET status = ? WHERE reportID = ? AND siteID = ?');
        if ($updStmt === false) {
            error_log('AssetRegister::setFoundReportStatus() prepare failed: ' . $db->error);
            return false;
        }
        $updStmt->bind_param('sii', $status, $reportId, $siteId);
        $ok = $updStmt->execute();
        $updStmt->close();

        if ($ok === false) {
            return false;
        }

        // 📜 Audit — entityType 'found-report' (maps to
        // tblAssetFoundReports via TABLE_FOR_ENTITY, so this ALSO writes
        // the platform tblAuditTrail mirror). actorType defaults to 'user'
        // — this action is always a manager acting from a real session
        // (found-reports.php's own gate — see that file's header), unlike
        // createFoundReport() above.
        self::audit(
            'found-report',
            $reportId,
            (int) $old['assetID'],
            'update',
            ['status' => (string) $old['status']],
            ['status' => $status]
        );

        return true;
    }

    /**
     * Delete found-reports older than `assets.found_report_retention_days`
     * (seeded 180 — migration 159) for the given site. GDPR housekeeping:
     * a found-report row holds third-party PII (reporterName/
     * reporterContact/message) volunteered by someone with no portal
     * account and no consent flow of their own — see migration 159's
     * table comment for why it's deliberately excluded from
     * `GdprEraser::catalogue()`'s per-USER erasure sweep (there is no user
     * to erase it for); age-based retention is the only cleanup path a
     * row like this gets.
     *
     * TODO(#405 reminders cron / Phase 2): this method is ready to call
     * but NOT wired into any scheduled job by this pass — none of the
     * existing `web/_apps/cron/*.php` endpoints (event-reminders.php,
     * import-feeds.php, discipleship-sweep.php) already loop "every site,
     * every day" in a shape this could just slot into without inventing a
     * new one, and this sub-issue's scope explicitly excludes adding a new
     * cron route/migration. Whichever future sub-issue adds a general
     * daily housekeeping sweep should call
     * `AssetRegister::purgeExpiredFoundReports($siteId)` once per active
     * site from there.
     *
     * @return int Number of rows deleted
     */
    public static function purgeExpiredFoundReports(int $siteId): int
    {
        $db = App::db();

        $retentionDays = (int) (App::settings('assets.found_report_retention_days') ?? 180);
        if ($retentionDays <= 0) {
            // 🛟 A misconfigured (zero/negative) setting must never be
            // read as "purge everything immediately" — fall back to the
            // seeded default rather than mass-deleting on a bad value.
            $retentionDays = 180;
        }

        $stmt = $db->prepare(
            'DELETE FROM tblAssetFoundReports WHERE siteID = ? AND createdAt < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        if ($stmt === false) {
            error_log('AssetRegister::purgeExpiredFoundReports() prepare failed: ' . $db->error);
            return 0;
        }
        $stmt->bind_param('ii', $siteId, $retentionDays);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        return (int) $deleted;
    }

    /* ==========================================================================
     * 🏷️ Label designer + PDF (#402) — see class header point 9 for the full
     * design rationale. `_apps/assets/labels.php` (GET designer/preview) and
     * `_apps/assets/labels-pdf.php` (POST PDF generator) are the two
     * callers; both re-validate every option against the allow-lists below
     * independently rather than trusting whichever screen posted to them.
     * ======================================================================== */

    /**
     * Printable label sheet size presets. SINGLE source of truth for both
     * the designer's live preview and the PDF generator — see
     * `buildLabelSheets()`'s own doc. Millimetre dimensions/pitches mirror
     * the commonly-published Avery template spec sheets for these product
     * codes; treat them as "close enough to print straight onto the
     * genuine Avery stock" rather than laser-measured exact figures — a
     * site that finds its printer drifts by a millimetre or two can still
     * fall back to the browser print-CSS path's normal page-margin
     * controls. `'small-tag'` has no Avery product code — a house-only
     * tight layout (38×21mm) for keys/small kit, where there's only ever
     * room for a QR code plus a single short code line (see
     * `renderLabelCellInner()`'s own doc for how that preset forces
     * "QR + code only" regardless of the `fields` a caller selects).
     *
     * @var array<string, array{label:string, widthMm:float, heightMm:float, cols:int, rows:int, marginTopMm:float, marginLeftMm:float, colGapMm:float, rowGapMm:float}>
     */
    public const LABEL_PRESETS = [
        'L7160' => [
            'label' => 'Avery L7160 — 63.5 × 38.1mm (3×7, 21 per sheet)',
            'widthMm' => 63.5, 'heightMm' => 38.1, 'cols' => 3, 'rows' => 7,
            'marginTopMm' => 15.1, 'marginLeftMm' => 7.2, 'colGapMm' => 2.5, 'rowGapMm' => 0,
        ],
        'L7163' => [
            'label' => 'Avery L7163 — 99.1 × 38.1mm (2×7, 14 per sheet)',
            'widthMm' => 99.1, 'heightMm' => 38.1, 'cols' => 2, 'rows' => 7,
            'marginTopMm' => 15.1, 'marginLeftMm' => 4.7, 'colGapMm' => 2.5, 'rowGapMm' => 0,
        ],
        'L7165' => [
            'label' => 'Avery L7165 — 99.1 × 67.7mm (2×4, 8 per sheet)',
            'widthMm' => 99.1, 'heightMm' => 67.7, 'cols' => 2, 'rows' => 4,
            'marginTopMm' => 13.1, 'marginLeftMm' => 4.7, 'colGapMm' => 2.5, 'rowGapMm' => 0,
        ],
        'small-tag' => [
            'label' => 'Small tag — 38 × 21mm, QR + code only (no Avery code)',
            'widthMm' => 38, 'heightMm' => 21, 'cols' => 5, 'rows' => 12,
            'marginTopMm' => 11.5, 'marginLeftMm' => 6, 'colGapMm' => 2, 'rowGapMm' => 2,
        ],
    ];

    /**
     * Recognised label content fields, in the FIXED canonical order they
     * always print in (a caller's own array order is never trusted — see
     * `renderLabelCellInner()`). `'serial'` is deliberately absent from
     * every default-selection list this pass ships (`labels.php`'s own
     * first-load default) — labels sit physically ON items, often visible
     * to anyone who picks the item up, so printing a serial number by
     * default would casually expose it far more widely than the register
     * screen (where it's only visible to a logged-in viewer) ever does.
     *
     * @var string[]
     */
    public const LABEL_FIELDS = ['name', 'assetTagCode', 'category', 'location', 'owningOrg', 'serial'];

    /** @var array<string, string> Human labels for LABEL_FIELDS, keyed the same. */
    public const LABEL_FIELD_LABELS = [
        'name'         => 'Name',
        'assetTagCode' => 'Asset tag code',
        'category'     => 'Category',
        'location'     => 'Location',
        'owningOrg'    => 'Owning organisation',
        'serial'       => 'Serial number',
    ];

    /**
     * Recognised label barcode symbologies — mirrors `tblAssets.labelSymbology`'s
     * ENUM exactly (migration 159, unused until this pass — #404). Stored
     * per-asset; `buildLabelSheets()` reads each asset's own value to
     * decide what to render alongside/instead of the QR code (see that
     * method's own doc for the fallback-to-QR behaviour when the chosen
     * symbology's source value is missing/invalid).
     *
     * @var string[]
     */
    public const LABEL_SYMBOLOGIES = ['qr', 'code128', 'ean13', 'upca', 'itf14', 'qr+code128'];

    /** @var array<string, string> Human labels for LABEL_SYMBOLOGIES, keyed the same — feeds edit.php's `<select>`. */
    public const LABEL_SYMBOLOGY_LABELS = [
        'qr'         => 'QR code (links to the public lost-and-found page)',
        'code128'    => 'Code 128 (asset tag code)',
        'ean13'      => 'EAN-13 (primary EAN-13 identifier)',
        'upca'       => 'UPC-A (primary UPC-A identifier)',
        'itf14'      => 'ITF-14 (primary ITF-14 identifier)',
        'qr+code128' => 'QR code + Code 128 (both)',
    ];

    /**
     * Which `LABEL_SYMBOLOGIES` values require a barcode lookup at all
     * (i.e. everything except the plain `'qr'` choice) — `buildLabelSheets()`
     * checks this to decide whether it needs to touch `Barcode::generate()`
     * for a given asset.
     *
     * @var string[]
     */
    private const BARCODE_SYMBOLOGIES = ['code128', 'ean13', 'upca', 'itf14', 'qr+code128'];

    /**
     * Maps a `LABEL_SYMBOLOGIES` GS1 value to the `tblAssetIdentifierTypes.typeCode`
     * (migration 159 seed data) whose PRIMARY `tblAssetIdentifiers` row
     * supplies the barcode's source value — see `assetsForLabels()`'s
     * `barcodeIdentifierValue` subquery, which uses the identical mapping
     * in SQL (`CASE a.labelSymbology WHEN ...`) so the two never drift.
     *
     * @var array<string, string>
     */
    private const GS1_IDENTIFIER_TYPE_CODES = [
        'ean13' => 'EAN-13',
        'upca'  => 'UPC-A',
        'itf14' => 'ITF-14',
    ];

    /**
     * Hard caps `labels-pdf.php` enforces server-side, regardless of what
     * the designer's own GET preview happened to render — see that file's
     * header for the OOM-guard rationale. Copies-per-asset AND the total
     * rendered label count (offset + assets×copies) are both bounded so a
     * tampered POST can't ask dompdf to lay out an unbounded number of
     * absolutely-positioned cells across an unbounded number of sheets.
     */
    public const MAX_LABEL_COPIES = 50;

    /** @see MAX_LABEL_COPIES */
    public const MAX_TOTAL_LABELS = 500;

    /**
     * Fetch the given assets (site-scoped, non-deleted) with the fields a
     * label can show. Ids that don't belong to this site (or don't exist,
     * or are soft-deleted) are simply ABSENT from the result — never an
     * error — same "the SQL filter does the tenancy check" convention as
     * `listForSite()`. Caller (`labels.php`/`labels-pdf.php`) is
     * responsible for treating a shorter-than-requested result as "some of
     * what you asked for wasn't available", not as a partial failure.
     *
     * `owningOrgName` is the FIRST org-type owner row for the asset (the
     * design's "first org owner if any" decision) — resolved via a
     * correlated subquery rather than a JOIN so an asset with either zero
     * or MULTIPLE org owners still yields exactly one row here (a JOIN
     * would drop the zero case or multiply the many case; neither is what
     * a one-row-per-asset label list wants). Ordered by `ownerID` so
     * "first" is deterministic (oldest-added), matching the same
     * "resolve one summary name" intent as `listOwners()`'s `partyName`.
     *
     * @param int[] $assetIds
     *
     * @return array<int, array<string, mixed>> Rows keyed numerically (NOT
     *         by assetID), each: assetID, name, assetTagCode, serialNumber,
     *         publicToken, labelSymbology, categoryName, locationName,
     *         owningOrgName, barcodeIdentifierValue (#404 — the asset's
     *         PRIMARY `tblAssetIdentifiers` row matching whichever GS1
     *         scheme `labelSymbology` calls for, or NULL when
     *         `labelSymbology` isn't a GS1 symbology or no such row
     *         exists — see `GS1_IDENTIFIER_TYPE_CODES`).
     */
    public static function assetsForLabels(int $siteId, array $assetIds): array
    {
        // 🧹 Normalise — dedupe, positive ints only. A tampered POST full
        // of non-numeric junk, negatives, or repeats never reaches the
        // query below as anything but a clean id list.
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $assetIds),
            static fn (int $id): bool => $id > 0
        )));
        if (count($ids) === 0) {
            return [];
        }

        // 🛡️ Floor safety net — labels-pdf.php's own MAX_TOTAL_LABELS/
        // MAX_LABEL_COPIES checks are the REAL cap on how many labels a
        // request can ultimately render, but a hand-crafted assetIds[]
        // with thousands of entries would still cost a needlessly large
        // IN() query before that check ever runs. Trimmed here
        // defensively — no legitimate designer selection needs this many.
        if (count($ids) > 500) {
            $ids = array_slice($ids, 0, 500);
        }

        $db = App::db();
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $sql = 'SELECT a.assetID, a.name, a.assetTagCode, a.serialNumber, a.publicToken, a.labelSymbology, '
             . '       c.categoryName, l.locationName, '
             . "       (SELECT org.orgName FROM tblAssetOwners o "
             . '        JOIN tblAssetOrgs org ON org.orgID = o.orgID '
             . "        WHERE o.assetID = a.assetID AND o.partyType = 'org' "
             . '        ORDER BY o.ownerID ASC LIMIT 1) AS owningOrgName, '
             // 🔢 #404 — the asset's PRIMARY identifier row for whichever
             // GS1 scheme its OWN labelSymbology calls for (NULL for every
             // other row, incl. plain 'qr'/'code128'/'qr+code128' assets —
             // this subquery is a no-op for those). Same value→typeCode
             // mapping as GS1_IDENTIFIER_TYPE_CODES in PHP; kept in lock-
             // step by both places citing each other.
             . "       (SELECT i.value FROM tblAssetIdentifiers i "
             . '        WHERE i.assetID = a.assetID AND i.isPrimary = 1 '
             . "          AND i.typeCode = CASE a.labelSymbology "
             . "                             WHEN 'ean13' THEN 'EAN-13' "
             . "                             WHEN 'upca'  THEN 'UPC-A' "
             . "                             WHEN 'itf14' THEN 'ITF-14' "
             . '                             ELSE NULL END '
             . '        LIMIT 1) AS barcodeIdentifierValue '
             . 'FROM tblAssets a '
             . 'LEFT JOIN tblAssetCategories c ON c.categoryID = a.categoryID '
             . 'LEFT JOIN tblAssetLocations l ON l.locationID = a.locationID '
             . 'WHERE a.siteID = ? AND a.isDeleted = 0 AND a.assetID IN (' . $placeholders . ') '
             . 'ORDER BY a.name ASC, a.assetID ASC';

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            error_log('AssetRegister::assetsForLabels() prepare failed: ' . $db->error);
            return [];
        }
        $types  = 'i' . str_repeat('i', count($ids));
        $params = array_merge([$siteId], $ids);
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
     * Absolute `https://{our own host}/a/{publicToken}` URL for an asset's
     * public lost-and-found page — the ONLY thing this pass ever encodes
     * into a label QR code. See class header point 9 for the full "own
     * host, never attacker-controlled" rationale.
     *
     * @param array<string, mixed> $asset A row from `assetsForLabels()`
     *                                    (or `get()`) — must carry a
     *                                    `publicToken` key.
     *
     * @return string The absolute URL, or '' if the asset's stored token
     *                doesn't match the expected shape (defence in depth —
     *                see the inline comment below; should never happen for
     *                a genuine DB row).
     */
    public static function labelPublicUrl(array $asset): string
    {
        $token = (string) ($asset['publicToken'] ?? '');

        // 🛡️ Re-validate the SHAPE even though publicToken is always
        // DB-generated (generatePublicToken()) — mirrors tag.php's own
        // defensive re-check of the very same pattern even after the
        // Router has already validated it. Guarantees this method can
        // NEVER return a malformed QR payload, even if a future caller
        // passed a hand-built $asset array instead of a real DB row.
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return '';
        }

        return self::siteBaseUrl() . '/a/' . $token;
    }

    /**
     * Absolute scheme+host for the CURRENT request — mirrors the existing
     * house convention for building absolute outbound links
     * (`Newsletter::baseUrl()`, `PrayerChain`'s own inline resolution,
     * `_apps/calendar/export.php`, `_apps/calendar/account-feed.php`).
     * `$_SERVER['HTTP_HOST']`/`['HTTPS']` are populated by the webserver/
     * vhost from the connection itself — never from a request BODY — so
     * this can't be steered by anything a POST/GET parameter supplies;
     * it's used SOLELY to prefix `labelPublicUrl()`'s own `/a/{token}`
     * path above, and that token always comes from the asset's own stored
     * `publicToken` column (shape-checked immediately above), never from
     * any request input. This is the mechanism behind the "QR payload is
     * always our own host" guarantee.
     */
    private static function siteBaseUrl(): string
    {
        $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && (string) ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host;
    }

    /**
     * Record one `audit('label', assetID, assetID, 'print', …)` row PER
     * ASSET in the batch — the design's "per-asset per-batch" decision, so
     * item.php's "Recent activity" strip for EACH individual asset shows
     * when a label for THAT asset was last printed, rather than a single
     * opaque "a batch ran somewhere" event with no per-asset visibility.
     *
     * `$actorUserId` is accepted (rather than silently dropped) for
     * signature clarity/future-proofing even though `self::audit()`
     * itself always re-derives the acting user from `$_SESSION` when
     * `actorType = 'user'` (see that method's own doc) — this mirrors
     * every OTHER mutating method in this class that accepts an
     * `$actorUserId` parameter: it's the caller's job to have confirmed
     * the current session IS that user before calling in (labels-pdf.php
     * does, via its manager gate), the same way `addIdentifier()`'s
     * `$actorUserId` flows to a `createdByID` column rather than to
     * `audit()`'s own actor resolution.
     *
     * @param int[] $assetIds
     */
    public static function recordLabelPrint(int $siteId, array $assetIds, string $preset, int $actorUserId): void
    {
        foreach (array_unique(array_map('intval', $assetIds)) as $assetId) {
            if ($assetId <= 0) {
                continue;
            }
            // 📜 entityType 'label' is deliberately NOT in TABLE_FOR_ENTITY
            // (no tblAssetLabels table — a label print is an audit EVENT,
            // never a persisted row of its own) so audit()'s tblAuditTrail
            // mirror is skipped automatically, same convention as the
            // 'token'/'scan' entityType above.
            self::audit('label', $assetId, $assetId, 'print', null, null, ['preset' => $preset, 'siteID' => $siteId]);
        }
    }

    /**
     * Full label-sheet HTML (one or more `.lbl-sheet` blocks, one per
     * physical A4 page) for a batch of assets — the ONE rendering path
     * both `labels.php`'s live preview AND `labels-pdf.php`'s PDF/
     * print-CSS fallback call, so what a manager sees on screen is
     * guaranteed byte-identical to what prints. This is the actual
     * mechanism behind "a live preview using the SAME CSS the PDF will
     * use" — rather than two hand-maintained copies of the same layout
     * math that could silently drift apart, there is exactly one.
     *
     * Layout strategy: absolute-positioned, mm-dimensioned cells — NOT CSS
     * Grid and NOT an HTML `<table>`. dompdf's grid/flex support is
     * inconsistent across versions, while `position:absolute` + mm units
     * is dompdf's most reliably-supported layout primitive AND renders
     * identically in every real browser for the on-screen preview, so one
     * strategy serves both renderers without a fallback branch of its own
     * (each label cell's `left`/`top`/`width`/`height` is computed once,
     * in PHP, from the preset's margin/pitch numbers below).
     *
     * SECURITY: every text value is `htmlspecialchars()`'d inside
     * `renderLabelCellInner()` — labels.php's preview and labels-pdf.php's
     * PDF both render free-text asset data (name/tag/category/location/
     * org/serial) a manager entered via edit.php, so this is the same
     * output-escaping discipline as every other view in this app.
     *
     * @param array<int, array<string, mixed>> $assets  Rows from
     *        `assetsForLabels()`, already site-scoped/deduped by the
     *        caller — this method performs no tenancy check of its own.
     * @param string   $presetKey Key into LABEL_PRESETS — falls back to
     *        'L7160' if somehow invalid (belt-and-braces; both callers
     *        validate independently against the allow-list before ever
     *        reaching here).
     * @param string[] $fields    Subset of LABEL_FIELDS to render —
     *        IGNORED for the 'small-tag' preset (see
     *        `renderLabelCellInner()`'s own doc: that preset is always
     *        QR + asset tag code only, regardless of what's ticked, since
     *        38×21mm has no room for more).
     * @param bool     $qrOn      Master "render a machine-readable code at
     *        all" switch — when true, EACH asset renders per its OWN
     *        `labelSymbology` (#404): plain QR for `'qr'`, a barcode ALONE
     *        for `'code128'`/`'ean13'`/`'upca'`/`'itf14'`, or BOTH for
     *        `'qr+code128'`. When an asset's chosen barcode symbology has
     *        no usable source value (see `LABEL_SYMBOLOGIES` doc — missing
     *        assetTagCode for Code 128, or no valid PRIMARY matching
     *        identifier for the GS1 symbologies), that ONE asset silently
     *        falls back to QR-only and a note is added to the returned
     *        `warnings` array — never fatal, never blocks the rest of the
     *        batch. When false, no code of any kind renders (unchanged
     *        pre-#404 behaviour).
     * @param int      $offset  Leading empty cell positions to skip —
     *        caller clamps this to `[0, cellsPerSheet-1]` first.
     * @param int      $copies  Copies per asset — caller clamps this to
     *        `[1, MAX_LABEL_COPIES]` first.
     *
     * @return array{css: string, html: string, sheetCount: int, labelCount: int, warnings: string[]}
     */
    public static function buildLabelSheets(
        array $assets,
        string $presetKey,
        array $fields,
        bool $qrOn,
        int $offset,
        int $copies
    ): array {
        $preset = self::LABEL_PRESETS[$presetKey] ?? self::LABEL_PRESETS['L7160'];
        $isSmallTag = $presetKey === 'small-tag';
        $cols = $preset['cols'];
        $rows = $preset['rows'];
        $cellsPerSheet = $cols * $rows;
        $offset = max(0, min($offset, $cellsPerSheet - 1));
        $copies = max(1, $copies);

        // 🎯 Only recognised fields ever reach the label body — belt and
        // braces; both callers already filter fields[] against
        // LABEL_FIELDS themselves (labels-pdf.php's is the one that
        // actually matters security-wise).
        $fields = array_values(array_intersect($fields, self::LABEL_FIELDS));

        // 🔳 QR + 🏷️ barcode data URIs — computed ONCE per unique asset, not
        // once per printed COPY, so a run of e.g. 20 copies of the same
        // asset doesn't re-encode the identical QR/barcode 20 times. mime
        // is whatever Qr::generate()/Barcode::generate() actually returned
        // (PNG when gd is loaded, SVG otherwise — see those classes' own
        // docs) rather than assumed, so this degrades gracefully on a
        // gd-less install.
        //
        // The QR is ALWAYS resolved here (not just for symbology='qr') —
        // it doubles as the universal fallback image for any barcode
        // symbology whose source value turns out missing/invalid below
        // (#404's documented "never fatal, fall back to QR + warn"
        // behaviour) — computing it lazily inside that fallback branch
        // instead would break the "once per unique asset" guarantee this
        // comment promises.
        $qrCache = [];
        $barcodeCache = [];
        $symbologyCache = [];
        $warnings = [];
        if ($qrOn === true) {
            foreach ($assets as $asset) {
                $assetId = (int) ($asset['assetID'] ?? 0);
                if ($assetId <= 0 || array_key_exists($assetId, $qrCache) === true) {
                    continue;
                }
                $qrCache[$assetId] = null;
                $url = self::labelPublicUrl($asset);
                if ($url !== '') {
                    $qr = Qr::generate($url, ['format' => 'png', 'size' => 256, 'ecc' => 'M']);
                    if (($qr['bytes'] ?? '') !== '') {
                        $qrCache[$assetId] = 'data:' . $qr['mime'] . ';base64,' . base64_encode($qr['bytes']);
                    }
                }

                // 🏷️ #404 — this asset's own chosen symbology. An
                // unrecognised stored value (should never happen — the
                // column is an ENUM and save.php validates against
                // LABEL_SYMBOLOGIES — but defend anyway) never reaches the
                // renderer as anything but 'qr'.
                $symbology = (string) ($asset['labelSymbology'] ?? 'qr');
                if (in_array($symbology, self::LABEL_SYMBOLOGIES, true) === false) {
                    $symbology = 'qr';
                }
                $symbologyCache[$assetId] = $symbology;
                $barcodeCache[$assetId] = null;
                if (in_array($symbology, self::BARCODE_SYMBOLOGIES, true) === false) {
                    continue; // plain 'qr' — nothing more to compute for this asset
                }

                $assetName = (string) ($asset['name'] ?? '');
                if ($symbology === 'code128' || $symbology === 'qr+code128') {
                    // ← assetTagCode, falling back to a synthesised
                    // 'AST-{id}' code when the field is blank (task spec).
                    $code = (string) ($asset['assetTagCode'] ?? '');
                    if ($code === '') {
                        $code = 'AST-' . $assetId;
                    }
                    $bc = Barcode::generate('code128', $code, ['format' => 'png', 'height' => 200, 'moduleWidth' => 3, 'quietModules' => 6]);
                    if ($bc['valid'] === true && ($bc['bytes'] ?? '') !== '') {
                        $barcodeCache[$assetId] = 'data:' . $bc['mime'] . ';base64,' . base64_encode($bc['bytes']);
                    } else {
                        // Reachable, not just belt-and-braces: assetTagCode
                        // is free-text VARCHAR(50) and Code 128 only covers
                        // ASCII 0-127 (Barcode::generate()'s own doc) — an
                        // emoji or accented character in a manager-entered
                        // tag code lands here. Barcode::generate() never
                        // fatals either way, so fall back to QR-only, same
                        // as the GS1 branch below.
                        $warnings[] = "Asset #{$assetId} ({$assetName}) — Code 128 label could not be rendered (asset tag code contains characters outside printable ASCII, or is too long); printed with the QR code instead.";
                    }
                } else {
                    // ← the asset's PRIMARY identifier matching this GS1
                    // scheme (assetsForLabels()'s barcodeIdentifierValue
                    // subquery) — absent or invalid means fall back to QR
                    // and warn, never fatal (task security musts).
                    $identValue = (string) ($asset['barcodeIdentifierValue'] ?? '');
                    $bc = $identValue !== ''
                        ? Barcode::generate($symbology, $identValue, ['format' => 'png', 'height' => 200, 'moduleWidth' => 3, 'quietModules' => 6])
                        : null;
                    if ($bc !== null && $bc['valid'] === true && ($bc['bytes'] ?? '') !== '') {
                        $barcodeCache[$assetId] = 'data:' . $bc['mime'] . ';base64,' . base64_encode($bc['bytes']);
                    } else {
                        $warnings[] = "Asset #{$assetId} ({$assetName}) has no valid primary "
                            . strtoupper($symbology) . ' identifier — printed with the QR code instead.';
                    }
                }
            }
        }

        // 📋 Flatten into a position queue: $offset leading empty slots
        // (the "start offset" feature — a part-used sheet's already-gone
        // positions), then each asset repeated $copies times consecutively
        // (the design's "copies duplicating each label" decision).
        $queue = array_fill(0, $offset, null);
        foreach ($assets as $asset) {
            for ($i = 0; $i < $copies; $i++) {
                $queue[] = $asset;
            }
        }
        $labelCount = count($assets) * $copies;

        // 🧮 array_chunk() on an EMPTY queue returns an empty array, not a
        // single all-blank sheet — correct here: no assets selected means
        // no sheet to preview/print, not a blank A4 page.
        $sheetsOfCells = array_chunk($queue, $cellsPerSheet);
        $sheetCount = count($sheetsOfCells);

        $html = '';
        foreach ($sheetsOfCells as $sheetIndex => $cells) {
            $isLastSheet = $sheetIndex === $sheetCount - 1;
            // 📄 page-break-after between sheets, not after the last one —
            // meaningful to BOTH dompdf (paginating the PDF) and a real
            // browser's print dialog (the print-CSS fallback path).
            $pageBreak = $isLastSheet === true ? '' : 'page-break-after:always;';
            $html .= '<div class="lbl-sheet" style="' . $pageBreak . '">';
            foreach ($cells as $posIndex => $cell) {
                $row = intdiv($posIndex, $cols);
                $col = $posIndex % $cols;
                $left = $preset['marginLeftMm'] + $col * ($preset['widthMm'] + $preset['colGapMm']);
                $top  = $preset['marginTopMm'] + $row * ($preset['heightMm'] + $preset['rowGapMm']);
                $style = 'left:' . self::mmFmt($left) . 'mm;top:' . self::mmFmt($top) . 'mm;'
                    . 'width:' . self::mmFmt($preset['widthMm']) . 'mm;height:' . self::mmFmt($preset['heightMm']) . 'mm;';
                if ($cell === null) {
                    // ⬜ A reserved/already-used position from the "start
                    // offset" feature — rendered as a bare empty cell so
                    // nothing prints there.
                    $html .= '<div class="lbl-cell lbl-empty" style="' . $style . '"></div>';
                    continue;
                }
                // 🎯 #404 — resolve which of QR/barcode actually show for
                // THIS asset: 'qr' and 'qr+code128' always show the QR;
                // 'qr+code128' additionally shows the barcode when it
                // rendered; any pure barcode symbology shows the barcode
                // ALONE when it rendered, or falls back to QR-alone when
                // it didn't (the warning for that fallback was already
                // recorded once, above, when the cache was built).
                $assetId = (int) ($cell['assetID'] ?? 0);
                $cellSymbology = $symbologyCache[$assetId] ?? 'qr';
                $barcodeReady  = ($barcodeCache[$assetId] ?? null) !== null;
                $showQr      = $qrOn === true && ($cellSymbology === 'qr' || $cellSymbology === 'qr+code128' || $barcodeReady === false);
                $showBarcode = $qrOn === true && $barcodeReady === true && $cellSymbology !== 'qr';
                $qrDataUri      = $showQr === true      ? ($qrCache[$assetId] ?? null)      : null;
                $barcodeDataUri = $showBarcode === true ? ($barcodeCache[$assetId] ?? null) : null;
                $html .= '<div class="lbl-cell" style="' . $style . '">'
                    . self::renderLabelCellInner($cell, $fields, $isSmallTag, $qrDataUri, $barcodeDataUri)
                    . '</div>';
            }
            $html .= '</div>';
        }

        return [
            'css'        => self::labelSheetCss(),
            'html'       => $html,
            'sheetCount' => $sheetCount,
            'labelCount' => $labelCount,
            'warnings'   => $warnings,
        ];
    }

    /**
     * Inner HTML for ONE label cell — QR and/or barcode (both optional)
     * plus the selected text fields, or, for the `'small-tag'` preset, QR
     * + asset tag code ONLY. Every text value is `htmlspecialchars()`'d
     * (see `buildLabelSheets()`'s own SECURITY note).
     *
     * @param array<string, mixed> $asset          A row from `assetsForLabels()`.
     * @param string[]             $fields         Already filtered to LABEL_FIELDS
     *                                              by the caller.
     * @param bool                 $isSmallTag     Small-tag preset ALWAYS
     *                                              ignores `$barcodeDataUri`
     *                                              (#404) — 38×21mm has no
     *                                              room for a QR PLUS a
     *                                              second machine-readable
     *                                              code, same "regardless
     *                                              of the caller's choice"
     *                                              precedent as it already
     *                                              sets for `$fields`.
     * @param string|null          $qrDataUri      Pre-built `data:image/...`
     *                                              string from `buildLabelSheets()`'s
     *                                              cache, or null when QR
     *                                              isn't shown for this cell
     *                                              (barcode is showing
     *                                              instead, or QR is off).
     * @param string|null          $barcodeDataUri Pre-built `data:image/...`
     *                                              string for this asset's
     *                                              chosen barcode symbology
     *                                              (#404), or null when a
     *                                              barcode isn't shown for
     *                                              this cell.
     */
    private static function renderLabelCellInner(array $asset, array $fields, bool $isSmallTag, ?string $qrDataUri, ?string $barcodeDataUri): string
    {
        $html = '';
        if ($qrDataUri !== null) {
            $html .= '<img class="lbl-qr" src="' . htmlspecialchars($qrDataUri, ENT_QUOTES, 'UTF-8') . '" alt="">';
        }

        if ($isSmallTag === true) {
            // 🏷️ Small tag = QR + code only, regardless of $fields OR the
            // asset's own labelSymbology (#404) — a 38×21mm label has no
            // room for more than one short line (see LABEL_PRESETS's own
            // doc). Falls back to a truncated asset name when no
            // assetTagCode is recorded.
            $code = (string) ($asset['assetTagCode'] ?? '');
            if ($code === '') {
                $code = mb_substr((string) ($asset['name'] ?? ''), 0, 16);
            }
            $html .= '<div class="lbl-code">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</div>';
            return $html;
        }

        // 🏷️ #404 — the asset's chosen barcode (Code 128/EAN-13/UPC-A/
        // ITF-14), when buildLabelSheets() resolved one for this cell.
        // Deliberately AFTER the small-tag early-return above so that
        // preset never renders a barcode image regardless of what the
        // caller passed in.
        if ($barcodeDataUri !== null) {
            $html .= '<img class="lbl-barcode" src="' . htmlspecialchars($barcodeDataUri, ENT_QUOTES, 'UTF-8') . '" alt="">';
        }

        // 🗂️ Fixed canonical order regardless of the order $fields arrived
        // in (LABEL_FIELDS itself defines the print order — a
        // reordered/tampered fields[] can select WHICH lines show, never
        // reorder them).
        $valueMap = [
            'name'         => $asset['name']          ?? null,
            'assetTagCode' => $asset['assetTagCode']  ?? null,
            'category'     => $asset['categoryName']  ?? null,
            'location'     => $asset['locationName']  ?? null,
            'owningOrg'    => $asset['owningOrgName'] ?? null,
            'serial'       => $asset['serialNumber']  ?? null,
        ];
        $html .= '<div class="lbl-text">';
        foreach (self::LABEL_FIELDS as $key) {
            if (in_array($key, $fields, true) === false) {
                continue;
            }
            $value = $valueMap[$key] ?? null;
            if ($value === null || (string) $value === '') {
                continue;
            }
            $html .= '<div class="lf lf-' . $key . '">' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * The `<style>` block BOTH `labels.php`'s preview and
     * `labels-pdf.php`'s PDF/print-CSS fallback embed verbatim — see
     * `buildLabelSheets()`'s own doc for why this is the mechanism behind
     * "the same CSS the PDF will use". A4-sized `.lbl-sheet` blocks;
     * `page-break-after` (set per-sheet by `buildLabelSheets()` above) is
     * meaningful to BOTH dompdf and a real browser's print dialog, so one
     * rule set serves both without an `@media print` branch of its own.
     */
    private static function labelSheetCss(): string
    {
        return <<<'CSS'
            @page { size: A4 portrait; margin: 0; }
            .lbl-sheet {
                position: relative;
                width: 210mm;
                height: 297mm;
                background: #fff;
                font-family: Arial, Helvetica, sans-serif;
                color: #000;
            }
            .lbl-cell {
                position: absolute;
                box-sizing: border-box;
                overflow: hidden;
                padding: 1.5mm;
                border: 0.25pt dotted #ccc;
                display: flex;
                align-items: center;
                gap: 1.5mm;
            }
            .lbl-qr { width: 14mm; height: 14mm; flex: 0 0 auto; }
            .lbl-barcode { width: 26mm; height: 11mm; flex: 0 0 auto; object-fit: contain; }
            .lbl-text { min-width: 0; overflow: hidden; }
            .lf {
                font-size: 7pt;
                line-height: 1.2;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .lf-name { font-weight: bold; font-size: 8.5pt; }
            .lbl-code {
                font-weight: bold;
                font-size: 9pt;
                text-align: center;
                flex: 1 1 auto;
            }
            CSS;
    }

    /**
     * Format a millimetre value for inline CSS — trims trailing zeros (a
     * float chain like `7.2000000000000` reads badly and needlessly
     * bloats the HTML across a few hundred repeated cells; both dompdf and
     * every real browser accept "7.2mm" and "63mm" equally well).
     */
    private static function mmFmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    /* ==========================================================================
     * 📅 Event assignments (#409, Phase 2 Pass 2) — see class header point 10.
     * ======================================================================== */

    /**
     * List an asset's event assignments, newest-assigned first. Joined to
     * the event's own name/slug/window so item.php's Assigned-events panel
     * never needs a second round-trip per row.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listEventAssignments(int $assetId): array
    {
        $db = App::db();
        $siteId = Site::id();

        $stmt = $db->prepare(
            'SELECT ea.*, e.eventName, e.eventSlug, e.startDateTime, e.endDateTime, '
            . '       u.fullName AS assignedByName '
            . 'FROM tblAssetEventAssignments ea '
            . 'JOIN tblEvents e ON e.eventID = ea.eventID '
            . 'LEFT JOIN tblUsers u ON u.userID = ea.assignedByID '
            . 'WHERE ea.assetID = ? AND ea.siteID = ? '
            . 'ORDER BY ea.createdAt DESC'
        );
        if ($stmt === false) {
            error_log('AssetRegister::listEventAssignments() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('ii', $assetId, $siteId);
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
     * List the assets assigned to one event, alphabetical by asset name —
     * the read behind the calendar event page's own "Assigned assets"
     * section. `$siteId` is an explicit parameter (unlike most reads in
     * this class, which pull `Site::id()` themselves) because the calendar
     * app's own controllers already resolve/pass their own site context;
     * this mirrors that convention rather than silently re-deriving it.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listAssetsForEvent(int $eventId, int $siteId): array
    {
        $db = App::db();

        $stmt = $db->prepare(
            'SELECT ea.assignmentID, ea.assetID, ea.assignedFrom, ea.assignedUntil, ea.notes, '
            . '       a.name AS assetName, a.assetTagCode, a.status AS assetStatus '
            . 'FROM tblAssetEventAssignments ea '
            . 'JOIN tblAssets a ON a.assetID = ea.assetID AND a.isDeleted = 0 '
            . 'WHERE ea.eventID = ? AND ea.siteID = ? '
            . 'ORDER BY a.name ASC'
        );
        if ($stmt === false) {
            error_log('AssetRegister::listAssetsForEvent() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('ii', $eventId, $siteId);
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
     * Assign an asset to an event. Does NOT trust its caller (see class
     * header point 10): re-confirms the asset via self::get() AND
     * independently re-validates the posted eventID exists on THIS site
     * (mirrors `_apps/documents/upload.php`'s own eventID re-validation —
     * never trust a posted eventID just because a form supplied one).
     *
     * Validates:
     *   - The asset exists and is on this site (self::get()).
     *   - eventID is a real, non-deleted event on Site::id().
     *   - assignedFrom/assignedUntil, when supplied, are real datetimes
     *     (parseOptionalDateTime()) with assignedFrom <= assignedUntil
     *     when both are given.
     *
     * ADVISORY (never blocking — see class header's "two-tier" note): warns
     * when the asset has an active loan, or when another event assignment
     * for this asset overlaps the requested window (each open end of the
     * window defaults to the EVENT's own start/end, mirroring migration
     * 160's column comments) — the caller decides whether to surface those
     * warnings, the assignment is saved either way.
     *
     * The `uq_astev_asset_event` unique key (one row per asset+event pair)
     * is caught via \mysqli_sql_exception into a friendly "already
     * assigned" warning — same catch shape as addIdentifier() above.
     *
     * $data keys: eventID (int), assignedFrom (datetime string|''),
     * assignedUntil (datetime string|''), notes.
     *
     * @param array<string, mixed> $data
     *
     * @return array{id: int, warnings: string[]}
     */
    public static function assignToEvent(int $assetId, array $data, int $actorUserId): array
    {
        $db     = App::db();
        $siteId = Site::id();

        // 🔒 Asset must exist and be on this site — get() is itself
        // site-scoped via Site::id().
        if (self::get($assetId) === null) {
            error_log('AssetRegister::assignToEvent() asset not found on this site: #' . $assetId);
            return ['id' => 0, 'warnings' => ['Asset not found.']];
        }

        // 🔒 DOUBLE IDOR guard, half 2 — the eventID is re-validated against
        // THIS site from scratch, exactly like the asset lookup above.
        // NEVER trust a posted eventID.
        $eventId = (int) ($data['eventID'] ?? 0);
        if ($eventId <= 0) {
            return ['id' => 0, 'warnings' => ['An event is required.']];
        }
        $eStmt = $db->prepare(
            'SELECT eventID, eventName, startDateTime, endDateTime FROM tblEvents '
            . 'WHERE eventID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
        );
        if ($eStmt === false) {
            error_log('AssetRegister::assignToEvent() event lookup prepare failed: ' . $db->error);
            return ['id' => 0, 'warnings' => ['Could not verify the event — please try again.']];
        }
        $eStmt->bind_param('ii', $eventId, $siteId);
        $eStmt->execute();
        $event = $eStmt->get_result()->fetch_assoc();
        $eStmt->close();
        if ($event === null || $event === false) {
            error_log('AssetRegister::assignToEvent() event not found on this site: #' . $eventId);
            return ['id' => 0, 'warnings' => ['Event not found.']];
        }

        // 📅 Optional assignment window — parseOptionalDateTime() returns
        // null (not set — use the event's own window), a normalised
        // 'Y-m-d H:i:s' string, or false (invalid, reject the whole op).
        $assignedFrom = self::parseOptionalDateTime($data['assignedFrom'] ?? '');
        if ($assignedFrom === false) {
            return ['id' => 0, 'warnings' => ['Invalid "assigned from" date/time.']];
        }
        $assignedUntil = self::parseOptionalDateTime($data['assignedUntil'] ?? '');
        if ($assignedUntil === false) {
            return ['id' => 0, 'warnings' => ['Invalid "assigned until" date/time.']];
        }
        if ($assignedFrom !== null && $assignedUntil !== null && $assignedFrom > $assignedUntil) {
            return ['id' => 0, 'warnings' => ['"Assigned from" must be before "assigned until".']];
        }

        $notes = trim((string) ($data['notes'] ?? ''));
        $notes = $notes !== '' ? mb_substr($notes, 0, 500) : null;

        $warnings = [];

        // 🚦 ADVISORY 1 — an active loan on this asset. Doesn't inspect the
        // loan's own dates (a loan has no fixed "until", see tblAssetLoans'
        // own dueDate semantics) — merely flags that the asset is
        // currently out, so a manager double-books with their eyes open.
        $loanStmt = $db->prepare(
            "SELECT 1 FROM tblAssetLoans WHERE assetID = ? AND siteID = ? AND status = 'active' LIMIT 1"
        );
        if ($loanStmt !== false) {
            $loanStmt->bind_param('ii', $assetId, $siteId);
            $loanStmt->execute();
            if ($loanStmt->get_result()->fetch_assoc() !== null) {
                $warnings[] = 'This asset is currently out on an active loan.';
            }
            $loanStmt->close();
        }

        // 🚦 ADVISORY 2 — another event assignment for this asset whose
        // window overlaps the one being requested. Each side's open end
        // defaults to the EVENT's own start/end (COALESCE), mirroring
        // migration 160's column comments and this method's own docblock.
        $windowStart = $assignedFrom ?? (string) $event['startDateTime'];
        $windowEnd   = $assignedUntil ?? (string) ($event['endDateTime'] ?? $event['startDateTime']);
        $overlapStmt = $db->prepare(
            'SELECT e.eventName FROM tblAssetEventAssignments ea '
            . 'JOIN tblEvents e ON e.eventID = ea.eventID '
            . 'WHERE ea.assetID = ? AND ea.siteID = ? AND ea.eventID != ? '
            . 'AND COALESCE(ea.assignedFrom, e.startDateTime) <= ? '
            . 'AND COALESCE(ea.assignedUntil, e.endDateTime, e.startDateTime) >= ? '
            . 'LIMIT 1'
        );
        if ($overlapStmt !== false) {
            $overlapStmt->bind_param('iiiss', $assetId, $siteId, $eventId, $windowEnd, $windowStart);
            $overlapStmt->execute();
            $overlapRow = $overlapStmt->get_result()->fetch_assoc();
            $overlapStmt->close();
            if ($overlapRow !== null && $overlapRow !== false) {
                $warnings[] = 'This asset overlaps another event assignment ("' . (string) $overlapRow['eventName'] . '") in this window.';
            }
        }

        $fields = [
            'siteID'        => [$siteId, 'i'],
            'assetID'       => [$assetId, 'i'],
            'eventID'       => [$eventId, 'i'],
            'assignedByID'  => [$actorUserId, 'i'],
            'assignedFrom'  => [$assignedFrom, 's'],
            'assignedUntil' => [$assignedUntil, 's'],
            'notes'         => [$notes, 's'],
        ];
        ['columns' => $columns, 'types' => $types, 'params' => $params] = self::splitFields($fields);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $newId = 0;
        try {
            $stmt = $db->prepare('INSERT INTO tblAssetEventAssignments (`' . implode('`, `', $columns) . '`) VALUES (' . $placeholders . ')');
            if ($stmt === false) {
                throw new \RuntimeException('Failed to prepare event-assignment insert: ' . $db->error);
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $newId = (int) $stmt->insert_id;
            $stmt->close();
        } catch (\mysqli_sql_exception $e) {
            // 🪞 Most likely cause: uq_astev_asset_event — this asset is
            // already assigned to this event. Friendly, non-fatal warning
            // rather than a 500 — see method doc.
            error_log('AssetRegister::assignToEvent() insert failed: ' . $e->getMessage());
            return ['id' => 0, 'warnings' => ['This asset is already assigned to this event.']];
        } catch (\Throwable $e) {
            error_log('AssetRegister::assignToEvent() failed: ' . $e->getMessage());
            return ['id' => 0, 'warnings' => ['Could not assign the asset — please try again.']];
        }

        if ($newId <= 0) {
            return ['id' => 0, 'warnings' => ['Could not assign the asset — please try again.']];
        }

        $auditNew = [];
        foreach ($fields as $col => $pair) {
            $auditNew[$col] = $pair[0];
        }
        self::audit('event-link', $newId, $assetId, 'create', null, $auditNew);

        return ['id' => $newId, 'warnings' => $warnings];
    }

    /**
     * Remove an event-assignment row. IDOR guard: the row must belong to
     * BOTH $assetId AND the current site before it's touched — mirrors
     * removeOwner()'s own "confirm it belongs to this asset first" pattern.
     *
     * @return bool True if a row existed (for this asset, on this site)
     *              and was removed
     */
    public static function unassignFromEvent(int $assignmentId, int $assetId, int $actorUserId): bool
    {
        $db     = App::db();
        $siteId = Site::id();

        $stmt = $db->prepare('SELECT * FROM tblAssetEventAssignments WHERE assignmentID = ? AND assetID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iii', $assignmentId, $assetId, $siteId);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($old === null || $old === false) {
            return false;
        }

        $delStmt = $db->prepare('DELETE FROM tblAssetEventAssignments WHERE assignmentID = ? AND assetID = ? AND siteID = ?');
        if ($delStmt === false) {
            return false;
        }
        $delStmt->bind_param('iii', $assignmentId, $assetId, $siteId);
        $ok = $delStmt->execute();
        $affected = $delStmt->affected_rows;
        $delStmt->close();

        if ($ok === false || $affected <= 0) {
            return false;
        }

        self::audit('event-link', $assignmentId, $assetId, 'delete', $old, null);

        return true;
    }

    /* ==========================================================================
     * 🔍 Scan log + "my assets" (#410, Phase 2 Pass 2) — see class header
     * point 11.
     * ======================================================================== */

    /**
     * Insert one tblAssetScanLog row. NEVER throws — wrapped in its own
     * try/catch so a logging failure can never break the page that called
     * it (the public lost-and-found page, or an internal manager re-scan).
     * Stores ONLY salted hashes (ipHash/userAgentHash — see saltedHash()'s
     * doc above); the raw IP/User-Agent are never persisted anywhere.
     *
     * This is DELIBERATELY separate from self::audit()'s existing
     * `audit('token', …, 'scan')` event (#401) — tag.php calls BOTH,
     * side by side, on the same view; see class header point 11 for why
     * two records exist for the one event.
     *
     * @param string   $context 'public' (the /a/{token} page) or 'internal'
     *                          (a logged-in manager re-scan) — anything
     *                          else silently falls back to 'public' (the
     *                          column's own schema default) rather than
     *                          failing the write.
     * @param int|null $actorUserId Session user id for an internal scan;
     *                          null for an anonymous public one.
     */
    public static function recordScan(int $assetId, string $context, ?int $actorUserId): void
    {
        if ($assetId <= 0) {
            return;
        }
        try {
            $db     = App::db();
            $siteId = Site::id();
            $context = in_array($context, self::SCAN_CONTEXTS, true) === true ? $context : 'public';
            $ipHash = self::ipHash();
            $uaHash = self::userAgentHash();

            $stmt = $db->prepare(
                'INSERT INTO tblAssetScanLog (siteID, assetID, scanContext, actorUserID, ipHash, userAgentHash) '
                . 'VALUES (?, ?, ?, ?, ?, ?)'
            );
            if ($stmt === false) {
                error_log('AssetRegister::recordScan() prepare failed: ' . $db->error);
                return;
            }
            $stmt->bind_param('iisiss', $siteId, $assetId, $context, $actorUserId, $ipHash, $uaHash);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            // 🛟 Never fatal — see method doc. Logged for diagnosis only.
            error_log('AssetRegister::recordScan() failed: ' . $e->getMessage());
        }
    }

    /**
     * Daily scan counts for one asset over the trailing $days, oldest
     * first, with EVERY day represented (zero-filled) so item.php's
     * manager-only sparkbar renders a consistent number of bars regardless
     * of how few days actually had a scan. Uses idx_astscn_asset_created.
     *
     * @return array<int, array{date: string, count: int}>
     */
    public static function scanStats(int $assetId, int $days = 30): array
    {
        $db = App::db();
        $days = max(1, min($days, 365));

        $stmt = $db->prepare(
            'SELECT DATE(createdAt) AS scanDate, COUNT(*) AS cnt FROM tblAssetScanLog '
            . 'WHERE assetID = ? AND createdAt >= DATE_SUB(CURDATE(), INTERVAL ? DAY) '
            . 'GROUP BY DATE(createdAt)'
        );
        if ($stmt === false) {
            error_log('AssetRegister::scanStats() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('ii', $assetId, $days);
        $stmt->execute();
        $result = $stmt->get_result();
        $counts = [];
        while ($row = $result->fetch_assoc()) {
            $counts[(string) $row['scanDate']] = (int) $row['cnt'];
        }
        $stmt->close();

        return self::zeroFillDailyCounts($counts, $days);
    }

    /**
     * Site-wide equivalent of scanStats() above — same zero-filled shape,
     * grouped across every asset on the site. Uses idx_astscn_site_created.
     *
     * @return array<int, array{date: string, count: int}>
     */
    public static function scanStatsForSite(int $siteId, int $days = 30): array
    {
        $db = App::db();
        $days = max(1, min($days, 365));

        $stmt = $db->prepare(
            'SELECT DATE(createdAt) AS scanDate, COUNT(*) AS cnt FROM tblAssetScanLog '
            . 'WHERE siteID = ? AND createdAt >= DATE_SUB(CURDATE(), INTERVAL ? DAY) '
            . 'GROUP BY DATE(createdAt)'
        );
        if ($stmt === false) {
            error_log('AssetRegister::scanStatsForSite() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('ii', $siteId, $days);
        $stmt->execute();
        $result = $stmt->get_result();
        $counts = [];
        while ($row = $result->fetch_assoc()) {
            $counts[(string) $row['scanDate']] = (int) $row['cnt'];
        }
        $stmt->close();

        return self::zeroFillDailyCounts($counts, $days);
    }

    /**
     * Shared zero-fill pass for scanStats()/scanStatsForSite() above —
     * expands a sparse {date: count} map into a dense, oldest-first array
     * covering EVERY day in the trailing $days window.
     *
     * @param array<string, int> $counts
     *
     * @return array<int, array{date: string, count: int}>
     */
    private static function zeroFillDailyCounts(array $counts, int $days): array
    {
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime('-' . $i . ' days'));
            $result[] = ['date' => $d, 'count' => $counts[$d] ?? 0];
        }
        return $result;
    }

    /**
     * Delete tblAssetScanLog rows older than
     * `assets.scan_log_retention_days` (seeded '365' by migration 160) for
     * one site. Provided now, wired into the #405 reminder-sweep cron in a
     * later pass — same "method ships now, caller lands later" precedent
     * as purgeExpiredFoundReports() (class header point 8).
     *
     * @return int Number of rows deleted
     */
    public static function purgeExpiredScanLog(int $siteId): int
    {
        $db = App::db();

        $retentionDays = (int) (App::settings('assets.scan_log_retention_days') ?? 365);
        if ($retentionDays <= 0) {
            $retentionDays = 365;
        }

        $stmt = $db->prepare(
            'DELETE FROM tblAssetScanLog WHERE siteID = ? AND createdAt < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        if ($stmt === false) {
            error_log('AssetRegister::purgeExpiredScanLog() prepare failed: ' . $db->error);
            return 0;
        }
        $stmt->bind_param('ii', $siteId, $retentionDays);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        return (int) $deleted;
    }

    /**
     * List the assets the given user is connected to — the read behind
     * `_apps/assets/my.php`. STRICTLY scoped to $userId (the caller MUST
     * pass the session user's own id — see my.php's own header for why no
     * id parameter is ever accepted there) and Site::id(). Three
     * independent ways an asset can appear, folded together and
     * de-duplicated by assetID (an asset the viewer both owns AND
     * currently has on loan appears ONCE, with both reasons listed):
     *
     *   - 'owner'    — a tblAssetOwners row for this user, DIRECT or via a
     *                  dept/group they belong to. Reuses isResponsibleFor()'s
     *                  exact three joins (direct/dept/group), just run as
     *                  three ordinary SELECTs here rather than three
     *                  early-return existence checks.
     *   - 'on-loan'  — an active tblAssetLoans row with this user as
     *                  counterpartyUserID (works for EITHER direction —
     *                  counterpartyUserID is only ever set when
     *                  counterpartyType='user' regardless of whether the
     *                  site is lending out or borrowing in).
     *   - 'licensed' — an active tblAssetLicenseAssignments seat for this
     *                  user.
     *
     * @return array<int, array<string, mixed>> Each row carries the asset's
     *         core display fields PLUS `reasons` (string[], a subset of
     *         'owner'|'on-loan'|'licensed'), `loanDueDate`/`loanIsOverdue`
     *         (only meaningful when 'on-loan' is in reasons), and
     *         `seatLabel` (only meaningful when 'licensed' is in reasons).
     */
    public static function listForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $db     = App::db();
        $siteId = Site::id();
        $rows   = []; // assetID => row, accumulated across the three sources below

        // 👤 1a. Direct ownership.
        $stmt = $db->prepare(
            'SELECT a.assetID, a.name, a.assetKind, a.assetTagCode, a.status, a.conditionState '
            . 'FROM tblAssetOwners o JOIN tblAssets a ON a.assetID = o.assetID '
            . 'WHERE o.partyType = "user" AND o.userID = ? AND a.siteID = ? AND a.isDeleted = 0'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $userId, $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                self::foldUserAssetRow($rows, $row, 'owner');
            }
            $stmt->close();
        }

        // 🏢 1b. Dept ownership — mirrors isResponsibleFor()'s dept join.
        $stmt = $db->prepare(
            'SELECT a.assetID, a.name, a.assetKind, a.assetTagCode, a.status, a.conditionState '
            . 'FROM tblAssetOwners o '
            . 'JOIN tblUserDepts ud ON ud.deptID = o.deptID '
            . 'JOIN tblAssets a ON a.assetID = o.assetID '
            . "WHERE o.partyType = 'dept' AND ud.userID = ? AND a.siteID = ? AND a.isDeleted = 0"
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $userId, $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                self::foldUserAssetRow($rows, $row, 'owner');
            }
            $stmt->close();
        }

        // 👥 1c. Group ownership — mirrors isResponsibleFor()'s group join.
        $stmt = $db->prepare(
            'SELECT a.assetID, a.name, a.assetKind, a.assetTagCode, a.status, a.conditionState '
            . 'FROM tblAssetOwners o '
            . 'JOIN tblUserGroups ug ON ug.groupID = o.groupID '
            . 'JOIN tblAssets a ON a.assetID = o.assetID '
            . "WHERE o.partyType = 'group' AND ug.userID = ? AND a.siteID = ? AND a.isDeleted = 0"
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $userId, $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                self::foldUserAssetRow($rows, $row, 'owner');
            }
            $stmt->close();
        }

        // 🔄 2. Active loans to this user.
        $stmt = $db->prepare(
            'SELECT a.assetID, a.name, a.assetKind, a.assetTagCode, a.status, a.conditionState, '
            . '       l.dueDate AS loanDueDate '
            . 'FROM tblAssetLoans l JOIN tblAssets a ON a.assetID = l.assetID '
            . "WHERE l.counterpartyUserID = ? AND l.status = 'active' AND a.siteID = ? AND a.isDeleted = 0"
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $userId, $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            $today = date('Y-m-d');
            while ($row = $result->fetch_assoc()) {
                $loanDueDate = $row['loanDueDate'];
                unset($row['loanDueDate']);
                self::foldUserAssetRow($rows, $row, 'on-loan', [
                    'loanDueDate'   => $loanDueDate,
                    'loanIsOverdue' => $loanDueDate !== null && (string) $loanDueDate < $today,
                ]);
            }
            $stmt->close();
        }

        // 🔑 3. Active licence seats assigned to this user.
        $stmt = $db->prepare(
            'SELECT a.assetID, a.name, a.assetKind, a.assetTagCode, a.status, a.conditionState, '
            . '       la.seatLabel AS licSeatLabel '
            . 'FROM tblAssetLicenseAssignments la JOIN tblAssets a ON a.assetID = la.licenseAssetID '
            . "WHERE la.userID = ? AND la.status = 'active' AND a.siteID = ? AND a.isDeleted = 0"
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $userId, $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $seatLabel = $row['licSeatLabel'];
                unset($row['licSeatLabel']);
                self::foldUserAssetRow($rows, $row, 'licensed', ['seatLabel' => $seatLabel]);
            }
            $stmt->close();
        }

        $result = array_values($rows);
        usort($result, static fn (array $x, array $y): int => strcmp((string) $x['name'], (string) $y['name']));
        return $result;
    }

    /**
     * Fold one asset row + reason into listForUser()'s accumulator by
     * reference, de-duplicating by assetID — a second/third source hitting
     * the SAME asset appends its reason (and any $extra fields) onto the
     * row already there rather than creating a duplicate entry.
     *
     * @param array<int, array<string, mixed>> $rows Accumulator, by reference
     * @param array<string, mixed>             $assetRow Must carry at least assetID/name/assetKind/assetTagCode/status/conditionState
     * @param array<string, mixed>             $extra Additional fields to merge onto the row (e.g. loanDueDate)
     */
    private static function foldUserAssetRow(array &$rows, array $assetRow, string $reason, array $extra = []): void
    {
        $assetId = (int) $assetRow['assetID'];
        if (isset($rows[$assetId]) === false) {
            $rows[$assetId] = [
                'assetID'        => $assetId,
                'name'           => (string) $assetRow['name'],
                'assetKind'      => (string) $assetRow['assetKind'],
                'assetTagCode'   => $assetRow['assetTagCode'],
                'status'         => (string) $assetRow['status'],
                'conditionState' => (string) $assetRow['conditionState'],
                'reasons'        => [],
                'loanDueDate'    => null,
                'loanIsOverdue'  => false,
                'seatLabel'      => null,
            ];
        }
        if (in_array($reason, $rows[$assetId]['reasons'], true) === false) {
            $rows[$assetId]['reasons'][] = $reason;
        }
        foreach ($extra as $key => $value) {
            $rows[$assetId][$key] = $value;
        }
    }

    /* ==========================================================================
     * ⏰ Reminder sweep read helpers (#405, Phase 2 Pass 3) — feed
     * `_apps/cron/asset-reminders.php`. Every method here is explicitly
     * `$siteId`-scoped (never ambient `Site::id()`) so the cron can iterate
     * every site in one process. See class header point 12 for the full
     * design rationale.
     * ======================================================================== */

    /**
     * Scheduled maintenance whose `nextDueDate` falls within
     * `[today, today+leadDays]` inclusive — extends `listUpcomingMaintenance()`
     * (which returns EVERY scheduled item with any due date, past or
     * future) down to just the items the #405 sweep should remind about
     * today. Newest-due-first is wrong for a reminder feed, so this is
     * ordered soonest-first, same as `listUpcomingMaintenance()`.
     *
     * @return array<int, array<string, mixed>> Each row: maintID, assetID,
     *         title, maintType, nextDueDate, assetName, assetTagCode
     */
    public static function listDueMaintenanceReminders(int $siteId, int $leadDays): array
    {
        $db = App::db();
        $leadDays = max(0, $leadDays);

        $stmt = $db->prepare(
            'SELECT m.maintID, m.assetID, m.title, m.maintType, m.nextDueDate, '
            . '       a.name AS assetName, a.assetTagCode '
            . 'FROM tblAssetMaintenance m '
            . 'JOIN tblAssets a ON a.assetID = m.assetID AND a.isDeleted = 0 '
            . "WHERE m.siteID = ? AND m.status = 'scheduled' AND m.nextDueDate IS NOT NULL "
            . '  AND m.nextDueDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) '
            . 'ORDER BY m.nextDueDate ASC'
        );
        if ($stmt === false) {
            error_log('AssetRegister::listDueMaintenanceReminders() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('ii', $siteId, $leadDays);
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
     * Assets whose `warrantyExpiry` falls within `[today, today+leadDays]`
     * inclusive.
     *
     * @return array<int, array<string, mixed>> Each row: assetID, name,
     *         assetTagCode, warrantyExpiry
     */
    public static function listExpiringWarranties(int $siteId, int $leadDays): array
    {
        $db = App::db();
        $leadDays = max(0, $leadDays);

        $stmt = $db->prepare(
            'SELECT assetID, name, assetTagCode, warrantyExpiry FROM tblAssets '
            . 'WHERE siteID = ? AND isDeleted = 0 AND warrantyExpiry IS NOT NULL '
            . '  AND warrantyExpiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) '
            . 'ORDER BY warrantyExpiry ASC'
        );
        if ($stmt === false) {
            error_log('AssetRegister::listExpiringWarranties() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('ii', $siteId, $leadDays);
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
     * Assets whose `insuranceRenewalDate` falls within
     * `[today, today+leadDays]` inclusive (#404 columns).
     *
     * @return array<int, array<string, mixed>> Each row: assetID, name,
     *         assetTagCode, insuranceRenewalDate, insurerName,
     *         insurancePolicyNumber
     */
    public static function listExpiringInsurance(int $siteId, int $leadDays): array
    {
        $db = App::db();
        $leadDays = max(0, $leadDays);

        $stmt = $db->prepare(
            'SELECT assetID, name, assetTagCode, insuranceRenewalDate, insurerName, insurancePolicyNumber '
            . 'FROM tblAssets '
            . 'WHERE siteID = ? AND isDeleted = 0 AND insuranceRenewalDate IS NOT NULL '
            . '  AND insuranceRenewalDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) '
            . 'ORDER BY insuranceRenewalDate ASC'
        );
        if ($stmt === false) {
            error_log('AssetRegister::listExpiringInsurance() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('ii', $siteId, $leadDays);
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
     * Active loans past their `dueDate` — a thin wrapper over the existing
     * `listLoans()` (#398) `overdueOnly` filter. `$includeConfidential =
     * true` deliberately — this is a system sweep that needs to notify
     * about every overdue loan regardless of the asset's confidentiality
     * flag, mirroring `listLoansForAsset()`'s own "nothing left to
     * restrict" rationale (see that method's doc).
     *
     * @return array<int, array<string, mixed>> Same row shape as listLoans()
     */
    public static function listOverdueLoans(int $siteId): array
    {
        return self::listLoans($siteId, ['overdueOnly' => true], true);
    }

    /**
     * Resolve the email audience for a reminder about ONE asset. Derives
     * the asset's own `siteID` fresh from `tblAssets` (never the caller's
     * ambient `Site::id()`) so this is safe to call regardless of which
     * site the cron currently has forced — see class header point 12.
     *
     * Two parts, both always attempted:
     *   1. Owner-authority holders — direct/dept/group `tblAssetOwners`
     *      rows carrying the flag `$authority` maps to (`'maintenance'` →
     *      `isMaintenanceAuthority`, `'lending'` → `isLendingAuthority`;
     *      any OTHER value, e.g. an insurance/warranty reminder that has
     *      no dedicated owner-authority flag of its own, simply skips this
     *      part entirely). Mirrors `canApproveLoan()`/
     *      `canManageMaintenance()`'s own three joins (point 5/6 above).
     *   2. Every active site admin/root-admin/site-root-admin/asset_manager
     *      role-holder on this asset's site, as a fallback audience —
     *      mirrors `found-save.php`'s own admin-notify query (see that
     *      file's header), widened to the SAME 4-tier hierarchy
     *      `App::isAdmin()` checks (found-save.php's version only checked
     *      the legacy `tblUsers.isAdmin` flag).
     *
     * Every candidate address is independently `FILTER_VALIDATE_EMAIL`'d
     * before being returned — a blank/malformed `emailAddress` on an
     * otherwise-matching row is silently dropped, never mailed to.
     *
     * @return string[] De-duplicated, validated email addresses
     */
    public static function resolveReminderRecipients(int $assetId, string $authority): array
    {
        if ($assetId <= 0) {
            return [];
        }

        $db = App::db();

        // 🌐 Resolve the OWNING site fresh from the asset row itself —
        // never Site::id() — so this method is correct no matter which
        // site the caller currently has forced (or none at all).
        $siteStmt = $db->prepare('SELECT siteID FROM tblAssets WHERE assetID = ? AND isDeleted = 0 LIMIT 1');
        if ($siteStmt === false) {
            return [];
        }
        $siteStmt->bind_param('i', $assetId);
        $siteStmt->execute();
        $siteRow = $siteStmt->get_result()->fetch_assoc();
        $siteStmt->close();
        if ($siteRow === null) {
            return [];
        }
        $siteId = (int) $siteRow['siteID'];

        $emails = [];

        // 👤 1. Owner-authority holders (direct/dept/group), narrowed to a
        // known OWNER_AUTHORITY_FIELDS column — see that constant's own
        // doc for why a value drawn from this small closed allow-list (and
        // ONLY from it) is safe to interpolate directly into the SQL below,
        // exactly like setOwnerAuthority()'s own SET-clause column name.
        $authorityColumn = match ($authority) {
            'maintenance' => 'isMaintenanceAuthority',
            'lending'     => 'isLendingAuthority',
            default       => null,
        };
        if ($authorityColumn !== null && in_array($authorityColumn, self::OWNER_AUTHORITY_FIELDS, true) === true) {
            $joins = [
                // direct
                'JOIN tblUsers u ON u.userID = o.userID WHERE o.assetID = ? AND o.partyType = "user" AND o.' . $authorityColumn . ' = 1',
                // dept
                'JOIN tblUserDepts ud ON ud.deptID = o.deptID JOIN tblUsers u ON u.userID = ud.userID WHERE o.assetID = ? AND o.partyType = "dept" AND o.' . $authorityColumn . ' = 1',
                // group
                'JOIN tblUserGroups ug ON ug.groupID = o.groupID JOIN tblUsers u ON u.userID = ug.userID WHERE o.assetID = ? AND o.partyType = "group" AND o.' . $authorityColumn . ' = 1',
            ];
            foreach ($joins as $joinSql) {
                $stmt = $db->prepare(
                    'SELECT DISTINCT u.emailAddress AS email FROM tblAssetOwners o '
                    . $joinSql
                    . ' AND u.isActive = 1 AND u.emailAddress IS NOT NULL AND u.emailAddress != ""'
                );
                if ($stmt !== false) {
                    $stmt->bind_param('i', $assetId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $emails[] = (string) $row['email'];
                    }
                    $stmt->close();
                }
            }
        }

        // 🛡️ 2. Site admins / asset_manager role-holders — ALWAYS included
        // as the fallback audience, regardless of whether part 1 above
        // found anyone. Widens found-save.php's own admin-notify query
        // (which only checked tblUsers.isAdmin) to the full 4-tier
        // hierarchy App::isAdmin() checks (tblUsers.isRootAdmin,
        // tblUserSites.isSiteAdmin/isSiteRootAdmin, legacy tblUsers.isAdmin)
        // plus the asset_manager role.
        $roleKey = 'asset_manager';
        $stmt = $db->prepare(
            'SELECT DISTINCT u.emailAddress AS email FROM tblUsers u '
            . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
            . 'LEFT JOIN tblUserRoles ur ON ur.userID = u.userID '
            . 'LEFT JOIN tblRoles r ON r.roleID = ur.roleID '
            . 'WHERE u.isActive = 1 AND u.emailAddress IS NOT NULL AND u.emailAddress != "" '
            . 'AND (u.isAdmin = 1 OR u.isRootAdmin = 1 OR us.isSiteAdmin = 1 OR us.isSiteRootAdmin = 1 OR r.roleKey = ?)'
        );
        if ($stmt !== false) {
            $stmt->bind_param('is', $siteId, $roleKey);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $emails[] = (string) $row['email'];
            }
            $stmt->close();
        }

        // 🧼 De-duplicate AND validate every candidate before returning —
        // callers (the cron) still validate again at send-time, but this
        // method's own contract is "a clean, ready-to-mail list".
        $emails = array_values(array_unique($emails));
        return array_values(array_filter(
            $emails,
            static fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false
        ));
    }

    /**
     * Resolve ONE loan's own counterparty (borrower/lender) contact
     * address — companion to resolveReminderRecipients() for the
     * loan-overdue reminder family, which must reach the actual
     * counterparty on top of the lending authority (see #405's security
     * musts — loan-overdue mail is scoped narrower than the other three
     * families). Mirrors listLoans()'s own `counterpartyDisplayName`
     * resolution (point 5 above) but returns a mailable address instead
     * of a display name.
     *
     * @param array<string, mixed> $loan A row from listLoans()/
     *        listOverdueLoans() — must carry counterpartyType,
     *        counterpartyUserID, counterpartyOrgID, counterpartyContact.
     *
     * @return string|null A validated email address, or null when the
     *         counterparty has no usable one on file
     */
    public static function resolveLoanCounterpartyEmail(array $loan): ?string
    {
        $db = App::db();
        $type = (string) ($loan['counterpartyType'] ?? '');

        if ($type === 'user' && ($loan['counterpartyUserID'] ?? null) !== null) {
            $userId = (int) $loan['counterpartyUserID'];
            $stmt = $db->prepare(
                'SELECT emailAddress FROM tblUsers WHERE userID = ? AND isActive = 1 '
                . 'AND emailAddress IS NOT NULL AND emailAddress != "" LIMIT 1'
            );
            if ($stmt !== false) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $email = $row !== null ? (string) $row['emailAddress'] : null;
                return ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) ? $email : null;
            }
            return null;
        }

        if ($type === 'org' && ($loan['counterpartyOrgID'] ?? null) !== null) {
            $orgId = (int) $loan['counterpartyOrgID'];
            $stmt = $db->prepare(
                'SELECT contactEmail FROM tblAssetOrgs WHERE orgID = ? AND isActive = 1 '
                . 'AND contactEmail IS NOT NULL AND contactEmail != "" LIMIT 1'
            );
            if ($stmt !== false) {
                $stmt->bind_param('i', $orgId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $email = $row !== null ? (string) $row['contactEmail'] : null;
                return ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) ? $email : null;
            }
            return null;
        }

        if ($type === 'other') {
            $contact = trim((string) ($loan['counterpartyContact'] ?? ''));
            return ($contact !== '' && filter_var($contact, FILTER_VALIDATE_EMAIL) !== false) ? $contact : null;
        }

        return null;
    }

    /**
     * For every straight-line OR reducing-balance depreciation asset on a
     * site, compute today's value via the `computeCurrentValue()`
     * dispatcher (widened for #412 Phase 3 Pass 2 — was
     * `computeStraightLineValue()`-only) and persist it to
     * `tblAssets.currentValuePence`/`valuationDate` — a narrow two-column
     * UPDATE, never the full `updateAsset()` field-set (which would
     * misleadingly diff every other column too, and would require a real
     * `$actorUserId` for a system-driven bulk write). An asset the
     * dispatcher can't compute a value for (missing an input — see
     * computeStraightLineValue()/computeReducingBalanceValue()'s own docs
     * for exactly which) is left completely untouched — never zeroed,
     * never guessed, matching both pure helpers' own "return null rather
     * than invent" contract.
     *
     * Deliberately no per-row `self::audit()` call — routine bulk
     * housekeeping, mirroring `purgeExpiredFoundReports()`/
     * `purgeExpiredScanLog()`'s own no-audit convention (points 8/11
     * above); the cron caller's own aggregate `Logger::activity()` call
     * covers the run.
     *
     * #412: also writes today's value into `tblAssetValueHistory` via
     * `recordValueSnapshot()` for every asset whose value WAS computable
     * this call — independent of whether the `tblAssets` UPDATE below
     * actually changed a row (a fully-depreciated asset sitting at
     * salvage writes the SAME value every day; `recordValueSnapshot()`
     * itself is the one that suppresses that churn, on its own
     * value-unchanged rule — see its doc). This method's own return value
     * is unchanged in meaning: it still counts only `tblAssets` rows
     * written, not history rows.
     *
     * @return int Count of assets whose currentValuePence/valuationDate
     *         were actually written this call
     */
    public static function persistCurrentValues(int $siteId): int
    {
        $db = App::db();

        $stmt = $db->prepare(
            'SELECT assetID, purchaseCostPence, usefulLifeMonths, purchaseDate, salvageValuePence, depreciationMethod '
            . "FROM tblAssets WHERE siteID = ? AND isDeleted = 0 AND depreciationMethod IN ('straight-line', 'reducing-balance')"
        );
        if ($stmt === false) {
            error_log('AssetRegister::persistCurrentValues() prepare failed: ' . $db->error);
            return 0;
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        $assets = [];
        while ($row = $result->fetch_assoc()) {
            $assets[] = $row;
        }
        $stmt->close();

        $today = date('Y-m-d');
        $written = 0;

        foreach ($assets as $asset) {
            $value = self::computeCurrentValue($asset, $today);
            if ($value === null) {
                // 🛟 Not computable (missing an input) — never invented,
                // never zeroed. Leave the row exactly as it was.
                continue;
            }

            $assetId = (int) $asset['assetID'];
            // 🕒 Self-assign updatedAt to SUPPRESS its `ON UPDATE
            //    CURRENT_TIMESTAMP` auto-bump — this is a daily system
            //    housekeeping write (valuationDate = today changes every
            //    day), and without this every straight-line asset's
            //    updatedAt would move daily, churning listForSite()'s
            //    `ORDER BY updatedAt DESC` and making the register's
            //    "recently updated" order meaningless. Explicitly setting
            //    the column (even to its own value) suppresses the auto-
            //    update on both MySQL 8 and MariaDB.
            $upd = $db->prepare(
                'UPDATE tblAssets SET currentValuePence = ?, valuationDate = ?, updatedAt = updatedAt '
                . 'WHERE assetID = ? AND siteID = ?'
            );
            if ($upd === false) {
                continue;
            }
            $upd->bind_param('isii', $value, $today, $assetId, $siteId);
            $upd->execute();
            if ($upd->affected_rows > 0) {
                $written++;
            }
            $upd->close();

            // 📈 #412 — record today's snapshot into the value-history
            // table regardless of $upd's own affected_rows (see method
            // doc above); recordValueSnapshot() suppresses its own churn.
            self::recordValueSnapshot(
                $siteId,
                $assetId,
                $today,
                $value,
                (string) $asset['depreciationMethod'],
                'cron',
                null
            );
        }

        return $written;
    }

    /**
     * Write (or in-place update) one day's snapshot into
     * `tblAssetValueHistory` for an asset — the #412 depreciation-trend
     * feed behind item.php's "Value history" panel. WRITE-ON-CHANGE-ONLY
     * for cron-sourced snapshots, to keep the table compact: an asset
     * sitting at a fully-depreciated salvage value (or simply unchanged
     * since yesterday) does NOT get a fresh row every single day — only
     * the day the computed value first differs from the most recently
     * recorded one. A manual entry (`$source !== 'cron'`) always writes,
     * since a manager deliberately recording a valuation is meaningful
     * regardless of whether the number happens to match the last one.
     *
     * Same-day re-runs never duplicate: `uq_astvh_asset_date` (assetID,
     * valueDate) makes the INSERT below an `ON DUPLICATE KEY UPDATE`
     * in-place replace, not a second row.
     *
     * @return bool True on a successful write (or a deliberate no-op
     *         skip because the value hasn't moved), false on a
     *         prepare/execute failure or an invalid $method/$source.
     */
    private static function recordValueSnapshot(
        int $siteId,
        int $assetId,
        string $valueDate,
        int $valuePence,
        string $method,
        string $source = 'cron',
        ?int $recordedById = null
    ): bool {
        // 🛟 Defensive ENUM validation — never write a value the column
        // itself can't hold. Mirrors the "never invent/guess" discipline
        // applied everywhere else in this class, just for shape rather
        // than for the value itself.
        if (in_array($method, ['straight-line', 'reducing-balance', 'manual'], true) === false) {
            return false;
        }
        if (in_array($source, ['cron', 'manual'], true) === false) {
            return false;
        }

        $db = App::db();

        // 🔎 Look up the most recently recorded snapshot for this asset
        // (site-scoped) to decide whether a cron-sourced write would be
        // pure churn — see method doc.
        $lookup = $db->prepare(
            'SELECT valueDate, currentValuePence FROM tblAssetValueHistory '
            . 'WHERE assetID = ? AND siteID = ? ORDER BY valueDate DESC, valueID DESC LIMIT 1'
        );
        if ($lookup === false) {
            error_log('AssetRegister::recordValueSnapshot() lookup prepare failed: ' . $db->error);
            return false;
        }
        $lookup->bind_param('ii', $assetId, $siteId);
        $lookup->execute();
        $latest = $lookup->get_result()->fetch_assoc();
        $lookup->close();

        if ($source === 'cron' && $latest !== null && (int) $latest['currentValuePence'] === $valuePence) {
            // 🛟 Unchanged since the last recorded snapshot — no churn.
            // This is the whole point: a fully-depreciated asset sitting
            // at salvage does not get a new row every day.
            return true;
        }

        $ins = $db->prepare(
            'INSERT INTO tblAssetValueHistory '
            . '(siteID, assetID, valueDate, currentValuePence, method, source, recordedByID) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE currentValuePence = VALUES(currentValuePence), '
            . 'method = VALUES(method), source = VALUES(source), recordedByID = VALUES(recordedByID)'
        );
        if ($ins === false) {
            error_log('AssetRegister::recordValueSnapshot() insert prepare failed: ' . $db->error);
            return false;
        }
        $ins->bind_param('iisissi', $siteId, $assetId, $valueDate, $valuePence, $method, $source, $recordedById);
        $success = $ins->execute();
        if ($success === false) {
            error_log('AssetRegister::recordValueSnapshot() insert execute failed: ' . $ins->error);
        }
        $ins->close();

        return $success;
    }

    /**
     * Reverse of the write side above — an asset's recorded value-history
     * rows in chronological (oldest → newest) order, ready either for a
     * future chart (ASC is the natural order for a trend line) or for a
     * caller to `array_reverse()` for a most-recent-first list (item.php's
     * "Value history" panel does exactly that — see that file). Site-scoped
     * even though the caller (item.php) has already validated the asset
     * belongs to the current site — an IDOR belt-and-braces match for every
     * other per-asset read in this class.
     *
     * @return array<int, array{valueDate:string, currentValuePence:int,
     *         method:string, source:string}>
     */
    public static function valueHistory(int $assetId, int $siteId, int $limit = 60): array
    {
        // 🛟 Clamp into a sane range — never an unbounded/zero/negative
        // LIMIT from a bad caller.
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 365) {
            $limit = 365;
        }

        $db = App::db();

        $stmt = $db->prepare(
            'SELECT valueDate, currentValuePence, method, source FROM tblAssetValueHistory '
            . 'WHERE assetID = ? AND siteID = ? ORDER BY valueDate ASC LIMIT ?'
        );
        if ($stmt === false) {
            error_log('AssetRegister::valueHistory() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('iii', $assetId, $siteId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'valueDate'         => (string) $row['valueDate'],
                'currentValuePence' => (int) $row['currentValuePence'],
                'method'            => (string) $row['method'],
                'source'            => (string) $row['source'],
            ];
        }
        $stmt->close();

        return $rows;
    }

    /* ==========================================================================
     * 📊 Value dashboard (#408, Phase 2 Pass 3) — feed
     * `_apps/assets/value-report.php`. See class header point 12.
     * ======================================================================== */

    /**
     * Register-wide value totals for a site, grouped by category and by
     * status. For each asset, "current value" PREFERS the persisted
     * `currentValuePence` (written by `persistCurrentValues()` above) and
     * falls back to a live `computeCurrentValue()` estimate (#412 — widened
     * from a `computeStraightLineValue()`-only fallback so a
     * reducing-balance asset counts toward the totals too, not just
     * straight-line) only when nothing has been persisted yet — an asset
     * that's simply not computable by EITHER method (missing an input —
     * for reducing-balance that includes a missing/zero salvage value, see
     * `computeReducingBalanceValue()`'s own doc) is counted in
     * `notValuedCount` rather than folded into a total as if it were zero
     * (mirrors both pure helpers' own "never invent" contract).
     * `insuranceGapPence` sums (insured − current) ONLY over assets where
     * BOTH figures are known — an asset with no insured value recorded, or
     * no computable current value, contributes nothing to that figure
     * either way. No currency conversion — see class header point 12's
     * closing note.
     *
     * @return array{
     *   totals: array{assetCount:int, purchaseCostPence:int, currentValuePence:int,
     *     notValuedCount:int, insuredValuePence:int, insuredAssetCount:int,
     *     insuranceGapPence:int, underInsuredCount:int},
     *   byCategory: array<int, array<string, mixed>>,
     *   byStatus: array<int, array<string, mixed>>
     * }
     */
    public static function valueSummaryForSite(int $siteId): array
    {
        $db = App::db();

        $stmt = $db->prepare(
            'SELECT a.assetID, a.status, a.categoryID, c.categoryName, '
            . '       a.purchaseCostPence, a.currentValuePence, a.depreciationMethod, '
            . '       a.usefulLifeMonths, a.salvageValuePence, a.purchaseDate, a.insuredValuePence '
            . 'FROM tblAssets a LEFT JOIN tblAssetCategories c ON c.categoryID = a.categoryID '
            . 'WHERE a.siteID = ? AND a.isDeleted = 0'
        );
        if ($stmt === false) {
            error_log('AssetRegister::valueSummaryForSite() prepare failed: ' . $db->error);
            return ['totals' => [], 'byCategory' => [], 'byStatus' => []];
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();

        $totals = [
            'assetCount' => 0, 'purchaseCostPence' => 0, 'currentValuePence' => 0,
            'notValuedCount' => 0, 'insuredValuePence' => 0, 'insuredAssetCount' => 0,
            'insuranceGapPence' => 0, 'underInsuredCount' => 0,
        ];
        $byCategory = [];
        $byStatus = [];

        while ($row = $result->fetch_assoc()) {
            $totals['assetCount']++;

            $purchase = $row['purchaseCostPence'] !== null ? (int) $row['purchaseCostPence'] : 0;
            $totals['purchaseCostPence'] += $purchase;

            $current = $row['currentValuePence'] !== null
                ? (int) $row['currentValuePence']
                : self::computeCurrentValue($row);

            if ($current === null) {
                $totals['notValuedCount']++;
            } else {
                $totals['currentValuePence'] += $current;
            }

            $insured = $row['insuredValuePence'] !== null ? (int) $row['insuredValuePence'] : null;
            if ($insured !== null) {
                $totals['insuredValuePence'] += $insured;
                $totals['insuredAssetCount']++;
                if ($current !== null) {
                    $totals['insuranceGapPence'] += ($insured - $current);
                    if ($insured < $current) {
                        $totals['underInsuredCount']++;
                    }
                }
            }

            $catKey  = $row['categoryID'] !== null ? (int) $row['categoryID'] : 0;
            $catName = $row['categoryName'] !== null ? (string) $row['categoryName'] : 'Uncategorised';
            if (isset($byCategory[$catKey]) === false) {
                $byCategory[$catKey] = [
                    'categoryID' => $catKey, 'categoryName' => $catName, 'assetCount' => 0,
                    'purchaseCostPence' => 0, 'currentValuePence' => 0, 'insuredValuePence' => 0,
                    'notValuedCount' => 0,
                ];
            }
            $byCategory[$catKey]['assetCount']++;
            $byCategory[$catKey]['purchaseCostPence'] += $purchase;
            if ($current !== null) {
                $byCategory[$catKey]['currentValuePence'] += $current;
            } else {
                $byCategory[$catKey]['notValuedCount']++;
            }
            if ($insured !== null) {
                $byCategory[$catKey]['insuredValuePence'] += $insured;
            }

            $statKey = (string) $row['status'];
            if (isset($byStatus[$statKey]) === false) {
                $byStatus[$statKey] = [
                    'status' => $statKey, 'assetCount' => 0,
                    'purchaseCostPence' => 0, 'currentValuePence' => 0, 'insuredValuePence' => 0,
                    'notValuedCount' => 0,
                ];
            }
            $byStatus[$statKey]['assetCount']++;
            $byStatus[$statKey]['purchaseCostPence'] += $purchase;
            if ($current !== null) {
                $byStatus[$statKey]['currentValuePence'] += $current;
            } else {
                $byStatus[$statKey]['notValuedCount']++;
            }
            if ($insured !== null) {
                $byStatus[$statKey]['insuredValuePence'] += $insured;
            }
        }
        $stmt->close();

        $byCategoryList = array_values($byCategory);
        usort($byCategoryList, static fn (array $x, array $y): int => strcmp((string) $x['categoryName'], (string) $y['categoryName']));
        $byStatusList = array_values($byStatus);
        usort($byStatusList, static fn (array $x, array $y): int => strcmp((string) $x['status'], (string) $y['status']));

        return ['totals' => $totals, 'byCategory' => $byCategoryList, 'byStatus' => $byStatusList];
    }

    /**
     * Per-asset depreciation report rows — name, category, purchase cost,
     * method, current value (persisted-preferred, live-estimate fallback,
     * same rule as valueSummaryForSite() above), % depreciated, and
     * insured value. `isEstimate` distinguishes a persisted valuation from
     * a live-computed one so the UI can label it accordingly (never
     * presented as more authoritative than it is).
     *
     * @return array<int, array<string, mixed>> Each row: assetID, name,
     *         assetTagCode, categoryName, purchaseCostPence,
     *         depreciationMethod, currentValuePence (nullable),
     *         isEstimate (bool), valuationDate (nullable),
     *         pctDepreciated (nullable float), insuredValuePence (nullable)
     */
    public static function depreciationReportRows(int $siteId): array
    {
        $db = App::db();

        $stmt = $db->prepare(
            'SELECT a.assetID, a.name, a.assetTagCode, c.categoryName, a.purchaseCostPence, '
            . '       a.depreciationMethod, a.usefulLifeMonths, a.salvageValuePence, a.purchaseDate, '
            . '       a.currentValuePence, a.valuationDate, a.insuredValuePence '
            . 'FROM tblAssets a LEFT JOIN tblAssetCategories c ON c.categoryID = a.categoryID '
            . 'WHERE a.siteID = ? AND a.isDeleted = 0 '
            . 'ORDER BY a.name ASC'
        );
        if ($stmt === false) {
            error_log('AssetRegister::depreciationReportRows() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $persisted = $row['currentValuePence'] !== null ? (int) $row['currentValuePence'] : null;
            // #412: computeCurrentValue() dispatcher — was
            // computeStraightLineValue()-only; widened so a
            // reducing-balance asset with no persisted value yet still
            // gets a live estimate row here, matching
            // valueSummaryForSite()'s own fallback above.
            $current   = $persisted ?? self::computeCurrentValue($row);
            $purchase  = $row['purchaseCostPence'] !== null ? (int) $row['purchaseCostPence'] : null;

            $pctDepreciated = null;
            if ($purchase !== null && $purchase > 0 && $current !== null) {
                $pctDepreciated = round((($purchase - $current) / $purchase) * 100, 1);
            }

            $rows[] = [
                'assetID'            => (int) $row['assetID'],
                'name'               => (string) $row['name'],
                'assetTagCode'       => $row['assetTagCode'],
                'categoryName'       => $row['categoryName'],
                'purchaseCostPence'  => $purchase,
                'depreciationMethod' => (string) $row['depreciationMethod'],
                'currentValuePence'  => $current,
                'isEstimate'         => $persisted === null && $current !== null,
                'valuationDate'      => $persisted !== null ? $row['valuationDate'] : null,
                'pctDepreciated'     => $pctDepreciated,
                'insuredValuePence'  => $row['insuredValuePence'] !== null ? (int) $row['insuredValuePence'] : null,
            ];
        }
        $stmt->close();

        return $rows;
    }

    /* ==========================================================================
     * 📋 Stocktake / scan-to-verify (#411)
     * ==========================================================================
     * A stocktake "run" (`tblAssetStocktakes`) is opened against the whole
     * site register or a location/category-scoped subset of it, pre-
     * populating one `tblAssetStocktakeItems` row per expected asset
     * (verifyStatus='pending'). A manager then scans assets — each scan
     * either confirms an expected item ('present'/'moved') or records one
     * that wasn't expected ('unexpected'); closing the run sweeps every
     * still-'pending' row to 'missing'. See findByScanCode() for how a raw
     * scanned string resolves to an asset, and recordStocktakeScan() for
     * the present/moved/unexpected decision itself.
     * ======================================================================== */

    /**
     * Open a new stocktake run for the current site, optionally scoped to
     * one location and/or one category (either/both NULL = the whole
     * site's register). Pre-populates `tblAssetStocktakeItems` with one
     * 'pending' row per matching, non-deleted asset in a single
     * INSERT…SELECT — see the file-header note above for the run's overall
     * lifecycle.
     *
     * @return array{ok: bool, msg: string, stocktakeId: int}
     */
    public static function startStocktake(string $label, ?int $locationId, ?int $categoryId, int $actorUserId): array
    {
        $db     = App::db();
        $siteId = Site::id();

        // 📋 Label — required, ≤150 chars (matches the VARCHAR(150) column).
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 150) {
            return ['ok' => false, 'msg' => 'A stocktake label is required (max 150 characters).', 'stocktakeId' => 0];
        }

        // 🔗 Optional scope FKs — must exist on THIS site, exactly like
        // save.php's own "never trust a bare posted int" FK checks. Unlike
        // save.php's silent-fallback-to-NULL convention, an invalid scope
        // id here is a hard failure — a stocktake silently opening against
        // the WRONG (or no) scope would be a much worse surprise than a
        // stale category id on an asset edit form.
        if ($locationId !== null && $locationId > 0) {
            $chk = $db->prepare('SELECT 1 FROM tblAssetLocations WHERE locationID = ? AND siteID = ? LIMIT 1');
            if ($chk === false) {
                return ['ok' => false, 'msg' => 'Could not start the stocktake — please try again.', 'stocktakeId' => 0];
            }
            $chk->bind_param('ii', $locationId, $siteId);
            $chk->execute();
            $found = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($found === null) {
                return ['ok' => false, 'msg' => 'The selected location was not found on this site.', 'stocktakeId' => 0];
            }
        } else {
            $locationId = null;
        }

        if ($categoryId !== null && $categoryId > 0) {
            $chk = $db->prepare('SELECT 1 FROM tblAssetCategories WHERE categoryID = ? AND siteID = ? LIMIT 1');
            if ($chk === false) {
                return ['ok' => false, 'msg' => 'Could not start the stocktake — please try again.', 'stocktakeId' => 0];
            }
            $chk->bind_param('ii', $categoryId, $siteId);
            $chk->execute();
            $found = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($found === null) {
                return ['ok' => false, 'msg' => 'The selected category was not found on this site.', 'stocktakeId' => 0];
            }
        } else {
            $categoryId = null;
        }

        // 💾 Transaction — "create the run" + "pre-populate its expected
        // items" are one atomic unit: a run with zero expected items
        // because the pre-populate step failed half-way would be a
        // confusing, silently-wrong stocktake, not a loud failure.
        App::beginTransaction();
        $stocktakeId   = 0;
        $expectedCount = 0;
        try {
            $ins = $db->prepare(
                "INSERT INTO tblAssetStocktakes (siteID, label, status, locationID, categoryID, startedByID) "
                . "VALUES (?, ?, 'open', ?, ?, ?)"
            );
            if ($ins === false) {
                throw new \RuntimeException('Failed to prepare stocktake insert: ' . $db->error);
            }
            $ins->bind_param('isiii', $siteId, $label, $locationId, $categoryId, $actorUserId);
            $ins->execute();
            $stocktakeId = (int) $ins->insert_id;
            $ins->close();

            if ($stocktakeId <= 0) {
                throw new \RuntimeException('Stocktake insert did not return an id.');
            }

            // 📦 Pre-populate expected items — dynamic WHERE built the same
            // way listForSite() builds its own (siteID + isDeleted always,
            // locationID/categoryID only when scoped).
            $where  = ['siteID = ?', 'isDeleted = 0'];
            $types  = 'i';
            $params = [$siteId];
            if ($locationId !== null) {
                $where[]  = 'locationID = ?';
                $types   .= 'i';
                $params[] = $locationId;
            }
            if ($categoryId !== null) {
                $where[]  = 'categoryID = ?';
                $types   .= 'i';
                $params[] = $categoryId;
            }

            $popSql = "INSERT INTO tblAssetStocktakeItems (stocktakeID, siteID, assetID, verifyStatus) "
                    . "SELECT ?, siteID, assetID, 'pending' FROM tblAssets WHERE " . implode(' AND ', $where);
            $popStmt = $db->prepare($popSql);
            if ($popStmt === false) {
                throw new \RuntimeException('Failed to prepare item pre-populate: ' . $db->error);
            }
            $popTypes  = 'i' . $types;
            $popParams = array_merge([$stocktakeId], $params);
            $popStmt->bind_param($popTypes, ...$popParams);
            $popStmt->execute();
            $expectedCount = $popStmt->affected_rows;
            $popStmt->close();

            App::commit();
        } catch (\Throwable $e) {
            App::rollback();
            error_log('AssetRegister::startStocktake() failed: ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'Could not start the stocktake — please try again.', 'stocktakeId' => 0];
        }

        // 📜 Audit — action 'create' so this ALSO mirrors to the platform
        // trail (see TABLE_FOR_ENTITY's doc comment) — opening a run is a
        // low-volume, noteworthy event, unlike the individual scans below.
        self::audit('stocktake', $stocktakeId, 0, 'create', null, [
            'label'         => $label,
            'locationID'    => $locationId,
            'categoryID'    => $categoryId,
            'expectedCount' => $expectedCount,
        ]);

        return ['ok' => true, 'msg' => 'Stocktake started — ' . $expectedCount . ' asset(s) expected.', 'stocktakeId' => $stocktakeId];
    }

    /**
     * Resolve a raw scanned string to a site-scoped, non-deleted asset.
     * Tries, in order, the FIRST match wins:
     *   (a) exact `assetTagCode`
     *   (b) exact `serialNumber`
     *   (c) `publicToken` — a bare 32-char lowercase-hex token, OR a full
     *       `/a/{token}` label URL (see labelPublicUrl()) from which the
     *       token is extracted defensively (never interpolated — always a
     *       bound parameter once found)
     *   (d) an exact `tblAssetIdentifiers.value` match (GS1/RFID/barcode
     *       identifiers — #393/#415), joined back to its (site-scoped,
     *       non-deleted) asset
     *
     * Each probe is site-scoped and its own prepared statement, mirroring
     * findByTagOrSerial()'s own "tag first, cheapest lookup first" shape.
     *
     * @return array<string, mixed>|null The full asset row (via self::get()
     *         — so the caller always gets every column, not just the id
     *         column each probe selected), or null when nothing matches.
     */
    public static function findByScanCode(int $siteId, string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $db = App::db();

        // (a) assetTagCode — exact match.
        $stmt = $db->prepare('SELECT assetID FROM tblAssets WHERE siteID = ? AND isDeleted = 0 AND assetTagCode = ? LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('is', $siteId, $code);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row !== null) {
                return self::get((int) $row['assetID']);
            }
        }

        // (b) serialNumber — exact match.
        $stmt = $db->prepare('SELECT assetID FROM tblAssets WHERE siteID = ? AND isDeleted = 0 AND serialNumber = ? LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('is', $siteId, $code);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row !== null) {
                return self::get((int) $row['assetID']);
            }
        }

        // (c) publicToken — bare token or a full /a/{token} URL. The regex
        // only ever EXTRACTS a candidate substring for a bound parameter
        // below — the raw $code is never itself interpolated into SQL.
        $token = null;
        if (preg_match('/[a-f0-9]{32}/', strtolower($code), $m) === 1) {
            $token = $m[0];
        }
        if ($token !== null) {
            $stmt = $db->prepare('SELECT assetID FROM tblAssets WHERE siteID = ? AND isDeleted = 0 AND publicToken = ? LIMIT 1');
            if ($stmt !== false) {
                $stmt->bind_param('is', $siteId, $token);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row !== null) {
                    return self::get((int) $row['assetID']);
                }
            }
        }

        // (d) tblAssetIdentifiers.value — exact match, site-scoped via a
        // join back to its (non-deleted) asset.
        $stmt = $db->prepare(
            'SELECT i.assetID FROM tblAssetIdentifiers i '
            . 'JOIN tblAssets a ON a.assetID = i.assetID AND a.isDeleted = 0 '
            . 'WHERE i.siteID = ? AND a.siteID = ? AND i.value = ? LIMIT 1'
        );
        if ($stmt !== false) {
            $stmt->bind_param('iis', $siteId, $siteId, $code);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row !== null) {
                return self::get((int) $row['assetID']);
            }
        }

        return null;
    }

    /**
     * Record one scan-to-verify result against an OPEN stocktake run.
     * Resolves $code via findByScanCode(), then decides the outcome:
     *   - Asset WAS expected (a `tblAssetStocktakeItems` row already
     *     exists for this stocktake+asset): 'present' — or 'moved' when a
     *     $foundLocationId is given AND differs from the asset's own
     *     recorded `locationID`.
     *   - Asset was NOT expected: 'unexpected' (foundLocationID recorded
     *     verbatim, may be null).
     * `uq_aststi_stocktake_asset` makes the UPSERT below an in-place
     * update on a re-scan of the same asset within the same run, never a
     * duplicate row.
     *
     * @return array{ok: bool, msg: string, verifyStatus?: string,
     *         assetID?: int, assetName?: string, isConfidential?: bool}
     */
    public static function recordStocktakeScan(int $stocktakeId, string $code, ?int $foundLocationId, int $actorUserId): array
    {
        $db     = App::db();
        $siteId = Site::id();

        // 🔒 Must be an OPEN run on THIS site.
        $stmt = $db->prepare("SELECT stocktakeID FROM tblAssetStocktakes WHERE stocktakeID = ? AND siteID = ? AND status = 'open' LIMIT 1");
        if ($stmt === false) {
            return ['ok' => false, 'msg' => 'Stocktake not found or already closed.'];
        }
        $stmt->bind_param('ii', $stocktakeId, $siteId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($found === null) {
            return ['ok' => false, 'msg' => 'Stocktake not found or already closed.'];
        }

        $asset = self::findByScanCode($siteId, $code);
        if ($asset === null) {
            return ['ok' => false, 'msg' => 'Code not recognised on this site.'];
        }
        $assetId = (int) $asset['assetID'];

        // 🔗 Optional found-location — validated same as startStocktake()'s
        // scope FKs, but here an invalid id is a soft "treat as unset"
        // rather than a hard failure (this is a fast-moving scan flow —
        // failing the whole scan over a stale dropdown value would be a
        // much worse UX than just not recording the found-location).
        if ($foundLocationId !== null && $foundLocationId > 0) {
            $chk = $db->prepare('SELECT 1 FROM tblAssetLocations WHERE locationID = ? AND siteID = ? LIMIT 1');
            if ($chk !== false) {
                $chk->bind_param('ii', $foundLocationId, $siteId);
                $chk->execute();
                $chkFound = $chk->get_result()->fetch_assoc();
                $chk->close();
                if ($chkFound === null) {
                    $foundLocationId = null;
                }
            } else {
                $foundLocationId = null;
            }
        } else {
            $foundLocationId = null;
        }

        // 🔎 Was this asset already expected in this run?
        $existsStmt = $db->prepare('SELECT itemID FROM tblAssetStocktakeItems WHERE stocktakeID = ? AND assetID = ? LIMIT 1');
        if ($existsStmt === false) {
            return ['ok' => false, 'msg' => 'Could not record the scan — please try again.'];
        }
        $existsStmt->bind_param('ii', $stocktakeId, $assetId);
        $existsStmt->execute();
        $existingItem = $existsStmt->get_result()->fetch_assoc();
        $existsStmt->close();

        $recordedLocationId = $asset['locationID'] !== null ? (int) $asset['locationID'] : null;

        // -------------------------------------------------------------------
        // 🧮 verifyStatus decision.
        // -------------------------------------------------------------------
        if ($existingItem !== null) {
            if ($foundLocationId !== null && $foundLocationId !== $recordedLocationId) {
                $verifyStatus          = 'moved';
                $storedFoundLocationId = $foundLocationId;
            } else {
                $verifyStatus          = 'present';
                $storedFoundLocationId = null;
            }
        } else {
            $verifyStatus          = 'unexpected';
            $storedFoundLocationId = $foundLocationId;
        }

        // 💾 UPSERT — uq_aststi_stocktake_asset makes a re-scan an in-place
        // update rather than a second row.
        $upsert = $db->prepare(
            'INSERT INTO tblAssetStocktakeItems (stocktakeID, siteID, assetID, verifyStatus, scannedByID, scannedAt, foundLocationID) '
            . 'VALUES (?, ?, ?, ?, ?, NOW(), ?) '
            . 'ON DUPLICATE KEY UPDATE verifyStatus = VALUES(verifyStatus), scannedByID = VALUES(scannedByID), '
            . 'scannedAt = VALUES(scannedAt), foundLocationID = VALUES(foundLocationID)'
        );
        if ($upsert === false) {
            error_log('AssetRegister::recordStocktakeScan() prepare failed: ' . $db->error);
            return ['ok' => false, 'msg' => 'Could not record the scan — please try again.'];
        }
        $upsert->bind_param('iiisii', $stocktakeId, $siteId, $assetId, $verifyStatus, $actorUserId, $storedFoundLocationId);
        $ok = $upsert->execute();
        $upsert->close();
        if ($ok === false) {
            return ['ok' => false, 'msg' => 'Could not record the scan — please try again.'];
        }

        // 📜 Audit — action 'scan' is NOT in ['create','update','delete'],
        // so this writes ONLY tblAssetAudit, never the platform trail
        // (deliberate — scans are high-volume, see TABLE_FOR_ENTITY's doc
        // comment).
        self::audit('stocktake', $stocktakeId, $assetId, 'scan', null, ['verifyStatus' => $verifyStatus]);

        return [
            'ok'             => true,
            'msg'            => 'Scan recorded.',
            'verifyStatus'   => $verifyStatus,
            'assetID'        => $assetId,
            'assetName'      => (string) $asset['name'],
            'isConfidential' => (int) ($asset['isConfidential'] ?? 0) === 1,
        ];
    }

    /**
     * Close an open stocktake run: race-safe status flip (only succeeds if
     * it was still 'open'), then sweep every still-'pending' item to
     * 'missing' — anything never scanned this run is, by definition,
     * missing.
     *
     * @return array{ok: bool, msg: string}
     */
    public static function closeStocktake(int $stocktakeId, int $actorUserId): array
    {
        $db     = App::db();
        $siteId = Site::id();

        App::beginTransaction();
        $missingCount = 0;
        try {
            // 🏁 Race-safe — the `AND status = 'open'` guard means two
            // concurrent "close" clicks can't both succeed (the second
            // affects zero rows).
            $upd = $db->prepare(
                "UPDATE tblAssetStocktakes SET status = 'closed', closedByID = ?, closedAt = NOW() "
                . " WHERE stocktakeID = ? AND siteID = ? AND status = 'open'"
            );
            if ($upd === false) {
                throw new \RuntimeException('Failed to prepare stocktake close: ' . $db->error);
            }
            $upd->bind_param('iii', $actorUserId, $stocktakeId, $siteId);
            $upd->execute();
            $affected = $upd->affected_rows;
            $upd->close();

            if ($affected <= 0) {
                App::rollback();
                return ['ok' => false, 'msg' => 'Stocktake was already closed, or could not be found.'];
            }

            $missStmt = $db->prepare(
                "UPDATE tblAssetStocktakeItems SET verifyStatus = 'missing' "
                . " WHERE stocktakeID = ? AND siteID = ? AND verifyStatus = 'pending'"
            );
            if ($missStmt === false) {
                throw new \RuntimeException('Failed to prepare missing-sweep: ' . $db->error);
            }
            $missStmt->bind_param('ii', $stocktakeId, $siteId);
            $missStmt->execute();
            $missingCount = $missStmt->affected_rows;
            $missStmt->close();

            App::commit();
        } catch (\Throwable $e) {
            App::rollback();
            error_log('AssetRegister::closeStocktake() failed: ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'Could not close the stocktake — please try again.'];
        }

        // 📜 Audit — action 'update' so this ALSO mirrors to the platform
        // trail (closing a run is low-volume, unlike the scans that fed
        // into it).
        self::audit(
            'stocktake',
            $stocktakeId,
            0,
            'update',
            ['status' => 'open'],
            ['status' => 'closed', 'missingCount' => $missingCount]
        );

        return ['ok' => true, 'msg' => 'Stocktake closed — ' . $missingCount . ' asset(s) marked missing.'];
    }

    /**
     * List a site's stocktake runs (open + closed), newest-started first,
     * each carrying its starter's/closer's full name, scope location/
     * category names, and a total expected-item count.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listStocktakes(int $siteId, ?string $status = null): array
    {
        $db = App::db();

        $where  = ['st.siteID = ?'];
        $types  = 'i';
        $params = [$siteId];
        if ($status !== null && in_array($status, self::STOCKTAKE_STATUSES, true) === true) {
            $where[]  = 'st.status = ?';
            $types   .= 's';
            $params[] = $status;
        }

        $sql = 'SELECT st.*, '
             . '       su.fullName AS startedByName, cu.fullName AS closedByName, '
             . '       l.locationName, c.categoryName, '
             . '       (SELECT COUNT(*) FROM tblAssetStocktakeItems i WHERE i.stocktakeID = st.stocktakeID) AS itemCount '
             . 'FROM tblAssetStocktakes st '
             . 'LEFT JOIN tblUsers su ON su.userID = st.startedByID '
             . 'LEFT JOIN tblUsers cu ON cu.userID = st.closedByID '
             . 'LEFT JOIN tblAssetLocations l ON l.locationID = st.locationID '
             . 'LEFT JOIN tblAssetCategories c ON c.categoryID = st.categoryID '
             . 'WHERE ' . implode(' AND ', $where) . ' '
             . 'ORDER BY st.startedAt DESC';

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            error_log('AssetRegister::listStocktakes() prepare failed: ' . $db->error);
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
     * Fetch a single stocktake run, site-scoped, with the same starter/
     * closer/scope names listStocktakes() carries. Null if missing or
     * belonging to another site.
     *
     * @return array<string, mixed>|null
     */
    public static function getStocktake(int $stocktakeId, int $siteId): ?array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT st.*, '
            . '       su.fullName AS startedByName, cu.fullName AS closedByName, '
            . '       l.locationName, c.categoryName '
            . 'FROM tblAssetStocktakes st '
            . 'LEFT JOIN tblUsers su ON su.userID = st.startedByID '
            . 'LEFT JOIN tblUsers cu ON cu.userID = st.closedByID '
            . 'LEFT JOIN tblAssetLocations l ON l.locationID = st.locationID '
            . 'LEFT JOIN tblAssetCategories c ON c.categoryID = st.categoryID '
            . 'WHERE st.stocktakeID = ? AND st.siteID = ? LIMIT 1'
        );
        if ($stmt === false) {
            error_log('AssetRegister::getStocktake() prepare failed: ' . $db->error);
            return null;
        }
        $stmt->bind_param('ii', $stocktakeId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== false && $row !== null ? $row : null;
    }

    /**
     * List one stocktake run's item rows — asset name/tag, recorded +
     * found location names, and who/when scanned — optionally filtered to
     * one verifyStatus. Ordered by verifyStatus then asset name, which
     * naturally groups the list by outcome for the scan screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function stocktakeItems(int $stocktakeId, int $siteId, ?string $verifyStatus = null): array
    {
        $db = App::db();

        $where  = ['i.stocktakeID = ?', 'i.siteID = ?'];
        $types  = 'ii';
        $params = [$stocktakeId, $siteId];
        if ($verifyStatus !== null && in_array($verifyStatus, self::STOCKTAKE_VERIFY_STATUSES, true) === true) {
            $where[]  = 'i.verifyStatus = ?';
            $types   .= 's';
            $params[] = $verifyStatus;
        }

        $sql = 'SELECT i.*, a.name AS assetName, a.assetTagCode, a.isConfidential, '
             . '       rl.locationName AS recordedLocationName, fl.locationName AS foundLocationName, '
             . '       su.fullName AS scannedByName '
             . 'FROM tblAssetStocktakeItems i '
             . 'JOIN tblAssets a ON a.assetID = i.assetID '
             . 'LEFT JOIN tblAssetLocations rl ON rl.locationID = a.locationID '
             . 'LEFT JOIN tblAssetLocations fl ON fl.locationID = i.foundLocationID '
             . 'LEFT JOIN tblUsers su ON su.userID = i.scannedByID '
             . 'WHERE ' . implode(' AND ', $where) . ' '
             . 'ORDER BY i.verifyStatus ASC, a.name ASC';

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            error_log('AssetRegister::stocktakeItems() prepare failed: ' . $db->error);
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
     * Variance summary for one stocktake run — a count per verifyStatus
     * (every status always present, 0 when there are no rows in it) plus
     * a 'total' across all of them, via a single GROUP BY query.
     *
     * @return array{pending: int, present: int, missing: int, moved: int,
     *         unexpected: int, total: int}
     */
    public static function stocktakeVariance(int $stocktakeId, int $siteId): array
    {
        $counts = array_fill_keys(self::STOCKTAKE_VERIFY_STATUSES, 0);

        $db = App::db();
        $stmt = $db->prepare(
            'SELECT verifyStatus, COUNT(*) AS cnt FROM tblAssetStocktakeItems '
            . 'WHERE stocktakeID = ? AND siteID = ? GROUP BY verifyStatus'
        );
        if ($stmt === false) {
            error_log('AssetRegister::stocktakeVariance() prepare failed: ' . $db->error);
            $counts['total'] = 0;
            return $counts;
        }
        $stmt->bind_param('ii', $stocktakeId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        $total = 0;
        while ($row = $result->fetch_assoc()) {
            $status = (string) $row['verifyStatus'];
            $cnt    = (int) $row['cnt'];
            if (array_key_exists($status, $counts) === true) {
                $counts[$status] = $cnt;
            }
            $total += $cnt;
        }
        $stmt->close();

        $counts['total'] = $total;
        return $counts;
    }

    /* ==========================================================================
     * 🖥️ Kiosk self check-in/out (#414, Phase 3 Pass 5) — see class header
     * point 14 for the full section overview. EVERY method below may be
     * called from a PUBLIC, unauthenticated request (`_apps/assets/
     * kiosk.php`/`kiosk-action.php`) — none of them may EVER trust a
     * caller-supplied userID/siteID the way createAsset()/updateAsset() do;
     * every one re-derives or re-validates its own scope from a real,
     * already-authenticated (by device token or PIN) source.
     * ======================================================================== */

    /**
     * A small denylist of PIN values that are trivially guessable —
     * checked by {@see isWeakKioskPin()} ALONGSIDE the programmatic
     * all-same-digit / sequential-run checks in that method, so this list
     * only needs to cover values neither of those two patterns already
     * catches (e.g. '0123' is a plain ascending run and never reaches
     * this array at all).
     *
     * @var string[]
     */
    private const KIOSK_PIN_WEAK_LIST = [
        '0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999',
        '1234', '4321', '1212', '2121', '000000', '123456', '654321',
    ];

    /**
     * True when $pin (already shape-validated by the caller —
     * {@see setKioskPin()}) is trivially guessable: on the small explicit
     * {@see KIOSK_PIN_WEAK_LIST}, every digit identical (e.g. '55555'), or
     * a straight ascending/descending run (e.g. '2345', '9876'). NON-
     * exhaustive by design (a determined attacker still has to brute-force
     * a 4-6 digit space, which is what {@see RateLimiter} in `kiosk-
     * action.php` is actually for) — this is a cheap first line of defence
     * against the handful of PINs a person is most likely to pick by habit.
     */
    private static function isWeakKioskPin(string $pin): bool
    {
        if (in_array($pin, self::KIOSK_PIN_WEAK_LIST, true) === true) {
            return true;
        }
        // 🔁 Every digit identical, any length in range.
        if (preg_match('/^(\d)\1+$/', $pin) === 1) {
            return true;
        }
        // 🔢 A contiguous slice of a straight ascending/descending run.
        $ascending  = '0123456789';
        $descending = '9876543210';
        if (str_contains($ascending, $pin) === true || str_contains($descending, $pin) === true) {
            return true;
        }
        return false;
    }

    /**
     * Register a new kiosk TERMINAL — mints a 32-hex device token
     * (`generatePublicToken()`, same shape as an asset's own public token),
     * retrying on the astronomically unlikely `uq_astkt_token` collision
     * (mirrors `addIdentifier()`'s own duplicate-catch shape, class header
     * point 4). ADMIN-only caller (`_apps/assets/kiosk-save.php`) — audited
     * as a normal session-admin action, NOT the `actorUserIdOverride` path
     * (the acting admin registered this terminal; no kiosk user is
     * involved yet). The plaintext token is returned ONCE — see
     * `listKioskTokens()`'s own doc for why it is never selectable again.
     *
     * @return array{ok: bool, msg: string, token?: string, tokenId?: int}
     */
    public static function mintKioskToken(string $label, int $actorUserId): array
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 150) {
            return ['ok' => false, 'msg' => 'Label is required (max 150 characters).'];
        }

        $db     = App::db();
        $siteId = Site::id();

        $token   = null;
        $tokenId = 0;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = self::generatePublicToken();
            $stmt = $db->prepare(
                'INSERT INTO tblAssetKioskTokens (siteID, label, token, isActive, createdByID) '
                . 'VALUES (?, ?, ?, 1, ?)'
            );
            if ($stmt === false) {
                error_log('AssetRegister::mintKioskToken() prepare failed: ' . $db->error);
                return ['ok' => false, 'msg' => 'Could not register the terminal — please try again.'];
            }
            try {
                $stmt->bind_param('issi', $siteId, $label, $candidate, $actorUserId);
                $stmt->execute();
                $tokenId = (int) $stmt->insert_id;
                $stmt->close();
                $token = $candidate;
                break;
            } catch (\mysqli_sql_exception $e) {
                // 🎲 uq_astkt_token collision — vanishingly unlikely for a
                // 32-hex value, but retry with a fresh candidate rather
                // than fail outright (same shape as addIdentifier()'s own
                // duplicate-catch).
                $stmt->close();
                continue;
            }
        }

        if ($token === null || $tokenId <= 0) {
            error_log('AssetRegister::mintKioskToken() could not generate a unique token after 5 attempts');
            return ['ok' => false, 'msg' => 'Could not register the terminal — please try again.'];
        }

        // 📜 Normal session-admin actor — the token value itself is NEVER
        // passed into audit() (see REDACTED_FIELDS' own rationale — a
        // kiosk terminal token gates the same class of action a publicToken
        // gates, so it stays out of every log row just as thoroughly, even
        // though 'token' isn't itself in REDACTED_FIELDS — simplest to
        // just never hand it to audit() at all).
        self::audit('kiosk', $tokenId, 0, 'create', null, ['label' => $label]);

        return ['ok' => true, 'msg' => 'Terminal registered.', 'token' => $token, 'tokenId' => $tokenId];
    }

    /**
     * Activate/revoke an existing terminal, site-scoped + IDOR-guarded
     * (read-then-mutate, mirrors `loanAction()`'s own pattern). Revoking
     * takes effect immediately — `resolveKioskTerminal()` re-checks
     * `isActive` on EVERY request, so a revoked terminal stops working on
     * its very next request, mid check-in/out session or not.
     *
     * @return array{ok: bool, msg: string}
     */
    public static function setKioskTokenActive(int $tokenId, bool $active, int $actorUserId): array
    {
        $db     = App::db();
        $siteId = Site::id();

        $stmt = $db->prepare('SELECT isActive FROM tblAssetKioskTokens WHERE tokenID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            error_log('AssetRegister::setKioskTokenActive() prepare failed: ' . $db->error);
            return ['ok' => false, 'msg' => 'Could not update the terminal — please try again.'];
        }
        $stmt->bind_param('ii', $tokenId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return ['ok' => false, 'msg' => 'Terminal not found.'];
        }

        $oldVal = (int) $row['isActive'];
        $newVal = $active === true ? 1 : 0;

        $updStmt = $db->prepare('UPDATE tblAssetKioskTokens SET isActive = ? WHERE tokenID = ? AND siteID = ?');
        if ($updStmt === false) {
            error_log('AssetRegister::setKioskTokenActive() update prepare failed: ' . $db->error);
            return ['ok' => false, 'msg' => 'Could not update the terminal — please try again.'];
        }
        $updStmt->bind_param('iii', $newVal, $tokenId, $siteId);
        $updStmt->execute();
        $updStmt->close();

        self::audit('kiosk', $tokenId, 0, 'update', ['isActive' => $oldVal], ['isActive' => $newVal]);

        return ['ok' => true, 'msg' => $active === true ? 'Terminal reactivated.' : 'Terminal revoked.'];
    }

    /**
     * Permanently remove a terminal registration, site-scoped + IDOR-
     * guarded (read-then-delete, mirrors `removeOwner()`'s own pattern).
     * Unlike most register/history tables in this class, a decommissioned
     * kiosk device is genuinely gone — there is no ongoing history value
     * in keeping a dead terminal row around the way there is for a loan or
     * a maintenance entry (`setKioskTokenActive()` above is the "keep the
     * row, just stop trusting it" option for a terminal being temporarily
     * taken out of service).
     *
     * @return array{ok: bool, msg: string}
     */
    public static function deleteKioskToken(int $tokenId, int $actorUserId): array
    {
        $db     = App::db();
        $siteId = Site::id();

        $stmt = $db->prepare('SELECT label FROM tblAssetKioskTokens WHERE tokenID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            error_log('AssetRegister::deleteKioskToken() prepare failed: ' . $db->error);
            return ['ok' => false, 'msg' => 'Could not delete the terminal — please try again.'];
        }
        $stmt->bind_param('ii', $tokenId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return ['ok' => false, 'msg' => 'Terminal not found.'];
        }

        $delStmt = $db->prepare('DELETE FROM tblAssetKioskTokens WHERE tokenID = ? AND siteID = ?');
        if ($delStmt === false) {
            error_log('AssetRegister::deleteKioskToken() delete prepare failed: ' . $db->error);
            return ['ok' => false, 'msg' => 'Could not delete the terminal — please try again.'];
        }
        $delStmt->bind_param('ii', $tokenId, $siteId);
        $delStmt->execute();
        $affected = $delStmt->affected_rows;
        $delStmt->close();

        if ($affected <= 0) {
            return ['ok' => false, 'msg' => 'Terminal not found.'];
        }

        self::audit('kiosk', $tokenId, 0, 'delete', ['label' => (string) $row['label']], null);

        return ['ok' => true, 'msg' => 'Terminal deleted.'];
    }

    /**
     * List every registered terminal for a site, newest-registered first.
     * NEVER selects the `token` column itself — see class header point 14
     * for why a terminal's credential is a show-once value.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listKioskTokens(int $siteId): array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT kt.tokenID, kt.label, kt.isActive, kt.createdAt, kt.lastSeenAt, '
            . '       u.fullName AS createdByName '
            . 'FROM tblAssetKioskTokens kt '
            . 'LEFT JOIN tblUsers u ON u.userID = kt.createdByID '
            . 'WHERE kt.siteID = ? '
            . 'ORDER BY kt.createdAt DESC'
        );
        if ($stmt === false) {
            error_log('AssetRegister::listKioskTokens() prepare failed: ' . $db->error);
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
     * PUBLIC-safe token→terminal resolve — the credential gate every
     * kiosk request re-checks (class header point 14). Re-validates the
     * `^[a-f0-9]{32}$` shape itself (never trusts an upstream guarantee —
     * same defensive convention as `tag.php`/`found-save.php`) before ever
     * touching the database, then requires `isActive = 1`: an unknown
     * token and a revoked one are 100% indistinguishable from this
     * method's return value alone (both null) — no oracle. On a match,
     * bumps `lastSeenAt` via a separate, cheap UPDATE (best-effort — never
     * allowed to turn a successful resolve into a failure).
     *
     * @return array<string, mixed>|null tokenID/siteID/label, or null
     */
    public static function resolveKioskTerminal(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }

        $db = App::db();
        $stmt = $db->prepare(
            'SELECT tokenID, siteID, label FROM tblAssetKioskTokens WHERE token = ? AND isActive = 1 LIMIT 1'
        );
        if ($stmt === false) {
            error_log('AssetRegister::resolveKioskTerminal() prepare failed: ' . $db->error);
            return null;
        }
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return null;
        }

        $tokenId = (int) $row['tokenID'];
        $bumpStmt = $db->prepare('UPDATE tblAssetKioskTokens SET lastSeenAt = NOW() WHERE tokenID = ?');
        if ($bumpStmt !== false) {
            $bumpStmt->bind_param('i', $tokenId);
            $bumpStmt->execute();
            $bumpStmt->close();
        }

        return $row;
    }

    /**
     * Set (or change) a member's OWN kiosk PIN — validates shape
     * (`^\d{4,6}$`) and rejects a weak/guessable value ({@see
     * isWeakKioskPin()}) before `password_hash()`-ing it and UPSERT-ing on
     * `uq_astkp_site_user` (re-setting a previously-cleared PIN
     * re-activates the row in the same statement). Logged via a plain
     * `Logger::activity()` call rather than `self::audit()` — a PIN isn't
     * asset-scoped, so it doesn't fit this class's asset-choke-point audit
     * trail (class header point 14).
     *
     * @return array{ok: bool, msg: string}
     */
    public static function setKioskPin(int $userId, int $siteId, string $pin): array
    {
        $pin = trim($pin);
        if (preg_match('/^\d{4,6}$/', $pin) !== 1) {
            return ['ok' => false, 'msg' => 'PIN must be 4-6 digits.'];
        }
        if (self::isWeakKioskPin($pin) === true) {
            return ['ok' => false, 'msg' => 'That PIN is too easy to guess — please choose a less predictable one.'];
        }

        $hash = password_hash($pin, PASSWORD_DEFAULT);

        $db = App::db();
        $stmt = $db->prepare(
            'INSERT INTO tblAssetKioskPins (siteID, userID, pinHash, isActive) VALUES (?, ?, ?, 1) '
            . 'ON DUPLICATE KEY UPDATE pinHash = VALUES(pinHash), isActive = 1'
        );
        if ($stmt === false) {
            error_log('AssetRegister::setKioskPin() prepare failed: ' . $db->error);
            return ['ok' => false, 'msg' => 'Could not save your PIN — please try again.'];
        }
        $stmt->bind_param('iis', $siteId, $userId, $hash);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok === false) {
            return ['ok' => false, 'msg' => 'Could not save your PIN — please try again.'];
        }

        Logger::activity('AssetKioskPinSet', 'Kiosk PIN set/changed', $userId);

        return ['ok' => true, 'msg' => 'Your kiosk PIN has been saved.'];
    }

    /**
     * Clear a member's OWN kiosk PIN — flips `isActive = 0` rather than
     * deleting the row (see `setKioskPin()`'s own UPSERT, which
     * re-activates it if the member sets a new PIN later).
     *
     * @return array{ok: bool, msg: string}
     */
    public static function clearKioskPin(int $userId, int $siteId): array
    {
        $db = App::db();
        $stmt = $db->prepare('UPDATE tblAssetKioskPins SET isActive = 0 WHERE userID = ? AND siteID = ?');
        if ($stmt === false) {
            error_log('AssetRegister::clearKioskPin() prepare failed: ' . $db->error);
            return ['ok' => false, 'msg' => 'Could not clear your PIN — please try again.'];
        }
        $stmt->bind_param('ii', $userId, $siteId);
        $stmt->execute();
        $stmt->close();

        Logger::activity('AssetKioskPinCleared', 'Kiosk PIN cleared', $userId);

        return ['ok' => true, 'msg' => 'Your kiosk PIN has been cleared.'];
    }

    /**
     * Whether a member currently has an active kiosk PIN set — NEVER
     * reveals the PIN itself, only its presence, for `kiosk-pin.php`'s own
     * "a PIN is currently set" readout.
     */
    public static function hasKioskPin(int $userId, int $siteId): bool
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT 1 FROM tblAssetKioskPins WHERE userID = ? AND siteID = ? AND isActive = 1 LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $userId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null;
    }

    /**
     * Resolve a kiosk identify-form IDENTIFIER (username or email — the
     * SAME either-or `Auth::loginLocal()` accepts, mirrored exactly
     * including the lower-cased/trimmed comparison value) to an ACTIVE
     * user who belongs to `$siteId` via an active `tblUserSites` row
     * (`partyExistsOnSite('user', …)`'s own join shape, class header point
     * 5) — never a bare userID from the request. Returns userID/fullName
     * only; NEVER a password/PIN hash or any other credential material.
     *
     * @return array{userID: int, fullName: string}|null
     */
    public static function resolveKioskUser(int $siteId, string $identifier): ?array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return null;
        }

        $db = App::db();
        $stmt = $db->prepare(
            'SELECT DISTINCT u.userID, u.fullName '
            . 'FROM tblUsers u '
            . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
            . 'LEFT JOIN tblLocalAccounts la ON la.userID = u.userID '
            . 'WHERE u.isActive = 1 AND (la.username = ? OR u.emailAddress = ?) '
            . 'LIMIT 1'
        );
        if ($stmt === false) {
            error_log('AssetRegister::resolveKioskUser() prepare failed: ' . $db->error);
            return null;
        }
        $stmt->bind_param('iss', $siteId, $identifier, $identifier);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row !== null ? $row : null;
    }

    /**
     * `password_verify()` a kiosk PIN against `$userId`'s active
     * `tblAssetKioskPins` row, site-scoped. A missing row simply verifies
     * false — the SAME outcome as a wrong PIN against a real row, so this
     * method's return value alone never reveals whether the user has a PIN
     * set at all (the caller — `kiosk-action.php`'s `identify` action —
     * shows the identical "incorrect details" message either way). Bumps
     * `lastUsedAt` on a successful verify.
     */
    public static function verifyKioskPin(int $siteId, int $userId, string $pin): bool
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT pinID, pinHash FROM tblAssetKioskPins WHERE siteID = ? AND userID = ? AND isActive = 1 LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $siteId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return false;
        }

        if (password_verify($pin, (string) $row['pinHash']) === false) {
            return false;
        }

        $pinId = (int) $row['pinID'];
        $bumpStmt = $db->prepare('UPDATE tblAssetKioskPins SET lastUsedAt = NOW() WHERE pinID = ?');
        if ($bumpStmt !== false) {
            $bumpStmt->bind_param('i', $pinId);
            $bumpStmt->execute();
            $bumpStmt->close();
        }

        return true;
    }

    /**
     * Kiosk self-checkout — a single asset, hand-over TO the PIN-resolved
     * member. Guards: the asset exists on the (forced-context) site, is
     * NOT confidential, and is in a loanable status (`in-service` or
     * `in-storage`) — a confidential asset and a wrong-status asset return
     * the EXACT SAME rejection message (no oracle distinguishing the two;
     * see class header point 14), checked BEFORE the separate "already has
     * an open loan" check (which only a real, already-public, non-
     * confidential asset can ever reach). TRANSACTIONAL, same shape as
     * `loanCheckout()` (class header point 5): the new loan-row INSERT and
     * the `tblAssets.status` UPDATE are one atomic unit. Deliberately does
     * NOT cascade a kit (#413) — a kiosk hand-over is always exactly one
     * asset. Audits entityType `'loan'`, action `'checkout'`, `actorType:
     * 'kiosk'`, attributed to `$kioskUserId` via the `audit()`
     * `actorUserIdOverride` (point 1).
     *
     * @return array{ok: bool, msg: string}
     */
    public static function kioskCheckout(int $assetId, int $kioskUserId, int $kioskTokenId): array
    {
        $asset = self::get($assetId);
        if ($asset === null) {
            return ['ok' => false, 'msg' => 'Item not found.'];
        }

        // 🙈 Uniform rejection — a confidential asset and one that simply
        // isn't in a loanable status right now are indistinguishable from
        // this response alone (see method doc).
        $loanableStatus = in_array((string) $asset['status'], ['in-service', 'in-storage'], true);
        if ((int) $asset['isConfidential'] === 1 || $loanableStatus === false) {
            return ['ok' => false, 'msg' => "That item isn't available to check out here."];
        }

        $db     = App::db();
        $siteId = Site::id();

        // 🚦 No open loan already in flight — mirrors createLoanRequest()'s
        // own "one unresolved loan at a time per asset" guard (class
        // header point 5). Only reached by a real, non-confidential,
        // loanable-status asset, so revealing "already checked out" here
        // is not itself an oracle for confidentiality.
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
                return ['ok' => false, 'msg' => 'That item is already checked out.'];
            }
        }

        $conditionOut = (string) $asset['conditionState'];
        if (in_array($conditionOut, self::CONDITION_STATES, true) === false) {
            $conditionOut = 'good';
        }

        $newLoanId  = 0;
        $newStatus  = 'on-loan';
        App::beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO tblAssetLoans '
                . '(siteID, assetID, direction, counterpartyType, counterpartyUserID, status, '
                . 'conditionOut, dateOut, requestedByID, approvedByID, approvedAt) '
                . "VALUES (?, ?, 'out', 'user', ?, 'active', ?, NOW(), ?, ?, NOW())"
            );
            if ($stmt === false) {
                throw new \RuntimeException('Failed to prepare kiosk checkout: ' . $db->error);
            }
            $stmt->bind_param('iiisii', $siteId, $assetId, $kioskUserId, $conditionOut, $kioskUserId, $kioskUserId);
            $stmt->execute();
            $newLoanId = (int) $stmt->insert_id;
            $stmt->close();
            if ($newLoanId <= 0) {
                throw new \RuntimeException('Kiosk checkout insert did not return an id');
            }

            $assetStmt = $db->prepare('UPDATE tblAssets SET status = ? WHERE assetID = ? AND siteID = ?');
            if ($assetStmt === false) {
                throw new \RuntimeException('Failed to prepare asset status update: ' . $db->error);
            }
            $assetStmt->bind_param('sii', $newStatus, $assetId, $siteId);
            $assetStmt->execute();
            $assetStmt->close();

            App::commit();
        } catch (\Throwable $e) {
            App::rollback();
            error_log('AssetRegister::kioskCheckout() failed: ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'Could not check out this item — please try again or ask a manager for help.'];
        }

        self::audit(
            'loan',
            $newLoanId,
            $assetId,
            'checkout',
            null,
            ['status' => 'active', 'conditionOut' => $conditionOut, 'assetStatus' => $newStatus],
            ['kioskTokenID' => $kioskTokenId],
            'kiosk',
            $kioskUserId
        );

        return ['ok' => true, 'msg' => 'Checked out: ' . (string) $asset['name']];
    }

    /**
     * Kiosk self-check-in — the PIN-resolved member returns an item they
     * currently have out. IDOR guard: finds the ONE active `direction =
     * 'out'` loan on this asset where `counterpartyUserID = $kioskUserId`
     * — a kiosk user can only ever check in a loan THEY are the
     * counterparty of, on THIS site — PLUS a defensive `isConfidential =
     * 0` join (belt-and-braces: `kioskCheckout()` already prevents a
     * confidential asset from ever acquiring such a loan via the kiosk
     * path in the first place, but this closes the same gate against a
     * hand-crafted POST targeting a confidential asset a non-kiosk
     * workflow separately loaned to this user — see class header point
     * 14). No match → the SAME friendly failure regardless of WHY (asset
     * doesn't exist / belongs to someone else / isn't out / is
     * confidential) — this single query has no separate existence branch
     * to leak through. `conditionIn` is validated against
     * `CONDITION_STATES`, defaulting to `'good'` when missing/invalid
     * (unlike `loanCheckin()`, a kiosk self-service flow doesn't hard-
     * require the value — a member skipping the condition select
     * shouldn't block their own check-in). TRANSACTIONAL, same shape as
     * `loanCheckin()` (class header point 5). Audits entityType `'loan'`,
     * action `'checkin'`, `actorType: 'kiosk'`, attributed to
     * `$kioskUserId` via the `audit()` `actorUserIdOverride` (point 1).
     *
     * @return array{ok: bool, msg: string}
     */
    public static function kioskCheckin(int $assetId, int $kioskUserId, int $kioskTokenId, ?string $conditionIn): array
    {
        $db     = App::db();
        $siteId = Site::id();

        $stmt = $db->prepare(
            'SELECT l.loanID FROM tblAssetLoans l '
            . 'JOIN tblAssets a ON a.assetID = l.assetID '
            . "WHERE l.assetID = ? AND l.siteID = ? AND l.counterpartyUserID = ? "
            . "AND l.direction = 'out' AND l.status = 'active' AND a.isConfidential = 0 "
            . 'LIMIT 1'
        );
        if ($stmt === false) {
            error_log('AssetRegister::kioskCheckin() prepare failed: ' . $db->error);
            return ['ok' => false, 'msg' => "You don't have that item checked out."];
        }
        $stmt->bind_param('iii', $assetId, $siteId, $kioskUserId);
        $stmt->execute();
        $loan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($loan === null) {
            return ['ok' => false, 'msg' => "You don't have that item checked out."];
        }
        $loanId = (int) $loan['loanID'];

        $condition = $conditionIn !== null ? trim($conditionIn) : '';
        if (in_array($condition, self::CONDITION_STATES, true) === false) {
            $condition = 'good';
        }

        App::beginTransaction();
        try {
            $updStmt = $db->prepare(
                "UPDATE tblAssetLoans SET status = 'returned', dateIn = NOW(), conditionIn = ? "
                . " WHERE loanID = ? AND assetID = ? AND siteID = ? AND status = 'active'"
            );
            if ($updStmt === false) {
                throw new \RuntimeException('Failed to prepare kiosk checkin: ' . $db->error);
            }
            $updStmt->bind_param('siii', $condition, $loanId, $assetId, $siteId);
            $updStmt->execute();
            $affected = $updStmt->affected_rows;
            $updStmt->close();
            if ($affected <= 0) {
                throw new \RuntimeException('Loan row did not update — status already changed');
            }

            $inService = 'in-service';
            $assetStmt = $db->prepare('UPDATE tblAssets SET status = ?, conditionState = ? WHERE assetID = ? AND siteID = ?');
            if ($assetStmt === false) {
                throw new \RuntimeException('Failed to prepare asset status update: ' . $db->error);
            }
            $assetStmt->bind_param('ssii', $inService, $condition, $assetId, $siteId);
            $assetStmt->execute();
            $assetStmt->close();

            App::commit();
        } catch (\Throwable $e) {
            App::rollback();
            error_log('AssetRegister::kioskCheckin() failed: ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'Could not check in this item — it may have already changed state.'];
        }

        self::audit(
            'loan',
            $loanId,
            $assetId,
            'checkin',
            ['status' => 'active'],
            ['status' => 'returned', 'conditionIn' => $condition, 'assetStatus' => 'in-service'],
            ['kioskTokenID' => $kioskTokenId],
            'kiosk',
            $kioskUserId
        );

        return ['ok' => true, 'msg' => 'Checked in — thank you!'];
    }

    /**
     * The kiosk user's OWN currently-out assets — feeds `kiosk.php`'s
     * check-in list. STRICTLY `counterpartyUserID = $kioskUserId` AND
     * `direction = 'out'` AND `status = 'active'`, additionally excluding
     * confidential assets defensively (same belt-and-braces rationale as
     * `kioskCheckin()`'s own join — none should ever exist here, but the
     * filter costs nothing and closes the gate regardless).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function kioskUserActiveLoans(int $kioskUserId, int $siteId): array
    {
        if ($kioskUserId <= 0) {
            return [];
        }

        $db = App::db();
        $stmt = $db->prepare(
            'SELECT l.loanID, a.assetID, a.name, a.assetTagCode '
            . 'FROM tblAssetLoans l JOIN tblAssets a ON a.assetID = l.assetID '
            . "WHERE l.counterpartyUserID = ? AND l.siteID = ? AND l.direction = 'out' AND l.status = 'active' "
            . 'AND a.siteID = ? AND a.isDeleted = 0 AND a.isConfidential = 0 '
            . 'ORDER BY a.name ASC'
        );
        if ($stmt === false) {
            error_log('AssetRegister::kioskUserActiveLoans() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('iii', $kioskUserId, $siteId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}
