#!/usr/bin/env python3
"""
A rate-limit row never holds a network address (#712).

Path: tools/audit-checks/check_no_raw_address_stored.py

WHY THIS EXISTS
---------------
A network address is personal data. Two tables held one unchanged and neither
was ever cleared out:

  - `tblLiveRateLimits.senderIP` held the viewer's address, and NOTHING removed
    a row -- no clear-out anywhere, no foreign key that could cascade in, no
    scheduled job -- under an index named `idx_ratelimit_prune`, for a prune
    that did not exist. Migration 236 renamed the column to
    `senderFingerprint`.
  - `tblApiRateLimits.bucket` is a single piece of text, and two kinds of bucket
    built it from the raw address with no hashing at all: the two-factor code
    check -- which pairs it with an ACCOUNT NUMBER, making the row a record of
    which person signed in from where -- and the push-notification sign-up.
    Four others DID hash, but with a plain unsalted SHA-256, which for a 32-bit
    IPv4 is reversible in about fifteen minutes on an ordinary machine (measured:
    4.87 million SHA-256 a second, and an independent check recovered an address
    from a stored value in under a second). So all six now go through
    `Portal/Core/AddressFingerprint::of()`, which salts with the installation's
    own key -- a key that is not in the database, so a database copy alone no
    longer says which addresses the portal has seen. An earlier version of this
    docstring said "three other buckets already hashed", which was wrong twice:
    the count was six, and hashing was not the same as being safe.

Nothing failed while either fault was there. Every page worked, the limits
worked, and the rows simply accumulated. So this check reads the code.

WHAT IT LOOKS FOR, under web/, in PHP code with comments and quoted text
removed first (so a sentence naming a function is not a breach)
-----------------------------------------------------------------------
1. A bucket name built from an address that does not go through
   `AddressFingerprint::of()`: any assignment whose name contains "bucket" (any
   case) whose value mentions an address, FOLLOWED ONE HOP through a local
   variable. The one hop is what makes it work -- the real pre-fix code assigned
   `$clientIp = RateLimiter::clientIp();` on one line and built the bucket from
   `$clientIp` on the next, and a first version of this check, which required
   the literal `clientIp()` inside the assignment, went green on it.

Checks 1 to 3 read the source with comments AND string contents blanked, so a
sentence in a comment is never a breach. Check 4 pins a literal, so it reads the
source with comments blanked and strings LEFT ALONE -- against the masked form
`hash('sha256', $ip)` could never match, and against the raw file a
commented-out copy of the pinned line would satisfy it (#569).
2. `RateLimiter::clientIp()` or `LiveChat::clientIp()` appearing in the same
   statement as a `bind_param` -- an address going straight into a statement.
3. The column `senderIP` named anywhere against `tblLiveRateLimits`, which would
   mean the rename has been undone.
4. `LiveChat::senderFingerprint()` must still exist and still hash. It is the
   ONE place the stored form is decided, asked by the writer and the reader, so
   removing it or making it return the address would reinstate the fault in both
   at once.

WHAT IT DELIBERATELY DOES NOT DO
--------------------------------
It does not object to an address being USED -- a limit has to be applied, and
`RateLimiter::clientIp()` is the one place that decides which address to
believe. It objects only to one being STORED.

It does not touch `tblLiveChatMessages.senderIP`, which keeps the address RAW
ON PURPOSE: the moderation screen shows it to an administrator deciding whether
to block somebody. That is a recorded decision with its own catalogue entry, and
a check that fought it would be wrong. The two columns were both called
`senderIP` until migration 236, which is exactly how the wrong one would get
changed.

It cannot see an address that reaches a column through a variable assigned
several statements earlier, or one built by concatenation across lines. It is
narrow on purpose: a check that cries wolf gets switched off (#483).

@link https://github.com/MWBMPartners/WebMS-Intra/issues/712
"""

import re
import sys
from pathlib import Path

WEB = Path(__file__).resolve().parents[2] / "web"
LIVECHAT = WEB / "_core" / "LiveChat.php"


