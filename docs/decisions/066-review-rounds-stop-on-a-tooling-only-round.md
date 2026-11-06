# D-066 · Review rounds stop on the first tooling-only round

**Date:** 2026-11-06
**Status:** in force

**Question:** ten rounds of independent checking have run on #777, and round 10
still found four real faults in the portal itself — including one where a legal
time somebody could type was accepted and stored up to 59 minutes short,
silently, where it had been an honest refusal. Three of that round's seven
findings were in the checking scripts rather than in the portal. Should the
rounds continue until one finds nothing real, stop now, or stop on a test?
**Answer:** **keep going, and stop on the first round whose findings are ALL in
the checking scripts, the tests or the written notes rather than in the portal.**
That round is the last one, and whatever is outstanding at that point becomes its
own issue.
**Rules out:** stopping on judgement ("this feels finished"); stopping now with
concerns unrecorded; and running without any cap at all.
**Why:** this is D-051's protected arm (stored data, a public form, a live API)
joined to D-064's rule, expressed as a test instead of as a feeling. Rounds 3 to
10 each found something real in the portal, so a stop decided by mood would have
landed in the wrong place; but nearly half of round 10's findings were in the
guards, which is exactly the signal D-064 was written for. #763 is the warning on
the other side: eight rounds, 52 findings, every one after the first in the guard
rather than in the fix.
**Supersedes:** nothing. It narrows how D-051's protected arm ENDS, and leaves
what it protects untouched.
**Where:** #777, from round 11 onwards. The judgement "all in tooling" is
recorded in each round's report, so the stop is visible.
