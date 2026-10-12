#!/usr/bin/env python3
"""
Addresses that stay inside the organisation being looked at.

Path:      tools/audit-checks/check_site_aware_addresses.py
Package:   Portal\\Tools
Author:    MWBM Partners Ltd (t/a MWservices)
Copyright: 2026-present MWBM Partners Ltd (t/a MWservices). All Rights Reserved.
Version:   2.1.0
Issue:     https://github.com/MWBMPartners/WebMS-Intra/issues/536

Finds redirects, links, form targets, script requests and breadcrumb entries
that are written as a bare address ("/expenses/approve") instead of being
built by `Site::url()`, `Router::url()`, `Router::urlForScript()` or, in a
script file, `Portal.url()`.

WHY THIS MATTERS
----------------
One installation can serve several organisations, and one way of telling them
apart is by the first part of the address: "/north/expenses/approve" is the
North organisation's approvals page. `Site::url('expenses/approve')` puts
that first part on. A bare "/expenses/approve" has none, so it lands on the
DEFAULT organisation. Somebody approving a claim for one organisation is
dropped into another, and the page they arrive on looks perfectly normal.

Nothing else catches it. Every file exists and every address is registered;
the mistake is in what the page points AT, and it only shows when
organisations are told apart this way.

WHAT IT READS
-------------
Every `.php` and `.js` file under `web/_apps`, `web/_core` and the web folder
(`web/public_html`), and the one file `web/_install/upgrade.php`.

It does NOT read: `_libraries`, `_vendor` and any folder called `vendor`
(other people's code); a file ending `.min.js`; `web/_install/index.php`
(the installer runs before any organisation exists); and anything in a place
named in `site_aware_addresses_pending.txt`, the list of places not swept
yet (see "THE PENDING LIST").

This used to read a short hand-written list only (roll-out packages B2a, B2b,
F1 and F2, 12 October 2026), because the portal held about 2,000 bare
addresses and a check that reported them all would have been switched off
the same day. Roll-out package F8 turned that round: everything is read, and
what has not been swept is named in the pending list, which can only shrink.

WHAT COUNTS AS A BARE ADDRESS
-----------------------------
On any line that is not a comment, an address that starts with a single "/"
in one of these places.

  Rule 1 applies everywhere this check reads.
  Rules 2 and 3 apply to the pages: `web/_apps` and `web/_install/upgrade.php`.
  Rule 4 applies only to the places listed in STRICT below.
  Rule 5 applies only to script files (a name ending `.js`).

Why not all four everywhere: measured over the whole portal on 12 October
2026, rules 2 and 3 found 653 places in the app folders (485 of them
breadcrumb entries), of which 2 were found not to be addresses; in
`web/_core` and the web folder they found 34, of which 21 were not (the "/"
a request falls back to, the address builder's own "/", paths on other
people's servers). A rule that is wrong more often than right gets switched
off, so it is kept to where it is right.

1. Straight after a word that takes an address:
   `Location:` (in any capitals, and also when the address is joined on as
   a second quoted piece: `'Location: ' . '/x'`); the attributes `href`,
   `action`, `formaction`, `src`, `data-url`, `data-href` and
   `hx-get/post/put/patch/delete` (quoted, unquoted, or with the quotes
   escaped as in `echo "<a href=\\"/x\\">"`); and in scripts `fetch(`,
   `sendBeacon(`, `EventSource(`, `.open('GET',`, `location =`,
   `location.href =`, `location.assign(` and `location.replace(`.
2. The value of an entry in a list: `'Calendar' => '/calendar'`. That is how
   every breadcrumb trail is written, and the page header prints each one
   as a link. The value of a `'path'` entry is left alone: that is a
   cookie's path, not a page.
3. Given to something, or handed back. Exactly this: the quoted address
   comes straight after "=" (not "==", "!=", "<=", ">=", "=>", ".=" or
   "+="), "?", "??", ":" (not "::") or the word `return`, with nothing
   between them but spaces and opening brackets. So it sees
   `$back = '/forms'`, `?? '/dashboard'`, `$ok ? '/a' : '/b'`,
   `$ok ? '/a' : ('/b?id=' . $id)`, `return ('/calendar?' . $query)`,
   `url: '/x'` in a script, and any other attribute
   (`value="/giving/give"`).
   The brackets were added on 13 October 2026: three redirects in
   `web/_apps/assets`, written `$ok ? '/a' : ('/b?id=' . $id)`, had the half
   in brackets passed over (four addresses), with this check saying "OK".
   It does NOT see an address after a bracket that follows a NAME, because
   that is a call: `goBack('/forms')`, `urlencode('/calendar/submit')`
   (rule 5 sees those in a script file; in a page nothing does, see "WHAT
   IT CANNOT DO"). Nor one with anything else in front of it:
   `$ok ? $base . '/x' : …`, `= (string) '/x'`.
   For rules 2 and 3 the quoted text must START with the address, and the
   address must be "/" on its own or "/" followed by a letter, a digit, "_",
   "?" or "#". That is what keeps patterns ('/^\\d+$/') out.
4. STRICT places only: ANY other quoted text that starts with a single "/",
   followed to its closing quote on the same line, through spaces and
   through a PHP tag. This is the rule those places were cleaned to (three
   independent checks on 12 October 2026 each planted a shape the narrower
   rules passed), so they are held to it still. It is not used everywhere
   because across the whole portal it reports hundreds of things that are
   not addresses: the "/" in `explode('/', $path)`, patterns, and pieces
   joined onto a host name.
5. Script files only: a quoted address handed to ANY function, as its first
   argument or a later one, on the line where the function's bracket opens:
   `postForm('/calendar/event/hub/upload-url', {…})`,
   `request('POST', '/x')`. Added on 13 October 2026: four requests in two
   script files went through helpers of their own (`postForm`, `postJson`),
   which rule 1's list of names could not know, and this check said "OK".
   The address must have a page's name: "/" followed by a letter, a digit
   or "_". "/" on its own is nearly always a separator (`split('/')`).
   Left alone, on purpose:
   - text handed to a function that only compares or cuts text and asks
     for nothing (see TEXT_FUNCTIONS): `path.indexOf('/api/') === 0`. Such
     a test can still be WRONG where organisations are told apart by the
     first part of the address (it does not expect that part); this check
     does not judge it;
   - a list or an object inside the brackets: `addAll(['/x'])`,
     `go({ url: '/x' })`;
   - an argument on a line of its own, below the opening bracket;
   - a piece joined onto something: `fetch(ORIGIN + '/api/x')`. The check
     cannot tell what the thing in front is. In `widget.js` and
     `widget/countdown.js` it is the portal's address as another website
     knows it: a whole address, which is `PortalAddress`'s matter. In
     `livechat-widget.js` it is the address of the page the script runs
     on, a page of the portal, so its four requests ARE bare and this
     check does NOT report them. Found on 13 October 2026 and left: that
     file starts before portal.js has run, so the fix is not one line.
   It is not used in PHP files: there a bracket after a name is everywhere
   (`in_array('/x', …)`, `str_starts_with($path, '/admin')`), and nearly
   none of it is an address somebody is sent to.

NOT REPORTED, AND WHY
---------------------
A check that cries wolf gets switched off.
- An address the WEB SERVER answers itself, because its first part is a
  real file or folder in the web folder ("/assets/css/portal.css",
  "/sw.js", "/offline/", "/api-docs"). An organisation's part in front
  would break those. What exists is read from the folder each time, so the
  list cannot go stale. It is judged on the WHOLE address, because
  "/assets" is two things at once here: a folder of files, and the Asset
  Tracker's pages ("/assets", "/assets/my", "/assets/item"), which are the
  portal's and do need the organisation's part. So, under a real folder:
  a file or folder that is there is the web server's (except a folder
  whose own address `.htaccess` hands to the portal, as it does for
  "/assets"); anything that is not there is the web server's too (an
  uploaded file, or a name PHP finishes off) UNLESS it is one of the
  portal's registered addresses, read from `web/_sql` by the same reader
  `check_webroot_shadowing.py` uses.
- "/robots.txt", "/sitemap.xml" and "/favicon.ico": web standards fix those
  three at the top of an address. See STANDARD_TOP_ADDRESSES.
- The first argument of a `preg_…(` call (a pattern), and the argument of
  one of the address builders (they take the leading "/" off themselves).
- Text whose "/" is followed straight by a space: a piece of a sentence.
- A line that begins with a comment mark (`//`, `*`, `#`, `/*`, `<!--`), so
  that a comment may quote an address. A comment at the END of a line of
  code is read like the code.
- A line that says why it must stay bare:
      // address-check: allow — the reason, in a few words
  The reason is required (ten characters at least). Any comment style works
  (`//`, `/* */`, `<!-- -->`), and "--" or "-" may stand for the long dash.
  Every such line is counted and listed in the output, so creep is visible,
  and a marker on a line where nothing is found is itself an error.

THE PENDING LIST
----------------
`site_aware_addresses_pending.txt`, beside this file: one place per line (a
folder or a single file, written from `web/`), "#" for comments. A place on
it is not judged, apart from any STRICT place inside it.
- The run FAILS if a place on the list holds no bare address any more (or
  is missing), so the list cannot rot: take the line out in the same change
  that sweeps the place.
- `--strict` fails while the list is not empty at all.
- A comment line of the form `# [S1] …` starts a group; the output counts
  the bare addresses left in each group.

WHAT IT CANNOT DO
-----------------
- It reads text, one line at a time. It cannot follow a value. An address
  put together from pieces none of which is a bare address in quotes is not
  seen: `'/' . $route`, `$base . 'expenses/approve'`,
  `sprintf('%s/expenses', $root)`.
- An address handed straight to a function is not seen in a PHP file
  outside the STRICT places: `goBack('/forms')`,
  `urlencode('/calendar/submit')`, `json_encode('/assets/kiosk?token=' . $t)`.
  (In a script file rule 5 sees it, within the limits listed there.)
- An address on a line of its own is not seen, because the word that says
  what it is sits on the line above: a request written
  `fetch(` / `'/api/x?id=' + id,` over two lines.
- An address joined onto something else is not seen outside the STRICT
  places: `$host . '/e/' . $slug`. Whole addresses with a host name are not
  judged at all; they belong to `PortalAddress`.
- An address built by a script while the page runs, and an address kept in
  the database (a menu entry, a notification, an e-mail template), are not
  seen. `tools/site-addresses-selftest.php` runs the portal and reads the
  pages it answers with, which covers part of that: only pages that answer
  a plain request, and only what is in the page when it arrives.
- An address that sits inside a longer piece of quoted text is not seen:
  `<meta http-equiv="refresh" content="0;url=/expenses">`,
  `header('Refresh: 0; url=/expenses')`.
- Quoted text whose closing quote is on another line is not seen by rule 4.
- It can report something that is not an address (sample text, a piece
  joined onto an address before it). Write the reason on the line with the
  allow marker.
- A mistyped address under a real folder ("/assets/itm") is taken for a
  file and left alone.
- It does not check that an address built by a helper is a registered one;
  `check_route_targets.py` looks at that side.

Exit code:
  0 — everything it was asked to read was read, all of it, and holds no bare
      address
  1 — at least one bare address; OR something could not be read in full (a
      place is missing, a folder could not be opened, a file could not be
      read, or it holds a link: links are not followed); OR the pending list
      names a place that is clean or missing; OR an allow marker has no
      reason or allows nothing; OR `--strict` and the pending list is not
      empty. Reading less than everything must never look like a pass.

Usage:
  python3 tools/audit-checks/check_site_aware_addresses.py
      [--strict] [--only PLACE [PLACE …]] [--root DIR] [--pending FILE]
      [--findings | --allowed | --answers ADDRESS [ADDRESS …]]

  --strict    also fail while the pending list is not empty.
  --only      judge only these places (written from `web/`, for example
              `_apps/venues _apps/calendar`), and judge them even though
              they are still on the pending list. For somebody sweeping one
              set of folders. The rest of the portal is not read, and the
              pending list is not judged.
  --root      read DIR instead of this repository's `web/` folder. For
              proving the check on a scratch copy with a planted fault.
  --pending   use FILE as the pending list (with --root, usually).
  --findings  print one line per bare address and nothing else:
              file, line, column, kind and address, with a tab between. For
              the rewriting script.
  --allowed   print one line per address that an allow marker excuses and
              nothing else: file, line, address and reason, with a tab
              between. For `tools/site-addresses-selftest.php`, which reads
              the pages the portal answers with and has to accept the same
              addresses this check accepts.
  --answers   judge nothing. For each address given, print "web-server" (it
              is a real file or folder in the web folder, or one of the
              three standard ones, so it takes no organisation's part) or
              "portal", a tab, and the address. For the same self-test, so
              that it and this check cannot disagree.
"""

