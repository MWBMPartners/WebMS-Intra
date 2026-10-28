#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# End-to-end migration test (#248, restructured per SQL portability fix spec §5)
# -----------------------------------------------------------------------------
# Spins up a disposable MySQL container (8.0.36 unless E2E_MYSQL_VERSION
# says otherwise) and exercises web/_sql/ the
# same way the real installer does: full_schema.sql first, THEN every
# numbered migration replayed on top of it, ignoring tblMigrations
# (web/_install/index.php:360-466) — not the historical "apply the
# migration chain to an empty DB first" order.
#
# Seven phases, and three data checks (described after them). 1 to 4 start
# from TODAY's install script. 5, 6 and 7 are the only ones that start from a
# database built by an older release: 5 and 6 upgrade one, which is where
# #553, #554 and #555 hid for months, and 7 runs the installer's "carry on"
# over one. (This line said "Six phases" above a list of seven from the day
# phase 7 was added until 13 October 2026.)
#   1. full_schema fresh-install — wipe the DB, load web/_sql/full_schema.sql
#      directly (the consolidated fresh-install path). Any SQL error is a
#      hard failure.
#   2. Installer replay — replay_all_migrations() runs every numbered file
#      under web/_sql/ in order over the phase-1 DB, IGNORING tblMigrations
#      (mirrors the installer's replay loop at _install/index.php:399-466).
#      Hard-fails on any migration error, or on a table/column/index count
#      drift vs phase 1. Since 12 October 2026 it also compares the install
#      script ALONE with the install script plus the replay, by name: every
#      column, index and link, every address and every setting's stored
#      value (see "the rule" beside compare_replay_with_install_script_alone).
#      The replay is NOT a complete no-op, and this header used to say it
#      was: it adds two links and switches three apps on. Those five are
#      named in that rule. Any other difference in structure, and any address
#      or setting value the replay changes or removes, is a hard failure. An
#      address or setting the replay ADDS is printed and does not fail here
#      (this used to say "anything else is a hard failure", which was untrue).
#      A tblMigrations row-count drift is a warning only for now —
#      tools/audit-checks/check_schema_seed_parity.py is the actual gate for
#      seed-block parity.
#   3. Idempotency re-run — replay_all_migrations() runs again over the
#      phase-2 DB. Hard-fails on any migration error, or ANY net schema /
#      tblMigrations change (mirrors an upgrade retry). Then full_schema.sql
#      itself is loaded a SECOND time over that database, which is what the
#      installer's "carry on with the existing database" choice does: it must
#      load without an error and leave the whole inventory as it was. Until
#      12 October 2026 nothing here ever loaded the install script over a
#      database that already held it, so a broken "is this already done?"
#      question in that file passed every phase.
#   4. Migrator catch-up over a full_schema base — load full_schema.sql as an
#      existing install (the base tables tblUsers/tblRoutes/tblSettings/… are
#      created ONLY by full_schema.sql, never by a numbered migration, so
#      "chain-from-truly-empty" is a fiction no code path produces). Then,
#      unless --skip-stale, clear the newest half of tblMigrations marks to
#      model a behind install and run Portal\Core\Migrator's catch-up
#      (run_all_migrations). Every pending migration must re-apply without
#      error over the installed base (HARD gate on any failure), and the
#      COUNTS of tables, columns, indexes and recorded migrations must equal
#      phase 1's (also a HARD gate — same base, same guarded migrations, so a
#      different count is a real fault in a migration's guard). Counts only:
#      this phase does not compare anything by name.
#   5. Upgrade from the last release — install the last release's own
#      web/_sql/ (its install script, then its own migrations, as that
#      release's installer did), then run this checkout's pending migrations
#      the way Portal\Core\Migrator::runAll() does: in order, stopping at the
#      first failure. The result must equal a fresh install (see "the rule"
#      beside compare_with_fresh_install). "The last release" is the main
#      branch (owner's decision, 25 September 2026, kept on 12 October):
#      release tags stopped in May 2026, so no tag can name it. Change it with
#      E2E_BASE_REF. It catches what goes wrong AFTER the last release. It
#      cannot catch a fault main is already past.
#   6. Upgrade from the oldest install script — the same, starting from the
#      frozen install script of 6 March 2026
#      (fixtures/full_schema-2026-03-06.sql). That file holds March's 19 base
#      tables only; every other table is built by TODAY's copies of
#      migrations 007 onwards. So this is "March's base tables, then today's
#      migrations", not a copy of anybody's database. It is the phase that
#      catches faults of the #553 / #555 kind. The file's body is checked
#      against a checksum first, so an edit to it fails the phase. Kept as a
#      permanent test (owner's decision 9, 12 October 2026).
#   7. The installer's "carry on with what is there", over an OLDER database
#      whose two time-zone places differ (#604). That choice loads TODAY's
#      install script over the database and then every numbered migration,
#      ignoring which have run. The older database here is March's install
#      script taken up to migration 216: it has the old `site.timezone`
#      setting and the old `eventTimezone` column, and has never seen
#      migration 217. Its setting is put on Pacific/Auckland while its
#      organisation's row says UTC, and a second organisation is given a row
#      (New York) and a setting of its own (Paris). Afterwards each
#      organisation's row must hold the zone its pages were showing, the old
#      setting must be gone and the mark there; a second pass must change
#      nothing. WHY IT IS HERE: the install script once wrote migration 217's
#      mark first, so 217 took every organisation for already brought into
#      line, copied nothing and deleted the setting. Pages went from Auckland
#      to UTC, with nothing to say so. Phases 1 to 6 could not see it: none
#      of them takes this path over a database whose two places differ.
#      THE SAME PHASE, FOR MIGRATION 220 (#622). The older database is also
#      given two two-step backup codes, two activity records and a consent
#      row written the old way (a session identifier in each, a copy of the
#      session in the log). Migration 220 deletes every backup code ONCE,
#      and "once" is decided by its own record in tblMigrations. The install
#      script must therefore NOT record it in a database that holds a code,
#      or the codes would survive this path. Afterwards: no code, the
#      migration recorded, the consent row a fingerprint, and the activity
#      records NOT marked as clean (Portal\Core\ActivityLogCleaner cleans
#      them from the Upgrade page; nothing in SQL does). Then a code is added
#      as if a person had made a new set, and the second pass must leave it.
#      Phase 1 says the other half: in a NEW database the install script
#      records migration 220 and marks the log as clean, with nothing to do.
#      AND, AT THE END OF THE PHASE, the one case where the install script's
#      two questions differ: a database with a backup code and an EMPTY
#      activity log. There the record must still be withheld (a code is
#      there to delete) although the empty log is rightly marked clean. Added
#      27 October 2026: the install script asking whether the LOG is empty,
#      rather than whether a CODE exists, is right in every other case, so
#      nothing here or in the self-test could see it.
#
# And three DATA checks, between phases 4 and 5. Migration 210 holds one
# statement that changes stored data: it empties an attendance session's
# pointer to an event that no longer exists. The install script holds the same
# statement. The phases compare the shape of the database and its seeded
# rows; none of them puts a row in that table, so a version of the statement
# that emptied EVERY pointer passed all six there were at the time.
#   - check_dead_pointer_cleanup, run twice: once with migration 210 and once
#     with the install script. Each plants three sessions and looks at every
#     column of every session afterwards.
#   - check_missing_events_table: with tblEvents taken away, both files must
#     stop and change nothing.
#
# Exit:
#   0 — all phases and the data checks pass (the warnings noted above do not
#   affect this).
#   non-zero — phases 1 to 4 and the data checks stop the run at the first
#   hard failure. Phases 5, 6 and 7 ALL always run, because they start from
#   different databases and one failing says nothing about the others; the
#   run then fails if any did.
#
# Requires: docker + git + bash 4+. Which MySQL it runs against comes from the
# E2E_MYSQL_VERSION environment variable — an EXACT version such as 8.0.36
# or 8.4.11, defaulting to 8.0.36 (the version this harness has always
# used; docker-compose.yml reads the same variable with the same default,
# and the two are kept in step). The pull-request check runs both 8.0.36
# and 8.4.11, side by side (owner's decision, 24 September 2026). Which
# MySQL 8 line production itself runs is not confirmed — see #475.
#
# Usage:
#   tools/e2e-migrations/run.sh                # run all seven phases and the data checks on MySQL 8.0.36
#   E2E_MYSQL_VERSION=8.4.11 tools/e2e-migrations/run.sh   # the same, on MySQL 8.4.11
#   E2E_BASE_REF=<commit, tag or branch> tools/e2e-migrations/run.sh   # phase 5 starts from there instead of origin/main
#     (phase 5's named exceptions, KNOWN_P5_*, are written for origin/main: from
#     another starting point one of them may not be seen, and that fails the phase)
#   tools/e2e-migrations/run.sh --skip-stale   # skip the phase-4 catch-up simulation
#   tools/e2e-migrations/run.sh --keep         # leave the container up afterwards
#
# Run one version at a time on one machine: both use the same container
# name (portal-e2e-mysql) and the same host port (33069).
# -----------------------------------------------------------------------------

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SQL_DIR="${REPO_ROOT}/web/_sql"
COMPOSE_FILE="${SCRIPT_DIR}/docker-compose.yml"

# Phase 5's starting point: the last release. See the header for why that is
# the main branch and not a tag.
E2E_BASE_REF="${E2E_BASE_REF:-origin/main}"
# Phase 6's starting point: a frozen copy of the oldest install script that
# still loads on MySQL 8. Never edit that file; its header says why.
OLDEST_SCHEMA="${SCRIPT_DIR}/fixtures/full_schema-2026-03-06.sql"
# The checksum of that file's BODY: everything below the first line made only
# of hashes, so its header can be reworded without touching this. Phase 6
# refuses to run when the body does not match, and also when any line of the
# header is not a comment (see phase 6 for why).
#
# Why: a planted change to one line of the body (a password length of 8 made
# 12) passed all six phases on 12 October 2026. Nothing guarded the file, and
# the proof in its header needed a commit that GitHub's one-commit checkout
# does not have. This needs no history: `git hash-object` only reads the text
# it is given. The number is git's own name for the original file, so anybody
# with the full history can confirm it:
#   git rev-parse 6f93394:web/sql/full_schema.sql
#
# What it cannot do: tell a deliberate change from a careless one. Whoever
# changes this number is saying "phase 6 now starts from a different
# database", and should say so in the commit.
FIXTURE_BODY_CHECKSUM="957d8e5ccd186432ddb6b6a94c789f9a35596195"

DB_HOST="127.0.0.1"
DB_PORT="33069"
DB_USER="root"
DB_PASS="e2e-root"
DB_NAME="portal_e2e"

# Which MySQL to test against — an EXACT version, because the check below
# compares it with what the server reports. 8.0.36 is the default because
# it is what this harness has always used. docker-compose.yml reads the
# same variable and carries the same default: keep the two in step.
export E2E_MYSQL_VERSION="${E2E_MYSQL_VERSION:-8.0.36}"

# Which database SERVER to test against: "mysql" (the default, and what this
# harness has always used) or "mariadb". ONE place decides what that choice
# means — the image to pull and the two client programs to call — because
# MariaDB 11.x NO LONGER SHIPS the `mysql` and `mysqladmin` compatibility
# symlinks earlier versions had; only `mariadb` and `mariadb-admin` exist. So
# a run.sh with `mysql` typed into it cannot talk to a MariaDB container at
# all, which is why this harness could not run on MariaDB before 28 October
# 2026 (#487). Measured against mariadb:11.4, not assumed.
export E2E_DB_ENGINE="${E2E_DB_ENGINE:-mysql}"
case "${E2E_DB_ENGINE}" in
    mysql)   E2E_DB_CLIENT="mysql";   E2E_DB_ADMIN="mysqladmin" ;;
    mariadb) E2E_DB_CLIENT="mariadb"; E2E_DB_ADMIN="mariadb-admin" ;;
    *) echo "✗ E2E_DB_ENGINE must be 'mysql' or 'mariadb', not '${E2E_DB_ENGINE}'" >&2; exit 64 ;;
esac
# What compose itself reads is E2E_DB_ENGINE (the image repository is the
# engine's own name on Docker Hub) and E2E_DB_ADMIN (its healthcheck calls the
# admin program by name). E2E_DB_CLIENT is for this script only, but all three
# are exported together so the set cannot drift apart.
export E2E_DB_CLIENT E2E_DB_ADMIN

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
    docker exec -i portal-e2e-mysql "${E2E_DB_CLIENT}" -u"${DB_USER}" -p"${DB_PASS}" -h localhost --skip-column-names "${DB_NAME}" -e "$1"
}

mysql_file() {
    docker exec -i portal-e2e-mysql "${E2E_DB_CLIENT}" -u"${DB_USER}" -p"${DB_PASS}" -h localhost "${DB_NAME}" < "$1"
}

# Drops and recreates DB_NAME so each phase that needs a clean slate starts
# from a genuinely empty database (also self-healing across --keep re-runs).
reset_db() {
    docker exec -i portal-e2e-mysql "${E2E_DB_CLIENT}" -u"${DB_USER}" -p"${DB_PASS}" -e \
        "DROP DATABASE IF EXISTS ${DB_NAME}; CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
}

