# D-165 · A currency sign in an imported file is read as the organisation's own currency whenever that currency uses the sign

**Date:** 2026-11-12  
**Status:** in force  
**Supersedes:** D-155, D-157

**Question:** Several currencies share signs ("$" for US, Australian, Canadian, New Zealand and other dollars; "£" for sterling and the Gibraltar, Falklands, Egyptian and other pounds; "₱" for the Philippine and Cuban pesos; "¥" for yen and yuan). How should a sign in an imported file (bank statement, asset spreadsheet) be read?

**Answer:** By the local configuration, in the owner's words: "If £ (or any other symbol, like $ or ₱) has multiple 'meanings' it should use the meaning that is applicable to the local config. so... $ = USD if the org currency is USD, but AUD if org is AUD." A sign is read as the organisation's own currency whenever that currency is written with it; a sign the organisation's currency never uses is refused, with the true reason. The three-letter code is always read. Boxes a person types into stay figures only (D-152).

**Rules out:** Refusing a shared sign that the organisation's own currency uses (D-155); reading "£" only for pounds sterling (D-157).

**Where:** #1004 — `Portal\Core\Currency` (file mode). Related: D-139, D-152, D-153.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
