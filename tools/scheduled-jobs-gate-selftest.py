#!/usr/bin/env python3
"""
The test OF the #775 hard gate's token-gate NESTING check.
======================================================================

WHY THIS FILE EXISTS, AND WHY IT NO LONGER TESTS WHAT IT USED TO.

Rounds 1-6 of #775's review each defeated a source-reading detector that tried
to answer "does this token gate REFUSE an unauthenticated request". Five
versions, wrong in BOTH directions four times:

    v1  passed an exit inside a braced closure
    v2  cried wolf on a real exit one level deeper; passed `$log->exit();`
    v3  passed `fn() => exit()`; cried wolf on a braceless gate
    v4  passed `fn() => match (…) { … exit(…) }`; cried wolf on
        `$matches = hash_equals(…)` above the gate
    v5  cried wolf on a refusal in an `else` branch; passed
        `$bad = !hash_equals(…)`

**That question is BEHAVIOURAL, and D-065 is the owner's own rule for it:
where the property is "this code RUNS", DRIVE it.** It is now driven, on all
fifteen pages, by section I-ALL of `tools/scheduled-jobs-selftest.php` — a
wrong token and no token, with the row read back.

WHAT DRIVING CANNOT SEE, which is what this check and this test are for. Round
6 found a gate the REQUEST decides whether to apply:

    if (($_GET['debug'] ?? '') !== '1') {
        if ($expected === '' || hash_equals(…) === false) { exit; }
    }

Driving with a wrong token does NOT catch it — the wrapper's condition holds,
so the gate applies and the request IS refused. **Measured: with that planted,
the driven test stayed at 196 of 196.** It is reachable only by sending the
magic parameter, and no test can guess one.

Its SHAPE, though, is plain: the gate became NESTED. So `gate_enclosures()`
asks only what blocks enclose the gate, and the rule is that each must have
been opened by `try` — a `try` cannot decide whether its body runs, while an
`if`, a loop, a `switch` or a function can. That is a "this code LOOKS like
this" property, which is the half D-065 says to pin.

WHAT THIS TEST PROVES: that the nesting reading is right, in both directions,
on every shape that matters. **It exists at all because the detector it
replaced was verified four times by throwaway harnesses nobody re-ran, which
is precisely how v4 lost v1's case.**

@package WebMS\\Portal\\Tools
@author  MWBM Partners Ltd (t/a MWservices)
@copyright All Rights Reserved — MWBM Partners Ltd (t/a MWservices)
"""
from __future__ import annotations

import importlib.util
import pathlib
import sys
import tempfile

HERE = pathlib.Path(__file__).resolve().parent
GATE = HERE / "audit-checks" / "check_scheduled_jobs_recorded.py"

GATE_BODY = ("if ($expected === '' || hash_equals($expected, $incoming) === false) {\n"
             "    http_response_code(403);\n    exit('Forbidden');\n}\n")

