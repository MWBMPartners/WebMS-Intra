#!/usr/bin/env python3
r"""
-------------------------------------------------------------------------------
 File:        tools/audit-checks/check_no_curl_close.py
 Description: Refuses any call to a cURL close function that PHP 8.5 deprecated.
              The portal targets PHP 8.5 (backward-compatible with 8.4), and
              every such call writes a "Deprecated" line to the error log each
              time it runs.
 Package:     Portal\\Tools\\AuditChecks
 Author:      MWBM Partners Ltd (t/a MWservices)
 Copyright:   2026-present MWBM Partners Ltd (t/a MWservices) - All Rights Reserved
 Version:     1.0.0
 Link:        https://github.com/MWBMPartners/WebMS-Intra/issues/673
-------------------------------------------------------------------------------

WHY THIS EXISTS
    `curl_close()` has done nothing since PHP 8.0, when the cURL handle became
    an object that is freed when it goes out of scope. PHP 8.5 deprecated it.
    #673 removed 37 calls across 15 files -- payments, text messages, Zoom,
    geocoding, transcription, translation, the webhook sender, the asset
    register, the hymnal and six others. Each wrote a deprecation line every
    time it ran, which both fills the log and buries real faults in it.

    NOT every outside call the portal makes: `SafeFetch`, `WebPush`,
    `MailerGoogle` and `Auth` had already removed theirs and left a note
    saying why, so fetching an address a person typed, sending a push and
    sending mail were never affected. Saying otherwise overstates the fault,
    which on this project counts as a real problem in its own right.

    There is never a reason to call it, so this check has NO exemption list.
    That is deliberate: a list would need a line per caller and would go stale
    the first time somebody forgot.

WHAT IT COVERS, AND WHAT IT DELIBERATELY DOES NOT
    Measured on PHP 8.5.11 rather than assumed, because flagging the wrong one
    would make this check cry wolf, and a check that cries wolf gets switched
    off:

        curl_close()        deprecated since 8.5   -> REFUSED
        curl_share_close()  deprecated since 8.5   -> REFUSED
        curl_multi_close()  NO deprecation notice  -> allowed, not flagged

    `curl_multi_close()` is NOT covered. It raised no notice when tested, so
    treating it the same way would refuse a call PHP is happy with.

HOW IT READS THE CODE, AND WHY NOT BY SEARCHING FOR TEXT
    It asks PHP's own tokeniser, through `php -r`, instead of searching the
    text. Searching would be wrong in both directions here:

      * **21 lines across 19 files** under `web/` MENTION `curl_close` in a
        comment to explain why there is no call, and none of them is a call.
        A text search reports every one as a fault. It was 6 before this
        package, in the four files that had already removed theirs; this
        package added the other 15 notes, so the figure more than trebled in
        the same change -- an earlier draft of this line still said six.
        Count it rather than trusting this number:
        `grep -rn curl_close web --include="*.php" | wc -l`.
      * The NAME inside a string -- `$fn = 'curl_close';` -- is not a call, and
        a text search would report it as one. The tokeniser ignores it, which
        is what the selftest's "the name in a string" case pins.
      * Comment-stripping by hand has been got wrong three times on this
        project: an apostrophe in `// don't` puts every later quote out of
        step, so the tokeniser is used rather than re-implemented.

    It handles BOTH spellings: a bare `curl_close()` (T_STRING) and a
    fully-qualified `\curl_close()` (T_NAME_FULLY_QUALIFIED). The first version
    of this check looked only at T_STRING and therefore reported CLEAN on a
    real, live deprecation -- the independent check found it by planting
    `\curl_close($ch);` into a namespaced file. That spelling is not exotic:
    a leading backslash is ordinary style in namespaced code, and fourteen of
    the fifteen files this package changed are `namespace Portal\Core;`.
    (An earlier draft also claimed php-cs-fixer's `native_function_invocation`
    rule writes `\curl_close(` by itself. That is DROPPED rather than kept:
    the rule's default `include` is `@compiler_optimized`, about 39 functions
    the Zend compiler special-cases, and `curl_close` is not one of them -- so
    it holds only when the rule is configured `@all` or `@internal`. There is
    no php-cs-fixer on this machine to settle it, and the argument does not
    need it.)
    (Said precisely, because a looser version of this sentence was wrong:
    `web/_core/` holds 190 PHP files, of which 105 are `namespace Portal\Core;`,
    15 are `MWBM\Mail`, and 70 -- everything under `_core/apps/`,
    `_core/templates/`, `_core/partials/`, plus `bootstrap.php` and a few
    others -- declare no namespace at all.)
    A name that still holds a `\` after the leading one is stripped, such as
    `\Vendor\curl_close`, is somebody else's function and is left alone. That
    guard is belt-and-braces rather than load-bearing: such a name is
    `T_NAME_QUALIFIED`, which is never an accepted token type in the first
    place.

    It also refuses to pass when it cannot run. A check that quietly succeeds
    because its tool is missing is the #646 failure: twelve guards that looked
    wired and ran nowhere. So a missing `php`, a tokeniser that returns
    nothing, or a scan that reads zero files is a FAILURE, not a pass.

WHAT IT CANNOT SEE
    Four shapes, each measured on PHP 8.5.11 to confirm PHP really does
    deprecate it. None is present in this repository and two are shapes nobody
    writes -- but stating ONE limit when there are four is the
    overstated-guarantee fault this project keeps finding, so here they are:

      * A call built at run time: `$fn = 'curl_' . 'close'; $fn($ch);`. That is
        a variable call, not a named one.
      * `call_user_func('curl_close', $ch)` -- the name is a string literal.
      * An import alias: `use function curl_close as cc;` then `cc($ch);`.
        Neither half is visible: the alias is not the name, and the
        `use function` line's own `curl_close` is skipped because the token
        before it is T_FUNCTION -- the method-declaration guard doing double
        duty.
      * `namespace\curl_close($ch);` in a file that declares NO namespace.
        That is T_NAME_RELATIVE, which is not an accepted token type. Inside a
        namespace the same text is a fatal error, so only the global case is
        reachable -- and 70 of the 190 files under `web/_core/` declare no
        namespace, as does everything under `web/_apps/`.

    IT ALSO READS `web/` ONLY. The self-tests under `tools/` make cURL calls on
    PHP 8.5 too -- `tools/page-head-selftest.php` records this very deprecation
    printing "a warning for every page this test fetched" -- so a call added to
    one of those would not be caught here. The scope is deliberate, because the
    guard is about the portal, but it is a limit rather than the absence of one.

    Run `--selftest` to prove both directions still work.
"""

