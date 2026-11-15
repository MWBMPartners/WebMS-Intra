#!/usr/bin/env python3
"""
Every outside company the portal's code names is accounted for (#808, D-158).

Path: tools/audit-checks/check_outside_hosts_named.py

WHY THIS EXISTS
---------------
#778 put three outside services under the on/off rules (D-058, D-072):
Gravatar, the sign-in photo, and maps of homes. Its own inventory found six
more that reach another company -- what3words, the address look-up, videos
and designs shown inside a page, the shared code libraries every page fetches,
the robot check on forms, and error monitoring -- and the privacy pages named
none of them. The owner decided (D-158, 12 November 2026): name each one
truthfully on the privacy pages before the first install; put them under the
on/off rules after it.

Naming them is worth little if the NEXT outside company arrives unnoticed. So
this check reads every internet name (a "host", such as `cdn.jsdelivr.net`)
written in the CODE under web/ and requires each to be accounted for in
exactly one place:

  1. `Portal\\Core\\OutsideServices::SERVICES` -- the three that are switched
     on and off (their `imgSources`; guarded further, file by file, by
     `check_outside_services_gated.py`);
  2. `Portal\\Core\\OutsideServices::NAMED` -- the six named on the privacy
     pages and not yet switched (D-158). Their words are printed by
     `web/_core/templates/outside-services-notice.php`, so being here IS being
     on the privacy pages;
  3. `NOT_ABOUT_ANYBODY` below -- hosts in code that send nobody's details to
     an outside company when a page is viewed or a member acts: a link a
     person may choose to follow, a name inside a file format that is never
     contacted, an example address, or a company an administrator sets up to
     do a job from the server (see the question below);
  4. `WHOLE_FILES` below -- files whose hosts are not the portal's own words
     at all (a copied library, the generated noticeboard bundle, a list of
     source documents), each with its reason.

It FAILS BOTH WAYS, with no other exception list:
  - a host in code that is in none of the four -- a new outside company, the
    fault this exists for. Decide which it is and add it, on purpose; if it
    sends anything about a person, it belongs in NAMED (and so on the privacy
    pages) or under the on/off rules;
  - a host in two of the four (one host, one answer);
  - a listed host that no code names any more, or a WHOLE_FILES entry that
    no longer exists -- a list that says more than is true is how a check
    starts reading as coverage it does not give.

WHAT COUNTS AS CODE
-------------------
PHP is read by PHP's own tokeniser with comments dropped (a regular expression
cannot tell `// see https://github.com/...` from code), and HTML comments
inside a PHP file's page text are dropped too. JavaScript and CSS have their
comments removed by a small reader that steps over quoted text; SQL has `--`,
`#` and `/* */` comments removed the same way; `.htaccess` has its `#` lines
removed. Everything else that is text (JSON, SVG, HTML, XML) is read whole.
Pictures, fonts and archives are skipped by their file ending; a file of any
OTHER ending is read whole rather than skipped, so a new kind of file cannot
slip past by being new.

A host is found in two shapes: after `//` (with or without `https:`, and with
a `*.` or `{s}.` in front, as a page rule or map template writes it), and as a
quoted piece that BEGINS with a dot -- `'.cloudflarestream.com/'` -- which is
how an address is glued together around a customer's own part.

WHAT THIS CANNOT SEE, said plainly
----------------------------------
1. A host built up in other pieces (`'https://' . $host`, or
   `'cdn.' . 'jsdelivr.net'`). Matching text cannot follow a concatenation.
   An address the administrator TYPES (an error monitor's, a translation
   server's) is not in the code at all, which is why error monitoring is in
   NAMED with no hosts.
2. Whether the words in NAMED are TRUE. It checks that every company is
   listed, never what the listing says. That is a person's job at review.
3. A JavaScript regular-expression literal containing `//` or a quote can
   confuse the comment reader. The direction of that fault is safe: text it
   wrongly keeps can only add hosts, which fail loudly.

RUN IT
------
    python3 tools/audit-checks/check_outside_hosts_named.py
    python3 tools/audit-checks/check_outside_hosts_named.py --self-test

Exit 0 when clean, 1 on any problem. Needs `php` on the PATH (for the
tokeniser and to read OutsideServices' two lists) and REFUSES without it.
"""

from __future__ import annotations

import json
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
OUTSIDE = Path("_core") / "OutsideServices.php"

