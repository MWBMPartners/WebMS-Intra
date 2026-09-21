# WebMS-Intra — project context for Codex and other OpenAI-based agents

> **`.claude/CLAUDE.md` is the source of truth.** This file is a plain-English
> summary of it for a reader who does not use Claude Code. If anything here
> disagrees with `.claude/CLAUDE.md`, that file is right, and this one should
> be corrected to match.

## What the product is

WebMS-Intra is an internal "back office" portal platform for churches and
similar organisations — announcements, a calendar, giving records, rotas,
safeguarding, and around fifty other small apps, each one turned on or off
per customer. It is sold and run by MWBM Partners Ltd (trading as
MWservices), all rights reserved.

**It is a product used by many separate customers, not a single site.**
That fact shapes several rules below (nothing hard-coded, no web address
built in, each customer's hosting can differ).

**The hosting limits that shape every decision:**
- Runs on ordinary shared web hosting (DreamHost, in our own set-up — an
  example, not something to assume). There is **no command line on the
  server, no Composer, no Docker, and no build step**. Everything shipped
  must work as plain PHP files copied onto the server.
- Written in PHP (target 8.5, kept working on 8.4) with Bootstrap 5.3.3 for
  styling.
- The production database is some version of MySQL 8 — DreamHost's shared
  hosting offers only that, no version choice. MySQL 8.0 itself reached the
  end of its extended support in April 2026; see "The MySQL 8 dialect trap"
  below.
- **Anything loaded at page-load time from a public content-delivery network
  (a CDN — a shared server elsewhere on the internet that many sites use for
  common scripts and stylesheets) needs a local copy to fall back on**,
  because some customers' office or church networks block those servers
  outright.
- **Customers will install from a downloadable zip package — this is
  PLANNED, not built yet (issue #499, open).** It is meant to be built by a
  GitHub Action rather than by cloning the repository. Even today, before
  that package exists, installing and upgrading must already work for
  someone with only a web hosting control panel — no command line.

**Where to find things that change over time.** Counts of apps, files,
tables and so on go stale within days and are not repeated here — see
`.claude/CLAUDE.md`'s "Counts, and when they were last checked" table for
current numbers and the exact command to re-check each one. `FEATURES.md`
at the repository root is the living inventory of what each app does;
`CHANGELOG.md` is the chronological history; `DEV_NOTES.md` carries the
deeper technical notes (including the exact SQL patterns referenced below).

## Directory layout

```
repo root/          not deployed — documentation, this file, and CI/CD (short
                     for "continuous integration / continuous deployment":
                     the automated checks and the automated deploy step that
                     run on GitHub whenever code is pushed)
web/                everything that is deployed, synced to the server
  _core/            the framework's own PHP classes (namespace Portal\Core)
  _apps/            every app's PHP handlers — kept OUTSIDE the public web
                     root on purpose (issue #159), reached only through the
                     router
  _vendor/          a small number of vendored third-party libraries
  _sql/             numbered, ordered database migrations, plus one script
                     that builds a brand-new database from nothing
  _lang/             translation files for the portal's own interface
  _install/         a standalone installation wizard (does not need the
                     rest of the framework loaded first)
  public_html/      the ONLY folder the web server may serve files from
                     directly: the front controller (the single PHP file
                     that every incoming address is actually handed to
                     first, and which then works out what to show), static
                     assets, and a small number of named entry-point PHP
                     files
  _auth_keys/, _uploads/, _backups/, _libraries/
                    server-managed, never stored in this repository
```

The owner decided, on 13 September 2026, that each of the three release
channels (alpha / beta / live) should get its own folder and its own
database on the server, with the channel read from a marker file the
deploy step writes — see "Nothing hard-coded" below. **This is a decided
design, not yet built:** as of 16 September 2026, `.github/workflows/
deploy.yml` still uses the old shared folder layout, and
`web/_core/bootstrap.php` still works out the channel from an environment
variable and then the folder name, not from a marker file. Treat the
separate-folder-and-database description as the target, and confirm
current behaviour with the owner before relying on it.

## Every standing rule, condensed

Each of these is a project-wide rule, not a one-off preference. The full
wording — usually longer, with the real-world example that led to it — lives
in `.claude/CLAUDE.md` under the heading named in brackets.

1. **Plain English, always.** Write for a capable reader who does not work
   on this system: ordinary words first, a technical term only when it is
   genuinely needed and explained on first use. Applies to chat replies,
   code comments, commit messages, pull request and issue text, and
   documentation — no exceptions. *(Already one of Codex's own global rules
   in `~/.codex/AGENTS.md`; repeated here because it governs everything else
   in this file too. Full text: `.claude/CLAUDE.md` → "Plain English".)*

2. **Cross-system review until clean.** Before asking for that review, the
   project's own mechanical checks have to pass first: `php -l` (PHP's own
   syntax checker) on every touched PHP file, every script under
   `tools/audit-checks/`, and the end-to-end migration harness whenever the
   change touches the database. Only once those pass is the change reviewed
   by a different AI system from the one that built it — built by Claude,
   reviewed by Codex, or the reverse when Codex built something. **Treat
   what the reviewer says as a second opinion, not a verdict:** check every
   point against the code before acting on it, and when the reviewer is
   wrong, say so plainly, with the evidence, rather than applying the
   finding anyway. Findings that do hold up get fixed and the change is
   reviewed again, round after round, until the review comes back clean.
   This is not optional and not a one-off; it is how "finished" is defined
   on this project. This whole approach follows the project's guiding
   principle, **GIRFT — Get It Right First Time**: verify before asserting,
   prefer a fix that refuses when it is unsure over one that guesses and
   reports success, and say plainly when something has not been verified.
   *(`.claude/CLAUDE.md` → "Codex review" and "Standing Instructions".)*

3. **Deep analysis and planning run sequentially, one at a time — never in
   parallel — even when the model is Opus.** Each step tries Fable first
   and only falls back to Opus for that one step if Fable is unavailable;
   the next step tries Fable again. Building (writing the actual code) uses
   Sonnet or Haiku, moving up to Opus only when the work is genuinely
   complex. *(`.claude/CLAUDE.md` → "Deep analysis: sequential, one run at
   a time" and "Working with the owner".)*

4. **Switching AI systems when one runs out.** If whatever is doing a piece
   of work becomes unavailable — out of credit, rate limited, or down — hand
   the work to another suitable system rather than stopping, then return to
   the usual one as soon as it can take work again. This depends entirely on
   the independent review in rule 2 actually happening: a review must never
   be quietly done by the same system that built the thing, and any fallback
   must be written down (commit message, handoff, progress report). *(Already
   one of Codex's own global rules in `~/.codex/AGENTS.md`. Full text:
   `.claude/CLAUDE.md` → "When one system runs out".)*

5. **The handoff (`.claude/HANDOFF.md`) is kept current as the work
   happens**, not tidied up at the end — updated before anything long
   starts, after each step, and the moment something important is learned.
   It is what makes rule 4 possible at all: a fallback can only work if the
   situation it needs is written down somewhere. Read its top section before
   starting any work here. *(`.claude/CLAUDE.md` → "When one system runs
   out" and "Working with the owner".)*

6. **After every finished piece of work:** create the GitHub issue first if
   there is not one already, with a description, its scope, and acceptance
   criteria; commit and push to the single working branch (never stack pull
   requests); keep `CHANGELOG.md`, `FEATURES.md`, `DEV_NOTES.md` and
   `README.md` current where the change touches them; update the related
   GitHub issue(s) and close them with a reference to the commit or pull
   request; update `.claude/` memory and context; update **this folder**
   (`.OpenAI/`); and update the handoff. Treat these as part of the
   definition of "done", not optional extras.

   **How this fits with rule 2 — when to commit.** **When Codex is
   available, review before committing remains the normal order — the rest
   of this paragraph describes what happens only while it is not.** The
   owner decided, on 16 September 2026, that while Codex is unavailable a
   finished piece is committed and pushed as soon as its INDEPENDENT check
   passes instead — a separate Claude agent that did NOT write the code,
   run after the builder's own mechanical checks (`php -l`, the audit
   scripts, the migration harness) already pass. This is a stricter
   condition than the builder's own checks alone, and it does not wait for
   the cross-system (Claude/Codex) review in rule 2 to happen first. The
   commit message must say plainly, in words, that the cross-system review
   has not happened yet. The other system reviews the commit afterwards,
   and any fix it finds lands as a new, separate commit. Either way, review
   by a different system is still required for every change before it
   counts as reviewed — only the timing relative to the commit changes.

   **TEMPORARY ARRANGEMENT, set by the owner on 20 September 2026.** Reviews are
   NOT being run package by package at the moment. One comprehensive review of
   the whole branch happens after the #514 build: it covers the work that was
   never reviewed while Codex was out of usage (13 to 20 September) and
   everything built since. No pull request is raised until that review is done
   and every finding is either fixed and re-reviewed clean, or written up as an
   issue the owner has agreed to leave for later. Once that pull request is
   raised, the normal arrangement returns: each finished piece goes to Codex as
   it lands. Claude-side independent checking never stopped — every package
   still gets a fresh agent that did not build it, before it is committed.
   Committed under this arrangement so far (all NOT yet Codex-reviewed):
   `5f278cb`, `12e637a`, `1e0809c`, `bd1ef12`, `6989b26` (#515) and
   `c99dc87` (#516, roles per organisation, 21 September 2026), plus the
   documentation and handoff commits in between.

   **How to tell what has actually been reviewed: read `.claude/
   HANDOFF.md`, never the commit message alone.** Which commits Codex has
   reviewed is recorded in the handoff, not inferred from whether a commit
   message happens to mention an outstanding review. A commit that says
   nothing about review is NOT necessarily reviewed — for example, commit
   `110e47d` (the owner's own precautionary backup, 15 September 2026) says
   nothing about review either way, and is genuinely NOT reviewed; the
   handoff records that everything from that commit onward is still
   waiting for the Codex catch-up review. Always check the handoff's own
   record before assuming a commit is, or is not, reviewed. *(`.claude/
   CLAUDE.md` → "Working with the owner" and "Standing Instructions";
   `.claude/HANDOFF.md`, decision A, 16 September.)*

7. **Autonomy, with decisions raised up front.** Work through a whole queue
   of tasks without stopping to ask permission at each step. Stop only for a
   decision that genuinely needs the project owner, state plainly what is
   needed and why, and raise such questions at the START of a piece of work
   rather than one at a time as they come up — then keep working on
   everything else while waiting for an answer. *(`.claude/CLAUDE.md` →
   "Working with the owner".)*

8. **Progress updates as a table.** When reporting on a queue of tasks, show
   each task and its current status as a table, and report often rather than
   only at the end. *(`.claude/CLAUDE.md` → "Working with the owner".)*

9. **No web address ever ends in `.php` (or any other language extension).**
   Every link, form target, redirect and background request uses the clean
   address the portal has registered, never the file that answers it. On
   this codebase it is not just bad practice — `.htaccess` (a small
   configuration file placed inside a folder, which the Apache web server
   reads before it serves anything from that folder) gives a flat "page not
   found" for almost any address ending in `.php`. That failure is quiet,
   but for a human reason rather than a technical one: the page carrying the
   wrong link looks completely normal, so only the person who actually
   clicks it ever finds out it was broken. (Whether that "page not found"
   itself gets written to any server log has not been checked — do not
   assume either way; this is not the same as web-root shadowing, rule 16
   below, where nothing runs at all.) This has already hidden three broken
   "Save" buttons and a broken upgrade page in one real check. *(`.claude/
   CLAUDE.md` → "Never put '.php' in a web address".)*

10. **Nothing about one customer's set-up is ever hard-coded.** WebMS-Intra
    is sold to many customers, so no web address, domain name, or hosting
    assumption may appear as a literal value in code, GitHub workflows,
    settings seeds, templates, emails or the API specification. Our own
    addresses (for example `portal.millrdsdacambridge.uk`) are examples of
    ONE customer's configuration, never a default to fall back on. Real
    addresses come from the installer, a setting, or a deployment secret;
    a missing one gives a clear message, never a silent fallback to ours.
    Hosting may differ per customer and even per release channel — never
    assume they share a user, a server, or a host. **Before committing,**
    search the code, the GitHub workflows and the `tools/` folder for this
    project's own domain name and expect to find nothing except clearly
    labelled examples — on this project that search is `grep -rn
    millrdsdacambridge web .github tools`. *(`.claude/CLAUDE.md` →
    "No web address is ever built in — WebMS-Intra is a product".)*

11. **Comment code properly, in every language used here** (PHP, HTML, CSS,
    JavaScript, XML, JSON, SQL). Explain the WHY, not the WHAT — "this runs
    after the save, because before the save the row has no identity number
    yet" is useful; "increments the counter" is not. Record what was tried
    and rejected, because that is the comment most likely to stop someone
    reintroducing a fixed fault. Say plainly what a piece of code cannot do,
    where that is not obvious. **JSON has no comment syntax** — never put
    `//` in a `.json` file; put the explanation in the accompanying schema
    instead (see rule 12). *(`.claude/CLAUDE.md` → "Comment everything, in
    every language".)*

12. **Every JSON and XML format gets a schema kept beside it** — a JSON
    Schema file (`*.schema.json`) with a `description` on every property, or
    an XSD (an XML Schema Definition — the equivalent schema-file format,
    but for XML instead of JSON) for XML. The schema is both the validator
    and the documentation, which is why the descriptions matter, and it has to be wired into an
    actual check — a schema nothing ever runs is a document, not a
    safeguard. *(`.claude/CLAUDE.md` → "A schema for every JSON and XML
    format".)*

13. **Every database change goes in TWO places, and they must agree.**
    A change to the shape of the database needs to reach both a brand-new
    install (`web/_sql/full_schema.sql`, run by the installer) and an
    existing customer's upgrade (a numbered migration in `web/_sql/`,
    replayed by the migrator). Reaching only one is the dangerous case: it
    is quiet, and two installations of the same version end up behaving
    differently until somebody hits the gap. Migrations must be safe to run
    twice, because the installer replays every numbered migration after the
    full schema, regardless of which have already run. `tools/audit-checks/
    check_schema_seed_parity.py` checks the two agree; run it before
    committing anything touching `web/_sql/`. **The storage engine is InnoDB
    and must stay that way** — it is what makes transactions, table links
    and an all-or-nothing backup restore possible; the alternative (MyISAM)
    supports none of that. *(`.claude/CLAUDE.md` → "Every database change
    goes in the install script too".)*

14. **The MySQL 8 dialect trap.** Production runs some version of MySQL 8
    (exact version unconfirmed — see `.claude/CLAUDE.md`'s database-version
    warning banner). Various handy `IF [NOT] EXISTS` forms on `ADD`/`DROP
    COLUMN`, `ADD`/`CREATE`/`DROP INDEX`/`KEY`, or `CHANGE`/`MODIFY COLUMN`
    are **MariaDB-only** and get rejected outright by MySQL with error 1064.
    Use the `information_schema` (the database's own built-in catalogue of
    which tables, columns and indexes already exist) + `PREPARE`/`EXECUTE`
    guard pattern instead — ask that catalogue whether the change is
    already there, and only then build and run the real SQL statement, as a
    prepared statement, when it is not (worked examples: migrations 037,
    112, 138; full templates in `DEV_NOTES.md`). `CREATE TABLE IF NOT
    EXISTS` / `DROP TABLE IF EXISTS`
    are standard MySQL and are fine as they stand. *(`.claude/CLAUDE.md` →
    "SQL dialect trap".)*

15. **The ApiRouter trap.** Any address starting `api/` is intercepted
    before the normal routing table is ever consulted, and resolved purely
    by naming convention to `_apps/{appName}/api/{action}.php`. A handler
    placed anywhere else, or a row added for it in the normal routing table,
    is simply never reached. Each such endpoint also needs a setting
    `api.{appName}.{action}.enabled = 'true'` seeded in the database, or the
    router answers 403 regardless of the code being correct. Adjacent trap:
    the response helper class exposes `::success()`, not `::ok()`, and its
    header-setting method is private — check the real class before calling
    it. *(`.claude/CLAUDE.md` → "ApiRouter routing trap".)*

16. **Web-root shadowing.** A real file or folder that already exists inside
    the public web root silently wins over any address the portal itself
    has registered with that same name — the web server answers directly
    from disk and the portal's own routing code never runs at all, with
    nothing written to any log. This has twice made a real app entirely
    unreachable, once hiding a live, unprotected page nobody had ever been
    able to test. **The general lesson matters more than the mechanism:** a
    page nobody can reach is a page nobody has ever actually looked at, so
    whatever is wrong with it — including a missing access check — simply
    accumulates unnoticed for as long as it stays unreachable. So before
    making a shadowed address reachable again, read what is genuinely
    behind it first; deleting the shadowing folder as a "tidy-up" is a
    security decision, not routine cleaning. Before adding a new address,
    check `tools/audit-checks/check_webroot_shadowing.py`, which compares
    the whole address (not just its first segment) against what genuinely
    exists on disk. *(`.claude/CLAUDE.md` → "Web-root shadowing trap".)*

17. **Only two variables are inherited by an app page: the database
    connection and the loaded settings.** The router does `global $mysqli,
    $SETTINGS;` immediately before loading any page under `_apps/`, and
    those two names are the only things such a page can reach without
    being handed them explicitly. Using any other ambient name (a `$db`
    variable, for instance) compiles fine and then fails the instant the
    page runs, with no file and no message. A helper that receives the
    database connection as an ordinary function parameter is a different,
    perfectly fine, thing. *(`.claude/CLAUDE.md` → "Two variables, and only
    two".)*

18. **Check a name against the thing that actually uses it, not against
    what merely looks similar.** Several real bugs here were the same
    shape: an address compared against a file path (or the reverse), a
    table name that was almost — but not exactly — right, so a search for
    it quietly found nothing and read exactly like "nothing to fix". A
    fourth instance is the one most likely to repeat: a fix that LOOKED
    complete but was not, because signing in is a JOURNEY through several
    addresses, not one page. Reopening the sign-in page during an upgrade
    was not enough on its own — anyone using two-factor sign-in typed their
    password correctly and was then sent straight on to a SECOND address
    that was still blocked. When fixing a blocked route, list every address
    in the whole journey, not just the one address a bug report happened to
    name. Check a seeded address against the routing table, a table name
    against the real schema file, a variable name against what the router
    actually provides — never against the folder layout, which only
    resembles the real thing. *(`.claude/CLAUDE.md` → "Name things by the
    right identifier".)*

19. **Code style, must-follow:** `declare(strict_types=1)` in every PHP
    file; full `if ($x === true)` comparisons rather than a bare `if ($x)`;
    platform-neutral file paths (`DIRECTORY_SEPARATOR`, `dirname()`);
    comments marking major sections, each starting with a small emoji
    marker — this is the existing house style across the codebase (visible
    in `web/public_html/.htaccess`, for example), so match it rather than
    inventing a plain-text alternative; detailed inline comments, with a
    reference link to the relevant issue or documentation where that is
    useful; never a raw `<table>` tag for displaying data — use the
    project's own `portal-data-list` component instead; database access
    only through MySQLi prepared statements, never by pasting user input
    into SQL; `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` on every value
    written into a page; a file header comment giving the path, a
    description, the package, the author, the copyright line (all rights
    reserved), and a version number. *(`.claude/CLAUDE.md` → "Code
    Style".)*

## A few more things worth knowing

- **Branches are meant to be promoted through four steps on the way to
  production, per the 7 September 2026 memory: `alpha` → `beta` →
  `release-candidate` → `main`.** `main` is what customers actually run.
  **No `release-candidate` branch exists on the remote today** (checked 16
  September 2026) — treat this four-step flow as the intended design, to
  be confirmed with the owner, not as something already set up. **One
  working branch at a time**, currently
  `claude/alpha-wip`, created from the tip of `alpha`. All new work happens
  there; no second pull request is opened while one is already open against
  `alpha`. When several branches are created side by side just to split up
  one piece of work, treat them as scaffolding only — delete them the same
  day their combined output has been gathered and merged, rather than
  leaving them cluttering the branch list. Judge any branch by comparing
  its actual file contents against the target branch, never by its commit
  messages — several branches here have looked like unfinished feature work
  while actually being fully superseded.
- **On every pull request, GitHub's own automated checks are treated as
  real findings**, not just the hard PHP-lint gate: route targets that do
  not exist, MariaDB-only SQL, non-idempotent migrations, column drift
  against the schema, and so on. A genuine finding gets fixed; a false
  positive gets a plain-English note explaining why, in the pull request
  itself.
- **A closed issue must say, in one sentence, how a person actually reaches
  the thing that was built.** Working code that nobody can get to — an
  address that changed, a link nobody added, a page hidden behind web-root
  shadowing (rule 16) — has happened here more than once, and every audit
  script and test still passed while it was true.
- Never commit `_auth_keys/`, `_uploads/`, `_backups/`, `_libraries/`,
  `.env`, or any `*.key` file — these are server-managed and specific to
  one running instance, never part of the shipped code.
