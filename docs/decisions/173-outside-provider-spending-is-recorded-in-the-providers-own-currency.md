# D-173 · Outside-provider spending is recorded in the provider's own currency

**Date:** 2026-11-12  
**Status:** in force

**Question:** The portal estimates outside services' costs (AI, translation, text messages, transcription) in pence from US-dollar price lists and compares them with spending limits. Every other money record keeps its own currency (D-139). How should these figures be kept?

**Answer:** Record each cost in the provider's own currency and amount, converting only to compare with limits.

**Rules out:** Keeping the figures as installation-currency estimates.

**Where:** #993 — `tblAiUsage`, `tblTranslationUsage`, `tblSmsMessage`, `tblTranscript`, `tblContentTranslation` and the limit checks. Related: D-139.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
