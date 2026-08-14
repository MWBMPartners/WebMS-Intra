#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# End-to-end migration test (#248, restructured per SQL portability fix spec §5)
# -----------------------------------------------------------------------------
# Spins up a disposable MySQL 8.0.36 container and exercises web/_sql/ the
# same way the real installer does: full_schema.sql first, THEN every
# numbered migration replayed on top of it, ignoring tblMigrations
# (web/_install/index.php:360-466) — not the historical "apply the
# migration chain to an empty DB first" order.
#
# Four phases:
#   1. full_schema fresh-install — wipe the DB, load web/_sql/full_schema.sql
#      directly (the consolidated fresh-install path). Any SQL error is a
#      hard failure.
#   2. Installer replay — replay_all_migrations() runs every numbered file
#      under web/_sql/ in order over the phase-1 DB, IGNORING tblMigrations
#      (mirrors the installer's replay loop at _install/index.php:399-466).
#      Hard-fails on any migration error, or on a table/column/index count
#      drift vs phase 1 — every migration must be a no-op on an up-to-date
#      schema. A tblMigrations row-count drift is a warning only for now —
#      tools/audit-checks/check_schema_seed_parity.py is the actual gate for
#      seed-block parity.
#   3. Idempotency re-run — replay_all_migrations() runs again over the
#      phase-2 DB. Hard-fails on any migration error, or ANY net schema /
#      tblMigrations change (mirrors an installer re-run / upgrade retry).
#   4. Migrator catch-up over a full_schema base — load full_schema.sql as an
#      existing install (the base tables tblUsers/tblRoutes/tblSettings/… are
#      created ONLY by full_schema.sql, never by a numbered migration, so
#      "chain-from-truly-empty" is a fiction no code path produces). Then,
#      unless --skip-stale, clear the newest half of tblMigrations marks to
#      model a behind install and run Portal\Core\Migrator's catch-up
#      (run_all_migrations). Every pending migration must re-apply as a guarded
#      idempotent no-op over the installed base (HARD gate on any failure), and
#      the final schema + tblMigrations must match phase 1 exactly (also a HARD
#      gate — same base, same guarded migrations, so any drift is a real
#      idempotency bug).
#
# Exit:
#   0 — all phases pass (the warnings noted above do not affect this).
#   non-zero — first hard-failing phase.
#
# Requires: docker + bash 4+. Tested under DreamHost-equivalent MySQL
# 8.0.36 (the version pinned in docker-compose.yml — production is
# confirmed MySQL 8; see docker-compose.yml's header comment).
#
# Usage:
#   tools/e2e-migrations/run.sh                # run all four phases
#   tools/e2e-migrations/run.sh --skip-stale   # skip the phase-4 catch-up simulation
#   tools/e2e-migrations/run.sh --keep         # leave the container up afterwards
# -----------------------------------------------------------------------------

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SQL_DIR="${REPO_ROOT}/web/_sql"
COMPOSE_FILE="${SCRIPT_DIR}/docker-compose.yml"

DB_HOST="127.0.0.1"
DB_PORT="33069"
DB_USER="root"
DB_PASS="e2e-root"
DB_NAME="portal_e2e"

KEEP=0
SKIP_STALE=0
for arg in "$@"; do
    case "${arg}" in
        --keep)       KEEP=1 ;;
        --skip-stale) SKIP_STALE=1 ;;
        *) echo "Unknown flag: ${arg}" >&2; exit 64 ;;
    esac
done

trap teardown EXIT INT TERM

teardown() {
    [[ -n "${SNAP_DIR:-}" ]] && rm -rf "${SNAP_DIR}" 2>/dev/null || true
    if [[ "${KEEP}" -eq 0 ]]; then
        echo "→ Tearing down container"
        docker compose -f "${COMPOSE_FILE}" down -v --remove-orphans >/dev/null 2>&1 || true
    else
        echo "→ Leaving container up (--keep). Stop manually: docker compose -f ${COMPOSE_FILE} down -v"
    fi
}

mysql_q() {
    docker exec -i portal-e2e-mysql mysql -u"${DB_USER}" -p"${DB_PASS}" -h localhost --skip-column-names "${DB_NAME}" -e "$1"
}

mysql_file() {
    docker exec -i portal-e2e-mysql mysql -u"${DB_USER}" -p"${DB_PASS}" -h localhost "${DB_NAME}" < "$1"
}

