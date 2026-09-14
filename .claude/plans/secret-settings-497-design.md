# Secret settings are never decrypted (#497) — Stage 3: the design

Date: 13 September 2026. Written by Fable 5.1 (stage 3 of the `pkg5-secret-settings` run).
No repository file was changed. I read the working tree as it stood, including other agents' uncommitted
edits, and I re-read every file this design tells a builder to change. I did not run a database in this
stage; stages 1 and 2 did, and their runs are what the test plan below repeats.

How to read the marks: **PROVEN** means I read the code or a stage before me ran it and I checked the
claim against the code myself. **INFERRED** means it follows from what was read but was not run.

This document is saved in two places, identical:
`.claude-work/reviews/pkg5-3-design.md` and `.claude/plans/secret-settings-497-design.md`.

---

## 0. Read this first

### 0.1 This design replaces pkg4 item 2. Nobody changes bootstrap line 397 except as part of this design.

`.claude-work/briefs/pkg4-admin-flags.md` lines 33-38, 70 and 86 tell the pkg4 builder to make the
loader's `isSensitive` comparison type-safe and "change nothing else in bootstrap.php". Stage 2 proved
that that one change, on its own, kills every page: `full_schema.sql` line 1553 seeds
`auth.ms365.tenantOnly = 'true'` with the sensitive flag on, `decrypt_setting('true')` throws, the loader
catches only database errors, and it runs before the error handler exists. PROVEN (stage 2 run B; the
loader is still unchanged in the working tree — `git diff web/_core/bootstrap.php` shows hunks only at
lines 90-135, PROVEN).

**Withdraw pkg4 item 2 in writing** (in `.claude/HANDOFF.md`, and in the pkg4 brief if it is still to be
run). If the pkg4 builder has already changed line 397, that change is replaced by Step 2 below.

### 0.2 Migration number: use 195. There is a clash, and it is with two plans that are not building yet.

- `web/_sql/` holds `000`-`193`; `193_trusted_proxies_and_channel_gate.sql` is present but uncommitted.
  No `194_*` file exists yet. PROVEN (`ls web/_sql/`, `git status`).
- `.claude/HANDOFF.md` line 264 reserves **195 = #497 (this design)**, 194 = #498 (demo data, the
  `maintenance-498` run) and 196 = pkg6b (router and cron). PROVEN.
- **The clash:** `.claude/plans/data-download-479-4-plan.md` says "Migration 194 is free" (line 17) and
  also names "a small migration 195" for its re-proof route (line 445); and
  `.claude/plans/public-door-4-fable-3-corrections.md` line 102 renumbers the public-door migrations to
  194 and 195. PROVEN. Neither of those is being built: #493 step 2 onwards is on hold until the r2
  revision exists (HANDOFF line 214), and #479 is at the plan stage.
- **Decision:** this design uses **195**. #479 and the public door take the next free numbers when they
  actually start (197 onwards, once 196 is used), and each of those plans needs a one-line renumbering
  note when its builder is briefed. Recommendation for the handoff: keep ONE "next free migration
  number" line that every brief reads and increments; three plans choosing 194 independently is how the
  clash arose.

### 0.3 Ordering against work in flight (all PROVEN from `git status` and the handoff)

| In flight | Touches | What this design must do |
| --- | --- | --- |
| pkg4 (Opus, background) | `App.php`, `bootstrap.php` line 397, `settings/save.php`, `settings/index.php`, `admin/settings/group.php`, `Logger.php` | Item 2 withdrawn (0.1). Step 6 (the generic editor) is built on the COMMITTED files after pkg1+pkg4 land as one commit (HANDOFF lines 68-73). |
| fix-pkg1-r3 | `settings/save.php`, `settings/index.php` | Same: Step 6 waits for it. |
| pkg2 (committed? — still `M` in the tree) | `admin/captcha/index.php`, `admin/captcha/save.php` (the global-administrator refusal) | Step 5 is built on top of pkg2's version, after it is committed. |
| maintenance-498 | migration 194, `full_schema.sql`, `admin/maintenance/demo-data.php` | Step 3's `full_schema.sql` hunks must be small and separable (only the lines named in Step 3), so a filtered patch can commit them on their own. |
| fix-forged-r2 | `Logger.php` (never throws, re-entrancy guard on alert mail) | Step 2's logging is wrapped in its own try/catch anyway, so it is safe whether or not that fix has landed. |
| pkg6b | migration 196, `Router.php`, cron moves | No overlap. |

### 0.4 Commit plan

- **Commit A (the fix):** Steps 1, 2, 3, 4, 7, 8, 9, 10. Self-contained. After Commit A every secret
  reaches its reader decrypted, no page can crash on a bad row, and CAPTCHA cannot fail open.
- **Commit B (the two pages):** Steps 5 and 6, after the pkg1/pkg4 commit and pkg2's commit. Until B
  lands, the CAPTCHA page still pre-fills secrets for a global administrator (it does that today, PROVEN)
  and the generic editor still stores an edited secret readable with the flag off (as today, PROVEN); the
  upgrade-time repair in Step 7 puts such rows right at the next Upgrade. **Both commits ship in the same
  release.**

Both commits go through the standing checks (`php -l`, all `tools/audit-checks/check_*.py`, all
`tools/*selftest*.php`, `check_schema_seed_parity.py --strict`, the migration harness) and a Codex
review before commit; while Codex is limited (HANDOFF line 185), an interim Fable review, labelled as
such, with a Codex catch-up owed.

---

## 1. The shape of the fix, in one paragraph

The decision of "what does this stored value mean" moves out of the loader's loop and into one small
new class, `Portal\Core\SecretSettings`, whose job is to turn a stored sensitive value into the value a
reader may use — **never throwing, never handing back encrypted text, peeling our own encryption if it
was applied more than once, and writing down the name of every row it could not read.** The loader
calls it per row. The two global helpers `encrypt_setting()` / `decrypt_setting()` become thin wrappers
over the same class so the helper's behaviour can be tested without a database. A migration and the
fresh-install script remove the one seeded row that was never a secret and correct the flag on five
others. CAPTCHA, the only security control that would otherwise fail open on an unreadable key, is made
to fail closed and say so. The two pages that write secrets wrongly are fixed so the state cannot recur.
An upgrade-time repair encrypts secrets that earlier code stored readable. A committed self-test runs the
real code on every data state stage 2 found, so a regression to `=== '1'` can never again pass every
check.

---

## 2. The exact changes, in order

Standing rules apply to every file: `declare(strict_types=1)`; full `if` notation; `$mysqli` on pages;
no `.php` in any address; comments that say why and what was rejected; file headers with path,
description, package, author, copyright, version. Never put a secret's value in a log line, an error
row, a flash message or a comment — key names only.

### Step 1 — New class `web/_core/SecretSettings.php` (`Portal\Core\SecretSettings`)

A new file, so it cannot collide with any agent's edit. The autoloader is registered at
`bootstrap.php` line 170, before the crypto helpers (line 192) and the loader (line 364), so both may
use the class. PROVEN.

