# D-192 · Exchange rates are fetched daily from a public service, with administrator-entered rates as the fallback

**Date:** 2026-11-16  
**Status:** in force

**Question:** D-173 records each outside company's charge in that company's own currency and converts only to compare with spending limits. The portal holds no exchange rates (#993). Where does a rate come from?

**Answer:** the owner chose: fetched daily from a public exchange-rate service, with a fallback to rates an administrator enters (at installation level or organisation level). So: a daily fetch of the rates for the currencies companies bill in; when the fetch is unavailable or stale, the administrator-entered rate is used; with neither, spending with that company is refused with a message saying why. The fetch is an outside service and must be named on the privacy pages like the others (D-158) and obey the outside-services rules.

**Rules out:** a built-in rate; counting spend in "pence" priced from a fixed list; limits set only in a company's own currency.

**Where:** #993. Related: D-173, D-158, D-058.

**Supersedes:** nothing.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
