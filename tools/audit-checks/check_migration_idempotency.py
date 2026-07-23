#!/usr/bin/env python3
"""
Migration idempotency static-analysis check.

Walks every web/_sql/NNN_*.sql migration and flags statements that aren't
safely re-runnable. Production targets MySQL 8.0 ∩ MariaDB 10.x (see
DEV_NOTES.md → "Portable DDL convention"), so MariaDB's `IF [NOT] EXISTS`
DDL extensions are NOT an acceptable fix here — MySQL 8 rejects them with
ERROR 1064 (see tools/audit-checks/check_mariadb_only_ddl.py). The only
portable idempotent form for DDL is the information_schema + PREPARE/
EXECUTE guard idiom already shipped in this codebase (web/_sql/037, 112,
138). Guard blocks are recognised structurally — any statement starting
with `SET @foo := …` is dynamic-SQL guard assignment and is idempotent by
construction, even though the DDL text quoted inside it (e.g. `ADD COLUMN`)
would otherwise trip these checks.

Flags:
  • CREATE TABLE without IF NOT EXISTS               → second run errors
    (standard MySQL syntax — this one IS fine bare)
  • bare top-level ALTER … ADD COLUMN                → wrap in the guard
  • bare top-level CREATE INDEX                      → wrap in the guard
  • bare top-level ALTER … ADD (UNIQUE) INDEX/KEY    → wrap in the guard
  • bare top-level ALTER … ADD CONSTRAINT            → wrap in the guard
  • bare top-level ALTER … DROP INDEX/KEY/COLUMN     → wrap in the guard
  • INSERT INTO without ON DUPLICATE KEY UPDATE      → second run risks
        duplicate-key errors OR (worse) row dupes (tblMigrations
        self-records included — they must carry the ON DUPLICATE KEY
        UPDATE idiom too, since the installer replays every migration file
        after loading full_schema.sql, ignoring tblMigrations)

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

# Dynamic-SQL guard assignments (the information_schema + PREPARE idiom —
# see DEV_NOTES.md → "Portable DDL convention"). A statement matching this
# is idempotent by construction: it's a `SET @var := …` assignment, and any
# DDL keywords quoted inside its string literal (e.g. `ADD COLUMN`) are not
# executed directly — they're only PREPAREd/EXECUTEd after an
# information_schema existence check elsewhere in the same guard block.
# Skip DDL CHECKS entirely for these so the false-positive class this
# script used to raise against 037's own guard ("SET @stmt := IF(…") can't
# recur.
GUARD_ASSIGN_RE = re.compile(r"^\s*SET\s+@\w+\s*:?=", re.IGNORECASE)

# Statements we require idempotency on. Each entry: (regex, what's_required).
# NOTE: these intentionally do NOT special-case `IF [NOT] EXISTS` on ADD/
# DROP COLUMN or ADD/CREATE/DROP INDEX|KEY — that clause is a MariaDB-only
# DDL extension that MySQL 8 rejects with ERROR 1064 (see
# check_mariadb_only_ddl.py). The only acceptable idempotent form for these
# is the information_schema guard (GUARD_ASSIGN_RE, above), so any bare
# top-level occurrence — guarded-looking or not — is flagged here.
CHECKS = [
    (
        re.compile(r"\bCREATE\s+TABLE\b(?!\s+IF\s+NOT\s+EXISTS)", re.IGNORECASE),
        "CREATE TABLE missing IF NOT EXISTS",
    ),
    (
        re.compile(r"\bALTER\s+TABLE\b[^;]*\bADD\s+COLUMN\b", re.IGNORECASE),
        "bare ADD COLUMN — wrap in the information_schema guard (DEV_NOTES → Portable DDL)",
    ),
    (
        re.compile(r"\bCREATE\s+(?:UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?INDEX\b", re.IGNORECASE),
        "bare CREATE INDEX — wrap in the information_schema guard (DEV_NOTES → Portable DDL)",
    ),
    (
        re.compile(r"\bALTER\s+TABLE\b[^;]*\bADD\s+(?:UNIQUE\s+)?(?:INDEX|KEY)\b", re.IGNORECASE),
        "bare ADD (UNIQUE) INDEX/KEY — wrap in the information_schema guard (DEV_NOTES → Portable DDL)",
    ),
    (
        re.compile(r"\bALTER\s+TABLE\b[^;]*\bADD\s+CONSTRAINT\b", re.IGNORECASE),
        "bare ADD CONSTRAINT — wrap in the information_schema guard (DEV_NOTES → Portable DDL)",
    ),
    (
        re.compile(r"\bALTER\s+TABLE\b[^;]*\bDROP\s+(?:INDEX|KEY|COLUMN)\b", re.IGNORECASE),
        "bare DROP INDEX/KEY/COLUMN — wrap in the information_schema guard (DEV_NOTES → Portable DDL)",
    ),
]

# INSERT must survive a re-run. Three idempotent idioms are accepted:
#   1. INSERT IGNORE
#   2. INSERT ... ON DUPLICATE KEY UPDATE
#   3. INSERT ... SELECT ... WHERE NOT EXISTS (...)  — the conditional-insert
#      form used by e.g. 015_multisite.sql (seed default site) and
#      143_cop_live_chat.sql (seed stream_moderator role); it inserts only
#      when the guard subquery finds no matching row, so it is a no-op on re-run.
# We check this separately so we can give a clearer message.
INSERT_RE = re.compile(r"\bINSERT\s+INTO\b", re.IGNORECASE)
INSERT_OK_RE = re.compile(
    r"\b(?:INSERT\s+IGNORE\b|ON\s+DUPLICATE\s+KEY\s+UPDATE\b|WHERE\s+NOT\s+EXISTS\b)",
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
        # Guard-idiom assignments are idempotent by construction — skip the
        # DDL CHECKS entirely so DDL keywords quoted inside the guard's own
        # string literal don't trip a false positive.
        if GUARD_ASSIGN_RE.match(stmt) is not None:
            continue
        for pattern, msg in CHECKS:
            if pattern.search(stmt) is not None:
                preview = re.sub(r"\s+", " ", stmt[:120])
                findings.append((lineno, f"{msg}: {preview}…"))
        # tblMigrations self-records are NOT exempt: the installer replays
        # every migration file after loading full_schema.sql, ignoring
        # tblMigrations (web/_install/index.php:~441), so a bare INSERT
        # here hits ERROR 1062 on a fresh install exactly like any other
        # non-idempotent INSERT. It must carry ON DUPLICATE KEY UPDATE /
        # INSERT IGNORE and is judged on the same merit as every other
        # INSERT below.
        if (
            INSERT_RE.search(stmt) is not None
            and INSERT_OK_RE.search(stmt) is None
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
