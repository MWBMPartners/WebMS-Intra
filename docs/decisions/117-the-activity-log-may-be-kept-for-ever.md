# D-117 · The activity log may be kept for ever, by choice

**Date:** 2026-11-10  
**Status:** in force

**Question:** the activity log (`tblActivityLogs`: who did what, when) is deleted by the nightly clear-out after
`audit.retentionDays` (365 by default, at most 3650). A stored 0 has always fallen back to a year, so there is no
way to keep it for ever. Should there be?

**Answer:** Yes. The owner, 10 November 2026: *"Allow activity log to also be configurable to never delete
records"* — said straight after choosing to keep the change history for ever (D-116). "Never delete" is a
choice, stored as 0, and follows the portal's rule for limits (#668, D-013): 0 is the LARGEST value, so an
organisation may choose "never" only where the installation allows never, and an installation with a number of
days caps every organisation at that number.

**Rules out:** keeping 0 as "fall back to a year" for this setting; letting an organisation choose "never"
when its installation has set a number of days; a "never" that is silently clamped to the 3650-day hard maximum;
and extending the choice to `tblErrors`, which the owner did not name.

**Where:** `_apps/cron/_retention-sweep.php` (`log_retention_days()`, `log_sweep()`),
`_apps/admin/maintenance/retention.php`, `tools/retention-caps-selftest.php`; the member-facing wording about the
activity log after #703. Issue #932.

**Supersedes:** nothing in the register. It reverses the long-standing CODE rule that a stored 0 means a year
(pinned in `tools/retention-caps-selftest.php`), which was never an owner's decision.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
