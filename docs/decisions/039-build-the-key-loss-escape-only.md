# D-039 · Build the key-loss escape only

**Date:** 2026-11-01  
**Status:** in force

**Question:** how much of #582 should be built?

**Answer:** the key-loss escape only — #582's "change the encryption key".

**Reason:** losing `enc.key` locks out every two-step user on a working server,
which is the live harm. The no-command-line rebuild is documented as a known
limit, because it only matters to somebody who has already lost the server and
still has the download that `eefa603` shipped.

**Rules out:** building the whole of #582 before release.

**Where:** #581, #582.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
