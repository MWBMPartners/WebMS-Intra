#!/usr/bin/env python3
"""
The lines of free text a PERSON decides about are exactly the agreed seven, and
every one of them can actually be emptied (#724).

Path: tools/audit-checks/check_erasure_review_items.py

WHY THIS EXISTS
---------------
Seven columns in this portal hold free text that no automatic erasure rule
touches, because whether the text matters depends on the request in front of
you: "Jane's laptop" on a licence seat is somebody's name, and "spare projector"
on the next row is not. The owner decided on 3 November 2026 that a person
holding the `data_controller` role looks at each such line and chooses — empty
it, or keep it — and that an erasure request CANNOT CLOSE while one is
undecided.

`web/_core/personal-data-catalogue.php` is where that is written down: a column
is marked by its entry's `needsReview` list. `Portal\\Core\\ErasureReview` reads
those marks and never keeps a list of its own, so the review and the catalogue
cannot drift. What could still drift is the MARKS themselves, and that is what
this check is for.

WHAT IT FAILS ON — AND IT FAILS BOTH WAYS
-----------------------------------------
  1. A column marked that is not one of the agreed seven. A new mark is a new
     decision about somebody's data, and it needs the owner, not a commit.
  2. One of the seven that has LOST its mark. That is the silent direction: the
     line simply stops being offered, the erasure stops refusing, and a request
     closes with the text in place and nothing saying so.
  3. A marked column its own entry's `columns` list does not name — a stale
     mark, usually left behind by a rename.
  4. A marked column with no `notEmptiedHere` reason beside it. The review page
     shows that reason as "why no rule decides this", so without one somebody is
     asked to judge a line with no explanation.
  5. A marked column that is not in `full_schema.sql` at all.
  6. A marked column declared NOT NULL. "Empty it" sets the column to NULL, and
     A NOT NULL COLUMN CANNOT BE EMPTIED — the refusal is quiet and the decision
     would be recorded over text that is still there. This is #715's lesson and
     the same gate `check_erasure_columns_nullable.py` is for the written
     erasure instructions, which does NOT cover these columns: it reads
     `nullCols` lists out of `GdprEraser.php`, and a reviewable column is
     emptied by `ErasureReview::record()` instead.

HOW IT READS THE CATALOGUE, AND WHY NOT THE OBVIOUS WAY
-------------------------------------------------------
Entry by entry, from each entry's own header to the NEXT header, parsing no
structure at all — the same way `check_personal_data_coverage.py` does, and for
the reason recorded there: a bracket-counting parser was BROKEN THE DAY IT WAS
WRITTEN by one unbalanced `[` inside a comment, and one entry's "block" then ran
over 144,000 characters of the file. Stripping comments first is also wrong,
because a reason may legitimately contain `//`, as any web address does.

WHAT IT CANNOT SEE (said plainly, because a check that reports nothing is easily
taken to mean there is nothing)
-------------------------------------------------------------------------------
  - WHETHER THE SEVEN ARE THE RIGHT SEVEN. It pins the owner's decision of
    3 November 2026; it cannot judge it. An eighth column that should be
    reviewed and is marked nowhere is invisible here, exactly as it is invisible
    to the review itself.
  - WHETHER A MARKED LINE CAN ACTUALLY BE REACHED for a given person. That
    depends on the row having one of the person's account columns on it, which
    only a real database can answer. `tools/erasure-review-selftest.php` does
    that against a real one.
  - WHETHER `ErasureReview` KEEPS A SECOND LIST. It checks only that the class
    reads the catalogue file at all. Proving no hand-written list hides in there
    would mean parsing PHP, including its comments — and the class's own
    docblock legitimately names one of the seven tables while explaining what
    the review cannot reach.
  - A MIGRATION THAT CHANGES A COLUMN LATER. It reads `full_schema.sql`, which
    is what a fresh install builds. The standing rule that a migration and the
    install script must agree, and the end-to-end migration harness, cover that.

Exit code:
  0 — the marked set is exactly the agreed seven and each can be emptied
  1 — at least one problem

Usage:
  python3 tools/audit-checks/check_erasure_review_items.py
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
CATALOGUE = REPO_ROOT / "web" / "_core" / "personal-data-catalogue.php"
SCHEMA = REPO_ROOT / "web" / "_sql" / "full_schema.sql"
REVIEW_CLASS = REPO_ROOT / "web" / "_core" / "ErasureReview.php"

# =============================================================================
# 📌 THE OWNER'S DECISION OF 3 NOVEMBER 2026, PINNED
# =============================================================================
# Exactly these lines of free text are put in front of a person. Adding one is a
# decision about somebody's personal data and belongs to the owner; taking one
# away is the silent direction and is why this list is compared BOTH WAYS.
#
# ⚠️ THREE OF THESE ARE NOT NAMED AS THE ISSUE NAMES THEM, and the right name is
#    the one the database uses. #724 lists `tblServicePlans.presenterText`,
#    `tblAssetLicenseSeats.*` and `tblSalvationCard.prayerRequest`. There is no
#    `presenterText` on `tblServicePlans` and no `tblAssetLicenseSeats` or
#    `tblSalvationCard` table at all. The columns live on `tblServicePlanItem`,
#    `tblAssetLicenseAssignments` and `tblSalvationCards`, which is what the
#    catalogue and the schema say. That is the "name things by the right
#    identifier" trap this repository has paid for before, and the whole reason
#    this list is checked against the schema rather than read as prose.
AGREED = {
    ("tblResourceBooking", "notes"),
    ("tblServicePlanItem", "presenterText"),
    ("tblRecording", "presenterText"),
    ("tblAssetLicenseAssignments", "deviceName"),
    ("tblAssetLicenseAssignments", "seatLabel"),
    ("tblSmallGroups", "meetingNotes"),
    ("tblSalvationCards", "prayerRequest"),
}


def catalogue_blocks(text: str) -> dict[str, str]:
    """table -> the text of its entry, header to next header. No structure is
    parsed; see this file's docstring for why not."""
    heads = list(re.finditer(r"\n    '(tbl[A-Za-z0-9_]+)' => \[\n", text))
    out: dict[str, str] = {}
    for n, head in enumerate(heads):
        stop = heads[n + 1].start() if n + 1 < len(heads) else len(text)
        out[head.group(1)] = text[head.end():stop]
    return out


