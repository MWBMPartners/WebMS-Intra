# D-201 · A merge moves the records the portal uses to decide who may manage them, and logs the move

**Date:** 2026-11-16  
**Status:** in force

**Question:** D-199 says a merge never reassigns "who did it". Some records (who asked to borrow an item, who uploaded a photo, who created a task, a saved report, a recording note, an attendance session, a booking, a rota-swap request) carry a number the portal uses to decide who may cancel, see or change that record. Left on the old account, the person loses the right to manage their own things after the merge. What should the merge do with them?

**Answer:** the owner chose: move them to the surviving account, and log it. Only records whose "who made it" number the portal uses to decide who may see or change the row are moved; the activity log and the change history are still never rewritten (D-199 stands for them). The move is written as its own new activity entry naming each kind of record moved and how many (numbers and fixed words only, no names).

**Rules out:** rewriting the activity log or change history; leaving a person unable to manage their own loans, photos and similar records after a merge; teaching every permission check about merged numbers instead.

**Where:** #1064 (`AccountMerge`, entry `AccountsMergedRecordsMoved`). Wording on the merge pages and the completion e-mail must say what moves and what stays.

**Supersedes:** nothing.

**Related:** D-199 stays in force for history; this covers only the ownership records above.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
