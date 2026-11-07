#!/usr/bin/env bash
# Path: tools/run-all-selftests.sh
# -----------------------------------------------------------------------------
# Run every self-test in this repository, in one command 🧪 (#695)
# -----------------------------------------------------------------------------
#     tools/run-all-selftests.sh              every test
#     tools/run-all-selftests.sh --list       just name them, run nothing
#     tools/run-all-selftests.sh --only mail  only the ones whose name matches
#     tools/run-all-selftests.sh --keep       leave the database servers running
#
# WHY THIS EXISTS (the owner's decision, 31 October 2026)
# Each package used to run "the checks that touch what I changed". That cannot
# see a test a DIFFERENT package broke, and it has now failed twice:
#
#   - #690: two tests had been red since some point in migrations 224-232. The
#     cause was migration 231, which created the first `tblSettings` row with a
#     non-empty `siteID` — several long-standing expectations had been true only
#     because no such row existed. Found by accident.
#   - #695: the first run of the whole set found THREE more red tests, none of
#     them broken by the work in hand. #569 added a third page that files an
#     erasure request as already confirmed, and #625 put a per-request script
#     nonce on every page, which two page-comparison tests read as a difference.
#
# A red baseline makes every later package's verification meaningless, and the
# longer it stays the harder it is to work out who caused it.
#
# WHY NOT IN THE PRE-PUSH HOOK (offered to the owner and deliberately not
# chosen). `.githooks/pre-push` is about nine seconds. This is about fifteen
# minutes with three database servers. A push gate that slow gets bypassed with
# `git push --no-verify`, which is worse than not having one.
#
# WHAT IT DOES NOT DO, said plainly:
#   - It does NOT run the end-to-end migration harness (`tools/e2e-migrations/`).
#     That has its own runner, its own engines and its own seven phases, and it
#     takes longer than everything here put together. Run it separately when the
#     database changes.
#   - It does NOT run the audit scripts. `.githooks/pre-push` already runs every
#     one of them on every push, in about nine seconds, so they are covered
#     where it matters. Run them by hand with:
#         for c in tools/audit-checks/check_*.py; do python3 "$c" || echo "RED $c"; done
#   - It runs ONE database version, 8.0.36 by default (SELFTEST_MYSQL_IMAGE
#     changes it). The workflows run the set twice, on 8.0.36 and 8.4.11. This
#     is a local smoke test of the whole set, not a replacement for them.
#   - It proves nothing about MariaDB, real Apache, a browser, or real hosting.
#     Each test's own header says what it cannot prove.
#
# WHAT IT NEEDS, and what it does when something is missing. Two different
# answers, because they have different costs:
#   - docker, php, python3 — a REFUSAL. Nothing runs at all.
#   - node, openssl, tar, rclone, qrencode — a WARNING naming the test that
#     suffers, and the run goes on. This is a reduced set, so the warning says
#     what it costs: without node the site-addresses test FAILS, while the
#     go-live and live-chat tests PASS WITHOUT RUNNING the page's script;
#     without node, or without Chrome, Chromium or Edge, the top-bar and
#     csrf-carriers browser tests REFUSE (exit 2, shown red) — never skip;
#     without rclone the off-site test refuses (exit 2, shown red); without
#     qrencode the QR test skips the part that reads its pictures with an
#     independent encoder.
#     (An earlier version of this header claimed it "refuses rather than
#     running a reduced set that looks complete". That was untrue for all five,
#     and an independent check caught it.)
# Containers are named after this script and started with `--rm`, so no volume
# is left behind; it prints the count of unnamed leftover volumes before and
# after so that is on the record.
#
# PHP VERSION: whatever `php` is on this machine. The workflows pin 8.4, and
# 8.5 for the static-calls test alone, so a difference here is a difference
# from what the pull request will run.
#
# @package   Portal\Tools
# @author    MWBM Partners Ltd (t/a MWservices)
# @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
# @license   All Rights Reserved
# @version   1.0.0
# @link      https://github.com/MWBMPartners/WebMS-Intra/issues/695
# -----------------------------------------------------------------------------

set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO" || exit 1

