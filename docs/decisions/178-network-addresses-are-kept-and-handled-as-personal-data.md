# D-178 · Network (IP) addresses are kept for audit, security and support, and handled as personal data

**Date:** 2026-11-12  
**Status:** in force

**Question:** should the portal continue to store visitors' and members' network (IP) addresses? The owner's reasons
for keeping them: audit, security and support purposes; and that an address is hardly tied to an individual in an
ordinary person's context.

**Answer:** keep storing them, for audit, security and support (a legitimate interest that the law names for network
and information security). But they are handled as personal data, because in law they are (the EU court's Breyer
ruling, 2016, followed in UK law) and because in this portal an address sits beside an account number, a time and an
action, which identifies a signed-in member outright. So: each place that keeps an address has a set keeping period
(for the activity log, D-117 to D-119); an erasure reaches the addresses on a person's own records (member records;
asset changes, D-164; visitor suggestions, #964; anonymous prayer requests, D-176); and the privacy notice names
network addresses and why they are kept.

**Rules out:** stopping storing network addresses; treating them as not personal data (kept for ever, unreachable by
an erasure, or left out of the privacy notice).

**Where:** `Portal\Core\Logger`, `Portal\Core\GdprEraser`, the personal-data catalogue and the privacy page. Related:
D-117, D-118, D-119, D-164, D-176, #964.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
