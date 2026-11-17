# D-221 · A consent record replaced from another device is noted, not guarded against; the 730-day default stands

**Date:** 2026-11-17  
**Status:** in force

**Question:** (#1127) a cookie choice on a second device replaces the first device's record, so with a keeping period under 365 days the first record can be deleted while its cookie still holds the answer. Guard against it?

**Answer:** the owner chose: no. It is harmless (the browser keeps asking correctly) and cannot happen with the default 730 days. The Retention page should say a period under a year can do this.

**Rules out:** refusing a period under 365 days.

**Where:** `ConsentRetention`; the Retention page wording; #1127.

**Supersedes:** nothing.

**Related:** D-166, D-210.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
