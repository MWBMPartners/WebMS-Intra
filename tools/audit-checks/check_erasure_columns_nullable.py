#!/usr/bin/env python3
"""
A column an erasure must empty can actually be emptied (#510, #535, #626).

Path: tools/audit-checks/check_erasure_columns_nullable.py

WHY THIS EXISTS
---------------
When somebody asks to be erased, `GdprEraser::catalogue()` holds the written
instructions. An instruction may name extra columns in `nullCols` — columns
that hold the person's own details and must be emptied even though they are not
the link to their account: an address, a browser name, request headers, a copy
of their session.

Emptying a column means setting it to NULL. **A column declared NOT NULL cannot
be set to NULL**, and the eraser asks the database first, so it does not crash:
it adds the column to a "refused" list, writes a line in the audit trail saying
what it could not empty, and carries on. The step is not counted as a failure,
so the request can be recorded as COMPLETED with that person's details still in
the table.

That is the right behaviour for a column that is only a LINK to an account —
the account itself has been emptied, so the row no longer names anybody. It is
the wrong behaviour for a column holding the person's own details, and nothing
tells the two apart. Found by the Codex review of GdprEraser.php on
27 October 2026.

Today exactly one `nullCols` column is NOT NULL — `tblUsers.emailAddress` — and
the eraser handles it on purpose: it writes a made-up address over the real one
and takes the column back off the refused list, with a comment recording that
the audit line used to claim it had been left alone. So there is nothing to fix
in the code. This check exists so that it STAYS that way: the next person to add
a NOT NULL column to a `nullCols` list would otherwise leave personal data
behind, and find out from nothing but an audit line nobody reads.

WHAT IT LOOKS FOR
-----------------
Every `nullCols` list in `web/_core/GdprEraser.php`, with the table its
instruction names. For each column it finds that table in
`web/_sql/full_schema.sql` and fails if the column is declared NOT NULL, unless
the pair is listed in HANDLED_ANOTHER_WAY below with its reason.

WHAT IT CANNOT SEE (said plainly, because a check that reports nothing is
easily taken to mean there is nothing)
-------------------------------------------------------------------------
  - A COLUMN THE DERIVED ROUTE EMPTIES. It reads only the written `nullCols`
    lists. Columns worked out from personal-data-catalogue.php are not checked.
  - WHETHER THE COLUMN SHOULD BE IN THE LIST AT ALL. It checks that what the
    list names can be emptied, not that the list is complete — that is what
    tools/gdpr-coverage-selftest.php is for.
  - A MIGRATION THAT CHANGES A COLUMN LATER. It reads full_schema.sql, which is
    what a fresh install builds. A migration that makes a column NOT NULL
    without changing the install script would pass here and fail on a real
    upgraded database — but the project rule that both must agree, and the
    end-to-end migration test, already cover that.
"""

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

# Pairs that ARE NOT NULL and are dealt with another way. Each needs its reason.
HANDLED_ANOTHER_WAY = {
    ("tblUsers", "emailAddress"): (
        "the eraser writes a made-up address over it instead of emptying it, and takes it "
        "back off the refused list so the audit trail does not claim it was left alone "
        "(see the comment beside TOMBSTONE_EMAIL in GdprEraser.php)"
    ),
}