# 🏷️ Everything this script owns is named after it, so nothing of anybody
#    else's can be removed by mistake, and a leftover is obvious.
PREFIX='runall'
MAIN="${PREFIX}-main"
SECOND="${PREFIX}-second"
LOOSE="${PREFIX}-loose"
PORT_MAIN=33306
PORT_SECOND=33307
PORT_LOOSE=33308
DB_PASS='selftest-root'
IMAGE="${SELFTEST_MYSQL_IMAGE:-mysql:8.0.36}"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/run-all-selftests-XXXXXX")"
LOGS="$WORK/logs"
mkdir -p "$LOGS"
SUMMARY="$WORK/summary.txt"
: > "$SUMMARY"

ONLY=''
LIST_ONLY=0
KEEP=0
while [ "$#" -gt 0 ]; do
    case "$1" in
        --list) LIST_ONLY=1 ;;
        --keep) KEEP=1 ;;
        --only) shift; ONLY="${1:-}" ;;
        --only=*) ONLY="${1#--only=}" ;;
        # The whole header block, not an arbitrary slice: an earlier version
        # printed lines 2-30 and stopped just before "WHAT IT DOES NOT DO" and
        # "WHAT IT NEEDS", which are the two parts somebody asking for help is
        # most likely to want.
        # Every comment line of the header, stopping at the first line that is
        # not one. An earlier version printed a fixed line range and stopped
        # just before "WHAT IT DOES NOT DO" and "WHAT IT NEEDS" — the two parts
        # somebody asking for help most wants.
        -h|--help) awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "${BASH_SOURCE[0]}"; exit 0 ;;
        *) echo "I do not know the option '$1'. Try --help." >&2; exit 2 ;;
    esac
    shift
done