def strip_php(text: str) -> str:
    """Blank comments and string CONTENTS, keeping newlines and quote marks."""
    out = []
    i = 0
    n = len(text)
    while i < n:
        two = text[i:i + 2]
        ch = text[i]
        if two == "//" or ch == "#":
            while i < n and text[i] != "\n":
                i += 1
            continue
        if two == "/*":
            i += 2
            while i < n and text[i:i + 2] != "*/":
                out.append("\n" if text[i] == "\n" else " ")
                i += 1
            i += 2
            continue
        if ch in "'\"":
            quote = ch
            out.append(quote)
            i += 1
            while i < n and text[i] != quote:
                if text[i] == "\\" and i + 1 < n:
                    i += 2
                    continue
                out.append("\n" if text[i] == "\n" else "x")
                i += 1
            out.append(quote)
            i += 1
            continue
        out.append(ch)
        i += 1
    return "".join(out)


def strip_comments_keep_strings(text: str) -> str:
    """
    Blank PHP comments but LEAVE string contents alone, keeping newlines.

    Needed because check 4 pins a literal: `hash('sha256', $ip)`. Against the
    masked source above that can never match, since the mask turns 'sha256'
    into 'xxxxxx' -- a trap this repository has hit more than once. Reading the
    RAW file instead is the other mistake, because then a commented-out copy of
    the pinned line satisfies the check (#569). So comments go and strings stay.
    """
    out = []
    i = 0
    n = len(text)
    while i < n:
        two = text[i:i + 2]
        ch = text[i]
        if two == "//" or ch == "#":
            while i < n and text[i] != "\n":
                i += 1
            continue
        if two == "/*":
            i += 2
            while i < n and text[i:i + 2] != "*/":
                out.append("\n" if text[i] == "\n" else " ")
                i += 1
            i += 2
            continue
        if ch in "'\"":
            quote = ch
            out.append(quote)
            i += 1
            while i < n and text[i] != quote:
                if text[i] == "\\" and i + 1 < n:
                    out.append(text[i:i + 2])
                    i += 2
                    continue
                out.append(text[i])
                i += 1
            out.append(quote)
            i += 1
            continue
        out.append(ch)
        i += 1
    return "".join(out)


BUCKET_ASSIGN = re.compile(r"\$\w*bucket\w*\s*=\s*([^;]{0,400});", re.I | re.S)