wait_for_mysql() {
    echo -n "→ Waiting for ${E2E_DB_ENGINE} to be ready (authenticated) "
    # NB: `mysqladmin ping` reports success even when auth is denied (an
    # "access denied" reply still proves the server is alive), so it goes
    # green before the MySQL image has finished its first-boot init — the
    # root password isn't set yet and the first real query then fails with
    # ERROR 1045. Poll an AUTHENTICATED query over TCP instead: the image's
    # temporary init server runs with --skip-networking, so a TCP connect
    # only succeeds once the real, fully-initialised server is up.
    for i in $(seq 1 90); do
        if docker exec portal-e2e-mysql "${E2E_DB_CLIENT}" -u"${DB_USER}" -p"${DB_PASS}" \
                -h 127.0.0.1 --protocol=TCP -e "SELECT 1;" >/dev/null 2>&1; then
            echo " ready."
            return 0
        fi
        echo -n "."
        sleep 1
    done
    echo " timeout!" >&2
    # Name the client program. This is the message a real engine/client mix-up
    # produces — the engine check further down never gets the chance to speak,
    # because this loop runs first and a missing client program looks exactly
    # like a server that never came up. Without this line the run just dumped
    # the server log, which says nothing about the real cause.
    echo "→ Could not reach the server with '${E2E_DB_CLIENT}'. Is that the right client for a '${E2E_DB_ENGINE}' image? (MariaDB 11.x has no 'mysql' program; MySQL has no 'mariadb' one.)" >&2
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
    # NOTE: this guard against full_schema.sql is already unreachable in
    # practice — the only caller, run_all_migrations(), loops over
    # "${SQL_DIR}"/[0-9]*.sql, and full_schema.sql has no leading digit — but
    # it is left in place as a second line of defence. A matching guard for
    # demo_data.sql used to sit here too; that file is gone (#498 moved demo
    # data creation into PHP, recorded in tblDemoDataRegister instead of a
    # SQL file the installer or Migrator would ever see), so there is
    # nothing left for that half of the check to protect against.
    if [[ "${name}" == "full_schema.sql" ]]; then
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
    # NOTE: a plain glob, deliberately — NOT `$(ls … | sort)`.
    #
    # Command substitution splits its result on spaces, so a checkout whose
    # path contains a space (for example "…/Coding & Development/…") was torn
    # into fragments and every migration reported as FAILED. A glob does not
    # split, and bash already returns glob matches in sorted order, which for
    # three-digit-prefixed names is the same order the installer uses.
    for file in "${SQL_DIR}"/[0-9]*.sql; do
        [[ -e "${file}" ]] || continue
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

# $1 (optional) = the folder of migrations to replay. Phases 2 and 3 give
# none and get this checkout's. Phase 5 gives the last release's folder, to
# build that release's database the way its own installer did.
replay_all_migrations() {
    local dir="${1:-${SQL_DIR}}"
    local applied=0
    local failed=0
    # NOTE: a plain glob, deliberately — NOT `$(ls … | sort)`.
    #
    # Command substitution splits its result on spaces, so a checkout whose
    # path contains a space (for example "…/Coding & Development/…") was torn
    # into fragments and every migration reported as FAILED. A glob does not
    # split, and bash already returns glob matches in sorted order, which for
    # three-digit-prefixed names is the same order the installer uses.
    for file in "${dir}"/[0-9]*.sql; do
        [[ -e "${file}" ]] || continue
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
# Upgrade chain (phases 5 + 6) — the working tree's migrations, run the way
# Portal\Core\Migrator::runAll() runs them on a customer's database: the
# pending ones only (worked out once, before the first one runs), in filename
# order, STOPPING AT THE FIRST FAILURE. A failed migration is not recorded, so
# the next upgrade tries it again.
#
# Why not reuse run_all_migrations() above: that one carries on after a
# failure and counts them. A real upgrade does not carry on, and #555 is
# exactly "the upgrade stops at 124 and nothing after it ever runs".
#
# What this cannot show: the Migrator sends a whole file through PHP's
# multi_query; this sends it through the mysql command-line client. The two
# split and report statements differently, so a file that only one of them
# trips over is invisible here.
# -----------------------------------------------------------------------------
upgrade_pending_migrations() {
    local applied=0
    local skipped=0
    local file name
    local done_list="${SNAP_DIR}/upgrade_done.txt"
    local err_file="${SNAP_DIR}/upgrade_err.txt"
    if ! mysql_q "SELECT filename FROM tblMigrations;" > "${done_list}"; then
        echo "  ✗ could not read tblMigrations, so there is no telling which migrations are pending" >&2
        return 1
    fi
    # Plain glob, same reason as in run_all_migrations: a path with a space.
    for file in "${SQL_DIR}"/[0-9]*.sql; do
        [[ -e "${file}" ]] || continue
        name="$(basename "${file}")"
        if grep -Fxq -- "${name}" "${done_list}"; then
            skipped=$((skipped + 1))
            continue
        fi
        if mysql_file "${file}" 2> "${err_file}"; then
            mysql_q "INSERT IGNORE INTO tblMigrations (filename) VALUES ('${name}');" >/dev/null 2>&1 || true
            applied=$((applied + 1))
        else
            echo "  ✗ the upgrade STOPPED at ${name}, after ${applied} migration(s) had run. The database said:" >&2
            grep -v 'Using a password on the command line' "${err_file}" | sed 's/^/      /' >&2 || true
            echo "    Nothing after ${name} was run, exactly as on a customer's database." >&2
            return 1
        fi
    done
    echo "  applied=${applied} skipped=${skipped} failed=0"
    return 0
}

# -----------------------------------------------------------------------------
# The inventory (phases 2, 5 + 6) — everything about a database that the
# comparisons below look at, as seven sorted text files, one line per thing.
# The older checks compare COUNTS, which is how a renamed key or a link missing
# on one side went unseen: the counts still matched.
#
#   columns     table | column | type | may be empty | default | extra
#   indexes     table | key | position in key | column | unique or not
#   links       table | link | position | column | target table | target column | on delete | on update
#   routes      address | file that answers it | needs sign-in
#   settings    setting | organisation (or "every organisation")
#   values      setting | organisation | the value stored for it
#   migrations  file name
#
# `settings` and `values` are kept apart on purpose. Whether a setting EXISTS
# and what it HOLDS are judged by different rules, each with its own named
# exceptions, and one list could not serve both.
#
# NOT looked at: the order of columns in a table, comments, character sets and
# collations, the formula of a worked-out column, table options such as the
# storage engine, a setting's stored DEFAULT (as opposed to its value), and
# the contents of every table other than the three above.
#
# $1 = a label for the files ("alone", "fresh", "p5", "p6").
# -----------------------------------------------------------------------------
INVENTORY_KINDS=(columns indexes links routes settings values migrations)
dump_inventory() {
    local label="$1"
    local kind
    mysql_q "SELECT CONCAT_WS(' | ', table_name, column_name, column_type, is_nullable,
                    IFNULL(column_default, '(no default)'), extra)
             FROM information_schema.columns
             WHERE table_schema = DATABASE();" > "${SNAP_DIR}/${label}_columns.raw" || return 1
    mysql_q "SELECT CONCAT_WS(' | ', table_name, index_name, seq_in_index,
                    IFNULL(column_name, '(expression)'),
                    IF(non_unique = 0, 'unique', 'not unique'))
             FROM information_schema.statistics
             WHERE table_schema = DATABASE();" > "${SNAP_DIR}/${label}_indexes.raw" || return 1
    # ⚠ "RESTRICT" and "NO ACTION" are FOLDED TOGETHER here, and the
    # fold is deliberate. They name the SAME rule in InnoDB on both engines —
    # each rejects the change at once — but the two servers write a rule that
    # was LEFT OUT of the statement differently: MySQL 8.0 and 8.4 record
    # "NO ACTION", MariaDB 11.4 records "RESTRICT". Measured on 28 October
    # 2026 (#487) on all three, with the clause given and left out, because
    # the first guess — that the servers normalise what they are told — was
    # WRONG: both record a DECLARED rule faithfully, and only the default for
    # an omitted clause differs. Migration 179's two links to tblVenues and
    # tblVenueRooms give ON DELETE SET NULL and no ON UPDATE at all, so they
    # were the pair that showed it: the names pinned in KNOWN_REPLAY_ONLY_LINKS
    # could only ever match one engine.
    # IT PRINTS THE PAIR, "NO ACTION/RESTRICT", rather than picking one word.
    # Folding to the single word "NO ACTION" was tried first and was worse: 26
    # links in full_schema.sql DECLARE "ON DELETE RESTRICT", so a failure line
    # would have read "on delete NO ACTION" for a constraint whose SQL plainly
    # says RESTRICT, and anybody grepping the schema for the word in the
    # message would have found nothing. The comparison is exactly as strong
    # either way — both sides are folded the same — but only the pair is TRUE
    # at the point somebody reads it.
    # WHAT THIS GIVES UP, said plainly: the harness now CANNOT TELL an
    # explicitly declared RESTRICT from NO ACTION, and 54 places in web/_sql
    # do declare RESTRICT. That costs nothing real, because changing one word
    # to the other changes no behaviour — but it does mean this harness is
    # not the place to catch such an edit. It cannot distinguish them anyway:
    # on MariaDB an omitted clause and an explicit RESTRICT are the same text.
    mysql_q "SELECT CONCAT_WS(' | ', k.table_name, k.constraint_name, k.ordinal_position, k.column_name,
                    k.referenced_table_name, k.referenced_column_name,
                    CONCAT('on delete ', IF(r.delete_rule IN ('RESTRICT', 'NO ACTION'), 'NO ACTION/RESTRICT', r.delete_rule)),
                    CONCAT('on update ', IF(r.update_rule IN ('RESTRICT', 'NO ACTION'), 'NO ACTION/RESTRICT', r.update_rule)))
             FROM information_schema.key_column_usage k
             JOIN information_schema.referential_constraints r
               ON r.constraint_schema = k.constraint_schema
              AND r.constraint_name   = k.constraint_name
              AND r.table_name        = k.table_name
             WHERE k.table_schema = DATABASE()
               AND k.referenced_table_name IS NOT NULL;" > "${SNAP_DIR}/${label}_links.raw" || return 1
    mysql_q "SELECT CONCAT_WS(' | ', routeKey, targetFile, IFNULL(isProtected, 'NULL'))
             FROM tblRoutes;" > "${SNAP_DIR}/${label}_routes.raw" || return 1
    mysql_q "SELECT CONCAT_WS(' | ', settingKey, IFNULL(siteID, 'every organisation'))
             FROM tblSettings;" > "${SNAP_DIR}/${label}_settings.raw" || return 1
    # The mysql client writes a line break or a tab inside a value as \n or
    # \t when it is not talking to a screen, so each setting stays one line.
    mysql_q "SELECT CONCAT_WS(' | ', settingKey, IFNULL(siteID, 'every organisation'),
                    IFNULL(settingValue, '(no value)'))
             FROM tblSettings;" > "${SNAP_DIR}/${label}_values.raw" || return 1
    mysql_q "SELECT filename FROM tblMigrations;" > "${SNAP_DIR}/${label}_migrations.raw" || return 1
    # Sorted by byte value, not by the machine's language settings, so that
    # comm (which needs both sides in the same order) agrees on every machine.
    for kind in "${INVENTORY_KINDS[@]}"; do
        LC_ALL=C sort "${SNAP_DIR}/${label}_${kind}.raw" > "${SNAP_DIR}/${label}_${kind}.txt" || return 1
    done
    return 0
}

# -----------------------------------------------------------------------------
# Two small helpers for the comparisons below.
# -----------------------------------------------------------------------------

# row_changes <list before> <list after> <n>
# Compares two of the lists above ROW BY ROW rather than line by line. The
# first <n> fields of a line name the row (1 for an address; 2 for a setting
# and its organisation) and the rest is what the row holds. Prints one line per
# row that differs, in parts:
#   changed  name  held before  held after
#   removed  name  held before
#   added    name  held after
# The parts are separated by the "unit separator" character (\037), read back
# with IFS=$'\037'. Not a TAB: bash's `read` folds two TABs in a row into one,
# so a setting whose value is EMPTY lost its empty part and the value after it
# was reported as the value before. (Caught by trying it on made-up lists,
# 12 October 2026, before any real setting hit it.)
# Line by line (comm) cannot tell "this address now points somewhere else"
# from "one address went and another came", and the two need different words.
# Both lists must have at least one line; every caller checks that first.
row_changes() {
    awk -v n="$3" '
        BEGIN { FS = " [|] " }
        function name(   i, s) { s = $1; for (i = 2; i <= n; i++) s = s " | " $i; return s }
        function held()        { return substr($0, length(name()) + 4) }
        NR == FNR { before[name()] = held(); waiting[name()] = 1; next }
        {
            k = name()
            if (k in waiting) {
                if (before[k] != held()) print "changed\037" k "\037" before[k] "\037" held()
                delete waiting[k]
            } else {
                print "added\037" k "\037" held()
            }
        }
        END { for (k in waiting) print "removed\037" k "\037" before[k] }
    ' "$1" "$2" | LC_ALL=C sort
}

# is_listed <text> <list…> — is <text> exactly one of the list's lines?
is_listed() {
    local want="$1" item
    shift
    for item in "$@"; do
        [[ "${item}" == "${want}" ]] && return 0
    done
    return 1
}

# unseen_exceptions <name of the list in this file> <what was seen, one per line> <list…>
# Every named exception below is a difference this test agrees to pass. This
# reports each one that was NOT seen in this run, and returns 1 if there is
# any. Why that is a failure and not a note: an exception that has stopped
# being true still sits in its list, and the day the same difference comes
# back, by a new fault, it is passed without a word. Nothing reported them
# until 12 October 2026.
unseen_exceptions() {
    local list_name="$1" seen="$2" item
    local bad=0
    shift 2
    for item in "$@"; do
        if ! grep -Fxq -- "${item}" <<< "${seen}"; then
            bad=1
            echo "  ✗ a named exception was NOT seen, so it is no longer true: ${item}" >&2
            echo "    Delete its line from ${list_name} in run.sh. Left there, it would pass the same difference if a new fault brought it back." >&2
        fi
    done
    return "${bad}"
}

