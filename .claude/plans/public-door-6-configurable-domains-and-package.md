# Plan amendment revision, stage 4: configurable addresses, hybrid hosting, and the installation package

Written 14 September 2026 on Fable, against the working tree on `claude/alpha-wip`. No repository file was edited.
This is the same text as `.claude/plans/public-door-6-configurable-domains-and-package.md`. **A builder follows this
document.** It merges the survey (stage 1, `plan-amend2-1-survey.md`), the design (stage 2, `plan-amend2-2-design.md`)
and the challenge of it (stage 3, `plan-amend2-3-challenge.md`). Every sound challenge point is folded in; section 0.4
says which, and section 0.5 says where this document departs from the design and why.

**Where this document and `public-door-5-channels-amendment.md` differ, this document wins.** Section 0.1 lists
exactly which parts of that amendment it replaces. Everything in the amendment not named there is unchanged: the marker
at the channel root, the `alpha` channel word, the per-folder mirrors, the deny files, the remote probes, the dry run,
the changeover's kill-switch discipline, and owner answers 1 and 5 to 10.

**How to read the markers.** PROVEN means the line was read or the command run on this machine — today, or in one of
the three earlier stages, which say so themselves. INFERRED means reasoning, documentation or general knowledge, not
observed here. Line numbers are from the working tree at the moment of reading; other agents were editing about sixty
files under `web/` while this was written (`git status`, PROVEN), among them `bootstrap.php`, `Gatekeeper.php`,
`full_schema.sql` and `public_html/index.php`. Every file was read as it is and nothing half-finished was treated as
the design.

**Migration numbers.** 194 (demo data), 195 (secret settings) and 196 (scheduled-job addresses and the Router fix) are
reserved by other work in flight (`.claude/HANDOFF.md:577-578, 413, 440`, PROVEN). **This document's one migration is
197.** The public-door migration, which the build plan and the amendment still call "194", takes the next free number
when Step 5 starts — 198 or later.

---

## 0. What this document replaces, precisely

### 0.1 In the amendment (`public-door-5-channels-amendment.md`)

| Amendment section | Verdict | Where the replacement is here |
| --- | --- | --- |
| 1, F2 (live's folder is the old base) | **One paragraph changed** — the values become "our own installation, for example". | 7.1 |
| 2.1 (the marker) — the "Written by" bullet | **Changed** — the package build also writes it; the installer checks for it and never writes one. | 7.2 |
| 2.2 (`Door::validate()`) | **One line added** — tolerate a byte-order mark and Windows line endings. | 7.2 |
| 2.4 (fallback, design A) | **Addition** — the dashboard banner and the installer's prerequisite check are added to design A. | 7.3 |
| 2.6 (local development) | **One sentence added** — the installer accepts the environment variable too. | 7.2 |
| 3.1 (settings, slash rules) | **Replaced in full.** | 7.4 |
| 3.3 (steps) — rows A2 and A6 | **Changed.** | 7.5 |
| 3.4 (tree guards) — guard 3's lists, guard 4 | **Changed** — `_includes`/`_functions` leave the lists (design call, section 10); the three placeholder folders leave `NOT_DEPLOYED` if OWNER DECISION 1 is yes. | 7.6 |
| 3.10 (health checks) — opening paragraph | **One sentence added** — the address format rule. | 7.7 |
| 3.11 (summary) | **Replaced in full.** | 7.8 |
| new 3.13 | **Added** — the three hosting arrangements. | 7.9 |
| 5.1 (a fresh channel) — points 1, 2 and 4 | **Changed** — the installer asks for addresses; a package installation creates the three server-managed folders itself. | 7.10 |
| 5.2 (databases) | **Replaced in full** — no hosting company named. | 7.11 |
| 5.7 (one hosting user) | **Replaced in full.** | 7.12 |
| 5.8 (things a pre-release channel needs) | **Three phrases changed.** | 7.13 |
| new 5.9 | **Added** — installing from the package. | 7.14 |
| 6 (the changeover) | **Replaced in full** — placeholders instead of our names; two new sentences in steps 8 and 10. | 7.15 |
| 7, Step 2 | **Items 11 to 17 added; verification extended.** | 7.16 |
| 7, Step 4 | **One rule added** — no published package before this step lands. | 7.16 |
| 7, Step 5 | **First line changed** — migration number; the public-address installer field ships here. | 7.16 |
| 7, Step 9 | **One Codex question added.** | 7.16 |
| 10 (documents) | **Replaced in full** by section 8 here. | 8 |
| OWNER DECISIONS 2, 3 and 4 | **Replaced** by the owner's answers and this design. | 7.17 |
| OWNER ANSWERS — closing paragraph | **Replaced** — the revision now exists. | 7.17 |

### 0.2 In the build plan (`public-door-3-build-plan.md`)

- `tblPublicHosts` (`:395-421`): unchanged in shape; the installer adds one row for site 1 (section 3.7 here);
  `siteID` keeps allowing NULL per the amendment §9.1.
- Step 5's migration number: the next free number at the time, never 194.
- Step 2 gains the items in 7.16.

### 0.3 In `DEV_NOTES.md`, `README.md`, `.claude/CLAUDE.md`

Section 8.1 is the list. In one line: DEV_NOTES 3b is rewritten from section 5 here, 3c from 7.15, and every mention
of our own hostname becomes `portal.example.org` except one labelled "our own installation, for example" paragraph.

### 0.4 What was taken from the challenge

All fourteen numbered changes in the challenge's section 10 are applied: E1, E2, B1, B2, B3, D1, D3, C1, C2, C3, A1,
A2, E3, and the one-line additions F1, F2, F4, F5, B4, D4. Two of its counts were corrected against the code while
folding them in (E3's "five paths" — section 6.8; the design's "sixteen settings" — section 5.1). Of the three design
calls it left open: B2's leftover-file delete is offered in the first version (section 6.10); B3's "hold tag packages
until Step 4" is taken and made mechanical by a guard (section 6.3); D2's rename of `.channel` is **not** taken, for a
reason the challenge did not weigh (section 4.6).

### 0.5 Where this departs from the design (stage 2), and why

1. **The eight `site.url` readers change in the same commit as migration 197, not in a follow-up** (challenge E1).
   Seeding an empty row silently disables the `??` fallback in five of them, so invitation e-mails and public form
   links would carry no hostname on every installation until an administrator set the address. Section 3.2.
2. **The three server-managed folders are NOT in the package** (challenge B1). Shipping `_auth_keys/` with a deny file
   inside creates the one upload shape — a "replace folder" upload — in which a customer destroys their own
   unrecoverable encryption key. The installer and the upgrade page create the folders and write the deny files
   instead. Section 6.11.
3. **Leftover files after a merged upgrade are not "harmless"** (challenge B2). A leftover folder or non-PHP file inside
   the web folder silently hides the portal address of the same name — this project's own web-root shadowing trap.
   The upgrade page now reads `REMOVED-FILES.txt` and offers a guarded delete. Section 6.10.
4. **No published package until Step 4 has landed** (challenge B3), enforced by a build guard. Section 6.3.
5. **The "channel file missing" message never names a channel word** (challenge D1); a button copies the word from the
   package's own `VERSION.txt` instead. Section 4.3.
6. **Migration 197 is tightened** (challenge E2): `AND siteID IS NULL`, a self-record, the `full_schema.sql` seed-block
   line, and the support-address seed's duplicate-key clause becomes a no-op so a replay cannot defeat it. Section 3.4.
7. **Guard G1 is a listing comparison, not a timestamp test** (challenge A1) — the timestamp test passes for every
   copy and proves nothing. Section 6.6.
8. **The sign-in order never crosses login groups** (challenge C1); **a channel folder named like a door is refused**
   (C2); **values are trimmed and ports validated** (C3). Section 5.
9. **The dompdf tarball is pinned by hash** (challenge A2). Section 6.7.

---

## 1. The facts everything rests on

From the earlier stages, not re-proven: the installer asks for no address and never reads a channel marker (survey
§2.2, §2.7); about fifty places read `HTTP_HOST` (survey §1.6); the deploy reads secret names that no longer exist and
GitHub holds names the amendment retired (survey §5, §6); no GitHub Release has ever been created and `release.yml`
failed on every run for reasons now lost (survey §7.4); iHymns publishes a Release asset with a pinned action, a
kill-switch variable and a guard job, but has no zip, no checksum and no web package (survey §10); nothing checks for a
hard-coded domain (survey §8.6).

Checked today, PROVEN:

- **The settings snapshot.** `bootstrap.php:383-401` selects every portal-wide row and every row for the detected
  organisation, ordered so the portal-wide rows come first and the organisation's rows overwrite them through
  `assign_setting()` (`:282-293`). An empty `settingValue` lands as `''` — there is no emptiness test. So a
  per-organisation `site.url` row wins for that organisation, and an empty seeded row is `''`, not absent. (This
  settles what the design left INFERRED at its §2.5 step 1.)
- **`App::settings('a.b')`** returns `null` only when the key is absent from the snapshot and returns the leaf value —
  `''` for an empty row — otherwise (`App.php:132-151`). `App::settingForSite()` returns `(string) settingValue` for a
  row that exists (`:171-191`). The `??` operator fires only for `null`.
- **The eight `site.url` readers**, by grep: `Mailer.php:197` (`?? ''`), `forms/manage.php:67`, `forms/edit.php:67`,
  `service-plans/edit.php:125`, `invites/save.php:49` (all `?? ('https://' . host)`), `forms/public.php:112`
  (`settingForSite … ?? ('https://' . host)`), `admin/settings/qr/index.php:222` (`?? 'https://example.invalid/'`),
  `admin/maintenance/backup-check.php:117` (`?? ''`). No `site.url` row is seeded anywhere under `web/_sql/`.
- **The support-address seed** is `(NULL, 'portal.support.email', 'portal-support@millrdsdacambridge.uk',
  'portal-support@millrdsdacambridge.uk', 0)` with `ON DUPLICATE KEY UPDATE defaultValue = VALUES(defaultValue)` in
  BOTH `full_schema.sql:3011-3012` and `062_help_support_route.sql:10-11`.
- **The installer** encodes fractional pages as string steps with booleans (`_install/index.php:117-125`); its
  "continue with existing data" choice sends the person to step 3 (`:574-577`); step 3 writes the brand settings with
  `ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue), defaultValue = VALUES(defaultValue)` (`:775-792`);
  finalize creates `_auth_keys/` with mode 0750 if missing (`:940-947`), writes `auth_creds.php`, `enc.key`,
  `portal.installed_version` and `.installed`, and sets 0640 on the three files (`:957-1027`); the prerequisite table
  is PHP 8.4+, `mysqli`, `sodium`, `json`, `mbstring`, `_sql/` readable, `full_schema.sql` readable, `_auth_keys/`
  writable or creatable (`:1189-1233`).
- **The upgrade page** aborts if the pre-upgrade backup fails, records `installed_version` only when every migration
  succeeded, and turns maintenance off (`_install/upgrade.php:85-155`). Its sidebar says "Upload the new portal files
  to your server (FTP sync)" (`:317`).
- **The maintenance gate** does not fire when `installed_version` is empty (`Maintenance.php:137-143`); `health` is in
  `Gatekeeper::OPEN_PATHS` (`Gatekeeper.php:122`) but not in `Maintenance::ALLOW_LIST` (`Maintenance.php:56-118`).
- **The web folder's `.htaccess`** answers 404 for any `.php` address but the three entry points (`:48-52`) and hands
  any EXISTING file or folder to Apache, never to the portal (`:67`, `:71`).
- **`download-dompdf.sh`** fetches `https://github.com/dompdf/dompdf/archive/refs/tags/v3.1.5.tar.gz`, checks no hash,
  and only WARNS when the autoloader is missing (`:44-63`).
- **`Asset.php`** names four local fallback files (`:129-138`: `bootstrap.min.css`, `bootstrap.rtl.min.css`,
  `bootstrap.bundle.min.js`, `fontawesome-all.min.css`) and carries an SRI hash for each (`:71-84`); `.gitignore:41-45`
  ignores three of them plus the `webfonts/` folder — the RTL sheet is not ignored and not committed. `Asset.php:152-163`
  says the product is sold to organisations behind networks that block public CDNs.
- **`.gitignore`** covers `web/_auth_keys/`, `web/_uploads/`, `web/_backups/`, `web/_libraries/`, `.env`, `*.key`,
  `*.log`, `error_log` (`:26-52`). Three `.gitkeep` files are tracked under `web/` (`_functions`, `_includes`,
  `private_html`); nothing in `web/` references `_includes` or `_functions`; the deploy's `LFTP_EXCLUDES` drops
  `.gitkeep` (`deploy.yml:109`) and its mirrors run with `--no-empty-dirs`, so a deployed installation has never had
  those two folders (INFERRED from the two flags together).
- **`release.yml`** sets `GH_TOKEN` (`:112-113`), attaches no files, has no manual trigger, and cancels an in-progress
  run (`:27-29`). **`version-bump.yml`** strips any `-suffix` before bumping (`:122-123`).
- **`pr-security.yml`** runs each audit script by name in one step (`:272-398`) — a new check is one more block there.
- **`lftp` is not installed on this machine** (`which lftp`), as the amendment's F9 says; every lftp claim stays INFERRED.
- Migrations on disk end at `194_demo_data_register.sql` (untracked); `full_schema.sql:8716` lists it in the
  `tblMigrations` seed block; `189_unreachable_admin_and_widget.sql` ends with the self-record idiom every migration
  needs. Issues #499 and #500 are open with the titles the survey recorded.

---

## 2. No address built in — what each one becomes, and the check that keeps it so

The rule (`.claude/CLAUDE.md`, "No web address is ever built in", PROVEN): an address comes from the installer, a
setting, or a GitHub secret or variable; a missing one gives a clear message or a skipped step with a warning, never a
quiet fallback to ours. The survey's table (§1.2) is the complete list; the challenge re-ran the scan and found nothing
new (E0).

### 2.1 The table

| Survey # | File and line | Replacement |
| --- | --- | --- |
| 1 | `deploy.yml:421`, the live health probe | Deleted with the step. The rewritten probe reads `ADMIN_BASE_URL_<CHANNEL>` and `PUBLIC_BASE_URL_<CHANNEL>` (section 5.4); empty → `::warning::` and skip. |
| 2 | `deploy.yml:33-37`, header examples | Header rewritten (section 5.6); examples use `example.org`. |
| 3, 4 | `full_schema.sql:3011` and `062_help_support_route.sql:10`, the support address seeded twice | Value and default become `''` in `full_schema.sql`; the row's duplicate-key clause becomes the no-op `settingKey = settingKey`. Migration 197 blanks an unedited row on upgraded installations (section 3.4). Migration 062's two literals and its clause change the same way — **OWNER DECISION 3**, because it edits a shipped migration. The installer asks for the support address instead (section 3.5). |
| 5 | `help/support.php:28`, the fallback | Fallback becomes `''`. When empty the page says: "No support address has been set up for this portal yet. An administrator can set one at Settings → Organisation." |
| 6 | `calendar/export.php:119`, `HTTP_HOST` falling back to our domain | Becomes `App::baseUrl()` (section 3.3). |
| 7 | `admin/integrations/cloudflare-stream/index.php:169`, placeholder text | `placeholder="portal.example.org (blank = any)"`. |
| 8 | `bootstrap.php:466`, a comment | "…which is the case on our own installation, where the host redirects http to https at its edge" — no domain. |
| 9 | `web/public_html_redir/index.html:5, 21` | **OWNER DECISION 1**: delete the three placeholder folders from the repository. If kept, the two lines become `https://portal.example.org/` with a comment saying the folder is a placeholder nothing deploys or packages. Either way the package build removes them (section 6.4). |
| 10 | `docs/openapi.yaml:15` | Deleted. Nothing references the file (survey); the served specification is `_core/api-spec.json` with `servers: "/"`. |
| 11 | `docs/day2-support.md:14` | "E-mail the support address shown at Help → Support in your portal." |
| 12 | `DEV_NOTES.md`, fourteen places | Sections 3b and 3c rewritten (section 8.1) with `example.org` names and one labelled "Our own installation, for example: …" paragraph; the release checklist's `:1379` becomes "open `/health` on the live staff address you set in `ADMIN_BASE_URL_LIVE`"; diagrams use `portal.example.org`; `dh_abcd1234` stays (already an obvious placeholder). |
| 13 | `README.md:114, 189, 196, 225-227` | Rewritten per section 8.1; `/admin/upgrade.php` becomes `/admin/upgrade`. |
| — | `.claude/CLAUDE.md:33`, "Server:" | "Our own installation (one customer's configuration, for example): portal.millrdsdacambridge.uk". Planning and memory files under `.claude/` stay, labelled as examples, per the standing rule. |

Third-party addresses seeded as defaults (`translation.libre.baseUrl`, the push endpoint allow list, Gravatar, the QR
service, Cloudflare Stream — survey §1.4) are other people's public services, not ours, and stay. They are listed by
the check's advisory section so a reader sees them; they are never a failure.

### 2.2 The check: `tools/audit-checks/check_no_hardcoded_domains.py` (new)

- `OWN_DOMAINS` at the top of the file holds `millrdsdacambridge.uk`, with a comment: add any domain that is ours;
  never a customer's. A hit is any line containing one of them, case-insensitive.
- **Strict scope** (a hit fails with `--strict`): `web/`, `.github/`, `tools/`, `README.md`, `docs/INSTALL.md`.
- **Report scope** (listed, never fails): `DEV_NOTES.md`, `FEATURES.md`, `CHANGELOG.md`, the rest of `docs/`.
- **Excluded**: `.claude/`, `.claude-work/`, `.dev-team/`, `.git/`, `web/_libraries/`, `node_modules/`.
- An **advisory** section prints every `https://` or `http://` literal in strict scope whose host is not on a
  known-third-party list (schema.org, w3.org, php.net, github.com, the CDNs, payment and messaging providers, Google,
  Microsoft, Cloudflare, `example.*`, `*.invalid`, `localhost`). Advisory only: an exact list of our own domains can be
  enforced without crying wolf; the rest is for a reader. A check that cries wolf gets switched off (CLAUDE.md's
  web-root shadowing note says exactly this about its first draft).
- `--root <dir>` scans a staged package folder instead of the repository (section 6.6, G6).
- Wired into `pr-security.yml` as one more block beside `check_no_php_in_urls.py` (`:302`), and run by the package build.
- **Prove it fires before trusting it** (memory note "checks only cover what they read"): run it BEFORE the removals in
  2.1 and confirm it lists survey items 1 to 9; make the removals; confirm it is clean. Record both runs in the commit.
- `.claude/CLAUDE.md`'s standing rule keeps its `grep -rn millrdsdacambridge web .github tools` line as the hand check;
  its "Known offender" sentence is removed when `deploy.yml:421` is gone.

---

## 3. The portal's own address

### 3.1 The three answers, in one table

| Answer | Required? | Where it lives | Form | Changed later at |
| --- | --- | --- | --- | --- |
| Staff (management) web address | Yes. Pre-filled from the address the installer was reached through; confirmed or corrected by the person. | `tblSettings`, key `site.url`, `siteID` NULL (portal-wide). A per-organisation row may override it, by the snapshot's existing rule (section 1). | A full address with scheme and no path: `https://portal.example.org` (a port is allowed: `http://localhost:8080`). | Admin → Settings → Organisation, a new "Web addresses" card (global administrator only — the page's existing rule, `organisation/save.php:87-99`, design §0 PROVEN). |
| Public website address | No. Asked only from the commit that creates `tblPublicHosts` (build plan Step 5); before that the field does not appear. | One `tblPublicHosts` row: `siteID` 1, `hostName`, `isActive` 1, `createdByID` NULL. | The hostname only, lower case, no scheme, port or path: `noticeboard.example.org` — the table's own rule (build plan `:407-408`). | Admin → Public website → Hostnames (`/admin/public/hosts`, build plan), global administrator only. |
| Support e-mail address | No. | `tblSettings`, key `portal.support.email` (exists; seeded `''` after section 2). | An e-mail address. | The same "Web addresses" card. |
| Channel | **Not asked.** | `<channel root>/.channel`, shipped inside the package (section 4). | One word. | Not by hand; the next package or deploy rewrites it. |