import json
import pathlib
import subprocess
import sys
import tempfile

# The two functions PHP 8.5 deprecated. Measured, not assumed -- see the header.
# Keep these LOWERCASE. The scan lowercases this list as well as each token it
# finds; because the list is already lowercase that second fold is a no-op, so
# NO TEST CAN PIN IT -- removing it was measured and changes not one hit. It is
# kept as cover for a future edit adding a mixed-case entry here. Said out loud
# because an independent check reported its removal as an unguarded fault, and
# a plant that changes no behaviour proves nothing either way.
REFUSED = ("curl_close", "curl_share_close")

REPO = pathlib.Path(__file__).resolve().parents[2]
WEB = REPO / "web"

# A tiny PHP program: walk the tree, tokenise each file, report real calls as
# JSON. Only T_STRING tokens count, so comments and strings never match.
PHP_SCAN = r"""
$root = $argv[1];
$names = array_map('strtolower', explode(',', $argv[2]));
$hits = []; $scanned = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($it as $f) {
    if ($f->isFile() === false || strtolower($f->getExtension()) !== 'php') { continue; }
    $scanned++;
    $tokens = token_get_all(file_get_contents($f->getPathname()));
    foreach ($tokens as $i => $t) {
        if (is_array($t) === false) { continue; }
        // Two token types, not one. PHP tokenises a bare `curl_close` as
        // T_STRING but a leading-backslash `\curl_close` as
        // T_NAME_FULLY_QUALIFIED. A leading backslash is ordinary style in
        // namespaced code, and 14 of the 15 files #673 changed are
        // `namespace Portal\Core;`. Looking only at T_STRING made this check
        // report CLEAN on a real, live deprecation; the independent check
        // caught that by planting it. The name is lowercased before it is
        // compared because PHP function names are case-insensitive, so
        // `CURL_CLOSE($ch)` is a real deprecation too -- measured.
        if (in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) === false) { continue; }
        $bare = ltrim($t[1], '\\');
        // `\Vendor\curl_close` is somebody else's function that merely ends in
        // the same word, so a name with a separator left in it is not ours.
        if (str_contains($bare, '\\') === true) { continue; }
        if (in_array(strtolower($bare), $names, true) === false) { continue; }
        // A method or property of the same name is not the global function.
        $prev = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
            $prev = $tokens[$j]; break;
        }
        if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR], true)) { continue; }
        $hits[] = ['file' => $f->getPathname(), 'line' => $t[2], 'name' => $t[1]];
    }
}
echo json_encode(['scanned' => $scanned, 'hits' => $hits]);
"""


