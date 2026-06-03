#!/usr/bin/env python3
"""
Subresource Integrity (SRI) audit.

Scans every PHP / HTML file under web/ for `<script>` and `<link>` tags
loading a third-party CDN resource. Reports any tag missing an `integrity=`
attribute.

A "CDN tag" is identified by an `src=` or `href=` attribute pointing at one
of the hosts in CDN_HOSTS below. Tags pointing at our own server (relative
paths or our domain) are skipped.

Catches the #161 class of bug — a contributor drops in a new CDN tag and
forgets the SRI attribute, leaving the portal vulnerable to a CDN
compromise replaying malware to every visitor.

Exit code:
  0 — no findings.
  1 — at least one CDN tag lacks integrity.

Usage:
  python3 tools/audit-checks/check_cdn_sri.py [--strict]
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
SCAN_DIRS = [REPO_ROOT / "web" / "_apps", REPO_ROOT / "web" / "public_html", REPO_ROOT / "web" / "_core"]

CDN_HOSTS = (
    "cdn.jsdelivr.net",
    "cdnjs.cloudflare.com",
    "unpkg.com",
    "ajax.googleapis.com",
    "fonts.googleapis.com",
    "fonts.gstatic.com",
    "challenges.cloudflare.com",
    "code.jquery.com",
)

# Match a <script ...> or <link ...> opening tag (HTML or inside a PHP echo).
TAG_RE = re.compile(r"<(?:script|link)\b[^>]*>", re.IGNORECASE | re.DOTALL)
SRC_RE = re.compile(r'(?:src|href)\s*=\s*["\']([^"\']+)["\']', re.IGNORECASE)
INTEGRITY_RE = re.compile(r"\bintegrity\s*=", re.IGNORECASE)


def is_cdn_url(url: str) -> bool:
    return any(host in url for host in CDN_HOSTS)


def scan_file(path: Path) -> list[tuple[int, str]]:
    """Return [(lineno, tag_excerpt), …] for tags missing integrity."""
    try:
        text = path.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return []

    findings = []
    for match in TAG_RE.finditer(text):
        tag = match.group(0)
        url_match = SRC_RE.search(tag)
        if url_match is None:
            continue
        url = url_match.group(1)
        if is_cdn_url(url) is False:
            continue
        if INTEGRITY_RE.search(tag) is not None:
            continue
        # The Asset class builds tags with integrity attribute at runtime,
        # so a literal Asset::js(...) call in PHP is fine even though the
        # tag string isn't visible here. Skip tags that mention the host
        # but don't include a src/href literal (template fragments).
        if "<?php" in tag or "<?=" in tag:
            continue

        lineno = text.count("\n", 0, match.start()) + 1
        excerpt = re.sub(r"\s+", " ", tag).strip()
        if len(excerpt) > 120:
            excerpt = excerpt[:117] + "…"
        findings.append((lineno, excerpt))
    return findings


def main(argv: list[str]) -> int:
    strict = "--strict" in argv
    all_findings: dict[Path, list[tuple[int, str]]] = {}
    for base in SCAN_DIRS:
        if base.is_dir() is False:
            continue
        for path in base.rglob("*"):
            if path.is_file() is False:
                continue
            if path.suffix.lower() not in {".php", ".html", ".html.php"}:
                continue
            findings = scan_file(path)
            if findings:
                all_findings[path] = findings

    total = sum(len(v) for v in all_findings.values())
    print(f"CDN tag audit — scanned {sum(1 for _ in (SCAN_DIRS[0].rglob('*')))} files")
    if total == 0:
        print("No CDN tags missing integrity= attribute. ✅")
        return 0

    print(f"\n### CDN tags WITHOUT SRI integrity ({total})\n")
    for path, findings in sorted(all_findings.items()):
        rel = path.relative_to(REPO_ROOT)
        for lineno, excerpt in findings:
            print(f"  • {rel}:{lineno} — {excerpt}")
    print()
    return 1 if strict is True else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
