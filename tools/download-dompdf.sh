#!/usr/bin/env bash
# =============================================================================
# WebMS-Intra — Download the pinned dompdf release into web/_libraries/dompdf/
#
# Path: tools/download-dompdf.sh
#
# Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
# Proprietary. Unauthorised copying, modification or distribution prohibited.
#
# PURPOSE
# -------
# Fetches the file dompdf itself publishes for one exact release, checks it
# against a pinned checksum, and unpacks it into web/_libraries/dompdf/ (or
# into the folder given as the first argument). Used by:
#   - .github/workflows/deploy.yml (downloads before the upload)
#   - tools/package/build.sh (puts dompdf into the installation package)
#   - local developers who need expense PDFs
#
# Usage:  bash tools/download-dompdf.sh [<destination folder ending in /dompdf>]
#         bash tools/download-dompdf.sh --write-list
#
# It is safe to run twice: if the right version is already complete in the
# destination, it exits without downloading.
#
# --write-list installs nothing. It fetches the pinned file, checks it, and
# writes tools/package/dompdf-files.sha256: one checksum for every file inside
# it. Run it whenever the pin changes, and commit the list with the pin.
#
# WHAT CHANGED ON 12 OCTOBER 2026, AND WHY (#499) — read before "tidying"
# ----------------------------------------------------------------------
# 1. It used to fetch GitHub's automatic archive of the tag's SOURCE
#    (…/archive/refs/tags/v3.1.5.tar.gz). That archive has no
#    `autoload.inc.php` and none of the libraries dompdf itself needs (its
#    `vendor/` folder). `web/_core/Pdf.php` loads exactly
#    `_libraries/dompdf/autoload.inc.php`, so a copy fetched that way could
#    never make a PDF. Proved on 12 October 2026: the source archive failed to
#    load; the published release file rendered a PDF. So this now fetches the
#    release file dompdf publishes (dompdf-3.1.5.zip), which has both.
# 2. The download is now checked against a pinned SHA-256 checksum (a
#    fingerprint of the exact bytes). Before, whatever GitHub served was
#    unpacked and uploaded, unchecked. The installation package turns that file
#    into something we hand to customers, so it is pinned now. The pin matches
#    the checksum GitHub's own API reports for the release file.
# 3. The old check looked for `src/Autoloader.php` or `lib/Autoloader.php` and
#    only WARNED when neither was there. Neither exists in dompdf 3, so it
#    warned on every run and nobody could tell that from a real problem. A
#    missing loader now stops the script (exit 1).
# 4. The version marker (VERSION.txt) is written LAST. It used to be written
#    before the check, so a half-unpacked copy carried the marker and the next
#    run skipped it as "already present".
#
# WHAT CHANGED LATER ON 12 OCTOBER 2026, after an independent check (#499)
# -----------------------------------------------------------------------
# 5. The pin is no longer read from the environment. `DOMPDF_SHA256=… bash
#    tools/download-dompdf.sh` used to accept a different file and report
#    "checksum matched". The version and its checksum are the two lines below
#    and nothing else.
# 6. Every unpacked file is now checked against a committed list
#    (tools/package/dompdf-files.sha256), and a file that is not on the list
#    stops the script. The list names the version and the download's checksum
#    it was made from, so the pin and the list cannot drift apart: change one
#    without the other and this script refuses. The installation package's
#    checker compares the packaged folder with the same list.
# 7. The publisher ships every file writable by everybody (rw-rw-rw-, and
#    folders rwxrwxrwx). On shared hosting that is PHP code another account
#    may be able to rewrite. The bits are now set after unpacking: files
#    rw-r--r--, folders rwxr-xr-x. This covers the deploy as well.
#
# TO UPGRADE dompdf: change DOMPDF_VERSION and DOMPDF_SHA256 together, then
# run this script with --write-list and commit the new list with them.
#   Release index: https://github.com/dompdf/dompdf/releases
#   Get the new checksum with:  shasum -a 256 dompdf-<version>.zip
#   and compare it with the "digest" GitHub's API shows for that file.
#
# IF THE CHECKSUM STOPS MATCHING: do not just paste in the new value. A file
# attached to a release is not supposed to change. Find out why first (was the
# release re-published?), and record the reason here when the pin is changed.
#
# WHAT THIS CANNOT PROVE: that dompdf's release is free of faults or was built
# honestly. The pin only proves we get the same bytes every time. And when the
# right version is "already present" it is NOT read again file by file: only
# the marker and the three required files are looked at.
# =============================================================================
set -euo pipefail