def marked_columns(blocks: dict[str, str]) -> set[tuple[str, str]]:
    found: set[tuple[str, str]] = set()
    for table, block in blocks.items():
        hit = re.search(r"'needsReview'\s*=>\s*\[([^\]]*)\]", block)
        if hit is None:
            continue
        for column in re.findall(r"'([A-Za-z0-9_]+)'", hit.group(1)):
            found.add((table, column))
    return found


def named_columns(block: str) -> set[str]:
    hit = re.search(r"'columns'\s*=>\s*\[([^\]]*)\]", block)
    if hit is None:
        return set()
    return set(re.findall(r"'([A-Za-z0-9_]+)'", hit.group(1)))


def explained_columns(block: str) -> set[str]:
    hit = re.search(r"'notEmptiedHere'\s*=>\s*\[(.*)", block, re.S)
    if hit is None:
        return set()
    return set(re.findall(r"^\s+'([A-Za-z0-9_]+)'\s*=>", hit.group(1), re.M))


def column_declaration(schema: str, table: str, column: str) -> str | None:
    """The column's declaration text, or None when table or column is absent."""
    tm = re.search(
        r"CREATE TABLE(?:\s+IF NOT EXISTS)?\s*`" + re.escape(table) + r"`\s*\((.*?)\n\)\s*ENGINE",
        schema,
        re.S | re.I,
    )
    if tm is None:
        return None
    cm = re.search(r"^\s*`" + re.escape(column) + r"`\s+([^,\n]*)", tm.group(1), re.M | re.I)
    if cm is None:
        return None
    return cm.group(1)