Nothing becomes public by answering the second question: `public.enabled` is seeded `false` (build plan `:576`) and the
public door answers 404 until a global administrator switches a surface on. The installer says so under the field.

### 3.2 `site.url` is seeded empty — so every reader changes in the same commit (challenge E1)

Seeding `(NULL, 'site.url', '', '', 0)` makes `App::settings('site.url')` return `''` instead of `null` on every
installation (section 1). Five readers use `?? ('https://' . $host)`, and `??` fires only for `null`. With the seed in
place and no code change, `forms/manage.php:118` would build `'' . '/f/' . $token` — a "copy this public link" address
with no host; `invites/save.php` would put a bare path in every invitation e-mail; the service-plans public link, the
public form page and the QR settings page go the same way. Migration 197 runs on every existing installation at its next
upgrade and `full_schema.sql` seeds the row on every fresh install, so this would break every installation until an
administrator visited the new card. The dashboard notice (3.8) would appear, but the e-mails would already be wrong.

**Therefore, in the SAME commit as migration 197:** every one of the eight readers listed in section 1 becomes a call to
`App::baseUrl()` (3.3), which treats an empty setting as unset; and the four private "scheme plus `HTTP_HOST`" helpers
(`Newsletter::baseUrl()` `Newsletter.php:301-305`, `Giving::absoluteUrl()`, `Workflow::absoluteUrl()`, the venue-reminder
cron's helper — design §0, PROVEN there) become one-line calls to it. The "follow-up sweep, not on this plan's path"
in 3.10 refers ONLY to the ~45 raw `HTTP_HOST` readers, never to these twelve.

Two tests that would have caught the mistake, added to Step 2's verification (section 9): with `site.url` EMPTY, generate
an invitation and confirm the e-mail's link begins with `https://<the host you used>/`; with `site.url` SET to a
different host, confirm the link begins with the setting. The self-test in 3.6 gains "setting present but empty →
`baseUrl()` returns the request's own address".

### 3.3 One function answers "what is this portal's address": `App::baseUrl()`

```
App::baseUrl(?int $siteId = null): string
  1. The setting, if NON-EMPTY.
     - $siteId given: one query — the organisation's own row, else the portal-wide row, skipping empty values:
         SELECT settingValue FROM tblSettings
         WHERE settingKey = 'site.url' AND (siteID = ? OR siteID IS NULL) AND settingValue <> ''
         ORDER BY (siteID IS NULL) ASC LIMIT 1
       (the ordering App::settingForSite() already uses, App.php:177-181, plus the emptiness test, so an
        organisation row left empty by the settings editor cannot hide the portal-wide address)
     - $siteId not given: $SETTINGS['site']['url'] from the snapshot (an organisation's own row already
       overrides the portal-wide one there — bootstrap.php:383-401)
     Trim; drop one trailing "/". Non-empty → return it.
  2. Else, on the web with a Host header: scheme + HTTP_HOST, the scheme test Newsletter::baseUrl() uses today
     (HTTPS set and not "off").
  3. Else ''.   (Command line, or a web request with no Host header — callers that build a link show a relative
                 one; the dashboard notice tells the administrator to set the address.)
```

`forms/public.php` passes the form's own `siteID` (it must never read the ambient organisation on a public route —
CLAUDE.md's Forms note); every other caller passes nothing. Why "empty means unset at every level": an administrator
who clears the field on the new card must get today's behaviour back, not a portal that builds hostless links.

### 3.4 Migration 197 and the `full_schema.sql` lines, exactly (challenge E2 folded in)

`web/_sql/197_portal_address_settings.sql`:

```sql
-- A. The portal's own web address. One portal-wide row, empty until the installer or an
--    administrator fills it. Empty means "not set": App::baseUrl() falls back to the request.
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`)
VALUES (NULL, 'site.url', '', '', 0)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- B. Our own support address must not sit in a customer's database. The seed wrote the same text
--    into settingValue and defaultValue, so an UNEDITED row still has value = default; an EDITED
--    row does not (no writer in the codebase touches defaultValue — group.php:222-228,
--    settings/save.php:360 and :598, PROVEN by the challenge). Portal-wide row only. The last
--    condition makes a second run a true no-op.
UPDATE `tblSettings`
SET `settingValue` = '', `defaultValue` = ''
WHERE `settingKey` = 'portal.support.email'
  AND `siteID` IS NULL
  AND `settingValue` = `defaultValue`
  AND `settingValue` <> '';

-- C. Self-record (the installer replays every numbered migration and ignores tblMigrations).
INSERT INTO `tblMigrations` (`filename`) VALUES ('197_portal_address_settings.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
```

`full_schema.sql`: add `(NULL, 'site.url', '', '', 0)` to the `site.*` block (`:1427-1459`); change `:3011` to
`(NULL, 'portal.support.email', '', '', 0)` and `:3012` to `ON DUPLICATE KEY UPDATE settingKey = settingKey`; add the
`tblMigrations` seed line for 197 beside 194's (`:8716`). `062_help_support_route.sql:10-11` changes the same way
(OWNER DECISION 3).

Why the seed's clause must become a no-op (challenge E2): the installer's "continue with existing data" path replays
`full_schema.sql` and then every migration on a database that already holds settings. With the old clause
`defaultValue = VALUES(defaultValue)`, the replay would rewrite `defaultValue` to `''` BEFORE 197 ran, an unedited row
(value still our address) would no longer equal its default, 197 would skip it, and our address would survive. With the
no-op clause no replay ever touches `defaultValue`, and 197's test holds on every path. This is the settings-table
trap (memory note `webms-settings-table-trap.md`) from the other side: a seed's duplicate-key clause must never write a
column an administrator or a later migration relies on.

Our own installation's row is unedited, so 197 blanks it on our upgrade — correct and expected; changeover step 10
(7.15) re-sets it on the card. `check_schema_seed_parity.py` and `check_migration_idempotency.py` must be green;
`check_settings_keys.py` then stops reporting `site.url` as unseeded (survey §1.6).

### 3.5 The installer page: step `'1.7'`, "Web addresses"

Shown after 1.5 (organisation type) and before 2 (database), using the same string-step-plus-boolean mechanism as 1.5
and 2.5 (`_install/index.php:117-125`). Why before the database: the answers need no database, and asking everything a
person must know before the first destructive step (step 3 writes the schema) keeps the wizard's shape "questions first,
then work". Session keys: `install_site_url`, `install_public_host`, `install_support_email`. Fields:

1. **"The address of this portal"** — required, pre-filled with `https://` + `$_SERVER['HTTP_HOST']` when the request
   was over https (`HTTPS` set and not `off` — `Newsletter::baseUrl()`'s test, `Newsletter.php:303`, PROVEN), else
   `http://` + host. Help text: "The address people type to reach this portal. It is used in every e-mail the portal
   sends, so it must be the address that works from outside. If this shows `http` but you reached the installer over
   `https`, change it." (The last sentence is challenge F5: the bootstrap-free installer has none of migration 193's
   trusted-proxy handling, so behind a proxy that ends TLS the pre-fill may say `http` — INFERRED.) Why pre-fill and
   confirm rather than trust silently: the installer is reached through the very hostname the customer set up (the
   front controller hands every address to it, `index.php:15-26`), so the guess is right in the ordinary case; the
   case where it is wrong — a hosting company's temporary preview address, a bare IP — is exactly the one the person
   recognises on screen.
2. **"Public website address (optional)"** — rendered only when the code carries `tblPublicHosts`. Help text: "If you
   will show a public noticeboard or calendar on its own address, type that hostname here, for example
   `noticeboard.example.org`. Nothing is published until an administrator switches it on."
3. **"Support e-mail address (optional)"** — help text: "Shown on the Help → Support page and in the footer of e-mails."

A pre-release package shows the box from section 4.5 at the top of this page and of step 1.

### 3.6 Validation, in one place for the installer and the admin card: `Portal\Core\PortalAddress`

New file `web/_core/PortalAddress.php`, **pure static, no dependencies**, so the bootstrap-free installer can `require`
it as it already requires `_core/version.php` and `_core/brand-defaults.php` (`_install/index.php:45, 52`), and the
admin page gets it through the autoloader.

- `normaliseUrl(string $raw): array{ok: bool, value: string, warning: string, error: string}`. Trim; scheme `http` or
  `https` required, lower-cased; host of letters, digits, hyphens and dots, or `localhost`, or a punycode name (a name
  typed with non-ASCII letters is refused with "type the punycode form" — the portal builds links by string
  concatenation, so nothing would convert it); optional `:port` 1-65535; **no path, query or fragment**, refused with
  "the portal must be at the root of its own address, for example `https://portal.example.org`, not
  `https://example.org/portal`" (why: `RewriteBase /` at `public_html/.htaccess:26` and every link and redirect in the
  portal begins with `/`); one trailing slash trimmed; host lower-cased; at most 255 characters. `http://` is accepted
  with `warning` = "use https for a real installation" — the documented local route `php -S localhost:8080` needs it
  (DEV_NOTES `:1342-1348`, PROVEN).
