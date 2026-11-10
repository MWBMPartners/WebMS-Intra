#!/usr/bin/env python3
"""
Every sign-in begins its session through Auth::beginSessionFor() (#900, #902).
=============================================================================

WHAT WENT WRONG (#900). Every way of signing in called
`session_regenerate_id(true)` and then wrote the person's number into the
session. That gives the session a new IDENTIFIER and keeps every ENTRY in it,
so whatever the previous person on that browser had waiting -- a message, kept
form typing, a come-back address, an API key shown once -- was handed to
whoever signed in next. `Auth::beginSessionFor($userId)` is now the ONE place a
sign-in decides what it keeps, and it is called at all six grants.

WHY A CHECK. The fault #900 fixed was not in any one page: it was a SHAPE that
six pages shared, and a seventh way of signing in written tomorrow would have
the same shape unless something says no. Part K of `tools/two-step-selftest.php`
proves the six behave; it cannot see a page that does not exist yet.

WHAT IT CHECKS, with PHP's own tokeniser (`sign-in-session-structure.php`
beside this file -- never a regular expression over PHP, which this repository
has had defeated by comments, strings, heredocs and `{$x}` more times than it
wants to count, #763 and #775):

  A  Every write of a signed-in identity anywhere under `web/` -- the entries
     `user_id` and `2fa_user_id`, by `$_SESSION[...]` or
     `$GLOBALS['_SESSION'][...]`, by `=` or any compound assignment, or as a
     key in a whole-session assignment -- is preceded IN THE SAME FUNCTION (or
     the same file, outside any function) by a call to
     `Auth::beginSessionFor()` that:
       - is a statement of its own (not `false && …`, not a ternary arm, not
         the braceless body of an `if`);
       - sits in a block that ENCLOSES the write (so `if (false) { call; }`
         then the write, in braces or `if (): … endif;`, does not count, and
         nor does a call in a closure or a `try` the write is outside);
       - is the nearest such call, and names the SAME person the write names,
         token for token.

     `2fa_user_id` counts as a grant, worked out rather than assumed:
     `Auth::sessionPerson()` reads it as whose session this is, and
     `2fa/verify.php` then calls `beginSessionFor()` for that SAME person,
     which keeps everything. So a write of it that no call came before would
     carry the previous person's entries straight through the code step.

  B  The one delegated grant: `_apps/auth/login/index.php` writes
     `2fa_user_id` inside `if (Auth::loginLocal(...) === true)`, and
     `Auth::loginLocal()` makes the call itself (through
     `loginLocalOneAtATime()`). Only that exact condition is accepted, and the
     chain is followed: `loginLocal` must still call `loginLocalOneAtATime`,
     and that must still hold its own checked grant.

  C  FAILS BOTH WAYS on the list of grants (`KNOWN_GRANTS`) and the list of
     call sites (`KNOWN_CALL_SITES`): a listed grant or call that is gone, and
     a new one nobody listed. Adding a way of signing in is meant to be a
     visible, reviewed act, with the reason written here.

  D  A key written as a CONSTANT (`$_SESSION[self::PROOF_KEY] = …`) is
     resolved from the constants the probe collects (`const X = '…';`, file
     or class, and `define('X', '…')`); one that turns out to be an identity
     key is a finding. A write through a truly VARIABLE key
     (`$_SESSION[$key] = …`), or a constant that cannot be resolved, cannot be
     judged and must be named in `VARIABLE_KEY_WRITES_ON_PURPOSE`, both ways.
     There is one today.

WHAT IT CANNOT SEE, said plainly and not to be read as covered
  - Whether the code RUNS. A call that passes every rule above can still be
    dead: a `return;`, `exit;`, `goto` or `while (true) {}` above it, a
    conditional `require` of the file, a throw. "Is this code reached" is a
    behavioural question (D-065), so part K of `tools/two-step-selftest.php`
    DRIVES every grant -- password, Microsoft, Google, passkey, invitation and
    the code step -- and its plants prove each one is seen. The self-test
    below includes one such dead call (`while (true) {}`) and requires this
    check to stay GREEN over it, so the blind spot is measured, not assumed.
  - Whether `Auth::beginSessionFor()` itself still does its job; part K's
    plants are what prove that.
  - Whether `Auth::loginLocal()` answers true only after it granted. It is
    followed by name, not by meaning.
  - A write through a reference (`$s = &$_SESSION;`), `extract()`,
    `session_decode()`, `array_merge()` of a variable into the whole session,
    or destructuring. A class alias for Auth is not recognised and is reported
    as a missing call -- the safe direction.

Run:        python3 tools/audit-checks/check_sign_in_begins_session.py
Self-test:  python3 tools/audit-checks/check_sign_in_begins_session.py --self-test
Exit 0 when clean; 1 on any finding, and when PHP cannot be asked. There is
DELIBERATELY no fallback to reading the PHP in Python (the
`check_member_language_applied.py` precedent).

@package   Portal\\Tools\\AuditChecks
@author    MWBM Partners Ltd (t/a MWservices)
@copyright 2026-present MWBM Partners Ltd (t/a MWservices)
@license   All Rights Reserved
@version   1.0.0
@link      https://github.com/MWBMPartners/WebMS-Intra/issues/902
"""

