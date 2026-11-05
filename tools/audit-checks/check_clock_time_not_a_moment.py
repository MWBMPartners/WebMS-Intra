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
gives it a date AND a zone.** `strtotime('01:30')` gives it both without being
asked: today's date, in PHP's default zone.

**AND THE ZONE IS NOT THE FAULT, which an earlier version of this header got
wrong.** It said PHP's default zone "has nothing to do with the organisation's".
It usually IS the organisation's: `web/_core/bootstrap.php` runs
`date_default_timezone_set(Site::timezone())` during start-up, so on any page
reached through the router the two are the same. **The fault is the DATE** --
today's, silently -- because on the one morning a year when the hour the reading
names does not exist in that zone, PHP moves it. The distinction matters: the
reason given for a fix is what the next reader checks the design against, and
"the zone is wrong" would send them to change the zone.

(The claim IS true for a scheduled job walking organisations with
`Site::forceContext()`, which does not reset PHP's zone -- but none of the sites
this check was written for is such a job.)

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
  * **A `DATETIME` whose comment does NOT say which kind of value it holds.**
    A `DATETIME` that declares itself a WALL-CLOCK reading in its own column
    comment IS swept, exactly like a `TIME` column, because the schema has then
    said the thing that makes the question answerable. **SIX do today**, and
    an earlier version of this paragraph said two -- naming only the pair
    migration 252 declared and omitting the four the package's own backlog is
    about, which is the wrong answer in the one place that defines the rule.
    They are `tblResourceBooking.startAt` and `endAt` (migration 252),
    `tblEvents.startDateTime` and `endDateTime`, and `tblEventSnapshots`'
    `snapStart` and `snapEnd`. The authoritative list is
    `clock_wall_clock_columns.pinned.txt`, which is pinned for the reason given
    beside `PIN_FILE`; do not count them from this prose. Of the 486
    time-shaped columns in the schema, 361 carry no comment at all, so for most
    of them the question is still open -- that is the bulk of #777.

  * **`DATETIME` and `DATE` columns that say nothing.** A `DATETIME` may hold a
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
moment function **by name, on one line**. It does NOT mean the portal is correct
across a clock change. Two blind spots, both stated rather than implied:

  * **A reading pulled into a variable several lines earlier** and converted
    there. **A stated blind spot is a place to go looking, not a place to
    stop** -- and it has yielded a real fault twice now, so it is SWEPT
    MECHANICALLY rather than by eye. The attendance API's
    `createFromFormat('H:i', $timeRaw)` in `create.php` and `update.php` came
    from here. A one-hop sweep over all 912 files today finds four sites: two
    faults on the part-two backlog (`_core/Newsletter.php:682`,
    `calendar/zoom-create.php:94`) and two that are CORRECT, each passing a
    real zone deliberately -- `_core/Venues.php:3590` with the venue's own
    zone, and `_core/Events.php:590`, which compares one reading in two zones
    on purpose to detect an old-form migration. The sweep command is in
    DEV_NOTES; run it rather than trusting this snapshot.

  * **A WRITE PATH, which reads no column at all, and this is the worst of
    them.** This check matches a column being READ. A handler that builds its
    value from `$_POST`, converts it, and INSERTs it never names the column
    anywhere near the conversion -- so the entire class of "store the moved
    hour" is invisible here. **Four review rounds missed
    `calendar/submit-save.php` for exactly that reason**: a public form whose
    01:30 submission was stored for ever as 02:30, found by reading the FORM
    rather than by any check. `resources/book.php` was the same class and was
    also found by eye. Both are fixed. A guard for the class is #785; until it
    exists, the fingerprint worth grepping is
    `date('Y-m-d H:i:s', ... strtotime ...)` applied to anything that is not a
    relative string.
  * **A call split over lines.** The search stops at a newline, which is what
    stopped it crying wolf over a date and a time side by side in one array
    literal — so

          $a = strtotime(
              (string) $row['sessionTime']
          );

    is missed. Re-running the pattern without the newline restriction across
    all 912 files gains exactly ONE hit -- `_core/Livestream.php:99`, whose `createFromFormat` carries its zone on the next line and is the CORRECT construction -- so nothing live is hidden today; an earlier version of this said "gains no hit".

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
# ⚠️ THE LEADING BACKSLASH IS NOT OPTIONAL. Leaving it out meant this check
#    missed a spelling that is live in this codebase -- `attendance/api/
#    create.php`, one of the files this package fixed, writes
#    `\DateTimeImmutable`. Planted,
#    `new \DateTimeImmutable((string) $row['meetingTime'])` passed.
#
#    **An earlier version of this comment called it the MAJORITY spelling, "87
#    against 79", and that was wrong: those figures counted `DateTimeZone` as
#    well.** For the class this sentence is about:
#
#        grep -rEo 'new[[:space:]]+\\DateTime(Immutable)?[^A-Za-z0-9_]' web | wc -l   # 51
#        grep -rEo 'new[[:space:]]+DateTime(Immutable)?[^A-Za-z0-9_]'   web | wc -l   # 55
#
#    so the backslashed form is the MINORITY. Allowing it is still right --
#    fifty-one places is not a corner case, and the check's own message
#    promises it catches "a DateTime constructor". But the evidence given for
#    it was not what the command returned, in a commit whose own stated lesson
#    is "write what the command returned, and quote the command". **A word
#    boundary is not a detail when you are counting.**
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
# same call, as `web/_core/Livestream.php` does.
#
# ⚠️ IT MUST REQUIRE THE ZONE, and the first version only required the DATE —
#    so `createFromFormat('Y-m-d H:i:s', $date . ' ' . $clock)` with no third
#    argument was exempt, while the constant's own name said "AND a zone". That
#    construction resolves in PHP's DEFAULT zone, which is the #777 fault in
#    exactly the form the exemption claimed to have excluded. No live instance
#    names a TIME column today, so it was a hole in a tripwire rather than a
#    missed fault — but an exemption whose name overstates what it checks is
#    how the next reader is misled.
#
#    A zone is "a third argument", recognised by a second comma at the call's
#    own bracket depth. The format string's own commas are inside quotes and
#    the date expression's are inside its own brackets, so depth is what tells
#    them apart.
CORRECT_WITH_DATE_AND_ZONE = re.compile(
    r"createFromFormat\s*\(\s*['\"][^'\"]*[Yy][^'\"]*['\"]"
)


