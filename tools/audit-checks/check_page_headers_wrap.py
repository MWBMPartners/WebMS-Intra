#!/usr/bin/env python3
"""
-------------------------------------------------------------------------------
 File:        tools/audit-checks/check_page_headers_wrap.py
 Description: Every page header -- the row holding a page's <h1> and its
              buttons -- must be one the shared phone rule in portal.css can
              reach, and that rule must still be there (#848).
 Package:     Portal\\Tools\\AuditChecks
 Author:      MWBM Partners Ltd (t/a MWservices)
 Copyright:   2026-present MWBM Partners Ltd (t/a MWservices) - All Rights Reserved
 Version:     1.0.0
 Link:        https://github.com/MWBMPartners/WebMS-Intra/issues/848
-------------------------------------------------------------------------------

WHY THIS EXISTS
    Most pages open with a row like this:

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Attendance</h1>
            <div class="d-flex gap-2"> ...four or five buttons... </div>
        </div>

    A flex row does not wrap unless it is told to, so on a 320-390px phone a
    title beside its buttons ran off the right-hand side and the WHOLE page
    scrolled sideways. #833 found it on four pages and fixed those four by
    adding `flex-wrap` to each; #848 counted the rest of the same shape.
    Fixing them one page at a time is how the shape came to be on so many
    pages: each new page copied the row from an older one.

    So the fix is ONE rule in portal.css (the block headed "Page headers wrap
    on a phone"), keyed on the row's SHAPE rather than on a class each page
    must remember: on a screen narrower than 768px, any flex row whose child
    is the <h1>, or whose child holds the <h1>, may wrap, and so may the
    button groups inside it. A page written tomorrow in the same shape is
    covered without anybody touching it.

    That moves the risk. The rule can only reach an <h1> that is a CHILD or
    a GRANDCHILD of the row, and only a row that is not told `flex-nowrap`.
    This check is what keeps every page inside that reach, and it pins the
    rule itself so that deleting or narrowing it fails here.

WHAT IT FAILS ON
    1. The rule in portal.css is missing or has changed (comments and
       spacing are ignored; anything else is a change).
    2. A page's <h1> sits inside a header-shaped flex row (one that spreads
       its children apart, `justify-content-between`) that is its
       great-grandparent or further up -- the rule cannot reach that row, so
       neither it nor its button group may wrap. This is reported EVEN WHEN a
       flex box nearer the <h1> is in reach: an icon-and-title block
       (`<div class="d-flex"><i></i><div><h1></h1><p></p></div></div>`)
       inside the spread row wraps itself and nothing else, and the spread
       row still runs off the screen (round 2 of the check measured one 207px
       too wide at 320px, with every check here green). A row that only
       CENTRES one card (the sign-in pages' `justify-content-center
       min-vh-100`) is not a header and is not reported: one child has
       nothing to wrap.
    3. A flex box the rule does reach (the <h1>'s parent or grandparent)
       cannot wrap after all:
       * it says `flex-nowrap` -- Bootstrap's `!important` wins over the rule
         at every width;
       * it says `flex-sm-nowrap` -- the same, from 576px, so between 576 and
         767px the row could not wrap;
       * its `style=` sets `flex-wrap` to anything but `wrap` or
         `wrap-reverse` (`nowrap`, or a keyword such as `initial` that comes
         to the same thing), or sets the `flex-flow` shorthand without a
         wrap value -- a shorthand puts every part it leaves out back to its
         starting value, and flex-wrap's is `nowrap`. A style attribute wins
         over the rule. `style="flex-wrap: wrap"` is NOT reported (it agrees
         with the rule), and nor is `white-space`: that decides whether the
         words INSIDE an item may break onto a second line, never whether
         the row's items may, so `white-space: nowrap` on the row does not
         stop it wrapping. The first version reported any style mentioning
         either word, which cried wolf on both correct cases.
    4. A row is a column on the smallest phones but turns back into a row at
       576px (`flex-column` with `flex-sm-row`): the rule leaves columns
       alone, so between 576 and 767px that row could not wrap.

WHAT IT CANNOT SEE, said plainly
    * Whether a header really FITS. That is measured in a real browser by
      tools/page-headers-wrap-selftest.mjs, which draws every header this
      check finds at 320 and 390px. This check only keeps each header in a
      shape the rule reaches.
    * An <h1> printed by PHP as a string (`echo '<h1>...'`), or built by
      JavaScript. It reads the markup outside `<?php ... ?>` blocks only.
    * A header made with an <h2>, or a flex row drawn by a class of the
      page's own instead of Bootstrap's `d-flex`.
    * A flex row further than two levels up that does not spread its
      children apart (`gap-3`, say, holding an icon and a title block) --
      not reported, because the sign-in pages' centring row has the same
      shape and is correct. Such a row is not drawn whole by the browser
      test either: it draws the outermost flex box within reach.
    * A button group inside the header that a page tells not to wrap
      (`flex-nowrap` on the group rather than on the row).
    * A button group inside the header that is a `btn-group` or
      `d-inline-flex` rather than a `d-flex`: the rule wraps `d-flex` groups
      only, because a `btn-group` is a joined set of buttons whose borders
      would break apart if it wrapped.
    * Where PHP `if`/`else` draws two different opening tags for one closing
      tag, the parent it finds may be the wrong branch's.

    --headers-json prints every header it found, with its markup, for the
    browser test; --self-test runs planted faults and correct shapes through
    it and fails if any is judged wrongly.
"""
from __future__ import annotations

