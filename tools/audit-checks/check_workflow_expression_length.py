#!/usr/bin/env python3
"""
No GitHub Actions script is long enough to break the whole workflow file (#745).

Path: tools/audit-checks/check_workflow_expression_length.py

WHY THIS EXISTS
---------------
GitHub's Actions service puts a ceiling on how long any ONE value carrying a
`${{ ... }}` may be. A workflow step's script is a single value. So a script
that holds even one `${{ ... }}` counts, in its entirety, against that ceiling
-- and when it goes over, GitHub refuses THE WHOLE FILE before it starts any
job at all.

That happened here, and it cost a week of security checking. On 27 October 2026
commit `c61211e` took the "Heuristic anti-pattern scan" script in
`.github/workflows/pr-security.yml` from 14,910 characters to 24,525. That
script held two `${{ ... }}` on its ninth and tenth lines. From the next push
onwards, every run of "PR Security Checks" failed with NO JOBS -- so about
THIRTY-FIVE hard gates (the PHP lint, the two-factor gate order, the account-change
guard, the reserved-word check, the personal-data coverage check and the rest)
ran on GitHub for a week without running anything. Fixed in `812d992` by
passing the two values in through `env:`.

NOTHING HERE COULD SEE IT, WHICH IS THE WHOLE POINT
---------------------------------------------------
  * The file is valid YAML. Every parser on this machine accepted it.
  * **actionlint accepted it too, and still does.** The ceiling belongs to
    GitHub's own service, not to the workflow schema, so no local linter knows
    about it. `lint.yml` was green on every one of those failing days.
  * On GitHub the only sign is a run with zero jobs, the words "This run likely
    failed because of a workflow file issue", and -- the real giveaway -- the
    run named after the FILE PATH instead of the workflow's own name, because
    GitHub falls back to the path when it cannot read the file.

So this is the #646 shape: a fault that every existing check is blind to, in the
file that holds the checks. It also cannot be guarded by a workflow, because a
workflow cannot run from a file GitHub will not read. It is guarded HERE, in
`tools/audit-checks/`, because `.githooks/pre-push` globs that folder and so
runs this before the push that would break things. `lint.yml` runs it as well,
for the push that used `--no-verify`.

WHAT IT LOOKS FOR
-----------------
Every `run:` script in every file under `.github/workflows/`:

  1. **FAILS** when a script holds a `${{ ... }}` and is longer than
     MAX_EXPRESSION_LENGTH. That is the fault above, exactly.
  2. **FAILS** when a workflow file is larger than MAX_FILE_BYTES. GitHub's
     documented limit; a file over it starts no runs either.
  3. **REPORTS, and fails** when a script with NO `${{ ... }}` is longer than
     MAX_EXPRESSION_LENGTH. Such a script is safe today and is ONE `${{ ... }}`
     away from stopping every check in its file, silently. Failing on it is
     deliberate: the alternative is printing a warning and exiting 0, and this
     repository has already been bitten by a check that named a real fault
     while exiting 0 (`check_schema_seed_parity.py`, which is how migration 223
     nearly shipped unrecorded). Such a script must be listed in
     LONG_SCRIPTS_ON_PURPOSE with a reason, which makes the risk a decision
     somebody took rather than something nobody noticed. The list FAILS BOTH
     WAYS: an entry whose script has since come back under the ceiling fails
     too, so the list cannot go stale the way the migration harness's
     exceptions did.

ABOUT THE CEILING, SAID HONESTLY
--------------------------------
21,000 is the number in GitHub's own error message ("Exceeded max expression
length 21000"). The exact boundary was NOT measured here. What WAS measured, on
this repository against the real service: 14,910 characters with an expression
was accepted, and 24,525 with an expression was refused. 21,000 lies between
them. If the real figure ever turns out to be lower, this check is too
generous, never too strict.

The length used is the script as this file reads it out of the YAML -- the block
scalar with its indentation removed. That is what GitHub stores. It is not
exact to the character (how a YAML writer folds a line could differ by a
newline or two), so do not read a figure here as the service's own count. It is
accurate to well within the margin that matters, because the thing being caught
is a script thousands of characters over.

WHAT THIS CANNOT SEE, said plainly because an overstated guarantee is worse
than none
--------------------------------------------------------------------------
  * **It reads `run:` scripts and nothing else.** A `${{ ... }}` in a long
     `with:` value, a long `env:` value or a long `if:` would hit the same
     ceiling and is not measured. Nothing here is remotely near it (the largest
     such value in this repository is a few hundred characters), and measuring
     them properly needs a real YAML parser, which this machine has not got --
     there is no pyyaml, and an audit check must run with no dependencies so the
     pre-push hook can run all of them in about nine seconds.
  * **It is not a YAML parser.** It finds `run:` by its indentation and reads
     the block that follows. It DOES handle a quoted key (`"run":`), a step
     whose first key is `run:` (`- run: |`) and a trailing comment on the block
     header (`run: | # a note`) -- all three were added after each was planted
     and silently missed. It does NOT handle flow style (`{run: "..."}`), nor a
     script given on the `run:` line and continued by YAML line folding.
     Every workflow in this repository is written in ordinary block style; a
     file that is not would be measured SHORT, which is the missing-it
     direction. Say so rather than imply otherwise.
  * **It reads only `.github/workflows/`.** A composite action
     (`.github/actions/**/action.yml`) can hold a long script with a
     `${{ ... }}` in it and is not looked at. There are none here today.
  * **It cannot tell a real `${{` from the four characters `${{` inside a
     comment or a quoted string in the script.** It counts either as an
     expression. That is deliberate: GitHub's template parser does not look at
     shell comments either -- it sees the script as one piece of text -- so
     this matches the behaviour being guarded.
  * **It says nothing about whether a workflow is wired up, or whether its
     checks pass.** That is #646's job and the workflows' own.

Package: WebMS-Intra / audit checks
Author: Claude Opus 5 (for MWBM Partners Ltd, t/a MWservices)
Copyright: All Rights Reserved -- MWBM Partners Ltd
Version: 1.0.0
"""