from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
import tempfile
from collections import Counter

HERE = os.path.dirname(os.path.abspath(__file__))
PROBE = os.path.join(HERE, "sign-in-session-structure.php")

# Generous: about a thousand files tokenised once. It stops a hang, not a race.
PROBE_TIMEOUT_SECONDS = 120

# 🔑 Every grant of a signed-in identity, as (file, function, key). The function
#    is "Class::method", or "(file)" for code outside any function. How each is
#    covered is in the fourth field: 'begin' (its own call comes first) or
#    'delegated' (inside `if (Auth::loginLocal(...) === true)`).
KNOWN_GRANTS = [
    ("web/_core/Auth.php", "Auth::loginLocalOneAtATime", "user_id", "begin"),        # a password
    ("web/_core/Auth.php", "Auth::callbackMS365", "user_id", "begin"),               # Microsoft
    ("web/_core/Auth.php", "Auth::callbackMS365", "2fa_user_id", "begin"),           # ...then waiting for a code
    ("web/_core/Auth.php", "Auth::callbackGoogle", "user_id", "begin"),              # Google
    ("web/_core/Auth.php", "Auth::callbackGoogle", "2fa_user_id", "begin"),          # ...then waiting for a code
    ("web/_apps/auth/login/webauthn.php", "(file)", "user_id", "begin"),             # a passkey
    ("web/_apps/auth/login/webauthn.php", "(file)", "2fa_user_id", "begin"),         # ...then waiting for a code
    ("web/_apps/invites/accept.php", "(file)", "user_id", "begin"),                  # an invitation
    ("web/_apps/auth/2fa/verify.php", "(file)", "user_id", "begin"),                 # the code step
    ("web/_apps/auth/login/index.php", "(file)", "2fa_user_id", "delegated"),        # password page, waiting for a code
]

# 🔑 Every call to Auth::beginSessionFor(), as (file, function). One each.
KNOWN_CALL_SITES = [
    ("web/_core/Auth.php", "Auth::loginLocalOneAtATime"),
    ("web/_core/Auth.php", "Auth::callbackMS365"),
    ("web/_core/Auth.php", "Auth::callbackGoogle"),
    ("web/_apps/auth/login/webauthn.php", "(file)"),
    ("web/_apps/invites/accept.php", "(file)"),
    ("web/_apps/auth/2fa/verify.php", "(file)"),
]

# 🔗 The delegated grant: the function a page calls, the file it lives in, the
#    call it must still make, and the function that call must reach.
DELEGATES = {
    "Auth::loginLocal": ("web/_core/Auth.php", "self::loginLocalOneAtATime", "Auth::loginLocalOneAtATime"),
}

# 🔍 Writes through a variable key. This check cannot see what the key is, so
#    each must be named with a reason.
VARIABLE_KEY_WRITES_ON_PURPOSE = {
    ("web/_apps/recordings/view.php", "(file)", "$playKey"):
        "marks one recording as played this session; the key is built from the recording's number, never an identity key",
}


