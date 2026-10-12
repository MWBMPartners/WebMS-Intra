#!/usr/bin/env python3
"""
No web address of ours is built in (#500).

Path: tools/audit-checks/check_no_hardcoded_domains.py

WHY THIS EXISTS
---------------
WebMS-Intra is a product that many customers install. The first customer's own
domain used to be written into it: a seeded support e-mail address, a fallback
in a help page, placeholder text, a redirect page and the deploy workflow's
health check. Every other customer would have shipped that address to their own
members. This check finds any line that names a domain of OURS, so it cannot
come back.

WHAT IT READS
-------------
- Must be clean (a line here fails the check): `web/`, `.github/`, `tools/`,
  `README.md` and `docs/INSTALL.md`. These are what is deployed, packaged, run
  on GitHub, or read by a customer who is installing.
- Listed only, never a failure: every other file in the repository (the
  developer notes, the change log, the plans and the assistants' notes). Those
  may name our installation as an EXAMPLE of one customer's set-up. They are
  listed so that a reader can check each one is labelled as an example.
- Never read: `.git/`, `node_modules/`, `.claude-work/`, `.dev-team/`, and the
  folders a server manages for itself (`web/_libraries/`, `web/_uploads/`,
  `web/_backups/`, `web/_auth_keys/`).

THE PENDING LIST
----------------
`check_no_hardcoded_domains.pending.txt`, beside this file, names lines that
are known and are waiting for a change this check's author was not allowed to
make (the workflow files need the owner's yes). A line on that list is counted
as "pending", not as a failure, so the check can pass today without hiding it.
The list cannot rot: an entry whose line is no longer there FAILS the check
until the entry is deleted. `--strict` fails for as long as the list holds
anything at all, so a release cannot go out with a line still waiting.

WHERE THE NAME IS KEPT
----------------------
`OWN_DOMAINS` below holds each name in base64. That is NOT secrecy: the
repository can be read by anybody, and anybody can decode it. It is so that the
plain name appears nowhere under `tools/`. Otherwise this check, and the hand
search in `.claude/CLAUDE.md`, would both report this file's own pattern for
ever, and a check that always reports something gets ignored. For the same
reason the pending list names a line by its fingerprint (SHA-256), not by its
text. `--print-own-domains` shows the names.
(`tools/package/check-package.py` keeps its own copy of the same name, the
same way, for its guard G6. Giving the two one home is a change to that tool.)

Add a name here only when the domain is OURS. Never add a customer's.

WHAT IT CANNOT DO
-----------------
- It knows only the names in `OWN_DOMAINS`. Some OTHER address written into the
  code is not found. `--hosts` lists every host name in the must-be-clean
  places so a person can read down them; nothing judges that list.
- It reads text. A name built at run time from pieces, or held in base64 as
  this file holds it, is not found.
- It does not check that an example in a document is LABELLED as one. A person
  has to read the "listed only" lines.
- It does not know which address a running portal uses. That is a setting
  (`site.url`), read by `web/_core/PortalAddress.php`.

Exit code:
  0  nothing found outside the pending list (and, with --strict, the pending
     list is empty)
  1  a line names one of our domains; or a pending entry is stale; or the
     pending list cannot be read; or --strict was given and something is
     pending

Usage:
  python3 tools/audit-checks/check_no_hardcoded_domains.py [--strict] [--hosts]
  python3 tools/audit-checks/check_no_hardcoded_domains.py --root <folder>
      reads EVERY file under <folder> as "must be clean" (a staged package);
      the pending list does not apply there
  python3 tools/audit-checks/check_no_hardcoded_domains.py --print-own-domains

Package:   Portal\\Tools
Author:    MWBM Partners Ltd (t/a MWservices)
Copyright: 2026-present MWBM Partners Ltd (t/a MWservices). All Rights Reserved.
Version:   1.0.0
Link:      https://github.com/MWBMPartners/WebMS-Intra/issues/500
"""

from __future__ import annotations