```php
final class SecretSettings
{
    /** How many layers of OUR OWN encryption the loader will peel from one value. */
    public const MAX_UNWRAP = 5;

    // Reasons a stored value could not be turned into a usable one. Key names
    // and one of these words are all that is ever logged — never the value.
    public const REASON_KEY_FILE      = 'key-file-unreadable';   // _auth_keys/enc.key missing or unreadable
    public const REASON_NOT_CIPHERTEXT = 'not-ciphertext';        // not base64, or shorter than nonce+tag: plain text stored with the flag on
    public const REASON_WRONG_KEY     = 'wrong-key-or-corrupt';  // right shape, but the authentication tag fails
    public const REASON_TOO_MANY      = 'wrapped-too-many-times';

    public static function keyPath(): string;            // PORTAL_ROOT/_auth_keys/enc.key unless overridden
    public static function useKeyFile(?string $path): void; // override: the self-test and a future rotation tool
    public static function keyIsReadable(): bool;

    public static function encrypt(string $plain): string;   // exactly today's body; throws RuntimeException if the key file is missing (unchanged)
    public static function decrypt(string $encoded): string; // today's body PLUS a length check; NEVER throws; '' on any failure
    public static function looksLikeCiphertext(string $value): bool; // strict base64 AND decoded length >= 24 + 16

    public static function resolveForLoader(string $key, string $stored): string; // the per-row decision (below)

    public static function failures(): array;    // ['auth.turnstile.secretKey' => 'wrong-key-or-corrupt', …]
    public static function rewrapped(): array;   // ['auth.recaptcha.siteKey' => 3, …]  (layers found)
    public static function isUnreadable(string $key): bool;
    public static function hasProblems(): bool;

    public static function reportProblems(): void;          // Step 2 calls this once per request (see §5)
    public static function repairStoredRows(\mysqli $db): array; // the upgrade-time repair (Step 7)

    public const KNOWN_SECRET_KEYS = [ /* the 47 real secrets from stage 1 §1, listed by name */ ];
    public const KNOWN_NOT_SECRET_KEYS = [
        'auth.google.redirectURI', 'auth.ms365.enduser.redirectURI', 'auth.ms365.appwide.redirectURI',
        'auth.ms365.defaultFrom', 'mail.google.serviceAccountKeyFile',
    ];
}
```

**`decrypt()` — what changes and why.** Today's helper (`bootstrap.php` 235-268, PROVEN) hands the
first 24 decoded bytes to libsodium as the nonce even when fewer than 24 exist, and libsodium throws
`SodiumException`. Stage 2 proved that `'true'`, `'abc'`, `'hello world'` and a 24-character key made of
base64 characters all throw, and that no list of "safe" plain values can be written, because PHP's strict
base64 decoder skips whitespace and the rule is about decoded length. The new body adds one line before
the split: `if (strlen($bin) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) return '';`
— a real ciphertext is always at least 40 bytes (`encrypt('')` is exactly 40, PROVEN stage 2 §5). Wrap
the libsodium call in `try … catch (\Throwable)` as well, returning `''`, so the promise "never throws"
does not rest on that one check. Every existing caller already treats `''` as failure and none relies on
the throw: `Auth::decrypt()` (1037-1052) catches it and returns `''`; `Zoom.php`, `AssetRegister.php`,
`Recordings.php` all test `!== ''`. PROVEN (my own grep of every `decrypt_setting(` caller on
13 September, listed in §7).

Keep a private `decryptWithReason(string $encoded): array{value: string, reason: string}` so the
resolver can record WHY, and let `decrypt()` return only the value.

**`resolveForLoader($key, $stored)` — the per-row decision.** Called only for a row whose flag is 1 and
whose value is neither null nor `''` (the loader keeps skipping empties exactly as today). Inside one
outer `try … catch (\Throwable)` that records `REASON_WRONG_KEY` and returns `''` (belt and braces —
nothing in here should throw):

1. `decryptWithReason($stored)`. A reason ⇒ record `failures[$key] = reason`, return `''`.
2. Peel: while `layers < MAX_UNWRAP` and `looksLikeCiphertext($plain)`: try `decryptWithReason($plain)`;
   if it yields a reason, STOP and keep `$plain` (a genuine secret that merely looks like base64 — a
   64-character hex cron token is 48 decoded bytes, PROVEN stage 2 §5 — fails the authentication tag
   and is kept as it is); otherwise `$plain = inner value; layers++`.
3. If `layers > 1`, record `rewrapped[$key] = layers`.
4. If `layers === MAX_UNWRAP` and `$plain` still verifies as our ciphertext, record `REASON_TOO_MANY`
   and return `''` (defensive; cannot happen from any writer in the tree, which adds one layer per save).
5. Return `$plain`.

Why peeling is safe: a value peels only if libsodium's 16-byte authentication tag verifies under our key,
which happens only for text we encrypted. The chance of a foreign value passing that check is 2⁻¹²⁸.
Stage 2 proved the twice-wrapped case exists on any installation whose global administrator has ever
pressed Save on `/admin/captcha` after entering keys (the page pre-fills ciphertext and re-encrypts it,
PROVEN `captcha/index.php` 86-96, 260, 292, 350; `save.php` 100-142, 199-217), and that without peeling
those installations stay locked out of local sign-in after the loader is fixed. Peeling makes the fix
self-contained; Step 5 stops the layers accumulating; Step 5's keep-path rewrites such a row with one
layer.

**Why a class and not more code in the loop:** the loop cannot be run without a database and a
credentials file, so nothing in CI has ever exercised it (stage 2 §3.8, PROVEN — no workflow runs
`bootstrap.php`). Putting the decision in a class lets `tools/secret-settings-selftest.php` (Step 9)
run the real code with no database, and lets the same code serve the upgrade-time repair and a future
key-rotation tool.

Header comment must record: what `=== '1'` did and why it never matched (prepared statements hand the
column back as the whole number 1 — PROVEN stages 1 and 2 on MySQL 8.0.36 with PHP 8.5); that the
loader has never decrypted a single row in any version in this repository's history (stage 2 §2.1,
PROVEN by `git show 06e7af9`); the three-plus-one data states; why enumeration of "safe" plain values was
rejected; and what the class cannot do — it cannot tell a value encrypted under a different key from
a corrupt one, and it never writes to the database.

### Step 2 — `web/_core/bootstrap.php`: helpers become wrappers; the loop calls the resolver; problems are reported once per request

Three hunks. None overlaps the other agent's uncommitted hunk at lines 90-135 (PROVEN, `git diff`).

**Hunk 1 — section 3 (lines 200-269).** Keep the `function_exists()` guards and both signatures.
Replace the bodies:

```php
function encrypt_setting(string $plain): string
{
    return \Portal\Core\SecretSettings::encrypt($plain);
}
function decrypt_setting(string $encoded): string
{
    return \Portal\Core\SecretSettings::decrypt($encoded);
}
```

Update the doc comments: `decrypt_setting()` now never throws; the reason is in `SecretSettings`.

**Hunk 2 — section 6, lines 396-399.** Replace the four lines with:

```php
// 🔓 Sensitive rows are turned into usable values by SecretSettings.
//    WHAT WAS HERE: `$row['isSensitive'] === '1'`. This row comes from a
//    prepared statement, which hands the flag back as the whole number 1,
//    so the test was false on every row and NO secret was ever decrypted
//    (checked 13 September 2026, MySQL 8.0.36, PHP 8.5). Every reader
//    received the encrypted text. See #497.
//    WHY NOT JUST `(int) … === 1` AND decrypt_setting(): the seed data
//    itself contained a plain-text row with the flag on, and a bad row
//    must never take the page down — this runs before the error handler
//    exists. resolveForLoader() never throws, never returns encrypted
//    text, and records the name of any row it could not read (§5).
if ((int) $row['isSensitive'] === 1 && $value !== null && $value !== '') {
    $value = \Portal\Core\SecretSettings::resolveForLoader((string) $row['settingKey'], (string) $value);
}
```