from __future__ import annotations

import importlib.util
import os
import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
PENDING_FILE = Path(__file__).with_name("site_aware_addresses_pending.txt")

# The web folder as it is called in this repository. An installed copy may
# call it anything (PORTAL_WEBROOT says which); this check reads the
# repository, where it has one name.
WEB_FOLDER = "public_html"

# What is read, written from web/.
JUDGED = ["_apps", "_core", WEB_FOLDER, "_install/upgrade.php"]

# The pages: where rules 2 and 3 apply as well as rule 1 (see the top of this
# file for the measurement behind that).
PAGES = ["_apps", "_install/upgrade.php"]

# Other people's code, wherever it sits.
SKIPPED_FOLDERS = {"_libraries", "_vendor", "vendor", "node_modules"}

# Places held to rule 4 as well (see the top of this file). Each was cleaned
# to that rule; keep each entry beside the change that cleaned it. A STRICT
# place is judged even when a folder round it is on the pending list.
STRICT = [
    "_apps/expenses",  # roll-out package B2a, 12 October 2026
    # Roll-out package B2b, 12 October 2026: the erasure and "delete my
    # account" pages. The administrator's folder is listed whole. The other
    # five are single files, because the folders they sit in
    # (`_apps/account`, `_apps/auth/account`) still hold pages that have not
    # been cleaned.
    "_apps/admin/erasure",
    "_apps/account/erasure-request.php",
    "_apps/account/erasure-confirm.php",
    "_apps/account/my-data.php",
    "_apps/auth/account/delete.php",
    "_apps/auth/account/delete-confirm.php",
    # Roll-out package F1, 12 October 2026 (#502): the pages that move a
    # person between organisations. A bare address here is the worst place
    # for one: it would drop somebody who has just chosen an organisation
    # into the default one. `_apps/site` is the whole folder (the chooser
    # and the switch handler). The set-up page and its save handler are
    # single files, because the rest of `_apps/admin/sites` belongs to #536.
    "_apps/site",
    "_apps/admin/sites/setup.php",
    "_apps/admin/sites/setup-save.php",
    "_core/SiteGate.php",
    # Roll-out package F2, 12 October 2026 (#511): the refusal page. Its
    # links lead a person who was refused back to their OWN organisations,
    # so a bare address here would send them to the default one instead.
    "_core/templates/not-a-member.php",
    # Roll-out package F8, 12 October 2026 (#536): the shared page header,
    # the menu and the three error pages. Every page is drawn with them, so
    # a bare address here is on every page at once.
    # NOT LISTED, on purpose: `footer.php` and `event-unavailable.php` in the
    # same folder. Each holds one quoted "/" that is not a page address (the
    # service worker's scope, written in a script; and what a request falls
    # back to when the server gives no address). Rule 4 would report both,
    # and the footer's could only be excused by a note sent to every
    # browser. Rule 1 still reads them, like the rest of `_core`.
    "_core/templates/header.php",
    "_core/templates/nav.php",
    "_core/templates/error-403.php",
    "_core/templates/error-404.php",
    "_core/templates/error-500.php",
]

