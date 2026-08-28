# Stage 01 review — orchestrator resolutions (feed into Stage 02)

I reviewed `01-data-model.md`. It is accepted as the data-model baseline. Below are my resolutions to its §13 open questions. Stage 02 MUST follow these.

## The one substantive CHANGE to the plan
**Q3 — DROP the `tblPayment` 'venue-hire' bridge and its guarded ALTER (do NOT ship them).**
- Rationale: `tblPayment` + `Payments::markPaymentSucceeded()` are the **inbound** card-checkout rail (donations/pledges — money IN via Stripe/PayPal). Paying a landlord for hire is **money OUT**; the confirmed scope is "invoicing & payment **tracking**", i.e. recording what the church paid, which the standalone payable ledger (`tblVenueInvoicePayments`, modelled on `tblExpenseClaimPayments`) already does.
- Therefore: **remove** `tblVenueInvoicePayments.portalPaymentID` (and its FK), do **not** add `'venue-hire'` to `tblPayment.purpose`, do **not** extend `Payments::PURPOSES`, do **not** add a `markPaymentSucceeded()` branch.
- **Result: migration 170 has ZERO guarded ALTERs — it is all `CREATE TABLE IF NOT EXISTS` (idempotent by construction).** This is simpler and removes the only risky DDL. `tblVenueInvoicePayments` keeps its own method/date/reference/recordedByID columns as a pure outgoing ledger.
- (If a church ever needs to COLLECT money toward hire, that's a separate future issue — add the rail then.)

## Accept the plan's recommended defaults for the rest
- **Q1 landlord CRUD** — reuse `tblAssetOrgs` for storage; Stage 02 verifies `AssetRegister::saveOrg()`'s contract/permission gate before calling it, AND ships a venues-side landlord picker/create so the flow works with the Assets app disabled (fallback: prepared statements against `tblAssetOrgs`).
- **Q2 GDPR — MANDATORY (do not skip):** add every new `tblVenue*` table carrying personal data (`createdByID`/`updatedByID`/`recordedByID`/`uploadedByID`, `caretakerName`/`caretakerPhone`) to `GdprEraser::catalogue()`, and decide org-contact handling. This is the #372 silent-skip-erasure lesson — Stage 03 verification must assert it.
- **Q4 cross-midnight** — unsupported v1 (`endTime > startTime` PHP-enforced). Accept.
- **Q5 generator duplicate policy** — skip any date already holding a non-deleted booking for (venue, date, same roomID-or-NULL) and report the skips. Accept.
- **Q6 event↔venue** — v1: a `venues.calendar_default_venue` setting selects which venue an event's "is it booked?" check runs against (great for the common single-venue church); file a follow-up issue for `tblEvents.venueID` (per-event venue) as the richer future path.
- **Q7 cross-tenant invariants** — PHP-enforced; EVERY venues save handler validates `status.siteID == booking.siteID` and `usageType.venueID == booking.venueID`. Into the Stage 03 verification checklist as a hard gate.
- **Q8 status seeding** — `Venues::seedStatuses($siteId)` idempotent, called on first `/venues` access per site; ALSO an admin "restore default statuses" action for the delete-all-then-recover case.
- **Q9 XLSX parsing** — native `ZipArchive` + `SimpleXML`; Stage 03 verification MUST smoke-test `ZipArchive` availability; the CSV path is the advertised guaranteed fallback in the wizard UI.
- **Q10 VAT/deposits/part-refunds** — out of v1 (deposits can be a ledger row if needed). Accept.
- **Q11 reminder recipients** — a `venues.reminder_roles` setting (role-list targeting, milestones-style). Accept.
- **Q12 room-level coverage** — note in the `Venues::classifyEventCoverage()` header that rule 2 must tighten to room-aware if events later gain room placement. Accept.
- **Q13 one-booking-many-events/day** — recommend one booking with notes describing the day + `eventID` = the main service; document in the leader help page. Accept.

## Reaffirm for Stage 02/03
- Migrations reserved **170–173**; 170 = whole venues schema foundation (now zero guarded ALTERs), folded at end of full_schema after the Asset Tracker block; routes seeded WITH their handler files present (or stub) to satisfy `check_route_targets.py`; per-site vocab seeded PHP-side, never in the migration.
- Timezone rule is non-negotiable: booking rows are wall-clock venue-local (`DATE`+`TIME`); conversion happens ONLY when comparing to `tblEvents` UTC (§8.2/§8.4).
- Audit via `Venues::audit()` → `Logger::audit()` → `tblAuditTrail`; one summary row per bulk op.
- All framework traps apply: AppRegistry entry + seeded `venues.enabled`; ApiRouter convention path + `api.venues.*.enabled` flags; `ApiResponse::success()`; no `<table>` (portal-data-list); CSRF on every POST; site-scope every query; `declare(strict_types=1)`; full IF notation; prepared statements only.
