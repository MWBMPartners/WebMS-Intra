# D-056 · Portal content reaches a public website all three ways

**Date:** 2026-11-05  
**Status:** in force

**Question:** #493 wants content created in the portal — the Noticeboard first —
to appear on the organisation's own public website, which is usually WordPress,
Wix or Squarespace and which this project cannot deploy to. The customer has to
add something to their site by hand, once. Which should be the main way: a
script they paste in, a frame they paste in, or a feed their website reads?

**Answer:** **all three.** The owner, 5 November 2026: *"support all of the
above options/methods, for wider use-cases/compatibility. Security first in
mind as well of course!"*

**What each is for, so the three do not collapse into one badly:**

* **A script they paste in** — the content appears styled as part of their
  page. The pattern already exists here and works (`widget/countdown.js`,
  #319). Best for most customers.
* **A frame they paste in** — one line, works on the restrictive website
  builders that forbid custom scripts. Looks like a box in their page and is
  awkward on a phone, so it is the fallback rather than the first offer.
* **A feed their website reads** — the best result, because the content is
  genuinely part of their page and fully visible to search engines and social
  previews. Needs somebody who can edit their website's code.

**Rules out:** picking one and copying it badly three times later, which is
what #493 warns against. It also rules out treating the frame as the headline
answer: it is named here as the compatibility fallback.

**"Security first" is part of the answer, not a pleasantry.** Three public
surfaces are three ways in, onto a database that also holds pastoral care
notes, safeguarding records, children's medical details and the member
directory. They must share ONE gate rather than each carrying its own — this
project has twice made an unreachable page reachable and found it had no access
check at all, and three separately-written surfaces is that risk tripled. The
existing frame pattern uses `X-Frame-Options: ALLOWALL`, which is **not a real
value** and works only because browsers ignore what they do not recognise; it
wants replacing, not copying.

**Where:** #493. The build plan goes on that issue before any code is written,
as the issue itself requires.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