# 🧾 The four kinds of host that are NOT about anybody. Each entry: host ->
#    (kind, reason). A host written with a leading dot stands for itself and
#    every name ending in it.
LINK = "a link a person may choose to follow; nothing is fetched until they do"
NAME_ONLY = "a name inside a file format, never contacted"
EXAMPLE = "an example or placeholder address, not a real company"
JOB = ("a company an administrator sets up to do a job, reached from this portal's own server; "
       "D-158 did not name these and the privacy page says it does not list them (question on #808)")

NOT_ABOUT_ANYBODY: dict[str, tuple[str, str]] = {
    # --- links a person may follow -------------------------------------------------
    "github.com": (LINK, "The project's own issue links in the API description and the API guide."),
    "ico.org.uk": (LINK, "The UK data protection regulator, named on the privacy page."),
    "what3words.com": (LINK, "A place's 'open in what3words' link, and the sign-up link on its admin page."),
    "www.openstreetmap.org": (LINK, "A place's 'open in OpenStreetMap' link."),
    "dash.cloudflare.com": (LINK, "Where an administrator gets robot-check keys (admin page)."),
    "dashboard.hcaptcha.com": (LINK, "Where an administrator gets robot-check keys (admin page)."),
    "learn.microsoft.com": (LINK, "Set-up guidance on an admin page."),
    "zoom.us": (LINK, "Set-up guidance for video meetings on an admin page."),
    "www.php.net": (LINK, "A help link on a server-information page."),
    "libretranslate.com": (LINK, "The default address of a translation server an administrator may point at; "
                                 "nothing is sent unless they choose that provider (it is then a JOB)."),
    # --- names inside a file format --------------------------------------------------
    "www.w3.org": (NAME_ONLY, "XML and SVG namespace names."),
    "www.inkscape.org": (NAME_ONLY, "The drawing program's namespace name inside old logo files."),
    "www.sitemaps.org": (NAME_ONLY, "The sitemap file format's namespace name."),
    "schema.org": (NAME_ONLY, "The vocabulary named in a page's search-engine description."),
    "purl.org": (NAME_ONLY, "A feed format's namespace name."),
    "www.itunes.com": (NAME_ONLY, "The podcast feed's namespace name."),
    "schemas.openxmlformats.org": (NAME_ONLY, "A spreadsheet format's namespace name."),
    "json-schema.org": (NAME_ONLY, "The schema language a JSON schema file declares."),
    "sts.windows.net": (NAME_ONLY, "The issuer name a Microsoft sign-in token carries, compared, never fetched."),
    # --- examples --------------------------------------------------------------------
    "www.example.org": (EXAMPLE, "Example addresses in help text and placeholders."),
    "portal.example.org": (EXAMPLE, "Example addresses in help text and the redirect page."),
    "example.org": (EXAMPLE, "Example addresses."),
    "example.com": (EXAMPLE, "Example addresses."),
    "example.invalid": (EXAMPLE, "A deliberately unusable address."),
    "hymns.example.org": (EXAMPLE, "An example address on a worship page."),
    "api.cuercode.example": (EXAMPLE, "An example address."),
    "www.ourchurch.org": (EXAMPLE, "An example organisation website in a placeholder."),
    "word.word.word": (EXAMPLE, "The what3words example shape."),
    "gair.gair.gair": (EXAMPLE, "The what3words example shape, in Welsh."),
    "youtube.com": (EXAMPLE, "A placeholder showing what a pasted YouTube address looks like."),
    "youtu.be": (EXAMPLE, "Recognising a pasted short YouTube address."),
    # --- jobs an administrator sets up (server to company) ---------------------------
    "login.microsoftonline.com": (JOB, "Signing in with Microsoft."),
    "graph.microsoft.com": (JOB, "Sending e-mail through Microsoft 365."),
    "accounts.google.com": (JOB, "Signing in with Google."),
    "oauth2.googleapis.com": (JOB, "Signing in with Google and the Gmail mail route."),
    "www.googleapis.com": (JOB, "Google sign-in details and the Gmail mail route."),
    "gmail.googleapis.com": (JOB, "Sending e-mail through Google."),
    "translation.googleapis.com": (JOB, "Translating an organisation's own published words (D-049)."),
    "api.openai.com": (JOB, "Translation, and AI drafting, which no page reaches today (#808)."),
    "api.anthropic.com": (JOB, "Translation, and AI drafting, which no page reaches today (#808)."),
    "api.assemblyai.com": (JOB, "Transcribing recordings (#276)."),
    "api.twilio.com": (JOB, "Sending text messages."),
    "rest.messagebird.com": (JOB, "Sending text messages."),
    "api.stripe.com": (JOB, "Taking payments."),
    "api-m.paypal.com": (JOB, "Taking payments."),
    "api-m.sandbox.paypal.com": (JOB, "Taking test payments."),
    "api.zoom.us": (JOB, "Creating video meetings."),
    "api.cloudflare.com": (JOB, "Setting up video uploads with Cloudflare Stream."),
    ".amazonaws.com": (JOB, "Sending text messages through Amazon (its SNS service), at an address glued "
                           "together around the administrator's chosen region."),
}