import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
SCAN = [ROOT / "web" / "_apps", ROOT / "web" / "_core" / "templates"]
PORTAL_CSS = ROOT / "web" / "public_html" / "assets" / "css" / "portal.css"

# The rule, exactly, with comments removed and every run of spacing made one
# space. If you change the rule on purpose, change this too -- and run
# tools/page-headers-wrap-selftest.mjs, which is what proves it still works.
PINNED_RULE = (
    "@media (max-width: 767.98px) { "
    ".d-flex:not(.flex-column):has(> h1, > * > h1) { flex-wrap: wrap; gap: 0.5rem; } "
    ".d-flex:has(> h1, > * > h1) > .d-flex:not(:has(> h1)) { flex-wrap: wrap; row-gap: 0.5rem; } "
    "}"
)

PHP_BLOCK = re.compile(r"<\?(?:php|=).*?(?:\?>|\Z)", re.S)
HTML_COMMENT = re.compile(r"<!--.*?-->", re.S)
TAG = re.compile(r"<(/?)([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>", re.S)
CLASS_ATTR = re.compile(r"""\bclass\s*=\s*(["'])(.*?)\1""", re.S)
STYLE_ATTR = re.compile(r"""\bstyle\s*=\s*(["'])(.*?)\1""", re.S)
VOID = {"area", "base", "br", "col", "embed", "hr", "img", "input", "link",
        "meta", "param", "source", "track", "wbr"}


def _blank(match: re.Match) -> str:
    """Keep line breaks, so offsets and line numbers stay the source's own."""
    return re.sub(r"[^\n]", " ", match.group(0))


def visible_markup(src: str) -> str:
    """The page's own HTML: PHP blocks and HTML comments blanked, same length."""
    return HTML_COMMENT.sub(_blank, PHP_BLOCK.sub(_blank, src))


def classes(attrs: str) -> set[str]:
    m = CLASS_ATTR.search(attrs)
    return set(m.group(2).split()) if m else set()


def style(attrs: str) -> str:
    m = STYLE_ATTR.search(attrs)
    return m.group(2) if m else ""


WRAPS = {"wrap", "wrap-reverse"}


def style_stops_wrap(st: str) -> str | None:
    """Why a style attribute stops a flex box wrapping, or None if it does not.

    Judged by what each declaration DOES, not by which words it mentions:
    `flex-wrap` to anything but wrap/wrap-reverse stops it (`nowrap`, and the
    keywords `initial`, `unset`, `revert` come to `nowrap` too); a
    `flex-flow` shorthand stops it unless it names a wrap value, because a
    shorthand resets what it leaves out and flex-wrap starts as `nowrap`.
    `white-space` is about the words inside an item and is ignored."""
    for decl in st.split(";"):
        if ":" not in decl:
            continue
        prop, value = decl.split(":", 1)
        prop = prop.strip().lower()
        words = value.lower().replace("!important", " ").split()
        if prop == "flex-wrap" and words != [] and (len(words) != 1 or words[0] not in WRAPS):
            return f"flex-wrap is {' '.join(words)}, not wrap"
        if prop == "flex-flow" and words != [] and WRAPS.isdisjoint(words):
            return f"flex-flow \"{' '.join(words)}\" names no wrap value, so flex-wrap goes back to nowrap"
    return None


