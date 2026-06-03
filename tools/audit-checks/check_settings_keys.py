#!/usr/bin/env python3
"""
Settings key consistency check.

Builds the set of seeded `tblSettings.settingKey` values across
full_schema.sql + numbered migrations. Then scans PHP for
`$SETTINGS['x']['y'][...]` and `App::settings()['x']['y'][...]` reads,
derives the implied dot-notation key, and verifies the key is in the
seeded set.

Distinguishes guarded reads (`?? 'default'`) from unguarded reads —
unguarded reads on missing keys are higher severity (silent null).

Catches the #203 / #208 class of bug — though both of those turned out
to be false positives because the previous audit didn't walk the full
schema. This check walks every INSERT into tblSettings across all SQL
files, so it should be more reliable.

Exit code:
  0 — no findings
  1 — at least one unguarded read of an unseeded key (CI annotates;
      blocks if --strict)

Usage:
  python3 tools/audit-checks/check_settings_keys.py [--strict]
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
SQL_DIR = REPO_ROOT / "web" / "_sql"
PHP_ROOTS = [
    REPO_ROOT / "web" / "_install",
    REPO_ROOT / "web" / "_core",
    REPO_ROOT / "web" / "_apps",
    REPO_ROOT / "web" / "public_html",
]

# Match: INSERT INTO `tblSettings` (...settingKey…) VALUES (..., 'key', ...)
# Handles both column-orderings used in this codebase:
#   (settingKey, settingValue, isSensitive, defaultValue) VALUES ('k', ...)
#   (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'k', ...)
INSERT_RE = re.compile(
    r"INSERT\s+INTO\s+`?tblSettings`?\s*\(([^)]+)\)\s*VALUES",
    re.IGNORECASE,
)
# Per-row pattern inside a VALUES batch: find the literal string in the
# position of settingKey based on the column order.
VALUES_TUPLE_RE = re.compile(r"\(([^)]*)\)", re.DOTALL)

# Match $SETTINGS read paths — at least two array dereferences.
SETTINGS_READ_RE = re.compile(
    r"\$SETTINGS(\[(?:'[^']+'|\"[^\"]+\")\]){2,}"
)
APP_SETTINGS_RE = re.compile(
    r"App::settings\(\)(\[(?:'[^']+'|\"[^\"]+\")\]){2,}"
)
# Capture each ['x'] segment for the path-extraction.
SEG_RE = re.compile(r"\[(?:'([^']+)'|\"([^\"]+)\")\]")


def collect_seeded_keys() -> set[str]:
    """Parse every INSERT INTO tblSettings and collect settingKey values."""
    keys: set[str] = set()
    for sql in sorted(SQL_DIR.glob("*.sql")):
        text = sql.read_text(encoding="utf-8", errors="ignore")
        for m in INSERT_RE.finditer(text):
            cols = [c.strip().strip("`") for c in m.group(1).split(",")]
            try:
                key_idx = cols.index("settingKey")
            except ValueError:
                continue
            # Slice from the end of the matched INSERT to the trailing ;
            tail = text[m.end() :]
            stop = tail.find(";")
            batch = tail[:stop] if stop >= 0 else tail
            for tup in VALUES_TUPLE_RE.finditer(batch):
                # Split tuple values respecting quotes — simple split
                # by comma is OK because we don't allow commas in our
                # settingKey values.
                vals = [v.strip() for v in tup.group(1).split(",")]
                if key_idx >= len(vals):
                    continue
                raw = vals[key_idx]
                # Strip surrounding quotes.
                if (raw.startswith("'") and raw.endswith("'")) or (
                    raw.startswith('"') and raw.endswith('"')
                ):
                    keys.add(raw[1:-1])
    return keys


def derive_key(segments: list[str]) -> str:
    return ".".join(segments)


def strip_php_comments(text: str) -> str:
    """Strip PHP comments while preserving line numbers (replace block
    comments with the same number of newlines so reported line numbers
    still match the original file)."""
    text = re.sub(
        r"/\*.*?\*/",
        lambda m: "\n" * m.group(0).count("\n"),
        text,
        flags=re.DOTALL,
    )
    text = re.sub(r"//[^\n]*", "", text)
    return text


def scan_php_reads(seeded: set[str]) -> list[tuple[Path, int, str, bool]]:
    """Return (file, line_no, key, is_guarded) findings."""
    findings: list[tuple[Path, int, str, bool]] = []
    for root in PHP_ROOTS:
        if not root.exists():
            continue
        for php in root.rglob("*.php"):
            try:
                raw = php.read_text(encoding="utf-8", errors="ignore")
            except OSError:
                continue
            text = strip_php_comments(raw)
            for pattern in (SETTINGS_READ_RE, APP_SETTINGS_RE):
                for m in pattern.finditer(text):
                    full = m.group(0)
                    segs: list[str] = []
                    for s in SEG_RE.finditer(full):
                        segs.append(s.group(1) or s.group(2))
                    key = derive_key(segs)
                    if key in seeded:
                        continue
                    line_no = text[: m.start()].count("\n") + 1
                    # Heuristic for guarded reads — check whether the
                    # surrounding 80 chars contains `?? `.
                    window = text[max(0, m.start() - 5) : min(len(text), m.end() + 80)]
                    guarded = "??" in window
                    findings.append((php, line_no, key, guarded))
    return findings


def check() -> int:
    seeded = collect_seeded_keys()
    print(f"Seeded settings keys: {len(seeded)}")
    findings = scan_php_reads(seeded)
    # Dedupe.
    deduped: dict[tuple[str, int, str], bool] = {}
    for php, line_no, key, guarded in findings:
        rel = str(php.relative_to(REPO_ROOT))
        deduped[(rel, line_no, key)] = guarded
    unguarded = [(p, ln, k) for (p, ln, k), g in deduped.items() if not g]
    guarded = [(p, ln, k) for (p, ln, k), g in deduped.items() if g]

    print(f"Unseeded reads (unguarded — HIGHER risk): {len(unguarded)}")
    print(f"Unseeded reads (guarded with ?? — lower risk): {len(guarded)}")
    print()
    if unguarded:
        print("### Unseeded settings reads — UNGUARDED\n")
        for rel, line_no, key in unguarded:
            print(f"  • {rel}:{line_no} — reads `{key}` with no fallback")
        print()
    if guarded:
        print("### Unseeded settings reads — guarded (informational)\n")
        for rel, line_no, key in guarded[:30]:  # cap at 30 for noise
            print(f"  • {rel}:{line_no} — reads `{key}` (has ?? fallback)")
        if len(guarded) > 30:
            print(f"  ... and {len(guarded) - 30} more")
    strict = "--strict" in sys.argv
    if unguarded and strict:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(check())
