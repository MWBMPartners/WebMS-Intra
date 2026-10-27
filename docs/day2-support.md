# Day-2 Support Contract

**Status:** Active from first congregation rollout.
**Owner:** Salem874 (Mill Rd Cambridge SDA portal admin).
**Last reviewed:** 2026-05-31.

This is a short, deliberately realistic statement of how problems with the portal are handled in production. It is NOT a vendor SLA — it sets expectations between maintainers and users.

## 1. Where to report problems

| Channel | What for |
|---|---|
| Tell your portal administrator | Anything: bugs, confusion, suggestions |
| The support e-mail address shown at Help → Support in your portal (an administrator sets it at Admin → Settings → Organisation) | Account issues, password reset failures |
| Direct message to Salem874 | True emergencies only (data loss, security) |

**There is no in-portal feedback link.** This section used to name one in the footer and say
that reports through it carried the user, the page, the browser and a screenshot automatically.
No such link and no such feature exists, so ask people to say what they were doing, what they
expected and what happened, and to give the page address and the time — those two let an
administrator find the matching entry in the activity log.

## 2. Triage owner

**Primary:** Salem874 (reads inbox at least once per weekday).
**Backup (when Salem874 is unavailable):** TBD before rollout.

Backup needs documented contact + the ability to disable the portal (set `portal.maintenance.active = '1'`) if something goes badly wrong.

## 3. Response-time expectations

| Severity | Definition | Response | Fix target |
|---|---|---|---|
| **Critical** | Login broken for everyone; data loss; security breach | Within 4h | Within 24h |
| **High** | One app non-functional for everyone (e.g. expenses won't submit) | Within 1 working day | Within 7 days |
| **Medium** | Cosmetic, single-user, edge case | Within 3 working days | Best effort |
| **Low** | Suggestions, "nice to have" | Acknowledged within 7 days | Triaged into backlog |

Response time = "we've seen it and replied to you". Fix time = "deployed to production".

## 4. Critical incident path

When a Critical issue is identified, follow the
[disaster-recovery runbook](disaster-recovery-runbook.md) — it covers
the steps below command-by-command.

1. Maintenance mode goes on, at **`/admin/settings/maintenance`** (or by setting
   `portal.maintenance.active = '1'`). That is the address of the toggle; this step
   used to say `/admin/maintenance`, which nothing answers.
2. Reporter + congregation notified via a single email blast.
3. JSON backup taken if not already automatic.
4. Diagnose + fix in a feature branch.
5. Test on the dev environment.
6. Deploy + run upgrade.
7. Maintenance mode off — **you must untick it yourself.** This step used to say it
   "auto-clears on `installed_version` update", and that is wrong: an explicit flag
   wins over everything else (`Maintenance::isActive()`, `web/_core/Maintenance.php`),
   so a flag switched on by hand at step 1 stays on however the versions compare.
   Trusting the old wording leaves the portal closed after the incident is over. (What
   DOES clear by itself is the automatic closure while a database upgrade is really
   waiting — nobody switched that on, so nobody has to switch it off.)
8. Post-incident note posted in announcements + sent to congregation.

## 5. Maintenance windows

**Default upgrade window:** Tuesday or Wednesday 21:00-22:00 UK time.

**Avoid:** Friday evening through Saturday evening (Sabbath observance for SDA congregations). See issue #231 for the technical enforcement of Sabbath quiet hours.

**Avoid:** Sunday mornings (when leadership are busy and can't troubleshoot if something breaks).

Planned maintenance is announced 48h ahead via email + dashboard banner.

## 6. Escalation path

When the day-2 owner can't resolve an issue within the fix target:

1. Open / re-open the corresponding GitHub Issue with the latest state.
2. Tag the issue with `priority:` per the severity table.
3. If it's blocking a service (Sabbath morning, baptism Saturday), escalate via direct contact to the development volunteer pool — TBD.

## 7. User-facing summary

This document's user-facing equivalent lives at `/help/support` (issue #226 implementation). Users see a friendly version: "Found a problem? Report it. We aim to respond within X. For emergencies, contact Y."

## Review cadence

This document is reviewed every 6 months OR after any Critical incident, whichever is sooner.

---

*This is a living document. Update as the support model matures.*
