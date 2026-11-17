# D-211 · A membership records when it was switched off, so consent records can be cleared after a member leaves

**Date:** 2026-11-17  
**Status:** in force

**Question:** (#934) D-166 keeps a consent record for the retention period "after the member leaves", but the portal does not record when a membership was switched off (only a joined date and an on/off switch). What should "leaves" mean?

**Answer:** the owner chose: add a leaving date to memberships. Every place that switches a membership off records the moment, and the consent records of a member who has left are deleted the retention period after it. This was NOT the recommended option ("leaves" means erased, nothing more built): the owner chose the more exact record knowingly, accepting the larger package.

**Rules out:** treating "leaves" as only an erasure; guessing a leaving date for memberships switched off before this exists (those have none and are handled as the plan says).

**Where:** a separate package (2h), after 2g: a column on `tblUserSites`, every writer that switches a membership off, a migration, the backup and restore lists, and the clear-out step.

**Supersedes:** nothing.

**Related:** D-166, D-205 (pilot first).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
