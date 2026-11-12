# D-161 · The language switch's return address goes through the redirect judge, and the judge looks only at the path

**Date:** 2026-11-12  
**Status:** in force

**Question:** The language switch sends a member back to the page they were on, but that return address is not checked by Auth::safeRedirectUrl(), and the judge looks at the whole address, wrongly refusing some legitimate returns (an address with a time in it). Fix both?

**Answer:** Fix both: route the language switch's return through the judge, and make the judge look only at the part before the first / ? or #.

**Rules out:** Leaving the language switch's return unjudged; leaving the judge reading the whole address.

**Where:** #910 — `Auth::safeRedirectUrl()`, the `?lang=` handling in `web/public_html/index.php`. Related: #620, D-062.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
