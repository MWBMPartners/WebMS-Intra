#!/usr/bin/env python3
"""
Every outside company the portal's code names is accounted for (#808; D-158, D-195).

Path: tools/audit-checks/check_outside_hosts_named.py

WHY THIS EXISTS
---------------
#778 put three outside services under the on/off rules (D-058, D-072):
Gravatar, the sign-in photo, and maps of homes. Its own inventory found more
that reach another company, and the privacy pages named none of them. The
owner decided (D-158): name each one truthfully on the privacy pages before the
first install, and put them under the on/off rules after it (#1085); and
(D-195) list the companies an organisation may use to do a job for it as a
second list, "only if this organisation uses it", checked by this same gate.

Naming them is worth little if the NEXT outside company arrives unnoticed. So
this check reads every internet name (a "host", such as `cdn.jsdelivr.net`)
written in the CODE under web/ and requires each to be accounted for:

  1. `OutsideServices::SERVICES` -- the three switched on and off (guarded
     further by `check_outside_services_gated.py`);
  2. `OutsideServices::NAMED` -- named on the privacy pages, no switch yet;
  3. `OutsideServices::USED_BY_ORGANISATION` -- the second list (D-195);
  4. `NOT_ABOUT_ANYBODY` below -- a link a person may choose to follow, a name
     inside a file format that is never contacted, or an example address;
  5. `WHOLE_FILES` below -- files whose hosts are not the portal's own words.

Lists 1-3 are printed on both privacy pages by
`web/_core/templates/outside-services-notice.php`, so being in one of them IS
being on the privacy pages.

THE RULES, each failing on its own
----------------------------------
A. A host in code that is in none of the lists -- a new outside company.
B. WHERE A HOST MAY BE WRITTEN, FILE BY FILE, WITH A COUNT (`PER_FILE`), for
   every host in lists 1-3 and every LINK: a use in a file not listed, or more
   uses than listed, fails (a new use of a known company -- a second Google
   script on another page -- needs a look at whether the privacy pages still
   say what is true); fewer fails too. Examples and file-format names are not
   counted, because they send nothing to anybody; rule C still holds them.
C. A host in a place a BROWSER LOADS BY ITSELF -- a `src=`, a `<link href=`,
   a CSS `url(` or `@import`, a page-rule `*-src` source or `$csp...Extra`, a
   script's `.src =`, `fetch(`, `loadScript(` or `importScripts(` -- must be
   owned by an entry the browser contacts: SERVICES, or a NAMED or
   USED_BY_ORGANISATION entry whose `from` is `browser` or `both`. A link, an
   example, a file-format name, or a company the privacy pages say only the
   SERVER contacts, fails there.
D. One host, one KIND of answer: a host may be in several of lists 1-3 (the
   same company can do two jobs), but never in those and NOT_ABOUT_ANYBODY.
E. Both ways: every listed host, every PER_FILE line and every WHOLE_FILES
   entry must still be true. A host in lists 2-3 must be named somewhere
   OTHER than OutsideServices.php, because its own list naming it proves
   nothing.

WHAT COUNTS AS CODE, AND THE SHAPES FOUND
-----------------------------------------
PHP is read by PHP's own tokeniser with comments dropped, and HTML comments in
a PHP file's page text dropped too. JavaScript and CSS have their comments
removed by a small reader that steps over quoted text; SQL has `--`, `#` and
`/* */` comments removed the same way; `.htaccess` has its `#` lines removed;
everything else that is text (JSON, SVG, HTML, XML) is read whole. Pictures,
fonts and archives are skipped by their ending; a file of any OTHER ending is
read whole, so a new kind of file cannot slip past by being new. Text is
compared without regard to capitals, and a name may hold accented letters.

A host is found in four shapes:
  - after `//`, `\\/\\/` (JavaScript and JSON escaping) or `%2F%2F` (an address
    inside another address), with or without the scheme, and with `*.`,
    `{s}.`, `{$x}.` or `${x}.` in front; and, with `http://` or `https://`
    in front, an internet-number address or a punycode name (round 7);
  - as a quoted piece that BEGINS with a dot (`'.cloudflarestream.com/'`),
    which is how an address is glued together around somebody's own part,
    unless its last part is a file ending (`.html.php`);
  - as a whole quoted string that is a host name, or a list of them separated
    by commas (`'api-free.deepl.com'`, a seeded allow-list), when its last part
    is a real top-level name from `REAL_TOP_LEVEL` -- needed because settings
    keys (`privacy.outside.gravatar`) look like host names too. Single,
    double and backtick quotes all count;
  - as a source in a PAGE-RULE LIST, with or without `https://`: every word
    after a `*-src` directive up to its `;`, and the value given to a
    `$csp...Extra` variable. Round 2 of the check planted `cdn.newtracker.io`
    in header.php's `script-src` and `*.tiles.newmaps.io` in an event page's
    `$cspImgExtra`; both told every browser to load from a new host and both
    passed green, because neither had a `//`.

WHAT THIS CANNOT SEE, said plainly
----------------------------------
1. A host built up in other pieces: `'https://' . $host` where `$host` comes
   from elsewhere, `'api.' . 'deepl.com'`, or a name in a quoted string that
   ends in a top-level name not in REAL_TOP_LEVEL. An address an
   administrator TYPES (a mail server, an error monitor, a translation server)
   is not in the code at all.
2. A browser load the context rule (C) does not recognise -- an address held
   in a variable and used as a `src` lines later, or a load written in a shape
   not in LOAD_CONTEXT. Rule B still counts the host where it is written.
3. Whether the words on the privacy pages are TRUE. It checks that every
   company is listed and who is said to contact it, never what the listing
   says it receives. That is a person's job at review.
4. A JavaScript regular-expression literal containing `//` or a quote can
   confuse the comment reader. Text it wrongly keeps can only add hosts, which
   fail loudly.
5. Measured gaps that stay green, each recorded rather than closed: a bare
   host name in a quoted string ending in a top-level name not in
   REAL_TOP_LEVEL (`'pixel.tracker.xyz'` -- a host written with `//` is read
   whatever its ending); a host inside a base64 `data:` address, or written
   in ANY encoding -- percent-encoding included: only a percent-encoded
   `://` in front of the host is read, never percent-encoded characters in
   the host itself (`https://tracker%2eio/`, `https://198%2e51%2e100%2e7/`);
   a page rule built in pieces
   (`'img-' . 'src ' . $x`); an internet-number address (an "IP address")
   written as a bare string with no `//` in front; and a punycode name, or
   an address written as one large number, with no `http://` or `https://`
   in front. (Round 8 closed `//198.51.100.7/` and `https://3325256711/`;
   round 9 replaced the list of number spellings with one rule, NUM_HOST, which
   reads every NUMBER spelling of a host -- but only after `//`, see below.)
   Also unseen: a name with no dot at all (`https://intranet/`), which a
   browser looks up like any other name.
   THE WHOLE CLASS, stated once rather than closed spelling by spelling
   (D-065, round 10): this reads an address only where `//` (plain, escaped
   as `\\/\\/`, or percent-encoded as `%2f%2f`) comes DIRECTLY before the
   host. The URL standard accepts many other spellings and a browser
   fetches them; none is read here. Measured green in round 10, and really
   fetched by Edge: backslashes for slashes (`https:\\\\198.51.100.7\\p.gif`,
   `https:\\\\tracker.io\\p.gif`, `\\\\tracker.io/p.gif`), a mixed pair
   (`https:/\\tracker.io/p.gif`), a tab after the `//` or a line break inside
   the host (the standard strips both), and percent-encoded characters in
   the host (above). Measured green and read as an address by node's URL
   reader: `http:tracker.io/p.gif`, with no slashes at all. Full-width dots
   are NOT claimed: Edge did not fetch them. Also not a load place here: an
   address in `srcset`; and a false red: `//300/400` in a script comment the
   comment reader keeps (see 4) reads as a number address.
6. Round 7 of the check planted `<img src="https://198.51.100.7/pixel.gif">`
   and `https://xn--80ak6aa92e.xn--p1ai/p.gif` and both passed green, because
   the top-level-name rule wanted letters only. Both are read now (the
   ADDRESS_LITERAL shape below), when written with a scheme. An address in a
   range kept for documentation or for the machine itself (127.x, 10.x, ::1,
   192.0.2.x, 198.51.100.x, 203.0.113.x) gets NO automatic pass: a browser
   still tries to load it, and a test address that ships is a fault, so it
   must be listed like any other host.

RUN IT
------
    python3 tools/audit-checks/check_outside_hosts_named.py
    python3 tools/audit-checks/check_outside_hosts_named.py --self-test

Exit 0 when clean, 1 on any problem. Needs `php` on the PATH (for the
tokeniser and to read OutsideServices' lists) and REFUSES without it.
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
OUTSIDE = "_core/OutsideServices.php"

LINK = "link"
NAME_ONLY = "name-only"
EXAMPLE = "example"

# 🧾 Hosts in code that send nobody's details. host -> (kind, reason). A link
#    is counted file by file (rule B); every kind is refused where a browser
#    loads by itself (rule C).
NOT_ABOUT_ANYBODY: dict[str, tuple[str, str]] = {
    # --- links a person may choose to follow -------------------------------------
    "github.com": (LINK, "The project's own issue links in the API description and the API guide."),
    "ico.org.uk": (LINK, "The UK data protection regulator, named on the privacy page."),
    "what3words.com": (LINK, "A place's 'open in what3words' link, and the sign-up link on its admin page."),
    "www.openstreetmap.org": (LINK, "A place's 'open in OpenStreetMap' link."),
    "dash.cloudflare.com": (LINK, "Where an administrator gets robot-check keys (admin page)."),
    "dashboard.hcaptcha.com": (LINK, "Where an administrator gets robot-check keys (admin page)."),
    "learn.microsoft.com": (LINK, "Set-up guidance on an admin page."),
    "www.php.net": (LINK, "A help link on a server-information page."),
    # --- names inside a file format ----------------------------------------------
    "www.w3.org": (NAME_ONLY, "XML and SVG namespace names."),
    "www.inkscape.org": (NAME_ONLY, "The drawing program's namespace name inside old logo files."),
    "www.sitemaps.org": (NAME_ONLY, "The sitemap file format's namespace name."),
    "schema.org": (NAME_ONLY, "The vocabulary named in a page's search-engine description."),
    "purl.org": (NAME_ONLY, "A feed format's namespace name."),
    "www.ecb.int": (NAME_ONLY, "The exchange-rate file format's namespace name (ExchangeRates::ECB_NAMESPACE), compared, never fetched."),
    "www.itunes.com": (NAME_ONLY, "The podcast feed's namespace name."),
    "schemas.openxmlformats.org": (NAME_ONLY, "A spreadsheet format's namespace name."),
    "json-schema.org": (NAME_ONLY, "The schema language a JSON schema file declares."),
    "sts.windows.net": (NAME_ONLY, "The issuer name a Microsoft sign-in token carries, compared, never fetched."),
    # --- examples ----------------------------------------------------------------
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
}

# 📁 Files whose hosts are not the portal's own words. Path under web/ -> reason.
# ⌨️ Entries whose company is at an address somebody TYPES (a setting), so no
#    host is in the code and none is listed. Each is still printed on the
#    privacy pages. Fails both ways: an entry here that lists hosts, and an
#    entry with no hosts that is not here.
TYPED_ADDRESS_ONLY = {"errorMonitoring", "webhooks", "offsiteBackup", "qrTracking"}

WHOLE_FILES: dict[str, str] = {
    "_core/data/giving-retention-countries.json":
        "The source documents behind each country's suggested keeping period, shown as links an "
        "administrator may follow.",
    "public_html/assets/noticeboard/noticeboard.noeval.js":
        "Generated from dc-runtime, which is not in this repository. Its unpkg.com React addresses are never "
        "reached (the page loads its own React copy first). Its Google Fonts STYLESHEET is refused by the page "
        "rule, but its two <link rel=\"preconnect\"> tags ARE acted on: measured in headless Edge (#808 round 8), "
        "the browser looks up and connects to fonts.googleapis.com and fonts.gstatic.com. So those two hosts "
        "are NAMED (OutsideServices::NAMED['fontConnection']) and on the privacy pages, and rule E finds them "
        "in this file. Its QR fallback is guarded by check_no_outside_qr_service.py.",
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

# 📍 Rule B: host entry -> {file under web/: number of times it is written there}.
#    Change a number on purpose, after checking the privacy pages still say
#    what is true. (Measured on 16 November 2026 with this check's own reader.)
PER_FILE: dict[str, dict[str, int]] = {
    '.amazonaws.com': {
        '_core/OutsideServices.php': 1,
        '_core/Sms.php': 1,
    },
    '.cloudflarestream.com': {
        '_core/OutsideServices.php': 1,
        '_core/VideoEmbed.php': 2,
    },
    '.googleusercontent.com': {
        '_core/OutsideServices.php': 2,
    },
    '.tile.openstreetmap.org': {
        '_apps/admin/settings/organisation/index.php': 1,
        '_apps/venues/venue.php': 1,
        '_core/OutsideServices.php': 2,
        '_core/partials/location-map-assets.php': 1,
    },
    'accounts.google.com': {
        '_core/Auth.php': 3,
        '_core/OutsideServices.php': 1,
    },
    'api-free.deepl.com': {
        '_core/OutsideServices.php': 1,
        '_core/Translation.php': 1,
    },
    'api-m.paypal.com': {
        '_core/OutsideServices.php': 1,
        '_core/Payments.php': 1,
    },
    'api-m.sandbox.paypal.com': {
        '_core/OutsideServices.php': 1,
        '_core/Payments.php': 1,
    },
    'api.anthropic.com': {
        '_core/AiAssistant.php': 1,
        '_core/OutsideServices.php': 1,
        '_core/Translation.php': 1,
    },
    'api.assemblyai.com': {
        '_core/OutsideServices.php': 1,
        '_core/Transcription.php': 3,
    },
    'api.cloudflare.com': {
        '_core/CloudflareStream.php': 1,
        '_core/OutsideServices.php': 1,
    },
    'api.deepl.com': {
        '_core/OutsideServices.php': 1,
        '_core/Translation.php': 1,
    },
    'api.openai.com': {
        '_core/AiAssistant.php': 1,
        '_core/OutsideServices.php': 2,
        '_core/Transcription.php': 1,
        '_core/Translation.php': 1,
    },
    'api.stripe.com': {
        '_core/OutsideServices.php': 1,
        '_core/Payments.php': 2,
    },
    'api.twilio.com': {
        '_core/OutsideServices.php': 1,
        '_core/Sms.php': 1,
    },
    'api.what3words.com': {
        '_core/OutsideServices.php': 1,
        '_core/What3Words.php': 1,
    },
    'api.zoom.us': {
        '_core/OutsideServices.php': 1,
        '_core/Zoom.php': 1,
    },
    'cdn.jsdelivr.net': {
        '_core/Asset.php': 8,
        '_core/OutsideServices.php': 1,
        '_core/partials/location-map-assets.php': 2,
        '_core/templates/header.php': 2,
        '_install/csp.php': 1,
        '_install/index.php': 1,
        'public_html/api-docs/index.php': 1,
    },
    'cdnjs.cloudflare.com': {
        '_core/Asset.php': 1,
        '_core/OutsideServices.php': 1,
        '_core/templates/header.php': 2,
    },
    'challenges.cloudflare.com': {
        '_core/Captcha.php': 2,
        '_core/OutsideServices.php': 1,
        '_core/templates/header.php': 2,
    },
    'dash.cloudflare.com': {
        '_apps/admin/captcha/index.php': 1,
    },
    'dashboard.hcaptcha.com': {
        '_apps/admin/captcha/index.php': 1,
    },
    'fonts.googleapis.com': {
        '_core/OutsideServices.php': 1,
    },
    'fonts.gstatic.com': {
        '_core/OutsideServices.php': 1,
    },
    'fcm.googleapis.com': {
        '_core/OutsideServices.php': 1,
        '_sql/177_web_push_sender.sql': 2,
        '_sql/full_schema.sql': 2,
    },
    'github.com': {
        '_apps/admin/live/chat.php': 1,
        '_apps/admin/settings/qr/index.php': 1,
        '_apps/help/disaster-recovery.php': 3,
        '_core/api-spec.json': 1,
        '_core/api-spec.schema.json': 1,
    },
    'gmail.googleapis.com': {
        '_core/MailerGoogle.php': 1,
        '_core/OutsideServices.php': 1,
    },
    'graph.microsoft.com': {
        '_apps/admin/integrations/index.php': 1,
        '_core/Mailer.php': 2,
        '_core/OutsideServices.php': 1,
    },
    'hcaptcha.com': {
        '_core/Captcha.php': 3,
        '_core/OutsideServices.php': 1,
    },
    'ico.org.uk': {
        '_apps/privacy/index.php': 1,
    },
    'js.hcaptcha.com': {
        '_core/Captcha.php': 1,
        '_core/OutsideServices.php': 1,
    },
    'learn.microsoft.com': {
        '_apps/admin/integrations/index.php': 1,
    },
    'libretranslate.com': {
        '_apps/admin/translation/save.php': 1,
        '_core/OutsideServices.php': 1,
        '_core/Translation.php': 2,
        '_sql/099_translation.sql': 2,
        '_sql/full_schema.sql': 2,
    },
    'login.microsoftonline.com': {
        '_apps/admin/integrations/index.php': 1,
        '_core/Auth.php': 4,
        '_core/Mailer.php': 1,
        '_core/OutsideServices.php': 2,
    },
    'maps.googleapis.com': {
        '_core/Geocoder.php': 1,
        '_core/OutsideServices.php': 1,
    },
    'nominatim.openstreetmap.org': {
        '_core/Geocoder.php': 1,
        '_core/OutsideServices.php': 1,
    },
    'notify.windows.com': {
        '_core/OutsideServices.php': 1,
        '_sql/177_web_push_sender.sql': 2,
        '_sql/full_schema.sql': 2,
    },
    'oauth2.googleapis.com': {
        '_core/Auth.php': 1,
        '_core/MailerGoogle.php': 2,
        '_core/OutsideServices.php': 2,
    },
    'player.twitch.tv': {
        '_core/Livestream.php': 1,
        '_core/OutsideServices.php': 1,
    },
    'player.vimeo.com': {
        '_core/Livestream.php': 1,
        '_core/OutsideServices.php': 1,
        '_core/VideoEmbed.php': 2,
    },
    'push.apple.com': {
        '_core/OutsideServices.php': 1,
        '_sql/177_web_push_sender.sql': 2,
        '_sql/full_schema.sql': 2,
    },
    'push.services.mozilla.com': {
        '_core/OutsideServices.php': 1,
        '_sql/177_web_push_sender.sql': 2,
        '_sql/full_schema.sql': 2,
    },
    'recaptcha.google.com': {
        '_core/Captcha.php': 1,
        '_core/OutsideServices.php': 1,
    },
    'rest.messagebird.com': {
        '_core/OutsideServices.php': 1,
        '_core/Sms.php': 1,
    },
    'translation.googleapis.com': {
        '_core/OutsideServices.php': 1,
        '_core/Translation.php': 1,
    },
    'upload.cloudflarestream.com': {
        '_apps/calendar/event-hub.php': 1,
        '_core/OutsideServices.php': 1,
        'public_html/assets/js/event-hub-upload.js': 1,
    },
    'upload.videodelivery.net': {
        '_apps/calendar/event-hub.php': 1,
        '_core/OutsideServices.php': 1,
        'public_html/assets/js/event-hub-upload.js': 1,
    },
    'what3words.com': {
        '_apps/admin/integrations/what3words/index.php': 1,
        '_core/GeoLocation.php': 1,
    },
    'www.canva.com': {
        '_apps/noticeboard/foyer.php': 1,
        '_apps/noticeboard/index.php': 1,
        '_core/OutsideServices.php': 1,
        '_core/PublicDoor.php': 1,
        '_core/api-spec.json': 1,
        '_core/data/public-door-posters.schema.json': 1,
    },
    'www.ecb.europa.eu': {
        '_core/ExchangeRates.php': 1,
        '_core/OutsideServices.php': 1,
    },
    'www.facebook.com': {
        '_core/Livestream.php': 2,
        '_core/OutsideServices.php': 1,
    },
    'www.google.com': {
        '_apps/admin/captcha/index.php': 1,
        '_core/Captcha.php': 5,
        '_core/GeoLocation.php': 2,
        '_core/OutsideServices.php': 1,
    },
    'www.gstatic.com': {
        '_core/Captcha.php': 1,
        '_core/OutsideServices.php': 1,
    },
    'www.googleapis.com': {
        '_apps/admin/integrations/index.php': 1,
        '_core/Auth.php': 1,
        '_core/MailerGoogle.php': 1,
        '_core/OutsideServices.php': 1,
    },
    'www.gravatar.com': {
        '_core/OutsideServices.php': 2,
    },
    'www.openstreetmap.org': {
        '_core/GeoLocation.php': 1,
    },
    'www.php.net': {
        '_core/PhpRuntime.php': 2,
    },
    'www.youtube-nocookie.com': {
        '_core/OutsideServices.php': 1,
        '_core/VideoEmbed.php': 2,
    },
    'www.youtube.com': {
        '_core/Livestream.php': 2,
        '_core/OutsideServices.php': 1,
    },
    'zoom.us': {
        '_core/OutsideServices.php': 1,
        '_core/Zoom.php': 2,
    },
}

# Endings that are never text.
BINARY = {".png", ".gif", ".jpg", ".jpeg", ".webp", ".ico", ".icns", ".woff", ".woff2", ".ttf", ".otf",
          ".zip", ".gz", ".pdf", ".mp3", ".mp4"}

# A glued piece whose last part is one of these is a FILE name, not a host.
# Measured: without it the first run reported `html.php` and `backup-run.lock`.
FILE_ENDINGS = {"php", "lock", "json", "txt", "js", "css", "sql", "html", "htm", "log", "pdf", "png", "csv",
                "ics", "xml", "zip", "tmp", "part", "bak", "md", "svg", "jpg", "gif", "webp", "enc", "gz", "tar"}

# A whole quoted string is read as a host only when it ends in one of these.
REAL_TOP_LEVEL = {"com", "net", "org", "io", "dev", "app", "cloud", "co", "us", "uk", "eu", "tv", "me", "info",
                  "biz", "ai", "gov", "edu", "int", "de", "fr", "nl", "be", "ch", "at", "ie", "au", "nz", "ca",
                  "za", "in", "jp", "invalid", "example"}

LABEL = r"[^\W_](?:[\w-]*[^\W_])?"
TOP = r"[^\W\d_]{2,}"
HOST_AFTER_SLASHES = re.compile(
    r"(?:https?(?::|%3a))?(?://|\\/\\/|%2f%2f)(?:\{[^}\s]*\}\.|\$\{[^}\s]*\}\.|\*\.)?"
    r"((?:" + LABEL + r"\.)+" + TOP + r")(?![\w-])")
# Round 7: an address written as an internet number (IPv4, or IPv6 in square
# brackets) or with a punycode label (`xn--`, how a name in another alphabet is
# spelt on the wire), which the top-level-name rule above cannot match.
#
# Round 9: a browser reads an internet number in MANY spellings, all meaning the
# same machine: `198.51.100.7`, one large number `3325256711`, hexadecimal
# `0xc6336407`, octal `0306.051.0144.07`, shortened `198.51.25607`, with a
# trailing dot, a port, or a user name in front (`u:p@`). Rounds 7 and 8 closed
# the spellings each check handed over, and round 9 handed over four more. So
# the rule is no longer a list of spellings: a host is read as an internet
# number when it is made ONLY of number-like parts -- each part either digits
# (decimal or, with a leading zero, octal) or `0x` and hex digits -- one to four
# parts joined by dots, optionally ending in a dot. A part of letters alone
# (`cafe`, `deadbeef`) is NOT number-like, because a browser does not read it
# as a number without `0x`, so ordinary names are untouched.
#   NUM_PART   one number-like part
#   NUM_HOST   one to four parts
#   USERINFO   a user name (and password) before an `@`
#   PORT       `:` and digits after the host
# Each piece is a name of its own so the self-test can switch each off and show
# the case it handles go red.
NUM_PART = r"(?:0x[0-9a-f]*|\d+)"
NUM_HOST = NUM_PART + r"(?:\." + NUM_PART + r"){0,3}"
USERINFO = r"(?:[^@/\s'\"`<>]+@)?"
PORT = r"(?::\d+)?"
TRAILING_DOT = r"\.?"
# Round 10: a `)` also ends an address, because CSS writes `url(https://198.51.100.7)` and
# `url(//198.51.100.7)` with nothing after the host, and CSS is a place the browser loads by
# itself. Measured: both passed green before; Edge fetched the second. A name of its own so the
# self-test can switch it off. Cost: page text like `(see //1.2.3)` would now read as an address.
CSS_URL_END = r")"
ADDRESS_LITERAL = re.compile(
    r"https?(?::|%3a)(?://|\\/\\/|%2f%2f)" + USERINFO
    + r"(\[[0-9a-f:.]+\]|" + NUM_HOST + r")" + TRAILING_DOT + PORT + r"(?=[/?#'\"`\s<>\\" + CSS_URL_END + r"]|$)"
    + r"|https?(?::|%3a)(?://|\\/\\/|%2f%2f)" + USERINFO
    + r"((?:[\w-]+\.)*xn--[\w-]+(?:\.[\w-]+)*)(?![\w.-])")
# Round 8: the same internet-number address with NO scheme (`//198.51.100.7/`), which a page loads as
# readily. Only when what follows is a path, a port, a query or a closing quote, so a version number in
# prose (`//1.2.3.4 notes`) is not read as an address.
ADDRESS_LITERAL_NO_SCHEME = re.compile(
    r"(?<![\w:%])(?://|\\/\\/)" + USERINFO + r"(\[[0-9a-f:.]+\]|" + NUM_HOST + r")" + TRAILING_DOT + PORT
    + r"(?=[/?#'\"`" + CSS_URL_END + r"])")
GLUED_SUFFIX = re.compile(r"(?<=['\"`])\.((?:" + LABEL + r"\.)+" + TOP + r")(?=[/'\"`])")
WHOLE_HOSTS = re.compile(r"^\s*((?:" + LABEL + r"\.)+" + TOP + r")(?:\s*,\s*(?:" + LABEL + r"\.)+" + TOP + r")*\s*$")
QUOTED = re.compile(r"'([^'\\\n]*)'|\"([^\"\\\n]*)\"|`([^`\\]*)`")

# Rule C: the text just before an address, on the same line, that means a
# browser fetches it by itself.
LOAD_CONTEXT = re.compile(
    r"(?:\bsrc\s*[=:]\s*\\?[\"'`]?|\bposter\s*=\s*\\?[\"'`]?|<link\b[^>]*\bhref\s*=\s*\\?[\"'`]?"
    r"|\burl\(\s*[\"']?|@import\s+(?:url\()?\s*[\"']?|\b[a-z-]+-src\b[^;\"]*|\$csp\w*extra\s*\.?=\s*[^;]*"
    r"|\bfetch\(\s*[\"'`]?|\bloadscript\(\s*[\"'`]?|\bimportscripts\(\s*[\"'`]?|\bsendbeacon\(\s*[\"'`]?"
    r"|\bnew\s+(?:websocket|eventsource|image)\(\s*[\"'`]?|\.open\(\s*[\"'`][a-z]+[\"'`]\s*,\s*[\"'`]?)[^\s\"'`<>]*$")

# Rule C, second half (round 2 of the check): a PAGE-RULE LIST names hosts with
# no `https://` at all -- `script-src 'self' cdn.newtracker.io` and
# `$cspImgExtra = '*.tiles.newmaps.io'` both tell every browser to load from a
# host that the `//` shape never sees. So every source in such a list is read as
# a host of its own: the words after a `*-src` directive up to its `;`, and the
# value given to a `$csp...Extra` variable.
CSP_DIRECTIVE = re.compile(r"\b[a-z-]+-src\s+([^;\"]*)")
CSP_EXTRA = re.compile(r"\$csp\w*extra\s*\.?=\s*(['\"])(.*?)\1")
CSP_SOURCE_HOST = re.compile(r"^(?:\*\.)?((?:" + LABEL + r"\.)+" + TOP + r")(?::\d+)?(?:/\S*)?$")

PHP_PROBE = r"""
$out = [];
foreach (array_slice($argv, 1) as $f) {
    $text = '';
    $strings = [];
    foreach (token_get_all((string) file_get_contents($f)) as $t) {
        if (is_array($t) === true) {
            // A comment becomes its own line breaks (or one space), so every later
            // line number stays true (#808 round 3: a plain space shifted them).
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { $nl = substr_count($t[1], "\n"); $text .= $nl > 0 ? str_repeat("\n", $nl) : ' '; continue; }
            if ($t[0] === T_INLINE_HTML) { $text .= preg_replace_callback('~<!--.*?-->~s', static fn ($m) => str_repeat("\n", substr_count($m[0], "\n")) . ' ', $t[1]); continue; }
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) { $strings[] = [substr($t[1], 1, -1), $t[2]]; }
            if ($t[0] === T_ENCAPSED_AND_WHITESPACE) { $strings[] = [$t[1], $t[2]]; }
            $text .= $t[1];
        } else {
            $text .= $t;
        }
    }
    $out[$f] = ['code' => $text, 'strings' => $strings];
}
echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE);
"""

PHP_LISTS = r"""
require $argv[1];
$s = [];
foreach (\Portal\Core\OutsideServices::SERVICES as $k => $v) { $s[$k] = ['hosts' => $v['imgSources'], 'from' => 'browser']; }
$n = [];
foreach (\Portal\Core\OutsideServices::NAMED as $k => $v) { $n[$k] = ['hosts' => $v['hosts'], 'from' => $v['from']]; }
$o = [];
foreach (\Portal\Core\OutsideServices::USED_BY_ORGANISATION as $k => $v) { $o[$k] = ['hosts' => $v['hosts'], 'from' => $v['from']]; }
echo json_encode(['SERVICES' => $s, 'NAMED' => $n, 'USED_BY_ORGANISATION' => $o]);
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
            if c == quote or (c == "\n" and quote != "`"):
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
            stop = n if end < 0 else end + 2
            out.append("\n" * text.count("\n", i, stop) + " ")
            i = stop
            continue
        line_comment = (not dash_comments and text.startswith("//", i) and (i == 0 or text[i - 1] not in ":\\"))
        line_comment = line_comment or (hash_comments and c == "#") or (dash_comments and text.startswith("--", i))
        if line_comment:
            end = text.find("\n", i)
            i = n if end < 0 else end
            continue
        out.append(c)
        i += 1
    return "".join(out)


