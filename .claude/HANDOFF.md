# Handoff — where the work is right now

*Overwritten, never appended. Current state only (#678). Issues are the record of
what is and is not done.*

**Branch:** `claude/alpha-wip`, clean. **11 commits unpushed**, and the branch is
**573 commits ahead of `origin/alpha`, 615 ahead of `origin/main`** — so every
fault those 573 commits fix is still live on the released branches. The owner
reserves pushes and merges; no pull request until the one comprehensive Codex
review of the whole branch.

## The queue is finished and the owner's release scope is built

All five items the owner chose on 29 October for the first usable release are in
the tree: repeating events (`6c6b854`), backups taken by themselves, downloaded
and brought back (`eefa603`, migration 228), transcription's trigger (#276,
#672), #641 in full, and the browser/visual half of #569 (`0186531`).

`tools/run-all-selftests.sh` is **66 of 66 green**. The one that refused on
31 October — `mail-queue`, whose part F3 lands in the hour New York's clock
repeats — re-ran clean after the overlap: 159 checks, all passed.

## What the 1 November audit of the whole open list established

198 issues were open; 97 were named in a commit subject. Four read-only
verifiers checked all 97 against the tree: **68 are finished, 16 are partly
done, 13 are genuinely open.** Nothing among them stops a fresh install or kills
an upgrade — the three that once did (#552, #555, #561) are fixed and verified.

**They are deliberately NOT closed.** Several carry a comment saying they close
when the branch merges, and the branch is unmerged, so closing them now would
claim something that has not happened.

## Waiting on the owner

1. **The merge, and the Codex review gate in front of it.** 573 commits of fixes
   are unmerged. The #514 part-8 precedent is to split a Codex review across
   several resets rather than wait for one allowance.
2. **#474 — branch protection.** Measured, not read: `main`, `beta` and `alpha`
   each have a protection object with **no required status checks, no required
   reviews, and admins not enforced**. A pull request into `main` can merge with
   every check red. The earlier claim that a deleted `Psalm` check blocks every
   pull request is FALSE; nothing is required at all.
3. **#678's three settings.** `~/.claude/settings.json` has no `permissions`
   block and no `env` block — checked, not assumed. So the force-push/hard-reset
   denials, the `docker prune` denials and
   `CLAUDE_CODE_MAX_CONCURRENT_SUBAGENTS=6` are all absent, **and
   `.claude/CLAUDE.md` still asserts in two places that they are in force.**
   Neither branch of #678's acceptance is met.
4. **#581 items 2, 3 and 4** — a snapshot with no single moment in time; no way
   to rebuild a destroyed database without a command line; losing `enc.key`
   locks out everyone with two-step sign-in (#582 is the nearest home).
5. **#475** — shipping onto MySQL 8.0, whose extended support ended April 2026.

## The real exposures left, none of them an install failure

- **#598 item 1** — a password reset does not end the person's other open
  sessions. Needs a prior compromise, so it is residue, not an open door.
- **#598 item 4** — after five failed sign-ins from one address,
  `forms/public-submit.php` and `prayer-requests/anonymous-save.php` answer
  "success" and **discard the submission**. Behind a shared address a visitor's
  prayer request is lost silently.
- **#511** — coordinator grants are still installation-wide
  (`admin/calendar/coordinators-save.php` has no `tblUserSites` join), and the
  legacy portal-wide `tblUsers.isAdmin` flag still counts in every organisation.
- **#549** — the time-zone sweep's own deliverable is missing, and `CURDATE()`
  appears 37 times in 21 files under `web/`, each answering in the database
  connection's zone rather than the organisation's.
- **#564** — the `-L` fix to `tools/watchdog.sh` approved on 25 September 2026 is
  still not applied, so a watchdog on a symlink fires immediately.

## Raised on 1 November, because nothing covered them

**#701** path mode's four remaining blockers (they lived only in prose),
**#702** mail parts M5/M6 split out of #592, **#703** the undecided question from
#574 about keeping the name of who made a pastoral visit. The visual audit's ten
undersized tap targets were recorded on **#225**, which is partly done.

**Codex has reviewed none of this branch.** Independent Claude checkers stood in
package by package.
