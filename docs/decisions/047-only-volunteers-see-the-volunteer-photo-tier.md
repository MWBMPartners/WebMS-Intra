# D-047 · Only volunteers may see the volunteer photo tier

**Date:** 2026-11-04  
**Status:** in force

**Question:** photo albums have tiers deciding who may see what. One tier is
meant for volunteers. The code lets ANY signed-in member reach albums shared at
that tier, while the comment beside it says only volunteers should. One of the
two is wrong — which was intended?

**Answer:** **only volunteers.** The owner's words: *"Only volunteers may see
them"*. So the comment was right and the code was wrong, and the code changes to
match.

**This is a BEHAVIOUR CHANGE, not a tidy-up, and the owner was told so before
choosing.** Members who can see photos at that tier today stop being able to. On
an installation already in use that is visible to the people using it.

**Rules out:** correcting the comment to match the code (the other way round),
and leaving the contradiction recorded but unresolved.

**Where:** the `volunteer` rung in `web/_core/Photos.php`, and #756, which
records that the rung is currently a NO-OP — both branches return the same rank,
so today a switch for it would open nothing.

**Issue:** [#756](https://github.com/MWBMPartners/WebMS-Intra/issues/756).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
