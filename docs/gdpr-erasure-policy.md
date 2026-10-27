# GDPR Right-to-Erasure Policy

This document describes the data categories we hold, what happens to each on
an Article 17 erasure request, and the sealed-audit posture proving compliance.

## The list, and its four decisions

The full list of every table that holds something about a person is one file:
`web/_core/personal-data-catalogue.php`. The erasure works from it. Each table
in it carries one of four decisions:

| Decision | What happens to the row | Used for |
| --- | --- | --- |
| `erase` | Deleted | Information about the person themselves |
| `unlink` | Kept; the link to the person is removed | The organisation's own work, where the person only made or handled it |
| `retain` | Kept, with the reason and the period written beside it | Records the organisation has to keep |
| `not-personal` | Nothing | Tables that only look personal, such as head counts |

`tools/audit-checks/check_personal_data_coverage.py` fails when a table that
holds personal data has no decision.

## Examples

These are examples, not the list. Where this table and the file disagree, the
file is right. `tools/audit-checks/check_docs_table_refs.py` checks that every
table named in this document exists; it cannot check what is said about it.

| Category | Tables | Treatment | Reason |
| --- | --- | --- | --- |
| **Ways of signing in** | `tblLocalAccounts`, `tblLinkedAccounts`, `tblWebAuthnCredentials`, `tblTotpBackupCodes`, `tblTrustedDevices`, `tblPasswordResets` | **Delete** | About the person and nobody else. (This row used to name two tables that do not exist. Signed-in sessions are not in the database at all: PHP keeps them as files on the server.) |
| **The person's own connections and choices** | `tblZoomAccount`, `tblPaymentMethod`, `tblPushSubscriptions`, `tblUserSmsPreference`, `tblNewsletterSubscription`, `tblUserTranslationPref` | **Delete** | Theirs alone; nothing for the organisation to keep |
| **Membership** | `tblUserSites`, `tblUserRoles` | **Delete** | Which organisation somebody belongs to, and in what role, is about them |
| **Tax declarations** (Gift Aid in the United Kingdom) | `tblGiftAidDeclaration` | **Kept, unchanged**, until the organisation's keep-until date. Its address, postcode, signature and notes are then emptied | The organisation's evidence for tax relief claimed on the donor's gifts. This row said "Delete" until 11 October 2026 |
| **Gifts that keep the donor's name** | `tblGivingEntry`, `tblGivingStatementLog`, the statement files, and the name and home address in `tblGivingKeptDonors` | **Kept with the donor's name** until the organisation's keep-until date, then the name, address and link are removed. Nothing is deleted | Tax and accounting rules in most countries. By default only gifts with a tax declaration, receipt or statement; an organisation may choose every gift. See "Giving records" below |
| **Every other gift, and counted envelopes** | `tblGivingEntry`, `tblCountEnvelopes` | **Anonymise** at once (the donor's number and any typed name are removed). One exception: an envelope in a count that is not yet closed keeps its giver when the gift it will become is one that keeps the donor's name; it loses it with the rest when the keep-until date passes | The amount, date and method are the organisation's accounts. The exception exists because the gift made from such an envelope would otherwise have no donor and drop out of the tax claim |
| **Records the person only worked on** | Visitors, salvation cards, tasks and rota slots given to them; follow-up notes, pastoral visits, payments, files, check-ins, room bookings and loans they made | **Kept**; only their account number is removed | The record is somebody else's, or the organisation's. Until 11 October 2026 the first four were deleted |
| **An invitation the person accepted** | `tblInvitation` | **Delete** | It holds their own email address. One they only sent is kept |
| **Other financial records** | `tblExpenseClaims`, `tblPayment` | **Kept**; the link to the person is removed | The organisation's accounts. (This row used to give one country's rule, "UK HMRC: 6-year retention required", as the reason for every customer.) |
| **Things the person wrote or made** | `tblAnnouncements`, `tblEvents`, `tblRecording`, `tblPrayerRequests` | **Kept**; the link to the person is removed. On a prayer request the name, email address and network address they typed are emptied too | The content is the organisation's; who made it is not kept |
| **Attendance** | `tblEventAttendance`, `tblSmallGroupMeetingAttendance` | **Kept**; the link to the person is removed | Deleting the row would leave the register wrong for everybody else who was there |
| **The log of what people did** | `tblActivityLogs` | **Kept; everything that could identify the person is emptied.** The visitor's network address, browser description, request headers, session identifier and the stored copy of the session are all cleared, and the link to the person is removed | The organisation needs the record of what happened to run and repair the portal; who did it is not kept. **This row was missing until 27 October 2026**, which mattered because the activity log is the one erasure behaviour #626 changed: a record now has to NAME its person in `userID`, and that single column is what an erasure matches. A record made at a shared kiosk deliberately names nobody, so it is not reached — a kiosk session outlives the person at it. Records written before #626 are brought into line separately, in pieces, from the Upgrade page |
| **The account itself** | `tblUsers` | **Emptied, not deleted.** The name becomes `[Deleted User]`; email address, telephone, address, biography, map position, language, two-factor secret, calendar address and notification choices are cleared; the account is switched off and loses any administrator rights | Hundreds of records point at the account. Deleting the row would break or delete them |