def code_texts(web: Path) -> dict[str, tuple[str, list[str]]]:
    """Every text file under web/: (code with comments removed, its quoted strings)."""
    texts: dict[str, tuple[str, list[str]]] = {}
    php_files: list[Path] = []
    for path in sorted(web.rglob("*")):
        if path.is_file() is False or path.suffix.lower() in BINARY:
            continue
        rel = path.relative_to(web).as_posix()
        if rel in WHOLE_FILES:
            continue
        suffix = path.suffix.lower()
        if suffix == ".php":
            php_files.append(path)
            continue
        raw = path.read_text(encoding="utf-8", errors="ignore")
        if suffix in (".js", ".mjs"):
            code = strip_c_like(raw)
        elif suffix == ".css":
            code = re.sub(r"/\*.*?\*/", lambda m: "\n" * m.group(0).count("\n") + " ", raw, flags=re.S)
        elif suffix == ".sql":
            code = strip_c_like(raw, hash_comments=True, dash_comments=True)
        elif path.name == ".htaccess":
            code = "\n".join("" if line.lstrip().startswith("#") else line for line in raw.splitlines())
        else:
            code = raw
        strings = [(m.group(1) or m.group(2) or m.group(3) or "", code.count("\n", 0, m.start()) + 1)
                   for m in QUOTED.finditer(code)]
        texts[rel] = (code, strings)
    exe = php()
    for start in range(0, len(php_files), 200):
        batch = php_files[start:start + 200]
        done = subprocess.run([exe, "-r", PHP_PROBE] + [str(p) for p in batch], capture_output=True, text=True)
        if done.returncode != 0:
            raise Refused("PHP's tokeniser could not read the PHP files: %s" % done.stderr.strip()[:400])
        for name, got in json.loads(done.stdout).items():
            texts[Path(name).relative_to(web).as_posix()] = (got["code"], got["strings"])
    return texts


