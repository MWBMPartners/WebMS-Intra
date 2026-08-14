# Handoff — Event Team Hub + release promotion to production

**Updated:** 2026-08-14 (session close)
**Product version:** `1.4.x` (automation owns the exact patch — see Notes).
**Primary work this session:** the **Event Team Hub** feature (WebMS-Intra
*and* projectBookIT) + a discovery-driven batch of core fixes, then a full
**alpha → beta → release-candidate → main** release promotion.

## Branch / tier state (at close)

| Branch | Tip | State |
| --- | --- | --- |
| `alpha` | (this commit) | Event Team Hub + all fixes; v1.4.x; deployed ✅ |
| `beta` | `44beb5e` | promoted via PR #389; deployed ✅; auto-bumped 1.4.1 |
| `release-candidate` | `8004749` | promoted via PR #390; deploy in flight |
| `main` | promotion in flight | rc → main is the final hop (PRODUCTION) |
| `claude/alpha-enhancements` | `6bec0cf` | the working branch; merged to alpha via **PR #388** |
| projectBookIT `feat/347-event-team-hub` | `06afac3` | **NOT merged** — still an open feature branch |

The four-tier flow is `alpha → beta → release-candidate → main`. Promotion
PRs hit a predictable `version.php` + `CHANGELOG.md` conflict (each tier
diverges on those two metadata files); resolve by keeping the *higher*
version + the fuller CHANGELOG (back-merge the base into the head, push the
head, then merge the PR so CI + deploy run). `auto-merge-alpha.yml`
fast-tracks *alpha* PRs only; beta/rc/main are merged manually once green.

## What shipped this session

### Event Team Hub — WebMS-Intra (#386, #387)
- **Phase 1** (`593ab5c`, migration 155) — `/calendar/event/hub`: resources
  (links + Markdown notes), a YouTube/Vimeo/Cloudflare Stream video grid
  with **signed-URL playback**, viewer roster context, coordinator tool
  strip. New `_core/VideoEmbed` (allowlist parse + safe embeds + RS256
  `signedToken` via `openssl_sign`), `Auth::isEventTeamMember()`, admin
  `/admin/integrations/cloudflare-stream` config page.
- **Phase 1.5** (`7c50496`, migration 156) — direct browser→Cloudflare
  upload: `_core/CloudflareStream` mgmt client, mint/status/settings page
  routes, `event-hub-upload.js` (200 MB cap + host allowlist + CSRF
  rotation), core `$cspConnectExtra`, in-portal Require-Signed-URLs /
  Allowed-Origins config.
- **#387** (`3b85b82`, migration 157) — `/api/calendar/hub-resources` +
  `hub-videos` read endpoints (tenant-scoped, identical-404, no secret
  leak) + `eventhub:read` scope in `ApiKey::SCOPES` — consumed by the
  projectBookIT sync.

### Discovery-driven core fixes (`6bec0cf`, migration 158)
- **#373 (ApiRouter globals fatal)** — `ApiRouter::dispatch()`/`dispatchV1()`
  never imported `global $mysqli, $SETTINGS;`, so 6 live handlers
  (`livechat/api/*` ×5 + `livestream/api/ping.php`) fataled on first hit.
  Fixed (Router.php already had it). **This was a real production bug.**
