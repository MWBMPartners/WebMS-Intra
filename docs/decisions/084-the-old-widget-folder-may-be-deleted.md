# D-084 · The old web-root widget folder may be deleted

**Date:** 2026-11-07  
**Status:** in force

**Question:** `web/public_html/widget/` holds the old countdown script other websites
paste in, and hides the address `/widget`, which nothing has answered since migration
189. The standing rule (5 October 2026) is that deleting a folder from the web root needs
the owner's approval, because a folder can hide a page. #493 replaces the script with the
new public-website door. May the folder be deleted?

**Answer:** **yes, delete it** in the package that builds the door, with a test proving
`/widget` still answers nothing afterwards. No customer installation exists, so no
website's countdown breaks. The owner, 7 November 2026, choosing the recommended option.

**Rules out:** keeping the old script alongside the new door.

**Where:** #493, package P4. Related: #478, #319.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