# Fixed by web standards at the TOP of an address, whatever organisation the
# visitor is in. Written out by hand, each with its reason.
STANDARD_TOP_ADDRESSES = {
    "/robots.txt": "search engines ask for it at the top of the address and nowhere else (RFC 9309)",
    "/sitemap.xml": "robots.txt names it once for the whole installation, at the top",
    "/favicon.ico": "browsers ask for it at the top of the address when a page names no icon",
}

# The characters an address runs through. It stops at a space, a quote, "<",
# ">" and a backslash (the last so that `href=\"/x\"` gives "/x").
ADDRESS_CHARS = r"""[^\s'"`<>\\]*"""

# A bare address: a single "/" followed by something that is not another "/"
# (so "//cdn.example" is not matched).
BARE = r"/(?!/)" + ADDRESS_CHARS

# For rules 2 and 3: "/" on its own, or "/" and then the start of a page
# name or of a query. A pattern ('/^\d+$/'), a piece of a sentence ('/ ')
# and a piece of a path ('/.htaccess') do not start that way.
PAGE = r"/(?:[A-Za-z0-9_?#]" + ADDRESS_CHARS + r")?"

# An opening quote, which in a PHP string in double quotes may be escaped.
QUOTE = r"""\\?["'`]"""

# Rule 1: an address straight after a word that takes one.
ATTRIBUTES = {
    "href": "link",
    "action": "form target",
    "formaction": "form target",
    "src": "address a page loads",
    "data-url": "address in an attribute",
    "data-href": "address in an attribute",
    "hx-get": "background request",
    "hx-post": "background request",
    "hx-put": "background request",
    "hx-patch": "background request",
    "hx-delete": "background request",
}
RULE_1 = [
    # `Location:` in any capitals: PHP sends the redirect whichever way the
    # word is written. The second line is the address joined on as a second
    # quoted piece.
    (re.compile(r"Location:\s*(" + BARE + r")", re.IGNORECASE), "redirect"),
    (re.compile(r"""Location:\s*["']\s*\.\s*["'](""" + BARE + r")", re.IGNORECASE), "redirect"),
    (re.compile(r"\b(?:fetch|sendBeacon|EventSource)\(\s*" + QUOTE + r"(" + BARE + r")"), "background request"),
    (re.compile(r"""\.open\(\s*["'][A-Za-z]+["']\s*,\s*""" + QUOTE + r"(" + BARE + r")"), "background request"),
    (re.compile(r"\blocation(?:\.href)?\s*=\s*" + QUOTE + r"(" + BARE + r")"), "redirect made by a script"),
    (re.compile(r"\blocation\.(?:assign|replace)\(\s*" + QUOTE + r"(" + BARE + r")"), "redirect made by a script"),
    # The attributes come LAST, so that `location.href = '/x'` in a script is
    # named a redirect and not a link (the first rule to find an address
    # names it). The quote is optional, because a browser follows
    # `<a href=/x>` just the same. "$" may not stand in front:
    # `$href = '/x'` is rule 3's. A "." may: `link.href = '/x'` in a script
    # is a bare address too.
    (
        re.compile(r"(?<![\w$-])(" + "|".join(ATTRIBUTES) + r")\s*=\s*(?:" + QUOTE + r")?(" + BARE + r")"),
        ATTRIBUTES,
    ),
]

