#!/usr/bin/env python3
"""
Installation package checker (#499).

Path: tools/package/check-package.py

Checks a built installation package (the folder, or the zip made from it)
before anybody is allowed to download it. tools/package/build.sh runs it twice
on every build: on the folder BEFORE the zip is made, and on the zip after.

WHY THIS EXISTS
---------------
The package is a public download. Whatever is in it, strangers get. On
13 September 2026 a real `web/_auth_keys/auth_creds.php` (the database login)
sat in a working folder on a development machine; a build that copied that
folder would have published it. These guards are all that stands between a
stray secret and that download, so every one of them FAILS the build, and a
guard that could not run counts as a failure, never as a quiet pass.

THE RULE BEHIND THE GUARDS: REFUSE WHEN UNSURE
----------------------------------------------
The first version of this file listed what must NOT be in a package. An
independent check on 12 October 2026 committed 43 files that list had not
thought of (the database login under another name, `prod.env`, `id_rsa`,
`backup.zip`, a dump inside the web folder) and every one was shipped. A list
of bad names can never be complete. So the main wall is now the other way
round: a package may hold ONLY what is positively allowed. Every file must be
of a kind, and in a place, that ALLOWED_KINDS names. Anything else fails, and
the message says what to do. The deny list is kept as a second net.

WHAT IT COMPARES THE PACKAGE WITH
---------------------------------
Everything it expects comes from GIT AT THE COMMIT YOU NAME, never from the
package itself and never from the working folder: the list of files, the exact
bytes of every one of them, the version number, the changelog, the guide, and
the fingerprints of the outside libraries. dompdf's files are not in git, so
they are compared with the committed list beside this script
(dompdf-files.sha256), which is tied to the pinned download. The package is
only ever read. No PHP from the package is run (`php -l` reads a file for
mistakes without running it).

Settings read from the scripts beside this one, so each has ONE home: the
staff web folder's name (STAFF_WEB_FOLDER in build.sh), and dompdf's version
and checksum (DOMPDF_VERSION and DOMPDF_SHA256 in tools/download-dompdf.sh).

THE GUARDS (G1 to G13 are the earlier design's numbers, `.claude/plans/
public-door-6-configurable-domains-and-package.md` section 6.6)
  G1   Every file git holds under web/ at that commit is in the package,
       byte for byte, except the documented removals. Every dompdf file is on
       the committed list, byte for byte, and none is missing. Nothing else is
       in the package except the documented additions. No links.
  --   What was left out is said, by name. A real file in web/_includes/ or
       web/_functions/ fails, because the build would leave it out. The one
       file let through there is the folder's own "refuse everything" file,
       and only while it is byte for byte the copy in web/_core/.
  --   Git holds no channel file (web/.channel), and neither does a package
       (see G9). One that is committed is refused, whatever it says.
  --   The allow list: every name uses only plain characters and an ending
       in small letters, and every file is of an allowed kind in an allowed
       place. A picture or a font is followed from its first part to its
       last, and may hold no mark of an archive, of PHP or of a key; a
       drawing (.svg) may hold no script and no link out of itself; any other
       kind must be text. An archive is refused unless it is named, with its
       checksum, and so is a file of web-server rules (.htaccess). Inside the
       web folder no name may carry .php (or .phtml, .phar, .pht, .inc) as
       any part of it, except the named PHP files.
  G2   No path matches the deny list (secrets, planning folders, development
       files, leftovers). G4: the three server-managed folders are absent at
       any depth, even empty.
  --   No file other than the installer holds the database login in the shape
       the installer writes, and none is the encryption key in its shape.
  G3   Every top-level name is on the allow list, and every expected one is
       there.
  --   No file or folder is writable by group or others.
  G5   gitleaks finds no secret (a separate program; see below). It is run
       twice: on the folder as it is, and on a copy in which every file has a
       plain name and is cut into small pieces, because left to itself it
       skips some files for their names and some for how they begin. Its
       "found nothing" counts only if it FOUND a made-up key it was shown
       first, alone and again among the pieces: a program that merely calls
       itself gitleaks, or one whose rules did not load, finds neither.
  G6   Our own domain is nowhere WRITTEN OUT WHOLE: not in any file, in
       capitals or small letters, and not in a file's name. (Until
       13 October 2026 this said "not in any byte of any file", which claimed
       more than it does: see WHAT THIS CANNOT DO.) The names it looks for
       have ONE home, the OWN_DOMAINS list in
       tools/audit-checks/check_no_hardcoded_domains.py.
  G7   Every .php file outside _vendor/ passes `php -l`, dompdf's included:
       PHP's exit code must be 0 AND it must say "No syntax errors".
  G8   dompdf is the pinned version, the committed list was made from the
       pinned download, and the files the portal loads are there.
  --   Every fallback copy of an outside library matches its fingerprint.
  G9   The channel word, the version and the tag all agree, and the package
       holds NO file called .channel. The word travels as the Channel line of
       VERSION.txt, and the installer writes the installation's own .channel
       from it only when there is none. Until 13 October 2026 every package
       carried the file, so unpacking one over an installation replaced that
       installation's own word: a test copy given a live package called
       itself live and lost its staff sign-in gate.
  G10  The guide is there and names the web folder the package really has.
       No switch lets a package through without it.
  --   No one file is larger than 16 MB, and all of them together are no
       larger than 150 MB (a real package is about 29 MB unpacked).
  G11-G13 (zip only) The zip matches its .sha256 file, is not damaged, has no
       unsafe entry names, no link, no entry writable by group or others, and
       is between 5 MB and 80 MB. It carries no comment, and every byte of it
       belongs to an entry, to the list of entries or to the end record:
       nothing in front, nothing between, nothing after. What it says it
       unpacks to is within the two limits above BEFORE anything is unpacked.
       Then every guard above runs again on what the zip really holds.

NOT BUILT HERE, ON PURPOSE: the earlier design's G14 and REMOVED-FILES.txt
belong to the two-door layout (#493) and to a later release.

Usage
  check-package.py <folder or zip> --commit <commit> --channel <live|beta|alpha>
                   [--tag <vX.Y.Z or vX.Y.Z-suffix>] [--repo <repository>]
                   [--allow-no-gitleaks]
  check-package.py --tree web [--commit <commit>] [--repo <repository>]
        For the pull-request checks. Reads what git holds under web/ at the
        commit (default HEAD) and applies the same rules about names, kinds,
        places, contents, archives, links and left-out files, plus the deny
        list. It does not build anything, so the guards that need a built
        package (dompdf, the fallback copies, gitleaks, php -l, the labels)
        do not run here. A file git does not track is not seen: the package
        is made from git, so such a file cannot get into it.

THE ONE "ALLOW" SWITCH, AND WHY IT CANNOT SHIP QUIETLY
  --allow-no-gitleaks    gitleaks is not installed on this machine. Skips G5
        only, and only when gitleaks really is absent.
  It prints "NOT CHECKED", and the last line then says the package is NOT FIT
  TO PUBLISH. It is REFUSED when --tag is given, and only a tagged build is
  ever published. build.sh puts NOT-FOR-RELEASE in the zip's name. This
  checker cannot insist on that name: it cannot tell from a zip whether
  gitleaks ran when it was built.
  There used to be a second switch, --allow-missing-guide, from the weeks
  before docs/INSTALL.md existed. It was removed on 13 October 2026: the guide
  exists, a package without it is useless to a customer, and a switch that
  lets one through is a way for that to happen by accident.

Exit: 0 when every guard that ran passed; 1 otherwise, including when this
script itself stopped early. A real result always ends with a line containing
"the run reached its end". A crash does not print that line, so anything
that reads the result must look for it as well as the exit code. build.sh does.
"Stopped early" includes being ENDED by something it read: until 13 October
2026 a list of our domains that ended the program when it was read stopped
the checker after G5 with exit code 0.

WHAT THIS CANNOT DO
  - It cannot tell an altered zip from the original if whoever altered it also
    rewrote the .sha256 file. What it does do is check the zip's real contents
    against git and the dompdf list every time it is run.
  - A file of an ALLOWED kind, in an allowed place, with an ordinary name,
    holding a secret in a shape nobody has described, would pass. Example: a
    new `_core/Notes.php` with a password in a comment, or a numbered file in
    `_sql/` that is really a copy of somebody's data. The allow list shrinks
    where a secret can hide; it cannot read meaning. Git's own ignore rules
    and review of what is committed are the first wall.
  - The contents rule knows two shapes only: the database login as the
    installer writes it (a `'db_pass' =>` line, or the same key in JSON), and
    a file that is nothing but 64 hexadecimal characters (the encryption
    key). The same password written any other way is not recognised.
  - gitleaks now reads every file, but it knows only the shapes in its own
    rules: keys and tokens of well-known services, and private keys. A
    password of somebody's own choosing, people's details, or anything
    packed or encoded (base64, a compressed stream) is not a shape it knows.
    A private key longer than about 4,000 characters is cut across two
    pieces in the second run and may be missed there.
  - A picture or a font is followed part by part to its end, so nothing can
    be stuck on after it, and the plain marks of an archive, of PHP (the
    long opening tag) and of a key are refused anywhere in it. But what is
    INSIDE a well-formed part is not understood: the dots of a picture, a
    note inside a PNG or a GIF, an entry inside an .icns, and the packed
    data of a font (Python has no reader for that packing, Brotli) could
    each carry bytes that are not picture or font at all. PHP's SHORT
    opening tag is two characters, which turn up by chance in any picture,
    so it cannot be refused; what stops such a file running is the name rule
    and the .htaccess rule, not this one.
  - A drawing (.svg) is refused for a script, an event attribute, a
    foreignObject and any link out of itself. Styles and animation that need
    none of those are not judged, and words inside it are read only by
    gitleaks.
  - The web folder's own .htaccess is held by its checksum alone. Whoever
    changes it and writes the new checksum here has to have read it: this
    script cannot tell a good rewrite rule from a bad one. Every other
    .htaccess is also refused for a line that could switch PHP on.
  - The name rule for the web folder knows .php (with or without a digit),
    .phtml, .phar, .pht, .phps and .inc. A server set up to run some other
    ending as a program (.cgi, .pl) is not covered by it; such a file is
    refused only when that is its LAST ending, as a kind nobody allowed.
  - Nothing looks INSIDE an archive. That is why an archive is refused unless
    it is named in ALLOWED_ARCHIVES with its checksum. A named archive was
    opened and read by a person once; a changed copy no longer matches.
  - An archive is recognised by how its contents begin (zip, gzip, bzip2, xz,
    7z, rar, tar). A container format this does not know, inside a file of an
    allowed kind, would not be seen as one.
  - It does not prove the package INSTALLS. That was done by hand on
    13 October 2026, by following docs/INSTALL.md from a built zip on MySQL
    8.0.36 and 8.4.11 behind PHP's own web server. Nothing here repeats it,
    and nobody has yet done it on a real hosting panel or under Apache.
  - G6 looks only for the names on the OWN_DOMAINS list (see G6 above). Some
    OTHER address built into the code is not found by it.
  - G6 finds a name only where it is written out whole, letter after letter,
    in one line. Shown on 13 October 2026 to pass: the name split across two
    lines, one of its letters written as %xx or as a character reference
    (&#x6d;), the whole of it in base64, and the name inside the PACKED text
    of a PNG. (A full stop written as %2E, and the plain text of a PNG, are
    found: the letters are still in a row.) Nothing here decodes anything.
  - The made-up key G5 shows gitleaks first proves that the program finds
    ONE shape, a private key, with these options and these settings. It does
    not prove any other rule loaded, and a program written to recognise that
    one key and nothing else would pass it.
  - The zip's bytes are accounted for by the lengths the zip itself states.
    Bytes hidden INSIDE an entry's own packed data (after the end of its
    compressed stream but within its stated length) are not looked for.
  - A file it reads as a program (the list of our domains) could end this
    script in a way Python cannot catch (os._exit). That file is our own.
  - It trusts git and the programs it calls (git, php, gitleaks, python).

Copyright (c) 2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import zipfile
import zlib
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from xml.etree import ElementTree

HERE = Path(__file__).resolve().parent
END_MARK = "the run reached its end"

PRODUCT = "WebMS Intra"
ZIP_PREFIX = "webms-intra-"
CHANNEL_WORDS = ("live", "beta", "alpha")

# The web folder's name in the repository. In the package it is renamed to
# STAFF_WEB_FOLDER (read from build.sh).
REPO_WEB_FOLDER = "public_html"

# What the build removes from git's list. This is deliberately written out a
# second time (build.sh has its own copy): if the two ever disagree, G1 fails,
# which is the point of a second wall.
#   - The three placeholder folders are deployed nowhere and one carried our
#     domain; the owner chose on 14 September 2026 to keep them in the
#     repository and have the package leave them out. What is left out is
#     printed by name on every run.
#   - _includes and _functions hold nothing but a placeholder file and the
#     "refuse everything" file. Any OTHER file there fails the check (see
#     guard_left_out): until 12 October 2026 it was left out without a word,
#     and the build passed with the file absent.
REMOVED_PLACEHOLDER_FOLDERS = ("private_html", "public_html_landing", "public_html_redir")
REMOVED_EMPTY_FOLDERS = ("_includes", "_functions")
REMOVED_FOLDERS = REMOVED_PLACEHOLDER_FOLDERS + REMOVED_EMPTY_FOLDERS
REMOVED_FILE_NAMES = (".gitkeep",)

CODE_FOLDERS = ("_apps", "_core", "_install", "_lang", "_sql", "_vendor", "_libraries")
# No ".channel" here: a package must not carry one (see G9 at the top).
ROOT_FILES = ("VERSION.txt", "CHANGELOG.md", "INSTALL.md")
CHANNEL_FILE = ".channel"
DOMPDF_FOLDER = "_libraries/dompdf/"
DOMPDF_LIST = HERE / "dompdf-files.sha256"

# G5. gitleaks is always given THIS settings file, which says only "use your
# own built-in rules". Naming it means nothing else can supply the settings:
# without it gitleaks would take them from the GITLEAKS_CONFIG variable of
# whoever runs the build, or from a .gitleaks.toml inside the folder it reads.
GITLEAKS_SETTINGS = HERE / "gitleaks-settings.toml"
# The second run hands gitleaks every file cut into pieces of PIECE bytes,
# each starting STEP bytes after the one before (so they overlap by half, and
# a secret up to STEP bytes long is whole in at least one piece), each behind
# PAD blank lines. gitleaks 8.21.2 reads 10,000 bytes at a time and judges
# what kind of file it has from how each such read BEGINS; PAD + PIECE is
# kept under 10,000 so that one piece is one read, and it begins with blank
# lines. See guard_secrets for why.
GITLEAKS_PIECE = 8000
GITLEAKS_STEP = 4000
GITLEAKS_PAD = 1024

# G6. The names of our own domains have ONE home: the OWN_DOMAINS list in the
# repository-wide check, tools/audit-checks/check_no_hardcoded_domains.py.
# Until 13 October 2026 this file kept a second copy of that list, and two
# lists that must agree are two lists that will one day disagree. To see the
# names: python3 tools/audit-checks/check_no_hardcoded_domains.py
# --print-own-domains.
OWN_DOMAINS_HOME = HERE.parent / "audit-checks" / "check_no_hardcoded_domains.py"

MIN_ZIP_BYTES = 5 * 1024 * 1024
MAX_ZIP_BYTES = 80 * 1024 * 1024
# What a package may UNPACK to. Until 13 October 2026 only the zip itself was
# measured, and a 150 MB script shipped inside an 11 MB zip: text of one
# repeated letter packs to almost nothing. On that day a real package was
# 1,580 files, 29.2 MB in all, its largest file 1.4 MB; the limits leave room
# to grow and none for that.
MAX_FILE_BYTES = 16 * 1024 * 1024
MAX_UNPACKED_BYTES = 150 * 1024 * 1024

# -----------------------------------------------------------------------------
# THE ALLOW LIST. A package may hold only what is named here.
# -----------------------------------------------------------------------------
# Worked out on 12 October 2026 from what git really held under web/ at commit
# 84c6180: 1,207 files, of which 1,202 are packaged. Each kind (the ending of
# the file's name, in small letters) is allowed only in the places listed. A
# place ending in "/" means "anywhere under this folder"; otherwise it is one
# exact file. WEB stands for the staff web folder, whatever it is called.
#
# TO SHIP A NEW KIND OF FILE, OR A KIND IN A NEW PLACE: add it here ON
# PURPOSE, with the reason, in the same commit as the file. Do not widen a
# place to "anywhere" to make a message go away: the narrow places are what
# stop a stray notes file or a dump being shipped.
WEB = "{web}"
ALLOWED_KINDS: dict[str, tuple[tuple[str, ...], str]] = {
    ".php": (
        ("_apps/", "_core/", "_install/", "_lang/", "_vendor/"),
        "the portal's own code, all of it outside the web folder; inside the web folder see WEB_FOLDER_PHP",
    ),
    ".sql": (("_sql/",), "the install script and the numbered database upgrades; the name must also fit SQL_NAME"),
    ".json": (("_core/",), "the API description and the data files the code reads, with their schemas"),
    ".css": ((WEB + "/",), "stylesheets a browser fetches"),
    ".js": ((WEB + "/",), "scripts a browser fetches"),
    ".svg": ((WEB + "/",), "drawings: icons, logos and brand artwork"),
    ".png": ((WEB + "/",), "pictures"),
    ".gif": ((WEB + "/",), "pictures (the animated brand emblems)"),
    ".ico": ((WEB + "/",), "browser tab icons"),
    ".icns": ((WEB + "/",), "the brand kit's app icons for macOS"),
    ".woff2": ((WEB + "/",), "fonts a browser fetches"),
    ".html": ((WEB + "/assets/images/brandkit/",), "the brand kit's two demonstration pages"),
    ".md": ((WEB + "/assets/images/brandkit/",), "the brand kit's read-me"),
    ".txt": ((WEB + "/assets/noticeboard/fonts/",), "the licences of the noticeboard's fonts, which must travel with them"),
    ".xsd": ((WEB + "/sitemap.xsd",), "the schema that describes the sitemap"),
}

# The only PHP files the web server may answer for directly. Everything else
# is reached through index.php. A new PHP file inside the web folder is how a
# phpinfo page, a database-admin page or a forgotten test page becomes
# reachable by strangers, so each one has to be named here.
WEB_FOLDER_PHP = (
    "index.php", "error.php", "api-docs/index.php", "manifest.php",
    "offline/index.php", "openapi.php", "robots.php", "sitemap.php",
)
# Inside the web folder, no file or folder name may carry one of these after
# ANY of its dots, in any capitals, unless it is one of the files named above.
# Until 12 October 2026 only the LAST ending was looked at, and an independent
# check shipped shell.php.js, shell.php.txt and phpinfo.php.png. On a server
# that picks PHP with "AddHandler … .php" (how many hosting panels write it)
# each of those ran as PHP for anybody who asked: the check served the zip
# from a real web server and got a phpinfo page back. ".php5", ".php7" and
# ".phps" are here because some hosts map those too.
PHP_LIKE_PART = re.compile(r"php\d*|phtml|phar|pht|phps|inc", re.IGNORECASE)

# Every real file in _sql/ is full_schema.sql or "<three digits>_<words>.sql".
# A dump saved there under any other name is refused.
SQL_NAME = re.compile(r"_sql/(full_schema|\d{3}_[A-Za-z0-9_]+)\.sql")

# EVERY .htaccess THAT SHIPS IS NAMED HERE, with the SHA-256 checksum of the
# copy somebody read. A file of this name holds rules for the web server, and
# one line in it can switch PHP on for pictures or hand out a list of files.
# Until 12 October 2026 the name was allowed anywhere and never read, and an
# independent check shipped one that made shell.png run as PHP.
#
# TO ADD ONE (the "refuse everything" files for private folders, for example):
# one line here, with the reason. A build that meets one it does not know
# prints the exact line to paste, checksum included. TO CHANGE ONE: read what
# changed, then put its new checksum here; the failure message prints it.
ALLOWED_HTACCESS = {
    WEB + "/.htaccess": (
        "896c4fc4a9125629ad39719291333f1c2ce7234623f00574f25fe3b3b3f71b1d",
        "the web folder's own rules: every address goes to index.php, and a direct .php address is refused "
        "(read 27 October 2026: the change was COMMENT ONLY — two comments said the web folder holds three "
        ".php files when it holds eight; no rule, condition or exception changed)",
    ),
    "_install/.htaccess": (
        "1164cf44c0f2c929155912677502795ea2cf448e8d3d87f0dbb196c780cf502e",
        "switches address rewriting off inside the installer's folder",
    ),
    # The five "refuse everything" files (roll-out package F3). One file, five
    # places, so one checksum. They hold no rule that switches anything on.
    # The same file also sits in web/_includes and web/_functions (roll-out
    # package F5), and those two are NOT named here: the package leaves both
    # folders out, file and all (see guard_left_out). Should one of them ever
    # be shipped, a build will print the line to add.
    "_apps/.htaccess": (
        "b1f4d428ffa07a2072588c220a7a0dc022a32f6deb578b802c9e6c87e4de5d95",
        "refuses every request: one comment and 'Require all denied'. The same file in five private folders (read 13 October 2026)",
    ),
    "_core/.htaccess": (
        "b1f4d428ffa07a2072588c220a7a0dc022a32f6deb578b802c9e6c87e4de5d95",
        "refuses every request: one comment and 'Require all denied'. The same file in five private folders (read 13 October 2026)",
    ),
    "_lang/.htaccess": (
        "b1f4d428ffa07a2072588c220a7a0dc022a32f6deb578b802c9e6c87e4de5d95",
        "refuses every request: one comment and 'Require all denied'. The same file in five private folders (read 13 October 2026)",
    ),
    "_sql/.htaccess": (
        "b1f4d428ffa07a2072588c220a7a0dc022a32f6deb578b802c9e6c87e4de5d95",
        "refuses every request: one comment and 'Require all denied'. The same file in five private folders (read 13 October 2026)",
    ),
    "_vendor/.htaccess": (
        "b1f4d428ffa07a2072588c220a7a0dc022a32f6deb578b802c9e6c87e4de5d95",
        "refuses every request: one comment and 'Require all denied'. The same file in five private folders (read 13 October 2026)",
    ),
}
# The second net for every .htaccess EXCEPT the web folder's own: a line that
# could make the server run a file as a program, or hand out a folder's list
# of files, is refused whatever the checksum says. "Options" is let through
# only when every word after it switches something OFF (-Indexes). The web
# folder's own file is left out because it may one day need such a line; it
# is held by its checksum alone, which the header says.
HTACCESS_RISKY_LINE = re.compile(
    r"\s*((?:add|set|remove|force)(?:handler|type|inputfilter|outputfilter|outputfilterbytype)"
    r"|php_\w+|action|script\w*|cgi\w*|xbithack|options)\b(.*)",
    re.IGNORECASE,
)

# Nothing looks inside an archive, so an archive is refused unless it is named
# here, with the SHA-256 checksum of the copy somebody opened and read.
ALLOWED_ARCHIVES = {
    WEB + "/assets/images/brandkit/WebMSChurchMS.zip": (
        "1cd682e98412d03597c98d18fcfaf627b9e1c8d9aa5fdc4c5de33ca3fd1c9316",
        "the brand kit as one download; opened on 12 October 2026: 22 brand files, no secret, no domain of ours",
    ),
}

# A picture, a font or a drawing has a test of its own (WHOLE_FILE_TESTS,
# further down, beside the functions it names). Every other allowed kind is
# text, and text holds no zero byte.
PNG_START = b"\x89PNG\r\n\x1a\n"
# What must not appear ANYWHERE in a picture, a font or a drawing. Each mark
# is long enough that it will not turn up by chance in packed data: the
# two-byte start of a gzip file would, so the fixed bytes that follow it are
# asked for as well. Before 12 October 2026 an archive was recognised only at
# the very start of a file, and an independent check shipped a working zip of
# the database login behind the first eight bytes of a PNG.
FOREIGN_MARKS = (
    (re.compile(rb"PK\x03\x04[\x00-\x3f]\x00[\x00-\xff]{2}[\x00\x08\x09\x0c\x0e\x5d\x5f\x62\x63]\x00"), "the start of a file inside a zip archive"),
    (re.compile(rb"PK\x05\x06\x00\x00\x00\x00"), "the end record of a zip archive"),
    (re.compile(rb"\x1f\x8b\x08[\x00-\x1f][\x00-\xff]{4}[\x00\x02\x04][\x00-\x0d\xff]"), "the start of a gzip archive"),
    (re.compile(rb"BZh[1-9]1AY&SY"), "the start of a bzip2 archive"),
    (re.compile(rb"\xfd7zXZ\x00"), "the start of an xz archive"),
    (re.compile(rb"7z\xbc\xaf\x27\x1c"), "the start of a 7z archive"),
    (re.compile(rb"Rar!\x1a\x07"), "the start of a rar archive"),
    (re.compile(rb"ustar(\x0000|  \x00)"), "the mark of a tar archive"),
    (re.compile(rb"<\?php", re.IGNORECASE), "a PHP opening tag"),
    (re.compile(rb"-----BEGIN "), "the first line of a key or a certificate"),
)
# How the contents of an archive begin: (where, bytes).
ARCHIVE_STARTS = (
    (0, b"PK\x03\x04"), (0, b"PK\x05\x06"), (0, b"PK\x07\x08"),   # zip, and the many formats built on it
    (0, b"\x1f\x8b"), (0, b"BZh"), (0, b"\xfd7zXZ\x00"),           # gzip, bzip2, xz
    (0, b"7z\xbc\xaf\x27\x1c"), (0, b"Rar!"), (257, b"ustar"),     # 7z, rar, tar
)

# Only these characters may appear in the name of a file or folder. Every
# name git holds under web/ today fits. It shuts out a space, a colon, a
# backslash, a line break and anything a hosting panel or Windows would
# mangle, at the FOLDER check; before 12 October 2026 a colon or a backslash
# was only noticed later, at the zip check.
NAME_CHARACTERS = re.compile(r"[A-Za-z0-9._-]+")

# -----------------------------------------------------------------------------
# G2 and G4: THE DENY LIST, the second net.
# -----------------------------------------------------------------------------
# Each pattern is tried against every file AND folder path in the package,
# without regard to capital letters (a Mac or Windows disk treats ".ENV" and
# ".env" as one file). "(^|/)" means "at any depth": before 12 October 2026
# several of these were tied to the top of the package, and
# admin_html/_backups/, _core/_auth_keys/ and _apps/.claude/ walked through.
DENY = [
    (r"(^|/)_auth_keys[^/]*(/|$)", "the folder that holds the database login and the encryption key, or a copy of it"),
    (r"(^|/)_uploads[^/]*(/|$)", "the folder that holds people's uploaded files, or a copy of it"),
    (r"(^|/)_backups[^/]*(/|$)", "the folder that holds database backups, or a copy of it"),
    (r"(^|/)[^/]*auth_creds[^/]*$", "the database login, or a copy of it"),
    (r"(^|/)enc\.key[^/]*$", "the encryption key, or a copy of it"),
    # ".env", ".env.local", ".env-backup", ".envrc" ...
    (r"(^|/)\.env[^/]*$", "an environment file, which usually holds passwords"),
    # ... and "prod.env", "prod.env.bak". The approved design had "\.env$";
    # the first build lost it, and prod.env was shipped.
    (r"\.env([._~-][^/]*)?$", "an environment file, which usually holds passwords"),
    (r"\.(key|pem|p12|pfx|ppk|jks|crt|cer)$", "a private key or certificate file"),
    (r"(^|/)id_(rsa|dsa|ecdsa|ed25519)[^/]*$", "a private sign-in key"),
    (r"(^|/)\.(htpasswd|npmrc|netrc)$", "a file that holds passwords or sign-in tokens"),
    (r"(^|/)\.user\.ini$", "a file that changes PHP's settings for a folder"),
    (r"(^|/)\.installed$", "the marker of a finished installation"),
    (r"\.log$|(^|/)error_log$", "a log file"),
    (r"(^|/)\.(claude[^/]*|openai|dev-team|github)(/|$)", "a planning or workflow folder"),
    (r"(^|/)\.git(/|$)", "a git folder"),
    (r"(^|/)\.(vscode|idea)(/|$)", "an editor's settings folder"),
    (r"(^|/)(\.DS_Store|Thumbs\.db)$", "a file a computer's file browser leaves behind"),
    (r"\.sw[op]$|~$|\.(bak\d*|backup|orig|old|rej|tmp|save)$", "an editor's leftover or a backup copy"),
    (r"(^|/)[^/]* copy( \d+)?(\.[^/]*)?$", "a copy somebody made by hand"),
    # "x.key " and "x.key." slipped past every pattern above that ends in "$".
    (r"[ .](/|$)", "a name that ends in a space or a dot, which hides its real ending"),
    (r"\.(sqlite|db)$|\.sql\.[^/]+$|(^|/)[^/]*dump[^/]*$", "a database file or a dump of one"),
    (r"\.(js|css)\.map$", "a source map, which gives away the unpacked source"),
    (r"(^|/)\.gitkeep$", "a placeholder the build should have removed"),
    (r"(^|/)\.git(ignore|attributes|modules)$", "a git settings file"),
    (r"^tools/|^docs/", "development tools or developer documents"),
    (r"(^|/)composer\.(json|lock)$|(^|/)package(-lock)?\.json$", "a development file"),
    (r"^(README|DEV_NOTES|FEATURES|CLAUDE)\.md$", "a developer document"),
    # Five real pages in _apps/ are called test.php ("test this connection"),
    # so the name can only be refused elsewhere.
    (r"^(?!_apps/)(.*/)?(test|tests|scratch)\.php$", "a test or scratch page outside the app pages"),
]
# INSIDE THE WEB FOLDER ONLY (the folder's name is put in front when the list
# is used). A real page, _apps/admin/system-info/phpinfo.php, must still ship:
# it is outside the web folder and checks who is asking. WEB_FOLDER_PHP above
# already refuses every unlisted PHP file in the web folder; these names are
# the second net for the best-known offenders.
DENY_IN_WEB_FOLDER = [
    (r"(.*/)?(phpinfo|php_info|info|test)[^/]*\.php$", "a page that prints the server's settings, or a test page"),
    (r"(.*/)?(adminer|phpmyadmin|pma|dbadmin|sqlbuddy|mysql)[^/]*(\.php|/|$)", "a database-admin page"),
]


class Report:
    """Collects every guard's result, so one run shows ALL the problems.

    Stopping at the first failure was rejected: somebody fixing a package
    would then meet the problems one build at a time.
    """

    # High enough that every refused file is named. It was 40, which hid most
    # of a long list behind "… and 60 more".
    MAX_LISTED = 250

    def __init__(self) -> None:
        self.problems = 0
        self.not_checked: list[str] = []

    def passed(self, guard: str, detail: str) -> None:
        print(f"PASS — {guard}: {detail}")

    def failed(self, guard: str, summary: str, items: list[str] | None = None) -> None:
        self.problems += 1
        print(f"FAIL — {guard}: {summary}")
        items = items or []
        for item in items[: self.MAX_LISTED]:
            print(f"  • {item}")
        if len(items) > self.MAX_LISTED:
            print(f"  … and {len(items) - self.MAX_LISTED} more")
        if os.environ.get("GITHUB_ACTIONS") == "true":
            # GitHub shows lines of this form at the top of the run's page.
            print(f"::error::Package check, {guard}: {summary}")

    def skipped(self, guard: str, why: str) -> None:
        self.not_checked.append(guard)
        print(f"NOT CHECKED — {guard}: {why}")


def run(cmd: list[str], **kwargs) -> subprocess.CompletedProcess:
    return subprocess.run(cmd, capture_output=True, **kwargs)


def git(repo: Path, *args: str, stdin: bytes | None = None) -> bytes:
    """Run git and return what it printed; raise with git's own words if it failed."""
    done = run(["git", "-C", str(repo), *args], input=stdin)
    if done.returncode != 0:
        raise RuntimeError(f"git {' '.join(args)} failed: {done.stderr.decode(errors='replace').strip()}")
    return done.stdout


