#!/usr/bin/env python3
"""
An activity record's WORDS are not built from a person's details (#933).

Path: tools/audit-checks/check_activity_descriptions_personal.py

    python3 tools/audit-checks/check_activity_descriptions_personal.py             check web/
    python3 tools/audit-checks/check_activity_descriptions_personal.py <web dir>   check another copy
    python3 tools/audit-checks/check_activity_descriptions_personal.py --self-test plant faults; prove them caught

WHY THIS EXISTS
---------------
An erasure empties an activity record's account number, network address,
browser name and session copy for the erased person's rows, but it keeps the
record's DESCRIPTION exactly as written. That is deliberate (#626): a record
names its person in `userID`, never in its words, so emptying one column is
enough. And under D-117 an installation may keep the activity log for ever.

So anything a writer puts into the words outlives every erasure. Measured on
11 November 2026 with PHP's tokeniser, 53 of the 682 calls to
`Logger::activity()` under web/ put a person's details there. After the
owner's two decisions on #933, 41 of those were changed to numbers and fixed
words, and 12 were deliberately left or put back:

  - D-137: a failed or rate-limited sign-in keeps the sign-in name that was
    TYPED, readable, because an administrator looking into repeated failures
    needs to see which name was tried (four writers in `Auth.php`, and
    `RateLimiter::clearNameFailures()`, whose words the sign-in limit matches);
  - the same reasoning for a SAFEGUARDING record: the name typed for an adult
    who tried to collect a child and was refused (`kids/checkout.php`);
  - D-138: an organisation's own labels stay — a task's title among them, so
    the five task writers and a workflow's cancellation reason (only ever
    fixed words from its one caller) are as they were.

The exceptions that keep a personal NAME are in ON_PURPOSE below, keyed by
file, record type and name, so each covers exactly one kind of record.

WHAT IT LOOKS FOR
-----------------
`activity-description-probe.php`, beside this file, reads every PHP file under
web/ with PHP's OWN tokeniser and reports, for each `Logger::activity(` call,
its record type and the names its description argument is built from:
variables, literal array keys, properties and called functions. (Not a regular
expression: a hand-written PHP reader has failed in this repository too many
times — #494, #763.) This file then fails on:

  1. A name that holds a person's details, from PERSONAL_NAMES below
     ("fullName", "emailAddress", "pickupName", "clientIp", ...), compared
     without regard to capitals. FILE_PERSONAL_NAMES adds names that are
     personal only in one file (`$name` in RateLimiter is a sign-in name; in
     forty other places it is a department's or a group's name, which D-138
     keeps).
  2. Anything read straight from the request (`$_POST`, `$_GET`, `$_REQUEST`,
     `$_COOKIE`) that is not first turned into a number: text a visitor sent
     is free text, and could be anything.
  3. A pass-through wrapper — a function or closure whose description is
     exactly one of its own parameters — that is not named in
     PASS_THROUGH_ON_PURPOSE. What reaches such a call is decided by its
     callers, which the probe does not follow, so each one is a decision.
  4. A statement in TEXT_PINS that is no longer exactly as pinned (as `php -w`
     prints it), or appears more or fewer times than once. This covers, for
     the ONE place pinned so far, the blind spot below: a value built in a
     local variable first (the event-registration bot check's `$slugTried`).

Both lists of exceptions FAIL BOTH WAYS: an entry that no longer matches
anything is reported too, because a stale exception reads as a reason nobody
gave (the migration harness's exceptions went stale that way).

WHAT IT CANNOT SEE — this is a PRESENCE check (D-065), and its limits are real
-----------------------------------------------------------------------------
- A personal value under a name that is not on the list. `$title` is a task's
  title in one file and an announcement's in another; `$reason` is typed free
  text in one place and a fixed code in another. Those are left to review,
  because listing them would cry wolf on forty correct calls (#483) and a
  check that cries wolf gets switched off. Of the 41 writers #933 changed,
  THREE are therefore NOT guarded here — measured by running this check over
  the code before the change, which named the other 38: the photo-rejection
  reason, the treasurer's payment references, and the leadership line below.
- A value that reaches the description through a local variable built earlier
  (`$detail = 'Assigned ' . $fullName; Logger::activity('X', $detail)`): only
  `detail` is seen. (`leadership/save.php` did exactly this.)
- A value passed through a pass-through wrapper's CALLERS — and a wrapper that
  adds words of its own (`Logger::activity($type, 'Note: ' . $words)`) is not a
  pass-through at all, so it is not even asked about.
- NAMED arguments in another order (`Logger::activity(description: $x, type:
  'T')`): the probe takes the SECOND argument as the description, whatever its
  name, so it reads `'T'` and misses `$x`. (No call is written that way today.)
- A name spelled differently from the list: a plural (`implode(', ',
  $emails)`), a snake_case key (`$row['full_name']`), or a getter
  (`$user->getEmail()`) — each is a name the list does not hold, measured by
  the independent check of round 2. Widening the list to catch them would start
  catching organisation labels too (D-138); review is the guard there.
- Two calls of the SAME record type in one file share an exception: when one
  of the two `LoginFailed` lines in `Auth.php` stopped carrying the typed name
  (planted), this check stayed green. The HTTP self-test is what catches that.
- Whether the words are TRUE, or whether a number is somebody's phone number.
- Writers that do not use `Logger::activity()` — there is one,
  `ActivityLogCleaner`, which writes fixed words and an account number.

What it does prove is that the shapes #933 removed do not come back under the
same names. Its OWN test is `--self-test` (below; run by
`tools/audit-check-selftests.py`, so by the pre-push hook and pr-security.yml):
a copy of web/ with planted writers, each required to be reported, and correct
writers that must not be. What the pages REALLY write is driven over HTTP by
`tools/activity-description-selftest.php`.

Exit: 0 when nothing is found; 1 when something is, or when the check could
not run at all (no php, the probe failed): a check that cannot run must never
look like a clean result.
"""

