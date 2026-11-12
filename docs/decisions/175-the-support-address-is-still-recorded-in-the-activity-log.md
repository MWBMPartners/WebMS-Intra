# D-175 · The organisation's support e-mail address is still recorded in the activity log when it is changed

**Date:** 2026-11-12  
**Status:** in force

**Question:** When an administrator saves the organisation's support e-mail address, the activity log records the address. It is the organisation's own contact but can be a person's own mailbox. Should the log record it?

**Answer:** Keep recording it, as now: it is the organisation's own published contact.

**Rules out:** Recording only that it changed.

**Where:** #990 — `web/_apps/admin/settings/organisation/save.php` (`PortalAddressSaved`). Related: #933 (its deliberate exception), D-174.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