from __future__ import annotations

import os
import re
import sys

# The ceiling GitHub's error message names. See "ABOUT THE CEILING" above:
# measured either side of it, not at it.
MAX_EXPRESSION_LENGTH = 21000

# GitHub's documented limit on a workflow file. A file over it starts no runs.
#
# The documentation says "500 KB" and does not say whether that is 500,000
# bytes or 512,000. The smaller reading is used, because being strict here
# costs nothing: the largest workflow file in this repository is about 95,000
# bytes, so nothing is anywhere near either figure.
MAX_FILE_BYTES = 500_000

# A script with NO `${{ ... }}` that is over the ceiling anyway. Safe today,
# one expression away from stopping every check in its file. Each entry names
# the workflow file, the step's name, and WHY it is allowed to be this long.
#
# FAILS BOTH WAYS: an entry whose script is no longer over the ceiling fails
# too, so this list cannot quietly stop describing the code.
LONG_SCRIPTS_ON_PURPOSE: dict[tuple[str, str], str] = {
    ("pr-security.yml", "Heuristic anti-pattern scan"): (
        "About 24,500 characters of shell holding the checks that are reported "
        "in the pull-request comment rather than failing a build. It is the "
        "script that caused #745. It carries no expression now -- the two "
        "commit names come in through env: -- and the step's own note says "
        "why one must never go back in. Splitting it into a script file under "
        "tools/ would be better engineering and is its own piece of work; "
        "doing it in the same change as the fix would have meant moving 500 "
        "lines of shell with no way to test the move except by pushing."
    ),
}

WORKFLOW_DIR = os.path.join(".github", "workflows")