from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))
WEB = os.path.join(REPO, "web")
PROBE = os.path.join(HERE, "activity-description-probe.php")
TIMEOUT_SECONDS = 120

# Names that hold a person's details. Lower case; compared without capitals.
PERSONAL_NAMES = {
    # names
    "fullname", "displayname", "firstname", "lastname", "surname", "givenname",
    "familyname", "preferredname", "assignname", "subjectname", "donorname",
    "freetextname", "extname", "extnm", "externalname", "pickupname",
    "submittername", "emergname", "pickupauthorisednames",
    # e-mail addresses and sign-in names
    "email", "emailaddress", "provideremail", "parentemail", "subjectemail",
    "assignemail", "submitteremail", "recipient", "delegateuser", "supportemail",
    "identifier", "countedas", "username",
    # telephone numbers and postal addresses
    "phone", "phonenumber", "mobile", "telephone", "emergphone", "postcode",
    "postaladdress", "homeaddress", "street", "addressline1", "addressline2",
    "dateofbirth", "dob",
    # network addresses and device addresses
    "ip", "ipaddress", "visitorip", "clientip", "remote_addr",
    "http_x_forwarded_for", "http_cf_connecting_ip", "endpoint",
    # free text a person writes about themselves or their own things
    "friendlyname", "claimtitle",
}

# Names that are personal only in one file. Keyed by the path under web/.
FILE_PERSONAL_NAMES = {
    "_core/RateLimiter.php": {"name"},  # a sign-in name, usually an e-mail address
}

# Request superglobals: anything a visitor sent.
REQUEST_NAMES = {"_post", "_get", "_request", "_cookie"}

