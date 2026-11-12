# D-154 · A member's copy of their own DBS checks leaves out who recorded each one

**Date:** 2026-11-12  
**Status:** in force

**Question:** a member's full data copy now includes the DBS checks about them (type, certificate reference, issue
and expiry dates, status, when recorded, notes). Each check also stores the name and account number of the worker who
typed it in. Should the member's copy say who recorded each check?

**Answer:** leave the worker out. Everything about the check itself is handed over, but not who typed it in — as the
copy already treats other people (who gave a role, who added someone to a group), and as the member's own
safeguarding page already shows it.

**Rules out:** giving the recorder's name or account number in the member's own data copy.

**Where:** #1012 — `Portal\Core\GdprEraser::dbsChecksAboutYou()`, `web/_apps/auth/account/data-export.php`.
Related: D-110 to D-113 (the name stays on the record), D-142.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
