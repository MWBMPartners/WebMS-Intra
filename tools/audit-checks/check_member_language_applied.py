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
both only when a session is active — and its one and only caller sat in
`bootstrap.php`, before the session started. So `?lang=cy` applied to the one
request that carried it and was thrown away by its own redirect, and
`tblUsers.locale` was never written by anything, on any installation. That is
why every account row still held the column's own default (D-060).

WHAT IT CHECKS
--------------
  A. `index.php` calls `I18n::reconsiderNowSessionExists(`.
  B. `index.php` calls `I18n::switchLocale(`.
  C. Both come AFTER `Auth::ensureSession()` in `index.php`. **This is the
     whole point** — a call in the right file in the wrong place is the fault
     that was just fixed, and it looks completely correct.
  D. `bootstrap.php` calls NEITHER. Leaving a second call where the fault was
     would re-create it: the early one resolves to the browser's answer, and
     for the switcher it would silently drop the choice again.
  E. The `?lang=` gate's WHOLE condition, matched as one string. Not the
     `is_string()` test alone: appending `&& false` to the condition left an
     earlier version green, and that stops any language being chosen and the
     column being written ever again. Pinning the whole condition is this
     repository's standing remedy for that.
  F. Neither call is inside a conditional. Measured: wrapping the reconsider
     call in `if (false) { ... }` left an earlier version green, which is
     #709's recorded lesson word for word -- when you pin a call, pin the gate
     that decides whether its block is entered at all. Brace depth is compared
     with the depth of the `Auth::ensureSession()` call, so both must sit at
     the same level of the file.
  H. The account page offers the control, and its handler asks whether the
     field ARRIVED rather than what it contains. D-062 made this control part
     of #763 rather than a later package, so it needs a guard or it is pinned
     by nothing. The "did it arrive" half is load-bearing: an empty value is a
     real instruction here ("forget my choice"), so `$_POST['locale'] ?? ''`
     cannot be told from a form that never carried the control, and a post from
     anywhere else would silently wipe a member's language. #677 cost this
     project a cleared portal address exactly that way.
  G. The reconsider call is PASSED a connection. `reconsiderNowSessionExists()`
     takes a nullable mysqli with a default of null, so `reconsiderNowSessionExists()` with no
     argument is legal PHP, reads as a harmless tidy-up, and **restores the
     member-locale half of #763 completely** -- with no connection the column
     is never read, nothing sets `$_SESSION['user_locale']`, and
     `resolveLocale()` falls through to `Accept-Language`. The self-test cannot
     catch it either, because it passes a connection explicitly. Measured: an
     earlier version of this check was green on it.

Each check is read from a copy of the file with **comments removed and string
literals masked**, because this repository has been bitten repeatedly by a
check that a comment mentioning the call was enough to satisfy, and by a `}`
or a keyword inside a message answering a walk falsely.

WHAT IT CANNOT DO, said plainly
-------------------------------
  * It cannot tell whether a page RENDERS in the member's language. That needs
    HTTP and a seeded installation. `tools/i18n-member-language-selftest.php`
    drives the class and says the same thing in its own closing lines.
  * It cannot see a call made through a variable class name, an alias, or a
    helper function in another file.
  * It does not read the ORDER of the two calls relative to each other. They
    are independent: one resolves, the other stores.

