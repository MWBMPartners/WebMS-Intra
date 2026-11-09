# D-101 · An organisation's public address takes effect only once the installation's global administrator approves it

**Date:** 2026-11-09  
**Status:** in force

**Question:** an organisation can give the portal a public address of its own (D-095), where only
its public events and posters answer. A check found that an organisation's administrator could
enter an address members actually sign in at — an alias the portal has no record of, or the
members' own address while the portal's address is not set — and every page there, sign-in
included, would stop working, with no way for the installation's own administrator (who may be a
different party, such as a hosting company) to undo it from that address. How should a public
address take effect?

**Answer:** **only once the installation's global administrator approves it.** An organisation
enters its public address, but it does nothing until the installation's global administrator
approves it on Admin → Public website; until then the card says it is waiting. The owner,
9 November 2026, choosing the recommended option.

**Rules out:** a public address taking effect as soon as an organisation saves it, guarded only by
the portal's own record of members' addresses.

**Where:** #493 P4a — `PublicWebsiteSetup`, `tblPublicHosts`, `Site::detectDoor()`. Related: D-085,
D-093, D-095, D-098.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
