# #479 data download — Stage 1: SURVEY of what exists today

Date: 13 September 2026. Branch: `claude/alpha-wip`. Survey only — nothing is designed here and no repository file was edited.

**How to read the marks.** PROVEN means I read it in the code or ran it on this machine. INFERRED means I worked it out from what I read but could not run it (there is no database and no DreamHost server on this machine).

**How the numbers were produced.** Three throw-away scripts in the session scratchpad (which is under `/tmp` and may vanish, so every result they produced is copied into this document): one loads `web/_core/personal-data-catalogue.php` with PHP and dumps it as JSON; one parses every `CREATE TABLE` block in `web/_sql/full_schema.sql` (209 tables parsed — matches the count in `.claude/CLAUDE.md`) and cross-references it against the catalogue, `GdprEraser::LINK_COLUMNS`, `GdprEraser::ACTOR_COLUMNS` and the tables `data-export.php` reads; one lists every column that is a foreign key to `tblUsers`. I also ran `php tools/gdpr-coverage-selftest.php` (13 passed, 0 failed) and `python3 tools/audit-checks/check_personal_data_coverage.py` (OK, 131 in the catalogue).

---

## Summary in six lines

1. The catalogue holds **131** tables: **55 erase, 70 unlink, 3 retain, 3 not-personal** (PROVEN). Its own section-heading comments say 85/33/5/3 = 126; those comments are stale and several tables sit under the wrong heading.
2. The download reads **20 distinct tables** through **21 query blocks** (PROVEN). Every one of the 20 is in the catalogue. It covers 10 of the 55 erase tables, 10 of the 70 unlink tables, 0 of the 3 retain tables. **108 personal-data tables are not in the download.**
3. Of the 20 it does read, only about 6 are read in full; the rest are partial — a column subset, a single link column where the table has several, or a hard cap (activity logs, newest 5,000 only, with nothing saying so).
4. **6 catalogue tables have no link to any account under any column name**; 4 more have a link the eraser's vocabulary does not recognise; and **11 tables outside the catalogue** have a foreign key to `tblUsers` under a column name the coverage check does not look for (`uploadedByID`, `preparedByID`, `paidByID`, …), so they pass the check while being unsorted.
5. The download is reached by any signed-in person for themselves only, on a plain GET, with no password re-check, no rate limit, no size limit beyond the 5,000-row cap, and an activity-log entry whose `userID` column is NULL because the logger is called without one.
6. Today's `SELECT *` on `tblUsers` exports `calendarToken` (a 64-character iCal feed credential); the "block credential-looking names unless integer" rule would have stopped that, and gets every real credential right, but over-blocks a few harmless date/time columns and drops `sessionDataSnapshot`, which the issue asked to be widened.

---

## 1. Re-count: what the catalogue says and what the download covers

### 1a. The catalogue

| Decision | Count (PROVEN, by loading the file with PHP) | The catalogue's own heading comment says |
| --- | --- | --- |
| erase | 55 | "85 tables" |
| unlink | 70 | "33 tables" |
| retain | 3 | "5 tables" |
| not-personal | 3 | "3 tables" |
| **Total** | **131** | 126 |

The heading comments were written before a batch of entries was changed from erase to unlink and are now wrong (PROVEN by comparing). Under the "ERASE" heading, 32 entries actually carry `'decision' => 'unlink'` (for example `tblAssetLoans`, `tblEventAttendance`, `tblGivingEntry`, `tblKidProfiles`, `tblUsers`). Under the "RETAIN" heading, `tblCareCase` and `tblExpenseClaims` carry `'unlink'`, so the real retain set is only **`tblCareVisit`, `tblDbsChecks`, `tblGivingStatementLog`**. Nothing reads the headings, so nothing is broken — but a person reviewing the file by eye is misled, and the count "4 kept for legal reasons" in the last #479 comment and in HANDOFF is now 3.

Every catalogue table exists in `full_schema.sql` (PROVEN — zero ghosts). Five catalogue entries name a column that is not on the table (PROVEN): `tblRecording` lists `createdByID` (real column: `uploadedByID`), `tblCareAccessLog` lists `userID` (real: `viewerID`), `tblAssetAudit` and `tblAssetScanLog` list `userID` (real: `actorUserID`), `tblAssetValueHistory` lists `createdByID` (real: `recordedByID`). All five are handled by hand-written eraser entries that use the right names, and the hand-written entry wins, so erasure is unaffected — but a download driven from the catalogue's `columns` list would query a column that does not exist for those five.

### 1b. The download today (`web/_apps/auth/account/data-export.php`)

21 calls to `$fetchUserRows(` (PROVEN by count). 20 distinct tables, because `tblSmallGroupMembers` is read twice (once by `userID`, once by `addedByID`). Two further tables are JOINed for context only, not matched on the person: `tblEvents` (gives `eventName`, `startDateTime` to the registrations block) and `tblSmallGroupMeetings` (gives `meetingDate`, `topic` to the attendance block).

Note the header comment in that file lists 17 tables and claims expense "line items, attachments metadata" are included. They are not: nothing in the file touches `tblExpenseClaimFiles` or any line-item table (PROVEN).

| Decision | In catalogue | In download | Missing |
| --- | --- | --- | --- |
| erase | 55 | 10 | **45** |
| unlink | 70 | 10 | **60** |
| retain | 3 | 0 | **3** |
| not-personal | 3 | 0 | 3 (nothing to download by definition) |
| **Total** | 131 | 20 | **108** personal + 3 not-personal |

### 1c. The 20 covered tables — full, or partial?

"Link" below means a column in `GdprEraser::LINK_COLUMNS`. A table is "partial by link" when the download matches on fewer of its link columns than it has; "partial by column" when it returns fewer columns than the catalogue calls personal or than the row holds. All PROVEN by reading the SQL in the file against the schema.

