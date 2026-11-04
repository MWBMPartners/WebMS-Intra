# D-023 · Stop a container, never force-remove it

**Date:** 2026-11-03  
**Status:** in force

**Question:** is `docker run --rm` enough?
**Answer:** no. Measured: `--rm` plus `docker rm -f` left an anonymous volume
behind; `docker stop` left nothing. So **stop** a container, and read the
dangling count afterwards. An agent must not try to remove its own leftover
volume — the classifier refuses it — but must report the id.
**Rules out:** treating `docker rm -f` as equivalent to `docker stop`; assuming
`--rm` alone leaves nothing behind; an agent reaching for `prune` or trying to
remove a volume itself.
**Supersedes in part:** D-008.
**Where:** `~/.claude/CLAUDE.md`, `.claude/CLAUDE.md`.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