# Drops and recreates DB_NAME so each phase that needs a clean slate starts
# from a genuinely empty database (also self-healing across --keep re-runs).
reset_db() {
    docker exec -i portal-e2e-mysql mysql -u"${DB_USER}" -p"${DB_PASS}" -e \
        "DROP DATABASE IF EXISTS ${DB_NAME}; CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
}

wait_for_mysql() {
    echo -n "→ Waiting for MySQL to be ready (authenticated) "
    # NB: `mysqladmin ping` reports success even when auth is denied (an
    # "access denied" reply still proves the server is alive), so it goes
    # green before the MySQL image has finished its first-boot init — the
    # root password isn't set yet and the first real query then fails with
    # ERROR 1045. Poll an AUTHENTICATED query over TCP instead: the image's
    # temporary init server runs with --skip-networking, so a TCP connect
    # only succeeds once the real, fully-initialised server is up.
    for i in $(seq 1 90); do
        if docker exec portal-e2e-mysql mysql -u"${DB_USER}" -p"${DB_PASS}" \
                -h 127.0.0.1 --protocol=TCP -e "SELECT 1;" >/dev/null 2>&1; then
            echo " ready."
            return 0
        fi
        echo -n "."
        sleep 1
    done
    echo " timeout!" >&2
    echo "→ Last 30 lines of container log:" >&2
    docker logs portal-e2e-mysql 2>&1 | tail -30 >&2 || true
    exit 1
}

count_tables() {
    mysql_q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();"
}
count_columns() {
    mysql_q "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE();"
}
count_indexes() {
    mysql_q "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE();"
}
count_migrations() {
    mysql_q "SELECT COUNT(*) FROM tblMigrations;" 2>/dev/null || echo 0
}

# Snapshot the full column and index inventory as stable, sortable text so a
# drift between two phases can be reduced to the exact objects that appeared
# (or vanished). `table.column` for columns; `table.index.seq.column` for
# indexes (SEQ_IN_INDEX keeps multi-column keys ordered and distinct).
SNAP_DIR="$(mktemp -d)"
dump_columns() {
    mysql_q "SELECT CONCAT(table_name, '.', column_name)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
             ORDER BY 1;" 2>/dev/null | sort
}
dump_indexes() {
    mysql_q "SELECT CONCAT(table_name, '.', index_name, '.', seq_in_index, '.', column_name)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
             ORDER BY 1;" 2>/dev/null | sort
}

# -----------------------------------------------------------------------------
# Legacy tblMigrations-respecting chain (phase 4 only) — models
# Portal\Core\Migrator running against a pre-full_schema DB: each file is
# applied once and recorded, subsequent runs skip anything already recorded.
# -----------------------------------------------------------------------------

apply_migration() {
    local file="$1"
    local name; name="$(basename "${file}")"
    if [[ "${name}" == "demo_data.sql" || "${name}" == "full_schema.sql" ]]; then
        return 0
    fi
    if mysql_file "${file}"; then
        # Record in tblMigrations if not already present (mimics Migrator).
        mysql_q "INSERT IGNORE INTO tblMigrations (filename) VALUES ('${name}');" >/dev/null 2>&1 || true
        return 0
    fi
    return 1
}

run_all_migrations() {
    local applied=0
    local skipped=0
    local failed=0
    for file in $(ls "${SQL_DIR}"/[0-9]*.sql 2>/dev/null | sort); do
        local name; name="$(basename "${file}")"
        local already; already=$(mysql_q "SELECT COUNT(*) FROM tblMigrations WHERE filename = '${name}';" 2>/dev/null || echo 0)
        if [[ "${already}" -gt 0 ]]; then
            skipped=$((skipped + 1))
            continue
        fi
        if apply_migration "${file}"; then
            applied=$((applied + 1))
        else
            failed=$((failed + 1))
            echo "  ✗ ${name} FAILED" >&2
        fi
    done
    echo "  applied=${applied} skipped=${skipped} failed=${failed}"
    return "${failed}"
}

# -----------------------------------------------------------------------------
# Installer-replay chain (phases 2 + 3) — mimics web/_install/index.php's
# post-full_schema replay loop exactly: every numbered file, in filename
# order, run unconditionally. tblMigrations is NOT consulted to decide
# whether to apply a file (the installer ignores it for this exact reason —
# see index.php:422-430) — only used afterwards as a count to compare.
# -----------------------------------------------------------------------------