| Table | Decision | Matched on | Verdict |
| --- | --- | --- | --- |
| tblUsers | unlink | userID | Full row minus `totpSecret`. Over-includes `calendarToken` (VARCHAR(64) iCal feed credential), `avatarPath`, `displayPhoto`. |
| tblLocalAccounts | erase | userID | Partial by column (minor): drops `passwordHash` (right) and `lastLogin`. |
| tblLinkedAccounts | erase | userID | Partial by column (minor): drops `providerSub` (the provider's identifier for the person). |
| tblWebAuthnCredentials | erase | userID | Partial by column: drops `credentialID`, `publicKey`, `signCount`, `aaguid`. Right under the credential rule. |
| tblPasswordResets | erase | userID | Full apart from `tokenHash` (right). |
| tblActivityLogs | unlink | userID | **Partial, significant.** 5 of 12 columns; drops `requestHeaders`, `sessionID`, `userAgent`, `sessionDataSnapshot`, `siteID`, `claimID`. Capped at the newest **5,000** rows; nothing in the output says a cap was applied. #479's second comment asked for this to be widened. |
| tblExpenseClaims | unlink | userID | Full row (`SELECT *`, 11 columns). The claim's files (`tblExpenseClaimFiles`, via `claimID`) and the generated PDFs are not included despite the header comment. |
| tblPrayerRequests | unlink | submitterID | Full row (`SELECT *`). Includes `partnerNote` (written by a prayer partner, someone else), `assignedToUserID`, `moderatorID`, `submitterIP`. Partial by link: `assignedToUserID` is a person link that `LINK_COLUMNS` does not know, so a request assigned to the person is not found; `moderatorID` not matched either. |
| tblConsentLog | erase | userID | Partial by column (minor): drops `sessionID` (right, text session ID), `userAgent` (catalogue calls it personal), `siteID`. |
| tblTrustedDevices | erase | userID | Full apart from `tokenHash` (right). |
| tblVenueBookings | unlink | updatedByID only | **Partial by link**: `createdByID` not matched. 10 columns of the row. |
| tblVenueUsageTypeWindows | unlink | createdByID | Full enough (drops nothing personal). |
| tblVenueImportBatches | unlink | createdByID | Full apart from `fileHash` (right). |
| tblGiftAidDeclaration | erase | donorID | Partial by column (minor): drops `acceptedIP`, `signaturePath`, `notes` (catalogue calls `notes` personal). |
| tblReportDefinitions | unlink | createdByID only | Partial by link: `updatedByID` not matched. |
| tblFormResponses | erase | submitterID | Full apart from `submitterIP` (public channel only, so always NULL for a matched row). |
| tblEventRegistrations | erase | submittedByUserID only | Partial by link (`reviewedByID`, an actor, not matched — arguably right) and by column (drops internal moderation `notes`, `reviewedByID`, `reviewedAt`). |
| tblSmallGroupMembers | erase | userID AND addedByID (two blocks) | Full by link. The `addedByID` block returns rows about OTHER people the subject added (membershipID, groupID, role, status) — an actor-matched block already in the download. |
| tblSmallGroupMeetingAttendance | unlink | userID only | Partial by link: `markedByID` not matched. |
| tblSmallGroups | unlink | createdByID | Partial by column: returns 4 columns; catalogue lists `postcode`, `latitude`, `longitude`, `what3words` as the personal ones (they are the group's meeting place, often a member's home). |

Three of the 21 blocks are already actor-matched rows about other people or the organisation: `venueBookingsEdited`, `venueUsageWindowsCreated`, `venueImportBatches`, `smallGroupsCreated`, `smallGroupMembersAdded`, `reportDefinitions` (six blocks, in fact). So question A in the brief is already live in today's file, not hypothetical.

---

## 2. Every catalogue table the download misses

Columns are the REAL columns on the table (from `full_schema.sql`), not the catalogue's list. Marks: **†** = a real link column the catalogue's `columns` list does not mention; **‡** = the catalogue names a column that is not on the table. "About" = a link in `LINK_COLUMNS` that is NOT in `ACTOR_COLUMNS` (`userID`, `memberID`, `donorID`, `submitterID`, `recipientUserID`, `assignedToID`, `targetUserID`, `convertedUserID`, `leaderID`). "Actor" = a link in `ACTOR_COLUMNS`. "(unknown name)" = a column that plainly points at a person but is in neither list. Credential-like columns are shown in brackets so the design can see them; they should never be handed over. All PROVEN from the schema parse and the catalogue dump.

### 2a. ERASE — 45 tables missing

| Table | About | Actor | Personal columns the catalogue names | Other name / contact / free-text columns on the row |
| --- | --- | --- | --- | --- |
| tblAiUsage | userID | — | — | — |
| tblAnonymousCheckins | — | — | userAgent | headcount, source; (ipHash, salted). **No link at all.** |
| tblAssetKioskPins | userID | — | — | (pinHash) |
| tblAssetOrgs | — | — | contactName, contactEmail, contactPhone, notes | orgName, agreementRef. **No link at all** — a lending partner organisation's contact person. |
| tblAssetOwners | userID | — | notes | — |
| tblAttendanceCounts | — | — | sessionID (INT row number — not personal) | groupLabel, headcount. **No link at all**; arguably mis-catalogued as erase. |
| tblErasureRequest | userID | processedByID † | notes | subjectEmail, subjectName, reasonRetained; (confirmToken) |
| tblEventCoordinators | userID | grantedByID † | — | — |
| tblEventCrewMembers | userID | — | — | externalName |
| tblEventJobAssignments | userID | — | — | externalName |
| tblEventPeople | userID | — | — | externalName |
| tblEventRSVPs | userID | — | — | — |
| tblLeadershipAssignments | userID | createdByID, updatedByID | personName, personEmail, notes | — |
| tblLiveChatMessages | — | moderatedByID † | displayName | body, senderIP, sessionToken. **No about-link** (anonymous chat identity). |
| tblNewsletterRecipient | userID | — | emailAddress | (unsubToken) |
| tblNewsletterSubscription | userID | — | — | (unsubToken) |
| tblOffboarding | userID | offboardedByID †, rehiredByID † | — | — |
| tblPathwayEnrolments | userID | enrolledByID † | — | — |
| tblPathwayProgress | userID | markedByID †, revokedByID † | notes | — |
| tblPaymentMethod | userID | — | — | — |
| tblPledges | userID | — | — | — |
| tblProjectPledge | donorID | — | donorName, donorEmail | message |
| tblPushSubscriptions | userID | — | userAgent | channels; (endpoint — a capability URL, p256dhKey, authKey) |
| tblReadingPlanEnrollment | userID | — | — | — |
| tblRecordingPlay | userID | — | — | (ipHash) |
| tblRotaSlot | assignedToID | createdByID | notes | — |
| tblRotaSwapRequest | targetUserID | requestedByID † | — | requestMessage, responseMessage |
| tblSalvationCards | assignedToID | — | fullName, email, phone, address, notes | prayerRequest, decision. Note: `assignedToID` is the follow-up worker, not the person the card is about — the card's subject has no account link. |
| tblServicePlanItem | — | presenterID † | notes | presenterText, title. **No about-link.** |
| tblServicePlanItems | — | — | slideNotes | slideBody. **No link at all.** |
| tblSmsMessage | recipientUserID | — | recipientNumber | body |
| tblTasks | assignedToID | createdByID | — | description |
| tblTotpBackupCodes | userID | — | — | (codeHash) |
| tblUserDepts | userID | — | — | isDeptSecretary (TINYINT) |
| tblUserGroups | userID | — | — | — |
| tblUserMilestone | userID | — | — | — |
| tblUserRoles | userID | — | — | — |
| tblUserSites | userID | — | — | — |
| tblUserSmsPreference | userID | — | — | phoneNumber |
| tblUserTours | userID | — | — | — |
| tblUserTranslationPref | userID | — | — | — |
| tblCareAccessLog | (unknown name) viewerID; catalogue says userID ‡ | — | — | caseID → a pastoral case. The hand-written eraser entry uses `viewerID` correctly. |
| tblVenueImportRows | — | — | rawNotes | sheetName, stateNote, rawHours, rawTimes, rawStatus. **No link at all** (links to a batch, which has createdByID). |
| tblVisitor | assignedToID, convertedUserID | createdByID | fullName, email, phone, notes | source, status. Three different people can be linked: the worker assigned, the account the visitor later became, and the volunteer who typed it in. |
| tblZoomAccount | userID | — | — | zoomAccountEmail; (refreshTokenEnc, accessTokenEnc) |

### 2b. UNLINK — 60 tables missing

| Table | About | Actor | Personal columns the catalogue names | Other name / contact / free-text columns on the row |
| --- | --- | --- | --- | --- |
| tblAssetEventAssignments | — | assignedByID | notes | — |
| tblAssetIdentifiers | — | createdByID | notes | verifyNote |
| tblAssetLicenseAssignments | userID | linkedByID †, releasedByID † | notes | deviceName |
| tblAssetLoans | — | counterpartyUserID, approvedByID †, requestedByID † | notes | counterpartyName, **counterpartyContact** (VARCHAR(255)), conditionOutNotes, conditionInNotes, declineReason |
| tblAssetStocktakeItems | — | scannedByID † | notes | — |
| tblAssetStocktakes | — | startedByID, closedByID † | notes | — |
| tblAttendanceSessions | — | createdByID, updatedByID | sessionID (INT), notes | — |
| tblCountSessions | — | counter1ID †, counter2ID †, createdByID, closedByID † | notes | — |
| tblDecisionMoments | — | recordedByID | notes | — |
| tblEventAttendance | userID | markedByID † | notes | walkinName |
| tblEventOccurrenceOverrides | — | createdByID | notes | overrideName |
| tblEventRSVPInvites | — | createdByID | email, displayName | (token). The invitee has no account link. |
| tblExpenseClaimApprovals | userID (means the APPROVER — see the catalogue's warning) | — | — | — |
| tblGivingEntry | donorID | recordedByID † | donorName, notes | — |
| tblInvitation | — | createdByID, acceptedByID †, revokedByID † | email | welcomeMessage; (tokenHash) |
| tblKidProfiles | — | parentUserID | fullName, dateOfBirth, allergies, medicalNotes | **pickupAuthorisedNames** (VARCHAR(500), "Comma-separated names allowed to collect"), photoConsent |
| tblNoticeboardUploads | — | createdByID | storedName (a file on disk) | mimeType, fileSize |
| tblPayment | userID | — | — | providerRef; (idempotencyKey) |
| tblResourceBooking | — | bookedByID †, approvedByID | notes | — |
| tblServicePlans | — | createdByID | notes | name; (displayToken) |
| tblSmallGroupMeetings | — | recordedByID | notes | topic |
| tblRecording | (unknown name) uploadedByID; catalogue says createdByID ‡ | presenterID † | — | presenterText, filePath, thumbnailPath |
| tblAssetAudit | (unknown name) actorUserID; catalogue says userID ‡ | — | — | (ipHash), apiKeyID |
| tblAssetScanLog | (unknown name) actorUserID; catalogue says userID ‡ | — | — | (ipHash, userAgentHash) |
| tblAssetValueHistory | — | recordedByID †; catalogue says createdByID ‡ | — | — |
| tblVenueAgreements | — | createdByID | notes | — |
| tblVenueInvoicePayments | — | recordedByID | notes | — |
| tblVenueInvoices | — | createdByID | notes | description, fileName, filePath |
| tblVenues | — | createdByID | postcode, latitude, longitude, what3words, caretakerName, caretakerPhone, notes | venueName, addressLine1, addressLine2. The caretaker has no account link. |
| tblAnnouncements | — | createdByID, updatedByID | — | body |
| tblApiKeys | — | createdByID, revokedByID † | — | name, lastUsedIP; (keyHash, keyPrefix) |
| tblAssetKioskTokens | — | createdByID | — | (token) |
| tblAssetMaintenance | — | createdByID; plus performedByUserID (unknown name, FK to tblUsers) | — | performedByName |
| tblAssets | — | createdByID | — | name, description, serialNumber, insurerName, insurancePolicyNumber; (licenseKey, publicToken) |
| tblAuditTrail | userID | — | ipAddress | tableName, fieldName, oldValue, newValue, changeSet — before/after values of records the person changed, which may be about other people |
| tblErrors | userID | resolvedByID (unknown name) | visitorIP, userAgent, requestHeaders | requestURL, errorDetail (may include a backtrace with request data) |
| tblEventHubResources | — | createdByID | — | body |
| tblEventHubVideos | — | createdByID | — | — |
| tblEvents | — | createdByID, updatedByID, moderatedByID †; plus submittedByID, statusChangedByID (unknown names) | — | eventName, description, locationName, locationAddress, locationPhone, locationEmail, hostOrgName, submitterName, submitterEmail, moderationNote |
| tblExternalFeeds | — | createdByID | — | name, url |
| tblForms | — | createdByID | — | description; (publicToken) |
| tblLivePrompts | — | createdByID | — | body |
| tblLivestreamSessions | — | — | sessionID (INT), userAgent | ipCountry; (sessionToken). **No link at all** (anonymous viewer). |
| tblNewsletter | — | createdByID | — | — |
| tblNoticeboardPosters | — | createdByID, updatedByID | — | mediaUrl, canvaUrl, thumbUrl |
| tblPathways | — | createdByID | — | name, description |
| tblPhoto | — | uploadedByUserID, moderatedByID † | — | originalFilename, caption, filePath, rejectionReason |
| tblPhotoAlbum | — | createdByID | — | name, description |
| tblPledgeCampaigns | — | createdByID | — | name, description |
| tblProject | — | createdByID | — | description, coverImagePath |
| tblReadingPlan | — | createdByID | — | name, description |
| tblRecordingNote | — | createdByID | — | body, documentPath |
| tblServicePlanMessages | — | createdByID | — | body |
| tblServicePlanState | — | updatedByID | — | — |
| tblSongs | — | createdByID | — | tuneName; (defaultKey — a musical key, not a credential) |
| tblVenueBookingGroups | — | createdByID | — | — |
| tblWebhooks | — | createdByID | — | name, targetUrl; (signingSecret) |
| tblWorkflowInstances | — | startedByID | — | tableName |
| tblZoomMeeting | — | createdByID | — | joinUrl, startUrl, recordingUrl |
| tblCareCase | (unknown name) personUserID — the case SUBJECT | openedByID † | personName | summary, category. The hand-written eraser entries handle both columns. |

### 2c. RETAIN — 3 tables missing (to be LISTED, not handed over, per the owner's decision)

| Table | About | Actor | What the row holds |
| --- | --- | --- | --- |
| tblCareVisit | — (reaches the subject via caseID → tblCareCase.personUserID) | visitedByID, followUpAssignedToID (both unknown names, both FKs to tblUsers) | kind, visitedAt, **notes** (TEXT, pastoral), followUpAt |
| tblDbsChecks | userID | recordedByID † | dbsType, referenceNumber, issuedDate, expiresAt, status, notes |
| tblGivingStatementLog | donorID | createdByID | periodKey, fromDate, toDate, totalPence, giftAidPence, pdfPath, emailedAt, emailedTo, errorMsg |

### 2d. Not-personal — 3 (nothing to download)

tblAssetLocations, tblGeocodeCache, tblResource. No link columns; map positions of things.

### 2e. Link columns the catalogue does not mention (29 tables)

PROVEN. Wherever the catalogue-driven eraser or a future catalogue-driven download relies on the catalogue's `columns` list, these links are invisible: `tblAssetLicenseAssignments` (linkedByID, releasedByID), `tblAssetLoans` (approvedByID, requestedByID), `tblAssetStocktakeItems` (scannedByID), `tblAssetStocktakes` (closedByID), `tblCountSessions` (counter1ID, counter2ID, closedByID), `tblErasureRequest` (processedByID), `tblEventAttendance` (markedByID), `tblEventCoordinators` (grantedByID), `tblEventRegistrations` (reviewedByID), `tblGivingEntry` (recordedByID), `tblInvitation` (acceptedByID, revokedByID), `tblLiveChatMessages` (moderatedByID), `tblOffboarding` (offboardedByID, rehiredByID), `tblPathwayEnrolments` (enrolledByID), `tblPathwayProgress` (markedByID, revokedByID), `tblPrayerRequests` (moderatorID), `tblResourceBooking` (bookedByID), `tblRotaSwapRequest` (requestedByID), `tblServicePlanItem` (presenterID), `tblSmallGroupMeetingAttendance` (markedByID), `tblSmallGroupMembers` (addedByID), `tblRecording` (presenterID), `tblAssetValueHistory` (recordedByID), `tblApiKeys` (revokedByID), `tblEvents` (moderatedByID), `tblPhoto` (moderatedByID), `tblCareCase` (openedByID), `tblDbsChecks` (recordedByID).

### 2f. Person-link columns spelled in ways `LINK_COLUMNS` does not know

PROVEN by scanning the schema. **Columns with a real FOREIGN KEY to `tblUsers` whose name is NOT in `LINK_COLUMNS`:** `performedByUserID` (tblAssetMaintenance), `uploadedByID` (tblAssetResources, tblDocuments, tblRecording, tblVenueAgreementFiles), `actorUserID` (tblAssetScanLog; also tblAssetAudit without an FK), `importedByID` (tblBankImports), `personUserID` (tblCareCase), `visitedByID` and `followUpAssignedToID` (tblCareVisit), `operatorID` (tblCcliUsage), `sentByID` (tblEventBroadcasts), `postedByID` (tblProjectUpdate), `preparedByID` (tblServicePlan — the run-sheet, singular), `contactedByID` (tblVisitorContact), `actedByID` (tblWorkflowActions). Without an FK but plainly a person: `assignedToUserID` (tblPrayerRequests), `checkedInByID`/`checkedOutByID` (tblKidCheckins), `viewerID` (tblCareAccessLog), `paidByID` (tblExpenseClaimPayments), `matchedByID` (tblBankTxns), `resolvedByID` (tblErrors), `submittedByID`/`statusChangedByID` (tblEvents), `giverID` (tblCountEnvelopes).

**Eleven of these tables are not in the catalogue at all** — tblAssetResources, tblBankImports, tblCcliUsage, tblDocuments, tblEventBroadcasts, tblProjectUpdate, tblServicePlan, tblVenueAgreementFiles, tblVisitorContact, tblWorkflowActions, tblExpenseClaimPayments — and the coverage check does not flag them, because its `PERSONAL_COLUMNS` regular expression (PROVEN, `check_personal_data_coverage.py` lines 86-112) lists only 15 link names, fewer than `LINK_COLUMNS`' 41, and none of the spellings above. So the check's "OK — 131 in the catalogue" is true of the names it knows, not of every table linked to a person. This is the same "checks only cover what they read" shape recorded in memory.

---

## 3. Tables with no account link at all, and what each holds

Two honest answers, because the code has two vocabularies.

**What the self-test reports today** (PROVEN, from running `php tools/gdpr-coverage-selftest.php`; it looks only at `erase` tables and only at the catalogue's `columns` list): `tblAnonymousCheckins`, `tblAssetOrgs`, `tblAttendanceCounts`, `tblLiveChatMessages`, `tblServicePlanItem`, `tblServicePlanItems`, `tblVenueImportRows` — 7 tables.

**What the real schema says** (PROVEN, every column on every catalogue table checked against `LINK_COLUMNS`):

| Table | Decision | Personal data on the row | Note |
| --- | --- | --- | --- |
| tblAnonymousCheckins | erase | `userAgent`; `ipHash` (salted, not raw) | Anonymous event check-ins; a headcount with a browser string. |
| tblAssetOrgs | erase | `contactName`, `contactEmail`, `contactPhone`, `notes` | The contact person at a partner organisation that lends or borrows kit. |
| tblAttendanceCounts | erase | none personal (`sessionID` is an INT row number) | Aggregate headcounts by group label. Catalogued as erase because of the `sessionID` name pattern. |
| tblServicePlanItems | erase | `slideNotes` (operator-only), `slideBody` | Worship slides. |
| tblVenueImportRows | erase | `rawNotes`, plus raw cell text | Staged rows from a spreadsheet import; links to a batch that has `createdByID`. |
| tblLivestreamSessions | unlink | `userAgent`, `ipCountry`, `sessionToken` | Anonymous livestream viewer sessions. |
| tblLiveChatMessages | erase | `displayName`, `body`, `senderIP` | Only `moderatedByID` (an actor); the chatter is anonymous. |
| tblServicePlanItem | erase | `notes`, `presenterText` | Only `presenterID` (an actor). |

Plus four that DO have a person link, but under a name `LINK_COLUMNS` does not recognise (so a generic matcher would miss them): **tblCareAccessLog** (`viewerID`), **tblAssetAudit** and **tblAssetScanLog** (`actorUserID`), **tblCareVisit** (`visitedByID`, `followUpAssignedToID`). The hand-written eraser handles the first three.

Outside the catalogue, tables holding personal data with no link (or an unknown-name link), all PROVEN from the schema: `tblAssetFoundReports` (`reporterName`, `reporterContact`, `message`, salted `ipHash` — public lost-and-found form); `tblKidCheckins` (`pickupName`, `badgeCode`, `checkedInByID`/`checkedOutByID`; the child link is `childID`); `tblCountEnvelopes` (`giverID`, `giverName`, amount — named offering envelopes); `tblEmailLog` (`toRecipients`, `fromAddress`, `subject` — the eraser scrubs it with a bespoke substring replace); `tblEventMaterials` (files, no person link); `tblExpenseClaimFiles` (files, linked via `claimID`); and the eleven FK-linked tables in 2f.

---

## 4. Free text that can hold a name or contact details with no link column

Scan of every table for text columns whose name says name/email/phone/mobile/contact/address/recipient/number (PROVEN; `fileName`/`groupName`/etc. excluded by hand). Grouped by what the catalogue already knows.

**a) In the catalogue, and the catalogue already names the column:** tblUsers (fullName, emailAddress, phoneNumber, displayPhone, displayAddress), tblLeadershipAssignments (personName, personEmail), tblCareCase (personName), tblVisitor (fullName, email, phone), tblInvitation (email), tblNewsletterRecipient (emailAddress), tblGivingEntry (donorName), tblGiftAidDeclaration (address), tblSmsMessage (recipientNumber), tblProjectPledge (donorName, donorEmail), tblEventRSVPInvites (email, displayName), tblEventRegistrations (fullName, parentName, parentPhone, parentEmail, emergencyContactName, emergencyContactPhone), tblSalvationCards (fullName, email, phone, address), tblKidProfiles (fullName), tblLiveChatMessages (displayName), tblAssetOrgs (contactName, contactEmail, contactPhone), tblVenues (caretakerName, caretakerPhone).

**b) In the catalogue, but the column is NOT in the catalogue's list** — a catalogue-driven download would not know these are personal: tblLocalAccounts.username; tblLinkedAccounts.providerEmail; **tblEvents.submitterName, submitterEmail, locationPhone, locationEmail, locationAddress** (public event submissions); tblEventPeople / tblEventCrewMembers / tblEventJobAssignments.externalName (a non-member named on an event); tblAuditTrail.ipAddress and tblConsentLog.ipAddress (listed under a different heading); tblPrayerRequests.submitterName, submitterEmail; tblZoomAccount.zoomAccountEmail; tblGivingStatementLog.emailedTo; tblUserSmsPreference.phoneNumber; tblErasureRequest.subjectEmail, subjectName; tblEventAttendance.walkinName; tblDbsChecks.referenceNumber; **tblKidProfiles.pickupAuthorisedNames**; **tblAssetLoans.counterpartyName, counterpartyContact**; tblAssetMaintenance.performedByName; tblAssets.serialNumber, insurerName, insurancePolicyNumber.

**c) Not in the catalogue at all:** tblEmailLog.fromAddress, toRecipients; tblKidCheckins.pickupName; tblCountEnvelopes.giverName; tblAssetFoundReports.reporterName, reporterContact.

