#!/usr/bin/env python3
"""
Addresses that stay inside the organisation being looked at.

Finds redirects, links, form targets and breadcrumb entries that are written
as a bare address ("/expenses/approve") instead of being built by
`Router::url()` or `Site::url()`.

WHY THIS MATTERS
----------------
One installation can serve several organisations, and one way of telling them
apart is by the first part of the address: "/north/expenses/approve" is the
North organisation's approvals page. `Router::url('expenses/approve')` puts
that first part on. A bare "/expenses/approve" has none, so it lands on the
DEFAULT organisation. Somebody approving a claim for one organisation is
dropped into another, and the page they arrive on looks perfectly normal.

Nothing else catches it. Every file exists and every address is registered;
the mistake is in what the page points AT, and it only shows when
organisations are told apart this way.

WHICH PLACES IT READS: A SHORT, WRITTEN LIST, ON PURPOSE
-------------------------------------------------------
The portal has about 900 bare redirects (issue #536). A check that reported
all of them on day one would be switched off the same day. So this check
reads only what is in LISTED below: the folders, and single files, that have
been cleaned and must now stay clean. Add an entry in the same change that
cleans it. Roll-out package B2a (12 October 2026) started the list with the
Expenses app; package B2b added the erasure and "delete my account" pages
the same day.

WHAT COUNTS AS A BARE ADDRESS
-----------------------------
Two kinds of thing, on any line that is not a comment:

1. An address straight after one of the words that take one: `Location:`
   (in any capitals: a redirect works whichever way the word is written),
   `href=` and `action=` (with quotes round the address or without),
   `fetch(`, `.open('GET',` and `location =`.
2. ANY text in quotes that starts with a single "/": `'/expenses/approve'`,
   `"/expenses/view?id="`, and `'/'` on its own (the dashboard of the
   default organisation). The text is followed to its closing quote on the
   same line, through spaces and through a PHP tag, so
   `data-url="/expenses/view?id=<?php echo (int) $id; ?>"` counts.

Kind 2 was added on 12 October 2026, after the independent check showed the
first version passing three shapes the cleaned lines could easily slide back
to: an address joined from two quoted pieces
(`'Location: ' . '/expenses/approve'`), one echoed inside a PHP tag in an
attribute (`href="<?php echo '/expenses/submit'; ?>"`), and a list written
over several lines, where the address sits on a line of its own. All three
are text in quotes that starts with "/", which is why the rule is that wide.

Three more shapes were added the same day, after the second independent
check planted them and each passed: quoted text that starts with "/" and
holds a PHP tag or a space before its closing quote (kind 2 used to give up
at the first "<" or space); `href=` and `action=` with no quotes; and
`location:` written in small letters.

Four things in quotes that start with "/" are NOT reported:
- the first argument of a `preg_…(` call: that is a pattern, written between
  two slashes, not an address;
- the argument of `Router::url(` or `Site::url(`: the helper takes the
  leading "/" off and puts the organisation's part on;
- anything under "/assets/": the web server answers for those files
  directly, and they are the same for every organisation;
- text whose "/" is followed straight by a space: that is a piece of a
  sentence (`'the address /' . $key . '/ is taken'`), not an address.

WHAT IT CANNOT DO
-----------------
- It says nothing about anything that is not on the list. "OK" means the
  listed places are clean, not that the portal is.
- It reads text, one line at a time. It cannot follow a value. An address
  held in a variable, handed back by a function, or put together from pieces
  none of which starts with "/" is not seen:
  `header('Location: ' . $somewhere)`, `$base . 'expenses/approve'`,
  `sprintf('%s/expenses', $root)`.
- An address that sits neither in quotes of its own nor after one of the
  words in kind 1 is not seen: `<meta http-equiv="refresh"
  content="0;url=/expenses">`, `header('Refresh: 0; url=/expenses')`, or
  any other bare address inside a longer piece of quoted text.
- Quoted text that starts with "/" is not seen when its closing quote is on
  another line, or when a "<" or ">" that is not part of a PHP tag comes
  before the closing quote. (That limit is what keeps the end of an HTML
  tag, `name="x"/>`, from being read as an address.)
- An address with no quotes is seen only straight after `href=` or
  `action=`. With a space either side of the "=" (`href = /expenses`), or
  after any other word, it is not.
- A whole address with a host name in it ("https://…/expenses") is not
  judged at all.
- A line that begins with a comment mark (`//`, `*`, `#`, `/*`) is skipped,
  so that a comment may quote an address. A comment at the END of a line of
  code is read like the code.
- It can report something that is not an address: any other quoted text that
  happens to start with "/". None exists in the listed places today. If one
  appears, write it another way, or add an exemption above and say why.
  Measured over every PHP file under `web/` on 12 October 2026 (far more
  than this check judges): the three shapes added that day report 17
  places the older rules passed. 12 are bare addresses. The other 5 are a
  pattern on a line of its own inside a `preg_…(` call written over
  several lines (3), a form field's example text, "/giving or https://…"
  (1), and a piece of a sentence that starts "/." (1). Expect those shapes
  when a new place joins the list.
- It does not check that an address built by the helper is a registered one;
  `check_route_targets.py` looks at that side.

Exit code:
  0 — every listed place was read, all of it, and holds no bare address
  1 — at least one bare address, OR something on the list could not be read
      in full: it is missing, it holds no PHP file, a folder inside it could
      not be opened, a file could not be read, or it holds a link (links are
      not followed). Reading less than everything must never look like a
      pass.

Usage:
  python3 tools/audit-checks/check_site_aware_addresses.py [--strict] [--root DIR]

  --strict  accepted so this can be called like the other checks; the exit
            code is already 1 on any finding.
  --root    read DIR instead of this repository's `web/` folder. For proving
            the check on a scratch copy with a planted fault.
"""

