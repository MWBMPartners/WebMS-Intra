#!/usr/bin/env python3
"""
A two-factor gate takes the sign-in away BEFORE it does anything else (#B2).

Path: tools/audit-checks/check_2fa_gate_order.py

WHY THIS EXISTS
---------------
Signing in happens in two steps for anybody with two-factor authentication on.
First the portal decides who they are (password, Microsoft, Google, or a
passkey) and writes `$_SESSION['user_id']`. Then a gate demotes that session to
"waiting for a code": it sets `2fa_user_id`, unsets `user_id`, and sends the
person to /auth/2fa/verify.

On 27 October 2026 all five of those gates did the demotion in the wrong order.
They worked out where to send the person FIRST:

    $_SESSION['2fa_user_id']    = $userId;
    $_SESSION['login_redirect'] = Auth::safeRedirectUrl($_GET['redirect'] ?? '/');
    unset($_SESSION['user_id'], $_SESSION['2fa_passed']);          # too late

`Auth::safeRedirectUrl()` was declared `string $url`, and a request chooses the
TYPE of what it sends as well as the value: `?redirect[]=x` hands PHP an array,
which threw a TypeError. The `unset()` was never reached, PHP wrote the session
at shutdown, and the next request saw somebody fully signed in who had never
been asked for a code. On the password path that needed nothing but the
victim's password — which is the one thing two-factor exists to survive.

Two changes closed it: `safeRedirectUrl()` now takes `mixed` and refuses a
non-string, and every gate unsets first.

WHY A CHECK, WHEN THE SELF-TEST ALREADY COVERS IT
-------------------------------------------------
Because it does not, on its own. The independent check of the fix measured
this: with `mixed` in place, moving an `unset()` back below the redirect is
INVISIBLE to the end-to-end self-test — 93 of 93 still passed. The two halves
of the fix defend different things, and only the ordering half survives
somebody later putting a throwing call inside a gate. So the ordering needs a
check that reads the shape of the code rather than its behaviour.

WHAT IT LOOKS FOR
-----------------
Every place under web/ that writes `$_SESSION['2fa_user_id']`. For each, it
reads the lines that follow and fails unless `unset($_SESSION['user_id'] …)`
comes BEFORE any write of `$_SESSION['login_redirect']`. A gate that never
unsets `user_id` at all fails too.

WHAT IT CANNOT SEE (said plainly, because a check that reports nothing is
easily taken to mean there is nothing)
-------------------------------------------------------------------------
  - WHETHER ANYTHING ELSE IN THE GATE CAN THROW. It only orders those two
    statements. A gate that unsets first and then calls something that dies is
    safe for this fault, but this check is not what makes it safe.
  - A GATE WRITTEN DIFFERENTLY. It finds gates by the name `2fa_user_id`. A new
    path that demotes a session some other way is invisible to it.
  - THE OTHER HALF OF THE FIX. It says nothing about `safeRedirectUrl()`'s
    signature. Narrowing that back to `string` would not fail here.
"""

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

# How far past the write of 2fa_user_id to look for the other two statements. A
# gate is a handful of lines; 25 is generous and keeps the check from wandering
# into whatever follows the block.
WINDOW = 25

WRITES_PENDING = re.compile(r"\$_SESSION\s*\[\s*['\"]2fa_user_id['\"]\s*\]\s*=")
UNSETS_SIGNIN = re.compile(r"unset\s*\(\s*\$_SESSION\s*\[\s*['\"]user_id['\"]\s*\]")
WRITES_REDIRECT = re.compile(r"\$_SESSION\s*\[\s*['\"]login_redirect['\"]\s*\]\s*=")


def strip_comments(line: str) -> str:
    """Take out // and # comments so a sentence describing the fault is not read as code."""
    out = re.sub(r"/\*.*?\*/", "", line)
    out = re.sub(r"(//|#).*$", "", out)
    return out


def check(web: Path) -> int:
    problems: list[str] = []
    gates = 0

    for path in sorted(web.rglob("*.php")):
        raw = path.read_text(encoding="utf-8", errors="replace").splitlines()
        lines = [strip_comments(line) for line in raw]
        for i, line in enumerate(lines):
            if WRITES_PENDING.search(line) is None:
                continue
            gates += 1
            where = path.relative_to(REPO_ROOT)
            unset_at = None
            redirect_at = None
            for j in range(i, min(i + WINDOW, len(lines))):
                if unset_at is None and UNSETS_SIGNIN.search(lines[j]) is not None:
                    unset_at = j
                if redirect_at is None and WRITES_REDIRECT.search(lines[j]) is not None:
                    redirect_at = j
            if unset_at is None:
                problems.append(
                    f"{where}:{i + 1} sets 2fa_user_id and never unsets user_id within "
                    f"{WINDOW} lines, so the session keeps a sign-in that has not earned it"
                )
                continue
            if redirect_at is not None and redirect_at < unset_at:
                problems.append(
                    f"{where}:{redirect_at + 1} writes login_redirect BEFORE "
                    f"{where}:{unset_at + 1} unsets user_id. Put the unset() first: if "
                    f"anything between them throws or exits, the session stays signed in "
                    f"with the second factor never asked for"
                )

    if gates == 0:
        print("check_2fa_gate_order: found no two-factor gate at all. Either they were renamed")
        print("or this check is looking in the wrong place; it is not safe to report success.")
        return 1

    if not problems:
        print(f"check_2fa_gate_order: PASS - all {gates} two-factor gates take the sign-in away first.")
        return 0

    print(f"check_2fa_gate_order: FAIL - {len(problems)} of {gates} two-factor gates are in the wrong order.\n")
    for problem in problems:
        print(f"  - {problem}\n")
    print("Why it matters: a gate that works out where to send somebody before it removes their")
    print("sign-in leaves them signed in if that work throws. On the password path this needed")
    print("nothing but the victim's password. Found 27 October 2026; see the comment at the top")
    print("of this file and the notes in web/_core/Auth.php.")
    return 1


def main(argv: list[str]) -> int:
    web = REPO_ROOT / "web"
    for i, arg in enumerate(argv):
        if arg == "--web" and i + 1 < len(argv):
            web = Path(argv[i + 1]).resolve()
    if not web.is_dir():
        print(f"check_2fa_gate_order: {web} is not a folder. Nothing was checked.")
        return 1
    return check(web)


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
