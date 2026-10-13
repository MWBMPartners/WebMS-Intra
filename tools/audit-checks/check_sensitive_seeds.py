#!/usr/bin/env python3
"""
A seeded secret must be empty (#497).

Path: tools/audit-checks/check_sensitive_seeds.py

A setting is a SECRET when its `isSensitive` column is 1. A secret is stored
encrypted, with a key that exists only on the installation. An SQL file cannot
encrypt anything, so a row that is seeded as a secret can only be seeded EMPTY.

WHAT WENT WRONG WITHOUT THIS: the install script seeded
`auth.ms365.tenantOnly` as the plain text `true` with the mark on. For as long
as the portal never decrypted anything nobody noticed. The first correct
settings load would have stopped every page on that one row (the decrypt call
throws on text that short). Migration 214 took the mark off it; this check is
what stops the next one.

It reads web/_sql/full_schema.sql and every numbered migration, and reports:

  1. an INSERT into tblSettings that gives a row the mark AND a value that is
     not empty. On a fresh install that value sits in the database as readable
     text, is reported as "needs entering again" for ever, and is in the
     repository for anybody to read;
  2. an INSERT into tblSettings that seeds a secret and ends in an
     ON DUPLICATE KEY UPDATE which sets `settingValue`. On an upgrade, or when
     the installer replays the migrations, that would overwrite a secret an
     administrator has stored with the seed.

WHAT IT CANNOT DO
  - It reads INSERT … VALUES statements only. A secret given a value by an
    UPDATE, or by INSERT … SELECT, is not seen.
  - It cannot know that a setting OUGHT to be a secret and is not marked. The
    mark is the only record of that.
  - It does not run any SQL. tools/settings-secrets-selftest.php does, and
    proves what the portal does with each kind of row.

Exit code:
  0 — nothing found (or findings without --strict)
  1 — at least one finding AND --strict was passed

Usage:
  python3 tools/audit-checks/check_sensitive_seeds.py [--strict] [--file PATH …]

`--file` reads the named files INSTEAD of web/_sql/. It exists so that this
check can be shown to fire on an install script known to be wrong.

@package   Portal\\Tools
@author    MWBM Partners Ltd (t/a MWservices)
@copyright 2026-present MWBM Partners Ltd (t/a MWservices)
@license   All Rights Reserved
@version   1.0.0
@link      https://github.com/MWBMPartners/WebMS-Intra/issues/497
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

# The statement and tuple splitting is the seed-parity check's, used as it is:
# two readers of the same files should not disagree about where a row ends.
sys.path.insert(0, str(Path(__file__).resolve().parent))
from check_schema_seed_parity import (  # noqa: E402
    FULL_SCHEMA, MIGRATION_GLOB, SQL_DIR, split_statements, split_tuples,
    strip_sql_comments, unquote,
)

SETTINGS_INSERT = re.compile(
    r"INSERT\s+(?:IGNORE\s+)?INTO\s+`?tblSettings`?\s*\(([^)]*)\)\s*VALUES\s*(.*)$",
    re.IGNORECASE | re.DOTALL,
)
ON_DUPLICATE = re.compile(r"\bON\s+DUPLICATE\s+KEY\s+UPDATE\b(.*)$", re.IGNORECASE | re.DOTALL)
SETS_VALUE = re.compile(r"`?settingValue`?\s*=", re.IGNORECASE)
# Migrations 122 to 139 wrote the mark's column under a wrong name. Both count.
MARK_COLUMNS = ("issensitive", "isencrypted")


def files_to_read(argv: list[str]) -> list[Path]:
    """The files named after each --file, or the install script and every migration."""
    named = [Path(argv[i + 1]) for i, a in enumerate(argv) if a == "--file" and i + 1 < len(argv)]
    if named:
        return named
    return [FULL_SCHEMA] + sorted(SQL_DIR.glob(MIGRATION_GLOB))


def findings_in(path: Path) -> tuple[list[str], int]:
    """What is wrong in one file, and how many secret rows it seeds at all."""
    found: list[str] = []
    secrets = 0
    for statement in split_statements(strip_sql_comments(path.read_text(encoding="utf-8"))):
        match = SETTINGS_INSERT.match(statement.strip())
        if match is None:
            continue
        columns = [c.strip().strip("`").lower() for c in match.group(1).split(",")]
        mark_at = next((columns.index(c) for c in MARK_COLUMNS if c in columns), None)
        if mark_at is None or "settingkey" not in columns or "settingvalue" not in columns:
            continue
        key_at, value_at = columns.index("settingkey"), columns.index("settingvalue")
        values = match.group(2)
        tail = ON_DUPLICATE.search(values)
        if tail is not None:
            values = values[: tail.start()]
        seeded: list[str] = []
        for row in split_tuples(values):
            if len(row) <= max(mark_at, key_at, value_at) or row[mark_at].strip() != "1":
                continue
            name = unquote(row[key_at])
            seeded.append(name)
            value = row[value_at].strip()
            # NULL and '' are the two ways of seeding "nothing".
            if value.upper() != "NULL" and unquote(value) != "":
                found.append(f"{path.name}: `{name}` is seeded as a secret WITH a value. "
                             "A seed cannot be encrypted, so a seeded secret must be empty.")
        secrets += len(seeded)
        if seeded and tail is not None and SETS_VALUE.search(tail.group(1)) is not None:
            found.append(f"{path.name}: the INSERT that seeds `{seeded[0]}`"
                         f"{'' if len(seeded) == 1 else f' and {len(seeded) - 1} more'} ends in an "
                         "ON DUPLICATE KEY UPDATE that sets settingValue. Running it again would "
                         "overwrite a stored secret with the seed.")
    return found, secrets


def check() -> int:
    files = files_to_read(sys.argv)
    missing = [str(p) for p in files if not p.is_file()]
    if missing:
        print("check_sensitive_seeds: cannot read " + ", ".join(missing))
        return 1
    found: list[str] = []
    secrets = 0
    for path in files:
        here, count = findings_in(path)
        found += here
        secrets += count
    for line in found:
        print("  • " + line)
    # The count is printed so that "nothing found" cannot mean "nothing read".
    print(f"check_sensitive_seeds: read {len(files)} file(s) seeding {secrets} secret row(s); "
          f"{len(found)} finding(s).")
    if not found:
        print("check_sensitive_seeds: OK — every seeded secret is empty, and no seed can overwrite one.")
    return 1 if found and "--strict" in sys.argv else 0


if __name__ == "__main__":
    sys.exit(check())
