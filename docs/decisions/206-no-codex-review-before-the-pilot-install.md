# D-206 · No Codex review before the pilot install; Claude (Opus) checks carry it

**Date:** 2026-11-17  
**Status:** in force

**Question:** D-035 starts the second-system (Codex) review only after all release work. The branch is about 316,000 lines (roughly 40 Codex allowances); the core that handles sign-in, erasure, money, backups and the installer is about 37,000 lines (roughly 5 allowances). What should Codex review before the pilot install?

**Answer:** the owner chose: nothing. The pilot runs on code checked by independent Opus agents (D-186, D-204); Codex reviews before any release (D-007), and every commit continues to say Codex has not reviewed it. This was NOT the recommended option (a scoped review of the core): the owner chose speed knowingly.

**Rules out:** claiming the pilot code has had a second-system review; skipping Codex before any release.

**Where:** commit messages ("Codex has not reviewed this"), `.claude/HANDOFF.md`.

**Supersedes:** nothing.

**Related:** D-035 and D-007 stand for the release.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