def git_file(repo: Path, commit: str, path: str) -> bytes | None:
    """The bytes of one file at the commit, or None when git has no such file."""
    if run(["git", "-C", str(repo), "cat-file", "-e", f"{commit}:{path}"]).returncode != 0:
        return None
    return git(repo, "show", f"{commit}:{path}")


def read_setting(path: Path, pattern: str, what: str) -> str:
    """Read one setting out of a script beside this one, so it has one home."""
    found = re.search(pattern, path.read_text(encoding="utf-8"), re.MULTILINE)
    if found is None:
        raise RuntimeError(f"could not find {what} in {path}")
    return found.group(1)


def blob_id(data: bytes, like: str) -> str:
    """The name git gives these bytes. `like` is one of git's own, to pick the hash kind."""
    hasher = hashlib.sha1() if len(like) == 40 else hashlib.sha256()
    hasher.update(b"blob %d\0" % len(data))
    hasher.update(data)
    return hasher.hexdigest()


def is_removed(rel: str) -> bool:
    """True for a path under web/ that the build deliberately leaves out."""
    parts = rel.split("/")
    return parts[0] in REMOVED_FOLDERS or parts[-1] in REMOVED_FILE_NAMES


def to_package_path(rel: str, web_folder: str) -> str:
    """Rename the repository's web folder to the name it has in the package."""
    if rel == REPO_WEB_FOLDER or rel.startswith(REPO_WEB_FOLDER + "/"):
        return web_folder + rel[len(REPO_WEB_FOLDER):]
    return rel


