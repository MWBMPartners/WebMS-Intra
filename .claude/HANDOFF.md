# Handoff — branch clean-up, full audit, documentation refresh

**Updated:** 2026-10-12 (kept current as the work happens, so it can be picked up at any point).
**Working branch:** `claude/alpha-wip` — the single work-in-progress branch.
**Target:** one pull request into `alpha` at the end. No stacked pull requests.

---

## Read this first — where we are right now

**PRIVACY, 12 October 2026 evening — the maintainer's real name was in seven tracked files** (found by the F4 builder in one; a search found the rest). Six are corrected in `160ac36` (a code comment, the sample inviter in the e-mail template preview, four old plans and the handoff archive, which recorded the local folder path). The seventh, `docs/day2-support.md`, is corrected in F4's fix round because F4 is changing that file. No commit MESSAGE holds the name. **The name is still in this PUBLIC repository's history (since `fa7967b`, 31 May 2026); rewriting history is the owner's decision and has been put to them once. Do not rewrite history without an explicit instruction.** Every agent's brief already says to write Salem874.

**OWNER, 12 OCTOBER 2026, about 17:45 — do not go over the same ground twice.** The owner was asked about the sign-in lockout rule
(5 wrong tries, 15 minutes) twice, in two forms, and said so: "we should not be re-going over the same things again. this just
causes elongated time to work and wasts usage credits/tokens!" From now on: search this file before putting any question, and never
ask again in another form; a build detail that serves an answer already given is decided here and reported in one line; each package
gets ONE independent check and at most ONE fix round, committed on the fix builder's proofs; shorter updates. The lockout rule is
settled: no more than 5 wrong tries in any 15 minutes, as built.

## OWNER DECISION, 12 OCTOBER 2026, ~06:05 — packages that share no files run SIDE BY SIDE; work autonomously

The owner, on the pace: "Let packages that share no files run side by side. Also run autonomously, but do ask me questions if you
need any clarifications or decisions." Written into `.claude/CLAUDE.md` ("Deep analysis"), `.OpenAI/CONTEXT.md` rule 3 and memory.
How it is run: each builder gets its exact file list; `CHANGELOG.md`, `DEV_NOTES.md` and `FEATURES.md` are never edited by two
packages at once (a builder working alongside another writes its entries to `<evidence>/DOC-ENTRIES.md`, added at commit); **stage each
package by ITS OWN file list — never from `git status`, which now mixes packages**; own named containers and ports.

## WHERE THINGS ARE, 12 OCTOBER 2026, 06:47 — package C committed; three jobs running; two owner answers; one trap

**Packages E and G are committed and pushed: `49b8169` (E; #553, #554, #555; migration 210) and `73843b6` (G; #577, #578,
#579; no database change; the runbook and off-site guide corrected in the same commit).** Each had two check rounds and a last
fix round committed on its builder's proofs. On the merged branch I ran: the whole migration test on MySQL 8.0.36 (all phases
passed), the backup's class-level self-test (23 proofs, 324 checks) and its page self-test (109 checks), every audit check and
the package guard. Evidence: `.claude-work/resume/rollout-E--merge-evidence/` and `rollout-G--merge-evidence/`. The checkouts
`worktrees/e` and `worktrees/g` and their branches can be removed.

