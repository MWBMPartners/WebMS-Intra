# Handoff — branch clean-up, full audit, documentation refresh

**Updated:** 2026-10-27 (kept current as the work happens, so it can be picked up at any point).
**Working branch:** `claude/alpha-wip` — the single work-in-progress branch.
**Target:** one pull request into `alpha` at the end. No stacked pull requests.

---

## 27 OCTOBER 2026, LATEST — slices 5 and 6; two owner decisions carried out

**Two decisions taken (question tool, settled):** (a) a snapshot now RECORDS whether it covers the
whole database; (b) the data download drops the third-party columns only. Both built and committed
as `b7496b7`. Slice 6 (outside calendars) is running.

### Slice 5, backup and restore — and a lesson in reading before fixing

Codex: "not safe to ship", six findings. The real one: **`restoreAll()` took its table list from
the snapshot's manifest and asked nothing else**, so a manifest edited down to one name turned
"restore ALL" into exactly the one-table restore the other path refuses.

**The obvious fix was already rejected IN THE CODE, with a good reason** — refusing those tables
in `restoreAll()` too "would make the pre-upgrade snapshot impossible to undo, which is the one
thing it exists for". I read that before touching anything. So the fix is the owner's choice: a
snapshot says `whole_database`, and a manifest that does not say so has the three protected tables
held back, **named in the answer**, with the rest restored. Six cases proved, including that a real
pre-upgrade snapshot still restores everything.

Format number deliberately NOT raised (compatible both ways; raising it would make older releases
refuse readable snapshots). Schema updated — mandatory, because that object sets
`additionalProperties: false` — and the committed sample refreshed with `--write-samples`, which
is what proof 1 of the backup self-test checks.

**Slice 5's other five are NOT fixed:** case differences defeating the engine/queue checks; foreign-key
checking assumed rather than enforced; a column whose type changed silently altering a value (#595
already has the lasting fix); a restore that cannot resume inside one request; and a skipped
duplicate still overwriting the kept row's self-links. Written up in
`.claude-work/resume/codex-pass/slice-5-result.md`.

### The download

`SELECT *` on prayer requests handed a person the account numbers of whoever moderated their
request and whoever it was assigned to; booking notes came along because they were the last to
EDIT the booking. Columns now named explicitly. `tblExpenseClaims` was checked and left: its only
person column is the person's own. Three checks pin it (coverage 65 -> 68), proved both ways.

### Still owed, and said plainly

- **No end-to-end self-test of the held-back restore path.** The six cases exercise the decision,
  not a real restore of a flagless manifest. It belongs as a new proof in
  `backup-restore-selftest.php`.
- Proof 19 of the backup self-test still fails about three runs in five, #636, unrelated to any of
  this — it failed identically before the commit.
- Slice 1's findings 3-5, slice 4's 4-5, slice 5's 1 and 3-6 are all unfixed and written up.
- Sweep: README and FEATURES done (`7a0e3a4`); **DEV_NOTES items and group 4 still to do.**
- Slices 7, 8 and 9 queued: `IcsReader.php`; `FeedAdmin.php` + `ImportNotifier.php`; money.

### Two traps worth carrying forward

- **A check that reads prose judges the wrong thing.** My first download checks failed on the
  page's own comment, which quotes "SELECT *" as the thing not to put back. Strip comments first.
- **Recreate the test database between planted runs.** A plant that stops part way leaves rows
  behind and every later run then fails for that reason instead.

## 27 OCTOBER 2026, LATER STILL — Codex slices 3, 4 and 5; the sweep; and two faults #626 left behind

**The owner said to proceed, including the queued item 5 (slices 3-9 and sweep groups 3-4).**
Slices 3 and 4 are done and their findings acted on; slice 5 is running. Full write-up:
`.claude-work/resume/codex-pass/slices-3-and-4-result.md`.

### The important one: #626 left two real faults, and nothing caught either

Both in `ActivityLogCleaner.php`, both written three commits earlier, both fixed in `6fae118`:
- **A cut-short session copy was attributed to the WRONG PERSON.** One pattern over the whole
  text took the first account anywhere, nested objects included. Reproduced exactly:
  `{"nested":{"user_id":42},"user_id":7,"cut":` answered 42. The record would show in another
  member's own download, and the blanking step destroys the copy, so nothing could correct it.
- **A new record could be written about somebody already erased.** The key-warning step asked
  only whether the account row EXISTS; an erased account still has one. `linkAccounts()` had
  the right guard a few hundred lines below; this sibling path did not.

**The activity-log self-test passed 91 of 91 on BOTH broken versions.** Measured, twice. Two
checks added, each proved to fail on its own fault and only its own (93/93 now).

**A testing trap worth remembering:** planting a fault in a self-test that writes to a shared
database leaves rows behind when it stops part way, and every later run then fails for THAT
reason. I read "83 passed, 8 failed" twice and nearly recorded a regression that did not exist.
**Recreate the database between planted runs.**

### What else landed

| What | Commit |
| --- | --- |
| Slice 1 + 3 fixes: an erasure refuses rather than erasing less; two routing faults; a new erasure-column tripwire | `7f51ab4` |
| Slice 4 fixes: the two above, three untrue comments, two new self-test checks | `6fae118` |
| Sweep group 3: README and FEATURES — twelve untrue claims, and the missing #622/#625 entries | `7a0e3a4` |

**Slice 3's best news:** Codex examined the multi-organisation boundary and found **no confirmed
cross-organisation leak**. The membership gate and both API switch paths check out.

**Three of eleven findings I pushed back on with evidence rather than changing working code:**
the asset-identifier lowercasing (the lookup is case-insensitive, so it matches anyway); a
column that "cannot be emptied" (no live column reaches it — 21 of 22 are nullable and the
22nd is deliberately handled, so the answer was a tripwire); and interpolated constants called
an injection risk, which Codex itself conceded.

### Still open, and worth the owner's eye

