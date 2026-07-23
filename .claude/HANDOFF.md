# Handoff — alpha enhancement bundle (draft PR #372)

**Updated:** 2026-07-23 (session close — docs hygiene pass, #183)
**Branch:** `claude/alpha-enhancements` → **draft PR #372** → `alpha`
**HEAD:** `527e801` — working tree clean.
**CI:** all green — Psalm, CodeQL, static-security, PHP-lint, actionlint, JS
checks, and the `e2e-migrations` harness all pass through migration 154.
PR Security Checks bot comment clean (see standing instruction in
`.claude/CLAUDE.md`).

Draft status is deliberate: `auto-merge-alpha.yml` only auto-merges
non-draft PRs, so #372 stays draft until the user says merge. Enhancement
work accumulates into this ONE PR rather than spawning new ones (user
decision, still in force).

---

## What shipped this session (folded into #372)

- **#299 "Giving polish"** — two-person offering-count session (sub-1,
  migration 150), pledge campaigns (sub-2, migration 151), bank
  reconciliation (sub-3, migration 152), plus the online/project-gift
  auto-attribution follow-up (`Giving::attributeGift()` wired into
  `Payments::markPaymentSucceeded()` and `Projects::fulfilPledge()`).
  Sub-4 (recurring-giving account updater) needs Stripe Billing — not
  started, kept deferred (see below).
- **#303 Phase 2** — Discipleship per-user progress + auto-completion
  (migration 153): enrolments, per-step progress, auto-sweep from
  attendance/RSVP evidence, member "my pathways" view, pastor roster.
- **#300 v2** — Service Plans operator → confidence-monitor message channel
  (migration 154), closing the last open piece of #300.
- **GDPR eraser fix** — `Portal\Core\GdprEraser::catalogue()` had wrong/
  mis-cased table names that silently skipped erasure; corrected, and added
  previously-missed auth-residue tables (`tblLocalAccounts`,
  `tblLinkedAccounts`, `tblTrustedDevices`, `tblPasswordResets`,
  `tblKidProfiles`). Plus an unrelated demo-data-wipe table-name fix.
- **New CI check** — `tools/audit-checks/check_php_table_refs.py` flags
  `tblXxx`-shaped identifiers hard-coded in PHP that don't exist in
  `full_schema.sql` (closes the gap that let the GDPR bug above slip past
  review). Wired into `pr-security.yml` as check 14.
- **native `confirm()` cleanup** — last 11 call sites converted to the house
  `data-confirm` pattern; `check_no_native_confirm.py` now reports 0.
- Base of #372 (already landed pre-session): #323 Phase 2 REST API v1
  write surface (dual-mode `ApiAuth`, `/api/v1/{resource}` facade, new
  write endpoints, `ApiKey::SCOPES` + rotation, per-key rate limiting,
  `Site::forceContext`) + #324 outbound webhooks admin CRUD.

Full prose detail for all of the above: top of `CHANGELOG.md` under
`[1.4.0] - 2026-07-22 (alpha)`. Apps inventory: `.claude/CLAUDE.md` →
"Apps (shipped on `main`)".

---

## Remaining / next

### Awaiting user decision (Bucket B)

- **#234** — Azure Mail.Send delegated-permission grant (high value; needs
  tenant admin consent, can't self-serve).
- **#322 / #141** — Web push: VAPID keypair generation/approval (admin UI
  can generate it, but a human should approve enabling push).
- **#373** — 1-minute live-confirm check: verify `/attendance` (and other
  dispatched routes) actually render on alpha post the `global $mysqli,
  $SETTINGS;` scope fix — needs a live runtime check, not just code review.
- **#105 / #106** — GitHub repo settings changes — owner-only, can't
  self-serve.
- **#370** — Dependabot tri-branch PR — pushed to
  `claude/dependabot-alpha-beta-branches-q24zd7` → main, no PR opened yet
  (harness rule); needs user to open/merge.
- **#304** — In-app messaging — offer polling Phase 1 vs keep deferred;
  needs a product-scope call before starting.
- **Product sequencing** — #153 / #150 / #156 / #155 — order of upcoming
  work, needs user prioritisation.
- **Confirm-keep-deferred** (no action unless user says otherwise):
  - **#302** — special-category data encryption risk (safeguarding/health
    fields) — flagged as a real risk, deferred pending scope decision.
  - **#299 sub-4** — recurring-giving account updater — needs Stripe
    Billing, not started.
  - **#320 / #321** — adversarial-review sign-off items — deferred pending
    user review.
- **New this session — policy item:** `tblSalvationCards` retention. The
  table is name-keyed (no `userID`), so it's structurally unreachable by the
  user-keyed GDPR erasure engine (`GdprEraser` already documents this
  exclusion deliberately — see `#303 Phase 2` catalogue notes). Needs a
  retention/redaction policy decision from the user — not a code bug, a
  data-protection scope question.

### Safe autonomous items still open

- **A7** — `site.url` setting seeding: needs an installer-vs-derive-at-
  runtime decision before implementing; low value. Verify current state
  before picking a direction.
- **A9** — Issue-tracker hygiene: close ~12 issues that are verified-shipped
  per this session's and prior sessions' work but still open on GitHub.
  Best done once #372 actually merges (so "closes #NNN" commit references
  resolve against the merged history, not a draft branch).

---

## Notes for the next session

- Don't hand-edit `web/_core/version.php` — automation owns version bumps
  on alpha/beta pushes (`version-bump.yml`); this session did not touch it
  per instruction.
- Don't hand-add alpha CHANGELOG entries on merge — `changelog.yml` handles
  that; this session's CHANGELOG entries were added directly per explicit
  instruction to this docs-only pass; verify no duplicate/conflicting entry
  appears when #372 merges.
- `_core/apps/*.php` (AppRegistry) is missing entries for `noticeboard`,
  `worship`, `salvation`, `kids` — real, working, shipped apps that simply
  don't appear in `/admin/apps`. Flagged in `.claude/CLAUDE.md`'s apps table
  note; not fixed here (docs-only pass) — worth a follow-up issue if
  unintentional.
- #183 (DEV_NOTES stale `core/`/`vendor/` path refs) — fixed this session,
  see CHANGELOG. Verify no other stale bare-path instances have crept back
  in before closing the issue for good.
