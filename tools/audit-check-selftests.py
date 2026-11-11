#!/usr/bin/env python3
"""
Find every audit check that offers a self-test, and run them (#919).

Path: tools/audit-check-selftests.py

    python3 -I tools/audit-check-selftests.py           run every one; exit 1 if any is not green
    python3 -I tools/audit-check-selftests.py --list    name them and judge the finding; run nothing
    python3 -I tools/audit-check-selftests.py --rows    one row each for tools/run-all-selftests.sh

WHY THIS EXISTS
---------------
Several checks in `tools/audit-checks/` carry a self-test: planted faults the
check must report, and correct code it must not. On 10 November 2026 one of
them -- `check_sign_in_begins_session.py --self-test` -- went red on CORRECT
code, because one plant expected its finding at a fixed line number of
`Auth.php` and #620 added lines above it. It stayed red on the pushed branch,
because nothing on this machine ran it: `.githooks/pre-push` runs every CHECK
but not its self-test, and `tools/run-all-selftests.sh` ran the PHP and node
self-tests only. The only thing that ran it was `pr-security.yml`, on GitHub,
after the push. And some self-tests ran in no workflow GitHub has ever run at
all (see "WHAT RUNS WHERE" below).

WHY IT FINDS THEM RATHER THAN LISTING THEM
------------------------------------------
A hand-written list of "the checks with a self-test" is exactly the kind of
list this repository keeps finding stale: `run-all-selftests.sh` was missing
FIVE tests when round 4 of #775 looked (its own header records it), and #646
found twelve guards that ran nowhere. A list goes stale silently, because a
check that is not on it simply is not run, and that looks the same as a check
that passes. So this file reads the checks themselves, every time.

HOW A SELF-TEST IS FOUND (measured on 11 November 2026, not assumed)
--------------------------------------------------------------------
There is no single convention. Every check that has one reads a FLAG from its
own command line, and two spellings are in use:

    --self-test   6 checks (backup_column_types, bind_param_arity,
                  htaccess_php_denied, inline_scripts_have_nonce,
                  redirect_targets_judged, sign_in_begins_session)
    --selftest    3 checks (event_status_matches_column, no_curl_close,
                  no_removed_sql_functions)

(Those counts are a record of the day this was written, not something this
file relies on -- it counts afresh every run. Read the real list with --list.)

So the rule is: a check offers a self-test when its source contains a string
CONSTANT that is exactly `--self-test` or `--selftest`. The source is read
with Python's own parser (`ast`), never searched as text, because the checks
QUOTE these flags in comments and docstrings all the time -- a text search
would find a check that merely mentions another check's flag. A comment is
not in the tree at all, and a docstring is a constant but is never EXACTLY the
flag.

A check that has no such constant is never run with a flag: several checks
treat an argument they do not know as a path, or ignore it and run the whole
scan, and "ran the scan" would then be reported as "self-test passed".

THE TWO WAYS THIS COULD GO WRONG, AND WHAT CATCHES EACH
-------------------------------------------------------
1. A NEW SPELLING. If somebody writes `--self_test` or `--selfTest` next,
   the rule above would simply not see it, and their self-test would join the
   ones nothing runs -- this issue's fault again, reached a new way. So any
   string constant that LOOKS like a self-test flag (`--self`, then an
   optional `-`, `_` or space, then `test`, any capitals) but is not one of
   the two known spellings is a PROBLEM, and it turns this file red. The fix
   is to use a known spelling, or to add the new one to FLAGS below on
   purpose.
2. A SELF-TEST NOTHING CAN REACH. Every check that has one today also defines
   a function named `self_test` or `selftest`. A check that defines such a
   function but reads no recognised flag is a PROBLEM too: either the flag is
   spelled in a way not even rule 1 recognises, or the self-test is dead code.
   Both are worth a red line.

Two checks with BOTH spellings would be ambiguous (which one is the real
entry?), so that is a problem as well. And finding NONE at all is a problem:
"nothing was found" must never read as "everything passed".

WHAT IT DOES NOT FIND, said plainly
-----------------------------------
- A self-test that a check runs on EVERY ordinary run needs no flag and is not
  listed here, because the ordinary run already exercises it, in the pre-push
  hook and in `pr-security.yml`. `check_public_door_schema.py` is the example:
  it validates built-in good and bad answers every time it runs.
- A self-test that lives in its OWN file (`tools/api-spec-check-selftest.py`,
  `tools/wall-clock-writes-selftest.py`, `tools/scheduled-jobs-gate-selftest.py`,
  `tools/document-write-check-selftest.py`, `tools/static-calls-selftest.php`)
  is not found here either. Those are rows of their own in
  `tools/run-all-selftests.sh`, whose header carries the one-liner that finds a
  self-test file it does not name.
- It cannot tell whether a self-test is any GOOD. It runs it and reads the exit
  code. Each self-test's own header says what it pins.

WHAT RUNS WHERE (11 November 2026)
----------------------------------
GitHub runs six of the nine from `pr-security.yml`, which it has registered
(sign_in_begins_session, redirect_targets_judged, no_removed_sql_functions,
htaccess_php_denied, no_curl_close, event_status_matches_column). Two more are
named only in workflows GitHub has never registered (#754):
bind_param_arity in `portal-selftests.yml`, backup_column_types in
`backup-selftest.yml`. And `check_inline_scripts_have_nonce.py --self-test` is
named in NO workflow at all. Locally, from #919, all of them run in the
pre-push hook and in `tools/run-all-selftests.sh`.

@package   Portal\\Tools
@author    MWBM Partners Ltd (t/a MWservices)
@copyright 2025-present MWBM Partners Ltd (t/a MWservices)
@license   All Rights Reserved
@version   1.0.0
@link      https://github.com/MWBMPartners/WebMS-Intra/issues/919
"""