def is_row(cls: set[str]) -> bool:
    """A Bootstrap flex box laid out as a row on the smallest phone."""
    return "d-flex" in cls and "flex-column" not in cls


def element_end(text: str, start: int, name: str) -> int:
    """Where the element opened at `start` closes (depth-counted, same name)."""
    depth = 0
    for m in TAG.finditer(text, start):
        if m.group(2).lower() != name:
            continue
        if m.group(1) == "":
            depth += 1
        else:
            depth -= 1
            if depth == 0:
                return m.end()
    return len(text)


def sample_markup(src: str) -> str:
    """The header's markup as a browser would roughly receive it.

    A PHP block inside a tag (an address, a class) is dropped; one between
    tags that prints something becomes the words "Sample text"; any other
    (an `if`, a loop) is dropped, so BOTH sides of an `if`/`else` are drawn.
    That is deliberate: it can only make a header wider than any real one,
    never narrower, so a header that fits here fits on the real page too --
    except where a real title or label is longer than two words."""
    out, pos = [], 0
    for m in PHP_BLOCK.finditer(src):
        out.append(src[pos:m.start()])
        before = src[:m.start()]
        inside_tag = before.rfind("<") > before.rfind(">")
        body = m.group(0)
        prints = body.startswith("<?=") or re.search(r"\becho\b|\bprint\b", body) is not None
        if inside_tag is False and prints is True:
            out.append("Sample text")
        pos = m.end()
    out.append(src[pos:])
    return HTML_COMMENT.sub("", "".join(out))


def scan_text(rel: str, src: str):
    """Return (headers, findings) for one file's source."""
    text = visible_markup(src)
    stack: list[tuple[str, str, int]] = []
    headers, findings = [], []
    for m in TAG.finditer(text):
        close, name, attrs = m.group(1), m.group(2).lower(), m.group(3)
        if close:
            for i in range(len(stack) - 1, -1, -1):
                if stack[i][0] == name:
                    del stack[i:]
                    break
            continue
        if name == "h1":
            line = text.count("\n", 0, m.start()) + 1
            where = f"{rel}:{line}"
            # The flex boxes holding this <h1>, nearest first, with how many
            # levels up each is (1 = parent, 2 = grandparent ...).
            flex = [(len(stack) - i, stack[i]) for i in range(len(stack) - 1, -1, -1)
                    if "d-flex" in classes(stack[i][1])]
            near = [(lvl, el) for lvl, el in flex if lvl <= 2]
            # Only a row spreading its children apart is a page header.
            # The first version counted ANY flex row further up, and cried
            # wolf on five sign-in and public pages, where a
            # `d-flex justify-content-center min-vh-100` row only centres
            # one card on the screen: a row with one child has nothing to
            # wrap onto a second line.
            # The second version looked further up ONLY when no flex box was
            # within reach, so an icon-and-title `d-flex` block inside the
            # spread row hid it: the rule wrapped the block, the spread row
            # and its buttons stayed on one line, and the header was 207px
            # too wide at 320px with this check green (round 2 of the check).
            # A far spread row is a fault whatever sits nearer.
            far = [(lvl, el) for lvl, el in flex
                   if lvl > 2 and is_row(classes(el[1])) and "justify-content-between" in classes(el[1])]
            if far != []:
                findings.append(
                    f"{where}  the <h1> is {far[0][0]} levels inside a flex row "
                    f"(class=\"{' '.join(sorted(classes(far[0][1][1])))}\"); the shared "
                    "phone rule reaches only a parent or grandparent, so this row cannot wrap")
            if near == []:
                continue
            header_lvl, header_el = near[-1]  # the outermost within reach is the header row
            for lvl, el in near:
                cls = classes(el[1])
                if "flex-nowrap" in cls:
                    findings.append(f"{where}  a flex box around the <h1> says flex-nowrap, "
                                    "which wins over the shared phone rule")
                if "flex-sm-nowrap" in cls:
                    findings.append(f"{where}  a flex box around the <h1> says flex-sm-nowrap, "
                                    "which wins over the shared phone rule from 576 to 767px")
                why = style_stops_wrap(style(el[1]))
                if why is not None:
                    findings.append(f"{where}  a flex box around the <h1> has style=\"{style(el[1])}\": "
                                    f"{why}, which wins over the shared phone rule")
                if "flex-column" in cls and "flex-sm-row" in cls:
                    findings.append(f"{where}  flex-column with flex-sm-row is a row from 576px, "
                                    "where the shared phone rule (which leaves columns alone) "
                                    "cannot make it wrap")
            start = header_el[2]
            end = element_end(text, start, header_el[0])
            hcls = classes(header_el[1])
            headers.append({
                "where": where,
                "rowClass": " ".join(sorted(hcls)),
                "isRow": is_row(hcls),
                "wrapsOnItsOwn": "flex-wrap" in hcls,
                "markup": sample_markup(src[start:end]),
            })
        if name in VOID or attrs.rstrip().endswith("/"):
            continue
        stack.append((name, attrs, m.start()))
    return headers, findings


