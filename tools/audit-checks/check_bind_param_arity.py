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
  0 — no findings
  1 — at least one arity mismatch; or, with --self-test, a made-up call judged
      wrongly
  (D-046, 4 November 2026: a finding now stops the work. This used to need
  --strict, so it printed a fault that kills a page and still exited 0 —
  nothing failed and nobody was forced to look. `--strict` is still accepted
  and now makes no difference. The change was only safe once #730's false
  alarm was fixed, because gating a check that cries wolf blocks every push.)

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


def mask_php(source: str) -> str:
    """
    The same text, with comments blanked and every string literal's contents
    replaced by `x` — SAME LENGTH, so every offset still lines up with the
    original, and with newlines kept so reported line numbers stay true.

    🐺 WHY THIS EXISTS, AND IT WAS A LIVE FALSE ALARM (#730). Without it, the
       quote-tracking in `extract_balanced()` and `split_top_level_args()` was
       walking the RAW source, and an apostrophe inside a `//` comment opened a
       string literal that never closed. In
       `web/_apps/calendar/manage/save.php` the comment

           // 📅 The series' OWN first date, not the one typed on the …

       sits between two of the call's arguments, so from "series'" onwards every
       later quote was out of step: the walk ran past the real closing
       parenthesis and swallowed the rest of the function. An eleven-argument
       call — eleven columns, eleven placeholders, eleven letters, eleven values,
       all correct — was reported as "type-string length 11 but 23 bound
       argument(s)" on every push and in every pull request's comment.

       **That is the fault this repository has recorded most often**, and it has
       broken `check_page_head_tags.py` (an apostrophe in `// don't`),
       `check_no_brotli_decode.py` (a `//` inside a URL) and
       `check_no_new_role_gates.py` (an apostrophe inside a heredoc). A check
       that cries wolf gets switched off (#483), and then it catches nothing.

    ⚠️ BLANKING, NEVER DELETING. Deleting comments once put reported line
       numbers 370 out (#716). Every replacement is the same length as what it
       replaces, and a newline stays a newline.

    ⚠️ AND THE TYPE-STRING IS READ FROM THE ORIGINAL, NOT FROM THIS. Masking is
       what makes a comment and a string disappear, but the type-string IS a
       string literal, so reading it here would give `bind_param('xxxxxxxxxxx')`
       — the right LENGTH by luck, and the wrong letters in the message. Exactly
       that mistake broke both halves of #716's guard. `check_text()` finds the
       call in the masked text and slices the original at the same offset.

    WHAT IT STILL CANNOT SEE: a type-string built by concatenation or held in a
    variable (those are skipped anyway, deliberately), and PHP it cannot
    tokenise — this is a walk, not a parser.
    """
    out: list[str] = []
    i = 0
    n = len(source)
    while i < n:
        ch = source[i]
        nxt = source[i + 1] if i + 1 < n else ""
        # A line comment. `#[` is an attribute, not a comment.
        if (ch == "/" and nxt == "/") or (ch == "#" and nxt != "["):
            while i < n and source[i] != "\n":
                out.append(" ")
                i += 1
            continue
        if ch == "/" and nxt == "*":
            while i < n and not (source[i] == "*" and i + 1 < n and source[i + 1] == "/"):
                out.append("\n" if source[i] == "\n" else " ")
                i += 1
            out.append("  " if i < n else "")
            i += 2
            continue
        # 📜 A heredoc or nowdoc. Its body is masked like any other string
        #    literal; the labels are kept so offsets hold. This branch's absence
        #    was a real hole in `check_no_new_role_gates.py` (#739): a nowdoc
        #    holding `<p>Don't change this</p>` made that check report
        #    "Nothing wrong" over a live finding.
        if ch == "<" and source[i:i + 3] == "<<<":
            opener = re.match(
                r'<<<[ \t]*(?:"([A-Za-z_]\w*)"|\'([A-Za-z_]\w*)\'|([A-Za-z_]\w*))\r?\n',
                source[i:],
            )
            if opener is not None:
                label = opener.group(1) or opener.group(2) or opener.group(3)
                out.append(opener.group(0))
                i += opener.end()
                closer = re.compile(r"^[ \t]*" + re.escape(label) + r"\b", re.M)
                found = closer.search(source, i)
                stop = found.start() if found is not None else n
                for position in range(i, stop):
                    out.append("\n" if source[position] == "\n" else "x")
                i = stop
                continue
            # `<<<` that is not a heredoc opener: ordinary text.
        if ch in ("'", '"'):
            quote = ch
            out.append(ch)
            i += 1
            while i < n and source[i] != quote:
                if source[i] == "\\" and i + 1 < n:
                    out.append("xx")
                    i += 2
                    continue
                out.append("\n" if source[i] == "\n" else "x")
                i += 1
            if i < n:
                out.append(quote)
                i += 1
            continue
        out.append(ch)
        i += 1
    return "".join(out)


