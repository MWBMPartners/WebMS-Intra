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

A LIST SPREAD OUT (`...$values`), added 13 October 2026. PHP hands every item
of the list to bind_param() as a value of its own, so one written argument
stands for a number of values this script cannot read from the text. It used
to count such an argument as ONE value, and so reported
`bind_param('sssssssssss', $key, $problem, $words, $mark, ...$values)` in
web/_core/MailRoutes.php (eleven letters, "5 bound arguments") on every run,
although the list holds seven items and the call is right. That false alarm
sat in every pull request's comment. What is done now:
  - the values written out one by one are counted as before;
  - a spread-out list counts as "none or more";
  - so such a call is reported only when it is wrong whatever the list holds:
    more values written out than there are letters.
WHAT THAT CANNOT CATCH: a spread-out list of the wrong length (too few
letters for the list, or too many letters). That is only known when the code
runs, and PHP then stops with the ValueError described above. The count of
such calls is printed, so that "0 mismatches" is not read as "every call was
checked exactly".

`--self-test` runs the rule over made-up calls of each shape, right and
wrong, and exits 1 unless every one is judged as written beside it. It reads
no file of the portal.

TODO (#433 follow-up): also associate the nearest preceding
`prepare('<literal SQL>')` call in the same function and compare its `?`
placeholder count against the bound-argument count — but only when BOTH
the SQL and the type-string are literals, to stay false-positive-free.
Skipped for the initial version because reliably scoping "nearest
preceding prepare() in the same function" without a real PHP parser is
fiddly enough to risk false positives; the arg-count-vs-type-length check
above already catches the fatal class on its own.

Exit code:
  0 — no findings, or findings without --strict (the pull-request run puts
      them in its comment and blocks nothing)
  1 — at least one arity mismatch AND --strict was given; or, with
      --self-test, a made-up call judged wrongly
  (This used to say "1 — at least one arity mismatch". The code has always
  needed --strict for that.)

Usage:
  python3 tools/audit-checks/check_bind_param_arity.py [--strict]
  python3 tools/audit-checks/check_bind_param_arity.py --self-test
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


def is_spread(arg: str) -> bool:
    """Is this written argument a list spread out (`...$values`)?

    PHP allows a space after the three dots. A STRING that holds three dots
    (`'...'`) starts with a quote, not a dot, so it is not taken for one.
    """
    return arg.strip().startswith("...")


def check_text(text: str) -> tuple[list[tuple[int, str]], int]:
    """Judge every bind_param() call in a piece of PHP text.

    Returns the findings, as (line_no, message), and how many calls with a
    literal type-string held a spread-out list (those are checked only for
    "more values written out than letters": see the top of this file).
    """
    findings: list[tuple[int, str]] = []
    with_spread = 0
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
        bound = args[1:]
        written_out = sum(1 for arg in bound if not is_spread(arg))
        line_no = text.count("\n", 0, m.start()) + 1
        if written_out != len(bound):
            # At least one list is spread out, so the real number of values is
            # "written_out or more". Only "more written out than letters" is
            # wrong whatever the list holds.
            with_spread += 1
            if len(type_str) < written_out:
                findings.append(
                    (
                        line_no,
                        f"bind_param('{type_str}', …) — type-string length "
                        f"{len(type_str)} but {written_out} bound argument(s) written out, "
                        "before the list that is spread out is counted at all",
                    )
                )
        elif len(type_str) != written_out:
            findings.append(
                (
                    line_no,
                    f"bind_param('{type_str}', …) — type-string length "
                    f"{len(type_str)} but {written_out} bound argument(s)",
                )
            )
    return findings, with_spread


def check_file(path: Path) -> tuple[list[tuple[int, str]], int]:
    """The same for one file. A file that cannot be read gives nothing."""
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return [], 0
    return check_text(text)


# The made-up calls of --self-test: the PHP text, and how many findings it
# must give. Each shape is here twice, right and wrong, so that a rule which
# stopped reporting anything would fail as surely as one that reported too
# much.
SELF_TEST_CASES = [
    ("values written out, the right number of letters", "$s->bind_param('is', $a, $b);", 0),
    ("values written out, one letter too many", "$s->bind_param('iss', $a, $b);", 1),
    ("values written out, one letter too few", "$s->bind_param('i', $a, $b);", 1),
    ("a list spread out after four values, eleven letters (the MailRoutes call)",
     "$stmt->bind_param('sssssssssss', $key, $problem, $words, $mark, ...$values);", 0),
    ("a list spread out, exactly as many letters as values written out (the list may be empty)",
     "$s->bind_param('ss', $a, $b, ...$rest);", 0),
    ("a list spread out, FEWER letters than values written out: wrong whatever the list holds",
     "$s->bind_param('s', $a, $b, ...$rest);", 1),
    ("nothing but a list spread out", "$s->bind_param('ss', ...$rest);", 0),
    ("a space after the three dots", "$s->bind_param('sss', $a, ... $rest);", 0),
    ("a call spread out, with commas of its own inside", "$s->bind_param('sss', ...array_merge($a, [$b, $c]));", 0),
    ("two lists spread out and one value, no letters at all", "$s->bind_param('', $a, ...$x, ...$y);", 1),
    ("a text of three dots is a value, not a list: right", "$s->bind_param('ss', $a, '...');", 0),
    ("a text of three dots is a value, not a list: wrong", "$s->bind_param('s', $a, '...');", 1),
    ("the letters are not written out as a text: not judged", "$s->bind_param($types, $a, $b, $c);", 0),
    ("the letters are put together: not judged", "$s->bind_param('i' . str_repeat('i', count($ids)), $siteId, ...$ids);", 0),
    ("two calls in one text, the second wrong", "$s->bind_param('i', $a);\n$t->bind_param('ii', $a);", 1),
]


def self_test() -> int:
    """Run the rule over the made-up calls above. 0 only when every one is judged as written."""
    wrong = 0
    for label, php, expected in SELF_TEST_CASES:
        findings, _ = check_text(php)
        ok = len(findings) == expected
        print(f"  {'ok  ' if ok else 'FAIL'}  {label}: {len(findings)} finding(s), {expected} expected")
        if not ok:
            wrong += 1
    spreads = sum(check_text(php)[1] for _, php, _ in SELF_TEST_CASES)
    counted = spreads == 7
    print(f"  {'ok  ' if counted else 'FAIL'}  calls with a literal type-string and a spread-out list are counted: {spreads}, 7 expected")
    if not counted:
        wrong += 1
    print(f"\n{len(SELF_TEST_CASES) + 1 - wrong} of {len(SELF_TEST_CASES) + 1} self-test checks passed.")
    print("SELF-TEST REACHED THE END")
    return 0 if wrong == 0 else 1


def check() -> int:
    if "--self-test" in sys.argv:
        return self_test()
    findings: list[tuple[Path, int, str]] = []
    with_spread = 0
    for root in PHP_ROOTS:
        if not root.exists():
            continue
        for php in sorted(root.rglob("*.php")):
            file_findings, file_spread = check_file(php)
            with_spread += file_spread
            for line_no, msg in file_findings:
                findings.append((php, line_no, msg))

    # The first two lines keep their places: the pull-request workflow leaves
    # them out of its comment by counting lines.
    print(f"Files scanned in: {[str(r.relative_to(REPO_ROOT)) for r in PHP_ROOTS]}")
    print(f"bind_param() arity mismatches: {len(findings)}")
    print(f"Calls with a list spread out, checked only for \"more values written out than letters\": {with_spread}")
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
