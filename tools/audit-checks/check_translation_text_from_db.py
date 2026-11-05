#!/usr/bin/env python3
"""
The text sent away to be translated comes from the DATABASE, never a request.

Path: tools/audit-checks/check_translation_text_from_db.py

WHY THIS EXISTS
---------------
The handler that used to sit at `web/_apps/api/translate.php` took the words to
be translated out of `$_POST['text']` and looked nothing up. It also called
`Translation::lookupCached()` — through `translate()` — on whatever row number
was posted, asking nothing about who was reading. It could not be reached,
because `ApiRouter` wants `api/{app}/{action}` and that address had two
segments, so nothing was ever exposed; **finishing #485 was the act of making
it reachable**, and three faults would have gone live together (#761):

  1. anybody signed in anywhere could read back the translated words of any row
     of any organisation;
  2. the allow-list of tables constrained the LABEL and not the words, so a
     member could spend the customer's money translating anything at all — and
     the answer was then cached against a real row's number, so the next reader
     of announcement 42 would be shown text that was never in announcement 42;
  3. nothing checked the caller could see the row.

Fault 2 is the one no test catches by accident, because the feature keeps
working: the button translates, something comes back, the cache fills. Only a
reader comparing the cached text with the row would notice.

WHAT IT CHECKS
--------------
  A. **The allow-list is pinned WHOLE**, with `== `, not walked. Adding a table
     fails this check on purpose, so whoever adds one reads the comment above
     it first — the comment explaining why a prayer request is not on it. A
     check that iterates the list stops testing a line somebody deletes
     (#697's lesson, where deleting a name from a constant simply stopped it
     being tested while the label still claimed eight).
  B. **`Translation::translate()` is called from exactly one file** under
     `web/`, named here. Fails BOTH ways: a new caller, and a named one that
     has stopped calling it.
  C. **That caller takes its text from `ContentTranslation::readSourceText()`**
     and reads no request field beyond the three it is allowed. This is the
     direct guard on fault 2.
  D. **Only the shared partial writes the control's own attribute.** A page
     writing `data-portal-translate` itself would be a page deciding for
     itself whether the control may appear, which is the judgement
     `whyNotOffered()` exists to hold in one place (#647). Comments are
     blanked but STRING CONTENTS ARE KEPT, because a page can write the
     attribute out of a PHP string and the first version of this check could
     not see that.
  E. **Both the partial and the handler ask `whyNotOffered()`.** Without this,
     C and D would pass on a handler that accepts a press the page would never
     offer, and on a page that offers one the handler would refuse.
  F. **The script is PINNED WHOLE** against
     `tools/audit-checks/portal-translate.pinned.js` — its code with comments
     removed and whitespace normalised — and separately may never contain
     `innerHTML`, which must hold however the pin changes (the translated words
     come from an outside company, so writing them as markup would hand a third
     party this portal's pages).
     **Why a whole-file pin, which is unusual and deliberate:** nothing
     executes THIS script. `tools/csrf-carriers-selftest.mjs` (#768) does now
     drive a real browser, and it LOADS this file, but only far enough to show
     it does not throw and that the shared token helper writes its carrier —
     it exercises none of the translate flow. And
     FIVE independent check rounds each found a one-edit change that left a
     narrower guard green while the script's token sharing was dead or its
     answers were thrown away. The comment at check F lists all of them. Any
     change to a STATEMENT fails this check — re-indenting and comment text
     are free, a line break is not; `--repin` regenerates it as a separate,
     visible act.

WHAT THIS CANNOT DO, said plainly because an overstated guarantee is worse than
none
-------------------------------------------------------------------------------
  * **It cannot tell whether the allow-list is RIGHT** — only that changing it
    is deliberate. Whether a given table holds the organisation's own words or
    a member's is a judgement, and it is written beside the list.
  * **It cannot see a request value reaching the text by a longer route**: a
    local variable set from a request field three lines earlier, a value out of
    the session, or a helper in another file. It reads the direct assignment
    and the superglobals by name. (A direct `$_SERVER['HTTP_…']` read WAS
    invisible to the first version, which is how a planted header reached the
    provider with every check green; that one is closed.)
   * **It cannot run the script.** Check F reads the two rules as text. The
     browser test added for #768 loads this file but drives only `portal.js`,
     so the toggle, the deadline and the abort path are still exercised by
     nothing at all. **A browser test existing is not the same as THIS script
     being tested**, and recording it loosely would be the reassurance shape
     this file is otherwise full of warnings about.
  * It says nothing about whether a translation is correct, nor about money.
    The monthly limit is enforced in `Translation::translate()`, and no static
    check can prove a limit is obeyed.
  * It does not check the control is REACHABLE — only that one place decides.
    `tools/content-translation-selftest.php` drives the real pages over HTTP.

Package: WebMS-Intra / audit checks
Author: Claude Opus 5 (for MWBM Partners Ltd, t/a MWservices)
Copyright: All Rights Reserved -- MWBM Partners Ltd
Version: 1.0.0
"""