def read_dompdf_list() -> tuple[str, str, dict[str, str]]:
    """The committed list of dompdf's files: (version, checksum of the
    download it was made from, {path inside dompdf/: SHA-256})."""
    version = zip_sum = ""
    files: dict[str, str] = {}
    for line in DOMPDF_LIST.read_text(encoding="utf-8").splitlines():
        if line.startswith("# version: "):
            version = line[len("# version: "):].strip()
        elif line.startswith("# release-zip-sha256: "):
            zip_sum = line[len("# release-zip-sha256: "):].strip()
        elif line and not line.startswith("#"):
            checksum, _, name = line.partition("  ")
            if re.fullmatch(r"[0-9a-f]{64}", checksum) is None or name == "":
                raise RuntimeError(f"{DOMPDF_LIST.name} has a line that is not '<checksum>  <path>': {line!r}")
            files[name] = checksum
    if version == "" or zip_sum == "" or len(files) == 0:
        raise RuntimeError(f"{DOMPDF_LIST.name} is missing its version line, its checksum line or its files")
    return version, zip_sum, files


def walk(root: Path) -> tuple[dict[str, Path], list[str], list[str]]:
    """Every real file, every folder, and everything that is neither."""
    files: dict[str, Path] = {}
    folders: list[str] = []
    odd: list[str] = []
    for current, dirnames, filenames in os.walk(root, followlinks=False):
        for name in list(dirnames):
            full = Path(current) / name
            rel = full.relative_to(root).as_posix()
            if full.is_symlink():
                odd.append(rel)
                dirnames.remove(name)
            else:
                folders.append(rel)
        for name in filenames:
            full = Path(current) / name
            rel = full.relative_to(root).as_posix()
            if full.is_symlink() or not full.is_file():
                odd.append(rel)
            else:
                files[rel] = full
    return files, folders, odd


class Package:
    """What is being checked, and what git says it should be."""

    def __init__(
        self, root: Path, repo: Path, commit: str, *,
        listing: tuple[dict[str, Path], list[str], list[str]] | None = None,
    ) -> None:
        """`listing` is given only by the pull-request form: git's own files,
        folders and links, with nothing built or downloaded."""
        self.root = root
        self.repo = repo
        self.commit = git(repo, "rev-parse", "--verify", f"{commit}^{{commit}}").decode().strip()
        self.web_folder = read_setting(
            HERE / "build.sh", r'^STAFF_WEB_FOLDER="([A-Za-z0-9_]+)"', "STAFF_WEB_FOLDER"
        )
        self.files, self.folders, self.odd = listing if listing is not None else walk(root)

        # git's list of web/ at the commit: package path -> git's name for the bytes.
        self.from_git: dict[str, str] = {}
        self.left_out: list[str] = []
        # The same for what is left out, so that guard_left_out can tell the
        # one file it lets through by its bytes and not by its name alone.
        self.left_out_blobs: dict[str, str] = {}
        for entry in git(repo, "ls-tree", "-r", "-z", self.commit, "--", "web/").split(b"\0"):
            if entry == b"":
                continue
            meta, path = entry.split(b"\t", 1)
            rel = path.decode("utf-8", errors="surrogateescape")[len("web/"):]
            if is_removed(rel):
                self.left_out.append(rel)
                self.left_out_blobs[rel] = meta.split()[2].decode()
                continue
            self.from_git[to_package_path(rel, self.web_folder)] = meta.split()[2].decode()

        self.dompdf_version, self.dompdf_zip_sum, dompdf_files = read_dompdf_list()
        self.dompdf_files = {DOMPDF_FOLDER + name: checksum for name, checksum in dompdf_files.items()}
        self.fallbacks = self._fallback_table() if listing is None else []

    def _fallback_table(self) -> list[tuple[str, str]]:
        """(package path, pinned fingerprint) for every fallback copy.

        Asked of tools/download-cdn-fallbacks.sh, which reads the constants in
        Asset.php. It is given git's copy of Asset.php, not the package's, so
        nothing from the package is run.
        """
        asset = git_file(self.repo, self.commit, "web/_core/Asset.php")
        if asset is None:
            raise RuntimeError("git has no web/_core/Asset.php at this commit")
        with tempfile.TemporaryDirectory() as tmp:
            asset_file = Path(tmp) / "Asset.php"
            asset_file.write_bytes(asset)
            done = run(["bash", str(HERE.parent / "download-cdn-fallbacks.sh"), "--list", "--asset-file", str(asset_file)])
        if done.returncode != 0:
            raise RuntimeError(
                "tools/download-cdn-fallbacks.sh --list failed: " + done.stderr.decode(errors="replace").strip()
            )
        table = []
        for line in done.stdout.decode().splitlines():
            _name, local, pinned, _url = line.split("\t")
            table.append((self.web_folder + local, pinned))
        if len(table) == 0:
            raise RuntimeError("the list of fallback copies came back empty")
        return table

    def positively_named(self) -> set[str]:
        """Files the kinds rule does not judge, because each is allowed by
        its exact name AND has its exact contents checked by another guard:
        the package's own three files (G9, G10, G1), the fallback copies
        (their fingerprints), dompdf's files (the committed list, G1) and
        the two files the build writes into _libraries/ (G1, G8)."""
        return (
            set(ROOT_FILES) | {path for path, _pinned in self.fallbacks} | set(self.dompdf_files)
            | {DOMPDF_FOLDER + "VERSION.txt", "_libraries/.htaccess"}
        )


# -----------------------------------------------------------------------------
# The guards
# -----------------------------------------------------------------------------