# 📁 Files whose hosts are not the portal's own words. Path under web/ -> reason.
WHOLE_FILES: dict[str, str] = {
    "_core/data/giving-retention-countries.json":
        "The source documents behind each country's suggested keeping period, shown as links an "
        "administrator may follow.",
    "public_html/assets/noticeboard/noticeboard.noeval.js":
        "Generated from dc-runtime, which is not in this repository. Its unpkg.com and Google Fonts "
        "addresses are refused by the noticeboard page's rule and never reached (the page loads its own "
        "React copy first); its QR fallback is guarded by check_no_outside_qr_service.py.",
    "public_html/assets/noticeboard/noticeboard.css":
        "Generated with the noticeboard bundle. Its Google Fonts import is refused by the page rule, which "
        "allows fonts and styles from no Google host; fonts-selfhost.css supplies the faces.",
    "public_html/assets/vendor/react/react-dom-18.3.1.production.min.js":
        "A copied library; its addresses are its own error-message links and namespace names.",
    "public_html/assets/vendor/swagger-ui/swagger-ui-bundle-5.17.14.js":
        "A copied library; its addresses are examples and links. Its validator is switched off "
        "(validatorUrl: null in api-docs/index.php).",
    "public_html/assets/vendor/swagger-ui/swagger-ui-standalone-preset-5.17.14.js":
        "A copied library; its addresses are links.",
    "public_html/assets/vendor/swagger-ui/swagger-ui-5.17.14.css":
        "A copied library; its addresses are namespace names.",
    "public_html/assets/noticeboard/fonts/OFL-bricolage-grotesque.txt": "A font's licence text.",
    "public_html/assets/noticeboard/fonts/OFL-ibm-plex-mono.txt": "A font's licence text.",
    "public_html/assets/noticeboard/fonts/OFL-ibm-plex-sans.txt": "A font's licence text.",
    "public_html/assets/noticeboard/fonts/OFL-instrument-serif.txt": "A font's licence text.",
}

# Endings that are never text.
BINARY = {".png", ".gif", ".jpg", ".jpeg", ".webp", ".ico", ".icns", ".woff", ".woff2", ".ttf", ".otf",
          ".zip", ".gz", ".pdf", ".mp3", ".mp4"}

HOST_AFTER_SLASHES = re.compile(
    r"(?:https?:)?//(?:\{[a-z]+\}\.|\*\.)?([a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,})(?![a-z0-9-])", re.I)
GLUED_SUFFIX = re.compile(r"""(?<=['"])\.([a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,})(?=[/'"])""", re.I)
# A glued piece such as '.html.php' or '-run.lock' is a FILE name, not a host: its last part is a
# file ending. Measured: without this the first run reported `html.php` and `backup-run.lock`.
FILE_ENDINGS = {"php", "lock", "json", "txt", "js", "css", "sql", "html", "htm", "log", "pdf", "png", "csv",
                "ics", "xml", "zip", "tmp", "part", "bak", "md", "svg", "jpg", "gif", "webp", "enc", "gz", "tar"}

PHP_PROBE = r"""
$out = [];
foreach (array_slice($argv, 1) as $f) {
    $text = '';
    foreach (token_get_all((string) file_get_contents($f)) as $t) {
        if (is_array($t) === true) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { $text .= ' '; continue; }
            if ($t[0] === T_INLINE_HTML) { $text .= preg_replace('~<!--.*?-->~s', ' ', $t[1]); continue; }
            $text .= $t[1];
        } else {
            $text .= $t;
        }
    }
    $out[$f] = $text;
}
echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE);
"""

