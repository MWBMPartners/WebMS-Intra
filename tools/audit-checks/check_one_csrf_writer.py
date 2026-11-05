#!/usr/bin/env python3
"""
One place writes the anti-forgery token into the page, and it writes EVERY carrier.

Path: tools/audit-checks/check_one_csrf_writer.py

WHY THIS EXISTS
---------------
`Auth::verifyCsrf()` REPLACES the token every time it accepts one, and
`Auth::csrfToken()` is ONE token per session. So the moment any background post
is accepted, every copy of the token the page is holding goes stale together —
and a page holds it in THREE places:

  1. `<meta name="csrf-token">`, which `header.php` writes on every page.
  2. Every `input[name="csrf_token"]` — the hidden field in each ordinary form.
  3. Every `[data-csrf]` element — at present the Translate control.

**FIVE SEPARATE COPIES OF "WRITE THE REPLACEMENT BACK" EXISTED, AND NO TWO
AGREED ABOUT HOW MANY CARRIERS THERE ARE** (#768):

  * `portal.js`'s `rememberCsrf()`      — the meta tag ONLY, and it returned
                                          false when there was no meta tag even
                                          though it had found a token.
  * `event-hub-upload.js`'s `syncCsrf()` — meta tag + fields, not `[data-csrf]`.
  * `report-builder.js`'s `syncCsrf()`   — a copy of the above, same gap. Its
                                          own comment says "(event-hub-upload.js
                                          precedent)".
  * `api-docs/index.php`'s `storeToken()` — the meta tag only; its comment says
                                          it follows "the event hub upload
                                          widget".
  * `portal-translate.js`'s `shareToken()` — all three, but only for the one
                                          answer shape it knew.

**That chain of comments is the finding.** Each new page copied the NEAREST
existing version rather than a shared one, so the incomplete rule propagated and
the complete one never did. The published helper existed the whole time and said
in its own comment that callers should use it rather than writing their own
copy — it was simply the least complete of the five.

THE MEASURED COST, so nobody mistakes this for tidiness: on the Host Console,
publishing a chat prompt goes through `ApiAuth::requireWrite()` and rotates the
token; the "Send 'We're live'" push (#612) sits on the same page in an ordinary
form. Publish a prompt, then press the button, and the push is REFUSED and the
press is lost. `go-live-button-selftest.php` never caught it because it presses
the button without publishing a prompt first.

WHAT IT CHECKS
--------------
  A. `Portal.writeCsrf()` exists in portal.js and writes **all three** carriers.
     A helper that has quietly lost one is the whole fault again, one level up.
  B. `writeCsrf` is published on `window.Portal`, or no other file can reach it.
  C. **No other file writes a carrier itself.** Any file that does, and is not
     the single stated exception, fails.
  D. **The exception still earns its place.** `api-docs/index.php` writes the
     meta tag directly because it is served by the web server, draws its own
     `<head>` and loads no portal.js — so the meta tag is genuinely its ONLY
     carrier. If that page ever grows a form field or a `[data-csrf]` element,
     its one-line write silently becomes incomplete, so this check re-tests that
     condition every run rather than trusting the note.
  E. **The exception list fails BOTH ways.** A listed file that no longer writes
     a carrier at all fails too, because a stale exception is how an allow-list
     stops describing the code (the migration-harness lesson).
  F. **The delegating functions still delegate.** Checks A-E say nothing about
     whether the files that USED to write their own carriers still hand the
     work over, and three one-edit changes killed that with every other check
     green. Each function's whole body is pinned, and the two ways to override
     a function while leaving its text in place — a second declaration, and a
     reassignment — are refused by name. This is the largest and most delicate
     part of the file; see the comment above DELEGATORS for why it is not the
     whole-file pin #485 prescribes, and #773 for the stronger option.

WHAT THIS CANNOT DO, said plainly because an overstated guarantee is worse than
none
---------------------------------------------------------------------------
  * **It cannot see a carrier written through a variable selector** — a name
    built by concatenation, or a query held in a variable passed in from
    elsewhere. It matches the selectors as they are written.
  * **It cannot tell whether a caller actually CALLS the helper.** A page that
    posts, rotates the token and simply ignores the answer passes this check and
    still leaves every carrier stale. Nothing static can see that; it needs the
    browser test in `tools/csrf-carriers-selftest.mjs`.
  * **It does not know about a FOURTH carrier nobody has invented yet.** If one
    is added, this check keeps passing while being wrong — which is exactly what
    happened to the five copies above. The defence is that there is now one
    place to add it to.

Package: WebMS-Intra / audit checks
Author: Claude Opus 5 (for MWBM Partners Ltd, t/a MWservices)
Copyright: All Rights Reserved -- MWBM Partners Ltd
Version: 1.0.0
"""

from __future__ import annotations

import os
import re
import sys

