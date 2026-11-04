# D-048 · The three safety settings are added to `~/.claude/settings.json`

**Date:** 2026-11-04  
**Status:** in force

**Question:** three safety settings had been recorded as "DONE and PROVED" on
5 October 2026 and were measured ABSENT on 1 November (#678): deny rules for
force-push and `git reset --hard`, deny rules for `docker volume prune` and
`docker system prune`, and `CLAUDE_CODE_MAX_CONCURRENT_SUBAGENTS=6`. Should they
be added?

**Answer:** **yes, all three.** The owner's words: *"Add all three"*. The file
now holds a `permissions.deny` block of twelve entries — force-push in four
forms, `--force-with-lease` in two, `git reset --hard` in two, and each `prune`
command in two — and an `env` block setting
`CLAUDE_CODE_MAX_CONCURRENT_SUBAGENTS` to `6`.

**Rules out:** leaving the backstop missing while the written rules carry the
work alone.

**What it does NOT do, which is the part that matters:** it does not change
which rule binds. The WRITTEN rules are still the boundary, for three reasons
that were already on record before this: a deny rule cannot catch every form of
a command (`bash -c '…'`, `/usr/bin/git …`), **it does not bind Codex at all**,
and a file can change under you. The settings are a backstop behind the rule,
never a replacement for it.

**How it must be established, and how it must NOT be:** by opening the file and
reading it. **Never by running one of the commands to see whether it is
refused** — that is destructive if the rule does not hold, and a test you must
not repeat is not a test.

**Where:** `~/.claude/settings.json` (outside this repository);
`.claude/CLAUDE.md` and `.OpenAI/CONTEXT.md` describe it.

**Issue:** [#678](https://github.com/MWBMPartners/WebMS-Intra/issues/678).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
