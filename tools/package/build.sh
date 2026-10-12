#!/usr/bin/env bash
# =============================================================================
# WebMS-Intra — Build the downloadable installation package (#499)
#
# Path: tools/package/build.sh
#
# Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
# Proprietary. Unauthorised copying, modification or distribution prohibited.
#
# PURPOSE
# -------
# Makes the zip a customer downloads to install or upgrade the portal with
# nothing but a hosting panel. It runs the same on a developer's machine and
# on GitHub, so everything it does can be proved before GitHub is involved;
# the workflow only calls it.
#
# Usage:
#   bash tools/package/build.sh <commit> <channel word> <output folder>
#        [--tag <tag>] [--allow-missing-guide] [--allow-no-gitleaks]
#
#   <commit>         what to package: a commit, a tag or a branch name
#   <channel word>   live, beta or alpha; written into the package's .channel
#   <output folder>  must not exist yet, or be empty. It receives:
#                      stage/                       the package, unpacked
#                      webms-intra-….zip            the package
#                      webms-intra-….zip.sha256     its checksum
#   --tag <tag>      for a build that will be published (v1.5.0, v1.5.0-beta.1)
#   --allow-…        see "THE TWO ALLOW SWITCHES" in check-package.py. Each
#                    skips ONE guard (the guide; the search for secrets), is
#                    refused with --tag, and adds NOT-FOR-RELEASE to the
#                    zip's name.
#
# THE ONE RULE THAT MATTERS MOST: the package is made from GIT, at the commit
# named, with `git archive`. It is never a copy of the working folder. On
# 13 September 2026 a real database login sat in a working folder on a
# development machine; a copy of that folder would have published it. A file
# git does not hold at that commit cannot get in this way.
#
# NO ZIP REACHES THE OUTPUT FOLDER UNLESS IT PASSED. check-package.py runs on
# the folder before the zip is made, and again on the zip. The zip is made in
# a temporary folder and is moved into the output folder only after it has
# passed and its checksum file is written. (Until 12 October 2026 it was made
# in the output folder and deleted on failure, and a failure between the two
# left a zip behind that nobody had checked.) No switch skips the two runs of
# the checker; the two --allow switches each skip one guard inside it, and a
# build that used one is never fit to publish.
#
# A run of the checker counts as passed only when its exit code is 0 AND its
# last line is a PASS line saying it reached its end. An empty or crashed
# checker gives exit code 0 or nothing at all, and must not read as a pass.
#
# The last line of a good run says "reached the end". A run that stops early
# says "BUILD FAILED" instead, so a crash cannot be mistaken for a pass. That
# holds for a run ended by a signal too (TERM, INT, HUP: a cancelled job, a
# Ctrl-C, a closed terminal). It CANNOT hold for KILL, which nothing can
# answer: a killed run prints nothing more and may leave its checksum file or
# a ".part" file behind. Even then no zip that was not checked is left under
# its final name, because the zip only gets that name after both checks.
#
# WHAT THIS CANNOT DO: it does not publish anything, create a tag, or decide
# which channel a commit belongs to (the caller says). And until package F3
# of the roll-out lands, the portal's code only works from a web folder
# called public_html, so a package built today cannot be installed yet. See
# README.md beside this script.
# =============================================================================
set -euo pipefail

# -----------------------------------------------------------------------------
# Settings
# -----------------------------------------------------------------------------

# The staff web folder's name INSIDE THE PACKAGE. The owner decided on
# 12 October 2026 that it is admin_html (decision 3 of the roll-out plan):
# when the public door (#493) arrives it takes the name public_html, and
# customers who had installed the staff pages under that name would each have
# had to re-point their address. The repository's folder keeps its own name.
# check-package.py reads this line, so there is no second copy to change.
# Letters, digits and underscores only.
STAFF_WEB_FOLDER="admin_html"

# The same folder's name in the repository.
REPO_WEB_FOLDER="public_html"

