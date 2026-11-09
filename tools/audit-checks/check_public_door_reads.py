#!/usr/bin/env python3
"""
The public door reads only what it should, and only through its readers (#493 P4a).

Path: tools/audit-checks/check_public_door_reads.py

WHY THIS EXISTS
---------------
`Portal\\Core\\PublicDoor` (web/_core/PublicDoor.php) decides what ANYBODY on
the internet can read about an organisation. Its safety rests on a few
structural promises that a later edit could break with every other test green:

  1. The organisation comes ONLY from `Site::doorSiteId()`. A call to
     `Site::id()` would read the organisation start-up fell back to -- which,
     for a refused request, is organisation 1. So `Site::id(` must not appear.
  2. It never reads the session: no `$_SESSION`, and no `Auth::` call except
     `Auth::peekSession()` -- the read-only look D-087's tester test needs.
  3. Every database read is inside one of the READERS (`events`, `posters`,
     `media`, `organisationFacts`). A query written anywhere else would be a
     read nothing reviews as a reader.
  4. The readers ask the ONE rule for what is public: `events()` asks
     `EventVisibility::where()` in website mode with
     `PublicWebsite::copiedRuleFor()`; `posters()` and `media()` ask
     `PublicWebsite::posterWhere()`.
  5. The renderers (`render*`) are given arrays, never the connection: no
     `\\mysqli` parameter, no `$db`, no `$mysqli`.
  6. `web/public_html/index.php` hands a door request over BEFORE
     `Auth::ensureSession()`, so a door answer never starts a session; and
     `Site::preDetect()` asks `detectDoor()` before `detectFromSession()`, and
     `detectDoor()` itself never touches the session.

HOW IT READS PHP: with PHP's own tokeniser (`token_get_all()`), so a comment
naming a forbidden call never counts and a brace inside a string never closes a
method. It REFUSES, with no fallback, when `php` cannot be run: this
repository has learned more than once that hand-parsing PHP with patterns is
defeated by the grammar (see `check_member_language_applied.py`'s history).

WHAT IT CANNOT SEE, said plainly
--------------------------------
- Whether the readers' SQL is RIGHT. That is tools/public-door-selftest.php,
  which drives the real door over HTTP with a planted fault for every gate.
- A forbidden call reached INDIRECTLY, through another class the door calls.
- Whether code it finds is reachable. It checks what the file CONTAINS.

USAGE
    python3 -I tools/audit-checks/check_public_door_reads.py

Package:   WebMS-Intra
Author:    MWBM Partners Ltd (t/a MWservices)
Copyright: All Rights Reserved
Version:   1.0.0
"""

from __future__ import annotations

import json
import shutil
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
DOOR = REPO / "web/_core/PublicDoor.php"
INDEX = REPO / "web/public_html/index.php"
SITE = REPO / "web/_core/Site.php"

READERS = {"events", "posters", "media", "organisationFacts"}
SQL_CALLS = ("->prepare(", "->query(", "->real_query(", "->multi_query(", "mysqli_query(", "->execute_query(")

TOKENISE = r"""
$out = [];
foreach (token_get_all(file_get_contents($argv[1])) as $t) {
    if (is_array($t)) {
        if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { $out[] = ' '; continue; }
        $out[] = [token_name($t[0]), $t[1]];
    } else {
        $out[] = $t;
    }
}
echo json_encode($out);
"""


class Refused(Exception):
    pass


def tokens(path: Path) -> list:
    php = shutil.which("php")
    if php is None:
        raise Refused("php is not on the PATH, and this check reads PHP only with PHP's own tokeniser")
    run = subprocess.run([php, "-r", TOKENISE, str(path)], capture_output=True, text=True)
    if run.returncode != 0:
        raise Refused("php could not tokenise %s: %s" % (path.name, run.stderr.strip()[:200]))
    lint = subprocess.run([php, "-l", str(path)], capture_output=True, text=True)
    if lint.returncode != 0:
        raise Refused("%s does not parse, so nothing it contains can be judged: %s" % (path.name, lint.stdout.strip()[:200]))
    return json.loads(run.stdout)


def text_of(tok) -> str:
    return tok[1] if isinstance(tok, list) else tok


def code(toks: list) -> str:
    """The file with comments blanked (strings kept, so a call's text is still visible)."""
    return "".join(text_of(t) for t in toks)


def functions(toks: list) -> dict[str, dict]:
    """Every NAMED function: its parameter text and its body text (comments blanked)."""
    found: dict[str, dict] = {}
    i = 0
    n = len(toks)
    while i < n:
        t = toks[i]
        if isinstance(t, list) and t[0] == "T_FUNCTION":
            j = i + 1
            while j < n and isinstance(toks[j], list) and toks[j][0] == "T_WHITESPACE":
                j += 1
            if j < n and isinstance(toks[j], list) and toks[j][0] == "T_STRING":
                name = toks[j][1]
                k = j + 1
                while k < n and text_of(toks[k]) != "(":
                    k += 1
                depth, start = 0, k
                while k < n:
                    s = text_of(toks[k])
                    if s == "(":
                        depth += 1
                    elif s == ")":
                        depth -= 1
                        if depth == 0:
                            break
                    k += 1
                params = "".join(text_of(x) for x in toks[start:k + 1])
                while k < n and text_of(toks[k]) not in ("{", ";"):
                    k += 1
                if k < n and text_of(toks[k]) == "{":
                    depth, body_start = 0, k
                    while k < n:
                        s = text_of(toks[k])
                        if s == "{" or (isinstance(toks[k], list) and toks[k][0] in ("T_CURLY_OPEN", "T_DOLLAR_OPEN_CURLY_BRACES")):
                            depth += 1
                        elif s == "}":
                            depth -= 1
                            if depth == 0:
                                break
                        k += 1
                    found[name] = {"params": params, "body": "".join(text_of(x) for x in toks[body_start:k + 1]),
                                   "start": body_start, "end": k}
                i = k
        i += 1
    return found


