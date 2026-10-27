#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# tools/deploy/resolve-settings.sh — decides where a deploy may put files and
# which hosting login it uses, and REFUSES whenever it cannot be sure.
# -----------------------------------------------------------------------------
# @package   WebMS-Intra (development tooling — never deployed; only web/ is)
# @author    Salem874
# @copyright MWBM Partners Ltd (t/a MWservices). All Rights Reserved.
# @version   1.2.0 (13 October 2026: the "tree" question accepts the one
#            "refuse everything" file in the two folders that are not uploaded;
#            before it, 1.1.0 of 12 October 2026, issue #500, roll-out package
#            F7, the second version, after the independent check of the first)
#
# WHY THIS EXISTS
# The deploy uploads with deletion: a file that is not in the repository is
# removed from the server folder it is mirrored into. So the one thing that
# must never be wrong is WHICH folder, on WHICH server. Until now that was
# worked out by shell lines inside the workflow file, which nothing could test.
# This script holds every such decision so that
# tools/deploy/resolve-settings-selftest.sh can prove each rule and each
# refusal on any machine, before GitHub or a server is involved.
#
# The rules are the owner's decisions of 13 September 2026: every release
# channel (live, beta, alpha) is a complete, separate installation in its own
# folder; hosting is set per channel; nothing is built in.
#
# THE THREE QUESTIONS IT ANSWERS (one per run)
#
#   resolve  --branch <main|beta|alpha> | --channel <live|beta|alpha>
#            [--plan-file <file>]
#       Reads the settings from environment variables (the way a workflow
#       passes them) and decides the login and the folders. Connects to nothing.
#
#   tree     --web-dir <folder> --channel <live|beta|alpha>
#       Checks the local web/ folder just before upload: every entry is one this
#       script knows what to do with, no folder that is mirrored with deletion
#       is empty, and the two folders that are NOT uploaded hold nothing but
#       .gitkeep and the "refuse everything" file. Connects to nothing.
#
#   remote   --channel <live|beta|alpha> --listing <file> [--fetched <file>]
#            [--adopt-existing true]
#       Given what the workflow read from the server folder (a listing, and its
#       channel file if it has one), says whether that folder may be written to.
#       The workflow does the connecting; this only judges. The answer is yes
#       for exactly three kinds of folder:
#         - one whose channel file says it is this channel's;
#         - an EMPTY one (nothing but the three folders the server keeps);
#         - a portal with no channel file and NOTHING ELSE in it, and only when
#           "--adopt-existing true" was given by a person (the old shared layout).
#       Anything else is refused, whatever was ticked: somebody else's website,
#       a web folder, a home folder, a portal mixed in with other things.
#
# EXIT CODES
#   0  go ahead        1  refused (every reason is printed)        2  called wrongly
#
# THE SETTINGS (resolve)
#   Shared login:        SFTP_HOST  SFTP_USER  SFTP_KEY or SFTP_PASSWORD  SFTP_PORT
#   Shared base folder:  SFTP_PATH_ROOT_DIR          (a full path)
#   One folder each:     SFTP_PATH_LIVE_DIR  SFTP_PATH_BETA_DIR  SFTP_PATH_ALPHA_DIR
#                        (a NAME inside the base folder, never a path)
#   Optional, per channel (<X> is LIVE, BETA or ALPHA):
#     its own login:       SFTP_HOST_<X>  SFTP_USER_<X>  SFTP_KEY_<X> or
#                          SFTP_PASSWORD_<X>  SFTP_PORT_<X>
#     its own base folder: SFTP_PATH_<X>_ROOT_DIR
#   Not secret:          SFTP_ENABLED (must be exactly "true": the four small
#                        letters, with no space or line break before or after)
#                        ADMIN_BASE_URL_LIVE / _BETA / _ALPHA (the staff address
#                        used for the health check; empty means "skip it")
#
#   A CHANNEL'S OWN LOGIN IS ALL OR NOTHING. If any one of a channel's five
#   login settings is set, its host, user and key-or-password must ALL come from
#   that channel's settings, and its port is its own or 22. Nothing is ever
#   borrowed from the shared login. Why: "a new host with the shared user and
#   password" may well sign in, and would then write the shared base folder's
#   path onto the wrong server; "a new user on the shared host" writes into that
#   user's home. Neither can be told from a deliberate choice by looking at the
#   values, so the shape itself is refused.
#   Different channels MAY use different arrangements (live on its own login,
#   beta and alpha on the shared one): that is what the owner asked for.
#
# WHAT IT PRINTS, AND WHAT IT NEVER PRINTS
# It says which SETTING supplied each value ("host: from SFTP_HOST_LIVE"),
# never the value. That holds for every message, refusals included, and the
# self-test searches everything printed for the values it fed in. The resolved
# host, user, port and folders are written ONLY to the --plan-file (readable
# by its owner alone), because the upload step has to get them from somewhere.
# A password or key is never written anywhere, not even there: the plan file
# holds the NAME of the setting to sign in with.
# A caller that has command tracing switched on (bash -x, or SHELLOPTS or
# BASH_ENV in its environment) would make bash itself print every value, so the
# first thing this script does is switch tracing off. It cannot stop a caller
# that prints the settings by some other means of its own.
# A plan file named with --plan-file is REMOVED before anything else is looked
# at, so a plan from an earlier run can never be read after a run that refused
# or was called wrongly. Only a run that ends in RESOLVED writes it again.
#
# WHAT IT CANNOT DO
#   - It cannot tell that two different host names are one server, or that two
#     users share a home folder. The "remote" question is the backstop for that.
#   - It does not look at a password or a key beyond "is it set" (and, for a
#     password, "does it start or end with a space or line break").
#   - It never connects, so it cannot know a folder exists or a login works.
#     The first real proof is a dry run of the workflow.
#
# PORTABILITY
# Plain bash, version 3.2 or later (the newer bash on a GitHub runner, and the
# old one a Mac ships): no associative arrays, no ${var,,}, no mapfile. Outside
# bash it starts only tr, find, rm and cat; the self-test runs it with nothing
# else within reach to keep that sentence true. (It used to say "tr, grep and
# find", which left out rm and cat; grep is no longer used.)
# -----------------------------------------------------------------------------

# Tracing off, before anything else: see "WHAT IT PRINTS" above. This used to
# be missing, and "bash -x" printed every password and key.
set +xv
set -euo pipefail
# Letter ranges such as [A-Za-z] mean different things in different languages'
# sort orders; in the plain "C" setting they mean exactly the 26 letters.
export LC_ALL=C

# =============================================================================
# 📌 THE ONE LINE TO CHANGE for owner decision 3 (the F plan, section 8)
# The name of the staff web folder ON THE SERVER, inside each channel's folder.
# "admin_html" is the recommendation and the owner's own naming of 13 September
# 2026. If the answer is different, change this line and nothing else.
# (DEPLOY_STAFF_WEB_DIR in the environment overrides it for one run; the
# proposed workflow does not set that.)
# =============================================================================
STAFF_WEB_DIR_DEFAULT='admin_html'

