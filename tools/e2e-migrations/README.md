# End-to-end migration test (#248)

Exercises every `web/_sql/NNN_*.sql` migration against a real MySQL 8.0.36
container (matching production's minimum-supported version — `ADD COLUMN IF
NOT EXISTS` requires ≥ 8.0.29).

## What it tests

| Phase | What | Pass condition |
|---|---|---|
| **1. Fresh install** | Apply every migration in filename order to an empty DB. | Every migration executes without SQL error. |
| **2. Idempotency** | Re-run every migration on the now-populated DB. | Zero net change to `tables / columns / indexes / tblMigrations`. |
| **3. Stale-DB upgrade** | Wipe, apply the first half, then the rest. | Catch-up produces the same final state as phase 1. |
| **4. full_schema fresh-install** | Wipe, load `web/_sql/full_schema.sql` directly (the consolidated fresh-install path) against an empty DB. | `full_schema.sql` loads without a SQL error (hard gate). Table/column/index/`tblMigrations` counts are then compared against phase 1's migration-chain numbers and printed — a mismatch is a **warning only**, not a failure (see below). Runs even when `--skip-stale` is passed; it's independent of phase 3. |

## Run it locally

Requires `docker` + `docker compose` + bash 4+.

```bash
tools/e2e-migrations/run.sh                # all four phases
tools/e2e-migrations/run.sh --skip-stale   # phases 1+2+4 (skip phase 3)
tools/e2e-migrations/run.sh --keep         # leave container running for inspection
```

A passing run looks like:

```
═════ Phase 1: fresh install ═════
  applied=104 skipped=0 failed=0
  schema: 110 tables, 1247 columns, 312 indexes, 105 migrations recorded
═════ Phase 2: idempotency re-run ═════
  applied=0 skipped=104 failed=0
  ✓ idempotency confirmed: zero net change on second run
═════ Phase 3: stale-DB upgrade ═════
  ✓ stale-DB upgrade catches up to the same final state
═════ Phase 4: full_schema fresh-install ═════
  ✓ full_schema.sql loaded without error
  schema: 110 tables, 1247 columns, 312 indexes, 105 migrations recorded
  ✓ full_schema vs migration-chain parity confirmed: identical counts
═════ All phases passed ═════
```

If phase 4's counts don't match phase 1's, the run still exits 0 but prints
a `⚠ full_schema vs migration-chain parity` warning with per-metric deltas —
review it, but it isn't a hard failure on its own (see below).

## How it's wired

- `docker-compose.yml` pins MySQL 8.0.36, exposes port `33069` on the host
  (uncommon so it doesn't clash with a local MySQL), uses `tmpfs` for
  storage so each invocation is genuinely fresh.
- `run.sh` brings the container up, waits for `mysqladmin ping`, then
  drives each phase by shelling `mysql … < file.sql` per migration. It
  records each successful file in `tblMigrations` via `INSERT IGNORE` —
  mimicking the production `Portal\Core\Migrator` wrapper behaviour.
- Phase 2 compares row counts from `information_schema.tables`,
  `information_schema.columns`, and `information_schema.statistics`
  before and after the re-run. Any drift fails the harness.
- Phase 3 drops the DB, applies the first N/2 migration files, then runs
  the full loop and confirms the catch-up reaches the same migration
  count as phase 1.
- Phase 4 drops the DB again and loads `web/_sql/full_schema.sql` directly
  (no per-file migration loop) — this is the actual fresh-install path the
  installer uses. A load error is the only hard failure; the resulting
  table/column/index/`tblMigrations` counts are then diffed against phase
  1's numbers (captured in `P1_TABLES` / `P1_COLUMNS` / `P1_INDEXES` /
  `P1_MIGS`) and any mismatch is printed as a warning only, since a
  structural difference between the migration chain and the consolidated
  schema can be legitimate and is better judged by a human than hard-failed
  in CI.

## Static-analysis companion

`tools/audit-checks/check_migration_idempotency.py` is the fast-feedback
first pass — it flags non-idempotent statement patterns (`CREATE TABLE`
without `IF NOT EXISTS`, `INSERT` without `ON DUPLICATE KEY UPDATE`, …)
without spinning up a container. Run that on every commit; run this
harness pre-release.

`tools/audit-checks/check_schema_seed_parity.py` is the SQL-source-level
gate for seed/mark parity between the migration chain and
`full_schema.sql` — it's what actually fails a PR (via `pr-security.yml`)
when a new migration's seeds/marks aren't ported into `full_schema.sql`.
Phase 4 here is the runtime-against-real-MySQL complement to that static
check, not a replacement for it — hence phase 4 only hard-fails on a load
error, not a count mismatch.

## CI

Wired to CI via `.github/workflows/e2e-migrations.yml` (#248), which runs
this harness (all four phases) on `ubuntu-latest` — Docker + `docker
compose` are preinstalled there, so no setup step is required. Triggers on
pushes/PRs that touch `web/_sql/**`, `tools/e2e-migrations/**`, or the
workflow file itself, plus manual `workflow_dispatch`.
