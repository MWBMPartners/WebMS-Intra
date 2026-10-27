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
announcements, calendar, giving, rotas, safeguarding and dozens of other small
apps, all switchable on or off per site. Count them from `web/_apps/` rather
than from any document — this file deliberately does not keep the number, for
the same reason it warns about counts below. Built and sold by MWBM Partners
Ltd, trading as MWservices. All rights reserved.

The product name is chosen at install time from a set of brand presets
(`WebMS Intra` is the generic default, `ChurchMS` the church one), so the same
codebase ships under different names.

## The constraints that decide every design choice

- **Shared hosting on DreamHost.** No command line on the server, no Composer,
  no Docker, no build step. Anything proposed has to work as plain PHP files
  copied onto the server.
- **PHP 8.5**, staying backward compatible with 8.4.
- **MySQL 8 in production** — DreamHost shared hosting runs MySQL 8, offers no
  MariaDB, and does not let a customer choose the version. **Which** MySQL 8 has
  not been confirmed, and it matters: 8.0's support ended April 2026, while
  8.4 LTS runs to 2029. The portal shows the version on the admin dashboard.
  Issue #475. Write SQL to the MySQL 8 ∩ MariaDB intersection meanwhile:
  MariaDB-only syntax — `IF NOT EXISTS` on `ADD COLUMN`, `ADD INDEX`,
  `MODIFY COLUMN` and friends — is a hard error on MySQL. (`CREATE TABLE IF NOT
  EXISTS` and `DROP TABLE IF EXISTS` are fine.)
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

Promotion is planned as `alpha` → `beta` → `rc` → `main`, with `main` as
production. **The branch is called `rc`, not `release-candidate`, and it does
not exist yet** — nothing in the repository creates it, and
`.github/workflows/package.yml` says so in its own header. So the promotion
that really runs today is `alpha` → `beta` → `main`, each branch deploying to
its own web folder (the table is in `DEV_NOTES.md`).

- **One work-in-progress branch at a time.** As of 7 September 2026 that is
  `claude/alpha-wip`.
- **One open pull request at a time**, to avoid two merges racing.
- **Judge a branch by its file contents, not its commit messages.** In
  September 2026 five branches that looked like unfinished feature work turned
  out to be entirely superseded; merging them would have undone later fixes.
  The method used to prove that is written up in
  `.claude/plans/branch-audit-2026-09-07.md`.

## How the team wants work done

- **Deep analysis and planning:** the strongest reasoning model available, one
  agent at a time, each building on what the one before it found. Since
  23 September 2026 that is **Opus, not Fable** — the owner swapped them
  deliberately, because the newest Opus costs less and is at least as good at
  this, so there is nothing left to fall back to. **The rule is about the tier,
  not the name:** an older note naming Fable as the planner is out of date
  rather than a reminder.
- **Never two analysis runs at once**, and nothing new starts — not even
  planning the next package — while a finished one is being independently
  checked. Two exceptions have been added since, both deliberate:
  **fact-gathering may run in parallel** (reading code, working through a list
  of issues, web research — work that only finds out what is already true;
  owner, 21 September 2026), and **two packages that change no file in common
  may run side by side** (owner, 12 October 2026). Judgements about one package
  still happen one agent at a time.
- **Building:** Sonnet or Haiku. Opus only when the work is genuinely hard.
- **Guiding principle:** get it right first time. Spend effort efficiently, but
  never at the cost of correctness.
- **Write everything in plain English** — replies, code comments, commit
  messages, pull requests, GitHub issues, documentation and in-app help. No
  unexplained jargon. Explain the "why", not just the "what". This is a
  standing rule, recorded in `.claude/CLAUDE.md`.

## After finishing any piece of work

1. Commit to the current work-in-progress branch. **Do not push.** The owner
   reserves pushes, pull requests, merges, tags and releases for their own
   explicit approval, and that rule says in so many words that an older
   repository instruction to push after every task does not supply it.
   **Know that this is a live conflict, not a settled wording:** standing
   instruction 6 of `.claude/CLAUDE.md` and rule 6 of `.OpenAI/CONTEXT.md`
   both still read "commit and push". The owner's reservation wins, and those
   two rule files are deliberately left alone until the owner settles the
   wording themselves. Prepare the commit and stop at that boundary.
2. Update the relevant GitHub issue.
3. Update `.claude/` (this file, `CLAUDE.md`, `HANDOFF.md`) and the repository
   documentation.
4. Update `.claude/HANDOFF.md` so the session can be picked up again after any
   interruption.
