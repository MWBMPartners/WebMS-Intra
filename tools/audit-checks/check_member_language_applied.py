#!/usr/bin/env python3
"""
The language question is asked AFTER the session starts, not before.

Path: tools/audit-checks/check_member_language_applied.py

WHY THIS EXISTS (#763)
----------------------
A member could choose their interface language, the choice was stored, and
**nothing applied it.** Every page came out in whatever the browser's
`Accept-Language` header asked for, or the installation's default.

Nothing was broken. The question was asked too early. `I18n::init()` is called
from `bootstrap.php`; the session does not start until `index.php` calls
`Auth::ensureSession()`, which is AFTER start-up has finished. Two of
`resolveLocale()`'s four sources are behind `session_status() ===
PHP_SESSION_ACTIVE`, so both were skipped on every single request, and the
block in bootstrap that read `tblUsers.locale` was behind the same test and
could not run either.

**The switcher had the same fault and it was worse.** `I18n::switchLocale()`
stores the chosen language in the session and writes it to `tblUsers.locale`,
both only when a session is active -- and its one and only caller sat in
`bootstrap.php`, before the session started. So `?lang=cy` applied to the one
request that carried it and was thrown away by its own redirect, and
`tblUsers.locale` was never written by anything, on any installation. That is
why every account row still held the column's own default (D-060).

WHAT IT CHECKS
--------------
  A. `index.php` calls `I18n::reconsiderNowSessionExists(`, and is passed the
     request's connection.
  B. `index.php` calls `I18n::switchLocale(`.
  C. Both come AFTER `Auth::ensureSession()` in `index.php`. **This is the
     whole point** -- a call in the right file in the wrong place is the fault
     that was just fixed, and it looks completely correct.
  D. `bootstrap.php` calls NEITHER. Leaving a call where the fault was would
     re-create it: the early one resolves to the browser's answer, and for the
     switcher it would silently drop the choice again.
  E. The `?lang=` gate refuses a value that is not text. Without it,
     `?lang[]=x` is a TypeError and a 500 on every address, open or closed,
     because this runs ahead of the maintenance gate (#509 point 2).
  F. The account page offers a `<select name="locale">` that is neither
     disabled nor hidden, asks `I18n::storedChoice()` and `I18n::browserLanguage()`,
     builds its list from `I18n::enabledLocales()`, and offers a stored language
     even when there is no translation file for it. A disabled control is not
     submitted, so the handler would see no field and store nothing while a
     presence check read it as there (the GoCardless lesson, #569).
  G. The handler stores through `I18n::chooseLanguage()` -- the one writer --
     and asks whether the `locale` field ARRIVED rather than what it contains.
     An empty value is a real instruction here ("forget my choice"), so
     `$_POST['locale'] ?? ''` cannot be told from a form that never carried the
     control, and any post from elsewhere would wipe the member's language.
     #677 cost this project a cleared portal address to that exact shape.

WHAT IT DELIBERATELY DOES NOT CHECK, AND THE EIGHT ROUNDS THAT SETTLED IT
-------------------------------------------------------------------------
**It does not try to prove the code is REACHED.** It used to. An earlier
version pinned the three regions' text and their nesting, read with PHP's own
tokeniser, so that nobody could wrap them in something that never runs. That
machinery reached roughly 1,100 lines guarding about sixty, and it was removed
on the owner's decision (**D-065**) after **eight independent review rounds and
52 findings -- every one after the first round in the GUARD, and not one in the
fix**, which every round confirmed was correct.

The rounds went 12, 7, 6, 6, 3, 3, 6, 9. Six of those rounds found a new way to
wrap a region in dead code, each answered with a sharper pattern:
`if (false) { }`, then the brace-less `if (false)`, then `if (false): endif;`,
then `if (false) { ;`, then `$endif = 1;` cancelling a hand-rolled counter, then
`if (true) { exit; }`. The eighth round found the guard still **refusing correct
work**: a passing comment that merely mentioned the guarded call made it report
the pin "worthless", and an ordinary helper added above the region made it
report that the region had "moved". **A check that cries wolf gets switched off
(#483)**, so by then the rounds were not even buying protection.

**The instrument was the mistake, not the sharpening.** "Is this code reached"
is behavioural, and the things a source-reading check can never see --
`while (true) { }` above the region, `trigger_error` at `E_USER_ERROR`, a
conditional `require` of the whole file -- are not edge cases but the same
question. So that job moved to where it can be measured:
`tools/i18n-member-language-http-selftest.php` signs in, loads the account
page, chooses a language, saves, and reads the column back. **Every wrapping the
pin caught also fails that test, and so do the four classes it could not see.**

**The transferable rule, which is why this is written out at length: where the
property is "this code RUNS", drive it; where it is "this code LOOKS like
this", pin it.** (#485's pinned script is the closest relative and stays,
because its own justification is the opposite situation -- nothing executes that
file, so there is nothing to drive.)

What remains here is presence and ORDER, which in eight rounds produced no
finding at all and cannot cry wolf.

Other limits, stated rather than implied:
  * It cannot see a call made through a variable class name, an alias, or a
    helper in another file.
  * It reads a comment-stripped, string-masked view produced by PHP itself
    (`member-language-structure.php`, `file` and `file_keys` modes), so a
    comment or a string mentioning a call cannot answer for the call -- but a
    string containing `<select name="locale">` would satisfy check F, which is
    why F is a courtesy and the HTTP test is the guarantee.

Package: WebMS-Intra / audit checks
Author: Claude Opus 5 (for MWBM Partners Ltd, t/a MWservices)
Copyright: All Rights Reserved -- MWBM Partners Ltd
Version: 3.0.0
"""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys

INDEX = os.path.join("web", "public_html", "index.php")
BOOTSTRAP = os.path.join("web", "_core", "bootstrap.php")
ACCOUNT = os.path.join("web", "_apps", "auth", "account", "index.php")
ACCOUNT_SAVE = os.path.join("web", "_apps", "auth", "account", "save.php")

# 🧰 PHP reads the PHP. Six rounds were lost to a hand-written reader being
#    wrong about `$endif`, a `}` inside a heredoc, a `#[Attribute]`, a `'?>'`
#    inside a string, and PHP written inside an HTML comment -- which PHP
#    executes. None of those distinctions is in the text; they are in the
#    grammar. This file asks for two whole-file views and nothing more.
STRUCTURE_PROBE = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), "member-language-structure.php"
)

# A generous limit: the probe reads four files and takes well under a second.
# It exists to stop a hang, not to race a slow machine.
PROBE_TIMEOUT_SECONDS = 30


def repo_root() -> str:
    here = os.path.dirname(os.path.abspath(__file__))
    return os.path.dirname(os.path.dirname(here))


def ask_php(root: str, wants) -> list:
    """
    Whole-file views, from PHP's own tokeniser: comments dropped, strings
    masked (`file`) or kept (`file_keys`).

    ⚠️ IT MUST FAIL LOUDLY, never quietly. PHP missing, PHP crashing, a timeout
       or output that will not parse all return None here and are reported as a
       finding by the caller -- the `check_static_calls.py` precedent. **There
       is deliberately no fallback to reading the PHP in Python**: six rounds
       proved there is no second-best reading to drop to.
    """
    php = shutil.which("php")
    if php is None:
        return None
    if os.path.isfile(STRUCTURE_PROBE) is False:
        return None
    request = json.dumps([
        {"file": os.path.join(root, relative), "anchor": "", "mode": mode}
        for relative, mode in wants
    ])
    try:
        done = subprocess.run(
            [php, STRUCTURE_PROBE, request],
            stdin=subprocess.DEVNULL,
            capture_output=True,
            text=True,
            timeout=PROBE_TIMEOUT_SECONDS,
            cwd=root,
        )
    except (subprocess.TimeoutExpired, OSError):
        return None
    if done.returncode != 0:
        return None
    try:
        answers = json.loads(done.stdout.strip() or "null")
    except ValueError:
        return None
    if isinstance(answers, list) is False or len(answers) != len(wants):
        return None
    return answers


