# Decisions — one file each

**Issues: [#751](https://github.com/MWBMPartners/WebMS-Intra/issues/751)** (why
this exists and how it is maintained) and
**[#752](https://github.com/MWBMPartners/WebMS-Intra/issues/752)** (the
decisions that are not about a feature).

Every decision is reachable **both ways**: each record names the issue it
governs, and each open issue a decision governs carries a comment naming the
decision. This folder is the record that travels with the code; the issues are
where it is discussed.

**This file is APPEND-ONLY, and it is SEARCHED BEFORE ANY QUESTION IS PUT TO THE
OWNER.** Set by the owner on 4 November 2026: *"we need to be firm about
recording decisions and checking those records on this device"*. The rule itself
lives in `~/.claude/CLAUDE.md`, so it binds every project on this machine, not
only this one.

## How to use it

**Before asking the owner anything, search this file.** If a row covers the
question, follow it and say so in your reply. Do not ask again.

**A re-ask is legitimate only when a MEASURED fact makes the recorded answer
unworkable.** Then the question must quote the row, state the fact, and ask
whether the decision still stands. "I think there might be a problem" is not a
measured fact.

**Never edit a row.** A decision taken again is a NEW row that names the one it
supersedes, so that "we decided this twice" is visible rather than hidden. The
superseded row stays exactly as it was.

**What every row carries:** the date, the question in plain words, the answer,
what the answer rules OUT, where it is implemented, and what it supersedes.

## Why this exists, with the instance that set it

The same decision — whether to require tests before a change reaches the `alpha`
branch — was put to the owner **three times on 3-4 November 2026** and answered
three different ways (D-016, D-019, D-024 below). The second ask was defensible:
a measured fact had unseated the first answer. The third was the owner's own
prompt. But three answers now exist for one question.

**The structural cause is what this register fixes.** Decisions *were* being
recorded — in `.claude/CLAUDE.md`, in four memory files, in `.claude/HANDOFF.md`
and in issue comments. Four places, none complete, and nothing to search.
Scattered records read as no record.

**This register is seeded, not complete.** It holds the decisions recoverable
from those four places on 4 November 2026. Anything older that turns up should be
appended with its real date and a note that it was added later.

---

## The register