# 📂 What is in the repository's web/ folder, and what happens to each part.
# This is the only list of its kind: the "tree" question refuses any entry that
# is on none of these lists, so a new folder cannot be silently left behind.
REPO_WEB_FOLDER='public_html'                 # the staff pages; goes to <channel>/<staff web folder>/
CODE_FOLDERS='_apps _core _install _lang _sql _vendor _libraries'   # each goes to <channel>/<same name>/, with deletion
KEPT_FOLDERS='_auth_keys _uploads _backups'   # the server's own: created if missing, never uploaded to, never deleted from
PLACEHOLDER_FOLDERS='_includes _functions'    # not deployed; may hold .gitkeep and the "refuse everything" file below, and nothing else
OTHER_NOT_DEPLOYED='private_html public_html_landing public_html_redir'  # kept in the repository by the owner's choice (14 September 2026); never deployed
TOP_FILES='CHANGELOG.md .channel'             # single files placed in the channel's folder
# The three web folders the OLD shared layout kept side by side in one folder
# (the deploy workflow before this script named exactly these). They matter
# only to the "remote" question, which must recognise an old-layout portal.
OLD_LAYOUT_WEB_FOLDERS='public_html public_html_beta public_html_dev'

# 🚫 THE "REFUSE EVERYTHING" FILE, byte for byte.
# Since 13 October 2026 every private folder in web/ holds one .htaccess that
# tells the web server to hand out nothing from it (the installer work, #499
# and #500). That includes the two placeholder folders, which until then held
# .gitkeep only. This script's rule for those two folders was "anything but
# .gitkeep is refused", so from that day it refused EVERY deploy, before
# connecting to anything. Found the first time the rule met the real code.
#
# The rule now lets in exactly one more thing: a file called .htaccess, at the
# top of the placeholder folder, that is THIS text and nothing else. A changed
# one is refused, and so is any other file, because that folder is never
# uploaded: a rule somebody writes there would never reach a server, and the
# old refusal exists to say so out loud.
#
# WHY THE TEXT IS HERE AND NOT A CHECKSUM. tools/package/check-package.py pins
# the same file by its SHA-256. A checksum needs a program to work it out
# (sha256sum on one machine, shasum on another), and this script starts only
# tr, find, rm and cat: see PORTABILITY above. Comparing text needs none. The
# self-test ties the two together: it checks that the repository's own copies
# are accepted, and that their checksum is the one check-package.py holds.
#
# WHEN THE FILE IS CHANGED ON PURPOSE (in all its folders, and in
# ALLOWED_HTACCESS in check-package.py): put the new text here, between the two
# marker lines, exactly. The self-test fails until the three agree.
#
# "read -d ''" reads up to a byte that never comes, so it takes the whole
# text, last line break included, and then reports "end of input" as a
# failure: hence "|| true". ($(cat <<...) was not used: the bash a Mac ships
# cannot read an apostrophe inside it.)
IFS= read -r -d '' REFUSE_EVERYTHING_TEXT <<'REFUSE_EVERYTHING_END' || true
# =============================================================================
# Refuse every request for anything in this folder.
#
# Nothing here is ever meant to be fetched by a browser: the portal's own code
# reads these files from disk. On a correct installation this folder sits
# OUTSIDE the web folder and this file is never consulted. It matters only when
# a hosting panel is pointed at the wrong folder (the one that holds _core,
# instead of the web folder inside it): without it the web server would then
# hand out these files to anybody who asked.
#
# The same file, byte for byte, sits in _apps, _core, _functions, _includes,
# _lang, _sql and _vendor, and the installation package copies it into
# _libraries. The installer writes it into _auth_keys, _uploads and _backups
# when an installation is finished, and the upgrade page puts it back there
# whenever it is missing.
#
# WHAT IT CANNOT DO: it is read only by Apache 2.4 or later (and servers that
# follow its rules files) with rules files switched on for this folder. On any
# other web server it does nothing, and the folder must be kept out of reach
# by where it is. An installation made before October 2026 does not have it in
# _auth_keys, _uploads or _backups until its upgrade page has been opened once.
# =============================================================================
Require all denied
REFUSE_EVERYTHING_END
readonly REFUSE_EVERYTHING_TEXT

CHANNELS='LIVE BETA ALPHA'

# Settings from the two earlier schemes. Nothing reads them. They are only
# WARNED about, never refused: an organisation-wide secret is visible to every
# repository, so another project adding one of these names must not be able to
# stop this project's deploys.
RETIRED_SETTINGS='SFTP_LIVE_PATH SFTP_BETA_PATH SFTP_DEV_PATH SFTP_PATH_LIVE_ADMIN_DIR SFTP_PATH_LIVE_PUBLIC_DIR SFTP_PATH_BETA_ADMIN_DIR SFTP_PATH_BETA_PUBLIC_DIR SFTP_PATH_ALPHA_ADMIN_DIR SFTP_PATH_ALPHA_PUBLIC_DIR'

# The shapes a value may have. Deliberately narrow: these values are later put
# into SFTP commands, so a space, a semicolon or a quote must never get through.
RE_HOST='^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$'
RE_USER='^[A-Za-z0-9_][A-Za-z0-9_.-]*$'
RE_PORT='^[0-9]{1,5}$'
RE_ROOT='^(/[A-Za-z0-9_.-]+)+$'
RE_DIR='^[A-Za-z0-9_-][A-Za-z0-9._-]*$'
RE_URL='^https?://[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(:[0-9]{1,5})?$'

# =============================================================================
# 🧰 Small helpers
# =============================================================================

REFUSALS=0
SEEN_REFUSALS='|'

# One refusal. $1 is a short fixed key (the self-test matches on it, and it is
# what to search this file for); $2 is the plain-English reason.
# Every reason is printed, not only the first, so that somebody setting up a
# new server sees the whole list in one run instead of one per attempt.
# The same reason is printed once even when three channels trip over it.
refuse() {
    local line="REFUSED ($1): $2"
    case "$SEEN_REFUSALS" in
        *"|$line|"*) return 0 ;;
    esac
    SEEN_REFUSALS="$SEEN_REFUSALS$line|"
    REFUSALS=$((REFUSALS + 1))
    printf '::error::%s\n' "$line"
}

warn() { printf '::warning::%s\n' "$1"; }

usage_error() {
    printf 'resolve-settings.sh: %s\n' "$1" >&2
    printf 'Usage: resolve-settings.sh resolve --branch <main|beta|alpha> | --channel <live|beta|alpha> [--plan-file <file>]\n' >&2
    printf '       resolve-settings.sh tree --web-dir <folder> --channel <live|beta|alpha>\n' >&2
    printf '       resolve-settings.sh remote --channel <live|beta|alpha> --listing <file> [--fetched <file>] [--adopt-existing true]\n' >&2
    exit 2
}