# -----------------------------------------------------------------------------
# THE RULE for "the installer's replay leaves a new install as the install
# script made it" (phase 2).
#
# Why it exists. Phases 5 and 6 compare an upgraded database with a fresh
# install, and the fresh install is the install script PLUS the replay. So a
# migration that overwrites a row writes the same wrong thing on BOTH sides and
# the comparison sees nothing. Planted on 12 October 2026: migration 210 made
# to point the podcast address at the wrong (existing) file passed all six
# phases and three text checks. The install script ALONE is the one copy a
# migration has not touched, so it is what the replay is held against here.
#
#   Structure — columns, indexes, links — must be the same before and after
#   the replay, both ways. Otherwise a database built from the install script
#   alone (the manual route in README.md) differs from one the installer
#   built, and since the install script records every migration as run,
#   nothing would ever put that right.
#
#   Addresses — the replay must not CHANGE where one points or whether it
#   needs a sign-in, and must not REMOVE one. Hard failure.
#
#   Setting values — the replay must not CHANGE one or REMOVE a setting. Hard
#   failure. A migration that puts a default back over a stored value does it
#   again every time the installer is run over a live database, and wipes out
#   what an administrator chose.
#
#   An address or setting the replay ADDS is printed, not failed: that is a
#   migration seeding something the install script lacks, and
#   check_schema_seed_parity.py is the gate for it (the same split the
#   tblMigrations count above uses).
#
# The named exceptions. Measured on 12 October 2026, on MySQL 8.0.36 and
# 8.4.11: the replay changes no address, and exactly the five things below.
# All five are older than this rule and each is a real difference, kept in
# view here because fixing them means editing migrations this piece of work
# did not own. Each is matched WORD FOR WORD, so a new difference cannot hide
# behind an old one. When one of them is fixed, delete its line: the phase
# FAILS while a line here names a difference that was not seen
# (unseen_exceptions above). These two lists belong to phase 2 alone.
#
#   Two links that migration 179 adds and the install script does not declare.
#   The same gap #554 was for fk_att_sess_event, which the install script now
#   adds itself.
KNOWN_REPLAY_ONLY_LINKS=(
    "tblEvents | fk_event_room | 1 | roomID | tblVenueRooms | roomID | on delete SET NULL | on update NO ACTION/RESTRICT"
    "tblEvents | fk_event_venue | 1 | venueID | tblVenues | venueID | on delete SET NULL | on update NO ACTION/RESTRICT"
)
#   Three apps the install script seeds as switched OFF and migrations 008,
#   009 and 017 switch ON, because they write the value again when the row is
#   already there. So every new install starts with them on, and running the
#   installer again over a live database switches them back on.
KNOWN_REPLAY_VALUE_CHANGES=(
    "attendance.enabled | every organisation | false → true"
    "calendar.enabled | every organisation | false → true"
    "leadership.enabled | every organisation | false → true"
)
#
# Needs the inventories "alone" (after phase 1) and "fresh" (after phase 2).
# Returns 0 when the rule holds, 1 when it does not.
# -----------------------------------------------------------------------------
compare_replay_with_install_script_alone() {
    local kind missing extra line what name before after
    local bad=0
    local unexpected=""
    local known=""
    local added=""

    for kind in columns indexes links; do
        missing="$(LC_ALL=C comm -23 "${SNAP_DIR}/alone_${kind}.txt" "${SNAP_DIR}/fresh_${kind}.txt")"
        extra="$(LC_ALL=C comm -13 "${SNAP_DIR}/alone_${kind}.txt" "${SNAP_DIR}/fresh_${kind}.txt")"
        unexpected=""
        known=""
        while IFS= read -r line; do
            [[ -z "${line}" ]] && continue
            if [[ "${kind}" == "links" ]] && is_listed "${line}" ${KNOWN_REPLAY_ONLY_LINKS[@]+"${KNOWN_REPLAY_ONLY_LINKS[@]}"}; then
                known+="${line}"$'\n'
            else
                unexpected+="${line}"$'\n'
            fi
        done <<< "${extra}"
        if [[ -n "${missing}" || -n "${unexpected}" ]]; then
            bad=1
            echo "  ✗ ${kind}: the install script alone and the install script plus the replay differ:" >&2
            [[ -n "${missing}" ]]    && { echo "    ── in the install script, GONE after the replay ──" >&2;                         sed 's/^/      - /' <<< "${missing}" >&2; }
            [[ -n "${unexpected}" ]] && { echo "    ── added by the replay, MISSING from the install script (full_schema.sql) ──" >&2; sed '/^$/d; s/^/      + /' <<< "${unexpected}" >&2; }
        else
            echo "  ✓ ${kind}: the replay leaves them as the install script made them ($(wc -l < "${SNAP_DIR}/alone_${kind}.txt" | tr -d ' ') lines)"
        fi
        if [[ -n "${known}" ]]; then
            echo "  ⚠ ${kind} the replay adds and the install script lacks — known and named in run.sh, not a failure:"
            sed '/^$/d; s/^/      + /' <<< "${known}"
        fi
        if [[ "${kind}" == "links" ]]; then
            unseen_exceptions "KNOWN_REPLAY_ONLY_LINKS" "${known}" ${KNOWN_REPLAY_ONLY_LINKS[@]+"${KNOWN_REPLAY_ONLY_LINKS[@]}"} || bad=1
        fi
    done

    # Addresses, then setting values, row by row.
    for kind in routes values; do
        unexpected=""
        known=""
        added=""
        while IFS=$'\037' read -r what name before after; do
            case "${what}" in
                changed)
                    line="${name} | ${before} → ${after}"
                    if [[ "${kind}" == "values" ]] && is_listed "${line}" ${KNOWN_REPLAY_VALUE_CHANGES[@]+"${KNOWN_REPLAY_VALUE_CHANGES[@]}"}; then
                        known+="${line}"$'\n'
                    else
                        unexpected+="CHANGED  ${line}"$'\n'
                    fi
                    ;;
                removed) unexpected+="REMOVED  ${name} | ${before}"$'\n' ;;
                added)   added+="${name} | ${before}"$'\n' ;;
            esac
        done < <(if [[ "${kind}" == "routes" ]]; then
                     row_changes "${SNAP_DIR}/alone_routes.txt" "${SNAP_DIR}/fresh_routes.txt" 1
                 else
                     row_changes "${SNAP_DIR}/alone_values.txt" "${SNAP_DIR}/fresh_values.txt" 2
                 fi)
        if [[ "${kind}" == "routes" ]]; then
            if [[ -n "${unexpected}" ]]; then
                bad=1
                echo "  ✗ addresses: the replay CHANGED or REMOVED an address the install script seeds (address | file that answers it | needs sign-in). A migration has written over the install script's row, so every install, new and upgraded, gets the migration's version:" >&2
                sed '/^$/d; s/^/      /' <<< "${unexpected}" >&2
            else
                echo "  ✓ addresses: the replay changes and removes none ($(wc -l < "${SNAP_DIR}/alone_routes.txt" | tr -d ' ') lines)"
            fi
        else
            if [[ -n "${unexpected}" ]]; then
                bad=1
                echo "  ✗ setting values: the replay CHANGED a stored value or REMOVED a setting (setting | organisation | value). A migration that does this undoes an administrator's choice every time the installer runs over a live database:" >&2
                sed '/^$/d; s/^/      /' <<< "${unexpected}" >&2
            else
                echo "  ✓ setting values: the replay changes none${known:+ but the named ones below}, and removes none ($(wc -l < "${SNAP_DIR}/alone_values.txt" | tr -d ' ') lines)"
            fi
            if [[ -n "${known}" ]]; then
                echo "  ⚠ setting values the replay changes — known and named in run.sh, not a failure:"
                sed '/^$/d; s/^/      ~ /' <<< "${known}"
            fi
            unseen_exceptions "KNOWN_REPLAY_VALUE_CHANGES" "${known}" ${KNOWN_REPLAY_VALUE_CHANGES[@]+"${KNOWN_REPLAY_VALUE_CHANGES[@]}"} || bad=1
        fi
        if [[ -n "${added}" ]]; then
            echo "  ⚠ ${kind} the replay ADDS and the install script lacks (printed, not a failure here — check_schema_seed_parity.py is the gate):"
            sed '/^$/d; s/^/      + /' <<< "${added}"
        fi
    done
    return "${bad}"
}

# -----------------------------------------------------------------------------
# THE RULE for "an upgraded database equals a fresh install" (phases 5 + 6).
#
#   Structure — columns, indexes, links — must match EXACTLY, both ways. One
#   thing only on either side is a hard failure. There is no list of allowed
#   exceptions, on purpose: migration 210 renamed two keys and changed one
#   default purely so that none is needed, because a list of exceptions is
#   where the next real difference hides.
#
#   Addresses must match EXACTLY, both ways. One a fresh install has and the
#   upgraded database lacks is the "page not found on every upgraded database"
#   fault (the podcast feed, found 12 October 2026). One ONLY on the upgraded
#   database is a failure too: the router answers every row of tblRoutes, so a
#   leftover address is a live page that exists on upgraded sites and nowhere
#   else. (This used to be printed and passed, with the words "nothing reads
#   it". That is untrue of an address. It is true of the one leftover setting
#   named below, and not of settings in general.)
#
#   Settings and recorded migrations — every one a fresh install has MUST
#   exist on the upgraded database: hard failure.
#
#   A setting ONLY on the upgraded database is a hard failure too, unless it
#   is named below. This used to be printed and passed, with the words "no
#   code asks for a setting it does not know about". That is untrue as a rule:
#   web/_core/ApiRouter.php builds the name `api.<app>.<action>.enabled` from
#   the address somebody asks for and switches that action on when the setting
#   holds `true`. Planted on 12 October 2026: such a setting given to upgraded
#   databases only passed every phase. The one leftover that really exists,
#   attendancecount.enabled on databases older than main, is named in phase
#   6's list; nothing reads it, and removing a customer's row to satisfy a
#   test is not worth the risk. (A recorded migration only on the upgraded
#   side is still printed and passed: the upgrade page reads that list only to
#   decide what has already run.)
#
#   Setting values — a setting on both sides must hold the SAME value: hard
#   failure. Nobody types anything into these test databases, so a different
#   value can only have come from a migration seeding the wrong thing on old
#   databases. Planted on 12 October 2026: migration 210 made to seed
#   calendar.displayName as 'Wrong name' passed all six phases, because only
#   whether the setting EXISTS was compared. (Phase 2's rule above cannot see
#   that one: on a new install the row is already there and 210 leaves it
#   alone, so the wrong value only ever lands on an old database.)
#
# The named exceptions, matched word for word, ONE LIST PER PHASE. Phases 5
# and 6 start from different databases, so a difference that is true of one
# need not be true of the other. They used to share one list, and that would
# have hidden a fault: once this work is on main, a database built from main
# holds 12 for the password length, and a later migration that put 8 back on
# upgraded sites would have been passed in phase 5 by the line kept for
# phase 6. Each phase FAILS when a line in its own list was not seen
# (unseen_exceptions above).
#
#   auth.password.minLength is 12 on a fresh install and 8 on BOTH upgraded
#   databases (measured 12 October 2026; fresh install → upgraded). Migration
#   006 seeds 8, and migration 041 raised only the stored DEFAULT to 12,
#   saying it leaves a value already in place alone. Whether an upgraded site
#   should be moved to 12 is the owner's decision and has not been taken, so
#   it is named here rather than "fixed" by a migration that changes a live
#   site's password rule.
#
#   ⚠ THE DAY THIS WORK REACHES main, PHASE 5 WILL FAIL on the line in
#   KNOWN_P5_VALUE_DIFFERENCES, on purpose: a database built from main will
#   then hold 12, the difference will not be seen, and the failure message
#   says to delete the line. Delete it then, and not before.
KNOWN_P5_VALUE_DIFFERENCES=(
    "auth.password.minLength | every organisation | 12 → 8"
)
KNOWN_P5_LEFTOVER_SETTINGS=()
KNOWN_P6_VALUE_DIFFERENCES=(
    "auth.password.minLength | every organisation | 12 → 8"
)
#   The one setting found only on a database upgraded from March's install
#   script (see the rule above for why it is named and not removed).
KNOWN_P6_LEFTOVER_SETTINGS=(
    "attendancecount.enabled | every organisation"
)
#
# $1 = the label the upgraded database's inventory is filed under: "p5" or
#      "p6". It also chooses which pair of lists above applies.
# Returns 0 when the rule holds, 1 when it does not.
# -----------------------------------------------------------------------------
compare_with_fresh_install() {
    local label="$1"
    local kind missing extra line what name before after
    local bad=0
    local unexpected=""
    local known=""
    local leftover_unexpected=""
    local leftover_known=""
    local lists=""
    local -a known_values=()
    local -a known_leftovers=()
    case "${label}" in
        p5) lists="KNOWN_P5"
            known_values=( ${KNOWN_P5_VALUE_DIFFERENCES[@]+"${KNOWN_P5_VALUE_DIFFERENCES[@]}"} )
            known_leftovers=( ${KNOWN_P5_LEFTOVER_SETTINGS[@]+"${KNOWN_P5_LEFTOVER_SETTINGS[@]}"} ) ;;
        p6) lists="KNOWN_P6"
            known_values=( ${KNOWN_P6_VALUE_DIFFERENCES[@]+"${KNOWN_P6_VALUE_DIFFERENCES[@]}"} )
            known_leftovers=( ${KNOWN_P6_LEFTOVER_SETTINGS[@]+"${KNOWN_P6_LEFTOVER_SETTINGS[@]}"} ) ;;
        *)  echo "  ✗ compare_with_fresh_install was given '${label}', and only p5 and p6 have a list of named exceptions — nothing was compared" >&2
            return 1 ;;
    esac
    if ! dump_inventory "${label}"; then
        echo "  ✗ could not read the upgraded database's inventory — nothing was compared" >&2
        return 1
    fi
    for kind in columns indexes links routes; do
        missing="$(LC_ALL=C comm -23 "${SNAP_DIR}/fresh_${kind}.txt" "${SNAP_DIR}/${label}_${kind}.txt")"
        extra="$(LC_ALL=C comm -13 "${SNAP_DIR}/fresh_${kind}.txt" "${SNAP_DIR}/${label}_${kind}.txt")"
        if [[ -n "${missing}" || -n "${extra}" ]]; then
            bad=1
            echo "  ✗ ${kind} differ from a fresh install:" >&2
            [[ -n "${missing}" ]] && { echo "    ── on a fresh install, MISSING after the upgrade ──" >&2; sed 's/^/      - /' <<< "${missing}" >&2; }
            [[ -n "${extra}" ]]   && { echo "    ── after the upgrade, NOT on a fresh install ──" >&2;    sed 's/^/      + /' <<< "${extra}" >&2; }
            if [[ "${kind}" == "routes" && -n "${extra}" ]]; then
                echo "    (an address only on the upgraded database is a live page there: the router answers every row)" >&2
            fi
        else
            echo "  ✓ ${kind}: identical to a fresh install ($(wc -l < "${SNAP_DIR}/${label}_${kind}.txt" | tr -d ' ') lines)"
        fi
    done
    for kind in settings migrations; do
        missing="$(LC_ALL=C comm -23 "${SNAP_DIR}/fresh_${kind}.txt" "${SNAP_DIR}/${label}_${kind}.txt")"
        extra="$(LC_ALL=C comm -13 "${SNAP_DIR}/fresh_${kind}.txt" "${SNAP_DIR}/${label}_${kind}.txt")"
        if [[ -n "${missing}" ]]; then
            bad=1
            echo "  ✗ ${kind}: a fresh install has these and the upgraded database does not:" >&2
            sed 's/^/      - /' <<< "${missing}" >&2
        else
            echo "  ✓ ${kind}: every one a fresh install has is there ($(wc -l < "${SNAP_DIR}/fresh_${kind}.txt" | tr -d ' ') lines)"
        fi
        if [[ "${kind}" == "migrations" ]]; then
            if [[ -n "${extra}" ]]; then
                echo "  ⚠ migrations recorded on the upgraded database only (printed, not a failure — see the rule in run.sh):"
                sed 's/^/      + /' <<< "${extra}"
            fi
            continue
        fi
        # A setting only on the upgraded database: named, or a failure.
        while IFS= read -r line; do
            [[ -z "${line}" ]] && continue
            if is_listed "${line}" ${known_leftovers[@]+"${known_leftovers[@]}"}; then
                leftover_known+="${line}"$'\n'
            else
                leftover_unexpected+="${line}"$'\n'
            fi
        done <<< "${extra}"
        if [[ -n "${leftover_unexpected}" ]]; then
            bad=1
            echo "  ✗ settings left over on the upgraded database ONLY (setting | organisation). A fresh install has no such setting, so upgraded sites would behave differently from new ones; a setting named api.<app>.<action>.enabled switches that API action on. Either a migration is seeding something the install script lacks, or this is a known leftover that belongs in ${lists}_LEFTOVER_SETTINGS:" >&2
            sed '/^$/d; s/^/      + /' <<< "${leftover_unexpected}" >&2
        fi
        if [[ -n "${leftover_known}" ]]; then
            echo "  ⚠ settings left over on the upgraded database only — known and named in run.sh, not a failure:"
            sed '/^$/d; s/^/      + /' <<< "${leftover_known}"
        fi
        unseen_exceptions "${lists}_LEFTOVER_SETTINGS" "${leftover_known}" ${known_leftovers[@]+"${known_leftovers[@]}"} || bad=1
    done
    # Setting values, for the settings both sides have. One only on one side
    # was dealt with just above, so "added" and "removed" are passed over.
    while IFS=$'\037' read -r what name before after; do
        [[ "${what}" == "changed" ]] || continue
        line="${name} | ${before} → ${after}"
        if is_listed "${line}" ${known_values[@]+"${known_values[@]}"}; then
            known+="${line}"$'\n'
        else
            unexpected+="${line}"$'\n'
        fi
    done < <(row_changes "${SNAP_DIR}/fresh_values.txt" "${SNAP_DIR}/${label}_values.txt" 2)
    if [[ -n "${unexpected}" ]]; then
        bad=1
        echo "  ✗ setting values differ from a fresh install (setting | organisation | value on a fresh install → value after the upgrade):" >&2
        sed '/^$/d; s/^/      ~ /' <<< "${unexpected}" >&2
    else
        echo "  ✓ setting values: every setting both have holds the same value${known:+, apart from the named one below}"
    fi
    if [[ -n "${known}" ]]; then
        echo "  ⚠ setting values that differ — known and named in run.sh, not a failure:"
        sed '/^$/d; s/^/      ~ /' <<< "${known}"
    fi
    unseen_exceptions "${lists}_VALUE_DIFFERENCES" "${known}" ${known_values[@]+"${known_values[@]}"} || bad=1
    return "${bad}"
}

