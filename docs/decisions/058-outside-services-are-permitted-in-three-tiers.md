# D-058 · An outside service is permitted in three tiers, and the strictest wins

**Date:** 2026-11-05  
**Status:** in force

**Question:** every page of the portal fetches a picture from `gravatar.com`,
at an address built from a scramble of the member's e-mail (#778). That is not
anonymous — Gravatar's own business is matching that scramble to a person — so
an outside company learns who is looking, from where, on what, and when. There
is no setting to switch it off. Should it be dropped, switched off by default,
or fetched and stored by the portal?

**Answer:** none of those three. The owner, 5 November 2026, chose a general
structure instead:

> *"Make this configurable, whereby a global admin (at installation, or
> organisation level) can set which of each are 'allowed', or default
> behaviour. An organisational global admin cannot contradict a
> limit/enablement/disablement set by the installation global admin. A user can
> then set their own preferences, which also cannot contradict any
> limit/enablement/disablement set by their organisation (or installation).
> If a user is part of multiple organisations (possible), then any user config
> must match and not exceed any limit set by any of their associated
> organisations"*

**The rule, stated so it can be implemented:**

1. **The installation decides what is allowed at all**, and the default.
2. **An organisation may TIGHTEN that and never loosen it.** This is D-013's
   rule for limits, applied to a permission: the two administrators may be
   different parties, so an organisation holding itself to less is its own
   business, and holding itself to more is not.
3. **A member may tighten it again, for themselves, and never loosen it.**
4. **A member in SEVERAL organisations is held to the strictest of all of
   them.** Not the organisation they happen to be looking at — *every* one they
   belong to.

So the effective answer is the strictest of: the installation, every
organisation the member belongs to, and the member's own choice.

**Why point 4 is the one to get right.** It is the only part that cannot be
worked out from the page being viewed. A member reading organisation A's page
is still a member of organisation B, and B's refusal is about THEM, not about
A's content. Resolving this per-request from the current organisation would
quietly leak for exactly the people most likely to object — the ones who joined
a second organisation that said no.

**It is NOT only about Gravatar.** The owner's words are "which of each are
allowed", so this is the shape for outside services generally. Anything that
sends something about a member to another company belongs under it.

**Rules out:** dropping Gravatar outright, and a single on/off switch at one
level. It also rules out "the organisation currently being viewed decides",
for the reason in point 4.

**What this does NOT settle:** which services are listed, what each default
is, where a member sets their own preference, and whether a change is recorded
in the activity log. Those belong in the build plan on #778.

**Where:** #778. Related: D-013 (for a limit, the installation's value wins),
and #623 (nothing under `web/` may ask another website to draw a QR code), which
is the same rule for a different outside request.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
