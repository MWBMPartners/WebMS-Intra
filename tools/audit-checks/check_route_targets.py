#!/usr/bin/env python3
"""
Route target-file existence check.

Parses every `INSERT INTO tblRoutes (...) VALUES ('routeKey', 'targetFile', ...)`
statement across the SQL files (full_schema.sql + numbered migrations) and
verifies that each targetFile resolves to an actual file under
web/_apps/ (#159 moved app controllers out of public_html for defence-in-depth).

Catches the #202 / #205 class of bug (route registered but file missing,
or route pointing at a path the front controller can't reach).

Exit code:
  0 — no findings (CI-green)
  1 — at least one missing target (CI annotates but doesn't block merge
      unless invoked with --strict)

Usage:
  python3 tools/audit-checks/check_route_targets.py [--strict]
"""

from __future__ import annotations

import os
import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
SQL_DIR = REPO_ROOT / "web" / "_sql"
APPS_DIR = REPO_ROOT / "web" / "_apps"
# Public fallback dir — small set of entry-point pages legitimately live
# in the webroot (Swagger UI, openapi.json, PWA offline fallback). Router
# checks _apps/ first then falls back to public_html/.
PUBLIC_DIR = REPO_ROOT / "web" / "public_html"

# Match: INSERT INTO `tblRoutes` (..) VALUES ('key', 'target', ...)
# Note: tolerate backticks, varying whitespace, multi-line VALUES.
INSERT_RE = re.compile(
    r"INSERT\s+INTO\s+`?tblRoutes`?\s*\([^)]+\)\s*VALUES\s*\(\s*"
    r"'([^']+)'\s*,\s*'([^']+)'",
    re.IGNORECASE | re.DOTALL,
)

# Match: VALUES ('key1', 'target1', ...), ('key2', 'target2', ...)
# For the batch-INSERT form used in some migrations.
BATCH_TUPLE_RE = re.compile(r"\(\s*'([^']+)'\s*,\s*'([^']+)'")

# Routes EXCLUDED from the check — known intentional special cases.
EXCLUDED_ROUTES: set[str] = {
    # API routes are special-cased to ApiRouter::dispatch() — the
    # targetFile in tblRoutes isn't used for them. (See #204 for the
    # cleanup of the 5 spurious ones; this skip protects future
    # api/* registrations that genuinely use the special case.)
}

# Path EXCLUSIONS — targets we know don't resolve to files under
# _apps/ but are still valid (handled by the Router special
# case or via a proxy).
EXCLUDED_TARGET_PREFIXES: tuple[str, ...] = (
    "../",  # any escape-out target is its own bug class — we already
            # flag those, but don't double-report from this check.
)


def collect_route_inserts() -> list[tuple[str, str, Path, int]]:
    """Return (routeKey, targetFile, sql_file, line_no) tuples."""
    findings: list[tuple[str, str, Path, int]] = []
    for sql in sorted(SQL_DIR.glob("*.sql")):
        text = sql.read_text(encoding="utf-8", errors="ignore")
        for m in INSERT_RE.finditer(text):
            line_no = text[: m.start()].count("\n") + 1
            # The first match captures (key, first_value) — but for
            # batch inserts the second tuple onwards is in the same
            # statement. Pick up additional tuples too.
            findings.append((m.group(1), m.group(2), sql, line_no))
            # Look ahead for additional tuples in the same VALUES batch.
            tail = text[m.end() :]
            # Stop at the trailing semicolon for this statement.
            stop = tail.find(";")
            batch = tail[:stop] if stop >= 0 else tail
            for tup in BATCH_TUPLE_RE.finditer(batch):
                findings.append((tup.group(1), tup.group(2), sql, line_no))
    return findings


def check() -> int:
    inserts = collect_route_inserts()
    if not inserts:
        print("No tblRoutes INSERTs found — nothing to check.")
        return 0

    # 🪞 "Last write wins" model: ON DUPLICATE KEY UPDATE (used by every
    #    tblRoutes INSERT in this codebase) means the most recently
    #    encountered targetFile for a routeKey is the one that ends up
    #    in the live table. Migrations apply in filename order, then
    #    full_schema.sql runs once for fresh installs — so iteration
    #    in SQL-file sort order gives the final state.
    #
    # We also note "rewrites" (routeKey whose target changed across
    # files) as informational diff — not flagged as a bug because the
    # last write is the intended truth. DELETE statements in later
    # migrations remove a routeKey entirely; for those we'd want
    # to track unsets, but the current schema only DELETEs via
    # one-off migrations whose presence we trust.
    final: dict[str, tuple[str, Path, int]] = {}
    delete_re = re.compile(
        r"DELETE\s+FROM\s+`?tblRoutes`?\s+WHERE\s+`?routeKey`?\s+(?:=\s*'([^']+)'|IN\s*\(([^)]+)\))",
        re.IGNORECASE | re.DOTALL,
    )

    # First, accumulate INSERT-last-wins.
    for route_key, target, sql, line_no in inserts:
        if route_key in EXCLUDED_ROUTES:
            continue
        final[route_key] = (target, sql, line_no)

    # Then apply DELETE statements in order (file sort order).
    for sql in sorted(SQL_DIR.glob("*.sql")):
        text = sql.read_text(encoding="utf-8", errors="ignore")
        for m in delete_re.finditer(text):
            if m.group(1):
                final.pop(m.group(1), None)
            elif m.group(2):
                for k in re.findall(r"'([^']+)'", m.group(2)):
                    final.pop(k, None)

    missing: list[tuple[str, str, Path, int]] = []
    for route_key, (target, sql, line_no) in final.items():
        if target.startswith(EXCLUDED_TARGET_PREFIXES):
            missing.append((route_key, target, sql, line_no))
            continue
        full = APPS_DIR / target
        if full.is_file():
            continue
        # Mirror the Router's _apps → public_html fallback for entry-point
        # pages (Swagger UI, openapi.json, PWA offline fallback).
        if (PUBLIC_DIR / target).is_file():
            continue
        missing.append((route_key, target, sql, line_no))

    print(f"Routes inspected (final state): {len(final)}")
    print(f"Missing target files: {len(missing)}")
    print()

    if missing:
        print("### Routes referencing missing files (final state)\n")
        for route_key, target, sql, line_no in missing:
            rel = sql.relative_to(REPO_ROOT)
            print(f"  • {route_key:40s} → {target:40s}  [last set in {rel}:{line_no}]")
        print()

    strict = "--strict" in sys.argv
    if missing and strict:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(check())
