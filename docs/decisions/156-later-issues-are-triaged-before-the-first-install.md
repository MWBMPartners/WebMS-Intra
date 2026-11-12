# D-156 · Issues raised after 5 November are triaged: only the risky ones are fixed before the first install

**Date:** 2026-11-12  
**Status:** in force

**Question:** D-055 clears the backlog as it stood on 5 November before the first release, and says issues raised
later are "judged on their merits". Since then about 150 follow-up issues have been raised by checks, faster than work
closes them, so clearing every one would leave no reliable release date. How should those later issues be handled?

**Answer:** triage. Before the first install, fix only a later issue that is a security, privacy, money or data-loss
fault, or something a member or administrator would see go wrong. Every other later issue (test gaps, tooling,
wording in code or developer documents, rare corners with no visible harm) is deferred to after the first install: it
keeps its issue and is labelled as deferred, never left in prose.

**Rules out:** clearing every later issue before the first install; deferring every later issue regardless of risk.

**Where:** the release queue; the issue tracker (label `status: deferred` plus a comment naming D-156 on each
deferred issue); `.claude/HANDOFF.md`. Related: D-055 (unchanged for the 5 November backlog), D-041.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