def outside_lists(web: Path) -> dict[str, dict[str, dict]]:
    done = subprocess.run([php(), "-r", PHP_LISTS, str(web / OUTSIDE)], capture_output=True, text=True)
    if done.returncode != 0:
        raise Refused("OutsideServices' lists could not be read: %s" % done.stderr.strip()[:400])
    data = json.loads(done.stdout)
    for key, entry in data["SERVICES"].items():
        entry["hosts"] = [re.sub(r"^https?://(\*\.)?", lambda m: "." if m.group(1) else "", h).rstrip("/")
                          for h in entry["hosts"]]
    return data


def covers(entry: str, host: str) -> bool:
    return host == entry or (entry.startswith(".") and (host.endswith(entry) or host == entry[1:]))


def scan(web: Path) -> list[tuple[str, str, bool, int]]:
    """Every host occurrence in code: (file, host, in a browser-load place, line number)."""
    found: list[tuple[str, str, bool, int]] = []
    for rel, (code, strings) in code_texts(web).items():
        low = code.lower()
        for match in HOST_AFTER_SLASHES.finditer(low):
            line_start = low.rfind("\n", 0, match.start()) + 1
            before = low[max(line_start, match.start() - 200):match.start()]
            found.append((rel, match.group(1), LOAD_CONTEXT.search(before) is not None,
                          low.count("\n", 0, match.start()) + 1))
        for match in list(ADDRESS_LITERAL.finditer(low)) + list(ADDRESS_LITERAL_NO_SCHEME.finditer(low)):
            if any(match.start() >= m.start() and match.end() <= m.end() for m in HOST_AFTER_SLASHES.finditer(low, max(0, match.start() - 8), match.end())):
                continue  # already read by the ordinary shape (a name with a letters-only ending)
            line_start = low.rfind("\n", 0, match.start()) + 1
            before = low[max(line_start, match.start() - 200):match.start()]
            found.append((rel, (match.group(1) or match.group(2) or ''), LOAD_CONTEXT.search(before) is not None,
                          low.count("\n", 0, match.start()) + 1))
        for pattern, group in ((CSP_DIRECTIVE, 1), (CSP_EXTRA, 2)):
            for match in pattern.finditer(low):
                for one_source in re.finditer(r"\S+", match.group(group)):
                    source = one_source.group(0).strip("'\"`,")
                    if "//" in source or "$" in source or "{" in source or source.endswith(":"):
                        continue  # a keyword, a scheme, a variable, or an address the `//` shape reads
                    one = CSP_SOURCE_HOST.match(source)
                    if one is not None and one.group(1).rsplit(".", 1)[-1] not in FILE_ENDINGS:
                        found.append((rel, one.group(1), True,
                                      low.count("\n", 0, match.start(group) + one_source.start()) + 1))
        for match in GLUED_SUFFIX.finditer(low):
            if match.group(1).rsplit(".", 1)[-1] not in FILE_ENDINGS:
                found.append((rel, match.group(1), False, low.count("\n", 0, match.start()) + 1))
        for string, string_line in strings:
            low_s = (string or "").lower()
            if WHOLE_HOSTS.match(low_s) is None:
                continue
            # Each host's own line: a string can begin with a line break (#808 round 4).
            for one_host in re.finditer(r"[^\s,]+", low_s):
                host = one_host.group(0)
                if host.rsplit(".", 1)[-1] in REAL_TOP_LEVEL:
                    found.append((rel, host, False, int(string_line) + low_s.count("\n", 0, one_host.start())))
    return found