def guard_listing(pkg: Package, report: Report) -> None:
    guard = "G1 the package against git and the dompdf list"
    problems: list[str] = []

    for rel in pkg.odd:
        problems.append(f"{rel!r} — a link or a special file; a package holds only real files")

    for rel, wanted in sorted(pkg.from_git.items()):
        if rel not in pkg.files:
            problems.append(f"{rel} — in git at this commit, missing from the package")
        elif blob_id(pkg.files[rel].read_bytes(), wanted) != wanted:
            problems.append(f"{rel} — differs from the copy in git at this commit")

    # dompdf is not in git, so its files are held to the committed list in
    # exactly the same way. Until 12 October 2026 ANY file under
    # _libraries/dompdf/ was accepted unread, and three files dropped in
    # between the folder check and the zipping were shipped in a tagged zip.
    for rel, wanted in sorted(pkg.dompdf_files.items()):
        if rel not in pkg.files:
            problems.append(f"{rel} — on the list of dompdf's files, missing from the package")
        elif hashlib.sha256(pkg.files[rel].read_bytes()).hexdigest() != wanted:
            problems.append(f"{rel} — differs from dompdf's own file (its checksum is not the one on the list)")

    # What may be in the package beyond those two lists. Everything else is
    # refused by name.
    fallback_paths = {path for path, _pinned in pkg.fallbacks}
    added = 0
    for rel in sorted(set(pkg.files) - set(pkg.from_git) - set(pkg.dompdf_files)):
        if rel in ROOT_FILES or rel in fallback_paths or rel == DOMPDF_FOLDER + "VERSION.txt":
            added += 1
        elif rel == "_libraries/.htaccess":
            added += 1
            # The "refuse everything" file for the one folder the build
            # creates. Its wording has one home: the copy in _core/.
            if "_core/.htaccess" not in pkg.files:
                problems.append(f"{rel} — nothing in _core/ to have copied it from")
            elif pkg.files[rel].read_bytes() != pkg.files["_core/.htaccess"].read_bytes():
                problems.append(f"{rel} — differs from _core/.htaccess, which it must be a copy of")
        elif rel.startswith(DOMPDF_FOLDER):
            problems.append(f"{rel!r} — not one of dompdf's own files (it is not on the committed list)")
        else:
            problems.append(f"{rel!r} — not in git at this commit, and not one of the documented additions")

    # The two documents copied in from outside web/ must be git's own copies.
    for name, source in (("CHANGELOG.md", "CHANGELOG.md"), ("INSTALL.md", "docs/INSTALL.md")):
        if name in pkg.files and pkg.files[name].read_bytes() != git_file(pkg.repo, pkg.commit, source):
            problems.append(f"{name} — differs from {source} in git at this commit")

    if problems:
        report.failed(guard, f"{len(problems)} file(s) are not what git and the dompdf list hold at {pkg.commit[:12]}", problems)
    else:
        report.passed(
            guard,
            f"{len(pkg.from_git)} files from git and {len(pkg.dompdf_files)} of dompdf's are present and identical "
            f"byte for byte; {added} documented additions; nothing else",
        )


def is_refuse_file_of_left_out_folder(pkg: Package, rel: str) -> bool:
    """True for the ONE real file a left-out folder may hold: its own "refuse
    everything" file (roll-out package F5).

    That file protects nothing but the folder it sits in, so leaving it out
    with the folder loses nothing. It is recognised by ALL of: it is the
    .htaccess directly inside _includes or _functions, and git holds for it
    exactly the bytes it holds for _core/.htaccess (whose own wording and
    checksum guard_allow_list holds). A rules file anywhere else under those
    folders, or one whose wording has drifted, is still a real file that
    would be lost, and still fails."""
    parts = rel.split("/")
    shipped = pkg.from_git.get("_core/.htaccess")
    return (
        len(parts) == 2 and parts[0] in REMOVED_EMPTY_FOLDERS and parts[1] == ".htaccess"
        and shipped is not None and pkg.left_out_blobs.get(rel) == shipped
    )


def guard_left_out(pkg: Package, report: Report) -> None:
    """Says what the package leaves out, and fails when that would lose a real file."""
    guard = "what the package leaves out"
    lost = [
        f"web/{rel} — a real file in a folder the package leaves out, so it would be missing from every "
        "installation. Either move it, or start shipping the folder: take it out of REMOVED_EMPTY_FOLDERS "
        "in build.sh AND in check-package.py"
        for rel in sorted(pkg.left_out)
        if rel.split("/")[0] in REMOVED_EMPTY_FOLDERS and rel.split("/")[-1] not in REMOVED_FILE_NAMES
        and not is_refuse_file_of_left_out_folder(pkg, rel)
    ]
    if lost:
        report.failed(guard, f"{len(lost)} real file(s) would be left out without anybody deciding it", lost)
        return
    shown = ", ".join("web/" + rel for rel in sorted(pkg.left_out)[:15])
    more = f", and {len(pkg.left_out) - 15} more" if len(pkg.left_out) > 15 else ""
    report.passed(
        guard,
        f"{len(pkg.left_out)} file(s) in git are left out on purpose (placeholders, the 'refuse everything' file "
        f"of each folder that is left out, and the three folders nothing deploys): {shown}{more}",
    )


def guard_no_channel_file_in_git(pkg: Package, report: Report) -> None:
    """Refuses a commit at which git holds web/.channel.

    That file says which copy of the portal ONE installation is. The
    repository must never hold one (it is on the ignore list, .gitignore): a
    committed one would make every checkout, and every channel deployed from
    it, claim the same channel. In a package it would also replace the word
    of every installation it is unpacked over (see G9 at the top).

    Until 13 October 2026 nothing refused it. An independent check committed
    one that said "live", and it passed: the build then wrote a file of the
    same name and word into every package, so the package matched git byte
    for byte. The build writes no such file any more, and G9 refuses a
    package that holds one; this guard stays because it says, at the pull
    request, exactly what is wrong and what to do.

    WHAT THIS CANNOT DO: it sees only what git holds at the commit. A
    .channel file in a working folder that git does not track is not seen,
    and cannot get into a package, which is made from git."""
    guard = "no channel file in git"
    if ".channel" in pkg.from_git:
        report.failed(
            guard,
            "web/.channel — git holds a channel file at this commit, and the repository must never hold one. It "
            "says which copy of the portal ONE installation is (live, beta, alpha or dev), so a committed one "
            "would make every checkout and every channel deployed from it claim the same channel; each deploy "
            "writes its own, and the installer writes an installation's. Take it out of git (git rm --cached web/.channel; it is already "
            "on the ignore list) and use the PORTAL_ENV setting for local work",
        )
    else:
        report.passed(guard, "git holds no web/.channel at this commit")


def looks_like_archive(data: bytes) -> bool:
    return any(data[where:where + len(start)] == start for where, start in ARCHIVE_STARTS)


def kind_of(name: str) -> str:
    """The ending of a file's name in small letters (".php"), the whole name
    for one that is only an ending (".htaccess"), or "" when it has none."""
    if name.startswith(".") and "." not in name[1:]:
        return name.lower()
    return "." + name.rsplit(".", 1)[1].lower() if "." in name else ""


def place_allowed(rel: str, places: tuple[str, ...], web_folder: str) -> bool:
    for place in places:
        place = place.replace(WEB, web_folder)
        if (place.endswith("/") and rel.startswith(place)) or rel == place:
            return True
    return False


# -----------------------------------------------------------------------------
# A picture or a font must be what it says ALL THE WAY THROUGH.
# -----------------------------------------------------------------------------
# Each function follows the file from its first part to its last, by the
# lengths the file itself states, and returns why it is not acceptable, or
# None. Before 12 October 2026 only the first 4 to 8 bytes were compared, so
# anything could follow them. None of these understands what is INSIDE a
# well-formed part (see WHAT THIS CANNOT DO at the top).

def png_problem(data: bytes) -> str | None:
    """A PNG is its 8 opening bytes, then parts: length, name, bytes, and a
    checksum of that part. The first is the header, the last is the end."""
    if not data.startswith(PNG_START):
        return "its contents do not begin like one"
    at, first = len(PNG_START), True
    while at < len(data):
        length = int.from_bytes(data[at:at + 4], "big")
        name = data[at + 4:at + 8]
        end = at + 12 + length
        if end > len(data):
            return "one of its parts runs past the end of the file"
        if zlib.crc32(data[at + 4:end - 4]) != int.from_bytes(data[end - 4:end], "big"):
            return "one of its parts does not match its own checksum"
        if first and name != b"IHDR":
            return "its first part is not a picture's header"
        first, at = False, end
        if name == b"IEND":
            return None if at == len(data) else f"{len(data) - at} byte(s) follow the picture's end"
    return "it has no end mark"


def gif_problem(data: bytes) -> str | None:
    """A GIF is a header, an optional table of colours, then pictures and
    notes, each made of counted blocks, and one end mark (a semicolon)."""
    if not data.startswith((b"GIF87a", b"GIF89a")) or len(data) < 14:
        return "its contents do not begin like one"

    def table(flags: int) -> int:
        return 3 * (2 << (flags & 7)) if flags & 0x80 else 0

    def past_blocks(at: int) -> int:
        while at < len(data):
            if data[at] == 0:
                return at + 1
            at += 1 + data[at]
        return len(data) + 1

    at = 13 + table(data[10])
    while at < len(data):
        if data[at] == 0x3B:
            return None if at + 1 == len(data) else f"{len(data) - at - 1} byte(s) follow the picture's end"
        if data[at] == 0x21:
            at = past_blocks(at + 2)
        elif data[at] == 0x2C and at + 10 < len(data):
            at = past_blocks(at + 11 + table(data[at + 9]))
        else:
            return "it holds a part that is neither a picture nor a note"
    return "it has no end mark"


def ico_problem(data: bytes) -> str | None:
    """An ICO is a list of pictures (where each starts and how long it is),
    then the pictures. Every byte must belong to the list or to one picture."""
    if not data.startswith(b"\x00\x00\x01\x00"):
        return "its contents do not begin like one"
    count = int.from_bytes(data[4:6], "little")
    at = 6 + 16 * count
    if count == 0 or at > len(data):
        return "its list of pictures is broken"
    spans = []
    for number in range(count):
        entry = data[6 + 16 * number:22 + 16 * number]
        size, start = int.from_bytes(entry[8:12], "little"), int.from_bytes(entry[12:16], "little")
        picture = data[start:start + size]
        if start < at or size == 0 or start + size > len(data):
            return "one of its pictures lies outside the file"
        if picture.startswith(PNG_START):
            inner = png_problem(picture)
            if inner is not None:
                return "a picture inside it is not a real PNG: " + inner
        elif int.from_bytes(picture[:4], "little") not in (40, 108, 124):
            # A bitmap inside an icon begins with the length of its own
            # header, and only these three lengths exist.
            return "one of its pictures is neither a PNG nor a bitmap"
        spans.append((start, start + size))
    for start, end in sorted(spans):
        if start > at:
            return f"{start - at} byte(s) belong to none of its pictures"
        at = max(at, end)
    return None if at == len(data) else f"{len(data) - at} byte(s) follow its last picture"


def icns_problem(data: bytes) -> str | None:
    """An ICNS states its whole length, then holds entries that each state
    their own. The entries must end exactly where the file does."""
    if not data.startswith(b"icns") or len(data) < 8:
        return "its contents do not begin like one"
    if int.from_bytes(data[4:8], "big") != len(data):
        return "the length it states is not the file's length"
    at = 8
    while at < len(data):
        size = int.from_bytes(data[at + 4:at + 8], "big")
        if size < 8 or at + size > len(data):
            return "one of its parts runs past the end of the file"
        at += size
    return None


def woff2_problem(data: bytes) -> str | None:
    """A WOFF2 font states its whole length, lists its tables, then holds ONE
    packed stream whose length it also states. This reads the list only to
    find where the stream starts, and requires the stream to end where the
    file ends. It CANNOT unpack the stream (Python has no Brotli reader), so
    it cannot tell a real font's data from other bytes of the same length.
    The format: https://www.w3.org/TR/WOFF2/ sections 4 and 5."""
    if not data.startswith(b"wOF2") or len(data) < 48:
        return "its contents do not begin like one"

    def number(where: int, width: int = 4) -> int:
        return int.from_bytes(data[where:where + width], "big")

    def past_varying_number(at: int) -> int:
        # A number written in 1 to 5 bytes; the top bit says "more follow".
        for used in range(1, 6):
            if at + used > len(data):
                break
            if not data[at + used - 1] & 0x80:
                return at + used
        return len(data) + 1

    if data[4:8] == b"ttcf":
        return ("it is a collection of several fonts, which this check cannot follow; if one must ship, teach "
                "woff2_problem to read its second list")
    if number(8) != len(data):
        return "the length it states is not the file's length"
    if number(14, 2) != 0:
        return "a field that must be zero is not"
    if number(28) or number(32) or number(40) or number(44):
        return "it carries a block of notes or of private data, which can hold anything (no font here has one)"
    at = 48
    for _table in range(number(12, 2)):
        if at >= len(data):
            return "its list of tables runs past the end of the file"
        flags = data[at]
        at += 1
        if flags & 0x3F == 0x3F:
            # A table with an unusual name carries the name in full.
            reshaped_by_default = data[at:at + 4] in (b"glyf", b"loca")
            at += 4
        else:
            reshaped_by_default = flags & 0x3F in (10, 11)   # glyf and loca
        at = past_varying_number(at)
        # A second number follows only for a table that was reshaped before
        # packing: glyf and loca are unless they say not, every other table
        # is not unless it says so.
        if (flags >> 6 == 0) == reshaped_by_default:
            at = past_varying_number(at)
    end = at + number(20)
    if end > len(data) or len(data) - end > 3 or any(data[end:]):
        return "its packed data does not end where the file ends"
    return None