# (name, PHP after the header, the enclosures expected, why it matters)
CASES: list[tuple[str, str, list[str], str]] = [
    ("at file scope, the ordinary shape", GATE_BODY, [], "14 of the 15 pages"),
    ("inside its own try, as health.php has it",
     "try {\n" + GATE_BODY + "} catch (\\Throwable $e) { throw $e; }\n", ["T_TRY"],
     "health.php — allowed, and the reason the rule is not 'depth 0'"),
    ("inside two nested trys",
     "try {\n try {\n" + GATE_BODY + " } finally { }\n} catch (\\Throwable $e) { }\n",
     ["T_TRY", "T_TRY"], "still only trys"),

    ("inside a REQUEST-CONTROLLED if",
     "if ((($_GET['debug'] ?? '') !== '1')) {\n" + GATE_BODY + "}\n", ["T_IF"],
     "round 6's bypass: ?debug=1 skips the gate entirely"),
    ("inside a plain if",
     "if (true) {\n" + GATE_BODY + "}\n", ["T_IF"], "any if can be made conditional"),
    ("inside a while",
     "while (false) {\n" + GATE_BODY + "}\n", ["T_WHILE"], "a loop that may not run"),
    ("inside a foreach",
     "foreach ([] as $x) {\n" + GATE_BODY + "}\n", ["T_FOREACH"], "an empty list skips it"),
    ("inside a function",
     "function gate(string $expected, string $incoming): void {\n" + GATE_BODY + "}\n",
     ["T_FUNCTION"], "a function nobody calls refuses nothing"),
    ("inside a closure",
     "$g = function (string $expected, string $incoming): void {\n" + GATE_BODY + "};\n",
     ["T_FUNCTION"], "the v1 fault's shape, caught by nesting instead"),
    ("inside a switch arm",
     "switch (1) {\n    case 1:\n" + GATE_BODY + "}\n", ["T_SWITCH"], "an arm that may not be taken"),
    ("inside a try INSIDE an if",
     "if (true) {\n try {\n" + GATE_BODY + " } finally { }\n}\n", ["T_IF", "T_TRY"],
     "a try does not launder an if above it"),
]


def main() -> int:
    spec = importlib.util.spec_from_file_location("gate", GATE)
    gate = importlib.util.module_from_spec(spec)
    try:
        spec.loader.exec_module(gate)
    except Exception as exc:                      # noqa: BLE001
        # ⚠️ REFUSE, DO NOT CRASH. A traceback is "a crash with a label", not a
        #    check that reports (#735). The previous version of this file did
        #    exactly that when the gate would not import.
        print("REFUSED: the gate module could not be loaded: %s" % exc)
        print("Nothing was checked. This is not a pass.")
        return 1

    good = bad = 0
    print("The #775 token-gate NESTING check, in both directions.\n")
    for name, php, want, why in CASES:
        with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as handle:
            handle.write("<?php\ndeclare(strict_types=1);\n"
                         "$incoming = (string) ($_GET['key'] ?? '');\n$expected = 'x';\n" + php)
            path = pathlib.Path(handle.name)
        try:
            got = gate.gate_enclosures(path)
        except gate.ProbeUnavailable as exc:
            print("REFUSED: %s" % exc)
            print("Nothing was checked. This is not a pass.")
            return 1
        finally:
            path.unlink(missing_ok=True)
        allowed = [kind for kind in got if kind != "T_TRY"]
        if got == want:
            good += 1
            print("  ok    %-44s %-22s %s"
                  % (name, "allowed" if len(allowed) == 0 else "REFUSED", why))
        else:
            bad += 1
            print("  FAIL  %-44s wanted %s, got %s" % (name, want, got))

    # ⚠️ THE COUNTS ARE PINNED, because the previous version's were not: deleting
    #    the two cases that cost rounds 4 and 5 left it printing "20 of 20" and
    #    exit 0. The printed total was `len(CASES)`, so it moved with the list —
    #    #697's recorded remedy, which is to pin the list itself.
    if len(CASES) != 11:
        print("\nFAIL: this file holds %d cases and must hold 11." % len(CASES))
        print("A case here is a shape the nesting rule has to get right. Losing")
        print("one silently is how the detector this replaced lost v1's case.")
        return 1
    must_refuse = len([c for c in CASES if [k for k in c[2] if k != "T_TRY"]])
    if (must_refuse, len(CASES) - must_refuse) != (8, 3):
        print("\nFAIL: the split moved — %d must be refused and %d allowed, "
              "expected 8 and 3." % (must_refuse, len(CASES) - must_refuse))
        return 1

    print("\n%d cases: %d as expected, %d not. 8 must be refused, 3 allowed."
          % (len(CASES), good, bad))
    if bad > 0:
        return 1
    print("NOT PROVED HERE: that a gate actually REFUSES. That is behavioural,")
    print("and section I-ALL of tools/scheduled-jobs-selftest.php drives it on")
    print("all fifteen pages (D-065).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
