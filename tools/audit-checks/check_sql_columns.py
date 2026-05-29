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

# Match: UPDATE `tblX` SET col = ?, col = ?, ...  up to the FIRST of:
#   WHERE / ORDER / LIMIT / closing quote / new statement / end-of-string.
# Critically we DO NOT span across PHP string concatenation joiners
# (which could otherwise pull in a later UPDATE statement's SET clause)
# nor across `UPDATE` / `INSERT` / `DELETE` / `SELECT` keywords that
# would start a new statement.
UPDATE_RE = re.compile(
    r"UPDATE\s+(?:IGNORE\s+)?`?(tbl\w+)`?\s+SET\s+"
    r"(.*?)"
    r"(?:\s+WHERE\b|\s+ORDER\b|\s+LIMIT\b"
    r"|\s+(?:INSERT|UPDATE|DELETE|SELECT)\b"
    r"|'\s*\)|\s*$)",
    re.IGNORECASE | re.DOTALL,
)
# Inside the SET clause: `colName` = expr  or  colName = expr
SET_ASSIGN_RE = re.compile(r"`?(\w+)`?\s*=")

# Match: SELECT col, col FROM `tblX` (no JOINs, no aliases — keeps the check
# precise but narrow). Skip SELECT * patterns.
SELECT_RE = re.compile(
    r"SELECT\s+(?!\*)([^\n]+?)\s+FROM\s+`?(tbl\w+)`?\s*"
    r"(?:WHERE|ORDER|GROUP|LIMIT|;|\s*$|\)|\s+(?:LEFT|INNER|RIGHT|OUTER|JOIN))",
    re.IGNORECASE,
)
# Inside the SELECT column list: identifier (possibly with AS alias)
SELECT_COL_RE = re.compile(r"`?(\w+)`?(?:\s+AS\s+\w+)?")


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


def scan_php_inserts(schema: dict[str, set[str]]) -> list[tuple[Path, int, str, str, str]]:
    """Return findings: (php_file, line_no, table, missing_column, statement_kind)."""
    findings: list[tuple[Path, int, str, str, str]] = []
    for root in PHP_ROOTS:
        if not root.exists():
            continue
        for php in root.rglob("*.php"):
            try:
                raw = php.read_text(encoding="utf-8", errors="ignore")
            except OSError:
                continue
            text = strip_php_comments(reconstruct_php_strings(raw))

            # Line numbers are computed from `text` (reconstructed), not
            # `raw`, because string-concatenation reconstruction collapses
            # multi-line SQL onto fewer lines — using raw.find() against
            # collapsed strings reports wildly wrong offsets.
            # `strip_php_comments` preserves newline count so the reported
            # line still points within a few lines of the actual SQL.

            # ── INSERT column lists ──
            for m in INSERT_RE.finditer(text):
                tbl = m.group(1)
                cols = [c.strip().strip("`") for c in m.group(2).split(",")]
                cols = [c for c in cols if c]
                if not cols or not all(re.fullmatch(r"\w+", c) for c in cols):
                    continue
                line_no = text[: m.start()].count("\n") + 1
                if tbl not in schema:
                    findings.append((php, line_no, tbl, "(unknown table)", "INSERT"))
                    continue
                for c in cols:
                    if c not in schema[tbl]:
                        findings.append((php, line_no, tbl, c, "INSERT"))

            # ── UPDATE … SET col = … assignments ──
            for m in UPDATE_RE.finditer(text):
                tbl = m.group(1)
                set_body = m.group(2)
                line_no = text[: m.start()].count("\n") + 1
                if tbl not in schema:
                    findings.append((php, line_no, tbl, "(unknown table)", "UPDATE"))
                    continue
                for assign in SET_ASSIGN_RE.finditer(set_body):
                    col = assign.group(1)
                    # Skip SQL keywords that may match the regex (e.g. NOW)
                    # and skip PHP-templated placeholders.
                    if col.upper() in {"NOW", "NULL", "TRUE", "FALSE", "AND", "OR"}:
                        continue
                    if col not in schema[tbl]:
                        findings.append((php, line_no, tbl, col, "UPDATE"))

            # ── SELECT col-list FROM tbl ──
            for m in SELECT_RE.finditer(text):
                col_body = m.group(1)
                tbl = m.group(2)
                line_no = text[: m.start()].count("\n") + 1
                if tbl not in schema:
                    findings.append((php, line_no, tbl, "(unknown table)", "SELECT"))
                    continue
                # Skip if the SELECT list contains anything that's not a
                # simple identifier (functions, expressions, aliases, joins).
                # We're after exact column references only.
                if any(sym in col_body for sym in ("(", ")", ".", " AS ", "*", "DISTINCT")):
                    continue
                cols = [c.strip().strip("`") for c in col_body.split(",")]
                # Skip pure-numeric "columns" — these are SQL literals from
                # existence-check patterns like `SELECT 1 FROM tbl WHERE …`.
                cols = [
                    c for c in cols
                    if c and re.fullmatch(r"\w+", c) and not c.isdigit()
                ]
                for c in cols:
                    if c not in schema[tbl]:
                        findings.append((php, line_no, tbl, c, "SELECT"))

    return findings


def check() -> int:
    schema = build_schema_map()
    print(f"Tables inspected: {len(schema)}")
    findings = scan_php_inserts(schema)
    print(f"Column-name mismatches (INSERT + UPDATE + SELECT): {len(findings)}")
    print()
    if findings:
        # Deduplicate (file, line, table, col, kind).
        seen: set[tuple[str, int, str, str, str]] = set()
        deduped: list[tuple[str, int, str, str, str]] = []
        for php, line_no, tbl, col, kind in findings:
            rel = str(php.relative_to(REPO_ROOT))
            key = (rel, line_no, tbl, col, kind)
            if key in seen:
                continue
            seen.add(key)
            deduped.append(key)

        # Group by statement kind for readable output.
        by_kind: dict[str, list[tuple[str, int, str, str, str]]] = {}
        for finding in deduped:
            by_kind.setdefault(finding[4], []).append(finding)

        for kind in ("INSERT", "UPDATE", "SELECT"):
            rows = by_kind.get(kind, [])
            if not rows:
                continue
            print(f"### {kind} column-name mismatches ({len(rows)})\n")
            for rel, line_no, tbl, col, _ in rows:
                print(f"  • {rel}:{line_no} — {kind} on {tbl} references column `{col}`")
            print()

    strict = "--strict" in sys.argv
    if findings and strict:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(check())