SVG_SPACE = "{http://www.w3.org/2000/svg}"
# Parts of a drawing that run a program or pull another document in.
SVG_REFUSED_PARTS = ("script", "foreignobject", "iframe", "embed", "object", "audio", "video", "handler", "listener")
SVG_REFUSED_TEXT = (
    (b"<!doctype", "a DOCTYPE, which can define text that grows without end"),
    (b"<!entity", "an entity definition"),
    (b"<script", "a script"),
    (b"<foreignobject", "a foreignObject, which holds a web page inside the drawing"),
    (b"javascript:", "a javascript: link"),
)
# A style that fetches something: url(…) to anything but a part of this same
# drawing, an @import, or the old expression(…).
SVG_STYLE_REACHES_OUT = re.compile(r"""url\(\s*['"]?\s*(?!#)|@import|expression\(""", re.IGNORECASE)


def svg_problem(data: bytes) -> str | None:
    """A drawing is text a browser acts on. It is read twice: as plain text
    (so a refused word counts even inside a comment), and as the tree of parts
    a browser would build. Checked against all 62 drawings in web/ on
    12 October 2026: none holds a script, an event attribute, a foreignObject
    or a link of ANY kind, so every link out of the drawing is refused. A
    drawing that must show a picture from inside itself (href="data:…") will
    be refused too; loosen that here, on purpose, if it is ever needed."""
    if b"\0" in data:
        return "its contents are not text"
    lowered = data.lower()
    for mark, why in SVG_REFUSED_TEXT:
        if mark in lowered:
            return f"it holds {why}"
    if re.search(rb"<\?(?!xml[\s?])", data):
        return "it holds an instruction other than the opening <?xml line (a stylesheet link, or PHP)"
    if re.match(rb"""\s*<\?xml[^>]*encoding\s*=\s*["'](?!utf-8["'])""", lowered):
        return "it says it is written in something other than UTF-8, so its words cannot be read reliably"
    try:
        root = ElementTree.fromstring(data.decode("utf-8"))
    except (UnicodeDecodeError, ValueError, ElementTree.ParseError) as error:
        return f"it cannot be read as a drawing ({type(error).__name__}: {str(error)[:80]})"
    if root.tag != SVG_SPACE + "svg":
        return "its outermost part is not <svg>"
    for part in root.iter():
        tag = part.tag.rsplit("}", 1)[-1].lower()
        if tag in SVG_REFUSED_PARTS:
            return f"it holds a <{tag}> part"
        for key, value in part.attrib.items():
            key = key.rsplit("}", 1)[-1].lower()
            if key.startswith("on"):
                return f"it holds an event attribute ({key}=), which runs a script"
            if key in ("href", "src") and not value.strip().startswith("#"):
                return f"it points outside itself ({key}=\"{value.strip()[:40]}\")"
            if key == "attributename" and value.strip().lower().endswith("href"):
                return "it animates a link, which can turn it into a script"
            if SVG_STYLE_REACHES_OUT.search(value):
                return f"its {key}= attribute fetches something from outside the drawing"
        if tag == "style" and SVG_STYLE_REACHES_OUT.search(part.text or ""):
            return "its style fetches something from outside the drawing"
    return None


WHOLE_FILE_TESTS = {
    ".png": png_problem, ".gif": gif_problem, ".ico": ico_problem, ".icns": icns_problem,
    ".woff2": woff2_problem, ".svg": svg_problem,
}


def foreign_mark(data: bytes) -> str | None:
    for pattern, what in FOREIGN_MARKS:
        if pattern.search(data):
            return f"it holds {what}"
    return None


def risky_htaccess_lines(data: bytes) -> list[str]:
    """The lines of a .htaccess that could run a file as a program or list a
    folder. A line the server would join to the next (it ends in a backslash)
    is joined first, so a word cannot be split across two lines to hide it."""
    lines = data.decode("utf-8", errors="replace").replace("\r\n", "\n").replace("\\\n", "").split("\n")
    risky = []
    for line in lines:
        found = HTACCESS_RISKY_LINE.fullmatch(line)
        if found is None:
            continue
        words = found.group(2).split()
        if found.group(1).lower() == "options" and words and all(word.startswith("-") for word in words):
            continue
        risky.append(line.strip()[:80])
    return risky


def guard_allow_list(pkg: Package, report: Report) -> None:
    guard = "the allow list (names, kinds, places)"
    problems: list[str] = []
    web = pkg.web_folder

    for rel in sorted(list(pkg.files) + pkg.folders + pkg.odd):
        for part in rel.split("/"):
            if NAME_CHARACTERS.fullmatch(part) is None:
                problems.append(f"{rel!r} — its name has a character other than letters, digits, dot, dash and underscore")
                break
            if part.endswith("."):
                problems.append(f"{rel!r} — its name ends in a dot, which Windows and some hosting panels drop")
                break

    named = pkg.positively_named()
    archives = {path.replace(WEB, web): entry for path, entry in ALLOWED_ARCHIVES.items()}
    htaccess = {path.replace(WEB, web): entry for path, entry in ALLOWED_HTACCESS.items()}
    web_php = {web + "/" + name for name in WEB_FOLDER_PHP}
    for rel, full in sorted(pkg.files.items()):
        data = full.read_bytes()
        # First, because it holds whatever else the file is: named, an
        # archive, a picture. Every folder on the way down is looked at too.
        if rel.startswith(web + "/") and rel not in web_php and any(
            PHP_LIKE_PART.fullmatch(part) for name in rel.split("/")[1:] for part in name.split(".")[1:]
        ):
            problems.append(
                f"{rel!r} — inside the web folder, with a PHP ending somewhere in its name. A web server may run "
                "such a file as PHP for anybody who asks, whatever its last ending is. Only the files WEB_FOLDER_PHP "
                "names may have one"
            )
            continue
        if rel in archives:
            if hashlib.sha256(data).hexdigest() != archives[rel][0]:
                problems.append(
                    f"{rel} — an archive that is allowed by name, but this is not the copy that was opened and "
                    "read (its checksum differs). Open the new one, then change its checksum in ALLOWED_ARCHIVES"
                )
            continue
        if looks_like_archive(data):
            problems.append(
                f"{rel!r} — an archive. Nothing looks inside one, so it is refused unless ALLOWED_ARCHIVES names "
                "it with its checksum"
            )
            continue
        if rel in named:
            continue

        name = rel.rsplit("/", 1)[-1]
        kind = kind_of(name)
        if name == ".htaccess":
            checksum = hashlib.sha256(data).hexdigest()
            as_written = f'WEB + "{rel[len(web):]}"' if rel.startswith(web + "/") else f'"{rel}"'
            if rel not in htaccess:
                problems.append(
                    f"{rel} — a file of web-server rules that ALLOWED_HTACCESS does not name. One line in such a "
                    "file can switch PHP on for pictures, so each one is named. If it should ship, READ it, then "
                    f'add this line to ALLOWED_HTACCESS: {as_written}: ("{checksum}", "<why it ships>"),'
                )
            elif checksum != htaccess[rel][0]:
                problems.append(
                    f"{rel} — web-server rules that are allowed by name, but this is not the copy that was read "
                    "(its checksum differs). Read what changed, then put the new checksum in ALLOWED_HTACCESS: "
                    f"{checksum}"
                )
            if rel != web + "/.htaccess":
                problems += [
                    f"{rel} — holds a line that could make the server run a file as a program, or list a folder: "
                    f"{line!r}. Rules like that belong only in the web folder's own .htaccess"
                    for line in risky_htaccess_lines(data)
                ]
            continue
        if "." in name and name.rsplit(".", 1)[1] != name.rsplit(".", 1)[1].lower():
            # ".Php" is accepted as PHP by some servers and not by others,
            # and one such file skipped the syntax check until 12 October
            # 2026. No file in web/ needs a capital in its ending. (dompdf
            # has three, each named on its own list, which is read above.)
            problems.append(f"{rel!r} — its ending is not all small letters ({name.rsplit('.', 1)[1]}); rename it")
            continue
        if kind not in ALLOWED_KINDS:
            problems.append(f"{rel!r} — a kind of file nobody allowed ({kind or 'no ending at all'})")
            continue
        elif rel in web_php:
            pass
        elif not place_allowed(rel, ALLOWED_KINDS[kind][0], web):
            places = ", ".join(place.replace(WEB, web) for place in ALLOWED_KINDS[kind][0])
            problems.append(f"{rel} — a {kind} file outside the place that kind is allowed ({places})")
            continue
        elif kind == ".sql" and SQL_NAME.fullmatch(rel) is None:
            problems.append(f"{rel} — not named like the install script or a numbered upgrade (123_words.sql)")
            continue

        if kind in WHOLE_FILE_TESTS:
            # The mark first: "it holds a PHP opening tag" tells somebody
            # more than "one of its parts runs past the end".
            why = foreign_mark(data) or WHOLE_FILE_TESTS[kind](data)
            if why is not None:
                problems.append(f"{rel} — called {kind}, but {why}")
        elif b"\0" in data:
            problems.append(f"{rel} — called {kind or name}, which is text, but its contents are not text")

    if problems:
        report.failed(
            guard,
            f"{len(problems)} name(s) or file(s) are not positively allowed, so the package is refused. WHAT TO DO: "
            "if a file should not ship, remove it from web/. If it should, add its kind or its place to "
            "ALLOWED_KINDS (or WEB_FOLDER_PHP, ALLOWED_ARCHIVES or ALLOWED_HTACCESS) in "
            "tools/package/check-package.py ON PURPOSE, with the reason, in the same commit",
            problems,
        )
    else:
        report.passed(
            guard,
            f"all {len(pkg.files)} files and {len(pkg.folders)} folders have plain names; every file is of an "
            f"allowed kind in an allowed place, or is named exactly; every picture, font and drawing was followed "
            f"to its end; {len(archives)} archive and {len(htaccess)} .htaccess files, allowed by name and checksum",
        )


def deny_hits(paths: list[str], web_folder: str) -> list[str]:
    patterns = [(re.compile(pattern, re.IGNORECASE), reason) for pattern, reason in DENY]
    patterns += [
        (re.compile("^" + re.escape(web_folder) + "/" + pattern, re.IGNORECASE), reason + ", inside the web folder")
        for pattern, reason in DENY_IN_WEB_FOLDER
    ]
    hits = []
    for rel in sorted(paths):
        for pattern, reason in patterns:
            if pattern.search(rel):
                hits.append(f"{rel!r} — {reason}")
                break
    return hits


def guard_deny_list(pkg: Package, report: Report) -> None:
    guard = "G2/G4 the deny list"
    # dompdf's own files are left to G1: each is on the committed list by
    # name and by checksum, which is a stronger test than its name, and the
    # publisher's release holds names this list refuses elsewhere on purpose
    # (composer.json, for one). A file in that folder that is NOT on the list
    # is still read here, and G1 refuses it as well.
    paths = [rel for rel in pkg.files if rel not in pkg.dompdf_files] + pkg.folders + pkg.odd
    hits = deny_hits(paths, pkg.web_folder)
    if hits:
        report.failed(guard, f"{len(hits)} path(s) must never be in a package", hits)
    else:
        report.passed(guard, f"none of {len(pkg.files)} files or {len(pkg.folders)} folders matches it")


# The line the installer writes (web/_install/index.php, where it builds
# auth_creds.php): "    'db_pass' => '<the password>',". Either quote, any
# spacing, "db_password" too, and the same key as JSON ("db_pass": …).
DB_LOGIN_SHAPE = re.compile(rb"""['"]db_pass(word)?['"]\s*(=>|:)""", re.IGNORECASE)
# The encryption key as the installer writes it: bin2hex(random_bytes(32)).
ENC_KEY_SHAPE = re.compile(rb"\s*[0-9a-fA-F]{64}\s*")
# The first lines mysqldump and phpMyAdmin write at the top of a dump.
SQL_DUMP_SHAPE = re.compile(rb"mysql ?dump|mariadb ?dump|phpmyadmin sql dump|dump completed on", re.IGNORECASE)
# The installer itself has to hold the login's shape: it is the code that
# writes the file. G1 has already proved this file is git's own copy.
MAY_HOLD_DB_LOGIN_SHAPE = ("_install/index.php",)


def guard_contents(pkg: Package, report: Report) -> None:
    guard = "contents (the database login, the encryption key, a dump)"
    problems = []
    for rel, full in sorted(pkg.files.items()):
        data = full.read_bytes()
        if rel not in MAY_HOLD_DB_LOGIN_SHAPE and DB_LOGIN_SHAPE.search(data):
            problems.append(f"{rel!r} — holds the database login in the shape the installer writes it")
        if ENC_KEY_SHAPE.fullmatch(data):
            problems.append(f"{rel!r} — is 64 hexadecimal characters and nothing else, the shape of the encryption key")
        if rel.lower().endswith(".sql") and SQL_DUMP_SHAPE.search(data[:4096]):
            problems.append(f"{rel!r} — begins like a dump of a real database, not like an upgrade script")
    if problems:
        report.failed(guard, f"{len(problems)} file(s) hold what must never be in a package", problems)
    else:
        report.passed(guard, f"none of {len(pkg.files)} files holds any of the three shapes")