import base64
import hashlib
import os
import re
import sys
from collections import Counter
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
PENDING_FILE = Path(__file__).resolve().with_name("check_no_hardcoded_domains.pending.txt")

# Our own domains, in base64 (see "WHERE THE NAME IS KEPT" above). Held without
# the ending (.uk), so the same name under another ending is found as well.
OWN_DOMAINS = tuple(base64.b64decode(name).decode() for name in ("bWlsbHJkc2RhY2FtYnJpZGdl",))

# Must be clean. A folder ends in "/"; anything else is one exact file.
STRICT_PLACES = ("web/", ".github/", "tools/", "README.md", "docs/INSTALL.md")

# Never read. Matched against the start of a path written with "/".
SKIPPED_PLACES = (
    ".git/", "node_modules/", ".claude-work/", ".dev-team/",
    "web/_libraries/", "web/_uploads/", "web/_backups/", "web/_auth_keys/",
)

# Files that are not text. Reading a picture as text finds nothing and wastes time.
BINARY_ENDINGS = {
    ".png", ".jpg", ".jpeg", ".gif", ".ico", ".icns", ".webp", ".woff", ".woff2", ".ttf", ".otf",
    ".eot", ".pdf", ".zip", ".gz", ".mp3", ".mp4", ".webm", ".phar",
}

HOST_RE = re.compile(r"https?://([A-Za-z0-9.-]+\.[A-Za-z]{2,})", re.IGNORECASE)


def fingerprint(line: str) -> str:
    """How the pending list names a line: the SHA-256 of its text without the spaces at either end."""
    return hashlib.sha256(line.strip().encode("utf-8")).hexdigest()


def files_under(root: Path, whole_tree_is_strict: bool):
    """Every readable text file under root, as (path written with "/", must it be clean?)."""
    for folder, dirs, names in os.walk(root):
        rel_folder = Path(folder).relative_to(root).as_posix()
        rel_folder = "" if rel_folder == "." else rel_folder + "/"
        if whole_tree_is_strict is False:
            # Do not walk into a skipped folder at all.
            dirs[:] = [d for d in dirs if (rel_folder + d + "/").startswith(SKIPPED_PLACES) is False]
        for name in sorted(names):
            rel = rel_folder + name
            if Path(name).suffix.lower() in BINARY_ENDINGS:
                continue
            strict = whole_tree_is_strict or any(
                rel.startswith(place) if place.endswith("/") else rel == place for place in STRICT_PLACES
            )
            yield rel, strict


def read_pending() -> tuple[list[tuple[str, str, str]], list[str]]:
    """The pending list as [(path, fingerprint, note)], and what is wrong with the file, if anything."""
    entries: list[tuple[str, str, str]] = []
    problems: list[str] = []
    if PENDING_FILE.is_file() is False:
        return entries, [f"the pending list {PENDING_FILE.name} is missing (an empty file means nothing is pending)"]
    for number, raw in enumerate(PENDING_FILE.read_text(encoding="utf-8").splitlines(), start=1):
        line = raw.strip()
        if line == "" or line.startswith("#"):
            continue
        parts = line.split(None, 2)
        if len(parts) < 2 or re.fullmatch(r"[0-9a-f]{64}", parts[1]) is None:
            problems.append(f"{PENDING_FILE.name} line {number} is not 'path fingerprint note'")
            continue
        entries.append((parts[0], parts[1], parts[2] if len(parts) > 2 else ""))
    return entries, problems