def entries_with_nullcols(src: str) -> list[tuple[str, list[str], int]]:
    """
    Every written instruction that names a table and a non-empty nullCols list.

    HOW, AND WHY NOT THE OBVIOUS WAY. The first version of this matched a whole
    instruction — ['table' => '...', ... ] — with one non-greedy pattern and
    then looked inside it for nullCols. It found ONE of the five lists and
    reported "PASS, all 12 columns checked", because an instruction spans
    several lines and holds nested brackets and apostrophes, so the pattern
    stopped early almost every time. A planted fault caught it. Two lessons
    kept here on purpose: a check that parses less than it claims is worse than
    no check, and the only way to know is to break the code and watch.

    So it works the other way round: find each nullCols list, then walk
    BACKWARDS to the nearest 'table' => '...' before it. That cannot overrun,
    because every list belongs to the instruction it sits inside.
    """
    found = []
    for m in re.finditer(r"'nullCols'\s*=>\s*\[([^\]]*)\]", src):
        cols = re.findall(r"'(\w+)'", m.group(1))
        if not cols:
            continue  # an empty list names nothing to empty
        before = src[: m.start()]
        tm = None
        for tm in re.finditer(r"'table'\s*=>\s*'(\w+)'", before):
            pass  # the LAST one before this list is the instruction it belongs to
        if tm is None:
            continue
        line = before.count("\n") + 1
        found.append((tm.group(1), cols, line))
    return found


def column_is_not_null(schema: str, table: str, column: str) -> bool | None:
    """True/False, or None when the table or column is not in the install script."""
    tm = re.search(r"CREATE TABLE[^`]*`" + re.escape(table) + r"`\s*\((.*?)\n\) ENGINE", schema, re.S | re.I)
    if tm is None:
        return None
    cm = re.search(r"^\s*`" + re.escape(column) + r"`\s+([^,\n]*)", tm.group(1), re.M | re.I)
    if cm is None:
        return None
    return re.search(r"\bNOT\s+NULL\b", cm.group(1), re.I) is not None


def check(eraser: Path, schema_file: Path) -> int:
    src = eraser.read_text(encoding="utf-8", errors="replace")
    schema = schema_file.read_text(encoding="utf-8", errors="replace")

    entries = entries_with_nullcols(src)
    if not entries:
        print("check_erasure_columns_nullable: found no nullCols list at all. Either the written")
        print("instructions were restructured or this check is looking in the wrong place; it is")
        print("not safe to report success.")
        return 1

    problems: list[str] = []
    checked = 0
    for table, cols, line in entries:
        for column in cols:
            state = column_is_not_null(schema, table, column)
            if state is None:
                problems.append(
                    f"GdprEraser.php:{line} tells an erasure to empty `{table}`.`{column}`, "
                    f"and the install script has no such table or column. Either the name is "
                    f"wrong or the install script is behind"
                )
                continue
            checked += 1
            if state is False:
                continue
            if (table, column) in HANDLED_ANOTHER_WAY:
                continue
            problems.append(
                f"GdprEraser.php:{line} tells an erasure to empty `{table}`.`{column}`, but the "
                f"install script declares it NOT NULL, so it CANNOT be emptied. The erasure will "
                f"record a line saying so and carry on, and the request can still be marked "
                f"completed with that person's details in place. Either make the column nullable "
                f"in BOTH full_schema.sql and a numbered migration, or deal with it another way "
                f"and add it to HANDLED_ANOTHER_WAY in this file with the reason"
            )

    if not problems:
        print(
            f"check_erasure_columns_nullable: PASS - all {checked} columns the written erasure "
            f"instructions must empty can be emptied."
        )
        return 0

    print(f"check_erasure_columns_nullable: FAIL - {len(problems)} problem(s).\n")
    for problem in problems:
        print(f"  - {problem}\n")
    print("Why it matters: a person told their data was erased, with some of it still there, is the")
    print("worst outcome this part of the portal has. A column that cannot be emptied fails quietly.")
    return 1


def main(argv: list[str]) -> int:
    eraser = REPO_ROOT / "web" / "_core" / "GdprEraser.php"
    schema = REPO_ROOT / "web" / "_sql" / "full_schema.sql"
    for i, arg in enumerate(argv):
        if arg == "--eraser" and i + 1 < len(argv):
            eraser = Path(argv[i + 1]).resolve()
        if arg == "--schema" and i + 1 < len(argv):
            schema = Path(argv[i + 1]).resolve()
    for path in (eraser, schema):
        if not path.is_file():
            print(f"check_erasure_columns_nullable: {path} is not a file. Nothing was checked.")
            return 1
    return check(eraser, schema)


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