def php_files(root: str) -> list[str]:
    """Every .php file under web/, as a path relative to the root, sorted."""
    out: list[str] = []
    for folder, dirs, files in os.walk(os.path.join(root, "web")):
        dirs.sort()
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
        done = subprocess.run(
            [php, PROBE],
            input=json.dumps(files),
            capture_output=True,
            text=True,
            timeout=PROBE_TIMEOUT_SECONDS,
            cwd=root,
        )
    except (subprocess.TimeoutExpired, OSError) as exc:
        return "the probe did not finish: " + str(exc)
    try:
        answers = json.loads(done.stdout.strip() or "null")
    except ValueError:
        return "the probe's answer was not JSON (exit " + str(done.returncode) + "): " + done.stderr.strip()[:300]
    if isinstance(answers, list) is False or len(answers) != len(files):
        return "the probe answered for a different number of files than it was asked about"
    return answers


def analyse(root: str) -> list[str]:
    """Every finding for the tree at `root`. An empty list means clean."""
    files = php_files(root)
    answers = ask_php(root, files)
    if isinstance(answers, str):
        return ["CANNOT RUN: " + answers + ". Nothing was checked; this is a failure, not a pass."]

    problems: list[str] = []
    found_grants: Counter = Counter()
    found_calls: Counter = Counter()
    found_dynamic: Counter = Counter()
    grant_ok: dict = {}
    calls_by_scope: dict = {}

    # Every plain-string constant in the tree, by name. A name defined twice
    # with different values is left unresolved (None), never guessed.
    constants: dict = {}
    for answer in answers:
        for c in answer.get("consts", []) if isinstance(answer, dict) else []:
            if c["name"] in constants and constants[c["name"]] != c["value"]:
                constants[c["name"]] = None
            else:
                constants.setdefault(c["name"], c["value"])

    def resolve(key_text: str, scope: str):
        """The value of a constant key, or None when it cannot be known."""
        name = key_text.lstrip("\\")
        if "::" in name:
            cls, const = name.rsplit("::", 1)
            if cls.lower() in ("self", "static"):
                if "::" not in scope:
                    return None
                cls = scope.split("::", 1)[0]
            name = cls.rsplit("\\", 1)[-1] + "::" + const
        elif "\\" in name:
            name = name.rsplit("\\", 1)[-1]
        if name.replace("::", "").replace("_", "").isalnum() is False:
            return None
        return constants.get(name)

    for relative, answer in zip(files, answers):
        if "error" in answer:
            problems.append(f"{relative}: {answer['error']}. A file PHP cannot read cannot be judged.")
            continue
        for scope, names in (answer.get("calls") or {}).items():
            calls_by_scope[(relative, scope)] = names
        for call in answer.get("begins", []):
            found_calls[(relative, call["scope"])] += 1
        for d in answer.get("dynamic", []):
            value = resolve(d["key"], d["scope"])
            if value is None:
                found_dynamic[(relative, d["scope"], d["key"])] += 1
            elif value in ("user_id", "2fa_user_id"):
                problems.append(f"{relative}:{d['line']} ({d['scope']}): `$_SESSION[{d['key']}]` is the identity key "
                                f"`{value}` written through a constant. Write a grant with the plain key, after "
                                "Auth::beginSessionFor(), so this check can follow it.")
        for w in answer.get("writes", []):
            where = f"{relative}:{w['line']} ({w['scope']})"
            what = f"`$_SESSION['{w['key']}']`" if w["form"] == "entry" else f"`{w['key']}` ({w['form']})"
            found_grants[(relative, w["scope"], w["key"])] += 1
            ok = True
            if w.get("arrow") is True:
                problems.append(f"{where}: {what} is written inside an arrow function, which has no statements, so no call "
                                "can be said to come before it. Write the grant as ordinary statements.")
                ok = False
            elif w["begin"] is None and w["delegated"] == "":
                why = "; ".join(f"the call on line {p['line']} does not count: {p['why']}" for p in w["passedOver"])
                problems.append(f"{where}: {what} is written with no call to Auth::beginSessionFor() before it that counts"
                                + (f" ({why})" if why else " (there is none in this function)")
                                + ". So whatever the previous person left in this browser's session is handed to this one (#900).")
                ok = False
            elif w["begin"] is not None and w["begin"]["arg"] != w["value"]:
                problems.append(f"{where}: Auth::beginSessionFor({w['begin']['arg']}) on line {w['begin']['line']} begins the "
                                f"session for a different value from the one {what} is given ({w['value'] or '(nothing)'}). "
                                "The session must be begun for the person being signed in.")
                ok = False
            if w["delegated"] != "" and w["begin"] is None and ok:
                grant_ok[(relative, w["scope"], w["key"])] = "delegated:" + w["delegated"]
            elif ok:
                grant_ok[(relative, w["scope"], w["key"])] = "begin"

    # C: both ways, for grants and call sites.
    expected_grants = Counter((f, s, k) for f, s, k, _ in KNOWN_GRANTS)
    for item in sorted(expected_grants - found_grants):
        problems.append(f"{item[0]} ({item[1]}): the listed grant of `{item[2]}` is no longer found. If this way of signing in "
                        "really has gone, remove it from KNOWN_GRANTS; if it moved, the list must say where.")
    for item in sorted(found_grants - expected_grants):
        problems.append(f"{item[0]} ({item[1]}): a grant of `{item[2]}` that is not on KNOWN_GRANTS. A new way of signing in "
                        "must be listed here on purpose, and must call Auth::beginSessionFor() first.")
    for f, s, k, how in KNOWN_GRANTS:
        got = grant_ok.get((f, s, k))
        if got is not None and how == "begin" and got != "begin":
            problems.append(f"{f} ({s}): the grant of `{k}` is listed as making its own call and now relies on {got}.")
        if got is not None and how == "delegated" and got.startswith("delegated:") is False:
            problems.append(f"{f} ({s}): the grant of `{k}` is listed as delegated and now makes its own call; update the list.")

    expected_calls = Counter(KNOWN_CALL_SITES)
    for item in sorted(expected_calls - found_calls):
        problems.append(f"{item[0]} ({item[1]}): the listed call to Auth::beginSessionFor() is no longer found.")
    for item in sorted(found_calls - expected_calls):
        problems.append(f"{item[0]} ({item[1]}): a call to Auth::beginSessionFor() that is not on KNOWN_CALL_SITES. "
                        "Every call ends whatever the session held for somebody else, so each is listed on purpose.")

    # B: the delegated grant's chain.
    for delegate, (dfile, must_call, reaches) in DELEGATES.items():
        used = any(v == "delegated:" + delegate for v in grant_ok.values())
        if used is False:
            continue
        if must_call not in calls_by_scope.get((dfile, delegate), []):
            problems.append(f"{dfile} ({delegate}): a page relies on {delegate}() to begin the session, and it no longer calls "
                            f"{must_call}(), which is where the call to Auth::beginSessionFor() is.")
        if grant_ok.get((dfile, reaches, "user_id")) != "begin":
            problems.append(f"{dfile} ({reaches}): a page relies on {delegate}() to begin the session, and {reaches}() no longer "
                            "holds a checked grant of `user_id`.")

    # D: variable keys, both ways.
    expected_dynamic = Counter(VARIABLE_KEY_WRITES_ON_PURPOSE.keys())
    for item in sorted(found_dynamic - expected_dynamic):
        problems.append(f"{item[0]} ({item[1]}): `$_SESSION[{item[2]}] = …` writes through a variable key, which this check "
                        "cannot judge. If it can never be an identity key, name it in VARIABLE_KEY_WRITES_ON_PURPOSE with the reason.")
    for item in sorted(expected_dynamic - found_dynamic):
        problems.append(f"{item[0]} ({item[1]}): VARIABLE_KEY_WRITES_ON_PURPOSE names `$_SESSION[{item[2]}]`, which is no longer "
                        "found. Remove the entry.")

    return problems