# `run: |`, `run: >`, with any chomping or indentation indicator after it.
#
# THREE SHAPES THIS HAD TO BE WIDENED FOR, each of which switched the whole
# check off silently, and each found by the independent check planting it:
#
#   * `run: |   # a note about the script` — a TRAILING COMMENT on the block
#     header. The first version demanded end-of-line straight after the `|`,
#     so the line fell through to INLINE_RUN and the script was measured as
#     the nine characters `| # a no…`. Adding a comment to the very script
#     this check protects would have turned it off, and nothing else would
#     have shown it.
#   * `"run": |` — a QUOTED KEY, which any formatter may produce. This
#     repository has already been bitten by exactly that: the QR guard went
#     green on `"qrUrl": function` (#623).
#   * `- run: |` — a step whose FIRST key is `run:`, so the `- ` sits on the
#     same line. The old pattern could not see it, although
#     `step_name_above()`'s own docstring assumed such steps were measured.
#
# The `- ` stays INSIDE the indent group on purpose: that keeps the measured
# indentation at the key's own column, so a sibling key (`env:`, `if:`) still
# ends the block where it should.
BLOCK_RUN = re.compile(
    r"^(?P<indent>\s*(?:-\s+)?)(?:run|\"run\"|'run'):\s*[|>][-+0-9]*\s*(?:#.*)?$"
)
# `run: something-on-one-line`, in the same three shapes.
INLINE_RUN = re.compile(
    r"^(?P<indent>\s*(?:-\s+)?)(?:run|\"run\"|'run'):\s*(?P<body>\S.*)$"
)
# The nearest `- name:` at or above the script, which is how a step is named.
STEP_NAME = re.compile(r"^\s*-?\s*name:\s*(?P<name>.+?)\s*$")


def repo_root() -> str:
    """The repository root, from this file's own position."""
    here = os.path.dirname(os.path.abspath(__file__))
    return os.path.dirname(os.path.dirname(here))


def step_name_above(lines: list[str], index: int) -> str:
    """
    The name of the step a `run:` at `index` belongs to.

    Walks back to the nearest `- name:` line. A step may also be named by
    nothing at all, in which case the line number stands in -- a message
    saying "line 304" is still enough to find it.
    """
    for i in range(index, -1, -1):
        if re.match(r"^\s*-\s+(name|uses|run|id):", lines[i]) is not None:
            match = STEP_NAME.match(lines[i])
            if match is not None:
                return match.group("name")
            # A step that starts with something other than `name:` -- look for
            # a `name:` among its own keys before giving up. STOP at the next
            # step: without that, an unnamed step was labelled with the name of
            # the step AFTER it, which is worse than saying "unnamed", because
            # it sends the reader to the wrong place (measured by the
            # independent check -- a `- run:` step came back named "Second").
            for j in range(i, min(i + 6, len(lines))):
                if j > i and re.match(r"^\s*-\s", lines[j]) is not None:
                    break
                match = STEP_NAME.match(lines[j])
                if match is not None:
                    return match.group("name")
            break
    return f"(unnamed step, line {index + 1})"


def scripts_in(text: str) -> list[tuple[int, str, str]]:
    """
    Every `run:` script in one workflow file.

    Answers a list of (line number, step name, script text). The script text is
    the block with its indentation taken off, which is what GitHub stores.
    """
    lines = text.split("\n")
    found: list[tuple[int, str, str]] = []

    i = 0
    while i < len(lines):
        block = BLOCK_RUN.match(lines[i])
        if block is not None:
            marker_indent = len(block.group("indent"))
            body: list[str] = []
            j = i + 1
            body_indent: int | None = None
            while j < len(lines):
                line = lines[j]
                if line.strip() == "":
                    body.append("")
                    j += 1
                    continue
                indent = len(line) - len(line.lstrip())
                if indent <= marker_indent:
                    break
                if body_indent is None:
                    body_indent = indent
                body.append(line[body_indent:] if len(line) >= body_indent else line.lstrip())
                j += 1
            # A trailing run of blank lines belongs to the file, not the script.
            while len(body) > 0 and body[-1] == "":
                body.pop()
            found.append((i + 1, step_name_above(lines, i), "\n".join(body) + "\n"))
            i = j
            continue

        inline = INLINE_RUN.match(lines[i])
        if inline is not None:
            found.append((i + 1, step_name_above(lines, i), inline.group("body")))
        i += 1

    return found


