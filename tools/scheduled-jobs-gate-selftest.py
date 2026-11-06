#!/usr/bin/env python3
"""
The test OF the #775 hard gate's token-refusal detector.
======================================================================

WHY THIS EXISTS, and it is the whole point: `gate_refuses()` in
`tools/audit-checks/check_scheduled_jobs_recorded.py` has been WRONG IN BOTH
DIRECTIONS THREE TIMES across five review rounds, and every version was
"verified by planting" the shapes its author had thought of — with a throwaway
harness that nobody re-ran. **That is why v4 failed v1's own case by a new
spelling.**

    v1  passed an exit inside a braced closure.
    v2  cried wolf on a real exit one level deeper; passed `$log->exit();`.
    v3  asked the tokeniser; passed `fn() => exit()`; cried wolf on a
        braceless gate.
    v4  skipped an arrow body but STOPPED at a `{`, so
        `fn() => match (true) { default => exit('x') }` passed — v1's fault
        again; and read `$matches = hash_equals(...)` above the gate as
        braceless, going RED on correct code.

A fifth cleverer pattern would have produced a round 6. **An accumulating,
COMMITTED plant set will not**, because every earlier round's case is re-run on
every push. This follows `tools/api-spec-check-selftest.py`, which the
repository already uses for the same reason: a check is only as good as the
test of the check.

WHAT IT PROVES. For each case below: that `gate_refuses()` answers what the
case says it should. A case marked REFUSES is a gate that really ends the
request; one marked DOES NOT is a gate that does not, whatever it looks like.
Getting either wrong has a cost, and they are different costs:

  * a false PASS lets an unauthenticated request run on — it writes the record
    whose whole purpose is to be truthful, and then does the job's real work;
  * a false RED is a hard gate in `pr-security.yml` accusing correct code, which
    blocks every pull request (#483).

WHAT IT CANNOT PROVE. That the gate's OTHER checks are right (the job list, the
call counts, the ordering) — those are the check's own concern — nor that any of
this RUNS, which is `tools/scheduled-jobs-selftest.php`'s section I, over real
HTTP.

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

HEAD = "<?php\ndeclare(strict_types=1);\n$incoming = (string) ($_GET['key'] ?? '');\n$expected = 'x';\n"

# Each case: (name, the PHP, whether the gate really refuses, which round found it)
CASES: list[tuple[str, str, bool, str]] = [
    # ── Gates that really DO refuse ───────────────────────────────────────
    ("the ordinary shape",
     "if ($expected === '' || hash_equals($expected, $incoming) === false) {\n"
     "    http_response_code(403);\n    exit('Forbidden');\n}\n", True, "v1"),
    ("die instead of exit",
     "if (hash_equals($expected, $incoming) === false) {\n    die('Forbidden');\n}\n", True, "v1"),
    ("an exit one level deeper, in an if/else",
     "if (hash_equals($expected, $incoming) === false) {\n"
     "    if (($_GET['json'] ?? '') === '1') {\n        exit('{}');\n"
     "    } else {\n        exit('Forbidden');\n    }\n}\n", True, "v2 cried wolf"),
    ("an exit in a switch arm",
     "if (hash_equals($expected, $incoming) === false) {\n"
     "    switch ($_GET['f'] ?? '') {\n        case 'json': exit('{}');\n"
     "        default: exit('Forbidden');\n    }\n}\n", True, "v2 cried wolf"),
    ("an exit in a match arm",
     "if (hash_equals($expected, $incoming) === false) {\n"
     "    match (true) { default => exit('Forbidden') };\n}\n", True, "v3"),
    ("an exit inside try/finally",
     "if (hash_equals($expected, $incoming) === false) {\n"
     "    try { exit('Forbidden'); } finally { }\n}\n", True, "v3"),
    ("a BRACELESS gate",
     "if (hash_equals($expected, $incoming) === false) exit('Forbidden');\n", True, "v3 cried wolf"),
    ("an arrow function BESIDE a real exit",
     "if (hash_equals($expected, $incoming) === false) {\n"
     "    $fmt = fn(string $m): string => $m;\n    exit($fmt('Forbidden'));\n}\n", True, "v4"),
    ("the comparison read into a variable FIRST",
     "$matches = hash_equals($expected, $incoming);\n"
     "if ($expected === '' || $matches === false) {\n"
     "    http_response_code(403);\n    exit('Forbidden');\n}\n", True, "v4 cried wolf"),
    ("an elseif gate",
     "if ($expected === '') {\n    exit('Forbidden');\n"
     "} elseif (hash_equals($expected, $incoming) === false) {\n    exit('Forbidden');\n}\n",
     True, "v5"),

    # ── Gates that do NOT refuse, however they look ───────────────────────
    ("no exit at all",
     "if (hash_equals($expected, $incoming) === false) {\n    http_response_code(403);\n}\n",
     False, "v1"),
    ("an exit inside a braced closure merely DEFINED",
     "if (hash_equals($expected, $incoming) === false) {\n"
     "    $stop = static function (): void { exit('Forbidden'); };\n}\n", False, "v1"),
    ("a METHOD named exit",
     "if (hash_equals($expected, $incoming) === false) {\n"
     "    http_response_code(403);\n    $log->exit();\n}\n", False, "v2"),
    ("an ARROW function, typed",
     "if (hash_equals($expected, $incoming) === false) {\n"
     "    $stop = fn(): never => exit('Forbidden');\n}\n", False, "v3"),
    ("an ARROW function, untyped",
     "if (hash_equals($expected, $incoming) === false) {\n"
     "    $stop = fn() => exit('Forbidden');\n}\n", False, "v3"),
    ("an arrow whose body is a MATCH",
     "if (hash_equals($expected, $incoming) === false) {\n"
     "    $stop = fn() => match (true) { default => exit('Forbidden') };\n}\n", False, "v4"),
    ("an arrow whose body is a CLOSURE",
     "if (hash_equals($expected, $incoming) === false) {\n"
     "    $stop = fn() => function (): never { exit('Forbidden'); };\n}\n", False, "v4"),
    ("a nested arrow whose body is a closure",
     "if (hash_equals($expected, $incoming) === false) {\n"
     "    $stop = fn() => fn() => function (): never { exit('no'); };\n}\n", False, "v4"),
    ("a STATIC arrow whose body is a match",
     "if (hash_equals($expected, $incoming) === false) {\n"
     "    $stop = static fn() => match (true) { default => exit('no') };\n}\n", False, "v4"),
    ("an arrow in an ARRAY literal",
     "if (hash_equals($expected, $incoming) === false) {\n"
     "    $h = ['stop' => fn() => match (true) { default => exit('no') }];\n}\n", False, "v4"),
    ("an anonymous CLASS with an exiting method",
     "if (hash_equals($expected, $incoming) === false) {\n"
     "    $o = new class { public function go(): never { exit('no'); } };\n}\n", False, "v4"),
    ("`exit` only inside a string",
     "if (hash_equals($expected, $incoming) === false) {\n"
     "    $m = 'exit now';\n    http_response_code(403);\n}\n", False, "v3"),
]


def load_gate():
    spec = importlib.util.spec_from_file_location("gate", GATE)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def main() -> int:
    gate = load_gate()
    good = bad = 0
    print("The #775 token-gate detector, every case from five review rounds.\n")
    for name, php, refuses, found in CASES:
        with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as handle:
            handle.write(HEAD + php)
            path = pathlib.Path(handle.name)
        try:
            code = gate.php_code_only(path)
            at = code.find("hash_equals")
            if at == -1:
                print("  FAIL  %-52s the probe lost `hash_equals`" % name)
                bad += 1
                continue
            got = gate.gate_refuses(code, at)
        except gate.ProbeUnavailable as exc:
            print("REFUSED: %s" % exc)
            print("Nothing was checked. This is not a pass.")
            return 1
        finally:
            path.unlink(missing_ok=True)
        if got == refuses:
            good += 1
            print("  ok    %-52s %s (%s)"
                  % (name, "refuses" if refuses else "does NOT refuse", found))
        else:
            bad += 1
            print("  FAIL  %-52s wanted %s, got %s  [%s]"
                  % (name, refuses, got, found))
    print("\n%d cases: %d as expected, %d not." % (len(CASES), good, bad))
    if bad > 0:
        print("A case here is a shape some earlier version of this detector got")
        print("wrong. Losing one again is how v4 re-created v1's fault.")
        return 1
    print("NOT PROVED HERE: that any of it RUNS. That is section I of")
    print("tools/scheduled-jobs-selftest.php, over real HTTP.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
