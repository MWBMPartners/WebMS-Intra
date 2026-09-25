#!/usr/bin/env python3
# -----------------------------------------------------------------------------
# tools/audit-checks/check_fk_references_unique_key.py — every foreign key
# must point at a PRIMARY KEY or a UNIQUE KEY on exactly its own columns.
# -----------------------------------------------------------------------------
# @package   WebMS-Intra (development tooling — never deployed; only web/ is)
# @author    MWBM Partners Ltd (t/a MWservices)
# @copyright MWBM Partners Ltd (t/a MWservices). All Rights Reserved.
# @version   1.4.0 (25 September 2026, #552)
"""
Foreign-key-target-is-a-real-key check (#552).

WHY THIS EXISTS
----------------
MySQL 8.4 turns on a rule of its own, called `restrict_fk_on_non_standard_key`,
that MySQL 8.0 never enforced. Under that rule, a foreign key is refused with
ERROR 6125 unless it points at the referenced table's PRIMARY KEY, or a UNIQUE
KEY covering EXACTLY the columns it points at, in the same order. An ordinary
(non-unique) index used to be enough on MySQL 8.0 — it no longer is.

This was found the hard way on 24 September 2026 (#552): three composite
foreign keys added on this branch each pointed at an ordinary index instead
of a unique one — one in migration 202, two in migration 203. The true
account, so it can be stated the same way everywhere: with the ORIGINAL
(pre-fix) files, a fresh install failed straight away, inside
`full_schema.sql` itself — this codebase's house style folds every
migration's DDL back into that one consolidated file too, so the same bad
foreign key sat there directly. An upgrade from alpha never runs
`full_schema.sql` at all — it replays the numbered migrations in order
against whatever a real customer's database already has — so it failed
instead inside migration 202, the first of the three links, and the
Migrator stopped there: nothing after it in the queue could ever run. With
the FIXED `full_schema.sql` — the three indexes made unique — paired with
the OLD 202 and 203, a fresh install succeeds: `full_schema.sql` already
has the right shape before either migration ever runs, so their own
(still old) text finds each key already unique and does nothing. Only the
upgrade path still needed 202 and 203 fixed too, because an upgrade never
sees `full_schema.sql`'s own fix at all. `check_mariadb_only_ddl.py`, the
check next to this one, cannot see this fault either way — it reads SQL as
plain text and never asks a real MySQL server anything, so it has no notion
of version-specific rules at all. Nothing else in this repository's
automated checks runs against MySQL 8.4, so this is the one check that
reads the shape of every foreign key and asks, in plain terms, "does the
table on the other end really have a key exactly like this one, whichever
way a real database came to hold it?" — without needing a MySQL 8.4 server
to find out.

WHAT IT CHECKS — FOUR SEPARATE PASSES
--------------------------------------
A database comes into being in exactly two ways, and — as fix round 2 found —
checking them separately is not quite enough either: the INSTALLER'S OWN
fresh install runs `full_schema.sql` and THEN replays every numbered migration
on top (so a link a migration adds, that never appears in `full_schema.sql`
itself, still has to work on a fresh install too), and an UPGRADE's own
starting point cannot safely be "guessed" from which keys no migration
happens to create. Four passes, run together, cover this properly:

* Rule 1a — FRESH INSTALL, `full_schema.sql`'s OWN links. Every foreign key
  `full_schema.sql` itself declares is checked against `full_schema.sql`'s own
  primary and unique keys — nothing from the migrations is allowed to "cover"
  this link, because on THIS path nothing from the migrations has run yet.

* Rule 1b — FRESH INSTALL, the INSTALLER'S OWN REPLAY. The installer does not
  stop at `full_schema.sql` — it then replays every numbered migration on top
  (`web/_install/index.php`), and this codebase deliberately leans on that:
  migration 179's two links to `tblVenues`/`tblVenueRooms` are never folded
  into `full_schema.sql`'s own `tblEvents` block at all (that table is created
  thousands of lines earlier in the file, before either target table exists to
  point at) — on a fresh install, those two links only ever come into being
  because the replay adds them afterwards. Rule 1a alone cannot see a link
  shaped like that, because it never looks at the migrations at all. Rule 1b
  does: starting from `full_schema.sql`'s OWN complete picture (every table it
  declares, fully formed, exactly as Rule 1a already checked), it replays
  every migration's key-and-link events in order, with ONE rule that decides
  whether a `CREATE TABLE IF NOT EXISTS` block does anything at all — see
  "The `CREATE TABLE IF NOT EXISTS` rule, shared by Rule 1b and Rule 2" below.

* Rule 2 — UPGRADE, IN ORDER. An upgrade never runs `full_schema.sql`. This
  rule starts from `STARTING_SET` below: 32 recorded keys. Thirty belong to
  the 14 original tables, which predate the numbered migrations. The other
  two are `tblTrustedDevices` keys that migration 047 creates under
  different names; they are listed as known drift (#553). See "The
  starting set is now a fixed list, not a guess" further down.
  Two further original UNIQUE keys, `tblRoles.roleKey` and
  `tblSettings.settingKey`, are NOT on that list. The reason is simple:
  `full_schema.sql` no longer declares them (migrations 202 and 015
  dropped them), and every run checks the list against `full_schema.sql`.
  Leaving them out has one side effect: Rule 2 cannot see either key even
  in the migrations that ran BEFORE the drop. That is harmless today,
  because nothing links to either key. Rule 2 then replays every numbered
  migration file (`web/_sql/[0-9][0-9][0-9]_*.sql` — nothing else; a
  released folder has held `demo_data.sql` alongside them, and that
  file is not part of the migration sequence) in NUMBER order. That
  models a database built by replaying the WHOLE migration history from
  the very beginning. That is not quite the
  same thing as "whatever a real database already has": most real,
  existing databases were instead installed from some OLDER copy of
  `full_schema.sql` and then upgraded from there — a starting point this
  rule cannot reproduce, because it does not know what any particular older
  `full_schema.sql` looked like. See "WHAT THIS CANNOT SEE" below for what
  that difference means in practice. Fix round 1 tried to
  work out an upgrade's STARTING point automatically ("any key
  `full_schema.sql` declares that no migration ever creates must predate the
  migration history") — an independent check proved that guess wrong (round
  2's finding T5): a key someone adds to `full_schema.sql` and simply forgets
  to add to a migration fits that description exactly, so the automatic guess
  wrongly treated it as present from day one, and "covered" a migration's link
  that a real MySQL 8.4 upgrade refuses with ERROR 6125. Fix round 2 replaces
  the guess with `STARTING_SET` below — a short, HAND-VERIFIED list, not an
  inference — see "The starting set is now a fixed list, not a guess" further
  down. From that exact list, strictly in the order migrations appear — file
  by file in number order, and within one file in the order the text itself
  reads, INCLUDING text sitting inside the quoted strings of this codebase's
  guarded `PREPARE`/`EXECUTE` idiom — every `ADD [UNIQUE] KEY|INDEX`,
  `ADD PRIMARY KEY`, `CREATE [UNIQUE] INDEX` and `DROP INDEX|KEY` updates that
  table's running picture of its own keys, and the SAME `CREATE TABLE IF NOT
  EXISTS` rule as Rule 1b applies here too (see below — round 2's finding T6:
  a table's OWN re-declaration, once it already exists, adds nothing). The
  moment a migration adds a foreign key, this script checks it against the
  picture AS IT STOOD AT THAT EXACT POINT — including anything an EARLIER
  statement in the SAME file already added — never against `full_schema.sql`'s
  own keys (an upgrade never runs that file) and never against something a
  LATER migration will only add afterwards.

* Rule 3 — THE TWO MUST AGREE. For every (table, index name) that BOTH
  `full_schema.sql` AND the migrations create, whether it ends up UNIQUE (and
  whether it is a prefix index — see below) must be the SAME by both routes.
  A fresh install and an upgrade are supposed to land on the identical
  database; if `full_schema.sql` says a key is unique and the migrations that
  build the same key leave it non-unique (or the reverse), that identical
  result has quietly stopped being true.

Alongside the four rules, ONE MORE pass — "the starting set is now a fixed
list, not a guess" below — checks the list ITSELF against `full_schema.sql`,
so a key nobody has explained is a finding rather than a silent assumption.

The `CREATE TABLE IF NOT EXISTS` rule, shared by Rule 1b and Rule 2
---------------------------------------------------------------------
MySQL's `CREATE TABLE IF NOT EXISTS` does NOTHING at all when the table
already exists — not even to the columns or keys the statement names; the
whole statement is skipped. This is not a rare edge case in this codebase's
REAL migrations: because `full_schema.sql` folds every migration's DDL back
into one file (see DEV_NOTES.md → "`full_schema.sql` fold pattern"), but the
ORIGINAL migration is never deleted or rewritten, most migrations still
carry their own full `CREATE TABLE IF NOT EXISTS` for a table
`full_schema.sql` (for Rule 1b) or an earlier migration (for Rule 2) has
already created. Of the 479 foreign keys the migrations declare today, Rule
1b's walk only actually CHECKS 36 of them — the other 443 sit inside exactly
this shape, and this rule is what correctly SKIPS them, because on a real
fresh install every one of those 443 redeclarations does nothing at all,
including any foreign key sitting inside its own body.

Round 2's finding T6 is what proves the skip is scoped correctly, not too
broadly. T6 plants a table (`tblP6`) that `full_schema.sql` and a migration
BOTH declare identically — so the migration's own redeclaration is rightly a
no-op — followed, in the SAME migration, by a SEPARATE, standalone `ALTER
TABLE ... ADD CONSTRAINT` foreign key. That is a different SQL statement,
one the redeclaration's no-op-ness never touches, and it is still checked
normally: T6's link is correctly found uncovered (the table it points at,
`tblEventCategories`, has no UNIQUE key on `categorySlug` alone in
`full_schema.sql` today — only the composite `uq_cat_slug_site`). T6 proves
the skip stops exactly at the redeclared `CREATE TABLE` block's own
boundary, and never silently swallows a later, separate statement for the
same table too.

Both Rule 1b and Rule 2 track, as they walk, exactly which tables already
exist at each point (Rule 1b starts knowing about EVERY table
`full_schema.sql` declares, since a fresh install creates all of them
before any migration ever replays; Rule 2 starts knowing only about
`STARTING_SET`'s own tables). The MOMENT a migration's `CREATE TABLE IF NOT
EXISTS` block is for a table ALREADY known, this script skips that block's
own PRIMARY KEY, named-key and inline-foreign-key events entirely — they
never happened on a real database either. Anything OUTSIDE that block (a
separate `ALTER TABLE`, a standalone `DROP INDEX`, a separate foreign key
added afterwards) is a completely different SQL statement and is NEVER
skipped by this rule — only the redeclared `CREATE TABLE` block's own
contents are.

The starting set is now a fixed list, not a guess
----------------------------------------------------
Every one of Rule 2's 32 starting-set entries (14 tables' PRIMARY KEY, 18
named indexes) was checked BY HAND against every numbered migration and is
listed in `STARTING_SET` below, each with the reason it is there. 30 of the
32 genuinely predate the numbered-migration system: no migration 000-206
contains a `CREATE TABLE` for any of their 14 tables (checked case-
insensitively against every file). Migration 000 is the very FIRST numbered
migration — the one that brings `tblMigrations`, the tracking table the
whole numbered system depends on, into being — and it creates nothing else
at all. That confirms these 14 tables already existed before migration 000
ever ran; there is no migration earlier than 000, so nothing in the numbered
system could have created them either. The remaining 2
(`tblTrustedDevices.uq_td_token_hash` and `.idx_td_user_active`) are NOT
primordial at all — migration 047 creates that whole table — and are listed
for a DIFFERENT, narrower reason: see their own comment in `STARTING_SET`,
and "WHAT FIX ROUND 2 FOUND ALONG THE WAY" below.

Nothing is inferred from the file list any more. Any key `full_schema.sql`
declares that is NEITHER created by a numbered migration NOR on this list is
reported as its own finding (see `explain_unaccounted_keys` below) —
"declared only in full_schema.sql — an upgraded database will not have this
key". Round 2's finding T5 is exactly this shape, and is caught this way.
The list itself is also checked against `full_schema.sql` every time this
script runs (`build_upgrade_baseline`): an entry naming a table or a key
`full_schema.sql` no longer declares, or a key a migration has since started
creating under that exact name, is reported as its own finding too — the
list must stay true, not just exist. A SECOND check runs on top of that:
even an entry whose table and key name are both still there can have had
its SHAPE edited in `full_schema.sql` only — see `StartingSetEntry` and
`check_starting_set_shapes`, below. Between the two, a listed key going
stale by name and a listed key changing shape are both now reported — but
see "WHAT THIS CANNOT SEE" below for what is still NOT covered by either
check.

WHAT CHANGED IN FIX ROUND 1 (24 September 2026)
-------------------------------------------------
Version 1.0.0 of this script (the one #552's build produced) POOLED every key
it found in `full_schema.sql` and in the migrations into one shared picture,
then checked every foreign key — wherever it came from — against that pooled
picture. That is wrong in a way that matters: it means a key declared
UNIQUE in only ONE of the two places "covers" a link that actually comes from
the OTHER one, where that unique key does not really exist. An independent
check proved this with two planted faults — a link in `full_schema.sql`
"covered" by a unique key that only a migration ever creates, and a link in
a migration "covered" by a unique key that only `full_schema.sql` ever
declares — both of which the pooled version passed cleanly under `--strict`
even though each one really does fail on MySQL 8.4. Version 1.1.0 replaced
the pooled model with separate rules, which is what actually caught both of
those planted faults. Issue #552 is the issue this check was written for.

Version 1.0.0 also had several further faults an independent check found: it
silently missed an unnamed foreign key and a `REFERENCES` clause with no
backticks around the table name (both of which MySQL 8.4 accepts and refuses
exactly the same as a named, backticked one); it wrongly flagged a key made by
`CREATE UNIQUE INDEX`, by a `UNIQUE INDEX` clause, or by an inline column-
level `PRIMARY KEY` (MySQL 8.4 accepts all three as a real covering key, so
treating them as absent was a false alarm); and its printed findings never
contained the `•` character the pull-request workflow (see
`.github/workflows/pr-security.yml`) looks for before showing a check's output
at all. All of those were fixed in round 1 too.

WHAT CHANGED IN FIX ROUND 2 (24 September 2026)
-------------------------------------------------
A second independent check (round 2) proved version 1.1.0 still had real
gaps, all fixed here:

* Rule 2's automatic starting-point guess ("no migration creates this key, so
  it must predate history") was provably wrong — see "The starting set is now
  a fixed list, not a guess" above. Replaced with `STARTING_SET`, a hand-
  verified list, plus `explain_unaccounted_keys` so an unexplained key is now
  a finding instead of a silent pass (round 2's finding T5).
* A `CREATE TABLE IF NOT EXISTS` for a table that already exists was still
  credited with adding keys it never really adds on a real database, on
  BOTH the upgrade path and — because Rule 1a never modelled the installer's
  own migration replay at all — the fresh-install path too (round 2's finding
  T6). See "The `CREATE TABLE IF NOT EXISTS` rule..." above; Rule 1b is new.
* A later migration turning an EXISTING plain key unique with the ordinary
  name-only guard (`IF` an index of this name is absent, `ADD UNIQUE`; `ELSE`
  do nothing) was read as if it always succeeds — a real database's guard
  would see the name already exists and do nothing, leaving the OLD plain key
  in place (round 2's finding T7). `apply_key_event` below now checks: if a
  "key" event of ANY shape finds a key of that exact name ALREADY on the
  table in a DIFFERENT shape (not the same columns, uniqueness or prefix-
  ness), with nothing having dropped it earlier in this SAME walk, the text
  is NOT applied — a real name-only guard would do nothing here too, and the
  table's OLD, real shape is what any LATER foreign key is then correctly
  checked against (that ordinary foreign-key-coverage check is what actually
  catches T7, not a dedicated message on the guard's own line — see
  `apply_key_event`'s own comment for why, and why the rule has to work the
  same way in BOTH directions: an `ADD UNIQUE` that cannot be trusted to
  really happen, AND a plain `ADD KEY` that cannot be trusted to really
  downgrade an existing unique one either — proving Rule 1b against a
  fresh-install case where a later `ADD KEY` tries to downgrade an
  existing UNIQUE key of the same name is exactly what found the second
  direction was needed too). This is also what makes fixed migrations
  202/203's OWN three-branch guard safe to read this way. Read in the
  plain order the file lists the branches, the FIRST candidate text that
  could make the key unique is always reached before any later branch's
  text that assumes it might still be plain. That ORDERING claim was
  checked by hand against
  202's own A6b block — not by T7. T7 proves something narrower: that
  `apply_key_event`'s general mechanism (a same-named key already present
  in a different shape stops a later event's text from being trusted)
  actually works, on its own small planted example. It does not exercise
  202's specific three-branch order at all.
* A prefix key (`` `endpoint`(255) ``) was read as an ordinary, non-prefix key
  on a column literally called `` endpoint`(255 `` — the regular expression
  for a column list stopped at the FIRST closing parenthesis, which closes
  the PREFIX LENGTH, not the column list itself. `parse_col_list` and every
  regular expression that captures a column list now allow one balanced
  `(NNN)` prefix-length group inside the list, and an optional trailing
  `ASC`/`DESC`, so the real column name and the fact that it is a prefix are
  both read correctly — a prefix key still never covers a link, as before,
  just for the right reason now.
* An `ALTER TABLE` naming its table WITHOUT backticks (`ALTER TABLE tblQ ADD
  KEY ...`) was invisible to `ALTER_TABLE_RE`, so whatever it declared was
  silently credited to the wrong table (whichever backticked `ALTER TABLE`
  happened to appear earlier in the same file). `ALTER_TABLE_RE` now accepts
  a table name with or without backticks.
* The printed findings still lacked the one thing that actually reaches a
  pull-request comment now that this check is wired into `pr-security.yml`:
  see "HOW THIS IS WIRED INTO pr-security.yml" below for the shape that
  keeps a genuine finding from being read as a clean run.
* Several comments here, in `DEV_NOTES.md` and in migrations 202/203 said
  things that were not quite true — fixed throughout, each in its own place
  rather than only listed here.

WHAT CHANGED IN FIX ROUND 3 (24 September 2026)
-------------------------------------------------
A THIRD independent check (round 3) proved round 2's `STARTING_SET` fix,
while a real improvement, was not the whole fix:

* MEDIUM 1 — `build_upgrade_baseline` fixed WHICH (table, key) pairs belong
  in Rule 2's starting set, but still read each entry's SHAPE (its columns,
  whether it is UNIQUE, any prefix length) out of `full_schema.sql` at run
  time. So editing a LISTED key's shape in `full_schema.sql` alone, with no
  matching migration, was silently trusted as if an upgraded database
  already had it that way — round 3's T8 (a listed plain key turned UNIQUE
  in `full_schema.sql` only) and S4 (a listed PRIMARY KEY widened in
  `full_schema.sql` only) both passed this check clean while a real MySQL
  8.4 upgrade from alpha failed both with ERROR 6125. Each `STARTING_SET`
  entry now RECORDS its shape (`StartingSetEntry`); the entries were built
  by this script's own parser reading today's `full_schema.sql`. On
  24 September 2026 they were separately confirmed — by an independent
  reading, not this parser — against 93 commits that touch
  `full_schema.sql` (92 distinct file contents) and a real MySQL 8.4.11
  database upgraded from alpha. `build_upgrade_baseline` seeds Rule 2's
  walk from that recorded shape, never `full_schema.sql`'s own;
  `check_starting_set_shapes` separately compares the two every run and
  reports a mismatch in the columns — and, for a named key, also in
  whether it is UNIQUE or a PREFIX key (a listed PRIMARY KEY is always a
  whole-number column, which MySQL cannot prefix, so that half of the
  comparison only ever applies to a named key). It does NOT compare the
  prefix LENGTH itself, only whether there is one — a prefix key can never
  be what a foreign key points at, whatever its length, so the length
  makes no difference to what this check protects. See "The starting set
  is now a fixed list, not a guess" above.
* LOW 2 — the "WHAT THIS CANNOT SEE" section below now says plainly what
  Rule 2 actually models (a database built by replaying every migration
  from the very start) versus what most real databases actually are
  (installed from some older `full_schema.sql`, then upgraded), and lists
  the specific shapes this still cannot see (an edited released migration,
  round 3's T9; a name-only guard hidden behind a symmetric skip and an
  old leftover key, T10) rather than claiming more than the code does.
* LOW 3 — this docstring used to send a reader to issue #552 for several
  things the issue does not actually contain (a worked-example list, a
  method, further detail). Issue #552 is cited from here on only as "the
  issue this check was written for"; anything a reader actually needs is
  written here instead.
* LOW 4 and several nits — wording fixes throughout, each corrected in its
  own place rather than listed twice here: the "CREATE TABLE IF NOT EXISTS"
  section's description of what T6 actually tests (a standalone `ALTER
  TABLE` after a redeclaration, not an inline foreign key inside one); the
  account of which path fails where kept consistent across this script, 202,
  203 and DEV_NOTES.md; migration 202's state-3 causes folded into one (an
  EARLIER, pre-#552 copy of the file, however it got there); and several
  long, dash-joined sentences broken into short ones.
* A non-UTF-8 byte in the schema file or a migration file used to reach
  Python's own default handler — a raw traceback and exit code 1, with no
  file name in the first line, and (worse) indistinguishable from a clean
  run by the exact `pr-security.yml` pattern "HOW THIS IS WIRED INTO
  pr-security.yml" below already warns about. It now gives the same kind
  of clear, file-naming message a missing file gives, and exits 2.

WHAT FIX ROUND 2 FOUND ALONG THE WAY
---------------------------------------
Classifying `STARTING_SET` by hand (see above) turned up two things that are
NOT part of #552 and were NOT fixed here, because this round may only change
comments, never a SQL statement:

* `tblTrustedDevices.uq_td_token_hash` / `.idx_td_user_active` — declared in
  `full_schema.sql` under those names, but migration 047 (which creates the
  WHOLE table) creates the SAME columns under DIFFERENT names instead
  (`uq_token_hash`, `idx_user_active`). The parser reads BOTH files correctly
  — this is genuine drift between the two files, not a reading fault. It is
  harmless for what this check protects: MySQL 8.4's rule, and every rule
  above, match a foreign key's target by its COLUMN LIST, never by the key's
  own name, and nothing anywhere in this codebase has a foreign key pointing
  at `tblTrustedDevices` at all (checked: zero `REFERENCES` clauses name it).
  Listed in `STARTING_SET` with that reason, not as "predates history" (it
  does not — the whole table is migration 047's) — flagged in #553, its own
  separate issue, rather than folded into #552.
* Migration 019's guard for `tblEventCategories.uq_cat_slug` checks for an
  index named literally `uq_cat_slug` — but migration 008 actually created it
  as `uq_category_slug` (different name). Proved on a real MySQL 8.4.11
  container: replaying migrations 008 then 019 in raw sequence leaves the OLD
  single-column key in place forever, alongside the new composite
  `uq_cat_slug_site` migration 019 also adds — the DROP this guard is meant
  to perform never actually matches anything. This is unrelated to #552 (the
  table is not in `STARTING_SET` at all — migration 008 creates it) and is
  currently harmless for the same reason as the `tblTrustedDevices` item
  above: nothing anywhere references `tblEventCategories(categorySlug)` alone
  by foreign key. Reported here as a surprise, not fixed — also tracked in
  #553, since it is the same shape of drift (a migration's guard naming the
  wrong key) as the `tblTrustedDevices` item above, not #552's own scope.

A prefix key (`` `endpoint`(255) ``) is read correctly — its real column name,
`endpoint`, is extracted separately from the `(255)` prefix length — but is
NEVER credited with covering a link, under any rule above. MySQL 8.4's rule
needs an index covering the WHOLE column; a prefix index only ever covers part
of the value stored in it, so it can never be what a foreign key points at,
unique or not.

HOW THIS IS WIRED INTO pr-security.yml
----------------------------------------
Since 25 September 2026 (owner's approval of 24 September 2026) this script
runs as its OWN step in `pr-security.yml`, named "Foreign-key target check
(#552, check 23 — runs on every PR, even if an earlier step failed)", wired
the way check 9 is — see check 9's comment for the trap this avoids: a step
shaped like `OUTPUT=$(python3
thischeck.py 2>&1 | tail -n +N || true)` followed by `echo "$OUTPUT" |
grep -q '•'` shows NOTHING — not even a warning — for two very different
failures: the script CRASHING (a Python traceback, exit 1) and the schema
file being missing (this script's own exit 2, see "EXIT CODES" below). Both
of those look EXACTLY like a clean run, because `|| true` throws the real
exit code away and neither a traceback nor this script's own "file not
found" message contains a `•`. The fix `pr-security.yml` already uses
elsewhere is to keep the exit code AND not throw any of the output away: run
the check, capture its exit code directly (no `|| true`), and show the
step as failed (or at least add its own visible section) whenever that exit
code is anything other than the "clean, or findings without --strict" 0 —
never rely on `grep -q '•'` alone to decide whether something is wrong.

Unlike checks 9-22, which live inside `pr-security.yml`'s "Heuristic
anti-pattern scan" step and are skipped whenever a pull request changes no
PHP file under web/ (a gap tracked as #556, not fixed here), this check's
own step never looks at which files changed — it runs on every pull
request, including one that changes only a migration file and no PHP,
which is exactly where a bad link between tables is most often introduced.

FIX ROUND 1 (25 September 2026). This step used to rely on GitHub's default
condition, `success()`, which meant an EARLIER step failing — the PHP lint
hard gate, the checkout step, or the gitleaks SARIF upload — skipped this
one too, on the exact pull requests where a real finding would matter most:
the "skipped on exactly the pull requests it matters most for" mistake
described above, just reached a different way. Proved by simulation: with
the PHP lint step made to fail, this step never ran, its `hits` output was
never set, and the job summary then showed an EMPTY count next to the
words "never skipped" — a skip that read exactly like a clean run. Fixed
with `if: ${{ !cancelled() }}` on the step, so it now runs whatever
happened earlier, and is skipped only if the whole workflow run itself is
cancelled. The "Post findings to PR" step's own condition was tightened at
the same time, from `steps.check23.outputs.hits != '0'` to `== '1'` — the
exact test for what this step actually writes (always the literal string
`0` or `1` — `HITS=0` / `HITS=1` in that step of `pr-security.yml`), so an
output that was never set
can never again be misread as "has findings".

WHAT THIS CANNOT SEE
---------------------
* It reads SQL as text with regular expressions, not by asking a real MySQL
  server, and it is not a real SQL parser. See "The `CREATE TABLE IF NOT
  EXISTS` rule..." above for how it tracks whether a table already exists,
  and "WHAT CHANGED IN FIX ROUND 2" for how it reads a name-only guard
  turning a plain key unique. A guarded block whose branches genuinely
  disagree about a key's final shape, in an order this script's plain left-
  to-right reading of the file text would get wrong, is not something this
  script can notice. Every guarded block in `web/_sql` today was checked BY
  HAND, one at a time, against the ORDER its branches appear in the file,
  and none has that shape.
* Rule 2 models a database built by replaying every numbered migration, in
  order, starting from the keys `STARTING_SET` records. Most real databases
  were instead installed from some OLDER copy of `full_schema.sql` and then
  upgraded. This check has no record of what each older copy looked like,
  so it cannot reproduce that starting point.
  The two can differ, and one case already does. `tblEventCategories` was
  not in the install script at all from its first version (commit 8136a3b,
  18 February 2026) until commit 53bcf81 (8 March 2026). That commit added
  it with the two-column key `uq_cat_slug_site`. Migration 008 (7 March
  2026) creates the table with a one-column key, `uq_category_slug`.
  Migration 019 tries to remove that key under the wrong name, so it never
  goes (#553). The result:
  - a database installed from an install script older than commit
    53bcf81 CAN have `uq_category_slug`: it does when migration 008
    created its `tblEventCategories`. The install scripts from commit
    1016ccb (7 March 2026) until 53bcf81 mark 008 as already run without
    creating its tables, so a database installed from one of those has no
    `tblEventCategories` at all until someone repairs it by hand;
  - a database installed from commit 53bcf81 onwards does not have it;
  - Rule 2 believes every upgraded database has it.
  So Rule 2 passes a link that relies on `uq_category_slug`. That is wrong
  for every database installed from commit 53bcf81 onwards. The
  fresh-install rules catch such a link only when it also exists on a
  fresh install: Rule 1a when it is written in `full_schema.sql`, Rule 1b
  when a migration adds it on a fresh install. Such a link written only
  inside a migration's own `CREATE TABLE IF NOT EXISTS`, for a table
  `full_schema.sql` already declares, is read by Rule 2 alone, because
  that block does nothing on a fresh install. Nothing catches that case:
  not this check, and not the end-to-end test. Migration 009's
  `fk_att_sess_event` is a real link that exists only that way (#554); it
  points at a primary key, so it is harmless for this check.
  A second, harmless difference of the same kind: migration 047 creates two
  `tblTrustedDevices` keys that `full_schema.sql` names differently
  (`uq_token_hash` and `idx_user_active`, against `uq_td_token_hash` and
  `idx_td_user_active`). See `STARTING_SET`'s own entries and #553.
* Two further shapes cannot be seen, both proved on a real MySQL 8.4
  upgrade (#552) and deliberately left unfixed, because fixing them would
  mean modelling something new rather than writing down a real limit:
  - An already-RELEASED migration, edited after the fact. This script reads
    whatever text is in the file today; it has no way to know that a
    migration already recorded as run on a real database once said
    something different.
  - A key `full_schema.sql` declares UNIQUE that a later, guarded migration
    creates PLAIN under the same name — hidden by TWO things at once: the
    guard is only trusted the first time a name is used (a DIFFERENT,
    genuinely untrustworthy migration text later in the SAME walk would
    normally be caught), and an old, left-over key of that same name from
    an EARLIER migration can make Rule 2's own picture agree with
    `full_schema.sql` for the wrong reason, hiding the disagreement.
* It only understands `CREATE TABLE IF NOT EXISTS `` `table` `` ( ... )
  ENGINE=...;` blocks, with the closing `) ENGINE=` at the start of its own
  line (checked while writing this script: `grep -c '^) ENGINE='` returns
  223 — the true table count. `grep -c 'CREATE TABLE IF NOT EXISTS'` returns
  226, three MORE, because three comment lines happen to contain that exact
  phrase without a real table following it — `^) ENGINE=` is the one to
  trust) — a `CREATE TABLE` that omits `IF NOT EXISTS`, which nothing in this codebase
  writes today, would not be recognised as a table at all, and anything
  inside it would be misattributed to whichever `ALTER TABLE` happens to
  appear nearest to it earlier in the same file.
* It requires a NAME on a `UNIQUE KEY`/`UNIQUE INDEX` clause. MySQL also
  allows an entirely unnamed one (`UNIQUE (`` `col` ``)`) — nothing in this
  codebase writes that shape, and this script would not recognise it as a
  key at all.
* It does not model `DROP PRIMARY KEY` — nothing in this codebase drops a
  primary key once created, so there was nothing to prove this against.
* It cannot tell whether a table or column genuinely exists in a live
  database, only what the SQL files say should be there. A database that has
  drifted from what these files describe (a hand-edited server, for instance)
  is outside what this check can see at all.
* It does not run against MariaDB, and does not know whether MariaDB applies
  a rule equivalent to `restrict_fk_on_non_standard_key` — MariaDB
  compatibility is unverified everywhere in this codebase (see DEV_NOTES.md).

**What the end-to-end migration test will and will not add.** The owner
approved running this repository's end-to-end migration test (see
DEV_NOTES.md → "End-to-end migration test") on MySQL 8.4 as well as
8.0.36, on 24 September 2026. That run is not in place yet.

`tools/e2e-migrations/run.sh` builds every database from today's
`full_schema.sql`. Phase 1 loads it. Phases 2 and 3 carry on from phase
1's database. Phase 4 reloads it.

So, once it runs on 8.4, it will catch a fault that shows up on a
database built from today's files. Two examples:
- a link to a plain key inside a `CREATE TABLE` written without
  `IF NOT EXISTS`, which this script cannot read;
- a link that relies on `uq_category_slug`, when that link also exists
  on a database built from today's files (today's install script does
  not have that key).

It will NOT catch a fault that shows up only on a database built by an
older release:
- an edited, already-released migration;
- a guarded branch that runs only on an older database;
- a link written only inside a migration's `CREATE TABLE IF NOT EXISTS`
  for a table `full_schema.sql` already declares (that block does nothing
  on a database built from today's files);
- a key that today's install script has but older installs lack, which a
  name-only guard then creates plain on those older databases.
Proved on MySQL 8.4.11: the edited migration and the hidden guard gave 0
errors the test's way, and a real upgrade from alpha failed both with
ERROR 6125.

So today nothing automated tests an upgrade from an older release. An
"upgrade from the previous release" phase would test one older starting
point, the last release, not every older install. It is proposed,
awaiting the owner's decision. It is not planned and not under way.

EXIT CODES
----------
  0 — clean, or findings exist but `--strict` was not given.
  1 — at least one finding under any rule above, OR a problem with
      `STARTING_SET` itself, AND `--strict` was given.
  2 — the schema file named (by default, or by `--schema`) does not exist,
      cannot be read, or — the same as a migration file — contains a byte
      that is not valid UTF-8; either way the message names the one file at
      fault.

USAGE
-----
  python3 tools/audit-checks/check_fk_references_unique_key.py [--strict]
  python3 tools/audit-checks/check_fk_references_unique_key.py \\
      --schema /path/to/a/full_schema.sql \\
      [--migrations-dir /path/to/migrations] [--no-migrations] [--strict]

`--schema` points the check at a different file instead of this repository's
own `web/_sql/full_schema.sql` — used to re-run this same check against an
old or a deliberately-broken copy while proving the check actually works.
`--migrations-
dir` points it at a different migrations folder to go with that (default: the
schema file's own directory). `--no-migrations` runs Rule 1a alone, against
the schema file only, with Rule 1b, Rule 2, Rule 3 and the starting-set check
all skipped entirely — what a planted-fault proof for a shape confined to one
file wants: a clean, self-contained answer about ONE file, with nothing from
this repository's real migrations mixed in.
"""

