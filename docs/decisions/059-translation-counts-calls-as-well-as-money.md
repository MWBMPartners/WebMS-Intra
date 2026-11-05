# D-059 · Translation counts calls as well as money

**Date:** 2026-11-05  
**Status:** in force

**Question:** the monthly translation budget counts what each provider reports
spending. LibreTranslate always reports zero, so on that route the budget can
never stop anything — somebody could translate without end on a limit of £1 —
and it ships pointing at `libretranslate.com`, which charges (#762). Say so on
the page and ship pointing nowhere; count the number of translations as well;
or record a guessed cost per call?

**Answer:** **count the number of translations as well.** A second limit that
works whatever a provider reports. The owner, 5 November 2026.

**Why this one rather than the cheaper options.** It is the only one that
actually STOPS runaway use. Saying so on the page is honest but changes
nothing — an organisation reading "this limit does not apply to this provider"
still has no limit. A guessed price is worse than no price: a budget measured
in money that is not the real money is a budget that misleads, and somebody
will plan against it.

**Rules out:** the recommendation (a warning on the page plus shipping the
address empty) as a COMPLETE answer, and inventing a nominal cost per call.
Neither is forbidden as an addition — the honest sentence on the admin page is
worth having either way — but neither is the answer on its own.

**What this costs, said plainly:** an organisation now has two limits to
understand, money and count, and either can stop a translation. The page must
say WHICH one stopped it, or somebody will raise their budget and find nothing
changes.

**What this does NOT settle:** the default count, whether it is per
organisation or per installation (D-013 says the installation's figure caps the
organisation's either way), and what the member is told when the count rather
than the money is what refused them. Those belong with the build.

**Where:** #762. Related: D-013, D-049, and #765 (spending is now counted per
organisation, so the count must be too).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
