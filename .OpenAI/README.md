# .OpenAI/ — context for Codex and other OpenAI-based agents

This folder is the OpenAI-side twin of `.claude/`. It exists so that Codex (or
any other OpenAI-based coding agent) can pick up this project with the same
background knowledge that Claude Code already keeps for itself — without
having to read Claude's own tool-specific notes to get it.

## Who reads this

Codex, or any other OpenAI-based agent working on WebMS-Intra. If you are
such an agent, read these three files before doing anything else:

1. `.OpenAI/CONTEXT.md` — what the product is, the directory layout, and a
   condensed, plain-English version of every standing rule the project runs
   under.
2. `.OpenAI/MEMORY.md` — the lessons learned on this project so far: real
   bugs found, traps in the codebase, and mistakes already made once and not
   worth repeating.
3. The top of `.claude/HANDOFF.md` — the current state of the work: what is
   in progress, what was decided, and what to do next. It is kept current as
   work happens, not rewritten from nothing each time — older dated entries
   stay further down the file. Always read the newest entry, at the top,
   first.

## How this relates to `.claude/` and the handoff

- **`.claude/CLAUDE.md` is the one source of truth for the rules.** This
  folder's `CONTEXT.md` is a plain-English summary of it, written for a
  reader who does not use Claude Code. Where the two disagree, `.claude/
  CLAUDE.md` is right, and `.OpenAI/CONTEXT.md` should be corrected to match.
- **`.claude/HANDOFF.md` is the one source of truth for "where things are
  right now."** It is assistant-agnostic on purpose — any coding tool,
  Claude or OpenAI-based, can resume cold from it. This folder does not
  duplicate it; read it directly.
- **`.OpenAI/MEMORY.md` mirrors the lessons in Claude's own memory files**
  (kept outside this repository, under Claude Code's own storage), translated
  into a form that means something without any Claude-specific tooling
  context. It leaves out anything that is purely about how Claude Code's own
  tools behave.

## The rule that keeps this folder useful

**Both `.claude/` and `.OpenAI/` are updated after every finished task**, the
same way and at the same time — this is a standing instruction from the
project owner (16 September 2026). A change to a rule, a newly found trap, or
a lesson worth keeping goes into both folders before the task counts as done.
A folder that falls behind the real rules is worse than no folder, because it
looks authoritative and is not.