from __future__ import annotations

import os
import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

# Folders and single files under web/ that have been cleaned and must stay
# clean. Keep each entry beside the change that cleaned it.
LISTED = [
    "_apps/expenses",  # roll-out package B2a, 12 October 2026
    # Roll-out package B2b, 12 October 2026: the erasure and "delete my
    # account" pages. The administrator's folder is listed whole. The other
    # five are single files, because the folders they sit in
    # (`_apps/account`, `_apps/auth/account`) still hold pages that have not
    # been cleaned; listing those folders would report 20 or so addresses
    # that belong to #536, and a check that cries wolf gets switched off.
    "_apps/admin/erasure",
    "_apps/account/erasure-request.php",
    "_apps/account/erasure-confirm.php",
    "_apps/account/my-data.php",
    "_apps/auth/account/delete.php",
    "_apps/auth/account/delete-confirm.php",
    # Roll-out package F1, 12 October 2026 (#502): the pages that move a
    # person between organisations. A bare address here is the worst place
    # for one: it would drop somebody who has just chosen an organisation
    # into the default one. `_apps/site` is the whole folder (the chooser
    # and the switch handler). The set-up page and its save handler are
    # single files, because the rest of `_apps/admin/sites` belongs to #536.
    "_apps/site",
    "_apps/admin/sites/setup.php",
    "_apps/admin/sites/setup-save.php",
    "_core/SiteGate.php",
    # Roll-out package F2, 12 October 2026 (#511): the refusal page. Its
    # links lead a person who was refused back to their OWN organisations,
    # so a bare address here would send them to the default one instead.
    "_core/templates/not-a-member.php",
]

# A bare address: a single "/" followed by something that is not another "/"
# (so "//cdn.example" is not matched) and not the end of the text.
BARE = r"/(?!/)[^\s'\"`<>]*"

# Kind 1: an address straight after a word that takes one.
# `Location:` is matched in any capitals, because PHP sends the redirect
# whichever way the word is written; `header('location: /x')` used to pass.
# The quote after `href=` and `action=` is optional, because a browser
# follows `<a href=/x>` just the same.
PATTERNS = [
    (re.compile(r"Location:\s*(" + BARE + r")", re.IGNORECASE), "redirect"),
    (re.compile(r"""href=["']?(""" + BARE + r")"), "link"),
    (re.compile(r"""action=["']?(""" + BARE + r")"), "form target"),
    (re.compile(r"""fetch\(\s*["'`](""" + BARE + r")"), "background request"),
    (re.compile(r"""\.open\(\s*["'][A-Z]+["']\s*,\s*["'`](""" + BARE + r")"), "background request"),
    (re.compile(r"""location(?:\.href)?\s*=\s*["'`](""" + BARE + r")"), "redirect made by a script"),
]

# Kind 2: any text in quotes that starts with a single "/". The closing quote
# must be the same mark as the opening one. Between the address and that
# closing quote there may be a whole PHP tag (which may hold quotes of its
# own) and any character except the closing mark, "<" and ">".
#
# This used to demand the closing quote straight after the address, so it
# gave up on `"/expenses/view?id=<?php echo (int) $id; ?>"` and on any text
# with a space in it. Leaving "<" and ">" out is deliberate: it is what keeps
# the end of an HTML tag (`name="x"/>`) from being read as the address "/".
# Only the address itself (group 2) is reported.
#
# `(?!/\s)`: a "/" followed straight by a space is a piece of a sentence, not
# an address. It is refused HERE, before anything is matched, and not skipped
# afterwards, so that a quoted address later on the same line is still read
# and not swallowed as part of the sentence.
QUOTED = re.compile(r"""(["'`])(?!/\s)(""" + BARE + r""")(?:<\?.*?\?>|(?!\1)[^<>])*\1""")

# What may stand straight before such a quote without it being a breach.
PATTERN_ARGUMENT = re.compile(r"\bpreg_\w+\(\s*$")
HELPER_ARGUMENT = re.compile(r"\b(?:Router|Site)::url\(\s*$")

