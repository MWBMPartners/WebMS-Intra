#!/usr/bin/env python3
"""
A reading from a clock must not be STORED after going through a moment (#785).

Path: tools/audit-checks/check_wall_clock_writes_not_moments.py

WHY THIS EXISTS
---------------
`check_clock_time_not_a_moment.py` catches a clock column being READ and
handed to something that wants a moment. It cannot see the same mistake on
the way IN, and that is the worse direction: a reader shows a wrong hour until
somebody fixes it, a writer is wrong FOR EVER, because every later reader
faithfully shows what was stored.

It cost this portal twice, and both were found by eye, never by a check:

  * `web/_apps/resources/book.php` (before `04a6226e`) -- a room asked for
    01:00-01:30 on 29 March 2026 was STORED as 02:00-02:30.
  * `web/_apps/calendar/submit-save.php` (before `05a8097e`) -- the PUBLIC
    event form. An event submitted for 01:30 that morning was stored as 02:30.
    Four review rounds missed it.

In both, a posted value went through `strtotime()`, was formatted back with
`date('Y-m-d H:i:s', ...)` several lines later, and was bound into an INSERT
that named the column fifty lines further on. On the morning the clocks go
forward the hour the value names does not exist, so PHP moves it.

WHAT IT CHECKS
--------------
It FOLLOWS THE VALUE, not an idiom. #785 opened with a same-line idiom as its
specification and its own comments measured that wrong in all three parts --
it would have caught NEITHER fault, because the conversion and the format are
seven and fifteen lines apart. So, with PHP's own tokeniser
(`wall-clock-writes-probe.php`), inside one function at a time:

  1. a moment is built from something WRITTEN -- a moment function whose value
     argument holds a variable;
  2. that moment is formatted back into text;
  3. that text is handed to a statement in the place that FILLS a clock
     column -- each `?` matched to its column (an INSERT's column list against
     its VALUES, an UPDATE's SET), and a value pushed onto a run-time list
     (`$params[] = ...`) matched to the `'<column> = ?'` written just before it.

Each of those pieces was forced by a MEASUREMENT, not added for completeness:

  * matching a value to its column: judging the whole statement reported
    `attendance/api/create.php` and `update.php`, whose DATE value sits in the
    same statement as a TIME column it does not fill, and two date-only values
    in `_core/Venues.php`;
  * NOT following a value into the arguments of an arbitrary call: letting it
    flow through every call reported twenty-six values in `import.php`,
    because `Events::alreadyInCalendar($db, ..., $storedStart)` returns a ROW;
  * reading a column list built at run time (`'UPDATE tblEvents SET ' .
    implode(', ', $set)`): without it, the events API's update endpoint before
    round 6 of #777 -- a real fault -- was invisible;
  * following a closure's `use` list: the three places that create an event
    bind their values inside `static function (...) use ($stmt, $values)`,
    and without it none of the three could be read.

MEASURED ON HISTORY, each faulty file put back into today's tree:

    resources/book.php      before 04a6226e   RED  startAt, endAt
    calendar/submit-save.php before 05a8097e  RED  startDateTime, endDateTime
    events/api/create.php   before 5ca77426   RED  startDateTime, endDateTime
                                                   (WITH the correct zone)
    events/api/update.php   before 5ca77426   RED  startDateTime, endDateTime
    calendar/manage/import.php before 5ca77426 RED startDateTime, endDateTime

And on today's tree it found ONE live fault, now fixed: the CSV import's
loose-spreadsheet step stored a cell in the skipped hour moved (see ALLOWED).

"A clock column" is read out of `web/_sql/full_schema.sql`, never typed here
(#706): every `TIME` column, and every `DATETIME` whose own comment declares it
a WALL-CLOCK reading -- TABLE AND COLUMN together, because `startAt` is a clock
reading in `tblResourceBooking` and may be a moment somewhere else.

"A moment function" is DERIVED from `MOMENT_BUILDERS` in
`check_clock_time_not_a_moment.py`, the one list, and every entry there must be
classified below or this check fails -- so the two cannot drift apart. Round 12
of #777 recorded what a second hand-written list costs: it was defeated by the
very spellings that had caused that package's faults.

A value that carries its OWN offset ("2026-06-01T10:00:00+01:00") really is an
instant and converting it is right -- but the ONE place that is done is
`I18n::instantFromInput()`, which checks the same shapes as the reading path.
A handler that routes through it builds no moment of its own and is not
reported. A handler that converts such a value INLINE is reported, on purpose:
that rule lives in one place (#777 round 6).

WHAT IT CANNOT SEE -- each a stated limit, not an oversight (D-065)
-------------------------------------------------------------------
This is a presence-and-shape check of a write path. The property "the hour
stored is the hour typed" is behavioural, and where it matters it is DRIVEN:
section I of `tools/clock-change-selftest.php` posts the real endpoints over the
hour that does not exist. This check is the tripwire for the next handler
nobody has driven yet. It does not see:

  * a value passed in as a function PARAMETER, or across files;
  * `$this->property`, `list()`/`[...] =` destructuring, `extract()`,
    references and variable variables;
  * SQL handed in as a PARAMETER (a helper such as `FeedAdmin::one($db,
    $sql, ...)`) -- the bind calls whose statement it could not read are
    COUNTED and listed by `--verbose` on every run, so that limit is a number
    rather than a hope (twelve today, read one by one when this was written:
    none of them stores a clock column through a moment. That is NOT "none
    writes a clock column" -- `DbBackup.php`'s restore writes every table,
    clock columns included, but copies stored values and builds no moment);
  * which column a value lands in, where that cannot be matched -- a spread
    whose pushes carry no `'<column> = ?'` literal, `execute($array)`,
    INSERT ... SELECT, or a value written into the text of `query()`. There it
    falls back to the whole STATEMENT, the direction that reports rather than
    hides;
  * a moment built from nothing a person wrote (`strtotime('+60 days')`),
    which is a moment and is deliberately not reported;
  * a moment built in one function and stored by another it is passed to, or
    an assignment that FLOWS through a function this check walls off
    (`PASS_THROUGH` in the probe lists the few it lets through).

A check that tried to see all of that would be a PHP interpreter. #763 and #775
are the record of static guards that grew towards one and were defeated round
after round; where the property is "this code RUNS", drive it.

EXEMPTIONS
----------
`ALLOWED` names a FILE, A VARIABLE AND A COLUMN together, never a file alone:
round 4 of #777 measured what forgiving a whole file costs (a brand-new fault in
a listed file left the gate green). It fails both ways -- an entry the probe no
longer reports fails, and so does an entry for a file that is not there -- and
a third way: each entry names the condition that makes it safe, and fails when
that condition is not in the file's live code exactly once.

The test OF this check is `tools/wall-clock-writes-selftest.py`: every shape
above planted in a fixture tree and required to be reported exactly, every
must-stay-green case required to stay green, and each defence above removed in
turn to prove some case goes red without it.

Package: WebMS-Intra / audit checks
Author: Claude Opus 5.5 (for MWBM Partners Ltd, t/a MWservices)
Copyright: All Rights Reserved -- MWBM Partners Ltd
Version: 1.0.0
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

# The one list of moment functions. Imported, never copied.
import check_clock_time_not_a_moment as reader_check  # noqa: E402

PROBE = os.path.join(HERE, "wall-clock-writes-probe.php")
SCHEMA = os.path.join("web", "_sql", "full_schema.sql")
PIN_FILE = reader_check.PIN_FILE

# ── Every entry of MOMENT_BUILDERS, classified ──────────────────────────────
# The number is the index of the VALUE argument (-1 means any argument).
# "probe" means the probe recognises the spelling itself (a constructor or a
# static method). None means it builds no moment, with the reason beside it.
#
# ⚠️ A NEW ENTRY IN MOMENT_BUILDERS THAT IS NOT CLASSIFIED HERE FAILS THIS
#    CHECK. That is the point: the alternative is a second list that silently
#    stops agreeing with the first.
CLASSIFIED: dict[str, object] = {
    "strtotime": 0,
    "strptime": 0,
    "date_create": 0,
    "date_create_immutable": 0,
    "date_create_from_format": 1,
    "date_create_immutable_from_format": 1,
    "mktime": -1,
    "gmmktime": -1,
    "createFromFormat": "probe",          # X::createFromFormat(format, VALUE)
    r"new\s+\\?DateTimeImmutable\b": "probe",
    r"new\s+\\?DateTime\b": "probe",
    # Measured in Europe/London: `date_parse('2026-03-29 01:30')['hour']` is
    # 1. It hands back the parts AS WRITTEN and resolves nothing, so it cannot
    # move an hour. `import.php` uses it to refuse an impossible date.
    "date_parse": None,
    "date_parse_from_format": None,
    # Converts an object that ALREADY exists; it reads nothing written. If the
    # object came through a moment, the variable it is held in says so.
    "createFromInterface": None,
}

# ── Exemptions: file, variable and column, each with its reason AND its guard ─
# Key: "path::$variable::table.column::where the moment is built" -- the last
# part being the function (a closure by its variable, lower-cased) that BUILT
# the moment. Fails both ways.
#
# ⚠️ THE PLACE IS PART OF THE KEY BECAUSE A VARIABLE NAME IS NOT A PLACE.
#    Measured: the CSV import before round 6 of #777 round-tripped its values
#    at the INSERT under the SAME names, `$startSql` and `$endSql`, as today's
#    deliberate fallback -- so a key of file, variable and column alone would
#    have forgiven that fault coming back, provided the guard below stayed.
#
# ⚠️ EACH ENTRY NAMES THE CONDITION THAT MAKES IT SAFE, AND THAT CONDITION IS
#    PINNED WHOLE. An exemption on its own would let somebody delete the very
#    guard that justified it with this check green -- and the test that drives
#    the guard (section K of clock-change-selftest.php) runs in
#    `portal-selftests.yml`, which GitHub has never registered (#754), and not
#    in the pre-push hook. So the entry fails unless its guarding condition is
#    in the file's live code EXACTLY ONCE (a dead copy above a gutted live one
#    is how #485, #763 and #777 round 9 were each defeated), whitespace free.
#    The WHOLE condition, because `&& false` defeats a substring pin -- the
#    one-edit bypass this repository has recorded five times.
#
#    An entry may need MORE THAN ONE pin, so the guard is a tuple of whole
#    statements, each required exactly once. The import's step (d) has five:
#    the condition that decides a cell has no zone or offset, a full date, no
#    REPORTED relative time and a weekday in range (with the `$relative` line
#    it reads, pinned in the same string so emptying it cannot pass); the zone
#    the readings are made in; PHP's own UTC reading of the cell; the
#    canonical cell built from what was WRITTEN; and the test that returns the
#    UTC reading only when the two agree and the reading is in the skipped
#    hour.
#
#    What the pins cannot see: whether the guarded code is REACHED, and what
#    the lines BETWEEN them compute. That is behavioural, and section K of
#    tools/clock-change-selftest.php drives it (D-065). Measured part by part
#    in round 5 of #785 -- every written field (year, month, day, hour,
#    minute, second, fraction), every relative date rule (year, month, day,
#    weekdays, first day of, last day of, the weekday, the weekday written
#    first), and every gate, plus (round 6) each bound of the weekday range
#    guard loosened alone -- each removed alone: 24 of 26 turn a section-K
#    check red. The two that do not, and why no check can:
#      * the `readingIsReal()` test: the UTC reading agrees with the zone on
#        every reading that exists, so removing it was measured to change
#        nothing -- over 330,727 cells in my round-5 sweep, and 0 of 9,348,144
#        in the independent check's round 5. Pinned as a statement of what the
#        step is for.
#      * the "full written date" guard: every cell without a year is refused
#        earlier by #797's two-reference rule, and `date_parse()` gives a
#        month-and-year cell day 1, so no cell reaching step (d) lacks one.
#        It stays as a guard against `setDate()` being handed `false`.
#    (Before round 4, d1, d5 and d7 -- the `weekdays` rule, the fraction and
#    the weekday -- each left section K green; before round 5 the year rule and
#    `first day of` did.)
#
# ⚠️ FIVE VERSIONS OF STEP (d) WERE PINNED HERE BEFORE THIS ONE, EACH WRONG.
#    **A pin pins whatever is there; it is never evidence that what is there
#    is right.**
#    * Round 0 REFUSED such a cell -- a refusal by SPELLING, which round 14
#      of #777 rules out. Planted back it fails K07b, K07c and K07f2.
#    * Round 1 rebuilt the reading from `date_parse()`'s fields and STORED
#      it: the wrong DATE for `+1 weekday`, `first/last day of` and an
#      ordinal `+1 month`. Planted back it fails K07b and K07e.
#    * Round 2 had only the reported-relative-time test, and msec/usec are
#      reported NOWHERE, so `+3600000 msec` was stored an hour early.
#      Planted back it fails K07f2.
#    * Round 3 had only the comparison, having called the reported-relative-
#      time test "measured redundant" -- and `date_parse()` misreports some
#      date rules, so `next sunday 2026-03-29 01:30 -168 hours` matched and
#      was stored an hour early. Planted back it fails K07c.
#    * Round 4 looked a weekday outside 0-6 up in the day-name list for
#      `<weekday> N weeks ago` (-1 to -7 by weekday with one `ago`; with
#      `ago ago` the weekday's own number, +1 to +6, and +7 only for
#      sunday), raising "Undefined array key -7". Planted back (the range
#      guard removed) it fails K07g and K07g2; loosening either bound alone,
#      even by one step, fails K07g.
IMPORT_STEP3_GUARD = (
    "$relative = $parsed['relative'] ?? []; "
    "if ($parsed['is_localtime'] === false "
    "&& is_int($parsed['year']) === true "
    "&& is_int($parsed['month']) === true "
    "&& is_int($parsed['day']) === true "
    "&& (int) ($relative['hour'] ?? 0) === 0 "
    "&& (int) ($relative['minute'] ?? 0) === 0 "
    "&& (int) ($relative['second'] ?? 0) === 0 "
    "&& (isset($relative['weekday']) === false "
    "|| (is_int($relative['weekday']) === true "
    "&& $relative['weekday'] >= 0 "
    "&& $relative['weekday'] <= 6))) {",
    "$utc = new \\DateTimeZone('UTC');",
    "$asRead = (new \\DateTimeImmutable($trimmed, $utc))->format('Y-m-d H:i:s.u');",
    "$dayName . $writtenBase->format('Y-m-d H:i:s.u') . ($dateRules === [] ? '' : ' ' . implode(' ', $dateRules)),",
    "if ($asRead !== null "
    "&& $asRead === $asWritten "
    "&& \\Portal\\Core\\EventRecurrence::readingIsReal(substr($asRead, 0, 19), date_default_timezone_get()) === false) { "
    "$why = ''; "
    "return substr($asRead, 0, 19); "
    "}",
)
_IMPORT_REASON = (
    "The CSV import's loose-spreadsheet step (`$fmtClockRaw` step 3) builds a "
    "moment with strtotime() ON PURPOSE: it is the only reader of Excel's long "
    "date, US slash dates and the rest, and the strict steps before it store "
    "the text without one. Since #785, for a cell with no zone, no offset, no "
    "relative time date_parse() reports, and any relative weekday it reports "
    "a day of the week (0-6), step (d) also reads the cell in UTC, where "
    "nothing moves, and returns THAT when it equals PHP's reading of a "
    "canonical cell built from what was WRITTEN and is in the hour the zone "
    "skipped -- so for a plain reading, what reaches this column is the "
    "reading the cell wrote. Known limit, a CLASS (#946): any date rule "
    "date_parse() misreports, combined with millisecond or microsecond "
    "arithmetic that exactly cancels the misreport, can still match and store "
    "the written reading -- `next <weekday>`, `last <weekday> of <month>` and "
    "`<ordinal> <weekday> of next month` are examples, not a complete list. "
    "A `<weekday> N weeks ago` cell with ONE `ago` is not one: date_parse() "
    "reports its weekday as -1 to -7, so the weekday guard always keeps it on "
    "the old answer. With `ago ago` it reports the weekday's own number (+1 "
    "to +6, or +7 for sunday), so a monday-to-saturday `ago ago` cell is "
    "ordinary and can be one. Driven by section K of "
    "tools/clock-change-selftest.php."
)
ALLOWED: dict[str, tuple[str, str | tuple[str, ...]]] = {
    "web/_apps/calendar/manage/import.php::$startSql::tblEvents.startDateTime::$fmtclockraw":
        (_IMPORT_REASON, IMPORT_STEP3_GUARD),
    "web/_apps/calendar/manage/import.php::$endSql::tblEvents.endDateTime::$fmtclockraw":
        (_IMPORT_REASON, IMPORT_STEP3_GUARD),
}


def repo_root() -> str:
    return os.path.dirname(os.path.dirname(HERE))


def builder_alternatives() -> list[str]:
    """MOMENT_BUILDERS split into its top-level alternatives."""
    return [a for a in reader_check.MOMENT_BUILDERS.pattern.split("|") if a != ""]


def clock_columns_by_table(schema: str) -> dict[str, list[str]]:
    """
    Every `TIME` column, and every `DATETIME` whose own comment says
    WALL-CLOCK, keyed by the table that declares it.

    Read from the CREATE TABLE blocks, so each column is tied to its table.
    """
    out: dict[str, list[str]] = {}
    for block in re.finditer(
        r"CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*?)\n\)\s*ENGINE",
        schema,
        re.S,
    ):
        table = block.group(1)
        for col in re.finditer(r"`(\w+)`\s+(TIME|DATETIME)\b([^\n]*)", block.group(2)):
            kind = col.group(2)
            rest = col.group(3)
            if kind == "TIME" or "WALL-CLOCK" in rest.upper():
                out.setdefault(table, [])
                if col.group(1) not in out[table]:
                    out[table].append(col.group(1))
    return out


def main(argv: list[str], allowed: dict[str, tuple[str, str]] | None = None) -> int:
    # `allowed` is for the self-test (tools/wall-clock-writes-selftest.py),
    # which runs this against fixture trees that hold none of the real files.
    allowed = ALLOWED if allowed is None else allowed
    root = repo_root()
    if "--root" in argv:
        root = os.path.abspath(argv[argv.index("--root") + 1])
    problems: list[str] = []

    schema_path = os.path.join(root, SCHEMA)
    if os.path.isfile(schema_path) is False:
        print(f"FAIL: {SCHEMA} is not there under {root}. This check cannot run.")
        return 1
    with open(schema_path, "r", encoding="utf-8", errors="replace") as handle:
        schema = handle.read()

    columns = clock_columns_by_table(schema)
    if len(columns) == 0:
        print(
            "FAIL: the schema declares no clock column at all, which cannot be "
            "right -- so this check is reading the wrong thing and would pass "
            "over every fault it exists to catch."
        )
        return 1

    # ⚠️ THE TWO CHECKS MUST AGREE ABOUT WHICH COLUMNS ARE CLOCK READINGS.
    #    This one ties each to its table and the reader check does not, so
    #    they read the schema differently. If the bare names ever disagree,
    #    one of them has stopped reading what the other reads -- and the one
    #    that reads fewer is the one whose guard silently shrank.
    mine = sorted({c for cols in columns.values() for c in cols})
    theirs = sorted(set(reader_check.clock_columns(schema)))
    if mine != theirs:
        problems.append(
            "this check and check_clock_time_not_a_moment.py no longer agree "
            "about which columns hold a clock reading.\n"
            f"       here only:  {', '.join(c for c in mine if c not in theirs) or '(none)'}\n"
            f"       there only: {', '.join(c for c in theirs if c not in mine) or '(none)'}\n"
            "       Each reads full_schema.sql in its own way (this one ties a "
            "column to its CREATE TABLE, because a column name alone is "
            "ambiguous across tables). Find which one stopped seeing a column."
        )
    # And the wall-clock DATETIME columns must be the PINNED set, so that a
    # reworded comment cannot drop a column out of THIS guard either. The
    # reader check owns the pin and its --repin; this only refuses to
    # disagree with it.
    pinned = reader_check.pinned_wall_clock(root)
    wall_dt = sorted(set(reader_check.wall_clock_datetime_columns(schema)))
    if pinned is not None and sorted(pinned) != wall_dt:
        problems.append(
            f"the WALL-CLOCK `DATETIME` columns in the schema ({', '.join(wall_dt)}) "
            f"are not the pinned set in {PIN_FILE} ({', '.join(sorted(pinned))}). "
            f"check_clock_time_not_a_moment.py owns that pin; see its --repin."
        )

    # ── The moment functions, derived ────────────────────────────────────
    sources: dict[str, int] = {}
    for alternative in builder_alternatives():
        if alternative not in CLASSIFIED:
            problems.append(
                f"MOMENT_BUILDERS in check_clock_time_not_a_moment.py has "
                f"`{alternative}`, which this check has not classified.\n"
                f"       Add it to CLASSIFIED with the index of its VALUE "
                f"argument, or with None and the reason it builds no moment. "
                f"A second list that quietly stops agreeing with the first is "
                f"what defeated round 12 of #777."
            )
            continue
        how = CLASSIFIED[alternative]
        if isinstance(how, int):
            sources[alternative] = how
    for name in CLASSIFIED:
        if name not in builder_alternatives():
            problems.append(
                f"CLASSIFIED names `{name}`, which is no longer in "
                f"MOMENT_BUILDERS. Take it out, or put it back there -- the "
                f"two lists describe one thing."
            )

    # ── Every PHP file under web/, through the probe in ONE process ──────
    files: list[str] = []
    for folder, _, names in os.walk(os.path.join(root, "web")):
        if "vendor" in folder:
            continue
        for name in sorted(names):
            if name.endswith(".php"):
                files.append(os.path.join(folder, name))
    files.sort()
    request = json.dumps({"columns": columns, "sources": sources, "files": files})
    try:
        ran = subprocess.run(
            ["php", PROBE], input=request, capture_output=True, text=True, timeout=600,
        )
    except Exception as why:   # noqa: BLE001 -- refuse, never pass
        print(
            f"FAIL: the PHP probe could not be run ({why}). This check proved "
            f"NOTHING about any file -- that is a fault in its surroundings, "
            f"not in the portal. PHP is needed because only the tokeniser can "
            f"tell code from the comments that quote the old code."
        )
        return 1
    if ran.returncode != 0:
        print(
            f"FAIL: the PHP probe stopped (exit {ran.returncode}): "
            f"{ran.stderr.strip()[:300]}\n"
            f"       This check proved NOTHING about any file."
        )
        return 1
    try:
        results = json.loads(ran.stdout)["results"]
    except (ValueError, KeyError) as why:
        print(f"FAIL: the PHP probe's answer could not be read ({why}).")
        return 1

    reported: dict[str, dict] = {}
    unread = 0
    unread_at: list[str] = []
    judged = 0
    for path in files:
        result = results.get(path)
        relative = os.path.relpath(path, root)
        if result is None:
            problems.append(f"the probe gave no answer for {relative}.")
            continue
        if result.get("error") is not None:
            # A file the probe could not follow is a refusal, never a pass.
            problems.append(
                f"{relative} could not be read by the probe: {result['error']}.\n"
                f"       That says nothing about the file's times; it means "
                f"this check could not look."
            )
            continue
        unread += int(result.get("unread", 0))
        unread_at += [f"{relative}:{line}" for line in result.get("unreadAt", [])]
        judged += len(result.get("judged", []))
        for found in result.get("findings", []):
            for column in found["columns"]:
                key = f"{relative}::{found['variable']}::{column}::{found['builtIn']}"
                reported[key] = {**found, "file": relative, "column": column}

    seen_allowed: set[str] = set()
    for key in sorted(reported):
        if key in allowed:
            seen_allowed.add(key)
            continue
        hit = reported[key]
        problems.append(
            f"{hit['file']}:{hit['line']} stores `{hit['variable']}` into "
            f"{hit['column']} after it went through a MOMENT built on line "
            f"{hit['builtOn']} (in {hit['builtIn']}).\n"
            f"       {hit['column']} holds a reading from a clock. Building a "
            f"moment from a reading -- strtotime(), a DateTime constructor, "
            f"createFromFormat(), mktime() -- resolves it in a zone, and on the "
            f"morning the clocks go forward the hour it names does not exist, "
            f"so PHP MOVES it. Formatted back and stored, the moved hour is "
            f"kept for ever: `resources/book.php` stored a 01:00 booking as "
            f"02:00, and the public event form stored a 01:30 event as 02:30.\n"
            f"       Store the TEXT: `I18n::wallClockFromInput()` checks its "
            f"shape and range and builds no moment. A value carrying its own "
            f"offset really is an instant -- route it through "
            f"`I18n::instantFromInput()`, the one place that is done. If this "
            f"really is right, add `{key}` to ALLOWED with the reason."
        )
    for key in sorted(allowed):
        path = key.split("::", 1)[0]
        full = os.path.join(root, path)
        if os.path.isfile(full) is False:
            problems.append(f"ALLOWED names {path}, which is not there. Remove `{key}`.")
            continue
        if key not in seen_allowed:
            problems.append(
                f"ALLOWED has `{key}`, but the probe no longer reports it. "
                f"Remove the entry -- a permission nothing uses reads as though "
                f"the shape were still there."
            )
            continue
        guards = allowed[key][1]
        if isinstance(guards, str) is True:
            guards = (guards,)
        with open(full, "r", encoding="utf-8", errors="replace") as handle:
            live = " ".join(reader_check.blank_comments(handle.read()).split())
        for guard in guards:
            times = live.count(" ".join(guard.split()))
            if times == 1:
                continue
            problems.append(
                f"ALLOWED forgives `{key}` because a guard makes it safe, and "
                f"that guard is in {path}'s live code {times} time(s), not "
                f"exactly once.\n"
                f"       wanted, whole: {guard.strip()}\n"
                f"       At zero the exemption is forgiving a fault nothing "
                f"guards any more; at two or more a dead copy can satisfy the "
                f"pin while the live one is gutted. If the guard was changed "
                f"deliberately, change it here in the same edit and say why."
            )

    print(
        f"Clock readings not stored through a moment: {len(files)} PHP file(s) "
        f"read, {sum(len(v) for v in columns.values())} clock column(s) in "
        f"{len(columns)} table(s) taken from the schema, "
        f"{len(sources)} moment function(s) derived from MOMENT_BUILDERS; "
        f"{judged} statement(s) found writing a clock column, each judged; "
        f"{len(allowed)} allowed on purpose; "
        f"{unread} bind call(s) whose statement could not be read from the source."
    )
    if "--verbose" in argv:
        for where in unread_at:
            print(f"   not read: {where}")
    # ⚠️ A RUN THAT JUDGED NOTHING HAS PROVED NOTHING. If the SQL reading or
    #    the schema reading ever breaks, every statement silently drops out
    #    and the check goes green over the whole portal -- the "answers
    #    nothing wrong because it could not look" failure (#763). Measured
    #    when this was written: dozens of clock-column writes are judged on
    #    an ordinary run, so none is not a quiet day; it is a broken check.
    if judged == 0:
        problems.append(
            "no statement writing a clock column was found anywhere under "
            "web/, which cannot be right: the portal writes events, bookings, "
            "rotas and sessions. This check is reading the SQL wrongly and "
            "would pass over every fault it exists to catch."
        )
    if len(problems) > 0:
        print("")
        for problem in problems:
            print(f"FAIL: {problem}")
        print("")
        print(
            f"{len(problems)} problem(s). A reading stored after going through a "
            f"moment is wrong for ever, one morning a year."
        )
        return 1
    print(
        "OK: no statement under web/ fills a clock column with a value that "
        "went through a moment, as far as one function's variables (and a "
        "closure's `use` list) can be followed.\n"
        "   It does NOT see a value passed in as a parameter or across files, "
        "or SQL handed in as a parameter (the count above; --verbose lists "
        "them). Where the stored hour matters it is DRIVEN over the hour that "
        "does not exist (sections I and K of tools/clock-change-selftest.php); "
        "this is the tripwire for the next handler nobody has driven yet."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
