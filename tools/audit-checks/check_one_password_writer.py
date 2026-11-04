#!/usr/bin/env python3
"""
One place hashes and stores a password (#728).

Path: tools/audit-checks/check_one_password_writer.py

WHY THIS EXISTS
---------------
`Portal\\Core\\Auth::setPassword()` is the one place a sign-in password is
hashed and written. Before #728, EIGHT places did it with their own SQL: the
installer, `invites/accept.php`, `users/api/create.php`,
`auth/reset-password/save.php`, `auth/account/change-password.php`, TWO sites in
`admin/users/save.php`, and `offboarding/do.php`.

**Every one of them was correct about hashing.** The cost was that a rule about
passwords could not be enforced anywhere, and #586 was exactly such a rule: a
password cannot be stored for an account with no `tblLocalAccounts` row. Its fix
was a read-back inside ONE page's transaction, which protected that page and
nothing else — and that page's read-back asked only whether a hash was PRESENT,
so an old hash left behind by a write that never landed would have passed it.

**Without this check the conversion gets undone one feature at a time**, which
is #739's lesson about hard-coded role gates, in a path where the cost is a
member who cannot sign in or a password that was never stored.

WHAT IT CHECKS
--------------
  1. **Nothing under `web/` calls `password_hash()`** except `Auth.php` and the
     files named in ALLOWED, each with a reason.
  2. **Nothing under `web/` writes the `passwordHash` column** in SQL except
     those same files. A write is an `INSERT INTO tblLocalAccounts` naming the
     column, or an `UPDATE ... SET passwordHash`.
  3. **It fails BOTH ways.** A file in ALLOWED that no longer writes a password
     is also a failure — that is how the migration harness's exception list
     went stale unnoticed, and a stale exception makes the check look stronger
     than it is.
  4. **`Auth.php` must still hold the one writer.** If `setPassword()`,
     `setUnusablePassword()` or the private `storeHash()` behind them goes, this
     check's whole premise has gone with it and it says so rather than passing
     on an empty field.

HOW IT READS THE SOURCE, and why it does not just grep
------------------------------------------------------
Comments are replaced by spaces of the same length, tracking string literals as
it goes, so a line number is never wrong and an apostrophe inside `// don't`
cannot put every later quote out of step. That exact fault has been found twice
in this repository's own checks (`check_page_head_tags.py`,
`check_no_outside_qr_service.py`). String literals are KEPT, because the SQL
this check is looking for lives inside them.

WHAT IT CANNOT DO, said plainly because an overstated guarantee is worse than
none
-----------------------------------------------------------------------------
  * **It cannot see a column or table name built by joining pieces** —
    `'password' . 'Hash'`, or a name held in a variable. Measured: nothing in
    this repository does that, and a check that tried to would have to evaluate
    PHP.
  * **It cannot see a write made through a helper** that takes a column name as
    an argument. Nothing here has one.
  * **It cannot see a write with no SQL verb of its own**, such as a stored
    procedure, a trigger, or an `ALTER TABLE … SET DEFAULT`.
  * It judges a SPAN between write verbs, not a parsed statement. So a file
    that writes a different table and, within the same span, merely READS
    `passwordHash` from `tblLocalAccounts` would be reported. No file here does
    that — measured — and the remedy if one appears is to narrow it with
    evidence, never to go back to the distance windows that let three shapes
    through.
  * **It cannot tell whether `Auth::setPassword()` is CORRECT**, only that it is
    the only thing doing the job. Its behaviour is proved by
    `tools/password-writer-selftest.php`.
  * **It does not look outside `web/`.** `tools/` and `docs/` may legitimately
    show the SQL — the disaster-recovery runbook prints an UPDATE for somebody
    locked out of their own installation, and that is the point of it.

Package: WebMS-Intra / audit checks
Author: Claude Opus 5 (for MWBM Partners Ltd, t/a MWservices)
Copyright: All Rights Reserved -- MWBM Partners Ltd
Version: 1.0.0
"""

from __future__ import annotations

import os
import re
import sys

WEB = "web"

# The ONE place. Not an exception — the subject.
THE_ONE_PLACE = os.path.join("web", "_core", "Auth.php")

# The three methods that make it the one place. If these go, so does the
# premise, and this check must say so rather than pass on an empty field.
MUST_STILL_BE_THERE = ("setPassword", "setUnusablePassword", "storeHash")

