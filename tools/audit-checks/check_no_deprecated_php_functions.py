#!/usr/bin/env python3
r"""
-------------------------------------------------------------------------------
 File:        tools/audit-checks/check_no_deprecated_php_functions.py
 Description: Refuses a call to any PHP function that PHP itself marks as
              deprecated, under web/ and tools/. The portal recommends PHP 8.5,
              and each such call writes a "Deprecated" line into the error log
              every time it runs.
 Package:     Portal\\Tools\\AuditChecks
 Author:      MWBM Partners Ltd (t/a MWservices)
 Copyright:   2026-present MWBM Partners Ltd (t/a MWservices) - All Rights Reserved
 Version:     1.0.0
 Link:        https://github.com/MWBMPartners/WebMS-Intra/issues/1003
-------------------------------------------------------------------------------

WHY THIS EXISTS
    #673 removed every `curl_close()` and wrote `check_no_curl_close.py` to
    keep them out. That guarded ONE NAME of a class, and the class came back
    under another name: `finfo_close()`, deprecated by the same PHP 8.5 for the
    same reason (the object frees itself), was still called by EIGHT upload
    handlers (#1003). Nobody noticed until #998's upload test found a
    `tblErrors` row reading "Function finfo_close() is deprecated since 8.5".
    A check per function name would need a new check per deprecation. This one
    asks PHP which functions are deprecated, so the next one is covered the day
    somebody runs it on a PHP that deprecates it.

WHERE THE LIST COMES FROM
    Two sources, joined:
      1. MEASURED_85 below: every internal function PHP 8.5.12 marks deprecated,
         read on 12 November 2026 with
             php -r 'foreach (get_defined_functions()["internal"] as $f)
                     { if ((new ReflectionFunction($f))->isDeprecated()) echo $f, "\n"; }'
         It is written down because the check also runs on PHP 8.4 (the
         workflows pin it), where `finfo_close` is NOT deprecated and the
         second source alone would wave it through.
      2. The same question asked of whatever PHP runs the check. So a newer PHP
         that deprecates something else makes this check go red on its first
         run, which is the point: that is a real fault on the version the
         portal recommends.
    A function the running PHP does not have at all (an extension that is not
    installed) is still refused if it is in MEASURED_85: the name is enough.

WHAT IS DELIBERATELY NOT HERE
    Deprecations that are not about a whole FUNCTION — a deprecated argument,
    a deprecated constant, the backtick operator, implicit nullable types —
    are not seen. `ReflectionFunction::isDeprecated()` answers only "is calling
    this function at all deprecated". Those other kinds need their own checks,
    and `php -l` does not see most of them either (it exits 0 on a
    deprecation).

HOW IT READS THE CODE
    With PHP's own tokeniser, never by searching text, for the reasons
    `check_no_curl_close.py` records at length: the notes that replaced each
    removed call MENTION the function's name (`grep -rn finfo_close web`
    finds those notes and nothing else), and a name inside a string is not a
    call. A bare `finfo_close(` and a fully-qualified `\finfo_close(` are both
    calls; a method or property of the same name (`->`, `::`, `?->`) and a
    declaration (`function finfo_close`) are not; a name with a namespace left
    in it (`\Vendor\finfo_close`) is somebody else's. Names are compared
    without regard to capitals, because PHP's function names are.

WHAT IT CANNOT SEE (the same four shapes as check_no_curl_close.py)
    A name built at run time (`$fn = 'finfo_' . 'close'; $fn($f);`); a name in
    a string handed to `call_user_func()`; an import alias
    (`use function finfo_close as fc;` then `fc($f);`); and
    `namespace\finfo_close($f);` in a file with no namespace.

PENDING (fails both ways)
    One call is LEFT on purpose, and named here so the check is green while it
    waits: `web/_apps/venues/invoice-save.php`, which another package was
    changing at the same time (#1003's builder was told not to touch it). The
    entry FAILS this check the moment the call has gone, so it cannot outstay
    its reason. Nothing else may be added.

    It also refuses to pass when it cannot run: no `php`, no answer from the
    tokeniser, or no PHP file read at all is a FAILURE, never a pass (#646).

    Run `--self-test` to prove both directions still work.
"""

