#!/usr/bin/env python3
"""
A time on a clock must never be pushed through a function that wants a moment.

Path: tools/audit-checks/check_clock_time_not_a_moment.py

WHY THIS EXISTS (#777, and #549's "mistake 1")
----------------------------------------------
The owner's standing rule of 23 September 2026: every time this portal stores,
works out, compares or shows must respect its time zone, and must stay correct
across the two nights a year when the clocks change. **An hour wrong in a diary
is a congregation arriving at a locked building.**

A column declared `TIME` holds a reading from a clock -- "the group meets at
19:30" -- and **a time on a clock is not a point in history until something
gives it a date AND a zone.** `strtotime('01:30')` gives it both, badly: today's
date, in PHP's default zone, which has nothing to do with the organisation's.

**IT WAS NOT HYPOTHETICAL.** Measured in Europe/London before this check was
written, on a stored meeting time of `01:30`:

    28 March 2026   ->  1:30 AM    (right)
    29 March 2026   ->  2:30 AM    (WRONG -- the hour does not exist that day,
                                    so PHP moved the time an hour forward)
    25 October 2026 ->  1:30 AM    (right)

Four pages did it: three in Small Groups and one in Attendance. The stored data
was never wrong; the display was, for one day a year, for any reading inside the
skipped hour. Worse, two different stored times (`01:00` and `01:30`) both came
out as the same words on that day.

WHAT IT CHECKS
--------------
Every `TIME` column in `web/_sql/full_schema.sql` is read out of the schema --
never from a list typed here, which is the #706 lesson -- and this check fails
when any PHP under `web/` passes one of those column names to `strtotime()` or
to a `DateTime` constructor.

**And it pins ONE function body: `I18n::formatClockTime()` must contain no
moment function at all.** That looks like belt and braces and is not. The
behavioural test cannot catch this one, and the reason is #549's own recorded
trap: *"a test written in December passes in London whatever the code does."*
Measured -- with `formatClockTime()`'s arithmetic replaced by the old
`date('g:i A', strtotime($text))`, `tools/clock-change-selftest.php` stayed at
**40 of 40**, because the test runs on an ordinary day and the two agree on
every ordinary day. They differ only inside the hour that does not exist, one
morning a year.

So for this one function the property really is "the source must not do that",
because no test running today can tell. Everywhere else the question is which
VALUE reached a moment function, and that is what the column sweep above
answers.

WHAT IT DELIBERATELY DOES NOT CHECK, because a check that cries wolf gets
switched off (#483)
-------------------------------------------------------------------------
  * **`DATETIME` and `DATE` columns.** A `DATETIME` may legitimately hold a
    moment, and turning one into a `DateTimeImmutable` is the right thing to do
    with it. Only `TIME` is unambiguous: there is no such thing as a `TIME`
    column that holds a point in history.
  * **A clock reading given a date AND a zone together**, as
    `web/_core/Livestream.php` does with
    `createFromFormat('Y-m-d H:i:s', $date . ' ' . $time, $tzObj)`. That is the
    correct construction, and it is recognised by the format string carrying a
    date part.

    **An earlier version of this sentence was untrue in the reassuring
    direction:** the code skipped EVERY `createFromFormat`, not only that form,
    so `createFromFormat('H:i', $clock)` -- which has neither a date nor a zone
    and quietly means "today, in whatever zone this server uses" -- was
    invisible. It was live in `attendance/api/create.php` and `update.php`,
    where it refused every valid session time from 01:00 to 01:59 on the
    spring-forward morning.
  * **Slicing the text**, as `web/_apps/rota/index.php` does with
    `substr((string) $slot['startTime'], 0, 5)`. Correct on every day of the
    year in every zone, and the model the fix copied.

So a green run means no PHP under `web/` hands a clock-reading column to a
moment function **by name, in one expression**. It does NOT mean the portal is
correct across a clock change: a reading pulled into a variable several lines
earlier and converted there is invisible here, and saying so is better than a
check that pretends otherwise.

THE ONE PLACE A CLOCK READING IS PUT INTO WORDS
-----------------------------------------------
`Portal\\Core\\I18n::formatClockTime()`. It never builds a moment, so there is
no transition for a reading to fall into. Proved against the old code on all
1,440 minutes of a normal day: identical output, and different only inside the
skipped hour, where the old code was wrong.

Package: WebMS-Intra / audit checks
Author: Claude Opus 5 (for MWBM Partners Ltd, t/a MWservices)
Copyright: All Rights Reserved -- MWBM Partners Ltd
Version: 1.0.0
"""

