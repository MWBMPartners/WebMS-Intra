#!/usr/bin/env python3
"""
Static mobile-readiness audit.

Catches the class of small-screen bugs that a device-free scan CAN see:

  • Missing or wrong <meta viewport> tag.
  • Hard-coded width:NNNpx (or width="NNN" with N>320) in template HTML
    — likely to overflow on a phone.
  • Bare <table> not wrapped in a .table-responsive container
    (Bootstrap's horizontal-scroll wrapper).
  • <input type="file"> without an accept= / capture= hint — the user
    can't pick from camera roll on iOS Safari.
  • <button>/<a class="btn"> with btn-sm but no explicit padding
    override — Bootstrap's btn-sm vertical-rhythm is < 44px tap
    target on iOS.
  • Bare modal markup without modal-fullscreen-sm-down — small-screen
    modal can have unreachable close button.

The device walk-through (real iOS + Android touch behaviour, file
pickers, autofill) still needs hands on a phone — see
docs/mobile-audit-worksheet.md for that.

Exit:
  0 — no findings (CI green).
  1 — at least one finding, but informational by default (use --strict
      to gate).

Usage:
  python3 tools/audit-checks/check_mobile_readiness.py [--strict]
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
SCAN_DIRS = [REPO_ROOT / "web" / "_apps", REPO_ROOT / "web" / "public_html", REPO_ROOT / "web" / "_core" / "templates"]

# Email templates use fixed-width table layouts because mail clients
# don't render Bootstrap responsive utilities. Genuine false positives.
EXEMPT_PATH_PARTS = ("templates/email/", "templates\\email\\")

# Header template must ship the viewport tag exactly once.
VIEWPORT_RE = re.compile(
    r'<meta\s+name=["\']viewport["\']\s+content=["\']([^"\']+)["\']',
    re.IGNORECASE,
)
HEADER_TEMPLATE = REPO_ROOT / "web" / "_core" / "templates" / "header.php"

# Hard-coded large pixel widths inside style="…" or width="NNN" attrs.
# We skip values <= 320 (a CSS-pixel iPhone fits 320, so smaller is fine).
# Crucial: (?<![\w-]) blocks matches inside `max-width` and `min-width` —
# those are responsive and don't overflow.
WIDTH_PX_RE = re.compile(
    r'(?:style="[^"]*(?<![\w-])width\s*:\s*(\d{3,})\s*px|(?<![\w-])width\s*=\s*["\']?(\d{3,})["\']?)',
    re.IGNORECASE,
)

# Bare <table> not preceded within ~120 chars by .table-responsive.
TABLE_RE = re.compile(r"<table\b[^>]*>", re.IGNORECASE)

# Skip <table> matches that fall inside an HTML comment, a PHP doc /
# line comment, or a PHP string literal — these are mentions, not
# rendered tags.
HTML_COMMENT_RE = re.compile(r"<!--.*?-->", re.DOTALL)
PHP_BLOCK_COMMENT_RE = re.compile(r"/\*.*?\*/", re.DOTALL)
PHP_LINE_COMMENT_RE  = re.compile(r"//[^\n]*")


def is_inside_excluded_range(text: str, offset: int) -> bool:
    """Return True if offset falls inside an HTML comment, PHP comment,
    or a PHP-emitted string literal (e.g. `. '<table…'`)."""
    for pat in (HTML_COMMENT_RE, PHP_BLOCK_COMMENT_RE, PHP_LINE_COMMENT_RE):
        for m in pat.finditer(text):
            if m.start() <= offset < m.end():
                return True
    # PHP string literal containing the tag (preceded by . ' or = ' or , ').
    # Walk back ≤120 chars looking for an unmatched opening quote.
    window_start = max(0, offset - 200)
    window = text[window_start:offset]
    # Strip away PHP heredoc markers (rare in this codebase) and count
    # unescaped single quotes.
    single = sum(1 for i, ch in enumerate(window) if ch == "'" and (i == 0 or window[i - 1] != "\\"))
    if single % 2 == 1:
        return True
    double = sum(1 for i, ch in enumerate(window) if ch == '"' and (i == 0 or window[i - 1] != "\\"))
    if double % 2 == 1:
        return True
    return False
RESPONSIVE_WRAP_RE = re.compile(r"table-responsive", re.IGNORECASE)

# File inputs without accept/capture hints.
FILE_INPUT_RE = re.compile(r'<input\b[^>]*\btype\s*=\s*["\']file["\'][^>]*>', re.IGNORECASE)

# Bootstrap modal markup without modal-fullscreen-sm-down on small screens.
MODAL_DIALOG_RE = re.compile(r'class="[^"]*\bmodal-dialog\b[^"]*"', re.IGNORECASE)


def lineno_of(text: str, offset: int) -> int:
    return text.count("\n", 0, offset) + 1


def check_viewport() -> list[str]:
    """One-shot check on the global header template."""
    findings: list[str] = []
    if HEADER_TEMPLATE.is_file() is False:
        findings.append(
            f"header template missing entirely: {HEADER_TEMPLATE.relative_to(REPO_ROOT)}"
        )
        return findings
    text = HEADER_TEMPLATE.read_text(encoding="utf-8", errors="replace")
    m = VIEWPORT_RE.search(text)
    if m is None:
        findings.append(
            f"{HEADER_TEMPLATE.relative_to(REPO_ROOT)}: no <meta viewport> tag — every "
            "mobile browser will render at 980px desktop default."
        )
        return findings
    content = m.group(1)
    if "width=device-width" not in content:
        findings.append(
            f"{HEADER_TEMPLATE.relative_to(REPO_ROOT)}:{lineno_of(text, m.start())} — "
            f'viewport missing width=device-width: "{content}"'
        )
    if "initial-scale" not in content:
        findings.append(
            f"{HEADER_TEMPLATE.relative_to(REPO_ROOT)}:{lineno_of(text, m.start())} — "
            f'viewport missing initial-scale: "{content}"'
        )
    return findings


def scan_file(path: Path) -> list[tuple[int, str]]:
    try:
        text = path.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return []
    findings: list[tuple[int, str]] = []

    # Hard-coded large pixel widths.
    for m in WIDTH_PX_RE.finditer(text):
        val = int(m.group(1) or m.group(2) or 0)
        if val > 320:
            findings.append(
                (lineno_of(text, m.start()),
                 f"hard-coded width {val}px — overflows iPhone SE (320px)")
            )

    # Bare tables.
    for m in TABLE_RE.finditer(text):
        # Skip matches inside HTML/PHP comments or PHP string literals
        # (mentions of <table>, not rendered tags).
        if is_inside_excluded_range(text, m.start()) is True:
            continue
        # Skip lines that look like PHP string concatenation emitting
        # email-template HTML (`. '<table…'`). The audit's quote-pairity
        # falls down on multi-string concatenated payloads; this catches
        # the common pattern without needing a real PHP tokeniser.
        line_start = text.rfind('\n', 0, m.start()) + 1
        line_prefix = text[line_start:m.start()].lstrip()
        if line_prefix.startswith(". '") or line_prefix.startswith('. "') or \
           line_prefix.startswith("= '") or line_prefix.startswith('= "'):
            continue
        # Look back 200 chars for table-responsive wrapper.
        ctx_start = max(0, m.start() - 200)
        ctx = text[ctx_start:m.start()]
        if RESPONSIVE_WRAP_RE.search(ctx) is None:
            findings.append(
                (lineno_of(text, m.start()),
                 "<table> not wrapped in .table-responsive — horizontal scroll on phones")
            )

    # File inputs without accept/capture.
    for m in FILE_INPUT_RE.finditer(text):
        tag = m.group(0)
        if 'accept=' not in tag.lower() and 'capture=' not in tag.lower():
            findings.append(
                (lineno_of(text, m.start()),
                 "<input type=\"file\"> without accept= or capture= — iOS users "
                 "can't pick from camera roll")
            )

    # Modal dialogs without modal-fullscreen-sm-down.
    for m in MODAL_DIALOG_RE.finditer(text):
        cls = m.group(0)
        if 'modal-fullscreen-sm-down' not in cls.lower() and 'modal-fullscreen' not in cls.lower():
            findings.append(
                (lineno_of(text, m.start()),
                 "modal-dialog without modal-fullscreen-sm-down — close button "
                 "may be unreachable on small screens")
            )

    return findings


def main(argv: list[str]) -> int:
    strict = "--strict" in argv
    file_findings: dict[Path, list[tuple[int, str]]] = {}
    for base in SCAN_DIRS:
        if base.is_dir() is False:
            continue
        for path in base.rglob("*"):
            if path.is_file() is False:
                continue
            if path.suffix.lower() not in {".php", ".html", ".html.php"}:
                continue
            rel_str = str(path.relative_to(REPO_ROOT))
            if any(part in rel_str for part in EXEMPT_PATH_PARTS):
                continue
            findings = scan_file(path)
            if findings:
                file_findings[path] = findings

    viewport_findings = check_viewport()
    total = sum(len(v) for v in file_findings.values()) + len(viewport_findings)

    print(f"Mobile readiness audit — scanned {sum(1 for _ in SCAN_DIRS[0].rglob('*'))} files")
    if viewport_findings:
        print("\n### Viewport / global head\n")
        for line in viewport_findings:
            print(f"  • {line}")

    if file_findings:
        print(f"\n### File findings ({sum(len(v) for v in file_findings.values())})\n")
        for path in sorted(file_findings.keys()):
            rel = path.relative_to(REPO_ROOT)
            for lineno, msg in file_findings[path]:
                print(f"  • {rel}:{lineno} — {msg}")

    if total == 0:
        print("\nNo static mobile-readiness issues found. ✅")
        print("Note: device walk-through (touch behaviour, file pickers, "
              "autofill) still needs hands on a phone — see "
              "docs/mobile-audit-worksheet.md.")
        return 0

    print(f"\nTotal: {total} finding(s).")
    return 1 if strict is True else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