def check(argv: list[str]) -> int:
    if "--print-own-domains" in argv:
        print("\n".join(OWN_DOMAINS))
        return 0

    strict_mode = "--strict" in argv
    root = REPO_ROOT
    staged = False
    if "--root" in argv:
        at = argv.index("--root")
        if at + 1 >= len(argv) or Path(argv[at + 1]).is_dir() is False:
            print("FAIL: --root needs a folder that exists")
            return 1
        root = Path(argv[at + 1]).resolve()
        staged = True

    names = tuple(name.lower() for name in OWN_DOMAINS)
    strict_hits: list[tuple[str, int, str]] = []
    listed_only: Counter[str] = Counter()
    hosts: Counter[str] = Counter()
    files_read = 0

    for rel, strict in files_under(root, staged):
        try:
            data = (root / rel).read_bytes()
        except OSError:
            continue
        if b"\x00" in data[:4096]:
            continue  # not text
        files_read += 1
        text = data.decode("utf-8", errors="ignore")
        lowered = text.lower()
        if strict is True and "--hosts" in argv:
            hosts.update(host.lower() for host in HOST_RE.findall(text))
        if any(name in lowered for name in names) is False:
            continue
        for number, line in enumerate(text.splitlines(), start=1):
            if any(name in line.lower() for name in names) is True:
                if strict is True:
                    strict_hits.append((rel, number, line))
                else:
                    listed_only[rel] += 1

    # The pending list applies to the repository only, never to a staged package:
    # nothing "waiting for a change" may be shipped.
    pending, problems = ([], []) if staged is True else read_pending()
    waiting = Counter((path, mark) for path, mark, _ in pending)
    failures: list[tuple[str, int, str]] = []
    pending_found: list[tuple[str, int]] = []
    for rel, number, line in strict_hits:
        key = (rel, fingerprint(line))
        if waiting[key] > 0:
            waiting[key] -= 1
            pending_found.append((rel, number))
        else:
            failures.append((rel, number, line))
    # What is left in `waiting` matched no line: those entries are stale. An
    # entry written twice for a line that is there once is stale once.
    stale: list[tuple[str, str, str]] = []
    for path, mark, note in pending:
        if waiting[(path, mark)] > 0:
            waiting[(path, mark)] -= 1
            stale.append((path, mark, note))

    where = f"the folder {root}" if staged is True else "the repository"
    print(f"Read {files_read} text files in {where}.")
    print(f"Lines naming one of our own domains where none may be: {len(failures)}")
    print(f"Pending (known, waiting for a change that needs approval): {len(pending_found)}")
    print(f"Stale pending entries (their line is gone): {len(stale)}")
    print(f"Listed only (documents that may show our address as a labelled example): "
          f"{sum(listed_only.values())} lines in {len(listed_only)} files")
    print()

    if failures:
        print("### A domain of ours is written into the product\n")
        for rel, number, line in failures:
            print(f"  • {rel}:{number} — {line.strip()[:160]}")
        print("\n  Take the address out. An address comes from a setting, the installer, or a GitHub")
        print("  secret or variable; a missing one gives a clear message, never a fallback to ours.\n")
    if pending_found:
        print("### Pending (not a failure today; --strict fails on these)\n")
        for rel, number in pending_found:
            print(f"  • {rel}:{number}")
        print()
    if stale:
        print(f"### Stale entries in {PENDING_FILE.name}\n")
        for path, mark, note in stale:
            print(f"  • {path} {mark[:12]}… {note}")
        print("\n  The line each one names is no longer in that file. Delete the entry: a list that")
        print("  keeps entries for lines that are gone would hide the next line that matches.\n")
    for problem in problems:
        print(f"FAIL: {problem}")
    if listed_only:
        print("### Listed only: check each is labelled as an example of one customer's set-up\n")
        for rel, count in sorted(listed_only.items()):
            print(f"  • {rel} ({count})")
        print()
    if "--hosts" in argv:
        print("### Every host name in an http(s) address in the must-be-clean places (for a person to read)\n")
        for host, count in sorted(hosts.items()):
            print(f"  • {host} ({count})")
        print()

    failed = bool(failures or stale or problems)
    if strict_mode is True and pending_found:
        print("FAIL (--strict): something is still pending.")
        failed = True
    print("Result: " + ("FAIL" if failed is True else "pass") + ". The check reached its end.")
    return 1 if failed is True else 0


if __name__ == "__main__":
    sys.exit(check(sys.argv[1:]))
