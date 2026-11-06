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
across a clock change. THREE blind spots, each stated rather than implied --
and the third was found in round 7 of #777, after this header had said "two"
for three rounds:

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
    all 912 files gains exactly ONE hit -- `_core/Livestream.php:99`, whose `createFromFormat` is split over five lines with its zone on line 102 and is the CORRECT construction -- so nothing live is hidden today; an earlier version of this said "gains no hit".

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
    # The text of the LAST argument, which is where the zone belongs.
    #
    # ⚠️ A TRAILING COMMA IS LEGAL PHP AND MADE THE FIRST VERSION OF THIS CRY
    #    WOLF. `new \DateTimeImmutable($t, $tz,)` has a real zone and an empty
    #    last slot, so reading "the text after the last comma" found nothing
    #    and answered "no zone" -- reporting CORRECT code, which is the
    #    direction that gets a check switched off (#483). Round 5 closed "a
    #    trailing comma read as a zone" and opened its mirror in the same edit.
    #
    #    So the arguments are split and the last NON-EMPTY one is read. A
    #    comment is stripped too, because `new \DateTimeImmutable($t, /* z */
    #    null)` passed as a zone -- measured.
    pieces = []
    depth = 0
    start = call.find("(") + 1
    j = start
    while j < i:
        ch = call[j]
        if ch in "'\"":
            quote = ch
            j += 1
            while j < i:
                if call[j] == "\\":
                    j += 2
                    continue
                if call[j] == quote:
                    break
                j += 1
        elif ch in "([":
            depth += 1
        elif ch in ")]":
            depth -= 1
        elif ch == "," and depth == 0:
            pieces.append(call[start:j])
            start = j + 1
        j += 1
    pieces.append(call[start:i])

    def bare(text: str) -> str:
        text = re.sub(r"/\*.*?\*/", " ", text, flags=re.S)
        text = re.sub(r"//[^\n]*", " ", text)
        # A named argument names the parameter, not the value: `timezone: null`
        # is still null, and `timezone: $tz` is still a zone.
        text = re.sub(r"^\s*[A-Za-z_]\w*\s*:(?!:)", "", text)
        return text.strip()

    filled = [bare(p) for p in pieces if bare(p) != ""]
    if len(filled) <= wanted:
        return False
    tail = filled[wanted].lower()
    if tail in ("null", "(null)", "\\null"):
        return False
    return True

