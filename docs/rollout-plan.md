# Rollout Plan — Two-Phase

**Status:** Active before first congregation rollout.
**Last reviewed:** 2026-05-31.

## Overview

To minimise day-1 issues affecting the whole congregation, we roll out in two phases:

1. **Phase 1 — Leadership pilot (week 1)**: 3-5 leadership volunteers use the portal in production. Feedback collected via in-portal widget + dedicated email.
2. **Phase 2 — Whole-congregation rollout (week 2+)**: invites sent to all members, "what's new" tour shown to everyone, day-2 support contract active.

## Phase 1 — Leadership pilot

### Participants

| Name | Role | Notes |
|---|---|---|
| _TBD_ | Elder | Primary feedback channel |
| _TBD_ | Deacon | |
| _TBD_ | Ministry lead | |
| _TBD_ | Treasurer | Specifically tests expenses |
| _TBD_ | Communication lead | Tests announcements + calendar |

### What's in scope for the pilot

- All shipped apps (dashboard, calendar, attendance, expenses, leadership, announcements, documents, tasks, prayer requests).
- Sign-in via local, MS365 SSO, passkey.
- Mobile + desktop.
- Email delivery (password reset, notifications).

### Flag

`portal.rollout.pilot_mode` setting (`tblSettings`):
- `1` = pilot mode is on. Feedback widget visible to designated pilot users. Some risky new features are hidden until phase 2.
- `0` = pilot complete. Whole-congregation mode active.

### Communications

> "We're starting with leadership for the first week to make sure everything's right before we open it to the whole congregation. Your feedback during this week shapes the rollout."

### Exit criteria (pilot → general)

Phase 1 ends when:

- No Critical or High-severity issues have surfaced in the last 48h.
- All 5 pilot users have signed in and completed at least one workflow.
- Email delivery confirmed working (no spam folder reports).
- Mobile UX confirmed acceptable on both iOS and Android.

If criteria aren't met, the pilot extends by 1 week (with documented reason).

## Phase 2 — Whole-congregation rollout

### Pre-launch checklist

- [ ] Pilot exit criteria met.
- [ ] `portal.rollout.pilot_mode` set to `0`.
- [ ] Invites generated for all members (via the invite onboarding workflow — #239).
- [ ] Announcement scheduled for the next Sunday service + email blast.
- [ ] Day-2 support contract communicated (see `docs/day2-support.md`).
- [ ] "What's new" tour configured for the welcome experience (#237).
- [ ] Maintenance window documented for any planned interventions.

### Launch communications

- **Service announcement**: "Our portal is now live for everyone. You'll receive an email invite this week."
- **Email blast**: with the invite link + 3-line "what it's for" + the `/help/getting-started` link.
- **In-portal**: dashboard banner pointing to the welcome tour.

### Week-2 review

After 1 week of whole-congregation use, review:

- Sign-in rate (target: 60% of invited users have signed in).
- Issue reports (severity + volume).
- Most-used apps.
- Most-confused-about features (from feedback widget).

Findings shape the next iteration.

## Tooling support

| Feature | Issue | Status |
|---|---|---|
| Pilot-mode flag | #232 | ✅ (this PR) |
| Feedback widget | #232 | 🟡 Deferred to Phase 2 prep |
| Invite-based onboarding | #239 | Pending |
| "What's new" tour | #237 | Pending |
| Day-2 support docs | #226 | ✅ Committed |

---

*This document is updated as the rollout progresses.*