# =============================================================================
# 🧪 Self-test: planted faults, each required to be reported, and shapes that
#    must stay green. Run against a COPY of the real files, never the repository.
# =============================================================================
COPIED = [
    "web/_core/Auth.php",
    "web/_apps/auth/login/webauthn.php",
    "web/_apps/invites/accept.php",
    "web/_apps/auth/2fa/verify.php",
    "web/_apps/auth/login/index.php",
    "web/_apps/recordings/view.php",
]

ACCEPT_CALL = "Auth::beginSessionFor($newUserId);"
VERIFY_PAIR = "    Auth::beginSessionFor($pendingUserId);\n    $_SESSION['user_id']    = $pendingUserId;"

# (name, file, from, to, words the finding must contain). `to` of None with a
# file that does not exist means "write this new file with `from` as content".
PLANTS = [
    ("the passkey call removed", "web/_apps/auth/login/webauthn.php",
        "            Auth::beginSessionFor($webauthnUserId);\n", "", "there is none in this function"),
    ("the call moved AFTER the write", "web/_apps/auth/2fa/verify.php",
        VERIFY_PAIR, "    $_SESSION['user_id']    = $pendingUserId;\n    Auth::beginSessionFor($pendingUserId);", "verify.php:"),
    ("the call left only in a comment", "web/_apps/invites/accept.php",
        ACCEPT_CALL, "// " + ACCEPT_CALL, "accept.php:"),
    ("the call left only in a string", "web/_apps/invites/accept.php",
        ACCEPT_CALL, "$unused = '" + ACCEPT_CALL + "';", "accept.php:"),
    ("the call inside if (false) { }", "web/_apps/invites/accept.php",
        ACCEPT_CALL, "if (false) { " + ACCEPT_CALL + " }", "does not enclose the write"),
    ("the call as the braceless body of if (false)", "web/_apps/invites/accept.php",
        ACCEPT_CALL, "if (false) " + ACCEPT_CALL, "accept.php:"),
    ("the call behind false &&", "web/_apps/invites/accept.php",
        ACCEPT_CALL, "false && " + ACCEPT_CALL, "not a statement of its own"),
    ("the call in if (false): … endif;", "web/_apps/invites/accept.php",
        ACCEPT_CALL, "if (false): " + ACCEPT_CALL + " endif;", "does not enclose the write"),
    ("the call in a try that swallows its failure", "web/_apps/invites/accept.php",
        ACCEPT_CALL, "try { " + ACCEPT_CALL + " } catch (\\Throwable $p902) { }", "does not enclose the write"),
    ("the call as a ternary arm", "web/_apps/invites/accept.php",
        ACCEPT_CALL, "$p902 = false ? Auth::beginSessionFor($newUserId) : null;", "not a statement of its own"),
    ("a method of the same name on another class", "web/_apps/invites/accept.php",
        ACCEPT_CALL, "Other::beginSessionFor($newUserId);", "it is not Auth::beginSessionFor()"),
    ("the session begun for somebody else", "web/_apps/invites/accept.php",
        ACCEPT_CALL, "Auth::beginSessionFor((int) $invite['invitationID']);", "different value"),
    ("the call inside a closure defined first", "web/_apps/auth/2fa/verify.php",
        "    Auth::beginSessionFor($pendingUserId);\n",
        "    $p902 = static function () use ($pendingUserId): void { Auth::beginSessionFor($pendingUserId); };\n", "verify.php:250"),
    ("the Microsoft callback's call removed (its sign-in AND its code-waiting write)", "web/_core/Auth.php",
        "on purpose (#731).\n        self::beginSessionFor($userId);", "on purpose (#731).\n        session_regenerate_id(true);",
        "Auth.php:1921"),
    ("the delegated condition widened", "web/_apps/auth/login/index.php",
        "if (Auth::loginLocal($username, $password) === true) {", "if (Auth::loginLocal($username, $password) === true || true) {",
        "index.php:"),
    ("the delegated chain broken", "web/_core/Auth.php",
        "return self::loginLocalOneAtATime($mysqli, $identifier, $password, $ip);",
        "return self::loginLocalSomethingElse($mysqli, $identifier, $password, $ip);", "no longer calls"),
    ("a seventh way of signing in, done right but not listed", "web/_apps/p902/new-grant.php",
        "<?php\nuse Portal\\Core\\Auth;\nAuth::beginSessionFor($id);\n$_SESSION['user_id'] = $id;\n", None, "not on KNOWN_GRANTS"),
    ("a new grant in double quotes with no call", "web/_apps/p902/new-grant.php",
        "<?php\n$_SESSION[\"user_id\"] = 7;\n", None, "no call to Auth::beginSessionFor()"),
    ("a new grant through $GLOBALS", "web/_apps/p902/new-grant.php",
        "<?php\n$GLOBALS['_SESSION']['2fa_user_id'] = 7;\n", None, "2fa_user_id"),
    ("a new grant as a whole-session assignment", "web/_apps/p902/new-grant.php",
        "<?php\n$_SESSION = ['user_id' => 7];\n", None, "whole session"),
    ("a new grant by ??=", "web/_apps/p902/new-grant.php",
        "<?php\n$_SESSION['user_id'] ??= 7;\n", None, "no call to Auth::beginSessionFor()"),
    ("a new grant inside an arrow function", "web/_apps/p902/new-grant.php",
        "<?php\n$f = fn () => $_SESSION['user_id'] = 7;\n", None, "arrow function"),
    ("a new write through a variable key", "web/_apps/p902/new-grant.php",
        "<?php\n$_SESSION[$k] = 1;\n", None, "variable key"),
    ("a grant through a constant that names the identity key", "web/_apps/p902/new-grant.php",
        "<?php\nconst P902_KEY = 'user_id';\n$_SESSION[P902_KEY] = 7;\n", None, "written through a constant"),
    ("a write through a constant nobody defined", "web/_apps/p902/new-grant.php",
        "<?php\n$_SESSION[P902_UNDEFINED] = 7;\n", None, "variable key"),
    ("the listed variable-key write gone", "web/_apps/recordings/view.php",
        "$_SESSION[$playKey] = 1;", "$_SESSION['p902'] = 1;", "no longer found"),
    ("a file PHP will not parse", "web/_apps/p902/new-grant.php",
        "<?php\nif (\n", None, "cannot be judged"),
]

