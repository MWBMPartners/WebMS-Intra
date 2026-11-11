# D-136 · Moderators' notes and "who did what" numbers are for signed-in administrators only

**Date:** 2026-11-11  
**Status:** in force

**Question:** after #968, the events detail answer gives an event's private moderation note (`moderationNote`) and
the account numbers of who created, edited, submitted, moderated and changed its status only to whoever may manage
events. The handler counted an API key holding `events:write` (a connected outside system that can create, edit and
delete events) as such. Should a connected service also read the moderators' notes and those account numbers?

**Answer:** no — people only. Only administrators signed in to the portal read moderators' notes and the
"who did what" numbers. A connected service with a write key can still create and edit events, but never receives
them.

**Rules out:** giving those fields to any API key, whatever its scope.

**Where:** #968 — `web/_apps/events/api/detail.php` (and `/api/v1/events/{id}`), and `web/_core/api-spec.json`.
Related: D-134 (a visitor's address is used only for the approval e-mail).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