# ⚠️ THE CONSTRUCTOR FORM IS EQUALLY CORRECT, AND THIS CHECK USED TO REFUSE
#    IT. `new DateTimeImmutable($date . ' ' . $time, $tz)` gives the reading a
#    date and a zone in one call, exactly as the `createFromFormat` form does,
#    and only the latter was recognised -- so the gate reported CORRECT code
#    and told its author to rewrite it or add the file to ALLOWED. Three live
#    files use that spelling and were REPORTED for it:
#    `calendar/event-attendance.php`, `calendar/event.php` and
#    `events/api/create.php`. Two of those three went into the part-two backlog
#    as work to do because of it, which is also what made the "28 files" count
#    wrong.
#
#    ⚠️ AN EARLIER VERSION OF THIS SENTENCE NAMED `_core/Venues.php` AS THE
#       THIRD AND LEFT `events/api/create.php` OUT, and that was wrong in a way
#       worth keeping. `Venues.php` DOES use the spelling (3590, 3596) -- but
#       it reads the column into a variable on an earlier line, so this check
#       NEVER SAW IT and never reported it. It could not have been "told to
#       rewrite", and it was never on the backlog. It is a fact about the blind
#       spot, not a file this fix rescued, and mixing the two overstated what
#       the fix did. **A check that cries wolf gets switched off
#    (#483)**, and this one was crying wolf and miscounting its own backlog in
#    one breath.
CONSTRUCTOR_CALL = re.compile(r"new\s+\\?DateTime(?:Immutable)?\s*\(")