def split_top_level_arg_spans(s: str) -> list[tuple[int, int]]:
    """Where each top-level comma-separated argument starts and ends in `s`.

    Spans rather than text, because the type-string has to be read from the
    UNMASKED source at the same offset — see `mask_php()`. Commas inside (),
    [] or a quoted string do not split.
    """
    spans: list[tuple[int, int]] = []
    depth = 0
    quote: str | None = None
    start = 0
    i = 0
    while i < len(s):
        c = s[i]
        if quote:
            if c == "\\" and i + 1 < len(s):
                i += 2
                continue
            if c == quote:
                quote = None
            i += 1
            continue
        if c in "'\"":
            quote = c
        elif c in "([":
            depth += 1
        elif c in ")]":
            depth -= 1
        elif c == "," and depth == 0:
            spans.append((start, i))
            start = i + 1
        i += 1
    if s[start:].strip():
        spans.append((start, len(s)))
    return spans


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
    # 🎭 Comments blanked and string contents masked FIRST, or an apostrophe in
    #    a comment runs the argument walk past the end of the call (#730). The
    #    mask is the same length as the source, so every offset below indexes
    #    both.
    masked = mask_php(text)
    for m in re.finditer(r"bind_param\s*\(", masked):
        paren = m.end() - 1
        inner, _ = extract_balanced(masked, paren)
        if inner is None:
            continue
        base = paren + 1
        spans = split_top_level_arg_spans(inner)
        if not spans:
            continue
        # ⚠️ The type-string comes from the ORIGINAL at the same offset. Read
        #    from the mask it would be `'xxxxxxxxxxx'` — the right length by
        #    luck and the wrong letters in the message (#716).
        first_start, first_end = spans[0]
        type_match = STRLIT_RE.match(text[base + first_start:base + first_end].strip())
        if not type_match:
            # Dynamic type-string (variable, str_repeat(), concatenation,
            # …) — not statically checkable, skip to stay false-positive-free.
            continue
        type_str = type_match.group(1) if type_match.group(1) is not None else type_match.group(2)
        bound = [inner[s:e].strip() for s, e in spans[1:]]
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
    # 🐺 THE #730 SHAPES. Each is here TWICE, right and wrong, because a fix
    #    that simply stopped reporting would pass the "right" half on its own —
    #    which is the "louder silence" the issue warned about.
    ("an apostrophe in a comment between the arguments, and the call is RIGHT (the live false alarm)",
     "$s->bind_param(\n    'ii',\n    $a,\n    // The series' OWN first date, not the one typed\n    $b\n);", 0),
    ("the same comment, and the call is WRONG: masking must not blind it",
     "$s->bind_param(\n    'i',\n    $a,\n    // The series' OWN first date, not the one typed\n    $b\n);", 1),
    ("a `//` inside a string is not a comment, and the call is RIGHT",
     "$s->bind_param('ss', $a, 'https://example.invalid/x');", 0),
    ("a `//` inside a string, and the call is WRONG",
     "$s->bind_param('s', $a, 'https://example.invalid/x');", 1),
    ("a block comment holding an apostrophe, and the call is RIGHT",
     "$s->bind_param('ii', $a, /* the series' date */ $b);", 0),
    ("a block comment holding an apostrophe, and the call is WRONG",
     "$s->bind_param('iii', $a, /* the series' date */ $b);", 1),
    # 📜 A heredoc whose body holds an ODD number of apostrophes. This is the
    #    shape that made check_no_new_role_gates.py report "Nothing wrong" over
    #    a live finding (#739), and it is latent here for the same reason: with
    #    the heredoc branch removed, "Don't" opens a string that swallows the
    #    call below it.
    ("a heredoc holding an apostrophe, then a WRONG call below it",
     "$html = <<<'HTML'\n<p>Don't change this</p>\nHTML;\n$s->bind_param('i', $a, $b);", 1),
    ("a heredoc holding an apostrophe, then a RIGHT call below it",
     "$html = <<<'HTML'\n<p>Don't change this</p>\nHTML;\n$s->bind_param('ii', $a, $b);", 0),
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
    # 🔤 THE LETTERS IN THE MESSAGE COME FROM THE SOURCE, NOT FROM THE MASK —
    #    and this needs its own check because EVERY count-based case above
    #    passes either way. Masking replaces a string's contents with `x` of
    #    the SAME LENGTH, so `len($type_str)` is right whichever text it is read
    #    from; only the letters printed to a developer are wrong. Measured:
    #    reading from the mask leaves all 24 cases above green while the message
    #    reads `bind_param('xxx', …)`.
    #
    #    A first version of this file added a case shaped like the others for
    #    it — same PHP, expected count 0 — and that case COULD NOT FAIL. It is
    #    the shape this repository keeps finding, written while fixing another
    #    instance of it.
    message = check_text("$s->bind_param('iss', $a, $b);")[0]
    lettered = len(message) == 1 and "'iss'" in message[0][1]
    print(f"  {'ok  ' if lettered else 'FAIL'}  the letters in the message come from the source, not the mask: "
          f"{message[0][1] if message else '(no finding at all)'}")
    if not lettered:
        wrong += 1

    spreads = sum(check_text(php)[1] for _, php, _ in SELF_TEST_CASES)
    counted = spreads == 7
    print(f"  {'ok  ' if counted else 'FAIL'}  calls with a literal type-string and a spread-out list are counted: {spreads}, 7 expected")
    if not counted:
        wrong += 1
    print(f"\n{len(SELF_TEST_CASES) + 2 - wrong} of {len(SELF_TEST_CASES) + 2} self-test checks passed.")
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

    # 🚧 A FINDING STOPS THE WORK (the owner, 4 November 2026 — D-046).
    #    This used to need `--strict`, so it printed a fatal-class fault and
    #    exited 0: nothing failed and nobody was forced to look. That is the
    #    #647 shape — `check_schema_seed_parity.py` named a missing migration
    #    mark while exiting 0, which is how migration 223 nearly shipped
    #    unrecorded.
    #
    #    It could only become a gate once the false alarm was gone (#730): an
    #    apostrophe in a comment made this report an eleven-argument call as
    #    "23 bound arguments" on every run, and gating THAT would have blocked
    #    every push. Fixing the wolf-crying comes first; the gate second.
    #
    #    `--strict` is still accepted so older invocations keep working, and it
    #    now makes no difference. The pre-push hook passes NO arguments and
    #    judges the exit code, which is exactly why the default had to change
    #    rather than a flag being added somewhere.
    if findings:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(check())