# The value of the setting NAMED $1, with spaces and line breaks removed from
# both ends (a pasted value often carries a line break). An unset setting is
# the same as an empty one. The answer is left in REPLY.
get() {
    local v="${!1-}"
    v="${v#"${v%%[![:space:]]*}"}"
    v="${v%"${v##*[![:space:]]}"}"
    REPLY="$v"
}

# $1 in small letters, left in REPLY. bash 3.2 has no built-in way to do this,
# so "tr" is started only when there is a capital letter to change.
lower() {
    if [[ "$1" == *[A-Z]* ]]; then
        REPLY="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')"
    else
        REPLY="$1"
    fi
}

# Is the word $1 in the space-separated list $2?
in_list() {
    local w
    for w in $2; do
        if [[ "$w" == "$1" ]]; then return 0; fi
    done
    return 1
}

val()   { local n="V_$1"; REPLY="${!n-}"; }       # the cleaned value of a setting
state() { local n="S_$1"; REPLY="${!n-empty}"; }  # empty | ok | bad
rget()  { local n="R_${1}_${2}"; REPLY="${!n-}"; }  # one resolved fact about a channel
rset()  { printf -v "R_${1}_${2}" '%s' "$3"; }

# Is $1 (compared without regard to capitals) the name of one of the portal's
# own folders? A base folder or a channel folder with such a name means
# somebody has pasted the wrong line from their hosting panel.
is_own_folder_name() {
    lower "$1"
    in_list "$REPLY" "admin_html public_html $STAFF_WEB_DIR_LOWER $CODE_FOLDERS $KEPT_FOLDERS"
}

# =============================================================================
# 🧼 Step 1 of "resolve": clean every setting, each by its kind
# A setting that is set but has the wrong shape is refused here, whichever
# channel is being deployed: the settings as a whole must be sound.
# =============================================================================
clean() {
    local name="$1" kind="$2" v st='ok' last
    get "$name"
    v="$REPLY"
    if [[ -z "$v" ]]; then
        st='empty'
    elif [[ "$kind" == 'key' ]]; then
        # A key is many lines by nature, so no shape is checked. It is not kept.
        v=''
    elif [[ "$kind" == 'password' ]]; then
        # The upload step has to use the password exactly as stored (this script
        # cannot hand a tidied copy back without printing it). So a stray space
        # or line break at either end is refused here, with the cause named,
        # rather than left to fail at sign-in with a message that would not say why.
        if [[ "${!name}" != "$v" ]]; then
            refuse password-edge-space "$name begins or ends with a space or a line break. Set it again without one (a value piped in with echo carries a line break)."
            st='bad'
        fi
        v=''
    elif [[ "$v" == *[[:space:]]* ]]; then
        refuse inner-space "$name has a space, tab or line break in the middle of it. Set it again as one unbroken value."
        st='bad'
    else
        case "$kind" in
            host)
                if ! [[ "$v" =~ $RE_HOST ]]; then
                    refuse host-shape "$name must be a bare server name such as sftp.example.org: no sftp:// in front, no slash, no port, no user name."
                    st='bad'
                fi
                ;;
            user)
                if ! [[ "$v" =~ $RE_USER ]]; then
                    refuse user-shape "$name must be a plain account name such as deployer (letters, digits, underscore, dot, hyphen)."
                    st='bad'
                fi
                ;;
            port)
                # The 10# stops a value such as 0022 being read as an octal number.
                if ! [[ "$v" =~ $RE_PORT ]] || [[ $((10#$v)) -lt 1 || $((10#$v)) -gt 65535 ]]; then
                    refuse port-shape "$name must be a whole number from 1 to 65535, such as 22. (This used to fall back to 22 quietly; it now stops, because a mistyped port usually means the wrong server.)"
                    st='bad'
                else
                    v="$((10#$v))"
                fi
                ;;
            root)
                v="${v%/}"   # ONE trailing slash is allowed and dropped
                if ! [[ "$v" =~ $RE_ROOT ]] || [[ "$v/" == */./* || "$v/" == */../* ]]; then
                    refuse root-shape "$name must be a full path that starts with / and names a folder, such as /home/deployer (letters, digits, underscore, dot, hyphen; no . or .. part; not / alone). A path that does not start with / would be created inside wherever the SFTP session happens to begin."
                    st='bad'
                else
                    last="${v##*/}"
                    if is_own_folder_name "$last"; then
                        refuse root-is-own-folder "$name ends in the name of one of the portal's own folders. It must be the folder ABOVE the channel's folder, and the channel's folder is the one ABOVE the web folder shown in your hosting panel. Example: /home/deployer, with the channel folder portal.example.org inside it."
                        st='bad'
                    fi
                fi
                ;;
            dir)
                v="${v%/}"   # ONE trailing slash is allowed and dropped
                if ! [[ "$v" =~ $RE_DIR ]]; then
                    refuse dir-shape "$name must be the NAME of one folder, such as portal.example.org: no slash at the start or in the middle, no leading dot, not . or ..  The full path is built by joining it to the base folder."
                    st='bad'
                elif is_own_folder_name "$v"; then
                    # Why this matters: the hosting panel's "web directory" box shows the
                    # web folder, so this mix-up is likely. With it, the code folders
                    # (the database script included) would be mirrored INTO the folder
                    # the address serves.
                    refuse dir-is-own-folder "$name is the name of one of the portal's own folders. It must name the channel's folder, which is the folder ABOVE the web folder shown in your hosting panel. Example: portal.example.org."
                    st='bad'
                fi
                ;;
            url)
                v="${v%/}"   # ONE trailing slash is allowed and dropped
                if ! [[ "$v" =~ $RE_URL ]]; then
                    refuse health-address-shape "$name must be the staff address with nothing after it, such as https://portal.example.org (it must begin https:// or http://, and have no path). Leave it empty to skip the health check."
                    st='bad'
                fi
                ;;
        esac
    fi
    if [[ "$st" == 'bad' ]]; then v=''; fi
    printf -v "V_$name" '%s' "$v"
    printf -v "S_$name" '%s' "$st"
}

# =============================================================================
# 🔑 Step 2 of "resolve": combine the settings for one channel
# =============================================================================
resolve_channel() {
    local X="$1" n first_set='' login
    local host_n user_n key_n pass_n port_n root_n dir_n ks ps
    local host='' user='' port='' port_from='' signin='' signin_n='' root='' dir='' chroot=''

    # --- Which login group? Any ONE of the five makes it the channel's own. ---
    for n in "SFTP_HOST_$X" "SFTP_USER_$X" "SFTP_PASSWORD_$X" "SFTP_KEY_$X" "SFTP_PORT_$X"; do
        state "$n"
        if [[ "$REPLY" != 'empty' && -z "$first_set" ]]; then first_set="$n"; fi
    done
    if [[ -n "$first_set" ]]; then
        login='channel'
        host_n="SFTP_HOST_$X"; user_n="SFTP_USER_$X"; key_n="SFTP_KEY_$X"; pass_n="SFTP_PASSWORD_$X"; port_n="SFTP_PORT_$X"
    else
        login='shared'
        host_n='SFTP_HOST'; user_n='SFTP_USER'; key_n='SFTP_KEY'; pass_n='SFTP_PASSWORD'; port_n='SFTP_PORT'
    fi

    # --- Host and user: required, and only ever from the chosen group. ---
    for n in "$host_n" "$user_n"; do
        state "$n"
        if [[ "$REPLY" == 'empty' ]]; then
            if [[ "$login" == 'channel' ]]; then
                refuse login-half-set "$first_set is set but $n is empty. A channel's own login is all or nothing: set SFTP_HOST_$X, SFTP_USER_$X and SFTP_KEY_$X or SFTP_PASSWORD_$X together, or clear all five so the channel uses the shared login. The shared host, user, key and password are never mixed in."
            else
                refuse shared-login-missing "$n is empty, and at least one channel relies on the shared login. Set it, or give every channel its own login."
            fi
        fi
    done
    state "$host_n"; if [[ "$REPLY" == 'ok' ]]; then val "$host_n"; host="$REPLY"; fi
    state "$user_n"; if [[ "$REPLY" == 'ok' ]]; then val "$user_n"; user="$REPLY"; fi

    # --- Sign-in: the group's key, else the group's password, else stop. ---
    state "$key_n";  ks="$REPLY"
    state "$pass_n"; ps="$REPLY"
    if [[ "$ks" == 'ok' ]]; then
        signin='key'; signin_n="$key_n"
    elif [[ "$ps" == 'ok' ]]; then
        signin='password'; signin_n="$pass_n"
    elif [[ "$ps" == 'bad' ]]; then
        :   # already refused in step 1
    elif [[ "$login" == 'channel' ]]; then
        refuse login-half-set "$first_set is set but neither SFTP_KEY_$X nor SFTP_PASSWORD_$X is. A channel with its own login never signs in with the shared key or password. Set one of them, or clear all five so the channel uses the shared login."
    else
        refuse shared-login-missing "Neither SFTP_KEY nor SFTP_PASSWORD is set, and at least one channel relies on the shared login."
    fi

    # --- Port: the group's own, else 22. A channel with its own login never
    #     takes the shared port: that port belongs to the shared server. ---
    state "$port_n"
    if [[ "$REPLY" == 'ok' ]]; then
        val "$port_n"; port="$REPLY"; port_from="$port_n"
    elif [[ "$REPLY" == 'empty' ]]; then
        port='22'; port_from='default'
    fi

    # --- Where: the channel's own base folder if it has one, else the shared one. ---
    state "SFTP_PATH_${X}_ROOT_DIR"
    if [[ "$REPLY" != 'empty' ]]; then root_n="SFTP_PATH_${X}_ROOT_DIR"; else root_n='SFTP_PATH_ROOT_DIR'; fi
    state "$root_n"
    if [[ "$REPLY" == 'ok' ]]; then
        val "$root_n"; root="$REPLY"
    elif [[ "$REPLY" == 'empty' ]]; then
        refuse root-missing "Neither SFTP_PATH_${X}_ROOT_DIR nor SFTP_PATH_ROOT_DIR is set, so there is no base folder for the $X channel. Example: /home/deployer."
    fi
    dir_n="SFTP_PATH_${X}_DIR"
    state "$dir_n"
    if [[ "$REPLY" == 'ok' ]]; then
        val "$dir_n"; dir="$REPLY"
    elif [[ "$REPLY" == 'empty' ]]; then
        # All three are needed on every run, whichever channel is being deployed:
        # without them "two channels in one place" cannot be ruled out.
        refuse dir-missing "$dir_n is empty. All three channel folders must be named on every run, so that no two channels can be sent to one place. Example: portal.example.org."
    fi

    if [[ -n "$root" && -n "$dir" ]]; then
        # /home/deployer/portal.example.org + portal.example.org would build the
        # folder inside itself. This is the value that was set on 11 September 2026
        # under the scheme before this one, so it is the likeliest mistake of all.
        lower "${root##*/}"; n="$REPLY"
        lower "$dir"
        if [[ "$n" == "$REPLY" ]]; then
            refuse root-ends-in-channel-folder "$root_n already ends in the folder that $dir_n names, so the channel would be built inside itself. $root_n must be the folder ABOVE the channel's folder. Example: /home/deployer with portal.example.org, not /home/deployer/portal.example.org with portal.example.org."
        else
            chroot="$root/$dir"
        fi
    fi

    rset LOGIN "$X" "$login"
    rset HOST "$X" "$host";      rset HOSTFROM "$X" "$host_n"
    rset USER "$X" "$user";      rset USERFROM "$X" "$user_n"
    rset SIGNIN "$X" "$signin";  rset SIGNINFROM "$X" "$signin_n"
    rset PORT "$X" "$port";      rset PORTFROM "$X" "$port_from"
    rset ROOTFROM "$X" "$root_n"; rset DIRFROM "$X" "$dir_n"
    rset CHROOT "$X" "$chroot"
}

# =============================================================================
# 🚧 Step 3 of "resolve": no two channels in one place, or one inside another
# Compared as (server, user, folder). What this CANNOT catch is said in the
# header: two names for one server, or two users with one home.
# =============================================================================
check_pair() {
    local A="$1" B="$2" ha ua ca hb ub cb where
    rget HOST "$A"; lower "$REPLY"; ha="$REPLY"
    rget HOST "$B"; lower "$REPLY"; hb="$REPLY"
    rget USER "$A"; ua="$REPLY"
    rget USER "$B"; ub="$REPLY"
    # Folders are compared without regard to capitals: some servers treat
    # Portal and portal as one folder, and when unsure this script refuses.
    rget CHROOT "$A"; lower "$REPLY"; ca="$REPLY"
    rget CHROOT "$B"; lower "$REPLY"; cb="$REPLY"
    # The PORT is deliberately not compared. One server answering on two ports
    # is far likelier than two servers behind one name, and when unsure this
    # script refuses: the same host, user and folder is the same place.
    # A blank here means that channel was already refused for another reason.
    if [[ -z "$ha" || -z "$hb" || -z "$ua" || -z "$ub" || -z "$ca" || -z "$cb" ]]; then return 0; fi
    if [[ "$ha" != "$hb" || "$ua" != "$ub" ]]; then return 0; fi

    rget ROOTFROM "$A"; where="$A is built from $REPLY"
    rget DIRFROM "$A";  where="$where + $REPLY"
    rget ROOTFROM "$B"; where="$where; $B from $REPLY"
    rget DIRFROM "$B";  where="$where + $REPLY"
    if [[ "$ca" == "$cb" ]]; then
        refuse same-place "$A and $B resolve to the same folder on the same server with the same user ($where). A deploy of one would overwrite the other, and they would share one database login and one encryption key. Give them different folders."
    elif [[ "$cb" == "$ca"/* ]]; then
        refuse nested "The $B channel's folder is inside the $A channel's folder ($where). A deploy of $A deletes what it does not recognise inside its own code folders, so it could delete the whole $B channel. Put the two side by side instead."
    elif [[ "$ca" == "$cb"/* ]]; then
        refuse nested "The $A channel's folder is inside the $B channel's folder ($where). A deploy of $B deletes what it does not recognise inside its own code folders, so it could delete the whole $A channel. Put the two side by side instead."
    fi
}

# =============================================================================
# 📝 The plan file: the only place a resolved value is written
# Every value in it has passed a narrow shape check above, so none can contain
# a quote, a space or anything else that would change its meaning when the
# workflow reads the file. It never holds a password or a key.
# =============================================================================
write_plan() {
    local file="$1" X="$2" word="$3" health_env="$4" health_base="$5" chroot
    rget CHROOT "$X"; chroot="$REPLY"
    (
        umask 077
        {
            printf "DEPLOY_CHANNEL='%s'\n" "$word"
            printf "DEPLOY_HEALTH_ENV='%s'\n" "$health_env"
            printf "DEPLOY_HEALTH_SETTING='ADMIN_BASE_URL_%s'\n" "$X"
            printf "DEPLOY_HEALTH_BASE='%s'\n" "$health_base"
            rget LOGIN "$X";      printf "DEPLOY_LOGIN_SOURCE='%s'\n" "$REPLY"
            rget HOST "$X";       printf "DEPLOY_HOST='%s'\n" "$REPLY"
            rget USER "$X";       printf "DEPLOY_USER='%s'\n" "$REPLY"
            rget PORT "$X";       printf "DEPLOY_PORT='%s'\n" "$REPLY"
            rget SIGNIN "$X";     printf "DEPLOY_SIGNIN='%s'\n" "$REPLY"
            rget SIGNINFROM "$X"; printf "DEPLOY_SIGNIN_SETTING='%s'\n" "$REPLY"
            printf "DEPLOY_CHANNEL_ROOT='%s'\n" "$chroot"
            printf "DEPLOY_LOCAL_WEB='web/%s'\n" "$REPO_WEB_FOLDER"
            printf "DEPLOY_REMOTE_WEB='%s/%s'\n" "$chroot" "$STAFF_WEB_DIR"
            printf "DEPLOY_CODE_FOLDERS='%s'\n" "$CODE_FOLDERS"
            printf "DEPLOY_KEPT_FOLDERS='%s'\n" "$KEPT_FOLDERS"
        } > "$file"
    )
}

# =============================================================================
# ❓ Question 1: resolve
# =============================================================================
do_resolve() {
    local X word health_env n kind other health_base='' health_line

    # --- Which channel. It comes from the branch and from nothing else: there
    #     is no way to send one branch's files to another channel's folder. ---
    if [[ -n "$OPT_BRANCH" && -n "$OPT_CHANNEL" ]]; then usage_error 'give --branch or --channel, not both'; fi
    if [[ -n "$OPT_BRANCH" ]]; then
        case "$OPT_BRANCH" in
            main)  OPT_CHANNEL='live' ;;
            beta)  OPT_CHANNEL='beta' ;;
            alpha) OPT_CHANNEL='alpha' ;;
            *)
                refuse branch-unknown 'Only the branches main, beta and alpha are deployed (to the live, beta and alpha channels). This run is on another branch.'
                finish_refused
                ;;
        esac
    fi
    case "$OPT_CHANNEL" in
        live)  X='LIVE';  word='live';  health_env='prod' ;;
        beta)  X='BETA';  word='beta';  health_env='beta' ;;
        alpha) X='ALPHA'; word='alpha'; health_env='alpha' ;;
        *) usage_error '--channel must be live, beta or alpha' ;;
    esac

    # (A plan file left by an earlier run has already been removed: see
    # remove_stale_plan, which runs before any of this. The removal used to sit
    # here, AFTER the branch check above, so an old plan survived a run on an
    # unknown branch and a run that was called wrongly.)

    # --- The switch. The workflow checks it too; this is the second lock.
    #     The value is compared as it stands, not tidied first: this used to
    #     accept " true " and "true" followed by a line break while every
    #     message said "exactly". The words were kept and the code made to match,
    #     because that is the choice that refuses more. ---
    if [[ "${SFTP_ENABLED-}" != 'true' ]]; then
        refuse switched-off 'SFTP_ENABLED is not exactly "true" (the four small letters, with no space or line break before or after), so deploys are switched off. Nothing else was checked.'
        finish_refused
    fi

    # --- The staff web folder's name (owner decision 3; see the top of this file). ---
    get DEPLOY_STAFF_WEB_DIR
    STAFF_WEB_DIR="${REPLY:-$STAFF_WEB_DIR_DEFAULT}"
    STAFF_WEB_DIR_FROM='the built-in default'
    if [[ -n "$REPLY" ]]; then STAFF_WEB_DIR_FROM='DEPLOY_STAFF_WEB_DIR'; fi
    lower "$STAFF_WEB_DIR"; STAFF_WEB_DIR_LOWER="$REPLY"
    if ! [[ "$STAFF_WEB_DIR" =~ $RE_DIR ]]; then
        refuse web-dir-shape 'The staff web folder name must be the name of one folder, such as admin_html (letters, digits, underscore, dot, hyphen; no slash; no leading dot).'
        STAFF_WEB_DIR_LOWER='admin_html'
    elif in_list "$STAFF_WEB_DIR_LOWER" "$CODE_FOLDERS $KEPT_FOLDERS"; then
        # Mirroring the staff pages, with deletion, into _auth_keys would destroy
        # the one file that cannot be recovered (the encryption key).
        refuse web-dir-reserved 'The staff web folder name is the name of one of the code folders or of a folder the server keeps for itself. The staff pages are mirrored there with deletion, so it must be a folder of its own, such as admin_html.'
        STAFF_WEB_DIR_LOWER='admin_html'
    fi

    # --- Step 1: clean every setting. ---
    clean SFTP_HOST host
    clean SFTP_USER user
    clean SFTP_PASSWORD password
    clean SFTP_KEY key
    clean SFTP_PORT port
    clean SFTP_PATH_ROOT_DIR root
    for other in $CHANNELS; do
        clean "SFTP_HOST_$other" host
        clean "SFTP_USER_$other" user
        clean "SFTP_PASSWORD_$other" password
        clean "SFTP_KEY_$other" key
        clean "SFTP_PORT_$other" port
        clean "SFTP_PATH_${other}_ROOT_DIR" root
        clean "SFTP_PATH_${other}_DIR" dir
        clean "ADMIN_BASE_URL_$other" url
    done

    # --- Step 2: combine, for ALL three channels (step 3 needs them all). ---
    for other in $CHANNELS; do resolve_channel "$other"; done

    # --- Step 3: no two channels in one place. ---
    check_pair LIVE BETA
    check_pair LIVE ALPHA
    check_pair BETA ALPHA

    # --- Leftovers from earlier schemes: say so, carry on. ---
    for n in $RETIRED_SETTINGS; do
        get "$n"
        if [[ -n "$REPLY" ]]; then
            warn "$n is still set, but nothing reads it any more. It cannot send a deploy anywhere. Delete it when convenient, so nobody mistakes it for a live setting."
        fi
    done

    if [[ "$REFUSALS" -gt 0 ]]; then finish_refused; fi

    # --- The health check address: a missing one is a skipped check with a
    #     warning, never somebody's real address filled in for it. ---
    state "ADMIN_BASE_URL_$X"
    if [[ "$REPLY" == 'ok' ]]; then
        val "ADMIN_BASE_URL_$X"; health_base="$REPLY"
        health_line="will run, against the address in ADMIN_BASE_URL_$X"
    else
        health_line="SKIPPED, because ADMIN_BASE_URL_$X is empty"
        warn "ADMIN_BASE_URL_$X is not set, so the health check after this deploy will be skipped. Nothing will confirm that the address is serving the $word channel's folder. Set it to the staff address, for example https://portal.example.org."
    fi

    if [[ -n "$OPT_PLAN" ]]; then write_plan "$OPT_PLAN" "$X" "$word" "$health_env" "$health_base"; fi

    # --- The account: which SETTING supplied each value. Never a value. ---
    printf 'Deploy settings resolved for the %s channel. Nothing was connected to.\n' "$word"
    rget LOGIN "$X"
    if [[ "$REPLY" == 'channel' ]]; then
        printf '  login:           the %s channel'"'"'s own (channel override)\n' "$word"
    else
        printf '  login:           the shared one (no login setting ending _%s is set)\n' "$X"
    fi
    rget HOSTFROM "$X";   printf '  host:            from %s\n' "$REPLY"
    rget USERFROM "$X";   printf '  user:            from %s\n' "$REPLY"
    rget SIGNIN "$X";     kind="$REPLY"
    rget SIGNINFROM "$X"; printf '  sign-in:         %s, from %s\n' "$kind" "$REPLY"
    rget PORTFROM "$X"
    if [[ "$REPLY" == 'default' ]]; then
        rget LOGIN "$X"
        if [[ "$REPLY" == 'channel' ]]; then
            printf '  port:            22, the default (SFTP_PORT_%s is empty; the shared port is never used for a channel with its own login)\n' "$X"
        else
            printf '  port:            22, the default (SFTP_PORT is empty)\n'
        fi
    else
        printf '  port:            from %s\n' "$REPLY"
    fi
    rget ROOTFROM "$X";   printf '  base folder:     from %s\n' "$REPLY"
    rget DIRFROM "$X";    printf '  channel folder:  from %s, inside the base folder\n' "$REPLY"
    printf '  health check:    %s\n' "$health_line"
    printf '  where each part of web/ goes, inside the channel folder:\n'
    printf '    web/%s/  ->  %s/   (the staff pages; mirrored, with deletion; name from %s)\n' "$REPO_WEB_FOLDER" "$STAFF_WEB_DIR" "$STAFF_WEB_DIR_FROM"
    for n in $CODE_FOLDERS; do
        printf '    web/%s/  ->  %s/   (mirrored, with deletion)\n' "$n" "$n"
    done
    printf '    created if missing, otherwise never touched: %s\n' "$KEPT_FOLDERS"
    for other in $CHANNELS; do
        if [[ "$other" != "$X" ]]; then
            rget LOGIN "$other"
            printf '  also checked, so no two channels share a place: %s (login: %s)\n' "$other" "$REPLY"
        fi
    done
    printf 'RESOLVED\n'
    exit 0
}

finish_refused() {
    printf 'Nothing was connected to and nothing was uploaded. Problems found: %s.\n' "$REFUSALS"
    exit 1
}

# Does the PHP file $1 name the channel file in a line of CODE?
# This used to be "does the file mention .channel anywhere", and a comment such
# as "one day, read the .channel file" was enough to pass. Now a line counts
# only when, with any // comment cut off its end, it is not itself a comment
# line (starting //, #, /* or *) and holds .channel followed at once by a
# closing quote, as in PORTAL_ROOT . '/.channel'.
# WHAT IT CANNOT DO: it reads no PHP. A quoted .channel in code that never
# opens the file would pass, and so would one inside a /* comment */ whose
# lines do not start with *. It is a guard against uploading code from before
# the channel file existed, not a proof that the code works.
# (Plain bash, not grep: with "pipefail", a grep that stops early can make the
# program feeding it report a failure, which would turn a yes into a no.)
names_channel_file_in_code() {
    local line
    while IFS= read -r line || [[ -n "$line" ]]; do
        line="${line%%//*}"
        line="${line#"${line%%[![:space:]]*}"}"
        case "$line" in
            '#'*|'/*'*|'*'*) continue ;;
        esac
        case "$line" in
            *".channel'"*|*'.channel"'*) return 0 ;;
        esac
    done < "$1"
    return 1
}

# Is $1 the "refuse everything" file, byte for byte? Returns 0 for yes.
# A link is not: what it points at can change without this folder changing.
# The size is asked first, of find, in bytes. That is not for speed: bash
# cannot hold a zero byte in a variable and drops it, so a file with zero
# bytes ADDED to the right text would compare as equal. With the size equal
# too, every byte has to be the same.
# WHAT IT CANNOT DO: say anything about a file's owner or permissions.
is_the_refuse_everything_file() {
    local file="$1" got=''
    if [[ -L "$file" || ! -f "$file" ]]; then return 1; fi
    if [[ -z "$(find "$file" -type f -size "${#REFUSE_EVERYTHING_TEXT}c" -print)" ]]; then return 1; fi
    # The "x" keeps the line breaks at the end, which $( ) would otherwise
    # take off. A newer bash warns when it drops a zero byte; the warning is
    # not wanted in a deploy's log, and the comparison below says no anyway.
    { got="$(cat "$file"; printf x)"; } 2>/dev/null
    [[ "$got" == "${REFUSE_EVERYTHING_TEXT}x" ]]
}

# =============================================================================
# ❓ Question 2: tree — is the local web/ folder safe to mirror from?
# Each code folder is mirrored on its own, so a deletion can only ever happen
# INSIDE a folder the repository owns completely. The price of that is that a
# new top-level folder would not be uploaded at all; this check turns that from
# a silent miss into a refusal.
# =============================================================================
do_tree() {
    local dir="$OPT_WEBDIR" entry name f has_reader=0 guard found stray
    case "$OPT_CHANNEL" in
        live|beta|alpha) : ;;
        *) usage_error '--channel must be live, beta or alpha' ;;
    esac
    if [[ -z "$dir" ]]; then usage_error 'tree needs --web-dir'; fi
    if [[ ! -d "$dir" ]]; then
        refuse tree-missing 'The web folder to upload from does not exist in this checkout.'
        finish_refused
    fi

    # --- Every entry at the top must be one this script knows what to do with. ---
    shopt -s dotglob nullglob
    for entry in "$dir"/*; do
        name="${entry##*/}"
        if [[ "$name" == "$REPO_WEB_FOLDER" ]] || in_list "$name" "$CODE_FOLDERS $KEPT_FOLDERS $PLACEHOLDER_FOLDERS $OTHER_NOT_DEPLOYED $TOP_FILES"; then
            continue
        fi
        # A name from the repository, not a setting, so it is safe to print.
        refuse tree-unknown-entry "web/$name is not on any list in tools/deploy/resolve-settings.sh, so the deploy does not know whether to upload it. Add it to CODE_FOLDERS (uploaded) or OTHER_NOT_DEPLOYED (left behind on purpose)."
    done
    shopt -u dotglob nullglob

    # --- A folder mirrored with deletion must not be empty or missing here.
    #     An empty one means the checkout or a fetch went wrong, and a mirror
    #     with deletion from a broken copy must not be started at all.
    #     (This comment used to say it "would empty the server's copy". That
    #     was a guess: see EMPTY_FOLDER_FACT in the self-test's header for what
    #     the real upload program was seen to do.) ---
    for name in $CODE_FOLDERS; do
        if [[ ! -d "$dir/$name" || -z "$(find "$dir/$name" -type f -print -quit)" ]]; then
            refuse tree-empty-code-folder "web/$name is missing or holds no file, so this copy of the code is incomplete. It is mirrored with deletion, and nothing is uploaded from an incomplete copy."
        fi
    done
    # dompdf is fetched during the run. Its version file is written last, so a
    # half-finished fetch is caught here instead of being mirrored.
    if [[ ! -f "$dir/_libraries/dompdf/VERSION.txt" ]]; then
        refuse tree-no-dompdf 'web/_libraries/dompdf/VERSION.txt is missing, so the PDF library was not fetched completely. Uploading now would delete the working copy on the server.'
    fi
    for f in index.php .htaccess; do
        if [[ ! -f "$dir/$REPO_WEB_FOLDER/$f" ]]; then
            refuse tree-web-incomplete "web/$REPO_WEB_FOLDER/$f is missing. Without it the staff address would answer nothing, or would list its files."
        fi
    done

    # --- The placeholder folders are not uploaded. If a real file ever appears
    #     in one, somebody expects it on the server, and it would not arrive.
    #     Two things may be there: .gitkeep, and the "refuse everything" file
    #     (see REFUSE_EVERYTHING_TEXT, near the top). Everything else that is
    #     not a folder is refused: another file, a file in a folder below, a
    #     link. This used to look at ordinary files only ("find -type f"), so a
    #     link was not seen at all; it is now. ---
    for name in $PLACEHOLDER_FOLDERS; do
        if [[ ! -d "$dir/$name" ]]; then continue; fi
        guard="$dir/$name/.htaccess"
        stray=0
        # Each name is compared here, in bash, and not by a pattern handed to
        # find: a folder name with a "[" or a "*" in it would be read as a
        # pattern there.
        while IFS= read -r found; do
            if [[ "$found" != "$guard" ]]; then stray=1; fi
        done < <(find "$dir/$name" ! -type d ! -name .gitkeep -print)
        if [[ "$stray" -eq 1 ]]; then
            refuse tree-placeholder-has-files "web/$name holds something besides .gitkeep and the \"refuse everything\" file, but that folder is not uploaded. Move $name from PLACEHOLDER_FOLDERS to CODE_FOLDERS in tools/deploy/resolve-settings.sh, or move the file."
        fi
        if [[ -e "$guard" || -L "$guard" ]] && ! is_the_refuse_everything_file "$guard"; then
            refuse tree-placeholder-htaccess-differs "web/$name/.htaccess is not, byte for byte, the \"refuse everything\" file this script knows (or it is a link, or not a file). That folder is not uploaded, so a rule written there would never reach the server. If the \"refuse everything\" file was changed on purpose in all its folders, put its new text in REFUSE_EVERYTHING_TEXT in tools/deploy/resolve-settings.sh."
        fi
    done

    # --- The channel file: one word, and the right one. ---
    if [[ ! -f "$dir/.channel" || "$(cat "$dir/.channel")" != "$OPT_CHANNEL" ]]; then
        refuse tree-channel-file "web/.channel must exist and hold exactly the word $OPT_CHANNEL. The workflow writes it just before this check; it tells the portal which channel it is."
    fi
    if [[ ! -f "$dir/CHANGELOG.md" ]]; then
        refuse tree-no-changelog 'web/CHANGELOG.md is missing. The workflow copies it there for the release-notes page.'
    fi

    # --- The code must be new enough to READ the channel file. Older code
    #     guesses the channel from the web folder's name; in the new layout every
    #     channel's web folder has the same name, and that guess lands on "dev",
    #     which shows PHP errors on screen. So this layout must never receive
    #     code from before roll-out package F3. ---
    for f in "$dir/_core/bootstrap.php" "$dir/_core/Channel.php"; do
        if [[ -f "$f" ]] && names_channel_file_in_code "$f"; then has_reader=1; fi
    done
    if [[ "$has_reader" -ne 1 ]]; then
        refuse tree-predates-channel-file 'This code does not read the channel file: neither web/_core/bootstrap.php nor web/_core/Channel.php has a line of code (a comment does not count) with the file name .channel in quotes. Deployed into a separate channel folder it would treat itself as a development copy and show PHP errors on screen. Merge the change that reads the channel file first.'
    fi

    if [[ "$REFUSALS" -gt 0 ]]; then finish_refused; fi
    printf 'The web folder is safe to upload from for the %s channel.\nTREE OK\n' "$OPT_CHANNEL"
    exit 0
}

# =============================================================================
# ❓ Question 3: remote — may this server folder be written to?
# The settings check cannot see two names for one server, or a setting that
# points at a folder which already holds something else. This is the backstop:
# the workflow LOOKS at the folder before creating or uploading anything, and
# this judges what it found.
#
# HOW IT KNOWS THE LISTING WAS REALLY READ
# A listing must show the folder's own entry "." (the upload program lists it,
# with hidden files) or an _auth_keys folder (every folder a deploy has
# finished in has one). A listing with neither means "could not look", and
# that is refused: carrying on would treat "I could not see" as "nothing is
# there". (The first version asked for _auth_keys alone, because the workflow
# then created that folder BEFORE looking. That meant folders were made inside
# a folder nobody had judged yet, so the order was turned round.)
#
# WHAT IT ANSWERS WHEN THERE IS NO CHANNEL FILE
# The first version looked only for a _core folder, and called everything else
# "no portal yet, first deploy": somebody else's website, a web folder, a home
# folder, a portal whose _core was missing. All were accepted. Now:
#   - nothing there but the kept folders            -> first deploy, go ahead
#   - other things, and no folder named _core       -> refused, tick or no tick
#   - _core, and things no portal deploy puts there -> refused, tick or no tick
#   - _core, and nothing but a portal's own names   -> refused, unless a person
#                                                      ticked "adopt existing folder"
# So the tick does one thing only: it lets a person say "this old-layout portal
# IS this channel". It cannot wave through a folder that is not recognisably a
# portal and nothing else. For such a folder there is exactly one way in, and
# it takes more than a tick: somebody with access to the server puts a channel
# file in it by hand. That is the same mark every deployed channel carries.
#
# Names are compared exactly, capitals included. A folder called _Core was not
# made by a deploy, so it counts as something else's; when unsure, refuse.
#
# WHAT IT CANNOT DO: it sees names, never contents. A folder that holds only
# things named like a portal's is taken for a portal. And it cannot tell WHICH
# channel an unmarked portal is; that is the judgement the tick asks a person for.
# What a server sends back is never printed: counts only, and a channel word
# only when it is one of the known ones.
# =============================================================================
do_remote() {
    local line name word='' has_channel=0 has_canary=0 has_code=0 others=0 foreign=0 own_names
    case "$OPT_CHANNEL" in
        live|beta|alpha) : ;;
        *) usage_error '--channel must be live, beta or alpha' ;;
    esac
    if [[ -z "$OPT_LISTING" ]]; then usage_error 'remote needs --listing'; fi

    # Every name a deploy of the portal puts in a channel's folder, under the
    # old shared layout or this one. Anything else is somebody else's.
    own_names="$CODE_FOLDERS $KEPT_FOLDERS $PLACEHOLDER_FOLDERS $OTHER_NOT_DEPLOYED $REPO_WEB_FOLDER $OLD_LAYOUT_WEB_FOLDERS admin_html $STAFF_WEB_DIR_DEFAULT CHANGELOG.md"
    get DEPLOY_STAFF_WEB_DIR
    if [[ "$REPLY" =~ $RE_DIR ]]; then own_names="$own_names $REPLY"; fi

    if [[ -r "$OPT_LISTING" ]]; then
        while IFS= read -r line || [[ -n "$line" ]]; do
            # A listing line may be a bare name or a full path, may end with a
            # marker for "folder" or "link", and may carry a Windows line end.
            line="${line%$'\r'}"
            line="${line%[/@*]}"
            name="${line##*/}"
            case "$name" in
                '')         continue ;;                  # a blank line
                .)          has_canary=1; continue ;;    # the folder's own entry
                ..)         continue ;;                  # the folder above
                .channel)   has_channel=1; continue ;;
                _auth_keys) has_canary=1; continue ;;
            esac
            if in_list "$name" "$KEPT_FOLDERS"; then continue; fi
            # From here on the entry is something the folder HOLDS.
            others=$((others + 1))
            if [[ "$name" == '_core' ]]; then has_code=1; fi
            if ! in_list "$name" "$own_names"; then foreign=$((foreign + 1)); fi
        done < "$OPT_LISTING"
    fi
    if [[ "$has_canary" -ne 1 ]]; then
        refuse remote-listing-unreadable "The listing of the channel's folder on the server could not be read, or shows neither the folder's own entry (a single dot) nor an _auth_keys folder. So there is no proof the folder was really looked at, it is not known what it holds, and nothing was uploaded. (A few servers do not list a folder's own entry. On one of those, create an _auth_keys folder inside a new channel folder by hand before its first deploy.)"
        finish_refused
    fi

    # What was fetched must be an ordinary file. Anything else in its place (a
    # folder, say) means the fetch did not do what was asked. This used to be
    # read anyway: bash printed an error of its own and the folder was then
    # treated as having no channel file. When unsure, refuse.
    if [[ -n "$OPT_FETCHED" && -e "$OPT_FETCHED" && ! -f "$OPT_FETCHED" ]]; then
        refuse remote-channel-file-unreadable "What was fetched as the folder's channel file is not an ordinary file, so it is not known whose folder this is. Nothing was uploaded."
        finish_refused
    fi
    if [[ -n "$OPT_FETCHED" && -s "$OPT_FETCHED" ]]; then
        # The first line, without a byte-order mark, a Windows line end or spaces.
        IFS= read -r word < "$OPT_FETCHED" || true
        word="${word#$'\xef\xbb\xbf'}"
        word="${word//[[:space:]]/}"
    fi

    if [[ -n "$word" ]]; then
        if [[ "$word" == "$OPT_CHANNEL" ]]; then
            printf 'The folder on the server already belongs to the %s channel.\nREMOTE OK\n' "$OPT_CHANNEL"
            exit 0
        fi
        # Only a known channel word is echoed back; anything else on a server is not.
        if in_list "$word" 'live beta alpha dev prod'; then
            refuse remote-other-channel "The folder on the server says it belongs to the \"$word\" channel, but this run is deploying \"$OPT_CHANNEL\". A setting points at the wrong folder or the wrong server. Nothing was uploaded."
        else
            refuse remote-other-channel "The folder on the server has a channel file that does not say \"$OPT_CHANNEL\" (it holds a word this script does not know). Nothing was uploaded."
        fi
        finish_refused
    fi

    if [[ "$has_channel" -eq 1 ]]; then
        refuse remote-channel-file-unreadable "The folder on the server has a channel file, but it could not be fetched or is empty, so it is not known whose folder this is. Nothing was uploaded."
        finish_refused
    fi

    # --- No channel file from here on. ---
    if [[ "$others" -eq 0 ]]; then
        printf 'The folder on the server is empty (it holds nothing but the folders the server keeps for itself): this is the first deploy of the %s channel into it.\nREMOTE OK\n' "$OPT_CHANNEL"
        exit 0
    fi

    if [[ "$has_code" -ne 1 ]]; then
        refuse remote-folder-not-empty "The folder on the server is not empty, and nothing in it says it is the \"$OPT_CHANNEL\" channel's. Besides the folders the server keeps for itself it holds $others other thing(s), with no channel file and no folder named exactly _core. A first deploy goes only into an empty folder, and this is not a portal this script recognises, so a setting most likely points at a folder that belongs to something else: a folder an address already serves, another website, or the account's home folder. Ticking \"adopt existing folder\" does not change this answer. Check the base folder and channel folder settings named in this run's summary. If the folder really is to become this channel's, empty it first; or, only if you are certain, put a file named .channel holding the one word $OPT_CHANNEL in it by hand, which is the mark every channel's folder carries. Nothing was uploaded and nothing was created."
        finish_refused
    fi

    if [[ "$foreign" -ne 0 ]]; then
        refuse remote-portal-among-other-things "The folder on the server holds a portal's _core folder and no channel file, but also $foreign thing(s) that no deploy of the portal puts there. So it cannot be taken for a portal from the old shared layout, and \"adopt existing folder\" is not accepted for it, ticked or not: it may be a home folder, or a shared folder with a portal mixed into it. A portal's own names are: $own_names. Move everything else out of the folder; or, only if you are certain this is the \"$OPT_CHANNEL\" channel's folder, put a file named .channel holding the one word $OPT_CHANNEL in it by hand. Nothing was uploaded and nothing was created."
        finish_refused
    fi

    if [[ "$OPT_ADOPT" != 'true' ]]; then
        # A portal is already there, with nothing to say which channel it is.
        # That is the old shared layout, or a setting pointing at another
        # installation of the portal. A person has to say which, once.
        refuse remote-unmarked-installation "The folder on the server already holds a portal (it has a _core folder, and nothing that is not a portal's own) but no channel file, so nothing says which channel it belongs to. If you are sure it is the \"$OPT_CHANNEL\" channel's folder (for example the first deploy after changing over from the old shared layout), run the workflow by hand with \"adopt existing folder\" ticked. Nothing was uploaded and nothing was created."
        finish_refused
    fi

    printf 'The folder on the server holds a portal with no channel file, and nothing that is not a portal'"'"'s own; adopting it as the %s channel because that was asked for by hand.\nREMOTE OK\n' "$OPT_CHANNEL"
    exit 0
}

