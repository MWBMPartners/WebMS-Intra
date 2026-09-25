# End-to-end migration test (#248)

Exercises `web/_sql/` against a real MySQL container the same way the
real installer does: `full_schema.sql` first, then every numbered migration
replayed on top of it, ignoring `tblMigrations` (`web/_install/index.php:360-466`)
— production runs MySQL 8, so the harness image is the right family. Which
MySQL version comes from `E2E_MYSQL_VERSION` (always an exact version, such
as 8.0.36 or 8.4.11; 8.0.36 by default, because that is what this harness
has always used). The pull-request check runs it twice, side by side, on
8.0.36 and 8.4.11 (owner's decision, 24 September 2026), because MySQL 8.4
refuses some links between tables that MySQL 8.0 allows (#552). Which MySQL
8 line production itself runs is not confirmed (see #475);
the harness previously applied migrations in the wrong order relative to the
installer's actual flow. See the SQL portability fix spec for the full
rationale.

## What it tests

| Phase | What | Pass condition |
|---|---|---|
| **1. full_schema fresh-install** | Wipe the DB, load `web/_sql/full_schema.sql` directly (the consolidated fresh-install path) against an empty DB. | `full_schema.sql` loads without a SQL error (hard gate). |
| **2. Installer replay** | `replay_all_migrations()` runs every numbered file under `web/_sql/` in order over the phase-1 DB, IGNORING `tblMigrations` — mirrors the installer's replay loop at `_install/index.php:399-466`. | `failed=0` (hard) **and** table/column/index counts identical to phase 1 (hard) — every migration must be a no-op on an up-to-date schema. A `tblMigrations` row-count drift vs phase 1 is a **warning only** for now — `check_schema_seed_parity.py` is the actual seed-parity gate. |
| **3. Idempotency re-run** | `replay_all_migrations()` runs again over the phase-2 DB. | `failed=0` and **zero** net change to tables/columns/indexes/`tblMigrations` vs phase 2 (hard) — mirrors an installer re-run / upgrade retry. |
| **4. Legacy chain-from-empty** | Wipe the DB, replay the `tblMigrations`-respecting chain from `000` onward via `run_all_migrations()` (models `Portal\Core\Migrator` against a pre-`full_schema` DB). Then, unless `--skip-stale`, wipe again and apply the first half of migrations followed by the rest. | Migration failures are a **hard** gate. Table/column/index/`tblMigrations` count parity vs phase 1's `full_schema` baseline is a **warning only** — a legitimate migration-chain vs consolidated-schema structural difference is better judged by a human. The stale-DB catch-up must reach the same final `tblMigrations` count as the rest of phase 4 (hard). `--skip-stale` skips only this half/half split — the chain-from-empty replay always runs. |

## Run it locally

Requires `docker` + `docker compose` + bash 4+.

```bash
tools/e2e-migrations/run.sh                # all four phases, on MySQL 8.0.36
E2E_MYSQL_VERSION=8.4.11 tools/e2e-migrations/run.sh   # the same, on MySQL 8.4.11
tools/e2e-migrations/run.sh --skip-stale   # phases 1-4, minus the phase-4 stale-DB split
tools/e2e-migrations/run.sh --keep         # leave container running for inspection
```

Run one version at a time on one machine — both use the same container name
(`portal-e2e-mysql`) and the same host port (`33069`).

A passing run looks like:

```
═════ Phase 1: full_schema fresh-install ═════
  ✓ full_schema.sql loaded without error
  schema: 110 tables, 1247 columns, 312 indexes, 146 migrations recorded
═════ Phase 2: installer replay ═════
  replayed=146 failed=0
  schema: 110 tables, 1247 columns, 312 indexes, 146 migrations recorded
  ✓ table/column/index counts identical to the full_schema baseline — every migration is a no-op
═════ Phase 3: idempotency re-run ═════
  replayed=146 failed=0
  schema: 110 tables, 1247 columns, 312 indexes, 146 migrations recorded
  ✓ idempotency confirmed: zero net change on second replay
═════ Phase 4: legacy chain-from-empty ═════
  applied=147 skipped=0 failed=0
  schema: 110 tables, 1247 columns, 312 indexes, 146 migrations recorded
  ✓ legacy chain vs full_schema parity confirmed: identical counts
  ── stale-DB upgrade split ──
  ✓ stale-DB upgrade catches up to the same final state
═════ All phases passed ═════
```

If phase 2's or phase 4's counts don't match their comparison baseline, the
run behaves differently depending on which: phase 2's table/column/index
mismatch is a **hard failure** (every migration must be a true no-op against
an up-to-date schema); phase 4's mismatch against phase 1 prints a
`⚠ legacy chain vs full_schema parity` warning with per-metric deltas and the
run still exits 0 — review it, but it isn't a hard failure on its own (see
below). A `tblMigrations` drift in phase 2 alone (schema otherwise identical)
is also a warning only, for the same reason.

## How it's wired

- `docker-compose.yml` takes its image from `E2E_MYSQL_VERSION` (default
  8.0.36; always an exact version, never a moving tag), exposes port
  `33069` on the host (uncommon so it doesn't clash with a local MySQL),
  uses `tmpfs` for storage so each invocation is genuinely fresh.
- `run.sh` brings the container up, waits for an authenticated query to
  succeed (`wait_for_mysql`), then asks the server for its own version and
  refuses to carry on if it does not match `E2E_MYSQL_VERSION` — without
  that check, a variable that never reached `docker-compose.yml` would
  silently test the wrong MySQL and still pass. Once confirmed, `run.sh`
  drives each phase via `mysql_q` / `mysql_file` helpers shelling out to the
  mysql CLI.
- **Phase 1** wipes the DB (`reset_db`) and loads `full_schema.sql` directly
  — the actual fresh-install path the installer uses. A load error is the
  only hard failure here.
- **Phases 2 and 3** call `replay_all_migrations()`, a new runner that loops
  over every `web/_sql/NNN_*.sql` file in filename order and executes it
  unconditionally — it does **not** consult `tblMigrations` to decide
  whether to run a file, matching the installer's own replay loop, which
  ignores `tblMigrations` for exactly this reason (`_install/index.php:422-430`).
  `tblMigrations` is only read afterwards, as a count for comparison.
- **Phase 4** uses the pre-existing `run_all_migrations()` / `apply_migration()`
  pair, which DOES consult `tblMigrations` before applying each file (mimicking
  `Portal\Core\Migrator`) — kept specifically to model a Migrator-only
  environment that never ran `full_schema.sql`. The stale-DB half/half split
  (skippable via `--skip-stale`) reuses the same pair.
- Row counts come from `information_schema.tables`, `information_schema.columns`,
  and `information_schema.statistics`, plus a `tblMigrations` row count.

## Static-analysis companions

`tools/audit-checks/check_mariadb_only_ddl.py` and
`tools/audit-checks/check_migration_idempotency.py` are the fast-feedback
first pass — they flag MariaDB-only DDL syntax and non-idempotent statement
patterns (bare `ADD COLUMN`, `INSERT` without `ON DUPLICATE KEY UPDATE`, …)
without spinning up a container. Run those on every commit; run this harness
pre-release.

`tools/audit-checks/check_schema_seed_parity.py` is the SQL-source-level gate
for seed/mark parity between the migration chain and `full_schema.sql` — it's
what actually fails a PR (via `pr-security.yml`) when a new migration's
seeds/marks aren't ported into `full_schema.sql`. Phases 2-4 here are the
runtime-against-real-MySQL complement to that static check, not a
replacement for it — hence the `tblMigrations`-count comparisons in phases 2
and 4 are warnings, not hard gates.

## CI

Wired to CI via `.github/workflows/e2e-migrations.yml` (#248), which runs this
harness (all four phases) on `ubuntu-latest` — Docker + `docker compose` are
preinstalled there, so no setup step is required. It runs on pull requests
and on pushes to alpha, beta and main, in both cases only when `web/_sql/`,
this folder or the workflow itself changes, and it can also be started by hand
(`workflow_dispatch`). It runs the harness TWICE, as two jobs side by
side — MySQL 8.0.36 and MySQL 8.4.11 (owner's decision, 24 September
2026) — using a matrix and the `E2E_MYSQL_VERSION` variable described
above.
