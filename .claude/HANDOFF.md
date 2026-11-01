# Handoff — where the work is right now

*Overwritten, never appended. Current state only (#678). Issues are the record of
what is and is not done.*

**Branch:** `claude/alpha-wip`. **Nothing is pushed** — the owner reserves pushes,
and no pull request may be raised until the one comprehensive Codex review of the
whole branch.

## Just committed

**#697 — a restore no longer throws away the rows an earlier restore kept.**
`DbBackup` reads the waiting rows before it empties anything and writes them again
after every table is back; `tblRestoreHeldRows` joined `WHOLE_DATABASE_ONLY` so a
one-table restore of it is refused. No migration, no schema change.

It also fixed a class of fault older than itself: **a table name compared, or used
as an array key, with capitals mattering**, which on a server with
`lower_case_table_names = 1` had silently disabled #694's cascade rescue, the
holding of restored mail and the recording of restored held texts. New hard gate
`tools/audit-checks/check_table_name_comparisons.py`, wired into
`pr-security.yml`.

## What to know before touching this area

- **The rule, which took four rounds to state right:** a table-keyed lookup is
  safe when **both sides come from the same place**, unsafe when one side is
  folded by the server and the other is not. Earlier wordings were wrong in both
  directions. `namedIn()` compares names; `forTable()` fetches from an array keyed
  by table name; neither is "the ONE way".
- **The audit script pins both helper bodies WHOLE.** Any edit to them fails it on
  purpose — update `EXPECTED_BODIES` in the same commit.
- **No self-test here can see this class of fault.** They all run one server set
  to 0. Verification was by driving the class directly against a server with the
  setting on.

## Open, with issues

- **#687** — the donor-retention cap (`giving.retention.years`). **Next up.** The
  owner's decision is on the issue: opt-in at either level, off by default; an
  installation global admin or an organisation's own may set one; where an
  installation cap exists an organisation may only go lower. Still open on the
  issue: what happens to an organisation already storing more than a newly-set
  cap. My intended reading, stated there rather than assumed: **refuse a cap that
  conflicts with a stored figure, naming the organisations that block it**, so the
  save-time check really is a cap rather than #668's "a save-time cap is not a
  cap".
- **#698** — a snapshot moved between servers with different
  `lower_case_table_names` settings. Deliberately out of #697; not measured.
- **#685** — organisations already over a newly-capped retention figure.
- **#697's** own residue: nothing automatic reaches the over-20,000-row ceiling or
  the two "could not read/count" branches.

## Verification of the commit, as run

`php -l` clean; all **46** `tools/audit-checks/check_*.py` pass with the parity
check's output read (231 migrations on disk, 231 marked, 0 missing); every
workflow YAML parses; `tools/run-all-selftests.sh` **66 tests, 0 not green**;
`tools/e2e-migrations/run.sh` seven phases and 42 checks (on the tree whose two
`web/_sql/` files are byte-identical to this one); **31 planted faults over five
rounds**, each caught by the check that should catch it.

**Codex has not reviewed any of it.** Five independent Claude checkers stood in,
finding 8, 6, 5, 4 and 4 real problems; all 27 are fixed.
