# Plan amendment, stage 3: the two-front-doors plan (#493) under fully separate channels

Written 13 September 2026 on Fable, against the working tree on `claude/alpha-wip`. No repository file was
edited. `git status` showed `web/_core/App.php`, `bootstrap.php`, `Gatekeeper.php` and `Logger.php` as
modified and uncommitted while this was written (other agents' work in progress); they were read as they are
and a half-finished edit there was never treated as the design. `web/_core/Door.php` and
`web/public_html/.door` do not exist yet — Step 2 of the plan has not been built.

**A builder follows this document.** It is the merged result of the re-walk (stage 1,
`plan-amend-1-rewalk.md`) and the challenge of it (stage 2, `plan-amend-2-challenge.md`). Every sound point
from the challenge is folded in; section 0.4 lists exactly where this document departs from the re-walk and
why. Where this document and any earlier document (the build plan, the 23 corrections, DEV_NOTES 3b and 3c,
the re-walk) disagree about channels, the deployment, the channel marker, the changeover or the health checks,
**this document wins**. The owner's decisions recorded on 13 September 2026 at the end of
`public-door-4-fable-3-corrections.md` are not re-opened.

Every factual claim is marked **PROVEN** (I read the line or ran the command on this machine) or
**INFERRED** (reasoning, documentation or experience — not observed here). Line numbers are from the working
tree at the moment of reading.

---

## 0. What this document replaces, precisely

### 0.1 In the 23 corrections (`public-door-4-fable-3-corrections.md`)

| Correction | Verdict | Where the replacement is |
| --- | --- | --- |
| 1 — host row required; `siteID` NULL | **Changed.** The tenant-safety half stands. The paragraph beginning "The three channels share one database" and the "add the alpha hostname first and only that" discipline are deleted. | Section 9 |
| 2 — refuse `..` and `.` | **Changed.** Pattern widened, target moved to the three channel-folder settings. | Section 3.1 |
| 3 — changeover order | **Replaced in full.** | Section 6 |
| 4 — `error.php`, CLI door, `Door::validate()`, mapping | **Changed.** Front controllers no longer read any file; bootstrap reads the marker from the channel root; the mapping changes (`alpha` → `alpha`); the fallback is an owner decision. | Section 2 |
| 5 — deploy workflow (5a-5e) | **Replaced in full**, keeping 5b's door-driven mirrors and 5c's two sign-in forms. | Section 3 |
| 6, 7, 8, 9, 11, 12, 13, 14, 17, 19, 20, 23 | **Unchanged.** | Section 8 has one line each |
| 10 — media served by PHP | **Stands; its "Why" paragraph is rewritten**, and one defect found in its own text is recorded. | Section 8 |
| 15 — one `web/.htaccess` + explicit grants | **Replaced in full.** No file at the channel root, no `Require all granted` in the door files. | Section 3.7 |
| 16 — probe both hostnames | **Changed.** Per channel and per door; the probe checks the channel, its source and the version. | Section 3.10 |
| 18 — same hosting user | **Changed.** Five hostnames, new folder names. | Sections 5 and 6 |
| 21 — `Logger.php` note | **Conditional.** Delete it if pkg4 has landed when Step 2 starts; the working tree's `Logger.php` was being edited as this was written. | Section 8 |
| 22 — line references | **Superseded.** | Section 12 |

### 0.2 In the build plan (`public-door-3-build-plan.md`)

- §0 C3 (the `.channel` sentence): replaced by section 2.
- Step 2 items 1, 2, 6 and 7 and its verification: replaced by section 7.
- Step 3 item 5 and its verification: replaced by section 7.
- Step 4, Step 5, Step 8: the notes in section 7 are added; content otherwise unchanged.
- Step 9: replaced by section 7.
- §7 in full — "The seven secrets", "The diff to `.github/workflows/deploy.yml`", "What the owner does, in
  order": replaced by sections 3 and 6.
- The bootstrap and `index.php` rows of §2, in the part that describes how the channel is decided: replaced by
  section 2.

### 0.3 In `DEV_NOTES.md`

Sections 3b (lines 405-634) and 3c (636-738) are replaced by sections 3 and 6 here. The "Manual Deploy
Override" section (336-340) is deleted (section 3.2). Section 10 lists the rest.

### 0.4 Where this departs from the re-walk (stage 1), and why

1. **One marker per installation, not one per web folder.** The re-walk (D4) kept the plan's per-door
   `.channel` inside `admin_html/` and `public_html/`, read by each front controller. Here the marker is
   `<channel root>/.channel`, read by bootstrap. Reason: one installation is one channel — the two doors share
   `_core/`, `_auth_keys/` and the database, so two copies could only ever agree or be wrong. A root marker
   also lets the deploy check the channel of the whole folder before it uploads anything, including the
   shared folders (challenge finding 1c). Section 2.
2. **The alpha channel becomes `PORTAL_ENV = 'alpha'`, not `'dev'`.** Reason: a grep of the whole tree finds
   only two places that test `PORTAL_ENV` for a specific word, both `=== 'prod'` (`App.php:266`,
   `Debug.php:162`); one dashboard badge colours itself on `'dev'` (`_apps/admin/index.php:257`). PROVEN. So
   nothing breaks, `Gatekeeper::VALID_CHANNELS` already lists `alpha` (`Gatekeeper.php:40`), the roles setting
   `portal.alphaAccessRoles` is already seeded (`full_schema.sql:1472`), and the in-app help is already right.
   And the health probe can then tell a lost marker (fallback) from a correct alpha marker. Section 2.3.
3. **The fallback becomes an owner decision, recommended fail-closed** (challenge finding 5). Section 2.4 and
   OWNER DECISIONS 1.
4. **No `.htaccess` at the channel root; a deny file inside each repository-owned folder instead**, and the
   `Require all granted` lines are dropped from the door files (challenge finding 2, taken one step further).
   Section 3.7.
5. **The manual `target` override is removed; the channel comes only from the branch** (challenge finding 1a).
   Section 3.2.
6. **All read-only checks run before any upload, and the channel root's marker is checked** (finding 1c).
   Section 3.5.
7. **The changeover uses the deploy kill switch so a real deploy cannot run before the dry run** (finding 3),
   starts with an inventory of the panel (finding 7), and forks on whether the old base holds an installation.
   Section 6.
8. **Probe 3 tolerates the maintenance window; probe 1 tolerates "not installed yet"** (finding 4 plus one
   case the challenge did not have). Section 3.10.
9. **`mkdir` still runs in a dry run.** The challenge asked to skip it or print instead; here it runs, and the
   document says why and what it costs. Section 3.9.

### 0.5 Which challenge findings were adopted

All nine, plus the relabels in its section 4. Findings 1, 2, 3, 4, 6, 7, 8 and every bullet of 9 are applied
as written or strengthened. Finding 5 is put to the owner with the challenge's recommendation. The one point
handled differently is the dry-run `mkdir` (section 3.9).

---

## 1. The facts everything rests on

**F1. A channel folder is an installation.** `bootstrap.php:55` sets `PORTAL_ROOT` to the folder above
`_core/`; the database login is read from `PORTAL_ROOT/_auth_keys/auth_creds.php` (`:302-308`), the
encryption key from `PORTAL_ROOT/_auth_keys/enc.key` (`:210`, `:251`). The installer uses the same rule
(`_install/index.php:36-41`) and writes `auth_creds.php`, `enc.key` and the `.installed` lock into that
folder's `_auth_keys/` (`:958-983`, `:1022`), recording `portal.installed_version` in that database
(`:985-1005`). PROVEN. Nothing in the code chooses anything per channel.

**F2. Live's channel folder is the old shared base.** Under option C, `SFTP_PATH_ROOT_DIR` = `/home/USER/` and
`SFTP_PATH_LIVE_DIR` = `portal.millrdsdacambridge.uk/`, so live's root is
`/home/USER/portal.millrdsdacambridge.uk/` — where the old workflow put the shared code (`deploy.yml:32-37`
and `:192`) and the value the owner set into `SFTP_PATH_ROOT_DIR` on 11 September (DEV_NOTES 3b:445). PROVEN
from the documents. **What is actually on the server is unknown to everyone who wrote these documents**; the
HANDOFF says "Nothing is deployed yet" (`:182`) while DEV_NOTES 3b says the account "already runs three web
roots" (`:611`). Section 6 step 0 is where that is found out.

**F3. The folder name can no longer say which channel this is, and the wrong guess lands on live's staff
side.** `bootstrap.php:92-109`: no `PORTAL_ENV` variable → look at `DOCUMENT_ROOT`; `public_html_dev` or
`alpha_html` → `dev`; `public_html_beta` or `beta_html` → `beta`; anything containing `public_html` → `prod`;
else `dev` with source `fallback`. Then `:147-156` displays PHP errors whenever `PORTAL_ENV` is not `prod`.
PROVEN. In the new layout every staff folder is `…/admin_html` (→ `dev` → errors shown, on live) and every
public folder is `…/public_html` (→ `prod`, even alpha's). Only a file the deploy writes can say the channel.

**F4. The old workflow cannot run.** It reads `SFTP_LIVE_PATH` / `SFTP_BETA_PATH` / `SFTP_DEV_PATH`
(`deploy.yml:154-156`), stops when the one for the channel is empty (`:185-188`), and DEV_NOTES 3b:568 records
all three as gone. Nothing reads any `SFTP_PATH_*` name yet. PROVEN. So no accidental old-style deploy can
happen while the settings are changed.

**F5. Sessions never cross hostnames.** `Auth.php:82` sets the cookie domain to `''`. PROVEN.

**F6. iHymns does this already.** Deploys serialised per branch with `cancel-in-progress: false`
(`iHymns deploy.yml:79-81`, reasons at `:55-78`); the channel word written into a file by the deploy
(`:515-522`); shared pieces put beside each channel's own folder (`:1014`, `:1073`); built paths masked in the
log (`:1016-1020`, `:1075-1078`); PHP reads the file as a fallback after a folder-name check
(`environment.php:27-40`); the database login two folders above the web root (`db_mysql.php:56`). PROVEN.
WebMS cannot copy iHymns' order (folder first, file second): its folder names now say the wrong thing (F3).

**F7. `/health` is blocked by the maintenance gate whenever the code is newer than the database.**
`Maintenance::ALLOW_LIST` (`Maintenance.php:56-118`) does not contain `health`; `isActive()` (`:124-151`) is
true when `portal.installed_version` is behind `PORTAL_VERSION`; `index.php:57-62` renders the 503 before the
Router sees `/health`. `version-bump.yml` bumps PATCH on every alpha push. PROVEN. Without a change, the
post-deploy probe would fail for the wrong reason after most alpha deploys.

**F8. Only two lines test `PORTAL_ENV` for a particular word.** `App.php:266` and `Debug.php:162`, both
`=== 'prod'`. Everything else displays it (`Router.php:572`, `admin/index.php:141`, `system-info/index.php:384`,
`admin/integrations/monitoring/index.php:71`) or colours a badge (`admin/index.php:257`). PROVEN by grep.

**F9. `lftp` is not installed on this machine.** PROVEN (`which lftp` → not found). Every statement about
lftp's behaviour in this document is INFERRED and is marked where it matters.

**F10. Twenty-one other files `require_once` bootstrap, all reached through the Router after `index.php` has
loaded it**, and `bootstrap.php:36-38` returns early when `PORTAL_ROOT` is defined. PROVEN. The "refuse when
no door is defined" rule sits after that early return (Correction 4), so none of them can trip it. The two
that load bootstrap on their own are `error.php:30-44` and `tools/offsite-backup/log-offsite-result.php:18-23`
(command line only). PROVEN.

**F11. Three `portal.*AccessRoles` rows are seeded**: `devAccessRoles` (`full_schema.sql:1468`),
`alphaAccessRoles` (`:1472`), `betaAccessRoles` (`:1476`). PROVEN.

**F12. Ten scheduled-job addresses are seeded** under `cron/` in `full_schema.sql`, matching the ten files in
`web/_apps/cron/`; each reads its token from that installation's settings (for example
`event-reminders.php:41`). PROVEN by count.

---

## 2. The channel marker

### 2.1 One file, at the channel root

- **Name and place:** `.channel`, in the channel root — `/home/USER/<channel folder>/.channel`, beside `_core/`.
  One line, one word.
- **Written by:** the deploy, into `web/.channel` in the checkout, then uploaded with a plain `put` as the first
  upload of the run (section 3.6). Never by hand on a server.
- **Not committed:** `.gitignore` gains `/web/.channel`. It has no such line today (PROVEN, file read in full).
- **Not excluded from upload:** it is not mirrored at all — it is `put` — so `LFTP_EXCLUDES` is irrelevant to it.
- **Unreachable from the web:** it sits outside both web folders. (The per-door `.door` files are inside the web
  folders and are covered by the dot-file rule at `.htaccess:29-30`, PROVEN; the public door's `.htaccess` must
  carry the same rule — challenge finding 9.)
- **Why one file and not two.** One installation is one channel: both doors read the same `_core/`, the same
  `_auth_keys/`, the same database (F1). Two copies could only agree or be wrong. And a root marker is what
  lets the deploy ask "does this whole folder belong to the channel I am about to deploy?" before it touches
  `_core/` — the check that closes challenge finding 1c for the shared folders too, not only for the doors.

### 2.2 Who reads it, and in what order

Bootstrap, immediately after `PORTAL_ROOT`, through one pure function in a new `web/_core/Door.php`:

```
Door::validate(string $sapi, ?string $door, ?string $webroot, string $envVar, ?string $fileWord)
    : array{refusal: ?string, env: string, source: string}
```

- Bootstrap supplies `$fileWord` as the trimmed first line of `PORTAL_ROOT . '/.channel'`, or `null` when the
  file is missing or unreadable (`@file_get_contents`, never a warning).
- `$envVar` is `getenv('PORTAL_ENV') ?: ''`.
- **Command line** (`PHP_SAPI === 'cli'`): `PORTAL_DOOR = 'cli'`, `PORTAL_WEBROOT = ''`; channel from the
  environment variable, else the file, else fallback; never a refusal; the header block and the document-root
  refusal are skipped. This keeps `tools/offsite-backup/log-offsite-result.php` working (F10).
- **Web** with `PORTAL_DOOR` or `PORTAL_WEBROOT` undefined → refusal: HTTP 500, one line, `exit`. This is the
  "stale front controller" guard from Correction 4 and it is unchanged.
- **Channel:** `$envVar` non-empty → `env = $envVar`, `source = 'environment'`. Else `$fileWord` in the mapping
  table below → `source = 'file'`. Else → the fallback (2.4), `source = 'fallback'`, one `error_log()` line per
  request naming `PORTAL_ROOT` and the word found (or "missing").
- **Front controllers define only `PORTAL_DOOR` and `PORTAL_WEBROOT`.** Correction 4's `PORTAL_CHANNEL`
  constant is dropped: `web/public_html/index.php`, `web/public_html/error.php` (inside its `try`, before the
  `require_once` at `:35`) and, from Step 4, the public door's own pair define the two constants and nothing
  else. No front controller reads a file.
- The self-test (plan §8) calls `Door::validate()` with `('apache2handler', null, null, '', null)` and asserts
  a refusal; with `('cli', null, null, '', null)` and asserts none; with each word in the table and asserts the
  mapped `env` and `source = 'file'`; with `'nonsense'` and with `null` and asserts the fallback.

### 2.3 The mapping

| Where the word comes from | word | `PORTAL_ENV` | `PORTAL_ENV_SOURCE` | gated by `Gatekeeper`? | roles setting read |
| --- | --- | --- | --- | --- | --- |
| deploy of `main` | `live` | `prod` | `file` | never | — |
| deploy of `beta` | `beta` | `beta` | `file` | yes | `portal.betaAccessRoles` |
| deploy of `alpha` | `alpha` | `alpha` | `file` | yes | `portal.alphaAccessRoles` |
| hand-written, local work | `dev` | `dev` | `file` | yes | `portal.devAccessRoles` |
| hand-written, local work | `prod` | `prod` | `file` | never | — |
| `PORTAL_ENV` environment variable | any | as given | `environment` | if on the list | `portal.<word>AccessRoles` |
| missing, unreadable, empty, or any other word | — | see 2.4 | `fallback` | see 2.4 | see 2.4 |

The marker word IS the channel word the deploy uses (`live`/`beta`/`alpha`), never the branch name.
`Gatekeeper::VALID_CHANNELS` stays `['alpha', 'beta', 'dev']` (`Gatekeeper.php:40`). `Gatekeeper::enforce()`
builds the roles key from the word (`:212`), which is why `alpha` reads `portal.alphaAccessRoles` — exactly
what the in-app help promises (`help/admin.php:406`). `PORTAL_ENV_SOURCE`'s value `'folder'` no longer exists;
the comparison at `Gatekeeper.php:302` changes (2.5).

### 2.4 When the marker is missing, unreadable, empty or unknown — OWNER DECISION 1

Two designs. The builder implements whichever the owner picks; the recommendation is the first.

**A — fail closed (recommended).** `PORTAL_ENV = 'dev'`, `PORTAL_ENV_SOURCE = 'fallback'`. The pre-release gate
runs (a fallback is on the gated list because `dev` is). PHP errors are hidden: the display rule at
`bootstrap.php:147-156` becomes "display only when `PORTAL_ENV !== 'prod'` AND `PORTAL_ENV_SOURCE !==
'fallback'`". Every public-door page carries `noindex` (Step 4 already does that for any non-live channel; the
rule becomes "live means `prod` AND source is not `fallback`"). One `error_log()` line per request.
`/health` reports `channelSource: "fallback"` (2.7), so the deploy probe fails.
What happens if the file vanishes on live: members meet the sign-in page; administrators pass the gate by
the flag on their record (`Gatekeeper.php:189-202`, PROVEN), see the state, and a re-run of the `main` deploy
restores the file in a minute. Loud and recoverable. What happens on alpha: it stays gated.

**B — today's Correction 4 default.** `PORTAL_ENV = 'prod'`, source `fallback`, gate off, errors hidden,
`noindex` on the public door, the same log line, plus — added here so it is at least visible — a red banner on
the admin dashboard when `PORTAL_ENV_SOURCE === 'fallback'`, and the `/health` field. What happens on live:
nothing visible to members. What happens on alpha: **the unfinished code is open to the world with errors
hidden until somebody notices**, which on shared hosting is when the next deploy runs.

Why this is a decision and not a default: the project's own rule is "prefer the fix that cannot fail quietly".
A's cost is a visible outage for members if a one-line file is ever deleted by hand — the deploy rewrites it
on every run, `--delete` never touches it (it is not inside any mirrored folder), and a DreamHost restore
brings it back with everything else, so the realistic cause is a person tidying up. B's cost is a pre-release
copy open to the public with nothing louder than a log line. The installer is untouched either way: it runs
before bootstrap (`index.php:15-26`, PROVEN) and never reads the marker.

### 2.5 `Gatekeeper` changes

- `shouldEnforce()`: under A, the source test at `:302-310` goes — the channel is gated whenever it is on the
  list and the switch is not `false`; when the source is `fallback` it still writes the log line, but gates.
  Under B, the test keeps refusing `fallback` and accepts `environment` or `file`; `folder` is gone either way.
- The two comment blocks that describe the old world (`:5-9`, `:17-25`, `:232-254`) are rewritten: the channel
  now comes from `<channel root>/.channel`, written by the deploy.
- Nothing else changes: `enforce()`, the open lists, the roles lookup and the admin-flag pass are as they are.

### 2.6 Local development

Unchanged: `PORTAL_ENV=dev php -S localhost:8080 -t public_html` (DEV_NOTES 1344-1348) — the environment
variable wins. Alternatively write the word `dev` into `web/.channel` by hand; it is gitignored.

### 2.7 `/health` says how it decided

`Router::healthCheck()` (`Router.php:545-579`) already answers `env` and `version` as JSON (`:570-578`, PROVEN).
It gains one field, `channelSource`, with `PORTAL_ENV_SOURCE`. The deploy probe (3.10) asserts it is `file`.
This is the only thing that proves, per channel, that the marker was read on the real server; `env` alone
cannot separate a correct alpha marker from a lost one under design A. It reveals one word; nothing else.

### 2.8 `.door` files (unchanged from Correction 5b, restated for completeness)

Per door, committed. At Step 2 `web/public_html/.door` contains `admin`; at Step 3 the file moves with the
folder to `web/admin_html/.door`; at Step 4 a new `web/public_html/.door` contains `public`. The deploy reads
them locally to choose targets (3.5), fetches the remote copy to refuse a wrong folder, and mirrors them with
the door. `LFTP_EXCLUDES` (`deploy.yml:104-122`) has no pattern that matches `.door` — PROVEN — so it reaches
the server.

---

## 3. The deployment workflow, exactly

### 3.1 Settings, how they combine, and the slash rules

**Read (secrets):** `SFTP_PATH_ROOT_DIR`, `SFTP_PATH_LIVE_DIR`, `SFTP_PATH_BETA_DIR`, `SFTP_PATH_ALPHA_DIR`, plus
the unchanged `SFTP_HOST`, `SFTP_USER`, `SFTP_PASSWORD`, `SFTP_PORT`, `SFTP_KEY`.
**Read (variables):** `SFTP_ENABLED` (the kill switch, `deploy.yml:94`); `ADMIN_BASE_URL_LIVE`,
`ADMIN_BASE_URL_BETA`, `ADMIN_BASE_URL_ALPHA`, `PUBLIC_BASE_URL_LIVE`, `PUBLIC_BASE_URL_BETA`,
`PUBLIC_BASE_URL_ALPHA` (probe addresses; empty means "skip that door with a warning").
**Warned about, never refused** (organisation secrets are shared between repositories — DEV_NOTES 3b:577-586):
`SFTP_LIVE_PATH`, `SFTP_BETA_PATH`, `SFTP_DEV_PATH`, and the six `SFTP_PATH_<LIVE|BETA|ALPHA>_<ADMIN|PUBLIC>_DIR`.
Each is referenced in the step's `env:` and, if non-empty, produces one `::warning::` with the two-line
explanation from DEV_NOTES 3b.

**All three folder settings are required on every run**, whichever channel is being deployed, so that the
distinctness rule below can be checked.

```
ROOT = SFTP_PATH_ROOT_DIR
  - required; refuse if empty
  - trim ONE trailing "/"
  - must match ^/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$  — exactly two segments, e.g. /home/dh_abcd1234
    (refuses "/", a relative path, any "." or ".." segment, and any THREE-segment value —
     "/home/USER/portal.millrdsdacambridge.uk" is the value set on 11 September and is now WRONG)

CHAN_DIR_X = SFTP_PATH_<X>_DIR  for X in LIVE, BETA, ALPHA
  - required; refuse if empty, naming which
  - trim ONE trailing "/"
  - must match ^[A-Za-z0-9_-][A-Za-z0-9._-]*$
    (letters, digits, underscore, hyphen; dots INSIDE are fine because DreamHost domain folders
     contain them; refuses a leading "/", a leading ".", ".", "..", hidden folders, and any inner "/")
  - the three trimmed values must be pairwise different, else refuse
    ("SFTP_PATH_ALPHA_DIR and SFTP_PATH_LIVE_DIR name the same folder — an alpha deploy would
      overwrite the live channel")

CHANNEL_ROOT  = $ROOT/$CHAN_DIR          e.g. /home/dh_abcd1234/portal.millrdsdacambridge.uk
REMOTE_ADMIN  = $CHANNEL_ROOT/admin_html    fixed name, same as the repository
REMOTE_PUBLIC = $CHANNEL_ROOT/public_html   fixed name, same as the repository
```

Why two segments is enforced rather than documented: the one likely mistake is leaving `SFTP_PATH_ROOT_DIR` at
its 11 September value. With live that would build `…/portal.x/portal.x/`; with alpha it would build
`/home/USER/portal.x/alpha.portal.x/` — a nested, plausible, wrong place that a hostname would then be pointed
at. Two segments refuses both before anything is uploaded. It deliberately encodes "the DreamHost home folder"
(`/home/USER`); a host with deeper home folders would need this one rule relaxed.

Every refusal is `::error::` naming the setting, the rule and an example value, and nothing is uploaded before
the resolution step finishes. Every built path is masked (`echo "::add-mask::$CHANNEL_ROOT"` and the two door
paths) — GitHub masks a value that exactly equals a secret, and a path built from one is otherwise printed
(iHymns `deploy.yml:1016-1020`, PROVEN precedent). Consequence for the owner: dry-run logs show `***` where the
folder would be; what the log DOES show is every file that would be uploaded or deleted, which is the
destructive direction. Folder identity is proved by the marker checks and the health probe, not by eye.

### 3.2 The channel comes from the branch, and only from the branch

The `target` input (`deploy.yml:72-81`) and the override at `:158-164` are **deleted**. Keep `dry_run`.
`github.ref_name` → `main` = `live`, `beta` = `beta`, `alpha` = `alpha`; anything else → `::error::` and stop
(the trigger already restricts pushes to those three branches, `:61-66`).

Why: the owner's decision is that channels are "separated at repo level only by branch … purely by GitHub
Action deployment based on source branch". An override that deploys the files of one branch under another
channel's name is the opposite of that (challenge finding 1a): "Run workflow" from `alpha` with target `main`
would put alpha's files into live's folder with a marker saying `live`, and the health probe would pass,
because `App::version()` reads `_core/version.php` from disk (`App.php:348-356`, PROVEN) and the probe compares
it with the same checkout. The only automated dispatcher, `auto-merge-alpha.yml` ("Wait for merge and dispatch
deploy", `gh workflow run deploy.yml --repo "$REPO" --ref alpha`), passes no target — PROVEN — so nothing needs
the override. To redeploy live, run the workflow from `main`. DEV_NOTES 336-340 ("Manual Deploy Override") is
deleted.

**Concurrency** (iHymns `deploy.yml:79-81`, and its reasons at `:55-78`, PROVEN):

```yaml
concurrency:
  group: deploy-${{ github.ref_name }}
  cancel-in-progress: false
```

Per channel, queued, never cancelled: a cancelled half-finished `--delete` mirror leaves files deleted and
their replacements never uploaded. Back-to-back deploys are real here: a human push and the auto-merge bridge's
dispatch can land minutes apart. `version-bump.yml` pushes with `GITHUB_TOKEN` and `[skip ci]`, which does not
re-trigger this workflow (the anti-recursion rule `auto-merge-alpha.yml` relies on) — PROVEN from both files.

### 3.3 The steps, in order

Every `run:` block starts with `set -euo pipefail`. Secrets reach a step only through `env:`.

| # | Step | Connects? | Runs in dry run? |
| --- | --- | --- | --- |
| A1 | Checkout (`fetch-depth: 0`) | no | yes |
| A2 | Resolve settings (3.1) and channel (3.2); mask paths | no | yes |
| A3 | Tree guards (3.4) | no | yes |
| A4 | Lint PHP; change detection (`[deploy all]`, dispatch always deploys) — as today | no | yes |
| A5 | Fetch dompdf; stage `CHANGELOG.md`; write `web/.channel` | no | yes |
| A6 | Install lftp; resolve port; choose sign-in method; stage key — as today | no | yes |
| B | Remote read-only probes (3.5); decide proceed/refuse | read only | yes |
| C | `mkdir -p -f` the channel root, both door targets if a local door maps to them, and `_auth_keys/`, `_uploads/`, `_backups/` | yes | yes (3.9) |
| D1 | `put web/.channel` → channel root | yes | no |
| D2 | Door mirrors, `--delete`, one per local door (3.6) | yes | yes, `--dry-run` |
| D3 | Shared folders, `--delete`, ONE FOLDER AT A TIME (3.6) | yes | yes, `--dry-run` |
| D4 | `put web/CHANGELOG.md` → channel root | yes | no |
| D5 | `put` the deny file into `_auth_keys/`, `_uploads/`, `_backups/` (3.7) | yes | no |
| E | Health probes (3.10) | HTTP | yes (they are read-only) |
| F | Clean up the key; summary (3.11) | no | yes |

Before every lftp call in C and D, assert: the target variable is non-empty (`: "${REMOTE_ADMIN:?}"`), starts
with `"$CHANNEL_ROOT/"` (doors and shared folders) or equals it (the puts), and ends in the expected fixed
name; the source directory exists. `steps.target.outputs.public_dir` (`deploy.yml:435`), which no step ever
sets, is the proof that an unwired output is not hypothetical (challenge finding 6).

Order inside D, and why: the marker first, so that if the run dies afterwards the folder already says what it
is; doors before shared folders, because a new front controller with an old bootstrap works (`index.php:141`
guards `PORTAL_ENV_SOURCE` with `defined()`, PROVEN) while a new bootstrap with an old front controller refuses
(Correction 4) — so doors-first has no refusal window on ordinary deploys; top-level files and deny files last.

### 3.4 Tree guards (no connection yet)

1. At least one of `web/admin_html/.door`, `web/public_html/.door` exists; a tree with neither predates Step 2
   → refuse "this branch predates the two-door change". Each present `.door` is exactly `admin` or `public`;
   two local doors must not claim the same word. A door whose `.door` says `admin` must contain `index.php`
   and `.htaccess` (Correction 5b).
2. `web/_core/bootstrap.php` must contain the text `.channel` → else refuse "this tree does not read the
   channel file; in the new folder layout it would show PHP errors on the live staff side". This is what makes
   section 4's ordering mechanical: the new folder layout can only be created by a deploy that also ships the
   file-reading bootstrap and writes the marker.
3. Every top-level entry of `web/` (after A5) must be in one of these lists, else refuse "add it to
   SHARED_DIRS or NOT_DEPLOYED":
   - `DOOR_DIRS = admin_html public_html`
   - `SHARED_DIRS = _apps _core _functions _includes _install _lang _sql _vendor _libraries`
   - `NOT_DEPLOYED = private_html public_html_landing public_html_redir _auth_keys _uploads _backups`
   - `TOP_FILES = CHANGELOG.md .channel`
   (`ls -A web/` today: `_apps _core _functions _includes _install _lang _sql _vendor private_html
   public_html public_html_landing public_html_redir` — PROVEN; `_libraries`, `_auth_keys`, `_uploads`,
   `_backups` are gitignored, `.gitignore:27,32,35,39`.)
4. Each of `_core _apps _sql _lang _vendor _install` holds at least one file → else refuse; `_includes` and
   `_functions` hold only `.gitkeep` today (PROVEN) and are mirrored as they are. `_libraries/dompdf/VERSION.txt`
   must exist (written by `tools/download-dompdf.sh:60`, PROVEN) → else refuse, so a half-fetched dompdf can
   never empty a channel's `_libraries/` through `--delete`. (`download-dompdf.sh` runs under `set -euo
   pipefail` and exits 1 on a failed download, `:21`, `:52-55` — belt and braces.)

### 3.5 Remote checks — all of them before any upload

One shell function runs every lftp session in whichever sign-in form applies, replacing today's duplicated
key/password steps (`deploy.yml:305-341`, `:363-405`). Both forms are exactly the ones in use today
(`:316-322` key, `:336-341` password — PROVEN):

```bash
# $1 = lftp commands, separated by ";". Sign-in form from steps.auth.outputs.method.
run_lftp() {
  local cmds="$1"
  if [[ "$AUTH_METHOD" == "key" ]]; then
    printf '%s\n' \
      "set sftp:connect-program \"ssh -o StrictHostKeyChecking=no -i $HOME/.ssh/deploy_key -p $SFTP_PORT\"" \
      "open sftp://$SFTP_USER@$SFTP_HOST:$SFTP_PORT" \
      "$cmds" "bye" > /tmp/lftp_cmds.txt
    lftp -f /tmp/lftp_cmds.txt
  else
    lftp -u "$SFTP_USER,$SFTP_PASS" \
      -e "set sftp:auto-confirm yes; set ssl:verify-certificate no; $cmds; bye" \
      "sftp://$SFTP_HOST:$SFTP_PORT"
  fi
}
```

The probes never depend on lftp's exit status (challenge finding 9 — `cls` on a missing path is unproven, and
`lftp` cannot be tested here, F9). Each fetches to a local file or captures a listing, and the shell inspects
what it got:

```bash
# 1. The channel root's own marker.
rm -f /tmp/remote.channel
run_lftp "get \"$CHANNEL_ROOT/.channel\" -o /tmp/remote.channel" || true
if [[ -s /tmp/remote.channel ]]; then
  FOUND="$(head -n1 /tmp/remote.channel | tr -d '[:space:]')"
  [[ "$FOUND" == "$CHAN" ]] || { echo "::error::REFUSING: the folder for '$CHAN' says it belongs to the '$FOUND' channel"; exit 1; }
fi
# absent → proceed (a first deploy, or the old shared base before its first deploy under this workflow)

# 2. Per local door (web/admin_html, web/public_html — whichever exist).
for LOCAL in web/admin_html web/public_html; do
  [[ -d "$LOCAL" ]] || continue
  WANT="$(tr -d '[:space:]' < "$LOCAL/.door")"
  case "$WANT" in admin) TARGET="$REMOTE_ADMIN";; public) TARGET="$REMOTE_PUBLIC";; esac
  rm -f /tmp/remote.door /tmp/remote.index
  run_lftp "get \"$TARGET/.door\" -o /tmp/remote.door" || true
  if [[ -s /tmp/remote.door ]]; then
    HAVE="$(tr -d '[:space:]' < /tmp/remote.door)"
    [[ "$HAVE" == "$WANT" ]] || { echo "::error::REFUSING: $TARGET says it is the '$HAVE' door; this run would write the '$WANT' door there"; exit 1; }
  else
    run_lftp "cls -1 \"$TARGET/index.php\"" > /tmp/remote.index 2>/dev/null || true
    if grep -q 'index\.php$' /tmp/remote.index; then
      echo "::error::REFUSING: $TARGET holds a portal with no marker — if it is the OLD management portal, delete it by hand (section 6), then re-run"; exit 1
    fi
    # absent, or a placeholder folder DreamHost created when the website was added → proceed; --delete clears a placeholder
  fi
