# D-012 · Packages that share no files may run side by side

**Date:** 2026-10-12  
**Status:** in force

**Question:** how can the work go faster?
**Answer:** a package may be planned, built and checked while another is in
flight, **as long as the two change no file in common**.
**Rules out:** two packages touching one file at once; two analysis judgements at
once.
**Where:** `.claude/CLAUDE.md` → "Deep analysis".

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