# 📁 Places where this is RIGHT, each with its reason. Fails both ways: an entry
#    that no longer does it fails too, because an allow-list that has stopped
#    describing the code is how the migration harness's exceptions went stale.
ALLOWED: dict[str, str] = {
    # ⚠️ `calendar/manage/save.php` LEFT THIS LIST IN ROUND 10, and the
    #    stale-entry rule is what said so rather than anybody noticing. Its
    #    four `strtotime()` conversions of `$_POST['startDateTime']` now slice
    #    the text instead (see WRITE_PATHS_WITHOUT_MOMENTS above for the two
    #    zones that made it matter), so the file no longer hands a
    #    clock-reading NAME to a moment function at all and an exemption for it
    #    would read as though the shape were still there.
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

# 📁 The files that WRITE a wall-clock column from something posted. Each must
#    build no moment at all: #777 rounds 2, 5, 6 and 8 each found one of them
#    doing it, and round 9 found the newest fix unguarded. Measured when this
#    was written: all three contain zero live moment constructions.
# ⚠️ `calendar/manage/save.php` IS A FIFTH WRITE PATH AND IS NOT ON THIS LIST,
#    which is a stated exception rather than an oversight. Round 10 found it
#    deriving four date parts with `date('j'|'n'|'Y-m-d', strtotime($posted))`,
#    and a date part looks safe to derive that way until it is measured: in
#    TWO zones of 419 the clocks go forward at 23:00, so a reading of
#    23:00-23:59 that night lands on the NEXT DAY. Swept across every IANA
#    zone for 2026-2041 rather than reasoned about --
#
#        America/Nuuk           64 readings, e.g. 2026-03-28 23:00 -> 03-29 00:00
#        America/Scoresbysund   64 readings, same shape
#        every other zone       none
#
#    -- and the cost was not cosmetic: the monthly pattern was stored on the
#    29th, `$firstWanted` then disagreed with the typed start, and the handler
#    UPDATED the event onto the pattern's date while telling the administrator
#    the first date had moved. Every occurrence of that series fell a day late,
#    permanently, and it read as deliberate. All four now slice the text.
#
#    ✅ IT HAS JOINED THE LIST (#777 round 12). This note used to say it could
#    not, because one live `strtotime` remained, formatting a DATE-ONLY value
#    for display. That call was inside the "the first date was moved" sentence,
#    and wording that sentence truthfully replaced it with
#    `I18n::formatClockDateTime()`. Measured with the tokeniser: ZERO moment
#    constructions remain in the file, so the write-path guard applies and is
#    stronger than the three text slices below.
#
#    The old note also CITED A LINE NUMBER, which this repository's own rule
#    forbids, and it had gone stale by line before it went stale by fact.
WRITE_PATHS_WITHOUT_MOMENTS = (
    os.path.join("web", "_apps", "events", "api", "create.php"),
    os.path.join("web", "_apps", "events", "api", "update.php"),
    os.path.join("web", "_apps", "calendar", "submit-save.php"),
    # 🆕 Joined on 6 November 2026 (#777 round 12), and it is the STRONGEST
    #    guard this file has for that page -- it asks PHP whether any moment is
    #    built at all, rather than pinning three expressions as text.
    #
    #    It became eligible as a side effect of wording the moved-date message
    #    truthfully: the last live `strtotime` in the file was inside that
    #    sentence, formatting a date-only value, and it is now
    #    `I18n::formatClockDateTime()`. Measured with the tokeniser, comments
    #    and string literals excluded: ZERO moment constructions remain.
    #
    #    That also retires a "stated exception" three documents carried -- "the
    #    one live `strtotime` left (`save.php:1210`, a DATE-ONLY display
    #    value)". It had ALREADY gone stale by line number before it went
    #    stale by fact: round 11 added 31 lines above it, so 1210 had become
    #    `$dropRule = $mysqli->prepare('DELETE FROM tblRecurrenceRules …')`.
    #    **This repository's own rule is never to cite by line number, and the
    #    commit that moved the line was the one carrying the citation.**
    os.path.join("web", "_apps", "calendar", "manage", "save.php"),
)

# ⚠️ ASK PHP, DO NOT PATTERN-MATCH THE TEXT. Each of those files EXPLAINS the
#    old code in a comment -- `create.php` quotes
#    `(new \DateTimeImmutable($startDateTime, $zone))` verbatim -- so a text
#    search reports the comment that records the fix as the fault. The
#    tokeniser is the only reading that can tell them apart, and this
#    repository has paid for that lesson repeatedly (#763 ended six rounds of
#    regular expressions by asking PHP).
# ⚠️ AND IT IS A LIST, WHICH IS NOT A RULE. Round 9 wrote the four spellings
#    round 8 had used, and round 10 walked through it in ONE WORD:
#    `date_create_immutable($raw)` is PHP's procedural alias for the
#    constructor, so the whole pre-round-8 revert came back with this gate at
#    exit 0, 65 audit scripts green and 95 clock checks green --
#    `01/06/2026 10:00 +0100` storing 6 January again. **This repository's own
#    written lesson, verbatim: "an allow-list written from the spellings you
#    were handed is not a rule."**
#
#    So the list now holds every internal PHP function that returns a
#    DateTime-ish value, enumerated from `get_defined_functions()` rather than
#    from memory, AND the real guard is the text pin below, which catches any
#    spelling at all because it compares the routing itself.
#
#    What the LIST still cannot see, said plainly rather than implied: a
#    variable class name (`$c = '\DateTimeImmutable'; new $c(...)`), a method
#    on an object made elsewhere, and an identifier that merely CONTAINS one of
#    these words. The pin is what covers those for `create.php`'s routing;
#    elsewhere in these files they stay a question for a person.
MOMENT_BUILDERS = re.compile(
    r"strtotime|strptime|createFromFormat|createFromInterface"
    r"|date_create_immutable_from_format|date_create_from_format"
    r"|date_create_immutable|date_create"
    r"|date_parse_from_format|date_parse"
    r"|gmmktime|mktime"
    r"|new\s+\\?DateTimeImmutable\b|new\s+\\?DateTime\b"
)

# 📌 THE REAL GUARD: the routing in `create.php`, pinned as TEXT.
#    A list of spellings is defeated by a spelling nobody thought of; this is
#    defeated by nothing, because it compares the statement itself. It is the
#    remedy #485 reached after four rounds and #763 after six, and round 10 is
#    the third round in a row to find this exact fix unguarded.
#
#    Whitespace is freed so a reformat does not go red; everything else is
#    byte-for-byte.
READING_ROUTER_FILE = os.path.join("web", "_apps", "events", "api", "create.php")
READING_ROUTER = (
    "$readingFor = static function (string $raw, \\DateTimeZone $zone): ?string { "
    "return I18n::inputCarriesOffset($raw) === false "
    "? I18n::wallClockFromInput($raw) "
    ": I18n::instantFromInput($raw, $zone); };"
)


# ⚠️ AND THE SAME FOR THE REPEATING-SERIES RULE (#777 round 11).
#
#    `calendar/manage/save.php` derives a repeating series' day of the month,
#    month of the year and start date FROM THE TEXT of the typed reading. Round
#    10 made them slices; round 11 measured that the slices could be put back
#    to `date('j', strtotime($startReading))` with ALL 65 AUDIT SCRIPTS GREEN,
#    this one included.
#
#    The reason is this check's own stated blind spot: it needs a declared
#    wall-clock COLUMN NAME near the conversion, and `$startReading` is a
#    local variable. **Round 10's own fix created the blind spot its own fix
#    then sat behind** -- before it, the expressions named `$startDateTime`
#    directly and the gate could see them.
#
#    So the guard is not another pattern over column names: it is that in THIS
#    file, `$startReading` may never reach a moment builder, and the three
#    slices must still be there. The harm is not cosmetic -- the pattern was
#    stored on the wrong day, so the series' first date disagreed with the
#    typed start and the handler UPDATED the event onto the pattern's date,
#    telling the administrator it had moved on purpose.
SLICED_RULE_FILE = os.path.join("web", "_apps", "calendar", "manage", "save.php")
SLICED_RULE_PARTS = (
    "substr($startReading, 8, 2)",
    "substr($startReading, 5, 2)",
    "substr($startReading, 0, 10)",
)
# Anything that turns a reading into a moment, with `$startReading` close
# enough to be its argument. Kept to one line and one statement on purpose:
# `[^;\n]` is what stopped an earlier version of this check walking across a
# multi-line statement and crying wolf.
# ⚠️ DERIVED FROM `MOMENT_BUILDERS`, NEVER WRITTEN OUT AGAIN (#777 round 12).
#    The first version of this was a fresh five-spelling list sitting forty
#    lines below the list that exists BECAUSE hand-written spelling lists do
#    not work -- and it was defeated by the two spellings that caused this
#    package's round-7 and round-11 portal faults. Planted into `save.php`:
#
#        $startMoment = DateTimeImmutable::createFromFormat(
#            'Y-m-d H:i:s', $startReading, new DateTimeZone($timezone));
#
#    `php -l` clean, **gate exit 0**, while the constant's own comment above
#    promised "$startReading may never reach a moment builder".
#    `date_create_from_format` walked through as well.
#
#    **"An allow-list written from the spellings you were handed is not a
#    rule" is this repository's own sentence, written in round 10 about this
#    very file -- and round 11 then wrote a new allow-list in it.** Deriving
#    the pattern is the only version that cannot drift from the one list.
#
# ⚠️ AND THE LOOKBEHIND IS NOT A DETAIL. The first version's alternation held
#    a bare `date` with no word boundary, so ANY identifier ending in `date(`
#    matched: `validate($startReading)` and `candidate($startReading)` each
#    made this hard gate FAIL on code that builds no moment, and the failure
#    message quoted a `date($startReading` call that was not on the line.
#    That is the direction that gets a check switched off (#483), and it is
#    live-plausible in a file that already defines `$looksLikeDateTime`.
#    `MOMENT_BUILDERS` does not carry bare `date` at all, because `date()`
#    FORMATS a moment rather than building one -- deriving inherits that
#    judgement instead of second-guessing it.
#
#    Kept to one line and one statement on purpose: `[^;\n]` is what stopped
#    an earlier version of this check walking across a multi-line statement
#    and crying wolf.
SLICED_RULE_FORBIDDEN = re.compile(
    r"(?<![A-Za-z0-9_])(?:" + MOMENT_BUILDERS.pattern + r")"
    r"\s*\([^;\n]{0,160}\$startReading"
)


# 📌 TWO FIXES THAT WERE PINNED BY NOTHING FOR A WHOLE ROUND (#777 round 13).
#
#    Round 12 fixed the moved-date message and the CSV import's silent rolling
#    of an impossible date. Round 13 reverted BOTH and found every gate and
#    every test green: 65 audit scripts, clock-change 100/100, recurrence
#    72/72, time-zones 148/148 and event-repeat-pages 251/251, while
#    `2026-02-31 10:00` imported as 3 March again. **That is the fourth round
#    running to find a headline fix unguarded.**
#
# ⚠️ THESE ARE TEXT PINS, AND D-065 SAYS THAT IS THE SECOND-BEST ANSWER HERE.
#    The property is behavioural -- "does this page say a true thing" and "does
#    this import refuse this cell" -- so the right guard drives the page. No
#    test does: nothing anywhere posts a CSV carrying an impossible date or an
#    offset-bearing cell, and no test drives the create form in a zone whose
#    clocks go forward, so the gap sentence has never been produced by a real
#    request. Raised as its own issue rather than claimed as covered. Until
#    then these at least mean a revert cannot be silent.
#
#    Each is a DISTINGUISHING clause, not the whole sentence, so ordinary
#    rewording stays green and deleting a branch does not.
FLASH_BRANCH_FILE = os.path.join("web", "_apps", "calendar", "manage", "save.php")
FLASH_BRANCHES = (
    ("EventRecurrence::whyFirstDateMoved",
     "the ONE place that decides WHICH of the three things happened. Round 13 "
     "decided it inline on the page and re-implemented it in the test, so a "
     "one-word change there left every gate and all 251 HTTP checks green "
     "(round 14). The page must ASK the shared rule."),
    ("Any later dates keep the time you typed",
     "the clocks-moved-it sentence."),
    ("and its time became",
     "the pattern-moved-it-AND-the-clocks-moved-its-time sentence. Round 12 "
     "had no third branch, so this case was told only 'to match the repeat "
     "you chose' and never heard that the hour moved."),
    ("to match the repeat you chose",
     "the pattern-alone sentence."),
)

# ⚠️ WHAT THESE PINS DO **NOT** MEAN, because the reason given for the last one
#    used to claim otherwise. An earlier version said that wording was "also
#    pinned by `event-repeat-pages-selftest.php`". It is not:
#    `grep -rn "repeat you chose" tools/event-repeat-pages-selftest.php`
#    returns NOTHING. That test's M02 asserts only the DATE substring "first
#    date was moved to 5 October 2028", which every branch produces, so the one
#    HTTP check reaching this code cannot tell the branches apart.
#
#    Telling the next reader that behaviour is covered where it is not is how
#    a text pin gets deleted as redundant. The behavioural gap is #787.

IMPORT_GUARD_FILE = os.path.join("web", "_apps", "calendar", "manage", "import.php")
IMPORT_GUARDS = (
    ("I18n::instantFromInput",
     "an offset-bearing cell is an INSTANT and must be converted, not refused. "
     "Round 12 refused five shapes that had always imported correctly, "
     "`toISOString()` output among them, under a comment of mine claiming "
     "nothing else was narrowed."),
    ("date_parse(",
     "an impossible calendar date must be refused in EVERY spelling. Round "
     "12 guarded it with `checkdate()` on a zero-padded ISO prefix, so "
     "`2026-2-31`, `02/31/2026` and `Feb 31 2026` all still rolled forward "
     "and imported as a success. Asking PHP what it parsed is a rule; a "
     "prefix pattern is a spelling list."),
)


class ProbeUnavailable(Exception):
    """The PHP probe could not run. NOT a finding about the file."""


def live_moment_builders(path: str) -> list[str]:
    """Every moment construction in a file's CODE, comments excluded."""
    import subprocess
    # ⚠️ STRINGS ARE BLANKED AS WELL AS COMMENTS. The tokeniser keeps string
    #    literals, and a perfectly ordinary error message mentioning
    #    `strtotime` would make this gate RED on correct code -- the direction
    #    that gets a check switched off (#483). Measured before adding it: no
    #    string in these three files trips the patterns TODAY, so this is
    #    hardening against a plausible edit rather than a fix for a live fault,
    #    and it costs nothing, because a moment is never built inside a string.
    #    Each literal becomes a run of `x` of the same length, which is this
    #    repository's usual remedy (`gdpr-coverage-selftest.php`), so reported
    #    offsets stay meaningful.
    probe = (
        '$t = token_get_all(file_get_contents($argv[1])); $o = "";'
        'foreach ($t as $x) { if (is_array($x)) {'
        '  if (in_array($x[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }'
        '  if (in_array($x[0], [T_CONSTANT_ENCAPSED_STRING,'
        '      T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {'
        '    $o .= str_repeat("x", strlen($x[1])); continue; }'
        '  $o .= $x[1]; } else { $o .= $x; } } echo $o;'
    )
    try:
        out = subprocess.run(
            ["php", "-r", probe, path],
            capture_output=True, text=True, timeout=30,
        )
    except Exception as problem:
        # ⚠️ REFUSE RATHER THAN PASS, BUT SAY THE RIGHT THING. A check that
        #    answers "nothing wrong" because it could not look is worse than no
        #    check (#763's rule) -- so this still fails closed. The FIRST
        #    version returned its excuse inside the findings list, so with
        #    `php` off the PATH the gate printed three paragraphs asserting
        #    that three correct files build a moment and telling the reader to
        #    fix them. **Round 9's own finding 5 -- a failure message that
        #    sends somebody looking for the wrong thing -- reproduced in the
        #    commit that fixed it** (round 10).
        raise ProbeUnavailable(f"php could not be run: {problem}") from problem
    # ⚠️ `token_get_all()` DOES NOT FAIL ON BROKEN PHP -- it warns and hands
    #    back whatever it managed, and the process still exits 0. So a file
    #    that does not parse came back as "no moments found" and this gate
    #    PASSED on it: the exact "answers nothing wrong because it could not
    #    look" failure the comment above warns against, measured on a
    #    deliberately broken file while writing this. Ask `php -l` first.
    lint = subprocess.run(["php", "-l", path], capture_output=True, text=True, timeout=30)
    if lint.returncode != 0:
        raise ProbeUnavailable(
            "the file does not parse, so its tokens cannot be trusted"
            + (f": {lint.stdout.strip()[:200]}" if lint.stdout.strip() != "" else "")
        )
    if out.returncode != 0:
        raise ProbeUnavailable(
            f"php exited {out.returncode} reading the file"
            + (f": {out.stderr.strip()[:200]}" if out.stderr.strip() != "" else "")
        )
    return MOMENT_BUILDERS.findall(out.stdout)


# 📁 The one display helper nothing drives, pinned below. The NAME is a
#    constant because the failure message names it, and round 9 found that
#    message citing a `REPATTERN_HELPER` that did not exist.
REPATTERN_HELPER = "$showReading"
REPATTERN_FILE = os.path.join("web", "_apps", "calendar", "manage", "series-repattern.php")
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
        # ⚠️ COMPARE THE SETS, NOT THE LENGTHS. The first version of this
        #    refusal tested `len()`, so an EQUAL-SIZED SWAP walked straight
        #    through -- measured in round 6: rewording `startAt`'s comment away
        #    and adding WALL-CLOCK to `heldAtUtc` repinned "6 wall-clock
        #    column(s)", exit 0, and `tblResourceBooking.startAt` -- the column
        #    round 2 fixed three reader pages for -- lost its only guard with
        #    an ordinary run afterwards saying nothing.
        #
        #    That is the exact shape this refusal was added to close, which is
        #    why the length test was worse than none: it carried #697's
        #    citation while leaving the hole.
        if was is not None and len([c for c in was if c not in found_wall]) > 0:
            lost = [c for c in sorted(was) if c not in found_wall]
            print(
                f"FAIL: repinning would DROP {len(lost)} column(s) from the "
                f"pinned set ({len(was)} -> {len(found_wall)}): "
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
            # ⚠️ SAY THE OPPOSITE THING ON THE WAY THROUGH. The first version
            #    printed the FAIL above and then exited 0, so a successful run
            #    read as a failure -- which is how somebody comes to ignore the
            #    word FAIL in this check's output.
            print(
                "   --repin-shrink was given, so the drop above is being "
                "recorded deliberately. Check the diff of the pin file says "
                "what you meant."
            )
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

    # ── The write paths: NO moment may be built in them at all ────────────
    # ⚠️ EVERY ONE OF THESE FIXES WAS REVERTIBLE WITH EVERY GUARD GREEN, round
    #    after round. Round 7 found three write paths pinned by nothing and
    #    closed them with endpoint checks; round 8 found the fourth; round 9
    #    found that round 8's own headline fix -- the one that stopped a date
    #    being stored SIX MONTHS WRONG -- was pinned by nothing either, because
    #    the endpoint checks send well-formed values where the old code and the
    #    new agree. Planted, the pre-round-8 constructor back in `create.php`'s
    #    offset branch left 65 audit scripts, 89 clock checks AND all 143
    #    endpoint checks green while `01/06/2026 10:00 +0100` stored 6 January.
    #
    #    So this stops asking what the code DOES and asks what it CONTAINS,
    #    which for these three files is exact rather than approximate: each one
    #    now routes every reading through `I18n`, so a LIVE moment
    #    construction in any of them is the fault, full stop. Measured before
    #    writing it -- all three contain zero today (comments stripped with
    #    PHP's own tokeniser, because each file EXPLAINS the old code in a
    #    comment and a text search would report those).
    #
    #    It cannot cry wolf on a legitimate change: if one of these handlers
    #    ever genuinely needs a moment, that is a decision worth making
    #    deliberately, and the file comes off this list in the same edit.
    for relative in WRITE_PATHS_WITHOUT_MOMENTS:
        full = os.path.join(root, relative)
        if os.path.isfile(full) is False:
            problems.append(
                f"WRITE_PATHS_WITHOUT_MOMENTS names {relative}, which is not "
                f"there. If it moved, change the entry in the same edit."
            )
            continue
        try:
            built = live_moment_builders(full)
        except ProbeUnavailable as why:
            problems.append(
                f"this check could not READ {relative}, so it proved nothing "
                f"about it: {why}.\n"
                f"       That is a fault in the check's surroundings, NOT in "
                f"the file -- do not go looking for a moment in it. PHP is "
                f"needed because each of these files quotes the old code in a "
                f"comment, so only the tokeniser can tell the comment from the "
                f"code."
            )
            continue
        if len(built) > 0:
            problems.append(
                f"{relative} builds a moment in live code: "
                f"{', '.join(sorted(set(built)))}.\n"
                f"       This file WRITES a wall-clock column, and for a value "
                f"stored as a reading, building a moment is the fault even "
                f"with the correct zone -- on the morning the clocks go forward "
                f"the hour it names does not exist in ANY zone, so PHP moves "
                f"it.\n"
                f"       Every reading here goes through "
                f"`I18n::wallClockFromInput()` (no offset) or "
                f"`I18n::instantFromInput()` (an offset, which really is a "
                f"moment, and which checks the SAME shapes). Use those.\n"
                f"       If this handler genuinely needs a moment of its own, "
                f"take it off WRITE_PATHS_WITHOUT_MOMENTS in the same edit and "
                f"say why -- do not leave the entry and work around it."
            )

    # ── The routing itself, pinned as text ────────────────────────────────
    router_path = os.path.join(root, READING_ROUTER_FILE)
    if os.path.isfile(router_path) is False:
        problems.append(f"{READING_ROUTER_FILE} is not there, so the routing cannot be pinned.")
    else:
        with open(router_path, "r", encoding="utf-8", errors="replace") as handle:
            router_code = blank_comments(handle.read())
        tidy = " ".join(router_code.split())
        wanted = " ".join(READING_ROUTER.split())
        # ⚠️ EVERY USE OF THE NAME IS JUDGED, NOT JUST THE DECLARATION
        #    (#777 round 11). Counting declarations left the pin defeated by a
        #    REASSIGNMENT: planted, the pinned closure was left byte-identical
        #    and `$readingFor = $readFast;` added below it, where `$readFast`
        #    built a moment through a VARIABLE class name. The declaration
        #    still appeared exactly once, the pinned text still matched, and
        #    all 65 audit scripts were green while the whole pre-round-8 harm
        #    was back -- `01/06/2026 10:00 +0100` stored as 6 January again.
        #
        #    This repository had already written that exact class down, about
        #    #485: "A reassignment (`shareToken = function () {...}`) killed
        #    the whole token fix with the gate green, because round 3 had
        #    closed the second-DECLARATION spelling." Round 10 adopted the
        #    text pin as the remedy and reproduced the known hole in it.
        #
        #    So: each occurrence of the name must be either the pinned
        #    declaration or a CALL. There is no third legitimate shape -- the
        #    closure is not passed anywhere, returned, or stored.
        uses = [m for m in re.finditer(r"\$readingFor\b", tidy)]
        stray = []
        for found in uses:
            after = tidy[found.end():found.end() + 2].lstrip()
            before_decl = tidy[found.start():found.start() + len("$readingFor = static function")]
            if before_decl == "$readingFor = static function":
                continue
            if after.startswith("("):
                continue
            stray.append(tidy[max(0, found.start() - 30):found.end() + 30])
        if tidy.count("$readingFor = static function") != 1:
            problems.append(
                f"{READING_ROUTER_FILE} defines $readingFor "
                f"{tidy.count('$readingFor = static function')} time(s); it must "
                f"define it exactly ONCE. A pin that finds its anchor by the "
                f"FIRST match is satisfied by a dead copy above a gutted live "
                f"one -- #485 round 2, #763, and #777 round 9 all record it."
            )
        elif stray != []:
            problems.append(
                f"{READING_ROUTER_FILE} uses $readingFor somewhere that is "
                f"neither its pinned declaration nor a call, so the pin above "
                f"it proves nothing: a REASSIGNMENT leaves the pinned text "
                f"byte-identical and sends every value through other code.\n"
                f"       seen: " + " | ".join(stray[:3])
            )
        elif wanted not in tidy:
            problems.append(
                f"{READING_ROUTER_FILE}'s $readingFor no longer reads exactly "
                f"as pinned.\n"
                f"       wanted: {wanted}\n"
                f"       This closure is the ONE place the handler decides "
                f"whether a posted time is a reading or an instant, and every "
                f"round from 7 to 10 found its predecessor revertible with "
                f"every guard green -- a list of banned spellings is defeated "
                f"by a spelling nobody thought of (`date_create_immutable` did "
                f"it in round 10), so the routing is pinned as TEXT instead.\n"
                f"       If the change is deliberate, update READING_ROUTER in "
                f"the same edit and say why in the commit. Whitespace is free; "
                f"anything else is not."
            )

    # ── The repeating series' rule parts, which the column rule cannot see ─
    sliced_path = os.path.join(root, SLICED_RULE_FILE)
    if os.path.isfile(sliced_path) is False:
        problems.append(f"{SLICED_RULE_FILE} is not there, so the series rule cannot be checked.")
    else:
        with open(sliced_path, "r", encoding="utf-8", errors="replace") as handle:
            sliced_code = blank_comments(handle.read())
        missing = [part for part in SLICED_RULE_PARTS if part not in sliced_code]
        if missing != []:
            problems.append(
                f"{SLICED_RULE_FILE} no longer slices the repeating series' "
                f"date parts out of the typed reading.\n"
                f"       missing: " + ", ".join(missing) + "\n"
                f"       These three decide a series' day of the month, month "
                f"of the year and start date. Derived with a conversion "
                f"instead, they name the wrong day in the two zones whose "
                f"clocks go forward at 23:00 -- the pattern is then stored on "
                f"a day the organiser did not type, the series' first date "
                f"disagrees with it, and the handler moves the event onto the "
                f"pattern's date while telling the administrator that was "
                f"deliberate. Every occurrence is wrong, permanently."
            )
        bad_use = SLICED_RULE_FORBIDDEN.search(sliced_code)
        if bad_use is not None:
            line = sliced_code[:bad_use.start()].count("\n") + 1
            problems.append(
                f"{SLICED_RULE_FILE}:{line} turns $startReading into a moment: "
                f"{bad_use.group(0)[:70]}\n"
                f"       $startReading IS the typed reading. Building a moment "
                f"from it is the fault this whole package is about, and THIS "
                f"check could not see it any other way: it needs a declared "
                f"wall-clock column name near the conversion, and this is a "
                f"local variable, so the ordinary rule above is blind here. "
                f"Round 10 introduced the variable, which is how its own fix "
                f"came to sit behind this check's own stated blind spot."
            )

    # ── Two fixes round 13 found unguarded ────────────────────────────────
    for rel, wanted in ((FLASH_BRANCH_FILE, FLASH_BRANCHES), (IMPORT_GUARD_FILE, IMPORT_GUARDS)):
        full = os.path.join(root, rel)
        if os.path.isfile(full) is False:
            problems.append(f"{rel} is not there, so its guards cannot be checked.")
            continue
        with open(full, "r", encoding="utf-8", errors="replace") as handle:
            code = blank_comments(handle.read())
        for needle, why in wanted:
            if needle not in code:
                problems.append(
                    f"{rel} no longer contains `{needle}` in live code.\n"
                    f"       That is {why}\n"
                    f"       This is a TEXT pin, and the property is really "
                    f"behavioural -- nothing drives it, which is why the pin "
                    f"exists. If the change is deliberate, update the constant "
                    f"in the same edit and say why."
                )

    # ── The one display helper nothing drives ─────────────────────────────
    # ⚠️ ROUND 7's FIX FOR `series-repattern.php` WAS PINNED BY NOTHING.
    #    Planting the pre-round-7 body back -- `createFromFormat('Y-m-d
    #    H:i:s', trim($reading))` with no zone, which really did show a stored
    #    01:30 as 02:30 -- left all 65 audit scripts green, 81 clock checks
    #    green AND `event-repeat-pages-selftest.php` at 251 of 251, because
    #    that test drives the page but never looks at the displayed time
    #    (measured in round 8 of #777).
    #
    #    This check cannot see the fault itself: the reading arrives as a
    #    FUNCTION PARAMETER, which is the third blind spot above. So the
    #    HELPER's body is pinned instead -- D-065's rule, where the property is
    #    "this code looks like this", pin it -- and the one thing it must not
    #    contain is a moment being built. A behavioural check would be better
    #    and belongs with #785; this is what can be done today.
    repattern = os.path.join(root, REPATTERN_FILE)
    if os.path.isfile(repattern) is False:
        problems.append(f"{REPATTERN_FILE} is not there, so $showReading cannot be checked.")
    else:
        with open(repattern, "r", encoding="utf-8", errors="replace") as handle:
            body = blank_comments(handle.read())
        # ⚠️ EXACTLY ONE DEFINITION, AND WHITESPACE IS FREE. Round 9 broke the
        #    first version of this pin two ways, both of them lessons already
        #    written down here:
        #
        #      * `body.find()` takes the FIRST match, so a DEAD BUT CORRECT
        #        copy placed ABOVE the live helper satisfied the pin while the
        #        live one was gutted -- gate exit 0, `php -l` clean, and the
        #        preview screen back to showing 01:30 as 02:30. #485 round 2
        #        and #763 both record this: "the start anchor must appear
        #        exactly ONCE".
        #      * it matched the literal `"$showReading = "`, so ONE EXTRA
        #        SPACE -- the aligned-assignment style this codebase uses
        #        elsewhere -- turned a behaviour-neutral edit into a RED hard
        #        gate, and a check that cries wolf gets switched off (#483).
        #
        #    And its failure message named `REPATTERN_HELPER`, a constant that
        #    did not exist, so the one sentence somebody acts on sent them
        #    looking for nothing. It exists now.
        # ⚠️ ANY LEGITIMATE CLOSURE SPELLING. Round 9 required `static
        #    function` -- the spelling it happened to be shown -- and round 10
        #    turned the gate RED by writing `= function` instead, which is
        #    behaviour-neutral and used 14 times elsewhere under `web/`
        #    (`grep -rn "= function (" web/_apps web/_core | wc -l`). Worse,
        #    the message then said the helper "was renamed or removed" when it
        #    was on the line just read. **Round 9's own finding 5, one
        #    condition over**, and crying wolf gets a check switched off (#483).
        HELPER_SHAPE = r"\s*=\s*(?:static\s+)?(?:function|fn)\b"
        definitions = re.findall(re.escape(REPATTERN_HELPER) + HELPER_SHAPE, body)
        if len(definitions) != 1:
            problems.append(
                f"{REPATTERN_FILE} defines {REPATTERN_HELPER} "
                f"{len(definitions)} time(s) as a closure; it must define it "
                f"exactly ONCE.\n"
                f"       At zero: it was renamed, removed, or written in a "
                f"shape this pin does not recognise (it accepts "
                f"`function`, `static function` and `fn`) -- change "
                f"REPATTERN_HELPER here in the same edit, or take this pin out "
                f"with the helper.\n"
                f"       At two or more: a pin that finds its anchor by the "
                f"FIRST match is satisfied by a dead copy above a gutted live "
                f"one, which is how this pin was defeated in round 9 of #777 "
                f"(and #485 and #763 before it)."
            )
        else:
            found = re.search(re.escape(REPATTERN_HELPER) + HELPER_SHAPE, body)
            at = found.start() if found is not None else -1
            end = body.find("};", at)
            helper = body[at:end if end != -1 else at + 600]
            if "I18n::formatClockDateTime" not in helper:
                problems.append(
                    f"{REPATTERN_FILE}'s $showReading no longer calls "
                    f"I18n::formatClockDateTime(). That helper shows a stored "
                    f"reading on the pattern-change preview -- the one screen "
                    f"whose purpose is comparing a date's current time with "
                    f"its new one -- and any other way of turning the reading "
                    f"into words builds a moment, which moves it on the "
                    f"clock-change morning (#777 round 7)."
                )
            # ⚠️ TAKE THE ALLOWED CALL OUT FIRST. `formatClockDateTime`
            #    CONTAINS the word `DateTime`, so the first version of this
            #    loop reported the correct code as a fault -- crying wolf in
            #    the check written to stop crying wolf (#483).
            rest = helper.replace("I18n::formatClockDateTime", "")
            for banned in ("strtotime", "createFromFormat", "DateTime"):
                if banned in rest:
                    problems.append(
                        f"{REPATTERN_FILE}'s $showReading contains "
                        f"`{banned}`. It must build no moment at all: a stored "
                        f"01:30 was shown as 02:30 that way (#777 round 7), "
                        f"and nothing drives this cell's content, so this pin "
                        f"is the only guard there is."
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
        "   THREE things it canNOT see, and every one has hidden a real "
        "fault:\n"
        "     * a reading pulled into a VARIABLE several lines earlier and "
        "converted there -- two faults, in the attendance API;\n"
        "     * a WRITE path, which reads no column at all, so the whole class "
        "of 'store the moved hour' is invisible here -- FOUR faults, every one "
        "found by eye (#785);\n"
        "     * a reading reaching a conversion through a function PARAMETER, "
        "which defeats this check and the one-hop sweep in DEV_NOTES alike.\n"
        "   Each stays a question for a person, and a stated blind spot is a "
        "place to go looking rather than a place to stop. (This line named only "
        "the first for three rounds after the other two were found.)"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