PHP_LISTS = r"""
require $argv[1];
$s = [];
foreach (\Portal\Core\OutsideServices::SERVICES as $k => $v) { $s[$k] = $v['imgSources']; }
$n = [];
foreach (\Portal\Core\OutsideServices::NAMED as $k => $v) { $n[$k] = $v['hosts']; }
echo json_encode(['services' => $s, 'named' => $n]);
"""


class Refused(Exception):
    """The check could not do its job, which is a failure, never a pass."""


def php() -> str:
    found = shutil.which("php")
    if found is None:
        raise Refused("php is not on the PATH, so comments cannot be told from code")
    return found


def strip_c_like(text: str, hash_comments: bool = False, dash_comments: bool = False) -> str:
    """Remove /* */ and // comments (and optionally # and -- ones), stepping over quoted text."""
    out: list[str] = []
    i, n = 0, len(text)
    quote = ""
    while i < n:
        c = text[i]
        if quote:
            out.append(c)
            if c == "\\" and i + 1 < n:
                out.append(text[i + 1])
                i += 2
                continue
            if c == quote:
                quote = ""
            i += 1
            continue
        if c in "'\"`":
            quote = c
            out.append(c)
            i += 1
            continue
        if text.startswith("/*", i):
            end = text.find("*/", i + 2)
            i = n if end < 0 else end + 2
            out.append(" ")
            continue
        line_comment = (not dash_comments and text.startswith("//", i) and (i == 0 or text[i - 1] != ":")) \
            or (hash_comments and c == "#") or (dash_comments and text.startswith("--", i))
        if line_comment:
            end = text.find("\n", i)
            i = n if end < 0 else end
            continue
        out.append(c)
        i += 1
    return "".join(out)


def code_texts(web: Path) -> dict[str, str]:
    """Every text file under web/, as code with comments removed, keyed by path under web/."""
    texts: dict[str, str] = {}
    php_files: list[Path] = []
    for path in sorted(web.rglob("*")):
        if path.is_file() is False or path.suffix.lower() in BINARY:
            continue
        rel = path.relative_to(web).as_posix()
        if rel in WHOLE_FILES:
            continue
        if path.suffix.lower() == ".php":
            php_files.append(path)
            continue
        raw = path.read_text(encoding="utf-8", errors="ignore")
        suffix = path.suffix.lower()
        if suffix in (".js", ".mjs"):
            texts[rel] = strip_c_like(raw)
        elif suffix == ".css":
            texts[rel] = re.sub(r"/\*.*?\*/", " ", raw, flags=re.S)
        elif suffix == ".sql":
            texts[rel] = strip_c_like(raw, hash_comments=True, dash_comments=True)
        elif path.name == ".htaccess":
            texts[rel] = "\n".join(line for line in raw.splitlines() if line.lstrip().startswith("#") is False)
        else:
            texts[rel] = raw
    exe = php()
    for start in range(0, len(php_files), 200):
        batch = php_files[start:start + 200]
        done = subprocess.run([exe, "-r", PHP_PROBE] + [str(p) for p in batch], capture_output=True, text=True)
        if done.returncode != 0:
            raise Refused("PHP's tokeniser could not read the PHP files: %s" % done.stderr.strip()[:400])
        for name, text in json.loads(done.stdout).items():
            texts[Path(name).relative_to(web).as_posix()] = text
    return texts


def outside_lists(web: Path) -> tuple[dict[str, list[str]], dict[str, list[str]]]:
    done = subprocess.run([php(), "-r", PHP_LISTS, str(web / OUTSIDE)], capture_output=True, text=True)
    if done.returncode != 0:
        raise Refused("OutsideServices' lists could not be read: %s" % done.stderr.strip()[:400])
    data = json.loads(done.stdout)
    services = {k: [re.sub(r"^https?://(\*\.)?", lambda m: "." if m.group(1) else "", s).rstrip("/")
                    for s in v] for k, v in data["services"].items()}
    return services, data["named"]


def covers(entry: str, host: str) -> bool:
    entry = entry.lower()
    return host == entry or (entry.startswith(".") and (host.endswith(entry) or host == entry[1:]))


