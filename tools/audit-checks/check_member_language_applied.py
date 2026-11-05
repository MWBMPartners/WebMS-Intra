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
#    three regions, where no logic reads a string's internal spacing, and a
#    heredoc inside a pinned region is REFUSED outright (`HEREDOC_MARK`), because
#    a heredoc's internal whitespace IS significant and freeing it would make two
#    genuinely different regions compare equal. An earlier version of this
#    comment cited a `NO_HEREDOC` that had never been written -- so the
#    enumeration #485 asks for was itself unsupported, which is the worse kind of
#    wrong. Freeing
#    whitespace rather than keeping line breaks is deliberate: a rewrap must not
#    turn this red, because a check that cries wolf gets switched off (#483).
# A heredoc or nowdoc opener. Refused inside a pinned region: its internal
# whitespace is significant, so freeing whitespace would make two different
# regions compare equal, and the pin would pass on changed behaviour.
HEREDOC_MARK = "<<<"

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
        "block",
    ),
    (
        "account page: the language control",
        ACCOUNT,
        # ⚠️ THE WHOLE ASSIGNMENT, not just `$langRead`. That name appears three
        #    times in as many lines -- the read, the "could not read" test and
        #    the "is it a string" test -- so the bare name was not unique and the
        #    uniqueness rule rejected it, correctly.
        "$langRead    = \\Portal\\Core\\I18n::storedChoice",
        "endif",
    ),
    (
        "account handler: storing or clearing the language",
        ACCOUNT_SAVE,
        "if (array_key_exists('locale', $_POST)",
        "block",
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


# 🧰 The probe that does the actual PHP reading, and the only thing that knows
#    what PHP is. See its header for the six rounds of hand-written parsing that
#    argue for its existence.
STRUCTURE_PROBE = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), "member-language-structure.php"
)

# A generous limit: the probe reads four files and takes well under a second.
# It exists to stop a hang, not to race a slow machine.
PROBE_TIMEOUT_SECONDS = 30

# The characters that may immediately precede a pinned region, measured from
# TOKENS rather than text, so a `;` inside a comment or a string cannot answer
# for it. **The failure message is built from this constant**, because round 4
# narrowed the set and left the message naming the two characters it had just
# removed — so the sentence somebody acts on told them the cause was allowed.
FRESH_START_CHARS = ";}"


def repo_root() -> str:
    here = os.path.dirname(os.path.abspath(__file__))
    return os.path.dirname(os.path.dirname(here))


