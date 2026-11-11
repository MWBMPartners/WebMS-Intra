#!/usr/bin/env python3
"""
The installer's country suggestion script is pinned WHOLE, as text (#942, D-133).

Path: tools/audit-checks/check_installer_country_script_pinned.py

WHAT IT GUARDS
--------------
D-133: the installer suggests a country and a time zone from the installing
person's own browser time zone, and "nothing is looked up or sent anywhere".
That promise rests on one small script on the installer's Web addresses page
(the `<script>` whose first line is `// 🌍 Suggest a country`). This check
requires that script's CODE to equal `installer-country-script.pinned.js`
beside it, exactly. ANY change to a statement fails, on purpose, until somebody
re-pins it with `--repin` — a separate, visible act that puts the change in
front of a reviewer, who reads it.

WHY A PIN AND NOT A TEST OF WHAT IT SENDS — the reason, settled after three
check rounds each got the zone out past the previous guard:
  * round 1: a search of the script's TEXT missed `new WebSocket('wss://…')`;
  * round 2: a list of traps in node missed a send behind a timer, an image
    built through `innerHTML`, CSS `url()` and `import()`;
  * round 3: a real browser recording every request missed a `pagehide`
    `sendBeacon`, a `<link rel=preconnect>` and `dns-prefetch` (DNS and TLS
    server names never reach the browser's Network domain), a timer past its
    window, and a send on the ZONE box's change.
Proving that something is ABSENT by sampling the ways it could happen is
unbounded: every list is one short. This script is OURS and SMALL, so the
honest guard is that nobody can change it without a person reading the change.
`tools/installer-country-script-selftest.mjs` stays as supporting evidence of
what the script does in a browser; it is not the guard.

HOW THE CODE IS COMPARED
------------------------
With `normalised_code()`, `js_source()` and `js_will_not_parse()` from
`check_translation_text_from_db.py` — the #485 pin's own rules, LOADED from
that file, never copied (two copies of a normaliser are two chances for the
generator and the checker to disagree). In short:
  * comment TEXT, indentation and blank lines are free;
  * a LINE BREAK is NOT free: in JavaScript a newline is a semicolon;
  * U+2028 and U+2029 end a `//` comment in JavaScript, so they are folded to
    line breaks first; a file JavaScript would refuse outright (an unclosed
    `/*`, a control character) is refused here too, because a pin of code that
    does not run certifies nothing.
WHAT IT STILL CANNOT SEE, from the #485 notes: whitespace inside a string
literal (this script has no string whose whitespace matters), and code after a
string CONTAINING `//` or `/*` on the same line (none today; adding one would
itself change the pinned code).

ALSO PINNED: the NUMBER of `<script` tags in the installer. A send added in a
NEW script elsewhere on the page would leave this one untouched, so the count
must equal SCRIPT_COUNT below, failing both ways. The other three scripts'
CONTENTS are not pinned (the theme switches and the password strength meter,
none of which reads the time zone today); a change to their number is, but a
change INSIDE one of them is not, and that is said rather than hidden.

NOT COVERED: HTML on the page outside any script (a `<link rel=preconnect>`
written as markup). It cannot carry the zone, which only the browser knows,
but it can reveal that somebody opened the installer; nothing here pins that.

USAGE
  python3 tools/audit-checks/check_installer_country_script_pinned.py
  python3 tools/audit-checks/check_installer_country_script_pinned.py --repin

Package: WebMS-Intra / audit checks
Copyright: All Rights Reserved -- MWBM Partners Ltd
"""

from __future__ import annotations

import importlib.util
import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
INSTALLER = os.path.join("web", "_install", "index.php")
PINNED = os.path.join("tools", "audit-checks", "installer-country-script.pinned.js")
# The installer's <script> tags: the early theme choice in <head>, the theme and
# colour-blind switches' wiring, the password strength meter, and this one
# (#942). Change it together with a reading of whatever new script was added.
SCRIPT_COUNT = 4
SCRIPT_RE = re.compile(r"<script>\s*(// 🌍 Suggest a country.*?)</script>", re.DOTALL)