## Workflow

1. User initiates from `/account/my-data` → `/account/erasure-request`, or from
   `/account/delete` ("Delete my account"). Both file a request; neither erases
   anything by itself. From "Delete my account" the typed phrase is the
   confirmation, so steps 2 and 3 are skipped. If the email in step 2 cannot be
   sent, an administrator can confirm the request on the person's behalf, and
   the audit trail records who did.
2. We send a confirmation email with a one-time token (24-hour expiry).
3. User clicks the link → request moves to `pending_review`.
4. Admin reviews at `/admin/erasure-requests` (one-month SLA tracked + colour-coded).
5. Admin clicks **Execute** → request status flips to `processing` (lock against
   double-execution), the `GdprEraser` walks the catalogue and writes per-table
   audit rows, then status → `completed`.
6. Per-request report at `/admin/erasure-requests/report?id=N` shows every action
   taken and verifies the **sealed audit chain**.

## Giving records

Each organisation sets its own rule at Admin, Giving records
(`/admin/settings/giving-records`):

- **which gifts keep the donor's name** (only those with a tax declaration,
  receipt or statement, or every gift);
- **for how many years**, counted from the end of the financial year of the
  donor's last kept gift;
- **its country**, which the portal uses only to SUGGEST a figure;
- **its financial year end**; and
- a **hold**, for while an audit or tax enquiry is open.

The figures the portal suggests are from research of mixed strength
(`docs/research/giving-retention/`, merged into
`web/_core/data/giving-retention-countries.json`). **None is legal fact.** The
page shows how strong each source is and asks an administrator to confirm the
figure with the organisation's own adviser.

Nothing is cleared before that confirmation, and nothing while a hold is on.
When the keep-until date has passed, the retention job removes the name, the
address, the link to the donor and the kept statement files together, and
writes a line to the original erasure's audit trail.

Four rules protect a person whose erasure stopped part way (added 12 October
2026, after two independent checks):

1. A kept name is cleared, and a donor is treated as erased, only when the
   account has really been emptied. The kept name is written near the start
   of an erasure and the account is emptied about half way through it; a run
   that stops in between leaves the person still a member.
2. The keep-until date is counted from the later of the date written on the
   kept record and the newest gift still linked to the donor there.
3. A request that is run again works the kept record out afresh, and never
   writes "[Deleted User]" over a real name. If the account was already
   emptied by the earlier run, the gifts are sorted by the wider of the rule
   recorded that day and the organisation's rule now: a later change to
   "which gifts" never takes the donor off a gift that either rule keeps,
   and only the retention job clears the kept name.
4. A request whose run has already removed or changed anything cannot be
   cancelled. Deleted records cannot be put back, and nothing records which
   gifts lost their donor. The request has to be run again to its end.

A statement the donor downloaded for themselves is kept, like the ones the
organisation issued, when every gift on it is a kept gift.

The seven settings can be changed only on the Giving records page. The general
settings editor (`/settings`) refuses them, because it has neither the un-tick
nor the second tick. The one exception is the installation-wide hold, which a
global administrator sets there; the page shows it apart from the
organisation's own hold.

## Sealed audit chain

Each row in `tblErasureAudit` stores
`chainHash = SHA-256(prev.chainHash ‖ action ‖ tableName ‖ recordKey ‖ details)`.

`GdprEraser::verifyAuditChain()` re-walks the chain and confirms every link.
Editing any row breaks the chain from that row onwards. Available to compliance
auditors as a JSON download.

## SLA tracking

- `tblErasureRequest.dueBy = requestedAt + 1 month`.
- Admin queue colour-codes red when overdue, amber when fewer than 7 days remain.
- Subject email + name snapshot taken at request time so the request survives the
  user-row anonymisation step.

## Adding a table that holds personal data

Add an entry to `web/_core/personal-data-catalogue.php` (`tblNewThing` stands
for the new table's name):

```php
'tblNewThing' => [
    'decision' => 'erase',               // or unlink, retain, not-personal
    'reason'   => 'why, in plain words', // shown to whoever reviews an erasure
    'columns'  => ['userID'],            // EVERY column that names a person
    // 'period' => 'how long, and why',  // for retain only
],
```

`check_personal_data_coverage.py` fails until the table has one.

Two things the entry does not do for you, both read from
`web/_core/GdprEraser.php`:

- A person column left out of `columns` is not looked at.
- If the column that names the person has a name the portal has not used
  before, add that name to `GdprEraser::LINK_COLUMNS` as well. The comment
  there says where in the list it goes, and why the place matters.

Where one decision does not fit a table, a hand-written instruction in
`GdprEraser::catalogue()` takes its place.
