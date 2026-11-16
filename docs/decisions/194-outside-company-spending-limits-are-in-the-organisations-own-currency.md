# D-194 · Spending limits for outside companies are written in each organisation's own currency

**Date:** 2026-11-16  
**Status:** in force

**Question:** with exchange rates fetched daily and an administrator fallback (D-192), whose currency is a spending limit for outside companies (AI, translation, text messages, transcription) written in (#993)?

**Answer:** the owner chose "each organisation's own currency". An organisation's limit is a plain amount in its own currency, the same as its giving and expenses (D-133, D-139). The installation's own limit caps each organisation's, converted with the daily rate, so the stricter figure always wins (D-013, D-026). A charge is recorded in the company's own currency and converted only to compare with a limit (D-173).

**Rules out:** a single installation-wide currency for every limit; limits with no named currency.

**Where:** #993. Related: D-173, D-192, D-133, D-139, D-013, D-026.

**Supersedes:** nothing.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