# -----------------------------------------------------------------------------
# 📋 The tests, in the order they run
# -----------------------------------------------------------------------------
# One line each: name | script | database (or '-') | extra environment.
#
# The database name and the extra environment are copied from each test's own
# workflow step, which is the only place that knows what a test needs. READ A
# TEST'S HEADER BEFORE CHANGING ITS LINE.
#
# `web:<folder>` in the extra environment is replaced by a scratch folder of
# that name, CREATED FIRST. That matters more than it looks: most of these
# tests REFUSE outright when `SELFTEST_WEB_DIR` does not already exist, and the
# refusal reads exactly like a failure. Twenty-three of them did that on the
# first run of this set.
# ⚠️ NO COMMENTS INSIDE THE LIST BELOW. It is a heredoc read line by line with
#    `IFS='|' read -r name script db extra`, and the only line it skips is an
#    EMPTY one — so a `#` line becomes a test called "#    something" and the
#    run reports it RED with "Could not open input file". Measured, by putting
#    two comment blocks in there and watching `--only scheduled-jobs` report
#    "2 tests ran; 1 not green".
#
#    FIVE TESTS WERE MISSING FROM THIS LIST, found by round 4 of #775 with this
#    file's own one-liner:
#
#      for f in tools/*-selftest.php; do
#        grep -q "$(basename "$f")" tools/run-all-selftests.sh || echo "$f"
#      done
#
#    `scheduled-jobs` is named in four documents and in the #775 hard gate's
#    own docstring as the guard for everything that gate cannot see — including
#    its one security property, that a job records its start only AFTER its
#    token is accepted — and it was run by nothing automatic anywhere.
#    `portal-check` was in `portal-selftests.yml`, which GitHub has never
#    registered (#754), and absent from here, **which is why this runner could
#    not see the two missing-dependency breaks that happened in it**.
#    `clock-change` is #777's and had been relied on for twenty-two review
#    rounds; the two i18n tests are #763's.
#
# ⚠️ AND THE ONE-LINER IN THAT NOTE IS PHP-ONLY, so it could not see the two
#    NON-PHP self-tests that were also missing. Use this instead:
#
#      find tools -type f -name "*selftest*" | while read -r f; do
#        grep -q "$(basename "$f")" tools/run-all-selftests.sh || echo "$f"
#      done
#
#    `tools/csrf-carriers-selftest.mjs` and
#    `tools/document-write-check-selftest.py` are in EXTRA_SHELL now. Being in
#    a workflow is NOT the inclusion rule — `api-spec-check-selftest.py` is in
#    both, and this file claims to run every self-test in the repository.
#
# ⚠️ AND A ROW MUST NOT CARRY A PORT ITS TEST DOES NOT READ. The `portal-check`
#    row was added with `SELFTEST_WEB_PORT=9833`, and that test reads neither
#    that variable nor any port. The pre-flight scrapes `_PORT…=` out of this
#    table, so a stale listener on a port NO test uses would have refused the
#    whole suite.
TESTS=$(cat <<'LIST'
gdpr-coverage|tools/gdpr-coverage-selftest.php|-|
giving-retention|tools/giving-retention-selftest.php|-|
php-runtime|tools/php-runtime-selftest.php|-|
clock-change|tools/clock-change-selftest.php|-|
i18n-member-language|tools/i18n-member-language-selftest.php|-|
i18n-member-language-http|tools/i18n-member-language-http-selftest.php|selftest_i18nhttp|
pdf-write|tools/pdf-write-selftest.php|-|
asset-identifier|tools/asset-identifier-selftest.php|-|
event-recurrence|tools/event-recurrence-selftest.php|-|
series-repattern|tools/series-repattern-selftest.php|-|
qr|tools/qr-selftest.php|-|QRENCODE
db-server|tools/db-server-selftest.php|-|
roles|tools/roles-selftest.php|-|
memberships|tools/memberships-selftest.php|-|
account-guard|tools/account-guard-selftest.php|-|
attendance-access|tools/attendance-access-selftest.php|-|
anon-checkins|tools/anon-checkins-selftest.php|-|
report-builder|tools/report-builder-selftest.php|-|
time-limit|tools/time-limit-selftest.php|-|
webpush|tools/webpush-selftest.php|-|
safefetch|tools/safefetch-selftest.php|-|SAFEFETCH_SELFTEST_PORT=9053 SAFEFETCH_SELFTEST_TLS_PORT=9054
static-calls|tools/static-calls-selftest.php|-|
cdn-sri|tools/cdn-sri-selftest.php|-|
ics-reader|tools/ics-reader-selftest.php|-|
restore-order|tools/restore-order-selftest.php|-|
backup-archive|tools/backup-archive-selftest.php|-|
app-switch|tools/app-switch-selftest.php|selftest_appswitch|
expense-rules|tools/expense-rules-selftest.php|selftest_expenserules|
expense-withdraw|tools/expense-withdraw-selftest.php|selftest_expensewithdraw|
expense-payment-race|tools/expense-payment-race-selftest.php|selftest_expensepayrace|
expense-edit|tools/expense-edit-selftest.php|selftest_expenseedit|
my-volunteering|tools/my-volunteering-selftest.php|selftest_myvolunteering|
gdpr-eraser|tools/gdpr-eraser-selftest.php|selftest_gdpreraser|web:gdpr-eraser
erasure-review|tools/erasure-review-selftest.php|selftest_erasurereview|
capabilities|tools/capabilities-selftest.php|selftest_capabilities|
held-texts|tools/held-texts-selftest.php|selftest_heldtexts|
setting-limit|tools/setting-limit-selftest.php|selftest_limit|
password-writer|tools/password-writer-selftest.php|selftest_pwwriter|
retention-caps|tools/retention-caps-selftest.php|selftest_retcaps|
rate-limit-privacy|tools/rate-limit-privacy-selftest.php|selftest_ratelimit|
webhook-retention|tools/webhook-retention-selftest.php|selftest_webhookret|
reserved-words-live|tools/reserved-words-live-selftest.php|selftest_reservedwords|
event-visibility|tools/event-visibility-selftest.php|selftest_visibility|
feed-resolver|tools/feed-resolver-selftest.php|selftest_resolver|
import-notifier|tools/import-notifier-selftest.php|selftest_notifier|
feed-admin|tools/feed-admin-selftest.php|selftest_feedadmin|web:feed-admin
page-head|tools/page-head-selftest.php|selftest_pagehead|SELFTEST_WEB_PORT=9674
site-modes|tools/site-modes-selftest.php|selftest_sitemodes|web:site-modes SELFTEST_WEB_PORT=9461
channel|tools/channel-selftest.php|selftest_channel|web:channel SELFTEST_WEB_PORT=9590
portal-address|tools/portal-address-selftest.php|selftest_portaladdress|web:portal-address SELFTEST_WEB_PORT=9660
site-addresses|tools/site-addresses-selftest.php|selftest_siteaddresses|web:site-addresses SELFTEST_WEB_PORT=9650
page-scripts|tools/page-scripts-selftest.php|selftest_pagescripts|web:page-scripts SELFTEST_WEB_PORT=9651
help-pages|tools/help-pages-selftest.php|selftest_helppages|web:help-pages SELFTEST_WEB_PORT=9663
outside-services|tools/outside-services-selftest.php|selftest_outside|SELFTEST_WEB_PORT=9778
content-translation|tools/content-translation-selftest.php|selftest_cttrans|web:content-translation SELFTEST_WEB_PORT=9671
erasure-pages|tools/erasure-pages-selftest.php|selftest_erasurepages|web:erasure-pages SELFTEST_WEB_PORT=9071
admin-password|tools/admin-password-selftest.php|selftest_adminpwd|web:admin-password SELFTEST_WEB_PORT=9086
self-service-password|tools/self-service-password-selftest.php|selftest_sspwd|web:self-service-password SELFTEST_WEB_PORT=9088
event-repeat-pages|tools/event-repeat-pages-selftest.php|selftest_evrep|web:event-repeat SELFTEST_WEB_PORT=9077
transcription-pages|tools/transcription-pages-selftest.php|selftest_transcription|web:transcription SELFTEST_WEB_PORT=9081
settings-secrets|tools/settings-secrets-selftest.php|selftest_settingssecrets|web:settings-secrets SELFTEST_WEB_PORT=9640 SELFTEST_MAIL_PORT=9641
sign-in-limits|tools/sign-in-limits-selftest.php|selftest_signinlimits|web:sign-in-limits SELFTEST_WEB_PORT=9520 SELFTEST_MAIL_PORT=9521
time-zones|tools/time-zones-selftest.php|selftest_timezones|web:time-zones SELFTEST_WEB_PORT=9861
upgrade-pages|tools/upgrade-pages-selftest.php|selftest_upgradepages|web:upgrade-pages SELFTEST_WEB_PORT=9850 SELFTEST_WEB_PORT_2=9851
two-step|tools/two-step-selftest.php|selftest_twostep|web:two-step SELFTEST_WEB_PORT=9740
live-chat-moderation|tools/live-chat-moderation-selftest.php|selftest_livechat|web:live-chat SELFTEST_WEB_PORT=9760
go-live-button|tools/go-live-button-selftest.php|selftest_golive|web:go-live SELFTEST_WEB_PORT=9762
activity-log|tools/activity-log-selftest.php|selftest_activitylog|web:activity-log SELFTEST_WEB_PORT=9770
scheduled-jobs|tools/scheduled-jobs-selftest.php|selftest_scheduledjobs|web:scheduled-jobs SELFTEST_WEB_PORT=9893
portal-check|tools/portal-check-selftest.php|selftest_portalcheck|
mail-pages|tools/mail-pages-selftest.php|selftest_mailpages|web:mail-pages SELFTEST_WEB_PORT=9580
mail-queue|tools/mail-queue-selftest.php|selftest_mailqueue|web:mail-queue SELFTEST_WEB_PORT=9585
mail-queue-pages|tools/mail-queue-pages-selftest.php|selftest_mailqueuepages|
maillib|tools/maillib/tests/run.php|-|web:mail-queue-pages SELFTEST_WEB_PORT=9800 SELFTEST_MAIL_PORT=9801
offsite-settings|tools/offsite-settings-selftest.php|selftest_offsite|web:offsite-settings
installer|tools/installer-selftest.php|-|web:installer SELFTEST_DB_PORT_2=33307 SELFTEST_WEB_PORT=9750
db-strict-mode|tools/db-strict-mode-selftest.php|-|web:db-strict SELFTEST_DB_PORT=33308 SELFTEST_DB_NAME=selftest_strict
feed-importer|tools/feed-importer-selftest.php|selftest_importer|FEED
backup-restore|tools/backup-restore-selftest.php|BACKUPUSER:class|
backup-page|tools/backup-page-selftest.php|BACKUPUSER:pages|web:backup-page SELFTEST_WEB_PORT=9480
top-bar|tools/top-bar-selftest.php|selftest_topbar|SELFTEST_WEB_PORT=9682
access-pages|tools/access-pages-selftest.php|selftest_accesspages|ALONE web:access-pages SELFTEST_WEB_PORT=9068
LIST
)

