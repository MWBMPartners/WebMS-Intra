#!/usr/bin/env python3
"""
A page's background request that SPENDS the form token must hand back the new one.

Path: tools/audit-checks/check_json_spenders_return_token.py

WHY THIS EXISTS (#1134)
-----------------------
`Auth::verifyCsrf()` replaces the session's one form token every time it
accepts one (#768). A handler that accepts a token and answers JSON to a script
on a page, without handing the replacement back, leaves that page holding a
spent token: its next ordinary form post is refused. Four handlers did exactly
that -- `geo/lookup`, `geo/w3w-suggest`, `privacy/consent` (the cookie banner,
shown to every new visitor on a page with the standard footer; not the sign-in page) and `worship/plan-reorder`
-- and the "Look up coordinates" button on eight pages lost the member's Save.

WHAT IT CHECKS (presence only, D-065)
-------------------------------------
Every PHP file under `web/_apps` that is NOT under an `api/` folder (those
answer through `ApiResponse`, whose `meta.csrfToken` is the house way) and that

  * calls `Auth::verifyCsrf(` (the method that SPENDS the token), AND
  * writes a JSON answer: `echo json_encode(`, `print json_encode(` or
    `exit(json_encode(`,

must contain the string `'csrfToken'`. The other spellings (`'csrf_token'`,
`'csrf'`) are NOT accepted, on purpose: every handler reads `$_POST['csrf_token']`
and answers `'reason' => 'csrf'`, so accepting either would pass the very
handlers this check exists for (measured against the four old ones). The three facts come from PHP's own
tokeniser (`token_get_all()`), never from regular expressions over the text,
because every handler here quotes the words in its own docblock: a comment
naming `csrfToken` must not satisfy the check, and `json_encode` used only to
write a LOG line (`offboarding/do.php`, `forms/field-save.php`, the newsletter
saves) is not an answer.

The list `ON_PURPOSE` names files allowed to spend a token and answer JSON
without a `'csrfToken'` (one, today: it returns the token as `csrf`). It fails both ways: a listed file that
no longer needs the exemption fails too.

WHAT IT CANNOT SEE, said plainly
--------------------------------
  * It does NOT prove EVERY exit of a handler carries the token: one
    `'csrfToken'` anywhere satisfies it. That is what the handlers' single
    answering closure is for, and what `tools/geo-token-selftest.php` drives
    over HTTP, one answer at a time.
  * An answer built in a variable and echoed later, `json_encode` called
    through an alias, and a handler that spends the token in an included file.
  * Whether the page SCRIPT writes the replacement into the page: that is
    `tools/geo-lookup-csrf-selftest.mjs`, in a real browser.

    python3 tools/audit-checks/check_json_spenders_return_token.py
    python3 tools/audit-checks/check_json_spenders_return_token.py --self-test
"""

import json
import os
import subprocess
import sys
import tempfile

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
APPS = os.path.join(ROOT, "web", "_apps")

# Files that may spend a token and answer JSON without handing one back, each
# with the reason. One entry today (the report builder's preview, which hands
# the token back as `csrf`). Fails both ways (see main()).
ON_PURPOSE: dict = {
    # Returns the replacement under the name `csrf`, which its own script
    # (report-builder.js) reads; it is not one of the four faults and works.
    "admin/reports/builder/preview.php": "answers with the replacement token as 'csrf', read by report-builder.js",
}

# PHP asked, not read: one small program, given file names on stdin, printing
# for each {"verify": bool, "json": bool, "token": bool} from the tokeniser.
# Comments, docblocks and inline HTML are never code tokens.
PHP_FACTS = r"""<?php
$out = [];
foreach (preg_split('/\R/', trim((string) stream_get_contents(STDIN))) as $file) {
    if ($file === '') { continue; }
    $src = (string) file_get_contents($file);
    $t = [];
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok) === true) {
            if (in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true) === true) { continue; }
            $t[] = [$tok[0], $tok[1]];
        } else {
            $t[] = [null, $tok];
        }
    }
    $verify = false; $json = false; $token = false;
    $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        [$id, $text] = $t[$i];
        if ($id === T_CONSTANT_ENCAPSED_STRING && trim($text, "'\"") === 'csrfToken') { $token = true; }
        // Auth::verifyCsrf( : the method name right after '::' and before '('.
        if ($id === T_STRING && $text === 'verifyCsrf' && $i > 0 && $t[$i - 1][0] === T_DOUBLE_COLON
            && ($t[$i + 1][1] ?? '') === '(') { $verify = true; }
        // json_encode( written as an ANSWER: right after echo, print or exit(.
        if (in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED], true) === true && ltrim($text, '\\') === 'json_encode'
            && ($t[$i + 1][1] ?? '') === '(' && $i > 0) {
            $p = $t[$i - 1];
            $pp = $t[$i - 2] ?? [null, ''];
            if (in_array($p[0], [T_ECHO, T_PRINT], true) === true
                || ($p[1] === '(' && $pp[0] === T_EXIT)) { $json = true; }
        }
    }
    $out[$file] = ['verify' => $verify, 'json' => $json, 'token' => $token];
}
echo json_encode($out);
"""