WEB = "web"
HELPER = os.path.join("web", "public_html", "assets", "js", "portal.js")

# 📁 The one file allowed to write a carrier outside the helper, and WHY. The
#    reason is checked, not merely recorded — see check D.
EXCEPTIONS = {
    os.path.join("web", "public_html", "api-docs", "index.php"): (
        "Served directly by the web server, draws its own <head> and loads no "
        "portal.js, so it cannot reach Portal.writeCsrf(). Safe only while the "
        "meta tag is its one carrier, which is re-checked below."
    ),
}

# 📌 THE DELEGATING FUNCTIONS ARE PINNED WHOLE, AND A CALL-SITE PIN WOULD NOT
#    DO. Checks A-E prove that one helper writes every carrier and that nobody
#    else writes one. They say NOTHING about whether the files that used to
#    write their own still hand the work over — and the independent check
#    measured three one-edit changes that killed the delegation with this gate
#    AND the browser test both green:
#
#      `if (false && window.Portal && ...)`   → gate OK, browser test 18/18
#      the whole body of syncCsrf() deleted   → gate OK, browser test 18/18
#      one added `return;` above the call     → gate OK, browser test 18/18
#
#    The cost is real: on `/admin/reports/builder/edit`, Preview rotates the
#    token and `syncCsrf()` is what puts the replacement into the Save form.
#    With the delegation dead, Preview-then-Save is refused and the
#    administrator loses the report definition they just built.
#
#    **A pin on the CALL could not catch the third of those** — inserting
#    `return;` above it leaves the call text exactly as it was (#709: pin the
#    gate that decides whether the block is entered, not just the block). So
#    the whole function body is pinned, normalised for whitespace. These
#    functions are one line of logic each; pinning them whole costs nothing and
#    any edit to either is meant to fail here and be looked at.
#
#    `portal-translate.js` needs no entry: its whole FILE is already pinned by
#    `check_translation_text_from_db.py`, and removing its delegation turns
#    that check red (verified).
DELEGATORS = {
    os.path.join("web", "public_html", "assets", "js", "event-hub-upload.js"): (
        "syncCsrf",
        "function syncCsrf(token) { if (window.Portal && typeof "
        "window.Portal.writeCsrf === 'function') { window.Portal.writeCsrf(token); } }",
    ),
    os.path.join("web", "public_html", "assets", "js", "report-builder.js"): (
        "syncCsrf",
        "function syncCsrf(token) { if (window.Portal && typeof "
        "window.Portal.writeCsrf === 'function') { window.Portal.writeCsrf(token); } }",
    ),
}

# ── WHAT COUNTS AS WRITING EACH CARRIER ───────────────────────────────────
#
# 🚨 FIVE PATTERNS USED TO SIT HERE THAT NOTHING CALLED — `WRITES_META`,
#    `META_BY_TAG`, `META_SELECTOR`, `FIELD_SELECTOR` and `DATA_SELECTOR`,
#    each mentioned exactly once in the whole file, at its own definition.
#    Measured: rebinding all five to match NOTHING, and then to match
#    EVERYTHING, left this check's output byte-identical and green both times.
#
#    That is worse than untidiness. `FIELD_SELECTOR` carried the comment
#    "EVERY ORDINARY WAY OF NAMING THAT FIELD, because round 2 added a working
#    sixth copy using three spellings the first version could not see" — so it
#    read exactly like the list the check consults. Somebody closing a gap
#    would have added the missing spelling THERE, and the gate would not have
#    changed by one character. The banner above them described a design two
#    rewrites out of date. **Dead code under an authoritative comment is a
#    trap, not clutter.**
#
# `WRITES_DATA` NEEDS NO LOOKUP, and that asymmetry is deliberate.
# `setAttribute('data-csrf', …)` and `dataset.csrf =` NAME the carrier in the
# write itself, so there is nothing to join up. The other two write shapes
# (`.value =`, `.content =`) say nothing about WHAT is being written, which is
# the whole reason they have to be followed back to a lookup.
WRITES_DATA = re.compile(
    r"setAttribute\(\s*['\"]data-csrf['\"]"
    r"|dataset\s*\.\s*csrf\s*=(?!=)"
    r"|dataset\s*\[\s*['\"]csrf['\"]\s*\]\s*=(?!=)"
)

# ⚠️ CHECK D ASKS MARKUP ONLY, AND ORING SELECTOR SPELLINGS IN MADE IT CRY WOLF
#    WITH AN UNTRUE MESSAGE. One planted READ —
#    `function readToken(a) { return a.csrf_token || ""; }` — turned this hard
#    gate red saying the page "now has an input[name=csrf_token] field as
#    well", when it has no field at all, and told the developer to write one.
#    Not contrived: `api-docs/index.php` already reads `body.csrfToken`, and
#    `csrf_token` is the older spelling portal.js accepts from the two push
#    handlers. A check that cries wolf gets switched off (#483).
#
#    HAVING a carrier is a question about MARKUP, because growing one means
#    writing HTML. A carrier created by JavaScript rather than written as
#    markup is invisible here; that is the narrowing's accepted cost.
HAS_FIELD_MARKUP = re.compile(r"name\s*=\s*['\"]csrf_token['\"]|name\s*=\s*csrf_token\b")
HAS_DATA_MARKUP = re.compile(r"\bdata-csrf\s*=")