def main() -> int:
    root = repo_root()
    directory = os.path.join(root, WORKFLOW_DIR)

    if os.path.isdir(directory) is False:
        print(f"FAIL: no {WORKFLOW_DIR} folder found under {root}")
        return 1

    names = sorted(
        name
        for name in os.listdir(directory)
        if name.endswith(".yml") is True or name.endswith(".yaml") is True
    )
    if len(names) == 0:
        print(f"FAIL: {WORKFLOW_DIR} holds no workflow files")
        return 1

    problems: list[str] = []
    allowed_seen: set[tuple[str, str]] = set()
    scripts_measured = 0
    longest = (0, "", "")

    for name in names:
        path = os.path.join(directory, name)
        size = os.path.getsize(path)
        if size > MAX_FILE_BYTES:
            problems.append(
                f"{name}: the file is {size:,} bytes, over GitHub's limit of "
                f"{MAX_FILE_BYTES:,}. A workflow file over that limit starts no "
                f"runs at all. Move shared steps into a reusable workflow or a "
                f"composite action."
            )

        with open(path, "r", encoding="utf-8") as handle:
            text = handle.read()

        for line_no, step, script in scripts_in(text):
            scripts_measured += 1
            length = len(script)
            carries_expression = "${{" in script
            if length > longest[0]:
                longest = (length, name, step)

            if length <= MAX_EXPRESSION_LENGTH:
                continue

            key = (name, step)
            # Mark the entry as still describing something real BEFORE the
            # branches below. Planting an expression back into the long script
            # showed why: with this line inside the no-expression branch only,
            # the real complaint came out alongside a second one telling the
            # reader to delete an allow-list entry that was still perfectly
            # correct. A check that cries wolf beside a true finding teaches
            # the reader to skim both.
            if key in LONG_SCRIPTS_ON_PURPOSE:
                allowed_seen.add(key)

            if carries_expression is True:
                problems.append(
                    f"{name}, step \"{step}\" (line {line_no}): the script is "
                    f"{length:,} characters AND holds a ${{{{ ... }}}}, so the "
                    f"whole script counts as one value against GitHub's ceiling "
                    f"of {MAX_EXPRESSION_LENGTH:,}. GitHub will refuse THE WHOLE "
                    f"FILE and start no job. Pass the value in through env: and "
                    f"read it as $AN_ENV_VAR inside the script -- that is what "
                    f"#745 did, and it is also GitHub's own hardening advice."
                )
                continue

            if key in LONG_SCRIPTS_ON_PURPOSE:
                continue

            problems.append(
                f"{name}, step \"{step}\" (line {line_no}): the script is "
                f"{length:,} characters, over GitHub's ceiling of "
                f"{MAX_EXPRESSION_LENGTH:,}. It carries no ${{{{ ... }}}} today, "
                f"so it works -- and it is ONE of them away from stopping every "
                f"check in this file with no error anybody here can see (#745). "
                f"Either shorten it, or add it to LONG_SCRIPTS_ON_PURPOSE in "
                f"{os.path.basename(__file__)} with a reason."
            )

    for key, reason in sorted(LONG_SCRIPTS_ON_PURPOSE.items()):
        if key in allowed_seen:
            continue
        workflow, step = key
        problems.append(
            f"{workflow}, step \"{step}\": listed in LONG_SCRIPTS_ON_PURPOSE, "
            f"but no script of that name in that file is over "
            f"{MAX_EXPRESSION_LENGTH:,} characters any more. Take the entry out, "
            f"so the list never claims something that has stopped being true. "
            f"The reason given was: {reason}"
        )

    print(f"Workflow files read: {len(names)}; run: scripts measured: {scripts_measured}")
    print(
        f"Longest script: {longest[0]:,} characters "
        f"({longest[1]}, \"{longest[2]}\"); ceiling {MAX_EXPRESSION_LENGTH:,}"
    )

    if len(problems) > 0:
        print("")
        for problem in problems:
            print(f"FAIL: {problem}")
        print("")
        print(f"{len(problems)} problem(s). See the header of this file for why this matters.")
        return 1

    print("OK: no workflow script is long enough to break its file.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
