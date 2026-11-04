#!/usr/bin/env python3
"""
Warns before a PHP, MySQL or MariaDB version we support stops being supported (#475).

Path: tools/check-end-of-life.py

WHY THIS EXISTS
---------------
This portal names the versions it supports in exactly two places in code --
`Portal\\Core\\DbServer` for the database and `Portal\\Core\\PhpRuntime` for PHP
-- and those lists go stale **silently**. Nothing anywhere notices that a
version we tell customers is fine stopped getting security fixes a year ago.
That is how MySQL 8.0 came to be named as the production database in every
document in this repository after its support had ended in April 2026 (#475),
and it is the same shape as #640's warning about PHP: going stale must make a
judgement less informative, never wrong.

So this reads the two lists OUT OF THE PHP -- never a second copy of them --
and compares them with endoflife.date, which publishes the dates the vendors
themselves announce.

WHY IT IS NOT IN tools/audit-checks/
------------------------------------
**It needs the internet.** Every script in `tools/audit-checks/` runs with no
network and no database, which is what lets `.githooks/pre-push` run all of
them in about nine seconds before every push. A check that needs a web request
there would make the hook slow and would fail on a train. This one is run by a
scheduled workflow instead, and by hand when somebody wants to know.

WHAT IT REPORTS
---------------
Three kinds of finding, and the second is the one that matters most:

  1. **ENDED** -- a version we support has passed its end of support. Nobody is
     fixing its security faults, and we are telling customers it is fine.
  2. **SOON** -- within WARN_WITHIN_DAYS of ending. This is the whole point:
     a warning while there is still time to plan, rather than a discovery
     afterwards.
  3. **NEWER LINE EXISTS** -- the vendor has published a line newer than the
     one we record as the newest we know about. Not urgent, and not a fault:
     it is how the constant that says "I know about versions up to here" gets
     kept honest. #640 learned this the hard way with PHP 8.6.

WHAT COUNTS AS "END OF SUPPORT", AND WHY IT DIFFERS BY VENDOR
-------------------------------------------------------------
endoflife.date gives two dates where a vendor publishes two:

  * `support` -- active support: new fixes of every kind.
  * `eol`     -- end of life: not even security fixes.

**PHP publishes both and the gap is two years**, so for PHP the honest question
is which one you mean. This script uses `eol` for ENDED (that is when security
fixes stop, which is what actually endangers a customer) and reports the
`support` date alongside it, because a version in security-fix-only mode is a
version to plan away from. **MariaDB publishes no `support` date at all**
(measured: every cycle answers `null`), so there `eol` is the only date there
is. MySQL publishes both.

Getting this wrong in the reassuring direction is the danger: reading PHP's
`eol` as "still fine for two more years" would have made this script say
nothing about PHP 8.4, whose active support ends on 31 December 2026.

WHAT THIS CANNOT DO, said plainly because an overstated guarantee is worse
than none
-------------------------------------------------------------------------
  * **It cannot tell a vendor-patched build from an unpatched one.** That is
    #640's rule and it applies here: a version number is not proof. This
    script talks about VERSION LINES, never about a particular server.
  * **It trusts endoflife.date.** That is a community-maintained site reading
    vendors' announcements, not the vendors themselves. A wrong date there is a
    wrong answer here. It is used because the alternative is somebody
    remembering, which is what failed.
  * **It says nothing about what a customer's host actually offers.** Knowing
    that MySQL 8.0 has ended does not tell you whether a customer can move off
    it. That is the constraint #475 exists to settle.
  * **It does not read the documents.** A `.md` file naming an ended version is
    not found by this; only the two code constants are read.
  * **A version line absent from endoflife.date is reported as unknown, not as
    fine.** Silence is not success.

Package: WebMS-Intra / tools
Author: Claude Opus 5 (for MWBM Partners Ltd, t/a MWservices)
Copyright: All Rights Reserved -- MWBM Partners Ltd
Version: 1.0.0
"""

from __future__ import annotations

import argparse
import datetime
import json
import os
import re
import sys
import urllib.error
import urllib.request

# How much notice we want. Six months is the owner's figure in #475 ("opens an
# issue when something we support is within six months of its end of support").
WARN_WITHIN_DAYS = 183

API = "https://endoflife.date/api/{product}.json"
TIMEOUT_SECONDS = 20