def normalised_css(css: str) -> str:
    css = re.sub(r"/\*.*?\*/", " ", css, flags=re.S)
    return re.sub(r"\s+", " ", css).strip()


def rule_present(css: str) -> bool:
    return PINNED_RULE in normalised_css(css)


def scan_all():
    headers, findings = [], []
    for base in SCAN:
        for path in sorted(base.rglob("*.php")):
            rel = str(path.relative_to(ROOT))
            h, f = scan_text(rel, path.read_text(encoding="utf-8", errors="replace"))
            headers += h
            findings += f
    return headers, findings


def self_test() -> int:
    """Planted faults must be reported; correct shapes must not be."""
    cases = [
        # (name, source, findings expected, headers expected)
        ("a title beside buttons, no flex-wrap (the #848 shape, now reached)",
         '<div class="d-flex justify-content-between"><h1>T</h1><div class="d-flex gap-2"><a>B</a></div></div>', 0, 1),
        ("a title block with a subtitle, one level down",
         '<div class="d-flex justify-content-between"><div><h1>T</h1><p>S</p></div><a>B</a></div>', 0, 1),
        ("a column header (flex-column flex-md-row)",
         '<div class="d-flex flex-column flex-md-row"><div><h1>T</h1></div><div class="d-flex"><a>B</a></div></div>', 0, 1),
        ("an <h1> in a plain container is not a header",
         '<div class="container"><h1>T</h1></div>', 0, 0),
        ("a commented-out header is not read",
         '<!-- <div class="d-flex flex-nowrap"><h1>T</h1></div> -->', 0, 0),
        ("PLANT: the row says flex-nowrap",
         '<div class="d-flex flex-nowrap justify-content-between"><h1>T</h1><a>B</a></div>', 1, 1),
        ("PLANT: the row stops wrapping by its style",
         '<div class="d-flex" style="flex-wrap: nowrap"><h1>T</h1><a>B</a></div>', 1, 1),
        ("PLANT: the <h1> three levels inside the row",
         '<div class="d-flex justify-content-between"><div><div><h1>T</h1></div></div><a>B</a></div>', 1, 0),
        ("a centring row holding one card, the <h1> deep inside (the sign-in pages)",
         '<div class="d-flex justify-content-center min-vh-100"><div class="card"><div class="card-body"><h1>T</h1></div></div></div>', 0, 0),
        ("PLANT: a row again from 576px",
         '<div class="d-flex flex-column flex-sm-row"><h1>T</h1><a>B</a></div>', 1, 1),
        ("PLANT: an icon-and-title d-flex block hides the spread row above it (round 2)",
         '<div class="d-flex justify-content-between"><div class="d-flex align-items-center"><i></i>'
         '<div><h1>T</h1><p>S</p></div></div><div class="d-flex"><a>B</a></div></div>', 1, 1),
        ("the same header with the icon inside the <h1>, so the row is its grandparent",
         '<div class="d-flex justify-content-between"><div><h1><i></i>T</h1><p>S</p></div>'
         '<div class="d-flex"><a>B</a></div></div>', 0, 1),
        ("an icon-and-title d-flex block directly in the spread row, no subtitle wrapper",
         '<div class="d-flex justify-content-between"><div class="d-flex align-items-center"><i></i>'
         '<h1>T</h1></div><div class="d-flex"><a>B</a></div></div>', 0, 1),
        ("a style that agrees with the rule (flex-wrap: wrap) is not reported",
         '<div class="d-flex justify-content-between" style="flex-wrap: wrap"><h1>T</h1><a>B</a></div>', 0, 1),
        ("white-space on the row is not reported: it does not stop items wrapping",
         '<div class="d-flex justify-content-between" style="white-space: nowrap"><h1>T</h1><a>B</a></div>', 0, 1),
        ("flex-flow naming wrap is not reported",
         '<div class="d-flex" style="flex-flow: row wrap"><h1>T</h1><a>B</a></div>', 0, 1),
        ("PLANT: flex-flow without a wrap value puts flex-wrap back to nowrap",
         '<div class="d-flex" style="flex-flow: row"><h1>T</h1><a>B</a></div>', 1, 1),
        ("PLANT: flex-wrap: initial comes to nowrap",
         '<div class="d-flex" style="color: red; flex-wrap: initial !important"><h1>T</h1><a>B</a></div>', 1, 1),
        ("PLANT: the row says flex-sm-nowrap",
         '<div class="d-flex flex-sm-nowrap justify-content-between"><h1>T</h1><a>B</a></div>', 1, 1),
        ("PHP inside the row is not mistaken for a tag",
         '<div class="d-flex <?php echo $x; ?>"><h1><?= $t ?></h1><?php if ($a): ?><a>B</a><?php endif; ?></div>', 0, 1),
    ]
    bad = 0
    for name, src, want_f, want_h in cases:
        h, f = scan_text("fixture.php", src)
        ok = len(f) == want_f and len(h) == want_h
        bad += 0 if ok else 1
        print(("  ok   " if ok else "  FAIL ") + f"{name}  (findings {len(f)}/{want_f}, headers {len(h)}/{want_h})")
    css_cases = [
        ("the pinned rule, with comments and other spacing", "/* x */\n@media (max-width: 767.98px)  {\n"
         " .d-flex:not(.flex-column):has(> h1, > * > h1) {\n  flex-wrap: wrap;\n  gap: 0.5rem;\n }\n"
         " /* y */ .d-flex:has(> h1, > * > h1) > .d-flex:not(:has(> h1)) { flex-wrap: wrap; row-gap: 0.5rem; }\n}", True),
        ("PLANT: the rule deleted", ".alert { overflow-wrap: break-word; }", False),
        ("PLANT: the button groups no longer wrap", PINNED_RULE.replace(
            "> .d-flex:not(:has(> h1)) { flex-wrap: wrap;", "> .d-flex:not(:has(> h1)) { flex-wrap: nowrap;"), False),
        ("PLANT: the rule reaches a direct <h1> only", PINNED_RULE.replace(
            ".d-flex:not(.flex-column):has(> h1, > * > h1)", ".d-flex:not(.flex-column):has(> h1)"), False),
    ]
    for name, css, want in css_cases:
        ok = rule_present(css) is want
        bad += 0 if ok else 1
        print(("  ok   " if ok else "  FAIL ") + name)
    total = len(cases) + len(css_cases)
    if bad == 0:
        print(f"OK: self-test, all {total} cases judged as expected.")
        return 0
    print(f"FAIL: self-test, {bad} of {total} cases judged wrongly.")
    return 1