Non-sensitive rows are untouched, including a `NULL` value (today's behaviour, PROVEN: `assign_setting`
receives the raw value).

**Hunk 3 — a new section 7c, immediately after `\Portal\Core\Site::init(...)` (line 572) and before
the Debug timer (line 581):**

```php
// 🚨 7c. Say so if any secret could not be read (see SecretSettings and #497)
//    Runs AFTER App::init and Site::init so Logger and Site behave normally,
//    and BEFORE the global handlers so nothing here can be the first thing
//    they catch. Wrapped: reporting a problem must never create one.
try {
    if (\Portal\Core\SecretSettings::hasProblems() === true) {
        \Portal\Core\SecretSettings::reportProblems();
    }
} catch (\Throwable $e) {
    error_log('[WebMS-Intra] SecretSettings::reportProblems failed: ' . $e->getMessage());
}
```

What `reportProblems()` does is in §5. Nothing else in bootstrap changes. In particular the outer
`catch (\mysqli_sql_exception)` at line 408 stays exactly as it is: it protects the database read, and
per-row problems are now handled inside the resolver, never by that catch (stage 2 §3.4: a single
try/catch round the whole loop would drop every setting after the first bad row).

### Step 3 — Migration `web/_sql/195_secret_settings_readable.sql` and the matching `full_schema.sql` lines

Header in the 193 style (what, why, what replay does, a note for the next migration), plain English,
`@package`/`@author`/`@copyright`/`@license` lines. Body:

```sql
-- A. auth.ms365.tenantOnly — removed. Nothing reads it (stage 1 §1 and stage 2 §1:
--    a repository-wide search finds it only in SQL; Microsoft sign-in is
--    restricted to one tenant by auth.ms365.tenantID, which is built into the
--    authorise, token and JWKS addresses and checked against the token's
--    issuer — web/_core/Auth.php 376-383, 434-437, 473-477). It was seeded
--    'true' with the sensitive flag on but never encrypted, and the first
--    working loader would have thrown on it. Deleted rather than re-flagged
--    because a DELETE is the one change check_schema_seed_parity.py verifies
--    on BOTH sides (it models delete tombstones), whereas a flag change is
--    invisible to that check. Covers a per-organisation copy too.
DELETE FROM `tblSettings` WHERE `settingKey` = 'auth.ms365.tenantOnly';

-- B. Five rows that were never secrets: three redirect addresses, an email
--    address and a FILE NAME (the file in _auth_keys/ is the secret, not its
--    name). Only rows still holding the empty seed are touched: a value set
--    through the generic editor is already flag 0 and plain; a non-empty
--    value with the flag on could only be ciphertext restored from an old
--    backup, which SQL cannot decrypt — the upgrade-time repair (PHP,
--    SecretSettings::repairStoredRows) handles those.
UPDATE `tblSettings` SET `isSensitive` = 0
WHERE `settingKey` IN (
    'auth.google.redirectURI', 'auth.ms365.enduser.redirectURI',
    'auth.ms365.appwide.redirectURI', 'auth.ms365.defaultFrom',
    'mail.google.serviceAccountKeyFile')
  AND `isSensitive` = 1
  AND (`settingValue` IS NULL OR `settingValue` = '');

-- C. Self-record (safe to run twice — the installer replays every migration)
INSERT INTO `tblMigrations` (`filename`) VALUES ('195_secret_settings_readable.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
```

Both statements replay as no-ops (nothing matches the second time). No DDL, so nothing for
`check_mariadb_only_ddl.py`.

