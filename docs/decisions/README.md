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
| [D-072](072-home-maps-are-covered-and-both-people-must-allow.md) | 2026-11-06 | Maps of a member's home are covered, and both people must allow them | in force |
| [D-073](073-silhouettes-are-accepted-and-uploads-come-later.md) | 2026-11-06 | Silhouettes are accepted, and a photo upload comes later | in force |
| [D-074](074-registrations-get-an-optional-installation-ceiling.md) | 2026-11-07 | Registrations get an optional installation ceiling, off by default | in force |
| [D-075](075-content-translation-gets-an-app-switch.md) | 2026-11-07 | Content translation gets its own app switch | in force |
| [D-076](076-frequent-jobs-get-allowances-from-their-own-timing.md) | 2026-11-07 | Frequent scheduled jobs get allowances from their own timing (supersedes part of D-070) | in force |
| [D-077](077-a-new-permission-decides-who-may-publish-to-the-public-website.md) | 2026-11-07 | A new permission decides who may put an item on the public website | in force |
| [D-078](078-public-website-items-get-a-switch-and-optional-dates.md) | 2026-11-07 | An item on the public website gets an on/off switch AND optional dates | in force |
| [D-079](079-a-new-event-starts-members-only.md) | 2026-11-07 | A new event starts members-only | in force |
| [D-080](080-copied-in-events-follow-a-chosen-rule.md) | 2026-11-07 | Copied-in events reach the public website by a rule each level chooses | in force |
| [D-081](081-the-installations-copied-events-rule-caps-an-organisations.md) | 2026-11-07 | The installation's rule for copied-in events caps an organisation's | in force |
| [D-082](082-only-listed-websites-may-frame-public-pages.md) | 2026-11-07 | Only websites an organisation lists may show its public pages in a frame | in force |
| [D-083](083-the-first-release-shows-the-noticeboard-and-events.md) | 2026-11-07 | The first release puts the noticeboard and events on the public website | in force |
| [D-084](084-the-old-widget-folder-may-be-deleted.md) | 2026-11-07 | The old web-root widget folder may be deleted | in force |
| [D-085](085-public-website-setup-can-be-handed-to-named-people.md) | 2026-11-07 | Setting up the public website can be handed to named people, in the first release | in force |
| [D-086](086-the-foyer-slideshow-follows-a-tiered-setting.md) | 2026-11-07 | What the foyer slideshow shows is a tiered setting | in force |
| [D-087](087-the-public-door-on-test-copies-follows-a-tiered-setting.md) | 2026-11-07 | Whether the public door on test copies is open to anybody is a tiered setting | in force |
| [D-088](088-pushes-to-the-working-branch-are-allowed-by-the-permission-check.md) | 2026-11-08 | Pushes to the working branch go through the permission check too | in force |
| [D-089](089-search-engine-markup-also-needs-the-website-tick.md) | 2026-11-08 | An event's search-engine markup also needs the public-website tick | in force |
| [D-090](090-one-website-yes-covers-every-date-of-a-repeating-copied-event.md) | 2026-11-08 | One website "Yes" for every date of a repeating copied-in event covers all its dates | in force |
| [D-091](091-dark-mode-shades-follow-the-organisations-colour.md) | 2026-11-09 | In dark mode, the main colour's shades follow the organisation's own colour | in force |
| [D-092](092-approving-a-suggested-event-keeps-it-members-only.md) | 2026-11-09 | Approving an event a member of the public suggested keeps it members-only | in force |
| [D-093](093-only-a-global-administrator-hands-out-public-website-setup.md) | 2026-11-09 | Only an organisation's global administrator may hand out "Set up the public website" | in force |
| [D-094](094-the-installation-can-forbid-framing-for-every-organisation.md) | 2026-11-09 | The installation can forbid every organisation's public pages being framed | in force |
| [D-095](095-the-public-website-page-stores-both-addresses.md) | 2026-11-09 | The public-website page stores the organisation's main website and, optionally, a public address for the portal's public pages | in force |
| [D-096](096-iphone-heic-receipts-are-accepted.md) | 2026-11-09 | iPhone photos (HEIC/HEIF) are accepted as expense receipts | in force |
| [D-097](097-the-public-door-starts-switched-off.md) | 2026-11-09 | Each organisation's public door starts switched off | in force |
| [D-098](098-other-public-pages-stay-off-the-public-address.md) | 2026-11-09 | The portal's other public pages do not answer on an organisation's public address in the first release | in force |
| [D-099](099-a-calendar-import-saves-every-row-it-can.md) | 2026-11-09 | A calendar import saves every row it can, and lists the ones it could not | in force |
| [D-100](100-a-reimported-row-already-in-the-calendar-is-skipped.md) | 2026-11-09 | A calendar-import row already in the calendar is skipped unless the manager ticks to import it | in force |

*100 decisions. This index is checked against the folder by
`tools/audit-checks/check_decisions_recorded.py`, so a file without a row — or a
row without a file — fails.*
