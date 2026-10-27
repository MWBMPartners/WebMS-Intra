#!/usr/bin/env python3
# Path: tools/audit-checks/check_inline_scripts_have_nonce.py
"""
A script written into a page carries the page's mark; no handler sits on an element.

WHY THIS CHECK EXISTS (#625)
----------------------------
Since 3 June 2026 (#144) the page header, web/_core/templates/header.php, tells
browsers to run only the scripts that carry a one-time mark, called a nonce. It
still lists the older permission ('unsafe-inline') beside it, but a browser that
knows about nonces IGNORES that permission as soon as a nonce is present. So on
every page that uses the standard header:

  * a <script> written into the page with no nonce="..." does not run;
  * a handler written on an element (onclick="...", onchange="...",
    onerror="..." and so on) does not run;
  * a link whose address starts "javascript:" does nothing.

Nothing on the server notices. The page is sent, every file exists, every test
that asks the server passes, and the button simply does nothing in a browser.
On 14 October 2026 that was found on 43 files: the Edit boxes on Admin, Settings
and Admin, Users opened empty, an expense claim could not be filed, and
"Register New Passkey" opened nothing. This check is here so it cannot come back.

WHAT IT FAILS ON, in any file it reads under web/
-------------------------------------------------
  1. A <script ...> opening tag with no src= and no nonce=. Data blocks are not
     scripts and are let through: type="application/json" and
     type="application/ld+json".
  2. An event handler attribute: on<event>= for every event name a browser
     knows (the list is HANDLER_NAMES below), wherever it is written: in the
     page, in a string PHP prints, or in a string a script turns into HTML.
     Inside a tag in the page's own HTML it also fails on any on<letters>=
     attribute, known name or not.
  3. An address that starts "javascript:" in href, src, action or formaction.

HOW TO WRITE IT INSTEAD
-----------------------
  * A script in a page:
        <script nonce="<?php echo htmlspecialchars(\\Portal\\Core\\App::cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
  * A handler: give the element a data- attribute and listen for the event in a
    script. The shared ones are in web/public_html/assets/js/portal-actions.js
    (data-portal-submit-on-change, data-portal-select-on-click); a confirmation
    is data-confirm (portal-confirm.js); a box that opens is Bootstrap's own
    data-bs-toggle.

THE EXEMPTION LIST, check_inline_scripts_have_nonce.exempt.txt
-------------------------------------------------------------
A file on that list is not held to the rule, and each line says why. There are
only four reasons, and the check tests the first two itself:

  no-rule     the page draws its own <!doctype> and never includes the standard
              header, so no script rule is sent and its scripts run.
  own-rule    the page sends a rule of its own.
  not-a-page  a file that is not a portal page (a bundle made by others, or a
              static file a browser opens by itself).
  waiting     the file is held by other work today; the exact change is written
              beside it and it comes off the list when that work has landed.

A file marked no-rule or own-rule that includes the standard header is REFUSED:
the rule applies to it, whatever the list says. A line whose file has nothing
left to exempt is REFUSED as stale, so the list shrinks by itself. And the list
may not grow: MOST_EXEMPTIONS below is today's count, and a longer list fails.
Lower that number in the commit that removes a line.

WHAT THIS CHECK CANNOT SEE — read this before trusting a pass
------------------------------------------------------------
  * A tag put together from pieces at run time. It reads the text of the file.
    `'<scr' . 'ipt>'`, a tag whose name comes from a variable, or a nonce that is
    printed only on one branch of an `if`, all look fine or wrong to it by luck.
    It judges a tag by the text from "<script" to the first ">" that follows.
  * A handler added by a script file: `el.onclick = ...` and
    `el.setAttribute('onclick', ...)` are not attributes written in a page. The
    first is allowed by the browser's rule; the second is refused by it, and
    this check does not look for it.
  * Whether the nonce printed is the RIGHT one. `nonce="x"` passes here. The
    run-time twin, tools/page-scripts-selftest.php, asks the real pages and
    compares each tag's nonce with the one in that answer's own header.
  * Whether a page really uses the standard header. Every file is held to the
    rule unless the list says otherwise, so a stand-alone page that is not on
    the list is failed too. That is on purpose: the next person to wrap it in
    the header would otherwise break it without a word from anything.
  * Scripts and styles a browser refuses for other reasons (a script file from
    an address the rule does not name, `eval`, `new Function`).
  * It does not run any script, and it is not a browser.
  * Words inside comments are left alone: PHP comments, HTML comments, and, in
    a script, a line that starts with // or *. A comment in some other shape
    that quotes a handler will be reported; reword it.

Exit code:
  0  nothing found
  1  at least one finding, or a fault in the exemption list
  2  the check could not do its work (it read too few files to be believed)

Usage:
  python3 tools/audit-checks/check_inline_scripts_have_nonce.py [--root <folder>] [--list <file>]
  python3 tools/audit-checks/check_inline_scripts_have_nonce.py --self-test

  --root   read <folder>/web instead of this repository's (used to show the
           check failing on a planted fault in a copy).
  --list   use another exemption list (the self-test does).

Package:   Portal\\Tools
Author:    MWBM Partners Ltd (t/a MWservices)
Copyright: 2026-present MWBM Partners Ltd (t/a MWservices). All Rights Reserved.
"""

