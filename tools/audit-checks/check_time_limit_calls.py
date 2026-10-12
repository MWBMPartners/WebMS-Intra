#!/usr/bin/env python3
"""
CI guard — fail when PHP's time limit is raised without asking first (#563).

Some shared hosts switch `set_time_limit` off (the `disable_functions`
setting). On PHP 8 a switched-off function does not exist, so a bare
`@set_time_limit(300)` ends the request with "Call to undefined function".
The `@` does not help: it hides warnings, not that kind of error.

Everything in the portal asks for more time through
`Portal\\Core\\TimeLimit::atLeast()`, which checks `function_exists()` first
and never lowers a limit. This check fails any other call to
`set_time_limit(` under web/ unless:
  - it is inside web/_core/TimeLimit.php (the one place allowed), or
  - one of the three lines above it asks `function_exists('set_time_limit')`
    (how FeedAdmin::allowTimeForLockedWork() does it).

Lines that are only comments are skipped, so a comment may explain the
function without tripping the check.

Exit code:
  0 — no findings.
  1 — at least one unguarded call. (--strict is accepted and changes nothing:
      this check always fails on a finding, because a quiet pass would let the
      crash back in.)

Usage:
  python3 tools/audit-checks/check_time_limit_calls.py [--strict]
  python3 tools/audit-checks/check_time_limit_calls.py --root <folder>   (used by the tests)
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

# A CALL: the name followed by an opening bracket. `function_exists('set_time_limit')`
# has a quote after the name, so it does not match.
#
# Capitals are ignored, because PHP ignores them in function names:
# `SET_TIME_LIMIT(300)` is the same call and crashes the same way. The first
# version of this check matched lower case only and let that spelling through.
CALL_RE = re.compile(r"\bset_time_limit\s*\(", re.IGNORECASE)
GUARD_RE = re.compile(r"function_exists\s*\(\s*['\"]set_time_limit['\"]\s*\)", re.IGNORECASE)
ALLOWED = {"web/_core/TimeLimit.php"}


def is_comment_only(line: str) -> bool:
    stripped = line.lstrip()
    return stripped.startswith(("//", "#", "/*", "*", "<!--"))


def scan(root: Path) -> tuple[list[tuple[str, int, str]], int]:
    """Return (findings, number of calls seen). Findings are (file, line, text)."""
    findings: list[tuple[str, int, str]] = []
    calls = 0
    web = root / "web"
    for path in sorted(web.rglob("*.php")):
        rel = path.relative_to(root).as_posix()
        if rel in ALLOWED:
            continue
        lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
        for i, line in enumerate(lines):
            if is_comment_only(line) or CALL_RE.search(line) is None:
                continue
            calls += 1
            context = lines[max(0, i - 3): i + 1]  # the three lines above, and this one
            if any(GUARD_RE.search(c) for c in context):
                continue
            findings.append((rel, i + 1, line.strip()))
    return findings, calls


def main(argv: list[str]) -> int:
    root = REPO_ROOT
    if "--root" in argv:
        root = Path(argv[argv.index("--root") + 1]).resolve()

    findings, calls = scan(root)
    print(f"Time-limit audit — {calls} call(s) to set_time_limit() outside TimeLimit.php")
    if not findings:
        print("Every call asks function_exists() first. ✅")
        return 0

    print(f"\n### set_time_limit() called without asking first ({len(findings)})\n")
    for rel, lineno, text in findings:
        print(f"  • {rel}:{lineno} — {text}")
    print("\nUse Portal\\Core\\TimeLimit::atLeast($seconds) instead.")
    return 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