# (path under web/, record type as written in the call, name) -> why. FAILS
# BOTH WAYS. The record type is part of the key so an exception covers ONE
# kind of record, not every record a file writes.
ON_PURPOSE = {
    ("_core/Auth.php", "'LoginBlocked'", "identifier"):
        "D-137: a rate-limited sign-in keeps the name that was typed, readable",
    ("_core/Auth.php", "'LoginBlocked'", "countedAs"):
        "D-137: a rate-limited sign-in keeps the name it is counted under, readable",
    ("_core/Auth.php", "'LoginFailed'", "countedAs"):
        "D-137: a failed sign-in keeps the name it is counted under, readable; the "
        "sign-in limit matches these exact words",
    ("_core/RateLimiter.php", "self::NAME_CLEARED_TYPE", "name"):
        "D-137: the 'count cleared' record must carry the same readable name the "
        "failures carry, or the limit for that name could never be cleared",
    ("_apps/kids/checkout.php", "'KidCheckoutBlocked'", "pickupName"):
        "a safeguarding record: who tried to collect a child and was refused, kept "
        "readable on D-137's reasoning; nothing else records a refused attempt",
    ("_apps/admin/settings/organisation/save.php", "'PortalAddressSaved'", "supportEmail"):
        "the ORGANISATION's support e-mail address, a published setting that an "
        "administrator changes on purpose; the record says what it was set to",
    ("_apps/admin/maintenance/demo-data.php", "'DemoDataActionRefused'", "_POST"):
        "the refused ACTION's name, cut to a-z and _ and 30 characters first",
    ("_apps/admin/maintenance/backup.php", "'BackupActionRefused'", "_POST"):
        "the refused ACTION's name, cut to a-z and _ and 30 characters first",
}

# (path under web/, function name or "closure") -> why. FAILS BOTH WAYS.
PASS_THROUGH_ON_PURPOSE = {
    ("_apps/admin/maintenance/backup.php", "closure"):
        "`$record`, called only on this page, with table and snapshot names, "
        "counts and the restore's own outcome words",
}

# Statements pinned WHOLE, as text, where a description is built from a local
# variable this check cannot see through (its stated blind spot). Each value is
# the statement as `php -w` prints it - comments and spacing removed by PHP
# itself, so a reworded comment or a re-indent does not matter and a changed
# statement does. It must appear exactly ONCE in the file: a dead copy above
# the live one would otherwise satisfy it.
#
# The event-registration bot check (#933 round 3): the visitor's `slug` reaches
# the log only through `$slugTried`, and deleting the `preg_match()` that keeps
# it to an event address's shape left every other check green - measured by
# the independent check of round 3, and by this file's own self-test now.
# Pinned with the record it feeds, so the description cannot be pointed back at
# the raw request either. Changing either statement on purpose means changing
# this pin in the same commit; that is the point.
TEXT_PINS = {
    "_apps/calendar/event-register-save.php": [
        "$slugTried = (is_string($_POST['slug'] ?? null) === true && "
        "preg_match('/^[a-z0-9][a-z0-9\\-]{0,79}$/i', trim($_POST['slug'])) === 1) "
        "? trim($_POST['slug']) : '(not an event address)'; "
        "Logger::activity('EventRegistrationCaptchaFailed', 'Slug=' . $slugTried);",
    ],
}


class CouldNotRun(Exception):
    """The probe could not give an answer; never a clean result."""


def probe(web: str) -> list[dict]:
    php = shutil.which("php")
    if php is None:
        raise CouldNotRun("there is no `php` on this machine, and this check asks PHP's own tokeniser")
    try:
        done = subprocess.run([php, PROBE, web], capture_output=True, timeout=TIMEOUT_SECONDS)
    except subprocess.TimeoutExpired as exc:
        raise CouldNotRun(f"the probe did not finish within {TIMEOUT_SECONDS} seconds") from exc
    if done.returncode != 0:
        raise CouldNotRun("the probe failed: " + done.stderr.decode("utf-8", "replace").strip()[:500])
    try:
        calls = json.loads(done.stdout.decode("utf-8"))["calls"]
    except (ValueError, KeyError) as problem:
        raise CouldNotRun(f"the probe's answer could not be read ({problem})") from problem
    if len(calls) < 100:
        # The real portal has hundreds; a handful means the probe is reading
        # the wrong folder or has stopped recognising the call.
        raise CouldNotRun(f"the probe found only {len(calls)} calls to Logger::activity()")
    return calls