from __future__ import annotations

import os
import re
import sys
import tempfile
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
LIST_FILE = Path(__file__).with_name("check_inline_scripts_have_nonce.exempt.txt")

# Today's length of the exemption list. A longer list fails: an exemption is
# for a page that sends its own rule or none, never a way to quiet a finding.
# Lower this number in the commit that removes a line.
MOST_EXEMPTIONS = 20

# Fewer files than this means the check is looking in the wrong place, and
# "nothing found" would then mean "nothing read". web/ holds well over 900.
FEWEST_FILES = 400
# The same guard from the other side: the tree is full of scripts that carry the
# nonce (63 when this was written). Seeing far fewer means the reader has gone
# blind, not that all is well. Lower this only if pages really were removed.
FEWEST_MARKED_SCRIPTS = 50

READ_SUFFIXES = {".php", ".phtml", ".inc", ".html", ".htm", ".js", ".mjs", ".svg"}

# Folders never opened. _auth_keys holds secrets and is never read by any tool.
SKIP_DIRS = {".git", "node_modules", "_auth_keys", "_uploads", "_backups", "_libraries"}

REASONS = ("no-rule", "own-rule", "not-a-page", "waiting")

# Every event handler attribute a browser knows, without its "on". From the
# HTML standard (global, window and document handlers) and the events the other
# web standards add to elements. A name that is not here is not a handler to a
# browser, so `once="..."` or `only="..."` in a string is not a finding.
HANDLER_NAMES = (
    "abort afterprint animationcancel animationend animationiteration animationstart auxclick "
    "beforecopy beforecut beforeinput beforematch beforepaste beforeprint beforetoggle beforeunload "
    "blur cancel canplay canplaythrough change click close command contentvisibilityautostatechange "
    "contextlost contextmenu contextrestored copy cuechange cut dblclick drag dragend dragenter "
    "dragexit dragleave dragover dragstart drop durationchange emptied ended error focus focusin "
    "focusout formdata fullscreenchange fullscreenerror gotpointercapture hashchange input invalid "
    "keydown keypress keyup languagechange load loadeddata loadedmetadata loadstart "
    "lostpointercapture message messageerror mousedown mouseenter mouseleave mousemove mouseout "
    "mouseover mouseup mousewheel offline online pagehide pagereveal pageshow pageswap paste pause "
    "play playing pointercancel pointerdown pointerenter pointerleave pointermove pointerout "
    "pointerover pointerrawupdate pointerup popstate progress ratechange readystatechange "
    "rejectionhandled reset resize scroll scrollend scrollsnapchange scrollsnapchanging search "
    "securitypolicyviolation seeked seeking select selectionchange selectstart slotchange stalled "
    "storage submit suspend timeupdate toggle touchcancel touchend touchmove touchstart "
    "transitioncancel transitionend transitionrun transitionstart unhandledrejection unload "
    "volumechange waiting wheel"
).split()