def guard_top_level(pkg: Package, report: Report) -> None:
    guard = "G3 top-level names"
    allowed = set(CODE_FOLDERS) | {pkg.web_folder} | set(ROOT_FILES)
    present = {entry.name for entry in pkg.root.iterdir()}
    problems = [
        f"{name!r} — not one of the names a package has at its top. If the deny list also names it above, it "
        "must simply go; otherwise decide whether it ships, and if so add it to CODE_FOLDERS or ROOT_FILES"
        for name in sorted(present - allowed)
    ]
    # INSTALL.md has its own guard (G10), which says why a package needs it.
    problems += [f"{name} — expected, and missing" for name in sorted(allowed - present - {"INSTALL.md"})]
    if problems:
        report.failed(guard, f"{len(problems)} problem(s) at the top of the package", problems)
    else:
        report.passed(guard, "only the listed names: " + " ".join(sorted(present)))


def unsafe_bits(mode: int) -> str | None:
    """Why these permission bits are not acceptable, or None when they are."""
    if mode & 0o022:
        return "writable by group or others"
    if mode & 0o7000:
        return "carries a set-user, set-group or sticky bit"
    return None


def guard_permission_bits(pkg: Package, report: Report) -> None:
    """Folder only. A zip's bits are read from the zip itself (G11-G13),
    because unpacking one here gives files this user's own default bits."""
    guard = "permission bits"
    problems = []
    for rel in sorted(["."] + list(pkg.files) + pkg.folders):
        why = unsafe_bits(stat.S_IMODE(os.lstat(pkg.root / rel).st_mode))
        if why is not None:
            problems.append(f"{rel!r} — {why}")
    if problems:
        report.failed(
            guard,
            f"{len(problems)} file(s) or folder(s) carry permission bits a package must not have; on shared "
            "hosting another account may be able to rewrite them",
            problems,
        )
    else:
        report.passed(guard, f"none of {len(pkg.files)} files or {len(pkg.folders) + 1} folders is writable by group or others")


def gitleaks_run(source: Path, work: Path) -> tuple[int, list[dict] | None, list[str]]:
    """One run of gitleaks over a folder. Returns its exit code, its finds
    (None when it left no report that can be read) and its last lines."""
    work.mkdir()
    found_file = work / "found.json"
    # --no-git: read the folder as it is, not a history. --redact: never put a
    # secret it finds into a log or into its report. The report is asked for
    # so that a find can be NAMED: until 12 October 2026 the build log said
    # only "leaks found: 2", with no file and no line.
    # --ignore-gitleaks-allow: a line marked "gitleaks:allow" is searched like
    # any other; a comment inside a packaged file must not be able to switch
    # the search off. --gitleaks-ignore-path and the working folder point at
    # an empty folder, so no list of "finds to ignore" lying about on the
    # build machine is picked up.
    done = run([
        "gitleaks", "detect", "--no-git", "--source", str(source), "--config", str(GITLEAKS_SETTINGS),
        "--gitleaks-ignore-path", str(work), "--ignore-gitleaks-allow", "--no-banner", "--redact",
        "--exit-code", "1", "--report-format", "json", "--report-path", str(found_file),
    ], cwd=work, env={key: value for key, value in os.environ.items() if not key.startswith("GITLEAKS_")})
    tail = (done.stdout + done.stderr).decode(errors="replace").strip().splitlines()[-8:]
    try:
        finds = json.loads(found_file.read_text(encoding="utf-8"))
        if not isinstance(finds, list) or any(not isinstance(find, dict) for find in finds):
            finds = None
    except (OSError, ValueError):
        finds = None
    return done.returncode, finds, tail


CANARY_NAME = "made-up-key-shown-first.txt"


def canary_text() -> str:
    """A made-up private key, for G5 to show gitleaks before believing it.

    Put together here, when it is needed, so that this file never holds one
    written out: a scanner reading the repository would report this very
    file. The letters come from a fixed checksum, so it is the same made-up
    key on every run and opens nothing anywhere."""
    letters = base64.b64encode(hashlib.sha256(b"webms-intra package checker: a made-up key").digest() * 3).decode()
    dashes = "-" * 5
    return f"{dashes}BEGIN RSA PRIVATE KEY{dashes}\n{letters[:64]}\n{letters[64:128]}\n{dashes}END RSA PRIVATE KEY{dashes}\n"


def guard_secrets(pkg: Package, report: Report, allow_no_gitleaks: bool) -> None:
    guard = "G5 gitleaks"
    if shutil.which("gitleaks") is None:
        if allow_no_gitleaks:
            report.skipped(guard, "gitleaks is not installed, and --allow-no-gitleaks was given")
        else:
            report.failed(
                guard,
                "gitleaks is not installed, so the search for secrets did not run. Install it, or give "
                "--allow-no-gitleaks for a build that will not be published",
            )
        return

    # TWO RUNS, because gitleaks on its own does not read every file. Found
    # by reading gitleaks 8.21.2 (config/gitleaks.toml, detect/directory.go)
    # after an independent check shipped a private key inside an .svg:
    #   1. BY NAME. Its built-in settings skip .gif .svg .woff2 .ttf, any name
    #      that ends in zip, pdf, doc, bin or exe, everything under a folder
    #      called node_modules, and scripts called jquery… or swagger-ui….
    #      Settings of ours cannot take a name OFF that list, only add to it.
    #   2. BY CONTENTS. It reads a file 10,000 bytes at a time, and drops the
    #      rest of the file as soon as one of those reads BEGINS like a
    #      program, an archive or a font ("wOF2", "PK", even the two letters
    #      "MZ"). So a script that merely starts with "MZ" is never searched.
    # The first run reads the folder as it is, because a few of its rules go
    # by a file's real name. The second reads every file again from a copy in
    # which each file has a number for a name and is cut into pieces that
    # begin with blank lines, so neither reason can apply. A find in a piece
    # is turned back into the real file's name and line.
    with tempfile.TemporaryDirectory() as tmp:
        # FIRST, CAN IT FIND ANYTHING? It is shown one file holding a made-up
        # private key. A program that reports nothing there has proved nothing
        # by reporting nothing about the package. WHAT WAS WRONG: any program
        # called gitleaks was believed. One that only wrote an empty report
        # passed, and the zip it "searched" then checked as a release (the
        # check of 13 October 2026). The same would have passed a real
        # gitleaks whose rules did not load.
        shown = Path(tmp) / "shown-first"
        shown.mkdir()
        (shown / CANARY_NAME).write_text(canary_text(), encoding="utf-8")
        code, finds, tail = gitleaks_run(shown, Path(tmp) / "shown-first-work")
        if code != 1 or not finds or any(Path(str(find.get("File", ""))).name != CANARY_NAME for find in finds):
            report.failed(
                guard,
                "the program called gitleaks was shown one file holding a made-up private key and did not report it "
                f"(exit code {code}, {'no report' if finds is None else str(len(finds)) + ' find(s)'}). A search that cannot "
                "find that has proved nothing, so its silence about this package is not accepted. Check that it is the "
                "real gitleaks and that it reads " + GITLEAKS_SETTINGS.name + ". Its last lines: " + " | ".join(tail),
            )
            return

        code, finds, tail = gitleaks_run(pkg.root, Path(tmp) / "as-it-is")
        runs = [("the folder as it is", code, finds, tail)]
        places = set()
        for find in finds or []:
            where = os.path.relpath(find.get("File", "?"), pkg.root) if os.path.isabs(find.get("File", "")) else find.get("File", "?")
            places.add(f"{where}:{find.get('StartLine', '?')} — {find.get('RuleID', '?')} (the value is not shown)")

        copy = Path(tmp) / "plain-names"
        copy.mkdir()
        pieces: list[tuple[str, int]] = []   # piece number -> (real name, line breaks before the piece)
        for rel, full in sorted(pkg.files.items()):
            data = full.read_bytes()
            start = 0
            while len(data) > 0:
                (copy / f"{len(pieces):07d}.txt").write_bytes(b"\n" * GITLEAKS_PAD + data[start:start + GITLEAKS_PIECE])
                pieces.append((rel, data.count(b"\n", 0, start)))
                if start + GITLEAKS_PIECE >= len(data):
                    break
                start += GITLEAKS_STEP
        # The made-up key again, as the LAST piece, cut and padded like every
        # other. This run must report it: that shows the pieces really are
        # read by this version of gitleaks, which is the whole point of them.
        canary_piece = len(pieces)
        (copy / f"{canary_piece:07d}.txt").write_bytes(b"\n" * GITLEAKS_PAD + canary_text().encode())
        code, finds, tail = gitleaks_run(copy, Path(tmp) / "plain-names-work")
        canary_seen = False
        real_finds = None if finds is None else []
        for find in finds or []:
            try:
                number = int(Path(find["File"]).stem)
                if number == canary_piece:
                    canary_seen = True
                    continue
                real_finds.append(find)
                rel, lines_before = pieces[number]
                places.add(f"{rel}:{lines_before + find['StartLine'] - GITLEAKS_PAD} — {find['RuleID']} (the value is not shown)")
            except (KeyError, ValueError, IndexError, TypeError):
                real_finds.append(find)
                places.add(f"a find that could not be traced back to a file: {str(find.get('File'))[:80]}")
        # With the made-up key found and nothing else, this run's own exit
        # code is 1; it is judged below as the run would have ended without it.
        runs.append((
            f"every file again under a plain name ({len(pieces)} pieces)",
            0 if code == 1 and canary_seen and not real_finds else code, real_finds, tail,
        ))

    # Each run must have ended with code 0, left a report, and listed nothing.
    # Anything else is a failure: a gitleaks that crashed, was not really
    # gitleaks, or wrote no report has proved nothing.
    wrong = []
    for what, code, finds, tail in runs:
        if finds is None:
            wrong.append(f"{what}: it ended with code {code} and left no report that can be read. Its last lines: " + " | ".join(tail))
        elif code != 0 and not finds:
            wrong.append(f"{what}: it ended with code {code} but named no place. Its last lines: " + " | ".join(tail))
        elif code == 0 and finds:
            wrong.append(f"{what}: it ended with code 0 but its report lists {len(finds)} find(s), so its exit code cannot be trusted")
        elif code != 0:
            wrong.append(f"{what}: it ended with code {code} (1 means it found something)")
    if not canary_seen:
        wrong.append(
            "every file again under a plain name: the made-up key put among the pieces was NOT reported, so this "
            "program does not read the pieces and that run has proved nothing"
        )
    if wrong or places:
        report.failed(
            guard,
            f"the search for secrets did not come back clean; {len(places)} place(s) named below",
            wrong + sorted(places),
        )
    else:
        report.passed(
            guard,
            f"gitleaks found the made-up key it was shown, alone and among the pieces, and nothing else in two "
            f"runs: the folder as it is, and all {len(pkg.files)} files again under plain names in {len(pieces)} "
            "pieces, so that none is skipped for its name or for how it begins",
        )


def own_domains() -> tuple[str, ...]:
    """The names G6 looks for, read from their one home.

    That file is read as Python and its list taken out of it. It is NOT run as
    a program and asked to print the names: an emptied or broken file run that
    way prints nothing and ends with exit code 0, which would read as "no
    names to look for", and G6 would then pass every package. Here a missing
    file, or an empty or misshapen list, is an error, and the guard fails.
    (compile() and exec() rather than an import, so that no __pycache__
    folder is left in the repository by a build.)"""
    scope: dict = {"__name__": "own_domains_home", "__file__": str(OWN_DOMAINS_HOME)}
    try:
        exec(compile(OWN_DOMAINS_HOME.read_text(encoding="utf-8"), str(OWN_DOMAINS_HOME), "exec"), scope)  # noqa: S102 — our own file, beside this one
    except SystemExit as stop:
        # A file that ENDS THE PROGRAM when it is read (sys.exit(0) at its
        # top) used to end this checker there and then, with exit code 0 and
        # every later guard not run. It is an error like any other.
        raise RuntimeError(f"{OWN_DOMAINS_HOME.name} ended the program when it was read (exit code {stop.code})") from None
    names = scope.get("OWN_DOMAINS")
    if not isinstance(names, tuple) or len(names) == 0 or any(not isinstance(name, str) or len(name) < 4 for name in names):
        raise RuntimeError(f"{OWN_DOMAINS_HOME.name} has no usable OWN_DOMAINS list (a tuple of names, each 4 characters or more)")
    return names


def guard_own_domain(pkg: Package, report: Report) -> None:
    """Why this reads the files itself and does not call that check with
    `--root <folder>`: run that way it reads TEXT files only. It skips every
    picture, font, PDF and archive by its ending, and any file with a zero
    byte in its first 4,096. A package is a public download, so here every
    byte of every file is read, and every file's name."""
    guard = "G6 our own domain"
    try:
        names = own_domains()
    except Exception as error:  # noqa: BLE001 — a guard that could not run is a failure, never a quiet pass
        report.failed(guard, f"the list of our own domains could not be read, so this guard did not run: {error}")
        return
    needles = [name.lower().encode() for name in names]
    hits = []
    for rel, full in sorted(pkg.files.items()):
        if any(needle in rel.lower().encode() for needle in needles):
            hits.append(f"{rel} — in the file's name")
        for number, line in enumerate(full.read_bytes().lower().split(b"\n"), start=1):
            if any(needle in line for needle in needles):
                hits.append(f"{rel}:{number}")
    if hits:
        report.failed(
            guard,
            f"{len(hits)} line(s) carry a domain of ours; no web address may be built in (#500)",
            hits,
        )
    else:
        report.passed(guard, f"none of the {len(needles)} name(s) on the one list is written out whole in any of {len(pkg.files)} files")


