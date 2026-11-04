#!/usr/bin/env python3
# The module docstring is a RAW string: it quotes PHP such as
# `use Portal\Core\Capabilities;`, and Python 3.12 warns that "\C" is not a
# valid escape — a warning printed on every run of a hard gate is noise that
# teaches people to ignore its output.
r"""
The capability registry and its storage cannot drift apart (#727). HARD GATE.

`web/_core/Capabilities.php` is the one place a gated "capability" is declared
and the one place an organisation's chosen roles for it are read or written. A
capability that is declared wrongly is not a cosmetic fault: it is a door that
opens for the wrong people, or for nobody, with nothing on any page looking
different.

WHAT IT CHECKS, and every one of these FAILS BOTH WAYS on purpose — an
unlisted fault and a listed exception that has stopped being true. The second
half is what went wrong with the migration harness's exception list, which went
stale unnoticed (#641).

  A  SHAPE. Every entry of `CAPABILITIES` and `NOT_YET_ASKED` declares exactly
     the fields it should: no missing field, and no extra one. A field nobody
     reads looks like a declaration and is not.
  B  THE TWO LISTS ARE DIFFERENT THINGS. A `CAPABILITIES` entry must name at
     least one file that asks it; a `NOT_YET_ASKED` entry must name none. So
     neither list can quietly become the other, and the page cannot end up
     offering a control that changes nothing anywhere (#671: "a seeded setting
     that nothing reads is a lie with a Save button").
  C  NO KEY IN BOTH LISTS.
  D  THE SHIPPED ROLE EXISTS. Every `standard` role key is a key in
     `Roles::STANDARD`, and `standard` is never empty. A capability shipping
     with a role no organisation has is a door nobody can open, which is
     exactly the shape #516 itself was.
  E  KEYS FIT THEIR COLUMNS. A capability key fits `capabilityKey VARCHAR(64)`
     and a role key fits `roleKey VARCHAR(50)`, and both match the shape the
     portal uses. A key too long for its column is silently truncated or
     refused at the moment somebody saves.
  F  THE STORAGE IS IN BOTH SQL FILES AND AGREES. Each table named by the
     class's own constants has a `CREATE TABLE IF NOT EXISTS` block in BOTH
     `web/_sql/243_capability_roles.sql` and `web/_sql/full_schema.sql`, with
     the SAME columns in the SAME order. That is the standing rule of
     11 September 2026: every database change goes in both, and the two must
     agree.
  G  EVERY STORED COLUMN IS USED. Every column of those tables is named by
     `Capabilities.php`, or is listed in `NOT_NAMED_BY_THE_CLASS` below with a
     reason. Fails both ways, so a column that stops being used and one that
     starts cannot pass.
  H  `askedAt` IS TRUE. Every file a capability names really asks it, and no
     other file under `web/` asks a capability at all. Without the second half
     a gate could be converted and left out of the list, and the list is what
     makes the set of gated capabilities reviewable.
  I  #724'S GATE. `erasure.review` declares `globalAdminFallsOpen => false`,
     and neither of its two files contains `App::hasRole(`. See the comment
     at the failure for why this one is not a preference.
  J  THE REPAIR ROUTE IS NEVER ITSELF A CAPABILITY. No file under
     `web/_apps/admin/roles/` may ask a capability. That page is what puts a
     mapping right, so gating it on a capability would make an empty set
     permanent — and an empty set being recoverable is the whole reason there
     is no "cannot be emptied" flag.
  K  `App::can()` DOES NOT SHORT-CIRCUIT. It must hand the global
     administrator flag to `Capabilities::allows()` rather than answering true
     itself. One `return true;` there would undo every
     `globalAdminFallsOpen => false` in the registry at once.
  L  THE INSTALLATION'S SETTING IS SEEDED in both SQL files, installation-wide.
     A setting nothing seeds reads as missing, and `installationLocks()` would
     answer "nothing is locked" for ever without anybody choosing that.
  M  NO OLD-STYLE GATE IS LEFT, AND NONE COMES BACK. Once a capability is
     offered, nothing under `web/` may call `App::hasRole()` with one of that
     capability's own `standard` role keys — not as a literal and not through a
     constant in the same file. This is what catches a converted gate being
     "fixed" back for consistency with its neighbours, and a NEW gate written
     in the old style inside an app that has already been converted. It fails
     both ways: `HAS_ROLE_ON_PURPOSE` names the files where such a call is
     deliberate, with the reason, and a listed file that no longer makes one
     fails too.
  O  THE SHORT NAME RESOLVES. Every file that writes `Capabilities::` either
     declares `use Portal\Core\Capabilities;` or is itself in that namespace.
     This one is here because of a fault this repository has already paid for
     (#536): a class used by its short name needs its `use` line in THAT file,
     and a missing one FAILS ONLY WHEN THE LINE RUNS. Most of the 49 converted
     gates read `App::isAdmin() === false && App::can(…)`, so for an
     administrator PHP never evaluates the right-hand side — a missing `use`
     line would be invisible to `php -l`, invisible to every administrator,
     and a fatal error for the first ordinary member who tried the page.
  N  AN INCOMPLETE ENTRY CANNOT BE GUESSED AT. `definition()` must ask
     `whyEntryIncomplete()` and THROW, because `globalAdminFallsOpen` is true
     for five capabilities and false for one — so a missing value has no safe
     default, and whichever way a default leaned, half the registry would be
     wrong with nothing on any page looking different. The whole condition is
     pinned, because `&& false` walks past a check that matches only the call.

HOW IT READS THE PHP, and why not with a bracket counter alone
--------------------------------------------------------------
Comments are removed and every string literal is MASKED — its contents
replaced by a run of `x` of the same length, so offsets still line up. #706's
lesson: one unbalanced `[` inside a comment made an entry's "block" swallow
144,494 of a file's 153,340 characters, and the comment that broke that parser
was a note about the parser. Masking strings as well means a `[` or a `}`
inside a sentence cannot move a boundary either.

CONSTANTS ARE RESOLVED. The `erasure.review` entry is keyed by
`self::ERASURE_REVIEW`, not by a literal, so a check reading literals alone
would not see it at all — #668's lesson, where a check would have passed on
the very fault it guards because the key appeared nowhere as a literal.

WHAT IT CANNOT DO, said plainly
-------------------------------
  - It cannot judge whether a capability's words are TRUE. "Opens the asset
    register" is prose, and nothing here reads the asset register.
  - It cannot see a capability asked through a variable
    (`App::can($whatever)`). Check H finds the FILE either way, because it
    looks for the call and not for the key, but it cannot tell which
    capability such a call asks. No such call exists today.
  - It says nothing about who holds a role. A correct registry pointing at a
    role nobody holds is a door nobody can open, and the page warns about that
    at run time because it is a fact about data rather than about code.

Exit code:
  0 — nothing wrong
  1 — at least one finding (blocking: this is a hard gate)
  2 — the check could not run (a file is missing, or the registry could not be
      read at all). A check that cannot run must never look like a pass.

Usage:
  python3 tools/audit-checks/check_capability_registry.py
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
CORE = REPO_ROOT / "web" / "_core"
CAPABILITIES_PHP = CORE / "Capabilities.php"
ROLES_PHP = CORE / "Roles.php"
APP_PHP = CORE / "App.php"
MIGRATION_SQL = REPO_ROOT / "web" / "_sql" / "243_capability_roles.sql"
FULL_SCHEMA_SQL = REPO_ROOT / "web" / "_sql" / "full_schema.sql"
WEB = REPO_ROOT / "web"

# The fields every entry must declare, and no others. `whyNotOffered` is
# required of a NOT_YET_ASKED entry and forbidden of an offered one, because an
# offered capability has no reason not to be offered.
REQUIRED_FIELDS = [
    "label",
    "opens",
    "standard",
    "globalAdminFallsOpen",
    "organisationMayRepoint",
    "emptyMeans",
    "askedAt",
]
NOT_YET_ASKED_EXTRA = ["whyNotOffered"]

# Columns of the two storage tables that `Capabilities.php` deliberately never
# names, with the reason. Check G fails if an entry here IS named, as well as
# if a column that is not here is missed — a stale exception makes a check look
# stronger than it is.
NOT_NAMED_BY_THE_CLASS = {
    "mapRoleID": "The child table's own key. Nothing needs it: a role row is "
    "identified by (mapID, roleKey) and is only ever inserted or deleted "
    "wholesale.",
}

# The one capability whose gate is a privacy boundary rather than a job title.
ERASURE_REVIEW_KEY = "erasure.review"
ERASURE_REVIEW_FILES = [
    "web/_apps/admin/erasure/review.php",
    "web/_apps/admin/erasure/review-save.php",
]

# How a capability is asked. Check H looks for the CALL and not for a key, so a
# call passing a variable is still found.
ASK_CALLS = ("App::can(", "Capabilities::allows(")
# The two files that implement the asking, which are not themselves gates.
ASK_IMPLEMENTATION = {"web/_core/App.php", "web/_core/Capabilities.php"}

# Check M. Files that may still call `App::hasRole()` with a role key belonging
# to an OFFERED capability, and why. FAILS BOTH WAYS: an unlisted call, and a
# listed file that no longer makes one.
#
# Every entry here is a case where the role and the capability are genuinely
# different questions, so pointing the second at a custom role must NOT change
# the first. Writing the reason down is the point: without it, the next reader
# cannot tell a deliberate exception from one somebody forgot.
# 🫙 EMPTY SINCE #739 (4 November 2026), AND THE REASON IS WORTH KEEPING.
#    It held one entry: `web/_core/Giving.php`, whose `canManage()` asked for
#    the `treasurer` role directly, with the note that "giving is its own
#    capability when somebody asks for one". #739 was somebody asking, so that
#    gate is now `giving.records` and the entry had to GO — this list fails
#    both ways, and a listed file that no longer makes such a call is itself a
#    finding. Leaving it would have been a stale exception, which makes a check
#    look stronger than it is (#641's lesson).
#
# ⚠️ DO NOT DELETE THE LIST. It is where the next deliberate same-key call goes.
#    And note what it is NOT: it covers only a call naming an OFFERED
#    capability's own `standard` role key. The general rule — no NEW
#    `App::hasRole()` call anywhere under `web/`, whatever key it names — is
#    `tools/audit-checks/check_no_new_role_gates.py`, which is where the three
#    remaining calls are named with their reasons.
HAS_ROLE_ON_PURPOSE: dict[str, str] = {}

findings: list[str] = []
# Files that vanished between the glob and the read — a note, never a finding.
# See read_globbed() for why the two cases are answered differently.
skipped: list[str] = []
# Files already reported as unreadable or gone, so three loops over the same
# tree report one file once.
_read_problems: set[str] = set()


def relative_to_repo(php: Path) -> str:
    """The path as this repository writes it, so a message reads like every other."""
    try:
        return php.relative_to(REPO_ROOT).as_posix()
    except ValueError:
        return php.as_posix()


def shown(php: Path) -> bool:
    """True when this file has already been reported; records it if not."""
    key = php.as_posix()
    if key in _read_problems:
        return True
    _read_problems.add(key)
    return False


def fail(message: str) -> None:
    findings.append(message)


def refuse(message: str) -> None:
    print("REFUSED: " + message)
    print("Nothing was checked.")
    sys.exit(2)


def read_globbed(php: Path) -> str | None:
    """
    One file found by a `rglob`, or None when it is no longer there.

    ⚠️ WHY THIS EXISTS: a file can VANISH between the glob and the read, and
       until 4 November 2026 the three loops below read straight through
       `read_text()` and died with a Python traceback when one did. That really
       happened: the pre-push hook globs this folder, a push ran this check
       while another process was adding and removing a file in the tree, and
       the output was a `FileNotFoundError` naming a path that has nothing to
       do with capabilities. In the hook's output that reads as the HOOK being
       broken, which is the worst kind of failure — nobody can act on it, and
       the honest answer ("nothing is wrong with the registry") is nowhere in
       it. A concurrent `git checkout`, an editor's atomic save (write a
       temporary file, rename over the target) or another tool working in the
       tree all do the same thing.

       The two cases are deliberately answered DIFFERENTLY, because they mean
       different things:
         - GONE: nothing to check. A file that does not exist cannot hold a
           gate, so this is a note and not a finding. Failing on it would be
           crying wolf at a race, and a check that cries wolf gets switched
           off (#483).
         - THERE BUT UNREADABLE (a permission, a directory in the way, an I/O
           error): a FINDING. A file this check cannot read is a file it cannot
           vouch for, and a check that cannot run must never look like a pass.
       `errors="replace"` already covers unreadable CONTENT; this is the same
       care for a file that has gone.

    ⚠️ WHAT IS STILL BARE, said plainly rather than left to be discovered: the
       reads of NAMED files (the three PHP sources, the two SQL files, the two
       repair-page files and #724's two) do not go through this. Each has an
       `is_file()` test immediately above it whose failure is already a
       sentence naming the file, so the only remaining window is a file removed
       between that test and the read — microseconds, on paths nothing in this
       repository rewrites. If one of those ever does produce a traceback, the
       cure is this helper and not a wider `try` round the whole script: a
       blanket catch would turn a genuine fault into a shrug.
    """
    try:
        return php.read_text(encoding="utf-8", errors="replace")
    except FileNotFoundError:
        if shown(php) is False:
            skipped.append(relative_to_repo(php))
        return None
    except OSError as problem:
        # ONE report per file, however many of the three loops below meet it:
        # the same path three times reads as three faults.
        if shown(php) is False:
            fail(
                relative_to_repo(php)
                + " could not be read (" + str(problem) + "), so this check cannot say whether "
                "it asks a capability or gates on a role key. A file that cannot be read must "
                "never pass quietly."
            )
        return None


def mask_php(source: str) -> str:
    """
    The same text, with comments blanked and every string literal's contents
    replaced by `x` — SAME LENGTH, so every offset still lines up with the
    original. Newlines inside a comment are kept for the same reason (deleting
    them once put reported line numbers 370 out, #716).

    Walks the source rather than using regular expressions, because a `//`
    inside a string (any URL has one) ate the rest of the line, and a `'`
    inside a comment ("don't") put every later quote out of step. Both of
    those are faults this repository has actually paid for.
    """
    out = []
    i = 0
    n = len(source)
    while i < n:
        ch = source[i]
        nxt = source[i + 1] if i + 1 < n else ""
        if ch == "/" and nxt == "/":
            while i < n and source[i] != "\n":
                out.append(" ")
                i += 1
            continue
        if ch == "#" and nxt != "[":
            while i < n and source[i] != "\n":
                out.append(" ")
                i += 1
            continue
        if ch == "/" and nxt == "*":
            while i < n and not (source[i] == "*" and i + 1 < n and source[i + 1] == "/"):
                out.append("\n" if source[i] == "\n" else " ")
                i += 1
            out.append("  ")
            i += 2
            continue
        # 📜 A HEREDOC OR NOWDOC — the branch that was missing, and whose
        #    absence is the exact fault this walk exists to avoid. Measured on
        #    the sibling check (`check_no_new_role_gates.py`, same walk): a
        #    file holding `<<<'HTML'` … `<p>Don't change this</p>` … `HTML;`
        #    and then a real gate made it report "Nothing wrong", because the
        #    apostrophe in "Don't" opened a bogus string literal that swallowed
        #    the call. Latent rather than live — the one nowdoc in the
        #    repository today holds an EVEN number of apostrophes — and it
        #    would fire the first time anybody wrote English prose in one.
        if ch == "<" and source[i:i + 3] == "<<<":
            opener = re.match(
                r'<<<[ \t]*(?:"([A-Za-z_]\w*)"|\'([A-Za-z_]\w*)\'|([A-Za-z_]\w*))\r?\n',
                source[i:],
            )
            if opener is not None:
                label = opener.group(1) or opener.group(2) or opener.group(3)
                out.append(opener.group(0))
                i += opener.end()
                closer = re.compile(r"^[ \t]*" + re.escape(label) + r"\b", re.M)
                found = closer.search(source, i)
                stop = found.start() if found is not None else n
                for position in range(i, stop):
                    out.append("\n" if source[position] == "\n" else "x")
                i = stop
                continue
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


def without_comments(source: str) -> str:
    """
    The same text with comments blanked and STRING LITERALS LEFT ALONE.

    ⚠️ `mask_php()` IS THE WRONG TOOL FOR CHECK M AND THAT IS NOT OBVIOUS.
       Masking strings is this repository's usual remedy, and it would blind
       check M completely: the thing it has to read IS a string literal, so
       `App::hasRole('asset_manager')` would come back as
       `App::hasRole('xxxxxxxxxxxxx')`. Exactly that mistake broke both halves
       of #716's guard. So this blanks comments only — and still WALKS the
       source rather than using a regular expression, because a `//` inside a
       string (any URL has one) ate the rest of the line, and an apostrophe
       inside a comment put every later quote out of step.

    Lengths and newlines are preserved, so reported line numbers stay true.
    """
    out = []
    i = 0
    n = len(source)
    while i < n:
        ch = source[i]
        nxt = source[i + 1] if i + 1 < n else ""
        if ch == "/" and nxt == "/":
            while i < n and source[i] != "\n":
                out.append(" ")
                i += 1
            continue
        if ch == "#" and nxt != "[":
            while i < n and source[i] != "\n":
                out.append(" ")
                i += 1
            continue
        if ch == "/" and nxt == "*":
            while i < n and not (source[i] == "*" and i + 1 < n and source[i + 1] == "/"):
                out.append("\n" if source[i] == "\n" else " ")
                i += 1
            out.append("  ")
            i += 2
            continue
        if ch in ("'", '"'):
            quote = ch
            out.append(quote)
            i += 1
            while i < n:
                if source[i] == "\\" and i + 1 < n:
                    out.append(source[i : i + 2])
                    i += 2
                    continue
                out.append(source[i])
                if source[i] == quote:
                    i += 1
                    break
                i += 1
            continue
        out.append(ch)
        i += 1
    return "".join(out)


def slice_constant(source: str, masked: str, name: str) -> tuple[int, int] | None:
    """
    The offsets of one `public const NAME = [ … ];` array body, found by
    counting brackets on the MASKED text so that a bracket inside a comment or
    a sentence cannot move either end.
    """
    match = re.search(r"public\s+const\s+" + re.escape(name) + r"\s*=\s*\[", masked)
    if match is None:
        return None
    start = match.end()
    depth = 1
    i = start
    while i < len(masked):
        if masked[i] == "[":
            depth += 1
        elif masked[i] == "]":
            depth -= 1
            if depth == 0:
                return (start, i)
        i += 1
    return None


def php_constants(source: str) -> dict[str, str]:
    """`public const NAME = 'value';` — the string ones only, for resolving a
    key written as `self::NAME` rather than as a literal."""
    found = {}
    for match in re.finditer(
        r"public\s+const\s+([A-Z][A-Z0-9_]*)\s*=\s*'([^']*)'\s*;", source
    ):
        found[match.group(1)] = match.group(2)
    return found


def top_level_keys(source: str, masked: str, start: int, end: int) -> list[tuple[str, int, int]]:
    """
    Every `'key' =>` or `self::CONST =>` at depth 0 inside an array body, with
    the offsets of its own value. Entries are delimited from one key to the
    NEXT key, so no structure inside an entry is parsed at all (#706's
    remedy).
    """
    constants = php_constants(source)
    # One pass, collecting (key, where its own `'key' =>` starts, where its
    # value starts). The value then runs to the start of the NEXT key, or to
    # the end of the array — so nothing inside an entry is parsed.
    found: list[tuple[str, int, int]] = []
    depth = 0
    i = start
    while i < end:
        ch = masked[i]
        if ch in "[(":
            depth += 1
        elif ch in "])":
            depth -= 1
        elif depth == 0:
            literal = re.match(r"'([^']*)'\s*=>", masked[i:end])
            constant = re.match(r"self::([A-Z][A-Z0-9_]*)\s*=>", masked[i:end])
            if literal is not None:
                # The masked text has the same offsets, so the real key is
                # read out of the original at the same place.
                real = re.match(r"'([^']*)'\s*=>", source[i:end])
                if real is not None:
                    found.append((real.group(1), i, i + real.end()))
                    i += real.end()
                    continue
            if constant is not None:
                name = constant.group(1)
                if name not in constants:
                    fail(
                        "Capabilities.php: an entry is keyed by self::"
                        + name
                        + ", which is not a string constant in that file — the key cannot be resolved."
                    )
                found.append((constants.get(name, "?" + name), i, i + constant.end()))
                i += constant.end()
                continue
        i += 1

    out = []
    for index, (key, _key_start, value_start) in enumerate(found):
        stop = found[index + 1][1] if index + 1 < len(found) else end
        out.append((key, value_start, stop))
    return out


def inside_brackets(masked: str, start: int, end: int) -> tuple[int, int] | None:
    """
    The offsets just inside one entry's own `[ … ]`. An entry's VALUE begins
    with that bracket, so without stepping over it every field sits one level
    deeper than `top_level_keys` looks and the entry reads as declaring
    nothing at all — which is exactly how the first version of this check
    reported every field of every entry as missing.
    """
    open_at = masked.find("[", start)
    if open_at < 0 or open_at >= end:
        return None
    depth = 0
    i = open_at
    while i < end:
        if masked[i] == "[":
            depth += 1
        elif masked[i] == "]":
            depth -= 1
            if depth == 0:
                return (open_at + 1, i)
        i += 1
    return None


def field_values(source: str, masked: str, start: int, end: int) -> dict[str, str]:
    """One entry's own fields: `'field' => <text>`, the text taken raw."""
    bounds = inside_brackets(masked, start, end)
    if bounds is None:
        return {}
    fields = {}
    for key, value_start, value_end in top_level_keys(source, masked, bounds[0], bounds[1]):
        # ⚠️ THE EXTENT IS MEASURED ON THE MASKED TEXT AND THE VALUE IS READ
        #    OUT OF THE ORIGINAL. A value runs to the start of the NEXT field,
        #    so the raw text between them includes any COMMENT written before
        #    that next field — and several of these entries carry a long one.
        #    Reading the raw slice made `standard` look like
        #    "['data_controller'], // ⚠️ FALSE, AND IT IS THE POINT…", which is
        #    not a list of quoted strings. Masking blanks the comment to
        #    spaces without moving a single offset, so trimming the masked
        #    slice gives the real end of the value.
        chunk = masked[value_start:value_end]
        lo = value_start + (len(chunk) - len(chunk.lstrip()))
        hi = value_start + len(chunk.rstrip())
        if hi > lo and masked[hi - 1] == ",":
            hi -= 1
            hi = value_start + len(masked[value_start:hi].rstrip())
        fields[key] = source[lo:hi]
    return fields


def string_list(text: str) -> list[str] | None:
    """A PHP list of single-quoted strings, or None when it is not one."""
    inner = text.strip()
    if inner.startswith("[") is False or inner.endswith("]") is False:
        return None
    return re.findall(r"'([^']*)'", inner)


# -----------------------------------------------------------------------------
# Read the three PHP files
# -----------------------------------------------------------------------------
for path in (CAPABILITIES_PHP, ROLES_PHP, APP_PHP, MIGRATION_SQL, FULL_SCHEMA_SQL):
    if path.is_file() is False:
        refuse("missing " + str(path.relative_to(REPO_ROOT)))

cap_source = CAPABILITIES_PHP.read_text(encoding="utf-8")
cap_masked = mask_php(cap_source)
roles_source = ROLES_PHP.read_text(encoding="utf-8")
roles_masked = mask_php(roles_source)
app_source = APP_PHP.read_text(encoding="utf-8")
app_masked = mask_php(app_source)

cap_slice = slice_constant(cap_source, cap_masked, "CAPABILITIES")
not_asked_slice = slice_constant(cap_source, cap_masked, "NOT_YET_ASKED")
roles_slice = slice_constant(roles_source, roles_masked, "STANDARD")
if cap_slice is None or not_asked_slice is None:
    refuse("could not find CAPABILITIES and NOT_YET_ASKED in Capabilities.php")
if roles_slice is None:
    refuse("could not find Roles::STANDARD in Roles.php")

standard_roles = [
    key for key, _s, _e in top_level_keys(roles_source, roles_masked, roles_slice[0], roles_slice[1])
]
if len(standard_roles) == 0:
    refuse("Roles::STANDARD came back empty, so nothing could be compared against it")

offered = {
    key: field_values(cap_source, cap_masked, s, e)
    for key, s, e in top_level_keys(cap_source, cap_masked, cap_slice[0], cap_slice[1])
}
declared = {
    key: field_values(cap_source, cap_masked, s, e)
    for key, s, e in top_level_keys(cap_source, cap_masked, not_asked_slice[0], not_asked_slice[1])
}
if len(offered) == 0:
    refuse("CAPABILITIES came back empty — either it really is, or the parse failed")

# -----------------------------------------------------------------------------
# A, B, C, D, E — the registry itself
# -----------------------------------------------------------------------------
KEY_SHAPE = re.compile(r"^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+$")
ROLE_SHAPE = re.compile(r"^[a-z][a-z0-9_]{1,49}$")

for name, entries, expect_asked in (
    ("CAPABILITIES", offered, True),
    ("NOT_YET_ASKED", declared, False),
):
    wanted = list(REQUIRED_FIELDS) + (NOT_YET_ASKED_EXTRA if expect_asked is False else [])
    for key, fields in entries.items():
        where = name + "['" + key + "']"
        missing = [field for field in wanted if field not in fields]
        extra = [field for field in fields if field not in wanted]
        if len(missing) > 0:
            fail(where + " does not declare: " + ", ".join(missing))
        if len(extra) > 0:
            fail(
                where
                + " declares a field nothing reads: "
                + ", ".join(extra)
                + " — a field nobody reads looks like a declaration and is not."
            )

        if KEY_SHAPE.match(key) is None:
            fail(where + ": the key is not of the shape area.thing, all lower case.")
        if len(key) > 64:
            fail(
                where
                + ": the key is "
                + str(len(key))
                + " characters and capabilityKey is VARCHAR(64), so it could not be stored."
            )

        shipped = string_list(fields.get("standard", ""))
        if shipped is None:
            fail(where + ": `standard` is not a list of quoted role keys.")
        else:
            if len(shipped) == 0:
                fail(
                    where
                    + ": `standard` is empty, so this capability ships opened by nobody. An "
                    "organisation that never opens the roles page would find the door shut — "
                    "the #499 shape, where 'fail closed' was built first and locked members out."
                )
            for role in shipped:
                if role not in standard_roles:
                    fail(
                        where
                        + ": `standard` names the role '"
                        + role
                        + "', which is not in Roles::STANDARD, so no organisation has it and "
                        "nobody could ever open this door."
                    )
                if ROLE_SHAPE.match(role) is None or len(role) > 50:
                    fail(
                        where
                        + ": the role key '"
                        + role
                        + "' is not of the shape the portal uses, or does not fit roleKey VARCHAR(50)."
                    )

        for flag in ("globalAdminFallsOpen", "organisationMayRepoint"):
            if fields.get(flag) not in ("true", "false"):
                fail(where + ": `" + flag + "` must be the word true or false, not " + repr(fields.get(flag)) + ".")

        for words in ("label", "opens", "emptyMeans"):
            text = fields.get(words, "")
            if text.startswith("'") is False and text.startswith('"') is False:
                fail(where + ": `" + words + "` must be written words, and it is not a string.")

        asked = string_list(fields.get("askedAt", ""))
        if asked is None:
            fail(where + ": `askedAt` is not a list of quoted paths.")
        elif expect_asked is True and len(asked) == 0:
            fail(
                where
                + ": it is offered on the roles page and names no file that asks it, so changing it "
                "would change nothing anywhere — #671's 'a seeded setting that nothing reads is a "
                "lie with a Save button'. Move it to NOT_YET_ASKED, or convert its gate."
            )
        elif expect_asked is False and len(asked) > 0:
            fail(
                where
                + ": it is in NOT_YET_ASKED and yet names files that ask it ("
                + ", ".join(asked)
                + "). Move it to CAPABILITIES so an organisation can set it."
            )

both = sorted(set(offered) & set(declared))
if len(both) > 0:
    fail(
        "These keys are in BOTH CAPABILITIES and NOT_YET_ASKED, so what the portal does with them "
        "depends on which list is read: " + ", ".join(both)
    )

# -----------------------------------------------------------------------------
# F, G — the storage, in both SQL files, agreeing, and every column used
# -----------------------------------------------------------------------------
table_constants = dict(re.findall(r"const\s+(MAP_TABLE|MAP_ROLE_TABLE)\s*=\s*'([^']+)'", cap_source))
if len(table_constants) != 2:
    refuse("could not read MAP_TABLE and MAP_ROLE_TABLE out of Capabilities.php")


def create_block(sql: str, table: str) -> str | None:
    match = re.search(
        r"CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`" + re.escape(table) + r"`\s*\((.*?)\n\)\s*ENGINE",
        sql,
        re.DOTALL | re.IGNORECASE,
    )
    return None if match is None else match.group(1)


def column_names(block: str) -> list[str]:
    names = []
    for line in block.split("\n"):
        stripped = line.strip()
        match = re.match(r"`([A-Za-z][A-Za-z0-9_]*)`\s+[A-Za-z]", stripped)
        if match is not None:
            names.append(match.group(1))
    return names


migration_sql = MIGRATION_SQL.read_text(encoding="utf-8")
full_schema_sql = FULL_SCHEMA_SQL.read_text(encoding="utf-8")

for constant_name, table in sorted(table_constants.items()):
    blocks = {}
    for label, sql in (("243_capability_roles.sql", migration_sql), ("full_schema.sql", full_schema_sql)):
        block = create_block(sql, table)
        if block is None:
            fail(
                "`"
                + table
                + "` (Capabilities::"
                + constant_name
                + ") has no CREATE TABLE IF NOT EXISTS block in web/_sql/"
                + label
                + ". Every database change goes in BOTH the install script and a numbered "
                "migration (the standing rule of 11 September 2026): in only one of them, two "
                "installations of the same version behave differently."
            )
        else:
            blocks[label] = column_names(block)

    if len(blocks) == 2:
        first, second = blocks["243_capability_roles.sql"], blocks["full_schema.sql"]
        if first != second:
            fail(
                "`"
                + table
                + "` has different columns in the two SQL files. The migration says "
                + ", ".join(first)
                + " and full_schema.sql says "
                + ", ".join(second)
                + "."
            )
        for column in first:
            named = ("`" + column + "`") in cap_masked or ("`" + column + "`") in cap_source
            excused = column in NOT_NAMED_BY_THE_CLASS
            if named is False and excused is False:
                fail(
                    "`"
                    + table
                    + "`.`"
                    + column
                    + "` is stored and Capabilities.php never names it. Either use it or add it to "
                    "NOT_NAMED_BY_THE_CLASS with the reason."
                )
            if named is True and excused is True:
                fail(
                    "`"
                    + table
                    + "`.`"
                    + column
                    + "` is in NOT_NAMED_BY_THE_CLASS, and Capabilities.php does name it. A stale "
                    "exception makes this check look stronger than it is — take the entry out."
                )

# -----------------------------------------------------------------------------
# H, J — askedAt is true, and the repair route is never itself a capability
# -----------------------------------------------------------------------------
claimed: dict[str, list[str]] = {}
for key, fields in offered.items():
    for path in string_list(fields.get("askedAt", "")) or []:
        claimed.setdefault(path, []).append(key)

really_asks: set[str] = set()
for php in sorted(WEB.rglob("*.php")):
    relative = php.relative_to(REPO_ROOT).as_posix()
    if relative in ASK_IMPLEMENTATION:
        continue
    raw = read_globbed(php)
    if raw is None:
        continue
    text = mask_php(raw)
    if any(call in text for call in ASK_CALLS):
        really_asks.add(relative)

for path, keys in sorted(claimed.items()):
    full = REPO_ROOT / path
    if full.is_file() is False:
        fail("CAPABILITIES names " + path + " as asking " + ", ".join(keys) + ", and there is no such file.")
        continue
    if path not in really_asks:
        fail(
            path
            + " is named as asking "
            + ", ".join(keys)
            + " and it asks no capability at all (no App::can() and no Capabilities::allows() in its "
            "code, comments excluded). Either the gate was never converted or it has been reverted."
        )

for path in sorted(really_asks - set(claimed)):
    fail(
        path
        + " asks a capability and no entry in CAPABILITIES names it. The list of gated capabilities "
        "is what makes them reviewable, so a gate that is not in it is invisible to anybody reading "
        "the registry."
    )

for path in sorted(really_asks):
    if path.startswith("web/_apps/admin/roles/"):
        fail(
            path
            + " asks a capability, and it is part of the page that PUTS A MAPPING RIGHT. Gating the "
            "repair route on a capability makes an empty set permanent, which is the one thing that "
            "would turn 'the empty set is a decision' into 'the empty set is a lock-out'. That fact "
            "is why there is no 'cannot be emptied' flag — do not make it untrue."
        )

# -----------------------------------------------------------------------------
# P — the repair page's OWN administrator gate, which nothing pinned at all
# -----------------------------------------------------------------------------
# ⚠️ WHY THIS IS A HARD GATE AND NOT ONLY A SELF-TEST CHECK. Check J above
#    says the repair page must ask NO capability, and `Capabilities`' class
#    header, `CHANGELOG.md` and the standing rule in `.claude/CLAUDE.md` all
#    then argue that an empty set is always recoverable "because the page that
#    changes a mapping is gated on App::isAdmin()". Until 3 November 2026
#    NOTHING checked that it was. An independent check turned
#    `if (App::isAdmin() === false)` into `if (false)` in each of these two
#    files in turn and got `php -l` clean, all 53 audit scripts green and the
#    capability self-test 199/199, both times.
#
#    `save.php` is the single writer of every capability mapping, so with that
#    one word changed any signed-in member could post `action=capability` and
#    re-point every offered door at a role they hold, and could rename and
#    delete roles. The gate itself is #516's and predates all of this; what
#    #727 did was make that one line the thing standing between a member and
#    every access decision in the registry.
#
#    It lives here as well as in the self-test because the pre-push hook runs
#    every script in this folder and runs no self-test (#646's lesson): a
#    guard that only runs on a pull request is not in front of a push.
#
#    THE PIN IS THE WHOLE BLOCK AS ONE LITERAL, deliberately. One literal does
#    both jobs: the WHOLE condition, so `if (false)` and `&& false` both fail
#    it, and the refusal INSIDE the block, so deleting the
#    `Router::renderError(403); return;` fails it too. Matching the condition
#    alone passed with the refusal removed once before (#668's section-D
#    lesson), and a regular expression allowing "anything, then a return"
#    would pass on a block that returned after doing the work.
REPAIR_PAGE_GATE = (
    "if (App::isAdmin() === false) {\n"
    "    Router::renderError(403);\n"
    "    return;\n"
    "}"
)
# Everything that must come AFTER the gate. A gate that is present but sits
# below the work is no gate at all, and a literal pin cannot see where a block
# is. Both files have it as the first executable statement after their `use`
# lines.
MUST_FOLLOW_THE_GATE = ("$_SERVER", "$_POST", "$_GET", "Site::id()", "Capabilities::")
for path in ("web/_apps/admin/roles/index.php", "web/_apps/admin/roles/save.php"):
    full = REPO_ROOT / path
    if full.is_file() is False:
        fail(
            path
            + " is missing, and it is one of the two files the repair route is made of. Check J "
            "above depends on this page existing and being gated on nothing but App::isAdmin()."
        )
        continue
    # Comments blanked, so a commented-out gate cannot satisfy this.
    gate_code = without_comments(full.read_text(encoding="utf-8", errors="replace"))
    gate_at = gate_code.find(REPAIR_PAGE_GATE)
    if gate_at < 0:
        fail(
            path
            + " no longer holds the administrator gate exactly as\n        "
            + REPAIR_PAGE_GATE.replace("\n", "\n        ")
            + "\n    This is the ONLY thing refusing an ordinary member, and admin/roles/save.php "
            "is the single writer of every capability mapping: without it any signed-in member "
            "can re-point every door in the registry at a role they hold, and rename and delete "
            "roles. If the gate must be rewritten, change this literal in the same edit and say "
            "in the commit message why the new form refuses at least as much."
        )
        continue
    too_early = [
        needle
        for needle in MUST_FOLLOW_THE_GATE
        if 0 <= gate_code.find(needle) < gate_at
    ]
    if len(too_early) > 0:
        fail(
            path
            + " has the administrator gate BELOW "
            + ", ".join(too_early)
            + ". The gate is intact, which is why a text pin alone would not notice: it has to come "
            "first, before anything reads the request, resolves the organisation or asks the "
            "registry, or the work happens and is only then refused."
        )

# -----------------------------------------------------------------------------
# I — #724's gate
# -----------------------------------------------------------------------------
if ERASURE_REVIEW_KEY not in offered:
    fail(
        "CAPABILITIES no longer offers '"
        + ERASURE_REVIEW_KEY
        + "', which is #724's gate and the first real consumer of this registry."
    )
else:
    if offered[ERASURE_REVIEW_KEY].get("globalAdminFallsOpen") != "false":
        fail(
            "CAPABILITIES['"
            + ERASURE_REVIEW_KEY
            + "'] must declare globalAdminFallsOpen => false. A global administrator who has not "
            "been given the Data Controller role is refused ON PURPOSE (#724, the owner, "
            "3 November 2026): whoever runs this installation may be a different party from "
            "whoever runs the organisation, and this door leads to a member's own words about "
            "whether they must go. Changing this one word opens that door with nothing on any "
            "page looking different."
        )
    for path in ERASURE_REVIEW_FILES:
        full = REPO_ROOT / path
        if full.is_file() is False:
            fail(path + " is missing, and it is one of the two files #724's gate must be on.")
            continue
        if "App::hasRole(" in mask_php(full.read_text(encoding="utf-8")):
            fail(
                path
                + " contains App::hasRole(), which answers TRUE for any global administrator with no "
                "role row (#516). That is exactly the person the Data Controller role exists to keep "
                "out of this page."
            )

# -----------------------------------------------------------------------------
# K — App::can() hands the flag over rather than answering for itself
# -----------------------------------------------------------------------------
can_at = app_masked.find("public static function can(string $capability): bool")
if can_at < 0:
    fail("App.php no longer has `public static function can(string $capability): bool`.")
else:
    # To the end of that one method: the next `    }` at method indentation.
    end_at = app_masked.find("\n    }", can_at)
    body = app_source[can_at : end_at if end_at > 0 else len(app_source)]
    if "Capabilities::allows(" not in body:
        fail("App::can() does not call Capabilities::allows(), so it is answering for itself.")
    if "self::flagIsOn($user, 'isRootAdmin')" not in body:
        fail(
            "App::can() does not pass self::flagIsOn($user, 'isRootAdmin') to Capabilities::allows(). "
            "The global administrator flag has to arrive as a FACT so the capability's own "
            "declaration can decide whether to honour it."
        )
    if re.search(r"return\s+true\s*;", body) is not None:
        fail(
            "App::can() contains `return true;`. A shortcut there would undo every "
            "globalAdminFallsOpen => false in the registry at once, #724's included, and nothing on "
            "any page would look different. The flag is passed to Capabilities::allows() instead."
        )

# -----------------------------------------------------------------------------
# L — the installation's setting is seeded in both SQL files
# -----------------------------------------------------------------------------
lock_key_match = re.search(r"const\s+LOCK_SETTING_KEY\s*=\s*'([^']+)'", cap_source)
if lock_key_match is None:
    fail("Capabilities::LOCK_SETTING_KEY is gone, so the installation's refusal has no setting.")
else:
    lock_key = lock_key_match.group(1)
    for label, sql in (("243_capability_roles.sql", migration_sql), ("full_schema.sql", full_schema_sql)):
        seeded = re.search(
            r"INSERT\s+INTO\s+`tblSettings`.*?\(\s*NULL\s*,\s*'" + re.escape(lock_key) + r"'",
            sql,
            re.DOTALL | re.IGNORECASE,
        )
        if seeded is None:
            fail(
                "'"
                + lock_key
                + "' is not seeded installation-wide (siteID NULL) in web/_sql/"
                + label
                + ". Unseeded, installationLocks() answers 'nothing is locked' for ever without "
                "anybody having chosen that."
            )

# -----------------------------------------------------------------------------
# M — no old-style gate is left for an offered capability, and none comes back
# -----------------------------------------------------------------------------
# Which role key belongs to which offered capability, so the failure can say
# what to use instead.
role_owner: dict[str, str] = {}
for key, fields in offered.items():
    for role in string_list(fields.get("standard", "")) or []:
        role_owner[role.lower()] = key

HAS_ROLE_CALL = re.compile(r"App::hasRole\(\s*(?:'([^']*)'|\"([^\"]*)\"|(?:self|static)::([A-Z][A-Z0-9_]*))\s*\)")

old_style: dict[str, list[str]] = {}
for php in sorted(WEB.rglob("*.php")):
    relative = php.relative_to(REPO_ROOT).as_posix()
    source = read_globbed(php)
    if source is None:
        continue
    # Comments blanked, strings KEPT — see without_comments() for why masking
    # them would make this check read nothing at all.
    code = without_comments(source)
    # Same-file string constants, so a key written as `self::ROLE_KEY` is
    # resolved. #668's lesson: a check reading literals alone would pass on
    # the very fault it guards, because the key appears nowhere as a literal.
    local_constants = dict(
        re.findall(r"const\s+([A-Z][A-Z0-9_]*)\s*=\s*'([^']*)'\s*;", code)
    )
    for match in HAS_ROLE_CALL.finditer(code):
        single, double, constant = match.group(1), match.group(2), match.group(3)
        if constant is not None:
            asked = local_constants.get(constant)
            if asked is None:
                continue
        else:
            asked = single if single is not None else double
        if asked is None:
            continue
        if asked.lower() in role_owner:
            old_style.setdefault(relative, []).append(asked)

for path, keys in sorted(old_style.items()):
    if path in HAS_ROLE_ON_PURPOSE:
        continue
    capability = role_owner[keys[0].lower()]
    fail(
        path
        + " calls App::hasRole('"
        + "', '".join(sorted(set(keys)))
        + "'), and that role key belongs to the offered capability '"
        + capability
        + "'. An organisation that points that capability at a role of its own would be let "
        "through everywhere else and refused here, which is worse than never offering it: use "
        "App::can(Capabilities::…) instead, and add the file to that capability's askedAt list. "
        "If the role and the capability really are different questions, add this file to "
        "HAS_ROLE_ON_PURPOSE with the reason."
    )

for path, reason in sorted(HAS_ROLE_ON_PURPOSE.items()):
    if path not in old_style:
        fail(
            path
            + " is listed in HAS_ROLE_ON_PURPOSE and makes no such App::hasRole() call any more. A "
            "stale exception makes this check look stronger than it is — take the entry out. The "
            "reason it carried was: " + reason
        )

# -----------------------------------------------------------------------------
# O — the short name `Capabilities` really resolves in every file that uses it
# -----------------------------------------------------------------------------
for php in sorted(WEB.rglob("*.php")):
    relative = php.relative_to(REPO_ROOT).as_posix()
    if relative == "web/_core/Capabilities.php":
        continue
    raw_for_use = read_globbed(php)
    if raw_for_use is None:
        continue
    code = without_comments(raw_for_use)
    # ⚠️ ONLY THE SHORT NAME NEEDS A `use` LINE, and the first version of this
    #    check did not know that — it searched for the text `Capabilities::`,
    #    which a FULLY QUALIFIED `\Portal\Core\Capabilities::` contains as a
    #    substring. So adding the #727 refusals to `web/_apps/settings/` in the
    #    house style of that file (its neighbours write
    #    `\Portal\Core\Maintenance::`) made this check report two files that
    #    were entirely correct. A check that cries wolf gets switched off
    #    (#483), and this one guards a real fault.
    #
    #    A reference is the SHORT name only where `Capabilities::` is NOT
    #    preceded by a backslash. `[^\\]` would miss a reference at the very
    #    start of a line, so the lookbehind is written to allow that.
    uses_short_name = re.search(r"(?<![\\\w])Capabilities::", code) is not None
    if uses_short_name is False:
        continue
    has_use = re.search(r"^use Portal\\Core\\Capabilities;", code, re.MULTILINE) is not None
    in_namespace = re.search(r"^namespace Portal\\Core;", code, re.MULTILINE) is not None
    if has_use is False and in_namespace is False:
        fail(
            relative
            + " writes `Capabilities::` and has neither `use Portal\\Core\\Capabilities;` nor its own "
            "`namespace Portal\\Core;`. PHP would not notice until that LINE RAN — and most of these "
            "gates read `App::isAdmin() === false && App::can(…)`, so for any administrator PHP never "
            "evaluates the right-hand side at all. The first ordinary member to open the page would "
            "get a fatal error (#536's lesson)."
        )

# -----------------------------------------------------------------------------
# N — an incomplete entry throws rather than being guessed at
# -----------------------------------------------------------------------------
# The WHOLE condition and the throw, not just the call: a check that matches
# `whyEntryIncomplete(` alone stays green when `&& false` is added, and one
# that matches the condition alone stays green when the throw is deleted
# (#668's section-D lesson).
if "private const DECLARED_FIELDS" not in cap_source:
    fail(
        "Capabilities.php no longer has `private const DECLARED_FIELDS`, which is the list that "
        "makes every field compulsory. Without it an entry could leave globalAdminFallsOpen out, "
        "and there is no safe default for it: it is true for five capabilities and false for "
        "erasure.review."
    )
if re.search(
    r"\$incomplete\s*=\s*self::whyEntryIncomplete\(\$entry\);\s*\n\s*if \(\$incomplete !== ''\) \{"
    r"[^}]*?throw new \\LogicException\(",
    cap_source,
    re.DOTALL,
) is None:
    fail(
        "Capabilities::definition() no longer asks whyEntryIncomplete() and THROWS on the answer. "
        "That is the only thing stopping an entry that leaves a field out being used with whatever "
        "a missing value happens to read as — and half the registry would then be wrong with "
        "nothing on any page looking different."
    )
for field in REQUIRED_FIELDS:
    if ("'" + field + "'") not in cap_source:
        fail("Capabilities::DECLARED_FIELDS no longer names '" + field + "'.")

# -----------------------------------------------------------------------------
# Q — what the one-request memo is keyed BY
# -----------------------------------------------------------------------------
# ⚠️ IT IS THE ORGANISATION THAT MAKES THE KEY SAFE, and an independent check
#    dropped it on 3 November 2026 with this gate green and the self-test
#    199/199 green, while an organisation that had chosen nothing was served
#    another organisation's chosen set.
#
#    Nothing in production reaches it today: `App::can()` always passes
#    `Site::id()`, and both callers that pass an organisation explicitly pass
#    the same one. But `web/_apps/cron/asset-reminders.php` already walks
#    every organisation with `Site::forceContext()`, inside the very app
#    holding most of the converted gates, so the first scheduled job to ask a
#    capability makes it live — and then the answer is a wrong decision about
#    who may open a register, not a slow page.
#
#    The behaviour is proved by section R of `tools/capabilities-selftest.php`,
#    which asks the two organisations in an order where the scoped and
#    unscoped keys MUST disagree. This is the cheap half: the pre-push hook
#    runs every script in this folder and runs no self-test (#646), so
#    without a pin here the only guard waits for a pull request.
if "$memoKey = (string) $siteId . '|' . $capability;" not in without_comments(cap_source):
    fail(
        "Capabilities::roleKeysInForce() no longer keys its one-request memo by "
        "`(string) $siteId . '|' . $capability`. Drop the organisation from that key and one "
        "organisation's chosen set is handed to the next organisation asked in the same request — "
        "silently, because nothing throws and the answer looks like an ordinary one. If the key "
        "must change shape, change this pin in the same edit and check section R of "
        "tools/capabilities-selftest.php still goes red when the organisation is removed."
    )

# -----------------------------------------------------------------------------
print("Capability registry check (#727)")
print("=" * 72)
print(
    "Offered: "
    + str(len(offered))
    + " ("
    + ", ".join(sorted(offered))
    + ")   Written down, not offered: "
    + str(len(declared))
    + "   Standard roles to choose from: "
    + str(len(standard_roles))
)
print()
# Per capability rather than one long line of paths: with six capabilities over
# fifty files the single line was unreadable, and an unreadable success line is
# how a wrong number goes unnoticed.
for key in sorted(offered):
    asked = string_list(offered[key].get("askedAt", "")) or []
    shipped = string_list(offered[key].get("standard", "")) or []
    print(
        "  "
        + key.ljust(20)
        + "ships with "
        + ("/".join(shipped)).ljust(18)
        + " global admin falls open: "
        + offered[key].get("globalAdminFallsOpen", "?").ljust(6)
        + " asked in "
        + str(len(asked))
        + " file(s)"
    )
print()
print("Files that ask a capability: " + str(len(really_asks)))
print(
    "Still gated the old way on purpose: "
    + (", ".join(sorted(HAS_ROLE_ON_PURPOSE)) if len(HAS_ROLE_ON_PURPOSE) > 0 else "none")
)
print()

# A file that vanished between the glob and the read is SAID OUT LOUD and is
# not a finding. Saying it matters: if a real file were being removed under
# this check again and again, a silent skip would look exactly like a clean
# run. See read_globbed().
if len(skipped) > 0:
    print(
        "ℹ️  "
        + str(len(skipped))
        + " file(s) disappeared between being listed and being read, so nothing was checked in "
        "them. A file that is not there cannot hold a gate, so this is not a finding — but if "
        "you see it on a quiet tree, something is removing files under this check: "
        + ", ".join(skipped[:5])
        + ("…" if len(skipped) > 5 else "")
    )
    print()

if len(findings) == 0:
    print("✅ Nothing wrong.")
    sys.exit(0)

print("❌ " + str(len(findings)) + " finding(s):")
for finding in findings:
    print()
    print("  - " + finding)
print()
sys.exit(1)