**`full_schema.sql`, exactly three kinds of edit, nothing else** (so the hunks separate cleanly from
the #498 hunks):

1. Delete the three-line seed at lines 1552-1554 (`INSERT … VALUES ('auth.ms365.tenantOnly', 'true', 1, 'true') ON DUPLICATE KEY UPDATE …`). PROVEN location.
2. In the seeds for the five keys in B, change the `isSensitive` value `1` to `0`. (`mail.google.serviceAccountKeyFile` is also inserted by migration 016 with `INSERT IGNORE`, PROVEN stage 1 — on a fresh install the schema's row already exists so 016 does nothing, and 195's UPDATE then matches nothing; on an upgrade 195 flips it. Both paths end flag 0.)
3. Add a self-record line for `195_secret_settings_readable.sql` to the `tblMigrations` seed block, in the same `INSERT … ON DUPLICATE KEY UPDATE filename = filename` form as the line for 193 (line 8695 today), after the last entry (the #498 line for 194 may sit after it by then).

Why not flip the flag on the identifiers too (`*.siteKey`, `*.clientID`, `tenantID`,
`payments.paypal.clientId`)? Encrypting an identifier is harmless, the CAPTCHA page writes site keys
encrypted, and #268 chose to encrypt the PayPal client id on purpose. Flipping a flagged row whose value
is ciphertext would hand ciphertext to a reader — the very fault being fixed — and SQL cannot tell.
Leave them. INFERRED consequence, PROVEN mechanism.

Parity: A is verified both sides by `check_schema_seed_parity.py` (tombstone modelling, PROVEN its
docstring lines 10-13 and `KEY_COLUMNS` line 42). B is invisible to that check, which is why Step 9 adds
`check_sensitive_seeds.py`.

### Step 4 — `web/_core/Captcha.php`: fail closed, and say so, when a key is present but cannot be read

Stage 2 §3.3 proved the fail-open: `activeProvider()` (107-125) picks the first provider whose two keys
are non-empty; an unreadable key becomes `''`; no provider is "configured"; `verify()` (230-256) returns
`true`; every one of the 13 protected forms — sign-in, password reset, anonymous prayer, public forms,
found asset, decision card, visitor form, pledges, live chat, expense submit, event registration —
silently loses its bot check. PROVEN (`grep -rl "Captcha::verify("` = 13 files).

Changes, all inside `Captcha`:

- `private const KEYS = ['auth.turnstile.siteKey', 'auth.turnstile.secretKey', 'auth.recaptcha.siteKey', 'auth.recaptcha.secretKey', 'auth.hcaptcha.siteKey', 'auth.hcaptcha.secretKey'];`
- `public static function unreadableKeys(): array` — those of `KEYS` for which
  `SecretSettings::isUnreadable()` is true.
- `verify()` — first line: `if (self::unreadableKeys() !== []) { return false; }` with a comment
  saying this is deliberate: a stored key that cannot be read means the administrator MEANT there to be
  a check, and a check that quietly disappears is worse than a form that refuses.
- `widget()` — when `unreadableKeys() !== []`, return a visible notice instead of a widget:
  `<div class="alert alert-warning" role="alert">This form cannot be sent at the moment: the portal's
  bot check is set up but its key could not be read. The portal's administrators have been notified.</div>`
  (escaped, no key name — this is public HTML). `scriptTag()` returns `''` in that state (there is no
  provider to load). `isConfigured()` returns `true` in that state (something IS configured; it cannot
  be read) — the builder must read its callers first and confirm none of them would then hide the
  notice. `isConfigured()`, `widget()` and `scriptTag()` are called from 15 files under `web/_apps/`
  (the sign-in, forgot-password and reset-password pages, the anonymous prayer, public form, found-asset,
  decision-card, event-registration, calendar-submit, expense-submit, project-view and live-chat pages).
  PROVEN by grep on 13 September.
- `listProviders()` — a provider with an unreadable key reports `configured => false` and a new
  `unreadable => true`, so the admin page can show a red badge (Step 5).

What this costs, said plainly: if `enc.key` is lost or replaced, or a backup from a different
installation is restored, and CAPTCHA keys are stored, then **local sign-in and password reset refuse
until an administrator acts** — restore the right key file, or blank the six rows with one statement
(§5 gives it, and the runbook will carry it). That state can only arise from an action on the server or
the database, so the person who caused it has the access needed to end it. It is Owner Decision 1.

### Step 5 — `web/_apps/admin/captcha/index.php` and `save.php`: stop pre-filling secrets; "leave blank to keep"; heal a multiply-wrapped row on the next save

Built on pkg2's committed version (the global-administrator refusal is already in `save.php` 63-90,
PROVEN uncommitted).

`index.php`:
- Lines 260, 292, 350: the three secret inputs get `value=""` always. Placeholder: "Leave blank to
  keep the stored key" when `$turnstileSecret !== ''` (and so on), otherwise "Not set". A small badge
  beside each: "Stored" / "Not stored" / red "Stored but cannot be read — enter it again" (from
  `Captcha::listProviders()`'s `unreadable`). Same red badge beside a site key whose name is in
  `Captcha::unreadableKeys()`.
- Site keys (251, 286, 344) keep pre-filling. They are public by design — rendered into every protected
  page's HTML (PROVEN `widget()`) — and after Step 2 the snapshot holds the correct plain value, so a
  re-save writes exactly one layer.
- One checkbox per provider: "Remove this provider's keys" (`clear_turnstile`, `clear_recaptcha`,
  `clear_hcaptcha`). Today a blank box wipes; after this, wiping is explicit.
- The hint under each provider says: keys are encrypted in the database and are never shown again.

`save.php`, the `keys` action (170-229):
- For each SECRET key: if the posted box is blank and the provider's "remove" box is unticked ⇒ skip the
  write (keep). If "remove" is ticked ⇒ write `''` for BOTH of that provider's keys (a provider with a
  site key and no secret is half-configured and never active, so removing both is the honest result).
  Otherwise ⇒ `$upsert()` as today (encrypts once).
- Site keys: as today (the posted value is written; blank wipes), because they are pre-filled.
- **Heal on keep:** when a secret is kept (blank box) and `SecretSettings::rewrapped()` names that key,
  re-write the row once-encrypted from the snapshot's plain value:
  `$upsert($mysqli, $key, (string) App::settings($key), true)`. The snapshot holds the peeled plain
  value (Step 1), so this stores one layer. Log `Logger::activity('CaptchaKeyRewrapRepaired', 'key=' . $key)`.
  This is what turns "the loader copes with the mess" into "the mess goes away on the next save".

### Step 6 — `web/_apps/settings/save.php` and `index.php`: the generic editor keeps a secret encrypted

Built on the committed files after pkg1+pkg4 land (0.3). Stage 1 §2a proved the fault: the edit modal
has no "sensitive" box (PROVEN `index.php` 402-433), so `save.php` line 58 makes `$isSensitive = 0`,
line 68 skips encryption, and the UPDATE at 247-259 writes the plain value AND turns the flag off. Every
Microsoft/Google credential and most cron tokens on a real installation were set this way (INFERRED),
which is why they work today and why they sit readable in the database.

`save.php`:
- The edit branch's lookup (line 264-267) also selects `isSensitive`.
- If the stored flag is 1: the flag stays 1 whatever the form says; a non-empty posted value is
  `encrypt_setting()`-ed; an EMPTY posted value with no `clearValue` tick leaves the row untouched and
  flashes "The stored value was left as it was because the box was empty. Tick 'Clear the stored value'
  if you meant to remove it."; `clearValue` ticked ⇒ value `''`, flag still 1.
- If the stored flag is 0: exactly today's behaviour. (Changing a row's sensitivity is not offered on
  edit; it was never intended to be, and the accidental flip is the whole bug.)
- The Add branch (line 68): encrypt only when `$isSensitive === 1 && $settingVal !== ''`. Today
  `encrypt_setting('')` stores 40 bytes of ciphertext for an empty secret, which makes every "configured
  = non-empty" badge lie about it (stage 1 §5.5, PROVEN mechanism).
- Comment what was here before and why it was wrong, in the house style already used in this file.

`index.php`:
- The edit modal gains a checkbox `clearValue` inside a `.portal-sensitive-only` block that the existing
  `show.bs.modal` handler (474-487) shows only when `data-sensitive === '1'`. The hint text (421-423)
  becomes: "This value is stored encrypted and is never shown here. Type a new value to replace it, or
  leave the box empty to keep the stored one."
- The reveal button for a global administrator (353-360) keeps showing the raw stored text; change its
  title to "Show the stored (encrypted) text" so nobody expects the readable value. Decrypting for
  display is a separate feature and is not added here.

### Step 7 — `web/_install/upgrade.php`: repair stored rows once, right after the migrations run

Stage 2 §4 proved that SQL cannot do this (no libsodium in SQL; the installer replays every migration
before `enc.key` exists — `_install/index.php` 729-762 then 976-983) and that it must not run before
Step 6 (or the next edit reverts it). `upgrade.php` bootstraps first (line 29) and runs
`$migrator->runAll($userId)` at line 122. PROVEN.

Immediately after `runAll()` returns, in the same branch, inside its own `try … catch (\Throwable)`:

```php
$secretRepair = \Portal\Core\SecretSettings::repairStoredRows($mysqli);
```

`repairStoredRows()`:
- Refuses (returns a report saying so, does nothing) when `keyIsReadable()` is false.
- For every row (any `siteID`) whose `settingKey` is in `KNOWN_SECRET_KEYS`:
  - flag 0 and non-empty value ⇒ `encrypt()`, set flag 1. ("Stored readable by the generic editor; now encrypted.")
  - flag 1, non-empty, `decryptWithReason` says `not-ciphertext` ⇒ it is certainly not our format (shorter
    than 40 decoded bytes or not base64), so it is plain text: `encrypt()` in place. (The old QR page and an
    old backup produce this state.)
  - flag 1 and `wrong-key-or-corrupt` ⇒ leave it, report it by name ("cannot be read; enter it again on its page").
  - flag 1 and readable ⇒ nothing.
- For every row whose key is in `KNOWN_NOT_SECRET_KEYS` with flag 1: empty ⇒ flag 0; decrypts under our
  key ⇒ store the plain value, flag 0; `not-ciphertext` ⇒ already plain, flag 0; `wrong-key` ⇒ leave,
  report.
- Every write is a prepared `UPDATE … WHERE settingID = ?`. Returns `['encrypted' => [keys], 'unflagged' => [keys], 'unreadable' => [keys], 'skipped' => reason|null]`.
- Idempotent: a second run finds nothing.
- The upgrade page prints the report (key names only) under the migration results, and
  `Logger::activity('SecretSettingsRepaired', …)` records the counts and names.

