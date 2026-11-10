# D-118 · An organisation's own administrator sets its activity-log period

**Date:** 2026-11-10  
**Status:** in force

**Question:** the activity log records what people do, administrators included. Each organisation may have its
own retention period within the installation's limit (D-117). Who may set an organisation's own period: only the
installation's administrator, or the organisation's own administrator too?

**Answer:** the organisation's own administrator too, within the installation's limit — the same as attendance
and giving records. The owner chose this knowing it lets an organisation's administrator shorten how long their own
actions are kept on record. The installation's administrator still sets the installation's figure, which caps every
organisation (D-013, #668).

**Rules out:** limiting the organisation's figure to the installation's administrator alone (as #932's first build
did); an organisation's figure above the installation's; an organisation choosing "never" when the installation has
not (D-117).

**Where:** #932 — `web/_core/ActivityLogRetention.php`, `web/_apps/admin/maintenance/retention.php`.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
