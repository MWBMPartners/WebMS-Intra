# D-075 · Content translation gets its own app switch

**Date:** 2026-11-07  
**Status:** in force

**Question:** every app folder can be switched off except three that must always run
(`push`, `tours`, `users`). `web/_apps/translation/`, added by #485, has no app switch,
so only the per-address `api.translation.content.enabled` row and each member's opt-in
stand between a member and a charged translation. Give it a switch, or add it to the
always-on exceptions (#792)?

**Answer:** **give it a switch, like any other app.** An organisation can turn the whole
translation feature off in one place; it spends money, so one off switch is the safer
design. The owner, 7 November 2026.

**Rules out:** adding `translation` to the always-on exceptions.

**Where:** #792 (the `app-switch-selftest.php` and `access-pages-selftest.php` failures).
Related: D-049, D-059.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
