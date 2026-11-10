# D-105 · The health page's mail status counts only the viewer's own organisation

**Date:** 2026-11-10  
**Status:** in force

**Question:** one installation sends every organisation's e-mail through one mail account. Any
organisation's administrator could open the health page and see which mail service the whole
installation uses, which of its settings are missing, and how many people in EVERY organisation
asked for a password-reset e-mail last week that was never sent (#598 item 2). Who may see what?

**Answer:** **an organisation's administrator still sees the mail status, counted for their own
organisation only.** They learn that mail is not working and that a global administrator must fix
it; the count covers only their own organisation's people, as the e-mail settings page already
does. The owner, 10 November 2026, choosing the recommended option.

**Rules out:** showing the status to global administrators only; showing every organisation's count
to any organisation's administrator (today's behaviour).

**Where:** #598 item 2 — `web/_apps/admin/maintenance/health.php`, `_health-probes.php`. Related:
#592 (the e-mail page).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
