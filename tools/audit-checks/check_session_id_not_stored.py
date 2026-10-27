#!/usr/bin/env python3
"""
The session identifier, and the whole session, are never stored (#622).

Path: tools/audit-checks/check_session_id_not_stored.py

WHY THIS EXISTS
---------------
A browser session's identifier IS the sign-in cookie. Whoever holds it can be
that person until the session ends. Until October 2026 the activity log stored
it with every record, beside a copy of everything in the session, and the
consent log stored it too. Anybody who could read those tables, a backup file
or a snapshot taken before an upgrade held a set of working sign-ins, and
every two-step backup code and some authenticator keys in readable text.

The fix has ONE place for each thing, both in web/_core/Logger.php:

  - Logger::sessionFingerprint() gives a value that may be stored in place of
    the identifier (it cannot be turned back);
  - Logger::sessionFacts() is the short list of plain facts a record keeps in
    place of the session.

Nothing failed while the fault was there: every page worked. So this check
reads the code and fails when either thing could be coming back.

WHAT IT LOOKS FOR, under web/, in PHP code (comments and quoted text are
taken out first, so a sentence that names a function is not a breach)
------------------------------------------------------------------------
1. THE IDENTIFIER. Any call of session_id() or session_create_id(), or the
   constant SID, outside the files listed in IDENTIFIER_READERS below. Each of
   those is listed with how many times it may appear and why it is harmless.
   A second call in a listed file fails as well: that is exactly how the old
   fault would come back (a new session_id() in Logger::activity()).
2. THE WHOLE SESSION. Any use of $_SESSION as one whole thing: copied to a
   variable, handed to a function, looped over. Reading or writing ONE named
   value ($_SESSION['user_id']) is not that and is ignored. Four whole-session
   forms cannot copy a value anywhere and are ignored too: isset($_SESSION),
   is_array($_SESSION), unset($_SESSION) and emptying it ($_SESSION = []).
   Anything else must be listed in WHOLE_SESSION_USERS with its reason.

WHAT IT CANNOT SEE (said plainly, because a check that reports nothing is
easily taken to mean there is nothing)
-------------------------------------------------------------------------
  - WHAT A LISTED LINE DOES NEXT. It counts the calls in a listed file. It
    does not follow the value. If the one permitted call in Logger.php were
    changed to store the identifier itself, this would still pass.
    tools/activity-log-selftest.php is what catches that: it runs the real
    pages and compares every stored value with the browser's cookie.
  - THE IDENTIFIER REACHED ANOTHER WAY: read out of $_COOKIE by the cookie's
    name, taken from the request's Cookie header, or read from the session
    files on disk. None of those says session_id().
  - ONE SECRET COPIED BY NAME. A page that writes $_SESSION['some_secret']
    into a table is using one named value, which rule 2 ignores on purpose
    (the portal does that properly several hundred times).
  - CODE IT DOES NOT READ: anything outside web/, the third-party folders
    (_vendor, _libraries, vendor, node_modules), files that are not .php, and
    PHP put together as text and run later.
  - HEREDOC TEXT. Text between <<< markers is read as code, so a mention
    there could be reported though it is only words. None exists today.

Exit code:
  0  nothing found
  1  at least one finding, or a listed file that no longer exists

Usage:
  python3 tools/audit-checks/check_session_id_not_stored.py
  python3 tools/audit-checks/check_session_id_not_stored.py --web <dir>
      (--web reads <dir> in place of web/. It is how the check is proved on a
       copy with a fault planted in it, never on the checkout itself.)
"""

from __future__ import annotations

import os
import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

SKIP_DIRS = {".git", "node_modules", "_libraries", "_vendor", "vendor", "_backups", "_uploads", "_auth_keys"}

# Rule 1. Path under web/ -> (how many calls it may hold, why each is harmless).
IDENTIFIER_READERS: dict[str, tuple[int, str]] = {
    "_core/Logger.php": (
        1,
        "Logger::sessionFingerprint(), the ONE place the identifier is read to be fingerprinted. "
        "It returns the fingerprint and never the identifier.",
    ),
    "_apps/admin/system-info/phpinfo.php": (
        1,
        "portalReportLeaksSessionToken() searches the finished Server Information report for the reader's own "
        "identifier, so that a report holding it is never sent. It compares and stores nothing.",
    ),
}

# Rule 2. Path under web/ -> (how many whole-session uses it may hold, why each is harmless).
WHOLE_SESSION_USERS: dict[str, tuple[int, str]] = {
    "_core/Auth.php": (
        1,
        "sendOfflineCopyHeaders() takes the NAMES of the session's values (array_keys) to decide one response "
        "header. No value is read.",
    ),
    "_core/Debug.php": (
        1,
        "The debug bar shows the session ON SCREEN, to the person whose session it is, with secret-looking names "
        "blanked, and only where debugging is switched on. Nothing is stored.",
    ),
}

IDENTIFIER = re.compile(r"\b(session_id|session_create_id)\s*\(|(?<![\w$>:])SID(?![\w(])")
WHOLE_SESSION = re.compile(r"\$_SESSION\b(?!\s*\[)")
# The four whole-session forms that cannot copy a value anywhere.
HARMLESS_WHOLE = re.compile(
    r"\b(?:isset|is_array|unset)\s*\(\s*\$_SESSION\s*\)|\$_SESSION\s*=\s*(?:\[\s*\]|array\s*\(\s*\))\s*;"
)


