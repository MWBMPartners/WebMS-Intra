# Feature Gap Ledger
<!-- dev-team: ledger=features schema=1.0 -->

How to read the evidence labels in this file:

- **PROVEN** means I read it in this repository or ran a command against it on 14 September 2026.
- **INFERRED** means it follows from what I read, but I did not see it directly.
- For competitor features, **opened** means I opened the vendor's own page and it confirmed the claim.
  **Summary only** means the vendor's page blocked automated reading (ChurchSuite's and Breeze's help sites both
  refused with "403 Forbidden"), so the claim rests on a search engine's summary of that page. Those claims are
  marked low confidence.
- No competitor claim in this file rests on my own memory. Every claim has a web address.

## Aim  (the yardstick every gap is measured against)

WebMS-Intra is a self-hosted back-office portal that runs on ordinary shared web hosting and is installed from a
download. It is built first for churches, and for charities, schools and community groups through brand presets. It
brings members, events, rotas, giving, communications and safeguarding together in one place.
**Category:** church management software, extending to organisation portals for charities and schools.

INFERRED: I worked this aim out from the code and the project's CLAUDE.md. The owner has not confirmed it. The
plugin's usual "scope gate", where the owner confirms the aim before research starts, was skipped: the brief supplied
the frame.

**The scope boundary used to rule features out.** The code shows this is not:

- a school management system. PROVEN: there are no pupil, class, behaviour or grades tables. The School, Charity and
  Community presets in `web/_core/brand-defaults.php` (lines 91-121) set only a name and an artwork folder; a search
  of that file for an app list found none.
- a fundraising or donor-pipeline CRM (a customer database built around winning donors);
- a native phone app. The portal is a progressive web app, meaning a website that can be installed like an app and
  can send notifications;
- a public website builder. The portal is the back office, and making its public content visible on the customer's own
  website is planned separately under #493.

## Run record

- **last-run:** 14 September 2026 · **scope-setting:** balanced · **depth:** standard
- **Code state relied on:** branch `claude/alpha-wip` at commit `dc193b1`. The working tree has uncommitted changes.
  - PROVEN: every file I relied on is unchanged from that commit, with two exceptions:
    - `web/_sql/full_schema.sql` has uncommitted changes, but none of them touch the areas I relied on. I checked the
      uncommitted changes for the tables and addresses named below and found none.
    - `web/_apps/calendar/event-register.php` has uncommitted changes, and I read it as it stands in the working tree.
- **Sweep verdicts used:** `.claude-work/sweep/batch-01.json` to `batch-09.json`, cited by issue number.
- **How this run differed from the plugin's method. Read this before trusting the ledger.**
  - The plugin expects separate agents for the scout, researcher, scorer, skeptic and completeness-critic roles. This
    session could not start other agents, so one agent (Opus 5) did every role, one slice after another.
  - **The skeptic check is therefore NOT independent.** It did re-open the cited vendor pages, and it re-checked
    absence against the code rather than against the project's own FEATURES.md. But the same agent that proposed each
    gap also tried to refute it.
  - Treat this ledger as not yet independently reviewed.
- **Slices, researched one at a time in this order:**
  1. people and membership;
  2. giving;
  3. communications;
  4. events, check-in, rotas and room booking;
  5. groups, forms and reports;
  6. the platform: website embedding, integrations and data import;
  7. what charities and schools expect, plus a completeness pass.

## Comparables researched

- **Planning Center** (planningcenter.com). Category leader. The most widely used church management suite; US-based,
  sold as separate products (People, Services, Giving, Registrations, Calendar, Groups, Check-Ins). Primary source:
  help.planningcenter.com.
- **ChurchSuite** (churchsuite.com). Direct competitor. UK-based, so it covers Gift Aid and DBS checks (UK criminal
  records checks). Primary source: support.churchsuite.com (summary only: the site returned 403).
- **Breeze** (breezechms.com). Direct competitor. A deliberately simple system for smaller churches. Primary source:
  support.breezechms.com (403) and breezechms.com.
- **Beacon** (beaconcrm.org). Adjacent: a UK charity CRM. It shows what a charity customer would expect for giving
  and volunteers. Primary source: guide.beaconcrm.org.
- **Arbor** (arbor-education.com). Adjacent: a UK school management system with a parent app. It shows what a school
  customer would expect for talking to parents. Primary source: support.arbor-education.com.

**Coverage.** Domains swept:

- people records and permissions;
- follow-up processes;
- giving and Gift Aid;
- email, text and chat messaging;
- events and repeating events;
- volunteer rotas;
- children's check-in;
- room booking;
- groups;
- forms;
- reports;
- embedding content on the organisation's own website;
- integrations;
- safeguarding;
- volunteer hours.

**Not covered.**

- Other comparables that were not researched: ChurchDesk, Elvanto/Tithely, Donorfy and ParentMail.
- Pricing tiers.
- Accessibility and phone behaviour, which cannot be judged from public pages.
- Accounting links such as Xero: I found no vendor page confirming a direct link, so none is claimed.
- ChurchSuite and Breeze claims could not be opened directly (403).

## In-scope gaps  (ranked — the buildable candidates)

Ranked by importance within the aim first, then lower effort and lower risk. Where a gap is already an issue or a
sweep follow-up, that is said.

1. FG-001 table-stakes — **Grant roles and permissions to people** — importance High — nobody can be made a
   treasurer, children's team member, care team member or coordinator from inside the portal.
2. FG-002 table-stakes — **Message a chosen group by email or text** — importance High — a small group, a rota team
   or a list, not just everyone or one event's attendees.
3. FG-003 table-stakes — **Repeating events** — importance High — a weekly service has to be entered by hand every
   week.
4. FG-004 table-stakes — **Show events, groups and giving on the organisation's own website** — importance High —
   already planned as #493.
5. FG-005 table-stakes — **Regular giving (card or Direct Debit)** — importance High — open as #447 and blocked on
   the owner's choice of payment provider.
6. FG-006 table-stakes — **DBS check expiry reminders** — importance Med — small; the sweep already proposes it on
   #310.
7. FG-007 table-stakes — **Volunteer "unavailable" dates and clash warnings on rotas** — importance Med.
8. FG-008 table-stakes — **Paid event sign-up, with the sign-up form made reachable** — importance Med.
9. FG-009 table-stakes — **Organisation-defined extra fields on people** — importance Med.
10. FG-010 table-stakes — **Name badges and pick-up labels at children's check-in** — importance Med.
11. FG-011 table-stakes — **Households: linking family members together** — importance Med.
12. FG-012 table-stakes (UK) — **Send Gift Aid claims straight to HMRC** — importance Med.
13. FG-013 nice-to-have — **Merge duplicate people** — importance Low.
14. FG-014 nice-to-have — **Questions that appear depending on an earlier answer, in Forms** — importance Low.
15. FG-015 nice-to-have — **Log volunteer hours** — importance Low.
16. FG-016 nice-to-have — **Keep a mailing list in step with Mailchimp** — importance Low.
17. FG-017 differentiator — **Group chat and direct messages** — importance Low — open as #304.

---

