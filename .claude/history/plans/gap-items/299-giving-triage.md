# Triage: Issue #299 "Giving polish" — what shipped vs what remains

Date: 2026-08-28 · Branch audited: `alpha` @ `a134463` (migrations through 174) · Worktree: `/home/user/WebMS-Intra/.claude/worktrees/agent-a464d620c0e6042ef`

## 0. Critical correction to the triage brief

The issue's real title is **"Giving polish: offering count + pledge campaigns + bank reconciliation + account updater"** — the fourth pillar is the **account updater** (payment-processor card-expiry webhook + donor re-enrol email flow for *recurring* giving), **not** "account/year-end statements". Statements were never part of #299's ask: self-service statements shipped with the base giving app (#266), and bulk treasurer statements shipped separately as #440 (PR #445, migration 172). Any triage that treats "statements" as #299's fourth pillar would wrongly conclude the issue is 100% done.

Issue body acceptance criteria (verified live via the GitHub API, 2026-08-28):

- [x] Two-person offering count session — checked off, "shipped on PR #372 (`a1e05f8`, migration 150)"
- [x] Pledge campaigns with goal thermometer + auto-attribution — checked off, "shipped on PR #372 (`b9f7df8`, migration 151)"
- [ ] Bank statement CSV import + match reconciliation — **unchecked in the body, but shipped** (see comment of 2026-07-23 + the #48 audit comment of 2026-08-17; code evidence below confirms). The body's checkbox is simply stale.
- [ ] Account-updater webhook handler + re-enrol email flow — unchecked, genuinely **not started**.

The issue's final comment (2026-08-17, the #48 issue audit) already re-scoped it: *"This issue stays open, now scoped down to just the account-updater sub-feature."*

## 1. Per-pillar verdict table (file:line evidence)

| # | Pillar | Verdict | Evidence (paths absolute from worktree root `web/` = `/home/user/WebMS-Intra/.claude/worktrees/agent-a464d620c0e6042ef/web/`) |
|---|--------|---------|----------|
| 1 | Two-person offering count | **DONE** | Migration `web/_sql/150_offering_count_sessions.sql` — `tblCountSessions` (L66), `tblCountEnvelopes` (L103), 4 route seeds (L121-124). Handlers `web/_apps/giving/count/{index,session,save,close}.php` all present; transactional close calls `Giving::attributeGift()` at `count/close.php:196`. Routes mirrored in `full_schema.sql:4980-4983`. Gated by `Giving::canManage()` (`web/_core/Giving.php:30`). FEATURES.md:607-620 documents it shipped ✅. |
| 2 | Pledge campaigns | **DONE** (incl. the online-giving attribution follow-up) | Migration `web/_sql/151_giving_pledge_campaigns.sql` — `tblPledgeCampaigns` (L53), `tblPledges` (L77, UNIQUE campaignID+userID). UI handlers `web/_apps/giving/{campaigns,campaign,campaign-save,pledge-save}.php`; routes `full_schema.sql:5000-5003`. Core math: `Giving::attributeGift()` (`web/_core/Giving.php:879`), `Giving::pledgeExpectedToDate()` (`Giving.php:980`). The follow-up flagged in the issue's 2026-07-22 comment ("online/project giving attribution is a documented follow-up") **was subsequently wired**: `web/_core/Payments.php:1177` and `web/_core/Projects.php:151` both call `Giving::attributeGift()` before their automatic `tblGivingEntry` inserts (FEATURES.md:637 confirms). Manual writer hook: `web/_apps/giving/entry-save.php:82`. |
| 3 | Bank reconciliation | **DONE** | Migration `web/_sql/152_bank_reconciliation.sql` — `tblBankImports` (L63), `tblBankTxns` (L83, dual nullable FKs `matchedEntryID`/`matchedCountSessionID`), routes (L115-118), `giving.reconcile.toleranceDays` setting (L123). Handlers `web/_apps/giving/reconcile/{index,import,view,match}.php` + shared no-guess auto-matcher `_automatch.php` (underscore-prefixed include, deliberately not a route — `_automatch.php:8-11`). Routes mirrored `full_schema.sql:5014-5017`. Covers the issue's asks: CSV import at `/giving/reconcile/import`, date+amount matching with tolerance window, unmatched flagging both directions ("in bank not in log" and "in log not in bank" with in-transit-vs-missing hint — per the 2026-07-23 ship comment and `reconcile/view.php`). |
| 4 | Account updater (recurring-giving card refresh) | **MISSING — and its prerequisite doesn't exist either** | See §2. |

### Adjacent (asked in the brief, but NOT a #299 pillar): statements

Fully covered, for the record: self-service `web/_apps/giving/my-statement.php` (`Giving::renderStatementPdf()` at `web/_core/Giving.php:186`, called at `my-statement.php:32`); treasurer bulk flow `web/_apps/giving/{statements,statements-generate,statements-email,statements-download}.php` (#440/PR #445, migration `web/_sql/172_giving_bulk_statements.sql` — `tblGivingStatementLog` L83, settings L116-118, routes `full_schema.sql:3962-3968`), byte-identical shared renderer per `statements-generate.php:10`. Gift Aid year-end HMRC tie-in also already exists (`web/_apps/giving/hmrc-export.php`). Nothing to build here.

## 2. The genuine remaining gap — sub-4, and why it is not buildable as scoped

#299 sub-4 asks for: Stripe/GoCardless card-expiry webhook → email donor a one-tap re-enrol link → pause the recurring gift until reauthorisation. Every prerequisite is absent on alpha today:

- **No subscriptions**: Stripe Checkout is created one-off only — `web/_core/Payments.php:318` hard-codes `'mode' => 'payment'` (never `'subscription'`). PayPal (#432, migration 167) is likewise one-off Orders v2 capture; GoCardless remains a stub.
- **No payment-method capture**: `grep -rn "INSERT INTO tblPaymentMethod|UPDATE tblPaymentMethod" web/` → **zero hits**. The table exists (`full_schema.sql:4130`) and is only ever *read* — `web/_apps/account/payment-methods.php:25` (list), `web/_apps/account/pm-delete.php` (delete), `web/_core/GdprEraser.php` (erasure). No code path ever creates a row.
- **No recurring flag ever set**: `tblPayment.isRecurring` (full_schema.sql:1317, DEFAULT 0) is written by no INSERT/UPDATE anywhere; the only reader is the read-only page `web/_apps/account/recurring.php:30`, whose own header (L6-8) says "cancel flow lands in the per-provider follow-up PR" — which never landed.
- **No relevant webhook events handled**: `Payments::handleStripeEvent()` (`web/_core/Payments.php:391-429`) handles exactly three types — `checkout.session.completed` (L399), `payment_intent.succeeded` (L409), `charge.refunded` (L417). No `payment_method.*`, `customer.subscription.*`, `invoice.*`, or `setup_intent.*`. `handlePayPalEvent()` (L917) handles capture-completed (+refund). Generic ingestion/verification/retry infrastructure DOES exist and is reusable (`Payments::ingestWebhook()`, `tblWebhookEvent` full_schema.sql:4173, webhook retry migration 166).

The issue's own 2026-07-23 comment reached the same conclusion and is still accurate: *"Sub-feature 4 is therefore really 'build Stripe Billing subscriptions + payment-method capture first, then the account-updater,' and should be re-scoped behind an explicit payment-provider sign-off rather than treated as the smallest remaining piece."* Nothing merged since (PayPal one-off checkout #432, bulk statements #440, workflow engine #443) changes that.

## 3. Recommendation: CLOSE #299 — spin sub-4 out as a re-scoped successor issue

**Do not build sub-4 under #299.** Reasons:

1. **3 of 4 pillars shipped and verified** in the merged alpha tree (table above); the issue title's "polish" framing is now misleading — everything polish-sized is done.
2. The remainder is **not polish, it is a new feature with an unbuilt dependency** (recurring giving / Stripe Billing subscriptions + payment-method capture). Estimated honestly, the dependency is larger than all of #299's shipped scope combined, carries a PCI/product decision (which provider(s), SCA/3DS re-auth UX, GoCardless mandates vs Stripe cards), and the issue's own audit comment says it should sit behind an explicit payment-provider sign-off. That sign-off has not been given.
3. Keeping #299 open as a shell for a dependency-blocked feature hides the real state: the FEATURES.md "tracked but not started" row (FEATURES.md:532) already describes only the account-updater residual.

**Suggested close-out actions** (for whoever executes; no repo writes made by this triage):

- Tick the stale sub-3 checkbox in the issue body (it shipped 2026-07-23, migration 152) so the body matches reality.
- Close #299 with a comment: sub-1/2/3 shipped (migrations 150/151/152, PR #372) + the online-attribution follow-up (Payments.php:1177 / Projects.php:151); sub-4 superseded by a new issue.
- Open a successor issue, suggested title: **"Recurring giving: provider subscriptions + payment-method capture + account-updater/re-enrol flow"**, labels `type: feature` (not enhancement — it's new infrastructure), `app: expenses` (the label #299 used for giving) or a proper `app: giving` label if one now exists, `status: blocked` pending provider sign-off. Body should carry forward the sub-4 spec from #299 plus the dependency list from §2 above, and note the reusable substrate that already exists: `Payments::ingestWebhook()` + `tblWebhookEvent` + retry (migration 166), `tblPaymentMethod` schema + read-only account pages, `tblPayment.isRecurring` column, `account/recurring.php` read-only page awaiting its cancel flow.
- Update FEATURES.md:532 to point at the successor issue number.

**Migration numbering note** (recorded for the successor): alpha's highest merged migration is **174** (`174_workflow_engine.sql`); the sequence has an intentional gap at 168-169. All five in-flight `claude/venue-*` remote branches top out at 170 (already merged into alpha). **Next free number: 175** — but the successor is blocked on sign-off, so do not reserve it yet; re-scan at build time.

## 4. Micro-residuals (optional, tiny — none block closing)

- `account/recurring.php:6-8` promises a cancel flow "in the per-provider follow-up PR" — fold into the successor issue.
- `Payments::handleStripeEvent()` L403-406 carries a documented follow-up (pass Stripe's observed `amount_total`/`currency` through `markPaymentSucceeded()` to extend the integrity gate) — pre-existing, unrelated to #299, could be its own micro-issue.
- Issue label is `app: expenses`; a dedicated `app: giving` label would help future triage (cosmetic).