def ask_php(root: str, regions) -> list:
    """
    The structural facts, from PHP's own tokeniser.

    🚨 WHY THIS IS NOT DONE IN PYTHON, which is the whole lesson of this file.
       Six check rounds got a one-edit change past a hand-written reader, and
       every one of them was the reader being wrong about PHP rather than the
       rule being too narrow:

         `$endif = 1;`              matched `\bendif\b`, cancelling a wrapper
         a `}` in a heredoc body    counted as a real brace
         `#[Deprecated]`            read as a `#` comment, hiding a `{`
         `'?>'` inside a string     ended the PHP span early
         `<!-- <?php … ?> -->`      PHP RUNS this; the reader blanked it
         `$exit = false;`           counted as an exit, so it cried wolf

       **None of those distinctions is in the text; they are in the grammar.**
       `token_get_all()` knows that `$endif` is `T_VARIABLE` and `endif` is
       `T_ENDIF`, that `#[` is `T_ATTRIBUTE`, that a brace in a heredoc is part
       of an encapsed string, and that PHP in an HTML comment is still PHP.

    ⚠️ IT MUST FAIL LOUDLY, never quietly. PHP missing, PHP crashing, a timeout
       or output that will not parse all return None here and are reported as
       findings by the caller — the `check_static_calls.py` precedent. A check
       that cannot run and says nothing is worse than no check.
    """
    php = shutil.which("php")
    if php is None:
        return None
    if os.path.isfile(STRUCTURE_PROBE) is False:
        return None
    request = json.dumps([
        {"file": os.path.join(root, relative), "anchor": anchor, "mode": mode}
        for _, relative, anchor, mode in regions
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
    if isinstance(answers, list) is False or len(answers) != len(regions):
        return None
    return answers


def whitespace_freed(text: str) -> str:
    """Runs of whitespace to one space, so indentation and wrapping are free."""
    return re.sub(r"\s+", " ", text)


def read_pinned() -> dict:
    """The sidecar, as {label: one line of pinned text}."""
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


def main() -> int:
    root = repo_root()
    problems: list[str] = []

    for relative in (INDEX, BOOTSTRAP, ACCOUNT, ACCOUNT_SAVE):
        if os.path.isfile(os.path.join(root, relative)) is False:
            print(f"FAIL: {relative} is not there. This check cannot run.")
            return 1

    # 📄 Every view of a file comes from PHP, never from a reader of our own.
    #    The plain-text checks below used a Python comment-stripper until round
    #    6, and it was wrong about PHP six different ways; simplifying it so it
    #    could not be wrong about `#[` left it not stripping `//` at all, so a
    #    COMMENT mentioning `I18n::switchLocale` answered the check that looks
    #    for the CALL. One source of truth.
    views = ask_php(root, (
        ("index code", INDEX, "", "file"),
        ("index keys", INDEX, "", "file_keys"),
        ("bootstrap code", BOOTSTRAP, "", "file"),
        ("account keys", ACCOUNT, "", "file_keys"),
        ("save code", ACCOUNT_SAVE, "", "file"),
        ("save keys", ACCOUNT_SAVE, "", "file_keys"),
    ))
    if views is None:
        print(
            "FAIL: the structural probe could not run, so not one of this "
            "check's questions can be answered. It needs PHP on PATH and "
            f"{os.path.basename(STRUCTURE_PROBE)} beside this script.\n"
            "       There is deliberately NO fallback to reading the PHP here: "
            "six check rounds each got a one-edit change past a hand-written "
            "reader, so there is no second-best reading to drop to."
        )
        return 1
    index_code, index_keys, boot_code, account_keys, save_code, save_keys = (
        str(v.get("text", "")) for v in views
    )

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

    # H. The account page's control, and its handler. Both views came from the
    #    probe above, with every other file's.

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
    # ── The three pinned regions, read by PHP ─────────────────────────────
    answers = ask_php(root, PINNED_REGIONS)
    if answers is None:
        print(
            "FAIL: the structural probe could not run. "
            f"It needs PHP on PATH and {os.path.basename(STRUCTURE_PROBE)} beside "
            f"this script, and it must finish within {PROBE_TIMEOUT_SECONDS}s and "
            "print JSON.\n"
            "       This check does NOT fall back to reading the PHP itself. Six "
            "check rounds each got a one-edit change past a hand-written reader, "
            "so there is no second-best reading to drop to -- a check that cannot "
            "run must say so."
        )
        return 1

    pinned = read_pinned()
    now = {}
    glued: list[str] = []
    duplicated: list[str] = []
    heredoc: list[str] = []
    broken: list[str] = []

    for (label, relative, anchor, _), answer in zip(PINNED_REGIONS, answers):
        if "error" in answer:
            broken.append(f"{relative} ({label}): {answer['error']}")
            continue
        now[label] = whitespace_freed(str(answer["text"])).strip()
        now[label + " :: structure"] = (
            f"braces={answer['braces']} alt={answer['alt']} exits={answer['exits']}"
        )
        if answer["count"] != 1:
            duplicated.append(
                f"{relative} ({label}): {anchor!r} appears {answer['count']} times"
            )
        if answer["heredoc"] is True:
            heredoc.append(f"{relative} ({label})")
        before = str(answer["before"])
        if before not in ("<?php", "html") and before not in FRESH_START_CHARS:
            glued.append(f"{relative} ({label}): the token before it ends {before!r}")

    if "--repin" in sys.argv:
        # ⚠️ REFUSE TO REPIN OVER A STRUCTURAL PROBLEM. Repinning while a region
        #    is glued, duplicated, holds a heredoc or has MOVED would write the
        #    fault into the sidecar and print nothing but success -- the one way
        #    a text pin fails silently.
        moved = [
            f"nesting changed: {relative} ({label})"
            for label, relative, _, _ in PINNED_REGIONS
            if (label + " :: structure") in pinned
            and now.get(label + " :: structure") != pinned[label + " :: structure"]
        ]
        blocking = (
            broken
            + moved
            + [f"glued in front: {w}" for w in glued]
            + [f"anchor not unique: {w}" for w in duplicated]
            + [f"heredoc inside: {w}" for w in heredoc]
        )
        if len(blocking) > 0:
            print("REFUSED to repin: the regions are not in a state worth pinning.")
            for line in blocking:
                print(f"  - {line}")
            print("Fix those first, then repin.")
            return 1
        with open(PINNED_REGIONS_FILE, "w", encoding="utf-8") as handle:
            handle.write(
                "# The three pinned regions of #763, regenerated by\n"
                "#   python3 tools/audit-checks/check_member_language_applied.py --repin\n"
                "# Read by PHP's own tokeniser: comments dropped, runs of whitespace\n"
                "# freed to one space. Do not hand-edit this file -- change the code\n"
                "# and repin, so the change is visible in one diff.\n"
            )
            for label, _, _, _ in PINNED_REGIONS:
                handle.write(f"\n### {label}\n{now[label]}\n")
                handle.write(
                    f"\n### {label} :: structure\n"
                    f"{now[label + ' :: structure']}\n"
                )
        print(f"Repinned {len(PINNED_REGIONS)} region(s) to {PINNED_REGIONS_FILE}")
        return 0

    for where in broken:
        problems.append(
            f"{where}. The probe could not read this region at all, so there is "
            f"nothing left guarding it. Its anchor has moved or its block is not "
            f"closed where the probe expects."
        )

    for where in duplicated:
        problems.append(
            f"{where}, so this pin is worthless.\n"
            f"       The region is found by its FIRST match, so a dead duplicate "
            f"above the live code satisfies the pin while the live code is gutted "
            f"-- measured: a copy inside `if (false) {{ }}` above the real block "
            f"left the gate reporting a byte-for-byte match while the only "
            f"surviving chooseLanguage() call was the dead one."
        )

    for where in heredoc:
        problems.append(
            f"{where} now contains a heredoc or nowdoc, which this pin refuses.\n"
            f"       A heredoc's internal whitespace is significant, and this pin "
            f"frees runs of whitespace -- so two genuinely different regions would "
            f"compare equal and the pin would pass on changed behaviour."
        )

    for where in glued:
        problems.append(
            f"{where}: something is glued IN FRONT of this pinned region, so it "
            f"no longer begins a fresh statement.\n"
            f"       A text pin cannot see this on its own: `if (false)` on the "
            f"line above, with no braces, makes the entire region the body of a "
            f"conditional that never runs while the region's own text is "
            f"untouched.\n"
            f"       The last TOKEN before a pinned region must end in one of "
            f"{' '.join('`' + c + '`' for c in FRESH_START_CHARS)}, or be an "
            f"opening `<?php` or inline markup. **This sentence is built from the "
            f"rule itself**: an earlier version listed `{{` and `:` as allowed, "
            f"which were the two characters the round before had REMOVED for "
            f"letting dead code wrap a region.\n"
            f"       It is measured from TOKENS, not text, so a `;` inside a "
            f"comment or a string cannot answer for it -- and it is a cheap first "
            f"pass only. What really guards the region is its pinned NESTING."
        )

    for label, relative, _, _ in PINNED_REGIONS:
        if label not in now:
            continue
        key = label + " :: structure"
        if key in pinned and now[key] != pinned[key]:
            problems.append(
                f"{relative}: the region '{label}' has MOVED -- its nesting is not "
                f"what it was pinned as.\n"
                f"       pinned: {pinned[key]}\n"
                f"       now:    {now[key]}\n"
                f"       `braces` is how many `{{` are open where it starts, `alt` "
                f"how many `if (…):` blocks, `exits` how many `return` / `exit` / "
                f"`die` sit at its own level before it. Any of them changing means "
                f"something now decides whether the region runs -- and the "
                f"region's own TEXT can be untouched while that is true.\n"
                f"       All three come from PHP's own tokeniser, so `$endif`, "
                f"`$exit`, a brace in a heredoc, a `#[Attribute]` and a `'?>'` in "
                f"a string are all read as what they are. Six rounds of "
                f"hand-written parsing got that wrong six different ways.\n"
                f"       If the move is deliberate, run\n"
                f"         python3 {os.path.basename(__file__)} --repin\n"
                f"       and commit the sidecar diff as a separate, visible act."
            )
        elif key not in pinned:
            problems.append(
                f"The structure of '{label}' is not in "
                f"{os.path.basename(PINNED_REGIONS_FILE)}. Run --repin, read the "
                f"diff, and commit it."
            )
        if label not in pinned:
            problems.append(
                f"The region '{label}' is not in "
                f"{os.path.basename(PINNED_REGIONS_FILE)}. Run --repin, read the "
                f"diff, and commit it."
            )
            continue
        if now[label] != pinned[label]:
            problems.append(
                f"{relative}: the region '{label}' is not what it was pinned as.\n"
                f"       This pin covers the WHOLE region, on purpose, and its text "
                f"comes from PHP's tokeniser rather than from a reader of my own.\n"
                f"       ⚠️ A PURE REFORMAT FAILS THIS TOO, deliberately. Comments "
                f"and runs of whitespace are free, but PHP needs a space where a "
                f"re-wrapped call previously had none. Taking whitespace out "
                f"altogether is not an option -- `new Foo` would become `newFoo`. "
                f"The precedent is `portal-translate.pinned.js` (#485), and the "
                f"reason it is acceptable in both places is the same: nothing "
                f"EXECUTES this code in any test, so a text pin is the only guard "
                f"there is.\n"
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
        "match the sidecar byte for byte, each region's anchor is unique and holds "
        "no heredoc, each sits at the nesting it was pinned at, and nothing is "
        "glued in front of any of them."
    )
    print(
        "   It cannot tell whether a page RENDERS in that language -- that needs "
        "HTTP and a seeded installation, and tools/i18n-member-language-selftest.php "
        "says the same of itself."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