- **id:** FG-001
  **feature:** Grant roles and permissions to people
  **what:** An administrator picks a person and gives them a role (treasurer, children's team, care team, visitor
  coordinator, event coordinator) or a permission level, from a screen in the portal.
  **class:** table-stakes
  **importance:** High. Many apps that already exist are, in practice, usable by administrators only until this
  exists.
  **prevalence:** 2/2 checked (Planning Center, ChurchSuite).
  **seen-in:**
  - Planning Center: https://help.planningcenter.com/en/136861-permissions-in-people.html (docs, opened: "select
    which level of access to give this person").
  - ChurchSuite: https://support.churchsuite.com/article/126-creating-new-users (docs, summary only: per-module access
    set as None, Use, Write or Manage).
  **effort:** M, because the role tables already exist and only the screen and its save handler are missing.
  **risk:** Med, because it changes who can open care notes, children's records and giving.
  **purpose-fit:** The code is built toward this. `tblRoles` and `tblUserRoles` exist. According to the sweep
  verdicts (PROVEN there, not re-read for this ledger), pages already check `App::hasRole(...)`:
  `Giving::canManage()` checks `treasurer`, `kids/checkin.php:17` checks `kids_team`, and `care/index.php:20-21`
  checks `care_team`. PROVEN by my own search of the working tree: nothing in `web/_apps`,
  `web/_core` or `web/_install` ever inserts into `tblUserRoles`; the only use found is a read at
  `web/_apps/admin/users/index.php:129`. The sweep verdicts on #17, #257, #258, #298, #341 and #440 record the same
  consequence, app by app. Related but separate: granting an event coordinator crashes on a wrong column name (sweep
  on #341; the fix is in the uncommitted working tree).
  **confidence:** high
  **spec-seed:**
  - Add role tick-boxes to the existing user edit page (`admin/users`), saved per site.
  - Only a site administrator may grant roles; only a global administrator may grant roles that reach every site.
  - Record every change in the audit trail.
  - Existing issue: none open. The sweep's follow-up on #17 proposes one.

- **id:** FG-002
  **feature:** Message a chosen group by email or text
  **what:** Pick a small group, a rota team, a role or a saved list, write one message, and send it by email and/or
  text. People who have opted out are skipped.
  **class:** table-stakes
  **importance:** High. This is what a group leader or administrator does every week.
  **prevalence:** 3/3 checked (ChurchSuite, Arbor, Breeze).
  **seen-in:**
  - Arbor: https://support.arbor-education.com/hc/en-us/articles/4407804794257-Send-email-SMS-or-in-app-messages-to-guardians-from-a-Trip
    (docs, opened).
  - ChurchSuite: https://support.churchsuite.com/article/425-communicating-to-individuals-and-groups-through-churchsuite
    (docs, summary only).
  - Breeze: https://support.breezechms.com/hc/en-us/categories/360000114273-Knowledge-Base (docs, summary only: "use
    that tag to communicate with the group").
  **effort:** M, because sending is already built (the email sender, the batched newsletter sender, the text-message
  sender); what is missing is choosing the recipients.
  **risk:** Low-Med. Sending volume on shared hosting is the main concern, and the newsletter sender already batches
  its sends by the hour.
  **purpose-fit:**
  - PROVEN: newsletter segments can only mean "everyone" or "people with these roles"
    (`web/_core/Newsletter.php:64`), and roles cannot be granted (FG-001).
  - PROVEN: the event broadcast (`calendar/event-broadcast-send.php`) reaches one event's attendees and volunteers
    only.
  - PROVEN: the Small Groups app has no send page. Its files are listed in `web/_apps/small-groups/` and none calls
    `Mailer::send`.
  - Sweep on #269: a scheduled newsletter is never sent when its time arrives.
  - Sweep on #272 and the brief: text-message credentials saved through the admin page are not decrypted until #497
    lands.
  **confidence:** high for the code; low for the ChurchSuite and Breeze citations.
  **spec-seed:**
  - Widen the segment rule to "members of group X", "rota team Y" or "a Reports Builder result".
  - Put a "Message this group" button on the group and rota pages that opens the existing composer.
  - Honour notification preferences and quiet hours.
  - Depends on FG-001 for the role-based lists, and on #497 for text messages.

- **id:** FG-003
  **feature:** Repeating events
  **what:** Create "every Saturday at 10:00" once. Change or cancel one date, or this date and every later one,
  without touching the rest.
  **class:** table-stakes
  **importance:** High. Almost every organisation's calendar is mostly repeating meetings.
  **prevalence:** 1/1 checked (Planning Center).
  **seen-in:** Planning Center: https://help.planningcenter.com/en/140958-create-and-manage-events.html (docs, opened:
  "you can update the single event or all future events").
  **effort:** L. It needs rule storage, working out the dates, the calendar views, the iCal calendar-subscription
  export and the reminders.
  **risk:** Med, because every calendar view and export is touched.
  **purpose-fit:**
  - PROVEN: `tblRecurrenceRules` exists, but nothing inserts, updates or deletes rows in it (search of
    `web/_apps` and `web/_core`).
  - PROVEN: the only "repeat" match in `calendar/manage/series-edit.php` is PHP's `str_repeat` function (line 163);
    that page bulk-edits events already created by hand.
  - Sweep on #26: not done. Sweep on #333: per-date changes are saved but nothing ever applies them.
  - Sweep on #327: imported calendar feeds do not expand repeating events either.
  **confidence:** high for the code. Low for prevalence: only one comparable was checked, although repeating events are
  near-universal in calendar software (INFERRED).
  **spec-seed:**
  - Store a repeat rule per series.
  - Work out the dates on read, for a limited window ahead, rather than writing thousands of rows.
  - Apply the existing `tblEventOccurrenceOverrides` table when showing a date.
  - Compare clock times as text, never as timestamps, to survive the clock change (project memory: wall-clock times).
  - Existing issue: #26 (closed, not done).

