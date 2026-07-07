# Handoff — PR #358 / Community Noticeboard integration

**Written:** 2026-07-07
**Branch:** `feat/discipleship-and-live-chat`
**PR:** #358 (single-PR rule — stack any remaining work onto this branch, do NOT open a new PR)
**Issue:** #360 (Community Noticeboard app — visual poster wall — Phase 1)
**Deep-planner output:** `.../scratchpad/noticeboard-integration-plan.md` (Fable 5 plan — full detail; this handoff is the résumé)

---

## Session context

Continuing PR #358 — was originally "#303 Discipleship Pathway Tracker Phase 1 + #313 COP Live Chat Phase 1+2 + #317 Virtual Host Console Phase 2 (LivePrompts)"; expanded during this session to also carry the Community Noticeboard app imported from Claude Design (https://claude.ai/design/p/6fab711c-d550-4200-8d96-42d6751a5fba).

CI is currently ✅ **green with zero security-check findings** on the last shipped commit before this handoff was drafted (`3f07bcc`). Do NOT push the workstream below until the executor lints every touched file — one broken commit undoes that clean state.

## What's done this session (already committed and pushed unless noted)

Recent commit chain on `feat/discipleship-and-live-chat` after the CI-green baseline `df6cee2`:

| SHA | Purpose | Files |
|---|---|---|
| `6731256` | **fix(noticeboard): qr.php** — `Qr::pngBytes`→`Qr::generate` (fatal), strict host pinning (bypass), 300-char cap | `web/_apps/noticeboard/api/qr.php` |
| `49cbb11` | **fix(noticeboard): save.php** — bind_param arity 20→21 (fatal on every save), cross-site poster guard, URL scheme allowlist, pre-tx data:-URI validation | `web/_apps/noticeboard/api/save.php` |
| `3f07bcc` | **fix(sql): full_schema noticeboard terminator + seed parity + migration 145 header** | `web/_sql/full_schema.sql`, `web/_sql/145_noticeboard.sql` |

Untracked strays deleted before the commit chain (no commit needed — untracked):
- `web/_apps/noticeboard/.DS_Store` — already gitignored
- `web/_sql/0XX_noticeboard.sql` — byte-identical stale copy of `145_noticeboard.sql`

**Push status:** commits `6731256`, `49cbb11`, `3f07bcc` are **NOT pushed yet.** Run `git push` when you're ready — user's standing rule is never push without explicit go-ahead, so ask first.

## What remains — 4 commits + 5 follow-up issues + docs updates

Full plan in [scratchpad/noticeboard-integration-plan.md](../../../private/tmp/claude-501/-Users-lance-manasse-Projects-Coding-and-Development-MWBM-Partners-Ltd-GitHub-WebMS-Intra/ebec51a3-358e-4971-ad72-125b71b4c5a9/scratchpad/noticeboard-integration-plan.md). Section numbers below reference that plan.

### Remaining commit 3 — page-scoped CSP extension mechanism (Sonnet)

**Files:** `web/_core/templates/header.php`

**What:** add three optional PHP variables that a page controller can set BEFORE requiring header.php to widen `img-src` / `media-src` / `frame-src` for that page only. Behaviour-neutral for every existing page.

**Where to edit:** immediately before line ~114 in header.php (where the CSP header string is built — grep for `Content-Security-Policy`). Insert:

```php
// 🔐 Page-scoped CSP extensions — a controller may set $cspImgExtra /
//    $cspMediaExtra / $cspFrameExtra (space-separated source lists) BEFORE
//    requiring header.php to widen those directives for that page only.
$cspImgExtra   = isset($cspImgExtra)   === true ? ' ' . trim((string) $cspImgExtra)   : '';
$cspMediaExtra = isset($cspMediaExtra) === true ? ' ' . trim((string) $cspMediaExtra) : '';
$cspFrameExtra = isset($cspFrameExtra) === true ? ' ' . trim((string) $cspFrameExtra) : '';
```

Then in the CSP header string:
- `img-src 'self' data:{$cspImgExtra};`
- `media-src 'self'{$cspMediaExtra};` **← new directive; without it `default-src 'self'` covers it**
- `frame-src https://challenges.cloudflare.com{$cspFrameExtra};`

**Lint:** `php -l web/_core/templates/header.php`
**Commit message:** `feat(core): page-scoped CSP extension variables in header template (#360)`

### Remaining commit 4 — self-host React 18.3.1 UMD + wire the board (Sonnet + user)

**⚠️ USER MUST DOWNLOAD** — per standing rule, we don't fetch external assets from the agent. Ask the user to run these commands in their own terminal:

```bash
cd 'web/public_html/assets/vendor/react/' 2>/dev/null || mkdir -p 'web/public_html/assets/vendor/react' && cd 'web/public_html/assets/vendor/react'
curl -fsSL -o react-18.3.1.production.min.js     'https://unpkg.com/react@18.3.1/umd/react.production.min.js'
curl -fsSL -o react-dom-18.3.1.production.min.js 'https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js'
```

**Then VERIFY SRI** — the bundle at `web/public_html/assets/noticeboard/noticeboard.noeval.js:1495,1497` embeds these expected sha384 hashes:

```bash
openssl dgst -sha384 -binary react-18.3.1.production.min.js     | openssl base64 -A
# MUST equal: DGyLxAyjq0f9SPpVevD6IgztCFlnMF6oW/XQGmfe+IsZ8TqEiDrcHkMLKI6fiB/Z
openssl dgst -sha384 -binary react-dom-18.3.1.production.min.js | openssl base64 -A
# MUST equal: gTGxhz21lVGYNMcdJOyq01Edg0jhn/c22nsx0kyqP0TxaV5WVdsSH1fSDUf5YJj1
```

If either mismatches, **STOP** — do not commit; the download may have been tampered with or the unpkg version has drifted.

**After React is placed and verified**, edit `web/_apps/noticeboard/index.php`:

1. **Before** the `require PORTAL_CORE . ... 'header.php';` line (currently line 29), add:
   ```php
   // 🔐 Board needs Canva iframes + externally-hosted poster media on THIS page only.
   $cspImgExtra   = 'https:';
   $cspMediaExtra = 'https:';
   $cspFrameExtra = 'https://www.canva.com';
   ```

2. **Replace** the current single `<script src="/assets/noticeboard/noticeboard.noeval.js" defer>` block (line 71) with three deferred nonce'd script tags in this exact order (defer preserves execution order):
   ```html
   <script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="/assets/vendor/react/react-18.3.1.production.min.js" defer></script>
   <script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="/assets/vendor/react/react-dom-18.3.1.production.min.js" defer></script>
   <script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="/assets/noticeboard/noticeboard.noeval.js" defer></script>
   ```

3. **Update the stale comment block** at lines 66-69 — it currently says React comes from unpkg; change it to describe the self-hosted setup + note that `loadReactUmd()` in the bundle short-circuits when `window.React`/`window.ReactDOM` pre-exist.

**Lint:** `php -l web/_apps/noticeboard/index.php`
**Commit message:** `feat(noticeboard): self-host React 18.3.1 UMD + wire board under nonce CSP (#360)`

### Remaining commit 5 — remove the unrunnable eval-variant bundle (needs user decision)

**⚠️ USER DECISION REQUIRED (Section 9 Q1 of the plan)** — the file `web/public_html/assets/noticeboard/noticeboard.js` is the eval variant of the bundle (runtime-Babel from unpkg). It **cannot** run under the portal CSP (no `unsafe-eval`, no unpkg allowlist). Fable's default recommendation is to `git rm` it — 143 KB dead weight + a footgun if someone wires it in later.

**Ask the user:** "Delete `web/public_html/assets/noticeboard/noticeboard.js` (eval-variant, unrunnable under CSP)? Y/N"

If Y: `git rm web/public_html/assets/noticeboard/noticeboard.js` → commit as `chore(noticeboard): remove unusable eval-variant bundle (#360)`.
If N: skip this commit.

### Remaining commit 7 — docs updates (Sonnet, LAST because it references earlier commits' behaviour)

**Files:** `CHANGELOG.md`, `FEATURES.md`, `DEV_NOTES.md`, `.claude/CLAUDE.md`

Detail is in plan Section 6. Summary:

- **CHANGELOG.md** — under `## [1.4.0-dev] - Unreleased`, add `### Community Noticeboard — poster wall Phase 1 (#360, PR #358)` with bullets: page + self-hosted React; 3 API endpoints (list/save/qr — enablement-flag gated); `tblNoticeboardPosters` (migration 145); page-scoped CSP extension mechanism in header.php; security hardening (cross-site write guard, URL scheme allowlist, strict QR host pinning).
- **FEATURES.md** — new app section after Announcements (~line 243): `### 📌 Noticeboard — /noticeboard/ ✅ (#360)` — poster wall (Canva/image/video/text posters, once/weekly scheduling, colour/aspect/serif styling, QR share). **Tables:** `tblNoticeboardPosters`. Routes + the 3 API endpoints. Admin gating (`App::isSiteAdmin()` for save). **Also reword** any "noticeboard" mentions inside the Announcements section (~line 233 block) — it currently calls announcements "the noticeboard"; make it "text announcements."
- **DEV_NOTES.md** — add a "Noticeboard React bundle" section: `dc-runtime` header (line 1 of the JS files) — GENERATED, do not hand-edit; `noticeboard.noeval.js` is the only wired variant; React self-hosted at `/assets/vendor/react/` with SRI hashes recorded; page-scoped CSP extension variables (`$cspImgExtra`/`$cspMediaExtra`/`$cspFrameExtra`) documented as a `header.php` contract; Google Fonts deliberately blocked → system-font degradation.
- **.claude/CLAUDE.md** — apps table: (a) change announcements row from "Per-site noticeboard" to "Per-site text announcements"; (b) add new row `noticeboard | /noticeboard | Visual poster wall (Canva embeds, media, weekday recurrence, QR share)`; (c) under "Recent ships" bump the PR #358 line to include the Noticeboard app.

