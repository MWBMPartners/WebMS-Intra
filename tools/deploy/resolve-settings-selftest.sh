#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# tools/deploy/resolve-settings-selftest.sh — proves every rule and every
# refusal in tools/deploy/resolve-settings.sh. Needs no server, no GitHub and
# no network: it only runs that script with made-up settings and made-up folders.
# -----------------------------------------------------------------------------
# @package   WebMS-Intra (development tooling — never deployed; only web/ is)
# @author    Salem874
# @copyright MWBM Partners Ltd (t/a MWservices). All Rights Reserved.
# @version   1.2.0 (13 October 2026: section 7 gained the cases of the "refuse
#            everything" file in the two folders that are not uploaded, 7.11a
#            to 7.11q, and section 9 one more, 9.5b; before it, 1.1.0 of
#            12 October 2026, issue #500, roll-out package F7)
#
# RUN IT
#   bash tools/deploy/resolve-settings-selftest.sh
# It exits 0 only when every check passed, and its LAST line is
#   SELFTEST REACHED ITS END
# Check for that line as well as the exit code: a run that stopped part way
# must not be taken for a pass. As a second lock, a run that ends anywhere but
# at its last line can no longer report exit code 0 at all: the exit trap turns
# that into exit code 1 and says so. (Before, a stray "exit 0" half way through
# gave exit code 0 with only the missing end line to show for it.)
#
#   RESOLVE_SETTINGS_SCRIPT=<file>  test another copy of the script (used to
#                                   show that a deliberately broken copy FAILS)
#   SELFTEST_BASH=<bash program>    run the script under a particular bash
#
# HOW EACH CHECK IS MADE SHARP
#   - A refusal must be the EXACT set of reasons expected, by their short keys.
#     "It refused" is not enough: a check that passes because some OTHER rule
#     happened to fire would hide a broken rule.
#   - An acceptance is checked against the plan file, value by value. Reading
#     the printed account alone would prove the words, not where files go.
#   - EVERY run doubles as a leak test. Every made-up setting value that can
#     carry it contains the marker "zqcanary", and after every run everything
#     the script printed is searched for it. The plan file is searched for the
#     made-up password and key after every run too.
#     A PORT cannot carry the marker (it is a number), so the checks that set a
#     port search what was printed for that number themselves. This paragraph
#     used to say "every value", and a port printed in full went unnoticed.
#     The same goes for what a SERVER sends back: the made-up folder listings
#     hold names with the marker, so a listing name echoed back is caught too.
#
# WHAT THIS CANNOT PROVE
# That a login works, that a folder exists, or how the SFTP program behaves.
# Nothing here connects to anything.
#
# EMPTY_FOLDER_FACT (what the script's "tree" question says about an empty code
# folder rests on this). Tried on 12 October 2026 with the real upload program
# (lftp 4.9.2 against OpenSSH 9.6; mirror --reverse --delete --only-newer
# --no-empty-dirs). Mirrored from a local folder holding no file at all, and
# from one holding only .gitkeep, it deleted NOTHING on the server. Mirrored
# from a folder that had lost one file and one sub-folder, it deleted exactly
# those. So with those flags, on that version, an empty local folder does not
# empty the server's copy; no other version was tried. The refusal stays, for
# a different reason: an empty code folder means the checkout is incomplete.
#
# PORTABILITY: plain bash 3.2 or later; env, mktemp, grep, sed, sort, tr, cp,
# find, ln, mkdir, rm, cat; and, for one check (7.11q), sha256sum or shasum,
# whichever the machine has.
# Most checks compare text inside bash itself rather than starting grep, because
# starting a few thousand small programs made one run take a minute on a Mac.
# -----------------------------------------------------------------------------

set -uo pipefail   # deliberately NOT -e: a failed check must not stop the run
export LC_ALL=C

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="${RESOLVE_SETTINGS_SCRIPT:-$HERE/resolve-settings.sh}"
BASH_BIN="${SELFTEST_BASH:-bash}"
# The full path of that bash: one section runs the script with almost nothing
# on its PATH, where a bare "bash" could not be found.
BASH_ABS="$(command -v "$BASH_BIN")" || { printf 'self-test: the bash to run the script with was not found\n' >&2; exit 3; }

if [[ ! -f "$SCRIPT" ]]; then
    printf 'self-test: the script to test was not found\n' >&2
    exit 3
fi

# A scratch folder, removed at the end. The pattern is checked again before
# removing, so an empty or unexpected value can never reach "rm -rf".
WORK="$(mktemp -d "${TMPDIR:-/tmp}/resolve-settings-selftest.XXXXXX")" || exit 3
# shellcheck disable=SC2329  # called by the trap on the line after it
cleanup() {
    case "$WORK" in
        */resolve-settings-selftest.??????) rm -rf -- "$WORK" ;;
    esac
}
# A run that ends anywhere but at its last line is not a pass, whatever exit
# code it was about to give: see RUN IT, above.
REACHED_END=0
# shellcheck disable=SC2329  # called by the trap on the line after it
on_exit() {
    local rc=$?
    cleanup
    if [[ "$REACHED_END" -ne 1 ]]; then
        printf 'SELFTEST STOPPED BEFORE ITS END (it was about to give exit code %s). This is NOT a pass.\n' "$rc"
        if [[ "$rc" -eq 0 ]]; then exit 1; fi
    fi
}
trap on_exit EXIT
PLAN="$WORK/plan.env"

PASSED=0
FAILED=0
OUT=''
RC=0
KEEP_PLAN=0
LEAKED=0
RUN_PATH="$PATH"    # the PATH the script is given; section 9 narrows it
BASH_FLAGS=()       # extra words for bash itself; section 9 uses -x
NL=$'\n'

pass() { PASSED=$((PASSED + 1)); printf 'ok    %s\n' "$1"; }
fail() {
    FAILED=$((FAILED + 1))
    printf 'FAIL  %s\n' "$1"
    printf '      exit code %s; it printed:\n' "$RC"
    printf '%s\n' "$OUT" | sed 's/^/      | /'
}

# =============================================================================
# The made-up settings. Every value carries the marker zqcanary.
# The key is several lines, as a real one is, but is deliberately NOT shaped
# like a real key: a secret scanner should have nothing to find in this file.
# =============================================================================
FAKE_KEY=$'made-up key, line one zqcanaryKEY7\nmade-up key, line two\n'
FAKE_PW='zqcanaryPW7'
BASE=(
    SFTP_ENABLED=true
    SFTP_HOST=sftp-zqcanary.example.org
    SFTP_USER=zqcanaryuser
    "SFTP_KEY=$FAKE_KEY"
    SFTP_PATH_ROOT_DIR=/home/zqcanaryroot
    SFTP_PATH_LIVE_DIR=live-zqcanary.example.org
    SFTP_PATH_BETA_DIR=beta-zqcanary.example.org
    SFTP_PATH_ALPHA_DIR=alpha-zqcanary.example.org
)

