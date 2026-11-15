# D-188 · Network addresses are emptied after 365 days by default, by one setting capped like the activity log

**Date:** 2026-11-15  
**Status:** in force

**Question:** D-178 says each place that keeps a network (IP) address needs a set keeping period but names no
figure. What is the default, and how is it set?

**Answer:** the owner chose 365 days. One setting, `privacy.networkAddressDays`, on Admin, Maintenance, Retention,
following the activity log's rules (D-117 to D-119): the installation caps each organisation, 0 means "never" only
where the installation allows it, and a figure above the cap is refused with the reason, never lowered. The
nightly clear-out empties only the address and keeps every record. It covers password-reset requests, trusted
devices, prayer requests, access keys' last-used address, live-chat messages, public-form answers and recording
plays. Consent records follow #934 (D-166); the change history is D-187; a Gift Aid declaration is D-189.

**Rules out:** a different figure per place; deleting the records rather than emptying the address; a default of
"never".

**Where:** #1019 (`Portal\Core\RetentionPeriod`, `NetworkAddressRetention`, `address_sweep()`, migration 295).
Related: D-117, D-118, D-119, D-178.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