def guard_php_lint(pkg: Package, report: Report) -> None:
    guard = "G7 php -l"
    if shutil.which("php") is None:
        report.failed(guard, "php is not installed, so no file was checked")
        return
    # _vendor/ is left out, as the deploy leaves it out: it is somebody
    # else's code, held in git. dompdf IS read (it was not, before 12 October
    # 2026): a file of its that this PHP cannot read means no PDF is made.
    targets = [
        full for rel, full in sorted(pkg.files.items())
        # In small letters: before 12 October 2026 a file called Mixed.Php
        # was accepted as PHP by the allow list and never read here. (The
        # allow list now refuses such an ending; this is the second net.)
        if rel.lower().endswith(".php") and not rel.startswith("_vendor/")
    ]
    if len(targets) == 0:
        report.failed(guard, "found no .php file to check, so the search itself is broken")
        return

    def lint_one(full: Path) -> str | None:
        # Both things, as for a batch below: exit code 0 AND PHP's own "No
        # syntax errors" line. The exit code alone was trusted here until
        # 12 October 2026, and a php whose exit code got lost on the way (a
        # wrapper that pipes its output) made a broken file read as clean.
        done = run(["php", "-l", str(full)])
        if done.returncode == 0 and done.stdout.count(b"No syntax errors detected in ") == 1:
            return None
        return f"{full.relative_to(pkg.root).as_posix()} — {(done.stdout + done.stderr).decode(errors='replace').strip()[:300]}"

    def lint(batch: list[Path]) -> list[str]:
        # PHP 8.3 and later read several files in one go, which is some
        # twenty times quicker than starting PHP once per file. The batch
        # counts as clean ONLY when PHP said "No syntax errors" once for
        # every file in it. Anything else (a mistake, an older PHP that read
        # only the first file, a crash) sends each file through on its own.
        done = run(["php", "-l", *[str(full) for full in batch]])
        if done.returncode == 0 and done.stdout.count(b"No syntax errors detected in ") == len(batch):
            return []
        return [result for result in map(lint_one, batch) if result is not None]

    batches = [targets[start:start + 40] for start in range(0, len(targets), 40)]
    with ThreadPoolExecutor(max_workers=8) as pool:
        problems = [problem for result in pool.map(lint, batches) for problem in result]
    if problems:
        report.failed(guard, f"{len(problems)} of {len(targets)} PHP file(s) have a mistake in them", problems)
    else:
        report.passed(guard, f"all {len(targets)} PHP files outside _vendor/ read cleanly, dompdf's included")


def guard_dompdf(pkg: Package, report: Report) -> None:
    guard = "G8 dompdf"
    script = HERE.parent / "download-dompdf.sh"
    pinned = read_setting(script, r'^DOMPDF_VERSION="([^"$]+)"', "DOMPDF_VERSION")
    pinned_sum = read_setting(script, r'^DOMPDF_SHA256="([0-9a-f]{64})"', "DOMPDF_SHA256")
    block = read_setting(script, r"^REQUIRED_FILES=\(\n((?:\s+\"[^\"]+\"\n)+)\)", "REQUIRED_FILES")
    required = re.findall(r'"([^"]+)"', block)
    problems = []
    # The list and the pin must have been made from the same download. If
    # somebody changes one without the other, nothing builds until they agree.
    if (pkg.dompdf_version, pkg.dompdf_zip_sum) != (pinned, pinned_sum):
        problems.append(
            f"{DOMPDF_LIST.name} was made from dompdf {pkg.dompdf_version} ({pkg.dompdf_zip_sum[:12]}…), but "
            f"tools/download-dompdf.sh pins {pinned} ({pinned_sum[:12]}…). Make the list again: "
            "bash tools/download-dompdf.sh --write-list"
        )
    marker = pkg.files.get(DOMPDF_FOLDER + "VERSION.txt")
    # The fetch script writes this marker last, so a half-unpacked copy has none.
    if marker is None:
        problems.append(f"{DOMPDF_FOLDER}VERSION.txt — missing, so dompdf was never fully unpacked")
    elif marker.read_bytes() != (pinned + "\n").encode():
        problems.append(f"{DOMPDF_FOLDER}VERSION.txt holds {marker.read_bytes()[:40]!r}; it must be exactly {pinned!r} and a line end")
    for name in required:
        full = pkg.files.get(DOMPDF_FOLDER + name)
        if full is None or full.stat().st_size == 0:
            problems.append(f"{DOMPDF_FOLDER}{name} — missing or empty; the portal cannot make a PDF without it")
        if DOMPDF_FOLDER + name not in pkg.dompdf_files:
            problems.append(f"{name} — a file the portal loads, but it is not on {DOMPDF_LIST.name}")
    if problems:
        report.failed(guard, "dompdf is incomplete, the wrong version, or its list does not match its pin", problems)
    else:
        report.passed(guard, f"version {pinned}; the list of its {len(pkg.dompdf_files)} files was made from the pinned download; the {len(required)} files the portal loads are there")


def fingerprint_matches(data: bytes, pinned: str) -> bool:
    """`pinned` is "sha256:<hex>", or what a web page carries: "sha384-<base64>"
    (several may be given, separated by spaces; a browser accepts any one)."""
    for one in pinned.split():
        if one.startswith("sha256:"):
            if hashlib.sha256(data).hexdigest() == one[len("sha256:"):]:
                return True
        else:
            kind, _, wanted = one.partition("-")
            if kind in ("sha256", "sha384", "sha512") and base64.b64encode(hashlib.new(kind, data).digest()).decode() == wanted:
                return True
    return False


def guard_fallbacks(pkg: Package, report: Report) -> None:
    guard = "fallback copies of the outside libraries"
    problems = []
    for rel, pinned in pkg.fallbacks:
        if rel not in pkg.files:
            problems.append(f"{rel} — missing; pages would have no fallback when the outside copy cannot be reached")
        elif not fingerprint_matches(pkg.files[rel].read_bytes(), pinned):
            problems.append(f"{rel} — its bytes do not match the fingerprint {pinned}")
    if problems:
        report.failed(guard, f"{len(problems)} of {len(pkg.fallbacks)} are missing or wrong", problems)
    else:
        report.passed(guard, f"all {len(pkg.fallbacks)} are present and match their fingerprints")


def expected_version(pkg: Package, channel: str, tag: str | None, problems: list[str]) -> str | None:
    """The version the package must carry; adds a problem for every disagreement."""
    source = git_file(pkg.repo, pkg.commit, "web/_core/version.php")
    found = re.search(r"^return\s+'([^']+)'\s*;", (source or b"").decode("utf-8", errors="replace"), re.MULTILINE)
    if found is None:
        problems.append("could not read the version out of web/_core/version.php in git at this commit")
        return None
    code_version = found.group(1)
    if tag is None:
        return code_version

    parts = re.fullmatch(r"v(\d+\.\d+\.\d+)(?:-([0-9A-Za-z.]+))?", tag)
    if parts is None:
        problems.append(f"the tag {tag!r} is not of the form v1.2.3 or v1.2.3-beta.1")
        return None
    number, suffix = parts.group(1), parts.group(2)
    if number != code_version:
        problems.append(
            f"version.php says {code_version}; the tag says {tag}. Bump the version first, then tag the "
            "commit that set it"
        )
    # A live package has a plain version; a beta package says so in its tag.
    # Stricter than the earlier design, which only refused "-beta" and "-rc"
    # on live: any suffix at all on a live tag is refused, so nothing odd can
    # be labelled as a finished release.
    if channel == "live" and suffix is not None:
        problems.append(f"the channel is live, but the tag {tag} carries the suffix -{suffix}")
    if channel == "beta" and (suffix is None or re.match(r"(beta|rc)", suffix) is None):
        problems.append(f"the channel is beta, so the tag must end -beta… or -rc…; it is {tag}")
    if channel == "alpha":
        problems.append("alpha packages are never tagged; they are build-only")
    return tag[1:]


def guard_labels(pkg: Package, report: Report, channel: str, tag: str | None) -> None:
    guard = "G9 channel, version and tag"
    problems: list[str] = []
    version = expected_version(pkg, channel, tag, problems)

    # A package must NOT carry the file (see G9 at the top). Looked for as a
    # file and as a folder, at the top, where the portal reads it.
    if CHANNEL_FILE in pkg.files or CHANNEL_FILE in pkg.folders:
        problems.append(
            f"{CHANNEL_FILE} — a package must not carry this file: unpacked over an installation it would REPLACE that "
            "installation's own word, turning a test copy live or a live portal into a test copy. The word travels "
            "as the Channel line of VERSION.txt, and the installer writes the file only when there is none. Take the "
            "line that writes it out of build.sh"
        )

    version_file = pkg.files.get("VERSION.txt")
    if version_file is None:
        problems.append("VERSION.txt — missing")
    else:
        lines = version_file.read_text(encoding="utf-8").splitlines()
        pairs = [tuple(line.split(": ", 1)) for line in lines]
        wanted = [("Product", PRODUCT), ("Version", version), ("Channel", channel), ("Commit", pkg.commit)]
        if [pair[0] for pair in pairs] != ["Product", "Version", "Channel", "Commit", "Built"] or any(len(pair) != 2 for pair in pairs):
            problems.append("VERSION.txt must be five 'Key: value' lines: Product, Version, Channel, Commit, Built")
        else:
            for (key, value), (_key, expected) in zip(pairs, wanted):
                if expected is not None and value != expected:
                    problems.append(f"VERSION.txt says {key}: {value}; it must be {expected}")
            if re.fullmatch(r"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ", pairs[4][1]) is None:
                problems.append(f"VERSION.txt's Built line is not a UTC time like 2026-10-12T07:00:00Z: {pairs[4][1]}")

    if problems:
        report.failed(guard, "the package's labels do not agree", problems)
    else:
        report.passed(guard, f"version {version}, channel {channel}, commit {pkg.commit[:12]}" + (f", tag {tag}" if tag else ", no tag"))


def guard_guide(pkg: Package, report: Report) -> None:
    """G1 has already held INSTALL.md to docs/INSTALL.md in git, byte for byte.
    This says whether it is there at all, and whether it is about THIS package."""
    guard = "G10 the installation guide"
    guide = pkg.files.get("INSTALL.md")
    if guide is None:
        report.failed(
            guard,
            "INSTALL.md is not in the package. A customer with only a hosting panel cannot install without it, "
            "so no package is made without it: write docs/INSTALL.md at this commit",
        )
    elif pkg.web_folder not in guide.read_text(encoding="utf-8", errors="replace"):
        report.failed(guard, f"INSTALL.md never mentions {pkg.web_folder}, the web folder this package really has")
    else:
        report.passed(guard, f"present, and it names the web folder {pkg.web_folder}")


def too_large(sizes: dict[str, int]) -> list[str]:
    """Why these files, by their sizes, are more than a package may unpack
    to: one line for each file over the limit, one for the total. Used on a
    folder's real sizes, and on the sizes a zip STATES before it is unpacked."""
    problems = [
        f"{name!r} — {size / 1048576:.1f} MB; no one file in a package may be larger than {MAX_FILE_BYTES // 1048576} MB"
        for name, size in sorted(sizes.items()) if size > MAX_FILE_BYTES
    ]
    total = sum(sizes.values())
    if total > MAX_UNPACKED_BYTES:
        problems.append(
            f"all {len(sizes)} files together are {total / 1048576:.1f} MB; a package may unpack to no more than "
            f"{MAX_UNPACKED_BYTES // 1048576} MB"
        )
    return problems


def guard_sizes(pkg: Package, report: Report) -> None:
    guard = "sizes"
    sizes = {rel: full.stat().st_size for rel, full in pkg.files.items()}
    problems = too_large(sizes)
    if problems:
        report.failed(
            guard,
            f"{len(problems)} size(s) are beyond what a package may hold. If a file really has grown that large, raise "
            "MAX_FILE_BYTES or MAX_UNPACKED_BYTES on purpose, with the reason",
            problems,
        )
    else:
        report.passed(guard, f"{len(sizes)} files, {sum(sizes.values()) / 1048576:.1f} MB in all, the largest {max(sizes.values(), default=0) / 1048576:.1f} MB")


def check_folder(root: Path, args: argparse.Namespace, repo: Path, report: Report, *, bits_on_disk: bool) -> None:
    """Run every guard on a folder."""
    pkg = Package(root, repo, args.commit)
    guard_listing(pkg, report)
    guard_left_out(pkg, report)
    guard_no_channel_file_in_git(pkg, report)
    guard_allow_list(pkg, report)
    guard_deny_list(pkg, report)
    guard_contents(pkg, report)
    guard_top_level(pkg, report)
    guard_sizes(pkg, report)
    if bits_on_disk:
        guard_permission_bits(pkg, report)
    guard_secrets(pkg, report, args.allow_no_gitleaks)
    guard_own_domain(pkg, report)
    guard_php_lint(pkg, report)
    guard_dompdf(pkg, report)
    guard_fallbacks(pkg, report)
    guard_labels(pkg, report, args.channel, args.tag)
    guard_guide(pkg, report)


# -----------------------------------------------------------------------------
# The zip
# -----------------------------------------------------------------------------