from __future__ import annotations

import argparse
import re
import sys
from dataclasses import dataclass, field
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_SCHEMA = REPO_ROOT / "web" / "_sql" / "full_schema.sql"
DEFAULT_MIGRATIONS_DIR = REPO_ROOT / "web" / "_sql"

# -----------------------------------------------------------------------------
# Rule 2's starting set — a fixed, reasoned list, not a guess (#552). Each
# entry's SHAPE is recorded here too, not read from `full_schema.sql` at run
# time. The shapes were produced by this script's own parser; on
# 24 September 2026 they were confirmed by an independent reading (see the
# module docstring). "The starting set is now a fixed list, not a guess", in
# the module docstring above, explains why the LIST exists. Why the SHAPE is
# recorded as well: before this was added, `build_upgrade_baseline` read each
# entry's columns, uniqueness and primary-key status out of `full_schema.sql`
# itself, every run — so editing a LISTED key's shape in `full_schema.sql`
# alone, with no matching migration, was silently trusted as if an already-
# upgraded database had it that way too. It never does: an upgrade never
# re-runs `full_schema.sql`, so a key that predates the migrations keeps
# whatever shape it had when the numbered migrations began, however
# `full_schema.sql` is edited afterwards. Round 3's T8 (a listed plain key
# turned UNIQUE in `full_schema.sql` only) and S4 (a listed PRIMARY KEY
# widened in `full_schema.sql` only) both passed this check clean before this
# fix, and both were proved failing on a real MySQL 8.4 upgrade from alpha
# with ERROR 6125. `build_upgrade_baseline` now seeds Rule 2's walk from the
# shape recorded HERE; `check_starting_set_shapes` separately compares that
# recorded shape against `full_schema.sql`'s own, every run, and reports any
# difference rather than trusting it silently. Each row below is a
# `StartingSetEntry` — see its own docstring for what each field means.
# -----------------------------------------------------------------------------