def check(web: Path, per_file: dict[str, dict[str, int]] | None = None) -> list[str]:
    per_file = PER_FILE if per_file is None else per_file
    problems: list[str] = []
    lists = outside_lists(web)

    # Who owns each entry, and whether a browser may load it.
    owners: dict[str, list[str]] = {}
    browser_ok: dict[str, bool] = {}
    disclosed_lists: dict[str, str] = {}
    for list_name in ("SERVICES", "NAMED", "USED_BY_ORGANISATION"):
        for key, entry in lists[list_name].items():
            for h in entry["hosts"]:
                h = h.lower()
                owners.setdefault(h, []).append("OutsideServices::%s['%s']" % (list_name, key))
                browser_ok[h] = browser_ok.get(h, False) or entry["from"] in ("browser", "both")
                disclosed_lists.setdefault(h, list_name)
            if list_name != "SERVICES" and entry["from"] not in ("browser", "server", "both"):
                problems.append("✗ OutsideServices::%s['%s'] says it is contacted from %r; it must be 'browser', "
                                "'server' or 'both', or the privacy pages print nothing for it."
                                % (list_name, key, entry["from"]))
            typed = key in TYPED_ADDRESS_ONLY
            if list_name != "SERVICES" and typed is False and entry["hosts"] == []:
                problems.append("✗ OutsideServices::%s['%s'] lists no hosts, so nothing ties it to code. If the "
                                "address is one somebody types, add the entry to TYPED_ADDRESS_ONLY here, on purpose."
                                % (list_name, key))
            if typed is True and entry["hosts"] != []:
                problems.append("✗ OutsideServices::%s['%s'] is in TYPED_ADDRESS_ONLY but lists hosts; take it out "
                                "of that list, so its hosts are tied to code like any other." % (list_name, key))
    for h, (kind, _reason) in NOT_ABOUT_ANYBODY.items():
        if h in owners:
            problems.append("✗ %s is named on the privacy pages (%s) AND listed here as sending nobody's details. "
                            "One host, one kind of answer." % (h, ", ".join(owners[h])))
        owners.setdefault(h, []).append("NOT_ABOUT_ANYBODY (%s)" % kind)
        browser_ok.setdefault(h, False)

    def owner_of(host: str) -> str | None:
        matches = [e for e in owners if covers(e, host)]
        return max(matches, key=len) if matches else None

    counted: dict[str, dict[str, int]] = {}
    seen: dict[str, set[str]] = {}
    # One report per file, host, line and kind of fault (#808 round 5): two shapes
    # can read the same written host -- a page-rule source can also be a whole
    # quoted string -- and the reader must be told once, not twice.
    reported: set[tuple[str, str, int, str]] = set()
    for rel, host, loads, line in scan(web):
        entry = owner_of(host)
        if entry is None:
            if (rel, host, line, "unlisted") in reported:
                continue
            reported.add((rel, host, line, "unlisted"))
            problems.append(
                "✗ %s (line %d) names the outside host %s, which is in none of the lists.\n"
                "   A request to another company can tell it something about whoever is looking. If it sends "
                "anything about a person, add it to OutsideServices::NAMED or ::USED_BY_ORGANISATION (which puts "
                "it on both privacy pages, D-158, D-195) or under the on/off rules (SERVICES, D-058); if it sends "
                "nobody's details, add it to NOT_ABOUT_ANYBODY here with its reason." % (rel, line, host))
            continue
        seen.setdefault(entry, set()).add(rel)
        if loads and browser_ok.get(entry, False) is False and (rel, host, line, "loads") not in reported:
            reported.add((rel, host, line, "loads"))
            problems.append(
                "✗ %s (line %d) puts %s where a browser fetches it by itself, but it is listed as %s.\n"
                "   A browser that loads it tells that company the reader's internet address. Either this is a "
                "company the privacy pages must say the BROWSER contacts (a NAMED or USED_BY_ORGANISATION entry "
                "with from 'browser' or 'both'), or it must not be loaded here."
                % (rel, line, host, ", ".join(owners[entry])))
        kind = NOT_ABOUT_ANYBODY.get(entry, ("disclosed", ""))[0]
        if kind in ("disclosed", LINK):
            counted.setdefault(entry, {})
            counted[entry][rel] = counted[entry].get(rel, 0) + 1

    for entry in sorted(set(counted) | set(per_file)):
        have = counted.get(entry, {})
        want = per_file.get(entry, {})
        for rel in sorted(set(have) | set(want)):
            if have.get(rel, 0) != want.get(rel, 0):
                problems.append(
                    "✗ %s names %s %d time(s); PER_FILE says %d.\n"
                    "   MORE (or a file not listed) is a new use of that company: check the privacy pages still "
                    "say what is true, then change the number here on purpose. FEWER means a use has gone."
                    % (rel, entry, have.get(rel, 0), want.get(rel, 0)))
    # Round 8: a disclosed host may be named ONLY inside a WHOLE_FILES file (the noticeboard bundle's Google
    # Fonts connection). Such a file is not read as code, so rule E looks for the host's plain text in it.
    whole_texts = {rel: (web / rel).read_text(encoding="utf-8", errors="ignore").lower()
                   for rel in WHOLE_FILES if (web / rel).is_file()}
    for entry in sorted(owners):
        files = seen.get(entry, set())
        if disclosed_lists.get(entry) in ("NAMED", "USED_BY_ORGANISATION"):
            files = files - {OUTSIDE}
            bare = entry.lstrip(".")
            files = files | {rel for rel, text in whole_texts.items() if bare in text}
        if files == set():
            problems.append("✗ %s is listed (%s) but no code under web/ names it%s. Remove it on purpose; a list "
                            "that says more than is true reads as coverage."
                            % (entry, ", ".join(owners[entry]),
                               " outside OutsideServices.php" if entry in disclosed_lists else ""))
    for rel in sorted(WHOLE_FILES):
        if (web / rel).is_file() is False:
            problems.append("✗ WHOLE_FILES names %s, which does not exist. Remove the entry on purpose." % rel)
    return problems


