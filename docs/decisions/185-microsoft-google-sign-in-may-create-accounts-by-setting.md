# D-185 · Whether a Microsoft or Google sign-in may create a new account is a setting (default: allow), and duplicate accounts can be merged

**Date:** 2026-11-13  
**Status:** in force

**Question:** when somebody signs in with Microsoft or Google and the portal finds no account linked to that sign-in
and no account with their e-mail address, what should happen? Today it makes a new, empty account. After a backup is
restored, a member who linked Microsoft and then changed their e-mail address after the backup looks exactly the same,
gets a second, empty account and loses sight of their own records (#1042).

**Answer:** the owner: "A setting, default: allow. However also needs to have a mechanism to 'merge' such duplicate
accounts, for cleanup." So: a setting decides whether a Microsoft or Google sign-in may create a new account; it starts
as "allow", so nothing changes until somebody chooses; an organisation may choose "no" (the stricter level wins, as
D-013/D-026). And an administrator can merge two accounts that belong to one person, so a duplicate made this way (or
any other way) can be cleaned up. The owner added: "this clarification and stance should also apply to SIGNula.id" —
recorded here; SIGNula.id must record it in its own register when that repository is next worked on.

**Rules out:** refusing every new Microsoft or Google sign-in outright; leaving duplicates with no way to clean them
up; a "refuse only after a restore" period.

**Where:** #1042 (the setting) and the merge feature's own issue. Related: D-013, D-018, D-026.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