# Rule 2: the value of an entry in a list. Group 1 is the entry's own name
# when that name is plain quoted text.
RULE_2 = re.compile(r"""(?:["'](\w+)["']\s*)?=>\s*["'](""" + PAGE + r""")(?=["'?#<]|$)""")

# Rule 3: given to something, or handed back. A "=" that is part of "==",
# "!=", "<=", ">=" or "=>" is not a giving, and neither is ".=" or "+=":
# those join a piece onto something else (see "WHAT IT CANNOT DO").
# `(?:\(\s*)*`: opening brackets between the two are looked through, so that
# `$ok ? '/a' : ('/b?id=' . $id)` is seen. Only brackets: a name in front of
# one (`goBack('/b')`) is a call, and this rule does not read calls.
RULE_3 = re.compile(
    r"(?:(?<![=!<>.+])=(?![=>])|\?\??|(?<!:):(?!:)|\breturn\b)\s*(?:\(\s*)*" + QUOTE + r"(" + PAGE + r""")(?=\\?["'`?#<]|$)"""
)

# Rule 4 (STRICT places): any text in quotes that starts with a single "/".
# The closing quote must be the same mark as the opening one. Between the
# address and that closing quote there may be a whole PHP tag (which may hold
# quotes of its own) and any character except the closing mark, "<" and ">".
# Leaving "<" and ">" out is deliberate: it is what keeps the end of an HTML
# tag (`name="x"/>`) from being read as the address "/".
# `(?!/\s)`: a "/" followed straight by a space is a piece of a sentence. It
# is refused HERE, before anything is matched, and not skipped afterwards, so
# that a quoted address later on the same line is still read.
RULE_4 = re.compile(r"""(["'`])(?!/\s)(/(?!/)[^\s'"`<>]*)(?:<\?.*?\?>|(?!\1)[^<>])*\1""")

