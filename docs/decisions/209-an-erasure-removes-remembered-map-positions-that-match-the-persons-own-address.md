# D-209 · An erasure removes remembered map positions that match the person's own address

**Date:** 2026-11-17  
**Status:** in force

**Question (#1067):** the portal remembers the answer of an address look-up so it does not ask twice (D-183). A request to erase a person could not remove a remembered position even when it was their own home address. What should an erasure do?

**Answer:** the owner chose: when erasing a person, also delete any remembered map position whose address matches an address held for that person.

**Rules out:** leaving a remembered position of somebody's home behind after an erasure; the alternative of never remembering home addresses.

**Where:** #1067 (`GdprEraser`, the look-up cache in `Geocoder`, the personal-data catalogue).

**Supersedes:** nothing.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
