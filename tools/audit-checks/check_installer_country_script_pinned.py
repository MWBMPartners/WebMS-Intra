#!/usr/bin/env python3
"""
The installer's country suggestion script's TEXT is pinned (#942, D-133).

Path: tools/audit-checks/check_installer_country_script_pinned.py

WHAT ENFORCES "SENDS NOTHING", AND WHAT THIS ADDS
--------------------------------------------------
D-133: the installer suggests a country and a time zone from the installing
person's own browser time zone, and "nothing is looked up or sent anywhere".

THE BROWSER ENFORCES THAT, not this check. Every installer answer carries a
strict Content Security Policy (web/_install/csp.php): only a script carrying
the answer's nonce runs, and nothing may be fetched, beaconed, streamed, framed
or posted elsewhere. Four check rounds each got the zone out past a guard that
READ the page's source (a WebSocket; a timer; an `innerHTML` picture; a
`pagehide` beacon; then a `<SCRIPT>` in capitals, the `<!-- <script` double
escape, an `onload=` attribute, an `<iframe srcdoc>`, a `javascript:` address
and a tag built in PHP from two halves). Reading source cannot list every way
HTML runs code; a browser obeying the policy does not need to.
`tools/installer-country-script-selftest.mjs` proves the policy blocks each of
those in a real browser.

WHAT THIS CHECK GUARDS is the one thing the policy does NOT block: a
DNS-prefetch or preconnect HINT built from the time zone (browsers put hints
under no directive). Such a hint, or any other change, would have to be written
into the suggestion script itself — the `<script>` whose first line is
`// 🌍 Suggest a country`, which runs because it carries the nonce. So its CODE
must equal `installer-country-script.pinned.js` beside this file, exactly; any
change to a statement fails until somebody re-pins it with `--repin`, a
separate, visible act that puts the change in front of a reviewer.

It also refuses `<!--` and `<script` (in any case) inside the pinned body,
because in HTML they change where the browser thinks the script ENDS (the
"double escape"), so the code that runs would no longer be the code pinned.

HOW THE CODE IS COMPARED
------------------------
With `normalised_code()`, `js_source()` and `js_will_not_parse()` from
`check_translation_text_from_db.py` — the #485 pin's own rules, LOADED from
that file, never copied. Comment text, indentation and blank lines are free; a
LINE BREAK is not (in JavaScript a newline is a semicolon); U+2028 and U+2029
are folded to line breaks; a body JavaScript would refuse outright is refused.
It still cannot see whitespace inside a string literal.

THE OTHER THREE SCRIPTS (the theme switches and the password strength meter)
are NOT pinned, and that is said plainly. Under the policy each runs only
because it carries the nonce, so a new or changed script runs only if it is
written into our own code WITH the nonce — a change a reviewer reads. The
count of `<script` tags (any case) is kept here as a cheap tripwire for that:
SCRIPT_COUNT, failing both ways. It cannot see a tag built in PHP from pieces,
which the policy refuses to run anyway (it carries no nonce).

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
# The opening tag's attributes are read quote by quote: the nonce attribute is
# printed by PHP (`<?php echo … ?>`), whose `?>` holds a `>` inside the quotes.
SCRIPT_RE = re.compile(r'<script\b(?:[^>"]|"[^"]*")*>\s*(// 🌍 Suggest a country.*?)</script>', re.DOTALL | re.IGNORECASE)


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
    # `<!--` and `<script` inside a script change where an HTML parser ends it
    # (the "double escape"): code after the first `</script>` would then run as
    # part of THIS script, carrying its nonce, while this check stopped reading.
    if "<!--" in found[0] or re.search(r"<script", found[0], re.IGNORECASE) is not None:
        return None, ("the suggestion script holds `<!--` or `<script`, which change where a browser ends it "
                      "(the double escape), so the code that runs would not be the code pinned")
    return found[0], ""


def main() -> int:
    rules = _pin_rules()
    with open(os.path.join(ROOT, INSTALLER), encoding="utf-8") as handle:
        page = handle.read()
    problems = []
    scripts = len(re.findall(r"<script\b", page, re.IGNORECASE))
    if scripts != SCRIPT_COUNT:
        problems.append(
            f"{INSTALLER} has {scripts} `<script` tag(s) (any case); {SCRIPT_COUNT} are counted. Under the "
            f"installer's policy a new script runs only if it carries the nonce, so read it: does it read the "
            f"time zone, or write a hint? Then set SCRIPT_COUNT in this file."
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
                    f"      THIS FAILS ON ANY CHANGE TO A STATEMENT, ON PURPOSE: the installer's policy blocks every "
                    f"send but a DNS or preconnect hint, and a hint built from the time zone would have to be "
                    f"written here. Read the change; if it is right, run this check with --repin and say in the "
                    f"commit message what changed."
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
