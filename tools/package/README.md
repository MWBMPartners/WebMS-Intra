# The installation package

These scripts build the zip a customer downloads to install or upgrade the portal using nothing but a hosting panel (#499), and check it before anybody can download it.

> **A package built today cannot be installed yet.** Three things are still to come, and until they land this is a proved build and a proved set of guards, not a product:
>
> 1. **The portal's code only works from a web folder called `public_html`.** The package calls it `admin_html` (see "The staff web folder's name"). Package F3 of the roll-out makes the code not care what the folder is called.
> 2. **There is no installation guide.** `docs/INSTALL.md` has to be written by really installing from the zip, which needs F3 and the installer changes (F5). Until it exists the build refuses to run without `--allow-missing-guide`, and the result is marked not fit to publish.
> 3. **The first customer's domain is still in five lines of five packaged files.** The domain guard refuses any build that carries it, so nothing builds from the branch until package F4 removes those lines. That refusal is the guard working.

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

Two switches exist for builds that prove the build and nothing more. Each skips ONE guard. Both are refused when `--tag` is given, both put `NOT-FOR-RELEASE` in the zip's name, and the last line then says `NOT FIT TO PUBLISH`:

- `--allow-missing-guide`: `docs/INSTALL.md` does not exist at that commit. Skips the guide guard.
- `--allow-no-gitleaks`: `gitleaks` is not installed on this machine. Skips the search for secrets, and only when `gitleaks` really is absent.

A build that will be published names its tag: `--tag v1.5.0` with `live`, or `--tag v1.5.0-beta.1` with `beta`. The tag must agree with `web/_core/version.php`. `--tag` is a label the build checks against the version; it does not look for a git tag of that name.

## The rules the build follows

- **Everything comes from git at the commit named, never from the working folder.** A file git does not hold cannot get in. That matters because a working folder holds the real database login.
- **A package may hold only what is positively allowed.** Every file must be of a kind, and in a place, that the allow list in `check-package.py` names. A list of bad names can never be complete; the first version of these guards had only that, and 43 files it had not thought of were shipped in a test. The deny list is kept as a second net.
- **No zip reaches the output folder unless it passed.** The zip is made in a temporary folder, checked there, and moved in only after it has passed and its checksum file is written. The two runs of the checker cannot be switched off.
- **The checker counts as passed only when it says so.** Its exit code must be 0 and its last line must be a `PASS` line containing `the run reached its end`. An empty or crashed checker gives neither.
- **The zip has no wrapper folder.** Its top level is the portal's folder, so "extract here" is the same act for a first install and for an upgrade.
- **Nothing of the customer's is in it.** No folder called `_auth_keys`, `_uploads` or `_backups` is in the package at any depth, even empty, so replacing a folder wholesale during an upgrade cannot wipe the encryption key.
- **No file or folder is writable by group or others**, whoever builds: the build sets the bits, and a guard reads them back from the folder and from the zip.
- **The search for secrets reads every file.** `gitleaks`, left to itself, skips some files for their names (`.svg`, `.gif`, `.woff2`, anything under `node_modules`) and some for how they begin (it stops reading a file as soon as a 10,000-byte stretch of it begins like a program or an archive). So it is run twice: on the folder as it is, and on a copy in which every file has a plain numbered name and is cut into small overlapping pieces. A find in a piece is reported under the real file's name and line.

## What is in a package

Everything git holds under `web/`, except: the three placeholder folders (`private_html`, `public_html_landing`, `public_html_redir`), the two empty ones (`_includes`, `_functions`) and every `.gitkeep`. Each file left out is printed by name. A real file in `_includes` or `_functions` stops the build, because leaving it out would lose it without anybody deciding that.

Added by the build: `_libraries/dompdf/` (every file on `dompdf-files.sha256`, plus `VERSION.txt`, which the fetch script writes last, and nothing else); the fallback copies under `<web folder>/assets/`; and at the top `.channel` (one word), `VERSION.txt` (five lines: product, version, channel, commit, time built), `CHANGELOG.md` and `INSTALL.md`.

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
| `.htaccess` | 2 | two files, each by name and checksum | The web server's own rules for a folder: the web folder's, and the installer's. |
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
- PHP inside the web folder under names that do not end in `.php`; five `.htaccess` files nobody named, or changed since they were read;
- pictures and fonts that are real except for one thing (bytes after the end, an archive or a PHP tag or a key inside a well-formed part, a length that is wrong), and drawings that hold a script, an event attribute or a link out;
- endings with a capital letter; the database login as JSON; a dump under an upgrade's name; a page outside the brand kit;
- a PHP file with a mistake in it, read by an ordinary PHP and by one whose exit code is lost, and under the name `Mixed.Php`;
- one known shape of secret in six files `gitleaks` skips or reads in pieces;
- a domain of ours in a file of its own;
- a file slipped into dompdf's folder after the folder check, a zip with a link, a zip with a damaged file, a failure at each step after the zipping, a build cancelled by each of three signals, and an empty checker.

One case goes the other way: new files of allowed kinds must be ACCEPTED, so that a rule tightened too far shows up as well.

Each of those cases has been seen to fail with its rule taken away, one rule at a time, in a copy of the scripts (`--tools`). **When you add a rule to the checker, add its case, and take the rule out once to see the self-test fail.** A self-test that passes on a broken checker is worse than none: that is exactly what an independent check found on 12 October 2026, with five rules loosened at once.

**Guards with NO case of their own here yet**, so a fault in one of them would not be noticed by this self-test: the top-level names (G3); dompdf's version marker (part of G8); the channel, version and tag labels (G9), including the refusal of an allow switch together with a tag; the guide guard inside the checker (G10; only the build's own refusal is planted); the zip's size, its checksum file and its entry names (part of G11 to G13); and the fallback copies' fingerprints. Each passes on every real build, which shows it does not refuse good packages, and nothing more.

It runs some twenty whole builds, so it is not quick: minutes on a quiet machine, far longer on a busy one. Its last line says how long it took. Without `gitleaks` installed it uses a stand-in and says, in capitals, that the search for secrets was not proved. Started in the background by another script, it may have to skip the three signal cases, and says so in its last line.

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
- Checking for our own domain anywhere but inside a package. `tools/audit-checks/check_no_hardcoded_domains.py` (package F4) will do that for the repository; once it exists the package's domain guard should call it, so the list of names has one home. Until then the guard holds the name in base64, so that the plain name is nowhere under `tools/`; `check-package.py --print-own-domains` shows it.
