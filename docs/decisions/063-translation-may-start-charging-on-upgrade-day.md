# D-063 · Translation may start charging on upgrade day, and the changelog says so

**Date:** 2026-11-05  
**Status:** in force

**Question:** migration 251 empties `tblUsers.locale` for every account, because
every row holds the column's own default rather than a choice (D-060). The
**Translate** button reads that same column to decide which language to
translate INTO, and falls back to the browser's language when nothing is stored
(`ContentTranslation::readLanguage()`). So today it asks for English, sees
English, and charges nothing. Afterwards, for any member whose browser asks for
something other than English, it would ask for that language, really translate,
and really charge the organisation — on the day the upgrade runs, with nobody
having pressed anything new. Fall back to English instead, let it start
translating and say so, or hold the migration?

**Answer:** **let it start translating, and say so in the changelog.** The
owner, 5 November 2026.

**Why.** It is what the feature is for: somebody whose browser asks for French
genuinely wants French, and offering them a translation they can read is the
whole point. Falling back to English would have meant a member on a French
browser is offered no translation they can read until they use the language
menu once.

**What this costs, said plainly.** An organisation that switched translation on
months ago and has been charged nothing starts being charged on upgrade day,
having changed nothing, with the only warning in a changelog they may not read.
Their monthly money limit and the translation count (D-059) stop runaway
spending; neither stops the surprise. The exposure is bounded: translation ships
switched off (`translation.enabled` = `0`) AND each member must switch it on for
their own account, so this reaches only organisations that deliberately turned
it on.

**Rules out:** making the Translate button fall back to English where no
language is stored; holding migration 251 back; and any document saying "nothing
anybody sees changes on the day this ships", which was written before this
consequence was found and is untrue in two ways (the other is D-062).

**What this does NOT settle:** whether the organisation should be TOLD, on the
page, that translation has begun costing them — that is worth considering and
belongs with #762's work on what the limits say.

**Where:** #763, migration 251, `web/_core/ContentTranslation.php`,
`CHANGELOG.md`. Related: D-059, D-060, D-062, D-049, D-013.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
