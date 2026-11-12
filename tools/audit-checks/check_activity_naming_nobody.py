#!/usr/bin/env python3
"""
An activity record that names nobody is written ONLY where a page promises
anonymity (#988).

Path: tools/audit-checks/check_activity_naming_nobody.py

    python3 tools/audit-checks/check_activity_naming_nobody.py              check web/
    python3 tools/audit-checks/check_activity_naming_nobody.py <web dir>    check another copy
    python3 tools/audit-checks/check_activity_naming_nobody.py --self-test  plant faults; prove them caught

WHY THIS EXISTS
---------------
`Logger::activity()` fills a record's account number from the session when the
caller names nobody (#626), so that an erasure reaches every record of somebody
signed in. #988 found the one place that is wrong: the anonymous prayer form,
where a member who sent a request while signed in got a record tying the
"anonymous" request to their account. `Logger::activityNamingNobody()` is the
deliberate way out - it leaves the account, the session's fingerprint and facts,
the network address, the browser name and the headers all empty.

That makes it a way to write a record nobody can be held to, so it is used only
where a page PROMISES the person anonymity. This check names every file that
calls it and how many times, and fails BOTH WAYS: a call in a file not on the
list (somebody reaching for it to keep an action off the record), and a listed
file whose count has changed (a promise quietly dropped, or a new record added
without a decision). It also pins the method's own statement, so the method
cannot start keeping what it promises to leave out.

Since #1017 one caller is not a page: `Captcha::recordV3Refusal()`, which
records a refused reCAPTCHA v3 answer and must name nobody when the form being
checked promises anonymity. Which forms do is ONE list in Captcha.php,
`PROMISES_ANONYMITY`, and this check pins it WHOLE (as `php -w` prints it), so
a form cannot be added to it - and its refusals taken off the record - without
this check changing too; and it requires the anonymous form to name itself by
the constant that list holds, so the form and the list cannot drift apart.

And since round 2 of #1017's check, nothing ELSE may name itself as that form:
the constant `FOR_ANONYMOUS_PRAYER_REQUEST` and its value
('anonymous-prayer-request', a word used for nothing else) are COUNTED in every
PHP file under web/ (USES below), failing both ways. Without that, any form
could pass the constant, or its value as a literal, to `Captcha::verify()` and
have its refused bot checks recorded naming nobody, while the list still read
"today only the anonymous prayer form".

WHAT IT CANNOT SEE - this is a PRESENCE check (D-065)
-----------------------------------------------------
- Whether a listed call is really on the anonymous path: that is behaviour,
  and `tools/erasure-pages-selftest.php` (section N, #988) drives the form
  over HTTP as a signed-in member and reads the records back.
- A call written through a variable class name or a wrapper.
- Whether the words passed name somebody: `check_activity_descriptions_personal.py`
  reads this method's calls by the same rules as `activity()`'s.

Comments are left out by PHP's own `php -w` (strip comments and white space),
never a regular expression, so a comment mentioning the method is not a call.

Exit: 0 when right; 1 when anything is wrong or the check could not run.
"""

from __future__ import annotations

import os
import re
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))

METHOD = "activityNamingNobody"

# File (relative to web/) -> (number of calls, why anonymity is promised there).
ON_PURPOSE = {
    "_apps/prayer-requests/anonymous-save.php": (
        4,
        "the anonymous prayer form: the request it saves, and the three refusals (form token, captcha, too many) (#988)",
    ),
    "_core/PrayerChain.php": (
        1,
        "the automatic assignment of a request sent through the anonymous form, which runs inside the sender's request (#988)",
    ),
    "_core/Captcha.php": (
        1,
        "the bot check's record of a refused reCAPTCHA v3 answer on a form in Captcha::PROMISES_ANONYMITY - today only the "
        "anonymous prayer form (#1017)",
    ),
}

# Statements pinned whole, as `php -w` prints them, each exactly ONCE in its
# file. File (relative to web/) -> list of (statement, why).
PINNED_STATEMENTS = {
    "_core/Captcha.php": [
        ("private const PROMISES_ANONYMITY = [self::FOR_ANONYMOUS_PRAYER_REQUEST];",
         "the ONE list of forms whose bot-check refusals name nobody (#1017): a form added to it has its refusals "
         "taken off the record, which is a decision, so this pin changes with it"),
    ],
    "_apps/prayer-requests/anonymous-save.php": [
        ("Captcha::verify($_POST, Captcha::FOR_ANONYMOUS_PRAYER_REQUEST)",
         "the anonymous form names itself to the bot check by the constant Captcha::PROMISES_ANONYMITY holds, so a "
         "refused reCAPTCHA v3 answer on it names nobody either (#1017)"),
    ],
}