- **id:** FG-004
  **feature:** Show events, groups and giving on the organisation's own website
  **what:** Copy a snippet into the church, charity or school website to show upcoming events, a group finder with a
  "join" button, or a giving form.
  **class:** table-stakes
  **importance:** High. The portal is the back office, and the public sees the organisation's own website.
  **prevalence:** 2/2 checked (Planning Center, ChurchSuite).
  **seen-in:**
  - Planning Center: https://help.planningcenter.com/en/137808-embed-church-center-calendar.html (docs, opened).
  - ChurchSuite: https://support.churchsuite.com/article/97-embed-small-group-lists-and-maps-in-your-website and
    https://support.churchsuite.com/article/333-enabling-small-group-sign-up-through-your-website (docs, summary only).
  **effort:** L, because it depends on the separate public entrance being designed under #493.
  **risk:** Med. It exposes data publicly; the sweep on #478 found internal events readable by guessing event numbers
  at `/attend`.
  **purpose-fit:**
  - PROVEN: only a countdown widget exists for other websites (routes `widget/countdown` and `widget/countdown.json`,
    `full_schema.sql:4877-4878`).
  - PROVEN: every Small Groups address needs sign-in (`full_schema.sql:8453-8464`, all marked protected).
  - Sweep on #493: the Noticeboard needs sign-in.
  - Project memory: "the portal is the back office only".
  **confidence:** high
  **spec-seed:**
  - Deliver it through the #493 public entrance.
  - Offer read-only lists of public events and groups first, then group sign-up with approval.
  - No web address may be built in (#500).
  - Existing issue: #493, in progress. Do not open a second issue.

- **id:** FG-005
  **feature:** Regular giving (card or Direct Debit)
  **what:** A giver sets up a monthly gift once, and can pause, change or stop it themselves.
  **class:** table-stakes
  **importance:** High for any customer that switches Giving on.
  **prevalence:** 3/3 checked (Planning Center, Beacon, ChurchSuite).
  **seen-in:**
  - Planning Center: https://help.planningcenter.com/en/138387-recurring-donations.html (docs, opened).
  - Beacon: https://guide.beaconcrm.org/en/articles/5720178-donation-forms (docs, summary only: Direct Debit through
    GoCardless, cards through Stripe).
  - ChurchSuite: https://support.churchsuite.com/article/114-accepting-online-donations (docs, summary only).
  **effort:** L. It needs provider subscriptions, saved payment methods, and handling failed payments and expired cards.
  **risk:** High, because it is money.
  **purpose-fit:**
  - Sweep on #447 (PROVEN there): nothing writes `tblPaymentMethod`, and `Payments.php` has no subscription handling.
  - PROVEN: `web/_apps/payments/checkout.php:7-8` accepts only one-off `giving` and `pledge` payments.
  - The brief: payment credentials saved through the admin page are not decrypted until #497 lands, so even one-off
    online giving needs #497 first.
  **confidence:** high
  **spec-seed:**
  - Decided by the owner's choice of payment provider.
  - A regular gift should create an ongoing Gift Aid declaration automatically, as ChurchSuite describes.
  - Existing issue: #447 (open, blocked).

- **id:** FG-006
  **feature:** DBS check expiry reminders
  **what:** The safeguarding lead is emailed before a volunteer's DBS check (the UK criminal records check) runs out,
  instead of only seeing it if they open the page.
  **class:** table-stakes (UK)
  **importance:** Med. It is a safeguarding duty for UK churches and charities.
  **prevalence:** 1/1 checked (ChurchSuite).
  **seen-in:** ChurchSuite: https://support.churchsuite.com/article/430-using-churchsuite-to-help-you-manage-dbs-renewals
  (docs, summary only: "assigned users will receive email reminder notifications").
  **effort:** S, because the scheduled reminder job and its once-only reminder log already exist.
  **risk:** Low.
  **purpose-fit:**
  - Sweep on #310 (PROVEN there): `tblDbsChecks` and the admin page exist, but nothing in `web/_apps/cron` reads
    that table.
  - INFERRED from the project's CLAUDE.md, not re-read in `cron/user-reminders.php` for this ledger: its reminder
    log was reserved for future one-off reminders "e.g. DBS-expiry".
  **confidence:** high for the code; low for the citation.
  **spec-seed:** Add a DBS family to `cron/user-reminders.php`: a set number of days before expiry, sent once per check,
  to the site's safeguarding lead. Existing: the sweep's proposed follow-up on #310.

