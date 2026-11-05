# D-053 · The time-zone pass is in the first release; the mobile pass is not

**Date:** 2026-11-05  
**Status:** in force

**Question:** two of the three standing audit passes were not named in D-041.
#549 checks that every time the portal shows, stores or compares respects its
time zone and survives the two nights a year when the clocks change. #225 is a
deliberate pass over the nine main things a person does, on a phone-sized
screen. Which must be finished before the first release?

**Answer:** **#549 is in the first release. #225 is deferred, with its issue
left open.** The owner, 5 November 2026.

**Rules out:** deferring both, and doing both. The reasoning offered for #549
was that the portal is a diary, so an hour wrong is a congregation arriving at
a locked building rather than a cosmetic fault — and three such faults have
already been found by checking rather than by anything visibly breaking.

**What deferring #225 costs, said plainly, because this is the half that was
NOT chosen:** most members will open this on a phone. If one of the nine main
tasks cannot be completed on a small screen, that member cannot use the portal
at all, and it will read as the product not working rather than as a layout
problem. The browser/visual half of #569 IS in the release (D-041), so pages
will be looked at — but #569's pass is about whether a page looks finished,
not about whether a task can be completed at phone width, and neither stands
in for the other.

**Where:** #549 (in), #225 (deferred and labelled); the release queue.

**Supersedes in part:** D-041, which named the browser/visual half of #569 and
was silent on these two. D-041 otherwise stands.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
