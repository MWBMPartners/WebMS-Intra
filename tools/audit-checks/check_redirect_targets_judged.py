#!/usr/bin/env python3
"""
No request value written straight into a Location header unless the ONE judge
has it (#620).

WHAT IT CHECKS, exactly
-----------------------
In every `header(...)` call under `web/` whose first argument holds a fixed
text beginning "Location:", a request value -- `$_GET`, `$_POST`,
`$_REQUEST`, `$_COOKIE`, `$_FILES`, or a `$_SERVER` value the visitor chooses
-- may appear only inside the brackets of `Auth::safeRedirectUrl( ... )`
(the class resolved to `Portal\\Core\\Auth` through the file's `namespace` and
`use` lines), or inside a wrapper that cannot produce another site's address
(`(int)`, `(float)`, `(bool)`, `intval()`, `urlencode()`, `rawurlencode()`,
`http_build_query()`, `isset()`, `empty()`). Anything else is reported,
unless the call is named in NOT_A_COME_BACK_ADDRESS below with a reason.

WHAT IT DOES NOT CHECK -- and this is most of the problem, so read it before
trusting a green run. It does NOT follow a value. A request value put into a
VARIABLE first and the variable written into the header passes, and so do a
helper's answer, a function's parameter, an array element, the whole header
held in a variable, a header name split in two, and a Refresh header. All
three pages #620 fixed had one of those shapes, so this check would NOT have
caught any of them as they were before #620 except the checkout's (whose
`sanitizeReturnTo($_POST[...])` sat in the header itself). Each of those is a
self-test case that must stay green, so the day the check starts seeing one
it says so. The full list is in `redirect-targets-structure.php`.

WHY SO NARROW (D-065, and #902's check before it). Version 1 of this check
followed values through assignments and expressions. It cried wolf on correct
code (an allow-list made with `in_array()`, `match` or `str_starts_with()`;
a `count()` joined after `Site::url()`), its own self-test passed with one of
its rules loosened, and it had silent holes it did not declare. A gate that
cries wolf gets switched off (#483). This version reports only what it can
see without judging anything about where a value goes: a request value
WRITTEN INTO the header. Whether the pages REFUSE what they should is
behaviour, and is DRIVEN over HTTP by `tools/come-back-addresses-selftest.php`.

ONE COST, said plainly: a request value written into the header only as a
TEST (`($_GET['x'] === 'a' ? ... : ...)`) is reported, because seeing that it
is only a test means reading the expression. No header in `web/` has that
shape today (measured 10 November 2026: 1,272 Location headers, none holding
a request value). Write the test above the header, or name the call below.

FAILS BOTH WAYS. A finding not on the list fails; a list entry that no longer
matches a finding fails too (#668: a stale exemption excuses whatever is
written there next).

Run:        python3 tools/audit-checks/check_redirect_targets_judged.py
Self-test:  python3 tools/audit-checks/check_redirect_targets_judged.py --self-test
Exit 0 when clean; 1 on any finding, and when PHP cannot be asked. There is
DELIBERATELY no fallback to reading the PHP in Python (the
`check_member_language_applied.py` precedent).

@package   Portal\\Tools\\AuditChecks
@author    MWBM Partners Ltd (t/a MWservices)
@copyright 2026-present MWBM Partners Ltd (t/a MWservices)
@license   All Rights Reserved
@version   2.0.0
@link      https://github.com/MWBMPartners/WebMS-Intra/issues/620
"""

from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
PROBE = os.path.join(HERE, "redirect-targets-structure.php")
PROBE_TIMEOUT_SECONDS = 120
CANNOT_RUN = "CANNOT RUN: "

# 📋 Location headers with a request value written into them that are NOT a
#    "come back to" address, each with the reason a person decided. Keyed by
#    the file and the whole header call with spacing and comments removed, so
#    any change to the call makes the entry stale and the new form a finding.
#    EMPTY on purpose: on 10 November 2026 no header in web/ needed one.
NOT_A_COME_BACK_ADDRESS: dict = {}