done
```

Decision table, in words:

| The folder … | Result |
| --- | --- |
| channel root has `.channel` = this channel | proceed |
| channel root has `.channel` ≠ this channel | **refuse** — catches a wrong secret (1b) and any mix-up the other rules miss, before anything is uploaded |
| channel root has no `.channel` | proceed (first deploy, or the old base) |
| door target has `.door` = the local door's word | proceed |
| door target has a different `.door` | **refuse** |
| door target has no `.door` and holds `index.php` | **refuse** — the old management portal, or an unknown portal |
| door target absent, or present without `index.php` | proceed — created or cleared by the mirror |

To confirm on the first alpha dry run (these run in a dry run, so the proof is free): a missing remote file
produces no local file; a listing of a missing `index.php` prints nothing. Both INFERRED.

### 3.6 What is uploaded where — the exact commands

`$DRY` is `--dry-run` or empty (`deploy.yml:103`). `set cmd:fail-exit yes` makes a failed command abort the
lftp script, so a failed `mkdir` or `get` can never let a later `mirror --delete` run on a wrong assumption —
INFERRED on the setting's name; confirm on the runner with `lftp -c 'set -a' | grep fail-exit` and, if the
name differs, use whatever the man page there gives. Phase C runs WITHOUT it (a `mkdir` of a folder that
already exists must not abort anything; today's workflow already uses `mkdir -p -f` on existing folders,
`deploy.yml:377-379`, PROVEN).

```bash
# C — folders. Runs in a dry run too (3.9).
run_lftp "mkdir -p -f $CHANNEL_ROOT; mkdir -p -f $CHANNEL_ROOT/_auth_keys; mkdir -p -f $CHANNEL_ROOT/_uploads; mkdir -p -f $CHANNEL_ROOT/_backups"
for LOCAL in web/admin_html web/public_html; do [[ -d "$LOCAL" ]] && run_lftp "mkdir -p -f $(target_for "$LOCAL")"; done