from __future__ import annotations

import ast
import os
import re
import subprocess
import sys
import time

# 🏠 Where everything is, worked out from this file so it runs from any folder.
REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CHECKS = os.path.join(REPO, "tools", "audit-checks")

# 🏷️ The spellings a check may use for its self-test flag. Add one here ON
#    PURPOSE, never to make a red line go away without reading it.
FLAGS = ("--self-test", "--selftest")

# 🔎 Anything that LOOKS like a self-test flag. Wider than FLAGS on purpose:
#    its whole job is to notice a spelling FLAGS does not know (rule 1 above).
LOOKS_LIKE_A_FLAG = re.compile(r"--self[-_ ]?tests?", re.IGNORECASE)

# 🔎 A function that looks like a self-test entry point (rule 2 above). Only
#    names that START that way: `_self_test_case_ok()` style helpers would
#    otherwise be read as a second entry point.
LOOKS_LIKE_A_SELF_TEST = re.compile(r"^_?(run_)?self_?test$", re.IGNORECASE)

# ⏱️ Longest any one self-test may take before it counts as not green. The
#    slowest measured on 11 November 2026 took under four seconds; a hang must
#    end as a red line, not as a hook that never returns.
TIMEOUT_SECONDS = 300


def find() -> tuple[list[tuple[str, str]], list[str]]:
    """Every (check file name, flag) that offers a self-test, and every problem.

    Reads each `tools/audit-checks/*.py` with Python's own parser. Never runs
    anything.
    """
    found: list[tuple[str, str]] = []
    problems: list[str] = []
    try:
        names = sorted(n for n in os.listdir(CHECKS) if n.endswith(".py"))
    except OSError as e:
        return [], ["could not list " + CHECKS + ": " + str(e)]

    for name in names:
        path = os.path.join(CHECKS, name)
        try:
            with open(path, encoding="utf-8") as handle:
                tree = ast.parse(handle.read(), filename=path)
        except (OSError, UnicodeDecodeError, SyntaxError, ValueError) as e:
            # A check that cannot be parsed cannot be judged either way, so it
            # is never assumed to have no self-test.
            problems.append(name + ": could not be read, so whether it has a self-test is unknown (" + str(e) + ")")
            continue

        flags: set[str] = set()
        odd: set[str] = set()
        entry_points: list[str] = []
        for node in ast.walk(tree):
            if isinstance(node, ast.Constant) and isinstance(node.value, str):
                if node.value in FLAGS:
                    flags.add(node.value)
                elif LOOKS_LIKE_A_FLAG.fullmatch(node.value) is not None:
                    odd.add(node.value)
            elif isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)):
                if LOOKS_LIKE_A_SELF_TEST.match(node.name) is not None:
                    entry_points.append(node.name)

        for spelling in sorted(odd):
            problems.append(
                name + ": reads the flag " + repr(spelling) + ", which is not a spelling this runner knows ("
                + " or ".join(FLAGS) + "), so its self-test would run nowhere. Use a known spelling, or add "
                "this one to FLAGS in tools/audit-check-selftests.py on purpose.")
        if len(flags) > 1:
            problems.append(
                name + ": reads BOTH " + " and ".join(sorted(flags)) + ", so which one runs the self-test is "
                "ambiguous. Keep one.")
            continue
        if len(flags) == 1:
            found.append((name, next(iter(flags))))
        elif entry_points != [] and odd == set():
            problems.append(
                name + ": defines " + ", ".join(entry_points) + "() but reads no self-test flag this runner "
                "recognises, so nothing can run it. Give it " + " or ".join(FLAGS) + ", or remove the dead code.")

    if found == [] and problems == []:
        problems.append("no check in tools/audit-checks/ offers a self-test. Nine did on 11 November 2026, so this "
                        "is far more likely to be this finder broken than all nine removed.")
    return found, problems


