# Proposed next work — 10 September 2026

Everything here comes from reading the code, not from commit messages or
documents. Where something was verified, the evidence is given. Where it rests
on judgement, that is said plainly.

Ranked by what I would do first. That ranking weighs three things: how certain
the problem is, how much harm it does while it sits there, and how much work it
is. A small, certain, harmful thing beats a large, speculative, nice one.

---

## Rank 1 — Repair the version and changelog automation on `alpha`

**Size:** small (an hour or two) · **Confidence:** verified · **New issue**

**What is wrong.** Two build jobs, "Version Bump" and "Changelog", are supposed
to run whenever something is pushed to `alpha`. Neither has run since **7 July
2026**, across roughly thirty merged pull requests.

**Why it happens.** Pull requests into `alpha` are merged automatically by
`auto-merge-alpha.yml` using the build service's own access token. GitHub
deliberately does not let a job acting with that token start another job — it
is how they stop runaway loops. The auto-merge job already works around this for
deployment, by explicitly starting the deploy job after the merge. The other two
never got the same treatment, and neither offers a manual trigger, so they
cannot even be started by hand.

**What it costs.** `alpha` reports version **1.4.0** while `beta` and `main`
report 1.4.1 — so the version shown in the portal footer, in the installer, and
in every API response is wrong on the branch being tested. The changelog is only
current because people have been editing it by hand inside each pull request,
which is work the automation was written to remove.

**The fix.** Add the same explicit start to `auto-merge-alpha.yml` that deploy
already gets, and add a manual trigger to both jobs so they can be run on
demand. Small, and it is the same pattern already proven in that file.

---

## Rank 2 — Give administrators a "check my portal" page

**Size:** medium (two to three days) · **Confidence:** judgement · **New issue**

**The idea.** A page under Admin that runs the same checks the build server
runs, against the live database, and shows a plain-English list of anything
wrong.

**Why this is worth doing.** Almost every problem found in this audit was
completely invisible from inside the product. The notification preferences page
could not be opened for months. Six help guides existed with nobody able to
find them. Six menu links led to "page not found". Every fresh install carried
479 duplicate settings rows and a weaker password rule than the project
believed. None of it produced an error, a warning, or an entry in a log.

The checks that would have caught most of it already exist in
`tools/audit-checks/`. They only run on a developer's machine and in the build
service, against the files — never against a real, running site with real data.

**What it would check**, all of which this audit had to work out by hand:

- Settings stored more than once (the thing migration 187 fixes — a page would
  show whether it has come back).
- Addresses in the routing table pointing at files that are not there.
- Data endpoints switched on with no handler behind them, and handlers with no
  switch.
- Apps switched on whose entry page does not exist.
- Help guides not linked from the Help Centre.
- Whether the scheduled jobs have actually run recently — several depend on a
  token an administrator has to set, and today nothing says whether that
  happened.
- Whether the version the portal reports matches the file it comes from.

**Why it belongs in an alpha release specifically.** Testers are the people
best placed to find this kind of thing, and right now they have no way to see
it.

---

## Rank 3 — Make switching an app off actually switch it off

**Size:** medium · **Confidence:** verified · **New issue**

Switching an app off at `/admin/apps` hides its pages. It does **not** stop its
data endpoints answering. `web/_core/ApiRouter.php` never consults the app
registry at all — a search for `AppRegistry` in that file returns nothing.

So with Asset Tracker switched off, `/assets` politely says the app is
unavailable while `/api/assets/list` and `/api/v1/assets` keep returning the
data. Six public addresses that sit outside their app's own prefix behave the
same way: `attend`, `attend/save`, `calendar.ics`, `unsubscribe`,
`recordings.rss` and `visit` — none of their handlers checks whether its app is
switched on.

A site administrator switching an app off will reasonably assume it is off.
Today it is half off, and which half is not written down anywhere.

**Care needed:** some of those addresses are deliberately public and outside the
app (an unsubscribe link has to work regardless). The work is deciding, per
endpoint, whether the switch should apply — not applying it blindly.

---

## Rank 4 — Prove the "delete my data" list is complete

**Size:** medium · **Confidence:** verified gap · **Extends #47**

`web/_core/GdprEraser.php` lists **55** tables to clear when somebody asks to be
erased. An existing check confirms every table named there is real. **Nothing
checks the other direction** — that every table holding something identifying a
person appears in the list.

A table added without a matching entry is simply missed, silently, and the
failure only becomes visible if somebody exercises their right to erasure and
notices. That is the worst possible time to find out.

**The fix.** A check that finds every table with a column pointing at a person
(`userID`, `donorID`, `submitterID`, `memberID`, and so on) and fails if it is
absent from both the erasure list and a short, deliberate written exclusion
list. It would run with the other checks on every pull request, so the gap
cannot reappear.

This is the single most valuable piece of #47 that can be done with code.

---

## Rank 5 — Decide what to do about the minimum password length

**Size:** small, but needs a decision first · **Confidence:** verified

Migration 041 raised the minimum from 8 to 12 characters, to line up with OWASP
guidance. It deliberately changed only the recorded *default*, so as not to
overwrite a length an administrator had chosen.

Because portal-wide settings could not be de-duplicated (the fault migration 187
fixes), an older seed of `8` was landing after that and winning. So **every
existing install has been requiring 8**, while the code's own fallback, the
help page at `/help/admin`, and migration 041 all say 12.

Migration 187 deliberately does **not** change it. There is no reliable way to
tell an untouched seed from a deliberate choice, and quietly overriding somebody
who chose 8 would be worse than leaving a stale value.

**The decision needed:** raise existing installs to 12 automatically, or leave
them and tell administrators? I would recommend telling them — a notice on the
admin dashboard when the setting is below 12, saying what it is, what it should
be, and offering a one-click change. That respects the choice while making sure
it is a choice.