replay_all_migrations() {
    local applied=0
    local failed=0
    for file in $(ls "${SQL_DIR}"/[0-9]*.sql 2>/dev/null | sort); do
        if mysql_file "${file}"; then
            applied=$((applied + 1))
        else
            failed=$((failed + 1))
            echo "  ✗ $(basename "${file}") FAILED" >&2
        fi
    done
    echo "  replayed=${applied} failed=${failed}"
    return "${failed}"
}

# -----------------------------------------------------------------------------
# Start
# -----------------------------------------------------------------------------

echo "→ Bringing up MySQL 8.0.36"
docker compose -f "${COMPOSE_FILE}" up -d --remove-orphans
wait_for_mysql

# -----------------------------------------------------------------------------
# Phase 1: full_schema fresh-install
# -----------------------------------------------------------------------------
echo
echo "═════ Phase 1: full_schema fresh-install ═════"
echo "  Wiping DB and loading full_schema.sql …"
reset_db
if ! mysql_file "${SQL_DIR}/full_schema.sql"; then
    echo "✗ full_schema.sql FAILED to load against MySQL 8.0.36 — see error above" >&2
    exit 1
fi
echo "  ✓ full_schema.sql loaded without error"

P1_TABLES=$(count_tables)
P1_COLUMNS=$(count_columns)
P1_INDEXES=$(count_indexes)
P1_MIGS=$(count_migrations)
echo "  schema: ${P1_TABLES} tables, ${P1_COLUMNS} columns, ${P1_INDEXES} indexes, ${P1_MIGS} migrations recorded"
# Snapshot the exact object inventory BEFORE replay so a phase-2 drift can be
# diffed down to the specific columns/indexes the replay introduced.
dump_columns > "${SNAP_DIR}/p1_columns.txt"
dump_indexes > "${SNAP_DIR}/p1_indexes.txt"

# -----------------------------------------------------------------------------
# Phase 2: installer replay
# -----------------------------------------------------------------------------
# The real install flow, exactly: full_schema.sql (phase 1) followed by every
# numbered migration, ignoring tblMigrations. On a correctly-guarded schema
# this must be a full no-op — full_schema already delivers the final shape,
# so nothing should be added, changed, or removed.
echo
echo "═════ Phase 2: installer replay ═════"
echo "  Replaying every numbered migration over the phase-1 DB (installer semantics, web/_install/index.php:399-466) …"
set +e
replay_all_migrations
REPLAY2_FAILED=$?
set -e
if [[ "${REPLAY2_FAILED}" -ne 0 ]]; then
    echo "✗ installer replay FAILED — ${REPLAY2_FAILED} migration(s) errored against the full_schema baseline" >&2
    exit 1
fi

P2_TABLES=$(count_tables)
P2_COLUMNS=$(count_columns)
P2_INDEXES=$(count_indexes)
P2_MIGS=$(count_migrations)
echo "  schema: ${P2_TABLES} tables, ${P2_COLUMNS} columns, ${P2_INDEXES} indexes, ${P2_MIGS} migrations recorded"