# Every use of the anonymous form's name for the bot check, file by file (as
# `php -w` prints the file). The constant twice in Captcha.php (its definition
# and PROMISES_ANONYMITY) and once in the form; the value once, in its
# definition. Any other file, or any other count, fails.
USES = {
    "FOR_ANONYMOUS_PRAYER_REQUEST": {"_core/Captcha.php": 2, "_apps/prayer-requests/anonymous-save.php": 1},
    "'anonymous-prayer-request'": {"_core/Captcha.php": 1},
    '"anonymous-prayer-request"': {},
}

LOGGER = "_core/Logger.php"
# The method's own statement: the account and the five browser columns are
# written as literal NULLs, never bound from anything.
PINNED_INSERT = "'VALUES (?, NULL, ?, ?, NULL, NULL, NULL, NULL, NULL)'"
DEFINITION = "public static function activityNamingNobody(string $type, string $description = ''): void"

CALL = re.compile(r"Logger::" + METHOD + r"\s*\(")


class CouldNotRun(Exception):
    pass


def stripped(path: str) -> str:
    """The file as PHP sees it with comments removed (`php -w`)."""
    try:
        out = subprocess.run(["php", "-w", path], capture_output=True, text=True, check=False)
    except FileNotFoundError as exc:
        raise CouldNotRun("php is not installed or not on the PATH") from exc
    if out.returncode != 0:
        raise CouldNotRun(f"php -w could not read {path}: {out.stderr.strip()}")
    return out.stdout


def method_body(text: str) -> str:
    start = text.find(DEFINITION)
    if start < 0:
        return ""
    end = text.find("public static function", start + len(DEFINITION))
    return text[start:] if end < 0 else text[start:end]


def problems(web: str) -> list[str]:
    found: dict[str, int] = {}
    used: dict[str, dict[str, int]] = {needle: {} for needle in USES}
    for root, _dirs, files in os.walk(web):
        for name in files:
            if name.endswith(".php") is False:
                continue
            path = os.path.join(root, name)
            with open(path, encoding="utf-8", errors="replace") as fh:
                raw = fh.read()
            wanted = [needle for needle in USES if needle.strip("'\"") in raw]
            if METHOD not in raw and wanted == []:
                continue  # the cheap test first: php -w only on files that mention one of them
            text = stripped(path)
            rel = os.path.relpath(path, web).replace(os.sep, "/")
            count = len(CALL.findall(text))
            if count > 0:
                found[rel] = count
            for needle in wanted:
                seen = text.count(needle)
                if seen > 0:
                    used[needle][rel] = seen

    wrong: list[str] = []
    for rel, count in sorted(found.items()):
        if rel not in ON_PURPOSE:
            wrong.append(
                f"{rel} calls Logger::{METHOD}() {count} time(s), and is not on ON_PURPOSE. A record that names nobody "
                "is only for a page that promises the person anonymity; if this one does, add it with the reason."
            )
    for rel, (expected, _why) in sorted(ON_PURPOSE.items()):
        got = found.get(rel, 0)
        if got != expected:
            wrong.append(
                f"{rel} calls Logger::{METHOD}() {got} time(s); ON_PURPOSE says {expected}. Either a record of an "
                "anonymous action now names somebody again, or a new one was added without a decision: change "
                "the list only on purpose."
            )

    for needle, expected in USES.items():
        for rel in sorted(set(expected) | set(used[needle])):
            want = expected.get(rel, 0)
            got = used[needle].get(rel, 0)
            if got != want:
                wrong.append(
                    f"{rel} uses {needle} {got} time(s); USES says {want}. Only the anonymous prayer form may name itself "
                    "to the bot check as that form: anything else passing it has its refused bot checks recorded naming "
                    "nobody (#1017). Change the list only on purpose."
                )

    for rel, pins in sorted(PINNED_STATEMENTS.items()):
        path = os.path.join(web, rel)
        if os.path.isfile(path) is False:
            wrong.append(f"{rel} is missing, and holds a statement this check pins")
            continue
        text = stripped(path)
        for statement, why in pins:
            seen = text.count(statement)
            if seen != 1:
                wrong.append(f"{rel} holds `{statement}` {seen} time(s), not exactly once: {why}")

    logger = os.path.join(web, LOGGER)
    if os.path.isfile(logger) is False:
        wrong.append(f"{LOGGER} is missing")
    else:
        with open(logger, encoding="utf-8") as fh:
            body = method_body(fh.read())
        if body == "":
            wrong.append(f"Logger::{METHOD}() is not defined as: {DEFINITION}")
        elif body.count(PINNED_INSERT) != 1 or "insertRow(" not in body or "'iss'" not in body:
            wrong.append(
                f"Logger::{METHOD}() no longer writes the account and the five browser columns as literal NULLs "
                f"({PINNED_INSERT}, bound 'iss'): it must keep nothing that says who sent the record"
            )
    return wrong