# 🧮 Two tests are not PHP, so they are run on their own lines below rather
#    than squeezed into the table above.
EXTRA_SHELL='deploy-settings|bash tools/deploy/resolve-settings-selftest.sh
api-spec-check|python3 -I tools/api-spec-check-selftest.py
scheduled-jobs-gate|python3 -I tools/scheduled-jobs-gate-selftest.py
document-write-check|python3 -I tools/document-write-check-selftest.py
csrf-carriers|node tools/csrf-carriers-selftest.mjs
package|bash tools/package/package-selftest.sh'

if [ "$LIST_ONLY" -eq 1 ]; then
    echo "$TESTS" | sed '/^$/d' | cut -d'|' -f1
    echo "$EXTRA_SHELL" | cut -d'|' -f1
    rm -rf "$WORK"
    exit 0
fi

# -----------------------------------------------------------------------------
# 🧰 What this machine has. A missing program is a REFUSAL, never a quietly
#    reduced run (#646's lesson: ask what a test needs, do not discover it).
# -----------------------------------------------------------------------------
missing=''
for program in docker php python3; do
    command -v "$program" > /dev/null 2>&1 || missing="$missing $program"
done
if [ -n "$missing" ]; then
    echo "REFUSED: this script needs$missing on the PATH. Nothing was run." >&2
    rm -rf "$WORK"
    exit 2
