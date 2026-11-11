# D-120 · A small group's meeting-place map is treated as the host's home

**Date:** 2026-11-11  
**Status:** in force, in part superseded  
**Superseded in part by:** D-124 (the host is the named member, not whoever entered the place)

**Question:** a small group's page can show an OpenStreetMap map of where the group meets, and small groups often
meet in a member's home. Showing the map sends that address, and the viewer's internet address, to an outside
service. Should it be treated as the host's personal location (gated like a member's own home map, D-072) or as
the organisation's published information?

**Answer:** as the host's home. The map is shown only where whoever entered the meeting place has agreed to it
being shown, on the same terms as a member's own home map (D-072, and the strictest-wins rule of D-058/D-073). A
plain written address may still be shown without the outside map.

**Rules out:** an ungated map of a meeting place; treating it as published information with only a warning.

**Where:** #809 — `web/_apps/small-groups/group.php` and `Portal\Core\OutsideServices`. Related: D-058, D-072, D-073.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