def php_files(root: str) -> list[str]:
    """Every .php file under web/ (vendor code and libraries left out), relative to the root, sorted."""
    out: list[str] = []
    for folder, dirs, files in os.walk(os.path.join(root, "web")):
        dirs[:] = sorted(d for d in dirs if d not in ("_vendor", "_libraries"))
        for name in sorted(files):
            if name.endswith(".php"):
                out.append(os.path.relpath(os.path.join(folder, name), root).replace(os.sep, "/"))
    return out


def ask_php(root: str, files: list[str]):
    """The probe's answer, or a string saying why it could not be had."""
    php = shutil.which("php")
    if php is None:
        return "there is no `php` on this machine, and this check reads PHP only through PHP's own tokeniser"
    if os.path.isfile(PROBE) is False:
        return "the probe " + PROBE + " is missing"
    try:
        done = subprocess.run([php, PROBE], input=json.dumps(files), capture_output=True, text=True,
                              timeout=PROBE_TIMEOUT_SECONDS, cwd=root)
    except (subprocess.TimeoutExpired, OSError) as exc:
        return "the probe did not finish: " + str(exc)
    try:
        answers = json.loads(done.stdout.strip() or "null")
    except ValueError:
        return "the probe's answer was not JSON (exit " + str(done.returncode) + "): " + done.stderr.strip()[:300]
    if isinstance(answers, list) is False or len(answers) != len(files):
        return "the probe answered for a different number of files than it was asked about"
    return answers


def analyse(root: str, exemptions: dict) -> tuple[list[str], int]:
    """(every problem for the tree at `root`, how many Location headers were read). No problems means clean."""
    files = php_files(root)
    if files == []:
        return [CANNOT_RUN + "no PHP files were found under " + os.path.join(root, "web") + ". Nothing was checked."], 0
    answers = ask_php(root, files)
    if isinstance(answers, str):
        return [CANNOT_RUN + answers + ". Nothing was checked; this is a failure, not a pass."], 0

    problems: list[str] = []
    used: set = set()
    locations = 0
    for relative, answer in zip(files, answers):
        if "error" in answer:
            problems.append(f"{relative}: {answer['error']}. A file PHP cannot read cannot be judged.")
            continue
        locations += int(answer.get("locations", 0))
        for finding in answer.get("findings", []):
            key = (relative, finding["statement"])
            if key in exemptions:
                used.add(key)
                continue
            problems.append(
                f"{relative}:{finding['line']}: `{finding['statement']}` writes the request value "
                f"{', '.join(finding['values'])} into a Location header outside Auth::safeRedirectUrl(). Give the "
                "request's value to Auth::safeRedirectUrl() and delete any judge of the page's own. If it is only a "
                "test, or this is not a 'come back to' address at all, work it out above the header, or add the call "
                "to NOT_A_COME_BACK_ADDRESS with the reason -- that is a decision, not a tidy-up.")
    for key in sorted(set(exemptions) - used):
        problems.append(
            f"{key[0]}: NOT_A_COME_BACK_ADDRESS names `{key[1]}`, which no longer matches any finding there. Remove "
            "the entry (or, if the call was only reworded, decide again and write the new form).")
    return problems, locations