# D1 — the marker. First upload of the run. Skipped in a dry run (put has no dry run).
[[ -z "$DRY" ]] && run_lftp "set cmd:fail-exit yes; put web/.channel -o $CHANNEL_ROOT/.channel"

# D2 — doors. One mirror per local door, target chosen by its .door word.
for LOCAL in web/admin_html web/public_html; do
  [[ -d "$LOCAL" ]] || continue
  TARGET="$(target_for "$LOCAL")"
  run_lftp "set cmd:fail-exit yes; mirror --reverse --delete $DRY --verbose --only-newer --no-empty-dirs \
    $LFTP_EXCLUDES --exclude ^\.well-known/ $LOCAL/ $TARGET/"
done

# D3 — shared code, ONE FOLDER AT A TIME. Never "web/" as a whole.
for f in _apps _core _functions _includes _install _lang _sql _vendor _libraries; do
  run_lftp "set cmd:fail-exit yes; mirror --reverse --delete $DRY --verbose --only-newer --no-empty-dirs \
    $LFTP_EXCLUDES web/$f/ $CHANNEL_ROOT/$f/"
done

# D4 — top-level file. Skipped in a dry run.
[[ -z "$DRY" ]] && run_lftp "set cmd:fail-exit yes; put web/CHANGELOG.md -o $CHANNEL_ROOT/CHANGELOG.md"

