# D-116 · The change history is kept for ever

**Date:** 2026-11-10  
**Status:** in force

**Question:** the portal keeps a change history (`tblAuditTrail`, `tblAssetAudit`): a copy of what a record held
each time it is created, changed or deleted. Nothing ever deletes these copies, so after an account is erased
its number stays in the copies of other records (an expense claim, a loan, a document) indefinitely. The
separate activity log is already deleted after a set period (`audit.retentionDays`, 365 by default). Should old
change-history entries be deleted after a set time too?

**Answer:** No. Keep the change history for ever, as now. What members read must say so plainly (#703 round 5
wording: "nothing clears it").

**Rules out:** deleting change-history rows on the activity log's period; a separate retention setting for the
change history; and any member-facing sentence implying the change history is cleared or anonymous.

**Where:** no code change. The wording is in GdprEraser's `tblUsers` reason, the personal-data catalogue,
`web/_apps/privacy/policy.php` and `docs/gdpr-erasure-policy.md` (#703). Issue #931.

**Supersedes:** nothing. Related, and still in force: the decision that rows ABOUT an erased account are anonymised (#707).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
