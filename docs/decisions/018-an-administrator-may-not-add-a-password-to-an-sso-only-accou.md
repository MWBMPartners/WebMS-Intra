# D-018 · An administrator may not add a password to an SSO-only account

**Date:** 2026-11-03  
**Status:** in force

**Question:** may an administrator type a password for somebody whose account
signs in only with Microsoft, Google or a passkey?
**Answer:** no, refused. The account's **owner** doing it for themselves is a
different question — #731.
**Rules out:** a second way into an account chosen by somebody who is not its
owner.
**Where:** #586; `_apps/admin/users/`.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
