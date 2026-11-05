#!/usr/bin/env python3
"""
The decision register is kept, and rows are superseded rather than edited.

Path: tools/audit-checks/check_decisions_recorded.py

WHY THIS EXISTS
---------------
The owner, 4 November 2026: *"we need to be firm about recording decisions and
checking those records on this device"*. Said after noticing that the same
question had been put to them more than once.

It had been. Whether to require tests before a change reaches the `alpha`
branch was asked **three times** on 3-4 November and answered three different
ways. The second ask was defensible — a measured fact had unseated the first
answer — and the third was the owner's own prompt. But three answers now exist
for one question, and the owner's words for the cost are the reason this check
is here: it *"delays development and release readiness but also risks
convoluted/fragmented code"*.

**The structural cause was that decisions were recorded in four places and
none of them was complete**: `.claude/CLAUDE.md`, several memory files,
`.claude/HANDOFF.md`, and issue comments. Scattered records read as no record,
because there is nothing to search. `.claude/DECISIONS.md` is the one register,
and this check is what stops it quietly falling out of use.

WHAT IT CHECKS
--------------
  1. `.claude/DECISIONS.md` exists and holds rows.
  2. **Every row is well formed** — a `D-NNN` number, a date, and the four
     labelled parts a row has to carry. A row missing its answer is a row
     nobody can follow.
  3. **Numbers are unique and never reused.** Reusing one is how a supersession
     silently overwrites what it was meant to preserve.
  4. **Every `supersedes` and `superseded by` names a row that exists.** A
     dangling reference is the shape #678 is about: a pointer that has stopped
     pointing anywhere.
  5. **A row the register says is superseded must say so**, and the row that
     supersedes it must name it back. One-way links are how two live answers
     end up on record for one question.
  6. **The count only grows.** The register is append-only, so the highest
     `D-NNN` must be at least as high as MINIMUM_ROWS — which is raised by hand
     when rows are added. A rewrite that drops history fails here.
  7. **The index and the folder agree.** A decision file with no row in
     `docs/decisions/README.md`, or a row naming a file that is not there, both
     fail. An index that has quietly stopped listing everything is the same
     fault as no index.
  8. **The instruction files point rather than restate.** A dated "the owner
     decided X" line in `.claude/CLAUDE.md` or `.OpenAI/CONTEXT.md` must carry a
     `D-NNN` number within a few lines, so a reader can reach the record. This
     is the drift check the owner asked for: those files are where the
     duplication grew, and a decision restated in full in two places is how two
     versions of it come to exist.

WHAT THIS CANNOT DO, said plainly because an overstated guarantee is worse than
none
---------------------------------------------------------------------------
  * **It cannot tell whether a decision was actually SEARCHED FOR before a
    question was asked.** Nothing can. That is a rule, kept by the person doing
    the work, and this check only makes the register trustworthy enough to be
    worth searching.
  * **It cannot tell whether a row is TRUE**, or whether what it says was
    implemented. "Where" is prose.
  * **It does not find a decision that was never written down.** A question
    answered and then forgotten leaves no trace for it to catch. The only
    defence is the habit.
  * It does not compare the register with the owner's actual words. A row that
    misstates an answer passes.

Package: WebMS-Intra / audit checks
Author: Claude Opus 5 (for MWBM Partners Ltd, t/a MWservices)
Copyright: All Rights Reserved -- MWBM Partners Ltd
Version: 1.0.0
"""

from __future__ import annotations

import os
import re
import sys

DECISIONS = os.path.join("docs", "decisions")
INDEX = os.path.join(DECISIONS, "README.md")

# Files in the folder that are not decisions.
NOT_A_DECISION = {"README.md"}

# Where a decision must NOT be restated in full. These files explain the RULES;
# the decision itself lives once, in docs/decisions/. A dated "the owner
# decided X" line here must carry a decision number, so the reader can get to
# the record (the owner, 4 November 2026).
POINTER_FILES = (
    os.path.join(".claude", "CLAUDE.md"),
    os.path.join(".OpenAI", "CONTEXT.md"),
)

# A dated claim that the owner decided something.
OWNER_CLAIM = re.compile(
    r"(?:the owner,?|owner'?s? (?:decision|choice) of)\s+"
    r"(\d{1,2} [A-Z][a-z]+ 2026)", re.I
)
# A decision number anywhere near it.
NUMBER_NEARBY = re.compile(r"\bD-\d{3}\b")

# Raised BY HAND when rows are added. The register is append-only, so this can
# only ever go up — it is what makes a rewrite that drops history fail.
MINIMUM_ROWS = 51

# `## D-014 · 2026-10-30 · One job per document`
ROW = re.compile(r"^##\s+(D-(\d{3}))\s+·\s+(\d{4}-\d{2}-\d{2})\s+·\s+(.+?)\s*$")

# The parts every row carries.
REQUIRED = ("**Question:**", "**Answer:**")
# One of these must be present: a row either rules something out, or is
# superseded (in which case what it ruled out no longer matters).
EITHER = ("**Rules out:**", "**Superseded")   # matched case-insensitively below

# Both directions, and CASE-INSENSITIVELY. The first version matched
# "SUPERSEDED BY" in capitals only, so a row written "Superseded in part by:"
# read as having no back-link and the check cried wolf on its own register —
# caught on the first run, which is the right time.
SUPERSEDES = re.compile(r"\*\*Supersedes(?: in part)?:\*\*\s*(.+)", re.I)
SUPERSEDED_BY = re.compile(r"\*\*Superseded(?: in part)? by:?\*?\*?\s*([A-Z0-9-]+)", re.I)


def repo_root() -> str:
    """The repository root, from this file's own position."""
    here = os.path.dirname(os.path.abspath(__file__))
    return os.path.dirname(os.path.dirname(here))


