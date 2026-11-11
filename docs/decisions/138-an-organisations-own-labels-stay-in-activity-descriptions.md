# D-138 · An organisation's own labels stay in activity-log descriptions

**Date:** 2026-11-11  
**Status:** in force

**Question:** #933 removes personal details (a provider's e-mail address, a person's full name) from the
plain-language descriptions in the activity log, because an erasure cannot reach text inside a description. Some
descriptions also name things the ORGANISATION itself made up — the name of a group, a role, a rota or an event.
Should those labels be removed as well?

**Answer:** keep them. They describe the organisation's own records, not a person, and without them the log stops
saying what was changed.

**Rules out:** stripping an organisation's own labels from activity descriptions as part of #933. A label that is
in fact a person's name, typed into such a field, is not covered by this decision.

**Where:** #933 — the descriptions passed to `Logger::activity()` across `web/_apps/` and `web/_core/`, and its audit
check.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
