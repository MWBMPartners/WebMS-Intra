#!/usr/bin/env python3
"""
A help page never points at a runbook section that does not exist (#744).

Path: tools/audit-checks/check_help_page_references.py

WHY THIS EXISTS
---------------
The in-app help under `web/_apps/help/` is what an end user actually reads, and
this repository's own plain-English rule says it matters MORE than the developer
notes, not less. **Until 4 November 2026 almost nothing read any of it.** The
only help page under any test was `calendar-imports.php`, pinned by check W50
of `tools/feed-admin-selftest.php`.

What that cost is on record (#583). `help/disaster-recovery.php` told a
customer, under "Stolen credentials", to rotate `enc.key` — which runbook
section 7.9 spends a whole section forbidding, because everything stored with
the old key becomes unreadable at once: every integration's secret, and every
person's two-factor sign-in. Two of its other steps were buttons the portal
does not have. And it pointed at **"runbook section 4.4"**, which had not
existed since #488 reorganised the runbook. The anchor in the link had been
updated; only the words a reader sees were left behind, so the link text and
the page it opened disagreed.

**Somebody reads that page when something has already gone wrong.** Nothing
caught any of it, and the stale section number was found by hand weeks later.

WHAT IT CHECKS, AND WHAT IT DELIBERATELY DOES NOT
-------------------------------------------------
One narrow, mechanical thing: **every runbook section number a help page shows
a reader must be a numbered heading in `docs/disaster-recovery-runbook.md`.**

**IT CANNOT JUDGE WHETHER ANY ADVICE IS CORRECT. No check can.** A help page
telling somebody to delete the wrong folder passes this cleanly. It is a guard
on one kind of staleness, not a statement that the help is verified, and
anybody reading a green run should take it as nothing more.

HOW IT AVOIDS CRYING WOLF (#483), WHICH TOOK MEASURING
------------------------------------------------------
  * **It reads only the text OUTSIDE `<?php ... ?>`** — the inline HTML, which
    is what the reader sees. That matters more than it sounds: measured on
    4 November 2026, `help/disaster-recovery.php` cites "section 4.4" TWICE and
    "section 4" and "section 7" once each, and **every one of those is inside a
    PHP comment** recording the #583 correction — i.e. the record of the fault
    being fixed. A check that read the whole file would fail on the very
    comment that documents the repair.
  * **A bare single-digit "Section 4" is NOT treated as a runbook reference.**
    The help pages carry their own headings "Section 1" through "Section 7",
    which overlap the runbook's single-digit sections exactly. There is no way
    to tell them apart without the word "runbook" nearby, so the word is
    required. Measured: `approvals.php`, `admin.php` and `expenses.php` all use
    those headings.
  * It therefore wants either the word **runbook** within RUNBOOK_WINDOW
    characters before the number, or the word **section** immediately before a
    DOTTED number.
  * **Both branches are needed, measured rather than assumed.** Of the seven
    live references today, one — `section&nbsp;7.7` in
    `help/disaster-recovery.php` — is caught ONLY by the dotted-section branch,
    because the word "runbook" is more than 60 characters before it. Dropping
    that branch to be safer would lose real coverage.
  * **THE COST OF KEEPING IT, stated because it is a cry-wolf risk and #483 is
    the rule that matters most here:** a help page writing "Section 4.9 of this
    page" about ITS OWN structure would be reported, because a dotted number
    after the word "section" is taken to mean the runbook. Measured — it
    really does fail. It costs nothing today: **no help page has a dotted
    heading of its own** (checked across all 20; their headings are "Section 1"
    to "Section 7"). If one ever gains one, write the self-reference without the
    word "section" immediately before it, or this check needs a narrower rule —
    do not simply delete the branch, because that is the branch catching 7.7.

WHAT IT CANNOT SEE, said plainly because an overstated guarantee is worse than
none
---------------------------------------------------------------------------
  * **A reference echoed out of PHP is missed**, because only the inline HTML
    is read. Every live reference today is in the HTML (measured), and reading
    PHP strings would mean stripping comments in Python, where a `//` inside a
    URL has already cost this repository a fault (#716).
  * **It does not follow the link's anchor.** #583's fault was that the anchor
    and the words disagreed; this checks the WORDS. An anchor pointing at a
    heading that has gone would pass.
  * **It says nothing about the other 19 help pages' content.** Whether they
    load at all is `tools/help-pages-selftest.php`; whether what they say is
    true is nobody's check.
  * It reads one runbook. A help page citing a section of some other document
    by number is not looked at.
  * **The window is 60 characters and does not cross a `<?php ... ?>` block.**
    So "the runbook" at the start of a long paragraph does not govern a number
    at the end of it. That is deliberate: the first version collapsed each PHP
    block to a single space, which pulled text hundreds of lines apart into one
    window and reported "Version 4.4 of the portal", "About 4.4 million
    records" and the date "4.4.2026" as stale references. A reference written
    with more than 60 characters between "runbook" and the number is missed.

Package: WebMS-Intra / audit checks
Author: Claude Opus 5 (for MWBM Partners Ltd, t/a MWservices)
Copyright: All Rights Reserved -- MWBM Partners Ltd
Version: 1.0.0
"""

from __future__ import annotations

import os
import re
import sys

HELP_DIR = os.path.join("web", "_apps", "help")
RUNBOOK = os.path.join("docs", "disaster-recovery-runbook.md")

# How far before a number the word "runbook" may sit and still govern it.
# "Runbook section&nbsp;6.2" is 24 characters once the entity is a space.
RUNBOOK_WINDOW = 60

