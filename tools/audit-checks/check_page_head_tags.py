#!/usr/bin/env python3
"""Every page that writes its own <head> must also write the shared preview tags (#569).

WHAT THIS IS FOR
----------------
`web/_core/templates/head-meta.php` writes the description, the canonical
address and the Open Graph and Twitter tags that decide what a link to a page
looks like when somebody shares it. Most pages get those tags by including
`header.php`, which includes head-meta.php for them.

A page that must not have the portal's navbar prints its own <head> instead,
and those are exactly the pages a stranger sees: the sign-in page, the
forgotten-password pages, the public visitor form, anonymous prayer requests,
accepting an invitation, unsubscribing. Before #569 none of them wrote any of
these tags, so a link pasted into a conversation showed as a bare address.

This check fails when a page writes a <title> of its own and does NOT reach
head-meta.php by either route. It is the tripwire for the next such page: a new
public page is easy to write without ever thinking about the preview card, and
nothing about the page looks wrong when the tags are missing.

HOW "WRITES ITS OWN <title>" IS DECIDED, AND WHY IT IS NOT A SIMPLE SEARCH
--------------------------------------------------------------------------
PHP string literals are removed before anything is searched. Without that step
a page is wrongly flagged for an E-MAIL BODY it builds as a string: several
pages hold `'<!DOCTYPE html><html><head>…'` for a message they send, which is
not a page head at all. Three reasonable searches written while building this
gave 27, 15 and 12 "own head" pages, and all three were wrong in different
ways. That is why the rule is expressed in terms of a printed <title> with
string literals stripped, and why the exempt files are named one by one below
rather than described by a pattern.

WHAT THIS CHECK CANNOT SEE — read this before trusting a pass
-------------------------------------------------------------
1. It does not check that the tags are CORRECT, only that the shared file is
   required. A canonical address pointing at the wrong place still passes.
   Only rendering a page catches that.
2. It cannot tell WHICH BRANCH of a page the require sits in. A page that
   requires head-meta.php inside an `if` that rarely runs passes, even though
   most visitors get a <head> without the tags. Every page was read by hand
   for #569, so none does this today.
3. It says nothing about a page outside `web/_apps/`, and nothing about
   `header.php` itself — if the require were deleted from there, every
   ordinary page would lose its tags and this check would stay green, because
   those pages do not write a <title> of their own.
4. It cannot see a <title> built by a function called from elsewhere, nor one
   written inside a heredoc.

   An EARLIER VERSION of this list claimed, as its first limitation, that a
   page mentioning `header.php` anywhere was let through. That was true of the
   version that accepted either file, and a planted fault proved it mattered:
   deleting the require from a live public page left the check green. The rule
   is now "a live require of head-meta.php, in this file" and the planted
   fault is caught. The claim is kept here, corrected, because an
   out-of-date list of weaknesses is worse than none — somebody would have
   trusted it.

Exit code 0 when every page is accounted for, 1 otherwise.
"""

from __future__ import annotations

import pathlib
import re
import sys

REPO = pathlib.Path(__file__).resolve().parents[2]
APPS = REPO / "web" / "_apps"

# A PHP string literal, single- or double-quoted, with backslash escapes.
PHP_OPEN = re.compile(r"<\?php\b|<\?=")

# A require or include that names the shared partial. Matched against the
# file with comments stripped, so a commented-out require does not count.
LIVE_REQUIRE = re.compile(r"(?:require|include)(?:_once)?\s[^;]*head-meta\.php", re.S)

# Where PHP code starts and stops. Needed because string literals may only be
# blanked inside PHP; see printed_markup() for what went wrong without this.