# Shapes that must NOT be reported: (name, file, from, to). `to` None = new file.
GREEN = [
    ("the method name in capitals (PHP ignores case)", "web/_apps/invites/accept.php",
        ACCEPT_CALL, "AUTH::BEGINSESSIONFOR($newUserId);"),
    ("the fully qualified class name", "web/_apps/invites/accept.php",
        ACCEPT_CALL, "\\Portal\\Core\\Auth::beginSessionFor($newUserId);"),
    ("an attribute on a parameter of a listed function (its ] must not end a bracket it did not open)", "web/_core/Auth.php",
        "private static function loginLocalOneAtATime(\\mysqli $mysqli, string $identifier, string $password, string $ip): bool",
        "private static function loginLocalOneAtATime(\\mysqli $mysqli, string $identifier, #[\\SensitiveParameter] string $password, string $ip): bool"),
    ("a {$x} string between the call and the write", "web/_apps/invites/accept.php",
        ACCEPT_CALL, ACCEPT_CALL + "\n$p902 = \"for {$newUserId} }\";"),
    ("a nowdoc holding braces and a fake write between them", "web/_apps/invites/accept.php",
        ACCEPT_CALL, ACCEPT_CALL + "\n$p902 = <<<'TXT'\n} } $_SESSION['user_id'] = 1;\nTXT;\n"),
    ("an attribute on a closure between them", "web/_apps/invites/accept.php",
        ACCEPT_CALL, ACCEPT_CALL + "\n$p902 = #[\\Deprecated] static function (): void {};"),
    ("the call and the write together in one inner block", "web/_apps/auth/2fa/verify.php",
        VERIFY_PAIR, "    if ($pendingUserId > 0) {\n" + VERIFY_PAIR + "\n    }"),
    ("quotations, comparisons and an interpolation that are not writes", "web/_apps/p902/not-a-grant.php",
        "<?php\n// $_SESSION['user_id'] = 1;\n$a = \"{$_SESSION['user_id']} = 1\";\n$b = $_SESSION['user_id'] === 5;\n"
        "$c = $_SESSION['user_id'] ?? 0;\nunset($_SESSION['2fa_user_id']);\n", None),
    ("a write through a class constant holding an ordinary key", "web/_apps/p902/not-a-grant.php",
        "<?php\nclass P902Thing\n{\n    public const KEEP = 'p902_kept', OTHER = 'x';\n"
        "    public static function put(): void\n    {\n        $_SESSION[self::KEEP] = 1;\n        $_SESSION[P902Thing::OTHER] = 2;\n    }\n}\n", None),
    # A MEASURED BLIND SPOT, not a feature: the call can never run, and this
    # check cannot see that. Part K of tools/two-step-selftest.php is what
    # catches a dead grant. If this ever turns red, move it to PLANTS.
    ("BLIND SPOT: a while (true) {} above the call (dead code the check cannot see)", "web/_apps/invites/accept.php",
        ACCEPT_CALL, "while (true) { }\n" + ACCEPT_CALL),
]


