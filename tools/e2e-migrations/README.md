# End-to-end migration test (#248)

Runs `web/_sql/` against a real, throwaway MySQL and checks three things:

1. **A new install works** (phases 1 to 4): `full_schema.sql` loads, and every
   numbered migration then replays on top of it without an error, which is
   what the real installer does (`web/_install/index.php`). The replay must
   leave the database as the install script made it, apart from five named
   differences (two links and three settings; see "The rule for the replay").
2. **An old database can be upgraded, and ends up the same as a new install**
   (phases 5 and 6, added 12 October 2026). Until then every phase started
   from today's install script, so a fault that only shows on an older
   database was invisible. Three were found that way by hand: #553, #554 and
   #555.
3. **The one statement that changes a customer's own rows does only what it
   should, in both files that hold it** (the three data checks, between phases
   4 and 5): migration 210 and the install script each empty only a pointer to
   an event that is gone, and both stop, changing nothing, when the events
   table itself is missing.

Which MySQL it runs on comes from `E2E_MYSQL_VERSION`: always an exact version
such as 8.0.36 or 8.4.11, and 8.0.36 when nothing is given. The pull-request
check runs it twice, side by side, on 8.0.36 and 8.4.11 (owner's decision, 24
September 2026), because MySQL 8.4 refuses some links between tables that
MySQL 8.0 allows (#552). Which MySQL 8 line production itself runs is not
confirmed (see #475).

## What it tests

| Phase | What it does | It passes when |
| --- | --- | --- |
| **1. Fresh install** | Empties the database and loads `web/_sql/full_schema.sql`. | The file loads with no SQL error. |
| **2. Installer replay** | Runs every numbered migration over the phase 1 database, in order, ignoring `tblMigrations`. This is the installer's own replay loop. | No migration fails; the number of tables, columns and indexes is the same as after phase 1; **and** the install script alone equals the install script plus the replay, by name (the rule is below). A different count of recorded migrations is a warning only; `check_schema_seed_parity.py` is the check for that. |
| **3. Run it all again** | Replays every migration a second time. Then loads `full_schema.sql` a second time over the same database. | No migration fails, and the counts of tables, columns, indexes and recorded migrations do not change: that is retrying an upgrade. Then the install script loads again with no error and leaves the whole inventory as it was: that is the installer's "carry on with the existing database" choice. (This row used to say the second replay "is somebody running the installer twice". It was not: the installer loads the install script first, and nothing here loaded that file over a database that already held it.) |
| **4. Catching up** | Loads `full_schema.sql` again, forgets that the newest half of the migrations ever ran, and lets them run again the way `Portal\Core\Migrator` would. | No migration fails, and the counts of tables, columns, indexes and recorded migrations all equal phase 1's. `--skip-stale` skips the forgetting and the catching up, and checks the freshly loaded database only. |
| **Data check 1: migration 210** | On the database phase 4 leaves, takes away the link from an attendance session to its event. That is the shape of a database built by the install script before 12 October 2026 (not of every older database: one upgraded through migration 009 always had the link). Plants three sessions (at a live event, at an event then deleted, at no event) and runs migration 210. | The first session keeps its pointer, the second has it emptied, the third is untouched; **every other column of every session is exactly as it was** (so no "last changed" time moved and no session was deleted or marked as deleted); and exactly one link from that column to the events table is there, with the right name and rule. |
| **Data check 2: the install script** | The same again with three new sessions, running `full_schema.sql` in place of migration 210. Then compares the three statements in the two files (the question "is a link already there?", the UPDATE and the ALTER) as text. | The same as data check 1, and the statements are word for word the same. |
| **Data check 3: the events table is missing** | Takes the link and then `tblEvents` away while sessions still point at events (a restore that got part-way), and runs migration 210, then the install script. | Each one **stops**, with error 1146 naming `tblEvents`; every column of every session, the pointer included, is as it was; and `tblEvents` has not been made again. |
| **5. Upgrade from the last release** | Builds the last release's database from that release's own `web/_sql/` (its install script, then its own migrations). Then runs this checkout's pending migrations the way the Migrator does: in order, **stopping at the first failure**. | The upgrade does not stop, and the result equals a fresh install (the rule is below). |
| **6. Upgrade from the oldest install script** | Checks the frozen install script of 6 March 2026 in `fixtures/` against its checksum, loads it, and upgrades it the same way. That file holds March's 19 base tables only, so this is "March's base tables, then today's copies of migrations 007 onwards". | The file's body is untouched, and then the same as phase 5. |

Phases 1 to 4 and the data checks stop the run at the first failure. Phases 5
and 6 both always run, because they start from different databases and one
failing says nothing about the other; the run fails if either does.

### What "the last release" means

The `main` branch (owner's decision, 25 September 2026, confirmed 12 October).
Release tags stopped in May 2026, so no tag can name it. Phase 5 reads
`origin/main`; give `E2E_BASE_REF` a commit, tag or branch to start somewhere
else. When tagging is real again, the default should become the newest tag.

GitHub checks out one commit only, so there `origin/main` is fetched first. If
the starting point cannot be found, **phase 5 fails with a plain message. It
never skips**, because a skipped phase reads exactly like a passed one. When
the branch being tested is `main` itself, phase 5 compares `main` with itself.
That passes only once phase 5's lists of named exceptions are empty: a
database compared with itself shows no difference, so any line still in those
lists is reported as no longer true (see "Each phase has its own lists" below).

### Why there are two starting points

- Phase 5 catches what goes wrong **after** the last release. It cannot catch a
  fault `main` is already past, and #553, #554 and #555 were all of that kind.
- Phase 6 catches those. It is a permanent test (owner's decision, 12 October
  2026). Its starting point is a file and not a commit name, so it needs no
  fetch and history cannot take it away. 6 March is the only old install
  script that loads on MySQL 8 unchanged.

**What phase 6 really starts from.** The frozen file creates only the 19 base
tables of that day and records seven migrations (000 to 006) as run. Every
other table is built by **today's** copies of migrations 007 onwards. The old
categories key (#553) and the missing `capacityCount` column (#555) are not in
the file; migration 008 creates them that way. So phase 6 stands for the path
a database installed that week has taken, as far as today's migration files
still say what they said then. It is not a copy of any customer's database.

**Never edit that file below its first line of hashes.** What it lacks is the
point, and its header says so. `run.sh` holds a checksum of that body
(`FIXTURE_BODY_CHECKSUM`) and phase 6 fails, without running, when the body
does not match. The checksum is git's own number for the original file, so it
needs no history to check (it works in GitHub's one-commit checkout), and
`git rev-parse 6f93394:web/sql/full_schema.sql` confirms it in a full clone.
The header above the line of hashes is not part of it and may be reworded,
but every line of it must stay a comment (`--` alone, or `--` and a space):
phase 6 fails, without running, when one is not. Otherwise a statement written
into the header would be run without the checksum ever seeing it.

### The rule for the replay (phase 2)

Phases 5 and 6 compare an upgraded database with a fresh install, and the
fresh install has had every migration replayed over it. So a migration that
**overwrites** a row writes the same wrong thing on both sides, and that
comparison cannot see it. The install script alone is the one copy no
migration has touched, so phase 2 holds the replay against it:

- **Structure must be the same before and after the replay, both ways**:
  every column, index and link. Otherwise a database built from the install
  script alone (the manual route in the main `README.md`) differs from one the
  installer built, and nothing would ever put it right, because the install
  script records every migration as already run.
- **The replay must not change or remove an address**: where it points, or
  whether it needs a sign-in.
- **The replay must not change a setting's stored value, or remove a
  setting.** A migration that does this undoes an administrator's choice every
  time the installer is run over a live database.
- An address or setting the replay **adds** is printed and does not fail here:
  `check_schema_seed_parity.py` is the check for that.

Five differences exist today. All are older than this rule, each is a real
difference, and each is named in `run.sh` and matched word for word, so a new
one cannot hide behind them. They are printed on every run, and **phase 2
fails when one of them is not seen**, with a message saying which line to
delete: an exception that is no longer true must not stay in the list.

- Two links, `fk_event_room` and `fk_event_venue` on `tblEvents`, which
  migration 179 adds and the install script does not declare.
- Three settings, `attendance.enabled`, `calendar.enabled` and
  `leadership.enabled`: the install script seeds them as `false`, and
  migrations 008, 009 and 017 write `true` over them. So every new install
  starts with those three apps switched on, and running the installer again
  over a live database switches them back on.

### The rule for "equals a fresh install" (phases 5 and 6)

"A fresh install" here is the database after phase 2: the install script
**plus** the installer's replay.

- **Structure must match exactly, both ways.** Every column (its table, name,
  type, whether it may be empty, default and extra), every index (table, name,
  each column's position, unique or not) and every link between tables (table,
  name, column, target table and column, what happens on delete and on
  update). One thing on either side only is a failure. There is no list of
  allowed exceptions: migration 210 renamed two keys and changed one default
  so that none is needed.
- **Addresses must match exactly, both ways** (`routeKey`, `targetFile`,
  `isProtected`). One missing after the upgrade is what found the podcast feed
  answering "page not found" on every upgraded database. One **only** on the
  upgraded database fails too: the router answers every row of `tblRoutes`, so
  a leftover address is a live page that exists on upgraded sites and nowhere
  else.
- **Every setting and every recorded migration a fresh install has must be on
  the upgraded database.** A missing one is a failure.
- **A setting only on the upgraded database is a failure too, unless it is
  named in `run.sh`.** This used to be printed and passed, on the grounds that
  no code asks for a setting it does not know about. That is not true as a
  rule: `web/_core/ApiRouter.php` builds the name `api.<app>.<action>.enabled`
  from the address somebody asks for, and switches that action on when the
  setting holds `true`. One leftover is named, for phase 6 only:
  `attendancecount.enabled`, which nothing reads. (A recorded migration only
  on the upgraded side is still printed and passed.)
- **A setting on both sides must hold the same value.** Nobody types anything
  into these test databases, so a different value can only come from a
  migration seeding the wrong thing on old databases. One difference is named
  in `run.sh` and printed on every run: `auth.password.minLength` is 12 on a
  fresh install and 8 on both upgraded databases, because migration 041 raised
  only the stored default. Whether upgraded sites should move to 12 is the
  owner's decision and has not been taken.
- **Each phase has its own lists of named exceptions** (`KNOWN_P5_…` and
  `KNOWN_P6_…` in `run.sh`), **and fails when a line in its list is not
  seen.** The two phases start from different databases, so what is true of
  one need not be true of the other. **The day this work reaches `main`, phase
  5 will fail on its `auth.password.minLength` line, on purpose:** a database
  built from `main` will then hold 12, and the message says to delete the
  line. For the same reason, a run with `E2E_BASE_REF` set to another starting
  point can fail on phase 5's lists.

## What it still cannot see

- **MariaDB.** Nothing here runs on it.
- **A database whose history matches neither starting point.** For example one
  installed in May or June 2026 by the installer of that time, which carried
  on past errors that the plain `mysql` client stops at. The install scripts
  of those months do not load on MySQL 8 as they stand.
- **A fault that is in the install script and in a migration alike.** Phase 2
  catches a migration that writes over the install script's row with something
  different. If both say the same wrong thing, every comparison here agrees.
  `check_route_targets.py` checks only that an address's file exists.
- **The contents of tables**, apart from addresses, settings and their values,
  recorded migrations, and the attendance sessions the data checks plant. Not
  a setting's stored **default**, and nothing a customer typed. So phase 3's
  second load of the install script would not show a seeded row being added
  twice in some other table.
- **The data-changing statement on a large table**, or sent through PHP rather
  than the `mysql` client. The data checks use three rows each.
- **An events table that is there but has not got its rows back** (a restore
  still half done). Data check 3 covers the table being missing. A pointer at
  an event missing from a table that exists is emptied, by both files, and
  nothing can tell that case from an event that was really deleted.
- **A link from the sessions' event column that is already there under another
  name.** Both files leave it as it is and add no second one (proved by hand
  on 12 October 2026, on MySQL 8.0.36 and 8.4.11); this test does not make
  one.
- **The order of columns, comments, character sets and collations, the formula
  of a worked-out column, the kind of an index and how much of a column it
  covers, check rules, and table options such as the storage engine.**
  Measured once by hand on 12 October 2026 for the 6 March database: comments
  differ, the order of columns differs in four tables, and 25 settings have an
  empty stored default; everything else in this list was identical.
- **The difference between the `mysql` client and the portal's own
  `multi_query`.** The test sends each file through the client; the installer
  and the Migrator send it through PHP. A file only one of them trips over
  does not show here.

## Run it locally

Needs `docker`, `docker compose`, `git` and bash 4 or newer.

```bash
tools/e2e-migrations/run.sh                # all six phases and the data checks, on MySQL 8.0.36
E2E_MYSQL_VERSION=8.4.11 tools/e2e-migrations/run.sh   # the same, on MySQL 8.4.11
E2E_BASE_REF=<commit, tag or branch> tools/e2e-migrations/run.sh   # phase 5 starts from there
tools/e2e-migrations/run.sh --skip-stale   # phase 4 without its catching-up half
tools/e2e-migrations/run.sh --keep         # leave the container up afterwards
```

Run one version at a time on one machine: both use the same container name
(`portal-e2e-mysql`) and the same host port (`33069`). A whole run took
between three and eight minutes on 12 October 2026, on a machine that was busy
with other work.

A passing run looked like this on 12 October 2026 (the numbers grow with every
migration; lines of `1` and password warnings from the `mysql` client are left
out):

```text
═════ Phase 1: full_schema fresh-install ═════
  ✓ full_schema.sql loaded without error
  schema: 224 tables, 2434 columns, 1218 indexes, 208 migrations recorded
═════ Phase 2: installer replay ═════
  replayed=208 failed=0
  schema: 224 tables, 2434 columns, 1218 indexes, 208 migrations recorded
  ✓ table/column/index counts identical to the full_schema baseline
  fresh-install inventory kept for phases 5 and 6: 2434 columns, 1218 index parts, 501 link parts, 595 addresses, 580 settings, 208 migrations
  ✓ columns: the replay leaves them as the install script made them (2434 lines)
  ✓ indexes: the replay leaves them as the install script made them (1218 lines)
  ✓ links: the replay leaves them as the install script made them (499 lines)
  ⚠ links the replay adds and the install script lacks — known and named in run.sh, not a failure:
      + tblEvents | fk_event_room | 1 | roomID | tblVenueRooms | roomID | on delete SET NULL | on update NO ACTION
      + tblEvents | fk_event_venue | 1 | venueID | tblVenues | venueID | on delete SET NULL | on update NO ACTION
  ✓ addresses: the replay changes and removes none (595 lines)
  ✓ setting values: the replay changes none but the named ones below, and removes none (580 lines)
  ⚠ setting values the replay changes — known and named in run.sh, not a failure:
      ~ attendance.enabled | every organisation | false → true
      ~ calendar.enabled | every organisation | false → true
      ~ leadership.enabled | every organisation | false → true
═════ Phase 3: idempotency re-run ═════
  replayed=208 failed=0
  schema: 224 tables, 2434 columns, 1218 indexes, 208 migrations recorded
  ✓ idempotency confirmed: zero net change on second replay
  ✓ full_schema.sql loaded a second time without error and changed nothing in the inventory
═════ Phase 4: Migrator catch-up over a full_schema base ═════
  base loaded: 224 tables, 2434 columns, 1218 indexes, 208 migrations recorded
  applied=104 skipped=104 failed=0
  schema: 224 tables, 2434 columns, 1218 indexes, 208 migrations recorded
  ✓ Migrator catch-up reaches the full_schema baseline: the counts of tables, columns, indexes and recorded migrations all match
═════ Data check: migration 210 empties only pointers to events that are gone (#554) ═════
  ✓ the session at a live event kept its pointer; the one at a deleted event had it emptied; the one with none is untouched
  ✓ every other column of all 3 sessions is exactly as it was, and the one link is in place
═════ Data check: the install script (full_schema.sql) empties only pointers to events that are gone (#554) ═════
  ✓ the session at a live event kept its pointer; the one at a deleted event had it emptied; the one with none is untouched
  ✓ every other column of all 6 sessions is exactly as it was, and the one link is in place
  ✓ the install script holds migration 210's question, UPDATE and ALTER word for word
═════ Data check: with tblEvents missing, migration 210 and the install script both stop and empty no pointer ═════
  ✓ 210_upgrade_gaps.sql stopped with error 1146 (tblEvents does not exist), changed no session and did not make the table again
  ✓ full_schema.sql stopped with error 1146 (tblEvents does not exist), changed no session and did not make the table again
═════ Phase 5: upgrade from the last release (origin/main) ═════
  starting point: origin/main = be29e726e607a77ea4ea26866c81d6ee53bf6a03
  replayed=159 failed=0
  last release installed: 158 tables, 1563 columns, 776 indexes, 159 migrations recorded
  applied=49 skipped=159 failed=0
  schema: 224 tables, 2434 columns, 1218 indexes, 208 migrations recorded
  ✓ columns: identical to a fresh install (2434 lines)
  ✓ indexes: identical to a fresh install (1218 lines)
  ✓ links: identical to a fresh install (501 lines)
  ✓ routes: identical to a fresh install (595 lines)
  ✓ settings: every one a fresh install has is there (580 lines)
  ✓ migrations: every one a fresh install has is there (208 lines)
  ✓ setting values: every setting both have holds the same value, apart from the named one below
  ⚠ setting values that differ — known and named in run.sh, not a failure:
      ~ auth.password.minLength | every organisation | 12 → 8
  ✓ a database upgraded from the last release equals a fresh install
═════ Phase 6: upgrade from the oldest install script (6 March 2026) ═════
  ✓ the frozen install script's body is untouched (checksum 957d8e5ccd186432ddb6b6a94c789f9a35596195) and its header holds comments only
  old database installed: 19 tables, 127 columns, 49 indexes, 7 migrations recorded
  applied=201 skipped=7 failed=0
  schema: 224 tables, 2434 columns, 1218 indexes, 208 migrations recorded
  ✓ columns: identical to a fresh install (2434 lines)
  ✓ indexes: identical to a fresh install (1218 lines)
  ✓ links: identical to a fresh install (501 lines)
  ✓ routes: identical to a fresh install (595 lines)
  ✓ settings: every one a fresh install has is there (580 lines)
  ⚠ settings left over on the upgraded database only — known and named in run.sh, not a failure:
      + attendancecount.enabled | every organisation
  ✓ migrations: every one a fresh install has is there (208 lines)
  ✓ setting values: every setting both have holds the same value, apart from the named one below
  ⚠ setting values that differ — known and named in run.sh, not a failure:
      ~ auth.password.minLength | every organisation | 12 → 8
  ✓ a database upgraded from the oldest install script equals a fresh install
═════ All phases passed ═════
```

A failing upgrade names the migration it stopped at and prints what the
database said. This is #555 before migration 124 was corrected:

```text
  ✗ the upgrade STOPPED at 124_event_registrations.sql, after 117 migration(s) had run. The database said:
      ERROR 1054 (42S22) at line 25: Unknown column 'capacityCount' in 'tblEvents'
    Nothing after 124_event_registrations.sql was run, exactly as on a customer's database.
✗ phase 6 FAILED — a database installed in March 2026 cannot be upgraded to this checkout
```

A failing comparison lists each difference: `-` is on a fresh install and
missing after the upgrade, `+` is the other way round, and `~` is a setting
whose value differs (the fresh install's value, an arrow, the upgraded one's).
In phase 2 the same marks compare the install script alone with the install
script plus the replay.

## How it is wired

- `docker-compose.yml` takes its image from `E2E_MYSQL_VERSION` (8.0.36 by
  default; always an exact version, never a moving tag), offers port `33069`
  on the host (an unusual one, so it does not clash with a local MySQL), and
  keeps the database in memory so every run starts empty.
- `run.sh` starts the container, waits until a signed-in query works
  (`wait_for_mysql`), then asks the server its own version and refuses to
  carry on if it is not the one asked for. Without that, a variable that never
  reached `docker-compose.yml` would test the wrong MySQL and still pass.
- **Phases 2 and 3** use `replay_all_migrations()`: every `web/_sql/NNN_*.sql`
  file in name order, run whether or not `tblMigrations` says it has run. That
  matches the installer, which ignores `tblMigrations` for the same reason.
- **Phase 4** uses `run_all_migrations()`, which does read `tblMigrations`
  first, as `Portal\Core\Migrator` does, but carries on after a failure and
  counts them.
- **Phases 5 and 6** use `upgrade_pending_migrations()`, which reads
  `tblMigrations` once and **stops at the first failure**, as
  `Migrator::runAll()` does. #555 is exactly "the upgrade stops and nothing
  after it runs", so a runner that carries on would hide it.
- **Phase 5** takes the last release's `web/_sql/` out of git with
  `git archive` into a temporary folder. Nothing in the checkout is changed.
- The comparison in phase 2 is `compare_replay_with_install_script_alone()`
  and the one in phases 5 and 6 is `compare_with_fresh_install()`. Both read
  seven sorted lists from each database (`dump_inventory()`). Structure is
  compared line by line with `comm`; addresses and setting values in phase 2,
  and setting values in phases 5 and 6, are compared row by row
  (`row_changes()`), which can tell "this address now points somewhere else"
  from "one went and another came". The reason behind each part of each rule,
  and each named exception, is written beside those functions.
- **The data checks** are `check_dead_pointer_cleanup()` (run once with
  migration 210 and once with the install script),
  `check_install_script_wording()` and `check_missing_events_table()`. They
  are not inside phase 5 on purpose: the statement only runs there while the
  last release has neither the link nor migration 210, and the day this work
  reaches `main` a check planted there would have gone blind. Taking the link
  away first works on every day.
- **Named exceptions** are checked by `unseen_exceptions()`: a line in a list
  that was not seen in the run fails the phase.

## The quick checks that go with it

`tools/audit-checks/check_mariadb_only_ddl.py` and
`tools/audit-checks/check_migration_idempotency.py` read the SQL files without
starting a database. They catch wording only MariaDB accepts, and statements
that are not safe to run twice. Run them on every commit, and this test
whenever `web/_sql/` changes.

`tools/audit-checks/check_schema_seed_parity.py` checks that every address,
setting and migration record a **migration** creates is also in
`full_schema.sql`. It does not check the other direction, a row the install
script seeds that no migration does; phases 5 and 6 do, and that is how the
missing podcast addresses were found.

## On GitHub

`.github/workflows/e2e-migrations.yml` runs this test on pull requests, and on
pushes to alpha, beta and main, in both cases only when `web/_sql/`, this
folder or the workflow itself changes. It can also be started by hand. It runs
the test twice, as two jobs side by side: MySQL 8.0.36 and MySQL 8.4.11.

**Phases 5 and 6, the data checks and the checksum have not yet run on GitHub**
(written 12 October 2026). Phase 5's fetch of `origin/main` in GitHub's
one-commit checkout, and the checksum under Linux's `sed` and `awk`, are the
parts that have only been reasoned about, not seen.