# 🚫 Pages that write their own <head> and are DELIBERATELY left without the
#    preview tags. Each needs a reason, because "it looked like clutter" is how
#    a real page ends up on a list like this.
#
#    The common thread: none of these is an address a person shares with
#    another person. A preview card for them would be drawn by nothing, and a
#    canonical address would be wrong or meaningless.
EXEMPT = {
    # 📄 Printed to paper or turned into a PDF. There is no browser tab, no
    #    link to share, and a canonical address on a printout means nothing.
    "assets/labels-pdf.php": "becomes a PDF of sticky labels; never a shared link",
    "venues/invoice-pdf.php": "becomes a PDF invoice; never a shared link",
    "venues/schedule-pdf.php": "becomes a PDF schedule; never a shared link",
    "service-plans/print.php": "a print view of a plan; the shareable version is service-plans/public.php",
    # ✉️ A preview OF AN E-MAIL, shown inside the admin area. The <head> here
    #    belongs to the message being previewed, not to a page anybody shares.
    "admin/email-templates/preview.php": "previews an e-mail template, not a page",
    "newsletter/preview.php": "previews a newsletter, not a page",
    # 🖥️ Screens, not pages. These are opened on a wall display or a kiosk
    #    tablet and stay open; nobody shares the address.
    "worship/display.php": "a wall display for a service; not shared",
    "assets/kiosk.php": "a fixed kiosk screen; not shared",
    "assets/tag.php": "an asset tag screen reached by scanning the tag itself",
    "service-plans/confidence.php": "a presenter's confidence monitor; not shared",
    # 🚑 The recovery screen shown when a backup or restore had to leave the
    #    portal closed. It must not include head-meta.php: that file asks
    #    PortalAddress and the settings, which READ THE DATABASE, and this
    #    page exists for the case where the database connection had to be
    #    closed. Adding the tags here could break the one page that tells an
    #    administrator how to reopen the portal. It is also noindex and seen
    #    only by an administrator mid-recovery, so a preview card is pointless.
    "admin/maintenance/backup.php": "a recovery screen that must not touch the database",
    # 🧩 Embedded in somebody ELSE'S website, so its <head> is never used as a
    #    page head. Its route was removed on purpose in migration 189 (#478).
    "calendar/widget.php": "embedded in other sites; its route was removed in migration 189",
}

SHARED_PARTIAL = "head-meta.php"


def printed_markup(text: str) -> str:
    """The markup a page really outputs: string literals blanked out INSIDE PHP
    code only, with the HTML between the tags left exactly as it is.

    WHY THE PHP/HTML SPLIT IS LOAD-BEARING. The first version of this blanked
    string literals across the WHOLE file, and that was wrong in a way that
    passed its first test: HTML attributes carry quotes too, so a quote in
    `content="…"` could pair with a quote inside a nearby PHP block and blank
    everything between them — the page's real <title> included. Three pages
    that genuinely print a title (worship/display.php,
    service-plans/confidence.php, venues/invoice-pdf.php) were reported as
    "no longer writes a <title>", which read like a tidy-up rather than a
    fault in the check. A <title> line that contains a PHP echo, which is
    most of them, is exactly the shape that triggered it.

    So: an e-mail body held in a PHP string is blanked (it is PHP code, and it
    is not a page head), and markup written straight out as HTML survives.
    WHY A HAND-WRITTEN SCAN AND NOT A REGULAR EXPRESSION. The second version
    blanked string literals with one regular expression per PHP region, and
    that was ALSO wrong, in the way this project has already been caught by
    once (the QR guard, #623): COMMENTS WERE NOT REMOVED FIRST. A comment
    containing an apostrophe — `// Error: we don't know` — opens a "string"
    as far as a regular expression is concerned, so it pairs with the next
    apostrophe somewhere further down and every quote after that is paired
    one out of step. The visible symptom was `admin/livestream/index.php`
    being reported as printing its own <title> when that title is inside an
    `echo '…'`, because the blanking had slipped out of step before reaching
    it. So the scan below tracks code, both kinds of quote, and both kinds of
    comment as proper states.

    WHAT IT STILL CANNOT DO: heredoc and nowdoc (`<<<HTML`) bodies are treated
    as code, so markup written that way is not seen. Nothing under web/_apps/
    prints a <head> that way today; a page that did would be missed.
    """
    out: list[str] = []
    i = 0
    end = len(text)
    in_php = False

    while i < end:
        if in_php is False:
            marker = PHP_OPEN.search(text, i)
            if marker is None:
                out.append(text[i:])
                break
            out.append(text[i:marker.start()])
            i = marker.end()
            in_php = True
            continue

        ch = text[i]
        two = text[i:i + 2]

        if two == "?>":
            i += 2
            in_php = False
        elif two == "//" or ch == "#":
            nl = text.find("\n", i)
            i = end if nl < 0 else nl
        elif two == "/*":
            close = text.find("*/", i + 2)
            i = end if close < 0 else close + 2
        elif ch in "'\"":
            quote = ch
            i += 1
            while i < end:
                if text[i] == "\\":
                    i += 2
                    continue
                if text[i] == quote:
                    i += 1
                    break
                i += 1
        else:
            i += 1

    return "".join(out)


