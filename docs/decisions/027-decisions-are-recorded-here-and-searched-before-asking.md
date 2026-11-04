# D-027 · Decisions are recorded here and searched before asking

**Date:** 2026-11-04  
**Status:** in force

**Question:** how do we stop the same question being asked twice?
**Answer:** this register, append-only, searched before any question. The rule is
machine-wide, in `~/.claude/CLAUDE.md`, so it binds every project on the device.
**Rules out:** recording a decision only in prose, only in memory, or only in an
issue comment; editing a row instead of superseding it.
**Where:** `docs/decisions/` (this folder), one file per decision, outside any
assistant's folder on purpose; `~/.claude/CLAUDE.md` for the machine-wide rule;
`.claude/CLAUDE.md` and `.OpenAI/CONTEXT.md` carry pointers only;
`tools/audit-checks/check_decisions_recorded.py` is the hard gate; issue #751.

**Refined the same day:** the first version of this put the register in
`.claude/DECISIONS.md`. The owner pointed out that a record inside one
assistant's folder is part of the problem, and chose `docs/decisions/` with one
file per decision, plus the relevant GitHub issue — and asked for regular
maintenance against drift, as a standing rule here and system-wide.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