# Plain values on purpose, never "${NAME:-default}": see point 5 above.
# tools/package/check-package.py reads these two lines as they are written.
DOMPDF_VERSION="v3.1.5"
DOMPDF_SHA256="51a24faa8ed258cff2405228b151e54caefcbe9a6e6dbb8ac3e0ba024945ba9c"

# The three files that must exist for the portal to make a PDF: the loader
# Pdf.php asks for, the loader that one hands over to, and dompdf's own main
# class. tools/package/check-package.py checks the same three in a package.
REQUIRED_FILES=(
    "autoload.inc.php"
    "vendor/autoload.php"
    "vendor/dompdf/dompdf/src/Dompdf.php"
)

# Resolve absolute path of this script's parent dir, then jump to repo root
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
FILE_LIST="$SCRIPT_DIR/package/dompdf-files.sha256"
WRITE_LIST=0
if [[ "${1:-}" == "--write-list" ]]; then
    WRITE_LIST=1
    shift
fi
DEST_DIR="${1:-$REPO_ROOT/web/_libraries/dompdf}"
VERSION_FILE="$DEST_DIR/VERSION.txt"

# The destination is DELETED and rebuilt below, so refuse anything that is not
# plainly a dompdf folder. A mistyped argument must not be able to delete some
# other folder.
if [[ "$(basename "$DEST_DIR")" != "dompdf" ]]; then
    echo "ERROR: the destination must be a folder called 'dompdf' (got: $DEST_DIR)" >&2
    exit 1
fi

# Says whether every required file is present and not empty.
dompdf_is_complete() {
    local name
    for name in "${REQUIRED_FILES[@]}"; do
        [[ -s "$1/$name" ]] || return 1
    done
    return 0
}

# -- Skip if the right version is already there AND complete --
# "Complete" matters: a copy left by the old version of this script has the
# same marker but no loader, and must be fetched again.
if [[ "$WRITE_LIST" -eq 0 ]] && [[ -f "$VERSION_FILE" ]] && [[ "$(cat "$VERSION_FILE")" == "$DOMPDF_VERSION" ]] && dompdf_is_complete "$DEST_DIR"; then
    echo "dompdf $DOMPDF_VERSION already present and complete at $DEST_DIR — skipping; reached the end."
    exit 0
fi

if [[ "$WRITE_LIST" -eq 1 ]]; then
    echo "Fetching dompdf $DOMPDF_VERSION to list its files"
else
    echo "Fetching dompdf $DOMPDF_VERSION into $DEST_DIR"
fi

ZIP_URL="https://github.com/dompdf/dompdf/releases/download/${DOMPDF_VERSION}/dompdf-${DOMPDF_VERSION#v}.zip"
TMP_DIR="$(mktemp -d -t dompdf.XXXXXX)"
# mktemp gave us this folder, so removing it is safe; ":?" stops the command
# if the variable were ever empty.
trap 'rm -rf "${TMP_DIR:?}"' EXIT

if ! curl -fsSL --retry 2 --max-time 120 -o "$TMP_DIR/dompdf.zip" "$ZIP_URL"; then
    echo "ERROR: failed to download $ZIP_URL" >&2
    exit 1
fi

# -- Check the bytes BEFORE unpacking anything --
ACTUAL_SHA256="$(shasum -a 256 "$TMP_DIR/dompdf.zip" | cut -d' ' -f1)"
if [[ "$ACTUAL_SHA256" != "$DOMPDF_SHA256" ]]; then
    echo "ERROR: the downloaded dompdf file does not match its pinned checksum." >&2
    echo "  expected: $DOMPDF_SHA256" >&2
    echo "  got:      $ACTUAL_SHA256" >&2
    echo "  Nothing was unpacked. See 'IF THE CHECKSUM STOPS MATCHING' at the top of this script." >&2
    exit 1
fi

# The release file holds one folder, "dompdf/". Unpack beside it, check it,
# and only then replace the destination, so a failure here leaves any earlier
# copy untouched.
unzip -q "$TMP_DIR/dompdf.zip" -d "$TMP_DIR/unpacked"
if ! dompdf_is_complete "$TMP_DIR/unpacked/dompdf"; then
    echo "ERROR: the dompdf release file does not hold the files the portal needs:" >&2
    printf '  %s\n' "${REQUIRED_FILES[@]}" >&2
    echo "  Check the layout of the release file for $DOMPDF_VERSION." >&2
    exit 1