# Files allowed to hash or write a password themselves, each with the reason.
# FAILS BOTH WAYS: an entry that no longer does it is as much a failure as an
# unlisted file that does.
ALLOWED = {
    os.path.join("web", "_apps", "offboarding", "do.php"):
        "Takes a password AWAY, and must reach EVERY row of an account "
        "(`WHERE userID = ?`), where Auth::setPassword() refuses an account "
        "with more than one set of sign-in details rather than choosing "
        "between them. It is also one step in a uniform list of audited "
        "steps, each recorded by name; a call outside that list would lose "
        "its record. The owner's rule is one place SETS a password — removal "
        "reaching every row is the safe direction, and is stated in "
        "Auth::setPassword()'s own docblock.",
    os.path.join("web", "_apps", "auth", "2fa", "setup.php"):
        "Hashes a two-factor BACKUP CODE, not a password: PASSWORD_BCRYPT "
        "into tblTotpBackupCodes, a different table with a different "
        "lifetime. #613 makes that page the one place a backup code is made.",
    os.path.join("web", "_core", "AssetRegister.php"):
        "Hashes a KIOSK PIN into the asset register's own table. Not a "
        "sign-in password: a kiosk PIN unlocks a shared machine's screen and "
        "is checked by that app alone.",
}

HASH_CALL = re.compile(r"\bpassword_hash\s*\(")

# 🧩 HOW A WRITE IS RECOGNISED, AND WHY IT IS NOT ONE BIG REGEX ANY MORE.
#
# Version 1 used two distance-windowed patterns: "UPDATE tblLocalAccounts,
# then within 200 characters SET, then within 200 more passwordHash", and an
# INSERT form with a 300-character window. An independent check got THREE
# shapes past it, each measured:
#
#   * `REPLACE INTO tblLocalAccounts (…, passwordHash) VALUES (…)` — the verb
#     was not in the pattern at all.
#   * `UPDATE tblUsers U JOIN tblLocalAccounts LA ON … SET LA.passwordHash = ?`
#     — the table named is `tblUsers`, and the column is written through an
#     alias.
#   * An INSERT whose column list is longer than 300 characters before
#     `passwordHash` — nothing clever, just a long list. **Note this one
#     defeats the window, not the pattern**, so version 1's own "cannot see a
#     name built by joining pieces" admission did not cover it.
#
# So the question is asked differently now, with NO distance window: split the
# file at each SQL write verb, and flag a span that names BOTH the table and
# the column. A span is everything from one write verb to the next, so a long
# column list, an alias and a join are all inside it.
#
# The cost of dropping the windows is a possible false alarm: a file that
# writes some OTHER table and then, in the same span, merely READS
# `passwordHash` from `tblLocalAccounts`. Measured against this repository: no
# file does that, and the check reports exactly the four known writers. A
# check that cries wolf gets switched off (#483), so if that ever changes the
# answer is to narrow it with evidence, never to put the windows back.
WRITE_VERB = re.compile(r"\b(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE)\b", re.I)
TABLE_NAME = re.compile(r"[`\"']?\btblLocalAccounts\b[`\"']?", re.I)
COLUMN_NAME = re.compile(r"[`\"]?\bpasswordHash\b[`\"]?", re.I)


def sql_writes(code: str) -> list[int]:
    """
    Character offsets of every SQL span that writes `tblLocalAccounts.passwordHash`.

    A span runs from one write verb to the next, so neither a long column list,
    nor a table alias, nor a join can put the column out of reach.
    """
    starts = [match.start() for match in WRITE_VERB.finditer(code)]
    found: list[int] = []
    for index, at in enumerate(starts):
        end = starts[index + 1] if index + 1 < len(starts) else len(code)
        span = code[at:end]
        if TABLE_NAME.search(span) is not None and COLUMN_NAME.search(span) is not None:
            found.append(at)
    return found


def blank_comments(src: str) -> str:
    """
    Replace every comment with spaces of the SAME LENGTH, keeping newlines, so
    reported line numbers stay exact and string literals are left alone.

    String literals are NOT removed: the SQL this check looks for is inside
    them. That is the opposite choice from the masking checks in this folder,
    and it is deliberate — stated here because the two are easy to confuse.
    """
    out = []
    i = 0
    n = len(src)
    while i < n:
        ch = src[i]
        if ch in ("'", '"'):
            quote = ch
            out.append(ch)
            i += 1
            while i < n:
                if src[i] == "\\" and i + 1 < n:
                    out.append(src[i:i + 2])
                    i += 2
                    continue
                out.append(src[i])
                if src[i] == quote:
                    i += 1
                    break
                i += 1
            continue
        if src.startswith("//", i) or ch == "#":
            end = src.find("\n", i)
            if end == -1:
                end = n
            out.append(" " * (end - i))
            i = end
            continue
        if src.startswith("/*", i):
            end = src.find("*/", i + 2)
            end = n if end == -1 else end + 2
            # Keep the newlines so line numbers do not move. Deleting them put
            # reported lines 370 out in check_no_brotli_decoding.py.
            out.append("".join("\n" if c == "\n" else " " for c in src[i:end]))
            i = end
            continue
        out.append(ch)
        i += 1
    return "".join(out)


