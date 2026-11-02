#!/usr/bin/env python3
"""
Personal-data coverage check.

Finds tables holding something about a person that nobody has decided what to do
with when that person asks to be forgotten, or asks for a copy of their data.

WHY THIS EXISTS
---------------
Two rights sit at the heart of data-protection law, and this portal has to
honour both:

  * somebody may ask for a COPY of everything held about them;
  * somebody may ask for it to be DELETED.

Both need the same thing first: an accurate list of where personal information
actually lives. Without that, both are guesswork.

For a long time the list here was not built, it was REMEMBERED. Tables were
added to `GdprEraser.php` as somebody happened to think of them. On
11 September 2026 the difference was measured: 134 tables hold something
personal, and **77 of them were in neither the deletion list nor the download**.
Two of those (the Noticeboard's) were found by accident, while looking at
something else entirely.

That is not a list. It is a sample. And the gap included a child's allergies and
medical notes, real mobile numbers, and criminal record check information.

WHAT THIS CHECK DOES
--------------------
It reads the database structure, finds every column that holds something about a
person, and fails when a table containing one has not been sorted into a
category in `web/_core/personal-data-catalogue.php`.

It does NOT decide what should happen to a table. A person must do that, because
the four possible answers are genuinely different decisions:

  erase           Delete the rows outright. For data that is ABOUT the person -
                  a visitor record, a registration, a message sent to them.

  unlink          Keep the row, remove the connection to the person. For "who
                  created this": a song, a rota entry, an announcement. Deleting
                  those would destroy the ORGANISATION'S work because a departed
                  volunteer happened to type it in. The content stays, the name
                  goes.

  retain          Keep it, because the law requires it. Gift Aid declarations,
                  financial records, safeguarding records. Each needs a stated
                  reason and a period, so the organisation can show why.

  not-personal    It only LOOKS personal. The map position of a room, a cache of
                  place names. Nothing about a person at all.

WHAT IT CANNOT DO
-----------------
It matches on column NAMES. A column holding personal information under an
unrevealing name will not be spotted, and a column whose name merely sounds
personal will be flagged when it is not. That is why every entry in the
catalogue is written down by a person with a reason attached, rather than
guessed at here.

Exit code:
  0 — every table holding personal data has a decision recorded
  1 — at least one has not (add --strict to fail a build on it)

Usage:
  python3 tools/audit-checks/check_personal_data_coverage.py [--strict]
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
SCHEMA = REPO_ROOT / "web" / "_sql" / "full_schema.sql"
CATALOGUE = REPO_ROOT / "web" / "_core" / "personal-data-catalogue.php"

# Columns that hold something about a person.
#
# Grouped so the reason each is here stays visible. Anchored to the whole column
# name, so "notes" matches but "denotes" does not.
PERSONAL_COLUMNS = re.compile(
    r"^("
    # Who somebody is
    r"firstName|lastName|fullName|displayName|preferredName|parentName|donorName"
    r"|emergencyContactName|caretakerName|contactName|personName"
    # How to reach them
    r"|email|emailAddress|recipientEmail|parentEmail|donorEmail|personEmail|contactEmail"
    r"|phone|mobile|telephone|recipientNumber|parentPhone|emergencyContactPhone"
    r"|caretakerPhone|contactPhone"
    # Where they are
    r"|address|displayAddress|postcode|postCode|latitude|longitude|what3words"
    # Who they are, in a more sensitive sense
    r"|dateOfBirth|dob|birthDate|gender|allergies|medicalNotes"
    # What they did, and from where
    r"|visitorIP|ipAddress|userAgent|sessionID|sessionDataSnapshot|requestHeaders"
    # Free text that routinely ends up being about somebody
    r"|notes|rawNotes|slideNotes|note"
    # A file somebody uploaded
    r"|storedName"
    # A link to an account
    r"|userID|memberID|donorID|submitterID|recipientUserID|createdByID|updatedByID"
    r"|reviewedByID|assignedToID|approverID|leaderID|uploadedByUserID|convertedUserID"
    r"|targetUserID|startedByID"
    # Added by #514 part P7: who approved or declined an outside calendar's
    # event (tblExternalEventApprovals). Named here so that removing that
    # table's catalogue entry later fails this check instead of passing
    # silently.
    r"|decidedByID"
    # Added by #535 (11 October 2026): who pressed Refresh on an outside
    # calendar, who opened a pastoral file, and who changed or scanned an
    # asset. Each is the ONLY personal column on its table, so without the
    # name here that table's catalogue entry could be deleted and this check
    # would still pass.
    r"|triggeredByID|viewerID|actorUserID"
    # Added by roll-out package A2a (11 October 2026). Thirteen tables that
    # only say WHO DID SOMETHING had no catalogue entry, and this check could
    # not see them, because none of these names was here. Each now has an
    # entry (decision: unlink), and each name is here so that removing one of
    # those entries fails this check instead of passing silently.
    #
    # `uploadedByID` used to be left out on purpose: three tables that use it
    # had no entry, and that gap was waiting for the owner's decision. The
    # decision was "keep the record, clear who did it", the three have
    # entries, and the name is in.
    r"|uploadedByID|actedByID|contactedByID|preparedByID|postedByID|sentByID"
    r"|operatorID|importedByID|matchedByID|paidByID|checkedInByID|checkedOutByID"
    # Four the database does not link to the account table, found by the
    # independent check of 12 October 2026. `executedByID` is why the
    # upgrade record (tblMigrations) now has an entry: without the name here,
    # that entry could be deleted and this check would still pass.
    r"|submittedByID|statusChangedByID|resolvedByID|executedByID"
    # Added by roll-out package A2b (12 October 2026): who a counted envelope
    # was from. `tblCountEnvelopes` had no catalogue entry and this check
    # could not see it, because neither name was here (`giverName` is a name
    # typed by hand when the envelope matched no member).
    r"|giverID|giverName"
    # Added by mail package M3 (13 October 2026, #592): a LIST of e-mail
    # addresses, separated by commas. It is the only thing that ties a row of
    # the send log (`tblEmailLog`) or of the mail queue (`tblMailQueue`) to a
    # person, so without the name here either table's catalogue entry could
    # be deleted and this check would still pass. (The send log had no entry
    # at all until then, for exactly that reason.) The mail queue's files
    # table is caught by `storedName`, further up.
    r"|toRecipients"
    r")$",
    re.IGNORECASE,
)

VALID_DECISIONS = {"erase", "unlink", "retain", "not-personal"}


def schema_tables() -> dict[str, list[str]]:
    """Every table in the fresh-install script, with its columns."""
    if not SCHEMA.is_file():
        return {}
    text = SCHEMA.read_text(encoding="utf-8", errors="replace")
    text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
    text = re.sub(r"--[^\n]*", "", text)

    tables: dict[str, list[str]] = {}
    for m in re.finditer(
        r"CREATE TABLE IF NOT EXISTS\s+`([A-Za-z0-9_]+)`(.*?)\n\)\s*ENGINE", text, re.S
    ):
        name, body = m.group(1), m.group(2)
        cols = re.findall(r"^\s+`([A-Za-z0-9_]+)`", body, re.M)
        tables.setdefault(name, [])
        tables[name].extend(c for c in cols if c not in tables[name])
    return tables


def catalogue_decisions() -> dict[str, str]:
    """What the catalogue says about each table.

    Read as text rather than by running the PHP, so this check needs no PHP and
    can run anywhere.

    🛑 IT USES catalogue_blocks(), NOT ITS OWN REGEX, and that is a fix rather
    than tidying. The old pattern required `'decision'` to follow the opening
    bracket with only whitespace between, so it MISSED any entry carrying a
    comment on that line — two of them (`tblExpenseClaimItems` and
    `tblReadingPlanProgress`, both of which explain there why their decision
    reads oddly). Measured: the regex saw 226 entries where PHP sees 228, so two
    were outside every rule below AND the success line printed a count that was
    two short. The file's own long comment on tblAnonymousCheckins warns about
    exactly this trap, which is how it was found.
    """
    out: dict[str, str] = {}
    for table, block in catalogue_blocks().items():
        hit = re.search(r"'decision'\s*=>\s*'([a-z-]+)'", block)
        if hit is not None:
            out[table] = hit.group(1)
    return out


# =============================================================================
# 🧬 #706 — THE SHAPE RULE, AND WHY THE LIST ABOVE WAS NOT ENOUGH
# =============================================================================
# PERSONAL_COLUMNS above is a hand-maintained list of LITERAL column names. Its
# own comments record eleven separate occasions on which somebody had to
# remember to extend it ("Added by #514 part P7", "Added by #535", "Added by
# roll-out package A2a", "Added by mail package M3"). So it can only ever find
# a column somebody had already thought of — and on 2 November 2026 that was
# measured: THIRTY-SEVEN columns holding a person's details were named nowhere
# in the catalogue, and this check was green over all of them. Among them:
#
#   tblUsers.phoneNumber        the list has `phone`, anchored whole
#   tblUsers.displayPhone       not in the list
#   tblSmallGroups.addressLine1 the list has `address`, anchored whole
#   tblEventAttendance.walkinName   nine specific *Name columns are listed; not this
#   tblPasswordResets.createdIP     only `visitorIP` and `ipAddress` are listed
#   tblPrayerRequests.submitterEmail  six specific *Email columns; not this
#
# That is the #645 shape one level up: a guard that LOOKS schema-driven while
# being driven by somebody's memory. The rule below asks about the SHAPE of a
# name instead, so a column nobody thought of is still found.
#
# WHY IT IS A WHOLE-WORD TEST, which matters more than it sounds. "contains the
# word name" is useless: it matches categoryName, deptName, typeName,
# themeName, tuneName, eventName, locationName, groupName, seriesName — none a
# person. A first draft flagged 283 columns across 150 tables, nearly all
# classifications. So the column name is split into WORDS, and a `*Name` column
# counts only when its qualifier says WHOSE. The same lesson as
# check_limit_settings_capped.py, and #483's: A CHECK THAT CRIES WOLF GETS
# SWITCHED OFF, AND THEN IT CATCHES NOTHING.
#
# THE LITERAL LIST STAYS, and the two halves now cross-pollinate: a column
# matching PERSONAL_COLUMNS is reported by the column-level rule too. Without
# that, `fullName`, `caretakerName`, `giverName`, `firstName` and `lastName`
# were known to the table-level half and invisible to the column-level one —
# and now that every table has an entry, the table-level half can only fire for
# a brand-new table, so that is where the next gap would have come from.
# Pointed out by the independent check of 2 November 2026.

CONTACT_WORDS = {
    "email", "mail", "phone", "telephone", "mobile",
    "address", "postcode", "postalcode", "dob", "nhs", "ip",
}

# Whose name is it? Only these make a `*Name` column a person's name.
PERSON_QUALIFIERS = {
    "reporter", "submitter", "counterparty", "walkin", "external", "contact",
    "guardian", "pickup", "presenter", "emergency", "donor", "member",
    "speaker", "author", "attendee", "applicant", "nominee", "recipient",
    "sender", "parent", "child", "performed", "nominated", "referrer",
}

FREE_TEXT_TYPES = {"text", "mediumtext", "longtext", "tinytext", "json", "blob"}
# A varchar this long is a box somebody types prose into, not a code or a slug.
PROSE_LENGTH = 200
# Shorter than this cannot hold an address or an e-mail, so a contact-shaped
# name on it is a flag or a code (`visibilityPhone`, `emailOutcome`, `emailAt`).
#
# WHAT THAT COSTS, measured: a `VARCHAR(10)` postcode column would not be
# flagged. Every real postcode column in this schema today is `VARCHAR(20)`,
# and the floor excludes only eight columns in the whole schema, all genuinely
# not personal (`emailLogID`, `visibilityPhone`, `ipCountry`, …). A `DATE`
# date-of-birth column is also missed, because the test is on text-holding
# types — but `dateOfBirth` and `dob` are in PERSONAL_COLUMNS, which the rule
# now also consults, so that one is covered after all.
HOLDS_TEXT_MIN = 12

# Nothing is exempt, and it should stay that way. Before adding a line, answer
# why the column cannot simply be named in the catalogue with a reason. Fails
# both ways: an entry here that is no longer true is also an error, because a
# stale exemption is where a real column hides.
#
# It is empty, so the stale-exemption clause below cannot fail today. That is a
# forward guard, not a tested one, and this comment is the honest label for it.
NAMED_ELSEWHERE_ON_PURPOSE: dict[str, str] = {}


def column_words(name: str) -> list[str]:
    """`pickupAuthorisedNames` -> ['pickup', 'authorised', 'names']."""
    spaced = re.sub(r"([a-z0-9])([A-Z])", r"\1 \2", name)
    return [w for w in re.split(r"[^A-Za-z0-9]+", spaced.lower()) if w != ""]


def why_personal_shape(column: str, type_name: str, length: int) -> str | None:
    """Why this column is shaped like a person's details, or None."""
    # The hand-written list first, so the two halves agree about what a personal
    # column is. It is matched whole and is case-insensitive.
    if PERSONAL_COLUMNS.match(column) is not None:
        return "on the list of personal column names"
    holds_text = (
        type_name in FREE_TEXT_TYPES
        or (type_name in {"varchar", "char"} and length >= HOLDS_TEXT_MIN)
    )
    if holds_text is False:
        return None
    words = set(column_words(column))
    contact = words & CONTACT_WORDS
    if contact != set():
        return "contact details (" + "/".join(sorted(contact)) + ")"
    if ({"name", "names"} & words) != set():
        whose = words & PERSON_QUALIFIERS
        if whose != set():
            return "a person's name (" + "/".join(sorted(whose)) + ")"
    return None