# Left out of the package. Three are placeholders nothing deploys (the owner
# chose on 14 September 2026 to keep them in the repository and leave them
# out here); two hold nothing but a placeholder file, and a real file in
# either stops the build (step 2). check-package.py has its own copy of these
# lists ON PURPOSE: if the two disagree, the check fails.
REMOVED_PLACEHOLDER_FOLDERS=(private_html public_html_landing public_html_redir)
REMOVED_EMPTY_FOLDERS=(_includes _functions)

PRODUCT="WebMS Intra"
# Always the generic product name: the brand is chosen at install time (#296).
ZIP_PREFIX="webms-intra-"

# -----------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
WORK=""
OUT=""
ZIP_NAME=""

stop() {
    echo "ERROR: $*" >&2
    exit 1
}

finish() {
    local code=$?
    # mktemp made this folder; ":?" stops the command if the name were empty.
    [[ -n "$WORK" ]] && rm -rf "${WORK:?}"
    if [[ "$code" -ne 0 ]]; then
        # Whatever stopped the run (KILL apart, see the top), no zip of this
        # run's may stay behind. The output folder was empty when the run
        # began, so these three names can only be this run's own.
        if [[ -n "$OUT" && -n "$ZIP_NAME" ]]; then
            rm -f -- "$OUT/$ZIP_NAME" "$OUT/$ZIP_NAME.part" "$OUT/$ZIP_NAME.sha256"
        fi
        echo "BUILD FAILED — stopped before the end (exit code $code). Nothing from this run may be used as a package." >&2
    fi
}
trap finish EXIT
# A signal needs lines of its own. Left to itself, bash ends on TERM, INT or
# HUP and hands the EXIT trap the code of the last command that FINISHED,
# which is 0: until 12 October 2026 a build cancelled just after its checksum
# was moved left that checksum behind and never said BUILD FAILED. These turn
# each signal into an ordinary failing exit, so finish() cleans up and says so.
# The codes are the usual ones (128 + the signal's number). Bash acts on a
# signal only once the program it is waiting for has ended, so a cancelled
# build stops at the end of the step it is in, not in the middle of it.
trap 'exit 143' TERM
trap 'exit 130' INT
trap 'exit 129' HUP

TAG=""
ALLOW=()
POSITIONAL=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        --tag) TAG="${2:?--tag needs a tag name}"; shift 2 ;;
        --allow-missing-guide | --allow-no-gitleaks) ALLOW+=("$1"); shift ;;
        -*) stop "unknown option '$1' (usage is at the top of this script)" ;;
        *) POSITIONAL+=("$1"); shift ;;
    esac
done
[[ "${#POSITIONAL[@]}" -eq 3 ]] || stop "usage: build.sh <commit> <channel word> <output folder> [--tag <tag>] [--allow-missing-guide] [--allow-no-gitleaks]"
CHANNEL="${POSITIONAL[1]}"
OUT="${POSITIONAL[2]}"

for PROGRAM in git tar zip curl unzip shasum openssl php python3; do
    command -v "$PROGRAM" > /dev/null || stop "this build needs '$PROGRAM', which is not installed"
done

case "$CHANNEL" in
    live | beta | alpha) ;;
    *) stop "the channel word must be live, beta or alpha (got '$CHANNEL')" ;;
esac

# check-package.py refuses this too. It is repeated here only so the refusal
# comes before several minutes of downloading, not after.
if [[ -n "$TAG" && "${#ALLOW[@]}" -gt 0 ]]; then
    stop "a build with --tag may be published, so no --allow-… switch is accepted with it"
fi

COMMIT="$(git -C "$REPO_ROOT" rev-parse --verify "${POSITIONAL[0]}^{commit}")" || stop "'${POSITIONAL[0]}' is not a commit in this repository"
SHORT="$(git -C "$REPO_ROOT" rev-parse --short "$COMMIT")"

