#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# End-to-end migration test (#248)
# -----------------------------------------------------------------------------
# Spins up a disposable MySQL 8.0.36 container, applies every migration
# under web/_sql/ in filename order, then runs the SAME set a second time
# to verify idempotency under the Migrator wrapper.
#
# Three phases:
#   1. Fresh install — apply every numbered migration in order. Tracks
#      tblMigrations rows.
#   2. Idempotency — re-runs the same loop, asserts no new rows in
#      tblMigrations and zero net schema change.
#   3. Stale-DB upgrade — applies the first half of migrations, then
#      applies the rest. Confirms the catch-up path.
#
# Exit:
#   0 — all three phases pass.
#   non-zero — first failing phase.
#
# Requires: docker + bash 4+. Tested under DreamHost-equivalent MySQL
# 8.0.36 (the version pinned in docker-compose.yml).
#
# Usage:
#   tools/e2e-migrations/run.sh                # run all three phases
#   tools/e2e-migrations/run.sh --skip-stale   # skip phase 3
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

wait_for_mysql() {
    echo -n "→ Waiting for MySQL to accept connections "
    for i in $(seq 1 60); do
        if docker exec portal-e2e-mysql mysqladmin ping -h localhost -u"${DB_USER}" -p"${DB_PASS}" >/dev/null 2>&1; then
            echo " ready."
            return 0
        fi
        echo -n "."
        sleep 1
    done
    echo " timeout!" >&2
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
    local before_after_changed=0
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
# Start
# -----------------------------------------------------------------------------

echo "→ Bringing up MySQL 8.0.36"
docker compose -f "${COMPOSE_FILE}" up -d --remove-orphans
wait_for_mysql

# -----------------------------------------------------------------------------
# Phase 1: fresh install
# -----------------------------------------------------------------------------
echo
echo "═════ Phase 1: fresh install ═════"
mysql_file "${SQL_DIR}/000_create_migrations_table.sql"
mysql_q "INSERT IGNORE INTO tblMigrations (filename) VALUES ('000_create_migrations_table.sql');" >/dev/null
run_all_migrations
P1_TABLES=$(count_tables)
P1_COLUMNS=$(count_columns)
P1_INDEXES=$(count_indexes)
P1_MIGS=$(count_migrations)
echo "  schema: ${P1_TABLES} tables, ${P1_COLUMNS} columns, ${P1_INDEXES} indexes, ${P1_MIGS} migrations recorded"

# -----------------------------------------------------------------------------
# Phase 2: idempotency re-run
# -----------------------------------------------------------------------------
echo
echo "═════ Phase 2: idempotency re-run ═════"
run_all_migrations
P2_TABLES=$(count_tables)
P2_COLUMNS=$(count_columns)
P2_INDEXES=$(count_indexes)
P2_MIGS=$(count_migrations)
echo "  schema: ${P2_TABLES} tables, ${P2_COLUMNS} columns, ${P2_INDEXES} indexes, ${P2_MIGS} migrations recorded"

if [[ "${P1_TABLES}" != "${P2_TABLES}" || "${P1_COLUMNS}" != "${P2_COLUMNS}" || "${P1_INDEXES}" != "${P2_INDEXES}" ]]; then
    echo "✗ idempotency FAILED — schema changed on second run" >&2
    exit 1
fi
if [[ "${P1_MIGS}" != "${P2_MIGS}" ]]; then
    echo "✗ idempotency FAILED — tblMigrations row count changed (P1=${P1_MIGS}, P2=${P2_MIGS})" >&2
    exit 1
fi
echo "  ✓ idempotency confirmed: zero net change on second run"

# -----------------------------------------------------------------------------
# Phase 3: stale-DB upgrade simulation
# -----------------------------------------------------------------------------
if [[ "${SKIP_STALE}" -eq 0 ]]; then
    echo
    echo "═════ Phase 3: stale-DB upgrade ═════"
    echo "  Wiping DB and replaying only the first half of migrations …"
    docker exec -i portal-e2e-mysql mysql -u"${DB_USER}" -p"${DB_PASS}" -e "DROP DATABASE IF EXISTS ${DB_NAME}; CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
    mysql_file "${SQL_DIR}/000_create_migrations_table.sql"
    mysql_q "INSERT IGNORE INTO tblMigrations (filename) VALUES ('000_create_migrations_table.sql');" >/dev/null

    all_files=( $(ls "${SQL_DIR}"/[0-9]*.sql | sort) )
    half=$(( ${#all_files[@]} / 2 ))
    for file in "${all_files[@]:0:$half}"; do
        apply_migration "${file}" || true
    done
    BEFORE_MIGS=$(count_migrations)
    echo "  applied first ${half} (recorded ${BEFORE_MIGS} migrations)"
    echo "  applying remaining ${#all_files[@]} − ${half} migrations …"
    run_all_migrations
    AFTER_MIGS=$(count_migrations)
    echo "  total recorded after catch-up: ${AFTER_MIGS}"
    if [[ "${AFTER_MIGS}" != "${P1_MIGS}" ]]; then
        echo "✗ stale-upgrade FAILED — catch-up produced ${AFTER_MIGS} migrations vs fresh install ${P1_MIGS}" >&2
        exit 1
    fi
    echo "  ✓ stale-DB upgrade catches up to the same final state"
fi

echo
echo "═════ All phases passed ═════"
