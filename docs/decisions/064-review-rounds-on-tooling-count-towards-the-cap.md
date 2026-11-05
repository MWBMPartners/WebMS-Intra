# D-064 · A review round that finds faults only in tooling counts towards the cap

**Date:** 2026-11-05  
**Status:** in force  
**Supersedes in part:** [D-051](051-check-rounds-cap-at-three-except-money-and-privacy.md)

**Question:** D-051 caps independent review at three rounds for ordinary work
but runs them **until a round is clean** for anything touching money, personal
details, authentication, erasure, backups or stored data. #763 is on that
protected arm, and it ran to **eight rounds and 52 findings — every one after
the first round in the GUARD I had built around the fix, and not one in the fix
itself**, which every round confirmed was correct. Should the rule tell those
two things apart?

**Answer:** **yes.** A round whose findings are all in checks, tests or tooling
— not in the code that touches a customer's data — counts towards the
three-round cap, exactly as presentation or documentation work does. The
protected arm is unchanged for anything that writes, deletes or exposes data.
The owner, 5 November 2026.

**How to apply it, so this is not a loophole.** Judge each ROUND by where its
findings landed, never by where the package started. A round that finds one
fault in the data-touching code is a protected-arm round however many tooling
faults it also found, and the counter does not advance. A round entirely in a
check, a self-test, a guard script or a document advances it. **Anything
outstanding when the cap is reached becomes its own issue**, named in the commit
message, and **the commit says which arm applied and why** — because the honest
version of this rule is that somebody chose.

**Why, with the instance.** #763's fix is about sixty lines. The guard I built
around it reached roughly 1,100 lines of Python and PHP, and the review rounds
went 12, 7, 6, 6, 3, 3, 6, 9. By the eighth round it still refused correct code
— a passing comment that merely mentioned the guarded call made it report the
pin "worthless", and an ordinary helper added above the region made it report
that the region had "moved". **A check that cries wolf gets switched off
(#483), so those rounds were not even buying protection.** D-051's own reason
for the protected arm — that a fault in money or personal data is the kind you
cannot take back — does not apply to a script that only ever prints a warning.

**Rules out:** running a round to clean because the PACKAGE touches stored data
when that round's findings are all in tooling; and reading this as any
relaxation of the protected arm itself, which keeps running until clean.

**What this costs, said plainly:** a guard may ship with a known rough edge,
and a check that cries wolf may survive a cap. Both are written up as issues
rather than fixed, which means somebody has to read the issue list. The
alternative cost is the one just paid: eight rounds on scaffolding while the
queue stood still.

**What it does NOT settle:** whether the guard in question should exist at all.
That is D-065.

**Where:** #763, #781. Related: D-051, D-015, and #483 (a check that cries wolf
gets switched off).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