def main() -> int:
    root = repo_root()
    problems: list[str] = []

    for relative in (INDEX, BOOTSTRAP, ACCOUNT, ACCOUNT_SAVE):
        if os.path.isfile(os.path.join(root, relative)) is False:
            print(f"FAIL: {relative} is not there. This check cannot run.")
            return 1

    views = ask_php(root, (
        (INDEX, "file"),
        (INDEX, "file_keys"),
        (BOOTSTRAP, "file"),
        (ACCOUNT, "file_keys"),
        (ACCOUNT_SAVE, "file"),
        (ACCOUNT_SAVE, "file_keys"),
    ))
    if views is None:
        print(
            "FAIL: the file-reading probe could not run, so not one of this "
            "check's questions can be answered. It needs PHP on PATH and "
            f"{os.path.basename(STRUCTURE_PROBE)} beside this script, and it "
            f"must finish within {PROBE_TIMEOUT_SECONDS}s and print JSON.\n"
            "       There is deliberately NO fallback to reading the PHP in "
            "Python: six check rounds each got a one-edit change past a "
            "hand-written reader, so there is no second-best reading to drop to."
        )
        return 1
    index_code, index_keys, boot_code, account_keys, save_code, save_keys = (
        str(v.get("text", "")) for v in views
    )

    reconsider = re.search(r"I18n::reconsiderNowSessionExists\s*\(([^)]*)\)", index_code)
    switcher = re.search(r"I18n::switchLocale\s*\(", index_code)
    ensure = re.search(r"Auth::ensureSession\s*\(", index_code)

    # ── A to D: the calls, and their order ────────────────────────────────
    if ensure is None:
        problems.append(
            f"{INDEX} no longer calls Auth::ensureSession(), so there is no "
            f"moment to measure 'after the session starts' against. If the "
            f"session now starts somewhere else, teach this check where."
        )
    if reconsider is None:
        problems.append(
            f"{INDEX} does not call I18n::reconsiderNowSessionExists(). Without "
            f"it the member's own saved language and the language switcher's "
            f"choice are BOTH unreachable, because I18n::init() runs during "
            f"start-up where no session exists -- and it latches, so it will "
            f"not look again. That is #763, and it was live for eight months."
        )
    elif reconsider.group(1).strip() in ("", "null"):
        problems.append(
            f"{INDEX}: reconsiderNowSessionExists() is passed no connection "
            f"(`{reconsider.group(1).strip() or 'nothing'}`). Its parameter "
            f"defaults to null, so this is legal PHP and reads as a tidy-up -- "
            f"and with no connection the member's tblUsers.locale is never "
            f"read, nothing sets $_SESSION['user_locale'], and resolveLocale() "
            f"falls through to Accept-Language. That is the member-locale half "
            f"of #763, whole, and no self-test can see it because they all pass "
            f"a connection. Pass the request's connection."
        )
    if switcher is None:
        problems.append(
            f"{INDEX} does not call I18n::switchLocale(). It is the only thing "
            f"that has ever written tblUsers.locale, so without it a language "
            f"cannot be chosen at all."
        )

    if ensure is not None:
        for name, found in (("I18n::reconsiderNowSessionExists", reconsider),
                            ("I18n::switchLocale", switcher)):
            if found is None:
                continue
            if found.start() < ensure.start():
                line = index_code[:found.start()].count("\n") + 1
                problems.append(
                    f"{INDEX} line {line}: {name}() is called BEFORE "
                    f"Auth::ensureSession(), so there is still no session when "
                    f"it runs -- which is exactly the fault #763 fixed, and it "
                    f"looks completely correct. Move it below the "
                    f"ensureSession() call."
                )

    for name in ("I18n::switchLocale", "I18n::reconsiderNowSessionExists"):
        hit = re.search(re.escape(name) + r"\s*\(", boot_code)
        if hit is not None:
            line = boot_code[:hit.start()].count("\n") + 1
            problems.append(
                f"{BOOTSTRAP} line {line} calls {name}(). Nothing in start-up "
                f"may: no session is active there, so the call silently does "
                f"nothing useful. For switchLocale() it is worse than nothing "
                f"-- the member's choice is dropped and tblUsers.locale is "
                f"never written, which is why every row held the column's own "
                f"default (D-060)."
            )

    # ── E: the ?lang= gate must refuse a non-string ───────────────────────
    if re.search(r"is_string\s*\(\s*\$_GET\s*\[\s*'lang'\s*\]\s*\)", index_keys) is None:
        problems.append(
            f"{INDEX}: the ?lang= gate no longer tests is_string($_GET['lang']). "
            f"A request sent as a list -- ?lang[]=x -- then reaches "
            f"switchLocale(), whose typed parameter refuses it with a TypeError: "
            f"a 500 and one error row, on EVERY address, open or closed, because "
            f"this runs ahead of the maintenance gate (#509 point 2)."
        )

    # ── F: the account page's control ─────────────────────────────────────
    field = re.search(r"<select[^>]*name=.locale.[^>]*>", account_keys)
    if field is None:
        problems.append(
            f"{ACCOUNT} no longer offers a language control (no <select> named "
            f"'locale'). D-062 put it there because the top-bar menu CHANGES a "
            f"language but is not a place a member can see what the portal has "
            f"stored about them, and offers no way back to following their "
            f"browser."
        )
    elif re.search(r"\bdisabled\b|\btype=.hidden.", field.group(0)) is not None:
        problems.append(
            f"{ACCOUNT}: the language control is disabled or hidden. A disabled "
            f"control is NOT submitted, so the handler sees no field and stores "
            f"nothing, while a presence check reads it as there -- the "
            f"GoCardless lesson (#569)."
        )
    if "I18n::storedChoice" not in account_keys:
        problems.append(
            f"{ACCOUNT} does not ask I18n::storedChoice(). Reading the column "
            f"here instead is a second reader of one fact, and two readers of a "
            f"language can disagree -- which is how this page came to tell every "
            f"member their language was 'en'."
        )
    if "I18n::browserLanguage" not in account_keys:
        problems.append(
            f"{ACCOUNT}: the 'Follow my browser' label does not ask "
            f"I18n::browserLanguage(). Built from I18n::locale() it names the "
            f"language the page is being DRAWN in, which for anybody who has a "
            f"stored choice IS that choice -- so the label was wrong for every "
            f"person who would ever read it, the option existing only to undo a "
            f"choice. Measured: stored cy, Accept-Language fr, label said "
            f"Cymraeg, answer would be Français."
        )
    if "I18n::enabledLocales" not in account_keys:
        problems.append(
            f"{ACCOUNT} does not build its list from I18n::enabledLocales(), "
            f"which is the languages this installation has a translation file "
            f"for."
        )
    if "isset($langChoices[$langStored]) === false" not in re.sub(r"\s+", " ", account_keys):
        problems.append(
            f"{ACCOUNT} no longer offers the member's STORED language when it is "
            f"absent from enabledLocales(). That list is readable "
            f"web/_lang/*.php files -- two on a stock installation -- while "
            f"isSupported() accepts any of the thirteen the code knows, so a "
            f"stored 'ar' matches no option, nothing carries `selected`, every "
            f"browser pre-selects value=\"\", and pressing Save to correct a "
            f"TELEPHONE NUMBER deletes the member's language. The page also "
            f"states something untrue about what the portal holds."
        )

    # ── G: the handler ────────────────────────────────────────────────────
    if "I18n::chooseLanguage" not in save_code:
        problems.append(
            f"{ACCOUNT_SAVE} does not call I18n::chooseLanguage(), so either the "
            f"language is not saved at all or something else is writing "
            f"tblUsers.locale. There is ONE writer; for eight months there was "
            f"also exactly one, in the wrong place, and that is the whole of "
            f"#763."
        )
    if re.search(r"array_key_exists\s*\(\s*'locale'\s*,\s*\$_POST\s*\)", save_keys) is None:
        problems.append(
            f"{ACCOUNT_SAVE} does not ask whether the 'locale' field ARRIVED "
            f"(array_key_exists('locale', $_POST)). An empty value is a real "
            f"instruction here -- forget my choice and follow my browser -- so "
            f"reading it as $_POST['locale'] ?? '' cannot be told from a form "
            f"that never carried the control, and any post to this handler from "
            f"elsewhere would silently wipe the member's language. #677 cost "
            f"this project a cleared portal address the same way."
        )

    print(
        "Member language applied: index.php read "
        f"({'reconsider ok' if reconsider is not None else 'reconsider MISSING'}, "
        f"{'switcher ok' if switcher is not None else 'switcher MISSING'}), "
        "bootstrap.php, the account page and its handler read"
    )

    if len(problems) > 0:
        print("")
        for problem in problems:
            print(f"FAIL: {problem}")
        print("")
        print(
            f"{len(problems)} problem(s). A member who chose a language and is "
            f"not given it has no way of knowing why, and no control to try again."
        )
        return 1

    print(
        "OK: the calls are present, in the right order, start-up makes neither, "
        "the ?lang= gate refuses a non-string, and the account page and its "
        "handler use the one reader and the one writer."
    )
    print(
        "   It does NOT prove any of that code is REACHED -- that is behavioural, "
        "and eight review rounds of trying to infer it from the source are "
        "recorded in this file's header and in D-065. "
        "tools/i18n-member-language-http-selftest.php measures it."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
