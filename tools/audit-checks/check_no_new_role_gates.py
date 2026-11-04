#!/usr/bin/env python3
# The module docstring is a RAW string: it quotes PHP such as
# `use Portal\Core\Capabilities;`, and Python 3.12 warns that "\C" is not a
# valid escape — a warning printed on every run of a hard gate is noise that
# teaches people to ignore its output.
r"""
No new hard-coded role gate (#739, #727). HARD GATE.

Gating a feature on a role key written into the PHP is the thing #727 and #739
exist to end. The owner settled it on 3 November 2026: "all remaining features
pointable as gated features, all standard roles' gates ... **This should also
be the mechanism going forward for all new features**, giving flexibility and
security." A customer on shared hosting cannot edit PHP, so a role they
created could record that somebody holds a responsibility and could never open
a door.

#739 converted the last of them. This check is what stops that being undone
one feature at a time — which is the way it would be undone, because each
individual `App::hasRole('something')` looks reasonable on its own and matches
whatever the file around it used to do.

WHAT IT CHECKS, and both halves FAIL, which is the point
--------------------------------------------------------
  A  NO UNLISTED GATE. Every `App::hasRole()` call under `web/` must be named
     in `HAS_ROLE_REMAINING` below, with its line, how it passes its key, and
     a reason. A new one — in a new file or in a file already listed — is a
     finding. It is counted PER FILE, so a second call in an already-listed
     file fails too (#727's lesson: `asset_manager` alone had 37 calls spread
     over 36 files, and a per-file "does it appear at all" test would have
     passed on a 38th).
  B  NO STALE EXCEPTION. A listed file that no longer makes the call, or has
     stopped making it the way the entry says, is also a finding. A stale
     exception makes a check look stronger than it is, which is how the
     migration harness's exception list went wrong unnoticed (#641).
  C  A VARIABLE KEY STAYS VARIABLE. Two of the three remaining calls are
     listed because they take their key from a row a CUSTOMER filled in, so
     they are not page gates at all and cannot be capabilities. If either ever
     passes a fixed key, the reason for its exemption has gone and the entry
     must be re-decided rather than inherited.
  D  A LITERAL KEY STAYS THE SAME KEY. The one remaining literal is pinned to
     its exact key, so the entry cannot come to cover a different gate than
     the one it was written about.
  E  THE OTHER WAY ROUND IS PINNED TOO. `Roles::has()` and
     `Roles::hasAnyOf()` answer the same question one level down, so writing
     one of those instead of `App::hasRole()` would walk straight past checks
     A to D. Every call to either is named in `ROLES_HAS_REMAINING`, and that
     list fails both ways as well. Without this half the whole check is one
     search-and-replace away from being decorative.

HOW IT READS THE PHP, and why not with `grep`
---------------------------------------------
`grep` counts every mention in a comment or a docblock, and CONVERTING a gate
LEAVES A COMMENT BEHIND naming the gate it replaced — so the commonest case
this check meets is a comment, not a call. On the working tree of 4 November
2026 `grep -rhoE "App::hasRole\(" web --include='*.php' | wc -l` answers 73
where there are 3 calls. A check that went red on those 70 comments would be
switched off within a day (#483), and switching it off is the only outcome
that matters.

So: comments are blanked, every string literal's contents are replaced by a
run of `x` of the same length, and the call is looked for in THAT text — which
also means a `App::hasRole(` written inside a string cannot be counted as a
call. The key is then read back out of the ORIGINAL text at the same offset,
which is why the masking preserves length exactly. The source is WALKED rather
than matched with a regular expression, because a `//` inside a string (any
URL has one) once ate the rest of a line, and an apostrophe inside a comment
("don't") once put every later quote out of step. Both are faults this
repository has already paid for (#716, #706).

WHY THIS IS PURE PYTHON AND DOES NOT SHELL OUT TO `php`
-------------------------------------------------------
`tools/role-gate-count.php` already counts these calls with PHP's own
tokeniser, which is the honest way to count them, and this check could have
run it. It deliberately does not, for three reasons:

  1. Everything in this folder must run offline with no dependencies, because
     `.githooks/pre-push` globs the folder and runs every script in about nine
     seconds. A step that needs a `php` binary on the PATH is a step that can
     REFUSE, and a check that cannot run must never look like a pass — so the
     usual outcome of a missing binary would be a red push for a reason that
     has nothing to do with the code.
  2. The masking walk here is the same code the sibling check
     `check_capability_registry.py` already uses for exactly this question
     (its check M reads `App::hasRole(` out of masked source), so this is the
     proven path in this repository rather than a new one.
  3. `role-gate-count.php` is deliberately NOT a gate — it exits 0 whatever it
     finds, because nothing should fail a push on a count. Wrapping it would
     mean parsing its printed output, and a check whose verdict comes from
     reading another program's prose is a check with a second way to be wrong.

The cost, said plainly: this check matches the three tokens `App` `::`
`hasRole`, so it is blind to the same things `role-gate-count.php` is blind to
— see below. Run that script for the figures; cite the command, never a number.

WHAT IT CANNOT SEE, measured rather than guessed
------------------------------------------------
  - A call through a CLASS ALIAS (`use Portal\Core\App as Gate;
    Gate::hasRole()`) or a variable class name (`$cls::hasRole()`). Measured
    by planting both: the alias form gets past, the variable form gets past.
    **A fully qualified `\Portal\Core\App::hasRole()` IS caught** — the
    pattern is `\bApp\s*::\s*hasRole` and a backslash is a word boundary, so
    it matches. An earlier version of this list claimed it was a blind spot,
    which was untrue in the MODEST direction: it still misled the next reader
    about what is covered, and that is why it is corrected rather than left as
    harmless caution.
  - A key built by CONCATENATION. Check C asks whether a listed run-time call
    has started passing a fixed key, and `App::hasRole('tour' . '_role')`
    gets past it — measured. So the guarantee check C states is about a
    literal or a constant, not about every fixed key.
  - A gate written as SQL. **25** files under `web/` name `tblUserRoles`
    (`grep -rln tblUserRoles web --include='*.php' | wc -l`); two of those
    name only the DIFFERENT table `tblUserRolesUnplaced`, so 23 name this one,
    and in two of the 23 the mention is prose in a help page or a comment
    rather than a query. **An earlier version of this paragraph said 22 and
    gave a breakdown that summed to 21**, leaving four files in no category at
    all — including `web/_core/Workflow.php`, the one file where role-based
    assignment decisions really do live. The numbers are re-measured above;
    the breakdown is deliberately not restated, because a hand-made
    categorisation of 23 files is the thing that went stale.
    **The conclusion survives, and was re-checked by reading thirteen of
    them independently: not one decides whether the person signed in right
    now may do something.** They build an audience of OTHER people (who to
    e-mail, offer or assign), run an erasure or a data export, write or seed
    rows, display somebody their own roles, or are `Roles` and `Capabilities`
    themselves. If a gate is ever written that way this check will not see it,
    and neither will any other check here.
  - Whether a capability is the RIGHT one for a gate. `App::can()` with a
    plausible but wrong key passes everything here.
  - Whether a listed reason is TRUE. The check holds somebody to their
    reason's SHAPE — the file, the line, the way the key is passed — and
    nothing judges the sentence.
  - JavaScript. Nothing in the browser may decide a gate anyway, but if
    something did, this would not find it.

Exit code:
  0 — nothing wrong
  1 — at least one finding (blocking: this is a hard gate)
  2 — the check could not run (the `web` folder is missing). A check that
      cannot run must never look like a pass.

Usage:
  python3 tools/audit-checks/check_no_new_role_gates.py
  python3 tools/audit-checks/check_no_new_role_gates.py --web <dir>
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

# -----------------------------------------------------------------------------
# The exceptions, each one a decision on record
# -----------------------------------------------------------------------------
# Every remaining `App::hasRole()` call under `web/`, keyed by its path
# relative to the repository root.
#
# Each entry declares:
#   calls  — one per call in that file, in file order. Each is a dict with
#              line  : the line it is on, so a reader can go straight to it.
#                      Checked loosely (see LINE_DRIFT) because an edit above
#                      it moves the line without changing anything that
#                      matters.
#              key   : the exact literal key, for a call that passes one; or
#                      None when the key is worked out at run time. This is
#                      what makes checks C and D fail: a listed variable call
#                      that starts passing a literal, and a listed literal
#                      call whose key changes, are both findings.
#              why   : why this one is not a capability, in plain words.
#
# ⚠️ ADDING AN ENTRY HERE IS NOT HOW A NEW GATE IS WRITTEN. The mechanism going
#    forward is a capability: declare one in `Portal\Core\Capabilities` and ask
#    it with `App::can()`. An entry here says "this cannot be a capability, and
#    here is why", and the only two reasons on record are a key that comes from
#    a customer's own data and a gate that is provably a no-op.
HAS_ROLE_REMAINING: dict[str, dict] = {
    "web/_apps/tours/api/active.php": {
        "calls": [
            {
                "line": 97,
                "key": None,
                "why": "The key comes from a guided tour's own `forRoles` column — a "
                "comma-separated list a customer typed on the tour editor — so this walks "
                "whatever role names that row holds. It is not a gate on a feature at all: "
                "it decides whether to OFFER one organisation's own tour to one member, and "
                "the thing being pointed at a role is already the customer's to choose. A "
                "capability cannot express it, because there is no fixed door to name.",
            }
        ]
    },
    "web/_core/ReportRegistry.php": {
        "calls": [
            {
                "line": 475,
                "key": None,
                "why": "The key comes from a report definition's own list of gate names, so "
                "this walks whatever that saved row holds. Same shape as the guided tour "
                "above: the report and its gates are the customer's own data, so there is no "
                "fixed door for a capability to name. (The two special gate words "
                "`@siteAdmin` and `@rootAdmin` are handled separately and are not role keys.)",
            }
        ]
    },
    "web/_core/Photos.php": {
        "calls": [
            {
                "line": 151,
                "key": "volunteer",
                "why": "A NO-OP, and that is the whole reason it is still here. "
                "`Photos::userTierRank()` is a ladder — administrator 3, the `staff` "
                "capability 2, this rung 1 — and the line after it returns 1 for EVERY "
                "signed-in member regardless, deliberately, 'to keep portals where role "
                "rollout is partial functional'. So holding the `volunteer` role changes "
                "nothing for anybody, and has not since `8625b9e`. Making it a capability "
                "would put a switch on the Roles page that cannot change what any member "
                "sees, which is #671's 'a seeded setting that nothing reads is a lie with a "
                "Save button'. Whether an ordinary member should reach the volunteer tier at "
                "all is a product question; it has to be settled before this becomes a "
                "capability, and converting the gate would hide it.",
            }
        ]
    },
}

# Every remaining `Roles::has()` / `Roles::hasAnyOf()` call under `web/`, same
# shape. This half exists because `Roles::has()` answers the same question one
# level down, so writing one of these instead of `App::hasRole()` would walk
# straight past the list above.
#
# `method` is pinned as well as the file, because `has()` asks about ONE key
# and `hasAnyOf()` about a set: a file swapping one for the other has changed
# what it does.
ROLES_HAS_REMAINING: dict[str, dict] = {
    "web/_core/App.php": {
        "calls": [
            {
                "line": 467,
                "method": "has",
                "why": "This IS `App::hasRole()`'s own body — the one implementation every "
                "hard-coded gate used to reach. It cannot be a capability without asking "
                "itself.",
            }
        ]
    },
    "web/_core/Capabilities.php": {
        "calls": [
            {
                "line": 1380,
                "method": "hasAnyOf",
                "why": "This IS `Capabilities::allows()`'s own body — the one place a "
                "capability's roles are turned into an answer about a person. Every "
                "`App::can()` call in the portal ends here.",
            }
        ]
    },
    "web/_core/Gatekeeper.php": {
        "calls": [
            {
                "line": 429,
                "method": "hasAnywhere",
                "why": "Asks whether somebody holds a role in ANY organisation, deliberately: "
                "this decides whether a person is offered the organisation switcher at all, "
                "which is a question about them across the whole installation rather than a "
                "door inside one organisation. A capability is scoped to the open "
                "organisation (`Capabilities::allows()` takes a `$siteId`), so it cannot "
                "express this. Its own docblock explains why it delegates here.",
            }
        ]
    },
    "web/_core/Workflow.php": {
        "calls": [
            {
                "line": 538,
                "method": "keysHeldBy",
                "why": "Reads the LIST of role keys a person holds, to match against a "
                "workflow step's own `assigneeValue` column — a row a customer filled in. It "
                "answers 'which of your roles apply here', not 'may you open this', so there "
                "is no fixed door for a capability to name. Same reason as the `has()` call "
                "below it.",
            },
            {
                "line": 1021,
                "method": "has",
                "why": "A workflow step's `assigneeType = 'role'` names the role in its own "
                "`assigneeValue` column — a row a customer filled in on the workflow editor "
                "— so this asks about whatever that row holds. It decides whether one person "
                "may act on one step, not whether a feature is open, so there is no fixed "
                "door for a capability to name. (It was a bare portal-wide join until #516; "
                "`Roles::has()` is what scopes it to the right organisation.)",
            }
        ]
    },
}

# How far a listed line may have drifted before the entry is treated as
# pointing somewhere else. A line number is for a READER's benefit — an edit
# anywhere above a call moves it without changing anything this check is about
# — so a drift is reported and is NOT a finding on its own. The pins that
# really bite are the file, the count, and how the key is passed.
LINE_DRIFT = 40

findings: list[str] = []
notes: list[str] = []
# Files that vanished between the glob and the read. A note, never a finding —
# see the comment in find_calls() for why the two read failures differ.
skipped: list[str] = []


def fail(message: str) -> None:
    findings.append(message)


def note(message: str) -> None:
    notes.append(message)


def refuse(message: str) -> None:
    print("REFUSED: " + message)
    print("Nothing was checked.")
    sys.exit(2)


def mask_php(source: str) -> str:
    """
    The same text, with comments blanked and every string literal's contents
    replaced by `x` — SAME LENGTH, so every offset still lines up with the
    original, and with newlines kept so reported line numbers stay true
    (deleting them once put reported line numbers 370 out, #716).

    ⚠️ MASKING THE STRINGS IS WHY THIS WORKS AND IS ALSO WHY THE KEY IS READ
       OUT OF THE ORIGINAL. The thing being looked for is a CALL, and a
       `App::hasRole(` inside a string or a comment is not one — masking is
       what makes those two cases disappear. But the key itself IS a string
       literal, so reading it from the masked text would give
       `App::hasRole('xxxxx')`. Exactly that mistake broke both halves of
       #716's guard. Find the call in the masked text; read its argument from
       the original at the same offset.

    The source is WALKED rather than matched with a regular expression,
    because a `//` inside a string (any URL has one) ate the rest of the line,
    and an apostrophe inside a comment put every later quote out of step.
    """
    out: list[str] = []
    i = 0
    n = len(source)
    while i < n:
        ch = source[i]
        nxt = source[i + 1] if i + 1 < n else ""
        # A line comment. `#[` is an attribute, not a comment.
        if (ch == "/" and nxt == "/") or (ch == "#" and nxt != "["):
            while i < n and source[i] != "\n":
                out.append(" ")
                i += 1
            continue
        if ch == "/" and nxt == "*":
            while i < n and not (source[i] == "*" and i + 1 < n and source[i + 1] == "/"):
                out.append("\n" if source[i] == "\n" else " ")
                i += 1
            # The closing `*/` itself, or whatever is left if the file ends
            # inside the comment.
            out.append("  " if i < n else "")
            i += 2
            continue
        # 📜 A HEREDOC OR NOWDOC. This branch was MISSING, and its absence is
        #    the exact fault this function's own docstring claims to have
        #    closed. Measured by an independent check: a file holding
        #    `<<<'HTML'` … `<p>Don't change this</p>` … `HTML;` followed by a
        #    real `App::hasRole('new_role')` made this check exit 0 with
        #    "Nothing wrong" — the apostrophe in "Don't" opened a bogus string
        #    literal that swallowed the whole call.
        #
        #    It was LATENT rather than live: the one nowdoc in the repository
        #    today (`web/_core/AssetRegister.php`) happens to hold an EVEN
        #    number of apostrophes, so nothing was hidden. It would have fired
        #    the first time anybody wrote ordinary English prose in a heredoc.
        #
        #    The body is masked like any other string literal, and the opening
        #    and closing labels are kept, so offsets and line numbers hold.
        if ch == "<" and source[i:i + 3] == "<<<":
            opener = re.match(r'<<<[ \t]*(?:"([A-Za-z_]\w*)"|\'([A-Za-z_]\w*)\'|([A-Za-z_]\w*))\r?\n', source[i:])
            if opener is not None:
                label = opener.group(1) or opener.group(2) or opener.group(3)
                out.append(opener.group(0))
                i += opener.end()
                # The body runs to a line whose first non-blank text is the
                # label. PHP 7.3+ allows it to be indented, so the search is
                # for the label at the start of a line after optional spaces.
                closer = re.compile(r"^[ \t]*" + re.escape(label) + r"\b", re.M)
                found = closer.search(source, i)
                stop = found.start() if found is not None else n
                for position in range(i, stop):
                    out.append("\n" if source[position] == "\n" else "x")
                i = stop
                continue
            # `<<<` that is not a heredoc opener: fall through as ordinary text.

        if ch in ("'", '"'):
            quote = ch
            out.append(quote)
            i += 1
            while i < n:
                if source[i] == "\\" and i + 1 < n:
                    out.append("xx")
                    i += 2
                    continue
                if source[i] == quote:
                    out.append(quote)
                    i += 1
                    break
                out.append("\n" if source[i] == "\n" else "x")
                i += 1
            continue
        out.append(ch)
        i += 1
    return "".join(out)


# `App::hasRole(` and `Roles::has(` / `Roles::hasAnyOf(`, as the three tokens
# each really is, with whitespace allowed between them so a reformatted call is
# still found. Matched against MASKED text, so a mention in a comment or inside
# a string is already gone.
HAS_ROLE_CALL = re.compile(r"\bApp\s*::\s*hasRole\s*\(")
# 🔎 ALL FOUR METHODS ON `Roles` THAT ANSWER A ROLE QUESTION, not two.
#
#    `hasAnywhere()` and `keysHeldBy()` were missing, and an independent check
#    got a gate past this file with each of them — a new file gating on
#    `Roles::hasAnywhere($db, $u, ['new_role'])`, and another on
#    `in_array('new_role', Roles::keysHeldBy(...), true)`. Both GREEN.
#
#    **Neither shape is hypothetical: both are live in this repository today**
#    — `web/_core/Gatekeeper.php` uses `hasAnywhere()` and
#    `web/_core/Workflow.php` uses `keysHeldBy()`. The argument this file
#    already makes for covering `has()` and `hasAnyOf()` ("without this half
#    the whole check is one search-and-replace away from being decorative")
#    applies word for word to the other two methods on the same class, and
#    leaving them out meant the check could be walked round by changing one
#    method name.
#
#    `keysHeldBy()` returns a LIST rather than an answer, so a gate built on it
#    is `in_array(...)` or similar. This check cannot judge that shape; what it
#    can do is insist every call is named with its reason, which is the same
#    bargain it strikes for the others.
ROLES_CALL = re.compile(r"\bRoles\s*::\s*(has|hasAnyOf|hasAnywhere|keysHeldBy)\s*\(")

# How the first argument was written. Read from the ORIGINAL source.
#   a single-quoted or double-quoted literal              -> that key
#   `self::NAME` / `static::NAME` / `ClassName::NAME`     -> a fixed constant
#   anything else                                         -> worked out at run time
ARG_LITERAL = re.compile(r"^\s*(?:'([^']*)'|\"([^\"]*)\")\s*[,)]")
ARG_CONSTANT = re.compile(r"^\s*(?:self|static|[A-Z][A-Za-z0-9_]*)\s*::\s*([A-Z][A-Z0-9_]*)\s*[,)]")


def how_the_key_is_passed(original: str, after_open_paren: int) -> tuple[str, str | None]:
    """
    ('literal', key) | ('constant', CONST_NAME) | ('runtime', None) for the
    first argument of a call whose opening bracket ends at `after_open_paren`.

    A CONSTANT is reported separately from a literal on purpose. It is a fixed
    key written once, which is convertible — `ExpenseRules::EXPORT_ROLE` and
    `Giving::ROLE_KEY` both were, in #739 — so it must not be waved through as
    if it were a key worked out at run time. That distinction is the one an
    earlier version of this repository's own notes got wrong, calling four
    non-literal calls "variable" when two were constants.
    """
    tail = original[after_open_paren : after_open_paren + 200]
    match = ARG_LITERAL.match(tail)
    if match is not None:
        return "literal", match.group(1) if match.group(1) is not None else match.group(2)
    match = ARG_CONSTANT.match(tail)
    if match is not None:
        return "constant", match.group(1)
    return "runtime", None


def relative(php: Path, root: Path) -> str:
    """The path as this repository writes it, so a message reads like every other."""
    try:
        return php.relative_to(root).as_posix()
    except ValueError:
        return php.as_posix()


def line_of(source: str, offset: int) -> int:
    return source.count("\n", 0, offset) + 1


def find_calls(web_dir: Path, path_root_dir: Path) -> tuple[dict[str, list[dict]], dict[str, list[dict]]]:
    """
    Every `App::hasRole()` and every `Roles::has()`/`hasAnyOf()` call under
    `web/`, keyed by path relative to the repository root, in file order.
    """
    has_role: dict[str, list[dict]] = {}
    roles: dict[str, list[dict]] = {}
    for php in sorted(web_dir.rglob("*.php")):
        # ⚠️ A FILE CAN VANISH BETWEEN THE GLOB AND THE READ, and the two
        #    reasons a read fails are answered DIFFERENTLY because they mean
        #    different things. This is not hypothetical: on 4 November 2026 the
        #    sibling check `check_capability_registry.py` died with a Python
        #    traceback during a push, because another process added and removed
        #    a file in the tree while the pre-push hook was globbing this
        #    folder. A traceback in a hook's output reads as the HOOK being
        #    broken; nobody can act on it, and the honest answer is nowhere in
        #    it. A concurrent `git checkout`, an editor's atomic save (write a
        #    temporary file, rename over the target) or another tool working in
        #    the tree all do the same thing.
        #      - GONE: a note, never a finding. A file that is not there cannot
        #        hold a gate, and failing on a race is crying wolf — which gets
        #        a check switched off (#483).
        #      - THERE BUT UNREADABLE: a FINDING. A file this check cannot read
        #        is a file it cannot vouch for, and a check that cannot run
        #        must never look like a pass.
        try:
            original = php.read_text(encoding="utf-8", errors="replace")
        except FileNotFoundError:
            skipped.append(relative(php, path_root_dir))
            continue
        except OSError as problem:
            fail(
                relative(php, path_root_dir)
                + " could not be read (" + str(problem) + "), so this check cannot say whether "
                "it holds a hard-coded role gate. A file that cannot be read must never pass "
                "quietly."
            )
            continue
        masked = mask_php(original)
        try:
            rel = php.relative_to(path_root_dir).as_posix()
        except ValueError:
            rel = php.as_posix()
        for match in HAS_ROLE_CALL.finditer(masked):
            kind, value = how_the_key_is_passed(original, match.end())
            has_role.setdefault(rel, []).append(
                {"line": line_of(masked, match.start()), "kind": kind, "value": value}
            )
        for match in ROLES_CALL.finditer(masked):
            roles.setdefault(rel, []).append(
                {"line": line_of(masked, match.start()), "method": match.group(1)}
            )
    return has_role, roles


# -----------------------------------------------------------------------------
# A, B, C, D — the `App::hasRole()` half
# -----------------------------------------------------------------------------
def check_has_role(found: dict[str, list[dict]]) -> None:
    for path in sorted(set(found) | set(HAS_ROLE_REMAINING)):
        actual = found.get(path, [])
        entry = HAS_ROLE_REMAINING.get(path)

        if entry is None:
            # A — an unlisted file.
            for call in actual:
                fail(
                    path
                    + ":"
                    + str(call["line"])
                    + " calls App::hasRole() and is in no exception list. Gating a feature on a "
                    "role key written into the PHP is what #727 and #739 ended: a customer on "
                    "shared hosting cannot edit PHP, so a role they created could never open the "
                    "door. Declare a capability in Portal\\Core\\Capabilities and ask it with "
                    "App::can(), and add the file to that capability's askedAt list. If it "
                    "genuinely cannot be a capability, add it to HAS_ROLE_REMAINING in this file "
                    "with the reason — and the only two reasons on record are a key that comes "
                    "from a customer's own data and a gate that is provably a no-op."
                )
            continue

        expected = entry["calls"]

        if len(actual) == 0:
            # B — a stale exception.
            fail(
                path
                + " is named in HAS_ROLE_REMAINING and makes no App::hasRole() call at all. If "
                "the gate was converted, DELETE the entry: a stale exception makes this check "
                "look stronger than it is (#641), and the next reader will believe a gate is "
                "still there that is not. Reason on record: " + expected[0]["why"]
            )
            continue

        if len(actual) != len(expected):
            # A and B at once: the count per file is pinned, so a SECOND call
            # in an already-listed file is a finding too.
            fail(
                path
                + " makes "
                + str(len(actual))
                + " App::hasRole() call(s) and HAS_ROLE_REMAINING names "
                + str(len(expected))
                + ". The count is pinned per file on purpose: #727 found one role key with 37 "
                "calls across 36 files, so a check that only asked whether a listed file calls "
                "it AT ALL would pass on a brand-new gate added beside an exempt one. Lines "
                "found: " + ", ".join(str(call["line"]) for call in actual)
            )
            continue

        for call, want in zip(actual, expected):
            where = path + ":" + str(call["line"])

            # C and D — the way the key is passed is pinned.
            if want["key"] is None:
                if call["kind"] != "runtime":
                    fail(
                        where
                        + " is listed as taking its role key at RUN TIME, and it now passes a "
                        + (
                            "literal key ('" + str(call["value"]) + "')"
                            if call["kind"] == "literal"
                            else "fixed constant (" + str(call["value"]) + ")"
                        )
                        + ". The reason for its exemption was that the key comes from a row a "
                        "customer filled in, so there was no fixed door to name — a fixed key "
                        "means that reason has gone. A constant counts: it is a key written "
                        "once, which is exactly what #739 converted in ExpenseRules and Giving. "
                        "Re-decide the gate rather than inheriting the exemption. Reason on "
                        "record: " + want["why"]
                    )
            else:
                if call["kind"] != "literal" or call["value"] != want["key"]:
                    fail(
                        where
                        + " is listed as passing the literal key '"
                        + str(want["key"])
                        + "' and now passes "
                        + (
                            "'" + str(call["value"]) + "'"
                            if call["kind"] == "literal"
                            else "a " + call["kind"] + " (" + str(call["value"]) + ")"
                        )
                        + ". The exemption was written about ONE gate, so it may not come to "
                        "cover a different one. Reason on record: " + want["why"]
                    )

            if abs(call["line"] - int(want["line"])) > LINE_DRIFT:
                note(
                    where
                    + " — HAS_ROLE_REMAINING says line "
                    + str(want["line"])
                    + ". Not a finding (a line number is for a reader, and an edit above a call "
                    "moves it), but worth putting right so the entry still points at the call."
                )


# -----------------------------------------------------------------------------
# E — the `Roles::has()` half, which is the way round the check above
# -----------------------------------------------------------------------------
def check_roles_has(found: dict[str, list[dict]]) -> None:
    for path in sorted(set(found) | set(ROLES_HAS_REMAINING)):
        actual = found.get(path, [])
        entry = ROLES_HAS_REMAINING.get(path)

        if entry is None:
            for call in actual:
                fail(
                    path
                    + ":"
                    + str(call["line"])
                    + " calls Roles::"
                    + call["method"]
                    + "() and is in no exception list. That answers the same question as "
                    "App::hasRole() one level down, so writing it here is how the rule above "
                    "gets walked past without a single App::hasRole() appearing anywhere. Use a "
                    "capability (Portal\\Core\\Capabilities plus App::can()), or add the file to "
                    "ROLES_HAS_REMAINING in this file with the reason."
                )
            continue

        expected = entry["calls"]

        if len(actual) == 0:
            fail(
                path
                + " is named in ROLES_HAS_REMAINING and makes no Roles::has()/hasAnyOf() call at "
                "all. Delete the entry rather than leaving it: a stale exception makes this "
                "check look stronger than it is (#641). Reason on record: " + expected[0]["why"]
            )
            continue

        if len(actual) != len(expected):
            fail(
                path
                + " makes "
                + str(len(actual))
                + " Roles::has()/hasAnyOf() call(s) and ROLES_HAS_REMAINING names "
                + str(len(expected))
                + ". Lines found: "
                + ", ".join(str(call["line"]) for call in actual)
            )
            continue

        for call, want in zip(actual, expected):
            where = path + ":" + str(call["line"])
            if call["method"] != want["method"]:
                fail(
                    where
                    + " is listed as calling Roles::"
                    + str(want["method"])
                    + "() and now calls Roles::"
                    + call["method"]
                    + "(). The method is pinned as well as the file, because has() asks about "
                    "ONE role key and hasAnyOf() about a set — a file that has swapped one for "
                    "the other has changed what it decides. Reason on record: " + want["why"]
                )
            if abs(call["line"] - int(want["line"])) > LINE_DRIFT:
                note(
                    where
                    + " — ROLES_HAS_REMAINING says line "
                    + str(want["line"])
                    + ". Not a finding; worth putting right."
                )


def main() -> int:
    parser = argparse.ArgumentParser(description="No new hard-coded role gate (#739).")
    parser.add_argument(
        "--web",
        default=None,
        help="the web folder to scan (default: the repository's own web/). Used by the "
        "planting runs, which copy the tree somewhere else.",
    )
    args = parser.parse_args()

    repo_root = Path(__file__).resolve().parents[2]
    web_dir = Path(args.web).resolve() if args.web is not None else repo_root / "web"
    # When --web points somewhere else, report paths relative to ITS parent, so
    # a planted copy reads the same as the real tree.
    path_root = repo_root if args.web is None else web_dir.parent

    if web_dir.is_dir() is False:
        refuse("there is no web folder at " + str(web_dir) + ".")

    print("No new hard-coded role gate (#739, #727)")
    print("=" * 72)

    has_role, roles = find_calls(web_dir, path_root)

    total_has_role = sum(len(calls) for calls in has_role.values())
    total_roles = sum(len(calls) for calls in roles.values())
    print(
        "App::hasRole() calls: "
        + str(total_has_role)
        + " in "
        + str(len(has_role))
        + " file(s)   |   Roles::has()/hasAnyOf() calls: "
        + str(total_roles)
        + " in "
        + str(len(roles))
        + " file(s)"
    )
    print(
        "Listed as unconvertible: "
        + str(sum(len(e["calls"]) for e in HAS_ROLE_REMAINING.values()))
        + " App::hasRole(), "
        + str(sum(len(e["calls"]) for e in ROLES_HAS_REMAINING.values()))
        + " Roles::has()/hasAnyOf()"
    )
    print("")
    for path in sorted(has_role):
        for call in has_role[path]:
            shown = (
                "'" + str(call["value"]) + "'"
                if call["kind"] == "literal"
                else (call["value"] if call["kind"] == "constant" else "worked out at run time")
            )
            print("  " + path + ":" + str(call["line"]) + "  App::hasRole(" + str(shown) + ")")
    for path in sorted(roles):
        for call in roles[path]:
            print("  " + path + ":" + str(call["line"]) + "  Roles::" + call["method"] + "()")
    print("")

    check_has_role(has_role)
    check_roles_has(roles)

    if len(skipped) > 0:
        # SAID OUT LOUD rather than swallowed: on a quiet tree this should
        # never appear, so if it does, something is removing files under this
        # check and a silent skip would look exactly like a clean run.
        notes.append(
            str(len(skipped))
            + " file(s) disappeared between being listed and being read, so nothing was checked "
            "in them. Not a finding (a file that is not there cannot hold a gate), but on a quiet "
            "tree it should not happen: " + ", ".join(skipped[:5]) + ("…" if len(skipped) > 5 else "")
        )

    for message in notes:
        print("\u2139\ufe0f  " + message)
    if len(notes) > 0:
        print("")

    if len(findings) == 0:
        print("✅ Nothing wrong. Every role gate is a capability, or is named with its reason.")
        print("   This says nothing about whether a gate asks the RIGHT capability, and nothing")
        print("   about a gate written as SQL — see the header for both.")
        return 0

    print("❌ " + str(len(findings)) + " finding(s):")
    for message in findings:
        print("  - " + message)
    return 1


if __name__ == "__main__":
    sys.exit(main())
