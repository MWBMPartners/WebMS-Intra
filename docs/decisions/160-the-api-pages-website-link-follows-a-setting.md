# D-160 · The API page's publisher "Website" link follows a setting

**Date:** 2026-11-12  
**Status:** in force

**Question:** The page that documents the portal's API has a "Website" link for the publisher, pointing at a fixed place. What should it do?

**Answer:** Follow a setting: the link uses a website address set during installation, and is left out when none is set — no address is built in.

**Rules out:** A fixed, built-in address; showing the name with no link.

**Where:** #863 — `web/public_html/api-docs/index.php` and the setting it reads. Related: #500.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
