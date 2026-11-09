#!/usr/bin/env python3
"""
Every web-root file that starts the portal hands a public-door request over first (#493 P4a).

Path: tools/audit-checks/check_webroot_door_handoff.py

WHY THIS EXISTS
---------------
D-098: an organisation's public address answers the public door's requests and
NOTHING else. The door is recognised by `Site::preDetect()` while the portal
starts up, but it is only ANSWERED by whichever file the web server ran. Three
files under web/public_html/ start the portal themselves -- index.php, error.php
(Apache's ErrorDocument target for every refused .php, dotfile and
403/404/500/503) and api-docs/index.php -- and round 1 of P4a's check found the
last two drawing a full portal page, with a session cookie, on a public address.
Round 2 found the API description drawing organisation 1's page when the
public-address look-up itself had FAILED. Both were fixed by asking, straight
after start-up and before any session starts:

    Site::isDoorRequest()        -> PublicDoor::notFound()
    Site::publicAddressUnknown() -> PublicDoor::tryAgain()

A NEW file in the web root that loads start-up is the realistic way this comes
back, and nothing would notice: it would work perfectly on the members' address.
So this check finds every PHP file under web/public_html/ whose code names
`bootstrap.php`, and requires both questions in its code (comments do not count)
BEFORE its first `ensureSession(` -- or, for a file that starts no session, at
all.

HOW IT READS PHP: with PHP's own tokeniser, so a comment naming either question
never satisfies it. It REFUSES, with no fallback, when `php` cannot be run.

WHAT IT CANNOT SEE, said plainly
--------------------------------
- Whether the question's ANSWER is acted on (a call whose result is ignored, or
  an `if` that does nothing, passes). tools/public-door-selftest.php drives all
  three files over HTTP on a public address and in the failure state, with a
  planted fault for each hand-off.
- A file that loads start-up without the text `bootstrap.php` in its own code
  (through another file it includes).

USAGE
    python3 -I tools/audit-checks/check_webroot_door_handoff.py

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
WEBROOT = REPO / "web/public_html"
QUESTIONS = ("Site::isDoorRequest()", "Site::publicAddressUnknown()")
# The three known entry points. A file on this list that stops loading
# start-up, or disappears, is reported too: the list must stay true.
KNOWN = {"index.php", "error.php", "api-docs/index.php"}

TOKENISE = r"""
$out = '';
foreach (token_get_all(file_get_contents($argv[1])) as $t) {
    if (is_array($t)) {
        if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT || $t[0] === T_INLINE_HTML) { $out .= ' '; continue; }
        $out .= $t[1];
    } else {
        $out .= $t;
    }
}
echo json_encode($out);
"""


def code_of(php: str, path: Path) -> str:
    run = subprocess.run([php, "-r", TOKENISE, str(path)], capture_output=True, text=True)
    if run.returncode != 0:
        raise RuntimeError("php could not tokenise %s: %s" % (path, run.stderr.strip()[:200]))
    return json.loads(run.stdout)


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
            code = code_of(php, path)
        except RuntimeError as e:
            problems.append(str(e))
            continue
        if "bootstrap.php" not in code:
            continue
        found.add(rel)
        session_at = code.find("ensureSession(")
        for question in QUESTIONS:
            at = code.find(question)
            if at < 0:
                problems.append("web/public_html/%s starts the portal but never asks %s, so on an organisation's public "
                                "address it would answer something other than the door (D-098)" % (rel, question))
            elif session_at >= 0 and at > session_at:
                problems.append("web/public_html/%s asks %s only AFTER its session starts: the answer would carry a "
                                "session cookie and leave a session file" % (rel, question))
    for missing in sorted(KNOWN - found):
        problems.append("web/public_html/%s no longer starts the portal (or is gone): if that is deliberate, take it off "
                        "KNOWN in this check" % missing)
    if problems:
        for p in problems:
            print("✗ " + p)
        return 1
    print("check_webroot_door_handoff: OK — %d web-root file(s) start the portal (%s), and each asks "
          "Site::isDoorRequest() and Site::publicAddressUnknown() before any session starts."
          % (len(found), ", ".join(sorted(found))))
    return 0


if __name__ == "__main__":
    sys.exit(main())