# run_script [VAR=value ...] -- <arguments for the script>
# Runs the script with ONLY those settings (nothing leaks in from this shell).
# A later VAR=value wins over an earlier one; "VAR=" means "not set".
run_script() {
    local envs=()
    while [[ $# -gt 0 && "$1" != '--' ]]; do envs+=("$1"); shift; done
    shift
    if [[ "$KEEP_PLAN" -ne 1 ]]; then rm -f "$PLAN"; fi
    OUT="$(env -i PATH="$RUN_PATH" ${envs[@]+"${envs[@]}"} "$BASH_ABS" ${BASH_FLAGS[@]+"${BASH_FLAGS[@]}"} "$SCRIPT" "$@" 2>&1)"
    RC=$?
    # The leak test that every run gets (capitals make no difference to it).
    shopt -s nocasematch
    if [[ "$OUT" == *zqcanary* ]]; then LEAKED=1; else LEAKED=0; fi
    shopt -u nocasematch
    if [[ "$LEAKED" -eq 1 ]]; then
        fail "LEAK: a made-up setting value appeared in what the script printed (arguments: $*)"
    fi
    if [[ -f "$PLAN" ]] && grep -q -e 'zqcanaryPW7' -e 'zqcanaryKEY7' "$PLAN"; then
        fail "LEAK: the made-up password or key was written to the plan file (arguments: $*)"
    fi
}

resolve() {   # resolve <channel> [VAR=value ...]
    local ch="$1"; shift
    run_script "${BASE[@]}" "$@" -- resolve --channel "$ch" --plan-file "$PLAN"
}

refusal_keys() { printf '%s\n' "$OUT" | grep -o 'REFUSED ([a-z-]*)' | sed 's/REFUSED (\(.*\))/\1/' | sort -u | tr '\n' ' ' | sed 's/ $//'; }

# expect_refused <label> "<key> [<key> ...]"
expect_refused() {
    local want got
    # shellcheck disable=SC2086  # the keys are meant to be split into separate words
    want="$(printf '%s\n' $2 | sort -u | tr '\n' ' ' | sed 's/ $//')"
    got="$(refusal_keys)"
    if [[ "$RC" -eq 1 && "$got" == "$want" && ! -e "$PLAN" && "$NL$OUT" == *"${NL}Nothing was connected to"* ]]; then
        pass "$1"
    else
        fail "$1  [wanted refusal: $want; got: ${got:-none}]"
    fi
}

# expect_ok <label> <the closing word the script prints: RESOLVED, TREE OK or REMOTE OK>
expect_ok() {
    if [[ "$RC" -eq 0 && "$NL$OUT$NL" == *"$NL$2$NL"* && "$OUT" != *REFUSED* ]]; then
        pass "$1"
    else
        fail "$1  [wanted: $2 and exit code 0]"
    fi
}

# plan_is <label> <NAME> <value>   — one line of the plan file, exactly
plan_is() {
    if [[ -f "$PLAN" ]] && grep -qxF "$2='$3'" "$PLAN"; then
        pass "$1"
    else
        fail "$1  [wanted $2='$3' in the plan file; the plan file has: $( [[ -f "$PLAN" ]] && grep "^$2=" "$PLAN" | sed 's/zqcanary/(marker)/g' || printf 'no file' )]"
    fi
}

says()     { if [[ "$OUT" == *"$2"* ]]; then pass "$1"; else fail "$1  [wanted the words: $2]"; fi; }
says_not() { if [[ "$OUT" == *"$2"* ]]; then fail "$1  [did not want the words: $2]"; else pass "$1"; fi; }

refused()  { local l="$1" k="$2" ch="$3"; shift 3; resolve "$ch" "$@"; expect_refused "$l" "$k"; }
accepted() { local l="$1" ch="$2"; shift 2; resolve "$ch" "$@"; expect_ok "$l" 'RESOLVED'; }

# The staff web folder's default name is read from the script, so the owner's
# answer to decision 3 changes one line there and none here.
DEFAULT_WEB="$(sed -n "s/^STAFF_WEB_DIR_DEFAULT='\(.*\)'$/\1/p" "$SCRIPT")"

# shellcheck disable=SC2016  # the inner bash is meant to expand its own BASH_VERSION
printf '== The script under test: %s (run with: %s)\n' "${SCRIPT##*/}" "$("$BASH_BIN" -c 'printf "bash %s" "$BASH_VERSION"')"

# =============================================================================
printf '\n== 1. Arrangement one: every channel under one login and one base folder\n'
# =============================================================================
accepted '1.1 live resolves from the shared settings' live
plan_is  '1.1 live: channel word'            DEPLOY_CHANNEL live
plan_is  '1.1 live: health must answer prod' DEPLOY_HEALTH_ENV prod
plan_is  '1.1 live: login is the shared one' DEPLOY_LOGIN_SOURCE shared
plan_is  '1.1 live: host'                    DEPLOY_HOST sftp-zqcanary.example.org
plan_is  '1.1 live: user'                    DEPLOY_USER zqcanaryuser
plan_is  '1.1 live: port defaults to 22'     DEPLOY_PORT 22
plan_is  '1.1 live: signs in with a key'     DEPLOY_SIGNIN key
plan_is  '1.1 live: the key is named, not copied' DEPLOY_SIGNIN_SETTING SFTP_KEY
plan_is  '1.1 live: channel folder'          DEPLOY_CHANNEL_ROOT /home/zqcanaryroot/live-zqcanary.example.org
plan_is  '1.1 live: staff web folder is inside the channel folder' DEPLOY_REMOTE_WEB "/home/zqcanaryroot/live-zqcanary.example.org/$DEFAULT_WEB"
plan_is  '1.1 live: the local staff pages'   DEPLOY_LOCAL_WEB web/public_html
plan_is  '1.1 live: code folders, one at a time' DEPLOY_CODE_FOLDERS '_apps _core _install _lang _sql _vendor _libraries'
plan_is  '1.1 live: folders the server keeps' DEPLOY_KEPT_FOLDERS '_auth_keys _uploads _backups'
says     '1.1 live: the account names the setting, not the value' 'host:            from SFTP_HOST'
if [[ -n "$(find "$PLAN" -perm 600)" ]]; then pass '1.1 the plan file can be read by its owner only'; else fail '1.1 the plan file can be read by its owner only'; fi

accepted '1.2 beta resolves' beta
plan_is  '1.2 beta: channel folder' DEPLOY_CHANNEL_ROOT /home/zqcanaryroot/beta-zqcanary.example.org
plan_is  '1.2 beta: health must answer beta' DEPLOY_HEALTH_ENV beta
accepted '1.3 alpha resolves' alpha
plan_is  '1.3 alpha: channel folder' DEPLOY_CHANNEL_ROOT /home/zqcanaryroot/alpha-zqcanary.example.org
plan_is  '1.3 alpha: health must answer alpha' DEPLOY_HEALTH_ENV alpha

accepted '1.4 a password is used when there is no key' alpha SFTP_KEY= "SFTP_PASSWORD=$FAKE_PW"
plan_is  '1.4 password sign-in' DEPLOY_SIGNIN password
plan_is  '1.4 the password is named, not copied' DEPLOY_SIGNIN_SETTING SFTP_PASSWORD
accepted '1.5 the key wins when both are set' alpha "SFTP_PASSWORD=$FAKE_PW"
plan_is  '1.5 key sign-in' DEPLOY_SIGNIN_SETTING SFTP_KEY
accepted '1.6 the shared port is used for the shared login' alpha SFTP_PORT=50122
plan_is  '1.6 port 50122' DEPLOY_PORT 50122
says     '1.6 the account names the port setting' 'port:            from SFTP_PORT'
says_not '1.6 the port NUMBER is in the plan file only, never in what is printed' '50122'
accepted '1.7 a port written 0022 is read as 22, not as an octal number' alpha SFTP_PORT=0022
plan_is  '1.7 port 22' DEPLOY_PORT 22
accepted '1.8 spaces, line breaks and one trailing slash at the ends are dropped' beta \
    $'SFTP_HOST=  sftp-zqcanary.example.org\n' $'SFTP_USER=\tzqcanaryuser ' 'SFTP_PATH_ROOT_DIR=/home/zqcanaryroot/ ' $'SFTP_PATH_BETA_DIR=beta-zqcanary.example.org/\n' 'SFTP_PORT= 22 '
plan_is  '1.8 host trimmed' DEPLOY_HOST sftp-zqcanary.example.org
plan_is  '1.8 user trimmed' DEPLOY_USER zqcanaryuser
plan_is  '1.8 folder trimmed' DEPLOY_CHANNEL_ROOT /home/zqcanaryroot/beta-zqcanary.example.org
accepted '1.9 a deep base folder is accepted (no hosting company is assumed)' live SFTP_PATH_ROOT_DIR=/var/www/vhosts/zqcanary.example.org
plan_is  '1.9 deep folder' DEPLOY_CHANNEL_ROOT /var/www/vhosts/zqcanary.example.org/live-zqcanary.example.org

# =============================================================================
printf '\n== 2. Arrangement two: separate logins per channel\n'
# =============================================================================
OWN_ALL=(
    SFTP_HOST= SFTP_USER= SFTP_KEY= SFTP_PATH_ROOT_DIR=
    SFTP_HOST_LIVE=sftp-zqcanary.example.org SFTP_USER_LIVE=zqcanarylive "SFTP_KEY_LIVE=$FAKE_KEY" SFTP_PATH_LIVE_ROOT_DIR=/home/zqcanarylive
    SFTP_HOST_BETA=sftp-zqcanary.example.org SFTP_USER_BETA=zqcanarybeta "SFTP_PASSWORD_BETA=$FAKE_PW" SFTP_PORT_BETA=2202 SFTP_PATH_BETA_ROOT_DIR=/home/zqcanarybeta
    SFTP_HOST_ALPHA=alpha-server-zqcanary.example.org SFTP_USER_ALPHA=zqcanaryalpha "SFTP_KEY_ALPHA=$FAKE_KEY" SFTP_PATH_ALPHA_ROOT_DIR=/home/zqcanaryalpha
)
accepted '2.1 live on its own login, with the shared login left completely empty' live "${OWN_ALL[@]}"
plan_is  '2.1 login is the channel own'  DEPLOY_LOGIN_SOURCE channel
plan_is  '2.1 host from the override'    DEPLOY_HOST sftp-zqcanary.example.org
plan_is  '2.1 user from the override'    DEPLOY_USER zqcanarylive
plan_is  '2.1 key named from the override' DEPLOY_SIGNIN_SETTING SFTP_KEY_LIVE
plan_is  '2.1 base folder from the override' DEPLOY_CHANNEL_ROOT /home/zqcanarylive/live-zqcanary.example.org
says     '2.1 the account says channel override' 'host:            from SFTP_HOST_LIVE'
accepted '2.2 beta on its own login, password and port' beta "${OWN_ALL[@]}"
plan_is  '2.2 beta user'     DEPLOY_USER zqcanarybeta
plan_is  '2.2 beta password named' DEPLOY_SIGNIN_SETTING SFTP_PASSWORD_BETA
plan_is  '2.2 beta port'     DEPLOY_PORT 2202
says_not '2.2 a channel own port number is never printed either' '2202'
accepted '2.3 alpha on its own server' alpha "${OWN_ALL[@]}"
plan_is  '2.3 alpha host'    DEPLOY_HOST alpha-server-zqcanary.example.org

accepted '2.4 one server per channel, same user name and same path on each: different servers are different places' live \
    SFTP_HOST_BETA=beta-server-zqcanary.example.org SFTP_USER_BETA=zqcanaryuser "SFTP_KEY_BETA=$FAKE_KEY" SFTP_PATH_BETA_DIR=live-zqcanary.example.org \
    SFTP_HOST_ALPHA=alpha-server-zqcanary.example.org SFTP_USER_ALPHA=zqcanaryuser "SFTP_KEY_ALPHA=$FAKE_KEY" SFTP_PATH_ALPHA_DIR=live-zqcanary.example.org
accepted '2.5 same server and same path but different users: different places' live \
    SFTP_HOST_BETA=sftp-zqcanary.example.org SFTP_USER_BETA=zqcanaryother "SFTP_KEY_BETA=$FAKE_KEY" SFTP_PATH_BETA_DIR=live-zqcanary.example.org

accepted '2.6 hybrid: live on its own login while beta and alpha use the shared one' live \
    SFTP_HOST_LIVE=live-server-zqcanary.example.org SFTP_USER_LIVE=zqcanarylive "SFTP_PASSWORD_LIVE=$FAKE_PW"
plan_is  '2.6 live uses its own host' DEPLOY_HOST live-server-zqcanary.example.org
plan_is  '2.6 live uses its own password, never the shared key' DEPLOY_SIGNIN_SETTING SFTP_PASSWORD_LIVE
accepted '2.6 hybrid: alpha, in the same settings, still uses the shared login' alpha \
    SFTP_HOST_LIVE=live-server-zqcanary.example.org SFTP_USER_LIVE=zqcanarylive "SFTP_PASSWORD_LIVE=$FAKE_PW"
plan_is  '2.6 alpha uses the shared host' DEPLOY_HOST sftp-zqcanary.example.org
plan_is  '2.6 alpha uses the shared key'  DEPLOY_SIGNIN_SETTING SFTP_KEY

accepted '2.7 a channel with its own login and no port of its own gets 22, never the shared port' live \
    SFTP_PORT=2222 SFTP_HOST_LIVE=live-server-zqcanary.example.org SFTP_USER_LIVE=zqcanarylive "SFTP_KEY_LIVE=$FAKE_KEY"
plan_is  '2.7 port is 22' DEPLOY_PORT 22
accepted '2.8 its own base folder alone, under the shared login' beta SFTP_PATH_BETA_ROOT_DIR=/srv/zqcanarybeta
plan_is  '2.8 login stays shared' DEPLOY_LOGIN_SOURCE shared
plan_is  '2.8 base folder from the override' DEPLOY_CHANNEL_ROOT /srv/zqcanarybeta/beta-zqcanary.example.org
says     '2.8 the account names the override' 'base folder:     from SFTP_PATH_BETA_ROOT_DIR'

# =============================================================================
printf '\n== 3. Arrangement three: a mixture inside one channel — must be refused\n'
# =============================================================================
refused '3.1 only the host is the channel own (shared user and key would be borrowed)' login-half-set live SFTP_HOST_LIVE=other-zqcanary.example.org
refused '3.2 only the user is the channel own' login-half-set live SFTP_USER_LIVE=zqcanaryother
refused '3.3 only the port is the channel own' login-half-set live SFTP_PORT_LIVE=2222
refused '3.4 only the key is the channel own' login-half-set live "SFTP_KEY_LIVE=$FAKE_KEY"
refused '3.5 only the password is the channel own' login-half-set live "SFTP_PASSWORD_LIVE=$FAKE_PW"
refused '3.6 own host and user, but the key would have to come from the shared login' login-half-set live \
    SFTP_HOST_LIVE=other-zqcanary.example.org SFTP_USER_LIVE=zqcanaryother "SFTP_PASSWORD=$FAKE_PW"
says    '3.6 the reason says the shared key is never used' 'never signs in with the shared key or password'
refused '3.7 own host and key, but no user' login-half-set live SFTP_HOST_LIVE=other-zqcanary.example.org "SFTP_KEY_LIVE=$FAKE_KEY"
says    '3.7 the reason names the setting that is empty' 'SFTP_HOST_LIVE is set but SFTP_USER_LIVE is empty'
refused '3.8 a half-set login on BETA stops an ALPHA deploy too (the settings must be sound as a whole)' login-half-set alpha SFTP_USER_BETA=zqcanaryother

refused '3.9 the shared login has no host' shared-login-missing alpha SFTP_HOST=
refused '3.10 the shared login has no user' shared-login-missing alpha SFTP_USER=
refused '3.11 the shared login has neither key nor password' shared-login-missing alpha SFTP_KEY=
refused '3.12 a key that is only spaces counts as not set' shared-login-missing alpha $'SFTP_KEY= \n '

# =============================================================================
printf '\n== 4. The shape of each value\n'
# =============================================================================
refused '4.1 a host with a space inside' inner-space alpha 'SFTP_HOST=sftp zqcanary.example.org'
refused '4.2 a base folder with a space inside' inner-space alpha 'SFTP_PATH_ROOT_DIR=/home/zqcanary root'
refused '4.3 a channel folder with a line break inside' inner-space alpha $'SFTP_PATH_ALPHA_DIR=alpha\nzqcanary'
refused '4.4 a host with sftp:// in front' host-shape alpha SFTP_HOST=sftp://zqcanary.example.org
refused '4.5 a host with a port on the end' host-shape alpha SFTP_HOST=zqcanary.example.org:22
refused '4.6 a host with a user in front' host-shape alpha SFTP_HOST=user@zqcanary.example.org
refused '4.7 a host with a slash' host-shape alpha SFTP_HOST=zqcanary.example.org/
refused '4.8 a channel own host with a semicolon' 'host-shape' alpha 'SFTP_HOST_BETA=zqcanary.example.org;rm' SFTP_USER_BETA=zqcanaryother "SFTP_KEY_BETA=$FAKE_KEY"
refused '4.9 a user with a semicolon' user-shape alpha 'SFTP_USER=zqcanary;user'
refused '4.10 a user with a quote' user-shape alpha "SFTP_USER=zqcanary'user"
refused '4.11 a port that is not a number' port-shape alpha SFTP_PORT=zqcanary
refused '4.12 port 0' port-shape alpha SFTP_PORT=0
refused '4.13 port 65536' port-shape alpha SFTP_PORT=65536
says_not '4.13 a refused port number is not printed back' '65536'
refused '4.14 a port with six digits' port-shape alpha SFTP_PORT=123456
says_not '4.14 a refused port number is not printed back' '123456'
refused '4.14b a user that begins with a hyphen (it could be read as an option by the sign-in program)' user-shape alpha SFTP_USER=-zqcanaryuser
refused '4.14c a host that begins with a hyphen (the same danger)' host-shape alpha SFTP_HOST=-zqcanary.example.org
refused '4.14d a host that ends with a hyphen' host-shape alpha SFTP_HOST=zqcanary.example.org-
refused '4.14e a channel own user that begins with a hyphen' user-shape alpha \
    SFTP_HOST_BETA=sftp-zqcanary.example.org SFTP_USER_BETA=-zqcanaryother "SFTP_KEY_BETA=$FAKE_KEY"
accepted '4.15 port 65535 is the largest allowed' alpha SFTP_PORT=65535
refused '4.16 a password with a line break on the end' password-edge-space alpha SFTP_KEY= $'SFTP_PASSWORD=zqcanaryPW7\n'
refused '4.17 a password with a space at the start' password-edge-space alpha SFTP_KEY= 'SFTP_PASSWORD= zqcanaryPW7'
accepted '4.18 a password with a space in the middle is left alone' alpha SFTP_KEY= 'SFTP_PASSWORD=zqcanary PW7'

refused '4.19 a base folder with no slash at the start' root-shape alpha SFTP_PATH_ROOT_DIR=home/zqcanaryroot
refused '4.20 a base folder that is / alone' root-shape alpha SFTP_PATH_ROOT_DIR=/
refused '4.21 a base folder with a .. part' root-shape alpha SFTP_PATH_ROOT_DIR=/home/zqcanaryroot/../other
refused '4.22 a base folder with a . part' root-shape alpha SFTP_PATH_ROOT_DIR=/home/./zqcanaryroot
refused '4.23 a base folder with an empty part' root-shape alpha SFTP_PATH_ROOT_DIR=/home//zqcanaryroot
refused '4.24 a base folder with a semicolon' root-shape alpha 'SFTP_PATH_ROOT_DIR=/home/zqcanary;root'
refused '4.25 a base folder with two trailing slashes (only one is dropped)' root-shape alpha SFTP_PATH_ROOT_DIR=/home/zqcanaryroot//
refused '4.26 a channel own base folder with no slash at the start' root-shape alpha SFTP_PATH_BETA_ROOT_DIR=home/zqcanarybeta

refused '4.27 a channel folder given as a full path' dir-shape alpha SFTP_PATH_ALPHA_DIR=/home/zqcanaryroot/alpha
refused '4.28 a channel folder with a folder inside it' dir-shape alpha SFTP_PATH_ALPHA_DIR=zqcanary/inner
refused '4.29 a channel folder that is .' dir-shape alpha SFTP_PATH_ALPHA_DIR=.
refused '4.30 a channel folder that is ..' dir-shape alpha SFTP_PATH_ALPHA_DIR=..
refused '4.31 a hidden channel folder' dir-shape alpha SFTP_PATH_ALPHA_DIR=.zqcanary
refused '4.32 a channel folder with a semicolon' dir-shape alpha 'SFTP_PATH_ALPHA_DIR=zqcanary;x'
refused '4.33 a bad BETA folder stops an ALPHA deploy too' dir-shape alpha SFTP_PATH_BETA_DIR=zqcanary/inner

# =============================================================================
printf '\n== 5. Folders that would land in the wrong place\n'
# =============================================================================
refused '5.1 the base folder is not set anywhere' root-missing alpha SFTP_PATH_ROOT_DIR=
accepted '5.2 no shared base folder, but every channel has its own' alpha SFTP_PATH_ROOT_DIR= \
    SFTP_PATH_LIVE_ROOT_DIR=/home/zqcanarya SFTP_PATH_BETA_ROOT_DIR=/home/zqcanaryb SFTP_PATH_ALPHA_ROOT_DIR=/home/zqcanaryc
refused '5.3 the folder of the channel being deployed is not named' dir-missing alpha SFTP_PATH_ALPHA_DIR=
refused '5.4 the folder of ANOTHER channel is not named (clashes could not be ruled out)' dir-missing alpha SFTP_PATH_LIVE_DIR=
refused '5.5 the base folder already ends in the channel folder (it would be built inside itself)' root-ends-in-channel-folder live \
    SFTP_PATH_ROOT_DIR=/home/zqcanaryroot/live-zqcanary.example.org
refused '5.6 the same, with different capitals' root-ends-in-channel-folder live \
    SFTP_PATH_ROOT_DIR=/home/zqcanaryroot/LIVE-zqcanary.example.org
refused '5.7 the same, through a channel own base folder' root-ends-in-channel-folder beta \
    SFTP_PATH_BETA_ROOT_DIR=/home/zqcanaryroot/beta-zqcanary.example.org

refused '5.8 a channel folder named admin_html' dir-is-own-folder live SFTP_PATH_LIVE_DIR=admin_html
refused '5.9 a channel folder named public_html, with a trailing slash' dir-is-own-folder live SFTP_PATH_LIVE_DIR=public_html/
refused '5.10 a channel folder named Public_HTML (capitals make no difference)' dir-is-own-folder live SFTP_PATH_LIVE_DIR=Public_HTML
refused '5.11 a channel folder named like a code folder' dir-is-own-folder live SFTP_PATH_LIVE_DIR=_core
refused '5.12 a channel folder named like a folder the server keeps' dir-is-own-folder live SFTP_PATH_LIVE_DIR=_auth_keys
refused '5.13 a base folder that ends in admin_html' root-is-own-folder live SFTP_PATH_ROOT_DIR=/home/zqcanaryroot/admin_html
refused '5.14 a base folder that ends in PUBLIC_HTML' root-is-own-folder live SFTP_PATH_ROOT_DIR=/home/zqcanaryroot/PUBLIC_HTML
refused '5.15 a base folder that ends in _uploads' root-is-own-folder live SFTP_PATH_ROOT_DIR=/home/zqcanaryroot/_uploads
refused '5.16 a channel own base folder that ends in admin_html' root-is-own-folder live SFTP_PATH_BETA_ROOT_DIR=/home/zqcanaryroot/admin_html

refused '5.17 two channels given the same folder under the shared login' same-place alpha SFTP_PATH_BETA_DIR=live-zqcanary.example.org
says    '5.17 the reason names both channels' 'LIVE and BETA resolve to the same folder'
says    '5.17 the reason names the settings to look at' 'BETA from SFTP_PATH_ROOT_DIR + SFTP_PATH_BETA_DIR'
refused '5.18 the same folder reached through two different base-folder settings' same-place live \
    SFTP_PATH_ROOT_DIR=/home/zqcanaryroot/sites SFTP_PATH_ALPHA_ROOT_DIR=/home/zqcanaryroot/sites/ SFTP_PATH_ALPHA_DIR=live-zqcanary.example.org
refused '5.19 the same folder, differing only in capitals' same-place live SFTP_PATH_ALPHA_DIR=LIVE-zqcanary.example.org
refused '5.20 the same folder on one server named with different capitals, on two channel logins' same-place live \
    SFTP_HOST_BETA=SFTP-zqcanary.Example.org SFTP_USER_BETA=zqcanaryuser "SFTP_KEY_BETA=$FAKE_KEY" SFTP_PATH_BETA_DIR=live-zqcanary.example.org
refused '5.20b the same, with the capitals on the other channel of the pair' same-place live \
    SFTP_HOST_LIVE=SFTP-zqcanary.Example.org SFTP_USER_LIVE=zqcanaryuser "SFTP_KEY_LIVE=$FAKE_KEY" SFTP_PATH_BETA_DIR=live-zqcanary.example.org
refused '5.20c the same folder, differing only in capitals, the other way round' same-place live SFTP_PATH_LIVE_DIR=ALPHA-zqcanary.example.org
refused '5.21 live and alpha clash while BETA is the one being deployed' same-place beta SFTP_PATH_ALPHA_DIR=live-zqcanary.example.org
refused '5.21b the same server, user and folder on a DIFFERENT PORT is still the same place' same-place live \
    SFTP_HOST_BETA=sftp-zqcanary.example.org SFTP_USER_BETA=zqcanaryuser "SFTP_KEY_BETA=$FAKE_KEY" SFTP_PORT_BETA=2202 SFTP_PATH_BETA_DIR=live-zqcanary.example.org
refused '5.21c the same, with the different port on the shared login instead' same-place alpha SFTP_PORT=2222 \
    SFTP_HOST_BETA=sftp-zqcanary.example.org SFTP_USER_BETA=zqcanaryuser "SFTP_KEY_BETA=$FAKE_KEY" SFTP_PATH_BETA_DIR=alpha-zqcanary.example.org
refused '5.21d one channel inside another on a different port is still nested' nested live \
    SFTP_HOST_BETA=sftp-zqcanary.example.org SFTP_USER_BETA=zqcanaryuser "SFTP_KEY_BETA=$FAKE_KEY" SFTP_PORT_BETA=2202 SFTP_PATH_BETA_ROOT_DIR=/home/zqcanaryroot/live-zqcanary.example.org
refused '5.22 one channel inside another (beta inside live)' nested live \
    SFTP_PATH_BETA_ROOT_DIR=/home/zqcanaryroot/live-zqcanary.example.org
says    '5.22 the reason says which is inside which' 'The BETA channel'"'"'s folder is inside the LIVE channel'"'"'s folder'
refused '5.23 one channel inside another, the other way round (live inside alpha)' nested live \
    SFTP_PATH_LIVE_ROOT_DIR=/home/zqcanaryroot/alpha-zqcanary.example.org
says    '5.23 the reason says which is inside which' 'The LIVE channel'"'"'s folder is inside the ALPHA channel'"'"'s folder'
accepted '5.24 a folder whose name merely begins like another is NOT inside it' live \
    SFTP_PATH_BETA_DIR=live-zqcanary.example.org-beta

# =============================================================================
printf '\n== 6. The channel, the switch, the staff web folder, the health address\n'
# =============================================================================
run_script "${BASE[@]}" -- resolve --branch main --plan-file "$PLAN";  expect_ok '6.1 branch main is the live channel' 'RESOLVED';  plan_is '6.1 main -> live' DEPLOY_CHANNEL live
run_script "${BASE[@]}" -- resolve --branch beta --plan-file "$PLAN";  expect_ok '6.2 branch beta is the beta channel' 'RESOLVED';  plan_is '6.2 beta -> beta' DEPLOY_CHANNEL beta
run_script "${BASE[@]}" -- resolve --branch alpha --plan-file "$PLAN"; expect_ok '6.3 branch alpha is the alpha channel' 'RESOLVED'; plan_is '6.3 alpha -> alpha' DEPLOY_CHANNEL alpha
run_script "${BASE[@]}" -- resolve --branch feature/zqcanary --plan-file "$PLAN"; expect_refused '6.4 any other branch is refused' branch-unknown
run_script "${BASE[@]}" -- resolve --branch live --plan-file "$PLAN"; expect_refused '6.5 "live" is a channel, not a branch' branch-unknown

refused '6.6 the switch is empty' switched-off alpha SFTP_ENABLED=
refused '6.7 the switch says TRUE in capitals (only exactly "true" counts)' switched-off alpha SFTP_ENABLED=TRUE
refused '6.8 the switch says false' switched-off alpha SFTP_ENABLED=false
refused '6.8b the switch has a space after it ("exactly" means exactly)' switched-off alpha 'SFTP_ENABLED=true '
refused '6.8c the switch has a space before it' switched-off alpha 'SFTP_ENABLED= true'
refused '6.8d the switch is followed by a line break' switched-off alpha $'SFTP_ENABLED=true\n'
refused '6.8e the switch says True' switched-off alpha SFTP_ENABLED=True
refused '6.8f the switch says 1' switched-off alpha SFTP_ENABLED=1

accepted '6.9 the staff web folder name is one setting' live DEPLOY_STAFF_WEB_DIR=staff_html
plan_is  '6.9 the staff pages go to that folder' DEPLOY_REMOTE_WEB /home/zqcanaryroot/live-zqcanary.example.org/staff_html
says     '6.9 the account says where the name came from' 'name from DEPLOY_STAFF_WEB_DIR'
refused  '6.10 a channel folder with that same name is then refused too' dir-is-own-folder live DEPLOY_STAFF_WEB_DIR=staff_html SFTP_PATH_LIVE_DIR=Staff_Html
accepted '6.11 the staff web folder may keep the name public_html' live DEPLOY_STAFF_WEB_DIR=public_html
refused  '6.12 a staff web folder named like a folder the server keeps (deletion would reach the encryption key)' web-dir-reserved live DEPLOY_STAFF_WEB_DIR=_auth_keys
refused  '6.13 a staff web folder named like a code folder' web-dir-reserved live DEPLOY_STAFF_WEB_DIR=_core
refused  '6.14 a staff web folder name with a slash' web-dir-shape live DEPLOY_STAFF_WEB_DIR=zqcanary/x

accepted '6.15 no health address: the check is skipped with a warning, never a made-up address' live
plan_is  '6.15 the plan holds no address' DEPLOY_HEALTH_BASE ''
says     '6.15 a warning is printed' '::warning::ADMIN_BASE_URL_LIVE is not set'
says     '6.15 the account says SKIPPED' 'health check:    SKIPPED'
accepted '6.16 a health address, with one trailing slash dropped' live ADMIN_BASE_URL_LIVE=https://staff-zqcanary.example.org/
says_not '6.16 no "skipped" warning is printed' '::warning::ADMIN_BASE_URL_LIVE'
plan_is  '6.16 the plan holds it' DEPLOY_HEALTH_BASE https://staff-zqcanary.example.org
plan_is  '6.16 and names the variable' DEPLOY_HEALTH_SETTING ADMIN_BASE_URL_LIVE
accepted '6.17 another channel health address is not used for this one' alpha ADMIN_BASE_URL_LIVE=https://staff-zqcanary.example.org
plan_is  '6.17 alpha has none' DEPLOY_HEALTH_BASE ''
accepted '6.17b with an address set for two channels, each gets its own' alpha \
    ADMIN_BASE_URL_LIVE=https://staff-zqcanary.example.org ADMIN_BASE_URL_ALPHA=https://alpha-staff-zqcanary.example.org
plan_is  '6.17b alpha gets the alpha address' DEPLOY_HEALTH_BASE https://alpha-staff-zqcanary.example.org
refused  '6.18 a health address with no https:// in front' health-address-shape live ADMIN_BASE_URL_LIVE=staff-zqcanary.example.org
refused  '6.19 a health address with a path' health-address-shape live ADMIN_BASE_URL_LIVE=https://staff-zqcanary.example.org/portal
refused  '6.20 a health address that is not a web address' health-address-shape live ADMIN_BASE_URL_LIVE=ftp://staff-zqcanary.example.org
refused  '6.21 a health address carrying a user name' health-address-shape live ADMIN_BASE_URL_LIVE=https://user@staff-zqcanary.example.org

accepted '6.22 a setting from an earlier scheme is warned about, not refused' alpha SFTP_LIVE_PATH=/home/zqcanaryroot/old SFTP_PATH_ALPHA_ADMIN_DIR=zqcanary_old
says     '6.22 the old full-path setting is named' '::warning::SFTP_LIVE_PATH is still set'
says     '6.22 the old folder-name setting is named' '::warning::SFTP_PATH_ALPHA_ADMIN_DIR is still set'

printf 'stale\n' > "$PLAN"; KEEP_PLAN=1
run_script "${BASE[@]}" SFTP_HOST= -- resolve --channel alpha --plan-file "$PLAN"
KEEP_PLAN=0
expect_refused '6.23 a plan file left by an earlier run is removed when this run is refused' shared-login-missing

# A plan left by an earlier run must be gone after EVERY run that did not end
# in RESOLVED: refused for any reason, or called wrongly in any way.
stale_then() {   # stale_then <label> <wanted exit code> [VAR=value ...] -- <arguments>
    local label="$1" want="$2"; shift 2
    printf "DEPLOY_HOST='stale-zqcanary.example.org'\n" > "$PLAN"; KEEP_PLAN=1
    run_script "${BASE[@]}" "$@"
    KEEP_PLAN=0
    if [[ "$RC" -eq "$want" && ! -e "$PLAN" && "$OUT" != *RESOLVED* ]]; then pass "$label"; else fail "$label  [wanted exit code $want and the old plan file gone; plan file $( [[ -e "$PLAN" ]] && printf 'STILL THERE' || printf 'gone' )]"; fi
    rm -f "$PLAN"
}
stale_then '6.23b an old plan is removed when the run is on a branch that is not deployed' 1 -- resolve --branch feature/zqcanary --plan-file "$PLAN"
stale_then '6.23c an old plan is removed when the switch is off' 1 SFTP_ENABLED=false -- resolve --channel alpha --plan-file "$PLAN"
stale_then '6.23d an old plan is removed when the run is called wrongly (both --branch and --channel)' 2 -- resolve --branch main --channel live --plan-file "$PLAN"
stale_then '6.23e ... called wrongly (a channel that does not exist)' 2 -- resolve --channel gamma --plan-file "$PLAN"
stale_then '6.23f ... called wrongly (an unknown option AFTER the plan file is named)' 2 -- resolve --channel live --plan-file "$PLAN" --zqcanary x
stale_then '6.23g ... called wrongly (an unknown option BEFORE the plan file is named)' 2 -- resolve --zqcanary x --channel live --plan-file "$PLAN"
stale_then '6.23h ... called wrongly (neither --branch nor --channel)' 2 -- resolve --plan-file "$PLAN"
stale_then '6.23i ... called wrongly (an unknown first word)' 2 -- deploy --channel live --plan-file "$PLAN"
stale_then '6.23j ... called wrongly (an option with its value missing, after the plan file)' 2 -- resolve --plan-file "$PLAN" --channel
stale_then '6.23k an old plan is removed when a setting is refused for its shape' 1 SFTP_PORT=zqcanary -- resolve --channel alpha --plan-file "$PLAN"
# An old plan that CANNOT be removed (here: a folder sits where the file should be) stops the run.
mkdir "$PLAN"; KEEP_PLAN=1
run_script "${BASE[@]}" -- resolve --channel alpha --plan-file "$PLAN"
KEEP_PLAN=0
if [[ "$RC" -eq 1 && "$(refusal_keys)" == 'plan-file-stuck' && "$OUT" != *RESOLVED* ]]; then pass '6.23l an old plan that cannot be removed stops the run instead of being left to be read'; else fail '6.23l an old plan that cannot be removed stops the run instead of being left to be read'; fi
rmdir "$PLAN"

run_script "${BASE[@]}" -- resolve --channel alpha
expect_ok '6.24 it resolves without a plan file being asked for' 'RESOLVED'

# Every reason at once: three unrelated mistakes are all reported in one run.
refused '6.25 several mistakes are all reported in one run' 'dir-shape port-shape login-half-set' alpha \
    SFTP_PATH_LIVE_DIR=zqcanary/x SFTP_PORT=zqcanary SFTP_USER_BETA=zqcanaryother

for args in '' 'resolve' 'resolve --channel' 'resolve --channel gamma' 'resolve --branch main --channel live' 'resolve --channel live --zqcanary x' 'deploy --channel live' 'tree --channel live' 'tree --web-dir x --channel gamma' 'remote --channel live' 'remote --listing x --channel gamma'; do
    # shellcheck disable=SC2086  # the words are meant to be split into arguments
    run_script "${BASE[@]}" -- $args
    if [[ "$RC" -eq 2 && "$NL$OUT" == *"${NL}Usage:"* ]]; then pass "6.26 called wrongly ($args): exit code 2 and the usage line"; else fail "6.26 called wrongly ($args): exit code 2 and the usage line"; fi
done

# =============================================================================
printf '\n== 7. The local web folder, just before upload ("tree")\n'
# =============================================================================
GOOD="$WORK/good/web"
mkdir -p "$GOOD/public_html" "$GOOD/_libraries/dompdf" "$GOOD/_includes" "$GOOD/_functions" "$GOOD/private_html" "$GOOD/public_html_landing" "$GOOD/public_html_redir"
for d in _apps _core _install _lang _sql _vendor; do mkdir -p "$GOOD/$d/sub"; printf 'x\n' > "$GOOD/$d/sub/file.txt"; done
# shellcheck disable=SC2016  # PHP text: the dollar sign is meant to stay as it is
printf '<?php\n$channel = @file_get_contents(PORTAL_ROOT . '"'"'/.channel'"'"');\n' > "$GOOD/_core/bootstrap.php"
printf 'v1\n' > "$GOOD/_libraries/dompdf/VERSION.txt"
printf 'x\n' > "$GOOD/public_html/index.php"; printf 'x\n' > "$GOOD/public_html/.htaccess"
: > "$GOOD/_includes/.gitkeep"; : > "$GOOD/_functions/.gitkeep"; : > "$GOOD/private_html/.gitkeep"
printf 'x\n' > "$GOOD/public_html_landing/index.html"; printf 'x\n' > "$GOOD/public_html_redir/index.html"
printf '# Changes\n' > "$GOOD/CHANGELOG.md"
printf 'alpha\n' > "$GOOD/.channel"

TREE_N=0
fresh_tree() {   # a new copy of the good folder; its path is left in T
    TREE_N=$((TREE_N + 1))
    T="$WORK/tree$TREE_N/web"
    mkdir -p "$WORK/tree$TREE_N"
    cp -R "$GOOD" "$T"
}
tree() { run_script -- tree --web-dir "$T" --channel "${1:-alpha}"; }

fresh_tree; tree; expect_ok '7.1 a complete folder is accepted' 'TREE OK'
fresh_tree; mkdir "$T/_auth_keys" "$T/_uploads" "$T/_backups"; tree; expect_ok '7.2 the server own folders may exist locally (they are simply never uploaded)' 'TREE OK'
fresh_tree; mkdir "$T/_newthing"; printf 'x\n' > "$T/_newthing/a.php"; tree; expect_refused '7.3 a new top-level folder nobody listed' tree-unknown-entry
says '7.3 the reason names the folder' 'web/_newthing is not on any list'
fresh_tree; printf 'x\n' > "$T/stray.txt"; tree; expect_refused '7.4 a stray top-level file' tree-unknown-entry
fresh_tree; printf 'x\n' > "$T/.env"; tree; expect_refused '7.5 a hidden top-level file' tree-unknown-entry
for d in _apps _core _install _lang _sql _vendor _libraries; do
    fresh_tree; rm -rf "${T:?}/$d"; mkdir "$T/$d"; tree
    case "$d" in
        _core)      expect_refused "7.6 an empty $d (an incomplete copy is never mirrored from)" 'tree-empty-code-folder tree-predates-channel-file' ;;
        _libraries) expect_refused "7.6 an empty $d (an incomplete copy is never mirrored from)" 'tree-empty-code-folder tree-no-dompdf' ;;
        *)          expect_refused "7.6 an empty $d (an incomplete copy is never mirrored from)" tree-empty-code-folder ;;
    esac
