# D-028 · Quiet hours never hold a critical alert

**Date:** 2026-10-13  
**Status:** in force

**Question:** may a text message be held for quiet hours whatever it says?

**Answer:** no. `Sms::NEVER_HELD` is the one list of what quiet hours never
hold — a critical alert and an emergency notice go out at once.

**Rules out:** a second list of exceptions anywhere else; holding an emergency
notice until morning.

**Where:** `Portal\Core\Sms::NEVER_HELD`; #610.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
