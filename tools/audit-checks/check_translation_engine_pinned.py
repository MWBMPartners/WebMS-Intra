#!/usr/bin/env python3
r"""
tools/audit-checks/check_translation_engine_pinned.py

The translation ENGINE's code is pinned whole (#762, D-059, D-065).

@package   WebMS\Portal\Tools
@author    MWBM Partners Ltd (t/a MWservices)
@copyright All Rights Reserved — MWBM Partners Ltd (t/a MWservices)
@version   1.0.0

WHAT THIS GUARDS
================
`web/_core/Translation.php` decides whether a PAID translation is checked
against the organisation's two monthly limits (money and number of requests,
D-059) and what cost reaches the month's record. No test here can reach a paid
provider — every behavioural check of the limits runs on LibreTranslate pointed
at a stub, which reports a cost of 0 — so a change that spares LibreTranslate
and switches the limits off for every PAID provider passes every behavioural
test there is.

WHY THE WHOLE FILE, AND NOT LESS — review rounds 1 to 5, each defeated by one
line just outside the region pinned before it (the #485 and #763 ending, met
again):

  1. two substrings pinned → `$result['costPence'] = 0;` above the return;
  2. from the provider call → `$month = '1999-01-01';` above it;
  3. from the provider choice → a paid-provider shortcut one line above that;
  4. the whole of `translate()` → the same shortcut moved into a method it
     calls (`lookupCached()`, `capPence()`, `callCap()`, `settle()`), and a
     second copy of `translate()` whose name differed only in CAPITALS (PHP
     ignores case in method names, the pin's name test did not).

Every region smaller than the file has an outside; the file is the unit PHP
loads, so pinning it leaves no line in the engine unpinned.

AND ROUND 6 FOUND THAT A PINNED LINE CAN STILL CHANGE MEANING. Inside
`namespace Portal\Core`, an UNQUALIFIED call such as `ceil(...)` or constant
such as `CURLOPT_POST` is looked up in THAT namespace first and only then in
PHP's own — so ONE line in any of the ~150 other `Portal\Core` files
(`function ceil(...)` returning 0) zeroed every paid provider's recorded cost
with this pin, every audit script and the self-test all green (measured: 2400
pence became 0). Every built-in in the engine is therefore written with a
leading `\`, which this pin then protects, and the check REFUSES any
unqualified built-in function or constant in the file, so a new one cannot
quietly reopen the hole.

HOW THAT REFUSAL DECIDES, AND WHY THIS WAY ROUND. Round 7 found the first
version listed positions to SKIP (after `case`, before a `:`), and two of
those skips also hid real lookups: the middle of a ternary
(`$a ? CURLOPT_TIMEOUT : 30`) and a `switch` case (`case CURLE_OK:`) — both
shadowable, both measured, both passing the gate and repinnable. The #763
lesson again: a list of the shapes you thought of is not a rule. So it is now
the other way round. A name equal to a built-in function (followed by `(`) or
a built-in constant is refused in EVERY position except the four that can
never be a lookup in this namespace: after `->`, `?->` or `::`, as a function's
own name, and as a named argument (`(` or `,` before it, `:` after it).
Anything rarer — a goto label, an enum case or a declared constant that
happens to share a built-in's name — is refused too. That is the safe
direction, and none of them is in the engine.

It needs PHP with `curl` and `mbstring`: without them `curl_*`, `CURLOPT_*` and
`mb_*` are not built-ins to this PHP and the refusal would quietly cover less,
so it REFUSES instead.

HOW IT IS READ
==============
By PHP's own tokeniser (`token_get_all()`), never a regular expression: this
repository's lesson from #763 is that "is this code" questions are in the
GRAMMAR, not the text. Comments and whitespace are DROPPED and every other
token is written on its own line, JSON-encoded so a token holding a line break
(a heredoc body) stays one line. So:

  * any change to the code — a statement, an operator, a name, a string
    literal's contents, even a single character — goes red;
  * a change ONLY to comments, indentation, blank lines or the spacing between
    tokens (`if(` against `if (`) stays green, because those are not tokens.

`--repin` regenerates the pinned file, as a separate, visible act: re-read the
changed code first, and ask of it every bypass listed above.

WHAT IT CANNOT SEE, said plainly
================================
The CODE of the other classes and pages the engine works with. What no longer
matters is any of them silently REPLACING a function or constant the engine
calls — that is closed above. What still matters, because those are real
classes the engine calls by design: `SettingLimit` (the three-tier arithmetic,
with its own self-test), `App::settings()`, `Site::id()`, `ContentTranslation`,
and the one caller, `web/_apps/translation/api/content.php`, which could in
principle reach a provider by some other route. Any of them could be edited to
treat paid providers differently, and no behavioural test here reaches a paid
provider. A gate that pinned them too would move this edge outwards again
without removing it; review of a change to any of them is what covers it.

It needs `php` on the PATH. Without it the check REFUSES and says nothing was
checked — it never passes by default.
"""
from __future__ import annotations