def has_a_zone_argument(call: str, wanted: int = 2) -> bool:
    """
    Whether a `createFromFormat(` call hands over a third argument.

    Counted at the call's own bracket depth: a comma inside the format string
    or inside the date expression's own brackets is not an argument separator.

    `wanted` is how many commas mean the zone is handed over:
      * 2 for `createFromFormat(format, value, zone)`
      * 1 for `new DateTimeImmutable(value, zone)` -- no format string in front

    ⚠️ COUNTING THE SEPARATOR IS NOT ENOUGH, AND ONE WORD DEFEATED THE FIRST
       VERSION OF THIS. `new DateTimeImmutable($clock, null)` has the comma and
       no zone: `null` is legal and means PHP's DEFAULT zone, which is the very
       thing this check exists to forbid. Measured --

           php -r 'date_default_timezone_set("Europe/London");
                   echo (new DateTimeImmutable("2026-03-29 01:30", null))
                         ->format("Y-m-d H:i:s P");'
           2026-03-29 02:30:00 +01:00

       -- so the whole #777 fault, with the hard gate green. An explicit
       `(null)` is also one of the shapes that already walked through #763's
       guard, so this repository had paid for the lesson once already.

       The last argument is read now, not merely counted. What this still
       cannot see, said plainly: a VARIABLE that happens to hold null
       (`$tz = null; new DateTimeImmutable($clock, $tz)`). That needs to know
       what a variable holds, which is not a question a source-reading check
       answers -- D-065's line.
    """
    depth = 0
    commas = 0
    i = call.find("(")
    if i == -1:
        return False
    while i < len(call):
        ch = call[i]
        if ch in "'\"":
            quote = ch
            i += 1
            while i < len(call):
                if call[i] == "\\":
                    i += 2
                    continue
                if call[i] == quote:
                    break
                i += 1
        elif ch in "([":
            depth += 1
        elif ch in ")]":
            depth -= 1
            if depth == 0:
                break
        elif ch == "," and depth == 1:
            commas += 1
        i += 1
    if commas < wanted:
        return False
    # The text of the LAST argument, which is where the zone belongs. `i` is
    # sitting on the call's closing bracket, and `last_comma` is the separator
    # in front of that argument.
    last_comma = call.rfind(",", 0, i)
    if last_comma == -1:
        return False
    tail = call[last_comma + 1:i].strip()
    if tail == "" or tail.lower() in ("null", "(null)", "\\null"):
        return False
    return True

# 📁 Places where this is RIGHT, each with its reason. Fails both ways: an entry
#    that no longer does it fails too, because an allow-list that has stopped
#    describing the code is how the migration harness's exceptions went stale.
ALLOWED: dict[str, str] = {
    # ⚠️ A LOCAL VARIABLE MAY SHARE A COLUMN'S NAME, and this check matches a
    #    NAME. `$startDateTime` here is `trim($_POST['startDateTime'] ?? '')`
    #    (line 93) -- a value somebody typed into the form, never read from
    #    `tblEvents` -- so the rule about wall-clock columns does not apply to
    #    it at all.
    #
    #    And all four conversions extract only a DATE PART (`'Y-m-d'`, `'j'`,
    #    `'n'`), which a moved hour cannot change: on 29 March 2026 a posted
    #    `01:30` becomes `02:30`, the same day, month and year. So there is
    #    nothing to fix, which is why this is ALLOWED rather than on the
    #    part-two list. It was ON that list until round 4 of #777 pointed out
    #    that a backlog entry asserts there is work to do.
    os.path.join("web", "_apps", "calendar", "manage", "save.php"):
        "$startDateTime is $_POST['startDateTime'], not a column read, and every "
        "conversion of it takes a date part only, which a moved hour cannot change.",
}

# ── The backlog, one path per line, in a file beside this one ──────────────
# Four `DATETIME` columns declared themselves WALL-CLOCK readings long before
# #777 began, and nothing read that declaration. 28 files put one of them
# through a moment function. Fixing them is #777 part two; listing them here is
# what stops a TWENTY-NINTH joining them in the meantime.
#
# It fails BOTH ways: a file not on the list that does this fails, and a listed
# file that has stopped doing it fails too, so the list cannot go stale in
# either direction. `--strict` fails while it holds anything at all, which is
# how the backlog is made visible rather than permanent (the
# `site_aware_addresses_pending.txt` pattern, #536).
#
# ⚠️ AND NOTHING RUNS `--strict`, which is as designed but must not be assumed
#    otherwise. `pr-security.yml` runs this check with no arguments, and
#    `.githooks/pre-push` globs the folder with no arguments, so the
#    backlog-is-empty mode never fires by itself. It is there for the person
#    who finishes part two to run once, as the proof that it IS finished.
PENDING_FILE = os.path.join("tools", "audit-checks", "clock_wall_clock_datetime_pending.txt")