done
fresh_tree; rm -rf "$T/_sql"; tree; expect_refused '7.7 a missing code folder' tree-empty-code-folder
fresh_tree; rm "$T/_libraries/dompdf/VERSION.txt"; printf 'x\n' > "$T/_libraries/dompdf/half.php"; tree; expect_refused '7.8 a half-fetched PDF library' tree-no-dompdf
fresh_tree; rm "$T/public_html/index.php"; tree; expect_refused '7.9 the staff pages have no index.php' tree-web-incomplete
fresh_tree; rm "$T/public_html/.htaccess"; tree; expect_refused '7.10 the staff pages have no .htaccess' tree-web-incomplete
fresh_tree; printf 'x\n' > "$T/_includes/helper.php"; tree; expect_refused '7.11 a real file in a folder that is not uploaded' tree-placeholder-has-files

# ---- The "refuse everything" file in the two folders that are not uploaded.
# Since 13 October 2026 web/_includes and web/_functions each hold the one
# .htaccess every private folder holds. The script's first rule ("anything but
# .gitkeep is refused") therefore refused every deploy of the real code, and
# nothing here noticed, because every tree in this section was made up and
# none held that file. So these cases use the REPOSITORY'S OWN two files,
# read from beside this script: if they ever stop being the text the script
# holds, 7.11a fails here instead of on the next deploy.
REAL_WEB="$HERE/../../web"
GUARD="$REAL_WEB/_includes/.htaccess"
if [[ -f "$GUARD" && -f "$REAL_WEB/_functions/.htaccess" ]]; then
    fresh_tree; cp "$GUARD" "$T/_includes/.htaccess"; cp "$REAL_WEB/_functions/.htaccess" "$T/_functions/.htaccess"; tree
    expect_ok "7.11a the repository's own \"refuse everything\" file, in both folders, is accepted" 'TREE OK'
    fresh_tree; cp "$GUARD" "$T/_functions/.htaccess"; tree
    expect_ok '7.11b in one of the two only: accepted' 'TREE OK'

    # A changed one, six ways. Each must be refused for that reason alone.
    fresh_tree; { cat "$GUARD"; printf 'x'; } > "$T/_includes/.htaccess"; tree
    expect_refused '7.11c one byte added at the end' tree-placeholder-htaccess-differs
    says '7.11c the reason names the file' 'web/_includes/.htaccess is not, byte for byte'
    # The same length, so this one is caught by the text and not by the size.
    fresh_tree; sed 's/Require all denied/Require all DENIED/' "$GUARD" > "$T/_functions/.htaccess"; tree
    expect_refused '7.11d its one rule changed, the length kept' tree-placeholder-htaccess-differs
    if [[ "$(cat "$T/_functions/.htaccess")" != "$(cat "$GUARD")" && "$(wc -c < "$T/_functions/.htaccess")" -eq "$(wc -c < "$GUARD")" ]]; then
        pass '7.11d (that case really was a change of text with the length kept)'
    else
        fail '7.11d (that case really was a change of text with the length kept)'
    fi
    fresh_tree; while IFS= read -r line; do printf '%s\r\n' "$line"; done < "$GUARD" > "$T/_includes/.htaccess"; tree
    expect_refused '7.11e the same text with Windows line ends' tree-placeholder-htaccess-differs
    # bash drops a zero byte when it reads a file into a variable, so the right
    # text with zero bytes ADDED would compare as equal: the script asks the
    # size first. This case is the reason it does.
    fresh_tree; { cat "$GUARD"; printf '\0\0'; } > "$T/_includes/.htaccess"; tree
    expect_refused '7.11f the right text with two zero bytes added (bash cannot see them; the size can)' tree-placeholder-htaccess-differs
    fresh_tree; tr '=' '\0' < "$GUARD" > "$T/_includes/.htaccess"; tree
    expect_refused '7.11g zero bytes in place of other bytes, the length kept' tree-placeholder-htaccess-differs
    fresh_tree; : > "$T/_includes/.htaccess"; tree
    expect_refused '7.11h an empty .htaccess' tree-placeholder-htaccess-differs
    fresh_tree; printf 'Require all granted\n' > "$T/_functions/.htaccess"; tree
    expect_refused '7.11i another .htaccess altogether' tree-placeholder-htaccess-differs
    fresh_tree; ln -s "$GUARD" "$T/_includes/.htaccess"; tree
    expect_refused '7.11j a link to the right file is not the right file' tree-placeholder-htaccess-differs
    fresh_tree; mkdir "$T/_includes/.htaccess"; tree
    expect_refused '7.11k a FOLDER of that name' tree-placeholder-htaccess-differs

    # Nothing else was let in with it.
    fresh_tree; cp "$GUARD" "$T/_includes/.htaccess"; printf 'x\n' > "$T/_includes/helper.php"; tree
    expect_refused '7.11l the right file and a stray file beside it: the stray file is still refused' tree-placeholder-has-files
    fresh_tree; mkdir "$T/_functions/sub"; cp "$GUARD" "$T/_functions/sub/.htaccess"; tree
    expect_refused '7.11m the right file one folder down is a stray file' tree-placeholder-has-files
    fresh_tree; ln -s "$GUARD" "$T/_functions/notes.txt"; tree
    expect_refused '7.11n a link under another name is refused (it used not to be looked at)' tree-placeholder-has-files
    fresh_tree; printf 'x\n' > "$T/_includes/.htaccess"; printf 'x\n' > "$T/_includes/helper.php"; tree
    expect_refused '7.11o a changed file and a stray file: both reasons' 'tree-placeholder-has-files tree-placeholder-htaccess-differs'
    fresh_tree; mkdir "$T/_includes/empty-folder"; cp "$GUARD" "$T/_includes/.htaccess"; tree
    expect_ok '7.11p an empty folder inside is not a file (as before)' 'TREE OK'

    # The package checker pins the same file by checksum. The two must mean
    # the same file, or one of them is out of date.
    if command -v sha256sum > /dev/null 2>&1; then SUM="$(sha256sum "$GUARD")"; elif command -v shasum > /dev/null 2>&1; then SUM="$(shasum -a 256 "$GUARD")"; else SUM=''; fi
    SUM="${SUM%% *}"
    if [[ "${#SUM}" -eq 64 ]] && grep -q "\"$SUM\"" "$HERE/../package/check-package.py" 2>/dev/null; then
        pass '7.11q the file this script accepts has the checksum tools/package/check-package.py holds'
    else
        RC=0; OUT="checksum worked out here: ${SUM:-none (no sha256sum and no shasum on this machine)}"
        fail '7.11q the file this script accepts has the checksum tools/package/check-package.py holds'
    fi