def facts(files):
    if not files:
        return {}
    proc = subprocess.run(
        ["php", "-r", PHP_FACTS.replace("<?php", "", 1)],
        input="\n".join(files),
        capture_output=True,
        text=True,
    )
    if proc.returncode != 0:
        # Never fall back to reading the text: a text search finds the prose.
        raise SystemExit("could not run PHP's tokeniser (is `php` on the PATH?): " + proc.stderr.strip())
    return json.loads(proc.stdout)


def php_files(root):
    found = []
    for base, _dirs, names in os.walk(root):
        rel = os.path.relpath(base, root).split(os.sep)
        if "api" in rel:
            continue
        for name in names:
            if name.endswith(".php"):
                found.append(os.path.join(base, name))
    return sorted(found)


def judge(root, on_purpose):
    """Return (problems, spenders): problems is a list of sentences."""
    problems = []
    spenders = []
    for path, f in facts(php_files(root)).items():
        if f["verify"] is not True or f["json"] is not True:
            continue
        spenders.append(path)
        rel = os.path.relpath(path, root).replace(os.sep, "/")
        if rel in on_purpose:
            if f["token"] is True:
                problems.append("%s is listed in ON_PURPOSE but now returns the token: remove it from the list" % rel)
            continue
        if f["token"] is not True:
            problems.append(
                "%s accepts a form token (Auth::verifyCsrf) and answers JSON but never returns a "
                "'csrfToken': the page it serves is left holding a spent token (#1134)" % rel
            )
    for rel in on_purpose:
        if os.path.join(root, rel.replace("/", os.sep)) not in spenders:
            problems.append("%s is listed in ON_PURPOSE but no longer spends a token and answers JSON: remove it" % rel)
    return problems, spenders


def self_test():
    """Planted handlers; each case says what the check must answer."""
    cases = {
        "red_no_token.php": ("<?php\nAuth::verifyCsrf($_POST['t'] ?? '');\necho json_encode(['ok' => true]);\n", True),
        "green_has_token.php": ("<?php\nAuth::verifyCsrf($_POST['t'] ?? '');\n$b['csrfToken'] = Auth::csrfToken();\necho json_encode($b);\n", False),
        "green_json_only_for_a_log.php": ("<?php\nAuth::verifyCsrf($_POST['t'] ?? '');\n$x = json_encode(['a' => 1]);\nheader('Location: /x');\n", False),
        "red_token_only_in_comment.php": ("<?php\n// the answer carries 'csrfToken'\n/** 'csrfToken' */\nAuth::verifyCsrf('');\nprint json_encode([]);\n", True),
        "red_exit_json.php": ("<?php\nAuth::verifyCsrf('');\nexit(json_encode(['ok' => false]));\n", True),
        "red_old_shape.php": ("<?php\nif (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {\n echo json_encode(['reason' => 'csrf']);\n exit;\n}\necho json_encode(['ok' => true]);\n", True),
        "green_no_verify.php": ("<?php\necho json_encode(['ok' => true]);\n", False),
        "green_does_not_spend.php": ("<?php\nAuth::verifyCsrfWithoutSpending('');\necho json_encode(['ok' => true]);\n", False),
        "api/green_api_folder.php": ("<?php\nAuth::verifyCsrf('');\necho json_encode(['ok' => true]);\n", False),
    }
    failures = []
    with tempfile.TemporaryDirectory() as tmp:
        for name, (code, _expect) in cases.items():
            path = os.path.join(tmp, name.replace("/", os.sep))
            os.makedirs(os.path.dirname(path), exist_ok=True)
            with open(path, "w") as handle:
                handle.write(code)
        problems, _ = judge(tmp, {})
        flagged = {p.split(" ", 1)[0] for p in problems}
        for name, (_code, expect_red) in cases.items():
            if (name in flagged) is not expect_red:
                failures.append("%s: expected %s, got %s" % (name, "RED" if expect_red else "green", "RED" if name in flagged else "green"))
        # The list fails both ways.
        with open(os.path.join(tmp, "green_has_token.php")) as _:
            pass
        stale, _ = judge(tmp, {"green_has_token.php": "x"})
        if not any("now returns the token" in p for p in stale):
            failures.append("a listed file that returns the token was not reported")
        gone, _ = judge(tmp, {"nothing_here.php": "x"})
        if not any("no longer spends" in p for p in gone):
            failures.append("a listed file that does not exist was not reported")
    if failures:
        print("SELF-TEST FAILED:")
        for line in failures:
            print("  - " + line)
        return 1
    print("self-test OK: %d planted handlers judged as expected, and the list fails both ways" % len(cases))
    return 0


def main():
    if "--self-test" in sys.argv:
        return self_test()
    problems, spenders = judge(APPS, ON_PURPOSE)
    if problems:
        print("A handler spends the form token and returns no replacement:")
        for line in problems:
            print("  - " + line)
        return 1
    print("OK: %d handler(s) outside api/ spend the form token and answer JSON; each returns a 'csrfToken'." % len(spenders))
    return 0


if __name__ == "__main__":
    sys.exit(main())