def check(web: Path) -> list[str]:
    problems: list[str] = []
    services, named = outside_lists(web)
    texts = code_texts(web)

    owners: dict[str, list[str]] = {}
    for key, hosts in services.items():
        for h in hosts:
            owners.setdefault(h.lower(), []).append("OutsideServices::SERVICES['%s']" % key)
    for key, hosts in named.items():
        for h in hosts:
            owners.setdefault(h.lower(), []).append("OutsideServices::NAMED['%s']" % key)
    for h in NOT_ABOUT_ANYBODY:
        owners.setdefault(h.lower(), []).append("NOT_ABOUT_ANYBODY in this check")
    for entry, places in sorted(owners.items()):
        if len(places) > 1:
            problems.append("✗ %s is listed in %d places (%s). One host, one answer: keep it in one."
                            % (entry, len(places), ", ".join(places)))

    found: dict[str, set[str]] = {}
    for rel, text in texts.items():
        for pattern in (HOST_AFTER_SLASHES, GLUED_SUFFIX):
            for match in pattern.finditer(text):
                if pattern is GLUED_SUFFIX and match.group(1).rsplit(".", 1)[-1].lower() in FILE_ENDINGS:
                    continue
                found.setdefault(match.group(1).lower(), set()).add(rel)

    used: set[str] = set()
    for host, files in sorted(found.items()):
        matches = [e for e in owners if covers(e, host)]
        if matches == []:
            problems.append(
                "✗ %s names the outside host %s, which is in none of the lists.\n"
                "   A request to another company can tell it something about whoever is looking. Decide what "
                "it is: if it sends anything about a person, add it to OutsideServices::NAMED (which puts it on "
                "both privacy pages, D-158) or under the on/off rules (SERVICES, D-058); if it sends nobody's "
                "details, add it to NOT_ABOUT_ANYBODY here with its reason." % (", ".join(sorted(files)), host))
        used.update(matches)
    for entry in sorted(owners):
        if entry not in used:
            problems.append("✗ %s is listed (%s) but no code under web/ names it any more. Remove it on "
                            "purpose; a list that says more than is true reads as coverage."
                            % (entry, ", ".join(owners[entry])))
    for rel in sorted(WHOLE_FILES):
        if (web / rel).is_file() is False:
            problems.append("✗ WHOLE_FILES names %s, which does not exist. Remove the entry on purpose." % rel)
    for key, hosts in named.items():
        if key != "errorMonitoring" and hosts == []:
            problems.append("✗ OutsideServices::NAMED['%s'] lists no hosts, so nothing ties it to code." % key)
    return problems


# 🧪 ---------------------------------------------------------------------------
# Self-test: plant each fault in a copy of the real tree, and require the check
# to report it, and to stay green on the untouched copy.
# ---------------------------------------------------------------------------

