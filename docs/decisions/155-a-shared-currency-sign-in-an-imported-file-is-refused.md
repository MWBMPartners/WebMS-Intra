# D-155 · A currency sign shared by several currencies is refused in an imported file, with the true reason

**Date:** 2026-11-12  
**Status:** in force

**Question:** the bank and asset imports read a cell written with the organisation's own currency sign (D-153). Some
signs are shared: "$" is written by Australian, Canadian, New Zealand, Singapore, Mexican and Hong Kong dollars as well
as US dollars; "¥" by yuan and yen. In an organisation whose own currency uses such a sign, should a cell written with
it be read as the organisation's own money?

**Answer:** no — refuse it, and say truthfully why: the sign is shared by several currencies, so the cell should drop
the sign or use the currency's three-letter code (for example AUD). A sign that belongs to only one currency, matching
the organisation's own, is still read (D-153).

**Rules out:** reading a shared sign as the organisation's own currency; refusing it with a sentence that says the
file is "in another currency".

**Where:** #1004 — `Portal\Core\Currency::whyNotAnAmount()` (file mode). Related: D-139 (an amount's currency is never
guessed), D-152, D-153.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
