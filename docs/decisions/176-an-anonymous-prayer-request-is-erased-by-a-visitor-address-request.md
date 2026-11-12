# D-176 · An anonymous prayer request is erased by the same visitor-address request as an event suggestion, and its network address is kept until then

**Date:** 2026-11-12  
**Status:** in force

**Question:** A visitor without an account can send an anonymous prayer request, which stores their name, e-mail address and network address, and no erasure request can reach them. Use D-140's visitor-address request for these too? Keep the network address?

**Answer:** Same as D-140 (and D-148 to D-150): an administrator files an erasure request by the visitor's e-mail address, after checking off the portal who is asking, and it also reaches anonymous prayer requests at that organisation. The network address is kept as now, and that request empties it.

**Rules out:** Leaving anonymous prayer requests unreachable; stopping storing the network address.

**Where:** #1009 — `Portal\Core\GdprEraser::executeVisitorAddress()`, `web/_apps/prayer-requests/anonymous-save.php`. Related: D-140, D-148, D-149, D-150, #988.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