def self_test() -> int:
    real = REPO / "web"
    failures = 0
    checks = 0

    def run(name: str, plant, expect: str | None) -> None:
        nonlocal failures, checks
        checks += 1
        with tempfile.TemporaryDirectory(prefix="p808-hosts-") as tmp:
            web = Path(tmp) / "web"
            shutil.copytree(real, web, ignore=shutil.ignore_patterns("*.png", "*.woff2", "*.zip", "*.gif"))
            plant(web)
            problems = check(web)
            joined = "\n".join(problems)
            if expect is None:
                ok = problems == []
            else:
                ok = expect in joined and len(problems) >= 1
            print(("  ✓ " if ok else "  ✗ ") + name + ("" if ok else "\n      got: " + (joined[:600] or "nothing")))
            if not ok:
                failures += 1

    def append(rel: str, text: str):
        def plant(web: Path) -> None:
            p = web / rel
            p.write_text(p.read_text(encoding="utf-8") + text, encoding="utf-8")
        return plant

    def replace(rel: str, old: str, new: str):
        def plant(web: Path) -> None:
            p = web / rel
            body = p.read_text(encoding="utf-8")
            assert body.count(old) >= 1, "plant anchor missing: " + old
            p.write_text(body.replace(old, new), encoding="utf-8")
        return plant

    print("check_outside_hosts_named self-test")
    run("S01 the untouched tree is green", lambda web: None, None)
    run("S02 a new host in PHP code fails",
        append("_apps/privacy/index.php", "<?php $x = 'https://tracker.newcompany.io/pixel.gif'; ?>\n"),
        "names the outside host tracker.newcompany.io")
    run("S03 the same host in a PHP comment does NOT fail",
        append("_apps/privacy/index.php", "<?php // https://tracker.newcompany.io/pixel.gif\n ?>\n"), None)
    run("S04 a new host in JavaScript code fails",
        append("public_html/assets/js/portal.js", "\nvar beacon = 'https://beacon.newcompany.io/x';\n"),
        "beacon.newcompany.io")
    run("S05 a host only in a JavaScript comment does NOT fail",
        append("public_html/assets/js/portal.js", "\n// https://beacon.newcompany.io/x\n"), None)
    run("S06 a protocol-relative host in page text fails",
        append("_apps/privacy/policy.php", '<script src="//cdn.newcompany.io/a.js"></script>\n'),
        "cdn.newcompany.io")
    run("S07 a glued-on suffix fails",
        append("_core/VideoEmbed.php", "\n// end\n<?php $u = 'https://x-' . $c . '.newstream.io/'; ?>\n"),
        "newstream.io")
    run("S08 a new host in a settings seed fails",
        append("_sql/full_schema.sql", "\nINSERT INTO `tblSettings` VALUES ('x', 'https://api.newcompany.io');\n"),
        "api.newcompany.io")
    run("S09 the same host in an SQL comment does NOT fail",
        append("_sql/full_schema.sql", "\n-- https://api.newcompany.io\n"), None)
    run("S10 a NAMED host whose code has gone fails",
        replace("_core/What3Words.php", "https://api.what3words.com/v3/", "https://' . 'x/v3/"),
        "api.what3words.com is listed")
    run("S11 a NAMED service that loses a host from its list fails (the code still names it)",
        replace("_core/OutsideServices.php", "'hosts'     => ['cdn.jsdelivr.net', 'cdnjs.cloudflare.com'],",
                "'hosts'     => ['cdnjs.cloudflare.com'],"),
        "names the outside host cdn.jsdelivr.net")
    run("S12 a host listed in two places fails",
        replace("_core/OutsideServices.php", "'hosts'     => ['api.what3words.com'],",
                "'hosts'     => ['api.what3words.com', 'github.com'],"),
        "github.com is listed in 2 places")
    run("S13 a WHOLE_FILES entry that no longer exists fails",
        lambda web: (web / "public_html/assets/noticeboard/fonts/OFL-ibm-plex-mono.txt").unlink(),
        "OFL-ibm-plex-mono.txt, which does not exist")
    run("S14 a switched (SERVICES) host that loses its entry fails where the code still names it",
        replace("_core/OutsideServices.php", "'imgSources' => ['https://www.gravatar.com'],",
                "'imgSources' => ['https://www.gravatar.invalid'],"),
        "names the outside host www.gravatar.com")
    run("S16 a host inside a WHOLE_FILES file does NOT fail",
        append("_core/data/giving-retention-countries.json", '\n"https://source.newcompany.io/a"\n'), None)
    run("S17 a glued file name such as '.html.php' is NOT read as a host",
        append("_apps/privacy/index.php", "<?php $f = $base . '.backup.json'; ?>\n"), None)
    run("S15 a new kind of file is read, not skipped",
        lambda web: (web / "public_html" / "x.webmanifest").write_text('{"u":"https://t.newcompany.io"}'),
        "t.newcompany.io")
    print("%d of %d self-test cases passed" % (checks - failures, checks))
    return 0 if failures == 0 else 1


def main() -> int:
    if len(sys.argv) > 1 and sys.argv[1] == "--self-test":
        try:
            return self_test()
        except Refused as err:
            print("REFUSED: %s. Nothing was checked, and this run fails." % err, file=sys.stderr)
            return 1
    web = REPO / "web"
    if web.is_dir() is False:
        print("✗ cannot find the web/ folder at %s" % web, file=sys.stderr)
        return 1
    try:
        problems = check(web)
    except Refused as err:
        print("REFUSED: %s. Nothing was checked, and this run fails." % err, file=sys.stderr)
        return 1
    if problems:
        print("\n".join(problems), file=sys.stderr)
        print("\n%d problem(s). The rule is #808 (D-158): every outside company the code names is on the "
              "privacy pages, under the on/off rules, or listed here as sending nobody's details." % len(problems),
              file=sys.stderr)
        return 1
    services, named = outside_lists(web)
    print("✓ Every outside host in code is accounted for: %d switched (SERVICES), %d named on the privacy "
          "pages (NAMED, D-158), %d sending nobody's details, %d whole files explained. (It cannot judge "
          "whether the privacy-page words are true; a person does that.)"
          % (sum(len(v) for v in services.values()), sum(len(v) for v in named.values()),
             len(NOT_ABOUT_ANYBODY), len(WHOLE_FILES)))
    return 0


if __name__ == "__main__":
    sys.exit(main())
