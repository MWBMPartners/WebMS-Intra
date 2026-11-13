# D-182 · How long a remembered map answer is kept is a setting, capped by the installation

**Date:** 2026-11-13  
**Status:** in force

**Question:** when someone types an address, the portal asks Google or OpenStreetMap where it is and remembers the
answer (`tblGeocodeCache`) so it does not ask again. Since #1037 a remembered answer holds only a one-way fingerprint
of the address, the map position and which service answered — no address text — but someone who already knows an
address and can read the database could tell it was looked up. How long should a remembered answer be kept?

**Answer:** the owner chose "a setting, capped per site": each organisation chooses how long its remembered answers
are kept, and the installation sets a maximum it cannot go over (the limit rule of #668 and D-013: the installation's
value caps the organisation's, through `Portal\Core\SettingLimit`). The nightly clear-out job deletes an answer not
used within the period in force.

**Rules out:** keeping remembered answers for ever; a fixed period in the code that nobody can change; an
organisation keeping them longer than the installation allows.

**Where:** #1037 — `web/_core/Geocoder.php`, `web/_apps/cron/_retention-sweep.php`, the retention settings page.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
