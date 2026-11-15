# D-186 · Any check or review carried out by Claude uses Opus, whatever model is otherwise set

**Date:** 2026-11-15  
**Status:** in force

**Question:** which Claude model should do a check or a review of somebody else's work, when the rest of the
session (or a cheaper helper) runs on another model?

**Answer:** the owner: "if carrying out any checks and reviews using Claude, do so using Opus, regardless of the
otherwise set model", and to make it a standing rule for this repository and for every project on the machine. So:
every independent check, review, red-team pass or verification by a Claude agent runs on Opus, even when the work
it checks was built on Sonnet or Haiku, and even when the session's own model is something else. Building may
still use a cheaper tier; checking never does. This sits beside the older rule that verification is never done by
a weaker model than the build (D-051 and the "Deep analysis" section): it names the model, so a tier name cannot
drift. It does not change the separate rule that Codex reviews before the merge (D-007).

**Rules out:** a check or review by Sonnet or Haiku to save cost; letting the session's default model decide who
checks; counting a builder's own re-read as the independent check.

**Where:** `.claude/CLAUDE.md` and `.OpenAI/CONTEXT.md` (pointers to this file), `~/.claude/CLAUDE.md` (machine
wide), the memory file `checks-and-reviews-use-opus`, and every brief written for a checker (the `dev-team:opus-builder`
agent type is Opus). Related: D-051, D-007.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
