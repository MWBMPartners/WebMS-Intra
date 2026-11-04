# D-019 · MySQL 8.0 and MariaDB 10.6 stay accepted, with a warning

**Date:** 2026-11-03  
**Status:** in force

**Question:** should the portal refuse to install on a database that no longer
gets security fixes?
**Answer:** no — keep installing, with a warning. `MIN_MYSQL` stays `8.0.0` and
`MIN_MARIADB` stays `10.6.0`.
**Rules out:** raising those two constants as a tidy-up. **It also makes the
warning the only protection**, which is why `TESTED_LINES` and its gate exist.
**Where:** `Portal\Core\DbServer`; `.claude/CLAUDE.md`.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