# ⚠️ THE FOUR COLUMNS THE BACKLOG IS ABOUT, NAMED RATHER THAN DERIVED -- AND
#    ROUND 4 DERIVED THEM, WHICH LEFT A HOLE EXACTLY WHERE ITS OWN NOTE SAID
#    THERE WAS NONE. Round 4 narrowed the exemption from forgiving a FILE to
#    forgiving a COLUMN, and then forgave every column in `wall_clock` -- all
#    SIX declared wall-clock columns, not the four this backlog exists for. So
#    `tblResourceBooking.startAt` and `endAt` -- the pair migration 252
#    declared, and the pair round 2 fixed three reader pages for -- were
#    forgiven in all 25 listed files. Planted: one line in the listed
#    `venues/booking.php` --
#    `date('g:i A', strtotime($ev['startAt']))` -- left the gate GREEN with its
#    output byte-identical.
#
#    Three statements were untrue at once, and all three were the note
#    describing the narrowing: the pending file's "IT FORGIVES THESE FOUR
#    COLUMNS ... AND NOTHING ELSE", this check's success message, and the code
#    comment at the exemption itself. **The narrowing was right and the note
#    overstated it, and the overstatement is precisely where the hole was** --
#    round 4's own finding 1, one level on.
#
#    So the four are written down. A fifth column declaring itself WALL-CLOCK
#    is NOT forgiven anywhere, which is the right default: a new column's
#    readers should be correct from the start, and the pin makes its arrival
#    visible.
BACKLOG_COLUMNS = (
    "startDateTime",   # tblEvents
    "endDateTime",     # tblEvents
    "snapStart",       # tblEventSnapshots
    "snapEnd",         # tblEventSnapshots
)


# ── The wall-clock `DATETIME` columns, and why the SET is pinned ───────────
# ⚠️ WITHOUT THE PIN, ROUND 3's WHOLE FIX RESTS ON AN ENGLISH WORD IN A COMMENT
#    THAT NOTHING WATCHES. Planted: rewording ONLY
#    `tblResourceBooking.startAt`/`endAt`'s comment from "WALL-CLOCK local to
#    the organisation" to "Local clock reading for the organisation" -- a
#    plausible documentation tidy -- AND putting the old fault back into
#    `my-bookings.php` left EVERY check green, this one included, while the
#    column count silently fell from 17 to 15 and the three reader pages lost
#    their only guard.
#
#    The remedy is the one this repository settled on in #485 and #763 after
#    four and six rounds of cleverer patterns: PIN THE SET, and regenerate the
#    pin with an explicit `--repin` so a change is a visible act rather than a
#    side effect. Changing a column's comment is fine; doing it without
#    noticing that a guard reads it is not.
#
#    What the pin does NOT do, said plainly: it pins the NAMES, not the
#    comments. A renamed column fails here, and a column whose comment stops
#    saying WALL-CLOCK fails here. A column whose comment is reworded but still
#    says WALL-CLOCK passes, which is right -- the word is the contract.
PIN_FILE = os.path.join("tools", "audit-checks", "clock_wall_clock_columns.pinned.txt")


def wall_clock_datetime_columns(schema: str) -> list[str]:
    """
    Every `DATETIME` whose own column comment declares it a WALL-CLOCK reading.

    `TIME` is unambiguous -- there is no such thing as a `TIME` column holding
    a moment -- which is why the sweep ignores a `DATETIME` that says nothing.
    A `DATETIME` that SAYS it holds a reading from a clock is as unambiguous as
    a `TIME` column, so it belongs in the sweep.
    """
    found = []
    for match in re.finditer(
        r"`([A-Za-z0-9_]+)`\s+DATETIME\b[^,\n]*?COMMENT\s+'([^']*)'", schema
    ):
        if "WALL-CLOCK" not in match.group(2).upper():
            continue
        name = match.group(1)
        if name not in found:
            found.append(name)
    return found