def check() -> int:
    for path in (CATALOGUE, SCHEMA, REVIEW_CLASS):
        if not path.is_file():
            print(f"check_erasure_review_items: {path} is not a file. Nothing was checked.")
            return 1

    text = CATALOGUE.read_text(encoding="utf-8", errors="replace")
    schema = SCHEMA.read_text(encoding="utf-8", errors="replace")
    blocks = catalogue_blocks(text)
    if not blocks:
        print("check_erasure_review_items: no catalogue entry could be read at all. Either the file was")
        print("restructured or this check is looking in the wrong place; it is not safe to report success.")
        return 1

    marked = marked_columns(blocks)
    problems: list[str] = []

    for table, column in sorted(marked - AGREED):
        problems.append(
            f"`{table}`.`{column}` is marked `needsReview` and is not one of the seven the owner agreed on "
            f"3 November 2026. Putting a line of free text in front of a person to judge is a decision about "
            f"somebody's personal data: get it decided, then add it to AGREED in this file"
        )

    for table, column in sorted(AGREED - marked):
        problems.append(
            f"`{table}`.`{column}` is one of the agreed seven and is NOT marked `needsReview` any more. This is "
            f"the silent direction: the line stops being offered for review, the erasure stops refusing to "
            f"close, and the request completes with that text in place and nothing saying so"
        )

    for table, column in sorted(marked):
        block = blocks.get(table, "")
        if column not in named_columns(block):
            problems.append(
                f"`{table}`.`{column}` is marked for review but the entry's own `columns` list does not name "
                f"it, so the mark is stale — usually a rename. `ErasureReview::markedColumns()` leaves such a "
                f"column OUT, so it would silently stop being reviewed"
            )
        if column not in explained_columns(block):
            problems.append(
                f"`{table}`.`{column}` is marked for review with no `notEmptiedHere` reason beside it. The "
                f"review page shows that reason as \"why no rule decides this\", so without one somebody is "
                f"asked to judge a line of somebody else's words with no explanation at all"
            )

        declaration = column_declaration(schema, table, column)
        if declaration is None:
            problems.append(
                f"`{table}`.`{column}` is marked for review and the install script has no such table or "
                f"column. Either the name is wrong or the install script is behind"
            )
            continue
        if re.search(r"\bNOT\s+NULL\b", declaration, re.I) is not None:
            problems.append(
                f"`{table}`.`{column}` is marked for review and the install script declares it NOT NULL, so "
                f"\"empty it\" CANNOT empty it. The refusal is quiet, which is #715's lesson: make the column "
                f"nullable in BOTH full_schema.sql and a numbered migration"
            )

    if "personal-data-catalogue.php" not in REVIEW_CLASS.read_text(encoding="utf-8", errors="replace"):
        problems.append(
            "web/_core/ErasureReview.php does not read personal-data-catalogue.php at all, so the list of what "
            "needs reviewing is no longer catalogue-driven. That was the whole design: a second list in code "
            "drifts from the decisions beside the columns"
        )

    if not problems:
        print(
            f"check_erasure_review_items: PASS - {len(marked)} column(s) are marked for review, they are "
            f"exactly the seven agreed on 3 November 2026, each is named and explained by its own catalogue "
            f"entry, and each can be emptied."
        )
        return 0

    print(f"check_erasure_review_items: FAIL - {len(problems)} problem(s).\n")
    for problem in problems:
        print(f"  - {problem}\n")
    print("Why it matters: an erasure request cannot be completed while one of these lines is undecided, so a")
    print("mark that disappears does not break anything loudly — it just stops a person being asked, and the")
    print("text stays. A mark that appears without a decision puts somebody else's words in front of a reader.")
    return 1


if __name__ == "__main__":
    sys.exit(check())
