#!/usr/bin/env python3
"""Check: only ONE place decides whether a PHP version is supported (#640).

WHY THIS EXISTS
---------------
Four places in this portal read the PHP version: the installation wizard's
checks, the admin dashboard, the Server Information page and the health page.
Until October 2026 three of them printed it and left the reader to judge it,
and the fourth judged it with its own rules written inline.

That was survivable while the only question was "is this 8.4 or newer". It
stopped being survivable when a SECURITY judgement had to be made as well --
whether the version is older than the release that fixed CVE-2026-109618 and
CVE-2026-109619, the pair reported as "PHP2Shell". A security judgement written
in two places is one that can disagree with itself, and then the portal tells a
customer on one page that they are fine and on another that they are not.

`Portal\\Core\\PhpRuntime` is now the one place that decides. This check fails
if a file under web/ compares PHP_VERSION against a version number itself.

WHAT THIS CHECK CANNOT SEE
--------------------------
1. A comparison built from a variable (`version_compare(PHP_VERSION, $floor)`).
   The pattern below needs a literal version number to recognise.
2. A rule written with the PHP_VERSION_ID integer constant instead
   (`PHP_VERSION_ID < 80400`). That is checked for separately below, but only
   in the obvious spelling.
3. A judgement made in JavaScript, or in a template this script does not read.
4. Anything that reads the version through a wrapper of its own making.
5. A version taken from somewhere other than this PHP process -- for example a
   version string read out of a settings row, a build file or another server's
   answer.

This list was WRONG when it was written, and the correction is worth keeping.
It did not mention `phpversion()`, and the pattern below only looked for the
PHP_VERSION constant -- so `version_compare(phpversion(), '8.4.0', '<')` passed
straight through. A review on 27 October 2026 planted exactly that line and both
this check and the self-test reported everything fine. `phpversion()` is the
obvious alternative spelling of the very expression this file exists to ban, so
the header was reassuring about ground it did not cover. Both spellings are now
matched. The lesson: a list of what a check cannot see is itself a claim, and an
untrue one is worse than no list, because it stops the next person looking.

So a clean run means "no obvious second rule", not "there is certainly only
one". Section I of tools/php-runtime-selftest.php checks the same thing from
the other direction, by proving each of the four pages ASKS PhpRuntime.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
WEB = ROOT / "web"

# The one file allowed to decide. Everything else must ask it.
THE_ONE_PLACE = "web/_core/PhpRuntime.php"

# Files that may legitimately PRINT the version without judging it: a backup
# manifest records what made it, a debug dump lists the environment, and the
# phpinfo page is a table of facts. None of them says "supported" or "safe".
ALLOWED_TO_PRINT_ONLY = {
    "web/_core/DbBackup.php",
    "web/_core/Debug.php",
    "web/_core/Router.php",
    "web/_apps/admin/system-info/phpinfo.php",
}

# A comparison of this PHP's own version against a literal version number, in
# either argument order, written either way round: the PHP_VERSION constant or
# the phpversion() function. Both spellings matter -- see the header.
_THIS_PHP = r"""(?:PHP_VERSION|phpversion\s*\(\s*\))"""
COMPARE = re.compile(
    r"""version_compare\s*\(\s*(?:""" + _THIS_PHP + r"""\s*,\s*['"][\d.]+['"]"""
    r"""|['"][\d.]+['"]\s*,\s*""" + _THIS_PHP + r""")""",
)

# The integer form of the same idea.
VERSION_ID = re.compile(r"PHP_VERSION_ID\s*[<>=!]")

# PHP comments explain the OLD rule in prose in several of these files, and a
# check that reads prose judges the wrong thing -- that mistake was made on
# this project on 27 October 2026 and is written down. Comments are stripped
# before the patterns are applied. This is a text-level strip, not a parse: it
# is deliberately simple, and it errs towards removing too much rather than too
# little, because a false alarm gets a check switched off and then it catches
# nothing.
BLOCK_COMMENT = re.compile(r"/\*.*?\*/", re.DOTALL)
LINE_COMMENT = re.compile(r"(?<!:)//[^\n]*")
HASH_COMMENT = re.compile(r"(?<!\$)#[^\n]*")


def without_comments(code: str) -> str:
    code = BLOCK_COMMENT.sub("", code)
    code = LINE_COMMENT.sub("", code)
    return HASH_COMMENT.sub("", code)


def main() -> int:
    problems: list[str] = []
    looked_at = 0

    for path in sorted(WEB.rglob("*.php")):
        rel = path.relative_to(ROOT).as_posix()
        if "_vendor" in rel:
            continue
        if rel == THE_ONE_PLACE or rel in ALLOWED_TO_PRINT_ONLY:
            continue

        looked_at += 1
        try:
            code = path.read_text(encoding="utf-8", errors="replace")
        except OSError as problem:  # unreadable file: say so, do not pass it
            problems.append(f"{rel}: could not be read ({problem})")
            continue

        bare = without_comments(code)

        for pattern, what in ((COMPARE, "version_compare() on this PHP's own version"),
                              (VERSION_ID, "a test on PHP_VERSION_ID")):
            for found in pattern.finditer(bare):
                # The line number within the comment-free text, which is the
                # only honest number available: stripping comments moves every
                # line, so a number taken from the original file would point at
                # the wrong place. The message says which text it counted.
                line = bare[: found.start()].count("\n") + 1
                problems.append(
                    f"{rel}: {what} (about line {line} of the comment-free text). "
                    f"Ask Portal\\Core\\PhpRuntime::classify() instead, and change the "
                    f"constants in {THE_ONE_PLACE} if the rule itself is wrong."
                )

    if problems:
        print("A second PHP version rule was found. Only one place may decide:")
        for problem in problems:
            print(f"  - {problem}")
        print(f"\n{len(problems)} problem(s) in {looked_at} files.")
        return 1

    print(f"OK: no second PHP version rule in {looked_at} files under web/ "
          f"({THE_ONE_PLACE} is the one place that decides).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
