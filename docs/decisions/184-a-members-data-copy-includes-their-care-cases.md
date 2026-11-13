# D-184 · A member's data copy includes their own pastoral care cases, without workers' names or visit notes

**Date:** 2026-11-13  
**Status:** in force

**Question:** when a member downloads a copy of the data the portal holds about them, which parts of a pastoral care
case about them should it include? A case holds the organisation, their name as typed, a category, a summary a worker
wrote about their circumstances (which may mention family by name), the status, opened and closed dates, and which
worker opened it; each case has visits with a date, a kind, the worker's notes, the visiting worker's name and a
follow-up date.

**Answer:** the owner chose "Include summary": the case's organisation, the member's name as typed, the category, the
summary, the status and the opened and closed dates; and for each visit only its date and kind.

**Rules out:** handing over who opened a case (as D-154 leaves out who recorded a DBS check), the case's own number,
the visiting worker's name, and the workers' visit notes (which can name other people); leaving the cases out of the
copy while My data counts them.

**Where:** #1015 — `web/_apps/auth/account/data-export.php`, `GdprEraser`'s inventory. Related: D-016, D-154.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
