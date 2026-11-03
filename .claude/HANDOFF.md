# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 3 November 2026.*

## Branch

`claude/alpha-wip` at **`1b78e98`**, pushed (#710 + #715 + #719, all three closed).
One package is uncommitted on top of it: **#723 + #721 + #720**.

**The owner gave standing approval on 2 November 2026 to push this working branch
after each commit** — never a release or protected branch, never a force push, and
no pull request until the Codex review is done. Everything else (pull requests,
merges, tags, releases) still needs their explicit say-so.

## In flight: #723 + #721 + #720, one commit

They share `GdprEraser.php` and the shared documents, so they are not worth slicing.
**Independent check running now** (a fresh agent, Codex's named stand-in); its report
lands at `.claude-work/resume/p723-independent-check.md`.

- **#723** — `tblResourceBooking.bookedByID` has LEFT `ACTOR_COLUMNS`, so the
  booker's `purpose` and `declineReason` are emptied on erasure. `approvedByID`
  STAYS an actor column. No migration: both columns were already nullable.
- **#721** — the eraser's statement glob is widened to `*.pdf*`, and
  `Pdf::sweepPartFiles()` removes strays older than an hour from the nightly job.
  `Pdf::PART_MARK` is the ONE place the mark is named.
- **#720** — the documents API's base64 branch compares the written byte count,
  removes a part-written file and refuses before the INSERT. Built by a separate
  agent in parallel (different files). Two new hard gates in `pr-security.yml`.

## Verification so far

| Check | Result |
|---|---|
| e2e harness, MySQL 8.0.36 | 7 phases, 42/42, exit 0 |
| e2e harness, MariaDB 11.4.13 | 7 phases, 42/42, exit 0 |
| `gdpr-eraser-selftest.php` | 266/266 |
| `gdpr-coverage-selftest.php` | 93/93 |
| `pdf-write-selftest.php` | 31/31 (sections H and I are new) |
| `document-write-check-selftest.py` | 15 plants, all as expected |
| 51 audit scripts | all pass |
| `php -l` | clean on every changed file |

**STILL OWED: the whole suite** (`tools/run-all-selftests.sh`). Deliberately held
until the independent check finishes, so its plants cannot collide with a suite run.

Evidence: `.claude-work/evidence/p723/` and `.claude-work/evidence/p720/`.

My own container `p723-mysql` is on port 33399 (database `selftest_p723`) and must
be removed before the commit: `docker rm -f -v p723-mysql`.

## Next, in this order

The owner reordered this on 3 November.

1. Finish #723/#721/#720: act on the check, run the whole suite, commit, push,
   close #723, #721, #720 with comments and `status: done`.
2. **#724** — the case-by-case erasure review role. Scope it against what is left
   now #723 has landed, not against the older list.
3. Block A: #582, #586, #583 · B: #685 **+ #722** (same `SettingLimit` shape, one
   package), #491, #492, #481 · C: #620, #596, #588 · D: #649, #541 ·
   E: #703 (now decided), #539, #619 · F: #476, #146, #637 · G: #474, #678.
4. Then the comprehensive Codex review of the whole branch, then the merge.

## Decisions in force that a reader needs

- **#703 (3 November): KEEP** the name of who made a pastoral visit or recorded a
  safeguarding check. It is part of the record, not the worker's own personal data.
- **#723 (3 November): the booker IS the subject** — reclassify, do not weaken the
  guard. **#724 next, before block A.**
- **#721 (3 November): BOTH fixes, glob first**, and **one hour** for the sweep.
- **Full check depth stays on every package** (the owner, asked directly whether to
  narrow it). Build → independent check by a fresh agent → fix → re-plant → commit,
  however many rounds that takes.
- **Codex has reviewed none of this.** The whole-branch review happens at the end of
  the queue, and every commit message says so.

## Open, unscheduled, each with an issue

#722 (per-organisation transcript period — folded into block B) · #725 (nothing
exercises the documents API over HTTP at all) · #726 (can `move_uploaded_file()`
report success after a partial write? not established either way).