import json
import pathlib
import subprocess
import sys
import tempfile

# Every internal function PHP 8.5.12 marks deprecated (see the header for the
# command). LOWERCASE, because the scan lowercases what it finds.
MEASURED_85 = (
    "strftime", "gmstrftime", "date_sunrise", "date_sunset",
    "libxml_disable_entity_loader",
    "openssl_x509_free", "openssl_pkey_free", "openssl_free_key",
    "curl_close", "curl_share_close",
    "finfo_close",
    "imagedestroy",
    "mhash_get_block_size", "mhash_get_hash_name", "mhash_keygen_s2k", "mhash_count", "mhash",
    "intlcal_set", "intlgregcal_create_instance",
    "strptime", "assert_options", "utf8_encode", "utf8_decode", "socket_set_timeout",
    "mysqli_execute", "mysqli_kill", "mysqli_ping", "mysqli_refresh",
    "odbc_result_all",
    "pg_errormessage", "pg_numrows", "pg_numfields", "pg_cmdtuples", "pg_fieldname",
    "pg_fieldsize", "pg_fieldtype", "pg_fieldnum", "pg_result", "pg_fieldprtlen",
    "pg_fieldisnull", "pg_freeresult", "pg_getlastoid", "pg_locreate", "pg_lounlink",
    "pg_loopen", "pg_loclose", "pg_loread", "pg_lowrite", "pg_loreadall", "pg_loimport",
    "pg_loexport", "pg_setclientencoding", "pg_clientencoding",
    "lcg_value", "shmop_close", "xml_set_object", "xml_parser_free",
    "zip_open", "zip_close", "zip_read", "zip_entry_open", "zip_entry_close",
    "zip_entry_read", "zip_entry_name", "zip_entry_compressedsize", "zip_entry_filesize",
    "zip_entry_compressionmethod",
)

# (path relative to the repository, function) -> why it is left for now.
# Fails BOTH ways: a listed call that has gone is reported, so the line is
# deleted rather than left to excuse a future one.
PENDING = {
    ("web/_apps/venues/invoice-save.php", "finfo_close"):
        "another package was changing this file while #1003 was built; "
        "remove the call (and this line) when that package has landed",
}

REPO = pathlib.Path(__file__).resolve().parents[2]
ROOTS = (REPO / "web", REPO / "tools")

# A small PHP program: the running PHP's own deprecated functions joined with
# the list it is given, then every PHP file tokenised and real calls reported
# as JSON. Comments and strings are never T_STRING, so they never match.
PHP_SCAN = r"""
$names = array_flip(array_filter(array_map('strtolower', explode(',', $argv[1]))));
foreach (get_defined_functions()['internal'] as $fn) {
    if ((new ReflectionFunction($fn))->isDeprecated() === true) { $names[strtolower($fn)] = true; }
}
$hits = []; $scanned = 0;
foreach (array_slice($argv, 2) as $root) {
    if (is_dir($root) === false) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() === false || strtolower($f->getExtension()) !== 'php') { continue; }
        $scanned++;
        $tokens = token_get_all((string) file_get_contents($f->getPathname()));
        $n = count($tokens);
        foreach ($tokens as $i => $t) {
            if (is_array($t) === false) { continue; }
            if (in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) === false) { continue; }
            $bare = ltrim($t[1], '\\');
            if (str_contains($bare, '\\') === true) { continue; }
            if (isset($names[strtolower($bare)]) === false) { continue; }
            // A CALL: the next real token is `(`. Without this a constant or
            // class of the same name would be reported.
            $next = null;
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
                $next = $tokens[$j]; break;
            }
            if ($next !== '(') { continue; }
            // Not a method, property or declaration of the same name.
            $prev = null;
            for ($j = $i - 1; $j >= 0; $j--) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
                $prev = $tokens[$j]; break;
            }
            if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) { continue; }
            $hits[] = ['file' => $f->getPathname(), 'line' => $t[2], 'name' => strtolower($bare)];
        }
    }
}
echo json_encode(['scanned' => $scanned, 'listed' => count($names), 'hits' => $hits]);
"""


