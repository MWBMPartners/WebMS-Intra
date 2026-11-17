# D-215 · The API documentation page (/api-docs and /openapi.json) is for signed-in administrators and API key holders only

**Date:** 2026-11-17  
**Status:** in force

**Question:** (#1131) the Swagger UI page at `/api-docs` and the description at `/openapi.json` are public on the live portal. D-214 allows restricting them to API users. Who may open them?

**Answer:** the owner chose: only signed-in administrators and people holding an API key. Recommended option taken. A public list of every address and field helps nobody except someone probing for weaknesses on an internal portal.

**Rules out:** keeping the page and the description public on the live portal; leaving it as an unstated choice.

**Where:** `web/public_html/api-docs/index.php`, the `/openapi.json` route, the page's own comment (which says "Public on the live portal"), `docs/INSTALL.md` if it mentions the page, and a test; #1131.

**Supersedes:** nothing.

**Related:** D-214.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
