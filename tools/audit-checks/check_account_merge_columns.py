#!/usr/bin/env python3
"""
Every column that holds an account number must be known to the account merge (#1064).

Path: tools/audit-checks/check_account_merge_columns.py

    python3 -I tools/audit-checks/check_account_merge_columns.py              the check
    python3 -I tools/audit-checks/check_account_merge_columns.py --self-test  plants faults and must see each

WHY THIS EXISTS
---------------
`Portal\\Core\\AccountMerge` lists every column that holds an account number and
what merging a duplicate account into the account that stays does to it (moves
it, keeps the kept account's copy, refuses, leaves it as history, ...). A table
added later with an account-number column that nobody adds to that list would be
skipped by a merge in silence: its rows would go on naming an account nobody can
use. A hand-written list goes stale exactly that way (#706), so this compares it
with the install script BOTH WAYS:

  * a column holding an account number that the merge does not list FAILS;
  * a listed column that the install script no longer has FAILS;
  * a column listed twice FAILS (two treatments for one column would let the
    preview and the move disagree).

WHAT COUNTS AS "HOLDS AN ACCOUNT NUMBER"
---------------------------------------
Either of:
  1. a FOREIGN KEY from it to `tblUsers` (in a CREATE TABLE or an ALTER TABLE —
     `tblAttendanceSessions` gets its keys from an ALTER, see
     check_series_attachments_complete.py); or
  2. a whole-number column whose NAME says so: `userID`, anything ending
     `UserID` or `ByID`, or any name on `GdprEraser::LINK_COLUMNS`, read from
     that file so there is one list of such names, not two. Measured on
     15 November 2026, 18 real account columns have no foreign key at all
     (`tblEventRSVPs.userID` among them), which is why rule 2 exists.

A name-shaped column that is NOT an account goes on
`AccountMerge::NOT_AN_ACCOUNT` with its reason, and that list fails both ways too.

WHAT IT CANNOT SEE, SAID PLAINLY
--------------------------------
  * A column that holds an account number on SOME rows only — chosen by another
    column (`tblWorkflowSteps.assigneeValue` when `assigneeType = 'user'`). No
    schema link and no name says so. Those are on `AccountMerge::POLYMORPHIC`;
    this check only proves each one listed still exists. A NEW one of that kind
    is invisible here.
  * An account number inside JSON or free text.
  * Whether a TREATMENT is right. That is a person's judgement, and whether a
    column inside a unique key is marked to handle a clash is asked of a real
    database by tools/account-merge-selftest.php.

It reads text with no database, so it can run before every push.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[1]
sys.path.insert(0, str(HERE))
from check_series_attachments_complete import strip_sql_comments  # noqa: E402  (one SQL comment-stripper, not two)

SCHEMA = REPO / "web" / "_sql" / "full_schema.sql"
MERGE = REPO / "web" / "_core" / "AccountMerge.php"
ERASER = REPO / "web" / "_core" / "GdprEraser.php"

INT_TYPES = r"(?:INTEGER|INT|BIGINT|SMALLINT|MEDIUMINT|TINYINT)"
NAME = r"`?([A-Za-z_][A-Za-z0-9_]*)`?"
DATA_TYPES = {
    "INTEGER", "INT", "BIGINT", "SMALLINT", "MEDIUMINT", "TINYINT", "DECIMAL", "NUMERIC", "FLOAT", "DOUBLE",
    "REAL", "BIT", "BOOL", "BOOLEAN", "CHAR", "VARCHAR", "BINARY", "VARBINARY", "TINYTEXT", "TEXT",
    "MEDIUMTEXT", "LONGTEXT", "TINYBLOB", "BLOB", "MEDIUMBLOB", "LONGBLOB", "ENUM", "SET", "DATE", "TIME",
    "DATETIME", "TIMESTAMP", "YEAR", "JSON", "GEOMETRY", "POINT",
}


# =============================================================================
# 🗄️ The install script
# =============================================================================

def schema_columns(sql_raw: str) -> tuple[dict[str, str], set[str], list[str]]:
    """
    Read the install script into (every column -> its type word, the columns
    with a foreign key to tblUsers, problems found while reading).

    Statements are applied IN FILE ORDER, so a DROP TABLE or DROP COLUMN removes
    what came before it. A RENAME is not followed — it is reported, because
    following it wrongly would hide a column and passing over it would be the
    silent direction.
    """
    sql = strip_sql_comments(sql_raw)
    events: list[tuple[int, str, tuple[str, ...]]] = []

    anchors = [(m.start(), m.group(1)) for m in re.finditer(
        r"\b(?:CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|ALTER\s+TABLE)\s+" + NAME, sql, re.I)]

    def owner_of(offset: int) -> str | None:
        found = None
        for at, name in anchors:
            if at < offset:
                found = name
            else:
                break
        return found

    # A declaration: at the start of a line inside CREATE TABLE, or after ADD [COLUMN].
    # Only a real data-type word makes it a declaration: an indented
    # "`userID` IN (...)" in an UPDATE would otherwise overwrite the real
    # column's type with "IN" and hide it (the silent direction).
    for m in re.finditer(r"(?:^[ \t]+|\bADD\s+(?:COLUMN\s+)?)`([A-Za-z_][A-Za-z0-9_]*)`\s+([A-Za-z]+)", sql, re.M | re.I):
        if m.group(2).upper() in DATA_TYPES:
            events.append((m.start(), "column", (m.group(1), m.group(2).upper())))
    for m in re.finditer(r"FOREIGN\s+KEY\s*\(\s*" + NAME + r"\s*\)\s*REFERENCES\s+" + NAME + r"\s*\(\s*" + NAME, sql, re.I):
        events.append((m.start(), "fk", (m.group(1), m.group(2), m.group(3))))
    for m in re.finditer(r"\bDROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?" + NAME, sql, re.I):
        events.append((m.start(), "drop_table", (m.group(1),)))
    for m in re.finditer(r"\bDROP\s+(?:COLUMN\s+)?`([A-Za-z_][A-Za-z0-9_]*)`", sql, re.I):
        events.append((m.start(), "drop_column", (m.group(1),)))
    for m in re.finditer(r"\b(?:RENAME\s+(?:TABLE|COLUMN)|CHANGE\s+(?:COLUMN\s+)?`)", sql, re.I):
        events.append((m.start(), "rename", ()))

    columns: dict[str, str] = {}
    linked: set[str] = set()
    problems: list[str] = []
    for offset, kind, data in sorted(events):
        owner = owner_of(offset)
        line = sql.count("\n", 0, offset) + 1
        if kind == "drop_table":
            gone = data[0] + "."
            columns = {k: v for k, v in columns.items() if not k.startswith(gone)}
            linked = {k for k in linked if not k.startswith(gone)}
            continue
        if owner is None:
            continue
        if kind == "column":
            columns[f"{owner}.{data[0]}"] = data[1]
        elif kind == "fk":
            if data[1] == "tblUsers" and data[2] == "userID":
                linked.add(f"{owner}.{data[0]}")
        elif kind == "drop_column":
            columns.pop(f"{owner}.{data[0]}", None)
            linked.discard(f"{owner}.{data[0]}")
        elif kind == "rename":
            problems.append(
                f"full_schema.sql line {line}: a RENAME or CHANGE COLUMN on {owner}. This check does not follow "
                "renames, so it cannot tell which column the new name holds. Teach it, rather than let a column "
                "it cannot see pass."
            )
    return columns, linked, problems


def account_columns(columns: dict[str, str], linked: set[str], link_names: set[str]) -> set[str]:
    """The columns that hold an account number, by link or by name (see the docstring)."""
    out = set(linked)
    for name, type_word in columns.items():
        table, column = name.split(".", 1)
        if table == "tblUsers":
            continue
        if re.fullmatch(INT_TYPES, type_word, re.I) is None:
            continue
        if column == "userID" or column.endswith("UserID") or column.endswith("ByID") or column in link_names:
            out.add(name)
    out.discard("tblUsers.userID")
    return out


# =============================================================================
# 🐘 The PHP lists
# =============================================================================

def php_code_only(php: str) -> str:
    """
    The PHP with comments removed and single-quoted strings KEPT (the entries
    are single-quoted strings). Walks the text so that `//` inside a string is
    not a comment and a commented-out entry does not count as listed.
    """
    out: list[str] = []
    i, n = 0, len(php)
    while i < n:
        ch = php[i]
        if ch == "'":
            j = i + 1
            while j < n and php[j] != "'":
                j += 2 if php[j] == "\\" else 1
            out.append(php[i:j + 1])
            i = j + 1
        elif ch == '"':
            j = i + 1
            while j < n and php[j] != '"':
                j += 2 if php[j] == "\\" else 1
            out.append(" " * (j + 1 - i))
            i = j + 1
        elif php.startswith("//", i) or ch == "#":
            j = php.find("\n", i)
            j = n if j < 0 else j
            out.append(" " * (j - i))
            i = j
        elif php.startswith("/*", i):
            j = php.find("*/", i + 2)
            j = n if j < 0 else j + 2
            out.append(re.sub(r"[^\n]", " ", php[i:j]))
            i = j
        else:
            out.append(ch)
            i += 1
    return "".join(out)


def constant_block(code: str, name: str) -> str:
    """The text of one `const NAME = [ ... ];`, brackets matched (strings already stepped over by the caller's view)."""
    m = re.search(r"\bconst\s+" + name + r"\s*=\s*\[", code)
    if m is None:
        return ""
    depth, i = 1, m.end()
    in_str = False
    while i < len(code) and depth > 0:
        ch = code[i]
        if in_str:
            if ch == "\\":
                i += 1
            elif ch == "'":
                in_str = False
        elif ch == "'":
            in_str = True
        elif ch == "[":
            depth += 1
        elif ch == "]":
            depth -= 1
        i += 1
    return code[m.end():i - 1]


def listed_names(block: str) -> list[str]:
    return re.findall(r"'(tbl[A-Za-z0-9_]+\.[A-Za-z0-9_]+)'", block)


def link_column_names(eraser_php: str) -> set[str]:
    return set(re.findall(r"'([A-Za-z0-9_]+)'", constant_block(php_code_only(eraser_php), "LINK_COLUMNS")))


# =============================================================================
# ⚖️ The comparison
# =============================================================================

def findings(sql_raw: str, merge_php: str, eraser_php: str) -> list[str]:
    columns, linked, problems = schema_columns(sql_raw)
    link_names = link_column_names(eraser_php)
    if not link_names:
        problems.append("GdprEraser::LINK_COLUMNS could not be read, so a column known only by its name would be missed.")
    accounts = account_columns(columns, linked, link_names)

    code = php_code_only(merge_php)
    treated = listed_names(constant_block(code, "BY_TREATMENT"))
    poly = listed_names(constant_block(code, "POLYMORPHIC"))
    not_account = listed_names(constant_block(code, "NOT_AN_ACCOUNT"))
    if not treated:
        problems.append("AccountMerge::BY_TREATMENT could not be read, or is empty.")

    every = treated + poly + not_account
    for name in sorted({x for x in every if every.count(x) > 1}):
        problems.append(f"{name} is listed more than once in AccountMerge. One column, one treatment.")

    known = set(every)
    for name in sorted(accounts - known):
        why = "has a foreign key to tblUsers" if name in linked else "is a whole-number column whose name says it holds an account number"
        problems.append(
            f"{name} {why}, and AccountMerge does not list it. A merge would leave its rows naming the duplicate "
            "account. Add it to AccountMerge::BY_TREATMENT with the right treatment (or to NOT_AN_ACCOUNT with the reason)."
        )
    for name in sorted(set(treated) - accounts):
        problems.append(f"{name} is listed in AccountMerge::BY_TREATMENT but the install script has no such account column. Remove it.")
    for name in sorted(set(not_account) - accounts):
        problems.append(f"{name} is on AccountMerge::NOT_AN_ACCOUNT but is not a name-shaped column any more. Remove it.")
    for name in sorted(set(poly) - set(columns)):
        problems.append(f"{name} is on AccountMerge::POLYMORPHIC but the install script has no such column. Remove it.")
    return problems


def run_check() -> int:
    problems = findings(
        SCHEMA.read_text(encoding="utf-8"),
        MERGE.read_text(encoding="utf-8"),
        ERASER.read_text(encoding="utf-8"),
    )
    if problems:
        print("FAIL — the account merge does not know every column that holds an account number (#1064):")
        for p in problems:
            print("  - " + p)
        return 1
    columns, linked, _ = schema_columns(SCHEMA.read_text(encoding="utf-8"))
    count = len(account_columns(columns, linked, link_column_names(ERASER.read_text(encoding="utf-8"))))
    print(f"OK — AccountMerge lists all {count} account-number columns in the install script "
          f"({len(linked)} linked to tblUsers). It cannot see a column that holds an account number "
          "on some rows only; those are listed by hand on AccountMerge::POLYMORPHIC.")
    return 0


# =============================================================================
# 🧪 The self-test: every plant must be reported, and the clean case must not
# =============================================================================

def self_test() -> int:
    eraser = "class X { public const LINK_COLUMNS = ['userID', 'createdByID', 'giverID']; }"
    schema = (
        "CREATE TABLE IF NOT EXISTS `tblUsers` (\n  `userID` INT NOT NULL AUTO_INCREMENT\n) ENGINE=InnoDB;\n"
        "CREATE TABLE IF NOT EXISTS `tblA` (\n  `aID` INT NOT NULL,\n  `userID` INT NOT NULL COMMENT 'who',\n"
        "  `note` VARCHAR(20),\n  CONSTRAINT `fk_a` FOREIGN KEY (`userID`) REFERENCES `tblUsers` (`userID`)\n) ENGINE=InnoDB;\n"
        "CREATE TABLE IF NOT EXISTS `tblB` (\n  `giverID` INT NULL,\n  `assigneeValue` VARCHAR(100),\n"
        "  `supersededByID` INT NULL\n) ENGINE=InnoDB;\n"
        "CREATE TABLE IF NOT EXISTS `tblC` (\n  `ownerRef` INT NULL\n) ENGINE=InnoDB;\n"
        "ALTER TABLE `tblC` ADD CONSTRAINT `fk_c` FOREIGN KEY (`ownerRef`) REFERENCES `tblUsers` (`userID`);\n"
    )
    merge = (
        "class AccountMerge {\n"
        "  public const BY_TREATMENT = [ self::MOVE => ['tblA.userID', 'tblB.giverID', 'tblC.ownerRef'], ];\n"
        "  public const POLYMORPHIC = [ 'tblB.assigneeValue' => ['treatment' => 'move', 'when' => \"x\"] ];\n"
        "  public const NOT_AN_ACCOUNT = [ 'tblB.supersededByID' => 'not a person' ];\n}\n"
    )
    cases: list[tuple[str, str, str, str, str]] = [
        # (label, schema, merge, expected text in a finding or '' for clean)
        ("clean fixture passes", schema, merge, eraser, ""),
        ("a new table with a foreign key to tblUsers is reported",
         schema + "CREATE TABLE IF NOT EXISTS `tblD` (\n  `personRef` INT,\n  FOREIGN KEY (`personRef`) REFERENCES `tblUsers` (`userID`)\n) ENGINE=InnoDB;\n",
         merge, eraser, "tblD.personRef has a foreign key"),
        ("a name-shaped column with no link is reported",
         schema + "CREATE TABLE IF NOT EXISTS `tblE` (\n  `checkedByID` INT NULL\n) ENGINE=InnoDB;\n",
         merge, eraser, "tblE.checkedByID is a whole-number"),
        ("a name on GdprEraser::LINK_COLUMNS with no link and no suffix is reported",
         schema + "CREATE TABLE IF NOT EXISTS `tblF` (\n  `giverID` INT NULL\n) ENGINE=InnoDB;\n",
         merge, eraser, "tblF.giverID"),
        ("a foreign key added by ALTER TABLE is found (owner is the ALTER's table)",
         schema, merge.replace(", 'tblC.ownerRef'", ""), eraser, "tblC.ownerRef has a foreign key"),
        ("a listed column that no longer exists is reported",
         schema, merge.replace("'tblC.ownerRef'", "'tblC.ownerRef', 'tblZ.userID'"), eraser, "tblZ.userID is listed"),
        ("a column listed twice is reported",
         schema, merge.replace("'tblB.giverID',", "'tblB.giverID', 'tblB.giverID',"), eraser, "listed more than once"),
        ("a COMMENTED-OUT entry does not count as listed",
         schema, merge.replace("'tblA.userID',", "// 'tblA.userID',\n"), eraser, "tblA.userID has a foreign key"),
        ("a stale NOT_AN_ACCOUNT entry is reported",
         schema.replace("`supersededByID` INT NULL", "`supersededBy` INT NULL"), merge, eraser, "tblB.supersededByID is on AccountMerge::NOT_AN_ACCOUNT"),
        ("a stale POLYMORPHIC entry is reported",
         schema.replace("`assigneeValue` VARCHAR(100)", "`assigneeText` VARCHAR(100)"), merge, eraser, "tblB.assigneeValue is on AccountMerge::POLYMORPHIC"),
        ("a dropped table's columns stop counting (the listed one is then stale)",
         schema + "DROP TABLE IF EXISTS `tblC`;\n", merge, eraser, "tblC.ownerRef is listed"),
        ("a rename is refused rather than guessed",
         schema + "ALTER TABLE `tblA` RENAME COLUMN `note` TO `notes`;\n", merge, eraser, "RENAME or CHANGE COLUMN"),
        ("a column NAME in a comment is not a column",
         schema + "-- `strayUserID` INT is mentioned in prose only\n", merge, eraser, ""),
        ("a foreign key inside a string is not a key",
         schema + "SET @s = 'ALTER TABLE `tblB` ADD CONSTRAINT `x` FOREIGN KEY (`assigneeValue`) REFERENCES `tblUsers` (`userID`)';\n",
         merge, eraser, ""),
        ("an unreadable LINK_COLUMNS is reported, not passed",
         schema, merge, "class X {}", "LINK_COLUMNS could not be read"),
    ]
    passed = failed = 0
    for label, sql, php, er, expected in cases:
        got = findings(sql, php, er)
        ok = (got == []) if expected == "" else any(expected in g for g in got)
        if ok:
            passed += 1
            print(f"  ok   {label}")
        else:
            failed += 1
            print(f"  FAIL {label}: got {got!r}")
    print(f"{passed} of {len(cases)} passed")
    return 0 if failed == 0 and passed == len(cases) else 1


if __name__ == "__main__":
    if "--self-test" in sys.argv[1:]:
        sys.exit(self_test())
    sys.exit(run_check())