# Served by the web server itself, the same for every organisation.
ALLOWED_PREFIXES = ("/assets/",)

HELPER = re.compile(r"\b(?:Router|Site)::url\(")


def findings_in(text: str) -> tuple[list[tuple[int, str, str]], int]:
    """Every bare address in one file's text, and how many addresses it builds with a helper."""
    found: list[tuple[int, str, str]] = []
    helped = 0
    for line_no, line in enumerate(text.splitlines(), start=1):
        stripped = line.strip()
        # A comment that quotes an address is not a breach of the rule.
        if stripped.startswith(("//", "*", "#", "/*")):
            continue
        helped += len(HELPER.findall(line))
        # Keyed by where the address starts, so one address found by both
        # kinds is reported once, under the more telling name.
        hits: dict[int, tuple[str, str]] = {}
        for pattern, kind in PATTERNS:
            for m in pattern.finditer(line):
                hits.setdefault(m.start(1), (kind, m.group(1)))
        for m in QUOTED.finditer(line):
            before = line[: m.start()]
            if PATTERN_ARGUMENT.search(before) or HELPER_ARGUMENT.search(before):
                continue
            kind = "breadcrumb link" if "$breadcrumbs" in line else "address in quotes"
            hits.setdefault(m.start(2), (kind, m.group(2)))
        for _, (kind, address) in sorted(hits.items()):
            if address.startswith(ALLOWED_PREFIXES):
                continue
            found.append((line_no, kind, address))
    return found, helped


def php_files_under(base: Path) -> tuple[list[Path], list[str]]:
    """
    Every PHP file under one listed place, and everything that stopped the
    whole of it being read.

    WHY NOT `Path.rglob()`: that is what the first version used, and it skips
    a folder it cannot open without a word. With a bare redirect planted in a
    folder made unreadable, the check printed "OK" and exited 0. `os.walk()`
    is told what to do with such an error instead: keep it, and fail.
    """
    if base.is_file():
        return [base], []

    errors: list[str] = []
    files: list[Path] = []

    def could_not_open(error: OSError) -> None:
        errors.append(f"{error.filename}: could not be opened ({error.strerror}).")

    for folder, subfolders, names in os.walk(base, onerror=could_not_open):
        for name in subfolders + names:
            path = Path(folder) / name
            # A link is not followed, so what it points at is not read.
            if path.is_symlink():
                errors.append(f"{path}: is a link, which is not followed.")
        files += [Path(folder) / name for name in names if name.endswith(".php")]
    return sorted(files), errors


def check() -> int:
    web = REPO_ROOT / "web"
    args = sys.argv[1:]
    if "--root" in args:
        position = args.index("--root")
        if position + 1 >= len(args):
            print("check_site_aware_addresses: --root needs a folder.")
            return 1
        web = Path(args[position + 1]).resolve()

    problems: list[str] = []
    findings: list[tuple[Path, int, str, str]] = []
    files_read = 0
    lines_read = 0
    helped_total = 0

    for entry in LISTED:
        base = web / entry
        if not base.exists():
            problems.append(f"{entry}: not found under {web}. Nothing was read there.")
            continue
        files, errors = php_files_under(base)
        problems += [f"{entry}: {error}" for error in errors]
        if not files:
            problems.append(f"{entry}: holds no PHP file. Nothing was read there.")
            continue
        for path in files:
            try:
                text = path.read_text(encoding="utf-8", errors="replace")
            except OSError as error:
                problems.append(f"{entry}: {path} could not be read ({error.strerror}).")
                continue
            found, helped = findings_in(text)
            files_read += 1
            lines_read += len(text.splitlines())
            helped_total += helped
            findings += [(path, line_no, kind, address) for line_no, kind, address in found]

    print(f"check_site_aware_addresses: read {files_read} PHP file(s), {lines_read} lines, in {len(LISTED)} listed place(s); "
          f"{helped_total} address(es) there are built by Router::url() or Site::url().")

    if problems:
        print("\nCOULD NOT READ ALL OF A LISTED PLACE — this run proves nothing about what it missed:\n")
        for problem in problems:
            print(f"  • {problem}")

    if findings:
        print(f"\nFOUND {len(findings)} BARE ADDRESS(ES)\n")
        print("When organisations are told apart by the first part of the address,")
        print("each of these lands on the DEFAULT organisation, not the one being used.\n")
        for path, line_no, kind, address in findings:
            route = address.split("?")[0].lstrip("/")
            print(f"  • {path.relative_to(web.parent)}:{line_no}")
            print(f"      {kind}: {address}")
            print(f"      build it instead: Router::url('{route}')")
            print()

    if problems or findings:
        print("FAILED — see above.")
        return 1

    print("OK — no bare address in the listed places. (Anything not on the list is not judged: see the top of this file.)")
    return 0


if __name__ == "__main__":
    sys.exit(check())
