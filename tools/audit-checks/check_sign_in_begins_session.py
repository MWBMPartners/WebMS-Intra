#!/usr/bin/env python3
"""
Every sign-in begins its session through Auth::beginSessionFor() (#900, #902).
=============================================================================

WHAT WENT WRONG (#900). Every way of signing in called
`session_regenerate_id(true)` and then wrote the person's number into the
session. That gives the session a new IDENTIFIER and keeps every ENTRY in it,
so whatever the previous person on that browser had waiting -- a message, kept
form typing, a come-back address -- was handed to whoever signed in next.
`Auth::beginSessionFor($userId)` is now the ONE place a sign-in decides what it
keeps, and it is called at all six grants.

WHY A CHECK. The fault was a SHAPE six pages shared. A seventh way of signing
in written tomorrow would have it too unless something says no; part K of
`tools/two-step-selftest.php` drives the six that exist and cannot see one that
does not yet.

WHAT IT CHECKS -- PRESENCE AND ORDER, NOTHING ELSE (D-065). PHP's own tokeniser
reads every file under `web/` (`sign-in-session-structure.php` beside this
file; never a regular expression over PHP, #763 and #775):

  A  Every write of a signed-in identity -- the entries `user_id` and
     `2fa_user_id`, by `$_SESSION[...]` or `$GLOBALS['_SESSION'][...]`, by `=`
     or any compound assignment, as a `foreach` target (`as X`, `as &X`,
     `as $k => X`, `as $k => &X`), or as a top-level key of a LITERAL array
     that is the whole right-hand side of `$_SESSION = […];` / `+= […];` with a
     value other than null, true or false -- has a call to
     `Auth::beginSessionFor(` EARLIER IN THE SAME FUNCTION (or the same file,
     outside any function). A write of `null`, directly or at the end of a
     chain (`$_SESSION['user_id'] = $_SESSION['2fa_user_id'] = null;`), is a
     sign-out and is not a grant.

     `2fa_user_id` counts as a grant, worked out rather than assumed:
     `Auth::sessionPerson()` reads it as whose session this is, and
     `2fa/verify.php` then calls `beginSessionFor()` for that SAME person, which
     keeps everything -- so a write of it with no call first would carry the
     previous person's entries straight through the code step.

  B  The one delegated grant: `_apps/auth/login/index.php` writes
     `2fa_user_id` after `Auth::loginLocal(`, which makes the call itself
     (through `loginLocalOneAtATime()`). A `loginLocal` call counts only for a
     grant LISTED as delegated, and the chain is followed by name: `loginLocal`
     must still call `loginLocalOneAtATime` -- written `self::`, `static::`,
     `Auth::` or any other way that names Auth, in any letter case, since PHP
     treats them all as one call -- and that must still hold a grant with its
     own call.

  C  FAILS BOTH WAYS on `KNOWN_GRANTS` and `KNOWN_CALL_SITES`: a listed grant
     or call that has gone, and a new one nobody listed. Adding a way of signing
     in is meant to be a visible, reviewed act.

  D  A write through any key that is not one plain string -- a variable, a
     constant, a concatenation such as `'user' . '_id'` -- cannot be read as
     written. A constant is resolved from `const X = '…';` / `define('X',
     '…')`; one naming an identity key is a finding. Everything else must be
     named in `VARIABLE_KEY_WRITES_ON_PURPOSE`, both ways. There is one today.

WHY NOT MORE. The first version also judged whether the call was a statement of
its own, whether its block enclosed the write, and whether it named the same
value. Each of those asks "does this call RUN, for this person" -- a behavioural
question -- and each cried wolf on correct code: a named argument, a cast on one
side only, a `do { } while (false)`, a label. A check that cries wolf gets
switched off (#483). Part K of `tools/two-step-selftest.php` DRIVES every
sign-in, so the behavioural half is measured there, where it can be.

THE HOLES THIS CHECK DOES NOT CLOSE, declared so nobody reads it as covering
them. Part K of `tools/two-step-selftest.php` drives the real sign-ins and is
what catches the ones that matter in practice.

  MEASURED -- each is a case in `BLIND` below, and the self-test requires this
  check to stay GREEN over it, so the day one stops being blind it says so:
  - a dead call: inside `if (false) { … }`, behind `false &&`, in a `try` that
    swallows the call's failure, or below a `while (true) {}`;
  - the session begun for somebody else, or the value REASSIGNED between the
    call and the write (the argument is never compared with the value);
  - the whole session put back between the call and the write
    (`$_SESSION = $keep;`, `$_SESSION += $keep;`);
  - a call in one `switch` case and the write in another;
  - the delegated grant's value (login/index.php's `2fa_user_id` is compared
    with nothing `loginLocal()` was given);
  - a grant by destructuring (`[$_SESSION['user_id']] = …`);
  - a whole-session write that is not a literal array, such as
    `$_SESSION = array_merge($_SESSION, ['user_id' => $id]);` -- function
    arguments are not read, because reading them cried wolf on the sign-out
    `array_diff_key($_SESSION, ['user_id' => true])`.

  DECLARED, NOT MEASURED -- no case in the self-test:
  - other dead calls: a ternary arm, a `return;`, `exit;` or `goto` above the
    call, a conditional `require` of the whole file;
  - a write through a reference (`$s = &$_SESSION;`), `extract()`,
    `session_decode()`, or a destructured `foreach` target (`as [X]`);
  - whether `Auth::beginSessionFor()` itself still works (part K's plants
    prove that), and whether `Auth::loginLocal()` answers true only after a
    grant.

  Imports ARE read: `use X;`, `use X as Y;` and the grouped
  `use A\\{B, C as D};`, so `use Something\\Else as Auth;` does not count as
  Auth and `use Portal\\Core\\{Auth as PA};` makes `PA::` count. Not read:
  `use` inside a `namespace { }` block or a second namespace in one file.

Run:        python3 tools/audit-checks/check_sign_in_begins_session.py
Self-test:  python3 tools/audit-checks/check_sign_in_begins_session.py --self-test
Exit 0 when clean; 1 on any finding, and when PHP cannot be asked. There is
DELIBERATELY no fallback to reading the PHP in Python (the
`check_member_language_applied.py` precedent).

@package   Portal\\Tools\\AuditChecks
@author    MWBM Partners Ltd (t/a MWservices)
@copyright 2026-present MWBM Partners Ltd (t/a MWservices)
@license   All Rights Reserved
@version   2.0.0
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

# Generous: about a thousand files tokenised once (about half a second here).
PROBE_TIMEOUT_SECONDS = 120

CANNOT_RUN = "CANNOT RUN: "

# 🔑 Every grant of a signed-in identity, as (file, function, key, how). The
#    function is "Class::method", or "(file)" outside any function. 'begin':
#    its own call comes first. 'delegated': it comes after Auth::loginLocal(.
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

# 🔗 The delegated grant: (file, function, the call it must still make, the
#    function that call must reach).
# The probe records any call on Auth -- however the class is written -- as
# "Auth::" plus the method name in small letters, so this is compared exactly.
DELEGATE = ("web/_core/Auth.php", "Auth::loginLocal", "Auth::loginlocaloneatatime", "Auth::loginLocalOneAtATime")

# 🔍 Writes through a key that cannot be read as written, each with a reason.
VARIABLE_KEY_WRITES_ON_PURPOSE = {
    ("web/_apps/recordings/view.php", "(file)", "$playKey"):
        "marks one recording as played this session; the key is built from the recording's number, never an identity key",
}

IDENTITY_KEYS = ("user_id", "2fa_user_id")


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


def analyse(root: str) -> list[str]:
    """Every finding for the tree at `root`. An empty list means clean."""
    files = php_files(root)
    answers = ask_php(root, files)
    if isinstance(answers, str):
        return [CANNOT_RUN + answers + ". Nothing was checked; this is a failure, not a pass."]

    # Every plain-string constant, by name; one defined twice differently is unresolved.
    constants: dict = {}
    for answer in answers:
        for c in answer.get("consts", []):
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
            return None  # a concatenation, a variable, an expression
        return constants.get(name)

    listed_how = {(f, s, k): how for f, s, k, how in KNOWN_GRANTS}
    problems: list[str] = []
    found_grants: Counter = Counter()
    found_calls: Counter = Counter()
    found_dynamic: Counter = Counter()
    covered: dict = {}
    calls_by_scope: dict = {}

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
            elif value in IDENTITY_KEYS:
                problems.append(f"{relative}:{d['line']} ({d['scope']}): `$_SESSION[{d['key']}]` is the identity key "
                                f"`{value}` written through a constant. Write a grant with the plain key, after "
                                "Auth::beginSessionFor(), so this check can follow it.")
        for w in answer.get("writes", []):
            where = f"{relative}:{w['line']} ({w['scope']})"
            what = f"`$_SESSION['{w['key']}']`" + ("" if w["form"] == "entry" else f" ({w['form']})")
            item = (relative, w["scope"], w["key"])
            found_grants[item] += 1
            if w["beginLine"] is not None:
                covered[item] = "begin"
            elif w["loginLocalLine"] is not None and listed_how.get(item) == "delegated":
                covered[item] = "delegated"
            else:
                problems.append(f"{where}: {what} is written with no call to Auth::beginSessionFor() before it in the "
                                "same function. So whatever the previous person left in this browser's session is "
                                "handed to this one (#900).")

    # C: both ways.
    expected_grants = Counter((f, s, k) for f, s, k, _ in KNOWN_GRANTS)
    for item in sorted(expected_grants - found_grants):
        if found_grants[item] > 0:
            problems.append(f"{item[0]} ({item[1]}): the grant of `{item[2]}` is listed {expected_grants[item]} times in "
                            f"KNOWN_GRANTS and found {found_grants[item]} time(s). List each grant once per write.")
        else:
            problems.append(f"{item[0]} ({item[1]}): the listed grant of `{item[2]}` is no longer found. If this way of "
                            "signing in really has gone, remove it from KNOWN_GRANTS; if it moved, the list must say where.")
    for item in sorted(found_grants - expected_grants):
        if expected_grants[item] > 0:
            problems.append(f"{item[0]} ({item[1]}): `{item[2]}` is written {found_grants[item]} times in this function and "
                            f"listed {expected_grants[item]} time(s). A second grant here must be listed on purpose.")
        else:
            problems.append(f"{item[0]} ({item[1]}): a grant of `{item[2]}` that is not on KNOWN_GRANTS. A new way of signing "
                            "in must be listed here on purpose, and must call Auth::beginSessionFor() first.")
    for f, s, k, how in KNOWN_GRANTS:
        got = covered.get((f, s, k))
        if got is not None and got != how:
            problems.append(f"{f} ({s}): the grant of `{k}` is listed as '{how}' and is now covered as '{got}'; update the list.")

    expected_calls = Counter(KNOWN_CALL_SITES)
    for item in sorted(expected_calls - found_calls):
        if found_calls[item] > 0:
            problems.append(f"{item[0]} ({item[1]}): the call to Auth::beginSessionFor() is listed {expected_calls[item]} "
                            f"times in KNOWN_CALL_SITES and found {found_calls[item]} time(s).")
        else:
            problems.append(f"{item[0]} ({item[1]}): the listed call to Auth::beginSessionFor() is no longer found.")
    for item in sorted(found_calls - expected_calls):
        if expected_calls[item] > 0:
            problems.append(f"{item[0]} ({item[1]}): Auth::beginSessionFor() is called {found_calls[item]} times in this "
                            f"function and listed {expected_calls[item]} time(s). A second call must be listed on purpose.")
        else:
            problems.append(f"{item[0]} ({item[1]}): a call to Auth::beginSessionFor() that is not on KNOWN_CALL_SITES. "
                            "Every call ends whatever the session held for somebody else, so each is listed on purpose.")

    # B: the delegated grant's chain, by name.
    dfile, dscope, must_call, reaches = DELEGATE
    if "delegated" in covered.values():
        if must_call not in calls_by_scope.get((dfile, dscope), []):
            problems.append(f"{dfile} ({dscope}): a page relies on it to begin the session, and it no longer calls "
                            f"{reaches}() (by any spelling that names Auth), which is where the call to "
                            "Auth::beginSessionFor() is.")
        if covered.get((dfile, reaches, "user_id")) != "begin":
            problems.append(f"{dfile} ({reaches}): a page relies on {dscope}() to begin the session, and {reaches}() no "
                            "longer holds a grant of `user_id` with its own call.")

    # D: keys that cannot be read, both ways.
    expected_dynamic = Counter(VARIABLE_KEY_WRITES_ON_PURPOSE.keys())
    for item in sorted(found_dynamic - expected_dynamic):
        problems.append(f"{item[0]} ({item[1]}): `$_SESSION[{item[2]}] = …` writes through a key this check cannot read "
                        "(a variable, an unknown constant or a concatenation). If it can never be an identity key, name it "
                        "in VARIABLE_KEY_WRITES_ON_PURPOSE with the reason.")
    for item in sorted(expected_dynamic - found_dynamic):
        problems.append(f"{item[0]} ({item[1]}): VARIABLE_KEY_WRITES_ON_PURPOSE names `$_SESSION[{item[2]}]`, which is no "
                        "longer found. Remove the entry.")

    return problems


# =============================================================================
# 🧪 Self-test: against a COPY of the real files, never the repository. Each
#    case is (name, file, swaps, words). `swaps` is a list of (from, to) that
#    must each match exactly once; a file not among COPIED is written new from
#    the single swap's `to`.
# =============================================================================
COPIED = [
    "web/_core/Auth.php",
    "web/_apps/auth/login/webauthn.php",
    "web/_apps/invites/accept.php",
    "web/_apps/auth/2fa/verify.php",
    "web/_apps/auth/login/index.php",
    "web/_apps/recordings/view.php",
]

CALL = "Auth::beginSessionFor($newUserId);"
ACCEPT = "web/_apps/invites/accept.php"
VERIFY = "web/_apps/auth/2fa/verify.php"
VERIFY_PAIR = "    Auth::beginSessionFor($pendingUserId);\n    $_SESSION['user_id']    = $pendingUserId;"
NEW = "web/_apps/p902/new.php"


def new_file(body: str) -> list:
    return [(None, "<?php\n" + body)]


# 🔴 Faults: each must be reported, with a finding containing `words`.
PLANTS = [
    ("the passkey call removed", "web/_apps/auth/login/webauthn.php",
        # The finding is named by WHAT is written, never by its line number:
        # this said "webauthn.php:13" (the write was on line 132) until #903
        # added lines above it and moved it to 141, which turned this case red
        # on correct code. The 2fa write's finding names `2fa_user_id`, so
        # this text still picks out the sign-in write alone.
        [("            Auth::beginSessionFor($webauthnUserId);\n", "")], "`$_SESSION['user_id']` is written"),
    ("the call moved AFTER the write", VERIFY,
        [(VERIFY_PAIR, "    $_SESSION['user_id']    = $pendingUserId;\n    Auth::beginSessionFor($pendingUserId);")], "verify.php:"),
    ("the call left only in a comment", ACCEPT, [(CALL, "// " + CALL)], "accept.php:"),
    ("the call left only in a string", ACCEPT, [(CALL, "$unused = '" + CALL + "';")], "accept.php:"),
    ("the call made inside a closure defined first", VERIFY,
        [("    Auth::beginSessionFor($pendingUserId);\n",
          "    $p902 = static function () use ($pendingUserId): void { Auth::beginSessionFor($pendingUserId); };\n")], "verify.php:"),
    ("the Microsoft callback's call removed (both of its writes go)", "web/_core/Auth.php",
        [("on purpose (#731).\n        self::beginSessionFor($userId);", "on purpose (#731).\n        session_regenerate_id(true);")],
        # Named by what is written, never by a line number: the number moved
        # when Auth.php grew (#620), and this self-test — a hard gate in
        # pr-security.yml — went red on correct code (found by #598's check).
        "(Auth::callbackMS365): `$_SESSION['user_id']` is written"),
    ("a loginLocal call standing in for the call, on a grant not listed as delegated", VERIFY,
        [("    Auth::beginSessionFor($pendingUserId);\n", "    Auth::loginLocal('p902', 'p902');\n")], "with no call"),
    ("a method of the same name on another class", ACCEPT, [(CALL, "Other::beginSessionFor($newUserId);")], "accept.php:"),
    ("another class imported AS Auth", ACCEPT,
        [("use Portal\\Core\\Auth;", "use Portal\\Core\\Other as Auth;")], "accept.php:"),
    ("the delegated page's loginLocal call gone", "web/_apps/auth/login/index.php",
        [("if (Auth::loginLocal($username, $password) === true) {", "if (Auth::loginLocalElsewhere($username, $password) === true) {")],
        "index.php:"),
    ("the delegated chain broken", "web/_core/Auth.php",
        [("return self::loginLocalOneAtATime($mysqli, $identifier, $password, $ip);",
          "return self::loginLocalSomethingElse($mysqli, $identifier, $password, $ip);")], "no longer calls"),
    ("a seventh way of signing in, with its call, but not listed", NEW,
        new_file("use Portal\\Core\\Auth;\nAuth::beginSessionFor($id);\n$_SESSION['user_id'] = $id;\n"), "not on KNOWN_GRANTS"),
    ("a seventh call site, not listed", NEW,
        new_file("use Portal\\Core\\Auth;\nAuth::beginSessionFor($id);\n"), "not on KNOWN_CALL_SITES"),
    ("a new grant in double quotes with no call", NEW, new_file("$_SESSION[\"user_id\"] = 7;\n"), "with no call"),
    ("a new grant through $GLOBALS", NEW, new_file("$GLOBALS['_SESSION']['2fa_user_id'] = 7;\n"), "2fa_user_id"),
    ("a new grant as a whole-session array", NEW, new_file("$_SESSION = ['user_id' => 7];\n"), "whole session"),
    ("a new grant by ??=", NEW, new_file("$_SESSION['user_id'] ??= 7;\n"), "with no call"),
    ("a new grant as a foreach target", NEW, new_file("foreach ([7] as $_SESSION['user_id']) {\n}\n"), "foreach target"),
    ("a new grant as a foreach target by reference", NEW, new_file("foreach ($a as &$_SESSION['user_id']) {\n}\n"), "foreach target"),
    ("a new grant as a foreach target after a key", NEW, new_file("foreach ($a as $k => $_SESSION['2fa_user_id']) {\n}\n"),
        "foreach target"),
    ("a new grant as a whole-session array(...)", NEW, new_file("$_SESSION += array('2fa_user_id' => $id);\n"), "whole session"),
    ("another class imported AS Auth in a grouped import", ACCEPT,
        [("use Portal\\Core\\Auth;", "use Portal\\Core\\{Other as Auth};")], "accept.php:"),
    ("a grant written twice in one function, listed once", VERIFY,
        [(VERIFY_PAIR, VERIFY_PAIR + "\n    $_SESSION['user_id']    = $pendingUserId;")], "written 2 times"),
    ("a new grant inside an arrow function", NEW, new_file("$f = fn () => $_SESSION['user_id'] = 7;\n"), "with no call"),
    ("a key built by concatenation", NEW, new_file("$_SESSION['user' . '_id'] = 7;\n"), "cannot read"),
    ("a key in a variable", NEW, new_file("$_SESSION[$k] = 1;\n"), "cannot read"),
    ("a constant naming the identity key", NEW, new_file("const P902_KEY = 'user_id';\n$_SESSION[P902_KEY] = 7;\n"),
        "written through a constant"),
    ("a constant nobody defined", NEW, new_file("$_SESSION[P902_UNDEFINED] = 7;\n"), "cannot read"),
    ("the listed variable-key write gone", "web/_apps/recordings/view.php",
        [("$_SESSION[$playKey] = 1;", "$_SESSION['p902'] = 1;")], "no longer found"),
    ("a file PHP will not parse", NEW, new_file("if (\n"), "cannot be judged"),
]

# 🟢 Correct code: must NOT be reported. Crying wolf gets a check switched off (#483).
GREEN = [
    ("the method name in capitals (PHP ignores case)", ACCEPT, [(CALL, "AUTH::BEGINSESSIONFOR($newUserId);")]),
    ("the fully qualified class name", ACCEPT, [(CALL, "\\Portal\\Core\\Auth::beginSessionFor($newUserId);")]),
    ("Auth imported under another name", ACCEPT,
        [("use Portal\\Core\\Auth;", "use Portal\\Core\\Auth;\nuse Portal\\Core\\Auth as PortalAuth;"),
         (CALL, "PortalAuth::beginSessionFor($newUserId);")]),
    ("a named argument", ACCEPT, [(CALL, "Auth::beginSessionFor(userId: $newUserId);")]),
    ("a cast on the argument only", ACCEPT, [(CALL, "Auth::beginSessionFor((int) $newUserId);")]),
    ("the call inside do { } while (false)", ACCEPT, [(CALL, "do { " + CALL + " } while (false);")]),
    ("a label before the call", ACCEPT, [(CALL, "p902label:\n" + CALL)]),
    ("a {$x} string between the call and the write", ACCEPT, [(CALL, CALL + "\n$p902 = \"for {$newUserId} }\";")]),
    # Inside a FUNCTION, where it matters: if the `}` of `{$x}` were read as a
    # block's end, the method would end early and its later write would be
    # judged outside it. (At file level a stray `}` changes no function.)
    ("a {$x} string inside a listed function, before its second write", "web/_core/Auth.php",
        [("on purpose (#731).\n        self::beginSessionFor($userId);",
          "on purpose (#731).\n        self::beginSessionFor($userId);\n        $p902 = \"for {$userId}\";")]),
    ("a nowdoc holding braces and a fake write", ACCEPT,
        [(CALL, CALL + "\n$p902 = <<<'TXT'\n} } $_SESSION['user_id'] = 1;\nTXT;\n")]),
    ("an attribute on a closure between them", ACCEPT, [(CALL, CALL + "\n$p902 = #[\\Deprecated] static function (): void {};")]),
    ("an attribute on a parameter of a listed function", "web/_core/Auth.php",
        [("private static function loginLocalOneAtATime(\\mysqli $mysqli, string $identifier, string $password, string $ip): bool",
          "private static function loginLocalOneAtATime(\\mysqli $mysqli, string $identifier, #[\\SensitiveParameter] string $password, string $ip): bool")]),
    ("the call and the write together in one inner block", VERIFY,
        [(VERIFY_PAIR, "    if ($pendingUserId > 0) {\n" + VERIFY_PAIR + "\n    }")]),
    ("a sign-out through a chain of = ending in null", NEW,
        new_file("$_SESSION['user_id'] = $_SESSION['2fa_user_id'] = null;\n")),
    ("a sign-out by array_diff_key on the whole session", NEW,
        new_file("$_SESSION = array_diff_key($_SESSION, ['user_id' => true, '2fa_user_id' => true]);\n")),
    ("a whole-session literal that sets the keys to null or false", NEW,
        new_file("$_SESSION = ['user_id' => null, '2fa_user_id' => false, 'x' => ['user_id' => 7]];\n")),
    ("the chain written with static::", "web/_core/Auth.php", [("return self::loginLocalOneAtATime($mysqli, $identifier, $password, $ip);", "return static::loginLocalOneAtATime($mysqli, $identifier, $password, $ip);")]),
    ("the chain written with Auth::", "web/_core/Auth.php", [("return self::loginLocalOneAtATime($mysqli, $identifier, $password, $ip);", "return Auth::loginLocalOneAtATime($mysqli, $identifier, $password, $ip);")]),
    ("the chain's method name in other letters", "web/_core/Auth.php",
        [("return self::loginLocalOneAtATime($mysqli, $identifier, $password, $ip);", "return self::LOGINLOCALONEATATIME($mysqli, $identifier, $password, $ip);")]),
    ("Auth in a grouped import under another name", ACCEPT,
        [("use Portal\\Core\\Auth;", "use Portal\\Core\\{App as P902App, Auth as PA};"), (CALL, "PA::beginSessionFor($newUserId);")]),
    ("a sign-out: = null and unset", NEW,
        new_file("$_SESSION['user_id'] = null;\n$_SESSION['2fa_user_id'] = NULL;\nunset($_SESSION['user_id'], $_SESSION['2fa_user_id']);\n")),
    ("quotations, comparisons, reads and an interpolation", NEW,
        new_file("// $_SESSION['user_id'] = 1;\n$a = \"{$_SESSION['user_id']} = 1\";\n$b = $_SESSION['user_id'] === 5;\n"
                 "$c = $_SESSION['user_id'] ?? 0;\n$d = 'Auth::beginSessionFor(1); $_SESSION[\"user_id\"] = 1;';\n")),
    ("a class constant holding an ordinary key", NEW,
        new_file("class P902Thing\n{\n    public const KEEP = 'p902_kept', OTHER = 'x';\n    public static function put(): void\n"
                 "    {\n        $_SESSION[self::KEEP] = 1;\n        $_SESSION[P902Thing::OTHER] = 2;\n    }\n}\n")),
]

# ⚪ MEASURED BLIND SPOTS: real faults this check cannot see, declared in the
#    header. It must stay GREEN over each; the day one turns red, the check has
#    learned something and the case belongs in PLANTS. Part K of
#    tools/two-step-selftest.php is what catches each of these.
BLIND = [
    ("the call inside if (false) { }", ACCEPT, [(CALL, "if (false) { " + CALL + " }")]),
    ("the call behind false &&", ACCEPT, [(CALL, "false && " + CALL)]),
    ("the call in a try that swallows its failure", ACCEPT, [(CALL, "try { " + CALL + " } catch (\\Throwable $p902) { }")]),
    ("a while (true) {} above the call", ACCEPT, [(CALL, "while (true) { }\n" + CALL)]),
    ("the session begun for somebody else", ACCEPT, [(CALL, "Auth::beginSessionFor((int) $invite['invitationID']);")]),
    ("the value reassigned between the call and the write", ACCEPT, [(CALL, CALL + "\n$newUserId = 1;")]),
    ("the whole session put back between the call and the write", ACCEPT,
        [(CALL, "$p902keep = $_SESSION;\n" + CALL + "\n$_SESSION = $p902keep;\n$_SESSION += $p902keep;")]),
    ("the call in one switch case and the write in another", VERIFY,
        [(VERIFY_PAIR, "    switch ($pendingUserId) {\n        case 1:\n    Auth::beginSessionFor($pendingUserId);\n"
                       "            break;\n        default:\n    $_SESSION['user_id']    = $pendingUserId;\n    }")]),
    ("the delegated grant's value never compared", "web/_apps/auth/login/index.php",
        [("$_SESSION['2fa_user_id'] = $loggedInUserId;", "$_SESSION['2fa_user_id'] = 12345;")]),
    ("a grant by destructuring", NEW, new_file("[$_SESSION['user_id']] = [7];\n")),
    ("a whole-session grant through array_merge", NEW,
        new_file("$_SESSION = array_merge($_SESSION, ['user_id' => $id]);\n")),
]

# Pinned, so a deleted case is a failure rather than a smaller green number (#697).
EXPECTED_PLANTS = 30
EXPECTED_GREEN = 23
EXPECTED_BLIND = 11


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

    report((len(PLANTS), len(GREEN), len(BLIND)) == (EXPECTED_PLANTS, EXPECTED_GREEN, EXPECTED_BLIND),
           f"the self-test holds {EXPECTED_PLANTS} plants, {EXPECTED_GREEN} green cases and {EXPECTED_BLIND} blind spots",
           f"it holds {len(PLANTS)}, {len(GREEN)} and {len(BLIND)}; change the pinned numbers only on purpose")

    with tempfile.TemporaryDirectory(prefix="p902-sign-in-check-") as tmp:
        originals: dict[str, str] = {}
        for rel in COPIED:
            with open(os.path.join(repo, rel), encoding="utf-8") as fh:
                originals[rel] = fh.read()
            os.makedirs(os.path.dirname(os.path.join(tmp, rel)), exist_ok=True)
            with open(os.path.join(tmp, rel), "w", encoding="utf-8") as fh:
                fh.write(originals[rel])

        def apply(rel: str, swaps: list) -> bool:
            path = os.path.join(tmp, rel)
            if rel not in originals:
                os.makedirs(os.path.dirname(path), exist_ok=True)
                with open(path, "w", encoding="utf-8") as fh:
                    fh.write(swaps[0][1])
                return True
            body = originals[rel]
            for old, new in swaps:
                if body.count(old) != 1:
                    return False
                body = body.replace(old, new)
            with open(path, "w", encoding="utf-8") as fh:
                fh.write(body)
            return True

        def restore(rel: str) -> None:
            path = os.path.join(tmp, rel)
            if rel not in originals:
                if os.path.exists(path):
                    os.remove(path)
                return
            with open(path, "w", encoding="utf-8") as fh:
                fh.write(originals[rel])

        base = analyse(tmp)
        report(base == [], "the unplanted copy of the six sign-in files and the password page is clean", " | ".join(base))

        for name, rel, swaps, words in PLANTS:
            if apply(rel, swaps) is False:
                report(False, "PLANT " + name, "a swap did not match exactly one place, so it was not applied")
                restore(rel)
                continue
            got = analyse(tmp)
            restore(rel)
            report(any(words in p for p in got), "PLANT " + name + " is reported",
                   "expected a finding containing '" + words + "'; got: " + (" | ".join(got) or "(nothing)"))

        for label, cases in (("GREEN", GREEN), ("BLIND SPOT", BLIND)):
            for name, rel, swaps in cases:
                if apply(rel, swaps) is False:
                    report(False, label + " " + name, "a swap did not match exactly one place, so it was not applied")
                    restore(rel)
                    continue
                got = analyse(tmp)
                restore(rel)
                report(got == [], label + " " + name + " is not reported" + (" (a known hole: part K catches it)" if label != "GREEN" else ""),
                       " | ".join(got))

        again = analyse(tmp)
        report(again == [], "the copy is clean again after every case (each was put back)", " | ".join(again))

    expected = 3 + EXPECTED_PLANTS + EXPECTED_GREEN + EXPECTED_BLIND
    print(f"\n{passed} passed, {failed} failed, {passed + failed} ran of {expected} expected "
          f"({EXPECTED_PLANTS} plants, {EXPECTED_GREEN} must-stay-green cases, {EXPECTED_BLIND} measured blind spots).")
    if failed > 0 or passed + failed != expected:
        print("FAIL — the self-test of check_sign_in_begins_session.py did not pass.")
        return 1
    print("PASS — every plant is reported, and nothing correct (nor any declared blind spot) is.")
    return 0


def main() -> int:
    if "--self-test" in sys.argv[1:]:
        return self_test()
    problems = analyse(os.path.dirname(os.path.dirname(HERE)))
    if problems and problems[0].startswith(CANNOT_RUN):
        print(problems[0])
        return 1
    if problems:
        print("FAIL: a sign-in does not begin its session through Auth::beginSessionFor() (#900, #902):\n")
        for p in problems:
            print("  - " + p)
        print("\nWhy it matters: without that call, whatever the previous person left in this browser's session is "
              "handed to whoever signs in next. This check sees only that the call is there and comes first; part K "
              "of tools/two-step-selftest.php drives every sign-in to see that it runs.")
        return 1
    print(f"OK: all {len(KNOWN_GRANTS)} grants of a signed-in identity under web/ have Auth::beginSessionFor() before them "
          f"in the same function ({len(KNOWN_CALL_SITES)} call sites; one grant delegated through Auth::loginLocal()). "
          "Presence and order only; part K of tools/two-step-selftest.php drives whether each runs.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
