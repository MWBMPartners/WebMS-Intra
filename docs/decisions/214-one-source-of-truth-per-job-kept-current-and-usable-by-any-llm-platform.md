# D-214 · One source of truth per job, kept current at every stage, and usable by any LLM platform

**Date:** 2026-11-17  
**Status:** in force

**Question:** (standing rule, this repository and every project on the development machine) how should the handoff, the project documents, the repository tracker, the documentation and the API description be kept, so that work can switch between models and platforms accurately and efficiently?

**Answer:** the owner decided: one handoff plus project documents for goals, requirements and prerequisites, and plans, all kept current as the work happens and written so any LLM platform (Claude, Codex, Ollama, others) can resume from the files; the repository's issues and plans (milestones, projects) are fully up to date and are the source of truth for what is done, so work can continue from any device; all project documentation (Markdown, in-app help and guides, wikis) is kept up to date as the work goes; and a web application that offers an API keeps its Swagger/OpenAPI description current and provides a browsable Swagger UI area for API users (which may be restricted to them). Full wording: `.claude/CLAUDE.md`, "One source of truth per job".

**Rules out:** a handoff per tool; a handoff or plan updated only at the end of a stretch; a tracker that lags the work; documentation left for a later sweep; an API description or viewer that has drifted from the code.

**Where:** `.claude/CLAUDE.md`, `.OpenAI/CONTEXT.md`, `AGENTS.md`, `docs/PROJECT.md` (new: goals, requirements and prerequisites, plan pointers), `~/.claude/CLAUDE.md`, `~/.codex/AGENTS.md` and the neutral machine-wide file `Build Infrastructure/Config/standing-rules.md`.

**Supersedes:** nothing.

**Related:** D-202 (model tiers), the older "one job per document" rule (#678), D-027 (the decision register).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
