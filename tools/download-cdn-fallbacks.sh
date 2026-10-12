#!/usr/bin/env bash
# =============================================================================
# WebMS-Intra — Fetch the local fallback copies of the outside libraries
#
# Path: tools/download-cdn-fallbacks.sh
#
# Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
# Proprietary. Unauthorised copying, modification or distribution prohibited.
#
# PURPOSE (#499)
# --------------
# Every page loads Bootstrap and Font Awesome from a public content delivery
# network (CDN), and web/_core/Asset.php writes a local address into each tag
# as the fallback for when the CDN cannot be reached. Until now nothing ever
# put files at those local addresses, so behind a network that blocks public
# CDNs the portal had no styling at all. This script fetches them, so the
# installation package can carry them (owner's decision, 14 September 2026).
#
# Usage:
#   bash tools/download-cdn-fallbacks.sh [--asset-file <Asset.php>] [--webroot <web folder>]
#       Fetch every fallback copy into the web folder. Defaults: this
#       repository's web/_core/Asset.php and web/public_html.
#   bash tools/download-cdn-fallbacks.sh --list [--asset-file <Asset.php>]
#       Print what is expected and stop. No network. One line per file, four
#       columns separated by tabs: name, address under the web folder, pinned
#       fingerprint, where it is downloaded from.
#       tools/package/check-package.py reads this to check a package.
#
# WHERE THE LIST COMES FROM — there is no second list to keep in step
# -------------------------------------------------------------------
# Asset.php names each library three times: CDN_<NAME> (where it is fetched
# from), <NAME>_INTEGRITY (the fingerprint a browser checks it against) and
# LOCAL_<NAME> (the fallback address). This script asks PHP for the constants
# themselves (the way tools/cdn-sri-selftest.php does) and takes every name
# that has all three. Reading the file as text was rejected: the addresses are
# built by joining pieces together, and a pattern over the text gets that wrong.
#
# So a version bump in Asset.php is picked up here with no change. Today that
# gives seven files: four that are fetched (Bootstrap's two stylesheets and
# its script, Font Awesome's stylesheet) and the three Swagger UI files, which
# are already committed and so are only CHECKED.
#
# THE FONT FILES ARE THE ONE EXCEPTION. Font Awesome's stylesheet loads eight
# font files from "../webfonts/". Asset.php has no fingerprint for those,
# because no tag names them. Their SHA-256 checksums are pinned in FONT_PINS
# below. They were worked out on 12 October 2026 and were identical from two
# independent places: cdnjs, and the package Font Awesome publishes on npm.
# The script also reads the stylesheet and fails if it names a font with no
# pin, or a pin names a font the stylesheet no longer uses.
#
# WHEN FONT AWESOME'S VERSION CHANGES in Asset.php the fonts will stop matching
# and this script will fail, naming them. Work the new checksums out from two
# places, as above, and replace FONT_PINS.
#
# A FILE THAT IS ALREADY THERE IS NEVER OVERWRITTEN. If it matches, it is kept.
# If it does not match, the script stops: a committed copy that differs from
# its fingerprint is a fault somebody needs to see, not one to paper over.
#
# WHAT THIS CANNOT PROVE: that the libraries themselves are sound; that a
# browser will accept the files (it works the fingerprint out the same way,
# but is not a browser); or that the CDN will be reachable from wherever this
# runs. An unreachable CDN stops the script, which is the right failure: the
# other outcome is a package that quietly lacks the files.
# =============================================================================
set -euo pipefail

