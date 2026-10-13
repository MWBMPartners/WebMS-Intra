#!/usr/bin/env bash
# =============================================================================
# WebMS-Intra — Self-test of the installation package's build and guards (#499)
#
# Path: tools/package/package-selftest.sh
#
# Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
# Proprietary. Unauthorised copying, modification or distribution prohibited.
#
# PURPOSE
# -------
# Proves that build.sh and check-package.py REFUSE what they must refuse, by
# planting faults and watching each fail for its own reason. A guard that
# has never been seen to fail proves nothing: on 12 October 2026 an
# independent check committed 43 files the first deny list had not thought of,
# and every one was shipped in a zip the build called good.
#
# It proves the guards catch what is planted HERE, and nothing more. Later the
# same day a second check loosened five rules in a copy of the checker, and
# this self-test still passed, because no case planted what those rules exist
# for (it had no PHP syntax error in it at all). Those five now have cases, and
# so has every rule added since; each case has been seen to fail with its rule
# removed. WHEN A RULE IS ADDED TO THE CHECKER, ADD ITS CASE HERE and take the
# rule out once, in a copy (--tools), to see this fail.
#
# STILL WITHOUT A CASE OF THEIR OWN (see README.md): the top-level names (G3),
# dompdf's version marker, the labels (G9), the guide guard inside the checker
# (G10), the zip's size, checksum file and entry names, and the fallback
# copies' fingerprints. A fault in one of those would not be noticed here.
#
# Usage:
#   bash tools/package/package-selftest.sh [--cache <folder>] [--logs <folder>]
#        [--only "<case> <case> …"] [--tools <folder>] [--base <commit>]
#
#   --cache <folder>  QUICK MODE. Keeps what was downloaded (dompdf, the
#                     fallback libraries) in this folder and uses it again on
#                     the next run, so only the first run needs the internet.
#                     Every checksum is still checked on every use.
#   --logs <folder>   keep each case's full output there (otherwise it is
#                     removed with the scratch folder, and only the output of
#                     a case that was NOT ok is printed)
#   --only "<ids>"    run only these cases, or groups by their first letter:
#                     T (git's list), B (whole builds), C (the checker on one
#                     planted fault), D (the dompdf fetch script)
#   --tools <folder>  test the scripts in another tools/ folder instead of the
#                     one this script is in (used to show that a fault planted
#                     in a COPY of the scripts makes these cases fail)
#   --base <commit>   the commit to build on (default HEAD)
#
# WHAT IT TOUCHES: nothing in the repository it is run from. It makes a
# scratch clone in the system's temporary folder (sharing this repository's
# stored files, read-only), removes that clone's remote so nothing can be
# pushed from it, copies the scripts in, and builds scratch commits there
# that are never kept. The scratch folder is removed at the end, by a path
# this script made itself and checks before removing.
#
# IT NEEDS the internet on its first run (see --cache), and the programs
# build.sh needs: git tar zip unzip curl shasum openssl php python3.
# gitleaks is used when it is installed. When it is NOT, a stand-in takes its
# place so the other guards can still be tested, and the run says so loudly:
# such a run proves nothing about what the real gitleaks finds.
#
# IT TAKES a while: some twenty whole builds, each of which reads every PHP
# file twice and searches every file for secrets four times. The last line
# says how long the run took; see README.md for what it took when written.
#
# THE THREE SIGNAL CASES (B13) need a shell that can be interrupted. A shell
# started in the background by another script is handed "ignore INT" (and one
# started under nohup, "ignore HUP"), and passes that on to everything it
# starts. Such a case is then SKIPPED, and the last line says how many were.
#
# EXIT: 0 only when every case was ok. The last line always says "reached the
# end"; a run without that line stopped early and proved nothing.
#
# WHAT IT CANNOT PROVE: that a package installs; anything about GitHub; that
# the guards catch a fault nobody has planted here.
# =============================================================================
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
TOOLS_FROM="$REPO_ROOT/tools"
BASE="HEAD"
CACHE=""
LOGS=""
ONLY=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --cache) CACHE="${2:?--cache needs a folder}"; shift 2 ;;
        --logs) LOGS="${2:?--logs needs a folder}"; shift 2 ;;
        --only) ONLY="${2:?--only needs a list of cases}"; shift 2 ;;
        --tools) TOOLS_FROM="${2:?--tools needs a folder}"; shift 2 ;;
        --base) BASE="${2:?--base needs a commit}"; shift 2 ;;
        *) echo "ERROR: unknown argument '$1' (usage is at the top of this script)" >&2; exit 1 ;;
    esac
done

for PROGRAM in git tar zip unzip curl shasum openssl php python3; do
    command -v "$PROGRAM" > /dev/null || { echo "ERROR: this self-test needs '$PROGRAM', which is not installed" >&2; exit 1; }
done

SCRATCH="$(mktemp -d -t package-selftest.XXXXXX)" || exit 1
cleanup() {
    # Only ever the folder mktemp gave this run: the name is checked first.
    case "$SCRATCH" in
        */package-selftest.*) [[ -d "$SCRATCH" ]] && rm -rf "${SCRATCH:?}" ;;
    esac
}
trap cleanup EXIT

[[ -n "$CACHE" ]] || CACHE="$SCRATCH/cache"
[[ -n "$LOGS" ]] || LOGS="$SCRATCH/logs"
mkdir -p "$CACHE" "$LOGS" "$SCRATCH/bin" "$SCRATCH/content" "$SCRATCH/out" || exit 1
CACHE="$(cd "$CACHE" && pwd)"
LOGS="$(cd "$LOGS" && pwd)"
RESULTS="$LOGS/RESULTS.txt"
: > "$RESULTS"

TOTAL=0
NOT_OK=0
ok() { TOTAL=$((TOTAL + 1)); echo "ok      $*" | tee -a "$RESULTS"; }
not_ok() {
    TOTAL=$((TOTAL + 1)); NOT_OK=$((NOT_OK + 1))
    echo "NOT-OK  $*" | tee -a "$RESULTS"
}
# check <case and what> <command…>: ok when the command succeeds.
check() { local what="$1"; shift; if "$@" > /dev/null 2>&1; then ok "$what"; else not_ok "$what"; fi; }
has() { grep -qF -- "$2" "$LOGS/$1.log"; }                 # the case's output holds this text
has_not() { ! grep -qF -- "$2" "$LOGS/$1.log"; }
rc_is() { [[ "$(cat "$LOGS/$1.rc")" == "$2" ]]; }
no_zip() { [[ -z "$(find "$SCRATCH/out/$1" -maxdepth 1 -name 'webms-intra-*' -print 2> /dev/null)" ]]; }
wanted() {                                                   # is this case asked for?
    [[ -z "$ONLY" ]] && return 0
    local id
    for id in $ONLY; do [[ "$1" == "$id" || "${1:0:1}" == "$id" ]] && return 0; done
    return 1
}
# The bullet lines one guard printed in a case's output.
guard_lines() {
    python3 - "$LOGS/$1.log" "$2" << 'PYEOF'
import sys
inside = False
for line in open(sys.argv[1], encoding="utf-8", errors="replace"):
    if line.startswith(("PASS", "FAIL", "NOT CHECKED")):
        inside = sys.argv[2] in line.split(":", 1)[0]
    elif inside and line.startswith("  • "):
        sys.stdout.write(line)
PYEOF
}

# -----------------------------------------------------------------------------
# Stand-in programs, put in front of the real ones for the whole run
# -----------------------------------------------------------------------------
REAL_CURL="$(command -v curl)"
REAL_ZIP="$(command -v zip)"
REAL_SHASUM="$(command -v shasum)"
REAL_MV="$(command -v mv)"
REAL_PHP="$(command -v php)"

# curl: keeps the first real download of each address and hands the same
# bytes back afterwards. The scripts under test still check those bytes
# against their checksums every time. SELFTEST_CACHE can point somewhere else
# for one command (case D02 uses that to serve a file that is not dompdf).
cat > "$SCRATCH/bin/curl" << EOF
#!/usr/bin/env bash
out=""; args=("\$@")
for ((i = 0; i < \${#args[@]}; i++)); do [[ "\${args[\$i]}" == "-o" ]] && out="\${args[\$((i + 1))]}"; done
url="\${args[\$((\${#args[@]} - 1))]}"
kept="\${SELFTEST_CACHE:-$CACHE}/\$(printf '%s' "\$url" | "$REAL_SHASUM" -a 256 | cut -d' ' -f1)"
if [[ -n "\$out" && -f "\$kept" ]]; then cp "\$kept" "\$out"; exit 0; fi
"$REAL_CURL" "\$@"; code=\$?
if [[ "\$code" -eq 0 && -n "\$out" ]]; then cp "\$out" "\$kept.\$\$" && mv "\$kept.\$\$" "\$kept"; fi
exit "\$code"
EOF

# zip: when SELFTEST_DROP_FILES is set, writes those files into the folder
# being zipped first. build.sh calls zip from inside the package folder AFTER
# the folder has been checked, so this is "something slipped in between the
# folder check and the zipping". The files are given ordinary permission
# bits (umask 022) on purpose: left group-writable, as a Linux user's default
# makes them, the zip is refused for THAT, which is right but is not the
# guard this case is here to prove.
cat > "$SCRATCH/bin/zip" << EOF
#!/usr/bin/env bash
umask 022
for name in \${SELFTEST_DROP_FILES:-}; do
    mkdir -p "\$(dirname "\$name")"
    printf '<?php\n// slipped in after the folder check\n' > "\$name"
done
exec "$REAL_ZIP" "\$@"
EOF

# shasum and mv: fail at one named moment after the zip has been made. mv can
# also send the build a signal at one of those moments (SELFTEST_SIGNAL_AT and
# SELFTEST_SIGNAL) and then do its job as usual: build.sh calls mv directly,
# so mv's parent is the build itself.
cat > "$SCRATCH/bin/shasum" << EOF
#!/usr/bin/env bash
case "\${SELFTEST_FAIL_AT:-}:\$*" in
    write-checksum:"-a 256 webms-intra-"*.zip) echo "planted failure: writing the checksum" >&2; exit 1 ;;
    last-look:"-a 256 -c webms-intra-"*) echo "planted failure: the last look at the moved zip" >&2; exit 1 ;;
esac
exec "$REAL_SHASUM" "\$@"
EOF
cat > "$SCRATCH/bin/mv" << EOF
#!/usr/bin/env bash
last="\${!#}"
case "\${SELFTEST_SIGNAL_AT:-}:\$last" in
    move-checksum:*/webms-intra-*.zip.sha256 | move-zip:*/webms-intra-*.zip.part | name-zip:*/webms-intra-*.zip) kill -s "\$SELFTEST_SIGNAL" "\$PPID" ;;
