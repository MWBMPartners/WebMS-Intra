#!/usr/bin/env python3
"""
No time zone is built in — hard gate (#782) 🕰️

THE RULE (.claude/CLAUDE.md, "Times always respect the time zone", the owner,
23 September 2026): "Never hard-code a zone, for the same reason no web address
is hard-coded — this is a product, not one customer's installation." An
organisation's zone is answered by `Site::timezone()` and nothing else (#604);
a stored zone that cannot be opened falls back through `Site::zoneFor()`.

WHAT WAS WRONG. Until #782, eighteen places under web/_apps and web/_core
wrote "Europe/London" as the answer when nobody had chosen a zone: the
livestream schedule, venues, the venue check API, the Sabbath quiet window and
the "We're live" job. A church in Sydney had its livestream countdown, its
hall's bookings and its quiet hours worked out on London's clock unless
somebody noticed a box. Nothing failed; a time was simply told on the wrong
clock — the shape this project has to look for, because no error ever shows.

WHAT THIS CHECKS. Every zone name written as a QUOTED VALUE in the PHP and the
JavaScript under web/ (see zone-literals.php for the PHP half and what it
reads). Each one must be named in ON_PURPOSE below, with how many times it
appears in that file and why. It FAILS BOTH WAYS:

  - a zone literal that is not listed (a new built-in zone: the fault), and
  - a listed entry that no longer matches what is there (fewer, more, or
    none), so the list cannot quietly go stale and start excusing something
    nobody re-read (#483, #645: an exemption list that cannot fail is how an
    old excuse comes to cover a new fault).

WHY IT IS NARROW, ON PURPOSE (#483: a check that cries wolf gets switched off).
  - COMMENTS ARE NEVER READ. Nearly every file this fault was fixed in now
    carries a comment saying "this used to be "Europe/London", typed in here",
    because that is how this project stops it coming back. A text search
    reports those comments as the very fault they record. The PHP half uses
    PHP's own tokeniser; the JavaScript half steps over // and /* */ comments.
  - ONLY REAL ZONE NAMES COUNT, from PHP's own list (legacy names included),
    so a file path or a sentence never does. Names without a "/" ("UTC") are
    not counted: UTC is the portal's deliberate "nothing is known" answer.
  - SQL FILES ARE NOT READ. Old migrations (070, 090, 170, 217) wrote the
    London defaults, and a migration is history: it cannot be edited, and the
    install script and migration 273 are what remove the defaults. JSON is not
    read either: web/_core/api-spec.json carries zone names as EXAMPLES for a
    developer, and the giving-retention countries file is data.
  - Third-party JavaScript under web/public_html/assets/vendor/ is not read:
    it is not this portal's code.

WHAT IT CANNOT SEE: a zone built from pieces ('Europe/' . 'London'), one that
is not in quotes, and one in a SQL file. And it cannot tell whether a fallback
that names NO zone is the right one — `Site::zoneFor()` is the answer, and a
reviewer has to look for it. A green run means no new zone name is written
into the code, NOT that every time is told on the right clock.

It needs `php` (the security job's runner image has it; other hard gates here
call it too). Without it this REFUSES with a reason and exits 2 — there is no
second-best reading to fall back to, and a gate that silently passes is worse
than none.

@package   WebMS-Intra (development tooling — never deployed; only web/ is)
@author    MWBM Partners Ltd (t/a MWservices)
@copyright 2026-present MWBM Partners Ltd (t/a MWservices)
@license   All Rights Reserved
@version   1.0.0
@link      https://github.com/MWBMPartners/WebMS-Intra/issues/782
"""

from __future__ import annotations

import collections
import json
import re
import shutil
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
HELPER = Path(__file__).resolve().parent / "zone-literals.php"

# Every zone literal that is there ON PURPOSE: (file, zone) -> (how many, why).
# "*" as the zone means "every zone literal in this file is data", and then the
# count is the number of literals the file holds, so a file that stops holding
# them — or grows one — fails just the same.
ON_PURPOSE: dict[tuple[str, str], tuple[int, str]] = {
    ("web/_core/WindowsTimeZones.php", "*"): (
        139,
        "DATA: a map from the zone names Microsoft calendars write to the "
        "IANA names PHP understands (#557). Each literal is one row of that "
        "map, not a default anybody gets.",
    ),
    ("web/_core/Events.php", "Europe/London"): (
        1,
        "OLD_FORM_ZONE: LOOKED FOR, never written. It finds events made before "
        "#604, when the old event form filled the box with London for every "
        "organisation, so Manage Events can offer to move them to the "
        "organisation's own zone.",
    ),
    ("web/_core/Events.php", "Etc/GMT+12"): (
        1,
        "The clock of the LAST place on Earth to reach a date, used by "
        "nowForADeletionDeadline() as the fallback for a zone it cannot read, "
        "so a deletion timed by an event can only ever be LATE, never early "
        "(#810). Not one customer's place: the safe end of every place.",
    ),
    ("web/_core/GivingRetention.php", "Etc/GMT+12"): (
        2,
        "The same safe end, for giving retention: a donor's name is never "
        "cleared before the date has reached everywhere on Earth. Both "
        "literals are the one fallback, written once for an unknown name and "
        "once for a name PHP then refuses.",
    ),
    ("web/_apps/calendar/manage/index.php", "Europe/London"): (
        1,
        "WORDS, not a default: the warning tells an administrator that events "
        "made before #604 are marked \"Europe/London\" — the value "
        "Events::OLD_FORM_ZONE looks for — and offers to move them.",
    ),
    ("web/_apps/calendar/manage/save.php", "Europe/London"): (
        1,
        "WORDS, not a default: the activity-log line for that same button, "
        "saying the events were moved FROM \"Europe/London\" to the "
        "organisation's own zone.",
    ),
}

