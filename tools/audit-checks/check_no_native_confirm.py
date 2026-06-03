#!/usr/bin/env python3
"""
CI guard — fail when raw `confirm()` is reintroduced.

Portal.Confirm (#244) replaces native window.confirm() with a themed
Bootstrap modal. Every onsubmit/onclick="return confirm(...)" call site
was migrated to a `data-confirm="..."` attribute. This check fails if
new code reintroduces the raw pattern.

Allowed: portal-confirm.js's own fallback to native confirm when
Bootstrap JS isn't available.

Exit code:
  0 — no findings
  1 — findings (CI annotates; strict mode blocks)

Usage:
  python3 tools/audit-checks/check_no_native_confirm.py [--strict]
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
PHP_ROOTS = [REPO_ROOT / "web" / "_apps", REPO_ROOT / "web" / "public_html"]

# Match: onsubmit="return confirm(...)"  OR  onclick="return confirm(...)"
NATIVE_CONFIRM_RE = re.compile(
    r'on(?:submit|click)\s*=\s*"return\s+confirm\(',
    re.IGNORECASE,
)
# Match: window.confirm(...)  in non-portal-confirm.js sources
WINDOW_CONFIRM_RE = re.compile(r'\bwindow\.confirm\s*\(')

ALLOWED_FILES = {
    "web/public_html/assets/js/portal-confirm.js",
}


def check() -> int:
    findings: list[tuple[Path, int, str]] = []
    for root in PHP_ROOTS:
        if not root.exists():
            continue
        for ext in ("*.php", "*.html", "*.js"):
            for f in root.rglob(ext):
                rel = str(f.relative_to(REPO_ROOT))
                if rel in ALLOWED_FILES:
                    continue
                try:
                    text = f.read_text(encoding="utf-8", errors="ignore")
                except OSError:
                    continue
                for m in NATIVE_CONFIRM_RE.finditer(text):
                    line_no = text[: m.start()].count("\n") + 1
                    findings.append((f, line_no, "native onsubmit/onclick confirm()"))
                for m in WINDOW_CONFIRM_RE.finditer(text):
                    line_no = text[: m.start()].count("\n") + 1
                    findings.append((f, line_no, "window.confirm()"))

    print(f"Files scanned in: {[str(r.relative_to(REPO_ROOT)) for r in PHP_ROOTS]}")
    print(f"Findings: {len(findings)}")
    if findings:
        print("\n### Findings\n")
        for f, line, kind in findings:
            rel = str(f.relative_to(REPO_ROOT))
            print(f"  • {rel}:{line} — {kind}")
        print(
            "\nReplace with `data-confirm=\"message\"` on the form or button"
            " (see web/public_html/assets/js/portal-confirm.js)."
        )

    strict = "--strict" in sys.argv
    return 1 if (findings and strict) else 0


if __name__ == "__main__":
    sys.exit(check())