# The guide is written by really installing the portal, so it does not exist
# until that can be done. A package without it is useless to a customer, so
# its absence stops the build unless the caller says, in so many words, that
# this build is not for release.
HAVE_GUIDE=1
if ! git -C "$REPO_ROOT" cat-file -e "$COMMIT:docs/INSTALL.md" 2> /dev/null; then
    HAVE_GUIDE=0
    case " ${ALLOW[*]-} " in
        *" --allow-missing-guide "*) ;;
        *) stop "docs/INSTALL.md does not exist at $SHORT. A package without the installation guide must not be released. To build one only to prove the build, add --allow-missing-guide" ;;
    esac
fi

# This script deletes nothing it did not make, so it will not build into a
# folder that already holds something.
if [[ -e "$OUT" ]] && [[ -n "$(ls -A "$OUT")" ]]; then
    stop "the output folder '$OUT' is not empty. Give a new folder; this script never clears one"
fi
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
STAGE="$OUT/stage"
WORK="$(mktemp -d -t package.XXXXXX)"

CHECK_ARGS=(--commit "$COMMIT" --channel "$CHANNEL" --repo "$REPO_ROOT")
[[ -n "$TAG" ]] && CHECK_ARGS+=(--tag "$TAG")
# Written this way because old versions of bash (the one macOS ships) treat
# an empty list as an error.
[[ "${#ALLOW[@]}" -gt 0 ]] && CHECK_ARGS+=("${ALLOW[@]}")

# Runs check-package.py on a folder or a zip ($1), keeping what it printed in
# $2. This is the ONE place that decides whether the checker passed, and it
# needs both things: exit code 0, and a last line that is a PASS line saying
# the run reached its end. On 12 October 2026 an emptied check-package.py
# (python3 exits 0 for an empty file) made the build say "passed every
# check": the exit code alone is not enough.
checker_passed() {
    local code=0 last
    python3 "$SCRIPT_DIR/check-package.py" "$1" "${CHECK_ARGS[@]}" > "$2" 2>&1 || code=$?
    cat "$2"
    if [[ "$code" -ne 0 ]]; then
        return 1
    fi
    last="$(tail -n 1 "$2")"
    if [[ "$last" != "PASS"*"the run reached its end." ]]; then
        echo "ERROR: check-package.py ended with exit code 0 but its last line is not a PASS line that says the run reached its end. That is a checker that did not really run, so this counts as a failure." >&2
        return 1
    fi
    return 0
}

echo "== 1. Taking web/ from git at $SHORT (never from the working folder)"
mkdir "$STAGE"
git -C "$REPO_ROOT" archive --format=tar "$COMMIT" web | tar -x -C "$STAGE" --strip-components=1

# An early trip-wire, before anything is removed or added: what was unpacked
# must be exactly git's list. It catches a git setting that leaves files out
# of an archive (an "export-ignore" attribute), which would otherwise only
# show up later as a puzzling "missing file". check-package.py makes the
# thorough comparison afterwards, byte for byte.
git -C "$REPO_ROOT" ls-tree -r -z --name-only "$COMMIT" -- web/ | tr '\0' '\n' | sed 's#^web/##' | LC_ALL=C sort > "$WORK/from-git.txt"
(cd "$STAGE" && find . -type f -print | sed 's#^\./##' | LC_ALL=C sort) > "$WORK/unpacked.txt"
if ! diff "$WORK/from-git.txt" "$WORK/unpacked.txt" > "$WORK/difference.txt"; then
    cat "$WORK/difference.txt" >&2
    stop "what was unpacked is not git's list of web/ at $SHORT (lines with '<' are missing, '>' are extra)"
fi
echo "   $(wc -l < "$WORK/unpacked.txt" | tr -d ' ') files, the same list git holds"

echo "== 2. Leaving out what is not part of an installation"
# A real file in one of the two "empty" folders would vanish from every
# installation without anybody having decided that. Stop here, before the
# downloads. check-package.py makes the same test from git's own list.
for NAME in "${REMOVED_EMPTY_FOLDERS[@]}"; do
    if [[ -d "$STAGE/$NAME" ]] && [[ -n "$(find "$STAGE/$NAME" -type f ! -name .gitkeep -print)" ]]; then
        find "$STAGE/$NAME" -type f ! -name .gitkeep -print >&2
        stop "web/$NAME holds a real file at $SHORT, and the package leaves that folder out. Move the file, or take $NAME out of REMOVED_EMPTY_FOLDERS here and out of REMOVED_EMPTY_FOLDERS in check-package.py"
    fi