# =============================================================================
# 🧪 The self-test
# =============================================================================
MUST_REPORT = {
    "direct": "<?php header('Location: ' . $_GET['to']);",
    "a slash in front": "<?php header('Location: /' . $_GET['u']);",
    "a slash and a backslash in front": "<?php header('Location: /\\\\' . $_GET['u']);",
    "a fixed page in front": "<?php header('Location: /north/' . $_GET['u']);",
    "a fixed slash joined on": "<?php header('Location: ' . '/' . $_POST['p']);",
    "a page's own judge": "<?php function clean($v) { return $v; }\nheader('Location: ' . clean($_POST['return_to'] ?? '/'));",
    "a global function named like the judge": "<?php function safeRedirectUrl($v) { return $v; }\nheader('Location: ' . safeRedirectUrl($_GET['u']));",
    "another class's method named like the judge": "<?php header('Location: ' . Other::safeRedirectUrl($_GET['u']));",
    "self:: in a page's own class": "<?php class Foo { function f() { header('Location: ' . self::safeRedirectUrl($_GET['u'])); } static function safeRedirectUrl($v) { return $v; } }",
    "an alias named Auth for another class": "<?php use Some\\Other\\Auth;\nheader('Location: ' . Auth::safeRedirectUrl($_GET['u']));",
    "Auth in another namespace, unimported": "<?php namespace Portal\\Apps;\nheader('Location: ' . Auth::safeRedirectUrl($_GET['u']));",
    "the judge present, the value outside it": "<?php use Portal\\Core\\Auth;\nheader('Location: ' . Auth::safeRedirectUrl('/') . $_GET['u']);",
    "a string function keeps it": "<?php header('Location: ' . strtolower($_GET['u']));",
    "urldecode keeps it": "<?php header('Location: ' . urldecode($_GET['u']));",
    "a string cast keeps it": "<?php header('Location: ' . (string) $_GET['u']);",
    "a query after it": "<?php header('Location: ' . $_GET['u'] . '?x=1');",
    "interpolated with braces": "<?php header(\"Location: {$_GET['u']}\");",
    "interpolated plain": "<?php header(\"Location: $_GET[u]\");",
    "in a heredoc": "<?php header(<<<H\nLocation: {$_GET['u']}\nH);",
    "sprintf": "<?php header(sprintf('Location: %s', $_GET['u']));",
    "lower case, no space": "<?php header('location:' . $_GET['u']);",
    "a space before the colon": "<?php header('Location : ' . $_GET['u']);",
    "a fully qualified header()": "<?php \\header('Location: ' . $_REQUEST['u']);",
    "a cookie": "<?php header('Location: ' . $_COOKIE['back']);",
    "an uploaded file's name": "<?php header('Location: /f/' . $_FILES['f']['name']);",
    "the referrer": "<?php header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/'));",
    "the request's own address": "<?php header('Location: ' . $_SERVER['REQUEST_URI']);",
    "the Host header": "<?php header('Location: https://' . $_SERVER['HTTP_HOST'] . '/x');",
    "a $_SERVER key that is not fixed text": "<?php header('Location: ' . $_SERVER[$k]);",
    "an only-a-test shape written into the header (the stated cost)": "<?php header('Location: ' . (($_GET['t'] ?? '') === 'a' ? '/a' : '/b'));",
}
MUST_STAY_GREEN = {
    "judged": "<?php use Portal\\Core\\Auth;\nheader('Location: ' . Auth::safeRedirectUrl($_GET['redirect'] ?? null));",
    "judged, method name in other capitals": "<?php use Portal\\Core\\Auth;\nheader('Location: ' . Auth::SafeRedirectURL($_GET['r']));",
    "judged with a fallback built from the request's number": "<?php use Portal\\Core\\Auth;\nheader('Location: ' . Auth::safeRedirectUrl($_POST['r'] ?? null, Site::url('a') . '?id=' . (int) $_POST['id']));",
    "judged, fully qualified": "<?php header('Location: ' . \\Portal\\Core\\Auth::safeRedirectUrl($_GET['r']));",
    "judged through an alias": "<?php use Portal\\Core\\Auth as Gate;\nheader('Location: ' . Gate::safeRedirectUrl($_GET['r']));",
    "judged through a group use": "<?php use Portal\\Core\\{Site, Auth};\nheader('Location: ' . Auth::safeRedirectUrl($_GET['r']));",
    "judged inside namespace Portal\\Core": "<?php namespace Portal\\Core;\nheader('Location: ' . Auth::safeRedirectUrl($_GET['r']));",
    "self:: inside Portal\\Core\\Auth": "<?php namespace Portal\\Core;\nclass Auth { function f() { header('Location: ' . self::safeRedirectUrl($_GET['redirect'] ?? '/')); } }",
    "static:: inside Portal\\Core\\Auth": "<?php namespace Portal\\Core;\nclass Auth { function f() { header('Location: ' . static::safeRedirectUrl($_GET['redirect'] ?? '/')); } }",
    "a number": "<?php header('Location: /x?id=' . (int) $_GET['id']);",
    "a number in brackets": "<?php header('Location: ' . Site::url('a') . '?id=' . (int) ($_GET['id'] ?? 0));",
    "intval": "<?php header('Location: /x?p=' . intval($_GET['p']));",
    "encoded": "<?php header('Location: ' . Site::url('assets') . '?status=' . urlencode($_GET['status']));",
    "a query built": "<?php header('Location: /x?' . http_build_query(['q' => $_GET['q']]));",
    "a test with isset": "<?php header('Location: ' . (isset($_GET['x']) ? Site::url('a') : Site::url('b')));",
    "no request value at all": "<?php header('Location: ' . Site::url('assets') . '?id=' . $id);",
    "a header that is not Location": "<?php header('X-Back: ' . $_GET['u']);",
    "a method called header": "<?php $res->header('Location: ' . $_GET['u']);",
    "quoted in a comment": "<?php // header('Location: ' . $_GET['u']);\n/* header('Location: ' . $_POST['u']); */",
    "quoted in a string": "<?php $help = \"header('Location: ' . \\$_GET['u'])\";",
    "a server-set $_SERVER key": "<?php header('Location: ' . $_SERVER['SCRIPT_NAME']);",
    # The version-1 checker's cry-wolf shapes (#620 round 1): correct code, must stay green.
    "cry-wolf h1: in_array allow-list": "<?php $tab = (string) ($_GET['tab'] ?? '');\n$r = in_array($tab, ['a', 'b'], true) === true ? Site::url('x') : Site::url('y');\nheader('Location: ' . $r);",
    "cry-wolf h2: str_starts_with": "<?php $r = str_starts_with((string) ($_GET['u'] ?? ''), '/a') === true ? Site::url('a') : Site::url('b');\nheader('Location: ' . $r);",
    "cry-wolf h3: match": "<?php $back = match ((string) ($_POST['from'] ?? '')) { 'list' => Site::url('assets'), default => Site::url('') };\nheader('Location: ' . $back);",
    "cry-wolf h4: isset in an allow-list": "<?php $allowed = ['/a' => true];\n$r = isset($allowed[$_GET['u']]) === true ? Site::url('a') : Site::url('b');\nheader('Location: ' . $r);",
    "cry-wolf h5: a count joined after Site::url": "<?php $n = count($_POST['ids'] ?? []);\nheader('Location: ' . Site::url('') . 'x' . $n);",
    "cry-wolf g5: judged, then a fragment": "<?php $back = Auth::safeRedirectUrl($_POST['r'] ?? null) . '#' . $_POST['anchor'];\nheader('Location: ' . $back);",
}
# MEASURED blind spots: real faults this check does NOT see. Each must stay
# green; the day one goes red, the check has started seeing it -- say so in
# the header and move it to MUST_REPORT.
BLIND_SPOTS = {
    "a request value in a variable first": "<?php $x = $_GET['u'];\nheader('Location: ' . $x);",
    "a slash and the request, in a variable": "<?php $r = '/' . $_GET['u'];\nheader('Location: ' . $r);",
    "a helper's answer": "<?php function back() { return $_POST['r']; }\nheader('Location: ' . back());",
    "a function's parameter": "<?php function go(string $to): never { header('Location: ' . $to); exit; }\ngo($_GET['u']);",
    "an array element": "<?php $d = ['to' => $_GET['u']];\nheader('Location: ' . $d['to']);",
    "the whole header in a variable": "<?php $h = 'Location: ' . $_GET['u'];\nheader($h);",
    "the header name split in two": "<?php header('Loc' . 'ation: ' . $_GET['u']);",
    "a heredoc holding a variable": "<?php $u = $_GET['u'];\nheader(<<<H\nLocation: $u\nH);",
    "a Refresh header": "<?php header('Refresh: 0; url=' . $_GET['u']);",
}
EXPECTED = {"report": 30, "green": 27, "blind": 9, "other": 3}


