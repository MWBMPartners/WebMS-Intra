#!/usr/bin/env python3
"""
Every web-root file that starts the portal hands a public-door request over FIRST (#493 P4a).

Path: tools/audit-checks/check_webroot_door_handoff.py

WHY THIS EXISTS
---------------
D-098: an organisation's public address answers the public door's requests and
NOTHING else. The door is recognised by `Site::preDetect()` while the portal
starts up, but it is only ANSWERED by whichever file the web server ran. Three
files under web/public_html/ start the portal themselves -- index.php, error.php
(Apache's ErrorDocument target for every refused .php, dotfile and
403/404/500/503) and api-docs/index.php -- and the independent check found, in
turn: the last two drawing a full portal page with a session cookie on a public
address (round 1), and the API description drawing organisation 1's page when
the public-address look-up itself had FAILED (round 2). The cure in every file
is the same two statements, straight after start-up:

    if (... Site::isDoorRequest() ...)        { PublicDoor::notFound(); }   (index.php: PublicDoor::answer())
    if (... Site::publicAddressUnknown() ...) { PublicDoor::tryAgain(); }

A NEW file in the web root that loads start-up is the realistic way this comes
back, and nothing would notice: it would work perfectly on the members' address.

THE RULE, POSITIONAL (round 3 of the check)
-------------------------------------------
The first version only required both questions somewhere above the first
`ensureSession(`, so `session_start()` or `Auth::csrfToken()` written above them
passed while its own success line said "before any session starts" (measured in
a new file, in api-docs/index.php and in error.php). Now, in every PHP file
under web/public_html/ whose code names `bootstrap.php`:

  1. the statement that requires start-up is found (the require whose statement
     names `bootstrap.php`, or the first require after the string is assigned);
  2. what follows it may be `use` statements only (they are compile-time), and
     then the NEXT TWO statements must be exactly the two hand-offs above, in
     that order: an `if` whose condition calls `Site::isDoorRequest()` and whose
     body BEGINS with a call to `PublicDoor::notFound()` or
     `PublicDoor::answer()`, then an `if` whose condition calls
     `Site::publicAddressUnknown()` and whose body begins with a call to
     `PublicDoor::tryAgain()`. Nothing else may come between start-up and them.

Class names are resolved through the file's own `use … as …` lines, and a
fully-qualified `\\Portal\\Core\\Site` counts. It reads PHP with PHP's own
tokeniser, so a comment or a STRING naming either question never counts. It
REFUSES, with no fallback, when `php` cannot be run.

WHAT IT STILL CANNOT SEE, said plainly
--------------------------------------
- A dead branch: `if (false && Site::isDoorRequest())` has the right shape and
  never runs. Likewise a condition that calls the question and ignores its
  answer, or a body that calls the right method after something that exits.
  tools/public-door-selftest.php drives all three files over HTTP, on a public
  address and in the failure state, with a planted fault for each hand-off.
- Start-up loaded through ANOTHER file this one includes (the text
  `bootstrap.php` must be in this file's own code).
- Whether PublicDoor's three methods really exit (they are declared `never`).

USAGE
    python3 -I tools/audit-checks/check_webroot_door_handoff.py

Package:   WebMS-Intra
Author:    MWBM Partners Ltd (t/a MWservices)
Copyright: All Rights Reserved
Version:   2.0.0
"""

from __future__ import annotations

import json
import shutil
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
WEBROOT = REPO / "web/public_html"
# The three known entry points. One that stops loading start-up, or
# disappears, is reported too: the list must stay true.
KNOWN = {"index.php", "error.php", "api-docs/index.php"}
REQUIRES = {"T_REQUIRE", "T_REQUIRE_ONCE", "T_INCLUDE", "T_INCLUDE_ONCE"}
NAME_TOKENS = {"T_STRING", "T_NAME_QUALIFIED", "T_NAME_FULLY_QUALIFIED", "T_NAME_RELATIVE"}
HANDOFFS = [
    ("Site", "isDoorRequest", ("PublicDoor", ("notFound", "answer"))),
    ("Site", "publicAddressUnknown", ("PublicDoor", ("tryAgain",))),
]

# Every token but whitespace, comments and inline HTML, as [name, text]
# (single-character tokens get the name "CHAR").
TOKENISE = r"""
$out = [];
foreach (token_get_all(file_get_contents($argv[1])) as $t) {
    if (is_array($t)) {
        if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG, T_CLOSE_TAG], true)) { continue; }
        $out[] = [token_name($t[0]), $t[1]];
    } else {
        $out[] = ['CHAR', $t];
    }
}
echo json_encode($out);
"""


class Refused(Exception):
    pass


def tokens(php: str, path: Path) -> list:
    run = subprocess.run([php, "-r", TOKENISE, str(path)], capture_output=True, text=True)
    if run.returncode != 0:
        raise Refused("php could not tokenise %s: %s" % (path, run.stderr.strip()[:200]))
    return json.loads(run.stdout)


def aliases(toks: list) -> dict[str, str]:
    """`use A\\B\\C;` and `use A\\B\\C as D;` at the top level: short name -> class's own last segment."""
    out: dict[str, str] = {}
    depth = 0
    i = 0
    while i < len(toks):
        name, text = toks[i]
        if text == "{":
            depth += 1
        elif text == "}":
            depth -= 1
        elif name == "T_USE" and depth == 0:
            j = i + 1
            parts = []
            while j < len(toks) and toks[j][1] != ";":
                parts.append(toks[j])
                j += 1
            if parts and parts[0][0] in NAME_TOKENS:
                full = parts[0][1].lstrip("\\").split("\\")[-1]
                short = full
                if len(parts) >= 3 and parts[1][0] == "T_AS":
                    short = parts[2][1]
                out[short] = full
            i = j
        i += 1
    return out