# 🧪 ---------------------------------------------------------------------------
# Self-test: plant each fault in a copy of the real tree, and require the check
# to report it (or, for the must-stay-green cases, nothing at all).
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
            ok = problems == [] if expect is None else (expect in joined)
            print(("  ✓ " if ok else "  ✗ ") + name + ("" if ok else "\n      got: " + (joined[:700] or "nothing")))
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
            p.write_text(body.replace(old, new, 1), encoding="utf-8")
        return plant

    def write(rel: str, text: str):
        def plant(web: Path) -> None:
            (web / rel).parent.mkdir(parents=True, exist_ok=True)
            (web / rel).write_text(text, encoding="utf-8")
        return plant

    new = "names the outside host"
    print("check_outside_hosts_named self-test")
    run("S01 the untouched tree is green", lambda web: None, None)
    run("S02 a new host in PHP code fails",
        append("_apps/privacy/index.php", "<?php $x = 'https://tracker.newcompany.io/pixel.gif'; ?>\n"),
        new + " tracker.newcompany.io")
    run("S03 the same host in a PHP comment does NOT fail",
        append("_apps/privacy/index.php", "<?php // https://tracker.newcompany.io/pixel.gif\n ?>\n"), None)
    run("S04 a new host in JavaScript code fails",
        append("public_html/assets/js/portal.js", "\nvar beacon = 'https://beacon.newcompany.io/x';\n"),
        new + " beacon.newcompany.io")
    run("S05 a host only in a JavaScript comment does NOT fail",
        append("public_html/assets/js/portal.js", "\n// https://beacon.newcompany.io/x\n"), None)
    run("S06 a protocol-relative host in page text fails",
        append("_apps/privacy/policy.php", '<script src="//cdn.newcompany.io/a.js"></script>\n'),
        new + " cdn.newcompany.io")
    run("S07 a glued-on suffix fails",
        append("_core/VideoEmbed.php", "\n<?php $u = 'https://x-' . $c . '.newstream.io/'; ?>\n"),
        new + " newstream.io")
    run("S08 a new address in a settings seed fails",
        append("_sql/full_schema.sql", "\nINSERT INTO `tblSettings` VALUES ('x', 'https://api.newcompany.io');\n"),
        new + " api.newcompany.io")
    run("S09 the same host in an SQL comment does NOT fail",
        append("_sql/full_schema.sql", "\n-- https://api.newcompany.io\n"), None)
    run("S10 a NAMED host whose code has gone fails (its own list does not count)",
        replace("_core/What3Words.php", "https://api.what3words.com/v3/", "https://' . 'x/v3/"),
        "api.what3words.com is listed")
    run("S11 a NAMED service that loses a host fails where the code still names it",
        replace("_core/OutsideServices.php", "'hosts'     => ['cdn.jsdelivr.net', 'cdnjs.cloudflare.com'],",
                "'hosts'     => ['cdnjs.cloudflare.com'],"),
        new + " cdn.jsdelivr.net")
    run("S12 a host both named and 'about nobody' fails",
        replace("_core/OutsideServices.php", "'hosts'     => ['api.what3words.com'],",
                "'hosts'     => ['api.what3words.com', 'github.com'],"),
        "github.com is named on the privacy pages")
    run("S13 a WHOLE_FILES entry that no longer exists fails",
        lambda web: (web / "public_html/assets/noticeboard/fonts/OFL-ibm-plex-mono.txt").unlink(),
        "OFL-ibm-plex-mono.txt, which does not exist")
    run("S14 a switched (SERVICES) host that loses its entry fails where the code still names it",
        replace("_core/OutsideServices.php", "'imgSources' => ['https://www.gravatar.com'],",
                "'imgSources' => ['https://www.gravatar.invalid'],"),
        new + " www.gravatar.com")
    run("S15 a new kind of file is read, not skipped",
        write("public_html/x.webmanifest", '{"u":"https://t.newcompany.io"}'), new + " t.newcompany.io")
    run("S16 a host inside a WHOLE_FILES file does NOT fail",
        append("_core/data/giving-retention-countries.json", '\n"https://source.newcompany.io/a"\n'), None)
    run("S17 a glued file name such as '.backup.json' is NOT read as a host",
        append("_apps/privacy/index.php", "<?php $f = $base . '.backup.json'; ?>\n"), None)
    run("S18 capitals do not hide a host",
        append("_apps/privacy/index.php", "<?php $x = 'HTTPS://Tracker.NewCompany.IO/x'; ?>\n"),
        new + " tracker.newcompany.io")
    run("S19 an address inside another address (percent-encoded) fails",
        append("_apps/privacy/index.php", "<?php $x = 'https://a.b/?u=https%3A%2F%2Fpixel.newcompany.io%2F'; ?>\n"),
        new + " pixel.newcompany.io")
    run("S20 an escaped https:\\/\\/ in JavaScript fails",
        append("public_html/assets/js/portal.js", "\nvar u = 'https:\\/\\/esc.newcompany.io\\/x';\n"),
        new + " esc.newcompany.io")
    run("S21 a JavaScript template address fails",
        append("public_html/assets/js/portal.js", "\nvar u = `https://${zone}.tpl.newcompany.io/x`;\n"),
        new + " tpl.newcompany.io")
    run("S22 a bare host list in a settings seed fails",
        append("_sql/full_schema.sql", "\nINSERT INTO `tblSettings` VALUES ('push.allow', 'a.newcompany.io,b.newcompany.io');\n"),
        new + " a.newcompany.io")
    run("S23 a bare host in a PHP string fails",
        append("_core/Translation.php", "\n<?php $h = 'api.newtranslator.com'; ?>\n"),
        new + " api.newtranslator.com")
    run("S24 a LINK host loaded as a picture fails",
        append("_apps/privacy/index.php", '<img src="https://www.openstreetmap.org/x.png">\n'),
        "puts www.openstreetmap.org where a browser fetches it by itself")
    run("S25 a SERVER-only company loaded as a script fails",
        append("_apps/privacy/index.php", '<script src="https://api.what3words.com/x.js"></script>\n'),
        "puts api.what3words.com where a browser fetches it by itself")
    run("S26 a known company's script on a NEW page fails (file by file)",
        write("_apps/privacy/zz-new.php", '<script src="https://www.google.com/x.js"></script>\n'),
        "_apps/privacy/zz-new.php names www.google.com 1 time(s); PER_FILE says 0")
    run("S27 one more use in a listed file fails",
        append("_core/Captcha.php", "\n<?php $x = 'https://www.google.com/other'; ?>\n"),
        "_core/Captcha.php names www.google.com")
    run("S28 an accented host is reported whole",
        append("_apps/privacy/index.php", "<?php $x = 'https://pixel.exämple-neu.com/x'; ?>\n"),
        new + " pixel.exämple-neu.com")
    run("S29 a page-rule source naming a LINK host fails",
        append("_apps/privacy/index.php", "<?php $cspFrameExtra = 'https://github.com'; ?>\n"),
        "puts github.com where a browser fetches it by itself")
    run("S30 a host with no scheme added to the page rule's script-src fails",
        replace("_core/templates/header.php", "'unsafe-inline' https://cdn.jsdelivr.net https://challenges.cloudflare.com{$cspCaptchaScript}; ",
                "'unsafe-inline' https://cdn.jsdelivr.net https://challenges.cloudflare.com cdn.newtracker.io; "),
        new + " cdn.newtracker.io")
    run("S31 a wildcard host with no scheme in a page's $cspImgExtra fails",
        replace("_apps/venues/venue.php", "$cspImgExtra = 'https://*.tile.openstreetmap.org';",
                "$cspImgExtra = 'https://*.tile.openstreetmap.org *.tiles.newmaps.io';"),
        new + " tiles.newmaps.io")
    run("S32 an example host sent by sendBeacon fails (a browser load)",
        append("public_html/assets/js/portal.js", "\nnavigator.sendBeacon('https://www.example.org/b', 'x');\n"),
        "puts www.example.org where a browser fetches it by itself")
    run("S33 a bare host in a backtick string fails",
        append("public_html/assets/js/portal.js", "\nvar h = `beacon.newcompany.com`;\n"),
        new + " beacon.newcompany.com")
    run("S34 a keyword, a scheme and a variable in a page rule are NOT read as hosts",
        append("_apps/privacy/index.php", "<?php $cspImgExtra = \"'self' data: https: {$x}\"; ?>\n"), None)
    run("S35 an entry marked as typed-address-only that lists a host fails",
        replace("_core/OutsideServices.php", "            'why'       => 'So that the people running this portal hear about faults and can fix them.',\n"
                "            'hosts'     => [],",
                "            'why'       => 'So that the people running this portal hear about faults and can fix them.',\n"
                "            'hosts'     => ['api.what3words.com'],"),
        "['errorMonitoring'] is in TYPED_ADDRESS_ONLY but lists hosts")
    run("S36 an entry with no hosts that is not marked typed-address-only fails",
        replace("_core/OutsideServices.php", "'hosts'     => ['api.what3words.com'],", "'hosts'     => [],"),
        "['what3words'] lists no hosts")
    def line_after(rel: str) -> int:
        return (real / rel).read_text(encoding="utf-8").count("\n") + 1

    run("S37 a host added at the end of header.php is reported at its true line (comments above it)",
        append("_core/templates/header.php", "<?php $x = 'https://line.newcompany.io/'; ?>\n"),
        "_core/templates/header.php (line %d) names the outside host line.newcompany.io"
        % line_after("_core/templates/header.php"))
    run("S38 a host added at the end of Qr.php is reported at its true line",
        append("_core/Qr.php", "\n$x = 'https://qrline.newcompany.io/';\n"),
        "_core/Qr.php (line %d) names the outside host qrline.newcompany.io" % (line_after("_core/Qr.php") + 1))
    run("S39 a bare host in a PHP string is reported at its true line, never line 0",
        append("_core/Translation.php", "\n$h = 'api.linetranslator.com';\n"),
        "_core/Translation.php (line %d) names the outside host api.linetranslator.com"
        % (line_after("_core/Translation.php") + 1))
    run("S40 a host after a block comment in JavaScript is reported at its true line",
        append("public_html/assets/js/portal.js", "\n/* one\ntwo\nthree */\nvar u = 'https://jsline.newcompany.io/';\n"),
        "public_html/assets/js/portal.js (line %d) names the outside host jsline.newcompany.io"
        % (line_after("public_html/assets/js/portal.js") + 4))
    run("S41 a host after a block comment in CSS is reported at its true line",
        append("public_html/assets/css/portal.css",
               "/* one\ntwo\nthree */\n.x { background: url('https://cssline.newcompany.io/a.png'); }\n"),
        "public_html/assets/css/portal.css (line %d) names the outside host cssline.newcompany.io"
        % (line_after("public_html/assets/css/portal.css") + 3))
    run("S42 a host after comment lines in .htaccess is reported at its true line",
        append("public_html/.htaccess",
               "# one\n# two\nHeader set X-Test \"https://htline.newcompany.io/\"\n"),
        "public_html/.htaccess (line %d) names the outside host htline.newcompany.io"
        % (line_after("public_html/.htaccess") + 2))
    run("S43 a whole-string host that begins on the string's second line is reported on that line",
        append("_core/Translation.php", "\n$h = '\napi.nlstart.com';\n"),
        "_core/Translation.php (line %d) names the outside host api.nlstart.com"
        % (line_after("_core/Translation.php") + 2))
    run("S45 an internet-number address loaded as a picture fails",
        append("_core/templates/footer.php", '<img src="https://198.51.100.7/pixel.gif">\n'),
        new + " 198.51.100.7")
    run("S46 a punycode address fails",
        append("_core/templates/footer.php", '<img src="https://xn--80ak6aa92e.xn--p1ai/p.gif">\n'),
        new + " xn--80ak6aa92e.xn--p1ai")
    run("S47 an IPv6 address in square brackets fails",
        append("_core/templates/footer.php", '<img src="https://[2001:db8::7]/p.gif">\n'),
        new + " [2001:db8::7]")
    run("S48 the machine's own address (127.0.0.1) gets no automatic pass",
        append("_core/templates/footer.php", '<?php $u = "http://127.0.0.1/hook"; ?>\n'),
        new + " 127.0.0.1")
    run("S50 an internet-number address with no scheme (//198.51.100.7/) fails",
        append("_core/templates/footer.php", '<img src="//198.51.100.7/pixel.gif">\n'),
        new + " 198.51.100.7")
    run("S51 an address written as one large number (https://3325256711/) fails",
        append("_core/templates/footer.php", '<img src="https://3325256711/pixel.gif">\n'),
        new + " 3325256711")
    run("S52 a disclosed host named only inside a WHOLE_FILES file still counts as named",
        replace("public_html/assets/noticeboard/noticeboard.noeval.js", "fonts.gstatic.com", "fonts.example.invalid"),
        "fonts.gstatic.com is listed")
    run("S49 a version number after // in page text is NOT read as an address",
        append("_core/templates/footer.php", '<p>see //1.2.3.4 notes</p>\n'), None)
    run("S53 a hexadecimal address (https://0xc6336407/) fails",
        append("_core/templates/footer.php", '<img src="https://0xc6336407/p.gif">\n'), new + " 0xc6336407")
    run("S54 an octal address (https://0306.051.0144.07/) fails",
        append("_core/templates/footer.php", '<img src="https://0306.051.0144.07/p.gif">\n'), new + " 0306.051.0144.07")
    run("S55 a shortened address (https://198.51.25607/) fails",
        append("_core/templates/footer.php", '<img src="https://198.51.25607/p.gif">\n'), new + " 198.51.25607")
    run("S56 an address with a trailing dot (https://198.51.100.17./) fails",
        append("_core/templates/footer.php", '<img src="https://198.51.100.17./p.gif">\n'), new + " 198.51.100.17")
    run("S57 an address with a port (https://198.51.100.18:8443/) fails",
        append("_core/templates/footer.php", '<img src="https://198.51.100.18:8443/p.gif">\n'), new + " 198.51.100.18")
    run("S58 an address with a user name in front (https://u:p@198.51.100.19/) fails",
        append("_core/templates/footer.php", '<img src="https://u:p@198.51.100.19/p.gif">\n'), new + " 198.51.100.19")
    run("S59 a hexadecimal address with no scheme (//0xc6336408/) fails",
        append("_core/templates/footer.php", '<img src="//0xc6336408/p.gif">\n'), new + " 0xc6336408")
    run("S60 a version number in a path, a decimal in a query, and numbers after // in prose stay green",
        append("_core/templates/footer.php",
               '<a href="https://www.example.org/v1.2.3/?n=1.5&m=2.0">x</a><p>//1.5 seconds, //10 items</p>\n'), None)
    run("S61 an internet number in CSS url(https://...) with nothing after the host fails (round 10)",
        append("_core/templates/footer.php", '<div style="background:url(https://198.51.100.20)"></div>\n'),
        new + " 198.51.100.20")
    run("S62 an internet number in CSS url(//...) with no scheme fails (round 10)",
        append("_core/templates/footer.php", '<div style="background:url(//198.51.100.21)"></div>\n'),
        new + " 198.51.100.21")
    run("S63 a punycode name in CSS url(https://...) fails",
        append("_core/templates/footer.php", '<div style="background:url(https://xn--80ak6aa92e.xn--p1ai)"></div>\n'),
        new + " xn--80ak6aa92e.xn--p1ai")
    run("S64 a version number in brackets in page text stays green (round 10)",
        append("_core/templates/footer.php", '<p>(version 1.2.3) and (v2.0)</p>\n'), None)
    # S44: one written host read by two shapes on one line (a page-rule source that is also a whole quoted
    # host name) is reported ONCE. Counted here rather than through run(), which only asks "is it there".
    checks += 1
    with tempfile.TemporaryDirectory(prefix="p808-hosts-") as tmp:
        web = Path(tmp) / "web"
        shutil.copytree(real, web, ignore=shutil.ignore_patterns("*.png", "*.woff2", "*.zip", "*.gif"))
        append("_apps/privacy/index.php", "<?php $cspImgExtra = 'cdn.twice.com'; ?>\n")(web)
        times = sum(1 for one in check(web) if "names the outside host cdn.twice.com" in one)
    ok = times == 1
    print(("  ✓ " if ok else "  ✗ ") + "S44 a host read by two shapes on one line is reported once"
          + ("" if ok else " (reported %d times)" % times))
    if not ok:
        failures += 1
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
        print("\n%d problem(s). The rule is #808 (D-158, D-195): every outside company the code names is on the "
              "privacy pages, under the on/off rules, or listed here as sending nobody's details." % len(problems),
              file=sys.stderr)
        return 1
    print("✓ Every outside host found in code is accounted for, in the four shapes this check reads (after a //, "
          "plain, escaped or percent-encoded; a glued-on .suffix; a whole quoted host name ending in a known "
          "top-level name; a source in a page-rule list) -- only where `//` comes directly before the host, so "
          "any other spelling the URL standard accepts (backslashes, missing slashes, a tab or line break, "
          "percent-encoded characters in the host, `http:host` with no slashes) is NOT read; %d PER_FILE lines hold; no link, example or server-only company sits where a browser "
          "loads by itself, in the load shapes it knows. It CANNOT see a host built from other pieces, an address "
          "typed by an administrator, a load through a variable, or whether the privacy-page words are true."
          % sum(len(v) for v in PER_FILE.values()))
    return 0


if __name__ == "__main__":
    sys.exit(main())
