# D-097 · Each organisation's public door starts switched off

**Date:** 2026-11-09  
**Status:** in force

**Question:** the "public door" is the set of addresses an organisation's own website reads its
public events and posters from — a feed, a pasted-in script, or a framed page (#493 P4). Only
items somebody ticked ever appear (D-057). When the portal is upgraded, should each
organisation's door start switched off until somebody turns it on?

**Answer:** **yes — off until turned on.** Nothing answers until a global administrator, or
somebody given "Set up the public website", presses "Turn on the public door" on Admin → Public
website. Turning it off, or replacing its address, stops every pasted-in copy at once. The owner,
9 November 2026, choosing the recommended option.

**Rules out:** every organisation's door answering from the upgrade.

**Where:** #493 P4a, `Portal\Core\PublicDoor` and `tblPublicDoorNames` (an organisation with no
row has no door). Related: D-057, D-085.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
