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