def class_of(text: str, alias: dict[str, str]) -> str:
    if "\\" in text:
        return text.lstrip("\\").split("\\")[-1]
    return alias.get(text, text)


def statement_end(toks: list, i: int) -> int:
    """The index of the `;` ending the statement that starts at i (parentheses balanced)."""
    depth = 0
    while i < len(toks):
        t = toks[i][1]
        if t in ("(", "["):
            depth += 1
        elif t in (")", "]"):
            depth -= 1
        elif t == ";" and depth == 0:
            return i
        i += 1
    return len(toks) - 1


def match_close(toks: list, i: int, open_: str, close: str) -> int:
    depth = 0
    while i < len(toks):
        if toks[i][1] == open_:
            depth += 1
        elif toks[i][1] == close:
            depth -= 1
            if depth == 0:
                return i
        i += 1
    return -1


def calls(toks: list, alias: dict[str, str], cls: str, methods: tuple) -> bool:
    """Do these tokens hold `<cls>::<method>(` (cls resolved through aliases)?"""
    for k in range(len(toks) - 3):
        if (toks[k][0] in NAME_TOKENS and class_of(toks[k][1], alias) == cls and toks[k + 1][0] == "T_DOUBLE_COLON"
                and toks[k + 2][0] == "T_STRING" and toks[k + 2][1] in methods and toks[k + 3][1] == "("):
            return True
    return False


def check_file(rel: str, toks: list) -> list[str]:
    alias = aliases(toks)
    at = next((k for k, t in enumerate(toks) if t[0] == "T_CONSTANT_ENCAPSED_STRING" and "bootstrap.php" in t[1]), -1)
    if at < 0:
        return []
    # The require that loads it: in the string's own statement, or the next one after it.
    start = at
    while start > 0 and toks[start - 1][1] not in (";", "{", "}"):
        start -= 1
    req = next((k for k in range(start, at) if toks[k][0] in REQUIRES), -1)
    if req < 0:
        req = next((k for k in range(at, len(toks)) if toks[k][0] in REQUIRES), -1)
    if req < 0:
        return ["web/public_html/%s names bootstrap.php but no require of it could be found" % rel]
    i = statement_end(toks, req) + 1
    while i < len(toks) and toks[i][0] == "T_USE":
        i = statement_end(toks, i) + 1
    problems = []
    for cls, question, (door, answers) in HANDOFFS:
        what = "%s::%s()" % (cls, question)
        if i >= len(toks) or toks[i][0] != "T_IF" or toks[i + 1][1] != "(":
            got = " ".join(t[1] for t in toks[i:i + 6]) if i < len(toks) else "(end of file)"
            problems.append("web/public_html/%s: the statement after start-up must be the hand-off asking %s, and it is "
                            "`%s …` — nothing may come between start-up and the two hand-offs (D-098)" % (rel, what, got))
            return problems
        close = match_close(toks, i + 1, "(", ")")
        cond = toks[i + 2:close]
        if close < 0 or calls(cond, alias, cls, (question,)) is False:
            problems.append("web/public_html/%s: the `if` after start-up does not call %s in its condition (a string or "
                            "comment naming it does not count)" % (rel, what))
            return problems
        if close + 1 >= len(toks) or toks[close + 1][1] != "{":
            problems.append("web/public_html/%s: the hand-off asking %s has no braced body" % (rel, what))
            return problems
        end = match_close(toks, close + 1, "{", "}")
        body = toks[close + 2:end]
        first = body[:statement_end(body, 0) + 1] if body else []
        if calls(first, alias, door, answers) is False:
            problems.append("web/public_html/%s: the hand-off asking %s does not BEGIN by calling %s::%s() — the answer "
                            "that exits" % (rel, what, door, "() or ".join(answers)))
            return problems
        i = end + 1
    return problems


def main() -> int:
    php = shutil.which("php")
    if php is None:
        print("✗ REFUSED: php is not on the PATH, and this check reads PHP only with PHP's own tokeniser. Nothing was checked.")
        return 1
    problems: list[str] = []
    found: set[str] = set()
    for path in sorted(WEBROOT.rglob("*.php")):
        rel = path.relative_to(WEBROOT).as_posix()
        try:
            toks = tokens(php, path)
        except Refused as e:
            problems.append(str(e))
            continue
        if any(t[0] == "T_CONSTANT_ENCAPSED_STRING" and "bootstrap.php" in t[1] for t in toks) is False:
            continue
        found.add(rel)
        problems.extend(check_file(rel, toks))
    for missing in sorted(KNOWN - found):
        problems.append("web/public_html/%s no longer starts the portal (or is gone): if that is deliberate, take it off "
                        "KNOWN in this check" % missing)
    if problems:
        for p in problems:
            print("✗ " + p)
        return 1
    print("check_webroot_door_handoff: OK — %d web-root file(s) start the portal (%s), and in each the two hand-offs "
          "(Site::isDoorRequest() → PublicDoor, Site::publicAddressUnknown() → PublicDoor::tryAgain()) are the first "
          "statements after start-up, with nothing in between. (It cannot see a dead branch or an ignored answer: "
          "tools/public-door-selftest.php drives them.)" % (len(found), ", ".join(sorted(found))))
    return 0


if __name__ == "__main__":
    sys.exit(main())
