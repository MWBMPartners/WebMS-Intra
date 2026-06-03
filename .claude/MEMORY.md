# Claude rolling memory — WebMS Intra

Living state file. Anchored to `main`; updated alongside every meaningful PR
or context change. **Latest snapshot below first** — older snapshots appended
at the bottom.

---

## Snapshot — 2026-06-03 (after PR #295 merged)

**On `main`:** v1.2.0 — last commit `d28c65d` (PR #291 squash-merged write-side
CRUD).

### Wave-state summary

The 1.2.0 cycle is effectively done. Major surface area now on `main`:

| Theme | Issues | PRs | Status |
| --- | --- | --- | --- |
| Apps wave 3 | #265 #275 #239 #240 | #283 | ✅ merged |
| Apps wave 4 (10 apps) | various | #284 | ✅ merged |
| Apps wave 5 (5 apps + infra) | various | #285 | ✅ merged |
| Defence-in-depth `_apps/` refactor | #159 | #288 | ✅ merged |
| Nonce-based CSP | #144 | #289 | ✅ merged |
| External error monitor | #143 | #290 | ✅ merged |
| REST API write CRUD | #157 | #291 | ✅ merged |
| PWA offline write queue | #233 | #292 | ✅ merged |
| Audit/SQL/security sweep | — | #293 | ✅ merged |
| Mobile-readiness sweep | #225 | #295 | ✅ merged |
| FEATURES + CHANGELOG sweep | — | #294 | ✅ merged |
| `auto-merge-alpha` verification | #147 | #287 | ✅ merged |
| Stale-rename docs sweep | #189 #182 #183 #194 | #286 | ✅ merged |

### Things to remember for next session

1. **GitHub API rate limit was exhausted** during the bulk issue-audit
   subtask. The retry strategy (when the limit resets) is `list_issues`
   in one bulk call and then triage in-process via local grep — **do not**
   per-issue `issue_read` again. Open issues to verify-and-close once
   quota allows include the wave-3/4/5 implementation tickets: #235,
   #236, #249, #263, #262, #273, #264, #274, #269, #266, #272, #267,
   #268, #276, #278, #277, #265, #275, #239, #240.

2. **`PORTAL_APPS` flip is live (PR #288)** — every new app controller
   goes under `web/_apps/<slug>/`, **not** `web/public_html/<slug>/`.
   The three exceptions Apache must still reach directly are
   `index.php`, `api-docs/`, `error.php`. Migration `tblRoutes.targetFile`
   is resolved against `PORTAL_APPS` first with a fallback to `public_html/`.

3. **CSP is now nonce-based** — every inline `<script>` needs
   `nonce="<?php echo App::cspNonce(); ?>"`. Inline event handlers
   (`onclick="…"`) are forbidden; bind in a nonce'd block.

4. **Offline queue uses IndexedDB + Background Sync** — see
   `web/public_html/assets/js/portal-offline.js`. Forms opt in via
   `data-offline-queueable`. Inspector at `/account/offline-queue`.

5. **REST API write endpoints are gated** by `api.{module}.{action}.enabled`
   per-site setting (defaults `true`). The OpenAPI doc at
   `web/public_html/openapi.json` lists 23 paths (10 read + 12 write +
   `/health`).

6. **Audit scripts under `tools/audit-checks/`** — `check_mobile_readiness.py`
   in particular now correctly skips `max-width`, HTML/PHP comments, and
   PHP-concatenated string-literal mentions of `<table>`. Re-run with
   `python3 tools/audit-checks/check_mobile_readiness.py` — should be 0
   findings on a clean tree.

7. **`_backups/` is gitignored** — anything in `tools/offsite-backup/` is
   admin-copied to the server out-of-band; do not move scripts into
   `_backups/` or they'll vanish at commit time.

### Subscriptions / in-flight

- None as of this snapshot. PR #291 was the last subscribed PR; merged.

### Known follow-ups (not pressing)

- v1.2.1 release-prep commit (version bump + CHANGELOG date stamp) once
  no further 1.2.0-line changes are queued.
- Issue audit pass (waiting on GitHub API rate-limit reset).
- Calendar view-modes / display-style (PRs #137 / #138) — separate branch
  outside this cycle.

---

## Snapshot — 2026-05-22 (pre-wave-3 baseline, archived)

`main` at v0.11.0 → 1.2.0 cycle in flight. Captcha #130 had just shipped;
password policy #132 just merged. Defence-in-depth refactor #159 was a
design proposal, not yet implementation.
