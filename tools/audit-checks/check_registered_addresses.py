#!/usr/bin/env python3
"""
Every address a page builds is an address the portal answers.

Path:      tools/audit-checks/check_registered_addresses.py
Package:   Portal\\Tools
Author:    MWBM Partners Ltd (t/a MWservices)
Copyright: 2026-present MWBM Partners Ltd (t/a MWservices). All Rights Reserved.
Version:   1.1.0
Issue:     https://github.com/MWBMPartners/WebMS-Intra/issues/612

WHAT WENT WRONG THAT THIS CATCHES
---------------------------------
On 13 October 2026 the link sweep (#536) was checked by comparing every
address it had rewritten with the addresses the portal really answers. Two
matched nothing, and neither was the sweep's doing:

  - Live chat moderation, on the Admin page, posted to `api/live/chat/moderate`.
    Nothing answers that. The handler is at `api/livechat/moderate`.
  - "<- Maintenance", on the backup pages, led to `admin/maintenance`. That
    is a folder, not a page.

Both read like the right address. Every file existed and every address in the
database was fine; the page simply pointed at a name nobody answers, so the
button only failed for the person who pressed it.

WHAT IT CHECKS
--------------
For every `Site::url('...')`, `Router::url('...')` and
`Router::urlForScript('...')` in a PHP file under `web/`, and every
`Portal.url('...')` in a script file, where the address is written out in full
as plain text, the address must be ONE of these:

  1. Empty. That is the front page.
  2. A `routeKey` the install script `web/_sql/full_schema.sql` registers (and
     does not delete again). EVERY registered row is read, however it is laid
     out: the file is read as SQL, value by value, not line by line. Some rows
     are written with extra spaces between the values, and a reader that looks
     for one exact layout misses them. (On 13 October 2026 this reader found the
     same 599 addresses as a database built from the script.)
     A query part (`?id=5`) or a "#..." part is not judged; only the address is.
  3. `api/{app}/{action}` where BOTH of these hold: the handler file
     `web/_apps/{app}/api/{action}.php` exists, AND the install script seeds the
     handler's own switch, the setting `api.{app}.{action}.enabled` (a row in
     tblSettings; seeded on or seeded off, either counts as "seeded"). The
     address table is never asked about an `api/` address, but `ApiRouter` asks
     for the file, and then reads that switch: with no row it answers 403 "This
     API endpoint is disabled" to everybody. (An earlier version of this check
     asked for the file only and said that was all `ApiRouter` looks for; that
     was wrong, found by the independent check of 13 October 2026.)
     `api/v1/{resource}` is the same router's other door, and its resources are
     read from `ApiRouter.php`.
  4. One of the few addresses the router answers by itself, without the table.
     They are named in ROUTER_ANSWERS below, each with the reason, and were
     found by reading `Router::handleSpecialRoutes()`.
  5. A real file or folder in the web folder, which the web server answers
     without asking the portal (`assets/css/portal.css`, `sw.js`). That is the
     fact `.htaccess` relies on, read from the folder each time so it cannot go
     stale.

Anything else is reported with its file and line, and the check exits 1.

It also reports two built addresses glued together:
`Site::url('small-groups') . Site::url('group')`. Each already carries the
organisation's part of the address, so the second one lands in the middle of
the first. That is wrong whatever the second key is, even a registered one.

WHAT IT CANNOT SEE (said plainly, because an overstated guarantee is worse
than none)
-------------------------------------------------------------------------
  - A key built from a variable: `Site::url('expenses/view/' . $id)`,
    `Site::url($page)`, `Site::url("claims/$id")`. The check can only read an
    address that is written out whole. It counts how many it skipped for that
    reason and prints the number; it does NOT judge them. (The short links the
    router answers, `a/<token>` and `f/<token>`, are all built this way.)
  - Whether the page at the address does what its button says. A registered
    address can still be the wrong one. This proves only that something
    answers there.
  - An address reached some other way than these four helpers: a bare
    "/page" (`check_site_aware_addresses.py` hunts those), a link in a stored
    setting, or one built in a database row.
  - A call inside a comment is skipped by looking at the start of its line
    (`//`, `*`, `#`, `/*`, `<!--`), not by reading the language. A call written
    after a comment marker in the middle of a line is read as code.
  - Whether the app that owns an `api/` folder is switched on (`ApiRouter` also
    refuses a switched-off app, from the list `AppRegistry::API_FOLDER_APPS`);
    whether a handler's switch is seeded ON (a switch seeded `false` counts as
    seeded here); or an organisation's own override of a switch.
  - Whether the registered address is switched ON for a given organisation, or
    whether the signed-in person may open it. Those are the page's business.
  - It reads the install script, not a database. A migration that registers an
    address must also be in the install script (a standing rule of this project,
    `.claude/rules/database.md`); if it is not, this check says the address is
    unregistered, which for a fresh install is true.

THE KNOWN LIST
--------------
Addresses this check found that reach nothing, and that were NOT fixed with the
change that added the check, are named in KNOWN_UNREGISTERED with the file, the
address and the reason. A line there is a debt, not an excuse: the list only
shrinks. A line that no longer matches anything is reported, so a fix cannot
leave its line behind.

THE LIST IS EMPTY (13 October 2026). The one address it held, the CSV export of
expense claims sending a person with an expired form token to `expenses`, which
is not a page, was fixed in the round after the independent check (it now goes
back to `expenses/approve`, the page that offers the export), and its line was
taken out. The tree this check was written for therefore ends "OK" with nothing
excused.

Usage:
  python3 tools/audit-checks/check_registered_addresses.py [--root DIR] [--list-registered]
    --root DIR          the web folder to read (default: this repository's web/).
                        Used to show the check failing on a planted fault in a copy.
    --list-registered   print every registered address the reader found, one
                        per line, then stop (to compare with the database).
    --list-switches     the same for the `api.*.enabled` switches it found.
Exit:  0 = every address written out is answered; 1 = a finding or a problem.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

# Folders that hold other people's code, or things this check has no business in.
SKIPPED_FOLDERS = {"_libraries", "_vendor", "vendor", "node_modules", "_auth_keys", "_uploads", "_backups"}

# The helpers that turn an address into a link. All of them end in
# Site::url(); the others only change how the result is written.
#   - Router::url() is Site::url() under another name (Router.php says so).
#   - Router::urlForScript() writes the result safely inside a script.
#   - Portal.url() is the same thing for a script FILE, which cannot ask PHP.
PHP_HELPERS = ("Site::url", "Router::url", "Router::urlForScript")
JS_HELPERS = ("Portal.url",)

# Addresses the ROUTER answers itself, without looking at the address table.
# Read from Router::handleSpecialRoutes() in web/_core/Router.php on 13 October
# 2026. Each is [exact address or pattern, reason]. If the router gains one,
# this list has to gain it too; the check cannot read the router's code.
ROUTER_EXACT = {
    "login/ms365": "starts the Microsoft sign-in (Router::handleSpecialRoutes)",
    "login/ms365/callback": "where Microsoft sends the person back (Router::handleSpecialRoutes)",
    "login/google": "starts the Google sign-in (Router::handleSpecialRoutes)",
    "login/google/callback": "where Google sends the person back (Router::handleSpecialRoutes)",
    "login/webauthn": "the passkey sign-in (Router::handleSpecialRoutes)",
    "logout": "ends the session (Router::handleSpecialRoutes)",
    "offline": "the page the service worker shows with no connection (Router::handleSpecialRoutes)",
    "health": "the deploy pipeline's health check (Router::healthCheck)",
}
# Short links: the router matches a prefix and then a pattern for the rest.
# An address written out in full must match the pattern to count.
ROUTER_PATTERNS = {
    "e": (re.compile(r"[a-z0-9][a-z0-9\-]{0,79}", re.I), "a public event page, /e/<name>"),
    "a": (re.compile(r"[a-f0-9]{32}"), "an asset's public lost-and-found page, /a/<token>"),
    "os": (re.compile(r"[a-f0-9]{32}"), "a service plan's public page, /os/<token>"),
    "f": (re.compile(r"[a-f0-9]{32}"), "a form's public page, /f/<token>"),
    "01": (re.compile(r"\d{8,14}(?:/21/[A-Za-z0-9\-_.]{1,20})?"), "an asset's printed code (GS1), /01/<number>"),
    "8003": (re.compile(r"[A-Za-z0-9\-_.]{14,30}"), "an asset's printed code (GS1), /8003/<number>"),
    "8004": (re.compile(r"[A-Za-z0-9\-_.]{1,30}"), "an asset's printed code (GS1), /8004/<number>"),
}

# Addresses found by this check on 13 October 2026 that reach nothing and were
# deliberately NOT fixed with it. {(file from web/, address): reason}.
# The coordinator decides what happens to each. Remove a line when it is fixed.
KNOWN_UNREGISTERED: dict[tuple[str, str], str] = {}

REASON_KNOWN = "known, reported on 13 October 2026"
MIN_REGISTERED = 100          # fewer than this means the reader read nothing useful
COMMENT_STARTS = ("//", "*", "#", "/*", "<!--")


# ---------------------------------------------------------------------------
# Reading the install script as SQL
# ---------------------------------------------------------------------------

def blank_sql_comments(text: str) -> str:
    """
    The SQL with every comment replaced by spaces (newlines kept, so a position
    still maps to its line). A "--" or "#" inside a quoted value is not a
    comment, which is why this walks the text instead of using a pattern.
    """
    out: list[str] = []
    i, n = 0, len(text)
    while i < n:
        c = text[i]
        if c == "'":
            j = i + 1
            while j < n:
                if text[j] == "\\":
                    j += 2
                    continue
                if text[j] == "'":
                    if j + 1 < n and text[j + 1] == "'":
                        j += 2
                        continue
                    break
                j += 1
            out.append(text[i:j + 1])
            i = j + 1
        elif text.startswith("--", i) and (i + 2 >= n or text[i + 2] in " \t\r\n"):
            j = text.find("\n", i)
            j = n if j < 0 else j
            out.append(" " * (j - i))
            i = j
        elif c == "#":
            j = text.find("\n", i)
            j = n if j < 0 else j
            out.append(" " * (j - i))
            i = j
        elif text.startswith("/*", i):
            j = text.find("*/", i + 2)
            j = n if j < 0 else j + 2
            out.append(re.sub(r"[^\n]", " ", text[i:j]))
            i = j
        else:
            out.append(c)
            i += 1
    return "".join(out)


def read_sql_value(text: str, i: int) -> tuple[str | None, int]:
    """One value of a VALUES tuple, starting at i. Returns (text of a quoted value or None for anything else, next position)."""
    n = len(text)
    while i < n and text[i].isspace():
        i += 1
    if i < n and text[i] == "'":
        j = i + 1
        chars: list[str] = []
        while j < n:
            if text[j] == "\\" and j + 1 < n:
                chars.append(text[j + 1])
                j += 2
                continue
            if text[j] == "'":
                if j + 1 < n and text[j + 1] == "'":
                    chars.append("'")
                    j += 2
                    continue
                break
            chars.append(text[j])
            j += 1
        return "".join(chars), j + 1
    # Not a quoted text (a number, NULL, a function call): skip to the next top-level , or ).
    depth = 0
    j = i
    while j < n:
        if text[j] == "(":
            depth += 1
        elif text[j] == ")":
            if depth == 0:
                break
            depth -= 1
        elif text[j] == "," and depth == 0:
            break
        elif text[j] == "'":
            _, j = read_sql_value(text, j)
            continue
        j += 1
    return None, j


def registered_addresses(sql_path: Path, problems: list[str]) -> dict[str, int]:
    """
    {address: line} for every address the install script registers in tblRoutes
    and does not delete again. Reads the script as SQL: comments removed,
    then every `INSERT ... INTO tblRoutes (columns) VALUES (...), (...)` read
    tuple by tuple, so the layout (one row per statement, many rows, extra
    spaces, a line break anywhere) does not matter.
    """
    try:
        raw = sql_path.read_text(encoding="utf-8", errors="replace")
    except OSError as error:
        problems.append(f"{sql_path}: could not be read ({error}). Nothing could be compared.")
        return {}
    text = blank_sql_comments(raw)
    found: dict[str, int] = {}
    statements = 0

    # Statements are walked in file order, because a later DELETE removes an earlier INSERT.
    pattern = re.compile(
        r"\bINSERT\s+(?:IGNORE\s+)?INTO\s+`?tblRoutes`?\s*\(([^)]*)\)\s*VALUES\b"
        r"|\bDELETE\s+FROM\s+`?tblRoutes`?\b([^;]*);"
        r"|\bUPDATE\s+`?tblRoutes`?\b([^;]*);"
        r"|\bREPLACE\s+INTO\s+`?tblRoutes`?\b",
        re.I,
    )
    for m in pattern.finditer(text):
        line = text.count("\n", 0, m.start()) + 1
        head = m.group(0).split(None, 1)[0].upper()
        if head == "INSERT":
            columns = [c.strip(" `").lower() for c in m.group(1).split(",")]
            if "routekey" not in columns:
                problems.append(f"{sql_path.name}:{line}: an INSERT into tblRoutes without a routeKey column; its rows could not be read.")
                continue
            key_at = columns.index("routekey")
            i, n, rows = m.end(), len(text), 0
            while True:
                while i < n and text[i].isspace():
                    i += 1
                if i >= n or text[i] != "(":
                    break
                i += 1
                values: list[str | None] = []
                while True:
                    value, i = read_sql_value(text, i)
                    values.append(value)
                    while i < n and text[i].isspace():
                        i += 1
                    if i < n and text[i] == ",":
                        i += 1
                        continue
                    break
                if i < n and text[i] == ")":
                    i += 1
                if len(values) != len(columns) or values[key_at] is None:
                    problems.append(f"{sql_path.name}:{line}: a row of the tblRoutes INSERT could not be read (it has {len(values)} value(s) for {len(columns)} column(s)).")
                else:
                    found[str(values[key_at]).strip("/").lower()] = line
                    rows += 1
                while i < n and text[i].isspace():
                    i += 1
                if i < n and text[i] == ",":
                    i += 1
                    continue
                break
            statements += 1
            if rows == 0:
                problems.append(f"{sql_path.name}:{line}: an INSERT into tblRoutes was found but no row of it could be read.")
        elif head == "DELETE":
            keys = re.findall(r"routeKey`?\s*=\s*'([^']*)'", m.group(2), re.I)
            for group in re.findall(r"routeKey`?\s+IN\s*\(([^)]*)\)", m.group(2), re.I):
                keys += re.findall(r"'([^']*)'", group)
            if not keys:
                problems.append(f"{sql_path.name}:{line}: a DELETE from tblRoutes that does not name a routeKey; the check cannot tell which addresses it removes.")
            for key in keys:
                found.pop(key.strip("/").lower(), None)
        else:
            problems.append(f"{sql_path.name}:{line}: a {head} on tblRoutes; this check only knows how to read INSERT and DELETE.")
    if statements == 0 or len(found) < MIN_REGISTERED:
        problems.append(
            f"{sql_path.name}: only {len(found)} registered address(es) were read (expected at least {MIN_REGISTERED}). "
            "The reader has stopped reading the file, so a clean result below would mean nothing."
        )
    return found


def seeded_api_switches(sql_path: Path, problems: list[str]) -> set[str]:
    """
    The `api.{app}.{action}.enabled` settings the install script seeds in
    tblSettings (portal-wide rows, whatever value they are seeded with), minus
    any it deletes again. Read statement by statement from the SQL with its
    comments removed, so the layout of a row does not matter.
    """
    try:
        text = blank_sql_comments(sql_path.read_text(encoding="utf-8", errors="replace"))
    except OSError:
        return set()
    key = re.compile(r"'(api\.[a-z0-9\-]+\.[a-z0-9\-]+\.enabled)'")
    found: set[str] = set()
    # Statements end at a ";" that is not inside a quoted value.
    start, i, n, quoted = 0, 0, len(text), False
    while i < n:
        c = text[i]
        if quoted:
            if c == "\\":
                i += 1
            elif c == "'":
                quoted = False
        elif c == "'":
            quoted = True
        elif c == ";":
            statement = text[start:i]
            head = re.match(r"\s*(INSERT|REPLACE|DELETE|UPDATE)\b[^`]*?`?tblSettings`?", statement, re.I)
            if head is not None:
                kind = head.group(1).upper()
                line = text.count("\n", 0, start) + 1
                if kind in ("INSERT", "REPLACE"):
                    found.update(m.group(1) for m in key.finditer(statement))
                elif kind == "DELETE":
                    if re.search(r"settingKey`?\s+(?:NOT\s+)?LIKE\s+'api", statement, re.I):
                        problems.append(f"{sql_path.name}:{line}: a DELETE from tblSettings by a LIKE pattern on 'api...'; the check cannot tell which switches it removes.")
                    found.difference_update(m.group(1) for m in key.finditer(statement))
                elif key.search(statement):
                    problems.append(f"{sql_path.name}:{line}: an UPDATE of tblSettings that names an api switch; this check only knows how to read INSERT and DELETE.")
            start = i + 1
        i += 1
    return found


def api_v1_resources(api_router: Path) -> set[str]:
    """The names `ApiRouter` accepts after `api/v1/`, read from its V1_RESOURCES list."""
    try:
        text = api_router.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return set()
    m = re.search(r"V1_RESOURCES\s*=\s*\[(.*?)\];", text, re.S)
    return set(re.findall(r"'([a-z0-9\-]+)'\s*=>", m.group(1))) if m else set()


# ---------------------------------------------------------------------------
# Reading the pages
# ---------------------------------------------------------------------------

def read_string_literal(text: str, i: int) -> tuple[str | None, int, bool]:
    """
    A quoted piece of PHP/JS text at i. Returns (its text or None, the position after its closing quote,
    whether it is "dynamic": a double-quoted string holding a variable).
    """
    quote = text[i]
    j = i + 1
    n = len(text)
    chars: list[str] = []
    while j < n:
        if text[j] == "\\" and j + 1 < n:
            chars.append(text[j + 1])
            j += 2
            continue
        if text[j] == quote:
            body = "".join(chars)
            dynamic = quote == '"' and ("$" in body or "{" in body)
            return body, j + 1, dynamic
        if text[j] == "\n" and quote != "`":
            return None, j, False
        chars.append(text[j])
        j += 1
    return None, n, False


def call_end(text: str, open_paren: int) -> int:
    """Position just after the bracket that closes the one at open_paren (quotes respected). -1 if it never closes."""
    depth = 0
    i, n = open_paren, len(text)
    while i < n:
        c = text[i]
        if c in "'\"":
            _, i, _ = read_string_literal(text, i)
            continue
        if c == "(":
            depth += 1
        elif c == ")":
            depth -= 1
            if depth == 0:
                return i + 1
        i += 1
    return -1


def calls_in(text: str, helpers: tuple[str, ...]) -> list[dict]:
    """
    Every call of a helper, with its line, its address if it is written out
    whole, and whether another built address is glued straight on after it.
    """
    names = "|".join(re.escape(h) for h in helpers)
    found: list[dict] = []
    for m in re.finditer(r"(?<![\w$>:])(" + names + r")\s*\(", text):
        line_start = text.rfind("\n", 0, m.start()) + 1
        line_end = text.find("\n", m.start())
        if text[line_start:line_end if line_end >= 0 else len(text)].lstrip().startswith(COMMENT_STARTS):
            continue
        open_paren = m.end() - 1
        i = open_paren + 1
        n = len(text)
        while i < n and text[i].isspace():
            i += 1
        # `Site::url()` with nothing inside is prose in a comment ("it uses Site::url()"), not a call.
        if i < n and text[i] == ")":
            continue
        call = {"helper": m.group(1), "line": text.count("\n", 0, m.start()) + 1, "key": None, "dynamic": True, "glued": False}
        if i < n and text[i] in "'\"":
            body, after, dyn = read_string_literal(text, i)
            j = after
            while j < n and text[j].isspace():
                j += 1
            if body is not None and dyn is False and j < n and text[j] in ",)":
                # The quoted text is the whole argument.
                call["key"] = body
                call["dynamic"] = False
            elif body is not None and (dyn is True or (j < n and text[j] == ".")):
                # Something is joined on. If the quoted start already holds the "?" or "#" that ends the
                # address, what follows is only a query, and the address is complete: `'calendar/event?slug=' . $slug`.
                head = re.split(r"[$]", body, maxsplit=1)[0] if dyn is True else body
                if re.search(r"[?#]", head) is not None:
                    call["key"] = head
                    call["dynamic"] = False
        end = call_end(text, open_paren)
        if end > 0:
            tail = text[end:end + 80]
            if re.match(r"\s*(?:\.|\+)\s*(?:" + "|".join(re.escape(h) for h in PHP_HELPERS) + r")\s*\(", tail):
                call["glued"] = True
        found.append(call)
    return found


class Registry:
    """Everything that can make an address count as answered."""

    def __init__(self, web: Path, registered: dict[str, int], switches: set[str]) -> None:
        self.web = web
        self.registered = registered
        self.switches = switches
        self.v1 = api_v1_resources(web / "_core" / "ApiRouter.php")
        self.web_folder = web / "public_html"

    def answered(self, key: str) -> str | None:
        """Why this address counts as answered (a short phrase), or None if nothing answers it."""
        path = re.split(r"[?#]", key, maxsplit=1)[0].strip().strip("/").lower()
        if path == "":
            return "the front page"
        # The start of a short link: `Site::url('a/') . $token`. The token is added by
        # the page, so only the start can be written out. Alone it would reach nothing,
        # which this check cannot tell (it does not look at what follows the call).
        start = re.split(r"[?#]", key, maxsplit=1)[0].strip().lstrip("/").lower()
        if start.endswith("/") and start.count("/") == 1 and start[:-1] in ROUTER_PATTERNS:
            return "the start of a short link the router answers itself: " + ROUTER_PATTERNS[start[:-1]][1]
        if path in self.registered:
            return "registered in full_schema.sql"
        parts = path.split("/")
        if parts[0] == "api":
            if len(parts) >= 3 and re.fullmatch(r"[a-z0-9\-]+", parts[1]) and re.fullmatch(r"[a-z0-9\-]+", parts[2]):
                if parts[1] == "v1":
                    return "the REST door of ApiRouter" if parts[2] in self.v1 else None
                if (self.web / "_apps" / parts[1] / "api" / f"{parts[2]}.php").is_file():
                    if f"api.{parts[1]}.{parts[2]}.enabled" in self.switches:
                        return "an api handler file that exists, with its switch seeded"
                    # The file is there, but with no switch row seeded ApiRouter answers 403 to everybody.
                    return None
            return None
        if path in ROUTER_EXACT:
            return "answered by the router itself: " + ROUTER_EXACT[path]
        if len(parts) >= 2 and parts[0] in ROUTER_PATTERNS:
            pattern, why = ROUTER_PATTERNS[parts[0]]
            if pattern.fullmatch("/".join(parts[1:])):
                return "answered by the router itself: " + why
        if ".." not in parts and (self.web_folder / parts[0]).exists():
            target = self.web_folder.joinpath(*parts)
            if target.is_file() or target.is_dir():
                return "a real file or folder the web server answers by itself"
        return None


def files_under(web: Path) -> list[Path]:
    out: list[Path] = []
    for path in sorted(web.rglob("*")):
        if not path.is_file() or path.suffix not in (".php", ".js") or path.name.endswith(".min.js"):
            continue
        if any(part in SKIPPED_FOLDERS for part in path.relative_to(web).parts):
            continue
        out.append(path)
    return out


def option(args: list[str], name: str) -> str | None:
    if name not in args:
        return None
    position = args.index(name)
    if position + 1 >= len(args):
        print(f"check_registered_addresses: {name} needs a value.")
        sys.exit(1)
    return args[position + 1]


def check() -> int:
    args = sys.argv[1:]
    web = Path(option(args, "--root") or REPO_ROOT / "web").resolve()
    problems: list[str] = []
    registered = registered_addresses(web / "_sql" / "full_schema.sql", problems)
    switches = seeded_api_switches(web / "_sql" / "full_schema.sql", problems)

    if "--list-registered" in args:
        for address in sorted(registered):
            print(address)
        return 1 if problems else 0
    if "--list-switches" in args:
        for name in sorted(switches):
            print(name)
        return 1 if problems else 0

    # With (almost) nothing registered there is nothing to compare against, and every address in the
    # portal would be reported as missing: thousands of lines that all say one thing. Say it once.
    if len(registered) < MIN_REGISTERED:
        print("check_registered_addresses: the registered addresses could not be read, so no address was judged.\n")
        for problem in problems:
            print(f"  • {problem}")
        print("\nFAILED — this run proves nothing about any address.")
        return 1

    if not (web / "_apps").is_dir():
        problems.append(f"{web / '_apps'}: not found, so no handler file could be looked for.")
    registry = Registry(web, registered, switches)
    if not registry.v1:
        problems.append(f"{web / '_core' / 'ApiRouter.php'}: no V1_RESOURCES list could be read, so `api/v1/...` addresses cannot be judged.")

    findings: list[tuple[str, int, str, str]] = []
    files_read = calls_read = whole = dynamic = 0
    known_used: set[tuple[str, str]] = set()

    for path in files_under(web):
        relative = path.relative_to(web).as_posix()
        helpers = JS_HELPERS if path.suffix == ".js" else PHP_HELPERS
        try:
            text = path.read_text(encoding="utf-8", errors="replace")
        except OSError as error:
            problems.append(f"{relative}: could not be read ({error}).")
            continue
        files_read += 1
        for call in calls_in(text, helpers):
            calls_read += 1
            if call["glued"]:
                findings.append((relative, call["line"], call["helper"] + "(...)",
                                 "is glued straight onto another built address. Each one already carries the organisation's part of the address, so the second lands in the middle of the first."))
            if call["dynamic"]:
                dynamic += 1
                continue
            whole += 1
            key = str(call["key"])
            if registry.answered(key) is not None:
                continue
            shown = re.split(r"[?#]", key, maxsplit=1)[0].strip().strip("/").lower()
            if (relative, shown) in KNOWN_UNREGISTERED:
                known_used.add((relative, shown))
                continue
            findings.append((relative, call["line"], f"{call['helper']}('{key}')",
                             "nothing answers this address: it is not registered in full_schema.sql, is not an api handler that exists, "
                             "is not one the router answers itself, and is not a real file in the web folder."))

    for (relative, address), why in KNOWN_UNREGISTERED.items():
        if (relative, address) not in known_used:
            problems.append(f"{relative}: the known list names '{address}' ({why}), but nothing there matches any more. Take the line out of KNOWN_UNREGISTERED.")
    if files_read == 0 or calls_read == 0:
        problems.append(f"Only {files_read} file(s) and {calls_read} call(s) were read under {web}: the reader is not reading what it should, so a clean result would mean nothing.")

    print(f"check_registered_addresses: read {files_read} file(s) under {web}; {calls_read} address call(s): "
          f"{whole} written out whole and judged, {dynamic} built from a variable and NOT judged; "
          f"{len(registered)} registered address(es) and {len(switches)} api switch(es) read from full_schema.sql; "
          f"{len(known_used)} known-unregistered address(es) on the list.")
    if known_used:
        print(f"\nKNOWN, NOT YET FIXED ({REASON_KNOWN}):")
        for relative, address in sorted(known_used):
            print(f"  • {relative}: '{address}' — {KNOWN_UNREGISTERED[(relative, address)]}")
    if problems:
        print("\nPROBLEMS — this run proves nothing about what it could not read:\n")
        for problem in problems:
            print(f"  • {problem}")
    if findings:
        print(f"\nFOUND {len(findings)} ADDRESS(ES) THAT REACH NOTHING OR ARE BUILT WRONG\n")
        for relative, line, call, why in sorted(findings):
            print(f"  • web/{relative}:{line}")
            print(f"      {call}")
            print(f"      {why}\n")
    if problems or findings:
        print("FAILED — see above.")
        return 1
    print("OK — every address written out whole is answered by something, and none is glued onto another. "
          "(A key built from a variable is not judged: see the top of this file.)")
    return 0


if __name__ == "__main__":
    sys.exit(check())