def text_pin_findings(web: str) -> list[str]:
    """Every TEXT_PINS statement must appear exactly once in its file, as PHP strips it."""
    php = shutil.which("php")
    if php is None:
        raise CouldNotRun("there is no `php` on this machine, and the text pins ask `php -w`")
    faults: list[str] = []
    for rel, statements in TEXT_PINS.items():
        path = os.path.join(web, rel.replace("/", os.sep))
        try:
            done = subprocess.run([php, "-w", path], capture_output=True, timeout=TIMEOUT_SECONDS)
        except subprocess.TimeoutExpired as exc:
            raise CouldNotRun(f"`php -w {rel}` did not finish") from exc
        if done.returncode != 0 or done.stdout == b"":
            raise CouldNotRun(f"`php -w` could not read web/{rel}")
        code = done.stdout.decode("utf-8", "replace")
        for statement in statements:
            count = code.count(statement)
            if count != 1:
                faults.append(f"web/{rel}: the pinned statement appears {count} time(s), not once: "
                              f"{statement[:160]}... It guards what reaches an activity record's words "
                              "through a local variable this check cannot see through. If the change "
                              "is deliberate, change TEXT_PINS in the same commit.")
    return faults


def findings(calls: list[dict]) -> list[str]:
    faults: list[str] = []
    used_on_purpose: set[tuple[str, str, str]] = set()
    used_pass_through: set[tuple[str, str]] = set()

    for call in calls:
        where = f"web/{call['file']}:{call['line']}"
        personal_here = PERSONAL_NAMES | FILE_PERSONAL_NAMES.get(call["file"], set())
        for name in call["names"]:
            low = name.lower()
            if low in personal_here or low in REQUEST_NAMES:
                key = (call["file"], call["type"], name)
                if key in ON_PURPOSE:
                    used_on_purpose.add(key)
                    continue
                what = ("text a visitor sent (`$" + name + "`), not turned into a number first"
                        if low in REQUEST_NAMES else f"`{name}`, which holds a person's details")
                faults.append(f"{where}: an activity record's words are built from {what}. "
                              "Write the record's number and fixed words instead, and put the "
                              "person in the third argument (#626, #933).")
        if call["passThrough"] is True:
            key = (call["file"], call["scope"])
            if key in PASS_THROUGH_ON_PURPOSE:
                used_pass_through.add(key)
            else:
                faults.append(f"{where}: a wrapper ({call['scope']}) passes one of its own "
                              "parameters straight through as an activity record's words, so "
                              "what is written is decided by its callers, which this check "
                              "cannot see. Read every caller, then name it in "
                              "PASS_THROUGH_ON_PURPOSE with the reason.")

    for key in sorted(set(ON_PURPOSE) - used_on_purpose):
        faults.append(f"ON_PURPOSE names web/{key[0]}, record type {key[1]}, `{key[2]}`, which no "
                      "activity record's words use any more: remove the entry.")
    for key in sorted(set(PASS_THROUGH_ON_PURPOSE) - used_pass_through):
        faults.append(f"PASS_THROUGH_ON_PURPOSE names web/{key[0]} ({key[1]}), which is no "
                      "longer a pass-through wrapper: remove the entry.")
    return faults