# -----------------------------------------------------------------------------
# THE DATA CHECKS — the one statement that changes a customer's own rows.
#
# Migration 210 adds the link from an attendance session to its event (#554).
# The database refuses the link while any session points at an event that is
# gone, so 210 first empties exactly those pointers. The install script holds
# the same statements, for a database built from it before the link existed.
# That UPDATE is the riskiest line in either file, and until 12 October 2026
# nothing here tested it: with its "the event no longer exists" condition
# taken out, so that it emptied EVERY session's pointer, all six phases passed.
#
# check_dead_pointer_cleanup is run twice, once per file:
#   $1 = a short tag that keeps the two runs' planted rows apart
#        ("m210", "install")
#   $2 = the file to run
#   $3 = what to call that file in a message
# On the database it is given it takes the link away again, which is the shape
# of a database built by the install script before 12 October 2026. (Not of
# every older database: one upgraded through migration 009 always had the
# link.) It then plants three sessions — one pointing at a live event, one at
# an event that has since been deleted, one at no event — runs the file, and
# checks:
#   the first still points at its event, the second points at nothing, the
#   third is untouched;
#   EVERY OTHER COLUMN OF EVERY SESSION in the table is exactly as it was. The
#   pointer is the only thing that may change. This used to look at the note,
#   the pointer and "last changed" only, and count the rows, so an UPDATE that
#   also marked the emptied sessions as deleted (`isDeleted = 1`) passed, and
#   the check printed "no session was deleted" (planted, 12 October 2026);
#   and exactly one link from that column to tblEvents is there, the right one.
#
# Why the install script gets its own run. Its copy of the statements used to
# be compared with the migration's as text and never put to work on a row: the
# test loaded that file only into an empty database. So a fault in what stands
# AROUND the statements in that file (the question "is the link already
# there?") could not be seen. Planted on 12 October 2026: the link's name
# misspelt in that question passed every phase, and tried by hand, a second
# run of the installer over that database stopped with error 1826.
#
# Why here and not inside phase 5. Phase 5's starting point is the last
# release, and the statement only runs there while that release has neither
# the link nor migration 210. The day this work reaches main that stops being
# true, and a check planted in phase 5 would have gone blind without anybody
# noticing (or failed, because a database with the link refuses the dead
# pointer being planted). Phase 6 never could: migration 009 creates the table
# with the link already on it. Taking the link away first works on every day.
#
# The dates below are plain stored values. Nothing compares them with today's
# date, so this check does not go stale as the calendar moves on.
#
# What it cannot show: how the statement behaves on a large table, or through
# PHP's multi_query (see upgrade_pending_migrations above).
#
# Returns 0 on a pass, 1 on any failure.
# -----------------------------------------------------------------------------

# Every link from tblAttendanceSessions.eventID to tblEvents, one per line, as
# "name | on delete rule". Asked by column and target, not by name, so a second
# link under another name shows up, and so does the right name with the wrong
# rule. (The message for that used to say the database "has no
# fk_att_sess_event link (found 0)" when the link was there and deleted the
# sessions with the event.)
SESSION_EVENT_LINKS_SQL="SELECT CONCAT_WS(' | ', k.constraint_name, CONCAT('on delete ', IF(r.delete_rule IN ('RESTRICT', 'NO ACTION'), 'NO ACTION/RESTRICT', r.delete_rule)))
     FROM information_schema.key_column_usage k
     JOIN information_schema.referential_constraints r
       ON r.constraint_schema = k.constraint_schema
      AND r.constraint_name   = k.constraint_name
      AND r.table_name        = k.table_name
     WHERE k.table_schema = DATABASE()
       AND k.table_name = 'tblAttendanceSessions'
       AND k.column_name = 'eventID'
       AND k.referenced_table_name = 'tblEvents'
     ORDER BY 1;"
THE_ONE_SESSION_EVENT_LINK="fk_att_sess_event | on delete SET NULL"

# Prints every session, one per line, as "column=value | column=value | …" for
# every column of tblAttendanceSessions EXCEPT the pointer (eventID). The list
# of columns is read from the database, so a column added later is covered
# without anybody remembering to add it here.
dump_sessions_without_pointer() {
    local cols col expr=""
    cols="$(mysql_q "SELECT column_name FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'tblAttendanceSessions'
                       AND column_name <> 'eventID'
                     ORDER BY ordinal_position;")" || return 1
    # No columns would mean comparing nothing with nothing, which passes.
    [[ -n "${cols}" ]] || return 1
    while IFS= read -r col; do
        expr+="${expr:+, }CONCAT('${col}=', IFNULL(\`${col}\`, 'NULL'))"
    done <<< "${cols}"
    mysql_q "SELECT CONCAT_WS(' | ', ${expr}) FROM tblAttendanceSessions ORDER BY sessionID;"
}

check_dead_pointer_cleanup() {
    local tag="$1" file="$2" what="$3"
    local links planted found rest_before rest_after changed
    local kept_at="2020-01-06 07:08:09"
    local state_sql="SELECT CONCAT_WS(' | ', s.notes,
                            CASE WHEN s.eventID IS NULL THEN 'points at no event'
                                 WHEN e.eventSlug = 'e2e-${tag}-live-event' THEN 'points at the live event'
                                 WHEN e.eventID IS NULL THEN 'points at an event that is gone'
                                 ELSE 'points at some other event' END)
                     FROM tblAttendanceSessions s
                     LEFT JOIN tblEvents e ON e.eventID = s.eventID
                     WHERE s.notes LIKE 'e2e-${tag}-session-%'
                     ORDER BY s.notes;"
    local expected_planted expected_after
    expected_planted="e2e-${tag}-session-1-live | points at the live event
e2e-${tag}-session-2-gone | points at an event that is gone
e2e-${tag}-session-3-none | points at no event"
    expected_after="e2e-${tag}-session-1-live | points at the live event
e2e-${tag}-session-2-gone | points at no event
e2e-${tag}-session-3-none | points at no event"

    echo
    echo "═════ Data check: ${what} empties only pointers to events that are gone (#554) ═════"

    links="$(mysql_q "${SESSION_EVENT_LINKS_SQL}")" || return 1
    if [[ "${links}" != "${THE_ONE_SESSION_EVENT_LINK}" ]]; then
        echo "  ✗ before anything was planted, the database should hold exactly one link from tblAttendanceSessions.eventID to tblEvents: ${THE_ONE_SESSION_EVENT_LINK}. Found:" >&2
        sed 's/^/      /' <<< "${links:-(no link at all)}" >&2
        return 1
    fi
    echo "  Taking the link away, to stand for a database built by the install script before 12 October 2026 …"
    if ! mysql_q "ALTER TABLE tblAttendanceSessions DROP FOREIGN KEY fk_att_sess_event;"; then
        echo "  ✗ could not take the link away — nothing was tested" >&2
        return 1
    fi
    # The second event is really created and really deleted, because that is
    # how a dead pointer comes about on a customer's database.
    echo "  Planting three sessions: at a live event, at an event then deleted, at no event …"
    if ! mysql_q "SET @site := (SELECT MIN(siteID) FROM tblSites);
                  INSERT INTO tblAttendanceServiceTypes (siteID, typeName, typeSlug)
                      VALUES (@site, 'e2e data check ${tag}', 'e2e-data-check-${tag}');
                  SET @type := LAST_INSERT_ID();
                  INSERT INTO tblEvents (siteID, eventName, eventSlug, startDateTime, timezone)
                      VALUES (@site, 'e2e live event ${tag}', 'e2e-${tag}-live-event', '2020-01-05 10:00:00', 'UTC');
                  SET @live := LAST_INSERT_ID();
                  INSERT INTO tblEvents (siteID, eventName, eventSlug, startDateTime, timezone)
                      VALUES (@site, 'e2e deleted event ${tag}', 'e2e-${tag}-deleted-event', '2020-01-05 10:00:00', 'UTC');
                  SET @gone := LAST_INSERT_ID();
                  INSERT INTO tblAttendanceSessions (siteID, serviceTypeID, eventID, sessionDate, notes, updatedAt) VALUES
                      (@site, @type, @live, '2020-01-05', 'e2e-${tag}-session-1-live', '${kept_at}'),
                      (@site, @type, @gone, '2020-01-05', 'e2e-${tag}-session-2-gone', '${kept_at}'),
                      (@site, @type, NULL,  '2020-01-05', 'e2e-${tag}-session-3-none', '${kept_at}');
                  DELETE FROM tblEvents WHERE eventID = @gone;" >/dev/null; then
        echo "  ✗ could not plant the three sessions — nothing was tested" >&2
        return 1
    fi
    planted="$(mysql_q "${state_sql}")" || return 1
    if [[ "${planted}" != "${expected_planted}" ]]; then
        # A check that planted nothing would compare nothing and pass.
        echo "  ✗ the three sessions are not as planted, so the check below would prove nothing. Wanted:" >&2
        sed 's/^/      /' <<< "${expected_planted}" >&2
        echo "    found:" >&2
        sed 's/^/      /' <<< "${planted}" >&2
        return 1
    fi
    if ! rest_before="$(dump_sessions_without_pointer)" || [[ "$(grep -c "updatedAt=${kept_at}" <<< "${rest_before}")" -lt 3 ]]; then
        echo "  ✗ could not read every column of the planted sessions before the run — nothing was tested" >&2
        return 1
    fi

    echo "  Running ${what} …"
    if ! mysql_file "${file}" >/dev/null; then
        echo "  ✗ ${what} FAILED on a database holding a session that points at a deleted event" >&2
        return 1
    fi

    found="$(mysql_q "${state_sql}")" || return 1
    rest_after="$(dump_sessions_without_pointer)" || return 1
    links="$(mysql_q "${SESSION_EVENT_LINKS_SQL}")" || return 1
    if [[ "${found}" != "${expected_after}" ]]; then
        echo "  ✗ ${what} did not leave the three pointers as it should. Only the pointer at the deleted event may be emptied. Wanted:" >&2
        sed 's/^/      /' <<< "${expected_after}" >&2
        echo "    found:" >&2
        sed 's/^/      /' <<< "${found}" >&2
        return 1
    fi
    if [[ "${rest_after}" != "${rest_before}" ]]; then
        # One column per line, so the message names the column that moved.
        changed="$(diff <(awk -F' [|] ' '{ for (i = 1; i <= NF; i++) print "session row " NR ": " $i }' <<< "${rest_before}") \
                        <(awk -F' [|] ' '{ for (i = 1; i <= NF; i++) print "session row " NR ": " $i }' <<< "${rest_after}") | grep '^[<>]' || true)"
        echo "  ✗ ${what} changed something other than the pointer. Every other column of every attendance session must be exactly as it was: no \"last changed\" time moved, no session deleted or marked as deleted (< before, > after):" >&2
        sed 's/^/      /' <<< "${changed}" >&2
        return 1
    fi
    if [[ "${links}" != "${THE_ONE_SESSION_EVENT_LINK}" ]]; then
        echo "  ✗ ${what} did not leave exactly one link from tblAttendanceSessions.eventID to tblEvents (${THE_ONE_SESSION_EVENT_LINK}). Found:" >&2
        sed 's/^/      /' <<< "${links:-(no link at all)}" >&2
        return 1
    fi
    echo "  ✓ the session at a live event kept its pointer; the one at a deleted event had it emptied; the one with none is untouched"
    echo "  ✓ every other column of all $(wc -l <<< "${rest_after}" | tr -d ' ') sessions is exactly as it was, and the one link is in place"
    return 0
}

