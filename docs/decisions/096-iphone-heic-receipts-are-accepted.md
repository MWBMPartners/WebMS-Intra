# D-096 · iPhone photos (HEIC/HEIF) are accepted as expense receipts

**Date:** 2026-11-09  
**Status:** in force

**Question:** iPhones save photos as HEIC files by default (HEIF is the same family), and the
portal refused them as expense-claim receipts, so an iPhone user's receipt was rejected. #649
asked whether to accept them. The portal never displays a receipt or puts one into a PDF; it
only stores the file outside the public web folder, under a name the portal makes up.

**Answer:** **accept them.** The owner, 9 November 2026, choosing the recommended option.

**Rules out:** refusing HEIC/HEIF receipts.

**Where:** `ExpenseRules::RECEIPT_TYPES`; #649. When receipts can be opened (#867), an approver
may need a viewer that reads HEIC.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