def schema_column_types() -> dict[str, dict[str, tuple[str, int]]]:
    """table -> {column: (type, length)}. Needed because the shape rule asks
    whether a column can actually HOLD text."""
    if not SCHEMA.is_file():
        return {}
    text = SCHEMA.read_text(encoding="utf-8", errors="replace")
    text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
    text = re.sub(r"--[^\n]*", "", text)
    out: dict[str, dict[str, tuple[str, int]]] = {}
    for m in re.finditer(
        r"CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`([A-Za-z0-9_]+)`(.*?)\n\)\s*ENGINE",
        text, re.S,
    ):
        cols: dict[str, tuple[str, int]] = {}
        for line in m.group(2).splitlines():
            hit = re.match(r"\s+`([A-Za-z0-9_]+)`\s+([a-z]+)(?:\((\d+)[^)]*\))?", line, re.I)
            if hit is None:
                continue
            # 🔑 A table's OWN auto-increment key is never personal, whatever it
            #    is called. `sessionID` is on the literal list because a USER's
            #    session identifier is personal — but it is also the primary key
            #    of tblAttendanceSessions and tblLivestreamSessions, and
            #    reporting those asked for a reason to be written against a row
            #    number. The same exclusion check_series_attachments_complete.py
            #    makes for a table whose own key is called `eventID`.
            if "AUTO_INCREMENT" in line.upper():
                continue
            cols[hit.group(1)] = (hit.group(2).lower(), int(hit.group(3) or 0))
        out[m.group(1)] = cols
    return out