# -----------------------------------------------------------------------------
# The install script's copy of the three statements (the question "is a link
# already there?", the UPDATE and the ALTER) must stay word for word the
# migration's, or the two ways of building a database drift apart again. Both
# copies have been put to work on rows by now, so this is the cheap second
# net: it names the difference in wording, where the checks above name what a
# difference did. It comes AFTER them on purpose, for that reason.
#
# What it cannot show: that the same words DO the same thing in both files.
# They did not, once: see check_missing_events_table below.
# -----------------------------------------------------------------------------
check_install_script_wording() {
    local start_of stmt question_m question_s
    for start_of in "'UPDATE \`tblAttendanceSessions\` SET " "'ALTER TABLE \`tblAttendanceSessions\` ADD CONSTRAINT \`fk_att_sess_event\` "; do
        stmt="$(grep -F -- "${start_of}" "${SQL_DIR}/210_upgrade_gaps.sql" || true)"
        if [[ -z "${stmt}" || "${stmt}" == *$'\n'* ]]; then
            echo "  ✗ expected exactly one line in migration 210 starting ${start_of}… and found $(grep -cF -- "${start_of}" "${SQL_DIR}/210_upgrade_gaps.sql" || true), so its wording could not be compared with the install script's" >&2
            return 1
        fi
        if ! grep -Fxq -- "${stmt}" "${SQL_DIR}/full_schema.sql"; then
            echo "  ✗ the install script (full_schema.sql) does not hold this statement of migration 210 word for word, so the two no longer do the same thing:" >&2
            echo "      ${stmt}" >&2
            return 1
        fi
    done
    # The question spreads over several lines: from "SET @fk_exists := (" to
    # the line that closes it.
    question_m="$(awk '/^SET @fk_exists := \($/, /^\);$/' "${SQL_DIR}/210_upgrade_gaps.sql")"
    question_s="$(awk '/^SET @fk_exists := \($/, /^\);$/' "${SQL_DIR}/full_schema.sql")"
    if [[ -z "${question_m}" || "${question_m}" != "${question_s}" ]]; then
        echo "  ✗ migration 210 and the install script do not ask \"is a link already there?\" in the same words (SET @fk_exists := …), so one may add the link where the other would not. Migration 210:" >&2
        sed 's/^/      /' <<< "${question_m:-(not found)}" >&2
        echo "    the install script:" >&2
        sed 's/^/      /' <<< "${question_s:-(not found)}" >&2
        return 1
    fi
    echo "  ✓ the install script holds migration 210's question, UPDATE and ALTER word for word"
    return 0
}

# -----------------------------------------------------------------------------
# DATA CHECK 3 — with tblEvents MISSING, neither file may empty a pointer.
#
# The database this stands for: one that has lost tblEvents (a restore that
# got part-way, or a table dropped by hand) while attendance sessions still
# point at events. Migration 210 stops there at once: its UPDATE names a table
# that does not exist (error 1146). The install script, holding the same
# words, did the opposite when they were first copied into it (12 October
# 2026; found by an independent check the same day, before any release): it
# made tblEvents again, EMPTY, thousands of lines earlier, so its UPDATE found
# no event for any pointer and emptied every one, with no message. It now
# stops before it makes the table (see the comment above CREATE TABLE
# `tblEvents` in full_schema.sql).
#
# What it does: takes the link and then the table away from the database the
# checks above have left (which holds sessions pointing at events), runs
# migration 210 and then the install script, and for each one checks that it
# STOPPED, that it stopped with error 1146 naming tblEvents, that every column
# of every session, the pointer included, is exactly as it was, and that
# tblEvents has not been made again. That last one matters as much as the
# rows: an empty tblEvents left behind turns the next run, of either file,
# into the silent one.
#
# The database is not usable afterwards. Phase 5 starts from an empty one.
#
# Returns 0 on a pass, 1 on any failure.
# -----------------------------------------------------------------------------
check_missing_events_table() {
    local pointing before after file what made
    local err_file="${SNAP_DIR}/missing_events_err.txt"
    echo
    echo "═════ Data check: with tblEvents missing, migration 210 and the install script both stop and empty no pointer ═════"
    pointing="$(mysql_q "SELECT COUNT(*) FROM tblAttendanceSessions WHERE eventID IS NOT NULL;")" || return 1
    if [[ "${pointing}" -lt 1 ]]; then
        # With no pointer to lose, a file that emptied every pointer would pass.
        echo "  ✗ no attendance session holds a pointer, so this check would prove nothing" >&2
        return 1
    fi
    echo "  Taking the link and then tblEvents away; ${pointing} session(s) still point at events …"
    if ! mysql_q "ALTER TABLE tblAttendanceSessions DROP FOREIGN KEY fk_att_sess_event;
                  SET FOREIGN_KEY_CHECKS = 0;
                  DROP TABLE tblEvents;"; then
        echo "  ✗ could not take the link and the table away — nothing was tested" >&2
        return 1
    fi
    before="$(mysql_q "SELECT * FROM tblAttendanceSessions ORDER BY sessionID;")" || return 1
    for file in "${SQL_DIR}/210_upgrade_gaps.sql" "${SQL_DIR}/full_schema.sql"; do
        what="$(basename "${file}")"
        if mysql_file "${file}" >/dev/null 2> "${err_file}"; then
            echo "  ✗ ${what} did NOT stop on a database whose tblEvents is missing while ${pointing} session(s) point at events. It must stop and change nothing. Sessions still holding a pointer afterwards: $(mysql_q "SELECT COUNT(*) FROM tblAttendanceSessions WHERE eventID IS NOT NULL;" || echo '?') of ${pointing}" >&2
            return 1
        fi
        if ! grep -q "ERROR 1146" "${err_file}" || ! grep -q "tblEvents' doesn't exist" "${err_file}"; then
            echo "  ✗ ${what} stopped, but not with the error that says what is wrong (error 1146, table tblEvents does not exist). The database said:" >&2
            grep -v 'Using a password on the command line' "${err_file}" | sed 's/^/      /' >&2 || true
            return 1
        fi
        after="$(mysql_q "SELECT * FROM tblAttendanceSessions ORDER BY sessionID;")" || return 1
        if [[ "${after}" != "${before}" ]]; then
            echo "  ✗ ${what} stopped, but had already changed attendance sessions (< before, > after):" >&2
            diff <(echo "${before}") <(echo "${after}") | grep '^[<>]' | sed 's/^/      /' >&2 || true
            return 1
        fi
        made="$(mysql_q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tblEvents';")" || return 1
        if [[ "${made}" != "0" ]]; then
            echo "  ✗ ${what} stopped, but left a new, empty tblEvents behind. The next run of the installer, or migration 210, would then empty every pointer" >&2
            return 1
        fi
        echo "  ✓ ${what} stopped with error 1146 (tblEvents does not exist), changed no session and did not make the table again"
    done
    return 0
}

# -----------------------------------------------------------------------------
# Start
# -----------------------------------------------------------------------------

echo "→ Bringing up ${E2E_DB_ENGINE} ${E2E_MYSQL_VERSION}"
docker compose -f "${COMPOSE_FILE}" up -d --remove-orphans
wait_for_mysql

# Refuse to carry on unless the server is the version that was asked for.
# Without this, a variable that never reached docker compose would run
# 8.0.36 under an "8.4" label and pass while testing nothing new. That is
# not imagined: a planning trial on 25 September 2026 printed "Bringing up
# MySQL 8.0.36" while the container really ran 8.4.11, because this line
# used to be fixed text.
ACTUAL_MYSQL_VERSION=$(mysql_q "SELECT VERSION();")
# Compare only the leading three-part NUMBER, because a server decorates it:
# MariaDB reports "11.4.13-MariaDB-ubu2404", a Ubuntu-packaged MySQL reports
# "8.0.41-0ubuntu0.24.04.1", and some MariaDB builds still carry the fake
# "5.5.5-" prefix they add for the benefit of very old clients (stripped
# first, or the number read would be 5.5.5). This keeps the MOVING-TAG
# guarantee exactly as it was — "8.4" still does not equal "8.4.11", which is
# the mistake this check exists to catch — while letting it work on either
# engine. It is NOT strict in every way the old test was, and saying so
# plainly: comparing the whole string also refused "8.0.36-log" and
# "8.0.36-0ubuntu0.24.04.1" when 8.0.36 was asked for, and those now pass.
# That is the point — a decorated string is the same server — but it is a
# loosening, not a no-op. It used to compare the WHOLE string, which is the
# second of the two reasons this harness could not run on MariaDB at all
# (#487).
VERSION_WITHOUT_OLD_CLIENT_PREFIX="${ACTUAL_MYSQL_VERSION#5.5.5-}"
REPORTED_NUMBER="$(printf '%s' "${VERSION_WITHOUT_OLD_CLIENT_PREFIX}" \
    | sed -n 's/^\([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\).*$/\1/p')"
if [[ "${REPORTED_NUMBER}" != "${E2E_MYSQL_VERSION}" ]]; then
    echo "✗ asked for ${E2E_DB_ENGINE} ${E2E_MYSQL_VERSION}, but the server reports '${ACTUAL_MYSQL_VERSION}' — refusing to test the wrong version" >&2
    exit 1
fi
# And the ENGINE must be the one that was asked for. MariaDB puts its own name
# in the string; MySQL never does.
# ⚠ BE HONEST ABOUT WHAT THIS CAN AND CANNOT CATCH. An earlier version of
# this comment claimed it stopped a run from "pulling one engine and talking to
# it with the other's client name". It does not, and it cannot: the image
# repository in docker-compose.yml and E2E_DB_CLIENT both come from the SAME
# variable, so they can never disagree. And if a mix-up is contrived anyway (by
# retagging the image, or editing the compose file), wait_for_mysql runs FIRST
# and dies in its retry loop first — measured on 28 October 2026 by pinning the
# image to mariadb 11.x and asking for mysql, which never reached this check at
# all. It is reachable in principle: MariaDB 10.x still ships the `mysql`
# symlink, so a retagged 10.x image with a matching version number WOULD get
# past wait_for_mysql and be caught here. So this is a cheap last line of
# defence, NOT the thing that stops a mix-up; the
# message in wait_for_mysql is what a real mix-up actually produces, which is
# why that one names the client it was calling.
# It tests for one word, so it does not tell a Percona or Oracle Enterprise
# build from plain MySQL. That only matters if the compose image is pointed
# somewhere else by hand.
if [[ "${ACTUAL_MYSQL_VERSION}" == *MariaDB* && "${E2E_DB_ENGINE}" != "mariadb" ]] \
   || [[ "${ACTUAL_MYSQL_VERSION}" != *MariaDB* && "${E2E_DB_ENGINE}" == "mariadb" ]]; then
    echo "✗ asked for engine '${E2E_DB_ENGINE}', but the server reports '${ACTUAL_MYSQL_VERSION}' — refusing to test the wrong engine" >&2
    exit 1
fi
echo "  server confirmed: ${E2E_DB_ENGINE} ${ACTUAL_MYSQL_VERSION}"

