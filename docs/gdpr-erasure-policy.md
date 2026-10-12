# GDPR Right-to-Erasure Policy

This document describes the data categories we hold, what happens to each on
an Article 17 erasure request, and the sealed-audit posture proving compliance.

## Categories

| Category | Tables | Treatment | Reason |
|---|---|---|---|
| **Authentication artefacts** | `tblSessions`, `tblTotpBackupCodes`, `tblWebauthnCredentials`, `tblZoomAccount`, `tblPaymentMethod` | **Delete** | No legitimate-interest retention |
| **App membership** | `tblUserSites`, `tblUserRoles`, `tblUserSmsPreference`, `tblNewsletterSubscription`, `tblUserTranslationPref` | **Delete** | Settings only — no archival value |
| **Gift Aid declarations** | `tblGiftAidDeclaration` | **Kept, unchanged** | The organisation's evidence for tax it has reclaimed on the donor's gifts. This row said "Delete" until 11 October 2026, which stopped being true that day. How long it is kept has not been confirmed with an accountant |
| **Records the person only worked on** | Visitors, salvation cards, tasks and rota slots given to them; follow-up notes, pastoral visits, payments, files, check-ins, room bookings and loans they made | **Kept**; only their account number is removed | The record is somebody else's, or the organisation's. Until 11 October 2026 the first four were deleted |
| **An invitation the person accepted** | `tblInvitation` | **Delete** | It holds their own email address. One they only sent is kept |
| **Financial records** | `tblExpenseClaim`, `tblGivingEntry`, `tblPayment` | **Anonymise** (null `userID`) | UK HMRC: 6-year retention required |
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