def scan(root: pathlib.Path):
    """Tokenise every PHP file under `root`. Returns (scanned, hits) or exits."""
    try:
        proc = subprocess.run(
            ["php", "-r", PHP_SCAN, "--", str(root), ",".join(REFUSED)],
            capture_output=True, text=True, timeout=180,
        )
    except FileNotFoundError:
        print("FAIL: php is not on PATH, so this check could not run at all.")
        print("      A check that cannot run must never report success (#646).")
        sys.exit(2)
    except subprocess.TimeoutExpired:
        print("FAIL: the tokeniser did not finish within 180 seconds.")
        sys.exit(2)

    if proc.returncode != 0 or proc.stdout.strip() == "":
        print("FAIL: the tokeniser produced no answer, so nothing was checked.")
        if proc.stderr.strip() != "":
            print("      php said: " + proc.stderr.strip().splitlines()[0])
        sys.exit(2)

    data = json.loads(proc.stdout)
    return data["scanned"], data["hits"]


def selftest() -> int:
    """Prove the check fails on a real call and passes on a comment mention.

    Both directions matter. A guard that only ever says "clean" is worse than
    no guard, because it is believed.
    """
    cases = [
        # (name, PHP body, must this be refused?)
        ("a real call",              "<?php $c = curl_init(); curl_close($c);",                       True),
        ("a share close",            "<?php $s = curl_share_init(); curl_share_close($s);",           True),
        ("a comment mentioning it",  "<?php // no curl_close() needed here\n$c = curl_init();",       False),
        ("a doc block mentioning it","<?php /** curl_close() is deprecated */\n$c = curl_init();",    False),
        ("the name in a string",     "<?php $s = 'curl_close';",                                      False),
        ("curl_multi_close",         "<?php $m = curl_multi_init(); curl_multi_close($m);",           False),
        ("a method of that name",    "<?php class A { public function curl_close() {} }\n$a = new A(); $a->curl_close();", False),
        ("an apostrophe then a call","<?php // don't do this\ncurl_close($c);",                       True),
        # The fully-qualified spelling. The FIRST version of this check looked
        # only at T_STRING and so reported CLEAN on both of these, which the
        # independent check found by planting one in a namespaced file.
        ("a fully-qualified call",   "<?php \\curl_close($c);",                                       True),
        ("one inside a namespace",   "<?php namespace Portal\\Core;\n\\curl_close($c);",              True),
        ("a fully-qualified share",  "<?php \\curl_share_close($s);",                                 True),
        # The cry-wolf direction: somebody else's function that merely ends in
        # the same word must NOT be refused.
        ("another package's call",   "<?php \\Vendor\\Http\\curl_close($c);",                          False),
        ("a relative namespaced one","<?php namespace A;\nVendor\\curl_close($c);",                    False),
        # PHP function names are case-insensitive, so these are REAL, live
        # deprecations -- measured on 8.5.11. Neither case-folding site was
        # pinned by any case, so removing `strtolower` from either side left
        # all 17 green while the check reported clean on a file holding both.
        # That is the #673 fault pattern a third time: the untested half is
        # the half one edit can neuter.
        ("an uppercase call",        "<?php CURL_CLOSE($c);",                                        True),
        ("an uppercase qualified one","<?php \\CURL_CLOSE($c);",                                      True),
        ("a mixed-case call",        "<?php Curl_Close($c);",                                        True),
    ]
    failures = 0
    with tempfile.TemporaryDirectory() as tmp:
        box = pathlib.Path(tmp)
        for i, (name, body, should_refuse) in enumerate(cases):
            for stale in box.glob("*.php"):
                stale.unlink()
            (box / ("case%d.php" % i)).write_text(body)
            scanned, hits = scan(box)
            refused = len(hits) > 0
            ok = refused == should_refuse
            if ok is False:
                failures += 1
            print("%s %-28s expected %-8s got %-8s (scanned %d)" % (
                "✓" if ok else "✗:", name,
                "refuse" if should_refuse else "pass",
                "refuse" if refused else "pass", scanned))

        # Now exercise main() ITSELF, not just scan(). Its verdict and its
        # vacuity guard are the two parts a one-character edit can neuter
        # while every case above stays green -- measured, not supposed.
        verdicts = [
            ("main: a real call is refused",  "<?php curl_close($c);",       1),
            ("main: a backslash call too",    "<?php \\curl_close($c);",      1),
            ("main: a clean file passes",     "<?php $c = curl_init();",     0),
        ]
        for name, body, want in verdicts:
            for stale in box.glob("*.php"):
                stale.unlink()
            (box / "page.php").write_text(body)
            got = main(box, quiet=True)
            ok = got == want
            if ok is False:
                failures += 1
            print("%s %-28s main() exit expected %d got %d" % (
                "✓" if ok else "✗:", name, want, got))

        # And an empty folder must make main() FAIL rather than report clean.
        for stale in box.glob("*.php"):
            stale.unlink()
        got = main(box, quiet=True)
        ok = got == 2
        if ok is False:
            failures += 1
        print("%s %-28s main() exit expected 2 got %d (nothing read is not a pass)"
              % ("✓" if ok else "✗:", "main: nothing to scan", got))

        # The last vacuity path: a root that is not there at all. Its `return 2`
        # was the one branch no case reached, so it could have been changed to
        # 0 with everything still green.
        got = main(box / "no-such-folder", quiet=True)
        ok = got == 2
        if ok is False:
            failures += 1
        print("%s %-28s main() exit expected 2 got %d (a missing root is not a pass)"
              % ("✓" if ok else "✗:", "main: no such root", got))

    total = len(cases) + 5
    print()
    if failures == 0:
        print("✓ selftest: all %d cases behaved as expected" % total)
        return 0
    print("✗ selftest: %d of %d case(s) did not behave as expected" % (failures, total))
    return 1