_PREDATES_REASON = (
    "predates the numbered migrations — part of the schema as it stood before "
    "migration 000 even existed (migration 000 itself creates only "
    "`tblMigrations`, the tracking table the whole numbered system depends "
    "on). Checked by hand: no numbered migration 000-206 contains a CREATE "
    "TABLE for this table (grepped case-insensitively against all 204 files)."
)

_TRUSTED_DEVICES_REASON = (
    "NOT primordial — migration 047 creates the WHOLE `tblTrustedDevices` "
    "table, including a unique key on the same column and a plain key on the "
    "same columns as this one. But it names them differently "
    "(`uq_token_hash` / `idx_user_active`, not `uq_td_token_hash` / "
    "`idx_td_user_active`) — genuine, pre-existing drift between "
    "full_schema.sql and migration 047, unrelated to #552, found while "
    "classifying this list (fix round 2). Harmless for what this check "
    "protects: every rule here matches a foreign key's target by its COLUMN "
    "LIST, never by the key's own name, and nothing anywhere in this "
    "codebase has a foreign key pointing at `tblTrustedDevices` at all "
    "(checked: zero REFERENCES clauses name it). Listed here rather than "
    "left as an unexplained finding because an automatic rule cannot safely "
    "equate two differently-named keys on its own; #553 is open to fix "
    "migration 047/full_schema.sql's naming properly, rather than folding "
    "that fix into #552's own scope."
)

