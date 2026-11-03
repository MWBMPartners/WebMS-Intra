# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 3 November 2026.*

## Branch

`claude/alpha-wip`. The last pushed commit is **`a112ac0`** (#714). One commit is
about to land on top of it: #710 + #715 + #719.

**The owner gave standing approval on 2 November 2026 to push this working branch
after each commit** — never a release or protected branch, never a force push, and
no pull request until the Codex review is done. Everything else (pull requests,
merges, tags, releases) still needs their explicit say-so.

## In flight right now

**#710 + #715 + #719 — one commit, fully verified, ready to land.** They share
`GdprEraser.php`, `personal-data-catalogue.php`, the two GDPR self-tests,
`CHANGELOG.md` and `DEV_NOTES.md`, so they are not worth slicing.

- **#715** empties a person's own words: a prayer request's `subject`, `body` and
  `partnerNote`, and `tblCareCase.summary`. **Migration 240** makes three columns
  nullable, because a `NOT NULL` column cannot be emptied and the failure is quiet.
- **#710** adds "Dealt with by hand" — the one other way to reach `closeRequest()`.
- **#719** checks the hard-coded reserved-word list against a real server.

Independent check done by a fresh agent (Codex's named stand-in): **ten real
problems, all fixed**, four plants proving the new guards, every file restored
byte-identically.

## Verification over this package, after the fixes

| Check | Result |
|---|---|
| e2e migration harness, MySQL 8.0.36 | 7 phases, 42/42, exit 0 |
| e2e migration harness, MariaDB 11.4.13 | 7 phases, 42/42, exit 0 |
| `tools/run-all-selftests.sh` | **70 tests, 0 not green** |
| 50 audit scripts | all pass |
| `php -l` | clean on every changed file |
| Docker | no package containers left; 1 dangling volume before and after (pre-existing, 30 October) |

Evidence logs: `.claude-work/evidence/p715/`.

## Next, in this order

The owner reordered this on 3 November. **#723 and #724 come before block A.**

1. **#723** — `tblResourceBooking.bookedByID` moves from `ACTOR_COLUMNS` to
   `LINK_COLUMNS`, so the booking's `purpose` and `declineReason` are emptied.
   `approvedByID` stays an actor column. Same files as #715, so it starts only
   after that commit lands.
2. **#724** — the case-by-case erasure review role. Scope it against what is left
   once #723 lands, not against the older list.
3. Block A: #582, #586, #583 · B: #685, #491, #492, #481 · C: #620, #596, #588 ·
   D: #649, #541 · E: #703 (now decided), #539, #619 · F: #476, #146, #637 ·
   G: #474, #678.
4. Then the comprehensive Codex review of the whole branch, then the merge.

## Decisions in force that a reader needs

- **#703 is decided (3 November): KEEP** the name of who made a pastoral visit or
  recorded a safeguarding check. It is part of the record, not the worker's own
  personal data. What the visit says ABOUT the person cared for is still emptied.
- **Full check depth stays on every package** (the owner, 3 November, asked
  directly whether to narrow it). Build → independent check by a fresh agent →
  fix → re-plant → commit, however many rounds that takes.
- **Codex has reviewed none of this.** The whole-branch review happens at the end
  of the queue, and every commit message says so.

## Open, unscheduled, each with an issue

#720 (base64 document short write) · #721 (stray `.part-*` files the eraser cannot
match) · #722 (a per-organisation page for the transcript period).
