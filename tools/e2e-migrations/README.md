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

## Run it locally

Requires `docker` + `docker compose` + bash 4+.

```bash
tools/e2e-migrations/run.sh                # all three phases
tools/e2e-migrations/run.sh --skip-stale   # phases 1+2 only
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
═════ All phases passed ═════
```

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

## Static-analysis companion

`tools/audit-checks/check_migration_idempotency.py` is the fast-feedback
first pass — it flags non-idempotent statement patterns (`CREATE TABLE`
without `IF NOT EXISTS`, `INSERT` without `ON DUPLICATE KEY UPDATE`, …)
without spinning up a container. Run that on every commit; run this
harness pre-release.

## CI

Not wired to CI by default — the harness needs Docker and takes ~30 s.
Add it to the release workflow if/when GitHub Actions has Docker
available in the runner.