# Font Awesome 6.5.1 font files: "<file name> <SHA-256>", one per line.
FONT_PINS="
fa-brands-400.ttf 5656d596bc597165a42182f67b2b9f17d2ae47a9e3ef1b042b9a729739730705
fa-brands-400.woff2 3a8924cd5203a28628716aedb5cef0943da4c3b44e3ffcee90ab06387b41c490
fa-regular-400.ttf 5d02dc9b858e3c85a794f87e379857f4fedc4e26cf15001714a9a0e0b1d2294d
fa-regular-400.woff2 2bccecf0bc7e96cd5ce4003abeb3ae9ee4a3d19158c4e6edfd2df32d2f0d5721
fa-solid-900.ttf fbbf06d7437aa30f3cd44c968380193545a8fc3eadfb7ad897bbb101eefec5a2
fa-solid-900.woff2 9fc85f3a4544ab0d570c7f8f9bbb88db8d92c359b2707580ea8b07c75673eae2
fa-v4compatibility.ttf 09663a36fc05e7190af8324b855105c5bb511ad94f94b81b34afee503279eca2
fa-v4compatibility.woff2 4d4a2d7fd1c6684845cb174fdd7fc073bd64cb741286fb247f8b76c2b7b852c4
"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
ASSET_FILE="$REPO_ROOT/web/_core/Asset.php"
WEBROOT="$REPO_ROOT/web/public_html"
LIST_ONLY=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --list) LIST_ONLY=1; shift ;;
        --asset-file) ASSET_FILE="${2:?--asset-file needs a path}"; shift 2 ;;
        --webroot) WEBROOT="${2:?--webroot needs a folder}"; shift 2 ;;
        *) echo "ERROR: unknown argument '$1' (see the top of this script for usage)" >&2; exit 1 ;;
    esac
done

if [[ ! -f "$ASSET_FILE" ]]; then
    echo "ERROR: no Asset.php at $ASSET_FILE" >&2
    exit 1
fi

# Prints one line per expected file: name, local address, fingerprint, source.
expected_files() {
    local rows css_local css_url name sum
    # A name with only two of the three constants is reported, not skipped:
    # a fallback address with nothing to check it against is a fault.
    # The "if !" is needed: bash switches "stop on error" off inside $(...),
    # so without it a PHP failure part way through would be carried on from.
    # shellcheck disable=SC2016  # the single quotes are deliberate: this is PHP, and its $names are not bash's
    if ! rows="$(php -r '
        require $argv[1];
        $c = (new ReflectionClass(Portal\Core\Asset::class))->getConstants();
        foreach ($c as $name => $local) {
            if (str_starts_with($name, "LOCAL_") === false) { continue; }
            $base = substr($name, 6);
            $hasUrl  = array_key_exists("CDN_" . $base, $c);
            $hasHash = array_key_exists($base . "_INTEGRITY", $c);
            if ($hasUrl === false && $hasHash === false) { continue; } // our own file, never on a CDN
            if ($hasUrl === false || $hasHash === false) {
                fwrite(STDERR, "ERROR: Asset::LOCAL_{$base} needs both CDN_{$base} and {$base}_INTEGRITY\n");
                exit(1);
            }
            echo $base, "\t", $local, "\t", $c[$base . "_INTEGRITY"], "\t", $c["CDN_" . $base], "\n";
        }
    ' -- "$ASSET_FILE")"; then
        echo "ERROR: could not read the library constants out of $ASSET_FILE" >&2
        return 1
    fi
    if [[ -z "$rows" ]]; then
        echo "ERROR: found no library in $ASSET_FILE with a CDN address, a fingerprint and a local address. The reading itself is broken." >&2
        return 1
    fi
    printf '%s\n' "$rows"

    # The fonts sit beside the stylesheet's own folder, on the CDN and locally.
    css_local="$(printf '%s\n' "$rows" | awk -F'\t' '$1 == "FONTAWESOME_CSS" { print $2 }')"
    css_url="$(printf '%s\n' "$rows" | awk -F'\t' '$1 == "FONTAWESOME_CSS" { print $4 }')"
    if [[ -z "$css_local" || -z "$css_url" ]]; then
        echo "ERROR: FONT_PINS lists Font Awesome fonts, but Asset.php has no FONTAWESOME_CSS library. Remove the pins or restore the library." >&2
        return 1
    fi
    while read -r name sum; do
        [[ -n "$name" ]] || continue
        printf 'FONTAWESOME_FONT\t%s/webfonts/%s\tsha256:%s\t%s/webfonts/%s\n' \
            "${css_local%/css/*}" "$name" "$sum" "${css_url%/css/*}" "$name"
    done <<< "$FONT_PINS"
}