| No. | Date | Decision | Status |
|---|---|---|---|
| [D-001](001-plain-english-everywhere.md) | 2026-09-07 | Plain English everywhere | in force |
| [D-002](002-every-change-is-reviewed-by-a-different-system.md) | 2026-09-10 | Every change is reviewed by a different system | in force |
| [D-003](003-every-database-change-goes-in-both-places.md) | 2026-09-11 | Every database change goes in both places | in force |
| [D-004](004-no-web-address-is-ever-built-in.md) | 2026-09-13 | No web address is ever built in | in force |
| [D-005](005-commit-and-push-after-an-independent-claude-check.md) | 2026-09-16 | Commit and push after an independent Claude check | in force |
| [D-006](006-times-respect-the-zone-and-both-clock-changes.md) | 2026-09-23 | Times respect the zone, and both clock changes | in force |
| [D-007](007-one-comprehensive-codex-review-at-the-end-of-the-queue.md) | 2026-09-24 | One comprehensive Codex review at the end of the queue | in force |
| [D-008](008-throwaway-containers-and-volumes-are-removed.md) | 2026-09-24 | Throwaway containers and volumes are removed | superseded |
| [D-009](009-never-wait-for-a-nudge.md) | 2026-09-24 | Never wait for a nudge | in force |
| [D-010](010-nothing-may-look-unfinished-or-ai-made.md) | 2026-10-04 | Nothing may look unfinished or AI-made | in force |
| [D-011](011-what-gets-a-review-round-narrowed.md) | 2026-10-05 | What gets a review round, narrowed | in force |
| [D-012](012-packages-that-share-no-files-may-run-side-by-side.md) | 2026-10-12 | Packages that share no files may run side by side | in force |
| [D-013](013-for-a-limit-the-installation-s-value-wins.md) | 2026-10-29 | For a LIMIT, the installation's value wins | in force |
| [D-014](014-one-job-per-document.md) | 2026-10-30 | One job per document | in force |
| [D-015](015-full-check-depth-on-every-package.md) | 2026-10-31 | Full check depth on every package | in force |
| [D-016](016-several-privacy-decisions.md) | 2026-11-03 | Several privacy decisions | in force |
| [D-017](017-gating-a-feature-is-a-capability-never-a-hard-coded-role.md) | 2026-11-03 | Gating a feature is a capability, never a hard-coded role | in force |
| [D-018](018-an-administrator-may-not-add-a-password-to-an-sso-only-accou.md) | 2026-11-03 | An administrator may not add a password to an SSO-only account | in force |
| [D-019](019-mysql-8-0-and-mariadb-10-6-stay-accepted-with-a-warning.md) | 2026-11-03 | MySQL 8.0 and MariaDB 10.6 stay accepted, with a warning | in force |
| [D-020](020-guarding-alpha-fix-the-two-jobs-first.md) | 2026-11-03 | Guarding `alpha`: fix the two jobs first | superseded |
| [D-021](021-guarding-alpha-leave-it-for-now.md) | 2026-11-03 | Guarding `alpha`: leave it for now | superseded |
| [D-022](022-administrators-no-longer-bypass-the-live-branch-s-rules.md) | 2026-11-03 | Administrators no longer bypass the live branch's rules | in force |
| [D-023](023-stop-a-container-never-force-remove-it.md) | 2026-11-03 | Stop a container, never force-remove it | in force |
| [D-024](024-the-version-number-moves-to-release-time.md) | 2026-11-04 | The version number moves to release time | in force |
| [D-025](025-the-help-page-guards.md) | 2026-11-04 | The help-page guards | in force |
| [D-026](026-the-sso-fallback-password-shape-and-order.md) | 2026-11-04 | The SSO fallback password: shape and order | in force |
| [D-027](027-decisions-are-recorded-here-and-searched-before-asking.md) | 2026-11-04 | Decisions are recorded here and searched before asking | in force |
| [D-028](028-quiet-hours-never-hold-a-critical-alert.md) | 2026-10-13 | Quiet hours never hold a critical alert | in force |
| [D-029](029-rows-a-restore-could-not-put-back-wait-with-no-timer.md) | 2026-10-31 | Rows a restore could not put back wait with no timer | in force |
| [D-030](030-free-text-columns-are-an-inventory-not-a-gate.md) | 2026-11-02 | Free-text columns are an inventory, not a gate | in force |
| [D-031](031-change-history-about-an-erased-person-is-anonymised.md) | 2026-11-02 | The change history about an erased person is anonymised, not deleted | in force |
| [D-032](032-the-giving-records-ceiling-is-opt-in.md) | 2026-10-31 | The giving-records ceiling is opt-in | in force |
| [D-033](033-a-date-a-repeat-pattern-adds-arrives-as-a-draft.md) | 2026-10-31 | A date a repeat pattern adds arrives as a draft | in force |
| [D-034](034-a-limit-recorded-beside-the-code-rather-than-forced.md) | 2026-11-02 | A limit recorded beside the code rather than forced | in force |
| [D-035](035-the-codex-review-is-split-and-not-started-until-the-work-is-done.md) | 2026-11-01 | The Codex review is split across resets, and not started until the release work is done | in force |
| [D-036](036-branch-protection-goes-on-after-the-merge.md) | 2026-11-01 | Branch protection goes on after the merge | superseded |
| [D-037](037-branch-protection-goes-on-now-before-the-merge.md) | 2026-11-04 | Branch protection goes on now, before the merge | in force |
| [D-038](038-ship-on-the-mysql-8-0-convention-and-say-so-plainly.md) | 2026-11-01 | Ship on the MySQL 8.0 convention and say so plainly | in force |
| [D-039](039-build-the-key-loss-escape-only.md) | 2026-11-01 | Build the key-loss escape only | in force |
| [D-040](040-a-treasurer-may-export-claims-alongside-an-approver.md) | 2026-11-01 | A treasurer may export claims alongside an approver | in force |
| [D-041](041-what-goes-into-the-first-usable-release.md) | 2026-10-29 | What goes into the first usable release | in force |
| [D-042](042-a-downloaded-snapshot-is-protected-by-a-selectable-combination.md) | 2026-10-29 | A downloaded snapshot is protected by a selectable combination | in force |
| [D-043](043-transcription-gets-its-trigger-not-a-notice.md) | 2026-10-29 | Transcription gets its trigger, not a notice | in force |
| [D-044](044-a-started-series-is-still-published-as-one-entry.md) | 2026-10-29 | A series that has already started is still published as one entry | in force |
| [D-045](045-report-at-a-commit-boundary-and-keep-going.md) | 2026-11-04 | Report at a commit boundary, and keep going in the same turn | in force |
| [D-046](046-two-reporting-checks-become-hard-gates.md) | 2026-11-04 | Two checks that only printed a problem now stop the work | in force |
| [D-047](047-only-volunteers-see-the-volunteer-photo-tier.md) | 2026-11-04 | Only volunteers may see the volunteer photo tier | in force |
| [D-048](048-the-three-safety-settings-are-added.md) | 2026-11-04 | The three safety settings are added to `~/.claude/settings.json` | in force |
| [D-049](049-content-translation-is-finished-for-the-first-release.md) | 2026-11-04 | Content translation is finished for the first release | in force |
| [D-050](050-one-shared-helper-for-the-rotated-form-token.md) | 2026-11-05 | One shared helper for keeping the rotated form token, built next | in force |
| [D-051](051-check-rounds-cap-at-three-except-money-and-privacy.md) | 2026-11-05 | Check rounds cap at three, except where money, privacy or data loss is at stake | in force |
| [D-052](052-portal-content-on-the-public-website-is-in-the-first-release.md) | 2026-11-05 | Portal content on the public website is in the first release | in force |
| [D-053](053-time-zones-in-mobile-pass-deferred.md) | 2026-11-05 | The time-zone pass is in the first release; the mobile pass is not | in force |
| [D-054](054-a-blocked-item-moves-to-the-end-of-the-queue.md) | 2026-11-05 | A blocked item moves to the end of the queue | in force |
| [D-055](055-the-whole-backlog-is-cleared-before-the-release.md) | 2026-11-05 | The whole backlog is cleared before the first release | in force |
| [D-056](056-portal-content-reaches-a-public-website-three-ways.md) | 2026-11-05 | Portal content reaches a public website all three ways | in force |
| [D-057](057-public-content-is-opt-in-per-item.md) | 2026-11-05 | Public content is opt-in, one item at a time | in force |
| [D-058](058-outside-services-are-permitted-in-three-tiers.md) | 2026-11-05 | An outside service is permitted in three tiers, and the strictest wins | in force |
| [D-059](059-translation-counts-calls-as-well-as-money.md) | 2026-11-05 | Translation counts calls as well as money | in force |
| [D-060](060-an-untouched-stored-language-counts-as-no-choice.md) | 2026-11-05 | An untouched stored language counts as no choice | in force |
| [D-061](061-the-language-picker-is-its-own-piece-of-work.md) | 2026-11-05 | The language picker is its own piece of work | superseded by D-062 |
| [D-062](062-the-language-menu-exists-and-the-account-page-needs-one-too.md) | 2026-11-05 | The language menu exists, and the account page needs one too | in force |
| [D-063](063-translation-may-start-charging-on-upgrade-day.md) | 2026-11-05 | Translation may start charging on upgrade day, and the changelog says so | in force |
| [D-064](064-review-rounds-on-tooling-count-towards-the-cap.md) | 2026-11-05 | A review round that finds faults only in tooling counts towards the cap | in force |
| [D-065](065-measure-reachability-dont-infer-it.md) | 2026-11-05 | Measure whether code runs; do not infer it from the source | in force |
| [D-066](066-review-rounds-stop-on-a-tooling-only-round.md) | 2026-11-06 | Review rounds stop on the first round whose findings are all in tooling | in force |
| [D-067](067-the-owner-removes-the-two-leftover-volumes.md) | 2026-11-06 | The owner removes the two leftover Docker volumes | in force |
| [D-068](068-signula-id-gets-the-one-password-writer-fix.md) | 2026-11-06 | SIGNula.id gets the one-password-writer fix | in force |
| [D-069](069-the-schema-archive-goes-in-an-ordinary-commit.md) | 2026-11-06 | SIGNula.id's committed archive goes in an ordinary commit, not a rewrite | in force |
| [D-070](070-overdue-is-set-per-job.md) | 2026-11-06 | "Overdue" is set per job, from what each job is for | in force |
| [D-071](071-a-job-with-no-record-is-nothing-known.md) | 2026-11-06 | A job with no record says "nothing is known yet", not a warning | in force |

*71 decisions. This index is checked against the folder by
`tools/audit-checks/check_decisions_recorded.py`, so a file without a row — or a
row without a file — fails.*