def main() -> int:
    problems: list[str] = []
    try:
        door_toks = tokens(DOOR)
        index_toks = tokens(INDEX)
        site_toks = tokens(SITE)
    except Refused as why:
        print("✗ REFUSED: %s. Nothing was checked." % why)
        return 1

    door = code(door_toks)
    if "Site::id(" in door:
        problems.append("PublicDoor.php calls Site::id(): the door must read ONLY Site::doorSiteId(), or a refused request falls back to organisation 1")
    if "$_SESSION" in door:
        problems.append("PublicDoor.php reads $_SESSION: the door never reads the session (D-087's peek goes through Auth::peekSession())")
    for at in range(len(door)):
        if door.startswith("Auth::", at) and door.startswith("Auth::peekSession(", at) is False:
            problems.append("PublicDoor.php calls %s: only Auth::peekSession() is allowed" % door[at:at + 30].split("(")[0])
            break

    fns = functions(door_toks)
    for name in ("answer", "notFound") + tuple(sorted(READERS)):
        if name not in fns:
            problems.append("PublicDoor.php has no %s() — a reader or the gate has been renamed or removed" % name)
    for name, fn in fns.items():
        if name in READERS:
            continue
        for call in SQL_CALLS:
            if call in fn["body"]:
                problems.append("PublicDoor::%s() runs a database statement (%s): only the readers %s may" % (name, call, ", ".join(sorted(READERS))))
    # SQL outside any named function (a closure at the top level, say).
    covered = [False] * len(door_toks)
    for fn in fns.values():
        for x in range(fn["start"], fn["end"] + 1):
            covered[x] = True
    loose = "".join(text_of(t) for x, t in enumerate(door_toks) if covered[x] is False)
    for call in SQL_CALLS:
        if call in loose:
            problems.append("PublicDoor.php runs a database statement (%s) outside any method" % call)

    rules = {
        # The WHOLE call, not its parts: "MODE_WEBSITE appears somewhere" was
        # satisfied by the fullDetailSelect() call while where() had been
        # switched to another mode (planted when this check was written).
        "events": ["EventVisibility::where('e', EventVisibility::MODE_WEBSITE, 0, $today, PublicWebsite::copiedRuleFor($db, $siteId))",
                   "EventVisibility::fullDetailSelect('e', EventVisibility::MODE_WEBSITE, 0, $today,",
                   "EventVisibility::redact($row, (int) $row['canSeeFull'] === 1)"],
        "posters": ["PublicWebsite::posterWhere("],
        "media": ["PublicWebsite::posterWhere("],
    }
    for name, needed in rules.items():
        body = fns.get(name, {}).get("body", "")
        for one in needed:
            if one not in body:
                problems.append("PublicDoor::%s() does not ask %s — the ONE rule for what is public" % (name, one.rstrip("(")))

    renderers = [n for n in fns if n.startswith("render")]
    if len(renderers) < 2:
        problems.append("PublicDoor.php has fewer than two render*() methods; the renderers have been renamed or removed")
    for name in renderers:
        fn = fns[name]
        if "mysqli" in fn["params"] or "$db" in fn["body"] or "$mysqli" in fn["body"] or "mysqli" in fn["body"]:
            problems.append("PublicDoor::%s() is given or reaches the database connection: a renderer is given arrays only" % name)

    index = code(index_toks)
    door_at = index.find("Site::isDoorRequest()")
    session_at = index.find("Auth::ensureSession()")
    answer_at = index.find("PublicDoor::answer(")
    if door_at < 0 or answer_at < 0:
        problems.append("index.php no longer hands a door request to PublicDoor::answer()")
    elif session_at >= 0 and (door_at > session_at or answer_at > session_at):
        problems.append("index.php hands the door its request AFTER Auth::ensureSession(): every door answer would start a session")

    site_fns = functions(site_toks)
    pre = site_fns.get("preDetect", {}).get("body", "")
    door_call = pre.find("self::detectDoor(")
    session_call = pre.find("self::detectFromSession(")
    if door_call < 0:
        problems.append("Site::preDetect() no longer asks self::detectDoor()")
    elif session_call >= 0 and door_call > session_call:
        problems.append("Site::preDetect() asks the session before the door: a door request must be decided before any session is looked at")
    detect = site_fns.get("detectDoor", {}).get("body", "")
    if detect == "":
        problems.append("Site::detectDoor() is missing")
    elif "Auth::" in detect or "$_SESSION" in detect or "detectFromSession" in detect:
        problems.append("Site::detectDoor() touches the session: the door's organisation comes from the address alone")

    if problems:
        for p in problems:
            print("✗ " + p)
        return 1
    print("check_public_door_reads: OK — PublicDoor reads no session and never Site::id(); its %d database statement(s) "
          "are all inside its readers; the readers ask EventVisibility (website mode) and PublicWebsite; its renderers "
          "get no connection; and index.php and Site::preDetect() decide a door request before any session."
          % sum(fns[n]["body"].count("->prepare(") for n in READERS if n in fns))
    return 0


if __name__ == "__main__":
    sys.exit(main())