from __future__ import annotations

import os
import re
import sys

# The ONE caller of the engine, and the ONE place the control is drawn.
HANDLER = os.path.join("web", "_apps", "translation", "api", "content.php")
PARTIAL = os.path.join("web", "_core", "templates", "translate-control.php")
DECIDER = os.path.join("web", "_core", "ContentTranslation.php")
SCRIPT  = os.path.join("web", "public_html", "assets", "js", "portal-translate.js")

# A. The allow-list, pinned whole and in order.
EXPECTED_TABLES = ["tblAnnouncements", "tblProject", "tblProjectUpdate"]

# F. THE WHOLE SCRIPT IS PINNED, in `portal-translate.pinned.js` beside this
#    file: its code with comments removed and whitespace normalised.
PINNED_SCRIPT = os.path.join("tools", "audit-checks", "portal-translate.pinned.js")

# C. The only request fields the handler may read.
ALLOWED_REQUEST_FIELDS = {"sourceTable", "sourceID", "targetLanguage"}


def repo_root() -> str:
    here = os.path.dirname(os.path.abspath(__file__))
    return os.path.dirname(os.path.dirname(here))


def mask_php(text: str, hash_is_comment: bool = True) -> str:
    """
    Blank out comments and the CONTENTS of string literals, keeping every
    character position, so a pattern search cannot be answered by a comment or
    by text inside a message.

    Positions are preserved rather than the text being deleted, so a key can
    still be read out of the ORIGINAL at the offset a match was found at. That
    is the only way to ask "which field is read" without being fooled by the
    word appearing in a sentence — and deleting instead of blanking would put
    every reported line number out (the #716 lesson).
    """
    out = list(text)
    index = 0
    length = len(text)
    while index < length:
        two = text[index:index + 2]
        if two == "//" or (hash_is_comment is True and text[index] == "#"):
            end = text.find("\n", index)
            end = length if end == -1 else end
            for position in range(index, end):
                out[position] = " "
            index = end
            continue
        if two == "/*":
            end = text.find("*/", index + 2)
            end = length if end == -1 else end + 2
            for position in range(index, end):
                if out[position] != "\n":
                    out[position] = " "
            index = end
            continue
        if text[index] in ("'", '"'):
            quote = text[index]
            position = index + 1
            while position < length:
                if text[position] == "\\":
                    position += 2
                    continue
                if text[position] == quote:
                    break
                position += 1
            for inner in range(index + 1, min(position, length)):
                if out[inner] != "\n":
                    out[inner] = "x"
            index = min(position + 1, length)
            continue
        index += 1
    return "".join(out)


def blank_comments(text: str, hash_is_comment: bool = True) -> str:
    """
    Comments blanked, string CONTENTS KEPT, positions preserved.

    `mask_php()` turns the inside of every string literal into `x`, which is
    right when the question is "does this code DO something" and wrong when the
    question is "does this file contain this name anywhere real". A page can
    write the control's own attribute from a PHP string:

        echo '<div data-portal-translate …>';

    and the masked copy cannot see it. An independent check planted exactly
    that and check D passed. (The self-test caught it over HTTP, so nothing was
    unguarded — but a gate whose stated claim does not hold is worse than one
    that admits the gap.)
    """
    out = list(text)
    index = 0
    length = len(text)
    while index < length:
        two = text[index:index + 2]
        if two == "//" or (hash_is_comment is True and text[index] == "#"):
            end = text.find("\n", index)
            end = length if end == -1 else end
            for position in range(index, end):
                out[position] = " "
            index = end
            continue
        if two == "/*":
            end = text.find("*/", index + 2)
            end = length if end == -1 else end + 2
            for position in range(index, end):
                if out[position] != "\n":
                    out[position] = " "
            index = end
            continue
        index += 1
    return "".join(out)


