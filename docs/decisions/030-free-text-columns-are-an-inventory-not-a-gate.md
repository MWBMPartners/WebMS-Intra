# D-030 · Free-text columns are an inventory, not a gate

**Date:** 2026-11-02  
**Status:** in force

**Question:** should the personal-data check fail on every column that might
hold free text about a person?

**Answer:** no. Free-text columns are an INVENTORY, not a gate.

**Rules out:** reading a green run as "every personal column is named" — it
means every contact-SHAPED column is named, and no more. A first draft flagged
283 columns across 150 tables, and a check that cries wolf gets switched off.

**Where:** `check_personal_data_coverage.py`; #706.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
