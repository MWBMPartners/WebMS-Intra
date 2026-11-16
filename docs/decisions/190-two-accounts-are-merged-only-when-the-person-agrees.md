# D-190 · Two accounts become one only when the person themselves agrees

**Date:** 2026-11-16  
**Status:** in force

**Question:** who must agree before two accounts that belong to one person are merged (#1064)? A merge cannot be undone and moves every record of the duplicate onto the surviving account.

**Answer:** the owner chose "the person themselves". An administrator with the merge permission starts it and sees only counts; it completes only when the person signs in to BOTH accounts and confirms. An administrator alone, or two administrators, cannot complete it. This follows D-018: nobody may add a sign-in method to somebody else's account.

**Rules out:** an administrator merging two accounts alone; two administrators agreeing without the person; moving a Microsoft or Google sign-in or a passkey onto an account without the person's own sign-in to both.

**Where:** #1064 (`AccountMerge`, `/admin/users/merge` and the person's confirmation page). Related: D-018, D-185, D-116.

**Supersedes:** nothing.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
