# D-099 · A calendar import saves every row it can, and lists the ones it could not

**Date:** 2026-11-09  
**Status:** in force

**Question:** managers can import a calendar from a spreadsheet file (CSV), one event per row. If
the database refuses one row — for example because it repeats a web address another event already
uses — what should the import do with the other rows?

**Answer:** **save the rest.** Every other row is saved, and the results list names each row that
failed and why; the manager fixes that row and imports just it again. The owner, 9 November 2026,
choosing the recommended option (it matches the page's "import valid rows" button and its
row-by-row results list).

**Rules out:** an all-or-nothing import, where one bad row stops the whole file being saved.

**Where:** `web/_apps/calendar/manage/import.php`; #790. Related: #877 (two rows with the same
event name produce the same web address).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
