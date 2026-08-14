#!/usr/bin/env python3
"""
Full-schema seed parity check (#364 / #194).

web/_sql/full_schema.sql must reproduce the end-state of running every numbered
migration in order. This script verifies, statically, that:

  1. every web/_sql/NNN_*.sql filename is marked executed in full_schema.sql's
     tblMigrations seed block (otherwise the installer's migration replay and
     the web Migrator disagree about what has run);
  2. every tblSettings settingKey seeded by the migrations (after modelling
     DELETE tombstones and settingKey REPLACE() renames) is also seeded by
     full_schema.sql — missing api.{app}.{action}.enabled flags cause
     ApiRouter 403s on fresh installs;
  3. every tblRoutes routeKey seeded by the migrations (after tombstones) is
     also seeded by full_schema.sql.

Reverse drift (rows only in full_schema.sql — e.g. installer-managed auth.*
provider settings) is reported as an informational count, never a failure.

Exit code:
  0 — no findings
  1 — at least one parity gap AND --strict was passed

Usage:
  python3 tools/audit-checks/check_schema_seed_parity.py [--strict]
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
SQL_DIR = REPO_ROOT / "web" / "_sql"
FULL_SCHEMA = SQL_DIR / "full_schema.sql"
MIGRATION_GLOB = "[0-9][0-9][0-9]_*.sql"

# Settings columns that mean "the sensitive flag" — migration files 122-139
# historically used the wrong name; both must resolve for key extraction.
KEY_COLUMNS = {"tblsettings": "settingkey", "tblroutes": "routekey",
               "tblmigrations": "filename"}


def strip_sql_comments(text: str) -> str:
    return re.sub(r"--[^\n]*", "", text)


def split_statements(text: str) -> list[str]:
    """Split on ';' but never inside a single-quoted string ('' and \\ safe)."""
    out: list[str] = []
    buf: list[str] = []
    in_q = False
    i = 0
    while i < len(text):
        c = text[i]
        if in_q:
            buf.append(c)
            if c == "\\" and i + 1 < len(text):
                buf.append(text[i + 1]); i += 2; continue
            if c == "'":
                if i + 1 < len(text) and text[i + 1] == "'":
                    buf.append("'"); i += 2; continue
                in_q = False
        elif c == "'":
            in_q = True; buf.append(c)
        elif c == ";":
            st = "".join(buf).strip()
            if st:
                out.append(st)
            buf = []
        else:
            buf.append(c)
        i += 1
    st = "".join(buf).strip()
    if st:
        out.append(st)
    return out


def split_tuples(values_sql: str) -> list[list[str]]:
    """Parse VALUES (…),(…) into raw per-column strings (quote/paren aware)."""
    tuples: list[list[str]] = []
    cur: list[str] = []
    buf: list[str] = []
    depth = 0
    in_q = False
    i = 0
    while i < len(values_sql):
        c = values_sql[i]
        if in_q:
            buf.append(c)
            if c == "\\" and i + 1 < len(values_sql):
                buf.append(values_sql[i + 1]); i += 2; continue
            if c == "'":
                if i + 1 < len(values_sql) and values_sql[i + 1] == "'":
                    buf.append("'"); i += 2; continue
                in_q = False
        elif c == "'":
            in_q = True; buf.append(c)
        elif c == "(":
            depth += 1
            if depth == 1:
                cur, buf = [], []
            else:
                buf.append(c)
        elif c == ")":
            depth -= 1
            if depth == 0:
                cur.append("".join(buf).strip()); tuples.append(cur)
                cur, buf = [], []
            else:
                buf.append(c)
        elif c == "," and depth == 1:
            cur.append("".join(buf).strip()); buf = []
        elif depth >= 1:
            buf.append(c)
        i += 1
    return tuples


def unquote(v: str) -> str:
    v = v.strip()
    if len(v) >= 2 and v.startswith("'") and v.endswith("'"):
        return v[1:-1]
    return v


INSERT_RE = re.compile(
    r"INSERT\s+(?:IGNORE\s+)?INTO\s+`?(tblSettings|tblRoutes|tblMigrations)`?"
    r"\s*\(([^)]*)\)\s*VALUES\s*(.*)$",
    re.IGNORECASE | re.DOTALL,
)
DELETE_RE = re.compile(
    r"DELETE\s+FROM\s+`?(tblSettings|tblRoutes)`?\s+WHERE\s+"
    r"`?(settingKey|routeKey)`?\s*(?:=\s*'([^']+)'|IN\s*\(([^)]*)\))",
    re.IGNORECASE | re.DOTALL,
)
RENAME_RE = re.compile(
    r"UPDATE\s+`?tblSettings`?\s+SET\s+`?settingKey`?\s*=\s*"
    r"REPLACE\(\s*`?settingKey`?\s*,\s*'([^']+)'\s*,\s*'([^']+)'\s*\)",
    re.IGNORECASE | re.DOTALL,
)


def seeded_keys(text: str) -> dict[str, set[str]]:
    """{tblsettings: {…}, tblroutes: {…}, tblmigrations: {…}} for one file."""
    out: dict[str, set[str]] = {t: set() for t in KEY_COLUMNS}
    for st in split_statements(strip_sql_comments(text)):
        m = INSERT_RE.match(st)
        if m is None:
            continue
        table = m.group(1).lower()
        cols = [c.strip().strip("`").lower() for c in m.group(2).split(",")]
        # isEncrypted was a historical typo for isSensitive — harmless here
        # because we only need the key column's position.
        key_col = KEY_COLUMNS[table]
        if key_col not in cols:
            continue
        idx = cols.index(key_col)
        vals = m.group(3)
        odk = re.search(r"\bON\s+DUPLICATE\s+KEY\b", vals, re.IGNORECASE)
        if odk is not None:
            vals = vals[: odk.start()]
        for tup in split_tuples(vals):
            if idx < len(tup):
                out[table].add(unquote(tup[idx]))
    return out


def check() -> int:
    if not FULL_SCHEMA.is_file():
        print("full_schema.sql not found — nothing to check")
        return 0
    schema = seeded_keys(FULL_SCHEMA.read_text(encoding="utf-8", errors="ignore"))

    migrations = sorted(SQL_DIR.glob(MIGRATION_GLOB))
    # Expected end-state, replayed in order with tombstones + renames.
    expected: dict[str, dict[str, str]] = {"tblsettings": {}, "tblroutes": {}}
    missing_marks: list[str] = []
    for mig in migrations:
        text = mig.read_text(encoding="utf-8", errors="ignore")
        if mig.name not in schema["tblmigrations"]:
            missing_marks.append(mig.name)
        per = seeded_keys(text)
        for k in per["tblsettings"]:
            expected["tblsettings"][k] = mig.name
        for k in per["tblroutes"]:
            expected["tblroutes"][k] = mig.name
        for st in split_statements(strip_sql_comments(text)):
            dm = DELETE_RE.match(st)
            if dm is not None:
                table = dm.group(1).lower()
                keys = [dm.group(3)] if dm.group(3) else re.findall(r"'([^']+)'", dm.group(4))
                for k in keys:
                    expected[table].pop(k, None)
            rm = RENAME_RE.match(st)
            if rm is not None:
                old, new = rm.group(1), rm.group(2)
                for k in list(expected["tblsettings"]):
                    if k.startswith(old):
                        expected["tblsettings"][new + k[len(old):]] = \
                            expected["tblsettings"].pop(k)

    miss_settings = {k: v for k, v in expected["tblsettings"].items()
                     if k not in schema["tblsettings"]}
    miss_routes = {k: v for k, v in expected["tblroutes"].items()
                   if k not in schema["tblroutes"]}

    print(f"Migrations on disk: {len(migrations)}")
    print(f"Marked executed in full_schema seed block: {len(schema['tblmigrations'])}")
    print(f"Missing seed-block marks: {len(missing_marks)}")
    print(f"Missing tblSettings seeds: {len(miss_settings)} | "
          f"missing tblRoutes seeds: {len(miss_routes)}")
    print()
    if missing_marks:
        print("### Migrations not marked executed in full_schema.sql\n")
        for name in missing_marks:
            print(f"  • {name} — add to the tblMigrations seed block")
        print()
    if miss_settings:
        print("### tblSettings rows seeded by migrations but absent from full_schema.sql\n")
        for k, src in sorted(miss_settings.items()):
            print(f"  • `{k}` (from {src})")
        print()
    if miss_routes:
        print("### tblRoutes rows seeded by migrations but absent from full_schema.sql\n")
        for k, src in sorted(miss_routes.items()):
            print(f"  • `{k}` (from {src})")
        print()
    extra_s = len([k for k in schema["tblsettings"] if k not in expected["tblsettings"]])
    extra_r = len([k for k in schema["tblroutes"] if k not in expected["tblroutes"]])
    print(f"(informational) full_schema-only rows — settings: {extra_s}, "
          f"routes: {extra_r} (installer/UI-managed rows are expected here)")

    findings = bool(missing_marks or miss_settings or miss_routes)
    if findings and "--strict" in sys.argv:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(check())
