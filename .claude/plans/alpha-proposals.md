# Alpha proposals: what to fix, finish, improve and add before testers use WebMS-Intra in earnest

Written on the afternoon of 14 September 2026, as the last step of the full issue sweep. Nothing was written to
GitHub and no issue was created. Every proposal below is for the owner to accept, change or turn down.

---

## How to read this document

**Evidence labels.** Every claim carries one of three labels.

- **PROVEN (re-read today)**: I read the file or ran the command myself while writing this document.
- **PROVEN (sweep)**: a sweep batch read or ran it, and where it was a recommendation the double-check agent
  confirmed it (`.claude-work/sweep/batch-01.json` to `batch-09.json`, `doublecheck.json`, `project-state.md`).
  I did not re-read it for this document.
- **INFERRED**: a conclusion I drew, an estimate, or general knowledge I did not test here.

**Effort** is a judgement, so every effort figure is INFERRED:

- **Small**: about a day of building or less, plus review.
- **Medium**: several days.
- **Large**: one to three weeks.
- **Very large**: more than that.

**Risk** means what could go wrong while making the change. It does not mean the harm of leaving the problem alone;
that harm is covered under "Why it matters".

**How I ranked.** Three things are weighed together:

1. how certain the problem is;
2. how much harm it does to alpha testing while it sits there;
3. how much work it is.

A small, certain, harmful problem beats a large, speculative, nice-to-have one.

---

## The code this rests on

- **PROVEN (re-read today):** the branch is `claude/alpha-wip`. Its last eight commits are `dc193b1`, `3a1e437`,
  `6c8712b`, `3e56f25`, `4eba0ee`, `e414cf1`, `89ad68d` and `eb077e1`.
- **PROVEN (re-read today):** the working tree has a lot of uncommitted work.
  - 41 tracked files are changed: 5,286 lines added and 945 removed. That includes `Router.php`, `Auth.php`, `sw.js`,
    the demo-data page, several calendar pages and `full_schema.sql`.
  - One file is deleted (`web/_sql/demo_data.sql`).
  - Untracked new files: six files under `web/_apps/cron/`, migrations 194 and 196, the plan
    `public-door-6-configurable-domains-and-package.md`, and `.dev-team/FEATURES.md`.
- **PROVEN (sweep):** 63 commits on this branch are not in `origin/alpha`.
- **PROVEN (sweep):** alpha reports version 1.4.0, while beta and main report 1.4.1.
- `.claude-work/sweep/git.txt` was gathered at 07:49 and is out of date. I did not rely on it.
- Wherever a proposal rests on uncommitted work, it says so.

## Who wrote this, and what was not checked

- **One agent wrote this document:** Opus 5, the model running this step. It is **not independently reviewed**.
  - The owner's rule asks for Fable first on deep analysis. Whether Fable was tried before this step is recorded by the
    workflow that launched it, not known to me. If Fable was intended, record this step as a fallback.
- **Group D rests on `.dev-team/FEATURES.md`.** That ledger was also produced by a single agent, whose own "skeptic"
  check was not independent. Its competitor citations are its own. The ChurchSuite and Breeze pages could not be
  opened (403), so claims citing them are low confidence.
- **Not checked by anyone in this run:**
  - nothing ran against a live server;
  - nothing ran against a database. The one exception is the sweep's run of the column checker on a copy of the SQL
    files, which needs no database;
  - no phone was used;
  - no payment sandbox account was used;
  - no competitor product was used hands-on.

---

## The short list: my recommended order across all groups

This is the ranking across groups. The detail for each item follows in its group.

| Rank | Proposal | Group | Effort | Existing issue |
| --- | --- | --- | --- | --- |
| 1 | A1. Commit the fix that makes protected pages ask visitors to sign in | Must fix | Small | #497 |
| 2 | A2. Get the finished fixes onto alpha, and stop four unfinished issues closing themselves | Must fix | Medium | #493–#498, #501, #503, #507 |
| 3 | A3. Make saved secrets readable, so integrations and scheduled jobs work | Must fix | Medium | #497 |
| 4 | A4. Let administrators give people roles, and create departments | Must fix | Medium | follow-up on #17 (not filed) |
| 5 | A5. Fix two-step verification set-up (broken QR code that sends the secret to Google) | Must fix | Small | follow-up on #92 (not filed) |
| 6 | E1. A real-database test that loads every page | Tools | Medium | none |
| 7 | A6. Make withdrawing an expense claim work | Must fix | Small | #73 (reopen) |
| 8 | A7. Close the remaining ways signed-out visitors see internal events | Must fix | Small | follow-up on #478; #503 |
| 9 | C1. A "Report a problem" button for testers | Tester experience | Small–Medium | none |
| 10 | B1. Add links to the pages nobody can find | Half-built | Small | several |
| 11 | A8. Encrypt care notes, as the app already claims | Must fix | Medium | follow-up on #257 |
| 12 | A9. Stop the page template overriding the security headers, and drop HSTS "preload" | Must fix | Small | follow-up on #160 |
| 13 | A10. Hand on waiting-list places | Must fix | Small | follow-up on #438 |
| 14 | C2 and C3. Show testers which copy and version they are on, and fix the version automation | Tester experience | Small | #476 |
| 15 | B2. Let the livestream video play | Half-built | Small | #273 (reopen) |
| 16 | E2. Make the reliable checks block merges into alpha | Tools | Small | #474, #108 |
| 17 | B3. Public event registration: add the on switch and a link | Half-built | Small–Medium | #348 (reopen) |
| 18 | B4. Send scheduled newsletters when their time comes | Half-built | Small–Medium | follow-up on #269 |
| 19 | C6. "Check my portal" page, including the scheduled jobs | Tester experience | Medium | #477 |
| 20 | C4. A tester guide and a "known problems" help page | Tester experience | Small | none |
| 21 | A11. Decide: anonymous "Submit an event" is switched on by default | Must fix (decision) | Small | comment on #326 |
| 22 | B9. Prove a full backup restore on a real database, then fix the runbook | Half-built | Medium | #472, #488 |
| 23 | C5. A first-run checklist that can actually be completed | Tester experience | Small | follow-up on #222 |
| 24 | B10. Send every email with a plain-text part | Half-built | Small–Medium | #254 (reopen) |
| 25 | E13 and E15. Closing keywords, and applying the sweep's GitHub changes carefully | Tools | Small | none |

After these, the order within each group below is my recommendation.

---

## Group A: must fix before testers touch it

These either expose testers' personal data, stop a core task from working at all, or make a correctly configured
feature look broken. Testers will type real names, amounts and pastoral details into an alpha copy that is reachable
from the internet, so these come first.

### A1. Commit the fix that makes protected pages ask visitors to sign in

- **What:** the Router is the part of the portal that decides which page answers an address. Its check that sends a
  signed-out visitor to the sign-in page never fires. The fix is built in the working tree. What remains is to review
  it, commit it and get it onto alpha, together with the pieces it depends on.
- **Why it matters for alpha testing:** at the last commit, any page without its own sign-in check is open to anyone
  on the internet. Pages confirmed open on 13 September include:
  - the expenses treasury queue, which shows claimants' names and amounts;
  - expense submission and approval;
  - the dashboard;
  - attendance;
  - the support page.
- **Evidence:**
  - **PROVEN (re-read today):** at the last commit, `web/_core/Router.php:83` reads
    `if ($route['isProtected'] === '1')`. That compares the value with the *text* "1".
  - **PROVEN (brief update; also recorded in the uncommitted Router comment):** the database hands this value back as
    the *number* 1. A number is never identical to text, so the test is false for every route.
  - **PROVEN (re-read today):** the working-tree version accepts both `1` and `'1'`. It is **uncommitted**.
  - **PROVEN (sweep, batch 9):** the sweep read all 562 route seeds. No page meant for signed-out visitors is marked
    protected, so the fix will not lock the public out of public pages.
- **Two things must travel with it:**
  1. **Scheduled jobs.** A scheduled job arrives with a token and no session. If it sits at a protected address, the
     fixed Router would send it to the sign-in page and the job would silently stop running.
     - **PROVEN (re-read today):** the uncommitted Router comment says every scheduled job must live at a `cron/...`
       address that is not marked protected.
     - **PROVEN (re-read today):** the new `cron/health`, `cron/backup-check` and `cron/retention-sweep` files and
       migration 196 exist, but are untracked.
     - **PROVEN (handoff):** a final round on maintenance mode is still in progress.
  2. **Four background requests.** Once the fix lands, the worship plan reorder, the service-plan message poll, the
     report-builder preview and the report data request will receive the sign-in page instead of a short error.
     - **PROVEN (sweep, double-check):** the uncommitted `Auth::requireLogin()` always redirects.
- **Effort:** Small. The fix is built and verified; what is left is the review, the four-request follow-up and the
  commit.
- **Risk:** Medium. Any page that only "worked" because nobody was asked to sign in will now ask. The sweep's reading
  of every route seed is the mitigation.
- **Depends on:**
  - the scheduled-job addresses (the "pkg6b" package) landing in the same commit or an earlier one;
  - Codex being available. **PROVEN (handoff):** Codex is limited until 17:37 today.
- **Existing issue:** #497, in progress. The four background requests have no issue yet; the sweep proposes a
  follow-up on #497.

### A2. Get the finished fixes onto alpha, and stop four unfinished issues closing themselves