SUFFIXES = (".js", ".php", ".mjs", ".html", ".htm")


def repo_root() -> str:
    """The repository root, from this file's own position."""
    here = os.path.dirname(os.path.abspath(__file__))
    return os.path.dirname(os.path.dirname(here))


def blank_comments(text: str, php_like: bool = False) -> str:
    """
    Replace every comment with spaces, keeping the file's length and line count.

    STRINGS ARE KEPT, NOT MASKED, and that is deliberate: the things being
    looked for here — `meta[name="csrf-token"]`, `'data-csrf'` — ARE string
    literals, so masking strings would blind this check completely. That exact
    mistake was made in #716's guard, where the value being read was itself a
    string.

    🚨 A .php OR .html FILE HOLDS THREE LANGUAGES, AND PRETENDING OTHERWISE
       BROKE THIS TWICE IN ONE SITTING. Version 1 applied JavaScript rules
       everywhere, so `<p>See https://example.com/docs</p>` on the same physical
       line as a `<script>` blanked that script and a planted carrier write
       passed the gate. Version 2 applied HTML rules outside `<?php`, so a
       perfectly ordinary `/* ... */` inside a `<script>` block survived and its
       mention of `[data-csrf]` was read as code — crying wolf on the file's own
       explanatory comment. Both directions are wrong, so the state is tracked:

         HTML     — only `<!-- -->` is a comment; `//` is ordinary text
         PHP      — inside `<?php ... ?>`: `//`, `#` and `/* */`
         SCRIPT   — inside `<script> ... </script>`: `//` and `/* */`

       A .js or .mjs file is SCRIPT throughout. The same parsing fault is on
       record for `check_page_head_tags.py` and `check_no_outside_qr_service.py`;
       this is its third appearance, which is why the fix models the file rather
       than adding another pattern.

    ⚠️ A JAVASCRIPT `//` COMMENT ENDS AT FOUR CHARACTERS, NOT ONE: LF, CR,
       U+2028 LINE SEPARATOR and U+2029 PARAGRAPH SEPARATOR. #485 round 6: a
       blanker that ended one at `\n` alone ran straight past a U+2028 and
       blanked real code after it, with every gate still green. U+2028 is what
       text pasted out of a PDF carries, so this is an ordinary accident, not
       only an attack.
    """
    out: list[str] = []
    i = 0
    n = len(text)
    line_enders = "\n\r\u2028\u2029"
    state = "html" if php_like is True else "script"

    def blanked(chunk: str) -> str:
        """Spaces, but keep the line breaks so line numbers stay true."""
        return "".join(c if c in line_enders else " " for c in chunk)

    while i < n:
        lowered = text[i:i + 9].lower()

        if state == "html":
            if text.startswith("<?", i):
                state = "php"
                out.append(text[i])
                i += 1
                continue
            if lowered.startswith("<script"):
                state = "script"
                out.append(text[i])
                i += 1
                continue
            if text.startswith("<!--", i):
                end_at = text.find("-->", i + 4)
                end_at = n if end_at == -1 else end_at + 3
                out.append(blanked(text[i:end_at]))
                i = end_at
                continue
            out.append(text[i])
            i += 1
            continue

        if state == "php" and text.startswith("?>", i):
            state = "html"
            out.append(text[i:i + 2])
            i += 2
            continue
        if state == "script" and php_like is True and lowered.startswith("</script"):
            state = "html"
            out.append(text[i])
            i += 1
            continue

        ch = text[i]

        # A string literal — copied through untouched.
        if ch in "'\"`":
            quote = ch
            out.append(ch)
            i += 1
            while i < n:
                if text[i] == "\\" and i + 1 < n:
                    out.append(text[i:i + 2])
                    i += 2
                    continue
                out.append(text[i])
                if text[i] == quote:
                    i += 1
                    break
                i += 1
            continue

        # A line comment. `#` is PHP only — in JavaScript it is not a comment,
        # and a `#` in a script block is a fragment identifier or a private field.
        if text.startswith("//", i) or (state == "php" and ch == "#"):
            began = i
            while i < n and text[i] not in line_enders:
                i += 1
            out.append(blanked(text[began:i]))
            continue

        # A block comment. An UNTERMINATED one runs to the end of file.
        if text.startswith("/*", i):
            end_at = text.find("*/", i + 2)
            end_at = n if end_at == -1 else end_at + 2
            out.append(blanked(text[i:end_at]))
            i = end_at
            continue

        out.append(ch)
        i += 1

    return "".join(out)


