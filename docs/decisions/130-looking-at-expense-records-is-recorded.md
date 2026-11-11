# D-130 · Looking at expense records is written to the activity log

**Date:** 2026-11-11  
**Status:** in force

**Question:** with #867, an expense claim's receipts can be opened by its claimant, the approvers for that
department, treasurers and administrators. The activity log records changes (a claim submitted, approved, paid)
but nothing in Expenses records anybody just looking. Should looking at expense records be recorded, so an
administrator can later see who opened which claim or receipt?

**Answer:** record all expense viewing: opening a claim's page, downloading the expenses spreadsheet, and opening a
receipt are each written to the activity log, with who looked and which claim (or which export).

**Rules out:** recording only receipts; recording nothing but changes, for Expenses. It does not extend the rule to
other apps — those still record changes only unless decided separately.

**Where:** #867 (receipts) and the Expenses pages — `web/_apps/expenses/`. The records are ordinary activity-log
entries, so they are kept for the activity log's period (D-117 to D-119) and name their person in `userID` (#626).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
