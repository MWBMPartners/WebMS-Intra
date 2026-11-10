#!/usr/bin/env python3
"""
A "come back to" address from the request goes through the ONE judge (#620).

WHAT IT CHECKS
--------------
Every `header('Location: ...')` under `web/` whose address takes any part
from the request -- `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, or a
`$_SERVER` value the visitor sends -- must have had that value through
`Auth::safeRedirectUrl()`. Anything else is reported, unless it is named in
NOT_A_COME_BACK_ADDRESS below with the reason a person decided it is safe.

WHY. An address a page accepts from a form or a link and then sends the
browser to is how a link is made that wears the portal's own address and
lands on somebody else's site (an "open redirect"). Three older pages each
had a judge of their own, and every one let something through: the payments
checkout took "/<tab>/outside.example", the asset assignment page took a
".." part that left the organisation, and the newsletter's click counter
forwarded to any web address at all (#620). `Auth::safeRedirectUrl()` is the
ONE judge, used by every sign-in page; this check is what stops a fourth page
growing a fourth judge.

HOW. `redirect-targets-structure.php`, beside this file, reads each file with
PHP's own tokeniser and follows a request value through the assignments of
one function. Its header says exactly when a value counts, when it does not,
and what it cannot see -- read that before trusting a green run. In short: a
value passed into a function's PARAMETER, or read by a function and
RETURNED, is not followed, and neither is a value in an array element or a
property. This is a tripwire for the ordinary shape, not a proof; whether
the three pages REFUSE what they should is driven over HTTP by
`tools/come-back-addresses-selftest.php`.

FAILS BOTH WAYS. A finding that is not on the list fails; a list entry that
no longer matches any finding fails too, because a stale exemption is how a
list stops meaning anything (#668).

Run:        python3 tools/audit-checks/check_redirect_targets_judged.py
Self-test:  python3 tools/audit-checks/check_redirect_targets_judged.py --self-test
Exit 0 when clean; 1 on any finding, and when PHP cannot be asked. There is
DELIBERATELY no fallback to reading the PHP in Python (the
`check_member_language_applied.py` precedent).

@package   Portal\\Tools\\AuditChecks
@author    MWBM Partners Ltd (t/a MWservices)
@copyright 2026-present MWBM Partners Ltd (t/a MWservices)
@license   All Rights Reserved
@version   1.0.0
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

# 📋 Location headers that take something from the request and are NOT a
#    "come back to" address, each with the reason. Keyed by the file and the
#    whole header call with its spacing removed, so ANY change to the call
#    makes the entry stale and the new form a finding: the decision is taken
#    again, by a person, rather than inherited.
#
#    WHAT AN ENTRY CANNOT SEE, measured rather than assumed: it excuses the
#    header CALL, so a change to how the value is built on an EARLIER line
#    passes. Planted on 10 November 2026 -- track-click.php made to fall back
#    to the old `u=` address on the line above its header -- this check stayed
#    green and tools/come-back-addresses-selftest.php went red on ten checks.
#    That self-test drives the click counter only; for the other three, an
#    entry records that a person decided, and nothing here would notice the
#    value behind it changing.
NOT_A_COME_BACK_ADDRESS = {
    ("web/public_html/index.php", "header('Location: '.$redirect,true,302)"):
        "the language switch (?lang=) sends the visitor back to the SAME address they asked for, with the lang "
        "part taken off; the path is the request's own. A browser removes tabs, turns a backslash into a slash "
        "and works out '.' and '..' before it sends anything, so the path it sends cannot begin a second "
        "address -- reasoned from the WHATWG URL standard, NOT measured in a browser. It is not given to "
        "Auth::safeRedirectUrl() because that judge refuses any address holding a letter, letters or digits "
        "and a colon ('T10:30' in a query), which would drop such a page's address. Raised as #910.",
    ("web/_apps/admin/integrations/zoom/connect.php", "header('Location: '.Zoom::authorizeUrl($clientId,$redirect,$state))"):
        "the browser goes to Zoom's own sign-in page; Zoom::authorizeUrl() fixes that address. The request's "
        "Host header goes INTO it as the address Zoom sends the administrator back to, and Zoom accepts only an "
        "address registered with it. Building that from the Host header rather than PortalAddress is a separate "
        "fault of the #500 kind, raised as #909.",
    ("web/_apps/payments/checkout.php", "header('Location: '.$redirect,true,303)"):
        "the browser goes to the payment provider's hosted checkout page, whose address Payments::startCheckout() "
        "gets from the provider. The request chooses the purpose and amount that go INTO that call, each "
        "checked on this page against the person's own records first.",
    ("web/_apps/newsletter/track-click.php", "header('Location: '.$target,true,302)"):
        "the address is read out of the newsletter's own stored list of links by Newsletter::linkTarget(); the "
        "request supplies only a random 32-character token to look it up, and a token that is not in that "
        "newsletter's list gets the 'no longer valid' page, never a redirect (#620).",
}


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


def analyse(root: str, exemptions: dict) -> list[str]:
    """Every problem for the tree at `root`. An empty list means clean."""
    files = php_files(root)
    if files == []:
        return [CANNOT_RUN + "no PHP files were found under " + os.path.join(root, "web") + ". Nothing was checked."]
    answers = ask_php(root, files)
    if isinstance(answers, str):
        return [CANNOT_RUN + answers + ". Nothing was checked; this is a failure, not a pass."]

    problems: list[str] = []
    used: set = set()
    for relative, answer in zip(files, answers):
        if "error" in answer:
            problems.append(f"{relative}: {answer['error']}. A file PHP cannot read cannot be judged.")
            continue
        for finding in answer.get("findings", []):
            key = (relative, finding["statement"])
            if key in exemptions:
                used.add(key)
                continue
            problems.append(
                f"{relative}:{finding['line']}: `{finding['statement']}` sends the browser to an address taken from "
                f"the request ({'; '.join(finding['why'])}) without Auth::safeRedirectUrl(). Pass the request's value "
                "through Auth::safeRedirectUrl() and delete any judge of the page's own. If this is not a 'come back "
                "to' address at all, add it to NOT_A_COME_BACK_ADDRESS with the reason -- that is a decision, not a "
                "tidy-up.")
    for key in sorted(set(exemptions) - used):
        problems.append(
            f"{key[0]}: NOT_A_COME_BACK_ADDRESS names `{key[1]}`, which no longer matches any finding there. Remove "
            "the entry (or, if the call was only reworded, decide again and write the new form): a stale exemption "
            "would excuse whatever is written there next.")
    return problems


# =============================================================================
# 🧪 The self-test: shapes that MUST be reported, and shapes that must NOT
# =============================================================================
MUST_REPORT = {
    "direct": "<?php header('Location: ' . $_GET['to']);",
    "a page's own judge": "<?php function clean($v) { return $v; }\nheader('Location: ' . clean($_POST['return_to'] ?? '/'));",
    "a fake judge of the same name": "<?php function safeRedirectUrl($v) { return $v; }\nheader('Location: ' . safeRedirectUrl($_GET['u']));",
    "the judge of another class": "<?php header('Location: ' . Other::safeRedirectUrl($_GET['u']));",
    "two hops": "<?php $a = (string) ($_POST['returnTo'] ?? 'item');\n$b = $a;\nheader('Location: ' . $b);",
    # Written BEFORE the line that feeds it, as in a loop: only following the
    # assignments until nothing changes finds it (one pass in order would not).
    "a hop written above the line that feeds it": "<?php foreach ([1, 2] as $i) {\n    if ($i === 2) { header('Location: ' . $b); }\n    $b = $a;\n    $a = $_GET['u'];\n}",
    "joined on to an empty Site::url": "<?php header('Location: ' . Site::url('') . $_GET['p']);",
    "trim keeps it": "<?php $u = trim($_GET['u']);\nheader('Location: ' . $u, true, 302);",
    "Elvis returns it": "<?php header('Location: ' . ($_GET['u'] ?: '/'));",
    "after a question mark, but as the OTHER branch": "<?php $x = $c ? '/a?b=1' : $_GET['u'];\nheader('Location: ' . $x);",
    "Site::url(x) then an alternative": "<?php header('Location: ' . ($ok ? Site::url('a') : $_GET['u']));",
    "interpolated": "<?php header(\"Location: {$_GET['u']}\");",
    "a fully qualified header()": "<?php \\header('Location: ' . $_REQUEST['u']);",
    "a referrer": "<?php header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/'));",
    "the address of the request": "<?php $p = $_SERVER['REQUEST_URI'];\nheader('Location: ' . $p);",
    "a string cast does not clean it": "<?php header('Location: ' . (string) $_GET['u']);",
    "appended with .=": "<?php $u = '';\n$u .= $_COOKIE['back'];\nheader('Location: ' . $u);",
    "lower-case location": "<?php header('location: ' . $_GET['u']);",
}
MUST_STAY_GREEN = {
    "judged": "<?php use Portal\\Core\\Auth;\nheader('Location: ' . Auth::safeRedirectUrl($_GET['redirect'] ?? null));",
    "judged with a fallback": "<?php $r = Auth::safeRedirectUrl($_POST['returnTo'] ?? null, Site::url('assets'));\nheader('Location: ' . $r);",
    "judged, fully qualified": "<?php header('Location: ' . \\Portal\\Core\\Auth::safeRedirectUrl($_GET['r']));",
    "judged inside Auth": "<?php class Auth { function f() { $t = self::safeRedirectUrl($_GET['redirect'] ?? '/'); header('Location: ' . $t); } }",
    "in the query, encoded": "<?php header('Location: ' . Site::url('assets') . '?status=' . urlencode($_GET['status']));",
    "in the query, raw": "<?php $s = (string) ($_POST['returnStatus'] ?? 'new');\nheader('Location: ' . Site::url('admin/decision-cards') . '?status=' . $s);",
    "a number": "<?php header('Location: ' . Site::url('') . (int) $_POST['id']);",
    "a number in brackets": "<?php $id = (int) ($_GET['id'] ?? 0);\nheader('Location: /x?id=' . $id);",
    "only a test": "<?php $x = ($_GET['a'] ?? '') === 'b' ? Site::url('a') : Site::url('b');\nheader('Location: ' . $x);",
    "only a condition": "<?php $c = is_scalar($_POST['n']) === true ? (int) $_POST['n'] : 0;\nheader('Location: ' . Site::url('') . $c);",
    "a test of emptiness": "<?php header('Location: ' . (empty($_GET['x']) ? Site::url('a') : Site::url('b')));",
    "after a page that is already named": "<?php header('Location: ' . Site::url('a/') . $_GET['token']);",
    "after a fixed path": "<?php header('Location: /assets/item?id=' . $_GET['id']);",
    "fixed text after Location": "<?php header('Location: /a/' . $_GET['t']);",
    "the request is not involved": "<?php $r = Site::url('assets');\nheader('Location: ' . $r);",
    "another function's own variable of the same name": "<?php function a() { $r = $_GET['u']; return 1; }\nfunction b() { $r = Site::url('x'); header('Location: ' . $r); }",
    "a closure's request value stays in the closure": "<?php $f = static function () { return Auth::safeRedirectUrl($_POST['r'] ?? null); };\n$r = $f();\nheader('Location: ' . $r);",
    "a header that is not Location": "<?php header('X-Back: ' . $_GET['u']);",
    "a method called header": "<?php $res->header('Location: ' . $_GET['u']);",
    "quoted in a comment": "<?php // header('Location: ' . $_GET['u']);\n/* header('Location: ' . $_POST['u']); */",
    "quoted in a string": "<?php $help = \"header('Location: ' . \\$_GET['u'])\";",
}


def self_test() -> int:
    """Plant every shape into a scratch tree, one file each, and read the verdicts."""
    failures = 0
    passed = 0
    with tempfile.TemporaryDirectory(prefix="redirect-check-selftest-") as scratch:
        web = os.path.join(scratch, "web")
        os.makedirs(web)
        names = {}
        for index, (label, code) in enumerate(list(MUST_REPORT.items()) + list(MUST_STAY_GREEN.items())):
            name = f"case{index:02d}.php"
            names[label] = "web/" + name
            with open(os.path.join(web, name), "w", encoding="utf-8") as handle:
                handle.write(code + "\n")
        problems = analyse(scratch, {})
        if any(p.startswith(CANNOT_RUN) for p in problems):
            print("FAIL — the self-test could not run the probe: " + problems[0])
            return 1
        for label in MUST_REPORT:
            hit = any(p.startswith(names[label] + ":") for p in problems)
            print(("PASS" if hit else "FAIL") + f" — reported, as it must be: {label}")
            passed += 1 if hit else 0
            failures += 0 if hit else 1
        for label in MUST_STAY_GREEN:
            hit = [p for p in problems if p.startswith(names[label] + ":")]
            print(("PASS" if hit == [] else "FAIL") + f" — NOT reported, as it must not be: {label}"
                  + ("" if hit == [] else "\n        " + hit[0]))
            passed += 1 if hit == [] else 0
            failures += 0 if hit == [] else 1

        # Both ways: an exemption that matches is used; one that matches nothing fails.
        direct = (names["direct"], "header('Location: '.$_GET['to'])")
        with_exemption = analyse(scratch, {direct: "a test reason"})
        ok = not any(p.startswith(names["direct"] + ":") for p in with_exemption)
        print(("PASS" if ok else "FAIL") + " — an exemption naming the exact call excuses it")
        passed += 1 if ok else 0
        failures += 0 if ok else 1
        stale = analyse(scratch, {("web/nothing.php", "header('Location: '.$x)"): "stale"})
        ok = any("no longer matches any finding" in p for p in stale)
        print(("PASS" if ok else "FAIL") + " — an exemption that matches nothing is itself a failure")
        passed += 1 if ok else 0
        failures += 0 if ok else 1

        # A file PHP cannot parse is a failure, never "nothing found" (#777).
        with open(os.path.join(web, "broken.php"), "w", encoding="utf-8") as handle:
            handle.write("<?php header('Location: ' . $_GET['u']\n")
        broken = analyse(scratch, {})
        ok = any(p.startswith("web/broken.php:") and "cannot parse" in p for p in broken)
        print(("PASS" if ok else "FAIL") + " — a file PHP cannot parse is reported, not passed")
        passed += 1 if ok else 0
        failures += 0 if ok else 1

    expected = len(MUST_REPORT) + len(MUST_STAY_GREEN) + 3
    if passed + failures != expected:
        print(f"FAIL — {passed + failures} checks ran where {expected} were expected")
        failures += 1
    print(f"\n{passed} passed, {failures} failed ({len(MUST_REPORT)} must-report, {len(MUST_STAY_GREEN)} must-stay-green, 3 about the list and parsing)")
    return 0 if failures == 0 else 1


def main() -> int:
    if "--self-test" in sys.argv[1:]:
        return self_test()
    root = os.path.abspath(os.path.join(HERE, "..", ".."))
    problems = analyse(root, NOT_A_COME_BACK_ADDRESS)
    if problems:
        print("check_redirect_targets_judged: a 'come back to' address is not judged by Auth::safeRedirectUrl() (#620)\n")
        for problem in problems:
            print("  • " + problem)
        return 1
    print(f"check_redirect_targets_judged: OK — every Location header fed from the request is judged by "
          f"Auth::safeRedirectUrl(), or is one of the {len(NOT_A_COME_BACK_ADDRESS)} named in NOT_A_COME_BACK_ADDRESS. "
          "It follows a value only through assignments inside one function; see redirect-targets-structure.php "
          "for what it cannot see.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