def php_without_comments(text: str) -> str:
    """The file with PHP comments removed and string literals KEPT.

    This is the opposite pass to printed_markup(), and both are needed.
    printed_markup() blanks strings to find markup the page really writes;
    this one keeps strings, because the thing being looked for — the name
    'head-meta.php' — lives INSIDE a string in a require statement.

    WHY IT EXISTS AT ALL. The first version of this check simply asked whether
    the text 'head-meta.php' appeared anywhere in the file. A planted fault
    walked straight through it: comment the require out, leave the line in
    place, and the page writes no preview tags while the check stays green.
    That is the same hole the QR guard had twice (#623), so it is guarded the
    same way here — by asking whether there is a LIVE require, with comments
    stripped, rather than whether a name appears.
    """
    out: list[str] = []
    i = 0
    end = len(text)
    in_php = False

    while i < end:
        if in_php is False:
            marker = PHP_OPEN.search(text, i)
            if marker is None:
                out.append(text[i:])
                break
            out.append(text[i:marker.end()])
            i = marker.end()
            in_php = True
            continue

        ch = text[i]
        two = text[i:i + 2]

        if two == "?>":
            out.append(two)
            i += 2
            in_php = False
        elif two == "//" or ch == "#":
            nl = text.find("\n", i)
            i = end if nl < 0 else nl
        elif two == "/*":
            close = text.find("*/", i + 2)
            i = end if close < 0 else close + 2
        elif ch in "'\"":
            quote = ch
            start = i
            i += 1
            while i < end:
                if text[i] == "\\":
                    i += 2
                    continue
                if text[i] == quote:
                    i += 1
                    break
                i += 1
            out.append(text[start:i])
        else:
            out.append(ch)
            i += 1

    return "".join(out)


def requires_shared_partial(text: str) -> bool:
    """Is there a LIVE require/include of head-meta.php in this file?"""
    return LIVE_REQUIRE.search(php_without_comments(text)) is not None


def main() -> int:
    if APPS.is_dir() is False:
        print(f"\u2717 cannot find {APPS}", file=sys.stderr)
        return 1

    missing: list[tuple[str, str]] = []
    reached = 0
    exempt_seen: set[str] = set()

    # 🚨 The one page that matters most, checked first and on its own.
    #    `header.php` carries the tags for every ORDINARY page. Those pages
    #    write no <title> of their own, so the loop below never looks at them:
    #    if the require were deleted from header.php, hundreds of pages would
    #    silently lose their description, canonical address and preview card
    #    and this check would still pass. It was the biggest blind spot in the
    #    first version, and it is cheap to close.
    shared_header = REPO / "web" / "_core" / "templates" / "header.php"
    shared_partial = REPO / "web" / "_core" / "templates" / SHARED_PARTIAL
    header_fault = ""
    if shared_partial.is_file() is False:
        header_fault = f"web/_core/templates/{SHARED_PARTIAL} is missing"
    elif shared_header.is_file() is False:
        header_fault = "web/_core/templates/header.php is missing"
    elif requires_shared_partial(shared_header.read_text(encoding="utf-8", errors="replace")) is False:
        header_fault = (
            "web/_core/templates/header.php no longer requires "
            f"{SHARED_PARTIAL}, so every ordinary page has lost its "
            "description, canonical address and preview card"
        )

    for path in sorted(APPS.rglob("*.php")):
        rel = path.relative_to(APPS).as_posix()
        text = path.read_text(encoding="utf-8", errors="replace")

        if "<title" not in printed_markup(text).lower():
            continue

        if rel in EXEMPT:
            exempt_seen.add(rel)
            continue

        if requires_shared_partial(text) is True:
            reached += 1
            continue

        missing.append((rel, "writes its own <title> but never reaches " + SHARED_PARTIAL))

    # 🧹 An exemption for a file that no longer writes a <title> is stale, and a
    #    stale list is how a real page gets quietly excused later.
    stale = sorted(set(EXEMPT) - exempt_seen)

    print(f"Pages writing a <title>: {reached + len(exempt_seen) + len(missing)}")
    print(f"  reaching {SHARED_PARTIAL}: {reached}")
    print(f"  deliberately exempt:      {len(exempt_seen)}")

    if stale:
        print()
        print("\u2717 Stale exemptions — these files no longer write a <title>,")
        print("  so their entry in EXEMPT should be removed:")
        for rel in stale:
            print(f"    {rel}")

    if missing:
        print()
        print("\u2717 Pages with no preview tags (#569):")
        for rel, why in missing:
            print(f"    web/_apps/{rel}")
            print(f"        {why}")
        print()
        print("  Fix: inside the page's <head>, add")
        print("    <?php")
        print("    $pageTitle       = '…';")
        print("    $pageDescription = '…';")
        print("    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates'")
        print("          . DIRECTORY_SEPARATOR . 'head-meta.php';")
        print("    ?>")
        print("  Or, if the page is never a link anybody shares, add it to")
        print("  EXEMPT in this file WITH A REASON.")

    if header_fault != "":
        print()
        print("\u2717 " + header_fault)
        print("  Every page that uses the shared header depends on that one line.")

    if missing or stale or header_fault != "":
        return 1

    print("OK \u2014 every page writing a <title> reaches the shared preview tags.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