Because 195 is a pending migration on this release, the Upgrade button will be pressed and the repair
will run on every existing installation. It is Owner Decision 2 (recommended: run it).

### Step 8 — Show the problem to administrators: `web/_apps/admin/index.php` and `web/_apps/admin/maintenance/health.php`

`admin/index.php`, directly under the `<h1>` at line ~148 (nobody else is editing this file, PROVEN
`git status`):
- If `SecretSettings::failures() !== []`: `alert alert-danger`. Text: "Some stored secrets could not be
  read: {key names}. Until this is fixed, the features that use them will not work, and the bot check on
  public forms and sign-in will refuse. Usual causes: the encryption key file `_auth_keys/enc.key` was
  replaced or is missing, or a database backup from a different installation was restored. Fix: put the
  original key file back, or enter each value again on its settings page." Link to `/admin/maintenance/health`.
- If `SecretSettings::rewrapped() !== []`: `alert alert-warning`: "These keys were saved encrypted more
  than once by an earlier version of the CAPTCHA page: {names}. The portal reads them correctly. Open
  Admin → Captcha and press Save once to tidy them."

`health.php`: a probe `$probes['Stored secrets']` in the existing shape (`state` / `label` / `detail`,
PROVEN lines 102-198): `crit` + "Cannot be read" + names when there are failures; `warn` + "Encrypted
more than once" + names when only rewraps; `ok` + "Readable" otherwise. Cron mode returns it as JSON
automatically.

### Step 9 — Proof that runs: `tools/secret-settings-selftest.php` and `tools/audit-checks/check_sensitive_seeds.py`

**`tools/secret-settings-selftest.php`** — same shape as `tools/webpush-selftest.php` (no database, no
bootstrap, exit 0/1, `assertTrue` helper; PROVEN its header). It `require`s
`web/_core/SecretSettings.php` only, writes a throwaway key file in `sys_get_temp_dir()`, calls
`SecretSettings::useKeyFile()`, and asserts, with the real code:

1. encrypt → decrypt round-trips.
2. `decrypt()` returns `''` and does NOT throw for `'true'`, `'abc'`, `'hello world'`,
   `'ABCDEFGHIJKLMNOPQRSTUVWX'`, `'0x4AAAAAAABkMYinukE8nzYq'`, `'false'`, `'1'`, `'0'` (the stage 2 table).
3. `resolveForLoader('k', 'true')` is `''` and `failures()['k'] === 'not-ciphertext'`.
4. Ciphertext made under a second key ⇒ `''`, reason `wrong-key-or-corrupt`.
5. `encrypt(encrypt('s3cret'))` ⇒ `'s3cret'`, `rewrapped()['k'] === 2`; three layers ⇒ 3.
6. `MAX_UNWRAP + 1` layers ⇒ `''`, reason `wrapped-too-many-times`.
7. A 64-hex plaintext encrypted once ⇒ that hex exactly, and `rewrapped()` is empty (the peel stops on a
   failed tag).
8. `encrypt('')` ⇒ `''` with no failure recorded.
9. `useKeyFile('/nonexistent')` ⇒ `resolveForLoader` gives `''` with reason `key-file-unreadable`;
   `encrypt()` throws `RuntimeException` (unchanged contract).
10. `hasProblems()` false on a fresh static state; the recorders are reset between cases (give the class
    a `resetForTests()` or run each case in a fresh process — the builder chooses and says which).

Optional `--bootstrap=/path/to/scratch/web/_core/bootstrap.php`: requires that file (a SCRATCH copy of
`web/` with its own `_auth_keys/auth_creds.php` pointing at the harness container — never the real
tree, HANDOFF lines 100-102) and asserts on the resulting `$SETTINGS` for a set of rows the test has
just written. This is the only automated path through the real loop; the test plan (§7) uses it.

**`tools/audit-checks/check_sensitive_seeds.py`** — imports `split_statements`/`split_tuples` from
`check_schema_seed_parity.py`, walks every `INSERT INTO tblSettings` in `full_schema.sql` and every
numbered migration, and fails on any tuple with `isSensitive = 1` and a non-empty `settingValue`, and on
any `ON DUPLICATE KEY UPDATE` that sets `settingValue` for a sensitive key. Message: "a seed cannot be
encrypted, so a sensitive seed must be empty". Wire it into `pr-security.yml` like the others (line
~398 pattern). Prove it fires: run it against the current `full_schema.sql` BEFORE Step 3 and confirm it
reports `auth.ms365.tenantOnly`; then after.

### Step 10 — Documentation, records and follow-ups

- **`.claude/HANDOFF.md`:** pkg4 item 2 withdrawn (0.1); 195 used; the 194/195 clash and the "one
  next-free-number line" recommendation (0.2); commit plan (0.4).
- **`DEV_NOTES.md`:** a new section "Secret settings — how they are stored, read and repaired" (the
  three-plus-one states, the resolver, what `''` means, the repair, the self-test). Correct the eleven
  statements stage 2 found that say a token "seeded empty, `isSensitive = 1`, encrypted at rest" while
  pointing administrators at the generic editor, which stored it readable until Step 6: lines 3212-3213,
  3243, 3335-3336, 3640, 4021, 4348, 4463-4464, 4504, 4690 (PROVEN line numbers on the working tree).
  After Step 6 the sentence becomes true; say from which version.
- **`docs/disaster-recovery-runbook.md` §4.4 step 3 (lines 143-152):** `web/_install/rotate-enc-key.php`
  does not exist (PROVEN `ls web/_install/`). Replace with the honest procedure until a tool exists:
  generate the new key file; then enter every secret again through its own page (list the pages); the
  generic editor keeps a secret encrypted (from this release). Add the CAPTCHA recovery statement
  (§5). Note that the installer writes 64 hex characters while the runbook shows `openssl rand -base64
  64` — both work because the helpers hash the file's contents (PROVEN `bootstrap.php` 217, 258). State
  what depends on `enc.key` so a rotation's blast radius is known: settings secrets, two-factor secrets,
  Zoom tokens, asset licence keys, the podcast token, the address-hash salt (stage 2 §3.5, PROVEN by grep).
- **`web/_apps/help/admin.php` lines 127 and 141:** add that editing a sensitive setting keeps it
  encrypted and never shows it. **`help/disaster-recovery.php` line 89:** matches the runbook change.
- **`.github/pull_request_template.md` line 63:** "set `isSensitive=1` **and** write the value through
  `encrypt_setting()` — the flag alone does not encrypt anything; a plain value with the flag on is
  unreadable."
- **`web/_apps/venues/settings.php` header (lines 10-26):** the "ON DUPLICATE KEY UPDATE never fires for
  a NULL siteID" note is stale since migration 187's `uq_setting_key_scope` (PROVEN `full_schema.sql`
  98-102; stage 2 run 5). Its other claim — "the loader calls `decrypt_setting()` on every
  `isSensitive = 1` row" — becomes true with this change; say so and reference `SecretSettings`.
- **`CHANGELOG.md` [Unreleased]:** a plain-English entry in the house style; **`FEATURES.md`** only if it
  describes encrypted settings (grep first).