# -----------------------------------------------------------------------------
# Phase 1: full_schema fresh-install
# -----------------------------------------------------------------------------
echo
echo "═════ Phase 1: full_schema fresh-install ═════"
echo "  Wiping DB and loading full_schema.sql …"
reset_db
if ! mysql_file "${SQL_DIR}/full_schema.sql"; then
    echo "✗ full_schema.sql FAILED to load against ${E2E_DB_ENGINE} ${E2E_MYSQL_VERSION} — see error above" >&2
    exit 1
fi
echo "  ✓ full_schema.sql loaded without error"

# Migration 220 (#622) on a NEW database: nothing to do. The install script
# records it (no backup code exists, so there is none to delete) and marks the
# activity log as clean (it is empty). Both are written with a test in the
# install script; phase 7 runs the other side of each test.
P1_220="$(mysql_q "SELECT CONCAT('220 recorded ', (SELECT COUNT(*) FROM tblMigrations WHERE filename = '220_activity_log_fingerprints.sql'), ' | how far ', COALESCE((SELECT settingValue FROM tblSettings WHERE settingKey = 'activityLog.cleanedThrough' AND siteID IS NULL), '(no row)'), ' | backup codes ', (SELECT COUNT(*) FROM tblTotpBackupCodes), ' | activity records ', (SELECT COUNT(*) FROM tblActivityLogs));")"
if [[ "${P1_220}" != "220 recorded 1 | how far all | backup codes 0 | activity records 0" ]]; then
    echo "✗ a NEW database must end with migration 220 recorded and its activity log marked as clean, having had nothing to do. Found: ${P1_220}" >&2
    exit 1
fi
echo "  ✓ a new database: migration 220 has nothing to do (${P1_220})"

# Migration 221 (#626) on a NEW database: nothing to link. The install script
# records it, because no record names nobody-with-session-details — there are no
# records at all. Phase 7 runs the other side of that test.
P1_221="$(mysql_q "SELECT CONCAT('221 recorded ', (SELECT COUNT(*) FROM tblMigrations WHERE filename = '221_activity_log_account_column.sql'), ' | how far ', COALESCE((SELECT settingValue FROM tblSettings WHERE settingKey = 'activityLog.cleanedThrough' AND siteID IS NULL), '(no row)'));")"
if [[ "${P1_221}" != "221 recorded 1 | how far all" ]]; then
    echo "✗ a NEW database must end with migration 221 recorded and the cleaner's mark left at \"all\": there is nothing to link. Found: ${P1_221}" >&2
    exit 1
fi
echo "  ✓ a new database: migration 221 has nothing to link (${P1_221})"

P1_TABLES=$(count_tables)
P1_COLUMNS=$(count_columns)
P1_INDEXES=$(count_indexes)
P1_MIGS=$(count_migrations)
echo "  schema: ${P1_TABLES} tables, ${P1_COLUMNS} columns, ${P1_INDEXES} indexes, ${P1_MIGS} migrations recorded"
# Snapshot the exact object inventory BEFORE replay so a phase-2 drift can be
# diffed down to the specific columns/indexes the replay introduced.
dump_columns > "${SNAP_DIR}/p1_columns.txt"
dump_indexes > "${SNAP_DIR}/p1_indexes.txt"
# And the full inventory of the install script ALONE: the one copy of the
# seeded rows that no migration has had the chance to write over. Phase 2
# holds the replay against it.
if ! dump_inventory "alone"; then
    echo "✗ could not read the install script's own inventory — phase 2 would have nothing to hold the replay against" >&2
    exit 1
fi
for kind in "${INVENTORY_KINDS[@]}"; do
    if [[ ! -s "${SNAP_DIR}/alone_${kind}.txt" ]]; then
        echo "✗ the install script's own list of ${kind} is empty — refusing to compare against nothing" >&2
        exit 1
    fi
done

# -----------------------------------------------------------------------------
# Phase 2: installer replay
# -----------------------------------------------------------------------------
# The real install flow, exactly: full_schema.sql (phase 1) followed by every
# numbered migration, ignoring tblMigrations. The replay must fail nowhere,
# leave the counts of tables, columns and indexes as they were, and leave
# every column, index, link, address and setting value as the install script
# made it, apart from five named differences.
#
# This comment used to say the replay "must be a full no-op … nothing should
# be added, changed, or removed". It never was: it adds two links and switches
# three apps on, and the count check below cannot see either.
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
    echo "✗ installer replay FAILED — the counts of tables, columns or indexes changed vs the full_schema baseline (no migration may add or remove one on an up-to-date schema)" >&2
    printf '    tables:  full_schema=%s  post-replay=%s  Δ=%+d\n' "${P1_TABLES}" "${P2_TABLES}" "$((P2_TABLES - P1_TABLES))" >&2
    printf '    columns: full_schema=%s  post-replay=%s  Δ=%+d\n' "${P1_COLUMNS}" "${P2_COLUMNS}" "$((P2_COLUMNS - P1_COLUMNS))" >&2
    printf '    indexes: full_schema=%s  post-replay=%s  Δ=%+d\n' "${P1_INDEXES}" "${P2_INDEXES}" "$((P2_INDEXES - P1_INDEXES))" >&2
    # Diff the object inventories so the fix is precise: anything a migration
    # ADDS here is an object missing from full_schema.sql (the migration guard
    # found it absent). Anything it DROPS means full_schema carries an object
    # no migration produces. Both must be reconciled into full_schema.sql so
    # no migration adds or removes a table, column or index on a fresh install.
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
    echo "  ✓ table/column/index counts identical to the full_schema baseline"
fi

# The database as it stands now is what the installer leaves behind: the
# install script plus the installer's replay of every migration. So this is
# what phases 5 and 6 compare an upgraded database with. (The install script
# alone is not quite the same thing: the replay adds two links and switches
# three apps on. The comparison just below names them.)
if ! dump_inventory "fresh"; then
    echo "✗ could not read the fresh install's inventory — phases 5 and 6 would have nothing to compare with" >&2
    exit 1
fi
for kind in "${INVENTORY_KINDS[@]}"; do
    # An empty list would make every later comparison pass while comparing
    # nothing, so it is refused here.
    if [[ ! -s "${SNAP_DIR}/fresh_${kind}.txt" ]]; then
        echo "✗ the fresh install's list of ${kind} is empty — refusing to compare against nothing" >&2
        exit 1
    fi
done
echo "  fresh-install inventory kept for phases 5 and 6: $(wc -l < "${SNAP_DIR}/fresh_columns.txt" | tr -d ' ') columns, $(wc -l < "${SNAP_DIR}/fresh_indexes.txt" | tr -d ' ') index parts, $(wc -l < "${SNAP_DIR}/fresh_links.txt" | tr -d ' ') link parts, $(wc -l < "${SNAP_DIR}/fresh_routes.txt" | tr -d ' ') addresses, $(wc -l < "${SNAP_DIR}/fresh_settings.txt" | tr -d ' ') settings, $(wc -l < "${SNAP_DIR}/fresh_migrations.txt" | tr -d ' ') migrations"

echo "  Holding the replay against the install script alone …"
if ! compare_replay_with_install_script_alone; then
    echo "✗ installer replay FAILED — the replay changed what the install script made (differences above)" >&2
    exit 1
fi

# -----------------------------------------------------------------------------
# Phase 3: idempotency re-run
# -----------------------------------------------------------------------------
# Re-run the exact same installer-replay chain a second time on top of the
# phase-2 DB. This mirrors an upgrade retry against an already-up-to-date
# schema: it must be a hard no-op. Then the install script itself is loaded a
# second time (further down), which is the other half of "somebody runs the
# installer again".
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

# The install script, a second time, over a database that already holds
# everything it makes. The installer's "carry on with the existing database"
# choice does exactly this, and until 12 October 2026 nothing here did: every
# other load of full_schema.sql in this file follows reset_db. So each "is
# this already done?" question in that file was only ever asked of an empty
# database, where the answer is always no. Planted that day: the link's name
# misspelt in the question beside fk_att_sess_event passed every phase, and
# tried by hand, a second run of the installer over that database stopped
# with error 1826 ("Duplicate foreign key constraint name").
#
# It must load with no error and leave the whole inventory as it was: every
# column, index, link, address, setting, setting value and recorded migration.
#
# What this cannot show: rows outside the inventory (dump_inventory says what
# it leaves out), or the file going through PHP's multi_query.
echo "  Loading full_schema.sql a SECOND time over the same database (the installer's \"carry on with the existing database\") …"
if ! dump_inventory "again_before"; then
    echo "✗ could not read the inventory before the second load of full_schema.sql — nothing to compare with" >&2
    exit 1
fi
if ! mysql_file "${SQL_DIR}/full_schema.sql"; then
    echo "✗ idempotency FAILED — full_schema.sql did not load a second time over a database it had built itself (error above). The installer's \"carry on with the existing database\" choice would stop with that error on every site" >&2
    exit 1
fi
if ! dump_inventory "again_after"; then
    echo "✗ could not read the inventory after the second load of full_schema.sql — nothing was compared" >&2
    exit 1
fi
AGAIN_BAD=0
for kind in "${INVENTORY_KINDS[@]}"; do
    if ! cmp -s "${SNAP_DIR}/again_before_${kind}.txt" "${SNAP_DIR}/again_after_${kind}.txt"; then
        AGAIN_BAD=1
        echo "  ✗ ${kind}: loading full_schema.sql a second time changed them (- before, + after):" >&2
        LC_ALL=C comm -23 "${SNAP_DIR}/again_before_${kind}.txt" "${SNAP_DIR}/again_after_${kind}.txt" | sed 's/^/      - /' >&2
        LC_ALL=C comm -13 "${SNAP_DIR}/again_before_${kind}.txt" "${SNAP_DIR}/again_after_${kind}.txt" | sed 's/^/      + /' >&2
    fi
done
if [[ "${AGAIN_BAD}" -ne 0 ]]; then
    echo "✗ idempotency FAILED — a second load of full_schema.sql is not a no-op (differences above)" >&2
    exit 1
fi
echo "  ✓ full_schema.sql loaded a second time without error and changed nothing in the inventory"

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
# pending migration must re-apply over the installed base with zero failures,
# and reach the fully-migrated state with the same COUNTS of tables, columns,
# indexes and recorded migrations as phase 1. (This used to say "byte-for-byte
# identical"; only counts are compared here.) Migration failures here are a
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
    # Plain glob, same reason as above — a path with a space must survive.
    all_files=( "${SQL_DIR}"/[0-9]*.sql )
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

# After catch-up the counts of tables, columns, indexes and recorded migrations
# must equal phase 1's full_schema baseline — this is a HARD gate (the catch-up
# re-applies the very same guarded migrations over the very same base, so a
# different count is a real fault in a migration's guard, not a
# chain-vs-schema structural difference).
if [[ "${P4_TABLES}" != "${P1_TABLES}" || "${P4_COLUMNS}" != "${P1_COLUMNS}" || "${P4_INDEXES}" != "${P1_INDEXES}" || "${P4_MIGS}" != "${P1_MIGS}" ]]; then
    echo "✗ Migrator catch-up parity FAILED — schema/tblMigrations drifted vs the full_schema baseline" >&2
    printf '    tables:     post-catch-up=%s  full_schema=%s  Δ=%+d\n' "${P4_TABLES}" "${P1_TABLES}" "$((P4_TABLES - P1_TABLES))" >&2
    printf '    columns:    post-catch-up=%s  full_schema=%s  Δ=%+d\n' "${P4_COLUMNS}" "${P1_COLUMNS}" "$((P4_COLUMNS - P1_COLUMNS))" >&2
    printf '    indexes:    post-catch-up=%s  full_schema=%s  Δ=%+d\n' "${P4_INDEXES}" "${P1_INDEXES}" "$((P4_INDEXES - P1_INDEXES))" >&2
    printf '    migrations: post-catch-up=%s  full_schema=%s  Δ=%+d\n' "${P4_MIGS}" "${P1_MIGS}" "$((P4_MIGS - P1_MIGS))" >&2
    exit 1
fi
echo "  ✓ Migrator catch-up reaches the full_schema baseline: the counts of tables, columns, indexes and recorded migrations all match"

# -----------------------------------------------------------------------------
# The data checks (they use the database phase 4 has just left)
# -----------------------------------------------------------------------------
# The order matters. Migration 210 first, then the install script, each on its
# own three sessions. Then the wording, so that a wrong statement is reported
# by what it did to a row. The missing-table check comes last because it
# leaves the database unusable.
if ! check_dead_pointer_cleanup "m210" "${SQL_DIR}/210_upgrade_gaps.sql" "migration 210"; then
    echo "✗ data check FAILED — migration 210's UPDATE on tblAttendanceSessions does not do exactly what it should (details above)" >&2
    exit 1
fi
if ! check_dead_pointer_cleanup "install" "${SQL_DIR}/full_schema.sql" "the install script (full_schema.sql)"; then
    echo "✗ data check FAILED — the install script's UPDATE on tblAttendanceSessions does not do exactly what it should (details above)" >&2
    exit 1
fi
if ! check_install_script_wording; then
    echo "✗ data check FAILED — the install script and migration 210 no longer say the same thing about the link (details above)" >&2
    exit 1
fi
if ! check_missing_events_table; then
    echo "✗ data check FAILED — with tblEvents missing, a file did not stop cleanly (details above)" >&2
    exit 1
fi