def main() -> int:
    if "--self-test" in sys.argv[1:]:
        return self_test()
    headers, findings = scan_all()
    if "--headers-json" in sys.argv[1:]:
        print(json.dumps(headers, indent=1))
        return 0
    if PORTAL_CSS.is_file() is False:
        findings.insert(0, f"{PORTAL_CSS.relative_to(ROOT)} is missing")
    elif rule_present(PORTAL_CSS.read_text(encoding="utf-8")) is False:
        findings.insert(0, "portal.css no longer holds the rule that lets page headers wrap on a phone "
                           "(the block headed 'Page headers wrap on a phone', #848), or it has changed. "
                           "The rule this check expects is PINNED_RULE in this file.")
    if findings != []:
        print("A page header could not wrap on a phone (#848):")
        for item in findings:
            print("  " + item)
        print("Keep the page's <h1> a child or grandchild of its flex row (an icon goes inside the <h1>,")
        print("not in a d-flex block of its own), with no flex-nowrap or flex-sm-nowrap, and keep")
        print("the rule in portal.css. tools/page-headers-wrap-selftest.mjs measures it in a browser.")
        return 1
    own = sum(1 for h in headers if h["isRow"] and h["wrapsOnItsOwn"] is False)
    print(f"OK: {len(headers)} page headers found, every one inside the reach of the shared phone rule;")
    print(f"    {own} of them are rows that do not say flex-wrap themselves and rely on it.")
    print("    Whether they really fit is measured by tools/page-headers-wrap-selftest.mjs, not here.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
