# D-108 · An administrator setting someone's password signs that person out of every browser

**Date:** 2026-11-10  
**Status:** in force

**Question:** D-104 decided that a member resetting or changing their OWN password signs out their
other browsers. When an administrator sets a new password for someone else's account (for example
after the member reports it may have been stolen), should that also sign the member out of every
browser where they are signed in (#598)?

**Answer:** **yes.** Setting someone's password from the admin page signs that person out everywhere,
so anyone using the old password is put out at once; the member signs in again with the new password.
The owner, 10 November 2026, choosing the recommended option.

**Rules out:** an administrator's password change leaving the person's existing sign-ins in place.

**Where:** #598 — `web/_apps/admin/users/save.php`, `Auth::endOtherSessionsOf()`. Extends D-104.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