# -----------------------------------------------------------------------------
# Phase 5: upgrade from the last release
# -----------------------------------------------------------------------------
# Every phase above starts from TODAY's install script, so none of them can see
# what happens to a database that was installed earlier and upgraded. This one
# builds the last release's database and upgrades it with this checkout.
#
# Returns 0 on a pass, 1 on any failure. It never skips: a starting point that
# cannot be found is a failure with a plain message, because a skipped phase
# reads exactly like a passed one.
phase5_upgrade_from_last_release() {
    local base_sha branch base_dir base_sql
    echo
    echo "═════ Phase 5: upgrade from the last release (${E2E_BASE_REF}) ═════"

    base_sha="$(git -C "${REPO_ROOT}" rev-parse --verify --quiet "${E2E_BASE_REF}^{commit}" || true)"
    if [[ -z "${base_sha}" && "${E2E_BASE_REF}" == origin/* ]]; then
        # GitHub checks out ONE commit, so origin/main is not there until it
        # is fetched. One commit of it is all this phase needs.
        #
        # --depth=1 is used ONLY in a checkout that is already cut short. In a
        # full clone it would cut that clone's history short too, which is not
        # something a test may do to a developer's repository.
        branch="${E2E_BASE_REF#origin/}"
        echo "  ${E2E_BASE_REF} is not known in this checkout — fetching it …"
        if [[ "$(git -C "${REPO_ROOT}" rev-parse --is-shallow-repository 2>/dev/null)" == "true" ]]; then
            git -C "${REPO_ROOT}" fetch --depth=1 origin "${branch}:refs/remotes/origin/${branch}" || true
        else
            git -C "${REPO_ROOT}" fetch origin "${branch}:refs/remotes/origin/${branch}" || true
        fi
        base_sha="$(git -C "${REPO_ROOT}" rev-parse --verify --quiet "${E2E_BASE_REF}^{commit}" || true)"
    fi
    if [[ -z "${base_sha}" ]]; then
        echo "✗ phase 5 FAILED — '${E2E_BASE_REF}' names no commit in this checkout, and fetching it did not help. Set E2E_BASE_REF to a commit, tag or branch that exists. (Not skipped on purpose: a skipped phase looks like a passed one.)" >&2
        return 1
    fi
    echo "  starting point: ${E2E_BASE_REF} = ${base_sha}"

    base_dir="${SNAP_DIR}/base"
    base_sql="${base_dir}/web/_sql"
    mkdir -p "${base_dir}"
    if ! git -C "${REPO_ROOT}" archive "${base_sha}" web/_sql | tar -x -C "${base_dir}" || [[ ! -f "${base_sql}/full_schema.sql" ]]; then
        echo "✗ phase 5 FAILED — could not take web/_sql/ out of ${E2E_BASE_REF} (${base_sha})" >&2
        return 1
    fi

    echo "  Installing the last release the way its own installer does: its install script, then its own migrations …"
    if ! reset_db || ! mysql_file "${base_sql}/full_schema.sql"; then
        echo "✗ phase 5 FAILED — the last release's own install script did not load" >&2
        return 1
    fi
    if ! replay_all_migrations "${base_sql}"; then
        echo "✗ phase 5 FAILED — the last release's own migrations did not replay over its own install script" >&2
        return 1
    fi
    echo "  last release installed: $(count_tables) tables, $(count_columns) columns, $(count_indexes) indexes, $(count_migrations) migrations recorded"

    echo "  Upgrading it with this checkout's migrations (pending only, in order, stopping at the first failure) …"
    if ! upgrade_pending_migrations; then
        echo "✗ phase 5 FAILED — a database installed from ${E2E_BASE_REF} cannot be upgraded to this checkout" >&2
        return 1
    fi
    echo "  schema: $(count_tables) tables, $(count_columns) columns, $(count_indexes) indexes, $(count_migrations) migrations recorded"
    if ! compare_with_fresh_install "p5"; then
        echo "✗ phase 5 FAILED — a database upgraded from ${E2E_BASE_REF} is not the same as a fresh install (differences above)" >&2
        return 1
    fi
    echo "  ✓ a database upgraded from the last release equals a fresh install"
    return 0
}

# -----------------------------------------------------------------------------
# Phase 6: upgrade from the oldest install script
# -----------------------------------------------------------------------------
# Phase 5 cannot catch a fault the last release is already past, and #553,
# #554 and #555 were all of that kind. This phase starts from the install
# script of 6 March 2026, which is the oldest one that still loads on MySQL 8,
# and runs every migration that file does not record as run.
#
# What that makes it: March's 19 base tables, then TODAY's copies of
# migrations 007 onwards. The old categories key (#553) and the missing
# capacityCount column (#555) are not in the frozen file; migration 008
# creates them that way. It is not a copy of any customer's database.
#
# What it still cannot stand for: a database whose history matches neither
# starting point — for example one installed in May or June 2026 by the
# installer of that time, which carried on past errors this client stops at.
phase6_upgrade_from_oldest_install_script() {
    echo
    echo "═════ Phase 6: upgrade from the oldest install script (6 March 2026) ═════"
    if [[ ! -f "${OLDEST_SCHEMA}" ]]; then
        echo "✗ phase 6 FAILED — the frozen install script is missing: ${OLDEST_SCHEMA}" >&2
        return 1
    fi
    # The checksum below covers the file from its first line of hashes down.
    # The header above that line is left out so that it can be reworded, and
    # that left a way round the checksum: a statement written INTO the header
    # (a CREATE TABLE, say) gives the same checksum, and MySQL would run it.
    # So every line of the header must be a comment: `--` alone, or `--` and a
    # space. (MySQL does not treat `--` followed straight by a letter as a
    # comment.)
    local bad_header
    bad_header="$(awk '/^-- ####*$/ { exit } !/^--( |$)/ { print "      line " NR ": " $0 }' "${OLDEST_SCHEMA}" || true)"
    if [[ -n "${bad_header}" ]]; then
        echo "✗ phase 6 FAILED — the frozen install script's HEADER holds a line that is not a comment: ${OLDEST_SCHEMA}" >&2
        echo "${bad_header}" >&2
        echo "    Every line above the first line of hashes must start with \"-- \". The checksum does not cover the header, so a statement there would be run without being checked. Phase 6 was NOT run." >&2
        return 1
    fi
    # git hash-object reads only the text it is given: it needs no history, so
    # it works in GitHub's one-commit checkout. `|| true` so that a failure to
    # work the checksum out ends in the plain message below, not a silent exit.
    local body_sum
    body_sum="$(sed '1,/^-- ####*$/d' "${OLDEST_SCHEMA}" | git hash-object --stdin || true)"
    if [[ "${body_sum}" != "${FIXTURE_BODY_CHECKSUM}" ]]; then
        echo "✗ phase 6 FAILED — the frozen install script's body has been CHANGED: ${OLDEST_SCHEMA}" >&2
        echo "    checksum of everything below its first line of hashes: ${body_sum:-could not be worked out}" >&2
        echo "    the checksum written in run.sh (FIXTURE_BODY_CHECKSUM): ${FIXTURE_BODY_CHECKSUM}" >&2
        echo "    That file is a museum piece: put it back as it was (git checkout -- that file). Phase 6 was NOT run, because it would have tested a database nobody ever had." >&2
        return 1
    fi
    echo "  ✓ the frozen install script's body is untouched (checksum ${body_sum}) and its header holds comments only"
    echo "  Loading the frozen install script of 6 March 2026 …"
    if ! reset_db || ! mysql_file "${OLDEST_SCHEMA}"; then
        echo "✗ phase 6 FAILED — the frozen install script did not load on ${E2E_DB_ENGINE} ${E2E_MYSQL_VERSION}" >&2
        return 1
    fi
    echo "  old database installed: $(count_tables) tables, $(count_columns) columns, $(count_indexes) indexes, $(count_migrations) migrations recorded"

    echo "  Upgrading it with this checkout's migrations (pending only, in order, stopping at the first failure) …"
    if ! upgrade_pending_migrations; then
        echo "✗ phase 6 FAILED — a database installed in March 2026 cannot be upgraded to this checkout" >&2
        return 1
    fi
    echo "  schema: $(count_tables) tables, $(count_columns) columns, $(count_indexes) indexes, $(count_migrations) migrations recorded"
    if ! compare_with_fresh_install "p6"; then
        echo "✗ phase 6 FAILED — a database upgraded from the March 2026 install script is not the same as a fresh install (differences above)" >&2
        return 1
    fi
    echo "  ✓ a database upgraded from the oldest install script equals a fresh install"
    return 0
}

# -----------------------------------------------------------------------------
# Phase 7 — the installer's "carry on" over an older database whose two
# time-zone places differ (#604). See the header for what it is and why.
#
# What it cannot show: the installer's own pages. It runs the SQL their
# "carry on" step runs (today's install script, then every migration), through
# the mysql command-line client, as the other phases do.
# -----------------------------------------------------------------------------
# The two organisations' zones in number order, the count of old setting rows,
# of marks, and of old columns, as one line.
phase7_zone_state() {
    mysql_q "SELECT CONCAT(
                 (SELECT GROUP_CONCAT(COALESCE(timezone, '(empty)') ORDER BY siteID SEPARATOR ' ') FROM tblSites),
                 ' | old setting rows ', (SELECT COUNT(*) FROM tblSettings WHERE settingKey = 'site.timezone'),
                 ' | marks ', (SELECT COUNT(*) FROM tblSettings WHERE settingKey = 'site.timezoneKeptAt' AND siteID IS NULL AND settingValue = 'organisation'),
                 ' | old columns ', (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEvents' AND COLUMN_NAME = 'eventTimezone'));"
}

# Migration 220's side of the same phase (#622), as one line: how many backup
# codes there are, whether the migration is recorded, how many consent rows
# and activity records still hold a session identifier, and what the cleaner's
# "how far" setting says.
phase7_log_state() {
    mysql_q "SELECT CONCAT(
                 'backup codes ', (SELECT COUNT(*) FROM tblTotpBackupCodes),
                 ' | 220 recorded ', (SELECT COUNT(*) FROM tblMigrations WHERE filename = '220_activity_log_fingerprints.sql'),
                 ' | consent identifiers ', (SELECT COUNT(*) FROM tblConsentLog WHERE sessionID IS NOT NULL AND sessionID NOT LIKE 'sha256:%'),
                 ' | activity records with an identifier ', (SELECT COUNT(*) FROM tblActivityLogs WHERE sessionID IS NOT NULL AND sessionID <> '' AND sessionID NOT LIKE 'sha256:%'),
                 ' | how far ', COALESCE((SELECT settingValue FROM tblSettings WHERE settingKey = 'activityLog.cleanedThrough' AND siteID IS NULL), '(no row)'));"
}

# Just the three numbers the "codes, empty log" case at the end of phase 7
# turns on: whether migration 220 is recorded, how many backup codes there
# are, and what the cleaner's "how far" setting says.
phase7_codes_state() {
    mysql_q "SELECT CONCAT(
                 '220 recorded ', (SELECT COUNT(*) FROM tblMigrations WHERE filename = '220_activity_log_fingerprints.sql'),
                 ' | backup codes ', (SELECT COUNT(*) FROM tblTotpBackupCodes),
                 ' | how far ', COALESCE((SELECT settingValue FROM tblSettings WHERE settingKey = 'activityLog.cleanedThrough' AND siteID IS NULL), '(no row)'));"
}

# Migration 221's side of the phase (#626), as one line: whether the migration
# is recorded, how many records name nobody while still holding session details
# (the ones an erasure cannot reach), and where the cleaner's mark stands.
phase7_link_state() {
    mysql_q "SELECT CONCAT(
                 '221 recorded ', (SELECT COUNT(*) FROM tblMigrations WHERE filename = '221_activity_log_account_column.sql'),
                 ' | records naming nobody ', (SELECT COUNT(*) FROM tblActivityLogs WHERE userID IS NULL AND sessionDataSnapshot IS NOT NULL AND sessionDataSnapshot <> ''),
                 ' | how far ', COALESCE((SELECT settingValue FROM tblSettings WHERE settingKey = 'activityLog.cleanedThrough' AND siteID IS NULL), '(no row)'));"
}

phase7_installer_carry_on_over_an_older_database() {
    echo
    echo "═════ Phase 7: the installer's \"carry on\" over an older database whose two time-zone places differ ═════"
    local file name
    local done_list="${SNAP_DIR}/p7_done.txt"
    local err_file="${SNAP_DIR}/p7_err.txt"
    echo "  Building the older database: the install script of 6 March 2026, then this checkout's migrations up to 216 …"
    if ! reset_db || ! mysql_file "${OLDEST_SCHEMA}"; then
        echo "✗ phase 7 FAILED — the frozen install script did not load on ${E2E_DB_ENGINE} ${E2E_MYSQL_VERSION}" >&2
        return 1
    fi
    if ! mysql_q "SELECT filename FROM tblMigrations;" > "${done_list}"; then
        echo "✗ phase 7 FAILED — could not read tblMigrations" >&2
        return 1
    fi
    # Plain glob, same reason as in run_all_migrations: a path with a space.
    for file in "${SQL_DIR}"/[0-9]*.sql; do
        [[ -e "${file}" ]] || continue
        name="$(basename "${file}")"
        # Nothing numbered 217 or later: this database has never seen migration 217.
        [[ "${name:0:3}" < "217" ]] || continue
        if grep -Fxq -- "${name}" "${done_list}"; then
            continue
        fi
        if ! mysql_file "${file}" 2> "${err_file}"; then
            echo "✗ phase 7 FAILED — the older database could not be built: ${name} stopped. The database said:" >&2
            grep -v 'Using a password on the command line' "${err_file}" | sed 's/^/      /' >&2 || true
            return 1
        fi
        mysql_q "INSERT IGNORE INTO tblMigrations (filename) VALUES ('${name}');" >/dev/null 2>&1 || true
    done
    # Its two places are set apart. The default organisation's row keeps what it
    # has (UTC); the installation's setting says Auckland, which is what its
    # pages show. A second organisation: its row says New York, its own
    # setting Paris.
    if ! mysql_q "UPDATE tblSites SET timezone = 'UTC' WHERE siteID = 1;
                  DELETE FROM tblSettings WHERE settingKey = 'site.timezone';
                  INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'site.timezone', 'Pacific/Auckland', 'UTC', 0);
                  INSERT INTO tblSites (siteKey, siteName, timezone, isActive) VALUES ('e2e-second', 'e2e second organisation', 'America/New_York', 1);
                  INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (LAST_INSERT_ID(), 'site.timezone', 'Europe/Paris', 'UTC', 0);" >/dev/null; then
        echo "✗ phase 7 FAILED — the older database's two time-zone places could not be set apart" >&2
        return 1
    fi
    # And for migration 221 (#626): a record of a signed-in person that names
    # NOBODY in its own column, which is how the portal wrote an ordinary
    # sign-in record until 27 October 2026. The install script must NOT record
    # 221 over such a database, and the migration must send the cleaner back
    # through the log by putting its mark at the start.
    #
    # Its session mark is a FINGERPRINT, not a raw identifier, on purpose: this
    # record stands for one written AFTER #622 (so its session details are
    # already in the named-facts shape) that merely lacks the account column.
    # Written with a raw identifier it also counted towards migration 220's
    # "activity records with an identifier", which this phase pins at 2, and
    # phase 7 failed saying the database was not the one it was about. Do not
    # change it back.
    #
    # And for migration 220 (#622): an account with two backup codes, two
    # activity records and one consent row, each holding a session identifier
    # as the portal stored it before, and the log holding a copy of a session.
    if ! mysql_q "INSERT INTO tblUsers (emailAddress) VALUES ('e2e-phase7@example.test');
                  SET @p7user := LAST_INSERT_ID();
                  INSERT INTO tblTotpBackupCodes (userID, codeHash) VALUES (@p7user, 'e2e old code one'), (@p7user, 'e2e old code two');
                  INSERT INTO tblActivityLogs (userID, activityType, activityDescription, sessionID, sessionDataSnapshot) VALUES
                      (@p7user, 'E2eOld', 'e2e old record one', '0123456789abcdef0123456789abcdef', '{\"user_id\":1,\"totp_backup_codes\":[\"AAAA1111\"]}'),
                      (@p7user, 'E2eOld', 'e2e old record two', '0123456789abcdef0123456789abcdef', '{\"user_id\":1}');
                  INSERT INTO tblConsentLog (siteID, sessionID, consentType, decision) VALUES (1, 'fedcba9876543210fedcba9876543210', 'cookies', 'accept');
                  INSERT INTO tblActivityLogs (userID, activityType, activityDescription, sessionID, visitorIP, userAgent, sessionDataSnapshot) VALUES
                      (NULL, 'E2eOldSignIn', 'e2e a sign-in that named nobody', CONCAT('sha256:', REPEAT('c', 64)), '203.0.113.5', 'e2e-agent', CONCAT('{\"format\":\"facts-1\",\"signedIn\":true,\"userID\":', @p7user, ',\"organisationID\":1}'));" >/dev/null; then
        echo "✗ phase 7 FAILED — the older database could not be given its old backup codes and activity records" >&2
        return 1
    fi
    local before after again
    local link_before link_after
    link_before="$(phase7_link_state)" || return 1
    echo "  the older database: ${link_before}"
    if [[ "${link_before}" != "221 recorded 0 | records naming nobody 1 | how far (no row)" ]]; then
        echo "✗ phase 7 FAILED — the older database is not the one this phase is about for migration 221 (it must hold a record that names nobody and no record of the migration). Nothing was proved." >&2
        return 1
    fi
    local log_before log_after log_again
    log_before="$(phase7_log_state)" || return 1
    echo "  the older database: ${log_before}"
    if [[ "${log_before}" != "backup codes 2 | 220 recorded 0 | consent identifiers 1 | activity records with an identifier 2 | how far (no row)" ]]; then
        echo "✗ phase 7 FAILED — the older database is not the one this phase is about for migration 220 (it must hold old backup codes and no record of the migration). Nothing was proved." >&2
        return 1
    fi
    before="$(phase7_zone_state)" || return 1
    echo "  the older database: ${before}"
    if [[ "${before}" != "UTC America/New_York | old setting rows 2 | marks 0 | old columns 1" ]]; then
        echo "✗ phase 7 FAILED — the older database is not the one this phase is about (it must have the old setting, the old column and no mark). Nothing was proved." >&2
        return 1
    fi

    echo "  Carrying on: today's install script over it, then every migration, ignoring which have run …"
    if ! mysql_file "${SQL_DIR}/full_schema.sql" 2> "${err_file}"; then
        echo "✗ phase 7 FAILED — today's install script did not load over the older database. The database said:" >&2
        grep -v 'Using a password on the command line' "${err_file}" | sed 's/^/      /' >&2 || true
        return 1
    fi
    if ! replay_all_migrations; then
        echo "✗ phase 7 FAILED — a migration stopped on the carry-on path" >&2
        return 1
    fi
    after="$(phase7_zone_state)" || return 1
    echo "  afterwards:         ${after}"
    if [[ "${after}" != "Pacific/Auckland Europe/Paris | old setting rows 0 | marks 1 | old columns 0" ]]; then
        echo "✗ phase 7 FAILED — after the installer's \"carry on\", each organisation's row must hold the zone its pages were showing (Pacific/Auckland, then Europe/Paris), with the old setting and the old column gone and the mark there. Found: ${after}" >&2
        echo "    If the rows still say UTC and America/New_York, the install script wrote migration 217's mark before 217 ran, and every organisation's zone was lost." >&2
        return 1
    fi
    echo "  ✓ each organisation's row holds the zone its pages were showing; the old setting and the old column are gone; the mark is there"
    log_after="$(phase7_log_state)" || return 1
    echo "  afterwards:         ${log_after}"
    # "how far 0" since migration 221 (#626), and NOT "(no row)" as it was
    # until 27 October 2026: 221 writes that row with 0 where anything needs
    # linking, which means "the cleaner starts at the beginning". What this
    # check has always been about is that it must not say "all" over a log that
    # holds old records, and 0 is the opposite of "all". If this line ever
    # reads "all" here, the fault 220's message below describes is back.
    if [[ "${log_after}" != "backup codes 0 | 220 recorded 1 | consent identifiers 0 | activity records with an identifier 2 | how far 0" ]]; then
        echo "✗ phase 7 FAILED — after the installer's \"carry on\", every old backup code must be gone, migration 220 recorded, the consent row a fingerprint, and the activity log NOT marked as clean (its two old records wait for the cleaner). Found: ${log_after}" >&2
        echo "    If the backup codes are still there, the install script recorded migration 220 before it ran, and the migration took the database for already done." >&2
        echo "    If \"how far\" says all, the install script marked a log that holds old records as clean, and nobody would ever be asked to clean it." >&2
        return 1
    fi
    echo "  ✓ every old backup code is gone, migration 220 is recorded, and the old activity records are left for the cleaner, not marked as clean"
    # Migration 221's side: recorded, and the cleaner sent back to the start.
    link_after="$(phase7_link_state)" || return 1
    echo "  afterwards:         ${link_after}"
    if [[ "${link_after}" != "221 recorded 1 | records naming nobody 1 | how far 0" ]]; then
        echo "✗ phase 7 FAILED — after the installer's \"carry on\", migration 221 must be recorded AND the cleaner's mark must be back at 0, so it goes through the log and gives that record the account its session details name. Found: ${link_after}" >&2
        echo "    If \"how far\" is still (no row) or says all, the install script recorded 221 over a database that HAS something to link, so the reset never happened and an erasure can never reach that record." >&2
        return 1
    fi
    echo "  ✓ migration 221 is recorded and the cleaner has been sent back through the log (${link_after})"
    # Somebody makes a new set of backup codes after the upgrade.
    if ! mysql_q "INSERT INTO tblTotpBackupCodes (userID, codeHash) SELECT userID, 'e2e a code made after the upgrade' FROM tblUsers WHERE emailAddress = 'e2e-phase7@example.test';" >/dev/null; then
        echo "✗ phase 7 FAILED — a backup code could not be added after the carry-on" >&2
        return 1
    fi
    log_after="$(phase7_log_state)" || return 1

    if ! mysql_file "${SQL_DIR}/full_schema.sql" 2> "${err_file}" || ! replay_all_migrations > /dev/null; then
        echo "✗ phase 7 FAILED — the same path a second time did not run" >&2
        return 1
    fi
    again="$(phase7_zone_state)" || return 1
    if [[ "${again}" != "${after}" ]]; then
        echo "✗ phase 7 FAILED — the same path a second time changed something. First: ${after}. Second: ${again}" >&2
        return 1
    fi
    echo "  ✓ the same path a second time changes nothing"
    log_again="$(phase7_log_state)" || return 1
    if [[ "${log_again}" != "${log_after}" || "${log_again}" != "backup codes 1 | "* ]]; then
        echo "✗ phase 7 FAILED — the same path a second time must NOT delete a backup code made after the upgrade, and must change nothing else of migration 220's. Before the second pass: ${log_after}. After it: ${log_again}" >&2
        return 1
    fi
    echo "  ✓ and a backup code made after the upgrade is still there (${log_again})"

    # ───── Migration 220 (#622): BACKUP CODES WITH AN EMPTY ACTIVITY LOG ─────
    # The one case where the install script's two questions do not agree, and
    # so the only case that can show it asking the WRONG ONE. The record that
    # migration 220 has run must be written only where NO BACKUP CODE exists;
    # written instead where the ACTIVITY LOG is empty, it is right in every
    # case but this one. Everything above holds a database with both codes and
    # old records; phase 1's database has neither; so neither could see it,
    # and the independent check of this package planted exactly that fault on
    # 27 October 2026 and nothing here noticed.
    #
    # What the fault would cost: in a database whose log has been emptied by
    # its own retention, or that never wrote a record, every two-step backup
    # code made before the fix stays alive — readable in every older backup —
    # with the migration marked as done, so it can never run again.
    #
    # It reuses the database this phase has finished with: the activity log and
    # the migration's row are taken away, one old code is left, and the
    # install script is loaded once more. Loading it over a database that
    # already holds it is the installer's "carry on" path, which this phase has
    # done twice already.
    if ! mysql_q "DELETE FROM tblActivityLogs;
                  DELETE FROM tblMigrations WHERE filename = '220_activity_log_fingerprints.sql';
                  DELETE FROM tblSettings WHERE settingKey = 'activityLog.cleanedThrough';
                  DELETE FROM tblTotpBackupCodes;
                  INSERT INTO tblTotpBackupCodes (userID, codeHash)
                       SELECT userID, 'e2e an old code, in a database whose log is empty' FROM tblUsers WHERE emailAddress = 'e2e-phase7@example.test';" >/dev/null; then
        echo "✗ phase 7 FAILED — the database could not be put into the \"codes, empty log\" state" >&2
        return 1
    fi
    if ! mysql_file "${SQL_DIR}/full_schema.sql" 2> "${err_file}"; then
        echo "✗ phase 7 FAILED — the install script would not load for the \"codes, empty log\" case" >&2
        grep -v 'Using a password on the command line' "${err_file}" | sed 's/^/      /' >&2 || true
        return 1
    fi
    local empty_log
    empty_log="$(phase7_codes_state)" || return 1
    if [[ "${empty_log}" != "220 recorded 0 | backup codes 1 | how far all" ]]; then
        echo "✗ phase 7 FAILED — with a backup code and an EMPTY activity log, the install script must NOT record migration 220: a code is there, so the migration still has work to do. Marking the empty log as clean is right. Found: ${empty_log}" >&2
        echo "    \"220 recorded 1\" means the install script is asking whether the ACTIVITY LOG is empty where it must ask whether a BACKUP CODE exists. The two agree in every other case, so this is the only check that shows it." >&2
        return 1
    fi
    # And the consequence, run for real rather than argued: with no record of
    # the migration, the old code is deleted when it runs.
    if ! mysql_file "${SQL_DIR}/220_activity_log_fingerprints.sql" 2> "${err_file}"; then
        echo "✗ phase 7 FAILED — migration 220 would not run for the \"codes, empty log\" case" >&2
        grep -v 'Using a password on the command line' "${err_file}" | sed 's/^/      /' >&2 || true
        return 1
    fi
    empty_log="$(phase7_codes_state)" || return 1
    if [[ "${empty_log}" != "220 recorded 1 | backup codes 0 | how far all" ]]; then
        echo "✗ phase 7 FAILED — after migration 220 ran over a database with a backup code and an empty activity log, the code must be gone and the migration recorded. Found: ${empty_log}" >&2
        return 1
    fi
    echo "  ✓ backup codes with an EMPTY activity log: the install script leaves migration 220 unrecorded, and the migration then deletes the old code (${empty_log})"
    return 0
}

# All three always run (see "Exit" in the header). `|| P5_RC=1` rather than
# reading $? afterwards: under `set -e` a failing call would end the script
# before the next line could look at it.
P5_RC=0
P6_RC=0
P7_RC=0
phase5_upgrade_from_last_release || P5_RC=1
phase6_upgrade_from_oldest_install_script || P6_RC=1
phase7_installer_carry_on_over_an_older_database || P7_RC=1

if [[ "${P5_RC}" -ne 0 || "${P6_RC}" -ne 0 || "${P7_RC}" -ne 0 ]]; then
    echo
    echo "═════ FAILED ═════" >&2
    [[ "${P5_RC}" -ne 0 ]] && echo "  ✗ phase 5 (upgrade from the last release) failed" >&2
    [[ "${P6_RC}" -ne 0 ]] && echo "  ✗ phase 6 (upgrade from the oldest install script) failed" >&2
    [[ "${P7_RC}" -ne 0 ]] && echo "  ✗ phase 7 (the installer's \"carry on\" over an older database) failed" >&2
    exit 1
fi

echo
echo "═════ All phases passed ═════"
# The last line of a complete run. Anything that reads a log of this test to
# decide "did it pass" should look for the line above AND exit code 0: a run
# that stopped early prints neither.
