#!/usr/bin/env python3
"""
Path: tools/audit-checks/check_csrf_rotation_returned.py

Refuse a data handler that SPENDS the page's form token and hands back no
replacement.

WHAT GOES WRONG WITHOUT THIS
----------------------------
`Auth::verifyCsrf()` replaces the form token every time it accepts one. That is
deliberate and right: it stops one token being used twice. But a page that posts
in the background keeps the token it was drawn with. If the handler does not
return the replacement, the page is left holding a spent token and EVERY PRESS
AFTER THE FIRST is refused — and the refusal is a 403 that most scripts ignore,
so the control simply stops working and says nothing. That was live on the
live-chat moderation page and the Host Console, and nothing in the repository
caught it (#634, #625).

WHAT COUNTS AS HANDING IT BACK
------------------------------
Any one of:
  * `ApiAuth::requireWrite()`  — the house way; it records the rotation itself
    and `ApiResponse` publishes it in `meta.csrfToken`.
  * `ApiAuth::noteCsrfRotation()` — for a handler whose sign-in rules are its
    own and which therefore cannot use `requireWrite()`.
  * a literal `csrfToken` in the file — for a handler that answers with bare
    JSON and names the replacement itself.

THREE SHAPES THIS CHECK CANNOT SEE, said plainly so nobody trusts it too far
---------------------------------------------------------------------------
1. A handler that publishes the replacement on its SUCCESS but not on a refusal
   reached after the check. This check sees that a token is handed back
   somewhere in the file, not that every answer carries it.
2. The CALLING PAGE. A handler can be perfect while the page holds its token in
   a `const` and can never update it, which is what made the Host Console fault
   permanent rather than passing. Page behaviour is for
   `tools/page-scripts-selftest.php` and a browser.
3. A handler that reaches `verifyCsrf()` through a variable or a helper rather
   than naming it. Everything here is a plain text match on the code.
4. **Anything outside an `api/` folder.** Only `web/_apps/**/api/*.php` is read.
   `web/_apps/admin/reports/builder/preview.php` is a page that answers a
   background post and hands a token back, and this check never looks at it.
5. **The DIFFERENCE between a token in an answer and the same words anywhere
   else.** The third pattern looks for a quoted `csrfToken`/`csrf_token`
   followed by `=>` or `:`, which is what putting it in an answer looks like.
   An array that merely HAPPENS to have that shape — `['csrf_token' =>
   'required']` in a list of validation rules, say, or a `case 'csrf_token':`
   label — would satisfy it without any token being sent. Neither shape is in
   the tree today. Going the other way, a genuine handback written as
   `$out['csrfToken'] = …;` is NOT matched and would be reported as missing;
   that is the safe direction to be wrong in, but it is worth knowing before
   anybody puzzles over a complaint.

It strips comments before judging, because this project has already written a
check that failed on a page's own prose explaining the very thing it forbids.
"""

import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
API_GLOB = "web/_apps/**/api/*.php"

# Does this handler SPEND the token?
#
# `Auth::verifyCsrf(` is the spending check. `Auth::verifyCsrfWithoutSpending(`
# is NOT matched by that pattern — the `W` sits where the pattern wants
# optional whitespace and then `(` — so the two cannot be confused and nothing
# needs subtracting. An earlier version of this file subtracted one count from
# the other and said in a comment that it had to, "because
# verifyCsrfWithoutSpending contains verifyCsrf". **That was wrong, and the
# subtraction it justified silently excused a broken handler:** a file holding
# one real `verifyCsrf(` AND one `verifyCsrfWithoutSpending(` came out at
# 1 - 1 = 0 and was skipped entirely. Found by the independent check of #634.
SPENDS = re.compile(r"Auth::verifyCsrf\s*\(")

# What counts as handing the replacement back.
#
# Each pattern must show the token being PUT INTO AN ANSWER. Merely calling
# `Auth::csrfToken()` does not: that reads the token and may do nothing with
# it. An earlier version matched the bare word `csrfToken`, which `Auth::csrfToken()`
# contains, so a handler with a stray `$unused = Auth::csrfToken();` passed —
# and two real handlers (push/api/subscribe.php, push/api/unsubscribe.php)
# passed only by that accident. Also found by the #634 check.
#
# Three answer shapes are real in this portal, so three patterns:
#   * the ApiResponse envelope, via requireWrite() or noteCsrfRotation();
#   * a bare-JSON answer naming `csrfToken`;
#   * a bare-JSON answer naming `csrf_token` (the older spelling, used by the
#     push handlers).
HANDS_BACK = (
    re.compile(r"ApiAuth::requireWrite\s*\("),
    re.compile(r"ApiAuth::noteCsrfRotation\s*\("),
    # A quoted key or array key named csrfToken / csrf_token — i.e. the token
    # being placed in an answer, not merely read.
    re.compile(r"""['"]csrf_?[Tt]oken['"]\s*(?:=>|:)"""),
)

# Files that genuinely need no replacement, each with the reason. Keep this
# list empty unless there is a real reason: an entry here is a handler nobody
# is checking any more.
EXEMPT: dict[str, str] = {}


def strip_php_comments(text: str) -> str:
    """
    Remove /* … */, // … and # … so the check judges code, not prose.

    Deliberately simple: it does not understand a comment marker inside a
    string literal, which would make it drop slightly too much. Dropping too
    much is the safe direction here — it can only hide a mention, never invent
    one, and a hidden mention makes this check COMPLAIN rather than pass.
    """
    text = re.sub(r"/\*.*?\*/", " ", text, flags=re.S)
    out = []
    for line in text.splitlines():
        line = re.sub(r"//.*$", "", line)
        line = re.sub(r"#(?!\[).*$", "", line)  # keep #[Attribute]
        out.append(line)
    return "\n".join(out)


def main() -> int:
    failures: list[str] = []
    checked = 0

    for path in sorted(REPO.glob(API_GLOB)):
        rel = path.relative_to(REPO).as_posix()
        raw = path.read_text(encoding="utf-8", errors="replace")
        code = strip_php_comments(raw)

        # Does it spend the token?
        if SPENDS.search(code) is None:
            continue

        checked += 1
        if rel in EXEMPT:
            continue

        if any(pattern.search(code) for pattern in HANDS_BACK):
            continue

        failures.append(
            f"{rel}: checks the form token with Auth::verifyCsrf(), which "
            f"REPLACES it, and hands no replacement back. A page posting more "
            f"than once will be refused on its second press, silently. Use "
            f"ApiAuth::requireWrite(), or call ApiAuth::noteCsrfRotation() "
            f"straight after the check, or name csrfToken in every answer."
        )

    if failures:
        print("FAIL: handlers that spend the form token and return no replacement\n")
        for line in failures:
            print("  • " + line)
        print(f"\n{len(failures)} of {checked} token-spending handlers are wrong.")
        return 1

    print(
        f"OK: all {checked} data handlers that spend the form token hand a "
        f"replacement back."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
