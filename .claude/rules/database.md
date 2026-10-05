---
paths:
  - "web/_sql/**"
---

<!-- .claude/rules/database.md - two sections moved word for word out of .claude/CLAUDE.md on
     5 October 2026 (owner's decision, Salem874). Claude Code loads this file only when it reads or
     edits a file under web/_sql/, because both rules matter only there. A one-line summary of the
     first rule stays in .claude/CLAUDE.md so that planning, which happens before any file is
     opened, still sees it. Codex reads the same rules as rules 13 and 14 of .OpenAI/CONTEXT.md.
     If you change a rule, change both. -->

## Every database change goes in the install script too (STANDING RULE)

**Any change to the shape of the database must ALSO appear in the fresh-install
script.** The owner confirmed this on 11 September 2026 as a standing rule for
all future work, not a one-off.

It has to reach BOTH of the two ways a customer's database can come into being,
and they must agree:

1. **THE INSTALLER** — a brand-new database, built from nothing.
   `web/_sql/full_schema.sql`, run by `web/_install/index.php`.
2. **THE UPGRADE** — a database that already exists and is being brought up to
   date. A **numbered migration** in `web/_sql/`, replayed by
   `web/_core/Migrator.php` through `web/_install/upgrade.php` (which is what
   the Admin → Upgrade button reaches, via a one-line proxy at
   `web/_apps/admin/upgrade.php`).

A change that reaches only one of them is the dangerous case, and it is quiet.
Put it only in a migration and a brand-new install is missing it. Put it only in
the fresh-install script and every existing customer never gets it. Either way
two installations of the same version behave differently, and nobody finds out
until somebody hits it — by which time the difference is old and hard to trace.

Migrations must be safe to run twice, because the installer replays
`full_schema.sql` and then EVERY numbered migration, ignoring which ones have
already run.

`tools/audit-checks/check_schema_seed_parity.py` compares the two and fails when
they disagree, so this is enforced rather than remembered. Run it before
committing anything that touches `web/_sql/`.

**The storage engine is InnoDB and should stay that way.** All 209 tables use
it. It is what makes transactions and links between tables possible — and it is
what makes an all-or-nothing restore possible at all (#472). The alternative,
MyISAM, supports neither. Moving away from InnoDB would break the backup restore
and the safety of every multi-step database change in the portal.

## SQL dialect trap (apply on every migration)

- **Production runs MySQL 8** (DreamHost shared hosting offers no other engine and no version choice). **Which** MySQL 8 is not confirmed — 8.0's support ended April 2026, 8.4 LTS runs to 2029; see #475. Either way it is MySQL, so MariaDB-only `IF [NOT] EXISTS` on `ADD`/`DROP COLUMN`, `ADD`/`CREATE`/`DROP INDEX`/`KEY`, or `CHANGE`/`MODIFY COLUMN` is rejected with **ERROR 1064** — `CREATE TABLE IF NOT EXISTS` / `DROP TABLE IF EXISTS` are standard MySQL and stay fine.
- **Use the `information_schema` + `PREPARE`/`EXECUTE` guard idiom** instead (see DEV_NOTES.md → "Portable DDL convention (MySQL 8.0 ∩ MariaDB)" for the full templates). House examples already shipped this way: migrations **037**, **112**, **138**.
- **Migrations must replay as no-ops** on an up-to-date schema — the installer replays every numbered migration after `full_schema.sql`, ignoring `tblMigrations`.
- **CI**: `tools/audit-checks/check_mariadb_only_ddl.py` + the `e2e-migrations` harness enforce this.