# Rule 2, anywhere: a known handler name, or a browser's own prefixed one.
HANDLER_RE = re.compile(
    r"(?<![A-Za-z0-9_$.\-])(on(?:" + "|".join(HANDLER_NAMES) + r"|webkit[a-z]+|moz[a-z]+|ms[a-z]+))\s*=",
    re.IGNORECASE,
)
# Rule 2, inside a tag in the page's own HTML: any on<letters>= attribute.
TAG_RE = re.compile(r"<[A-Za-z][^<>]*>", re.DOTALL)
ANY_HANDLER_IN_TAG_RE = re.compile(r"""(?:^|[\s"'/])(on[a-z]{2,})\s*=""", re.IGNORECASE)
# Rule 3.
JS_ADDRESS_RE = re.compile(r"""\b(?:href|src|action|formaction)\s*=\s*\\?["']?\s*javascript:""", re.IGNORECASE)

SCRIPT_OPEN_RE = re.compile(r"<script\b", re.IGNORECASE)
SCRIPT_CLOSE_RE = re.compile(r"</script", re.IGNORECASE)
HAS_SRC_RE = re.compile(r"(?<![\w-])src\s*=", re.IGNORECASE)
HAS_NONCE_RE = re.compile(r"(?<![\w-])nonce\s*=", re.IGNORECASE)
DATA_TYPE_RE = re.compile(r"""type\s*=\s*\\?["']?\s*application/(?:ld\+)?json\b""", re.IGNORECASE)
STANDARD_HEADER_RE = re.compile(r"""templates['"]?\s*(?:\.\s*DIRECTORY_SEPARATOR\s*\.\s*['"]|/)header\.php""")
HEREDOC_RE = re.compile(r"""<<<[ \t]*(['"]?)([A-Za-z_][A-Za-z0-9_]*)\1""")


def blank(text: list[str], start: int, end: int) -> None:
    """Replace text[start:end] with spaces, keeping the line breaks, so every
    position and line number in the file stays where it was."""
    for i in range(start, end):
        if text[i] != "\n":
            text[i] = " "


def mask_php(source: str, pure_template: bool) -> tuple[str, str]:
    """Blank out PHP comments, and say where the PHP is.

    Returns two texts of the same length as the file:
      * `no_comments`: the file with every PHP comment blanked;
      * `html_only`:   the file with every PHP block blanked whole, so that
                       what is left is the HTML the page writes by itself.

    WHY A SMALL READER AND NOT A PATTERN: a comment mark inside a string is not
    a comment ('http://...' is the usual one), and a quote inside a comment is
    not a string. Telling them apart needs the text read in order. This reader
    knows quoted strings, heredocs, the three comment shapes and the "?>" that
    ends a block. It does not know PHP: it cannot follow `{$a["b"]}` inside a
    double-quoted string further than the next unescaped quote, which is right
    often enough for finding comments and is all it is used for.
    """
    out = list(source)
    html = list(source)
    n = len(source)
    i = 0
    in_php = False
    while i < n:
        if in_php is False:
            if pure_template is True:
                break
            start = source.find("<?", i)
            if start == -1:
                break
            if source.startswith("<?php", start) or source.startswith("<?=", start):
                in_php = True
                php_from = start
                i = start + (5 if source.startswith("<?php", start) else 3)
            else:
                i = start + 2
            continue

        ch = source[i]
        two = source[i:i + 2]
        if two == "?>":
            blank(html, php_from, i + 2)
            in_php = False
            i += 2
        elif ch == "'" or ch == '"':
            j = i + 1
            while j < n and source[j] != ch:
                j += 2 if source[j] == "\\" else 1
            i = j + 1
        elif two == "<<" and HEREDOC_RE.match(source, i) is not None:
            m = HEREDOC_RE.match(source, i)
            closing = re.compile(r"^[ \t]*" + re.escape(m.group(2)) + r"\b", re.MULTILINE)
            end = closing.search(source, m.end())
            i = end.end() if end is not None else n
        elif two == "//" or (ch == "#" and two != "#["):
            j = i
            while j < n and source[j] != "\n" and source[j:j + 2] != "?>":
                j += 1
            blank(out, i, j)
            i = j
        elif two == "/*":
            j = source.find("*/", i + 2)
            j = n if j == -1 else j + 2
            blank(out, i, j)
            i = j
        else:
            i += 1
    if in_php is True:
        blank(html, php_from, n)
    return "".join(out), "".join(html)