# D5 — the deny file into the three server-managed folders (3.7). Skipped in a dry run.
[[ -z "$DRY" ]] && for d in _auth_keys _uploads _backups; do
  run_lftp "set cmd:fail-exit yes; put web/_core/.htaccess -o $CHANNEL_ROOT/$d/.htaccess"
done
```

Several lftp sessions per deploy is the price of per-folder scoping; combining the D3 mirrors into one script
with several `mirror` lines is equivalent and fine. `$LFTP_EXCLUDES` is today's list (`deploy.yml:104-122`);
`WEB_ROOT_EXCLUDES` (`:128-137`) is **deleted** — nothing mirrors `web/` any more, so there is nothing for it
to protect.

**Why one folder at a time, and never `web/` (the re-walk's D3, unchanged and load-bearing).** Live's channel
root is the old base (F2). One `mirror --reverse --delete` of `web/` into it removes everything there that is
neither in the repository nor on an exclusion list: the old `public_html_beta/` and `public_html_dev/`,
`public_html_landing/`, `public_html_redir/`, `private_html/`, any `.well-known/`, anything DreamHost or the
owner placed there — and, if one line of the list is mistyped, `_auth_keys/`, `_uploads/` or `_backups/`. An
exclusion list fails invisibly and in the destructive direction. With per-folder mirrors a `--delete` can only
act INSIDE `_core/`, `_apps/`, `_sql/`, `_lang/`, `_vendor/`, `_install/`, `_includes/`, `_functions/`,
`_libraries/` and the two door folders — all of which the repository owns completely. Everything else at the
channel root is structurally untouched, not list-protected. The cost — a new top-level folder does not deploy
until it is added to `SHARED_DIRS` — is turned from a silent miss into a refusal by tree guard 3.

**What can never be deleted, and why each is safe:**
- `_auth_keys/`, `_uploads/`, `_backups/` — never named in any mirror; only `mkdir -p -f` and one `put` of a
  deny file. `enc.key` cannot be recovered (DEV_NOTES 3c:671-683).
- `.well-known/` — lives INSIDE the web folders (`.htaccess:29` exempts it from the dot-file block, PROVEN, so
  the project already expects it there), which are mirrored with `--delete`; hence `--exclude ^\.well-known/`
  on both door mirrors. Whether lftp's `--exclude` also protects an excluded REMOTE folder from `--delete` is
  INFERRED (today's workflow relies on it for `_auth_keys/`, comment at `deploy.yml:351-356`). Prove it on the
  first alpha dry run: create `<alpha>/admin_html/.well-known/probe.txt` by SFTP, run `dry_run`, confirm the
  log does not list it for deletion, delete the probe.
- The old-layout folders and DreamHost's own extras at a channel root — never inside a mirrored folder.
- Inside `_libraries/`: only what the build puts there survives, as today (`deploy.yml:380` has `--delete` and
  `_libraries` is not excluded — PROVEN; the comment at `:123-127` claiming otherwise is wrong, as DEV_NOTES
  3c:724-731 already says). Now per channel: three copies of dompdf, harmless.

### 3.7 Deny files — replaces Correction 15

**No file at the channel root, ever.** Live's channel root also holds `public_html_landing/`,
`public_html_redir/` and `private_html/` (placeholders in the repository — PROVEN, `web/public_html_landing/
index.html` "Pre-launch landing page", `web/public_html_redir/index.html`; the server copies "may serve other
addresses", hosting facts §4 — PROVEN the documents say so, unknown whether any hostname still points there).
Apache reads the `.htaccess` in every folder on the path from the filesystem root down to the requested file
(INFERRED — standard Apache behaviour), so a deny at `/home/USER/portal.x/.htaccess` would also deny those
neighbours, silently, and would be read on EVERY request to both doors — so a directive DreamHost's
`AllowOverride` does not permit would answer 500 everywhere. The repository proves only that `FileInfo`
directives work (`web/public_html/.htaccess` uses `RewriteEngine`, `RewriteRule`, `ErrorDocument`, `Header`,
`:25-96`, PROVEN); `Require` is `AuthConfig` and `Options` is `Options`, neither proven.

**Instead: one identical deny file inside each repository-owned shared folder**, so it travels with that
folder's own mirror: `web/_core/.htaccess`, `web/_apps/.htaccess`, `web/_sql/.htaccess`, `web/_lang/.htaccess`,
`web/_vendor/.htaccess`, `web/_includes/.htaccess`, `web/_functions/.htaccess`, and the same directives added
to `web/_install/.htaccess` (today only `RewriteEngine Off`, PROVEN — the installer is reached by `require` from
`index.php:19-21`, never by address, PROVEN, so denying direct access changes nothing). For `_libraries/`, the
dompdf fetch step copies `web/_core/.htaccess` into `web/_libraries/.htaccess` before upload. For
`_auth_keys/`, `_uploads/` and `_backups/` — never mirrored — step D5 `put`s the same file. That is the ONE
write into a server-managed folder in the whole design: one file, never a delete, the same content every time,
and the only way to cover those three folders without a root file. Say so in the workflow comment.

Contents (the same in every copy):

```apache
# Path: _core/.htaccess  (identical copies live in _apps, _sql, _lang, _vendor, _install, _includes,
#                         _functions, _libraries, and are placed by the deploy into _auth_keys, _uploads,
#                         _backups)
# This folder is never inside a web directory, so in normal operation Apache never reads this file.
# It is read in exactly one situation: a hostname in the hosting panel has been pointed at the folder
# ABOVE this one by mistake. In that situation any answer other than the file's contents is the right
# answer — including a 500 caused by a directive this host does not permit in .htaccess.
Require all denied
Options -Indexes
```

**These files are never read in normal operation**, because no request served from `admin_html/` or
`public_html/` passes through `_core/`. That is the property that makes them safe to ship without proving
`AllowOverride`: a fault in them can only fire in the panel-mistake case, where a 500 is as good as a 403.

**The door files lose Correction 15's `Require all granted`.** With no root deny there is nothing to override,
and a `Require` line in a file that IS read on every request is the 500 hazard above.

**What still protects the panel-mistake case beyond these files:** the bootstrap document-root refusal (plan
Step 2 item 3) for requests that reach PHP, and the probe in 3.10 for files Apache would serve directly.

Optional but cheap: a tiny audit check that the copies are identical, so one cannot drift.

### 3.8 The `.well-known/` exclusion

On both door mirrors only (3.6). Proof procedure in 3.6.

### 3.9 The dry run

The existing `dry_run` input. `$DRY` is threaded into every `mirror`. The marker and `CHANGELOG.md` are written
in the checkout only; every `put` is skipped (`put` has no dry run); the probes in 3.5 run (they are
read-only, so the dry run also proves the marker logic against the real server); the probes in 3.10 run.

**`mkdir -p -f` still runs in a dry run**, as today (`deploy.yml:372`, PROVEN). The challenge asked to skip
it. It runs here because whether `mirror --reverse --dry-run` can report against a target folder that does not
yet exist is INFERRED either way, and the first live dry run (section 6) is exactly a run whose door target
does not exist yet and whose shared-folder output is the part that matters. The cost: a valid-but-misspelt
`SFTP_PATH_ALPHA_DIR` in a dry run leaves an empty channel root with three empty subfolders on the server,
which the SFTP client shows at once and which is deleted by hand. The log prints "a dry run still creates
empty folders; nothing is uploaded or deleted".

### 3.10 Health checks — every channel, both doors, after every deploy

Addresses from the six variables in 3.1; an empty one → `::warning::` and skip that door. Expected channel
word: `live` → `prod`, `beta` → `beta`, `alpha` → `alpha`.

1. **Staff door, `/health`.** `curl -fsS --max-time 15 "$ADMIN/health"`, up to three tries five seconds apart
   (a stale opcode cache right after upload can make the first answer old — INFERRED). Then:
   - the body is JSON with a `status` key → `env` must equal the expected word, `channelSource` must be `file`
     (or `environment`), `version` must equal `web/_core/version.php` in the checkout. Any mismatch →
     `::error::`, the run FAILS: a wrong `env` means the hostname is pointed at a folder whose marker says
     something else; `fallback` means the marker was not read. (Needs section 7's Step 2 item 8; until then
     the probe meets the 503 holding page after every version-bumped deploy, F7.)
   - the body is not JSON and the status is 200 → `::warning:: this channel is not installed yet — the
     installer answered; run it now, then re-run the probe`. Not a failure: on a fresh channel `index.php:15-26`
     hands EVERY address, `/health` included, to the installer (PROVEN). Print the first 200 characters.
   - connection refused or no answer → `::error::`, fail — the variable is set, so the hostname is expected
     to exist.
2. **Both doors, must not answer 200:** `_sql/full_schema.sql`, `_core/bootstrap.php`, `_auth_keys/enc.key`,
   `_uploads/`, `.channel` (Correction 16; Apache serves these without PHP, so only the deny files and this
   probe cover them).
3. **Pre-release staff door only:** `curl -sI "$ADMIN/dashboard"` → a 302 whose `Location` contains `/login`
   (`Auth::requireLogin()` sends `Location: /login?redirect=…`, `Auth.php:108-115`, PROVEN) → pass. A 503 →
   `::warning:: gate not proven — maintenance is active on this channel; run Admin → Upgrade and re-run the
   probe` (`dashboard` is not on the maintenance allow list and the maintenance gate runs first,
   `index.php:57-62`, PROVEN). Anything else, a 200 above all → `::error::`, fail.
4. **Public door (from Step 4):** `/` → 404; on a pre-release channel `X-Robots-Tag` contains `noindex`.

### 3.11 Summary

Print channel, channel root (masked), both door targets (masked), sign-in method, commit — replacing the
never-set `public_dir` at `deploy.yml:435`. The header comment (`:7-57`) is rewritten to this section's
model; the sentence "whichever branch pushed last wins for shared code" is deleted, because it is no longer
true.

### 3.12 Two things kept exactly as today, noted so nobody re-decides them

- Change detection (`deploy.yml:212-240`) looks only at `web/`, so a push that changes ONLY the workflow file
  computes `has_changes=false` and uploads nothing; a manual dispatch always deploys. That is how the first run
  under the new workflow is started (section 6).
- `[deploy all]` and `[skip ci]` keep their meanings.

---

## 4. The hard ordering

The hazard, exactly: `bootstrap.php:92-109` (F3). Any request that reaches a `…/admin_html` folder whose
`_core/bootstrap.php` still guesses from the folder name shows PHP errors on that staff side; any that reaches
`…/public_html` under the same code treats it as live.

1. **One commit** carries all of: bootstrap reading `<root>/.channel` through `Door::validate()` with the
   folder sniff deleted; `index.php` and `error.php` defining `PORTAL_DOOR` and `PORTAL_WEBROOT`;
   `web/public_html/.door` = `admin`; `health` on the maintenance allow list; `channelSource` in `/health`;
   `/web/.channel` in `.gitignore`; the deny files; the rewritten `deploy.yml`.
2. The rewritten workflow **refuses any tree** whose `bootstrap.php` does not read the file (tree guard 2) and
   any tree with no `.door` (guard 1). So the new folder layout can only be created by a deploy that also ships
   the file-reading bootstrap and writes the marker. The two cannot arrive separately.
3. **All remote checks run before any upload** (3.5), so a folder is never half-changed by a run that then
   refuses.
4. A hostname is pointed at a folder **only after** the deploy that created it has finished (section 6); the
   probe's `env` + `channelSource` check is the proof, per channel, that the marker was read.
5. Nobody renames a folder on the server by hand. The deploy creates `admin_html/`; the old `public_html/` is
   deleted, never renamed.
6. The one unavoidable window is on live when the old base holds a real installation (section 6, fork B):
   old front controller + new core → a one-line refusal on every request until the hostname is re-pointed
   minutes later. It is avoided entirely by fork A.

What would have happened without this: renaming `public_html` → `admin_html` on the server before the commit
in 1 → `dev` guessed on live's staff side, PHP errors on screen (`bootstrap.php:105`, `:153-155`); creating a
public folder before it → alpha's public side counted as live.

---

## 5. Each channel is a separate installation

### 5.1 What a fresh channel goes through

1. The deploy creates the channel root, `admin_html/`, and empty `_auth_keys/`, `_uploads/`, `_backups/`
   (each with a deny file), and writes `.channel`.
2. A hostname is pointed at `<channel root>/admin_html` in the DreamHost panel.
3. The first request finds no `auth_creds.php` and hands over to the installer (`index.php:15-26`) —
   bootstrap-free, before the maintenance gate and the pre-release gate, so a pre-release channel can always be
   installed and the marker is never consulted.
4. The installer asks for a database host, name, user and password (step 2), writes THAT channel's
   `auth_creds.php` and a fresh 32-byte `enc.key` (`_install/index.php:958-983`, PROVEN), records
   `portal.installed_version` in THAT database (`:985-1005`) and drops the `.installed` lock (`:1022`).
5. **Between steps 2 and 4 the installer is reachable by anyone who knows the hostname** — minutes, not
   hours, but do the three in one sitting.
6. The code creates its own upload sub-folders at run time (21 `mkdir(` calls in `_core` and `_apps`, PROVEN
   by count), so the deploy's empty `_uploads/` is enough.

### 5.2 Databases

One MySQL database and one MySQL user per channel, created in the DreamHost panel (MySQL Databases) — for
example `webms_live`, `webms_beta`, `webms_alpha`, each with its own user and password, on the account's MySQL
hostname. The alpha and beta databases start EMPTY: a fresh install with a fresh administrator account (the
owner's words: copying live's data is a separate, planned job — 5.6). A MySQL user reaches only the databases it
has been granted, which is what keeps three channels' data apart under one hosting login — INFERRED on
DreamHost's grants.

### 5.3 Re-running an installer

`_install/index.php:57` refuses while `_auth_keys/.installed` or `auth_creds.php` exists — in THAT channel's
folder (PROVEN). Wiping alpha to start again means deleting alpha's two files (and dropping its database, or
letting the installer's "existing database" step do it — it asks the hostname to be typed as confirmation,
`:555-570`, PROVEN); it cannot touch beta or live.

### 5.4 Upgrading

Each deploy of newer code puts that one channel into the maintenance window until its own umbrella
administrator runs Admin → Upgrade (`Maintenance.php:133-148`; `_apps/admin/upgrade.php` → `_install/upgrade.php:29`,
umbrella administrator only at `:53-56`, PROVEN). Three channels, three separate upgrades, three separate
`tblMigrations` tables. Alpha's upgrade cannot change live's database. Migration 194 (the public door) runs on
alpha when alpha's administrator presses the button, and again, separately, on beta and on live.

### 5.5 Scheduled jobs

Every job is an address plus a token that lives in its channel's database (F12), so a job is per channel:
`https://<channel staff hostname>/cron/<job>?key=<that channel's token>`. One DreamHost user has one crontab, so
live's entries and any pre-release entries sit side by side. On alpha and beta the pre-release gate refuses
`/cron/` (`Gatekeeper::OPEN_PREFIXES` holds only `help`, `Gatekeeper.php:133-135`, PROVEN; the consequence is
written at `index.php:133-140`), so scheduled jobs on pre-release channels do nothing unless `cron` is added to
the open list. With separate, empty databases that is the right default (OWNER DECISION 5). The offsite-backup
tool is copied into `web/_backups/` (`admin/maintenance/offsite-backup.php:210-216`) — now per channel; only
live's is worth setting up.

### 5.6 `_auth_keys/` is never copied between channels — and what moving data would take

- Copy `auth_creds.php` and both folders open the SAME database: two "channels" sharing one set of members,
  settings and upload records — the arrangement the owner rejected, now hidden behind two hostnames.
- Copy `enc.key` and every value marked sensitive in the other channel's database becomes unreadable:
  `decrypt_setting()` returns `''` on a wrong key (`bootstrap.php:262-265`, PROVEN) — payment and e-mail keys,
  every two-factor secret, the push signing key (DEV_NOTES 3c:676-680). Nothing reports it; settings simply
  read as empty.
- **If data or uploads ever have to move** (say, a copy of live into beta): it is a clone of an installation,
  not a file copy. Dump live's database and restore it into beta's (the admin restore, #472); copy `_uploads/`
  into beta's folder; copy live's `enc.key` into beta's `_auth_keys/` — the ONE legitimate case, because the
  restored rows were encrypted with it — but never `auth_creds.php`, which must stay beta's own. Then, in beta,
  change every row that names the outside world: `tblSites.hostPattern` and `tblPublicHosts` (live hostnames),
  every `*.cron_token`, live payment-provider keys and webhook secrets (a beta with live Stripe keys can take
  real money), e-mail sending settings, VAPID keys if the push subscriptions are not to receive beta's
  notifications. That list is why "fresh install" is the default for beta (OWNER DECISION 7).

### 5.7 One hosting user — what it does and does not protect

It DOES give each channel its own database (5.2), its own encryption key, uploads, backups, settings, users
and sessions (F5). It does NOT give filesystem separation: the same Unix user runs PHP for all three hostnames
(INFERRED for DreamHost; hosting facts §2 shows one user holding many domains), so code on alpha could read
`/home/USER/portal…/_auth_keys/auth_creds.php` by absolute path and connect to live's database with it. Nothing
does so by accident — every path in the code is relative to its own `PORTAL_ROOT` (F1) — but a malicious or
badly broken alpha deploy is not stopped by anything. One SFTP login reaches all three folders. The stronger
arrangement is a separate DreamHost user per channel, which needs per-channel `SFTP_USER`/`SFTP_PASSWORD`
settings and breaks option C's "one home folder" assumption. OWNER DECISION 4, default "not now".

### 5.8 Things a pre-release channel needs that the documents did not say

- **Sign-in providers.** `login/ms365/callback` and `login/google/callback` exist (`Gatekeeper.php:115-117`,
  PROVEN); an OAuth application only redirects to registered addresses (INFERRED), so alpha and beta each need
  their callback registered — or use password sign-in, which the installer's first administrator gets anyway.
- **Payment and Zoom webhooks, newsletter trackers** are per channel and are refused on pre-release channels
  by the gate (`index.php:133-140`).
- **Certificates.** Live already sends `Strict-Transport-Security: max-age=31536000; includeSubDomains`
  (`bootstrap.php:475-483`, PROVEN), so a browser that has visited `portal.x` refuses `alpha.portal.x` without a
  valid certificate (INFERRED). Let's Encrypt in the panel needs the hostname's DNS to resolve to DreamHost
  first (INFERRED); nothing in the repository says where the domain's DNS is hosted (`bootstrap.php:466`
  mentions "DreamHost's edge-redirect"; Cloudflare appears only as Turnstile, Stream and the trusted-proxy
  plumbing — PROVEN by grep). Section 6 makes the wait checkable.
- **PHP version** is chosen per website in the panel (INFERRED); set each pre-release website to match live.

---

## 6. The changeover — for somebody with the DreamHost panel and an SFTP client (replaces DEV_NOTES 3c)

Alpha and beta are new folders and cannot collide with anything. Live's channel folder is the old base (F2),
so live is the one place old and new overlap. The deploy kill switch (`vars.SFTP_ENABLED`, `deploy.yml:94`)
is used so that no real deploy can run before its dry run — a merge to `alpha` is a push (`:61-69`) AND the
auto-merge bridge dispatches a second run after it, so `[skip ci]` alone would not stop both (challenge
finding 3).

**Step 0 — find out what is there (nothing else first).**
- Panel → Websites → Manage Websites: write down EVERY hostname and its web directory. Note which point inside
  `/home/USER/portal.millrdsdacambridge.uk/` (the old `public_html/`, `public_html_beta/`, `public_html_dev/`,
  `public_html_landing/`, `public_html_redir/` are the likely ones — DEV_NOTES 3b:611 says three web roots are
  served from it). For each, decide now: re-point it later, or delete it later. This list feeds OWNER
  DECISION 2 (if `dev.portal…` or `beta.portal…` already exist, they are re-pointed rather than added).
- SFTP client → `/home/USER/portal.millrdsdacambridge.uk/_auth_keys/`. **Fork:** if it holds `enc.key` or
  `auth_creds.php`, this is a real installation → copy the whole folder to
  `/home/USER/auth_keys_backup_<today>/` — OUTSIDE every channel folder — and follow **fork B** at step 9. If
  the folder is missing or empty, there is no installation → **fork A** at step 9.
- Where is DNS for `millrdsdacambridge.uk` hosted? If not at DreamHost, every new hostname below needs a record
  added at the DNS host before its certificate can be issued.

**Step 1 — switch the deploy off.** GitHub → Settings → Secrets and variables → Actions → Variables →
`SFTP_ENABLED` = `false`.

**Step 2 — merge the Step 2 work to `alpha`.** The push-triggered run and the bridge's dispatched run are both
skipped at the job level. (The old workflow could not have run anyway, F4.)

**Step 3 — settings.** Secrets: change `SFTP_PATH_ROOT_DIR` to `/home/USER/`; add `SFTP_PATH_LIVE_DIR =
portal.millrdsdacambridge.uk/`, `SFTP_PATH_BETA_DIR = <beta folder>/`, `SFTP_PATH_ALPHA_DIR = <alpha folder>/`
(names from OWNER DECISION 2); delete the six `SFTP_PATH_<CHANNEL>_<DOOR>_DIR` secrets. Leave `SFTP_HOST`,
`SFTP_USER`, `SFTP_PASSWORD` alone (DEV_NOTES 3b:570-575 — change, never delete). Variables: nothing yet.

**Step 4 — switch the deploy on.** `SFTP_ENABLED` = `true`.

**Alpha (a new folder)**

**Step 5.** Actions → Deploy via SFTP → Run workflow → branch `alpha` → tick `dry_run`. Read the log: the
probes report the channel root absent; "would create"; nothing to delete; the `.well-known` proof if you
planted one (3.6). Run it again without `dry_run`. The folder now holds the marker, the shared code, `admin_html/`
and three empty server-managed folders. The health probe warns that its variable is empty — expected.

**Step 6.** Panel → add (or re-point) the alpha staff website **under the same user as the portal** (hosting
facts §2), web directory `/home/USER/<alpha folder>/admin_html`, Let's Encrypt ticked, PHP version as live. If
DNS is elsewhere, add the record there first. Wait until `curl -sI https://<alpha>/health` — without `-k` —
answers at all (it will be the installer page, status 200, HTML). Then GitHub Variables → `ADMIN_BASE_URL_ALPHA`
= `https://<alpha>`.

**Step 7.** Panel → MySQL Databases → create the alpha database and its user.

**Step 8 — in one sitting (5.1 point 5).** Open the alpha address; the installer appears; complete it with the
alpha database; sign in. `curl -s https://<alpha>/health` must show `"env":"alpha"`, `"channelSource":"file"`
and the version in `web/_core/version.php`. Run Admin → Upgrade if offered. Dispatch the workflow once more
(no dry run) and confirm the health probe passes.

**Beta** — repeat steps 5-8 for the `beta` branch (promote `alpha` → `beta` first; that push deploys beta for
real into a NEW, empty folder, which is safe — or use the kill switch again if a dry run is wanted first),
`"env":"beta"`.

**Live**

**Step 9, fork A — the old base holds no installation (recommended when true; OWNER DECISION 10).** In the SFTP
client delete everything inside `/home/USER/portal.millrdsdacambridge.uk/` EXCEPT `public_html_landing/`,
`public_html_redir/`, `private_html/`, `_libraries/` and anything from your step 0 list that another hostname
still serves. Live is now a fresh channel: kill switch off; promote `beta` → `main`; kill switch on; dispatch
from `main` with `dry_run`; read; dispatch for real; re-point `portal.millrdsdacambridge.uk` at
`/home/USER/portal.millrdsdacambridge.uk/admin_html`; create the live database; install; `"env":"prod"`. No
window, nothing to delete afterwards. Skip to step 11.

**Step 9, fork B — the old base holds a real installation.** Kill switch off; promote `beta` → `main`; kill
switch on; dispatch from `main` with `dry_run`. Read with care: it must show uploads INTO `_core/`, `_apps/`, …
and INTO a new `admin_html/`, and deletions only inside those folders. It must never list `public_html/`,
`_auth_keys/`, `_uploads/`, `_backups/` or the other old folders. Then dispatch for real. **Known window:** from
this moment until step 10, the OLD `public_html/index.php` (what the hostname still serves) runs with the NEW
`_core/bootstrap.php`, which refuses a front controller that defines no door — a plain one-line 500 on every
request. Nothing is live, so this is acceptable; do step 10 straight away.

**Step 10 (fork B).** Panel → `portal.millrdsdacambridge.uk` → web directory →
`/home/USER/portal.millrdsdacambridge.uk/admin_html`. Sign in (live's `_auth_keys/` is untouched, so its
existing database). `/health` must say `"env":"prod"`, `"channelSource":"file"`. Set `ADMIN_BASE_URL_LIVE`.
Then, only now, delete `public_html/`, `public_html_beta/` and `public_html_dev/` inside live's folder — after
checking your step 0 list that no website still points at any of them. They are old management-portal copies
the deploy never touches, so nothing else will remove them. Leave `_auth_keys/`, `_uploads/`, `_backups/`,
`_libraries/`, `private_html/`, `public_html_landing/`, `public_html_redir/`.

**Step 11 — the neighbours.** Fetch every address from your step 0 list that is served from inside live's
folder and confirm each still answers as before. Under this design nothing at the channel root is written
except `.channel` and `CHANGELOG.md`, so nothing should have changed; check anyway.

**When Step 4 of the build (the public door) lands**

**Step 12.** Deploy each channel again. The workflow creates a fresh `public_html/` carrying `.door` =
`public`. On live (fork B) it REFUSES while the old `public_html/` still exists — it has `index.php` and no
marker — which is why step 10's deletion is not optional.

**Step 13.** Add the public hostname for each channel (OWNER DECISION 3), web directory
`/home/USER/<channel folder>/public_html`, same user, Let's Encrypt on; set `PUBLIC_BASE_URL_<CHAN>`. Expect
"not found" for everything.

---

## 7. Build steps 2 to 9 — what changes

### Step 2 — Door identity (CHANGED; it carries the whole deploy rewrite)

1. `web/public_html/index.php` AND `web/public_html/error.php` (inside its `try`, before `:35`) gain
   `define('PORTAL_DOOR', 'admin'); define('PORTAL_WEBROOT', __DIR__);` — two constants, no file read.
2. Bootstrap: `Door::validate()` as in section 2.2; the folder sniff (`bootstrap.php:92-109`) deleted; the
   `PORTAL_ENV_SOURCE` comment (`:111-135`) rewritten; the error-display rule per the owner's choice (2.4);
   `Gatekeeper::shouldEnforce()` per 2.5. **Required, not optional** — nothing else can tell the channels apart.
3. Document-root refusal — unchanged (plan Step 2 item 3). It now fires for a hostname pointed at the channel
   root itself; Apache-served files are covered by 3.7 and 3.10.
4. `Headers::emitAdmin()` extraction — unchanged.
5. Router uses `PORTAL_WEBROOT` at `Router.php:95-96` and `:257-258` (Correction 14) — unchanged; `__DIR__` is
   right in both the old and the new layout.
6. Deny files per 3.7 — replaces the plan's item 6 and Correction 15.
7. `deploy.yml` rewritten per section 3 — in the SAME commit as items 1-2 (section 4).
8. `health` added to `Maintenance::ALLOW_LIST` (F7). The list is prefix-matched (`Maintenance.php:157-166`,
   `str_starts_with`), so `health` would also admit an address beginning `health…`; there is none.
   OWNER DECISION 8.
9. `channelSource` added to `Router::healthCheck()` (2.7).
10. `/web/.channel` added to `.gitignore`; `web/public_html/.door` = `admin` committed.

**Verification — changed.** Alpha is a fresh installation, so: section 6 steps 5-8, then `curl -sI` the header
set and diff it against the pre-Step-2 capture (unchanged); `curl -sI https://<alpha>/.door` → 403
(`.htaccess:29-30`); `curl -s https://<alpha>/health` → `"env":"alpha"`, `"channelSource":"file"`. Under
design A, temporarily rename the marker on the server by SFTP for one request: `/health` must say
`"channelSource":"fallback"`, the dashboard must redirect to sign-in, the response must carry `noindex`; rename
it back. The "run `php web/_core/bootstrap.php` from the shell" proof stays deleted (Correction 4).

### Step 3 — The rename (wording only)

Items 1, 3, 4, 6 unchanged; item 2 deleted (Correction 14); item 5 = "no workflow change; the door mirrors are
driven by `.door`; verify an ordinary alpha deploy passes". The plan's `^admin_html/` exclude lines
(`1245-1258`) are moot — nothing mirrors `web/`. Verification: replace "set the nine secrets…" with "the four
`SFTP_PATH_*` settings were set at changeover (section 6); run an ordinary alpha deploy". The
`grep -rn "public_html"` intentional-reference list gains `Router.php:9` and the workflow's own comments.

### Step 4 — The empty public door (content unchanged; notes added)

The public door's `.htaccess` carries the dot-file block from `web/public_html/.htaccess:29-30`. Its front
controller and error page define `PORTAL_DOOR = 'public'` and `PORTAL_WEBROOT = __DIR__`, nothing else.
"Against the alpha public host" means a hostname added in the panel only AFTER this step's deploy has created
`public_html/` with `.door` = `public` (section 6 steps 12-13). `noindex` on a pre-release public door depends
on the marker; add to the verification: with the marker renamed for one request, the header is STILL present.

### Step 5 — Migration 194 and `/admin/public` (content unchanged; one note)

The migration runs on each channel's own database when its administrator presses Admin → Upgrade (5.4). The
harness replay requirement is unchanged. Migration 194's header carries the note in section 10 about 193's
"or else from the name of the web folder" sentence.

### Step 6 — Noticeboard (unchanged; Correction 10 stands, section 8)

### Step 7 — Calendar (unchanged)

### Step 8 — Embeds, robots, sitemap (content unchanged; one rule made explicit)

"Only on the live channel may a public page be indexable" means `PORTAL_ENV === 'prod'` AND
`PORTAL_ENV_SOURCE !== 'fallback'`.

### Step 9 — Documents, second opinion, commit (CHANGED)

Documents per section 10. The Codex prompt keeps its four questions and adds two: "can any upload in
`deploy.yml` ever delete or overwrite `_auth_keys/`, `_uploads/`, `_backups/`, `.well-known/` or any folder at
a channel root that is not in the repository", and "is there any request path on which bootstrap decides the
channel from a folder name, or shows PHP errors after a fallback".

---

## 8. The 23 corrections, one line each

1. **Changed.** Host row on every request — stands (`Site::$enabled` is false until `Site::init()`,
   `Site.php:79`, `:108-109`, PROVEN; the public door skips `init()`). Channel paragraph deleted. Section 9.
2. **Changed.** Section 3.1 (pattern `^[A-Za-z0-9_-][A-Za-z0-9._-]*$`; a name like `a..b` passes and is
   harmless — no slash).
3. **Replaced.** Section 6.
4. **Changed.** Section 2. `error.php:30-44` and `log-offsite-result.php:18-23` still require bootstrap with no
   defines (PROVEN); the CLI door and the refusal stand; the constants shrink to two; the mapping changes.
5. **Replaced.** Section 3 (5b and 5c kept in substance; 5d moot; 5e's wording becomes "the four `SFTP_PATH_*`
   settings"; the migration renumbering in 5e unchanged).
6. **Unchanged.** Boundary proof asserts on the resolved file.
7. **Unchanged.** Sign-in methods refuse on the public door.
8. **Unchanged.** `PublicMaintenance::check()` now reads the channel's own database, right by construction.
9. **Unchanged.** Inheritance is about tenants inside one installation.
10. **Stands; "Why" rewritten.** The old reason ("PHP cannot know which channel's folder to write into") is
    gone: the target would now always be `PORTAL_ROOT/public_html/media/`, one per installation. Static
    copying has become POSSIBLE. What still favours serving through PHP: the database stays the ONLY record of
    what is published — no reconciliation sweep, no `public.cron_token`, no `media/` folder for the door
    mirror's `--delete` to remove, no "file exists but the row says private" state, and the GDPR step stays a
    row update. Byte ranges are not an argument either way: Apache serves ranges for a real file (INFERRED),
    and `Recordings::streamFile()` does it for the PHP route (`Recordings.php:49-92`: parses `HTTP_RANGE`,
    answers 206/416, sends `Accept-Ranges` and `Content-Range` — PROVEN). What favours static copies: no PHP
    process per media fetch — small, because the one-year immutable cache means one fetch per file per
    display. Recommendation: keep 10 (OWNER DECISION 6). **One defect in Correction 10's own text, found on
    re-reading:** `Recordings::streamFile()` sends its own `Cache-Control: public, max-age=3600`
    (`Recordings.php:88`, PROVEN), and PHP's `header()` replaces an earlier header of the same name, so the
    one-year `immutable` header the correction tells `media.php` to send BEFORE calling `streamFile()` would be
    overwritten. Send it after the call returns headers, or give `streamFile()` a cache parameter. Not a
    channel matter; recorded so it is not lost. If the owner prefers static copies regardless, the short honest
    list: `PublicMedia` writes to `PORTAL_ROOT/public_html/media/{siteKey}/` and creates the folder, a sweep
    that creates as well as deletes, `--exclude ^media/` on the public door mirror, `tblNoticeboardUploads.
    isPublic` and the GDPR disk purge restored.
11. **Unchanged.** Calendar column names.
12. **Unchanged.** `robots.txt`/`sitemap.xml` dispatched by the door.
13. **Unchanged.** `widget/countdown.js` stays.
14. **Unchanged.** `PORTAL_WEBROOT` in Router; simpler now — every staff folder is `admin_html`, and
    `__DIR__` works on purpose rather than by accident.
15. **Replaced.** Section 3.7.
16. **Changed.** Section 3.10; the DEV_NOTES sentence fix ("refuses requests that reach a front controller from
    a too-high web directory; Apache-served files are covered only by the deny files and the probe") stands.
17. **Unchanged.** Admin door `X-Robots-Tag` unconditional at Step 8. (Between Steps 2 and 8 one settings row
    could make alpha's staff door indexable — `bootstrap.php:530-543` — but the gate redirects crawlers to the
    sign-in page, so the exposure is the sign-in page. One sentence, no change.)
18. **Changed.** Five hostnames, all under the same user; web directories
    `/home/USER/<channel folder>/admin_html` and `…/public_html`; every `public_html_dev` / `admin_html_dev`
    form deleted.
19. **Unchanged.** `publish.php` and the help page in Step 5.
20. **Unchanged.** Feeds send `Access-Control-Allow-Origin: *`.
21. **Conditional.** The working tree's `Logger.php` is being edited (git status `M`; HANDOFF says pkg4 moves
    `clientIp()` onto `RateLimiter::clientIp()`). If pkg4 has landed when Step 2 starts, delete the note; if
    not, it stands as written.
22. **Superseded.** Section 12.
23. **Unchanged.** `public.assetBase` dropped.

---

## 9. The public side under separate databases

1. **`tblPublicHosts.siteID` keeps allowing NULL.** The NULL row was never about channels: it is Correction 1's
   step 4, the installation's shared public hostname that answers only token-addressed requests so a
   multi-tenant installation can serve several organisations' embeds from one hostname. Unchanged by separate
   channels. On this single-site installation no NULL row will exist, and nothing forces one.
2. **Each channel's database holds only that channel's public hostnames.** Adding alpha's public hostname in
   alpha's database has no effect on beta or live, whose databases do not contain it. The "add the alpha
   hostname first and only that" discipline is gone.
3. **"Publish separately per channel" for a delegated administrator means:** a grant is a row in
   `tblPublicSurfaceGrants` in ONE channel's database, naming a user row in THAT database. A person delegated
   on alpha does not exist on live unless separately created there; switching a surface on, granting, marking
   posters public and adding a hostname are all repeated on live by live's global administrator. A rehearsal on
   alpha proves the code and the procedure; it configures nothing on live. The help page and `/admin/public`
   say so in one sentence each.
4. **Correction 10** — section 8.

---

## 10. Documents and comments that must change

- **`DEV_NOTES.md`:** 217-260 (the repository-versus-server diagram — one channel folder holds everything);
  263-298 (deployment model and remote layout — three channel folders, no shared base, "last push wins"
  deleted); 336-340 ("Manual Deploy Override" — deleted); 378-404 (the secrets table — the four names); **3b
  rewritten from section 3** (405-634: four settings, the new slash table, a "where everything ends up" diagram
  with three roots, the panel table with five hostnames, the retired-name list gaining the six door settings);
  **3c replaced by section 6** (636-738); 1294-1326 (Dev Site Access — `public_html_dev/` is gone; the alpha
  channel reads `portal.alphaAccessRoles`, local work reads `portal.devAccessRoles`); 1328-1350 (Environment
  Detection — the marker at the channel root, the table of words, the fallback per the owner's choice);
  2588-2619 (the "file disappeared after deploy" troubleshooting — now per channel folder, and
  `WEB_ROOT_EXCLUDES` no longer exists to add things to).
- **`README.md`:** 114 and 225-226 (the old folder names and "shared remote base").
- **`.claude/CLAUDE.md`:** Directory Layout (`public_html/ … mirrors this dir to the server's public_html/
  (main), public_html_beta/ …`); Key Constants (`PORTAL_ENV -- auto-detected from DOCUMENT_ROOT` → from
  `<channel root>/.channel`; add `PORTAL_ENV_SOURCE`, `PORTAL_DOOR`, `PORTAL_WEBROOT`); Git Notes ("Deploy
  workflow syncs web/ only", "Shared dirs mirror with --delete" → per folder, per channel); the web-root
  shadowing section's `web/public_html/` (becomes `admin_html` after Step 3); the header's "Server:" line
  (three staff hostnames); and a new standing note: **the three channels are three installations — a setting,
  a user, a grant, an upload or a scheduled-job token on one never exists on another.**
- **`.github/workflows/deploy.yml`** header (`7-57`): the channel model, the four settings, no "last push wins".
- **Code comments describing the old world:** `bootstrap.php:11-16` and `84-135`; `Gatekeeper.php:5-9`,
  `17-25`, `232-254`; `Router.php:7-10`; `index.php:103-105`, `114-117`; migration 193's header (`:114-120`,
  "or else from the name of the web folder") — a migration header cannot be edited on a customer's server, so
  migration 194's header carries the correction instead.
- **In-app admin help** `web/_apps/help/admin.php:377-420`: "production, beta, alpha, and dev" → the three
  channels plus local work; add `portal.devAccessRoles` (HANDOFF already lists its absence as a follow-up); add
  the "three installations" sentence and what a pre-release channel does not do (no scheduled jobs, no real
  data).
- **`/admin/public` page and `/help/public-site`**: the one sentence from section 9 point 3.
- **`CHANGELOG.md`**, **`FEATURES.md`**, and a memory file `webms-channels-fully-separate.md`.
- **Three copies of dompdf** — one sentence in DEV_NOTES "dompdf at deploy time".

---

## 11. What was not checked

No server, DreamHost panel, Apache, lftp or GitHub Actions run was touched. `lftp` is not installed on this
machine (F9), so nothing about its exit codes, `cmd:fail-exit`, `--exclude` under `--delete`, `mirror`
against a missing target, or `get`/`cls` on a missing file was tested — each is marked at the point it
matters, and the first alpha dry run is where each is confirmed. Apache's reading of parent `.htaccess` files,
DreamHost's `AllowOverride`, placeholder files in a new web directory, per-website PHP versions, MySQL grant
isolation, where the domain's DNS is hosted, Let's Encrypt's requirements, browser HSTS behaviour, opcode-cache
timing, OAuth redirect rules, and GitHub's handling of `github.ref_name` on a dispatch are all INFERRED and
marked so. I did not run the audit scripts or self-tests. `App.php`, `bootstrap.php`, `Gatekeeper.php` and
`Logger.php` were modified and uncommitted in the working tree; the line numbers here are from that tree at the
moment of reading.

---

## 12. Refreshed line references (supersedes Correction 22 and the re-walk's section F)

`bootstrap.php`: early return 36-38; `PORTAL_ROOT` 55; sub-constants 57-60; channel block 92-109;
`PORTAL_ENV_SOURCE` comment 111-135; error display 147-156; `encrypt_setting()` key path 210;
`decrypt_setting()` key path 251, wrong-key return 262-265; credentials 302-308; `mysqli_report` 316;
`preDetect` 356-361; settings load 374-410; header block 412-544 (HSTS 475-483, `X-Robots-Tag` 530-543);
timezone 548-553; `App::init` 562; `Site::init` 572; error/exception handlers 591-611; `I18n::init` 626.
`Gatekeeper.php`: `VALID_CHANNELS` 40; `OPEN_PATHS` 112-128 (`health` 122); `OPEN_PREFIXES` 133-135;
`enforce()` 140-224 (redirect 157-160, admin flags 189-202, roles key 212); `shouldEnforce()` 275-313
(source test 302-310). `Maintenance.php`: `ALLOW_LIST` 56-118; `isActive()` 124-151; `isAllowed()` 157-166.
`Router.php`: web-root fallback 93-98; `offline` 256-259; `health` 262-265; `healthCheck()` 545-579 (JSON
570-578). `index.php`: installer hand-off 15-26; maintenance gate 57-62; consequence note 133-140; gate 141-144.
`error.php`: bootstrap require 30-44. `.htaccess`: dot-file block 29-30; `.php` block 48-52; `ErrorDocument`
84-87; `Header unset` 94-96. `_install/index.php`: constants 36-45; re-install refusal 57; drop confirmation
555-570; credentials 958-974; key 976-983; version 985-1005; lock 1022. `_install/upgrade.php`: bootstrap 29;
umbrella check 53-56. `Site.php`: `$enabled` 79; `init()` 104-124. `Auth.php`: cookie params 79-85 (domain 82);
`requireLogin()` 108-115. `App.php`: prod test 266; `version()` 348-356; `env()` 365-366. `Debug.php`: 162.
`Recordings.php`: `streamFile()` 49-92 (Cache-Control 88). `deploy.yml`: header 7-57; trigger 61-69; inputs
70-86; kill switch 94; dry-run flag 103; `LFTP_EXCLUDES` 104-122; `WEB_ROOT_EXCLUDES` 128-137; checkout
141-145; target step 151-198 (old secrets 154-156, override 158-164, map 166-183, empty check 185-188,
`dirname` 192); change detection 212-240; dompdf 245-247; changelog 251-253; auth 275-289; key form 316-322;
password form 336-341; shared mirror 374-383 / 397-405 (mkdir 377-379); health 418-422; summary 428-438
(`public_dir` 435). `version-bump.yml`: alpha rule 15 and 95-98. `full_schema.sql`: `AccessRoles` 1468, 1472,
1476. `help/admin.php`: 377-420 (`portal.alphaAccessRoles` 406). iHymns: `deploy.yml` 55-81, 515-522,
1014-1020, 1073-1078; `environment.php` 27-40; `db_mysql.php` 56.

---

## Design choices made here, not put to the owner

Listed so they are visible, with the reason each is a design matter rather than an owner call:

- The marker lives at the channel root, one per installation (2.1) — a truthful model of what a channel is.
- The alpha channel's `PORTAL_ENV` is `alpha` (2.3) — nothing in the code distinguishes `dev` from `alpha`
  except a badge colour (F8), and the help page and seeds are already right for `alpha`.
- No `.htaccess` at the channel root; deny files inside each repository-owned folder (3.7).
- The manual deploy override is removed (3.2) — it contradicts the owner's own decision that channels are
  separated "purely by … source branch".
- Shared code is uploaded one folder at a time, never `web/` as a whole (3.6).
- `mkdir` still runs in a dry run (3.9).

---

# OWNER DECISIONS

Genuinely new decisions only. Recommendation first, then the alternative, then what each costs. Everything
answered on 13 September 2026 — option C, the retirement of the six door settings, the six earlier decisions —
stands and is not re-opened.

**1. What the portal does when the channel marker is missing, unreadable or unknown** (section 2.4).
- **Recommended: fail closed** — treat it as a pre-release copy: sign-in gate ON, PHP errors hidden, `noindex`,
  one log line per request, `/health` reports `"channelSource":"fallback"` so the deploy probe fails. Cost: if
  the one-line file is ever deleted by hand on LIVE, members meet the sign-in page until an administrator
  re-runs the `main` deploy (about a minute); administrators are never locked out.
- Alternative: today's default — treat it as live: gate OFF, errors hidden, `noindex`, the log line, plus a red
  dashboard banner and the `/health` field. Cost: a pre-release copy that loses its marker is open to the world,
  with unfinished code, until somebody notices.

**2. Folder and hostname names for beta and alpha** (section 6 step 0 tells you what already exists).
- **Recommended: folder = hostname**, DreamHost's own convention and the iHymns precedent —
  `beta.portal.millrdsdacambridge.uk` and `alpha.portal.millrdsdacambridge.uk`. If `dev.portal…` already exists
  in the panel, either re-point it (then the alpha folder is `dev.portal…`) or add `alpha.portal…` and delete
  the old hostname. Cost of choosing other names: none in code; the two secrets and the panel entries differ.

**3. Public hostnames per channel** (needed at Step 4).
- **Recommended:** `public.millrdsdacambridge.uk` for live (as DEV_NOTES 3b:619 already assumes) and
  `alpha-public.` / `beta-public.millrdsdacambridge.uk` for the others. Keeping them outside the `portal.`
  sub-tree is a small tidiness point rather than a safety one: live's HSTS `includeSubDomains` reaches only
  `*.portal.…`, and these hostnames carry certificates anyway. Cost: none.

**4. A separate DreamHost user per channel?**
- **Recommended: not now.** Revisit before beta ever holds a copy of real data. Cost of doing it: per-channel
  `SFTP_USER`/`SFTP_PASSWORD` settings, `SFTP_PATH_ROOT_DIR` becoming per channel, and a second round of
  secrets changes. What it buys: alpha's code could no longer read live's `_auth_keys/` by absolute path (5.7).

**5. Scheduled jobs on pre-release channels?**
- **Recommended: no.** Leave `/cron/` behind the gate; add `cron` to `Gatekeeper::OPEN_PREFIXES` only while a
  job is being tested, and take it out again. Cost: a job cannot be rehearsed on alpha without that temporary
  edit. Why it is the right default: a beta that one day holds a copy of live's members must not e-mail them.

**6. Media on the public door: keep Correction 10 (served by the door's own PHP with byte ranges), or return
to static copies now that each channel has its own public folder?**
- **Recommended: keep Correction 10.** The database stays the only record of what is published. Cost: one PHP
  process per media fetch, paid once per file per display because of the one-year cache.
- Alternative: static copies. Cost: a sweep that creates and deletes, a second record of publication state
  that can disagree with the first, and the GDPR disk purge back.

**7. What beta's database holds.**
- **Recommended: a fresh install**, like alpha. A copy of live is a separate job (5.6) and, if ever done, makes
  decision 5 load-bearing and needs every outside-facing row changed.

**8. `health` on the maintenance allow list.**
- **Recommended: yes.** It exposes during the upgrade window what `/health` already exposes outside it — status,
  channel, version, PHP version, site id, database state — and it is what lets the deploy prove each channel
  read its marker. Cost: none beyond that exposure.

**9. Delete the old `public_html/`, `public_html_beta/` and `public_html_dev/` from live's folder after the
changeover** (section 6 step 10, fork B).
- **Recommended: yes, by hand**, after the step 0 list confirms nothing points at them. Cost: none; they are
  old copies of the management portal. Leaving them means the Step 4 deploy refuses on live forever.

**10. If the old base holds NO installation, wipe it and treat live as a fresh channel** (section 6 step 9,
fork A).
- **Recommended: yes, when step 0 finds `_auth_keys/` missing or empty.** No changeover window, nothing to
  delete afterwards, the same procedure as alpha. Cost: none — there is nothing to lose. If step 0 finds a real
  installation, fork B applies and this decision does not arise.

---

# OWNER ANSWERS — 13 September 2026

Recorded by the main session. Where an answer departs from the recommendation, the owner's words are quoted.

1. **Marker missing, unreadable or unknown: FAIL CLOSED**, treated as a pre-release copy (the recommendation).
2. **and 3. Folder and hostname names, and public hostnames: THE PREMISE WAS OVERRULED.** The owner said:
   > "We dont want to hardcode the URLs/domain names. Remember, WebMS-Intra is a system, that can be used by many
   > clients. Any domains should be configurable during the installation process (for real customers). Of course the
   > first use will be by us, and will be github deployed via SFTP (we should also create a github action to deploy a
   > zip package containing the files so a future client can get the installation for use)"

   Consequences a builder must follow:
   - **No web address or domain name may be hard-coded** in code, workflows or settings seeds.
     `portal.millrdsdacambridge.uk`, `public.millrdsdacambridge.uk`, `beta.portal…` and `alpha.portal…` in this
     document are EXAMPLES of one customer's configuration (ours), never values. Every one must come from a setting,
     an installer answer, or a GitHub secret or variable. Known literal today: `deploy.yml:421` hard-codes the live
     health-check address.
   - **The installer asks for the addresses** (staff address, public address) for a real customer.
   - **NEW WORK: a GitHub Action that builds a downloadable zip installation package**, so a future customer can
     install without GitHub or SFTP.
4. **One or separate hosting users per channel: HYBRID, CONFIGURABLE.** The owner said:
   > "We want a mix of both, hybrid, for the user to be able to configure... In our own first case though, we'll
   > obviosuly deploy via SFTP through github actions, and in that case, yes we'll use Dreamhost Shared hosting and all
   > will be under the same user. Other clients however may use different hosting platforms, and diffferent config so
   > may user different hosting accounts."

   Consequences:
   - The deploy must support one shared hosting login AND per-channel logins.
   - Nothing in the portal may assume the channels share a user, a server or a hosting company.
5. **Scheduled jobs on pre-release channels: NO** (the recommendation).
6. **Media on the public door: through the portal's own code; Correction 10 stands** (the recommendation).
7. **Beta's database: a fresh install** (the recommendation).
8. **`health` on the maintenance allow list: YES** (the recommendation).
9. **Delete the old `public_html*` folders from live's folder: YES, by hand, after checking** (the recommendation).
10. **An empty old base: wipe it and set live up fresh: YES** (the recommendation).

**Answers 2, 3 and 4, and the new zip package, need a revision of this amendment.** It runs as a sequential Fable run
after the secret-settings design run; brief `.claude-work/briefs/plan-amend-channels-r2.md`. Until that revision
exists, **where this document hard-codes an address or assumes one hosting login, these answers win.**
