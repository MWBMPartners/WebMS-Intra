#!/usr/bin/env python3
"""
An activity record that names nobody is written ONLY for anonymity (#988), or
for an account that is not in the database (#922).

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
where a page PROMISES the person anonymity (#988), or where the account the
session names is not in the database at all, so a record naming it would be
refused (#922: a whole restore run by an administrator who is not in the
snapshot). This check names every file that
calls it and how many times, and fails BOTH WAYS: a call in a file not on the
list (somebody reaching for it to keep an action off the record), and a listed
file whose count has changed (a promise quietly dropped, or a new record added
without a decision). It also pins the method's own statement, so the method
cannot start keeping what it promises to leave out.

WHAT IT CANNOT SEE - this is a PRESENCE check (D-065)
-----------------------------------------------------
- Whether a listed call is really on the path it is listed for: that is
  behaviour. `tools/erasure-pages-selftest.php` (section N, #988) drives the
  anonymous form over HTTP as a signed-in member and reads the records back;
  `tools/backup-page-selftest.php` checks Q16 (a restorer who is NOT in the
  restored database: the line names nobody) and Q23 (a restorer who IS there,
  switched off: the line names her) drive the backup page's one call (#922).
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

# File (relative to web/) -> (number of calls, and why: anonymity promised there, or an account not in the database).
ON_PURPOSE = {
    "_apps/prayer-requests/anonymous-save.php": (
        4,
        "the anonymous prayer form: the request it saves, and the three refusals (form token, captcha, too many) (#988)",
    ),
    "_core/PrayerChain.php": (
        1,
        "the automatic assignment of a request sent through the anonymous form, which runs inside the sender's request (#988)",
    ),
    "_apps/admin/maintenance/backup.php": (
        1,
        "a whole restore run by an administrator whose account is not in the restored database: naming it would be "
        "refused by the account link, so the line names nobody and says in its words which account ran it (#922)",
    ),
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
    for root, _dirs, files in os.walk(web):
        for name in files:
            if name.endswith(".php") is False:
                continue
            path = os.path.join(root, name)
            with open(path, encoding="utf-8", errors="replace") as fh:
                if METHOD not in fh.read():
                    continue  # the cheap test first: php -w only on files that mention it
            count = len(CALL.findall(stripped(path)))
            if count > 0:
                found[os.path.relpath(path, web).replace(os.sep, "/")] = count

    wrong: list[str] = []
    for rel, count in sorted(found.items()):
        if rel not in ON_PURPOSE:
            wrong.append(
                f"{rel} calls Logger::{METHOD}() {count} time(s), and is not on ON_PURPOSE. A record that names nobody "
                "is only for anonymity (#988) or an account that is not in the database (#922); if this is one of "
                "those, add it with the reason."
            )
    for rel, (expected, _why) in sorted(ON_PURPOSE.items()):
        got = found.get(rel, 0)
        if got != expected:
            wrong.append(
                f"{rel} calls Logger::{METHOD}() {got} time(s); ON_PURPOSE says {expected}. Either a record that "
                "should name nobody (anonymity, #988, or an account not in the database, #922) now names somebody "
                "again, or a new one was added without a decision: change the list only on purpose."
            )

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
            for rel in list(ON_PURPOSE) + [LOGGER]:
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

    # 5. The backup page's restore line goes back to Logger::activity(): the
    #    line for a restorer who is not in the database would be refused again.
    applied3 = {"v": False}

    def backup_back(w: str) -> None:
        applied3["v"] = replace(
            w, "_apps/admin/maintenance/backup.php",
            "Logger::activityNamingNobody($type, $what);",
            "Logger::activity($type, $what);",
        )
    got = with_copy(backup_back)
    ok("the backup page's restore line going back to Logger::activity() is reported",
       applied3["v"] is True and any("maintenance/backup.php" in g for g in got), "; ".join(got))

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
    print(f"OK: Logger::{METHOD}() is called {total} time(s), only in the {len(ON_PURPOSE)} file(s) listed for "
          "anonymity (#988) or an account not in the database (#922), and still writes the account and the browser columns as empty. (A presence check: see this "
          "file's header for what it cannot see.)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