# =============================================================================
# 🧪 The self-test
# =============================================================================
def self_test() -> int:
    passed = failed = 0

    def ok(label: str, good: bool, detail: str = "") -> None:
        nonlocal passed, failed
        if good:
            passed += 1
            print(f"PASS — {label}")
        else:
            failed += 1
            print(f"FAIL — {label}" + (f": {detail}" if detail else ""))

    real = os.path.join(REPO, "web")
    base = problems(real)
    ok("the real web/ is clean", base == [], "; ".join(base))

    def with_copy(edit) -> list[str]:
        tmp = tempfile.mkdtemp(prefix="p988-nobody-")
        try:
            web = os.path.join(tmp, "web")
            for rel in sorted(set(list(ON_PURPOSE) + list(PINNED_STATEMENTS) + [LOGGER])):
                dst = os.path.join(web, rel)
                os.makedirs(os.path.dirname(dst), exist_ok=True)
                shutil.copyfile(os.path.join(real, rel), dst)
            edit(web)
            return problems(web)
        finally:
            shutil.rmtree(tmp, ignore_errors=True)

    def write(web: str, rel: str, text: str) -> None:
        dst = os.path.join(web, rel)
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        with open(dst, "w", encoding="utf-8") as fh:
            fh.write(text)

    def replace(web: str, rel: str, old: str, new: str) -> bool:
        path = os.path.join(web, rel)
        with open(path, encoding="utf-8") as fh:
            text = fh.read()
        if text.count(old) != 1:
            return False
        with open(path, "w", encoding="utf-8") as fh:
            fh.write(text.replace(old, new))
        return True

    # 1. A new caller outside the list.
    got = with_copy(lambda w: write(w, "_apps/admin/zzplant.php",
                                    "<?php\nuse Portal\\Core\\Logger;\nLogger::activityNamingNobody('X', 'Deleted a member');\n"))
    ok("a call in a file not on the list is reported", any("zzplant.php" in g for g in got), "; ".join(got))

    # 2. A comment mentioning it is NOT a call.
    got = with_copy(lambda w: write(w, "_apps/admin/zzcomment.php",
                                    "<?php\n// Logger::activityNamingNobody('X', 'only a note');\n/* Logger::activityNamingNobody('Y') */\n"))
    ok("a comment that mentions the method is not reported", got == [], "; ".join(got))

    # 3. The anonymous form goes back to naming the session's account.
    applied = {"v": False}

    def back_to_activity(w: str) -> None:
        applied["v"] = replace(
            w, "_apps/prayer-requests/anonymous-save.php",
            "Logger::activityNamingNobody('PrayerRequestAnonSubmitted',",
            "Logger::activity('PrayerRequestAnonSubmitted',",
        )
    got = with_copy(back_to_activity)
    ok("the anonymous form's saved-request record going back to Logger::activity() is reported",
       applied["v"] is True and any("anonymous-save.php" in g for g in got), "; ".join(got))

    # 4. The method starts keeping the network address.
    applied2 = {"v": False}

    def keeps_address(w: str) -> None:
        applied2["v"] = replace(
            w, LOGGER,
            "'VALUES (?, NULL, ?, ?, NULL, NULL, NULL, NULL, NULL)'",
            "'VALUES (?, NULL, ?, ?, NULL, NULL, ?, NULL, NULL)'",
        )
    got = with_copy(keeps_address)
    ok("the method keeping the network address is reported",
       applied2["v"] is True and any("literal NULLs" in g for g in got), "; ".join(got))

    # 5. #1017: the bot check's refusal goes back to naming the session's account.
    applied5 = {"v": False}

    def captcha_back_to_activity(w: str) -> None:
        applied5["v"] = replace(
            w, "_core/Captcha.php",
            "Logger::activityNamingNobody('CaptchaRejected',",
            "Logger::activity('CaptchaRejected',",
        )
    got = with_copy(captcha_back_to_activity)
    ok("the bot check's refusal on an anonymous form going back to Logger::activity() is reported (#1017)",
       applied5["v"] is True and any("_core/Captcha.php calls" in g for g in got), "; ".join(got))

    # 6. #1017: a second form added to the list of forms promising anonymity.
    applied6 = {"v": False}

    def list_widened(w: str) -> None:
        applied6["v"] = replace(
            w, "_core/Captcha.php",
            "private const PROMISES_ANONYMITY = [self::FOR_ANONYMOUS_PRAYER_REQUEST];",
            "private const PROMISES_ANONYMITY = [self::FOR_ANONYMOUS_PRAYER_REQUEST, self::FOR_SIGN_IN];",
        )
    got = with_copy(list_widened)
    ok("a form added to Captcha::PROMISES_ANONYMITY without this check changing is reported (#1017)",
       applied6["v"] is True and any("PROMISES_ANONYMITY" in g for g in got), "; ".join(got))

    # 7. #1017: the anonymous form no longer names itself by the constant.
    applied7 = {"v": False}

    def form_by_literal(w: str) -> None:
        applied7["v"] = replace(
            w, "_apps/prayer-requests/anonymous-save.php",
            "Captcha::verify($_POST, Captcha::FOR_ANONYMOUS_PRAYER_REQUEST)",
            "Captcha::verify($_POST, 'anonymous-prayer')",
        )
    got = with_copy(form_by_literal)
    ok("the anonymous form naming itself to the bot check by anything but the constant is reported (#1017)",
       applied7["v"] is True and any("FOR_ANONYMOUS_PRAYER_REQUEST" in g for g in got), "; ".join(got))

    # 8. A comment quoting the pinned list does not count as the statement.
    applied8 = {"v": False}

    def list_only_in_comment(w: str) -> None:
        applied8["v"] = replace(
            w, "_core/Captcha.php",
            "private const PROMISES_ANONYMITY = [self::FOR_ANONYMOUS_PRAYER_REQUEST];",
            "// private const PROMISES_ANONYMITY = [self::FOR_ANONYMOUS_PRAYER_REQUEST];\n"
            "    private const PROMISES_ANONYMITY = [];",
        )
    got = with_copy(list_only_in_comment)
    ok("the list emptied, with the old statement left in a comment, is reported (a comment is not the statement)",
       applied8["v"] is True and any("PROMISES_ANONYMITY" in g for g in got), "; ".join(got))

    # 9. #1017 round 2: another form names itself as the anonymous form, by the constant.
    got = with_copy(lambda w: write(w, "_apps/zzplant/save.php",
                                    "<?php\nuse Portal\\Core\\Captcha;\nCaptcha::verify($_POST, Captcha::FOR_ANONYMOUS_PRAYER_REQUEST);\n"))
    ok("another form passing Captcha::FOR_ANONYMOUS_PRAYER_REQUEST is reported (#1017)",
       any("zzplant/save.php uses FOR_ANONYMOUS_PRAYER_REQUEST" in g for g in got), "; ".join(got))

    # 10. ... and by the literal value.
    got = with_copy(lambda w: write(w, "_apps/zzplant/save.php",
                                    "<?php\n\\Portal\\Core\\Captcha::verify($_POST, 'anonymous-prayer-request');\n"))
    ok("another form passing the value 'anonymous-prayer-request' as a literal is reported (#1017)",
       any("zzplant/save.php uses 'anonymous-prayer-request'" in g for g in got), "; ".join(got))

    # 11. A comment naming the constant is not a use.
    got = with_copy(lambda w: write(w, "_apps/zzplant/note.php",
                                    "<?php\n// Captcha::FOR_ANONYMOUS_PRAYER_REQUEST and 'anonymous-prayer-request' are the form's.\n"))
    ok("a comment naming the constant or its value is not reported", got == [], "; ".join(got))

    print(f"\n{passed} passed, {failed} failed.")
    return 0 if failed == 0 else 1


def main() -> int:
    if len(sys.argv) > 1 and sys.argv[1] == "--self-test":
        try:
            return self_test()
        except CouldNotRun as exc:
            print(f"COULD NOT RUN: {exc}")
            return 1
    web = sys.argv[1] if len(sys.argv) > 1 else os.path.join(REPO, "web")
    try:
        wrong = problems(web)
    except CouldNotRun as exc:
        print(f"COULD NOT RUN: {exc}")
        return 1
    if wrong:
        for line in wrong:
            print(f"FAIL: {line}")
        return 1
    total = sum(n for n, _ in ON_PURPOSE.values())
    print(f"OK: Logger::{METHOD}() is called {total} time(s), only in the {len(ON_PURPOSE)} file(s) that promise "
          "anonymity, and still writes the account and the browser columns as empty; the bot check's list of forms "
          "promising anonymity is as pinned. (A presence check: see this file's header for what it cannot see.)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