def php_like(relative: str) -> bool:
    """True for files where `//` is only a comment inside `<?php ... ?>`."""
    return relative.endswith((".php", ".html", ".htm"))


def read(root: str, relative: str) -> str:
    with open(os.path.join(root, relative), "r", encoding="utf-8", errors="replace") as handle:
        return handle.read()


# ── FINDING A CARRIER, AND WRITING ONE ────────────────────────────────────
#
# 🚨 THIS IS THE FOURTH VERSION OF THESE PATTERNS AND THE FIRST THREE EACH HAD
#    A HOLE, SO THIS ONE STOPS ENUMERATING SYNTAX.
#
#    v1: "selects a field" + "has a `.value =` somewhere" — two true facts
#        about one file joined into a false one; cried wolf on a page that only
#        READ the field.
#    v2: added spellings, but nested `elements['csrf_token']` inside the
#        `querySelector(` branch — a plain precedence bug, five spellings
#        through.
#    v3: rebuilt as lookup × write, and added `.forEach(` — but only when it
#        followed the lookup IMMEDIATELY. One wrapping call defeated it, and
#        `for…of` was not handled at all: 48 of 152 combinations missed.
#
#    Each version was "verified by planting" and each planted the forms its
#    author had thought of. **Planting one form of a pattern does not test the
#    pattern** — v3's own comment said exactly that, and v3 then did it.
#
#    So this version does not try to know every way of writing a loop. It asks
#    a structural question instead: **find the statement a lookup sits in,
#    collect every NAME that statement binds, and see whether any of them is
#    written.** A wrapper, a spread, a destructure, a `for…of` or a callback
#    all bind their name inside that statement, so they are covered without
#    being named. `for (const f of Array.from(x.querySelectorAll(...)))` is
#    real house idiom here — `livechat-widget.js:204`, and 12 `for…of` uses
#    under `web/` — so this was not a contrived gap.
LOOKS_UP_FIELD = (
    r"querySelector(?:All)?\s*\([^)]*\[\s*name\s*=\s*['\"]?csrf_token[^)]*\)"
    r"|getElementsByName\s*\(\s*['\"]csrf_token['\"]\s*\)"
    r"|elements\s*\[\s*['\"]csrf_token['\"]\s*\]"
    r"|elements\s*\.\s*csrf_token"
    r"|\.\s*csrf_token\b"
)
LOOKS_UP_META = (
    r"querySelector(?:All)?\s*\([^)]*meta\[\s*name\s*=\s*['\"]?csrf-token[^)]*\)"
    r"|getElementsByTagName\s*\(\s*['\"]meta['\"]\s*\)"
)
WRITES_A_VALUE = r"(?:\.\s*value\s*=(?!=)|\.\s*setAttribute\s*\(\s*['\"]value['\"])"
WRITES_META_VALUE = (
    r"(?:\.\s*content\s*=(?!=)|\.\s*setAttribute\s*\(\s*['\"]content['\"])"
)

# Every way a statement gives a name to something: a declaration (including a
# destructured one), a `for…of` binding, a callback parameter, a bare
# assignment. Deliberately generous — a name collected here only matters if it
# is later written with one of the two write shapes above.
BINDS_A_NAME = re.compile(
    r"(?:var|let|const)\s*\[\s*(\w+)"                 # var [f] = …
    r"|(?:var|let|const)\s*\{[^}]*?(\w+)\s*[},]"      # const { 0: f } = …
    r"|(?:var|let|const)\s+(\w+)"                      # var f = …
    r"|for\s*\(\s*(?:var|let|const)\s+(\w+)\s+of"    # for (const f of …)
    r"|function\s*\*?\s*\w*\s*\(\s*(\w+)"          # function (f) { … }
    r"|\(\s*(\w+)\s*\)\s*=>"                        # (f) => …
    r"|(?:^|[^\w.])(\w+)\s*=>"                         # f => …
    r"|(?:^|[^\w.])(\w+)\s*=(?!=|>)"                   # f = …
)

# Chunks big enough to hold one step of "this name came from that one".
STATEMENT_BREAK = re.compile(r"[;\n]")

# A string literal, for NAME HARVESTING ONLY.
# ⚠️ TEMPLATE LITERALS TOO, AND LEAVING THEM OUT LEFT ONE FILE'S WALK BROKEN.
#    A backtick string may span lines and hold anything — `noticeboard.noeval.js`
#    carries a CSS template with `*{ box-sizing:border-box; }` and six
#    `@keyframes` blocks, so its braces were counted as code and one function
#    never closed. The `[\s\S]` is deliberate: unlike the other two, a template
#    literal really does cross newlines.
STRING_LITERAL = re.compile(
    r"'[^'\\\n]*(?:\\.[^'\\\n]*)*'"
    r"|\"[^\"\\\n]*(?:\\.[^\"\\\n]*)*\""
    r"|`[^`\\]*(?:\\[\s\S][^`\\]*)*`"
)