- `hostName(string $raw): array{ok: bool, value: string, error: string}`: accepts a bare hostname or a full address and
  returns the hostname alone, lower case, no scheme, port or path; refuses `/`, `@`, spaces or a port. The rule
  `admin/public/host-save.php` (build plan) uses too, so a hostname typed at install and one typed later cannot differ
  in form.

`tools/portal-address-selftest.php` (the pattern of `webpush-selftest`, `report-builder-selftest`) holds a table of
accepted and refused inputs for both functions, plus the `App::baseUrl()` cases: setting set; setting empty with a Host
header; setting empty on the command line; an empty organisation row over a set portal-wide row (uses reflection to seed
`App::$settings`, as `webpush-selftest` reaches private state).

### 3.7 Where the installer writes the answers, and when

At step 3, right after the brand seeds, in the same prepared-statement loop (`_install/index.php:775-792`), with one
difference in the duplicate-key clause:

```sql
INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive)
VALUES (NULL, ?, ?, '', 0)
ON DUPLICATE KEY UPDATE settingValue = IF(settingValue = '', VALUES(settingValue), settingValue)
```

Why: the "continue with existing data" path runs step 3 against a database that already has settings, after an
umbrella-administrator sign-in (survey §2.4; `:574-577` PROVEN). An address an administrator chose earlier must not be
overwritten by the wizard's pre-fill; an empty one is filled. The brand seeds overwrite today (`:778-779`); this
document does not change them. (`VALUES()` inside `ON DUPLICATE KEY UPDATE` is deprecated from MySQL 8.0.20 — challenge
F6 — but the whole codebase uses it, `_install/index.php:778`, `upgrade.php:142`; it belongs to #475's list, not here.)

The public hostname, when given and when the table exists:

```sql
INSERT INTO tblPublicHosts (siteID, hostName, isActive, createdByID)
SELECT 1, ?, 1, NULL FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tblPublicHosts WHERE hostName = ?)
```

The `WHERE NOT EXISTS` idiom the codebase uses for role seeds (CLAUDE.md, migration 183). The unique key on `hostName`
makes a duplicate impossible either way; the guard avoids an error page.

### 3.8 The admin card and two dashboard notices

- **Settings → Organisation, "Web addresses" card**: the staff address (validated by `normaliseUrl()`, the warning
  shown for `http`), the support e-mail, and — from Step 5 — a link to Admin → Public website → Hostnames rather than a
  second place to edit hostnames. Written by the page's existing `$upsert` helper (`save.php:176-181`, design §0), global
  administrator only.
- **Dashboard, administrators only.** When `site.url` is empty: "The portal's web address has not been set; e-mailed
  links use whatever address each visitor typed. Set it at Settings → Organisation." When set but its host is not the
  host of the current request: "This portal's recorded address is `X`; you reached it as `Y`." One line, no action
  taken; it catches a moved portal and a stale setting.
- **`/health`**: no new field for the address. Every field on `/health` is public; the deploy probe has no need of it.

### 3.9 How this fits `tblPublicHosts` and `tblSites.hostPattern`

- `tblPublicHosts` is unchanged from the build plan (`:395-418`), with the amendment's note that `siteID` keeps allowing
  NULL (§9.1). The installer adds one row for site 1; further organisations' hostnames are added at
  `/admin/public/hosts`. The migration that creates the table is the public-door migration, renumbered when Step 5
  starts.
- `tblSites.hostPattern` is **not written** by the installer. It is read only by subdomain detection in multi-site mode
  (`Site.php`, seeded mode `session` — survey §1.7); writing the staff address into it would give the address a second
  home with a different meaning, the mistake the build plan's own comment on `tblPublicHosts` warns about (`:397-403`).

### 3.10 The wider `HTTP_HOST` sweep — a follow-up issue, not on this path

The ~45 raw readers (survey §1.6) keep building links from whatever hostname the request carried until they are
converted, so section 2's rule is not yet fully true of the code (challenge F7). Filed as its own issue, ordered by risk:

1. **E-mailed links first** — `auth/forgot-password/save.php:177`, `account/erasure-request.php:67`,
   `calendar/event-invites-send.php:71`, the three cron reminders, `PrayerChain.php:191`. Why first: anybody can send a
   request with a made-up `Host` header; a password-reset e-mail whose link is built from it carries the outsider's
   address. The setting closes that as a side effect. Say so in the issue.
2. Payment return addresses and the webhook/redirect addresses shown to administrators.
3. Feeds, sitemap, robots, public pages, QR codes.
4. **Never changed**: readers that must match what the browser sent — WebAuthn's relying party (`auth.webauthn.rpID`
   seeded empty), the Twitch `parent`, multi-site subdomain detection, the installer's drop confirmation.

---

## 4. The channel for a package installation

### 4.1 The package carries the marker; the installer checks it arrived; nobody guesses

- The package build writes `.channel` into the staging folder before zipping, exactly as the deploy writes it before
  its first `put` (amendment §2.1): `live` for a package built from a tag on `main`, `beta` from a tag on `beta`, the
  branch's word for a manual build (section 6.2). It ships at the package root, beside `_core/`, which is the channel
  root after upload.
- Nothing in `Door::validate()`, `Gatekeeper` or the mapping (amendment §2.2-2.5) changes: a package installation reads
  the file and reports `PORTAL_ENV_SOURCE = 'file'` exactly as a deployed one does. Under today's folder-sniffing
  bootstrap the marker is unread and harmless; it becomes load-bearing the moment Step 2 lands.
- **The installer never writes `live` by itself**, and never tells a person to. Owner answer 1 is that nobody guesses.
  **Rejected alternative, recorded:** the installer asks "Is this the live portal or a test copy?" and writes the word.
  It invites installing beta code as "live" and creates a second opinion about the channel beside the deploy's; the
  package's word is the product's statement about the code's maturity, and one statement is enough.

### 4.2 Two prerequisite rows on step 1, and one re-check at finalize (challenge D1 and D3 folded in)

The existing prerequisite table (`_install/index.php:1189-1233`) gains:

- **"Channel file present."** Passes when `getenv('PORTAL_ENV')` is non-empty — shown as "Channel: DEV (from the
  environment)" — OR when `INSTALL_ROOT/.channel` is readable and its first line, with a UTF-8 byte-order mark removed
  and Windows line endings tolerated, is one of `live`, `beta`, `alpha`, `dev`, `prod` — shown as "Package channel:
  LIVE". Why the environment variable passes: the documented local route is `export PORTAL_ENV=dev; php -S …` with no
  file (DEV_NOTES `:1342-1348`), the amendment keeps it (§2.6), and `Door::validate()` reads the variable before the
  file (§2.2) — the installer and bootstrap must agree about what counts. On failure, the message **never contains a
  channel word**: "The file `.channel` was not found beside `_core/`. It is a hidden file, and some file managers do
  not show or upload hidden files. Upload the package again with hidden files shown. The word this package carried is
  on the `Channel:` line of `VERSION.txt` in the portal folder." Plus, when it applies, the button in 4.3.
- **"Hidden files arrived."** `.htaccess` exists inside whichever of `admin_html/` or `public_html/` exists under
  `INSTALL_ROOT`. Same message. Why this row matters on its own: without that `.htaccess` there are no clean addresses,
  `.php` files are not blocked and dot-files are served — the portal does not work, whatever the marker says.
- **At finalize (step 5)** the channel check runs again and refuses there too, with the same message and button,
  because the step-1 table can be bypassed by jumping to a later step (`README.md:143`, survey). The `.htaccess` check
  need not repeat: a portal without it fails visibly on the first page after install.

### 4.3 "Restore the channel file from VERSION.txt" — a button that copies, never decides

When `.channel` is missing (or unknown) but `VERSION.txt` is present and its `Channel:` line holds a known word, the
installer offers one button that writes that word into `INSTALL_ROOT/.channel`. It copies the package's own statement;
it decides nothing. `VERSION.txt` is a visible file written by the same build step as `.channel` (section 6.4), so the
two can only disagree if a person edited one. The same button appears on `_install/upgrade.php` (umbrella administrator
only, CSRF-protected, shown only when `PORTAL_ENV_SOURCE === 'fallback'` and `PORTAL_ROOT/VERSION.txt` carries a known
word) — that covers a package UPGRADE whose hidden file was lost. A deployed installation has no `VERSION.txt`
(section 6.4), so the button never appears there; the deploy is re-run instead.

### 4.4 `Door::validate()` tolerance

One line in the reader: strip a UTF-8 byte-order mark and trailing `\r` before comparing the word. A customer who
re-creates the file in a Windows editor may produce either (INFERRED, common). Amendment §2.2 gains that sentence (7.2).

### 4.5 Where the person finds out

- A pre-release word (`beta`, `alpha`, `dev`) puts a box on installer steps 1 and 1.7: "This is a BETA pre-release
  package. Every page will ask for a staff sign-in, and errors are shown on screen. Do not use it for real data.
  Install a live release for real use."
- `_install/upgrade.php` shows the channel word in its sidebar and, for a pre-release word, the same box. Why there:
  after a package upgrade the maintenance gate sends every administrator to that page, so it is where a person who
  extracted a beta package over a live portal finds out. The fix is to extract the live package again.
- The admin dashboard shows a red banner when `PORTAL_ENV_SOURCE === 'fallback'`: "This portal cannot find its channel
  file (`.channel` beside `_core/`). Until it is restored, members are asked to sign in as staff and errors are hidden.
  Re-run the deploy, or go to Admin → Upgrade and restore it from `VERSION.txt`." The amendment offered this banner
  only under design B (§2.4); it costs nothing under A, and a package customer has no deploy to re-run. The banner text
  says "errors are hidden" because that is what design A does once Step 2 lands (`bootstrap.php:147-156` changes then);
  today's bootstrap still shows errors after a fallback guess — do not "correct" the banner against today's code
  (challenge D0).
- Members meet the staff sign-in page while the marker is missing (fail closed). The Gatekeeper's page must not say
  why — that would tell strangers something about the server. `INSTALL.md` §11 says: "if every page asks for a staff
  sign-in, sign in as the administrator and read the red notice on the dashboard" (challenge D4).

### 4.6 Why `.channel` keeps its name (design call; the challenge's D2 recommended renaming it)

The challenge proposed a visible name such as `CHANNEL` so that a file manager that hides or drops dot-files cannot lose
the marker. That would remove nothing from the failure class: the web folder's `.htaccess` is a dot-file the portal
cannot run without (4.2), and Apache gives it no other name. A file manager that drops hidden files breaks the
installation whatever the marker is called; the installer's "hidden files arrived" row catches both with one message,
and the `VERSION.txt` button covers the one remaining case — a marker deleted on its own. Renaming would cost a
find-and-replace through the amendment (§2.1, §3.3, §3.5, §3.6, §3.10 probe 2, `.gitignore`, `TOP_FILES`) and the build
plan for no reduction in what can go wrong. Recorded so nobody re-opens it without a new reason.

---

## 5. Hybrid hosting in the deploy

### 5.1 The settings

**Shared (secrets, unchanged names):** `SFTP_HOST`, `SFTP_USER`, `SFTP_PASSWORD`, `SFTP_KEY`, `SFTP_PORT`,
`SFTP_PATH_ROOT_DIR`.
**Per channel, required (secrets):** `SFTP_PATH_LIVE_DIR`, `SFTP_PATH_BETA_DIR`, `SFTP_PATH_ALPHA_DIR` (option C).
**Per channel, OPTIONAL overrides (secrets)**, `<X>` = `LIVE`, `BETA` or `ALPHA`:

| Group | Name | Overrides |
| --- | --- | --- |
| Login | `SFTP_HOST_<X>` | `SFTP_HOST` |
| Login | `SFTP_USER_<X>` | `SFTP_USER` |
| Login | `SFTP_PASSWORD_<X>` | `SFTP_PASSWORD` |
| Login | `SFTP_KEY_<X>` | `SFTP_KEY` |
| Login | `SFTP_PORT_<X>` | `SFTP_PORT` |
| Location | `SFTP_PATH_<X>_ROOT_DIR` | `SFTP_PATH_ROOT_DIR` |

**Variables:** `SFTP_ENABLED` (the kill switch); `ADMIN_BASE_URL_<X>` and `PUBLIC_BASE_URL_<X>` (probe addresses).

That is 27 secret names (6 shared, 3 required folder names, 6 override kinds × 3 channels) and 7 variables. The design
called it "sixteen settings" by counting kinds rather than names; the workflow's `env:` block must list all 27 names,
because a step reads a secret only through `env:` (amendment §3.3) — that also settles challenge C5 without any
computed-name lookup: every name is in `env:` and the shell chooses.

Why these names: the login overrides put the channel at the END, so each sits directly under the shared setting it
overrides in GitHub's alphabetical list (`SFTP_HOST`, `SFTP_HOST_ALPHA`, `SFTP_HOST_BETA`, `SFTP_HOST_LIVE`, …). The
location override keeps the owner's `SFTP_PATH_<CHANNEL>_<WHAT>_DIR` shape, so it sorts beside `SFTP_PATH_<X>_DIR`. Both
ROOT settings are absolute paths despite the `_DIR` suffix, as `SFTP_PATH_ROOT_DIR` already is. The channel word is
`LIVE`, never `MAIN` (DEV_NOTES 3b).

