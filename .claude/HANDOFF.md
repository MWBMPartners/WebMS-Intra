# Handoff — Roadmap execution (Dependabot tri-branch + alpha enhancement bundles)

**Updated:** 2026-07-21 (live — refreshed after every task)
**Session goal:** (1) copy #368 → alpha ✅; (2) Dependabot + security on alpha/beta ✅; (3) don't merge those yet; (4) full brief/memory/issue review → implement next steps + enhancements, looping; (5) per task: issue + commit + HANDOFF; (6) bundle for efficiency.

**Model policy:** analysis/deep-planning → **Fable 5 sequential**; implementation → **Sonnet/Haiku** (Opus only if necessary). GIRFT.

**PR strategy (user decision):** enhancement work does NOT spawn new PRs — it accumulates into ONE **draft** alpha integration PR: **#372** (branch `claude/alpha-enhancements` → `alpha`). Draft so `auto-merge-alpha.yml` can't merge it. Dependabot infra is separate on `claude/dependabot-alpha-beta-branches-q24zd7` → main.

---

## Branches / PRs in flight (nothing merged — user merges later)
| PR | Branch → base | What | State |
|---|---|---|---|
| #369 | `chore/setup-python-v7-alpha` → alpha | setup-python 6→7 (alpha twin of #368) | draft, CI green |
| #372 | `claude/alpha-enhancements` → alpha | **roadmap enhancement bundles** (accumulating) | draft |
| (no PR) | `claude/dependabot-alpha-beta-branches-q24zd7` → main | Dependabot tri-branch (#370) + docs | pushed, no PR opened (harness rule) |
| #368 | dependabot → main | setup-python 6→7 (upstream Dependabot) | leave as-is |

## Roadmap (Fable) → `scratchpad/roadmap-analysis.md` (full detail)
Ranked bundles. Legend S/M/L, model.

- **B1 ✅ Truth sweep** (#371) — done. ~27 issues closed w/ evidence; #323 reopened (REST API Phase 2); #338/#339/#322/#128/#40/#234/#248/#225 re-scoped; #194/#183 kept open (fixes incomplete). Docs reconciled in commit `6b8bc76`.
- **B3 ✅ (#338)** iCal export now via `Portal\Core\Ical` (TZID/VTIMEZONE/RRULE) — commit `b73371d`. feed/account-feed untouched. Closes #338 on merge.
- **🚨 #373 (NEW, high / maybe-critical)** — `Router::dispatch()` never imported `$mysqli`/`$SETTINGS` into controller scope; 177 controllers use bare `$mysqli`, 6 use bare `$SETTINGS`. PHP-scope-proven undefined inside the dispatched `require`. Applied safe 2-word fix `global $mysqli, $SETTINGS;` before the require — commit `effdafa`. **NEEDS USER RUNTIME CONFIRM: load `/attendance` on alpha — renders ⇒ latent; 500s ⇒ live app-wide regression (from #159).**
- **B2 (M, Sonnet impl in flight)** fresh-install parity (#364/#339/#194) — spec `scratchpad/b2-parity-spec.md`. Bigger than expected: 58 seed-block entries, 37 settings + 102 routes rows, #339 index fix, + **6 migrations (122/123/125/129/135/139) fail on a nonexistent `isEncrypted` column and abort the chain**, + 112 DROP-INDEX guard, + new `check_schema_seed_parity.py` audit checker wired into CI.
- **B4 (M)** Noticeboard wave — #362 help (Haiku), #365 openapi docs (Haiku), #361 fonts (Sonnet, USER DECISION: restyle to Plus Jakarta Sans vs self-host 3 faces), #363 media upload pipeline (Sonnet).
- **B5 (M, Sonnet)** run existing `tools/e2e-migrations/` in GitHub Actions (#248) — after B2.
- **B6 (S)** CI hardening #105/#106/#107 (repo settings = user; deploy `--delete` monitor).
- **B7 (S+M, Sonnet)** prayer-requests #312 (settings kebab-case rename) + #311 (assignment workflow).
- **B8 (M, Sonnet/Opus for VAPID)** push delivery #322 + install prompt #141 — USER DECISION: VAPID keygen.
- **B9 (S-M)** GDPR gap audit #47 → close umbrella + narrow successors.
- **B10 (L, Opus, SOLE-FOCUS)** REST API v1 Phase 2 (reopened #323, absorbs #157/#95).
- **B11 (M)** MS365 delegated mail #234. **B12/B13** church-vertical + platform — USER prioritization.

## Reopen candidates (from roadmap §2)
- #323 ✅ reopened. #31 SIGNula → **surface to user** (brief requires SIGNula support but issue closed "not applicable"). #95/#157 → leave closed (folded into #323).

## Pending USER decisions (surface when reached, don't guess)
1. #361 noticeboard fonts: restyle to Plus Jakarta Sans (recommended) vs self-host 3 faces.
2. #322/#141 push: generate VAPID keypair (admin UI can, no CLI).
3. #31 SIGNula: reopen-as-icebox vs amend brief.
4. B12/B13 church-vertical + platform sequencing.

## Follow-ups still open / not yet done
- **#183** DEV_NOTES stale `core/`/`vendor/` paths (~lines 194,511,947,957,1029,1151-1179,1338,1465-1467) — dedicated verify-then-fix doc pass.
- **#194** folded into B2.
- Close #371 + the B1-closed issues stay closed; close #372-tracked issues when #372 merges.

## ⚠️ Safety rails
- `auto-merge-alpha.yml` squash-auto-merges any **non-draft** PR based on `alpha` → keep alpha PRs **draft** until user says merge.
- Dependabot reads `dependabot.yml` only from default branch (main); security-updates only target default branch.
- `changelog.yml` runs on push to alpha/beta from `web/**` commits only → auto-generates CHANGELOG on merge; don't hand-add alpha CHANGELOG entries. `version-bump.yml` auto-bumps on alpha/beta pushes (not feature-branch/PR pushes).
- Version source of truth = `web/_core/version.php` = **1.2.1** (don't hand-edit; automation owns it).
- Code style: strict_types, full-IF `=== true`, MySQLi prepared stmts, `htmlspecialchars(…,ENT_QUOTES,'UTF-8')`, no `<table>` (portal-data-list), PORTAL_* consts. ApiRouter: handler at `_apps/{app}/api/{action}.php` + seed `api.{app}.{action}.enabled='true'`; `ApiResponse::success()` not `::ok()`.

## Task IDs (harness): 1-4 ✅ · 6 ✅ B1 · 7 B2 · 8 B3 · 9 B4