def blank_comment_lines(text: str, start: int, end: int) -> str:
    """In a script, blank every line of text[start:end] that starts with //, *
    or /*: those are comments. A line of HTML in a string does not start that
    way, so this cannot hide a real tag or handler. It is not a reader of
    scripts: a comment that follows code on the same line is left alone."""
    out = list(text)
    pos = start
    for line in text[start:end].split("\n"):
        stripped = line.lstrip()
        if stripped.startswith("//") or stripped.startswith("*") or stripped.startswith("/*"):
            blank(out, pos, pos + len(line))
        pos += len(line) + 1
    return "".join(out)


def end_of_tag(text: str, start: int) -> int:
    """Where the tag that starts at `start` ends: the first ">" that is not the
    end of a PHP block written inside the tag, and not PHP's own "->" or "=>"
    (a tag built in a PHP string runs on into the code that finishes it). Looks
    at most 1,500 characters ahead, so a tag with no end cannot swallow a file."""
    i = start
    limit = min(len(text), start + 1500)
    while i < limit:
        if text.startswith("<?", i) and i != start:
            close = text.find("?>", i)
            if close == -1:
                return limit
            i = close + 2
            continue
        if text[i] == ">" and text[i - 1] not in "-=?":
            return i + 1
        i += 1
    return limit


def scan(source: str, is_script_file: bool = False, pure_template: bool = False) -> tuple[list[tuple[int, str, str]], int, bool]:
    """Read one file's text. Returns (findings, marked scripts seen, uses the
    standard header). A finding is (line, what, the text that was found)."""
    findings: list[tuple[int, str, str]] = []
    no_comments, html_only = mask_php(source, pure_template or is_script_file)
    work = list(no_comments)
    html = list(html_only)
    line_of = lambda pos: source.count("\n", 0, pos) + 1  # noqa: E731

    # Where the scripts are. A script's body is not HTML: a "<script" or a "<!--"
    # inside it is text. In a script FILE the whole file is one body.
    bodies: list[tuple[int, int]] = []
    marked = 0
    if is_script_file is True:
        bodies.append((0, len(source)))
        # A script file's heading often shows how to embed it, "<script src=...>"
        # and all. Those lines start with * or //, and are blanked HERE, before
        # tags are looked for. (The first draft blanked them only afterwards, for
        # the handler rule, and reported the word "<script>" in the heading of
        # web/public_html/widget/countdown.js as a script with no nonce.)
        no_comments = blank_comment_lines(no_comments, 0, len(no_comments))
        work = list(no_comments)
    i = 0
    while i < len(no_comments):
        if is_script_file is True:
            opening = SCRIPT_OPEN_RE.search(no_comments, i)
            comment_at = -1
        else:
            opening = SCRIPT_OPEN_RE.search(no_comments, i)
            comment_at = no_comments.find("<!--", i)
        if comment_at != -1 and (opening is None or comment_at < opening.start()):
            end = no_comments.find("-->", comment_at + 4)
            end = len(no_comments) if end == -1 else end + 3
            blank(work, comment_at, end)
            blank(html, comment_at, end)
            i = end
            continue
        if opening is None:
            break
        tag_end = end_of_tag(no_comments, opening.start())
        tag = no_comments[opening.start():tag_end]
        if DATA_TYPE_RE.search(tag) is not None:
            pass
        elif HAS_NONCE_RE.search(tag) is not None:
            marked += 1
        elif HAS_SRC_RE.search(tag) is None:
            findings.append((line_of(opening.start()), "a script with no nonce", " ".join(tag.split())[:90]))
        closing = SCRIPT_CLOSE_RE.search(no_comments, tag_end)
        if closing is None:
            i = tag_end
            continue
        if is_script_file is False:
            bodies.append((tag_end, closing.start()))
        i = closing.start() + 2

    # In a script, a line that starts with // or * is a comment. Blank it, so a
    # note that quotes a handler is not reported. (A line of HTML in a string
    # does not start that way.)
    for body_from, body_to in bodies:
        work = list(blank_comment_lines("".join(work), body_from, body_to))
        blank(html, body_from, body_to)

    text = "".join(work)
    seen: set[tuple[int, str]] = set()
    for m in HANDLER_RE.finditer(text):
        key = (line_of(m.start()), m.group(1).lower())
        if key not in seen:
            seen.add(key)
            findings.append((key[0], "a handler on an element", m.group(1) + "="))
    for m in JS_ADDRESS_RE.finditer(text):
        findings.append((line_of(m.start()), 'an address that starts "javascript:"', " ".join(m.group(0).split())))
    for tag in TAG_RE.finditer("".join(html)):
        for m in ANY_HANDLER_IN_TAG_RE.finditer(tag.group(0)):
            key = (line_of(tag.start() + m.start(1)), m.group(1).lower())
            if key not in seen:
                seen.add(key)
                findings.append((key[0], "a handler on an element", m.group(1) + "="))

    findings.sort()
    return findings, marked, STANDARD_HEADER_RE.search(no_comments) is not None


