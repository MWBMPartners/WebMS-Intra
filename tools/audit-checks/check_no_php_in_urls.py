#!/usr/bin/env python3
"""
No ".php" in web addresses.

Finds links, form targets, redirects and background requests that point at an
address ending in ".php".

WHY THIS MATTERS — two separate reasons, and the second is the surprising one
---------------------------------------------------------------------------
**It looks unprofessional, and it tells people what the site is built with.**
A visitor who sees "/expenses/submit/save.php" learns the site runs on PHP.
Somebody looking for a way in now knows which weaknesses are worth trying, which
versions to fingerprint, and which automated tools to point at it. It is a small
advantage to hand over for nothing, and it costs nothing to withhold.

**In THIS portal, such an address does not work at all.** `.htaccess` answers
404 for every address ending in ".php", except the three pages that genuinely
live in the web root (`/index.php`, `/error.php`, `/api-docs/index.php`). So a
link written that way is not merely untidy — it is broken, and broken in the
quietest possible manner: the page it sits on looks completely normal, and only
the person who clicks it finds out.

That is not hypothetical. When this check was first written it immediately found
**three Expenses forms** — submit, approve and treasury — every one of which
posted to an address ending in ".php". Filling in an expense claim and pressing
Save would have produced "page not found". The correct addresses were already
registered and working; the forms simply named the wrong ones. It also found the
database upgrade page redirecting to a 404 whenever a form token expired,
stranding an administrator half way through upgrading their database.

None of that showed up in any other check, because every file involved existed
and every address involved was registered. The mistake was in what the pages
pointed AT.

HOW TO WRITE THE ADDRESS INSTEAD
--------------------------------
Use the clean address that is registered for the page. `/expenses/submit/save`,
not `/expenses/submit/save.php`. The portal works out which file answers it.

Exit code:
  0 — nothing found
  1 — at least one address ending in .php (add --strict to fail a build on it)

Usage:
  python3 tools/audit-checks/check_no_php_in_urls.py [--strict]
"""

from __future__ import annotations

import os
import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
WEB = REPO_ROOT / "web"

# Only things a browser would actually request.
PATTERNS = [
    (re.compile(r'href="(/[^"\s]*\.php[^"\s]*)"'), "link"),
    (re.compile(r"href='(/[^'\s]*\.php[^'\s]*)'"), "link"),
    (re.compile(r'action="(/[^"\s]*\.php[^"\s]*)"'), "form target"),
    (re.compile(r"action='(/[^'\s]*\.php[^'\s]*)'"), "form target"),
    (re.compile(r'Location:\s*(/[^"\'\s]*\.php[^"\'\s]*)'), "redirect"),
    (re.compile(r'fetch\(\s*[\'"](/[^\'"]*\.php[^\'"]*)[\'"]'), "background request"),
    (re.compile(r'\.open\(\s*[\'"][A-Z]+[\'"]\s*,\s*[\'"](/[^\'"]*\.php[^\'"]*)[\'"]'), "background request"),
]

# An address ending in .php INSIDE A PHP STRING (#743).
#
# WHY THE PATTERNS ABOVE CANNOT SEE THIS, AND WHY IT MATTERS MOST
# ---------------------------------------------------------------
# All of them expect the address to sit directly inside the attribute, as
# `href="/admin/users.php"`. The house style for anything built in PHP is not
# that — it is:
#
#     <a href="<?php echo htmlspecialchars('/admin/users.php', ENT_QUOTES, 'UTF-8'); ?>">
#
# **Measured on 4 November 2026: that line was invisible to all THREE checks
# written to catch it** — this one, `check_site_aware_addresses.py` and
# `check_registered_addresses.py` — while the SAME address written as a plain
# literal was caught by two of the three. It is the FORM they could not see,
# not the address. So the shape a check could not see was the shape the
# codebase mostly uses.
#
# HOW IT DECIDES, AND WHY NOT MORE WIDELY
# ---------------------------------------
# A `.php` string on its own is usually innocent: `require`, a route's target
# file, a comment, a test fixture. Reporting those gets the check switched off
# and then it catches nothing (#483). So a string is reported only when the
# line it sits on also shows the value being used AS AN ADDRESS, and shows no
# sign of being a file operation.
#
# WHAT THIS CANNOT SEE, said plainly because an overstated guarantee is worse
# than none:
#   * **The address must start with "/".** A relative `'admin/users.php'` is
#     not matched, because that is exactly the shape of `require 'foo.php'`
#     and telling them apart on one line is guesswork.
#   * **It reads ONE LINE at a time**, as the rest of this check does. An
#     address and its `href` split across two lines are missed.
#   * **It judges by what else is on the line**, not by following the value.
#     A `.php` address assigned to a variable on one line and echoed into an
#     `href` ten lines later is missed.
#   * It cannot see an address built by joining pieces
#     (`'/admin/' . $page . '.php'`), which is the same limit the literal
#     patterns have.
#   * **A TRAILING comment that quotes such an address IS reported.** A line
#     beginning with a comment marker is skipped, but
#     `$x = 1; // never write href="/admin/users.php"` is not. Measured on
#     4 November 2026, and **the patterns above have exactly the same
#     exposure** — this is not something the in-string rule introduced. It was
#     left rather than fixed on purpose: stripping a trailing `//` would cut a
#     line at the `//` inside any `https://` string, which is a fault this
#     repository has already paid for (#716, where a `//` in a URL ate the rest
#     of the line). Nothing in the repository trips it today; if something ever
#     does, the honest fix is to rewrite the comment, not to loosen the check.
PHP_STRING_ADDRESS = re.compile(r"""['"](/[A-Za-z0-9_\-./]*\.php(?:\?[^'"]*)?)['"]""")