**d) Free-text note/body/description columns:** 85 columns across 82 tables (PROVEN count; names `notes`, `note`, `rawNotes`, `slideNotes`, `partnerNote`, `medicalNotes`, `description`, `body`, `message`, `summary`). Any of them can name somebody. Two are structured blobs that routinely carry personal data: `tblFormResponses.answersJson` (whatever the form asked) and `tblReportDefinitions.definition` (an administrator may have typed a name into a filter value — documented as a residual risk in GdprEraser). `tblActivityLogs.sessionDataSnapshot` and `requestHeaders` are JSON snapshots of the session and headers.

**The two the brief names.** `tblAssetLoans.counterpartyContact` is VARCHAR(255) with no comment; `counterpartyName` beside it is "Free-text name when counterpartyType=other"; `AssetRegister.php` accepts the contact regardless of whether the borrower is an account holder (per #492 item 2, not re-verified here). `tblKidProfiles.pickupAuthorisedNames` is VARCHAR(500) "Comma-separated names allowed to collect" — so its format IS known (comma-separated), which #492 item 2 said was the missing piece.

**Can they be found?** Only by searching text for the person's known values (name, email, phone). The codebase already has one such search: `GdprEraser::eraseEmailLogRecipients()` does `REPLACE(toRecipients, ?, '[erased]') WHERE toRecipients LIKE ?` on the captured email address (PROVEN, lines 1007-1031). Nothing in the download does any text search today (PROVEN). INFERRED limits of a text search: it finds only exact values held on `tblUsers` at the time (a nickname, an old address, a spelling variant, or a second phone number is missed), and a common name over-matches other people's rows — which is precisely why `data-export.php` refuses to match `tblSalvationCards` by name or email (its own comment, lines 237-244).

---

## 5. Credential-looking columns, their real types, and the "block by name, allow integers" rule

Pattern used: hash | secret | token | password | passwd | key | salt | cipher | signature | nonce | credential | otp | session, case-insensitive, anywhere in the name. 80 columns match across the whole schema (PROVEN). The rule as written in the brief: leave out unless the database type is an integer.

**Real secrets the rule correctly leaves out (all text types):** tblUsers.totpSecret VARCHAR(255), tblUsers.calendarToken VARCHAR(64), tblLocalAccounts.passwordHash VARCHAR(255), tblPasswordResets.tokenHash VARCHAR(255), tblWebAuthnCredentials.credentialID TEXT and publicKey TEXT, tblTotpBackupCodes.codeHash VARCHAR(255), tblTrustedDevices.tokenHash CHAR(64), tblInvitation.tokenHash CHAR(64), tblZoomAccount.refreshTokenEnc TEXT and accessTokenEnc TEXT, tblNewsletterRecipient.unsubToken CHAR(40), tblNewsletterSubscription.unsubToken CHAR(40), tblErasureRequest.confirmToken CHAR(64), tblPushSubscriptions.p256dhKey and authKey VARCHAR(255), tblWebhooks.signingSecret VARCHAR(255), tblEventRSVPInvites.token VARCHAR(64), tblApiKeys.keyHash CHAR(64), tblAssetKioskTokens.token CHAR(32), tblAssetKioskPins.pinHash VARCHAR(255), tblAssets.licenseKey VARCHAR(500), tblForms/tblAssets/tblServicePlan.publicToken, tblServicePlans.displayToken CHAR(64), tblLivestreamSessions/tblLiveChatMessages/tblLiveRateLimits.sessionToken, tblActivityLogs.sessionID VARCHAR(255), tblConsentLog.sessionID VARCHAR(255), tblPayment.idempotencyKey VARCHAR(80).

**Integers the rule correctly keeps:** tblUsers.totpEnabled TINYINT(1), tblUserDepts.isDeptSecretary TINYINT(1), tblAttendanceSessions.sessionID INT, tblAttendanceCounts.sessionID INT, tblLivestreamSessions.sessionID INT, tblCountSessions.countSessionID INT, tblCountEnvelopes.countSessionID INT, tblBankTxns.matchedCountSessionID INT, tblSmallGroupMeetings.attendanceSessionID INT, tblAssetKioskTokens.tokenID INT, tblApiKeys.keyID INT, tblAuditTrail.apiKeyID INT, tblAssetAudit.apiKeyID INT, tblAiUsage.inputTokens INT and outputTokens INT. Every example the brief gave checks out.

**Where the rule is wrong or needs a decision** (PROVEN types, INFERRED consequence):

| Column | Type | What the rule does | Assessment |
| --- | --- | --- | --- |
| tblActivityLogs.sessionDataSnapshot | TEXT | drops it ("session") | **Wrong direction.** It is a snapshot of the person's session — exactly what #479's second comment asked to be exported. Logger strips `csrf_token`, `oauth_state`, `oauth_nonce` before storing (PROVEN, Logger.php:57). A decision is needed: hand it over, or list it. |
| tblAttendanceSessions.sessionDate, sessionTime | DATE, TIME | drops them ("session", not integer) | Harmless dates dropped. The rule should say "text types are blocked", not "only integers are allowed". Same for tblZoomAccount.accessTokenExpiresAt DATETIME. |
| tblSongs.defaultKey | VARCHAR(10) | drops it | A musical key. Harmless, but shows "key" over-reaches. |
| tblGivingStatementLog.periodKey, tblReportDefinitions.sourceKey, tblFormFields.fieldKey, tblBankImports.bankKey, tblErasureAudit.recordKey | VARCHAR | drops them | Harmless labels dropped. Safe direction. |
| tblApiKeys.keyPrefix | VARCHAR(12) | drops it | Designed to be shown ("visible prefix for admin identification"). Safe direction. |
| tblGiftAidDeclaration.signaturePath | VARCHAR(255) | drops it ("signature") | A path to a signature image — the person's own signature. Dropping the PATH is right; the FILE is theirs (but no code writes this column — see section 7). |
| tblSites.siteKey, tblSettings.settingKey, tblRoutes.routeKey, tblRoles.roleKey, tblWorkflows.workflowKey, tblTours.tourKey, tblEmailTemplates.templateKey/availableTokens, tblHymnLookupCache.queryHash, tblGeocodeCache.queryHash, tblWebhookDeliveries.payloadHash, tblBankImports.fileHash, tblVenueImportBatches.fileHash | text | drops them | Not personal tables; irrelevant to a per-person download. |
| ipHash / userAgentHash columns (tblRecordingPlay, tblAnonymousCheckins, tblAssetFoundReports, tblAssetAudit, tblAssetScanLog) | CHAR(64) | drops them | Salted hashes; dropping is fine, and they are not a credential either. |

**Credential-like columns the name pattern MISSES** (PROVEN they exist; INFERRED they matter): `tblPushSubscriptions.endpoint` VARCHAR(500) — the push endpoint URL, which together with the two keys lets anyone send notifications to that browser; `tblKidCheckins.badgeCode` CHAR(6) — the safeguarding collection code (table not in the catalogue); `tblLinkedAccounts.providerSub` — an identifier, not a secret, but not something to publish.

**What today's file does:** the `tblUsers` block strips only `totpSecret`, so `calendarToken` (VARCHAR(64), the iCal feed token) IS in every download produced today (PROVEN, data-export.php lines 102-105 against the schema). The other blocks hand-pick columns and leave the hashes out.

---

## 6. How `data-export.php` works today, end to end

All PROVEN from the file, the schema seed, Router.php, Auth.php and Logger.php unless marked.

- **Address.** `/account/data-export` → `auth/account/data-export.php`, seeded with `isProtected = 1` (full_schema.sql:3135; originally migration 048). Linked from the account page's "Download my data (JSON)" button (`auth/account/index.php:822`), from `/account/delete`, from `/privacy`, and from two help pages. A second, different JSON download exists at `/account/my-data?download=1` — that one is a count-per-table inventory built by `GdprEraser::inventory()`, not the data itself.
- **Who can reach it.** Any signed-in account, for itself only: `Auth::ensureSession(); Auth::requireLogin();` then `$userId` comes from `App::user()`. There is no administrator path and no impersonation facility anywhere in `_core` or `_apps/admin` (PROVEN by grep — the only "impersonate" hits are the Google mail delegation). Nobody can produce a download for somebody else.
- **The router's own guard.** `Router.php:83` does `if ($route['isProtected'] === '1')` on a value fetched with `get_result()->fetch_assoc()` from a `TINYINT(1)` column. INFERRED: under mysqlnd that value is the integer `1`, so the strict string comparison is never true and the router never enforces login itself — the same shape Codex found in `Gatekeeper.php:143` on 11 September. It does not matter for this page (it calls `requireLogin()` itself, as do 430 of the 635 files under `_apps/`), but it belongs in the sweep.
- **Proof of identity.** None beyond the session cookie. No password re-entry, no second factor, no CSRF (it is a GET with no form). For comparison, the account-delete flow (`delete-confirm.php`) is a POST with CSRF plus a typed phrase "DELETE MY ACCOUNT" — not the password either. The only places the code re-checks a password are sign-in (`Auth.php:870`) and change-password (`change-password.php:82`). No "recent authentication" facility exists (PROVEN by grep for reauth / recentAuth / lastAuthAt / step-up).
- **Rate limiting.** None on this page (no `RateLimiter::` call). The facility exists — `RateLimiter::tooMany($bucket, $max, $window)`, `recordHit()`, `retryAfter()` — and is used by the TOTP verify page.
- **Logging.** `Logger::activity('DataExport', 'User downloaded their data export');` — written to `tblActivityLogs` with headers, session ID, IP, user agent and a session snapshot. **The `userID` column of that row is NULL**, because `Logger::activity()` takes `?int $userId = null` as its third argument and binds it directly with no fallback to the session (PROVEN, Logger.php:48-86). The person is only recoverable from the session snapshot. Nothing is written to `tblAuditTrail`.
- **Scope across organisations.** No query has `siteID` in its WHERE clause, so a person's rows from every site they belong to come back (PROVEN). The payload records `Site::id()` — the CURRENT site only — as `siteID`, which is misleading for a multi-site member.
- **Failure mode.** `$fetchUserRows` returns `[]` when `prepare()` fails (lines 72-75). A table that does not exist (app not installed) or a mistyped column produces an empty list that looks identical to "no rows". Nothing is logged. This is the "quietly leave things out" shape the brief warns about, already present.
- **Size.** Unbounded except `LIMIT 5000` on activity logs. Every row of every block is held in PHP arrays, then serialised in one `json_encode(... JSON_PRETTY_PRINT ...)` and echoed. No output buffering is started by the entry point, bootstrap, router or header (PROVEN by grep), no `set_time_limit()`, no streaming. INFERRED: peak memory is roughly the row data several times over (arrays plus the pretty-printed string); with the LONGTEXT columns excluded, a long-standing member's export is probably a few megabytes, but nothing bounds it.
- **How it is sent.** `Content-Type: application/json; charset=utf-8`, `Content-Disposition: attachment; filename="webms-intra-data-export-{userID}-{Ymd-His}.json"`, `Cache-Control: no-store`, one JSON document, `exportVersion` `'1'`, keys `exportedAt`, `siteID`, `subject{userID,emailAddress}`, `data{…21 blocks…}`. No schema file exists for it (PROVEN: no `*.schema.json` mentions it).
- **History.** Last five commits touching the file: 9c77216 (#479 registrations), 18e70b6 (#156 reports), 3403555 (#153 forms), 207c9fb (#150 small groups), 2f4c75a (#456 Gift Aid) — i.e. each new app added a hand-written block, which is the drift the brief describes.

---

## 7. Uploaded files that belong to a person

Files live under `PORTAL_ROOT/_uploads/` (outside the web root; gitignored at `.gitignore:32`; server-managed). Folder names used by the code (PROVEN by grep): `assets` (+ `assets/labels`), `venues` (+ `venues/agreements`), `giving` (`giving/statements`), `documents`, `noticeboard`, `recordings`, `photos` (+ `photos/queue`), `pdfs`, `expenses`, `calendar` (`calendar/materials`).

| Where the file is recorded | Path column | Folder | Link to a person | In catalogue? | Note |
| --- | --- | --- | --- | --- | --- |
| tblUsers | avatarPath VARCHAR(255) | usually an external https URL set at MS365/Google sign-in (Auth.php:505/532/744/767) | userID | yes | `Avatar.php` also accepts a local relative path, but no code writes local avatars (PROVEN, no other "avatars" reference). |
| tblUsers | displayPhoto VARCHAR(500) "Path under _uploads/ to profile photo" | — | userID | yes | **No code reads or writes this column** apart from `SELECT *` (PROVEN by grep). INFERRED: dormant. |
| tblExpenseClaimFiles | storedFilename | `_uploads/expenses` (expenses/submit/save.php:163) | via claimID → tblExpenseClaims.userID (no direct link) | **no** | Receipts — the person's own uploads. Generated claim PDFs also recorded here with a `stage` (ExpensePdf.php:250), under `_uploads/pdfs`. |
| tblGiftAidDeclaration | signaturePath | — | donorID | yes | **No code writes it** (PROVEN by grep). Dormant. |
| tblGivingStatementLog | pdfPath | `_uploads/giving/statements/{siteID}/statement-{donorID}-{periodKey}.pdf` | donorID | yes (retain) | Rendered statement PDF; the eraser unlinks these files (`eraseGivingStatementFiles()`). |
| tblPhoto | filePath, originalFilename | `_uploads/photos` (+ `queue` before moderation) | uploadedByUserID (actor) | yes (unlink) | The organisation's gallery; content the person created. |
| tblDocuments | filePath | `_uploads/documents` | uploadedByID (unknown name, FK to tblUsers) | **no** | Document library; organisation's material. |
| tblNoticeboardUploads | storedName | `_uploads/noticeboard` | createdByID | yes (unlink) | Poster media. |
| tblRecording | filePath, thumbnailPath | `_uploads/recordings` | uploadedByID (unknown name), presenterID | yes (unlink; catalogue names the wrong column) | Sermon/teaching audio-video. A presenter's voice/likeness is their data; the file is the organisation's. |
| tblEventMaterials | filePath | `_uploads/calendar/materials` | none | **no** | Event handouts. |
| tblAssetResources | filePath | `_uploads/assets` | uploadedByID NOT NULL (unknown name, FK) | **no** | Types include `receipt`, `ownership-agreement`, `insurance`, `legal` — may contain an owner's personal details. |
| tblVenueAgreementFiles | filePath | `_uploads/venues/agreements` | uploadedByID NOT NULL (unknown name, FK) | **no** | Hire contracts. |
| tblVenueInvoices | filePath | `_uploads/venues` | createdByID | yes (unlink) | Invoices. |
| tblVenueImportBatches | fileName (the uploaded spreadsheet) | `_uploads/venues` (Venues.php:3030) | createdByID | yes (unlink) | |
| tblBankImports | filename | (not traced) | importedByID (unknown name, FK) | **no** | Bank statement CSVs — the ORGANISATION's transactions, naming many donors. |

Kids: `tblKidProfiles` and `tblKidCheckins` have no photo or file column (PROVEN).

---

## 8. What GitHub #479 and #492 say is still open

Read with `gh` on 13 September. #479 is OPEN with five comments; #492 is OPEN with no comments.

**#479 acceptance criteria against the code today:**

| Criterion | State | Evidence |
| --- | --- | --- |
| The check exists and runs on every pull request | Met | `check_personal_data_coverage.py` runs at `pr-security.yml:315` (PROVEN). But `tools/gdpr-coverage-selftest.php` is NOT run by any workflow (PROVEN by grep of `pr-security.yml`); it is run by hand. |
| Every table holding a person identifier is in the list or an exclusion list with a reason | Partly | 131 sorted with reasons. Eleven FK-linked tables are unsorted because the check's identifier regex is narrower than `LINK_COLUMNS` (section 2f). |
| Adding a new table with a person identifier and no entry fails the check | Partly | True for the 15 names the regex knows; false for `uploadedByID`, `preparedByID`, `paidByID`, `actorUserID`, etc. |
| Any genuine gaps found are fixed | Erasure largely; download no | 108 catalogue tables absent from the download. |
| The exclusion list is written for a non-technical reader | Met in form | Reasons are plain English; 30-odd are the same boilerplate sentence. |

The issue's comments record, in order: (1) five tables missed; (2) the eraser never deletes the user row so `ON DELETE CASCADE`/`SET NULL` never fire, and the export is partial for activity logs; (3) the Noticeboard tables and files on disk; (4) the 77-of-134 count and the four kinds; (5) the registrations fix (commit 9c77216, migration 191), and the "still open" table: **123 tables, 23 in the download, 81 findable by account, 15 with no link, 4 retained** — figures now superseded by this survey (131 / 20 tables in 21 blocks / 118 with a known link / 6 with none under any name / 3 retain). The last comment also states the direction: drive the download from the catalogue.

**#492 — four erasure follow-ups, all still open in the code (PROVEN):**

1. *First link only.* `fromPersonalDataCatalogue()` breaks at the first matching name (GdprEraser.php:460-465). `inventory()` — what `/account/my-data` shows — counts on that same single column (lines 654-684). So someone who only ever EDITED an attendance session is not found or counted.
2. *Free-text contact details survive an unlink.* `tblAssetLoans.counterpartyContact` untouched; `tblKidProfiles.pickupAuthorisedNames` deliberately untouched (GdprEraser.php:137-141). The column's own comment says the format is comma-separated (section 4).
3. *Who has authority over a child's registration.* `tblEventRegistrations` is on the self-test's `$deliberateDeletes` list (selftest lines 218-222), so the whole record goes when the SUBMITTER asks — right for a parent, wrong for a volunteer who typed it in. `reviewedByID` exists on the table as the only other person link.
4. *Records kept whole where the list says "remove the name".* `tblExpenseClaims.userID` is `INT NOT NULL` (PROVEN) so the anonymise UPDATE is refused and logged as "partial"; `tblCareCase` is catalogued `unlink` with hand-written entries anonymising `personUserID` and `openedByID`. Whether a claimant's identity must stay with a six-year financial record is the owner's decision. The catalogue's own tblExpenseClaims reason already asserts "the claimant's name is removed", which the database currently prevents.

---

## 9. Shared-hosting limits that constrain an export

- The brief points at DEV_NOTES.md "around line 3616". The relevant text is at **lines 3945-3953** (line 3616 is in the Cloudflare/PayPal section): *"DreamHost shared FastCGI can kill a long-running request regardless of `set_time_limit()`"*, so the giving-statements generate/email handlers cap themselves at `giving.statements.batchPerRun` (default 25) per request and tell the user to re-submit to continue, copying `Newsletter::dispatch()`'s `newsletter.batchPerHour` shape (PROVEN). `offsite-backup-run.php:7` says the same: "long-running runs may hit PHP's `max_execution_time`". DEV_NOTES.md:4845 adds that anything sleeping inside a request "would hold a PHP-FPM worker hostage".
- `set_time_limit()` is used in four places: 300 s in `giving/statements-generate.php:109`, `giving/statements-email.php:113`, `cron/giving-statements.php:72`; 900 s in `admin/maintenance/offsite-backup-run.php:43` (PROVEN). Nothing sets `memory_limit`. There is no `.user.ini` or `php_value` in `.htaccess` (PROVEN), so PHP's memory and time limits are whatever DreamHost sets; the portal reads them at runtime on `/admin/system-info` (`ini_get('memory_limit')`, `max_execution_time` — system-info/index.php:205-206). **The actual values on the live server are unknown from this machine** (INFERRED that they are DreamHost defaults).
- No command line, no Composer, nothing can be installed (CLAUDE.md; DEV_NOTES.md:2689). Scheduled work is only possible through token-gated URLs hit by an external scheduler such as DreamHost's cron (DEV_NOTES.md:3214); the four `cron/*.php` handlers are the precedent.
- ZIP: `ZipArchive` is used by `giving/statements-download.php` (with a `class_exists` fallback to per-file links when it is absent — the file's header says it may not be available on this server), `Venues.php` and `venues/import.php` (PROVEN present in code; NOT verified enabled on the server).
- PDF: dompdf 3.1.5 is server-managed under `_libraries/` (CLAUDE.md) and wrapped by `web/_core/Pdf.php` (PROVEN — the only file naming `Dompdf`); giving statements and expense claims already render PDFs through it.
- Streaming large files: `statements-download.php` sends `Content-Length` + `readfile()`; `Recordings.php:100` uses `flush()` for media. No precedent for streaming a database result set incrementally.
- Uploads: DEV_NOTES.md:3406 mentions "DreamHost's PHP upload limits" without numbers (the Cloudflare Stream design bypasses them by posting straight to Cloudflare).
- Hosting reality check for size: nothing is installed on the live server yet and there is no real customer data (#479 comment 1; HANDOFF). A "long-standing member with thousands of rows" is a future state, not a measured one.

---

## 10. Things noticed that the brief did not ask for but the design will trip over

1. **The activity-log entry for a download does not name the person** (`userID` NULL) — section 6. Any "record in the audit trail that a download happened" requirement is not met today even though a log line exists.
2. **`$fetchUserRows` swallows prepare failures** — an absent table or a wrong column becomes "no rows". A catalogue-driven download that copies this helper inherits the fault.
3. **The catalogue's `columns` lists are not a reliable column source** — five name a column that does not exist, twenty-nine omit a real link column, and many omit name/contact columns (sections 1a, 2e, 4b). A download built from `information_schema` (as the brief's safety rule H requires) sidesteps this; a download built from the catalogue's lists does not.
4. **The coverage check's identifier list is narrower than `LINK_COLUMNS`**, so eleven person-linked tables are outside the catalogue and nothing says so (section 2f).
5. **`inventory()` on `/account/my-data` has the same first-link-only limit as the eraser** and also silently skips on exception, so the counts a person sees before requesting erasure are already an under-count.
6. **Router's `isProtected` string comparison** (INFERRED never true) — outside this design, but it means every page's own `requireLogin()` is the only guard.
7. **Six existing blocks are actor-matched rows about other people or the organisation** (section 1c), so question A is a change to current behaviour, not only a rule for new blocks.
8. **The `tblRecording` catalogue entry names a column that does not exist**, and the catalogue's headings and counts are stale — cheap to fix, but they will confuse whoever builds from the file.
9. **`tblSalvationCards` is catalogued erase with `assignedToID` as its "about" link**, but that column is the follow-up worker; the person the card is ABOUT has no link. A catalogue-driven download would hand the worker every card assigned to them, including other people's names, phone numbers and prayer requests — the sharpest example of question A.
10. **`tblAuditTrail.oldValue/newValue/changeSet`** for rows matched on `userID` are the before/after values of records the person CHANGED — routinely other people's data.
11. **Erasure-request flow already has an email-confirmation token** (`tblErasureRequest.confirmToken`, status `pending_confirmation`) — a precedent for "email a link rather than stream it" (question I/J), though not read in detail here.

## What was not checked

- Nothing was run against a database; every "PROVEN" about SQL is about the text of the schema and the code, not about live behaviour.
- The live server's `memory_limit`, `max_execution_time`, FastCGI kill threshold and `ZipArchive` availability are unknown.
- `AssetRegister.php:4010` (the counterpartyContact acceptance claim in #492) was not re-read.
- No Codex review was run on this survey; the brief is design-only and the standing review rule applies to the change, not to the survey.
