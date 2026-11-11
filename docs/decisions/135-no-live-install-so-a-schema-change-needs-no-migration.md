# D-135 · While no customer installation exists, a schema change needs no numbered migration

**Date:** 2026-11-02 (recorded in the register on 2026-11-11, from the assistant's memory record of the owner's words)  
**Status:** in force while no customer installation exists

**Question:** the standing rule (owner, 11 September 2026; `.claude/rules/database.md`) is that every database
change goes in BOTH `web/_sql/full_schema.sql` and a numbered migration, and the two must agree. With no customer
installation in existence, is a paired migration still needed?

**Answer:** no. The owner, 2 November 2026: there is no live, active install yet, so a database migration "isn't
needed for #708 or anything yet — once all this is ready it'd be a clean install". A schema change may go into
`full_schema.sql` alone.

**Rules out:** treating the September rule as binding while no installation exists — and also deleting that rule:
it becomes binding again the moment a customer installs.

**Where:** `web/_sql/`. One reason a migration may still be needed, which the owner's words do not cover: the
end-to-end migration harness upgrades two older databases and requires each to end up equal to a fresh install, so
a change to `full_schema.sql` alone can make it fail. Check the harness rather than assuming either way. Packages
since have mostly kept pairing migrations anyway; nothing here forbids that.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
