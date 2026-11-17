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

This only proves a fingerprint is PRESENT, never that it is RIGHT (#568: three
pinned values were wrong and nothing noticed). tools/cdn-sri-selftest.php
downloads each file and works the fingerprint out again; it needs internet.

A SECOND RULE, FOR JAVASCRIPT FILES (#575). A script can add its own tag at run
time, and then no PHP or HTML tag shows it. The generated noticeboard bundle
does exactly that for Babel: `BABEL_URL = "https://unpkg.com/..."` and a script
element with no `integrity`. So every `<NAME>_URL = "https://<cdn host>..."` in
a `.js` file under web/ must have a `<NAME>_SRI` in the same file (as REACT_SRI
already is), except the named, reasoned list JS_EXEMPT below.

THE EXEMPTION IS CHECKED BOTH WAYS, because a list nobody re-reads goes stale
without a sound. It fails when noticeboard/index.php no longer defines
`window.Babel` in an inline script BEFORE the bundle's own tag (then the fetch is
reachable again), and it fails when the bundle gains a `BABEL_SRI` (then the
exemption is stale and must be deleted). The bundle is generated from a design
page that is not in this repository, so it cannot be fixed here; the lasting
fix is to add `BABEL_SRI` and `s.integrity = BABEL_SRI` at its source.

What this cannot see: a URL built by joining strings, or a host not in CDN_HOSTS.

Exit code:
  0 — no findings.
  1 — at least one CDN tag lacks integrity (with --strict), or a JS rule fails.

Usage:
  python3 tools/audit-checks/check_cdn_sri.py [--strict]
  python3 tools/audit-checks/check_cdn_sri.py --self-test
"""

from __future__ import annotations

import re
import sys
import tempfile
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
# web/_install is scanned too (#568): the installation wizard is the first page a
# new customer sees, and its own Bootstrap tag was never audited before.
SCAN_DIRS = [
    REPO_ROOT / "web" / "_apps",
    REPO_ROOT / "web" / "public_html",
    REPO_ROOT / "web" / "_core",
    REPO_ROOT / "web" / "_install",
]

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


# 🔐 #575 — the JavaScript rule. Key: (path under the repository, constant name).
JS_EXEMPT = {
    ("web/public_html/assets/noticeboard/noticeboard.noeval.js", "BABEL"): (
        "unreachable: window.Babel is defined first by "
        "web/_apps/noticeboard/index.php, so the bundle never adds the tag"
    ),
}
BABEL_STUB_PAGE = "web/_apps/noticeboard/index.php"
BABEL_BUNDLE_NAME = "noticeboard.noeval.js"

JS_URL_RE = re.compile(r"\b([A-Za-z0-9]+)_URL\s*=\s*[\"']https?://([^\"'/]+)", re.IGNORECASE)


def js_findings(root: Path, scan_dirs: list[Path], exempt: dict, stub_page: str) -> list[str]:
    """Return plain-English problems for the JavaScript rule."""
    problems: list[str] = []
    seen_exempt: set = set()
    for base in scan_dirs:
        if base.is_dir() is False:
            continue
        for path in sorted(base.rglob("*.js")):
            try:
                text = path.read_text(encoding="utf-8", errors="replace")
            except OSError:
                continue
            rel = path.relative_to(root).as_posix()
            for m in JS_URL_RE.finditer(text):
                name, host = m.group(1), m.group(2)
                if any(host == h or host.endswith("." + h) for h in CDN_HOSTS) is False:
                    continue
                has_sri = re.search(r"\b" + re.escape(name) + r"_SRI\b", text) is not None
                key = (rel, name.upper())
                if key in exempt:
                    seen_exempt.add(key)
                    if has_sri is True:
                        problems.append(
                            f"{rel}: {name}_URL now has a {name}_SRI, so its exemption is stale -- delete it from JS_EXEMPT"
                        )
                    continue
                if has_sri is False:
                    lineno = text.count("\n", 0, m.start()) + 1
                    problems.append(
                        f"{rel}:{lineno} -- {name}_URL points at {host} and there is no {name}_SRI fingerprint in the file"
                    )
    for key in exempt:
        if key not in seen_exempt:
            problems.append(f"JS_EXEMPT names {key[0]} {key[1]}_URL, which is no longer there -- delete the entry")
    if exempt:
        why = stub_problem(root / stub_page)
        if why is not None:
            problems.append(why)
    return problems


def stub_problem(page: Path) -> str | None:
    """None when the page defines window.Babel inline BEFORE the bundle's tag."""
    try:
        raw = page.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return f"{page.name}: cannot be read, so the Babel exemption cannot be proved"
    # PHP blocks first (their own comments and the nonce's '>' go with them), then HTML comments.
    code = re.sub(r"<\?(?:php|=).*?\?>", "", raw, flags=re.DOTALL)
    code = re.sub(r"<!--.*?-->", "", code, flags=re.DOTALL)
    bundle = re.search(r"<script\b[^>]*" + re.escape(BABEL_BUNDLE_NAME) + r"[^>]*>", code, re.IGNORECASE)
    if bundle is None:
        return f"{BABEL_STUB_PAGE}: no script tag for {BABEL_BUNDLE_NAME} found, so the Babel exemption cannot be proved"
    for tag in re.finditer(r"<script\b([^>]*)>(.*?)</script>", code, re.IGNORECASE | re.DOTALL):
        if tag.start() >= bundle.start():
            break
        if re.search(r"\bsrc\s*=", tag.group(1), re.IGNORECASE) is not None:
            continue
        body = re.sub(r"/\*.*?\*/", "", tag.group(2), flags=re.DOTALL)
        body = re.sub(r"(?m)^\s*//.*$", "", body)
        if re.search(r"\bwindow\.Babel\s*=(?!=)", body) is not None:
            return None
    return (
        f"{BABEL_STUB_PAGE}: no inline script defining window.Babel before the {BABEL_BUNDLE_NAME} tag -- "
        "the bundle could fetch Babel from an outside server with no fingerprint again (#575)"
    )