fi

# -- Permission bits (see point 7 at the top) --
# "X" gives the "may open" bit to folders only: no file in the release has it.
chmod -R u=rwX,go=rX "$TMP_DIR/unpacked/dompdf"

# -- Every file, against the committed list (see point 6 at the top) --
(cd "$TMP_DIR/unpacked/dompdf" && find . -type f -print | sed 's#^\./##' | LC_ALL=C sort) > "$TMP_DIR/names.txt"
if [[ "$WRITE_LIST" -eq 1 ]]; then
    {
        echo "# The files inside dompdf's release, with the SHA-256 checksum of each."
        echo "# Written by:  bash tools/download-dompdf.sh --write-list   (never by hand)"
        echo "# Read by:     tools/download-dompdf.sh and tools/package/check-package.py"
        echo "# The two lines below tie this list to the pin in tools/download-dompdf.sh."
        echo "# If either differs from the pin, nothing is installed and no package builds."
        echo "# version: $DOMPDF_VERSION"
        echo "# release-zip-sha256: $DOMPDF_SHA256"
        while IFS= read -r NAME; do
            echo "$(shasum -a 256 "$TMP_DIR/unpacked/dompdf/$NAME" | cut -d' ' -f1)  $NAME"
        done < "$TMP_DIR/names.txt"
    } > "$TMP_DIR/list.txt"
    mv "$TMP_DIR/list.txt" "$FILE_LIST"
    echo "wrote $FILE_LIST ($(wc -l < "$TMP_DIR/names.txt" | tr -d ' ') files) for dompdf $DOMPDF_VERSION; nothing was installed; reached the end."
    exit 0
fi

if [[ ! -f "$FILE_LIST" ]]; then
    echo "ERROR: $FILE_LIST is missing, so dompdf's files cannot be checked one by one. Nothing was installed." >&2
    exit 1
fi
if ! grep -qx "# version: $DOMPDF_VERSION" "$FILE_LIST" || ! grep -qx "# release-zip-sha256: $DOMPDF_SHA256" "$FILE_LIST"; then
    echo "ERROR: $FILE_LIST was not made from the download pinned in this script" >&2
    echo "  ($DOMPDF_VERSION, $DOMPDF_SHA256). Nothing was installed." >&2
    echo "  The pin and the list change together: run  bash tools/download-dompdf.sh --write-list  and commit both." >&2
    exit 1
fi
grep -v '^#' "$FILE_LIST" > "$TMP_DIR/list.txt"
sed 's/^[0-9a-f]*  //' "$TMP_DIR/list.txt" | LC_ALL=C sort > "$TMP_DIR/listed.txt"
if ! diff "$TMP_DIR/listed.txt" "$TMP_DIR/names.txt" > "$TMP_DIR/difference.txt"; then
    echo "ERROR: the unpacked dompdf does not hold exactly the files on $FILE_LIST" >&2
    echo "  (lines with '<' are missing, '>' are extra). Nothing was installed." >&2
    cat "$TMP_DIR/difference.txt" >&2
    exit 1
fi
if ! (cd "$TMP_DIR/unpacked/dompdf" && shasum -a 256 -c "$TMP_DIR/list.txt" > "$TMP_DIR/verify.txt" 2>&1); then
    echo "ERROR: a file inside dompdf does not match its checksum on $FILE_LIST. Nothing was installed." >&2
    grep -v ': OK$' "$TMP_DIR/verify.txt" >&2
    exit 1
fi

rm -rf "${DEST_DIR:?}"
mkdir -p "$(dirname "$DEST_DIR")"
mv "$TMP_DIR/unpacked/dompdf" "$DEST_DIR"

# -- Version marker, written last (see point 4 at the top) --
echo "$DOMPDF_VERSION" > "$VERSION_FILE"
# The same bits as the files beside it, whatever this user's defaults are.
chmod 644 "$VERSION_FILE"

# The last line says "reached the end" on purpose: anything that calls this
# script can look for it, so a run that stopped early is never taken for a
# finished one.
echo "dompdf $DOMPDF_VERSION installed at $DEST_DIR (the download and every file in it matched their checksums); reached the end."