- **id:** FG-007
  **feature:** Volunteer "unavailable" dates and clash warnings on rotas
  **what:** Volunteers mark dates they cannot serve. Whoever builds the rota is warned before scheduling them on those
  dates, or twice at the same time.
  **class:** table-stakes
  **importance:** Med
  **prevalence:** 2/2 checked (Planning Center, ChurchSuite).
  **seen-in:**
  - Planning Center: https://help.planningcenter.com/en/142872-manage-blockout-dates.html (docs, opened: "a warning
    message will notify them that you're unavailable").
  - ChurchSuite: https://support.churchsuite.com/article/378-rota-unavailability and
    https://support.churchsuite.com/article/220-rota-clash-management (docs, summary only).
  **effort:** M. It needs one new table, a "my unavailable dates" page and a check in `rota/slot-save.php`.
  **risk:** Low.
  **purpose-fit:**
  - PROVEN: the rota has three tables only, `tblRotaRoleType`, `tblRotaSlot` and `tblRotaSwapRequest`
    (`full_schema.sql:2809-2846`).
  - PROVEN: a search of `web/_apps/rota` for "unavailab", "blockout" or "availability" finds nothing.
  - Swaps and reminders already exist (sweep on #256).
  **confidence:** high
  **spec-seed:**
  - A member adds a date or date range.
  - Saving a slot that clashes shows a warning but still allows the save.
  - The swap-accept page refuses a clash.
  - A later option would be offering replacements when someone declines.
  - Existing issue: none found.

- **id:** FG-008
  **feature:** Paid event sign-up, with the sign-up form made reachable
  **what:** People register for a camp, conference or trip, answer its questions (allergies, consent) and pay, or pay a
  deposit, online.
  **class:** table-stakes
  **importance:** Med
  **prevalence:** 1/1 checked (Planning Center). Arbor ties paid trips to consent (https://support.arbor-education.com/hc/en-us/articles/360008180414-Signing-my-child-up-for-a-Trip-on-the-Parent-Portal-or-the-Parent-App,
  docs, summary only).
  **seen-in:** Planning Center: https://help.planningcenter.com/en/139362-collect-payments-in-registrations.html (docs,
  opened: "Use partial payments to require a deposit").
  **effort:** M, because the payment checkout and the sign-up form both exist.
  **risk:** Med, because money is tied to capacity and waiting lists.
  **purpose-fit:**
  - Sweep on #347 and #348 (PROVEN there): the sign-up form, the moderation queue and the allergy, medical and consent
    fields exist, but nothing can switch `registrationEnabled` on, so nobody can reach them.
  - PROVEN in the working tree: `calendar/event-register.php` carries the allergy and medical fields (lines 134-138).
  - PROVEN: `payments/checkout.php` knows only the `giving` and `pledge` purposes.
  **confidence:** high
  **spec-seed:**
  - First, add the "registration open" switch to the event form (the sweep follow-up).
  - Then add an optional price and deposit to the event, and an `event` purpose in the checkout that confirms the
    place only when payment succeeds.
  - Depends on #497 for payment credentials.

