# D-177 · A currency sign made only of letters is not read in an imported file; the three-letter code is

**Date:** 2026-11-12  
**Status:** in force  
**Supersedes in part:** D-165 (only for signs made only of letters)

**Question:** some currencies' signs are made only of letters — "R" (rand), "kr" (kronor), "Rs" (rupees), "Fr."
(Swiss francs), "P", "L", "K", "Q", "Rp", "Ft", "RM". In imported files these clash with ordinary text ("R Smith",
"Fr. John", "P.O. 1234" read as currencies) and "Rs. 50" was read as 0.50, a hundred times too small. Signs with a
symbol in them and three-letter codes have none of these problems. How should letter-only signs be treated?

**Answer:** codes only for these. In an imported file a letter-only sign is not read: the cell is refused with a plain
message to write the three-letter code instead (for example "ZAR 12.50"). Signs containing a symbol ($, £, €, ¥, ₱,
₹, R$, A$…) and three-letter codes are read exactly as D-165 says.

**Rules out:** reading letter-only signs in imported files, with or without extra rules.

**Where:** #1004 — `Portal\Core\Currency` (file mode). Related: D-152, D-153, D-165.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