- **#308 worship live-sync** — operator/projector polled `/api/worship/
  state|advance` but the handlers sat at unreachable legacy paths with no
  enable flags → 404. Relocated to `_apps/worship/api/{state,advance}.php` +
  seeded `api.worship.*.enabled`.
- **#339** — `calendar/manage/save.php` slug probe now site-scoped.
- **#255 AppRegistry** — added `_core/apps/{noticeboard,worship,salvation,
  kids}.php` + seeded `worship/salvation/kids.enabled='true'` (⚠️ see Notes).
- Dead `api/*` `tblRoutes` cleanup (19 rows) + CF "Test connection" button +
  CLAUDE.md docs bump.

### projectBookIT (`feat/347-event-team-hub`, NOT merged)
Ported the whole capability: Phase 0 CSP consolidation + `$routeParams` fix
(`2edd175`), Phase 1 hub + `VideoEmbedService` (`bfa2b82`/`fd7e96d`), Phase 2
CF direct upload (`604d58e`), Phase 3 one-way **WebMS→BookIT sync**
(`WebmsIntraSyncService`, migration 071, cron step, admin link UI) (`0fdb5f4`),
plus rate-limit + webhook-annotation fold-ins (`06afac3`). Issue
**projectbookit#347**.

### Issue hygiene
Closed 16 issues with evidence: #303, #311, #363, #323, #375, #300, #338,
#360, #364, #194, #183, #374, #386, #387, #339, #373. Merged the 3
Dependabot codeql-action PRs (#382/#384/#385 → main/beta/rc).

## Remaining / next

### Release pipeline (in flight at close)
- Finish **release-candidate → main** (the production hop): create PR,
  resolve the version/CHANGELOG conflict, merge once CI green, monitor the
  **production** SFTP deploy. `main` merges may also fire `release.yml` /
  version-bump — watch for a tag.

### Owner actions (non-blocking; features are inert until done)
- **Cloudflare Stream** (both platforms): create a **Stream:Edit** API token
  + a signing key on the Stream-owning account; paste into each platform's
  admin. Until then the CF path is fully inert (YouTube/Vimeo still work).
- **projectBookIT**: decide whether to merge `feat/347-event-team-hub`;
  apply migrations 070/071 in order; to enable sync, mint a WebMS-Intra key
  scoped `events:read`+`eventhub:read` (tenant-pinned) and paste into BookIT.

### Real bugs found, filed, not yet fixed
- **projectbookit#348** — `tblWebhooks` schema drift: migrations 039 vs 053
  define incompatible shapes (039 wins); `WebhookService` (#254) reads
  columns that don't exist on the live table → "Unknown column" on any real
  install. Needs a reconciliation migration + a canonical-shape decision.
- **projectBookIT `VideoConferenceService::getSetting`** bypasses `Settings`
  decryption (§10.3) — ticketed, deferred (out of hub scope).

### Deferred (owner / product decisions, unchanged)
#299 sub-4 (Stripe Billing), #234 (tenant-admin consent), #322/#141 (web
push VAPID approval), #302/#304/#320/#321, #128/#150/#153/#155/#156 (new
apps), #361/#362/#365 (Noticeboard wave 2), #248 (largely done),
#97–#103 (BookIT-as-provider — the *reverse* direction, NOT superseded by
#387), #105/#106/#107 (owner-only repo/ops). `tblSalvationCards` retention
is a data-protection policy question, not code.

## Notes for the next session

- **Don't hand-edit `web/_core/version.php` or add alpha CHANGELOG entries**
  — `version-bump.yml` + `changelog.yml` own them (they auto-commit
  `[skip ci]` after each alpha/beta merge; beta auto-bumped to 1.4.1 this
  session). Expect per-tier version divergence — resolve promotion conflicts
  by keeping the higher version.
- **AppRegistry deploy-ordering caveat:** worship/salvation/kids now gate on
  settings seeded by **migration 158**. `AppRegistry::isEnabled()` returns
  false when the setting is missing, so on an existing install they 403 in
  the window between code-deploy and running 158 — **run migration 158 as
  part of any upgrade** (worship live-sync needs it too, so it's mandatory
  regardless).
- **`[CF-kc]` Cloudflare endpoints** (direct_upload body, edit verb, 200 MB
  ceiling, upload host) are from training knowledge — docs are egress-blocked
  here. Re-verify at the first live upload; safe because the CF path is inert
  until credentials are configured. The admin "Test connection" button is the
  cheapest live check.
- **`removeVideo` divergence is deliberate** — WebMS best-effort (source of
  truth), BookIT abort-on-failure (satellite). Both documented inline; do
  not "fix" to match.
- One handoff doc only: **this file**. `MEMORY.md` is the separate durable
  memory; `CLAUDE.md` is the project instructions.