else
    RC=0; OUT="looked for $GUARD"
    fail "7.11a-q the repository's own \"refuse everything\" files were not found beside this self-test, so none of these cases ran"
fi
fresh_tree; rm "$T/.channel"; tree; expect_refused '7.12 no channel file' tree-channel-file
fresh_tree; tree beta; expect_refused '7.13 the channel file names another channel' tree-channel-file
fresh_tree; printf 'alpha\nlive\n' > "$T/.channel"; tree; expect_refused '7.14 a channel file with two lines' tree-channel-file
fresh_tree; rm "$T/CHANGELOG.md"; tree; expect_refused '7.15 no change log copy' tree-no-changelog
fresh_tree; printf '<?php // guesses the channel from the folder name\n' > "$T/_core/bootstrap.php"; tree; expect_refused '7.16 code from before the channel file existed' tree-predates-channel-file
fresh_tree; printf '<?php // old\n' > "$T/_core/bootstrap.php"; printf '<?php\nfinal class Channel {\n    private const FILE = "/.channel";\n}\n' > "$T/_core/Channel.php"; tree; expect_ok '7.17 the channel file may be read from Channel.php instead' 'TREE OK'
# A comment that merely mentions the file is not code that reads it.
fresh_tree; printf '<?php // reads PORTAL_ROOT . "/.channel"\n' > "$T/_core/bootstrap.php"; tree; expect_refused '7.17b a // comment on the first line that names the file in quotes does not count' tree-predates-channel-file
fresh_tree; printf '<?php\n// TODO one day read the .channel file\n' > "$T/_core/bootstrap.php"; tree; expect_refused '7.17c a comment that mentions the file without quotes does not count' tree-predates-channel-file
fresh_tree; printf '<?php\n/**\n * Reads the file named '"'"'.channel'"'"'.\n */\n' > "$T/_core/bootstrap.php"; tree; expect_refused '7.17d a mention inside a /** block */ does not count' tree-predates-channel-file
fresh_tree; printf '<?php\n    # the file is ".channel"\ndefine("X", 1); // see ".channel"\n' > "$T/_core/bootstrap.php"; tree; expect_refused '7.17e a # comment, and a // comment after code, do not count' tree-predates-channel-file
fresh_tree; printf '<?php\nconst A = "/.channels";\nconst B = "x.channel.txt";\n' > "$T/_core/bootstrap.php"; tree; expect_refused '7.17f a longer name that merely contains .channel does not count' tree-predates-channel-file
fresh_tree; printf '<?php\n\tconst F = PORTAL_ROOT . DIRECTORY_SEPARATOR . '"'"'.channel'"'"'; // which channel\n' > "$T/_core/bootstrap.php"; tree; expect_ok '7.17g code that names the file, with a comment after it, counts' 'TREE OK'
T="$WORK/no-such-folder/web"; tree; expect_refused '7.18 the web folder is not there at all' tree-missing