# Where each list of supported versions really lives. Read, never copied.
DB_SERVER = os.path.join("web", "_core", "DbServer.php")
PHP_RUNTIME = os.path.join("web", "_core", "PhpRuntime.php")


def repo_root() -> str:
    """The repository root, from this file's own position."""
    return os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def php_string_const(text: str, name: str) -> str | None:
    """The value of `public const NAME = '...';` in some PHP."""
    match = re.search(
        r"public\s+const\s+" + re.escape(name) + r"\s*=\s*'([^']*)'\s*;", text
    )
    return match.group(1) if match is not None else None


def php_list_const(text: str, name: str) -> list[str] | None:
    """
    The values of `public const NAME = ['a', 'b'];` in some PHP.

    Deliberately not a PHP parser: these two constants are written as a single
    line of quoted strings and have been since they were introduced. If that
    ever changes, this returns None and the caller treats it as "could not
    read", which fails -- it does not quietly report an empty list, because an
    empty list would look like "nothing to warn about".
    """
    match = re.search(
        r"public\s+const\s+" + re.escape(name) + r"\s*=\s*\[(.*?)\]\s*;",
        text,
        re.DOTALL,
    )
    if match is None:
        return None
    values = re.findall(r"'([^']*)'", match.group(1))
    return values if len(values) > 0 else None


def fetch(product: str) -> list[dict]:
    """Every release line endoflife.date knows about for one product."""
    url = API.format(product=product)
    request = urllib.request.Request(
        url, headers={"Accept": "application/json", "User-Agent": "WebMS-Intra-eol-check"}
    )
    with urllib.request.urlopen(request, timeout=TIMEOUT_SECONDS) as response:
        return json.loads(response.read().decode("utf-8"))


def as_date(value: object) -> datetime.date | None:
    """
    One of endoflife.date's date fields as a real date.

    The field holds a date, or `true` (already ended, no date given), or
    `false` (not ended), or is absent. Only a real date answers here; the
    caller handles the other three by hand, because treating `true` as "no
    date, so nothing to say" would hide a version that has already ended.
    """
    if isinstance(value, str) is True:
        try:
            return datetime.date.fromisoformat(value)
        except ValueError:
            return None
    return None


def judge(product: str, series: str, cycles: list[dict], today: datetime.date) -> dict:
    """
    What we know about one version line we claim to support.

    Answers a dictionary with a `state` of 'ended', 'soon', 'fine' or
    'unknown', and the words to say about it.
    """
    row = next((c for c in cycles if str(c.get("cycle")) == series), None)
    if row is None:
        return {
            "state": "unknown",
            "series": series,
            "words": (
                f"{product} {series} is not a release line endoflife.date knows "
                f"about. Either the constant names a line that does not exist, or "
                f"the site has not got it. Check by hand -- this is not the same "
                f"as 'still supported'."
            ),
        }

    # MariaDB publishes no active-support date, so `eol` is all there is.
    support = as_date(row.get("support"))
    eol = as_date(row.get("eol"))
    latest = row.get("latest")
    is_lts = row.get("lts") is True

    # `eol: true` means ended with no date given.
    if row.get("eol") is True:
        return {
            "state": "ended",
            "series": series,
            "words": (
                f"{product} {series} has ENDED (endoflife.date gives no date, only "
                f"that it is over). Nobody is fixing its security faults."
            ),
        }

    detail = f"latest {latest}" + (", a long-term release" if is_lts is True else "")

    if eol is not None and eol <= today:
        extra = ""
        if support is not None:
            extra = f" Active support ended {support.isoformat()}."
        return {
            "state": "ended",
            "series": series,
            "words": (
                f"{product} {series} ENDED on {eol.isoformat()} — "
                f"{(today - eol).days} days ago. Not even security fixes now "
                f"({detail}).{extra}"
            ),
        }

    # Active support ending is worth saying even while security fixes continue:
    # a line in security-fix-only mode is a line to plan away from. PHP is the
    # one here that publishes both, and the gap is two years.
    if support is not None and support <= today and eol is not None:
        days = (eol - today).days
        state = "soon" if days <= WARN_WITHIN_DAYS else "fine"
        return {
            "state": state,
            "series": series,
            "words": (
                f"{product} {series} is in SECURITY FIXES ONLY — active support "
                f"ended {support.isoformat()}; security fixes stop "
                f"{eol.isoformat()} ({days} days, {detail})."
            ),
        }

    if support is not None and support > today:
        days = (support - today).days
        if days <= WARN_WITHIN_DAYS:
            return {
                "state": "soon",
                "series": series,
                "words": (
                    f"{product} {series} loses ACTIVE SUPPORT on "
                    f"{support.isoformat()} — {days} days away. Security fixes "
                    f"continue until {eol.isoformat() if eol is not None else 'an unpublished date'} "
                    f"({detail})."
                ),
            }

    if eol is not None:
        days = (eol - today).days
        if days <= WARN_WITHIN_DAYS:
            return {
                "state": "soon",
                "series": series,
                "words": (
                    f"{product} {series} ends on {eol.isoformat()} — {days} days "
                    f"away ({detail})."
                ),
            }
        return {
            "state": "fine",
            "series": series,
            "words": f"{product} {series} — supported until {eol.isoformat()} ({detail}).",
        }

    return {
        "state": "unknown",
        "series": series,
        "words": (
            f"{product} {series} — endoflife.date publishes no end date for it "
            f"({detail}). Treated as unknown, not as supported."
        ),
    }