# Shows the value is being used as an address.
ADDRESS_MARKERS = (
    "href",
    "action=",
    "Location:",
    "Site::url",
    "Router::url",
    "Router::urlForScript",
    "Portal.url",
    "window.location",
    "fetch(",
    ".open(",
    "formaction",
    "src=",
)

# Shows the value is a FILE, not an address. These are the legitimate uses the
# check must stay quiet about.
FILE_MARKERS = (
    "require",
    "include",
    "__DIR__",
    "dirname(",
    "file_get_contents",
    "file_put_contents",
    "file_exists",
    "is_file",
    "is_readable",
    "realpath",
    "unlink",
    "basename(",
    "targetFile",
    "PORTAL_APPS",
    "PORTAL_WEBROOT",
    "opendir",
    "scandir",
    "glob(",
    "token_get_all",
    "SplFileInfo",
)

SKIP_DIRS = {".git", "node_modules", "_libraries", "vendor", "_backups", "_uploads"}

# The only three pages the web server is allowed to serve directly, named in
# .htaccess. A link to one of these is correct.
ALLOWED = {"/index.php", "/error.php", "/api-docs/index.php"}


def check() -> int:
    findings: list[tuple[Path, int, str, str]] = []

    for root, dirs, files in os.walk(WEB):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        for fn in files:
            if not fn.endswith((".php", ".js", ".html")):
                continue
            path = Path(root) / fn
            try:
                text = path.read_text(encoding="utf-8", errors="replace")
            except OSError:
                continue

            for line_no, line in enumerate(text.splitlines(), start=1):
                stripped = line.strip()
                # A comment explaining the rule is not a breach of it.
                if stripped.startswith(("//", "*", "#", "/*")):
                    continue
                for pattern, kind in PATTERNS:
                    for m in pattern.finditer(line):
                        url = m.group(1)
                        if url.split("?")[0] in ALLOWED:
                            continue
                        findings.append((path, line_no, kind, url))

                # The same address hidden inside a PHP string (#743). Judged by
                # what else is on the line — see PHP_STRING_ADDRESS above.
                if path.suffix != ".php":
                    continue
                if any(marker in line for marker in FILE_MARKERS):
                    continue
                if not any(marker in line for marker in ADDRESS_MARKERS):
                    continue
                for m in PHP_STRING_ADDRESS.finditer(line):
                    url = m.group(1)
                    if url.split("?")[0] in ALLOWED:
                        continue
                    found_already = any(
                        f[0] == path and f[1] == line_no and f[3] == url for f in findings
                    )
                    if found_already:
                        continue
                    findings.append((path, line_no, "address inside a PHP string", url))

    if not findings:
        print("check_no_php_in_urls: OK — no web address ends in .php.")
        return 0

    print("check_no_php_in_urls: FOUND ADDRESSES ENDING IN .php\n")
    print("These are broken as well as untidy. This portal's .htaccess answers")
    print('"page not found" for every address ending in .php, so anybody who')
    print("clicks one gets an error — while the page it sits on looks fine.\n")

    for path, line_no, kind, url in findings:
        rel = path.relative_to(REPO_ROOT)
        print(f"  • {rel}:{line_no}")
        print(f"      {kind}: {url}")
        print(f"      use the registered address instead: {url.split('?')[0][:-4]}")
        print()

    print("Check the address is registered in web/_sql/full_schema.sql, then")
    print("point at that rather than at the file.")
    return 1


if __name__ == "__main__":
    sys.exit(check())