fi
if ! command -v node > /dev/null 2>&1; then
    echo "WARNING: node is not on this machine. The site addresses' test will FAIL without it," >&2
    echo "         and the live chat and go-live tests will PASS with the page's script NOT run." >&2
    echo "         The top-bar and csrf-carriers browser tests will REFUSE (shown red)." >&2
fi
for program in openssl tar rclone qrencode; do
    command -v "$program" > /dev/null 2>&1 || case "$program" in
        qrencode) echo "WARNING: qrencode is not on this machine, so the QR test cannot check its own" >&2
                  echo "         pictures against an independent encoder. The rest of it still runs." >&2 ;;
        *)        echo "WARNING: $program is not on this machine, so the off-site backup settings test will" >&2
                  echo "         REFUSE (exit 2) and show as red. Everything else is unaffected." >&2 ;;
    esac
done
if ! docker info > /dev/null 2>&1; then
    echo "REFUSED: docker is installed but not running. Nothing was run." >&2
    rm -rf "$WORK"
    exit 2
fi
# Every port the table names, not just the database ones: a stale server left on
# an app port is answered by the test as though it were this run's (see the
# PHP_CLI_SERVER_WORKERS note further down).
TABLE_PORTS="$(printf '%s\n' "$TESTS" | grep -oE '_PORT[_0-9]*=[0-9]+' | grep -oE '[0-9]+$' | sort -u | tr '\n' ' ')9055"
for port in "$PORT_MAIN" "$PORT_SECOND" "$PORT_LOOSE" $TABLE_PORTS; do
    if (command -v lsof > /dev/null 2>&1) && [ -n "$(lsof -nP -iTCP:"$port" -sTCP:LISTEN 2>/dev/null)" ]; then
        echo "REFUSED: something is already listening on port $port. Nothing was run." >&2
        echo "         A database port (33306-33308) usually means containers a previous run left" >&2
        echo "         behind: docker rm -vf ${PREFIX}-main ${PREFIX}-second ${PREFIX}-loose" >&2
        echo "         Any other port usually means an orphaned 'php -S' from a test that was" >&2
        echo "         interrupted: lsof -nP -iTCP:$port -sTCP:LISTEN names it." >&2
        rm -rf "$WORK"
        exit 2
    fi
