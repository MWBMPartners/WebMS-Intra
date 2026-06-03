#!/usr/bin/env python3
"""
Migration idempotency static-analysis check.

Walks every web/_sql/NNN_*.sql migration and flags statements that aren't
safely re-runnable:

  • CREATE TABLE without IF NOT EXISTS         → second run errors
  • ALTER TABLE … ADD COLUMN without IF NOT EXISTS  → second run errors
  • INSERT INTO without ON DUPLICATE KEY UPDATE      → second run risks
        duplicate-key errors OR (worse) row dupes
  • CREATE INDEX without IF NOT EXISTS         → second run errors

The full e2e harness (tools/e2e-migrations/run.sh) re-runs every migration
twice on a real MySQL 8.0 docker to catch what static analysis misses;
this script is the fast-feedback first pass that runs in every CI build.

Exit:
  0 — clean.
  1 — at least one non-idempotent statement (CI gating optional via --strict).

Usage:
  python3 tools/audit-checks/check_migration_idempotency.py [--strict]
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
SQL_DIR = REPO_ROOT / "web" / "_sql"

# Statements we require idempotency on. Each entry: (regex, what's_required).
CHECKS = [
    (
        re.compile(r"\bCREATE\s+TABLE\b(?!\s+IF\s+NOT\s+EXISTS)", re.IGNORECASE),
        "CREATE TABLE missing IF NOT EXISTS",
    ),
    (
        re.compile(r"\bALTER\s+TABLE\b[^;]*\bADD\s+COLUMN\b(?!\s+IF\s+NOT\s+EXISTS)", re.IGNORECASE),
        "ADD COLUMN missing IF NOT EXISTS (requires MySQL 8.0.29+)",
    ),
    (
        re.compile(r"\bCREATE\s+INDEX\b(?!\s+IF\s+NOT\s+EXISTS)", re.IGNORECASE),
        "CREATE INDEX missing IF NOT EXISTS",
    ),
]

# INSERT requires either ON DUPLICATE KEY UPDATE or INSERT IGNORE for the
# row to survive a re-run. We check this separately so we can give a
# clearer message.
INSERT_RE = re.compile(r"\bINSERT\s+INTO\b", re.IGNORECASE)
INSERT_OK_RE = re.compile(
    r"\b(?:INSERT\s+IGNORE\b|ON\s+DUPLICATE\s+KEY\s+UPDATE\b)",
    re.IGNORECASE,
)

# 000_create_migrations_table.sql is exempt — it bootstraps the tracking
# table and only runs once.
EXEMPT_FILES = {"000_create_migrations_table.sql"}

# Files allowed to have non-idempotent INSERTs (one-shot seeds).
EXEMPT_INSERT_FILES = {
    "demo_data.sql",
}


def strip_comments_preserving_lines(sql: str) -> str:
    """Strip SQL line and block comments BEFORE we split on `;`.
    Preserves newlines so line numbers stay accurate."""
    # Block comments — replace with the same number of newlines they contained.
    def _block_repl(m: re.Match[str]) -> str:
        return "\n" * m.group(0).count("\n")
    sql = re.sub(r"/\*.*?\*/", _block_repl, sql, flags=re.DOTALL)
    # Line comments — strip `--` to end-of-line.
    sql = re.sub(r"--[^\n]*", "", sql)
    return sql


def split_statements(sql: str) -> list[tuple[int, str]]:
    """Split SQL on `;` keeping line numbers, respecting `'`, `"`, and `` ` ``
    quoted strings so a `;` inside a literal doesn't fragment the parse.
    Caller pre-strips comments so a `;` inside a comment is also safe."""
    out: list[tuple[int, str]] = []
    buf: list[str] = []
    start_line = 1
    line = 1
    quote: str | None = None  # currently-open quote char, or None.
    i = 0
    n = len(sql)
    while i < n:
        ch = sql[i]
        if ch == "\n":
            line += 1
        if quote is None:
            if ch in ("'", '"', "`"):
                quote = ch
                buf.append(ch)
            elif ch == ";":
                stmt = "".join(buf).strip()
                if stmt:
                    out.append((start_line, stmt))
                buf = []
                start_line = line
            else:
                buf.append(ch)
        else:
            # Inside a quoted literal. Handle SQL '' (doubled single quote)
            # escape — keep both chars verbatim and continue inside the
            # literal.
            if ch == quote:
                if i + 1 < n and sql[i + 1] == quote:
                    buf.append(ch)
                    buf.append(ch)
                    i += 2
                    continue
                buf.append(ch)
                quote = None
            elif ch == "\\" and i + 1 < n:
                buf.append(ch)
                buf.append(sql[i + 1])
                i += 2
                continue
            else:
                buf.append(ch)
        i += 1
    tail = "".join(buf).strip()
    if tail:
        out.append((start_line, tail))
    return out


# Self-recording INSERT INTO tblMigrations is handled by the Migrator
# wrapper (it skips files already in the table before re-executing), so
# it's safe to ignore that pattern even when the file is run standalone
# via `mysql < file.sql`.
SELF_RECORD_RE = re.compile(
    r"\bINSERT\s+(?:IGNORE\s+)?INTO\s+`?tblMigrations`?\b",
    re.IGNORECASE,
)


def check_file(path: Path) -> list[tuple[int, str]]:
    if path.name in EXEMPT_FILES:
        return []
    try:
        raw = path.read_text(encoding="utf-8")
    except OSError:
        return []
    sql = strip_comments_preserving_lines(raw)

    findings: list[tuple[int, str]] = []
    for lineno, stmt in split_statements(sql):
        for pattern, msg in CHECKS:
            if pattern.search(stmt) is not None:
                preview = re.sub(r"\s+", " ", stmt[:120])
                findings.append((lineno, f"{msg}: {preview}…"))
        if (
            INSERT_RE.search(stmt) is not None
            and INSERT_OK_RE.search(stmt) is None
            and SELF_RECORD_RE.search(stmt) is None
            and path.name not in EXEMPT_INSERT_FILES
        ):
            preview = re.sub(r"\s+", " ", stmt[:120])
            findings.append(
                (lineno, f"INSERT missing ON DUPLICATE KEY UPDATE / INSERT IGNORE: {preview}…")
            )
    return findings


def main(argv: list[str]) -> int:
    strict = "--strict" in argv
    files = sorted(SQL_DIR.glob("[0-9]*.sql"))
    total_findings = 0
    print(f"Migration idempotency audit — {len(files)} numbered migration(s)")
    for path in files:
        findings = check_file(path)
        if findings:
            total_findings += len(findings)
            print(f"\n{path.relative_to(REPO_ROOT)}:")
            for lineno, msg in findings:
                print(f"  • L{lineno}: {msg}")

    print()
    if total_findings == 0:
        print("All migrations look re-runnable. ✅")
        return 0
    print(f"Found {total_findings} non-idempotent statement(s).")
    return 1 if strict is True else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
