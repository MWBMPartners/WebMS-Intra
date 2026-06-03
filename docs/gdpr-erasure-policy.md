# GDPR Right-to-Erasure Policy

This document describes the data categories we hold, what happens to each on
an Article 17 erasure request, and the sealed-audit posture proving compliance.

## Categories

| Category | Tables | Treatment | Reason |
|---|---|---|---|
| **Authentication artefacts** | `tblSessions`, `tblTotpBackupCodes`, `tblWebauthnCredentials`, `tblZoomAccount`, `tblPaymentMethod` | **Delete** | No legitimate-interest retention |
| **App membership** | `tblUserSites`, `tblUserRoles`, `tblUserSmsPreference`, `tblNewsletterSubscription`, `tblGiftAidDeclaration`, `tblUserTranslationPref` | **Delete** | Settings only — no archival value |
| **Financial records** | `tblExpenseClaim`, `tblGivingEntry`, `tblPayment` | **Anonymise** (null `userID`) | UK HMRC: 6-year retention required |
| **User-generated content** | `tblAnnouncements`, `tblEvents`, `tblRecording`, `tblPrayerRequests` | **Anonymise** (null `userID`; for prayer requests, also blank `submitterName/Email/IP`) | Body retained for congregational continuity; authorship detached |
| **Attendance / engagement** | `tblAttendanceCheckIns` | **Anonymise** | Aggregate stats retained |
| **User row itself** | `tblUsers` | **Anonymise** (replace `fullName` with `[Deleted User]`, blank PII, set `isActive=0`) | FK integrity for historical attribution we want to keep |

## Workflow

1. User initiates from `/account/my-data` → `/account/erasure-request`.
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