def read_list(path: Path) -> tuple[dict[str, tuple[str, str]], list[str]]:
    """The exemption list: {file: (reason, the words after it)} and its faults."""
    entries: dict[str, tuple[str, str]] = {}
    faults: list[str] = []
    if path.is_file() is False:
        return entries, [f"the exemption list {path} is missing"]
    for number, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        line = raw.strip()
        if line == "" or line.startswith("#"):
            continue
        parts = line.split(None, 2)
        if len(parts) < 3:
            faults.append(f"{path.name}:{number}: needs three things: the file, the reason and the words that explain it")
            continue
        name, reason, words = parts
        if reason not in REASONS:
            faults.append(f"{path.name}:{number}: '{reason}' is not one of {', '.join(REASONS)}")
            continue
        if name in entries:
            faults.append(f"{path.name}:{number}: {name} is listed twice")
            continue
        entries[name] = (reason, words)
    return entries, faults


def check(root: Path, list_file: Path, most: int, fewest_files: int, fewest_marked: int, quiet: bool = False) -> int:
    say = (lambda *a: None) if quiet is True else print  # noqa: E731
    web = root / "web"
    if web.is_dir() is False:
        say(f"ERROR: no web folder under {root}")
        return 2
    entries, faults = read_list(list_file)

    files_read = 0
    marked_total = 0
    problems: list[str] = []
    exempt_used: set[str] = set()
    for folder, dirs, names in os.walk(web):
        dirs[:] = sorted(d for d in dirs if d not in SKIP_DIRS)
        for name in sorted(names):
            path = Path(folder) / name
            if path.suffix.lower() not in READ_SUFFIXES:
                continue
            rel = path.relative_to(root).as_posix()
            try:
                source = path.read_text(encoding="utf-8", errors="replace")
            except OSError as why:
                problems.append(f"{rel}: could not be read ({why})")
                continue
            files_read += 1
            suffix = path.suffix.lower()
            findings, marked, uses_header = scan(
                source,
                is_script_file=suffix in (".js", ".mjs"),
                pure_template=suffix in (".html", ".htm", ".svg"),
            )
            marked_total += marked
            if rel in entries:
                reason = entries[rel][0]
                exempt_used.add(rel)
                if len(findings) == 0:
                    faults.append(f"{list_file.name}: {rel} has nothing left to exempt. Remove its line and lower MOST_EXEMPTIONS.")
                if reason in ("no-rule", "own-rule") and uses_header is True:
                    faults.append(
                        f"{list_file.name}: {rel} is marked '{reason}' but includes the standard header, "
                        "so the rule applies to it. It cannot be exempt."
                    )
                continue
            for line, what, found in findings:
                problems.append(f"{rel}:{line}: {what}: {found}")

    for name in sorted(set(entries) - exempt_used):
        faults.append(f"{list_file.name}: {name} is not a file this check reads. Remove its line and lower MOST_EXEMPTIONS.")
    if len(entries) > most:
        faults.append(
            f"{list_file.name}: {len(entries)} files are exempt and at most {most} may be. The list may only shrink: "
            "an exemption is for a page that sends its own script rule or none, never a way to quiet a finding."
        )

    if files_read < fewest_files or marked_total < fewest_marked:
        say(
            f"ERROR: read {files_read} file(s) and saw {marked_total} script(s) carrying a nonce; expected at least "
            f"{fewest_files} and {fewest_marked}. The check is looking in the wrong place, so its answer means nothing."
        )
        return 2

    for line in problems:
        say(line)
    for line in faults:
        say(line)
    waiting = sorted(name for name, (reason, _) in entries.items() if reason == "waiting")
    say(
        f"\nRead {files_read} files under web/. Scripts carrying a nonce: {marked_total}. "
        f"Exempt files: {len(entries)} (at most {most}), of which waiting for other work: {len(waiting)}."
    )
    if len(problems) > 0 or len(faults) > 0:
        say(
            f"FAIL: {len(problems)} finding(s), {len(faults)} fault(s) in the exemption list. A browser refuses each "
            "finding on a page that uses the standard header (#625). See the top of this file for how to write it instead."
        )
        return 1
    say("OK: no script without a nonce, no handler on an element, no javascript: address.")
    return 0