# Rule 5 (script files): a quoted address that is an argument of a call.
# Group 1 is the opening quote, group 2 the address. The address needs a
# page's name, and must run to its closing quote with no space in it (so
# `log('/x is the path')`, a sentence, is not taken for one).
RULE_5 = re.compile(r"[(,]\s*(" + QUOTE + r")(/[A-Za-z0-9_]" + ADDRESS_CHARS + r""")(?=["'`]|$)""")

# Functions of the language itself that only compare or cut text. Text handed
# to one of these is not an address anybody is sent to. Kept short on
# purpose: every name here is a place rule 5 does not look.
# `replace` is here for text (`name.replace('/x', '')`); `location.replace()`
# is rule 1's and is found before this list is asked.
TEXT_FUNCTIONS = {
    "indexOf", "lastIndexOf", "startsWith", "endsWith", "includes",
    "split", "join", "replace", "replaceAll", "test", "match", "search",
}

# Words that stand before a bracket without being a function.
NOT_FUNCTIONS = {"if", "for", "while", "switch", "catch", "return", "typeof", "function"}

# What may stand straight before a quote without it being a breach.
PATTERN_ARGUMENT = re.compile(r"\bpreg_\w+\(\s*$")
BUILDERS = r"(?:\b(?:Router|Site)::url(?:ForScript)?|\bPortal\.url)\("
BUILDER_ARGUMENT = re.compile(BUILDERS + r"\s*$")
BUILDER = re.compile(BUILDERS)

# "// address-check: allow — the reason". Group 1 is the reason, which ends
# where the comment does.
ALLOW = re.compile(r"address-check:\s*allow\b\s*(?:—|–|--|-|:)?\s*(.*?)\s*(?:\?>|\*/|-->|$)")
COMMENT_STARTS = ("//", "*", "#", "/*", "<!--")


def registered_addresses(sql_folder: Path) -> set[str]:
    """
    Every address the portal registers, read by `check_webroot_shadowing.py`'s
    own reader, so the two checks cannot disagree about what "a registered
    address" is. That reader takes its folder from a name in its own file, so
    the name is pointed at `sql_folder` first (it matters with --root).
    """
    spec = importlib.util.spec_from_file_location("check_webroot_shadowing", Path(__file__).with_name("check_webroot_shadowing.py"))
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    module.SQL_DIR = sql_folder
    return {address.strip("/").lower() for address in module.seeded_addresses()}