def js_source(text: str) -> str:
    """
    Turn every character JavaScript treats as a LINE TERMINATOR into `\n`.

    🚨 WHY THIS EXISTS, and it is the sharpest thing in this file.
    JavaScript ends a `//` comment at FOUR characters: LF, CR, **U+2028 LINE
    SEPARATOR** and **U+2029 PARAGRAPH SEPARATOR**. `blank_comments()` and
    `mask_php()` end one at `\n` only. Python's universal-newline read turns a
    lone CR into `\n` by accident, so CR was safe; U+2028 and U+2029 came
    through untouched, and the blanker then ran PAST them to the end of the
    physical line — turning real code into spaces, after which
    `splitlines()` had nothing to split and the line was dropped as blank.

    So ONE added line, its `//` comment terminated by U+2028, put
    `output.innerHTML = String(data.translation || '')` live with this gate
    GREEN and every audit check green — measured, and executed in node: the
    planted markup ran. That defeats BOTH guards at once: the whole-file pin,
    and the `innerHTML` search which is the one security rule here.

    **And the likelier case is an accident, not an attack.** U+2028 is what
    text pasted out of a PDF or a word processor carries. A `//` comment whose
    TEXT contains one, with no code after it at all, makes the WHOLE FILE fail
    to parse — `node --check` exit 1 — so the Translate control silently
    does nothing on every page, while this gate says OK. That is exactly the
    fault class the pin exists for.

    Do NOT instead read the file with `newline=''`: that would stop Python
    folding CR and reopen the case that is currently safe by accident.

    **IT ALSO TURNS VERTICAL TAB AND FORM FEED INTO SPACES**, which is the
    opposite direction and stops a false alarm. JavaScript calls both plain
    WHITESPACE; Python's `str.splitlines()` SPLITS on them, so either one
    inside a statement added a line break to the normalised code and the pin
    went red over source JavaScript is perfectly happy with. Crying wolf gets
    a check switched off (#483), so the two are folded to the thing both
    languages agree they are.
    """
    return (
        text.replace("\u2028", "\n").replace("\u2029", "\n")
            .replace("\v", " ").replace("\f", " ")
    )


def js_will_not_parse(script: str) -> list[str]:
    """
    Reasons JavaScript would REFUSE this whole file, which Python reads happily.

    🚨 WHY THIS EXISTS. The pin compares normalised code, so it cannot see
    a change that normalises to nothing — and TWO such changes stop the file
    parsing at all, which means the Translate control silently does nothing on
    every page while this check says OK. That is the fault class the pin was
    adopted for, and the U+2028 fix closed only one route to it. Measured, each
    with `node --check` refusing the file and this gate green beforehand:

      • **A `/*` that is never closed, after the last statement.**
        `blank_comments()` treats an unterminated block comment as "comment to
        the end of the file" and blanks it; JavaScript throws "Unterminated
        comment". With no statement after it the normalised code is unchanged,
        so the pin matched. **This is an ordinary accident** — a comment
        begun and not finished, or a paste truncated at the end of a file.
      • **U+001F in a statement.** `str.split()` counts it as whitespace and
        deletes it; JavaScript rejects it as an illegal character. It is the
        one C0 control that Python strips WITHOUT splitting, so U+0085,
        U+001C, U+001D and U+001E all fail the pin by accident and this one
        did not.

    `\t`, `\n`, `\r`, `\v` and `\f` are excluded because JavaScript accepts
    all five in source; flagging them would be the crying-wolf direction.
    """
    problems = []
    odd = sorted({
        "U+%04X" % ord(character) for character in script
        if (ord(character) < 0x20 and character not in "\t\n\r\v\f")
        or 0x7F <= ord(character) <= 0x9F
    })
    if len(odd) > 0:
        problems.append(
            f"holds control character(s) {', '.join(odd)}, which JavaScript refuses in "
            f"source. Python reads them and the pin can normalise them away, so the whole "
            f"file would stop parsing with this check green."
        )
    # The same walk `blank_comments()` does, so this fires exactly where
    # Python's reading and JavaScript's diverge.
    index = 0
    while True:
        start = script.find("/*", index)
        if start == -1:
            break
        end = script.find("*/", start + 2)
        if end == -1:
            problems.append(
                f"has a `/*` at character {start} that is never closed. Python treats that "
                f"as a comment running to the end of the file; JavaScript refuses the whole "
                f"file with \"Unterminated comment\", so nothing in it runs."
            )
            break
        index = end + 2
    return problems


