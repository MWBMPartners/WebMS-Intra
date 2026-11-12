# D-171 · Rota swap requests have configurable limits, per duty and per day, set at installation and organisation level

**Date:** 2026-11-12  
**Status:** in force

**Question:** A member can ask others to swap a duty, and an open request is e-mailed to every member with the switch on (D-141). Nothing limits how many requests a member can send. What limit should there be?

**Answer:** Configurable limits per duty and per day, defaulting to one open request per duty and ten swap requests per member per day. Configurable at installation and organisation level as with other limits (the installation's value caps the organisation's). A member who goes over a limit gets a user-friendly process, in the owner's words: "there should be a user friendly process if someone makes a swap request above the limits set".

**Rules out:** No limit; fixed limits that cannot be configured; a bare refusal at the limit.

**Where:** #989 — `Portal\Core\RotaSwaps`, `Portal\Core\SettingLimit`. Related: D-141, the installation-caps-organisation rule.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