def main(root: pathlib.Path = WEB, quiet: bool = False) -> int:
    """The real verdict. `root` is a parameter so the selftest can exercise
    THIS function and not merely `scan()`.

    That matters: the independent check of #673 showed that changing
    `if len(hits) == 0` to `>= 0`, or deleting the vacuity guard, left the
    nine scan-level cases all green while the check reported CLEAN on a tree
    holding a real call. The untested half was exactly the half that could be
    neutered, which is this project's oldest lesson about checks.
    """
    say = (lambda *a: None) if quiet is True else print

    if root.is_dir() is False:
        say("FAIL: %s does not exist, so nothing was checked." % root)
        return 2

    scanned, hits = scan(root)

    # Not vacuous: if the scan read no files, the answer "clean" means nothing.
    if scanned == 0:
        say("FAIL: no PHP files were read under %s, so this check proved nothing." % root)
        return 2

    if len(hits) == 0:
        say("✓ no deprecated cURL close call under %s (%d PHP files read)"
            % (root.name, scanned))
        say("  refused: %s   allowed: curl_multi_close (not deprecated)" % ", ".join(
            f + "()" for f in REFUSED))
        return 0

    say("✗ %d deprecated cURL close call(s) found (%d PHP files read):" % (len(hits), scanned))
    for h in hits:
        here = pathlib.Path(h["file"]).resolve()
        try:
            shown = here.relative_to(REPO)
        except ValueError:
            shown = here
        say("    %s:%d  %s()" % (shown, h["line"], h["name"]))
    say("")
    say("  These have done nothing since PHP 8.0 and PHP 8.5 deprecates them, so")
    say("  each call writes a 'Deprecated' line to the error log. Delete the call;")
    say("  the handle is freed when it goes out of scope. See #673 for the note")
    say("  the other callers carry in its place.")
    return 1


if __name__ == "__main__":
    # --selftest must be dispatched HERE, not inside main(): main() now takes a
    # root so the selftest can call it, and an early return inside it would
    # recurse. When this dispatch was briefly missing, `--selftest` quietly ran
    # the ordinary check and reported success -- a test that does not run is
    # the #646 failure in miniature.
    sys.exit(selftest() if "--selftest" in sys.argv else main())