def lines_newer_than(cycles: list[dict], ours: str) -> tuple[str | None, int | None]:
    """
    Whether endoflife.date lists any release line newer than the one we record.

    Answers (the site's newest line, how many it lists above ours). A count of
    None means our line is not on the site's list at all.

    **The site's own ordering is used, never a version comparison, and that is
    load-bearing twice over.** MySQL's numbering changed shape -- Oracle moved
    from 9.x to year-based lines (`26.7`, `26.10`) -- so comparing numbers
    would put `26.10` either side of `9.7` depending on how you read it, and
    neither reading is a fact about which came first.

    **And "not on the list" must NOT be reported as being behind.** The first
    version of this said "the newest line we record is 8.6, and endoflife.date
    now lists 8.5 ... the constant is behind", which is false in the
    cry-wolf direction: PHP 8.6 is a PRE-RELEASE, endoflife.date lists only
    released lines, and #640 pins `NEWEST_KNOWN_SERIES = '8.6'` on purpose.
    Telling somebody to "update" a constant that is deliberately ahead is how
    a check gets switched off (#483).
    """
    listed = [str(c.get("cycle")) for c in cycles]
    if len(listed) == 0:
        return (None, None)
    if ours not in listed:
        return (listed[0], None)
    return (listed[0], listed.index(ours))


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Warn before a PHP, MySQL or MariaDB version we support stops being supported."
    )
    parser.add_argument(
        "--days",
        type=int,
        default=WARN_WITHIN_DAYS,
        help=f"how much notice to ask for, in days (default {WARN_WITHIN_DAYS})",
    )
    parser.add_argument(
        "--json",
        action="store_true",
        help="print the findings as JSON, for a workflow to act on",
    )
    args = parser.parse_args()

    root = repo_root()
    today = datetime.date.today()

    try:
        with open(os.path.join(root, DB_SERVER), "r", encoding="utf-8") as handle:
            db_text = handle.read()
        with open(os.path.join(root, PHP_RUNTIME), "r", encoding="utf-8") as handle:
            php_text = handle.read()
    except OSError as problem:
        print(f"FAIL: could not read the files that name the supported versions: {problem}")
        return 2

    # What we claim to support. Read out of the code, never a second copy.
    claims: list[tuple[str, str, list[str], str | None]] = []

    mysql_series = php_list_const(db_text, "MYSQL_SUPPORTED_SERIES")
    mariadb_series = php_list_const(db_text, "MARIADB_SUPPORTED_SERIES")
    php_min = php_string_const(php_text, "MIN_PHP")
    php_preferred = php_string_const(php_text, "PREFERRED_SERIES")

    if mysql_series is None or mariadb_series is None:
        print(
            "FAIL: could not read MYSQL_SUPPORTED_SERIES or MARIADB_SUPPORTED_SERIES "
            f"out of {DB_SERVER}. They may have been rewritten in a shape this "
            "script cannot read. It refuses rather than reporting an empty list, "
            "because an empty list looks exactly like 'nothing to warn about'."
        )
        return 2
    if php_min is None or php_preferred is None:
        print(f"FAIL: could not read MIN_PHP or PREFERRED_SERIES out of {PHP_RUNTIME}.")
        return 2

    # PHP's supported set is the minimum's line up to the preferred line. Only
    # those two are stated in code, so only those two are judged -- naming a
    # line nobody stated would be inventing a claim.
    php_lines = sorted({".".join(php_min.split(".")[:2]), php_preferred})

    claims.append(("MySQL", "mysql", mysql_series, php_string_const(db_text, "MYSQL_NEWEST_KNOWN")))
    claims.append(
        ("MariaDB", "mariadb", mariadb_series, php_string_const(db_text, "MARIADB_NEWEST_KNOWN"))
    )
    claims.append(
        ("PHP", "php", php_lines, php_string_const(php_text, "NEWEST_KNOWN_SERIES"))
    )

    findings: list[dict] = []
    lines: list[str] = []
    could_not_ask: list[str] = []

    for label, product, series_list, newest_known in claims:
        try:
            cycles = fetch(product)
        except (urllib.error.URLError, urllib.error.HTTPError, ValueError, TimeoutError) as problem:
            could_not_ask.append(f"{label}: {problem}")
            continue

        lines.append(f"{label} — the lines this portal says it supports: {', '.join(series_list)}")
        for series in series_list:
            verdict = judge(label, series, cycles, today)
            lines.append(f"  [{verdict['state'].upper()}] {verdict['words']}")
            if verdict["state"] in ("ended", "soon", "unknown"):
                findings.append({"product": label, **verdict})

        if newest_known is not None:
            newest, above = lines_newer_than(cycles, newest_known)
            if above is None and newest is not None:
                # Ahead of the site, or a line that does not exist. Said
                # neutrally on purpose: for PHP this is DELIBERATE (#640 pins
                # a pre-release, and endoflife.date lists only released
                # lines), so it must not read as "you are behind".
                lines.append(
                    f"  [AHEAD OR UNLISTED] {label}: we record {newest_known} as the "
                    f"newest line we know about, and endoflife.date does not list it "
                    f"(its newest is {newest}). That is expected where we have "
                    f"deliberately pinned a pre-release — PHP 8.6 is pinned on "
                    f"purpose (#640). Nothing to do unless the line does not exist."
                )
            elif above is not None and above > 0 and newest is not None:
                words = (
                    f"{label}: we record {newest_known} as the newest line we know "
                    f"about, and endoflife.date lists {above} newer — the newest "
                    f"being {newest}. Not urgent and not a fault, but a judgement "
                    f"made from a stale constant goes quiet rather than wrong (#640)."
                )
                lines.append(f"  [NEWER LINE] {words}")
                findings.append(
                    {"product": label, "state": "newer-line", "series": newest, "words": words}
                )
        lines.append("")

    if args.json is True:
        print(json.dumps({
            "today": today.isoformat(),
            "days": args.days,
            "findings": findings,
            "could_not_ask": could_not_ask,
            "report": lines,
        }, indent=2))
    else:
        print(f"End-of-life check — {today.isoformat()}, warning within {args.days} days")
        print("")
        for line in lines:
            print(line)
        urgent = [f for f in findings if f["state"] in ("ended", "soon")]
        if len(could_not_ask) > 0:
            # NEVER print the reassuring line beside this. The first version
            # printed "Nothing we support is within the warning window"
            # directly under "COULD NOT ASK", which asserts the comforting half
            # of a thing it had just said it could not establish -- the exact
            # shape this repository keeps being bitten by. The exit code was
            # right (2) and the words were wrong, which is worse, because
            # somebody reads the words.
            print("COULD NOT ASK — so this run establishes NOTHING, for any product:")
            for problem in could_not_ask:
                print(f"  - {problem}")
            print("")
            if len(urgent) > 0:
                print(
                    f"{len(urgent)} version line(s) need attention among the products "
                    f"that did answer. There may be more among the ones that did not."
                )
            else:
                print(
                    "Nothing needing attention among the products that DID answer. "
                    "That is not the same as nothing needing attention."
                )
        elif len(urgent) > 0:
            print(f"{len(urgent)} version line(s) need attention.")
        else:
            print("Nothing we support is within the warning window.")

    # Exit codes, so a workflow can act on them:
    #   2 — could not establish the facts. NEVER treated as a pass.
    #   1 — something we support has ended or ends soon.
    #   0 — nothing urgent. A newer line existing on its own is not urgent.
    if len(could_not_ask) > 0:
        return 2
    if any(f["state"] in ("ended", "soon") for f in findings) is True:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
