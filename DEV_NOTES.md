# Development Notes

> Living document -- kept up to date with tips, processes, and guidance for
> working on WebMS Intra.

---

## Installing PHP on your own machine

### Why you need it

The portal is written in PHP, but PHP is not part of this repository — it is a
program that has to be installed on whatever computer you are working on. Two
things stop working without it:

1. **Visual Studio Code cannot check your PHP files as you type.** It shows the
   message *"Cannot validate since a PHP installation could not be found. Use
   the setting 'php.validate.executablePath' to configure the PHP executable."*
2. **You cannot run the same syntax check that the build server runs.** When a
   pull request is opened, the "PR Security Checks" job runs `php -l` over
   every PHP file in `web/`, and a single typo fails the whole build. That is a
   hard gate — nothing merges past it. Running the same check yourself before
   pushing turns a ten-minute round trip into a two-second one.

You do **not** need a web server, a database, or Composer on your own machine
for this. You only need the `php` command itself, so it can read your files and
tell you whether they are valid. The portal is served on shared hosting, which
supplies its own PHP.

### Which version

The project targets **PHP 8.5** and stays backward compatible with **8.4**.
Install 8.5 if your system offers it; 8.4 is fine too. Anything older than 8.4
will report errors on perfectly valid code, because it does not understand the
newer syntax the project uses.

### macOS

The usual way is Homebrew, a package manager for macOS. Check whether you
already have it:

```bash
brew --version
```

If that prints a version number, install PHP with:

```bash
brew install php
php --version          # confirm — expect 8.5.x
```

If `brew --version` says "command not found", install Homebrew first by
following the one-line instruction on <https://brew.sh>, then close and reopen
your terminal and run the two commands above.

Homebrew puts PHP at `/opt/homebrew/bin/php` on Apple Silicon Macs (M1 and
later) and at `/usr/local/bin/php` on older Intel Macs. Confirm which one with:

```bash
which php
```

**If you would rather not use Homebrew**, download a ready-made build from
<https://php.net/downloads> or use MAMP (<https://www.mamp.info>), which bundles
PHP with a web server. MAMP hides its copy of PHP inside the application
folder, at a path like
`/Applications/MAMP/bin/php/php8.5.0/bin/php` — you will need that full path
for the Visual Studio Code setting below.

### Windows

1. Go to <https://windows.php.net/download> and download the **Thread Safe**
   ZIP file for the newest 8.5 release, 64-bit (labelled `x64`).
2. Unzip it to a folder with no spaces in the name, for example `C:\php`.
3. In that folder, copy `php.ini-development` and rename the copy to `php.ini`.
4. Add `C:\php` to your PATH, which is the list of folders Windows searches
   when you type a command:
   press the Start button, type *"Edit the system environment variables"*, open
   it, click **Environment Variables**, select **Path** under *User variables*,
   click **Edit**, click **New**, and enter `C:\php`. Click OK on every window.
5. Close and reopen your terminal, then check it worked:

   ```powershell
   php --version
   ```

Two alternatives, if you would rather not do the above by hand:

- **Chocolatey** (a package manager for Windows): `choco install php`
- **Laragon** or **XAMPP**: bundles that install PHP together with a web server
  and a database. Their PHP lives at a path such as
  `C:\laragon\bin\php\php-8.5.0\php.exe` or `C:\xampp\php\php.exe`.

### Linux (Debian, Ubuntu, Linux Mint)

The version in the standard Ubuntu and Debian repositories is often older than
8.4. The usual fix is Ondřej Surý's PPA, a well-known third-party source that
packages current PHP releases for these systems.

```bash
sudo apt update
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php     # Ubuntu / Mint
sudo apt update
sudo apt install -y php8.5-cli
php --version
```

On **Debian** the PPA command above does not apply. Use Ondřej Surý's Debian
repository instead — the current instructions are at
<https://deb.sury.org/> — then `sudo apt install -y php8.5-cli`.

`php8.5-cli` is the command-line build. It is all you need for syntax checking;
there is no need for `php8.5-fpm`, Apache or Nginx.

### Linux (Fedora, RHEL, Rocky, AlmaLinux)

```bash
sudo dnf install -y php-cli
php --version
```

If that installs something older than 8.4, add the Remi repository, which
packages current PHP releases for these systems — instructions at
<https://rpms.remirepo.net/> — then select the 8.5 module and install `php-cli`
again.

### Raspberry Pi

Raspberry Pi OS is built on Debian, so it follows the Debian instructions
above. The version that ships in the standard repository is usually too old, so
use Ondřej Surý's Debian repository:

```bash
sudo apt update
sudo apt install -y apt-transport-https lsb-release ca-certificates curl
curl -sSLo /tmp/debsuryorg-archive-keyring.deb \
  https://packages.sury.org/debsuryorg-archive-keyring.deb
sudo dpkg -i /tmp/debsuryorg-archive-keyring.deb
echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] \
  https://packages.sury.org/php/ $(lsb_release -sc) main" \
  | sudo tee /etc/apt/sources.list.d/php.list
sudo apt update
sudo apt install -y php8.5-cli
php --version
```

This works on both 64-bit and 32-bit Raspberry Pi OS. On a Pi Zero or an older
Pi the install takes several minutes — that is normal, not a hang.

### Pointing Visual Studio Code at it

Once `php --version` works in a terminal, tell the editor where PHP is. Find
the full path first:

```bash
which php        # macOS, Linux, Raspberry Pi
where php        # Windows
```

Then open Visual Studio Code's settings (**Code → Settings → Settings**, or
`Ctrl`/`Cmd` + `,`), search for `php.validate.executablePath`, and paste that
path in. Typical values:

| System | Typical path |
| --- | --- |
| macOS, Apple Silicon (Homebrew) | `/opt/homebrew/bin/php` |
| macOS, Intel (Homebrew) | `/usr/local/bin/php` |
| Linux / Raspberry Pi | `/usr/bin/php` |
| Windows | `C:\php\php.exe` |

Restart Visual Studio Code. The "could not be found" message should be gone.

Do **not** commit this setting to the repository. The right path is different
on every machine, so it belongs in your personal settings, or in a
`.vscode/settings.json` inside the project folder — which is deliberately
listed in `.gitignore` for exactly this reason.

### Running the same check the build server runs

From the repository root:

```bash
find ./web -type f -name "*.php" \
  -not -path "*/vendor/*" \
  -not -path "*/_libraries/*" \
  -print0 | xargs -0 -n1 -P4 php -l
```

That is character-for-character the command in
`.github/workflows/pr-security.yml`. Silence, or a run of "No syntax errors
detected" lines, means you are clear. Any other output is a real error that
will fail the build, and it names the file and line number.

On Windows, run that command from Git Bash or Windows Subsystem for Linux
(WSL), because `find` and `xargs` are not PowerShell commands. The PowerShell
equivalent is:

```powershell
Get-ChildItem -Path .\web -Recurse -Filter *.php |
  Where-Object { $_.FullName -notmatch '\\(vendor|_libraries)\\' } |
  ForEach-Object { php -l $_.FullName }
```

### What you still cannot do locally

Installing PHP lets you check that files are *syntactically* valid. It does not
let you run the portal, because that also needs a web server, a MySQL 8.0
database, and the credentials in `_auth_keys/` which are never committed. To
exercise the database migrations, use the end-to-end harness described under
**End-to-end migration test (#248)** further down this document — it runs in
CI against real MySQL 8.0.36 and 8.4.11 databases, as two jobs side by side.

---

## Repository vs Server Structure

The Git repository root contains documentation and CI/CD config. **All deployable
code lives inside `web/`.**

> **The server side of this diagram described a layout that no longer exists**,
> until it was corrected on 27 October 2026 (#637). It showed the three channels
> sharing one base folder, with `public_html`, `public_html_dev` and
> `public_html_beta` beside each other — the arrangement where a deploy of one
> channel could overwrite another's code, last push winning. **Each channel now
> has a folder of its own**, and the web address points at `admin_html` inside
> it. `README.md` holds the current deploy table and is the place to check first.
> The domain below is **one customer's set-up, shown as an example**: no address
> is built into this product (#500).

```
Git repo root (NOT deployed)          Server: <one channel's folder>/
├── .claude/                           ├── _core/
├── .github/workflows/deploy.yml       ├── _vendor/
├── CHANGELOG.md                       ├── _sql/
├── DEV_NOTES.md                       ├── _auth_keys/    (server-managed)
├── README.md                          ├── _libraries/    (server-managed)
└── web/ ─── contents deployed ──────► ├── _uploads/      (server-managed)
    ├── _core/                         ├── _apps/         (app controllers, #159)
    ├── _apps/        (#159)           ├── _backups/      (server-managed)
    ├── _vendor/                       ├── _includes/
    ├── _sql/                          ├── _functions/
    ├── _lang/                         ├── admin_html/    (the staff pages)
    ├── _install/                      ├── _install/
    ├── _includes/                     ├── _lang/
    ├── _functions/                    ├── private_html/
    ├── _libraries/ (gitignored)       ├── public_html_landing/
    ├── public_html/                   └── public_html_redir/
    │   ├── auth/
    │   ├── dashboard/
    │   ├── expenses/
    │   ├── help/
    │   └── settings/
    ├── private_html/
    ├── public_html_landing/
    └── public_html_redir/
```

The `_` prefix on every server-side dir is a naming convention: any dir
that starts with `_` is **above the web server's document root** and cannot be
reached over HTTP. Only the channel's web folder is web-accessible.

The repo holds **one** `public_html/` source tree, and the deploy mirrors it into
the channel's own folder. **The old destinations `public_html_dev/` and
`public_html_beta/` beside a shared `public_html/` are gone** — see the warning at
the top of this section and the deploy table in `README.md`. There is still no
per-channel front controller in the repo. Note also that the channel is decided in
exactly one place, `Portal\Core\Channel`: never work it out from a folder name in
code, and never type `public_html` into a path (use `PORTAL_WEBROOT`).

**Key rule:** when referencing paths in PHP code, use `PORTAL_ROOT` and related
constants (defined in `bootstrap.php`). When referencing paths in Git/CI, prefix
with `web/`.

---

## Deployment Model

WebMS Intra uses a **three-branch SFTP deployment model** modelled on the
iHymns pipeline. Only `web/` is synced; the active branch decides which
public web root the upload lands in.

| Branch  | Channel    | Where it lands on the server                     | Auto-bump rule       |
| ------- | ---------- | ------------------------------------------------ | -------------------- |
| `alpha` | alpha/dev  | its own folder, named by `SFTP_PATH_ALPHA_DIR`    | PATCH (always)       |
| `beta`  | beta       | its own folder, named by `SFTP_PATH_BETA_DIR`     | Conventional Commits |
| `main`  | production | its own folder, named by `SFTP_PATH_LIVE_DIR`     | none on `main`       |

### Remote layout: one folder per channel (rewritten by the workflow package, #499)

Each channel has a folder of its own, named by `SFTP_PATH_ALPHA_DIR`,
`SFTP_PATH_BETA_DIR` or `SFTP_PATH_LIVE_DIR` inside the base folder
`SFTP_PATH_ROOT_DIR`. **Point the channel's web address at `admin_html` inside its
folder.**

```text
SFTP_PATH_ROOT_DIR/
└── <the channel's folder>/
    ├── admin_html/            ← from web/public_html/
    ├── _apps/                 ← from web/_apps/      (#159)
    ├── _core/                 ← from web/_core/
    ├── _install/  _lang/  _sql/  _vendor/
    ├── _libraries/dompdf/     ← fetched at deploy time by tools/download-dompdf.sh
    ├── _auth_keys/            ← created once if missing, then never touched
    ├── _uploads/              ← the same
    └── _backups/              ← the same
```

**THIS REPLACED A SHARED BASE, and that is the point of the change.** All three
channels used to share one base folder: only `web/public_html/` went to a sibling of
its own (`public_html/`, `public_html_beta/`, `public_html_dev/`) and every code
folder went to the shared base **from every branch — last push wins**. So deploying
alpha overwrote live's `_core`. Nothing warned anybody; it was simply how it worked.
Each channel now has its own copy of everything, and the deploy refuses a folder that
does not look like that channel's own.

### Workflows

- `deploy.yml` — push to alpha/beta/main, or manual dispatch. PHP-lint, fetch
  pinned dompdf, SFTP via lftp (SSH key first, password fallback).
- `version-bump.yml` — push to alpha or beta. Alpha always bumps PATCH; beta
  uses Conventional Commits (BREAKING/`!:` → major, `feat(` → minor, else patch).
- `changelog.yml` — push to alpha or beta only (NOT main — the ruleset
  on main blocks the bot's direct push). Appends per-branch sections to
  `CHANGELOG.md` from commit messages since the last `v*` tag. Entries
  propagate to main via the normal beta → main merge.
- `package.yml` — merge into `alpha`, `beta`, `rc` or `main`, or a manual
  run. Builds the installation zip and checks it. For `main` it publishes a
  GitHub Release, for `rc` a pre-release; for `alpha` and `beta` the zip is
  only a download on the run. A manual run never publishes, whatever the
  branch. **It replaced `release.yml`**, which made a Release when somebody
  pushed a `v*` tag by hand; such a push now gets a run that says it
  published nothing, rather than silence. Publishing waits on a repository
  variable, so nothing is published until that is switched on.
- `auto-merge-alpha.yml` — PR opened or synchronised against `alpha`. Enables
  GitHub native auto-merge, then dispatches BOTH `deploy.yml` and
  `package.yml` after the merge. The bridge is required here because GitHub's
  *native* auto-merge IS attributed to `GITHUB_TOKEN`, which doesn't trigger
  downstream workflows. Manual UI merges on `beta` and `main` don't need a
  bridge — the `push:` event from a human-attributed merge fires normally.
  **Anything new that is supposed to happen on a merge into `alpha` needs a
  line here too.** The package bridge was missed when `package.yml` was
  written (found by its independent check, 14 October 2026, added 27
  October): `package.yml` listens for a push to `alpha`, that push never
  happens, and the proof is that `version-bump.yml` — which fires on the same
  event — has raised the version on `alpha` twice in the branch's whole
  history. The bridge's failure is deliberately swallowed with a warning,
  because `gh workflow run` cannot find a workflow until its file is on the
  default branch, and a hard failure there would lose the deploy dispatch.
- `portal-selftests.yml`, `mail-selftests.yml`, `calendar-selftests.yml` — every
  pull request, with no path filter, which is why these are the ones that can be
  made required status checks. The self-tests that need only PHP run in one job;
  the rest run against a real MySQL, on 8.0.36 and 8.4.11. Before this, most
  self-tests ran nowhere but a developer's machine.
- `backup-selftest.yml`, `e2e-migrations.yml` — a pull request **that touches the
  files they are about**. They still run and a red one is still visible, but
  **a path-filtered check must never be made required**: GitHub has no "required
  only when it runs", so the branch rule would wait for a report that never comes.
  `package.yml` has no `pull_request` trigger at all, so the same goes for its
  jobs. (The first draft of the owner's required-checks checklist named all three
  and would have locked `main`, `beta` and `alpha`; its independent check caught
  it on 27 October 2026.)
- **Not every test runs automatically**, and nothing in the workflows says so:
  `tools/page-scripts-selftest.php` (#625), `tools/qr-selftest.php` (#623) and
  seven of the 32 scripts in `tools/audit-checks/` are named in no workflow. Two of
  those seven are the tripwires added for #625 and #622. Tracked as #637.
- **The Psalm job was removed** (13 October 2026). It installed Psalm in a
  temporary folder, Psalm stopped with "Could not find any composer
  autoloaders", the step swallowed that with `|| true`, wrote an empty report
  and went green. A check that cannot fail is worse than none, because it
  looks like a pass. The names "PHP Static Analysis" and "PHP -l lint" were
  kept on purpose: a branch rule finds a required check by its job name.
  With Psalm gone, **no code-analysis check in this repository can turn a run
  red** — `codeql.yml` still runs but keeps `continue-on-error: true`,
  because Code Scanning is not switched on and the upload half of that step
  would fail on every pull request. The owner chose on 26 October 2026 to
  keep it that way and say so plainly rather than hide it.
- `pr-security.yml` — runs on every PR against alpha/beta/main. PHP lint
  (hard gate), gitleaks secrets scan, heuristic anti-pattern scan (checks
  9-22), and — as its own step, so it is never skipped by the heuristic
  scan's early exit — the foreign-key target check (#552, check 23).
- `calendar-selftests.yml` — runs on every PR against alpha/beta/main. Runs
  the four calendar self-tests (#514) that used to depend on somebody
  remembering to run them by hand. Three of the four (event visibility,
  feed resolver, feed importer) each get their own throwaway database,
  built from `full_schema.sql` in a MySQL 8.0.36 container GitHub starts
  beside the job and removes, data included, when the job ends; the
  fourth, the calendar reader self-test, needs no database at all.
- `e2e-migrations.yml` — the end-to-end migration test harness (#248). Runs
  on PRs and on pushes to alpha/beta/main, in both cases only when
  `web/_sql/`, the harness folder or the workflow itself changes, plus
  manual runs. Runs the
  harness's six phases and its three data checks twice, as two jobs side by side, on MySQL 8.0.36
  and MySQL 8.4.11.
- `repo-config-audit.yml` — weekly + on PRs touching `.github/workflows/`.
  Detects orphaned required-status-check rules (see gotchas section).

**Three of the descriptions above have not yet been observed for real on
GitHub.** `calendar-selftests.yml`, `pr-security.yml`'s check 23, and
`e2e-migrations.yml`'s MySQL 8.4.11 job were all added on the
`claude/alpha-wip` branch, which has not yet been opened as a pull request.
The first two run on a pull request (the calendar self-tests can also be
started by hand, once that file reaches the default branch). The migration
test runs on a pull request or when alpha, beta or main is updated — in
both cases only when certain files change — and can be started by hand;
none of that has happened yet. The present tense above
describes what each is BUILT to do, not something already seen on GitHub's
own machines. What was proved instead is local, and some parts, such as how
GitHub starts and removes the database container, can only be proved on
GitHub. The local proofs ran on this Mac, in throwaway MySQL 8.0.36 and
8.4.11 containers, and on a Linux machine built to match GitHub's runner
(Ubuntu 24.04, the same PHP 8.4 packages GitHub's `setup-php` step
installs). That last proof found a real fault. On a machine whose PHP lacks
seven old Windows time-zone names, the calendar reader self-test's own
"L4" check used to fail. Whether GitHub's runner lacks them was never
verified, because it did not need to be: the fault was in the portal's own
committed data, not in any one machine. It is tracked as issue #557 (high
priority) and is now fixed: `tools/generate-windows-timezones.php` converts
every zone it writes to its CURRENT spelling and refuses rather than fall
back to an old one, the committed list was regenerated, and a new self-test
check ("L6" in `tools/ics-reader-selftest.php`) stops an old spelling
creeping back in unnoticed. See the CHANGELOG's `fix(calendar):` entry for
issue #557.

### Day-to-Day Workflow

1. Branch off `alpha` for new work.
2. Open a PR against `alpha` → auto-merge fires once checks pass.
3. When `alpha` is stable, open a PR from `alpha` → `beta` for wider testing.
4. When `beta` is stable, open a PR from `beta` → `main` for production.
5. **Nothing more to do: the merge into `main` publishes the release.**
   `package.yml` works out the version from `web/_core/version.php`, makes the
   tag itself and publishes `webms-intra-<version>.zip` with its checksum.
   Pushing a `v*` tag by hand publishes nothing any more, and the tag still
   counts as taken, so do not do it. If the run is red because "The version
   number was not raised", raise the number and merge again.

   A release candidate works the same way on the `rc` branch, which does not
   exist yet: a merge there publishes `v<version>-rc.<n>` marked as a
   pre-release.

### Manual Deploy Override

`Actions → Deploy via SFTP → Run workflow` accepts an override target
(`alpha` / `beta` / `main`) that bypasses the branch-based mapping for a
one-off deploy.

### Commit flags

- `[skip ci]` — skip every workflow on this commit
- `[deploy all]` — force a full re-sync regardless of change detection

---

## CI/CD Secrets Setup — Step-by-Step

Configure these once when bringing a fresh repo (or a new server) online.

### 1. Generate the SSH deploy keypair (preferred over password)

On your local machine:

```bash
ssh-keygen -t ed25519 -C "webms-intra-deploy@github" \
  -f ~/.ssh/webms_intra_deploy -N ''
```

Produces:

- `~/.ssh/webms_intra_deploy`     — private key (goes into GitHub secret `SFTP_KEY`)
- `~/.ssh/webms_intra_deploy.pub` — public key (goes onto the DreamHost server)

### 2. Authorise the public key on DreamHost

DreamHost panel → **Users → SFTP Users → [deploy user] → Manage Users**,
paste the contents of `~/.ssh/webms_intra_deploy.pub` into **Authorized Keys**.

Verify from your laptop:

```bash
ssh -i ~/.ssh/webms_intra_deploy -p 22 <SFTP_USER>@<SFTP_HOST> 'pwd; ls'
```

### 3. Set the GitHub repo secrets

> ⚠️ **Three names in this step were RETIRED by the deploy rewrite (#499, #500).**
> `SFTP_LIVE_PATH`, `SFTP_BETA_PATH` and `SFTP_DEV_PATH` used to be listed here as
> required. They are now on `RETIRED_SETTINGS` in `tools/deploy/resolve-settings.sh`:
> setting one only produces a warning, and the deploy will refuse for want of the
> base folder. The table below is the real list. **Every value is an example of one
> customer's set-up, not a default** — nothing in this product has a built-in address.

| Setting                | Secret? | Required | Example value |
| ---------------------- | ------- | -------- | ------------- |
| `SFTP_HOST`            | secret  | yes      | `iad1-shared-XX-XX.example-host.com` |
| `SFTP_USER`            | secret  | yes      | `dh_abcd1234` |
| `SFTP_PATH_ROOT_DIR`   | secret  | yes      | `/home/dh_abcd1234/portal.example.org` — a FULL path to the base folder |
| `SFTP_PATH_LIVE_DIR`   | secret  | yes      | `public_html` — **a NAME inside the base folder, never a path** |
| `SFTP_PATH_BETA_DIR`   | secret  | yes      | `public_html_beta` |
| `SFTP_PATH_ALPHA_DIR`  | secret  | yes      | `public_html_dev` (the alpha branch deploys the dev channel) |
| `SFTP_PORT`            | secret  | no       | `22` if omitted |
| `SFTP_KEY`             | secret  | one of   | the whole private key file (preferred) |
| `SFTP_PASSWORD`        | secret  | one of   | the SFTP password (used only when `SFTP_KEY` is unset) |
| `SFTP_ENABLED`         | variable | yes     | must be **exactly** `true` — four small letters, no spaces or line breaks. Anything else means "work it out but upload nothing", which is how you take a dry run. |
| `ADMIN_BASE_URL_LIVE` / `_BETA` / `_ALPHA` | variable | no | the staff address the health check uses; empty means skip that check |

A channel may have its own login and its own base folder instead
(`SFTP_HOST_LIVE`, `SFTP_USER_LIVE`, `SFTP_KEY_LIVE` or `SFTP_PASSWORD_LIVE`,
`SFTP_PORT_LIVE`, `SFTP_PATH_LIVE_ROOT_DIR`, and the same for `BETA` and `ALPHA`).
**A channel's own login is all or nothing:** if any one of its five login settings is
set, its host, user and key-or-password must ALL come from that channel's settings.
Nothing is borrowed from the shared login, because "a new host with the shared user"
would sign in happily and then write the shared base folder's path onto the wrong
server, and that cannot be told from a deliberate choice by looking at the values.

```bash
# Replace every value with your own. None of these is a default.
gh secret set SFTP_HOST           --body 'iad1-shared-XX-XX.example-host.com'
gh secret set SFTP_USER           --body 'dh_abcd1234'
gh secret set SFTP_PATH_ROOT_DIR  --body '/home/dh_abcd1234/portal.example.org'
gh secret set SFTP_PATH_LIVE_DIR  --body 'public_html'
gh secret set SFTP_PATH_BETA_DIR  --body 'public_html_beta'
gh secret set SFTP_PATH_ALPHA_DIR --body 'public_html_dev'
gh secret set SFTP_KEY            < ~/.ssh/webms_intra_deploy
# optional:
gh secret set SFTP_PORT           --body '22'
gh secret set SFTP_PASSWORD                       # prompts (keeps it out of shell history)
# not secret, and nothing uploads until this one says exactly "true":
gh variable set SFTP_ENABLED      --body 'true'
```

---

### 3b. The two front doors — SUPERSEDED, and SIX OF ITS SETTING NAMES ARE RETIRED (#493; see the warning)

> ⚠️ **READ THIS BEFORE FOLLOWING ANYTHING IN THIS SECTION. Added 27 October 2026.**
>
> This describes an arrangement that was planned and then replaced by the workflow
> package (#499). **Six of the setting names below are retired**: nothing reads
> `SFTP_PATH_LIVE_ADMIN_DIR`, `SFTP_PATH_LIVE_PUBLIC_DIR`, `SFTP_PATH_BETA_ADMIN_DIR`,
> `SFTP_PATH_BETA_PUBLIC_DIR`, `SFTP_PATH_ALPHA_ADMIN_DIR` or
> `SFTP_PATH_ALPHA_PUBLIC_DIR`, and each one still set only produces a warning line in
> every run. `SFTP_PATH_ROOT_DIR` is still real; the per-channel folder settings are now
> `SFTP_PATH_ALPHA_DIR`, `SFTP_PATH_BETA_DIR` and `SFTP_PATH_LIVE_DIR`, one folder per
> channel holding BOTH `admin_html` and the code folders. There is no separate public
> website folder any more.
>
> **In particular, do NOT follow the "what to do on the server" table further down**,
> which tells you to delete `public_html/`, `public_html_beta/` and `public_html_dev/`.
> It was written for a migration that never happened in this shape. Deleting a live
> channel's folder on the strength of it would take the site down.
>
> What is true is in the owner's guide,
> `.claude-work/resume/rollout-WF--build-evidence/DEPLOY-SET-UP-AND-FIRST-RUN.md`, and in
> "Remote layout: one folder per channel" above. **Rewriting this section properly is
> tracked as #637**; it is left here, marked, rather than deleted, because the
> reasoning in it about why both folders must share a parent is still why the current
> layout is shaped as it is.

> ⚠️ **Do not set these yet.** The deployment does not read them until the work
> on #493 lands. They are written down here now so the change can be planned,
> and so nobody has to work them out again later.

**What is changing.** The portal is becoming two websites that share one set of
code:

- **`web/admin_html/`** — the management portal. Everything behind a login. This
  is today's `web/public_html/`, renamed.
- **`web/public_html/`** — new, and genuinely public. No login, ever. This is what
  a visitor to the noticeboard or the public calendar sees.

**Why the existing three settings are retired rather than reused.** The folder
called `public_html` **keeps its name but changes its meaning**. Today it holds
the management portal; afterwards it holds the public website. If one of the old
settings were quietly left pointing at the old place, the next deployment would
copy **the entire management portal into the public web folder** — and it would
look like it had worked.

A required setting that has not been created yet makes the deployment **stop
with a clear error**. A setting that still exists but now means something
different fails silently, in the worst possible direction. So all three go, and
new, clearly-named ones replace them — ones the deployment insists on, so a missed
step stops it rather than sending files to the wrong place.

**One setting names the base folder. The rest name only their own folder.**

`SFTP_PATH_ROOT_DIR` holds the shared base — the folder everything else sits inside.
The six front-door settings hold **just a folder name**, not a full path. The
deployment joins them.

This is not only tidier. It makes the "both folders must share a parent" rule
**impossible to break** rather than something the deployment has to check: every
folder is placed inside `SFTP_PATH_ROOT_DIR` by construction. It also means moving the
whole installation is a one-setting change.

| Setting | Value (example) |
| --- | --- |
| `SFTP_PATH_ROOT_DIR` | `/home/dh_abcd1234/portal.millrdsdacambridge.uk/` |

| Branch  | Front door        | Setting                      | Value               |
| ------- | ----------------- | ---------------------------- | ------------------- |
| `main`  | Management portal | `SFTP_PATH_LIVE_ADMIN_DIR`   | `admin_html/`       |
| `main`  | Public website    | `SFTP_PATH_LIVE_PUBLIC_DIR`  | `public_html/`      |
| `beta`  | Management portal | `SFTP_PATH_BETA_ADMIN_DIR`   | `admin_html_beta/`  |
| `beta`  | Public website    | `SFTP_PATH_BETA_PUBLIC_DIR`  | `public_html_beta/` |
| `alpha` | Management portal | `SFTP_PATH_ALPHA_ADMIN_DIR`  | `admin_html_dev/`   |
| `alpha` | Public website    | `SFTP_PATH_ALPHA_PUBLIC_DIR` | `public_html_dev/`  |

**How the names are built.** Every one of these starts `SFTP_PATH_`, so in
GitHub's settings list — which sorts alphabetically — they sit together in one
block instead of being scattered among the other `SFTP_` settings. Inside that
block they then fall into order by channel:

```text
SFTP_PATH_ROOT_DIR                          the base folder, one for every channel
SFTP_PATH_<CHANNEL>_<FRONT DOOR>_DIR        for example SFTP_PATH_LIVE_ADMIN_DIR
```

- **`<CHANNEL>`** is `LIVE`, `BETA` or `ALPHA` — the channel, not the branch. The
  deployment already thinks in channels (`deploy.yml` sets `channel=live` for the
  `main` branch, `channel=beta`, `channel=alpha`), so this uses the same word for
  the same thing. `main` is the branch; `live` is what it deploys to.
- **`_DIR` at the end** says the value is a **folder name**, not a full path. That
  matters: the old settings held full paths, and a setting that keeps its name
  while its meaning changes is the exact trap this whole piece of work exists to
  avoid.

Names from earlier drafts of this plan — `SFTP_ROOT_DIR`, `SFTP_ADMIN_DIR_LIVE`, `SFTP_ADMIN_PATH_LIVE` and so on — were
never set and are not read. If one turns up somewhere it is simply ignored, and
the deployment stops for the setting it actually wants.

One deliberate mismatch, so it does not look like a mistake: the alpha settings
say `ALPHA`, but the folders are still named `..._dev`. They keep their existing
names so the server needs no renaming for a channel nobody outside the team
visits. Rename them to `..._alpha` if you prefer — just change the values of the
two `SFTP_PATH_ALPHA_*` settings to match.

#### Slashes: where they belong, and where they break things

This is the easiest thing to get wrong, and getting it wrong sends a deployment
somewhere plausible-looking rather than failing outright. The rules are short.

| Setting | Must START with `/`? | May END with `/`? | May contain `/` in the middle? |
| --- | --- | --- | --- |
| `SFTP_PATH_ROOT_DIR` | **Yes — required** | Yes, optional (trimmed) | Yes, it is a full path |
| `SFTP_PATH_*_ADMIN_DIR` | **No** | Yes, optional (trimmed) | **No — refused** |
| `SFTP_PATH_*_PUBLIC_DIR` | **No** | Yes, optional (trimmed) | **No — refused** |
| `SFTP_HOST` | **No** | **No** | **No** |
| `SFTP_USER` | **No** | **No** | **No** |

**In plain terms:**

- **`SFTP_PATH_ROOT_DIR` is a full path and must start with `/`.** A trailing slash is
  fine either way — the deployment trims it before joining.
- **The six folder settings are a NAME, not a path.** Just `admin_html` or
  `admin_html/`. No leading slash, and nothing with another folder inside it.
- **`SFTP_HOST` is a bare machine name.** No `sftp://` in front, no slash after.

**Right:**

```text
SFTP_PATH_ROOT_DIR         /home/dh_abcd1234/portal.millrdsdacambridge.uk/
SFTP_PATH_ROOT_DIR         /home/dh_abcd1234/portal.millrdsdacambridge.uk   ← also fine
SFTP_PATH_LIVE_ADMIN_DIR   admin_html/
SFTP_PATH_LIVE_ADMIN_DIR   admin_html                                       ← also fine
SFTP_HOST                  iad1-shared-12-34.dreamhost.com
```

**Wrong, and what actually happens:**

| What somebody types | What goes wrong |
| --- | --- |
| `SFTP_PATH_ROOT_DIR` = `home/dh_abcd1234/portal...` (no leading slash) | **Refused, with a message.** Without that check it would be treated as relative to wherever the SFTP session starts, and could create a whole `home/dh_abcd1234/...` tree *inside* your home folder — a second copy of everything, in the wrong place, that looks like it worked. |
| `SFTP_PATH_LIVE_ADMIN_DIR` = `/home/dh_abcd1234/portal.../admin_html` (the old full-path style) | **Refused, with a message.** Joined to the base it would build `/home/dh_abcd1234/portal...//home/dh_abcd1234/portal.../admin_html`. |
| `SFTP_PATH_LIVE_ADMIN_DIR` = `admin_html/public` | **Refused** — a folder name cannot contain another folder. |
| `SFTP_PATH_LIVE_ADMIN_DIR` = `/admin_html/` | Accepted. Both slashes are trimmed, leaving `admin_html`. Untidy but unambiguous, so it is allowed rather than refused. |
| `SFTP_HOST` = `sftp://iad1-shared-12-34.dreamhost.com/` | **Connection fails.** The host is put straight into `sftp://USER@HOST:PORT`, so this becomes `sftp://user@sftp://iad1-...com/:22`. |
| `SFTP_PATH_ROOT_DIR` ends `//` | Harmless — trimmed to one. |

**Why the deployment refuses rather than tidying up the first two.** Both are the
signature of somebody pasting the old style into a new setting. Quietly correcting
that would hide a half-finished changeover, which is the state in which the really
expensive mistakes happen. A refusal costs a minute; a deployment into the wrong
folder can publish the management portal.

**Unchanged:** `SFTP_HOST`, `SFTP_USER`, `SFTP_PORT`, and `SFTP_KEY` or
`SFTP_PASSWORD`.

**Retired:** `SFTP_LIVE_PATH`, `SFTP_BETA_PATH`, `SFTP_DEV_PATH`. Confirmed gone
from this repository and not inherited from the organisation — checked with the
GitHub API on 11 September 2026.

```bash
gh secret set SFTP_PATH_ROOT_DIR         --body '/home/dh_abcd1234/portal.millrdsdacambridge.uk/'

gh secret set SFTP_PATH_LIVE_ADMIN_DIR   --body 'admin_html/'
gh secret set SFTP_PATH_LIVE_PUBLIC_DIR  --body 'public_html/'
gh secret set SFTP_PATH_BETA_ADMIN_DIR   --body 'admin_html_beta/'
gh secret set SFTP_PATH_BETA_PUBLIC_DIR  --body 'public_html_beta/'
gh secret set SFTP_PATH_ALPHA_ADMIN_DIR  --body 'admin_html_dev/'
gh secret set SFTP_PATH_ALPHA_PUBLIC_DIR --body 'public_html_dev/'

# Only needed when converting an OLDER installation. Done here on 11 September 2026.
gh secret delete SFTP_LIVE_PATH
gh secret delete SFTP_BETA_PATH
gh secret delete SFTP_DEV_PATH
```

#### What is set up right now (checked 11 September 2026)

Checked with the GitHub API, not from memory. A secret can be set on this
repository or inherited from the organisation, and from inside a workflow the two
look identical — so the only way to know is to ask.

| Setting | Where it is set | Note |
| --- | --- | --- |
| The seven `SFTP_PATH_*` | This repository | as in the tables above |
| `SFTP_HOST`, `SFTP_USER`, `SFTP_PASSWORD` | This repository, **overriding** an organisation value of the same name | see the warning below |
| `SFTP_PORT` | The organisation only | inherited |
| `SFTP_KEY` | **Not set anywhere** | this installation signs in with the password |
| `SFTP_LIVE_PATH`, `SFTP_BETA_PATH`, `SFTP_DEV_PATH` | **Not visible at either level** | the changeover is complete |

> ⚠️ **Change the repository's `SFTP_HOST`, `SFTP_USER` and `SFTP_PASSWORD` — never
> delete them.** Each one overrides an organisation-wide value that other
> repositories share. Delete one here and the deployment does NOT stop: it
> quietly falls back to the organisation's value, which may belong to a different
> account or a different server. That is the worst way for it to fail — a
> deployment that reports success against the wrong place.

**Why a leftover retired setting is warned about, not refused.** An earlier draft
of the deployment refused to run at all if it could see one of the three retired
names. That would have been wrong here. Organisation secrets are visible to every
repository in the organisation, and this organisation already shares its SFTP
settings between repositories. Another repository adding `SFTP_LIVE_PATH` at the
organisation level would then have stopped every WebMS-Intra deployment, for a
reason that has nothing to do with WebMS-Intra — and a check that cries wolf gets
switched off. Nothing reads the retired names any more, so a leftover one cannot
send a deployment anywhere. The protection that actually matters is the checking
of the new settings, and that does stop the deployment when something is wrong.

**Where the shared code goes.** Straight into `SFTP_PATH_ROOT_DIR`. The deployment used
to work this out by taking the folder above the web root, which meant a typo in a
path quietly relocated the shared code somewhere plausible-looking. Now it is
stated outright, and an empty setting fails loudly.

#### Where everything ends up

The public front door finds the shared code by looking **one folder up** from
itself. Because every folder is placed inside `SFTP_PATH_ROOT_DIR`, that is always
true — there is nothing to get wrong and nothing for the deployment to check.

```text
/home/dh_abcd1234/portal.millrdsdacambridge.uk/      <- SFTP_PATH_ROOT_DIR
├── _core/  _apps/  _sql/  _lang/  _vendor/  _install/   <- shared code
├── _auth_keys/  _uploads/  _backups/                     <- server-managed, never touched
├── admin_html/          <- main  : the management portal
├── admin_html_beta/     <- beta  : the management portal
├── admin_html_dev/      <- alpha : the management portal
├── public_html/         <- main  : the public website
├── public_html_beta/    <- beta  : the public website
└── public_html_dev/     <- alpha : the public website
```

This account already runs three web roots from one base folder, so the
arrangement is already proven here.

#### What to set in the DreamHost panel

| Address                             | Web directory                                                    |
| ----------------------------------- | ---------------------------------------------------------------- |
| `portal.millrdsdacambridge.uk`      | `/home/dh_abcd1234/portal.millrdsdacambridge.uk/admin_html`       |
| `public.millrdsdacambridge.uk`      | `/home/dh_abcd1234/portal.millrdsdacambridge.uk/public_html`      |

> 🚨 **Never point a web address at the shared folder itself.** If any domain's
> web directory is set to `/home/dh_abcd1234/portal.millrdsdacambridge.uk/`, the
> web server will hand out database snapshots, uploaded files (pastoral
> attachments, children's photos, expense receipts, Gift Aid addresses) and the
> installer. The mistake would be in the hosting panel, so every check in the
> deployment would pass while it was happening. The portal refuses to start if it
> detects this, and a probe after deployment fetches those addresses and fails the
> run if they answer — but the panel is the place to get it right.

Serving the public pages at a path on a **separate main domain**
(`example.org/noticeboard`) needs nothing deployed and no extra setting. That is a
different mechanism — see `.claude/plans/public-door-3-build-plan.md`.

---

### 3c. Changing over a server that already has the old layout (#493)

> 🛑 **Do not deploy until the #493 work has landed.** The deployment does not yet
> know about `admin_html/`, so the shared upload would copy the entire management
> portal into the shared folder, where a misconfigured web address could serve it.

#### Does the deployment clean up after itself?

Mostly yes, and it is worth knowing exactly where.

Both uploads run `lftp mirror --reverse --delete`, so **a file removed from the
repository is removed from the server on the next deployment**. That means the old
management-portal files sitting in `public_html/` would be cleared out when the
new public website is uploaded there.

Relying on that alone is still the wrong call for this particular change, for one
reason: `public_html` is changing **meaning**, not just contents. If a deployment
stops half way, or an exclusion happens to match, what survives is an
admin-portal file in a folder the whole world can now read. Deleting the folder
beforehand removes that possibility rather than guarding against it.

#### What to delete, and what to never touch

| On the server | Do this | Why |
| --- | --- | --- |
| `public_html/` | **Delete the whole folder** | Every file in it is the old management portal. Under the new layout this folder is the PUBLIC website. |
| `public_html_beta/` | **Delete the whole folder** | Same. |
| `public_html_dev/` | **Delete the whole folder** | Same. |
| `admin_html/` etc. | Nothing — they do not exist yet | The deployment creates them. |
| `_core/` `_apps/` `_sql/` `_lang/` `_vendor/` `_install/` | Leave, or delete — either is fine | Fully replaced from the repository every time. |
| `_libraries/` | Leave alone | Holds dompdf, fetched during the build. |
| **`_auth_keys/`** | **🚨 NEVER DELETE** | See below. |
| **`_uploads/`** | **🚨 NEVER DELETE** | Everything anybody has uploaded through the portal — documents, photos, pastoral attachments, expense receipts. |
| **`_backups/`** | **🚨 NEVER DELETE** | The database snapshots. Deleting these at the same moment as a big structural change removes the one thing that could put it right. |

#### Why `_auth_keys/` is the one that cannot be recovered

It holds two things: the database connection details, and `enc.key` — the key used
to scramble sensitive settings before they are stored.

The database details can be typed in again. **The key cannot.** Everything
scrambled with it becomes permanently unreadable if it is lost: saved API keys for
payments, email and text messaging; every member's two-factor authentication
secret; the browser-notification signing key; Gift Aid and integration
credentials. There is no recovery, because that is the entire point of it.

The deployment never uploads or deletes this folder — it is excluded from every
mirror. The danger is a person tidying up by hand.

#### So: wipe everything, or wipe selectively?

**If the server has never had a real installation** — no database set up, nothing
uploaded, no `_auth_keys/enc.key` — then wiping the whole base folder is simplest
and carries no risk. Check for the key file first:

```bash
ls -la /home/USER/portal.millrdsdacambridge.uk/_auth_keys/
```

If that folder is missing or empty, there is nothing to lose.

**If there IS a real installation**, delete only the three web-root folders and
leave the rest:

```bash
cd /home/USER/portal.millrdsdacambridge.uk/
# Take a copy of the irreplaceable folder first, somewhere outside the web base.
cp -a _auth_keys ~/auth_keys_backup_before_public_door

rm -rf public_html public_html_beta public_html_dev
# _auth_keys, _uploads and _backups are deliberately NOT in that line.
```

#### The order to do it in

1. Land the #493 work so the deployment understands the new layout.
2. Copy `_auth_keys/` somewhere safe, outside the web base folder.
3. Delete the three old web-root folders.
4. Set `SFTP_PATH_ROOT_DIR` and the six `SFTP_PATH_*_DIR` settings, and delete the
   three old ones. **Done on 11 September 2026** — see 3b.
5. Re-point `portal.millrdsdacambridge.uk` at `admin_html` in the DreamHost panel,
   and point the new public address at `public_html`.
6. Deploy **alpha first**, with the dry-run option switched on, and read what it
   says it would delete before letting it run for real.
7. Check the alpha management portal still signs in, then check the alpha public
   address answers "not found" for everything — because nothing is published yet.
8. Only then do beta, then main.

#### One thing the deployment used to get wrong

**This no longer applies, and is kept only so the lesson is not lost.** Until the
deploy was rewritten (#499, commit `c61211e`), a constant `WEB_ROOT_EXCLUDES` in
`.github/workflows/deploy.yml` carried a comment saying the shared upload "runs
without `--delete`, so any other server-managed contents of `_libraries/` are
preserved". That was untrue: the upload did use `--delete` and `_libraries/` was
not excluded, so anything put there by hand was removed on the next deploy.

**Neither the constant nor that comment exists any more** — `grep -rn
WEB_ROOT_EXCLUDES .github/` finds nothing. The lesson that survives the rewrite:
the mirrored folders are mirrored with `--delete`, so **nothing you place on the
server by hand inside them survives a deploy**. Check what the current workflow
excludes before relying on anything staying put.
```

**Shared-base note.** The shared `_core/`, `_vendor/`, `_sql/` etc. upload to
`dirname()` of whichever per-branch path applies. When all three paths share
one parent (the default — recommended for the WebMS-Intra single-site setup),
all branches' shared code lands in the same place. Point them at different
parents if you want full isolation.

### 4. Enable the kill switch

```bash
gh variable set SFTP_ENABLED --body 'true'
```

While `SFTP_ENABLED != 'true'`, all deploy runs no-op.

### 5. Repo settings (one-time UI clicks)

1. **Settings → General → Pull Requests → Allow auto-merge** = ON.
2. **Settings → Branches → Add rule** on `alpha`, `beta`, and `main`:
   - Disable allow-deletions
   - Disable allow-force-pushes
   - (Recommended) Require status check on `main`

### 6. Verify

```bash
gh workflow run deploy.yml --ref main
gh run watch
```

### Rotating the SSH key

1. Generate a new keypair
2. Add the new public key on DreamHost (don't remove the old yet)
3. Update `SFTP_KEY` in GitHub
4. Trigger a manual deploy to confirm
5. Remove the old public key from DreamHost

---

## dompdf at deploy time

Expense PDF generation depends on dompdf in `_libraries/dompdf/`. The library
is **not** committed to this repo — `tools/download-dompdf.sh` fetches the
pinned version at deploy time and the lftp mirror uploads it as part of the
shared `web/` sync. Update the pinned version by editing `DOMPDF_VERSION` in
that script.

For local development:

```bash
bash tools/download-dompdf.sh
```

The script is idempotent — re-runs skip if the right version is already present.

---

## Per-site branding flow

The portal supports per-site visual branding via `tblSites` columns. Site
admins can override the brand colour, logo and favicon per-site without
touching code.

### Data model

`tblSites` columns relevant to branding (see migration `037_site_favicon.sql`):

| Column         | Type         | Purpose                                          |
| -------------- | ------------ | ------------------------------------------------ |
| `siteName`     | VARCHAR(255) | Display name used in nav, page titles, footer    |
| `logoPath`     | VARCHAR(500) | Header logo URL/path (any image format)          |
| `faviconPath`  | VARCHAR(500) | Browser-tab favicon URL/path; NULL = default     |
| `primaryColor` | VARCHAR(7)   | `#RRGGBB` hex; default `#5e6ad2` (Linear indigo) |
| `copyrightOrg` | VARCHAR(255) | Footer copyright holder                          |

### How a value flows from DB to UI

1. `Site::loadSiteRow()` selects the row into a class-level cache. (It is
   private, and reached through `Site::init()`; this line named it
   `loadCurrentSite()`, which no code has ever had.)
2. `Site::branding('color' | 'logo' | 'favicon' | 'name' | …)` returns the
   relevant column.
3. `web/_core/templates/header.php` reads `Site::branding('color')`,
   derives `--portal-primary-rgb` (R,G,B) from the hex, and **inline-
   styles** the `<html>` element:

   ```html
   <html data-bs-theme="light"
         style="--portal-primary: #5e6ad2; --portal-primary-rgb: 94, 106, 210;">
   ```

4. `web/public_html/assets/css/portal.css` defines the design tokens
   inside `:root`. Because the `<html>` inline style has higher
   specificity than `:root`, the per-site primary wins. The derived
   variants (`--portal-primary-hover`, `--portal-primary-active`,
   `--portal-primary-subtle`) are auto-derived from the primary via
   `color-mix()` and shift along with it on any browser that supports
   color-mix (Chrome 111+, Safari 16.2+, Firefox 113+). On older
   browsers, the literal indigo hex fallbacks defined in `:root` apply.
5. `header.php` also renders `<link rel="icon">` from
   `Site::branding('favicon')`, with `/assets/images/favicon.ico` as the
   fallback.

### Admin UI

Umbrella admins manage all sites at **`/admin/sites/`**. The "New / Edit
site" modal has form fields for `siteName`, `siteKey`, `hostPattern`,
`logoPath`, `faviconPath`, `primaryColor` (color picker), `copyrightOrg`,
`timezone`, and active status.

The save handler at `web/public_html/admin/sites/save.php` validates the
primary colour as `#RGB` or `#RRGGBB` and falls back to the indigo default
on invalid input.

### Defaults for white-label deploys

`tblSites` ships with the global "WebMS Intra" defaults. New sites
inherit `#5e6ad2` until the admin sets their own brand colour. Logo and
favicon default to `/assets/images/logo.svg` and
`/assets/images/favicon.ico` respectively.

### "Powered by &lt;product&gt;" footer attribution

When a site uses CUSTOM branding (any branding field differs from the
ACTIVE product brand — see "Two-layer brand model" below), the footer
renders a small "Powered by &lt;product&gt;" attribution after the
copyright line, where `<product>` resolves via `Site::productName()`.
Sites running the active product brand defaults don't show it — the
copyright line already names the product.

Detection (`Site::usesCustomBranding()` in `web/_core/Site.php`):

- `siteName` differs from `Site::productName()` (active product name
  resolved from `product.name` setting / `PORTAL_PRODUCT_NAME_DEFAULT`
  constant / `Site::DEFAULT_SITE_NAME` cold-start fallback), OR
- `logoPath` differs from `Site::DEFAULT_LOGO_PATH`
  (`'/assets/images/logo.svg'`), OR
- `primaryColor` differs from `Site::DEFAULT_PRIMARY_COLOR`
  (`'#5e6ad2'`, compared case-insensitively), OR
- `copyrightOrg` is non-empty (default is NULL), OR
- `faviconPath` is non-empty and differs from the default favicon path

Admins disable attribution globally via the `branding.hidePoweredBy`
setting in `/settings/` (set to the string `'true'`). Default is
`'false'`, so attribution is on out-of-the-box for custom-branded
deploys.

Markup lives in `web/_core/templates/footer.php`; styling is in the
`.portal-powered-by`, `.portal-powered-by-prefix`, and
`.portal-powered-by-mark` rules in `portal.css`. The mark class is a
hook for future hyperlinking when the product landing page exists.

The same detection ALSO drives a `<meta name="generator" content="<product>">`
tag in `web/_core/templates/header.php`. This is the standard SaaS / CMS
attribution mechanism — invisible to humans, picked up by site analysers
like Wappalyzer + "View page source" + browser dev tools.

---

## Two-layer brand model (issue #296)

There are TWO independent branding layers in the portal. They compose
top-down at render time.

```text
┌─ Layer 1 — PRODUCT (system) ────────────────────────────────────┐
│   Set ONCE at install via the installer's Step 1.5 picker.       │
│   Lives in tblSettings (siteID=NULL) as product.* + portal.industry. │
│   Drives: <meta name="generator">, footer "Powered by …",        │
│   X-Powered-By header, PWA manifest name/description,            │
│   installer wizard heading + footer.                             │
└──────────────────────────────────────────────────────────────────┘
                       ▼ overridden by ▼
┌─ Layer 2 — TENANT (per-site, already shipped) ──────────────────┐
│   Set per-site via /admin/sites/<id>/branding.                   │
│   Lives in tblSites columns + `branding.*` settings.             │
│   Drives: page chrome (siteName, logo, colour, favicon),         │
│   copyright org, "Powered by …" visibility opt-out.              │
└──────────────────────────────────────────────────────────────────┘
```

**Resolution rule everywhere**: tenant override > product default >
hardcoded constant.

### Why two layers

Tenants already had `branding.*` (Linear-style per-org skin). Adding a
SECOND layer above lets the same codebase ship as different sub-brands
without forking — `WebMS Intra` for the generic install, `ChurchMS` for
church installs, future `SchoolMS` / `CharityMS` etc. for other verticals.
Tenant branding stays decoupled; an install branded as `ChurchMS` can
still be deployed for `Mill Road SDA Cambridge` and the latter wins in
the chrome.

### Product brand presets

Defined in `web/_core/brand-defaults.php` as a `return [...]` array keyed
by `portal.industry` value (`generic`, `church`, `school`, `nonprofit`,
`community`, `small-business`). Each preset declares `name`, `tagline`,
`publisher`, `assetFolder`, and a human `displayLabel` for the installer
dropdown.

The file is **bootstrap-free** — it cannot reference any class, function,
or constant from elsewhere — so the installer (which runs before the
framework loads) can `require` it the same way the runtime does.

### Resolution helpers

| Helper | Reads from | Used by |
| --- | --- | --- |
| `Site::productName()` | `App::settings('product.name')` → `PORTAL_PRODUCT_NAME_DEFAULT` → `DEFAULT_SITE_NAME` | header meta, footer mark, X-Powered-By, manifest |
| `Site::productTagline()` | `App::settings('product.tagline')` → `PORTAL_PRODUCT_TAGLINE_DEFAULT` | manifest description, installer subtitle |
| `Site::productPublisher()` | `App::settings('product.publisher')` → `PORTAL_PRODUCT_PUBLISHER_DEFAULT` | footer copyright |

The `PORTAL_PRODUCT_*_DEFAULT` constants are seeded in `bootstrap.php`
from `brand-defaults.php`'s generic preset, so they're already valid
strings before `$SETTINGS` is loaded.

### Picking the brand at install time

The installer wizard's **Step 1.5 — Organisation Type** (encoded as the
string `'1.5'` in URLs, alongside the existing `'2.5'` data-choice page)
shows a dropdown of preset display labels. The chosen industry key is
stored in `$_SESSION['install_industry']`; all subsequent steps display
the matching brand. After `full_schema.sql` runs, Step 3 INSERTs the
preset's `name` / `tagline` / `publisher` values plus the industry key
into `tblSettings`.

### Changing brand post-install

The brand is fully reversible: admins can edit `portal.industry`,
`product.name`, `product.tagline`, or `product.publisher` via
`/admin/settings`. Changing `portal.industry` does **not** auto-rewrite
the other rows — the admin may have customised them. Re-seeding is a
manual SQL exercise if a full preset reset is wanted; documented as a
v1.x follow-up.

### Per-brand assets

Per-brand asset folders live at
`web/public_html/assets/images/brandkit/assets/<assetFolder>/{icon,icon-192,icon-512,logo}.svg`
(moved from the original `assets/images/brands/<assetFolder>/` path
during the WebMS/ChurchMS brand-kit drop — `manifest.php` and
`header.php` both resolve from `brandkit/assets/` today; `assets/images/brands/`
no longer exists). The brand-aware `manifest.php` controller resolves the
active preset's `assetFolder` and serves icons from there; it falls back
to the top-level `/assets/images/{logo,icon-192,icon-512}.svg`
placeholders if the per-brand file isn't present (belt-and-braces —
every preset directory that exists today does have the full set).
All six presets (`generic`/`webms-intra`, `church`/`churchms`,
`school`/`schoolms`, `nonprofit`/`charityms`, `community`/`communityms`,
`small-business`/`businessms`) now have distinct artwork (#306) — see
FEATURES.md's "PWA install prompt + manifest shortcuts + sub-brand
starter artwork" section for what's designer-finished (WebMS, ChurchMS —
outlined-vector wordmarks) vs. functional-starter (the four newer
presets — `icon*.svg` are full-quality hand-drawn-equivalent emblems on
the same token structure, but `logo.svg`'s wordmark line is set in a
system-font stack pending a designer pass).

### Where the brand is NOT applied

By design, these surfaces stay as `WebMS Intra` regardless of preset:

- PHP `@package WebMS Intra` / `@author MWBM Partners Ltd` doc-tags —
  these document code authorship, not user-facing branding.
- `error_log('[WebMS-Intra] …')` server-log prefixes — codebase identity
  for operators reading logs, not user-facing brand.
- `robots.txt` — comment header is brand-neutral so the static file
  can be served without going through a PHP controller.
- ~~`openapi.json` `info.title`~~ — no longer true: this now IS
  brand-aware via `openapi.php` + `api-spec.json` (#307, see "Deferred
  follow-ups" item 2 below).

### Deferred follow-ups from the brand-layer PR (#297)

Tracked as separate issues; called out here so they don't get lost
between PRs.

1. **Distinct sub-brand artwork** — ✅ **done (#306)**. All four
   previously-placeholder presets (`school`/`schoolms`,
   `nonprofit`/`charityms`, `community`/`communityms`,
   `small-business`/`businessms`) now have their own
   `assets/images/brandkit/assets/<assetFolder>/{icon,icon-192,icon-512,logo}.svg`
   set — the resolver needed no code changes, exactly as anticipated
   here. Remaining gap: each new `logo.svg`'s wordmark is system-font
   text, not the WebMS/ChurchMS kits' outlined vector glyphs — a
   designer pass on that one file per preset is still open (tracked
   in-repo, not a fresh GitHub issue — see each `logo.svg`'s own
   `<desc>`).

2. **`openapi.json` brand-aware conversion** — ✅ **done (#307)**. The
   spec now lives at `web/_core/api-spec.json` with
   `web/public_html/openapi.php` rewriting the `info` block per the
   active brand before emitting, routed via `tblRoutes` — the exact
   pattern anticipated below stayed accurate.

3. **`prayerRequests.*` → `prayer-requests.*` setting-key naming
   standardisation** — drift dating to the original prayer-requests
   app (PR #129). Every other app uses kebab-case slugs as setting
   prefixes (`prayer-requests` is the directory name, `tblRoutes`
   key, app slug). The setting key is camelCase. A migration would
   rename the rows in `tblSettings` AND update the three handlers
   that read `App::settings('prayerRequests.*')`. Mechanical work;
   only deferred because it touches a wide-blast-radius app and
   isn't urgent enough to bundle into this PR.

Each is filed as its own GitHub issue with `for consideration` label
so the per-item decision happens later. Search the issue tracker for
"deferred from #297" to find them.

---

## Theme modes + colour-blind palette

The portal and the standalone installer support three theme modes and an
opt-in colour-blind safe palette. Both are user-level preferences stored in
`localStorage` (per-device, per-browser).

### Theme modes

- **Light** — light surfaces, dark text (the design's default visual)
- **Dark** — dark surfaces, light text (`[data-bs-theme="dark"]` overrides)
- **Auto** — follows the OS `prefers-color-scheme` and live-updates if the
  system flips

Click the half-stroke circle icon in the navbar to cycle through the modes.
The icon updates to indicate the active preference (sun = light, moon =
dark, half-stroke = auto). Persisted as `localStorage.portal-theme` =
`light` / `dark` / `auto`. Missing key defaults to `auto`.

The same control exists in the standalone installer at `/install/` (top-
right of the page). Its tokens mirror `portal.css` and are kept in sync
manually.

### Colour-blind safe palette

Opt-in toggle (`localStorage.portal-cb` = `on` / unset). When enabled,
the eye icon in the navbar shows as active and the semantic colours
(success, danger, warning, accent) shift to a palette from Wong (Nature
Methods, 2011) that's distinguishable for deutan + protan colour
blindness (~95 % of CB cases):

- `--portal-success`: default `#16a34a` (green) → CB `#009e73` (bluish-green); dark CB `#5dd1a8`
- `--portal-danger`: default `#dc2626` (red) → CB `#d55e00` (vermillion); dark CB `#ff8a4d`
- `--portal-warning`: default `#d97706` (amber) → CB `#e69f00` (orange); dark CB `#ffc04d`
- `--portal-accent`: default `#06b6d4` (cyan) → CB `#56b4e9` (sky blue); dark CB `#7fc6f0`

Primary stays untouched — it's the site's identity colour and is
user/site-set (see "Per-site branding flow" above).

**Accessibility note:** CB-safe tokens reduce the risk of mis-reading
status colours, but **colour alone should never be the only signal**.
Components that convey state (badges, alerts, validation messages)
should also use icons or text labels. The PR template's security
checklist already mentions this for new UI work.

### Dyslexia-friendly reading mode (#46)

A third opt-in toggle (`localStorage.portal-read` = `on` / unset), applied
as `<html data-portal-read="on">` and wired identically to the CB toggle
(FOUC script → nav button → `portal.js` `initReadToggle()`). When enabled,
`[data-portal-read="on"]` in `portal.css` swaps the body font to a clean
system sans-serif stack (`Verdana, Tahoma, "Trebuchet MS", …` — no web font
is fetched, so it stays CSP-safe and adds no network cost) and applies wider
letter/word spacing, ~1.6–1.7 line height and left-aligned (never justified)
body text. These choices follow the **British Dyslexia Association Style
Guide (2023)**. It composes with the theme and CB toggles — all three can be
on at once. The nav button uses the `fa-book-open-reader` icon; the help
page `/help/getting-started` documents it for end users.

### Flow

```text
localStorage  ──FOUC script──▶  <html data-bs-theme="..." data-portal-cb="..." data-portal-read="...">
                                       │
                                       ▼
                              portal.css token / rule overrides
                                       │
                                       ▼
                              all components inherit
```

The FOUC script runs synchronously in `<head>` before first paint, so
the chosen theme + CB mode are applied with no flash. portal.js (and
the installer's inline JS) then wire up the toggle buttons and listen
for `prefers-color-scheme` changes when in `auto` mode.

### Where to find the code

- `web/public_html/assets/css/portal.css` — token blocks for light,
  dark, CB-safe (and dark + CB-safe combined)
- `web/_core/templates/header.php` — inline FOUC script reading
  localStorage and applying the attrs
- `web/_core/templates/nav.php` — theme + CB toggle buttons
- `web/public_html/assets/js/portal.js` — `initThemeToggle()` (cycles
  light → dark → auto), `initCbToggle()` (on/off), `initReadToggle()` (on/off)
- `web/_install/index.php` — installer mirrors the theme + CB controls inline
  (it's standalone, can't load portal.css/portal.js; the reading-mode toggle
  is portal-only for now)

---

## Workflow: `auto-merge-alpha.yml` — verified-correct-but-unfired (#147)

**Last verification:** 2026-06-19 (originally 2026-06-03) · **Status:** unfired (still 0 runs since creation)

**2026-06-19 re-audit**: `gh run list --workflow auto-merge-alpha.yml --limit 20` returns `[]`. `gh pr list --base alpha --state all --limit 20` returns `[]`. No PR has ever targeted the `alpha` branch; the workflow has never executed. Decision below stands — retained, not deleted. Re-audit every ~6 months; if alpha branch usage stays at zero for the next audit window, revisit deletion.

The workflow at `.github/workflows/auto-merge-alpha.yml` calls
`gh pr merge --auto --squash` on any PR whose `base` is `alpha`, then waits
for the merge and dispatches `deploy.yml` so the alpha environment updates
(the dispatch is required because GitHub's anti-recursion rule suppresses
push events attributed to `GITHUB_TOKEN`).

It has **zero runs** to date because **no PR has been opened against `alpha`**
in the entire history of the repo — every PR (#280 - #286 reviewed) has
targeted `main` directly. The workflow is structurally correct (trigger,
permissions, command syntax all match GitHub's auto-merge contract), it
simply has not had the opportunity to execute.

### Re-verification procedure when alpha is actually exercised

```bash
git checkout alpha
git checkout -b test-auto-merge-$(date +%s)
echo "" >> README.md  # trivial no-op
git commit -am "test: auto-merge smoke"
git push -u origin HEAD
gh pr create --base alpha --title "test: auto-merge smoke" --body "verifying #147"
# Wait ≤30s for "Auto-Merge Alpha PRs" run to appear in Actions tab.
# PR should show the green "auto-merge" badge.
# Once required checks pass, GitHub auto-merges and dispatches deploy.yml.
gh pr view --json autoMergeRequest,mergeStateStatus
```

### Decision

Workflow retained, **not deleted**. Cost of keeping it = zero (it
only runs when triggered). Cost of deleting = re-authoring the
trigger/permissions/dispatch logic if the team starts using alpha.
Delete only if alpha branch usage stays at zero for the next 6 months.

---

## Branch protection & rulesets — gotchas

Two GitHub mechanisms can guard a branch in parallel: classic **branch
protection rules** (Settings → Branches) and the newer **rulesets**
(Settings → Rules → Rulesets). This repo currently uses **both**, which
is allowed but creates traps. Read this before adding or modifying any
required check.

### Required-check name format

When you add a required status check to a ruleset or branch protection,
the **context name** you enter must match the exact string GitHub records
on the check_run — which for GitHub Actions is the **job's `name:` field**
(or the job ID if no `name:` is set). It is **not** the prefixed
`Workflow Name / Job Name` form you see in the PR UI's checks list.

Example. Given this workflow:

```yaml
name: PR Security Checks    # workflow name
jobs:
  security:                  # job ID
    name: Static security checks   # job name — THIS is what to enter
```

The PR UI shows `PR Security Checks / Static security checks (pull_request)`.
But the required-check context to enter is just:

```text
Static security checks
```

If you enter the prefixed form, the rule waits forever for a check that
never arrives — the same orphan condition that bit PR #104.

### Orphans: required check names with no producing workflow

A required check that no workflow emits silently soft-locks every future
PR. Common causes:

- A workflow gets renamed and the rule isn't updated
- A required check is added in anticipation of a workflow that never ships
- A `name:` field is changed without thinking about the rule

**`.github/workflows/repo-config-audit.yml`** runs weekly and on PRs that
touch any workflow. It calls `tools/audit-required-checks.py`, which
cross-references every required check name against every workflow job
name in the repo. Orphans fail the audit and post a comment on the PR.

Run the audit locally:

```bash
python3 tools/audit-required-checks.py
```

Exits 0 on clean (or degraded mode), 1 on orphans, 2 on unexpected error.

### Optional: enabling the full audit in CI

The default `GITHUB_TOKEN` in workflow runs **cannot read rulesets or
branch protection** — the GitHub Actions permissions model has no
`administration: read` key. Without that, the CI audit runs in
**degraded mode** (it can still emit a useful summary based on
workflow-file inspection, but can't catch orphans).

To unlock the full CI audit, create a **fine-grained personal access
token** scoped to this repo with **Administration: Read** permission,
then store it as a repo secret named `RULESET_AUDIT_TOKEN`:

1. GitHub → your account → Settings → Developer settings → Personal
   access tokens → Fine-grained tokens → Generate new token
2. Repository access: select **only** `WebMS-Intra` (least privilege)
3. Repository permissions: **Administration: Read** (rest stay None)
4. Generate and copy the token
5. In the repo: Settings → Secrets and variables → Actions →
   New repository secret → name `RULESET_AUDIT_TOKEN`, value =
   the PAT

The workflow auto-detects the secret and uses it when present; absent
secret = degraded mode, no failure. Local `gh` runs are unaffected
since you're already authenticated as an admin.

### Branch protection + rulesets are additive

If a check is required by **either** source, the PR is blocked until it
passes. Removing a rule from branch protection does not remove a
duplicate copy in a ruleset. When debugging "why is this PR blocked?",
inspect both:

```bash
# Branch protection on a branch
gh api repos/MWBMPartners/WebMS-Intra/branches/main/protection

# All active rulesets
gh api repos/MWBMPartners/WebMS-Intra/rulesets
gh api repos/MWBMPartners/WebMS-Intra/rulesets/<id>
```

### Modifying a ruleset's required checks

`PUT /repos/.../rulesets/<id>` with the full ruleset body (after stripping
server-only fields like `id`, `created_at`, `updated_at`, `_links`).
Easier-but-slower: use the GitHub UI at Settings → Rules → Rulesets →
[ruleset] → Edit.

### Solo-dev branch protection profile

Set on `main`, `beta`, and `alpha` to disallow deletions and force-pushes
without requiring PR reviews you can't satisfy:

- Disallow allow_deletions, allow_force_pushes
- Do not enforce_admins (so you can bypass when needed)
- No required_pull_request_reviews (would block solo dev)
- Required linear history on `main` only (forces squash/rebase)
- Required status checks: `Static security checks` on `main`

---

## Dev Site Access Control

The dev site (`public_html_dev/`) is **not** protected by `.htaccess` basic
auth. Instead, it uses the portal's own authentication and authorisation
system via `Gatekeeper::enforce('dev')`.

### How Access Works

1. User visits the dev site
2. If not logged in, they are redirected to the login page (MS365 SSO or local)
3. After login, the Gatekeeper checks:
   - **Root Admins** (`isRootAdmin=1` in tblUsers) -- always allowed
   - **Admins** (`isAdmin=1` in tblUsers) -- always allowed
   - **Role-based** -- if the user's roles match `portal.devAccessRoles` setting
4. If denied, they see a 403 error page and the attempt is logged

### Managing Dev Access

To grant a non-admin user access to the dev site:

1. Go to **Settings** in the portal admin UI
2. Find or create the setting `portal.devAccessRoles`
3. Set the value to a comma-separated list of role keys, e.g.: `Developer,Tester`
4. Grant the user that role on the Users page (the "Roles" button on their row) — since
   roles belong to one organisation each (#516), this gate deliberately reads it
   differently from an ordinary `App::hasRole()` check: it counts a role held in **ANY**
   organisation the user belongs to (`Roles::hasAnywhere()`), because `portal.devAccessRoles`
   is itself a portal-wide setting, not scoped to one organisation

This approach is better than `.htaccess` because:

- Uses the same SSO login (no separate passwords to manage)
- Role-based (grant/revoke via the Users page, not file editing)
- Audit trail (denied access is logged via Logger)
- Consistent UX with the rest of the portal

---

## Release channels (which copy of the portal an installation is)

- Order: `PORTAL_ENV`; else the first line of `<folder that holds _core>/.channel`; else the web folder's name when
  it is exactly `public_html` / `public_html_beta` / `public_html_dev`; else assumed live. Decided in
  `Portal\Core\Channel::decide()`; start-up (`bootstrap.php`) puts the answer in `PORTAL_ENV` (`prod`, `beta`,
  `alpha`, `dev`) and how it was decided in `PORTAL_ENV_SOURCE` (`environment`, `file`, `folder`, `assumed`).
- "Fail closed" (an installation that says nothing is shut to all but staff) was built first, in commit `7b30c99`,
  and replaced by the owner the same day: it refused every member of a live portal, and every scheduled job and
  payment notice, the moment the code was uploaded without a new file. Do not bring it back.
- The folder's name is the last part of `PORTAL_WEBROOT` **as a front controller set it** (`__DIR__`). It is never
  read from `DOCUMENT_ROOT` (on some hosting a visitor's Host header shapes that), and never from start-up's own
  stand-in name. The match is on the whole name, exactly as written.
- A command-line tool that loads `bootstrap.php` with no front controller skips the folder step: with no setting
  and no file it runs as assumed live, even on a copy that is beta by its folder's name. A tool that needs the
  folder step defines `PORTAL_WEBROOT` itself before loading start-up.
- PHP's error text is printed only on a pre-release channel that was stated (setting, file or folder). On such a
  copy a visitor who is not signed in CAN see a PHP warning on the addresses the staff gate leaves open (sign-in,
  Help, the offline page). Never on live, stated or assumed.
- The staff gate runs on beta, alpha and dev however they were decided, and never on live. Passing it needs the
  Admin or Root Admin flag; the database upgrade page behind it is for a Root Admin only.
- Only the file's first line is read, and it must end within 256 bytes. A byte-order mark and Windows, Unix or old
  Mac line ends are tolerated; capital letters and spaces round the word are forgiven; UTF-16 is not read.
- **Local work:** set `PORTAL_ENV` (for example `PORTAL_ENV=dev php -S …`), or keep your own `web/.channel`
  (ignored by git). The checkout's web folder is named `public_html`, so without either you run as live.
- **Self-tests that start the portal** set `PORTAL_ENV=prod` (access-pages, backup-page, erasure-pages, feed-admin,
  sign-in-limits, site-modes, db-strict-mode); channel-selftest sets each case itself.
- `/api-docs/` is run by the web server directly (the folder is real), so it loads start-up itself and asks the
  maintenance and staff gates itself. The `api-docs` row in `tblRoutes` does not reach it on Apache.

**When something looks wrong**

- **"The admin dashboard says this portal has not been told which copy it is."** Nothing states the channel, so
  it is treated as live. Create the `.channel` file (see above), unless several copies share one `_core`.
- **"A test copy is open to everybody."** Open `/health`. `"channelSource":"assumed"` means the copy's folder has
  another name and it has no file or setting. Give it one.

### Local Development

```bash
cd web
export PORTAL_ENV=dev
php -S localhost:8080 -t public_html
```

---

## Version Tagging Convention

Use [Semantic Versioning](https://semver.org/):

```
v{MAJOR}.{MINOR}.{PATCH}
```

- **MAJOR** -- breaking changes (e.g. DB schema changes requiring migration)
- **MINOR** -- new features, new app modules
- **PATCH** -- bug fixes, minor tweaks

Examples: `v0.1.0`, `v0.2.0`, `v1.0.0`

### Release Checklist

**Do not tag by hand.** This list used to end with `git tag -a` and `git push origin
v0.3.0`. Since #499 `package.yml` makes the tag and publishes the release itself on a
merge into `main`, and **a tag pushed by hand publishes nothing while still counting as
taken** — so pushing one blocks the name the automatic release wanted.

1. Ensure all changes are committed and merged to `main`
2. Verify the beta site works correctly
3. Raise the number in `web/_core/version.php` if the merge from `beta` has not already
   brought it up — a run that finds the number already released ends red saying so
4. Run pending SQL migrations on the live database (if any)
5. Merge `beta` into `main`. That merge builds the zip, makes the tag and publishes the
   Release, and separately starts the deploy
6. Monitor both runs in GitHub Actions
7. Verify the live health check at `<the customer's address>/health` (the address comes
   from `ADMIN_BASE_URL_LIVE`; there is no built-in one)

---

**The activity log and erasure (#626).** Part one gave `tblActivityLogs` a hand-written instruction in
`GdprEraser::catalogue()` with five columns in `nullCols`; part two made that instruction sufficient.

**The root cause was not the erasure.** An instruction matches ONE column, `userID`, and `Logger::activity()` took
the account number as an optional third argument that 279 of its 582 callers under `web/` left out — including
`Auth.php`'s `LoginLocal` line, which runs AFTER `$_SESSION` holds the account. Such a record had an empty column
and session facts saying `"userID": <the person>`. The method had the number in its hand and wrote NULL.

**Rejected: making the erasure chase those records** by matching on the session fingerprint. A shared machine or a
kiosk means one fingerprint is not proof of one person, and that is exactly how this eraser has wiped other
people's records before (#574). Matching on the account number inside the stored session facts was also rejected:
there is no index for it, and the #622 work measured one statement over 500,000 activity rows at 175 seconds,
which a shared host cuts off mid-erasure.

**So the record is made to name its person instead.** `Logger::activity()` fills `userID` from
`sessionFacts()['userID']` when the caller named nobody — that value only, never `waitingForCodeUserID` (somebody
part way through their second step) or `kioskUserID`. **That last one is a real member's account**, the one a shared
kiosk was handed to by a PIN, not "the machine's"; it is left out because a kiosk session outlives the person at it,
so naming them could blame them for somebody else's action. A kiosk record therefore still names nobody and an
erasure still cannot reach it — the same gap in miniature, left open because what a shared device's record means is
the owner's decision. It never overrides a caller that named somebody; all 303
such calls pass the actor, so it agrees with them rather than changing their meaning.

**Older records: `ActivityLogCleaner::linkAccounts()`, inside the existing walk.** It must run BEFORE the
blanking step of that same piece, because that step blanks every old whole-session copy and the copy is the only
place a pre-#622 record says whose session it was — a separate pass would always arrive to find the evidence gone.
That is why there is one walk, one mark and one button rather than two, and why migration 221 resets
`activityLog.cleanedThrough` instead of adding a second marker.

**The one way that step could do harm:** filling the column for somebody already erased would undo their erasure.
So it tests each account with `GdprEraser::emptiedAccountSql()` — the one definition the portal uses — and where
the account is erased or gone it CLEARS the record's address, browser name, headers, fingerprint and session
details instead. Planted as a fault, that mistake is caught by
`tools/activity-log-selftest.php`'s "cleared, not linked" check.

**Two gaps closed without being reported:** `auth/account/data-export.php` and the "filter by person" list on
Admin, Activity both read `userID`, so a person's own sign-ins were missing from both.

**The person's own download no longer includes the activity log's description** (the owner's decision): the field
is free text from some 550 call sites and about two dozen put a third party in it, so handing it to the member who
caused the record would be giving them somebody else's details on request. Two checks in
`tools/gdpr-coverage-selftest.php` hold it, because the obvious "improvement" is to put it back.

Tests: `tools/activity-log-selftest.php` (the logger's five cases and the cleaner's six, including that it obeys the
same kiosk and second-step rules as the logger, reads a copy that is cut short, and that migration 221 re-arms the
walk on a database that already holds the mark — the live path, which nothing covered); section 17 of
`tools/gdpr-eraser-selftest.php` holds the boundary — the erasure itself must never start reaching records that do
not name the person; phases 1 and 7 of `tools/e2e-migrations/run.sh` for migration 221.

## Coding Conventions

These are enforced across the codebase. Follow them in all new code.

- `declare(strict_types=1)` at the top of every PHP file
- Full IF notation: `if ($x === true)` not `if ($x)`
- Platform-neutral paths: use `DIRECTORY_SEPARATOR` instead of `/`
- Emoji-annotated comments for major code sections
- `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` for all output escaping
- No `<table>` tags for data display -- use `portal-data-list` component
- MySQLi prepared statements only -- never interpolate user input into SQL
- Use `Portal\Core\App::` methods over `global` keyword in new code

---

## The settings table: why a save used to add a row instead of changing one

**Read this before touching `tblSettings` or writing a settings seed.**

### What used to happen

A setting that applies to the whole portal is stored with its site left empty
(`siteID IS NULL`). A setting that applies to one site stores that site's
number. The table's rule for "no two rows may be the same" covered
`(settingKey, siteID)`.

That works for a per-site setting. It cannot work for a portal-wide one,
because **MySQL never treats one empty value as equal to another** — not even
to itself. Two rows both saying `('site.name', empty)` were not duplicates as
far as that rule was concerned.

Most saves in this codebase use "insert this, or update it if it is already
there". For a portal-wide setting the "already there" half never fired, so those
saves added another row instead of changing one — the settings-group screen and
the app on/off switch both behaved that way. (The separate dot-notation settings
editor was unaffected: it looks the row up by its identity number and updates
it.)

Counted from the seed files, a fresh install would begin with about **479
surplus rows**, and each affected save added more. That figure comes from
counting the seeds, not from measuring a real database.

Migration 187 fixed it by adding a derived column `siteScope` (the site number,
or `-1` for portal-wide) and putting the uniqueness rule on
`(settingKey, siteScope)`.

### Four rules that now matter

**1. The derived column must be VIRTUAL, never STORED.**
MySQL refuses a cascading foreign key on the base column of a STORED derived
column, and `tblSettings.siteID` has `ON DELETE CASCADE`. With STORED,
`full_schema.sql` will not load at all — *ERROR 1215: Cannot add foreign key
constraint*.

**2. A seed must never write `settingValue` in its duplicate clause.**
Before the fix this was harmless, because portal-wide rows never collided. Now
it would reset a value an administrator chose, every time the installer
replays. Use one of these instead:

```sql
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`          -- do nothing
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`)  -- default only
```

Migrations 012 and 021 had to be corrected for exactly this. If you add a seed
that writes `settingValue`, you are resetting somebody's choice on every
upgrade.

**3. Never assume "the newest row" is the one in use.**
Several screens — payments, captcha, SMS, translation, integrations — find the
row to change with an unordered `SELECT ... LIMIT 1` and update that one, which
in practice is the **oldest** copy. Migration 187's clean-up therefore keeps
the row that looks edited (its value differs from its own default, compared
byte for byte), then the most recently written, then the newest.

**4. `SIGNAL` cannot raise a clear error in a migration.**
These files run through the prepared-statement route, and MySQL 8.0 answers
`SIGNAL` there with *ERROR 1295: This command is not supported in the prepared
statement protocol yet*. Migration 187 works around it by selecting from a
table whose **name** is the message, which fails loudly and readably.

### What the fix deliberately did not do

It does not set out to change any value an administrator chose — but that is a
preference, not a guarantee. The clean-up keeps ONE copy of each portal-wide
setting and deletes the rest, choosing by judgement (does the value differ from
its own recorded default). Where several copies held different chosen values,
only one survives.

What it deliberately does NOT do is impose a NEW value on an existing site.
Two settings are never written to at all, because a leftover seed and a
deliberate choice cannot be told apart:

- **The minimum password length.** Existing sites may still require 8 while the
  project believes 12. `/help/admin` now warns about this and tells the
  administrator to check.
- **The expenses delete endpoint.**

Both were corrected at the seed instead, so newly installed portals land on the
right value without any existing database being touched.

## Migration numbers 168, 169 and 195 do not exist

They were never used — **three numbers, not two; this heading named only 168 and
169 until 27 October 2026.** Nothing depends on the numbering being unbroken:
both the installer and the migration runner list the files and sort them, so a
gap is invisible. Verify with `ls web/_sql/ | grep -E '^(168|169|195)_'`, which
finds nothing.

Do not "fill in" a gap. A file numbered 168 today would run before everything
from 169 onwards on every replay, which is not where new work belongs — give it
the next number after the highest that exists.

## SQL Migrations

Migrations live in `web/_sql/` as numbered `.sql` files. They are executed via
the web-based Migrator (admin-only) and tracked in `tblMigrations`.

### Adding a New Migration

1. Create `web/_sql/NNN_description.sql` (next sequential number).
2. **Put the same change in `web/_sql/full_schema.sql` as well, and make the two
   agree** (the owner's rule, 11 September 2026). A migration brings an existing
   database up to date; the install script builds a new one. A change in only one
   of them means a fresh install and an upgraded install end up different, which
   is what the end-to-end migration test exists to catch.
3. Make the migration safe to run twice. **Do NOT write `IF NOT EXISTS` or
   `IF EXISTS` on a column or an index to get that** — this step used to say to,
   and it was wrong: that form is MariaDB-only and a hard error on MySQL, which
   is what production runs. `tools/audit-checks/check_mariadb_only_ddl.py` fails
   on it. Test for the column or index first and act on the answer instead.
   (`CREATE TABLE IF NOT EXISTS` and `DROP TABLE IF EXISTS` are fine on both.)
4. Push to the working branch, then to **`alpha`** — that is the branch that
   deploys the dev channel. **This step used to say "push to `main` -- it
   deploys to dev", which was the live channel all along**: `main` deploys live,
   `beta` deploys beta, `alpha` deploys dev, and nothing else decides it
   (`.github/workflows/deploy.yml`). Following the old step would have run an
   untested migration against a customer's live database.
5. Run the migration on dev through Admin, Migrations.
6. Test thoroughly.
7. Promote the branch as usual. **Do not tag by hand** — see "Release
   Checklist"; the installation package and its tag are made by `package.yml`.

### Current Migrations

| File | Purpose |
|------|---------|
| `000_create_migrations_table.sql` | Migration tracking table |
| `001_create_tblErrors.sql` | Error logging |
| `002_create_expense_support_tables.sql` | Expense approvals + payments |
| `003_add_missing_settings.sql` | Required settings entries |
| `004_seed_routes.sql` | Initial route definitions |
| `006_local_auth_enhancement.sql` | Password resets, password policy settings, auth routes |
| `007_admin_routes.sql` | Admin section routes |
| `008_calendar_events_schema.sql` | Calendar / Events / Preaching Plan tables and seeds |
| `009_attendance_schema.sql` | Attendance service types, sessions, counts tables and seeds |
| `010_expenses_phase6.sql` | Expense multi-approver settings, file stage column, approver role column, view route |
| `011_auth_phase7.sql` | Linked accounts table, WebAuthn credentials table, Google/WebAuthn settings, account routes |
| `012_i18n_phase8.sql` | Adds locale column to tblUsers, i18n settings (defaultLocale, enabled) |
| `013_help_translations_route.sql` | Adds route for translations help page |
| `014_admin_integrations_route.sql` | Adds route for admin integration diagnostics page |
| `015_multisite.sql`                | Multi-site support: tblSites, tblUserSites, siteID columns, multisite settings/routes |
| `016_google_mail.sql`              | Google Workspace email settings: mail.provider, service account key, delegate user |
| `017_leadership.sql`               | Leadership app: roles, assignments tables, seed roles, routes, settings |
| `018_multisite_fixes.sql`          | Multi-site bug fixes: missing siteID on recurrence rules, open redirect prevention |
| `019_slug_uniqueness_multisite.sql` | Composite unique index on event slugs (slug + siteID) |
| `020_composite_indexes.sql`        | Composite indexes for multi-site query performance |
| `021_display_format_settings.sql`  | Configurable date/time display format settings |
| `022_expense_withdrawal.sql`       | Expense claim withdrawal feature, concurrent approval lock |
| `023_series_bulk_edit_route.sql`   | Event series bulk edit route |
| `024_csv_export_routes.sql`        | CSV export routes for expenses, attendance, leadership, admin |
| `025_install_upgrade_route.sql`    | Upgrade handler route for admin upgrade page |
| `026_notification_preferences.sql` | notifyPrefs JSON column, digest settings |
| `027_user_import_route.sql`        | User CSV import route |
| `028_event_rsvp.sql`               | tblEventRSVPs, capacity column on tblEvents, RSVP route |
| `029_announcements.sql`            | tblAnnouncements, announcement routes and app settings |
| `030_document_library.sql`         | tblDocCategories, tblDocuments, document routes and settings |
| `031_audit_trail.sql`              | tblAuditTrail for before/after change tracking |
| `032_totp_2fa.sql`                 | TOTP columns on tblUsers, tblTotpBackupCodes, 2FA routes |
| `033_reports.sql`                  | Reports/analytics dashboard routes |
| `034_workflow_engine.sql`          | tblWorkflows, Steps, Instances, Actions tables |
| `035_api_expansion.sql`            | REST API routes for events, attendance, users, announcements |
| `036_tasks_reminders.sql`          | tblTasks with recurrence, task routes and app settings |
| `full_schema.sql`                  | Consolidated schema for fresh installs (covers 000–036) |

---

## Portable DDL convention (MySQL 8.0 ∩ MariaDB)

**Supported engines — read the qualification, it matters.** This convention was
written for the overlap between MySQL 8.0 and MariaDB 10.4. Both of those are
now past their support dates: MySQL 8.0's extended support ended in April 2026,
MariaDB 10.4's maintenance ended in June 2024.

**Keep following the convention.** It avoids the syntax the two engines
genuinely disagree on, so it is the sensible thing to write while the target is
unsettled. But be clear about what it is and is not:

- It is a **coding rule**, not evidence. Following it does not show that any
  particular migration works on MariaDB, or on a newer MySQL.
- **No MariaDB version is covered by any automated test in this repository**, so
  MariaDB compatibility is unverified.
- Neither MySQL 8.0 nor MariaDB 10.4 is a supported version to be targeting.

Choosing and testing the versions we actually support is issue **#475**. Until
that lands, this convention is the safest thing to write — not a guarantee that
what you write will run everywhere.

Historic wording, kept so the original intent is readable. **Treat the
compatibility statements in it as claims that nothing here backs up, not as
current guidance:** MySQL 8.0+ (the production target at the time) and MariaDB 10.4+
(described at the time as compatible). MySQL-wire-compatible managed/cloud
databases — AWS RDS MySQL 8, Aurora MySQL 3.x, Azure Database for MySQL
(Flexible Server 8.0), GCP Cloud SQL for MySQL — were assumed to follow from
MySQL-8 compatibility on the reasoning that they accept the same DDL and reject
the same MariaDB-only extensions. **None of those platforms is covered by an
automated test in this repository either.** PostgreSQL, SQL Server, and
and MySQL-protocol-compatible platforms that are not MySQL — PlanetScale (built
on Vitess), TiDB and SingleStore — are explicitly **not supported**. They are
grouped here for a practical reason rather than a shared architecture: each
differs from MySQL in ways that would need assessing one at a time, foreign-key
handling among them. Supporting any of them would mean testing against it, not
just adjusting some SQL.

**Rule: never use `IF [NOT] EXISTS` on `ADD`/`DROP COLUMN`, `ADD`/`CREATE`/`DROP
INDEX`/`KEY`, or `CHANGE`/`MODIFY COLUMN`.** That clause is a MariaDB-only DDL
extension — MySQL 8.0 (all point releases, plus 8.4/9.x) rejects it with a parse
error, **ERROR 1064**, which aborts the whole statement/file under
`mysqli::multi_query` (the installer and Migrator both use it). `CREATE TABLE IF
NOT EXISTS` and `DROP TABLE IF EXISTS` are standard MySQL and remain fine to use
as-is.

Instead, guard every DDL object with an `information_schema` existence check and a
dynamic `PREPARE`/`EXECUTE`. This is the house idiom, already shipped and
production-proven under `mysqli::multi_query` in `web/_sql/037_site_favicon.sql`
(ADD COLUMN), `web/_sql/112_events_calendar_easy_wins.sql` (DROP INDEX), and
`web/_sql/138_worship_present_state.sql` (ADD UNIQUE KEY — its own comment notes
"some MySQL builds reject IF NOT EXISTS"). Conventions:

- One guard block per DDL object (per column / per index / per FK) — never batch a
  multi-column ALTER behind a single sentinel guard. `multi_query` aborts mid-file
  on connection loss, and per-object guards make a re-run self-healing.
- Keep the literal DDL textually contiguous inside the quoted string (don't split
  `` ALTER TABLE `tblX` ADD COLUMN `colY` `` across concatenation) —
  `tools/audit-checks/check_sql_columns.py` builds its column inventory from
  raw-text regexes that match inside string literals across newlines.
- Escape single quotes inside the literal by doubling (`''`), as in COMMENT clauses.
- Use `SELECT 1` as the no-op branch.

**A foreign key must point at a PRIMARY key or a UNIQUE key on exactly its own
columns, in the same order — not just any index.** MySQL 8.0 let a foreign key
point at an ordinary (non-unique) index; MySQL 8.4 does not. It turns on a rule
of its own, `restrict_fk_on_non_standard_key`, and refuses with ERROR 6125
otherwise. This was found on 24 September 2026 (#552): three composite foreign
keys added on a working branch each pointed at an ordinary `KEY` instead of a
`UNIQUE KEY`. Both ways a database can come into being were affected, but not
at the same point in the process. A FRESH INSTALL on MySQL 8.4 failed
IMMEDIATELY, inside `full_schema.sql` itself — this codebase folds every
migration's DDL back into that file too (see "`full_schema.sql` fold pattern"
below), so the same bad foreign key sat there directly, and the installer
never even reached the migration that introduced it. An UPGRADE never runs
`full_schema.sql` at all — it replays the numbered migrations in order against
whatever a real database already has — so it fails instead at the first
migration that adds one of the three bad links, and the Migrator stops there.
That upgrade path is the one that actually matters going forward: a fresh
install today already uses the fixed `full_schema.sql`, but an existing
customer's database can still reach the same three migrations directly. The
fix was to make those three indexes unique, which cannot fail on any existing
data because their leading column is already each table's own PRIMARY KEY.
Until 25 September 2026 the automated database test ran only against
`mysql:8.0.36`, which accepts these links, so it could not see this. It is
now built to run on MySQL 8.4 as well (see the paragraph on it below; not
yet run on GitHub). That catches the fault on a database built from today's
files, but not on an older customer database taking the upgrade route. The
check that catches it as text is
`tools/audit-checks/check_fk_references_unique_key.py`. It reads every
foreign key in `full_schema.sql` and the numbered migrations as text, and
checks each one four ways: against `full_schema.sql`'s own keys; against
what a fresh install's own replay of every migration would hold at that
exact point; against what a real upgrade, replayed in strict order, would
hold; and it checks that the two paths agree with each other.

The upgrade check starts from a fixed list of 32 keys. Thirty belong to
the 14 original tables, which predate the numbered migrations. The other
two are `tblTrustedDevices` keys that migration 047 creates under
different names; they are listed as known drift (#553). Each entry
records its columns, whether it is UNIQUE, and, for a named key, whether
it is a prefix key. The prefix length is recorded but not compared: a
prefix key can never be what a foreign key points at. Every run compares
the list with `full_schema.sql`, so a change to a listed key made only in
`full_schema.sql` is reported. The entries were produced by the check's
own parser. On 24 September 2026 they were confirmed by an independent
reading against all 93 commits that touch `full_schema.sql` (92 distinct
contents, across the paths `sql/`, `web/sql/` and `web/_sql/`), and
against a real MySQL 8.4.11 database upgraded from alpha.

The check cannot see everything. Three kinds of fault escape it. The
first is an edited, already-released migration. The second is a key
`full_schema.sql` declares UNIQUE that a name-only guard creates plain on
older databases; the check's own replay already holds a left-over key of
that name, which hides it. The third is a key that differs between
databases installed at different times. The last one already happens for
real. `tblEventCategories` was not in the install script from its first
version (commit 8136a3b, 18 February 2026) until commit 53bcf81 (8 March
2026), which added it with the two-column key `uq_cat_slug_site`.
Migration 008 (7 March 2026) creates the table with a one-column key,
`uq_category_slug`. Migration 019 tries to remove it under the wrong name,
so it never goes (#553). A database installed from an install script
older than commit 53bcf81 can have `uq_category_slug`: it does when
migration 008 created its `tblEventCategories`. (The install scripts from
commit 1016ccb, 7 March 2026, until 53bcf81 mark 008 as already run
without creating its tables, so a database installed from one of those
has no `tblEventCategories` until someone repairs it by hand.) A database
installed from commit 53bcf81 onwards does not have it. The check's
upgrade rule assumes every upgraded database has it. Its fresh-install
rules catch a link that relies on it only when that link also exists on a
fresh install. Such a link written only inside a migration's own
`CREATE TABLE IF NOT EXISTS`, for a table the install script already
declares, escapes both. Migration 009's `fk_att_sess_event` is a real
link that exists only that way (#554); it is harmless for this check,
because it points at a primary key. The script's own "WHAT THIS CANNOT
SEE" section gives the full list.

The owner approved running the end-to-end migration test (see
"End-to-end migration test" below) on MySQL 8.4 as well as 8.0.36, on
24 September 2026. It is BUILT to run on both, as two jobs side by side
(`.github/workflows/e2e-migrations.yml`'s matrix), since 25 September
2026 — but that workflow change has not yet run for real on GitHub. It
runs on a pull request or when alpha, beta or main is updated — in both
cases only when certain files change — or when started by hand; none of
those has happened yet. What has run is the harness itself, by hand, against real MySQL
8.0.36 and 8.4.11 containers on this Mac. By hand:
`E2E_MYSQL_VERSION=8.4.11 tools/e2e-migrations/run.sh`. `tools/e2e-migrations/run.sh`
builds every database from today's `full_schema.sql`: phase 1 loads it,
phases 2 and 3 carry on from phase 1's database, and phase 4 reloads it.
So on 8.4 it catches a fault that shows up on a database built from today's
files. One example is a link that relies on `uq_category_slug`, when the
link also exists on a database built from today's files. It will not
catch a fault that shows up only on a database built by an older release.
That covers an edited, already-released migration; a guarded branch that
runs only on an older database; a key that today's install script has but
older installs lack; and a link written only inside a migration's
re-declared `CREATE TABLE IF NOT EXISTS`. Proved on MySQL 8.4.11: an
edited released migration and a hidden name-only guard gave 0 errors the
test's way, and a real upgrade from alpha failed both with ERROR 6125.
So today nothing automated tests an upgrade from an older release. An "upgrade from the previous release"
phase would test one older starting point, the last release, not every
older install. It is proposed, awaiting the owner's decision. It is not
planned and not under way.

Since 12 October 2026 that is no longer the whole story. `run.sh` has two more
phases that upgrade an older database: phase 5 from the last release (the `main`
branch) and phase 6 from the install script of 6 March 2026
(`tools/e2e-migrations/fixtures/full_schema-2026-03-06.sql`, a frozen file that
must never be edited below its header; `run.sh` checks its checksum, and that its
header holds comments only). Both must end up equal to a fresh install. Migration
210 closed the differences this section lists: `uq_category_slug` is removed
(#553), `fk_att_sess_event` is added wherever no link from that column to
`tblEvents` exists (#554) and the install script now adds it too, and the two
`tblTrustedDevices` keys are renamed to the install script's names. Two links are
still added only by a migration (179: `fk_event_room`, `fk_event_venue`). The
foreign-key check itself has not been changed: its fixed list and its notes still
describe the old names, and it does not read `RENAME INDEX`.

**`check_fk_references_unique_key.py` is BUILT to run on every pull
request** since 25 September 2026 — the owner agreed to this on
24 September 2026 — but, like the paragraph above, that workflow change has
not yet run for real on GitHub: it only runs on a pull request, and this
branch has not yet been opened as one. Once it has, it runs as its own
step in `pr-security.yml` (check 23), reporting into the pull request
comment; it is not a hard gate. A crash or a missing schema file gets its
own visible section there rather than being read as a clean run. Unlike
checks 9-22, which live inside `pr-security.yml`'s heuristic-scan step and
are skipped whenever a pull request changes no PHP file under `web/` (a
gap tracked as #556), this check's own step never looks at which files
changed and keeps running even when an earlier step in the job failed — it
runs on every pull request, including one that only touches SQL. To run it
by hand:
`python3 tools/audit-checks/check_fk_references_unique_key.py --strict`.

**A database that ran an OLD (pre-#552) copy of migration 202 or 203 to
completion is never repaired by upgrading.** This can only happen on MySQL 8.0
or on MariaDB, because neither ever enforced the new MySQL 8.4 rule, so
nothing stopped the old file finishing — including if somebody re-ran an
old copy by hand, which lands in the same state as completion, not a
separate cause. It cannot happen on a real, deployed database: migrations
188-206 have never left this branch, and deploys come only from the three
release branches, `main`, `beta` and `alpha` (a manual run of `deploy.yml`
could target another branch, but every one of the 111 runs on record has
used one of those three). Such a database keeps its plain, non-unique
index: the Migrator never re-runs a migration it has already recorded as
done, so simply pulling in the fixed files does not touch it. A dump made
from one will not load into MySQL 8.4 (ERROR 6125), even with foreign-key
checks turned off. To repair it, run the fixed migration 202 and/or 203 by
hand (both are safe to re-run), or reinstall.

### Templates

**ADD COLUMN** — guard on `information_schema.COLUMNS`:

```sql
-- ➕ tblFoo.barColumn — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblFoo'
      AND COLUMN_NAME  = 'barColumn'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblFoo` ADD COLUMN `barColumn` VARCHAR(64) DEFAULT NULL COMMENT ''What it is (#NNN)'' AFTER `bazColumn`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

**ADD INDEX / CREATE INDEX** — guard on `information_schema.STATISTICS`:

```sql
-- 🔍 idx_foo_bar — guarded ADD INDEX
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblFoo'
      AND INDEX_NAME   = 'idx_foo_bar'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblFoo` ADD INDEX `idx_foo_bar` (`barColumn`, `bazColumn`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

Composite indexes produce one `STATISTICS` row per column, so `COUNT(*) = 0` / `> 0`
still works. For a unique index use `ADD UNIQUE KEY \`uq_…\` (…)` in the literal.
Standardise on `ALTER TABLE … ADD` rather than `CREATE INDEX` for consistency; both
are prepare-able.

**DROP INDEX** — same `STATISTICS` guard, inverted:

```sql
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblFoo'
      AND INDEX_NAME   = 'uq_old_index'
);
SET @sql := IF(@idx_exists > 0,
    'ALTER TABLE `tblFoo` DROP INDEX `uq_old_index`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

**DROP COLUMN** — `COLUMNS` guard, inverted:

```sql
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblFoo'
      AND COLUMN_NAME  = 'obsoleteColumn'
);
SET @sql := IF(@col_exists > 0,
    'ALTER TABLE `tblFoo` DROP COLUMN `obsoleteColumn`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

**MODIFY / CHANGE COLUMN:**

- `MODIFY COLUMN` to a fixed target definition is naturally re-runnable (the same
  MODIFY twice succeeds) — no guard needed unless the migration must be conditional
  on the *current* type, in which case guard on
  `information_schema.COLUMNS.COLUMN_TYPE`/`DATA_TYPE`.
- `CHANGE COLUMN` (rename) is NOT re-runnable — the old name is gone on the second
  run — so guard on the **old** name:

```sql
SET @old_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblFoo'
      AND COLUMN_NAME  = 'oldName'
);
SET @sql := IF(@old_exists > 0,
    'ALTER TABLE `tblFoo` CHANGE COLUMN `oldName` `newName` VARCHAR(64) NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

**ADD FOREIGN KEY** — guard on `information_schema.TABLE_CONSTRAINTS`:

```sql
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME        = 'tblFoo'
      AND CONSTRAINT_NAME   = 'fk_foo_bar'
      AND CONSTRAINT_TYPE   = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `tblFoo` ADD CONSTRAINT `fk_foo_bar` FOREIGN KEY (`barID`) REFERENCES `tblBar`(`barID`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

### `full_schema.sql` fold pattern: an ALTER's FK target is created LATER in the file

`full_schema.sql` contains **zero ALTER statements** — every migration's DDL folds
inline into the relevant `CREATE TABLE` block so a fresh install reproduces the
end-state of replaying every numbered migration. That's straightforward for a new
COLUMN or plain KEY index (no cross-table dependency), but breaks down for a FK
whose *referenced* table is created **further down** the file than the table being
altered — MySQL raises **errno 1824** ("Failed to open the referenced table") for a
forward-referencing FK inside a `CREATE TABLE`, and `full_schema.sql` has no
`FOREIGN_KEY_CHECKS` toggle to paper over it.

**First occurrence: migration 179 (#436)** — `tblEvents` (created at the very top of
`full_schema.sql`, alongside the earliest core tables) gained nullable
`venueID`/`roomID` columns whose FKs point at `tblVenues`/`tblVenueRooms`
(`web/_sql/170_venue_bookings.sql`), created thousands of lines later. `tblEvents`
itself can't move — dozens of later tables FK it — so the fold splits in two:

- The **columns + their plain KEY indexes** fold inline into `tblEvents`' `CREATE
  TABLE`, exactly like any other additive column (no cross-table dependency).
- The **FK constraints are deliberately left OUT of `full_schema.sql` entirely** —
  a comment at both the altered table's fold point and the referenced table's
  section explains why and points at the migration. The installer replays every
  numbered migration **after** loading `full_schema.sql` (see "SQL dialect trap" in
  `.claude/CLAUDE.md`), so migration 179's own guarded `ADD CONSTRAINT` blocks add
  both FKs on that replay — by the time they run, `tblVenues`/`tblVenueRooms`
  already exist (either from a real historical migration 170 run, or from
  `full_schema.sql`'s own `CREATE TABLE` for them earlier in the same install). End
  state is identical whether the install was upgraded from a live migration 170+179
  history or built fresh from `full_schema.sql` + migration replay.
- `check_schema_seed_parity.py` only compares migration filenames/settings
  keys/route keys (not FK presence), so this split creates no parity finding; the
  `179_event_venue_link.sql` filename still needs its own `tblMigrations` seed row
  in `full_schema.sql`'s seed block, same as any other migration.

Reach for this split pattern whenever a later migration ALTERs a table that sits
*before* its FK target in `full_schema.sql`'s creation order — it is NOT limited to
`tblEvents`/venues, just the first time the codebase needed it.

Note: this checks constraint *name* existence only — a same-named FK with a
different definition is silently accepted (the same tolerance the old
`IF NOT EXISTS` forms had for columns). Acceptable, but worth knowing.

### PREPARE-ability caveat (MySQL 8.0 manual — "SQL Syntax Permitted in Prepared Statements")

Prepare-able (everything the templates above need): `ALTER TABLE`, `CREATE INDEX`,
`DROP INDEX`, `CREATE TABLE`, `DROP TABLE`, `RENAME TABLE`, DML, `SET`, `SHOW`.

**NOT prepare-able** — do not try to wrap these in the guard idiom:
`CREATE`/`DROP TRIGGER`, `CREATE`/`ALTER`/`DROP PROCEDURE`/`FUNCTION`/`EVENT`,
`ALTER VIEW`, `LOCK TABLES`. If a future migration needs a *conditional*
trigger/procedure, the condition has to move to PHP (Migrator/installer side) — the
SQL-file guard idiom cannot express it. MariaDB's prepare-able set is a superset of
MySQL's, so the intersection constraint is exactly the MySQL list above.

### Replayability rule

`web/_install/index.php` loads `full_schema.sql` and then **replays every numbered
migration file in order**, deliberately ignoring `tblMigrations` (see the comment
above the replay loop) — this is how a stale partial install catches up to the
latest schema. That means **every migration must be a replayable no-op** on an
up-to-date schema:

- Every DDL statement needs an `information_schema` guard (per the templates
  above) — a bare `ADD COLUMN`/`ADD INDEX`/`ADD CONSTRAINT`/`DROP INDEX` fails with
  1060/1061/1826/1091 the moment it re-runs against a schema that already has the
  object.
- Every seed `INSERT` (including a migration's own `INSERT INTO tblMigrations
  (filename) VALUES (...)` self-record — `filename` is `UNIQUE KEY
  uq_filename`) needs `ON DUPLICATE KEY UPDATE`, `INSERT IGNORE`, or a `WHERE NOT
  EXISTS` guard, or it fails with ERROR 1062 on replay.

### `check_sql_columns.py` false positive: a table name embedding a bare SQL keyword + a short alias (#150)

`tools/audit-checks/check_sql_columns.py`'s `SELECT_RE` locates a query's table
name with `FROM\s+\`?(tbl\w+)\`?\s*(?:WHERE|ORDER|GROUP|LIMIT|;|…|\s+(?:LEFT|
INNER|RIGHT|OUTER|JOIN))` — it requires the greedy `\w+` table-name capture to
be *immediately* followed (mod whitespace) by one of those bare keywords. When
the SQL text instead puts a short **alias** right after the table name (e.g.
`FROM tblSmallGroupMembers m INNER JOIN …`), the full-length match fails and
the regex engine backtracks the `\w+` capture shorter and shorter — and if the
table name happens to **contain one of those keywords as a bare substring**
(`tblSmallGroup*` all contain "Group"), the backtrack eventually lands on
`tblSmall` + `Group…`, which matches the literal `GROUP` alternative (no word
boundary required) and stops there. The checker then reports the truncated,
unregistered `tblSmall` as an "unknown table", even though the real table
(`tblSmallGroupMembers`) is correctly defined in `full_schema.sql`. This is
NOT a real defect in the SQL — verified by hand-tracing the regex and
confirmed empirically (`python3 -c '...'` against the exact pattern).

**Workaround** (no audit-script change needed): never put a short alias
*immediately* after `FROM tblKeywordEmbeddingTable` — either drop the alias
entirely and qualify columns with the full table name (`tblSmallGroupMembers.
userID`), or reorder the join so the keyword-embedding table is introduced via
`JOIN` instead of being the primary `FROM` target (the checker's regex is
strictly `FROM`-anchored — it never inspects text after `JOIN`, so an alias on
a *joined* table, however named, is invisible to it and always safe). Both
forms are semantically identical to the aliased original; MySQL still returns
the column under its own unqualified name either way. See
`web/_core/SmallGroups.php` (`listGroups()`, `groupsFor()`, `membersOf()`,
`pushHeadcountToAttendance()`, `attendanceStats()`) and
`web/_apps/auth/account/data-export.php`'s `smallGroupMemberships`/
`smallGroupAttendance` blocks for worked examples. Will recur for any future
table whose name embeds `GROUP`/`ORDER`/`LIMIT`/`WHERE` as a bare substring
right after `tbl` — check new table names against this before aliasing them.

### `check_sql_columns.py` can now read a JOINed SELECT's `alias.column` names (#519/#520)

Before 17 September 2026 this checker gave up completely on any SELECT with a
JOIN or a short table alias — a **bare** column name in a joined statement
could belong to either table, and guessing would make the checker cry wolf
and get switched off, so it skipped the whole statement (counted under
"skipped — join or table alias present").

That was too cautious for one common case: a name written as
`shortname.column` says exactly which table it belongs to. Nothing has to be
guessed. `Events::promoteFromWaitlist()` selected `u.email` from a
`JOIN tblUsers u`; `tblUsers` has never had a column called that — the real
name is `emailAddress`. The query threw on every call, was silently caught,
rolled back and logged one line nobody reads, so **the waiting-list feature
has been completely dead since it shipped, and nothing on screen ever said
so** (#520). The checker's own bare-name scan could never have caught this —
`u.email` sits in a two-table JOIN — which is exactly the gap this closes.

**What it does now:** of the statements it still skips as "join or alias",
those that name at least one `alias.column` get a SECOND, narrower reading —
only qualified names, never bare ones. Measured on the real tree: 2,739
`alias.column` names checked, 0 false alarms, and it found both `u.email`
(#520) and a second real fault it turned up along the way, the Leadership
API's `a.assignedAt` (`tblLeadershipAssignments` has no such column — see the
CHANGELOG entry for both fixes).

**What it still cannot do** — read the full account in the script's own
header ("THE SELECT-ALIAS READING") before touching this again:
- A statement whose column list holds a quoted value before `FROM` (e.g.
  `COALESCE(e.eventName, "— no event —")`) is not recognised **at all**, so
  this reading never even sees it. This is why it did **not** catch
  `admin/live/chat.php`'s real, live `m.flaggedReason` fault — that one was
  found by hand during the same sweep and fixed in the same commit, but the
  checker still cannot see that shape.
- A bare column name in a joined statement is still not read — unchanged.
- Column names are compared **without regard to case**, because MySQL
  compares them that way (proved: `SELECT d.FILENAME FROM tblDocuments d`
  runs fine even though the schema spells it `fileName`). Comparing
  case-sensitively was tried first and immediately cried wolf on that real,
  correct query — do not "tidy" this back to a case-sensitive compare.
- An unresolvable short name (one the statement itself never declared) is
  skipped and counted, never reported — reporting it would risk accusing a
  correct column of a table this reading could not identify.

There is no self-test file dedicated to `check_sql_columns.py` — see the
script's header for why (it follows its own established practice of a small
throwaway fixture file per new rule, run once and deleted, rather than a
permanent fixture suite).

---

## File Structure Quick Reference

All paths below are relative to `web/` (the deployable root):

| Path | Purpose |
|------|---------|
| `_core/` | Framework classes (`Portal\Core` namespace) |
| `_core/templates/` | Shared page templates (header, footer, nav, errors) |
| `_vendor/simplejwt/` | Vendored RS256 JWT verifier (no Composer) |
| `_sql/` | Numbered SQL migration files |
| `_lang/` | I18n translation files (en.php, cy.php, …) |
| `_install/` | Installation wizard and upgrade handler |
| `public_html/` | The single web-root source; branch-based deploy maps this to `public_html/` (main), `public_html_dev/` (alpha) or `public_html_beta/` (beta) on the server |
| `public_html/{app}/` | App controllers (e.g. `expenses/`, `auth/`, `dashboard/`) |
| `_auth_keys/` | Credentials and encryption keys (gitignored, created by installer) |
| `_uploads/` | User file uploads (gitignored) |
| `_backups/` | Server backups (gitignored) |
| `_libraries/` | Self-hosted libs e.g. dompdf (gitignored) |
| `_includes/` | Shared includes (future) |
| `_functions/` | Shared functions (future) |

---

## Adding a New App Module

1. Create directory: `web/public_html/{appname}/index.php`
2. Add route to `tblRoutes` (or create a migration)
3. In the app file, set page metadata and include templates:

```php
<?php
declare(strict_types=1);

use Portal\Core\Auth;

$pageTitle   = 'My App';
$pageSection = 'myapp';
$breadcrumbs = ['Dashboard' => '/', 'My App' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- App content here -->

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
```

4. If the app needs a settings-based enable flag, add `myapp.enabled = true`
   to `tblSettings`
5. The nav will pick it up automatically if configured in the template

---

## Translations (i18n)

The portal supports multiple languages via the `I18n` framework (`_core/I18n.php`).
All user-facing text is stored in **language files** under `web/_lang/`, one file
per locale. English (`en.php`) is the baseline — every other language file only
needs to include the keys it translates; missing keys fall back to English automatically.

### How It Works (The Big Picture)

```
User visits page
  → I18n checks: user DB preference → session → browser Accept-Language → default
  → Loads web/_lang/{locale}.php (e.g. lang/fr.php)
  → t('auth.sign_in') returns "Se connecter" instead of "Sign In"
  → Missing keys fall back to English automatically
```

### Language File Format

Each language file is a PHP file that returns a flat associative array.
Keys use **dot-notation** for logical grouping (e.g. `nav.dashboard`, `auth.sign_in`).

```php
<?php
// File: web/_lang/fr.php
declare(strict_types=1);

return [
    'nav.dashboard'    => 'Tableau de bord',
    'nav.sign_in'      => 'Se connecter',
    'auth.sign_in'     => 'Se connecter',
    'auth.password'    => 'Mot de passe',
    // ... only include keys you want to translate
    // anything missing falls back to English
];
```

### Key Naming Convention

Keys follow the pattern `{section}.{description}` using lowercase and underscores:

| Prefix         | Section                      | Example                                    |
| -------------- | ---------------------------- | ------------------------------------------ |
| `nav.`         | Navigation bar               | `nav.dashboard`, `nav.sign_out`            |
| `auth.`        | Login, password, account     | `auth.sign_in`, `auth.forgot_password`     |
| `dashboard.`   | Dashboard page               | `dashboard.welcome`                        |
| `expenses.`    | Expense claims               | `expenses.submit_title`                    |
| `calendar.`    | Calendar / Events            | `calendar.all_categories`                  |
| `attendance.`  | Attendance tracker           | `attendance.record_title`                  |
| `admin.`       | Admin panel                  | `admin.user_management`                    |
| `settings.`    | Settings page                | `settings.add_setting`                     |
| `help.`        | Help centre                  | `help.title`                               |
| `error.`       | Error pages (403/404/500)    | `error.page_not_found`                     |
| `common.`      | Shared UI elements           | `common.save`, `common.cancel`             |
| `email.`       | Email templates              | `email.greeting`                           |
| `format.`      | Date/number/currency formats | `format.date.short`                        |

### Step-by-Step: Adding a New Language

1. **Copy the English baseline** as a starting point:
   ```bash
   cp web/_lang/en.php web/_lang/fr.php
   ```

2. **Edit the file header** — update the language name and flag emoji:
   ```php
   /**
    * French (fr) Translation File 🇫🇷
    */
   ```

3. **Translate each string value** (the part after `=>`). Do NOT change the keys
   (the part before `=>`):
   ```php
   // ✅ Correct — only change the value
   'nav.dashboard' => 'Tableau de bord',

   // ❌ Wrong — never change the key
   'nav.tableau_de_bord' => 'Tableau de bord',
   ```

4. **Remove keys you haven't translated yet** — they'll fall back to English
   automatically. This is better than leaving English text in a French file.

5. **Check the locale is registered** in `_core/I18n.php` in the `$locales` array.
   All 13 currently supported locales are already registered:
   `en, cy, fr, de, es, pt, ar, he, fa, ur, zh, ja, ko`

6. **Test it** — visit any page and add `?lang=fr` to the URL, or use the
   language switcher dropdown in the navigation bar.

### Step-by-Step: Translating a String

When you see a string you want to translate:

1. **Find the key** — search `web/_lang/en.php` for the English text:
   ```bash
   grep -n "Sign In" web/_lang/en.php
   ```
   Result: `'auth.sign_in' => 'Sign In',`

2. **Add the key to your language file** with the translated value:
   ```php
   'auth.sign_in' => 'Se connecter',
   ```

3. **Save and test** — the change is live immediately (no build step needed).

### Parameterised Strings

Some strings include dynamic values using `:param` syntax:

```php
// English
'auth.too_many_attempts' => 'Too many attempts. Try again in :minutes minute(s).',

// French
'auth.too_many_attempts' => 'Trop de tentatives. Réessayez dans :minutes minute(s).',
```

The `:minutes` placeholder is replaced at runtime. Keep the `:param` names exactly
as they are in the English file — only translate the surrounding text.

### Pluralisation

Strings that change based on a count use `|` as a separator:

```php
// Two forms: singular | plural
'expenses.claim_count' => 'One claim|:count claims',

// Three forms: zero | one | many
'items.count' => 'No items|One item|:count items',
```

French example:

```php
'expenses.claim_count' => 'Une réclamation|:count réclamations',
'items.count' => 'Aucun élément|Un élément|:count éléments',
```

### RTL (Right-to-Left) Languages

RTL locales (Arabic, Hebrew, Farsi, Urdu) are handled automatically:

- The `<html>` tag gets `dir="rtl"`
- Bootstrap loads its RTL CSS variant
- Portal CSS applies margin/text-alignment overrides

No special action is needed when translating — just provide the translated text
and the framework handles the layout direction.

### Using Translations in PHP Code

In any PHP file loaded after bootstrap:

```php
// Simple translation
echo t('nav.dashboard');  // "Dashboard" or translated equivalent

// With parameters
echo t('auth.too_many_attempts', ['minutes' => 5]);

// With pluralisation
echo t('items.count', ['count' => 3]);

// Always escape for HTML output
echo htmlspecialchars(t('auth.sign_in'), ENT_QUOTES, 'UTF-8');
```

### Language Switcher

Users change their language via the globe dropdown in the navigation bar.
When a user switches language:

1. A `?lang=fr` query parameter is sent
2. The preference is stored in their session
3. If logged in, it's also saved to `tblUsers.locale` in the database
4. On next login, their preference is loaded from the database automatically

### Admin Settings

Two settings control i18n behaviour (in the portal Settings page):

| Setting Key          | Purpose                                               | Default |
| -------------------- | ----------------------------------------------------- | ------- |
| `i18n.defaultLocale` | The default language for users who haven't chosen one | `en`    |
| `i18n.enabled`       | Whether the i18n system is active                     | `true`  |

### Translation Review / Approval Workflow

There is no built-in approval UI — translations are managed as code:

1. **Translator** creates or edits `web/_lang/{locale}.php`
2. **Developer** reviews the changes via Git pull request or code review
3. **Merge to `main`** — translations deploy to dev automatically
4. **Test on dev** — verify strings appear correctly in context
5. **Tag a release** — translations deploy to production

This keeps translations version-controlled, reviewable, and auditable.

---

## New Core Classes (v0.8.1)

### Container (`_core/Container.php`)

Lightweight dependency injection container that works alongside the existing static
`App` registry. Supports singleton and factory bindings with lazy resolution:

```php
$container = new Container();
$container->singleton('mailer', fn() => new Mailer($config));
$mailer = $container->get('mailer'); // same instance each time
```

Use `Container` for new service wiring; existing `App::db()`, `App::settings()` etc.
remain unchanged for backward compatibility.

### ApiRouter (`_core/ApiRouter.php`)

Dedicated API route dispatcher, extracted from the main `Router` class. Handles
all `api/{app}/{action}` patterns with JSON content-type enforcement, CORS headers,
and standardised error envelopes via `ApiResponse`. The main `Router::dispatch()`
delegates to `ApiRouter` for any path starting with `api/`.

#### A switched-off app's endpoints are refused (#478, 11 October 2026)

Switching an app off in Admin → Apps used to hide its pages and leave its data
endpoints answering, because an `api/…` address never reaches the page gate in
`Router::dispatch()`. Both ways in (`dispatch()` and `dispatchV1()`) now call one
private method, `requireAppSwitchedOn()`, after "does the handler file exist"
(404) and before the per-endpoint switch. An app that is off answers 403: "The
<app name> app is switched off for this organisation."

- **Whose switch.** For a request with a valid API key, the key's own
  organisation's (`AppRegistry::isEnabledForSite()`), whatever address it was
  sent to, because that is the organisation whose data the handler answers
  with. For everybody else, the organisation the address points at
  (`AppRegistry::isEnabled()`). The same choice `resolveEnabledFlag()` makes.
- **Which app.** `AppRegistry::API_FOLDER_APPS`, written out by hand: one line
  for each folder under `web/_apps/*/api`. Fourteen folders map to the app of
  the same name; `events` maps to `calendar` (Events is the Calendar app) and
  `livechat` to `cop-live-chat` (setting `chat.enabled`).
- **The three exceptions** (mapped to `null`, never refused): `push` (web push
  belongs to the portal; its own setting is `push.enabled`), `tours` (guided
  tours are part of the interface) and `users` (accounts; administrators only,
  each endpoint with its own switch).
- **A new `api` folder needs a line.** Without one the router refuses it with
  "This API endpoint is disabled" and writes the reason to PHP's error log. It
  cannot tell which switch applies, and guessing "none" would bring the fault
  back for every folder added later.

Pages that live in an app's folder under an address of their own are tied to
the app by a second hand-written list, `AppRegistry::ROUTES_OUTSIDE_PREFIX`,
which `appForRoute()` consults when no app's own address matches: `attend`,
`attend/save`, `calendar.ics` and `account/calendar-feed` (Calendar),
`recordings.rss` (Recordings) and `visit` (Visitors). **`unsubscribe` is
deliberately not listed:** people must be able to stop mail whatever the
Newsletter switch says.

`tools/app-switch-selftest.php` checks all of this with no database: every
folder has a line, every named app is registered, the exceptions are exactly
these, no other seeded page of a switchable app is left untied (it knows about
`unsubscribe` and `auth/invite`), and `ApiRouter` calls the gate in both ways
in. It compares the gate, and `isEnabledForSite()`, with a copy written out in
the test, word for word: a first version looked only for the gate's pieces and
still passed with the gate skipped for every request carrying a key. **So a
deliberate change to either function must be made in the test's copy too.**
Given a throwaway database it also runs `isEnabledForSite()`.

`tools/access-pages-selftest.php` (needs a throwaway database) asks the running
portal: for each of the 19 folders, with only that folder's app off, one
endpoint answers 403 naming that app; a key is judged by its own organisation;
a folder with no line is refused and its handler never runs; the stray pages
and the "disabled" page behave. It also runs the #545 handlers for real. It
copies `web/` to a scratch folder and starts PHP's built-in server, as part W
of `tools/feed-admin-selftest.php` does; do not run the two at the same moment
on one database. None of these is part of the pull-request checks yet; run them
by hand.

Not covered by #478: a switched-off app's scheduled jobs, the personal pages
under `account/` that belong to an app, `auth/invite`, `widget/countdown`, and
the router's short addresses (`e`, `f`, `a`, `os`).

### CsvExporter (`_core/CsvExporter.php`)

Generic CSV export helper used across five apps: expenses, attendance, leadership,
admin users, and activity logs. Accepts a column definition array and a MySQLi result
set, streams output with proper headers (`Content-Type: text/csv`,
`Content-Disposition: attachment`), and escapes fields to prevent formula injection.

### Validator (`_core/Validator.php`)

Input validation framework using pipe-separated rule syntax:

```php
$v = new Validator($_POST, [
    'email'  => 'required|email|max:255',
    'amount' => 'required|numeric|min:0.01',
    'date'   => 'required|date',
]);
if ($v->fails()) {
    $errors = $v->errors(); // ['email' => ['The email field is required.']]
}
```

Built-in rules: `required`, `email`, `numeric`, `integer`, `min`, `max`,
`date`, `in`, `regex`, `string`, `boolean`. Custom rules can be added via closures.

### Transaction Helpers

`App::beginTransaction()`, `App::commit()`, and `App::rollback()` wrap MySQLi
transaction methods for cleaner multi-statement operations:

```php
App::beginTransaction();
try {
    // multiple inserts/updates
    App::commit();
} catch (\Throwable $e) {
    App::rollback();
    throw $e;
}
```

---

## Error Handling Standardisation (v0.8.1)

All CSRF validation failures and OAuth errors now follow a consistent
**flash + redirect** pattern instead of mixed approaches (some pages used
`die()`, others rendered inline errors, others returned JSON):

- **CSRF failures** — set a flash error message in `$_SESSION['flash']` and
  redirect back to the originating form. The header template renders flash
  messages automatically.
- **OAuth errors** — capture error details, flash a user-friendly message,
  and redirect to the login page. Technical details are logged via `Logger`.
- **No remaining `die()` or bare `exit()` calls** — all early-termination
  paths use flash+redirect or `ApiResponse::error()` (for API endpoints).

This was tracked in Issue #82.

---

## Password policy & strength validation (#53 / PR #132)

`Auth::validatePassword()` and `Auth::passwordPolicy()` are the canonical
helpers — every password-set flow goes through them (reset, account
change-password, admin user create / update, and the standalone installer
which carries a self-contained copy).

**Settings (all `auth.password.*`):**

| Key | Default | Notes |
| --- | --- | --- |
| `minLength` | `12` | Bumped from 8 in migration 041 (OWASP ASVS L1) |
| `maxLength` | `128` | Defence against pathological inputs; bcrypt truncates at 72 anyway |
| `requireUppercase` | `true` | Independent of lowercase since #132 |
| `requireLowercase` | `true` | New flag — previously implicit |
| `requireNumber` | `true` | |
| `requireSpecial` | `true` | Any non-alphanumeric |

`Auth::passwordPolicy()` returns the active policy as a structured array
(rules list + min/max + required flags) so password forms can render
hints consistently. Forms also wire up the JS strength meter via the
`data-portal-password-input` + `data-portal-password-meter` attributes —
`portal.js` attaches the meter on every matching input; the installer
ships an inline copy because it loads before `bootstrap.php`.

**5-step score** mirrors the server policy:

- +1 length ≥ minLength
- +1 contains lowercase
- +1 contains uppercase
- +1 contains digit
- +1 contains symbol

Bands: 0-1 Very weak (red), 2 Weak (red), 3 Fair (warning), 4 Strong
(info), 5 Very strong (success).

---

## Multi-provider Captcha (#130)

`Portal\Core\Captcha` accepts three providers — Cloudflare Turnstile,
Google reCAPTCHA (v2 checkbox or v3 invisible-score), and hCaptcha — and
picks the active one based on an admin-configurable priority list.

**Settings:**

- `auth.captcha.priority` — comma-separated provider keys
  (default `turnstile,recaptcha,hcaptcha`); the first one with **both**
  site + secret keys configured wins.
- `auth.turnstile.{siteKey,secretKey}`
- `auth.recaptcha.{siteKey,secretKey,version}` (version = `v2` or `v3`)
- `auth.recaptcha.v3.{action,threshold}` (default action `submit`,
  threshold `0.5`)
- `auth.hcaptcha.{siteKey,secretKey}`

**Public API (unchanged contract from previous Captcha class):**

```php
Captcha::scriptTag()          // <script> tag(s) for the active provider
Captcha::widget()             // widget markup (or invisible hidden input for v3)
Captcha::verify($_POST)       // server-side verification
Captcha::isConfigured()       // true if at least one provider is wired up
Captcha::activeProvider()     // 'turnstile' | 'recaptcha' | 'hcaptcha' | ''
Captcha::listProviders()      // for the admin UI
Captcha::normalisePriority()  // for the admin save handler
```

**Admin UI** lives at `/admin/captcha` — SortableJS-powered drag-and-drop
priority list + per-provider key inputs + v2/v3 toggle + action / score
threshold inputs for v3.

**reCAPTCHA v3 verification** enforces **both** action match (anti-replay)
and score threshold; rejections are logged via `Logger::activity()` as
`CaptchaRejected` so probing surfaces in the activity log.

---

## Debug mode hardening (#54 / PR #133)

`Debug::isEnabled()` and `App::isDebug()` both refuse to enable debug
mode when `PORTAL_ENV === 'prod'`, regardless of admin status or query
params. Defence-in-depth:

1. `Debug::isEnabled()` — returns false in prod; `Debug::renderPanel()`
   is already gated on it. Attempts in prod are logged once per request
   as `DebugBlocked` activity (IP + path).
2. `App::isDebug()` — same prod refusal. The global exception handler
   in `bootstrap.php` already routes detailed traces through
   `App::isDebug()`, so stack traces / file paths can never leak in
   prod even on unhandled exceptions.
3. `bootstrap.php` — forces `display_errors`, `display_startup_errors`,
   and `html_errors` to `'0'` in prod. `error_reporting(E_ALL)` stays
   on so `Logger::phpError()` continues to capture everything.

---

## Anchor / link colour theme binding (PR #135)

`portal.css` binds `--portal-link` (and its hover / RGB variants) to
Bootstrap's `--bs-link-color`, `--bs-link-color-rgb`,
`--bs-link-hover-color`, and `--bs-link-hover-color-rgb` in both the
light `:root` and `[data-bs-theme="dark"]` blocks. Without these
bindings, every plain `<a>` / `.btn-link` / `.alert-link` / `.link-*`
falls back to the browser-default blue, which clashes hard in dark mode.

`_install/index.php` mirrors the same binding in its self-contained
inline `<style>` block because the installer doesn't load `portal.css`.

Per-site branding still flows through: `--portal-link` resolves to
`--portal-primary`, which `Site::branding()` overrides on
`<html style="--portal-primary: …">`, so anchor colour follows the
site's primary colour automatically.

---

## Calendar view modes (#136 / PRs #137 #138)

`web/public_html/calendar/index.php` is a thin **view router**. It:

1. Validates `?view=` against the whitelist
   (`day | week | weekdays | weekend | month | year | list`).
2. Resolves a visible date range from `?date=YYYY-MM-DD`
   (or `YYYY-MM`, `YYYY`, falling back to today on parse failure).
3. Fetches every event overlapping that range in **one** query
   (no per-cell N+1).
4. Delegates rendering to a per-view partial under `views/`.

**View partials (under `web/public_html/calendar/views/`):**

- `_shared_header.php` — date navigation (◀ Today ▶ + date picker),
  view switcher, filter row.
- `_day_columns.php` — **one** hour-timeline renderer reused by day /
  week / weekdays / weekend, parametrised by column count. Events
  position absolutely by start time and clip to each column's
  `[00:00, 24:00]` window. All-day events strip above the timeline.
- `day.php`, `week.php`, `weekdays.php`, `weekend.php` — thin wrappers
  around `_day_columns.php` with their own day list.
- `month.php` — 7-column 5/6-row calendar grid; up to 3 event pills
  per cell + "+ N more" link to day view.
- `year.php` — 12-month wall planner. 24-column grid (12 months ×
  day-number + content sub-columns), 31 day rows, blank cells where
  months are shorter. Multi-day event bands repeat on every covered
  day so they read as continuous strips. Auto-built legend with
  category swatches at the top.
- `list.php` — the original chronological card grid.

**Settings:** `calendar.defaultView` (default `month`).
**Per-user:** `localStorage['portal-calendar-view']` remembers the
last-used view across visits; URL `?view=` always wins.

**Category styling (#138):** `tblEventCategories.color` (hex,
regex-validated server-side) drives event background tints AND a
left-border accent. `tblEventCategories.displayStyle` toggles between
`'background'` (tinted band — default) and `'text'` (coloured text
on default background — used for Bank Holidays / Notable Days that
should flag a day rather than fill it).

**Per-month strap-lines (#138):** `tblCalendarMonthThemes` stores one
text line per `(siteID, year, month)`. Rendered as an italicised
strap-line under each month name on the year planner. Managed via
`/calendar/manage/month-themes` (year picker + 12 inputs; empty
values delete the row).

**Security:** all colour values are hex-validated by regex
(`/^#[0-9a-fA-F]{3,8}$/`) **before** persistence **and**
`htmlspecialchars`-escaped on output, blocking CSS injection via
crafted category colours.

---

## Prayer Requests (#129)

Self-contained app at `/prayer-requests/`. Single table
(`tblPrayerRequests`) with status lifecycle
(`pending → active → answered → archived`) and visibility flag
(`leadership | congregation`).

Anonymous public submission lives at `/prayer-requests/anonymous` —
no login required — and is gated by:

1. **CSRF** — session token issued by the GET form, verified on POST.
2. **Captcha** — whichever provider is active per the
   `auth.captcha.priority` setting (see above).
3. **RateLimiter** — same per-IP limiter used by the login form.
4. Hard-coded to `visibility = leadership` and `status = pending`
   (anonymous submissions never broadcast directly to the congregation).
5. Always redirects to the same generic success page so abusers can't
   fingerprint success vs failure.

Logged-in submitters get a "display as Anonymous" toggle — members
see "Anonymous", but the moderation queue still shows the real
submitter for pastoral follow-up.

---

## Asset Tracker (assets)

Top-level app at `/assets`, slug `assets`, model class
`Portal\Core\AssetRegister` (`_core/AssetRegister.php`), 13-table schema
(`tblAsset*`) shipped whole in migration 159 (#393/#395), with the
register/ownership/loans/maintenance/identifiers/licences/labels/lost-
and-found UI built out across #394-#402, and docs + a register CSV
export closing out Phase 1 (#403).

### Two meanings of "asset" — do not conflate

This codebase uses the word "asset" for two **completely unrelated**
things:

1. **`Portal\Core\Asset`** (`_core/Asset.php`) + `public_html/assets/` —
   web **infrastructure**. The `Asset` class builds CDN-with-fallback
   `<link>`/`<script>` tags (Bootstrap, Font Awesome) with SRI hashes;
   `public_html/assets/` is the static webroot directory (CSS, JS,
   images, fonts, vendor bundles, per-brand artwork). Nothing here is
   ever stored in the database.
2. **The Asset Tracker app** (`_apps/assets/`, slug `assets`, model
   `Portal\Core\AssetRegister`, tables `tblAsset*`) — a completely
   separate domain concept: the organisation's own physical/digital
   inventory (laptops, chairs, vehicles, software licences), with
   ownership, loans, maintenance, and a public lost-and-found page.

The two never share code, tables, or routes — but they DO share a
routing collision that needed an explicit fix (see below), so keep the
distinction in mind before assuming a grep hit for "asset" means the
other one.

### `.htaccess` — the bare `/assets` route rewrite

The physical static directory `public_html/assets/` and the Asset
Tracker's bare route `/assets` collide at the URL level. Apache's
existing "skip rewriting for an existing directory" rule
(`RewriteCond %{REQUEST_FILENAME} !-d`) would resolve a bare `/assets`
request to that static directory (a listing/404, depending on server
config) rather than routing it to the front controller, so the Asset
Tracker's own register page would never be reachable. `public_html/
.htaccess` carries a small rule ahead of the static-file/-directory
checks that intercepts ONLY the exact bare path (`^assets/?$`, with or
without a trailing slash) and sends it to `index.php` before those
checks run:

```apache
RewriteRule ^assets/?$ index.php [QSA,L]
```

Deep links like `/assets/item` or `/assets/edit` are untouched — they
don't match `^assets/?$` and fall through to the normal rewrite below
unaffected, since there's no colliding `assets/item/` directory on
disk.

### Register CSV export — no dedicated route

The register's CSV export (#403) is a query-string switch
(`?export=csv`) on the existing `_apps/assets/index.php` handler rather
than a new `assets/export` route — it reuses the exact same
`AssetRegister::listForSite()` call (and therefore the exact same
manager-gated confidential filter) the HTML view already makes, so the
two can never drift apart on what a given viewer is allowed to see.
The export column list is an explicit allow-list; `licenseKey` and
`publicToken` are never selected for it.

### Phase 3 (#411-#415) — stocktake, depreciation history, kits, kiosk mode, GS1 Digital Link/GEPIR

Five sub-issues, schema shipped whole in migration 161 (+ one small
migration 162 ENUM/route addition for the kiosk pass) — the same
"foundation migration first, logic in later passes" shape Phase 1 used.
The non-obvious bits a future dev needs, one per sub-issue:

**Kiosk security model (#414).** A kiosk terminal is a shared,
unattended, PUBLIC device — `assets/kiosk` and `assets/kiosk-action` are
seeded `isProtected = 0` and NEVER call `Auth::requireLogin()`. Identity
lives in TWO layers, both distinct from a normal portal login:

- `tblAssetKioskTokens` — the per-DEVICE credential (`?token=` in the
  URL), minted by an admin, shown once at mint time, never re-readable.
  `resolveKioskTerminal()` re-validates the `^[a-f0-9]{32}$` shape itself
  and treats "unknown" and "revoked" (`isActive = 0`) identically (no
  oracle) — same convention as `/a/{token}`.
- `tblAssetKioskPins` — the per-USER credential (a short PIN a member
  sets for themselves at `/assets/kiosk-pin`, a normal session-gated
  page). `kiosk.php`/`kiosk-action.php` NEVER set `$_SESSION['user_id']`
  — that would grant the shared device a real portal login. Instead they
  use their own `kiosk_token_id`/`kiosk_site_id`/`kiosk_user_id`/
  `kiosk_user_name`/`kiosk_expires` session keys, cleared on terminal
  switch or idle-timeout (server-enforced on every load, independent of
  the `assets.kiosk_auto_checkout` client-side countdown display).

Every kiosk check-in/out audits as entityType `'loan'` (it IS a loan
event) with the new `actorType: 'kiosk'` value +
`AssetRegister::audit()`'s `$actorUserIdOverride` parameter — the only
way to attribute a row to a real user with no session of their own. This
lets an auditor filter `actorType = 'kiosk'` to see every action taken on
an unattended device, separately from a logged-in `'user'` action.
`kioskCheckout()`/`kioskCheckin()` both reject a confidential asset with
the exact same message as a wrong-status one (no oracle) and never run
`cascadeKitCheckout()` — a kiosk hand-over is always a single asset.

**Reducing-balance depreciation — no `depreciationRate` column (#412).**
`tblAssets` has no stored percentage; `computeReducingBalanceValue()`
derives a CONSTANT yearly percentage once, from `costPence`,
`salvageValuePence`, and `usefulLifeYears`:

```
rate = 1 - (salvageValuePence / costPence) ^ (1 / usefulLifeYears)
```

then applies that same fixed rate compounding year-over-year from the
purchase date to today. Requires a POSITIVE `salvageValuePence` (unlike
straight-line, which doesn't) — an asset missing that input, or not
`depreciationMethod = 'reducing-balance'`, simply renders nothing on
item.php rather than guessing. `tblAssetValueHistory` (one row per
`(asset, valueDate)`, `uq_astvh_asset_date`) is the snapshot table the
`#405` cron writes to on a write-on-change-only basis — item.php's Value
History panel is pure read-only display over that table, never itself a
compute path.

**Kit design — one level deep, cascade only at checkout (#413).** A kit
is `tblAssets.parentAssetID` (column existed since migration 159,
unused until this pass). `attachToKit()` enforces, in order: both
assets exist on this site; child ≠ parent; the PARENT is not itself
someone else's component (no two-level nesting); the CHILD doesn't
already have components of its own (same reason); the child has no
EXISTING parent (detach first); the child has no open loan. A kit's
components only ever move together at the LOAN boundary —
`cascadeKitCheckout()`/`cascadeKitCheckin()` fire ONLY for a top-level
loan (`parentLoanID IS NULL`) on an asset that currently has components,
and `cascadeKitCheckin()` additionally prompts for a PER-COMPONENT return
condition (a kit's individual pieces can come back in different states
even though they went out together).

**Stocktake `verifyStatus` lifecycle (#411).** `tblAssetStocktakes`
(`status`: `open`→`closed`) owns a run; `tblAssetStocktakeItems`
(`uq_aststi_stocktake_asset` — one row per asset per run, a re-scan is an
UPDATE not a duplicate) tracks each asset through:

- `pending` — expected (pre-populated from the run's location/category
  scope at open time), not yet scanned.
- `present` — scanned, at its recorded location.
- `moved` — scanned, but at a DIFFERENT location (`foundLocationID`
  records where).
- `unexpected` — scanned during this run but wasn't in the original
  expected set.
- `missing` — closed out with the run (never scanned) — the ONLY status
  a closed run can still show as unresolved.

**GS1 Digital Link resolver + GEPIR config gating (#415, the FINAL Asset
Tracker Phase 3 pass).** `Router::handleSpecialRoutes()` gained a THIRD
prefix-matched block (after `e/{slug}` and `a/{token}`) recognising the
three bare GS1 Digital Link path shapes Asset Tracker identifiers can
carry — `01/{gtin}` (GTIN, optionally `/21/{serial}`), `8003/{grai}`
(GRAI), `8004/{giai}` (GIAI) — each anchored-regex-matched and NOT a
`tblRoutes` row, requiring `_apps/assets/dl.php` directly exactly like
the `a/{token}` block requires `tag.php`. `dl.php` gates on
`assets.digital_link_enabled` (a GLOBAL/host-site-merged setting read via
`App::settings()`, since the resolver is deliberately cross-site — there
is no session-selected site to scope a per-site override to), then calls
`AssetRegister::resolveDigitalLink()`, which is ALSO deliberately global
(no `Site::id()` filter — GS1 keys are globally unique) but excludes a
confidential asset in the SQL `WHERE` clause itself (never a post-filter)
so there is no code path that could ever hand back a confidential
asset's token; an ambiguous match (>1 asset sharing a key) resolves to
null identically to "not found". A hit is a plain 302 redirect to the
asset's EXISTING `/a/{token}` public page — `dl.php` makes no further
access decision of its own, `tag.php` owns everything past that point.

GEPIR (Global Electronic Party Information Registry) verify is a
SEPARATE, manager-only, session-gated action (`identifier-verify.php` →
`AssetRegister::verifyIdentifier()`) — nothing to do with the public
resolver above beyond sharing the word "GS1". It ALWAYS runs the local
GS1 mod-10 check-digit first (`validateIdentifier()`, unchanged from
Phase 1) and ONLY attempts an outbound GEPIR HTTP lookup when BOTH
`assets.gepir_verify_enabled === 'true'` AND `assets.gepir_endpoint` is a
non-empty `https://` URL — both off/empty by default (migration 161), so
a fresh install never makes an outbound call until an admin explicitly
configures a real endpoint. The outbound call itself
(`AssetRegister::gepirLookup()`, private) is defensive-by-default: 5s
timeout, TLS verification always on, no redirects followed, response
size capped both via a `Range` request header and a hard `substr()`
after retrieval. ANY GEPIR failure — feature off, no endpoint, network/
timeout/parse error — falls back to the local check-digit result rather
than failing the verify action; `isVerified`/`verifiedAt`/`verifyNote`
(migration 161 columns on `tblAssetIdentifiers`) are the verify cache,
and every verify audits as entityType `'identifier'`, action `'update'`
(mirrors into the platform's generic audit trail like any other
identifier edit).

---

## Troubleshooting

### "CSRF" error on form submission

The CSRF token has expired or was already used (tokens rotate after use).
Reload the form page to get a fresh token.

### Changes not appearing on dev site

Check GitHub Actions for deploy failures. Common causes:

- PHP lint error (syntax issue blocks deploy)
- FTP credentials expired (check DH_HOST/DH_USER/DH_PASS secrets)

### 403 on dev site after login

Your user account lacks dev access. Either:

- Set `isAdmin=1` on your user record in `tblUsers`, or
- Add your role to `portal.devAccessRoles` in Settings

### Debug panel not showing

Append `?debug=true` to the URL. Only visible to admin users in **non-prod**
environments. Debug mode is unconditionally refused when `PORTAL_ENV=prod`
(any attempt is logged as a `DebugBlocked` activity entry). See issue #54.

### A file disappeared from the server after deploy

Almost certainly because **it isn't in the `web/` tree in the repo**. The
deploy workflow mirrors with `--delete` on the shared dirs, so any file
present on the server but absent from `web/` will be removed on the next
push.

Affected (mirrored) trees on the server:

```text
<base>/_core/
<base>/_vendor/
<base>/_sql/
<base>/_includes/
<base>/_functions/
<base>/_libraries/
```

What survives:

- `<base>/_auth_keys/` — server-only (credentials, encryption key)
- `<base>/_uploads/` — user uploads
- `<base>/_backups/` — server-managed snapshots

If you need a quick patch on the server during an incident, **commit + push**
instead of SCP'ing the file — manual edits to mirrored dirs vanish on the
next deploy. If a library or vendored asset must live on the server but not in
the repo, it has to be excluded from the mirror in `.github/workflows/deploy.yml`.
**This used to name a constant `WEB_ROOT_EXCLUDES`, which the #499 rewrite
removed**, so read the workflow for how exclusions are expressed now rather than
searching for that name.

Use the workflow_dispatch **dry-run** input on `deploy.yml` to preview what
a deploy would change (and crucially, what it would delete) before pushing.
See issue #107 for the full rationale and mitigation list.

## The installation package (#499)

How to build and check one, and the table of file kinds a package may hold, are
in `tools/package/README.md`. Four things will stop a pull request with no
other warning once the tree check runs on pull requests:

1. **A new kind of file under `web/`, a PHP file inside the web folder, or an
   archive needs a line in `tools/package/check-package.py`** (its allow list).
   Otherwise `python3 tools/package/check-package.py --tree web` and every
   build refuse it.
2. **Adding or changing ANY `.htaccess` under `web/` needs one line in
   `ALLOWED_HTACCESS` in that file**: the path, the file's SHA-256, and why it
   ships. The refusal prints the exact line to paste. This includes a change to
   `web/public_html/.htaccess`.
3. **A drawing (`.svg`) may not link to anything outside itself**, and a file
   name's ending must be in small letters.
4. The staff web folder's name inside the package is one line in
   `tools/package/build.sh` (`STAFF_WEB_FOLDER`, `admin_html`: owner,
   12 October 2026).

**The PDF library (dompdf)** is the publisher's release file, pinned by SHA-256
in `tools/download-dompdf.sh`. It is not uploaded by hand. To upgrade it,
change the version and the checksum together and write the file list again
(`bash tools/download-dompdf.sh --write-list`).

**What the guards cannot catch**, said plainly in the checker's own header: a
secret in a shape nobody has described (a password somebody chose, the database
login written any way but the installer's, people's details), anything packed
or encoded, and a bad rule inside the web folder's own `.htaccess`. Git's
ignore rules remain the first wall; these guards are the second.

### Upgrading from a package: when the portal closes, and who records the version

- **When the portal closes for an upgrade is decided in ONE place, `Portal\Core\Maintenance::isActive()`:** the
  explicit switch, or the recorded version behind the files' AND a numbered file under `_sql` the database has
  not recorded. The read-only question is `Migrator::waiting()` (never `new Migrator()` on a visitor's request:
  its constructor creates a missing table). **The portal never records the version by itself.**
  `Maintenance::recordVersion()` is the ONE statement that records it after installation, and ONLY the Upgrade
  page's button calls it; never call it on the way through the gate. (For a few hours on 13 October 2026 the
  gate recorded the version when nothing was waiting. A request that arrived while a newer version was half
  uploaded then recorded it, and the upgrade file that landed a moment later never closed the portal.) A page
  that only needs to ASK uses `Maintenance::versionIsBehind()`, `whyUpgradeUnknown()` and `upgradeNotice()`,
  which change nothing. Test: `tools/upgrade-pages-selftest.php` (needs a database user who may switch the
  server's statement log on).
- **Which row holds the recorded version: the installation's own, and no other.** Start-up
  (`web/_core/bootstrap.php`) passes over a row of `portal.installed_version` that belongs to ONE organisation,
  so nothing in the portal sees it, at no cost to an ordinary request. Admin, Settings refuses to add, change or
  delete the setting (`Maintenance::lockedInGeneralEditor()`, asked in three places like
  `Site::lockedInGeneralEditor()`). It cannot stop somebody changing the row straight in the database. **Found,
  not changed:** the installer's own reader (`web/_install/db_state.php`) still prefers a row of organisation 1.
- **When the portal cannot tell, `Migrator::waiting()` says which of four causes it was** (`cause`: `folder`,
  `folder-empty`, `table-missing`, `table-unreadable`), and the Upgrade page words its advice by that. A button is
  offered only where pressing it can help: never for an unreadable folder or table. Add a cause there, not a
  second way of telling.
- **After `runAll()`, call `Maintenance::forgetVersionState()`** before asking the gate anything again: the
  answer is kept for the rest of the request, and the one from before the run is no longer true. And end a run
  with `Maintenance::setActive(false, '')` (or `(true, '')` after a failure): the second argument takes the words
  "Applying database migrations" away, which `setActive(false)` alone leaves stored.
- **Admin, Migrations is for a global administrator only** (`App::isUmbrellaAdmin()`), like the Upgrade page.
- **A form token works once** (`Auth::verifyCsrf()` makes a new one after each good use). A test that sends two
  forms with one token proves nothing about the second: it is turned away for the token. Fetch one for each, and
  recognise a refusal by its words (learned in `tools/upgrade-pages-selftest.php`, section M).
- **What that costs.** While the version is behind and nobody has pressed the button, every request asks the
  question once: one folder listing and one plain statement, about half a millisecond measured (0.5 to 0.65 ms
  with 214 upgrade files, the database on the same machine). With the versions equal nothing is asked at all.
  Keep it that cheap: do not turn the statement into a prepared one (three trips to the database where it is
  one), and do not ask it from anywhere that runs when the versions are equal.
- **The words an administrator is shown have one home, `Maintenance::upgradeNotice()`.** The health page's
  "Migrations" line, the Upgrade page and the Admin dashboard's notice (a global administrator only) use it.
- **Never write a `.channel` file into a package.** `build.sh` no longer does, and `check-package.py` (G9)
  refuses a package that holds one. The installer's finish writes it (`installWriteChannelFile()` in
  `web/_install/install_common.php`) from `VERSION.txt`'s `Channel:` line, only when there is none.
- **Building needs the whole repository, not only `tools/package/`.** The checker's domain guard (G6) reads its
  names from `tools/audit-checks/check_no_hardcoded_domains.py`. That is the ONE list: add a domain of ours there
  and nowhere else. Keep the name `OWN_DOMAINS` and keep it a tuple; the guard fails if it is missing or empty.
  G6 finds a name only where it is written out whole.
- **Never commit `web/.channel`.** It is on the ignore list. If one is committed, `check-package.py --tree web`
  and every package build refuse the commit. Use `PORTAL_ENV` for local work.
- **`gitleaks`.** A build needs it; `--allow-no-gitleaks` is the one switch, its zip is named NOT-FOR-RELEASE and
  it is refused with `--tag` (held by self-test cases `B17` to `B19`, which make a path of their own with no
  `gitleaks` on it). `gitleaks-settings.toml` lets ONE thing through, a find whose secret is exactly
  `YOUR_TOKEN`; do not add to it, reword the look-alike instead. Proved with gitleaks 8.30.1 (the macOS build).
  The proposed workflow installs 8.30.1 too, and cannot run until somebody fills in that download's checksum.
- **An upgrade by unpacking never removes a file.** A file one version had and the next does not stays on the
  customer's hosting. A list of files to delete on upgrade (`REMOVED-FILES.txt`) is still not built (#606). Until
  it is, think before removing or renaming anything under `web/public_html/` (a stale file there can still be
  fetched) or `web/_sql/` (the Upgrade page reads every numbered file it finds).
- **How the install-from-the-zip proofs were made**, so they can be made again: the scratch scripts are kept in
  `.claude-work/resume/rollout-F6b--fix-evidence/scripts-i-used/` (not in the repository).

---

## Who may see and do what: the rules added on 12 October 2026 (#534, #548)

- **`Auth::ensureSession()` looks the signed-in account up on every request** (`SELECT isActive FROM
  tblUsers WHERE userID = ? LIMIT 1`). It must keep naming only `userID` and `isActive`: it runs on the
  upgrade page, on databases that are part way through upgrading. A failed look-up is never read as "not
  active".
- **`LiveChat::eventOpenToViewer()` is the one rule** for the chat list, prompts, send, the viewer ping and
  the chat box on `/live`. Its fourth argument has no default on purpose, and a test now holds that: the
  four data endpoints pass `true` ("count the sign-in only for a request made by the portal's own pages"),
  and `/live` passes `false` because it is a page a person opens. **A new chat endpoint must pass `true`.**
- **How "made by the portal's own pages" is read** (`LiveChat::requestCameFromOwnPages()`): from
  `Sec-Fetch-Site: same-origin` when the browser sent that header; otherwise from an `Origin` equal to the
  address the request was sent to; failing that, from a `Referer` at that address; otherwise no. **Browsers
  send `Sec-Fetch-Site` only to an https address** (or to the machine itself), so on a portal reached over
  plain http `Origin` and `Referer` decide for every browser, and a page that sends no `Referer` leaves an
  internal event's chat empty there. Nothing is compared with a name written in the code or a setting.
  Behind a proxy that ends https without sending `X-Forwarded-Proto`, an older browser is refused, and in
  one narrow case accepted; both are written beside the function, with why the second adds no exposure.
- `Venues::canView()` is the one "may see venue bookings" test. A new page that shows bookings, venue
  details or booked days must ask it. `calendar/index.php` asks it inside its `try`, so a database fault
  there leaves the calendar standing with no booked days.
- **The chat box script sends the sign-in cookie to its own address only** (`credentials: 'same-origin'`).
  It sends every request to the address of the page it is on, so it cannot work on another website.
- **Every address a page writes goes through `Site::url()`** (`Router::url()` is the same thing under its older
  name). `check_site_aware_addresses.py` reads the whole of `web/_apps`, `web/_core`, the web folder and
  `web/_install/upgrade.php`. The list of places not swept yet, `site_aware_addresses_pending.txt`, has been EMPTY
  since 13 October 2026 (the second part of the sweep did the last 44 places, about 2,700 addresses in 503 files);
  nothing is added to it, and `--strict` fails if a line appears. Its `STRICT`
  list (it was called `LISTED`) names the places held to the widest rule. The check reads whether an address is
  built by the helper, not whether it is the right address; `tools/site-addresses-selftest.php` runs the portal
  and reads the pages. **The empty pending list is needed before `Site::FIRST_PART_MODE_READY` is switched on,
  and is not enough on its own:** the check reads text (its header lists what it cannot see), and the service
  worker, the installable app, the chat box on `/live`, the return address of a Microsoft or Google sign-in, and
  a link in a push or an e-mail made by a scheduled job (a job has no organisation's part) are not
  organisation-aware.
- **Three things the sweep taught** (13 October 2026). (1) Never join two built addresses:
  `Site::url('small-groups') . Site::url('group')` prints the organisation's part twice. The sweep script did this
  in two files and its own inverse test could not see it; comparing every key inside `Site::url('…')` with the
  registered addresses did. (2) A page that ACCEPTS a come-back address by a pattern or a list must accept it with
  the organisation's part in front, and must go on refusing an address that leaves the portal. (3) A class used by
  its short name needs its `use` line in that very file, and a missing one fails only when the line runs: of the
  2,441 rewritten lines, 547 ran in no test (most are redirects inside save handlers).

---

## Backups and recovery: what was proved on 12 October 2026 (#488)

- **The runbook is `docs/disaster-recovery-runbook.md`.** Its table "What was
  tested" lists each proof.
- **A snapshot is rows only.** `DbBackup::snapshot()` writes one file per table
  and a manifest last. It holds no table structure, no uploaded file and no
  key. Nothing makes one on a timer (#581): only the upgrade page and the "Run
  snapshot now" button do.
- **A full restore is all or nothing, and that is now proved**: planted bad
  snapshots, changes of table shape, and a restore killed half way each left
  the database identical before and after.
- **The three faults this proof found in `DbBackup`** (#577, #578, #579) were fixed the same day; see "The portal's own
  snapshots" below. The runbook says what is still true.
- **When changing a document under `docs/`**, run
  `python3 tools/audit-checks/check_docs_table_refs.py --strict`. A made-up
  example name goes in its `ALLOWED_EXAMPLE_NAMES`, with the reason.
- **When changing a command in the runbook, run it first.** The mark "Tested"
  is a statement about that exact text.

### The portal's own snapshots (`Portal\Core\DbBackup`)

- **Form.** A snapshot is a folder `_backups/upgrade-YYYYMMDD-HHMMSS/` holding
  `tblName.jsonl` per table and `_manifest.json`, written last by rename. A
  folder without a readable manifest is unfinished and is never a backup. The
  two shapes are described property by property in
  `web/_core/data/backup-manifest.schema.json` and
  `backup-table-file.schema.json`. Snapshots made before October 2026 hold one
  `tblName.json` per table and no `format` in the manifest; they still
  restore, at their old cost in memory.
- **Adding a column type.** `DbBackup::TEXT_TYPES` and `BYTE_TYPES` list the
  types the backup carries. A type on neither is refused at snapshot time, so
  a migration that adds one breaks backups for every customer. The check
  `check_backup_column_types.py` fails on the pull request. To teach the
  backup a type: add it to a list AND to the scratch table in proof 14 of
  `tools/backup-restore-selftest.php`, which fails if a type is listed without
  being proved. BIT and FLOAT are left off on purpose: neither survives.
- **A table that points at itself** must do so through a column that may be
  empty, and must have a primary key. The restore writes such rows with the
  pointer empty and fills it in afterwards. The same check fails otherwise.
- **Never switch the database's link checking off in a restore.** It was
  tried in #472 and reverted: a restore could then succeed with rows pointing
  at nothing.
- **The check-only run is a full rehearsal** (owner, 12 October 2026). The
  choice is one constant, `DbBackup::CHECK_ONLY_IS_REHEARSAL`.
- **A pointer at a row that is not in the snapshot** is emptied only when the
  link's own rule is ON DELETE SET NULL, and is counted in
  `pointers_emptied`. Any other rule refuses the restore.
- **Running the tests.** Both need a database built from `full_schema.sql`
  whose name starts with `selftest_`, and a user with rights on that one
  database:
  `SELFTEST_DB_NAME=… SELFTEST_DB_PORT=… SELFTEST_DB_USER=… SELFTEST_DB_PASS=… php tools/backup-restore-selftest.php`
  and the same with `SELFTEST_WEB_PORT=…` for `tools/backup-page-selftest.php`.
  Each prints `REACHED THE END` as its last line. If the snapshot's shape
  changes, run the first with `--write-samples` and bring the schema files
  into line (proof 1 fails until you do).
- **A restore runs in a strict session of its own.** `pinStrictness()` sets
  the connection's `sql_mode` to `DbBackup::RESTORE_SQL_MODE` and switches
  `sql_notes` on, in one statement, for the length of a restore, and
  `putStrictnessBack()` puts both back in the same `finally` as the time
  zone. What it puts back is the portal's own list
  (`DbServer::STRICT_MODE_MYSQL` or `_MARIADB`), not the host's. The two lists
  differ on purpose: the portal's has `NO_ZERO_DATE` and `NO_ZERO_IN_DATE`, the
  restore's must not. Do not add `NO_ZERO_DATE` or `NO_ZERO_IN_DATE` to the mode: a database
  that was ever run loosely holds dates of zero, and they must go back as
  they were. A statement that succeeds with a warning OR a note is treated as
  a failure (`tryStatement()`), because that is how the database reports a
  value it rounded or trimmed; that is why the notes must be on.
- **What a restore cannot notice.** Some changes the database makes with no
  error, no warning and no note when a column's kind was altered after the
  snapshot. Seven are known (the list is in the header of `DbBackup.php`):
  fewer digits of a second, a decimal into a whole-number column, a wider
  fixed-width BINARY column, end spaces into a CHAR column, DOUBLE into FLOAT
  and BIGINT into DOUBLE, a two-digit number into a YEAR column, and (with
  `explicit_defaults_for_timestamp` off) "no value" into a TIMESTAMP column
  that may no longer be empty. Recording each column's type in the manifest,
  and refusing at restore when it has changed, would close all of them; it
  is not done.
- **A value that is not on its column's list of choices.** A loosely run
  database stores it as an empty text (the database numbers it 0), and a
  restore refuses it. `snapshot()` looks for these as it reads (see
  `dumpTable()`): it sends one extra statement only for a table where an ENUM
  column handed over an empty text. What it finds goes in the result
  (`warnings`, `unlisted_values`) and in the manifest (`unlisted_values`,
  always present, empty when none). `DbBackup::unlistedIn()` reads that list
  out of a manifest safely and `unlistedWarning()` is the ONE sentence every
  page shows; do not write a second. The upgrade page stops on it; its second
  button posts `go_on_without_way_back=1`. It does not prune older snapshots
  when the new one carries the warning.
- **A name from a browser** goes through `DbBackup::isSnapshotName()` and
  nothing else, before anything is closed, changed or deleted. Deleting is
  `DbBackup::deleteSnapshot()`, which never follows a link. Reading a
  manifest for a page is `DbBackup::manifestOf()`, which refuses a link that
  leads out of the backups folder; do not read `_manifest.json` directly.
- **`connection_closed` in a restore's result** means the class closed the
  database connection: to be sure unfinished work was undone, or because the
  session's settings could not be put back afterwards. It can be true on a
  SUCCESSFUL result. The caller must not use the connection again in that
  request; `backup.php` shows a bare page and stops.
- **A row larger than `max_allowed_packet`** is refused in words before it is
  sent (`tooLargeProblem()`), so the connection stays open and the ordinary
  undo runs.
- **Tables outside the snapshot.** `outsideTables()` finds every table that
  is not in the snapshot and is linked, directly or onward, to one that is;
  their rows and their linking columns (links to themselves included) are
  counted inside the transaction before and after, and any difference is
  reported in `other_tables_changed` and a warning.
- **The standing check and `ALTER TABLE`.** `check_backup_column_types.py`
  reads an `ALTER TABLE` that adds a column, a link, a primary key or an
  index, with or without the database's name in front of the table's. Any
  other form, and any statement whose table name it cannot read, is a
  finding. Put a change into the table's `CREATE TABLE` in the install script.
- **Zip package.** Two new files live under `web/_core/data/`. Whoever owns
  the package's file list should confirm it takes that folder whole.

---

## End-to-end migration test (#248)

Before each release, run every migration through real MySQL 8.0.36 and
8.4.11 containers to catch what the static `check_sql_columns.py` and `check_migration_idempotency.py` audits miss:

- Statement order — a later migration assuming a table a previous one
  forgot to create.
- FK constraints that pass static parsing but blow up at runtime on
  real data shapes.
- Genuine non-idempotency that the static audit can't model (e.g. a
  trigger that errors second-time-round).

### Procedure

Requires `docker` on your own machine.

```bash
tools/e2e-migrations/run.sh                # all six phases and the data checks
E2E_MYSQL_VERSION=8.4.11 tools/e2e-migrations/run.sh   # the same on MySQL 8.4.11
tools/e2e-migrations/run.sh --skip-stale   # phase 4 without its catching-up half
tools/e2e-migrations/run.sh --keep         # leave the container up afterwards
```

The script runs six phases and three data checks:
1. **Fresh install**: load `full_schema.sql` into an empty database.
2. **Installer replay**: run every migration over it. No table, column, index, link,
   address or setting value may change, apart from five differences named in `run.sh`.
3. **Run it all again**: the migrations a second time (no count may change), then
   `full_schema.sql` a second time over the same database (nothing in the inventory
   may change).
4. **Catching up**: reload, forget the newest half of the migrations, let them run again.
- **Data checks**: plant three attendance sessions and run migration 210, then three
  more and run the install script; each time only the pointer at a deleted event may
  be emptied and no other column of any session may change. Then take the events
  table away: both files must stop and change nothing.
5. **Upgrade from the last release** (`origin/main`, or `E2E_BASE_REF`).
6. **Upgrade from the oldest install script** (6 March 2026: a frozen file holding that
   day's base tables, upgraded by today's migrations).

Phases 5 and 6 must end up equal to a fresh install, compared by name and not by
count. Each phase has its own list of named exceptions in `run.sh`, and fails when a
line in its list is no longer seen. The test cannot see MariaDB, a database whose
history matches neither starting point, a setting's stored default, or the contents
of tables other than addresses, settings and their values, recorded migrations and
the planted sessions.

**After this work reaches `main`.** The first run of the migration test after roll-out package E reaches `main` will fail
in phase 5, on purpose: `auth.password.minLength | every organisation | 12 → 8` in
`KNOWN_P5_VALUE_DIFFERENCES` (`tools/e2e-migrations/run.sh`) stops being true, because
a database built from `main` then holds 12. The failure message says to delete that
line. Delete it then, in its own commit, and not before.

See `tools/e2e-migrations/README.md` for details.

### Static idempotency audit

`tools/audit-checks/check_migration_idempotency.py` is the fast
first-pass. Flags `CREATE TABLE` without `IF NOT EXISTS`, `ADD COLUMN`
without `IF NOT EXISTS`, and `INSERT` without `ON DUPLICATE KEY UPDATE`
or `INSERT IGNORE`. Quote-aware splitter — `;` inside string literals
and comments doesn't fragment the parse.

Some old migrations (014-018, 037, 043) flag because they used pre-multi-site
patterns without the `IF NOT EXISTS` clause. These have already run in
production and the Migrator wrapper skips already-applied files, so
they're safe. **New migrations must pass cleanly** — drop a non-idempotent
DDL in a fresh migration and the audit will catch it.

## The browsable API documentation page (/api-docs)

### What the page is

`/api-docs` shows every endpoint of the portal's REST API in a form you can
read and try out in a browser. It is built by a third-party library called
Swagger UI, which reads the machine-readable description the portal serves at
`/openapi.json`. That description comes from `web/_core/api-spec.json`, passed
through `web/public_html/openapi.php` so the product name matches the active
brand.

### Self-hosted Swagger UI files

The three Swagger UI files are committed to the repository:

```
web/public_html/assets/vendor/swagger-ui/swagger-ui-5.17.14.css
web/public_html/assets/vendor/swagger-ui/swagger-ui-bundle-5.17.14.js
web/public_html/assets/vendor/swagger-ui/swagger-ui-standalone-preset-5.17.14.js
```

They deploy with the rest of `public_html/`, so nothing has to be installed or
built on the server. That matters because the portal runs on shared hosting
with no command line and no package manager.

The page still asks the jsdelivr CDN for them *first*, because a visitor who
has already loaded Swagger UI elsewhere gets it instantly from their browser
cache. The committed copies are the fallback, wired through the existing
`Asset::css()` / `Asset::js()` machinery — exactly how Bootstrap and Font
Awesome have always worked. If the CDN cannot be reached, the browser loads the
local copy and the page works normally.

**Setting `api.docs.local_assets_only`** (migration 185, seeded `false`) skips
the CDN entirely. Turn it on for a site whose network *blocks* public CDNs
outright: without it the browser has to attempt the CDN and wait for the
attempt to fail on every visit, which makes the page look broken for a few
seconds. When it is on, the page also drops jsdelivr from its
Content-Security-Policy header, so the policy exactly matches what the page
actually loads.

### Refreshing the self-hosted Swagger UI files

Do this whenever `Asset::SWAGGER_VERSION` is bumped. The committed files must
stay byte-identical to what the CDN serves, because both are validated against
the same SRI hash — if they drift, the CDN copy loads and the local copy
silently fails the integrity check.

```bash
V=5.17.14                       # set to the new version
D=web/public_html/assets/vendor/swagger-ui
mkdir -p "$D"

for f in swagger-ui.css swagger-ui-bundle.js swagger-ui-standalone-preset.js; do
  curl -sSL -o "/tmp/$f" "https://cdn.jsdelivr.net/npm/swagger-ui-dist@$V/$f"
  echo "$f  sha384-$(openssl dgst -sha384 -binary "/tmp/$f" | openssl base64 -A)"
done
```

Copy each printed hash into the matching `SWAGGER_*_INTEGRITY` constant in
`web/_core/Asset.php`, then move the files into place with the version in the
filename (the same convention the React files under `assets/vendor/react/`
use, so an old cached copy can never be served for a new version):

```bash
mv /tmp/swagger-ui.css                    "$D/swagger-ui-$V.css"
mv /tmp/swagger-ui-bundle.js              "$D/swagger-ui-bundle-$V.js"
mv /tmp/swagger-ui-standalone-preset.js   "$D/swagger-ui-standalone-preset-$V.js"
git rm web/public_html/assets/vendor/swagger-ui/*-<OLD VERSION>.*
```

Finally update `SWAGGER_VERSION` in `Asset.php`. Both the CDN URLs and the
local paths are built from that one constant, so nothing else needs editing.

To confirm a committed file matches its recorded hash at any time:

```bash
openssl dgst -sha384 -binary web/public_html/assets/vendor/swagger-ui/swagger-ui-5.17.14.css \
  | openssl base64 -A
# compare with SWAGGER_CSS_INTEGRITY in web/_core/Asset.php
```

The Swagger UI stylesheet embeds all of its images directly, so there are no
extra image or font files to fetch. Nothing else is needed.

### Trying an endpoint out, and the anti-forgery token

"Try it out" on a POST, PUT, PATCH or DELETE endpoint only works if the request
carries a valid anti-forgery token — a short random string that proves the
request came from a real page of this portal, not from another website.

Two things make that work:

1. **The page publishes a token.** `/api-docs` draws its own `<head>` instead of
   using the shared page template, so it emits its own
   `<meta name="csrf-token">`. Before this was added the token was always
   empty, and every write from "Try it out" was refused with "CSRF check
   failed".
2. **The reply hands back a replacement.** `Auth::verifyCsrf()` issues a
   brand-new token every time it accepts one, so a token can never be reused.
   `ApiAuth::requireWrite()` captures the replacement and `ApiResponse` puts it
   in the response's `meta.csrfToken` field; the page reads it from there and
   updates its copy. Without this, only the *first* write of a session would
   succeed.

`meta.csrfToken` appears **only** on session-authenticated writes. A request
authenticated with a bearer API key never gets it, because such a request
carries no session cookie and anti-forgery tokens do not apply to it. The field
is documented in `api-spec.json` on both `V1SuccessEnvelope` and
`V1ErrorEnvelope`.

The page also sets `validatorUrl: null`. By default Swagger UI asks
`validator.swagger.io` to grade the specification and shows a badge with the
result — which would tell an outside service the address of the install, and is
blocked by the page's own security policy anyway.

---

## Adding a new CDN dependency (#161)

Every `<script>` and `<link>` tag pointing at a third-party CDN MUST carry
an `integrity="sha384-…"` attribute and `crossorigin="anonymous"`. Without
SRI, a compromise of the CDN serves arbitrary JS/CSS to every visitor
simultaneously — and SRI is the only client-side mitigation.

The `tools/audit-checks/check_cdn_sri.py` script scans every PHP / HTML
file under `web/` and flags any CDN tag without an `integrity=` attribute.
It runs in CI and locally — drop a tag without SRI and the check fails.

### Procedure

1. **Pin the version.** SRI requires exact byte matching, so `@latest`
   and unpinned major versions (`bootstrap@5`) will break the check on
   every release. Use an exact patch version (`bootstrap@5.3.3`).

2. **Generate the hash:**
   ```bash
   curl -sL https://cdn.jsdelivr.net/npm/<package>@<version>/<file> \
     | openssl dgst -sha384 -binary \
     | openssl base64 -A
   ```
   Prefix the output with `sha384-`.

3. **Add to `web/_core/Asset.php`:**
   - Add a `*_VERSION` constant (one source of truth for bumps).
   - Add a `CDN_*` URL constant building from the version.
   - Add a `*_INTEGRITY` hash constant from the curl/openssl pipeline.
   - Add a helper method (`Asset::sortableJs()` style) that calls
     `self::css()` or `self::js()` with the constants. Helpers attach
     SRI automatically and route an `onerror` to the local fallback.

4. **Use the helper, never raw `<script>`:**
   ```php
   <?php echo \Portal\Core\Asset::sortableJs(); ?>
   ```
   Inline `<script src="https://cdn…">` tags will be caught by the audit
   AND don't get the local-fallback handler.

5. **Run the audit:**
   ```bash
   python3 tools/audit-checks/check_cdn_sri.py
   ```
   Should report `No CDN tags missing integrity= attribute.` ✅

### Checking that the fingerprints are right (#568)

`check_cdn_sri.py` only proves a fingerprint is PRESENT. Whether it is RIGHT is
checked by `php tools/cdn-sri-selftest.php`, which needs the internet. It
downloads every pinned library and compares: the nine in `Asset.php`, the
installer's own tag, the two Leaflet tags in the map partial, and React and
React DOM in the noticeboard script. A fingerprint written anywhere in a PHP,
HTML or JavaScript file under `web/` that it cannot match to a file to download
is a failure too, so a new way of writing a tag cannot slip past it.

After any version bump, work each value out again and then run the self-test:

```bash
curl -sL <address> | openssl dgst -sha384 -binary | openssl base64 -A   # then put "sha384-" in front
```

---

## Asking PHP for more time (#563)

Never call `set_time_limit()` directly. Some shared hosts switch that function
off, and on PHP 8 a switched-off function does not exist, so the call ends the
request with "Call to undefined function". Use
`Portal\Core\TimeLimit::atLeast($seconds)` instead. It never lowers a limit,
leaves "no limit" alone, and does nothing when the limit is already enough, so
calling it inside a loop does not keep restarting PHP's clock.

`tools/audit-checks/check_time_limit_calls.py` fails any other call. The one
exception is `FeedAdmin::allowTimeForLockedWork()`, which asks
`function_exists()` itself. `tools/time-limit-selftest.php` proves the helper's
behaviour, including on a host with the function switched off.

**The calendar importer's self-test loads core classes from a written list**,
not through the autoloader. When a core class gains a new dependency, add its
file to BOTH lists in `tools/feed-importer-selftest.php` (the top of the file,
and the scratch runner it writes). A missing one shows up as "Class ... not
found" inside a refresh, not as a loud failure. `TimeLimit.php` was added there
for #563.

---

## Noticeboard React bundle (#360 / PR #358)

The board's frontend at `web/public_html/assets/noticeboard/noticeboard.{css,noeval.js}` is **generated** — the `dc-runtime` header on line 1 of the JS files marks it. Do not hand-edit. Rebuild from the Claude Design page (https://claude.ai/design/p/6fab711c-d550-4200-8d96-42d6751a5fba) when the source component changes.

Two variants exist in the design output:
- `noticeboard.js` — eval variant (runtime-Babel from unpkg). **Never wire this in.** The portal CSP disallows `unsafe-eval` and does not allowlist unpkg, so it cannot run.
- `noticeboard.noeval.js` — precompiled variant, wired in `_apps/noticeboard/index.php`.

### Deliberate hand-edit exception (#363 — real upload pipeline)

`noticeboard.noeval.js`'s `handleFile()` already special-cased a host upload
hook (falling back to a `data:` URI `FileReader` read when absent), but named
it `host.uploadFile(file)`. #363 wires that hook up to a real backend via
`window.NoticeboardHost.upload(file)` (the bridge name the rest of the portal
uses — see `_apps/noticeboard/index.php`), so the **only** hand-edit made to
the generated bundle is renaming the two `uploadFile` references at
`handleFile()` to `upload`. Everything else in the function (the `Promise`
wrapping, `uploading` state, error `alert()`, and the `data:` URI fallback for
a standalone/local deployment with no host) is untouched.
**If you regenerate the bundle from the Claude Design source**, either carry
this rename forward again, or (better) rename the hook to `upload` in the
source component itself so a regeneration doesn't silently revert it.

### React hosting

React 18.3.1 UMD is **self-hosted** at `web/public_html/assets/vendor/react/`:
- `react-18.3.1.production.min.js` — sha384 `DGyLxAyjq0f9SPpVevD6IgztCFlnMF6oW/XQGmfe+IsZ8TqEiDrcHkMLKI6fiB/Z`
- `react-dom-18.3.1.production.min.js` — sha384 `gTGxhz21lVGYNMcdJOyq01Edg0jhn/c22nsx0kyqP0TxaV5WVdsSH1fSDUf5YJj1`

The bundle's `loadReactUmd()` short-circuits when `window.React` / `window.ReactDOM` pre-exist — so the CSP-blocked unpkg fetch path never fires when React is pre-loaded via the self-hosted `<script nonce defer>` tags. Verify SRI on any bundle regeneration; the hashes must match the strings embedded at `noticeboard.noeval.js:1495,1497`.

### Page-scoped CSP extension contract

`_core/templates/header.php` accepts three optional variables set by a page controller BEFORE the `require ... 'header.php';` line:

- `$cspImgExtra`   — space-separated source list appended to `img-src`
- `$cspMediaExtra` — space-separated source list appended to `media-src` (new directive; behaviour-neutral vs the previous `default-src 'self'` fallback when unset)
- `$cspFrameExtra` — space-separated source list appended to `frame-src`

Extensions are per-page — the underlying `default-src / img-src / frame-src` remain unchanged for every other page. `_apps/noticeboard/index.php` sets `https:` on img/media and `https://www.canva.com` on frame.

### Typography (#361 — self-hosted)

The generated bundle template still injects a `fonts.googleapis.com` stylesheet (Bricolage Grotesque / Instrument Serif / IBM Plex Mono / IBM Plex Sans) and the generated `noticeboard.css` still carries the matching `@import url(https://fonts.googleapis.com/...)`. The portal CSP does not allowlist Google Fonts (self-hosting stance, PR #356), so both of those remain **dead under CSP** — every request they'd make is blocked. They're left in place rather than hand-edited, per the "generated — do not edit" rule above; harmless once the fonts are self-hosted (below), since a broken/blocked `@import` just does nothing.

As of #361, all four families are **self-hosted** instead of falling back to `system-ui`:

- `web/public_html/assets/noticeboard/fonts-selfhost.css` — hand-maintained (NOT generated), `@font-face` rules for the four families/weights the bundle actually references (verified by grepping `noticeboard.noeval.js` / `noticeboard.css`): Bricolage Grotesque (variable, wght 400–800), Instrument Serif (400 upright + italic), IBM Plex Mono (400/500), IBM Plex Sans (400/500/600). `latin` + `latin-ext` subsets (the latter for Welsh diacritics — see `_lang/cy.php`); cyrillic/greek/vietnamese subsets skipped.
- `web/public_html/assets/noticeboard/fonts/*.woff2` — the actual font files, sourced from `@fontsource`/`@fontsource-variable` v5.3.0 (SIL OFL-1.1), ~360 KB total. `OFL-*.txt` license texts for each family are redistributed alongside them.
- Wired via a `<link rel="stylesheet" href="/assets/noticeboard/fonts-selfhost.css">` in `_apps/noticeboard/index.php`, placed immediately **before** the generated `noticeboard.css` `<link>` so the `@font-face` rules exist before the board renders any text.
- Fallback chain: `#noticeboard-root { font-family: 'IBM Plex Sans', 'Plus Jakarta Sans', system-ui, sans-serif; }` — if a self-hosted face were ever unavailable, the board falls back to the portal's own self-hosted Plus Jakarta Sans (PR #356) before system fonts. Per-poster inline `font-family` values are set by the generated bundle itself and can't route through that fallback without editing generated code — moot in practice since the `@font-face` rules make all four families resolve directly.
- CSP: no changes needed. `style-src`/`font-src` both include `'self'` at `_core/templates/header.php`, and the fonts are same-origin — `_apps/noticeboard/index.php` only widens `img-src`/`media-src`/`frame-src` (unchanged).

---

## REST API v1 (#323 Phase 2)

### Dual-mode contract — bearer OR session, never both

Every `_apps/{app}/api/{action}.php` handler resolves auth through one choke-point,
`Portal\Core\ApiAuth` (`web/_core/ApiAuth.php`):

- **Bearer** — `Authorization: Bearer wbms_…`. Detected purely by the `wbms_` prefix
  (`ApiAuth::isBearer()`); any other bearer scheme falls through to the session path
  untouched, so this never hijacks a future OAuth integration. Verified via
  `ApiKey::findByPlaintext()` (Phase 1), scope-gated via `ApiKey::hasScope()`
  (wildcards `*` / `{res}:*` supported), tenant-pinned to the KEY's own site
  (`Site::forceContext()` — see below), and per-key rate-limited. **No CSRF** — a
  bearer token travels in an explicit header set by calling code, never by a
  browser automatically, so CSRF protection is meaningless for it (the
  OWASP-sanctioned exemption for token auth).
- **Session** — the existing logged-in portal user. Reproduces the historical
  per-handler boilerplate verbatim (same order, same rejection strings/codes):
  `requireAuth` → optional `requireAdmin` → `Auth::ensureSession()` → CSRF via the
  `X-CSRF-TOKEN` header or `csrf_token` body field on writes.

Handlers call `ApiAuth::requireRead('{resource}:read', $sessionNeedsAdmin)` or
`ApiAuth::requireWrite('{resource}:write', $sessionNeedsAdmin)` (the latter returns
the decoded JSON body) instead of hand-rolling the boilerplate. `ApiAuth::source()`
/ `apiKeyId()` / `actorUserId()` feed `Logger::audit()`'s new `$apiKeyId` /
`$source` parameters (auto-resolved when omitted — every pre-existing call site
compiles and behaves unchanged).

### `/api/v1/{resource}[/{id}]` facade

`ApiRouter::dispatchV1()` translates `(HTTP verb, resource, id)` into the identical
legacy `(app, action)` pair and re-runs the **same** pipeline as
`/api/{app}/{action}`: same handler file, same `api.{app}.{action}.enabled` flag.
No new gating vocabulary, no `tblRoutes` rows. See CLAUDE.md's "ApiRouter routing
trap" section for the one-line summary. Per-resource action aliases (where the
handler file isn't named `create`/`update`/`delete`):

| Resource | POST → | PUT/PATCH → | DELETE → |
|---|---|---|---|
| noticeboard | `save` | — (none) | — (none) |
| leadership | `assign` | — (none) | `unassign` (id = assignmentID) |
| prayer-requests | `create` | `moderate` | — (none) |
| tasks | `create` | `complete` | `delete` |
| expenses | `create` | — (**deferred to Phase 3**) | `delete` |

`GET /api/v1/{resource}/{id}` (a "detail" route) exists for **events only** — every
other resource 404s an id-suffixed GET (no `detail.php` handler). Unknown resource
→ 404; non-numeric id → 400; unsupported verb → 405 with an `Allow` header built
from which handler files actually exist on disk.

### Scope vocabulary

`Portal\Core\ApiKey::SCOPES` is the single source of truth — twenty `{resource}:read`
/ `{resource}:write` pairs across the ten v1 resources (events, announcements,
attendance, prayer-requests, documents, expenses, leadership, tasks, noticeboard,
users). The admin mint form (`_apps/admin/integrations/api-keys.php`) renders this
constant as a checkbox grid — never a duplicated hardcoded list — and
`api-keys-save.php` re-validates every submitted token server-side against
`SCOPES` ∪ `{'*'}` ∪ `{'{resource}:*'}` for a KNOWN resource, rejecting anything
else. `ApiKey::hasScope()` honours both wildcard forms at verification time.

### Tenant pinning (`Site::forceContext`)

A bearer request carries no session, so `Site::id()` would otherwise resolve to
the host-detected default site rather than the key's own. `resolveBearer()` in
`ApiAuth` re-points the site context to the key's `siteID` via
`Site::forceContext(int $siteId)` before the handler runs, so every
`Site::id()`-scoped query in every handler becomes tenant-correct automatically.
`forceContext()` fails CLOSED: a key with `siteID <= 0`, a missing/inactive site,
or (when multisite is disabled) any site other than the install's single site, all
500 rather than silently falling back to the ambient default. `ApiRouter`'s
`api.{app}.{action}.enabled` gate is ALSO resolved against the pinned site
(`App::settingForSite`), not the frozen bootstrap `$SETTINGS` snapshot — a site's
own kill-switch can't be bypassed via the Host header on a bearer request.

### Per-key rate limiting

`RateLimiter::tooMany()` / `recordHit()` / `retryAfter()` implement a generic
sliding window against a new `tblApiRateLimits` table (bucket = `apikey:{keyID}`),
separate from the pre-existing login-attempt limiter. Limits default to 300
requests / 5 minutes, overridable per-site via `api.rateLimit.perKey.maxRequests`
/ `windowMinutes`. A 429 sets `Retry-After`. Session callers are never rate-limited
by this mechanism (unchanged — they're protected by login rate limiting instead).

### Rotation grace

`ApiKey::rotate($keyId, $byUserId, ?$graceHours)` mints a replacement key first,
then either revokes the old key immediately (`$graceHours === 0`) or caps its
`expiresAt` at `now + $graceHours` and stamps `rotatedToID` (`$graceHours` null
resolves against the `api.keys.rotationGraceHours` setting, default 24). The old
key stays `isActive = 1` and dies naturally via the existing `expiresAt` check in
`findByPlaintext()` once the cutoff passes — zero changes to the verification
path. The admin UI (`api-keys.php`) offers a grace `<select>` (immediate / 1h /
24h / 72h, default 24h) on the rotate control, and shows an "Expiring (rotated)"
badge on any key row where `rotatedToID IS NOT NULL AND isActive = 1`.

### ⚠️ Known limitations (documented, not bugs)

1. **The per-key rate limiter is check-then-record, not atomic.** `tooMany()` and
   `recordHit()` are two separate statements with no `SELECT ... FOR UPDATE` /
   advisory lock between them — a genuinely concurrent burst against the same key
   can slightly exceed `maxRequests` before the limiter catches up. This is
   approximate limiting by design (house pattern: fail-open on DB error, cheap
   single-row inserts + a covering index over a distributed lock), not a
   correctness bug. Revisit only if abuse patterns actually exploit the window.
2. **The bearer key-row `lastUsedAt`/`lastUsedIP` stamp runs on the PRE-gate
   lookup.** `ApiRouter::resolveEnabledFlag()` calls `ApiAuth::bearerKeyRow()` to
   discover the key's site for the `enabled` check BEFORE the handler (and its own
   `ApiAuth::requireRead/Write()` call) runs — and `ApiKey::findByPlaintext()`
   stamps `lastUsedAt` as a side effect of that same lookup. `bearerKeyRow()` is
   cached per-request, so this costs exactly one extra single-row `UPDATE`, not a
   duplicate DB round-trip — but it means a valid key hammering a DISABLED
   endpoint still drives one un-throttled `lastUsedAt` write per request (the
   per-key rate limiter only runs inside `resolveBearer()`, which a disabled
   endpoint never reaches). Self-contention against the key's own row; no
   cross-tenant impact; negligible load in practice.
3. **Expenses status-transition update (approve/reject/reimburse) is DEFERRED to
   Phase 3.** There is no `PUT /api/v1/expenses/{id}` in this release — v1 ships
   `create` + `delete` (Pending-only) only. The transition needs to share the
   existing multi-approver workflow + `ExpenseMailer` side-effects
   (`_apps/expenses/approve/`) rather than duplicating that logic, which wants its
   own extraction pass.
4. **Several write endpoints record creator/updater as NULL for bearer requests.**
   `ApiAuth::actorUserId()` returns `null` in bearer mode (there is no session
   user) — handlers use `ApiAuth::actorUserId() ?? 0`, so a bearer-created row's
   `createdByID`/`updatedByID` is `0`/`NULL` rather than a real user. Attribution
   for a bearer-made change is via the audit trail instead:
   `tblAuditTrail.apiKeyID` + `.source = 'apikey'` (see the admin Audit Trail
   viewer's new source badge + key-prefix column).

---

## Giving — two-person offering count session (#299 sub-feature 1)

Extension to the existing `giving` app (#266, migration 094). #299 ("Giving
polish") bundles FOUR sub-features — offering counting, pledge campaigns,
bank reconciliation, account-updater. Only sub-feature 1 is built here; the
other three are separate, tracked-but-not-started scope.

### Naming: `tblGiftEntries` vs the real `tblGivingEntry`

#299's issue body sketches the write target as `tblGiftEntries`, but the
giving app actually shipped as `tblGivingEntry` (singular "Entry", amounts in
PENCE via `amountPence` — see `Portal\Core\Giving`, `web/_sql/full_schema.sql`
§"Giving / contributions log"). This migration (150) writes to the REAL
table. New columns introduced here follow `tblGivingEntry`'s own convention
(`siteID INT NOT NULL DEFAULT 1`, `createdAt DATETIME NOT NULL DEFAULT
CURRENT_TIMESTAMP`, `fk_<short>_<col>` constraint names) rather than the
issue body's sketch verbatim.

### Schema

- **`tblCountSessions`** — one row per service date. `counter1ID`/`counter2ID`
  (nullable, assigned at creation or later) each get their own independent
  `cashTotal1/chequeTotal1/envelopeTotal1` and `…2` triplet (`DECIMAL(10,2)`,
  nullable until entered). `cashTotal`/`chequeTotal`/`envelopeTotal` (also
  nullable `DECIMAL(10,2)`) are the **agreed** totals — set only once the two
  independent counts match, or an admin resolves a discrepancy — and are what
  actually gets written to the gift log on close.
  **`categoryID` (NOT NULL, FK `tblGivingCategory`) is an addition beyond the
  issue body's column sketch** — it's required because `tblGivingEntry.categoryID`
  is `NOT NULL`, so every count session needs to know which giving
  category/fund its gift log posts to. Picked at session-creation time.
- **`tblCountEnvelopes`** — added per the issue body's own conditional ("if
  envelope-level named entries are needed to write per-envelope gift entries,
  add a child table"). Models the numbered/named giving-envelope breakdown of
  a session's agreed `envelopeTotal` — `giverID` (nullable, matched member) OR
  `giverName` (free text, mirrors `tblGivingEntry.donorName`'s own
  matched-vs-free-text pattern), `amount DECIMAL(10,2)`, `method
  ENUM('cash','cheque')`. Entered ONCE per session (not duplicated per
  counter) — only the aggregate cash/cheque/envelope totals are independently
  double-keyed; the named breakdown is collaborative, shared session data.

### State machine (`tblCountSessions.status`)

```
open ──(counter 1 OR 2 submits)──▶ counting ──(other counter submits, MATCH)──▶ counting (agreed totals set, ready to close)
                                       │
                                       └──(other counter submits, MISMATCH)──▶ discrepancy
                                                                                   │
                                                              (counter re-enters, now matches) ──▶ counting
                                                              (admin resolves w/ agreed totals) ──▶ counting
counting ──(close: agreed totals set AND envelopes reconcile)──▶ closed  [terminal]
```

`giving.countRequiresTwoCounters` (default `'true'`) — when `'false'`,
whichever counter slot is completed FIRST is auto-agreed immediately (no
discrepancy comparison at all), for sites that only run a single-counter
process.

### Close — writing a *balanced* gift log

The issue body says close writes "`tblGiftEntries` for each named envelope +
a single 'loose cash' entry". This implementation goes one step further for
correctness: it ALSO emits a single aggregate **"loose cheque"** row for any
agreed `chequeTotal` not covered by named cheque envelopes. Rationale: the
GIRFT requirement is that close "writes a **balanced** gift log" — the sum of
every `tblGivingEntry` row written for a session must equal `cashTotal +
chequeTotal + envelopeTotal` exactly, not just cover the cash bucket. All
three checks below run BEFORE the transaction opens (`_apps/giving/count/close.php`),
so a rejected close never touches the database:

1. Status must be `'counting'` (not `'open'`, `'discrepancy'`, or already `'closed'`).
2. Agreed `cashTotal`/`chequeTotal`/`envelopeTotal` must all be set (non-NULL).
3. `SUM(tblCountEnvelopes.amount)` must equal the agreed `envelopeTotal` EXACTLY
   (integer-pence comparison, never `==` on floats/DECIMALs).

Then, in one transaction: one `tblGivingEntry` row per named envelope
(`donorID`/`donorName` from the envelope, `categoryID`/`donatedAt`/`siteID`
from the session, `reference = 'Count #<id>'`) + a loose-cash row (if the
agreed cash total exceeds what named cash envelopes cover) + a loose-cheque
row (same, for cheques) + `UPDATE … SET status='closed' … WHERE status='counting'`
(the `WHERE status='counting'` guard + checking `affected_rows` catches a
concurrent close/edit between the pre-checks and the write, aborting the
transaction rather than double-writing).

### Gate

Every route (`/giving/count`, `/giving/count/session`, `/giving/count/save`,
`/giving/count/close`) uses `Portal\Core\Giving::canManage()` — the same gate
`giving`'s existing `manage.php`/`entry-save.php`/`cat-save.php` already use
(site admin OR the `treasurer` role, migration 017). Resolving a live
`'discrepancy'` (`action=resolve` in `save.php`) additionally requires
`App::isAdmin()` — matching the issue body's "an admin resolves" wording;
plain treasurers can only re-enter counts or close once no discrepancy
remains.

### New core helper

`Portal\Core\Giving::parseDecimal(string $input): ?string` — validated
non-negative `DECIMAL(10,2)`-safe amount parsing (round-trips through
float → round → `number_format`, never trusts the client string into SQL
verbatim). Sibling to the existing `Giving::parseAmount()` (pence-int, used
by `tblGivingEntry` writes) — this workflow's independent counter totals are
DECIMAL columns on `tblCountSessions`/`tblCountEnvelopes`, not pence.

---

## Discipleship Pathway Tracker Phase 2 (#303 Phase 2)

Extension to Phase 1 (migration 142 — `tblPathways`/`tblPathwaySteps`,
admin CRUD only, app hidden behind `discipleship.enabled = 'false'`).
Phase 2 adds per-user enrolment/progress, auto-completion, member-facing
routes, and a pastor roster. New core helper: `Portal\Core\Discipleship`.

### Adopted scoping decisions (issue #303 blocker comment, 2026-06-21)

1. **Auto-completion sources — option (a), per-user tables only.**
   `tblEventAttendance` (rows with `userID IS NOT NULL` — walk-ins are
   excluded automatically by the sweep's `ea.userID = e.userID` join) and
   `tblEventRSVPs`. `tblSalvationCards` has no `userID` and
   `tblDecisionMoments` is an aggregate counter with no per-user rows —
   both are structurally incompatible with a per-user completion model,
   not merely deprioritised; revisit if/when either table grows a
   per-user identity column.
2. **Pastor surface stays a flat roster list.** One `portal-data-list` row
   per enrolled member (progress bar, n/m required steps, last
   completion), with drill-down to a per-member step list. A
   members×steps matrix was explicitly rejected — it's the house
   `<table>` ban plus the issue's own recorded decision.
3. **Mentor relationships deferred.** No `tblPathwayMentor` schema, no UI,
   in this phase.

### The revoke-vs-delete unmark semantic

`tblPathwayProgress` has `UNIQUE(stepID, userID)` — at most one progress
row can ever exist per (step, member) pair, for the lifetime of that step.
Unmarking a step (admin action, or a member's own auto-completed step
being corrected) sets `revokedAt`/`revokedByID` on that SAME row; it is
**never** `DELETE`d. "Complete" everywhere in the app — `progressFor()`,
`rosterStats()`, `refreshEnrolmentStatuses()`, the member/admin views —
means `revokedAt IS NULL`.

This is deliberate, not an oversight: `Discipleship::autoSweep()` writes
via `INSERT IGNORE`, relying on the unique key to make repeat sweeps a
no-op. If unmarking deleted the row, the very next sweep (lazy, on the
next page view) would see no conflicting key, re-insert the row from the
still-existing attendance/RSVP evidence, and silently resurrect a step a
coordinator deliberately corrected. Keeping the (now-revoked) row means
its unique key permanently blocks that re-insertion — the only way to
"undo an unmark" is the admin explicitly re-marking it complete again
(`progress-mark.php`'s `complete` action, which clears `revokedAt`/
`revokedByID` on the SAME row rather than inserting a new one).

One consequence worth knowing: a step revoked once can only ever be
un-revoked by a human action (manual re-mark). It will never silently
flip back to complete on its own, even if the auto-evidence that
originally satisfied it still exists — this is the intended trade-off
(coordinator correction wins over automation), documented here so it
isn't mistaken for a bug during support triage.

### Lazy-sweep design (no scheduler dependency)

`Discipleship::autoSweep(int $siteId, ?int $pathwayId = null)` is pure
set-based SQL (three `INSERT IGNORE … SELECT` statements, one per
`autoRule` value, each joining active pathways × active enrolments × the
matching evidence table) — cheap enough to run synchronously on every
page load rather than needing a background job. It is invoked at the top
of:

- `discipleship/index.php` (member "My pathways") — scoped to the site.
- `discipleship/view.php` (member pathway detail) — scoped to the pathway.
- `admin/discipleship/progress-pathway.php` (pastor roster) — scoped to
  the pathway.

Because every rule's `INSERT IGNORE` is idempotent via
`UNIQUE(stepID, userID)`, calling `autoSweep()` on every page view never
duplicates work — a repeat call over already-swept data inserts zero new
rows. `cron/discipleship-sweep.php` exists purely so a site with low
member traffic on discipleship pages still gets fresh auto-completions
(e.g. overnight) — it is a convenience, never a correctness dependency.

### Cron token setup

Same pattern as `reminders.cron_token` (migration 122): the endpoint reads
`?key=<value>`, compares it to `Settings::get('discipleship.cron_token', '')`
via `hash_equals()`, and 403s whenever the stored token is the empty
string — so the cron endpoint is inert until an admin explicitly sets a
non-empty `discipleship.cron_token` value (via `/admin/settings`; the
setting is seeded `isSensitive = 1`, so it's encrypted at rest like other
secrets). Point an external scheduler (e.g. DreamHost's cron, or a
third-party uptime-ping-style scheduler) at:

```
https://<your-portal-host>/cron/discipleship-sweep?key=<your-token>
```

The route (`cron/discipleship-sweep`, migration 153) is seeded
`isProtected = 0` — it is public but token-gated, exactly like
`cron/event-reminders`. It loops every distinct `siteID` that owns at
least one active pathway and runs one site-wide `autoSweep()` per site,
returning a plain-text `OK {"sitesSwept":N,"inserted":{...}}` summary.

---

### Webhooks setup

`Portal\Core\WebhookDispatcher::emit()` (#324) POSTs a signed JSON payload to
every active `tblWebhooks` row subscribed to an event, synchronously,
inline with the triggering request. A non-2xx response (or a network
failure) leaves the `tblWebhookDeliveries` row in `status = 'failed'` with
`attemptCount` and a computed `nextRetryAt` rather than giving up —
`WebhookDispatcher::retryDue()` (migration 166, #324 v1.1) is the
cron-driven async retry worker that sweeps those rows back up.

Same cron-token pattern as `reminders.cron_token` / `assets.cron_token` /
`discipleship.cron_token`: the endpoint reads `?key=<value>`, compares it
to `App::settings('webhooks.cron_token')` via `hash_equals()`, and 403s
whenever the stored token is the empty string — migration 166 seeds
`webhooks.cron_token` empty (`isSensitive = 1`), so the endpoint is inert
until an admin sets a real value via `/admin/settings`. Point an external
scheduler at (every 5-15 minutes is a reasonable interval — failed
deliveries retry with exponential backoff, so a tighter interval mostly
just wastes requests on rows not yet due):

```
*/10 * * * * curl -fsS "https://<your-portal-host>/cron/webhook-retry?key=<your-token>" >/dev/null
```

The route (`cron/webhook-retry`, migration 166) is seeded `isProtected = 0`
— public but token-gated, exactly like every other `cron/*` route. Each
run selects up to 100 `tblWebhookDeliveries` rows in `status = 'failed'`
with `attemptCount < 6` (`WebhookDispatcher::MAX_ATTEMPTS`) whose
`nextRetryAt` has elapsed (or was never set), oldest-`createdAt` first, and
re-delivers each through the SAME `attemptDelivery()` private method
`emit()`'s own initial attempt uses — no separate retry-vs-first-try
signing/POST logic to drift apart. A delivery that keeps failing backs off
exponentially (base 60s × 2^attempts, capped at 21600s / 6h) via
`DATE_ADD(NOW(), INTERVAL ? SECOND)` computed in SQL (not PHP's own clock,
so an app-server clock skew can never desync `nextRetryAt` from the DB's
own `NOW()` retryDue()'s WHERE clause compares it against); once
`attemptCount` reaches `MAX_ATTEMPTS` the row moves to the terminal
`status = 'dead'` and is never retried again. Returns a plain-text
`OK {"checked":N,"delivered":N,"failed":N,"dead":N}` summary.

---

## Event Team Hub Phase 1 (#386)

### Architecture: extends the calendar app, not a new app

`/calendar/event/hub` lives in `_apps/calendar/` (`event-hub.php` +
`event-hub-save.php`), not a new top-level app — CLAUDE.md is explicit
that Calendar/Events/Preaching Plan is ONE app, and every input the hub
composes (event, coordinators, crews, jobs, people) already lives in the
calendar app's tables, keyed by `eventID`. The two genuinely reusable
pieces — provider detection, embed URLs, CF signed tokens, CSP frame-src
lists — live in `_core/VideoEmbed.php` so `/live`, noticeboard, or
recordings can adopt the same helper later without dragging in the
calendar app.

### Access control: `canView` vs `canManage`

Two distinct gates, deliberately different in breadth:

- `canView` = `Auth::isEventTeamMember($eventId)` — coordinator OR crew
  leader/participant OR job assignee OR a `tblEventPeople` row (host/
  speaker/organiser/…). Grants read access to the hub page only.
- `canManage` = `App::isAdmin() || Auth::isCoordinatorOf($eventId)` — the
  existing house idiom, unchanged, used by every other event sub-tool
  (crews/jobs/attendance/broadcast). Grants the inline add/edit/remove/
  reorder forms and the tool-strip links.

`isEventTeamMember()` mirrors `isCoordinatorOf()`'s shape exactly (same
`self::check()` + `$eventId <= 0` guards, same `$db->prepare()` /
`bind_param()` / `fetch_assoc()` / `close()` rhythm) so the two methods
stay trivially comparable during review. All four membership tables
(`tblEventCoordinators`, `tblEventCrewMembers`, `tblEventJobAssignments`,
`tblEventPeople`) cascade-delete with their event, so hub access can never
outlive the event it's scoped to.

### Video providers: allowlist detection, never a raw embed

`VideoEmbed::parse()` is the single place a pasted string becomes a
`{provider, ref}` pair — YouTube (`watch?v=`, `youtu.be/`, `/shorts/`,
`/live/`, `/embed/`), Vimeo (`vimeo.com/<id>`,
`player.vimeo.com/video/<id>`), or Cloudflare Stream (UID embedded in
`customer-*.cloudflarestream.com`, `watch.cloudflarestream.com`, or
`iframe.videodelivery.net`, or a bare 32-hex UID). Anything else returns
`null` — `event-hub-save.php`'s `addVideo` action flashes an error and
stores nothing. Every `embedUrl()` call re-validates the ref's character
class immediately before string-interpolating it into an iframe `src`
(the same discipline as `Livestream::embedUrl()`'s ID sanitiser), so even
a corrupted/tampered database row can't produce an XSS-bearing iframe.

### Cloudflare Stream signed-URL playback (no vendored JWT encoder)

`_vendor/simplejwt/JWT.php` is **verify-only** (built for MS365/Google ID
tokens) — it has no encode/sign capability, and it is deliberately not
extended for this, since it's scoped as an IdP-token verifier. Signing is
new code in `VideoEmbed::signedToken()`:

1. Build the compact JWT segments by hand: `header = {"alg":"RS256",
   "kid":<keyID>}`, `payload = {"sub":<uid>, "kid":<keyID>,
   "exp":now+ttl, "downloadable":false}`, both base64url-encoded (RFC
   4648 §5, no padding).
2. `openssl_sign($header.'.'.$payload, $signature, $pem,
   OPENSSL_ALGO_SHA256)` — the same primitive `simplejwt` uses for
   verification, just run in the opposite direction.
3. Token = `header.payload.` + base64url(`$signature`).

The PEM (`cfstream.signingKeyPem`, `isSensitive = 1`, libsodium-encrypted
via `encrypt_setting()`/`decrypt_setting()`) and the key ID never leave
the server — only the short-lived JWT reaches the browser, inside an
iframe `src` that itself is never logged. A missing/invalid key makes
`signedToken()` return `null` (logged via `Logger::errorPlatform()`,
**never** logging the PEM or signature) — the hub page renders an
"unavailable — check Stream settings" tile rather than a broken iframe.
Tokens are cached in `$_SESSION['cfstream_tokens'][$uid]` plus a
per-request static memo, reused while more than 1/10th of
`cfstream.tokenTtlSeconds` (default 21600s / 6h) remains — RSA-2048
signing is ~1ms, so this is belt-and-braces, not load-bearing.

### Cloudflare Stream credential-setup runbook

Two **separate** credentials — see the two-credential explainer on
`/admin/integrations/cloudflare-stream` itself:

1. **Pick the Cloudflare account** that owns (or will own) the Stream
   subscription. This portal's Cloudflare MCP connection exposes D1/KV/
   R2/Workers only, **not Stream** — the portal cannot see or create
   either credential below; an admin must do both in the Cloudflare
   dashboard/API directly.
2. **Create the Stream:Edit API token** (Phase 1.5 — captured now for
   forward-compatibility, unused by anything shipped in this release):
   Cloudflare dashboard → **My Profile → API Tokens → Create Token →
   Custom Token** → permission **Account → Cloudflare Stream → Edit**,
   scoped to the one account from step 1 (not "all accounts"), no zone
   permissions. Optionally pin it to the DreamHost server's outbound IP
   under "Client IP Address Filtering". Paste the token into **API
   token** on the admin page — it's `isSensitive`-encrypted and never
   re-displayed once saved.
3. **Create the signing key** (used TODAY for signed-URL playback): the
   Cloudflare dashboard has **no UI** for this — it must be created via
   the API:
   ```
   curl -X POST "https://api.cloudflare.com/client/v4/accounts/<accountID>/stream/keys" \
        -H "Authorization: Bearer <a token with Stream:Edit>"
   ```
   The response contains `result.id` (→ **Signing key ID**) and
   `result.pem` (→ **Signing key PEM**, base64-encoded in the API
   response — paste the decoded PEM, including the
   `-----BEGIN PRIVATE KEY-----`/`-----END-----` lines, into the
   textarea). **This is a one-time, non-retrievable secret** — Cloudflare
   does not let you fetch the private key again after creation, so paste
   it into the admin page (or a password manager) immediately.
4. Fill in **Account ID** (32-hex, from the Cloudflare dashboard URL or
   `GET /accounts`) and **Customer code** (the `customer-<code>` segment
   your account's Stream playback URLs already use — visible on any
   existing video's embed code, or `GET /accounts/{id}/stream` → any
   video's `preview`/`playback.hls` URL).
5. Tick **Enable Cloudflare Stream video embeds** and save. Existing
   Cloudflare Stream videos on the hub whose `requiresSignedUrl` is
   unticked will start rendering immediately (playback needs only the
   customer code); signed videos need the signing key from step 3.

### Phase 1 vs Phase 1.5 scope

Phase 1 (this build) is **external references only** — a coordinator
pastes a YouTube/Vimeo URL or a Cloudflare Stream UID/URL;
`tblEventHubVideos.uploadStatus` is always `'external'`. Deliberately OUT
of scope, tracked as Phase 1.5 against the same issue (#386):

- `Portal\Core\CloudflareStream` — the Cloudflare *management* API client
  (mint/poll/edit/delete direct-creator-uploads), modelled on `Zoom.php`.
- The direct-upload JSON endpoints (`calendar/event/hub/video/upload-url`,
  `calendar/event/hub/video/status`) and the vanilla-XHR upload widget
  (progress bar, no `tus` in the first build — CSP forbids CDN JS, and
  `tus-js-client` would have to be vendored).
- `$cspConnectExtra` — a new page-scoped CSP extension point mirroring
  the existing `$cspImgExtra`/`$cspMediaExtra`/`$cspFrameExtra` trio in
  `header.php`, needed so the browser can POST straight to Cloudflare's
  upload URL without touching DreamHost's PHP upload limits.

`tblEventHubVideos` already carries every Phase 1.5 column
(`uploadStatus`, `errorDetail`, `uploadedAt`, `lastCheckedAt`,
`allowedOrigins`) and the admin page already carries every Phase 1.5
setting (`cfstream.apiToken`, `cfstream.maxUploadDurationSeconds`,
`cfstream.uploadMintPerHour`, `cfstream.defaultRequireSignedUrls`,
`cfstream.allowedOrigins`) — Phase 1.5 should need zero schema/settings/
admin-page changes, only new POST handlers gated behind
`CloudflareStream::isConfigured()`.

#### Phase 1.5 — SHIPPED (migration 156, routes only)

As predicted, Phase 1.5 needed no schema/settings/admin-page change — just
`Portal\Core\CloudflareStream` and three page-route handlers seeded by
migration 156 (routes only):

- `calendar/event/hub/upload-url` (POST, JSON) — mints a one-time
  direct-upload URL via `CloudflareStream::createDirectUpload()` and inserts
  an `uploadStatus='pending'` row. Gated in order: session → CSRF →
  admin/coordinator → cross-site → `isConfigured()` → per-user hourly rate
  limit (counts `tblActivityLogs` `CfStreamUploadMinted` rows in the last 60
  min — no separate table) → input/origins validation → CF call. Returns the
  rotated CSRF token so the no-reload poll loop keeps working.
- `calendar/event/hub/video-status` (POST, JSON) — polled ~4 s; terminal
  (`external`/`ready`/`error`) + non-CF rows return the stored state with no
  CF call; `pending`/`processing` throttled to one CF GET per ~5 s;
  reconciles the `requiresSignedUrl`/`allowedOrigins` mirrors from every
  live GET; a transient CF failure only stamps `lastCheckedAt`, never flips
  a video to `error`.
- `calendar/event/hub/video-settings` (POST, form + redirect/flash) —
  **CF-first**: `CloudflareStream::updateVideo()` runs first, the local
  mirror updates only on confirmed success.

Client: `web/public_html/assets/js/event-hub-upload.js` — basic ≤200 MB
upload (tus deferred, still not vendored), enforces the size cap **and** a
host allowlist (`upload.videodelivery.net` / `upload.cloudflarestream.com`)
on the returned `uploadURL`, and writes each response's rotated CSRF token
back into `<meta name="csrf-token">` and every `input[name=csrf_token]`.
`$cspConnectExtra` was added to `header.php` (identical pattern to
`$cspFrameExtra`); the hub sets it to the two upload hosts only for a
manager on a configured install. `event-hub-save.php`'s `removeVideo`
best-effort-deletes a portal-uploaded CF video first (a CF failure is logged
but never blocks the local delete — a coordinator must never get stuck with
an undeleteable row, e.g. when CF already 404s the asset).

**Runbook — the second credential (API token).** Distinct from the signing
key (step 3 above): Cloudflare dashboard → **My Profile → API Tokens →
Create Custom Token** → permission **Account → Cloudflare Stream → Edit**,
scoped to the Stream-owning account only → paste into
`admin/integrations/cloudflare-stream` (`cfstream.apiToken`). With it set
plus `cfstream.accountID`, the "Upload to Cloudflare" panel appears on the
hub for coordinators/admins. Until it is set, `CloudflareStream::
isConfigured()` is false and the whole upload path is inert — the hub is
exactly the Phase-1 paste-a-link experience.

---

## Event Team Hub REST API read endpoints (#387)

### Why: projectBookIT Event Team Hub Phase 3 integration

`_apps/calendar/api/hub-resources.php` and `hub-videos.php` expose the two
tables from Event Team Hub Phase 1 (#386, migration 155) over the public
REST API so an external system — projectBookIT's Event Team Hub Phase 3
(projectbookit#347) — can pull a given event's resources/videos with a
site-scoped bearer key, without any session/cookie access to this portal.

### Convention path, ApiRouter routing trap applies exactly as usual

Both handlers live at `_apps/calendar/api/{action}.php` — the standard
convention path `ApiRouter::dispatch()` resolves directly from the URL
(`api/calendar/hub-resources` → `_apps/calendar/api/hub-resources.php`).
**Neither is registered in `tblRoutes`** — `api/*` paths never reach
`tblRoutes` at all (`Router::handleSpecialRoutes` hands them straight to
`ApiRouter::dispatch` first) — see CLAUDE.md's "ApiRouter routing trap".
Their own switches are `api.calendar.hub-resources.enabled` /
`api.calendar.hub-videos.enabled` in `tblSettings`, seeded `'true'` by
migration 157 (settings-only migration — no schema, no routes). Since #478
the Calendar app's own switch applies as well: with Calendar off, both answer
403 before the handler runs.

**Who is answered (#534, 11 October 2026).** Until then the event lookup
tested the event's number, organisation, "not deleted" and status, and nothing
about the viewer, so any signed-in account from any organisation could read an
internal event's Team Hub. Both lookups now carry the shared rule
(`EventVisibility::where()`), used exactly as `events/api/detail.php` uses it:
"session" mode for a signed-in person, "key" mode for an API key (which is
answered as before). A refused event is the same "Event not found" as a
missing one. What this does not do: the Team Hub PAGE lets in only the event's
team (`Auth::isEventTeamMember()`), and these endpoints still answer anybody
who may see the event. That is older than #534 and is written up as a
follow-up.

Note `calendar` was **not** added to `ApiRouter::V1_RESOURCES` — these two
endpoints are legacy-convention-path only, reachable at
`/api/calendar/hub-resources` / `/api/calendar/hub-videos`, with no
`/api/v1/calendar/...` facade alias. Adding one is a reasonable future
follow-up but was out of scope for #387 (the projectBookIT consumer only
needs the legacy shape).

### Auth: same dual-mode pattern as `events/list.php`/`detail.php`

Both handlers open with `ApiAuth::requireRead('eventhub:read')` — the
identical one-line dual-mode (bearer OR session) + scope-check idiom used
by every other read endpoint. `eventhub:read` is a **read-only** scope
(no `eventhub:write` counterpart exists yet — the Team Hub's own
add/edit/remove/reorder actions stay on `event-hub-save.php`'s session-only
CSRF-protected form POST; the REST surface here is consumption-only for
Phase 3). The admin API-keys mint form's `scopeGroups` grouping
(`_apps/admin/integrations/api-keys.php`) already tolerates a
read-without-write resource — it renders only the checkboxes present in
`ApiKey::SCOPES` for that resource prefix, so `eventhub` shows a single
"Read" checkbox with no empty "Write" slot.

### Tenant guard: explicit 404, not a WHERE-clause-only filter

`tblEventHubResources`/`tblEventHubVideos` are child tables of `tblEvents`
with **no own `siteID` column** (matches the `tblEventCrews`/`tblEventJobs`
precedent from migrations 117/118 — see the migration 155 header). Both
handlers therefore run an explicit tenant-guard query BEFORE touching
either hub table:

```php
SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1
```

A miss (wrong site OR event doesn't exist OR soft-deleted) returns the
identical `ApiResponse::error('Event not found', 404)` in every case —
there is no separate "event exists but wrong tenant" response shape, so a
scan across `eventID` values from another tenant's API key can't be used
to enumerate which IDs exist elsewhere.

### No-secret-leak discipline on the video endpoint

`hub-videos.php` SELECTs an explicit column list — `videoID, provider,
videoRef, title, requiresSignedUrl, allowedOrigins, uploadStatus,
sortOrder` — never `SELECT *`, so a future `tblEventHubVideos` ALTER
(e.g. a Phase 2 column) can't silently widen the API response. `videoRef`
is intentionally included: it's the public YouTube/Vimeo ID or Cloudflare
Stream UID a player embeds against, not a secret. What's deliberately
EXCLUDED: any Cloudflare signing key, any signed playback token (minted
per-viewer by `VideoEmbed::signedToken()` on the portal's own hub page,
never handed to an API consumer), and every `cfstream.*` setting value —
none of those columns/settings are read by either handler at all.
`requiresSignedUrl`/`allowedOrigins` are playback-*policy* metadata (would
a token be required, from which origins), not the token/key material
itself, so returning them is safe and useful to a consumer deciding how to
embed the video.

---

## Discovery-pass fold-in batch (#373 ApiRouter half, #339, #308, #255, migration 158)

### ApiRouter never got the Router.php #373 fix

`Router::dispatch()` was fixed for #373 by importing `global $mysqli,
$SETTINGS;` immediately before `require $targetFile;` (commit `58871ca`) —
PHP include scope is the enclosing function's locals, so a legacy controller
reading the bootstrap DB handle as a bare `$mysqli` needs that global
imported into `dispatch()`'s scope or it resolves to `null`.
`ApiRouter::dispatch()`/`dispatchV1()` include handlers the identical way
(`require $apiFile;` inside a static method) but never got the same
import — six live handlers (`livechat/api/*`, `livestream/api/ping.php`)
read bare `$mysqli` and fatally errored (`Fatal error: Call to a member
function prepare() on null`) on every request. Fixed by mirroring
`Router.php:128` at both `ApiRouter.php` call sites. No-op for handlers
already using `App::db()` (the preferred pattern for new code).

### Worship live-sync relocation — same shape as migration 144

`worship/present.php` (operator console) and `worship/display.php` (public
projector display) poll `/api/worship/state` + POST `/api/worship/advance`.
The real handlers pre-existed at `_apps/api/worship-{state,advance}.php`
— a location `ApiRouter::dispatch()` can never resolve to, because it
builds the include path directly from the URL segments
(`_apps/{appName}/api/{action}.php`) and never queries `tblRoutes`. Moved
both files **verbatim** (same SQL, same auth checks, same CCLI logging) to
`_apps/worship/api/{state,advance}.php` — the only change is the file
location and the docblock. Precedent: migration 144 did the identical
relocation for `api/livestream/ping` → `_apps/livestream/api/ping.php`.
Migration 158 seeds `api.worship.state.enabled` / `api.worship.advance.
enabled` = `'true'`; ApiRouter 403s a convention-path handler with no
enabled flag exactly like a missing one, so the relocation alone isn't
enough.

### AppRegistry trap — registering an always-on app needs its enable seed IN THE SAME migration

`AppRegistry::isEnabled()` returns `false` when an app's `settingKey` is
absent from `$SETTINGS` (`AppRegistry.php:95-116`), and
`Router::dispatch()` renders a 403 "app disabled" page for any registered-
but-disabled app's routes (`Router.php:113-117`). Before this batch,
`noticeboard`/`worship`/`salvation`/`kids` had working routes/tables/
handlers but no `_core/apps/{slug}.php` file — `AppRegistry::appForRoute()`
never matched them, so `Router::dispatch()`'s gate check (`$owningApp !==
null && isEnabled(...) === false`) short-circuited on `$owningApp === null`
and every request passed through ungated. The moment a `_core/apps/{slug}
.php` file is added, that app becomes gate-eligible — if its `enabled`
setting isn't ALSO seeded `true` in the same change, the app goes dark
immediately (silent 403 on every route). `noticeboard.enabled` was already
seeded (migration 145) since it self-checks the setting directly in its own
code; `worship`/`salvation`/`kids` had no enable flag anywhere, so migration
158 seeds all three `= 'true'` in the same migration that ships their
`_core/apps/*.php` files — never split across two migrations/PRs.

### Dead `api/*` tblRoutes rows — the check_*.py DELETE-tombstone parser only understands `= '...'` / `IN (...)`

`check_route_targets.py` and `check_schema_seed_parity.py` both model
`DELETE FROM tblRoutes WHERE routeKey = '...'` and `... WHERE routeKey IN
(...)` structurally (see each script's `delete_re`/`DELETE_RE`) so they can
compute the "final state" after every migration replays and confirm
`full_schema.sql` stays in parity. A `LIKE 'api/%'` pattern is NOT
recognised by either regex — using it would have left the 19 removed rows
looking "still expected" in `full_schema.sql`, breaking parity. Migration
158 therefore spells out all 19 `routeKey` values explicitly in a `DELETE
... WHERE routeKey IN (...)` — functionally identical to a `LIKE` sweep
(nothing else currently starts with `api/`) but readable by the audit
tooling. Precedent: migration 056 did the same explicit-list `DELETE` for
an earlier batch of 5 dead `api/*` rows.

### Cloudflare Stream "Test connection" — machine-safe response only

`CloudflareStream::testConnection()` calls the cheapest Stream endpoint
that validates BOTH `cfstream.accountID` and `cfstream.apiToken` together —
`GET /accounts/{acct}/stream?per_page=1` — succeeds on a brand-new account
with zero videos, no uid needed. Returns `{success, message}` with a small
fixed set of generic messages (not-configured / 401-403 / 404 / generic
transport failure) — Cloudflare's own error text is deliberately never
echoed back to the browser (only logged server-side via the shared
`request()` path, same as every other `CloudflareStream` call), so a
copy-pasted screenshot of the admin page can't leak anything
token-adjacent. This is also the best low-risk way to firm up the
**[CF-kc]**-flagged endpoint set (see `CloudflareStream.php`'s class
docblock) before the first real upload — a wrong endpoint shape now
surfaces as a clear "could not reach Cloudflare Stream" on the settings
page instead of a silent failure discovered mid-upload.

---

## PayPal payment adapter + online giving/pledge checkout (gap #1)

### Sandbox setup

1. Create a PayPal Developer account at https://developer.paypal.com and a
   **Sandbox** app (Apps & Credentials → Create App). Note the **Client ID**
   and **Secret** — these are the sandbox pair, distinct from any live pair.
2. On `/payments` (admin), set Provider = PayPal, Mode = Sandbox, paste the
   Client ID + Secret, tick Enable. Client ID and Secret are both stored
   encrypted at rest (libsodium, same as Stripe's secret key) and rendered
   as password-style "leave blank to keep" inputs — the saved value is
   never re-echoed into the form.
3. Create a webhook subscription (Apps & Credentials → your app → Add
   Webhook) pointed at the URL shown on the admin page:
   `https://<your-host>/payments/webhook?provider=paypal`, subscribed to
   exactly these three events:
   - `CHECKOUT.ORDER.APPROVED`
   - `PAYMENT.CAPTURE.COMPLETED`
   - `PAYMENT.CAPTURE.REFUNDED`
4. Copy the webhook's **Webhook ID** (`WH-…`) into the admin page's
   "Webhook ID" field and save. This is the ONE value
   `paypalVerifyWebhook()` needs to call PayPal's verify-webhook-signature
   API — leaving it blank means every PayPal webhook 401s (fail-closed by
   design, not a bug: S13 in the threat table below).
5. Test end-to-end with a PayPal sandbox buyer account (created
   automatically alongside the sandbox app, under Sandbox → Accounts):
   `/giving/give` → pick a category → an amount → "Continue to secure
   payment" → approve as the sandbox buyer → land back on
   `/payments/return` → `tblPayment` should show `succeeded` with the
   PayPal capture id as `providerRef`, and exactly one `tblGivingEntry` row
   (`reference = 'payment:{id}'`).

Going live is the same five steps against a **Live** app + Mode = Live —
there is deliberately no code branch that reads `payments.test_mode` for
PayPal; `payments.paypal.mode` is the sole authority for which API base
URL (`api-m.sandbox.paypal.com` vs `api-m.paypal.com`) is used, and
anything other than the literal string `'live'` is treated as sandbox
(fail-safe: a typo or half-finished migration hits sandbox, never live
money).

### Why `intent=CAPTURE` orders need TWO capture triggers

Unlike Stripe Checkout (charged the moment the payer completes checkout),
a PayPal Orders v2 order with `intent: 'CAPTURE'` is only APPROVED when
the payer finishes on PayPal's page — an explicit second API call
(`POST /v2/checkout/orders/{id}/capture`) actually takes the money. Two
paths make that call, both funnelled through the same
`paypalCaptureOrder()` → `paypalHandleCaptureResult()` →
`markPaymentSucceeded()` chain:

1. **Return path** (primary, fast) — `payments/return.php` calls
   `Payments::finalizeReturn()` before rendering, which captures
   immediately when the row is still `pending` and the redirect result is
   `ok`. Most payers land here within seconds of approving.
2. **`CHECKOUT.ORDER.APPROVED` webhook** (backstop) — covers a payer who
   approves on PayPal's page and then closes the tab / loses connectivity
   before the redirect completes. Without this, that payment would sit
   `pending` forever (PayPal auto-voids an uncaptured APPROVED order after
   ~3 days, so the payer is never actually charged, but the giving/pledge
   record would never land either).

Both paths — plus the `PAYMENT.CAPTURE.COMPLETED` webhook that arrives
after ANY capture, whoever triggered it — can race each other for the same
row. That's safe by construction: `markPaymentSucceeded()`'s final
transition is a single atomic
`UPDATE tblPayment SET status='succeeded', … WHERE status='pending'`
gated on `affected_rows === 1` — only the first caller to win that WHERE
clause fans out into Giving/Projects; every later caller (a slower webhook
delivery, a double-click, a retried delivery) sees `affected_rows === 0`
and returns immediately.

### ★ The S1 integrity gate — the one control that matters most

`Payments::markPaymentSucceeded()` is the single choke point every success
path funnels through, and it now takes two additional parameters:
`?int $observedAmountPence, ?string $observedCurrency`. When non-null,
they are asserted EXACTLY (`===`, both amount and currency) against the
pending row's own `amountPence`/`currency` BEFORE the atomic transition
above runs. A mismatch:

```php
UPDATE tblPayment SET status = 'failed',
       errorMsg = 'amount-mismatch exp:{row} got:{observed}'
 WHERE paymentID = ? AND status = 'pending';
-- + Logger::activity('PaymentIntegrityFail', …)
-- + return; -- NEVER falls through to the fan-out below
```

Every PayPal call site extracts the observed amount from the field PayPal
itself reports back (the capture response's `amount.value`/
`currency_code`, the same fields on the 422-reconcile GET-order response,
or the verified webhook's `resource.amount`) and parses it through
`paypalMoneyToPence()` — a STRICT `^([0-9]+)\.([0-9]{2})$` parser that
returns `null` on anything else (`"10.5"`, `"1,000.00"`, scientific
notation). A `null` parse result is itself treated as an integrity failure
(row marked `failed`, `PaymentIntegrityFail` logged) — it is never passed
through to `markPaymentSucceeded()` as `null`, because a PayPal call site
passing `null` observed values would silently DISABLE the gate for that
call. **Grep rule for any future PayPal code path:**
`grep -n 'markPaymentSucceeded' _core/Payments.php` — every PayPal call
site must show 4 arguments, never 2. Stripe call sites keep passing 2 args
(unchanged behaviour) — extending the same gate to Stripe using
`checkout.session.completed`'s own `amount_total`/`currency` fields is a
noted follow-up, not done in this PR, to keep the Stripe diff at zero.

### `checkout.php` — what #430 already hardened vs. what this PR added

`checkout.php` already validated `purpose`/`purposeRef` server-side before
this PR (PR #430): the pledge branch requires `donorID` to be the logged-in
user and forces `amountPence`/`currency` from the pledge row (never the
POSTed amount — an under-payment could otherwise still fulfil a pledge in
full); the giving branch validates the category is active and site-scoped;
anything else coerces to `purpose = 'other'` with `purposeRef = null` (no
fan-out target, so nothing to forge). This PR's additions sit ADJACENT to
that, not instead of it:

- **`GIVING_MAX_AMOUNT_PENCE`** (currently `1_000_000` — £10,000) rejects
  an online GIVING amount above the ceiling with a "For large gifts please
  contact the office" flash. Pledges are exempt — a legitimate pledge can
  exceed this, and its amount is forced from the pledge row regardless of
  what (if anything) was POSTed.
- **Server-built descriptions** — `description` is no longer read from
  `$_POST` at all; it's built from the already-validated category name
  (`'Giving — {name}'`) or project title (`'Pledge — {title}'`, truncated
  to 120 chars). This closes a text-injection vector that becomes
  materially worse once a REAL provider-hosted page exists to inject into
  — a POSTed description previously flowed straight into the (until this
  PR, unreachable) provider checkout page's product/order name.

One implementation quirk worth knowing: the 100-pence floor check runs
BEFORE the purpose branch that overrides the amount for a pledge, so the
pledge "Pay now" form on `my-pledges.php` submits a throwaway
`amount=1.00` hidden field purely to clear that floor gate — the pledge
branch immediately overwrites `$amountPence` from the pledge row, so the
throwaway value never reaches the provider.

### Q5 — pledge/checkout currency mismatch

`/payments/checkout` always charges `payments.currency` (one site-wide
currency), but `tblProject.currency` is set per-project. A pledge on a
EUR-denominated project would, if charged, be billed `payments.currency`
(e.g. GBP) at face value — a pre-existing quirk that #430's pledge-amount
forcing doesn't address (it forces the AMOUNT, using
`pledge['currency'] ?? $currency`, but `Payments::startCheckout()` still
ultimately hands the provider whatever `$currency` ends up as). The v1
mitigation shipped here is minimal: `my-pledges.php` only renders the "Pay
now" button when `project.currency === payments.currency`; a currency
mismatch hides the button rather than risking a wrong-currency charge.
Proper multi-currency checkout is a separate, larger issue.
## Venue Bookings (#429)

### Wall-clock rule (make-or-break)

`tblVenueBookings` times are wall-clock VENUE-local (`DATE` + `TIME`, never
converted to UTC — a 09:30 hire stays 09:30 across DST). `tblEvents`
datetimes are wall-clock EVENT-local in `timezone` (the one zone column an event has, #604).
`Venues::classifyEventCoverage()` therefore compares wall-clock to
wall-clock: identical IANA zones ⇒ direct comparison, NO conversion;
differing zones ⇒ convert event-zone → venue-zone via `DateTimeImmutable`.
UTC never appears in this path. Never "fix" either store to UTC.

**Follow-up issue #435 (fixed):** `tblEvents.*DateTime` column comments used
to say "stored in UTC" — that was wrong. Events are stored wall-clock
event-local (proof: `calendar/manage/save.php` binds the raw POST value
verbatim; `calendar/event.php` reads it back in the event's own zone;
`Ical.php` emits a `TZID`, never a trailing `Z`). Corrected the `startDateTime`/
`endDateTime` `COMMENT`s in `full_schema.sql` and their source migration
(`008_calendar_events_schema.sql`) to `'... wall-clock local (venue/site
local); NOT UTC'` — documentation only, no data change, no rebuild
migration (see "Datetime handling" note below).

### Datetime handling (#435)

`tblEvents.*DateTime` (and every other wall-clock date/time column in this
codebase — `tblVenueBookings`, etc.) store **wall-clock local** (venue/site
local), **NOT UTC**. Compare wall-clock-to-wall-clock; convert only across
differing IANA zones (`DateTimeImmutable`), never to/from UTC as an
intermediate step.

### Native XLSX parser caps (no Composer)

`Venues::parseWorkbook()` reads `.xlsx` via `ZipArchive` + `SimpleXML` with
hard caps: upload size ≤ `venues.maxFileSize` (10 MB fallback when unset),
≤ 200 zip entries, ≤ 20 MB per entry (checked pre-extraction), only the
workbook/rels/sheets/sharedStrings parts are read, `LIBXML_NONET` always
set and `LIBXML_NOENT` never set, 5,000 rows/sheet, 500 chars/cell. Excel
serial dates are converted explicitly rather than trusted from cell
formatting. `ZipArchive` availability isn't guaranteed on every shared host,
so the import wizard checks `class_exists('ZipArchive')` up front and
degrades to CSV-only with a visible notice when it's missing — the CSV path
never depends on `ZipArchive` at all.

### Scheduler

Add alongside the other cron lines (token in `venues.cron_token`, seeded
empty — the endpoint is inert until an admin sets a real value, same
pattern as `discipleship.cron_token` / `assets.cron_token`):

```
https://<your-portal-host>/cron/venue-reminders?key=<your-token>   (daily)
```

The route (`cron/venue-reminders`, migration 170) is seeded
`isProtected = 0` — public but token-gated, exactly like
`cron/asset-reminders`. It loops every distinct `siteID` with at least one
active venue, skips sites with `venues.enabled` or
`venues.reminders_enabled` off, and runs three sweeps per site (un-agreed
bookings, agreement renewals, invoices due) with a `(refType, refID,
dueDate)` dedupe key so a re-scheduled item naturally re-reminds without
spamming on every run.

### Per-event venue/room links + room-aware coverage (gap #436, migration 179)

Additive follow-up: `tblEvents` gains two optional nullable columns
(`venueID`, `roomID`, FKs `ON DELETE SET NULL`) so an event can declare
which hired venue — and optionally which room within it — it's actually
held at, upgrading the calendar manage form's venue picker from a
transient advisory-only check into a **persisted** link. See the
`full_schema.sql` fold pattern subsection above ("an ALTER's FK target is
created LATER in the file") for why the FKs live only in the migration,
not the fold.

- **`classifyEventCoverage(array $event, int $venueId, ?int $roomId =
  null)`** — the `$roomId` param is optional and trailing specifically so
  both pre-#436 call sites (`venues/api/check.php`, `calendar/manage/
  save.php`) compile and behave **identically** unchanged when they don't
  pass it. When set, it's re-validated site+venue-scoped via the existing
  private `validateRoomForVenue()` (no new query) — an unresolvable room
  (wrong venue/site, deleted, `<= 0`) silently degrades to venue-level
  coverage rather than erroring, the same "no existence oracle" posture as
  the pre-existing venue-missing sentinel.
- **The room filter runs on `availabilityForRange()`'s existing output** —
  that query already selects `b.roomID` (needed by the calendar strip
  tooltips), so no schema/query change was needed to make coverage
  room-aware, only a PHP-side `array_filter()` on rows already in hand:
  `roomID IS NULL` (whole-venue booking) or `roomID = $roomId` survives the
  filter; everything else (including that OTHER room's own
  `unavailable`/`closed` rows) is excluded — a Room B "unavailable" row
  must never mark a Room A event unavailable, and vice versa a whole-venue
  `unavailable` row still blocks every room.
- **New verdict `COVERAGE_ROOM_NOT_COVERED`** (`'room-not-covered'`,
  severity `danger`) fires when the room-filtered rows have nothing to say
  (no filtered row reached an earlier cascade branch) but the *unfiltered*
  day's rows include a confirmed bookable hire — i.e. the venue is
  genuinely booked that day, just not for this room. Slotted into
  `WORST_ORDER` immediately after `COVERAGE_NO_BOOKING`; unreachable when
  `$roomId` is null, so its insertion cannot change any existing
  (roomless) call's worst-case ordering.
- **Write-path invariant:** `roomID` never survives without a `venueID` it
  validated against — `calendar/manage/save.php` always re-validates the
  room against the *posted* venue (`Venues::getRoom($room, $venue,
  $site)`), so changing an event's venue automatically drops a stale room
  from the old venue (the room-belongs-to-venue check simply fails against
  the new one). The DB-level `ON DELETE SET NULL` FK only covers the
  narrower case of the room itself being deleted.
- **Disabled-app symmetry, both directions:** render-side, the picker sits
  entirely inside the pre-existing `$hasVenueCheck` guard (empty
  `$venueOptions` ⇒ no venue/room markup at all — the #429 resilience
  pattern, unchanged). Write-side, `save.php`'s UPDATE **omits**
  `venueID`/`roomID` from its `SET` list entirely when the Venues guard
  isn't active, rather than writing NULL — toggling the app off and
  editing an event must preserve that event's existing link, not silently
  erase it.
- **Bug fixed in the same PR:** the event-form's live "is it booked?" JS
  posted `startDateTime`/`endDateTime`/`timezone`, but `venues/api/
  check.php` has always read `start`/`end`/`tz` (its own documented
  header). Every live check 400'd on `invalid-range` and the JS's
  `.catch()` silently hid the alert — Surface A never actually worked
  since #429 shipped. Canonicalised on `check.php`'s existing contract
  (unchanged) and fixed the JS to match, adding `roomID` to the same
  request.

## Giving — Bulk year-end statements (gap #4, #440)

Treasurer-only "Annual statements" page at `/giving/statements`: pick a
period (calendar year default, free from/to fields, one-click UK-tax-year
preset), preview per-donor totals + Gift Aid eligible sums, then generate
PDFs, download them all as a ZIP, or email each donor their own statement.

### Renderer reuse (the whole point of this feature)

`Portal\Core\Giving::renderStatementPdf(int $siteId, int $donorId, string
$fromDate, string $toDate, string $periodLabel): string|false` is the
SAME function called by both the self-service page (`giving/my-
statement.php`) and the bulk batch (`giving/statements-generate.php`) —
their output is byte-identical by construction, not by convention. Three
deliberate upgrades landed on this shared function (so both callers get
them for free):

1. **Donor lookup is site-scoped** — a donor renders only if they are a
   currently-active member of `$siteId` OR have at least one giving entry
   recorded under `$siteId` (an `OR` of two `EXISTS` clauses, not a plain
   `INNER JOIN tblUserSites`). The first arm is the normal case (including
   a member who has never given, which the old — unscoped — query also
   allowed); the second arm lets a treasurer pull a single historical
   statement for a donor who has since left the site without reopening
   the original "any userID renders for any site" hole. A donor matching
   neither arm can never render, regardless of which site's treasurer
   asks.
2. **Gift Aid eligible column + summary** — per-entry eligibility via the
   existing declaration-window rule (`validFrom <= donatedAt AND (validTo
   IS NULL OR validTo >= donatedAt) AND status = 'active'`), computed with
   a correlated `EXISTS`, never a `JOIN` against `tblGiftAidDeclaration` —
   an overlapping pair of active declarations can never double-count a
   single entry, mirroring `buildHmrcCsv()`'s own known JOIN hazard.
   Statement shows "Total given" and "of which Gift Aid eligible"
   — deliberately **no** projected 25% reclaim figure; that belongs to the
   charity's own HMRC CSV claim, not a donor-facing estimate.
3. **Output path namespaced by siteID + periodKey** —
   `_uploads/giving/statements/{siteID}/statement-{donorID}-{periodKey}.pdf`
   (`periodKey` = `Giving::statementPeriodKey()`, `"{from}_{to}"`). Fixes
   the old flat `statement-{donor}-{year}.pdf` naming, which let the same
   donor in two sites overwrite one file with the other site's data.

### Batching (Newsletter dispatch pattern, copied deliberately)

DreamHost shared FastCGI can kill a long-running request regardless of
`set_time_limit()` (`admin/maintenance/offsite-backup-run.php` documents
this in its own header). `Giving::renderStatementBatch()` and the email
handler both cap their own work at `giving.statements.batchPerRun`
(default 25) per invocation — exactly `Newsletter::dispatch()`'s
`newsletter.batchPerHour` shape. Re-POST the same form ("Generate
statements" / "Email statements") to continue a larger run; the flash
message tells you how many remain.

### Dedupe / sent-log

One `tblGivingStatementLog` row per `(siteID, donorID, periodKey)`
(`UNIQUE` key) — the row IS the run state: `pdfPath` set = rendered,
`queuedAt` set = an email run has started, `emailedAt` set = sent (the
dedupe fence — every email selector adds `emailedAt IS NULL`, so a
completed row can never be re-picked without the explicit "Resend to
already-emailed donors" checkbox, which clears `emailedAt`/`errorMsg`
first and is audit-logged as `GivingStatementsResend`). `errorMsg` set on
a failed generate or send is never auto-retried — the preview page's
per-row "Retry" link (routes back through `statements-generate.php` with
`action=retry`) just clears it, re-entering the row into the normal next
batch.

### Opt-out

New `notifyPrefs` key `givingStatements` (default **on**, like every
other key) — whitelisted in `auth/account/notifications-save.php`, a
switch on `/account/notifications`. Enforced at BOTH queue time (the
preview page's "opted out" badge, and the email handler's queue-eligible
selector reads it indirectly via `Giving::sendStatementEmail()`) AND send
time (`Giving::sendStatementEmail()` re-decodes `notifyPrefs` fresh from
`tblUsers` immediately before sending — never trusts whatever was true
when the row was queued).

### Attachment integrity

`Mailer::attach()` silently DROPS any file over 4 MB rather than erroring
— a statement PDF is tens of KB, but `Giving::sendStatementEmail()` still
checks `filesize()` explicitly and marks the row `errorMsg = 'pdf-too-
large'` rather than ever letting a bodiless email go out.

### Optional cron sweeper

```
https://<your-portal-host>/cron/giving-statements?key=<your-token>   (every 15 min)
```

Token in `giving.cron_token`, seeded empty — the endpoint is inert until
an admin sets a real value (same pattern as `venues.cron_token` /
`assets.cron_token` / `discipleship.cron_token`). Unlike the venue/asset
reminder crons, this one is NOT required for a typical site — the
interactive "Email statements" button finishes a modest donor list in one
or two clicks. It exists for a larger site whose queue would otherwise
need many manual re-triggers: it sweeps every site's queued-but-unsent
rows in one pass (`giving.statements.batchPerRun × 4` per invocation),
calling `Site::forceContext()` per row purely so error logging attributes
correctly, and reading per-site settings (`giving.charityName` etc.) via
`App::settingForSite()` rather than the frozen `$SETTINGS` bootstrap
snapshot — the same cross-site-loop correctness rule `cron/venue-
reminders.php`'s header documents.

### GDPR erasure

`tblGivingStatementLog` rows are left alone by an erasure (donorID is
`NOT NULL` there), apart from the address a statement was mailed to, which
is emptied at once.

**The statement FILES changed in roll-out package A2b.** This note used to
say every rendered PDF of the erased donor's was deleted, "a name-bearing
document with no retention duty once its subject is erased". That stopped
being true: a statement the organisation ISSUED for gifts that keep the
donor's name is a copy of what the donor was given, and it cannot be made
again once the account is emptied. `eraseGivingStatementFiles()` now keeps a
file when the organisation is keeping that donor's name AND every gift on
the file is a kept gift: it keeps every gift, or its statement log has a row
for that donor and exactly that period, or (a statement the donor made for
themselves) at least one gift in its period still carries the donor and none
of the gifts this run took the donor off falls inside it. Every other file
is still deleted. The kept
files are removed by the retention job with the names. See "Giving records
keep a donor's name for a set time" below.

#### How an erasure runs (#510, #535, 11 October 2026)

`GdprEraser::execute()` works step by step, not as one transaction: the
header of `web/_core/GdprEraser.php` says why. Each instruction saves its
change and its one audit line together. A failed step is recorded and the
run carries on; the request then goes back to `pending_review` untouched.
The request is closed only as the last step, and is never deleted. If the
audit trail cannot be written the run stops and throws.

- **One list of person columns:** `GdprEraser::LINK_COLUMNS`. A person
  column missing from it is skipped, or emptied on other people's rows.
- **Kept on purpose:** Gift Aid declarations (tax; the six-year period is
  not confirmed with an accountant), and five tables no column ties to an
  account. Each gets a `retain` audit line.
- **Two tests, neither on GitHub yet.** `php tools/gdpr-coverage-selftest.php`
  needs no database. `tools/gdpr-eraser-selftest.php` runs a real erasure:
  give it a throwaway MySQL database built from `full_schema.sql`, named
  `selftest_…`, through `SELFTEST_DB_PORT`, `SELFTEST_DB_PASS` and
  `SELFTEST_DB_NAME`. Run it after any change to the catalogue or the
  eraser, as an ordinary user (not root). Proven on MySQL 8.0.36 and 8.4.11.
- **Three tests, run them together.** The third is
  `tools/erasure-pages-selftest.php`, which drives the real pages (same
  environment variables, plus `SELFTEST_WEB_DIR` for its scratch copy).
  The real-database test alone passes when an instruction wrongly deletes on
  a "who did it" column; only the coverage test catches that.
- **Still not handled:** names typed as free text, and ONE place pinned by
  name in the real-database test (`TEXT_THAT_CAN_HOLD_AN_ACCOUNT_NUMBER`):
  `tblWorkflowSteps.assigneeValue`, the account number of the one named
  person an approval step is given to. What that step should become when
  the person is erased needs a decision. (#492 was 37 columns on 11 October
  2026; all 37 are now built. `tblInvitation.acceptedByID` is the invited
  person's own account, so an accepted invitation is deleted with them.)
- **The test finds person columns three ways:** by `GdprEraser::LINK_COLUMNS`,
  by the database's links to `tblUsers`, and by the shape of the column's
  name (`PERSON_COLUMN_SHAPE`). The third was added after three columns with
  no link and no place on the list were found by hand. A new column with a
  person-shaped name must be dealt with or added to the test's list of
  look-alikes, with its reason.
- **An erasure does not end an open session.** Sessions are PHP's own files;
  `Auth::check()` only asks whether the session holds an account number. To
  end an erased (or deactivated) person's session, `Auth::check()` would have
  to confirm, once per request, that `tblUsers.isActive` is still 1 for that
  number and destroy the session when it is not. That change runs on every
  page, so it belongs in an access package, not here.

#### Other people's records, and one route to an erasure (roll-out package A2a, 11 October 2026)

- **A record is deleted only when it is ABOUT the erased person.** Being
  GIVEN a record (`assignedToID`) or being who a request is aimed at
  (`targetUserID`) is not that. Both names are in
  `GdprEraser::ACTOR_COLUMNS`, so a delete on either is refused by
  construction. They used to be classed as "about the person", and erasing
  a follow-up worker deleted the visitors, salvation cards, tasks and rota
  slots given to them. Do not move them back.
- **One instruction per person column (#492).** An instruction matches rows
  on ONE column. For a table the eraser handles from the written list, every
  person column named in `personal-data-catalogue.php` gets its own
  instruction: name the column there and it is dealt with; leave it out and
  it is not looked at. For a table with hand-written lines in
  `GdprEraser::catalogue()`, write one line per column. Never put a second
  person column in an instruction's `nullCols`: that empties it only on rows
  that also match the first column. The coverage test fails on either slip.
- **A table that may be deleted on a "who did it" column** (a child's event
  registration, on who submitted it) must be hand-written once it has a
  second such column. The derived route picks the column to delete on by its
  place in `LINK_COLUMNS`, and would have picked who REVIEWED the
  registration. The coverage test names the one column each such table may
  be deleted on.
- **A kept ("retain") table** may carry one other kind of instruction: one
  that empties a single "who did it" column and nothing else (who typed in a
  DBS check, who ran a giving statement). The coverage test allows exactly
  that and nothing wider.
- **`sqlSets`** on an instruction adds a fixed piece of SQL to its UPDATE.
  One use: a swap request still waiting is cancelled. The text goes into the
  statement as written, so it must never come from input; the coverage test
  holds the list to exactly that one.
- **`tblErasureRequest.claimedAt` is a moment in UTC.** Execute writes it
  with `UTC_TIMESTAMP()`, a run refreshes it about once a minute, and
  `GdprEraser::putBackStuckRequest()` compares it with `UTC_TIMESTAMP()`.
  Never `NOW()`: the server's own clock jumps an hour twice a year, and on
  the spring night that would let a request be put back while still running.
  The coverage test fails on any other clock.
- **One way to file a request: `GdprEraser::fileRequest()`.** Both
  `/account/erasure-request` and `/account/delete/confirm` call it. "Delete
  my account" used to empty the account itself with its own short list; it
  must never write to an account table again (the coverage test checks).
- **Three ways a request gets confirmed, and only three.** The emailed link
  ("Request data erasure"); the typed phrase ("Delete my account", which
  passes `true` to `fileRequest()` so the request starts at "pending review"
  and no email is sent); and `GdprEraser::confirmOnBehalf()`, the
  administrator's button for a request whose email never arrived. Only a
  page that has itself checked a typed phrase may pass `true`; the coverage
  test holds the callers to exactly those two pages.
- **`calendarToken` is cleared on erasure.** `Portal\Core\Ical` looks an
  account up by that key without asking whether the account is active, so a
  key left on an emptied account kept the feed address working.
- **The person's photo.** `eraseProfileImages()` deletes a file only when it
  is an image inside `_uploads` that no other account also names, and only
  then empties `avatarPath` and `displayPhoto`. Those two columns are NOT in
  the account instruction's list on purpose: emptying the link before the
  file is gone would orphan the file. Today no page stores a photo file
  (`avatarPath` is only ever an address from single sign-on; nothing writes
  `displayPhoto`), so this is exercised by the test's own files.
- **Two named lists in the real-database test** say where a delete is
  allowed to reach a kept table: `CASCADES_INTO_KEPT_TABLES` (a person's own
  visitor record takes its notes; their own Zoom connection takes its
  meetings) and `EMPTIED_LINKS_ON_KEPT_TABLES` (a deleted pledge empties the
  link on the gifts given towards it). Anything else fails.
- **Deploy order.** Migration 208 must run before an erasure can be started:
  Execute refuses on a database with no `claimedAt` column and says to run
  the upgrade.

#### Giving records keep a donor's name for a set time (roll-out package A2b, 12 October 2026)

The owner's rule (11 October 2026): on erasure everything about the person
goes at once, EXCEPT the giving records the organisation's country requires
it to keep. Those keep the donor's identity until a keep-until date, and the
retention job then clears them.

- **Where the name lives.** `tblGivingKeptDonors`, one row per organisation
  and erased donor (migration 209). The kept gifts keep `donorID`, so the
  tax claim file still matches each gift to its declaration by account
  number and date; the account that number points at is emptied as before.
  *Rejected:* putting the name on the declaration (most countries have no
  declaration), and emptying the number on every gift and linking kept gifts
  to the kept row instead (a gift given before the erasure and not yet
  claimed would drop out of the next claim, silently).
- **The order matters.** `GdprEraser::keepGivingRecords()` runs before the
  table walk, because it reads the name and the walk empties it (about half
  way through: the account's instruction is not the walk's last). **If it fails, the whole run stops**, the request goes back to
  "pending review" and nothing after it runs. It checks for the kept-names
  table first, for everybody, so no erasure starts on a database without
  migration 209.
- **The invariant (after the independent check of 12 October 2026).** The
  kept row is written near the START of an erasure; the account is emptied
  about HALF WAY through the table walk, with more of the walk and four
  further steps after it; a run can stop anywhere. (This note first said "at
  its END". That mistake is where the fault fixed under 3 below came from.)
  So:
  1. A kept name is cleared, and a donor is treated as erased, ONLY when the
     account has really been emptied. One definition:
     `GdprEraser::emptiedAccountSql()` (switched off, the made-up name, the
     made-up address). The clearing guard, the claim file, the statements
     list and `Giving::isErasedDonor()` all use it. "A kept row exists" is
     not the same question.
  2. The keep-until date is counted from the LATER of the date on the kept
     row and the newest gift (or open-count envelope) still linked to the
     donor there: `GivingRetention::stateOfKeptRow()`.
  3. Every later run of a request works the kept row out again, and never
     writes a made-up name over a real one. (This note used to say that on a
     second run "the account already says [Deleted User]". That is true only
     when the earlier run got as far as emptying it.)
     When the account had not been emptied yet, the row is also tied to the
     request now doing the erasing, so the line written at the clearing goes
     on the trail of the request that really erased the person.
     When the account HAS been emptied (the earlier run stopped after that
     step), the donor is already erased and the row is what was decided on
     that day: the later run sorts the gifts and the envelopes by the WIDER
     of the rule stored on the row and today's setting (if either says
     "every gift", every gift is kept), never changes the stored rule, and
     never empties the row. Only the retention job clears it, under its
     guard. (The first version sorted by today's setting in every case, so
     narrowing "which gifts" between two runs emptied a kept name at once,
     even under a hold. The second sorted by the stored rule alone, so
     WIDENING it between two runs took the donor off a gift that today's
     rule keeps. Both found by the checks of 12 October 2026. A donor taken
     off a gift cannot be put back, so the wider rule wins.)
  4. A request whose run has already CHANGED ANYTHING cannot be cancelled
     (`GdprEraser::runHasChangedAnything()`, asked by
     `admin/erasure/process.php` of a request that is still waiting). It
     reads the request's audit trail: a deleted row, an emptied column or a
     kept name. Nothing a run does can be put back. (It first asked only
     about the giving records, so a member with none whose run had already
     deleted their membership could still be cancelled.)
  The clearing decides INSIDE its transaction: it locks the kept row and the
  donor's gifts at that organisation, reads the rule and the state again, and
  deletes files and changes rows only if the answer is still "due". (It used
  to decide first and lock afterwards, and a gift recorded in that gap lost
  its donor.) What it cannot do: hold a new gift back on a server set to
  "read committed", where the lock on the gap does not exist.
  A record already past its keep-until date at erasure is cleared at the END
  of the run, once the account is emptied, not inside the giving step.
- **Counted envelopes** are handled by the giving step
  (`GdprEraser::unnameCountEnvelopes()`), not by the walk. An envelope in a
  count that is not yet closed keeps its giver when the gift it will become
  is a kept gift, so that closing the count makes a gift the tax claim can
  match. Its giver goes with the rest when the keep-until date has passed.
- **The seven settings are locked in the general settings editor**
  (`GivingRetention::lockedInGeneralEditor()`), which knows nothing of the
  un-tick or the second tick. A global administrator may still change the
  installation-wide `hold` row there.
- **One class decides everything.** `Portal\Core\GivingRetention`:
  `suggestionFor()` (what the portal suggests for a country), `ruleForSite()`
  (one organisation's rule, read fresh), `keepUntil()` and `stateFor()`
  (until when, and whether it may be cleared now). The erasure, the job, its
  preview and the settings page all call it. **Do not write a second date
  rule or a second "is it due" test.**
- **The guard is inside the clearing routine.**
  `GdprEraser::clearKeptGivingIdentity()` clears only when `stateFor()`
  answers `due`, which needs all three: no hold, a confirmed number of
  years, and the keep-until date passed where the organisation is. The job
  hands it every kept record and decides nothing itself. The files go first
  and the rows second: see that method's docblock for why.
- **Dates are calendar dates.** "Keep until" is text, `YYYY-MM-DD`, worked
  out with whole numbers for year, month and day. Nothing adds hours, so the
  two clock-change nights cannot move it. The one moment involved is "what
  is today's date where the organisation is" (`tblSites.timezone`). A zone
  that is empty or is not a NAMED zone answers the date at UTC minus twelve
  hours, so a bad zone can only clear late. (PHP accepts a bare offset such
  as "GMT+25" as a zone; the first version trusted that and was a day ahead
  of everywhere. The self-test found it.)
- **The keep-until date is never stored.** The kept row stores the fact (the
  last kept gift's date); the date is worked out from today's settings each
  time. A stored date would be left behind by a changed setting.
- **Counted from the end of the financial year**, not from the gift: most
  countries' rules start at a year end. With no year end set, one full year
  after the last gift is used, which is never earlier than any real year end
  would give (the self-test tries every day of the year).
- **A country per organisation**: `giving.retention.countryCode`, empty by
  default. `org.address.countryCode` is deliberately NOT read: it is one
  value for the installation and "GB" unless changed.
- **An organisation's rule is read from its OWN settings rows only**
  (`GivingRetention::ruleForSite()`), not through `App::settingForSite()`,
  which would fall back to the installation-wide row. Otherwise one edit to
  the installation-wide `giving.retention.confirmed` row in the general
  settings editor would "confirm" a figure for every organisation that had
  never opened the page, and start clearing their kept records. The one
  exception is `giving.retention.hold`: a hold on the organisation OR on the
  whole installation holds.
- **The data file.** `web/_core/data/giving-retention-countries.json`, with
  its schema beside it, checked by
  `tools/audit-checks/check_giving_retention_data.py`. To update a country,
  edit its row and `updatedOn`, run the check, commit; no code changes. An
  organisation that has confirmed keeps its own figure; one that has not
  follows the new suggestion at once, and nothing of its is cleared until it
  confirms. The PHP reader applies the schema's rules itself: a bad row
  counts as "nothing found", an unreadable file gives everybody the
  placeholder. The four research reports are in
  `docs/research/giving-retention/`.
- **The settings are saved by `GivingRetention::saveForSite()`**, not by the
  page, so the rules are tested without a browser: a value outside its list
  refuses the whole save; ticking "confirmed" writes the figure into the
  organisation's own setting; changing the figure or the country un-ticks
  it; a change that would make kept records due needs a second tick.
- **Tests.** `tools/giving-retention-selftest.php` (no database): the
  suggestions, the dates, both clock-change nights, the guard.
  `tools/gdpr-eraser-selftest.php` sections 11 to 13 (a database): linked
  giving data at two organisations, the scheduled clearing, the settings.
  `tools/erasure-pages-selftest.php` section G: the page. Set
  `SELFTEST_SNAPSHOT_FILE` on that last one to have it hold the page still
  for a browser.
- **Deploy order.** Migration 209 must run before an erasure: without it the
  giving-records step stops the run, puts the request back and says to
  upgrade. The claim file and the statements pages also read the new table.
- **Not built.** Nothing records which gifts were actually claimed, so a
  gift counts as kept when a declaration (whatever its status) or a
  statement of the donor's covers its date. A gift that names its donor only
  by a typed name cannot be found. The pull-request workflow does not run
  the new self-test or the data-file check yet (the owner has agreed to that
  edit as its own change).

### User reminders cron (#439, migration 171)

Token in `user_reminders.cron_token`, seeded empty + `isSensitive = 1` — set
a real value at `/admin/settings` before adding the crontab line, same
empty-fails-closed pattern as every other cron token in this app. Add
alongside the other cron lines — this one needs **15-minute** granularity
(unlike the daily venue/asset sweeps) because the task family compares
against a DATETIME the user picked deliberately:

```
*/15 * * * * curl -fsS "https://<your-portal-host>/cron/user-reminders?key=<your-token>" > /dev/null
```

The route (`cron/user-reminders`, migration 171) is seeded
`isProtected = 0` — public but token-gated. It loops every distinct
**active site** (not pre-filtered to "owns a candidate row" — with three
unrelated families that pre-filter would be noisier than a few cheap empty
result sets) and runs three families per site, each gated by its own
per-site flag (`tasks.reminders_enabled` / `rota.reminders_enabled` /
`milestones.digest_enabled`, all default ON, read via
`App::settingForSite()` inside the loop):

- **task-reminder** — `tblTasks.reminderDate <= NOW()`, capped to the last
  `tasks.reminder_lookback_days` (default 7) so first activation on an
  install with years of stale `reminderSent = 0` rows doesn't blast every
  overdue task in one run. Dedupe is an atomic claim on the existing
  `tblTasks.reminderSent` flag column (migration 036) — no new log table.
  Honours the new `taskReminders` notification preference (default on).
- **rota-slot** — `tblRotaSlot` rows due within
  `rota.reminder_days_before` (default 3, migration 074) with
  `reminderSentAt IS NULL`. One grouped email per assignee per run listing
  every due duty; dedupe still claims each slot individually
  (`reminderSentAt = NOW() WHERE reminderSentAt IS NULL`). Honours the new
  `rotaReminders` notification preference (default on).
- **milestone-digest** — once per day, 06:00-08:59 server time (matches
  `cron/event-reminders.php`'s own day-of window). Sends today's
  birthdays/anniversaries to the roles listed in
  `milestones.digest_recipients` (migration 076) — **an empty recipients
  CSV skips the site entirely** (explicit opt-in only; no silent fallback
  to admins for birthday data). Dedupe uses the new generic
  `tblUserReminderLog` table (`(refType, refID, dueDate)` unique key,
  check-first + race-catch on the concurrent-run case) since
  `tblUserMilestone` carries no sent-flag column of its own — the table is
  deliberately generic so a future single-shot family (e.g. DBS expiry,
  deferred) can reuse it with zero DDL.

Also repaired in the same PR: `cron/event-reminders.php` was selecting
`u.email` from `tblUsers` — the real column is `emailAddress` — so under
this app's strict mysqli reporting the very first `prepare()` threw and the
event-reminder cron 500'd on **every** invocation. Fixed to
`u.emailAddress AS email` throughout; the three other `u.email` call sites
found during this work (`calendar/event-broadcast-send.php`,
`admin/calendar/coordinators.php`, `admin/safeguarding/dbs.php`) are
tracked separately in #438, not touched here.

## Service-Plans ↔ Worship additive bridge (gap #6, #442, migration 173)

### The two-model situation

Two independent "service plan" data models exist and always have —
neither was ever aware of the other:

| | Run-sheet builder (#262/#300) | Worship presentation (#308/#355) |
|---|---|---|
| Tables | `tblServicePlan` (SINGULAR) + `tblServicePlanItem` + `tblServicePlanMessages` | `tblServicePlans` (PLURAL) + `tblServicePlanItems` + `tblServicePlanState` + `tblCcliUsage` |
| Born in | migration 089 (+110, +154) | migration 137 (+138, +139) |
| Surface | `/service-plans` (login-only, no coordinator ACL) | `/worship` (admin-or-coordinator write ACL, driven by `eventID`) |
| `eventID` | Schema-only — **no handler in `_apps/service-plans/` ever writes it** (write-dead since migration 089) | Live and ACL-bearing — `plan-save.php`'s `$gate` closure derives write access from it |

Migration 154's own header calls the two "unrelated" — that stays true of
the DATA (no merge, no rename, ever — see "Explicitly out of scope"
below). Gap #6 adds ONE optional, additive cross-reference so a worship
plan can declare which run-sheet it presents.

### The bridge column

`tblServicePlans.runSheetPlanID` INT NULL, UNIQUE (`uq_plans_runsheet`),
`FOREIGN KEY … REFERENCES tblServicePlan(planID) ON DELETE SET NULL`
(migration 173). NULL = unpaired — the state of every row that existed
before this migration; nothing is backfilled in bulk.

**Why the column lives on the worship side, not the run-sheet side:** in
`full_schema.sql`, `tblServicePlan` (the run-sheet, migration 089) is
created ~2,200 lines before `tblServicePlans` (the worship plan, migration
137) — the FK folds inline into the child's CREATE, honouring the file's
"FK ordering respected" guarantee. The reverse direction would need an
out-of-order ALTER with zero precedent (every column added to an
already-created table in this file is folded inline into that table's own
CREATE, never a standalone ALTER — see migration 110's `startedAt`/
`closedAt` on `tblServicePlan` itself, or 138's `displayToken` on
`tblServicePlans`). This also means pairing only ever mutates a
`tblServicePlans` row, so that app's stricter existing write-ACL governs
pair/unpair for free — the ACL-free run-sheet model is never written by
the bridge except the one guarded exception below.

### Source-of-truth table (no field sync in v1 — Q4, decided)

| Data | Owner | The other side shows… |
|---|---|---|
| Programme order, sections, presenters, durations, AV notes, serviceDate, print | Run-sheet (`tblServicePlan`) | a read-only summary |
| Slides, canonical songs (`songID` → `tblSongs`), lyrics, projector state, displayToken, CCLI | Worship (`tblServicePlans`) | a read-only summary |
| The pairing itself | `tblServicePlans.runSheetPlanID` — single physical record, no mirror column | resolved by reverse lookup (`ServicePlanLink::runSheetForWorshipPlan()`) |
| Event binding | Worship's `eventID` is authoritative where both are set; the run-sheet's may be backfilled from it at pair time ONLY (never the reverse) | — |

The two item representations are structurally incompatible — Model A uses
a free-text `title` per section (`sectionType='song'` + "Hymn 256 — Amazing
Grace"); Model B uses a canonical `songID` FK into `tblSongs`. Any
automatic sync needs fuzzy title↔song matching with a real false-positive
risk, so v1 ships zero sync — the counterpart panels are read-only
visibility only. A one-shot, user-triggered "copy sections → slides"
button is the deliberate v2 candidate.

### Pairing invariants (`Portal\Core\ServicePlanLink`)

All enforced in `ServicePlanLink::pair()`/`unpair()` — mechanism only, NOT
the ACL decision (that's the caller's job, see below):

1. **Same site (hard).** Every row is loaded `siteID = Site::id()`-scoped;
   a foreign-tenant planID simply resolves to "not found", never a
   mismatch that needs its own error path.
2. **Same event (hard, when knowable).** Refused only when BOTH sides
   declare an `eventID` AND they differ. Either side NULL (the run-sheet's
   is NULL on every row today) always proceeds.
3. **1:1 (hard).** A run-sheet already claimed by a DIFFERENT worship plan
   is refused with a friendly flash naming the other plan. The UNIQUE key
   is the concurrency backstop — a mysqli errno 1062 on the pairing UPDATE
   is caught and turned into the same friendly refusal, never a fatal 500.
   Re-pairing a worship plan's own existing link (or the same pair again)
   is allowed — it's an overwrite of the row already being edited (Q6,
   decided).
4. **eventID backfill (soft, one-directional, NULL-only — Q1, decided:
   yes).** At successful pair, if the run-sheet's `eventID IS NULL` and the
   worship plan's is not, the run-sheet's dormant `eventID` is set from
   it. **Never the reverse** — writing the worship side's `eventID` would
   silently grant that event's coordinator write access to the plan
   (`plan-save.php`'s `$gate` closure derives from it); that column is
   ONLY ever set through the worship app's own explicit, authorised
   `save-metadata` flow.
5. **Unpair never touches the run-sheet row** — a prior eventID backfill
   stays; it's true information about the run-sheet regardless of whether
   the pairing that revealed it still exists.

### ACL — who may pair/unpair

Pairing mutates a `tblServicePlans` row, so `worship/plan-link.php` reuses
that app's existing write gate **verbatim** (copied, not refactored out of
`plan-save.php`): admin, OR `Auth::isCoordinatorOf($plan['eventID'])` when
the plan is event-bound; free-floating template plans are admin-only. CSRF
(`Auth::verifyCsrf`) + POST-only, matching `plan-save.php`'s own pattern.
UI-level control visibility is cosmetic only:

- **Worship editor panel** — controls show when the page's own `$canWrite`
  is true (same variable the rest of `plan.php` already gates on).
- **Run-sheet editor panel** — controls show for `App::isAdmin()` only
  (Q2, decided). The run-sheet app has no coordinator concept to check
  per-candidate without an extra query per row in the picker list; v1
  keeps that side admin-only. Non-admins still SEE the read-only summary
  when a pairing exists.

The handler re-checks the real gate regardless of which page's UI a POST
came from — a forged request bypassing the picker's visibility rule still
hits the same 403.

### Resilience — the venue-overlay precedent, again

Both counterpart panels (`service-plans/edit.php`, `worship/plan.php`)
follow the exact `calendar/index.php` venue-overlay pattern: guard the
whole resolve behind `AppRegistry::isEnabled('<counterpart-slug>')`, then
wrap the `ServicePlanLink` call in `try { … } catch (\Throwable $e) {
error_log(...); $link = null; }`. This means a disabled counterpart app, a
deploy where the migration hasn't landed yet (mysqli throws "unknown
column" under this repo's strict reporting), or any other resolver
exception leaves the panel silently empty — the page never breaks, and
neither existing surface's own queries changed at all.

### Explicitly out of scope (v2+ candidates)

- Paired badges on the two list pages (`service-plans/index.php`,
  `worship/plans.php`) — Q3, deferred; a 10-line follow-up (one extra LEFT
  JOIN each).
- Any field sync, in either direction — Q4, deferred; see the
  source-of-truth table above.
- Confidence-monitor (`live.php`/`confidence.php`) surfacing the worship
  operator's current slide via the paired plan's `tblServicePlanState`.
- Any rename or merge of the two confusingly-similar table names — never,
  or only in a major version with a compatibility view. Permanently out of
  scope for this gap item; the additive bridge is the whole point.

---

## Workflow Execution Engine + Generic Approvals Inbox (#443)

Migration 034 shipped four tables (`tblWorkflows`, `tblWorkflowSteps`,
`tblWorkflowInstances`, `tblWorkflowActions`) and an admin definition CRUD at
`/admin/workflows`, but nothing anywhere ever started, advanced, completed,
or timed out an instance. Migration 174 adds four additive
`tblWorkflowInstances` columns (`subjectLabel`, `contextJson`,
`currentStepStartedAt`, `outcome`) + one composite index
(`idx_wfi_site_status`) + one `tblWorkflowActions.action` enum value
(`'commented'`) — no redesign, no new tables — and this PR builds the engine
on top: `Portal\Core\Workflow` (`web/_core/Workflow.php`), a generic
`/approvals` inbox app, a token-gated hourly timeout cron, and exactly ONE
wired reference consumer (announcement publish approval).

### The state machine

A *definition* (`tblWorkflows` row + ordered `tblWorkflowSteps`) instantiates
into a `tblWorkflowInstances` row via `Workflow::start($definitionKey,
$subjectTable, $subjectId, $startedById, $subjectLabel, $context)`, which
walks `currentStep` (a **stepOrder** value, not a stepID) through the step
sequence via `Workflow::act($instanceId, $stepId, $actorId, $decision,
$comment)`, records one `tblWorkflowActions` row per decision, and
terminates with `status='completed'|'cancelled'` + the new `outcome` column
(`'approved'|'rejected'|'cancelled'`). `Workflow::cancelForSubject(...)`
closes an active instance out-of-band (e.g. the subject record was
deleted). `Workflow::timeoutSweep($siteId)` is the per-site timeout pass the
cron calls.

**Atomicity** — every mutator opens `begin_transaction()`, `SELECT … FOR
UPDATE`-locks the instance row (site-scoped — the IDOR wall: a foreign
instanceID is deliberately indistinguishable from a missing one, both
return `'not_found'`), resolves the CURRENT step fresh
(`ORDER BY stepOrder ASC, stepID ASC LIMIT 1` — `tblWorkflowSteps` has no
unique key on `(workflowID, stepOrder)`, so this ordering is how the engine
deterministically handles the double-submit-race duplicate-order edge
everywhere), and claims the transition with a single `UPDATE …
WHERE instanceID=? AND currentStep=? AND status IN ('pending','in_progress')`
gated on `affected_rows === 1` — the exact discipline
`expenses/approve/save.php`'s row-lock + reconfirm and
`Payments::markPaymentSucceeded`'s guarded UPDATE already use. A losing
racer gets `'conflict'` and does nothing. `act()`'s posted `$stepId` must
equal the server-resolved current step — a mismatch is `'stale_step'`
(the form was rendered before someone else advanced the instance).
`start()`'s duplicate-active guard is a single conditional
`INSERT … SELECT … FROM DUAL WHERE NOT EXISTS (...)` gated the same way.

**A `'rejected'`/`'cancelled'` decision always terminates the instance,
never advances it** — `claimTransition()`'s next-step lookup only ever runs
for an `'approved'` outcome; any other terminal outcome skips it entirely
and completes the instance at the CURRENT step, regardless of whether a
later step exists. This closes a security-review finding (SEC-01): the
advance-vs-complete branch used to key off "does a next step exist" alone,
so a reject at step 1 of a multi-step definition would advance to step 2
and let a later approve publish the very thing that was just rejected. The
same gate covers a human reject in `act()`, a timeout auto-reject in
`timeoutAutoAct()`, and an `'auto'`-type step that rejects in
`processAutoSteps()` — all three pass `$actionWord === $terminalOutcome`
into `claimTransition()`.

`start()`'s two "didn't start" outcomes are distinguishable via its
`reason` return value — `'no_definition'` (nothing configured; the only
case a caller should fail open) vs `'duplicate_active'`/`'error'` (a
concurrent submit already has an instance running, or an unexpected
failure — treat exactly like an already-pre-checked "awaiting approval",
never fail open). See "Announcements wiring" below for why this mattered
(SEC-03).

**Authorisation lives INSIDE `act()`**, never trusted from the HTTP layer:
the actor must (a) be an active `tblUserSites` member of the instance's own
site, AND (b) either match the current step's assignee (role/user/group per
`tblWorkflowSteps.assigneeType`/`assigneeValue`) OR — when
`workflows.admin_override` is `'1'` (default on) — be a site admin for that
site (the same 4-tier test as `App::isAdmin()`, evaluated explicitly for
the *acting* user + the *instance's* site rather than the session/`Site::
id()` pair `App::isAdmin()` itself checks).

**Subject-adapter registry** — `Workflow::applySubjectEffect()` is a single
`match` on `workflowKey`, run **inside the same transaction** as the final
approval's atomic claim. v1 ships one arm: `'announcement_publish'` flips
`tblAnnouncements.isPublished = 1` on outcome `'approved'` — so approval and
publish commit or fail together; there is no "approved but never
published" ghost, and no double-publish (the UPDATE is idempotent — a
re-run only matches 0 rows if the announcement was since soft-deleted, in
which case a `Logger::errorPlatform` Warning is logged but the workflow
still completes truthfully). Adding a second wired consumer later is one
new `match` arm here + one `Workflow::start()` call at that consumer's
submit point + one enable flag — no other engine change.

**Timeout policy — escalate-only unless a step opts in.** `timeoutSweep()`
NEVER auto-approves/auto-rejects on a bare timeout. Only a step whose
`autoAction` is explicitly `'approve'` or `'reject'` gets auto-decided
(`actedByID = NULL`, comment `"Auto-<word> after Nh timeout"`); a `NULL` or
`'escalate'` `autoAction` only escalates — one `'escalated'` action row per
`(instanceID, stepID)` (deduped, locked so two overlapping sweep runs can't
double-escalate) plus an email to the site's admins and the step's own
assignees, with the instance left active awaiting a human. The seeded
`announcement_publish` step ships `timeoutHours=72, autoAction=NULL`
(escalate after 72h, never auto-decide).

### Nobody decides or pays their own expense claim (#545, 11 October 2026)

`Portal\Core\ExpenseRules` holds the rule once: `isOwnClaim()`, the two
refusal sentences, and `requiredApprovers()` (the query that used to sit in
`expenses/approve/save.php`, with the claimant left out). Both save handlers
ask `isOwnClaim()` straight after the claim is found and before anything is
written; administrators are refused too. Leaving the claimant off the
required list is what stops a lead's own claim waiting for ever for an
approval that is now refused: when nobody else is a lead or required
approver, one approval from anybody else with authority is enough.

The two lists (`approve/index.php`, `treasury/index.php`), the claim page's
two buttons, the "submitted" e-mail and the administrator's dashboard number
all leave a person's own claims out, so nothing offers a button that cannot
work. Unchanged on purpose: an administrator's approval never stands in for a
required approver's; a retired department's approvers still finish its
pending claims; somebody with no authority at all is refused before any claim
is looked up, with the same sentence as before.

`tools/expense-rules-selftest.php` (needs a throwaway database) checks the
rule against real rows and reads the seven pages to make sure they still call
it, requiring the own-claim test as one exact statement (so "unless an
administrator" written in front of it fails). `tools/access-pages-selftest.php`
proves it through the real handlers: a lead, a treasurer and an administrator
each try to approve, reject or pay their own claim, and nothing is recorded.
Neither is part of the pull-request checks yet; run them by hand.

The refusal says who DOES decide: "the other leads and required approvers of
its department or, when there are none, another approver or an administrator".
The first wording ("Another approver or an administrator must decide it") was
untrue where another required approver exists, because an administrator's
approval alone leaves such a claim waiting. A claimant who holds approval
authority is told the same on the claim's own page.

#### Withdrawing a claim (#587, migration 211)

`211_expense_claim_withdrawn.sql` adds 'Withdrawn' to the end of the choices of
`tblExpenseClaims.status` and turns an empty status into 'Withdrawn';
`full_schema.sql` has the same list. `tools/expense-withdraw-selftest.php`
(37 checks) needs a throwaway database whose name starts with `selftest_`, and
**it changes the shape of `tblExpenseClaims` while it runs** (it takes
'Withdrawn' off the list to stand for an older database, then lets the
migration put it back), so nothing else may use that database at the same
time. `tools/access-pages-selftest.php` now has 278 checks (part M presses the
real button and reads the stored status back).

Three things worth keeping, because each is the shape of a fault found here:

- **A column that is a fixed list of choices stores an EMPTY value, and reports
  success, when it is given a choice it does not know and the server is not in
  strict mode.** The portal now asks every connection for strict mode (#593),
  so through the portal this is an error; a connection opened any other way
  still depends on the server. A new choice goes on the END of the list (the server stores a
  choice as its position), in a numbered migration and in `full_schema.sql`,
  and every place that lists the choices has to learn it: for claims that is
  `expenses/api/list.php` and `ReportRegistry.php`.
- **Anything a save page does after its commit belongs outside the block that
  rolls back.** Inside it, a failed e-mail showed "try again" about work that
  was already stored. `expenses/approve/save.php`, `expenses/treasury/save.php`
  and `expenses/submit/save.php` still have that shape (read, not run) and were
  not changed here.
- **A page shows the one-off message another page left only if it reads
  `$_SESSION['flash_msg']` itself.** There is no shared place that does it. In
  Expenses, the approvals, treasury, claim and submit pages now do.

### Why the seeded `expense_approval` workflow stays dormant

Migration 034 seeded ONE `tblWorkflows` row (`expense_approval`, zero
steps) that nothing has ever called `start()` on. It stays exactly that way
— Expenses already has its own complete, independent, department-scoped
multi-approver system (`tblExpenseClaimApprovals`,
`expenses/approve/save.php`'s own `FOR UPDATE` + mandatory-approver logic).
Mirroring or driving Expenses through the generic engine would double-write
approval state for zero benefit and risk destabilising a money path with
stronger semantics than the generic step schema expresses. The admin
`/admin/workflows` UI carries an info box saying so.

### `/approvals` — the generic inbox

New infrastructure app (`web/_apps/approvals/`, AppRegistry entry
`web/_core/apps/approvals.php`, default-ON `approvals.enabled` — the inbox
itself is free to expose; the *consumer* flags that feed it stay
default-off per-consumer). Three sections, no `<table>`
(`portal-data-list` throughout):

1. **Awaiting your decision** — `Workflow::actionableForUser()`. Admins see
   every active instance for the site (with a Mine/All toggle); everyone
   else only ever sees rows they're actually eligible to act on. This is
   **display-only filtering** — `Workflow::act()` re-checks authorisation
   independently on every POST, so a stale/forged row in the DOM can never
   grant an action the engine wouldn't otherwise allow.
2. **Action controls** — one CSRF'd POST form per row to `/approvals/act`
   carrying hidden `instanceID` + `stepID` (the stale-form guard token),
   Approve/Reject/Comment-only buttons. `approvals/act.php` is transport
   only — CSRF check, input whitelist, call `Workflow::act()`, translate
   the result to a flash message. No authorisation logic in the handler.
3. **History** — last 50 completed/cancelled instances (non-admins: only
   ones they started or acted on), each row expandable (Bootstrap collapse)
   to the full `Workflow::actionsForInstance()` timeline.

### Timeout cron

`cron/workflow-timeouts.php` (hourly external cadence — `timeoutHours`
granularity is hours) — token-gated exactly like `cron/user-reminders.php`:
`?key=` vs `workflows.cron_token`, `hash_equals()`, **empty stored token
always 403s** (seeded empty + `isSensitive=1`). Global kill-switch
`workflows.enabled` read once before the per-site loop (genuinely global,
like the token itself); everything else is delegated to
`Workflow::timeoutSweep($siteId)` inside the standard
`Site::forceContext($siteId)` try/catch-continue loop.

### Announcements wiring (the reference consumer)

Per-site flag `workflows.announcements.enabled` (default `'false'`).
**Flag off ⇒ not one line of today's behaviour changes** for any of the
three write paths below. Flag on:

- **Create** (`announcements/save.php` AND `announcements/api/create.php`):
  posting `isPublished=1` (the API's own default) withholds the flag
  (`isPublished` forced to 0 before the INSERT) and calls
  `Workflow::start(...)` instead.
- **Update** (`announcements/save.php` AND `announcements/api/update.php`):
  only a genuine **publish request** (posted `isPublished=1` AND the stored
  row is currently `isPublished=0`) triggers the same withhold-and-start.
  Editing an already-published row, or unchecking Published (retract),
  passes through completely unchanged — no approval needed to edit or
  unpublish in v1.
- **The REST write API is gated identically to the HTML form** (closes
  SEC-02): `api/create.php` and `api/update.php` used to write
  `isPublished` straight to the database with no workflow gate at all,
  regardless of this setting — a caller with `announcements:write` scope
  could publish past a running approval process entirely via the API. Both
  handlers now `require_once` the same `announcements/_workflow-gate.php`
  helper `save.php` uses, so all three write paths share ONE implementation
  of the gate rather than three that could drift out of sync again. The API
  handlers read the flag via `App::settingForSite()` rather than
  `Settings::get()`'s ambient snapshot, since a bearer-key request's
  tenant site can differ from the host-detected site the snapshot was
  frozen for.
- **Fail-open** (deliberate, not a security boundary): if
  `Workflow::start()` reports `reason === 'no_definition'` (nothing
  active/steppable configured for the site), the gate helper publishes
  directly anyway and logs a `Logger::errorPlatform` Warning
  (`WF_MISCONFIG`) so admins see the misconfiguration in `/admin/errors` —
  a half-configured gate must never silently block every publish on a
  site. Any OTHER reason an instance didn't start — an instance already
  active for this subject (checked up front via
  `Workflow::activeInstanceForSubject()`, and again via `start()`'s own
  `'duplicate_active'` result if a concurrent submit won that race in
  between), or `'error'` — gets the same "already awaiting approval"
  response and NEVER publishes directly. This distinction (SEC-03) matters
  precisely because of that race: two near-simultaneous submits could both
  pass the up-front check, one wins `start()`'s atomic insert and a real
  approval begins, and the loser used to be indistinguishable from "gate
  misconfigured" — so it fell through the fail-open branch and published
  the subject anyway, right past the instance its twin had just started.
- `announcements/delete.php` calls `Workflow::cancelForSubject(...)` after
  the soft-delete so a deleted announcement never lingers in anyone's
  inbox.
- `announcements/manage.php` relabels the Published checkbox to "Publish
  (requires approval)" and shows an "Awaiting approval" badge (linking to
  `/approvals`) on any unpublished row with a running instance.

### Admin CRUD completion

`/admin/workflows` gained the three pieces migration 034's CRUD was always
missing: per-step **Delete** (new `admin/workflows/step-delete.php` —
site-ownership-checked, refuses while the workflow has any active
instance, and catches the `tblWorkflowActions.stepID` RESTRICT FK as a
friendly "this step has recorded history" refusal rather than a 500), an
**isActive** toggle on the workflow header form, and an **autoAction**
select on the Add Step row (feeds the timeout policy above). Step
*reorder* is deliberately out of scope — delete + re-add reaches the same
place, since steps are ordered by `stepOrder` and the existing add-step
logic already appends at `MAX(stepOrder)+1`.

### Extending the engine — adding consumer #2

1. Seed a new `tblWorkflows` definition (+ steps) for the target
   `workflowKey`, gated behind your own per-site enable flag.
2. At your consumer's submit point: check the flag, and when on, call
   `Workflow::start($yourKey, $yourTable, $yourId, $userId, $label,
   ['url' => $viewUrl])` instead of writing your "approved" state directly.
   It returns `['instanceId' => ?int, 'reason' => ?string]` — fail OPEN
   (act directly) only when `reason === 'no_definition'`; treat
   `'duplicate_active'` and `'error'` exactly like your own pre-checked
   "already awaiting approval" case (fail CLOSED — never act directly). Do
   the same up-front `Workflow::activeInstanceForSubject()` check
   `announcements/_workflow-gate.php` does before calling `start()` at all —
   see SEC-03 above for why both matter.
3. Add one `case '$yourKey':` arm inside
   `Workflow::applySubjectEffect()` for the `'approved'`/`'rejected'`/
   `'cancelled'` side effects. That's the entire integration surface — no
   other engine change.

`'review'` step type behaves identically to `'approval'` in v1 (both block
awaiting a human); the enum distinction is reserved for a later phase. The
`delegate` decision verb is deferred to v2 (needs target-picking UI + its
own authorisation surface) — v1 ships approve/reject/comment only.

---

## Web Push setup (#322)

`Portal\Core\WebPush` (`web/_core/WebPush.php`) sends VAPID (RFC 8292)
signed, RFC 8291 aes128gcm-encrypted browser push notifications for "we're
live now" and upcoming-service reminders. Migration 111 seeded the
`push.*` settings keys empty; migration 177 seeded everything else
(TTLs, auto-notify toggles, the SSRF host allowlist, the two
`api.push.*.enabled` ApiRouter flags, four route rows) — **no key
material**. The feature is **INERT** on every fresh install until an
owner completes the three steps below; `WebPush::isConfigured()` gates
every send path, the client subscribe UI, and both cron jobs, so an
unconfigured install's cron runs are cheap, harmless no-ops.

### 1. Generate or import a VAPID key pair

At `/admin/integrations/push`, either:

- Click **"Generate new key pair"** — one click, no OpenSSL needed. The
  private key is sodium-encrypted at rest via the house
  `encrypt_setting()` helper and is **never** re-displayed once saved
  (the page shows only a "configured ✔" badge and an 8-hex fingerprint of
  the *public* key — the private key precedent set by
  `admin/integrations/cloudflare-stream`'s signing key).
- Or generate a key pair yourself and paste it into the **"Import"** box.
  Either a full PEM:

  ```sh
  openssl ecparam -name prime256v1 -genkey -noout -out vapid.pem
  cat vapid.pem   # paste the WHOLE file contents into the import box
  ```

  or the bare base64url 32-byte private scalar some tools (e.g. the
  `web-push` npm CLI's `generate-vapid-keys`) export standalone —
  `WebPush::importPrivateKey()` accepts either shape and derives the
  matching public key itself (validated in `tools/webpush-selftest.php`:
  ext-openssl reconstructs the public point from the private scalar alone
  at parse time, so no explicit public-key DER field is needed).

Then set a **contact** (`mailto:` or `https:` — RFC 8292 §2.1 requires
one; a push service uses it to reach you if this install starts
misbehaving) and tick **"Enable Web Push"**.

**Regenerating breaks existing subscriptions.** A real push service
validates that the sender's VAPID public key matches the one the
browser's `pushManager.subscribe()` call used — clicking "Generate" again
is a fresh start, not a rotation. Existing subscribers silently stop
receiving pushes until they revisit the portal and re-subscribe (the
`/account/notifications` and `/live` opt-in widgets re-run
`getSubscription()` on every page load, so this self-heals the next time
each subscriber's browser is online — there's no need to chase anyone
down).

### 2. Point the crontabs at the two push endpoints

| Endpoint | Cadence | Token setting |
| --- | --- | --- |
| `cron/push-golive?key=<token>` | every 5 minutes | `push.cron_token` |
| `cron/event-reminders?key=<token>` | every 15 minutes (already required for #329; Web Push now rides its 1h window) | `reminders.cron_token` |

Both tokens seed empty + `isSensitive=1` — set a real value at
`/admin/settings` (or paste one at `/admin/integrations/push` if a future
build adds that field) or the endpoint 403s forever (empty-fails-closed,
the six-of-six `cron/*` convention). `push.cron_token` is DEDICATED —
don't reuse `reminders.cron_token` — so the two endpoints can be rotated
independently.

`cron/push-golive.php` only does anything when its own settings are ALSO
turned on (both default OFF): `push.golive.auto` (auto-detect a schedule
window opening, deduped once per channel per day via
`tblUserReminderLog`) and `push.reminders.broadcast` (a "starting
soon" broadcast to every signed-in person's device on the `reminders` channel when the next scheduled
stream is within 60 minutes). The PRIMARY go-live trigger doesn't need
this cron at all — it's the manual **"Send 'We're live' notification"**
button on `/admin/livestream` and the Host Console, which fires
synchronously the moment an admin clicks it.

A channel with two separate live windows in one day needs the manual
button for the second window — the auto-detect dedupe key is
`(refType='push-golive', refID=channelID, dueDate=today)`, once per
CHANNEL per DAY by design (issue #322's decided default; revisit only if
a real site genuinely runs two auto-notified streams in a single day).

### 3. Verify it end-to-end

1. `/admin/integrations/push` → the banner should flip from amber
   ("Inert") to green ("Configured and active") once steps 1-2 are done.
2. `/account/notifications` (or `/live`) in Chrome/Firefox → the "Push
   notifications" card renders a working "Enable notifications on this
   device" button (it stays hidden entirely, with no button at all, while
   `WebPush::publicKey()` returns `''`) → click it, grant the browser
   permission prompt → a row appears in `tblPushSubscriptions` with the
   correct `siteID`/`userID`/`channels`.
3. `/admin/integrations/push` → **"Send test notification to my
   devices"** → a notification should arrive on that device within a few
   seconds; clicking it focuses an existing portal tab (or opens one) at
   the notification's target URL.
4. `/admin/livestream` with a channel currently inside its scheduled
   window → **"Send 'We're live' notification"** → the button is disabled
   with a tooltip explaining why whenever Web Push is unconfigured OR no
   channel is currently live.
5. Prune check: subscribe, then revoke notification permission for the
   site in the browser's own site settings, then send another test/live
   push → the push service returns 404/410 → the subscription row flips
   `isActive=0` + `lastHttpStatus` records the code → the admin page's
   subscription counts update on next load.

### Troubleshooting

**"401" / "403" from the push service, every single send** — almost
always one of two causes, both guarded by `tools/webpush-selftest.php`
(run it — `php tools/webpush-selftest.php` — before chasing anything
else):

- **Clock skew.** The VAPID JWT's `exp` claim is `now + 12h`; if the
  server's clock is badly wrong, every JWT looks expired (or not-yet-valid)
  to the push service. Check `date` on the server.
- **A broken DER→JOSE conversion.** `openssl_sign()` returns a DER
  `ECDSA-Sig-Value`; RFC 7518 §3.4 requires the JWS signature to be the
  RAW 64-byte `R‖S` concatenation instead. Shipping the DER bytes
  unconverted is THE classic implementer mistake for this exact protocol
  — every push service just returns 401/403 with zero diagnostic detail,
  because from its side the signature simply doesn't verify. If
  `tools/webpush-selftest.php` still passes after a refactor, this class
  of bug is ruled out; if it starts failing, this is almost certainly why.

**Nothing happens when I click "Enable notifications" on `/account/notifications`**
— open devtools → Application → Service Workers and confirm `/sw.js` is
registered and activated (footer.php registers it on every page load);
`push-subscribe.js` requires an active service worker registration before
it can call `pushManager.subscribe()`. Also check `Notification.permission`
isn't already `'denied'` from an earlier browser-level block — the widget
shows "Blocked in browser settings" in that case rather than a button.

**A legitimate push endpoint gets rejected at subscribe time** — the SSRF
host allowlist (`push.endpointHostAllowlist`, admin-editable at
`/admin/integrations/push`) is a suffix match against the endpoint's
host. A new browser vendor's push service host is not a code change —
just add it to the allowlist.
## Hymnal lookup + public Order of Service (gap #128 residual, migration 178)

### Re-scope verdict

#128 ("Order of Service planner with iHymns integration") was filed
2026-05-22, before service-plans (#262/#300) or the Worship Presentation
Engine (#308/#355) existed. A 2026-08-28 architecture pass found both apps
already cover ~90% of the issue's ask (item CRUD, reorder, presenters,
durations, notes, templates, event linking, song library + CCLI log). This
migration ships ONLY the residual — a local hymnal index, an optional
default-off remote lookup, and a congregation-facing public view — all
additive on the EXISTING `tblServicePlan`/`tblServicePlanItem`/`tblSongs`
tables. **No new app, no third service-plan data model.**

### Hymnal CSV import format

`Portal\Core\Hymnal::importCsv()` (used by `/admin/hymns`'s per-hymnal
import form) expects a header row with these column names, in ANY order
(case-insensitive, matched by name — not by position):

```
number,title,firstLine,author,tuneName,meter,ccliNumber,copyrightLine
```

Only `number` and `title` are required — every other column may be
omitted entirely or left blank per-row. `number` supports non-numeric
suffixes (`"256a"`) — a leading numeric prefix is extracted into
`numberSort` for ordering; a fully non-numeric number sorts as 0 (first).
Upsert is keyed on `(hymnalID, number)` — **re-importing the same file (or
an updated version of it) is idempotent**, never creating duplicates.
Size-capped at `Hymnal::CSV_MAX_BYTES` (256 KB) / `Hymnal::CSV_MAX_ROWS`
(5000 rows); a row missing `number` or `title` is skipped with a reported
error rather than aborting the whole import. **Metadata only — the
importer has no lyrics column and never will**: hymn lyrics carry
copyright risk and stay hand-entered into `tblSongs.lyrics` under the
church's own CCLI licence, exactly as before #128.

### The iHymns Tier-2 contract (generic, provider-agnostic)

No public API for the issue's linked `ihymns.co.uk` could be verified
during planning (DNS/proxy blocked from the build sandbox; no public
documentation found). Repo docs (`deploy.yml`, this file's own SFTP
pipeline notes) suggest iHymns is an in-house MWBM/MWservices sibling
deployment, not a third-party integration — so rather than block on an
unverifiable API, `Portal\Core\Hymnal::searchRemote()` implements a
**generic** HTTPS JSON client any owner-controlled endpoint can speak:

```
GET {baseUrl}/search?q={query}&limit={n}
-> 200 {"results":[{"id":"…","hymnal":"CH","number":"256","title":"…",
                     "firstLine":"…","author":"…","tune":"…","meter":"…",
                     "url":"…"}]}
```

- `id` or `url` becomes `sourceRef` on a promoted `tblSongs` row (so a
  remote hit needs no re-fetch once picked).
- Only `title` is strictly required in a result; every other field is
  optional and simply omitted/null when absent.
- The client sends `Authorization: Bearer {apiKey}` when a key is
  configured — no other auth scheme is supported in v1.

**Ship decision:** default OFF (`hymns.remote.enabled = 'false'`) until an
owner supplies a `baseUrl` + `host` and flips the flag — this is how the
issue's "written permission from iHymns" acceptance criterion is honoured
(record the confirmation, or "it's ours, self-granted" if owner-controlled,
on #128 when flipping). Tier 0 (free-text) and Tier 1 (local index) work
fully with zero configuration; Tier 2 is a pure enhancement layered on top
that can be turned on/off at any time with zero code change.

**SSRF hardening (mandatory, not optional even though the feature is
default-off and admin-configured):**
- https-only, re-checked at BOTH settings-save (`Hymnal::
  validateRemoteConfig()`, called from `admin/hymns-save.php`) AND
  request time (`Hymnal::searchRemote()`/`testRemoteConnection()`) —
  belt-and-braces against a config row being edited directly or a future
  code path bypassing the save-time check.
- Single-host allowlist — `hymns.remote.host` must match the configured
  `baseUrl`'s host EXACTLY (case-insensitive `strcasecmp`). No user- or
  operator-supplied URL is ever fetched at query time; the ONLY thing a
  search can hit is what an admin configured and saved.
- IP-literal AND DNS-resolved private/reserved-range refusal
  (`Hymnal::isPrivateOrReservedHost()` — `FILTER_VALIDATE_IP` with
  `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`, plus a
  `dns_get_record()` A/AAAA resolve-and-check when the host isn't already
  an IP literal). **Documented residual:** DNS rebinding between this
  check and curl's own connect is not fully mitigable on DreamHost shared
  hosting (no pinned resolver) — accepted because the feature is
  default-off, single-host, owner-configured, and GET-only.
- `CURLOPT_FOLLOWLOCATION = false` — a 3xx from the provider is never
  followed (a compromised/misconfigured provider can't redirect the
  server anywhere else).
- `CURLOPT_PROTOCOLS` / `CURLOPT_REDIR_PROTOCOLS` locked to
  `CURLPROTO_HTTPS` — belt-and-braces alongside the `parse_url()` scheme
  check.
- `CURLOPT_CONNECTTIMEOUT = 3`, `CURLOPT_TIMEOUT = 5`.
- Response body capped at ~512 KB via a `CURLOPT_WRITEFUNCTION` callback
  that returns a short byte count once the cap is exceeded — curl treats
  that as a transfer error and aborts, so an oversized/streaming response
  can never be buffered in full.
- JSON-only parse (`json_decode` with a depth limit) — any shape surprise
  (non-array, missing `results`, wrong types) is treated as "no results",
  never a fatal error.
- 24h server-side cache (`tblHymnLookupCache`, `hymns.remote.cacheTtl`
  default `86400`) keyed on `(siteID, SHA-256(query))` — a repeat search
  never re-hits the remote host while cached, bounding worst-case outbound
  call volume regardless of how often the picker is used.
- **Never logged:** the API key is sent ONLY as a request header, stored
  encrypted at rest (`isSensitive=1`, migration 178), and every
  `Logger::errorPlatform()` call in `Hymnal.php` carries a generic message
  (status code / curl errno only) — never the query string, the full URL,
  or any header value.
- Every failure mode (disabled, misconfigured, unreachable, timeout,
  oversized, malformed JSON) degrades to `[]` and NEVER throws — the local
  index's results always render regardless of remote provider health.

### Public Order-of-Service share flow

Mirrors the Asset Tracker's `/a/{token}` public-page pattern (#393/#395)
almost exactly — same Router special-route shape, same uniform-404
philosophy, same "no oracle" security reasoning:

1. **Token.** `tblServicePlan.publicToken` — `CHAR(32)`,
   `bin2hex(random_bytes(16))` (`Hymnal::generateShareToken()`), UNIQUE.
   NULL on every plan until first enabled.
2. **Enable/disable/rotate — `service-plans/share.php`.** CSRF'd POST,
   session-gated (any logged-in user who can reach the plan's edit page —
   same write ACL as everything else on `edit.php`; the plan's own
   separate "tighten the write ACL" item, gap #128 OQ6, is intentionally
   out of scope here). `enable` refuses up front (flash message, no state
   change) unless the SITE-level `service_plans.public_share.enabled` flag
   is `'true'` — enabling a plan's own sharing while the site has never
   opted in would otherwise look like it worked but silently 404 forever,
   which is a worse UX than an immediate clear refusal. `rotate` mints a
   fresh token, immediately invalidating any previously shared link/QR
   code (no state at the old token to "expire" — it's just no longer the
   plan's `publicToken`, so the very next lookup 404s uniformly).
3. **The public page — `service-plans/public.php`, reached via
   `Router`'s `os/{token}` special-route block (cloned from `a/{token}`)
   — NOT a tblRoutes row**, so it works even mid-migration/if tblRoutes
   is unreachable, same rationale as every other Router special route on
   that file.
4. **Uniform 404, five gates, ALL must pass:** (1) token matches a real
   `publicToken`, (2) `isPublicShared = 1`, (3) `status = 'published'`
   (never draft/archived), (4) the PLAN'S OWN SITE has
   `service_plans.public_share.enabled = 'true'`, (5) `service_plans.
   enabled = '1'` for that site. Any single failure → the exact same
   `Router::renderError(404)` as an unknown token — an attacker probing
   32-hex tokens can never learn "wrong token" apart from "right token,
   sharing off" from the response alone.
5. **Tenant safety — the one thing this file must never get wrong.**
   `public.php` is reached with NO active-site context: it's public,
   unauthenticated, and the request's Host header may not even belong to
   the token's own site on a multi-site install. So EVERY setting lookup
   after the token resolves uses `App::settingForSite('…',
   $plan['siteID'])` — **never** `Site::id()` or the bootstrap `$SETTINGS`
   snapshot (which reflects the *request's* host-detected site, not
   necessarily the *token's* site) — the exact same reasoning
   `ApiRouter::resolveEnabledFlag()` already documents for a bearer
   request pinned to a specific tenant.
6. **Content model — congregation fields ONLY (decided defaults).**
   Order/position, title, and **presenter name** (decided: yes — standard
   on a printed order of service) render; internal `notes` (AV/tech cues)
   are NEVER selected by the item query, let alone rendered — there is no
   flag to accidentally flip, the column simply isn't in the `SELECT`.
   The internal `sectionType` label ("Sermon", "Offering", …) is ALSO
   deliberately omitted from both the public view and `print.php`'s new
   `congregation` variant — a real printed bulletin doesn't tag every line
   with its internal category, just number + title + presenter.
7. **`print.php`'s `?version=leader|congregation`** reuses the exact same
   `isCongregation` gate to suppress `notes` + the `sectionType` label;
   `version` absent or anything other than `congregation` falls back to
   `leader`, so every existing bookmark/link renders byte-identically to
   before #128.

---

## Several organisations on one installation, and the members-only gate (#502, #511)

- **Which organisation is decided in ONE place**: `Site::preDetect()`. In session mode
  it takes a read-only look at the session (`Auth::peekSession()`) and closes it again,
  so the rest of start-up runs with no session active, as in the other two ways. One
  side effect: in session mode every signed-in answer carries a `Set-Cookie` for the
  session the browser already holds. It is harmless (same identifier, no lifetime).
- **Whether the request may go on is decided in ONE place**: `SiteGate::enforce()`,
  called by `web/public_html/index.php` just before `Router::dispatch()`. A new front
  controller must call it too, or it has neither the "could not work out the
  organisation" stop nor the members-only gate.
- **`web/public_html/error.php` cannot call `enforce()`** (it has to keep the web
  server's status code), so it asks `SiteGate::wouldStop()` and shows its plain page on
  a yes. `wouldStop()` repeats the gate's rules without the passes for particular
  addresses. A rule added to `enforce()` belongs in `wouldStop()` too; checks E01 to E04
  of the self-test hold the two together. `/api-docs/index.php` asks the gate nothing.
- **"Your organisations" is ONE list**: `Site::organisationsFor()`. The chooser, the
  switcher, the switch handler and the gate all use it. It uses only columns that have
  existed since migration 015; keep it that way, because it runs before the upgrade page.
- **`Site::FIRST_PART_MODE_READY`** is the one line the link-sweep package switches on.
- **Building an address, by where it is printed** (#536):
  - in HTML: `htmlspecialchars(Site::url('page/name'), ENT_QUOTES, 'UTF-8')`;
  - in a redirect: `header('Location: ' . Site::url('page/name') . '?id=' . $id)`: only the page's name goes in
    the helper, the query stays outside;
  - in a script printed by a PHP page: `fetch('<?php echo Router::urlForScript('page/name'); ?>')`. Never
    `htmlspecialchars()` there: a browser does not undo HTML escapes inside a script. Not between backticks.
    Where the script is itself written inside a PHP string, the tag is printed as text and does nothing: close
    the string and join the helper on, `' . Router::urlForScript('page/name') . '`;
  - in a script FILE: `Portal.url('page/name')`, which reads `<meta name="portal-base">` from the page header.
    A page that prints its own `<head>` must print that tag itself if it loads such a file. The check reports a
    quoted address handed to any function in a script file, whatever the function is called.
- **An address the web server answers itself takes no organisation's part**: a real file or folder in the web
  folder (`/assets/css/…`, `/sw.js`, `/offline/`, `/api-docs`) and `/robots.txt`, `/sitemap.xml`, `/favicon.ico`.
  `/assets`, `/assets/my` and `/assets/item` are the Asset Tracker's PAGES and do take it. The check and the
  self-test ask the same function (`--answers`), so they cannot disagree.
- **A note beside a line of a template must be a PHP comment**, not an HTML or script comment: the second kind is
  sent to every browser, and the page is then no longer byte for byte what it was with no first parts in use.
  **Never make it the last thing on a line:** PHP takes away a line break that comes straight after `?>`. For
  `address-check: allow`, put it straight after the address's closing quote
  (`href="/manifest.json"<?php // address-check: allow — reason ?>>`); the check stops reading a line where the
  note starts, and the self-test excuses the address only inside the text that very line prints.
- **The organisation's part of an address is matched whatever capitals it is typed in** (the database's rule), and
  `Router::extractPath()` agrees since #536. Links on the page keep the spelling the person came in on.
- **`Auth::safeRedirectUrl()` judges a "come back to" address twice**, as it was sent and once decoded, and both
  must pass. Where first parts are in use it must start with the request's own part; from an address with no
  part it must not start with another active organisation's (that asks the database the question
  `Site::detectFromPath()` asks; change the two together). Its fallback is the organisation's front page.
- **Comparing old code with new, page for page**: reading a page writes rows, so the database is saved once and
  put back before each run; and the old code is first run against itself, to learn what differs whatever the
  code (the clock, form tokens, a new two-step secret, free disk space, the order of two log rows written in
  the same second). The script that does this for F8 is kept with that package's evidence, not in the repository.
- A failed organisation look-up shows a "try again" page (HTTP 503) instead of
  organisation 1, and reaches PHP's error log in all three ways. A database older than
  migration 015 (no organisation column in the settings table) is treated as
  "multi-organisation off", not as a failure.
- Links to another organisation come from `Site::addressOf()`. The redirect after the
  set-up is saved comes from `Site::urlUnder()`.
- **`Site::lockedInGeneralEditor()` is asked in three places** of the general settings
  editor: change and add (`settings/save.php`) and delete (`settings/index.php`).
- **Two self-tests changed with the gate** (`tools/access-pages-selftest.php`, which has
  285 checks on this branch, and `tools/feed-admin-selftest.php`): their outsider parts
  run with the gate's one refusing line stood down in the scratch copy, so the page's own
  rule answers; then the same outsiders are sent again with the gate back ("at the door"
  checks). A new test that sends an outsider to a page should do the same, or its
  "refused" will come from the gate and prove nothing about the page.
- **The gate shows the chooser itself** (it does not go through the address table), and a
  global administrator who has not chosen an organisation yet may still open
  `admin/upgrade`. Both exist so that an upgrade can never be locked behind the chooser.
- **`tools/site-modes-selftest.php` has two test-only extras in its router**: one hands
  PHP another session's data on its second read (a session that changes between the
  early look and the real start), and one answers with the portal's start-up alone.
  It also makes a look-up fail for one request by renaming a table away. A test that
  searches a whole page for a short number must ask for the number standing on its own:
  every page carries a random form token, and "7919" turned up inside one by chance.

---

## Time zones: one answer for an event, one for an organisation (#604)

- **`Portal\Core\Site::timezone(?int $siteId)` is the ONE place that answers an organisation's time zone.** Never read `tblSites.timezone`, and never bring back a `site.timezone` setting. It always answers a name from PHP's main list (UTC when none is stored or the stored text is not one). `Site::timezoneFacts()` gives what it was worked out from, `Site::isZoneName()` is the test, `Site::zoneFor($stored, $siteId)` is the zone to read a stored clock time in (the stored one when PHP can open it, else the organisation's).
- **One reader does NOT follow it, on purpose: giving retention.** `GivingRetention::timezoneOfSite()` always answers "not known", so "today" is the last place on Earth's date. A deletion that cannot be undone must never come sooner. The one date in that class taken in the organisation's OWN zone is the "Confirmed on" date `saveForSite()` stores, which only people read.
- **The mark.** Migration 217 writes the installation-wide setting `site.timezoneKeptAt = organisation`. While it is absent the database has not been through 217 and `Site::timezone()` reads the old setting, exactly as start-up did. The install script writes the mark only where there is no `site.timezone` row and no `tblEvents.eventTimezone` column. The general Settings page refuses both keys. A test that loads classes without `bootstrap.php` must call `Site::init()` with the mark, as the three feed tests and `tools/gdpr-eraser-selftest.php` do. The mark's `updatedAt` is "when this installation's time zones were brought into line": `Events::markedLondonBeforeUpgrade()` reads it.
- **An event's zone is `tblEvents.timezone`, and the column has no default.** Every `INSERT INTO tblEvents` names it; one that does not is refused (error 1364). There is no `eventTimezone`.
- **A stored event time is a reading of the event's clock.** To show or re-shape it, never pass it through `strtotime()`/`date()` or a zone it is not in. To turn it into a moment, read it in its zone (`Site::zoneFor()` or `MailQueue::useBy()`).
- **A calendar file for ONE organisation gives `Ical::emit()` that organisation's zone** (`Site::timezone($siteId)`): a feed is asked for with a token and no sign-in, so the request's own organisation is the default one.
- **A column removed by a migration** goes on `DbBackup::REMOVED_COLUMNS`, or older snapshots stop restoring.
- **Two new lines in the activity log:** `EventZonesSetToOrganisation` (the Manage Events button, with how many events it changed) and `EventReminderNotSent` (a day-before reminder left unsent on the day the clocks go back).
- **The reminder job's day-before window is half an hour wide and can straddle midnight.** A call inside it that lands on the event's own day still sends, naming the day, with the event's START as its use-by moment. Only when the moment exactly 24 hours before the start is itself the event's own day is nothing sent.
- **Tests:** `tools/time-zones-selftest.php` (131 checks; a fresh database of its own; it runs the reminder job every half hour over a week, so it takes over a minute). `tools/gdpr-eraser-selftest.php` (225 checks) works out "today" as the date twelve hours behind UTC and has a Kiritimati organisation (section 16); it refuses to start in the five minutes before 12:00 UTC. `tools/mail-queue-selftest.php` (155 checks) holds a late call's use-by moment. The migration test has a seventh phase: the installer's "carry on" over an older database whose two zone places differ.

---

## Addresses that reach nothing, and the "We're live" push (#612)

- Run the check with `python3 tools/audit-checks/check_registered_addresses.py`. It reads `web/_sql/full_schema.sql`
  as SQL (comments removed, every row of every `INSERT INTO tblRoutes` and every `api.*.enabled` row of
  `INSERT INTO tblSettings` read value by value, later `DELETE`s applied). On 13 October 2026 it found the same 599
  addresses and the same 61 api switches as a MySQL 8.0.36 database built from that script
  (`--list-registered` and `--list-switches` print them for comparison).
- It cannot see a key built from a variable (`Site::url('expenses/view/' . $id)`), nor judge whether a registered
  address is the right one, nor whether the app that owns an `api/` folder is switched on, nor a switch seeded
  `false`. It prints how many calls it skipped.
- The router answers some addresses without the address table (`login/google`, `logout`, `a/<token>`, `f/<token>`
  and others). They are listed, each with its reason, in `ROUTER_EXACT` and `ROUTER_PATTERNS` in the check. If
  `Router::handleSpecialRoutes()` gains one, add it there.
- `KNOWN_UNREGISTERED` in the check is EMPTY. A line there would be an address found to reach nothing and not yet
  fixed; it only shrinks, and a line that no longer matches fails the check.
- The moderation handler (`web/_apps/livechat/api/moderate.php`) uses up the form token as soon as it has checked it
  (`Auth::verifyCsrf()` rotates it) whatever the answer, and does not send the new one back. A page that posts to it
  must fetch a fresh token before the next press. `admin/live/chat.php` does that by reloading after every answer.
  Two moderation tabs open at once still share one token: the second tab's first press says "CSRF check failed" and
  then reloads.
- Live chat is off in a new installation (`chat.enabled` is `false`); the page answers "App disabled" until a global
  administrator switches it on. `tools/live-chat-moderation-selftest.php` switches it on for its run, switches several
  organisations on (told apart by sign-in session) for part G, and puts both back.
- Run the test: `SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=... SELFTEST_DB_PASS=... SELFTEST_WEB_PORT=9750
  SELFTEST_WEB_DIR=/a/quiet/folder php tools/live-chat-moderation-selftest.php`. The database must hold
  `full_schema.sql`. Part E needs Node.js; without it the part says SKIPPED and the last line says the script was not
  run.
- The "We're live" handler (`web/_apps/admin/livestream/index.php`, action `notify_live`) calls `WebPush::sendToChannel()`
  directly, so there is no place to hand it a stand-in sender. `tools/go-live-button-selftest.php` instead gives every
  device an `http://...selftest.invalid` address, which `WebPush::send()` refuses before any network call, recording
  outcome -1 on the device's row: a row with `failCount` one higher and `lastHttpStatus = -1` is a device that WAS
  addressed. To read WHAT was said, one line is put at the top of `send()` in the test's SCRATCH COPY of the sender (never
  the repository's file): it writes the device, the words, the keep-time, urgency and collapse key to a file, and pauses
  when `GLB_SLOW_MS` is set (only for the server with eight workers, so that overlapping presses overlap). It opens no
  connection. Web Push is made to look configured with made-up text (the private key's row is marked "not secret" for the
  run) and everything is put back. It would also catch a real answer (any other status) as a failure.
- The limit on "We're live" presses is taken in `web/_apps/admin/livestream/index.php` inside a short transaction that first
  locks the organisation's own `tblSites` row (`SELECT … FOR UPDATE`, the way `Events` and the calendar importer take a
  turn), then asks `RateLimiter::tooMany()` and records the hit with `RateLimiter::recordHit()`, and only then sends.
  The sender's query (`WebPush::sendToChannel()`) leaves out a device whose account is switched off
  (`tblUsers.isActive = 0`) or whose membership of that organisation is switched off (`tblUserSites.isActive = 0`). Since
  the follow-up of 13 October 2026 a device with NO account is left out too (`ps.userID IS NOT NULL`); an erased
  account turns its devices into devices with no account, so they stop being addressed as well. A device whose
  account has no membership row at all is unchanged.
- **Who may send "We're live"** (`web/_apps/admin/livestream/index.php`, action `notify_live`): a person who is not an
  administrator is let past the page's gate for a POST whose action is `notify_live` ONLY. With an event number, the
  event must pass `HostConsole::eventBelongsToSite($eventId, $siteId)` AND the person must pass
  `Auth::isCoordinatorOf($eventId)` (true for an administrator too; false for a withdrawn coordinator or, when the DBS
  setting is on, one without a valid check). With NO number only an administrator may send (the channels page's own
  button posts none). A refused person who is not an administrator always gets the 403 page, so the answer says
  nothing about which event numbers are real.
- The Host Console (`event.php`) sets `$mayPress` from the SAME two calls. There is no shared function yet; one would
  belong in `Portal\Core\HostConsole`. The test compares the button with the handler for seven kinds of person.
- Activity type `GoLivePushPressed`: description `userID=N, eventID=N` (or `eventID=none`), written after the press
  is counted and before the send. It holds nothing of the push.
- `api/push/subscribe` answers 401 before anything else when nobody is signed in, and stores the device against the
  session's account only. `api/push/unsubscribe` is unchanged on purpose: it needs a form token and the device's own
  endpoint address, not a sign-in, so a device with no account can still switch itself off.
- Admin, Integrations, Push has its OWN copy of the sender's rule for its counts (`$reachable` in
  `admin/integrations/push/index.php`): keep it together with the query in `WebPush::sendToChannel()`.
- The handler reads `action`, `csrf_token` and `returnTo` as text only (`is_string()`); a list is an empty value.
  `eventID` is still cast with `(int)`, so a list there is read as event 1 (on the issue).
- Test: `tools/go-live-button-selftest.php` (127 checks; `GLB_EXPECTED_CHECKS`), parts J (coordinators), K
  (subscribing) and L (the Integrations page's counts).
- `web/_apps/auth/account/save.php` (the Account page's "Notification Preferences" card) now reads the stored choices and
  changes only its own three keys (`emailDigest`, `expenseUpdates`, `eventReminders`).
- `web/_core/templates/header.php` draws the trail of links: only the last entry is `aria-current="page"`; an earlier
  entry with no address is `<li class="breadcrumb-item">` with plain words (it used to be `active` and current too).

---

## The installer (#499, #500)

`web/_install/index.php` runs with none of the portal loaded. It is reached only through the web folder's
`index.php`, when `_auth_keys/auth_creds.php` is missing.

- **Who may use it.** `web/_install/owner_code.php` writes a code into `_auth_keys/install-code.php` on the first
  visit by anybody and judges what is typed. Wrong tries are counted IN THE FILE, because a session is a cookie the
  guesser throws away. The session of somebody who typed the code holds a fingerprint of it, compared with the file
  on every request: a replaced code ends every session the old one let in. `installCodesMatch()` is the one
  comparison and it is `hash_equals()`. Do not add a second one.
- **The code file is read and written under a file lock.** `installCodeJudge()` locks the whole file while it
  reads, judges and writes; `installCodeRead()` waits for that lock with a shared one. Without the shared lock a
  page view could read the file while it was empty for a moment, take that for "no code file" and write a new
  code: 200 wrong codes at once then gave 13 to 18 new codes where 20 were due. Do not read that file any other
  way. Writing a new file and renaming it into place was rejected: it swaps the file under requests still waiting
  for the lock.
- **No form is acted on without the code**, and every form carries a token kept in the session (a page on another
  website must not be able to post the database step into a wizard somebody has unlocked).
- **Two refusals for a website pointed at the folder that holds `_core`.** (1) Opened directly: the web folder is
  the folder of the file that handed the request over (`INSTALL_WEB_FOLDER`), and none means the web server ran
  `_install/index.php` itself. (2) Any part of the address asked for is the web folder's own name: on a rightly
  pointed website that name is never in an address. The name comes from the folder itself, never from anything
  typed or from what the web server reports. Neither can catch the commonest sign, a folder list, "Forbidden" or
  "Not found" at the plain address, because no file of the portal runs then.
- **A choice belongs to ONE database.** "Carry on" or "wipe" is kept together with the identity of the database it
  was made for (server, port, name and user), by `installChoiceKeep()` in `web/_install/db_state.php`, and
  `installChoiceHeld()` answers "none" the moment the database held is another. The database step forgets any
  earlier choice whenever it lets a database through. The build step wipes only on a wipe held for the database it
  is about to touch, and with no choice held for a database that is not empty it builds nothing and draws the
  choice page. Both forms also carry a mark for the database their page was drawn for (`installDatabaseMark()`),
  so a page left open in another tab is not acted on for a different database. **Never read the session's choice
  directly:** that is how a wipe confirmed for one database once wiped another (found by the check of 13 October
  2026). Uploaded files are emptied in one place only, inside that wipe.
- **The last page is drawn in the answer to the finish.** Once `auth_creds.php` exists the front controller starts
  the portal on every request, so nothing after that request can reach the installer.
- **The order of the finish matters**: code, database and administrator, private folders, key file, addresses, the
  database login, then the installed version and the lock. The login is last of what can fail, because it is what
  locks the installer; the version comes after it, because a database with a version recorded counts as a finished
  portal. Because the addresses are stored before the login, a finish that stops at the login has already stored
  them: the session remembers which values this run filled in (`install_filled`), and a later press may replace
  those, and only those.
- **A database that holds tables but no installed version** has "no finished installation recorded". That is what
  an interrupted installation leaves, and also what a working portal looks like if it was installed before the
  version was recorded (29 May 2026) or had the record emptied. The installer cannot tell them apart, so it never
  says "nothing was finished here", and the choice page shows how many accounts the database holds. It lets the
  code stand in for the sign-in there, but only when every `tbl…` table in it is one the install script makes
  (`installTablesNotOurs()`); otherwise it refuses. With a version recorded it demands a signed-in global
  administrator.
- **Shared with the upgrade page**: `web/_install/install_common.php` holds the private folders' "refuse
  everything" file and the words for the channel. A rules file with no rule in it is written again; one that holds
  a rule is left alone, and `installProtectFolder()` reports whether it holds the refusal, so that no page claims a
  protection that is not there. The channel is still decided only in `Portal\Core\Channel`;
  `installChannelFileIgnored()` only notices a `.channel` file that the decision did not come from.
- **Only `PortalAddress::check()`** may be called from the installer.
- **Do not type `public_html` into a string under `web/`** (`tools/channel-selftest.php` D04): the installer names
  the three folders through `Channel::FOLDER_NAMES`.
- Test: `tools/installer-selftest.php` (130 checks; 131 with `SELFTEST_DB_PORT_2`, a second throwaway server, for
  "the same database name on another server"). It makes and drops three databases named `selftest_inst_…`, so run
  it only against a throwaway server, and not as the system's administrator account.

**The package and the two empty folders.** `web/_functions` and `web/_includes` now hold the "refuse everything"
file as well. The package still leaves both folders out. `build.sh` and `check-package.py` let exactly one file
through there: the folder's own `.htaccess`, while it is byte for byte the copy in `web/_core`. Anything else in
either folder still stops the build (`package-selftest.sh` cases T04b and B08b).

---

## Secret settings (#597, #497)

- **Back up `_auth_keys/enc.key` together with the database, every time.**
  From this version every integration's secret depends on it.
- A setting is a secret when `tblSettings.isSensitive` is 1. That mark is the
  only record of it. Compare it with `SecretSettings::isMarked()`, never with
  `=== '1'`: a prepared statement returns the number 1.
- `Portal\Core\SecretSettings` (`web/_core/SecretSettings.php`) is the one
  place that says what a stored secret means. `forReader()` is what the
  settings load in `bootstrap.php` hands on; `state()` is what a page may say
  about a row; `seal()` is what a save stores; `unreadable()` names the ones
  the portal cannot read; `repairStoredRows()` is what Upgrade runs;
  `keyFileNote()` is what the health page says about the key file.
- **`$SETTINGS`, `App::settings()` and `Settings::get()` hold the REAL value of
  every secret.** Never decrypt a value taken from them: it is already
  decrypted, and decrypting again gives an empty text or an error.
- **The key is the SHA-256 of the whole key file, so a file of ANY length is
  a working key.** Only an empty file, or something that is not a file, is
  "no key". Never refuse a short key file: every secret on that installation
  would be lost. The rule is written twice, in `SecretSettings::keyFileContents()`
  and in `portal_key_file_contents()` in `bootstrap.php` (which the two older
  helper functions use). Change both together; the self-test holds them to
  the same answer.
- **A marked row that does not decrypt is used exactly as stored**, and
  start-up never stops for one. Text that is not base64 of at least 40 bytes
  is certainly READABLE TEXT. Text that is, and that the key file does not
  open, is treated as CANNOT BE READ; a readable secret that happens to be
  long base64 is counted there too, which loses nothing.
- **A page must never print a secret**, not in a `value=""`, not in an
  attribute, not as dots that count its characters. Show "set" or "not set".
  An empty box must keep the stored value, **and must never remove a value
  the portal cannot read**: such a box is empty because the page will not
  show a scramble, not because somebody emptied it.
- **A save must go through `SecretSettings::seal()`** and refuse when it
  answers null. Twelve older save pages, the QR page and the venues page
  still call `encrypt_setting()` directly: with no key they answer with an
  error page where a plain message belongs.
- **`Captcha::verify($_POST, $form)` must be told which form is asking.**
  `Captcha::FOR_SIGN_IN` and `Captcha::FOR_PASSWORD_RESET` are let through
  without the bot check while its keys cannot be read; any other name is a
  form that is refused then. `Captcha::widget($form)` takes the same and
  shows the notice. **A form that draws no widget must show
  `Captcha::closedNotice()` itself**, as the visitor form and live chat do.
- **An address that is recorded or sent anywhere goes through
  `Logger::withoutJobToken()`**, which cuts the value of a `key` or `token`
  parameter. A scheduled job that takes its token under another name must be
  added to the list in `Logger.php`.
- `bootstrap.php` switches on PHP's `zend.exception_ignore_args`, so a stored
  error trace names each step without what it was handed. It also calls
  `SecretSettings::reportUnreadable()`: one SHORT line in the web server's log
  on every request while a secret cannot be read (a count, and where to read
  more), and at most one Critical row in the error log every six hours (none
  while the portal is closed for an upgrade).
- The test is `tools/settings-secrets-selftest.php`. It needs a database named
  `selftest_…` holding `full_schema.sql`, an account allowed to make a
  trigger, and three free ports. It was run on MySQL 8.0.36 and 8.4.11;
  nothing here has been run on MariaDB.
- `tools/audit-checks/check_sensitive_seeds.py` fails on a setting seeded as a
  secret with a value, and on a seed that could overwrite a stored secret.
- What each piece cannot do is written at the top of `SecretSettings.php`,
  `Captcha.php`, `Logger::withoutJobToken()` and migration 214.

---

## The portal's own web address (#500)

`Portal\Core\PortalAddress` is the ONE place that decides the address in a link the portal sends out.

- `PortalAddress::absolute('page?x=1', $siteId)` — a whole link, or `''` when there is no address to give. The caller must then leave the link out or not send. Never build one from `$_SERVER['HTTP_HOST']`.
- `PortalAddress::linkHtml($route, $label, $siteId)` — the same as a ready `<p><a>` line for an e-mail, or `''`.
- `PortalAddress::forPage($route, $siteId)` — for a link SHOWN on a page (copy this link, a QR code); falls back to the address without a host name.
- `PortalAddress::saved()` — the set address only, never the request's (used for the name given to a mail server).
- `PortalAddress::check($typed)` uses nothing but PHP, so the installer can load that one file.
- `PortalAddress::visitorCanChooseHost()` — whether a request can still choose the host name of an e-mailed link. Yes while nothing usable is set, except in "own address each" mode when the DEFAULT organisation has an address of its own. The dashboard's warning goes by this; do not work it out again on a page.
- Order: the installation-wide `site.url` row when it holds a usable address; else the request's address; else nothing. A stored value that is not usable is ignored (links follow the request again, and the dashboard says so). A value that cannot be read gives nothing, not the request's.
- Only the installation-wide row is read. An organisation's own `site.url` row is ignored on purpose: an administrator of one organisation could otherwise point reset links at a site of their choosing.
- Several organisations: "own address each" uses the organisation's own host name with the set address's scheme and port; "first part of the address" adds the organisation's key; "sign-in session" adds nothing. An organisation that cannot be found, or is switched off, gets no link.
- Pass the organisation's number in any job that works through several organisations.
- The dashboard's "Use this address" button posts to the card's own save handler (`admin/settings/organisation/save`, `form=web-addresses`) with the address alone. That handler leaves the support address untouched when its box was not sent.
- `Newsletter::dispatch()` returns a third value, `notSent`: `''` when a pass was made, a `Newsletter::NOT_SENT_*` reason when nothing was tried. A caller must show that reason and not report "sent 0". `Newsletter::cannotSendBecause($siteId)` asks the same question before anybody is locked in.
- Test: `tools/portal-address-selftest.php`. Check: `tools/audit-checks/check_no_hardcoded_domains.py` (its pending list must be empty before a release: `--strict`).
- `tools/audit-checks/check_no_php_in_urls.py` does not read the address of a picture (`src=`). Three QR pictures pointed at an address ending `.php` for that reason; part Q of the self-test now holds them.
- Not converted: readers of the request's host name that are NOT e-mails (feeds, the sitemap, QR labels, payment return addresses, the webhook addresses shown to administrators). See "Found" in the build report.

---

## Two-factor authentication: backup codes (#613)

- `web/_apps/auth/2fa/setup.php` is the ONE place backup codes are made (`twoStepReplaceBackupCodes()`), for
  switching on and for "Make New Backup Codes" (the Account page's form posts there with `action=new_backup_codes`;
  there is no separate address, so no row in `tblRoutes`).
- **The codes are drawn in the answer to the request that made them and nowhere else.** Never put them, or the key
  for the authenticator app, in `$_SESSION` in readable form. The key waits in the session encrypted
  (`Auth::encrypt()`), under `totp_setup_secret`. The name `totp_backup_codes` is no longer written; the set-up page
  removes it from any session that still has it.
- **How the old set is removed, and why.** Inside the transaction: lock the person's own `tblUsers` row
  (`… FOR UPDATE`), read their old code rows with a plain `SELECT`, delete each by its own `codeID`. **Never
  `DELETE FROM tblTotpBackupCodes WHERE userID = ?` there:** when the person has no rows, that statement holds the
  empty stretch of the index, a second person doing the same holds it too, and the database stops one of them (a
  deadlock). Catching the deadlock and trying again does not work (the other request is still working out its
  fingerprints). The caller must not read anything inside its transaction before calling the function: the plain
  read sees the database as it was at the transaction's first plain read. Checks D5b, D5c and D5d hold all three.
- A backup code is single-use because `verify.php` marks it with `UPDATE … WHERE codeID = ? AND isUsed = 0` and
  lets the person in only when that changed one row. Do not go back to "mark it, then say yes". It is looked for
  among the person's OWN codes only (`WHERE userID = ?`): check C13.
- Storage is unchanged: `tblTotpBackupCodes.codeHash` is a bcrypt fingerprint (`password_hash`), one row per code.
- Activity types added: `TotpBackupCodesMade`, `TotpBackupCodesFailed`. `TotpVerified` and `TotpVerifyFailed` are
  unchanged (the sign-in report in `admin/reports/data.php` reads them by name).
- Limits: the code page keeps its own (5 per account and address in 15 minutes, 15 per account in 30). "Make New
  Backup Codes" has a separate one, `totp_newcodes:<account>`, 5 in 15 minutes.
- **A script written into a page needs the page's nonce** (a one-time mark the page's header gives the browser).
  The Account form's "send once" script carries `nonce="<?php echo … App::cspNonce() … ?>"`. The header's rules
  (`templates/header.php`) name a nonce, and a browser that knows nonces then refuses any script written into the
  page without one. Many older pages still write a script with no nonce; that is tracked as its own issue.
- Still open, each on the issue: the picture (QR code) on the set-up page (#623); an authenticator code can be
  used again inside its window; one person in two windows at the same moment is shown two sets and only the later
  one is stored.
- Test: `php tools/two-step-selftest.php` (see its header for what it cannot prove: a real authenticator app, how
  the pages look, that a script runs, an account with no password, MariaDB). It must run alone on its database
  server.

---

## Sign-in limits and password reset (#580)

- **`Mailer::canSend()` is the one place that answers "can the portal send e-mail?"** `Mailer::sendingRoute()` beside it gives the route's name and what is still missing. A page must never test a provider's settings itself. A new mail route is added in `Mailer::route()` and `Mailer::sendingRoute()` and nowhere else. "Yes" is not proof of delivery.
- **Sign-in records.** `LoginFailed` is what the limits count: one per wrong password (written by `Auth::loginLocal()`) or per refusal by the page itself (expired form, captcha, empty field). `LoginBlocked` is a try refused because a limit was already reached; it is never counted. `LoginNameCountCleared` is written when a password reset is completed: failures for that name older than it are no longer counted (nothing is deleted).
- **The limit for one name is counted by account.** `Auth::loginLocal()` records a failure under the account's user name, whichever of the name and the e-mail address was typed, using `RateLimiter::FAILED_NO_ACCOUNT` and `FAILED_WRONG_PASSWORD`. The counter matches those sentences exactly. Change the wording in those two constants only.
- **One sign-in try at a time for each visitor address.** `Auth::loginLocal()` holds a named database lock (`GET_LOCK`) for the address from the count to the record. MySQL frees it when the request's connection closes, so a dead request cannot leave an address locked. A try that cannot have the lock within 5 seconds is refused as blocked. Tries at one NAME from many addresses at once do not take turns.
- **`Auth::NO_ACCOUNT_HASH`** is the made-up fingerprint checked when a name has no usable account. Its cost must equal what `password_hash(…, PASSWORD_DEFAULT)` gives; the self-test fails when they differ. Accounts whose password was last set under PHP 8.3 or older (cost 10) are checked faster than it and can be told apart until the password is next changed.
- **Reset links.** `Auth::storeResetLink()` and `Auth::findResetLink()` are the only code that writes or judges a link. `tblPasswordResets.expiresAt` is a moment in UTC (rows written before this version held the page's local time; the changed fingerprint makes those rows unreachable). `tokenHash` is the SHA-256 of a fixed prefix plus the link's secret. Both column comments in `full_schema.sql` should say so at the next database change.
- **"Forgot password" answers first.** It uses `fastcgi_finish_request()` (PHP-FPM) or `litespeed_finish_request()`, and otherwise sends a complete, zero-length answer and carries on. A web server that holds PHP's output until the script ends (Apache with mod_fcgid, CGI) still makes the person wait for the e-mail, so the time still differs there. Closing that on every host needs the e-mail to be queued and sent by the scheduled job.
- **`Logger::redactSensitiveHeaders()` cuts the `Referer` header at its "?" or "#"**, for every record. Rows written before this version are not cleaned.
- **Reset limits** use the general counter (`RateLimiter::tooMany()` / `recordHit()`), buckets `pwreset:ask:<fingerprint of the address>` and `pwreset:use:<…>`.
- **`RateLimiter::lockoutRemaining()`, `nameLockoutRemaining()` and `retryAfter()` ask the database for the time left**, so both ends of the sum use one clock. Do not go back to `strtotime()` on a time the database wrote.
- **Self-test:** `tools/sign-in-limits-selftest.php` (114 checks; needs a `selftest_` database that knows time zones by name, and nine free ports: the web port, the mail port and the seven after it; starts its own stand-in for the mail services). Run on MySQL 8.0.36 and 8.4.11. It is not run on GitHub, like the other page self-tests.
- **New activity record types:** `PasswordResetMailFailed` (the provider refused the message; it used to be recorded as sent) and `LoginNameCountCleared`.

---

## The mail library (`web/_core/maillib/`, #592)

Parts one to four of six. The portal sends through it when the SMTP route is chosen; a message that
cannot go at once waits in the mail queue; and a global administrator has a page for the queue and three
deliberate ways past a failed certificate check. Parts five and six (Microsoft 365 and Google on the same
client; PHPMailer as a second engine) come after the first roll-out, by the owner's decision.

### Wired into the portal (part two, `MailRoutes`)

- **Who calls it.** Only `web/_core/MailRoutes.php` names the library. `Mailer::send()` asks `MailRoutes` when `mail.provider` is `smtp`. No page reads the SMTP settings itself.
- **Why `MailRoutes` reads the database and not `$SETTINGS`.** (1) It was written when the portal's start-up never decrypted a secret setting; since #597 was fixed `$SETTINGS` does hold the real password, but nothing should read it from there. (2) `$SETTINGS` has one organisation's own rows laid over the installation's; a mail server's name must never come from an organisation's row. Every query in `MailRoutes` says `siteID IS NULL`. **The choice of route is read the same way**, through `MailRoutes::chosenRoute()`: do not read `$SETTINGS['mail']['provider']` anywhere.
- **The password's rules.** `MailRoutes::saveSmtp()` is the place meant to write it (encrypted, marked as a secret), **but it is not the only thing that can**: the general Settings page can change the same row for a global administrator. What is promised is about reading: a stored value is used only when its row is marked as a secret AND it decrypts with the installation's key. Do not loosen either test: an unmarked value is what the Settings page's Edit box leaves behind, in plain text. (A request to the Settings page that asks for the value to be encrypted and marked passes both tests, and that one is used.)
- **Since #597 was fixed**, `$SETTINGS['mail']['smtp']['password']` holds the real password in memory on every request. Never print `$SETTINGS`; that package's check fetched 368 addresses four ways each and found no secret on any.
- **Time limits.** Sends use shorter limits than the library's defaults (27 seconds at most for one message), because every send is still inside a web request. That is per message, not per request: a page that sends to many people in turn can still run past the host's limit (to be cured by the queue, M3).
- **The hourly limit** counts `tblEmailLog.sentAtUtc`, never `sentAt` (the database server's own clock, which repeats an hour when the clocks go back). It is counted before each send and is not locked, so sends at the same moment can pass it.
- **Run its test:** `SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… SELFTEST_WEB_DIR=/a/quiet/folder php tools/mail-pages-selftest.php`. It starts the library's own stand-in mail servers and trusts their made-up authority through PHP's `openssl.cafile` setting, so no file of the portal is changed for the test.

### The mail queue (part three, `MailQueue`)

- **Only what could not be sent at once is stored.** `Mailer::send()` tries now when it may. It keeps a message, and answers `true`, when: the caller passed `sendNow` false; a fault with the route is on record; the request has used its budget; the hourly limit has no room; or the try ended in "later". It answers `false` only when the message was neither sent nor kept. `Mailer::lastOutcome()` is `sent`, `queued`, `held`, `refused`, `not-set-up` or `not-sent`; `Mailer::lastIsWaiting()` is true for `queued` and `held`. **"True" is not "sent": anything that records or shows a result asks.**
- **Is the job running? One question, one place: `MailQueue::jobIsRunning()`.** True when the scheduler has called the job's own page with the right token in the last 15 minutes (`tblMailRouteState.jobLastRanAt`, written by `web/_apps/cron/mail-queue.php` only; a run made by any other page does not count). A moment in the future answers no. While it is false the portal does as it did before the queue wherever that gets mail delivered: "Forgot password" sends in the request on every web server; `Mailer::requestMessages()` gives no limit of 25 (the 20 seconds stay); and a page tries again once the wait after a refused sign-in is over. A failed certificate holds mail either way. The health line asks the same function. Do not write a second test of this.
- **What decides.** `MailRoutes::whatNext()` is the one place the mail library's four outcomes become what the portal does. A "no" at the sign-in is about the mailbox (the message waits). A "no" before the message itself (the greeting, the encryption, the sender) is tried again: it can be forged before encryption is on, and a "no" to the sender is usually a daily limit. Only a "no" to a recipient, the content or the size is final.
- **Every statement that moves a row is in `MailQueue.php`**, each in a method marked 🔀. Read them there before changing a state.
- **Times.** Every stored moment is UTC from the database's `UTC_TIMESTAMP()`, written in the statement itself; every length is seconds added by the database. Nothing uses `NOW()`, PHP's clock or `UNIX_TIMESTAMP(column)` (which reads a stored UTC value as local time). `MailQueue::shown()` is the one place a moment is turned into a zone, for showing. **`MailQueue::useBy($timeOnAClock, $zone, $otherwise)`** turns "the event starts at 10:00, America/New_York" into the UTC moment `expiresAt` needs, using `$otherwise` when PHP does not know `$zone`; when it cannot, it answers text that is not a moment, and such a message is never kept. **An event's zone is `tblEvents.timezone`** (the column the event form writes and the event page, the calendar feed and the export read), with the organisation's zone, from `Site::timezone()`, as `$otherwise`. (Until migration 217 there was a second column, `eventTimezone`, which only the feed importer wrote; it is gone, #604.) One limit, written beside `useBy()` and held by a check: a time in the stretch that happens twice on the night the clocks go back is read by PHP as its second happening in the zones tried at or east of Greenwich (London, Paris, Lisbon, Sydney, Lord Howe, Auckland: so up to an hour late), and as its first in those tried west of it (New York, Los Angeles, Santiago, St John's, the Azores).
- **Never twice.** `claim()` is one `UPDATE … ORDER BY … LIMIT` with a random mark, safe without the lock. `handOver()` marks the row `handing-over` and counts the attempt BEFORE the send, and is the last check that the message may still go. `run()` takes `GET_LOCK` (named for the database), so two runs cannot both spend the hourly limit. A claim ten minutes old is dead: `recoverDeadClaims()`. `DbBackup` calls `MailQueue::holdRestored()` at the end of a restore, inside the restore's own transaction.
- **`origin`** (`tblMailQueue.origin`, the `origin` option of `Mailer::send()`): a kind and a number, such as `newsletter:12`, `statement:7`, `reset:5`. When a kept message goes or fails, `MailQueue::tellOrigin()` tells the record it came from (`Newsletter::mailSettled()`, `Giving::statementMailSettled()`), which is what turns "waiting" into "delivered" or "failed". `MailQueue::removeWaitingFrom('reset:5')` removes a person's older waiting reset e-mails. To make a new record follow its message: add its kind to `MailQueue::ORIGINS`.
- **The Message-ID is fixed when a message is kept** (`MailRoutes::newMessageId()`), not at its first attempt, so a second copy, where one is allowed, carries the same one.
- **The route's record** (`tblMailRouteState`, one row, key `installation`). `MailRoutes::noteResult()` writes it after every SMTP attempt; `standingProblem()` reads it before every send and every run. Once the wait after a refused sign-in is over, a page (with no job running) or a run must win `MailRoutes::claimSignInTry()` before it signs in: one statement that moves "not before" on by two minutes and that only one request can win. A record made under other settings is dropped (it carries a fingerprint of them), so saving the password again starts mail at once. "Send test" always contacts the server. Package M4 reads the certificate's facts from this row.
- **The job** is `web/_apps/cron/mail-queue.php`, a web request like every other scheduled job, so it starts no new message after 20 seconds and uses the same short send limits as a page. Token: `maintenance.cronToken`, in an `Authorization: Bearer` header or `?token=`. It removes the token from `$_SERVER` before doing anything, so no error or activity record carries it. It prints numbers only. It is on `Gatekeeper::OPEN_PATHS`, so a scheduler can run it on a pre-release copy; the other scheduled jobs are not.
- **The scheduler line** (an example address): `*/5 * * * * curl -fsS -H "Authorization: Bearer <token>" "https://<your-portal-host>/cron/mail-queue" >/dev/null`
- **The four numbers** are read straight from `tblSettings` (not `$SETTINGS`), the organisation's row first, then the installation's, then the default in `MailQueue::NUMBERS`. The Settings page lets an organisation's administrator add, change and (by clearing the box) remove their organisation's own row for exactly those four names; `MailQueue::ownNumberCheck()` applies the queue's own test and writes the words.
- **Attachments** are copied to `_uploads/mail-queue/<32 hex>/<the file's own name>` when a message is kept. The row says how many files it has; a row whose stored files do not come to that number is failed, never sent without one. No transaction is opened in `add()`: it would commit the caller's. (The event broadcast opens ONE of its own around its record and all its messages, under a lock for the event, so a request that ends part way leaves nothing.)
- **`MailQueue::pageCanAnswerFirst()`** is true for PHP-FPM, LiteSpeed and PHP's built-in server only. Everywhere else (Apache's own PHP module, FastCGI, CGI) "Forgot password" queues the reset and does not send in the request, but ONLY while the job is running.
- **Erasure.** `MailQueue::eraseAddress()` takes the run lock, waiting up to 20 seconds, so a run cannot write an erased address back; it tells the record a deleted message came from that it did not go; the send-log step then empties the reason text, and the mail error lines (`GdprEraser::MAIL_ERROR_PLATFORMS`) that name the address by itself. When a run is still working after the wait, the person is taken out of the queue all the same, the answer carries `runWasWorking`, and the eraser counts the step as failed so that the request stays at "pending review" and is run again.
- **What it cannot do.** Rule out a second copy when a mail server accepts and its answer is lost (such a message is held). Send what is WAITING while the job is not running. Classify a refusal from Microsoft 365 or Google (they answer yes or no; a message they turn down in a request is not kept). Keep the limit exact for mail sent straight from pages at the same instant. Release a held message (package M4).
- **Run its test:** `SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… SELFTEST_WEB_DIR=/a/quiet/folder php tools/mail-queue-selftest.php`. It needs `php-cgi` and Python 3, a database that knows time zones by name, a database user that may create a trigger (part F5), and a database server whose own clock is UTC (part F3 refuses otherwise; it also refuses in the one hour of the year that happens twice on New York's clock). `SELFTEST_ONLY=Q5,Q4` runs some parts and never exits 0.
- **Two traps met while testing.** The send log's own tidying (`Mailer::logSend()`, about one write in fifty) deletes lines more than 90 days old by the server's clock: a test that sets a made-up clock months ahead must give its own log lines a `sentAt` of that night. And an erasure turns an address in the send log into "[erased]", so a test that finds its own lines by address leaves that line behind, where it counts against the next test's hourly limit: find such lines by subject as well.

### The certificate check, and the ways past it (part four, `MailRoutes`)

- **`MailRoutes::certificateCheck()` is the ONE place `mail.smtp.certCheck` is read.** It holds `on`, `off`, or `only:<64 hexadecimal characters>` (a certificate's SHA-256 fingerprint). Anything else counts as `on`. The installation's row only. Do not read the setting anywhere else.
- **`mail.smtp.certCheckOffAt`** is the moment (UTC, the database's clock) the e-mail page last switched checking off or accepted a certificate. `MailRoutes::setCertificateCheck()` writes both rows in one statement and no transaction (it is also called from inside a send).
- **`MailRoutes::lockedInGeneralEditor()`** names the three settings the general Settings page must not add, change or remove: `mail.smtp.host`, `mail.smtp.certCheck`, `mail.smtp.certCheckOffAt`. `web/_apps/settings/save.php` asks it in the edit and the add branch, and `web/_apps/settings/index.php` in the delete and when it draws a row. Not locked, and so still a way round on that page: the port, the user name, the kind of encryption and the password.
- **The setting is part of `MailRoutes::settingsMark()`**, so changing it anywhere clears a certificate fault on record and releases the mail held for it.
- **`MailRoutes::standingProblem()` is the ONE place that says whether a fault stands in the way of mail**, and it answers "nothing" unless SMTP is the route in use. Ask it before sending, stopping or holding anything (`Mailer::send()`, `MailQueue::send()`, `MailQueue::sendOneHeld()`, `MailQueue::health()` all do). `certificateOnRecord()` and `certificateProblem()` say what is ON RECORD for the SMTP mailbox, whatever the route: they are for showing, never for deciding whether mail goes. `noteResult()` holds queued mail for a certificate only when SMTP is the route in use, and `MailQueue::send()` puts back anything still held "for the certificate" while another route is in use.
- **The record made by a test under another route does not survive the switch to SMTP**: the route is part of `settingsMark()`, so it is cleared like any record made under other settings, and the first message finds the fault again on a checked connection.
- **What is stored outlives a change of route.** `standingWarning()`, the "switch back on" button and `recheckCertificate()` all act on what is stored, never on which route is in use. `MailQueue::send()` calls `recheckCertificate()` first, before it asks whether the route in use can send.
- **`MailRoutes::recheckCertificate()`** asks the SMTP mailbox's mail server once a request, and at the start of every `MailQueue::run()`, whether its certificate would pass now; when it would, checking goes back on (`MailCertificateCheckBackOn` in the activity log, under no account). In `sendSmtp()` it is called BEFORE the route is opened: switching back on forgets the open route and wipes its password.
- **`MailRoutes::standingWarning()`** is what the three pages show. `line` is for a global administrator; `publicLine` names nobody and is what the health line and an organisation's administrator get. The moment is the one stored beside the setting. An administrator is named only when the newest of the four log lines (`MailCertificateAccepted`, `MailCertificateCheckOff`, `MailCertificateCheckOn`, `MailCertificateCheckBackOn`) is the same change as what is stored: the same kind, the same fingerprint, and within 60 seconds of the stored moment.
- **One message past the check**: `MailQueue::sendOneHeld($db, $queueId, $fingerprint)` → `handle()` → `Mailer::deliverQueued()` → `MailRoutes::sendSmtp()` with `onlyCertificate`. Getting through never clears the fault on record (that would release every held message). One case does clear it: the send is refused because the server shows another certificate, and that other certificate passes the full check (`noteResult()` asks once). `sendOneHeld()` then puts the one message back in the queue with the rest.
- **`MailRoutes::saveSmtp()`** treats a changed name OR port as a changed server: the password must be typed again (or removed), and checking goes back on from "off" and from "one certificate accepted".
- **The page**: `web/_apps/admin/integrations/_mail-queue.php` is loaded by `email.php` and has no address of its own. Its seven actions go through the ONE chain at the top of `email.php` (form token, known action, global administrator). Only `queue_send_past_check` is refused for a route other than SMTP; the three that change the SMTP mailbox's certificate setting are allowed under any route. Do not add an action beside that chain. Every moment on the page goes through `MailQueue::shown()`, in one zone: `tblSites.timezone` when it is set to something other than `UTC`, otherwise PHP's zone (which start-up takes from the `site.timezone` setting).
- **A failed message is not sent again from the page**: a failed row no longer says whether it might already have gone.
- **The mail library**: `SendOptions::certificateNotChecked()` and `CertificateReader::wouldPass()`. No fourth connection mode was added; see the header of `Smtp/Connection.php`. Its stand-in mail server can now be told which certificate to show from a file (`certNamedIn`).
- Test: `tools/mail-queue-pages-selftest.php` (118 checks; MySQL 8.0.36 and 8.4.11). Part R1 uses a stand-in for Microsoft 365 on a second port (`SELFTEST_MAIL_PORT`, default the web port + 1), and points the two addresses the Microsoft 365 route calls at it in the scratch copy of `Mailer.php`, as `tools/sign-in-limits-selftest.php` does.

### The library itself (part one)

- **Where it is.** Code in `web/_core/maillib/` (`autoload.php` and 15 classes under `src/`); its README and tests in `tools/maillib/`. Two folders because only `web/` deploys and the zip package allows documents in one named place. Lifting it out means copying both, keeping their places; the whole test passes from such a copy.
- **The rule that keeps it liftable, and its check.** Nothing under `web/_core/maillib/` may reach for the portal. `tools/maillib/tests/stands-alone.php` reads each file as PHP's own tokens and works from a list of what the library MAY use: every function it calls and class it names must be on the list or the library's own, and nothing may be reached by a name worked out at run time (no `$f()`, no `new $class`, no `$$name`). It proves itself on 44 planted files first. `tools/audit-checks/check_maillib_stands_alone.py` only starts it, so it needs PHP. **A new PHP function used in the library fails the check until it is added to the list in that file.** What it cannot see is said in its header.
- **Run its test:** `php tools/maillib/tests/run.php` (PHP with OpenSSL, plus Python 3 for one proof; no database). It starts stand-in mail servers on 127.0.0.1, makes its own certificates for the run, always runs the library with `proc_open`, `popen`, `exec`, `shell_exec`, `system`, `passthru`, `mail` and `fsockopen` switched off, and ends with "the run reached its end: 74 of 74 proofs passed". Three proofs run in a PHP of their own: one with `idn_to_ascii` switched off, one limited to 32 MB of memory, one with OpenSSL told (for that process only) that it may speak TLS 1.1. If the test is killed, its stand-in servers stop and remove the scratch folder and its keys within about a second. It is not run on GitHub yet (the owner agreed on 12 October 2026 that it should be; the workflow file is added with the other approved workflow changes).
- **Four outcomes, and one trap.** A send ends `accepted`, `try-later`, `refused` or `needs-person`. A refused SIGN-IN is `refused` at stage `AUTH`: that is about the mailbox password, not the message. Whoever wires the queue (M3) must ask `isAboutTheMessage()` before dropping a refused message.
- **When the certificate is blamed.** Only when PHP says it refused the certificate, or the library's own name rule did. PHP's words for that, on 8.4 and 8.5, are listed beside `PHP_BLAMES_THE_CERTIFICATE` in `Connection.php`. A handshake that is cut off says nothing at all, and used to be read as a bad certificate. If a future PHP words a refusal differently, the library reports "encryption failed, try later" and its test fails on that PHP: bring the list up to date.
- **PHP obeys the TLS method given in the call, not the one in the settings.** `stream_socket_enable_crypto($stream, true, $method)` decides; `crypto_method` in the stream's settings is ignored for a handshake started that way (tried against a TLS 1.1 server on 8.4 and 8.5). The library takes both from one place. To prove a TLS floor at all on OpenSSL 3, OpenSSL's own floor has to be lowered first, or OpenSSL refuses before the code under test is asked: the test does it for one process with an `OPENSSL_CONF` file (`MinProtocol = TLSv1`, `CipherString = DEFAULT:@SECLEVEL=0`), and first shows that a plain PHP stream then really does connect with TLS 1.1.
- **PHP 8.5 accepts server names PHP 8.4 refuses.** 8.5.10 falls back to a certificate's old "common name" when its list of names is for another server; 8.4.26 does not. Both accept a star inside a label (`m*.example.org`) and a bare `*`. The library's own rule (RFC 7817 section 3) decides as well, so the answer does not depend on the PHP version.
- **Memory.** About five times the content, measured (53 MB in all for one 10 MB attachment). `Message::needRoomFor()` refuses in words; it cannot when PHP has no limit or `ini_get` is switched off.
- **Learned while building (PHP 8.5.10, OpenSSL 3.6.3):** over TLS a non-blocking write to a server that has stopped reading returns 0 and never hangs, so writing in pieces against a clock holds its limit (measured 2.1 s against a 2.0 s limit); a write to a peer that has hung up also returns 0, not a failure, so a hang-up has to be asked about with `feof()`; OpenSSL 3 itself refuses TLS 1.1 unless its security level is lowered.
- **A reader that forgives too much proves too little.** The test reads every sent message back with Python's e-mail parser, because it shares no code with the library. But that parser re-joins a letter that was cut in half across two "encoded words", so it showed the right name for a library that was cutting letters in half. Found by planting that fault and watching the test still pass. The test now decodes each encoded word by itself. When adding a check that reads output back through another tool, ask what that tool quietly repairs.
- **And the same reader differs between its own versions.** Python 3.13.5 puts a space between two adjacent encoded words in a display name; Python 3.14.7 does not. Names written as encoded words are now read from the header itself, word by word, which gives one answer everywhere.
- **A test that never leaves UTC cannot see a time-zone fault.** The Date line written with the local clock but marked "+0000" passed the whole first test. The test now sets four zones that change their clocks, finds both change nights from each zone's own rules counted from today, and reads the Date back as a moment.
- **PHP's `intl` extension is optional for the library.** With it, a domain in non-English letters is converted to its ASCII form. Without it, such an address is refused. The test proves the second case on every machine by starting PHP with `idn_to_ascii` switched off.
- **Psalm was run by hand on the first build only** (5.26.1: no errors, seven warnings left on purpose). It was NOT run again after the fix.

---

## Text messages held for quiet hours (#610)

- **One place.** `Portal\Core\Sms` holds every statement that moves a held text. `send()` holds; `sendHeld()` (called only
  by `web/_apps/cron/mail-queue.php`, after the mail step) sends or settles; `holdRestored()` is what a restore calls.
- **What is never held** is `Sms::NEVER_HELD`: `critical_alerts` and `emergency_comms` (the owner, 13 October 2026). That
  list is the one place that decides it. Adding a kind to it is a decision for the owner.
- **The states of a held text**, all in `tblSmsMessage`: held is `status = 'queued'` with `errorMsg = 'quiet-hours'`
  (`Sms::HELD_REASON`), an empty `sentAt` and a `heldUseBy`; taken is the same with `claimedAt` filled in; then `sent`, or
  `failed` with plain words in `errorMsg` (the `Sms::NOT_SENT_*` sentences, `SENT_AFTER_HOLD`, `UNKNOWN_INTERRUPTED`). No
  new status word was added.
- **The claim** is one statement that fills `claimedAt` only while the row is held, untaken and inside its use-by moment. A
  claim is never undone. A row a dead run took is recorded as "not known whether it was sent" after ten minutes, by the job
  or by the next text the portal handles.
- **Who may still be sent a held text** is asked when it comes due, in `Sms::whyNotThisPerson()`. The rule is not written
  there: with several organisations it is `Site::isOwnOrganisation()` (the list the membership gate uses); with one
  organisation, where nobody needs a membership row, it is whether the account is switched on. If Site cannot answer, the
  run stops with the text still held and untaken: a text is not sent on a guess. **A text sent at once IS asked, since the owner's decision of
  26 October 2026 landed** (#610): `send()` puts the same question as `sendHeld()`, through
  `whyNotThisPerson()`, to every text that names a person, and a person is judged at the moment their text would go —
  now for a text going now, when it comes due for a held one. Asking BEFORE the hold was built first and silently
  refused a text for anybody suspended and reinstated inside two days, which is why the moment matters.
  **This paragraph said the opposite** — "a text sent at once is not asked", with the gap "still to land" — until
  27 October 2026; see the list at the top of `web/_core/Sms.php`, which records the same change.
- **Times.** `heldUseBy` and `claimedAt` are moments in UTC from `UTC_TIMESTAMP()`. `createdAt` and `sentAt` are still the
  database server's own clock, and the daily limit's "day" is a day on that clock. "Is the person in their quiet hours?" is
  asked of `Sabbath` for a moment read from the database (`SELECT UNIX_TIMESTAMP()`), when holding and when sending.
- **Use-by lengths** are in `Sms::HELD_USE_WITHIN` (48 hours for the kinds listed) and `HELD_USE_WITHIN_UNKNOWN` (two hours
  for a kind the portal does not know); a caller can give its own as the sixth argument of `send()` (a moment in UTC, as
  `MailQueue::useBy()` makes one). `HELD_NEVER_AFTER` (48 hours) is the ceiling.
- **The job page's answer** stays one JSON document of a status word and plain numbers; the texts step's numbers are the
  entries whose names begin `texts`. `tools/mail-queue-selftest.php` holds the answer to "numbers only".
- **The broadcast page's sentence** is built by `Sms::broadcastSummary()`, so that it can be tested. `skipped` in what
  `sendCategory()` answers still includes the held texts; the sentence takes them out. `MailQueue::jobIsRunning()` means
  "the job ran in the last 15 minutes" and no more, so neither that sentence nor the preferences page ever says a held text
  will NOT be sent. That wording was built the other way first ("and NOT going to be sent afterwards"), and the second
  check proved it untrue in three ordinary cases: a job that runs hourly, a job running late, and a job set up the next
  day each handed the held texts over at its next call. An administrator told the texts would not be sent sends the
  broadcast again, and people get two texts — the very thing the sentence exists to stop.
- **Database not upgraded yet:** `send()` records the text as not sent (it is never held without a use-by moment) and
  `sendHeld()` answers `notReady`.
- **Test:** `tools/held-texts-selftest.php` (106 checks; MySQL 8.0.36 and 8.4.11). It sets the database's own clock for its
  connection (`SET timestamp`) and puts a recorder where the provider is by extending the class (`providerSend()` is
  `protected` for that reason). It reads the real lines of the Notification preferences page in both states of the job, and
  the sentences on Account, SMS and the broadcast page; and it holds every sentence the class records to a copy written out
  in the test (`SAYS`). Change a page or a sentence and the test together.
- **What it cannot do** is listed at the top of `Sms.php`.

## The portal's own QR pictures (#623)

- **One place.** `Portal\Core\Qr` is the only place a QR picture is made, and `Portal\Core\QrEncoder` is its own
  helper. Nothing under `web/` asks another website to draw one. The owner means to hand the drawing to their separate
  CueRCode project later, so keeping it to one file is what makes that a one-file change.
- **The fault that hid for months.** `QrEncoder` wrote the fifteen format marks — the squares naming the
  error-correction level and the mask — back to front. ISO/IEC 18004 figure 25 gives the order. A reader reads those
  marks before anything else, so it never reached the data, and EVERY picture the portal drew was unreadable by every
  reader. Nothing caught it because a wrong picture is still a picture: it draws, it is the right shape, and no code
  path fails. Only reading a picture back catches this, which is why `tools/qr-test-reader.php` exists.
- **Never put a secret in a web address.** `Qr::dataUri()` writes the picture into the page, and that is the only way to
  show a picture of something secret. A picture fetched from `/qr?content=...` puts the text in a web address, and web
  addresses end up in server logs and browser histories. `Totp::getQrUrl()` built a `chart.googleapis.com` address
  carrying the person's authenticator key; it is removed, and must not come back.
- **A page that writes its own `<head>` sends no page rule.** The two-step set-up page and the public event page made
  the same mistake, and it was INVISIBLE on one and live on the other: the portal's rule refused the image on the
  two-step page, so nothing appeared, while `calendar/public-landing.php` sends no such rule and the request to Google
  really was made on every view. When judging whether an outside request can happen, check whether that page includes
  `templates/header.php`.
- **Tests.** `tools/qr-selftest.php` (67 checks, or 76 with `--with-qrencode`) reads every picture back with
  `tools/qr-test-reader.php`, a reader written only for the tests, and compares against 23 kept `qrencode` pictures in
  `tools/fixtures/qr/`. The reader was proved against 316 `qrencode` pictures first, at every size and level and at the
  exact edge of each, before it was pointed at our own drawing — otherwise it could simply have agreed with our own
  mistake. The independent check went further and derived the standard's BCH format and version values by hand.
- **What is NOT proved:** `qrencode` is not installed on every machine (so `--with-qrencode` may not run), PHP 8.4 was
  not available for the check, and nobody has scanned a printed label with a real camera.
- **Where gd is missing** the drawing falls back to SVG, and every caller sends the `mime` it is given back.

## Scripts the browser refused, and the form token a long-lived page spends (#625)

- **Every inline script needs the answer's own nonce.** `App::cspNonce()` makes one per response and
  `templates/header.php` sends it in the page rule. A script written into a page without it, or a handler written on an
  element, is refused by the browser and NOTHING is logged on the server: the page looks finished and the control does
  nothing. That is why this went unnoticed in 63 files.
- **`tools/audit-checks/check_inline_scripts_have_nonce.py` is the tripwire**, with an exemption list of 20 files in
  four groups (pages that send no rule, pages that send their own, files that are not portal pages). Every reason on
  that list was read and verified by the independent check. Three shapes it CANNOT see are named in its own header: a
  nonce reused across answers, an unclosed HTML comment, and `setAttribute('onclick')` from a script file.
- **Prefer an attribute to a handler.** `data-portal-submit-on-change` and `data-portal-select-on-click` are read by
  `web/public_html/assets/js/portal-actions.js`, loaded once from the footer. A new page should use those rather than
  writing a handler or a fresh inline script.
- **A LONG-LIVED PAGE AND THE ROTATING TOKEN.** `Auth::verifyCsrf()` REPLACES the token every time it accepts one, so
  a page that writes more than once without reloading must be handed the replacement and must keep it. The house way is
  `ApiAuth::requireWrite()` with `ApiResponse`, which puts `csrfToken` in its `meta` block for exactly this reason.
  `api/worship/advance.php` does it by hand, returning `csrfToken` on the successful answer AND on every refusal
  reached after the check has rotated the token — otherwise one mistaken press ends the console's session.
- **Four invariants hold the operator console together**, and three of the four were broken by careful people inside
  twenty-four hours while this was being built, so they are written here as well as in the file: the token variable is
  NOT `const`; only one command is in flight at a time; the deadline is armed across the body read, not just the wait
  for headers; and the view's poll cannot pile up. Why a queue rather than independent requests: the commands are
  ordered, and more fundamentally two commands in flight can never both succeed, because the token rotates on every
  acceptance.
- **A deadline must cover reading the answer.** A `fetch` promise settles when the HEADERS arrive, so clearing the
  timer in `.finally()` on it leaves the body read unguarded — measured at a full 25 seconds against a stalled body.
  Hold the controller across the body read.
- **Say when a command fails, and say only what is known.** "Not sent" is untrue when the request was carried out and
  only the answer was lost. The console says the command may or may not have gone through, and points at the view.
- **Eight other handlers have the same spent-token fault** (worst: `geo/w3w-suggest.php`, which posts once per 400 ms
  of typing, and `auth/account/webauthn.php`, which posts twice per click). Their own issue.

## The activity log and sessions (#622)

- **ONE place for each thing, both in `web/_core/Logger.php`.** `Logger::sessionFingerprint()` is the only thing that
  may be stored in place of a session identifier (`Logger::fingerprintOf()` is the rule: `sha256:` and the SHA-256 of
  the identifier). `Logger::sessionFacts()` is the only copy of a session a record keeps: six named facts, whole
  numbers and true/false only. To add a fact, add it there on purpose and say who reads it. Never go back to
  "everything except a list".
- **Never store `session_id()`.** `tools/audit-checks/check_session_id_not_stored.py` fails on it, and on any use of
  the whole `$_SESSION`, outside its two short lists.
- **The fingerprint is plain SHA-256 on purpose.** The clean-up of old rows makes the same value in SQL
  (`CONCAT('sha256:', SHA2(sessionID, 256))`), where the key file cannot be read. The monthly sign-ins report
  (`_apps/admin/reports/data.php`) joins on this column, so old and new records of one session must match.
- **Migration 220 does only quick things**: three column comments, the consent log, the mark for an empty log, and the
  delete of old backup codes. The delete runs only while the migration's own row is absent from `tblMigrations`: that
  row is the "already done" mark. `full_schema.sql` writes that row ONLY where no backup code exists, and writes
  `activityLog.cleanedThrough = all` ONLY where the activity log is empty, because the installer's "carry on" choice
  runs the install script over older databases (the #604 trap).
- **`Portal\Core\ActivityLogCleaner` cleans the old records**, from the Upgrade page, in pieces of 5,000 log numbers,
  for about 20 seconds a press. One piece is one transaction: find authenticator keys and write each account's record,
  then fingerprint and blank, then move "how far" on. Only one runs at a time (a named database lock). It is not part
  of the upgrade gate: the version is recorded and the portal opened before it starts.
- **Its two settings** are `activityLog.cleanedThrough` (a log number, or `all`) and `activityLog.keyLines` (the log
  numbers of the key records). They travel with a backup, so an older backup put back is cleaned again from the start.
- **WHAT A ONE-TABLE RESTORE DOES TO MIGRATION 220'S MARK.** "Once" is the migration's own row in `tblMigrations`.
  Nothing on an ordinary page can touch that row. Admin, Maintenance, Backup can put ONE table back on its own, and
  **it now REFUSES all three of `tblMigrations`, `tblTotpBackupCodes` and `tblSettings`** — `DbBackup::WHOLE_DATABASE_ONLY`
  (`web/_core/DbBackup.php`), asked by `isWholeDatabaseOnly()` and worded by `wholeDatabaseOnlyReason()`; the page draws
  "Whole-snapshot restore only" in place of the form and refuses a posted request as well. **This paragraph used to say
  it refused none of them**, which was true when it was measured and stopped being true when that guard landed; it is
  kept because what was measured is exactly why the guard exists. Measured
  by the independent check on 26 October 2026, on a database that had really been upgraded: `tblMigrations` alone from
  an older backup makes the next press of the Upgrade page delete every backup code made since (1 then 0);
  `tblTotpBackupCodes` alone brings used codes back alive and the migration leaves them (2 then 2); `tblSettings`
  alone loses `activityLog.keyLines`, so the health page's count goes to 0 for good. **A WHOLE restore is safe in both
  directions, because the row travels with it.** The hazard belongs to one-table restores in general, not to this
  migration — ANY migration whose "once" test is its own row is reachable the same way. A guard is being built
  separately (the owner, 26 October 2026).
- **Measured** (13 October 2026, MySQL 8.0.36, 500,000 records, about 1 GB): one statement 176 s; the cleaner 60 to 90 s
  over 3 or 4 presses, slowest piece 1.3 to 1.8 s; migration 220 itself 5.5 s (almost all of it 19,600 consent rows),
  0.6 s on a second run.
- **Why the self-test's made-up log is dense in one place.** A piece covers `[$from, $from + PIECE - 1]`. The older
  part's records were sparse, so no boundary ever landed on a record that exists, and a one-character fault (`$to + 1`
  for "how far") passed 72 of 72 while leaving one record uncleaned at every boundary — for ever, and reported as
  finished. The test now builds `PIECE + 3` records at consecutive numbers and reads the database back.
- **The self-test refuses to search the log for an empty text.** A pattern that had outgrown the two-step set-up page
  read the authenticator key as nothing, and `logHolds('')` matched every record, so two checks passed while proving
  nothing. A check that passes without proving anything is worse than one that fails.
- Tests: `tools/activity-log-selftest.php` (77 checks); `tools/e2e-migrations/run.sh` phases 1 and 7, with a coda at
  the end of phase 7 for the one case where "no backup code exists" and "the log is empty" disagree.

## Who may be sent a text, and when that is asked (#610, follow-up)

- **One rule, asked at two moments.** `Sms::whyNotThisPerson()` is the only place that decides whether a person may be
  sent a text. It answers a REASON (`''`, `no-account`, `account-off`, `not-member`, `org-off`), and two translators turn
  that into words: `heldPersonWords()` for a held text coming due (the `NOT_SENT_*` sentences, "When they ended, …")
  and `atOncePersonWords()` for a text refused as it is sent (the `AT_ONCE_*` sentences, present tense, no mention of
  quiet hours). It used to return the sentences themselves, which is why only a held text could use it. **Never write
  the reason into the record**: an administrator would read `account-off`.
- **JUDGE A PERSON AT THE MOMENT THEIR TEXT WOULD GO.** `send()` asks AFTER the decision to hold, so only a text about
  to go is asked; a held text is asked by `sendHeld()` when it comes due. This was built the other way round first, on
  the stated grounds that holding would "only put off recording the same answer by up to two days" — which is FALSE,
  because the held path asks when the text comes due, not when it was written. The independent check measured the cost:
  an account switched off on the Friday and back on by the Saturday had its text refused outright, where the portal had
  previously held it and then delivered it. Part M9 of the test is that case.
- **Asked of every kind**, the `NEVER_HELD` ones included, and skipped only when no person is named (the caller chose
  the number and there is no account to judge).
- **A refused text has no "sent at"**, because it was never handed over; the list of recent texts shows that column.
  **And `record()` keeps the row but drops the NAME when the database refuses one who has gone** (`fk_sms_user` is
  `ON DELETE SET NULL`). That rescue lives in `record()` and nowhere else, because it is the one place that writes such
  a row. It matters for a narrow but real window: a broadcast holding an account number it read moments before the
  account was deleted. There is no such thing as a stored number with no person — `tblUserSmsPreference.userID` is NOT
  NULL and cascades.
- **What it still cannot do:** say whether the number is still that person's. That is `whyNoLongerWanted()`, asked only
  of a held text, where up to two days have passed.
- Test: `tools/held-texts-selftest.php`, 117 checks on MySQL 8.0.36 and 8.4.11.

## Tables a one-table restore refuses (#622 follow-up)

- **`DbBackup::WHOLE_DATABASE_ONLY` is the ONE list**, with a plain-words reason beside each name;
  `isWholeDatabaseOnly()` is the one comparison and `wholeDatabaseOnlyReason()` the one place the words are built. The
  Backup page asks both — to leave the three out of its per-table buttons, and to turn a posted request away before the
  portal is closed — and keeps no copy of the names.
- **The comparison ignores capital letters on purpose**: whether MySQL treats two spellings as one table is a server
  setting (`lower_case_table_names`) and differs between a Linux host and a developer's Mac. Being stricter than the
  server is safe; being looser is not.
- **The test before adding a fourth table:** if this table alone went back to how it was, would the rest of the
  database still make sense beside it? If the answer depends on what has happened SINCE the snapshot — an upgrade, a
  code being spent, a counter moving on — it belongs on the list.
- **Why these three, measured rather than argued.** `tblMigrations` alone makes the portal believe a finished upgrade
  is still waiting, so it closes itself and runs that upgrade again: that destroyed a two-factor backup code made after
  migration 220 (1 then 0, on both MySQL versions). `tblTotpBackupCodes` alone brought two spent codes back usable, and
  usable they stayed. `tblSettings` alone brought back a row migration 217 had deleted, and the re-run overwrote an
  organisation's time zone chosen after that upgrade.
- **A whole-database restore is untouched** and still puts all three back value for value; it is the supported route.
- **Refused before anything happens:** measured at not one database statement and no file opened. Asked with a snapshot
  name that does not exist, the answer still names the TABLE — which is how the page test proves the ordering, after
  its earlier check was found to claim that proof without making it.
- **One consequence, recorded not worked around:** a `tblSettings` snapshot from before migration 187 now has no
  realistic way back, because the whole restore already refused those snapshots and mending the file by hand would mean
  recomputing its SHA-256 in `_manifest.json`.

## MS365 Graph email via a shared mailbox (gap #234, migration 176)

### What was already there vs what #234 actually needed

`Mailer::sendViaGraph()` already did app-only client-credentials Graph
auth and posted to `/v1.0/users/{mail.defaultFromAddress}/sendMail` — i.e.
the portal was already sending through what is, mechanically, a shared
mailbox. What the issue's acceptance criteria actually needed was
**formalising and hardening** that path, not a new auth model: an
explicit shared-mailbox identity separate from the general from-address,
a `from` object in the payload (previously entirely absent — Graph
derived the sender purely from the URL mailbox), proper 401/429/403/404
handling, and a persisted send log. See
`.claude/plans/gap-items/234-shared-mailbox-plan.md` for the full
build-ready plan this session implemented from.

### Model decision: app-only, NOT delegated (recorded per the issue's Q1)

The issue body sketched a delegated `Mail.Send.Shared` model — a real
licensed user's OAuth token with Exchange "Send As"/"Send on Behalf"
permission on the shared mailbox. This was **deliberately not built**:

- It requires an entirely new interactive OAuth authorize/refresh-token
  flow, a new encrypted secret (the refresh token) with its own rotation
  lifecycle, and a dependency on a real human account whose password
  reset, MFA re-registration, or conditional-access policy change would
  silently kill portal mail — precisely the failure class the #227-era
  offboarding work exists to *cause* on purpose when a person leaves.
- Application `Mail.Send` (what was already in place) already lets the
  app send as any tenant mailbox with **zero new secrets** — it reuses
  `Mailer::accessToken()` verbatim. The "it's too broad" objection is
  answered by an **Application Access Policy** (or its RBAC-for-
  Applications successor) scoping the app to just the shared mailbox —
  see the runbook in `web/_apps/help/admin.php#ms365-shared-mailbox`.
- `sender` (the Graph field for send-on-behalf) is therefore never set
  anywhere on this path — only `from` is, which is what app-only send-as
  actually uses.

If a tenant ever refuses to grant application `Mail.Send` even with an
Application Access Policy, the delegated model remains a well-scoped
follow-up issue — nothing in this design blocks adding it later as a
second sub-mode alongside the shared-mailbox one.

### `effectiveSender()` — the one place the send identity is resolved

`Mailer::effectiveSender()` (private) is the single resolution point,
called only from inside `sendViaGraph()`:

1. `mail.ms365.sharedMailbox` non-empty AND `FILTER_VALIDATE_EMAIL`s →
   that address is BOTH the URL mailbox and the `from` address; display
   name from `mail.ms365.sharedMailboxName`, falling back to
   `mail.defaultFromName`.
2. Non-empty but invalid (someone hand-edited it via the generic
   `/admin/settings` editor, bypassing the save handler's own
   `FILTER_VALIDATE_EMAIL` check) — log `BAD_SHARED_MAILBOX` once via
   `Logger::errorPlatform()` and fall through to rule 3, so a typo can
   never take portal mail down entirely.
3. Empty (the seeded default) — `mail.defaultFromAddress`, byte-for-byte
   the pre-#234 behaviour. Returns `null` when that's empty too, and the
   caller throws `RuntimeException('From address missing')` exactly as
   before.

**Security-critical property:** `effectiveSender()` reads `$SETTINGS`
only — nothing on this path ever touches `$_POST`/`$_GET`. The ONLY
writer of `mail.ms365.sharedMailbox` is the CSRF'd, admin-gated
`admin/integrations/ms365-mail-save.php` handler. Grep-verify at any
future touch of this code: `grep -rn "ms365.*sharedMailbox" web/_apps/`
should show exactly one write site.

### Why the `from` object is added in BOTH modes (a deliberate, benign behaviour change)

Before #234, `Mailer.php`'s Graph payload had NO `from`/`sender` key at
all — Graph derived the sender purely from the URL mailbox, and
`mail.defaultFromName` was silently unused on the MS365 path (only the
Google path, `MailerGoogle.php:149`-ish, ever honoured it). Adding
`message.from` in the legacy/direct mode too — not just the new
shared-mailbox mode — means `mail.defaultFromName` finally takes effect
everywhere. This is flagged explicitly in the CHANGELOG as a visible-but-
benign change: recipients now see whatever display name is configured,
where before they saw the mailbox's own Exchange display name.

### Error matrix — why each threshold is what it is

| Condition | Behaviour | Why |
| --- | --- | --- |
| 401 | Clear `self::$token`, retry once | The static per-request token cache can outlive the token's actual server-side validity (revocation, secret rotation) — one fresh-token retry costs nothing and fixes the common case. A second 401 falls through to the fail path — never loop. |
| 429 | Read `Retry-After`; retry once ONLY if ≤5s | This runs synchronously inside a web request on **shared hosting** — an unbounded or long sleep would hold a PHP-FPM worker hostage. A short bounded wait is worth it; anything longer fails the same as any other error and relies on the caller's own retry/queue semantics (or a human re-clicking Send Test Email). |
| 403 `ErrorAccessDenied` / 404 `ErrorInvalidUser`\|`ErrorItemNotFound` | Parse Graph's own `error.code`, surface it | These almost always mean "mailbox outside the Application Access Policy scope", "mailbox deleted", or "consent missing" — a bare HTTP code tells the admin nothing; Graph's own machine-readable code tells them exactly what to check in the runbook. |
| 5xx / cURL failure | Fail path, no special retry | Graph outages are Microsoft's problem, not a shape this codebase should paper over with more retries on shared hosting. |
| Any failure, `mail.fallbackProvider==='google'` | One attempt via `MailerGoogle::send()` | Opt-in only (default `''`) — a silent provider failover changes the visible sending identity, which can break SPF/DKIM/DMARC alignment for anyone actually checking headers. An admin has to choose this explicitly. |

### `tblEmailLog` — shared by both providers, fail-soft by design

`Mailer::logSend()` is `public` (not `private`) specifically so
`MailerGoogle::send()` can call it directly on its own success/failure
branches — there is deliberately no per-provider duplicate of this
table or its writer. The whole method body is wrapped in try/catch with
`error_log()` on failure: a logging bug must never be the reason a real
send that already happened (or already definitively failed) throws an
uncaught exception back up through the caller. Retention (`mail.log.
retentionDays`, default 90) is pruned opportunistically on roughly
1-in-50 writes (`random_int(1, 50) === 1`) — the same "no dedicated cron
needed" trick used elsewhere in this codebase for low-stakes housekeeping.

`toRecipients` is a comma-joined free-text column, not a normalised
per-recipient table — deliberately, since this is an audit trail, not a
queryable-by-recipient index; the 10-row "Recent sends" admin list and
GDPR erasure (below) are the only two consumers and neither needs it
normalised.

### GDPR erasure — why this is a bespoke step, not a `catalogue()` entry

`GdprEraser::catalogue()`'s generic delete/anonymise actions are built
around ONE `userCol` FK per table. `tblEmailLog.toRecipients` is a
comma-joined address LIST — a single send can name several recipients,
and the column holds free-text addresses, not a `userID`. So instead of
a catalogue entry, `GdprEraser::eraseEmailLogRecipients()` does a
targeted `REPLACE(toRecipients, ?, '[erased]') WHERE toRecipients LIKE
?` — the row, provider, status, subject, and httpCode all stay (this is
an audit trail of SENDS, not of the recipient), only the address itself
is detached. This mirrors the `eraseGivingStatementFiles()` /
`tblGivingStatementLog.emailedTo` precedent immediately above it in
`GdprEraser.php` (#440 Q5) — "PII column scrubbed, row + other columns
retained for the audit trail".

The address has to be captured at the TOP of `execute()`, before the
catalogue is walked — the catalogue's own LAST entry anonymises
`tblUsers.emailAddress` to NULL, so by the time a bespoke post-catalogue
step (like the Giving-statements sweep) runs, the address is already
gone. `execute()` now does a plain `SELECT emailAddress FROM tblUsers
WHERE userID = ?` up front and threads that string through to the new
sweep at the same point the Giving-statements sweep already runs.

### Admin UI — closing the test/production drift permanently

`admin/integrations/index.php`'s "Send Test Email" button used to
duplicate the ENTIRE token-acquisition + sendMail cURL flow inline
(~150 lines) rather than calling `Mailer::send()`. That meant the test
could stay green while the real path silently drifted — exactly the kind
of gap #234's own audit surfaced. `sendTestEmail()` now calls
`\Portal\Core\Mailer::send()` directly and reads back the just-written
`tblEmailLog` row for its diagnostic detail (provider/httpCode/
errorCode/errorDetail) — whatever this button proves now also proves the
real send path, shared-mailbox mode included. The standalone "Test Token
Acquisition" button keeps its own separate inline flow deliberately — it
tests ONLY credential validity, a genuinely different diagnostic that
doesn't attempt a send.

### Fold-in fix — the dead `email.*` settings vocabulary

`admin/integrations/email.php` (#230's deliverability page) was reading
`email.provider` / `email.from` — settings seeded in `full_schema.sql`
but never written by any save handler anywhere in the codebase — so it
permanently reported "smtp" regardless of what was actually configured. (Since #592 part two `Mailer::provider()` gives the route `send()` will really use, and those two settings are deleted by migration 213.)
Fixed to read `Mailer::provider()` + the same effective-sender
resolution order as `effectiveSender()` (duplicated as a 5-line
display-only mirror, since `effectiveSender()` itself is `private` to
`Mailer`). The two dead `email.*` seed rows are left in place
deliberately — removing a seed needs its own cleanup migration, which is
out of scope here and not worth it for two harmless unread rows.

### Owner runbook (Azure AD / Microsoft 365 admin)

Full step-by-step lives in `web/_apps/help/admin.php` (anchor
`#ms365-shared-mailbox`) and is linked from `/admin/integrations`. Summary:

1. Create/identify the shared mailbox in the M365 admin centre — no
   licence needed.
2. Confirm the existing app-wide registration already has Application
   `Mail.Send` with admin consent (if portal email already works today,
   this is already done).
3. **Recommended:** scope it with `New-ApplicationAccessPolicy` (legacy
   but functional) or RBAC for Applications (successor) — otherwise the
   app token can send as any tenant mailbox, not just the configured one.
4. Set the shared mailbox + display name at `/admin/integrations` → Save
   → Send Test Email.
5. Deliverability note: mail leaves via Microsoft's own infrastructure,
   so the tenant's standard SPF/DKIM/DMARC records (not the web server's
   IP reputation) are what need to be correct — the Email Deliverability
   page's DNS probe checks them against the effective sender's domain.

---

## Location / Geocoordinates / What3Words platform — Chunk A + B (#456, migrations 180-181)

Cross-repo data-format CONTRACT shared with ProjectBookIT/ProjectEPass —
**standalone principle**: every repo builds its own `GeoLocation`/
`What3Words`/`Geocoder` classes and reads its own settings; no repo ever
calls another at runtime for a geo function. The CONTRACT is a shape
agreement only (column types, the canonical `location` JSON wire object,
what3words canonical form) — see the class docblocks in `_core/
GeoLocation.php` / `What3Words.php` / `Geocoder.php` for the full contract
text.

### What3Words key-in-query-string security note

Unlike every other Bearer-style adapter in this codebase (Zoom,
CloudflareStream), the what3words v3 API takes its API key as a
**query-string parameter** (`?key=…`), never a header. `What3Words::
request()` is the ONLY place `w3w.apiKey` is read; the key is appended via
`http_build_query()` (rawurlencoded exactly once, never concatenated raw)
and the `$url` local variable that embeds it must NEVER appear in a
`Logger::errorPlatform()` call, an exception message, or a return value —
every failure log line carries only `path=… httpCode=…`. Grep-audit gate:
`grep -rn "w3w.apiKey\|geo.google.apiKey"` across `web/_apps` + `web/_core`
should show it ONLY in the two adapters (`What3Words.php`, `Geocoder.php`)
and the two admin integration pages (index + save) — nowhere else, ever.

### Nominatim usage policy compliance

`Geocoder`'s Nominatim leg is built to comply with OSM's usage policy from
the first commit, not bolted on later:

1. **Descriptive User-Agent** — `Site::productName() . '/' . PORTAL_VERSION
   . ' (' . $contact . ')'`, where `$contact` resolves `privacy.
   contactEmail` → `mail.defaultFromAddress` → the literal `'admin contact
   unset'`. Sent via `CURLOPT_USERAGENT`.
2. **≤1 request/second throttle** — the last-call timestamp is persisted
   in the `geo.nominatim.lastCallAt` setting (global, `siteID IS NULL`);
   `Geocoder::throttle()` `usleep()`s the remainder up to a hard cap of
   1.2s (bounded — at most one geocode call happens per request, so this
   never compounds into a slow page).
3. **Cache-once** — `tblGeocodeCache` (global, no `siteID` — an address
   geocodes identically for every tenant) caches only successes; a given
   address/coordinate pair hits Nominatim at most once, ever.
4. **Attribution** — `location.map_attribution` ("Map data © OpenStreetMap
   contributors") is rendered in every Leaflet tile layer's `attribution`
   option by `location-map-assets.php`'s init script.

Both `What3Words` and `Geocoder` are entirely best-effort: every public
method returns `null`/`[]`/`false` on any failure and never throws, and
both API-enable flags (`w3w.enabled`, `geo.autoGeocode`) default OFF so a
fresh upgrade fires zero network calls until an admin opts in.

### Leaflet 1.9.4 SRI — verification method + upgrade procedure

The two Leaflet CDN tags live in `web/_core/partials/location-map-assets.php`
as literal `<link>`/`<script>` tags (not routed through `Asset.php`'s
helper pattern — this partial is self-contained by design per the feature
spec) with `integrity=` + `crossorigin="anonymous"`, matching what
`check_cdn_sri.py` requires.

**How the hashes were verified (no live fetch of jsdelivr was possible from
the build sandbox — its outbound proxy blocked it):** the exact `leaflet@
1.9.4` npm tarball was fetched from `registry.npmjs.org` (jsdelivr's `/npm/`
CDN mirrors the published npm tarball byte-for-byte — this is documented
jsdelivr behaviour, not an assumption), `dist/leaflet.js` and `dist/
leaflet.css` were extracted, and both sha384 AND sha256 digests were
independently recomputed and compared against the values already pinned in
the feature's build spec. All four matched exactly:

```bash
curl -sS -o leaflet-1.9.4.tgz https://registry.npmjs.org/leaflet/-/leaflet-1.9.4.tgz
tar -xzf leaflet-1.9.4.tgz package/dist/leaflet.js package/dist/leaflet.css
openssl dgst -sha384 -binary package/dist/leaflet.js  | openssl base64 -A   # -> cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH
openssl dgst -sha384 -binary package/dist/leaflet.css | openssl base64 -A   # -> sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H
```

**On any future Leaflet version bump:** re-run the same fetch-and-hash
procedure against the new version's tarball, update both `src`/`href` URLs
AND both `integrity=` attributes in `location-map-assets.php` together
(mismatched pairs silently break the map with no console hint beyond a
blocked-resource CSP-adjacent network error), and re-run `python3 tools/
audit-checks/check_cdn_sri.py --strict`.

**CSP:** `script-src`/`style-src` already allow `https://cdn.jsdelivr.net`
(header.php's hard-coded policy) — no header.php edit was needed. Tiles
need the page to set `$cspImgExtra = 'https://*.tile.openstreetmap.org';`
BEFORE requiring `header.php` (the existing #386 page-scoped CSP-widening
pattern). Leaflet's default marker PNG icons resolve relative to the CSS
URL (jsdelivr) — NOT covered by `img-src` — so the init script builds an
inline SVG `L.divIcon` marker instead of loading Leaflet's default icons,
keeping `img-src` limited to just the tile host.

### `w3w.enabled` semantics (locked decision)

The flag gates ONLY the What3Words API adapter (autosuggest, validation,
lat/lng↔W3W conversion) — the `///word.word.word` input field is ALWAYS
rendered by `location-input.php` regardless of this setting, and a typed
value is always stored and displayed with a working `///` link. This is
the "stored-field fallback" the whole feature is built around: turning the
API off never removes functionality that already existed, it only removes
the convenience layer on top.

### Directory `$can()` "team tier = private" quirk (inherited verbatim)

`directory/profile.php`'s existing `$can()` visibility check treats the
`team` tier as equivalent to `private` for non-admin viewers — a
pre-existing platform behaviour, not something Chunk A/B changes.
`visibilityCoords` (Chunk B, shipped) is gated through the SAME `$can()`
closure, so it inherits this quirk verbatim by construction; fixing it
was out of scope for this feature.

## Chunk B — member PII + GDPR lockstep (#456, migration 181)

Migration 181 adds FOUR columns to `tblUsers` ONLY: `latitude`/
`longitude`/`what3words` (member home coordinates, PRIVATE by default)
and `visibilityCoords` (ENUM, default `'private'`). Deliberately **no
`geocodedAt`/`geocodeSource` pair** — unlike the standard five-column
block migration 180 added elsewhere, a member's own coordinates are
NEVER auto-geocoded anywhere in this codebase (`geo.autoGeocode` never
applies to `tblUsers`); provenance is always "the member themself" via
either hand entry or their own explicit "Look up coordinates" click on
`directory/me.php`, so a provenance column would carry no information.

### `visibilityCoords` is INDEPENDENT of `visibilityAddress`

A member sharing their address TEXT never implies consent to show a map
PIN — a pin is strictly more precise (and more automatable to scrape at
scale) than a postal address string. `directory/profile.php` therefore
runs a SEPARATE `$can($u['visibilityCoords'])` check before rendering
anything coordinate-shaped, entirely independent of the existing
`$can($u['visibilityAddress'])` check for the address text `<dd>`. Both
default `'private'`.

### Non-owner coarsening + what3words suppression (the actual gate)

`directory/profile.php`'s coords block (`portal_location_display()` call,
gated by `$showCoordsBlock = $can($u['visibilityCoords']) && coords
non-null`):

- **Owner or admin** (`$isOwnerOrAdmin`): full DECIMAL(10,7) precision +
  the exact what3words value.
- **Any other viewer the tier permits**: coordinates pass through
  `GeoLocation::coarsenCoords()` (round to 3dp, ≈110m) before rendering,
  an "Approximate location" badge shows, the map renders an `L.circle`
  (150m radius) instead of a precise marker (`data-approx="1"` — see
  `location-map-assets.php`'s init script), and the what3words value is
  **suppressed entirely** — never coarsened, because a what3words square
  is ~3m×3m and cannot be meaningfully "rounded" the way a coordinate
  pair can; showing it next to an "approximate" pin would silently
  re-precise the whole point of coarsening.

This coarsen-and-suppress logic lives in the SHARED
`location-display.php` partial (`coarsen` config key) — Chunk B is the
first (and, per the spec, only) caller that ever passes `coarsen: true`;
every other Chunk A caller (Venues, Events, Resources) always shows full
precision because none of those tables carry personal data.

### GDPR lockstep — what changed and why

Shipped in the SAME PR as the schema (house rule: never a PII column
without its export/erasure wiring in the same commit):

1. **Export** (`auth/account/data-export.php`) — `tblUsers`'s existing
   `SELECT *` already re-exports the four new columns with zero code
   change; the real, PRE-EXISTING gap this chunk closed was that Gift Aid
   declarations (home address + postcode, HMRC-mandated) were never
   exported for the declaring donor at all. New `giftAidDeclarations`
   block, `WHERE donorID = ?`.
2. **Self-service erasure** (`auth/account/delete-confirm.php`) — the
   tblUsers anonymise `UPDATE` never nulled `displayAddress`/
   `displayPhone` (a separate pre-existing miss, found while auditing
   this column family) — both now null alongside the three new PII
   columns; `visibilityCoords` resets to `'private'` defensively.
   **No longer true since 11 October 2026:** that page no longer empties
   anything. It files an ordinary erasure request, and the catalogue in
   point 3 is the only route ("Other people's records, and one route to
   an erasure" above).
3. **Admin erasure catalogue** (`Portal\Core\GdprEraser::catalogue()`) —
   the final `tblUsers` entry's `nullCols` gained `latitude`/`longitude`/
   `what3words`. `visibilityCoords` is a `NOT NULL` ENUM so it can't go
   through the generic null-column mechanism — left untouched there is
   harmless because with the coordinates already NULL there is nothing
   left for any visibility tier to gate.
4. **Round-trip proof** (manually traced, no local MySQL in this build
   sandbox): a user with address+coords+W3W+a Gift Aid declaration
   exports all four coordinate fields plus the declaration's address/
   postcode; after erasure (either path) a re-`SELECT` of `tblUsers`
   shows all three coordinate columns NULL, and the admin path's
   `tblGiftAidDeclaration` DELETE (pre-existing catalogue entry, unchanged
   this chunk) removes the declaration's address entirely.
   **No longer true since 11 October 2026:** Gift Aid declarations are
   now KEPT on erasure, address included (the owner's decision; see
   "How an erasure runs" under "GDPR erasure" above).

### GiftAid / Salvation — text-only partial reuse, deliberately no schema change

Both apps reuse `location-input.php`'s "reduced names map" mode
(`showCoords: false, showW3W: false`, `names` mapping only the fields
each app already has) purely for UI/i18n consistency — mapped onto their
EXISTING `address`/`postcode` POST field names, so `gad-save.php`/
`card-save.php` needed zero changes. No `latitude`/`longitude`/
`what3words` column was added to `tblGiftAidDeclaration` or
`tblSalvationCards` — HMRC and pastoral follow-up need postal text, not a
map pin, and no new column means no new erasure surface to wire.

### Hard exclusion: Kids / Care / Visitors

Per the locked decision (safeguarding data, no consent mechanism for a
map pin), the shared partials are NEVER imported into `_apps/kids/`,
`_apps/care/`, or `_apps/visitors/`, and no location column was added to
`tblKidProfiles`/`tblCareCase`/`tblCareVisit`/`tblVisitor`. Verify on any
future touch of this feature with:

```bash
git diff --stat -- web/_apps/kids web/_apps/care web/_apps/visitors   # must be empty
git diff | grep -iE "tblKidProfiles|tblCareCase|tblCareVisit|tblVisitor" # must be empty
```

---

## Reports Builder (#156, migration 184)

Whitelist-driven custom report builder alongside the pre-existing #93
fixed dashboards, at `/admin/reports/builder/*`. The **entire point** of
this feature is that a saved report is a **definition** (registry keys +
literal filter values), never SQL — see FEATURES.md's "Reports Builder"
subsection for the user-facing summary; this section is the how-it-works
and how-to-extend-it writeup.

### The two-class architecture

- **`Portal\Core\ReportRegistry`** (`web/_core/ReportRegistry.php`) —
  pure static data + lookups. No DB access, no `$_GET`/`$_POST`/
  `$_REQUEST`/`$_COOKIE` reads anywhere in the file (grep-provable). Holds
  the closed `SOURCES` constant (six v1 sources), `OPERATORS`/
  `TYPE_OPERATORS`, `AGGREGATIONS`/`DEFAULT_AGGS_BY_TYPE`, and
  `TRANSFORMS`. Every lookup method (`source()`, `column()`, `operator()`,
  `aggregation()`, `transform()`) is a strict `array_key_exists()` /
  `??`-null-coalesce read against those constants — an unknown key always
  resolves to `null`, never a partial/fuzzy match.
- **`Portal\Core\ReportBuilder`** (`web/_core/ReportBuilder.php`) — the
  compiler/executor/CSV/CRUD engine. `compile(array $definition, int
  $siteId): array` is the ONE place in the codebase that assembles report
  SQL. Every substring it concatenates into the SQL string is either (a) a
  `ReportRegistry` constant reached by strict key lookup, (b) a
  request-independent literal (`AS `, ` FROM `, backticks, …), or (c)
  generated placeholder text (`implode(', ', array_fill(0, $n, '?'))`)
  whose LENGTH comes from `count()`, never from request content. The only
  user-typed contribution anywhere is inside `$params`, always reaching
  the DB via `mysqli_stmt::bind_param()`.

### Adding a new data source safely

This is **code + PR review only** — there is no admin UI that can add a
source, and that is intentional. To add a seventh source:

1. Add an entry to `ReportRegistry::SOURCES` — `table`/`alias`/`appSlug`/
   `siteExpr` are mandatory; `fixedWhere`/`joins`/`mandatoryJoins` as
   needed. Every column needs `expr` (`alias.column` — validated by
   `assertSelfConsistent()`'s regex), `label`, and `type`.
2. Confirm the table is a REAL table already in `full_schema.sql` —
   `tools/audit-checks/check_php_table_refs.py` enforces this
   automatically for anything referenced from PHP.
3. If any column carries financial or PII-adjacent data, set `'gates' =>
   […]` (a `tblRoles.roleKey` string, `'@siteAdmin'`, or `'@rootAdmin'` —
   ANY one passing is enough) and `'pii' => true` for audit-log/CSV-notice
   purposes. Gates apply identically in SELECT and WHERE — there is no
   separate "read-only" vs "filterable" tier.
4. Run `php tools/report-builder-selftest.php` — Part A asserts
   `ReportRegistry::assertSelfConsistent()` passes (naming conventions +
   the forbidden-table blocklist below); add a red-team case for any new
   gate to Part C.
5. **Never** add a table matching the forbidden-table blocklist (care,
   kids, safeguarding, prayer requests, local/linked accounts, settings,
   API keys, sessions, trusted devices, password resets, WebAuthn, TOTP) —
   `assertSelfConsistent()` throws a `RuntimeException` if you do. Those
   fragments are deliberately stored as bare word fragments (`'Kid'`,
   `'Care'`, …) reassembled with the `'tbl'` prefix only at RUNTIME inside
   `containsForbiddenTable()` — NOT as literal `'tblXxx'` strings — purely
   so `check_php_table_refs.py`'s regex scanner (which flags any
   `tblXxx`-shaped identifier that isn't a real schema table) doesn't
   false-positive on a string that names a forbidden PATTERN rather than
   referencing an actual table.

### The compile() algorithm, in order

1. **Shape gate** — reject unknown top-level keys, wrong format version,
   oversized JSON (`ReportBuilder::MAX_DEF_BYTES`).
2. **Source resolve** — `ReportRegistry::source()` (null ⇒ refuse) +
   `AppRegistry::isEnabled($src['appSlug'])` (false ⇒ refuse — a
   report's owning app being disabled fails the SAME way an unknown
   source does).
3. **Column resolve** (SELECT or GROUP+aggregates) — `resolveColumn()`
   looks up the column AND checks its gates in one call, shared by every
   position (SELECT/WHERE/GROUP/aggregate) so a gate can never be
   accidentally skipped in one position but not another.
4. **WHERE — tenant scope FIRST, forced.** `$where = ["{siteExpr} = ?"]`
   with `$siteId` from the CALLER's `Site::id()` — the definition format
   has NO siteID field at all, so there is nothing for a hostile
   definition to even attempt to override.
5. **WHERE — user filters**, arity-checked per operator, value-coerced
   per column type, then wrapped in ONE set of parentheses and AND-ed onto
   the tenant scope: `siteExpr = ? AND fixedWhere AND (userRow1 OR
   userRow2 …)`. An `OR` conjunction can only ever widen matches INSIDE
   that parenthesised group — it structurally cannot reach outside it to
   touch the tenant-scope predicate.
6. **GROUP BY / ORDER BY** reference the SELECT list's OWN output alias
   (backtick-quoted), never re-derive an expression — `sort.col` must be
   a member of the exact output-key set step 3 built, checked by
   `in_array(..., true)`.
7. **JOINs** — `mandatoryJoins` always emitted; every other join emitted
   only if some selected/filtered/grouped column actually needs it
   (`noteJoin()` tracks a `$joinsNeeded` set keyed by join-key, not by
   content).
8. **Assemble + `strlen($types) === count($params)` assert** — belt-and-
   braces; should be structurally unreachable given the lockstep
   construction above, but throws a clean `RuntimeException` instead of
   letting a mismatched `bind_param()` produce PHP 8's uncaught
   `ValueError` (see `check_bind_param_arity.py`'s own doc comment for why
   that class of bug is otherwise invisible to `mysqli_report(STRICT)`).

### Re-validation on every run — the "DB-tampered row" story

A saved definition is JSON in `tblReportDefinitions.definition`. Nothing
about the STORAGE format is trusted at read time: `run.php`/`export.php`/
`edit.php` all call `ReportBuilder::decodeDefinitionJson()` then
`ReportBuilder::compile()` FRESH on every single load — there is no
"compile once, cache the SQL" path anywhere. If a row is hand-edited in
the database (or a column is removed from the registry in a later
release) to reference an unknown key, `compile()` throws
`\InvalidArgumentException` and the page shows "this report definition is
no longer valid" instead of ever reaching the SQL layer.

### `preview.php` — the AJAX endpoint, not `api/*`

Session-authed AJAX POST, following the `geo/lookup.php` /
`geo/w3w-suggest.php` precedent (#456) — deliberately NOT under `api/*`
(the ApiRouter routing trap in `.claude/CLAUDE.md` does not apply here at
all; no `api.*.enabled` flag, no `tblRoutes` row needed for it — it IS a
normal `tblRoutes` row, `admin/reports/builder/preview`, `isProtected=1`).
`Auth::verifyCsrf()` rotates the session token on every successful check
(`_core/Auth.php`), so the JSON response always carries the freshly
rotated token back (`csrf` key) and `report-builder.js`'s `syncCsrf()`
writes it into every `input[name="csrf_token"]` on the page — the same
pattern `event-hub-upload.js` uses for its own repeated-AJAX-POSTs case.

### Chart.js SRI hash — derived without jsdelivr access

`Asset::chartJs()` follows the `Asset::sortableJs()`/Bootstrap precedent
exactly. `cdn.jsdelivr.net` was policy-denied (403) from THIS build's
sandbox proxy (see `/root/.ccr/README.md`'s per-tool status), so the hash
was derived the same way the location-chunkA Leaflet SRI hashes were
(#456): downloaded the real npm tarball from `registry.npmjs.org` (which
IS reachable — it's in the proxy's `noProxy` allowlist) and hashed
`dist/chart.umd.js` directly. That file is deliberately the UNMINIFIED
UMD build, not `chart.umd.min.js` — the 4.x npm package ships NO
pre-minified UMD file at all (verified by inspecting the extracted
tarball), and jsdelivr's on-the-fly minification pipeline would make a
`.min.js` URL's served bytes NOT independently reproducible from the
tarball. See `Asset.php`'s `CHARTJS_INTEGRITY` doc comment for the exact
`curl | tar | openssl dgst` derivation command.

### Ambiguities resolved (A1–A8, full spec in the #156 build plan)

| # | Decision |
| --- | --- |
| A1 | Chart.js (SRI-pinned) shipped, not CSS bars |
| A2 | One AND/OR conjunction per report — no nested filter groups in v1 |
| A3 | Drag-and-drop = column-order chip reordering (SortableJS), not a free-form field-onto-canvas builder |
| A4 | Table is `tblReportDefinitions`, not the issue's literal `tblReports` |
| A5 | `isShared` = visible to this site's admins, never truly public/member-visible |
| A6 | Users source exposes fullName (open) + email (site-admin gated) only — no phone/address/coords/bio (directory visibility tiers aren't modelled by the builder) |
| A7 | `reports.enabled` slug folds BOTH the #93 dashboards and the new builder — seeded ON so nothing changes on upgrade |
| A8 | Giving `donorName` gated strictly `treasurer` (no `@siteAdmin` escape hatch); `amountPence` gated `treasurer` OR `@siteAdmin` (Giving::canManage() parity) |

Scheduling/emailed reports are explicitly OUT of #156's scope — not
built, not stubbed.
## Forms Builder (#153)

New app at `/forms` (`web/_apps/forms/`) + `Portal\Core\FormEngine`
(`web/_core/FormEngine.php`) — a generic form designer so "we need a quick
form" doesn't need a code change. Migration 182: `tblForms`,
`tblFormFields`, `tblFormResponses` (all three brand new — no ALTERs, so
no `information_schema` guard idiom needed).

### Why a JSON snapshot, not an EAV values table

`tblFormResponses.answersJson` stores `{fieldKey: {label, type, value}}`
for the whole submission in ONE column, rather than a normalised
`tblFormResponseValues (responseID, fieldID, value)` table. Reasoning:

- A response is a record of *what was submitted*, not a live view of the
  current form. An EAV table keyed on `fieldID` breaks the moment an admin
  edits a field's label or type, or deletes it — either the historical
  value silently relabels itself (wrong), or an `ON DELETE CASCADE`
  destroys it (worse). The snapshot captures label+type at submission
  time, so it stays correct forever regardless of later edits.
- Every read path (moderation list, response detail, CSV export, GDPR
  export) consumes a WHOLE response at once, never filters/aggregates by a
  single field's value — so EAV's one real advantage (per-value SQL
  `WHERE`) buys nothing here.
- JSON columns for this exact "denormalised historical snapshot" shape are
  already house practice — `tblUsers.notifyPrefs`, `tblAuditTrail
  .changeSet`, `tblWorkflowInstances.stepsLog`.
- `FormEngine::csvRows()` reconciles the two worlds for export: it walks
  CURRENT fields by position for the main columns, then appends any
  fieldKey present in a snapshot but absent from the current field list
  (a field deleted after that response was submitted) as trailing columns
  keyed by the original `fieldKey` — nothing is ever silently dropped from
  an export just because a field was later removed.

### Field-type registry + extension contract

`FormEngine::FIELD_TYPES` is a PHP whitelist keyed by the stored
`fieldType` string — deliberately `tblFormFields.fieldType VARCHAR(20)`,
**not a SQL ENUM**. Adding a new field type is: one new `FIELD_TYPES`
entry (with its allowed `config` keys), one new `case` in
`renderField()`'s switch, one new `case` in `validateSubmission()`'s
switch. No migration required. A type is never removed once shipped —
`decodeAnswers()`'s historic snapshots still reference it by name, and an
unknown/removed type simply renders nothing / validates nothing / stores
nothing (`isset(FIELD_TYPES[$type])` gates every code path) rather than
erroring.

`sanitiseConfig()` is the ONE place admin-entered field configuration is
allowed to cross into storage — it copies ONLY the keys named in that
type's `config` list, casts/clamps every value to a hard `CAP_*` constant,
and is re-run on every READ too (`FormEngine::resolveConfig()`), so even a
hand-edited row in the database can't smuggle an unexpected key or an
oversized value through to `renderField()`/`validateSubmission()`.

### `/f/{token}` public route

Cloned from service-plans' `/os/{token}` (itself cloned from
`assets/tag.php`'s `/a/{token}`) — a `Router::handleSpecialRoutes()` block
matching `^[a-f0-9]{32}$`, bypassing `tblRoutes` entirely (works even
mid-migration). `web/_apps/forms/public.php` owns the six-gate uniform-404
(token exists / audience public|both / published / open window / site
`forms.allowPublic` / site `forms.enabled`) — **every gate after the token
lookup is scoped to the form's own `siteID`**, never `Site::id()`, since a
public route has no ambient site context and the request's Host header
need not belong to the form's own site on a multi-site install. This is
the same tenant-safety discipline as `service-plans/public.php` and
`ApiRouter::resolveEnabledFlag()`.

`forms/public-submit.php` is a NORMAL `tblRoutes` row (`isProtected = 0`)
— NOT a special route — so it re-resolves the token and re-runs all six
gates itself rather than trusting anything about how the visitor arrived.
Hardening ladder: honeypot → CSRF → `Captcha::verify()` →
`RateLimiter::isBlocked()` (fake-success redirect on trip — never signal
an abuser) → a per-IP `tooMany()`/`recordHit()` bucket (5 / 15 min,
keyed on a SHA-256 of the client IP, `assets/found-save.php` precedent).

### Residual defaults applied (owner sign-off deferred — see spec §1.2)

The build spec left three decisions open with a stated minimal-safe
default; all three were applied as-is:

1. **Public response retention: keep indefinitely in v1.** Public
   submissions have no `submitterID` (see below), so there is no
   subject-linked erasure path for them at all — only a per-row admin
   delete from `/forms/responses`. `forms.responseRetentionDays` was
   seeded as a stub, default `'0'` (= keep forever), for a follow-up. When
   the owner wants an auto-purge window, the shape is a ~20-line addition
   to an existing token-gated cron sweep (e.g. `cron/forms-purge.php`,
   `user_reminders.cron_token`/`giving-statements.php` precedent): for
   each site with `forms.responseRetentionDays > 0`, blank
   `submitterIP`/delete `tblFormResponses` rows where `channel = 'public'
   AND createdAt < NOW() - INTERVAL {days} DAY` — no schema change needed,
   the column and the setting already exist.
2. **No per-role fill restriction in v1.** Any signed-in member of the
   site can fill any published `internal`/`both` form — there's no
   `restrictRoleKey` column. If the owner wants form-level role gating
   later, that's one nullable `tblForms.restrictRoleKey` column +
   `App::hasRole()` checks in `fill.php`/`submit.php`, no other schema
   change.
3. **`heading` display-only field type is included** (a section header
   inside a long form) — near-zero cost, `'input' => false` in the
   registry skips it in validation/snapshot building entirely. The owner
   may strike it from `FIELD_TYPES` without touching anything else.

### #302 reuse seam (mission-trips public application form)

`FormEngine` is deliberately parameter-driven — no method reads
`Site::id()` or `$_SESSION`. Every call takes the site/form/user context
it needs explicitly, so a future app (#302 mission-trips names this exact
seam: "reuses `forms` framework when #153 ships") can:

1. Create a form programmatically — prepared `INSERT`s into
   `tblForms`/`tblFormFields` (or a follow-up
   `FormEngine::createForm(array $definition): int` convenience method).
2. Render it inside its OWN page — `FormEngine::getFields($formId)` +
   `FormEngine::renderField($field, $old, $errors)` in a loop, same as
   `forms/fill.php` does.
3. Validate + persist under its OWN channel label —
   `FormEngine::validateSubmission()` → `buildAnswersJson()` →
   `saveResponse($formId, $siteId, 'mission-trip-application', $userId,
   null, $json)` — `channel` is a free-text-ish label the caller chooses
   (the `tblFormResponses.channel` ENUM would need `'mission-trip
   -application'` added as a value, or the caller can reuse `'internal'`
   and disambiguate by which `formID` it queries instead).
4. Read responses back by `formID` for its own approval workflow —
   `tblFormResponses WHERE formID = ?`, no `forms` app UI involved at all.

**Special-category data does NOT go through generic forms.** A mission
trip's passport/medical manifest data must stay in #302's own encrypted
manifest store — `answersJson` has no field-level encryption and is
readable by any admin who can view `/forms/responses`, which is the wrong
protection level for that category of data. Generic Forms is for the
application/registration questions only.

---

## Server Information page + a real database-version check (#489, migration 188)

Three places in this codebase already read the database's version and
print it — the admin dashboard, the health page, and every backup file —
and none of them said what the number meant. That stopped being a minor
gap in April 2026, when MySQL 8.0 reached the end of its extended
support and stopped receiving security fixes. This section is for
anyone extending `Portal\Core\DbServer` or the Server Information page.

### Database strictness (`sql_mode`), since 12 October 2026 (#593)

- **One place opens every connection:** `Portal\Core\DbServer::connect()`. Do not write `new mysqli` anywhere else under `web/`; the self-test fails if it finds one. A connection opened elsewhere runs as loosely as the host allows and nothing looks wrong.
- **What it asks for.** The setting is REPLACED, not added to, so every installation runs under the same one:
  - A server KNOWN to be MySQL (Percona included): `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`, which is MySQL 8's own default and what every test here runs under (`DbServer::STRICT_MODE_MYSQL`).
  - Every other server, MariaDB included: the same without `ONLY_FULL_GROUP_BY` (`DbServer::STRICT_MODE_MARIADB`). With that word on, MariaDB 10.6 and 11.4 refuse `SELECT id, name, COUNT(*) FROM t GROUP BY id`, which MySQL accepts and the portal does in many places.
  - **"Known to be MySQL" takes two things** (`DbServer::wantedSqlMode()`): the version text does not name MariaDB, AND the server answers a question only MySQL answers (`SELECT @@information_schema_stats_expiry`). Being unsure always gives the shorter list, because the two mistakes are not equally bad: a MySQL without the word is only less fussy about grouping, while a MariaDB given MySQL's list accepts it and then answers "error" on pages that worked. A MariaDB can hide its name (`--version=…`), which is why the name alone is not trusted. That MariaDB leaves the question unanswered rests on MariaDB's documentation; no MariaDB was run to confirm it.
  - Never add `NO_AUTO_CREATE_USER`: MySQL 8 does not know it, and a server that does not know one word refuses the whole list.
- **Why not "add strictness to what the host has".** It would keep anything else the host switched on. `ANSI_QUOTES` makes `status = "valid"` mean a column called valid, and the portal writes text in double quotes inside SQL in many places (a safeguarding check, `Auth::isCoordinatorOf()`, among them).
- **If the full list is refused**, it asks once more without the three names MySQL has said it will retire (`NO_ZERO_IN_DATE`, `NO_ZERO_DATE`, `ERROR_FOR_DIVISION_BY_ZERO`). A server that takes only that shorter list is still strict, but is judged `warn`: an amber alert, an amber health check, and a line in PHP's error log on every request, until the lists in `DbServer.php` are brought into line.
- **Reading it:** `DbServer::strictness($db)` gives `ok` (exactly as asked), `warn` (strict, but not that exact list, or the database would not say) or `crit` (not strict at all). The health check, the dashboard alert, Server Information and the installer's step 2 all use it. `ok` means the setting read back matches; it does not prove the portal's own request is why (a host that already has the same list looks the same).
- **Cost:** four short statements at start-up on MySQL (two to learn the product, one to ask the MySQL-only question, one to set the mode); three on MariaDB.
- **Writing SQL from now on.** A value that does not fit is an ERROR on every installation, not only on the test servers. An empty text sent to a date or number column is refused; send NULL. A zero date (`0000-00-00`) is refused.
- **A database that was run loosely for years** may hold zero dates. Its rows can still be read, and their other columns changed. Writing a zero date back, copying such rows to another table, and a table change that copies the table row by row are refused (error 1292); a table change done in place still works. Mend with `WHERE column = 0`.
- **Self-tests that open their own connection** (most of them) do not go through `DbServer::connect()`, so on a loose server they still run loose. `feed-importer` and `my-volunteering` need a server on MySQL's default for that reason.
- **Running the strict-mode self-test.** It needs a throwaway server started with `--sql-mode=""` and an account that may `SET GLOBAL`. Part R changes the server's own `sql_mode` for about a minute and puts it back; never point it at a server anything else uses. What it cannot show: anything about MariaDB (no automated run uses one), and a web server or browser (the pages are loaded from the command line through the portal's entry page).

### `Portal\Core\DbServer` — the one place the judgement is made

`web/_core/DbServer.php` reads `VERSION()` and `@@version_comment` from
the database connection it is given, works out which product it is
talking to (MySQL, MariaDB, Percona, or "unknown" if it genuinely cannot
tell), and returns one of three verdicts:

- `'ok'` — inside its maker's own support window.
- `'warn'` — works fine, but worth knowing about (most often: no longer
  receiving security fixes). **A warning never blocks anything.**
- `'crit'` — older than this portal's own `_sql/` migrations can run on.
  This is the only verdict that stops the installer.

The four version numbers that decide all of this are constants at the
very top of the file — `MIN_MYSQL` (8.0.0), `MIN_MARIADB` (10.6.0),
`SUPPORTED_MYSQL` (8.4.0), `SUPPORTED_MARIADB` (11.4.0) — so a future
policy change (a new minimum, a new "supported" floor as versions age
out) is a one-line edit in one file, not a hunt through four call sites.

### The bootstrap-free contract — do not break this

`DbServer` is written to depend on **nothing except the `mysqli`
connection handed to it** — no `App`, no `Site`, no `PORTAL_*` constant,
no `require` of anything else in `_core/`. This is the same contract
already used by `version.php` and `brand-defaults.php` (see "Product
brand presets" above), and it exists for one reason: the installation
wizard runs *before* the portal's normal start-up code, so it cannot use
ordinary framework classes. `web/_install/index.php` simply
`require_once`s `DbServer.php` directly and calls
`DbServer::inspect($testConn)` on the raw connection it just opened.

**If you add a dependency on `App`, `Site`, `Settings`, or a `PORTAL_*`
constant to this file, the installer breaks**, because none of those
exist yet at the point step 2 needs an answer. If a change genuinely
needs something like that, it does not belong in `DbServer` — put it in
the page that calls it instead.

### The MariaDB "5.5.5-" prefix trap

MariaDB sometimes reports its version as `5.5.5-10.11.6-MariaDB-…`. The
fake `5.5.5-` at the front is deliberate on MariaDB's part: it is there
so that very old MySQL client programs — the kind that refuse to talk to
anything whose version string starts with "10." — will still agree to
connect. Read literally, that string looks like ancient MySQL 5.5, and
every current MariaDB server would be misclassified and wrongly refused.
`DbServer::parseVersion()` strips the prefix before doing anything else with
the string. (This line named it `extractVersion()`, which no code has; the
method is private, which is why nothing caught the wrong name.) `tools/db-server-selftest.php` checks this
specifically, alongside 14 other real version strings, and needs no
database to run:

```bash
php tools/db-server-selftest.php
```

### The phpinfo() apache2handler leak — the trap that is not obvious

`web/_apps/admin/system-info/phpinfo.php` calls PHP's own `phpinfo()`
function, restricted to umbrella administrators, to produce the "give
this to your hosting provider" report. Two sections are obviously
dangerous and are simply never asked for: the server's environment
variables (often holding database passwords on shared hosting) and the
current request (which contains the reader's own session cookie). PHP is
asked for `INFO_GENERAL | INFO_CONFIGURATION | INFO_MODULES |
INFO_LICENSE` only — `INFO_ENVIRONMENT` and `INFO_VARIABLES` are never
in that list.

**That is not enough on its own.** When PHP runs as an Apache module,
the `apache2handler` entry inside the *modules* section — which IS
requested, because the report would be useless without it — prints two
extra tables of its own accord: "Apache Environment" and "HTTP Headers
Information". The second of those contains the request's `Cookie`
header, which is the reader's own session token — the exact leak that
leaving out the request section was supposed to prevent, arriving
through a different door entirely.

Be precise about what was and was not checked here, because it matters
if you are deciding how much to trust this. **What was verified on a
running server:** that `phpinfo()` masks nothing at all; that the command
line prints a plain-text report while a web server prints HTML (so a
filter tested only from the command line silently matches nothing and
looks like it works); and that the filter removes those two tables,
along with the values in them, when they are present. **What was not
verified:** the `apache2handler` behaviour itself, on a real Apache
server with PHP loaded as a module — there was none to hand. The tables
were reproduced in PHP's own markup and fed through the filter instead.
So the defence is tested; the precise trigger for it is taken from how
PHP is built rather than from a live observation. The session-token
check at the very end of the file exists partly because of that gap.

The fix: capture the report into a string with `ob_start()`/
`ob_get_clean()` instead of echoing it straight to the browser, then
strip out any `<table>` whose heading matches a short deny-list
(`Apache Environment`, `HTTP Headers Information`, plus `Environment`
and `PHP Variables` as belt-and-braces) before anything is sent. If you
ever change what sections are requested, re-check this list — a new PHP
SAPI integration could print its own extra tables the same way.

**Second line of defence:** any configuration setting whose *name* looks
like a secret (`pass`, `pwd`, `secret`, `token`, `apikey`, `sendmail`,
and similar) has its value replaced with "hidden" before display.
PHP masks nothing on its own — checked against a running server, where
`mysqli.default_pw` and a `sendmail_path` carrying a password both
printed in full. Be honest about what name-matching can and cannot do:
it catches a setting that announces what it holds, which is most of
them. It cannot catch a secret sitting in a setting with an innocent
name. The section-removal above is the real defence, because it is
structural and cannot miss; the name-matching is the backstop.

**Last check before anything is shown:** the finished, filtered page is
searched for the reader's own current session token. If it is found
anywhere — meaning some path nobody thought of leaked it — nothing is
shown at all, rather than trusting the reasoning above to have been
complete.

### Two deliberate "warn, don't block" decisions

Both of these were considered and chosen on purpose — please do not
"fix" them without re-reading the reasoning first.

1. **The installer warns but does not block on an out-of-support
   database version.** It blocks only on `'crit'` (too old to install
   onto at all). Most installs of this portal are on shared hosting,
   where the person installing it cannot change the database version
   themselves. Blocking on "unsupported but working" would just lock
   them out of their own portal over something they have no way to fix.

2. **The health page's traffic light stays green on an ageing-but-working
   database.** That page is polled by uptime monitors. A database past
   its security-fix date is worth knowing about, but it is not an
   incident happening right now — it will be equally true tomorrow, and
   every day after. Turning the light amber for that would make it a
   *permanent* amber, which is a light everybody quickly learns to
   ignore — and a real problem arriving later would then land in a
   warning nobody reads any more. The light only turns amber for
   `'crit'`, a genuine fault. The database version and its support
   position are still reported in the text beside the light; they are
   just not allowed to move the light itself.

### Where the verdict is shown

| Where | Who can see it | Notes |
| --- | --- | --- |
| `_install/index.php` (step 2, and the banner on later steps) | Anyone installing the portal | Only place that can block, and only on `'crit'` |
| `/admin` dashboard | Signed-in admins | |
| `/admin/maintenance/health` | Admins. Uptime monitors use `/cron/health?token=…` instead, which returns the same checks as JSON (it was `?cron=1` on this page until #497). Like that old mode, it still answers while maintenance mode is on: it is the only `cron/` address `Maintenance.php` lets through, and only as an exact match | Traffic light never moves for a `'warn'` |
| `/admin/system-info` | Any administrator | New — also shows PHP version/limits/extensions and connection facts (never the password — it is asked of the live connection, not read from the credentials file, so it is never loaded into the page) |
| `/admin/system-info/phpinfo` | Umbrella administrators only | New — full PHP report, filtered as described above |

Every one of these calls the same `DbServer::inspect()`, so there is
exactly one place to fix if the judgement is ever wrong, not four.

---

## Routing traps found 2026-09-10 (#483, #478, #477, #482)

Four faults were fixed on the same day, and none of them was what its
issue title said. All four were found by checking the actual code
against the actual web root and the actual list of addresses, not by
reading the reports. The first two below share one root cause; the other
two are separate mistakes worth knowing about because the same shape of
mistake can easily happen again somewhere else.

### A real file or folder in `web/public_html/` always beats a seeded address of the same name

`web/public_html/.htaccess` contains this rule:

```apache
RewriteCond %{REQUEST_FILENAME} !-d
```

In plain words: if the address somebody typed matches a REAL FOLDER that
actually exists on disk, the web server answers for that folder itself —
serving a file listing, or refusing outright — and the request never
reaches the portal at all. This rule exists for a good reason: it is
what lets a stylesheet or an image be served directly, quickly, without
the portal's own routing code getting involved. But it also means a
folder placed in the web root can silently swallow a seeded address that
happens to share its name, and the portal never even finds out the
request happened.

This is exactly what happened twice. A real folder called `admin/`
sat in the web root holding three unrelated pages, so every visit to
`/admin` — the first thing an administrator ever clicks — was answered
by that folder instead of by the portal. And a real folder called
`widget/` (holding `countdown.js`, a script other people's websites
already embed) sat in the web root too, so the seeded public address
`widget` never once reached the page it was supposed to point at.
Neither case left anything in the error log, because no portal code ever
ran to log anything. That is the dangerous part: it does not look like a
crash, it looks like the address was never registered.

**How to spot this before it ships.** Compare every seeded `routeKey` in
`web/_sql/full_schema.sql` (or a new migration's `tblRoutes` INSERT)
against everything that actually exists in `web/public_html/`:

```bash
ls -1 web/public_html/
```

If a seeded address's first path segment matches a real file or folder
name in that list, it will not be reached — test it by requesting the
address directly rather than trusting that a route exists because a row
in the database says so.

**Two collisions are deliberate and correct, not bugs:** `assets` has its
own explicit `RewriteRule` ahead of the folder-check in `.htaccess`,
carving out an exception on purpose, and `api-docs` is meant to be served
directly by the web server rather than by the portal. Do not "fix" either
of those.

**When the folder cannot move** (because something outside this
codebase already depends on its exact address — the countdown script
case above), the seeded address has to be removed instead, not repaired.
Removing a route and fixing the orphaned page behind it should happen in
the same change, not two: while the page is still reachable in principle
by some future re-point but has not yet had its own access rules
checked, leaving it half-fixed is worse than leaving it broken. In the
`widget` case, the orphaned page's own database queries turned out to be
missing a check for `isPublic = 1` — meaning that if it had ever quietly
become reachable, it would have shown a member of the public a list of
internal, non-public events. That was fixed at the same time the address
was removed, and the file now carries a comment explaining why it has no
address and what would need to be true before anyone gives it one again.

### An address is what a visitor types; a file path is where the code that answers it lives — do not confuse the two

`web/_core/Maintenance.php` keeps a short allow-list of addresses that
still work while the portal has switched itself into maintenance mode
(which it does automatically whenever the code is newer than the
database — in other words, on every single upgrade). That list is
compared against the ADDRESS a visitor typed.

It used to contain `'auth/login'` and `'auth/logout'`. Those look like
addresses, but they are not — they are fragments of the FILE PATH that
answers the sign-in page. The sign-in page's actual address is `login`;
the file that answers it happens to live at `auth/login/index.php`. The
two are usually related but they are never guaranteed to be the same
string, and here they were not. Because neither entry ever matched what
a visitor's browser actually requested, the sign-in page itself was
blocked during every maintenance window — including the maintenance
window's own holding page, whose "sign in" link pointed at an address
that did not exist either.

**The lesson generalises beyond this one file.** Anywhere in this
codebase that compares against "the address", check what a visitor
actually typed (the seeded `routeKey` in `tblRoutes`) — never the name of
the file that happens to answer it. When adding to a list like this one,
check each entry against the seeded addresses in
`web/_sql/full_schema.sql`, not against the folder layout of `_apps/`.

One more thing worth knowing before you touch this list: the match is a
PREFIX match, not an exact match, and that is deliberate rather than
sloppy. `login` also has to allow `login/webauthn` (passkey sign-in), and
both password-reset entries have to allow their own `/save` submit
handlers. Narrow any entry to an exact match and the form will load but
silently fail to submit — a more confusing half-broken state than either
being fully open or fully blocked.

A related trap surfaced while fixing this one: a first attempt corrected
the sign-in page itself but missed the second step of signing in — the
two-factor code screen a visitor with two-factor switched on is sent to
after a correct password. That is a half-fix that looks like a whole
one: it was only found by listing every address that plays a part in
signing in and checking each one individually, rather than assuming that
fixing the page named in the report was the entire journey a visitor
takes.

### `$db` does not exist — the router only ever hands a page two things

When the portal loads a page, it hands that page exactly two things:
the database connection, in a variable called `$mysqli`, and the site's
settings, in a variable called `$SETTINGS` (see the `global` line in
`web/_core/Router.php`, immediately before the page file is loaded).
Anything else a page reaches for simply is not there — PHP does not
raise an error for using an undefined variable in the way many other
languages would; it silently treats it as empty, and then the very next
line that tries to *use* that empty value as if it were a real
connection stops the page dead.

Five CSV export handlers reached for a variable called `$db` instead of
`$mysqli`. Each one looked correct to read and passed a syntax check
(`php -l` cannot catch this — the variable name is syntactically valid,
it is simply never set). The fault only ever showed up at the moment a
real visitor pressed the real "Export CSV" button: no file appeared, and
nothing was written to the error log, because the page died before it
reached any of the code that would have logged something.

**How to spot this before it ships:** grep any new or touched file under
`web/_apps/` for a variable that looks like a database handle, and
confirm it is either `$mysqli` (the one the router actually provides) or
explicitly passed in as a function parameter from a caller that itself
has `$mysqli`. `web/_apps/announcements/_workflow-gate.php` is a correct
example of the second pattern — it takes the connection as a parameter
rather than assuming a global variable exists — and is not a bug.

---

## Roles per organisation (#516)

Before this, `tblRoles` was one list for the whole portal, and **nothing anywhere could ever put
a row into `tblUserRoles`** — the members page showed a person's roles and the help page
described where they live, but no button, form or script could give one. Every one of the 61
files (66 call sites) that call `App::hasRole()`, plus `App.php` itself, only ever answered yes
for a global administrator, because a global administrator is given every role automatically and
nobody else could ever be given anything.

### The key/label split — why a rename can never break a role check

Every role row has always carried two names: `roleKey` (a short, fixed, lower-case word such as
`treasurer` — what the CODE compares against) and `roleName` (the label a person sees). This did
not change. What changed is that `(siteID, roleKey)` is now the unique pair, instead of `roleKey`
alone — so each organisation has its OWN `treasurer` row, and may rename its own `roleName`
freely without touching the key any of the 61 files look up.

### Everything is per organisation, including the list itself

Each organisation gets the same standard fourteen roles a new organisation is seeded with
(`Portal\Core\Roles::STANDARD`), may rename any of them, and may add roles of its own. A holding
in `tblUserRoles` names both the account AND the organisation — treasurer of Organisation A is
not treasurer of Organisation B. `App::hasRole()` now answers strictly for `Site::id()`, the
organisation open right now.

### `Portal\Core\Roles` — granting, revoking, seeding and the shared role lookups

`web/_core/Roles.php` owns granting, revoking and seeding roles, and the shared "does this person
hold this role here" lookups. It is not the only code that touches `tblRoles`/`tblUserRoles`:
the role-list page (`web/_apps/admin/roles/save.php`) writes `tblRoles` itself, and about a dozen
hand-written queries elsewhere still read both tables directly, each repeating the
per-organisation join rule by hand. Change that rule and you must change those sites too;
searching for `tblUserRoles` finds them. The one method worth knowing about beyond the obvious
`has()`/`grant()`/`revoke()`:

- `Roles::holdsSql(string $userExpr, string $roleIdExpr, string $siteExpr): string` — builds the
  exact `EXISTS (...)` SQL fragment a caller can drop straight into a hand-written WHERE clause,
  for a caller (starting with #514's calendar-import audience query) that needs "does this
  person hold this role" as part of a bigger query rather than as a separate PHP call. Every
  argument must be a plain, developer-written identifier or the literal `?` — never
  request-derived — checked BEFORE anything else runs, so a bad argument throws with no database
  connection needed at all.

### Who may grant a role, and the refusal shape

Granting or removing a role for a specific account is an `AccountGuard`-guarded action —
`AccountGuard::REACH_THIS_ORG`, the same reach a membership row's `isSiteAdmin` flag already
uses. An administrator of the organisation currently open may grant a role within their own
organisation; a global administrator may do it anywhere. A refusal looks and costs exactly the
same as an account that does not exist (the standing #503 rule) — see
`web/_apps/admin/users/roles-save.php`. Managing the LIST itself (rename, add, delete) is a
plainer `App::isAdmin()` gate — see `web/_apps/admin/roles/save.php` — because no particular
account is being changed.

### The pen — `tblUserRolesUnplaced`

`tblUserRoles` had no organisation column before migration 202. A hand-edited database (there
should be none on a real installation — this only matters if somebody wrote a row in directly
before this feature existed) might hold a role that cannot be placed automatically. The migration
places it automatically ONLY where doing so is not a guess (a single-organisation portal, or a
multi-organisation one holding exactly one organisation row — the exact #533 reasoning) and
otherwise copies it into `tblUserRolesUnplaced` for a global administrator to place by hand at
`/admin/users/roles-unplaced` — the same shape `/admin/users/unplaced` already proved for
accounts with no organisation at all.

### `check_role_keys.py` — what it protects, and what it cannot see

The standard fourteen roles exist in three places that have to agree: `Roles::STANDARD`, the A9
seed in `202_roles_per_organisation.sql`, and the matching fold-in block in `full_schema.sql`.
`tools/audit-checks/check_role_keys.py` compares all three, plus every place in the code that
names a role key by a fixed string literal (`hasRole('...')`, `Giving::ROLE_KEY`, a
`ReportRegistry` `'gates'` entry, the `visitors.coordinator_role` setting default). It **cannot**
see a role key read from a variable (`hasRole($variable)`, `ReportRegistry::callerPassesGates()`'s
own run-time comparison), a key typed into a saved workflow step or newsletter segment (data, not
code), or anything referenced only from JavaScript.

### What #514 gets from this

`Roles::holdsSql('V', 'am.refID', 'E.siteID')` is the exact call #514's calendar-import audience
picker uses to ask "is this person in role R for organisation S" inside a database query, without
needing to know this class's table names or join shape.

## User groups and departments per organisation (#517)

Two kinds of grouping had existed in the database for a long time with **nothing anywhere able to
create one or add a person**: user groups (`tblGroups`, members in `tblUserGroups`) and departments
(`tblDepts`, members in `tblUserDepts`). Code READ them — workflow approval by group, asset
ownership by group or department, expense approval by department — so those options only ever
worked after a hand edit, and the installer seeds neither. `tblGroups` also had **no organisation
column**, so a group was visible and usable in every organisation. The owner's decision (21
September 2026): both belong to one organisation each, are managed by that organisation's
administrators, and stay two different things — groups are committees and working groups;
departments carry the five flags expense approval depends on.

### The shape (migration 203 + the `full_schema.sql` fold)

- `tblGroups` gains `siteID` (required) and `isActive` (the "retire" switch). `tblDepts` already had
  both; it gains only `idx_depts_id_site (deptID, siteID)`.
- Both membership tables gain `siteID` (required), `addedAt`, `addedByID`, a unique key on
  `(userID, groupID)` / `(userID, deptID)`, and two **composite foreign keys** (rules the database
  enforces over two columns at once): `(userID, siteID)` → `tblUserSites` (the person's own
  membership of that organisation) and `(groupID, siteID)` → `tblGroups` / `(deptID, siteID)` →
  `tblDepts` (the SAME organisation's group or department). So a membership for a non-member, or
  naming another organisation's group, is refused by the database itself (error 1452), and deleting
  a person's membership row of ONE organisation ("remove from site") removes only that
  organisation's group and department memberships.
- **The index trap:** InnoDB needs the columns a composite foreign key POINTS AT to lead an index.
  `idx_groups_id_site` and `idx_depts_id_site` exist for exactly that; without them adding the key
  fails with `ERROR 1822 Missing index for constraint` (proven while planning). **Since 24 September
  2026 (#552), both must also be UNIQUE, not merely present** — MySQL 8.4 refuses to create a foreign
  key unless it points at a PRIMARY or UNIQUE key on exactly those columns; see the "Portable DDL
  convention" section above for the full story.
- `tblDepts.isActive` stays nullable: a NULL from a hand edit already means "off" everywhere.
  `Departments::setActive()` compares `COALESCE(isActive, 0) <> ?`, because a plain `<>` against
  NULL matches nothing and such a department could never be retired or reinstated.

### Carry-over, and the pens

Nothing could write these tables, so a real installation has no rows to carry. For a hand-edited
one: a GROUP is placed automatically only where that is not a guess (single-organisation portal,
or exactly one organisation row — the #533/#516 reading); a group membership follows its group if
the person has a membership row there. DEPARTMENT memberships follow the department's own
`siteID` **without** the single-organisation condition, because that `siteID` has been real data
since migration 015 (the claim form and the API already treat a department as living there) —
only a person with no membership row there is parked. Duplicate department memberships have their
flags folded ("set if either copy has it") into the kept row before the duplicates are removed, so
no approver loses the flag. Anything not placed goes to `tblGroupsUnplaced`,
`tblUserGroupsUnplaced` or `tblUserDeptsUnplaced`, for a global administrator at
`/admin/users/memberships-unplaced`. A parked group keeps its **number** and gets it back when
placed (workflow steps name a group by typing its number); if a dump-and-restore has since let
another group take that number, placement uses a new one and says so. That new number is chosen
one above every number in use or still waiting in a pen, and the group's still-parked members are
moved to it, so a later "Place" can never put them into a different group that happens to hold
the old number (proven wrong otherwise during the #517 build). A parked group's asset
ownership rows are removed by migration 159's `fk_asto_group` cascade and must be re-added by hand.

### `Portal\Core\UserGroups` and `Portal\Core\Departments`

The two classes own every write to the four tables and the shared lookups, in the `Roles` shape:
connection passed first, no cache, no global-administrator shortcut in SQL, a plain `INSERT`
catching 1062/1452 (never `INSERT IGNORE`, which turns a foreign-key refusal into a silent
warning), audit and activity records with account NUMBERS only. They do not decide WHO may act:
the list pages gate on `App::isAdmin()`; the Members pages run `AccountGuard::check(…,
REACH_THIS_ORG, …)` for the particular account, so a refusal reads exactly "That account could
not be found." `Departments::FLAGS` is the single description of the five flags; the pages and
the help page render it. Nothing in the portal reads `isDeptAssistant` or `isDeptSecretary`, and
their descriptions say so.

**The column-checker trap.** `check_sql_columns.py` misreads `FROM tblUserGroups <short name>` (and
`FROM tblUserGroupsUnplaced p`, or `… tblUserGroupsUnplaced GROUP BY …`) on a SELECT as an unknown
table — the word "Group" inside the name (its blind spot 15). Start from `tblGroups`, or reach the
membership table through a `JOIN`.

### Why expense APPROVAL carries no "department switched on" test (owner's answer Q3)

Retiring a department stops NEW claims (the claim form's list, `expenses/submit/save.php` and
`expenses/api/create.php` all require `isActive = 1`). The approval listing, the decision gate, the
required-approver list and the approver e-mails deliberately do NOT test it, so claims already
charged to a retired department are finished by its own approvers. Adding the test was proven to
strand such a claim for ever: `approve/save.php` requires every lead and required approver to
approve, and an administrator's approval never satisfies that. Every approval query does now require
the membership to be in the claim's own organisation and the person's membership there to be
active, so somebody who has left can neither approve nor block a claim.

### What #514 calls

`UserGroups::memberSql('V', 'am.refID', 'E.siteID')` and `Departments::memberSql('V', 'am.refID',
'E.siteID')` — one `EXISTS (…)` fragment each, answering "is this person a CURRENT member of that
group / department in that organisation": membership row in that organisation, the group or
department belongs to it and is switched on, and the person's organisation membership is active.
A retired or deleted group or department matches nobody (fail closed). The pickers are
`UserGroups::forSite($db, $siteId, true)` and `Departments::forSite($db, $siteId, true)`.
`tools/memberships-selftest.php` pins both strings.

### `check_membership_queries_scoped.py` — what it protects, and what it cannot see

Every line of PHP under `web/` that names `tblUserGroups` or `tblUserDepts` must have the text
`siteID` within 10 lines either side (comments ignored). It exists to stop the next
`JOIN tblUserGroups ug ON ug.userID = u.userID` with no organisation — the shape every old reader
had. It **cannot** tell whether the `siteID` it finds actually scopes THAT query: on the pre-#517
tree it found 13 of the 17 old unscoped lines, and missed four whose statements tested ANOTHER
table's `siteID` nearby. It does not read SQL held in variables, and does not check the two pen
tables (by design). `ALLOWED` names offboarding (a whole-portal exit on purpose) and the two erasure
lists.

## Outside calendars (#514)

An organisation can subscribe the portal to a calendar somebody else publishes (Google, Microsoft 365 or any
calendar file at a web address). The portal copies its events in, and an administrator decides who sees each one.
It was built in eleven parts (the plan is in the issue); this section is what a developer must know before
changing any of it. The administrators' guide is `/help/calendar-imports`; the feature list is in `FEATURES.md`.

### What is where

- **`SafeFetch`** (`web/_core/SafeFetch.php`) — may this address be fetched, and fetches it.
- **`IcsReader`** and **`WindowsTimeZones`** — turn a calendar file into a list of dates. They open no connection and read
  no setting, which is what lets them be tested alone.
- **`FeedImporter`** — the ONE place that writes a copied-in event. Refreshes a calendar and decides what to remove.
- **`FeedResolver`** — works out who may see each copied-in event, and writes the answer onto the event (`import*` columns).
- **`EventVisibility`** — the ONE rule that reads those columns. Every place that shows events uses it.
- **`ExternalAudience`** — the "selected groups and people" lists (`tblExternalAudienceMembers`).
- **`FeedAdmin`** — every read and write the administrator pages make. The pages are
  `web/_apps/admin/calendar/feeds*.php`, plus one section of the Approvals inbox (`web/_apps/approvals/_imports-section.php`).
- **`ImportNotifier`** — tells administrators that dates are waiting.
- **`web/_apps/cron/import-feeds.php`** — the scheduled job.
- **Tables**, all `tblExternal…`: `Feeds` (made by migration 129, the older importer, #327, which these replaced; columns added
  by 204 to 206), then from migrations 204 to 206 and 218: `AudienceMembers`, `EventTags`, `CategoryMap`, `FeedRuns`,
  `EventChoices`, `FeedRules`, `RuleConditions`, `EventApprovals` and `ApprovalNotices`. All are in `full_schema.sql` too.
  Migration 207 adds only the page addresses (routes).

### The one rule, and its modes

`EventVisibility::where()` (and `whereForColumn()`, for a query that has a viewer column instead of one viewer) hands a
caller a piece of SQL to add to its own query, so the database decides row by row. The SQL text depends only on the mode
and the alias, never on the event or the viewer, so a refused event and a missing one cost the database the same work
(the #503 lesson). The seven modes (`EventVisibility.php:150-185`): `session` (a page; a signed-in person or nobody),
`anonymous`, `website` (countdown widget and sitemap), `token` (a personal feed, reminders and push: never administrator
powers), `invite`, `key` (an API key is treated as the public, never as a member) and `bulkMembers` (the newsletter).
`add()` refuses a piece whose `?` marks, types string and values do not agree; no audit script can count that for us.
An event alias or viewer column may not be one of the rule's own inner names (`RESERVED_ALIASES`): it would bind to
the wrong table and never fail.

- **Imported events** carry `importLevel` (public, members, groups, hidden) and `importDetail` (full, or "basic": title,
  date and time, and category). They are shown only while their calendar exists AND is switched on, tested live in
  every query, so pausing hides everything at once. A stored answer expires (`importRecheckAt`, UTC); past it only
  administrators see the event until it is worked out again. That fails closed.
- **Why the portal's own events keep `isPublic`.** Seven pages write it (the event form, a member's suggestion, the file
  import, series edit, moderation, and the events API's create and update), and so does the import job. A second column
  that could disagree with it would be worse than one. Their two levels are public, or members of the event's own organisation.
- **"Member" is one test:** an active account with an ACTIVE membership row for the event's own organisation. No row means no,
  on one organisation or several (#533; an earlier draft treated "no row" as "member" on a single-organisation portal and
  the owner removed that). **"Administrator"** is a global administrator, or an active site administrator of the event's
  organisation; the old portal-wide `isAdmin` flag counts for nothing (`EventVisibility::isAdminOfSite()`).
- **Detail.** `redact()` empties `REDACTED_COLUMNS` for a viewer who may see only title, date and time. An API key gets
  exactly what a signed-out visitor gets, unless the calendar, a choice or a rule has "Don't show via API" ticked
  (`importApiOptOut`, written only by `FeedResolver`; the owner's decision of 24 September 2026).
- **What it cannot do:** recall a copy a browser, calendar app or search engine already took; stop a caller forgetting to
  use it (that is the audit check, below).

### How an imported event is recognised

By `tblEvents.externalUidHash` (the SHA-256 of the exact bytes of the calendar's UID) plus `externalRecurrenceKey` (which
date of a repeating event; an identifier, never a time), unique together with `externalFeedID`. **The hash is raw
bytes (`BINARY(32)`) on purpose.** `tblEvents` compares text without regard to capital letters, so `ABC@x` and `abc@x`,
two different events under RFC 5545, would overwrite each other on every refresh. Choices (`tblExternalEventChoices`)
are keyed by this identity, not by `eventID`, so a choice survives the row being removed and brought back; an event
deleted and re-created at the source gets a new UID and no choice matches it (it falls back to the calendar's setting).
Migration 205 brought the #327 rows forward: a one-off keeps its row (and members' answers); a renamed event keeps the
newest; a repeating event (one row for the whole series) and a UID of 255+ characters are left without an identity and
removed by the first complete refresh, so an answer on such a row is lost.

### Times

Two kinds, never mixed (the owner's rule of 23 September 2026). Moments (`nextFetchAt`, `refreshLeaseUntil`,
`externalLastSeenAt`, `importRecheckAt`, the notifier's `emailAt` and `pushAt`) are UTC and compared with the
database's `UTC_TIMESTAMP()`, never PHP's clock. Event times (`startDateTime`, `endDateTime`) are wall-clock readings in
the event's zone, compared as text and never converted. An end may sort BEFORE its start on the night the clocks go
back (01:30 then 01:15): the importer stores what the reader gives it and does not "repair" it. The zone times with no zone of their own are read in
(`FeedImporter::zoneFor()`): the calendar's own zone if set and known; UTC if set but unknown; else the organisation's
(`Site::timezone()`). A calendar with no zone of its own is refused while the organisation's stored zone is in doubt
(`zoneInDoubtProblem()`). The reader follows RFC 5545 §3.8.5.3 for repeating events across a clock change (every
instance keeps the same exact length): the owner chose the standard on 23 September 2026; some calendar programs differ.
Windows zone names (`GMT Standard Time`) are turned into ordinary ones by `WindowsTimeZones::MAP`. **That file is
generated:** `php tools/generate-windows-timezones.php` rewrites it from ICU, `--check` compares and changes nothing. It is
committed, not built at run time, because `intl` is missing on some shared hosts.

### The reader's limits (`IcsReader`)

The file is untrusted, and running out of memory in PHP cannot be caught, so every list whose length the file chooses is
counted before it is built (`MAX_*` constants), and each per-event limit has a file-wide twin. The rule: never turn
text from the file into an array before knowing how many pieces it makes. It is not "this cannot happen"; the header lists
what is still unbounded. Fixed limits include 400 dates for one repeating event and 500,000 lines. **One number is a setting:**
dates per calendar, default 2,000 (`IcsReader::MAX_EVENTS_PER_FEED`), raised by `feeds.maxEventsPerFeed`
(read in `FeedImporter`, ceiling `MAX_EVENTS_PER_FEED_CEILING` = 10,000). Reaching it makes the read `capped`.
It ignores `VTIMEZONE` blocks (zone names are looked up in the system's database), attachments (counted, never
imported), and everything that is not a `VEVENT`. Not every repeat rule is supported (`UNSUPPORTED_RULE_PARTS`): such a
series keeps the dates the file states one by one and loses the rest, with a warning.

### `SafeFetch`

Only http and https (`webcal://` and `webcals://` are fetched as https); no user name or password in the address; only ports
80 and 443; every disguised internal address refused; EVERY address a name gives must be public; the connection is pinned
to the addresses checked (no DNS rebinding); redirects are followed one at a time and checked again, https to http refused;
proxies are ignored; size and time are capped. One message (`REFUSED_MESSAGE`) for a private address and for a name that
does not exist, so nobody can map the host's network. **It never logs an address** (a Google "secret address" is the
password to the calendar), and neither does the job. Limits: the name lookup has no time limit of its own; the extra
pre-request check needs libcurl 7.80 or later; a host that allows web requests only through a proxy fails every fetch.
`Hymnal` uses `isPublicIp()` but its own download is not pinned (a follow-up issue). Outbound webhooks and Web Push do not use `SafeFetch`.

### The importer: what it will never delete

**An incomplete download never removes anything.** Three shapes are incomplete: the connection dropped (`complete = false`),
the reader hit the per-calendar limit (`capped`), or one event's skipped-date list was cut so dates went missing from
inside it (`capped`, with NO end point). `capped` is read FIRST. A capped read with no end point removes nothing at all;
one with an end point removes only what starts strictly before it, and the point itself is not trusted when it is
"exact: false" (several events can start at that same moment: `removalEndPoint()`). A refresh removes by soft deletion
(`isDeleted = 1`). Deleting a calendar removes the calendar, every event it brought in, and every answer on them.

- **One refresh at a time, with an END.** A refresh claims the calendar by writing `refreshLeaseUntil` (10 minutes ahead),
  not a plain "busy" flag, because a killed request would leave a flag set for ever. The next fetch is scheduled when a refresh
  STARTS, so a calendar that kills the request is not first in line again. Never fetch a calendar more often than every 15
  minutes (`MIN_FETCH_MINUTES`); "Refresh now" is refused within 60 seconds of the last. The portal keeps 30 days back and
  12 months ahead.
- **Lock order.** Every writer of a calendar takes ONE lock first: the calendar's own row, `SELECT … FOR UPDATE`, by number
  AND organisation (`FeedImporter::lockFeed()`, `FeedAdmin::inFeedLock()`). That covers a refresh, pause, resume, delete, a
  save, a rule, a choice and a decision. Inside it: do the work, call `FeedResolver::resolveFeed()` with the database's own
  clock, commit. `FeedResolver` takes no lock and starts no transaction itself. A page decision on dates of several calendars
  takes one lock per calendar, so it is not all-or-nothing across calendars (`decide()` says what it reports then).
  `addCalendar()` and `previewRule()` are the two writes outside it, with reasons in `FeedAdmin`'s header.

### Choices, rules and approvals (`FeedResolver`)

Seven steps for each live event (the list is in `FeedResolver`'s header): a duplicate, a private mark with no choice, an
active choice (a date's own, then the whole series'), the active rules (several combine to the NARROWEST answer), "is it wider
than the calendar's own setting?", an approval row if it is, and a write that skips unchanged rows. Showing an event to more
people than the calendar's own setting needs an approval; narrowing never waits. The preview an administrator sees runs the SAME load and
work-out as the real thing and writes nothing (`previewRule()`), so the two cannot disagree.

- **Approval states** (`tblExternalEventApprovals.status`): `pending` (waiting), `approved`, `declined`, `superseded` (replaced
  by a newer request or content), `withdrawn` (nothing asks for it any more). `reason`: `new_match`, `content_changed`,
  `address_changed`, `choice`, `series_match`. Two fingerprints: `requestHash` (WHAT was asked: origin, level, detail,
  website box, list) and `contentHash` (what viewers would SEE; dates and times are left out on purpose, so a moved date is
  not asked about again).
- **The sibling decision** (pass 2 in `FeedResolver::plan()`). A waiting date looks for a decision on another date of the same
  repeating event with the same request and content, and follows the newest. It is a second pass that looks only at decisions as
  they stood at the end of the first, so the answer cannot depend on the order the dates were read in; one pass is enough
  because a row it settles copies an existing decision.
- A duplicate never gets an approval row; a narrowing choice or rule still applies to it, a widening one does not.
- Not noticed by itself: a change of the organisation's time zone. Stored `importRecheckAt` moments keep the old zone's day
  boundaries until each calendar is next worked out (about twenty hours). That belongs with #549.

### Who may be named on a list: the kinds live in four places

`tblExternalAudienceMembers.kind` has six values: `small_group`, `leadership_role`, `role`, `user_group`, `department`,
`person`. **A new kind changes four places together**, and needs people of that kind in both self-tests' made-up worlds:

1. the rule: the `audience` piece of `EventVisibility`;
2. its fast copy: `FeedAdmin::whoCanSee()`;
3. the list class: `ExternalAudience` (`OFFERED_KINDS`, `options()`, `currentMany()`, `labelled()`, `belongsToOrganisation()`);
4. the picker: `web/_apps/admin/calendar/_audience-picker.php`.

`FeedResolver::audienceSummary()` holds the words ("1 role, 2 user groups") and already knew all six.

- **The fast copy exists for speed, and a test is its tripwire.** After a save the page says "N dates now …". Asking the real
  rule about every account measured 7 to 9.5 seconds per save at 300 events × 500 accounts, inside the calendar's lock (shared hosting
  stops a page after 30 to 60). The copy measured 0.03 to 0.11 seconds a save at 1,000 × 2,000 before part 10; part 10 made one reading about three times slower,
  still under a tenth of a second, and a save makes two (`whoCanSee()`'s docblock has the figures and what was not measured). `tools/feed-admin-selftest.php`
  (D17) asks the REAL rule about every account of its made-up world, before and after each save, and compares the copy's count AND its
  answer for every event. **Any change to the rule must be matched in the copy, and the test says so, as long as its world holds
  the kind of person the change is about.** Four ways that were tried and rejected are listed in `whoCanSee()`'s docblock.
- **Roles, user groups and departments are not written out in the rule.** The rule and the copy both use the SQL pieces
  `Roles::holdsSql()`, `UserGroups::memberSql()` and `Departments::memberSql()` hand back, with the organisation handed in being the EVENT'S
  (`<alias>.siteID`; `am.siteID` in the copy), never the piece's own: that is what keeps another organisation's role from counting. A static
  property cannot be filled by a method call, so the rule holds the markers `{ROLE}`, `{UGROUP}`, `{DEPT}`, which `piece()`
  fills in. `EventVisibility` loads the three classes itself (`borrowed()`) because three self-tests load it alone.
- **One number, six kinds (check R9).** Six tables number their rows separately, so on a real installation the same number can be
  a role, a group, a department, a small group, a leadership role and somebody's account. Each test world holds one of each
  with ONE shared number; without it a rule that read one kind's row as another's passed every check. A new kind needs the shared number too.
- `not_offered` is now only a guard: the test fails if the database's list and `OFFERED_KINDS` ever differ. Another
  organisation's entry is `gone`, not `not_offered`.

### Telling administrators (`ImportNotifier`, part 9)

The ONE place that decides who is told and what. `run()` never throws and is called only from the job, straight after the recheck pass.

- **One pass reads the data**; it does not hook into the pages that make dates wait. (Rejected: a push fired from six save pages
  and two places in the importer. A date can start waiting in eight ways and in any written later.)
- **Nobody is told twice.** The only memory is `tblExternalApprovalNotices` (one row per administrator per organisation). A person's turn is
  taken by CHANGING their row in one statement; only one run can change it, so two runs at once cannot both send. (Rejected: read, then
  send. Rejected: `tblUserReminderLog`, whose key would hide the person's number in a column called `refID`, where erasure, the data
  copy and backup order could not find it.)
- **Order inside an organisation's turn: the push first, then the e-mails**, and a push claim is always followed by its call. Do not put
  anything slow between them: a claim is never undone.
- Every person passes `EventVisibility::isAdminOfSite()`, asked again before a person's push is claimed and just before each message. The
  events listed come from `FeedAdmin::pendingApprovals()`, the approvals list's own reader. Do not write a second rule.
- `emailLocalDate` is a date on the ORGANISATION'S calendar; the day is compared only as "is the stored day before today?" and "07:00" as text,
  so a 23- or 25-hour day cannot move a message. The e-mail carries each waiting event's title and time (the owner, 12 October 2026), the event's
  own clock time with its zone, never converted, at most 20 events. The push carries a number only. **Quiet hours hold nothing** (the owner,
  13 October 2026): do not bring a hold back without that decision changing.
- **Why one try a day.** A provider that is down would otherwise be asked every few minutes. A failed day shows as `emailOutcome = 'failed'`;
  the card and the list are unaffected. A message the mail queue keeps carries a use-by moment (the start of the organisation's next day), so
  today's list goes today or not at all; the row says `kept`, never `sent` (`Mailer::send()` answers true for a message only kept).
- **A route that cannot send claims nothing:** with no mail route no day is claimed, and with push not set up no number is raised.
- Nothing it logs, pushes or prints may name a person, a calendar or an event; a fault's own words are never logged.
- The e-mail's link comes from `PortalAddress::absolute()`, not `Site::url()`: a job has no organisation address of its own (#500). The
  dashboard card's link is `Site::url()`, because a page is drawn for its own organisation.
- Adding a notification switch: the key goes in `$defaults` in `auth/account/notifications.php` AND in `$allowedKeys` in `notifications-save.php`,
  in the same change. A switch that is not always shown (the push ones) must be carried in a hidden field while it is hidden, or Save switches it off.
- Cannot do: know what became of a kept message; keep a push from arriving at night; push about dates that count again because a paused calendar was
  switched back on; reach a global administrator by push on a device that never subscribed while in that organisation.

### The scheduled job

| Address | How often | Setting that holds its key |
| --- | --- | --- |
| `cron/import-feeds?key=<key>` | every 15 minutes | `feeds.cron_token` |

An empty stored key refuses every visit (403), and the comparison is constant-time. It sets its own time limit once, before its loop (`TimeLimit::atLeast()`), not inside it,
because `set_time_limit()` restarts the count: a job with two hundred calendars would otherwise never reach PHP's limit.
Its own budget (`JOB_BUDGET_SECONDS`, 50) is what stops it starting more calendars. It prints counts and
calendar NUMBERS only, never a name or an address: a scheduled task's output usually ends up in an e-mail. One line for the recheck pass, a second
(`notify: …`, figures only) for the notifier, one per calendar, then a summary. It is not on `Gatekeeper::OPEN_PATHS` (only `cron/mail-queue` is), so a scheduler cannot reach it on a pre-release copy's staff gate; and `Maintenance` lets only `cron/health` through, so it waits while an upgrade is in progress.

### Tests

Each refuses to pass if it checked nothing. Run them on a throwaway database (`docker run --rm`, built from `web/_sql/full_schema.sql`);
`feed-admin-selftest` needs a throwaway database SERVER of its own (it sets the server's time zone). Details are in each header.

- `tools/ics-reader-selftest.php` — PHP only. `tools/safefetch-selftest.php` — no database; starts a local test server.
- `tools/event-visibility-selftest.php`, `tools/feed-resolver-selftest.php` — a database.
- `tools/feed-importer-selftest.php` — a database and a test calendar server.
- `tools/feed-admin-selftest.php` — a database, and its part W drives the real pages through PHP's built-in server on a scratch copy of `web/`.
  It reads sentences in the help pages (W50), so changing the guide's wording means changing the check with it.
- `tools/import-notifier-selftest.php` — a database whose account may create a trigger; sends nothing.
- `ics-reader`, `event-visibility`, `feed-resolver`, `feed-importer` and `feed-admin` are in `.github/workflows/calendar-selftests.yml`
  (not yet run on GitHub: no pull request exists). **`safefetch-selftest.php` and `import-notifier-selftest.php` are in no workflow**; adding
  the second is a one-step change that needs the owner's yes (workflow file).

### What `check_event_visibility.py` cannot see

It finds a line that names `tblEvents` and looks 12 lines above to 25 below for a marker (`EventVisibility::`, `externalFeedID IS NULL` or `IS NOT NULL`). It
cannot see a query built far from the word (a table name assembled from a variable), a marker next to an unrelated query, a file on its
allow-list that later gains a new viewer-facing query, or whether the marker is spelled right or applied to the right alias. Those are for the
self-tests and a review. Its exit code is real, but on a pull request it runs inside the heuristic step that ends `|| true`, so a finding adds
to the bot's comment and does not fail the run.

### Left alone on purpose: `admin/sites/users.php`'s DELETE

Removing a person from an organisation deletes their `tblUserSites` row. Since #516 and #517 that cascades, through composite links, away their roles,
user-group memberships and department memberships IN THAT ORGANISATION ONLY. A removed person has no row and is refused by the rule by
construction, so nothing in #514 needed to change there. Do not "tidy" it to a soft switch-off without reading the rule's membership test.

### Not proved

A real Google or Microsoft 365 calendar (every test calendar is hand-written; two reader checks print SKIPPED); MariaDB; Apache; PHP 8.4; a
real mail or push service; the pull-request checks on GitHub; the organisation chosen by session or sub-domain (#502). **Codex has not reviewed
any of it.**

## The form token is replaced on every acceptance, and the answer must carry it (#634)

`Auth::verifyCsrf()` replaces the stored token whenever it accepts one. That is deliberate — it
stops a token being used twice. The consequence catches people out: **anything that posts without
reloading must be handed the replacement, or its second post is refused**, and the refusal is a 403
that most scripts ignore, so the control silently stops working.

- The house way is `ApiAuth::requireWrite()` with `ApiResponse`, whose `meta.csrfToken` exists for
  exactly this.
- `ApiAuth::noteCsrfRotation()` is for a handler that deliberately does NOT use `requireWrite()`,
  because its sign-in rules are its own and it must not accept an API key — a stream moderator, an
  event's coordinator, the person's own prayer request, the person's own tour. Call it immediately
  after a check that passed, and nowhere else: calling it without a passed check would hand out a
  token the request never earned.
- Return the replacement on the success **and on every refusal reached after the check**. A request
  turned away for some other reason has still spent the token. `ApiResponse::meta()` is reached by
  both `success()` and `error()`, so an ApiResponse handler gets this for free.
- **In a page, read the token at the moment of posting, never once at load**, and keep it somewhere
  you can reassign. A `const` cannot be updated at all, which is what made the Host Console's fault
  permanent rather than passing.
- `portal.js` writes a replacement back into the page's `<meta name="csrf-token">` tag for every
  answer it sees, success or refusal, and publishes `Portal.csrf()` and `Portal.rememberCsrf()`.
  **This only covers posts made through `Portal.fetch`, and the only caller of that under `web/` is
  `_apps/documents/index.php`.** A script using plain `fetch()` must call `Portal.rememberCsrf()` on
  its own answer — `portal-tour.js` does; `push-subscribe.js` and `report-builder.js` still do not.
- Three answer shapes carry the token, and `rememberCsrf()` reads all three: `meta.csrfToken` from
  an ApiResponse envelope, a top-level `csrfToken`, and a top-level `csrf_token` (the older
  spelling, which the two push handlers send).
- **A download cannot do any of this**, because its answer is the file and the page it was clicked
  from never reloads. `Auth::verifyCsrfWithoutSpending()` exists for that one case — a read-only GET
  — and must never be used for anything that writes. It gives up single use, which buys nothing on a
  request that changes nothing.
- The tripwire is `tools/audit-checks/check_csrf_rotation_returned.py`. Its header names the five
  shapes it cannot see; read them before trusting it.

## A unique key cannot cover a column that may be empty (#594, and #187 before it)

This has now been got wrong twice, in two tables. MySQL never treats one empty value (`NULL`) as
equal to another, so a unique key on `(siteID, somethingKey)` does **not** stop two portal-wide rows,
and an `ON DUPLICATE KEY UPDATE` against such a key silently INSERTS instead of updating. Every
replay then adds another copy.

The fix both times: a VIRTUAL generated column holding `COALESCE(siteID, -1)`, with the unique key on
that. It must be VIRTUAL and not STORED, because MySQL refuses a foreign key with ON DELETE CASCADE
on a table holding a STORED generated column (ERROR 1215).

**Before adding a table where "applies to every organisation" means an empty column, do this from
the start.** And when de-duplicating existing rows, think about which copy wins: migration 187 could
not simply keep the newest, because a replay re-seeds the row and the fresh copy would be newer than
an administrator's edit, so it tested "does this row differ from its own default?" first. Migration
222 can keep the newest only because nothing in the portal ever writes a portal-wide e-mail template
— `admin/email-templates/save.php` takes its organisation from `Site::id()` and writes overrides only.

---

Last updated: October 2026
