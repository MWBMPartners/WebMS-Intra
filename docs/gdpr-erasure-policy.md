# GDPR Right-to-Erasure Policy

This document describes the data categories we hold, what happens to each on
an Article 17 erasure request, and the sealed-audit posture proving compliance.

## Categories

| Category | Tables | Treatment | Reason |
|---|---|---|---|
| **Authentication artefacts** | `tblSessions`, `tblTotpBackupCodes`, `tblWebauthnCredentials`, `tblZoomAccount`, `tblPaymentMethod` | **Delete** | No legitimate-interest retention |
| **App membership** | `tblUserSites`, `tblUserRoles`, `tblUserSmsPreference`, `tblNewsletterSubscription`, `tblUserTranslationPref` | **Delete** | Settings only — no archival value |
| **Tax declarations** (Gift Aid in the United Kingdom) | `tblGiftAidDeclaration` | **Kept, unchanged**, until the organisation's keep-until date. Its address, postcode, signature and notes are then emptied | The organisation's evidence for tax relief claimed on the donor's gifts. This row said "Delete" until 11 October 2026 |
| **Gifts that keep the donor's name** | `tblGivingEntry`, `tblGivingStatementLog`, the statement files, and the name and home address in `tblGivingKeptDonors` | **Kept with the donor's name** until the organisation's keep-until date, then the name, address and link are removed. Nothing is deleted | Tax and accounting rules in most countries. By default only gifts with a tax declaration, receipt or statement; an organisation may choose every gift. See "Giving records" below |
| **Every other gift, and counted envelopes** | `tblGivingEntry`, `tblCountEnvelopes` | **Anonymise** at once (the donor's number and any typed name are removed). One exception: an envelope in a count that is not yet closed keeps its giver when the gift it will become is one that keeps the donor's name; it loses it with the rest when the keep-until date passes | The amount, date and method are the organisation's accounts. The exception exists because the gift made from such an envelope would otherwise have no donor and drop out of the tax claim |
| **Records the person only worked on** | Visitors, salvation cards, tasks and rota slots given to them; follow-up notes, pastoral visits, payments, files, check-ins, room bookings and loans they made | **Kept**; only their account number is removed | The record is somebody else's, or the organisation's. Until 11 October 2026 the first four were deleted |
| **An invitation the person accepted** | `tblInvitation` | **Delete** | It holds their own email address. One they only sent is kept |
| **Other financial records** | `tblExpenseClaim`, `tblPayment` | **Anonymise** (null `userID`) | The organisation's accounts. (This row used to give one country's rule, "UK HMRC: 6-year retention required", as the reason for every customer.) |
| **User-generated content** | `tblAnnouncements`, `tblEvents`, `tblRecording`, `tblPrayerRequests` | **Anonymise** (null `userID`; for prayer requests, also blank `submitterName/Email/IP`) | Body retained for congregational continuity; authorship detached |
| **Attendance / engagement** | `tblAttendanceCheckIns` | **Anonymise** | Aggregate stats retained |
| **User row itself** | `tblUsers` | **Anonymise** (replace `fullName` with `[Deleted User]`, blank PII, set `isActive=0`) | FK integrity for historical attribution we want to keep |

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

## Adding a new PII-bearing table

Append an entry to `GdprEraser::catalogue()`:

```php
['table' => 'tblNewThing', 'userCol' => 'userID', 'action' => 'delete'],
// or
['table' => 'tblNewThing', 'userCol' => 'createdByID', 'action' => 'anonymise',
 'nullCols' => ['authorEmail'], 'reason' => 'why we keep the row'],
```

The dependents must precede `tblUsers` in the catalogue so its anonymisation
step runs last.
