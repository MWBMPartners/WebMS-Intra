# Project memory — WebMS Intra

**What this file is.** A short, stable set of facts about this project that an
AI assistant should load at the start of every session, so it does not have to
re-derive them. It is committed to the repository on purpose, so it works for
everyone on the team and inside the build server, not just on one laptop.

> **Note on history.** Until 7 September 2026 this file was a *symbolic link*
> pointing at a folder inside one developer's home directory
> (`/Users/…/.claude/projects/…/memory/MEMORY.md`). That link was broken — the
> folder name it pointed at did not exist — so the file had been unreadable to
> every session for some time, and the standing instruction to "keep MEMORY.md
> updated" could not be followed. It is now an ordinary file.

---

## What the product is

An internal portal platform for churches and similar organisations —
announcements, calendar, giving, rotas, safeguarding and around forty other
small apps, all switchable on or off per site. Built and sold by MWBM Partners
Ltd, trading as MWservices. All rights reserved.

The product name is chosen at install time from a set of brand presets
(`WebMS Intra` is the generic default, `ChurchMS` the church one), so the same
codebase ships under different names.

## The constraints that decide every design choice

- **Shared hosting on DreamHost.** No command line on the server, no Composer,
  no Docker, no build step. Anything proposed has to work as plain PHP files
  copied onto the server.
- **PHP 8.5**, staying backward compatible with 8.4.
- **MySQL 8.0 in production.** MariaDB-only SQL syntax — `IF NOT EXISTS` on
  `ADD COLUMN`, `ADD INDEX`, `MODIFY COLUMN` and friends — is a hard error
  there. (`CREATE TABLE IF NOT EXISTS` and `DROP TABLE IF EXISTS` are fine.)
- **Anything fetched from the public internet at page-load time needs a local
  copy to fall back on**, because some customer networks block public content
  delivery networks outright.

## The two traps that catch people out

1. **Paths beginning `api/` never consult the routing table.** `Router.php`
   hands them to `ApiRouter.php`, which finds the handler by naming convention
   at `_apps/{app}/api/{action}.php`. Each one also needs a setting
   `api.{app}.{action}.enabled = 'true'` seeded in the database, or it refuses
   every request with "403 forbidden". Registering an `api/…` path in
   `tblRoutes` does nothing at all.
2. **Every database migration is replayed on an already-complete schema.** The
   installer runs `full_schema.sql` first and then replays every numbered
   migration, ignoring which ones are recorded as done. So each migration must
   be safe to run a second time.

## Where the real answers are

`.claude/CLAUDE.md` is the instruction file, but treat its *counts* with
suspicion — they have gone stale before. Confirm against the code:

| Question | Where the answer actually is |
| --- | --- |
| Which apps exist? | `web/_apps/` (directories) |
| Which apps can an administrator switch on or off? | `web/_core/apps/` (one file per app) |
| What framework classes exist? | `web/_core/*.php` |
| What does the database look like? | `web/_sql/full_schema.sql` + numbered migrations |
| What version is this? | `web/_core/version.php` — the single source of truth |
| What is already built? | `FEATURES.md`, then verify in the code |
| Where did we get to last time? | `.claude/HANDOFF.md` |

## Branch and release rules

Four-step promotion: `alpha` → `beta` → `release-candidate` → `main`.
`main` is production.

- **One work-in-progress branch at a time.** As of 7 September 2026 that is
  `claude/alpha-wip`.
- **One open pull request at a time**, to avoid two merges racing.
- **Judge a branch by its file contents, not its commit messages.** In
  September 2026 five branches that looked like unfinished feature work turned
  out to be entirely superseded; merging them would have undone later fixes.
  The method used to prove that is written up in
  `.claude/plans/branch-audit-2026-09-07.md`.

## How the team wants work done

- **Deep analysis and planning:** sequential Fable 5 agents, never a parallel
  fan-out. Fall back to Opus only if Fable is unavailable, and try Fable again
  next time.
- **Building:** Sonnet or Haiku. Opus only when the work is genuinely hard.
- **Guiding principle:** get it right first time. Spend effort efficiently, but
  never at the cost of correctness.
- **Write everything in plain English** — replies, code comments, commit
  messages, pull requests, GitHub issues, documentation and in-app help. No
  unexplained jargon. Explain the "why", not just the "what". This is a
  standing rule, recorded in `.claude/CLAUDE.md`.

## After finishing any piece of work

1. Commit and push to the current work-in-progress branch.
2. Update the relevant GitHub issue.
3. Update `.claude/` (this file, `CLAUDE.md`, `HANDOFF.md`) and the repository
   documentation.
4. Update `.claude/HANDOFF.md` so the session can be picked up again after any
   interruption.
