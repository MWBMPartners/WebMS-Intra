# WebMS-Intra: goals, requirements and prerequisites, plan pointers

*The project's goals and prerequisites, in one place, written so any person or LLM platform can pick the project up from the
files alone (D-214). The CURRENT state is in `.claude/HANDOFF.md` (one screen, overwritten); the record of what is and is not
done is GitHub Issues and Milestones; decisions are `docs/decisions/`; what shipped is `CHANGELOG.md`. Update this file when a
goal, a requirement or a plan changes, at the time it changes.*

## Goals

- An internal "portal" web platform for an organisation (first user: a non-profit congregation), PHP 8.5 (8.4 compatible),
  MySQL 8.0/8.4 and MariaDB, on shared hosting with no command line and no Composer. Product name is chosen at install.
  Full original brief: `.claude/ProjectBrief_Chat.claude`; feature inventory: `FEATURES.md`.
- **Near term (D-205): a pilot install for the owner's own organisation**, one organisation, with the paid outside services
  (AI, translation, transcription, text messages, online payments) switched off (D-207). The full backlog (D-055) and the
  full Codex review (D-007) still apply before any release to anybody else.
- **Done means:** installable from the downloadable zip by someone with only a hosting panel; upgrades work; every personal
  detail has a deletion and an owner-visible copy; nothing is sent to an outside company without being named and permitted.

## Requirements and prerequisites

- **Hosting:** PHP 8.5 (minimum 8.4), MySQL 8.0 or 8.4 or MariaDB 10.6+ (a warning shows for versions past end of life),
  Apache with `.htaccess`, a scheduler able to call the `cron/` addresses (the mail queue job is the one that matters first).
- **Pilot state (17 November 2026):** the live pilot zip `webms-intra-1.4.1-live-a0ab6b67.zip` is built and was rehearsed three times locally; it waits on the real-host rehearsal (see the handoff).
- **To install the pilot:** a spare hosting subdomain and an empty database, the installation zip built from a named commit
  (`tools/package/build.sh`), the installer's ownership code, and `docs/INSTALL.md` followed word for word.
- **To build or check:** Docker (named throwaway containers), `gh`, Python 3, PHP 8.5, node 22+, Edge or Chrome for the
  browser self-tests; every check is run by a different model from the one that built it (checks always on Opus, D-186;
  Claude plans on Opus and builds on Sonnet or Haiku, D-202; the same tier shape for Codex and Ollama).
- **Before the pilot:** the whole suite, the migration harness on every engine, the zip built on a clean worktree, an install
  rehearsal (local, then the spare subdomain) and an upgrade rehearsal; review rounds capped at three per package (D-204).
- **Standing rules:** `.claude/CLAUDE.md` (the source), mirrored for other platforms in `.OpenAI/CONTEXT.md`.

## Plans

- **Now:** `.claude/HANDOFF.md`, "Next, in this order".
- **First install:** the pilot plan (verify, rehearse, install) is summarised in the handoff; its working copy was
  `/tmp/claude-501/firstinstall/plan.md` (a scratch path, may be gone).
- **Rollout to a congregation:** `docs/rollout-plan.md` (two phases; reviewed 2026-05-31, review it before the pilot becomes a rollout).
- **After the pilot:** the backlog (D-055), the Codex review (D-007), then the merge to `main`.
- Older plans are in `.claude/history/plans/` with a terminal line each.