def mask_strings(text: str) -> str:
    """
    Blank the inside of every string literal, keeping length.

    ⚠️ FOR HARVESTING NAMES ONLY — never for finding a lookup, because every
       selector IS a string. Harvesting without this read the SELECTOR's own
       words as bound names: for `document.querySelector('[name=csrf_token]')`
       the "names" came out as `name`, `csrf_token` and `action`. So every file
       using the unquoted house spelling had `name` tainted, and any
       `name.value = …` in it cried wolf — and `name` is one of the commonest
       variables in form code. `web/_apps/auth/account/index.php`, the file the
       first version already cried wolf over, uses that spelling twice.
    """
    return STRING_LITERAL.sub(lambda m: m.group(0)[0] + " " * (len(m.group(0)) - 2) + m.group(0)[-1],
                              text)


def function_spans(code: str) -> list[tuple[int, int]]:
    """
    Where every function body begins and ends.

    🚨 THE BRACE WALK STEPS OVER STRING LITERALS, and not doing so left 52
       files under `web/` with at least one function the walk could never
       close — `portal.js` itself among them. One unbalanced `{` or `}` inside
       a string is enough: `noticeboard.noeval.js` carries a CSS template
       string, and `maillib`'s interfaces declare bodiless `function …;` whose
       `find("{")` runs off to an unrelated brace. Planted in that live file,
       an ordinary `fs.forEach(function (f) { f.value = …; })` passed the gate.

       **CLAUDE.md already records this exact trap** for the money pages: "a
       brace-matching walk must step over STRING LITERALS, or one `{` in a log
       message swallows the rest of the function and the check passes on a
       removed gate." `blank_comments()` in this same file steps over them
       carefully; this walk did not. It now runs on a masked copy whose indices
       line up with the original.

       A bodiless declaration has no body to find: if the next `{` lies beyond
       the `;` that ends the declaration, there is none.

       **One function in the tree still does not close**: the outer IIFE of
       `noticeboard.noeval.js`, which is GENERATED from `dc-runtime` outside
       this repository and is not portal code. It was 52 files before this
       walk learned about literals. **It fails in the SAFE direction** — a
       missing span means a lookup there falls back to the whole file, which is
       a WIDER scope, so nothing is missed and the only cost is a slightly
       greater chance of a false positive in that one file. Said here rather
       than chased, because the remedy is in a repository we do not hold.
    """
    masked = mask_strings(code)
    spans: list[tuple[int, int]] = []
    for match in re.finditer(r"\bfunction\b", masked):
        opened = masked.find("{", match.end())
        if opened == -1:
            continue
        ends = masked.find(";", match.end())
        if ends != -1 and ends < opened:
            continue
        depth, i = 0, opened
        while i < len(masked):
            if masked[i] == "{":
                depth += 1
            elif masked[i] == "}":
                depth -= 1
                if depth == 0:
                    spans.append((match.start(), i + 1))
                    break
            i += 1
    return spans


def enclosing_span(spans: list[tuple[int, int]], at: int, length: int) -> tuple[int, int]:
    """
    The SMALLEST function body containing `at`, or the whole file.

    ⚠️ THIS IS LEXICAL CONTAINMENT, AND THE PREVIOUS MODEL WAS NOT.
       v5 ran the analysis over each function body separately and over a "top
       level" with every function body BLANKED OUT. That blanking stopped a
       name read in one function standing in for an unrelated write in
       another — and it also made this invisible:

           var inputs = document.querySelectorAll('input[name="csrf_token"]');
           inputs.forEach(function (f) { f.value = token; });

       at the TOP LEVEL of a script, because the lookup was in the top-level
       view and the write sat inside a `function` body blanked out of it. The
       very idiom v5 was rewritten to catch, in ES5 house style, on any of the
       34 pages here with a non-IIFE inline script.

       Containment is the right model and fixes both at once: a callback is
       NESTED inside the scope that created it, so a write there counts; two
       sibling functions contain neither the other, so a reused name does not.
       An arrow callback has no `function` keyword and so opens no span, which
       is harmless — its body stays part of the scope that holds it.
    """
    best = (0, length)
    for begin, finish in spans:
        if begin <= at < finish and (finish - begin) < (best[1] - best[0]):
            best = (begin, finish)
    return best


