# D-061 · The language picker is its own piece of work

**Date:** 2026-11-05  
**Status:** superseded  
**Superseded by:** [D-062](062-the-language-menu-exists-and-the-account-page-needs-one-too.md)

> ⚠️ **This decision was taken on a premise that is FALSE.** Its question
> asserted that no control for choosing a language exists anywhere in the
> portal. There is a language menu in the top bar of every page — established
> with `grep -rn languageSwitcher web tools`. The answer below is left exactly
> as it was recorded, because a decision is never edited; D-062 is the answer
> in force. This note is the only addition, and it exists because the
> register's shape check requires a superseded row to say so, in both
> directions.

**Question:** there is no control anywhere in the portal for choosing a
language — no menu, no button, nothing on the account page. The only way in is
to type `?lang=cy` on the end of an address by hand. Should building that
control be part of #763, or its own piece of work?

**Answer:** **its own issue, after the release queue.** #763 fixes the
plumbing so a choice sticks when one can be made; the control itself is
tracked separately. The owner, 5 November 2026.

**Why.** Until that issue is built the portal still shows everybody the
language their browser asks for, which is what it does today — so nothing is
worse than now, and the saved-language machinery is in place and proved. The
alternative added roughly half a day and touched the account pages and the
page header, which other queued work also touches (D-012: packages that share
files do not run side by side).

**Rules out:** building a picker inside #763. It does NOT rule out the
`?lang=` address continuing to work — that is the one route there is, and
#763 makes it actually save the choice.

**What this costs, said plainly:** a feature that is ready and unreachable.
`tblUsers.locale` can be written by `?lang=`, and nothing in the interface
offers it, so in practice only somebody who reads this record will ever use
it until the follow-up lands.

**Where:** #763 for the plumbing; the follow-up issue for the control.
Related: D-060.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