def catalogue_blocks() -> dict[str, str]:
    r"""table -> the text of its entry.

    🛑 THIS DOES NOT COUNT BRACKETS, AND THAT IS THE WHOLE POINT. The first
    version walked brackets from `'tblX' => [`, and it was BROKEN THE DAY IT WAS
    WRITTEN. `personal-data-catalogue.php` line 182 holds, inside a COMMENT, the
    text `('tblXxx' => [ \s* 'decision' => '...')` — one unbalanced `[`. So
    tblAnonymousCheckins' "entry" ran from line 162 to line 2352: 144,494 of the
    file's 153,340 characters. Its set of named columns absorbed EVERY other
    entry's `notEmptiedHere` keys, sixty of them, so a contact-shaped column
    added to that table was invisible whenever its name appeared anywhere else
    in the file. Proved by planting: `postcode` on tblAnonymousCheckins passed
    while `postcode` on tblVenueRooms was caught, in the same run.

    The irony is worth keeping: that comment is itself a note about an earlier
    time this file broke this very parser.

    Stripping comments first was the obvious repair and is WRONG — a reason may
    legitimately contain `//`, as any URL does, so stripping would eat real
    text. Entries are top-level and uniformly indented, so each simply runs from
    its own header to the next header, or to the end. Nothing inside an entry —
    a comment, an apostrophe, a bracket, a nested array — can move that
    boundary.
    """
    if not CATALOGUE.is_file():
        return {}
    text = CATALOGUE.read_text(encoding="utf-8", errors="replace")
    heads = list(re.finditer(r"\n    '(tbl[A-Za-z0-9_]+)' => \[\n", text))
    out: dict[str, str] = {}
    for n, head in enumerate(heads):
        stop = heads[n + 1].start() if n + 1 < len(heads) else len(text)
        out[head.group(1)] = text[head.end():stop]
    return out