def self_test() -> int:
    """Planted cases: each must be reported, and the good states must stay green."""
    stub = '<script nonce="<?php echo $n; ?>">window.Babel = {};</script>\n'
    tag = '<script nonce="<?php echo $n; ?>" src="/a/noticeboard.noeval.js" defer></script>\n'
    page_ok = '<?php /* x */ ?><!-- window.Babel = 1; -->\n' + stub + tag
    page_after = tag + stub
    page_comment_only = (
        '<!-- window.Babel = {}; -->\n<script>// window.Babel = {};\n</script>\n'
        '<script src="/a/noticeboard.noeval.js" defer></script>'
    )
    bundle = 'var BABEL_URL = "https://unpkg.com/x.js";\n'
    cases = [
        # name, files, expected problem count (0 = must be green), needle
        ("good", {"p.php": page_ok, "b.js": bundle}, 0, ""),
        ("stub removed", {"p.php": page_ok.replace("window.Babel = {}", "window.Other = {}"), "b.js": bundle}, 1, "window.Babel"),
        ("stub after bundle", {"p.php": page_after, "b.js": bundle}, 1, "window.Babel"),
        ("stub only in comments", {"p.php": page_comment_only, "b.js": bundle}, 1, "window.Babel"),
        ("stale exemption", {"p.php": page_ok, "b.js": bundle + 'var BABEL_SRI = "sha384-x";\n'}, 1, "stale"),
        ("exempt entry gone", {"p.php": page_ok, "b.js": "var x = 1;\n"}, 1, "no longer there"),
        ("new unfingerprinted url", {"p.php": page_ok, "b.js": bundle, "c.js": 'var FOO_URL = "https://unpkg.com/foo.js";\n'}, 1, "FOO_URL"),
        ("new url with fingerprint", {"p.php": page_ok, "b.js": bundle, "c.js": 'var FOO_URL = "https://unpkg.com/foo.js"; var FOO_SRI = "sha384-y";\n'}, 0, ""),
        ("own-server url", {"p.php": page_ok, "b.js": bundle, "c.js": 'var BAR_URL = "/assets/bar.js";\n'}, 0, ""),
    ]
    failed = 0
    for name, files, want, needle in cases:
        with tempfile.TemporaryDirectory(prefix="cdn-sri-selftest-") as tmp:
            root = Path(tmp)
            (root / "js").mkdir()
            (root / "pages").mkdir()
            for fname, content in files.items():
                (root / ("pages" if fname.endswith(".php") else "js") / fname).write_text(content, encoding="utf-8")
            exempt = {("js/b.js", "BABEL"): "test"}
            found = js_findings(root, [root / "js"], exempt, "pages/p.php")
        ok = len(found) == want and all(needle in f for f in found)
        print(f"  {'ok  ' if ok else 'FAIL'} {name}: {len(found)} problem(s)" + ("" if ok else f" -- {found}"))
        failed += 0 if ok else 1
    print("self-test:", "FAILED" if failed else "all cases behaved")
    return 1 if failed else 0


def main(argv: list[str]) -> int:
    if "--self-test" in argv:
        return self_test()
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

    js_problems = js_findings(REPO_ROOT, [REPO_ROOT / "web"], JS_EXEMPT, BABEL_STUB_PAGE)
    if js_problems:
        print("\n### JavaScript outside-script rule (#575)\n")
        for problem in js_problems:
            print(f"  • {problem}")
        print()
        return 1

    total = sum(len(v) for v in all_findings.values())
    print(f"CDN tag audit — scanned {sum(1 for d in SCAN_DIRS if d.is_dir() for _ in d.rglob('*'))} files")
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