def self_test() -> int:
    """Plant every shape into a scratch tree, one file each, and read the verdicts."""
    failures = 0
    passed = 0
    counts = {"report": len(MUST_REPORT), "green": len(MUST_STAY_GREEN), "blind": len(BLIND_SPOTS), "other": 3}
    if counts != EXPECTED:
        print(f"FAIL — the case lists hold {counts}, where {EXPECTED} are pinned: a case was added or lost without the count")
        failures += 1
    with tempfile.TemporaryDirectory(prefix="redirect-check-selftest-") as scratch:
        web = os.path.join(scratch, "web")
        os.makedirs(web)
        names = {}
        every = [("report", k, v) for k, v in MUST_REPORT.items()] + [("green", k, v) for k, v in MUST_STAY_GREEN.items()] \
            + [("blind", k, v) for k, v in BLIND_SPOTS.items()]
        for index, (_kind, label, code) in enumerate(every):
            name = f"case{index:02d}.php"
            names[label] = "web/" + name
            with open(os.path.join(web, name), "w", encoding="utf-8") as handle:
                handle.write(code + "\n")
        problems, _locations = analyse(scratch, {})
        if any(p.startswith(CANNOT_RUN) or "cannot parse" in p for p in problems):
            print("FAIL — the self-test could not read its own cases: " + "; ".join(p for p in problems if "cannot" in p.lower())[:400])
            return 1
        for kind, label, _code in every:
            hit = [p for p in problems if p.startswith(names[label] + ":")]
            want = kind == "report"
            ok = (hit != []) == want
            words = {"report": "reported, as it must be", "green": "NOT reported (correct code)",
                     "blind": "NOT reported (a MEASURED blind spot)"}[kind]
            print(("PASS" if ok else "FAIL") + f" — {words}: {label}" + ("" if ok or hit == [] else "\n        " + hit[0]))
            passed += 1 if ok else 0
            failures += 0 if ok else 1

        direct = (names["direct"], "header('Location: '.$_GET['to'])")
        ok = not any(p.startswith(names["direct"] + ":") for p in analyse(scratch, {direct: "a test reason"})[0])
        print(("PASS" if ok else "FAIL") + " — an exemption naming the exact call excuses it")
        passed += 1 if ok else 0
        failures += 0 if ok else 1
        stale = analyse(scratch, {("web/nothing.php", "header('Location: '.$x)"): "stale"})[0]
        ok = any("no longer matches any finding" in p for p in stale)
        print(("PASS" if ok else "FAIL") + " — an exemption that matches nothing is itself a failure")
        passed += 1 if ok else 0
        failures += 0 if ok else 1
        with open(os.path.join(web, "broken.php"), "w", encoding="utf-8") as handle:
            handle.write("<?php header('Location: ' . $_GET['u']\n")
        ok = any(p.startswith("web/broken.php:") and "cannot parse" in p for p in analyse(scratch, {})[0])
        print(("PASS" if ok else "FAIL") + " — a file PHP cannot parse is reported, not passed")
        passed += 1 if ok else 0
        failures += 0 if ok else 1

    total = sum(EXPECTED.values())
    if passed + failures != total + (1 if counts != EXPECTED else 0):
        print(f"FAIL — {passed + failures} checks ran where {total} were expected")
        failures += 1
    print(f"\n{passed} passed, {failures} failed ({EXPECTED['report']} must-report, {EXPECTED['green']} must-stay-green, "
          f"{EXPECTED['blind']} measured blind spots, {EXPECTED['other']} about the list and parsing)")
    return 0 if failures == 0 else 1


def main() -> int:
    if "--self-test" in sys.argv[1:]:
        return self_test()
    root = os.path.abspath(os.path.join(HERE, "..", ".."))
    problems, locations = analyse(root, NOT_A_COME_BACK_ADDRESS)
    if problems:
        print("check_redirect_targets_judged: a request value is written into a Location header outside "
              "Auth::safeRedirectUrl() (#620)\n")
        for problem in problems:
            print("  • " + problem)
        return 1
    print(f"check_redirect_targets_judged: OK — {locations} Location headers read; none has a request value written "
          f"into it outside Auth::safeRedirectUrl(), and {len(NOT_A_COME_BACK_ADDRESS)} are named exemptions. It does "
          "NOT follow values through variables or helpers: see redirect-targets-structure.php for what it cannot see.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
