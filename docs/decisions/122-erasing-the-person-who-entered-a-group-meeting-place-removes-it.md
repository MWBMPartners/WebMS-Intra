# D-122 · Erasing the person who entered a group's meeting place removes the address

**Date:** 2026-11-11  
**Status:** in force, in part superseded  
**Superseded in part by:** D-124 (the host is the named member, not whoever entered the place)

**Question:** a small group's meeting place is often a member's home, and since #809 the portal records who typed
it in (treated as that person's home under D-120). If that person is erased, what happens to the group's meeting
address?

**Answer:** the meeting address is emptied from the group, together with the record of who entered it. The group
itself stays; a leader can type a new meeting place.

**Rules out:** keeping the address on the group after the person who entered it has been erased; emptying only the
link to them.

**Where:** #809 — `GdprEraser` and `web/_core/personal-data-catalogue.php` (the `tblSmallGroups` entry). Related:
D-120, D-072.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