# =============================================================================
printf '\n== 8. What the server folder already holds ("remote")\n'
# =============================================================================
L="$WORK/listing.txt"; F="$WORK/fetched.txt"
remote() { run_script -- remote --channel "$@"; }
# holds <name> ...   — a listing of the three kept folders plus those names
holds() { printf '_auth_keys\n_backups\n_uploads\n' > "$L"; if [[ $# -gt 0 ]]; then printf '%s\n' "$@" >> "$L"; fi; }
# Three ways a person might try to tick the box; none may change a "never".
never() {   # never <label> <key> <channel>   — refused with that key, ticked or not
    remote "$3" --listing "$L";                       expect_refused "$1 (not ticked)" "$2"
    remote "$3" --listing "$L" --adopt-existing true; expect_refused "$1 (\"adopt\" ticked: still refused)" "$2"
}

holds
remote alpha --listing "$L" --fetched "$WORK/none.txt"; expect_ok '8.1 an empty channel folder: first deploy' 'REMOTE OK'
says '8.1 it says so' 'first deploy of the alpha channel'
remote alpha --listing "$L"; expect_ok '8.2 the same, with no fetched file named at all' 'REMOTE OK'
# What the real upload program printed for a brand-new folder, before anything
# was created in it: full paths, the folder itself and the folder above.
printf '/home/x/alpha-zqcanary.example.org/./\n/home/x/alpha-zqcanary.example.org/../\n' > "$L"
remote alpha --listing "$L" --fetched "$WORK/none.txt"; expect_ok '8.2b a brand-new folder holding nothing at all (only its own entry and the one above)' 'REMOTE OK'
says '8.2b it is a first deploy' 'first deploy of the alpha channel'
printf './\n../\n\n_uploads/\n' > "$L"
remote beta --listing "$L"; expect_ok '8.2c its own entry, the one above, a blank line and a kept folder are not "something in the folder"' 'REMOTE OK'

printf '.channel\n_apps\n_auth_keys\n_backups\n_core\n_uploads\nadmin_html\n' > "$L"
printf 'alpha\n' > "$F"
remote alpha --listing "$L" --fetched "$F"; expect_ok '8.3 the folder says it is this channel' 'REMOTE OK'
printf '\xef\xbb\xbfalpha\r\n' > "$F"
remote alpha --listing "$L" --fetched "$F"; expect_ok '8.4 the same, saved by a Windows editor (a mark at the start, a Windows line end)' 'REMOTE OK'
printf 'live\n' > "$F"
remote alpha --listing "$L" --fetched "$F"; expect_refused '8.5 the folder says it belongs to ANOTHER channel' remote-other-channel
says '8.5 a known channel word is named' 'belongs to the "live" channel'
remote alpha --listing "$L" --fetched "$F" --adopt-existing true; expect_refused '8.6 ticking "adopt" never overrides a channel file that says otherwise' remote-other-channel
printf 'zqcanaryword\n' > "$F"
remote alpha --listing "$L" --fetched "$F"; expect_refused '8.7 an unknown word in the channel file is refused, and not echoed' remote-other-channel
printf 'alphabet\n' > "$F"
remote alpha --listing "$L" --fetched "$F"; expect_refused '8.8 a word that merely starts like the channel is refused' remote-other-channel
for w in ALPHA Alpha alphA; do
    printf '%s\n' "$w" > "$F"
    remote alpha --listing "$L" --fetched "$F"; expect_refused "8.8b the channel word in other capitals ($w) is not this channel: a deploy only ever writes small letters" remote-other-channel
done
printf 'LIVE\n' > "$F"
remote live --listing "$L" --fetched "$F"; expect_refused '8.8c the same for live' remote-other-channel

: > "$F"
remote alpha --listing "$L" --fetched "$F"; expect_refused '8.9 a channel file is listed but came back empty' remote-channel-file-unreadable
remote alpha --listing "$L" --fetched "$WORK/none.txt"; expect_refused '8.10 a channel file is listed but could not be fetched' remote-channel-file-unreadable
remote alpha --listing "$L" --fetched "$WORK/none.txt" --adopt-existing true; expect_refused '8.11 ticking "adopt" does not excuse an unreadable channel file' remote-channel-file-unreadable
mkdir "$WORK/fetched-is-a-folder"; holds
remote alpha --listing "$L" --fetched "$WORK/fetched-is-a-folder"; expect_refused '8.11b what was fetched is a folder, not a file: refused, not read (and not taken for "no channel file")' remote-channel-file-unreadable

# --- A portal with no channel file, and NOTHING else: the one thing the tick is for.
# First as the old shared layout left it, then as a deploy in this layout leaves it.
OLD_LAYOUT='_apps _core _install _lang _sql _vendor _libraries public_html public_html_beta public_html_dev CHANGELOG.md'
NEW_LAYOUT="_apps _core _install _lang _sql _vendor _libraries $DEFAULT_WEB CHANGELOG.md"
for ch in live beta alpha; do
    # shellcheck disable=SC2086  # the names are meant to be split into separate words
    holds $OLD_LAYOUT
    remote "$ch" --listing "$L"; expect_refused "8.12 $ch: an old-layout portal with no channel file is refused until a person says whose it is" remote-unmarked-installation
    remote "$ch" --listing "$L" --adopt-existing true; expect_ok "8.13 $ch: the same, adopted by hand" 'REMOTE OK'
    says "8.13 $ch: it says it was adopted by hand" "adopting it as the $ch channel"
    for tick in TRUE True ' true' 'true ' yes 1 false ''; do
        remote "$ch" --listing "$L" --adopt-existing "$tick"; expect_refused "8.14 $ch: only exactly \"true\" adopts (given: \"$tick\")" remote-unmarked-installation
    done
    # shellcheck disable=SC2086  # the names are meant to be split into separate words
    holds $NEW_LAYOUT
    remote "$ch" --listing "$L"; expect_refused "8.15 $ch: a portal in this layout with no channel file, not ticked" remote-unmarked-installation
    remote "$ch" --listing "$L" --adopt-existing true; expect_ok "8.15 $ch: the same, adopted by hand" 'REMOTE OK'
done
holds _core
remote live --listing "$L"; expect_refused '8.15b a folder holding only _core, not ticked' remote-unmarked-installation
printf 'live\n' > "$F"
remote live --listing "$L" --fetched "$F"; expect_ok '8.16 a fetched channel file is believed even when the listing did not show it' 'REMOTE OK'

printf '/home/x/alpha.example.org/.channel\n/home/x/alpha.example.org/_auth_keys/\n/home/x/alpha.example.org/_core/\r\n' > "$L"
printf 'alpha\n' > "$F"
remote alpha --listing "$L" --fetched "$F"; expect_ok '8.17 a listing given as full paths, with folder marks and a Windows line end' 'REMOTE OK'
remote alpha --listing "$L" --fetched "$WORK/none.txt"; expect_refused '8.18 that listing is understood: its channel file is missed when it cannot be fetched' remote-channel-file-unreadable

printf '_core\n_uploads\n' > "$L"
remote alpha --listing "$L" --fetched "$F"; expect_refused '8.19 a listing that shows neither the folder own entry nor _auth_keys: "could not look" is not "nothing there"' remote-listing-unreadable
: > "$L"
remote alpha --listing "$L"; expect_refused '8.20 an empty listing' remote-listing-unreadable
remote alpha --listing "$WORK/none.txt" --adopt-existing true; expect_refused '8.21 no listing at all, even with "adopt" ticked' remote-listing-unreadable
printf 'my_auth_keys\nx_core\n' > "$L"
remote alpha --listing "$L"; expect_refused '8.22 a name that merely ends like _auth_keys does not count' remote-listing-unreadable
printf '../\n_core\n' > "$L"
remote alpha --listing "$L"; expect_refused '8.22b the entry for the folder ABOVE is not proof that this one was read' remote-listing-unreadable
printf 'drwxr-xr-x  2 u g 4096 Oct 12 08:00 .\ndrwxr-xr-x  2 u g 4096 Oct 12 08:00 _auth_keys\n' > "$L"
remote alpha --listing "$L"; expect_refused '8.22c a listing in a shape this script does not read (long format) is not guessed at' remote-listing-unreadable

# --- An occupied folder is never "a first deploy", and the tick cannot make it one.
# (The first version of the script answered "no portal yet, first deploy" to all of these.)
holds index.php wp-admin/ wp-content/ wp-config.php .htaccess
never '8.23 somebody else website in the folder' remote-folder-not-empty live
says  '8.23 the reason says the tick does not help' 'does not change this answer'
says  '8.23 the reason counts what is there, and names none of it' 'it holds 5 other thing(s)'
holds index.php .htaccess assets/ api-docs/ error.php
never '8.24 an old-layout WEB folder (the folder an address serves)' remote-folder-not-empty live
holds _apps/ _sql/ _vendor/ _lang/ _install/ _libraries/ admin_html/
never '8.25 every portal folder EXCEPT _core' remote-folder-not-empty live
holds _Core/ _Apps/
never '8.26 a portal whose folders are listed with other capitals (_Core is not _core)' remote-folder-not-empty live
holds logs/ Maildir/ portal-zqcanary.example.org/ zqcanary.example.org/ .bashrc .ssh/
never '8.27 the account home folder (its names are never printed back: the leak test watches for one)' remote-folder-not-empty live
holds index.html
never '8.28 a single stray file is enough' remote-folder-not-empty alpha
says  '8.28 the count is 1' 'it holds 1 other thing(s)'
holds .CHANNEL
never '8.29 a file named .CHANNEL is not a channel file' remote-folder-not-empty alpha
holds .htaccess
never '8.30 a hidden file alone' remote-folder-not-empty beta

# --- A portal mixed in with other things is not "an old-layout portal".
# shellcheck disable=SC2086  # the names are meant to be split into separate words
holds $OLD_LAYOUT .bashrc .ssh/ Maildir/ zqcanary.example.org/
never '8.31 a home folder with a portal unpacked into it' remote-portal-among-other-things live
says  '8.31 the reason counts the other things' 'but also 4 thing(s)'
# shellcheck disable=SC2086  # the names are meant to be split into separate words
holds $OLD_LAYOUT wp-config.php
never '8.32 a portal and one file of another website' remote-portal-among-other-things beta
holds _core .CHANNEL
never '8.33 a portal and a file named .CHANNEL' remote-portal-among-other-things alpha
holds _core _Apps
never '8.34 a portal folder beside one with other capitals' remote-portal-among-other-things alpha
holds _core staff_html
never '8.35 a web folder under a name nobody told the script about' remote-portal-among-other-things live
run_script DEPLOY_STAFF_WEB_DIR=staff_html -- remote --channel live --listing "$L" --adopt-existing true
expect_ok '8.35b the same folder once DEPLOY_STAFF_WEB_DIR names it, adopted by hand' 'REMOTE OK'
run_script DEPLOY_STAFF_WEB_DIR=staff_html -- remote --channel live --listing "$L"
expect_refused '8.35c and still refused without the tick' remote-unmarked-installation

# --- The one way in for a folder the script does not recognise: a channel
# file put there by hand. It takes somebody with access to the server.
holds .channel index.php wp-admin/ .bashrc
printf 'alpha\n' > "$F"
remote alpha --listing "$L" --fetched "$F"; expect_ok '8.36 a folder marked by hand as this channel is this channel, whatever else it holds' 'REMOTE OK'
remote beta --listing "$L" --fetched "$F" --adopt-existing true; expect_refused '8.36b and only that channel' remote-other-channel

# =============================================================================
printf '\n== 9. A caller cannot make the script print a value, and what it needs to run\n'
# =============================================================================
# Three ways a caller can have command tracing switched on before the script's
# first line runs. bash would then print every command with its values filled
# in, passwords and keys included. The script switches tracing off first.
TRACE_SETTINGS=("SFTP_PASSWORD=$FAKE_PW" SFTP_PORT=50122 ADMIN_BASE_URL_ALPHA=https://staff-zqcanary.example.org)
printf 'set -x\n' > "$WORK/trace-on.sh"
untraced() {   # untraced <label> <closing word>   — after a run_script
    # The only traced lines allowed are the two that hold no value at all: the
    # script's own "switch tracing off", and the caller's file switching it on.
    local plus
    plus="$(printf '%s\n' "$OUT" | grep '^+' | grep -c -v -x -e '+ set +xv' -e '+ set -x')"
    if [[ "$LEAKED" -eq 0 && "$plus" -eq 0 && "$OUT" != *50122* && "$OUT" != *zqcanaryPW7* && "$NL$OUT$NL" == *"$NL$2$NL"* ]]; then
        pass "$1"
    else
        fail "$1  [wanted no value printed, no traced line except the one that switches tracing off, and the closing word $2; other traced lines: $plus]"
    fi
}
BASH_FLAGS=(-x)
run_script "${BASE[@]}" "${TRACE_SETTINGS[@]}" -- resolve --channel alpha --plan-file "$PLAN"
untraced '9.1 run as "bash -x": no setting value is printed' 'RESOLVED'
run_script "${BASE[@]}" "${TRACE_SETTINGS[@]}" SFTP_HOST= -- resolve --channel alpha --plan-file "$PLAN"
untraced '9.1b run as "bash -x", and refused: no setting value is printed' 'Nothing was connected to and nothing was uploaded. Problems found: 1.'
BASH_FLAGS=(-xv)
run_script "${BASE[@]}" "${TRACE_SETTINGS[@]}" -- resolve --channel alpha --plan-file "$PLAN"
if [[ "$LEAKED" -eq 0 && "$OUT" != *50122* && "$OUT" == *RESOLVED ]]; then pass '9.1c run as "bash -xv": no setting value is printed'; else fail '9.1c run as "bash -xv": no setting value is printed'; fi
BASH_FLAGS=()
run_script "${BASE[@]}" "${TRACE_SETTINGS[@]}" SHELLOPTS=xtrace -- resolve --channel alpha --plan-file "$PLAN"
untraced '9.2 tracing handed down in SHELLOPTS: no setting value is printed' 'RESOLVED'
run_script "${BASE[@]}" "${TRACE_SETTINGS[@]}" "BASH_ENV=$WORK/trace-on.sh" -- resolve --channel alpha --plan-file "$PLAN"
untraced '9.3 tracing switched on by a BASH_ENV file: no setting value is printed' 'RESOLVED'
run_script "${BASE[@]}" "${TRACE_SETTINGS[@]}" SHELLOPTS=xtrace "BASH_ENV=$WORK/trace-on.sh" -- resolve --branch feature/zqcanary --plan-file "$PLAN"
untraced '9.3b both at once, on a refused run' 'Nothing was connected to and nothing was uploaded. Problems found: 1.'

# The script's header says which programs it starts besides bash. Here it is
# given a PATH that holds those and nothing else, so a new one cannot creep in
# unnoticed. Capital letters in the host make it use tr; "tree" uses find and cat;
# an old plan file makes it use rm.
ONLY="$WORK/onlybin"; mkdir "$ONLY"
for tool in tr find rm cat; do ln -s "$(command -v "$tool")" "$ONLY/$tool"; done
RUN_PATH="$ONLY"
printf 'stale\n' > "$PLAN"; KEEP_PLAN=1
run_script "${BASE[@]}" SFTP_HOST=SFTP-zqcanary.Example.ORG -- resolve --channel live --plan-file "$PLAN"
KEEP_PLAN=0
expect_ok '9.4 "resolve" runs with only tr, find, rm and cat within reach' 'RESOLVED'
plan_is   '9.4 and wrote a new plan over the old one' DEPLOY_CHANNEL live
T="$GOOD"; tree; expect_ok '9.5 "tree" runs with only those four' 'TREE OK'
# The same with the "refuse everything" file to be compared: no fifth program.
T="$WORK/tree-with-guard/web"; mkdir -p "$WORK/tree-with-guard"; cp -R "$GOOD" "$T"
cp "$HERE/../../web/_includes/.htaccess" "$T/_includes/.htaccess"; cp "$HERE/../../web/_functions/.htaccess" "$T/_functions/.htaccess"
tree; expect_ok '9.5b "tree" compares the "refuse everything" file with only those four' 'TREE OK'
holds; remote alpha --listing "$L"; expect_ok '9.6 "remote" runs with only those four' 'REMOTE OK'
RUN_PATH="$PATH"

# =============================================================================
printf '\nresolve-settings self-test: %s checks, %s passed, %s failed.\n' "$((PASSED + FAILED))" "$PASSED" "$FAILED"
if [[ "$FAILED" -eq 0 ]]; then FINAL=0; else FINAL=1; fi
REACHED_END=1
printf 'SELFTEST REACHED ITS END\n'
exit "$FINAL"