# =============================================================================
# 🧪 The self-test: planted writers must be reported, correct ones must not
# =============================================================================
# One file of PLANTS and one of writers that must stay GREEN are dropped into a
# throwaway copy of web/ (the whole copy, so the exception lists are not
# reported as stale and the result is exactly what a real run would print).
# Each plant carries a marker word in its type, so a finding is matched to the
# plant by its line number, which this file knows because it wrote the file.
PLANTS = [
    ("plant-fullname",  "Logger::activity('P1', 'Created user: ' . $fullName);"),
    ("plant-email-key", "Logger::activity('P2', 'Sent to ' . $row['emailAddress']);"),
    ("plant-property",  "Logger::activity('P3', 'Linked ' . $link->providerEmail);"),
    ("plant-interp",    "Logger::activity('P4', \"Registered {$fullName}\");"),
    ("plant-ip-call",   "Logger::activity('P5', 'From ' . RateLimiter::clientIp());"),
    ("plant-post",      "Logger::activity('P6', 'Asked for ' . $_POST['name']);"),
    ("plant-get",       "Logger::activity('P7', 'Search ' . $_GET['q']);"),
    ("plant-max",       "Logger::activity('P8', 'Name ' . max($fullName, ''));"),
    ("plant-qualified", "\\Portal\\Core\\Logger::activity('P9', 'Pickup ' . $pickupName);"),
    ("plant-wrapper",   "$log = static function (string $type, string $words): void { Logger::activity($type, $words); };"),
    # #988: the record that names nobody on purpose is read by the same rules -
    # its WORDS must not name anybody either.
    ("plant-nobody",    "Logger::activityNamingNobody('P10', 'Sent by ' . $submitterEmail);"),
    # The same name and file as an ON_PURPOSE entry, under ANOTHER record type:
    # the exception must not stretch to it. Written into the copy's checkout page.
    ("plant-wrong-type", None),
]
GREENS = [
    "Logger::activity('G1', 'Added account #' . $userId . ' to group #' . $groupId);",
    "Logger::activity('G2', 'Created group \"' . $groupName . '\" (#' . $newId . ')');",   # D-138
    "Logger::activity('G3', 'Sent to ' . count($recipients) . ' recipients');",
    "Logger::activity('G4', 'Days ' . (int) $_POST['ownDays']);",
    "Logger::activity('G5', 'Endpoint at ' . (string) parse_url($endpoint, PHP_URL_HOST));",
    "Logger::activity('G6', 'Fixed words only');",
    "// Logger::activity('G7', 'In a comment: ' . $fullName);",
    "Logger::activity('G8', 'A string that says fullName and email');",
    "Logger::activity('G9', 'Length ' . strlen($emailAddress));",
]


