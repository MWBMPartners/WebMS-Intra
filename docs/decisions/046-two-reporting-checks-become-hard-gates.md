# D-046 · Two checks that only printed a problem now stop the work

**Date:** 2026-11-04  
**Status:** in force

**Question:** two audit checks printed a finding and still exited 0, so nothing
stopped a push or a merge — `check_bind_param_arity.py` (a page that tells the
database the wrong number of values dies with an HTTP 500 for every visitor) and
`check_fk_references_unique_key.py` (an upgraded database not getting an index
it needs). Both were printing exactly one complaint, and both complaints were
measured FALSE. Once those are fixed, should the two checks REFUSE rather than
report?

**Answer:** **yes — make both stop the work.** The owner's words: *"Make both
stop the work (recommended)"*. With the two false complaints fixed each prints
nothing, so becoming a hard gate costs nothing on the day it is done, and a real
finding later stops the push instead of scrolling past. This is how the other 58
scripts in `tools/audit-checks/` already behave.

**Rules out:** leaving either as a printed note only; and the half-way option of
gating only the arity check. It also rules out gating them BEFORE the false
complaints are fixed, which would have blocked every push.

**Accepted cost, stated because it is real:** if either check ever produces a
NEW false complaint, work stops until somebody fixes it. That is the known cost
of a hard gate and is the opposite failure from #483's "a check that cries wolf
gets switched off" — which is why the false complaints were fixed first, in the
same package.

**Where:** `tools/audit-checks/check_bind_param_arity.py` and
`tools/audit-checks/check_fk_references_unique_key.py`; named in
`.github/workflows/pr-security.yml`.

**Issues:** [#730](https://github.com/MWBMPartners/WebMS-Intra/issues/730) and
[#747](https://github.com/MWBMPartners/WebMS-Intra/issues/747).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
