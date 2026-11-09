#!/usr/bin/env python3
"""
.htaccess refuses every direct .php address — extra path information included (#493 P4a).

Path: tools/audit-checks/check_htaccess_php_denied.py

WHY THIS EXISTS
---------------
web/public_html/.htaccess refuses (404) every address that would run a PHP
file directly, except the three entry points: /index.php, /api-docs/index.php
and /error.php. Round 6 of P4a's independent check measured, on a real
Apache 2.4 (php:8.4-apache), that the rule matched only addresses ENDING in
.php — so /robots.php/x, /manifest.php/x, /sitemap.php/x and /openapi.php/x
ran the file directly, with "/x" as extra path information: 200, and a fatal
error naming the server's file path when display_errors is on. The rule now
matches ".php" (and .php5-style endings, .phtml, .phar) followed by "/" or the
end, case-insensitively.

WHAT IT CHECKS
--------------
It reads the deny rule's own `RewriteCond %{REQUEST_URI}` lines (the block
just before `RewriteRule .* - [R=404,L]`), turns each into a Python regular
expression (Apache's syntax is the same for everything these lines use; [NC]
means case-insensitive; a leading ! negates), and asks, for a fixed list of
addresses, whether ALL the conditions hold — i.e. whether the rule refuses it:

  - must be REFUSED: every non-entry .php file, with and without extra path
    information, in capitals, and the other PHP endings — and the entry points
    themselves WITH extra path information;
  - must NOT be refused: the three entry points exactly, and ordinary
    addresses (a clean route, the public door's /w/... and /events.json, a
    static file, a folder called "php").

WHAT IT CANNOT SEE, said plainly: what Apache really does. It reads the rule
as text and applies it with Python's regular expressions, which agree with
Apache's PCRE on everything these lines use; it does not run Apache, and it
cannot see a server that ignores .htaccess (tools/public-door-selftest.php's
section F runs every web-root file directly for that case). Percent-encoded
paths (/%77/...) are #884's.

USAGE
    python3 -I tools/audit-checks/check_htaccess_php_denied.py

Package:   WebMS-Intra
Author:    MWBM Partners Ltd (t/a MWservices)
Copyright: All Rights Reserved
Version:   1.0.0
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
HTACCESS = REPO / "web/public_html/.htaccess"

MUST_REFUSE = [
    "/robots.php", "/robots.php/x", "/manifest.php/", "/sitemap.php/a/b", "/openapi.php/x",
    "/offline/index.php", "/offline/index.php/x", "/x/y.php", "/ROBOTS.PHP", "/Robots.Php/x",
    "/a.php5", "/a.php7/x", "/a.phtml", "/a.phtml/x", "/a.phar", "/a.phar/x",
    "/index.php/x", "/api-docs/index.php/x", "/error.php/x",
]
MUST_ALLOW = [
    "/index.php", "/api-docs/index.php", "/error.php", "/", "/expenses/submit", "/events.json",
    "/w/0123456789abcdef01234567/events.json", "/assets/js/portal.js", "/php/notes", "/a.phpx",
]


def conditions(text: str) -> list[tuple[bool, re.Pattern]]:
    lines = text.splitlines()
    end = next((i for i, l in enumerate(lines) if l.strip().startswith("RewriteRule") and "[R=404" in l), -1)
    if end < 0:
        raise ValueError("no `RewriteRule ... [R=404,...]` deny rule was found")
    conds = []
    i = end - 1
    while i >= 0 and lines[i].strip().startswith("RewriteCond"):
        parts = lines[i].split()
        if len(parts) < 3 or parts[1] != "%{REQUEST_URI}":
            raise ValueError("a condition of the deny rule does not test %%{REQUEST_URI}: %s" % lines[i].strip())
        pattern = parts[2]
        flags = parts[3] if len(parts) > 3 else ""
        negated = pattern.startswith("!")
        if negated:
            pattern = pattern[1:]
        conds.append((negated, re.compile(pattern, re.I if "NC" in flags else 0)))
        i -= 1
    if conds == []:
        raise ValueError("the deny rule has no conditions")
    return list(reversed(conds))


def refused(conds: list[tuple[bool, re.Pattern]], uri: str) -> bool:
    for negated, rx in conds:
        hit = rx.search(uri) is not None
        if hit == negated:
            return False
    return True


def main() -> int:
    try:
        conds = conditions(HTACCESS.read_text(encoding="utf-8"))
    except (OSError, ValueError, re.error) as e:
        print("✗ check_htaccess_php_denied: the deny rule could not be read: %s" % e)
        return 1
    problems = ["%s is NOT refused, and Apache would run it directly" % u for u in MUST_REFUSE if refused(conds, u) is False]
    problems += ["%s IS refused, and it must not be" % u for u in MUST_ALLOW if refused(conds, u) is True]
    if problems:
        for p in problems:
            print("✗ " + p)
        return 1
    print("check_htaccess_php_denied: OK — the deny rule refuses all %d direct PHP addresses tried (extra path "
          "information, capitals and the other PHP endings included) and none of the %d it must let through."
          % (len(MUST_REFUSE), len(MUST_ALLOW)))
    return 0


if __name__ == "__main__":
    sys.exit(main())
