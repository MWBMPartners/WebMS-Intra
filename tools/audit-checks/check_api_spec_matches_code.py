#!/usr/bin/env python3
"""
The API description agrees with the code that answers it (#641).

Path: tools/audit-checks/check_api_spec_matches_code.py

WHY THIS EXISTS
---------------
`web/_core/api-spec.json` is what a customer's developer reads before writing
anything against this portal, and `web/public_html/api-docs/` serves it as a
browsable page. Nothing in this repository had ever compared it with the code,
so the two drifted for as long as the file has existed: on 30 October 2026,
17 endpoints that answer real requests appeared nowhere in it; two more were
described only under their `api/v1/` address while three operations stated in so
many words that their legacy address did not exist, when it does. (19 legacy
addresses are documented as a result -- that is the number of addresses added,
not the number that were missing.) Neither is visible by reading either file on its own -- you have to hold
both at once, which is exactly what a check is for.

The failure is quiet and it lands on somebody outside this team. An endpoint
missing from the description is one a developer never learns about; an endpoint
described but not built is one they write code against and cannot call. #646 is
the rule being followed here: a correct statement that nothing enforces is a
statement that goes stale.

WHAT IT CHECKS
--------------
A  Every reachable endpoint is documented, as its own path or as the
   `**Legacy alias:**` of an `api/v1/` operation.
B  Every documented address resolves to a handler file that is really on disk.
C  No operation claims `**Legacy alias:** none` while a reachable legacy
   handler exists. That exact sentence was untrue for the three user write
   operations.
D  The `api/v1/` resources in the description and in `ApiRouter` are the same
   set, both ways round.
E  No documented path has a shape `ApiRouter` cannot answer.
F  No handler file sits where the router can never reach it.
G  Every handler on disk has its `api.{app}.{action}.enabled` row seeded --
   unless check I accounts for it.
H  Every seeded `api.*.enabled` row has a handler file.
I  Every handler in an `api` folder that is reached as an ORDINARY PAGE ROUTE
   instead, through `tblRoutes`, is named and explained. Such a file answers
   requests with neither of ApiRouter's two gates in front of it, so it is the
   one position where the folder name misleads a reader.

Note G is about ONE of two gates. `ApiRouter::dispatch()` asks the app's own
switch first, through `AppRegistry::API_FOLDER_APPS`, and a folder with no line
there is refused for everybody; `tools/app-switch-selftest.php` is what covers
that list. So a seeded switch is necessary for an address to work, not
sufficient.

G, H and I are about whether an address works at all, not about the description,
and they are here because this is the one check that already holds the handler
list and the seed list side by side. Without the seed `ApiRouter` answers 403
to everybody, so a handler with no row is unreachable however well documented,
and a row with no handler is a switch for nothing.

HOW IT DECIDES WHAT IS REACHABLE -- from the router's own rules, not a list
--------------------------------------------------------------------------
`ApiRouter::dispatch()` requires EXACTLY three parts (`api/{app}/{action}`)
and checks each of the last two against `^[a-z0-9\\-]+$`. So a file counts as
a handler here only when it sits at `_apps/{app}/api/{action}.php` and both
names match that same pattern.

**That is why there is no exemption list for helper files, and it is the whole
reason the rule is expressed this way.** `_apps/assets/api/_coerce.php` is
shared code that `create.php` and `update.php` require; its leading underscore
puts it outside the router's pattern deliberately, and its own header says so.
A check carrying a hand-written list of "files that are not really endpoints"
would need a new line every time somebody added a helper, and would go red on
the day they forgot. Reading the router's pattern instead means the convention
that already makes the file unreachable is the same thing that excludes it here.

The `api/v1/` facade is resolved through the four tables in `ApiRouter`
(`V1_RESOURCES` plus the create/update/delete name overrides), read out of the
file rather than copied. Without that, check B would have reported
`noticeboard/save` and `leadership/assign` as documented-but-missing, because
the facade reaches them under names no path key spells out. A check that cries
wolf gets switched off, and then it catches nothing.

Check A also counts a `**Legacy alias:**` line as documentation, because that
is a convention the file already uses for 40 legacy addresses.

**Said honestly, because the first version of this paragraph was a false
measurement and the independent check caught it: today that parse changes
NOTHING.** It claimed ignoring aliases would report 28 undocumented endpoints
against a true figure of 19. The 28 came from a different model -- counting only
`paths` keys and not resolving the facade at all -- and does not apply here.
Measured against this check's own sets, `aliases - targets` is EMPTY, and
removing the parse entirely leaves the check green. The reason is structural: a
`**Legacy alias:** POST /api/x/y` line sits inside the v1 operation that already
resolves to that same (app, action) pair, so it can never contribute a pair
`targets` lacks.

It is kept for the one case it would catch -- an alias naming a pair no v1 path
resolves to, which the convention does not currently produce -- and NOT because
it is load-bearing today. Check C's separate reading of
`**Legacy alias:** none` is load-bearing and is proved by planting.

WHAT THIS CANNOT SEE, said plainly because an overstated guarantee is worse
than none
--------------------------------------------------------------------------
1. **Whether the description of an endpoint is TRUE.** It compares which
   addresses exist, not what they say about themselves. A documented parameter
   the handler ignores, a response field never sent, a wrong status code or a
   stale example: all pass. Check C is the one exception, and it works only
   because "Legacy alias: none" happens to be a claim about an address.
2. **Which HTTP verbs a handler really accepts.** It does not try: the verb a
   legacy handler allows is decided by `ApiAuth::requireMethod()` at run time,
   and `PORTAL_API_V1` deliberately relaxes a POST-only guard for a valid
   PUT/PATCH/DELETE through the facade. Reading that out of the text of a file
   would be wrong in both directions.
3. **A per-organisation switch.** Check G reads the install script's
   installation-wide seed. An administrator can still switch an endpoint off
   for one organisation, which is the point of the switch and not a fault.
4. **Whether a documented address is reachable for the CALLER.** Access is the
   handler's business; this is about whether the address exists.
5. **A seed added by a numbered migration rather than the install script.**
   It reads `full_schema.sql` only. The two must agree anyway -- that is the
   rule in `.claude/rules/database.md`, and `check_schema_seed_parity.py` is
   what enforces it -- so reading one of them is enough here.

USAGE
    python3 -I tools/audit-checks/check_api_spec_matches_code.py
    python3 -I tools/audit-checks/check_api_spec_matches_code.py --list

Package:   WebMS-Intra
Author:    MWBM Partners Ltd (t/a MWservices)
Copyright: All Rights Reserved
Version:   1.0.0
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
WEB = REPO / "web"
APPS = WEB / "_apps"
SPEC = WEB / "_core" / "api-spec.json"
ROUTER = WEB / "_core" / "ApiRouter.php"
SCHEMA_SQL = WEB / "_sql" / "full_schema.sql"

# 🛡️ The router's own name rule, from ApiRouter::dispatch(). One constant
#    because checks A, B and F all have to agree with it.
NAME = re.compile(r"^[a-z0-9\-]+$")

# 🩺 Paths in the description that are not `api/` addresses and so have no
#    handler to find. `/health` is answered by the router itself.
# 📄 Addresses the description carries that ApiRouter does not serve, so the
#    handler comparison must skip them. Each is here for a different reason and
#    neither is a loophole:
#
#      /health              — the router answers it itself.
#      /expenses/api/export — an ordinary page route (#675). It is in an `api`
#                             folder and it is NOT an API endpoint; check I is
#                             what proves the route and the file behind it still
#                             exist, and `api-spec.schema.json` spells the
#                             address out in full so a misspelling cannot slip
#                             past the skip.
#
#    Skipping means checks A to E say nothing about these two, which is why the
#    cover has to come from somewhere else. Do not add a path here without
#    saying, as above, what then guards it.
NOT_API_PATHS = {"/health", "/expenses/api/export"}

# 📂 Files in an `api` folder the router can never reach, each with the reason
#    it is still here. An UNLISTED one fails, and so does a listed one that has
#    gone -- a named exception that has stopped being true is how a stale list
#    quietly stops guarding anything.
#
#    Both of these were left at `_apps/api/` by the #159 move of app pages out
#    of the web root; their own headers still say `Path: public_html/api/...`.
#    `api/translate` is two parts, and dispatch() refuses anything that is not
#    exactly three, so neither has answered a request since that move. They are
#    the member-facing half of content translation (#485), which ships switched
#    off and is documented as not reachable. Deleting them is that issue's
#    decision, not this check's.
UNREACHABLE_ON_PURPOSE = {
    "_apps/api/ai-improve.php": "#485 — content translation is not reachable; unreachable since the #159 move.",
    "_apps/api/translate.php": "#485 — content translation is not reachable; unreachable since the #159 move.",
}

# 🔌 Handlers with no `api.{app}.{action}.enabled` row, so ApiRouter answers
#    403 to everybody. Same rule as above: unlisted fails, and so does a listed
#    one that has been seeded or deleted.
#    EMPTY since 30 October 2026 (#675). It used to name `expenses/export`,
#    on the strength of a claim that turned out to be wrong in both halves:
#    the handler is reached through `tblRoutes` as an ordinary page route
#    (`expenses/api/export`), where no `api.*.enabled` row applies, and it
#    answers a real CSV today. Check I is what now accounts for it. Nothing
#    may be added here without measuring the endpoint first.
UNSEEDED_ON_PURPOSE: dict[str, str] = {}

# 🪧 Seeded switches with no handler file: a switch for nothing.
#
#    ALSO EMPTY since 30 October 2026 (#675), and the reason is the lesson of
#    this whole package. It used to name four `api.expenses.*` rows as seeded
#    with no handler. They are NOT seeded: migration 186 deletes all four, and
#    `full_schema.sql` folds that DELETE in AFTER every one of their INSERTs.
#    Measured on a real MySQL 8.0.36 loaded from the install script, a fresh
#    database holds exactly three `api.expenses.*` rows -- create, delete,
#    list. The four were an artefact of seeded_switches() reading the file with
#    a plain regex, which matched the names INSIDE the DELETE statement and
#    counted each as a seed. See that function for the fix and for the
#    dangerous direction of the same blindness.
ORPHAN_SEEDS_ON_PURPOSE: dict[str, str] = {}

# 🛣️ Handler files in an `api` folder that are reached as ORDINARY PAGE ROUTES
#    through `tblRoutes`, not through ApiRouter.
#
#    This category exists because the check used to assume that a file at
#    `_apps/{app}/api/{action}.php` could only ever be an ApiRouter endpoint.
#    `Router::dispatch()` looks up `PORTAL_APPS` first, so a `tblRoutes` row
#    whose `targetFile` is `{app}/api/{action}.php` reaches exactly the same
#    file at a completely different address -- and at that address NEITHER the
#    `api.{app}.{action}.enabled` row NOR the app's own API-folder switch is
#    consulted. So such a file is reachable while check G says it is dead.
#
#    They are listed rather than waved through BECAUSE of that difference: a
#    page-routed handler in an `api` folder has two fewer gates in front of it
#    than its position suggests, and anybody reading the folder name will
#    assume otherwise. A new one should be a deliberate decision, not a
#    discovery.
PAGE_ROUTED_ON_PURPOSE = {
    "expenses/export": "#675 — the Expenses CSV download. Route `expenses/api/export` "
                       "→ `expenses/api/export.php`, with a live button on "
                       "`expenses/approve`. It gates itself on App::isAdmin() or the "
                       "`approver` role and scopes its query to Site::id(); it is a "
                       "browser download, not part of the REST API, which is why "
                       "`api.expenses.export.enabled` is deliberately NOT seeded "
                       "(owner, 30 October 2026).",
}

VERBS = ("get", "post", "put", "patch", "delete")

# 📖 The convention the description already uses to name a legacy address, and
#    the sentence that denies there is one.
ALIAS_RE = re.compile(r"\*\*Legacy alias:\*\*\s*`?([A-Z]+)\s+/api/([a-z0-9\-]+)/([a-z0-9\-]+)")
ALIAS_NONE_RE = re.compile(r"\*\*Legacy alias:\*\*\s*none", re.IGNORECASE)


# ---------------------------------------------------------------------------
# Reading the router's tables
# ---------------------------------------------------------------------------

def php_map(text: str, const: str) -> dict[str, str] | None:
    """
    One `private const NAME = [ 'a' => 'b', ... ];` table out of ApiRouter.php.

    Deliberately NOT a PHP parser: these four tables are plain lists of quoted
    pairs. Comments are stripped FIRST so a commented-out line cannot be read
    as live -- without that, deleting a resource by commenting it out would
    leave this check believing it was still there.

    Returns None when the constant is not declared at all (so a rename is a
    failure rather than a silently empty table), and {} when it is declared
    with no entries.
    """
    m = re.search(const + r"\s*=\s*\[(.*?)\];", text, re.S)
    if m is None:
        return None
    body = re.sub(r"/\*.*?\*/", "", m.group(1), flags=re.S)
    body = re.sub(r"//[^\n]*", "", body)
    return dict(re.findall(r"'([a-z0-9\-]+)'\s*=>\s*'([a-z0-9\-]+)'", body))


def v1_tables() -> tuple[dict[str, str], dict[str, str], dict[str, str], dict[str, str]] | str:
    """The four tables, or a sentence saying which one could not be read."""
    text = ROUTER.read_text(encoding="utf-8", errors="replace")
    out = []
    for const in ("V1_RESOURCES", "V1_CREATE_ACTION", "V1_UPDATE_ACTION", "V1_DELETE_ACTION"):
        table = php_map(text, const)
        if table is None:
            return ("ApiRouter::%s is not declared in %s. Every `api/v1/` address is "
                    "resolved through these four tables, so this check cannot run "
                    "without it." % (const, ROUTER.relative_to(REPO)))
        out.append(table)
    if not out[0]:
        return ("ApiRouter::V1_RESOURCES is empty, which would make every `api/v1/` "
                "address a 404. If that is really intended, this check needs changing "
                "on purpose.")
    return (out[0], out[1], out[2], out[3])


def v1_target(resource: str, method: str, has_id: bool,
              resources: dict[str, str], create: dict[str, str],
              update: dict[str, str], delete: dict[str, str]) -> tuple[str, str] | None:
    """
    The (app, action) an `api/v1/{resource}` request lands on, following
    ApiRouter::v1Action() exactly. None when the router refuses that verb and
    id shape, or knows no such resource.
    """
    app = resources.get(resource)
    if app is None:
        return None
    if method == "get":
        return (app, "detail" if has_id else "list")
    if method == "post":
        return None if has_id else (app, create.get(resource, "create"))
    if method in ("put", "patch"):
        return (app, update.get(resource, "update")) if has_id else None
    if method == "delete":
        return (app, delete.get(resource, "delete")) if has_id else None
    return None


# ---------------------------------------------------------------------------
# Reading the code and the install script
# ---------------------------------------------------------------------------

def handlers_on_disk() -> tuple[set[tuple[str, str]], list[str], list[str]]:
    """
    (reachable handlers, helper files, files the router can never reach).

    EVERY `.php` file under `_apps` with a path segment named exactly `api` is
    considered, at whatever depth, and then judged against the ONE position the
    router can reach: `_apps/{app}/api/{action}.php`. Anything else holding an
    `api` segment is unreachable.

    **Looking at only two shapes was not enough, and this is the fault the
    check exists to catch.** The first version globbed `*/api/*.php` and
    `api/*.php`, so a file at `_apps/api/{sub}/{action}.php` was invisible --
    and that is exactly where `push/subscribe`, `push/unsubscribe`,
    `tours/active` and `tours/complete` all sat until #322 and #363 moved them,
    as those handlers' own headers still record. A file at
    `_apps/{app}/{sub}/api/{action}.php` was invisible too. So the one check
    meant to notice an endpoint parked where nothing can reach it would have
    missed all three real cases this repository has had. Found by the
    independent check of 30 October 2026 by planting both shapes.

    An ordinary page is NOT flagged: a path with no `api` segment is not
    looked at here at all, because it is not pretending to be an endpoint.
    """
    reachable: set[tuple[str, str]] = set()
    helpers: list[str] = []
    unreachable: list[str] = []
    for php in sorted(APPS.rglob("*.php")):
        parts = php.relative_to(APPS).parts
        if "api" not in parts:
            continue
        rel = php.relative_to(WEB).as_posix()
        # The one reachable shape: {app}/api/{action}.php -- exactly three parts.
        if len(parts) == 3 and parts[1] == "api":
            app, action = parts[0], php.stem
            if NAME.match(app) is None:
                unreachable.append(rel)
            elif NAME.match(action) is None:
                helpers.append(rel)
            else:
                reachable.add((app, action))
        else:
            unreachable.append(rel)
    return reachable, helpers, unreachable


def read_install_script() -> tuple[str, list[int]]:
    """
    The install script, with every COMMENT blanked out, and the offsets of the
    semicolons that really end a statement.

    WHY THIS EXISTS — a fault the independent check found on 30 October 2026,
    in the very function that was written to close this shape of hole (#675).

    The first version of seeded_switches() ended a `DELETE` statement at the
    next `;` found by `str.find`. Its docstring claimed that a semicolon
    appearing inside the statement would make the check OVER-report, "the
    over-reporting direction, not the silent one". **That was wrong**, and it
    does not even need a string literal — a `--` comment is enough, which is
    this file's own style throughout. These two differ by one character:

        DELETE FROM `tblSettings`
        -- these are gone; nothing reads them any more
        WHERE `settingKey` IN ('api.events.create.enabled');

        DELETE FROM `tblSettings`
        -- these are gone and nothing reads them any more
        WHERE `settingKey` IN ('api.events.create.enabled');

    The second correctly fails check G. The FIRST passes: the semicolon in
    "gone;" ends the span early, so the key sits outside it and is read as a
    SEED. `web/_apps/events/api/create.php` is on disk, so the check then
    reports a healthy endpoint that in fact answers 403 to every caller. That
    is the silent direction, on the exact fault the rewrite exists to catch.
    Measured both ways before this was written.

    So the text is scanned ONCE, character by character, tracking whether we
    are in code, a quoted literal, a quoted identifier or a comment:

      * every comment's characters become spaces, same length, so offsets
        found in the result still point at the right place in the original.
        **Anything quoted inside a comment therefore disappears**, which fixes
        a second finding from the same check: a commented-out route literal
        used to be read as a live route, which made check G stop guarding a
        handler that had really become unreachable.
      * a `;` is recorded as ending a statement only when it is in CODE.

    MySQL's own rules, because guessing them would defeat the point:
      * `--` starts a comment only when followed by whitespace or the end of
        the input. `a--b` is not a comment, and the file contains `-----` rules
        inside comments that must not be mistaken for anything else.
      * `#` starts a comment anywhere in code. It must NOT when quoted: the
        install script seeds colours such as `'#0f766e'`, and treating that as
        a comment would blank the rest of the line.
      * inside a `'...'` literal, `''` is one quote and `\\` escapes the next
        character. Both appear in this file.

    @return (masked text, sorted offsets of the statement-ending semicolons)
    """
    text = SCHEMA_SQL.read_text(encoding="utf-8", errors="replace")
    out: list[str] = []
    ends: list[int] = []
    i, size = 0, len(text)
    while i < size:
        ch = text[i]

        # --- a comment: blank it, keeping the length -----------------------
        if ch == "-" and text.startswith("--", i) and (i + 2 >= size or text[i + 2] in " \t\r\n"):
            stop = text.find("\n", i)
            stop = size if stop == -1 else stop
            out.append(" " * (stop - i))
            i = stop
            continue
        if ch == "#":
            stop = text.find("\n", i)
            stop = size if stop == -1 else stop
            out.append(" " * (stop - i))
            i = stop
            continue
        if text.startswith("/*", i):
            stop = text.find("*/", i + 2)
            stop = size if stop == -1 else stop + 2
            # Newlines are kept so line numbers in any later message still add up.
            out.append("".join("\n" if c == "\n" else " " for c in text[i:stop]))
            i = stop
            continue

        # --- a quoted literal or identifier: kept, but nothing inside it
        #     counts as code -----------------------------------------------
        if ch in ("'", '"', "`"):
            out.append(ch)
            i += 1
            while i < size:
                if text[i] == "\\" and ch != "`":
                    out.append(text[i:i + 2])
                    i += 2
                    continue
                if text[i] == ch:
                    # A doubled quote is one character of the value.
                    if i + 1 < size and text[i + 1] == ch:
                        out.append(text[i:i + 2])
                        i += 2
                        continue
                    out.append(ch)
                    i += 1
                    break
                out.append(text[i])
                i += 1
            continue

        # --- ordinary code -------------------------------------------------
        if ch == ";":
            ends.append(i)
        out.append(ch)
        i += 1

    masked = "".join(out)
    # The mask must not move anything, or every offset below is wrong.
    assert len(masked) == size, "the masked install script changed length"

    return masked, ends


def statement_spans(masked: str, ends: list[int], what: str) -> list[tuple[int, int]]:
    """
    Where each `DELETE FROM <table>` statement begins and ends in the masked
    text. The end is the first STATEMENT-ENDING semicolon after it -- not
    merely the next `;` character, which is the whole point of the scanner
    above.
    """
    spans: list[tuple[int, int]] = []
    for match in re.finditer(r"DELETE\s+FROM\s+`?%s`?" % what, masked, re.IGNORECASE):
        after = [e for e in ends if e > match.start()]
        spans.append((match.start(), after[0] if after else len(masked)))

    return spans


def seeded_switches() -> set[tuple[str, str]]:
    """
    Every (app, action) whose `api.*.enabled` row a FRESH INSTALL really ends
    up holding.

    WHY THIS IS NOT ONE REGEX, THOUGH IT USED TO BE
    -----------------------------------------------
    It was `set(re.findall(...))` over the whole file. That is wrong in a way
    that is invisible from either side, because `full_schema.sql` both SEEDS
    and DELETES settings -- it folds old migrations in, and migration 186
    deletes four `api.expenses.*` rows that earlier blocks of the same file
    insert. A name inside that DELETE matched the regex exactly as a name
    inside an INSERT does, so the check counted four rows as seeded that no
    installation has held since migration 186. Measured, not reasoned: a real
    MySQL 8.0.36 loaded from this file holds `api.expenses.create.enabled`,
    `api.expenses.delete.enabled` and `api.expenses.list.enabled`, and nothing
    else beginning `api.expenses.`.

    Crying wolf was the harmless direction. **The dangerous one is check G.**
    A handler whose switch is seeded and then deleted is unreachable -- 403 to
    everybody -- and the old reading called it seeded, so G passed on it. That
    is the shape this function now exists to catch.

    So the two statement kinds are applied IN FILE ORDER: a name in an INSERT
    adds, a name in a DELETE removes, and a later seed brings a deleted row
    back. Nothing here understands SQL; it does not need to. The keys are
    plain quoted literals and the only question is which statement each one
    sits inside.

    WHAT IT STILL CANNOT SEE, stated plainly because an overstated guarantee
    is worse than none:

      * a DELETE that matches by `LIKE 'api.%'`, by a subquery, or by anything
        other than the key written out in full. There is no such statement in
        `full_schema.sql` today (checked: the three DELETEs from `tblSettings`
        name their keys literally), but one added later would be invisible
        here and this function would over-report.
      * a row inserted or removed by application code rather than by the
        install script.
      * an `UPDATE` that changes a row's VALUE. This function answers "is the
        row there", never "is it set to true" -- which is correct for its one
        caller, since an administrator may switch any endpoint off.
      * a statement built by `PREPARE`/`EXECUTE` from a string, as the portable
        DDL idiom does. The key would be inside a literal and read as a seed.

    What it CAN now see, which the first version could not: a semicolon inside
    a comment or a literal, and a key quoted inside a comment. See
    read_install_script() for the fault that taught us both.
    """
    masked, ends = read_install_script()
    spans = statement_spans(masked, ends, "tblSettings")

    def inside_a_delete(at: int) -> bool:
        return any(start <= at <= stop for start, stop in spans)

    present: set[tuple[str, str]] = set()
    for key in re.finditer(r"'api\.([a-z0-9\-]+)\.([a-z0-9\-]+)\.enabled'", masked):
        pair = (key.group(1), key.group(2))
        if inside_a_delete(key.start()) is True:
            present.discard(pair)
        else:
            present.add(pair)

    return present


def page_routed_handlers() -> set[tuple[str, str]]:
    """
    Every (app, action) whose handler file a `tblRoutes` row reaches as an
    ordinary page, so no `api.*.enabled` switch applies to it.

    `Router::dispatch()` resolves a route's `targetFile` under `PORTAL_APPS`
    first, so a row like

        ('expenses/api/export', 'expenses/api/export.php', 1)

    loads `web/_apps/expenses/api/export.php` at the address
    `expenses/api/export` -- the SAME file ApiRouter would serve at
    `api/expenses/export`, with none of ApiRouter's gates in front of it.

    Only the `targetFile` is read, because that is what decides which file
    runs. The route KEY is not required to match any pattern: the portal
    happens to name this one after the file, but a row could point any address
    at the same handler and the consequence for check G would be identical.

    Deletions are honoured the same way and for the same reason as in
    seeded_switches(): `full_schema.sql` removes routes as well as adding them.

    WHAT IT CANNOT SEE, and one of these was a real finding of the independent
    check on 30 October 2026:

      * it reads the TARGET, so a route whose target is built by concatenation
        or supplied through `PREPARE` is invisible.
      * it used to read a target quoted inside a COMMENT as a live route, so
        leaving `-- the download used to be routed as 'expenses/api/export.php'`
        behind while removing the real row made the check go green on a handler
        that had become unreachable. Comments are now blanked before anything
        is read -- see read_install_script().
      * a route added by application code rather than by the install script.
    """
    masked, ends = read_install_script()
    spans = statement_spans(masked, ends, "tblRoutes")

    present: set[tuple[str, str]] = set()
    # A quoted `{app}/api/{action}.php`, wherever it appears. In an INSERT that
    # is a route's target; inside a DELETE span it is a route being removed.
    for hit in re.finditer(r"'([a-z0-9\-]+)/api/([a-z0-9\-]+)\.php'", masked):
        pair = (hit.group(1), hit.group(2))
        if any(start <= hit.start() <= stop for start, stop in spans) is True:
            present.discard(pair)
        else:
            present.add(pair)

    return present


class Described:
    """What the description says, resolved to (app, action) pairs."""

    def __init__(self, tables: tuple[dict[str, str], dict[str, str], dict[str, str], dict[str, str]]) -> None:
        resources, create, update, delete = tables
        self.resources = resources
        self.targets: dict[tuple[str, str], list[str]] = {}
        self.aliases: dict[tuple[str, str], list[str]] = {}
        self.claims_no_alias: dict[tuple[str, str], list[str]] = {}
        self.unanswerable: list[str] = []
        self.v1_seen: set[str] = set()

        spec = json.loads(SPEC.read_text(encoding="utf-8"))
        for path, ops in spec.get("paths", {}).items():
            if path in NOT_API_PATHS:
                continue
            parts = path.strip("/").split("/")
            methods = [m.lower() for m in ops if m.lower() in VERBS]

            if len(parts) >= 2 and parts[0] == "api" and parts[1] == "v1":
                self._v1_path(path, parts, ops, methods)
                continue
            if len(parts) == 3 and parts[0] == "api" \
                    and NAME.match(parts[1]) and NAME.match(parts[2]):
                self.targets.setdefault((parts[1], parts[2]), []).append(path)
                continue
            self.unanswerable.append(
                "%s — not a shape ApiRouter can answer (it needs `api/{app}/{action}` "
                "exactly, or `api/v1/...`)." % path)

    def _v1_path(self, path: str, parts: list[str], ops: dict, methods: list[str]) -> None:
        resource = parts[2] if len(parts) > 2 else ""
        has_id = len(parts) > 3
        self.v1_seen.add(resource)
        # dispatchV1() answers 404 for any segment after the id, so a path with
        # more than `api/v1/{resource}/{id}` cannot be served as described. The
        # non-v1 twin of this rule (exactly three parts) was already caught;
        # missing this half meant the check passed on the very shape the router
        # grew the rule for on 27 October 2026. Found by planting
        # `/api/v1/events/{id}/attendees`, which used to pass.
        if len(parts) > 4:
            self.unanswerable.append(
                "%s — ApiRouter answers 404 for any segment after the id, so it "
                "cannot serve this path." % path)
            return
        if resource not in self.resources:
            self.unanswerable.append(
                "%s — `%s` is not in ApiRouter::V1_RESOURCES, so the router answers "
                "404 for it." % (path, resource))
            return
        for method in methods:
            where = "%s %s" % (method.upper(), path)
            target = v1_target(resource, method, has_id, self.resources,
                               *_tables_tail())
            if target is None:
                self.unanswerable.append(
                    "%s — the description gives this verb, which ApiRouter refuses "
                    "for this address shape." % where)
                continue
            self.targets.setdefault(target, []).append(where)
            text = " ".join(str(ops[method].get(k) or "") for k in ("summary", "description"))
            for _verb, app, action in ALIAS_RE.findall(text):
                self.aliases.setdefault((app, action), []).append(where)
            if ALIAS_NONE_RE.search(text):
                self.claims_no_alias.setdefault(target, []).append(where)


_TABLES: tuple[dict[str, str], dict[str, str], dict[str, str], dict[str, str]] | None = None


def _tables_tail() -> tuple[dict[str, str], dict[str, str], dict[str, str]]:
    assert _TABLES is not None
    return _TABLES[1], _TABLES[2], _TABLES[3]


# ---------------------------------------------------------------------------
# The check
# ---------------------------------------------------------------------------

def main() -> int:
    global _TABLES

    for needed in (SPEC, ROUTER, SCHEMA_SQL):
        if needed.is_file() is False:
            print("✗ %s is missing, so nothing can be compared."
                  % needed.relative_to(REPO), file=sys.stderr)
            return 1

    tables = v1_tables()
    if isinstance(tables, str):
        print("✗ " + tables, file=sys.stderr)
        return 1
    _TABLES = tables

    reachable, helpers, unreachable = handlers_on_disk()
    if not reachable:
        print("✗ No api handler files were found under %s. Either the layout has "
              "changed or this check is looking in the wrong place; it is not "
              "credible that the portal has no endpoints."
              % APPS.relative_to(REPO), file=sys.stderr)
        return 1

    try:
        spoken = Described(tables)
    except json.JSONDecodeError as exc:
        print("✗ %s is not valid JSON (%s), so nothing can be compared."
              % (SPEC.relative_to(REPO), exc), file=sys.stderr)
        return 1

    switches = seeded_switches()
    # 🛣️ Handlers reached as ordinary pages instead. Check G must not call
    #    these dead, because they are not: see page_routed_handlers().
    paged = page_routed_handlers() & reachable
    live = reachable & switches                      # file on disk AND switch seeded
    described = set(spoken.targets) | set(spoken.aliases)

    if "--list" in sys.argv:
        print("handler files the router can reach : %d" % len(reachable))
        print("   of those, with a seeded switch  : %d" % len(live))
        print("helper files (outside the pattern) : %d" % len(helpers))
        for h in helpers:
            print("      %s" % h)
        print("documented as its own path         : %d" % len(spoken.targets))
        print("documented as a **Legacy alias:**  : %d" % len(spoken.aliases))
        print("documented either way              : %d" % len(described))
        print("\nreachable and undocumented:")
        for app, action in sorted(live - described):
            print("      api/%s/%s" % (app, action))
        print("\ndocumented with no handler file:")
        for app, action in sorted(described - reachable):
            print("      api/%s/%s" % (app, action))
        print("\nreached as an ordinary page route, not through ApiRouter: %d"
              % len(paged))
        for app, action in sorted(paged):
            print("      %s/api/%s  (no api.*.enabled gate applies)" % (app, action))
        print("\nhandlers with no seeded switch and no page route:")
        for app, action in sorted(reachable - switches - paged):
            print("      api/%s/%s" % (app, action))
        print("\nseeded switches with no handler:")
        for app, action in sorted(switches - reachable):
            print("      api/%s/%s" % (app, action))
        return 0

    problems: list[str] = []

    # ---- A: every reachable endpoint is documented -------------------------
    missing = sorted(live - described)
    if missing:
        problems.append(
            "✗ A: %d endpoint(s) answer real requests but appear nowhere in %s:\n%s\n"
            "   A developer reading the description never learns these exist. Give "
            "each one a path of its own, or name it as the `**Legacy alias:**` of "
            "the `api/v1/` operation that shares its handler."
            % (len(missing), SPEC.relative_to(REPO),
               "\n".join("      api/%s/%s   (_apps/%s/api/%s.php)" % (a, b, a, b)
                         for a, b in missing)))

    # ---- B: every documented address has a handler -------------------------
    phantom = sorted(described - reachable)
    if phantom:
        lines = []
        for app, action in phantom:
            where = spoken.targets.get((app, action), []) + spoken.aliases.get((app, action), [])
            lines.append("      %-30s documented at %s"
                         % ("api/%s/%s" % (app, action), ", ".join(where) or "(unknown)"))
            if (APPS / app).is_dir() is False:
                lines.append("         there is no `_apps/%s/` folder at all." % app)
            else:
                lines.append("         `_apps/%s/api/%s.php` does not exist." % (app, action))
        problems.append(
            "✗ B: %d documented endpoint(s) have no handler file, so every call "
            "gets 404:\n%s" % (len(phantom), "\n".join(lines)))

    # ---- C: "Legacy alias: none" where there is one ------------------------
    untrue = sorted((t for t in spoken.claims_no_alias if t in live))
    if untrue:
        problems.append(
            "✗ C: %d operation(s) say `**Legacy alias:** none` while the legacy "
            "address really does reach a handler:\n%s\n   The sentence is untrue as "
            "written. Either name the legacy address or explain why it is not to be "
            "used."
            % (len(untrue),
               "\n".join("      %s  —  api/%s/%s is on disk with its switch seeded"
                         % (", ".join(spoken.claims_no_alias[(a, b)]), a, b)
                         for a, b in untrue)))

    # ---- D: the v1 resource sets match, both ways --------------------------
    router_only = sorted(set(spoken.resources) - spoken.v1_seen)
    if router_only:
        problems.append(
            "✗ D: ApiRouter accepts %d `api/v1/` resource(s) the description never "
            "mentions: %s.\n   The REST door is open and undocumented."
            % (len(router_only), ", ".join(router_only)))

    # ---- E: a documented shape the router cannot answer --------------------
    if spoken.unanswerable:
        problems.append(
            "✗ E: %d documented path(s) the router cannot answer as described:\n%s"
            % (len(spoken.unanswerable),
               "\n".join("      " + u for u in spoken.unanswerable)))

    # ---- F / G / H: the three named lists ----------------------------------
    problems += named_list_problems(
        "F", set(unreachable), UNREACHABLE_ON_PURPOSE,
        "handler file(s) sit where ApiRouter can never reach them",
        "The one position the router reaches is `_apps/{app}/api/{action}.php`. A "
        "file one level up answers `api/{action}` -- two parts, which dispatch() "
        "refuses -- and one nested deeper is not looked for at all. Either way they "
        "answer nothing. Move each to the reachable position, or delete it.")
    problems += named_list_problems(
        "G", {"%s/%s" % p for p in (reachable - switches - paged)}, UNSEEDED_ON_PURPOSE,
        "handler(s) have no `api.{app}.{action}.enabled` row in the install script",
        "ApiRouter answers 403 when that row is absent, so the endpoint is "
        "unreachable however well it is documented. (That row is not the only "
        "gate: the app's own switch is asked first, which "
        "tools/app-switch-selftest.php covers.)")
    problems += named_list_problems(
        "H", {"%s/%s" % p for p in (switches - reachable)}, ORPHAN_SEEDS_ON_PURPOSE,
        "seeded `api.*.enabled` row(s) have no handler file",
        "A switch for an endpoint that does not exist. Remove the row in a "
        "migration, or build the handler.")
    problems += named_list_problems(
        "I", {"%s/%s" % p for p in paged}, PAGE_ROUTED_ON_PURPOSE,
        "handler(s) in an `api` folder are reached as ORDINARY PAGE ROUTES",
        "A `tblRoutes` row points an ordinary address at a file in an `api` "
        "folder, so Router -- not ApiRouter -- serves it, and NEITHER the "
        "`api.{app}.{action}.enabled` row NOR the app's own API-folder switch is "
        "asked. That is two fewer gates than the file's position implies. Name it "
        "here with what gates it does have, or move it out of the `api` folder.")

    if problems:
        print("\n\n".join(problems), file=sys.stderr)
        print("\n%d problem(s). The rule is #641: the API description and the code "
              "that answers it must agree.\nRun with --list to see both sides."
              % len(problems), file=sys.stderr)
        return 1

    # Deliberately does NOT add the alias count to the path count: all 40
    # aliases are among the documented endpoints already, and printing both as
    # though they were separate read as 101 endpoints on a portal with 61.
    print("✓ The API description agrees with the code: %d reachable endpoint(s), all "
          "documented; every documented address has a handler; %d `api/v1/` "
          "resource(s) described both ways; %d helper file(s) correctly outside the "
          "router's reach."
          % (len(live), len(spoken.resources), len(helpers)))
    return 0


def named_list_problems(letter: str, found: set[str], allowed: dict[str, str],
                        what: str, why: str) -> list[str]:
    """
    One of the three "known and deliberate" lists.

    Fails BOTH ways on purpose. Something unlisted is a new fault. Something
    LISTED BUT NO LONGER FOUND means the list is stale, and a stale list is how
    an exception quietly stops describing anything -- the same failure the
    end-to-end migration harness hit on 29 October 2026, when three of its
    named exceptions had stopped being true and nobody was told because the
    workflow that would have said so only runs on a pull request.
    """
    out = []
    new = sorted(found - set(allowed))
    if new:
        out.append("✗ %s: %d %s:\n%s\n   %s"
                   % (letter, len(new), what,
                      "\n".join("      %s" % n for n in new), why))
    stale = sorted(set(allowed) - found)
    if stale:
        out.append("✗ %s: %d entry(ies) in this check's own list are no longer true, "
                   "so the list is stale:\n%s\n   Delete each line. A named exception "
                   "that has stopped describing anything is worse than none, because "
                   "it is believed."
                   % (letter, len(stale),
                      "\n".join("      %s  —  listed as: %s" % (s, allowed[s])
                                for s in stale)))
    return out


if __name__ == "__main__":
    sys.exit(main())
