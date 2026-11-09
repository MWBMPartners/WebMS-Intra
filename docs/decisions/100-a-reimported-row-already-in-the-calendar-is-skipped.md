# D-100 · A calendar-import row already in the calendar is skipped unless the manager ticks to import it

**Date:** 2026-11-09  
**Status:** in force

**Question:** fixing #877 lets a calendar spreadsheet hold the same event name on many dates, but
removes an accidental safety net: re-uploading a file already imported (for example after fixing
one bad row, as D-099 suggests) quietly creates a second copy of every event. What should the
import do with a row matching an event already in the calendar — the same name and the same start
date and time?

**Answer:** **skip it, with a tick to import anyway.** The preview marks such rows "already in the
calendar" and leaves them out unless the manager ticks "import these anyway"; the results list
says which were skipped as already present. Re-uploading a corrected file adds only what is
missing. The owner, 9 November 2026, choosing the recommended option.

**Rules out:** always refusing such rows; importing them regardless.

**Where:** `web/_apps/calendar/manage/import.php`; #877. Related: D-099.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