@dataclass(frozen=True)
class StartingSetEntry:
    """One Rule 2 starting-set entry — WHICH (table, key) pair predates the
    numbered migrations, WHY, and (fix round 3, #552, MEDIUM 1) its SHAPE as
    an already-upgraded database really has it. `table`/`name`/`reason` are
    unchanged from fix round 2 (`name` is None for the table's own PRIMARY
    KEY). `shape`, `cols` and `prefix_len` are new in round 3: `shape` is one
    of "PRIMARY" (this entry IS the table's PRIMARY KEY), "UNIQUE" (a named
    UNIQUE key) or "plain" (a named, ordinary, non-unique key); `cols` is the
    column list in order (order matters to MySQL 8.4's own rule); `prefix_len`
    records a MySQL prefix length such as the `(255)` in `` `endpoint`(255) ``
    — none of today's 32 entries has one. `check_starting_set_shapes` does
    NOT compare this length against `full_schema.sql`; it only compares
    WHETHER the key is a prefix key at all, a plain yes/no, the same way
    `IndexInfo` does for an ordinary key elsewhere in this script (see
    `IndexInfo`/`parse_col_list`) — safe, because a prefix key can never be
    what a foreign key points at, at any length, so the exact length makes
    no difference to what this check protects.
    Two different things read this recorded shape, for two different reasons:
    `build_upgrade_baseline` SEEDS Rule 2's walk with it (so the walk itself
    can never be fooled by a `full_schema.sql`-only edit of a listed key's
    shape), and `check_starting_set_shapes` COMPARES it against what
    `full_schema.sql` declares today (so such an edit is reported, not merely
    ignored)."""

    table: str
    name: str | None
    shape: str
    cols: tuple[str, ...]
    prefix_len: int | None
    reason: str


def _pk(table: str, cols: tuple[str, ...], reason: str) -> StartingSetEntry:
    """Build a STARTING_SET entry for a table's own PRIMARY KEY."""
    return StartingSetEntry(table=table, name=None, shape="PRIMARY", cols=cols, prefix_len=None, reason=reason)


def _idx(
    table: str,
    name: str,
    unique: bool,
    cols: tuple[str, ...],
    reason: str,
    prefix_len: int | None = None,
) -> StartingSetEntry:
    """Build a STARTING_SET entry for a named index (UNIQUE or plain)."""
    return StartingSetEntry(
        table=table, name=name, shape="UNIQUE" if unique else "plain", cols=cols, prefix_len=prefix_len, reason=reason
    )


# Every entry's `cols`/`unique`/`prefix_len` below was read out of today's
# full_schema.sql by this script's OWN parser (never re-typed by hand, which
# would risk introducing the same kind of silent drift this whole list
# exists to catch). On 24 September 2026 the shapes were separately
# confirmed — by an independent reading, not this parser — against 93
# commits that touch full_schema.sql (92 distinct file contents, including
# the very first one, commit 8136a3b, 18 February 2026, then at
# `sql/full_schema.sql` — the file has since moved twice, to
# `web/sql/full_schema.sql` and then today's `web/_sql/full_schema.sql`)
# and a real MySQL 8.4.11 database upgraded from alpha. The two
# `tblTrustedDevices` entries are the one expected difference: they are
# absent from the first 28 of those 93 versions, because migration 047
# (see `_TRUSTED_DEVICES_REASON`) had not been written yet — exactly what
# that entry's own reason already says.
STARTING_SET: tuple[StartingSetEntry, ...] = (
    _pk("tblActivityLogs", ("logID",), _PREDATES_REASON),
    _idx("tblActivityLogs", "claimID", False, ("claimID",), _PREDATES_REASON),
    _idx("tblActivityLogs", "userID", False, ("userID",), _PREDATES_REASON),
    _pk("tblDepts", ("deptID",), _PREDATES_REASON),
    _pk("tblExpenseClaimFiles", ("fileID",), _PREDATES_REASON),
    _idx("tblExpenseClaimFiles", "claimID", False, ("claimID",), _PREDATES_REASON),
    _pk("tblExpenseClaimItems", ("itemID",), _PREDATES_REASON),
    _idx("tblExpenseClaimItems", "claimID", False, ("claimID",), _PREDATES_REASON),
    _pk("tblExpenseClaims", ("claimID",), _PREDATES_REASON),
    _idx("tblExpenseClaims", "deptID", False, ("deptID",), _PREDATES_REASON),
    _idx("tblExpenseClaims", "userID", False, ("userID",), _PREDATES_REASON),
    _pk("tblGroups", ("groupID",), _PREDATES_REASON),
    _pk("tblLocalAccounts", ("localID",), _PREDATES_REASON),
    _idx("tblLocalAccounts", "userID", False, ("userID",), _PREDATES_REASON),
    _idx("tblLocalAccounts", "username", True, ("username",), _PREDATES_REASON),
    _pk("tblRoles", ("roleID",), _PREDATES_REASON),
    _pk("tblRoutes", ("routeID",), _PREDATES_REASON),
    _idx("tblRoutes", "uq_routeKey", True, ("routeKey",), _PREDATES_REASON),
    _pk("tblSettings", ("settingID",), _PREDATES_REASON),
    _idx("tblTrustedDevices", "uq_td_token_hash", True, ("tokenHash",), _TRUSTED_DEVICES_REASON),
    _idx(
        "tblTrustedDevices",
        "idx_td_user_active",
        False,
        ("userID", "revokedAt", "expiresAt"),
        _TRUSTED_DEVICES_REASON,
    ),
    _pk("tblUserDepts", ("userDeptID",), _PREDATES_REASON),
    _idx("tblUserDepts", "deptID", False, ("deptID",), _PREDATES_REASON),
    _idx("tblUserDepts", "userID", False, ("userID",), _PREDATES_REASON),
    _pk("tblUserGroups", ("userGroupID",), _PREDATES_REASON),
    _idx("tblUserGroups", "groupID", False, ("groupID",), _PREDATES_REASON),
    _idx("tblUserGroups", "userID", False, ("userID",), _PREDATES_REASON),
    _pk("tblUserRoles", ("userRoleID",), _PREDATES_REASON),
    _idx("tblUserRoles", "roleID", False, ("roleID",), _PREDATES_REASON),
    _idx("tblUserRoles", "userID", False, ("userID",), _PREDATES_REASON),
    _pk("tblUsers", ("userID",), _PREDATES_REASON),
    _idx("tblUsers", "emailAddress", True, ("emailAddress",), _PREDATES_REASON),
)

# -----------------------------------------------------------------------------
# Regular expressions. Every one is written to be told apart from its
# near-neighbours WITHOUT a real SQL parser — the comment on each explains how,
# and what it would take to fool it.
# -----------------------------------------------------------------------------

# A `CREATE TABLE IF NOT EXISTS` block. Every one of the 223 real tables in
# this project's full_schema.sql (checked while writing this script:
# `grep -c '^) ENGINE='` returns 223; `grep -c 'CREATE TABLE IF NOT EXISTS'`
# returns 226 — three MORE, because three comment lines happen to contain that
# exact phrase) closes with `) ENGINE=` at the very start of its own line, so
# that is a safe marker for "the CREATE TABLE body ends here" — safer than
# trying to balance parentheses by hand across VARCHAR(255)-style column
# definitions, which also contain `(` and `)`.
CREATE_TABLE_RE = re.compile(
    r"CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`(?P<table>[^`]+)`\s*\("
    r"(?P<body>.*?)"
    r"\n\)\s*ENGINE=",
    re.IGNORECASE | re.DOTALL,
)

# `PRIMARY KEY (`col1`, `col2`)` as its own clause.
PRIMARY_KEY_CLAUSE_RE = re.compile(r"PRIMARY\s+KEY\s*\(\s*(?P<cols>[^)]+?)\s*\)", re.IGNORECASE)

# An inline single-column PRIMARY KEY written on the column's OWN definition
# line, e.g. `` `roleID` INT NOT NULL AUTO_INCREMENT PRIMARY KEY `` — MySQL
# accepts this as an alternative to the separate `PRIMARY KEY (...)` clause
# above. The negative lookahead `(?!\s*\()` is what tells the two shapes
# apart: the separate-clause form always has an open parenthesis straight
# after the words "PRIMARY KEY"; the inline form never does. `[^,`]*?`
# (non-greedy, stops at the next comma or backtick) keeps the match inside
# ONE column's own definition, so it can never accidentally reach across a
# comma into a LATER, unrelated "PRIMARY KEY (...)" clause and misread that
# as this column's own inline marker.
INLINE_PRIMARY_KEY_RE = re.compile(
    r"`(?P<col>[^`]+)`\s+[^,`]*?\bPRIMARY\s+KEY\b(?!\s*\()",
    re.IGNORECASE,
)

# A column LIST inside a KEY/INDEX/CREATE INDEX clause — one or more
# comma-separated columns, each optionally carrying a MySQL prefix length
# (e.g. `` `endpoint`(255) ``) and/or a trailing ASC/DESC. Fix round 2's
# finding: the OLD pattern here was simply `[^)]+?`, which stops at the
# FIRST closing parenthesis — for a prefix key that is the one closing the
# PREFIX LENGTH, not the column list itself, so `` `endpoint`(255) `` was
# read as one column literally called `` endpoint`(255 ``, and the whole key
# was silently treated as non-prefix. `(?:[^()]|\(\s*\d+\s*\))+` matches any
# run of non-parenthesis characters OR one balanced `(NNN)` group, so the
# ONE level of nesting a prefix length can ever introduce is consumed as part
# of the column list, and the regex's own trailing `\)` still correctly
# matches the OUTER closing parenthesis that ends the whole list. See
# `parse_col_list` below for where the prefix length is then split back out
# from the column name, and where a trailing ASC/DESC is stripped.
_COLUMN_LIST = r"(?P<cols>(?:[^()]|\(\s*\d+\s*\))+)"

# A named KEY or INDEX clause — UNIQUE or plain; MySQL treats `KEY` and
# `INDEX` as exact synonyms everywhere, so both spellings are accepted here
# too. Requires a NAME between the keyword and the opening parenthesis, which
# is exactly what stops this from also matching `PRIMARY KEY (...)` (no name
# sits between "KEY" and "(" there) or `FOREIGN KEY (...)` (same reason) —
# both fail to match here because the name group demands at least one
# character that is not `(`, and the very next character after "KEY"/"INDEX"
# in either of those phrases IS `(`. Used for a clause inside a CREATE TABLE
# body; `ADD_KEY_RE` below is the same idea for `ALTER TABLE ... ADD ...`.
KEY_CLAUSE_RE = re.compile(
    r"(?P<unique>UNIQUE\s+)?(?:KEY|INDEX)\s+`?(?P<name>[^`\s(]+)`?\s*\(\s*" + _COLUMN_LIST + r"\s*\)",
    re.IGNORECASE,
)