### 5.2 How they combine (challenge C1 folded in)

```
FIRST, every one of the 27 values: trim leading and trailing whitespace (a pasted secret often carries a
newline, and lftp's failure message would not say so); REFUSE any value with inner whitespace;
SFTP_PORT and SFTP_PORT_<X>, when set, must be an integer 1-65535.

LOGIN GROUP for channel X
  if any of SFTP_HOST_X, SFTP_USER_X, SFTP_PASSWORD_X, SFTP_KEY_X, SFTP_PORT_X is non-empty:
      login source = "channel"
      HOST = SFTP_HOST_X            required → refuse: "SFTP_PORT_X is set but SFTP_HOST_X is empty"
      USER = SFTP_USER_X            required → refuse, same shape
      sign-in: SFTP_KEY_X, else SFTP_PASSWORD_X, else refuse   — NEVER the shared key or password
      PORT = SFTP_PORT_X, else 22   — NEVER the shared SFTP_PORT: that port belongs to the shared host
  else:
      login source = "shared"
      HOST, USER from the shared names; sign-in: SFTP_KEY, else SFTP_PASSWORD, else refuse; PORT = SFTP_PORT, else 22

LOCATION for channel X
  ROOT     = SFTP_PATH_X_ROOT_DIR if non-empty, else SFTP_PATH_ROOT_DIR   — refuse if both empty
  CHAN_DIR = SFTP_PATH_X_DIR                                              — required (unchanged)
  CHANNEL_ROOT = ROOT/CHAN_DIR;  REMOTE_ADMIN = CHANNEL_ROOT/admin_html;  REMOTE_PUBLIC = CHANNEL_ROOT/public_html
```

