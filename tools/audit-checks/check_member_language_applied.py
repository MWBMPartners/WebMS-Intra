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
  E. **Three WHOLE REGIONS, pinned as text** in `member-language.pinned.txt`:
     the part of `index.php` from `Auth::ensureSession()` through the `?lang=`
     block; the account page's language control; and the account handler's
     language block. Any change to any statement in them fails, and `--repin`
     regenerates the sidecar as a separate, visible act.

     **This replaced four substring pins and a brace-depth test, and the reason
     is the whole lesson of this file.** Three check rounds each got a one-edit
     plant past a cleverer pattern: `if (false) { ... }` was answered with a
     brace count, so `if (false)` with NO braces walked through; `false &&`
     walked through; "is the argument missing" was answered, so the literal
     `(null)` walked through; the gate conditions were pinned, so emptying the
     block BODY walked through, and so did `chooseLanguage(null)` and
     `$langStored === false` inside the option loop. Every one is the same
     thing -- the statement is still there and does nothing -- and #485 took
     FOUR rounds to learn that the answer is not a fifth pattern but to pin the
     text.

     What the four substring pins used to say is kept as the REASON in the
     failure message, because a reason is what the next reader needs; they are
     gone as code, because two guards over one fact drift apart and a pattern
     left as dead code is one somebody will edit believing it works.

  F. The account page offers a `<select name="locale">` that is neither
     disabled nor hidden -- a disabled control is not submitted, so the handler
     would see no field and store nothing while a presence check read it as
     there (the GoCardless lesson, #569).

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

# 📌 The sidecar holding the three pinned regions, and the regions themselves,
#    named by the code that starts and ends each one.
#
# 🚨 WHY A WHOLE-REGION PIN AND NOT A BETTER PATTERN. Three check rounds, three
#    one-edit bypasses, and each fix closed the spelling it had been handed:
#    `if (false) { ... }` was answered with a brace count, so `if (false) ...`
#    with NO braces walked through; "is the argument missing" was answered, so
#    the literal `(null)` walked through; the gate conditions were pinned, so
#    emptying the block BODY walked through, and so did `chooseLanguage(null)`.
#    All of them are the same thing -- the statement is still there and does
#    nothing -- and #485 took four rounds to learn that the answer is to pin the
#    whole thing as TEXT rather than to guess the next spelling. `--repin` makes
#    a deliberate change a separate, visible act.
#
# ⚠️ WHAT THE NORMALISATION THROWS AWAY, enumerated as #485 says it must be.
#    Comments go, and runs of whitespace become one space. **PHP does not care
#    about whitespace except inside a string literal or a heredoc**, so the
#    only thing given up is telling `'a b'` from `'a  b'` -- immaterial in these
#    three regions, where no logic reads a string's internal spacing, and
#    `readable_code()` refuses a heredoc outright (see `NO_HEREDOC`). Freeing
#    whitespace rather than keeping line breaks is deliberate: a rewrap must not
#    turn this red, because a check that cries wolf gets switched off (#483).
PINNED_REGIONS_FILE = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), "member-language.pinned.txt"
)

# Each region: (label, file, the code it starts at, the `if` whose closing brace
# ends it). The second anchor is walked to its matching `}`, so the region always
# covers the WHOLE block however it is reformatted.
PINNED_REGIONS = (
    (
        "index.php: the session starts, the language is resolved, ?lang= is handled",
        INDEX,
        "Auth::ensureSession();",
        "if (isset($_GET['lang'])",
    ),
    (
        "account page: the language control",
        ACCOUNT,
        "$langRead",
        None,
    ),
    (
        "account handler: storing or clearing the language",
        ACCOUNT_SAVE,
        "if (array_key_exists('locale', $_POST)",
        "if (array_key_exists('locale', $_POST)",
    ),
)

def whitespace_freed(text: str) -> str:
    """Runs of whitespace to one space, so indentation and wrapping are free."""
    return re.sub(r"\s+", " ", text)


def block_end(code: str, start: int) -> int:
    """
    The offset just past the `}` that closes the first `{` at or after `start`.

    ⚠️ IT STEPS OVER STRING LITERALS. The money pages and
       `check_table_name_comparisons.py` both record that a `{` inside a message
       otherwise swallows the rest of a function, and a walk that cannot do this
       passes on a removed gate.
    """
    i = code.find("{", start)
    if i == -1:
        return -1
    depth = 0
    while i < len(code):
        ch = code[i]
        if ch in "'\"":
            quote = ch
            i += 1
            while i < len(code):
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
            if depth == 0:
                return i + 1
        i += 1
    return -1


