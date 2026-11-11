# D-132 · A look at expense records that showed no claim is not recorded

**Date:** 2026-11-11  
**Status:** in force  
**Supersedes in part:** D-130

**Question:** D-130 records every look at expense records. As built in #867, a look that turned up NO claims — a
spreadsheet download for a department with no claims, an API page past the end, an empty list or preview — writes
nothing. D-130 names "downloading the expenses spreadsheet" without that exception. Should a look that showed no
claims still be recorded?

**Answer:** no. Nothing was seen, so nothing is recorded. All six kinds of look (the approvals list, the treasury
list, a person's own list, the report preview, the API list and the spreadsheet) follow the same rule, and the
in-app help says so.

**Rules out:** recording an empty download or list with a count of 0.

**Where:** #867 — the Expenses pages, `api/expenses/list` and the report builder's preview. D-130 otherwise stands.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