done
for NAME in "${REMOVED_PLACEHOLDER_FOLDERS[@]}" "${REMOVED_EMPTY_FOLDERS[@]}"; do
    if [[ -d "$STAGE/$NAME" ]]; then
        (cd "$STAGE" && find "$NAME" -type f -print | sed 's#^#   left out: web/#')
    fi
    rm -rf "${STAGE:?}/${NAME:?}"
done
find "$STAGE" -type f -name .gitkeep -delete
if [[ "$STAFF_WEB_FOLDER" != "$REPO_WEB_FOLDER" ]]; then
    mv "$STAGE/$REPO_WEB_FOLDER" "$STAGE/$STAFF_WEB_FOLDER"
fi
echo "   the staff web folder is called $STAFF_WEB_FOLDER in this package"

echo "== 3. Adding dompdf (the PDF library), checked against its pinned checksum"
bash "$REPO_ROOT/tools/download-dompdf.sh" "$STAGE/_libraries/dompdf"
# _libraries/ is the one folder the build creates, so git supplies no
# "refuse everything" file for it. The wording of that file has one home, the
# copy in _core/, which package F3 of the roll-out adds. Until it exists there
# is nothing to copy and no wording is invented here. It matters only when a
# hosting panel is pointed at the wrong folder: _libraries/ is outside the
# web folder.
if [[ -f "$STAGE/_core/.htaccess" ]]; then
    cp "$STAGE/_core/.htaccess" "$STAGE/_libraries/.htaccess"
else
    echo "   note: no _core/.htaccess at this commit, so _libraries/ gets no 'refuse everything' file yet"
fi

echo "== 4. Adding the fallback copies of the outside libraries, each checked against its fingerprint"
bash "$REPO_ROOT/tools/download-cdn-fallbacks.sh" --asset-file "$STAGE/_core/Asset.php" --webroot "$STAGE/$STAFF_WEB_FOLDER"

echo "== 5. Adding the package's own files"
# No PHP is run to read the version: the file is one "return '1.4.0';" line.
VERSION="$(sed -n "s/^return '\([^']*\)';.*/\1/p" "$STAGE/_core/version.php")"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || stop "could not read a version like 1.4.0 out of _core/version.php (got '$VERSION')"
# A tagged pre-release carries its suffix in the package's version (1.5.0-beta.1).
PACKAGE_VERSION="$VERSION"
[[ -n "$TAG" ]] && PACKAGE_VERSION="${TAG#v}"

printf '%s\n' "$CHANNEL" > "$STAGE/.channel"
# Plain text, five "Key: value" lines. "Which package do you have?" is the
# first question support asks, and the installer reads the Channel line.
{
    echo "Product: $PRODUCT"
    echo "Version: $PACKAGE_VERSION"
    echo "Channel: $CHANNEL"
    echo "Commit: $COMMIT"
    echo "Built: $(date -u '+%Y-%m-%dT%H:%M:%SZ')"
} > "$STAGE/VERSION.txt"
# From git at the commit, like everything else; the portal's release-notes
# page reads it.
git -C "$REPO_ROOT" show "$COMMIT:CHANGELOG.md" > "$STAGE/CHANGELOG.md"
if [[ "$HAVE_GUIDE" -eq 1 ]]; then
    git -C "$REPO_ROOT" show "$COMMIT:docs/INSTALL.md" > "$STAGE/INSTALL.md"
else
    echo "   NOTE: no installation guide exists at this commit. This package is NOT FOR RELEASE."
fi

echo "== 6. Setting the same permission bits whoever builds"
# What a customer's "extract" gives each file comes from the bits stored in
# the zip, and those come from this folder. Left alone they depend on who
# builds: a user whose default lets the group write (umask 0002, GitHub's
# runner) stored every file group-writable. Files rw-r--r--, folders
# rwxr-xr-x; nothing in a package needs to be run as a program.
find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f -exec chmod 644 {} +