def catalogue_named_columns() -> dict[str, set[str]]:
    """table -> the column names its entry NAMES, from `columns` and the keys of
    `notEmptiedHere`, lowercased.

    Read as TEXT, like catalogue_decisions(), so this check still needs no PHP
    and runs anywhere — which is why it can sit in the pre-push hook at all.
    Five other checks in this folder do shell out to php; this one deliberately
    does not.
    """
    out: dict[str, set[str]] = {}
    for table, block in catalogue_blocks().items():
        named: set[str] = set()
        cols = re.search(r"'columns'\s*=>\s*\[([^\]]*)\]", block)
        if cols is not None:
            named |= {c.lower() for c in re.findall(r"'([A-Za-z0-9_]+)'", cols.group(1))}
        nee = re.search(r"'notEmptiedHere'\s*=>\s*\[(.*)", block, re.S)
        if nee is not None:
            named |= {
                c.lower()
                for c in re.findall(r"^\s+'([A-Za-z0-9_]+)'\s*=>", nee.group(1), re.M)
            }
        out[table] = named
    return out


def shape_findings() -> tuple[list[tuple[str, str, str]], list[tuple[str, str, str]], list[str]]:
    """(columns that must be named and are not, the free-text inventory, stale exemptions)."""
    types = schema_column_types()
    named = catalogue_named_columns()
    missing: list[tuple[str, str, str]] = []
    free_text: list[tuple[str, str, str]] = []
    exempt_used: set[str] = set()
    for table, cols in sorted(types.items()):
        for column, (type_name, length) in cols.items():
            if column.lower() in named.get(table, set()):
                continue
            key = f"{table}.{column}"
            reason = why_personal_shape(column, type_name, length)
            if reason is not None:
                if key in NAMED_ELSEWHERE_ON_PURPOSE:
                    exempt_used.add(key)
                else:
                    missing.append((table, column, reason))
                continue
            if (type_name in FREE_TEXT_TYPES
                    or (type_name == "varchar" and length >= PROSE_LENGTH)):
                shown = type_name + (f"({length})" if length > 0 else "")
                free_text.append((table, column, shown))
    stale = sorted(set(NAMED_ELSEWHERE_ON_PURPOSE) - exempt_used)
    return missing, free_text, stale