- **id:** FG-009
  **feature:** Organisation-defined extra fields on people
  **what:** An administrator adds fields such as "Baptism date", "Dietary needs" or "Year group", chooses who can see
  them, and whether members can edit their own.
  **class:** table-stakes
  **importance:** Med
  **prevalence:** 2/2 checked (Planning Center, ChurchSuite).
  **seen-in:**
  - Planning Center: https://help.planningcenter.com/en/138571-manage-custom-fields.html (docs, opened).
  - ChurchSuite: https://support.churchsuite.com/article/39-creating-custom-fields (docs, summary only).
  **effort:** M
  **risk:** Med. Every new field is personal data, so it must reach the personal-data catalogue, the eraser and the
  data download (#479).
  **purpose-fit:**
  - PROVEN: `tblUsers` has fixed columns only (`full_schema.sql`, `tblUsers` block).
  - PROVEN: a search for "customfield", "custom_field", "profileField" or "tblUserField" in `web/_apps` and `web/_core`
    finds nothing.
  - The Forms app already has a safe field registry to copy (`web/_core/FormEngine.php:67-82`).
  **confidence:** high
  **spec-seed:**
  - Reuse FormEngine's field types.
  - Store one row per person per field, scoped to the site.
  - Visibility follows the directory's private, team, members or public levels.
  - Add the field table to the erasure catalogue in the same change.
  - Existing issue: none found.

- **id:** FG-010
  **feature:** Name badges and pick-up labels at children's check-in
  **what:** Checking a child in prints a name badge and a matching parent pick-up label carrying the security code.
  **class:** table-stakes (for customers running children's work)
  **importance:** Med
  **prevalence:** 2/2 checked (Breeze, ChurchSuite).
  **seen-in:**
  - Breeze: https://www.breezechms.com/lp/children-church-check-in (vendor product page, opened: "a printer set up to
    print name tags at check-in").
  - ChurchSuite: https://support.churchsuite.com/article/684-connecting-a-printer (docs, summary only: child name
    badges and parent pick-up badges on a Brother label printer).
  **effort:** M. Printing to a label printer from a browser is awkward; a page sized for printing is the practical
  start.
  **risk:** Med. Label printers vary widely by make.
  **purpose-fit:**
  - PROVEN: a search of `web/_apps/kids` for "label" or "print", excluding form labels, finds nothing.
  - Sweep on #298: the 6-digit badge codes and allergy warnings exist on screen only.
  **confidence:** high
  **spec-seed:**
  - After check-in, open a label-sized print page (child name, allergy flag, code) and a second label for the parent
    (code only).
  - No child data should appear on the parent's label.
  - Existing: the sweep's notes on #298.

- **id:** FG-011
  **feature:** Households: linking family members together
  **what:** People are grouped into a household (adults and children), so an update, a joint Gift Aid record or a
  child's pick-up can be managed once for the family.
  **class:** table-stakes
  **importance:** Med. It is central for churches and schools, but a large change to the data structure.
  **prevalence:** 2/2 checked (Planning Center, Breeze).
  **seen-in:**
  - Planning Center: https://www.planningcenter.com/blog/2017/02/households-activity-feeds-and-workflows-come-to-people-mobile-html
    (vendor blog, opened: "manage the members of a household").
  - Breeze: https://support.breezechms.com/hc/en-us/articles/360001160753-Using-Family-Roles (docs, summary only).
  **effort:** L. It touches the directory, the children's app, giving, erasure and the data download.
  **risk:** Med. Deleting someone's data must not remove or expose the rest of the family.
  **purpose-fit:**
  - PROVEN: no household or family table in `full_schema.sql`.
  - PROVEN: the only family link is `tblKidProfiles.parentUserID`, which allows one parent per child and is not a
    household.
  **confidence:** high for the code; low for the Breeze citation.
  **spec-seed:**
  - A household table with members and a role for each member (adult or child).
  - A child's record may point to its household instead of a single parent.
  - Directory visibility stays per person.
  - Worth doing before real customer data arrives: #487 calls this the "last cheap moment" to change the database
    structure.

- **id:** FG-012
  **feature:** Send Gift Aid claims straight to HMRC
  **what:** The treasurer submits the Gift Aid claim from inside the portal, instead of downloading a file and
  uploading it to HMRC by hand.
  **class:** table-stakes (UK)
  **importance:** Med. The download route works today, so this saves time rather than unblocking anything.
  **prevalence:** 2/2 checked (Beacon, ChurchSuite).
  **seen-in:**
  - Beacon: https://guide.beaconcrm.org/en/articles/5720187-gift-aid (docs, opened: "submit it directly to HMRC
    through Beacon").
  - ChurchSuite: https://support.churchsuite.com/article/119-reclaiming-gift-aid (docs, summary only).
  **effort:** L. It needs HMRC's Charities Online submission format and HMRC's recognition of the software.
  **risk:** High. Stored HMRC credentials, and claims are legal submissions.
  **purpose-fit:**
  - PROVEN: `web/_core/Giving.php:104` `buildHmrcCsv()` is the only HMRC route.
  - PROVEN: a search for "govtalk", "charities online" or an HMRC submission address in `web/_apps` and `web/_core`
    finds nothing.
  - PROVEN: no support for the Gift Aid Small Donations Scheme (a search for "gasds" or "small donation" finds
    nothing).
  **confidence:** high for the code.
  **spec-seed:** Leave until after alpha. First confirm that the existing export matches HMRC's current claim
  spreadsheet, a cheaper step.

- **id:** FG-013
  **feature:** Merge duplicate people
  **what:** When the same person exists twice, an administrator merges the two records and keeps all their history.
  **class:** nice-to-have
  **importance:** Low. Email addresses are unique per account here (PROVEN: `UNIQUE KEY emailAddress` on `tblUsers`),
  which limits how duplicates arise.
  **prevalence:** 1/1 checked (Planning Center).
  **seen-in:** Planning Center: https://help.planningcenter.com/en/138581-merge-duplicate-profiles.html (docs, opened).
  **effort:** M, because every table that points at a user has to be moved across.
  **risk:** Med, because a merge cannot be undone.
  **purpose-fit:** PROVEN: a search for "merge" in `web/_apps/admin/users` finds nothing. The personal-data catalogue
  already lists every table that holds a person's data, so it could drive the merge.
  **confidence:** high
  **spec-seed:** Drive it from `personal-data-catalogue.php`, the same list the eraser uses. Show a preview of what
  moves, and require typing a confirmation.

- **id:** FG-014
  **feature:** Questions that appear depending on an earlier answer, in Forms
  **what:** "Do you have allergies?" answered Yes shows a follow-up question; No hides it.
  **class:** nice-to-have
  **importance:** Low
  **prevalence:** 1/1 checked (Planning Center).
  **seen-in:** Planning Center: https://www.planningcenter.com/blog/2019/10/create-specialized-forms-with-conditional-fields
  (vendor blog, opened).
  **effort:** S-M
  **risk:** Low, as long as hidden answers are also thrown away when the form is saved, not only hidden on screen.
  **purpose-fit:** PROVEN: `FormEngine::FIELD_TYPES` (`FormEngine.php:67-82`) has 12 types, with no show-if setting
  and no file-upload type.
  **confidence:** high
  **spec-seed:** A "show only if field X equals option Y" setting. The server ignores answers to hidden questions.

- **id:** FG-015
  **feature:** Log volunteer hours
  **what:** Volunteers or their leaders record hours served, and the organisation reports totals per person and per
  month.
  **class:** nice-to-have. It matters more for the Charity preset.
  **importance:** Low
  **prevalence:** 1/1 checked (Beacon).
  **seen-in:** Beacon: https://guide.beaconcrm.org/en/articles/5720080-working-with-volunteers (docs, opened: "log the
  hours that your volunteers are working").
  **effort:** M
  **risk:** Low
  **purpose-fit:** PROVEN: a search for "volunteer hours", "hoursServed" or "hoursLogged" across `web/` finds nothing.
  INFERRED: rota slots and event jobs already hold start and end times, so a first version could total those.
  **confidence:** high
  **spec-seed:** Work the hours out from confirmed rota slots and event jobs, allow manual additions, and add a Reports
  Builder source.

- **id:** FG-016
  **feature:** Keep a mailing list in step with Mailchimp
  **what:** A group or list in the portal is copied automatically to a Mailchimp audience.
  **class:** nice-to-have
  **importance:** Low. The portal already sends newsletters itself.
  **prevalence:** 1/1 checked (ChurchSuite).
  **seen-in:** ChurchSuite: https://support.churchsuite.com/article/64-how-to-integrate-mailchimp (docs, summary only).
  **effort:** M
  **risk:** Low
  **purpose-fit:** PROVEN: `Newsletter.php:234-252` reserves a `mailchimp` provider, which quietly falls back to the
  internal sender (sweep on #269).
  **confidence:** low, because the citation could not be opened.
  **spec-seed:** Either wire the reserved option or remove it, so an administrator is not offered a choice that does
  nothing.

- **id:** FG-017
  **feature:** Group chat and direct messages
  **what:** Members of a small group talk between meetings inside the portal.
  **class:** differentiator
  **importance:** Low. Groups already use WhatsApp and similar apps.
  **prevalence:** 2/2 checked (Planning Center, Arbor).
  **seen-in:**
  - Planning Center: https://www.planningcenter.com/changelog/groups/new-enable-group-chat-from-the-church-center-app
    (vendor changelog, opened).
  - Arbor: https://arbor-education.com/blog-the-arbor-app-is-here/ (vendor blog, summary only: in-app messaging).
  **effort:** L
  **risk:** High. Instant delivery is hard on shared hosting with no long-running processes; it also needs moderation,
  safeguarding rules for any child involved, and erasure.
  **purpose-fit:** Sweep on #304 (PROVEN there): no message tables and no messaging app. The Small Groups class names
  #304 as its intended user.
  **confidence:** high
  **spec-seed:** If pursued, send notifications through the existing web push rather than true live chat. Existing
  issue: #304.

## Checked, and NOT gaps: the portal already has these under another name

These were found in the comparables and ruled out as gaps because the code already does them. Several are
**built but broken or unreachable**. That belongs to the issue sweep's proposals, not to this ledger.

- **Follow-up processes** (Planning Center Workflows, https://www.planningcenter.com/blog/2017/02/households-activity-feeds-and-workflows-come-to-people-mobile-html,
  opened; ChurchSuite Flows, https://support.churchsuite.com/article/154-flows, summary only). Here: the Visitors
  follow-up board, the Approvals workflow engine and Discipleship pathways.
  - Caveat, sweep on #258: visitors cannot be assigned to anyone until FG-001 exists.
- **Key dates** (ChurchSuite, https://support.churchsuite.com/article/31-stay-on-top-of-your-church-member-key-dates,
  summary only). Here: the Milestones app.
- **Room and resource booking** (ChurchSuite Bookings, summary only). Here: Resources and Venues (sweep on #263 and
  #429).
- **Rota swaps and reminders** (ChurchSuite, https://support.churchsuite.com/article/221-enabling-disabling-rota-swap-functionality,
  summary only). Here: `rota/swap` plus the reminder job (sweep on #256 and #439).
- **Staff sign-in with Microsoft or Google** (ChurchSuite, https://support.churchsuite.com/article/641-integrating-with-azure-active-directory,
  summary only). Here: Microsoft 365 and Google sign-in, plus passkeys (security keys or fingerprint sign-in).
- **Lists built from rules, and reports** (Planning Center Lists, https://www.planningcenter.com/blog/2016/06/custom-views-for-your-lists-html,
  summary only). Here: the Reports Builder with six sources (PROVEN: `ReportRegistry.php:93-234`).
  - Caveat: a report result cannot yet be used as a mailing list (part of FG-002).
- **Group sign-up with approval** (ChurchSuite, summary only). Here: `small-groups/join` with join requests, for
  signed-in members only. The public side is FG-004.
- **Consent requests to parents** (Arbor, https://support.arbor-education.com/hc/en-us/articles/203813172-Managing-Parental-and-Trip-Consents,
  opened). Here: partly, through the Forms app and the event sign-up consent fields. Sign-up is unreachable (FG-008).
- **Web access for other software and connections to other services** (ChurchSuite API and Zapier, summary only).
  Here: REST API version 1, and outgoing notifications to other systems signed to prove they came from the portal
  (sweep on #324).
- **One-off online giving, statements and pledges.** Here: Giving plus Payments (Stripe, PayPal).
  - Caveat, from the brief: this is inert until #497, because payment credentials are not decrypted.

## Out-of-scope (surfaced, NOT recommended)

- **A native iPhone and Android member app.** Seen in Planning Center (Church Center app,
  https://www.planningcenter.com/church-center, summary only) and Arbor (https://arbor-education.com/blog-the-arbor-app-is-here/,
  summary only).
  **Why excluded:** WebMS-Intra is installed by each customer from a download onto shared hosting (#499). It already
  installs as an app from the browser and sends notifications (sweep on #141 and #322). Store apps would need a
  separate codebase and app-store accounts for every customer.
- **Parents' evening booking, pupil behaviour and progress, school meals.** Seen in Arbor
  (https://support.arbor-education.com/hc/en-us/sections/12209476383517-Parent-Portal-and-App-Payments-School-Shop-Meals-Clubs-and-Trips,
  summary only).
  **Why excluded:** that is a school management system. PROVEN: the code has no pupil, class or year-group data, and
  the School preset only changes the name and artwork. A school customer would keep its own management system.
- **Text-to-give** (giving by sending a text message). Seen in Planning Center (https://help.planningcenter.com/en/138346-introduction-for-administrators.html,
  summary only).
  **Why excluded:** it relies on US-style short-code texting through Planning Center's own payment service. Nothing in
  the code leans toward it: the text-message sender only sends, and cannot receive replies (PROVEN: a search of
  `Sms.php` for "inbound" or "reply" finds nothing). UK customers give online or by Direct Debit (FG-005).
- **Automatic volunteer scheduling** (Planning Center, https://help.planningcenter.com/en/142868-schedule-your-teams.html,
  summary only).
  **Why excluded for now:** it is only worth having after FG-007 exists. Automatic crew and job assignment already
  exists for events (sweep on #349).

## Snapshot caveat

Competitor features as of 14 September 2026, from public vendor help pages. Marketing pages may overstate. ChurchSuite
and Breeze help pages could not be opened by the fetch tool, so those citations rest on search-engine summaries and are
marked low confidence. The skeptic and completeness checks were done by the same agent that did the research, not by
an independent one. Re-run to refresh, and have a separate reviewer check the top five gaps before any is built.
Nothing here is approved to build.
