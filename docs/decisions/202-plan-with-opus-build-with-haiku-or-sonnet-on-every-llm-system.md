# D-202 · Plan with the strongest tier, build with a cheaper tier, check with the strongest tier — on every LLM system

**Date:** 2026-11-16  
**Status:** in force

**Question:** which model tier should do which kind of work, to use the credit allowance efficiently?

**Answer:** the owner decided, for the whole queue, this project and every project on this device:
- **Planning and design: Opus** (or the other system's strongest tier).
- **Building: Haiku or Sonnet**, whichever fits the task (Haiku for mechanical edits; Sonnet for ordinary building), being efficient with tokens and credit.
- **Checks and reviews by Claude: always Opus** (D-186 stands; building never uses a weaker tier than a check would).
- **Other systems follow the same shape:** Codex (strongest model for planning and review, a cheaper one for building) and local models such as Ollama (cheapest suitable model for building; never the only reviewer of risky work). Model names change; the rule is about tiers.
- The fable model stays excluded for this project.

**Rules out:** using the strongest tier for routine building; using a cheaper tier for a check or review.

**Where:** `.claude/CLAUDE.md`, `.OpenAI/CONTEXT.md`, `~/.claude/CLAUDE.md`, memory. Agent types: `dev-team:opus-builder` plans and checks; `dev-team:sonnet-builder` / `dev-team:quick-edits` build.

**Supersedes:** nothing.

**Related:** D-186 (checks always on Opus) stands unchanged beside this.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
