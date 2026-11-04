#!/usr/bin/env python3
"""
What the portal TELLS a customer is tested is what is really tested (#475).

Path: tools/audit-checks/check_tested_db_lines.py

WHY THIS EXISTS
---------------
`Portal\\Core\\DbServer` shows a customer a sentence about which database
versions this project's automated tests really run on. Until 3 November 2026
those sentences were **written by hand**, and two of them were untrue on a
customer-facing page:

  * every MariaDB answer said the tests "only ever run against MySQL" — eight
    days after the migration harness began replaying every database change on
    MariaDB 11.4 (#487);
  * every supported-MySQL answer said the tests "run against the MySQL 8.0
    line, so if you are on something newer you are ahead of what is routinely
    tested here" — which told somebody on MySQL 9.7 that nobody had tested
    their version, when 9.7 is tested.

Both were stale in the MODEST direction, which is the safer one. A
customer-facing sentence that is untrue is still a fault, and the next one to
go stale could easily point the other way: telling somebody a version is
tested when it is not is how a customer comes to run a portal nothing has ever
been run against.

The sentence is now derived from `DbServer::TESTED_LINES`. **This check is what
stops that constant going stale**, which is the same failure one step along.

WHAT IT LOOKS FOR
-----------------
`DbServer::TESTED_LINES` against the matrix in
`.github/workflows/e2e-migrations.yml` — the workflow that actually does the
running. It **fails both ways**:

  * a release line the workflow tests that the constant does not list, so the
    portal under-claims and a customer is told less than is true;
  * a line the constant lists that the workflow does not test, so **the portal
    tells a customer a version is tested when nothing runs on it.** That is the
    dangerous direction and the reason this is a hard gate.

An exception list was deliberately NOT built. There is no honest reason for the
two to differ: if a line is dropped from the workflow, the sentence must stop
claiming it the same day.

WHAT IT CANNOT SEE, said plainly because an overstated guarantee is worse than
none
--------------------------------------------------------------------------
  * **It reads the migration harness's matrix and nothing else.** The calendar
    self-tests run on their own set of engines, and `portal-selftests.yml` on
    another. `TESTED_LINES` describes the HARNESS on purpose — that is the one
    that replays every database change — so folding the others in would
    overstate what the sentence means. A line tested only by the calendar
    self-tests is correctly absent here.
  * **It compares release LINES, not exact versions.** The workflow names
    `9.7.4`; the constant says `9.7`. A workflow that moved from `9.7.4` to
    `9.7.0` would not be noticed, and need not be: the sentence speaks about
    the line.
  * **It does not check that the workflow passes**, or that it runs at all.
    That is #646's job, and #745's.
  * **It is not a YAML parser.** It reads the `include:` block's
    `engine:`/`version:` pairs by their indentation. A matrix written in flow
    style would be read as empty, which fails loudly rather than passing — the
    safe direction.

Package: WebMS-Intra / audit checks
Author: Claude Opus 5 (for MWBM Partners Ltd, t/a MWservices)
Copyright: All Rights Reserved -- MWBM Partners Ltd
Version: 1.0.0
"""

from __future__ import annotations

import os
import re
import sys

DB_SERVER = os.path.join("web", "_core", "DbServer.php")
WORKFLOW = os.path.join(".github", "workflows", "e2e-migrations.yml")


def repo_root() -> str:
    """The repository root, from this file's own position."""
    here = os.path.dirname(os.path.abspath(__file__))
    return os.path.dirname(os.path.dirname(here))


def lines_from_constant(text: str) -> dict[str, list[str]] | None:
    """
    `DbServer::TESTED_LINES` as {engine: [line, ...]}.

    Answers None when the constant cannot be read, which the caller treats as a
    failure. It must never answer an empty mapping from an unreadable
    constant: an empty mapping compares equal to an empty workflow and would
    read as agreement.
    """
    block = re.search(
        r"public\s+const\s+TESTED_LINES\s*=\s*\[(.*?)\]\s*;", text, re.DOTALL
    )
    if block is None:
        return None

    found: dict[str, list[str]] = {}
    for engine, values in re.findall(
        r"'([a-z]+)'\s*=>\s*\[([^\]]*)\]", block.group(1)
    ):
        found[engine] = re.findall(r"'([^']+)'", values)

    return found if len(found) > 0 else None


