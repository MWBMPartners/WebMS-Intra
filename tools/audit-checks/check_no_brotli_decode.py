#!/usr/bin/env python3
"""Nothing under web/ asks for a reply it would decode with Brotli (#716).

WHAT THIS GUARDS
----------------
CVE-2026-111497 — the flaw called HTTP2Shell, and also Node2Shell,
Python2Shell and Broccoli2Shell — is an out-of-bounds write in Google's Brotli
decoder (`c/dec/decode.c`), in Brotli 1.0.3 to 1.2.0, fixed in 1.0.10, 1.1.1
and 1.2.1. It is remote, unauthenticated, needs no user interaction, and is
triggered simply by something decoding a crafted Brotli stream. CISA listed it
as actively exploited on 27 October 2026.

This portal contains no Brotli code. What it controls is whether it ever asks
anything to decode Brotli FOR it — and libcurl decodes what it accepts, inside
the web server's own process, using whatever Brotli library it was linked
against. Measured on the development machine on 2 November 2026: PHP's libcurl
reported `brotli/1.2.0`, inside the affected range, with the
CURL_VERSION_BROTLI feature bit set.

So the one thing our code can do — and the only thing that works regardless of
what a customer's host has installed — is not ask for `br`.

🛑 THE TRAP, AND IT IS WHY THIS CHECK LOOKS FOR AN EMPTY STRING.
   `CURLOPT_ENCODING => ''` reads as "no compression preference" and means the
   OPPOSITE: libcurl advertises EVERY coding it was built with, Brotli
   included. That is how `SafeFetch` came to accept `br` without anybody
   deciding to. An empty value is therefore a failure here, not a pass.

WHAT IT CHECKS
--------------
Under `web/`, for every PHP file:

  1. `CURLOPT_ENCODING` / `CURLOPT_ACCEPT_ENCODING` must not be set to an
     empty string.
  2. Its value must not name `br` or `brotli` as a coding.
  3. A hand-written `Accept-Encoding:` header must not name them either.
  4. PHP's optional Brotli extension must not be called
     (`brotli_uncompress`, `brotli_uncompress_init`, …). Nothing uses it today;
     if somebody adds it, the decision belongs to a person, not to a commit.

WHAT IT CANNOT SEE, said plainly because an overstated guarantee is worse than
none:

  - A value built at run time, or read from a setting, or concatenated. It
    reads what is written beside the option.
  - **A curl handle that sets no encoding at all is FINE and is not reported** —
    libcurl then sends no `Accept-Encoding` and decodes nothing. That is what
    every other curl call site in this repository does.
  - Anything outside `web/`. The development machine's own Node and libcurl are
    a separate matter, recorded on #716; this check is about the product.
  - Whether the host's Brotli is patched. That is unknowable from here, and a
    version number could not prove a vendor-patched build either way — the rule
    #640 already records for PHP. That is exactly why not asking for `br` is
    the fix rather than hoping.
  - A reply the PORTAL serves compressed. Apache's `mod_brotli` compresses;
    this bug is in DECODING. Different direction, not this check's business.

HOW IT FAILS BOTH WAYS
----------------------
An offending line fails. So does an entry in ALLOWED_ON_PURPOSE that is no
longer true, because a stale exemption is where a real one hides.

ALLOWED_ON_PURPOSE IS EMPTY AND SHOULD STAY EMPTY. If you are about to add to
it, the question to answer first is why this portal needs to decode Brotli from
an address it does not control.
"""

from __future__ import annotations

import pathlib
import re
import sys

REPO = pathlib.Path(__file__).resolve().parents[2]
WEB = REPO / "web"

# Nothing is exempt. See the docstring before adding to this.
ALLOWED_ON_PURPOSE: dict[str, str] = {}