**The half-set rule, in one sentence: a login group is all or nothing, and the two groups never cross.** If ANY of the
five login overrides for a channel is set, the deploy takes host, user and the sign-in secret from that channel's
overrides and refuses if one of those three is missing. Why: the two half-set shapes that could land files in the wrong
place are "a new host with the shared user and password" (which may sign in if one provider account serves both
servers, and then writes the shared ROOT path onto the wrong server) and "a new user on the shared host" (which writes
into that user's home under the shared ROOT name). Neither can be told from a deliberate choice by looking at the
values, so the rule refuses the shape itself. The design's A6 row said "channel key, channel password, shared key,
shared password, in that order" — a fallback across groups that would have allowed exactly the shape the rule refuses
(challenge C1); the sign-in order above is the only one, stated once here and once in 7.4.

The location override stands alone: a channel may sit in a different base folder under the same login (a customer
whose channels do not share a parent folder), or under its own login. When it is a mistake, the worst outcome is a
stray empty tree on the shared server, and the remote marker probe (amendment §3.5, unchanged) refuses if the target
already belongs to another channel.

### 5.3 The rules (replaces amendment §3.1's block; challenge C2 folded in)

```
ROOT (whichever setting supplied it)
  - trim ONE trailing "/"
  - must start with "/"; must not be "/" alone
  - every segment matches ^[A-Za-z0-9_.-]+$ ; no "." or ".." segment
  - ANY depth: /home/USER, /var/www/vhosts/example.org, /home/customer/www are all accepted
  - REFUSE if the last segment of ROOT equals CHAN_DIR:
      "/home/USER/portal.example.org" + "portal.example.org/" would build
      /home/USER/portal.example.org/portal.example.org — the 11 September value nested inside itself
  - REFUSE if the last segment is admin_html or public_html (case-insensitive): "the root is the folder
      ABOVE the channel folder, and the channel folder is the folder ABOVE the web directory in your panel"

CHAN_DIR = SFTP_PATH_<X>_DIR
  - trim ONE trailing "/"; ^[A-Za-z0-9_-][A-Za-z0-9._-]*$ ; no leading "/", no inner "/", not "." or ".."
  - REFUSE admin_html and public_html (case-insensitive): "SFTP_PATH_LIVE_DIR names a door folder; the
      channel folder is the folder ABOVE the web directory in your panel". Why: the panel's "web directory"
      box shows the door path, so this confusion is likely, and with it the deploy would mirror _sql/ (with
      full_schema.sql) and _core/ INTO the folder the hostname serves; the remote probes would not refuse
      (the door target …/admin_html/admin_html/.door is simply absent), and only the deny files and the
      post-upload probe would stand between the schema and the public — after the upload.

DISTINCTNESS (all three channels are resolved on every run, as before)
  - the resolved triple (HOST, USER, CHANNEL_ROOT) must be pairwise different across LIVE, BETA, ALPHA
  - refuse naming the two channels and the setting to change:
      "BETA and LIVE resolve to the same folder on the same server (SFTP_PATH_BETA_DIR = SFTP_PATH_LIVE_DIR
       under the shared login) — a beta deploy would overwrite the live channel"
  - what this cannot catch: two hostnames that are the same server, or two users that share a home.
    The remote marker probe is the backstop and is unchanged.
```

Why the two-segment rule goes: it "deliberately encodes the DreamHost home folder" (amendment §3.1), and owner answer 4
says nothing may assume the hosting company. The one mistake it existed to catch — the three-segment 11 September value
— is caught by the last-segment rule instead, at any depth.

Every refusal stays `::error::` naming the setting, the rule and an example value, before anything connects. Masking of
built paths is unchanged; the override values are secrets and GitHub masks them wherever they appear (INFERRED, as the
amendment already relies on for the shared ones).

### 5.4 Health-check addresses

Unchanged in substance from amendment §3.10: `ADMIN_BASE_URL_<X>` and `PUBLIC_BASE_URL_<X>` are **variables** (they are
public addresses); empty means `::warning::` and skip that door. Added: each value is trimmed of one trailing slash and
must begin `https://` or `http://`, else `::error::` naming the variable — a bare hostname makes `curl` fail with a
message that says nothing about the cause. The literal at `deploy.yml:421` is gone with the old step. The summary (5.6)
lists which probes ran and which were skipped, so a skipped probe is visible without reading the log.

### 5.5 What changes elsewhere in the workflow

- **A2 (resolve settings)** reads all 27 names through `env:`, applies 5.2 and 5.3 for all three channels, and exports
  the login source and port for the channel being deployed by `$GITHUB_OUTPUT`; the step that needs the host, user or
  secret has all 27 names in its own `env:` and picks by the login source (a secret must never pass through an output).
- **A6 (choose sign-in method, stage key)** stages whichever key applies — `SFTP_KEY_<X>` when the login source is
  `channel`, `SFTP_KEY` when it is `shared` — to `~/.ssh/deploy_key`; one run deploys one channel, so one key.
- **`run_lftp()`** (amendment §3.5) reads the resolved `$SFTP_HOST`, `$SFTP_USER`, `$SFTP_PORT`, `$SFTP_PASS`; the
  function body does not change.
- **The dry run** (amendment §3.9) with a newly added override proves the override's login works, because the remote
  probes connect read-only before anything is uploaded. Adding an override to a running installation: set the secrets,
  dispatch with `dry_run`, read the probe output, then deploy.
- Tree guards, remote probes, mirrors, deny files, `.well-known` — unchanged, except the lists in 7.6.

### 5.6 The summary (replaces amendment §3.11)

Print: channel; for each of host, user, sign-in method, port and root, the NAME of the setting that supplied it and
whether it was `shared` or a `channel override` (for example "host: SFTP_HOST_LIVE (channel override)") — names only,
never values; channel root and door targets (masked); which health probes ran, which were skipped and why; commit. The
never-set `public_dir` (`deploy.yml:435`) is gone. The header comment is rewritten: the channel model, the settings in
one table, the all-or-nothing rule, and no hosting company named as a requirement.

### 5.7 Organisation-level secrets with the new names

DEV_NOTES 3b's warning stands: a repository secret hides an organisation secret of the same name, and deleting the
repository one makes the deploy fall back silently (INFERRED, GitHub's precedence). A partial organisation-level login
group turns into a refusal under the all-or-nothing rule (good); a complete one would deploy to whatever server it
names, and nothing inside a workflow can tell the two apart. The summary's "which name supplied this" line is the
visibility; the dry-run-first habit is the procedure; and one rule goes into DEV_NOTES 3b: **never create
organisation-level secrets with any of the 27 names — they belong to one repository.** Retired names are still warned
about, never refused.

### 5.8 The three hosting arrangements the workflow supports

| Arrangement | Settings used | What separates the channels |
| --- | --- | --- |
| One login, one server, one base folder (our own installation) | shared settings + the three `SFTP_PATH_<X>_DIR` | each channel's own database, encryption key, uploads, backups, settings, users and sessions (amendment F5) — not the filesystem user |
| One server, one login per channel | plus `SFTP_HOST_<X>` (the same host), `SFTP_USER_<X>`, a secret, and usually `SFTP_PATH_<X>_ROOT_DIR` | the above plus the filesystem: one channel's code cannot read another's `_auth_keys/` |
| One server per channel | plus a different `SFTP_HOST_<X>` | everything |

The portal itself never knows which applies: every path it uses is relative to its own root (amendment F1), every
address it uses comes from its own database (section 3), and the marker probes and health probes run per channel in
exactly the same way.

---

## 6. The installation package

### 6.1 One workflow, absorbing `release.yml`

A new workflow `.github/workflows/package.yml`, "Build installation package". **It absorbs `release.yml`**: building the
package and creating the GitHub Release from `CHANGELOG.md` happen in one job, and `release.yml` is deleted. Why: a
Release without the package is of no use to a customer; `release.yml` has failed on every run it ever had and the reason
is lost (survey §7.4); and two workflows firing on one tag would race to create the same Release. Alternative recorded:
keep `release.yml` and have the package job wait for the Release and upload with `--clobber` — more moving parts for no
gain. The new workflow keeps every step's output verbose (`gh --version`, the exact command, the API error) so a failure
reason is not lost a second time (challenge F3).

### 6.2 Triggers, channel, version, kill switch

```yaml
on:
  push:
    tags: ['v*']            # publish: build, then create or update the Release and attach the files
  workflow_dispatch:        # build only: the zip is kept as a workflow artifact; no Release, no tag
concurrency:
  group: package-${{ github.ref_name }}
  cancel-in-progress: false
permissions:
  contents: write           # needed only to create the Release; every other step reads
```

- **Checkout with full history:** `fetch-depth: 0`, then `git fetch origin main beta --tags` (challenge F1) — the
  channel decision and `REMOVED-FILES.txt` both need it (`release.yml:41-43` already sets `fetch-depth: 0` for the
  same reason, PROVEN). The packaged commit is `git rev-parse "$GITHUB_REF_NAME^{commit}"`, which peels an annotated tag
  to the commit it points at (git's syntax — INFERRED on the runner).
- **Which channel word goes into the package.** On a tag push: if the tagged commit is an ancestor of `origin/main`
  (`git merge-base --is-ancestor`) → `live`, and the tag must NOT contain `-beta` or `-rc`; else if an ancestor of
  `origin/beta` → `beta`, and the tag MUST contain `-beta` or `-rc` (the rule `release.yml:60-68` already uses to mark a
  pre-release); else refuse: "tag a commit that is on main or beta". On a manual run: the branch's word (`main` →
  `live`, `beta` → `beta`, `alpha` → `alpha`, anything else → refuse), and nothing is published. **The workflow never
  creates a tag**: tagging stays a human act.
- **The version must agree with the tag.** `web/_core/version.php`'s string must equal the tag with the leading `v` and
  any `-suffix` removed — the same stripping `version-bump.yml:122-123` does — else refuse: "version.php says 1.4.0; the
  tag says v1.5.0. Bump the version first: `main` is bumped by hand, `beta` by version-bump.yml." That is the whole of
  the interaction with the version bump: the bump is upstream, and the package refuses to ship a mislabelled build. One
  trap, for the release checklist (challenge F2): `version-bump.yml` bumps PATCH on every push to `beta` touching
  `web/**`, so a person who sets `version.php` to `1.5.0` on `beta` gets an automatic `1.5.1` commit on top — **tag the
  commit that set the version, not `beta`'s head after the bump.** The known problem that `version-bump.yml` has not
  run on `alpha` since July (`README.md:243-252`) is neither made worse nor better; alpha packages are manual, build-only.
- **Kill switch on publishing only:** `vars.PACKAGE_PUBLISH_ENABLED == 'true'` on the publish step, plus an always-run
  guard step that prints `::notice::` saying whether publishing is on (the iHymns idiom, `apple-dmg.yml:140-166`), so a
  green run with a skipped publish is never taken for a release. The build and its guards always run — they are harmless
  and they are the proof.

### 6.3 No published package before Step 4 has landed (challenge B3), enforced by a guard

Build plan Steps 3 and 4 rename `public_html/` to `admin_html/` and create a NEW `public_html/` that is the public door,
answering 404 to everything until a surface is switched on. A deploy refuses on live while the old `public_html/`
exists (amendment §6 step 12); a merged package has no refusal. A customer who installed from a package built between
Step 2 and Step 4 has their hostname pointed at `public_html/`; merging a post-Step-4 package over it turns that folder
into the public door, and every address — `/login` and `/admin/upgrade` included — answers 404, so the administrator
cannot reach the page that would explain it. The fix on the customer's side is one panel change, but nothing would
tell them.

So: **a tag build refuses unless the packaged tree has both doors** — `web/admin_html/.door` saying `admin` and
`web/public_html/.door` saying `public` (guard G14, section 6.6). Manual, build-only runs skip G14; they exist to prove
the build. `INSTALL.md` therefore describes only the two-door layout: "point your staff address at `admin_html`". The
alternative — a version-specific `UPGRADE-NOTES.txt` shown by the upgrade page — is more moving parts for a boundary
that can simply be avoided, and is recorded here as not taken.

### 6.4 Exactly what goes in, and the layout (challenge B1 folded in)

**Source: `git archive` of the exact commit, never the working tree.** `git archive --format=tar "$COMMIT":web |
tar -x -C "$STAGE"` gives every tracked file under `web/` and nothing else (git's documented behaviour — INFERRED; that
the listing matches the tracked tree today is PROVEN, survey §8.4). Why: on 13 September a stray
`web/_auth_keys/auth_creds.php` sat in a working tree on this machine (HANDOFF); a copy of a working tree would have
shipped it. Anything gitignored — `_auth_keys/`, `_uploads/`, `_backups/`, `_libraries/`, `*.key`, `.env` — cannot be
in the archive by construction.

Then, in the staging folder:

| Action | What | Why |
| --- | --- | --- |
| remove | `private_html/`, `public_html_landing/`, `public_html_redir/` (if still in the repository — OWNER DECISION 1) | Placeholders nothing deploys; one carries our domain. |
| remove | `_includes/`, `_functions/` (if still in the repository — section 10), every `.gitkeep` | Empty; nothing references them; a deployed installation has never had them (section 1). |
| add | `_libraries/dompdf/` via `bash tools/download-dompdf.sh` (pinned by hash, 6.7), then `_libraries/.htaccess` copied from `_core/.htaccess` | Exactly as the deploy fetches it (`deploy.yml:245-247`) and as amendment §3.7 protects it. |
| add | `CHANGELOG.md` at the package root | As the deploy stages it for `/admin/release-notes` (`deploy.yml:251-253`). |
| add | `INSTALL.md` at the root, copied from `docs/INSTALL.md` | The customer guide (8.2). Kept in the repository so it is reviewed like any document. |
| add | `.channel` — one word (6.2) | Section 4. |
| add | `VERSION.txt` — five lines, `Key: value`: `Product: WebMS Intra`, `Version: 1.5.0`, `Channel: live`, `Commit: <short sha>`, `Built: <UTC time>` | "Which package do you have?" is the first support question, and the restore button (4.3) reads the `Channel:` line. Plain text, so no schema is owed. |
| add | `REMOVED-FILES.txt` — see 6.10 for what it holds | The upgrade page reads it (6.10). |
| add (OWNER DECISION 2) | the CDN-fallback files (6.8) | A portal that works behind a network that blocks CDNs. |
| **NOT added** | `_auth_keys/`, `_uploads/`, `_backups/`, or any deny file for them | Challenge B1: with those three folders IN the package, a "replace folder" upload of the whole package — the choice the Finder and some panel file managers offer when a folder of the same name exists (INFERRED, general knowledge) — replaces the customer's `_auth_keys/` with one holding only a deny file, and `enc.key` cannot be recovered (DEV_NOTES 3c). With them OUT, every top-level entry in the package is repository-owned, and replacing any of them wholesale loses only what the package re-supplies. The installer and the upgrade page create the folders and write the deny files instead (6.11). |

**Layout: no wrapper folder.** The zip's root IS the channel root:

```
_apps/  _core/  _install/  _lang/  _sql/  _vendor/  _libraries/
admin_html/  public_html/                 (both doors — a tag build refuses a tree without both, 6.3)
.channel   CHANGELOG.md   INSTALL.md   VERSION.txt   REMOVED-FILES.txt
```

Why no wrapper: the frequent operation is the upgrade, which is "extract into the portal's folder, merging". With a
wrapper (`webms-intra-1.5.0/…`) the new version lands BESIDE the old one, nothing changes, and the portal looks entirely
normal — the quiet failure. Without one, "extract here" is the same act for a first install and for an upgrade. The
cost — a person who unzips on a laptop gets a dozen folders instead of one — is stated in the guide.

**A property worth stating in the guide:** nothing in the package has the same name as anything of the customer's. The
only things of theirs that can live INSIDE a package-owned folder are a `.well-known/` folder inside the web folder
(the deploy protects it, amendment §3.6; a replace-style upload would not) and any hand edit to that folder's
`.htaccess` (challenge B4 — some hosts need an `AddHandler` line for the PHP version, INFERRED). The guide says: put
those back after upgrading.

### 6.5 File names and checksum

`webms-intra-<version>.zip` and `webms-intra-<version>.zip.sha256` for a tag build (a pre-release tag's suffix is in the
version: `webms-intra-1.5.0-beta.1.zip`); `webms-intra-<version>-<channel>-<shortsha>.zip` for a manual build. Always the
generic product name: the brand is chosen at install time (#296), not at build time. Checksum: `sha256sum
webms-intra-<version>.zip > webms-intra-<version>.zip.sha256` — the `sha256sum -c` format, so a customer with a command
line can verify, and the hash is also printed in the Release notes for one without.

### 6.6 The guards — each one fails the build (challenge A1 folded in; G4 inverted; G14 new)

All run against the staging folder before zipping, then G11-G13 against the zip itself. Each prints `::error::` with the
offending path and exits 1.

| # | Guard | Catches |
| --- | --- | --- |
| G1 | **Listing comparison.** Immediately after `git archive` and BEFORE any removal or addition: `find "$STAGE" -type f | sed "s#^$STAGE/##" | sort` must equal `git ls-tree -r --name-only "$COMMIT" -- web/ | sed 's#^web/##' | sort` byte for byte. (The design's timestamp test — "no file predates the archive step" — is true of every archive extraction AND of every copy made afterwards, because git writes the extraction moment into a tree archive; PROVEN on this machine by the challenge. It proves nothing.) | a working-tree copy, an untracked file |
| G2 | **Deny list** over every path in the stage: `^\.claude`, `^\.claude-work`, `^\.dev-team`, `^\.github`, `^\.git(/|$)`, `^\.vscode`, `^\.idea`, `\.env$`, `\.key$`, `\.pem$`, `\.p12$`, `\.log$`, `error_log$`, `auth_creds\.php$`, `enc\.key$`, `\.installed$`, `^_auth_keys(/|$)`, `^_uploads(/|$)`, `^_backups(/|$)`, `\.DS_Store$`, `Thumbs\.db$`, `\.swp$`, `~$`, `\.gitkeep$`, `\.gitignore$`, `\.gitattributes$`, `^tools/`, `^docs/`, `^composer\.json$`, `^(README|DEV_NOTES|FEATURES|CLAUDE)\.md$` | a secret, a planning folder, a development file, a server-managed folder |
| G3 | **Allow list** of top-level entries: `_apps _core _install _lang _sql _vendor _libraries public_html admin_html .channel CHANGELOG.md INSTALL.md VERSION.txt REMOVED-FILES.txt` — anything else refuses with "add it to the package allow list or exclude it" | a new folder nobody decided about (the amendment's tree guard 3, same idea) |
| G4 | `_auth_keys/`, `_uploads/`, `_backups/` do **not** exist in the stage — not even as empty folders | the B1 shape |
| G5 | `gitleaks detect --no-git --source "$STAGE"` (the CLI `pr-security.yml:122-134` installs) | a committed secret of any known shape |
| G6 | `check_no_hardcoded_domains.py --strict --root "$STAGE"` | our own address anywhere in the package |
| G7 | `php -l` on every `.php` outside `_libraries/` and `_vendor/` (the deploy's rule, `deploy.yml:204-208`) | a syntax error |
| G8 | `_libraries/dompdf/VERSION.txt` equals the pin in `download-dompdf.sh` and `src/Autoloader.php` exists | a half-fetched dompdf |
| G9 | `.channel` is exactly the decided word and one of `live`, `beta`, `alpha`; `version.php` agrees with the tag (6.2); on a live tag `INSTALL.md` contains no "pre-release" banner text; `VERSION.txt`'s `Channel:` line equals `.channel` | a mislabelled package |
| G10 | `INSTALL.md` names the web folder that actually exists in the package (`admin_html` when present, else `public_html`) | a guide that describes the wrong layout (matters for manual builds; a tag build always has both doors, G14) |
| G11 | The zip's own listing (`unzip -Z1`) equals the expected listing derived from the SAME `git ls-tree` output minus the removals in 6.4 plus the files the build added (listed from disk right after each addition); no entry begins with `/` or contains `..` | a zip that differs from what was checked |
| G12 | `unzip -t` passes | a corrupt archive |
| G13 | Size between 5 MB and 80 MB | an empty package or a runaway inclusion (`web/` is 16 MB today plus dompdf, survey §8.2) |
| G14 | **Tag builds only:** `admin_html/.door` exists and says `admin`, `public_html/.door` exists and says `public` | a published package from before the two-door layout (6.3) |

**What can never be included, by construction rather than by list:** anything outside `web/` (the archive starts from
`web/` alone), anything untracked, anything gitignored. The lists in G2 to G4 are the second wall behind the first.

### 6.7 dompdf pinned by hash (challenge A2)

`tools/download-dompdf.sh` gains `DOMPDF_SHA256=…` and fails on mismatch — the discipline `Asset.php:71-84` already
applies to CDN files. The `:60-63` "expected autoloader not found" warning becomes `exit 1`: G8 depends on the file, so
the script should refuse rather than warn. Two cautions, both INFERRED: GitHub's auto-generated tag tarballs have not
always been byte-stable over time, so the pin may one day need re-deriving (record why, in the script, when that
happens); and a release asset dompdf itself publishes for the version is stabler if one exists — the builder checks the
release page and pins whichever artefact is used. The deploy has always had this gap; the package turns it into a
distribution, which is why it is closed now.

### 6.8 The CDN-fallback files (OWNER DECISION 2; challenge E3's count corrected)

`Asset.php:129-138` names FOUR local fallback files — `bootstrap.min.css`, `bootstrap.rtl.min.css`,
`bootstrap.bundle.min.js`, `fontawesome-all.min.css` — and carries an SRI hash for each (`:71-84`) (PROVEN today; the
challenge said "five paths" — it counted `.gitignore`'s `webfonts/` folder as a fifth). `.gitignore:41-45` ignores three
of the four plus `assets/webfonts/`; the RTL sheet is neither ignored nor committed. Nothing fetches any of them today,
so on the server they exist only where somebody uploaded them by hand, and `Asset.php` writes them as the `onerror`
fallback into every page (survey §8.3).

A new `tools/download-cdn-fallbacks.sh` fetches the four files from the exact CDN addresses `Asset.php` names and
checks each against the matching `*_INTEGRITY` constant before accepting it — reading the paths and hashes OUT of
`Asset.php` with a small regex over the constants, so the two cannot drift — and fetches the Font Awesome 6.5.1
`webfonts/` files the CSS refers to, each pinned by a SHA-256 in the script. `.gitignore` gains
`web/public_html/assets/css/bootstrap.rtl.min.css`. The package build runs the script after the dompdf fetch; the
deploy does not need to (it never has, and a CDN-blocked customer on the deploy path is us, who are not one).

### 6.9 Where it is published, and for how long

- **Tag build:** the zip and its `.sha256` are attached to the GitHub Release for the tag. `gh release view "$TAG"` →
  exists: `gh release upload "$TAG" <zip> <sha256> --clobber`; else `gh release create "$TAG" --title "WebMS Intra $TAG"
  --notes-file notes.md [--prerelease] <zip> <sha256>`. The `gh` CLI, not a third-party action: it is what `release.yml`
  already calls, and it removes a dependency to pin. Notes: the `## [VERSION]` sections of `CHANGELOG.md` by
  `release.yml`'s regex (`:85-100`), falling back to "Release VERSION" WITH a `::warning::` (`release.yml` falls back
  silently); a footer with the sha256 and "verify with `sha256sum -c`".
- **Every build:** `actions/upload-artifact@v7` (already in use, `pr-security.yml:158-163`), `retention-days: 14` for a
  manual build, 30 for a tag build, `if-no-files-found: error`.
- **Retention:** Release assets stay until somebody deletes the Release (INFERRED); all releases are kept. `-beta`/`-rc`
  tags are marked pre-release so the repository's "Latest" is always a live release (INFERRED, GitHub excludes
  pre-releases from "latest"); `INSTALL.md` says "download the Latest release".
- Visibility follows the repository's (private → collaborators; INFERRED, not checked). A public download page for
  customers is outside this plan; the guide says "your supplier gives you the download link".

### 6.10 How a customer upgrades — and what the upgrade page does with `REMOVED-FILES.txt` (challenge B2 folded in)

1. **Back up:** Admin → Maintenance → Backup, AND copy `_auth_keys/` somewhere outside the portal folder (`enc.key`
   cannot be recovered).
2. **Extract the new zip INTO the portal's folder, merging** — overwrite files, delete nothing. The package carries no
   `auth_creds.php`, `enc.key`, `.installed`, uploads, backups, or even the three folders that hold them (6.4), so
   nothing of the customer's is touched even by a replace-style upload of a package-owned folder. The guide keeps the
   bold warnings anyway: never delete the old folder first; if you changed `.htaccess` in the web folder, apply your
   change again.
3. **Members see the "back shortly" page** — the maintenance gate fires because `portal.installed_version` is now
   behind `PORTAL_VERSION` (`Maintenance.php:144-148`). This depends on `installed_version` having been recorded, which
   the wizard does and the README's "manual configuration" route does not — that route is removed from the README
   (section 8.1; challenge B5).
4. **Sign in as the umbrella administrator → Admin → Upgrade** (`/admin/upgrade`; the holding page's sign-in link leads
   there). The page shows the channel word (4.5) and, above the button, **the leftover-file list**:
   - The build writes `REMOVED-FILES.txt`: every path relative to the package root that ANY earlier published package
     shipped and this one does not — cumulative since the first package tag (`git log --diff-filter=D --name-only
     <first package tag>..$COMMIT -- web/`, `web/` stripped, filtered to paths absent at `$COMMIT`), never the build's
     own removals (6.4). A path is one line; a first line beginning `#` explains the file. Cumulative, because a customer
     who skips a release must still learn what the skipped release removed; the file costs nothing on the customer's
     side because the page checks it against what exists. When no package tag exists yet: one comment line, "first
     package: nothing to remove".
   - `_install/upgrade.php` reads `PORTAL_ROOT/REMOVED-FILES.txt` if present (a deployed installation has none, and the
     page shows nothing), tests each entry with `file_exists()`, and lists the ones still on disk. Entries inside
     `admin_html/` or `public_html/` are shown in red with: "This file or folder was removed in this version. While it
     exists, the portal address of the same name cannot be reached." Why red: `.htaccess:67` and `:71` hand any existing
     file or folder to Apache and never to the portal — the web-root shadowing trap (`#483`, a folder `admin/` made the
     whole Admin area unreachable with nothing in any log). Whole web-root folders were removed from git this month and
     `robots.txt` became a seeded address on 11 September (challenge B2, PROVEN there), so this is not hypothetical.
   - **A "Delete these" button** (CSRF-protected, umbrella administrator only, logged): deletes only paths that are in the
     file, resolve (by `realpath()`) inside `PORTAL_ROOT`, whose first segment is one of the package-owned names
     `_apps _core _install _lang _sql _vendor _libraries admin_html public_html`, that are not symbolic links, and that
     are not `.well-known` or under it. Everything else is skipped with a note. Folders are removed recursively. PHP on
     shared hosting normally runs as the user that owns the files, so the delete will usually succeed (INFERRED); when
     it does not, the list stays and names what to delete by hand.
   - **The upgrade is not blocked** — a customer whose web folder is read-only to PHP must still be able to upgrade —
     but the list stays on the page until the entries are gone, and the dashboard carries a one-line notice for
     administrators while any red entry remains.
   Then run the upgrade: backup, migrations, `installed_version`, gate off (`_install/upgrade.php:85-155`).
5. **A pre-release package over a live portal:** `.channel` becomes `beta`, the gate turns on for members. The upgrade
   page's box (4.5) is where the person finds out; the fix is to extract the live package again.

`REMOVED-FILES.txt` is package-only. The deploy's `--delete` mirrors make it unnecessary there, and the amendment's
`TOP_FILES` list stays `CHANGELOG.md .channel`.

### 6.11 The deny files in a package installation (challenge B1)

The deploy still `put`s the deny file into the three server-managed folders it creates (amendment §3.3 D5, §3.6 —
unchanged). For a package installation:

- **The installer**, at finalize, already creates `_auth_keys/` with mode 0750 when missing (`_install/index.php:940-947`).
  At the same point it creates `_uploads/` and `_backups/` when missing and writes the deny file into all three —
  copied from `INSTALL_ROOT/_core/.htaccess` (the committed copy amendment §3.7 introduces), or the two literal lines
  if that file is absent, so the content has one source. The code already creates its own sub-folders under `_uploads/`
  and creates `_backups/` before writing a snapshot (design §0, `DbBackup.php:157, 172`, PROVEN there), so nothing else
  changes.
- **`_install/upgrade.php`** re-creates any missing deny file in the three folders on every run — one `is_file()` and one
  `file_put_contents()` each — so an installation upgraded from a version before the deny files gets them too.
- Both paths still produce the same installation: the same folders at the channel root, each with the same deny file.
  That is the property that lets one set of documents describe both.

### 6.12 The first run proves the parts that cannot be tested here

Nothing about GitHub Actions, `gh`, `zip`, `unzip`, `gitleaks` on the runner or the Release API was run on this machine.
The order for the builder: (1) manual run from `alpha` with publishing off → read the guard output and the artifact's
listing; (2) in a throwaway branch, plant a fake `web/_auth_keys/auth_creds.php` and commit it with `git add -f`, run,
confirm G2 and G4 refuse; plant a line with our domain in a PHP file, confirm G6 refuses; plant a stray untracked file
in `web/` and confirm G1 does NOT see it (the archive cannot contain it) while a `cp` into the stage would; (3) push a
pre-release tag such as `v1.4.1-rc.1` on `beta` with publishing on → confirm the Release, the two assets, the
pre-release flag, and that `sha256sum -c` passes on a download — this run is also where `release.yml`'s three lost
failures get explained; (4) install from that zip on a scratch hosting account, following `INSTALL.md` literally, and
record every step where the guide and reality differed; (5) upgrade that installation from a second manual build and
confirm the leftover-file list and the delete button behave.

---

## 7. Replacement text for the amendment

Each block below replaces the named section of `public-door-5-channels-amendment.md` in full unless it says "change"
or "add". Everything not named is unchanged.

### 7.1 Section 1 — F2, one paragraph

> **F2. Live's channel folder is the old base.** Under option C, `SFTP_PATH_ROOT_DIR` is the base folder and
> `SFTP_PATH_LIVE_DIR` names live's folder inside it. In our own installation, for example, that is `/home/USER/` and
> `portal.millrdsdacambridge.uk/`, so live's root is `/home/USER/portal.millrdsdacambridge.uk/` — where the old
> workflow put the shared code (`deploy.yml:32-37`, `:192`). Those are one customer's values (ours), not part of the
> design. **What is actually on the server is unknown**; section 6 step 0 is where that is found out.

### 7.2 Section 2 — three small changes

Section 2.1, the "Written by" bullet:

> - **Written by:** the deploy, into `web/.channel` in the checkout, then uploaded with a plain `put` as the first
>   upload of the run (section 3.6); OR the package build, into the same place in its staging folder before zipping, so
>   a package installation carries it (revision §4). Never by hand on a server. The installer checks it is present and
>   refuses to install without it; it never writes one, and never names a channel word in a message. The one thing it
>   offers is a button that copies the word from the package's own `VERSION.txt` (revision §4.3).

Section 2.2, add after "Bootstrap supplies `$fileWord` as the trimmed first line…":

> The reader strips a UTF-8 byte-order mark and a trailing `\r` before comparing, because a file re-created in a
> Windows editor may carry either.

Section 2.6, add one sentence:

> The installer accepts the environment variable as the channel too (revision §4.2), so a local install under
> `PORTAL_ENV=dev` with no file is not refused.

### 7.3 Section 2.4 — add after design A's paragraph

> **Addition (13 September answers):** A is chosen. Two things are added to A so that a copy that has lost its marker
> tells the person who can fix it: a red banner on the admin dashboard when `PORTAL_ENV_SOURCE === 'fallback'`, and the
> installer's prerequisite check that the package's `.channel` arrived (revision §4). Both cost nothing and matter most
> for a package installation, which has no deploy to re-run.

### 7.4 Section 3.1 — replaced in full

> ### 3.1 Settings, how they combine, and the slash rules
>
> **Shared (secrets):** `SFTP_HOST`, `SFTP_USER`, `SFTP_PASSWORD`, `SFTP_KEY`, `SFTP_PORT`, `SFTP_PATH_ROOT_DIR`.
> **Per channel, required (secrets):** `SFTP_PATH_LIVE_DIR`, `SFTP_PATH_BETA_DIR`, `SFTP_PATH_ALPHA_DIR`.
> **Per channel, optional overrides (secrets):** `SFTP_HOST_<X>`, `SFTP_USER_<X>`, `SFTP_PASSWORD_<X>`, `SFTP_KEY_<X>`,
> `SFTP_PORT_<X>` (the login group) and `SFTP_PATH_<X>_ROOT_DIR` (the location), for `<X>` = `LIVE`, `BETA`, `ALPHA`.
> Twenty-seven secret names in all, every one listed in the resolution step's `env:`.
> **Variables:** `SFTP_ENABLED` (the kill switch); `ADMIN_BASE_URL_<X>` and `PUBLIC_BASE_URL_<X>` (probe addresses; empty
> means "skip that door with a warning"; must begin `https://` or `http://`).
> **Warned about, never refused:** `SFTP_LIVE_PATH`, `SFTP_BETA_PATH`, `SFTP_DEV_PATH`, and the six
> `SFTP_PATH_<X>_<ADMIN|PUBLIC>_DIR`.
>
> Hosting is configurable per channel. Our own installation uses one hosting login and one base folder for every
> channel (the shared settings only); another customer may give a channel its own login, its own base folder, or its
> own server. **A login group is all or nothing, and the two groups never cross:** if any of a channel's five login
> overrides is set, its host, user and sign-in secret must all come from the overrides — its key, else its password,
> else refuse; never the shared key or password — and its port comes from `SFTP_PORT_<X>` or defaults to 22, never
> from the shared port. Otherwise everything comes from the shared names. The location override stands alone.
>
> [the resolution block from revision §5.2 and the rules block from §5.3, verbatim]
>
> Every refusal is `::error::` naming the setting, the rule and an example value, and nothing is uploaded before the
> resolution step finishes. Every built path is masked with `::add-mask::`; the override values are secrets and are
> masked by GitHub itself. Dry-run logs show `***` where a folder would be; what they DO show is every file that would
> be uploaded or deleted. Folder identity is proved by the marker checks and the health probe, not by eye.

### 7.5 Section 3.3 — two rows

> | A2 | Trim and validate all 27 values; resolve settings for all three channels (3.1); channel (3.2); apply the all-or-nothing login rule; mask paths | no | yes |
> | A6 | Install lftp; for THIS channel's login source: `channel` → its key, else its password; `shared` → the shared key, else the shared password; stage the chosen key | no | yes |

### 7.6 Section 3.4 — the lists in guard 3, and guard 4

Guard 3's lists become: `DOOR_DIRS = admin_html public_html`; `SHARED_DIRS = _apps _core _install _lang _sql _vendor
_libraries` (`_includes` and `_functions` are deleted from the repository — revision §10); `NOT_DEPLOYED = _auth_keys
_uploads _backups` plus `private_html public_html_landing public_html_redir` only while those exist in the repository
(OWNER DECISION 1); `TOP_FILES = CHANGELOG.md .channel` (unchanged — `VERSION.txt`, `INSTALL.md` and
`REMOVED-FILES.txt` are package-only). Guard 4's sentence about `_includes` and `_functions` holding only `.gitkeep` is
deleted. Section 3.6's D3 loop and section 3.7's list of deny-file copies lose the same two names.

### 7.7 Section 3.10 — add one sentence to the opening paragraph

> Each address is trimmed of one trailing slash and must begin `https://` or `http://`; anything else is `::error::`
> naming the variable. No address is ever written into the workflow file.

### 7.8 Section 3.11 — replaced in full

> ### 3.11 Summary
>
> Print: channel; for host, user, sign-in method, port and root, the NAME of the setting that supplied each and whether
> it was `shared` or a `channel override` — never a value; channel root and both door targets (masked); which health
> probes ran and which were skipped and why; commit. `public_dir` (`deploy.yml:435`), which no step ever set, is gone.
> The header comment (`:7-57`) is rewritten to this section's model: the channel model, the settings table above, the
> all-or-nothing rule, and no hosting company named as a requirement; "whichever branch pushed last wins for shared
> code" is deleted.

### 7.9 New section 3.13

> ### 3.13 The three hosting arrangements the workflow supports
>
> [the table from revision §5.8, verbatim]
>
> The portal itself never knows which applies: every path it uses is relative to its own root (F1), every address it
> uses comes from its own database (revision §3), and the marker probes and health probes run per channel in exactly
> the same way. Adding an override to a running installation: set the secrets, dispatch with `dry_run`, read the probe
> output (it signs in read-only with the new login before anything is uploaded), then deploy. Never create
> organisation-level secrets with any of the 27 names.

### 7.10 Section 5.1 — points 1, 2 and 4

> 1. The deploy creates the channel root, `admin_html/`, and empty `_auth_keys/`, `_uploads/`, `_backups/` (each with a
>    deny file), and writes `.channel`. For a package installation the person extracts the package instead; the
>    installer creates the three folders and writes the deny files at finalize (revision §6.11).
> 2. A hostname is pointed at `<channel root>/admin_html` in the hosting panel.
> 4. The installer asks for the database (step 2) and the portal's web addresses (step 1.7 — revision §3), writes THAT
>    channel's `auth_creds.php`, `enc.key` and `.installed`, records `portal.installed_version` and `site.url` in THAT
>    database. The deploy never writes an address; the installer never writes a channel marker.

### 7.11 Section 5.2 — replaced in full

> ### 5.2 Databases
>
> One database and one database user per channel, created in the hosting panel — for example `webms_live`,
> `webms_beta`, `webms_alpha`, each with its own user and password. The alpha and beta databases start EMPTY: a fresh
> install with a fresh administrator account (OWNER DECISION 7). A database user reaches only the databases it has been
> granted, which is what keeps three channels' data apart under one hosting login — INFERRED for any particular host's
> grants; it is the ordinary arrangement.

### 7.12 Section 5.7 — replaced in full

> ### 5.7 Hosting arrangements — what each does and does not protect
>
> Under one hosting login (ours), each channel has its own database, encryption key, uploads, backups, settings, users
> and sessions, but not its own filesystem user: the same user runs PHP for every hostname, so code on alpha could read
> live's `_auth_keys/auth_creds.php` by absolute path. Nothing does so by accident — every path in the code is relative
> to its own `PORTAL_ROOT` (F1) — but a malicious or badly broken alpha deploy is not stopped by anything, and one SFTP
> login reaches all three folders. A login per channel, or a server per channel, closes that; the deploy supports both
> through the per-channel overrides (3.1, 3.13) and nothing in the portal cares which is in use. OWNER ANSWER 4: hybrid
> and configurable; our own installation stays on one login.

### 7.13 Section 5.8 — three phrases

"registered in the DreamHost panel" → "registered with the sign-in provider"; "Let's Encrypt in the panel needs the
hostname's DNS to resolve to DreamHost first" → "a certificate from the hosting panel needs the hostname's DNS to
resolve to that host first"; "PHP version is chosen per website in the panel" → "…in the hosting panel".

### 7.14 New section 5.9

> ### 5.9 Installing from the package instead of the deploy
>
> A customer without GitHub or SFTP installs from the zip package (revision §6). The result is the same installation:
> the same folders at the channel root, `.channel` carried by the package instead of the deploy, `_auth_keys/`,
> `_uploads/` and `_backups/` created by the installer with the same deny file the deploy would have placed, and the
> same installer asking the same questions. What differs: an upgrade is a merging extraction rather than a `--delete`
> mirror, so files the new version removed remain on disk — and a leftover inside the web folder hides the portal
> address of the same name, which is why the upgrade page lists them from `REMOVED-FILES.txt` and offers to delete
> them (revision §6.10); and there is no health probe — the person opens `/health` themselves. `docs/INSTALL.md` is the
> guide, and it is the ONLY installation document a customer needs. No package is published before Step 4 has landed
> (revision §6.3).

### 7.15 Section 6 — replaced in full

> ## 6. The changeover — for somebody with the hosting panel and an SFTP client (replaces DEV_NOTES 3c)
>
> This section describes OUR OWN changeover. Placeholders: `<live staff address>`, `<alpha staff address>`,
> `<beta staff address>`, `<live folder>`, `<alpha folder>`, `<beta folder>`, `/home/USER/`. In our installation, for
> example, `<live staff address>` is `portal.millrdsdacambridge.uk` and `<live folder>` is the same name; the
> pre-release names are chosen at step 0 (any names; folder = hostname is the tidy convention). None of these values
> appears in any repository file.
>
> Alpha and beta are new folders and cannot collide with anything. Live's channel folder is the old base (F2), so live
> is the one place old and new overlap. The deploy kill switch (`vars.SFTP_ENABLED`) is used so that no real deploy can
> run before its dry run — a merge to `alpha` is a push AND the auto-merge bridge dispatches a second run after it, so
> `[skip ci]` alone would not stop both.
>
> **Step 0 — find out what is there (nothing else first).**
> - Panel → websites: write down EVERY hostname and its web directory. Note which point inside
>   `/home/USER/<live folder>/` (the old `public_html/`, `public_html_beta/`, `public_html_dev/`, `public_html_landing/`,
>   `public_html_redir/` are the likely ones — DEV_NOTES 3b:611 says three web roots are served from it). For each,
>   decide now: re-point later, or delete later. Choose the pre-release hostnames and folder names now; if pre-release
>   hostnames already exist they are re-pointed rather than added.
> - SFTP client → `/home/USER/<live folder>/_auth_keys/`. **Fork:** if it holds `enc.key` or `auth_creds.php`, this is
>   a real installation → copy the whole folder to `/home/USER/auth_keys_backup_<today>/` — OUTSIDE every channel
>   folder — and follow **fork B** at step 9. If missing or empty → **fork A** at step 9.
> - Where is the domain's DNS hosted? If not at the hosting company, every new hostname needs a record added at the
>   DNS host before its certificate can be issued.
>
> **Step 1 — switch the deploy off.** GitHub → Settings → Secrets and variables → Actions → Variables →
> `SFTP_ENABLED` = `false`.
>
> **Step 2 — merge the Step 2 work to `alpha`.** Both the push-triggered run and the bridge's dispatched run are
> skipped at the job level.
>
> **Step 3 — settings.** Secrets: change `SFTP_PATH_ROOT_DIR` to `/home/USER/` (the home folder — NOT the portal's
> folder; the deploy refuses the nested shape, but set it right); add `SFTP_PATH_LIVE_DIR = <live folder>/`,
> `SFTP_PATH_BETA_DIR = <beta folder>/`, `SFTP_PATH_ALPHA_DIR = <alpha folder>/`; delete the six
> `SFTP_PATH_<CHANNEL>_<DOOR>_DIR` secrets. Set NO per-channel override: our channels share one login and one base
> folder. Leave `SFTP_HOST`, `SFTP_USER`, `SFTP_PASSWORD` alone (change, never delete — DEV_NOTES 3b). Variables:
> nothing yet.
>
> **Step 4 — switch the deploy on.** `SFTP_ENABLED` = `true`.
>
> **Alpha (a new folder)**
>
> **Step 5.** Actions → Deploy via SFTP → Run workflow → branch `alpha` → tick `dry_run`. Read the log: the probes
> report the channel root absent; "would create"; nothing to delete; the `.well-known` proof if you planted one (3.6);
> the summary says every login setting came from the shared names. Run again without `dry_run`. The folder now holds
> the marker, the shared code, `admin_html/` and three server-managed folders each holding a deny file. The health
> probe warns that its variable is empty — expected.
>
> **Step 6.** Panel → add (or re-point) the alpha staff website under the same hosting user, web directory
> `/home/USER/<alpha folder>/admin_html`, certificate ticked, PHP version as live. Wait until
> `curl -sI https://<alpha staff address>/health` answers at all (the installer page, 200, HTML). Then GitHub
> Variables → `ADMIN_BASE_URL_ALPHA = https://<alpha staff address>`.
>
> **Step 7.** Panel → databases → create the alpha database and its user.
>
> **Step 8 — in one sitting (5.1 point 5).** Open the alpha address; the installer appears; at "Web addresses" confirm
> the pre-filled `https://<alpha staff address>` and type the support e-mail address; complete it with the alpha
> database; sign in. `curl -s https://<alpha staff address>/health` must show `"env":"alpha"`,
> `"channelSource":"file"` and the version in `web/_core/version.php`. Run Admin → Upgrade if offered. Dispatch the
> workflow once more (no dry run) and confirm the health probe passes.
>
> **Beta** — repeat steps 5-8 for the `beta` branch (promote `alpha` → `beta` first; that push deploys beta for real
> into a NEW, empty folder, which is safe — or use the kill switch again if a dry run is wanted first), `"env":"beta"`.
>
> **Live**
>
> **Step 9, fork A — the old base holds no installation (OWNER DECISION 10: yes).** In the SFTP client delete
> everything inside `/home/USER/<live folder>/` EXCEPT `public_html_landing/`, `public_html_redir/`, `private_html/`,
> `_libraries/` and anything from your step 0 list that another hostname still serves. Live is now a fresh channel:
> kill switch off; promote `beta` → `main`; kill switch on; dispatch from `main` with `dry_run`; read; dispatch for
> real; re-point `<live staff address>` at `/home/USER/<live folder>/admin_html`; create the live database; install
> (confirm `https://<live staff address>` at "Web addresses"); `"env":"prod"`. Set `ADMIN_BASE_URL_LIVE`. No window,
> nothing to delete afterwards. Skip to step 11.
>
> **Step 9, fork B — the old base holds a real installation.** Kill switch off; promote `beta` → `main`; kill switch
> on; dispatch from `main` with `dry_run`. Read with care: uploads INTO `_core/`, `_apps/`, … and INTO a new
> `admin_html/`, deletions only inside those folders; never `public_html/`, `_auth_keys/`, `_uploads/`, `_backups/` or
> the other old folders. Then dispatch for real. **Known window:** until step 10 the OLD `public_html/index.php` runs
> with the NEW `_core/bootstrap.php`, which refuses a front controller that defines no door — a one-line 500 on every
> request. Nothing is live, so this is acceptable; do step 10 straight away.
>
> **Step 10 (fork B).** Panel → `<live staff address>` → web directory → `/home/USER/<live folder>/admin_html`. Sign
> in (live's `_auth_keys/` is untouched). `/health` must say `"env":"prod"`, `"channelSource":"file"`. Set
> `ADMIN_BASE_URL_LIVE`. Go to Settings → Organisation and set the portal's address AND the support e-mail address
> again — an upgraded installation has an empty `site.url` until somebody sets it, and migration 197 blanks an
> unedited support address (ours was unedited); the dashboard reminds you about the first. Then, only now, delete
> `public_html/`, `public_html_beta/` and `public_html_dev/` inside live's folder (OWNER DECISION 9) — after checking
> your step 0 list that no website still points at any of them. Leave `_auth_keys/`, `_uploads/`, `_backups/`,
> `_libraries/`, `private_html/`, `public_html_landing/`, `public_html_redir/`.
>
> **Step 11 — the neighbours.** Fetch every address from your step 0 list that is served from inside live's folder
> and confirm each still answers as before.
>
> **When Step 4 of the build (the public door) lands**
>
> **Step 12.** Deploy each channel again. The workflow creates a fresh `public_html/` carrying `.door` = `public`. On
> live (fork B) it REFUSES while the old `public_html/` still exists — which is why step 10's deletion is not optional.
>
> **Step 13.** Add the public hostname for each channel (a hostname of your choosing; nothing in the code cares
> which), web directory `/home/USER/<channel folder>/public_html`, same user, certificate on; set
> `PUBLIC_BASE_URL_<CHANNEL>`; add the hostname at Admin → Public website → Hostnames. Expect "not found" for
> everything until a surface is switched on.

### 7.16 Section 7 — Steps 2, 4, 5 and 9

**Step 2** gains items 11 to 17 (section 9 here has the same list with the verification):

> 11. `web/_core/PortalAddress.php` (revision §3.6) and `tools/portal-address-selftest.php`.
> 12. Installer step 1.7 "Web addresses" (staff address and support e-mail only until Step 5), the two prerequisite
>     rows, the finalize-time channel re-check, the "restore from VERSION.txt" button, the pre-release box (revision
>     §3.5, §4.2-4.5); the installer creates `_uploads/` and `_backups/` and writes the deny files at finalize (§6.11).
> 13. Migration 197 and its `full_schema.sql` lines: the `site.url` seed, the support-address blanking, the no-op
>     duplicate-key clause; migration 062's two literals and clause (OWNER DECISION 3); `help/support.php`'s empty-state
>     text; `calendar/export.php:119`; the Cloudflare Stream placeholder; the `bootstrap.php:466` comment (revision §2, §3.4).
> 14. `App::baseUrl()`, and — **in the same commit** — the eight `site.url` readers and the four private helpers delegate
>     to it (revision §3.2, §3.3). The wider raw-`HTTP_HOST` sweep is filed as its own issue, e-mail links first.
> 15. The "Web addresses" card on Settings → Organisation and its save-handler lines; the two dashboard notices and the
>     fallback banner (revision §3.8, §4.5); the channel word, the pre-release box, the deny-file re-creation and the
>     `REMOVED-FILES.txt` list with its guarded delete on `_install/upgrade.php` (§6.10, §6.11); the BOM/CRLF tolerance
>     in `Door::validate()` (§4.4).
> 16. `tools/audit-checks/check_no_hardcoded_domains.py`, wired into `pr-security.yml`; proven to fire before the
>     removals (revision §2.2).
> 17. `.github/workflows/package.yml` with `release.yml` deleted, `tools/download-dompdf.sh` pinned by hash,
>     `tools/download-cdn-fallbacks.sh` (OWNER DECISION 2), `docs/INSTALL.md` (revision §6, §8.2). Manual, build-only
>     runs from `alpha` are how it is proved; no tag is pushed until Step 4 has landed.
>
> The `deploy.yml` rewrite (item 7) follows revision §5: the 27 secret names, the all-or-nothing rule, the slash rules,
> the door-name refusal, the summary. **Verification adds:** with `site.url` set, `/health` unchanged; with `site.url`
> EMPTY, an invitation e-mail's link begins with `https://<the host used>/`; with it SET to another host, the link
> begins with the setting; `check_no_hardcoded_domains.py` clean; `grep -rn millrdsdacambridge web .github tools`
> finds nothing; `tools/portal-address-selftest.php` passes; a manual package build passes every guard and the
> throwaway-branch refusals in revision §6.12 (2) fire.

**Step 4** gains one rule:

> No package is published (no `v*` tag is pushed) before this step has landed on the branch being tagged; the package
> build's guard G14 refuses a tag build of a tree without both doors (revision §6.3).

**Step 5** changes its first line:

> ### Step 5 — The public-door migration (the next free number when this step starts — 198 or later; NOT 194, which
> #498 took), the permission model, and `/admin/public`. The installer's "Public website address (optional)" field
> ships in this step, because the table it writes to is created here (revision §3.1, §3.7). `admin/public/host-save.php`
> uses `PortalAddress::hostName()`.

**Step 9** adds one Codex question:

> "Is there any file in the package the build would produce, or any line in `web/`, `.github/` or `tools/`, that names
> our own domain or assumes one hosting login? And is there any upload shape — merge or replace, with or without hidden
> files — in which extracting the package over an installed portal touches `_auth_keys/`, `_uploads/` or `_backups/`?"

### 7.17 OWNER DECISIONS 2, 3 and 4, and the OWNER ANSWERS closing paragraph

> **2 and 3. Addresses — ANSWERED 13 September: none are hard-coded.** Folder and hostname names are chosen by whoever
> does the changeover (section 6 step 0) and set as secrets and variables; the installer asks a customer for theirs
> (revision §3). No repository file names any of them.
>
> **4. Hosting per channel — ANSWERED 13 September: hybrid, configurable.** The deploy supports one login for every
> channel (ours) and a login, base folder or server per channel, through optional overrides (revision §5). Our own
> installation sets no override.

The "OWNER ANSWERS — 13 September 2026" block stays as the record; its closing paragraph ("Until that revision
exists…") becomes: "Revised in `public-door-6-configurable-domains-and-package.md`, which wins over this document
wherever they differ."

---

## 8. Documentation that must change

### 8.1 The list

| Document | Change |
| --- | --- |
| **`docs/INSTALL.md`** (NEW) | The customer guide (8.2). Copied into the package as `INSTALL.md`. |
| `README.md` | "Requirements": PHP 8.4 or newer (the installer checks 8.4+, `_install/index.php:1191-1194`; CLAUDE.md says 8.5 with 8.4 compatibility), the extensions the installer checks (`mysqli`, `sodium`, `json`, `mbstring`) plus the ones it does not but the code needs (`openssl`, `curl` — survey §2.3; either add them to the check or say "not checked by the installer"); MySQL 8.0+ / MariaDB 10.6+ with 8.4 / 11.4 recommended and "MariaDB is not covered by our automated tests" (`DbServer.php`, design §0). "Fresh Installation" and "Upgrading" replaced by two sentences pointing at `docs/INSTALL.md`, with `/admin/upgrade` (never `.php`). **"Manual Configuration" deleted** — it skips the migration replay and never records `installed_version`, so the maintenance gate would never fire on upgrade (survey §11, challenge B5). `:114`, `:225-227` folder names per the amendment §10. |
| `DEV_NOTES.md` | 3b rewritten from section 5 (the 27 names in one table, the all-or-nothing rule, the slash rules, the door-name refusal, the three arrangements, the "no organisation-level secrets" rule, `example.org` names, one labelled "our own installation" paragraph); 3c replaced by 7.15; the release checklist (`:1352-1380`) gains "rename `## [Unreleased] (alpha)` to `## [X.Y.Z] - date (main)`", "tag on `main` — and on `beta`, tag the commit that set the version, not the head after the bump", "watch the package workflow", "open `/health` on the address in `ADMIN_BASE_URL_LIVE`", "download the zip and check `sha256sum -c`"; a new "Installation package" section (what is in it, the guards, the first-run proof, how to prove a change to the build); the fourteen hostname mentions become `portal.example.org`; "dompdf at deploy time" gains "and at package build time, pinned by hash". |
| `.claude/CLAUDE.md` | "Server:" line labelled as our example; the standing rule's "Known offender" sentence removed when `deploy.yml:421` is gone; Directory Layout and Key Constants per the amendment §10; a line "customers install from the zip package built by `package.yml`; `docs/INSTALL.md` is their guide; the package never contains `_auth_keys/`, `_uploads/` or `_backups/`". |
| `.github/workflows/deploy.yml` header | Per 5.6. |
| `.github/workflows/package.yml` header | Purpose, triggers, the guards, the kill switch, "never creates a tag", "no tag build before Step 4". |
| `.github/workflows/release.yml` | Deleted (6.1). |
| `docs/day2-support.md:14` | Per 2.1. |
| `docs/openapi.yaml` | Deleted. |
| `docs/disaster-recovery-runbook.md`, `docs/offsite-backup-setup.md` | DreamHost named as OUR host, labelled ("on our own hosting, for example"), not as the product's requirement. Not rewritten. |
| In-app help `help/admin.php:377-420` | Per the amendment §10, plus: "The portal's own web address is set at Settings → Organisation and is used in every e-mail it sends." |
| In-app help `help/support.php` | The empty-state text (2.1). |
| In-app help `help/admin-first-steps.php` | A first-steps item: "Check the portal's web address at Settings → Organisation — it is used in every e-mail." |
| `_install/upgrade.php` sidebar (`:315-321`) | "Upload the new portal files (extract the package into the portal's folder, merging; or let the deploy do it)"; the channel word; the pre-release box; the leftover-file list. Its two `<table>`s (survey §3.3) are a code-style matter, not touched here. |
| `CHANGELOG.md`, `FEATURES.md` | Entries for #499 and #500. |
| Memory | `webms-channels-fully-separate.md` (amendment §10) gains: no address is built in; the package carries the marker; `site.url` is the portal's address and empty means unset; the package never holds the three server-managed folders. |
| Issues | #500 and #499 updated with this document's file names; a new issue for the raw-`HTTP_HOST` sweep (3.10). |

### 8.2 `docs/INSTALL.md` — the customer guide, outline with the load-bearing sentences

Written for a person with a hosting panel and no command line. Every sentence in ordinary words. Headings and the
sentences that must be in it:

1. **What you are installing.** "WebMS Intra is a staff portal that runs on your own web hosting. This guide is for the
   downloadable package. If your portal is deployed for you from GitHub, you do not need this guide."
2. **Before you start — what you need.** A hosting account with PHP 8.4 or newer; a MySQL 8.0 or newer (or MariaDB
   10.6 or newer) database — "the installer checks the version and tells you if it is too old"; **a web address of its
   own for the portal** (a subdomain such as `portal.yourchurch.org`), "the portal must sit at the root of its own
   address, not in a folder of your main website"; the ability to point that address at a folder of your choosing
   (most panels call this the web directory or document root); a certificate for the address (most panels offer a free
   one).
3. **Download and check the package.** "Download the Latest release — the file is called `webms-intra-<version>.zip`.
   Never install a package marked pre-release for real use." How to check the `.sha256` if they can; if they cannot,
   "the hash is printed on the release page for your supplier to check with you".
4. **The package's layout.** The listing from 6.4, and: "There is no wrapper folder. The contents of the zip ARE the
   portal's folder. Upload or extract them into an empty folder that you create for the portal, for example `portal/`."
   Then: "`admin_html` inside it is the ONLY folder your web address may point at." And: "Nothing in the package has
   the same name as anything the portal creates for you later (`_auth_keys`, `_uploads`, `_backups`), so a later
   upgrade cannot touch your keys, uploads or backups."
5. **Upload.** The primary route (challenge F4): "Extract the zip on your own computer, then upload the CONTENTS of the
   extracted folder into the portal folder — **with hidden files shown**. The package contains files whose names begin
   with a dot; if your file manager or upload program hides them, switch on 'show hidden files' before you upload."
   The alternative where the panel offers it: upload the zip and extract it in the portal folder. "Do not put the
   portal's folder inside your website's public folder."
6. **Point the address.** "In your panel, set the web directory of `portal.yourchurch.org` to the `admin_html` folder
   INSIDE the portal folder — not the portal folder itself. If you point it at the portal folder, your database backups
   and every uploaded file become downloadable by anyone; the portal refuses to run in that state, but get it right
   first."
7. **Create a database.** Name, user, password, host — "write them down; the installer asks for all four".
8. **Run the installer.** "Open your portal address in a browser. The installer starts by itself." The steps in order,
   including "Web addresses: check the address shown is the one people will type; nothing is published by the optional
   public address." "The installer refuses to continue if the hidden files did not arrive — go back to step 5. If only
   the channel file is missing, the installer offers to restore it from `VERSION.txt`."
9. **After installing.** Sign in; Settings → Organisation to check the addresses; set up e-mail sending; the help
   pages inside the portal.
10. **Upgrading.** 6.10 in customer words, with the bold warnings: back up first; copy `_auth_keys` somewhere safe;
    extract INTO the same folder, merging; never delete the folder first; if you changed `.htaccess` in the web folder,
    apply your change again; then Admin → Upgrade — "the upgrade page lists anything from the old version that must
    go; delete it (the page offers to) before you carry on".
11. **If something goes wrong.** The installer says "already installed" → you are re-running it on an installed
    portal; use Admin → Upgrade instead. Members see "back shortly" → an administrator must run Admin → Upgrade.
    "Every page asks for a staff sign-in" → sign in as the administrator and read the red notice on the dashboard; the
    channel file is missing or you installed a pre-release package; what to do. Where to get help: "the support
    address your supplier gave you".
12. **What this guide does not cover.** Scheduled jobs (a separate help page in the portal), moving a portal between
    servers, and running more than one copy (channels) — "that is the deploy's job".

The guide is checked by G6 (no address of ours) and G10 (names the right folder), and its first real test is the
scratch install in 6.12 (4).

---

## 9. Where each piece lands in the build, and how each is verified

| Step of the build plan | What lands there (from this document) | Verified by |
| --- | --- | --- |
| Step 2 (with the deploy rewrite, one commit) | Items 11-17 of 7.16: `PortalAddress`, installer step 1.7 and the prerequisite rows, migration 197 + `full_schema.sql` + 062, `App::baseUrl()` with all twelve callers, the admin card, the notices and banner, the upgrade page's word/box/deny-file/leftover-list, the domain check, `package.yml` (build-only until Step 4), the dompdf pin, the CDN fallback script | 7.16's verification list; `php -l`; every audit script; the migration harness (197 replays as a no-op); the self-test; a manual package build; the throwaway-branch refusals |
| Step 4 | Nothing new; the rule "no tag before this lands" (G14) | A manual build of the post-Step-4 tree passes G14 |
| Step 5 | The installer's public-address field; `tblPublicHosts` row at install; `host-save.php` uses `PortalAddress::hostName()` | A fresh install with a public hostname typed shows one `tblPublicHosts` row; the same hostname typed at `/admin/public/hosts` is refused as a duplicate |
| Step 9 | The documents in 8.1; the two Codex questions in 7.16; the first published package (6.12 steps 3-5) | The scratch install and upgrade from the package |

Order inside Step 2, so that nothing is half-done if the commit is split: (a) the domain check, proven to fire; (b)
migration 197 + `full_schema.sql` + 062 + `App::baseUrl()` + all twelve callers, one commit — never the seed without the
readers; (c) `PortalAddress` + the installer page + the prerequisite rows + the admin card; (d) the upgrade page's
additions; (e) `package.yml` + the two fetch scripts + `INSTALL.md`; (f) the deploy rewrite; (g) the documents.

---

## 10. Design choices made here, not put to the owner

- **`site.url` is reused** rather than a new key (3.1) — eight readers already exist; a new key would mean eight edits
  and a rename in the check. **Empty means unset at every level** (3.3).
- **The public address is a `tblPublicHosts` row, not a setting** (3.9) — one home for one fact.
- **The installer never writes the channel word; the package carries it** (4.1) — fail-closed means nobody guesses.
- **`.channel` keeps its name** (4.6) — `.htaccess` is hidden and load-bearing anyway, so a visible marker removes no
  failure; the challenge's D2 is recorded as considered and not taken.
- **A login group is all or nothing, and the groups never cross** (5.2).
- **The two-segment ROOT rule becomes "last segment must not equal the channel folder, and must not be a door name"**
  (5.3) — the same mistake caught, plus a likely new one, no hosting company assumed.
- **`release.yml` is folded into `package.yml`** (6.1).
- **No wrapper folder in the zip** (6.4).
- **The three server-managed folders are not in the package** (6.4, 6.11).
- **No published package before Step 4**, mechanised by G14 (6.3).
- **The upgrade page offers the leftover-file delete in the first version**, guarded as 6.10 describes — the list alone
  would leave the customer to find and delete web-root shadows by hand, which is the failure that hides.
- **`_includes/` and `_functions/` are deleted from the repository** in the same commit as the deploy rewrite: nothing
  references them (section 1), the deploy has never created them on a server, and the amendment's `SHARED_DIRS`, D3
  loop and deny-file list each lose two entries (7.6).
- **`gh` CLI rather than a third-party Release action** (6.9).
- **The support e-mail is asked at install** (3.5) — one field, and it removes the last reason for a fallback.
- **The primary upload route in the guide is "extract on your own computer, upload the contents with hidden files
  shown"** (8.2, challenge F4) — the panel's extractor is the alternative, because whether the owner's own host's panel
  extracts a zip is unknown.

---

## 11. What was not checked

No GitHub Actions run, no `gh`, `zip`, `unzip`, `gitleaks` or `lftp` invocation, no hosting panel, no server, no file
manager. `git archive`'s timestamp behaviour was proven on this machine (macOS) by the challenge; the runner (Ubuntu) is
INFERRED to match, because it is git that writes the timestamp. Finder and panel file managers' replace-versus-merge
behaviour, GitHub's precedence of repository over organisation secrets, the masking of secret values, the retention of
Release assets, the exclusion of pre-releases from "Latest", the peeling of an annotated tag with `^{commit}` on the
runner, the stability of GitHub's tag tarballs, and whether PHP on the customer's host may delete files in the web
folder are INFERRED. `check_no_hardcoded_domains.py`, `package.yml`, `PortalAddress`, `App::baseUrl()` and the
installer page do not exist yet, so none was run. `docs/INSTALL.md` is an outline here; its full text is the builder's,
tested by a real scratch install. Line numbers for `bootstrap.php`, `full_schema.sql`, `public_html/index.php` and the
modified files under `_apps/` come from a working tree other agents were editing at the time. Whether the dompdf
tarball, the CDN-fallback files or any placeholder folder is present on the live server is unknown.

---

# OWNER DECISIONS

Genuinely new decisions only. Recommendation first, then the alternative, then what each costs. Everything answered on
13 September 2026 stands and is not re-opened. The design raised these three as candidates and the challenge found no
reason to drop any and no fourth.

**1. Delete `web/public_html_redir/`, `web/public_html_landing/` and `web/private_html/` from the repository.**
- **Recommended: yes.** They are placeholders (one holds a `.gitkeep`, two hold a one-page `index.html`); nothing deploys
  or packages them; `public_html_redir/index.html` carries our domain twice; and their copies on the server are untouched
  by their removal from git (the deploy never mirrors `web/` as a whole under the amendment, and the changeover's "leave
  these" lists in 7.15 refer to the SERVER copies, which stay). Cost: none in the portal; the amendment's `NOT_DEPLOYED`
  list shrinks to the three server-managed folders (7.6).
- Alternative: keep them. Cost: the two lines in `public_html_redir/index.html` become `https://portal.example.org/`
  with a comment saying the folder is a placeholder nothing deploys or packages; the package build removes them anyway;
  the domain check must not be given an allow-list entry for them (the example address is not ours, so none is needed).

**2. Ship the CDN-fallback files in the package** (6.8).
- **Recommended: yes**, in the first version if the fetch script stays small, else as the first follow-up. What it buys:
  a portal that renders correctly behind a network that blocks public CDNs — the case `Asset.php:152-163` says the
  product is sold into — without anyone uploading files by hand. Cost: a script that fetches four files (checked
  against the SRI hashes already in `Asset.php`) and the Font Awesome web-font files (pinned in the script); a larger
  zip (a few megabytes, INFERRED); and a build that fails loudly when a CDN is unreachable from the runner — which is
  the right failure, since the alternative is a package that quietly lacks them.
- Alternative: no. Cost: a customer behind a CDN-blocking network gets an unstyled portal until somebody uploads the
  files by hand, and `INSTALL.md` must say so and say which files.

**3. Edit migration 062's two seed literals and its duplicate-key clause** (3.4).
- **Recommended: yes.** It is the only way the repository stops carrying our support address in a file that every
  fresh install replays; migration 197 covers upgraded installations; the migration harness proves the replay; and the
  no-op clause is what makes 197's "unedited row" test hold on the installer's resume path. Cost: a one-off exception to
  "never edit a shipped migration", recorded in 062's header with the reason.
- Alternative: leave 062 as it is. Cost: an allow-list entry in the domain check for that one file; and on every fresh
  install the replay of 062 rewrites `defaultValue` back to our address (its clause is `defaultValue =
  VALUES(defaultValue)`), 197's equality test then fails, and our address sits in that row's `defaultValue` — shown as
  the "default" in the settings editor — on every customer's installation, for good.

---

# OWNER ANSWERS — 14 September 2026

1. **The placeholder folders `web/public_html_redir/`, `web/public_html_landing/` and `web/private_html/`: KEEP THEM.**
   NOT the recommendation; this is the owner's choice. Follow the "Alternative" text of decision 1:
   - the two lines in `public_html_redir/index.html` become `https://portal.example.org/`, with a comment saying the
     folder is a placeholder that nothing deploys or packages;
   - the package build removes them;
   - the domain check gets NO allow-list entry for them, because the example address is not ours.
2. **CDN-fallback copies in the package: YES** (the recommendation). Include them in the first version if the fetch
   script stays small, otherwise as the first follow-up. Check them against the SRI hashes already in `Asset.php`.
3. **Edit migration 062's two seed literals and its duplicate-key clause: YES** (the recommendation). Record the
   one-off exception in 062's header. Migration 197 covers upgraded installations.