# A numbered heading: `## 4. Close the portal`, `### 7.10 Something`.
HEADING = re.compile(r"^#{1,4}\s+([0-9]+(?:\.[0-9]+)*)[.\s]")

# A number that could be a section reference.
NUMBER = re.compile(r"\b([0-9]+(?:\.[0-9]+)*)\b")

# `<?php ... ?>` or `<?= ... ?>`, including one left unclosed at end of file.
PHP_BLOCK = re.compile(r"<\?(?:php|=)?.*?(?:\?>|\Z)", re.DOTALL)


def repo_root() -> str:
    """The repository root, from this file's own position."""
    here = os.path.dirname(os.path.abspath(__file__))
    return os.path.dirname(os.path.dirname(here))


def runbook_headings(text: str) -> set[str]:
    """Every numbered heading in the runbook, as strings like '7.10'."""
    found: set[str] = set()
    for line in text.split("\n"):
        match = HEADING.match(line)
        if match is not None:
            found.add(match.group(1))
    return found


# What a removed `<?php ... ?>` block leaves behind. The window that looks
# backwards for the word "runbook" must never read ACROSS one.
FENCE = "\x00"


def reader_text(php: str) -> str:
    """
    The part of a help page a reader sees: everything outside `<?php ... ?>`.

    Each PHP block becomes a FENCE, not a space. **A space was the first
    version and it cried wolf, which my own planting caught.** Collapsing a
    long PHP region to one character pulls text that is hundreds of lines apart
    into the same 60-character window, so an ordinary "Version 4.4 of the
    portal", "About 4.4 million records" and a date "4.4.2026" were each
    reported as a stale runbook reference, governed by a "runbook" that is
    nowhere near them on the page. A fence keeps the window inside one piece of
    text the reader really sees as one piece.
    """
    out = PHP_BLOCK.sub(FENCE, php)
    # HTML entities that stand for a space, so "section&nbsp;6.2" reads as a
    # phrase. The live pages really do use `&nbsp;` there.
    return out.replace("&nbsp;", " ").replace("&#160;", " ")


def references(text: str) -> list[tuple[str, str]]:
    """
    Every runbook section number the text shows a reader.

    Answers a list of (number, the phrase it was found in), so a failure can
    quote what the reader would see rather than only a digit.
    """
    found: list[tuple[str, str]] = []
    lowered = text.lower()

    for match in NUMBER.finditer(text):
        number = match.group(1)
        start = match.start()
        before = lowered[max(0, start - RUNBOOK_WINDOW):start]
        # Never read across a removed PHP block — see reader_text().
        if FENCE in before:
            before = before[before.rindex(FENCE) + len(FENCE):]

        governed_by_runbook = "runbook" in before
        # `section 7.3` with a DOTTED number. The pages' own headings are
        # single digits, so a dotted one is never a page heading.
        governed_by_section = (
            "." in number
            and re.search(r"sections?\s*$", before) is not None
        )

        if governed_by_runbook is False and governed_by_section is False:
            continue

        phrase = text[max(0, start - 40):match.end() + 1]
        found.append((number, " ".join(phrase.split())))

    return found


def main() -> int:
    root = repo_root()
    runbook_path = os.path.join(root, RUNBOOK)
    help_path = os.path.join(root, HELP_DIR)

    if os.path.isfile(runbook_path) is False:
        print(f"FAIL: {RUNBOOK} is not there, so no reference can be checked.")
        return 1
    if os.path.isdir(help_path) is False:
        print(f"FAIL: {HELP_DIR} is not there.")
        return 1

    with open(runbook_path, "r", encoding="utf-8") as handle:
        headings = runbook_headings(handle.read())

    if len(headings) == 0:
        print(
            f"FAIL: no numbered headings were found in {RUNBOOK}. Either it has "
            f"been rewritten in a shape this check cannot read, or it really has "
            f"none — it refuses rather than passing on an empty set, because an "
            f"empty set makes every reference look wrong OR right depending on "
            f"which way round the comparison is written."
        )
        return 1

    pages = sorted(
        name for name in os.listdir(help_path) if name.endswith(".php")
    )
    problems: list[str] = []
    checked = 0

    for name in pages:
        with open(os.path.join(help_path, name), "r", encoding="utf-8") as handle:
            text = reader_text(handle.read())

        for number, phrase in references(text):
            checked += 1
            if number in headings:
                continue
            problems.append(
                f"{os.path.join(HELP_DIR, name)}: points the reader at runbook "
                f"section {number}, which is not a heading in {RUNBOOK}.\n"
                f"      what the reader sees: …{phrase}…\n"
                f"      the runbook's sections are: {', '.join(sorted(headings, key=_sortable))}"
            )

    print(
        f"Help pages read: {len(pages)}; runbook references checked: {checked}; "
        f"runbook headings: {len(headings)}"
    )

    if len(problems) > 0:
        print("")
        for problem in problems:
            print(f"FAIL: {problem}")
        print("")
        print(
            "Somebody reads these pages when something has already gone wrong. "
            "Point at a section that exists, and remember the LINK'S ANCHOR has "
            "to agree with the words — #583's fault was that they did not."
        )
        return 1

    print("OK: every runbook section a help page names is a real heading.")
    print(
        "   This says NOTHING about whether the advice on those pages is "
        "correct. No check can."
    )
    return 0


def _sortable(number: str) -> tuple[int, ...]:
    """Sort '7.10' after '7.9' rather than before it."""
    return tuple(int(part) for part in number.split("."))


if __name__ == "__main__":
    sys.exit(main())
