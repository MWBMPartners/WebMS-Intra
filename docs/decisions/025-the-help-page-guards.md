# D-025 · The help-page guards

**Date:** 2026-11-04  
**Status:** in force

**Question:** should the runbook-reference check be binding, and should the help
pages be loaded over HTTP?
**Answer:** **binding** (a hard gate), knowing it binds everybody; and **all
twenty** pages loaded, not just the disaster-recovery one.
**Rules out:** a report-only check; covering one page only.
**Where:** `tools/audit-checks/check_help_page_references.py`,
`tools/help-pages-selftest.php`.

**Issue:** [#752](https://github.com/MWBMPartners/WebMS-Intra/issues/752) —
the standing issue for decisions that are not about a feature.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