def nothing_glued_in_front(raw: str, start_anchor: str) -> bool:
    """
    Whether the pinned region begins a fresh statement.

    🚨 THE LAST HOLE THE THIRD CHECK ROUND FOUND, AND IT IS WORTH THE WHOLE
       COMMENT. A whole-region pin cannot see something glued IN FRONT of its
       first statement: `if (false)` on the line above, with no braces, makes
       the entire region the body of a conditional that never runs, and the
       region's own text is unchanged, so it matched. Measured on
       `save.php` -- the gate was green while a member's language could never
       be stored.

       Moving the start anchor one statement earlier would have moved the hole
       rather than closed it, so the rule is general: the last thing before the
       region, ignoring whitespace, must be `;`, `{`, `}`, `:` or the `>` of a
       `?>`. Anything else -- `)`, `&&`, `else`, a bare `if (false)` -- is
       something reaching across into the region.
    """
    code = readable_code(raw, mask_strings=False)
    at = code.find(start_anchor)
    if at == -1:
        return True          # a missing region is reported elsewhere
    before = code[:at].rstrip()
    if before == "":
        return True
    # An opening PHP tag is a fresh start too -- the account page's region is
    # the first thing inside its own `<?php`, and a first version of this rule
    # reported that as glue. Crying wolf gets a check switched off (#483), so
    # both directions were checked before this was committed.
    if before.endswith("<?php") is True or before.endswith("<?=") is True:
        return True
    return before[-1] in ";{}:>"


def region_text(raw: str, start_anchor: str, block_anchor) -> str:
    """
    One pinned region, normalised: comments gone, whitespace freed.

    `block_anchor` None means "to the end of the file's PHP region we were
    given" -- used for the account page, whose control is markup rather than one
    block, and whose own end marker is the `<?php endif; ?>` below it.
    """
    code = readable_code(raw, mask_strings=False)
    start = code.find(start_anchor)
    if start == -1:
        return ""
    if block_anchor is None:
        end = code.find("endif;", start)
        end = len(code) if end == -1 else end + len("endif;")
    else:
        at = code.find(block_anchor, start)
        if at == -1:
            return ""
        end = block_end(code, at)
        if end == -1:
            return ""
    return whitespace_freed(code[start:end]).strip()