class WebFolder:
    """What the web server answers itself, without the portal: see "NOT REPORTED" at the top."""

    def __init__(self, folder: Path, routes: set[str]) -> None:
        self.folder = folder
        self.routes = routes
        # Folders whose OWN address `.htaccess` hands to the portal although
        # the folder exists: `RewriteRule ^assets/?$ index.php`.
        self.handed_to_portal: set[str] = set()
        try:
            rules = (folder / ".htaccess").read_text(encoding="utf-8", errors="replace")
        except OSError:
            rules = ""
        for line in rules.splitlines():
            m = re.match(r"\s*RewriteRule\s+\^([\w.-]+)/\?\$\s+index\.php\b", line)
            if m:
                self.handed_to_portal.add(m.group(1))

    def answers(self, address: str) -> bool:
        """Does the web server answer this address itself, so that it takes no organisation's part?"""
        path = re.split(r"[?#]", address, maxsplit=1)[0]
        if path in STANDARD_TOP_ADDRESSES:
            return True
        parts = [part for part in path.split("/") if part != ""]
        if not parts or ".." in parts or not (self.folder / parts[0]).exists():
            return False
        target = self.folder.joinpath(*parts)
        if target.is_file():
            return True
        if target.is_dir():
            return not (len(parts) == 1 and parts[0] in self.handed_to_portal)
        return "/".join(parts).lower() not in self.routes


def called_function(before: str) -> str | None:
    """
    The name of the function whose "(" is still open at the end of `before`
    (the start of one line of a script), or None when the nearest bracket
    still open is not a call: a list, an object, or brackets that only group.

    It reads text and counts brackets. A bracket inside quoted text earlier
    on the line is counted like any other, so `f(')', '/x')` is misread.
    """
    depth = 0
    for position in range(len(before) - 1, -1, -1):
        character = before[position]
        if character in ")]}":
            depth += 1
        elif character in "([{":
            if depth > 0:
                depth -= 1
                continue
            if character != "(":
                return None
            name = re.search(r"([\w$]+)\s*$", before[:position])
            return name.group(1) if name else None
    return None


def findings_in(text: str, rules: int, web_folder: WebFolder, script_file: bool = False) -> tuple[list[tuple[int, int, str, str]], int, list[tuple[int, str, list[str]]]]:
    """
    One file's text, judged by rules 1 to `rules` (1, 3 or 4), and by rule 5
    as well when it is a script file. Returns: every bare address as (line,
    column, kind, address), columns counted from 0; how many addresses the
    file builds with a helper; and every allow marker as (line, reason, the
    addresses it excuses).
    """
    found: list[tuple[int, int, str, str]] = []
    markers: list[tuple[int, str, list[str]]] = []
    helped = 0
    for line_no, line in enumerate(text.splitlines(), start=1):
        # A comment that quotes an address is not a breach of the rule.
        if line.strip().startswith(COMMENT_STARTS):
            continue
        # The reason on an allow marker is not code either. It is cut off
        # before anything is looked for: a reason that quoted an address
        # ("it starts at '/'") was read as one, and then excused itself.
        allow = ALLOW.search(line)
        if allow:
            line = line[: allow.start()]
        helped += len(BUILDER.findall(line))
        # Keyed by where the address starts, so one address found by several
        # rules is reported once, under the most telling name.
        hits: dict[int, tuple[str, str]] = {}
        for pattern, kind in RULE_1:
            for m in pattern.finditer(line):
                name = kind[m.group(1)] if isinstance(kind, dict) else kind
                hits.setdefault(m.start(m.lastindex), (name, m.group(m.lastindex)))
        for m in RULE_2.finditer(line) if rules >= 3 else []:
            if m.group(1) == "path":
                continue  # a cookie's path
            hits.setdefault(m.start(2), ("breadcrumb link" if "$breadcrumbs" in line else "address in a list", m.group(2)))
        for m in RULE_3.finditer(line) if rules >= 3 else []:
            hits.setdefault(m.start(1), ("breadcrumb link" if "$breadcrumbs" in line else "address given to something", m.group(1)))
        if rules >= 4:
            for m in RULE_4.finditer(line):
                before = line[: m.start()]
                if PATTERN_ARGUMENT.search(before) or BUILDER_ARGUMENT.search(before):
                    continue
                hits.setdefault(m.start(2), ("breadcrumb link" if "$breadcrumbs" in line else "address in quotes", m.group(2)))
        for m in RULE_5.finditer(line) if script_file else []:
            before = line[: m.start(1)]
            name = called_function(before)
            if name is None or name in NOT_FUNCTIONS or name in TEXT_FUNCTIONS or BUILDER_ARGUMENT.search(before):
                continue
            hits.setdefault(m.start(2), ("address handed to a function", m.group(2)))
        real = [(column, kind, address) for column, (kind, address) in sorted(hits.items()) if not web_folder.answers(address)]
        if allow:
            markers.append((line_no, allow.group(1), [address for _, _, address in real]))
            if len(allow.group(1)) >= 10:
                continue
        found += [(line_no, column, kind, address) for column, kind, address in real]
    return found, helped, markers


