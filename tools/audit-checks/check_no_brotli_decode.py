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

🛑 THE TRAP, AND THE FIRST VERSION OF THIS CHECK GOT IT WRONG.
   The obvious reading is that the danger is `CURLOPT_ENCODING => ''` (which
   advertises everything, Brotli included) or a value that names `br`. That is
   what this check looked for on 2 November 2026, and an independent check
   proved it insufficient the same day.

   **Setting the option to ANY non-null value switches libcurl's content
   decoding ON, and libcurl then chooses the decoder BY NAME FROM THE REPLY'S
   `Content-Encoding` HEADER** — from everything it was compiled with, Brotli
   included. What we advertised is irrelevant. Measured against a server that
   replies `Content-Encoding: br` without being asked (libcurl 8.22.0,
   brotli/1.2.0):

       CURLOPT_ENCODING unset             -> 33 raw bytes, NOT decoded
       CURLOPT_ENCODING = ''              -> decoded
       CURLOPT_ENCODING = 'gzip, deflate' -> STILL DECODED

   In the threat this guards against the SERVER IS THE ATTACKER — these are
   addresses somebody typed — so "we did not ask for it" is worth nothing.

   **So the rule is: the option must not be set at all.** Leaving it unset is
   what every other curl call site in this portal already does, and it is why
   every one of them is immune.

WHAT IT CHECKS
--------------
Under `web/`, for every PHP file:

  1. `CURLOPT_ENCODING` / `CURLOPT_ACCEPT_ENCODING` must not be SET AT ALL.
     Not empty, not `'gzip, deflate'`, not anything — see the trap above. The
     one exception the code may state is an explicit `null`, which is the same
     as not setting it.
  2. A hand-written `Accept-Encoding:` header must not name `br` or `brotli`.
     (Advertising it is not what causes the decode, but asking for a thing we
     refuse to handle is incoherent and worth catching.)
  3. PHP's optional Brotli extension must not be called
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
    """Blank out comments, keeping every line number and every string intact.

    🛑 THIS WALKS THE SOURCE RATHER THAN MATCHING PATTERNS, and both reasons
       are faults that were measured on the first two versions of this file.

       **A `//` inside a string ate the rest of the line.** Version 1 ran
       `re.sub(r"//.*$")` straight over the source, so a one-line option array
       holding `'https://api.example.com/v1/feed'` had everything after the URL
       deleted — including `CURLOPT_ENCODING => ''`. The check passed on the
       exact fault it exists for. A `/*` inside a string was worse: the block
       pattern spans newlines and swallowed everything to the next `*/`.

       **Masking strings instead BROKE BOTH DETECTORS.** Version 2 replaced
       every literal with a run of `x`, which fixes the comment problem and
       destroys the thing being read: the option's VALUE is a string, and so is
       a hand-written `Accept-Encoding` header. Caught immediately by testing
       rather than by reading, which is the only reason it is not in the
       committed file.

       So: step over strings, blank only real comments, and leave everything
       else exactly where it was.

       **Line numbers survive because a block comment is replaced by its own
       newlines, not deleted.** Deleting one shifts every line below it —
       version 1 reported line 666 for a fault on line 1036, 370 out.
    """
    out: list[str] = []
    i, n = 0, len(src)
    while i < n:
        ch = src[i]
        # A string: copy it through untouched, honouring backslash escapes.
        if ch in "'\"":
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
        # A block comment: keep only its newlines.
        if src.startswith("/*", i):
            close = src.find("*/", i + 2)
            close = n if close == -1 else close + 2
            out.append("\n" * src.count("\n", i, close))
            i = close
            continue
        # A line comment: drop it, keep the newline that ends it.
        if src.startswith("//", i) or src.startswith("#", i):
            nl = src.find("\n", i)
            i = n if nl == -1 else nl
            continue
        out.append(ch)
        i += 1
    return "".join(out)


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
            value = m.group("value").strip()
            # An explicit null is the same as not setting it, and is allowed.
            if value.lower() == "null":
                continue
            quoted = value[:1] in {"'", '"'}
            bare = value.strip("'\"").strip()
            if quoted and bare == "":
                why = ("set to an EMPTY string — libcurl then advertises every coding it has "
                       "AND decodes whatever the reply declares")
            elif quoted:
                why = (f"set to {value} — any non-null value switches libcurl's content decoding "
                       f"ON, and it then decodes whatever the REPLY declares, Brotli included. "
                       f"What you advertise is irrelevant")
            else:
                why = ("set to a value this check cannot read — and ANY non-null value switches "
                       "libcurl's content decoding on")
            note(line_no, "CURLOPT_ENCODING is " + why)

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
        print("  DO NOT SET THE OPTION AT ALL. That is what web/_core/SafeFetch.php does")
        print("  and what every other curl call site here already did — it is why they")
        print("  are immune. With the option unset, libcurl sends no Accept-Encoding AND")
        print("  ignores a Content-Encoding the server declares anyway.")
        print()
        print("  Naming codings does NOT help: 'gzip, deflate' was committed as the fix")
        print("  on 2 November 2026 and measured still decoding Brotli, because any")
        print("  non-null value turns content decoding on and the decoder is then chosen")
        print("  from the REPLY. If you need compression badly enough, decode it in PHP")
        print("  with CURLOPT_HTTP_CONTENT_DECODING => false — do not re-enable libcurl's.")
        print()
    if stale:
        print(f"{len(stale)} exemption(s) in ALLOWED_ON_PURPOSE are no longer true. Remove them:\n")
        for key in stale:
            print(f"  • {key}")
        print()
    return 1


if __name__ == "__main__":
    sys.exit(main())
