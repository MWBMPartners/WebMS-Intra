# Handoff — WebMS-Intra

**Updated:** 2026-10-31. **Working branch:** `claude/alpha-wip`. **Target:** one pull request into
`alpha` at the end; no stacked pull requests.

> **This file is OVERWRITTEN, never appended** (owner, 30 October 2026, #678). It holds where the
> work is **right now** and nothing else. It is not a work log and carries no history.
>
> | Looking for | Go to |
> |---|---|
> | What is and is not done, and every deferral or open decision | **the GitHub issues** |
> | What shipped | `CHANGELOG.md` |
> | Technical lessons | `DEV_NOTES.md` |
> | Finished and superseded plans | `.claude/history/plans/` |
> | Earlier versions of this file | `.claude/history/HANDOFF-archive-to-2026-10-30.md` and two older archives |
>
> **Anything not-done gets a GitHub issue in the same action that decides it.** If it is not worth
> an issue it is not worth recording here — nothing checks prose. **Never cite a document by line
> number;** cite issue numbers and commit SHAs.

---

## Where we are right now

**The queue as it stood is EMPTY.** #671, #672 and #669 are all closed. Pick up from "What to do
next" below, which needs no decision, or from the owner's answers to the questions in "Waiting on
the owner".

**Nothing is pushed.** `origin/claude/alpha-wip` was last updated on **27 October 2026** (`0e15afd`).
Everything since exists only on this disk, plus the sign-in hotfix on `hotfix/sign-in-1.4.2`,
including **migrations 224 to 232**. The owner decided on 30 October, asked directly, that **nothing
is pushed until the comprehensive Codex review**; do not offer again, and "it is only the working
branch" is not a reason.

### Closed this run

| Issue | What | Commit |
|---|---|---|
| **#671** | The off-site backup's six settings really decide what a run does | `ec22773` |
| **#672** | Unattended transcription — **decided NOT wanted**, closed as not planned | (no code) |
| **#669** | Change the pattern of a repeating series that already exists | `ffdbc08` |

### Raised this run and NOT built

| Issue | What it needs |
|---|---|
| **#688** | Do `s3` and `sftp` get address boxes on the off-site backup page, or come off it? |
| **#689** | `access-pages-selftest.php` counts through the server-wide `mysql.general_log`, so it must run ALONE |
| **#690** | **Two self-tests fail at HEAD** — `gdpr-eraser-selftest.php` and `backup-restore-selftest.php`. Verified in a clean worktree, so NOT caused by #669. They run on any pull request touching `web/_sql/**`, so the next PR will be red |
| **#691** | No way to restore a date a pattern change hid. Three sentences promising otherwise are corrected |
| **#692** | Should a date the pattern adds be published or drafted? A product judgement |
| **#693** | The in-transaction attachment re-check is guarded by no test (single-worker harness cannot reach it) |

**Migrations:** the highest in `web/_sql/` is **232**. Allocate **233 upward**.

**No throwaway database is left up.** Dangling volumes: **1 before this run, 1 after** — none added.
Name the next one after its own work and remove it with `docker rm -vf` (the `-v` matters).

### What to do next, needing no decision

1. **#690 first.** Two self-tests are broken at HEAD and nothing has run them since 27 October. Every
   later package inherits a red baseline until this is fixed, and the cause is probably something one
   of migrations 224–232 changed.
2. Then the wider open list (#660–#667 and below), and the verification work in item 5 under
   "Waiting on the owner".

### The lessons from this run, worst first

1. **Never write "(checked)" about something you did not check.** The audit script guarding #669's
   safety list said "Nothing in this schema does that today (checked)". One query disproved it. The
   word makes the next reader stop looking.
2. **A safety list keyed on the tidier half of a schema looks complete and is not.** The list was
   built from FOREIGN KEY declarations; `tblEventRSVPs` (every member's RSVP) and `tblZoomMeeting`
   name an event with no key at all, so the two most sensitive tables were missing. **And the check
   verified the constant against the same SQL it parsed itself, so both agreed and both were wrong.
   Verify against a DIFFERENT source** — loading the schema and asking `information_schema` showed 34
   where the code said 32, immediately.
3. **A guard never exercised ALONE is not covered**, however many checks pass around it. Removing the
   "did they press confirm" requirement failed nothing, because the only request carrying a plan
   fingerprint was one that also confirmed. For each guard, ask what request exercises it with the
   other guards satisfied.
4. **Six checks in one package were vacuous, and planting found every one; reading found none.** Two
   were satisfied by a row the change never touched; one pinned a value on a change that never
   altered it; one removed the wrong dates (created by a different writer, so their addresses could
   not collide); one could not fail while every fixture shared the same time zone; one asked about a
   date an earlier check had already removed. **A check satisfied by state the change never touched
   is not a check.**
5. **A comparative claim reasoned out instead of measured was wrong in both directions.** "`'no
   rule'` is the most dangerous ON DELETE value" — measured, a SOFT delete fires no ON DELETE rule at
   all, so all three behave identically for what that page does. I had given the soft-delete
   consequence as the reason for a claim that only holds under hard deletion.
6. **When you copy a row, list the columns that describe one instance's own history and clear them.**
   A dynamic column list taken from the database carried a cancellation, a moderation trail, a
   private individual's name and e-mail, and a registration window that had already closed.
7. **Restore the file the plant actually edited.** A plant runner that backs up the file named in its
   argument, while the plant script edits another, reports "did not apply" and leaves the real edit in
   the tree.

---

## Waiting on the owner

1. **Apply the proposed `~/.claude/settings.json`** — prepared in a session scratchpad with a README.
   It restores **three guards recorded as "DONE and PROVED" on 5 October 2026 that do not exist**:
   the deny rules for force-push and hard reset, the deny rules for `docker volume prune` and
   `docker system prune`, and `CLAUDE_CODE_MAX_CONCURRENT_SUBAGENTS=6`. That file has no
   `permissions` block and no `env` block at all. **I cannot write it — refused as
   self-modification, which is correct.** Needs a restart. Tracked as **#678**.
2. **#564: a two-character fix, approved 25 September, never applied.** `tools/watchdog.sh` has no
   `-L`, so a watchdog pointed at a symbolic link fires immediately. Also
   **`~/.claude/bin/watchdog.sh` does not exist**, so "keep both copies identical" has one copy.
   Noted this run: on a path under `/private/tmp/claude-501/...` the `quiet` mode fired spuriously
   because `stat` reported a stale size and mtime for a file that was really being written.
3. **Decisions raised and still open**, each with its own issue and the options written out:
   **#688**, **#692** (publish or draft a date the pattern adds — my recommendation is draft, with a
   line in the preview saying so), **#687** (may a hosting provider bound how long an organisation
   keeps donor names and addresses — the one case where capping is the DANGEROUS direction),
   **#685** (organisations already over the attendance retention limit), **#681** (may a department
   approver export their own departments' claims). **#679** is still open from before.
4. **One thing #671 does NOT fix, and only you can decide it.** The off-site backup still needs a
   command line and `rclone`, so **on plain shared hosting it can never send anything** — the page
   now says so plainly instead of implying otherwise. Making it work with no shell at all (uploading
   over HTTPS from PHP) is option 3 of #671 and was not built: it is a feature needing a decision on
   which storage providers to support. No issue raised, because the scope is yours to set.
5. **Close the rest of the finished issues.** 17 were closed on 30 October, and #669/#671/#672 this
   run. **Not** closed, because I could not verify every part had landed: the roll-out set (#510,
   #535, #545, #547, #568, #570, #563, #478, #534, #488) and #499 / #500 / #502. They need a check,
   not a guess.
6. **The public history rewrite** — settled in principle, not done. See the 13 October decision
   below. It happens ONCE, just before the pull request.

---

## Standing owner decisions

Newest first. **Policy, never archived.** Full original wording for anything before 30 October is in
`.claude/history/HANDOFF-archive-to-2026-10-30.md`.

**31 October 2026 — three answers on #669, and one on #672.** A pattern change touches **future dates
only, and a date with anything attached is never moved or removed** (chosen over a tick-box per
attached date: the worst case under this rule is a date needing a manual fix, the worst case under
the other is a lost attendance register). **A hand-edited date is identified by storing the moment
the pattern produced it**, so it is worked out rather than remembered. **Nothing is written without a
preview.** And **transcription will never send recordings unattended** — it stays two deliberate
presses, because one API key pays for every organisation and shared hosting kills a long request
part-way.

**30 October 2026 (later) — seven answers, do not re-ask.** **#671:** the off-site backup's six
settings are **made real** — not merely labelled with a notice, and not deleted. **#675:** leave
`api.expenses.export.enabled` unseeded and document the working page route; fix the Export button's
visibility so it is offered only to people the download serves. **#677:** the nine `org.*` keys and
`portal.support.email` become per-organisation with a migration copying the installation's values
down, the support address falling back to the installation's; an ordinary administrator may save the
address card while `site.url` stays global-administrator-only. **#670:** the calendar feed's
FALLBACK is the answer — a series whose dates no longer match its pattern is published one date at a
time, and `EXDATE`/`RECURRENCE-ID` overrides are NOT wanted. Three more are written up as issues
rather than decided: #681, #685, #687.

**30 October 2026 — records, and four queue answers.** One job per document and nothing not-done in
prose only (#678; written into `.claude/CLAUDE.md`). **Nothing is pushed until the Codex review.**
Closing an issue is two actions — `gh issue close` **and** the `status: done` label.

**13 October 2026 — the public history rewrite.** Happens **once**, just before the pull request,
when all queued work is committed. Every commit's author and committer become `Salem874` with
`Salem874@users.noreply.github.com`, and the name comes out of file contents. The assistant
**prepares and rehearses in a mirror clone**, proves the name is gone from every commit, message,
file and identity on every branch and tag, then hands over exact commands and a checklist. **The
OWNER** unlocks `main`, `alpha` and `beta`, switches the deploy off, force-pushes and locks up
again. **The assistant never force-pushes.** The checklist must also cover: the two Dependabot pull
requests, every other copy being re-downloaded, asking GitHub Support to purge cached commits, and
commit numbers in documents ceasing to match. Also: **questions go through the question tool**, not
report prose — "they are getting missed just as messages in that chat". `gitleaks` is installed
(8.30.1). The old installer on the first customer's address is **closed**: do not raise it again.

**12 October 2026 — pace.** **Packages that share no files run side by side**, and work runs
autonomously with questions asked when a decision is genuinely needed. **This supersedes the 24
September "one package at a time, do not offer it again"**, which the archive still states as
absolute. Each builder gets its exact file list; `CHANGELOG.md`, `DEV_NOTES.md` and `FEATURES.md`
are never edited by two packages at once; **stage each package by its own file list, never from
`git status`**; own named containers and ports — **and note #689: own named DATABASES is not enough,
because one test counts through a server-wide table.** And **do not go over the same ground twice** —
"this just causes elongated time to work and wasts usage credits/tokens!" Search this file before
asking. **The sign-in lockout rule is settled: no more than 5 wrong tries in any 15 minutes, as
built.**

**11 October 2026 — the roll-out set and the Codex policy.** Must-fix before roll-out: #510, #535,
#545, #547, #568, #570, #563, #478, the rest of #534, #488, plus #499, #500, #502 and #514 parts
9-11; later additions were the twelve account-linked tables and #553/#555. Everything else waits
unless pulled forward. **Codex: the riskiest parts only, NOT a gate, and only once the queue is
done** — "minimise multiple rounds of code reviews, wasting potential codex usage/credits/tokens".
So **one independent check per code package**, sized to the risk; small wording-and-test fixes after
it are committed on the builder's proofs **without another round**; Codex gets **one lean,
security-first pass at the end** (sign-in and permissions, erasure, expenses and payments, calendar
import), split across its resets, blocking nothing. **Gift Aid declarations are KEPT on erasure.**

**5 October 2026 — eleven answers (final; do not reopen).** Thinking effort `high`. **At most six
helper agents at once** — the seventh is refused, not queued. **What gets reviewed is narrowed
exactly as machine-wide:** only changes that could do real harm; **documentation of every kind —
instruction files, memory, this handoff, notes — renames and comment-only changes do not get a
review round.** A fresh agent checks each code package as **Codex's named stand-in**, and every
commit message says plainly that Codex has not reviewed it. **Deleting a web-root folder that hides
an address needs explicit approval first.** #569's pre-push check is one per repository with its own
installer, not a global hooks folder. Old handoff entries move to an archive, marked as history.

**25 September 2026 — #564 approved** (see "Waiting on the owner", item 2).

**24 September 2026 — still governing.** API keys see an imported event exactly as a signed-out
visitor would, plus a per-calendar "don't show via API" box, unticked by default. **Codex reviews
the whole branch at the END OF THE QUEUE**, moving to the end again if tasks are added; run it with
`-c model="gpt-6-astra"` and stdin closed — **a refusal naming `gpt-6-sol` is the model-name
problem, not a lack of credit**; a credit refusal names a reset time. **Throwaway containers are
removed WITH their data** (`docker run --rm` or `docker rm -v`, named after the work, and report
`docker ps -a` and the dangling-volume count); checking once found **415 left-behind volumes taking
101 GB** while every agent had reported clean-up as done. **Five calendar design questions are
settled and must not be re-asked:** private events hidden until chosen; a changed event goes back
for approval; later dates of a repeating event follow an approved one when nothing differs; an
organisation with no site administrator of its own sends approval notices to every global
administrator; at "title, date and time only" the category shows and the RSVP counts do not.