def self_test() -> int:
    passed = failed = 0

    def ok(label: str, good: bool, detail: str = "") -> None:
        nonlocal passed, failed
        if good:
            passed += 1
            print("PASS — " + label)
        else:
            failed += 1
            print("FAIL — " + label + ("\n        " + detail[:900] if detail else ""))

    scratch = tempfile.mkdtemp(prefix="activity-descriptions-selftest-")
    try:
        copy = os.path.join(scratch, "web")
        shutil.copytree(WEB, copy, ignore=shutil.ignore_patterns("_auth_keys", "_uploads", "_backups", "_libraries"))

        # A clean copy first: the check must be green on the real portal, or
        # every plant below proves nothing.
        try:
            clean = findings(probe(copy))
        except CouldNotRun as why:
            print(f"REFUSED — the self-test could not run the probe: {why}")
            return 1
        ok("the real portal, unchanged, has no findings", clean == [], "\n".join(clean))
        pins_clean = text_pin_findings(copy)
        ok("the real portal's pinned statements are all present, once each", pins_clean == [], "\n".join(pins_clean))

        # The slug plant (#933 round 4): delete the shape test from the
        # assignment, leaving the variable and the record as they are. Every
        # name-based rule stays green on this, which is why the pin exists.
        register = os.path.join(copy, "_apps", "calendar", "event-register-save.php")
        with open(register, encoding="utf-8") as handle:
            reg_source = handle.read()
        shape_test = "\n                && preg_match('/^[a-z0-9][a-z0-9\\-]{0,79}$/i', trim($_POST['slug'])) === 1)"
        if reg_source.count(shape_test) != 1:
            ok("the slug plant found the shape test it removes", False,
               f"the shape test appears {reg_source.count(shape_test)} times")
        else:
            with open(register, "w", encoding="utf-8") as handle:
                handle.write(reg_source.replace(shape_test, ")", 1))
            ok("the slug plant (shape test deleted) leaves the name rules green - the blind spot the pin covers",
               findings(probe(copy)) == [])
            pinned = text_pin_findings(copy)
            ok("the slug plant is reported by the text pin", any("event-register-save.php" in f for f in pinned),
               "\n".join(pinned))
            with open(register, "w", encoding="utf-8") as handle:
                handle.write(reg_source)
            ok("and with the file put back, the pin is green again", text_pin_findings(copy) == [])

        folder = os.path.join(copy, "_apps", "zz933selftest")
        os.makedirs(folder)
        plant_lines: dict[str, int] = {}
        lines = ["<?php", "declare(strict_types=1);", "use Portal\\Core\\Logger;", "use Portal\\Core\\RateLimiter;"]
        for marker, code in PLANTS:
            if code is None:
                continue
            lines.append(code)
            plant_lines[marker] = len(lines)
        with open(os.path.join(folder, "plants.php"), "w", encoding="utf-8") as handle:
            handle.write("\n".join(lines) + "\n")

        green_lines = ["<?php", "declare(strict_types=1);", "use Portal\\Core\\Logger;"] + GREENS
        with open(os.path.join(folder, "greens.php"), "w", encoding="utf-8") as handle:
            handle.write("\n".join(green_lines) + "\n")

        # The wrong-type plant: the readable pickup name written into a record
        # of ANOTHER type in the very file whose exception covers it.
        checkout = os.path.join(copy, "_apps", "kids", "checkout.php")
        with open(checkout, encoding="utf-8") as handle:
            source = handle.read()
        anchor = "Logger::activity('KidCheckedOut', "
        if source.count(anchor) != 1:
            ok("the wrong-type plant found the line it plants beside", False,
               f"'{anchor}' appears {source.count(anchor)} times in kids/checkout.php")
        else:
            before = source[: source.index(anchor)]
            plant_lines["plant-wrong-type"] = before.count("\n") + 1
            source = source.replace(anchor, "Logger::activity('KidCheckedOut', 'To ' . $pickupName); " + anchor, 1)
            with open(checkout, "w", encoding="utf-8") as handle:
                handle.write(source)

        planted = findings(probe(copy))
        text = "\n".join(planted)
        for marker, _ in PLANTS:
            if marker not in plant_lines:
                continue
            path = "web/_apps/kids/checkout.php" if marker == "plant-wrong-type" else "web/_apps/zz933selftest/plants.php"
            ok(f"{marker} is reported", f"{path}:{plant_lines[marker]}:" in text, text)
        ok("no line of the GREEN file is reported (an organisation's label, a count, a cast, a host, a comment, a string)",
           "zz933selftest/greens.php" not in text, text)
        expected = len(plant_lines)
        ok(f"exactly {expected} findings, one per plant and nothing else", len(planted) == expected,
           f"{len(planted)} findings:\n" + text)
    finally:
        shutil.rmtree(scratch, ignore_errors=True)

    print(f"\n{passed} passed, {failed} failed.")
    return 0 if failed == 0 and passed > 0 else 1


def main() -> int:
    if "--self-test" in sys.argv[1:]:
        return self_test()
    # One optional argument: another web/ folder to read instead (an unchanged
    # copy, for proving the check goes red on the code #933 replaced). The
    # exception lists still name paths under web/, so a different tree may
    # also report stale entries; that is expected there.
    web = os.path.abspath(sys.argv[1]) if len(sys.argv) > 1 else WEB
    try:
        calls = probe(web)
    except CouldNotRun as why:
        print(f"  • check_activity_descriptions_personal: COULD NOT RUN — {why}. "
              "This is a failure, not a clean result.")
        return 1
    try:
        faults = findings(calls) + text_pin_findings(web)
    except CouldNotRun as why:
        print(f"  • check_activity_descriptions_personal: COULD NOT RUN — {why}. "
              "This is a failure, not a clean result.")
        return 1
    if faults:
        print("Activity-record words built from a person's details (#933):")
        for fault in faults:
            print("  • " + fault)
        return 1
    print(f"OK: {len(calls)} calls to Logger::activity() read; no description is built "
          "from a name on the personal list or from unconverted request text, other than "
          f"the {len(ON_PURPOSE)} named exceptions, every pass-through wrapper is named, and "
          f"{sum(len(v) for v in TEXT_PINS.values())} pinned statement(s) are as pinned. "
          "(A presence check: see this file's header for what it cannot see.)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
