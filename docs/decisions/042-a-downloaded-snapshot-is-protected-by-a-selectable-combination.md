# D-042 · A downloaded snapshot is protected by a selectable combination

**Date:** 2026-10-29  
**Status:** in force

**Question:** backups need a download and an upload — how is a downloaded
snapshot protected?

**Answer:** both download AND upload, and for protection a **selectable
combination**: a key the global administrator defines (the default), with
including the portal's own key file as an opt-in.

**Reason:** safest by default, and flexible for somebody moving host.

**Rules out:** only ever using the portal's key file, which would make a
snapshot useless on a new server; and only ever using a typed key, which asks
an administrator to look after one more secret with no choice in it.

**Where:** `DbBackup`; the Backup page.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
