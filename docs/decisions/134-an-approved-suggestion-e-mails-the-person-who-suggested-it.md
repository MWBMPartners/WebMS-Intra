# D-134 · An approved event suggestion e-mails the person who suggested it

**Date:** 2026-11-11  
**Status:** in force

**Question:** someone who is not signed in and suggests an event on the public calendar is told "You'll receive an
email at the address you provided when it's approved", but no such e-mail was ever sent, and since D-092 an approved
suggestion is visible to members only, so that person cannot see it either. Send the e-mail, or change the sentence?

**Answer:** send it. When an administrator approves a suggestion, the person who suggested it gets a short e-mail at
the address they gave, saying it was approved. Their address is used only for that.

**Rules out:** changing the sentence to promise nothing; using the address for anything else.

**Where:** #859 — `web/_apps/calendar/submit.php` and `web/_apps/admin/calendar/moderate.php`, through `Mailer`.
Related: D-092.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