# =============================================================================
# ▶ Read the command line
# =============================================================================
MODE="${1-}"
if [[ $# -gt 0 ]]; then shift; fi

OPT_BRANCH=''; OPT_CHANNEL=''; OPT_PLAN=''; OPT_WEBDIR=''; OPT_LISTING=''; OPT_FETCHED=''; OPT_ADOPT=''
STAFF_WEB_DIR="$STAFF_WEB_DIR_DEFAULT"; STAFF_WEB_DIR_LOWER="$STAFF_WEB_DIR_DEFAULT"; STAFF_WEB_DIR_FROM=''

# 🧹 Before ANYTHING is judged: remove every file named with --plan-file.
# A plan from an earlier run must never be read after a run that did not end
# in RESOLVED, and that includes a run that is refused for its branch and a
# run that is called wrongly, so this looks through the whole command line by
# itself, ahead of the reading below (which stops at the first mistake).
# If the old file cannot be removed (it is a folder, or not ours to remove) the
# run stops: leaving it would be the very thing this is here to prevent.
ARG_I=1
while [[ "$ARG_I" -lt $# ]]; do
    ARG_NEXT=$((ARG_I + 1))
    if [[ "${!ARG_I}" == '--plan-file' && -n "${!ARG_NEXT}" ]]; then
        rm -f -- "${!ARG_NEXT}" 2>/dev/null || true
        if [[ -e "${!ARG_NEXT}" || -L "${!ARG_NEXT}" ]]; then
            refuse plan-file-stuck 'The file named with --plan-file is already there and could not be removed, so this run stops rather than leave an old plan where a later step could read it.'
            finish_refused
        fi
    fi
    ARG_I=$((ARG_I + 1))
done

while [[ $# -gt 0 ]]; do
    case "$1" in
        --branch|--channel|--plan-file|--web-dir|--listing|--fetched|--adopt-existing)
            if [[ $# -lt 2 ]]; then usage_error "$1 needs a value"; fi
            case "$1" in
                --branch)         OPT_BRANCH="$2" ;;
                --channel)        OPT_CHANNEL="$2" ;;
                --plan-file)      OPT_PLAN="$2" ;;
                --web-dir)        OPT_WEBDIR="$2" ;;
                --listing)        OPT_LISTING="$2" ;;
                --fetched)        OPT_FETCHED="$2" ;;
                --adopt-existing) OPT_ADOPT="$2" ;;
            esac
            shift 2
            ;;
        *) usage_error 'unknown option' ;;
    esac
done

case "$MODE" in
    resolve)
        if [[ -z "$OPT_BRANCH" && -z "$OPT_CHANNEL" ]]; then usage_error 'resolve needs --branch or --channel'; fi
        do_resolve
        ;;
    tree)   do_tree ;;
    remote) do_remote ;;
    *)      usage_error 'the first word must be resolve, tree or remote' ;;
esac