# `CURLOPT_ENCODING => <value>` / `curl_setopt($h, CURLOPT_ENCODING, <value>)`
OPTION = re.compile(
    r"CURLOPT_(?:ACCEPT_)?ENCODING\s*(?:=>|,)\s*(?P<value>'[^']*'|\"[^\"]*\"|[A-Za-z_$][\w$:>\-\[\]']*)",
    re.I,
)
HEADER = re.compile(r"""['"]\s*accept-encoding\s*:\s*(?P<value>[^'"]*)['"]""", re.I)
EXTENSION = re.compile(r"\bbrotli_(?:un)?compress\w*\s*\(", re.I)


def codings(value: str) -> list[str]:
    """'gzip, br;q=0.5' -> ['gzip', 'br']."""
    bare = value.strip().strip("'\"")
    return [part.split(";")[0].strip().lower() for part in bare.split(",") if part.strip() != ""]


def strip_comments(src: str) -> str:
    """A commented-out line is not live code, and a comment ABOUT brotli (there
    is a long one in SafeFetch explaining this very rule) must not be read as
    an offence. Block comments first, then line comments."""
    src = re.sub(r"/\*.*?\*/", "", src, flags=re.S)
    src = re.sub(r"(?m)//.*$", "", src)
    return src


def main() -> int:
    if not WEB.is_dir():
        print("check_no_brotli_decode: no web/ directory — nothing to check.")
        return 0

    problems: list[tuple[str, int, str]] = []
    exempt_used: set[str] = set()
    files = 0

    for path in sorted(WEB.rglob("*.php")):
        raw = path.read_text(encoding="utf-8", errors="replace")
        src = strip_comments(raw)
        files += 1
        rel = str(path.relative_to(REPO))

        def note(line_no: int, what: str) -> None:
            key = f"{rel}:{line_no}"
            if key in ALLOWED_ON_PURPOSE:
                exempt_used.add(key)
            else:
                problems.append((rel, line_no, what))

        for m in OPTION.finditer(src):
            line_no = src[: m.start()].count("\n") + 1
            value = m.group("value")
            quoted = value[:1] in {"'", '"'}
            if quoted and value.strip("'\"").strip() == "":
                note(line_no, "CURLOPT_ENCODING is set to an EMPTY string, which tells libcurl to "
                              "advertise every coding it has — Brotli included")
                continue
            if quoted:
                named = codings(value)
                if "br" in named or "brotli" in named:
                    note(line_no, f"CURLOPT_ENCODING names Brotli ({value})")
            # A non-literal value cannot be judged here; the docstring says so.

        for m in HEADER.finditer(src):
            named = codings(m.group("value"))
            if "br" in named or "brotli" in named:
                note(src[: m.start()].count("\n") + 1,
                     "a hand-written Accept-Encoding header names Brotli")

        for m in EXTENSION.finditer(src):
            note(src[: m.start()].count("\n") + 1,
                 "PHP's Brotli extension is called directly")

    stale = sorted(set(ALLOWED_ON_PURPOSE) - exempt_used)

    if not problems and not stale:
        print(f"check_no_brotli_decode: OK — none of {files} PHP file(s) under web/ asks for a "
              f"reply it would decode with Brotli (#716, CVE-2026-111497).")
        return 0

    print("check_no_brotli_decode: SOMETHING WOULD BE DECODED WITH BROTLI\n")
    if problems:
        print("CVE-2026-111497 is an out-of-bounds write in the Brotli decoder, remotely")
        print("triggerable by a crafted stream, and libcurl decodes what it accepts inside")
        print("this process. These lines ask for that:\n")
        for rel, line_no, what in problems:
            print(f"  • {rel}:{line_no} — {what}")
        print()
        print("  Name the codings you want instead, as web/_core/SafeFetch.php does:")
        print("      CURLOPT_ENCODING => 'gzip, deflate',")
        print("  Setting no encoding at all is also fine — libcurl then sends no")
        print("  Accept-Encoding and decodes nothing.")
        print()
    if stale:
        print(f"{len(stale)} exemption(s) in ALLOWED_ON_PURPOSE are no longer true. Remove them:\n")
        for key in stale:
            print(f"  • {key}")
        print()
    return 1


if __name__ == "__main__":
    sys.exit(main())