# `ADD [UNIQUE] KEY|INDEX `name` (cols)` after ALTER TABLE. Same
# name-must-exist reasoning as KEY_CLAUSE_RE keeps this from ever matching
# `ADD CONSTRAINT `` `name` `` FOREIGN KEY (...)` — "CONSTRAINT" sits between
# "ADD" and "KEY"/"INDEX" there, so the literal adjacency this pattern
# requires never lines up.
ADD_KEY_RE = re.compile(
    r"ADD\s+(?P<unique>UNIQUE\s+)?(?:KEY|INDEX)\s+`?(?P<name>[^`\s(]+)`?\s*\(\s*" + _COLUMN_LIST + r"\s*\)",
    re.IGNORECASE,
)

# `ADD PRIMARY KEY (cols)`. Nothing in this codebase does this today (every
# table's PRIMARY KEY is set at CREATE TABLE time), but it costs nothing to
# read.
ADD_PRIMARY_KEY_RE = re.compile(r"ADD\s+PRIMARY\s+KEY\s*\(\s*(?P<cols>[^)]+?)\s*\)", re.IGNORECASE)

# A standalone `CREATE [UNIQUE] INDEX name ON `table` (cols)` statement — a
# second, less common way MySQL lets you add an index, outside ALTER TABLE
# and outside a CREATE TABLE body. Nothing in `web/_sql` writes this shape
# today, but MySQL 8.4 would accept it as a covering key exactly as readily
# as an inline `UNIQUE KEY`, so a check that could not see it would be worse
# than useless the day anyone does write one.
CREATE_INDEX_RE = re.compile(
    r"CREATE\s+(?P<unique>UNIQUE\s+)?INDEX\s+`?(?P<name>[^`\s(]+)`?\s+ON\s+`?(?P<table>[^`\s(]+)`?"
    r"\s*\(\s*" + _COLUMN_LIST + r"\s*\)",
    re.IGNORECASE,
)

# `DROP INDEX name` / `DROP KEY name`. Deliberately does NOT match
# `DROP FOREIGN KEY name` (dropping a constraint, not an index) — the literal
# `INDEX`/`KEY` keyword this pattern demands right after "DROP" never lines
# up with "FOREIGN" sitting there instead.
DROP_INDEX_RE = re.compile(r"DROP\s+(?:INDEX|KEY)\s+`?(?P<name>[^`\s(,;]+)`?", re.IGNORECASE)

# A foreign key, named or not, with or without backticks around the table it
# references. MySQL allows both: `CONSTRAINT name FOREIGN KEY (...)` names
# the link; a bare `FOREIGN KEY (...)` (no CONSTRAINT clause at all) is just
# as valid, and MySQL invents its own name for it. Likewise `REFERENCES
# table (...)` needs no backticks — they are only ever a readability
# convention in this codebase's own house style, never a requirement MySQL
# itself enforces. `name`/`reftable` are therefore both optional-backtick
# groups, and the whole `CONSTRAINT ... ` prefix is optional too.
FK_RE = re.compile(
    r"(?:CONSTRAINT\s+`?(?P<name>[^`\s]+)`?\s+)?"
    r"FOREIGN\s+KEY\s*\(\s*(?P<cols>[^)]+?)\s*\)"
    r"\s+REFERENCES\s+`?(?P<reftable>[^`\s(]+)`?\s*\(\s*(?P<refcols>[^)]+?)\s*\)",
    re.IGNORECASE | re.DOTALL,
)

# `ALTER TABLE `table`` — used only to work out WHICH table an `ADD ...` /
# `DROP INDEX...` / bare `FOREIGN KEY` belongs to, by taking the nearest one
# that appears earlier in the same file. That is a heuristic, not a parser.
# Fix round 2: the table name's backticks are now OPTIONAL — `ALTER TABLE
# tblQ ADD KEY ...` (no backticks at all) used to be invisible to this
# pattern entirely, so whatever it declared was silently credited to
# whichever OTHER, backticked `ALTER TABLE` happened to appear earlier in
# the same file instead. Nothing in `web/_sql` writes an un-backticked
# `ALTER TABLE` today (checked while making this fix), but this script would
# have misattributed it silently if something did.
ALTER_TABLE_RE = re.compile(r"ALTER\s+TABLE\s+`?(?P<table>[A-Za-z_][A-Za-z0-9_]*)`?", re.IGNORECASE)


def strip_comments_preserving_lines(sql: str) -> str:
    """Remove SQL line (`--`) and block (`/* */`) comments before scanning,
    while keeping every newline so reported line numbers still point at the
    right place."""

    def _block_repl(m: re.Match[str]) -> str:
        return "\n" * m.group(0).count("\n")

    sql = re.sub(r"/\*.*?\*/", _block_repl, sql, flags=re.DOTALL)
    sql = re.sub(r"--[^\n]*", "", sql)
    return sql


def line_of(text: str, pos: int) -> int:
    return text.count("\n", 0, pos) + 1


def _rel(path: Path) -> str:
    try:
        return str(path.relative_to(REPO_ROOT))
    except ValueError:
        return str(path)  # a scratch file outside the repo — report its real path instead.


def parse_col_list(raw: str) -> tuple[tuple[str, ...], bool]:
    """Turn a raw column list like "`roleID`, `siteID`" — or, for a PREFIX
    index, "`endpoint`(255)" — into (("roleID","siteID"), False) or
    (("endpoint",), True). A trailing `ASC`/`DESC` on any column (MySQL
    allows this on an index column since 8.0) is read and discarded — it
    changes the index's scan direction, never which columns it covers, so it
    plays no part in whether a foreign key is covered. MySQL 8.4's
    restrict_fk_on_non_standard_key rule never accepts a prefix index as a
    foreign key's target (a prefix index only ever covers PART of a column's
    value, so it cannot enforce the exact equality a foreign key needs) — the
    `True` this returns is what tells the caller never to credit that key
    with covering anything, however many columns it lists. Order is
    preserved and matters: MySQL 8.4 cares about column ORDER, not just which
    columns are present."""
    cols: list[str] = []
    prefixed = False
    for part in raw.split(","):
        part = part.strip()
        if part == "":
            continue
        # A column is read ONE of two ways, never a mix of both: a
        # backticked name (`` `col` ``) is everything between the two
        # backticks, whitespace included — MySQL itself allows a space
        # inside a backticked identifier — read as one unit; an UN-quoted
        # name has no closing marker, so it stops at the first whitespace,
        # comma, backtick or parenthesis instead. Either way, an optional
        # `(NNN)` prefix length and a trailing ASC/DESC are then read and
        # dropped. Two fixes were made, on 24 and 25 September 2026. Both
        # were proved on planted SQL that MySQL genuinely accepts. The
        # first fixed the UN-quoted case: `a ASC`, with no backticks, used
        # to be read as one column literally named "a ASC". That fix made
        # the name group exclude whitespace. It then broke the BACKTICKED
        # case: `` `my col` ASC `` no longer matched at all, so it fell
        # through to the "cannot parse cleanly" branch below. That branch
        # kept the ASC inside the name, giving a column called
        # "my col` ASC". Reading the two shapes with a proper
        # either/or, instead of one shared pattern with optional backticks
        # on each end, fixes both at once without reintroducing the first.
        m = re.match(
            r"^(?:`(?P<q>[^`]+)`|(?P<name>[^`(),\s]+))"
            r"\s*(?:\(\s*(?P<len>\d+)\s*\))?\s*(?:ASC|DESC)?\s*$",
            part,
            re.IGNORECASE,
        )
        if m is not None:
            col_name = m.group("q") if m.group("q") is not None else m.group("name")
            cols.append(col_name.strip())
            if m.group("len") is not None:
                prefixed = True
        else:
            # Anything this cannot parse cleanly — strip backticks and keep
            # going rather than lose the whole key; safe because a name that
            # fails to line up this way simply never equals any foreign
            # key's own column tuple, so nothing is wrongly credited.
            cols.append(part.strip("`").strip())
    return tuple(cols), prefixed


def split_cols(raw: str) -> tuple[str, ...]:
    """The plain form used for a FOREIGN KEY's own column lists — these
    never carry a MySQL prefix length (a foreign key cannot be defined on a
    column prefix), so there is nothing to detect here."""
    cols, _unused = parse_col_list(raw)
    return cols


@dataclass
class IndexInfo:
    """One named index this script has read: its columns in order, whether
    it is UNIQUE, and whether it is a prefix index (which can never satisfy
    MySQL 8.4's rule, unique or not — see `parse_col_list`)."""

    cols: tuple[str, ...]
    unique: bool
    prefixed: bool


@dataclass
class TableKeys:
    """Everything this script currently believes about one table's keys, at
    one specific point in ONE of the walks below (Rule 1b's fresh-install
    replay, or Rule 2's upgrade order) — or, for Rule 1a, simply what
    `full_schema.sql` alone declares. The different pictures are deliberately
    never pooled into one shared one — see "WHAT CHANGED IN FIX ROUND 1" in
    the module docstring for why that used to be the fault this script
    existed to catch."""

    primary_key: tuple[str, ...] | None = None
    indexes: dict[str, IndexInfo] = field(default_factory=dict)

    def clone(self) -> "TableKeys":
        return TableKeys(
            primary_key=self.primary_key,
            indexes={n: IndexInfo(i.cols, i.unique, i.prefixed) for n, i in self.indexes.items()},
        )

    def has_key_on(self, cols: tuple[str, ...]) -> bool:
        """True if `cols`, in this exact order, is this table's PRIMARY KEY
        or a full-length (non-prefix) UNIQUE key — the same test MySQL
        8.4's restrict_fk_on_non_standard_key rule applies."""
        if self.primary_key is not None and self.primary_key == cols:
            return True
        for info in self.indexes.values():
            if info.unique and info.prefixed is False and info.cols == cols:
                return True
        return False


@dataclass(frozen=True)
class FkLink:
    """One foreign key read from full_schema.sql itself — used only for
    Rule 1a, which never needs to know WHERE in the file it sat relative to
    other DDL, only which table and columns it points at."""

    name: str
    source_table: str
    source_cols: tuple[str, ...]
    ref_table: str
    ref_cols: tuple[str, ...]
    source_file: str
    line: int


@dataclass
class Event:
    """One DDL action, in the order it appears in a migration file — the
    smallest unit the walks below advance by. `kind` is one of: "key" (a
    named index, UNIQUE or not, coming into being or being replaced), "drop"
    (a named index disappearing), "pk" (the table's PRIMARY KEY becoming
    known) or "fk" (a foreign key being added — the exact moment this script
    checks coverage, not a separate later pass). `from_create_table` is True
    only for a "pk"/"key"/"fk" event that comes from INSIDE a `CREATE TABLE
    IF NOT EXISTS` block's own body — see "The `CREATE TABLE IF NOT EXISTS`
    rule..." in the module docstring: this is what lets both walks correctly
    skip a redeclared table's own events while still applying an `ALTER
    TABLE` or standalone `DROP INDEX`/`CREATE INDEX` for that same table
    normally, because those are separate SQL statements a redeclaration never
    touches."""

    kind: str
    pos: int
    table: str
    file: str
    line: int
    name: str | None = None
    cols: tuple[str, ...] | None = None
    unique: bool = False
    prefixed: bool = False
    fk_name: str | None = None
    ref_table: str | None = None
    ref_cols: tuple[str, ...] | None = None
    from_create_table: bool = False


