# D-149 · A visitor is not sent a confirmation e-mail after their details are erased

**Date:** 2026-11-12  
**Status:** in force

**Question:** after a visitor's event-suggestion details are erased on request (D-140), should the portal e-mail the
visitor to confirm?

**Answer:** no. The administrator has already been in touch to check it is them; D-134 allows the address to be
used only for the approval e-mail; and sending one would put the address back into the record of sent mail just as
it is removed.

**Rules out:** an automatic confirmation e-mail to the visitor.

**Where:** #964 — `Portal\Core\GdprEraser::executeVisitorAddress()`. Related: D-134, D-140.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
