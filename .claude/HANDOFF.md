# Handoff — where the work is right now

*Overwritten, never appended. Current state only (#678). Issues are the record of
what is and is not done.*

**Branch:** `claude/alpha-wip`. **Nothing is pushed** — the owner reserves pushes,
and no pull request until the one comprehensive Codex review of the whole branch.

## Just committed

**#687 — an optional ceiling on how long giving records keep a donor's name**
(migration 234). Settable installation-wide by a global administrator, or by an
organisation for itself, and **off by default**: the figure is a statutory
retention period, so forcing it down could make a customer destroy records their
own law requires. The owner's decision, 31 October 2026.

Before it: **#697** (`e1fe6e4`), carrying the rows a restore could not put back
through a restore.

## What to know before touching the ceiling

- **`capFromDatabase()` is the authority for every refusal**, read LIVE and under
  `FOR UPDATE` on the installation's own settings row, inside the transaction
  that writes (#647's rule). `capInForce()` reads the request's snapshot through
  `SettingLimit` and is for DRAWING THE PAGE ONLY. Using the snapshot to judge a
  save let two ordinary requests store 50 years under a ceiling of 7.
- **The gate is on `$yearsOwn` after rules 1 and 2**, which is exactly what will
  be stored. Gating `$years` (the typed figure OR the suggestion) refused saves
  that store nothing — including switching the hold on.
- **The organisation's own ceiling is judged only when it CHANGES.** It may
  legitimately sit above the installation's and is simply superseded.
- **`max` on both number boxes is the hard maximum**, never the ceiling: a
  prefill above `max` stops a real browser submitting the form at all (#668).
- `check_limit_settings_capped.py` now REQUIRES the cap — its exemption for
  `GivingRetention.php` is gone.

## Open, with issues

- **#699** — the ceiling's two save gates are covered by no automatic test. They
  are the whole design, so this is the honest weak point. Needs an HTTP section
  (`access-pages-selftest.php`, which must run alone, #689).
- **#700** — two organisations saving the giving-records page for the FIRST time
  in the same instant deadlock on their inserts' gap locks. **Pre-existing**,
  proved against `git show HEAD:` of the class; nothing is lost and a retry works.
- **#698** — a snapshot moved between servers with different
  `lower_case_table_names`. Deliberately out of #697; not measured.
- **#685** — organisations already over a newly-set attendance retention cap.

## Verification of this commit, as run

`php -l` clean; all **46** `tools/audit-checks/check_*.py` pass with the parity
check's output read (232 migrations on disk, 232 marked, 0 missing);
`tools/e2e-migrations/run.sh` all seven phases; `tools/giving-retention-selftest.php`
63 of 63.

**`tools/run-all-selftests.sh`: 65 of 66 green.** The one that is not is
`mail-queue`, which **REFUSED rather than failed**: its part F3 computes a moment
that falls in the hour New York's clock repeats, and today (1 November 2026) is
the day those clocks go back. Nothing in this package touches mail, every check
before F3 passed, and the same suite was **66/66 with this package's setting
already seeded** earlier the same day. A re-run is scheduled for after the
overlap.

**Codex has not reviewed any of it.** Independent Claude checkers stood in: five
rounds on #697 (8, 6, 5, 4, 4 real problems) and two on #687 (6 and 3). All are
fixed.