def extract_and_blank_create_tables(text: str) -> tuple[list[tuple[str, str, int, int]], str]:
    """Every (table, body, start_line, start_pos) CREATE TABLE block in
    `text`, AND a copy of `text` with each block's own span replaced by
    matching-length whitespace (newlines kept, so every later line number
    stays correct). The blanked copy is what every OTHER regex in this
    script scans, so that a column definition or a comment INSIDE some
    table's body can never be misread as belonging to a different, unrelated
    `ALTER TABLE` statement that happens to appear earlier in the same
    file."""
    blocks: list[tuple[str, str, int, int]] = []
    blanked = text
    for m in CREATE_TABLE_RE.finditer(text):
        blocks.append((m.group("table"), m.group("body"), line_of(text, m.start()), m.start()))
        span = m.span()
        blanked = blanked[: span[0]] + re.sub(r"[^\n]", " ", blanked[span[0] : span[1]]) + blanked[span[1] :]
    return blocks, blanked


def parse_table_block(
    table: str, body: str, source_file: str, start_line: int
) -> tuple[tuple[str, ...] | None, dict[str, IndexInfo], list[FkLink]]:
    """Read one CREATE TABLE body's own PRIMARY KEY (clause or inline),
    every named UNIQUE/plain KEY|INDEX clause, and every foreign key it
    declares."""
    pk: tuple[str, ...] | None = None
    clause = PRIMARY_KEY_CLAUSE_RE.search(body)
    if clause is not None:
        pk, _unused = parse_col_list(clause.group("cols"))
    else:
        inline = INLINE_PRIMARY_KEY_RE.search(body)
        if inline is not None:
            pk = (inline.group("col").strip(),)

    indexes: dict[str, IndexInfo] = {}
    for m in KEY_CLAUSE_RE.finditer(body):
        cols, prefixed = parse_col_list(m.group("cols"))
        indexes[m.group("name")] = IndexInfo(cols=cols, unique=bool(m.group("unique")), prefixed=prefixed)

    fks: list[FkLink] = []
    for m in FK_RE.finditer(body):
        fks.append(
            FkLink(
                name=m.group("name") or "(unnamed)",
                source_table=table,
                source_cols=split_cols(m.group("cols")),
                ref_table=m.group("reftable"),
                ref_cols=split_cols(m.group("refcols")),
                source_file=source_file,
                line=start_line + body.count("\n", 0, m.start()),
            )
        )
    return pk, indexes, fks


def read_schema_file(path: Path) -> tuple[dict[str, TableKeys], list[FkLink]]:
    """Parse one full_schema.sql-shaped file: every CREATE TABLE's PRIMARY
    and UNIQUE/plain keys (including a standalone CREATE INDEX statement, if
    one exists), and every foreign key declared inside one. This is Rule
    1a's ENTIRE picture, and also Rule 1b's STARTING picture — nothing from
    any migration is folded in here; Rule 1b folds migrations in afterwards,
    on top of a clone of what this function returns."""
    raw = path.read_text(encoding="utf-8")
    text = strip_comments_preserving_lines(raw)
    rel = _rel(path)
    tables: dict[str, TableKeys] = {}
    fks: list[FkLink] = []

    blocks, blanked = extract_and_blank_create_tables(text)
    for table, body, start_line, _pos in blocks:
        pk, indexes, block_fks = parse_table_block(table, body, rel, start_line)
        entry = tables.setdefault(table, TableKeys())
        if entry.primary_key is None:
            entry.primary_key = pk
        entry.indexes.update(indexes)
        fks.extend(block_fks)

    for m in CREATE_INDEX_RE.finditer(blanked):
        cols, prefixed = parse_col_list(m.group("cols"))
        entry = tables.setdefault(m.group("table"), TableKeys())
        entry.indexes[m.group("name")] = IndexInfo(cols=cols, unique=bool(m.group("unique")), prefixed=prefixed)

    return tables, fks


def parse_migration_file(path: Path) -> list[Event]:
    """Turn one numbered migration into an ORDERED list of Events — every
    key-shaping and foreign-key-adding action it contains, in the exact
    order the file itself lists them (this is what makes both walks below
    possible at all)."""
    raw = path.read_text(encoding="utf-8")
    text = strip_comments_preserving_lines(raw)
    rel = _rel(path)
    events: list[Event] = []

    blocks, blanked = extract_and_blank_create_tables(text)
    for table, body, start_line, pos in blocks:
        pk, indexes, block_fks = parse_table_block(table, body, rel, start_line)
        if pk is not None:
            events.append(Event(kind="pk", pos=pos, table=table, file=rel, line=start_line, cols=pk, from_create_table=True))
        for name, info in indexes.items():
            events.append(
                Event(
                    kind="key", pos=pos, table=table, file=rel, line=start_line,
                    name=name, cols=info.cols, unique=info.unique, prefixed=info.prefixed,
                    from_create_table=True,
                )
            )
        for fk in block_fks:
            events.append(
                Event(
                    kind="fk", pos=pos, table=table, file=rel, line=fk.line,
                    fk_name=fk.name, cols=fk.source_cols, ref_table=fk.ref_table, ref_cols=fk.ref_cols,
                    from_create_table=True,
                )
            )

    alter_positions = [(m.start(), m.group("table")) for m in ALTER_TABLE_RE.finditer(blanked)]

    def table_for(text_pos: int) -> str | None:
        owner: str | None = None
        for apos, atable in alter_positions:
            if apos <= text_pos:
                owner = atable
            else:
                break
        return owner

    for m in ADD_KEY_RE.finditer(blanked):
        owner = table_for(m.start())
        if owner is None:
            continue  # an ADD KEY with no preceding ALTER TABLE never happens in this codebase; skip rather than guess.
        cols, prefixed = parse_col_list(m.group("cols"))
        events.append(
            Event(
                kind="key", pos=m.start(), table=owner, file=rel, line=line_of(text, m.start()),
                name=m.group("name"), cols=cols, unique=bool(m.group("unique")), prefixed=prefixed,
            )
        )

    for m in ADD_PRIMARY_KEY_RE.finditer(blanked):
        owner = table_for(m.start())
        if owner is None:
            continue
        cols, _unused = parse_col_list(m.group("cols"))
        events.append(Event(kind="pk", pos=m.start(), table=owner, file=rel, line=line_of(text, m.start()), cols=cols))

    for m in CREATE_INDEX_RE.finditer(blanked):
        cols, prefixed = parse_col_list(m.group("cols"))
        events.append(
            Event(
                kind="key", pos=m.start(), table=m.group("table"), file=rel, line=line_of(text, m.start()),
                name=m.group("name"), cols=cols, unique=bool(m.group("unique")), prefixed=prefixed,
            )
        )

    for m in DROP_INDEX_RE.finditer(blanked):
        owner = table_for(m.start())
        if owner is None:
            continue  # a DROP INDEX with no preceding ALTER TABLE never happens in this codebase; skip rather than guess.
        events.append(
            Event(kind="drop", pos=m.start(), table=owner, file=rel, line=line_of(text, m.start()), name=m.group("name"))
        )

    for m in FK_RE.finditer(blanked):
        owner = table_for(m.start())
        if owner is None:
            continue  # a FOREIGN KEY with no ALTER TABLE and no CREATE TABLE around it never happens in this codebase; skip rather than guess.
        events.append(
            Event(
                kind="fk", pos=m.start(), table=owner, file=rel, line=line_of(text, m.start()),
                fk_name=m.group("name") or "(unnamed)",
                cols=split_cols(m.group("cols")), ref_table=m.group("reftable"), ref_cols=split_cols(m.group("refcols")),
            )
        )

    events.sort(key=lambda e: e.pos)
    return events


def collect_created_names(all_events: list[Event]) -> tuple[set[tuple[str, str]], set[str]]:
    """Every (table, index name) any migration's text ever creates — an ADD
    or a CREATE, regardless of order — and every table any migration ever
    sets a PRIMARY KEY on. Used to check `STARTING_SET` against reality (a
    listed entry that a migration has since started creating under that
    exact name is now stale) and for Rule 3 (which (table,name) pairs BOTH
    full_schema.sql and the migrations create at all). Order does not matter
    for THIS pass — only the walks themselves care about order."""
    names: set[tuple[str, str]] = set()
    pk_tables: set[str] = set()
    for e in all_events:
        if e.kind == "key" and e.name is not None:
            names.add((e.table, e.name))
        elif e.kind == "pk":
            pk_tables.add(e.table)
    return names, pk_tables


def build_upgrade_baseline(
    schema_tables: dict[str, TableKeys], created_names: set[tuple[str, str]], created_pk_tables: set[str]
) -> tuple[dict[str, TableKeys], set[str], list[str]]:
    """Rule 2's starting point. Seeded from each `STARTING_SET` entry's own
    RECORDED shape (fix round 3, #552, MEDIUM 1) — never read from
    `full_schema.sql` at run time, because a database that has genuinely been
    upgraded keeps whatever shape a predates-the-migrations key had when the
    numbered migrations began, however `full_schema.sql` is edited
    afterwards (round 3's T8 and S4 — see `StartingSetEntry`'s own docstring,
    and "MEDIUM 1" in the module docstring). Still checks each entry's table
    and key NAME against `full_schema.sql` — a renamed or removed key means
    the LIST has gone stale, which this function still has to catch, because
    it is a different kind of problem from a SHAPE mismatch on a key that is
    still there under the same name (see `check_starting_set_shapes` for
    that one, run separately). Returns (the starting keys, the set of tables
    known to exist from the very start, any problems found with the list
    itself — e.g. an entry naming a table or key `full_schema.sql` no longer
    declares, or one a migration has since started creating for real)."""
    baseline: dict[str, TableKeys] = {}
    known_tables: set[str] = set()
    problems: list[str] = []
    for entry in STARTING_SET:
        if entry.table not in schema_tables:
            what = "PRIMARY KEY" if entry.shape == "PRIMARY" else f"`{entry.name}`"
            problems.append(
                f"STARTING_SET names `{entry.table}` ({what}), but full_schema.sql no longer declares "
                "that table at all — the list is out of date; fix STARTING_SET by hand before trusting "
                "the rest of this check's output."
            )
            continue
        table_keys = baseline.setdefault(entry.table, TableKeys())
        if entry.shape == "PRIMARY":
            if entry.table in created_pk_tables:
                problems.append(
                    f"STARTING_SET says `{entry.table}`'s PRIMARY KEY predates the migration history, but "
                    "a numbered migration now sets it — the list is out of date; remove this entry."
                )
                continue
            if schema_tables[entry.table].primary_key is None:
                problems.append(
                    f"STARTING_SET names `{entry.table}`'s PRIMARY KEY, but full_schema.sql does not "
                    "declare one for that table any more — the list is out of date."
                )
                continue
            table_keys.primary_key = entry.cols  # the RECORDED shape — see MEDIUM 1 above, never full_schema.sql's own.
            known_tables.add(entry.table)
        else:
            assert entry.name is not None
            if (entry.table, entry.name) in created_names:
                problems.append(
                    f"STARTING_SET says `{entry.table}`.`{entry.name}` predates the migration history, but "
                    "a numbered migration now creates a key of that exact name — the list is out of date; "
                    "remove this entry."
                )
                continue
            if entry.name not in schema_tables[entry.table].indexes:
                problems.append(
                    f"STARTING_SET names `{entry.table}`.`{entry.name}`, but full_schema.sql does not "
                    "declare a key of that name for that table any more — the list is out of date."
                )
                continue
            table_keys.indexes[entry.name] = IndexInfo(
                cols=entry.cols, unique=(entry.shape == "UNIQUE"), prefixed=entry.prefix_len is not None
            )  # the RECORDED shape — see MEDIUM 1 above, never full_schema.sql's own.
    return baseline, known_tables, problems


def _describe_recorded_shape(entry: StartingSetEntry) -> str:
    """A short, human-readable description of one STARTING_SET entry's
    RECORDED shape — used only in `check_starting_set_shapes`'s finding
    text, so a reader can see at a glance what changed."""
    cols_str = ", ".join(entry.cols)
    if entry.shape == "PRIMARY":
        return f"PRIMARY KEY ({cols_str})"
    prefix_note = " (a prefix key)" if entry.prefix_len is not None else ""
    return f"{entry.shape} KEY `{entry.name}` ({cols_str}){prefix_note}"


def _describe_live_pk(cols: tuple[str, ...]) -> str:
    return f"PRIMARY KEY ({', '.join(cols)})"


