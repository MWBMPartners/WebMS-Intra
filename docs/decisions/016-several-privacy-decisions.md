# D-016 · Several privacy decisions

**Date:** 2026-11-03  
**Status:** in force

**Question:** a batch — what happens to a worker's name on a pastoral visit; who
a room booking is about; what free text an erasure empties.
**Answer:** #703 keep the name of who made a pastoral visit or safeguarding
check. #723 the booker IS the booking's subject, so `purpose` is emptied —
`purpose` **and nothing else**; `approvedByID` stays an actor column. #715 empty a
person's own words about themselves; keep `presenterText`, `deviceName`,
`seatLabel`, `meetingNotes`.
**Rules out:** unlinking the worker; emptying text on an approver's number.
**Where:** `GdprEraser`; migration 240; `.claude/CLAUDE.md`.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