if [[ "${P2_TABLES}" != "${P1_TABLES}" || "${P2_COLUMNS}" != "${P1_COLUMNS}" || "${P2_INDEXES}" != "${P1_INDEXES}" ]]; then
    echo "✗ installer replay FAILED — schema drifted vs the full_schema baseline (every migration must be a no-op on an up-to-date schema)" >&2
    printf '    tables:  full_schema=%s  post-replay=%s  Δ=%+d\n' "${P1_TABLES}" "${P2_TABLES}" "$((P2_TABLES - P1_TABLES))" >&2
    printf '    columns: full_schema=%s  post-replay=%s  Δ=%+d\n' "${P1_COLUMNS}" "${P2_COLUMNS}" "$((P2_COLUMNS - P1_COLUMNS))" >&2
    printf '    indexes: full_schema=%s  post-replay=%s  Δ=%+d\n' "${P1_INDEXES}" "${P2_INDEXES}" "$((P2_INDEXES - P1_INDEXES))" >&2
    # Diff the object inventories so the fix is precise: anything a migration
    # ADDS here is an object missing from full_schema.sql (the migration guard
    # found it absent). Anything it DROPS means full_schema carries an object
    # no migration produces. Both must be reconciled into full_schema.sql so
    # every migration is a genuine no-op on a fresh install.
    dump_columns > "${SNAP_DIR}/p2_columns.txt"
    dump_indexes > "${SNAP_DIR}/p2_indexes.txt"
    echo "    ── columns ADDED by replay (missing from full_schema.sql) ──" >&2
    comm -13 "${SNAP_DIR}/p1_columns.txt" "${SNAP_DIR}/p2_columns.txt" | sed 's/^/      + /' >&2
    echo "    ── columns REMOVED by replay (in full_schema.sql, no migration adds) ──" >&2
    comm -23 "${SNAP_DIR}/p1_columns.txt" "${SNAP_DIR}/p2_columns.txt" | sed 's/^/      - /' >&2
    echo "    ── indexes ADDED by replay (missing from full_schema.sql) ──" >&2
    comm -13 "${SNAP_DIR}/p1_indexes.txt" "${SNAP_DIR}/p2_indexes.txt" | sed 's/^/      + /' >&2
    echo "    ── indexes REMOVED by replay (in full_schema.sql, no migration adds) ──" >&2
    comm -23 "${SNAP_DIR}/p1_indexes.txt" "${SNAP_DIR}/p2_indexes.txt" | sed 's/^/      - /' >&2
    exit 1
fi
if [[ "${P2_MIGS}" != "${P1_MIGS}" ]]; then
    echo "⚠ tblMigrations count drift vs the full_schema seed block (full_schema=${P1_MIGS}, post-replay=${P2_MIGS}) — not a hard failure; tools/audit-checks/check_schema_seed_parity.py is the seed-parity gate" >&2
else
    echo "  ✓ table/column/index counts identical to the full_schema baseline — every migration is a no-op"
fi

# -----------------------------------------------------------------------------
# Phase 3: idempotency re-run
# -----------------------------------------------------------------------------
# Re-run the exact same installer-replay chain a second time on top of the
# phase-2 DB. This mirrors a user re-running the installer (or an upgrade
# retry) against an already-up-to-date schema: it must be a hard no-op.
echo
echo "═════ Phase 3: idempotency re-run ═════"
echo "  Replaying every numbered migration again over the phase-2 DB …"
set +e
replay_all_migrations
REPLAY3_FAILED=$?
set -e
if [[ "${REPLAY3_FAILED}" -ne 0 ]]; then
    echo "✗ idempotency re-run FAILED — ${REPLAY3_FAILED} migration(s) errored on the second replay" >&2
    exit 1
fi

P3_TABLES=$(count_tables)
P3_COLUMNS=$(count_columns)
P3_INDEXES=$(count_indexes)
P3_MIGS=$(count_migrations)
echo "  schema: ${P3_TABLES} tables, ${P3_COLUMNS} columns, ${P3_INDEXES} indexes, ${P3_MIGS} migrations recorded"

if [[ "${P3_TABLES}" != "${P2_TABLES}" || "${P3_COLUMNS}" != "${P2_COLUMNS}" || "${P3_INDEXES}" != "${P2_INDEXES}" || "${P3_MIGS}" != "${P2_MIGS}" ]]; then
    echo "✗ idempotency FAILED — schema or tblMigrations changed on the second replay" >&2
    exit 1
fi
echo "  ✓ idempotency confirmed: zero net change on second replay"

