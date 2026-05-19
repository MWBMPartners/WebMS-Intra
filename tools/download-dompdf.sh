#!/usr/bin/env bash
# =============================================================================
# WebMS-Intra — Download pinned dompdf release into web/_libraries/dompdf/
#
# Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
# Proprietary. Unauthorised copying, modification or distribution prohibited.
#
# PURPOSE
# -------
# Fetches a pinned dompdf release tarball from GitHub and extracts it into
# web/_libraries/dompdf/. Used by:
#   - .github/workflows/deploy.yml (CI; downloads before SFTP upload)
#   - local devs who need expense PDF rendering
#
# This script is idempotent: if the right version is already present,
# it exits without re-downloading.
#
# To upgrade dompdf, change DOMPDF_VERSION below and commit.
# Release index: https://github.com/dompdf/dompdf/releases
# =============================================================================
set -euo pipefail

DOMPDF_VERSION="${DOMPDF_VERSION:-v3.1.5}"

# Resolve absolute path of this script's parent dir, then jump to repo root
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
DEST_DIR="$REPO_ROOT/web/_libraries/dompdf"
VERSION_FILE="$DEST_DIR/VERSION.txt"

# -- Skip if already at the right version --
if [[ -f "$VERSION_FILE" ]] && [[ "$(cat "$VERSION_FILE")" == "$DOMPDF_VERSION" ]]; then
    echo "dompdf $DOMPDF_VERSION already present at $DEST_DIR — skipping"
    exit 0
fi

echo "Fetching dompdf $DOMPDF_VERSION into $DEST_DIR"

# -- Clean any previous version --
rm -rf "$DEST_DIR"
mkdir -p "$DEST_DIR"

# -- Fetch + extract the source tarball --
TARBALL_URL="https://github.com/dompdf/dompdf/archive/refs/tags/${DOMPDF_VERSION}.tar.gz"
TMP_TARBALL="$(mktemp -t dompdf.XXXXXX.tar.gz)"

trap 'rm -f "$TMP_TARBALL"' EXIT

if ! curl -fsSL --max-time 120 -o "$TMP_TARBALL" "$TARBALL_URL"; then
    echo "ERROR: failed to download $TARBALL_URL" >&2
    exit 1
fi

# Strip the top-level "dompdf-x.y.z/" directory from the tarball as we extract
tar -xzf "$TMP_TARBALL" --strip-components=1 -C "$DEST_DIR"

# -- Pin marker (used by the idempotency check above) --
echo "$DOMPDF_VERSION" > "$VERSION_FILE"

# -- Sanity check: the loader file we depend on exists --
if [[ ! -f "$DEST_DIR/src/Autoloader.php" ]] && [[ ! -f "$DEST_DIR/lib/Autoloader.php" ]]; then
    echo "WARNING: expected autoloader not found in extracted dompdf — verify the tarball layout for $DOMPDF_VERSION" >&2
fi

echo "dompdf $DOMPDF_VERSION installed at $DEST_DIR"