def main() -> int:
    problems = []

    for path in sorted(WEB.rglob("*.php")):
        code = strip_php(path.read_text(encoding="utf-8", errors="replace"))
        shown = path.relative_to(WEB.parent)

        # 1. A bucket built from an address and not fingerprinted.
        #
        #    FOLLOWS ONE HOP THROUGH A LOCAL VARIABLE, and that is the whole
        #    reason this check works. Its first version required the text
        #    `clientIp()` INSIDE the bucket assignment, and the real pre-fix
        #    code was:
        #
        #        $clientIp = RateLimiter::clientIp();
        #        $rlBucket = 'pushsub:' . $clientIp;
        #
        #    `$clientIp` is not `clientIp()`, so the live fault in the live file
        #    went GREEN. An independent check proved it by restoring the real
        #    pre-fix file and watching this script exit 0 — the one shape the
        #    fault is most likely to come back in, because it is the shape it
        #    already had. Renaming the local defeated it too.
        for match in BUCKET_ASSIGN.finditer(code):
            value = match.group(1)
            if "AddressFingerprint::of(" in value:
                continue

            tainted = "clientIp" in value
            if tainted is False:
                # One hop: does the assignment use a local that was itself
                # assigned from an address earlier in the file?
                for name in set(re.findall(r"\$(\w+)", value)):
                    earlier = re.search(
                        r"\$" + re.escape(name) + r"\s*=\s*([^;]{0,300});",
                        code[:match.start()],
                    )
                    if earlier is None:
                        continue
                    source = earlier.group(1)
                    if "clientIp" in source and "AddressFingerprint::of(" not in source:
                        tainted = True
                        break

            if tainted is False:
                continue

            line = code[:match.start()].count("\n") + 1
            problems.append(
                str(shown) + ":" + str(line) + " builds a rate-limit bucket from "
                "the visitor's address without fingerprinting it. The bucket is "
                "STORED and only has to be STABLE for the limit to work, so pass "
                "it through Portal\\Core\\AddressFingerprint::of(), which is the "
                "one place that decides the stored form. EVERY other bucket built "
                "from an address already does."
            )

        # 2. An address bound straight into a statement.
        for number, line in enumerate(code.split("\n"), start=1):
            if "bind_param" not in line:
                continue
            if "RateLimiter::clientIp()" in line or "LiveChat::clientIp()" in line:
                problems.append(
                    str(shown) + ":" + str(number) + " binds the visitor's address "
                    "straight into a statement. Store a fingerprint of it, not "
                    "the address."
                )

        # 3. The old column name against the throttle log.
        #
        #    READ WITH STRINGS INTACT, and that is not a detail: the names being
        #    looked for live INSIDE a SQL string, and the masked source above
        #    blanks a string's contents, so both of them vanish from it. Planted
        #    (an INSERT naming `senderIP` again), the masked form MISSED it.
        #
        #    Judged within one statement's worth of text rather than per file,
        #    because `senderIP` is still a legitimate column on
        #    `tblLiveChatMessages` and one file -- the personal-data catalogue --
        #    names both tables. A whole-file test would report that for ever.
        kept = strip_comments_keep_strings(
            path.read_text(encoding="utf-8", errors="replace")
        )
        for match in re.finditer(r"tblLiveRateLimits", kept):
            window = kept[max(0, match.start() - 300):match.end() + 300]
            if "senderIP" not in window:
                continue
            # 🪤 AND IT HAS TO LOOK LIKE SQL. Without this the check reported
            #    `DbBackup::REMOVED_COLUMNS`, where the table name and the OLD
            #    column name sit beside each other on purpose — that entry is
            #    what stops a pre-migration snapshot blocking a restore, so
            #    demanding its removal would be the check fighting the fix.
            #    #718's lesson: "does this string look like SQL" has to be
            #    asked, and asked strictly.
            if re.search(r"\b(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b", window, re.I) is None:
                continue
            line = kept[:match.start()].count("\n") + 1
            problems.append(
                str(shown) + ":" + str(line) + " names `senderIP` in the same "
                "statement as `tblLiveRateLimits`. Migration 236 renamed that "
                "column to `senderFingerprint` precisely so it could not be "
                "confused with `tblLiveChatMessages.senderIP`, which stays raw "
                "for moderation on purpose."
            )

    # 4. The one place the stored form is decided must still decide it.
    if LIVECHAT.is_file() is False:
        problems.append("web/_core/LiveChat.php is missing.")
    else:
        livechat = strip_comments_keep_strings(LIVECHAT.read_text(encoding="utf-8"))
        # ANCHORED TO THE WHOLE BODY, with no other `return` allowed before the
        # pinned one. The first version ended the pattern at the pinned return
        # with `.*?` in DOTALL, so planting
        #
        #     return $ip;
        #
        #     return AddressFingerprint::of($ip);
        #
        # satisfied it while the method handed back the raw address. `[^}]*?`
        # cannot cross the method's closing brace and the negative lookahead
        # forbids an earlier return, so dead code above the pin no longer hides
        # a live fault.
        pinned = re.compile(
            r"public\s+static\s+function\s+senderFingerprint\s*\(\s*string\s+\$ip\s*\)"
            r"\s*:\s*string\s*\{"
            r"(?:(?!return)[^}])*?"
            r"return\s+AddressFingerprint::of\(\s*\$ip\s*\)\s*;",
            re.S,
        )
        if pinned.search(livechat) is None:
            problems.append(
                "LiveChat::senderFingerprint() is gone, or no longer returns "
                "AddressFingerprint::of($ip) as the FIRST thing it does. It is "
                "the one place that decides what the chat stores in place of an "
                "address, asked by the writer AND by the reader - so changing it "
                "reinstates the fault in both at once, and the throttle would go "
                "on working either way."
            )

    if problems != []:
        print("FAIL: a network address could be stored in a rate-limit row (#712).\n")
        for problem in problems:
            print("  - " + problem + "\n")
        return 1

    print("OK: no rate-limit row holds a network address; "
          "LiveChat::senderFingerprint() still decides the stored form.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