echo "== 7. Checking the folder (no zip is made unless this passes)"
if ! checker_passed "$STAGE" "$WORK/check-folder.txt"; then
    stop "the package folder failed its checks, so NO zip was made. The folder is left at $STAGE for you to look at"
fi

echo "== 8. Making the zip, in a temporary folder"
if [[ -n "$TAG" ]]; then
    ZIP_NAME="${ZIP_PREFIX}${PACKAGE_VERSION}.zip"
else
    ZIP_NAME="${ZIP_PREFIX}${PACKAGE_VERSION}-${CHANNEL}-${SHORT}"
    # So that a zip passed from hand to hand still says what it is.
    [[ "${#ALLOW[@]}" -gt 0 ]] && ZIP_NAME="${ZIP_NAME}-NOT-FOR-RELEASE"
    ZIP_NAME="${ZIP_NAME}.zip"
fi
mkdir "$WORK/zip"
# No wrapper folder: the zip's top level IS the portal's folder, so "extract
# here" is the same act for a first install and for an upgrade. With a wrapper
# (webms-intra-1.5.0/…) an upgrade would land BESIDE the old version, change
# nothing, and look entirely normal. -X leaves out the builder's user and
# group numbers.
(cd "$STAGE" && zip -X -q -r "$WORK/zip/$ZIP_NAME" .)
# The form `sha256sum -c` reads, naming the zip without a folder, so a
# customer can check a download from wherever they saved it.
(cd "$WORK/zip" && shasum -a 256 "$ZIP_NAME" > "$ZIP_NAME.sha256")

echo "== 9. Checking the zip itself (it never reaches the output folder if this fails)"
if ! checker_passed "$WORK/zip/$ZIP_NAME" "$WORK/check-zip.txt"; then
    stop "the zip failed its checks and was never put in the output folder. The folder is left at $STAGE for you to look at"
fi

echo "== 10. Moving the checked zip and its checksum into the output folder"
# rw-r--r-- on both, whoever builds. Until 12 October 2026 they took the
# builder's own default: rw-rw-r-- on GitHub's runner, rw-rw-rw- under a
# umask of 000. What is INSIDE the zip was already right; this is the zip
# file itself, which is what gets attached to a release.
chmod 644 "$WORK/zip/$ZIP_NAME" "$WORK/zip/$ZIP_NAME.sha256"
# The checksum first, and the zip under a ".part" name until it is whole, so
# that a zip called $ZIP_NAME exists in the output folder only once it is
# complete and has its checksum beside it. Then the copy is checked once more
# against that checksum. If any line here fails, the trap at the top removes
# all three names.
mv "$WORK/zip/$ZIP_NAME.sha256" "$OUT/$ZIP_NAME.sha256"
mv "$WORK/zip/$ZIP_NAME" "$OUT/$ZIP_NAME.part"
mv "$OUT/$ZIP_NAME.part" "$OUT/$ZIP_NAME"
(cd "$OUT" && shasum -a 256 -c "$ZIP_NAME.sha256" > "$WORK/moved.txt" 2>&1) || stop "the zip in the output folder does not match its checksum after the move: $(cat "$WORK/moved.txt")"
[[ -z "$(find "$OUT" -maxdepth 1 -type f -name "$ZIP_NAME*" ! -perm 644 -print)" ]] || stop "the zip or its checksum in the output folder is not rw-r--r-- after the move"

echo "   package:  $OUT/$ZIP_NAME"
echo "   checksum: $(cut -d' ' -f1 "$OUT/$ZIP_NAME.sha256")"
if [[ "${#ALLOW[@]}" -gt 0 ]]; then
    echo "BUILD COMPLETE, BUT NOT FIT TO PUBLISH (built with ${ALLOW[*]}) — reached the end: $ZIP_NAME"
else
    echo "BUILD COMPLETE — the folder and the zip both passed every check; reached the end: $ZIP_NAME"
fi
