# D-137 · A failed sign-in keeps the name that was typed, readable, in the activity log

**Date:** 2026-11-11  
**Status:** in force

**Question:** when somebody fails to sign in, the activity log records the sign-in name they typed. That name is
often a real person's e-mail address, and an erasure cannot find it, because a failed attempt is not tied to any
account. #933's first build replaced the typed name with a fingerprint (a scrambled code that matches the same name
again but cannot be read back). Should the log keep the readable name, or only the fingerprint?

**Answer:** keep the readable name. An administrator looking into repeated failed sign-ins needs to see which name
was being tried.

**Rules out:** storing only a fingerprint of the typed name for a failed sign-in, and changing how the sign-in
limit counts attempts (it goes on matching the readable name, as before #933).

**Where:** #933 — the failed sign-in record written from `Portal\Core\Auth` and `Portal\Core\RateLimiter`.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