New installs already land on 12 once migration 187 is in.

---

## Rank 6 — Stop three migrations switching apps back on

**Size:** small · **Confidence:** verified · **New issue**

Three migrations force a value rather than seeding it:

| File and line | Forces |
| --- | --- |
| `web/_sql/008_calendar_events_schema.sql:330` | `calendar.enabled` back to `true` |
| `web/_sql/008_calendar_events_schema.sql:331` | the calendar icon back to its default |
| `web/_sql/009_attendance_schema.sql:166` | `attendance.enabled` back to `true` |
| `web/_sql/017_leadership.sql:133` | `leadership.enabled` back to `true` |

The installer replays every migration. So an administrator who has switched the
calendar off finds it switched back on after an upgrade retry, with nothing
saying why. Same shape as the date-format and language problems already fixed in
migrations 021 and 012, and the same fix: only set the value if the setting is
not there yet.

---

## Rank 7 — Document the other 29 data endpoints

**Size:** medium · **Confidence:** verified · **New issue**

The published description of the portal's data interface covers **33** of the
**62** endpoints that exist. The other 29 work but are undocumented, so anybody
building against the portal cannot discover them from `/api-docs`.

Now that the documentation page works without internet access and its "Try it
out" button actually saves (this session's work), filling the gap has real value.

Some of the 29 are genuinely internal — the guided-tour and live-chat endpoints,
for instance — and the right answer for those may be to mark them as internal
rather than document them as public. That judgement is part of the work.

---

## Rank 8 — Finish the mobile pass on a real phone

**Size:** small for the walk-through, unknown for what it finds · **Issue #225**

The automated half is done: pull request #453 shipped the fixes, and the
mobile-readiness check now reports zero problems across 760 files and runs on
every pull request.

The half that matters has not been done. The check says so itself: *"device
walk-through (touch behaviour, file pickers, autofill) still needs hands on a
phone"*. `docs/mobile-audit-worksheet.md` lists the nine flows and appears never
to have been filled in.

Nothing automated can tell you whether the on-screen keyboard covers the field
being typed into, whether a photo taken on the phone can be attached, or whether
giving can be completed one-handed. Somebody has to hold a phone.

---

## Rank 9 — Small clean-ups worth doing together

**Size:** small (half a day for all of them) · **Confidence:** verified

- **Three dead files** in `web/_apps/api/` (`ai-improve.php`, `translate.php`,
  `livestream-ping.php`). Their addresses were removed in migration 158 and
  nothing calls them. One of them, `translate.php`, describes a "translate this
  content" link that does not exist anywhere in the product — worth knowing,
  because the translation feature is less complete than it looks.
- **Three page handlers in the web root** —
  `web/public_html/admin/integrations/monitoring/*`. The project's own rule is
  that only the front controller, the error page and the documentation viewer
  live there. They work only because the router falls back to that folder.
- **Thirteen framework classes with no version line** in their header, which the
  house style requires: `AiAssistant`, `ErrorMonitor`, `Gatekeeper`,
  `GdprEraser`, `Giving`, `Livestream`, `Newsletter`, `Photos`, `Projects`,
  `Sms`, `Transcription`, `Translation`, `Zoom`.
- **A note in the developer documentation** that migrations 168 and 169 do not
  exist and never did, so nobody tries to fill the gap.

---

## Rank 10 — Warn when a deploy is about to remove files

**Size:** small · **Issue #107**

The documenting half is done: the developer notes explain that the deploy
mirrors the shared folders with `--delete`, so a file on the server but not in
the repository is removed on the next push.

Nothing monitors it. The suggestion is modest: have the deploy record what it
removed, and stop if the count crosses a threshold — which would catch a wrong
path or an accidental folder rename before it takes the site down. Both are
changes to `.github/workflows/deploy.yml` alone; nothing new on the server.

---

## Rank 11 — Keep a history of settings changes

**Size:** medium · **Confidence:** judgement · **New issue**

`tblSettings` records when a setting was last written but not what it was
before, or who changed it. During this audit that made several questions
unanswerable — was this value chosen, or is it a leftover seed? Migration 187
had to guess, using "does it differ from its own default" as a stand-in.

A small history table would remove the guesswork, help with support ("it worked
last week"), and support the record-keeping that data-protection law expects for
anything affecting privacy or retention.

---

## Rank 12 — Split up the two very large classes

**Size:** large · **Confidence:** judgement · **Not recommended yet**

`web/_core/AssetRegister.php` is **10,252 lines** in one class;
`web/_core/Venues.php` is 4,218. Both work and both are well commented. But a
file that long is hard to change safely, and the venue work in August showed
why — eight fatal faults of the same kind were found in one review of code that
size.

**I would not do this now.** It is a large change with no user-visible benefit
and real risk of breaking working features, and the alpha testing period is the
wrong moment. Worth revisiting after the current round of releases.

---

## Deliberately not proposed

- **The BookIT integration (#97–#103, seven issues).** Verified as not started —
  no `CalendarProvider`, no `BookITClient`, no settings, only two passing
  mentions in comments. It is a coherent piece of work but it is a *new
  integration*, and the portal has enough half-finished edges right now that
  starting a new external dependency would be the wrong call.
- **The four "for consideration" features** — watch party (#321), simulated-live
  playback (#320), in-app messaging (#304), mission trips (#302). All verified
  as not started. All reasonable ideas. None of them is a good use of an alpha
  testing period, whose job is to find out whether what already exists works.
- **Updating Swagger UI** from the pinned 5.17.14. Now self-hosted and working;
  changing the version means re-deriving three integrity hashes and re-testing
  for no benefit anybody has asked for. Worth doing when there is a reason.
