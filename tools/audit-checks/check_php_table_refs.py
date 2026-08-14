#!/usr/bin/env python3
"""
PHP table-reference existence check.

Builds the set of KNOWN tables from every `CREATE TABLE [IF NOT EXISTS]
`tblX`` statement in web/_sql/full_schema.sql — the authoritative schema
with migrations 000-153 already folded in — then scans the PHP codebase
for any `tblXxx`-shaped identifier and flags ones that don't name a real
table.

check_sql_columns.py only parses .sql files, so a wrong table name
hard-coded straight into a PHP query string (or a comment, string, etc.)
was invisible to it — that exact gap let the GDPR eraser silently skip
real tables because the hunted-for name didn't match anything the
schema actually creates. This check closes it from the PHP side.

PHP comments (`//` line, `#` line, `/* … */` block) are stripped before
scanning so a comment that deliberately talks about a table name that
doesn't exist — e.g. "no `tblFoo` column/table; see `tblBar` instead" —
doesn't false-positive. This is the dominant source of noise; see
ALLOWLIST below for any mention that still needs an explicit exemption
after comment-stripping (kept empty unless a genuine one shows up).

Exit code:
  0 — no findings (CI-green)
  1 — at least one unknown table referenced (CI annotates but doesn't
      block merge unless invoked with --strict)

Usage:
  python3 tools/audit-checks/check_php_table_refs.py [--strict]
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
SQL_DIR = REPO_ROOT / "web" / "_sql"
PHP_ROOTS = [
    REPO_ROOT / "web" / "_apps",
    REPO_ROOT / "web" / "_core",
    REPO_ROOT / "web" / "_install",
    REPO_ROOT / "web" / "public_html",
]

# Match: CREATE TABLE IF NOT EXISTS `tblX` (  — table name only, schema
# body isn't needed for an existence check.
CREATE_RE = re.compile(
    r"CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(tbl\w+)`?\s*\(",
    re.IGNORECASE,
)

# Match any tblXxx-shaped identifier anywhere in PHP source (query
# strings, comments before stripping, docblocks, etc.).
TABLE_REF_RE = re.compile(r"\btbl[A-Za-z0-9_]+\b")

# Explicit allowlist for genuine comment-only false positives that
# survive comment-stripping — (relative_path, line_number): reason.
# Prefer fixing comment-stripping over adding here; this stays empty
# unless a real edge case turns up that stripping can't handle.
ALLOWLIST: dict[tuple[str, int], str] = {}


def build_known_tables() -> set[str]:
    """Return the set of table names CREATE-d in full_schema.sql."""
    known: set[str] = set()
    full = SQL_DIR / "full_schema.sql"
    if full.is_file():
        text = full.read_text(encoding="utf-8", errors="ignore")
        known.update(CREATE_RE.findall(text))
    return known


def strip_php_comments(text: str) -> str:
    """
    Strip `//` line, `#` line, and `/* … */` block PHP comments while
    preserving line numbers (block comments are replaced with the same
    number of newlines so reported line numbers still point at the
    original file).

    Regex-driven, not a real PHP tokenizer — like every other checker
    in this directory it can be fooled by a `#`/`//` inside a string
    literal, but that only ever costs a false NEGATIVE (a reference
    hidden alongside the stripped text), never a false positive, so it
    stays on the safe side for a heuristic CI gate.
    """
    # Block comments first, so a `//` or `#` that happens to sit inside
    # one doesn't get treated as its own (redundant) line comment once
    # the block is blanked.
    text = re.sub(
        r"/\*.*?\*/",
        lambda m: "\n" * m.group(0).count("\n"),
        text,
        flags=re.DOTALL,
    )
    text = re.sub(r"//[^\n]*", "", text)
    # `#` starts a PHP line comment EXCEPT `#[` (PHP 8 attribute
    # syntax, e.g. `#[Attribute]`) — exclude that so attributes aren't
    # mistaken for comments.
    text = re.sub(r"#(?!\[)[^\n]*", "", text)
    return text


def scan_php_refs(known: set[str]) -> list[tuple[Path, int, str]]:
    """Return findings: (php_file, line_no, unknown_table_name)."""
    findings: list[tuple[Path, int, str]] = []
    for root in PHP_ROOTS:
        if not root.exists():
            continue
        for php in root.rglob("*.php"):
            try:
                raw = php.read_text(encoding="utf-8", errors="ignore")
            except OSError:
                continue
            text = strip_php_comments(raw)
            for m in TABLE_REF_RE.finditer(text):
                name = m.group(0)
                if name in known:
                    continue
                line_no = text[: m.start()].count("\n") + 1
                rel = str(php.relative_to(REPO_ROOT))
                if (rel, line_no) in ALLOWLIST:
                    continue
                findings.append((php, line_no, name))
    return findings


def check() -> int:
    known = build_known_tables()
    print(f"Tables inspected (from full_schema.sql): {len(known)}")

    findings = scan_php_refs(known)

    # Deduplicate (file, line, table) — a line can only report the same
    # unknown name once even if it appears twice on it.
    seen: set[tuple[str, int, str]] = set()
    deduped: list[tuple[str, int, str]] = []
    for php, line_no, name in findings:
        rel = str(php.relative_to(REPO_ROOT))
        key = (rel, line_no, name)
        if key in seen:
            continue
        seen.add(key)
        deduped.append(key)

    print(f"Unknown table references: {len(deduped)}")
    print()
    if deduped:
        print("### PHP references to tables not in full_schema.sql\n")
        for rel, line_no, name in deduped:
            print(f"  • {rel}:{line_no} — references unknown table `{name}`")
        print(
            "\nEither fix the table name to match web/_sql/full_schema.sql, "
            "or if this is a genuine comment-only mention that comment-"
            "stripping can't resolve, add a documented entry to "
            "ALLOWLIST in this script."
        )

    strict = "--strict" in sys.argv
    if deduped and strict:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(check())