def _describe_live_index(name: str, info: IndexInfo) -> str:
    shape = "UNIQUE" if info.unique else "plain"
    prefix_note = " (a prefix key)" if info.prefixed else ""
    return f"{shape} KEY `{name}` ({', '.join(info.cols)}){prefix_note}"


def check_starting_set_shapes(schema_tables: dict[str, TableKeys]) -> list[str]:
    """MEDIUM 1, fix round 3 (#552). Compares each `STARTING_SET` entry's
    RECORDED shape (see `StartingSetEntry`) against what `full_schema.sql`
    declares for that exact (table, name) TODAY. `build_upgrade_baseline`
    already stops the walk itself trusting a drifted shape; this is the
    SEPARATE, explicit check that reports the drift itself as a finding — on
    its own, even before any migration ever links to the key in question, so
    the problem is caught the moment it is introduced rather than only once
    something else happens to depend on it. A predates-the-migrations key
    can only ever reach a real, already-upgraded database in the shape it
    had when the numbered migrations began; if `full_schema.sql` says
    something different today, that difference was made in the wrong place
    — it belongs in a migration, so it can reach an existing database too.
    Round 3's T8 (a listed plain key turned UNIQUE in `full_schema.sql`
    only) and S4 (a listed PRIMARY KEY widened in `full_schema.sql` only)
    are exactly this shape, both proved failing on a real MySQL 8.4 upgrade
    from alpha with ERROR 6125. Silently skips an entry whose table or key
    name `full_schema.sql` no longer declares at all — `build_upgrade_
    baseline` already reports that, as a DIFFERENT kind of problem (the list
    itself has gone stale), and there is nothing left here to compare a
    shape against."""
    findings: list[str] = []
    for entry in STARTING_SET:
        table_keys = schema_tables.get(entry.table)
        if table_keys is None:
            continue  # reported by build_upgrade_baseline instead — nothing to compare a shape against.
        if entry.shape == "PRIMARY":
            live_cols = table_keys.primary_key
            if live_cols is None:
                continue  # reported by build_upgrade_baseline instead.
            if live_cols != entry.cols:
                findings.append(
                    f"`{entry.table}`'s PRIMARY KEY is recorded in STARTING_SET as "
                    f"{_describe_recorded_shape(entry)}, but full_schema.sql now declares it as "
                    f"{_describe_live_pk(live_cols)} — `full_schema.sql` changes a key that predates the "
                    "migrations; an upgraded database keeps the original shape — make the change in a "
                    "migration too, or, if a migration made this change too, remove this entry, as the "
                    "list check says."
                )
        else:
            assert entry.name is not None
            info = table_keys.indexes.get(entry.name)
            if info is None:
                continue  # reported by build_upgrade_baseline instead.
            live_shape = "UNIQUE" if info.unique else "plain"
            if (live_shape, info.cols, info.prefixed) != (entry.shape, entry.cols, entry.prefix_len is not None):
                findings.append(
                    f"`{entry.table}`.`{entry.name}` is recorded in STARTING_SET as "
                    f"{_describe_recorded_shape(entry)}, but full_schema.sql now declares it as "
                    f"{_describe_live_index(entry.name, info)} — `full_schema.sql` changes a key that "
                    "predates the migrations; an upgraded database keeps the original shape — make the "
                    "change in a migration too, or, if a migration made this change too, remove this "
                    "entry, as the list check says."
                )
    return findings


def explain_unaccounted_keys(
    schema_tables: dict[str, TableKeys], created_names: set[tuple[str, str]], created_pk_tables: set[str]
) -> list[str]:
    """Every key full_schema.sql declares must be explained: either a
    numbered migration creates it under that exact name, or it is on
    `STARTING_SET` with a reason. Anything else is a finding — this is what
    catches round 2's T5 (a key added to full_schema.sql and simply
    forgotten in its migration): before fix round 2, nothing created it, so
    the OLD automatic guess silently assumed it always existed; now, nothing
    on this list explains it either, so it is reported instead."""
    starting_pk_tables = {e.table for e in STARTING_SET if e.shape == "PRIMARY"}
    starting_index_pairs = {(e.table, e.name) for e in STARTING_SET if e.shape != "PRIMARY"}
    findings: list[str] = []
    for table, keys in sorted(schema_tables.items()):
        if keys.primary_key is not None and table not in created_pk_tables and table not in starting_pk_tables:
            findings.append(
                f"`{table}`'s PRIMARY KEY is declared in full_schema.sql, but no numbered migration ever "
                "sets it and it is not on STARTING_SET — add it to a migration, or, if it truly predates "
                "the migrations, to STARTING_SET with the reason."
            )
        for name in sorted(keys.indexes):
            if (table, name) in created_names or (table, name) in starting_index_pairs:
                continue
            findings.append(
                f"`{table}`.`{name}` is declared in full_schema.sql, but no numbered migration ever "
                "creates it and it is not on STARTING_SET — an upgraded database will not have this key; "
                "add it to a migration, or, if it truly predates the migrations, to STARTING_SET with the "
                "reason."
            )
    return findings


def apply_key_event(live: dict[str, TableKeys], e: Event) -> None:
    """Apply one "key" event to the running `live` picture — UNLESS the
    table already has a DIFFERENTLY-SHAPED key of that exact name (not the
    same columns, uniqueness or prefix-ness), with nothing having dropped it
    first in this walk. Fix round 2's finding T7: this codebase's usual
    name-only guard (`IF` no index of this name exists, do something; `ELSE`
    do nothing — the DEV_NOTES.md template) checks ONLY whether a key of
    that NAME exists, in ANY shape. It does NOTHING when one already does,
    regardless of what shape the "do something" branch's text would have
    created — so that text is never trustworthy evidence the key actually
    takes on this shape here. This has to be a SYMMETRIC rule, not merely
    "distrust an ADD UNIQUE over an existing plain key". Distrusting only
    that one direction would WRONGLY FLAG a different, real case: a plain
    `ADD KEY` that Rule 1b's own starting point (`full_schema.sql`)
    already declares unique. Believed on its own, that plain `ADD KEY`
    would wrongly downgrade the key, and the later foreign-key check would
    then wrongly report a real, still-unique key as uncovered. A real
    fresh install's name-only guard protects against that too, in exactly
    the same way.

    This script cannot evaluate a guard's real IF-condition, but it CAN
    tell whether the walk has already seen this exact key genuinely removed
    first (a `DROP INDEX`/`DROP KEY` event for that name, anywhere earlier
    in the SAME walk — including inside the SAME guarded statement, which is
    exactly how fixed migrations 202/203's own three-branch guard proves
    itself safe: by the time either of THEIR "DROP then ADD UNIQUE" branches'
    text is reached, the FIRST branch's plain "ADD UNIQUE" text has usually
    already run in this script's own left-to-right reading, so there is
    nothing left of that name for this check to distrust).

    No separate "the guard cannot be trusted" finding is printed here —
    deliberately. Skipping the overwrite leaves the table's OLD, real shape
    in place, and the ORDINARY foreign-key-coverage check
    (`_check_fk_coverage`) then tests any later foreign key against THAT
    real shape, exactly as a real database would — which is what actually
    catches a genuine problem (round 2's T7 is caught this way: the key
    this event claims to make unique stays plain, and the foreign key added
    moments later in the same migration is then correctly found
    uncovered). When nothing ever relies on the key this event's text
    could not be trusted about, there is nothing here worth a warning
    either — see check_rule3 for what still catches a mismatch even then,
    where full_schema.sql and the migrations both declare the same name."""
    entry = live.setdefault(e.table, TableKeys())
    if e.kind != "key":
        raise AssertionError(f"apply_key_event called on a non-key event: {e.kind}")
    assert e.name is not None and e.cols is not None
    existing = entry.indexes.get(e.name)
    if existing is not None and (existing.unique != e.unique or existing.cols != e.cols or existing.prefixed != e.prefixed):
        return  # a name-only guard would do nothing here — the OLD key's real shape stays exactly as it was.
    entry.indexes[e.name] = IndexInfo(cols=e.cols, unique=e.unique, prefixed=e.prefixed)


def run_walk(
    seed_tables: dict[str, TableKeys],
    seed_known_tables: set[str],
    files_events: list[tuple[str, list[Event]]],
) -> tuple[dict[str, TableKeys], list[tuple[Event, str]], int]:
    """Replay every migration's key-and-link events, in the order the file
    lists them (and files in numeric order), against a running per-table
    picture that starts at `seed_tables` — used for BOTH Rule 1b (seeded
    from full_schema.sql's own complete picture, with `seed_known_tables`
    naming every table it declares — a fresh install creates all of them
    before any migration replays) and Rule 2 (seeded from
    `build_upgrade_baseline`'s recorded starting set, with
    `seed_known_tables` naming only those tables). See "The `CREATE TABLE IF
    NOT EXISTS` rule..." in the module docstring: a whole `CREATE TABLE IF
    NOT EXISTS` block's own pk/key/inline-fk events are skipped together,
    as a group, the moment its table is already known to exist — everything
    else (an `ALTER TABLE`, a standalone `DROP INDEX`/`CREATE INDEX`, an
    `ADD CONSTRAINT ... FOREIGN KEY` outside any CREATE TABLE body) is a
    separate SQL statement and always still applies. Every "fk" event is
    checked at the EXACT moment it is reached — never against a picture
    built from later statements."""
    live: dict[str, TableKeys] = {t: k.clone() for t, k in seed_tables.items()}
    known: set[str] = set(seed_known_tables)
    findings: list[tuple[Event, str]] = []
    fk_count = 0

    flat: list[tuple[str, Event]] = [(f, e) for f, evs in files_events for e in evs]
    n = len(flat)
    i = 0
    while i < n:
        cur_file, cur_e = flat[i]
        if cur_e.from_create_table:
            # Gather the WHOLE CREATE TABLE block's own events — they all
            # share the same file and the same starting position, because
            # `parse_migration_file` builds them all from one `blocks` entry.
            group: list[Event] = []
            j = i
            while (
                j < n
                and flat[j][0] == cur_file
                and flat[j][1].from_create_table
                and flat[j][1].table == cur_e.table
                and flat[j][1].pos == cur_e.pos
            ):
                group.append(flat[j][1])
                j += 1
            if cur_e.table not in known:
                # A genuine first creation — real on a real database. Apply
                # every pk/key event FIRST, so a self-referencing foreign
                # key inside the SAME block (checked next) sees the table's
                # own, now-complete, set of keys — exactly how MySQL builds
                # one CREATE TABLE statement's constraints together.
                known.add(cur_e.table)
                for ge in group:
                    if ge.kind == "pk":
                        live.setdefault(ge.table, TableKeys()).primary_key = ge.cols
                    elif ge.kind == "key":
                        apply_key_event(live, ge)
                for ge in group:
                    if ge.kind == "fk":
                        fk_count += 1
                        _check_fk_coverage(live, ge, findings)
            # else: the table already exists — `CREATE TABLE IF NOT EXISTS`
            # does nothing at all on a real database, so this whole block's
            # own events (including any inline foreign key) are skipped, not
            # counted, and not applied. This is what fix round 2's finding
            # T6 needs: a redeclared table's own events must never be
            # credited.
            i = j
        else:
            if cur_e.kind == "fk":
                fk_count += 1
                _check_fk_coverage(live, cur_e, findings)
            elif cur_e.kind == "key":
                apply_key_event(live, cur_e)
            elif cur_e.kind == "drop":
                assert cur_e.name is not None
                live.setdefault(cur_e.table, TableKeys()).indexes.pop(cur_e.name, None)
            elif cur_e.kind == "pk":
                live.setdefault(cur_e.table, TableKeys()).primary_key = cur_e.cols
            i += 1
    return live, findings, fk_count


