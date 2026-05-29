#!/usr/bin/env python3
"""
SQL column-name existence check.

Builds a (table → columns) map from every `CREATE TABLE` in
web/_sql/full_schema.sql plus every `ALTER TABLE … ADD COLUMN` across the
numbered migrations. Then greps the PHP codebase for `INSERT INTO tblX (col, ...)
VALUES` patterns and verifies every column appears in the map.

Catches the #198 / #201 class of bug: runtime SQL references a column that
doesn't exist on the named table.

Exit code:
  0 — no findings
  1 — one or more mismatches (CI annotates but doesn't block unless --strict)

Usage:
  python3 tools/audit-checks/check_sql_columns.py [--strict]
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
    REPO_ROOT / "web" / "public_html",
]

# Match: CREATE TABLE IF NOT EXISTS `tblX` ( …columns… )
CREATE_RE = re.compile(
    r"CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(tbl\w+)`?\s*\((.*?)\)\s*ENGINE",
    re.IGNORECASE | re.DOTALL,
)
# Match a column definition inside a CREATE TABLE block — `colName` …
COLUMN_RE = re.compile(r"^\s*`(\w+)`\s+", re.MULTILINE)

# Match: ALTER TABLE `tblX` ADD COLUMN [IF NOT EXISTS] `colY` …
ALTER_ADD_RE = re.compile(
    r"ALTER\s+TABLE\s+`?(tbl\w+)`?\s+ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?`(\w+)`",
    re.IGNORECASE,
)
# Also: ALTER TABLE `tblX` … `colY` (sometimes split across multiple
# ADD COLUMN clauses in one ALTER statement). Greedy enough for our patterns.
ALTER_ADD_MULTI_RE = re.compile(
    r"ALTER\s+TABLE\s+`?(tbl\w+)`?\s+(.*?);",
    re.IGNORECASE | re.DOTALL,
)

# Match: INSERT INTO `tblX` (col, col, col) — captures the column list.
INSERT_RE = re.compile(
    r"INSERT\s+(?:IGNORE\s+)?INTO\s+`?(tbl\w+)`?\s*\(([^)]+)\)",
    re.IGNORECASE,
)


def build_schema_map() -> dict[str, set[str]]:
    """Return {tableName: {column, column, …}}."""
    schema: dict[str, set[str]] = {}

    full = SQL_DIR / "full_schema.sql"
    if full.is_file():
        text = full.read_text(encoding="utf-8", errors="ignore")
        for m in CREATE_RE.finditer(text):
            tbl = m.group(1)
            body = m.group(2)
            cols = set(COLUMN_RE.findall(body))
            schema.setdefault(tbl, set()).update(cols)

    # Apply ALTER TABLE ADD COLUMN from migrations on top.
    for sql in sorted(SQL_DIR.glob("*.sql")):
        text = sql.read_text(encoding="utf-8", errors="ignore")
        # Simple single-add ALTERs
        for m in ALTER_ADD_RE.finditer(text):
            schema.setdefault(m.group(1), set()).add(m.group(2))
        # Multi-clause ALTERs: pick out every `colName` mentioned after
        # an ADD COLUMN inside the statement body. Coarse but effective.
        for m in ALTER_ADD_MULTI_RE.finditer(text):
            tbl = m.group(1)
            body = m.group(2)
            if "ADD COLUMN" not in body.upper():
                continue
            for col_match in re.finditer(
                r"ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?`(\w+)`",
                body, re.IGNORECASE,
            ):
                schema.setdefault(tbl, set()).add(col_match.group(1))

    return schema


def reconstruct_php_strings(text: str) -> str:
    """
    Reconstruct concatenated PHP single-quoted strings into a single
    logical string so SQL split across multiple `'…' . '…'` lines parses
    as one INSERT statement.

    Specifically collapses sequences like:
        'INSERT INTO tblX ('
            . 'colA, '
            . 'colB)'
    into:
        'INSERT INTO tblX (colA, colB)'

    Single-pass, regex-driven — doesn't handle every edge of PHP
    string syntax, but covers the patterns used in this codebase.
    """
    # Collapse '<chars>' . '<chars>' (with possible whitespace/newlines) into
    # '<chars><chars>'. Run repeatedly until no more matches.
    pattern = re.compile(r"'([^']*)'\s*\.\s*'([^']*)'")
    while True:
        new = pattern.sub(lambda m: "'" + m.group(1) + m.group(2) + "'", text)
        if new == text:
            return new
        text = new


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


def scan_php_inserts(schema: dict[str, set[str]]) -> list[tuple[Path, int, str, str]]:
    """Return findings: (php_file, line_no, table, missing_column)."""
    findings: list[tuple[Path, int, str, str]] = []
    for root in PHP_ROOTS:
        if not root.exists():
            continue
        for php in root.rglob("*.php"):
            try:
                raw = php.read_text(encoding="utf-8", errors="ignore")
            except OSError:
                continue
            text = strip_php_comments(reconstruct_php_strings(raw))
            for m in INSERT_RE.finditer(text):
                tbl = m.group(1)
                col_list = m.group(2)
                cols = [c.strip().strip("`") for c in col_list.split(",")]
                cols = [c for c in cols if c]
                # Sanity guard against parser fragments — column names
                # should be `\w+`. Skip anything that doesn't parse cleanly.
                if not cols or not all(re.fullmatch(r"\w+", c) for c in cols):
                    continue
                if tbl not in schema:
                    findings.append(
                        (php, raw[: raw.find(m.group(0))].count("\n") + 1,
                         tbl, "(unknown table)")
                    )
                    continue
                for c in cols:
                    if c not in schema[tbl]:
                        line_no = raw[: raw.find(m.group(0))].count("\n") + 1
                        if line_no <= 0:
                            line_no = 1
                        findings.append((php, line_no, tbl, c))
    return findings


def check() -> int:
    schema = build_schema_map()
    print(f"Tables inspected: {len(schema)}")
    findings = scan_php_inserts(schema)
    print(f"Column-name mismatches: {len(findings)}")
    print()
    if findings:
        print("### Column-name mismatches\n")
        # Deduplicate (file, line, table, col).
        seen: set[tuple[str, int, str, str]] = set()
        for php, line_no, tbl, col in findings:
            rel = str(php.relative_to(REPO_ROOT))
            key = (rel, line_no, tbl, col)
            if key in seen:
                continue
            seen.add(key)
            print(f"  • {rel}:{line_no} — INSERT INTO {tbl} references column `{col}`")
    strict = "--strict" in sys.argv
    if findings and strict:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(check())