**Commit message:** `docs(noticeboard): CHANGELOG, FEATURES, DEV_NOTES, CLAUDE.md apps table (#360)`

### Step 8 — post-work (no commit)

1. Comment on issue #360 with SHAs of commits 6731256/49cbb11/3f07bcc + remaining SHAs + link to PR #358. Do NOT close #360 until PR #358 merges.
2. Create the 5 follow-up `for consideration` issues (create the label if missing — user prefers doing this through their global standing practice):

   | # | Title | Body summary |
   |---|---|---|
   | 1 | `Noticeboard: self-host Bricolage Grotesque / Instrument Serif / IBM Plex OR restyle to Plus Jakarta Sans` | Currently Google Fonts blocked by CSP → falls back to `system-ui` stack. PR #356 pattern is the model. |
   | 2 | `/help/noticeboard help page` | No per-app help entry yet; matches how announcements/documents/tasks defer their help pages. |
   | 3 | `Noticeboard: real media upload pipeline (replace data: URI rejection)` | Board's upload flow produces `data:` URIs; save.php rejects them → admin gets a failed save on native upload. Need portal-hosted media storage + returned URL. |
   | 4 | `full_schema.sql seed backfill for migrations 108-144` | Tables were backfilled in PR #358; their tblSettings + tblRoutes seeds were NOT. Fresh installs still get seeds via the migration runner, but full_schema alone is out-of-parity. |
   | 5 | `Document api/noticeboard/* in openapi.php / api-docs` | The 3 endpoints aren't in the OpenAPI spec yet. |

## User decisions still needed (surface these BEFORE executing)

1. **Delete `noticeboard.js` eval-variant bundle?** (Commit 5) — default recommendation: yes.
2. **Page-scoped `img-src https:` / `media-src https:` on /noticeboard** — permits admin-curated external images/videos (mild tracking-pixel exposure, one page only). Alternative: keep 'self'-only and require portal-hosted media — but then most pasted poster URLs won't render. Default: allow, page-scoped.
3. **Typography degradation accepted?** Board falls back to system fonts; visible visual delta from the Design mock. Default: accept for Phase 1, log follow-up issue #1.
4. **Push commits 6731256 / 49cbb11 / 3f07bcc now?** They're in local git only; not on origin.

## Risks / gotchas

- **Whole-set-replace concurrency:** two admins editing simultaneously = last-writer-wins. Acceptable Phase 1; note in FEATURES.md.
- **Canva regional subdomains:** the `www.canva.com` pin (server + frame-src) rejects other Canva hosts. Widen only if a real embed fails.
- **Babel fallback path in the bundle** (`noticeboard.noeval.js:1007`): if the precompiled component registration ever misses, the board dies on the CSP-blocked unpkg Babel fetch. Test in dev with the console open; regenerate the bundle if triggered — do NOT "fix" it via CSP.
- **QR is authed** while the encoded deep-links require login anyway — coherent for an intranet board; revisit if a public board mode ships later.

## Verification checklist (do this before considering the workstream done)

- [ ] `git status --short` clean of noticeboard-related files (`.DS_Store`, `0XX_*` gone)
- [ ] `php -l` passes on every touched PHP file
- [ ] Local dev: `/noticeboard` renders with zero console CSP violations (nonce React, allowed Canva iframe, allowed https: img/media, blocked Google Fonts is expected)
- [ ] Local dev: save round-trip works (create, edit, delete, reorder) — no 500, no bind_param arity error
- [ ] Local dev: `/api/noticeboard/qr?data=<current-host-url>` returns image; off-host URL returns 422
- [ ] PR #358 CI check "Static security checks" reports 0 findings after push
- [ ] Issue #360 has SHA references before PR #358 merges
- [ ] Follow-up issues #1-5 above exist with `for consideration` label

## Related handoff artifacts

- Deep planner output: `.../scratchpad/noticeboard-integration-plan.md` (Sections referenced above)
- Fable 5 subagent ID for resume: `aa3ad8cb2fb316b03` (use `SendMessage` if you need it to reconsider a decision, e.g. Canva domain widening)
- MEMORY.md pointers: `[[apirouter-routing-trap]]`, `[[single-pr-multi-bundle-pattern]]`, `[[brand-font-pattern]]` all relevant
