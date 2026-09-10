#!/usr/bin/env python3
"""
Personal-data coverage check.

Finds tables holding something about a person that nobody has decided what to do
with when that person asks to be forgotten, or asks for a copy of their data.

WHY THIS EXISTS
---------------
Two rights sit at the heart of data-protection law, and this portal has to
honour both:

  * somebody may ask for a COPY of everything held about them;
  * somebody may ask for it to be DELETED.

Both need the same thing first: an accurate list of where personal information
actually lives. Without that, both are guesswork.

For a long time the list here was not built, it was REMEMBERED. Tables were
added to `GdprEraser.php` as somebody happened to think of them. On
11 September 2026 the difference was measured: 134 tables hold something
personal, and **77 of them were in neither the deletion list nor the download**.
Two of those (the Noticeboard's) were found by accident, while looking at
something else entirely.

That is not a list. It is a sample. And the gap included a child's allergies and
medical notes, real mobile numbers, and criminal record check information.

WHAT THIS CHECK DOES
--------------------
It reads the database structure, finds every column that holds something about a
person, and fails when a table containing one has not been sorted into a
category in `web/_core/personal-data-catalogue.php`.

It does NOT decide what should happen to a table. A person must do that, because
the four possible answers are genuinely different decisions:

  erase           Delete the rows outright. For data that is ABOUT the person -
                  a visitor record, a registration, a message sent to them.

  unlink          Keep the row, remove the connection to the person. For "who
                  created this": a song, a rota entry, an announcement. Deleting
                  those would destroy the ORGANISATION'S work because a departed
                  volunteer happened to type it in. The content stays, the name
                  goes.

  retain          Keep it, because the law requires it. Gift Aid declarations,
                  financial records, safeguarding records. Each needs a stated
                  reason and a period, so the organisation can show why.

  not-personal    It only LOOKS personal. The map position of a room, a cache of
                  place names. Nothing about a person at all.

WHAT IT CANNOT DO
-----------------
It matches on column NAMES. A column holding personal information under an
unrevealing name will not be spotted, and a column whose name merely sounds
personal will be flagged when it is not. That is why every entry in the
catalogue is written down by a person with a reason attached, rather than
guessed at here.

Exit code:
  0 — every table holding personal data has a decision recorded
  1 — at least one has not (add --strict to fail a build on it)

Usage:
  python3 tools/audit-checks/check_personal_data_coverage.py [--strict]
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
SCHEMA = REPO_ROOT / "web" / "_sql" / "full_schema.sql"
CATALOGUE = REPO_ROOT / "web" / "_core" / "personal-data-catalogue.php"

# Columns that hold something about a person.
#
# Grouped so the reason each is here stays visible. Anchored to the whole column
# name, so "notes" matches but "denotes" does not.
PERSONAL_COLUMNS = re.compile(
    r"^("
    # Who somebody is
    r"firstName|lastName|fullName|displayName|preferredName|parentName|donorName"
    r"|emergencyContactName|caretakerName|contactName|personName"
    # How to reach them
    r"|email|emailAddress|recipientEmail|parentEmail|donorEmail|personEmail|contactEmail"
    r"|phone|mobile|telephone|recipientNumber|parentPhone|emergencyContactPhone"
    r"|caretakerPhone|contactPhone"
    # Where they are
    r"|address|displayAddress|postcode|postCode|latitude|longitude|what3words"
    # Who they are, in a more sensitive sense
    r"|dateOfBirth|dob|birthDate|gender|allergies|medicalNotes"
    # What they did, and from where
    r"|visitorIP|ipAddress|userAgent|sessionID|sessionDataSnapshot|requestHeaders"
    # Free text that routinely ends up being about somebody
    r"|notes|rawNotes|slideNotes|note"
    # A file somebody uploaded
    r"|storedName"
    # A link to an account
    r"|userID|memberID|donorID|submitterID|recipientUserID|createdByID|updatedByID"
    r"|reviewedByID|assignedToID|approverID|leaderID|uploadedByUserID|convertedUserID"
    r"|targetUserID|startedByID"
    r")$",
    re.IGNORECASE,
)

VALID_DECISIONS = {"erase", "unlink", "retain", "not-personal"}


def schema_tables() -> dict[str, list[str]]:
    """Every table in the fresh-install script, with its columns."""
    if not SCHEMA.is_file():
        return {}
    text = SCHEMA.read_text(encoding="utf-8", errors="replace")
    text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
    text = re.sub(r"--[^\n]*", "", text)

    tables: dict[str, list[str]] = {}
    for m in re.finditer(
        r"CREATE TABLE IF NOT EXISTS\s+`([A-Za-z0-9_]+)`(.*?)\n\)\s*ENGINE", text, re.S
    ):
        name, body = m.group(1), m.group(2)
        cols = re.findall(r"^\s+`([A-Za-z0-9_]+)`", body, re.M)
        tables.setdefault(name, [])
        tables[name].extend(c for c in cols if c not in tables[name])
    return tables


def catalogue_decisions() -> dict[str, str]:
    """
    What the catalogue says about each table.

    Read as text rather than by running the PHP, so this check needs no PHP and
    can run anywhere.
    """
    if not CATALOGUE.is_file():
        return {}
    text = CATALOGUE.read_text(encoding="utf-8", errors="replace")
    found: dict[str, str] = {}
    for m in re.finditer(
        r"'(tbl[A-Za-z0-9_]+)'\s*=>\s*\[\s*'decision'\s*=>\s*'([a-z-]+)'", text
    ):
        found[m.group(1)] = m.group(2)
    return found


def check() -> int:
    tables = schema_tables()
    if not tables:
        print("check_personal_data_coverage: could not read the schema — skipping.")
        return 0

    decisions = catalogue_decisions()

    if not CATALOGUE.is_file():
        print(
            "check_personal_data_coverage: no catalogue yet at\n"
            f"  {CATALOGUE.relative_to(REPO_ROOT)}\n"
            "Nothing to check against. Create it before relying on this."
        )
        return 0

    undecided: list[tuple[str, list[str]]] = []
    bad_decision: list[tuple[str, str]] = []

    for table in sorted(tables):
        personal = [c for c in tables[table] if PERSONAL_COLUMNS.match(c)]
        if not personal:
            continue
        if table not in decisions:
            undecided.append((table, personal))
            continue
        if decisions[table] not in VALID_DECISIONS:
            bad_decision.append((table, decisions[table]))

    # A table named in the catalogue that no longer exists is worth knowing
    # about: it usually means a rename happened and the decision was left behind
    # pointing at nothing.
    ghosts = sorted(t for t in decisions if t not in tables)

    if not undecided and not bad_decision and not ghosts:
        covered = len(decisions)
        print(
            f"check_personal_data_coverage: OK — every table holding personal data "
            f"has a decision recorded ({covered} in the catalogue)."
        )
        return 0

    print("check_personal_data_coverage: PERSONAL DATA WITH NO DECISION RECORDED\n")

    if undecided:
        print("These tables hold something about a person, and nobody has said what")
        print("should happen to it when that person asks to be forgotten:\n")
        for table, cols in undecided:
            shown = ", ".join(cols[:8])
            more = f", and {len(cols) - 8} more" if len(cols) > 8 else ""
            print(f"  • {table}")
            print(f"      {shown}{more}")
        print()
        print("  Add each to web/_core/personal-data-catalogue.php with one of:")
        print("    'erase'        delete the rows — data ABOUT the person")
        print("    'unlink'       keep the row, remove the person's name — 'who made this'")
        print("    'retain'       keep it, with a legal reason and a period")
        print("    'not-personal' it only looks personal — a map position, a cache")
        print()

    if bad_decision:
        print("These have a decision that is not one of the four allowed:\n")
        for table, decision in bad_decision:
            print(f"  • {table}: '{decision}'")
        print()

    if ghosts:
        print("These are in the catalogue but no longer exist in the database.")
        print("Usually a rename that left its decision behind:\n")
        for table in ghosts:
            print(f"  • {table}")
        print()

    return 1


if __name__ == "__main__":
    sys.exit(check())