done

VOLUMES_BEFORE="$(docker volume ls -q -f dangling=true 2>/dev/null | wc -l | tr -d ' ')"

# 🧹 Whatever happens from here — finished, failed, or interrupted — the
#    containers go, WITH their data (`-v`: a plain `docker rm` leaves the
#    volume behind, which is how this machine once came to hold 415 of them
#    taking 101 GB while every agent reported clean-up as done).
cleanup() {
    if [ "$KEEP" -eq 1 ]; then
        echo
        echo "--keep: leaving $MAIN, $SECOND and $LOOSE running, and $WORK in place."
        echo "        Remove them with: docker rm -vf $MAIN $SECOND $LOOSE"
        return
    fi
    docker rm -vf "$MAIN" "$SECOND" "$LOOSE" > /dev/null 2>&1
    if [ "${red:-1}" -eq 0 ]; then
        rm -rf "$WORK"
    else
        echo
        echo "The logs of everything that ran are in $LOGS"
    fi
    local after
    after="$(docker volume ls -q -f dangling=true 2>/dev/null | wc -l | tr -d ' ')"
    echo
    echo "Unnamed leftover volumes: $VOLUMES_BEFORE before, $after after."
    if [ "$after" -gt "$VOLUMES_BEFORE" ]; then
        echo "⚠️  This run ADDED $((after - VOLUMES_BEFORE)). That should not happen — every container"
        echo "    here is started with --rm and removed with -v. Worth looking into."
    fi
}
trap cleanup EXIT
# 🛑 A separate handler for an interrupt: after a trap returns, bash CARRIES ON.
#    With cleanup on INT alone, Ctrl-C took the database servers down and then
#    ran every remaining test against nothing. `exit` here runs the EXIT trap
#    once and stops.
trap 'echo; echo "Interrupted."; exit 130' INT TERM

ask() {
    local container="$1"
    shift
    docker exec -i -e MYSQL_PWD="$DB_PASS" "$container" mysql -uroot -h 127.0.0.1 --protocol=TCP "$@"
}

echo "▶ Starting three database servers ($IMAGE)."
for spec in "$MAIN $PORT_MAIN" "$SECOND $PORT_SECOND" "$LOOSE $PORT_LOOSE"; do
    # shellcheck disable=SC2086
    set -- $spec
    docker rm -vf "$1" > /dev/null 2>&1
    if ! docker run -d --rm --name "$1" -e MYSQL_ROOT_PASSWORD="$DB_PASS" -p "$2:3306" \
            --tmpfs /var/lib/mysql "$IMAGE" > /dev/null; then
        echo "REFUSED: could not start $1." >&2
        exit 2
    fi
done
for container in "$MAIN" "$SECOND" "$LOOSE"; do
    ready=0
    for _ in $(seq 1 120); do
        if ask "$container" -e 'SELECT 1' > /dev/null 2>&1; then ready=1; break; fi
        sleep 1
    done
    if [ "$ready" -eq 0 ]; then
        echo "REFUSED: $container did not accept a query within two minutes." >&2
        docker logs "$container" 2>&1 | tail -20 >&2
        exit 2
    fi
done
# 🪢 One server's own setting is deliberately LOOSE, because that is what the
#    strict-mode test needs: it proves DbServer::connect() makes the connection
#    strict whatever the server was set to.
ask "$LOOSE" -e "SET GLOBAL sql_mode = ''" > /dev/null
ask "$MAIN" -e 'SELECT VERSION() AS server, @@GLOBAL.sql_mode AS sql_mode' 2>/dev/null | sed 's/^/  /'