import os
import subprocess
import sys

ENGINE = os.path.join("web", "_core", "Translation.php")
PINNED = os.path.join("tools", "audit-checks", "translation-engine.pinned.txt")

# ⚠️ NO `<?php` HERE: `php -r` is already in PHP mode and refuses the tag.
# Exit 3: unreadable. 4: a token JSON cannot encode (an empty line would make
# two different invalid literals compare equal — round 6). 5: unqualified
# built-ins, listed on stderr, one "line kind name" per line.
PROBE = (
    '$src = file_get_contents($argv[1]);'
    'if ($src === false) { fwrite(STDERR, "unreadable"); exit(3); }'
    '$toks = token_get_all($src); $out = "";'
    'foreach ($toks as $t) {'
    '  if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }'
    '  $j = json_encode(is_array($t) ? $t[1] : $t, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);'
    '  if ($j === false) { fwrite(STDERR, "unencodable token"); exit(4); }'
    '  $out .= $j . "\\n";'
    '}'
    '$consts = [];'
    'foreach (get_defined_constants(true) as $g => $l) { if ($g !== "user") { foreach ($l as $k => $_) { $consts[$k] = true; } } }'
    'unset($consts["TRUE"], $consts["FALSE"], $consts["NULL"]);'
    'if (!extension_loaded("curl") || !extension_loaded("mbstring")) { fwrite(STDERR, "needs curl and mbstring"); exit(6); }'
    '$skip = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION];'
    '$sig = static fn ($t): bool => !(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true));'
    '$bad = []; $n = count($toks);'
    'for ($i = 0; $i < $n; $i++) { $t = $toks[$i];'
    '  if (!is_array($t) || $t[0] !== T_STRING) { continue; }'
    '  $p = $i - 1; while ($p >= 0 && !$sig($toks[$p])) { $p--; }'
    '  $q = $i + 1; while ($q < $n && !$sig($toks[$q])) { $q++; }'
    '  $prev = $p >= 0 ? $toks[$p] : null; $next = $q < $n ? $toks[$q] : null;'
    '  if (is_array($prev) && in_array($prev[0], $skip, true)) { continue; }'
    '  if ($next === ":" && ($prev === "(" || $prev === ",")) { continue; }'
    '  if ($next === "(" && function_exists($t[1]) && (new ReflectionFunction($t[1]))->isInternal()) {'
    '    $bad[] = $t[2] . " function " . $t[1]; continue; }'
    '  if (isset($consts[$t[1]])) { $bad[] = $t[2] . " constant " . $t[1]; }'
    '}'
    'if ($bad !== []) { fwrite(STDERR, implode("\\n", $bad)); exit(5); }'
    'echo $out;'
)
MIN_TOKENS = 1000


