# D-157 · "£" in an imported file is read only for an organisation using pounds sterling

**Date:** 2026-11-12  
**Status:** in force

**Question:** D-153's example for imported files was "£1,234.56" read as pounds, and D-155 refuses a sign shared by
several currencies. "£" is also the sign of the Gibraltar, Falkland Islands and St Helena pounds and of the Egyptian,
Syrian, Lebanese and South Sudanese pounds. How should "£" in an imported file be treated?

**Answer:** pounds sterling only. In an organisation using GBP, "£1,234.56" is read as its own money (D-153's example).
In an organisation using any other "£" currency, "£" is refused with the shared-sign reason (D-155), and the file uses
the three-letter code (for example GIP 12.50).

**Rules out:** refusing "£" in a GBP organisation; reading "£" as the organisation's own money in a non-GBP "£"
currency.

**Where:** #1004 — `Portal\Core\Currency` (`SHARED_SIGNS` and the file-mode reader). Related: D-152, D-153, D-155.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
