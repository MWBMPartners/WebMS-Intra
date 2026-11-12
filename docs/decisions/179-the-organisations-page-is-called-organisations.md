# D-179 · The page that manages an installation's organisations is called "Organisations" everywhere

**Date:** 2026-11-12  
**Status:** in force

**Question:** the page where a global administrator manages the organisations on an installation was called "Sites" in
the menu, while about forty sentences elsewhere (help, installer, health checks) called it "Organisations". Which name?

**Answer:** "Organisations" everywhere — menu, page title, breadcrumbs, labels, help, installer and documents. It matches
the portal's own word for a church or group using it.

**Rules out:** "Sites" as a name a person reads for this page.

**Where:** #973 — `web/_lang/en.php` (`nav.sites`), `web/_apps/admin/sites/*`, help pages, installer, docs. The address
`admin/sites` itself is unchanged.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
