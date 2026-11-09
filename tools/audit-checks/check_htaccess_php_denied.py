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

WHY IT PINS THE TEXT (round 7 of P4a's check)
---------------------------------------------
Version 1 read only the deny rule's RewriteCond lines and judged them against
a table of addresses. Round 7 found four ways to leave those lines untouched
while the rule does nothing, each green:
  - the RewriteRule changed (`^never$` instead of `.*`), so the conditions are
    never reached;
  - `RewriteEngine Off` anywhere (in .htaccess the LAST RewriteEngine line
    decides for the whole file);
  - an earlier rule that ends processing first (`RewriteRule ^ - [L]`);
  - an `[OR]` on a condition, which turns "all of these" into "any of these".
Nothing in the tests executes the real .htaccess (the self-tests use PHP's
built-in server, which ignores it), so whether the rule RUNS cannot be driven
here (D-065): the source is PINNED instead.
  1. Exactly one RewriteEngine directive, and it says On.
  2. No section (`<If…>`, `<IfModule …>`, …) is open where it stands, and
     none opens inside the pinned region — a section could make the whole
     block conditional.
  3. Every directive from `RewriteEngine On` to the end of the deny rule is
     EXACTLY the pinned list below (whitespace runs collapsed — Apache splits
     a directive's arguments on whitespace, so a run of spaces means the same
     as one). So no rule can be added before the deny rule, no condition
     changed, no [OR] added, and the rule itself cannot change.
  4. The address table, as before: the deny rule's conditions, applied with
     Python's regular expressions, refuse every address that must be refused
     and none that must be let through. (A change to the pinned text fails 3
     first; 4 is what says WHY the pinned text is right.)
A deliberate change to the block means changing PINNED_REGION here too, in
the same commit, which is the point.

WHAT IT CANNOT SEE, said plainly: what Apache really does. It does not run
Apache; it cannot see a server that ignores .htaccess (AllowOverride None) or
has no mod_rewrite (tools/public-door-selftest.php's section F runs every
web-root file directly, as such a server would), nor a server-wide setting
that changes how this file is read (RewriteOptions in the server's own
configuration). Percent-encoded paths (/%77/...) are #884's.

USAGE
    python3 -I tools/audit-checks/check_htaccess_php_denied.py
    python3 -I tools/audit-checks/check_htaccess_php_denied.py --self-test
        (plants each known way of making the rule do nothing into a copy of
        the text, and requires every one to be reported; the real file must
        pass)

Package:   WebMS-Intra
Author:    MWBM Partners Ltd (t/a MWservices)
Copyright: All Rights Reserved
Version:   2.0.0
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
HTACCESS = REPO / "web/public_html/.htaccess"

# Every directive from `RewriteEngine On` to the end of the deny rule, in
# order, exactly. The two rules before the deny block refuse dotfiles (403)
# and redirect a typed /index.php to / (301); neither lets a request through
# to PHP, and neither may change without this list changing too.
PINNED_REGION = [
    "RewriteEngine On",
    "RewriteBase /",
    r"RewriteCond %{REQUEST_URI} (^|/)\.(?!well-known) [NC]",
    "RewriteRule ^ - [F]",
    r"RewriteCond %{THE_REQUEST} ^(GET|POST|HEAD)\s/index\.php[\s?]",
    r"RewriteRule ^index\.php$ / [R=301,L]",
    r"RewriteCond %{REQUEST_URI} \.(php[0-9]?|phtml|phar)(/|$) [NC]",
    r"RewriteCond %{REQUEST_URI} !^/index\.php$",
    r"RewriteCond %{REQUEST_URI} !^/api-docs/index\.php$",
    r"RewriteCond %{REQUEST_URI} !^/error\.php$",
    "RewriteRule .* - [R=404,L]",
]
DENY_RULE = "RewriteRule .* - [R=404,L]"

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


def directives(text: str) -> list[tuple[int, str]]:
    """(line number, directive with whitespace runs collapsed) for every line
    Apache reads: a comment is a line whose first non-blank character is #."""
    out = []
    for n, line in enumerate(text.splitlines(), start=1):
        s = line.strip()
        if s == "" or s.startswith("#"):
            continue
        out.append((n, " ".join(s.split())))
    return out


def name_of(directive: str) -> str:
    return directive.split(" ", 1)[0].lower()


def structure_problems(text: str) -> list[str]:
    ds = directives(text)
    problems = []
    engines = [(n, d) for n, d in ds if name_of(d) == "rewriteengine"]
    if len(engines) != 1:
        problems.append("there must be exactly ONE RewriteEngine line, and there are %d (in .htaccess the last one decides "
                        "for the whole file, so a second can switch the deny rule off)" % len(engines))
    if engines == [] or engines[0][1].split(" ")[1:] != ["On"]:
        if engines != []:
            problems.append("line %d says %r: the rewrite engine must be On, or no rule runs" % engines[0])
        return problems
    start = next(i for i, (n, d) in enumerate(ds) if name_of(d) == "rewriteengine")
    depth = 0
    for _n, d in ds[:start]:
        if d.startswith("</"):
            depth -= 1
        elif d.startswith("<"):
            depth += 1
    if depth != 0:
        problems.append("RewriteEngine On (line %d) sits inside a section (<If…>, <IfModule …>), which can make the "
                        "whole deny rule conditional" % ds[start][0])
    region = [d for _n, d in ds[start:start + len(PINNED_REGION)]]
    if region != PINNED_REGION:
        for i, want in enumerate(PINNED_REGION):
            got = region[i] if i < len(region) else "(nothing)"
            if got != want:
                line = ds[start + i][0] if start + i < len(ds) else 0
                problems.append("from RewriteEngine On to the deny rule, directive %d (line %d) is %r where the pinned text "
                                "is %r — a rule added before the deny rule, a changed condition, an [OR], or a changed "
                                "rule can each make it refuse nothing. If the change is deliberate, change PINNED_REGION "
                                "in this check in the same commit" % (i + 1, line, got, want))
                break
    return problems


def conditions(text: str) -> list[tuple[bool, re.Pattern]]:
    ds = [d for _n, d in directives(text)]
    if DENY_RULE not in ds:
        raise ValueError("no `%s` deny rule was found" % DENY_RULE)
    end = ds.index(DENY_RULE)
    conds = []
    i = end - 1
    while i >= 0 and name_of(ds[i]) == "rewritecond":
        parts = ds[i].split(" ")
        if len(parts) < 3 or parts[1] != "%{REQUEST_URI}":
            raise ValueError("a condition of the deny rule does not test %%{REQUEST_URI}: %s" % ds[i])
        pattern = parts[2]
        flags = parts[3] if len(parts) > 3 else ""
        if "OR" in flags.strip("[]").upper().split(","):
            raise ValueError("a condition of the deny rule carries [OR]: %s" % ds[i])
        negated = pattern.startswith("!")
        if negated:
            pattern = pattern[1:]
        conds.append((negated, re.compile(pattern, re.I if "NC" in flags.upper() else 0)))
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


def problems_in(text: str) -> list[str]:
    problems = structure_problems(text)
    try:
        conds = conditions(text)
    except (ValueError, re.error) as e:
        return problems + ["the deny rule could not be read: %s" % e]
    problems += ["%s is NOT refused, and Apache would run it directly" % u for u in MUST_REFUSE if refused(conds, u) is False]
    problems += ["%s IS refused, and it must not be" % u for u in MUST_ALLOW if refused(conds, u) is True]
    return problems


def self_test(text: str) -> int:
    """Plants each known way of making the deny rule do nothing; each must be reported."""
    plants = {
        "the rule never matches (^never$)": (DENY_RULE, "RewriteRule ^never$ - [R=404,L]"),
        "RewriteEngine Off added at the end": (None, "\nRewriteEngine Off\n"),
        "an earlier rule ends processing first": ("RewriteBase /", "RewriteBase /\nRewriteRule ^ - [L]"),
        "[OR] on the first condition": (r"\.(php[0-9]?|phtml|phar)(/|$) [NC]", r"\.(php[0-9]?|phtml|phar)(/|$) [NC,OR]"),
        "the old rule, ending in .php only (round 6)": (r"\.(php[0-9]?|phtml|phar)(/|$) [NC]", r"\.php$ [NC]"),
        "the block wrapped in a section": ("RewriteEngine On", "<If \"false\">\nRewriteEngine On"),
        "RewriteEngine Off in place of On": ("RewriteEngine On", "RewriteEngine Off"),
    }
    bad = 0
    reported = 0
    real = problems_in(text)
    if real != []:
        print("✗ self-test: the real file is reported: %s" % real[0])
        bad += 1
    for label, (old, new) in plants.items():
        if old is None:
            planted = text + new
        else:
            if text.count(old) != 1:
                print("✗ self-test: plant %r could not be applied (its text is not in the file exactly once)" % label)
                bad += 1
                continue
            planted = text.replace(old, new)
        found = problems_in(planted)
        print(("✓" if found != [] else "✗") + " plant: %s — %s" % (label, found[0][:110] if found else "NOT REPORTED"))
        bad += 0 if found != [] else 1
        reported += 1 if found != [] else 0
    print("check_htaccess_php_denied self-test: %d of %d plants reported, real file %s."
          % (reported, len(plants), "clean" if real == [] else "REPORTED"))
    return 1 if bad else 0


def main() -> int:
    try:
        text = HTACCESS.read_text(encoding="utf-8")
    except OSError as e:
        print("✗ check_htaccess_php_denied: .htaccess could not be read: %s" % e)
        return 1
    if "--self-test" in sys.argv[1:]:
        return self_test(text)
    problems = problems_in(text)
    if problems:
        for p in problems:
            print("✗ " + p)
        return 1
    print("check_htaccess_php_denied: OK — RewriteEngine On once and in no section, the %d directives up to the deny rule "
          "exactly as pinned, and the rule refuses all %d direct PHP addresses tried (extra path information, capitals "
          "and the other PHP endings included) and none of the %d it must let through."
          % (len(PINNED_REGION), len(MUST_REFUSE), len(MUST_ALLOW)))
    return 0


if __name__ == "__main__":
    sys.exit(main())