def normalised_code(script: str) -> str:
    """
    A script's CODE: comments gone, indentation free — **and LINE BREAKS KEPT.**

    🚨 KEEPING THE LINE BREAKS IS THE WHOLE POINT, and leaving them out was
    round 5's bypass. **In JavaScript a newline is a semicolon**: automatic
    semicolon insertion terminates a statement after `return`, `throw`,
    `break`, `continue` and `++`/`--`. So collapsing newlines made

        return
            box.querySelector('[data-portal-translate-output]');

    equal to `return box.querySelector(...);` — and that one added newline
    changes `outputBlock()` to return `undefined`, which makes a translation
    that ARRIVED AND WAS PAID FOR get thrown away while the member is told
    nothing came back. Measured by running the file in node, not reasoned.

    The same lever reached `shareToken()`'s `return true;`, the button labels
    and the two `return { ok: … }` objects. Every one of them whitespace only,
    with the gate green — the same class of one-edit bypass rounds 2, 3 and 4
    each found, reached through the NORMALISER rather than through a spelling.

    Indentation and blank lines are still free, and comment TEXT is still free,
    because neither changes what runs.

    **WHAT THIS STILL CANNOT SEE: any whitespace inside a STRING LITERAL, in
    EITHER direction.** Two spaces collapse to one — and so does a tab, and
    so does a non-breaking space. The direction that bites is the one an
    earlier version of this note left out: turning a single space INTO two, or
    into a tab, is equally invisible.

    **AND ONE STRING HERE DOES DEPEND ON IT, so "no string depends on this" was
    untrue in the reassuring direction.** `'CSRF check failed'` is compared
    with the exact words `ApiAuth` sends, so `'CSRF  check failed'` or a tab in
    place of that space silently stops the match — and a member is then shown
    the raw words "CSRF check failed" on every press, which is precisely what
    the script's own comment says it avoids. Measured through this gate: two
    spaces, a tab and a non-breaking space are all invisible; a zero-width
    space (U+200B) is caught, because Python does not count it as whitespace.

    **The one string whose whitespace is known to change behaviour,
    `'CSRF check failed'`, is therefore checked separately and
    UN-normalised** — see check F. That closes the residue where it bites; it
    remains open for any string that does not have to match something exactly,
    which today is all the rest.

    A second limit worth naming: `blank_comments()` does not track string
    literals, so a string CONTAINING `//` or `/*` would blank the real code
    after it on the same line. No such string exists in the pinned file today;
    if one is ever added, everything after it on that line is invisible to the
    pin for ever after the next `--repin`.

    ONE function, used by the comparison and by `--repin` alike: two copies of
    this rule would be two chances for the generator and the checker to
    disagree, and then the pin would certify whatever the generator produced.
    """
    lines = [
        " ".join(line.split())
        for line in blank_comments(script, hash_is_comment=False).splitlines()
    ]
    return "\n".join(line for line in lines if line != "")


def read(root: str, relative: str) -> str | None:
    path = os.path.join(root, relative)
    if os.path.isfile(path) is False:
        return None
    with open(path, "r", encoding="utf-8") as handle:
        return handle.read()


def php_files(root: str, under: str) -> list[str]:
    found = []
    for folder, _, names in os.walk(os.path.join(root, under)):
        for name in names:
            if name.endswith(".php"):
                full = os.path.join(folder, name)
                found.append(os.path.relpath(full, root))
    return sorted(found)


