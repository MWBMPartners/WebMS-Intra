# D-198 · Approving a suggestion that was postponed earlier shows the same warning and needs the same box

**Date:** 2026-11-16  
**Status:** in force

**Question:** D-197 shows a warning (and needs a ticked box) before a suggestion that was cancelled earlier is approved. Some suggestions were marked Postponed while still waiting for approval, before that was blocked (#965); members saw them as postponed. Should approving one of those show the same warning and need the same box (#1081)?

**Answer:** the owner chose yes. Approving a suggestion that was cancelled OR postponed earlier shows the warning and needs the ticked box. D-197's rule is widened to name both statuses; the warning's words say which.

**Rules out:** approving a once-postponed suggestion silently, so that members who were told it was postponed see it published as going ahead as planned.

**Where:** #1081 (`EventSuggestions::WARN_IF_EVER_STATUSES`, the moderation page, a migration marking waiting suggestions that are postponed today). Related: D-169, D-196, D-197.

**Supersedes:** nothing.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
