#!/usr/bin/env python3
"""
Path: tools/audit-checks/check_series_attachments_complete.py

Every table that points at an event must be named in SeriesRepattern (#669)
=============================================================================

WHAT THIS GUARDS, AND WHY IT IS THE MOST IMPORTANT PART OF #669
-----------------------------------------------------------------------------
`Portal\\Core\\SeriesRepattern::ATTACHMENTS` is the list of every table holding
a row that points at an event. Changing a repeating series' pattern removes
dates the new pattern no longer wants, and that list is the ONLY thing stopping
it removing a date that carries somebody's attendance, a booked room, a child's
check-in or a registration.

So the constant is the worst possible shape for a safety list: it LOOKS complete
whatever is in it. Add a new table with an `eventID` foreign key and say nothing,
and its rows become invisible to the repattern page — the first pattern change
after that ships quietly hides the dates they hang off. Nothing would fail, and
nothing would be logged, because from the page's point of view the date had
nothing attached.

This check is what makes the constant trustworthy. It compares it against
`web/_sql/full_schema.sql`, which is the schema a fresh install really gets.

IT FAILS BOTH WAYS, deliberately
-----------------------------------------------------------------------------
  • A table in the schema with an `eventID` foreign key to `tblEvents` that is
    NOT in the constant — the dangerous direction above.
  • An entry in the constant naming a table that no longer has such a key — the
    direction that makes a list rot quietly. The migration harness's own
    exception lists went stale exactly that way and nobody noticed, which is
    why both halves are checked here rather than just the one that bites.

A table that genuinely must be left out goes in LEFT_OUT_ON_PURPOSE below with
its reason, and that list is checked too: an entry there that has stopped being
true fails as well.

TWO PARSING TRAPS THIS SCRIPT HANDLES, BOTH FOUND BY GETTING THEM WRONG FIRST
-----------------------------------------------------------------------------
  0. `INT` before `INTEGER` in the type alternation. `INTEGER` is a real MySQL
     type name, and with `INT` listed first the regex matched its leading three
     letters and then failed a word boundary against the "E" — so an eventID
     column declared INTEGER NOT NULL was not seen as a declaration at all, and
     its table vanished from the comparison. Nothing in this schema uses `INTEGER` today, which is exactly
     why it would have gone unnoticed.

  1. A foreign key is not always written inside its own CREATE TABLE. This
     schema also declares one through `ALTER TABLE` — `tblAttendanceSessions`
     gets its `eventID` key that way, inside a quoted statement in the file's
     tail. A first draft of this parser walked backwards to the nearest
     `CREATE TABLE` and therefore attributed that key to whichever table had
     been created last, reporting `tblEvents` as pointing at itself AND missing
     `tblAttendanceSessions` entirely. The real list was wrong in both
     directions at once.
  2. Comments must be stripped BEFORE anything is matched, or a commented-out
     foreign key counts as a live one. That is the #675 lesson applied here:
     a key inside a `--` comment was being read as real in a different check,
     and the silent direction is the one that matters.

A TABLE DOES NOT NEED A FOREIGN KEY TO POINT AT AN EVENT, and the first
version of this check assumed it did
-----------------------------------------------------------------------------
The first version read the schema's FOREIGN KEY declarations, and this
docstring said in so many words: "A table that points at an event WITHOUT a
foreign key. Nothing in this schema does that today (checked)." **That claim
was written without checking and it was false.** The independent check of
31 October 2026 asked the database and found two:

  • `tblEventRSVPs` — `eventID INT NOT NULL`, no foreign key, carrying
    `uq_event_user (eventID, userID)`. It holds every member's
    going/maybe/no answer, which is one of the things the owner's decision
    explicitly names. A pattern change was offering to remove a date with
    those answers against it.
  • `tblZoomMeeting` — `eventID INT NULL`, no foreign key, written by the
    seeded `/calendar/zoom-create`.

So this check now looks for the `eventID` COLUMN, which is what "points at an
event" actually means. A foreign key is how most of them say it, not how all
of them do, and keying the safety list on the tidier half of the schema is how
the two most sensitive tables came to be missing from it.

ONE EXCLUSION, and it is a real one: a table whose own primary key is called
`eventID` is not pointing at anything. `tblWebhookEvent.eventID` is its own
`AUTO_INCREMENT` primary key.

HOW THAT EXCLUSION IS DECIDED, stated because an earlier wording understated it:
the word `AUTO_INCREMENT` on the column's own declaration. That is a text test,
not a reading of the table's keys — so it would also exclude a table that merely
MENTIONED the word in a comment or a string on the same line, which is why
`strip_sql_comments()` blanks both before anything is matched. A table with a
foreign key to `tblEvents` is never excluded this way, whatever its own key is
called.

WHAT IT STILL CANNOT SEE
  • Whether the WORDS beside each table are accurate. It checks that a table is
    named, not that "attendance has been marked" is a fair description of it.
  • A table added by a numbered migration and not folded into
    `full_schema.sql`. That is a different fault, and
    `check_schema_seed_parity.py` is what catches it.

Exit codes: 0 — the two lists agree. 1 — they do not.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
SCHEMA = REPO_ROOT / "web" / "_sql" / "full_schema.sql"
CLASS_FILE = REPO_ROOT / "web" / "_core" / "SeriesRepattern.php"

# Tables with an eventID foreign key that are deliberately NOT in the
# constant. Each needs a reason, and an entry that has stopped being true
# fails this check — see the docstring.
#
# EMPTY ON PURPOSE. Every table that points at an event is listed in the
# constant, because the owner's decision of 31 October 2026 is that a future
# date with ANYTHING attached is left alone. There is no table whose rows are
# cheap enough to lose.
LEFT_OUT_ON_PURPOSE: dict[str, str] = {}


def strip_sql_comments(text: str) -> str:
    """
    Blank out `--` line comments, `/* */` blocks AND single-quoted string
    literals, keeping line numbering so a finding can still be reported against
    the right line.

    Done BEFORE any matching: see trap 2 in the docstring.

    THE STRING LITERALS MATTER AS MUCH AS THE COMMENTS, and leaving them in was
    a measured false negative. Every column in this schema carries a `COMMENT
    '...'`, and the exclusion below keys on the word `AUTO_INCREMENT` appearing
    on the column's own line — so a column declared

        `eventID` INT NOT NULL COMMENT 'points at the AUTO_INCREMENT key of tblEvents'

    excluded its whole table from the list, silently. That is the dangerous
    direction: the table simply disappears from the comparison and the check
    still passes. Found by the independent check of 31 October 2026, which
    planted exactly that line and watched this script exit 0.

    `''` inside a literal is SQL's own escape for a quote and is handled by
    treating it as two adjacent literals, which is harmless here: both are
    blanked either way.
    """
    text = re.sub(r"/\*.*?\*/", lambda m: re.sub(r"[^\n]", " ", m.group(0)), text, flags=re.S)
    out = []
    for line in text.split("\n"):
        cut = line.find("--")
        line = line if cut < 0 else line[:cut]
        # Blank what is inside single quotes, keeping the line's length so the
        # reported line numbers stay right.
        line = re.sub(r"'[^'\n]*'", lambda m: "'" + " " * (len(m.group(0)) - 2) + "'", line)
        out.append(line)
    return "\n".join(out)


def tables_pointing_at_events(sql: str) -> dict[str, int]:
    """
    Every table with an `eventID` column that refers to an event, mapped to the
    line it is declared on.

    A FOREIGN KEY IS NOT REQUIRED — see the docstring. Two tables in this
    schema name an event with no constraint at all, and they were the two most
    sensitive ones.

    The owner of a declaration is whichever `ALTER TABLE` or `CREATE TABLE` is
    CLOSEST above it, not whichever CREATE TABLE came last — see trap 1. That
    matters for the `ADD COLUMN` form as well as the `ADD CONSTRAINT` one.
    """
    owners: dict[str, int] = {}
    own_key: set[str] = set()

    # Where each CREATE/ALTER names a table, by character offset.
    anchors: list[tuple[int, str]] = []
    for m in re.finditer(
        r"\b(?:CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|ALTER\s+TABLE)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?",
        sql,
        re.I,
    ):
        anchors.append((m.start(), m.group(1)))

    def owner_of(offset: int) -> str | None:
        found = None
        for at, name in anchors:
            if at < offset:
                found = name
            else:
                break
        return found

    # 1️⃣ Every DECLARATION of a column called eventID — in a CREATE TABLE body
    #    or through ALTER TABLE ... ADD COLUMN. `\beventID\b` followed by a
    #    type is what tells a declaration from a mention in a key or a
    #    constraint.
    for col in re.finditer(
        r"`?\beventID\b`?\s+(?:INTEGER|INT|BIGINT|SMALLINT|MEDIUMINT|TINYINT)\b",
        sql,
        re.I,
    ):
        owner = owner_of(col.start())
        if owner is None or owner == "tblEvents":
            continue
        line = sql.count("\n", 0, col.start()) + 1
        owners.setdefault(owner, line)
        # A table whose OWN primary key is called eventID is not pointing at
        # anything. AUTO_INCREMENT on the same declaration is the tell.
        if re.search(r"AUTO_INCREMENT", col.group(0), re.I) is not None:
            own_key.add(owner)

    # 2️⃣ The same declarations again, this time to catch AUTO_INCREMENT, which
    #    sits after the type rather than inside the match above.
    for col in re.finditer(
        r"`?\beventID\b`?\s+(?:INTEGER|INT|BIGINT|SMALLINT|MEDIUMINT|TINYINT)[^,\n]*AUTO_INCREMENT",
        sql,
        re.I,
    ):
        owner = owner_of(col.start())
        if owner is not None:
            own_key.add(owner)

    # 3️⃣ And every FOREIGN KEY to tblEvents, because a key can be added by
    #    ALTER TABLE in a table whose column declaration this file states
    #    somewhere else entirely (tblAttendanceSessions does exactly that).
    for fk in re.finditer(
        r"FOREIGN\s+KEY\s*\(\s*`?(\w+)`?\s*\)\s*REFERENCES\s*`?tblEvents`?\s*\(\s*`?eventID`?\s*\)",
        sql,
        re.I,
    ):
        owner = owner_of(fk.start())
        if owner is None or owner == "tblEvents":
            continue
        line = sql.count("\n", 0, fk.start()) + 1
        owners.setdefault(owner, line)
        # A table with a foreign key TO tblEvents is pointing at an event
        # whatever its own primary key happens to be called.
        own_key.discard(owner)

    for table in own_key:
        owners.pop(table, None)

    return owners


def constant_tables(php: str) -> list[str]:
    """
    The table names keyed in `SeriesRepattern::ATTACHMENTS`.

    Read from the constant's own block rather than from the whole file, so a
    table name mentioned in a comment somewhere else in the class is not
    mistaken for an entry.
    """
    start = php.find("const ATTACHMENTS")
    if start < 0:
        return []
    end = php.find("];", start)
    if end < 0:
        return []
    block = php[start:end]
    # Strip `//` comments inside the block for the same reason the SQL ones go.
    block = "\n".join(line.split("//")[0] for line in block.split("\n"))
    return re.findall(r"'(tbl[A-Za-z0-9_]+)'\s*=>", block)


def main() -> int:
    if SCHEMA.is_file() is False:
        print(f"FAIL: cannot read {SCHEMA}")
        return 1
    if CLASS_FILE.is_file() is False:
        print(f"FAIL: cannot read {CLASS_FILE}")
        return 1

    sql = strip_sql_comments(SCHEMA.read_text(encoding="utf-8"))
    php = CLASS_FILE.read_text(encoding="utf-8")

    in_schema = tables_pointing_at_events(sql)
    in_constant = constant_tables(php)

    if in_constant == []:
        print("FAIL: SeriesRepattern::ATTACHMENTS could not be read, or is empty.")
        print("      That constant is the only thing stopping a pattern change removing a date")
        print("      that carries attendance, a booking or a registration.")
        return 1

    duplicates = sorted({t for t in in_constant if in_constant.count(t) > 1})
    missing = sorted(set(in_schema) - set(in_constant) - set(LEFT_OUT_ON_PURPOSE))
    stale = sorted(set(in_constant) - set(in_schema))
    stale_exemptions = sorted(set(LEFT_OUT_ON_PURPOSE) - set(in_schema))
    pointless_exemptions = sorted(set(LEFT_OUT_ON_PURPOSE) & set(in_constant))

    print(f"Tables naming an event in full_schema.sql:            {len(in_schema)}")
    print(f"Tables named in SeriesRepattern::ATTACHMENTS:          {len(set(in_constant))}")
    if LEFT_OUT_ON_PURPOSE:
        print(f"Left out on purpose:                                  {len(LEFT_OUT_ON_PURPOSE)}")

    problems = 0

    if missing:
        problems += len(missing)
        print("\n### Tables that name an event and are NOT in SeriesRepattern::ATTACHMENTS\n")
        for table in missing:
            print(f"  • {table}  (full_schema.sql:{in_schema[table]})")
        print("\nRows in these tables are INVISIBLE to the series repattern page, so a pattern")
        print("change will hide the dates they hang off without saying so. Add each to the")
        print("constant with the plain words a person would recognise, or to LEFT_OUT_ON_PURPOSE")
        print("in this script with the reason it is safe to lose.")

    if stale:
        problems += len(stale)
        print("\n### Entries in the constant naming a table that does not name an event\n")
        for table in stale:
            print(f"  • {table}")
        print("\nEither the table was renamed or dropped, or its eventID column was. A list that names")
        print("tables which no longer exist stops being read as a real safety list.")

    if stale_exemptions:
        problems += len(stale_exemptions)
        print("\n### LEFT_OUT_ON_PURPOSE names a table that no longer names an event\n")
        for table in stale_exemptions:
            print(f"  • {table} — {LEFT_OUT_ON_PURPOSE[table]}")
        print("\nRemove it: an exemption nobody needs hides the next one that matters.")

    if pointless_exemptions:
        problems += len(pointless_exemptions)
        print("\n### A table is both exempted and listed in the constant\n")
        for table in pointless_exemptions:
            print(f"  • {table}")
        print("\nOne or the other. Being in the constant is what protects it; the exemption")
        print("says it does not need protecting, and both cannot be true.")

    if duplicates:
        problems += len(duplicates)
        print("\n### The same table appears twice in the constant\n")
        for table in duplicates:
            print(f"  • {table}")
        print("\nPHP keeps only the LAST of two identical array keys, so the first set of")
        print("words is silently thrown away.")

    if problems == 0:
        print("\nEvery table that points at an event is accounted for. ✅")
        return 0

    print(f"\n{problems} problem(s). ❌")
    return 1


if __name__ == "__main__":
    sys.exit(main())