def main() -> int:
    root = repo_root()
    problems: list[str] = []

    decider = read(root, DECIDER)
    handler = read(root, HANDLER)
    partial = read(root, PARTIAL)
    script  = read(root, SCRIPT)
    if script is not None:
        script = js_source(script)

    for relative, body in ((DECIDER, decider), (HANDLER, handler), (PARTIAL, partial), (SCRIPT, script)):
        if body is None:
            problems.append(
                f"{relative} is not there. It is one of the three files this feature is "
                f"made of, and without it the others cannot be judged."
            )
    if decider is None or handler is None or partial is None or script is None:
        for problem in problems:
            print(f"FAIL: {problem}")
        return 1

    # ---------------------------------------------------------------- A
    masked = mask_php(decider)
    found_tables: list[str] = []
    for match in re.finditer(r"^\s{8}'([A-Za-z0-9_]+)'\s*=>\s*\[", decider, re.M):
        # Read the name from the ORIGINAL, but only accept it where the masked
        # text shows the line really is code rather than a comment.
        if masked[match.start():match.end()].strip().startswith("'") is False:
            continue
        found_tables.append(match.group(1))
    if found_tables != EXPECTED_TABLES:
        problems.append(
            f"the translatable tables in {DECIDER} are {found_tables}, and this check "
            f"expects exactly {EXPECTED_TABLES}, in that order.\n"
            f"      If you are ADDING one: read the comment above SOURCES first — a prayer "
            f"request is deliberately not translatable, because it is a member's own words "
            f"and usually about a third person who cannot agree to them being sent to "
            f"another company (D-049). Then change EXPECTED_TABLES here in the same commit, "
            f"and say on the issue what the new table holds and whose words they are.\n"
            f"      If you are REMOVING one: the admin page and the member's Account page "
            f"both NAME the three in prose, and so does api-spec.json. Change those too."
        )

    # ---------------------------------------------------------------- B
    callers = []
    for relative in php_files(root, "web"):
        body = read(root, relative)
        if body is None:
            continue
        if "Translation::translate(" in mask_php(body):
            callers.append(relative)
    if callers != [HANDLER]:
        problems.append(
            f"the files calling Translation::translate() are {callers}, and this check "
            f"expects exactly ['{HANDLER}'].\n"
            f"      Every caller has to read its own text from the database through "
            f"ContentTranslation::readSourceText(), check the reader may see that row, and "
            f"ask whyNotOffered() — a second caller is a second chance to get #761 wrong. "
            f"If a second one is genuinely right, name it here and give it its own checks."
        )

    # ---------------------------------------------------------------- C
    handler_masked = mask_php(handler)
    if "= ContentTranslation::readSourceText(" not in handler_masked:
        problems.append(
            f"{HANDLER} does not take its text from ContentTranslation::readSourceText(). "
            f"That method is the one place the words are read, and reading them anywhere "
            f"else is #761's fault 2 — the text came from the request, so the allow-list "
            f"constrained the label and not the words."
        )
    fields: set[str] = set()
    for match in re.finditer(r"\$body\[\s*'", handler_masked):
        closing = handler.find("'", handler.index("'", match.start()) + 1)
        opening = handler.index("'", match.start())
        fields.add(handler[opening + 1:closing])
    # 🚨 A REQUEST VALUE READ ANY OTHER WAY. Reading `$body['…']` was the
    #    only thing the first version of this check looked at, so an
    #    independent check planted ONE line —
    #        $text = (string) ($_SERVER['HTTP_X_SOURCE_TEXT'] ?? $text);
    #    — and this gate, all sixty-one audit checks and all sixty-six
    #    self-test checks stayed green while #761's fault 2 was live and
    #    complete: planted text sent to the provider, charged for, and cached
    #    against a real announcement's own row number. The handler needs none
    #    of these: `ApiAuth` reads the body and the form token for it.
    # ⚠️ ASK FOR THE SUPERGLOBAL'S NAME, NOT A NAME-PLUS-KEY. The first
    #    version of this list held `$_SERVER['HTTP_`, which is half code and
    #    half STRING LITERAL — and `mask_php()` turns the string half into
    #    `x`, so the composite could never match and the planted header read
    #    straight past it. The same trap as the selectors in check F below,
    #    twice in one afternoon. The handler legitimately needs NONE of these.
    for forbidden in ("$_POST", "$_GET", "$_REQUEST", "$_FILES", "$_COOKIE", "$_SERVER"):
        if forbidden in handler_masked:
            problems.append(
                f"{HANDLER} reads `{forbidden}` directly. Everything it is allowed to take "
                f"from the request arrives through `ApiAuth::body()` and is limited to "
                f"{sorted(ALLOWED_REQUEST_FIELDS)}; the words themselves come from the "
                f"database. A request value reaching the text by ANY route is #761's fault "
                f"2, which is invisible to every behavioural test because the feature goes "
                f"on working — the button translates, something comes back, the cache "
                f"fills, and what is cached was never in the row."
            )

    unexpected = sorted(fields - ALLOWED_REQUEST_FIELDS)
    if len(unexpected) > 0:
        problems.append(
            f"{HANDLER} reads request field(s) it should not: {unexpected}. Only "
            f"{sorted(ALLOWED_REQUEST_FIELDS)} may come from the request. **A field "
            f"carrying TEXT must never be one of them** — that is exactly how the old "
            f"handler came to translate whatever was posted and cache it against a real "
            f"row's number (#761). The form token is read by ApiAuth, not here."
        )

    # ---------------------------------------------------------------- D
    writers = []
    for relative in php_files(root, "web"):
        body = read(root, relative)
        if body is None or relative == PARTIAL:
            continue
        # Comments blanked, string contents KEPT: a page may write the
        # attribute out of a PHP string, which the masked copy cannot see.
        if "data-portal-translate" in blank_comments(body):
            writers.append(relative)
    if len(writers) > 0:
        problems.append(
            f"these file(s) write the control's own attribute themselves: {writers}. Only "
            f"{PARTIAL} may, because it is the one place that asks whyNotOffered() before "
            f"drawing anything. A page writing it directly is a page deciding for itself "
            f"whether the control may appear, and a page and a handler each holding a copy "
            f"of one judgement is how they come to disagree (#647)."
        )

    # ---------------------------------------------------------------- E
    for relative, body in ((HANDLER, handler_masked), (PARTIAL, mask_php(partial))):
        if "ContentTranslation::whyNotOffered(" not in body:
            problems.append(
                f"{relative} does not ask ContentTranslation::whyNotOffered(). Both the "
                f"page and the handler must, or the control can be offered where the press "
                f"would be refused, or refused where it would be accepted."
            )

    # ---------------------------------------------------------------- F
    # 🚨 THE SCRIPT WAS READ BY NOTHING AT ALL. An independent check deleted
    #    the token-sharing call AND changed `textContent` to `innerHTML`, and
    #    NOTHING went red anywhere — 61 audit checks and 66 self-test checks.
    #    Both rules were guarded only by the comment that stated them, and one
    #    of them is what stops an outside translation company putting markup
    #    into this portal's pages.
    #
    #    No browser is driven by any test here, so this is a static check and
    #    says so. `#` is NOT a comment in JavaScript, hence the flag.
    # 🚨 FIRST: would JavaScript refuse this file outright? A file that does
    #    not parse runs nothing, and the pin cannot see two of the ways that
    #    happens. See `js_will_not_parse()`.
    for reason in js_will_not_parse(script):
        problems.append(f"{SCRIPT} {reason}")

    script_masked = mask_php(script, hash_is_comment=False)
    if "innerHTML" in script_masked:
        problems.append(
            f"{SCRIPT} uses `innerHTML`. The translated words come from an outside "
            f"company, so writing them as HTML would hand a third party the ability to "
            f"put markup in this portal's pages. It must only ever set `textContent`, "
            f"and the file's own comment says so."
        )
    # 🚨 `shareToken()` IS PINNED WHOLE — ITS BODY AND ITS CALL LINE — AND
    #    THREE WEAKER VERSIONS OF THIS CHECK WERE EACH BYPASSED.
    #
    #      v1 asked whether the text `shareToken(` appeared. `function
    #         shareToken(payload)` satisfies that, so deleting the only call
    #         left it green. A DEFINITION IS NOT A USE.
    #      v2 asked for a call that is not the definition. `if (false) {
    #         shareToken(answer.body); }` satisfies that — #709's round-2
    #         lesson: WHEN YOU PIN A CALL, PIN THE GATE THAT DECIDES WHETHER
    #         ITS BLOCK RUNS, or you have pinned a label on a door nobody
    #         opens.
    #      v3 asked whether two selector strings appeared anywhere. Changing
    #         the write loop to `index < 0` satisfies that: the selector is
    #         still there and the fix is gone.
    #
    #    So the whole thing is pinned, as `check_table_name_comparisons.py`
    #    pins both its helpers' bodies and for the same reason. **ANY edit to
    #    `shareToken()` fails this check on purpose**, including a harmless
    #    one: read what it does, decide the new text is right, and put it here.
    #    Nothing in this repository runs the script, so this is the only guard
    #    on a fault that was measured to break a payment form.
    script_plain = blank_comments(script, hash_is_comment=False)
    # 🚨 ONE PIN FOR THE WHOLE SCRIPT, AFTER FOUR ROUNDS OF PINNING
    #    SPELLINGS. This is the part worth reading.
    #
    #    Four independent check rounds each found a ONE-EDIT change that left
    #    this gate green while the script's token sharing was dead or partial.
    #    Each round I closed the spellings I had been handed, and the next
    #    round found more:
    #
    #      round 2: the call deleted; the call inside `if (false)`; the form
    #               loop changed to `index < 0`.
    #      round 3: the call wrapped but keeping its assignment; a `return`
    #               above it; the line moved into a dead function; a SECOND
    #               `function shareToken()` declared after the real one.
    #      round 4: `shareToken = function () { return true; };` after the
    #               declaration — a reassignment, which is the round-3 case
    #               written differently; and `&&` changed to `||` in the
    #               reload guard, one character, restoring a fault round 2 had
    #               found. Three more besides.
    #
    #    **I WAS CLOSING INSTANCES AND CALLING IT THE CLASS, four times.** The
    #    remedy is not a fifth clever pattern: it is to pin the whole file and
    #    accept the cost. The file's CODE — comments stripped, whitespace
    #    normalised, LINE BREAKS KEPT — must equal
    #    `portal-translate.pinned.js` exactly, so any change to any STATEMENT
    #    fails this check on purpose and has to be read by a person.
    #
    #    **Be exact about what "any change" means, because a looser version of
    #    this sentence was untrue and round 5 proved it.** Re-indenting is
    #    free, a blank line is free, and editing comment TEXT is free — none
    #    of those changes what runs. A LINE BREAK IS NOT FREE, because in
    #    JavaScript a newline is a semicolon; collapsing newlines was round 5's
    #    bypass and `normalised_code()` carries the reason. What is left
    #    invisible is any whitespace inside a STRING LITERAL, in either
    #    direction — and `'CSRF check failed'` really does depend on its one
    #    space, so that is a real residue and not a theoretical one.
    #
    #    That is proportionate here and nowhere else in this repository, for
    #    one reason: **nothing exercises THIS script's own behaviour.** A
    #    browser test exists (`tools/csrf-carriers-selftest.mjs`, #768) and
    #    `tools/layout-audit.mjs` has driven one since 30 October, but
    #    neither runs this file's own logic, so there is no behavioural guard
    #    behind the pin, and
    #    the faults it is standing in front of were measured — a pledge form
    #    refused with the member's typing lost, a guided-tour post answering
    #    403, and a member told to reload for ever when reloading cannot help.
    #
    #    **TO CHANGE THE SCRIPT:** make the change, satisfy yourself it is
    #    right by reading it, then regenerate the pin:
    #        python3 tools/audit-checks/check_translation_text_from_db.py --repin
    #    and say in the commit message what changed and why. The regeneration
    #    is deliberately a separate, visible act.
    # 🚨 THE ONE STRING WHOSE WHITESPACE MATTERS, checked UN-normalised.
    #    The pin collapses whitespace inside string literals, which is a
    #    residue in both directions — and `'CSRF check failed'` really depends
    #    on its single space, because it is compared with the exact words
    #    `ApiAuth` sends. Measured through this gate: `'CSRF  check failed'`,
    #    a tab or a non-breaking space in that gap are all invisible to the
    #    pin, and any of them silently shows a member the raw words "CSRF
    #    check failed" on every press — which is the thing the script's own
    #    comment says it avoids.
    #
    #    This is NOT a substitute for the pin and must not grow into a list of
    #    spellings; it closes the one hole the normaliser is known to have a
    #    behavioural consequence for. If another string ever has to match
    #    something exactly, add it here and say why.
    if "said === 'CSRF check failed'" not in script_plain:
        problems.append(
            f"{SCRIPT} no longer holds the exact comparison `said === 'CSRF check failed'`. "
            f"That string is matched against the words `ApiAuth` sends, so a single "
            f"character of whitespace inside it — which the whole-file pin CANNOT see, "
            f"because it collapses whitespace in string literals — silently shows a "
            f"member the raw words \"CSRF check failed\" on every press instead of telling "
            f"them to reload. If `ApiAuth`'s wording changed, change both together."
        )

    pinned = read(root, PINNED_SCRIPT)
    if pinned is None:
        problems.append(
            f"{PINNED_SCRIPT} is not there. It is the pinned copy of the script's code, "
            f"and without it nothing guards a file that no test runs."
        )
    else:
        live = normalised_code(script)
        want = "\n".join(line for line in pinned.splitlines() if line.strip() != "")
        if live != want:
            where = next(
                (i for i in range(min(len(live), len(want))) if live[i] != want[i]),
                min(len(live), len(want)),
            )
            problems.append(
                f"{SCRIPT}'s code is not what {PINNED_SCRIPT} pins.\n"
                f"      First difference at character {where} of the normalised code:\n"
                f"        pinned: …{want[max(0, where - 60):where + 60]}…\n"
                f"        found:  …{live[max(0, where - 60):where + 60]}…\n"
                f"      ({len(want)} characters pinned, {len(live)} found.)\n"
                f"      THIS FAILS ON ANY CHANGE TO A STATEMENT, ON PURPOSE (re-indenting "
                f"and comment text are free; a LINE BREAK is not, because a newline is a "
                f"semicolon in JavaScript) — see the comment above this "
                f"check. Four rounds of narrower pins were each got past by a one-edit "
                f"change, with the token sharing dead and every other check green. If your "
                f"change is right, re-read the script and run this check with --repin."
            )

    print(
        f"Translation: {len(found_tables)} translatable table(s), "
        f"{len(callers)} engine caller(s), "
        f"{len(sorted(fields))} request field(s) read by the handler"
    )

    if len(problems) > 0:
        print("")
        for problem in problems:
            print(f"FAIL: {problem}")
        print("")
        print(
            f"{len(problems)} problem(s). The rule is #761: the words sent to an outside "
            f"company come from the database, through the row's own visibility rule, and "
            f"one place decides who may ask."
        )
        return 1

    print(
        "OK: the allow-list is as pinned, one file calls the engine, its text comes from "
        "the database, one place draws the control, and both ask the same rule."
    )
    print(
        "   This CANNOT tell whether the allow-list is RIGHT, nor see a request value "
        "reaching the text by a longer route. See the header."
    )
    return 0