esac
case "\${SELFTEST_FAIL_AT:-}:\$last" in
    move-checksum:*/webms-intra-*.zip.sha256) echo "planted failure: moving the checksum" >&2; exit 1 ;;
    move-zip:*/webms-intra-*.zip.part) head -c 1000 "\$1" > "\$last"; echo "planted failure: moving the zip (half of it arrived)" >&2; exit 1 ;;
    name-zip:*/webms-intra-*.zip) echo "planted failure: giving the zip its name" >&2; exit 1 ;;
esac
exec "$REAL_MV" "\$@"
EOF

GITLEAKS_IS_REAL=1
if ! command -v gitleaks > /dev/null; then
    GITLEAKS_IS_REAL=0
    # Finds ONE shape, the one this self-test plants (handed over in
    # SELFTEST_KNOWN_SHAPE), and reports its file and line. It SKIPS files the
    # way the real gitleaks 8.21.2 does, by name and by how they begin, so
    # that the cases about "every file is read" fail here too if the
    # checker's second run is taken away. It is not the real thing: it proves
    # the checker's own handling, never what the real gitleaks recognises.
    cat > "$SCRATCH/bin/gitleaks" << 'EOF'
#!/usr/bin/env python3
import json, os, re, sys
args = sys.argv[1:]
source, report = args[args.index("--source") + 1], args[args.index("--report-path") + 1]
shape = os.environ["SELFTEST_KNOWN_SHAPE"].encode()
skipped_name = re.compile(r"(?i)\.(bmp|gif|jpe?g|svg|tiff?)$|\.(eot|[ot]tf|woff2?)$|(doc|zip|pdf|bin|exe)$|(^|/)node_modules/")
skipped_start = (b"wOF2", b"PK\x03\x04", b"MZ", b"\x1f\x8b", b"%PDF")
finds = []
for folder, _folders, names in os.walk(source):
    for name in sorted(names):
        full = os.path.join(folder, name)
        data = open(full, "rb").read()
        if skipped_name.search(os.path.relpath(full, source)) or data.startswith(skipped_start):
            continue
        finds += [
            {"File": full, "StartLine": number, "RuleID": "stand-in-rule"}
            for number, line in enumerate(data.split(b"\n"), start=1) if shape in line
        ]
json.dump(finds, open(report, "w"))
sys.exit(1 if finds else 0)
EOF
fi
# php with its exit code lost on the way (a wrapper that pipes its output).
# Kept out of the ordinary path: only case C10 puts it in front.
mkdir -p "$SCRATCH/bin-lost-code" || exit 1
cat > "$SCRATCH/bin-lost-code/php" << EOF
#!/usr/bin/env bash
"$REAL_PHP" "\$@" | cat
EOF
chmod +x "$SCRATCH/bin-lost-code/php"
chmod +x "$SCRATCH"/bin/*
export PATH="$SCRATCH/bin:$PATH"

# -----------------------------------------------------------------------------
# The scratch repository and its scratch commits
# -----------------------------------------------------------------------------
BASE_COMMIT="$(git -C "$REPO_ROOT" rev-parse --verify "$BASE^{commit}")" || { echo "ERROR: '$BASE' is not a commit here" >&2; exit 1; }

# A scratch repository with the scripts under test copied in as plain files.
# $1 = its name. The first one shares the real repository's stored files; the
# later ones are made from the first, so that they hold the scratch commits
# too. (The first draft made them all from the real repository, where those
# commits do not exist, and two cases then failed for the wrong reason. The
# "for its own reason" lines are what showed it.)
make_repo() {
    git clone -q --no-checkout --shared "${2:-$SCRATCH/repo}" "$SCRATCH/$1" || return 1
    git -C "$SCRATCH/$1" remote remove origin || return 1
    mkdir -p "$SCRATCH/$1/tools/package" || return 1
    cp "$TOOLS_FROM/package/build.sh" "$TOOLS_FROM/package/check-package.py" "$TOOLS_FROM/package/dompdf-files.sha256" "$TOOLS_FROM/package/gitleaks-settings.toml" "$SCRATCH/$1/tools/package/" || return 1
    cp "$TOOLS_FROM/download-dompdf.sh" "$TOOLS_FROM/download-cdn-fallbacks.sh" "$SCRATCH/$1/tools/" || return 1
}
make_repo repo "$REPO_ROOT" || { echo "ERROR: could not make the scratch repository" >&2; exit 1; }
R="$SCRATCH/repo"
BUILD="$R/tools/package/build.sh"
CHECKER="$R/tools/package/check-package.py"
WEB_FOLDER="$(sed -n 's/^STAFF_WEB_FOLDER="\([A-Za-z0-9_]*\)"$/\1/p' "$BUILD")"
VERSION="$(git -C "$R" show "$BASE_COMMIT:web/_core/version.php" | sed -n "s/^return '\([^']*\)';.*/\1/p")"

# What the planted files hold.
C="$SCRATCH/content"
printf 'planted by the package self-test\n' > "$C/txt"
printf '<?php\n// planted by the package self-test\n' > "$C/php"
# The database login exactly as web/_install/index.php writes it (a made-up password).
printf "<?php\nreturn [\n    'db_host' => 'localhost',\n    'db_user' => 'portal',\n    'db_pass' => 'not-a-real-password-selftest',\n    'db_name' => 'portal',\n];\n" > "$C/login"
# The encryption key's shape: 64 hexadecimal characters (made up).
printf '%s' "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef" > "$C/key"
# A shape the real gitleaks knows (an AWS key id, made up). It is put together
# here, at run time, from pieces, so that this script does not itself hold
# one: written out whole, even once, in a line that only LOOKS for it, the
# real gitleaks reported this very file.
KNOWN_SHAPE="$(printf '%s%s%s' "AKIA" "Z7QK2M4N" "X6PWL3VT")"
# shellcheck disable=SC2016  # the single quotes are deliberate: $awsKey is PHP's, not bash's
printf '<?php\n$awsKey = "%s";\n' "$KNOWN_SHAPE" > "$C/aws"
export SELFTEST_KNOWN_SHAPE="$KNOWN_SHAPE"
printf 'text\0with\0zero\0bytes\n' > "$C/bin"
# The login as JSON, and the first line mysqldump writes.
printf '{"db_host": "localhost", "db_pass": "not-a-real-password-selftest"}\n' > "$C/login-json"
printf -- '-- MySQL dump 10.13  Distrib 8.0.36, for Linux (x86_64)\nINSERT INTO tblUsers VALUES (1);\n' > "$C/dump"
printf '<?php\nthis is not php (\n' > "$C/broken-php"
# Files of web-server rules.
printf 'Options +Indexes\nAddHandler application/x-httpd-php .png .txt .js .svg\n' > "$C/htaccess-runs-pictures"
printf 'Require all denied\n' > "$C/htaccess-refuse-all"
printf 'Options -Indexes -ExecCGI\nRequire all denied\n' > "$C/htaccess-switches-off"
# One rule split over two lines with a backslash, as the server would join it.
printf 'Add\\\nHandler application/x-httpd-php .txt\n' > "$C/htaccess-split-line"
(cd "$C" && "$REAL_ZIP" -q zip.zip txt && gzip -c txt > gz)
mv "$C/zip.zip" "$C/zip"