def line_of(src: str, at: int) -> int:
    return src.count("\n", 0, at) + 1


def main() -> int:
    # The repository root: this file sits in tools/audit-checks/, so it is TWO
    # folders up from its own directory. Counted rather than guessed — a
    # draft was one short and reported "web/ is not there".
    here = os.path.dirname(os.path.abspath(__file__))
    root = os.path.dirname(os.path.dirname(here))
    web = os.path.join(root, WEB)
    if os.path.isdir(web) is False:
        print(f"FAIL: {WEB}/ is not there.")
        return 1

    problems: list[str] = []
    writers: dict[str, list[str]] = {}

    for folder, _dirs, files in os.walk(web):
        for name in sorted(files):
            if name.endswith(".php") is False:
                continue
            full = os.path.join(folder, name)
            relative = os.path.relpath(full, root)
            with open(full, "r", encoding="utf-8", errors="replace") as handle:
                raw = handle.read()
            code = blank_comments(raw)

            found: list[str] = []
            for match in HASH_CALL.finditer(code):
                found.append(f"calls password_hash() at line {line_of(code, match.start())}")
            for at in sql_writes(code):
                found.append(f"writes the passwordHash column at line {line_of(code, at)}")
            if len(found) > 0:
                writers[relative] = found

    # 1. The one place must still hold the one writer.
    one = writers.pop(THE_ONE_PLACE, None)
    if one is None:
        problems.append(
            f"{THE_ONE_PLACE} no longer hashes or stores a password at all. That is this "
            f"check's whole premise, so it is a failure rather than a clean field: if the one "
            f"place has gone, every caller has been changed to do it itself or the feature has "
            f"been removed."
        )
    else:
        with open(os.path.join(root, THE_ONE_PLACE), "r", encoding="utf-8") as handle:
            auth = blank_comments(handle.read())
        for method in MUST_STILL_BE_THERE:
            if re.search(r"function\s+" + method + r"\s*\(", auth) is None:
                problems.append(
                    f"{THE_ONE_PLACE} no longer has a `{method}()` method. One place storing a "
                    f"password is what #728 built; losing one of its three parts means callers "
                    f"have gone back to their own SQL, or will have to."
                )

    # 2. Everything else must be listed, with a reason.
    for relative in sorted(writers):
        if relative not in ALLOWED:
            problems.append(
                f"{relative} hashes or stores a password itself ({'; '.join(writers[relative])}). "
                f"`Portal\\Core\\Auth::setPassword()` is the ONE place that does this (#728) — "
                f"call it instead. If this file genuinely must do its own, add it to ALLOWED in "
                f"{os.path.basename(__file__)} WITH THE REASON, so the exception is a decision on "
                f"record rather than a silent second writer."
            )

    # 3. BOTH WAYS: a listed file that no longer does it.
    for relative in sorted(ALLOWED):
        if relative not in writers:
            exists = os.path.isfile(os.path.join(root, relative))
            problems.append(
                f"{relative} is in ALLOWED but no longer hashes or stores a password"
                + ("" if exists else " (the file is not there any more)")
                + f". Take it off the list in {os.path.basename(__file__)}. A stale exception "
                f"makes this check look stronger than it is, which is how the migration "
                f"harness's exception list went unnoticed."
            )

    # Count the two kinds apart. A draft printed "3 other file(s) ... hash or
    # store one" on a GREEN run, which reads as three unlisted writers — the
    # number was right and the sentence was not.
    listed = sum(1 for relative in writers if relative in ALLOWED)
    unlisted = len(writers) - listed
    print(
        f"One place stores a password: {THE_ONE_PLACE}, plus {listed} of {len(ALLOWED)} "
        f"file(s) allowed by name doing so; {unlisted} unlisted file(s) under {WEB}/ "
        f"hash or store a password."
    )

    if len(problems) > 0:
        print("")
        for problem in problems:
            print(f"FAIL: {problem}")
        print("")
        print(f"{len(problems)} problem(s).")
        return 1

    print("OK: no second place hashes or stores a sign-in password.")
    print(
        "   This CANNOT see a column name built by joining pieces or held in a variable, and "
        "cannot tell whether Auth::setPassword() is correct — only that it is the only thing "
        "doing the job."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
