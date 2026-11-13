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
| [D-101](101-a-public-address-takes-effect-only-once-approved.md) | 2026-11-09 | An organisation's public address takes effect only once the installation's global administrator approves it | in force |
| [D-102](102-webhook-ports-are-an-installation-setting.md) | 2026-11-10 | Which port numbers a webhook may use is an installation setting, standard ports by default | in force |
| [D-103](103-the-approver-role-sees-only-its-own-departments-claims.md) | 2026-11-10 | The organisation-wide Approver role sees only its own departments' expense claims | in force |
| [D-104](104-changing-or-resetting-a-password-signs-out-other-browsers.md) | 2026-11-10 | Resetting or changing a password signs the person out of every other browser | in force |
| [D-105](105-the-health-pages-mail-count-covers-the-viewers-own-organisation.md) | 2026-11-10 | The health page's mail status counts only the viewer's own organisation | in force |
| [D-106](106-attendance-totals-are-seen-by-administrators-and-a-chosen-role.md) | 2026-11-10 | An organisation's attendance totals are seen by administrators and a role it chooses | in force |
| [D-107](107-no-change-to-the-password-minimum-on-running-installations.md) | 2026-11-10 | No change to the minimum password length on running installations: none exists | in force |
| [D-108](108-an-administrator-setting-a-password-signs-the-person-out.md) | 2026-11-10 | An administrator setting someone's password signs that person out of every browser | in force |
| [D-109](109-ordinary-members-do-not-see-session-headcounts.md) | 2026-11-10 | Ordinary members do not see attendance sessions' headcounts | in force |
| [D-110](110-who-opened-a-care-case-is-kept.md) | 2026-11-10 | Who opened a pastoral care case is kept when that worker is erased | in force |
| [D-111](111-who-checked-a-child-in-or-out-is-kept.md) | 2026-11-10 | Who checked a child in or out is kept when that helper is erased | in force |
| [D-112](112-a-workers-name-stays-on-their-own-case.md) | 2026-11-10 | A worker's name stays on a visit they made on their own care case | in force |
| [D-113](113-kept-worker-names-and-numbers-are-said-plainly.md) | 2026-11-10 | A kept worker's name and number both stay, and the portal says so plainly | in force |
| [D-114](114-who-may-delete-a-past-attendance-session.md) | 2026-11-10 | A past attendance session can be deleted by administrators, the attendance role and its recorder | in force |
| [D-115](115-an-api-key-with-attendance-write-may-delete-sessions.md) | 2026-11-10 | An API key with attendance-write may still delete attendance sessions | in force |
| [D-116](116-the-change-history-is-kept-for-ever.md) | 2026-11-10 | The change history (audit trail) is kept for ever; members are told plainly | in force |
| [D-117](117-the-activity-log-may-be-kept-for-ever.md) | 2026-11-10 | The activity log may be kept for ever, by choice (0 = never, capped by the installation) | in force |
| [D-118](118-an-organisation-sets-its-own-activity-log-period.md) | 2026-11-10 | An organisation's own administrator sets its activity-log period, within the installation's | in force |
| [D-119](119-lowering-the-activity-log-ceiling-is-refused-below-an-organisation.md) | 2026-11-10 | Lowering the installation's activity-log period below an organisation's is refused, naming them | in force |
| [D-120](120-a-group-meeting-place-map-is-the-hosts-home.md) | 2026-11-11 | A small group's meeting-place map is treated as the host's home (gated like D-072) | in force; in part superseded by D-124, D-128 |
| [D-121](121-a-public-address-may-be-requested-by-several-until-approved.md) | 2026-11-11 | A public address may be requested by several organisations until one is approved | in force |
| [D-122](122-erasing-the-person-who-entered-a-group-meeting-place-removes-it.md) | 2026-11-11 | Erasing the person who entered a group's meeting place empties the address | in force; in part superseded by D-124 |
| [D-123](123-a-small-group-may-rotate-the-home-it-meets-in.md) | 2026-11-11 | A small group may rotate the home it meets in; each place is its own host's | in force |
| [D-124](124-each-listed-home-has-a-named-host.md) | 2026-11-11 | Each listed meeting home has a named host, whose agreement and erasure count | in force |
| [D-125](125-who-may-add-a-groups-meeting-home.md) | 2026-11-11 | Leaders may add any meeting home; a member may add their own | in force |
| [D-126](126-removing-a-meeting-home-deletes-its-address-at-once.md) | 2026-11-11 | Removing a meeting home deletes its address at once | in force |
| [D-127](127-members-see-where-their-group-meets-next.md) | 2026-11-11 | Members see where their small group meets next | in force |
| [D-128](128-a-place-marked-not-anybodys-home-may-show-a-map.md) | 2026-11-11 | A meeting place marked 'not anybody's home' may show a map without a host | in force |
| [D-129](129-an-event-with-no-end-time-stays-listed-until-the-end-of-its-day.md) | 2026-11-11 | An event with no end time stays on the public website's list until the end of its day, or for a chosen time | in force |
| [D-130](130-looking-at-expense-records-is-recorded.md) | 2026-11-11 | Looking at expense records is written to the activity log | superseded in part by D-132 |
| [D-131](131-a-retyped-home-marked-not-anybodys-home-is-a-known-limit.md) | 2026-11-11 | A home emptied and typed back in as 'not anybody's home' is a known limit, with a warning | in force |
| [D-132](132-a-look-at-expense-records-that-showed-none-is-not-recorded.md) | 2026-11-11 | A look at expense records that showed no claim is not recorded | in force |
| [D-133](133-the-installation-and-each-organisation-choose-their-country.md) | 2026-11-11 | The installation and each organisation choose their own country; nothing defaults to Great Britain | in force |
| [D-134](134-an-approved-suggestion-e-mails-the-person-who-suggested-it.md) | 2026-11-11 | An approved event suggestion e-mails the person who suggested it | in force |
| [D-135](135-no-live-install-so-a-schema-change-needs-no-migration.md) | 2026-11-02 | While no customer installation exists, a schema change needs no numbered migration | in force |
| [D-136](136-moderators-notes-are-for-signed-in-administrators-only.md) | 2026-11-11 | Moderators' notes and who-did-what numbers are for signed-in administrators only | in force |
| [D-137](137-a-failed-sign-in-keeps-the-name-that-was-typed.md) | 2026-11-11 | A failed sign-in keeps the name that was typed, readable, in the activity log | in force |
| [D-138](138-an-organisations-own-labels-stay-in-activity-descriptions.md) | 2026-11-11 | An organisation's own labels stay in activity-log descriptions | in force |
| [D-139](139-every-money-record-keeps-its-own-currency.md) | 2026-11-11 | Every money record keeps its own currency; an expense keeps the quoted and the converted amount | in force |
| [D-140](140-a-visitors-event-suggestion-details-are-erased-on-request.md) | 2026-11-11 | A visitor's event-suggestion details are erased on request, not automatically | in force |
| [D-141](141-an-open-rota-swap-request-is-e-mailed-to-every-member.md) | 2026-11-11 | An open rota swap request is e-mailed to every member who has the switch on | in force |
| [D-142](142-a-workers-download-lists-records-carrying-their-name-without-details.md) | 2026-11-11 | A worker's data download lists the records that carry their name, without the other person's details | in force |
| [D-143](143-a-series-page-offers-every-hidden-date-back.md) | 2026-11-11 | A series' bulk-edit page offers every hidden date back, however it was hidden | in force |
| [D-144](144-a-restored-date-on-a-day-already-taken-needs-a-second-confirmation.md) | 2026-11-11 | Bringing back a date onto a day the series already uses needs a deliberate second confirmation | in force |
| [D-145](145-a-restored-date-comes-back-as-a-draft.md) | 2026-11-11 | A date brought back comes back as a draft | in force |
| [D-146](146-a-cancelled-date-brought-back-stays-cancelled.md) | 2026-11-11 | A date that was cancelled before it was hidden comes back still cancelled | in force |
| [D-147](147-a-postponed-date-brought-back-stays-postponed.md) | 2026-11-11 | A date that was postponed before it was hidden comes back still postponed | in force |
| [D-148](148-a-visitor-erasure-request-records-a-tick-and-a-short-note.md) | 2026-11-12 | A visitor erasure request records a required tick and a short note on how the person was checked | in force |
| [D-149](149-no-confirmation-e-mail-after-a-visitor-erasure.md) | 2026-11-12 | A visitor is not sent a confirmation e-mail after their details are erased | in force |
| [D-150](150-a-visitor-erasure-request-reaches-only-its-own-organisation.md) | 2026-11-12 | A visitor erasure request reaches only the organisation whose administrator filed it | in force |
| [D-151](151-records-in-an-organisation-the-worker-has-left-are-counted-not-listed.md) | 2026-11-12 | A worker's download counts, and does not list, records in organisations they have left | in force |
| [D-152](152-a-typed-money-amount-is-figures-only.md) | 2026-11-12 | A money amount typed into a box is figures only | in force |
| [D-153](153-the-asset-import-reads-amounts-like-the-bank-import.md) | 2026-11-12 | The asset import reads amounts the way the bank-statement import does | in force |
| [D-154](154-a-members-dbs-copy-leaves-out-who-recorded-it.md) | 2026-11-12 | A member's copy of their own DBS checks leaves out who recorded each one | in force |
| [D-155](155-a-shared-currency-sign-in-an-imported-file-is-refused.md) | 2026-11-12 | A currency sign shared by several currencies is refused in an imported file, with the true reason | superseded by D-165 |
| [D-156](156-later-issues-are-triaged-before-the-first-install.md) | 2026-11-12 | Issues raised after 5 November are triaged: only the risky ones are fixed before the first install | in force |
| [D-157](157-the-pound-sign-in-a-file-is-read-only-for-pounds-sterling.md) | 2026-11-12 | "£" in an imported file is read only for an organisation using pounds sterling | superseded by D-165 |
| [D-158](158-outside-services-are-named-now-and-gated-later.md) | 2026-11-12 | Outside services not yet under D-058 are named on the privacy page now and gated later | in force |
| [D-159](159-a-series-website-window-is-fixed-dates-said-plainly.md) | 2026-11-12 | A series' public-website window is one set of fixed dates, said plainly on the form | in force |
| [D-160](160-the-api-pages-website-link-follows-a-setting.md) | 2026-11-12 | The API page's publisher "Website" link follows a setting | in force |
| [D-161](161-the-language-switch-return-goes-through-the-redirect-judge.md) | 2026-11-12 | The language switch's return address goes through the redirect judge, and the judge looks only at the path | in force |
| [D-162](162-members-see-no-last-changed-time-on-past-sessions.md) | 2026-11-12 | Members see no "last changed" time on a past attendance session | in force |
| [D-163](163-the-privacy-retention-setting-is-kept-and-given-a-job.md) | 2026-11-12 | The unused privacy data-retention setting is kept and given a real job | in force |
| [D-164](164-an-erasure-empties-the-hashed-address-on-asset-changes.md) | 2026-11-12 | An erasure empties the hashed network address on asset-change records the person made | in force |
| [D-165](165-a-currency-sign-in-a-file-means-the-organisations-own-currency.md) | 2026-11-12 | A currency sign in an imported file is read as the organisation's own currency whenever that currency uses the sign | in force |
| [D-166](166-the-privacy-retention-setting-governs-how-long-consents-are-kept.md) | 2026-11-12 | The privacy data-retention setting governs how long consent records are kept | in force |
| [D-167](167-whether-an-organisation-issues-tax-receipts-is-a-setting.md) | 2026-11-12 | Whether an organisation issues tax receipts for gifts is its own setting | in force |
| [D-168](168-a-hosts-home-stops-being-the-meeting-place-when-they-leave.md) | 2026-11-12 | When a host leaves a small group, their home stops being its meeting place automatically | in force |
| [D-169](169-a-pending-suggestion-cannot-be-published-before-approval.md) | 2026-11-12 | A pending event suggestion cannot be published before it is approved | in force |
| [D-170](170-a-repair-step-for-erased-accounts-re-saved-before-977.md) | 2026-11-12 | A repair step is built for erased accounts re-saved before #977 | in force |
| [D-171](171-rota-swap-requests-have-configurable-limits.md) | 2026-11-12 | Rota swap requests have configurable limits, per duty and per day, set at installation and organisation level | in force |
| [D-172](172-the-single-gift-ceiling-is-configurable.md) | 2026-11-12 | The single-gift ceiling is configurable at installation and organisation level | in force |
| [D-173](173-outside-provider-spending-is-recorded-in-the-providers-own-currency.md) | 2026-11-12 | Outside-provider spending is recorded in the provider's own currency | in force |
| [D-174](174-an-errors-words-have-e-mail-addresses-removed-when-written.md) | 2026-11-12 | An error's own words have any e-mail address removed before they are stored | in force |
| [D-175](175-the-support-address-is-still-recorded-in-the-activity-log.md) | 2026-11-12 | The organisation's support e-mail address is still recorded in the activity log when it is changed | in force |
| [D-176](176-an-anonymous-prayer-request-is-erased-by-a-visitor-address-request.md) | 2026-11-12 | An anonymous prayer request is erased by the same visitor-address request as an event suggestion, and its network address is kept until then | in force |
| [D-177](177-letter-only-currency-signs-are-not-read-in-a-file.md) | 2026-11-12 | A currency sign made only of letters is not read in an imported file; the three-letter code is | in force |
| [D-178](178-network-addresses-are-kept-and-handled-as-personal-data.md) | 2026-11-12 | Network (IP) addresses are kept for audit, security and support, and handled as personal data | in force |
| [D-179](179-the-organisations-page-is-called-organisations.md) | 2026-11-12 | The page that manages an installation's organisations is called "Organisations" everywhere | in force |
| [D-180](180-an-imported-date-is-decided-as-it-stands-now.md) | 2026-11-12 | An imported date is approved or declined as it stands now | in force |
| [D-181](181-an-event-starts-between-1900-and-at-least-2200.md) | 2026-11-12 | An event's start must fall between 1900 and 2200, or fifty years ahead if that is later | in force |
| [D-182](182-the-map-answer-memory-is-kept-for-a-capped-setting.md) | 2026-11-13 | How long a remembered map answer is kept is a setting, capped by the installation | in force |
| [D-183](183-a-remembered-home-position-is-personal-data.md) | 2026-11-13 | A remembered map answer for a home is personal data; which addresses are homes is chosen | in force |
| [D-184](184-a-members-data-copy-includes-their-care-cases.md) | 2026-11-13 | A member's data copy includes their own pastoral care cases, without workers' names or visit notes | in force |

*184 decisions. This index is checked against the folder by
`tools/audit-checks/check_decisions_recorded.py`, so a file without a row — or a
row without a file — fails.*
