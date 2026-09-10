#!/usr/bin/env python3
"""
Web-root shadowing check.

Finds addresses that the portal thinks it answers, but which the web server
quietly answers for itself instead.

WHY THIS EXISTS
---------------
`web/public_html/.htaccess` says, in effect: if a web address matches something
that REALLY EXISTS on disk, serve that and do not bother the portal. The rule is
written as `RewriteCond %{REQUEST_FILENAME} !-d` (and the same for `-f`), and it
is correct and necessary — it is what lets stylesheets, images and scripts be
served straight from disk without going through the portal.

The trouble is what happens when a real folder happens to share its name with an
address the portal has registered. The web server wins, every time, and:

  * no portal code runs, so NOTHING is written to the error log;
  * the visitor gets a bare folder listing or a flat refusal;
  * there is no clue anywhere pointing at the cause.

This is not theoretical. Two faults of exactly this shape were found on
10 September 2026, both long-standing:

  * A folder `web/public_html/admin/` made the ENTIRE ADMIN AREA unreachable —
    the first thing an administrator ever clicks (#483).

  * A folder `web/public_html/widget/` hid a PUBLIC, sign-in-free address
    (#478). That is the dangerous shape. Because nobody could reach the page,
    nobody had noticed that it had no access check at all and that its queries
    returned INTERNAL events. Anybody "tidying up" that folder would have
    published internal event names, dates and locations to the internet in the
    same moment.

WHY THE EXISTING ROUTE CHECK CANNOT CATCH THIS
----------------------------------------------
`check_route_targets.py` asks "does the file this address points at exist?" For
both faults above the answer was YES. The address was registered correctly and
the file was present. The web server simply never asked the portal. A check that
looks only at the portal's own records is blind to it by construction, which is
why this is a separate check rather than an addition to that one.

WHAT COUNTS AS A PROBLEM
------------------------
An address is reported when THE WHOLE ADDRESS matches a real file or folder
under `web/public_html/`, unless that pairing is on the list of deliberate
exceptions below.

It has to be the whole address, not just the start of it, and getting that wrong
makes the check useless. The web server compares the ENTIRE requested path
against the disk. So `/admin` is hidden by a folder called `admin`, but
`/admin/activity` is NOT — there is no `admin/activity` on disk, so that request
reaches the portal perfectly well even while `/admin` is broken.

The first draft of this check compared only the first segment. It correctly
found `/admin`, and then also reported `/admin/activity`, `/widget/countdown`
and others that were entirely fine. A check that cries wolf gets switched off,
and then it catches nothing at all.

Exit code:
  0 — nothing found
  1 — at least one shadowed address (add --strict to fail a build on it)

Usage:
  python3 tools/audit-checks/check_webroot_shadowing.py [--strict]
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
SQL_DIR = REPO_ROOT / "web" / "_sql"
WEBROOT = REPO_ROOT / "web" / "public_html"
HTACCESS = WEBROOT / ".htaccess"

# ---------------------------------------------------------------------------
# Deliberate exceptions — pairings that collide ON PURPOSE and are handled.
#
# Each entry needs a reason. "It was already like that" is not a reason: both
# faults this check exists for were already like that.
# ---------------------------------------------------------------------------
ALLOWED = {
    # `.htaccess` has its own rule, `RewriteRule ^assets/?$ index.php`, placed
    # BEFORE the real-file rule. So the bare /assets address reaches the portal
    # (it is the Asset Tracker's home page) while everything deeper —
    # /assets/css/…, /assets/js/… — is served straight from disk as intended.
    "assets": "handled by an explicit rewrite rule ahead of the real-file rule",

    # The API documentation viewer is MEANT to be served directly by the web
    # server, from web/public_html/api-docs/index.php. It is one of only three
    # PHP files that legitimately live in the web root.
    "api-docs": "served directly on purpose; one of the three allowed web-root pages",

    # The offline page a browser shows when there is no connection. It has to be
    # reachable without the portal running, which is the whole point of it.
    "offline": "must work when the portal cannot be reached at all",
}


def seeded_addresses() -> dict[str, tuple[str, Path, int]]:
    """
    Every address the portal registers, from full_schema.sql and the numbered
    migrations, as {address: (target file, which SQL file, which line)}.

    Later files win, mirroring what actually happens when the installer replays
    them in order.
    """
    found: dict[str, tuple[str, Path, int]] = {}
    pattern = re.compile(
        r"\(\s*'([^']+)'\s*,\s*'([^']+\.php)'\s*,\s*[0-9]",
        re.IGNORECASE,
    )
    deleted = re.compile(r"DELETE\s+FROM\s+`?tblRoutes`?.*?routeKey`?\s*=\s*'([^']+)'",
                         re.IGNORECASE | re.DOTALL)

    for sql in sorted(SQL_DIR.glob("*.sql")):
        try:
            text = sql.read_text(encoding="utf-8", errors="replace")
        except OSError:
            continue
        if "tblRoutes" not in text:
            continue
        for line_no, line in enumerate(text.splitlines(), start=1):
            for m in pattern.finditer(line):
                found[m.group(1)] = (m.group(2), sql, line_no)
        # An address that is later deleted is no longer registered.
        for m in deleted.finditer(text):
            found.pop(m.group(1), None)
    return found


def webroot_entries() -> dict[str, str]:
    """What really exists directly inside the web root: {name: 'folder'|'file'}."""
    entries: dict[str, str] = {}
    if not WEBROOT.is_dir():
        return entries
    for child in WEBROOT.iterdir():
        if child.name.startswith("."):
            continue
        entries[child.name] = "folder" if child.is_dir() else "file"
    return entries


def check() -> int:
    if not HTACCESS.is_file():
        print("check_webroot_shadowing: no .htaccess found — skipping.")
        return 0

    rules = HTACCESS.read_text(encoding="utf-8", errors="replace")
    if "REQUEST_FILENAME} !-d" not in rules:
        print(
            "check_webroot_shadowing: the web server no longer skips real folders,\n"
            "  so this whole class of fault cannot happen. If that is deliberate,\n"
            "  this check can be retired. If it is not, something has been lost."
        )
        return 0

    addresses = seeded_addresses()
    on_disk = webroot_entries()
    findings = []

    for address, (target, sql, line_no) in sorted(addresses.items()):
        # The web server compares the WHOLE requested path against the disk, so
        # this must too. Only an address that really exists as a file or folder
        # is hidden; a longer address that merely starts with one is fine.
        on_disk_path = WEBROOT / address
        try:
            shadowed = on_disk_path.is_dir() or on_disk_path.is_file()
        except OSError:
            shadowed = False
        if shadowed is False:
            continue
        if address in ALLOWED:
            continue
        kind = "folder" if on_disk_path.is_dir() else "file"
        findings.append((address, target, kind, address, sql, line_no))

    if not findings:
        print(
            f"check_webroot_shadowing: OK — {len(addresses)} addresses checked, "
            f"none hidden by a real file or folder in the web root."
        )
        return 0

    print("check_webroot_shadowing: FOUND ADDRESSES THE PORTAL WILL NEVER SEE\n")
    print("A real file or folder in web/public_html/ shares its name with these")
    print("addresses. The web server answers for the file or folder and never asks")
    print("the portal, so nothing runs and nothing is written to the error log.\n")

    for address, target, kind, first, sql, line_no in findings:
        rel = sql.relative_to(REPO_ROOT)
        print(f"  • /{address}")
        print(f"      the portal thinks this goes to : {target}")
        print(f"      but this really exists         : web/public_html/{first}  ({kind})")
        print(f"      address registered in          : {rel}:{line_no}")
        print()

    print("To fix one of these, pick whichever fits:")
    print("  - move the real folder's contents into web/_apps/ (the router looks")
    print("    there first, so the address usually needs no change at all);")
    print("  - remove the address, if it has never worked and nothing needs it;")
    print("  - give the address a different name that does not collide;")
    print("  - or, if the collision is deliberate and handled, add it to ALLOWED")
    print("    at the top of this file WITH THE REASON.")
    print()
    print("Before making a hidden address reachable, READ WHAT IS BEHIND IT.")
    print("A page nobody can open is a page nobody has checked. One of these")
    print("turned out to have no sign-in check and to return internal data.")

    return 1 if "--strict" in sys.argv else 1


if __name__ == "__main__":
    sys.exit(check())
