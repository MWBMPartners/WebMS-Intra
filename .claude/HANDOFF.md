# Handoff — WebMS-Intra

**Updated:** 2026-10-30. **Working branch:** `claude/alpha-wip`. **Target:** one pull request into
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

**Nothing is pushed.** `origin/claude/alpha-wip` was last updated on **27 October 2026** (`0e15afd`).
Everything since exists only on this disk — **31 commits**, plus the sign-in hotfix on
`hotfix/sign-in-1.4.2`. That includes **migrations 224 to 230** and several harm-capable fixes. The owner
decided on 30 October, asked directly, that **nothing is pushed until the comprehensive Codex review**; do
not offer again, and do not treat "it is only the working branch" as a reason.

**Nothing is in flight.** The tree is clean and the queue is free to start.

| # | Next up | Issue | Notes |
|---|---|---|---|
| 1 | One organisation's address written installation-wide | **#677** | Decided 30 Oct: per-organisation rows **and a migration copying installation values down**. `portal.support.email` is a separate question. Needs migration **231** |
| 2 | Limit settings: installation caps organisation, app-wide | **#668** | The big sweep; 30+ limit-shaped settings |
| 3 | #669, #670, #671, #672 | — | Not started |
| 4 | The login page links no icon, so `/favicon.ico` 404s | **#680** | Found by the #569 browser audit on its first run |
| 5 | Should a department approver export their own departments' claims? | **#681** | Split out of #675; needs the owner first |

**Migrations:** the highest in `web/_sql/` is **230**. Allocate **231 upward**; never let two packages
claim one number.

**Throwaway database:** container `p675-mysql` on port 33675 (root password `p675pw`) may still be up,
holding `webms` and `selftest_export` loaded from `full_schema.sql`. Remove it with
`docker rm -vf p675-mysql` and name the next one after its own work.

### What #675 taught, carried because it will happen again

Committed `d6f3e32`; the issue is closed with `status: done`. **All three of its headline claims were
false**, and measuring rather than reading is the only reason that was found. Four traps worth keeping:

* **A grep for the wrong string reads exactly like nothing-to-fix.** The issue said nothing linked the
  export, on the strength of `grep -rn "expenses/export"`. The address is `expenses/api/export`.
* **A file in an `api` folder may be served by the ORDINARY router**, through `tblRoutes`, with neither
  the `api.*.enabled` row nor the app's API-folder switch in front of it — two fewer gates than the
  folder implies. There is exactly one, and check I of `check_api_spec_matches_code.py` now names it.
* **My own fix was then wrong in the SILENT direction, and the independent check caught it.** A `;`
  inside a `--` comment ended a DELETE span early, so a deleted key read as a seed and check G passed on
  an endpoint answering 403 to everybody — while the docstring called that case harmless. Read
  `read_install_script()` before touching anything that parses `full_schema.sql`.
* **A harness helper can hide the fault you are testing for.** The "approval still works after the
  download" check used `post()`, which fetches a *fresh* form token, so it passed on the broken code.

---

## Waiting on the owner

1. **Apply the proposed `~/.claude/settings.json`** — prepared in this session's scratchpad with a
   README. It restores **three guards recorded as "DONE and PROVED" on 5 October 2026 that do not
   exist**: the deny rules for force-push and hard reset, the deny rules for `docker volume prune`
   and `docker system prune`, and `CLAUDE_CODE_MAX_CONCURRENT_SUBAGENTS=6`. There is no
   `permissions` block and no `env` block in that file at all. It also pre-approves
   `gh issue close`. **I cannot write it — refused as self-modification, which is correct.** Needs a
   restart to take effect. Tracked as **#678**.
2. **#564: a two-character fix, approved 25 September, never applied.** `tools/watchdog.sh:63` has
   no `-L`, so a watchdog pointed at a symbolic link fires immediately. It waited five weeks because
   the session that recorded the approval was a cloud session that could not reach this Mac. Also
   **`~/.claude/bin/watchdog.sh` does not exist**, so "keep both copies identical" has one copy.
3. **Close the rest of the finished issues.** 17 were closed on 30 October. **Not** closed, because
   I could not verify every part had landed: the roll-out set (#510, #535, #545, #547, #568, #570,
   #563, #478, #534, #488) and #499 / #500 / #502. They need a check, not a guess.
4. **The public history rewrite** — settled in principle, not done. See the 13 October decision
   below. It happens ONCE, just before the pull request.

**The erasure questions are now resolved or raised.** Checked against the code on 30 October rather
than trusting the prose that carried them: **two of the three were already built on 12 October
2026** — the five columns the database would not let be empty were made emptiable, and
`tblInvitation.acceptedByID` is deleted with its person. The live record is `KNOWN_STILL_NAMED` in
`tools/gdpr-eraser-selftest.php`, which lists just ONE such column (`tblExpenseClaims.userID`).
Only `tblWorkflowSteps.assigneeValue` was genuinely open, and it is now **#679**.

---

## Standing owner decisions

Newest first. **Policy, never archived.** Full original wording for anything before 30 October is in
`.claude/history/HANDOFF-archive-to-2026-10-30.md`.

**30 October 2026 — records, and four queue answers.** One job per document and nothing not-done in
prose only (#678; written into `.claude/CLAUDE.md`). #675: seed `expenses/export`, document it **and
add a test**; delete the four orphan switches by migration. #677: per-organisation settings rows,
with a migration copying installation values down; `portal.support.email` is a separate question.
**Nothing is pushed until the Codex review.** Closing an issue is two actions — `gh issue close`
**and** the `status: done` label.

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
`git status`**; own named containers and ports. And **do not go over the same ground twice** — "this
just causes elongated time to work and wasts usage credits/tokens!" Search this file before asking.
**The sign-in lockout rule is settled: no more than 5 wrong tries in any 15 minutes, as built.**

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