def lines_from_workflow(text: str) -> dict[str, list[str]] | None:
    """
    The engine/version pairs in the harness workflow's matrix, as release lines.

    Reads the `include:` list by indentation rather than parsing YAML, because
    an audit script here runs with no dependencies and this machine has no
    pyyaml. A shape it cannot read answers None, which fails.
    """
    include = re.search(r"^(\s*)include:\s*$", text, re.MULTILINE)
    if include is None:
        return None

    start = include.end()
    indent = len(include.group(1))
    found: dict[str, list[str]] = {}
    engine: str | None = None

    for line in text[start:].split("\n"):
        if line.strip() == "":
            continue
        here = len(line) - len(line.lstrip())
        if here <= indent and line.strip().startswith("- ") is False:
            break
        engine_match = re.match(r"^\s*-\s*engine:\s*'([^']+)'", line)
        if engine_match is not None:
            engine = engine_match.group(1)
            continue
        version_match = re.match(r"^\s*version:\s*'([^']+)'", line)
        if version_match is not None and engine is not None:
            # The constant speaks of release LINES, so two parts only.
            series = ".".join(version_match.group(1).split(".")[:2])
            found.setdefault(engine, [])
            if series not in found[engine]:
                found[engine].append(series)

    return found if len(found) > 0 else None


def main() -> int:
    root = repo_root()
    try:
        with open(os.path.join(root, DB_SERVER), "r", encoding="utf-8") as handle:
            php = handle.read()
        with open(os.path.join(root, WORKFLOW), "r", encoding="utf-8") as handle:
            yml = handle.read()
    except OSError as problem:
        print(f"FAIL: could not read the files to compare: {problem}")
        return 1

    claimed = lines_from_constant(php)
    if claimed is None:
        print(
            f"FAIL: could not read DbServer::TESTED_LINES out of {DB_SERVER}. It "
            f"refuses rather than reading an empty list, because an empty list "
            f"compares equal to an empty matrix and would read as agreement."
        )
        return 1

    tested = lines_from_workflow(yml)
    if tested is None:
        print(
            f"FAIL: could not read the matrix out of {WORKFLOW}. If it was "
            f"rewritten in flow style, this check must be taught the new shape — "
            f"it fails rather than passing on nothing."
        )
        return 1

    problems: list[str] = []
    for engine in sorted(set(claimed) | set(tested)):
        mine = sorted(claimed.get(engine, []))
        theirs = sorted(tested.get(engine, []))

        for series in theirs:
            if series not in mine:
                problems.append(
                    f"{engine} {series}: the migration harness runs on it, and "
                    f"DbServer::TESTED_LINES does not list it. The portal is telling "
                    f"customers LESS than is true — add '{series}' to "
                    f"TESTED_LINES['{engine}']."
                )
        for series in mine:
            if series not in theirs:
                problems.append(
                    f"{engine} {series}: DbServer::TESTED_LINES lists it, and the "
                    f"migration harness does NOT run on it. **The portal is telling "
                    f"customers a version is tested when nothing runs on it.** Either "
                    f"add it to {WORKFLOW}'s matrix, or take it out of TESTED_LINES."
                )

    print(f"DbServer::TESTED_LINES  : {ndict(claimed)}")
    print(f"{WORKFLOW} : {ndict(tested)}")

    if len(problems) > 0:
        print("")
        for problem in problems:
            print(f"FAIL: {problem}")
        print("")
        print(f"{len(problems)} problem(s). See the header of this file for why this matters.")
        return 1

    print("OK: what the portal says is tested is what the harness really runs on.")
    return 0


def ndict(value: dict[str, list[str]]) -> str:
    """One readable line for a mapping of engine to release lines."""
    return "; ".join(f"{k} {', '.join(sorted(v))}" for k, v in sorted(value.items()))


if __name__ == "__main__":
    sys.exit(main())