# -----------------------------------------------------------------------------
# Phase 4: Migrator catch-up over a full_schema base (the real upgrade path)
# -----------------------------------------------------------------------------
# Models Portal\Core\Migrator upgrading an EXISTING install. This is the only
# state in which Migrator ever runs in production: the numbered migrations are
# incremental-over-base by design — the foundational tables (tblUsers,
# tblRoutes, tblSettings, tblExpenseClaims, …) are created ONLY by
# full_schema.sql, never by a numbered migration (verified: no `[0-9]*.sql`
# file CREATEs them). The installer always loads full_schema.sql first, which
# also seeds tblMigrations with every filename so a fresh install shows
# nothing pending (web/_install/index.php:360,399-441,424-427). A
# "chain-from-truly-empty" run is therefore a fiction that no code path
# produces, and hard-failing on its missing-base-table errors tested nothing
# real — so phase 4 now starts from a full_schema base.
#
# The scenario exercised: an install that is BEHIND (its tblMigrations only
# records the older half of the chain), on which Migrator catches up. Every
# pending migration must re-apply as a guarded idempotent no-op over the
# installed base — zero failures — and reach the fully-migrated state with the
# schema byte-for-byte identical to phase 1. Migration failures here are a
# HARD gate: with the base tables present, the only way a migration can fail
# is a genuinely broken guard/dependency.
echo
echo "═════ Phase 4: Migrator catch-up over a full_schema base ═════"
echo "  Loading full_schema.sql as the installed base (models an existing install) …"
reset_db
if ! mysql_file "${SQL_DIR}/full_schema.sql"; then
    echo "✗ phase 4 base load FAILED — full_schema.sql did not load" >&2
    exit 1
fi
P4BASE_MIGS=$(count_migrations)
echo "  base loaded: $(count_tables) tables, $(count_columns) columns, $(count_indexes) indexes, ${P4BASE_MIGS} migrations recorded"

if [[ "${SKIP_STALE}" -eq 0 ]]; then
    echo "  Simulating a behind install: clearing the newest half of tblMigrations marks …"
    all_files=( $(ls "${SQL_DIR}"/[0-9]*.sql | sort) )
    total=${#all_files[@]}
    half=$(( total / 2 ))
    cleared=0
    for file in "${all_files[@]:$half}"; do
        name="$(basename "${file}")"
        mysql_q "DELETE FROM tblMigrations WHERE filename = '${name}';" >/dev/null 2>&1 || true
        cleared=$((cleared + 1))
    done
    echo "  cleared ${cleared} marks (tblMigrations now at $(count_migrations)); running Migrator catch-up …"
    set +e
    run_all_migrations
    P4_CATCHUP_FAILED=$?
    set -e
    if [[ "${P4_CATCHUP_FAILED}" -ne 0 ]]; then
        echo "✗ Migrator catch-up FAILED — ${P4_CATCHUP_FAILED} migration(s) errored re-applying over the full_schema base" >&2
        exit 1
    fi
else
    echo "  (stale-DB catch-up simulation skipped: --skip-stale — verifying base only)"
fi

P4_TABLES=$(count_tables)
P4_COLUMNS=$(count_columns)
P4_INDEXES=$(count_indexes)
P4_MIGS=$(count_migrations)
echo "  schema: ${P4_TABLES} tables, ${P4_COLUMNS} columns, ${P4_INDEXES} indexes, ${P4_MIGS} migrations recorded"

# After catch-up the schema and tblMigrations must be identical to phase 1's
# full_schema baseline — this is now a HARD gate (the catch-up re-applies the
# very same guarded migrations over the very same base, so any drift is a real
# idempotency bug, not a chain-vs-schema structural difference).
if [[ "${P4_TABLES}" != "${P1_TABLES}" || "${P4_COLUMNS}" != "${P1_COLUMNS}" || "${P4_INDEXES}" != "${P1_INDEXES}" || "${P4_MIGS}" != "${P1_MIGS}" ]]; then
    echo "✗ Migrator catch-up parity FAILED — schema/tblMigrations drifted vs the full_schema baseline" >&2
    printf '    tables:     post-catch-up=%s  full_schema=%s  Δ=%+d\n' "${P4_TABLES}" "${P1_TABLES}" "$((P4_TABLES - P1_TABLES))" >&2
    printf '    columns:    post-catch-up=%s  full_schema=%s  Δ=%+d\n' "${P4_COLUMNS}" "${P1_COLUMNS}" "$((P4_COLUMNS - P1_COLUMNS))" >&2
    printf '    indexes:    post-catch-up=%s  full_schema=%s  Δ=%+d\n' "${P4_INDEXES}" "${P1_INDEXES}" "$((P4_INDEXES - P1_INDEXES))" >&2
    printf '    migrations: post-catch-up=%s  full_schema=%s  Δ=%+d\n' "${P4_MIGS}" "${P1_MIGS}" "$((P4_MIGS - P1_MIGS))" >&2
    exit 1
fi
echo "  ✓ Migrator catch-up reaches the full_schema baseline exactly — every migration is a clean no-op over the installed base"

echo
echo "═════ All phases passed ═════"