JS_SKIP_PREFIX = "web/public_html/assets/vendor/"


def refuse(why: str) -> None:
    print(f"REFUSED — {why}. Nothing was checked, and this gate fails.")
    sys.exit(2)


def js_string_literals(text: str):
    """Yield (line, contents) for every quoted string in JavaScript source,
    stepping over // and /* */ comments. A template literal (`...`) counts
    as a string. Regular-expression literals are NOT understood: one holding
    a quote can make the scan pair quotes wrongly, which can only produce a
    "string" that is NOT exactly a zone name — and only an exact zone name is
    ever reported — so the error is towards missing, never towards crying
    wolf. Said here so nobody mistakes this for a JavaScript parser."""
    i, n, line = 0, len(text), 1
    while i < n:
        c = text[i]
        if c == "\n":
            line += 1
            i += 1
        elif text.startswith("//", i):
            j = text.find("\n", i)
            i = n if j < 0 else j
        elif text.startswith("/*", i):
            j = text.find("*/", i + 2)
            j = n if j < 0 else j + 2
            line += text.count("\n", i, j)
            i = j
        elif c in "'\"`":
            start_line = line
            j = i + 1
            buf = []
            while j < n and text[j] != c:
                if text[j] == "\\" and j + 1 < n:
                    buf.append(text[j:j + 2])
                    j += 2
                    continue
                if text[j] == "\n":
                    line += 1
                    if c != "`":
                        break  # an ordinary quote cannot cross a line: stop here
                buf.append(text[j])
                j += 1
            yield start_line, "".join(buf)
            i = j + 1
        else:
            i += 1


def main() -> int:
    if shutil.which("php") is None:
        refuse("this needs `php` on the PATH, to read PHP with PHP's own tokeniser")
    if HELPER.is_file() is False:
        refuse(f"its PHP half, {HELPER.name}, is missing")

    try:
        done = subprocess.run(
            ["php", str(HELPER), str(REPO)], capture_output=True, text=True, timeout=120
        )
    except subprocess.TimeoutExpired:
        refuse("its PHP half did not finish within two minutes")
    if done.returncode != 0:
        refuse(f"its PHP half could not read the code: {done.stderr.strip() or 'no reason given'}")
    try:
        found = json.loads(done.stdout)
        zones = set(found["zones"])
        hits = list(found["hits"])
    except (ValueError, KeyError, TypeError):
        refuse("its PHP half answered something that is not the expected JSON")
    if len(zones) < 300:
        # A PHP built without its zone database knows almost none, and every
        # literal would then look like "not a zone": a green run on nothing.
        refuse(f"PHP knows only {len(zones)} zone names, so it cannot tell a zone from anything else")

    for path in sorted((REPO / "web").rglob("*.js")):
        rel = path.relative_to(REPO).as_posix()
        if rel.startswith(JS_SKIP_PREFIX):
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        for line, contents in js_string_literals(text):
            if contents in zones:
                hits.append({"file": rel, "line": line, "zone": contents})

    counted: dict[tuple[str, str], list[int]] = collections.defaultdict(list)
    whole_file = {f for (f, z) in ON_PURPOSE if z == "*"}
    for h in hits:
        key = (h["file"], "*") if h["file"] in whole_file else (h["file"], h["zone"])
        counted[key].append(int(h["line"]))

    problems: list[str] = []
    for key, lines in sorted(counted.items()):
        if key not in ON_PURPOSE:
            file, zone = key
            where = ", ".join(str(n) for n in sorted(lines))
            problems.append(
                f"  • {file} (line {where}): the zone \"{zone}\" is written into the code. "
                "WebMS-Intra is a product: an organisation's zone is Site::timezone(), and a stored "
                "zone that cannot be opened falls back through Site::zoneFor(). If this one really "
                "is data or deliberate, add it to ON_PURPOSE in this file with the reason (#782)."
            )
    for key, (expected, _why) in sorted(ON_PURPOSE.items()):
        seen = len(counted.get(key, []))
        if seen != expected:
            file, zone = key
            what = "zone literals" if zone == "*" else f"\"{zone}\""
            problems.append(
                f"  • ON_PURPOSE says {file} holds {what} {expected} time(s), and it holds {seen}. "
                "An exemption must match what is there: re-read the file and correct the entry "
                "(or remove it), so the list never excuses something nobody looked at."
            )

    if problems:
        print("No time zone is built in (#782): FAILED\n")
        print("\n".join(problems))
        return 1

    total = sum(n for n, _ in ON_PURPOSE.values())
    print(
        f"No time zone is built in (#782): OK — {len(hits)} zone literal(s) under web/, "
        f"all {total} of them named on purpose in {len(ON_PURPOSE)} entries; no other."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
