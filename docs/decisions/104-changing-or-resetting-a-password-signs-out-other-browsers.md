# D-104 · Resetting or changing a password signs the person out of every other browser

**Date:** 2026-11-10  
**Status:** in force

**Question:** if someone resets a forgotten password, or changes their password while signed in,
anyone already signed in as them elsewhere — another browser, another phone, possibly someone who
stole the password — stays signed in (#598 item 1). Which of these should sign out every OTHER
browser where that person is signed in?

**Answer:** **both.** A forgotten-password reset AND a signed-in password change sign the person out
everywhere else; the browser making the change stays signed in. The owner, 10 November 2026,
choosing the recommended option.

**Rules out:** a reset alone signing others out; neither doing so (today's behaviour).

**Where:** #598 item 1 — `reset-password/save.php`, `auth/account/change-password.php`,
`Auth::beginSessionFor()`, `Auth::endSessionOfInactiveAccount()`.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