def pinned_wall_clock(root: str) -> list[str] | None:
    """The recorded set, or None when the file is not there."""
    path = os.path.join(root, PIN_FILE)
    if os.path.isfile(path) is False:
        return None
    out = []
    with open(path, "r", encoding="utf-8") as handle:
        for line in handle:
            line = line.strip()
            if line == "" or line.startswith("#"):
                continue
            out.append(line)
    return out


def pending_list(root: str) -> list[str]:
    """The paths still allowed to do this, read from the file beside this one."""
    path = os.path.join(root, PENDING_FILE)
    if os.path.isfile(path) is False:
        return []
    out = []
    with open(path, "r", encoding="utf-8") as handle:
        for line in handle:
            line = line.strip()
            if line == "" or line.startswith("#"):
                continue
            out.append(line)
    return out

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

    # The wall-clock `DATETIME` columns are read by their own function above,
    # so the SET can be named and pinned -- see PIN_FILE and finding 2 of
    # round 4.
    for name in wall_clock_datetime_columns(schema):
        if name not in found:
            found.append(name)
    return found


def php_only(text: str) -> str:
    """Everything inside `<?php ... ?>` or `<?= ... ?>`, with the rest blanked."""
    out = []
    i = 0
    n = len(text)
    while i < n:
        # ⚠️ THE EARLIEST OF THE TWO, NEVER `<?php` FIRST AND `<?=` AS A
        #    FALLBACK. The old code looked for `<?php` and only tried `<?=`
        #    when no `<?php` remained, so EVERY short-echo block followed
        #    anywhere later in the file by a `<?php` block was blanked.
        #    Planted and measured: a `<?= date('H:i', strtotime(
        #    $r['sessionTime'])) ?>` line between two `<?php` blocks left this
        #    check green, with `'strtotime' in php_only(raw)` False.
        #
        #    No live instance today -- `grep -rl '<?=' web/_apps web/_core`
        #    answers one file and that one is inside a docblock -- so this was
        #    a hole in the guard rather than a fault on a page. But `<?=` is
        #    ordinary PHP and nothing in the house rules forbids it, so the
        #    first page written that way would have been unguarded.
        candidates = [p for p in (text.find("<?php", i), text.find("<?=", i)) if p != -1]
        start = min(candidates) if len(candidates) > 0 else -1
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
        schema_text = handle.read()
    columns = clock_columns(schema_text)
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
    wall_clock = set(wall_clock_datetime_columns(schema_text))
    # The matched column name is CAPTURED, because the backlog is forgiven per
    # COLUMN and not per file -- see finding 1 of round 4 below.
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
        MOMENT_FUNCTIONS + r"\s*\([^;\n]{0,200}?\b(" + names + r")\b",
        re.IGNORECASE,
    )

    problems: list[str] = []
    seen_allowed: set[str] = set()
    pending = pending_list(root)
    seen_pending: set[str] = set()
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
                # Exempt only when BOTH are true: the format string carries a
                # date part AND a zone is handed over. The whole call is needed
                # to see the third argument, not just the matched prefix.
                whole = code[found.start():found.start() + 400]
                if CORRECT_WITH_DATE_AND_ZONE.search(found.group(0)) is not None:
                    if has_a_zone_argument(whole, 2) is True:
                        continue
                # The constructor form is correct on the same terms: a value
                # and a zone in one call. ONE comma, because there is no format
                # string in front of it.
                if CONSTRUCTOR_CALL.match(found.group(0)) is not None:
                    if has_a_zone_argument(whole, 1) is True:
                        continue
                hits.append((code[:found.start()].count("\n") + 1, found.group(1)))
            if len(hits) == 0:
                continue
            if relative in ALLOWED:
                seen_allowed.add(relative)
                continue
            # ── The backlog forgives a COLUMN, never a FILE ────────────────
            # ⚠️ IT USED TO FORGIVE THE FILE, WHICH SWITCHED PART ONE'S OWN
            #    RULE OFF IN 28 OF 912 FILES. Planted: one extra line in
            #    `calendar/event.php`, a listed file --
            #    `date('g:i A', strtotime($event['meetingTime']))`, a brand-new
            #    part-ONE fault on a `TIME` column -- left this check GREEN
            #    with its output unchanged. Two of the 28 already handle `TIME`
            #    columns (`attendance/record.php`, and `venues/booking.php`,
            #    which is a booking form with four of them); both are correct
            #    today, so nobody was shown a wrong hour, but they had left the
            #    guard entirely.
            #
            #    The pending file's own header promised "a file not on it that
            #    does this fails the check, so the hole closes behind us" --
            #    the #645 shape, a note stating the guarantee the code had
            #    forgotten to implement, written in the same commit by the same
            #    hand.
            #
            #    So a hit is forgiven only when the column it matched is one
            #    of `BACKLOG_COLUMNS` -- the FOUR this backlog is about, named
            #    there rather than derived from the six declared wall-clock
            #    columns. Round 4 derived them, which forgave
            #    `tblResourceBooking.startAt`/`endAt` in all 25 files while
            #    this comment said "nothing else"; see BACKLOG_COLUMNS. A
            #    `TIME`-column hit in a listed file still fails, and so now
            #    does a `startAt` one.
            if relative in pending:
                forgiven = [h for h in hits if h[1] in BACKLOG_COLUMNS]
                hits = [h for h in hits if h[1] not in BACKLOG_COLUMNS]
                if len(forgiven) > 0:
                    seen_pending.add(relative)
                if len(hits) == 0:
                    continue
            problems.append(
                f"{relative} hands a clock-reading column to a moment function "
                f"on line(s) "
                f"{', '.join(str(h[0]) + ' (' + h[1] + ')' for h in hits)}.\n"
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

    for relative in sorted(pending):
        if os.path.isfile(os.path.join(root, relative)) is False:
            problems.append(
                f"{PENDING_FILE} names {relative}, which is not there. Take the "
                f"line out."
            )
            continue
        if relative not in seen_pending:
            problems.append(
                f"{relative} is on the #777 part-two list but no longer hands a "
                f"wall-clock column to a moment function. Take the line out -- "
                f"a list that keeps names of work already done is how the "
                f"migration harness's exception list went stale unnoticed, and "
                f"it hides how much is really left."
            )

    # ── The pinned set of wall-clock columns (finding 2 of round 4) ───────
    pin_path = os.path.join(root, PIN_FILE)
    found_wall = sorted(wall_clock)
    if "--repin" in sys.argv:
        # ⚠️ REFUSE BEFORE WRITING, which is #697's lesson about exactly this
        #    shape: a `--repin` that writes the fault into the sidecar and
        #    prints success is the one way a text pin fails silently. Measured
        #    on the first version: with every `WALL-CLOCK` comment reworded
        #    away, `--repin` wrote a pin holding ZERO columns and printed
        #    "Repinned 0 wall-clock column(s)", exit 0.
        #
        #    Today an ordinary run would still go red afterwards, through the
        #    stale-entry rule on the backlog -- but that protection goes the
        #    day the backlog is emptied, which is precisely when somebody will
        #    be editing these comments.
        was = pinned_wall_clock(root)
        if len(found_wall) == 0:
            print(
                "FAIL: the schema declares NO `DATETIME` column as a "
                "WALL-CLOCK reading, so there is nothing to pin. Repinning an "
                "empty set would record the fault and report success. Check "
                "that full_schema.sql still carries those column comments."
            )
            return 1
        if was is not None and len(found_wall) < len(was):
            lost = [c for c in sorted(was) if c not in found_wall]
            print(
                f"FAIL: repinning would SHRINK the pinned set from "
                f"{len(was)} to {len(found_wall)}, dropping: "
                f"{', '.join(lost)}.\n"
                f"       Each dropped column silently removes this check's "
                f"only guard over every page that reads it. If that is really "
                f"intended -- a column removed from the schema, or one that "
                f"genuinely never held a clock reading -- pass --repin-shrink "
                f"as well, so the intent is in the command and not only in the "
                f"diff."
            )
            if "--repin-shrink" not in sys.argv:
                return 1
        with open(pin_path, "w", encoding="utf-8") as handle:
            handle.write(
                "# The `DATETIME` columns whose own comment in full_schema.sql\n"
                "# declares them WALL-CLOCK readings, as\n"
                "# check_clock_time_not_a_moment.py reads them.\n"
                "#\n"
                "# Regenerated ONLY by `--repin`, deliberately, so that changing\n"
                "# which columns a guard covers is a visible act and not a side\n"
                "# effect of rewording a comment. Round 4 of #777 measured the\n"
                "# alternative: rewording two comments took the set from 6 to 4\n"
                "# and every check stayed green with a real fault live.\n"
                "#\n"
                "# One name per line.\n"
            )
            for name in found_wall:
                handle.write(name + "\n")
        print(f"Repinned {len(found_wall)} wall-clock column(s) into {PIN_FILE}.")
        return 0

    pinned = pinned_wall_clock(root)
    if pinned is None:
        problems.append(
            f"{PIN_FILE} is not there, so nothing pins WHICH `DATETIME` columns "
            f"this check sweeps -- and that set is read from an English word in "
            f"a schema comment. Run this check with --repin to record it."
        )
    elif sorted(pinned) != found_wall:
        gone = [c for c in sorted(pinned) if c not in found_wall]
        extra = [c for c in found_wall if c not in pinned]
        problems.append(
            f"the wall-clock `DATETIME` columns have changed.\n"
            f"       pinned:  {', '.join(sorted(pinned)) or '(none)'}\n"
            f"       schema:  {', '.join(found_wall) or '(none)'}\n"
            + (f"       no longer declared WALL-CLOCK: {', '.join(gone)}\n" if gone else "")
            + (f"       newly declared WALL-CLOCK: {', '.join(extra)}\n" if extra else "")
            + f"       A column dropping OUT of this set silently removes this "
              f"check's only guard over every page that reads it -- measured in "
              f"round 4 of #777, where rewording two comments left every check "
              f"green with a real fault live. If the change is intended, run "
              f"this check with --repin, which is meant to show up in a diff."
        )

    if "--strict" in sys.argv and len(pending) > 0:
        problems.append(
            f"{len(pending)} file(s) are still on the #777 part-two list in "
            f"{PENDING_FILE}. --strict is for the day that list is empty."
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
        # ⚠️ THE BRACKET IS NOT COSMETIC. Without it, a function added above
        #    this one whose name merely STARTS with the same text -- say
        #    `formatClockTimeRange()` -- would be found first and this check
        #    would pin the WRONG body, reporting a clean result about a
        #    function it had not read.
        at = body.find("function " + FORMATTER_NAME + "(")
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
        f"{len(columns)} clock-reading column(s) taken from the schema "
        f"(every `TIME`, plus every `DATETIME` whose comment says WALL-CLOCK); "
        f"{len(ALLOWED)} allowed on purpose ({len(seen_allowed)} still doing it), "
        f"{len(pending)} waiting on #777 part two ({len(seen_pending)} still doing it)"
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
        "   It reads the column names OUT OF the schema -- every `TIME` column, "
        "and every `DATETIME` whose own comment declares it a WALL-CLOCK "
        "reading -- so a new one is covered the day it is declared, without "
        "anybody remembering to add it here.\n"
        f"   It does NOT yet mean every wall-clock column is read correctly: "
        f"{len(pending)} file(s) are listed in {PENDING_FILE} as #777 part "
        f"two's backlog, and this run accepted {len(BACKLOG_COLUMNS)} named "
        f"columns ({', '.join(BACKLOG_COLUMNS)}) in those files -- nothing "
        f"else, and that sentence is now true: it forgave all six declared "
        f"wall-clock columns until round 5. The number is printed rather than written "
        f"down, because the first version of this sentence said 28 and would "
        f"have become untrue the day part two fixed one file.\n"
        "   It canNOT see a reading "
        "pulled into a variable several lines earlier and converted there -- that "
        "stays a question for a person."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