- **After this work reaches `main`, the first run of the migration test fails in phase 5, ON PURPOSE**: one named exception
  (the minimum password length is 8 on a database built from today's `main`, 12 on a fresh install) stops being true. Delete
  that line from `KNOWN_P5_VALUE_DIFFERENCES` in `tools/e2e-migrations/run.sh` then, in its own commit, not before. Written in
  `DEV_NOTES.md` too. Say it in the pull request.
- **Workflow pieces now waiting to be placed (all approved by the owner):** E's `rollout-E--fix2-evidence/PROPOSED-workflow-changes.diff`
  (#556 and the test's new wording); G's `rollout-G--fix2-evidence/proposed-backup-selftest.yml` and
  `proposed-pr-security.patch`; the three report-only blocks of W3 plus one for `check_docs_table_refs.py`; the new self-tests
  of W4; the calendar's part 9 step; the mail self-tests. Place them as ONE change, apply each patch with `git apply --check`
  first, and run `actionlint`. `deploy.yml` and `package.yml` wait for the owner to see their difference tables.
- **So now:** H1 (#587) is building; F1 follows H1 (both change `full_schema.sql`; H1 took migration 211, so F1 takes 212 and
  F4 213). H2 (#580) is building. K (strict mode) and M1 (mail library) are building. M2 follows H2.
- Issues opened today from the builders' and checkers' "Found" lists: #575, #576, #577 to #591, #592 (mail, the tracking
  issue for M1 to M6), #593 (strict mode, package K), #594 (installer and install-script gaps; for F5), #595 (backup
  follow-ups; the lasting fix is recording column types in a snapshot), #596 (ten smaller findings).

**F6 part 1 is committed and pushed as `bd1bfab`** (#499; nine files under `tools/` plus the documents). Two check rounds; the
second fix round was committed on its builder's proofs with no third check, because nothing can be published until part 2. On the
merged branch the tree check passes (all 1,201 files may be packaged). **For every package still to come:** adding or changing ANY
`.htaccess` under `web/`, a PHP file in the web folder, an archive, or a new kind of file needs one line in
`tools/package/check-package.py`, or `python3 tools/package/check-package.py --tree web` refuses it; the refusal prints the line
to paste (F3 adds `.htaccess` files, so its brief must say this). The corrected `package.yml` and the table of its 19 differences
from W2 are in `rollout-F6--fix2-evidence/`; show the owner that table before placing it. Part 2 (the guide and the
install-from-the-zip proof) waits for F3, F4 and F5, and its check looks at the whole guard again. The self-test takes 40 to 53
minutes on this loaded machine; measure it on a quiet one before wiring it into a workflow. The checkout
`.claude-work/worktrees/f6` and branch `ro-f6-build` can be removed.

**Packages B2a and B2b are committed and pushed as `716581f`** (60 files; #534, #548, #574, part of #511 and #536). Three check
rounds; the last small fix round was committed on its builder's proofs. On the merged branch I ran `access-pages-selftest` (240
of 240) and `erasure-pages-selftest` (66 of 66); evidence in `.claude-work/resume/rollout-B2a--merge-evidence/`. Runbook 7.7 is
corrected. The checkout `.claude-work/worktrees/b2a` and branch `ro-b2a-build` can be removed. Opened #591: an erasure has no
time allowance of its own (the erasure test hit PHP's 30-second limit when this machine's load average was over 400, and passed
with a longer allowance). **So H1 and F1 now wait only for E.**

**The owner's answers on mail, 12 October, about 13:25 (final; word for word in the brief of the second plan):**
1. Our own small mailer client, which also sends through the non-SMTP services (Microsoft 365, Google Workspace) "based on our
   previously defined requirements re emails"; shaped so it can later be lifted out as a standalone library for the owner's other
   PHP web applications; and PHPMailer allowed as a fallback.
2. One mail route for the installation for now; per-organisation later, with the global administrator still able to choose
   per-installation or per-organisation.
3. Always encrypted, certificate always checked. If the check fails: the global administrator is told, the unsent messages are
   queued, and the administrator may send a queued message with the check skipped, one at a time, or switch checking off behind
   a clear warning. No unencrypted choice.
4. A mail queue is built now, not after roll-out.

**The second mail plan is written: `.claude-work/resume/rollout-S--plan-r2.md`** (450 lines; six packages M1 to M6; the first
plan `rollout-S--plan.md` is superseded where they differ). M1 the library core and SMTP route (started 14:00, beside anything);
M2 SMTP wired into the portal (settings, the e-mail page, "Send test", the hourly limit; one migration): **the smallest package
that gives a working SMTP route**; M3 the queue (one migration); M4 the queue page and the certificate flow; M5 Microsoft 365
and Google moved onto the same client; M6 PHPMailer as a second SMTP engine. **Order: H2, then M2, then F1 to F4, then M3, then
M4.** M2, M4, M5 and M6 change pages under `admin`, `dashboard` and `help`, so none runs beside link-sweep slices S1 or S2; M3
not beside F8, S1 or S3; M3 and the calendar's part 9 share `GdprEraser.php`, `personal-data-catalogue.php` and
`auth/account/data-export.php`; M2 and part 9 share `dashboard/index.php`.

**The owner's answers to the second plan's decisions, 12 October, about 14:05 (final):**
1. PHPMailer is NOT shipped. A GLOBAL administrator puts it on the server and chooses it as the SMTP engine. It is used only when
   chosen, never tried automatically. Mail that cannot be sent is queued, as with our own client.
2. The certificate page offers "accept only this server's present certificate" FIRST, above "send this one with the check
   skipped" and "switch checking off".
3. When checking is off and the certificate becomes good again, checking switches itself back on, with an activity-log line.
4. Waiting mail: retried for about a day; kept 7 days, then deleted; never sent when older than 48 HOURS (the owner typed "48
   days" and confirmed 48 hours when asked). **Each of the three numbers is configurable per organisation**, by that
   organisation's administrator or the installation's global administrator, with installation defaults. The plan had them as
   installation settings only: M3's brief must add this.
5. **M5 and M6 are built AFTER the first roll-out.** Before roll-out: M1, M2, M3, M4.
6. Quiet hours: nothing is held automatically for now; the preferences page's sentence that says non-urgent e-mail waits is
   corrected in M3; the rule is decided later, with the calendar's part 9.
7. The mail self-tests may run on GitHub: one new workflow file, `mail-selftests.yml`, as the plan's section 6 writes it; placed
   after M1 has passed its check. No `continue-on-error`.

**Package F7 is committed and pushed as `a9d8569`** (#500; `tools/deploy/resolve-settings.sh` and its self-test, 366 checks; no
workflow uses it yet). Its fix round was not given a second check (refuse-side changes, nothing calls it, and the workflow is
checked again before placing). The corrected proposal is `rollout-F7--fix-evidence/proposed-deploy.yml`, with
`WHAT-CHANGED-IN-THE-PROPOSAL.md` ending in the table of 23 differences from W1 to show the owner. It cannot be placed before F3,
and F3's code must name the channel file in a way F7's `tree` rule sees (not yet proved against real F3 code). The checkout
`.claude-work/worktrees/f7` and branch `ro-f7-build` can be removed.

**The plan for #514 parts 9, 10 and 11 is written: `.claude-work/resume/p514-p9-11--plan.md`** (487 lines; packages P9
notifications, P10 audiences, P11 documents). P9 and P10 share no file. Both start after B2a, B2b, E, G, F1, F2, F4, F8 and the F9
slices they name. **The owner overruled the plan on one point (12 October): the daily e-mail to administrators also carries each
waiting event's TITLE AND TIME**, not only numbers and calendar names. So P9's brief must add what the plan's recommendation
avoided: a time shown in the event's own zone with the zone named, and tested on both clock-change nights; only events that
administrator may see; a line saying when the list was made, since an event can change or be hidden before the e-mail is read;
and no title in any log. The owner said yes to adding P9's self-test to `calendar-selftests.yml` (the exact step is in the plan's
section 6).

**The owner added two faults to the roll-out set (12 October): #587 and #580. Their plan is written:
`.claude-work/resume/rollout-H--plan.md`** (two packages that share no file; no workflow change). The owner's answers, final:

1. A withdrawn expense claim is a status of its own, final: the claimant only, only while pending; an approved, paid, rejected or
   already-withdrawn claim is refused; no undo; the record, receipts and approvals are kept.
2. It counts in Admin, Reports' "Total claims" and "Total value", as a rejected claim does, with its own bar under "by status".
3. The migration turns an EMPTY status into Withdrawn (an empty one can only have come from this button on a loosely set server).
4. A sign-in block ends 15 minutes after the fifth failure; further tries while blocked do not extend it.
5. A blocked person may still ask for and use a reset link, under a limit of its own (5 requests and 10 uses per address per 15
   minutes).
6. **An SMTP mail route IS needed for roll-out** (the portal has only Microsoft 365 and Google; the pages that offer SMTP are not
   wired to anything). Its plan is being written (S). It must fit with H2, which adds `Mailer::canSend()`.

**When each can start:** H1 (#587; one migration and `full_schema.sql`; Opus) after B2a, B2b and E are committed, and BEFORE F1
(H1 is a two-line change to the install script; F1 holds that file longest). H2 (#580; `auth/login/index.php`, `Mailer.php`,
`forgot-password/save.php`, the health page; Opus) after G is committed, and before F4. Neither touches `Auth.php` or `Site.php`.
Migration numbers are given at build time in commit order: E 210, then H1, then F1, F4, the calendar's part 9, and S if it needs one.

**Package D is committed and pushed as `ce66fc1`** (#488; eight files). It had a full check, a fix round, a narrow check of the
fix round, and four last small fixes made on proofs in `.claude-work/resume/rollout-D--fix2-evidence/` (run them again with the two
scripts there). Small follow-ups are #590. **Two corrections to the runbook are still owed:** G's `RUNBOOK-CORRECTIONS.md` when G
lands, and section 7.7 (plus row 17 of its "What was tested" table) when B2a lands, because B2a ends a switched-off account's
session. Each corrected sentence marked "Tested" must be run first.

**Package C is committed and pushed as `a34bb77`** (#547, #568, #563; issues commented, kept open until the merge). Its one check
found the three fixes right and two faults in the NEW TESTS; both were fixed in the same commit on planted-fault proofs, with no
further round (evidence `.claude-work/resume/rollout-C--fix-evidence/`). What it found and did not fix is in #575 (the noticeboard
script loads Babel with no fingerprint) and #576 (small follow-ups).

**Running now (each with a watchdog on its report in `.claude-work/resume/`):**

| Package | What | Where it works | Report |
| --- | --- | --- | --- |
| H1 | expense withdraw (#587) — **DONE, pushed `aa25592`**. One check, one round of fixes, then my own run on the merged branch (MySQL 8.0.36): 37 of 37, 50 of 50 and 278 of 278. Migration 211 is taken. Documents updated in the same commit. Checkouts `h1` and `h1m` can be removed. |
| H2 | sign-in lockout and "Forgot password" (#580) — **DONE, pushed (the commit titled `fix(auth): sign-in limits and "Forgot password"`)**. One check, one fix round (`e82cd0d`), then my own run on the merged branch against a loosely set MySQL 8.0.36: sign-in-limits 114 of 114, access-pages 278 of 278, strict-mode 128 of 128. **The fixes to the sign-in gate were NOT independently checked: sign-in goes first in the closing Codex pass.** The blocking rule is as the owner set it (settled). Two things the owner should know and has been told: reset links sent before this version stop working when it is installed (at most an hour's worth, once); and on a web server that holds a page back until the script ends, "Forgot password" still takes longer for a known address until mail is queued (mail package M3 closes that; say so in M3's brief). Help pages `faq.php` and `getting-started.php` reworded in the same commit. Open: #597 (settings page secrets), #598 (twelve follow-ups). `RateLimiter::isUserOrIpBlocked()` is no longer called by anything. Checkout `h2` can be removed. **Mail part 2 (M2) may start now.** |
| M1 | the mail library's core and its own SMTP route (#592) — **DONE, pushed (see the commit titled `feat(mail): a mail library of our own`)**. One check (ten faults, none letting a secret travel unprotected), one fix round (`e75af58`), then my own run on the merged branch: 74 of 74, the stands-alone check and the package check pass. Nothing calls the library yet. **Before briefing M2, M3 or M4 read `.claude-work/resume/rollout-M1--fix-evidence/FOR-LATER-PACKAGES.md`**: a certificate PHP accepts can now be refused for its name (a customer would see mail held); `needs-person / certificate` can arrive with no certificate facts; M3 must ask `isAboutTheMessage()` before dropping a refused message and should count repeated `encryption-failed`; M4 adds the "checking off" mode. Not done: Psalm after the fix; OpenSSL 1.1.1 and LibreSSL wording; a real mail server. Checkouts `m1` and `m1m` can be removed. |
| M2 | SMTP wired into the portal (#592, part 2 of 6; migration 213) — **DONE, pushed (the commit titled `feat(mail): the portal can send through an SMTP mailbox`)**. One check (nothing serious; the password was found nowhere), one fix round (`6da5b24`), then my own run on the merged branch, MySQL 8.0.36: mail-pages 108 of 108, access-pages 285, site-modes 136, sign-in-limits 114, the mail library 74, every audit script, the package check; the install script loaded twice leaves six mail rows and one record each for 211, 212 and 213. Migration test not run again on the merged branch (the checker ran it on both MySQL versions; only SQL comments changed since). Next free migration: **214** (S597 has it if it needs one). **When S597 is committed, the DEV_NOTES mail section's sentence "the portal's start-up never decrypts a secret setting" must be brought up to date.** Seven points for the queue packages are on #592. Small, unplaced: the Integrations page still shows `mail.provider` from `$SETTINGS`; after a refused sign-in each skipped message also writes an error-record line; `tools/sign-in-limits-selftest.php` line 1215 raises a PHP warning while passing. **F4 (no built-in address) may start now**, and M3 after F4. The checkout `m2m` can be removed. |
| F3 | the release channel; the web folder may have any name (#499, #500; no migration) — **DONE, pushed (the commit titled `fix(core): the release channel is decided in one place`)**. One check, then one round (`6e0c2e2`) that fixed its four small faults and built THE OWNER'S FINAL RULE (12 October 2026, settled): the `PORTAL_ENV` setting; else the `.channel` file beside `_core`; else a web folder named exactly `public_html` (live), `public_html_beta` (beta) or `public_html_dev` (dev); else ASSUMED LIVE, errors hidden, gate off. My own run on the merged branch, loosely set MySQL 8.0.36: channel 220 of 220, site-modes 136, access-pages 285, sign-in-limits 114, strict-mode 128, every audit script, the package check. `/api-docs/` was a fatal error on every installation and now works. Documents, the API description, the help page, two SQL comments and both assistants' files updated in the same commit. Told to the owner: a pre-release copy the last release recognised only as `alpha_html` / `beta_html`, or by a path merely containing the names, is now assumed live and must be given the file BEFORE upgrading; our own three folders have the exact names. **Carry into later briefs:** F5 (installer): a missing channel file is a warning, not a refusal; write a deny file into `_auth_keys/` and `_install/` (with the hosting panel on the wrong folder `/_auth_keys/enc.key` was handed out). F7 (deploy): today's `deploy.yml` about line 380 would delete a hand-made `.channel`; where copies share one `_core`, write no file. F6 part 2: the guard should refuse a channel file committed at `web/.channel`. Small, unplaced: `/offline/` is answered by the web server outside the gate (holds nothing private); an administrator is not told when the setting or file held an unknown word; Server Information could show the source. The checkout `f3` can be removed. **S597 may start now.** |
| S597 | secrets in Settings, with the owner's two September answers on #497 (#597 with #497; migration 214) — **DONE, pushed (the commit titled `fix(core): secrets saved in Settings are stored encrypted`)**. One check (nothing serious), one fix round (`b7fa0dd`), then my own run on the merged branch, MySQL 8.0.36: settings-secrets 151 of 151, mail-pages 108, access-pages 285, site-modes 136, sign-in-limits 114, channel 220, portal-address 93, site-addresses 101, every audit script, the package check; the install script loaded twice leaves 585 settings rows, 57 marked secret, and one record each for 211 to 215. The last comment naming our installation is gone and the domain check's pending list holds only the four `deploy.yml` lines. **The upgrade notes (CHANGELOG, README) open with what STARTS when the files are uploaded** (outside error reporting, the venue reminders job, web push; text messages, payments, AI, transcription and maps on use), what can STOP (a job whose token was regenerated), and "back up `enc.key` with the database": repeat all three in the pull request text. `Captcha::verify()` takes the form's name as a second argument with no default: **M3 also edits `auth/forgot-password/save.php`, so keep that argument when joining.** Workflow lines proposed, not placed: `check_sensitive_seeds.py --strict` in `pr-security.yml`; one line of the pull request template. Not built, to write up: Upgrade's own snapshot and older backups hold secrets as readable text; the visitor form and live chat cannot be sent while a bot check IS working (neither draws a challenge; older than this); `help/disaster-recovery.php` line 90 says to "rotate `enc.key`" while the runbook says never replace it by hand; `feeds.cron_token` and `reminders.cron_token` are seeded without the mark. The checkout `s597c` can be removed. **F5 (the installer) may start now.** |
| F8 | link sweep part 1: the tools, the shared page furniture and the sign-in redirects (#536) — **DONE, pushed (the commit titled `fix(core): links in the shared page furniture and the sign-in flow`)**. One check (the sign-in redirects held against 525 tries), one fix round (`2eba304`), then my own run on the merged branch, MySQL 8.0.36: site-addresses 101 of 101, access-pages 285, site-modes 136, sign-in-limits 114, channel 220, portal-address 93, every audit script, the package check. It also closed an OPEN REDIRECT after sign-in that affected every installation (own line in CHANGELOG). I took `_core/ExpenseMailer.php` off the pending list in the same commit (F4 made its links whole). **Counts on this branch for F9's four slices: S1 421, S2 654, S3 683, S4 938 bare addresses; `_install/upgrade.php` (4) is F5's.** The four builders follow `.claude-work/resume/rollout-F8--build-evidence/HOW-TO.md` and run `sweep-addresses.php` from there (neither is committed); each takes only its own lines off the pending list. Then ONE check for all four. **`Site::FIRST_PART_MODE_READY` stays false even with an empty list**, until these are settled (written on #536): the chat box on `/live` (`livechat-widget.js` asks four bare addresses and runs before `portal.js`), the service worker, the installable app's start address, the return address of a Microsoft or Google sign-in. S3's builder corrects `/auth/login` (not an address) in two calendar pages by hand. The checkout `f8` can be removed. **F9 is done LAST: the owner's decision of 11 October 2026 on the F plan's question 5 ("Yes, but last"; settled). It rewrites links in about 500 files, so it runs when nothing else is in flight, and it also sweeps the pages later packages add.** |
| M3 | the mail queue (#592, part 3 of 6; migration 216) — on `ro-m3-merged` as `277dbc7` (checkout `.claude-work/worktrees/m3m`). Its ONE check (`rollout-M3--check.md`) found the queue's CORE sound (9 workers on 400 messages, kills at three points, two tabs, 90 rounds: nothing delivered twice; 17 kinds of mail-server answer: nothing lost without a record; clock-change nights exact; body, reset link and password nowhere readable) and **1 HIGH, 6 MEDIUM, 5 LOW around it.** HIGH: with no scheduled job, on PHP run by Apache (its own module, FastCGI or CGI: ordinary shared hosting, ours included) no password-reset e-mail goes out and nobody is told; after one refused sign-in or failed certificate ALL mail stops; and the job cannot run at all on a pre-release copy (the staff gate redirects the scheduler). MEDIUM: the health page says the job ran when none exists; pages and records say "sent" for mail only waiting; mail can go late (a reminder arrived three hours after its event); a restored backup sends waiting mail again; an erased person's address stays in two places; no page lets an organisation set its own four numbers. **ONE fix round is running** (report `rollout-M3--fix.md`). **Decided here, not asked:** delivery comes before the small timing difference (while the real job has not run in 15 minutes the portal sends as it did before the queue, a reset included); mail that was waiting when a backup was taken is HELD after a restore, never sent by itself. **Because of the HIGH fault, a SHORT SECOND CHECK reads only what the fix round changes** (the owner's own rule: a later round checks only the latest fix round's changes; go round again only where mail delivery, data loss or a security hole is at stake). Then commit: `git cherry-pick --no-commit 277dbc7 <fix>`. To write up when it lands: text messages stopped by quiet hours are recorded as queued and never sent; a stored attachment has no checksum; a run can be ended by a 30-second web server limit. After it: M4 (the queue page and the certificate choices). |
| F5 | the installer (#499, #500; no migration) — BUILT as `20f7f82` on `ro-f5-build` (checkout `.claude-work/worktrees/f5`, on `3740abe`), plus `d70aad0`, mine: the builder's ready patch that makes the comment in the seven refuse-everything files true (comments and one checksum; the rule unchanged; the seven copies identical; the package check passes at that commit). Report `rollout-F5--build.md`; the full text for `docs/INSTALL.md` and the document lines are in `rollout-F5--build-evidence/DOC-ENTRIES.md`. **Its ONE check is running** (`rollout-F5--check.md`). **Told to press hardest on a safety gate the builder CHANGED:** a session lost after the tables were built used to be a dead end; now a database with tables but no installed version recorded is let through on the ownership code, provided every `tbl…` table in it is one the install script makes. The builder also fixed five faults in the old installer (one made the "database is not empty" page crash for everybody; another overwrote the key file on every finish, which would make every stored secret unreadable). Then at most one fix round, commit on proofs: `git cherry-pick --no-commit 20f7f82 d70aad0 <fix>`. Its entry is off the link check's pending list, which then names only the four slices. Not built, for an issue when it lands: `_install/.htaccess` still says "allow direct access"; the table-building step asks for no extra time; a signed-in global administrator's wipe still removes another program's `tbl…` tables; the installer's pages load Bootstrap from an outside address; `docs/INSTALL.md` does not exist yet (the documentation sweep creates it from the builder's text). |
| F4 | no built-in web address, and e-mailed links use the portal's set address (#500, code side; migration 215) — **DONE, pushed (the commit titled `fix(core): no web address is built in`)**. One check (no serious fault), one fix round (`98b9ba1`), then my own run on the merged branch, MySQL 8.0.36: portal-address 93 of 93, mail-pages 108, access-pages 285, site-modes 136, sign-in-limits 114, every audit script, the package check; the install script loaded twice leaves one `site.url` row. `docs/day2-support.md` now names the maintainer by username only, so no tracked file holds the real name. Upgrade notes are in CHANGELOG and README. **Still to place:** the one-line `bootstrap.php` comment (wording in `rollout-F4--fix-evidence/DOC-ENTRIES.md`, section 1) together with deleting that entry of the domain check's pending list: do it in the commit that brings S597 across, because S597 is in `bootstrap.php`. The four `deploy.yml` lines stay pending until the approved workflow change. **For later briefs:** F5 (installer) loads `PortalAddress.php` alone, calls `check()`, and fills the seeded `site.url` row only if it is empty (never a plain insert); F6 part 2: `check-package.py` keeps its own copy of our domain's name. Documentation sweep: 19 lines in `DEV_NOTES.md` name our installation and each needs "for example" beside it. Not fixed: #600, plus `Newsletter::lockInRecipients()` is not safe to run twice (not reachable through the pages today). **M3 (the mail queue) and F5 may start now.** The checkout `f4` can be removed. |
| F1 + F2 | several organisations (#502; migration 212) and the members-only gate (#511) — **DONE, pushed (the commit titled `fix(core): several organisations on one installation`)**. One check (no serious fault; the gate held against about 190 hand-made requests), one fix round (`fe20aca`), then my own run on the merged branch against a loosely set MySQL 8.0.36: site-modes 136 of 136, access-pages 285 of 285, sign-in-limits 114 of 114, strict-mode 128 of 128, feed-admin 840 of 840, every audit script. Migration test not run again on the merged branch (no migration changed since the checker ran it on both MySQL versions). **Trap learned: run `access-pages` on a database server nothing else is using; four of its checks count statements in the server-wide log.** Owed: the by-hand browser look at the three new pages and the in-app help (#599). Next free migration after M2's 213 is 214. F3, F4 (+F8), F5 (+F9), F6 part 2 and the calendar's parts 9 and 10 may now start, comparing file lists with M2 first. Checkouts `f1` and `f1m` can be removed. |
| K | strict database mode (#593) — **DONE, pushed `04eb374`**. One check, one fix round (`85ea1b3`), then my own run on the merged branch against a loosely set MySQL 8.0.36: 128 of 128, expense-withdraw 37 of 37, access-pages 278 of 278. MariaDB not run. The rule "only `DbServer::connect()` opens a connection" is now in `.claude/CLAUDE.md` and `.OpenAI/CONTEXT.md`. Owed in the documentation sweep: in-app help for the new health check. Checkouts `k` and `k2` can be removed. **H2's fix and F1 both come after this and must be joined to it by hand where they share `_health-probes.php`.** |

B2a and E each make ONE commit on their own branch and do not push. Bring each across with `git cherry-pick <hash>` onto
`claude/alpha-wip` after its one check, then remove the checkout (`git worktree remove`) and delete its branch. B2b (plan item 10, the
erasure pages' addresses) starts after B2a. Each builder's document lines wait in its `…--build-evidence/DOC-ENTRIES.md`; add them at
its commit.

**The plan for the next block is written: `.claude-work/resume/rollout-F--plan.md`** (422 lines; nine packages F1 to F9; read its
summary and its section 4.1, "what runs beside what"). The short of it:

- F1 several organisations (#502, migration 211), then F2 a members-only gate (#511's main hole), then F3 the channel read from a
  file, then F4 no built-in address (migration 212) beside F8 link sweep part 1, then F5 the installer beside F9 link sweep part 2
  (four slices). **F1 waits for B2a, B2b and E to be committed**: it shares `Auth.php` and `full_schema.sql` with them.
- F6 and F7 share no file with anything, so they were started at once (07:15). F6's guide (`docs/INSTALL.md`) and its
  install-from-the-zip proof are a part 2, after F3 and F5.
- The planner ran it: session mode puts everybody on organisation 1 (#502 is real); in the two address ways a member of one
  organisation could open another's dashboard (#511); the installer has no proof of ownership; the bare addresses number about
  2,000 in 495 files, not 905.
- **The owner answered the plan's decisions on 12 October at about 07:20 (final; do not reopen):**
  1. **One way per installation**, chosen on a set-up page and changeable later; all three ways built and proved. Not mixed.
  2. **The members-only gate (F2, #511's main hole) is IN this block, and strict**: a signed-in person who is not a member sees the
     refusal page on every address of that organisation, public pages included.
  3. **The staff web folder is `admin_html`** in the zip package and on the server (the repository folder keeps its name).
  4. **The four workflow changes W1 to W4 (the plan's section 5) are APPROVED OUTRIGHT**: replace `deploy.yml`; new `package.yml`
     and delete `release.yml`; three report-only blocks in `pr-security.yml`; the new self-tests in the test workflow. The owner
     chose this over "read the two big files first". Place each only as the plan describes it, only after its package has passed
     its check, and `deploy.yml` not before F3 is committed (it reads a field F3 adds). Change nothing else in any workflow file,
     and no `continue-on-error`. Placing a file is not running it: the first real deploy is a dry run the owner starts.
  5. **The app-folder link sweep (F9) finishes before roll-out, but last.** Until then the set-up page marks "first part of the
     address" as not ready and refuses it.
  6. **Session mode and signed-out visitors: accepted.** They see the default organisation's sign-in and public pages; the set-up
     page and the help say so. Two organisations that both need public pages use one of the address ways.
  Still with the owner, not a decision: the live address shows the installer to anyone until it is restricted in the hosting panel.

**Learned from the three builds, 12 October, about 07:30 (act on these):**

- **Package D found three faults in the portal's own backup, and the owner added all three to the roll-out set as ONE package, G**
  (owner, 12 October): #577 no snapshot can be made once one imported calendar event exists, so the upgrade page refuses (our own
  calendar import introduced it; it must not be released); #578 a snapshot holding a record that points at a later record of the
  same table can never be restored, and the check-only run says it can; #579 snapshot and restore run out of memory at 128 MB.
  All in `web/_core/DbBackup.php`. The plan is being written. The runbook says what is true TODAY; G corrects it in its own commit.
- **The owner also said yes to one more workflow line**: `check_docs_table_refs.py` as a report-only block in `pr-security.yml`,
  placed with the three blocks approved earlier today (W3). Nothing else in that file changes.
- **D waits on B2a in one sentence**: runbook 7.7 says switching an account off does not end an open session. B2a makes that
  untrue. Correct the sentence, and row 17 of the runbook's "What was tested" table, when both are on the branch.
- **B2a put two files in its commit that the plan did not list**: `web/public_html/assets/js/livechat-widget.js` (the chat box now
  sends the sign-in cookie to the portal's own site, so internal chat works for members inside the portal; NOT proved in a
  browser; its checker is told to attack it) and `tools/expense-rules-selftest.php` (it pinned five redirect lines).
- **B2a found two more places that show venue bookings to any signed-in account**, outside its file list:
  `web/_apps/venues/venue.php` and the venue strip in `web/_apps/calendar/index.php` (about line 448). The owner's decision was to
  close all venue pages, so these are fixed straight after B2a's check, with whatever the check finds, in one fix round.
- **Four comments in other files are now untrue** (`GdprEraser.php:66-76`, `admin/livestream/dashboard.php:50-52`,
  `check_event_visibility.py:236-243`, `help/venues.php:90`); wording is in B2a's `DOC-ENTRIES.md`. B2b takes the first; the
  fix round takes the rest.
- **E's builder found 25 settings with an empty stored default on an upgraded 6 March database**; its checker is asked whether
  anything reads them. E's proposed workflow diff is in its evidence folder and is part of the approved test-workflow change.
- Issues opened from the builders' "Found" lists: #577 to #585.

**After D's check, 12 October, about 08:00:**

- The checker tried a fix for each of the four serious findings (its evidence 19 and 20), so the fix builder starts from fixes
  already shown to work and re-proves each. Plan: read the result, then commit D on those proofs with no further check round,
  and say so in the commit message. The owner was told and may ask for a second check on the script, which deletes backup copies.
- **Package G gains one fault**: `DbBackup::prune()` appears to count half-made snapshot folders when keeping "the newest N", so
  failed snapshots can push good ones out (read, not run; the planner was told to run it). The planner also found that a
  half-made folder is reported as a FRESH backup by the freshness job and the health page.
- **F7's proposed workflow goes beyond W1 as the owner approved it** (a new "adopt existing folder" tick-box; the health check
  skipped in a dry run; two extra questions in the script). Its checker lists every difference. **Show the owner that list before
  placing `deploy.yml`**: the approval covers W1 as the plan describes it, not more.
- #586 opened: an administrator setting a password for an account that has none is told it was saved, and nothing is stored.

**From F6's build, 12 October, about 08:10:**

- **The deploy has been fetching dompdf (the library that makes PDFs) in a form the portal cannot load**: the tag's source
  archive has no `autoload.inc.php`, which `web/_core/Pdf.php` requires. The builder ran both: old download fails, the publisher's
  release file makes a real PDF. `tools/download-dompdf.sh` now fetches the release file, pinned by SHA-256. Today's `deploy.yml`
  calls the same script, so the next deploy uploads the new layout. Whether PDFs work on any live server today is NOT known. The
  checker is told to prove both halves with the portal's own `Pdf` class and to confirm today's workflow still calls it correctly.
- A build from the branch is refused until F4 removes five lines that carry the first customer's domain, and a package is not
  installable until F3. Both are correct and are said in `tools/package/README.md`.
- The real `gitleaks` is not installed on this machine; the secrets guard was proved with a stand-in. The checker runs the real one.
- F6's proposed `package.yml` differs from W2 in four ways (listed in its header). As with F7: show the owner the checker's list
  of differences before placing it.
- F6's commit message gives a check count (94) that later grew to 103. When bringing the commit across, write the message afresh.

**Owner answers and check results, 12 October, about 08:45 (final where they are the owner's):**

- **Package G, the owner's three answers:** the check-only run becomes a FULL REHEARSAL; a "Check this snapshot" button is added
  to the backup page IN this package; the new backup self-test and the column-type check run automatically on GitHub (one new
  workflow file, one report-only block in `pr-security.yml`). The builder was told by message. Its proposed workflow text goes
  in its evidence folder; place it after G's check.
- **Workflow changes approved so far, all of them, so nobody has to piece it together:** W1 `deploy.yml` replaced; W2
  `package.yml` new and `release.yml` deleted; W3 three report-only blocks in `pr-security.yml`; W4 the new self-tests in the
  test workflow; plus one block for `check_docs_table_refs.py`; plus G's workflow file and its one block; plus E's proposed diff
  (#556). **Before placing `deploy.yml` or `package.yml`, show the owner the list of where the proposal differs from what the
  plan described** (each checker wrote that table). Nothing else in any workflow file changes; no `continue-on-error`.
- **B2a's check** (`rollout-B2a--check.md`): the access rules and the session change do what the owner decided; it could not
  sign an active person out wrongly or keep a switched-off one in. Real faults: another address of the same site can post into an
  internal event's chat through a signed-in member (proved in a real browser); three planted faults the tests let through; the
  new address check can pass without reading a folder; untrue comments. And owner decision 1 is NOT yet met: `venues/venue.php`
  shows a venue's address and bookings to any signed-in account, and the calendar's venue strip shows booked days to a
  signed-OUT visitor. All in the fix round.
- **Routed to F2, not fixed in B2a — put both in F2's brief with a named test:** `/api/attendance/list` gives any signed-in
  account another organisation's session list with notes, and `/attendance` shows them head counts. F2's one members-only gate
  must cover data endpoints as well as pages.
- **D's fix round** fixed every finding except one LOW item, now stated as a limit (the off-site script does not check a
  snapshot's files against its manifest). Because the script deletes backup copies and the fix is a new design, it gets a narrow
  check before D is committed. After D is committed: apply G's `RUNBOOK-CORRECTIONS.md`, and correct runbook 7.7 for B2a.
- **F7's check**: 328 hostile settings and all 576 login combinations gave a right answer or a refusal. Faults: `remote` calls
  an occupied folder "no portal yet"; six planted faults pass the self-test; in the PROPOSAL a dry run prints the password and
  signing in with a key cannot work (both proved with the real `lftp` in a container, with tried fixes).

**E's check, 12 October, about 08:55:**

- **HIGH, routed to G (told by message):** after migration 210 adds the link from attendance sessions to events, the snapshot the
  upgrade page took just BEFORE it can no longer be fully restored if it holds one pointer at a deleted event (error 1452), and
  today's check-only run says it can. G's restore is to empty such a pointer, count it and report it, ONLY where the link's own
  rule says "empty the pointer when the parent is deleted"; any other link still refuses. **E must not be released without G.**
- E's own fix round: the install script gains the link (a database built from it alone recorded 210 as run but never got it);
  the migration test keeps the install-script-alone lists so a wrong address target can no longer pass; three planted sessions
  test the one statement that changes stored data; a leftover address fails; the frozen fixture gets a checksum.
- The 25 empty stored defaults on an upgraded database are NOT a fault today: nothing reads a stored default while the portal runs.
- **The machine is at its limit.** Six agents, most with their own database containers, pushed the load average past 200, and
  one checker's container stopped by itself (it believes Docker ran out of memory; its results were already saved). Do not add
  another database-heavy job while six are running, and treat a container that vanishes mid-run as "run it again", not as a result.

**From G's build, 12 October, about 10:00:**

- New snapshot form: one file per table, one row per line (`.jsonl`), read and written a row at a time; raw bytes as base64.
  60,000 rows snapshot and restore inside a 32 MB limit. An old-form snapshot still restores; the OLD code refuses a new-form one.
- "Check only" is a full rehearsal behind one constant, `CHECK_ONLY_IS_REHEARSAL`. A rehearsal that does not undo IS a restore,
  so the checker is told to attack that first after exactness.
- A dead pointer is emptied on restore ONLY where the link's own rule is SET NULL and the column may be empty; it is counted in
  `pointers_emptied`. This is what makes E safe to release: **E and G go out together.**
- **To place after G's check, all in `rollout-G--build-evidence/`:** `RUNBOOK-CORRECTIONS.md` (every old and new sentence for
  the two documents D committed; section 2.1's heading changes, so its anchor changes in five places), `DOC-ENTRIES.md`,
  `proposed-backup-selftest.yml`, and `proposed-pr-security-step.md` (a fragment; it also needs four small lines in "Post
  findings" that are described, not written as a patch). The workflow pieces are approved by the owner.
- **One small fix is owed straight after the check (my call, told to the owner):** the upgrade page stores "Backup failed" and
  "Invalid or expired form token" and never shows them, so an administrator sees the page come back with nothing said. About six
  lines in `web/_install/upgrade.php`, a file G already changes. The checker is asked for the exact fix and its check.
- The builder did NOT run the by-hand page checklist (how the changed pages look in a browser). It is owed before the push.

**G's check, 12 October, about 11:35 — what the fix round was told:**

- M1: on a database connection set loosely (some shared hosts), a restore said yes while cutting values short. The restore's own
  session is to be made strict and put back afterwards. M2/L2: the backup page's Delete followed a link out of the backups folder;
  one name test for every action, before anything is closed or changed. M3: the upgrade page shows the message it stored (the
  checker tried the fix). **My call, told to the owner:** the "backup failed" message says what went wrong and what to do; it no
  longer names the setting that skips the backup. M4: the proposed workflow stayed green when a self-test failed (a pipe hid the
  exit code). M5: six proofs for six planted faults that passed.
- Also to be fixed there: a restore that affects rows in tables NOT in the snapshot must say so; a table that is not InnoDB is
  refused before anything changes; the two Restore buttons leave a portal closed if an administrator had closed it.
- The corrected `RUNBOOK-CORRECTIONS.md`, `DOC-ENTRIES.md` and workflow pieces will be in `rollout-G--fix-evidence/`; use THOSE,
  not the builder's.

**G's narrow check, 12 October, about 15:00 — what the last fix round was told, and one question for the owner:**

- On a database server that is run loosely, a value that is not on its column's list of choices is stored as an empty text, and
  a snapshot holding one can never be put back whole (the restore refuses it on purpose; a strict session will not take it in any
  form). Nobody was told until the restore was needed. The fix round makes `snapshot()` count such cells and say so, on the
  backup page, the upgrade page and the health page; my lean, given to the builder, is that the upgrade page stops when its own
  snapshot carries that warning, with a deliberate way on for a global administrator.
- **The portal never sets the database's strictness itself** (it runs with whatever the host has), which is how a loose host
  can store such a value at all, and how #587 stored an empty status. Whether the portal should make its OWN connection strict
  was put to the owner, who said YES on 12 October: a small package of its own (K), before F1 takes `bootstrap.php`.
- Also in the fix round: the server's notes are switched on for the restore's session; a row larger than the server's limit is
  refused in words before it is sent; Inspect refuses a link; an outside table that points at itself is counted; a proof for
  outside tables followed onward. The code will say truthfully which changes of column type a restore cannot see (seven kinds
  are known); the durable fix, recording each column's type in the manifest, is a later issue.

**From M1's build, 12 October, about 17:00 — things the later mail packages must be told:**

- **The library has two certificate modes, not three**: fully checked, and "this one certificate only" for one send. The
  owner's third way through ("switch checking off behind a warning") is NOT in the library. **M4 must add it to the library on
  purpose, with its own proofs**; the plan's M4 lists no library file to change.
- **A refused SIGN-IN comes back as "refused"**, with `SendResult::isAboutTheMessage()` to tell it from a refused MESSAGE.
  **M3's queue must never drop mail because the mailbox's sign-in was refused**: it holds the queue and tells the global
  administrator. Otherwise one wrong password throws away every password reset.
- One send can take up to about 66 seconds in the worst case, not the plan's 30. All recipients or none. One message to several
  people shows each the others' addresses (said in the README).
- **Found, and the owner must be asked (it is a workflow file):** the Psalm job on GitHub analyses NOTHING and still reports
  success ("Could not find any composer autoloaders"; an empty report is uploaded). A check that cannot fail.

**A habit that worked today, keep it:** when a package's checkout was cut before other packages were committed, make a fresh
checkout from the working branch, `git cherry-pick` the builder's commit onto it, and have the CHECK run against that. Then the
check covers the combination, and the commit that is brought across is the one that was checked (K as `f110f89`, H1 as
`654bf98`, M1 as `f34d677`).

**A trap in this session's own tooling:** a guard refuses any single command that both mentions the default branch's name
anywhere in its text and sends commits to GitHub. Keep the sending command on its own.

**From H2's build, 12 October, about 17:40:**

- **The owner was asked and chose (final): the sign-in block stays as built.** No more than 5 wrong tries in any 15 minutes: a
  block ends when the OLDEST of the five is 15 minutes old. For five in a row that is "15 minutes after the fifth"; five spread
  over 14 minutes block for 1 minute. The screen tells the person the real time left. The limiter's blocking rule, which
  Microsoft and Google sign-in also read, is NOT changed.
- The builder changed `web/_core/RateLimiter.php` (not in the plan): the minutes left are now asked of the database, because the
  page's clock and the database's differed and the page said "try again in 0 minutes" with 15 to go.
- **To be settled by H2's check, by running it:** whether a mail secret saved through the portal's own settings page reaches
  the mail service intact. Start-up never decrypts settings marked encrypted (#497: a flag is compared with the text `'1'` and
  the database hands back the number 1). If the page encrypts on save and nothing decrypts, Microsoft 365 mail cannot work for
  anybody who sets it up through the page. If so, #497 (or the smallest part of it) has to join the roll-out set: ask the owner.

**Trap, learned the hard way today:** the Agent tool's own "separate checkout" option (`isolation: worktree`) cuts the checkout from
`main`, which is about 400 commits behind the working branch, and refuses writes to the main folder. A builder started that way found
none of the code it was told to change, and rightly stopped. **Do not use it.** Make the checkout by hand instead:
`git worktree add -b <branch> .claude-work/worktrees/<name> claude/alpha-wip`, and tell the builder to work there.

**Two owner answers, 12 October, about 06:25 (final):**

1. **How one installation serves several organisations (#502).** The owner: "Best scenario is that all three are possible (own
   address each, first part, or sign in session. Ideally it is sign-in session, but what if a signed in user has access to multiple
   organisations - a real possibility)". So all three ways must work: each organisation on its own address; the organisation as the
   first part of the address; and the organisation chosen by the sign-in session, which is the preferred one. Session mode therefore
   needs a way for a person who belongs to several organisations to choose one at sign-in and switch later. "First part of the
   address" needs every link in the portal to be built through `Site::url()`: that is the #536 sweep, about 905 bare links. NOT yet
   planned — it is planned with #499, #500 and #502, after the roll-out set.
2. **Live chat on an internal event (B2a item 4):** yes to the fix. An internal event's chat works only inside the portal, for people
   who may see that event; the chat box embedded on a public website works for public events only.

## OWNER DECISIONS, 11 OCTOBER 2026 — THE ROLL-OUT PLAN (final; this is the queue now)

The owner: "we really are wanting to get through this so we can try and finalise and begin roll-out usage — so lets focus on this and
cleaning up anything else now". Asked up front, with facts from `.claude-work/resume/rollout-issue-status.md` (85 open issues
classified: 37 already fixed on this branch, 38 not started, 7 partly):

1. **The first roll-out is an organisation the owner is involved in — AND it includes #499 (zip installer), #500 (no built-in
   address) and #502 (multi-organisation "session" mode).**
2. **Must-fix before roll-out — "the roll-out set":** #510 (erasure stops part-way, critical), #535 (erasure skips four tables), #545
   (self-approval of expenses), #547 (my-volunteering crashes), #568 (wrong library fingerprints), #570 (wrong app name), #563
   (`set_time_limit` crash), #478 (a switched-off app's data endpoints still answer), the rest of #534 (items 3-4), #488 (make the
   recovery runbook true). **Everything else waits until after roll-out** (#569's audit and push check, #564, the upgrade test and
   #553-#556, #558-#562, #549 and the rest) unless the owner pulls it forward.
3. **#514 parts 9, 10 and 11 are finished BEFORE roll-out** (not deferred).
4. **Codex: the riskiest parts only, NOT a gate, and only once the queued items are done.** A fresh Opus agent's independent check
   before a commit stays available as an option. **"Minimise multiple rounds of code reviews, wasting potential codex
   usage/credits/tokens."** So: one independent check per code package, sized to the risk; tiny wording-and-test fixes after it are
   committed on the builder's proofs without another round; Codex gets ONE lean, security-first pass over the riskiest areas at the end
   (sign-in and permissions, erasure, expenses and payments, calendar import), split across its resets, blocking nothing.

**#514 PART 8 IS COMMITTED: `6abed16` (11 Oct ~17:35), pushed; #514 commented.** 799 checks; final whole-part check by an Opus
stand-in for Codex; the last narrow check's two LOW faults + four uncaught preview faults fixed and committed on the builder's proofs
(no further round, per decision 4). Follow-ups opened: #571 (a very long series can lose dates on an every-date choice), #572
(migration 207's deprecated `VALUES()`), #573 (the result message off screen on a phone). Still to record for the Codex pass: part 8
is one of its "riskiest parts". Evidence for all 46+ rounds: `.claude-work/resume/p514-p8-*`.
**THE ROLL-OUT SET IS PLANNED** — `.claude-work/resume/rollout-set--plan.md` (407 lines; no database change; every fix has a check
that fails today). Packages, one in flight at a time, ONE independent check each: **A erasure** (#510, #535 — Opus; #535's four named
tables already have instructions, what is really skipped is `tblExternalFeedRuns`, `tblRecording.presenterID` and eight catalogue
entries); **B access and money** (#478, #570, #545, rest of #534 — Opus; two confirmed leaks: `calendar/api/hub-resources.php`,
`hub-videos.php`); **C broken pages** (#547, #568, #563 — Sonnet; a FOURTH wrong fingerprint in `web/_install/index.php:1286`);
**D the recovery runbook** (#488 — Opus; commands must be proved by running them). The plan's decisions 1-7 follow its
recommendations. Found list F1-F11 in the plan.
**OWNER, 11 Oct ~18:10:** (8) **Gift Aid declarations are KEPT on erasure, with the reason recorded** (builder A told); (9) **the
twelve tables that link to an account but are not in the erasure list JOIN the roll-out set** — package A2, straight after A (same
files), once the owner has settled each table (builder A lists what each holds); **fresh install or upgrade: "not sure yet" → #553
and #555 JOIN the roll-out set** as package E, with a test that upgrades from the last release; the stray folder — DONE (the #516
report moved to `.claude-work/resume/p516--fix-r1.md`; the empty misnamed folders removed).
**PACKAGE A BUILT (11 Oct ~19:30), NOT committed — nine files, snapshot `.claude-work/resume/rollout-A-snapshot/`:** an erasure now
finishes (every erasure used to crash on `fk_ea_request` — #510 reproduced on a real database), keeps and closes its own request last,
writes one audit line per instruction; Gift Aid declarations KEPT (owner); `tblExternalFeedRuns`, `tblRecording.presenterID` and others
now reached; four lists said truthfully to be unreachable; `/account/my-data` lists the person's own request again. 68/68 and 26/26;
16 planted faults caught. The builder went beyond the plan in six small ways (its report) — the checker judges them.
**NOW: PACKAGE A's ONE CHECK** (fresh Opus, Codex stand-in; brief `.claude-work/briefs/rollout-A-check.md`; report
`.claude-work/resume/rollout-A--check.md`; watchdog).
**PACKAGE A IS COMMITTED: `3750fb0` (11 Oct ~20:40), pushed; #510 and #535 commented.** One independent check (Opus, Codex stand-in:
a real erasure on linked records — nothing lost or leaked, permissions hold); its one untrue audit line (`tblCareVisit`) and four
test gaps fixed and committed on the builder's proofs, no second round. 79 + 26 checks; 21 faults caught.
**NOW: PACKAGE B BUILD** (access and money: #478, #570, #545, rest of #534; fresh Opus builder; brief = the plan's Package B; report
`.claude-work/resume/rollout-B--build.md`; watchdog). Then ONE check, then commit; then C (Sonnet), D, E.
**Research, report 1 DONE** (`.claude-work/resume/donation-retention-research.md`, 24 countries): periods run from 3-4 years (US) to
10 (Germany, Italy, Philippines); most count from the END OF THE YEAR of the last gift, not the gift date; several extend during an
audit (→ a "hold" switch); donor-identifying records required in 15 of 24, not in Australia, NOT FOUND for 7; most data-protection
laws read allow keeping what another law requires. Report 2 (38 more countries, every continent) still running.
For A2 from A's check: a "processing" request of the same account stays stuck; another ORGANISATION's waiting request is closed too;
deleting a Zoom account cascades into kept `tblZoomMeeting`; a pledge delete empties `pledgeID` on kept gifts; more unlisted "who did
it" columns (`tblExpenseClaimPayments.paidByID`, `tblKidCheckins.checkedIn/OutByID`, `tblErasureRequest.processedByID`,
`tblCareVisit.followUpAssignedToID`); nothing can put back a request stuck at "processing"; Cancel can cancel a completed request.

**PACKAGE A2 — THE OWNER'S DECISIONS ARE COMPLETE (11 Oct ~21:15): `.claude-work/briefs/rollout-A2-decisions.md` is the brief.**
Others' records: keep, clear the worker. "Who did it" columns: keep the record, clear who did it. Giving records, country-neutral:
identity kept only on tax-related gifts by default (organisation may switch to all gifts), for a per-organisation number of years
counted from the END OF THE FINANCIAL YEAR of the last gift, suggested per country from a JSON data file (source strength + link),
**10 years, clearly flagged, for an unknown country**, confirmed once by an administrator, a "hold" switch for audits, cleared
automatically by the retention job. Research 2 DONE (`…-research-2.md`, 40 countries: 5-10 years; donor records follow the general
tax/accounting period almost everywhere; six countries' erasure exception weak or unclear). **Running now (all with watchdogs):
the A2 PLAN (Opus, read-only) → `.claude-work/resume/rollout-A2--plan.md`; research 3, the rest of the world →
`…-research-3.md`; a re-check of the weak entries against primary sources → `…-research-recheck.md`** (the owner asked whether to
expand the research: yes, in the background — it only adds rows to the data file and blocks nothing).

**A2 IS PLANNED AND DECIDED (11 Oct ~22:00).** Plan: `.claude-work/resume/rollout-A2--plan.md` (two packages: **A2a** others'
records + "who did it" columns + smaller items + "Delete my account" filing an erasure request + photo and biography cleared —
migration 208, Sonnet per the plan but see the note; **A2b** the giving-records feature — migration 209, Opus). **The owner's answers
override the plan where they differ — all in `.claude-work/briefs/rollout-A2-decisions.md` section 5:** a country PER ORGANISATION
now; statement PDFs KEPT with the tax evidence; no clearing before "confirmed"; minimum suggestion 6 years; "Delete my account" files
an erasure request (in A2a); the new tests go into the pull-request checks (workflow edit APPROVED, one change after A2b).
Research 3 DONE (`…-research-3.md`, 147 rows: the rest of the world; mostly one 2019 secondary survey). Re-check of weak entries
still running (`…-research-recheck.md`).
**PACKAGE B IS BUILT (24 files, NOT committed; snapshot `.claude-work/resume/rollout-B-snapshot/`) and IN ITS ONE CHECK** (fresh Opus;
brief `.claude-work/briefs/rollout-B-check.md`; report `.claude-work/resume/rollout-B--check.md`; watchdog). Built: the app switch
now gates every `/api/…` address of a switched-off app (two hand-written lists in `AppRegistry`; an unlisted `api` folder is
REFUSED); nobody can approve, reject or pay their OWN expense claim (`ExpenseRules`, new); the two Team Hub data endpoints and the
venue booking card follow `EventVisibility`; the "app is disabled" page names the right app. 77 + 46 + 829 checks; 17/17 faults.
**OWNER: ALL of builder B's Found items join the roll-out set → package B2** (plan after B commits): `admin/calendar/
registrations-act.php` has no organisation test; `attendance/record/save.php` lets any signed-in account record figures; the venue
booking itself shows across organisations; the Team Hub data endpoints are wider than the Team Hub page; `livechat/api/list.php` and
`prompts.php` need no sign-in (with #548); `live/index.php` reveals which event numbers exist; three handlers store any event number
they are sent (`kids/checkin-do.php`, `salvation/card-save.php`, `livestream/api/ping.php`); expense handlers redirect to bare
addresses; the `AppRegistry::all()` comment; `FEATURES.md` and `api-spec.json` not updated for B.
**PACKAGE B IS COMMITTED: `6223e2e` (11 Oct ~23:10), pushed; #545, #478, #570, #534 commented.** One check: PASS, no real fault. Seven
planted faults that passed the tests were closed afterwards (tests only; the access and money code byte for byte as checked — I
compared it with the snapshot). New committed tests: `tools/expense-rules-selftest.php` (50), `tools/app-switch-selftest.php` (86
with a database), `tools/access-pages-selftest.php` (94, drives real pages; must not run at the same moment as
`feed-admin-selftest.php` on one database); `feed-admin-selftest.php` is now 831.
**NOW: PACKAGE A2a BUILD** (fresh OPUS builder — the plan said Sonnet, but this decides what personal data is deleted or kept and now
rebuilds "Delete my account"; brief = `.claude-work/briefs/rollout-A2-decisions.md` + the plan's A2a; migration 208; report
`.claude-work/resume/rollout-A2a--build.md`; watchdog). Research re-check DONE (`…-research-recheck.md`: 29 entries, 24 now on
primary text; Philippines 5 by statute but keep 10 as the cautious figure; Bangladesh has no official figure; Zambia 6; Ecuador,
Zambia and Morocco have clear exceptions; France 10 confirmed) — A2b's builder merges all four files.

**PACKAGE A2a IS BUILT (12 Oct ~01:00; 19 files, NOT committed; snapshot `.claude-work/resume/rollout-A2a-snapshot/`) and IN ITS ONE
CHECK** (fresh Opus; brief `.claude-work/briefs/rollout-A2a-check.md`; report `.claude-work/resume/rollout-A2a--check.md`; watchdog).
Built: other people's records kept, worker cleared (delete on an "acted on it" column now refused by construction); the eraser builds
one instruction for EVERY person column a table has (195 instructions; 31 of 37 extra "who did it" columns cleared); the person's own
visitor record deleted; photo, biography, `calendarToken`, `notifyPrefs` cleared; **"Delete my account" files a CONFIRMED erasure
request through `GdprEraser::fileRequest()` and changes nothing itself**; an administrator can "Confirm on the person's behalf";
a request stuck at "processing" can be put back (15 minutes, `claimedAt` in UTC); migration **208** (four columns emptiable;
`tblErasureRequest.claimedAt`), proved on 8.0.36 and 8.4.11 and from the previous release. 126 + 40 checks; 75 faults caught.
**Six columns left pinned in `KNOWN_STILL_NAMED`, needing decisions:** five may not be empty (`tblAssetLicenseAssignments.linkedByID`,
`tblAssetLoans.requestedByID`, `tblOffboarding.offboardedByID`, `tblResourceBooking.bookedByID`, `tblRotaSwapRequest.requestedByID`
— a migration); `tblInvitation.acceptedByID` (the row holds the invited person's own email address — delete an accepted invitation
with its person?). **For the owner's safeguarding lead:** who made a pastoral visit, and who recorded a DBS check, are now cleared.
Builder's other Found items: its report, list items 2, 4, 6-13, 16, 17.
**PACKAGE A2a IS COMMITTED: `f58fdd2` (12 Oct ~02:10), pushed; issue #574 opened for it (and for A2b).** 26 files; migration 208
(nine "who did it" columns emptiable; `tblErasureRequest.claimedAt`); 132 + 43 + 38 checks on 8.0.36 and 8.4.11; migration test on
both; upgrade from `6223e2e` equals a fresh install; 115 faults caught. No second check (owner's instruction).
**NOT BUILT, moved to package B2:** ending an erased person's open session — the portal keeps sessions as PHP's own files, and
`Auth::check()` (`web/_core/Auth.php:556`) asks only whether the session holds an account number; it must also confirm
`tblUsers.isActive = 1` once per request and destroy the session when not. **Also for B2:** the erasure pages write bare addresses
(`/admin/erasure-requests/...`, `/account/...`) instead of `Site::url()`, so in first-part-of-the-address mode their buttons reach
the default organisation (read, not run; #536's family). **Decisions still open:** `tblWorkflowSteps.assigneeValue` (an approval
step given to one named person — what should it become when they are erased?); the safeguarding names (cleared as built).
**NOW: PACKAGE A2b BUILD** (giving records, country-neutral; fresh Opus builder; brief `.claude-work/briefs/rollout-A2-decisions.md`
sections 3 and 5 + the plan's section 2; migration 209; the four research files merged into a JSON data file with a schema and copied
to `docs/research/giving-retention/`; report `.claude-work/resume/rollout-A2b--build.md`; watchdog). Then ONE check → commit → B2 → C
→ D → E → #499/#500/#502 → #514 parts 9-11 → the workflow edit → documentation sweep → Codex pass.

**PACKAGE A2b IS BUILT (12 Oct ~03:30; 30 files, NOT committed; snapshot `.claude-work/resume/rollout-A2b-snapshot/`) and IN ITS ONE
CHECK** (fresh Opus; brief `.claude-work/briefs/rollout-A2b-check.md`; report `.claude-work/resume/rollout-A2b--check.md`; watchdog).
Built: `GivingRetention` (new) + `tblGivingKeptDonors` (migration **209**, seven settings, two addresses); a donor's name and address
kept on tax-related gifts (or all gifts, the organisation's choice) until the end of the financial year of the last kept gift plus the
organisation's years; a country PER ORGANISATION (`giving.retention.countryCode`); suggestions from
`web/_core/data/giving-retention-countries.json` (211 countries, schema + check; never under 6; flagged 10 when unknown); nothing
cleared before "confirmed" or while on hold; statement files kept with the evidence; the page Admin → Giving records
(`/admin/settings/giving-records`); research copied to `docs/research/giving-retention/`. 54 + 53 + 188 + 48 checks on 8.0.36 and
8.4.11; 71 faults caught. The builder left the plan in twelve places (its report) — the checker judges them.
**A2b's ONE CHECK (12 Oct ~04:15): NOT CLEAN — one MEDIUM, three LOW** (end of `.claude-work/resume/rollout-A2b--check.md`; proofs
`scenario2.php`, `scenario3.php` in its evidence). Held: nothing cleared early on any not-confirmed, hold, date, year-end, zone or
other-organisation path (21 donors, 12 organisations; 997 instants in 15 zones); no name kept without a reason; the words never state
a law as fact; data file sound; migration 209 right. **F1 MEDIUM:** a kept row made by a run that did NOT finish is never brought up
to date and the clearing guard never checks the account was emptied — a gift recorded between a stopped run and its re-run is
cleared a year early; and if the stopped request is cancelled, a LIVE member is treated as an erased donor (`isErasedDonor()` true)
and the sweep took the donor off a five-day-old gift. F2 an untrue privacy-page sentence; F3 no test for "country change un-ticks
confirmed"; F4 a changelog sentence.
**NOW: A2b FIX ROUND (same Opus builder)** — F1-F4, plus: keep a self-downloaded statement when every gift on it is kept; lock the
seven settings out of the general `/settings` editor; show the installation-wide and the organisation's hold separately; an open
count's envelope keeps its giver for a kept donor; Uruguay's flag; a test for the "no request left" guard. Watchdog on
`.claude-work/resume/rollout-A2b--build.md`. **THEN a NARROW independent check of the F1 fix only** (tax evidence cleared early
cannot be undone — a check sized to that risk; everything else goes in on the builder's proofs) → commit → B2.

**A2b FIX ROUND 1 DONE (~05:10)** (F1 by four rules: "emptied" = switched off AND "[Deleted User]" AND a deleted address, one
definition; keep-until from the LATER of stored date / newest linked gift / newest linked envelope; later runs recalculate and never
write a made-up name over a real one; a request whose giving step has run cannot be cancelled. 210 + 59 + 56 + 54 checks on both
versions; 43 faults). Snapshot `.claude-work/resume/rollout-A2b-snapshot2/`.
**NARROW CHECK OF THAT FIX (~05:40): NOT CLEAN — it found a fault the fix introduced** (end of
`.claude-work/resume/rollout-A2b--check2.md`; proofs `c2-scenario.php` E3, `c2-race.php`): **C2-1 MEDIUM** — when the account is
ALREADY emptied, a later run re-sorts the gifts by TODAY's "which gifts" rule and its "nothing to keep" branch empties the kept name
with no guard (no hold, no confirmation, no date); C2-2 LOW a milliseconds gap (the clearing decides before its transaction); C2-3
LOW untrue words ("the account is emptied last" — it is step 101 of 207). Everything else in the four rules held (four stops of one
request; seven near-miss accounts; every way round the cancel refusal).
**NOW: A2b FIX ROUND 2 (same builder)** — C2-1 (sort by the rule STORED on the kept row; never the unguarded branch), C2-2 (re-read
inside the lock; files deleted after), C2-3, plus: refuse to cancel whenever an earlier run deleted or emptied ANYTHING (a live
member lost their membership row), and behaviour tests for the two key details. **THEN the same checker (`a6aac53c844c9c476`)
re-runs its own two proofs against the fix — minutes, not a round — and A2b is committed.**

**PACKAGE A2b IS COMMITTED: `fd08b7f` (12 Oct ~07:45), pushed; #574 commented.** 33 files; migration 209; 223 + 60 + 57 + 54 checks on
8.0.36 and 8.4.11; 110 faults. Reviewed by one full check, a narrow check of the F1 fix (found C2-1, which the fix had introduced),
that checker's re-run (found C2-4, a small mirror), all fixed; the checker's last probe line compares two different people's numbers
and cannot pass as written — I read it and confirmed (his gift now carries his number; 3 kept gifts, was 2).
**THE PLAN FOR B2, D AND E IS IN: `.claude-work/resume/rollout-B2-D-E--plan.md`** (481 lines; a table of files per package and what
each can run beside; ten owner decisions, each with a recommendation the builders follow). Its coordinator's point: "shares no
files" is not enough in ONE folder — the page tests copy all of `web/` and the migration test replays all of `web/_sql/` — so
**side-by-side builders now work in their OWN checkouts (the Agent tool's `isolation: worktree`), commit there, and the
commissioning session brings each commit across** (cherry-pick onto `claude/alpha-wip`; never pushed from the worktree).
**RUNNING NOW (12 Oct ~08:00), each with a watchdog:**
- **C's one check** (Opus; `.claude-work/resume/rollout-C--check.md`). C = exactly the 18 files in
  `.claude-work/resume/rollout-C-FILES.txt` (uncommitted in the MAIN folder); its document entries are in
  `…/rollout-C--build-evidence/DOC-ENTRIES.md` — add them at commit.
- **B2a build** (Opus, own worktree; items 1-9, 11, 12 incl. ending an erased or switched-off account's session in
  `Auth::ensureSession()`; report `…/rollout-B2a--build.md`; container `roB2a-mysql`, ports 33430-39).
- **E build** (Opus, own worktree; migration 124 corrected, new 210, new migration-test phases from `origin/main` and from a frozen
  6 March install script; workflow changes written as a diff, NOT applied; report `…/rollout-E--build.md`; ports 33440-49).
- **D build** (Opus, MAIN folder, documents only; every runbook command run, a real backup and restore; report
  `…/rollout-D--build.md`; ports 33450-59). One sentence ("lock one account out") waits for B2a.
**Then:** B2b (the erasure pages' addresses; after B2a) → #499/#500/#502 → #514 parts 9-11 → the one workflow change → documentation
sweep → Codex pass → pull request when the owner says.

**(earlier)** RUNNING SIDE BY SIDE since 12 Oct ~06:15 (owner's decision above):
- **A2b fix round 2** (Opus builder `a25b4240c3ddf008d`; report `.claude-work/resume/rollout-A2b--build.md`; watchdog) — at 05:56 its
  fixes passed on both versions (220 + 57 + 54 + 60); planting faults. Then the checker `a6aac53c844c9c476` re-runs `c2-scenario.php`
  and `c2-race.php`; then commit. **A2b's file list for staging: `.claude-work/resume/rollout-A2b-snapshot2/FINGERPRINTS.txt` plus
  whatever the builder's last addendum adds — NOT `git status`.**
- **Package C build** (Sonnet; #547, #568, #563; report `.claude-work/resume/rollout-C--build.md`; own container `roC-mysql`, ports
  33410-33419; must not touch the shared documents — its entries go to `…/rollout-C--build-evidence/DOC-ENTRIES.md`; watchdog).
- **The plan for B2, D and E** (Opus, read-only; `.claude-work/resume/rollout-B2-D-E--plan.md`; watchdog) — it states each package's
  files and what each can run beside. B2 includes ending an erased or switched-off person's session (`Auth::check()`).

**SEEN ON THE LIVE ADDRESS (12 Oct ~03:40, read-only curl): the example customer's live address answers EVERY path with the
installer's "Install — Welcome" page** — so that installation is NOT installed (which answers "fresh install or upgrade?": fresh),
and the install wizard is open to anybody who visits. Told the owner. Nothing was sent to the server.

**(history)** A2a's ONE CHECK (12 Oct ~01:15): NOT CLEAN on three small points, no data loss (end of `.claude-work/resume/rollout-A2a--check.md`):
its own erasure on linked data deleted eight rows, all the person's own (the old code lost 18, most of them other people's); 34 of 34
page attacks refused; migration 208 right. Findings: three unlinked "who did it" columns never reached (`tblEvents.submittedByID`,
`tblErrors.resolvedByID`, `tblMigrations.executedByID`) with four untrue sentences; the emptied account checked against 15 named
columns only (K19 passes); one untrue reason in `check_account_writes_guarded.py`.
**OWNER, 12 Oct ~01:00 ("just OK those now, we need to resolve them anyway"):** the five pinned columns get the database change
(extend 208 — not committed yet); an accepted invitation is DELETED with its person. NOT decided (no recommendation was given): the
safeguarding question — who made a pastoral visit / recorded a DBS check is CLEARED as built; say so in the roll-out notes.
**NOW: A2a FIX ROUND (same Opus builder; no second check)** — the three findings; the two owner items; and from the checker's Found
list: end the erased person's sessions (if that needs an `Auth` change on every page it goes to B2 instead — the builder reports);
the approval line that vanishes once its approver is erased (inner joins in `expenses/view/index.php`, `ExpensePdf.php`); a committed
page-driving test for the erasure pages; three untrue sentences on the pages. Watchdog on `.claude-work/resume/rollout-A2a--build.md`.
**THEN:** commit → A2b (giving records; migration 209; Opus) → B2 → C → D → E → #499/#500/#502 → #514
parts 9-11 → the workflow edit → documentation sweep → Codex pass.

**(done)** NEXT, in order (one package in flight; nothing edits the tree while B is being checked): B's check → fix → commit → A2a build →
check → commit → A2b → B2 → C → D → E → #499/#500/#502 → #514 parts 9-11 → the workflow edit → documentation sweep → Codex pass.

**(older note)** PACKAGE A2 (erasure, part two) — being designed with the owner: (i) erasing a follow-up worker today DELETES other people's
records (`tblVisitor`, `tblSalvationCards`, `tblTasks`, `tblRotaSlot` on `assignedToID`; `tblRotaSwapRequest` on `targetUserID`) —
OWNER: keep the record, clear the worker; (ii) nine "who did it" tables not in the list — OWNER: keep the record, clear who did it
(three columns cannot be empty today → a migration, 208); (iii) giving records — the owner asked about legal duties: HMRC requires the
donor's name and address on the declaration and a link from each claimed gift to it (sources in this session: gov.uk Chapter 3 and
7, the 2016 Regulations), so names on tax-claimed gifts must be KEPT for a period; and the owner wants it right for EVERY country,
not only the UK. Design direction: country-neutral — keep the tax evidence the organisation's own country requires for a period that
is a per-organisation setting, default suggested from `org.address.countryCode`, then removed automatically by the retention job;
everything else erased at once. **Research running** (Sonnet, facts only, 24 countries, sources quoted):
`.claude-work/resume/donation-retention-research.md`. Also for A2: the email-log step alters longer addresses (`ann@` inside `joann@`);
kept declarations carry no name today; `/account/erasure-request` prints "retaind".

**ORDER:** part 8's commit → the roll-out set → #499, #500, #502 → #514 parts 9-11 → documentation sweep → the Codex pass alongside →
the pull request when the owner says.

**At merge time:** commits `f0eb9d5` ("Closes #477", "Closes #482") and `20d7a05` ("Closes #483") would close issues that are NOT done
(the #477 page does not exist; 33 of 62 endpoints documented; three dead files remain). History cannot be rewritten — reopen those
three straight after the merge, and say so in the pull request.
**Open question for the owner (asked 11 Oct):** is the roll-out a FRESH install or an UPGRADE of an existing database? An upgrade of a
database installed before 25 June 2026 stops at migration 124 (#555), so #553/#555 would join the roll-out set.

## OWNER DECISIONS, 5 OCTOBER 2026, about 03:00 — eleven answers to questions put up front (final; do not reopen)

1. **Chunk 3 closed by carrying its last fixes into chunk 4** — accepted.
2. **The builder's two FOUND items:** the `#categories-help` phone-width fix is folded into P8-4 (builder told); the wrong app name on
   the app-switched-off page is its own issue, **#570**, fixed after part 8 alongside #569. Part 8's checks must not rely on that heading.
3. **Builder:** Opus for the rest of part 8; Sonnet for the simpler items after it (#568, #563 …); Opus still checks.
4. **Thinking effort `xhigh` → `high`** — DONE in `~/.claude/settings.json` (top level + both per-model entries). A settings-file
   change applies from the NEXT session (Claude Code's docs); `/effort high` applies it to a running one.
5. **Instruction reviews (`.claude-work/resume/instructions-review.md` and `instructions-audit-2.md`): apply EVERYTHING now** — all 103
   verdicts including the big trim of `.claude/CLAUDE.md` (history and inventory out; its two buried traps saved to DEV_NOTES first),
   the five new instructions, and the "real problem" definition. Being done by its own Opus agent (see section 2).
6. **Handoff:** move old entries into an archive file beside it, marked as history — AFTER part 8 is committed.
7. **Deny rules** for force-push, hard reset and docker prune — DONE and PROVED (each refused; the same commands without the flag
   ran). Deny only; no "ask" prompts. They cannot catch `bash -c '…'` or `/usr/bin/git …` (Claude Code's docs).
8. **The cache add-on** moved from `.claude/skills/prompt-cache-control/` to `~/.claude/skills/` — DONE; the repository no longer
   holds it, so the "leave it out of every commit" rule is retired.
9. **#569's pre-push check:** one per repository, with its own installer (the FileMoCo shape); not a global hooks folder.
10. **Helper agents: at most six at once, in writing AND enforced** by `CLAUDE_CODE_MAX_CONCURRENT_SUBAGENTS=6` — DONE. The seventh
    agent is REFUSED, not queued, and the setting is read only at session start: shape workflows to six at a time.
11. The cut-off "Also find" in the second instruction review: **nothing more** — drop it.
12. **The 01:59 edit to `~/.claude/CLAUDE.md` (5 Oct) was the owner's — keep it.** It added the five new instructions in Anthropic's
    wording, narrowed reviews to harm-capable changes, and prefers the other service over a helper as reviewer.
13. **What gets reviewed HERE: narrowed exactly as machine-wide.** Only changes that could do real harm (code, database, data,
    money, credentials, personal details, safety gates) get a review round. Documentation of every kind — instruction files, memory,
    the handoff and notes — renames and comment-only changes do not. The owner chose this knowing the instruction rewrite is then
    committed without a review round. Written into `.claude/CLAUDE.md` ("Codex review"), `.OpenAI/CONTEXT.md` rule 2, `AGENTS.md`
    and the review memory.
14. **Who checks: a fresh agent checks each code package as Codex's named stand-in; the FINAL whole-of-part-8 check goes to CODEX**
    (`codex … -c model="gpt-6-astra" < /dev/null`; read the output for a limit message — a limit can exit 0); the end-of-queue
    whole-branch Codex review still happens, possibly after a reset.
15. **Deleting a web-root folder that hides an address needs the owner's explicit approval first** (first review S13). Written into
    `.claude/CLAUDE.md` (web-root section), `.OpenAI/CONTEXT.md` rule 16 and the shadowing memory.

**Instruction work — DONE (5 Oct ~03:50):** both reviews applied in full by an Opus editor (report: end of
`.claude-work/resume/instructions-apply.md`; before-copies in `instructions-apply-before/`). `.claude/CLAUDE.md` 1,506 → ~590 lines;
nothing deleted outright — "Recent ships" and the inventories moved word for word to `.claude/history/`, three folder-only traps to
`.claude/rules/` (path-scoped). Also fixed: `.dev-team/config.yml`'s stale review comments (first review C2) and FEATURES.md's pointer
to the removed counts table. **Left for later, noted by the editor:** two plans (`.claude/plans/public-door-5…:1063-1064`,
`public-door-6…:1280`) edit sections that have moved — fix when #499/#500 is built; `common-builder.md:25-26` (a saved brief) still
promises a per-package Codex review; path rules and HTML-comment stripping are documented but not yet seen working in a live session.

## OWNER DECISION, 25 SEPTEMBER 2026, about 16:00 — `tools/watchdog.sh` shortcut fix APPROVED (recorded by a CueRCode session)
The owner approved the fix for the watchdog "always fires on a shortcut" fault: add `-L` to both `stat` calls in `mtime()` so
the script reads the real file's age, not the shortcut's. Apply it to **both copies together** — this repo's `tools/watchdog.sh`
and the device-wide `~/.claude/bin/watchdog.sh` on the owner's Mac — and keep them identical. Details, exact change and checks:
**#564**. Not done yet: the CueRCode cloud session can't reach the Mac, so a session on the Mac should do it. Until then, point
`quiet` at the real file (`ls -la` the `.output` shortcut to see where it leads).

## OWNER DECISIONS, 24 SEPTEMBER 2026, late evening — two workflow-file changes APPROVED

1. **Run `tools/audit-checks/check_fk_references_unique_key.py` on every pull request** — owner: "if it is feasible to check the
   run on each PR then yes". It is feasible: it only reads text and takes seconds, like the other twenty. Wire it into
   `.github/workflows/pr-security.yml` with **step 9's pattern** (keep the exit code, do not trim lines), so a crash or a missing
   file can never look clean.
2. **The end-to-end migration test runs on MySQL 8.4 as well as 8.0.36** — owner: "end-to-end migration test should also run on
   mysql 8.4". Both versions, not a swap: 8.0.36 stays until #475 decides otherwise. The image is set in
   `tools/e2e-migrations/docker-compose.yml`; the workflow is `.github/workflows/e2e-migrations.yml`. Prove the harness passes on
   8.4 locally before wiring it in.

**When:** straight after #552 is committed — NOT while #552's check is running (nothing may change the tree under a checker). Built
as ONE workflow package together with queue task 4 (the three calendar self-tests into the pull-request checks, approved earlier),
so the workflow files change once. Change only what was approved; never add `continue-on-error` unasked.

## DECISIONS TAKEN 24 SEPTEMBER 2026, about 09:00 — read before building anything

The owner asked for every pending decision to be raised up front. These are the answers. **Everything below the START HERE
block that contradicts them is out of date.**

**1. API keys — CHANGED, and it reverses committed code.** An API key now sees an imported event **exactly as a signed-out
visitor would**, plus a new per-calendar **"don't show via API"** box — the "show on our website" box in reverse. Unticked (the
default) = keys receive the calendar's events. **This replaces the owner's 17 September answer**, which parts 1 and 2 were built
to; the owner confirmed today's answer stands after being shown the two side by side. **CORRECTED the same afternoon:** this note first said it
WIDENS what existing installations send on upgrade. **That was wrong** — checked against `origin/alpha`, where an API key's event
list (`events/api/list.php`) filters only by organisation, not-deleted and published, and `EventVisibility` does not exist at all.
**Released versions already send keys EVERY imported event in full detail.** The "keys receive almost nothing" behaviour exists only
on this unreleased branch. So on upgrade customers keep receiving their calendars' events through keys, and **private-marked events
stop** — a narrowing. The owner was told the wrong direction and told the correction; the decision stands. The upgrade notes must
say what actually changes.
**Built into P7 and P8**, beside the website box it mirrors. Full detail at the top of `.claude-work/resume/p514--plan-r2.md`.

**2. Five design questions were re-asked by mistake and answered exactly as on 17 September** — private events hidden until
chosen; a changed event goes back for approval; later dates of a repeating event follow an approved one when nothing differs; an
organisation with no site administrator of its own has its approval notices sent to every global administrator; at "title, date
and time only" the category shows and the RSVP counts do not. **Nothing changes for any of them.** They were re-asked because the
plan's "built as recommended" notes were read as unconfirmed; the answers were at the top of the same file. Saved to memory as a
lesson: read the settled answers before asking.

**3. Codex reviews the whole branch at the END OF THE WHOLE QUEUE** — not after #514. It moves to the very end again if tasks are
added. Run it with `-c model="gpt-6-astra"` and stdin closed, e.g. `codex review --uncommitted -c model="gpt-6-astra" < /dev/null`,
or it refuses with a message naming `gpt-6-sol` — which is the model name, not a lack of credit. **The cost, said plainly:** this
makes the stretch of work Codex has not seen as long as it can be, against the machine-wide rule's advice to review frequently. The
owner chose it knowing Codex's allowance is small.

**4. Both calendar self-tests go into the pull-request checks, with a throwaway MySQL database.** GitHub starts the database
beside the check and removes it when the job ends, so nothing accumulates anywhere. Still to build — it is task 4 in the queue.

**4b. One package at a time — REAFFIRMED on 24 September.** Offered a separate git worktree so the live faults (#545, #547) could
be fixed in parallel without touching #514's files, the owner chose to keep strictly one at a time. **Do not offer it again.**

**5. Throwaway containers are removed WITH their data — now a standing rule, machine-wide and in this project.** Checking after
the owner asked found **415 left-behind database volumes taking 101 GB**, plus 8 stopped test containers from this project going
back to 10 September. Every agent had reported its clean-up as done; they removed containers and never their data. **Cleared the
same day with the owner's approval: 101.1 GB reclaimed, 425 volumes down to 2.** Kept: round 3's running checker, and
`g2ml-mysql` and `wrapper-v2`, which belong to other projects. **Every brief that lets an agent start a container must now say:
`docker run --rm` or `docker rm -v`, name it after its work, and report `docker ps -a` and the count of left-behind volumes.**

**6. Using agents rather than the workflow tool** — stated rather than asked. The rules say to use "workflows and agents" where
they fit. Background agents with a progress file are used because this project recorded that workflow agents are killed after
three minutes without output and the whole run fails; that workaround saved two builders on 23 September.

# ============================================================================
# START HERE — written 23 September 2026, about 21:00, for a COMPLETELY FRESH
# SESSION with no chat history. Everything needed to carry on is in this one
# block. Everything below it is history, kept because it explains WHY.
# ============================================================================

---

## Older entries

Everything written before 5 October 2026 (the sections that used to follow here: "0. Before you restart the session"
through the old queue and its history) is in `.claude/history/HANDOFF-archive-to-2026-10-05.md`. It is history, not
instructions.