# 🪤 EVERY loop over this table reads it on file descriptor 3, NOT on stdin.
#    Loading the schema uses `docker exec -i`, which reads stdin — and if the
#    loop is fed by `<<< "$TESTS"` on stdin, the FIRST schema load swallows the
#    rest of the test list. The first run of this script built exactly one
#    database and then reported 37 red tests, every one of them "Unknown
#    database". Nothing about the message points at the cause.
echo "▶ Building a throwaway database per test."
built=0
while IFS='|' read -r name script db extra <&3; do
    [ -z "${name:-}" ] && continue
    case "$name" in *"${ONLY}"*) ;; *) [ -n "$ONLY" ] && continue ;; esac
    case "$db" in
        '-'|'') continue ;;
        BACKUPUSER:*)
            # 🔐 The backup tests connect as a user of their own with a
            #    password, because what they prove includes what a restore may
            #    and may not do as that user.
            who="backup_${db#BACKUPUSER:}"
            database="selftest_backup_${db#BACKUPUSER:}"
            ask "$MAIN" -e "DROP DATABASE IF EXISTS \`$database\`; CREATE DATABASE \`$database\`;
                CREATE USER IF NOT EXISTS '$who'@'%' IDENTIFIED BY 'selftest-user';
                GRANT ALL PRIVILEGES ON \`$database\`.* TO '$who'@'%';" > /dev/null || exit 2
            docker exec -i -e MYSQL_PWD='selftest-user' "$MAIN" mysql -u"$who" \
                -h 127.0.0.1 --protocol=TCP "$database" < web/_sql/full_schema.sql > /dev/null || exit 2
            ;;
        *)
            ask "$MAIN" -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\`" > /dev/null || exit 2
            ask "$MAIN" "$db" < web/_sql/full_schema.sql > /dev/null || exit 2
            ;;
    esac
    built=$((built + 1))
done 3<<< "$TESTS"
echo "  $built database$([ "$built" -eq 1 ] || echo s) built from web/_sql/full_schema.sql."

# -----------------------------------------------------------------------------
# 🏃 Run them
# -----------------------------------------------------------------------------
export SELFTEST_DB_HOST=127.0.0.1
export SELFTEST_DB_PORT="$PORT_MAIN"
export SELFTEST_DB_USER=root
export SELFTEST_DB_PASS="$DB_PASS"

ran=0
red=0
record() {                               # record <name> <exit code> <seconds> <log>
    local name="$1" code="$2" seconds="$3" log="$4"
    printf '%-22s %-6s %4ds  %s\n' "$name" \
        "$([ "$code" -eq 0 ] && echo 'green' || echo "RED($code)")" \
        "$seconds" "$(tail -2 "$log" | tr '\n' ' ' | cut -c1-90)" >> "$SUMMARY"
    printf '  %-22s %s (%ds)\n' "$name" \
        "$([ "$code" -eq 0 ] && echo '✓' || echo "✗ exit $code")" "$seconds"
    ran=$((ran + 1))
    [ "$code" -eq 0 ] || red=$((red + 1))
}

echo "▶ Running the tests."
while IFS='|' read -r name script db extra <&3; do
    [ -z "${name:-}" ] && continue
    case "$name" in *"${ONLY}"*) ;; *) [ -n "$ONLY" ] && continue ;; esac

    env_pairs=()
    args=()
    feed=0
    case "$db" in
        '-'|'') ;;
        BACKUPUSER:*)
            env_pairs+=("SELFTEST_DB_NAME=selftest_backup_${db#BACKUPUSER:}"
                        "SELFTEST_DB_USER=backup_${db#BACKUPUSER:}"
                        "SELFTEST_DB_PASS=selftest-user") ;;
        *) env_pairs+=("SELFTEST_DB_NAME=$db") ;;
    esac
    for piece in ${extra:-}; do
        case "$piece" in
            # `ALONE` is a note to whoever reads this table, not a switch: every
            # test here runs one at a time anyway. It marks the one test that
            # MUST be alone — access-pages counts through the server-wide
            # `mysql.general_log`, so anything else on the same server makes it
            # fail spuriously (#689). It is last in the table for that reason.
            ALONE) ;;
            FEED) feed=1 ;;
            # 🔳 The workflow adds --with-qrencode when qrencode is on the
            #    machine: that is the part which reads our own pictures back
            #    with an INDEPENDENT encoder, and it is the only thing that
            #    catches a picture that draws and cannot be read.
            QRENCODE) command -v qrencode > /dev/null 2>&1 && args+=('--with-qrencode') ;;
            web:*) folder="$WORK/web/${piece#web:}"; mkdir -p "$folder"
                   env_pairs+=("SELFTEST_WEB_DIR=$folder") ;;
            *) env_pairs+=("$piece") ;;
        esac
    done

    log="$LOGS/$name.log"
    started=$SECONDS
    if [ "$feed" -eq 1 ]; then
        # 📅 The importer's test needs a stand-in web server handing out
        #    calendar files beside it, which it writes itself first.
        feed_dir="$WORK/calendars"
        mkdir -p "$feed_dir"
        env ${env_pairs[@]+"${env_pairs[@]}"} SELFTEST_FEED_DIR="$feed_dir" SELFTEST_FEED_PORT=9055 \
            php "$script" --write-calendars > "$log" 2>&1
        PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:9055 "$feed_dir/server.php" \
            > "$feed_dir.server.log" 2>&1 &
        feed_pid=$!
        for _ in $(seq 1 30); do
            php -r 'exit(@fsockopen("127.0.0.1", 9055) ? 0 : 1);' && break
            sleep 1
        done
        env ${env_pairs[@]+"${env_pairs[@]}"} SELFTEST_FEED_DIR="$feed_dir" SELFTEST_FEED_PORT=9055 \
            php -d memory_limit=512M "$script" > "$log" 2>&1
        code=$?
        kill "$feed_pid" > /dev/null 2>&1
        # 🧟 AND ITS CHILDREN. `PHP_CLI_SERVER_WORKERS=6` makes `php -S` fork
        #    six workers; killing the master leaves them orphaned to PID 1 and
        #    STILL BOUND to the port. The next run then fails to bind (into a
        #    log nothing reads), the readiness probe gets an answer from the
        #    OLD server and says "ready", and the importer test runs against a
        #    previous run's calendar fixtures and reports GREEN. Measured: six
        #    such workers from an earlier run were still serving port 9055
        #    two hours later, and served a later run's requests.
        #
        #    Matched on this run's own scratch path, which no other process can
        #    share, rather than on the port or on "php -S" — either of those
        #    could kill somebody else's server.
        pkill -f "$feed_dir/server.php" > /dev/null 2>&1
        wait "$feed_pid" 2>/dev/null
    else
        env ${env_pairs[@]+"${env_pairs[@]}"} php -d memory_limit=512M "$script" \
            ${args[@]+"${args[@]}"} > "$log" 2>&1
        code=$?
    fi
    record "$name" "$code" "$((SECONDS - started))" "$log"
done 3<<< "$TESTS"

while IFS='|' read -r name command <&3; do
    [ -z "${name:-}" ] && continue
    case "$name" in *"${ONLY}"*) ;; *) [ -n "$ONLY" ] && continue ;; esac
    log="$LOGS/$name.log"
    started=$SECONDS
    # shellcheck disable=SC2086
    $command > "$log" 2>&1
    record "$name" "$?" "$((SECONDS - started))" "$log"
done 3<<< "$EXTRA_SHELL"

# -----------------------------------------------------------------------------
# 🧾 The answer
# -----------------------------------------------------------------------------
if [ "$ran" -eq 0 ]; then
    echo
    echo "REFUSED: nothing matched --only '$ONLY', so NOTHING RAN. A typo here used to print" >&2
    echo "         \"0 tests ran; 0 not green\" and exit 0, which reads exactly like a clean run." >&2
    echo "         'tools/run-all-selftests.sh --list' names them all." >&2
    exit 2
fi

echo
echo "================================ SUMMARY ================================"
cat "$SUMMARY"
echo "========================================================================="
if [ "$red" -gt 0 ]; then
    echo
    echo "NOT GREEN:"
    grep -v ' green ' "$SUMMARY" | sed 's/^/  /'
fi
echo
echo "$ran test$([ "$ran" -eq 1 ] || echo s) ran; $red not green."
if [ "$red" -gt 0 ] || [ "$KEEP" -eq 1 ]; then
    echo "Full output: $LOGS"
fi
[ "$red" -eq 0 ] || exit 1
exit 0