class ScanFailed(Exception):
    """The tokeniser could not answer, so nothing was checked."""


def scan(roots):
    """Tokenise every PHP file under `roots`. Returns the decoded answer."""
    try:
        proc = subprocess.run(
            ["php", "-r", PHP_SCAN, "--", ",".join(MEASURED_85)] + [str(r) for r in roots],
            capture_output=True, text=True, timeout=300,
        )
    except FileNotFoundError:
        raise ScanFailed("php is not on PATH")
    except subprocess.TimeoutExpired:
        raise ScanFailed("the tokeniser did not finish within 300 seconds")
    if proc.returncode != 0 or proc.stdout.strip() == "":
        said = proc.stderr.strip().splitlines()[0] if proc.stderr.strip() != "" else "nothing"
        raise ScanFailed("the tokeniser produced no answer (php said: %s)" % said)
    return json.loads(proc.stdout)


def judge(roots, repo, pending, quiet=False):
    """The verdict: 0 clean, 1 a fault, 2 could not check. `repo` and
    `pending` are parameters so the self-test can drive THIS function, not
    only the scan."""
    say = (lambda *a: None) if quiet is True else print
    for r in roots:
        if r.is_dir() is False:
            say("FAIL: %s does not exist, so nothing was checked." % r)
            return 2
    try:
        data = scan(roots)
    except ScanFailed as e:
        say("FAIL: %s. A check that cannot run must never report success (#646)." % e)
        return 2
    if data["scanned"] == 0:
        say("FAIL: no PHP file was read, so this check proved nothing.")
        return 2

    found = set()
    faults = []
    for h in data["hits"]:
        here = pathlib.Path(h["file"]).resolve()
        try:
            shown = str(here.relative_to(repo.resolve()))
        except ValueError:
            shown = str(here)
        found.add((shown, h["name"]))
        if (shown, h["name"]) in pending:
            continue
        faults.append("    %s:%d  %s()" % (shown, h["line"], h["name"]))

    stale = sorted(k for k in pending if k not in found)

    if len(faults) == 0 and len(stale) == 0:
        say("✓ no call to a deprecated PHP function (%d PHP files read, %d functions refused)"
            % (data["scanned"], data["listed"]))
        if len(pending) > 0:
            say("  waiting, on purpose: " + ", ".join("%s %s()" % k for k in sorted(pending)))
        return 0

    if len(faults) > 0:
        say("✗ %d call(s) to a function PHP deprecates (%d PHP files read):" % (len(faults), data["scanned"]))
        for f in faults:
            say(f)
        say("")
        say("  Each writes a 'Deprecated' line to the error log every time it runs.")
        say("  For a *_close() or *_free() of an object, delete the call: the object")
        say("  is freed when it goes out of scope (#673, #1003). Otherwise use what")
        say("  the PHP manual names as the replacement.")
    for k in stale:
        say("✗ PENDING names %s %s(), and that call has gone. Delete its line from" % k)
        say("  PENDING in this file, so it cannot excuse a call added later.")
    return 1


