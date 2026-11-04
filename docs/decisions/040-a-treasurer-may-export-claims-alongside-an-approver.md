# D-040 · A treasurer may export claims alongside an approver

**Date:** 2026-11-01  
**Status:** in force

**Question:** who may export the claims spreadsheet?

**Answer:** add treasurer alongside approver in
`ExpenseRules::mayExportClaims()`.

**Reason:** a treasurer already sees the treasury list and makes the payments,
so refusing them the spreadsheet of what to pay is the odd case.

**Rules out:** widening it to department approvers scoped to their own
departments — that stays #681.

**Where:** #541 item 2; `Portal\Core\ExpenseRules::mayExportClaims()`.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