# Does this file match its pinned fingerprint? The pin is either
# "sha256:<hex>" (the fonts) or what a web page carries: "sha384-<base64>",
# possibly several separated by spaces, any one of which may match.
fingerprint_matches() {
    local file="$1" pinned="$2" one algo actual
    for one in $pinned; do
        case "$one" in
            sha256:*) actual="sha256:$(shasum -a 256 "$file" | cut -d' ' -f1)" ;;
            sha256-* | sha384-* | sha512-*)
                algo="${one%%-*}"
                actual="$algo-$(openssl dgst "-$algo" -binary "$file" | openssl base64 -A)"
                ;;
            *) echo "ERROR: cannot read the fingerprint '$one'" >&2; return 1 ;;
        esac
        [[ "$actual" == "$one" ]] && return 0
    done
    return 1
}

ROWS="$(expected_files)"

if [[ "$LIST_ONLY" -eq 1 ]]; then
    printf '%s\n' "$ROWS"
    exit 0
fi

if [[ ! -d "$WEBROOT" ]]; then
    echo "ERROR: the web folder $WEBROOT does not exist" >&2
    exit 1
fi

TMP_DIR="$(mktemp -d -t cdnfallback.XXXXXX)"
trap 'rm -rf "${TMP_DIR:?}"' EXIT

KEPT=0
FETCHED=0
CSS_FILE=""
while IFS=$'\t' read -r NAME LOCAL PINNED URL; do
    DEST="$WEBROOT$LOCAL"
    [[ "$NAME" == "FONTAWESOME_CSS" ]] && CSS_FILE="$DEST"
    if [[ -e "$DEST" ]]; then
        if ! fingerprint_matches "$DEST" "$PINNED"; then
            echo "ERROR: $DEST is already there but does NOT match its fingerprint ($PINNED)." >&2
            echo "  It was not overwritten. If it is a stale download, delete it and run this again;" >&2
            echo "  if it is a committed file, the copy or the fingerprint in Asset.php is wrong." >&2
            exit 1
        fi
        KEPT=$((KEPT + 1))
        echo "kept     $LOCAL (already there, matches)"
        continue
    fi
    if ! curl -fsSL --retry 2 --max-time 120 -o "$TMP_DIR/download" "$URL"; then
        echo "ERROR: failed to download $URL" >&2
        exit 1
    fi
    if ! fingerprint_matches "$TMP_DIR/download" "$PINNED"; then
        echo "ERROR: $URL does not match its fingerprint ($PINNED). Nothing was written for it." >&2
        exit 1
    fi
    mkdir -p "$(dirname "$DEST")"
    mv "$TMP_DIR/download" "$DEST"
    FETCHED=$((FETCHED + 1))
    echo "fetched  $LOCAL (matches)"
done <<< "$ROWS"

# The stylesheet and the pins must name the same fonts, in both directions.
# The stylesheet has just been checked against its fingerprint, so it can be
# trusted to say which fonts it loads.
USED="$(grep -o 'url(\.\./webfonts/[^)?#]*' "$CSS_FILE" | sed 's#.*/##' | LC_ALL=C sort -u)"
PINNED_NAMES="$(printf '%s\n' "$FONT_PINS" | awk 'NF { print $1 }' | LC_ALL=C sort -u)"
if [[ "$USED" != "$PINNED_NAMES" ]]; then
    echo "ERROR: Font Awesome's stylesheet and FONT_PINS do not name the same font files." >&2
    echo "  The stylesheet loads: $(echo "$USED" | tr '\n' ' ')" >&2
    echo "  FONT_PINS lists:      $(echo "$PINNED_NAMES" | tr '\n' ' ')" >&2
    exit 1
fi

echo "FALLBACK COPIES COMPLETE — $KEPT kept, $FETCHED fetched, every one matches its fingerprint; reached the end."
