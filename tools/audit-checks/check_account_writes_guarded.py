#!/usr/bin/env python3
"""
Account-write guard coverage check (#518).

Before this issue, several pages that write to `tblUsers` or the tables
that sit alongside it — `tblLocalAccounts`, `tblUserSites`,
`tblWebAuthnCredentials`, `tblLinkedAccounts`, `tblTrustedDevices`,
`tblPasswordResets`, `tblTotpBackupCodes`, `tblDbsChecks`,
`tblUserRoles` — trusted nothing more than "is this person an
administrator of whichever organisation is open right now"
(`App::isAdmin()`). That let an administrator of ONE organisation change,
or create, an account belonging to ANY organisation, including a global
administrator's own account, or grant themselves the portal-wide
`isAdmin` flag. The fix is `Portal\\Core\\AccountGuard` — the one place
that decides how far such a change is allowed to reach.

This check does not, and cannot, prove `AccountGuard` is used
CORRECTLY — only that a file which writes one of those tables also
MENTIONS the class somewhere. A file that calls `AccountGuard::` in a
way that never actually decides anything would still pass. That is a
deliberate trade-off: a check this blunt is cheap to run on every pull
request and catches the shape of fault #518 actually was — a whole file
with NO ownership check of any kind — while a check thorough enough to
verify correct USE would need to understand what each page is trying to
do, which is what the real-database test plan and a human/AI review are
for instead.

WHAT THIS CANNOT SEE (be honest about the blind spots, not just the
catch):
  - SQL assembled from variables rather than written out as a plain
    string next to the table name (e.g. a table name built from an
    array, as `Portal\\Core\\GdprEraser`'s erasure catalogue does).
  - A statement built by concatenating several string pieces where the
    table name itself is one of the pieces rather than sitting in the
    same string literal as the verb.
  - A write made through a shared helper function defined in a
    DIFFERENT file — the write itself would need to be found in THAT
    file, not the one that calls the helper.
  - Whether `AccountGuard::` actually gates the write that follows it,
    as opposed to appearing anywhere else in the same file (a stale
    comment, a different code path). The self-test
    (`tools/account-guard-selftest.php`) and the real-database test plan
    are what prove the RULE itself is right; this check only proves
    every matching file at least reaches for it somewhere.

Exit code:
  0 — no findings (every matching file mentions AccountGuard::, or is on
      the ALLOWED list with a stated reason, and every ALLOWED entry
      still exists and still matches)
  1 — at least one finding (a matching file with no AccountGuard:: and
      not on the list, OR a stale ALLOWED entry). This is NOT gated
      behind --strict, unlike most checks in this directory — --strict
      is still accepted, for consistency with how the other scripts here
      are invoked, but makes no difference to the exit code. A change
      that removes account-ownership checking from a live handler is
      exactly the shape of fault #518 was, so this one is treated as
      always-blocking rather than a heuristic to review later.

Usage:
  python3 tools/audit-checks/check_account_writes_guarded.py [--strict]
  python3 tools/audit-checks/check_account_writes_guarded.py --web <dir>
    (--web replaces the "web" directory name this scans under — used by
    the #518 build's own proof to point the check at a throwaway scratch
    copy, WITHOUT ever touching the real working tree.)
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

# 🏛️ Every table an account-takeover could reach, per the #518 sweep
# (web/_core/AccountGuard.php's own docblock names the three proven
# takeovers this list exists to stop happening again). Deliberately the
# SAME table list AccountGuard's own docblock and the #518 plan's sweep
# commands used, so this check and that sweep can never quietly drift
# apart from each other.
GUARDED_TABLES = (
    "tblUsers",
    "tblLocalAccounts",
    "tblUserSites",
    "tblWebAuthnCredentials",
    "tblLinkedAccounts",
    "tblTrustedDevices",
    "tblPasswordResets",
    "tblTotpBackupCodes",
    "tblDbsChecks",
    "tblUserRoles",
)

# Matches: UPDATE tblX / INSERT INTO tblX / INSERT IGNORE INTO tblX /
# REPLACE INTO tblX / DELETE FROM tblX — an optional backtick either side
# of the table name, case-insensitive verbs (SQL keywords are commonly
# written upper-case in this codebase, but not everywhere).
WRITE_RE = re.compile(
    r"\b(?:UPDATE|INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|DELETE\s+FROM)\s+"
    r"`?(" + "|".join(GUARDED_TABLES) + r")\b",
    re.IGNORECASE,
)

MARKER = "AccountGuard::"

# 📋 Every file this check has already confirmed writes one of the tables
# above WITHOUT going through AccountGuard, and WHY that is fine — each
# one only ever acts on the SIGNED-IN PERSON'S OWN account/session, is a
# global-administrator-only page, or (invites/accept.php) never writes
# the portal-wide isAdmin flag. Relative to `web/`.
#
# An entry that no longer exists, or whose file no longer matches
# WRITE_RE at all, is treated as a FINDING too (a "stale entry") — this
# list is a set of decisions about REAL code, not a wish-list, and a
# decision about code that no longer exists or no longer does what it
# once did needs re-making, not silently carrying forward.
ALLOWED: dict[str, str] = {
    "_apps/admin/maintenance/demo-data.php": "global administrators only (App::isRootAdmin())",
    "_apps/admin/sites/users.php": "global administrators only (App::isUmbrellaAdmin())",
    "_apps/auth/2fa/disable.php": "the signed-in person's own account",
    "_apps/auth/2fa/setup.php": "the signed-in person's own account",
    "_apps/auth/2fa/verify.php": "the person completing their own sign-in",
    "_apps/auth/account/change-password.php": "own account",
    "_apps/auth/account/delete-confirm.php": "own account",
    "_apps/auth/account/notifications-save.php": "own account",
    "_apps/auth/account/save.php": "own account",
    "_apps/auth/account/webauthn-delete.php": "own account",
    "_apps/auth/account/webauthn.php": "own account",
    "_apps/auth/forgot-password/save.php": "anonymous; creates a reset token sent to the account's own address",
    "_apps/auth/reset-password/save.php": "anonymous; needs a valid reset token",
    "_apps/auth/login/webauthn.php": "signing in",
    "_apps/calendar/account-feed.php": "own account",
    "_apps/directory/save.php": "own account",
    "_apps/invites/accept.php": "creates the invited person's new account; never sets portal-wide rights (#518)",
    "_core/Auth.php": "sign-in: updates the signed-in person's own record, links and devices, and adds a new single-sign-on account to the organisation where they signed in",
    "_core/I18n.php": "own language choice",
    "_core/Ical.php": "own calendar token",
}


def strip_php_comments(text: str) -> str:
    """
    Strip `//` line, `#` line, and `/* … */` block PHP comments while
    preserving line numbers — the same approach check_php_table_refs.py
    already uses in this directory, so a comment that happens to mention
    "UPDATE tblUsers" in passing (documenting a past fix, for instance)
    doesn't produce a false finding. A `#` immediately followed by `[` is
    left alone, because that is a PHP 8 attribute, not a comment.
    """
    text = re.sub(
        r"/\*.*?\*/",
        lambda m: "\n" * m.group(0).count("\n"),
        text,
        flags=re.DOTALL,
    )
    text = re.sub(r"//[^\n]*", "", text)
    text = re.sub(r"#(?!\[)[^\n]*", "", text)
    return text


def scan(web_dir: Path) -> tuple[list[str], list[str]]:
    """
    Return (findings, stale_allowed) — findings are file paths (relative
    to web_dir's parent, i.e. starting with the web dir's own name) that
    write a guarded table but never mention AccountGuard:: and are not on
    the ALLOWED list; stale_allowed are ALLOWED keys that no longer exist
    or no longer match at all.
    """
    findings: list[str] = []
    matched_allowed: set[str] = set()

    roots = [web_dir / "_apps", web_dir / "_core"]
    for root in roots:
        if root.exists() is False:
            continue
        for php in sorted(root.rglob("*.php")):
            # The guard class itself obviously writes nothing and must not
            # be asked to guard itself.
            if php.name == "AccountGuard.php":
                continue
            try:
                raw = php.read_text(encoding="utf-8", errors="ignore")
            except OSError:
                continue
            text = strip_php_comments(raw)
            if WRITE_RE.search(text) is None:
                continue

            rel = php.relative_to(web_dir).as_posix()

            if MARKER in raw:
                # Guarded (or at least the marker is present somewhere in
                # the file — see the module docstring's honest limits).
                continue

            if rel in ALLOWED:
                matched_allowed.add(rel)
                continue

            findings.append(rel)

    stale = sorted(set(ALLOWED.keys()) - matched_allowed)
    return findings, stale


def check() -> int:
    web_arg_index = None
    web_dir = REPO_ROOT / "web"
    for i, arg in enumerate(sys.argv):
        if arg == "--web" and i + 1 < len(sys.argv):
            web_dir = Path(sys.argv[i + 1]).resolve()
            web_arg_index = i
    if web_arg_index is not None:
        print(f"Scanning: {web_dir} (--web override)")
    else:
        print(f"Scanning: {web_dir}")

    if web_dir.exists() is False:
        print(f"ERROR: {web_dir} does not exist.")
        return 1

    findings, stale = scan(web_dir)

    print(f"Guarded-table writes with no AccountGuard:: and not on ALLOWED: {len(findings)}")
    print(f"Stale ALLOWED entries (no longer exist, or no longer match): {len(stale)}")
    print()

    if findings:
        print("### Files that write an account/access table without AccountGuard::\n")
        for rel in findings:
            print(f"  • {rel}")
        print(
            "\nEither add a Portal\\Core\\AccountGuard:: check to this file, or — ONLY if "
            "this write genuinely cannot reach beyond one organisation (e.g. it acts "
            "solely on the signed-in person's own account) — add it to ALLOWED in this "
            "script with a one-line reason, the same way the existing entries are "
            "documented.\n"
        )

    if stale:
        print("### Stale ALLOWED entries\n")
        for rel in stale:
            print(f"  • {rel} — no longer exists, or no longer writes a guarded table at all")
        print(
            "\nRemove the entry (the decision it recorded no longer applies to any real "
            "code), or fix the path if the file simply moved.\n"
        )

    # Deliberately NOT gated behind --strict (see the module docstring):
    # a finding here is the exact shape #518 was, so it is always
    # blocking. --strict is still accepted so this script can be invoked
    # the same way as every other checker in this directory.
    if findings or stale:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(check())
