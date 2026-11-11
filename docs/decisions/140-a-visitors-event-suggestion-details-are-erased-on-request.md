# D-140 · A visitor's event-suggestion details are erased on request, not automatically

**Date:** 2026-11-11  
**Status:** in force

**Question:** a visitor who is not signed in can suggest an event, giving a name and an e-mail address (used only to
tell them it was approved, D-134). Those details stay on the event row for ever, and an erasure request cannot reach
them because an erasure finds a person through their account and a visitor has none; the approval e-mail also
leaves the address in the record of sent mail. Clear them automatically once the suggestion is decided, erase them
only on request, or both?

**Answer:** erase on request only. The details stay on the event until the visitor asks. An administrator can then
file an erasure request by e-mail address, without an account, after checking off the portal that it really is that
person. That request also clears the matching sent-mail and mail-queue rows.

**Rules out:** clearing a visitor's name and address automatically when the suggestion is approved or turned down.

**Where:** #964 — `Portal\Core\GdprEraser`, the erasure requests page, `tblEvents.submitterName` /
`submitterEmail`, `tblEmailLog` and the mail queue. Related: D-134.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
