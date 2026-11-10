# D-103 · The organisation-wide Approver role sees only its own departments' expense claims

**Date:** 2026-11-10  
**Status:** in force

**Question:** an expense claim belongs to one department, and each department names who approves
its claims. Separately, an organisation can give someone the organisation-wide role "Approver".
The approvals list shows a role-holder only the claims of departments they approve for, and they
can decide only those; but the claim page let them open any claim in the organisation in full
(claimant, line items, suppliers, receipts), and the spreadsheet download gave them every claim in
the organisation (#648). What should the role let someone see?

**Answer:** **their own departments only.** A role-holder sees and downloads only the claims of
departments they approve for, exactly as the approvals list and the decision page already work.
Administrators and the treasurer keep seeing everything, as now. The owner, 10 November 2026,
choosing the recommended option.

**Rules out:** the role seeing (or downloading) every claim in the organisation while deciding
only its own departments.

**Where:** #648 (#541 item 3) — `web/_apps/expenses/view/index.php`,
`web/_apps/expenses/api/export.php`, `Portal\Core\ExpenseRules`, `Departments::approverDepts()`.
Related: D-040 (who may export at all), #681.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