def repin() -> int:
    """
    Write the live script's normalised code over the pin.

    Separate and explicit on purpose: the pin exists so that a change to a file
    nothing tests has to be looked at by a person, and a check that repinned
    itself would be no check at all.
    """
    root = repo_root()
    script = read(root, SCRIPT)
    if script is None:
        print(f"FAIL: {SCRIPT} is not there.")
        return 1
    # ⚠️ FOLDED HERE TOO. This was the ONE read of the script that did not
    #    go through `js_source()`, so the generator and the checker really
    #    could disagree about a file carrying U+2028 — the very thing
    #    `normalised_code()`'s docstring says one function exists to prevent.
    #    It failed SAFE (the gate stayed red and nothing was blessed), but the
    #    failure message says "run --repin", and on that input --repin could
    #    never make it green, with nothing saying why.
    script = js_source(script)
    refusals = js_will_not_parse(script)
    if len(refusals) > 0:
        print(
            "REFUSED — not re-pinning a script JavaScript would not parse:\n  "
            + "\n  ".join(refusals)
            + "\nFix the script first. A pin taken from a file that does not run is a pin "
            + "certifying nothing."
        )
        return 1
    normalised = normalised_code(script)
    with open(os.path.join(root, PINNED_SCRIPT), "w", encoding="utf-8") as handle:
        handle.write(normalised)
    print(
        f"Re-pinned {PINNED_SCRIPT} from {SCRIPT}: {len(normalised)} characters of code.\n"
        f"Say in the commit message WHAT changed in the script and why. **Nothing "
        f"exercises this script's own behaviour**, so this pin — plus your reading of "
        f"it — are the only guards on it. (`tools/csrf-carriers-selftest.mjs` does drive "
        f"a real browser and loads this file, but only far enough to show it does not "
        f"throw.) It DOES run in production: `footer.php` serves it to every page."
    )
    return 0


if __name__ == "__main__":
    if "--repin" in sys.argv:
        sys.exit(repin())
    sys.exit(main())