def _pin_rules():
    """The #485 pin's normaliser, loaded by path (see the header)."""
    path = os.path.join(ROOT, "tools", "audit-checks", "check_translation_text_from_db.py")
    spec = importlib.util.spec_from_file_location("translation_pin_rules", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def the_script(text: str) -> tuple[str | None, str]:
    found = SCRIPT_RE.findall(text)
    if len(found) != 1:
        return None, f"expected exactly ONE `<script>` beginning `// 🌍 Suggest a country` in {INSTALLER}, found {len(found)}"
    if "<?" in found[0]:
        return None, "the suggestion script now holds PHP, so what the browser runs is no longer what this file says"
    return found[0], ""


def main() -> int:
    rules = _pin_rules()
    with open(os.path.join(ROOT, INSTALLER), encoding="utf-8") as handle:
        page = handle.read()
    problems = []
    scripts = len(re.findall(r"<script\b", page))
    if scripts != SCRIPT_COUNT:
        problems.append(
            f"{INSTALLER} has {scripts} `<script` tag(s); {SCRIPT_COUNT} are pinned. A new script on the "
            f"installer's pages can read the browser's time zone and send it, which this pin of the one "
            f"suggestion script cannot see. Read the change, then set SCRIPT_COUNT in this file."
        )
    script, why = the_script(page)
    if script is None:
        problems.append(why)
    else:
        script = rules.js_source(script)
        for reason in rules.js_will_not_parse(script):
            problems.append(f"the suggestion script {reason}")
        pinned_path = os.path.join(ROOT, PINNED)
        if os.path.isfile(pinned_path) is False:
            problems.append(f"{PINNED} is not there. Run this check with --repin after reading the script.")
        else:
            with open(pinned_path, encoding="utf-8") as handle:
                pinned = handle.read()
            live = rules.normalised_code(script)
            want = "\n".join(line for line in pinned.splitlines() if line.strip() != "")
            if live != want:
                where = next((i for i in range(min(len(live), len(want))) if live[i] != want[i]), min(len(live), len(want)))
                problems.append(
                    f"the installer's country suggestion script is not what {PINNED} pins.\n"
                    f"      First difference at character {where} of the normalised code:\n"
                    f"        pinned: …{want[max(0, where - 60):where + 60]}…\n"
                    f"        found:  …{live[max(0, where - 60):where + 60]}…\n"
                    f"      THIS FAILS ON ANY CHANGE TO A STATEMENT, ON PURPOSE: D-133 promises this script sends "
                    f"nothing anywhere, and three check rounds showed no test can prove that. Read the change; "
                    f"if it is right, run this check with --repin and say in the commit message what changed."
                )
    if problems:
        for problem in problems:
            print(f"FAIL: {problem}")
        return 1
    print(f"OK: the installer's country suggestion script is as pinned, and the installer has {SCRIPT_COUNT} scripts.")
    return 0


def repin() -> int:
    rules = _pin_rules()
    with open(os.path.join(ROOT, INSTALLER), encoding="utf-8") as handle:
        page = handle.read()
    script, why = the_script(page)
    if script is None:
        print(f"REFUSED: {why}")
        return 1
    script = rules.js_source(script)
    refusals = rules.js_will_not_parse(script)
    if refusals:
        print("REFUSED — not pinning a script JavaScript would not run:\n  " + "\n  ".join(refusals))
        return 1
    normalised = rules.normalised_code(script)
    with open(os.path.join(ROOT, PINNED), "w", encoding="utf-8") as handle:
        handle.write(normalised + "\n")
    print(f"Re-pinned {PINNED}: {len(normalised)} characters of code. Say in the commit message what changed and why.")
    return 0


if __name__ == "__main__":
    sys.exit(repin() if "--repin" in sys.argv else main())
