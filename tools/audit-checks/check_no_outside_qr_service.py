#!/usr/bin/env python3
"""
Nothing under web/ asks another website to draw a QR picture (#623).

Path: tools/audit-checks/check_no_outside_qr_service.py

WHY THIS EXISTS
---------------
A QR picture fetched from somebody else's website puts the text being encoded
INTO THE ADDRESS of that request. Addresses reach server logs, browser
histories and anything in between. That is how `Totp::getQrUrl()` sent every
new authenticator key to `chart.googleapis.com` until 14 October 2026. #623
removed it and made `Portal\\Core\\Qr` the ONE place a QR picture is made,
drawing it here and handing it over as a `data:` URI written into the page.

**#623 is a rule about PHP that had no guard for JAVASCRIPT.** A script can
build such an address just as easily, and a browser will fetch it without the
portal ever seeing the request. This check closes that half.

It exists for the reason given in #646: a correct rule that nothing enforces is
a rule that comes back. The thing it guards has already happened once.

WHAT IT LOOKS FOR
-----------------
Every occurrence of a known outside QR service's host name in any file under
web/. Each file that holds one must be listed in ALLOWED below, WITH THE NUMBER
OF TIMES it may appear, and with a reason. An unlisted file fails. A listed file
that holds MORE than its stated number fails too -- that is exactly how the old
fault would return, as a second call added to a file already on the list.

**Why it does not try to tell code from comments.** #623 deliberately keeps
notes naming the removed service, so that nobody reinstates it: three files say
"this used to ask chart.googleapis.com". Parsing comments out of a minified,
generated JavaScript bundle is not something to trust a regular expression
with, and a check that is wrong in either direction is worse than none (a check
that cries wolf gets switched off, and then it catches nothing). Counting
occurrences per file is exact, needs no parsing, and fails on the one thing
that matters: a NEW one appearing.

WHAT THIS CANNOT SEE, said plainly because an overstated guarantee is worse
than none
--------------------------------------------------------------------------
1. **A QR service that is not in OUTSIDE_QR_HOSTS.** The list is of known
   services, and the internet has more. Measured: `qrcode.tec-it.com` and
   `qrtag.net` pass. Add to the list when you meet one.
2. **A listed host built up in pieces**, such as `'api.' + 'qrserver.com'`.
   Measured: it passes. Matching text cannot follow a concatenation, and
   anything that could would be a JavaScript parser.
Both are evasions, not accidents -- somebody would have to be working around
the rule. What this check is for is the ACCIDENT: a well-meaning fallback
added, or a guard quietly lost in a refactor. It catches those.

THE ONE ENTRY THAT IS LIVE CODE, AND THE GUARD ON IT
----------------------------------------------------
`web/public_html/assets/noticeboard/noticeboard.noeval.js` really does build an
`api.qrserver.com` address, as the last of three fallbacks in `qrSrc()`. It is
UNREACHABLE in this portal, because `web/_apps/noticeboard/index.php` sets
`NoticeboardHost.qrUrl`, which always returns the portal's own
`api/noticeboard/qr` address, and `qrSrc()` takes that first.

That bundle is GENERATED from `dc-runtime/src/*.ts`, which is NOT in this
repository, and its own header says do not edit it. So the fallback cannot be
removed here -- the fix belongs in dc-runtime. What CAN be done here is make
sure the thing keeping it unreachable does not quietly disappear, so this check
also verifies that host function.

It requires a LIVE `qrUrl` property whose body RETURNS the portal's own QR
address. So it fails when that function is removed, renamed, commented out, or
left in place returning something else -- each of which is a moment the
fallback goes live while nothing looks broken. It passes a quoted key or any
spacing, because a reformat is not a fault.

**It does not prove the function returns a WORKING address**, only that the
address appears in its body. Proving more would mean running the page.

RUN IT
------
    python3 tools/audit-checks/check_no_outside_qr_service.py

Exit 0 when clean, 1 on any breach.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
WEB = REPO / "web"

# 🌐 Host names of services that draw a QR picture for you. Matched as plain
#    text, case-insensitively. Add to this list rather than writing a second
#    check.
OUTSIDE_QR_HOSTS = (
    "api.qrserver.com",
    "chart.googleapis.com",
    "chart.apis.google.com",
    "quickchart.io",
    "goqr.me",
    "api.qrcode-monkey.com",
    "zxing.org",
)

# ✅ The files allowed to mention one, how many times, and why.
#    A count that no longer matches fails: too few means the note has been lost
#    (update the count on purpose), too many means a new one has appeared.
ALLOWED = {
    "_core/Totp.php": (
        1,
        "A note recording that this class USED TO send the authenticator key to "
        "chart.googleapis.com, and that the method was removed on 14 October 2026 "
        "and must not come back. #623 wants that written down.",
    ),
    "_apps/auth/2fa/setup.php": (
        1,
        "The same note, where the key is now shown as a data: URI instead.",
    ),
    "_apps/calendar/public-landing.php": (
        1,
        "A note recording that this line used to build a chart.googleapis.com "
        "address. This page writes its own <head>, so it sends no page rule and "
        "its outside request really did happen -- which is why the note is here.",
    ),
    "public_html/assets/noticeboard/noticeboard.noeval.js": (
        1,
        "LIVE CODE, but unreachable: the last of three fallbacks in qrSrc(). "
        "NoticeboardHost.qrUrl (checked separately below) always answers first "
        "with the portal's own address. Generated from dc-runtime, which is not "
        "in this repository, so it cannot be removed here.",
    ),
}

# 🛡️ The guard that keeps the bundle's fallback unreachable.
GUARD_FILE = "_apps/noticeboard/index.php"
#
# ⚠ THIS GUARD HAS BEEN WRONG TWICE. Both versions were found by planting, and
#   both are worth knowing, because each is a way a check can look like a check
#   and not be one.
#
#   VERSION 1 searched for the plain string "qrUrl". Renaming the function to
#   "qrUrlRENAMED" -- exactly how it would be lost in a refactor -- left that
#   string in place as a PREFIX, so it passed on a guard that no longer existed.
#
#   VERSION 2 required `\bqrUrl\s*[:(]` and `api/noticeboard/qr` as TWO
#   INDEPENDENT patterns, anywhere in the file. That passed on BOTH of the
#   states it exists to catch:
#     • the whole function COMMENTED OUT -- the text is all still there, so
#       both patterns matched while `NoticeboardHost` had no `qrUrl` at all;
#     • the function kept but `return '';` -- with the old address left in a
#       note above it, both patterns matched while qrSrc()'s `if (r) return r`
#       fell straight through to api.qrserver.com.
#   It also went RED on `"qrUrl": function`, a quoted property key -- which any
#   formatter may produce. A hard gate that fails on a cosmetic reformat is the
#   "cries wolf" fault this file's own docstring warns about, in the same
#   breath as missing the real thing.
#
#   VERSION 3, below, asks the question the guard is actually about: is there a
#   LIVE `qrUrl` property whose body RETURNS the portal's own QR address? The
#   name may be quoted, the spacing may be anything, and the two facts must be
#   in ONE PLACE rather than merely both present somewhere in the file.

# The property, with an optional quoted key so a reformat does not fail it.
GUARD_PROPERTY = re.compile(r"""["']?\bqrUrl["']?\s*[:(]""")

# What its body must contain, within GUARD_WINDOW characters of the property.
GUARD_RETURNS = "return"
GUARD_ADDRESS = "api/noticeboard/qr"
GUARD_WINDOW = 400

# Comment shapes stripped from the GUARD FILE before searching, so that
# commenting the function out is not mistaken for having it.
BLOCK_COMMENT = re.compile(r"/\*.*?\*/", re.S)
WHOLE_LINE_COMMENT = re.compile(r"^[ \t]*(?://|\*).*$", re.M)


def live_code_only(text: str) -> str:
    """
    Strip the comment shapes that could fake this guard.

    Only /* ... */ blocks and lines that BEGIN with // or * are removed. A
    trailing comment on a line of real code is deliberately left alone,
    because an address inside one cannot fake a whole function, and because
    cutting at the first `//` would also cut `https://` out of real code --
    this file holds both.

    This is done for the guard file ONLY. It is hand-written PHP with a little
    JavaScript in it, so the shapes are predictable. The allow-list above does
    NOT do this, for the reason given in the docstring: one of its files is a
    minified generated bundle, where no such assumption holds.
    """
    return WHOLE_LINE_COMMENT.sub("", BLOCK_COMMENT.sub("", text))


def main() -> int:
    problems: list[str] = []

    if WEB.is_dir() is False:
        print("✗ cannot find the web/ folder at %s" % WEB, file=sys.stderr)
        return 1

    # ---- 1. Count every mention, file by file -----------------------------
    found: dict[str, int] = {}
    for path in sorted(WEB.rglob("*")):
        if path.is_file() is False:
            continue
        try:
            text = path.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        lowered = text.lower()
        hits = sum(lowered.count(host) for host in OUTSIDE_QR_HOSTS)
        if hits > 0:
            found[path.relative_to(WEB).as_posix()] = hits

    for rel, hits in sorted(found.items()):
        if rel not in ALLOWED:
            problems.append(
                "✗ %s names an outside QR service %d time(s) and is not on the "
                "allowed list.\n"
                "   Nothing under web/ may ask another website to draw a QR picture "
                "(#623): the text being encoded would travel in that address, and "
                "addresses reach logs and browser histories.\n"
                "   Draw it with Portal\\Core\\Qr instead -- Qr::dataUri() for "
                "anything secret, so the picture is written into the page." % (rel, hits)
            )
            continue
        allowed_count, reason = ALLOWED[rel]
        if hits != allowed_count:
            problems.append(
                "✗ %s names an outside QR service %d time(s); %d was expected.\n"
                "   Why it is allowed at all: %s\n"
                "   MORE than expected means a new one has appeared -- that is the "
                "fault this check exists for. FEWER means a deliberate note has been "
                "lost; if that was on purpose, change the count here and say why."
                % (rel, hits, allowed_count, reason)
            )

    for rel in sorted(ALLOWED):
        if rel not in found:
            problems.append(
                "✗ %s is on the allowed list but names no outside QR service.\n"
                "   Either the file moved, or a note #623 wants kept has gone. Remove "
                "its entry here on purpose, or put the note back." % rel
            )

    # ---- 2. The guard that keeps the bundle's fallback unreachable --------
    guard = WEB / GUARD_FILE
    if guard.is_file() is False:
        problems.append(
            "✗ %s is missing. It is what keeps the noticeboard bundle's "
            "api.qrserver.com fallback unreachable, by giving the board the "
            "portal's own QR address." % GUARD_FILE
        )
    else:
        guard_text = live_code_only(
            guard.read_text(encoding="utf-8", errors="ignore")
        )
        why = (
            "   That host function is the ONLY reason the noticeboard bundle's "
            "api.qrserver.com fallback never runs: qrSrc() asks it first and uses "
            "whatever it returns, so an absent function -- or one returning an "
            "empty string -- sends the board's share links to a third party with "
            "nothing looking broken.\n"
            "   If the board's QR really has moved, point this check at whatever "
            "replaced it."
        )

        # Every place the property appears in LIVE code; one of them must have
        # the address in its body. Checking all of them rather than the first
        # means an abandoned copy above the real one cannot hide a broken one,
        # and a real one below a broken one still passes.
        bodies = [
            guard_text[m.end():m.end() + GUARD_WINDOW]
            for m in GUARD_PROPERTY.finditer(guard_text)
        ]
        if not bodies:
            problems.append(
                "✗ %s has no live `qrUrl` property.\n"
                "   Commenting it out counts as not having it: this check reads the "
                "file with whole-line comments removed, because a commented-out copy "
                "is exactly how the guard would be lost.\n%s" % (GUARD_FILE, why)
            )
        elif any(
            GUARD_RETURNS in body and GUARD_ADDRESS in body for body in bodies
        ) is False:
            problems.append(
                "✗ %s has a `qrUrl` property, but none of them RETURNS the portal's "
                "own QR address (%r within %d characters of the property).\n"
                "   Having the function is not enough -- a body that returns an "
                "empty string, or returns something else entirely, lets qrSrc() fall "
                "through just as surely as no function at all.\n%s"
                % (GUARD_FILE, GUARD_ADDRESS, GUARD_WINDOW, why)
            )

    # ---- report -----------------------------------------------------------
    if problems:
        print("\n".join(problems), file=sys.stderr)
        print(
            "\n%d problem(s). The rule is #623: Portal\\Core\\Qr is the one place a "
            "QR picture is made." % len(problems),
            file=sys.stderr,
        )
        return 1

    print(
        "✓ No outside QR service is reachable. %d file(s) mention one, all "
        "accounted for, and the noticeboard's own QR address is still in place."
        % len(found)
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
