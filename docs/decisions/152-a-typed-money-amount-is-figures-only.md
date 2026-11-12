# D-152 · A money amount typed into a box is figures only

**Date:** 2026-11-12  
**Status:** in force

**Question:** typed money amounts are now read strictly (#1004): no scientific notation, at most two decimals, nothing
over what the database can hold. Should a box also accept thousands commas between groups of three ("1,234.50") and
the record's own currency symbol or code in front ("£12.50")?

**Answer:** figures only. A box accepts digits and at most one decimal point with at most two decimals; "1,234.50",
"£12.50" and "GBP 12.50" are refused with a plain message asking for figures only.

**Rules out:** commas and currency symbols or codes in a typed amount.

**Not covered:** a file the portal reads rather than a person typing — a bank statement import keeps reading the
statement's own number format (commas between groups of three) because that is how banks write it; that was the
commissioning session's reading of the question, recorded on #1004 for the owner to correct.

**Where:** #1004 — `Portal\Core\Currency::parseAmount()` and every box that takes money.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