def zip_bytes_unaccounted_for(zip_path: Path, archive: zipfile.ZipFile, size: int) -> list[str]:
    """Every byte of a package's zip must belong to an entry, to the list of
    entries, or to the end record. Returns what does not.

    WHAT WAS WRONG: only the entries were looked at. A zip with a made-up key
    and our own name in its archive comment, or with a megabyte of other
    bytes in front of its first entry, or 30 KB after its end, passed once
    its checksum file had been rewritten (the check of 13 October 2026). None
    of those bytes is a file, so no other guard ever read them.

    Read from the zip's own bytes: the 30-byte header in front of each entry
    says how long its name and its extra field are; the entry's packed data
    follows; the next entry must begin exactly there. The list of entries
    must begin where the last entry ends, and the 22-byte end record must be
    the last thing in the file. What it cannot see is at the top of this
    file."""
    problems: list[str] = []
    if archive.comment:
        problems.append(f"it carries a comment of {len(archive.comment)} byte(s); a package's zip carries none, and nothing reads one")
    spans = []
    with zip_path.open("rb") as raw:
        for info in archive.infolist():
            raw.seek(info.header_offset)
            head = raw.read(30)
            if len(head) != 30 or head[:4] != b"PK\x03\x04":
                problems.append(f"{info.filename!r} — no entry begins where the list of entries says this one does")
                continue
            if info.flag_bits & 0x08:
                # build.sh writes to a file, where zip never needs this form;
                # with it, an entry's end cannot be worked out from its header.
                problems.append(f"{info.filename!r} — written with its sizes AFTER its data, which the build's zip never does")
                continue
            name_length, extra_length = int.from_bytes(head[26:28], "little"), int.from_bytes(head[28:30], "little")
            spans.append((info.header_offset, info.header_offset + 30 + name_length + extra_length + info.compress_size, info.filename))
        raw.seek(max(0, size - 22))
        end_record = raw.read(22)
    expected = 0
    for start, end, name in sorted(spans):
        if start > expected:
            problems.append(
                f"{start - expected} byte(s) that belong to no entry, "
                + ("in front of the first entry" if expected == 0 else f"in front of {name!r}")
            )
        elif start < expected:
            problems.append(f"{name!r} — begins inside the entry before it")
        expected = max(expected, end)
    if expected != archive.start_dir:
        problems.append(f"{abs(archive.start_dir - expected)} byte(s) between the last entry and the list of entries are not accounted for")
    if len(end_record) != 22 or end_record[:4] != b"PK\x05\x06" or end_record[20:22] != b"\x00\x00":
        problems.append("it does not END with its end record: something follows it")
    elif archive.start_dir + int.from_bytes(end_record[12:16], "little") != size - 22:
        problems.append("byte(s) between the list of entries and the end record are not accounted for")
    return problems


def zip_is_safe_to_open(zip_path: Path, report: Report) -> bool:
    """G11-G13. Returns False when the zip must not even be unpacked."""
    guard = "G11-G13 the zip file"
    problems: list[str] = []

    size = zip_path.stat().st_size
    if not MIN_ZIP_BYTES <= size <= MAX_ZIP_BYTES:
        problems.append(
            f"it is {size / 1048576:.1f} MB; a real package is between 5 MB and 80 MB "
            "(smaller means something is missing, larger means something got in)"
        )

    sums = Path(str(zip_path) + ".sha256")
    actual = hashlib.sha256(zip_path.read_bytes()).hexdigest()
    if not sums.is_file():
        problems.append(f"{sums.name} is missing; every package is published with its checksum beside it")
    else:
        # The form `sha256sum -c` reads: "<checksum>  <file name>".
        parts = sums.read_text(encoding="utf-8").split()
        if len(parts) != 2 or parts[1].lstrip("*") != zip_path.name:
            problems.append(f"{sums.name} must hold one line: the checksum, then {zip_path.name}")
        elif parts[0] != actual:
            problems.append(
                f"the zip does not match {sums.name}: the zip is {actual}, the file says {parts[0]}. "
                "It was changed after its checksum was written"
            )

    names: list[str] = []
    try:
        with zipfile.ZipFile(zip_path) as archive:
            names = archive.namelist()
            for info in archive.infolist():
                name = info.filename
                # A zip can name a file anywhere on the disk it is unpacked on.
                if name.startswith(("/", "\\")) or "\\" in name or ":" in name or ".." in name.split("/"):
                    problems.append(f"{name!r} — an entry name that could be unpacked outside the folder")
                mode = info.external_attr >> 16
                if stat.S_IFMT(mode) == stat.S_IFLNK:
                    problems.append(f"{name} — a link, not a real file")
                # The bits a customer's "extract" gives each file. They are
                # read here because unpacking in this script would lose them.
                if info.create_system != 3 or mode == 0:
                    problems.append(f"{name!r} — the zip does not record this entry's permission bits, so they cannot be checked")
                elif unsafe_bits(stat.S_IMODE(mode)) is not None:
                    problems.append(f"{name!r} — {unsafe_bits(stat.S_IMODE(mode))} ({stat.filemode(mode)})")
            if len(names) != len(set(names)):
                problems.append("the same entry name appears twice")
            problems += zip_bytes_unaccounted_for(zip_path, archive, size)
            # What it SAYS it unpacks to, before a byte is unpacked. Reading
            # an entry stops at the size stated for it, so a zip cannot
            # unpack to more than this.
            problems += too_large({info.filename: info.file_size for info in archive.infolist()})
            # Every file is read to its end, which is where its checksum is
            # compared. Python's own testzip() was used before; it lets a
            # broken compressed stream through as a crash instead of a result.
            for info in archive.infolist():
                try:
                    with archive.open(info) as member:
                        while member.read(1 << 20):
                            pass
                except Exception as error:  # noqa: BLE001 — a bad checksum, a broken stream, anything at all
                    problems.append(f"{info.filename} — damaged inside the zip ({type(error).__name__})")
    except zipfile.BadZipFile as error:
        problems.append(f"it cannot be opened as a zip: {error}")

    if problems:
        report.failed(guard, f"{zip_path.name} is not acceptable; it was NOT unpacked", problems)
        return False
    report.passed(
        guard,
        f"{zip_path.name}: {size / 1048576:.1f} MB, matches its .sha256, not damaged, {len(names)} safe entries, "
        "none a link, none writable by group or others, no comment, and every byte of it accounted for",
    )
    return True


def check_zip_name(zip_path: Path, args: argparse.Namespace, report: Report) -> None:
    guard = "the zip's file name"
    name = zip_path.name
    problems = []
    if not (name.startswith(ZIP_PREFIX) and name.endswith(".zip")):
        problems.append(f"it must be called {ZIP_PREFIX}<version>….zip")
    if args.tag is not None and name != f"{ZIP_PREFIX}{args.tag[1:]}.zip":
        problems.append(f"a tagged build is called {ZIP_PREFIX}{args.tag[1:]}.zip")
    if problems:
        report.failed(guard, f"{name} is wrongly named", problems)
    else:
        report.passed(guard, name)


# -----------------------------------------------------------------------------
# The pull-request form: the same rules, over what git holds
# -----------------------------------------------------------------------------

def check_tree(tree: str, commit: str, repo: Path) -> int:
    """Runs every guard that does not need a built package over git's own
    list of web/. Before 12 October 2026 this ran the deny list alone, over
    names alone, and passed 36 files it should not have."""
    if tree.strip("/") != "web":
        print(f"FAIL — --tree checks web/, the folder the package is made from, not {tree!r}; {END_MARK}.")
        return 1
    report = Report()
    web_folder = read_setting(HERE / "build.sh", r'^STAFF_WEB_FOLDER="([A-Za-z0-9_]+)"', "STAFF_WEB_FOLDER")
    entries = [entry for entry in git(repo, "ls-tree", "-r", "-z", commit, "--", "web/").split(b"\0") if entry]
    if len(entries) == 0:
        print(f"FAIL — git holds no file under web/ at {commit}, so nothing was checked; {END_MARK}.")
        return 1

    # The names are taken from git's list and never from a disk: a Mac's disk
    # treats "x.KEY" and "x.key" as one file, and would show only one of them.
    # Each file's bytes are saved under a number, and looked up by its name.
    wanted: list[tuple[str, str]] = []
    links: list[str] = []
    for entry in entries:
        meta, path = entry.split(b"\t", 1)
        mode, _kind, blob = meta.split()
        rel = path.decode("utf-8", errors="surrogateescape")[len("web/"):]
        if is_removed(rel):
            continue
        rel = to_package_path(rel, web_folder)
        if mode in (b"100644", b"100755"):
            wanted.append((rel, blob.decode()))
        else:
            links.append(rel)

    with tempfile.TemporaryDirectory() as tmp:
        # One git process hands over every file: "<name> blob <size>", a line
        # end, the bytes, a line end.
        stream = git(repo, "cat-file", "--batch", stdin="".join(blob + "\n" for _rel, blob in wanted).encode())
        files: dict[str, Path] = {}
        at = 0
        for number, (rel, blob) in enumerate(wanted):
            line_end = stream.index(b"\n", at)
            name, kind, size = stream[at:line_end].split()
            if name.decode() != blob or kind != b"blob":
                raise RuntimeError(f"git did not hand over web/{rel} as a file")
            files[rel] = Path(tmp) / f"{number:06d}"
            files[rel].write_bytes(stream[line_end + 1:line_end + 1 + int(size)])
            at = line_end + 1 + int(size) + 1
        folders = sorted({rel.rsplit("/", count)[0] for rel in list(files) + links for count in range(1, rel.count("/") + 1)})

        print(f"Checking what git holds under web/ at {commit}, named as the package would name it")
        pkg = Package(Path(tmp), repo, commit, listing=(files, folders, links))
        if links:
            report.failed(
                "links", f"{len(links)} entry(ies) are links or sub-repositories; a package holds only real files",
                [repr(rel) for rel in links],
            )
        guard_left_out(pkg, report)
        guard_no_channel_file_in_git(pkg, report)
        guard_allow_list(pkg, report)
        guard_deny_list(pkg, report)
        guard_contents(pkg, report)
        guard_sizes(pkg, report)
    if report.problems > 0:
        print(f"FAIL — {report.problems} guard(s) failed for web/ at {commit}; {END_MARK}.")
        return 1
    print(f"PASS — all {len(files)} files git holds under web/ at {commit} may be packaged; {END_MARK}.")
    return 0


# -----------------------------------------------------------------------------

def main() -> int:
    parser = argparse.ArgumentParser(description="Check an installation package before it can be downloaded.")
    parser.add_argument("target", nargs="?", help="the package folder, or the zip")
    parser.add_argument("--commit", help="the commit the package was built from")
    parser.add_argument("--channel", choices=CHANNEL_WORDS, help="the channel word the package must carry")
    parser.add_argument("--tag", help="the release tag, for a build that will be published")
    parser.add_argument("--repo", default=str(HERE.parent.parent), help="the repository (default: the one this script is in)")
    parser.add_argument("--allow-no-gitleaks", action="store_true")
    parser.add_argument("--tree", help="'web': check what git holds under web/ (with --commit, default HEAD)")
    args = parser.parse_args()
    global STARTED
    STARTED = True
    repo = Path(args.repo).resolve()

    if args.tree is not None:
        return check_tree(args.tree, args.commit or "HEAD", repo)
    if args.target is None or args.commit is None or args.channel is None:
        parser.error("give the package (a folder or a zip), --commit and --channel")

    target = Path(args.target).resolve()
    report = Report()
    print(f"Checking {target}")

    if args.tag is not None and args.allow_no_gitleaks:
        # Only a tagged build is ever published, so this is the one place the
        # switch is shut out for good.
        report.failed("the allow switch", "a build with --tag may be published, so --allow-no-gitleaks is not accepted with it")
    elif target.is_dir():
        check_folder(target, args, repo, report, bits_on_disk=True)
    elif target.is_file():
        if zip_is_safe_to_open(target, report):
            with tempfile.TemporaryDirectory() as tmp:
                with zipfile.ZipFile(target) as archive:
                    archive.extractall(tmp)
                check_folder(Path(tmp), args, repo, report, bits_on_disk=False)
            check_zip_name(target, args, report)
    else:
        report.failed("the package", f"{target} is neither a folder nor a file")

    if report.problems > 0:
        print(f"FAIL — {report.problems} guard(s) failed for {target}; {END_MARK}.")
        return 1
    if report.not_checked:
        print(
            "PASS, BUT NOT FIT TO PUBLISH — every guard that ran passed, and these did not run: "
            + "; ".join(report.not_checked) + f". {target}; {END_MARK}."
        )
        return 0
    print(f"PASS — every guard passed for {target}; {END_MARK}.")
    return 0


# True once the arguments have been read: from then on, nothing but main()
# returning may end this run with exit code 0.
STARTED = False

if __name__ == "__main__":
    try:
        result = main()
    except SystemExit as stop:
        # Python's reader of arguments ends the run itself (--help with 0, a
        # wrong option with 2), and that is left alone. ANYTHING ELSE that
        # ends the run before main() returns has stopped it early. Until
        # 13 October 2026 such an end kept its own exit code, and that code
        # could be 0.
        if not STARTED or stop.code not in (None, 0):
            raise
        print("FAIL — the checker was ended before its last line by something it read, and proved nothing")
        sys.exit(1)
    except Exception as error:  # noqa: BLE001 — any crash must read as a failure, never as silence
        print(f"FAIL — the checker stopped early and proved nothing: {type(error).__name__}: {error}")
        sys.exit(1)
    sys.exit(result)
