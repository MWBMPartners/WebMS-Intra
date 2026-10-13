# The installation package

These scripts build the zip a customer downloads to install or upgrade the portal using nothing but a hosting panel (#499), and check it before anybody can download it.

> **A package installs and upgrades.** On 13 October 2026 one was built, unpacked into an empty folder, and installed by following `docs/INSTALL.md`, on MySQL 8.0.36 and on 8.4.11. Then two later packages were unpacked over each: one that differs only in its version number, and one that brings a database upgrade. See "Proved by installing" below for what that covered, and for the fault the first of those two found. **Not proved:** a real hosting panel and its file manager, and Apache (the installs ran behind PHP's own web server, with a small script standing in for the web folder's `.htaccess`).

## What is here

| File | What it does |
| --- | --- |
| `build.sh` | Builds the package from git at one commit, and runs the checker on the folder and on the zip. |
| `check-package.py` | The guards. Every one fails the build. Its header lists them and says what they cannot catch. |
| `dompdf-files.sha256` | Every file inside dompdf's release, with a checksum each. Written by `../download-dompdf.sh --write-list`, never by hand. |
| `gitleaks-settings.toml` | The settings the secrets search is always given: its own built-in rules, and nothing from the build machine. |
| `package-selftest.sh` | Plants faults and requires each to fail for its own reason. It proves the guards catch what is planted there, and nothing more. |
| `../download-dompdf.sh` | Fetches the PDF library, checked against a pinned checksum and then file by file against the list. The deploy uses it too. |
| `../download-cdn-fallbacks.sh` | Fetches the local copies of Bootstrap and Font Awesome that pages fall back to, each checked against the fingerprint in `web/_core/Asset.php`. |

## Building one

```bash
bash tools/package/build.sh <commit> <live|beta|alpha> <a new, empty folder>
```

Needs the internet (it downloads dompdf and the fallback copies) and these programs: `git tar zip unzip curl shasum openssl php python3`, plus `gitleaks` (a program that searches for secrets). PHP must be 8.4 or later, the same as the portal.

The output folder receives `stage/` (the package, unpacked), the zip, and the zip's `.sha256` checksum file; the last two are `rw-r--r--` whoever builds. The last line says `reached the end`. If it says `BUILD FAILED` instead, nothing from that run may be used. A build that is cancelled (the signals TERM, INT or HUP) stops at the end of the step it is in, removes its zip and checksum, and says `BUILD FAILED`. A build that is killed outright (KILL) can do neither: it may leave a checksum file or a `.part` file behind, but never a zip under its final name that was not checked.

One switch exists, for a machine without `gitleaks`: `--allow-no-gitleaks`. It skips ONE guard, the search for secrets, and only when `gitleaks` really is absent. It is refused when `--tag` is given, it puts `NOT-FOR-RELEASE` in the zip's name, and the last line then says `NOT FIT TO PUBLISH`. The self-test holds all three (cases `B17`, `B18` and `B19`); until 13 October 2026 nothing did, and the whole self-test passed with the name taken out of the build.

There is no switch for a missing guide. A commit without `docs/INSTALL.md` is not built, and the checker refuses a package without `INSTALL.md` whatever it is told. (`--allow-missing-guide` existed until 13 October 2026, from the weeks before the guide was written. It is now refused as an unknown option.)

A build that will be published names its tag: `--tag v1.5.0` with `live`, or `--tag v1.5.0-beta.1` with `beta`. The tag must agree with `web/_core/version.php`. `--tag` is a label the build checks against the version; it does not look for a git tag of that name.

## The rules the build follows

- **Everything comes from git at the commit named, never from the working folder.** A file git does not hold cannot get in. That matters because a working folder holds the real database login.
- **A package may hold only what is positively allowed.** Every file must be of a kind, and in a place, that the allow list in `check-package.py` names. A list of bad names can never be complete; the first version of these guards had only that, and 43 files it had not thought of were shipped in a test. The deny list is kept as a second net.
- **No zip reaches the output folder unless it passed.** The zip is made in a temporary folder, checked there, and moved in only after it has passed and its checksum file is written. The two runs of the checker cannot be switched off.
- **The checker counts as passed only when it says so.** Its exit code must be 0 and its last line must be a `PASS` line containing `the run reached its end`. An empty or crashed checker gives neither.
- **The zip has no wrapper folder.** Its top level is the portal's folder, so "extract here" is the same act for a first install and for an upgrade.
- **Nothing of the customer's is in it.** No folder called `_auth_keys`, `_uploads` or `_backups` is in the package at any depth, even empty, so replacing a folder wholesale during an upgrade cannot wipe the encryption key.
- **No package holds a `.channel` file, and git must not hold one either.** The file says which copy of the portal an installation is (`live`, `beta`, `alpha`), and it belongs to the installation. Until 13 October 2026 the build wrote one into every package, so unpacking a newer package REPLACED the installation's own word: a test copy given a live package called itself live and lost its staff sign-in gate, and a live portal given a beta package shut out every member. The word now travels only as the `Channel:` line of `VERSION.txt`. The installer's finish reads that line and writes `.channel` only when there is none (`installWriteChannelFile()` in `web/_install/install_common.php`); an upgrade never runs the installer, so nothing an upgrade does can touch the file. The checker refuses a package that holds one (G9), and a commit at which git holds `web/.channel`.
- **Our own domains have one list.** The domain guard takes its names from `OWN_DOMAINS` in `tools/audit-checks/check_no_hardcoded_domains.py` and keeps none of its own. If that file is missing, its list is empty, or it ends the program when it is read, the guard fails. It reads every file itself, pictures and fonts included, because that check, run on a folder, reads text files only. **It finds a name only where it is written out whole.** Split across two lines, with a letter written as `%xx` or `&#x..;`, in base64, or inside the packed text of a PNG, a name gets through; nothing here decodes anything.
- **The zip holds nothing but its files.** It carries no comment, and every byte of it belongs to an entry, to the list of entries or to the end record. What it says it unpacks to is held to two limits before anything is unpacked: 16 MB for one file, 150 MB in all. (Only the zip's own size used to be measured, and a 150 MB script travelled inside an 11 MB zip.)
- **No file or folder is writable by group or others**, whoever builds: the build sets the bits, and a guard reads them back from the folder and from the zip.
- **The search for secrets reads every file.** `gitleaks`, left to itself, skips some files for their names (`.svg`, `.gif`, `.woff2`, anything under `node_modules`) and some for how they begin (it stops reading a file as soon as a 10,000-byte stretch of it begins like a program or an archive). So it is run twice: on the folder as it is, and on a copy in which every file has a plain numbered name and is cut into small overlapping pieces. A find in a piece is reported under the real file's name and line.
- **The search has to show it can find something.** Before its silence is believed, `gitleaks` is shown a made-up private key, alone and again as one of the pieces, and must report it both times. A program that only calls itself `gitleaks`, or a real one whose rules did not load, reports neither and fails the guard. The key is put together when it is needed; it is not written out in any file here.
- **One placeholder is let through, and nothing else.** `gitleaks-settings.toml` lets through a find whose secret is exactly the word `YOUR_TOKEN`: the scheduled-job command the Help page and the release notes tell an administrator to copy holds it, and the real `gitleaks` reports that line. A longer word or a real token in the same command is still reported (case `C26`).

## What is in a package

Everything git holds under `web/`, except: the three placeholder folders (`private_html`, `public_html_landing`, `public_html_redir`), the two empty ones (`_includes`, `_functions`) and every `.gitkeep`. Each file left out is printed by name. A real file in `_includes` or `_functions` stops the build, because leaving it out would lose it without anybody deciding that. The one file let through there is the folder's own "refuse everything" `.htaccess`, and only while it is byte for byte the copy in `_core`: it protects nothing but the folder it sits in, so it is left out with the folder.

Added by the build: `_libraries/dompdf/` (every file on `dompdf-files.sha256`, plus `VERSION.txt`, which the fetch script writes last, and nothing else); the fallback copies under `<web folder>/assets/`; and at the top `VERSION.txt` (five lines: product, version, channel, commit, time built), `CHANGELOG.md` and `INSTALL.md`. No `.channel`: see the rules above.

### The kinds of file a package may hold

Counted from git at commit `84c6180` (12 October 2026): 1,207 files under `web/`, of which 5 are left out.

| Kind | Files | Allowed where | Why it ships |
| --- | --- | --- | --- |
| `.php` | 841 | `_apps/`, `_core/`, `_install/`, `_lang/`, `_vendor/` | The portal's own code, all outside the web folder. |
| `.php` | 8 | the web folder, by exact name only | The only PHP a browser may reach directly: `index.php`, `error.php`, `api-docs/index.php`, `manifest.php`, `offline/index.php`, `openapi.php`, `robots.php`, `sitemap.php`. |
| `.sql` | 208 | `_sql/`, named `full_schema.sql` or `123_words.sql` | The install script and the numbered database upgrades. |
| `.svg` | 62 | the web folder | Icons, logos and brand artwork. |
| `.woff2` | 21 | the web folder | Fonts a browser fetches. |
| `.js` | 17 | the web folder | Scripts a browser fetches. |
| `.png` | 13 | the web folder | Pictures. |
| `.css` | 7 | the web folder | Stylesheets. |
| `.ico` | 5 | the web folder | Browser tab icons. |
| `.txt` | 4 | `<web folder>/assets/noticeboard/fonts/` | The noticeboard fonts' licences, which must travel with them. |
| `.json` | 3 | `_core/` | The API description, and one data file with its schema. |
| `.gif` | 3 | the web folder | The animated brand emblems. |
| `.icns` | 3 | the web folder | The brand kit's app icons for macOS. |
| `.html` | 2 | `<web folder>/assets/images/brandkit/` | The brand kit's two demonstration pages. |
| `.htaccess` | 7 | seven files, each by name and checksum | The web server's own rules for a folder: the web folder's, the installer's, and the one "refuse everything" file that sits in `_apps`, `_core`, `_lang`, `_sql` and `_vendor`. |
| `.md` | 1 | `<web folder>/assets/images/brandkit/` | The brand kit's read-me. |
| `.xsd` | 1 | `<web folder>/sitemap.xsd` | The schema that describes the sitemap. |
| `.zip` | 1 | one file, by name and checksum | `assets/images/brandkit/WebMSChurchMS.zip`, the brand kit as one download. |

**To ship a new kind of file, or a kind in a new place,** add it to `ALLOWED_KINDS` (or `WEB_FOLDER_PHP`, `ALLOWED_ARCHIVES` or `ALLOWED_HTACCESS`) in `check-package.py` on purpose, with the reason, in the same commit as the file. The message a refused build prints says the same.

**To add or change a `.htaccess`** (the "refuse everything" files for private folders, for example): one line in `ALLOWED_HTACCESS`, with the reason. Every `.htaccess` that ships is named there with the checksum of the copy somebody read, because one line in such a file can make the server run pictures as PHP. A build that meets one it does not know prints the exact line to paste, checksum included; a changed one prints its new checksum. Every `.htaccess` except the web folder's own is also refused for a line that could run a file as a program or list a folder (`AddHandler`, `SetHandler`, `AddType`, `php_value`, `Options +…` and their like); `Options -Indexes`, which only switches something off, is fine.

The other rules about names and contents:

- Names may use only letters, digits, dot, dash and underscore, and may not end in a dot. An ending must be in small letters (`.php`, never `.Php`).
- **Inside the web folder, no name may carry `.php` (with or without a digit), `.phtml`, `.phar`, `.pht`, `.phps` or `.inc` after any of its dots**, in any capitals, except the eight named PHP files. `shell.php.js` is refused: on a server that picks PHP by "any name with `.php` in it", which is how many hosting panels set it up, that file runs as PHP for anybody who asks.
- A picture or a font (`.png`, `.gif`, `.ico`, `.icns`, `.woff2`) is followed from its first part to its last by the lengths the file itself states, so nothing can be stuck on after it, and it may not hold the mark of an archive, a PHP opening tag or the first line of a key anywhere.
- A drawing (`.svg`) must be well-formed, and may hold no script, no event attribute (`onload=` and the like), no `foreignObject`, and no link to anything outside itself. None of the 62 drawings in `web/` on 12 October 2026 has a link of any kind, so this refuses nothing real. A drawing that embeds a picture as `href="data:…"` would be refused too; loosen `svg_problem` on purpose if one is ever needed.
- Any other kind must be text.
- Nothing looks inside an archive, so an archive is refused, whatever it is called, unless `ALLOWED_ARCHIVES` names it with the checksum of the copy somebody opened and read.

## The staff web folder's name

One line near the top of `build.sh`:

```bash
STAFF_WEB_FOLDER="admin_html"
```

The owner decided this on 12 October 2026 (decision 3 of the roll-out plan): `admin_html` follows the owner's naming of 13 September 2026, and it means no customer has to re-point their address when the public pages (#493) later take the name `public_html`. The checker reads this line, so changing it here is the whole change. The repository's own folder stays `web/public_html`.

## dompdf: the pin and the list

`../download-dompdf.sh` holds the version and the checksum of the one file dompdf publishes for that release. Neither is read from the environment. `dompdf-files.sha256` holds a checksum for every file inside that download, and names the version and the download's checksum it was made from. The fetch script and the checker each refuse when the list and the pin disagree, so they cannot drift apart. The checker compares the packaged folder with the list exactly as it compares every other file with git: a file added, changed or missing fails, in the folder and again in the zip.

To move to a new dompdf: change `DOMPDF_VERSION` and `DOMPDF_SHA256` together, run `bash tools/download-dompdf.sh --write-list`, and commit all three changes together.

## Checking a package by hand

```bash
python3 tools/package/check-package.py <folder or zip> --commit <commit> --channel <word> [--tag <tag>]
```

It runs every guard and reports all the problems in one go. Look for a last line beginning `PASS` and containing `the run reached its end`, as well as the exit code: a crash prints neither.

```bash
python3 tools/package/check-package.py --tree web [--commit <commit>]
```

This is for the pull-request checks. It reads what git holds under `web/` at the commit (default `HEAD`) and applies the same rules about names, kinds, places, contents, archives, links and left-out files, and the deny list. It builds nothing, so the guards that need a built package do not run. It does not see a file git does not track; such a file cannot get into a package.

## Proving the guards still work

```bash
bash tools/package/package-selftest.sh --cache <a folder to keep downloads in>
```

It touches nothing in the repository: it works in a scratch clone in the system's temporary folder and removes it afterwards. It needs the internet on its first run; with `--cache` later runs use what was kept.

It plants faults and requires each to be refused for its own reason. **It proves the guards catch what is planted in it, and nothing more.** What it plants:

- the 76 files an independent check planted on 12 October 2026, and twenty kinds of file on no list;
- PHP inside the web folder under names that do not end in `.php`; five `.htaccess` files nobody named, or changed since they were read; the "refuse everything" file of a left-out folder with other wording, and with the right wording one folder further down;
- pictures and fonts that are real except for one thing (bytes after the end, an archive or a PHP tag or a key inside a well-formed part, a length that is wrong), and drawings that hold a script, an event attribute or a link out;
- endings with a capital letter; the database login as JSON; a dump under an upgrade's name; a page outside the brand kit;
- a PHP file with a mistake in it, read by an ordinary PHP and by one whose exit code is lost, and under the name `Mixed.Php`;
- one known shape of secret in six files `gitleaks` skips or reads in pieces;
- a domain of ours in a file of its own; one more name put on the one list of our domains, in a copy, which must make the package the real checker passed fail for the one line that holds it; and that list missing, and empty;
- a commit with no installation guide, the old switch that used to let one through, a package folder with its guide removed, and a guide that never names the web folder;
- a channel file committed at `web/.channel`, and one put into a package folder as every build used to write it;
- a build with the allow switch on a path with no `gitleaks` (the name and the last line), the same build without the switch, and the switch together with a tag, given to the build and to the checker;
- a `gitleaks` that finds nothing whatever it is shown, and one that finds the made-up key alone but does not read the pieces; a made-up token, and a longer word, in the command whose placeholder is let through;
- a zip with a comment, with bytes in front of its first entry, after its end, and hidden between its last entry and its list of entries, each with its checksum file rewritten;
- a 17 MB file of an allowed kind, in git, in a package folder and in a zip, and ten files of 15 MB that are each within the limit and together are not;
- a list of our domains that ends the program when it is read;
- a file slipped into dompdf's folder after the folder check, a zip with a link, a zip with a damaged file, a failure at each step after the zipping, a build cancelled by each of three signals, and an empty checker.

One case goes the other way: new files of allowed kinds must be ACCEPTED, so that a rule tightened too far shows up as well.

Each of those cases has been seen to fail with its rule taken away, one rule at a time, in a copy of the scripts (`--tools`). The cases for the three gaps closed on 13 October 2026 are `T13 B01 B15 C13 C14 C15`; those added after that day's independent check are `T14`, `B16` to `B19` and `C16` to `C26`. **When you add a rule to the checker, add its case, and take the rule out once to see the self-test fail.** A self-test that passes on a broken checker is worse than none: that is exactly what an independent check found on 12 October 2026, with five rules loosened at once.

**Guards with NO case of their own here yet**, so a fault in one of them would not be noticed by this self-test: the top-level names (G3); dompdf's version marker (part of G8); the version and tag labels (the rest of G9); the smallest and largest size of the zip as a file, its checksum file and its entry names (part of G11 to G13); the fallback copies' fingerprints; and the last net that turns any other early end of the checker into exit code 1. Each passes on every real build, which shows it does not refuse good packages, and nothing more.

It runs some twenty-five whole builds, so it is not quick: minutes on a quiet machine, far longer on a busy one. Its last line says how long it took. Without `gitleaks` installed it uses a stand-in and says, in capitals, that the search for secrets was not proved; case `C26` is then not run, and says so. The three cases about a machine with no `gitleaks` (`B17` to `B19`) do not depend on the machine: they run with a path of their own on which no program of that name can be found. Started in the background by another script, it may have to skip the three signal cases, and says so in its last line.

## What these guards cannot do

Said in full at the top of `check-package.py`. The short form:

- A file of an allowed kind, in an allowed place, with an ordinary name, holding a secret in a shape nobody has described, would pass. The contents rule knows the database login only as the installer writes it, and the encryption key only as 64 hexadecimal characters alone in a file. `gitleaks` reads every file now, but knows only the shapes in its own rules (keys and tokens of well-known services, private keys): not a password somebody chose, not people's details, and nothing packed or encoded.
- A picture or a font is followed to its end, but what is inside a well-formed part of it is not understood: the dots of a picture, a note inside a PNG, the packed data of a font. Bytes hidden there on purpose are not seen. PHP's two-character short opening tag cannot be refused in a picture, because those two characters turn up by chance.
- The web folder's own `.htaccess` is held by its checksum alone. Whoever changes it and writes the new checksum has to have read it.
- A server set up to run some ending other than PHP's as a program (`.cgi`, `.pl`) is outside the name rule.
- Names that begin with a dot (`_core/.hidden.php`), very long names, and mistakes in `_vendor/` are not refused.

Review of what is committed under `web/`, and git's own ignore rules, are the first wall; this is the second.

## Not done by these scripts

- Publishing. The proposed workflow does that, and it is not in the repository until the owner has read it.
- Deciding which channel a commit belongs to. The caller says.
- The list of files an upgrade should delete (`REMOVED-FILES.txt`), which is left for a later release: the first package has nothing earlier to remove.
- Checking for our own domain anywhere but inside a package. `tools/audit-checks/check_no_hardcoded_domains.py` does that for the repository, and holds the one list of names (in base64, so that the plain name is nowhere under `tools/`). To see them: `python3 tools/audit-checks/check_no_hardcoded_domains.py --print-own-domains`.
- Installing. Nothing here unpacks a package and runs the installer. See below.

## Proved by installing

Done by hand on 13 October 2026, with a package built from the working branch, and not repeated by any script in the repository:

- **Install.** The zip was unpacked into an empty folder, its `admin_html` folder served by PHP's own web server, and the installer followed from `docs/INSTALL.md` alone, into a database whose user could not create databases, on MySQL 8.0.36 and 8.4.11. Afterwards: the key file, the database login and the lock in `_auth_keys`; the "refuse everything" file in all three private folders; `/health` reporting `"channelSource":"file"`; and a PDF made by the packaged dompdf.
- **Upgrade.** A later package (one more database upgrade, a higher version) was unpacked over each installation and `/admin/upgrade` opened. By checksum, nothing under `_auth_keys`, `_uploads` or `_backups` changed, and the saved settings were still there. The upgrade page added one snapshot, and put back a "refuse everything" file that had been deleted on purpose.
- **Unpacking never removes a file**, so going back to an older package means deleting the eight folders a package brings first; otherwise the newer version's database upgrade files stay, and the older Upgrade page offers to run them. The guide says so.
- **What the independent check then found, and what was changed (the same day).** A newer package with NO database upgrade in it left the portal on its holding page for ever, with nothing to press: the first proof above had only used a package that did bring one. The portal now closes for an upgrade only while a database upgrade is really waiting. It never records the new version by itself: only the Upgrade page's button does, and until that is pressed every request asks whether an upgrade is waiting, so a version that is still being uploaded cannot be taken for a finished one (`Portal\Core\Maintenance`; held by `tools/upgrade-pages-selftest.php`). And unpacking replaced the installation's own `.channel` word; packages no longer carry that file (see the rules above). Both were proved again from real packages on both MySQL versions: a version-only package leaves a stranger and a member with the portal, before the "Finish the upgrade" button and after it; a package with a database upgrade shows the holding page until the Upgrade page's button is pressed; and a beta installation given a live package, and a live one given a beta package, each keep their own word.

**Not proved:** a real hosting panel (its "Extract", its handling of names that begin with a full stop, its limits on time and size), Apache obeying the `.htaccess` files, MariaDB, and a browser (pages were read as text, and every file a page names was asked for).
