# D-107 · No change to the minimum password length on running installations: none exists

**Date:** 2026-11-10  
**Status:** in force

**Question:** new installations require passwords of 12 characters. An old fault (fixed by #466)
left installations running before then requiring only 8. Should an upgrade raise 8 to 12, tell
administrators and let them choose, or do nothing (#480)?

**Answer:** **nothing.** No customer installation exists yet, so there is no running installation on
the old 8; every installation made from now on requires 12. The help page's note telling an
administrator to check their own value stays. The owner, 10 November 2026, choosing the
recommended option.

**Rules out:** an upgrade raising 8 to 12 automatically; a dashboard notice with a button.

**Where:** #480 — migration 041, migration 187, `Auth.php`'s fallback of 12, `/help/admin`.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