def strip_comments_and_text(source: str) -> str:
    """PHP source with every comment and every quoted text replaced by spaces.

    Line breaks are kept, so a finding's line number is the real one. Written
    out by hand because the check must run with Python alone: it does not ask
    PHP to read the file.
    """
    blank = lambda text: re.sub(r"[^\n]", " ", text)  # noqa: E731 - spaces, with the line breaks kept
    out: list[str] = []
    i, n = 0, len(source)
    in_php = False
    while i < n:
        if in_php is False:
            # Page text outside <?php ... ?> is not code. It must be skipped
            # whole: an apostrophe in a sentence ("don't") would otherwise be
            # read as the start of quoted text and hide the PHP after it.
            start = source.find("<?", i)
            start = n if start == -1 else start
            out.append(blank(source[i:start]))
            i = min(start + 2, n)
            out.append("  " if start < n else "")
            in_php = start < n
            continue
        ch = source[i]
        nxt = source[i + 1] if i + 1 < n else ""
        if ch == "?" and nxt == ">":
            out.append("  ")
            i += 2
            in_php = False
        elif ch == "/" and nxt == "*":
            end = source.find("*/", i + 2)
            end = n if end == -1 else end + 2
            out.append(blank(source[i:end]))
            i = end
        elif (ch == "/" and nxt == "/") or (ch == "#" and nxt != "["):
            # A one-line comment ends at the line's end, or at "?>" if that
            # comes first (PHP's own rule).
            line_end = source.find("\n", i)
            line_end = n if line_end == -1 else line_end
            tag = source.find("?>", i, line_end)
            end = line_end if tag == -1 else tag
            out.append(" " * (end - i))
            i = end
        elif ch in ("'", '"'):
            j = i + 1
            while j < n and source[j] != ch:
                j += 2 if source[j] == "\\" else 1
            j = min(j + 1, n)
            out.append(ch + re.sub(r"[^\n]", " ", source[i + 1 : j - 1]) + (ch if j - 1 > i else ""))
            i = j
        else:
            out.append(ch)
            i += 1
    return "".join(out)


def findings_in(text: str, pattern: re.Pattern[str], harmless: re.Pattern[str] | None = None) -> list[int]:
    """The line number of every match of `pattern`, leaving out any inside a harmless form."""
    safe_spans = [m.span() for m in harmless.finditer(text)] if harmless is not None else []
    lines: list[int] = []
    for m in pattern.finditer(text):
        if any(start <= m.start() < end for start, end in safe_spans):
            continue
        lines.append(text.count("\n", 0, m.start()) + 1)
    return lines


def check(web: Path) -> int:
    problems: list[str] = []
    seen: dict[str, dict[str, list[int]]] = {"identifier": {}, "whole": {}}
    files_read = 0

    for root, dirs, files in os.walk(web):
        dirs[:] = sorted(d for d in dirs if d not in SKIP_DIRS)
        for name in sorted(files):
            if not name.endswith(".php"):
                continue
            path = Path(root) / name
            try:
                code = strip_comments_and_text(path.read_text(encoding="utf-8", errors="replace"))
            except OSError as error:
                problems.append(f"{path}: could not be read ({error}).")
                continue
            files_read += 1
            rel = path.relative_to(web).as_posix()
            found = findings_in(code, IDENTIFIER)
            if found:
                seen["identifier"][rel] = found
            found = findings_in(code, WHOLE_SESSION, HARMLESS_WHOLE)
            if found:
                seen["whole"][rel] = found

    rules = (
        ("identifier", IDENTIFIER_READERS, "reads the session identifier",
         "Store Logger::sessionFingerprint() instead: the identifier is the sign-in cookie."),
        ("whole", WHOLE_SESSION_USERS, "uses the whole session",
         "Keep only named facts: see Logger::sessionFacts(). A copy of the whole session copies every secret in it."),
    )
    for kind, allowed, what, instead in rules:
        for rel, lines in sorted(seen[kind].items()):
            where = ", ".join(f"line {n}" for n in lines)
            if rel not in allowed:
                problems.append(
                    f"web/{rel} ({where}) {what}, and the file is not on this check's list.\n"
                    f"      {instead}\n"
                    f"      If this use stores nothing and sends nothing, add the file to the list in this check, with the reason."
                )
            elif len(lines) > allowed[rel][0]:
                problems.append(
                    f"web/{rel} ({where}) {what} {len(lines)} times; the list allows {allowed[rel][0]}.\n"
                    f"      The use that is allowed: {allowed[rel][1]}\n"
                    f"      {instead}"
                )
        for rel in sorted(allowed):
            if not (web / rel).is_file():
                problems.append(
                    f"web/{rel} is on this check's list and is not there. Take it off the list, or put right what moved it: "
                    f"a list that names a file that is gone is not being read by anybody."
                )
            elif rel not in seen[kind]:
                problems.append(
                    f"web/{rel} is on this check's list, and no longer {what}. Take it off the list, so that the list says "
                    f"only what is true."
                )

    if files_read == 0:
        problems.append(f"No PHP file was found under {web}. Nothing was checked, which is not the same as nothing found.")

    if not problems:
        print(
            f"check_session_id_not_stored: OK. {files_read} PHP files read under {web.name}/; the session identifier is "
            f"read only in the {len(IDENTIFIER_READERS)} listed places and the whole session is used only in the "
            f"{len(WHOLE_SESSION_USERS)} listed places."
        )
        return 0

    print("check_session_id_not_stored: FOUND\n")
    for problem in problems:
        print(f"  - {problem}\n")
    print("Why it matters: the session identifier is the sign-in cookie, and the session holds whatever a page put")
    print("there. Neither may reach a table, a file or another service (issue 622).")
    return 1


def main(argv: list[str]) -> int:
    web = REPO_ROOT / "web"
    for i, arg in enumerate(argv):
        if arg == "--web" and i + 1 < len(argv):
            web = Path(argv[i + 1]).resolve()
    if not web.is_dir():
        print(f"check_session_id_not_stored: {web} is not a folder. Nothing was checked.")
        return 1
    return check(web)


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