def writes_a_carrier(code: str, lookup: str, write: str) -> bool:
    """
    Does this file WRITE the carrier `lookup` finds, as opposed to reading it?

    🚨 THE SIXTH VERSION. The first four enumerated syntax or statement shapes
       and each had a hole; the fifth followed the VALUE, which was right, but
       scoped it by BLANKING function bodies out of a "top level" view, which
       was not. See `enclosing_span()` for what that missed and why containment
       replaces it.

       Every earlier version was "verified by planting" the shapes its author
       had thought of. That is why the question asked here is deliberately not
       about syntax at all: a name bound from a lookup is tainted, any statement
       mentioning a tainted name taints what IT binds, and a write onto a
       tainted name is a carrier write. Caching, wrapping, spreading,
       destructuring, `for…of` and callbacks are steps in that chain rather
       than patterns to list.

    ⚠️ WHAT IT STILL CANNOT DO, said plainly because five versions each claimed
       more than they delivered. Names are matched by SPELLING within one
       scope, so a name reused for something else in the SAME function can
       report a write that is not there. An element passed into a helper
       function, assigned through an object property, or returned from a
       function is not followed. Nothing here parses JavaScript. The browser
       test, not this, is what proves behaviour.
    """
    if re.search(r"(?:" + lookup + r")(?:\s*\[[^\]]*\])?\s*" + write, code) is not None:
        return True
    # Each lookup is judged inside the smallest function that contains it — or
    # the whole file when nothing does. A write in a nested callback is inside
    # that span; a write in a sibling function is not.
    spans = function_spans(code)
    for match in re.finditer(lookup, code):
        begin, finish = enclosing_span(spans, match.start(), len(code))
        if taints_through(code[begin:finish], lookup, write) is True:
            return True
    return False



def taints_through(code: str, lookup: str, write: str) -> bool:
    """Follow the value within ONE region. See writes_a_carrier()."""
    masked = mask_strings(code)
    # Statement spans, measured on the real code so lookups are findable, and
    # read on the masked copy so selectors do not donate their words as names.
    bounds = [0]
    for match in STATEMENT_BREAK.finditer(code):
        bounds.append(match.end())
    bounds.append(len(code))
    chunks = [(bounds[i], bounds[i + 1]) for i in range(len(bounds) - 1)]

    finder = re.compile(lookup)
    tainted: set[str] = set()
    for match in finder.finditer(code):
        for begin, finish in chunks:
            if begin <= match.start() < finish:
                tainted.update(n for g in BINDS_A_NAME.findall(masked[begin:finish]) for n in g if n)

    # Follow the value: a chunk mentioning a tainted name taints what it binds.
    changed = True
    while changed is True:
        changed = False
        for begin, finish in chunks:
            piece = masked[begin:finish]
            if any(re.search(r"\b" + re.escape(name) + r"\b", piece) for name in tainted) is False:
                continue
            for group in BINDS_A_NAME.findall(piece):
                for name in group:
                    if name != "" and name not in tainted:
                        tainted.add(name)
                        changed = True

    if len(tainted) == 0:
        return False
    # ⚠️ THE WRITE IS LOOKED FOR IN THE REAL CODE, NOT THE MASKED COPY, and
    #    getting that backwards broke the whole meta half for one run: the
    #    write patterns CONTAIN string literals (`setAttribute('content', …)`,
    #    `setAttribute('value', …)`), so masking blanks the very word being
    #    matched and no write is ever found. Names come from the masked copy,
    #    writes from the real one — each read where its evidence actually is.
    written = re.compile(r"\b(\w+)\s*(?:\[[^\]]*\])?\s*" + write)
    return any(hit.group(1) in tainted for hit in written.finditer(code))


def carrier_writes(code: str) -> list[str]:
    """
    Which carriers this file writes for itself. Comments already blanked.

    ⚠️ `WRITES_DATA` NEEDS NO LOOKUP, AND THAT ASYMMETRY IS DELIBERATE rather
       than an oversight. `setAttribute('data-csrf', …)` and `dataset.csrf =`
       NAME the carrier in the write itself, so there is nothing to join up —
       and that is why a file writing both `[data-csrf]` and the fields is
       still caught even when the field half slips through. The other two
       write shapes (`.value =`, `.content =`) say nothing about WHAT is being
       written, which is the whole reason they have to be tied back to a
       lookup.
    """
    found = []
    if writes_a_carrier(code, LOOKS_UP_META, WRITES_META_VALUE) is True:
        found.append("the <meta name=\"csrf-token\"> tag")
    if writes_a_carrier(code, LOOKS_UP_FIELD, WRITES_A_VALUE) is True:
        found.append("an input[name=\"csrf_token\"] field")
    if WRITES_DATA.search(code) is not None:
        found.append("a [data-csrf] element")
    return found