from __future__ import annotations

import os
import re
import sys

WEB = "web"
SCHEMA = os.path.join("web", "_sql", "full_schema.sql")

# A clock reading handed straight to something that wants a moment.
#
# ⚠️ THE LEADING BACKSLASH IS NOT OPTIONAL, and leaving it out meant this check
#    missed the MAJORITY spelling of the thing it exists to catch. Measured
#    under `web/`: `new \DateTime*` appears 87 times against 79 for the bare
#    form, and `attendance/api/create.php` -- one of the files this package
#    fixed -- writes `\DateTimeImmutable`. Planted,
#    `new \DateTimeImmutable((string) $row['meetingTime'])` passed.
#
# ⚠️ AND `createFromFormat` IS INCLUDED, with one exception below. The header
#    used to say this skipped "a clock reading given a date AND a zone
#    together", which is the correct construction -- but the code skipped EVERY
#    `createFromFormat`, including `createFromFormat('H:i', $clock)`, which has
#    neither a date nor a zone and quietly means "today, in whatever zone this
#    server uses". That form was live in two files and invisible here.
MOMENT_FUNCTIONS = (
    r"(?:strtotime|new\s+\\?DateTime(?:Immutable)?|"
    r"\\?DateTime(?:Immutable)?::createFromFormat)"
)

# The one construction that is RIGHT: a reading given a date AND a zone in the
# same call, as `web/_core/Livestream.php` does. Recognised by the format string
# carrying a date part, which `'H:i'` does not.
CORRECT_WITH_DATE_AND_ZONE = re.compile(
    r"createFromFormat\s*\(\s*['\"][^'\"]*[Yy][^'\"]*['\"]"
)

# 📁 Places where this is RIGHT, each with its reason. Fails both ways: an entry
#    that no longer does it fails too, because an allow-list that has stopped
#    describing the code is how the migration harness's exceptions went stale.
ALLOWED: dict[str, str] = {}

# 🕐 The one function whose BODY is pinned, and the three things it may not
#    contain. See "WHAT IT CHECKS" for the measurement that argues for it: the
#    behavioural test stays green with the fault put back, because it runs on an
#    ordinary day.
FORMATTER_FILE = os.path.join("web", "_core", "I18n.php")
FORMATTER_NAME = "formatClockTime"
FORMATTER_FORBIDS = ("strtotime", "date(", "DateTime")

SUFFIXES = (".php",)


def repo_root() -> str:
    here = os.path.dirname(os.path.abspath(__file__))
    return os.path.dirname(os.path.dirname(here))


def clock_columns(schema: str) -> list[str]:
    """
    Every column declared `TIME` in the schema.

    ⚠️ READ OUT OF THE SCHEMA, never from a list typed here. #706's lesson is
       that a hand-written list of column names is somebody's memory wearing a
       schema-driven check's clothes -- it knew `phone` but not `phoneNumber`,
       and its own comments recorded eleven occasions on which somebody had to
       extend it.
    """
    found = []
    for match in re.finditer(r"`([A-Za-z0-9_]+)`\s+TIME\b", schema):
        name = match.group(1)
        if name not in found:
            found.append(name)
    return found


def php_only(text: str) -> str:
    """Everything inside `<?php ... ?>`, with the rest blanked."""
    out = []
    i = 0
    n = len(text)
    while i < n:
        start = text.find("<?php", i)
        if start == -1:
            start = text.find("<?=", i)
        if start == -1:
            out.append("".join(c if c == "\n" else " " for c in text[i:]))
            break
        out.append("".join(c if c == "\n" else " " for c in text[i:start]))
        end = text.find("?>", start)
        if end == -1:
            out.append(text[start:])
            break
        out.append(text[start:end + 2])
        i = end + 2
    return "".join(out)


