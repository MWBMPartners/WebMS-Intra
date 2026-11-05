# D-060 · An untouched stored language counts as no choice

**Date:** 2026-11-05  
**Status:** in force

**Question:** a member's saved interface language was applied nowhere (#763),
because the question is asked before their session exists. Fixing that makes
`tblUsers.locale` start deciding what language every signed-in person sees —
and **every account row holds `'en'`, which the database put there as the
column's default rather than anybody choosing it.** So once the column is
obeyed, what should happen to an account still holding that untouched `'en'`:
treat it as "not chosen", let the stored value always win, or count only a
choice made in the current visit?

**Answer:** **treat an untouched `'en'` as "not chosen".** A one-off database
change empties the column where it still holds the default, and new accounts
start empty; an account with nothing stored keeps following the browser's own
`Accept-Language` request, exactly as every page does today. The owner,
5 November 2026.

**Why, and the measured fact that makes it safe.** Nothing under `web/` has
ever been able to write that column. The only writer is
`I18n::switchLocale()`, which stores nothing unless a session is active, and
its one and only caller has been in `bootstrap.php` — before the session
starts — since the framework landed. Established with
`git log -S"switchLocale" -- web/`, which answers two commits: `fe2b3fb`
(7 March 2026, the original) and `bd1ef12` (the `?lang[]=` type fix). So every
`'en'` in that column is the schema default, and emptying it discards no
choice anybody made.

**What the rejected options would have cost somebody.** Letting the stored
value always win pins **every signed-in member to English** from the day it
ships, because that is what their row says — so somebody whose browser asks
for French, and who gets a French portal today, starts getting an English one,
with nothing on any page explaining why and no control anywhere to change it.
Twelve languages are matched from the browser's request on every installation
(`I18n::isSupported()` reads the built-in list, not a setting), so that is a
visible regression rather than a theoretical one. Counting only the current
visit leaves the column as dead weight and loses a choice overnight.

**Rules out:** keeping `DEFAULT 'en'` on `tblUsers.locale`; reading the column
without first distinguishing "chose English" from "never chose". It does NOT
rule out a member genuinely choosing English — once the control exists
(D-061), that choice is stored and wins over the browser like any other.

**What this costs, said plainly:** if an account somehow did hold a genuine
choice of English, that choice is forgotten and the browser decides again. No
route by which the portal could have made such a choice was found, and the
command above is how that was established rather than assumed.

**Where:** #763, migration 251, `web/_sql/full_schema.sql`,
`Portal\Core\I18n::reconsiderNowSessionExists()`. Related: D-061, and
`ContentTranslation::readerLanguage()`, whose docblock recorded this fault and
deliberately did not fix it.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