- **What:** four steps, in order.
  1. Finish the review rounds for the built-but-uncommitted packages, and commit them.
  2. Before merging, deal with the commit messages that say "Closes #472", "Closes #477", "Closes #482" and
     "Closes #483".
  3. Merge `claude/alpha-wip` into `alpha` as the single planned pull request.
  4. Check that the migration harness passed before trusting that merge.
- **Why it matters for alpha testing:** testers use the alpha copy, and the alpha copy is built from `origin/alpha`.
  - It lacks the committed fixes for #493 to #496:
    - who can change portal-wide payment, email and text-message settings;
    - trusting a visitor's claimed address.
  - It lacks the uncommitted fixes too:
    - #498: the demo-data wipe can delete real accounts;
    - #503: a draft event is shown to anyone with its link;
    - #507: signed-in pages stay readable offline after sign-out, which matters on a shared church-office computer;
    - #501: the crashes on the reports page and on the event-coordinator grant.
  - Testing a copy that lacks known fixes spends testers' time rediscovering faults that are already found.
- **Evidence:**
  - **PROVEN (re-read today):** the `git status` figures in "The code this rests on".
  - **PROVEN (sweep, project-state):** 63 commits are not in `origin/alpha`.
  - **PROVEN (sweep, project-state):** commits `01f3ab0`, `f0eb9d5` and `20d7a05` carry closing keywords for #472,
    #477, #482 and #483. The sweep found all four not finished.
  - **INFERRED from GitHub's documented behaviour:** those issues will close themselves when the commits reach
    `main`, and the board will then mark them Done.
  - **PROVEN (sweep, comment on #147):** the automatic merge into alpha fires before the migration harness and the
    syntax check finish, because alpha requires no checks (#474).
- **Effort:** Medium. Most of it is review rounds; nine reviews were queued today, and Codex is rate-limited.
- **Risk:** Medium. It is a large merge. Mitigations:
  - read the harness result by hand;
  - stage named files only, never everything at once (project memory: never `git add -A` while agents are editing).
- **Depends on:**
  - A1;
  - Codex;
  - the owner's choice on the closing lines: either edit history that is already pushed, or squash-merge and leave
    them out of the pull request text.
- **Existing issue:** #493, #494, #495, #496, #497, #498, #501, #503 and #507 (all in progress). The merge itself has
  no issue.

### A3. Make saved secrets readable, so integrations and scheduled jobs work

- **What:** build the design in `.claude/plans/secret-settings-497-design.md`. It has ten steps and needs migration
  195. Settings marked sensitive would then be decrypted when the portal loads them.
- **Why it matters for alpha testing:** today every secret saved through an admin page reaches the code still
  encrypted. A tester who sets up any of the following correctly will conclude that the feature is broken:
  - online giving (Stripe, PayPal);
  - captcha;
  - text messages;
  - Microsoft 365 and Google email and sign-in secrets;
  - web push notifications;
  - Zoom;
  - Cloudflare video;
  - what3words and Google address look-ups;
  - error monitoring.

  **Every scheduled job's token** is also stored as a secret. So reminders, the backup check, retries of
  notifications to other systems, workflow timeouts, asset reminders and venue reminders all refuse every call.
- **Evidence:**
  - **PROVEN (re-read today):** `web/_core/bootstrap.php:397` reads `if ($row['isSensitive'] === '1' ...)`. It is the
    same number-versus-text fault as A1, and `bootstrap.php` has no uncommitted change.
  - **PROVEN (sweep):** the consequence is recorded issue by issue on #32, #46, #48, #141, #142, #143, #234, #268,
    #322, #324, #386, #405, #429, #432, #439, #440, #443 and #456.
  - **INFERRED (sweep, batch 9):** the three new `cron/...` addresses would also answer 403.
  - **PROVEN (re-read today):** the design's contents list includes a new `SecretSettings` class, a captcha that
    refuses safely when a key cannot be read, a one-off repair in the upgrade page, a notice for administrators, and a
    self-test plus a new check.
- **Effort:** Medium. The design is written and the owner has answered its two decisions. It touches the start-up
  code, captcha, the settings editor, the upgrade page, the dashboard and the health page. It must be tested against
  a real database.
- **Risk:** High. Every secret passes through this code. A mistake either breaks every integration at once or exposes
  secrets.
- **Depends on:** #498 and the scheduled-job package being committed first (the design's section 0.3 and the
  handoff).
- **Existing issue:** #497.

### A4. Let administrators give people roles, and create departments

- **What:** role tick-boxes on the existing user edit page, per organisation, with every change written to the audit
  trail. Plus a departments page, where an administrator creates a department and marks who leads it and who
  approves its claims.
- **Why it matters for alpha testing:**
  - **On a fresh installation, Expenses cannot accept a single claim.** The claim form requires a department, and
    nothing in the portal can create one.
  - The treasurer, children's check-in, care, prayer partner, visitor follow-up, asset manager, venue manager, stream
    moderator and announcement approver jobs can all be done by administrators only.
  - A tester asked to "try the treasurer's job" cannot be given it.
  - The admin help page tells them roles are managed in the database.
- **Evidence:**
  - **PROVEN (re-read today):** no statement anywhere under `web/` inserts into `tblUserRoles`, `tblDepts` or
    `tblUserDepts`.
  - **PROVEN (re-read today):** `web/_apps/help/admin.php:368` says "Role assignment is currently managed at the
    database level".
  - **PROVEN (re-read today):** `web/_apps/expenses/submit/index.php` lists departments from `tblDepts` and marks the
    Department field `required`.
  - **PROVEN (re-read today):** pages check these role names:

    | Role name | Times checked |
    | --- | --- |
    | `asset_manager` | 37 |
    | `groups_coordinator` | 6 |
    | `care_team` | 4 |
    | `kids_team` | 3 |
    | `Approver` | 3 |
    | `stream_moderator` | 2 |
    | `Treasurer` | 2 |
    | `volunteer`, `venue_manager`, `staff`, `prayer_team` | 1 each |

  - **PROVEN (sweep, batch 5):** only seven role names are seeded. Several that pages check are not among them.
  - **PROVEN (sweep):** the consequence is recorded on #17, #65, #87, #236, #257, #258, #266, #298, #311, #393, #429,
    #440 and #443.
  - **INFERRED:** the checked names mix capital letters (`Approver`, `Treasurer`) with lower-case ones (`care_team`).
    The build must confirm that each name a page checks exactly matches a seeded role name. Otherwise the new screen
    will grant a role that no page recognises.
- **Effort:** Medium. The tables exist. Still needed:
  - the screens and their save handlers;
  - seeds for the missing roles, in a migration and in the install script;
  - the audit entries.
- **Risk:** Medium. This decides who can open care notes, children's records and giving. It needs a Codex review, and
  a real-database test of the permission checks (the "flags come back as numbers" trap).
- **Depends on:** A1 first, so the new role pages are themselves protected.
- **Existing issue:** the sweep's follow-up on #17 (not filed). It is also FG-001 in `.dev-team/FEATURES.md`.

### A5. Fix two-step verification set-up: the QR code is broken and sends the secret to Google

- **What:** build the QR code with the portal's own generator instead of Google's old chart service. Do the same for
  the QR code on the public event page.
- **Why it matters for alpha testing:**
  - A tester turning on two-step verification sees a broken image and has to type the code by hand.
  - More seriously, the image address carries the secret itself to an outside company. Anyone holding that secret can
    produce valid sign-in codes for that account.
- **Evidence:**
  - **PROVEN (re-read today):** `web/_core/Totp.php:109` and `web/_apps/calendar/public-landing.php:92` both build
    `https://chart.googleapis.com/chart?...` addresses.
  - **PROVEN (sweep, batch 7, by requesting the address):** that service now answers "not found".
  - **PROVEN (sweep):** the portal already has its own QR generator (`web/_apps/qr.php`).
- **Effort:** Small.
- **Risk:** Low. **INFERRED:** make sure the local generator neither logs nor caches the text it is given.
- **Depends on:** nothing.
- **Existing issue:** the sweep's follow-ups on #92 and #346.

### A6. Make withdrawing an expense claim work

- **What:** add "Withdrawn" to the list of values the claim's status column accepts. It needs a guarded migration and
  the same change in the install script.
- **Why it matters for alpha testing:** the Withdraw button, its address, the log entry and the approver email all
  exist. But the save is refused by the database, so the claimant sees "Error withdrawing claim" every time.
- **Evidence:**
  - **PROVEN (re-read today):** `web/_sql/full_schema.sql:409` defines
    `status ENUM('Pending','Approved','Rejected','Reimbursed')`. An ENUM is a fixed list of allowed values.
  - **PROVEN (re-read today):** `web/_apps/expenses/withdraw/save.php:120` writes `'Withdrawn'`.
  - **PROVEN (re-read today):** no file under `web/_sql/` contains "Withdrawn".
  - **INFERRED (sweep, double-check):** on a database that is not in strict mode, the value would be saved as empty
    rather than refused. The live server's setting was not checked.
- **Effort:** Small.
- **Risk:** Low. **INFERRED:** adding a value to the end of the list is a quick change on MySQL 8.
- **Depends on:** nothing.
- **Existing issue:** #73. The sweep recommends reopening it.

### A7. Close the remaining ways signed-out visitors see internal events

- **What:** two fixes.
  - The public check-in page (`/attend`) should refuse events that are not public.
  - The series calendar download should check whether each event is public.
- **Why it matters for alpha testing:** internal events (a leaders' meeting, a pastoral visit rota) should not be
  visible to someone who guesses an event number.
- **Evidence:**
  - **PROVEN (sweep, double-check):** `/attend` shows and accepts internal events because it never checks `isPublic`.
  - **PROVEN (sweep, batch 9):** `web/_apps/calendar/export.php:119` does not check whether events are public, even in
    the uncommitted #503 fix.
  - **PROVEN (handoff):** the draft-visibility rule is now copied in about eleven files. A shared helper is
    recommended, so the next copy does not drift.
- **Effort:** Small for the two fixes. Medium if the shared helper is included.
- **Risk:** Low.
- **Depends on:** A2 (the #503 work touches the same files).
- **Existing issue:** the sweep's follow-up on #478, and #503.

### A8. Encrypt care notes, as the app already claims

- **What:** encrypt pastoral care notes when they are saved and decrypt them when they are shown. Convert existing
  notes once, during upgrade.
- **Why it matters for alpha testing:** pastoral notes are the most sensitive data in the portal: health, family and
  faith. The app tells people they are encrypted, and they are not. Testers may type real notes.
- **Evidence:** **PROVEN (sweep, batch 5, double-checked):** care notes are not encrypted despite the claim, and the
  `care_team` role cannot be granted (see A4).
- **Effort:** Medium. It covers saving, showing, the one-off conversion, and keeping erasure and the personal data
  download working.
- **Risk:** Medium to High. A mistake in the conversion loses notes. Take a backup first, and make the conversion safe
  to run twice.
- **Depends on:** A4, so that someone other than an administrator can use the app. It shares the encryption key
  handling with A3 (INFERRED).
- **Existing issue:** the sweep's follow-up on #257.

### A9. Stop the page template overriding the security headers, and drop HSTS "preload"

- **What:** headers are instructions the portal sends to the browser with every page. An administrator can configure
  the security headers, but the page template then overwrites them with fixed values. The fix is to remove the fixed
  copies.
- **Why it matters for alpha testing:** one of the fixed headers is HSTS with "preload".
  - HSTS tells browsers "only ever use a secure connection for this domain".
  - "Preload" additionally asks browser makers to build that rule into browsers permanently. It is hard to undo, and
    it covers every other site under the same domain.
  - #160 deliberately rejected it. WebMS-Intra is a product installed on other organisations' domains (#500), so this
    decision belongs to each customer.
- **Evidence:** **PROVEN (sweep, batch 3, double-checked):** `web/_core/templates/header.php:98-102` sends fixed
  headers after the configurable ones, including HSTS with `preload`.
- **Effort:** Small.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** the sweep's follow-up on #160.

### A10. Hand on waiting-list places

- **What:** when someone cancels a place on a full event, the next person on the waiting list should be moved up. The
  code that does this names a column that does not exist, so it fails every time.
- **Why it matters for alpha testing:** any tester who tries event capacity will see nobody moved up, and nobody told.
- **Evidence:**
  - **PROVEN (re-read today):** `web/_core/Events.php:138` selects `u.email`. The real column is `emailAddress`.
  - **PROVEN (sweep, double-check):** the error is caught and quietly undone at `:188`.
  - The closing search for #438 only looked in `web/_apps`, which is why this copy was missed.
- **Effort:** Small.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** the sweep's follow-up on #438.

### A11. Decide: anonymous "Submit an event" is switched on by default

- **What:** an owner decision. Should new installations seed anonymous event submission as switched off until a
  captcha is configured?
- **Why it matters for alpha testing:** an alpha copy reachable from the internet will collect junk submissions.
  Submissions only reach the moderation queue, so nothing appears in public, but the queue fills.
- **Evidence:**
  - **PROVEN (re-read today):** `web/_sql/full_schema.sql:4928-4929` seed `calendar.publicSubmit.enabled` and
    `calendar.publicSubmit.allowAnonymous` as `'true'`.
  - **PROVEN (sweep, #326):** the captcha check passes automatically when no captcha provider is set up.
  - **PROVEN (re-read today):** the A3 design changes what captcha does when its keys cannot be read.
- **Effort:** Small. Change the default for new installations. For existing ones, change the value only where it still
  equals the old default, which respects the settings-table trap.
- **Risk:** Low.
- **Depends on:** A3, for a working captcha.
- **Existing issue:** the sweep's comment on #326.

### A12. Decide the minimum password length on existing sites

- **What:** an owner decision, already written up. Existing sites require 8 characters while the project intends 12.
- **Why it matters for alpha testing:** alpha testers create accounts under whatever rule the copy has.
- **Evidence:** **PROVEN (sweep):** #480 is open and not done.
- **Effort:** Small, once decided. The issue recommends a dashboard notice with a one-click change.
- **Risk:** Low.
- **Depends on:** the owner's decision.
- **Existing issue:** #480.

### A13. Multi-organisation "session" mode never works, and it is the default

- **What:** either fix session mode, or, for alpha, tell testers to use path mode and hide session mode.
- **Why it matters for alpha testing:** this only matters if testers switch on more than one organisation. If they do,
  everyone lands on organisation 1, and the organisation switcher appears to work but does not.
- **Evidence:**
  - **PROVEN (sweep, batch 9):** `web/_sql/full_schema.sql:1661` seeds `multisite.detectionMode` as `session`.
  - **PROVEN (sweep, batch 9):** `Site::preDetect()` falls back to session mode when that row is missing.
- **Effort:** Medium to fix. Small to hide and document.
- **Risk:** Medium. Getting the organisation wrong is a data-separation fault.
- **Depends on:** nothing.
- **Existing issue:** #502.

---

## Group B: finish what is half-built

Each of these was merged and, in most cases, closed. Everything written works, but the way in or the last step is
missing. For testers these are the most confusing faults, because the feature visibly exists.

### B1. Add links to the pages nobody can find

- **What:** add menu entries, dashboard cards or toolbar buttons for pages that exist and work but that nothing links
  to.
  - the dedicated settings pages, including Sabbath quiet hours (#252, #251);
  - the song usage (CCLI) report (#309);
  - the event-coordinator grant page, and the coordinator's own "my events" page (#341);
  - "my volunteering" (#342);
  - the children's-ministry parent page at `/kids/profiles` (#298);
  - asset loans, stocktakes and kiosks (#398, #411, #414);
  - the host console (#317);
  - the denominational returns page (#305);
  - the sermon notes editor (#301).
- **Why it matters for alpha testing:** a tester cannot test what they cannot find. They will report these features as
  missing.
- **Evidence:**
  - **PROVEN (sweep, batches 4–8, double-checked where a follow-up was raised):** each page was found to have no
    inbound link.
  - **PROVEN (handoff):** nothing in the portal calls `/admin/reports/data` either.
- **Effort:** Small.
- **Risk:** Low. **INFERRED:** check each page's own access check before linking it, because more people will try it
  once it is visible. That matters most while A1 is uncommitted.
- **Depends on:** A1.
- **Existing issue:** the sweep's follow-ups on #252, #305 and #317, and its comments on #298, #309, #341, #342, #398,
  #411 and #414.

### B2. Let the livestream video play

- **What:** let the `/live` page (and the livestream admin page) widen the page's security rules for the video
  providers it actually embeds.
- **Why it matters for alpha testing:** a tester opening a livestream sees the chat working next to an empty video
  box.
- **Evidence:**
  - The Content Security Policy is the list of outside sites a page may load content from.
  - **PROVEN (re-read today):** `web/_core/templates/header.php:160` allows embedded frames only from
    `challenges.cloudflare.com`, plus whatever a page adds through `$cspFrameExtra`.
  - **PROVEN (re-read today):** only two pages add anything: `web/_apps/calendar/event-hub.php:198` (through
    `VideoEmbed::frameSrcOrigins()`) and `web/_apps/noticeboard/index.php:34`.
  - **PROVEN (re-read today):** `web/_apps/live/index.php:70` outputs a video frame, and adds nothing.
  - **PROVEN (sweep, batch 5):** the admin livestream page has the same fault. The rule predates the page, so the
    player never worked.
- **Effort:** Small. The helper `VideoEmbed::frameSrcOrigins()` already exists.
- **Risk:** Low. Allow only the providers in use.
- **Depends on:** nothing. `live/index.php` is part of the uncommitted #503 work, so do this after A2.
- **Existing issue:** #273. The sweep recommends reopening it.

### B3. Public event registration: add the on switch and a link

- **What:** put a "Registration open" switch on the event form and a "Register" link on the event page. At the same
  time, fix the error shown after saving a registration when no mail sender is configured.
- **Why it matters for alpha testing:** the registration form, its moderation queue, and its allergy, medical and
  consent fields are all built. No installation can use any of it.
- **Evidence:**
  - **PROVEN (re-read today):** a search of `web/_apps` and `web/_core` finds no line that writes
    `registrationEnabled`; only reads.
  - **PROVEN (sweep, batch 7):** nothing links to `/calendar/event/register`, and the automatic crew builder (#349)
    is empty for the same reason.
  - **PROVEN (handoff test note):** `event-register-save.php:147` gives a server error after saving when no mail
    sender is configured.
  - **PROVEN (re-read today):** the registration pages are changed in the uncommitted #503 work, so that internal
    events need sign-in.
- **Effort:** Small to Medium.
- **Risk:** Medium. It is a public form that collects children's allergy and medical details. **INFERRED:** confirm
  retention and erasure coverage for those rows before switching it on.
- **Depends on:**
  - A2 (the #503 changes);
  - A3 (captcha and email).
- **Existing issue:** #348, which the sweep recommends reopening. #347 and #349 have comments pointing to it.

### B4. Send scheduled newsletters when their time comes

- **What:** a scheduled job that finds newsletters whose send time has passed and hands each one to the existing
  batched sender, making sure each newsletter is claimed only once.
- **Why it matters for alpha testing:** the editor offers a "schedule for" date, and the list then shows
  "For <date>". Nothing ever sends it.
- **Evidence:**
  - **PROVEN (re-read today):** `web/_apps/newsletter/save.php:35` reads the posted `scheduledFor` (the file's header says it saves it), and
    `web/_apps/newsletter/index.php:96-97` displays it.
  - **PROVEN (re-read today):** the only caller of `Newsletter::dispatch()` is the manual Send button
    (`web/_apps/newsletter/send.php:61`).
  - **PROVEN (re-read today):** none of the job files in `web/_apps/cron/` handles newsletters.
- **Effort:** Small to Medium.
- **Risk:** Low to Medium. It must keep the existing hourly batch limit, and it should respect quiet hours (B11).
- **Depends on:**
  - A1's `cron/...` address pattern;
  - A3, because the job token is a secret.
- **Existing issue:** the sweep's follow-up on #269.

### B5. Repeating events, and the pieces built on top of them

- **What:** store a repeat rule once ("every Saturday at 10:00"), work out the dates when showing the calendar, and
  allow one date to be changed or cancelled.
- **Why it matters for alpha testing:**
  - Almost every organisation's calendar is mostly repeating meetings. Today a weekly service has to be entered by
    hand, week by week.
  - Testers from any church, charity or school will notice this within minutes.
  - Comparable products offer it: Planning Center updates "the single event or all future events" (FG-003 in
    `.dev-team/FEATURES.md`, citing https://help.planningcenter.com/en/140958-create-and-manage-events.html).
- **Evidence:**
  - **PROVEN (re-read today):** nothing under `web/` inserts into or updates `tblRecurrenceRules`.
  - **PROVEN (sweep):** per-date overrides are saved but never applied (#333).
  - **PROVEN (sweep):** the calendar download's repeat-rule code reads that empty table (#338).
  - **PROVEN (sweep):** imported feeds do not expand repeats (#327).
- **Effort:** Large. It touches the rule storage, every calendar view, the downloads and subscriptions, and the
  reminders.
- **Risk:** Medium to High.
  - Every calendar view is touched.
  - The clock-change trap applies: compare wall-clock times as text, never as timestamps (project memory).
- **Depends on:**
  - A2 first, because many calendar files are in the #503 work;
  - a design pass (a deep-analysis run) before building.
- **Existing issue:** #26, which the sweep recommends reopening; also #333 and #338.
- **For alpha, if this waits:** say plainly in help that series are created one event at a time (INFERRED
  suggestion).

### B6. Calendar "photo" view: add it to the allowed list, or remove it

- **What:** decide whether the photo view exists.
- **Why it matters for alpha testing:** choosing the photo view silently shows a different view.
- **Evidence:**
  - **PROVEN (re-read today):** `web/_apps/calendar/index.php:65` allows only day, week, weekdays, weekend, month, year
    and list.
  - **INFERRED from the handoff:** the uncommitted scheduled-job package removed a stray photo route and corrected
    comments about `calendar/views/photo.php`. Check what the working tree now intends before deciding.
- **Effort:** Small.
- **Risk:** Low.
- **Depends on:** A2.
- **Existing issue:** #331. The sweep recommends reopening it.

### B7. Co-organisers are saved but shown nowhere

- **What:** show an event's extra organisers on the event page and in downloads, or remove the field.
- **Why it matters for alpha testing:** an organiser adds co-organisers and never sees them again.
- **Evidence:** **PROVEN (sweep, batch 6):** the organisers are written to `tblEventOrgs`, and nothing reads that table.
- **Effort:** Small to Medium.
- **Risk:** Low.
- **Depends on:** A2.
- **Existing issue:** #332. The sweep recommends reopening it.

### B8. Integrations with no way in: decide, one by one, to finish or hide for alpha

- **Why it matters for alpha testing:** an administrator can switch these on and even enter a **paid** API key for
  services the portal never actually calls (#485). Testers would spend time, and possibly money, on features that
  cannot work.
- **My recommendation:** for alpha, label these "not yet available" at `/admin/apps`. That is Small effort and Low
  risk. Then finish them one at a time:
  - **B8a. Zoom (#274):** nothing links to `calendar/zoom-create`. Add a "Create Zoom meeting" button on the event
    manage page. *Small; depends on A3.*
  - **B8b. Sermon notes (#301):** notes are saved to `tblRecordingNote`, but the recording page never shows them and
    nothing links to the editor. *Small.*
  - **B8c. Transcription (#276):** nothing can queue a transcription, and the transcript and search pages are
    unlinked. *Medium; depends on A3 and on spending limits.*
  - **B8d. AI drafting (#277):** the endpoint sits at `web/_apps/api/ai-improve.php`, an address the data interface
    cannot serve, and no editor calls it. #483 plans to delete that file, so decide this before #483. *Medium.*
  - **B8e. Content translation (#485, #278):** only the unreachable `_apps/api/translate.php` calls the translation
    code. Decide before #483 deletes it. *Medium.*
- **Evidence:** **PROVEN (sweep, batches 5–6, double-checked):** for each item above.
- **Existing issue:** #274, #276, #277 and #301 (the sweep recommends reopening all four), and #485.

### B9. Prove a full backup restore on a real database, then fix the runbook

- **What:** three steps.
  1. Run a real full restore against a throwaway MySQL database.
  2. Update the disaster-recovery runbook to match the rewritten restore.
  3. Record who restored or deleted which backup, and when.
- **Why it matters for alpha testing:** testers will make a mess, and an administrator will reach for Restore. If
  restore fails, the test data goes, and so does trust in the product.
- **Evidence:**
  - **PROVEN (sweep, batch 8):** the restore was rewritten in `98e78c9` and `01f3ab0`. It now works in one
    transaction, puts tables back in dependency order, and no longer uses TRUNCATE.
  - **PROVEN (re-read today, `checks.txt`):** the ordering self-test passes 7 of 7. It also says it does not prove a
    real restore.
  - **PROVEN (sweep):** the runbook is wrong in two ways (#488).
  - **PROVEN (sweep):** restores and deletions are not logged (#227).
- **Effort:** Medium.
- **Risk:** Low for testing, because it runs against a throwaway database.
- **Depends on:** A2. Note that `01f3ab0` would auto-close #472 on merge.
- **Existing issue:** #472 and #488, plus the sweep's follow-up on #227.

### B10. Send every email with a plain-text part, and use the saved templates

- **What:** two changes.
  - Send every email as both HTML and plain text.
  - Make the Mailer use the template overrides that administrators save at `/admin/email-templates`.
- **Why it matters for alpha testing:**
  - **INFERRED (general deliverability knowledge, not tested here):** HTML-only messages are more likely to be filed
    as spam. Testers would then miss invitations, reminders and password resets.
  - A tester who edits a template sees no change.
- **Evidence:**
  - **PROVEN (sweep, batch 5, double-checked):** no email provider in the Mailer sends a combined HTML and plain-text
    message (#254).
  - **PROVEN (sweep, batch 5, double-checked):** saved template overrides are never read (#243).
- **Effort:** Small to Medium.
- **Risk:** Low to Medium. Every outgoing email changes shape, so send a test through each provider.
- **Depends on:** A3, to test the Microsoft 365 and Google senders.
- **Existing issue:** #254, which the sweep recommends reopening, and the sweep's follow-up on #243.

### B11. Apply quiet hours (Sabbath) to email

- **What:** hold automated email during an organisation's quiet hours and send it afterwards.
- **Why it matters for alpha testing:** an organisation that switches quiet hours on will expect the reminders and
  newsletters to wait. Today only the settings pages know about quiet hours.
- **Evidence:** **PROVEN (sweep, batch 4):** the Mailer has no quiet-hours check, and there is no queue. The
  per-person and admin pages were built (#251).
- **Effort:** Medium. It needs a small send queue, or a "hold until" step in each scheduled job.
- **Risk:** Medium. A queue that never empties is a quiet failure, so it needs a visible count (see C6).
- **Depends on:**
  - B4's scheduled-job pattern;
  - A3.
- **Existing issue:** the sweep's follow-up on #231.

### B12. Critical-error alerts: add the in-portal banner, a test button and text messages

- **What:** add an in-portal banner for administrators, a "send test alert" button, and a call to the text-message
  sender.
- **Why it matters for alpha testing:** email is the only alert channel, and it can fail silently. So a serious fault
  during testing may go unnoticed.
- **Evidence:** **PROVEN (sweep):** email is the only channel (#229), and the Logger never calls the text-message
  helper (#272).
- **Effort:** Small to Medium.
- **Risk:** Low.
- **Depends on:** A3.
- **Existing issue:** the sweep's follow-ups on #229 and #272.

### B13. DBS check expiry reminders

- **What:** email the safeguarding lead a set number of days before a volunteer's DBS check (the UK criminal records
  check) expires, once per check.
- **Why it matters for alpha testing:** UK churches and charities treat this as a safeguarding duty. Today the lead
  only sees an expiry if they open the page.
- **Evidence:**
  - **PROVEN (sweep, #310):** `tblDbsChecks` and its admin page exist, and no scheduled job reads the table.
  - The ledger (FG-006) cites ChurchSuite (summary only, low confidence).
- **Effort:** Small. The reminder job and its once-only log already exist.
- **Risk:** Low.
- **Depends on:**
  - A3, for the job token;
  - a setting naming the safeguarding lead.
- **Existing issue:** the sweep's follow-up on #310.

### B14. Photos default to a visibility level no member can hold

- **What:** change the default visibility for new photos, or make the "staff" level grantable.
- **Why it matters for alpha testing:** an uploaded photo is visible to administrators only, and testers will think
  the upload failed.
- **Evidence:** **PROVEN (sweep, #236):** the default is `staff`, and nobody can be given that role.
- **Effort:** Small.
- **Risk:** Low.
- **Depends on:** A4.
- **Existing issue:** the sweep's follow-up on #236.

### B15. Reading plans ship with no readings and no way to write them

- **What:** seed at least one plan, and later add an authoring page.
- **Why it matters for alpha testing:** a tester opens Reading Plans and finds nothing to read.
- **Evidence:** **PROVEN (sweep, #265):** there are no `tblReadingPlanDay` rows, and there is no authoring page.
- **Effort:** Small to seed one plan made of references only (for example "Genesis 1–3"). Medium for an authoring
  page. INFERRED: references alone avoid any question about copyright in Bible text.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** the sweep's follow-up on #265.

### B16. Switching an app off should switch off its data endpoints too

- **What:** decide, endpoint by endpoint, whether an app's on/off switch applies to it, and enforce that decision.
- **Why it matters for alpha testing:** an administrator who switches an app off will assume it is off. Its data still
  answers through the API.
- **Evidence:** **PROVEN (sweep):** #478 is open and not done.
- **Effort:** Medium.
- **Risk:** Medium. Some public addresses, such as unsubscribe links, must keep working.
- **Depends on:** nothing.
- **Existing issue:** #478.

### B17. The remaining erasure and privacy decisions

- **What:**
  - finish the four erasure faults in #492;
  - decide what happens to the seven tables that hold personal information with no link to an account;
  - settle #491, where one organisation's settings control the clear-outs for every organisation.
- **Why it matters for alpha testing:** a tester who asks for their data to be deleted should find it deleted.
- **Evidence:** **PROVEN (re-read today, `checks.txt`):** the erasure self-test lists seven such tables:
  `tblAnonymousCheckins`, `tblAssetOrgs`, `tblAttendanceCounts`, `tblLiveChatMessages`, `tblServicePlanItem`,
  `tblServicePlanItems` and `tblVenueImportRows`.
- **Effort:** Medium.
- **Risk:** Medium.
- **Depends on:** nothing.
- **Existing issue:** #492, #47 and #491.

### B18. Smaller follow-ups from the sweep, each worth doing, best done in one tidy package

Each item is a separate proposal. Effort is shown against each. Evidence for all of them: **PROVEN (sweep)**, with
double-checks where a follow-up was raised.

- **OAuth sign-in failures never count towards the rate limit** (#62). *Small.* This is a security item, so do it
  first.
- **Six places read the connection address directly**, including the Gift Aid acceptance record (#496 comment).
  Behind a trusted proxy they would record the proxy's address. *Small.*
- **The off-site backup breaks on a path with a space** (`escapeshellcmd` does not quote spaces). The
  "← Maintenance" link on four pages points at an address that is not registered (#498 comment). *Small.*
- **Leadership transitions send no email** (#76). *Small.*
- **The RSVP form has no guest-count box** (#334). *Small.*
- **The cancellation reason, and who changed the status, are never recorded** (#337). *Small.*
- **Exports ignore the page's filters, and there is no PDF** (#77). *Medium.*
- **Decision cards have no follow-up reminders** (#316). *Medium.*
- **Document categories cannot be restricted to a group of people** (#90). *Medium.*
- **The before-and-after audit covers API changes only**, not edits in the browser (#91). *Medium.*
- **The document library ignores the `?event=N` filter** (#351). *Small.*
- **The weekly digest email was never built** (#86). *Medium*; or remove its settings (see C8).
- **Polish for the holiday Bible club (VBS) event tools**, deferred on #343, #344 and #345. *Medium.*

---

## Group C: the tester's experience

These do not fix a fault in a feature. They make the alpha copy something a tester can find their way around, and
report on usefully.

### C1. A "Report a problem" button for testers

- **What:** a small link on every page for signed-in users, which could be limited to the alpha and beta copies. It
  opens a short form ("what happened?") and records alongside the answer:
  - the page address;
  - the time;
  - the version and the channel;
  - the browser;
  - the last error the portal logged for that person's session.

  Reports go to a table and an admin page, and optionally to an email address set in a setting.
- **Why it matters for alpha testing:** "the treasurer page broke" costs a round of questions. A report that already
  carries the page, the version and the logged error can be acted on straight away. The portal already records errors
  in `tblErrors`, so a report can point at the exact one.
- **Evidence:**
  - **PROVEN (re-read today):** no feedback feature exists. A search for "feedback" in the apps and templates finds
    only a code comment and one help sentence.
  - **PROVEN (sweep, #232):** the rollout plan's feedback widget was never built.
  - **PROVEN (re-read today):** the owner's brief asked for restricted access to alpha and beta
    (`.claude/ProjectBrief_Chat.claude:622` and `:821`). That gate now exists (`4eba0ee`).
- **Effort:** Small to Medium.
- **Risk:** Low to Medium.
  - A report says who made it, so it is personal data. It must be added to the personal-data catalogue in the same
    change; the existing check enforces that.
  - Under #500, the destination address must be a setting, never built in.
- **Depends on:** A1.
- **Existing issue:** none.

### C2. Show testers which copy and version they are on

- **What:** a slim banner on pre-release copies, for example "Pre-release copy · alpha · version 1.4.x", linking to C4.
- **Why it matters for alpha testing:** testers switching between alpha, beta and live need to know which one they are
  reporting on. Screenshots without it are ambiguous.
- **Evidence:** **PROVEN (re-read today):** `web/_core/templates/header.php` never mentions the environment or the
  channel.
- **Effort:** Small.
- **Risk:** Low.
- **Depends on:**
  - C3, so the version shown is right;
  - the channel marker designed in `public-door-5-channels-amendment.md` and `public-door-6-…` (INFERRED).
- **Existing issue:** none.

### C3. Fix the version and changelog automation on alpha

- **What:** make the version-bump and changelog jobs run after the automatic merge into alpha, and give both a manual
  trigger.
- **Why it matters for alpha testing:** alpha shows the wrong version everywhere: the footer, the installer and API
  responses.
- **Evidence:** **PROVEN (sweep, project-state):** alpha says 1.4.0, while beta and main say 1.4.1.
- **Effort:** Small.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** #476.

### C4. A tester guide and a "known problems" help page

- **What:** one help page covering:
  - what to try first;
  - what is known not to work yet, for example integrations until A3 lands, and repeating events;
  - how to report a problem.

  Update it at every release. Also correct the admin help page's line about roles once A4 lands.
- **Why it matters for alpha testing:** it stops testers filing reports on known faults, and points them at the areas
  where their time is most useful.
- **Evidence:** **PROVEN (re-read today):** there are 19 files in `web/_apps/help/` (for example `getting-started`,
  `admin-first-steps`, `faq`, `support`). None is for testers or lists known problems.
- **Effort:** Small.
- **Risk:** Low. It goes stale unless its update is part of the release routine.
- **Depends on:** nothing.
- **Existing issue:** none.

### C5. A first-run checklist that can actually be completed

- **What:** build the "Mark done" handler, and point the email step at the setting that is actually used.
- **Why it matters for alpha testing:** the checklist is the first thing a new administrator sees. Four of its six
  steps can never tick, so it looks broken from the first minute.
- **Evidence:**
  - **PROVEN (sweep, batch 4):** nothing writes `portal.first_run.steps.*`.
  - **PROVEN (sweep, batch 4):** the email step watches the unused `email.from` key.
  - **PROVEN (handoff):** the Dismiss button is shown to every administrator.
- **Effort:** Small.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** the sweep's follow-up on #222.

### C6. "Check my portal" page, including the scheduled jobs

- **What:** an admin page that runs checks against the live installation. On top of what #477 lists, add a section on
  scheduled jobs, showing for each job:
  - its address;
  - whether its token is set and readable;
  - when it last ran.
- **Why it matters for alpha testing:** almost every fault found this month was invisible from inside the product.
  Customers installing from the zip package (#499) will have only a hosting panel, and must set up each scheduled job
  by hand. Today nothing tells them whether a job has ever run.
- **Evidence:**
  - **PROVEN (re-read today):** `web/_apps/cron/` holds 13 scheduled-job files, plus three shared helper files.
  - **PROVEN (sweep, patterns in batches 3–8):** every job token is a sensitive setting, so every job is affected by
    A3.
  - **PROVEN (re-read today):** the A3 design already adds a notice for unreadable secrets on the dashboard and the
    health page. Reuse that notice here rather than building a second one.
- **Effort:** Medium.
- **Risk:** Low.
- **Depends on:** A3.
- **Existing issue:** #477. Note that commit `f0eb9d5` would auto-close it on merge (see A2).

### C7. A demo data set that fills every app, with one rule for where demo rows may appear

- **What:** once #498's safe wipe is committed, extend the demo set so that each switched-on app has something in it.
  Then agree one rule: demo rows are visible inside the portal, but never leave it. That means no newsletters, emails,
  notifications sent to other systems, public feeds, or outside API use.
- **Why it matters for alpha testing:** testers learn an app far faster from a populated screen than from an empty
  one. And a demo announcement must never reach a real person's inbox.
- **Evidence:**
  - **PROVEN (sweep, #242):** today's demo set is five users and three announcements.
  - **PROVEN (handoff):** the uncommitted #498 work adds a register of demo rows and a fingerprint check before
    deleting.
  - **PROVEN (handoff):** `Newsletter.php` (uncommitted) now leaves demo announcements out.
  - **PROVEN (re-read today):** `web/_apps/announcements/api/list.php` has no demo exclusion. The data interface
    returns demo announcements while demo data is loaded. Under the rule above, that decision depends on who is
    calling: a browser session is fine, an outside system's API key is not.
- **Effort:** Medium.
- **Risk:** Medium. Every table the demo set touches must register its rows, or Wipe will leave them behind.
- **Depends on:** A2 (#498).
- **Existing issue:** #498 for the safe wipe. None for the larger set or the rule.

### C8. Remove or label switches that do nothing

- **What:** for each setting that appears in the settings editor but that no code reads, either connect it to
  something or remove it. The known cases:
  - six `expenses.*` settings (#21);
  - the `notifications.digest*` settings and the misleading `deliveryReady` banner (#86);
  - `auth.allowWordPressLogin` (#127);
  - `portal.rollout.pilot_mode` (#232);
  - `preachingplan.enabled`;
  - the "Mailchimp" newsletter provider, which quietly uses the internal sender instead (FG-016).
- **Why it matters for alpha testing:** a tester who flips a switch and sees nothing change will either file a bug or
  stop trusting the settings.
- **Evidence:** **PROVEN (sweep, batches 1, 2, 3 and 5; double-checked for #21 and #86).**
- **Effort:** Small to Medium. Under the settings-table trap and the two-places rule, removing a seeded setting needs a
  migration and the matching install-script change.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** the sweep's follow-ups on #21 and #86, and its comments on #127 and #232.

### C9. Stop upgrades switching apps back on

- **What:** make three old migrations set their app switch only if the setting is not there yet.
- **Why it matters for alpha testing:** a tester switches the calendar off, an upgrade is retried, and the calendar
  comes back with no explanation.
- **Evidence:** **PROVEN (sweep):** #481 is open and not done.
- **Effort:** Small.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** #481.

### C10. Settings pages: show site administrators a read-only view

- **What:** on the 14 pages that only a global administrator may save, show site administrators the values without a
  Save button. Also decide which settings belong to each organisation.
- **Why it matters for alpha testing:** today a site administrator fills in a form and is refused only after pressing
  Save.
- **Evidence:** **PROVEN (sweep, batch 9, double-checked):** 14 pages show a Save button that the server then refuses.
- **Effort:** Small to Medium.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** the sweep's follow-up on #495.

### C11. Walk through the nine main flows on a real phone

- **What:** hands-on testing of the nine flows listed in `docs/mobile-audit-worksheet.md`.
- **Why it matters for alpha testing:** nothing automated can tell whether the on-screen keyboard hides a field, or
  whether a phone photo attaches to an expense claim.
- **Evidence:** **PROVEN (re-read today, `checks.txt`):** the mobile-readiness check passes, and says the device
  walk-through "still needs hands on a phone".
- **Effort:** Small, plus whatever it finds.
- **Risk:** none.
- **Depends on:** a person with a phone.
- **Existing issue:** #225.

### C12. Decide what to offer testers for interface translation

- **What:** for alpha, either hide the language switcher until the Welsh coverage is higher, or keep it with its
  "partial" badge.
- **Why it matters for alpha testing:** a Welsh-speaking tester gets a portal that is mostly in English.
- **Evidence:** **PROVEN (sweep, batch 4):**
  - the Welsh file covers 95 of 310 keys (31%);
  - 197 English keys are used nowhere;
  - 28 newer files never call the translation helper.
- **Effort:** Small for the decision. Large to fix fully.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** the sweep's follow-up on #210.

### C13. Show dates and times one consistent way

- **What:** make pages honour the configured date format.
- **Why it matters for alpha testing:** it is visible inconsistency on almost every screen.
- **Evidence:** **PROVEN (sweep, #69, double-checked):** more than sixty pages hard-code a date format.
- **Effort:** Medium.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** the sweep's follow-up on #69.

---

## Group D: new features

These come from the scored research ledger at `.dev-team/FEATURES.md`, adjusted for what alpha testing needs. Every
competitor claim below is that ledger's citation, not something I re-opened today. The ledger itself says a separate
reviewer should check its top five before any of them is built.

### D1. Message a chosen group by email or text (FG-002)

- **What:** pick a small group, a rota team, a role or a saved report result, write one message, and send it by email
  and/or text. People who opted out are skipped.
- **Why it matters for alpha testing:** it is what a group leader does every week. Testers from any organisation will
  look for it.
- **Evidence:**
  - **PROVEN (ledger):** newsletter segments can only mean "everyone" or "people with these roles"
    (`web/_core/Newsletter.php:64`).
  - **PROVEN (ledger):** the Small Groups app has no send page.
  - The ledger cites Arbor (opened), and ChurchSuite and Breeze (summary only).
- **Effort:** Medium. Sending already exists; choosing the recipients does not.
- **Risk:** Low to Medium. The main concern is sending volume on shared hosting.
- **Depends on:** A4 (role lists), A3 (text messages), and ideally B4 and B11.
- **Existing issue:** none.

### D2. Repeating rota slots and bulk assignment

- **What:** "every Saturday for 26 weeks", created once.
- **Why it matters for alpha testing:** an administrator currently adds each week's rota slots by hand.
- **Evidence:** **PROVEN (sweep, comment on #256):** there are no recurring slot definitions and no bulk assignment.
- **Effort:** Medium.
- **Risk:** Low.
- **Depends on:** nothing. It pairs naturally with B5 and D3.
- **Existing issue:** none.

### D3. Volunteer "unavailable" dates and clash warnings on rotas (FG-007)

- **What:** volunteers mark the dates they cannot serve. Whoever builds the rota is warned before scheduling them on
  those dates, or twice at the same time.
- **Why it matters for alpha testing:** testers who run rotas will expect it.
- **Evidence:**
  - **PROVEN (ledger):** the rota has three tables and no availability data.
  - The ledger cites Planning Center (opened) and ChurchSuite (summary only).
- **Effort:** Medium.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** none.

### D4. Households: linking family members together (FG-011)

- **What:** a household with adult and child members, so a family's details, joint Gift Aid and children's pick-up can
  be managed once.
- **Why it matters for alpha testing:** it is central for churches and schools. And it is a change to the database
  structure, which is cheapest before real customer data exists.
- **Evidence:**
  - **PROVEN (ledger):** there is no household table.
  - **PROVEN (ledger):** the only family link is one parent per child (`tblKidProfiles.parentUserID`).
  - The ledger cites Planning Center (opened) and Breeze (summary only).
- **Effort:** Large.
- **Risk:** Medium. Erasing one person must not remove or expose the rest of the family.
- **Depends on:** #475 and #487 (settle the database structure before the first customer).
- **My recommendation:** design it during alpha, and build it before beta.
- **Existing issue:** none. #487 calls this period "the last cheap moment".

### D5. Organisation-defined extra fields on people (FG-009)

- **What:** an administrator adds fields such as "Baptism date" or "Dietary needs", and chooses who can see and edit
  each one.
- **Why it matters for alpha testing:** every organisation has a few fields of its own. Without this, testers will ask
  for columns one at a time.
- **Evidence:**
  - **PROVEN (ledger):** `tblUsers` has fixed columns only.
  - **PROVEN (ledger):** the Forms app's field registry could be reused.
  - The ledger cites Planning Center (opened).
- **Effort:** Medium.
- **Risk:** Medium. Every new field is personal data, so it must be covered by the catalogue, the eraser and the data
  download.
- **Depends on:** nothing.
- **Existing issue:** none.

### D6. Paid event sign-up (FG-008)

- **What:** an optional price or deposit on an event, with the place confirmed only when payment succeeds.
- **Why it matters for alpha testing:** camps, conferences and trips.
- **Evidence:**
  - **PROVEN (ledger):** `payments/checkout.php` knows only giving and pledges.
  - The ledger cites Planning Center (opened).
- **Effort:** Medium.
- **Risk:** Medium. Money is tied to capacity and to waiting lists (see A10).
- **Depends on:** B3, A3 and A10.
- **Existing issue:** none.

### D7. Name badges and pick-up labels at children's check-in (FG-010)

- **What:** a print page sized for labels. The child's label carries the name, an allergy flag and the security code;
  the parent's label carries the code only.
- **Why it matters for alpha testing:** it is the physical half of safe check-in.
- **Evidence:**
  - **PROVEN (ledger):** there is no print or label page in the children's app.
  - The ledger cites Breeze (opened product page) and ChurchSuite (summary only).
- **Effort:** Medium.
- **Risk:** Medium. Label printers vary.
- **Depends on:** A4 (the children's team role).
- **Existing issue:** none (see the sweep's comment on #298).

### D8. Show events, groups and giving on the organisation's own website (FG-004)

- **What:** read-only public lists and embeds, delivered through the planned public entrance.
- **Why it matters for alpha testing:** the portal is the back office. The public sees the organisation's own website.
- **Evidence:** **PROVEN (ledger):** only the countdown widget can be embedded today.
- **Effort:** Large.
- **Risk:** Medium, because it publishes data.
- **Depends on:** #493, which is already designed and in progress. **Do not start this separately.**
- **Existing issue:** #493.

### D9. Regular giving by card or Direct Debit (FG-005)

- **What:** a giver sets up a monthly gift once, and can pause or change it themselves.
- **Why it matters for alpha testing:** it matters to any customer that switches Giving on.
- **Evidence:** **PROVEN (sweep, #447):** nothing writes saved payment methods, and there is no subscription handling.
- **Effort:** Large.
- **Risk:** High, because it is money.
- **Depends on:** the owner's choice of payment provider, and A3.
- **Existing issue:** #447, blocked.

### D10. Check the Gift Aid export against HMRC's current claim spreadsheet (first step of FG-012)

- **What:** compare the existing export with HMRC's current template, before considering sending claims straight to
  HMRC.
- **Why it matters for alpha testing:** a treasurer tester will upload the file to HMRC. A mismatch there is a real
  failure.
- **Evidence:** **PROVEN (ledger):** `web/_core/Giving.php:104` (`buildHmrcCsv()`) is the only route to HMRC.
- **Effort:** Small for the check. Large for direct submission, which I would leave until after alpha.
- **Risk:** Low for the check.
- **Depends on:** nothing.
- **Existing issue:** none.

### D11. Questions that appear depending on an earlier answer, in Forms (FG-014)

- **What:** a "show only if question X has answer Y" setting on a form question.
- **Why it matters for alpha testing:** forms stay short for people the follow-up questions do not apply to.
- **Evidence:** **PROVEN (ledger):** there is no show-if setting among the 12 field types.
- **Effort:** Small to Medium.
- **Risk:** Low, provided the server discards answers to hidden questions.
- **Depends on:** nothing.
- **Existing issue:** none.

### D12. Merge duplicate people (FG-013)

- **What:** an administrator merges two records for the same person, driven by the personal-data catalogue, with a
  preview and a typed confirmation.
- **Why it matters for alpha testing:** it has low importance, because email addresses are unique per account.
- **Evidence:** **PROVEN (ledger):** there is no merge feature.
- **Effort:** Medium.
- **Risk:** Medium, because a merge cannot be undone.
- **Depends on:** nothing.
- **Existing issue:** none.

### D13. Log volunteer hours (FG-015)

- **What:** work out hours from confirmed rota slots and event jobs, allow manual additions, and offer a report.
- **Why it matters for alpha testing:** it matters more to the charity preset than to churches.
- **Evidence:** **PROVEN (ledger):** there is no hours data.
- **Effort:** Medium.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** none.

### D14. Group chat and direct messages (FG-017)

- **What:** members of a group talk between meetings.
- **Why it matters for alpha testing:** it has low importance. Groups already use other messaging apps.
- **Evidence:** **PROVEN (sweep, #304):** nothing is built. Two of the original blockers have since gone away (the rate
  limiter, and Small Groups).
- **Effort:** Large.
- **Risk:** High:
  - instant delivery is hard on shared hosting;
  - it needs moderation;
  - it needs safeguarding rules for any child involved.
- **Depends on:** A3, for push notifications.
- **Existing issue:** #304. **Not recommended for alpha.**

---

## Group E: tools and the review process

The brief asks for this part explicitly. Each item either catches a class of fault that has already reached testers,
or protects the review process that the owner's rules depend on.

### E1. A real-database test that loads every page

- **What:** a new step in the existing migration workflow.
  1. After installing onto MySQL, start PHP's built-in web server.
  2. Create an administrator and a plain member.
  3. Request every seeded address three times: signed out, as the member, and as the administrator.
  4. Fail on any server error, any PHP warning, or any new row in `tblErrors`.
  5. Also check that every protected address sends a signed-out visitor to sign in.
- **Why it matters for alpha testing:** the faults that hurt testers most this month were invisible to all 15 checks,
  but each one fails the moment its page loads against a real database:
  - the Router's sign-in check (#497);
  - the admin dashboard crash (#501);
  - the reports page crash (#93);
  - "is this person a global administrator?" answering no for everyone.
- **Evidence:**
  - **PROVEN (re-read today):** `tools/e2e-migrations/run.sh` makes no web request at all.
  - **PROVEN (re-read today):** the workflow's only database is `mysql:8.0.36`.
  - **PROVEN (project memory, "flags come back as numbers"):** a stand-in database that returned text hid the
    administrator fault from a verifier.
  - **PROVEN (handoff):** the verify agents already build throwaway MySQL containers by hand, so the pieces exist.
- **Effort:** Medium.
- **Risk:** Low. It runs only in the automatic checks.
  - **INFERRED:** it covers page loads (GET) only. Save handlers (POST) need test data and could come later.
  - **INFERRED:** pages that call outside services need a skip list.
- **Depends on:** A1 committed. Otherwise its signed-out test fails on day one, which is exactly what it is for.
- **Existing issue:** none. It gives more than #146 (visual comparison of screenshots) for less.

### E2. Make the reliable checks block merges into alpha

- **What:** require a set of checks to pass before a pull request can merge into alpha:
  - PHP syntax;
  - route targets;
  - static calls;
  - schema and seed parity;
  - migration idempotency (safe to run twice);
  - MariaDB-only SQL;
  - web-root shadowing;
  - no `.php` addresses;
  - personal-data coverage;
  - the self-tests.

  Keep the column checker advisory until its known false alarms are fixed (E9).
- **Why it matters for alpha testing:** alpha is what testers get. Today a pull request can reach it with a known
  failing check.
- **Evidence:**
  - **PROVEN (re-read today):** `.github/workflows/pr-security.yml` says each step is non-blocking except PHP lint
    (lines 10 and 438).
  - **PROVEN (sweep, comment on #147):** the automatic merge into alpha fires before the harness finishes.
- **Effort:** Small.
- **Risk:** Low to Medium. A false alarm would block merging. Every check on the list has been shown to catch its
  fault, and the self-tests prove the hardest two.
- **Depends on:** nothing.
- **Existing issue:** #474, and #108 (branch rules, which the sweep recommends reopening).

### E3. A new check: pages with no way in

- **What:** for every seeded address, confirm that some page, menu or template links to it. Keep an allow list for
  addresses that are meant to be reached another way:
  - public token addresses (`/e/`, `/f/`, `/os/`, `/a/`);
  - scheduled jobs;
  - the data interface;
  - form targets.
- **Why it matters for alpha testing:** this is the most repeated fault in the whole sweep: built, merged, closed, and
  unreachable (B1, B3 and B8).
- **Evidence:**
  - **PROVEN (re-read today):** `tools/audit-checks/` has no such check.
  - **PROVEN (project memory, "shipped but unreachable").**
- **Effort:** Medium.
- **Risk:** Low. Prove it fires on a planted fault before trusting it, and print how many addresses it examined (project
  memory, "checks only cover what they read").
- **Depends on:** nothing.
- **Existing issue:** none. Part of the idea is in #477.

### E4. A new check: a value written that its column does not allow

- **What:** compare every quoted status value that the PHP code writes against the column's list of allowed values in
  `full_schema.sql`.
- **Why it matters for alpha testing:** it would have caught A6 before a claimant did.
- **Evidence:** **PROVEN (re-read today):** no check in `tools/audit-checks/` reads the lists of allowed values.
- **Effort:** Small to Medium.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** none.

### E5. A new report: tables nobody writes, and flags and settings nobody reads

- **What:** a report-only list with an allow list, run on every pull request.
- **Why it matters for alpha testing:** "table nobody writes" and "flag nobody reads" were the dominant fault shapes in
  batches 1 and 6. Known cases:
  - tables: `tblRecurrenceRules`, `tblUserRoles`, `tblDepts`, `tblUserDepts`, `tblRecordingNote`, `tblEventOrgs`,
    `tblEventOccurrenceOverrides`;
  - columns and flags: `registrationEnabled`, `cancelReason`;
  - settings: `portal.first_run.steps.*`, and the settings listed in C8.

  Also fix the existing settings checker's blind spot: a seeded value containing `;` or `)` is misread.
- **Evidence:**
  - **PROVEN (sweep, batches 1, 3, 4 and 6).**
  - **PROVEN (re-read today):** the existing settings check only looks in one direction, for reads without seeds.
- **Effort:** Medium.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** none.

### E6. A new check: every role a page checks is a seeded role

- **What:** compare the names in `hasRole('…')` calls with the role names seeded in `tblRoles`.
- **Why it matters for alpha testing:** it stops a page being quietly limited to administrators by a role nobody can
  hold. It is also the safety net for A4.
- **Evidence:** **PROVEN (re-read today):** the list of role names in A4.
- **Effort:** Small.
- **Risk:** Low.
- **Depends on:** nothing. It is most useful alongside A4.
- **Existing issue:** none.

### E7. Two small checks for outside content

- **What:**
  1. **A page that embeds a frame must widen the frame rule.** This would have caught B2.
  2. **A weekly job requests every hard-coded outside address and reports any that fail.** This would have caught A5's
     dead chart service. Run it weekly, not on every pull request, so a brief outage elsewhere never blocks a merge.
- **Why it matters for alpha testing:** both faults reached testers and were invisible to review.
- **Evidence:**
  - **PROVEN (re-read today):** the evidence under A5 and B2.
  - **PROVEN (re-read today):** the planned `check_no_hardcoded_domains.py` (#500, plan-6 section 2.2) covers our own
    addresses, not outside ones.
- **Effort:** Small.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** none.

### E8. Correct the published API description, and validate it automatically

- **What:**
  - remove the form-token (CSRF) parameter from reading (GET) operations;
  - delete or replace the stale `docs/openapi.yaml`;
  - add a step that validates `web/_core/api-spec.json` against the OpenAPI standard on every pull request.
- **Why it matters for alpha testing:** a tester or partner building against `/api-docs` is told that reading data
  needs a form token. **INFERRED:** that suggests reading requires a browser session, which is wrong.
- **Evidence:**
  - **PROVEN (re-read today):** 12 reading operations in `web/_core/api-spec.json` mention a CSRF token:
    - `/api/v1/events` and `/api/v1/events/{id}`;
    - `/api/v1/announcements`, `/attendance`, `/prayer-requests`, `/documents`, `/expenses`, `/leadership`,
      `/noticeboard` and `/users`;
    - `/api/v1/assets` and `/api/v1/assets/{id}`.
  - **PROVEN (sweep, #55):** `docs/openapi.yaml` stopped at version 0.8.2.
  - The owner's standing rule: "a schema nothing runs is a document, not a check".
- **Effort:** Small for the corrections and the validation step. Medium for the 29 undocumented endpoints in #482.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** #482. There is none for the CSRF lines.

### E9. Finish the column checker's current round

- **What:** complete the round already in progress.
  - **PROVEN (handoff):** it now reads WHERE clauses, and it found two real faults (both being fixed).
  - **PROVEN (handoff):** its Codex review found three false alarms: SQL comments, a join alias named like a table, and
    a number such as `1e3`.
  - **PROVEN (re-read today, `checks.txt`):** the static-call check says it does not verify argument counts beyond
    zero arguments. Write that limit into the check guide, so nobody reads "passed" as more than it is.
- **Why it matters for alpha testing:** it is a prerequisite for making the column check blocking (E2).
- **Effort:** Small.
- **Risk:** Low.
- **Depends on:** Codex.
- **Existing issue:** none (the work is under #501's small-faults package).

### E10. Make the Codex review queue survive a session ending, and keep it in the repository

- **What:**
  - move `.claude-work/codex-queue.sh` into `tools/`, so it is kept in the repository;
  - start its waiter from the operating system's own scheduler, rather than as a background command of the Claude
    session;
  - have it write each result into a small review ledger (see E11).
- **Why it matters for alpha testing:** the owner's rules make a Codex review the gate before anything is committed.
  Today that gate depends on a script and a waiter that can silently vanish.
- **Evidence:**
  - **PROVEN (re-read today):** the queue script already checks that a review really finished. It looks for the
    usage-limit text, the "at capacity" text, a `tokens used` line and a real answer (lines 11–46).
  - **PROVEN (project memory, and the handoff):** every waiter died when the session ended at 09:04, and nothing said
    so.
  - **INFERRED (sweep, batch 7):** `.claude-work/` is ignored by git, so the script is not in version control.
  - **PROVEN (re-read today):** the queue list for the 17:39 run (`codex-queue-6.list`) is currently empty.
- **Effort:** Small.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** none.

### E11. A structured review-and-commit ledger, and a shorter handoff

- **What:** one line per package, covering:
  - its files, and a fingerprint of its diff;
  - its verify rounds;
  - each Codex round's verdict, and which model reviewed;
  - the commit it landed in.

  The handoff then points at the ledger, instead of carrying the same facts in prose.
- **Why it matters for alpha testing:** under the owner's hand-over rule, the handoff is what lets another system carry
  on. It has to be quick to read at the worst moment.
- **Evidence:**
  - **PROVEN (re-read today):** `.claude/HANDOFF.md` still opens with "Updated: 2026-09-11".
  - **PROVEN (re-read today):** its "latest" entries are not in time order (an entry "about 14:45" sits above entries
    at 13:20, 13:00 and 13:15).
  - **PROVEN (re-read today):** the state of each package has to be pieced together from several of those entries.
- **Effort:** Small.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** none.

### E12. Track every deferral named in a closing comment

- **What:** a closing routine. Each item deferred in a closing comment gets either a linked issue, or an explicit
  "won't do".
- **Why it matters for alpha testing:** a large share of the sweep's 43 follow-ups were deferrals that nobody tracked.
  Several were promised to "a separate issue" or "the sweep" that were never filed.
- **Evidence:** **PROVEN (sweep, the patterns sections of batches 4–9):**
  - #224, #227, #229, #230 and #231 were closed with deferrals and no tracking issue;
  - "tracked separately" with no issue (#409);
  - "worth its own issue" never filed (#478);
  - follow-ups promised under #495 and #497 were never filed.
- **Effort:** Small (a process change).
- **Risk:** none.
- **Depends on:** nothing.
- **Existing issue:** none.

### E13. Keep closing keywords away from unfinished issues

- **What:** a house rule and a small warning check.
  - Commit messages say "Refs #N".
  - "Closes #N" goes only in a pull request's text, after the acceptance criteria are checked.
  - A check lists every closing keyword in a pull request's commits that points at an issue which is still open.
- **Why it matters for alpha testing:** four unfinished issues will otherwise close themselves on the next merge to
  main (A2). The board's automation will then mark them Done.
- **Evidence:** **PROVEN (sweep, project-state).**
- **Effort:** Small.
- **Risk:** Low.
- **Depends on:** nothing.
- **Existing issue:** none.

### E14. Repeat the sweep before each promotion from alpha to beta

- **What:** run the same issue sweep (code-based verdicts, then an independent double-check) before promoting alpha to
  beta.
- **Why it matters for alpha testing:** this sweep found 14 closed issues whose core was never delivered.
  **INFERRED:** without a repeat, the same drift returns between promotions.
- **Evidence:** **PROVEN (sweep, doublecheck):** 14 reopens were confirmed.
- **Effort:** Small to Medium per run, now that the method and scripts exist.
- **Risk:** none.
- **Depends on:** nothing.
- **Existing issue:** none.

### E15. Apply the sweep's GitHub changes in one careful pass

- **What:** apply the confirmed changes in this order:
  1. deal with the closing keywords first (A2, E13);
  2. apply the 14 reopens and the 1 close;
  3. file the 43 follow-ups;
  4. post the comments;
  5. bring the board to 137 items;
  6. make the label merges that are safe to make without asking.
- **Why it matters for alpha testing:** testers and the owner will use the issue list and the board to see what is
  known.
- **Evidence:** **PROVEN (sweep):** `doublecheck.json` and `project-state.md`.
  - **INFERRED from GitHub's documentation:** setting an open issue to Done on the board closes it, so no open issue may
    be set to Done.
  - **PROVEN (sweep):** reopening does not move a card back, so #26 must be moved by hand.
- **Effort:** Small.
- **Risk:** Medium, because of the board automation. Follow `project-state.md` exactly.
- **Depends on:** the owner's approval.
- **Existing issue:** none.

---

## Deliberately not proposed for alpha

- **BookIT integration (#97–#103).**
  - **PROVEN (sweep):** nothing is built.
  - It is a new outside dependency, while the portal has enough half-finished edges of its own.
- **Mission trips (#302), simulated-live playback (#320), watch parties (#321).**
  - **PROVEN (sweep):** none is started.
  - Alpha testing is for finding out whether what exists works.
- **Plugin framework (#155) and WordPress Multisite (#127).**
  - #127's public-embed part belongs to #493.
  - Its unused setting is covered in C8.
- **Visual screenshot comparison (#146).** E1 catches more for less.
- **Production secrets behind an approval step (#105) and signed commits (#106).** Both are worthwhile repository
  hygiene with no effect on testers. Do them with #108, after E2.
- **The database version move (#475) and the structure re-check (#487).**
  - #475 is blocked on what the hosting offers.
  - #487 follows it.
  - Do settle both before the first customer, and before D4.
- **Per-event time zones (#238).** The sweep recommended reopening it. My recommendation is that the owner instead
  closes it as "won't do for now", since no calendar code reads the field.
- **Settings change history (#484).** It is useful for support, but it does not change what a tester sees. Revisit
  before beta.
- **Updating Swagger UI (#486).** There is no reason for it yet.
- **Splitting the two very large classes** (`AssetRegister.php`, `Venues.php`). This is high risk with no visible
  benefit during testing.
- **The small clean-ups in #483.** Do them after the B8d and B8e decisions, because #483 would delete the only
  surviving code for those features.
- **From the research ledger's out-of-scope list:**
  - a native phone app;
  - school management features;
  - text-to-give;
  - automatic volunteer scheduling.

---

## How the proposals depend on each other

- **A1 (sign-in fix)** comes first. B1, C1 and E1 assume protected pages are protected.
- **A3 (readable secrets)** unlocks every integration and every scheduled job. B4, B8a, B8c, B10, B11, B12, B13, C6,
  D1, D6 and D9 all wait on it.
- **A4 (roles and departments)** unlocks Expenses on a fresh installation, and every job that is today limited to
  administrators. A8, B14, D1 and D7 wait on it.
- **A2 (landing the finished work)** must come before A7, B2, B3, B5, B6, B7, B9 and C7. Those items touch files that
  are currently uncommitted.
- **B5 (repeating events)** is what #333's overrides and #338's calendar-download repeat rule are waiting for.
- **E2 (blocking checks)** is safest after E9 (fixing the column checker's false alarms).
