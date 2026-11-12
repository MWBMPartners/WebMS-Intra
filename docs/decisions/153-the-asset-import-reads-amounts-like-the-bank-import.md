# D-153 · The asset import reads amounts the way the bank-statement import does

**Date:** 2026-11-12  
**Status:** in force

**Question:** D-152 makes a typed money amount figures only, and leaves a bank-statement file reading its own commas.
The asset import also reads a file: a spreadsheet of assets uploaded as CSV, which a spreadsheet saved with currency
formatting writes as "£1,234.56". Should the asset import read amounts the way the bank import does?

**Answer:** yes. The asset import accepts commas between groups of three and the asset's own currency sign or code, as
a file a spreadsheet wrote. Anything else is still refused. Boxes a person types into stay figures only (D-152).

**Rules out:** refusing a currency-formatted spreadsheet cell by cell.

**Where:** #1004 — `Portal\Core\Currency::parseAmount()` (`fromStatement` / file mode), `web/_apps/assets/import.php`.
Related: D-152.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