def pinned_body(code: str, name: str) -> str | None:
    """The named function's whole body, whitespace normalised. None if absent."""
    start = code.find("function " + name)
    if start == -1:
        return None
    opened = code.find("{", start)
    if opened == -1:
        return None
    depth = 0
    i = opened
    while i < len(code):
        if code[i] == "{":
            depth += 1
        elif code[i] == "}":
            depth -= 1
            if depth == 0:
                return re.sub(r"\s+", " ", code[start:i + 1]).strip()
        i += 1
    return None


def main() -> int:
    root = repo_root()
    problems: list[str] = []

    # ── A + B. The helper itself. ────────────────────────────────────────────
    if os.path.isfile(os.path.join(root, HELPER)) is False:
        print(f"FAIL: {HELPER} is not there, so there is no one place to write the token.")
        return 1

    helper_code = blank_comments(read(root, HELPER), php_like(HELPER))

    if re.search(r"function\s+writeCsrf\s*\(", helper_code) is None:
        problems.append(
            f"{HELPER} has no `function writeCsrf(`. It is the ONE place the "
            f"replacement anti-forgery token is written into the page. Without it "
            f"every caller writes its own, which is how five different ideas of "
            f"'how many carriers a page has' came to exist (#768)."
        )
    else:
        wrote = carrier_writes(helper_code)
        for carrier, present in (
            ("the <meta name=\"csrf-token\"> tag", "the <meta name=\"csrf-token\"> tag" in wrote),
            ("an input[name=\"csrf_token\"] field", "an input[name=\"csrf_token\"] field" in wrote),
            ("a [data-csrf] element", "a [data-csrf] element" in wrote),
        ):
            if present is False:
                problems.append(
                    f"{HELPER}'s writeCsrf() no longer writes {carrier}. All three "
                    f"carriers go stale together the moment any post is accepted, so "
                    f"a helper missing one leaves that one holding a spent token — "
                    f"and the page looks perfectly normal until somebody presses Save."
                )

    if re.search(r"window\s*\.\s*Portal\s*\.\s*writeCsrf\s*=", helper_code) is None:
        problems.append(
            f"{HELPER} does not publish writeCsrf on window.Portal, so no other "
            f"file can reach it — and every one of them would have to write its own "
            f"copy again."
        )

    # ── C. Nobody else writes a carrier. ─────────────────────────────────────
    seen_exceptions: set[str] = set()
    for folder, _, names in os.walk(os.path.join(root, WEB)):
        for name in sorted(names):
            if name.endswith(SUFFIXES) is False:
                continue
            full = os.path.join(folder, name)
            relative = os.path.relpath(full, root)
            if relative == HELPER:
                continue
            code = blank_comments(read(root, relative), php_like(relative))
            wrote = carrier_writes(code)
            if len(wrote) == 0:
                continue
            if relative in EXCEPTIONS:
                seen_exceptions.add(relative)
                continue
            problems.append(
                f"{relative} writes {' and '.join(wrote)} itself. "
                f"`Portal.writeCsrf()` is the one place that is done (#768): it "
                f"writes all three carriers, so a copy here is a copy that will go "
                f"out of step the next time a carrier is added. Call "
                f"window.Portal.writeCsrf(token) instead — or, if this file genuinely "
                f"cannot reach it, add it to EXCEPTIONS in "
                f"{os.path.basename(__file__)} with the reason."
            )

    # ── D. The exception still earns its place. ──────────────────────────────
    for relative in sorted(EXCEPTIONS):
        if os.path.isfile(os.path.join(root, relative)) is False:
            problems.append(
                f"EXCEPTIONS names {relative}, which is not there. Remove the entry: "
                f"an allow-list that has stopped describing the code is how the "
                f"migration harness's exceptions went stale unnoticed."
            )
            continue
        if relative not in seen_exceptions:
            problems.append(
                f"{relative} is listed in EXCEPTIONS but no longer writes a token "
                f"carrier at all. Remove the entry — a permission nothing uses reads "
                f"as though the fault were still there, and the next person to touch "
                f"that file will think writing a carrier by hand is allowed."
            )
            continue
        code = blank_comments(read(root, relative), php_like(relative))
        # The reason this file is allowed to write the meta tag by hand is that
        # the meta tag is its ONLY carrier. Re-test that, rather than trusting
        # the sentence recording it.
        if HAS_FIELD_MARKUP.search(code) is not None:
            problems.append(
                f"{relative} is the stated exception to the one-writer rule ONLY "
                f"because the meta tag is its one carrier — and it now has an "
                f"input[name=\"csrf_token\"] field as well. Its one-line write is "
                f"therefore incomplete, and silently so: the form will be refused "
                f"after any accepted post. Load portal.js there and call "
                f"window.Portal.writeCsrf(), or write the field too."
            )
        if HAS_DATA_MARKUP.search(code) is not None:
            problems.append(
                f"{relative} is the stated exception ONLY because the meta tag is "
                f"its one carrier — and it now has a [data-csrf] element as well, "
                f"which its one-line write does not touch."
            )

    # ── F. The delegating files still delegate. ──────────────────────────────
    for relative in sorted(DELEGATORS):
        name, expected = DELEGATORS[relative]
        if os.path.isfile(os.path.join(root, relative)) is False:
            problems.append(f"DELEGATORS names {relative}, which is not there.")
            continue
        code = blank_comments(read(root, relative), php_like(relative))

        # 🚨 ONE DECLARATION, AND NO REASSIGNMENT. PINNING THE BODY ALONE WAS
        #    THE WEAKER FORM OF A GUARD THIS REPOSITORY HAD ALREADY RECORDED AS
        #    DEFEATED, and round 2 defeated it the same two ways.
        #
        #    `pinned_body()` takes the FIRST `function <name>`, so anything that
        #    leaves that declaration alone and overrides it later passed:
        #
        #      a second `function syncCsrf(token) { return; }` after it — in
        #      JavaScript the LATER declaration wins (measured:
        #      `function f(){return "FIRST";} function f(){return;} f()` is
        #      undefined), so the pinned body never runs;
        #
        #      `syncCsrf = function (token) { return; };` after it.
        #
        #    Both left this gate, all 62 audit scripts and the browser test
        #    green. **#485 records these exact two spellings** — "a reassignment
        #    killed the whole token fix with the gate green, because round 3 had
        #    closed the second-DECLARATION spelling" — and its remedy was to pin
        #    the WHOLE FILE. I wrote #709's "pin the gate, not the block" into
        #    the comment above and then shipped #485's beaten pattern underneath
        #    it.
        #
        #    **Why not the whole-file pin here, said plainly.** `portal-translate.js`
        #    is 220 lines, single-purpose, and executed by nothing, so pinning it
        #    whole costs nothing and is right. These two are large feature files
        #    that change for unrelated reasons every time somebody touches
        #    uploads or the report builder; a whole-file pin would go red on
        #    every such edit and be switched off (#483). So the body is pinned
        #    AND the two override routes are closed by name. **That is weaker
        #    than a whole-file pin and this comment is where that trade is
        #    recorded** — see #773 for the stronger option, which is to make the
        #    browser test actually call these functions.
        declarations = len(re.findall(r"\bfunction\s+" + re.escape(name) + r"\s*\(", code))
        if declarations != 1:
            problems.append(
                f"{relative} declares `function {name}(` {declarations} time(s), not once. "
                f"In JavaScript a LATER declaration of the same name WINS, so a second one "
                f"makes the pinned body dead code while the pin still matches. Measured: "
                f"`function f(){{return 'FIRST';}} function f(){{return;}} f()` answers "
                f"undefined."
            )
        reassigned = re.search(
            r"(?<![.\w])" + re.escape(name) + r"\s*=(?!=)", code
        )
        if reassigned is not None:
            problems.append(
                f"{relative} assigns to `{name}` somewhere. A reassignment after the "
                f"declaration replaces the function while the pinned body is still in the "
                f"file, word for word — the second of the two spellings #485 records as "
                f"beating a pin of this kind."
            )

        found = pinned_body(code, name)
        if found is None:
            problems.append(
                f"{relative} no longer has a `function {name}(`. It is pinned here "
                f"because checks A-E cannot see whether this file still hands the work "
                f"to Portal.writeCsrf() — three one-edit changes killed the delegation "
                f"with every other check green."
            )
            continue
        if found is not None and found != expected:
            problems.append(
                f"{relative}'s {name}() is not what is pinned in "
                f"{os.path.basename(__file__)}.\n"
                f"       pinned: {expected}\n"
                f"       found:  {found}\n"
                f"    If the change is deliberate, update DELEGATORS in the same commit "
                f"and say why. This function exists only to hand the token to the one "
                f"helper; anything else in it is either a second copy of the rule or a "
                f"way out of calling it. On /admin/reports/builder/edit a dead "
                f"delegation means Preview-then-Save is refused and the administrator "
                f"loses the report they just built."
            )

    print(
        f"One CSRF token writer: {HELPER} writes "
        f"{len(carrier_writes(helper_code))} of 3 carriers; "
        f"{len(EXCEPTIONS)} stated exception(s), {len(seen_exceptions)} of them still "
        f"writing a carrier; {len(DELEGATORS)} delegating function(s) pinned"
    )

    if len(problems) > 0:
        print("")
        for problem in problems:
            print(f"FAIL: {problem}")
        print("")
        print(
            f"{len(problems)} problem(s). A carrier left holding a spent token loses "
            f"somebody's work at the moment they press Save, and the page gives no "
            f"sign until then."
        )
        return 1

    print("OK: one helper writes every carrier, and the one exception still earns its place.")
    print(
        "   This CANNOT tell whether a caller actually calls the helper, nor see a "
        "carrier written through a variable selector. The browser test is what proves "
        "the behaviour."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