# ---------------------------------------------------------------------------
# The self-test: planted cases, each of which the check must get right.
# ---------------------------------------------------------------------------

NONCE_PHP = "<?php echo htmlspecialchars(\\Portal\\Core\\App::cspNonce(), ENT_QUOTES, 'UTF-8'); ?>"

# (name, file kind, text, the findings expected as (line, what begins with))
CASES: list[tuple[str, str, str, list[tuple[int, str]]]] = [
    ("a bare script in a page", "php", "<?php $a = 1; ?>\n<p>x</p>\n<script>\nrun();\n</script>\n", [(3, "a script")]),
    ("a script with the nonce", "php", f'<p>x</p>\n<script nonce="{NONCE_PHP}">\nrun();\n</script>\n', []),
    ("a script file from our own address", "php", '<script src="/assets/js/portal.js" defer></script>\n', []),
    ("a JSON data block", "php", '<script type="application/json" id="d">{"a":1}</script>\n', []),
    ("a search-engine data block", "php", '<script type="application/ld+json">{"a":1}</script>\n', []),
    ("a bare script, in capitals", "php", "<SCRIPT>\nrun();\n</SCRIPT>\n", [(1, "a script")]),
    ("a tag split over lines, no nonce", "php", "<script\n   defer\n>\nrun();\n</script>\n", [(1, "a script")]),
    ("a tag split over lines, nonce on the second", "php", f'<script\n   nonce="{NONCE_PHP}">\nrun();\n</script>\n', []),
    ("a bare script printed by PHP", "php", "<?php\necho '<script>go();</script>';\n", [(2, "a script")]),
    ("a bare script built in a PHP string", "php", "<?php\n$tag = \"\\n\" . '<script>'\n    . 'if(a){b();}'\n    . '</script>';\n", [(2, "a script")]),
    ("a marked script built in a PHP string", "php", "<?php\n$tag = '<script nonce=\"' . $n . '\">' . 'go();' . '</script>';\n", []),
    ("a script tag with PHP's arrow inside it", "php", "<?php\n$tag = '<script src=\"' . $this->url() . '\"></script>';\n", []),
    ("the word in a PHP comment", "php", "<?php\n// Returns a <script> tag with onerror=\"x\" on it.\n/* <script> onclick=\"y\" */\n# <script>\n$a = 1;\n", []),
    ("the word in a docblock", "php", "<?php\n/**\n * Asset::js() returns a <script> tag; onerror=\"...\" was here.\n */\nfunction f(): void {}\n", []),
    ("the word in an HTML comment", "php", "<p>x</p>\n<!-- a <script> block, and onclick=\"y\" -->\n<p>y</p>\n", []),
    ("the word in a comment inside a script", "php", f'<script nonce="{NONCE_PHP}">\n// a second <script> block, and onclick="y"\nrun();\n</script>\n', []),
    ("a comment mark inside a string is not a comment", "php", "<?php\n$u = 'http://example.invalid/'; echo '<script>go();</script>';\n", [(2, "a script")]),
    ("a quote inside a comment is not a string", "php", "<?php\n// it's here\necho '<script>go();</script>';\n", [(3, "a script")]),
    ("a heredoc that holds a bare script", "php", "<?php\n$h = <<<HTML\n<script>go();</script>\nHTML;\n", [(3, "a script")]),
    ("a second bare script after a marked one", "php", f'<script nonce="{NONCE_PHP}">a();</script>\n<p>x</p>\n<script>b();</script>\n', [(3, "a script")]),
    ("onclick on a button", "php", '<button type="button" onclick="go()">Go</button>\n', [(1, "a handler")]),
    ("onchange on a select, on its own line", "php", '<select name="a"\n        onchange="this.form.submit()">\n</select>\n', [(2, "a handler")]),
    ("a handler in capitals", "php", '<button onClick="go()">Go</button>\n', [(1, "a handler")]),
    ("a handler with no quotes", "php", "<body onload=start()>\n", [(1, "a handler")]),
    ("a handler a browser does not know, inside a tag", "php", '<div onfrobnicate="x()">\n', [(1, "a handler")]),
    ("two handlers on one element", "php", '<div onclick="a()" onkeydown="b()">x</div>\n', [(1, "a handler"), (1, "a handler")]),
    ("a handler printed by PHP", "php", "<?php\n$tag .= ' onerror=\"this.onerror=null;\"';\n", [(2, "a handler")]),
    ("a handler in HTML a script builds", "php", f'<script nonce="{NONCE_PHP}">\nel.innerHTML = \'<a onclick="go()">x</a>\';\n</script>\n', [(2, "a handler")]),
    ("a handler in HTML a script file builds", "js", "var h = '<a onclick=\"go()\">x</a>';\n", [(1, "a handler")]),
    ("a property set by a script is not an attribute", "js", "el.onclick = function () {};\nwindow.onload = start;\n", []),
    ("the word in a script file's heading", "js", "/* How to embed it:\n *   <script src=\"x.js\" defer></script>\n * Options on the <script> tag: onclick=\"no\"\n */\n// a <script> tag\nrun();\n", []),
    ("a bare script in a string of a script file", "js", "var h = '<script>go();</' + 'script>';\n", [(1, "a script")]),
    ("words that only look like a handler", "php", '<input name="once" value="1" data-on="x">\n<?php $online = "yes"; $on = 1; ?>\n', []),
    ("a data- attribute named like a handler", "php", '<div data-onclick="x" data-portal-submit-on-change>x</div>\n', []),
    ("a javascript: link", "php", '<a href="javascript:window.close()">Close</a>\n', [(1, "an address")]),
    ("a static HTML file with a bare script", "html", "<!doctype html>\n<script>\nrun();\n</script>\n", [(2, "a script")]),
    ("an SVG with a handler", "svg", '<svg xmlns="http://www.w3.org/2000/svg" onload="x()"></svg>\n', [(1, "a handler")]),
]


