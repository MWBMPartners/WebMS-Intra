#!/usr/bin/env python3
"""
mysqli_stmt::bind_param() arity check (#433).

On PHP 8, `mysqli_stmt::bind_param()` throws an uncaught `ValueError` when
the type-string length does not equal the number of bound variables —
`ValueError` is not a `mysqli_sql_exception`, so the repo-wide
`mysqli_report(MYSQLI_REPORT_STRICT)` setting does NOT catch it, and the
request dies with an HTTP 500. `php -l`, Psalm, and every other existing
audit check miss this class of bug because it is a runtime arity mismatch,
not a syntax or schema error.

For every `->bind_param('<literal-type-string>', $arg1, $arg2, …)` call
where the type-string is a STRING LITERAL, this script counts the
top-level comma-separated bound arguments and compares that count to the
type-string length. A mismatch is the fatal class described above.

Calls whose type-string is NOT a literal (a variable, `str_repeat(...)`,
string concatenation, etc.) are SKIPPED — they can't be evaluated
statically without risking false positives, so this check only ever
reports something it can prove from the literal text.

TODO (#433 follow-up): also associate the nearest preceding
`prepare('<literal SQL>')` call in the same function and compare its `?`
placeholder count against the bound-argument count — but only when BOTH
the SQL and the type-string are literals, to stay false-positive-free.
Skipped for the initial version because reliably scoping "nearest
preceding prepare() in the same function" without a real PHP parser is
fiddly enough to risk false positives; the arg-count-vs-type-length check
above already catches the fatal class on its own.

Exit code:
  0 — no findings (CI-green)
  1 — at least one arity mismatch (CI annotates but doesn't block merge
      unless invoked with --strict)

Usage:
  python3 tools/audit-checks/check_bind_param_arity.py [--strict]
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
PHP_ROOTS = [
    REPO_ROOT / "web" / "_install",
    REPO_ROOT / "web" / "_core",
    REPO_ROOT / "web" / "_apps",
    REPO_ROOT / "web" / "public_html",
]
# _vendor/ and _libraries/ are deliberately NOT in PHP_ROOTS (vendored /
# server-managed code isn't ours to fix), and .claude/worktrees/ sits
# outside web/ entirely so it's never visited either way.

# Match a single- or double-quoted string literal with no escapes/interp —
# exactly what a bind_param() type-string literal looks like in this
# codebase (`'issi'`, "issi", …).
STRLIT_RE = re.compile(r"^'([^'\\]*)'$|^\"([^\"\\]*)\"$")


def extract_balanced(text: str, start: int) -> tuple[str | None, int]:
    """text[start] is '('. Return (inner_text, index_after_matching_close).

    Balances nested parens and skips over quoted strings (so a `)` or `,`
    inside a string literal argument doesn't confuse the boundary), same
    approach as the other regex-driven checkers in this directory — not a
    real PHP tokenizer, but sufficient for a `bind_param(...)` call site.
    """
    depth = 0
    i = start
    quote: str | None = None
    while i < len(text):
        c = text[i]
        if quote:
            if c == "\\":
                i += 2
                continue
            if c == quote:
                quote = None
            i += 1
            continue
        if c in "'\"":
            quote = c
        elif c == "(":
            depth += 1
        elif c == ")":
            depth -= 1
            if depth == 0:
                return text[start + 1 : i], i + 1
        i += 1
    return None, len(text)


def split_top_level_args(s: str) -> list[str]:
    """Split a call-argument string on top-level commas (ignoring commas
    inside (), [], or quoted strings)."""
    args: list[str] = []
    depth = 0
    quote: str | None = None
    cur: list[str] = []
    i = 0
    while i < len(s):
        c = s[i]
        if quote:
            cur.append(c)
            if c == "\\" and i + 1 < len(s):
                cur.append(s[i + 1])
                i += 2
                continue
            if c == quote:
                quote = None
            i += 1
            continue
        if c in "'\"":
            quote = c
            cur.append(c)
        elif c in "([":
            depth += 1
            cur.append(c)
        elif c in ")]":
            depth -= 1
            cur.append(c)
        elif c == "," and depth == 0:
            args.append("".join(cur).strip())
            cur = []
            i += 1
            continue
        else:
            cur.append(c)
        i += 1
    tail = "".join(cur).strip()
    if tail:
        args.append(tail)
    return args


def check_file(path: Path) -> list[tuple[int, str]]:
    """Return [(line_no, message), …] for bind_param() arity mismatches."""
    findings: list[tuple[int, str]] = []
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return findings

    for m in re.finditer(r"bind_param\s*\(", text):
        paren = m.end() - 1
        inner, _ = extract_balanced(text, paren)
        if inner is None:
            continue
        args = split_top_level_args(inner)
        if not args:
            continue
        type_match = STRLIT_RE.match(args[0].strip())
        if not type_match:
            # Dynamic type-string (variable, str_repeat(), concatenation,
            # …) — not statically checkable, skip to stay false-positive-free.
            continue
        type_str = type_match.group(1) if type_match.group(1) is not None else type_match.group(2)
        nbound = len(args) - 1
        if len(type_str) != nbound:
            line_no = text.count("\n", 0, m.start()) + 1
            findings.append(
                (
                    line_no,
                    f"bind_param('{type_str}', …) — type-string length "
                    f"{len(type_str)} but {nbound} bound argument(s)",
                )
            )
    return findings


def check() -> int:
    findings: list[tuple[Path, int, str]] = []
    for root in PHP_ROOTS:
        if not root.exists():
            continue
        for php in sorted(root.rglob("*.php")):
            for line_no, msg in check_file(php):
                findings.append((php, line_no, msg))

    print(f"Files scanned in: {[str(r.relative_to(REPO_ROOT)) for r in PHP_ROOTS]}")
    print(f"bind_param() arity mismatches: {len(findings)}")
    print()
    if findings:
        print("### bind_param() arity mismatches (fatal ValueError on PHP 8)\n")
        for php, line_no, msg in findings:
            rel = str(php.relative_to(REPO_ROOT))
            print(f"  • {rel}:{line_no} — {msg}")
        print(
            "\nA mismatched type-string throws an uncaught ValueError on PHP 8 "
            "(mysqli_report STRICT does not catch it — it isn't a "
            "mysqli_sql_exception) => HTTP 500 on every call to this code path. "
            "Fix the type-string to have exactly one character per bound "
            "variable, in the same order."
        )

    strict = "--strict" in sys.argv
    if findings and strict:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(check())