def _check_fk_coverage(live: dict[str, TableKeys], e: Event, findings: list[tuple[Event, str]]) -> None:
    assert e.ref_table is not None and e.ref_cols is not None
    ref = live.get(e.ref_table)
    if ref is None:
        findings.append(
            (
                e,
                f"references `{e.ref_table}`, which this check has not seen any migration or "
                "full_schema.sql create by this point — cannot tell whether the target key exists; "
                "treated as a finding rather than assumed clean.",
            )
        )
        return
    if ref.has_key_on(e.ref_cols) is False:
        cols_str = ", ".join(e.ref_cols)
        pk_str = ", ".join(ref.primary_key) if ref.primary_key else "(none)"
        findings.append(
            (
                e,
                f"references `{e.ref_table}` ({cols_str}); by this point that is not `{e.ref_table}`'s "
                f"PRIMARY KEY ({pk_str}) or a UNIQUE key on exactly those columns in that order — an 8.4 "
                "database would refuse this with ERROR 6125 here.",
            )
        )


def check_rule1a(schema_tables: dict[str, TableKeys], schema_fks: list[FkLink]) -> list[tuple[FkLink, str]]:
    """Rule 1a — fresh install, full_schema.sql's own links. Every link
    full_schema.sql declares, checked against full_schema.sql's own keys
    only — before any migration has ever replayed."""
    findings: list[tuple[FkLink, str]] = []
    for fk in schema_fks:
        ref = schema_tables.get(fk.ref_table)
        if ref is None:
            findings.append(
                (
                    fk,
                    f"references `{fk.ref_table}`, which this check never saw a CREATE TABLE for in "
                    "full_schema.sql — cannot tell whether the target key exists; treated as a finding "
                    "rather than assumed clean.",
                )
            )
            continue
        if ref.has_key_on(fk.ref_cols) is False:
            cols_str = ", ".join(fk.ref_cols)
            pk_str = ", ".join(ref.primary_key) if ref.primary_key else "(none)"
            findings.append(
                (
                    fk,
                    f"references `{fk.ref_table}` ({cols_str}), which is not that table's PRIMARY KEY "
                    f"({pk_str}) or a UNIQUE key on exactly those columns in full_schema.sql itself — a "
                    "fresh install would refuse this with ERROR 6125.",
                )
            )
    return findings


def check_rule3(
    schema_tables: dict[str, TableKeys], live_final: dict[str, TableKeys], created_names: set[tuple[str, str]]
) -> list[str]:
    """Rule 3 — the two must agree. For every (table, index name) BOTH
    full_schema.sql and the migrations create, its final UNIQUE/prefix
    state at the end of Rule 2's upgrade walk must match full_schema.sql's
    own."""
    findings: list[str] = []
    for table, keys in sorted(schema_tables.items()):
        for name, info in sorted(keys.indexes.items()):
            if (table, name) not in created_names:
                continue  # only in full_schema.sql — nothing on the migration side to agree or disagree with.
            end = live_final.get(table, TableKeys()).indexes.get(name)
            if end is None:
                findings.append(
                    f"`{table}`.`{name}` is declared in full_schema.sql (UNIQUE={info.unique}), but the "
                    "migrations that create it also drop it and never re-add it — an upgrade ends with no "
                    "such index at all, while a fresh install has one."
                )
            elif end.unique != info.unique or end.prefixed != info.prefixed:
                findings.append(
                    f"`{table}`.`{name}` is UNIQUE={info.unique}, prefix={info.prefixed} in full_schema.sql, "
                    f"but the migrations that create it leave it UNIQUE={end.unique}, prefix={end.prefixed} "
                    "at the end of the upgrade order — a fresh install and an upgrade end up with different "
                    "index definitions for the same name."
                )
    return findings


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument(
        "--schema",
        type=Path,
        default=DEFAULT_SCHEMA,
        help="Path to a full_schema.sql-shaped file to check (default: this repo's own web/_sql/full_schema.sql).",
    )
    parser.add_argument(
        "--migrations-dir",
        type=Path,
        default=None,
        help="Directory of numbered migrations to also read (default: the schema file's own directory).",
    )
    parser.add_argument(
        "--no-migrations",
        action="store_true",
        help="Run Rule 1a alone, against the schema file only — Rule 1b, Rule 2, Rule 3 and the "
        "starting-set check are all skipped entirely. Used to test this check against a self-contained "
        "scratch copy.",
    )
    parser.add_argument(
        "--strict",
        action="store_true",
        help="Exit 1 if any rule, or the starting-set check, finds anything.",
    )
    args = parser.parse_args(argv)

    schema_path: Path = args.schema
    if not schema_path.exists():
        print(f"Schema file not found: {schema_path}", file=sys.stderr)
        return 2
    try:
        schema_tables, schema_fks = read_schema_file(schema_path)
    except (OSError, UnicodeDecodeError) as exc:
        # UnicodeDecodeError (a non-UTF-8 byte in the file) is NOT an OSError
        # in Python — without catching it separately here, it used to reach
        # nothing but Python's own default handler: a raw traceback on
        # stderr and exit code 1, with no file name anywhere in the first
        # line a reader would see, and — worse — indistinguishable from a
        # clean run by the very `pr-security.yml` pattern this script's own
        # "HOW THIS IS WIRED INTO pr-security.yml" section warns about (a
        # crash looks exactly like "no bullet points", which that section
        # already flags as a trap for a MISSING file; a non-UTF-8 byte is
        # the same trap, reached a different way). A missing file already
        # exits 2 with a clear message; this now does too (found by round 3,
        # #552).
        print(f"Could not read schema file {schema_path}: {exc}", file=sys.stderr)
        return 2

    rule1a_findings = check_rule1a(schema_tables, schema_fks)

    print("Foreign-key-target-is-a-real-key audit (#552)")
    print(f"  schema file read:                 {schema_path}")
    print(f"  tables read from it:              {len(schema_tables)}")
    print(f"  foreign keys read from it:        {len(schema_fks)}")

    rule1b_findings: list[tuple[Event, str]] = []
    rule2_findings: list[tuple[Event, str]] = []
    rule3_findings: list[str] = []
    starting_set_problems: list[str] = []
    starting_set_shape_findings: list[str] = []
    unaccounted_findings: list[str] = []
    migration_file_count = 0
    migration_fk_count = 0
    rule1b_fk_count = 0
    rule2_fk_count = 0

    if args.no_migrations is False:
        migrations_dir: Path = args.migrations_dir or schema_path.parent
        # Numbered migrations ONLY — `[0-9][0-9][0-9]_*.sql` — never every
        # `.sql` file in the folder. A released folder has held
        # `demo_data.sql` alongside the numbered ones, which is not part of
        # the migration sequence the Migrator replays.
        files = sorted(migrations_dir.glob("[0-9][0-9][0-9]_*.sql"))
        files_events: list[tuple[str, list[Event]]] = []
        for p in files:
            try:
                files_events.append((_rel(p), parse_migration_file(p)))
            except (OSError, UnicodeDecodeError) as exc:
                # Caught per file, not around the whole list comprehension,
                # so the message can name the ONE file at fault — see the
                # matching comment on the schema-file read above for why
                # UnicodeDecodeError has to be caught here explicitly too.
                print(f"Could not read migration file {p}: {exc}", file=sys.stderr)
                return 2
        migration_file_count = len(files)
        all_events = [e for _f, evs in files_events for e in evs]
        migration_fk_count = sum(1 for e in all_events if e.kind == "fk")
        created_names, created_pk_tables = collect_created_names(all_events)

        # Rule 1b — fresh install's own migration replay, seeded from
        # full_schema.sql's COMPLETE picture (every table it declares is
        # already known to exist, because a fresh install creates all of
        # them before any migration ever replays).
        rule1b_seed_tables = {t: k.clone() for t, k in schema_tables.items()}
        rule1b_known = set(schema_tables.keys())
        _rule1b_live, rule1b_findings, rule1b_fk_count = run_walk(rule1b_seed_tables, rule1b_known, files_events)

        # Rule 2 — upgrade, in order, seeded from the recorded starting set.
        baseline, baseline_known, starting_set_problems = build_upgrade_baseline(
            schema_tables, created_names, created_pk_tables
        )
        live_final, rule2_findings, rule2_fk_count = run_walk(baseline, baseline_known, files_events)
        rule3_findings = check_rule3(schema_tables, live_final, created_names)
        unaccounted_findings = explain_unaccounted_keys(schema_tables, created_names, created_pk_tables)
        # MEDIUM 1, fix round 3 (#552): a SEPARATE pass from build_upgrade_baseline
        # above — that function only checks whether each entry's table/name still
        # exists; this one checks whether the SHAPE it recorded still matches what
        # full_schema.sql declares today. See check_starting_set_shapes's own
        # docstring for why both are needed.
        starting_set_shape_findings = check_starting_set_shapes(schema_tables)

        print(f"  numbered migration files scanned: {migration_file_count}")
        print(f"  foreign keys read from them:      {migration_fk_count}")
        print(
            f"  of those, checked against a fresh install's own replay (Rule 1b): {rule1b_fk_count} "
            f"(the rest sit inside a CREATE TABLE block for a table full_schema.sql already declares, "
            "which does nothing on a real fresh install)"
        )
        print(f"  of those, checked against the upgrade order (Rule 2):              {rule2_fk_count}")
        print(f"  starting-set entries for Rule 2's baseline:                        {len(STARTING_SET)}")
    else:
        print("  migrations: not scanned (--no-migrations; Rule 1b, Rule 2, Rule 3 and the starting-set check are skipped)")

    print(f"  total foreign keys checked (schema + migrations):  {len(schema_fks) + migration_fk_count}")
    total_findings = (
        len(rule1a_findings)
        + len(rule1b_findings)
        + len(rule2_findings)
        + len(rule3_findings)
        + len(starting_set_problems)
        + len(starting_set_shape_findings)
        + len(unaccounted_findings)
    )
    print()

    if total_findings == 0:
        print(
            "No foreign key was found pointing at anything other than a real PRIMARY/UNIQUE key — on a "
            "fresh install, on a fresh install's own migration replay, on an upgrade in order, or in "
            "whether the two agree — and every key full_schema.sql declares is explained. ✅"
        )
        return 0

    if starting_set_problems:
        print(f"STARTING_SET problems ({len(starting_set_problems)} finding(s) — the list itself is out of date):")
        for why in starting_set_problems:
            print(f"  • {why}")
        print()

    if starting_set_shape_findings:
        print(
            f"STARTING_SET shape check ({len(starting_set_shape_findings)} finding(s) — full_schema.sql must "
            "not change the shape of a key that predates the migrations):"
        )
        for why in starting_set_shape_findings:
            print(f"  • {why}")
        print()

    if rule1a_findings:
        print(
            f"Rule 1a — fresh install, full_schema.sql's own links ({len(rule1a_findings)} finding(s), "
            "checked against full_schema.sql's own keys only):"
        )
        for fk, why in rule1a_findings:
            print(f"  • {fk.source_file}:{fk.line}: `{fk.source_table}`.`{fk.name}` {why}")
        print()

    if rule1b_findings:
        print(
            f"Rule 1b — fresh install, the installer's own migration replay ({len(rule1b_findings)} "
            "finding(s), checked against what a fresh install's replay of every migration would actually "
            "hold by that exact point):"
        )
        for e, why in rule1b_findings:
            print(f"  • {e.file}:{e.line}: `{e.table}`.`{e.fk_name or e.name}` {why}")
        print()

    if rule2_findings:
        print(
            f"Rule 2 — upgrade order ({len(rule2_findings)} finding(s), checked against the keys a real "
            "upgrade would have built by that exact point):"
        )
        for e, why in rule2_findings:
            print(f"  • {e.file}:{e.line}: `{e.table}`.`{e.fk_name or e.name}` {why}")
        print()

    if rule3_findings:
        print(f"Rule 3 — full_schema.sql and the migrations must agree ({len(rule3_findings)} finding(s)):")
        for why in rule3_findings:
            print(f"  • {why}")
        print()

    if unaccounted_findings:
        print(
            f"Starting-set list — every full_schema.sql key must be explained ({len(unaccounted_findings)} "
            "finding(s)):"
        )
        for why in unaccounted_findings:
            print(f"  • {why}")
        print()

    print(f"Found {total_findings} problem(s).")
    print(
        "See DEV_NOTES.md → \"Portable DDL convention (MySQL 8.0 ∩ MariaDB)\" for the guarded "
        "ADD UNIQUE KEY idiom that fixes this without risking data on an existing database."
    )
    return 1 if args.strict else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
