# D-174 · An error's own words have any e-mail address removed before they are stored

**Date:** 2026-11-12  
**Status:** in force

**Question:** An error line, and sometimes an activity record, copies an error's own words, and a mail server's refusal can quote a person's e-mail address that no erasure can find. How should such lines be written?

**Answer:** Remove addresses when written: before an error's words are stored, any e-mail address in them is replaced with "[address]". The line still says what went wrong.

**Rules out:** Storing an error's words with an address in them; tagging lines for a later erasure instead.

**Where:** #1007, #990 — `Portal\Core\Logger` (the one place error and activity words are stored). Related: #933.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