def selftest() -> int:
    """Both directions, through judge() itself. A guard that only ever says
    "clean" is worse than none, because it is believed."""
    cases = [
        # (name, PHP body, should it be refused?)
        ("finfo_close, the #1003 call",   "<?php $f = finfo_open(FILEINFO_MIME_TYPE); finfo_close($f);", True),
        ("curl_close, the #673 call",     "<?php $c = curl_init(); curl_close($c);",                      True),
        ("imagedestroy",                  "<?php imagedestroy($i);",                                       True),
        ("xml_parser_free",               "<?php xml_parser_free($p);",                                    True),
        ("fully qualified",               "<?php namespace Portal\\Core;\n\\finfo_close($f);",            True),
        ("in capitals",                   "<?php FINFO_CLOSE($f);",                                         True),
        ("after an apostrophe comment",   "<?php // don't\nfinfo_close($f);",                              True),
        ("a comment mentioning it",       "<?php // no finfo_close() here\n$f = finfo_open();",            False),
        ("a docblock mentioning it",      "<?php /** finfo_close() is deprecated */ $x = 1;",               False),
        ("the name in a string",          "<?php $s = 'finfo_close';",                                     False),
        ("a method of that name",         "<?php $a->finfo_close(); A::finfo_close(); $a?->finfo_close();", False),
        ("a declared method",             "<?php class A { public function finfo_close() {} }",           False),
        ("somebody else's function",      "<?php \\Vendor\\finfo_close($f);",                              False),
        ("a constant of that name",       "<?php echo FINFO_CLOSE;",                                        False),
        ("finfo_open is fine",            "<?php $f = finfo_open(FILEINFO_MIME_TYPE); finfo_file($f, 'x');", False),
        ("curl_multi_close is fine",      "<?php curl_multi_close($m);",                                   False),
    ]
    failures = 0
    total = 0
    with tempfile.TemporaryDirectory() as tmp:
        box = pathlib.Path(tmp)
        for i, (name, body, refuse) in enumerate(cases):
            for old in box.glob("*.php"):
                old.unlink()
            (box / ("case%d.php" % i)).write_text(body)
            got = judge([box], box, {}, quiet=True)
            want = 1 if refuse is True else 0
            total += 1
            ok = got == want
            failures += 0 if ok else 1
            print("%s %-32s exit expected %d got %d" % ("✓" if ok else "✗", name, want, got))

        # PENDING, both ways.
        for old in box.glob("*.php"):
            old.unlink()
        (box / "wait.php").write_text("<?php finfo_close($f);")
        (box / "other.php").write_text("<?php $x = 1;")
        for name, pend, want, other in [
            ("pending: a listed call passes",   {("wait.php", "finfo_close")}, 0, "<?php $x = 1;"),
            # Only other.php is at fault here: the listed entry is still true.
            ("pending: an unlisted one fails",  {("wait.php", "finfo_close")}, 1, "<?php finfo_close($g);"),
        ]:
            (box / "other.php").write_text(other)
            got = judge([box], box, pend, quiet=True)
            total += 1
            ok = got == want
            failures += 0 if ok else 1
            print("%s %-32s exit expected %d got %d" % ("✓" if ok else "✗", name, want, got))
        (box / "wait.php").write_text("<?php $x = 1;")
        (box / "other.php").write_text("<?php $x = 1;")
        got = judge([box], box, {("wait.php", "finfo_close")}, quiet=True)
        total += 1
        ok = got == 1
        failures += 0 if ok else 1
        print("%s %-32s exit expected 1 got %d (a stale entry fails)"
              % ("✓" if ok else "✗", "pending: the call has gone", got))

        # Nothing read, and a missing root, are failures — never a pass.
        for old in box.glob("*.php"):
            old.unlink()
        for name, roots in [("an empty folder", [box]), ("a missing folder", [box / "nope"])]:
            got = judge(roots, box, {}, quiet=True)
            total += 1
            ok = got == 2
            failures += 0 if ok else 1
            print("%s %-32s exit expected 2 got %d" % ("✓" if ok else "✗", name, got))

    print()
    if failures == 0:
        print("✓ self-test: all %d cases behaved as expected" % total)
        return 0
    print("✗ self-test: %d of %d case(s) did not behave as expected" % (failures, total))
    return 1


if __name__ == "__main__":
    if "--self-test" in sys.argv:
        sys.exit(selftest())
    sys.exit(judge(list(ROOTS), REPO, PENDING))