def self_test() -> int:
    repo = os.path.dirname(os.path.dirname(HERE))
    passed = failed = 0

    def report(ok: bool, label: str, detail: str = "") -> None:
        nonlocal passed, failed
        if ok:
            passed += 1
            print("PASS — " + label)
        else:
            failed += 1
            print("FAIL — " + label + ("\n        " + detail if detail else ""))

    with tempfile.TemporaryDirectory(prefix="p902-sign-in-check-") as tmp:
        originals: dict[str, str] = {}
        for rel in COPIED:
            src = os.path.join(repo, rel)
            dst = os.path.join(tmp, rel)
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            with open(src, encoding="utf-8") as fh:
                originals[rel] = fh.read()
            with open(dst, "w", encoding="utf-8") as fh:
                fh.write(originals[rel])

        def apply(rel: str, old: str, new) -> bool:
            path = os.path.join(tmp, rel)
            if new is None:
                os.makedirs(os.path.dirname(path), exist_ok=True)
                with open(path, "w", encoding="utf-8") as fh:
                    fh.write(old)
                return True
            body = originals[rel]
            if body.count(old) != 1:
                return False
            with open(path, "w", encoding="utf-8") as fh:
                fh.write(body.replace(old, new))
            return True

        def restore(rel: str, new) -> None:
            path = os.path.join(tmp, rel)
            if new is None:
                os.remove(path)
            else:
                with open(path, "w", encoding="utf-8") as fh:
                    fh.write(originals[rel])

        base = analyse(tmp)
        report(base == [], "the unplanted copy of the six sign-in files and the password page is clean", " | ".join(base))

        for name, rel, old, new, words in PLANTS:
            if apply(rel, old, new) is False:
                report(False, "PLANT " + name, "the plant did not match exactly one place, so it was not applied")
                continue
            got = analyse(tmp)
            restore(rel, new)
            report(any(words in p for p in got), "PLANT " + name + " is reported",
                   "expected a finding containing '" + words + "'; got: " + (" | ".join(got) or "(nothing)"))

        for name, rel, old, new in GREEN:
            if apply(rel, old, new) is False:
                report(False, "GREEN " + name, "the case did not match exactly one place, so it was not applied")
                continue
            got = analyse(tmp)
            restore(rel, new)
            report(got == [], "GREEN " + name + " is not reported", " | ".join(got))

        again = analyse(tmp)
        report(again == [], "the copy is clean again after every case (each was put back)", " | ".join(again))

    expected = 2 + len(PLANTS) + len(GREEN)
    print(f"\n{passed} passed, {failed} failed, {passed + failed} ran of {expected} expected "
          f"({len(PLANTS)} plants, {len(GREEN)} must-stay-green cases).")
    if failed > 0 or passed + failed != expected:
        print("FAIL — the self-test of check_sign_in_begins_session.py did not pass.")
        return 1
    print("PASS — every plant is reported and every green case is left alone.")
    return 0


def main() -> int:
    if "--self-test" in sys.argv[1:]:
        return self_test()
    root = os.path.dirname(os.path.dirname(HERE))
    problems = analyse(root)
    if problems:
        print("FAIL: a sign-in does not begin its session through Auth::beginSessionFor() (#900, #902):\n")
        for p in problems:
            print("  - " + p)
        print("\nWhy it matters: without that call, whatever the previous person left in this browser's session is "
              "handed to whoever signs in next. Note what this check cannot see: whether the call RUNS. Part K of "
              "tools/two-step-selftest.php drives every sign-in for that.")
        return 1
    print(f"OK: all {len(KNOWN_GRANTS)} grants of a signed-in identity under web/ begin their session through "
          f"Auth::beginSessionFor() ({len(KNOWN_CALL_SITES)} call sites, one delegated grant followed). "
          "This says each call is PRESENT and IN ORDER, not that it runs; part K of tools/two-step-selftest.php drives that.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