def read_pinned() -> dict:
    """The sidecar, as {label: normalised text}."""
    if os.path.isfile(PINNED_REGIONS_FILE) is False:
        return {}
    out = {}
    label = None
    for line in open(PINNED_REGIONS_FILE, "r", encoding="utf-8"):
        if line.startswith("### "):
            label = line[4:].rstrip("\n")
            continue
        if label is not None and line.strip() != "":
            out[label] = line.rstrip("\n")
            label = None
    return out


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

    # H. The account page's control, and its handler.
    with open(os.path.join(root, ACCOUNT), "r", encoding="utf-8") as handle:
        account_keys = readable_code(handle.read(), mask_strings=False)
    with open(os.path.join(root, ACCOUNT_SAVE), "r", encoding="utf-8") as handle:
        save_raw = handle.read()
    save_code = readable_code(save_raw)
    save_keys = readable_code(save_raw, mask_strings=False)

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
        # ⚠️ A `disabled` control IS NOT SUBMITTED, so the handler sees no
        #    field, decides nothing, and the member's choice is never stored --
        #    while this check stays green over a control that looks present.
        #    That is the GoCardless lesson (#569): disabling the option would
        #    have made Save switch the payment provider silently.
        problems.append(
            f"{ACCOUNT}: the language control is disabled or hidden. A disabled "
            f"control is NOT submitted, so the handler sees no field and stores "
            f"nothing, and a hidden one cannot be used -- either way the member "
            f"has a control that does nothing while this check reads it as "
            f"present."
        )
    # And "Follow my browser (X)" must name what the BROWSER asks for, never
    # I18n::locale() -- which, for anybody with a stored choice, IS that choice.
    if "I18n::browserLanguage" not in account_keys:
        problems.append(
            f"{ACCOUNT}: the 'Follow my browser' label does not ask "
            f"I18n::browserLanguage(). Built from I18n::locale() it names the "
            f"language the page is being DRAWN in, which for anybody who has a "
            f"stored choice is that choice -- so the label was wrong for every "
            f"single person who would ever read it, the option existing only to "
            f"undo a choice. Measured: stored cy, Accept-Language fr, label said "
            f"Cymraeg, answer would be Fran\u00e7ais."
        )
    if "I18n::enabledLocales" not in account_keys:
        problems.append(
            f"{ACCOUNT} does not build its list from I18n::enabledLocales(), "
            f"which is the languages this installation has a translation file "
            f"for.\n"
            f"       Two things this message USED to say, both of which were "
            f"untrue and are worth recording because they were untrue in the "
            f"reassuring direction. It said the account page and the top-bar "
            f"menu 'can never offer different languages': the menu ALSO hides "
            f"anything below `portal.i18n.minimum_coverage_for_switcher`, a real "
            f"seeded setting, and `coverage('cy')` measures 0.30 -- so at the "
            f"0.95 that setting's own comment suggests, the menu disappears "
            f"entirely while this page still offers Welsh. And it said the list "
            f"'keeps a right-to-left language with no translation file off', "
            f"which is the behaviour the next round deliberately REMOVED: the "
            f"page must offer a stored language even with no translation file, "
            f"or it matches no option, nothing is selected, and an unrelated "
            f"Save deletes it."
        )

    if "I18n::chooseLanguage" not in save_code:
        problems.append(
            f"{ACCOUNT_SAVE} does not call I18n::chooseLanguage(), so either the "
            f"language is not saved at all or something else is writing "
            f"tblUsers.locale. There is ONE writer; for eight months there was "
            f"also exactly one, in the wrong place, and that is the whole of "
            f"#763."
        )
    # ── E. The three WHOLE-REGION pins ────────────────────────────────────
    # 🚨 This replaced four substring pins and a brace-depth test. Three check
    #    rounds each got a one-edit plant past a cleverer pattern, and every one
    #    was the same thing: the statement is still there and does nothing.
    #    #485 took four rounds to learn that the answer is to pin the text.
    pinned = read_pinned()
    now = {}
    glued: list[str] = []
    for label, relative, start_anchor, block_anchor in PINNED_REGIONS:
        with open(os.path.join(root, relative), "r", encoding="utf-8") as handle:
            raw = handle.read()
        now[label] = region_text(raw, start_anchor, block_anchor)
        if nothing_glued_in_front(raw, start_anchor) is False:
            glued.append(f"{relative} ({label})")

    if "--repin" in sys.argv:
        with open(PINNED_REGIONS_FILE, "w", encoding="utf-8") as handle:
            handle.write(
                "# The three pinned regions of #763, regenerated by\n"
                "#   python3 tools/audit-checks/check_member_language_applied.py --repin\n"
                "# Comments removed and runs of whitespace freed to one space. Do not\n"
                "# hand-edit this file: change the code and repin, so the change is\n"
                "# visible in one diff.\n"
            )
            for label, _, _, _ in PINNED_REGIONS:
                handle.write(f"\n### {label}\n{now[label]}\n")
        print(f"Repinned {len(PINNED_REGIONS)} region(s) to {PINNED_REGIONS_FILE}")
        return 0

    for where in glued:
        problems.append(
            f"{where}: something is glued IN FRONT of this pinned region, so it "
            f"no longer begins a fresh statement.\n"
            f"       A whole-region pin cannot see this: `if (false)` on the line "
            f"above, with no braces, makes the entire region the body of a "
            f"conditional that never runs while the region's own text is "
            f"untouched. Measured -- the gate was green while a member's language "
            f"could never be stored.\n"
            f"       The last thing before a pinned region must be `;`, `{{`, "
            f"`}}`, `:` or the `>` of a `?>`."
        )

    for label, relative, _, _ in PINNED_REGIONS:
        if now[label] == "":
            problems.append(
                f"{relative}: the pinned region '{label}' could not be found at "
                f"all -- its anchors are gone, so there is nothing left to guard."
            )
            continue
        if label not in pinned:
            problems.append(
                f"The region '{label}' is not in "
                f"{os.path.basename(PINNED_REGIONS_FILE)}. Run this script with "
                f"--repin, read the diff, and commit it."
            )
            continue
        if now[label] != pinned[label]:
            problems.append(
                f"{relative}: the region '{label}' is not what it was pinned "
                f"as.\n"
                f"       This pin covers the WHOLE region, on purpose. Three "
                f"check rounds each got a one-edit plant past a cleverer "
                f"pattern -- `if (false) {{ }}`, then `if (false)` with no "
                f"braces, then `false &&`, then an emptied block body, then "
                f"`chooseLanguage(null)`, then `$langStored === false` inside "
                f"the option loop -- and every one of them is the same thing: "
                f"the statement is still there and does nothing.\n"
                f"       ⚠️ A PURE REFORMAT FAILS THIS TOO, and that is "
                f"deliberate rather than a fault. Comments and runs of "
                f"whitespace are free, but PHP needs a space in places where "
                f"there was none before, so re-wrapping a call across lines "
                f"does change the normalised text. Taking whitespace out "
                f"altogether is not an option -- `new Foo` would become "
                f"`newFoo`. The precedent is `portal-translate.pinned.js` "
                f"(#485), and the reason it is acceptable in both places is the "
                f"same: nothing EXECUTES this code in any test, so a text pin is "
                f"the only guard there is.\n"
                f"       If the change is deliberate, run\n"
                f"         python3 {os.path.basename(__file__)} --repin\n"
                f"       read the diff to "
                f"{os.path.basename(PINNED_REGIONS_FILE)}, and commit it as a "
                f"separate, visible act."
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
        "asks it nowhere, the account page offers a control that is neither "
        "disabled nor hidden and asks browserLanguage(), all three pinned regions "
        "match the sidecar byte for byte, and nothing is glued in front of any of "
        "them."
    )
    print(
        "   It cannot tell whether a page RENDERS in that language -- that needs "
        "HTTP and a seeded installation, and tools/i18n-member-language-selftest.php "
        "says the same of itself."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