def tokens() -> str:
    """The engine's code, one JSON-encoded token per line, or SystemExit."""
    if os.path.isfile(ENGINE) is False:
        print("REFUSED: %s does not exist, so nothing was compared." % ENGINE)
        raise SystemExit(1)
    try:
        lint = subprocess.run(["php", "-l", ENGINE], capture_output=True, text=True)
    except FileNotFoundError:
        print("REFUSED: `php` is not on the PATH, so the translation engine was not read.")
        print("Nothing was checked. This is not a pass.")
        raise SystemExit(1)
    if lint.returncode != 0:
        # ⚠️ ASK `php -l` FIRST: `token_get_all()` does not fail on a file that
        #    will not parse, so a broken engine would be compared as if it were
        #    code (#775 round 10's lesson).
        print("REFUSED: %s does not parse, so it was not compared." % ENGINE)
        print(lint.stdout + lint.stderr)
        raise SystemExit(1)
    run = subprocess.run(["php", "-r", PROBE, ENGINE], capture_output=True, text=True)
    if run.returncode == 5:
        print("Translation engine: %s calls a BUILT-IN function or constant by its short name." % ENGINE)
        print("Inside `namespace Portal\\Core` such a name is looked up in that namespace first, so one")
        print("line in any other Portal\\Core file could replace it and, for example, zero every paid")
        print("provider's recorded cost with every check green (#762 round 6). Write each with a")
        print("leading backslash (`\\ceil(`, `\\CURLOPT_POST`). Found (line, kind, name):")
        for line in run.stderr.splitlines():
            print("    " + line)
        raise SystemExit(1)
    if run.returncode == 6:
        print("REFUSED: this PHP lacks the curl or mbstring extension, so it would not recognise")
        print("`curl_*`, `CURLOPT_*` or `mb_*` as built-ins and the refusal below would quietly")
        print("cover less. Nothing was checked. Run it with a PHP that has both.")
        raise SystemExit(1)
    if run.returncode != 0:
        print("REFUSED: php could not read %s (%s)." % (ENGINE, run.stderr.strip() or "no detail"))
        raise SystemExit(1)
    return run.stdout


def main() -> int:
    current = tokens()
    if "--repin" in sys.argv[1:]:
        # A floor, so a repin cannot quietly pin an emptied or gutted engine.
        if current.count("\n") < MIN_TOKENS or '"Translation"' not in current:
            print("REFUSED to repin: %s has %d tokens and %s the Translation class — that is not "
                  "the engine. Nothing was written." % (ENGINE, current.count("\n"),
                  "holds" if '"Translation"' in current else "does NOT hold"))
            return 1
        with open(PINNED, "w", encoding="utf-8") as handle:
            handle.write(current)
        print("Repinned %s from %s (%d tokens)." % (PINNED, ENGINE, current.count("\n")))
        return 0

    try:
        with open(PINNED, encoding="utf-8") as handle:
            pinned = handle.read()
    except FileNotFoundError:
        print("REFUSED: %s is missing, so there is nothing to compare with." % PINNED)
        return 1

    if current == pinned:
        print("Translation engine: %s matches its pin (%d tokens). Comments and "
              "spacing are free; any change to the code fails here." % (ENGINE, current.count("\n")))
        print("Every built-in it calls is written with a leading backslash, so no other file can "
              "replace one. NOT CHECKED HERE: the code of SettingLimit, App, Site, "
              "ContentTranslation and the endpoint that calls the engine.")
        return 0

    # Show WHERE, so the person reading this can judge the change.
    a = pinned.splitlines()
    b = current.splitlines()
    first = next((i for i in range(min(len(a), len(b))) if a[i] != b[i]), min(len(a), len(b)))
    print("Translation engine: %s has CHANGED since it was pinned (#762)." % ENGINE)
    print("First difference at token %d:" % (first + 1))
    print("  pinned:  " + " ".join(a[max(0, first - 3):first + 4]))
    print("  now:     " + " ".join(b[max(0, first - 3):first + 4]))
    print("")
    print("This file decides whether a PAID translation is checked against both monthly")
    print("limits and what cost is recorded, and no test can reach a paid provider. Six")
    print("review rounds each switched the limits off for paid providers with one line")
    print("that every behavioural test passed. If this change is deliberate, re-read it")
    print("against the bypasses in this script's header, then run:")
    print("    python3 tools/audit-checks/check_translation_engine_pinned.py --repin")
    return 1


if __name__ == "__main__":
    sys.exit(main())
