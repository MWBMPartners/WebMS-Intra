#!/usr/bin/env python3
"""
MariaDB-only DDL syntax check.

MySQL 8.0 (all point releases, and 8.4/9.x) rejects MariaDB's `IF [NOT]
EXISTS` DDL extensions on ADD/DROP COLUMN, ADD/CREATE/DROP INDEX|KEY, and
CHANGE/MODIFY COLUMN with a hard parse error (ERROR 1064) — this is NOT the
same thing as the `IF NOT EXISTS` supported natively on CREATE TABLE / DROP
TABLE, which is standard MySQL and always fine. Because 1064 is a parse
error, every execution path fails outright: Migrator stale-upgrade,
installer fresh install, and the e2e harness.

The portable fix is the information_schema + PREPARE/EXECUTE guard idiom
already shipped in this codebase (web/_sql/037, 112, 138) — see DEV_NOTES.md
→ "Portable DDL convention (MySQL 8.0 ∩ MariaDB)".

This script scans every web/_sql/*.sql file (numbered migrations,
full_schema.sql, demo_data.sql) for the MariaDB-only forms and flags them.

Exit:
  0 — clean.
  1 — at least one MariaDB-only DDL statement found (CI gating optional via
      --strict).

Usage:
  python3 tools/audit-checks/check_mariadb_only_ddl.py [--strict]
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
SQL_DIR = REPO_ROOT / "web" / "_sql"

# MariaDB-only `IF [NOT] EXISTS` DDL extensions. MariaDB places the clause
# immediately after the verb phrase (optionally after the object name for
# the DROP forms) — adjacency matching avoids false positives from a `[^;]*`
# bridge picking up an unrelated, legitimate `CREATE TABLE IF NOT EXISTS`
# later in the same statement. Must include the KEY/UNIQUE/FULLTEXT/SPATIAL
# synonyms — a plain `ADD COLUMN|ADD INDEX` alternation would miss
# `ADD KEY IF NOT EXISTS` and `CREATE UNIQUE INDEX IF NOT EXISTS`.
#
# Standard MySQL forms — `CREATE TABLE IF NOT EXISTS`, `DROP TABLE IF
# EXISTS` — are outside this alternation and never match.
MARIADB_ONLY_RE = re.compile(
    r"\b(?:"
    r"ADD\s+COLUMN"
    r"|ADD\s+(?:UNIQUE\s+)?(?:INDEX|KEY)"
    r"|CREATE\s+(?:UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?INDEX"
    r"|DROP\s+(?:INDEX|KEY|COLUMN|FOREIGN\s+KEY)"
    r"|CHANGE\s+COLUMN|MODIFY\s+COLUMN"
    r")\s+(?:`[^`]+`\s+)?IF\s+(?:NOT\s+)?EXISTS\b",
    re.IGNORECASE,
)


def strip_comments_preserving_lines(sql: str) -> str:
    """Strip SQL line and block comments BEFORE we scan line-by-line.
    Preserves newlines so line numbers stay accurate."""
    # Block comments — replace with the same number of newlines they contained.
    def _block_repl(m: re.Match[str]) -> str:
        return "\n" * m.group(0).count("\n")
    sql = re.sub(r"/\*.*?\*/", _block_repl, sql, flags=re.DOTALL)
    # Line comments — strip `--` to end-of-line.
    sql = re.sub(r"--[^\n]*", "", sql)
    return sql


def check_file(path: Path) -> list[tuple[int, str]]:
    try:
        raw = path.read_text(encoding="utf-8")
    except OSError:
        return []
    sql = strip_comments_preserving_lines(raw)

    findings: list[tuple[int, str]] = []
    for lineno, line in enumerate(sql.splitlines(), start=1):
        for m in MARIADB_ONLY_RE.finditer(line):
            preview = re.sub(r"\s+", " ", line.strip())[:120]
            findings.append(
                (lineno, f"MariaDB-only DDL (MySQL 8 rejects with error 1064): {preview}…")
            )
    return findings


def main(argv: list[str]) -> int:
    strict = "--strict" in argv
    files = sorted(SQL_DIR.glob("*.sql"))
    total_findings = 0
    print(f"MariaDB-only DDL audit — {len(files)} SQL file(s) scanned")
    for path in files:
        findings = check_file(path)
        if findings:
            total_findings += len(findings)
            print(f"\n{path.relative_to(REPO_ROOT)}:")
            for lineno, msg in findings:
                print(f"  • L{lineno}: {msg}")

    print()
    if total_findings == 0:
        print("No MariaDB-only DDL found. ✅")
        return 0
    print(f"Found {total_findings} MariaDB-only DDL statement(s).")
    print("See DEV_NOTES.md → \"Portable DDL convention (MySQL 8.0 ∩ MariaDB)\" "
          "for the information_schema + PREPARE guard idiom (house examples: "
          "migrations 037/112/138).")
    return 1 if strict is True else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