- **Issue #497:** comment with the commit references; **new issue:** "Encryption key rotation tool" with
  stage 2 §3.5's table list; **new issue** (small): "`/settings` reveal shows encrypted text for a global
  administrator — decide whether to decrypt for display".
- **Migration 059's header** says a flag-on plain value "makes the loader try to decrypt". That never
  happened (stage 2 §2.1). Do not edit a shipped migration; note it in DEV_NOTES.

---

## 3. What the loader does with a sensitive row that will not decrypt — and what `''` means for each key

Rule: **the reader always gets either the readable value or `''` — never encrypted text, never an
exception.** `''` is what every reader already treats as "not set" (stage 1 §3, PROVEN). Which
reason applies is recorded by name (§5). The table is the 57 seeded keys plus the one runtime key, grouped
by what `''` does. INFERRED for provider-side behaviour; PROVEN for the code paths named.

| Keys | What `''` does | Safe? |
| --- | --- | --- |
| `assets/discipleship/giving/push/user_reminders/venues/webhooks/workflows.cron_token`, `maintenance.cronToken` | every cron and the three maintenance job modes answer 403 on an empty token (`hash_equals` against `''` never true; explicit empty ⇒ 403 in each) | **Fails closed** — correct |
| `payments.stripe.webhookSecret` | `Payments` 361-364 returns false: every webhook rejected | Fails closed |
| `payments.paypal.secret`, `payments.paypal.clientId` | no PayPal token (513): checkout and webhooks stop | Fails closed |
| `zoom.webhookSecret` | `zoom/webhook.php` 33 answers 503 | Fails closed |
| `zoom.clientSecret` | OAuth connect and token refresh fail; logged | Stops, visibly |
| `payments.stripe.secret` | checkout returns nothing; logged | Stops, visibly |
| `auth.ms365.enduser.clientSecret`, `auth.google.clientSecret` | the provider button still shows (`clientID` non-empty), the token exchange fails on return with an error page | Stops, visibly |
| `auth.ms365.appwide.clientSecret` | Graph mail fails; `tblEmailLog` and `Logger` record it | Stops, visibly |
| `auth.*.clientID`, `auth.ms365.tenantID`, `auth.*.redirectURI` (identifiers/addresses, now flag 0 for the URIs) | provider counts as not configured: button hidden / login fails | Stops, visibly |
| `sms.*`, `transcription.*`, `translation.*` (unreachable anyway, #485), `ai_assist.*`, `w3w.apiKey`, `hymns.remote.apiKey`, `cfstream.*`, `monitoring.sentryDsn`, `portal.qr.cuercode.api_key`, `newsletter.mailermatt.apiKey` (no reader), `payments.gocardless.*` (badge only) | the feature stops or its "configured" badge goes grey; nothing else breaks | Stops, visibly or silently — the §5 warning is what makes it visible |
| `push.vapidPrivateKey` | `WebPush::isConfigured()` false ⇒ subscribe button hidden, no sends | Stops, visibly |
| `geo.google.apiKey` | Google skipped, Nominatim used | Degrades (a cost, not a security matter) |
| `mail.google.serviceAccountKeyFile` (now flag 0) | Google mail not configured | Stops, visibly |
| **`auth.{turnstile,recaptcha,hcaptcha}.{siteKey,secretKey}`** | **without Step 4: every protected form silently loses its bot check. With Step 4: `verify()` returns false and the widget area shows the notice** | **Fails closed after Step 4** |
| `auth.ms365.tenantOnly` | deleted; no reader | n/a |
| `recordings.podcast_token` (site-scoped, runtime) | `Recordings::podcastToken()` reads the row itself with the correct `(int) === 1` test and regenerates on `''` — unchanged in both directions (PROVEN 213-268); the loader's copy in `$SETTINGS` is read by nothing | Unaffected |

So exactly one key family can quietly turn a security control off, and Step 4 closes it. Every other
`''` either refuses (good) or stops a feature in a way the administrator will see, and §5 names the row.

---

## 4. Plain-text secrets already stored, and how double decryption is avoided

**The four data states** (stage 1 §0.8 plus stage 2 §3.2), what the loader delivers, and what puts the
row right:

| State | Where it comes from | Loader delivers (Steps 1-2) | Put right by |
| --- | --- | --- | --- |
| Flag 1, ciphertext, one layer | the 13 dedicated pages, venues regenerate, the QR fix, podcast token | the plain value | nothing needed |
| Flag 0, plain | the generic editor's edit path (Microsoft, Google, most cron tokens) | the plain value, as today | Step 7 encrypts and re-flags; Step 6 stops it recurring |
| Flag 1, plain | `tenantOnly` seed; an old QR key; an old backup | `''` + failure `not-ciphertext` (never a throw) | `tenantOnly`: Step 3 deletes it. Others: Step 7 encrypts in place (a value shorter than 40 decoded bytes or not base64 is certainly not our ciphertext); a plain value ≥ 40 base64 bytes is indistinguishable from foreign ciphertext and is reported for re-entry |
| Flag 1, ciphertext wrapped N times | `/admin/captcha` re-saves (PROVEN) | the plain value, `rewrapped[key] = N` | Step 5 heals on the next save; Step 5 stops it recurring |
| Flag 1, ciphertext under another key / corrupt | rotated or missing `enc.key`; a backup from another installation | `''` + failure `wrong-key-or-corrupt` or `key-file-unreadable` | restore the key file, or re-enter on the page (§5 text) |

**Double decryption cannot happen.** Every reader takes the snapshot (`$SETTINGS`, `App::settings()`,
`Settings::get()`); none calls `decrypt_setting()` on a snapshot value. The only settings reader that
decrypts itself is `Recordings::podcastToken()`, from its own SELECT, never from the snapshot. `WebPush`
explicitly relies on the loader (comments 101-106, 568-570). `App::settingForSite()` never reads a
secret (52 distinct literal keys, none sensitive). PROVEN (stage 1 §3, stage 2 §1; my grep of every
`decrypt_setting(` caller on 13 September: `Auth.php` 1047, `Zoom.php` 101 and 112,
`Recordings.php` 236, `AssetRegister.php` 6133 — five calls, all on their own tables or their own
SELECT; every other match is a comment).

**Double encryption is stopped at the only place it happens** (Step 5) and its existing damage is
undone by the loader (Step 1 peel) and tidied by the next save (Step 5 heal).

---

## 5. What is logged, and where

Principle: a key NAME and a reason word, never a value, never a fragment of a value.

1. **The web server's error log**, every request that has a problem: one `error_log()` line from
   `SecretSettings::reportProblems()`: `[WebMS-Intra] secret settings unreadable: auth.turnstile.secretKey (wrong-key-or-corrupt), …`.
   Cheap, and the only record that survives a broken database. On shared hosting administrators rarely
   see it (INFERRED), which is why it is not the only record.
2. **`tblErrors`, through `Logger::errorPlatformForSite(null, 'Settings', 'Critical', 'SECRET_SETTING_UNREADABLE', title, detail)`**
   — `null` because a portal-wide row belongs to no organisation and that method exists for exactly
   this (PROVEN 291-301). Severity `Critical` so the existing alert email fires
   (`portal.alerts.severities` defaults to `Critical,Fatal`, PROVEN line 398), with the existing
   cooldown. **Throttled** so the table cannot fill while broken: before writing, one prepared
   `SELECT 1 FROM tblErrors WHERE errorCode = ? AND createdAt > (NOW() - INTERVAL 6 HOUR) LIMIT 1`
   (`createdAt` PROVEN `full_schema.sql` 1095); a hit skips the write. This query runs only when
   there IS a problem, so a healthy installation pays nothing. Rewraps go in the same way as
   `Warning` / `SECRET_SETTING_REWRAPPED` (no alert email by default). The detail text carries the
   recovery: "Restore `_auth_keys/enc.key` from your own backup, or enter the value again on its
   settings page. To regain local sign-in when CAPTCHA keys cannot be read:
   `UPDATE tblSettings SET settingValue = '' WHERE settingKey IN ('auth.turnstile.siteKey','auth.turnstile.secretKey','auth.recaptcha.siteKey','auth.recaptcha.secretKey','auth.hcaptcha.siteKey','auth.hcaptcha.secretKey');`
   then enter the keys again on Admin → Captcha."
3. **The admin dashboard banner and the health probe** (Step 8) — read from the in-memory recorders,
   so they show the truth for THIS request with no database write.
4. **`Logger::activity()`** on the two repairs: `SecretSettingsRepaired` (Step 7) and
   `CaptchaKeyRewrapRepaired` (Step 5), names and counts only.

Why not a marker file like Logger's alert cooldown (`_backups/.alert-<hash>`, PROVEN 420-430): if that
folder is missing or unwritable the sentinel silently fails and the log floods, or, done the other way,
the warning is silenced. A row lookup has neither failure mode.

Timing: `reportProblems()` runs after `Site::init()` and before the global handlers (Step 2 hunk 3),
inside its own try/catch. `Logger::db()` takes the global `$mysqli` (PROVEN lines 35-42), so it works
there.

---

## 6. Alternatives rejected, and why

| Alternative | Why not |
| --- | --- |
| Fix only the comparison (pkg4 item 2) | Kills every page on the seed data (stage 2 run B). |
| Fix the comparison and let migration 195 fix the row | Code reaches the server by SFTP before the Upgrade button is pressed, and `upgrade.php` bootstraps before running migrations (line 29 then 122), so the new loader always meets the bad row at least once — on the request that would repair it. |
| Make the loader throw or exit on a row it cannot read | Takes down the upgrade page and the maintenance holding page along with everything else; the operator loses the very tools needed to fix it. |
| Return `''` and move on, nothing else | Correct for every key except CAPTCHA, where it is a silent fail-open (§3), and an administrator would never learn which row is broken. |
| One try/catch round the whole loop | The first bad row would stop the loop and leave every setting after it unloaded — a different way to break every page (stage 2 §3.4). |
| Enumerate "safe" plain values and skip them | Impossible: whether a value throws depends on decoded length, and PHP's strict decoder skips whitespace (stage 2 §2.2). |
| Refuse multiply-wrapped values instead of peeling | Leaves every installation that ever re-saved the CAPTCHA page locked out of local sign-in until a database edit (stage 2 §3.2); peeling is safe because only our own ciphertext passes the authentication tag. |
| Add a marker to ciphertext so plain text can be told apart | Changes the helper's output format, which reaches TOTP secrets, Zoom tokens, asset licence keys and the podcast token too (stage 2 §3.5); a read-only marker rule would still need the length test this design uses. |
| Re-encrypt plain rows in SQL | SQL has no libsodium, and the installer replays migrations before the key exists. |
| Re-flag `tenantOnly` to 0 instead of deleting | Nothing reads it; a flag change is invisible to the parity check; a delete is verified on both sides. |
| Flip the flag on the identifiers too | SQL cannot decrypt a flagged row that already holds ciphertext; flipping would hand ciphertext to a reader. |
| Fail CAPTCHA closed on public forms only, open on sign-in | Needs every `verify()` caller to say which form it is (13 files) and leaves the one door a bot most wants open; the lockout it avoids is recoverable in one statement by the person who caused it. Kept as Owner Decision 1, option B. |
| Log every request's failure to `tblErrors` unthrottled | Fills the table while broken. |
| Let the loader write the repaired row back | The loader runs on every request including public ones; a read path that writes races with itself and hides the repair from the audit trail. Repairs happen only on the Upgrade page and the CAPTCHA save. |
| Build the key-rotation tool in this change | A different piece of work with its own blast radius across six tables; filed as an issue. |

---

## 7. Test plan against a real database

All against a throwaway `mysql:8.0.36` — the harness container is the easiest
(`tools/e2e-migrations/run.sh --keep`, port 33069, user `portal`, password `e2e-portal`, database
`portal_e2e`; PROVEN `docker-compose.yml` 11-16) — and PHP 8.5 with libsodium (PROVEN locally:
8.5.10, sodium loaded). **Never create `web/_auth_keys/` in the real tree**: make a scratch copy of
`web/` under the scratchpad, give IT an `_auth_keys/auth_creds.php` and `enc.key`, and require THAT
copy's `bootstrap.php` (HANDOFF lines 100-102). Remove the container afterwards.

**A. Mechanical, before anything else**
1. `php -l` on every touched file. All fifteen `tools/audit-checks/check_*.py` exit 0.
   `check_schema_seed_parity.py --strict` exit 0 (the tombstone models the delete).
2. `check_sensitive_seeds.py` FAILS on the pre-change `full_schema.sql` (names `tenantOnly`) and passes
   after — proof that the new check fires.
3. `tools/secret-settings-selftest.php` passes all ten cases; every other `tools/*selftest*.php` still
   exits 0.
4. `tools/e2e-migrations/run.sh`: all four phases clean with 195 in place (fresh schema + replay + second
   replay + Migrator chain).

**B. Fresh install**
5. Load the new `full_schema.sql`, replay every numbered migration in filename order (the installer's
   loop). Assert: zero rows `WHERE settingKey = 'auth.ms365.tenantOnly'`; the five Step 3 B keys have
   `isSensitive = 0`; every remaining `isSensitive = 1` row is empty; 56 such rows (57 − tenantOnly).
6. Bootstrap the scratch copy against it: `$SETTINGS` loads; `SecretSettings::hasProblems()` is false;
   nothing written to `tblErrors`.

**C. Upgrade from a database seeded by the CURRENT schema (the real customer path)**
7. Load the OLD `full_schema.sql` (git `HEAD` version) + old migrations 000-193. Write, through the real
   pages' code or equivalent SQL, one row of each state: an encrypted `payments.stripe.secret`; a plain
   flag-0 `auth.ms365.enduser.clientSecret` and `giving.cron_token`; a plain flag-1
   `portal.qr.cuercode.api_key` of 20 characters; a twice-wrapped `auth.turnstile.secretKey` with a
   once-wrapped `auth.turnstile.siteKey`; a `cfstream.apiToken` encrypted under a DIFFERENT key; leave
   `tenantOnly` as seeded.
8. Deploy the NEW code (scratch copy) WITHOUT running 195 — the SFTP-before-Upgrade window. Bootstrap:
   **no exception**; `$SETTINGS['auth']['ms365']['tenantOnly']` is `''` with failure `not-ciphertext`;
   Stripe secret readable; MS365 secret and cron token readable (plain, as before); QR key `''` with
   `not-ciphertext`; Turnstile secret readable and `rewrapped` = 2, site key readable; `cfstream.apiToken`
   `''` with `wrong-key-or-corrupt`. `Captcha::verify([])` is **false** and `widget()` returns the notice
   ONLY IF a captcha key is unreadable — here none is (the turnstile pair peeled), so `activeProvider()`
   is `turnstile` and the widget renders with the plain site key. One `tblErrors` row with code
   `SECRET_SETTING_UNREADABLE` naming `tenantOnly`, the QR key and `cfstream.apiToken`; a second request
   within 6 hours adds none. The admin dashboard shows both banners; the health probe is `crit`.
9. Run 195 through `Migrator` (or the upgrade page). Then `repairStoredRows()`: report says MS365 secret
   and cron token encrypted (now flag 1, ciphertext, still readable in the snapshot); QR key encrypted in
   place and now readable; `cfstream.apiToken` reported unreadable and left alone; `tenantOnly` gone.
   Run it again: report empty. Bootstrap again: only `cfstream.apiToken` remains a failure.
10. Press Save on the CAPTCHA page with the secret box blank: the Turnstile secret row is rewritten with
    one layer (decrypts directly, `rewrapped` empty on the next request) and
    `CaptchaKeyRewrapRepaired` is in the activity log. Press Save again: nothing changes.
11. Edit `giving.cron_token` on `/settings` with a new value: stored ciphertext, flag still 1, readable
    in the snapshot. Edit with an empty box: row untouched, flash shown. Tick "Clear": value `''`,
    flag 1. Add a new key with "Sensitive" ticked and an empty value: stored `''`, not 40 bytes.
12. Replace the scratch `enc.key` with a different one and bootstrap: every stored secret is a failure
    (`wrong-key-or-corrupt`), `Captcha::verify()` returns **false**, `widget()` returns the notice,
    dashboard banner red, health `crit`, alert email attempted (or its cooldown sentinel touched).
    Run the §5 recovery statement: `verify()` returns true (no keys stored), notice gone. Delete the key
    file entirely: same as above with reason `key-file-unreadable`; `encrypt_setting()` throws on the
    CAPTCHA save and the page shows its failure flash rather than a blank page.

**D. Every integration that reads a secret** — the provable part without provider accounts is that
the value reaching the class is byte-for-byte what was typed. For each of the 13 dedicated pages plus the
venues regenerate button and the QR page: save a known dummy value through the real handler, bootstrap the
scratch copy, and assert `hash('sha256', App::settings($key)) === hash('sha256', $typed)` (compare hashes
so the test log never carries the value). Then, where a "Test connection" exists — MS365 Graph
(`/admin/integrations`), Cloudflare Stream, Web Push test-send, geocoding lookup, what3words suggest —
run it against a real dummy key and confirm the provider now answers "invalid key" rather than a malformed
request; and `tools/webpush-selftest.php` still passes. Provider acceptance of a REAL key is INFERRED
until the owner tries one on beta; say so in the report.

**E. Regression guard for the original bug**: temporarily put `=== '1'` back in the scratch copy and
run the `--bootstrap` self-test: it must fail on "an encrypted value really decrypts". Restore.

---

## 8. What this design does not do (and where each goes)

- No key-rotation tool (issue to file; scope in stage 2 §3.5).
- No decrypt-for-display on `/settings` (issue to file, small).
- Does not stop the CAPTCHA page rendering the plain SITE keys into its form — they are public by design.
- Does not change how `DbBackup` restores `tblSettings` (verbatim rows, PROVEN 803-816); a backup from
  another installation now produces named warnings instead of silent ciphertext. Whether the backup
  includes `enc.key` was not checked (INFERRED not: `_auth_keys` is gitignored and server-managed); the
  runbook should say "back up `enc.key` with the database".
- Does not touch the QR page's uncommitted fix (`admin/settings/qr/index.php` 156-196, PROVEN) — it is
  correct as written and starts working the moment Step 2 lands; note the one-line release caveat from
  stage 2 §3.10 (a QR save in the SFTP-before-Upgrade window on a database that has not run 187 could
  add a duplicate row).
- Does not run against a real installation; every "what an installation holds" is INFERRED from the
  writers.

---

## OWNER DECISIONS

### Decision 1 — What CAPTCHA does when its keys are stored but cannot be read

**Recommendation: A — refuse on every protected form, including sign-in and password reset, and show
the notice.** This is the standing "refuse when unsure" rule, the state can only be caused by an action on
the server or database by someone who therefore has the access to end it, and the recovery is one
documented statement (§5) or restoring the key file. Cost: until an administrator acts, nobody can sign in
with a local password (Microsoft/Google sign-in also stops if their secrets were encrypted), and public
forms cannot be sent. Everything else in this design is unchanged whichever option is chosen.

- **B — refuse on public, sign-in-free forms; allow sign-in and password reset** (they keep their own
  attempt limits). Cost: `Captcha::verify()` needs to know which form it protects — a parameter on 13
  call sites — and the sign-in page, the door a bot most wants, loses its check while broken. Avoids the
  lockout.
- **C — allow everything, but loudly** (error row, alert email, dashboard banner). Cost: every public
  form is open to bots until somebody reads the warning; contradicts the brief's "never quietly turn a
  security setting off" only in the word "quietly".

### Decision 2 — Encrypting secrets that earlier code stored readable

**Recommendation: A — run `repairStoredRows()` automatically on the next Upgrade** (Step 7). It
restores what the seed intended (those rows were seeded sensitive; the generic editor turned the flag off
without telling anyone), it is idempotent, it reports what it did by name, and 195 guarantees the Upgrade
button is pressed this release. Cost: from that moment those secrets depend on `enc.key`; losing the key
file then costs every integration credential, where today it costs only two-factor secrets and Zoom
tokens. The runbook must say "back up `enc.key` with the database" in the same release.

- **B — offer it as a button on the health page only.** Cost: many installations never press it, and
  their credentials stay readable in the database indefinitely; two installations of the same version
  differ.
- **C — do nothing; rows are encrypted only when next edited.** Same cost as B, without even a way to
  do it deliberately.

There are no other owner decisions. Deleting `tenantOnly`, using 195, peeling multiply-wrapped values,
failing closed on unreadable cron tokens and webhooks (already the code's behaviour), and leaving the
identifiers flagged are technical choices explained in §6.

---

# OWNER ANSWERS — 13 September 2026

- **Decision 1, what CAPTCHA does when its keys are stored but cannot be read: OPTION B, NOT the recommendation.**
  Refuse on public, sign-in-free forms. **Allow sign-in and password reset**, which keep their own attempt limits.
  The builder must follow what the design lists as B's cost:
  - `Captcha::verify()` needs to know which form it is protecting, so all 13 call sites pass it;
  - the sign-in page and password reset skip the CAPTCHA check while the keys are unreadable, and still apply
    their attempt limits;
  - the notice to administrators says plainly that sign-in is running without its anti-spam check until the keys
    are fixed.

  Everything else in Step 4 and §5 is unchanged.
- **Decision 2, encrypting secrets earlier code stored readable: OPTION A, the recommendation.** Run
  `repairStoredRows()` automatically on the next Upgrade (Step 7). The same release's runbook and DEV_NOTES must say
  "back up `enc.key` together with the database".