def main() -> int:
    root = repo_root()
    folder = os.path.join(root, DECISIONS)
    index_path = os.path.join(root, INDEX)

    if os.path.isdir(folder) is False:
        print(
            f"FAIL: {DECISIONS} is not there. It is the one register of decisions the "
            f"owner has made, and the rule in ~/.claude/CLAUDE.md says it is searched "
            f"before any question is put to them. It lives outside any one assistant's "
            f"folder on purpose, so every tool reads the same record."
        )
        return 1
    if os.path.isfile(index_path) is False:
        print(f"FAIL: {INDEX} is not there, so nothing lists the decisions.")
        return 1

    names = sorted(
        name for name in os.listdir(folder)
        if name.endswith(".md") and name not in NOT_A_DECISION
    )
    if len(names) == 0:
        print(f"FAIL: {DECISIONS} holds no decision files.")
        return 1

    rows: dict[str, dict[str, object]] = {}
    problems: list[str] = []

    for name in names:
        with open(os.path.join(folder, name), "r", encoding="utf-8") as handle:
            body = handle.read()
        match = re.match(r"^#\s+(D-(\d{3}))\s+·\s+(.+?)\s*$", body.split("\n")[0])
        if match is None:
            problems.append(
                f"{name}: its first line is not `# D-NNN · a title`, so nothing can "
                f"cite it by number."
            )
            continue
        number = match.group(1)
        if re.match(r"^" + match.group(2) + r"-", name) is None:
            problems.append(
                f"{name}: the file name does not start with its own number "
                f"({match.group(2)}), so the folder cannot be read in order."
            )
        if number in rows:
            problems.append(
                f"{number} appears twice ({name} and {rows[number]['file']}). A number "
                f"must never be reused: reusing one is how a supersession silently "
                f"overwrites the row it was meant to preserve."
            )
            continue
        rows[number] = {"file": name, "body": body, "title": match.group(3)}

    for number in sorted(rows):
        body = str(rows[number]["body"])
        name = rows[number]["file"]
        for part in REQUIRED:
            if part not in body:
                problems.append(f"{number} ({name}) has no {part} — a record nobody can follow.")
        if any(part.lower() in body.lower() for part in EITHER) is False:
            problems.append(
                f"{number} ({name}) says neither what it rules out nor that it has been "
                f"superseded. One or the other is what makes a record usable."
            )
        if "**Date:**" not in body:
            problems.append(f"{number} ({name}) has no date.")

        for cited in SUPERSEDES.findall(body):
            for other in re.findall(r"D-\d{3}", cited):
                if other not in rows:
                    problems.append(f"{number} says it supersedes {other}, which is not a decision here.")
                elif "superseded" not in str(rows[other]["body"]).lower():
                    problems.append(
                        f"{number} supersedes {other}, but {other} does not say so. A one-way "
                        f"link is how two live answers end up on record for one question."
                    )
        for other in SUPERSEDED_BY.findall(body):
            if other not in rows:
                problems.append(f"{number} says it is superseded by {other}, which is not a decision here.")
            elif number not in str(rows[other]["body"]):
                problems.append(f"{number} says {other} supersedes it, but {other} does not name it back.")

    # The index must list every file, and name no file that is not there.
    with open(index_path, "r", encoding="utf-8") as handle:
        index_text = handle.read()
    for number in sorted(rows):
        if number not in index_text:
            problems.append(
                f"{number} is not listed in {INDEX}. An index that has quietly stopped "
                f"listing everything is the same fault as no index."
            )
    for cited in re.findall(r"\]\((\d{3}-[a-z0-9-]+\.md)\)", index_text):
        if cited not in names:
            problems.append(f"{INDEX} links to {cited}, which is not in the folder.")

    highest = max((int(number.split("-")[1]) for number in rows), default=0)
    if highest < MINIMUM_ROWS:
        problems.append(
            f"the highest decision is D-{highest:03d}, below MINIMUM_ROWS "
            f"({MINIMUM_ROWS}). The register is APPEND-ONLY, so this can only mean "
            f"history has been dropped. If decisions were genuinely added, raise "
            f"MINIMUM_ROWS in {os.path.basename(__file__)} in the same change."
        )

    # The drift check: the instruction files POINT, they do not restate.
    drift = 0
    for relative in POINTER_FILES:
        path = os.path.join(root, relative)
        if os.path.isfile(path) is False:
            continue
        with open(path, "r", encoding="utf-8") as handle:
            lines = handle.read().split("\n")
        for index_no, line in enumerate(lines):
            if OWNER_CLAIM.search(line) is None:
                continue
            window = "\n".join(lines[max(0, index_no - 2):index_no + 3])
            if NUMBER_NEARBY.search(window) is None:
                drift += 1
                problems.append(
                    f"{relative} line {index_no + 1} states a dated decision by the owner "
                    f"with no D-NNN number within two lines, so a reader cannot reach the "
                    f"record: {line.strip()[:90]}"
                )

    print(
        f"Decision register: {len(rows)} decision(s) in {DECISIONS}, highest "
        f"D-{highest:03d}, minimum {MINIMUM_ROWS}; {drift} un-pointed claim(s) in the "
        f"instruction files"
    )

    if len(problems) > 0:
        print("")
        for problem in problems:
            print(f"FAIL: {problem}")
        print("")
        print(
            f"{len(problems)} problem(s). The register is only worth searching if it can "
            f"be trusted, and it is searched before every question put to the owner."
        )
        return 1

    print("OK: every decision is well formed, indexed, and pointed to rather than restated.")
    print(
        "   This CANNOT tell whether the register was searched before a question was "
        "asked, nor whether a decision is true. Those are habits, not checks."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
