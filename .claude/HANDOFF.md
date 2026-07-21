# Handoff — Dependabot tri-branch coverage + rolling enhancement bundles

**Written:** 2026-07-21 · **Updated:** 2026-07-21 (live — updated after every task)
**Primary branch:** `claude/dependabot-alpha-beta-branches-q24zd7` (off `main`)
**Session goal (verbatim intent):**
1. Copy PR #368 so a twin targets `alpha`. ✅ (#369, draft)
2. Extend Dependabot + GitHub security checks to `alpha` + `beta` (not just `main`). ✅ (#370)
3. Do NOT merge those PRs yet — user merges later.
4. Then: review ProjectBrief + memory + FULL open/closed issue set → identify next steps → implement, looping for extra enhancements/features.
5. Per task: create/reopen a GitHub issue, do the work, update the issue, commit, update THIS handoff.
6. Bundle work for efficiency.

**Model policy (user-mandated):**
- **Analysis / deep planning / strategising → Fable 5, SEQUENTIAL (never parallel).** Fall back to Opus only if Fable is unavailable, then keep retrying Fable next time.
- **Implementation → Sonnet or Haiku.** Opus only if genuinely necessary. Goal = GIRFT (get it right first time), token-efficient.
- Orchestrate via the Workflow/Agent "dev team" (the `dev-team-plugins` the user named isn't installed; closest catalog match is the **Engineering** plugin, not enabled — intent fulfilled via Workflow/subagents).

---

## ✅ Done this session

### Phase A — Dependabot / security tri-branch coverage (#370)
| Item | State | Ref |
|---|---|---|
| Alpha twin of #368 (`setup-python` 6→7) | **draft PR open**, all 10 checks green, auto-merge job `skipped` (draft guard) | #369 · branch `chore/setup-python-v7-alpha` · commit `1873cdd` |
| `dependabot.yml` → github-actions + composer on main/alpha/beta (6 entries) | committed + pushed | `5833857` |
| DEV_NOTES "Dependabot three-tier coverage" + CHANGELOG Unreleased | committed + pushed | `5833857` |
| Security-scan branch audit (CodeQL/php-static-analysis/pr-security/deploy already cover alpha/beta) | confirmed — no workflow change needed | documented in DEV_NOTES |
| Umbrella issue | open, progress-commented | #370 |

**Nothing merged** (per instruction). The dependabot.yml change only takes effect once `claude/dependabot-alpha-beta-branches-q24zd7` merges to `main` (Dependabot reads config only from the default branch).

---

## 🔜 Next — Phase B/C (enhancement bundles)

- **PR strategy (user decision 2026-07-21):** do NOT create a new PR per bundle. All alpha-destined enhancement work accumulates into **ONE dedicated `alpha` integration PR** — branch **`claude/alpha-enhancements`** → base `alpha`, opened **draft** (auto-merge-alpha guard). #369 stays a clean, separately-mergeable dependency bump. The dependabot infra work stays on `claude/dependabot-alpha-beta-branches-q24zd7` → main (separate concern).
- **Fable roadmap agent is running** (deep review of brief/memory/FEATURES/DEV_NOTES + full open/closed issues). Output → `scratchpad/roadmap-analysis.md`. On completion: pick highest value-to-effort bundles, implement with Sonnet/Haiku, one issue + commit + handoff-update per bundle, all onto `claude/alpha-enhancements`.
- Open the alpha integration PR (draft) once the FIRST bundle is committed (GitHub needs ≥1 commit diff).

## ⚠️ Standing gotchas / safety rails (do not relearn the hard way)
- **`auto-merge-alpha.yml` squash-auto-merges any *non-draft* PR based on `alpha`** once checks pass → open every alpha-targeted PR as **draft** while "don't merge yet" holds.
- **Dependabot reads `dependabot.yml` only from the default branch (`main`).** Alpha/beta copies of the file are inert.
- **Dependabot *security* updates only ever target the default branch** — no retarget. Alpha/beta rely on the version-update entries.
- **CHANGELOG bot (`changelog.yml`) runs only on push to alpha/beta and only from `web/**` commits** → `.github/`/docs-only changes need a manual CHANGELOG line.
- **Version on branch is 1.2.1** (`web/_core/version.php` single source of truth); `version-bump.yml` auto-bumps on alpha/beta pushes.
- Code style: `declare(strict_types=1)`, full-IF (`=== true`), MySQLi prepared stmts, `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`, no `<table>` (use `portal-data-list`), `PORTAL_*` constants. `api/*` routing trap + `ApiResponse::success()` (not `::ok()`) — see .claude/CLAUDE.md.

## Task list (harness TaskCreate IDs)
1. ✅ Copy PR #368 → alpha draft   2. ✅ Dependabot alpha/beta   3. ✅ Security branch audit
4. ⏳ Fable roadmap analysis (running)   5. ✅ this HANDOFF rewrite (kept live)

## Resume pointers
- Roadmap file (when ready): `scratchpad/roadmap-analysis.md`
- Open items surfaced pre-analysis (from prior HANDOFF): 5 noticeboard follow-ups (fonts, /help/noticeboard, media-upload pipeline, full_schema seed backfill 108-144, openapi noticeboard docs); PR #297 deferral (brand-aware `openapi.json`).
