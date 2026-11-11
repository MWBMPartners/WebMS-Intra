# D-139 · Every money record keeps its own currency, and an expense keeps both the quoted and the converted amount

**Date:** 2026-11-11  
**Status:** in force

**Question:** #966 gives each organisation its own currency (the kind of money: GBP pounds, EUR euros, USD
dollars). Many saved amounts were a bare number with no note of which money it was (expense claims, member
pledges, collection counts, …), and no total added different currencies up separately. So changing an
organisation's currency would silently relabel every old amount and mix currencies in totals. Option A: lock the
currency once any money is recorded. Option B: every record saves its own currency and every total is worked out
per currency.

**Answer:** B, and more. The owner's words: "We need a currency entry for every record, especially as whilst we
may work on one currency (say GBP) some expenses may be quoted in another (USD) and claims will need provide
evidence in the originally quoted currency and the converted (forex) domestic currency (GBP) for record and audit
accuracy".

So:
- every kind of record that holds money saves the currency it is in, beside the amount;
- every total, report and statement is added up separately for each currency, never across currencies;
- an expense claim's line keeps the amount and currency it was QUOTED in, and the CONVERTED amount in the
  organisation's own (domestic) currency, both kept for audit; what is reimbursed is the converted amount.

**Rules out:** option A (refusing a currency change once money is recorded) as the answer to mislabelling; a total
that adds amounts in different currencies; an expense claim that records only the converted figure.

**Where:** #966 — `Portal\Core\Currency`, the money tables (`full_schema.sql` and a migration), the expense pages,
and every page or statement that shows or sums money.
Related: D-133 (a currency is pre-selected from the country and stays changeable — this keeps it changeable).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