- **Slice 4 finding 1 needs a decision:** the person's own download hands over OTHER PEOPLE'S
  details — every prayer-request column (`moderatorID`, `assignedToUserID`, which the catalogue
  itself marks as separate people) and booking notes that may hold a third party's telephone
  number. Same shape as the `activityDescription` decision already taken, so the principle is
  settled; which columns go is not.
- **Slice 1 findings 3, 4 and 5 are unfixed** (concurrent erasures restoring an address; a retry
  losing a changed address; unbounded mail clean-up in one transaction), as are slice 4's 4 and 5.
- **#638 filed:** four self-test checks ALREADY FAIL on the committed branch — site-modes S20,
  S69, S71 and site-addresses F12. Proved pre-existing in a worktree at HEAD. S69's own detail
  shows the protection working and the response differing, so it reads as a reporting fault.
- Sweep group 3 still has the DEV_NOTES items; group 4 (the API description's real gaps) untouched.

## 27 OCTOBER 2026, LATER — the code queue emptied, then the Codex pass found two ways past sign-in

**The queue was empty of code work; it is not any more.** With X1-X9, WF, #604 and #626 all landed, I
started the two things left that were not blocked on the owner: the documentation sweep and the
end-of-queue Codex pass. Both found real work.

### THE SIGN-IN HOLES — the most important thing on this page

**Codex slice 2 (`Auth.php`, `ApiAuth.php`, `Totp.php`, `Captcha.php`) returned "not safe to ship".
Two holes were real, and are fixed in `6a6fbd6`.** Full write-up:
`.claude-work/resume/codex-pass/slice-2-result.md`.
1. **An OAuth callback was accepted with no sign-in started** — both sides of the state comparison
   defaulted to empty, so `?state=` passed. Signs a visitor into the attacker's account.
2. **The second factor could be skipped** by posting `?redirect[]=x`: the gate built the redirect
   before unsetting `user_id`, `safeRedirectUrl()` took `string`, so it threw and the session stayed
   signed in. **On the password form that needed nothing but the victim's password**, and it was
   REPRODUCED LIVE on the old code.

**Three lessons worth more than the fix:**
- **Codex found two of four places. The other two came from its leads, not its list.** The password
  path was found because its comment claimed to mirror the callbacks "EXACTLY" — it did, fault
  included. The passkey path was found by the independent checker, because a search for
  `safeRedirectUrl($_GET[` cannot see a value read from posted JSON.
- **Nothing in the repository caught any of it**, and my own first proof passed on fully reverted
  code because it re-implemented the expressions instead of loading the file. The checker measured
  that. There are now two real checks: `tools/audit-checks/check_2fa_gate_order.py` (6 planted faults,
  all caught) and section Z of `tools/two-step-selftest.php` (93 checks; Z1/Z2 fail on the old code).
  **Both are needed** — reversing the order alone is invisible to the self-test.
- **A third fault is LIVE on `main` and not fixed there:** 1.4.0's OAuth callbacks never call
  `userRequires2fa()`, so Microsoft and Google sign-in skip two-factor entirely. #B2 added that gate
  on this branch only.

**OWNER'S ANSWERS, 27 October (question tool; settled):** (a) **no portal is live yet**, so nothing
is exposed; (b) these go in a **private GitHub security advisory, not a public issue** — draft ready
at `.claude-work/resume/security/ADVISORY-DRAFT--two-sign-in-holes.md`, for the owner to create.
**Do not open a public issue for them.** One point left for the owner in that draft: the fix commit's
comments explain both faults, so pushing it publishes them while `main` is still unfixed.

### The documentation sweep

Three read-only gatherers, 56 findings, triaged in
`.claude-work/resume/doc-sweep-2026-10-27--TRIAGE.md`. Committed: `c8d12b9` (seven instructions that
would have done harm — including DEV_NOTES telling you to push a migration to `main` "it deploys to
dev", which would run untested SQL on a customer's live database) and `21875b5` (statements users and
administrators were told that were untrue). **One of the 56 was WRONG** — `/health` in INSTALL.md is
correct, because `Router.php:327` answers it before the routing table; the gatherer had checked only
the seed file. Verify before fixing.

**Still to do from the sweep:** groups 3 and 4 of the triage — about 30 more untrue statements, and the
real gaps (the API description covers 44 of 61 reachable endpoints; 181 schema properties have no
description; **nothing anywhere executes `api-spec.json` against the code**, which is how ten untrue
statements accumulated in it).

### The Codex pass — 2 of 9 slices done

`.claude-work/resume/codex-pass/PLAN.md` has all nine, sized to one allowance each, worst first, with
reusable prompts. Slice 1 (the erasure engine) returned five findings worth acting on and two I pushed
back on with evidence — **nothing from slice 1 is fixed yet**. Slice 2 is fixed. Slices 3-9 are queued
and block nothing. Two things that make a slice work: a fixed file list with "do not read the
instruction files", and telling it not to report interpolation of a constant as an injection — it
overstated exactly that in slice 1 and complied in slice 2.

### Still blocked on the owner

The push (23 commits local only), the required status checks, holding alpha's auto-merge before the
first deploy, and now the security advisory. HIST stays last, after the sweep is finished.

## 27 OCTOBER 2026 — X8 and X9 committed; the WF fix round built and in check; #626 built and in check

**Both remaining code packages are in flight at once, which the owner's 12 October decision allows: they share no
file.** WF changes `.github/workflows/`, `tools/deploy/`, `tools/e2e-migrations/`, `tools/db-strict-mode-selftest.php`
and `tools/audit-checks/check_bind_param_arity.py`. #626 changes `web/_core/GdprEraser.php`,
`personal-data-catalogue.php`, `web/_apps/admin/reports/data.php` and the two GDPR self-tests. The shared documents
(`CHANGELOG.md`, `DEV_NOTES.md`, `FEATURES.md`) are written by WF only; #626's lines wait in
`.claude-work/resume/rollout-626--DOC-ENTRIES.md` and go in when it is committed.

### Landed

| What | Commit |
| --- | --- |
| X8 (#610 found 1, the `Sms::send()` gate) + X9 (one-table restore guard) + the mail tests #625 had broken | `98ab31f` |

**I broke two tests landing X6 and did not notice.** `mail-queue-selftest` (150/155) and `mail-queue-pages-selftest`
(117/118) went red because #625 gives every answer a fresh one-off script number, and those checks compared two whole
answers byte for byte to prove the portal never says whether an address has an account. X8's checker found it. Both
now strip the number before comparing. **The lesson for landing: running "the tests the package names" is not enough
when a change alters something every page emits.** Recorded as item 7 of #636.

### WF fix round — COMMITTED as `c61211e`, after its check found eight more problems

**The check of my own fix round was NOT CLEAN: eight real problems, seven of them untrue sentences rather than
broken code.** All eight are fixed in the commit. The one that matters most:

**The checklist I wrote for the owner would have locked every pull request into `main`, `beta` and `alpha` for
ever.** It told them to require `Build and check the package` — and `package.yml` has **no `pull_request` trigger at
all** — plus the backup and migration-harness jobs, both path-filtered. **A required check that never reports is a
pending check for ever**, which is the same trap the document opens by warning about. The checklist now names the
ten checks that really do run on every pull request and names the six that must never be required. **If you write
anything about required checks, verify the trigger and the path filter of each name first.**

The others: "nine new test jobs run on every pull request" was untrue in five places (two are path-filtered, two
were not new, and two self-tests plus seven of the 32 audit scripts run in no workflow at all); the guide turned
uploads off for the dry run and never turned them back on; the alpha bridge starts a MANUAL run so four of the
guide's statements about an alpha zip were wrong (14 days not 90, and a different message); my comment on the one
code change gave a false reason (swallowing the failure protects against a red mark, not against losing the deploy
dispatch, which GitHub has already accepted); my corrected `continue-on-error` count was itself wrong (six before,
one after — five went, two with Psalm and three from `pr-security.yml`); and `DEV_NOTES.md` section 3b told somebody
to delete a channel's folder on the strength of a layout that was never built, which now opens with a warning.

**What held:** the code change is safe (a dispatched `package.yml` cannot publish, `actions: write` is present, no
loop, deploy-first order right), the three-file merge lost nothing from either side, the `lftp` correction matches
the real trial output word for word, and the claim that the package's only `run.sh` change is comment text is true.

### How the fix round was built (kept for the record)

Worktree `.claude-work/worktrees/wffix`, branch `ro-wf-fix`, uncommitted. The package (built against `aab9add`) was
applied onto `98ab31f` with a three-way merge. **Only one file conflicted** — `php-static-analysis.yml`, resolved the
package's way (the Psalm job goes; the Dependabot bump inside it went with it). `codeql.yml` merged keeping the
branch's bump to `v4.39.1`; `tools/e2e-migrations/run.sh` merged cleanly and **the package's change to it is
comment-only**, proved by filtering the diff, which is why the migration harness was not re-run.

All five problems from `rollout-WF--check-RECOVERED.md` are closed:

1. **The roll-out order could not be obeyed** — the guide said "merge nothing until the dry run has been read", but on
   `alpha` the bot merges and then starts a REAL deploy. Part 0 gained a step 3 (switch "Allow auto-merge" off, or
   draft every open pull request into `alpha`), part 4 step 1 now says to put `SFTP_ENABLED` back to `false` as soon
   as the dry run has been read, and the top of the guide points at both things only the owner can do.
2. **Required checks** — new part 10 of the guide, pointing at the checklist already written. Owner's to run.
3. **`continue-on-error`** — the owner's page said there was none; corrected to name `codeql.yml` and say plainly that
   with Psalm gone **no code-analysis check here can turn a run red**. (Tracked onward as #618.)
4. **Alpha almost never built a zip** — `auto-merge-alpha.yml` now also runs `gh workflow run package.yml --ref alpha`,
   beside the deploy dispatch it already had. Its failure is swallowed with a warning on purpose: `gh workflow run`
   cannot find a workflow until its file is on `main`, and a hard failure would lose the deploy dispatch.
5. **"Making directory …"** — `lftp` never prints those words; the guide now says `mkdir -p`, proved against the F7
   trial runs, and explains `get -O` and `chmod -f 644` so they do not alarm anybody.

Also: `README.md`, `FEATURES.md`, `DEV_NOTES.md` no longer describe `release.yml`, and `DEV_NOTES.md`'s "tag a
release" step is replaced by what really happens. Checks run: actionlint 1.7.12 on all 15 files (exit 0 under
`SHELLCHECK_OPTS=--severity=error` as `lint.yml` sets it, and with no setting on the file I changed, before and
after); deploy settings self-test 386/386; package self-test 340/340 in 4 min 36 s; all 32 audit checks; `php -l`.

### #626 PART ONE — COMMITTED `fd6b8b2` (documents `8bf7967`); its check found the fix was NOT ENOUGH

### #626 PART TWO — COMMITTED `edf56ff`. THE OWNER REJECTED "accept the limit"

**What part one's check found, and it was right.** An erasure matches ONE COLUMN (`userID`). `Logger::activity()`
took the account number as an OPTIONAL third argument and 279 of its 582 callers under `web/` left it out —
`Auth.php`'s own `LoginLocal` line among them, which runs AFTER `$_SESSION` holds the account. Those records had an
empty column and session facts naming the person, so an erasure went straight past them. Part one's instruction was
therefore real but insufficient, and every sentence implying otherwise was corrected rather than left standing.

**The owner's decision, 27 October 2026 (question tool):** offered "accept the limit, build the rest later", they
answered *"I dont like your 'accept the limit' proposal. i would rather do this properly now. what would be the best
'proper' fix now? remember GIRFT"*. So part two fixes the CAUSE.

**THE ANSWER, and it is smaller than any of the three routes I offered.** The fix is in the LOGGER, not the eraser:
`Logger::activity()` already reads the session to build the facts it stores, then wrote NULL into the column
anyway. It now fills the column from `sessionFacts()['userID']` when the caller named nobody. `WHERE userID = ?` is
then correct and the part-one instruction is sufficient. **Rejected on purpose: matching on the session fingerprint**
(a shared machine or kiosk means one fingerprint is not proof of one person — how this eraser has wiped other
people's records before, #574) **and matching on the account number inside the stored facts** (no index, and #622
measured 175 seconds for one statement over 500,000 rows, which a shared host cuts off mid-erasure).

**Three things that make this the GIRFT answer rather than a patch:**
1. **Two unreported gaps close.** `auth/account/data-export.php` ("what we hold about you") and the "filter by
   person" list on Admin, Activity both read that column, so a person's own sign-ins were missing from both.
2. **Nothing is re-attributed.** The 303 callers that pass a number are untouched, and all of them pass the ACTOR
   (`$actorUserID`, `$userId`) — verified — so filling from the session agrees with them rather than changing what
   the column means. `waitingForCodeUserID` and `kioskUserID` are never used: neither is the person whose record it is.
3. **The harmful case is handled.** A naive backfill would UNDO erasures: for somebody already erased the column is
   empty and the facts still name them. `ActivityLogCleaner::linkAccounts()` CLEARS those records instead, which
   retroactively finishes erasures that could never reach them. It tests with `GdprEraser::emptiedAccountSql()`, the
   one definition the portal uses.

**The ordering trap, written down because it is not obvious:** `linkAccounts()` MUST run inside the existing walk
and BEFORE its blanking step. That step blanks every old whole-session copy, and the copy is the only place a
pre-#622 record says whose session it was — a separate pass would always arrive to find the evidence gone. That is
why migration 221 resets `activityLog.cleanedThrough` rather than adding a second marker: one walk, one mark, one button.

Nine files. Checks: activity-log self-test 87/87 (was 77) and eraser 230/230 on MySQL 8.0.36 **and** 8.4.11;
erasure pages 66/66; coverage 63/63; migration harness ALL PHASES PASSED, 42 ticks; three planted faults each caught
by the right check, including the one that would undo an erasure; all 32 audit checks; `php -l`; `bash -n`.

**Two harness failures on the way, and NEITHER was a test artefact — both are worth knowing:**
- My phase-7 plant first carried a RAW session identifier, which also counted towards migration 220's "activity
  records with an identifier" and made phase 7 say the database was not the one it was about. The record should
  carry a FINGERPRINT anyway (it stands for a post-#622 record that merely lacks the account column).
- **Migration 221 writes the cleaner's "how far" row with `0`, and phase 7's migration-220 check expected that row
  to be ABSENT.** I widened that expectation to `0` rather than changing 221, because `0` means "start at the
  beginning" and the check exists to catch `all` over a log that still holds old records. **That is me editing
  somebody else's check so my own package passes, so the checker was asked to judge it specifically.** General
  lesson: a migration that writes a settings row can break another migration's phase check without either being wrong.

**ITS CHECK WAS NOT CLEAN — four real problems, all fixed, and two of them were tests that proved nothing:**

1. **Migration 221's ONE JOB was covered nowhere.** Every installation that finished the #622 cleaning already
   holds the mark, so the live path is the "row exists" branch — and phase 7's database has no row while the
   self-test set the mark by hand. The checker changed the migration to leave an existing mark alone and **the
   whole harness still passed**; the erasure gap would then have stayed open for ever. Two checks now cover it,
   including that a second run does not send an administrator back to the start.
2. **"A kiosk's own account" was untrue.** `kioskUserID` is A REAL MEMBER's account — the one a shared kiosk has
   been handed to by a PIN (`_apps/assets/kiosk-action.php`), and `sessionFacts()`'s own docblock twenty lines
   away said so correctly. It is still left out, for the right reason: a kiosk session outlives the person at it,
   so naming them could blame them for somebody else's action. **A kiosk record therefore still names nobody and
   an erasure cannot reach it** — the same gap in miniature, left open because what a shared device's record means
   is the owner's decision. "One rule covers every record there is" is corrected to "every record of somebody
   signed in as themselves". The CLEANER's half of that rule was also unchecked (the checker made it fall back to
   `kioskUserID` and everything still passed); it is checked now, as is the pattern branch for a cut-short copy.
3. **The Upgrade page and health line described only the older job**, so once migration 221 re-armed the walk they
   told administrators something untrue about what was waiting and gave them no reason to press the button again.
4. **The data download handed over other people's details.** Records that used to be invisible there are now the
   actor's, and about two dozen call sites put an invited guest's e-mail address or an outside crew member's name
   into the free-text description. **OWNER'S DECISION, 27 October 2026 (question tool): leave the description out
   of the download.** Type, address and time stay. Stripping names out of the text was rejected — pattern-matching
   free text from ~550 call sites would miss cases while looking as though it had not. Two checks pin it, because
   the obvious "improvement" is to put it back.

**Counts:** part one's "279 of 582" was inflated by matches inside COMMENTS, including the ones I had just
written. Two careful counts the same day still disagreed (550/302/248 vs 551/295/256), so the prose says "roughly
half" and `Logger.php` records how to count. The repository rule is that counts are not written down.

**One judgement to revisit if it ever looks wrong:** phase 7 expected the cleaner's mark row to be ABSENT after
the installer's "carry on"; migration 221 writes it with 0, so I widened that expectation rather than changing the
migration. The checker was asked to judge that specifically and agreed. General lesson: a migration that writes a
settings row can break another migration's phase check without either being wrong.

Final checks: activity-log 91/91 and eraser 230/230 and erasure-pages 66/66 on MySQL 8.0.36 AND 8.4.11; coverage
65/65; access-pages 293/293; upgrade-pages 95/95; harness ALL PHASES PASSED. Seven planted faults each caught by
the right check. Documentation from `rollout-626b--DOC-ENTRIES.md` REPLACED part one's entries, which described the
gap as open.

Worktree `.claude-work/worktrees/e626`, branch `ro-626-build`, uncommitted, cut from `98ab31f`. Five files.

**What #626 really was.** `tblActivityLogs` is marked "unlink" in the written list and names six columns. The derived
route builds one instruction per column that holds an ACCOUNT NUMBER and empties nothing else — so five were named as
personal and left in place for months, the session facts still holding the account number that had just been emptied
beside them. The fix is a hand-written instruction with those five in `nullCols`, the only way to empty a column that
is not itself a link to an account.

**The trap to know about:** a hand-written entry WINS over the derived one for the whole table, so it must keep doing
everything the derived one did. It matches on `userID`, and `processEntry()` always empties the matched column too.

**`sessionID` is included, and that was a judgement.** The issue names five details, the handoff's version of the
owner's question named four and left the fingerprint out. It is in because `admin/reports/data.php` already described
the consequence (an erased person's password-and-code sign-ins count twice) **as though the eraser did this** — that
comment was untrue when written and is now true. The checker was asked to judge that reasoning.

Checks: coverage self-test 63/63 (was 60); eraser self-test 229/229 (was 225) on MySQL 8.0.36 **and** 8.4.11, and
225/225 on the unchanged code so there is no regression; erasure pages 66/66 on 8.0.36; four planted faults each
caught by the right check, including the one that matters most — hiding the fault by pinning the table.

### Issues filed today

- **#634** — seven handlers check the form token and hand back no replacement, so a background post works once. Two
  proved repeat-use (`livechat/api/moderate.php` from `admin/live/chat.php`; `prompt-publish.php` from the Host
  Console, which holds the token in a `const`). Four are reached from nothing, one is called by nothing at all.
- **#635** — 35 MORE tables name a column as personal that nothing empties. `tblErrors` (address, browser name,
  headers) is #626 over again. Pinned by name in `gdpr-coverage-selftest.php` so the list must shrink, never grow;
  each table is the owner's decision.
- **#637** — the deploy documents describe two superseded layouts as current, and seven automatic checks run in no
  workflow (two of them this week's tripwires, for #625 and #622). Filed from the fix round's check.
- **#636** — seven findings from the 25–27 October packages. Worth doing first: proof 19 of the backup restore test
  fails about three runs in five regardless of any change, and `docs/disaster-recovery-runbook.md` still tells
  somebody to restore the three tables the portal now refuses — a runbook read during an incident.

### Next

1. **WF is committed (`c61211e`).** Read #626's check report when it lands, fix what is real, and commit it with the
   documentation lines from `.claude-work/resume/rollout-626--DOC-ENTRIES.md` (which also holds the one standing rule
   to add to `.claude/CLAUDE.md` and `.OpenAI/CONTEXT.md`).
2. Then **HIST** (the history rewrite to remove the owner's real name) — last before the pull request, prepared and
   rehearsed in a mirror clone, with the owner running the push.
3. **The push is still blocked on the owner.** 14 commits are local only; two attempts were refused by this
   machine's permission rules, the second as "Out-of-Place Publication". Nothing has reached GitHub.

---

## LATER ON 26 OCTOBER 2026 — X3, X7 and X8 done; the lost WF report recovered; what the checks found

**Two more of the owner's answers, 26 October (question tool; settled):**
5. **`codeql.yml` KEEPS its `continue-on-error`, and the WORDING is corrected instead.** The package cuts these from
   six to one; the one that stays is deliberate, because GitHub's paid Code Scanning is not enabled here and the
   CodeQL upload is refused without it. Removing the flag would turn the job red on every pull request for a billing
   reason, not a code one. So the sentence "No `continue-on-error` anywhere" in the owner's approval page and in this
   file is UNTRUE and must be replaced with one naming the file that keeps it and why. **Still to do.**
6. **All nine new test jobs become required status checks** on `main`, `beta` and `alpha`, and `Psalm` is removed.
   The assistant cannot read or change branch rules (`tools/audit-required-checks.py` quietly returns success without
   admin scope), so it PREPARES the exact job names and `gh` commands as a checklist and **the owner runs them**.
   **Still to do.**

### Landed on the working branch

| What | Commit | Notes |
| --- | --- | --- |
| X3 (#610) held texts | `00fa9ad` | See the earlier block. The clock-change window fault found while landing it. |
| X7 (#623) QR pictures | `1dce495` | Two commits joined (`c8c6044`, `a7cbbe7`) plus documents. |

**X7's story is worth reading before touching anything that draws or fetches a picture.** Two pages made the SAME
mistake — building a `chart.googleapis.com` address — and it was invisible on one and live on the other. On
`auth/2fa/setup.php` the portal's own page rule refused the image, so the box was simply empty: broken but nothing
left the browser. On `calendar/public-landing.php`, which writes its own `<head>` and sends no such rule, the picture
really loaded and every view sent the event's address and the visitor's IP to Google. **When judging whether a page
can make an outside request, check whether it includes `templates/header.php`.** The first independent check found
that page; the second proved the leak gone by serving the page with every network function in PHP disabled.

**Both of my own fixes in X7 were caught being wrong by the checks, and both in the same way: an untrue comment.** My
first fix said the address was printed "either way" when it actually sat inside the test for the picture, so a failed
drawing hid both. Worth remembering as a habit: when you add a guard, check every promise already written near it.

### Built and checked, NOT yet landed

| Package | Branch / commit | State |
| --- | --- | --- |
| **X6** (#625) | `ro-x6-build`, `808c794` + `2b53dae` + uncommitted | One check (NOT CLEAN, one real problem) and TWO fix rounds. Round 1: my Worship fix learned the replacement token only from its own answer, so two presses before the first reply shared a token and the console moved one slide for two presses, silently — the very fault #625 is about, in the page I had just fixed. Round 2: serialising the commands made a HANG far worse than before (a refused request rejects and is reported; one that never answers never rejects, so every later press waited for ever while the slide view kept refreshing and the page looked alive — three presses reached the portal 0 times and said nothing). Closed with a 10-second `AbortController` deadline, and `refreshToken()` now reads the portal's own `<meta name="csrf-token">` instead of pattern-matching the page's script text, which was picking the right one of two matches by page ordering rather than by design. **Verification of round 2 was running when this was written.** |
| **X8** (#610 found 1) | `ro-x8-build`, `a202098` | Built and tested, NOT independently checked. `Sms::whyNotThisPerson()` now returns a REASON and two translators give the words, so `send()` can ask the same question `sendHeld()` asks without a second copy of the rule. Asked BEFORE holding, and of every kind including `NEVER_HELD`. 115 of 115 on MySQL 8.0.36 (up from 106: part M's eight checks and L1b), 8 planted faults all caught. **Not run on 8.4.11.** Document lines wait in `rollout-X8--build-evidence/DOC-ENTRIES.md`. |

**X8's two self-inflicted faults, both found by its own new test and both of which would have recorded something
untrue:** a refused text was stamped with a "sent at" time, which the Recent-messages list shows (`record()` already
had a switch for this and it was not being passed); and a text for a DELETED account stopped the whole run on
`fk_sms_user`, because the gate found "no account" and then wrote a record naming somebody no longer in `tblUsers` —
that key is `ON DELETE SET NULL`, so the record now names no person.

**Two existing checks in the held-texts test changed, both because behaviour really changed and neither made weaker.**
H8 used to expect a person who was NEVER a member to have their text held and judged two days later; it is now refused
as it is sent, so H8 asserts BOTH paths. L9 counts the sentences the class can record and had 13 written in; there are
now 17.

### WF — the lost report is recovered, and it is NOT CLEAN

`.claude-work/resume/rollout-WF--check-RECOVERED.md`. Five real problems, none in the code that uploads or publishes.
I verified two of them myself against the files rather than taking them on trust:

1. **A real deploy can reach a customer's server before any dry run is read.** The guide says "merge nothing until the
   dry run has been read", but `auto-merge-alpha.yml` merges alpha pull requests by itself and then runs
   `gh workflow run deploy.yml --ref alpha` **with no inputs**, so `dry_run` takes its default `false`. CONFIRMED by
   reading both files. The owner must close or draft open alpha pull requests, or switch auto-merge off, before setting
   `SFTP_ENABLED` to `true`.
2. **The required status checks** — the owner's answer 6 above.
3. **`codeql.yml` keeps `continue-on-error`** — the owner's answer 5 above. CONFIRMED: six on the branch today, exactly
   one at the package's tip.
4. **Almost no merge into `alpha` will build an installation zip**, although "built on every merge" was approved:
   `package.yml` triggers only on `push`, and GitHub starts no run for a push attributed to its own token. Fix is one
   `gh workflow run package.yml --ref alpha` beside the existing deploy bridge; a manual run never publishes.
5. **The guide tells the owner to look for "Making directory …" in the dry run and `lftp` never prints those words**
   (it prints `mkdir -p <address>`), so its absence would read as a fault.

Also in its "Found": four documents still describe the deleted `release.yml` and hand tagging, which meets this
project's "untrue message" test and the fix round should decide.

### FOUND by the checks, not fixed, each owed an issue

- **Eight handlers have the same spent-token fault as the Worship console, all pre-existing.** Worst:
  `geo/w3w-suggest.php` with `_core/partials/location-map-assets.php` (one POST per 400 ms of typing), and
  `auth/account/webauthn.php` (two POSTs per click, so passkey registration may never have completed for anybody).
  Then `geo/lookup.php`, `worship/plan-reorder.php`, `livechat/api/prompt-publish.php`,
  `noticeboard/api/{save,upload}.php`, `tours/api/complete.php`, `documents/api/update.php`. The house convention
  (`ApiAuth::requireWrite()` + `ApiResponse` → `meta.csrfToken`) already solves it and two callers do it right. Worth
  one issue and an audit check.
- **`calendar/public-landing.php` builds its address from `$_SERVER['HTTP_HOST']`**, so a forged Host makes the printed
  address AND the QR picture point at a host the visitor chose. Not new, not worsened by #623. `PortalAddress` would fix
  the printed address, the picture and `og:url` together.
- **`tools/site-addresses-selftest.php` matches app switches with a pattern blind to a name holding an underscore**
  (line 780), so it never reaches the service-plans or reading-plans pages.
- **Three faults neither X6 tool catches:** a session-wide nonce (passes 88 of 88 — nothing would catch that
  regression), an unclosed HTML comment, and `setAttribute('onclick')` from a script file.

### Still to pick up, in order

1. **X6:** land it once the round-2 verification is back.
2. **X4 (#622):** its check was cut off on 14 October and never wrote a verdict; a fresh checker is now running it
   **against the landed tree**, because X7 changed `tools/two-step-selftest.php` (now 90 checks) and X4's own log
   recorded that test failing there. Its patch applies cleanly to `claude/alpha-wip`.
3. **X8:** its one independent check, then join with its document lines.
4. **WF:** the fix round for the five problems above, plus the owner's checklist for the branch rules.
5. **#626** (erasure blanks the visitor's address, browser name, headers and session facts on activity records).
6. **HIST** last, just before the pull request.

### The machine

Containers started and removed by name with their data this session: `x3land-mysql80`, `x6worship-mysql80`,
`x7final-mysql8036`, `x7final-mysql8411`, `x6final-mysql8036`, `x6final-mysql8411`, `x6final2-*`, `x8build-mysql8036`,
`x7fix2-mysql80`, `x7fix2b-mysql80`. Left-behind volumes 0 before and 0 after, every time. No other project's
container or volume touched; no `prune` ever run.

**THE PUSH IS STILL BLOCKED** by this machine's permission rules. Everything above is committed locally and nothing
has reached GitHub.

## WHERE THINGS ARE, 26 OCTOBER 2026 — the session of 14 October was cut off mid-flight; what was recovered

**Read this before anything else: work stopped abruptly on 14 October 2026 at about 01:52.** Three builders had written
their reports but never committed, and their changes were sitting UNCOMMITTED in their checkouts for twelve days. Nothing
was lost. The first thing this session did was commit each one on its own branch, before touching anything else. What the
gap means in practice: no background agent from that session survives, so every "running" line in the 14 October table
below is stale — read this block instead.

**The branch is synced.** `origin/alpha` had six Dependabot updates to `github/codeql-action` (v4.37.9 to v4.39.1, in
`codeql.yml` and `php-static-analysis.yml`). **The owner chose a MERGE, not a rebase** (26 October, question tool): a
rebase would rewrite all 492 commits on this branch and every commit number quoted in this file, `CHANGELOG.md` and the
GitHub issues would stop matching, 20 worktree branches would be orphaned, and it would then need a forced push the owner
reserves. The merge is clean and `actions: read` survived it. **The one history rewrite this branch gets is still the HIST
task, once, just before the pull request.** Do not rebase this branch.

**THE PUSH IS BLOCKED, and nothing in this session has reached GitHub.** `git push origin claude/alpha-wip` was refused by
this machine's own permission rules, not by git or by GitHub. Every commit below is local only. The owner (or an
interactive session) must run the push, or allow it. **Say so in any report: this work is committed, not pushed.**

### THE OWNER'S ANSWERS, 26 OCTOBER 2026 (question tool; settled, do not ask again)

1. **Sync by merging `origin/alpha`, not rebasing** (above).
2. **The Worship, Present form-token fault is built INSIDE package X6.** X6's builder found it in the browser and left it
   alone as outside its brief. Every control on that page (Prev, Next, Blank, Black, Show, the keys) worked exactly ONCE
   and was then silently refused: the page takes one form token when drawn, the portal replaces the token each time it
   accepts one, and `web/_apps/worship/api/advance.php` never handed the replacement back, so every later press was
   answered 400 "csrf" with nothing shown to the operator. It was proved on the base commit with three plain requests
   (200, 400, 400), so neither the browser nor X6's change plays any part — it was simply hidden while the page's script
   was refused altogether. Built this session; see "X6" below.
3. **#626: when a person is erased, the visitor's address, browser name, headers and session facts on their activity
   records are blanked too — before roll-out.** Its own package, not yet started.
4. **#610 found 1: a text sent AT ONCE is asked whether the account or organisation is switched off — before roll-out.**
   Today only a HELD text is asked again when it comes due, and of the three callers only the prayer chain's own choice of
   a partner asks. The rule is already written and tested as `Sms::whyNotThisPerson()`, so the work is to ask it in
   `Sms::send()`. Its own package, not yet started.

### WHAT LANDED THIS SESSION

**X3 (#610, texts held for quiet hours) is COMMITTED on the working branch as `00fa9ad`** — three commits from
`ro-x3-build` joined (`261c3b9` the build, `c006f11` the fixes after its check, `8eb831e` the last round of words and
tests, which this session committed from the checkout), with the documentation lines from its `DOC-ENTRIES.md`
(`CHANGELOG.md`, `FEATURES.md`, `DEV_NOTES.md`, `docs/INSTALL.md`, `.claude/CLAUDE.md`, `.OpenAI/CONTEXT.md`). Two
independent checks and a fix round after each; Codex has not seen it.

**ONE FAULT FOUND WHILE LANDING IT, in the test, and worth remembering.** On the merged tree the test failed 2 checks of
103 where it had passed 106 of 106 in the builder's own checkout. Part K asks PHP's time-zone data for the next
clock-change night in each direction and had a **twelve-month window**. This session ran on 26 October 2026, the day after
Britain put its clocks back, so London's next "clocks go back" night is 31 October 2027 — five days outside the window.
The test found no "back" night and reported a fault in code that was correct. Proved from PHP's own data and kept:
over 366 days London has 0 back and 1 forward; over 732 days, 1 back and 2 forward. `clockChanges()` now takes the window
as an argument and part K asks for **two years**; `ordinarySaturday()` keeps the shorter window on purpose (it only looks
400 days ahead) and the comment says why. **This is the project's own time-zone trap in a new guise — a test that passes
in one month and not another — so look for it in any other test that searches a fixed window from "now".** Then on the
landed tree: held-texts 106 of 106 exit 0, mail-queue 155 of 155, all 30 audit checks exit 0, `php -l` clean. Evidence:
`.claude-work/resume/x3-landing-evidence/`.

### WHAT IS BUILT BUT NOT YET LANDED (each committed on its own branch, none pushed)

| Package | Branch and commit | State, and what it still needs |
| --- | --- | --- |
| **X7** (#623, the QR pictures) | `ro-x7-build`, `c8c6044` | **Built and committed this session from the checkout.** The fault is real and proved: `QrEncoder` wrote the fifteen format marks BACK TO FRONT, so no reader could read any picture the portal ever drew; with the one line corrected, a strict reader written for this work and macOS's own reader read every size. The two-step set-up page also built a `chart.googleapis.com` address that carried the person's authenticator key to another website, and the portal's own page rule then refused the image, so the page was broken and leaky at once; it now draws its own picture with `Qr::dataUri()` and `Totp::getQrUrl()` is removed. New test `tools/qr-selftest.php` (67 checks, or 76 with `--with-qrencode`), new test-only reader `tools/qr-test-reader.php`, 23 kept qrencode fixtures. 34 planted faults caught, 9 more in `two-step-selftest.php`. **STILL OWED: the final two-step self-test runs on MySQL 8.0.36 and 8.4.11 (the builder was cut off before them), then ONE independent check, then join.** |
| **X6** (#625, scripts the browser refused) | `ro-x6-build`, `808c794` plus the Worship fix | **Built and committed this session from the checkout (50 files).** 97 inline scripts and handlers in 63 files carried no nonce, so the browser refused them and the buttons did nothing. New check `check_inline_scripts_have_nonce.py` (self-test 52 of 52; 97 findings on the base tree, exit 0 on this one) with a 20-file exemption list explained line by line; new test `tools/page-scripts-selftest.php` (88 of 88 on this tree, 34 of 88 on the base, as it must be). Proved in a real browser against two throwaway portals side by side: 50 rows, none of the 47 feature rows works on the base, all work here. Two faults only a browser could find: the passkey dialog built before Bootstrap existed (fixed), and the Worship, Present token (the owner put it in this package; built this session). **STILL OWED: the final browser pass, the library-fallback proof, the other house tests, MySQL 8.4.11, then ONE independent check, then join.** |

### WHAT NEEDS PICKING UP, in order

1. **X7:** the two final self-test runs, then its one independent check, then join. Shares no file with X6, so the two may run side by side (owner, 12 October).
2. **X6:** finish its owed proofs (the Worship rows included), then its one independent check, then join.
3. **X4 (#622, the activity log's session facts):** built as `af719e3` on `ro-x4-build`; **its full independent check was cut off at 01:47 on 14 October, mid-way through the migration test, and never wrote a verdict.** Its working log (`rollout-X4--check.md`) is detailed and records real findings already: one-table restores from an OLDER backup do not hold; of 14 planted faults of its own the new test caught 9, and the five it missed are named; on the landing tree the new test fails one check (71 of 72) and `two-step-selftest.php` fails one (83 of 84), both because they read pages that have since changed. **Treat the check as unfinished: re-run its last part and get a verdict before any fix round.**
4. **WF (the one change to GitHub's workflow files):** the package is `177527b`, `3aaa875`, `9b39f7c` on `ro-wf-build`; what it changes is `git diff aab9add 9b39f7c`. **Its one check FINISHED and is NOT CLEAN: five real problems** — two in the owner's guide, one that zips are almost never built on `alpha` (every pull request into alpha is set to merge by itself and such a merge starts no run, so a version rise there will not build a zip), one old weakness kept, and one list that needs the owner's yes. **The checker's full report was in its final message to the coordinator and that message is LOST with the 14 October session.** Its working log and the whole evidence folder survive (`rollout-WF--check.md`, `rollout-WF--check-evidence/`, start at `00-WHAT-IS-IN-THIS-FOLDER.txt`); `70-the-guide-against-the-files.txt` holds the guide findings. **Recover the five problems from the evidence before fixing anything, and do not assume there were only five.**
5. **Two new packages from the owner's answers above:** the `Sms::send()` gate (#610 found 1) and the erasure of activity-record session facts (#626). Neither is started.
6. **HIST** stays last, just before the pull request, exactly as agreed on 13 October.

### THE MACHINE, left clean

Containers this session started and removed by name with their data: `x3land-mysql80`, `x6worship-mysql80`. Left-behind
volumes: 0 before, 0 after. No other project's container or volume was touched.

## Read this first — where we are right now

**OWNER, 13 OCTOBER 2026 — three answers and one instruction about how to ask.**
1. **`gitleaks` is installed** on this Mac (Homebrew, 8.30.1), with the owner's yes. The secrets search can now really run on a package; F6b's fix builder was told.
2. **#604 is in the roll-out set** (events' two time-zone columns). Queue it after F6b and M4: it touches the events API's detail answer, `Venues.php`, the feed importer and possibly a migration (217 is the next free number unless M4 took it; M4 did not).
3. **The public history IS to be rewritten to remove the owner's real name.** NOT YET DONE; do nothing destructive without the answers below. Facts (13 October): 805 commits on six remote branches and two tags; the name is in file contents in about 23 commits, in no commit message, and in the author or committer identity of 134 commits (a name, and an e-mail address holding the first name); the repository is public with no forks; two open pull requests (Dependabot, #601 and #602); `main`, `alpha` and `beta` refuse forced pushes (the owner must lift that for the push); a push to a release branch starts the deploy workflow (it must be switched off for the push, or it deploys); this Mac's settings refuse a forced push for the assistant, so the owner runs the push or lifts that rule. What a rewrite cannot do: reach copies somebody already made, or GitHub's own kept copies of old commits (that needs a request to GitHub Support). Every commit number written in the handoff, the changelog and issue comments stops matching afterwards. The owner was asked, with the question tool: WHEN; whether the 134 identities change too; who runs the push. **Their answers are recorded below this note when given.**
4. **Questions go through the question tool from now on**, not as lines under "Blocked on me" ("They are getting missed just as messages in that chat"). Memory note: `ask-decisions-with-the-question-tool`.
5. **THE OWNER'S ANSWERS ON THE HISTORY REWRITE, 13 October 2026 (given through the question tool; settled, do not ask again):**
   - **When:** ONCE, just before the pull request, when all queued work is committed. Not before.
   - **Attribution:** YES: every commit's author and committer become `Salem874` with GitHub's private no-reply address (`Salem874@users.noreply.github.com`), as well as the name coming out of file contents.
   - **The push:** the assistant PREPARES AND REHEARSES in a separate copy (a mirror clone outside the working folder), proves the name is gone from every commit, message, file and identity on every branch and tag, and that every file is otherwise byte for byte the same; then gives the owner the exact commands and a checklist. **The OWNER** unlocks `main`, `alpha` and `beta`, switches the deploy off, runs the forced push, and locks up again. **The assistant never force-pushes.** The checklist must also say: close or re-make the two Dependabot pull requests; every other copy of the repository must be downloaded afresh; ask GitHub Support to purge cached commits; commit numbers quoted in documents and issues stop matching.
6. **The old installer on the first customer's live address: CLOSED.** Asked what to do about it, the owner answered: "There is not current install/distributed package". Nothing is installed or distributed there now. Do not raise it again.

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

## WHERE THINGS ARE, 12 OCTOBER 2026 — moved to the archive

That day's progress table (packages H1 to H8, F1 to F9, M1 to M4 and the rest, each with its commit and what its
checks found) is history, and it is now in `.claude/history/HANDOFF-archive-to-2026-10-05.md` under the heading
"APPENDED 27 OCTOBER 2026". Moved on 27 October 2026 when this file passed 1,000 lines, which is what the owner asked
for on 5 October. **Nothing a decision depends on was moved:** every "OWNER DECISION" section is still below, in
place and unchanged.

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
