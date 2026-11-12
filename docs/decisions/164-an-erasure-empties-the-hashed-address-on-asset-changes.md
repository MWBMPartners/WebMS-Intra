# D-164 · An erasure empties the hashed network address on asset-change records the person made

**Date:** 2026-11-12  
**Status:** in force  

**Question:** Asset-change records keep a hashed copy of the network address of the person who made the change; the general change history's is already emptied by an erasure. Empty it on asset-change records too?

**Answer:** Yes: an erasure empties the hashed network address on asset-change records the erased person made; who changed what, and when, is kept.

**Rules out:** Keeping the hashed address after an erasure.

**Where:** #938 — `Portal\Core\GdprEraser`, the personal-data catalogue. Related: D-031, D-116.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
