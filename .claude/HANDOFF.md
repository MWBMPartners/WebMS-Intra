# Handoff — where the work is right now

*Overwritten, never appended. Current state only (#678). Issues are the record of
what is and is not done.*

**Branch:** `claude/alpha-wip`, clean. **13 commits unpushed**, and **573 ahead of
`origin/alpha`, 615 ahead of `origin/main`** — so every fault those commits fix is
still live on the released branches. Pushes and merges are the owner's.

## The owner's seven decisions, 1 November 2026

1. **Codex review: split across resets, but DO NOT START until every identified
   release task is done** — the owner's reason: avoid re-reviewing the same code.
   So the order is **finish the release work → queue the Codex pieces → merge**.
2. **#474 branch protection goes on AFTER the merge** (hard gates + one review).
3. **#678: do both halves.** Wording corrected in `d43a4f6`; the settings JSON is
   drafted for approval and NOT written — it is the owner's own file.
4. **#475: ship on the MySQL 8.0 convention and say so plainly.** A floor, not a
   ceiling: the harness already passes on 8.4.11 and MariaDB 11.4.13.
5. **#581 item 4: build the key-loss escape** (#582's change-the-key tool). Item 3
   (rebuild with no command line) is documented as a known limit.
6. **#703: the name on a pastoral or safeguarding record is configurable, default
   keep.**
7. **#541 item 2: add treasurer alongside approver** in `mayExportClaims()`.

## The release scope is built, and all five items are verified

Repeating events (`6c6b854`), backups taken by themselves, downloaded and brought
back (`eefa603`, migration 228), transcription's trigger, #641 in full, and the
browser/visual half of #569 (`0186531`). `tools/run-all-selftests.sh` is **66 of
66** — the mail-queue test re-ran clean after the New York clock overlap.

## The issue tracker is now honest

198 were open. **Six read-only audits judged every one against the CODE**, not
commit messages — the earlier pass had selected candidates by commit subject,
which is the signal that cannot be trusted (#556 was finished with no commit
naming it anywhere). Result: **75 closed** with `status: done`, **22 given a
status correction**, **128 open**, 1 critical and 19 high.

A seventh audit re-read 18 of the 71 closures against the code: **all correctly
closed, none to reopen.** It also caught a **false statement in my own comment on
#529** — I said no help page names who may read attendance figures;
`web/_apps/help/calendar.php:377` does. Corrected on the issue, because a wrong
reason on an open issue is what stops the next reader looking.

Raised because nothing covered them: **#701** (path mode's four blockers),
**#702** (mail M5/M6), **#703** (the erasure decision), **#704** (#514's waived
criterion 3). **#540 closed as a duplicate of #587.**

## What must be fixed before the release, in order

1. **#666** — event reminders ignore the preference. Measured: the recipient query
   has no `notifyPrefs` condition, the only mention in the file is a comment about
   the push path, and the page tells the member it does not work. A consent
   failure on a live mail path.
2. **#635** — 35 tables still leave named personal data after an erasure; the
   pinned list in `tools/gdpr-coverage-selftest.php` is unchanged. 35 owner
   decisions.
3. **#582** — the key-loss escape (decision 5).
4. **#586** — a password is reported saved and nothing is stored when the account
   has none. The likeliest day-one support call.
5. **#583g** — `/help/disaster-recovery` describes three tools that do not exist
   and links to GitHub addresses a customer cannot open.
6. **#683 / #685 / #491 / #492 item 2 / #481** — cross-organisation and retention
   faults: one organisation's support address in another's mail; the attendance cap
   enforced at the form and ignored by the job; a log clear-out with no `siteID`;
   a borrower's phone number surviving erasure; migrations switching apps back on.
7. **#620 / #649 / #596 items 3 and 7 / #588 item 1** — redirect judging (including
   a tab bypass), receipts silently dropped, the `Secure` cookie flag behind a
   proxy, mail silently not sent with no from-address, and
   `StrictHostKeyChecking=no` on the deploy.
8. **#541 item 2, #703, #539, #619** — small, decided or near-decided.
9. **#476, #146, #637** — alpha's wrong version; the visual audit runs in no
   workflow; one audit check runs in no workflow and two documents overstate the
   gap.

## Known weak points, stated rather than buried

- **#699** — the giving ceiling's two save gates have no automatic test.
- **#585 item 1** — the "error log is empty" check cannot fail: a caught exception
  goes to `tblErrors`, which that check never reads generally.
- **#479's check is wired `|| true`**, so a table of personal data with no decision
  recorded leaves the run green.
- **#689** — `access-pages-selftest.php` must run alone.

**Codex has reviewed nothing on this branch.** Independent Claude checkers stood in.
