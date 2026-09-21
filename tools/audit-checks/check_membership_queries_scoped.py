#!/usr/bin/env python3
"""
Group and department membership queries must name an organisation (#517).

WHY THIS CHECK EXISTS
-------------------------------------------------------------------------
Before #517, user groups (`tblGroups`, members in `tblUserGroups`) had no
organisation at all, and neither membership table said which organisation
a membership belonged to. Every one of the eleven places that read the two
membership tables — workflow approval by group, asset ownership by group or
department, expense approval by department, the expense e-mails — joined
them by person and group/department number alone, e.g.

    JOIN tblUserGroups ug ON ug.userID = u.userID

with no organisation anywhere in sight. So a membership left behind in an
organisation somebody had left, or a group number from another
organisation, still counted. Migration 203 gave both tables a required
`siteID`, and #517 rewrote every reader to test it. This check exists to
stop the next hand-written query from quietly going back to the old shape.

WHAT IT CHECKS
-------------------------------------------------------------------------
Every `.php` file under `web/` (comments stripped first, line numbers kept).
For every line that names `tblUserGroups` or `tblUserDepts` as a whole word,
the window made of that line and the 10 lines either side of it must
contain the text `siteID` somewhere. If it does not, that line is a
finding. "10 lines either side", not just the lines after: an audit record
such as `Logger::audit('tblUserGroups', ...)` sits AFTER the query whose
`siteID` scoped it, and must not be reported.

Whole-word matching means the two "awaiting placement" tables,
`tblUserGroupsUnplaced` and `tblUserDeptsUnplaced`, are NOT checked, by
design: they are pens that grant nothing, read only by a global-
administrator page, and have no organisation column to test.

ALLOWED (below) lists the few files that mention the tables on purpose
without an organisation, each with its reason. An ALLOWED entry whose file
no longer exists, or no longer mentions either table at all, is itself a
finding (a "stale entry"): the list records decisions about real code, and
a decision about code that has gone needs re-making, not carrying forward.

WHAT THIS CHECK CANNOT SEE (be honest about the blind spots)
-------------------------------------------------------------------------
  - Whether the `siteID` it finds is really a scoping condition on THAT
    query. Any mention of the word within the window satisfies it — a
    different query nearby, a variable name, an array key. It catches the
    shape #517 fixed (a membership join with no organisation anywhere near
    it); it does not prove the organisation test is correct. The #517
    build's real-database proofs, and review, do that.
  - A query whose membership-table line sits more than 10 lines away from
    its `siteID` (a very long statement) is reported even if it is fine;
    move the condition closer or split the statement.
  - SQL whose table name is held in a variable or built from pieces (as
    `Portal\\Core\\GdprEraser`'s erasure list does) is not seen at all.
  - Files outside `web/`, and anything that is not a `.php` file (the
    numbered migrations are SQL and are checked by other scripts).
  - The two "awaiting placement" pen tables (by design; see above).

Exit code:
  0 — no findings and no stale ALLOWED entries.
  1 — at least one finding or stale entry. This is NOT gated behind
      --strict: an unscoped membership query is exactly the second fault
      #517 fixed, so it always blocks, the same deliberate choice
      check_account_writes_guarded.py and check_role_keys.py made. --strict
      is still accepted so this script can be invoked like the others.

Usage:
  python3 tools/audit-checks/check_membership_queries_scoped.py [--strict]
  python3 tools/audit-checks/check_membership_queries_scoped.py --web <dir>
    (--web replaces the "web" directory this scans — used to point the
    check at a throwaway scratch copy for its own proof, WITHOUT touching
    the real working tree; the check_role_keys.py convention.)
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

# Whole words only, so tblUserGroupsUnplaced / tblUserDeptsUnplaced (the
# pens) do not match: after "tblUserGroups" the next character there is a
# letter, so there is no word boundary.
TABLE_RE = re.compile(r"\b(?:tblUserGroups|tblUserDepts)\b")

# The window: the line itself plus this many lines before AND after it.
WINDOW = 10

MARKER = "siteID"

# 📋 Files that mention a membership table on purpose with no organisation,
# and why. Relative to `web/`. Nothing else belongs here: a new entry needs
# the same kind of reason, written down.
ALLOWED: dict[str, str] = {
    "_apps/offboarding/do.php": "whole-portal exit: deletes every organisation's memberships on purpose (steps 6a/6b)",
    "_core/GdprEraser.php": "erasure list entries (table names in an array), not queries",
    "_core/personal-data-catalogue.php": "erasure list entries (table names as array keys), not queries",
}


def strip_php_comments(text: str) -> str:
    """
    Strip `//` line, `#` line and `/* … */` block PHP comments while keeping
    line numbers — the same approach check_account_writes_guarded.py and
    check_role_keys.py use, so a comment describing a past fault (as many
    #517 comments do, quoting the old unscoped join) is never reported. A
    `#` immediately followed by `[` is left alone (a PHP 8 attribute).

    Known limit, shared with those two scripts: a `//` or `#` inside a
    quoted string is treated as a comment too, so text after it on that
    line is not read. In SQL strings this is rare.
    """
    text = re.sub(r"/\*.*?\*/", lambda m: "\n" * m.group(0).count("\n"), text, flags=re.DOTALL)
    text = re.sub(r"//[^\n]*", "", text)
    text = re.sub(r"#(?!\[)[^\n]*", "", text)
    return text


def scan(web_dir: Path) -> tuple[list[str], list[str]]:
    """
    Return (findings, stale) — findings are "path:line — text" strings for
    membership-table lines with no `siteID` in their window; stale are
    ALLOWED keys whose file is gone or no longer names either table.
    """
    findings: list[str] = []
    mentioned_allowed: set[str] = set()

    for php in sorted(web_dir.rglob("*.php")):
        try:
            raw = php.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        lines = strip_php_comments(raw).split("\n")
        hits = [i for i, line in enumerate(lines) if TABLE_RE.search(line) is not None]
        if len(hits) == 0:
            continue

        rel = php.relative_to(web_dir).as_posix()
        if rel in ALLOWED:
            mentioned_allowed.add(rel)
            continue

        for i in hits:
            window = "\n".join(lines[max(0, i - WINDOW): i + WINDOW + 1])
            if MARKER not in window:
                findings.append(f"{rel}:{i + 1} — {lines[i].strip()[:120]}")

    stale = sorted(set(ALLOWED.keys()) - mentioned_allowed)
    return findings, stale


def check() -> int:
    web_dir = REPO_ROOT / "web"
    override = False
    for i, arg in enumerate(sys.argv):
        if arg == "--web" and i + 1 < len(sys.argv):
            web_dir = Path(sys.argv[i + 1]).resolve()
            override = True
    print(f"Scanning: {web_dir}" + (" (--web override)" if override else ""))

    if web_dir.exists() is False:
        print(f"ERROR: {web_dir} does not exist.")
        return 1

    findings, stale = scan(web_dir)

    print(f"Membership-table lines with no siteID within {WINDOW} lines either side: {len(findings)}")
    print(f"Stale ALLOWED entries (file gone, or no longer names either table): {len(stale)}")
    print()

    if findings:
        print("### Group/department membership queries with no organisation\n")
        for line in findings:
            print(f"  • {line}")
        print(
            "\nSince #517 every group and department membership belongs to ONE organisation "
            "(tblUserGroups.siteID / tblUserDepts.siteID). Tie the membership to the organisation "
            "the query is about — e.g. `ug.siteID = o.siteID`, or use UserGroups::isMember() / "
            "Departments::isMember() / their memberSql() fragments. Only if the query genuinely "
            "must cover every organisation (as offboarding does), add the file to ALLOWED in this "
            "script with a one-line reason.\n"
        )

    if stale:
        print("### Stale ALLOWED entries\n")
        for rel in stale:
            print(f"  • {rel} — no longer exists, or no longer names tblUserGroups/tblUserDepts")
        print("\nRemove the entry, or fix the path if the file simply moved.\n")

    # Deliberately NOT gated behind --strict — see the module docstring.
    if findings or stale:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(check())
