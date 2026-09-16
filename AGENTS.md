# AGENTS.md — read this before working on WebMS-Intra

Before doing anything else, read:

1. `.OpenAI/CONTEXT.md` — what this product is and every standing rule it
   runs under, in plain English.
2. `.OpenAI/MEMORY.md` — real bugs and traps already found in this
   codebase, so they are not found a second time.
3. `.claude/CLAUDE.md` — the source of truth for the rules above; if
   `.OpenAI/CONTEXT.md` ever disagrees with it, `.claude/CLAUDE.md` is
   right, not this file (`AGENTS.md`) and not `CONTEXT.md`.
4. The top of `.claude/HANDOFF.md` — the current state of the work and what
   to do next.

The three most important rules, in one line each:

- **Write everything in plain, ordinary English** — chat replies, code
  comments, commit messages, issues, pull requests — for a capable reader
  who does not work on this system.
- **Every change is reviewed by a different AI system from the one that
  built it**, and reviewed again after each fix, until the review comes
  back clean.
- **Keep `.claude/HANDOFF.md` current as the work happens**, so any AI
  coding tool can resume the work cold at any point.