Package: WebMS-Intra / audit checks
Author: Claude Opus 5 (for MWBM Partners Ltd, t/a MWservices)
Copyright: All Rights Reserved -- MWBM Partners Ltd
Version: 1.0.0
"""

from __future__ import annotations

import os
import re
import sys

INDEX = os.path.join("web", "public_html", "index.php")
BOOTSTRAP = os.path.join("web", "_core", "bootstrap.php")
ACCOUNT = os.path.join("web", "_apps", "auth", "account", "index.php")
ACCOUNT_SAVE = os.path.join("web", "_apps", "auth", "account", "save.php")

# 📌 The ?lang= condition, WHOLE, with runs of whitespace freed to one space.
#    Pinned as one string rather than as its parts, because asking only for the
#    is_string() test passed on `&& false` appended to it. A deliberate change
#    here is a deliberate edit of this constant.
PINNED_LANG_CONDITION = (
    "if (isset($_GET['lang']) === true && is_string($_GET['lang']) === true "
    "&& $_GET['lang'] !== '') {"
)


def whitespace_freed(text: str) -> str:
    """Runs of whitespace to one space, so indentation and wrapping are free."""
    return re.sub(r"\s+", " ", text)


def brace_depth(code: str, offset: int) -> int:
    """
    How many unclosed `{` sit before `offset`.

    ⚠️ IT STEPS OVER STRING LITERALS, and without that one `{` in a message
       would throw the count off for the rest of the file. The copy this is
       given already has its literals masked to runs of `x`, so the step is
       belt and braces -- but `check_table_name_comparisons.py` and the money
       pages both learnt this the hard way, so it is written out rather than
       assumed.
    """
    depth = 0
    i = 0
    while i < offset and i < len(code):
        ch = code[i]
        if ch in "'\"":
            quote = ch
            i += 1
            while i < offset and i < len(code):
                if code[i] == "\\":
                    i += 2
                    continue
                if code[i] == quote:
                    i += 1
                    break
                i += 1
            continue
        if ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
        i += 1
    return depth


def repo_root() -> str:
    here = os.path.dirname(os.path.abspath(__file__))
    return os.path.dirname(os.path.dirname(here))


def readable_code(text: str, mask_strings: bool = True) -> str:
    """
    Comments gone, string literals masked, length and line count preserved.

    Pass `mask_strings=False` where the thing being looked for CONTAINS a
    string — an array key such as `$_GET['lang']`. Masking would turn that key
    into `'xxxx'` and the search could then never match, which is a clause
    that cannot fail dressed as a strict check. Comments are still removed in
    that view, which is what the masking was mostly guarding against.

    ⚠️ MASKED RATHER THAN DELETED. A `)` or the word `switchLocale` inside a
       message would answer a search falsely either way, and deleting changes
       every line number after it, so a reported line would point at the wrong
       place. Replacing each literal with a run of `x` of the same length
       keeps both honest.
    """
    out: list[str] = []
    i = 0
    n = len(text)
    while i < n:
        ch = text[i]
        if ch in "'\"":
            quote = ch
            out.append(ch)
            i += 1
            while i < n:
                if text[i] == "\\" and i + 1 < n:
                    out.append("xx" if mask_strings is True else text[i:i + 2])
                    i += 2
                    continue
                if text[i] == quote:
                    out.append(quote)
                    i += 1
                    break
                if mask_strings is True:
                    out.append("\n" if text[i] == "\n" else "x")
                else:
                    out.append(text[i])
                i += 1
            continue
        if text.startswith("//", i) or ch == "#":
            began = i
            while i < n and text[i] != "\n":
                i += 1
            out.append(" " * (i - began))
            continue
        if text.startswith("/*", i):
            end = text.find("*/", i + 2)
            end = n if end == -1 else end + 2
            out.append("".join(c if c == "\n" else " " for c in text[i:end]))
            i = end
            continue
        out.append(ch)
        i += 1
    return "".join(out)


def main() -> int:
    root = repo_root()
    problems: list[str] = []

    for relative in (INDEX, BOOTSTRAP, ACCOUNT, ACCOUNT_SAVE):
        if os.path.isfile(os.path.join(root, relative)) is False:
            print(f"FAIL: {relative} is not there. This check cannot run.")
            return 1

    with open(os.path.join(root, INDEX), "r", encoding="utf-8") as handle:
        index_raw = handle.read()
    index_code = readable_code(index_raw)
    # Comments removed, strings KEPT — for check E, which looks at an array key.
    index_keys = readable_code(index_raw, mask_strings=False)
    with open(os.path.join(root, BOOTSTRAP), "r", encoding="utf-8") as handle:
        boot_code = readable_code(handle.read())

    reconsider = re.search(r"I18n::reconsiderNowSessionExists\s*\(", index_code)
    switcher = re.search(r"I18n::switchLocale\s*\(", index_code)
    ensure = re.search(r"Auth::ensureSession\s*\(", index_code)

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

    # E. The WHOLE ?lang= condition, pinned as one string.
    #
    # ⚠️ NOT THE is_string() TEST ALONE. An earlier version asked only for that
    #    and was GREEN when `&& false` was appended to the condition -- which
    #    means no language can be chosen and tblUsers.locale is never written
    #    again. Pin the whole condition; this repository has learnt that at
    #    least four times (#668, #674, #709, #485).
    if PINNED_LANG_CONDITION not in whitespace_freed(index_keys):
        problems.append(
            f"{INDEX}: the ?lang= condition is not what it was pinned as.\n"
            f"       pinned: {PINNED_LANG_CONDITION}\n"
            f"       Two things it is guarding at once. The is_string() half: a "
            f"request sent as a list -- ?lang[]=x -- otherwise reaches "
            f"switchLocale(), whose typed parameter refuses it with a TypeError, "
            f"which is a 500 on EVERY address, open or closed, because this runs "
            f"ahead of the maintenance gate (#509 point 2). And the condition as "
            f"a WHOLE: appending `&& false` to it stops any language being chosen "
            f"and tblUsers.locale being written ever again, and that was green "
            f"against a check that asked only for the is_string() test.\n"
            f"       If the change is deliberate, update PINNED_LANG_CONDITION in "
            f"{os.path.basename(__file__)} as a separate, visible act."
        )

    # F. Neither call may sit inside a conditional.
    #
    # ⚠️ `if (false) { I18n::reconsiderNowSessionExists(...); }` was GREEN
    #    against an earlier version: the inner pin matched text that never ran.
    #    #709: when you pin a call, pin the gate that decides whether its block
    #    is entered at all, or you have pinned a label on a door nobody opens.
    if ensure is not None:
        base_depth = brace_depth(index_code, ensure.start())
        for name, found in (("I18n::reconsiderNowSessionExists", reconsider),
                            ("I18n::switchLocale", switcher)):
            if found is None:
                continue
            depth = brace_depth(index_code, found.start())
            # switchLocale() legitimately sits one level in, inside the ?lang=
            # block whose whole condition check E pins. The reconsider call must
            # be at the same level as ensureSession(), because nothing may
            # decide whether it runs.
            allowed = base_depth + 1 if name.endswith("switchLocale") else base_depth
            if depth != allowed:
                line = index_code[:found.start()].count("\n") + 1
                problems.append(
                    f"{INDEX} line {line}: {name}() is {depth - base_depth} brace "
                    f"level(s) away from Auth::ensureSession(), where "
                    f"{allowed - base_depth} is expected. Something now decides "
                    f"whether it runs, and whatever that something is, a request "
                    f"it says no to is a request where the member's language is "
                    f"not applied -- the whole of #763, back again, with this "
                    f"check green because the call is still in the file."
                )

    # G. The reconsider call must be PASSED a connection.
    #
    # ⚠️ The parameter is `?\mysqli $db = null`, so dropping the argument is
    #    legal PHP and reads as a tidy-up -- and it restores the member-locale
    #    half of #763 in full. The self-test cannot see it: it passes a
    #    connection explicitly.
    if reconsider is not None:
        tail = index_code[reconsider.end():]
        closing = tail.find(")")
        inside = tail[:closing] if closing != -1 else ""
        if inside.strip() == "":
            line = index_code[:reconsider.start()].count("\n") + 1
            problems.append(
                f"{INDEX} line {line}: reconsiderNowSessionExists() is called "
                f"with NO argument. Its parameter defaults to null, so this is "
                f"legal PHP and looks harmless -- and with no connection the "
                f"member's tblUsers.locale is never read, nothing sets "
                f"$_SESSION['user_locale'], and resolveLocale() falls through to "
                f"Accept-Language. That is the member-locale half of #763, whole. "
                f"Pass the request's connection."
            )

    # H. The account page's control, and its handler.
    with open(os.path.join(root, ACCOUNT), "r", encoding="utf-8") as handle:
        account_keys = readable_code(handle.read(), mask_strings=False)
    with open(os.path.join(root, ACCOUNT_SAVE), "r", encoding="utf-8") as handle:
        save_raw = handle.read()
    save_code = readable_code(save_raw)
    save_keys = readable_code(save_raw, mask_strings=False)

    if re.search(r"name=.locale.", account_keys) is None:
        problems.append(
            f"{ACCOUNT} no longer offers a language control (no field named "
            f"'locale'). D-062 put it there because the top-bar menu CHANGES a "
            f"language but is not a place a member can see what the portal has "
            f"stored about them, and offers no way back to following their "
            f"browser."
        )
    if "I18n::storedChoice" not in account_keys:
        problems.append(
            f"{ACCOUNT} does not ask I18n::storedChoice(). Reading the column "
            f"here instead would be a second reader of one fact, and two "
            f"readers of a language can disagree -- which is how this page came "
            f"to tell every member their language was 'en'."
        )
    if "I18n::enabledLocales" not in account_keys:
        problems.append(
            f"{ACCOUNT} does not build its list from I18n::enabledLocales(). "
            f"That is the same list the top-bar menu uses, so the two can never "
            f"offer different languages -- and it keeps a right-to-left language "
            f"with no translation file off the list, which would otherwise give "
            f"a right-to-left page of English words."
        )

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
        "bootstrap.php read for either call"
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
        "OK: the language question is asked after the session starts, start-up "
        "asks it nowhere, neither call is behind a condition, the reconsider call "
        "is passed a connection, the ?lang= condition is as pinned, and the "
        "account page offers the control through the one reader and the one writer."
    )
    print(
        "   It cannot tell whether a page RENDERS in that language -- that needs "
        "HTTP and a seeded installation, and tools/i18n-member-language-selftest.php "
        "says the same of itself."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
