# D-094 · The installation can forbid every organisation's public pages being framed

**Date:** 2026-11-09  
**Status:** in force

**Question:** D-082 lets each organisation list the outside websites allowed to show its
public pages in a frame. Should whoever runs the whole installation (for example a hosting
company) be able to forbid framing for every organisation?

**Answer:** **yes — one installation-wide yes/no switch, on (allowed) by default.** When it
is off, every organisation's list is ignored and an organisation cannot save a non-empty
one (it is told why); emptying a list is always allowed. The stricter answer wins, as in
D-081, D-086 and D-087. The owner, 9 November 2026, choosing the recommended option.

**Rules out:** each organisation deciding framing with no installation-wide limit.

**Where:** #493 package P3, `Portal\Core\PublicWebsiteSetup`; the header itself is sent by
the public door (P4). Related: D-013, D-081, D-082, D-086, D-087.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