def short(name: str) -> str:
    """`check_no_curl_close.py` -> `no_curl_close`, for a row name that fits a line."""
    stem = name[:-3] if name.endswith(".py") else name
    return stem[len("check_"):] if stem.startswith("check_") else stem


def main(argv: list[str]) -> int:
    known = {"--list", "--rows"}
    unknown = [a for a in argv if a not in known]
    if unknown != []:
        print("I do not know " + ", ".join(unknown) + ". Use --list, --rows or nothing.", file=sys.stderr)
        return 2

    found, problems = find()

    if "--rows" in argv:
        # One row per self-test, in run-all-selftests.sh's EXTRA_SHELL shape:
        # `name|command`, read with IFS='|'. Problems are NOT printed here:
        # the runner carries a separate `audit:finder` row (this file with
        # --list) that turns red for them, so a broken finding can never just
        # make rows vanish quietly.
        for name, flag in found:
            print("audit:" + short(name) + "|python3 -I tools/audit-checks/" + name + " " + flag)
        return 0

    if "--list" in argv:
        for name, flag in found:
            print("  " + name + " " + flag)
        for p in problems:
            print("✗ " + p)
        print(str(len(found)) + " check(s) offer a self-test; " + str(len(problems)) + " problem(s) finding them.")
        return 1 if problems else 0

    # ▶️ Run every one. Output is kept quiet for a green self-test (its last
    #    line only) and shown IN FULL for a red one, the same choice the
    #    pre-push hook makes for the checks themselves.
    red = 0
    started_all = time.monotonic()
    for name, flag in found:
        started = time.monotonic()
        try:
            done = subprocess.run(
                [sys.executable, "-I", os.path.join("tools", "audit-checks", name), flag],
                cwd=REPO, capture_output=True, text=True, timeout=TIMEOUT_SECONDS)
            code, output = done.returncode, (done.stdout + done.stderr)
        except subprocess.TimeoutExpired:
            code, output = -1, "did not finish within " + str(TIMEOUT_SECONDS) + " seconds"
        except OSError as e:
            code, output = -1, "could not be started: " + str(e)
        seconds = time.monotonic() - started
        if code == 0:
            last = (output.strip().splitlines() or ["(no output)"])[-1]
            print("  ✓ %-38s %5.1fs  %s" % (short(name), seconds, last[:80]))
        else:
            red += 1
            print("\n✗ %s %s exited %d after %.1fs:" % (name, flag, code, seconds))
            for line in output.rstrip().splitlines():
                print("    " + line)
            print()
    for p in problems:
        print("✗ " + p)
    print("%d audit self-test(s) ran in %.1fs; %d not green; %d problem(s) finding them."
          % (len(found), time.monotonic() - started_all, red, len(problems)))
    return 1 if (red > 0 or problems != []) else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
