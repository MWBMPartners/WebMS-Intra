#!/usr/bin/env python3
"""
A day is not always 86,400 seconds, and the portal must not assume it is.

Path: tools/audit-checks/check_clock_change_arithmetic.py

WHY THIS EXISTS (#549)
----------------------
The portal is a diary: services, lessons, rotas, room bookings, reminders,
livestreams. **An hour wrong is a congregation arriving at a locked building.**
The owner's standing rule of 23 September 2026 is that every time this portal
stores, works out, compares or shows must stay correct across the two nights a
year when the clocks change.

Twice a year a day is 23 or 25 hours. So **dividing a span of seconds by 86,400
to count DAYS is wrong on those two weekends** — it is #549's "mistake 3", and
the issue asks for exactly this check.

**IT WAS NOT HYPOTHETICAL.** Measured before this check was written:

  * `calendar/views/month.php` worked its grid out that way and rendered **41
    cells where March 2026 needs 42** — a whole day missing from the calendar,
    in Europe/London and America/New_York alike. October was unaffected,
    because a 25-hour day rounds the other way and integer division swallows
    it, which is why nobody noticed.
  * `Giving::pledgeExpectedToDate()` counted **13 days where 22 March to 5
    April 2026 is 14**, so a weekly pledge was a whole instalment out for the
    rest of its window.
  * Four more counted "days until" or "days since" from two dates and were a
    day out across a change: a DBS expiry, a venue renewal, the rehire undo
    window, and a member's own safeguarding page.

WHAT IT CHECKS
--------------
  **Division by 86,400** — `/ 86400`, `intdiv(x, 86400)`, `% 86400` — in PHP
  under `web/`. That shape means "turn a span into days", and a span between
  two dates is not a whole number of 86,400s when a clock change falls in it.

WHAT IT DELIBERATELY DOES NOT CHECK, because a check that cries wolf gets
switched off (#483)
-------------------------------------------------------------------------
  * **Multiplying by 86,400, and constants equal to it.** `CACHE_TTL = 86400`,
    a cookie that lasts `365 * 86400` seconds, a backup kept for `86400`
    seconds — all of these want EXACT seconds and are right. Flagging them
    would have produced far more noise than signal: of the 42 mentions of
    86,400 under `web/`, most are of this kind.
  * **JavaScript.** The two countdowns (`live/index.php`,
    `calendar/public-landing.php`) tick down an exact duration to a moment,
    which is the correct use. They are not read at all.
  * **`diff()` used as a length** — #549's mistake 2, where PHP applies an
    interval from `diff()` as a wall-clock offset and one you built yourself as
    an exact one. It is real and it cost this project an overnight event ending
    an hour late, but it cannot be told from a correct use without knowing what
    the interval is for, and guessing would cry wolf. **Nothing here looks for
    it; it stays a question for a person**, and saying so is better than a
    check that pretends to cover it.

So a green run means no PHP under `web/` divides by 86,400 outside the
allow-list. It does NOT mean the portal is correct across a clock change.

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

# Dividing a span by a day's worth of seconds, in its three spellings.
DIVIDES_BY_A_DAY = re.compile(r"/\s*86_?400\b|intdiv\s*\([^)]*,\s*86_?400\s*\)|%\s*86_?400\b")

# 📁 Places where dividing by 86,400 is RIGHT, each with the reason. These
#    measure an exact DURATION — not calendar days — so a clock change does not
#    apply. Fails both ways: an entry that no longer divides by 86,400 fails
#    too, because an allow-list that has stopped describing the code is how the
#    migration harness's exceptions went stale.
ALLOWED = {
    os.path.join("web", "_apps", "admin", "system-info", "index.php"): (
        "Server uptime, which is an exact duration in seconds since the machine "
        "started — not a count of calendar days. A 23-hour day does not make a "
        "machine's uptime shorter."
    ),
    os.path.join("web", "_core", "ScheduledJobs.php"): (
        "How long ago a scheduled job last ran, for the words an administrator "
        "reads. The span comes from TIMESTAMPDIFF(SECOND, ...) between two "
        "moments BOTH IN UTC, and UTC has no clock changes, so it is an exact "
        "duration and not a count of calendar days -- the same case as server "
        "uptime above. The question being answered is 'how long since this "
        "ran', which a duration answers; 'which day did it last run' would be "
        "a calendar question and is not asked anywhere. The phrase is "
        "deliberately rough and the method says so."
    ),
}

SUFFIXES = (".php",)


def repo_root() -> str:
    here = os.path.dirname(os.path.abspath(__file__))
    return os.path.dirname(os.path.dirname(here))


def php_only(text: str) -> str:
    """
    Everything inside `<?php ... ?>`, with the rest blanked.

    ⚠️ WITHOUT THIS, A COUNTDOWN WRITTEN IN JAVASCRIPT INSIDE A PHP PAGE IS
       READ AS PHP. `calendar/public-landing.php` and `live/index.php` both
       hold `Math.floor(s / 86400)` in a `<script>` block — a correct exact
       duration — and reporting them would be the crying-wolf direction on the
       very first run.
    """
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
    """Comments out, strings kept, length and line count preserved."""
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
    problems: list[str] = []
    seen_allowed: set[str] = set()
    looked_at = 0

    for folder, _, names in os.walk(os.path.join(root, WEB)):
        if "vendor" in folder:
            continue
        for name in sorted(names):
            if name.endswith(SUFFIXES) is False:
                continue
            relative = os.path.relpath(os.path.join(folder, name), root)
            with open(os.path.join(root, relative), "r", encoding="utf-8", errors="replace") as handle:
                raw = handle.read()
            looked_at += 1
            code = blank_comments(php_only(raw))
            hits = [code[:m.start()].count("\n") + 1 for m in DIVIDES_BY_A_DAY.finditer(code)]
            if len(hits) == 0:
                continue
            if relative in ALLOWED:
                seen_allowed.add(relative)
                continue
            problems.append(
                f"{relative} divides by 86,400 on line(s) {', '.join(str(h) for h in hits)}.\n"
                f"       That turns a span of seconds into DAYS — and twice a year a day is 23 or "
                f"25 hours, so the answer is out by one across a clock change. Count calendar days "
                f"instead: `$a->diff($b)->days` on two zone-aware moments.\n"
                f"       Measured instances of this exact shape before it was guarded: a calendar "
                f"month rendering 41 cells where March needs 42, and a weekly pledge a whole "
                f"instalment out.\n"
                f"       If this one really is an exact DURATION rather than calendar days, add it "
                f"to ALLOWED in {os.path.basename(__file__)} with the reason."
            )

    for relative in sorted(ALLOWED):
        if os.path.isfile(os.path.join(root, relative)) is False:
            problems.append(
                f"ALLOWED names {relative}, which is not there. Remove the entry."
            )
            continue
        if relative not in seen_allowed:
            problems.append(
                f"{relative} is in ALLOWED but no longer divides by 86,400. Remove the entry — "
                f"a permission nothing uses reads as though the shape were still there, and the "
                f"next person to touch that file will think it is allowed."
            )

    print(
        f"Clock-change arithmetic: {looked_at} PHP file(s) read; "
        f"{len(ALLOWED)} allowed on purpose, {len(seen_allowed)} of them still dividing"
    )

    if len(problems) > 0:
        print("")
        for problem in problems:
            print(f"FAIL: {problem}")
        print("")
        print(
            f"{len(problems)} problem(s). An hour wrong in a diary is a congregation arriving "
            f"at a locked building."
        )
        return 1

    print("OK: nothing divides a span by 86,400 to count days, outside the allow-list.")
    print(
        "   This does NOT cover #549's mistake 2 — a `diff()` result used as a length — which "
        "cannot be told from a correct use without knowing what the interval is for. That stays "
        "a question for a person."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
