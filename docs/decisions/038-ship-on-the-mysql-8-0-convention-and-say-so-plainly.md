# D-038 · Ship on the MySQL 8.0 convention and say so plainly

**Date:** 2026-11-01  
**Status:** in force

**Question:** should the supported-database floor be raised to 8.4 before
release?

**Answer:** no. Ship on the MySQL 8.0 ∩ MariaDB convention and say so plainly.

**Reason:** the owner's own DreamHost server reported 8.0.41, and the migration
harness and calendar tests already passed on 8.4.11 and MariaDB 11.4.13 — so 8.0
is a FLOOR, not a ceiling.

**Rules out:** raising the floor before release. See also D-019, which kept 8.0
accepted with a warning after its support ended.

**Where:** #475; `Portal\Core\DbServer`.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