def files_under(base: Path) -> tuple[list[Path], list[str]]:
    """
    Every file to judge under one place, and everything that stopped the
    whole of it being read.

    WHY NOT `Path.rglob()`: that is what the first version used, and it skips
    a folder it cannot open without a word. With a bare redirect planted in a
    folder made unreadable, the check printed "OK" and exited 0. `os.walk()`
    is told what to do with such an error instead: keep it, and fail.
    """
    if base.is_file():
        return [base], []

    errors: list[str] = []
    files: list[Path] = []

    def could_not_open(error: OSError) -> None:
        errors.append(f"{error.filename}: could not be opened ({error.strerror}).")

    for folder, subfolders, names in os.walk(base, onerror=could_not_open):
        for name in subfolders + names:
            path = Path(folder) / name
            # A link is not followed, so what it points at is not read.
            if path.is_symlink():
                errors.append(f"{path}: is a link, which is not followed.")
        subfolders[:] = [name for name in subfolders if name not in SKIPPED_FOLDERS]
        files += [Path(folder) / name for name in names if name.endswith(".php") or (name.endswith(".js") and not name.endswith(".min.js"))]
    return sorted(files), errors


def within(path: Path, places: list[Path]) -> Path | None:
    """The first of `places` that `path` is, or is inside."""
    for place in places:
        if path == place or place in path.parents:
            return place
    return None


def read_pending(file: Path, web: Path) -> tuple[list[tuple[str, str]], list[str]]:
    """The pending list as (place, group) pairs, and what is wrong with the file itself."""
    try:
        lines = file.read_text(encoding="utf-8").splitlines()
    except OSError as error:
        return [], [f"the pending list {file} could not be read ({error.strerror})."]
    entries: list[tuple[str, str]] = []
    problems: list[str] = []
    group = "no group"
    for number, line in enumerate(lines, start=1):
        text = line.strip()
        if text.startswith("#"):
            m = re.match(r"#\s*\[([^\]]+)\]", text)
            if m:
                group = m.group(1)
            continue
        if text == "":
            continue
        place = text.strip("/")
        if place in [entry for entry, _ in entries]:
            problems.append(f"the pending list names {place} twice (line {number}).")
            continue
        if not any(place == judged or place.startswith(judged + "/") for judged in JUDGED):
            problems.append(f"the pending list names {place} (line {number}), which this check does not read at all.")
            continue
        entries.append((place, group))
    return entries, problems


def option(args: list[str], name: str) -> str | None:
    """The word after `name`, or None when `name` is not given. Exits when the word is missing."""
    if name not in args:
        return None
    position = args.index(name)
    if position + 1 >= len(args) or args[position + 1].startswith("--"):
        print(f"check_site_aware_addresses: {name} needs a value.")
        sys.exit(1)
    return args[position + 1]