def check() -> int:
    tables = schema_tables()
    if not tables:
        print("check_personal_data_coverage: could not read the schema — skipping.")
        return 0

    decisions = catalogue_decisions()

    if not CATALOGUE.is_file():
        print(
            "check_personal_data_coverage: no catalogue yet at\n"
            f"  {CATALOGUE.relative_to(REPO_ROOT)}\n"
            "Nothing to check against. Create it before relying on this."
        )
        return 0

    undecided: list[tuple[str, list[str]]] = []
    bad_decision: list[tuple[str, str]] = []

    for table in sorted(tables):
        personal = [c for c in tables[table] if PERSONAL_COLUMNS.match(c)]
        if not personal:
            continue
        if table not in decisions:
            undecided.append((table, personal))
            continue
        if decisions[table] not in VALID_DECISIONS:
            bad_decision.append((table, decisions[table]))

    # A table named in the catalogue that no longer exists is worth knowing
    # about: it usually means a rename happened and the decision was left behind
    # pointing at nothing.
    ghosts = sorted(t for t in decisions if t not in tables)

    # 🧬 #706: the column-level half. The table having a decision is not enough
    #    if the decision never mentions the column holding the person's details.
    unnamed, free_text, stale_exempt = shape_findings()

    # The inventory. PRINTED, NEVER FATAL — the owner's decision of 2 November
    # 2026. A text column can hold anything, and most of these hold nothing
    # personal (`eventSlug`, a song title, a room name) while a few really do
    # (`tblAuditTrail.oldValue`, `tblPrayerRequests.testimony`). Gating all of
    # them would mean hundreds of written reasons, most of them boilerplate, and
    # filler prose nobody reads is a cost rather than coverage. They are worked
    # down in later packages, each with its own issue.
    #
    # ⚠️ SO READ IT. A green run means every CONTACT-SHAPED column is named in
    #    the catalogue. It does NOT mean every personal column is.
    if free_text:
        print(f"check_personal_data_coverage: {len(free_text)} free-text column(s) the "
              f"catalogue does not name.")
        print("  Not a failure (see the note in the code). A green run means every")
        print("  contact-shaped column is named, not that every personal one is.\n")

    if not undecided and not bad_decision and not ghosts and not unnamed and not stale_exempt:
        covered = len(decisions)
        print(
            f"check_personal_data_coverage: OK — every table holding personal data "
            f"has a decision recorded ({covered} in the catalogue), and every "
            f"contact-shaped column is named in its entry."
        )
        return 0

    print("check_personal_data_coverage: PERSONAL DATA WITH NO DECISION RECORDED\n")

    if unnamed:
        print(f"{len(unnamed)} column(s) hold a person's details and are named in NO")
        print("catalogue entry — so nothing erases them, and nothing includes them in")
        print("the copy a person is entitled to ask for:\n")
        for table, column, reason in unnamed:
            print(f"  • {table}.{column} — {reason}")
        print()
        print("  Name each one in its table's entry in")
        print("  web/_core/personal-data-catalogue.php: in `columns` if the erasure acts")
        print("  on it, and in `notEmptiedHere` with the reason if it is kept. Naming it")
        print("  is what makes it visible; the decision itself is yours.")
        print()

    if stale_exempt:
        print(f"{len(stale_exempt)} exemption(s) in NAMED_ELSEWHERE_ON_PURPOSE are no")
        print("longer true — the column is gone, renamed, or now named. Remove the line:\n")
        for key in stale_exempt:
            print(f"  • {key}")
        print()

    if undecided:
        print("These tables hold something about a person, and nobody has said what")
        print("should happen to it when that person asks to be forgotten:\n")
        for table, cols in undecided:
            shown = ", ".join(cols[:8])
            more = f", and {len(cols) - 8} more" if len(cols) > 8 else ""
            print(f"  • {table}")
            print(f"      {shown}{more}")
        print()
        print("  Add each to web/_core/personal-data-catalogue.php with one of:")
        print("    'erase'        delete the rows — data ABOUT the person")
        print("    'unlink'       keep the row, remove the person's name — 'who made this'")
        print("    'retain'       keep it, with a legal reason and a period")
        print("    'not-personal' it only looks personal — a map position, a cache")
        print()

    if bad_decision:
        print("These have a decision that is not one of the four allowed:\n")
        for table, decision in bad_decision:
            print(f"  • {table}: '{decision}'")
        print()

    if ghosts:
        print("These are in the catalogue but no longer exist in the database.")
        print("Usually a rename that left its decision behind:\n")
        for table in ghosts:
            print(f"  • {table}")
        print()

    return 1


if __name__ == "__main__":
    sys.exit(check())
