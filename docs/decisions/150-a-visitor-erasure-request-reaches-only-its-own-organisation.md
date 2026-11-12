# D-150 · A visitor erasure request reaches only the organisation whose administrator filed it

**Date:** 2026-11-12  
**Status:** in force

**Question:** one installation can host several organisations, possibly run by different people. Should a visitor
address request filed by one organisation's administrator also clear that address from other organisations' event
suggestions?

**Answer:** no. It reaches only the organisation whose administrator filed it and checked who was asking. Another
organisation files its own request if the visitor asks it.

**Rules out:** one request clearing the address across every organisation on the installation.

**Where:** #964 — `Portal\Core\GdprEraser::executeVisitorAddress()`. Related: D-140.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