def check() -> int:
    args = sys.argv[1:]
    web = Path(option(args, "--root") or REPO_ROOT / "web").resolve()
    pending_file = Path(option(args, "--pending") or PENDING_FILE)
    machine = "--findings" in args or "--allowed" in args
    only: list[str] = []
    if "--only" in args:
        for word in args[args.index("--only") + 1:]:
            if word.startswith("--"):
                break
            only.append(word.strip("/"))
        if not only:
            print("check_site_aware_addresses: --only needs at least one place, written from web/ (for example _apps/venues).")
            return 1

    problems: list[str] = []
    web_folder = WebFolder(web / WEB_FOLDER, registered_addresses(web / "_sql"))
    if not web_folder.folder.is_dir():
        problems.append(f"{web_folder.folder}: the web folder is not there, so nothing could be told apart from a real file.")

    if "--answers" in args:
        for address in args[args.index("--answers") + 1:]:
            if address.startswith("--"):
                break
            print(("web-server" if web_folder.answers(address) else "portal") + "\t" + address)
        return 1 if problems else 0

    pending, pending_problems = ([], []) if only else read_pending(pending_file, web)
    problems += pending_problems
    pending_paths = {web / place: (place, group) for place, group in pending}
    strict_paths = [web / place for place in STRICT]
    page_paths = [web / place for place in PAGES]

    places = only if only else JUDGED
    for place in places:
        if not any(place == judged or place.startswith(judged + "/") for judged in JUDGED):
            problems.append(f"{place}: this check does not read that place at all (it reads {', '.join(JUDGED)}).")
    for place, _ in pending:
        if not (web / place).exists():
            problems.append(f"{place}: on the pending list, but not found under {web}. Take its line out.")
    if not only:
        for place in STRICT:
            if not (web / place).exists():
                problems.append(f"{place}: listed as a STRICT place, but not found under {web}. Nothing was read there.")

    findings: list[tuple[Path, int, int, str, str]] = []
    allowed: list[tuple[Path, int, str, list[str]]] = []
    left_in: dict[str, int] = {place: 0 for place, _ in pending}
    files_read = lines_read = helped_total = skipped_files = 0

    for place in places:
        base = web / place
        if not base.exists():
            problems.append(f"{place}: not found under {web}. Nothing was read there.")
            continue
        files, errors = files_under(base)
        problems += [f"{place}: {error}" for error in errors]
        if not files:
            problems.append(f"{place}: holds no PHP or script file. Nothing was read there.")
            continue
        for path in files:
            try:
                text = path.read_text(encoding="utf-8", errors="replace")
            except OSError as error:
                problems.append(f"{place}: {path} could not be read ({error.strerror}).")
                continue
            strict = within(path, strict_paths) is not None
            rules = 4 if strict else (3 if within(path, page_paths) is not None else 1)
            found, helped, markers = findings_in(text, rules, web_folder, path.suffix == ".js")
            waiting = None if strict else within(path, list(pending_paths))
            if waiting is not None:
                # Not judged yet. Counted, so the list can be held to "still
                # has something in it" and the work left can be seen.
                left_in[pending_paths[waiting][0]] += len(found)
                skipped_files += 1
                continue
            files_read += 1
            lines_read += len(text.splitlines())
            helped_total += helped
            findings += [(path, line_no, column, kind, address) for line_no, column, kind, address in found]
            for line_no, reason, excused in markers:
                if len(reason) < 10:
                    problems.append(f"{path.relative_to(web.parent)}:{line_no}: an allow marker with no reason. Say why the address must stay bare.")
                elif not excused:
                    problems.append(f"{path.relative_to(web.parent)}:{line_no}: an allow marker on a line where nothing is found. Take it off.")
                else:
                    allowed.append((path, line_no, reason, excused))

    for place, _ in pending:
        if (web / place).exists() and left_in[place] == 0:
            problems.append(f"{place}: on the pending list, but it holds no bare address. Take its line out of {pending_file.name}.")

    if machine:
        if "--allowed" in args:
            for path, line_no, reason, excused in allowed:
                for address in excused:
                    print(f"{path}\t{line_no}\t{address}\t{reason}")
        else:
            for path, line_no, column, kind, address in findings:
                print(f"{path}\t{line_no}\t{column}\t{kind}\t{address}")
        for problem in problems:
            print(f"check_site_aware_addresses: {problem}", file=sys.stderr)
        return 1 if (problems or findings) else 0

    print(f"check_site_aware_addresses: read {files_read} file(s), {lines_read} lines, under {', '.join(places)}; "
          f"{helped_total} address(es) there are built by a helper.")
    if allowed:
        print(f"\n{len(allowed)} line(s) say why their address stays bare (address-check: allow):")
        for path, line_no, reason, _ in allowed:
            print(f"  • {path.relative_to(web.parent)}:{line_no} — {reason}")
    if pending:
        groups: dict[str, int] = {}
        for place, group in pending:
            groups[group] = groups.get(group, 0) + left_in[place]
        print(f"\nNOT SWEPT YET: {len(pending)} place(s) on the pending list, {skipped_files} file(s), "
              f"{sum(left_in.values())} bare address(es) left in them:")
        for group, count in groups.items():
            print(f"  • {group}: {count} in {sum(1 for _, g in pending if g == group)} place(s)")

    if problems:
        print("\nPROBLEMS — this run proves nothing about what it could not read or judge:\n")
        for problem in problems:
            print(f"  • {problem}")

    if findings:
        print(f"\nFOUND {len(findings)} BARE ADDRESS(ES)\n")
        print("When organisations are told apart by the first part of the address,")
        print("each of these lands on the DEFAULT organisation, not the one being used.\n")
        for path, line_no, _, kind, address in findings:
            route = re.split(r"[?#]", address, maxsplit=1)[0].lstrip("/")
            helper = "Portal.url" if path.suffix == ".js" else "Site::url"
            print(f"  • {path.relative_to(web.parent)}:{line_no}")
            print(f"      {kind}: {address}")
            print(f"      build it instead: {helper}('{route}')")
            print()

    strict_and_pending = "--strict" in args and bool(pending)
    if strict_and_pending:
        print(f"\n--strict: the pending list still names {len(pending)} place(s). The sweep is not finished.")

    if problems or findings or strict_and_pending:
        print("FAILED — see above.")
        return 1

    if only:
        print("OK — no bare address in the place(s) named. (Nothing else was read.)")
    elif pending:
        print("OK — no bare address outside the pending list. (The places on it are NOT clean: see above.)")
    else:
        print("OK — no bare address anywhere this check reads. (See the top of this file for what it cannot see.)")
    return 0


if __name__ == "__main__":
    sys.exit(check())
