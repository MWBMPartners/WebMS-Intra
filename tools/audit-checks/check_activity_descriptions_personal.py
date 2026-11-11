#!/usr/bin/env python3
"""
An activity record's WORDS are never built from a person's details (#933).

Path: tools/audit-checks/check_activity_descriptions_personal.py

WHY THIS EXISTS
---------------
An erasure empties an activity record's account number, network address,
browser name and session copy for the erased person's rows, but it keeps the
record's DESCRIPTION exactly as written. That is deliberate (#626): a record
names its person in `userID`, never in its words, so emptying one column is
enough. And under D-117 an installation may keep the activity log for ever.

So anything a writer puts into the words outlives every erasure. Measured on
11 November 2026, 53 of the 682 calls to `Logger::activity()` under web/ put a
person's details there: an e-mail address on every failed sign-in, every
password-reset request and every provider link; a registrant's, a child's, a
donor's, a new member's name; the name typed for the adult collecting a child;
a visitor's network address; a passkey's name. Many of those records belong to
the ADMINISTRATOR who acted (`userID` is who did it), so erasing the person
named in the words could never reach them at all. All 53 now write numbers and
fixed words; this check stops a 54th.

WHAT IT LOOKS FOR
-----------------
`activity-description-probe.php`, beside this file, reads every PHP file under
web/ with PHP's OWN tokeniser and reports, for each `Logger::activity(` call,
the names its description argument is built from: variables, literal array
keys, properties and called functions. (Not a regular expression: a
hand-written PHP reader has failed in this repository too many times — #494,
#763.) This file then fails on:

  1. A name that holds a person's details, from PERSONAL_NAMES below
     ("fullName", "emailAddress", "pickupName", "clientIp", ...), compared
     without regard to capitals. FILE_PERSONAL_NAMES adds names that are
     personal only in one file (`$name` in RateLimiter is a sign-in name; in
     forty other places it is a department's or a group's name).
  2. Anything read straight from the request (`$_POST`, `$_GET`, `$_REQUEST`,
     `$_COOKIE`) that is not first turned into a number: text a visitor sent
     is free text, and could be anything.
  3. A pass-through wrapper — a function or closure whose description is
     exactly one of its own parameters — that is not named in
     PASS_THROUGH_ON_PURPOSE. What reaches such a call is decided by its
     callers, which the probe does not follow, so each one is a decision.

The three lists of exceptions FAIL BOTH WAYS: an entry that no longer matches
anything is reported too, because a stale exception reads as a reason nobody
gave (the migration harness's exceptions went stale that way).

WHAT IT CANNOT SEE — this is a PRESENCE check (D-065), and its limits are real
-----------------------------------------------------------------------------
- A personal value under a name that is not on the list. `$title` is a task's
  title in one file and an announcement's in another; `$reason` is typed free
  text in one place and a fixed code in another. Those are left to review,
  because listing them would cry wolf on forty correct calls (#483) and a
  check that cries wolf gets switched off. Of the 53 writers #933 changed, NINE
  are therefore NOT guarded here, measured by running this check over the
  code before the change (it named the other 44): the five task-title
  writers, the workflow-cancellation and photo-rejection reasons, the
  treasurer's payment references, and the leadership line below.
- A value that reaches the description through a local variable built earlier
  (`$detail = 'Assigned ' . $fullName; Logger::activity('X', $detail)`): only
  `detail` is seen. (`leadership/save.php` did exactly this.)
- A value passed through a pass-through wrapper's CALLERS.
- Whether the words are TRUE, or whether a number is somebody's phone number.
- Writers that do not use `Logger::activity()` — there is one,
  `ActivityLogCleaner`, which writes fixed words and an account number.

What it does prove is that none of the 53 shapes #933 removed comes back under
the same names, and that the organisation's labels (a department's name, an
event's title) can still be written. Its own test is section P of
`tools/activity-description-selftest.php`, which plants a writer back.

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

# (path under web/, name) -> why it may be written. FAILS BOTH WAYS.
ON_PURPOSE = {
    ("_apps/admin/settings/organisation/save.php", "supportEmail"):
        "the ORGANISATION's support e-mail address, a published setting that an "
        "administrator changes on purpose; the record says what it was set to",
    ("_apps/calendar/event-register-save.php", "_POST"):
        "the event's address (`slug`) a failed bot check was for, which names an "
        "event, not a person",
    ("_apps/admin/maintenance/demo-data.php", "_POST"):
        "the refused ACTION's name, cut to a-z and _ and 30 characters first",
    ("_apps/admin/maintenance/backup.php", "_POST"):
        "the refused ACTION's name, cut to a-z and _ and 30 characters first",
}

# (path under web/, function name or "closure") -> why. FAILS BOTH WAYS.
PASS_THROUGH_ON_PURPOSE = {
    ("_apps/admin/maintenance/backup.php", "closure"):
        "`$record`, called only on this page, with table and snapshot names, "
        "counts and the restore's own outcome words",
}


def refuse(why: str) -> "None":
    print(f"  • check_activity_descriptions_personal: COULD NOT RUN — {why}. "
          "This is a failure, not a clean result.")
    sys.exit(1)


def main() -> int:
    # One optional argument: another web/ folder to read instead (an unchanged
    # copy, for proving the check goes red on the code #933 replaced). The
    # exception lists still name paths under web/, so a different tree may
    # also report stale entries; that is expected there.
    web = os.path.abspath(sys.argv[1]) if len(sys.argv) > 1 else WEB
    php = shutil.which("php")
    if php is None:
        refuse("there is no `php` on this machine, and this check asks PHP's own tokeniser")
    try:
        done = subprocess.run([php, PROBE, web], capture_output=True, timeout=TIMEOUT_SECONDS)
    except subprocess.TimeoutExpired:
        refuse(f"the probe did not finish within {TIMEOUT_SECONDS} seconds")
    if done.returncode != 0:
        refuse("the probe failed: " + done.stderr.decode("utf-8", "replace").strip()[:500])
    try:
        calls = json.loads(done.stdout.decode("utf-8"))["calls"]
    except (ValueError, KeyError) as problem:
        refuse(f"the probe's answer could not be read ({problem})")
    if len(calls) < 100:
        # The real portal has hundreds; a handful means the probe is reading
        # the wrong folder or has stopped recognising the call.
        refuse(f"the probe found only {len(calls)} calls to Logger::activity()")

    faults: list[str] = []
    used_on_purpose: set[tuple[str, str]] = set()
    used_pass_through: set[tuple[str, str]] = set()

    for call in calls:
        where = f"web/{call['file']}:{call['line']}"
        personal_here = PERSONAL_NAMES | FILE_PERSONAL_NAMES.get(call["file"], set())
        for name in call["names"]:
            low = name.lower()
            if low in personal_here or low in REQUEST_NAMES:
                key = (call["file"], name)
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
        faults.append(f"ON_PURPOSE names web/{key[0]} and `{key[1]}`, which no activity "
                      "record's words use any more: remove the entry.")
    for key in sorted(set(PASS_THROUGH_ON_PURPOSE) - used_pass_through):
        faults.append(f"PASS_THROUGH_ON_PURPOSE names web/{key[0]} ({key[1]}), which is no "
                      "longer a pass-through wrapper: remove the entry.")

    if faults:
        print("Activity-record words built from a person's details (#933):")
        for fault in faults:
            print("  • " + fault)
        return 1
    print(f"OK: {len(calls)} calls to Logger::activity() read; no description is built "
          "from a name on the personal list or from unconverted request text, and every "
          "pass-through wrapper is named. (A presence check: see this file's header for "
          "what it cannot see.)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