# new_commit <parent> <name> [<path under web/> <content name>]…  → prints the commit
new_commit() {
    local parent="$1" name="$2" blob tree
    shift 2
    export GIT_INDEX_FILE="$SCRATCH/index.$name"
    git -C "$R" read-tree "$parent" || return 1
    while [[ $# -gt 0 ]]; do
        blob="$(git -C "$R" hash-object -w -- "$2")" || return 1
        git -C "$R" update-index --add --cacheinfo "100644,$blob,$1" || return 1
        shift 2
    done
    tree="$(git -C "$R" write-tree)" || return 1
    unset GIT_INDEX_FILE
    git -C "$R" -c user.name=selftest -c user.email=selftest@invalid commit-tree "$tree" -p "$parent" -m "self-test: $name (never kept)"
}

# Pictures, fonts and drawings, made from the REAL ones git holds at the base
# commit, so that each planted file differs from a good one in one way only.
make_pictures() {
    python3 - "$R" "$BASE_COMMIT" "$C" "$KNOWN_SHAPE" << 'PYEOF'
import io, subprocess, sys, zipfile, zlib
repo, commit, out, shape = sys.argv[1:5]
def git(*args):
    return subprocess.run(["git", "-C", repo, *args], capture_output=True, check=True).stdout
names = git("ls-tree", "-r", "--name-only", commit, "--", "web/public_html/").decode().splitlines()
def real(kind):
    """The first real file of this kind that git holds."""
    return git("show", f"{commit}:{next(name for name in names if name.endswith(kind))}")
def put(name, data):
    open(f"{out}/{name}", "wb").write(data)
png, gif, ico, icns, woff2 = (real(kind) for kind in (".png", ".gif", ".ico", ".icns", ".woff2"))
packed = io.BytesIO()
with zipfile.ZipFile(packed, "w") as archive:
    archive.writestr("auth_creds.php", "<?php // made up\n")
a_zip = packed.getvalue()
def png_holding(note):
    """A real PNG with one more well-formed part (its checksum right) before its end part."""
    part = b"prVt" + note
    return png[:-12] + len(note).to_bytes(4, "big") + part + zlib.crc32(part).to_bytes(4, "big") + png[-12:]
put("png-real", png)
put("png-tail", png + b"anything at all")
put("png-zip-inside", png_holding(a_zip))
put("png-php-inside", png_holding(b"<?PHP phpinfo();"))
put("png-key-inside", png_holding(b"-----BEGIN PRIVATE KEY-----\nmade up\n"))
put("png-start-only", png[:8] + b"\nnot a picture after its first eight bytes\n")
put("gif-tail", gif + b"anything at all")
put("gif-start-only", b"GIF89a" + b"DB_PASSWORD=made-up\n" * 3)
put("ico-tail", ico + b"anything at all")
put("icns-tail", icns + b"anything at all")
put("icns-start-only", b"icns" + b"a3f1" * 16 + b"\n")
put("woff2-tail", woff2 + b"anything at all")
grown = woff2 + b"anything at all!"
put("woff2-tail-length-put-right", grown[:8] + len(grown).to_bytes(4, "big") + grown[12:])
put("woff2-zip", b"wOF2" + a_zip)
put("woff2-text", b"this is not a font at all\n")
def svg(inner, attributes=""):
    return f'<?xml version="1.0"?>\n<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"{attributes}>{inner}</svg>\n'.encode()
# Links that stay inside the drawing are fine, and this one must be accepted.
put("svg-good", svg('<defs><linearGradient id="g"><stop offset="0" stop-color="#fff"/></linearGradient></defs><rect id="r" width="8" height="8" fill="url(#g)"/><use href="#r"/>'))
put("svg-script", svg("<script>fetch('/api/users')</script>"))
put("svg-event", svg("<rect width='8' height='8'/>", " onload=\"fetch('/api/users')\""))
put("svg-foreign", svg("<foreignObject><p>a page</p></foreignObject>"))
put("svg-link-out", svg('<image href="https://collector.invalid/x.png"/>'))
put("svg-data-link", svg('<image href="data:image/png;base64,AAAA"/>'))
put("svg-style-out", svg("<style>rect { fill: url(https://collector.invalid/f.svg#a); }</style>"))
put("svg-animated-link", svg('<a href="#x"><set attributeName="href" to="#y"/></a>'))
put("svg-stylesheet", b'<?xml version="1.0"?>\n<?xml-stylesheet href="https://collector.invalid/s.css"?>\n<svg xmlns="http://www.w3.org/2000/svg"/>\n')
put("svg-doctype", b'<?xml version="1.0"?>\n<!DOCTYPE svg [<!ENTITY a "aaaa">]>\n<svg xmlns="http://www.w3.org/2000/svg">&a;</svg>\n')
put("svg-not-a-drawing", b"<svg xmlns='http://www.w3.org/2000/svg'><rect></svg>\n")
put("svg-key", svg("<!-- kept here for now\n-----BEGIN PRIVATE KEY-----\nmade up\n-->"))
PYEOF
}
make_pictures || { echo "ERROR: could not make the planted pictures, fonts and drawings" >&2; exit 1; }

# PASSABLE: the base commit made buildable. Two things may stand in the way
# today, and each is handled only while it is true: lines that carry a domain
# of ours (package F4 removes them), and no installation guide (part 2).
DOMAIN_FILES=""
EXTRA=()
while IFS= read -r NAME; do
    [[ -n "$NAME" ]] || continue
    while IFS= read -r FILE; do
        [[ -n "$FILE" ]] || continue
        case " $DOMAIN_FILES " in *" $FILE "*) continue ;; esac
        DOMAIN_FILES="$DOMAIN_FILES $FILE"
        git -C "$R" show "$BASE_COMMIT:$FILE" | python3 -c 'import re, sys; sys.stdout.buffer.write(re.sub(sys.argv[1].encode(), b"a-church", sys.stdin.buffer.read(), flags=re.I))' "$NAME" > "$C/fixed.$(echo "$FILE" | tr '/' '_')"
        EXTRA+=("$FILE" "$C/fixed.$(echo "$FILE" | tr '/' '_')")
    done < <(git -C "$R" grep -l -i -I -F "$NAME" "$BASE_COMMIT" -- web/ | sed "s/^$BASE_COMMIT://")
done < <(python3 "$CHECKER" --print-own-domains)
HAD_GUIDE=1
if ! git -C "$R" cat-file -e "$BASE_COMMIT:docs/INSTALL.md" 2> /dev/null; then
    HAD_GUIDE=0
    printf '# Installing (a stand-in written by the package self-test)\n\nPoint your address at the %s folder.\n' "$WEB_FOLDER" > "$C/guide"
    EXTRA+=("docs/INSTALL.md" "$C/guide")
fi
PASSABLE="$(new_commit "$BASE_COMMIT" passable ${EXTRA[@]+"${EXTRA[@]}"})" || { echo "ERROR: could not make the passable scratch commit" >&2; exit 1; }

# The 64 files an independent check planted on 12 October 2026 (36 of them
# were shipped then), its 7 "the login under another name" files, and its 5
# odd names. "what|path under web/|the text to look for, when it is not the path".
PLANTED_BY_THE_CHECK=(
    "txt|.claude/notes.md" "txt|.dev-team/x.md" "txt|.env" "txt|.github/workflows/x.yml" "txt|.installed"
    "txt|README.md" "login|_auth_keys/auth_creds.php" "txt|_backups/dump.sql" "txt|_core/.DS_Store"
    "txt|_core/.d.swp" "txt|_core/.env.local" "txt|_core/.gitignore" "txt|_core/.vscode/settings.json"
    "txt|_core/Thumbs.db" "txt|_core/a.bak" "php|_core/c.php~" "txt|_core/cert.PEM" "txt|_core/debug.log"
    "txt|_core/error_log" "txt|_core/secret.KEY" "txt|_core/store.PFX" "txt|_core/store.p12"
    "txt|_core/sub/.ENV" "txt|_uploads/member-photo.txt" "txt|composer.lock" "txt|docs/x.md" "txt|tools/x.sh"
    "aws|_core/planted-known-shape.php"
    "txt|_apps/.claude/settings.local.json" "txt|_apps/.github/workflows/x.yml" "txt|_apps/help/docs/x.md"
    "txt|_apps/help/tools/x.sh" "txt|_core/.dev-team/x.md" "txt|_core/.env-backup" "txt|_core/.envrc"
    "txt|_core/.npmrc" "php|_core/Database copy.php" "txt|_core/b.orig" "zip|_core/backup.zip"
    "txt|_core/composer.json" "php|_core/config.php.backup" "php|_core/config.php.bak2" "txt|_core/db.sqlite"
    "gz|_core/dump.sql.gz" "txt|_core/e.old" "txt|_core/f.rej" "txt|_core/g.tmp" "php|_core/h.php.save"
    "txt|_core/id_rsa" "txt|_core/keystore.jks" "login|_core/planted-plain-login.php" "txt|_core/prod.env"
    "txt|_core/putty.ppk" "txt|_core/server.crt" "gz|_core/site-backup.tar.gz" "php|_core/test.php"
    "txt|_sql/dump-2026-09-13.sql" "txt|public_html/.claude-work/resume.md" "txt|public_html/.htpasswd"
    "txt|public_html/.user.ini" "php|public_html/adminer.php" "txt|public_html/assets/js/app.js.map"
    "php|public_html/info.php" "php|public_html/phpinfo.php"
    "login|_core/auth_creds.php.txt" "login|_core/auth_creds.old.php" "login|_apps/_auth_keys_copy/creds.php"
    "login|public_html/_auth_keys/db.php" "txt|public_html/_backups/portal-2026-09-13.sql"
    "txt|public_html/_uploads/member-photo.jpg" "key|_core/_auth_keys/enc.key.old"
    $'txt|_core/secret.key\n|_core/secret.key\\n' "txt|_core/x.key |_core/x.key '" "txt|_core/x.key.|_core/x.key.'"
    "txt|_core/co:lon.txt" 'txt|_core/back\slash.txt|_core/back\\slash.txt'
)
# Twenty kinds of file that are on NO list in check-package.py, to show that
# the allow list refuses what nobody thought of.
UNHEARD_OF=(
    "txt|_core/report.pdf.exe" "php|_core/tool.phar" "txt|_core/cache.sqlite3" "txt|_core/passwords.kdbx"
    "txt|_core/office.ovpn" "txt|_core/terraform.tfstate" "txt|_core/credentials" "txt|_core/settings.yaml"
    "txt|_core/php.ini" "txt|_core/members.csv" "txt|_core/accounts.xlsx" "txt|_core/deploy.sh"
    "txt|_core/export.xml" "txt|_core/secrets.toml" "txt|_core/session.har" "txt|_core/remote.rdp"
    "txt|_core/notes.docx" "txt|_core/service-account.p8" "txt|_core/wallet.dat" "txt|_core/Makefile"
)
# An allowed kind in a place nobody allowed, or with contents that do not suit it.
OUT_OF_PLACE=(
    "php|public_html/assets/shell.php" "txt|_apps/help/notes.md" "txt|public_html/members.json"
    "txt|_core/notes.txt" "txt|_apps/giving/export.sql" "txt|_sql/members_export.sql"
    "txt|public_html/assets/images/logo.png" "zip|public_html/assets/images/photo.png" "bin|_core/Blob.php"
)

# ---- Added after the second independent check of 12 October 2026 ----
# PHP inside the web folder under a name that does not END in .php.
PHP_ENDINGS=(
    "php|public_html/assets/js/shell.php.js" "php|public_html/assets/noticeboard/fonts/shell.php.txt"
    "php|public_html/assets/css/shell.PHP.css" "png-real|public_html/assets/images/phpinfo.php.png"
    "php|public_html/assets/js/tool.phtml.js" "php|public_html/assets/js/tool.phar.js"
    "php|public_html/assets/js/tool.pht.js" "txt|public_html/assets/js/settings.inc.js"
    "php|public_html/assets/js/tool.php7.js" "txt|public_html/assets/code.php/app.js"
    "php|public_html/sub/index.php" "php|public_html/assets/index.php"
)
# Two of these used to be planted at _core/.htaccess and _apps/.htaccess, as
# files "nobody named". Since 12 October 2026 the portal ships its own "refuse
# everything" file at those two places (and in _lang, _sql and _vendor), named
# in ALLOWED_HTACCESS, so a file planted there is no longer un-named: it is a
# named file that has CHANGED. The two are now planted one folder further down,
# where nothing is named, and the one in _lang/ is kept as the proof that a
# named "refuse everything" file is still held to its checksum.
HTACCESS_FILES=(
    "htaccess-runs-pictures|public_html/assets/.htaccess" "htaccess-changed|public_html/.htaccess"
    "htaccess-refuse-all|_core/templates/.htaccess" "htaccess-switches-off|_apps/admin/.htaccess" "htaccess-split-line|_lang/.htaccess"
)
# "what|path under web/|the reason the allow list must give"
PICTURES=(
    "png-tail|public_html/assets/images/p-tail.png|byte(s) follow the picture's end"
    "png-zip-inside|public_html/assets/images/p-zip-inside.png|it holds the start of a file inside a zip archive"
    "png-php-inside|public_html/assets/images/p-php-inside.png|it holds a PHP opening tag"
    "png-key-inside|public_html/assets/images/p-key-inside.png|it holds the first line of a key or a certificate"
    "png-start-only|public_html/assets/images/p-start-only.png|one of its parts runs past the end of the file"
    "gif-tail|public_html/assets/images/p-tail.gif|byte(s) follow the picture's end"
    "gif-start-only|public_html/assets/images/p-start-only.gif|it holds a part that is neither a picture nor a note"
    "ico-tail|public_html/assets/images/p-tail.ico|byte(s) follow its last picture"
    "icns-tail|public_html/assets/images/p-tail.icns|the length it states is not the file's length"
    "icns-start-only|public_html/assets/images/p-start-only.icns|the length it states is not the file's length"
    "woff2-tail|public_html/assets/fonts/p-tail.woff2|the length it states is not the file's length"
    "woff2-tail-length-put-right|public_html/assets/fonts/p-tail-length-put-right.woff2|its packed data does not end where the file ends"
    "woff2-zip|public_html/assets/fonts/p-zip.woff2|it holds the start of a file inside a zip archive"
    "woff2-text|public_html/assets/fonts/p-text.woff2|its contents do not begin like one"
)
DRAWINGS=(
    "svg-script|public_html/assets/images/d-script.svg|it holds a script"
    "svg-event|public_html/assets/images/d-event.svg|it holds an event attribute (onload=)"
    "svg-foreign|public_html/assets/images/d-foreign.svg|it holds a foreignObject"
    "svg-link-out|public_html/assets/images/d-link-out.svg|it points outside itself (href="
    "svg-data-link|public_html/assets/images/d-data-link.svg|it points outside itself (href="
    "svg-style-out|public_html/assets/images/d-style-out.svg|its style fetches something from outside the drawing"
    "svg-animated-link|public_html/assets/images/d-animated-link.svg|it animates a link"
    "svg-stylesheet|public_html/assets/images/d-stylesheet.svg|it holds an instruction other than the opening <?xml line"
    "svg-doctype|public_html/assets/images/d-doctype.svg|it holds a DOCTYPE"
    "svg-not-a-drawing|public_html/assets/images/d-not-a-drawing.svg|it cannot be read as a drawing"
    "svg-key|public_html/assets/images/d-key.svg|it holds the first line of a key or a certificate"
)
CAPITAL_ENDINGS=("broken-php|_apps/help/Mixed.Php" "php|_core/Upper.PHP" "png-real|public_html/assets/images/Logo.PNG")
# The four rules the second check loosened without this self-test noticing
# (its fifth, the syntax check, is cases C09 to C11).
LOOSENED_UNSEEN=(
    "login-json|_core/data/login.json|holds the database login in the shape the installer writes it"
    "dump|_sql/252_real_header.sql|begins like a dump of a real database"
    "txt|public_html/notes.html|a .html file outside the place that kind is allowed"
)
# Files other packages will add. Every one MUST be accepted: a rule that
# refuses these has been tightened too far.
NEW_AND_FINE=(
    "svg-good|public_html/assets/images/planted-good.svg" "png-real|public_html/assets/images/planted-real.png"
    "php|_core/PlantedNew.php" "php|_core/planted.inc.php" "txt|public_html/assets/js/php-helper.js"
)

# Turns a list into the arguments new_commit takes.
plant_args() {
    PLANT=()
    local item what path
    for item in "$@"; do
        what="${item%%|*}"; path="${item#*|}"; path="${path%%|*}"
        PLANT+=("web/$path" "$C/$what")
    done
}
# The text the checker's output must hold for one planted item.
needle_of() {
    local rest="${1#*|}"
    if [[ "$rest" == *"|"* ]]; then
        printf '%s' "${rest#*|}" | sed "s#^public_html/#$WEB_FOLDER/#"
    else
        printf '%s' "$rest" | sed "s#^public_html/#$WEB_FOLDER/#"
    fi
}

plant_args "${PLANTED_BY_THE_CHECK[@]}"
PLANT_CHECK="$(new_commit "$PASSABLE" planted-by-the-check "${PLANT[@]}")" || { echo "ERROR: could not plant the check's files" >&2; exit 1; }
plant_args "${UNHEARD_OF[@]}" "${OUT_OF_PLACE[@]}" "key|_core/Salt.php"
PLANT_OWN="$(new_commit "$PASSABLE" unheard-of "${PLANT[@]}")" || { echo "ERROR: could not plant the unheard-of files" >&2; exit 1; }
{ git -C "$R" show "$PASSABLE:web/public_html/.htaccess" && printf '# one line more than the copy that was read\n'; } > "$C/htaccess-changed" || exit 1
plant_args "${PHP_ENDINGS[@]}"
PLANT_PHP_ENDINGS="$(new_commit "$PASSABLE" php-endings "${PLANT[@]}")" || { echo "ERROR: could not plant the PHP endings" >&2; exit 1; }
plant_args "${HTACCESS_FILES[@]}"
PLANT_HTACCESS="$(new_commit "$PASSABLE" htaccess "${PLANT[@]}")" || { echo "ERROR: could not plant the .htaccess files" >&2; exit 1; }
plant_args "${PICTURES[@]}" "${DRAWINGS[@]}" "${LOOSENED_UNSEEN[@]}"
PLANT_PICTURES="$(new_commit "$PASSABLE" pictures "${PLANT[@]}")" || { echo "ERROR: could not plant the pictures" >&2; exit 1; }
plant_args "${CAPITAL_ENDINGS[@]}"
PLANT_CAPITALS="$(new_commit "$PASSABLE" capitals "${PLANT[@]}")" || { echo "ERROR: could not plant the capital endings" >&2; exit 1; }
plant_args "${NEW_AND_FINE[@]}"
PLANT_FINE="$(new_commit "$PASSABLE" new-and-fine "${PLANT[@]}")" || { echo "ERROR: could not plant the acceptable files" >&2; exit 1; }
# A domain of ours in a file of its own, so that the proof of the domain guard
# does not depend on the base still carrying such lines (package F4 removes
# them). The name is asked of the checker at run time and written in mixed
# capitals; it is never written out in this script.
OWN_DOMAIN="$(python3 "$CHECKER" --print-own-domains | head -n 1)"
# shellcheck disable=SC2016  # the single quotes are deliberate: $address is PHP's, not bash's
printf '<?php\n// planted by the package self-test\n$address = "https://portal.%s.uk/";\n' "$(printf '%s' "$OWN_DOMAIN" | tr '[:lower:]' '[:upper:]')" > "$C/domain"
PLANT_DOMAIN="$(new_commit "$PASSABLE" own-domain web/_core/PlantedAddress.php "$C/domain")" || exit 1
LOST_FILE="$(new_commit "$PASSABLE" lost-file web/_includes/helper.php "$C/php")" || exit 1
# The ONE file a left-out folder may hold is its own "refuse everything" file,
# byte for byte the copy in _core/ (roll-out package F5). Two that are not
# that: the folder's own rules file with other wording, and the right wording
# one folder further down.
git -C "$R" show "$PASSABLE:web/_core/.htaccess" > "$C/htaccess-of-core" || exit 1
REFUSE_REWORDED="$(new_commit "$PASSABLE" refuse-reworded web/_includes/.htaccess "$C/htaccess-refuse-all")" || exit 1
REFUSE_DEEPER="$(new_commit "$PASSABLE" refuse-deeper web/_functions/kept/.htaccess "$C/htaccess-of-core")" || exit 1
BRAND_ZIP="$(new_commit "$PASSABLE" changed-archive web/public_html/assets/images/brandkit/WebMSChurchMS.zip "$C/zip")" || exit 1
# A link: git's mode 120000, pointing out of the package.
export GIT_INDEX_FILE="$SCRATCH/index.link"
git -C "$R" read-tree "$PASSABLE"
git -C "$R" update-index --add --cacheinfo "120000,$(printf '../../../../etc/passwd' | git -C "$R" hash-object -w --stdin),web/_core/planted-link"
LINK="$(git -C "$R" -c user.name=selftest -c user.email=selftest@invalid commit-tree "$(git -C "$R" write-tree)" -p "$PASSABLE" -m "self-test: link (never kept)")"
unset GIT_INDEX_FILE

echo "Scratch repository ready: base $(git -C "$R" rev-parse --short "$BASE_COMMIT"), version $VERSION, web folder $WEB_FOLDER;"
echo "  domain lines at the base:$([[ -n "$DOMAIN_FILES" ]] && echo " yes, in$DOMAIN_FILES" || echo " none"); guide at the base: $([[ "$HAD_GUIDE" -eq 1 ]] && echo yes || echo "no (a stand-in is used)")"
if [[ "$GITLEAKS_IS_REAL" -eq 1 ]]; then
    echo "  gitleaks: the real one ($(gitleaks version 2> /dev/null))"
else
    echo "  gitleaks: NOT INSTALLED. A STAND-IN IS USED, so this run proves nothing about what the real gitleaks finds."
fi

# run <case> <command…>: keeps the output and the exit code, read directly.
run() {
    local id="$1"
    shift
    "$@" > "$LOGS/$id.log" 2>&1
    echo "$?" > "$LOGS/$id.rc"
}
tree() { run "$1" python3 "$CHECKER" --tree web --commit "$2"; }
build() { local id="$1" commit="$2"; shift 2; run "$id" bash "$BUILD" "$commit" live "$SCRATCH/out/$id" "$@"; }
# every_one_named <case> <guard, or "" for anywhere> <items…>
every_one_named() {
    local id="$1" guard="$2" item missing=0 lines
    shift 2
    if [[ -n "$guard" ]]; then lines="$(guard_lines "$id" "$guard")"; else lines="$(cat "$LOGS/$id.log")"; fi
    for item in "$@"; do
        if ! grep -qF -- "$(needle_of "$item")" <<< "$lines"; then
            missing=$((missing + 1))
            echo "        not named${guard:+ by $guard}: $(needle_of "$item")"
        fi
    done
    [[ "$missing" -eq 0 ]]
}
none_named() {
    local id="$1" guard="$2" item lines
    shift 2
    lines="$(guard_lines "$id" "$guard")"
    for item in "$@"; do
        grep -qF -- "$(needle_of "$item")" <<< "$lines" && { echo "        named by $guard, which should not know it: $(needle_of "$item")"; return 1; }
    done
    return 0
}
named() { if every_one_named "$@"; then ok "$1 ${CASE_NOTE}"; else not_ok "$1 ${CASE_NOTE}"; fi; }
# why <case> <guard> <path under web/> <reason>: that guard names this file, and for this reason
why() {
    local path
    path="$(printf '%s' "$3" | sed "s#^public_html/#$WEB_FOLDER/#")"
    if guard_lines "$1" "$2" | grep -F -- "$path" | grep -qF -- "$4"; then ok "$1 … $3: $4"; else not_ok "$1 … $3: $4"; fi
}
# each_why <case> <guard> <"what|path|reason"…>
each_why() {
    local id="$1" guard="$2" item rest
    shift 2
    for item in "$@"; do rest="${item#*|}"; why "$id" "$guard" "${rest%%|*}" "${rest#*|}"; done
}
# Can a program started from this shell be sent this signal at all? Not when
# the shell was itself started with the signal ignored (see the top).
signal_reaches() { bash -c "trap 'exit 7' $1; kill -s $1 \$\$; exit 0" > /dev/null 2>&1; [[ $? -eq 7 ]]; }
SKIPPED=0

# -----------------------------------------------------------------------------
# T. The pull-request form: the same rules over git's own list (no build)
# -----------------------------------------------------------------------------
if wanted T01; then
    tree T01 "$PASSABLE"
    check "T01 git's list at the passable commit: accepted" rc_is T01 0
    check "T01 … and the run reached its end" has T01 "may be packaged; the run reached its end."
fi
if wanted T02; then
    tree T02 "$PLANT_CHECK"
    check "T02 the ${#PLANTED_BY_THE_CHECK[@]} files the independent check planted: refused" rc_is T02 1
    # All but one. _core/planted-known-shape.php is an ordinary PHP file in an
    # ordinary place, holding a key in a shape only gitleaks knows. This form
    # does not run gitleaks (the pull-request checks run it themselves); the
    # whole build does, and case B06 requires it to name that file.
    SEEN_WITHOUT_GITLEAKS=()
    for ITEM in "${PLANTED_BY_THE_CHECK[@]}"; do
        [[ "$ITEM" == "aws|"* ]] || SEEN_WITHOUT_GITLEAKS+=("$ITEM")
    done
    CASE_NOTE="… every one of the ${#SEEN_WITHOUT_GITLEAKS[@]} that can be seen without gitleaks is named"; named T02 "" "${SEEN_WITHOUT_GITLEAKS[@]}"
    # The second net on its own: the deny list must name these whatever the
    # allow list does, at any depth.
    CASE_NOTE="… the deny list, on its own, names the server-kept and planning folders BELOW the top, and the leftovers"
    named T02 "the deny list" "txt|public_html/_backups/portal-2026-09-13.sql" "txt|public_html/_uploads/member-photo.jpg" \
        "key|_core/_auth_keys/enc.key.old" "txt|_apps/.claude/settings.local.json" "txt|_core/.dev-team/x.md" \
        "txt|_apps/.github/workflows/x.yml" "txt|public_html/.claude-work/resume.md" "txt|_core/prod.env" \
        "txt|_core/.env-backup" "txt|_core/.envrc" "txt|_core/.npmrc" "txt|_core/id_rsa" "txt|_core/b.orig" \
        "txt|_core/db.sqlite" "gz|_core/dump.sql.gz" "txt|public_html/assets/js/app.js.map" "php|public_html/adminer.php" \
        "php|public_html/info.php" "php|public_html/phpinfo.php" "login|_core/auth_creds.php.txt" "php|_core/Database copy.php" \
        "txt|_core/server.crt" "txt|_core/putty.ppk" "txt|_core/keystore.jks" "php|_core/test.php" "txt|_sql/dump-2026-09-13.sql" \
        "txt|_core/composer.json" "php|_core/config.php.bak2" "php|_core/h.php.save" "txt|_core/x.key |_core/x.key '" "txt|_core/x.key.|_core/x.key.'"
    check "T02 … an archive is refused AS an archive (nothing looks inside one)" has T02 "'_core/backup.zip' — an archive."
    check "T02 … whatever kind of archive it is" has T02 "'_core/site-backup.tar.gz' — an archive."
    check "T02 … and the run reached its end" has T02 "the run reached its end."
fi
if wanted T03; then
    tree T03 "$PLANT_OWN"
    check "T03 twenty kinds on no list, and nine files out of place: refused" rc_is T03 1
    CASE_NOTE="… all ${#UNHEARD_OF[@]} unheard-of kinds are named by the allow list"; named T03 "the allow list" "${UNHEARD_OF[@]}"
    if none_named T03 "the deny list" "${UNHEARD_OF[@]}"; then ok "T03 … and by the allow list ALONE: the deny list names none of them"; else not_ok "T03 … and by the allow list ALONE: the deny list names none of them"; fi
    CASE_NOTE="… all ${#OUT_OF_PLACE[@]} out-of-place files are named by the allow list"; named T03 "the allow list" "${OUT_OF_PLACE[@]}"
    CASE_NOTE="… a file that is 64 hexadecimal characters is named by the contents guard"; named T03 "contents" "key|_core/Salt.php"
    check "T03 … an archive called .png is refused AS an archive" has T03 "$WEB_FOLDER/assets/images/photo.png' — an archive."
    check "T03 … and the run reached its end" has T03 "the run reached its end."
fi
if wanted T04; then
    tree T04 "$LOST_FILE"
    check "T04 a real file in web/_includes/: refused, not left out silently" rc_is T04 1
    check "T04 … and it says which file and why" has T04 "web/_includes/helper.php — a real file in a folder the package leaves out"
fi
if wanted T04b; then
    # The base holds the "refuse everything" file in both left-out folders. It
    # is left out with them, by name, and that is not a failure.
    tree T04b "$PASSABLE"
    check "T04b the 'refuse everything' file in the two left-out folders: accepted" rc_is T04b 0
    check "T04b … and each is named as left out" has T04b "web/_functions/.htaccess, web/_includes/.gitkeep, web/_includes/.htaccess"
    check "T04b … and the run reached its end" has T04b "may be packaged; the run reached its end."
    tree T04c "$REFUSE_REWORDED"
    check "T04b the same file with other wording: refused" rc_is T04c 1
    check "T04b … as a real file that would be lost" has T04c "web/_includes/.htaccess — a real file in a folder the package leaves out"
    tree T04d "$REFUSE_DEEPER"
    check "T04b the right wording one folder further down: refused" rc_is T04d 1
    check "T04b … as a real file that would be lost" has T04d "web/_functions/kept/.htaccess — a real file in a folder the package leaves out"
fi
if wanted T05; then
    tree T05 "$BRAND_ZIP"
    check "T05 the one allowed archive, changed: refused" rc_is T05 1
    check "T05 … because its checksum differs" has T05 "this is not the copy that was opened and"
fi
if wanted T06; then
    tree T06 "$LINK"
    check "T06 a link in git's list: refused" rc_is T06 1
    check "T06 … and it is named" has T06 "_core/planted-link"
fi
if wanted T07; then
    tree T07 "$BASE_COMMIT"
    check "T07 git's list at the base commit itself: accepted" rc_is T07 0
    check "T07 … and it says what is left out" has T07 "left out on purpose"
fi

if wanted T08; then
    tree T08 "$PLANT_PHP_ENDINGS"
    check "T08 PHP inside the web folder under ${#PHP_ENDINGS[@]} names that do not end in .php, or are not on the list: refused" rc_is T08 1
    for ITEM in "${PHP_ENDINGS[@]}"; do why T08 "the allow list" "${ITEM#*|}" "with a PHP ending somewhere in its name"; done
    check "T08 … and the run reached its end" has T08 "the run reached its end."
fi
if wanted T09; then
    tree T09 "$PLANT_HTACCESS"
    check "T09 five .htaccess files nobody named, or changed since they were read: refused" rc_is T09 1
    why T09 "the allow list" "public_html/assets/.htaccess" "a file of web-server rules that ALLOWED_HTACCESS does not name"
    why T09 "the allow list" "public_html/assets/.htaccess" "could make the server run a file as a program, or list a folder: 'AddHandler application/x-httpd-php .png .txt .js .svg'"
    why T09 "the allow list" "public_html/assets/.htaccess" "could make the server run a file as a program, or list a folder: 'Options +Indexes'"
    why T09 "the allow list" "public_html/.htaccess" "this is not the copy that was read"
    why T09 "the allow list" "_core/templates/.htaccess" "add this line to ALLOWED_HTACCESS: \"_core/templates/.htaccess\": (\"$("$REAL_SHASUM" -a 256 "$C/htaccess-refuse-all" | cut -d' ' -f1)\", \"<why it ships>\"),"
    why T09 "the allow list" "_apps/admin/.htaccess" "a file of web-server rules that ALLOWED_HTACCESS does not name"
    why T09 "the allow list" "_lang/.htaccess" "this is not the copy that was read"
    check "T09 … a line that only switches things OFF is not called dangerous" has_not T09 "'Options -Indexes -ExecCGI'"
    why T09 "the allow list" "_lang/.htaccess" "could make the server run a file as a program, or list a folder: 'AddHandler application/x-httpd-php .txt'"
    check "T09 … and the run reached its end" has T09 "the run reached its end."
fi
if wanted T10; then
    tree T10 "$PLANT_PICTURES"
    check "T10 ${#PICTURES[@]} pictures and fonts that are not what they say all the way through, ${#DRAWINGS[@]} drawings that act or reach out, and three more: refused" rc_is T10 1
    each_why T10 "the allow list" "${PICTURES[@]}" "${DRAWINGS[@]}"
    each_why T10 "contents" "${LOOSENED_UNSEEN[@]:0:2}"
    each_why T10 "the allow list" "${LOOSENED_UNSEEN[@]:2:1}"
    check "T10 … and the run reached its end" has T10 "the run reached its end."
fi
if wanted T11; then
    tree T11 "$PLANT_CAPITALS"
    check "T11 three files whose ending has a capital letter: refused" rc_is T11 1
    for ITEM in "${CAPITAL_ENDINGS[@]}"; do why T11 "the allow list" "${ITEM#*|}" "its ending is not all small letters"; done
fi
if wanted T12; then
    tree T12 "$PLANT_FINE"
    check "T12 ${#NEW_AND_FINE[@]} new files of allowed kinds (a drawing with links inside itself, a real picture, PHP outside the web folder): ACCEPTED" rc_is T12 0
    check "T12 … and the run reached its end" has T12 "may be packaged; the run reached its end."
fi

# -----------------------------------------------------------------------------
# B. Whole builds
# -----------------------------------------------------------------------------
PASS_BUILT=""
# The passing build that the C cases plant their faults in. Built once.
need_pass_build() {
    [[ -n "$PASS_BUILT" ]] && return 0
    build B03 "$PASSABLE"
    PASS_BUILT="$SCRATCH/out/B03"
    PASS_ZIP="$(find "$PASS_BUILT" -maxdepth 1 -name 'webms-intra-*.zip' -print | head -n 1)"
}
# The permission bits stored in a zip: prints "clean <entries>" or the offenders.
zip_bits() {
    python3 - "$1" << 'PYEOF'
import sys, zipfile
bad = [i.filename for i in zipfile.ZipFile(sys.argv[1]).infolist() if i.create_system != 3 or (i.external_attr >> 16) & 0o7022 or (i.external_attr >> 16) == 0]
print("clean" if not bad else "NOT CLEAN: " + ", ".join(bad[:5]), len(zipfile.ZipFile(sys.argv[1]).infolist()))
sys.exit(1 if bad else 0)
PYEOF
}

if wanted B01 && [[ "$HAD_GUIDE" -eq 0 ]]; then
    build B01 "$BASE_COMMIT"
    check "B01 the base commit, no guide, no switch: refused" rc_is B01 1
    check "B01 … and it says why" has B01 "docs/INSTALL.md does not exist"
    check "B01 … and nothing was made" no_zip B01
fi
if wanted B02 && [[ -n "$DOMAIN_FILES" ]]; then
    build B02 "$BASE_COMMIT" --allow-missing-guide
    check "B02 the base commit carries a domain of ours: refused" rc_is B02 1
    check "B02 … by the domain guard" has B02 "FAIL — G6 our own domain"
    check "B02 … and by that guard ALONE (every other guard passes on the real files)" has B02 "FAIL — 1 guard(s) failed"
    check "B02 … and no zip was made" no_zip B02
fi
if wanted B03 || wanted C; then
    need_pass_build
    check "B03 the passable commit: builds" rc_is B03 0
    check "B03 … and says the folder and the zip both passed" has B03 "BUILD COMPLETE — the folder and the zip both passed every check; reached the end"
    # The zip check's own line, from a build: a build that skipped it has none.
    check "B03 … the checker ran TWICE and reached its end both times" test "$(grep -c '^PASS — every guard passed for .*the run reached its end\.$' "$LOGS/B03.log")" -eq 2
    check "B03 … the second time on the zip itself" test "$(grep -c '^PASS — G11-G13 the zip file' "$LOGS/B03.log")" -eq 1
    check "B03 … the zip and its checksum are in the output folder, and agree" bash -c "cd '$PASS_BUILT' && '$REAL_SHASUM' -a 256 -c '$(basename "${PASS_ZIP:-none}").sha256'"
    check "B03 … with nothing half-moved beside them" test -z "$(find "$PASS_BUILT" -maxdepth 1 -name '*.part' -print)"
    check "B03 … a real page called phpinfo.php, outside the web folder, still ships" bash -c "unzip -Z1 '$PASS_ZIP' | grep -qx '_apps/admin/system-info/phpinfo.php'"
    check "B03 … no entry in the zip is writable by group or others" zip_bits "$PASS_ZIP"
    check "B03 … and none in the folder" test -z "$(find "$PASS_BUILT/stage" -perm -020 -print -o -perm -002 -print | head -n 1)"
fi
if wanted B04; then
    (umask 0002; build B04 "$PASSABLE")
    check "B04 the same build by a user whose files are group-writable by default (umask 0002): builds" rc_is B04 0
    check "B04 … and still no entry in the zip is writable by group or others" zip_bits "$(find "$SCRATCH/out/B04" -maxdepth 1 -name 'webms-intra-*.zip' -print | head -n 1)"
    check "B04 … and the zip file and its checksum file are themselves rw-r--r--" test "$(find "$SCRATCH/out/B04" -maxdepth 1 -type f -name 'webms-intra-*' -perm 644 -print | wc -l | tr -d ' ')" -eq 2
    (umask 022; build B04b "$PASSABLE")
    check "B04 … the same under umask 022" zip_bits "$(find "$SCRATCH/out/B04b" -maxdepth 1 -name 'webms-intra-*.zip' -print | head -n 1)"
fi
if wanted B05; then
    build B05 "$PASSABLE" --tag "v$VERSION"
    check "B05 a tagged build: builds" rc_is B05 0
    check "B05 … and is called webms-intra-$VERSION.zip" test -f "$SCRATCH/out/B05/webms-intra-$VERSION.zip"
fi
if wanted B06; then
    build B06 "$PLANT_CHECK"
    check "B06 a build holding the ${#PLANTED_BY_THE_CHECK[@]} planted files: refused" rc_is B06 1
    CASE_NOTE="… every one is named"; named B06 "" "${PLANTED_BY_THE_CHECK[@]}"
    CASE_NOTE="… a colon, a backslash, a trailing space, a trailing dot and a line break are refused at the FOLDER check"
    named B06 "the allow list" $'txt|_core/secret.key\n|_core/secret.key\\n' "txt|_core/x.key |_core/x.key '" "txt|_core/x.key.|_core/x.key.'" "txt|_core/co:lon.txt" 'txt|_core/back\slash.txt|_core/back\\slash.txt'
    CASE_NOTE="… the login under other names is named by the contents guard"
    named B06 "contents" "login|_core/planted-plain-login.php" "login|_core/auth_creds.php.txt" "login|_apps/_auth_keys_copy/creds.php" "login|public_html/_auth_keys/db.php"
    check "B06 … the installer, which must hold that shape, is not named" has_not B06 "'_install/index.php' — holds the database login"
    CASE_NOTE="… phpinfo.php INSIDE the web folder is refused"; named B06 "the deny list" "php|public_html/phpinfo.php"
    check "B06 … gitleaks names the file and the line of its find, not just a count" has B06 "_core/planted-known-shape.php:2 — "
    check "B06 … and the value it found is not printed" has_not B06 "$KNOWN_SHAPE"
    check "B06 … it was the FOLDER that was refused, before any zip" has B06 "so NO zip was made"
    check "B06 … no zip was made" no_zip B06
    check "B06 … and the folder check reached its end" has B06 "the run reached its end."
fi
if wanted B07; then
    build B07 "$PLANT_OWN"
    check "B07 a build holding twenty unheard-of kinds: refused" rc_is B07 1
    CASE_NOTE="… every one is named by the allow list"; named B07 "the allow list" "${UNHEARD_OF[@]}" "${OUT_OF_PLACE[@]}"
    check "B07 … the message says what to do" has B07 "WHAT TO DO: if a file should not ship, remove it from web/"
    check "B07 … no zip was made" no_zip B07
fi
if wanted B08; then
    build B08 "$LOST_FILE"
    check "B08 a real file in web/_includes/: the build stops" rc_is B08 1
    check "B08 … saying which folder" has B08 "web/_includes holds a real file"
    check "B08 … no zip was made" no_zip B08
fi
if wanted B08b; then
    # The build's own copy of the same rule (see T04b). The file that IS let
    # through is in every other build here, since the base holds it.
    build B08b "$REFUSE_REWORDED"
    check "B08b the 'refuse everything' file of a left-out folder, with other wording: the build stops" rc_is B08b 1
    check "B08b … saying which folder" has B08b "web/_includes holds a real file"
    check "B08b … no zip was made" no_zip B08b
    build B08c "$REFUSE_DEEPER"
    check "B08b the right wording one folder further down: the build stops" rc_is B08c 1
    check "B08b … saying which folder" has B08c "web/_functions holds a real file"
    check "B08b … no zip was made" no_zip B08c
fi
if wanted B09; then
    build B09 "$LINK"
    check "B09 a link in git: the build stops" rc_is B09 1
    check "B09 … no zip was made" no_zip B09
fi
if wanted B10; then
    DROPPED="_libraries/dompdf/planted-dump.sql _libraries/dompdf/lib/planted-shell.php _libraries/dompdf/vendor/auth_creds.php.txt"
    SELFTEST_DROP_FILES="$DROPPED" build B10 "$PASSABLE" --tag "v$VERSION"
    check "B10 three files slipped into dompdf's folder AFTER the folder check: the build fails" rc_is B10 1
    check "B10 … the folder had passed" has B10 "/stage; the run reached its end."
    check "B10 … it is the ZIP that failed" has B10 "the zip failed its checks and was never put in the output folder"
    check "B10 … each of the three is named" bash -c "grep -qF \"planted-dump.sql' — not one of dompdf's own files\" '$LOGS/B10.log' && grep -qF \"planted-shell.php' — not one of dompdf's own files\" '$LOGS/B10.log' && grep -qF \"vendor/auth_creds.php.txt' — not one of dompdf's own files\" '$LOGS/B10.log'"
    check "B10 … and there is no zip, no checksum and no half-moved file" no_zip B10
fi
for MOMENT in write-checksum move-checksum move-zip name-zip last-look; do
    ID="B11-$MOMENT"
    if wanted B11 || wanted "$ID"; then
        SELFTEST_FAIL_AT="$MOMENT" build "$ID" "$PASSABLE"
        check "$ID a failure after the zipping ($MOMENT): the build fails" rc_is "$ID" 1
        check "$ID … the planted failure is what stopped it" has "$ID" "planted failure"
        check "$ID … and says BUILD FAILED" has "$ID" "BUILD FAILED"
        check "$ID … and no zip, checksum or half-moved file is left" no_zip "$ID"
    fi
done
if wanted B12; then
    make_repo repo-empty-checker && : > "$SCRATCH/repo-empty-checker/tools/package/check-package.py"
    run B12 bash "$SCRATCH/repo-empty-checker/tools/package/build.sh" "$PASSABLE" live "$SCRATCH/out/B12"
    check "B12 an EMPTY check-package.py (exit code 0, no words): the build fails" rc_is B12 1
    check "B12 … because the end line is missing" has B12 "its last line is not a PASS line"
    check "B12 … no zip was made" no_zip B12
fi

# A build told to stop at three moments after the zipping, by three signals.
for TRIPLE in move-checksum:HUP:129 move-zip:TERM:143 name-zip:INT:130; do
    MOMENT="${TRIPLE%%:*}"; SIGNAL="${TRIPLE#*:}"; SIGNAL="${SIGNAL%%:*}"; ID="B13-$SIGNAL"
    if wanted B13 || wanted "$ID"; then
        if signal_reaches "$SIGNAL"; then
            SELFTEST_SIGNAL_AT="$MOMENT" SELFTEST_SIGNAL="$SIGNAL" build "$ID" "$PASSABLE"
            check "$ID a build sent $SIGNAL after the zipping ($MOMENT): it ends with code ${TRIPLE##*:}" rc_is "$ID" "${TRIPLE##*:}"
            check "$ID … and says BUILD FAILED" has "$ID" "BUILD FAILED"
            check "$ID … and never says it is complete" has_not "$ID" "BUILD COMPLETE"
            check "$ID … and no zip, checksum or half-moved file is left" no_zip "$ID"
        else
            SKIPPED=$((SKIPPED + 1))
            echo "SKIPPED $ID: this shell was started with $SIGNAL ignored, so nothing started from it can be sent $SIGNAL" | tee -a "$RESULTS"
        fi
    fi
done
if wanted B14; then
    build B14 "$PLANT_DOMAIN"
    check "B14 a file that carries a domain of ours, in capitals: refused" rc_is B14 1
    check "B14 … by the domain guard, which names the file and the line" has B14 "  • _core/PlantedAddress.php:3"
    check "B14 … and by that guard ALONE" has B14 "FAIL — 1 guard(s) failed"
    check "B14 … and the name itself is not in this script" test "$(grep -c -i -F "$OWN_DOMAIN" "${BASH_SOURCE[0]}")" -eq 0
    check "B14 … and no zip was made" no_zip B14
fi

# -----------------------------------------------------------------------------
# C. The checker alone, on a copy of the passing build with ONE fault planted
# -----------------------------------------------------------------------------
CHECK_ARGS=(--commit "$PASSABLE" --channel live)
# fresh <case>: a new copy of the passing folder at $CASE
fresh() { CASE="$SCRATCH/case/$1"; mkdir -p "$SCRATCH/case" && cp -R "$PASS_BUILT/stage" "$CASE"; }
# zipped <case>: zip $CASE as the build would, with its checksum, at $ZIPPED
zipped() {
    mkdir -p "$SCRATCH/zipcase/$1"
    ZIPPED="$SCRATCH/zipcase/$1/$(basename "$PASS_ZIP")"
    (cd "$CASE" && "$REAL_ZIP" -X -q -r -y "$ZIPPED" . && cd "$(dirname "$ZIPPED")" && "$REAL_SHASUM" -a 256 "$(basename "$ZIPPED")" > "$(basename "$ZIPPED").sha256")
}
folder_case() {   # <case> <what> <text the output must hold>
    run "$1" python3 "$CHECKER" "$CASE" "${CHECK_ARGS[@]}"
    check "$1 $2: refused" rc_is "$1" 1
    check "$1 … for its own reason" has "$1" "$3"
    check "$1 … and the run reached its end" has "$1" "the run reached its end."
}
zip_case() {
    run "$1" python3 "$CHECKER" "$ZIPPED" "${CHECK_ARGS[@]}"
    check "$1 $2: refused" rc_is "$1" 1
    check "$1 … for its own reason" has "$1" "$3"
    check "$1 … and the run reached its end" has "$1" "the run reached its end."
}

if wanted C01; then
    need_pass_build; fresh C01; printf '\n' >> "$CASE/_libraries/dompdf/vendor/dompdf/dompdf/src/Dompdf.php"
    folder_case C01 "one byte added to one of dompdf's files" "_libraries/dompdf/vendor/dompdf/dompdf/src/Dompdf.php — differs from dompdf's own file"
fi
if wanted C02; then
    need_pass_build; fresh C02; rm -f "$CASE/_libraries/dompdf/AUTHORS.md"
    folder_case C02 "one of dompdf's files missing" "_libraries/dompdf/AUTHORS.md — on the list of dompdf's files, missing from the package"
fi
if wanted C03; then
    need_pass_build; fresh C03; cp "$C/php" "$CASE/_libraries/dompdf/vendor/extra.php"
    folder_case C03 "a file added to dompdf's folder" "_libraries/dompdf/vendor/extra.php' — not one of dompdf's own files"
fi
if wanted C04; then
    need_pass_build; fresh C04
    make_repo repo-list-drift && sed 's/^# release-zip-sha256: ..../# release-zip-sha256: 0000/' "$TOOLS_FROM/package/dompdf-files.sha256" > "$SCRATCH/repo-list-drift/tools/package/dompdf-files.sha256"
    run C04 python3 "$SCRATCH/repo-list-drift/tools/package/check-package.py" "$CASE" "${CHECK_ARGS[@]}"
    check "C04 a list of dompdf's files that was not made from the pinned download: the checker refuses" rc_is C04 1
    check "C04 … for its own reason" has C04 "but tools/download-dompdf.sh pins"
    run C04b bash "$SCRATCH/repo-list-drift/tools/download-dompdf.sh" "$SCRATCH/case/C04b/dompdf"
    check "C04 … and the fetch script refuses too" rc_is C04b 1
    check "C04 … for the same reason" has C04b "was not made from the download pinned in this script"
    check "C04 … having installed nothing" test ! -e "$SCRATCH/case/C04b/dompdf"
fi
if wanted C05; then
    need_pass_build; fresh C05; chmod 664 "$CASE/_core/version.php"; chmod 777 "$CASE/_lang"
    folder_case C05 "a file the group can write, and a folder everybody can" "'_core/version.php' — writable by group or others"
    check "C05 … the folder is named too" has C05 "'_lang' — writable by group or others"
fi
if wanted C06; then
    need_pass_build; fresh C06; chmod 666 "$CASE/_core/version.php"; zipped C06
    zip_case C06 "a zip that stores a file as writable by everybody" "'_core/version.php' — writable by group or others (-rw-rw-rw-)"
fi
if wanted C07; then
    need_pass_build; fresh C07; ln -s /etc/passwd "$CASE/_core/planted-link"; zipped C07
    zip_case C07 "a zip holding a link" "_core/planted-link — a link, not a real file"
fi
if wanted C08; then
    need_pass_build; fresh C08; zipped C08
    # Spoil the stored bytes of ONE file in the middle of the zip. The zip
    # still opens and lists; only reading that file shows the damage.
    python3 - "$ZIPPED" << 'PYEOF'
import struct, sys, zipfile
path = sys.argv[1]
info = max((i for i in zipfile.ZipFile(path).infolist() if i.filename.endswith("full_schema.sql")), key=lambda i: i.compress_size)
data = bytearray(open(path, "rb").read())
name_length, extra_length = struct.unpack("<HH", data[info.header_offset + 26:info.header_offset + 30])
middle = info.header_offset + 30 + name_length + extra_length + info.compress_size // 2
for offset in range(middle, middle + 64):
    data[offset] ^= 0xFF
open(path, "wb").write(data)
PYEOF
    (cd "$(dirname "$ZIPPED")" && "$REAL_SHASUM" -a 256 "$(basename "$ZIPPED")" > "$(basename "$ZIPPED").sha256")
    check "C08 (the spoiled zip still opens and lists its files)" bash -c "unzip -Z1 '$ZIPPED' | grep -q full_schema.sql"
    zip_case C08 "a zip holding one damaged file" "_sql/full_schema.sql — damaged inside the zip"
fi

if wanted C09; then
    need_pass_build; fresh C09; cp "$C/broken-php" "$CASE/_core/version.php"
    folder_case C09 "a PHP file with a mistake in it" "FAIL — G7 php -l: 1 of "
    check "C09 … and the file is named" test -n "$(guard_lines C09 "G7" | grep -F "_core/version.php — ")"
fi
if wanted C10; then
    need_pass_build; fresh C10; cp "$C/broken-php" "$CASE/_core/version.php"
    PATH="$SCRATCH/bin-lost-code:$PATH" run C10 python3 "$CHECKER" "$CASE" "${CHECK_ARGS[@]}"
    check "C10 the same mistake, read by a php whose exit code is lost on the way: still refused" rc_is C10 1
    check "C10 … by the syntax check, which names the file" test -n "$(guard_lines C10 "G7" | grep -F "_core/version.php — ")"
    check "C10 … and the run reached its end" has C10 "the run reached its end."
fi
if wanted C11; then
    need_pass_build; fresh C11; cp "$C/broken-php" "$CASE/_core/Mixed.Php"
    folder_case C11 "a PHP file with a mistake in it, called Mixed.Php" "'_core/Mixed.Php' — its ending is not all small letters"
    check "C11 … and the syntax check reads it all the same" test -n "$(guard_lines C11 "G7" | grep -F "_core/Mixed.Php — ")"
fi
if wanted C12; then
    need_pass_build; fresh C12
    # The one shape the secrets search is asked for here, in files the real
    # gitleaks does not read when left to itself: three it skips for their
    # names, one for the folder it is in, one for how it begins, and one where
    # the shape sits past the first 10,000 bytes on a line this case knows.
    mkdir -p "$CASE/$WEB_FOLDER/assets/node_modules"
    printf '<?xml version="1.0"?>\n<svg xmlns="http://www.w3.org/2000/svg">\n<!-- %s -->\n</svg>\n' "$KNOWN_SHAPE" > "$CASE/$WEB_FOLDER/assets/images/planted-key.svg"
    printf 'GIF89a\nkey = "%s"\n' "$KNOWN_SHAPE" > "$CASE/$WEB_FOLDER/assets/images/planted-key.gif"
    printf 'wOF2\nkey = "%s"\n' "$KNOWN_SHAPE" > "$CASE/$WEB_FOLDER/assets/images/planted-key.woff2"
    printf 'var key = "%s";\n' "$KNOWN_SHAPE" > "$CASE/$WEB_FOLDER/assets/node_modules/planted.js"
    printf 'MZ = 1;\nvar key = "%s";\n' "$KNOWN_SHAPE" > "$CASE/$WEB_FOLDER/assets/js/planted-begins-mz.js"
    { for ((LINE = 1; LINE <= 400; LINE++)); do printf '/* filler line %04d, to push the next one past ten thousand bytes */\n' "$LINE"; done; printf '/* key: %s */\n' "$KNOWN_SHAPE"; } > "$CASE/$WEB_FOLDER/assets/css/planted-late.css"
    run C12 python3 "$CHECKER" "$CASE" "${CHECK_ARGS[@]}"
    check "C12 one known shape in six files gitleaks skips or reads in pieces: refused" rc_is C12 1
    for PLACE in "assets/images/planted-key.svg:3" "assets/images/planted-key.gif:2" "assets/images/planted-key.woff2:2" "assets/node_modules/planted.js:1" "assets/js/planted-begins-mz.js:2" "assets/css/planted-late.css:401"; do
        check "C12 … the secrets search names $PLACE" test -n "$(guard_lines C12 "G5" | grep -F "$WEB_FOLDER/$PLACE — ")"
    done
    check "C12 … and the value it found is not printed" has_not C12 "$KNOWN_SHAPE"
    check "C12 … and the run reached its end" has C12 "the run reached its end."
fi

# -----------------------------------------------------------------------------
# D. The dompdf fetch script
# -----------------------------------------------------------------------------
if wanted D01; then
    # Called the way the deploy calls it: no arguments, from the top of a checkout.
    make_repo repo-deploy
    (cd "$SCRATCH/repo-deploy" && umask 0002 && run D01 bash tools/download-dompdf.sh)
    check "D01 called as the deploy calls it: works" rc_is D01 0
    check "D01 … and reached its end" has D01 "reached the end."
    check "D01 … the file the portal loads is there" test -s "$SCRATCH/repo-deploy/web/_libraries/dompdf/autoload.inc.php"
    check "D01 … nothing it unpacked is writable by group or others, even under umask 0002" test -z "$(find "$SCRATCH/repo-deploy/web/_libraries/dompdf" -perm -020 -print -o -perm -002 -print | head -n 1)"
    (cd "$SCRATCH/repo-deploy" && run D01b bash tools/download-dompdf.sh)
    check "D01 … and a second run skips" has D01b "already present and complete"
fi
if wanted D02; then
    # A different file served at dompdf's address, and its checksum offered in
    # the environment. Before 12 October 2026 that was accepted.
    mkdir -p "$SCRATCH/other-cache"
    OTHER="$SCRATCH/other-cache/$(printf '%s' "https://github.com/dompdf/dompdf/releases/download/$(sed -n 's/^DOMPDF_VERSION="\(.*\)"$/\1/p' "$R/tools/download-dompdf.sh")/dompdf-$(sed -n 's/^DOMPDF_VERSION="v\(.*\)"$/\1/p' "$R/tools/download-dompdf.sh").zip" | "$REAL_SHASUM" -a 256 | cut -d' ' -f1)"
    cp "$C/zip" "$OTHER"
    SELFTEST_CACHE="$SCRATCH/other-cache" DOMPDF_SHA256="$("$REAL_SHASUM" -a 256 "$OTHER" | cut -d' ' -f1)" run D02 bash "$R/tools/download-dompdf.sh" "$SCRATCH/case/D02/dompdf"
    check "D02 a different file, with its checksum offered in the environment: refused" rc_is D02 1
    check "D02 … because the pin in the script is the only pin" has D02 "does not match its pinned checksum"
    check "D02 … having installed nothing" test ! -e "$SCRATCH/case/D02/dompdf"
fi

# -----------------------------------------------------------------------------
if [[ "$NOT_OK" -gt 0 ]]; then
    echo
    echo "The output of each case that was not ok:"
    while IFS= read -r ID; do
        [[ -f "$LOGS/$ID.log" ]] && { echo "----- $ID (exit code $(cat "$LOGS/$ID.rc" 2> /dev/null))"; tail -n 40 "$LOGS/$ID.log"; }
    done < <(grep '^NOT-OK' "$RESULTS" | awk '{ print $2 }' | sort -u)
    echo "SELF-TEST FAILED — $NOT_OK of $TOTAL checks were not ok; reached the end."
    exit 1
fi
if [[ "$TOTAL" -eq 0 ]]; then
    echo "SELF-TEST FAILED — no case ran, so nothing was proved; reached the end."
    exit 1
fi
echo "SELF-TEST PASSED — $TOTAL of $TOTAL checks ok$([[ "$SKIPPED" -gt 0 ]] && echo " ($SKIPPED SIGNAL CASE(S) SKIPPED, see above)")$([[ "$GITLEAKS_IS_REAL" -eq 0 ]] && echo " (WITH A STAND-IN FOR gitleaks: the secrets search itself is not proved)"), in $((SECONDS / 60)) min $((SECONDS % 60)) s; reached the end."
