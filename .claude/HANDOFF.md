# Handoff — WebMS-Intra working branch

**Updated:** 2026-10-30. Kept current **as the work happens**, so it can be picked up at any point.
**Working branch:** `claude/alpha-wip` — the single work-in-progress branch.
**Target:** one pull request into `alpha` at the end. No stacked pull requests.

**How to read this file.** Everything above "Recent work" is **live**: current state, things
waiting on the owner, and standing decisions that still govern. "Recent work" is a short log.
Finished work is in the archive at the bottom — it is history, not instructions.

**Restructured on 30 October 2026** under the owner's decision 6 of 5 October ("move old entries
into an archive file beside it, marked as history — after part 8 is committed"; part 8 was
`6abed16`). The whole previous file is kept verbatim in
`.claude/history/HANDOFF-archive-to-2026-10-30.md`, whose header records what the sense-check
found wrong in it.

---

## Where we are right now

**In flight:** #673 (37 deprecated `curl_close()` calls removed, plus a tripwire). Built, fixed
after a NOT CLEAN independent round, and in a second narrow round. Not committed.

**Nothing is pushed, and it is 25 commits, not six.** `origin/claude/alpha-wip` was last updated
on **27 October 2026** (`0e15afd`). Everything since exists only on this disk: **25 commits over
three days**, `37a63de` → `a933307`, plus the sign-in hotfix on `hotfix/sign-in-1.4.2`. That
includes **four migrations (224, 225-226, 227, 228, 229)** and several harm-capable fixes — a
claim could be paid twice (#647/#651), a truncated download deleting events (#645), the
clock-change work (#652/#653), a unique key that refused nothing (#487), and the twelve checks
that ran nowhere (#646).

The owner decided on 30 October 2026, asked directly, that **nothing is pushed until the
comprehensive Codex review**. Do not offer to push again before it, and do not treat "it is only
the working branch" as a reason. **They were told "six commits" when the figure was 25** — the
correction has been given; the decision stands unless they change it.

**The queue, in order:**

| # | Task | Issue | State |
|---|---|---|---|
| 1 | Deprecated `curl_close()` + tripwire | #673 | In second review round |
| 2 | Events `status`: allow-lists do not match the column | #676 | Planned, buildable |
| 3 | Browser/visual audit half | #569 | Harness built and proven; needs wiring in |
| 4 | Dead API switches; `expenses/export` answers 403 | #675 | Decided 30 Oct, buildable |
| 5 | Organisation address written installation-wide | #677 | Decided 30 Oct, buildable |
| 6 | Limit settings: installation caps organisation, app-wide | #668 | The big sweep |
| 7 | Change a repeating series' pattern | #669 | Not started |
| 8 | A cancelled/moved single date is invisible to subscribers | #670 | Not started |
| 9 | Offsite backup needs a command line, so does nothing | #671 | Not started |
| 10 | Nothing drains the transcription queue | #672 | Not started |

**Migration numbers:** the highest in `web/_sql/` is **229**. #675 and #677 both need one, and
#676 may. Allocate **230 upward** and do not let two packages claim one number.

**#676 is wider than its issue says** (found while gathering facts, not yet built):
- **Three** allow-lists accept `archived`, not two — `web/_apps/calendar/manage/import.php:141`
  has it as well as the two API writers.
- The column is `ENUM('draft','published','cancelled','postponed')`, so `archived` is refused
  and `postponed` can never be set.
- `web/_apps/admin/calendar/moderate.php:8` says "Reject = status='archived'" but line 63 writes
  `"draft"`. **An untrue comment**, which counts as a real problem here.
- `api-spec.json` contradicts itself: one `status` enum lists three values, another four, and
  `archived` appears in four places.
- **Do NOT claim "archived was never storable".** That is true only from **#593** (12 October
  2026), when `DbServer::connect()` made every connection strict. Before that a non-strict
  install would have stored the **empty string** instead of erroring, leaving events with no
  status, invisible to any `status IN (...)` filter. Check
  `SELECT status, COUNT(*) FROM tblEvents GROUP BY status` on a real database first; a repair
  migration may be needed.

**Item 3's harness is built and proven** (drafted in the session scratchpad, not yet in `tools/`).
Edge headless driven over the DevTools protocol using **node's own built-in `WebSocket`** — no
Playwright, no Puppeteer, no `npm install`. On fixtures it found six real faults and raised no
false alarm on a clean page.

> **The trap in it, measured before the harness was written.** With
> `Emulation.setDeviceMetricsOverride` and `mobile: true`, **Chromium widens the layout viewport
> to fit the content**: a 900px-wide page asked to render at 390 reports `innerWidth` of 900. So
> `scrollWidth > window.innerWidth` is **permanently false** and a horizontal-overflow check
> written the obvious way can never fail. Compare against **the width the driver asked for**,
> which is known from outside the page. Planting the naive version proved it: it missed the 320px
> and 390px overflows entirely and caught only 768.

---

## Waiting on the owner

1. **Closing GitHub issues does not work from here.** `gh issue close` is refused by the
   permission classifier as an external write; comments post fine. **The consequence is bigger
   than it looks:** every issue CLAUDE.md describes as built and shipped is still OPEN — #604,
   #499, #500, #502 among them — so **the queue cannot be reconciled against GitHub at all**, and
   anyone reading the issue list sees finished work as outstanding. Needs either the owner closing
   them or a permission rule.
2. **#641 needs closing** specifically (its completion comment is posted).
3. **The public history rewrite** — settled in principle, not done. See the standing decision of
   13 October below. It happens ONCE, just before the pull request, when all queued work is
   committed.

**Open technical questions nobody has answered** (carried forward so they are not lost; all from
the erasure work):

- **`tblWorkflowSteps.assigneeValue`** — an approval step given to one named person. What should
  it become when that person is erased?
- **Six columns pinned in `KNOWN_STILL_NAMED`.** Five cannot currently be empty and would need a
  migration: `tblAssetLicenseAssignments.linkedByID`, `tblAssetLoans.requestedByID`,
  `tblOffboarding.offboardedByID`, `tblResourceBooking.bookedByID`,
  `tblRotaSwapRequest.requestedByID`.
- **`tblInvitation.acceptedByID`** — the row holds the invited person's own e-mail address.
  Delete an accepted invitation along with its person?

---

## Standing owner decisions

Newest first. These are **policy and are never archived.** The full original wording of anything
before 30 October is in `.claude/history/HANDOFF-archive-to-2026-10-30.md`.

### 30 October 2026 — four answers, given through the question tool

1. **#675 `expenses/export`: seed it, document it, AND add a test.** Not seed alone — it has never
   served a real request, so the package adds an HTTP test rather than publishing it blind.
2. **#675's four orphan switches: DELETE them with a numbered migration**, plus the matching
   change to `full_schema.sql` so the two agree. This is an approved deletion of data.
3. **#677: the organisation's postal address, coordinates and what3words become PER-ORGANISATION
   settings rows**, written with the organisation's `siteID`. Chosen over new `tblSites` columns.
   **The migration must copy the existing installation-wide values DOWN to each organisation**, or
   every running install loses the address it is displaying. `portal.support.email` is a separate
   question and is **not** covered.
4. **Nothing is pushed until the comprehensive Codex review.** The owner chose the strictest
   option over protecting the work off-machine.

### 13 October 2026 — the public history rewrite, and how to ask

- **The rewrite happens ONCE, just before the pull request**, when all queued work is committed.
  Not before.
- **Attribution:** every commit's author and committer become `Salem874` with
  `Salem874@users.noreply.github.com`, and the name comes out of file contents too.
- **Who runs it:** the assistant PREPARES AND REHEARSES in a mirror clone outside the working
  folder, proves the name is gone from every commit, message, file and identity on every branch
  and tag and that every file is otherwise byte for byte the same, then gives the owner the exact
  commands and a checklist. **The OWNER** unlocks `main`, `alpha` and `beta`, switches the deploy
  off, runs the forced push and locks up again. **The assistant never force-pushes.**
  The checklist must also say: close or re-make the two Dependabot pull requests; every other copy
  must be downloaded afresh; ask GitHub Support to purge cached commits; and commit numbers quoted
  in documents and issues stop matching.
- **Questions go through the question tool**, not as lines in a report — "they are getting missed
  just as messages in that chat".
- **`gitleaks` is installed** (Homebrew 8.30.1), with the owner's yes.
- **The old installer on the first customer's live address: CLOSED.** "There is not current
  install/distributed package". Do not raise it again.

### 12 October 2026 — pace, and not repeating ourselves

- **Packages that share no files run SIDE BY SIDE**, and work runs autonomously, with questions
  asked when a decision is genuinely needed. **This supersedes the 24 September "one package at a
  time, do not offer it again"** — which is still written as absolute in the archive, so a reader
  of that file needs this line.
  How it is run: each builder gets its exact file list; `CHANGELOG.md`, `DEV_NOTES.md` and
  `FEATURES.md` are never edited by two packages at once; **stage each package by ITS OWN file
  list, never from `git status`**; own named containers and ports.
- **Do not go over the same ground twice.** The owner was asked about the sign-in lockout rule
  twice and said so: "this just causes elongated time to work and wasts usage credits/tokens!"
  Search this file before asking anything. **The lockout rule is settled: no more than 5 wrong
  tries in any 15 minutes, as built.**

### 11 October 2026 — the roll-out set and the Codex policy

- **The roll-out set** (must-fix before roll-out): #510, #535, #545, #547, #568, #570, #563, #478,
  the rest of #534, #488 — plus #499, #500 and #502, and #514 parts 9-11. Later additions: the
  twelve account-linked tables not in the erasure list; #553 and #555 (fresh install or upgrade).
  **Everything else waits until after roll-out unless the owner pulls it forward.** The work on
  most of these is done; their issues are still open — see "Waiting on the owner" item 1.
- **Codex: the riskiest parts only, NOT a gate, and only once the queued items are done.**
  "Minimise multiple rounds of code reviews, wasting potential codex usage/credits/tokens." So:
  **one independent check per code package**, sized to the risk; small wording-and-test fixes
  after it are committed on the builder's proofs without another round; **Codex gets ONE lean,
  security-first pass over the riskiest areas at the end** (sign-in and permissions, erasure,
  expenses and payments, calendar import), split across its resets, blocking nothing. #514 part 8
  is one of its named riskiest parts.
- **Gift Aid declarations are KEPT on erasure**, with the reason recorded.

### 5 October 2026 — eleven answers (final; do not reopen)

Several are done and are kept here only so nobody re-asks:

- **Thinking effort `high`** (was `xhigh`) — done in `~/.claude/settings.json`.
- **Deny rules for force-push, hard reset and docker prune** — done and proved. They cannot catch
  `bash -c '…'` or `/usr/bin/git …`.
- **Helper agents: at most six at once**, enforced by `CLAUDE_CODE_MAX_CONCURRENT_SUBAGENTS=6`.
  The seventh is **refused, not queued**, and the setting is read only at session start.
- **What gets reviewed: narrowed exactly as machine-wide.** Only changes that could do real harm
  (code, database, data, money, credentials, personal details, safety gates). **Documentation of
  every kind — instruction files, memory, this handoff, notes — renames and comment-only changes
  do not.** The owner chose this knowing an instruction rewrite is then committed unreviewed.
- **Who checks:** a fresh agent checks each code package as **Codex's named stand-in**, and every
  commit message says plainly that Codex has not reviewed it.
- **Deleting a web-root folder that hides an address needs the owner's explicit approval first.**
- **#569's pre-push check:** one per repository with its own installer, **not** a global hooks
  folder. (Built: `.githooks/pre-push` + `tools/install-git-hooks.sh`.)
- **Handoff:** move old entries to an archive file beside it, marked as history, after part 8 is
  committed. (This is the approval the 30 October restructure acted on.)

### 25 September 2026 — watchdog shortcut fix APPROVED, and STILL NOT DONE

Add `-L` to both `stat` calls in `mtime()` so the script reads the real file's age, not the
shortcut's. Details and checks: **#564**.

**Two things found on 30 October 2026 while sense-checking this:**
- **The fix is still not applied.** `tools/watchdog.sh:63` is
  `t=$(stat -c %Y "$1" …) || t=$(stat -f %m "$1" …)` — no `-L`. It has been waiting five weeks
  because the session that recorded the approval was a cloud session that could not reach this Mac.
- **`~/.claude/bin/watchdog.sh` does not exist.** So the instruction to "apply it to both copies
  together" now has one copy, and `.claude/CLAUDE.md`'s claim that "the owner's Mac also has
  `~/.claude/bin/watchdog.sh`, and the two copies' logic is kept identical" is **false** and
  should be corrected.

Until the fix lands, point `quiet` at the real file rather than a shortcut.

### 24 September 2026 — the ones that still govern

- **API keys see an imported event exactly as a signed-out visitor would**, plus a per-calendar
  "don't show via API" box, unticked by default. This replaced the 17 September answer and the
  owner confirmed it after seeing both side by side.
- **Codex reviews the whole branch at the END OF THE WHOLE QUEUE**, and moves to the very end
  again if tasks are added. Run it with `-c model="gpt-6-astra"` and stdin closed. **A refusal
  naming `gpt-6-sol` is the model-name problem, not a lack of credit**; a credit refusal names a
  reset time.
- **Throwaway containers are removed WITH their data.** `docker run --rm` or `docker rm -v`, named
  after the work, and report `docker ps -a` and the count of left-behind volumes. Checking once
  found **415 left-behind volumes taking 101 GB** while every agent had reported clean-up as done.
- **Five calendar design questions are settled** and must not be re-asked: private events hidden
  until chosen; a changed event goes back for approval; later dates of a repeating event follow an
  approved one when nothing differs; an organisation with no site administrator of its own sends
  approval notices to every global administrator; at "title, date and time only" the category
  shows and the RSVP counts do not.

---

## Recent work

Newest first, briefly. `CHANGELOG.md` is the full record and `DEV_NOTES.md` holds the technical
lessons; neither is repeated here.

### 30 October 2026 — #673, deprecated `curl_close()`, and two wrong things I had said

Removed **37 `curl_close()` calls across 15 files**. The call has done nothing since PHP 8.0 and
PHP 8.5 deprecates it, so each wrote a `Deprecated` line every time it ran. Added
`tools/audit-checks/check_no_curl_close.py` with a 17-case `--selftest`, both hard gates in
`pr-security.yml` (now 30 steps). `php -l` clean on all 15; 42 audit checks pass; 337 checks pass
against a real `mysql:8.0.36` (QR 67, transcription 153, held texts 117). 0 dangling volumes.

**The independent round came back NOT CLEAN and both findings were real:**
1. **The tripwire was blind to the spelling most likely to bring the fault back.** It read only
   `T_STRING`; PHP tokenises `\curl_close` as **`T_NAME_FULLY_QUALIFIED`**, so it reported clean on
   a real, live deprecation. A leading backslash is ordinary style in namespaced code, and
   fourteen of the fifteen changed files are `namespace Portal\Core;`. Fixed to accept both, in
   any mix of capitals, and to leave `\Vendor\curl_close` alone as a different function.
   **A second round then found four more untrue statements and one more unpinned fold** — the
   `web/_core/` sweep above ("every file" is false: 70 of 190 declare no namespace), a
   php-cs-fixer claim that cannot be settled on a machine without php-cs-fixer and was DROPPED, a
   service list off by one, and "six comments mention `curl_close`" which this package itself
   turned into 21. All corrected; the selftest is now **21 cases**.
   **The other forbidden-call guards were checked and are NOT blind:**
   `check_session_id_not_stored.py` uses `\b`, which matches across the backslash, and
   `db-strict-mode-selftest.php` has an explicit `\\?` for `new \mysqli`. Mine was the outlier,
   and the hybrid shape that test uses — tokeniser to strip comments and strings, regex to match
   the name — is more robust than matching token *types*.
2. **Four documents overstated the blast radius**, saying every outside call wrote a line and
   listing **push** among them. `WebPush`, `SafeFetch`, `MailerGoogle` and `Auth` had already
   removed theirs. The DEV_NOTES section named WebPush as already-clean two paragraphs after
   saying a push wrote a line. This is the #487 shape.

**And a third fault of the same family, from the checker's strongest "Found":** the `--selftest`
exercised only `scan()`, never the verdict, so changing `len(hits) == 0` to `>= 0` left every case
green while the check passed on a tree holding a real call. `main()` now takes its root so the
selftest asserts its exit code. Fixing that surfaced a **fourth**: moving the `--selftest`
dispatch made `--selftest` quietly run the ordinary check and report success — the #646 failure in
miniature, inside the package that cites it.

### 30 October 2026 — #674/#668, a form that could push one organisation's setting to everyone

`$SETTINGS` is already merged by the time a page runs, so Admin → Transcription filled its boxes
from the merged array while its Save writes installation-wide: a global administrator in an
organisation holding its own value saw that value and **pressing Save without touching anything
made it the installation's.** Fixed; the page now names settings that differ. A new check,
`check_install_wide_prefill.py`, found the same shape on **fifteen** admin forms — fourteen are on
a pending list so it can gate from today. Commit `a933307`.

### 30 October 2026 — #641, the API description checked against the code

`api-spec.json` had never been compared with the handlers: 17 reachable endpoints were absent, 286
properties and 42 parameters had no description, and five replies described the inside of the
envelope as the whole reply. Two hard-gate checks plus `api-spec-check-selftest.py`, the test *of*
those checks (26 plants, each required to be caught by the expected check **and by nothing else**).
Commits `d6302ef`, `ed22873`, `1b48d5b`, `72f76e4`.

### Earlier

Transcription (#276, migration 229) `05e64d9`; backups (#569, migration 228) `eefa603`; repeating
events and the installation-caps-organisation rule (#668, migration 227) `6c6b854`; #514 part 8
`6abed16`. Full detail in `CHANGELOG.md`.

---

## Archive

| File | Holds |
|---|---|
| `.claude/history/HANDOFF-archive-to-2026-10-30.md` | The whole handoff as it stood on 30 October 2026, plus what the sense-check found wrong in it |
| `.claude/history/HANDOFF-archive-to-2026-10-29.md` | The database-shape audit (#487 task 6, `962269a`) back to 26 October 2026 |
| `.claude/history/HANDOFF-archive-to-2026-10-05.md` | Everything before 5 October 2026, and the 12 October progress table |

All three are **history, not instructions.** Every standing owner decision has been carried
forward into this file; if something in an archive reads as a live instruction and is not repeated
above, it is out of date.