def blank_comments(text: str) -> str:
    """
    Comments out, strings kept, length and line count preserved.

    Strings are KEPT because a column name appears inside one in every SQL
    statement; the search is for a column name next to a moment function, and a
    `SELECT s.sessionTime` is not that.
    """
    out = []
    i = 0
    n = len(text)
    while i < n:
        ch = text[i]
        if ch in "'\"":
            quote = ch
            out.append(ch)
            i += 1
            while i < n:
                if text[i] == "\\" and i + 1 < n:
                    out.append(text[i:i + 2])
                    i += 2
                    continue
                out.append(text[i])
                if text[i] == quote:
                    i += 1
                    break
                i += 1
            continue
        if text.startswith("//", i) or ch == "#":
            began = i
            while i < n and text[i] != "\n":
                i += 1
            out.append(" " * (i - began))
            continue
        if text.startswith("/*", i):
            end = text.find("*/", i + 2)
            end = n if end == -1 else end + 2
            out.append("".join(c if c == "\n" else " " for c in text[i:end]))
            i = end
            continue
        out.append(ch)
        i += 1
    return "".join(out)


def main() -> int:
    root = repo_root()
    schema_path = os.path.join(root, SCHEMA)
    if os.path.isfile(schema_path) is False:
        print(f"FAIL: {SCHEMA} is not there. This check cannot run.")
        return 1
    with open(schema_path, "r", encoding="utf-8", errors="replace") as handle:
        columns = clock_columns(handle.read())
    if len(columns) == 0:
        print(
            "FAIL: the schema declares no TIME column at all, which cannot be "
            "right -- so this check is reading the wrong thing and would pass "
            "over every fault it exists to catch."
        )
        return 1

    # A column name, then a moment function within the same expression, in
    # either order: `strtotime($x['sessionTime'])` and
    # `$sess['sessionTime']` passed to `new DateTime(...)`.
    names = "|".join(re.escape(c) for c in columns)
    # ⚠️ NO NEWLINE, AND THAT IS THE WHOLE OF FINDING 3. `[^;]` blocks a
    #    semicolon but not a line break, so the search walked out of the call
    #    and across the rest of a multi-line statement -- reporting a date and
    #    a time side by side in one array literal, which is ordinary and is
    #    already the shape of `calendar/feed.php` and
    #    `venues/schedule-pdf.php`. Measured: a probe with
    #    `strtotime($r['sessionDate'])` on one line and
    #    `substr($r['sessionTime'], 0, 5)` on the next was reported as a fault.
    #    A check that cries wolf gets switched off (#483).
    #
    #    A call is also not 200 characters of anything: it stops at the line it
    #    is on, and at the closing bracket or a comma that ends its argument.
    pattern = re.compile(
        MOMENT_FUNCTIONS + r"\s*\([^;\n]{0,200}?\b(?:" + names + r")\b",
        re.IGNORECASE,
    )

    problems: list[str] = []
    seen_allowed: set[str] = set()
    looked_at = 0

    for folder, _, files in os.walk(os.path.join(root, WEB)):
        if "vendor" in folder:
            continue
        for name in sorted(files):
            if name.endswith(SUFFIXES) is False:
                continue
            relative = os.path.relpath(os.path.join(folder, name), root)
            with open(os.path.join(root, relative), "r", encoding="utf-8", errors="replace") as handle:
                raw = handle.read()
            looked_at += 1
            code = blank_comments(php_only(raw))
            hits = []
            for found in pattern.finditer(code):
                # A reading given a date AND a zone together is the correct
                # construction, not a fault -- see CORRECT_WITH_DATE_AND_ZONE.
                if CORRECT_WITH_DATE_AND_ZONE.search(found.group(0)) is not None:
                    continue
                hits.append(code[:found.start()].count("\n") + 1)
            if len(hits) == 0:
                continue
            if relative in ALLOWED:
                seen_allowed.add(relative)
                continue
            problems.append(
                f"{relative} hands a clock-reading column to a moment function "
                f"on line(s) {', '.join(str(h) for h in hits)}.\n"
                f"       A `TIME` column holds a reading from a clock, and a "
                f"time on a clock is not a point in history until something "
                f"gives it a date AND a zone. `strtotime('01:30')` gives it "
                f"today's date in PHP's default zone -- and on the morning the "
                f"clocks go forward that reading does not exist, so PHP moves "
                f"it an hour.\n"
                f"       Measured before this was guarded: a group whose "
                f"meeting time is 01:30 displayed as 2:30 AM on 29 March 2026, "
                f"in four places -- and 01:00 and 01:30 came out as the same "
                f"words.\n"
                f"       Put it into words with "
                f"`Portal\\Core\\I18n::formatClockTime()`, which never builds a "
                f"moment, or slice the text as `web/_apps/rota/index.php` does. "
                f"If it genuinely needs to BE a moment, give it a date and a "
                f"zone together, as `web/_core/Livestream.php` does with "
                f"`createFromFormat('Y-m-d H:i:s', $date . ' ' . $time, $tz)`, "
                f"and add this file to ALLOWED with the reason."
            )

    for relative in sorted(ALLOWED):
        if os.path.isfile(os.path.join(root, relative)) is False:
            problems.append(f"ALLOWED names {relative}, which is not there. Remove the entry.")
            continue
        if relative not in seen_allowed:
            problems.append(
                f"{relative} is in ALLOWED but no longer does this. Remove the "
                f"entry -- a permission nothing uses reads as though the shape "
                f"were still there, and the next person to touch that file will "
                f"think it is allowed."
            )

    # ── The formatter's own body ──────────────────────────────────────────
    formatter_path = os.path.join(root, FORMATTER_FILE)
    if os.path.isfile(formatter_path) is False:
        problems.append(f"{FORMATTER_FILE} is not there, so {FORMATTER_NAME}() cannot be checked.")
    else:
        with open(formatter_path, "r", encoding="utf-8", errors="replace") as handle:
            body = blank_comments(handle.read())
        at = body.find("function " + FORMATTER_NAME)
        if at == -1:
            problems.append(
                f"{FORMATTER_FILE} no longer defines {FORMATTER_NAME}(). It is "
                f"the ONE place a clock reading is put into words; without it "
                f"every page that shows one has to invent its own, which is how "
                f"four of them came to be an hour out."
            )
        else:
            # To the end of the function: the first `}` that closes its `{`.
            depth = 0
            start = body.find("{", at)
            end = len(body)
            i = start
            while i < len(body) and start != -1:
                if body[i] == "{":
                    depth += 1
                elif body[i] == "}":
                    depth -= 1
                    if depth == 0:
                        end = i
                        break
                i += 1
            inside = body[at:end]
            for forbidden in FORMATTER_FORBIDS:
                if forbidden in inside:
                    problems.append(
                        f"{FORMATTER_FILE}: {FORMATTER_NAME}() contains "
                        f"`{forbidden}`, so it is building a moment again.\n"
                        f"       A time on a clock must be put into words from "
                        f"its DIGITS. The moment the function takes a date -- "
                        f"even today's, implicitly -- it inherits that day's "
                        f"clock change, and one morning a year the hour it "
                        f"shows does not exist and PHP moves it.\n"
                        f"       This is pinned as SOURCE because no test "
                        f"running today can catch it: measured, "
                        f"tools/clock-change-selftest.php stayed at 40 of 40 "
                        f"with the old `date('g:i A', strtotime(...))` put back, "
                        f"since the two agree on every ordinary day. That is "
                        f"#549's own trap -- 'a test written in December passes "
                        f"in London whatever the code does'."
                    )

    print(
        f"Clock readings not made moments: {looked_at} PHP file(s) read against "
        f"{len(columns)} TIME column(s) taken from the schema; "
        f"{len(ALLOWED)} allowed on purpose, {len(seen_allowed)} of them still doing it"
    )

    if len(problems) > 0:
        print("")
        for problem in problems:
            print(f"FAIL: {problem}")
        print("")
        print(
            f"{len(problems)} problem(s). An hour wrong in a diary is a "
            f"congregation arriving at a locked building."
        )
        return 1

    print(
        "OK: no PHP under web/ hands a clock-reading column to strtotime() or a "
        f"DateTime constructor, and {FORMATTER_NAME}() builds no moment of its own."
    )
    print(
        "   It reads the column names OUT OF the schema, so a new TIME column is "
        "covered without anybody remembering to add it. It canNOT see a reading "
        "pulled into a variable several lines earlier and converted there -- that "
        "stays a question for a person."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