def self_test() -> int:
    failures = 0

    def ok(name: str, passed: bool, detail: str = "") -> None:
        nonlocal failures
        if passed is False:
            failures += 1
        print(("  pass  " if passed else "  FAIL  ") + name + ("" if passed or detail == "" else "\n          " + detail))

    print("A. What the reader finds in planted text")
    for name, kind, text, expected in CASES:
        found, _, _ = scan(text, is_script_file=kind == "js", pure_template=kind in ("html", "svg"))
        got = [(line, what) for line, what, _ in found]
        good = len(got) == len(expected) and all(g[0] == e[0] and g[1].startswith(e[1]) for g, e in zip(got, expected))
        ok(name, good, f"expected {expected}, found {found}")

    print("B. The whole check, on a made-up tree")
    good_page = f'<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . \'templates\' . DIRECTORY_SEPARATOR . \'header.php\'; ?>\n<script nonce="{NONCE_PHP}">a();</script>\n'
    own_page = "<!doctype html>\n<script>a();</script>\n<button onclick=\"b()\">x</button>\n"

    def tree(files: dict[str, str], listed: str) -> tuple[Path, Path]:
        base = Path(tempfile.mkdtemp(prefix="nonce-check-selftest-"))
        for rel, text in files.items():
            target = base / rel
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(text, encoding="utf-8")
        list_path = base / "exempt.txt"
        list_path.write_text(listed, encoding="utf-8")
        return base, list_path

    def run(files: dict[str, str], listed: str, most: int = 5) -> int:
        base, list_path = tree(files, listed)
        try:
            return check(base, list_path, most, fewest_files=1, fewest_marked=1, quiet=True)
        finally:
            for folder, dirs, names in os.walk(base, topdown=False):
                for name in names:
                    os.remove(Path(folder) / name)
                for name in dirs:
                    os.rmdir(Path(folder) / name)
            os.rmdir(base)

    files = {"web/_apps/a/good.php": good_page, "web/_apps/a/own.php": own_page}
    listed = "web/_apps/a/own.php  no-rule  draws its own page\n"
    ok("a clean tree with one honest exemption passes", run(files, listed) == 0)
    ok("the same tree without the exemption fails", run(files, "") == 1)
    ok("a planted bare script in a standard page fails", run({**files, "web/_apps/a/bad.php": good_page + "<script>b();</script>\n"}, listed) == 1)
    ok("a planted handler in a standard page fails", run({**files, "web/_apps/a/bad.php": good_page + '<a onclick="b()">x</a>\n'}, listed) == 1)
    ok("a planted handler in a script file fails", run({**files, "web/public_html/assets/js/x.js": "h = '<a onclick=\"b()\">x</a>';\n"}, listed) == 1)
    ok("an exempt page that includes the standard header is refused", run({**files, "web/_apps/a/own.php": good_page + own_page}, listed) == 1)
    ok("...unless it is marked as waiting for other work", run({**files, "web/_apps/a/own.php": good_page + own_page}, "web/_apps/a/own.php  waiting  held by other work; change line 3\n") == 0)
    ok("an exemption with nothing left to exempt is refused as stale", run({**files, "web/_apps/a/own.php": good_page}, listed) == 1)
    ok("an exemption for a file that is not there is refused", run(files, listed + "web/_apps/a/gone.php  no-rule  was here once\n") == 1)
    ok("a list longer than the most allowed is refused", run(files, listed, most=0) == 1)
    ok("a reason that is not one of the four is refused", run(files, "web/_apps/a/own.php  because  I said so\n") == 1)
    ok("a file listed twice is refused", run(files, listed + listed) == 1)
    ok("a folder of secrets is never opened", run({**files, "web/_auth_keys/k.php": "<script>x();</script>\n"}, listed) == 0)
    base, list_path = tree(files, listed)
    ok("too few files read is an error, not a pass", check(base, list_path, 5, fewest_files=50, fewest_marked=1, quiet=True) == 2)
    ok("no marked script seen anywhere is an error, not a pass", check(base, list_path, 5, fewest_files=1, fewest_marked=50, quiet=True) == 2)
    for folder, dirs, names in os.walk(base, topdown=False):
        for name in names:
            os.remove(Path(folder) / name)
        for name in dirs:
            os.rmdir(Path(folder) / name)
    os.rmdir(base)

    total = len(CASES) + 15
    print(f"\nSelf-test reached its end: {total - failures} of {total} passed.")
    return 0 if failures == 0 else 1


def main(argv: list[str]) -> int:
    if "--self-test" in argv:
        return self_test()
    root = REPO_ROOT
    list_file = LIST_FILE
    args = list(argv)
    while len(args) > 0:
        arg = args.pop(0)
        if arg == "--root" and len(args) > 0:
            root = Path(args.pop(0)).resolve()
        elif arg == "--list" and len(args) > 0:
            list_file = Path(args.pop(0)).resolve()
        elif arg == "--strict":
            pass  # every finding already fails; accepted so the other checks' habit works here too
        else:
            print(f"ERROR: unknown argument '{arg}' (see the top of this file for usage)")
            return 2
    return check(root, list_file, MOST_EXEMPTIONS, FEWEST_FILES, FEWEST_MARKED_SCRIPTS)


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
