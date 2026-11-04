# WebMS-Intra — project context for Codex and other OpenAI-based agents

> **`.claude/CLAUDE.md` is the source of truth**, together with the three
> folder-specific rules in `.claude/rules/` (moved there on 5 October 2026).
> This file is a plain-English summary of them for a reader who does not use
> Claude Code. If anything here disagrees with them, they are right, and this
> one should be corrected to match.

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
tables and so on go stale within days, so they are not kept here or in
`.claude/CLAUDE.md` any more (its counts table was removed on 5 October 2026;
a snapshot with the commands to re-count is in
`.claude/history/claude-md-inventory-2026-10-04.md`). Count from the code;
when any document disagrees with the code, the code is right. `FEATURES.md`
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
   reviewed again, round after round, until a round finds no real problem.
   **A real problem is** wrong behaviour, a security or privacy gap, possible
   data loss, a message or comment that is untrue, or a test that passes on
   broken code. The reviewer still reports everything it sees; hardening
   ideas, wording preferences and polish go under "Found" (or into an issue)
   and do not start another round.
   This is not optional and not a one-off; it is how "finished" is defined
   on this project. This whole approach follows the project's guiding
   principle, **GIRFT — Get It Right First Time**: verify before asserting,
   prefer a fix that refuses when it is unsure over one that guesses and
   reports success, and say plainly when something has not been verified.
   **What gets reviewed (owner, 5 October 2026, "narrow exactly as machine-wide"):** only changes that could do real harm — code, database changes, anything that deletes or overwrites data, touches money, credentials or personal details, or changes a safety gate. Documentation of every kind (instruction files, memory, the handoff and progress notes), renames and comment-only changes get no review round. **Who:** a fresh agent that did not build it checks each code package before commit, named as standing in for Codex; the final whole-of-part-8 check of #514 was done by a fresh Opus agent as Codex's named stand-in, after Codex ran out of usage twice (owner, 5 October 2026), and Codex reviews part 8 later in pieces across its resets, with no other work waiting on it; one Codex review of the whole branch still ends the queue.
   *(`.claude/CLAUDE.md` → "Codex review" and "Standing Instructions".)*

3. **Deep analysis and planning run sequentially, one at a time — never in
   parallel — on Opus** (since 23 September 2026; it used to be Fable,
   falling back to Opus, and was changed deliberately because the newest
   Opus is cheaper and at least as good). **Fact-gathering may run in
   parallel; judgements may not:** reading code, checking a long list of
   issues or web research can be split across several agents at once,
   because none of them makes a judgement the others need to see first.
   Keep helper agents few — no more than six at once (on the owner's Mac
   Claude Code enforces this with a setting, and refuses a seventh). One
   package is in flight at a time, and checking counts. Building (writing
   the actual code) uses Sonnet or Haiku, moving up to Opus only when the
   work is genuinely complex; verification is never done by a weaker model
   than the build. *(`.claude/CLAUDE.md` → "Deep analysis: sequential, one
   run at a time".)*

   **Packages that share no files may run side by side (owner, 12 October 2026).** Asked how to speed up, the owner chose this: a package may be planned, built and checked while another is in flight, as long as the two change no file in common. Where files overlap, one at a time still holds. In practice: each builder is given its exact file list and told which shared files it must not touch; the shared documents (`CHANGELOG.md`, `DEV_NOTES.md`, `FEATURES.md`) are never edited by two packages at once — a builder working alongside another writes its entries to a note in its evidence folder, and the commissioning session adds them when that package is committed; each package is staged by its own file list, never from `git status`; each uses its own named containers and ports. Analysis and planning judgements about ONE package still happen one agent at a time.

4. **Switching AI systems when one runs out.** If whatever is doing a piece
   of work becomes unavailable — out of credit, rate limited, or down — hand
   the work to another suitable system rather than stopping, then return to
   the usual one as soon as it can take work again. This depends entirely on
   the independent review in rule 2 actually happening: a review must never
   be quietly done by the same system that built the thing, and any fallback
   must be written down (commit message, handoff, progress report). *(Already
   one of Codex's own global rules in `~/.codex/AGENTS.md`. Full text:
   `.claude/CLAUDE.md` → "When one system runs out".)*

5. **One job per document, and NOTHING NOT-DONE LIVES ONLY IN PROSE
   (owner, 30 October 2026, #678).** GitHub issues are the record of what is
   and is not done **and of every deferral or open decision**;
   `.claude/HANDOFF.md` holds where the work is RIGHT NOW and is
   **overwritten, never appended** (current state only, about one screen, no
   history); `CHANGELOG.md` is what shipped; `DEV_NOTES.md` is the technical
   lessons; a plan in `.claude/plans/` is point-in-time thinking that gets a
   terminal status line and moves to `.claude/history/plans/` when the work
   lands. **A deferral or open question gets an issue in the same action that
   decides it** — if it is not worth an issue it is not worth recording,
   because nothing checks prose. The handoff is still kept current AS THE WORK
   HAPPENS, which is what makes rule 4 possible: a fallback only works if the
   situation it needs is written down. Read its top before starting work.
   **Never cite another document by line number** — cite issue numbers and
   commit SHAs, which do not move; every line-number citation between these
   files was already dangling. **Closing an issue is two actions:** always try
   `gh issue close` AND add the `status: done` label, because a refused close
   is otherwise invisible. Note the mistake that came with this rule: one
   refused close was reported as a standing block and as the reason finished
   issues were open; retried, it worked, and the real reason was that nobody
   had closed them. One failure is not a standing block. *(`.claude/CLAUDE.md` → "One job per document".)*

6. **After every finished piece of work:** create the GitHub issue first if
   there is not one already, with a description, its scope, and acceptance
   criteria; commit and push to the single working branch (never stack pull
   requests); keep `CHANGELOG.md`, `FEATURES.md`, `DEV_NOTES.md` and
   `README.md` current where the change touches them; update the related
   GitHub issue(s), and close them once the work has merged, with a
   reference to the commit or pull request; update `.claude/` memory and
   context; update **this folder**
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
   by a different system is still required for every change that gets a
   review round (see rule 2) before it counts as reviewed — only the timing relative to the commit changes.

   **TEMPORARY ARRANGEMENT, set by the owner on 20 September 2026 and moved on
   24 September 2026.** Reviews are NOT being run package by package at the
   moment. One comprehensive review of the whole branch happens **at the END OF
   THE WHOLE QUEUE** — after #514, the follow-up issues, #549 and the
   documentation sweep — and it moves to the very end again if tasks are added.
   It covers the work that was never reviewed while Codex was out of usage (13
   to 20 September) and everything built since. Run Codex with
   `-c model="gpt-6-astra"` and stdin closed, or it refuses. No pull request
   is raised until that review is done
   and every finding is either fixed and re-reviewed clean, or written up as an
   issue the owner has agreed to leave for later. Once that pull request is
   raised, the normal arrangement returns: each finished piece goes to Codex as
   it lands. Claude-side independent checking never stopped — every package
   still gets a fresh agent that did not build it, before it is committed.
   The list of commits waiting for that review is kept in `.claude/HANDOFF.md`
   (it used to be repeated here, and went stale). Two commits live OUTSIDE the
   working branch and must be reviewed by name: `560a4f2` on main (the #504
   squash: codeql-action bump, the
   security-backport guard, Psalm's `actions: read`, a DEV_NOTES paragraph)
   and `1830dd7` on beta (Psalm's `actions: read`).

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
   everything else while waiting for an answer. **When NOT to stop:** stop
   and ask only when you cannot continue without an answer, or before
   anything destructive — deleting data, force-pushing, or changing anything
   outside this repository; everything else, carry on and report afterwards.
   **Hold the scope:** deliver what was asked, at the scope intended; if the
   request looks mistaken, say so in one sentence and carry on as asked;
   anything found outside the task goes under "Found" or into an issue, and
   is not built unless the owner says so. *(`.claude/CLAUDE.md` → "Working
   with the owner".)*

8. **Progress updates, and how a long run ends. THE TABLE IS STANDING, NOT ON
    REQUEST** (owner, 29 October 2026, having had to ask why it had stopped
    appearing: it was given once when asked and then quietly dropped). It is how
    the owner tracks an autonomous run without reading a narrative. Say in one sentence what
   you are about to do; while working, update only when you find something
   important, change direction or finish a piece of work — always in the same
   message as your next action, never as a place to stop. For a queue, show
   each task and its status as a table, often. End any run longer than a
   single change with three headings, in this order: **Blocked on me** (what
   needs the owner, and why), **Changed** (what landed, with the commits),
   **Found** (anything discovered and not done, each with an issue number);
   write "nothing" under an empty one. *(`.claude/CLAUDE.md` → "Working with
   the owner".)*

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
    supports none of that. Since 12 October 2026 the migration test
    (`tools/e2e-migrations/run.sh`) also upgrades two older databases, one
    built from the last release and one from the install script of 6 March
    2026, and requires each to end up equal to a fresh install. Run it whenever
    a change touches `web/_sql/`. *(`.claude/rules/database.md` → "Every
    database change goes in the install script too".)*

    **MariaDB 11.4 is now covered by automated tests (#487, 28 October 2026),
    and the words for it matter.** Two bodies of work pass on MariaDB 11.4.13:
    the six calendar self-tests (`calendar-selftests.yml`, which runs them on
    `mysql:8.0.36`, `mysql:8.4` and `mariadb:11.4`) and the end-to-end
    migration harness — all seven phases and all 42 checks, the SAME 42 that
    pass on MySQL 8.0.36 and 8.4.11. `e2e-migrations.yml` runs three jobs side
    by side; `E2E_DB_ENGINE` (`mysql` or `mariadb`) is the ONE place that
    decides the container image and both client program names. **Still do not
    write "MariaDB is supported" without saying what was measured** — no
    customer runs on it in production. One test skip remains, in feed-admin,
    and it is a limit of the TEST: MariaDB has `performance_schema` off by
    default and no `PS_CURRENT_THREAD_ID()`, while the portal queries
    `performance_schema` nowhere (checked, not assumed).

    Three MariaDB facts worth keeping. MariaDB 11.x ships **no `mysql` or
    `mysqladmin` program** — only `mariadb` and `mariadb-admin` — which is
    what stopped the harness running on it at all. `SELECT VERSION()` returns
    `11.4.13-MariaDB-ubu2404`, so a check comparing the whole string for
    equality can never pass (compare the leading three-part number instead;
    that keeps the moving-tag guarantee, since `8.4` still does not equal
    `8.4.11`; it is a deliberate loosening in one way, accepting `8.0.36-log`
    and `8.0.36-0ubuntu0.24.04.1`, so do not call it "just as strict" flatly).
    And
    for a foreign-key rule that was **left out** of the statement, MySQL 8.0
    and 8.4 record `NO ACTION` while MariaDB 11.4 records `RESTRICT` — a
    DECLARED rule is recorded faithfully by all three, so the engines do NOT
    normalise what they are told. That was measured on all three after the
    first guess proved wrong.

13a. **A control that posts a request nothing handles (29 October 2026).** Two
    found in one sitting and they share a shape: the page sends the request
    correctly, the handler has no branch for it, and the redirect at the end
    makes it look as though it worked. Nothing is logged, nothing is shown --
    worse than a missing button, because the control invites the click. A
    walk-in could be added to an attendance register and never taken off: the
    grid draws the cell like a member's and really posted the removal, but
    `event-attendance-mark.php` read `if ($userId > 0)` and nothing else. To
    hunt for more: list a handler's branches, list every form that posts to it,
    and check each posted shape against a branch -- a trailing
    `header('Location: ...')` hides every unhandled case.

13c. **The QR rule had no guard for JavaScript (#623, 29 October 2026).**
    `Portal\Core\Qr` is the one place a QR picture is made, because a picture
    fetched from another website carries the encoded text inside that request's
    address. That was enforced by reading PHP only. The noticeboard's generated
    bundle still falls back to `api.qrserver.com`; it is unreachable ONLY
    because `web/_apps/noticeboard/index.php` gives the board the portal's own
    address first, and the bundle is generated from `dc-runtime`, which is not
    in this repository, so it cannot be removed here.
    `tools/audit-checks/check_no_outside_qr_service.py` now guards both halves.
    **Its own first version passed on a broken guard:** it searched for the
    plain string `qrUrl`, so renaming the function to `qrUrlRENAMED` left that
    string as a prefix and the check went green. Planting is what caught it.

13b. **A unique key is POWERLESS over a column that can be empty (#487,
    migration 224).** In MySQL and MariaDB a unique key stands aside for any row
    where any part of it is empty -- an empty value is not even equal to itself.
    `INSERT IGNORE` and `ON DUPLICATE KEY UPDATE` lean entirely on the key, so
    where it stands aside they stop meaning "do nothing the second time" and
    start meaning "insert another row". `tblEventAttendance` collected
    duplicate rows for exactly this reason: its key covered
    `(eventID, userID, walkinName, dayDate)`, a row names its person by EITHER
    the member number OR the walk-in name, so no row ever filled in all four and
    the key refused nothing, ever -- under a comment claiming it was "idempotent
    via unique key". **Do not say it "counted people twice in every figure":
    that was claimed in five places and is FALSE, caught by the independent
    check.** The attendance grid keys its display by person and day and lists
    walk-ins with `SELECT DISTINCT`, so nothing shown was ever wrong. The cost
    was a duplicated "who marked this" trail and a figure that would be wrong
    as soon as anything counted rows rather than distinct people.

    The cure is a worked-out (generated) column, as `tblSettings` and
    `tblEmailTemplates` already do with `siteScope` = `COALESCE(siteID, -1)`.
    Use `VIRTUAL`, never `STORED`: MySQL refuses a STORED generated column on a
    table carrying an ON DELETE CASCADE foreign key (ERROR 1215).

    Two things that look like mistakes and are not. `attendeeRef` is left EMPTY
    when the person is unknown, because erasure anonymises attendance rather than
    deleting it so the aggregate survives, two erased people cannot be told
    apart, and collapsing them would delete the counts erasure exists to
    preserve. And `tblWebhookEvent` is deliberately not fixed: coalescing its
    reference would make every unparseable webhook of one provider collide and be
    silently dropped, losing audit rows, while the money path is already
    idempotent via `WHERE status = 'pending'`.

    **Read these keys out of a LOADED DATABASE, never from the SQL**:
    `information_schema` reports a generated column as nullable because that is
    declared rather than worked out, so `siteScope` looks like a fault and is
    not. Fourteen of the fifteen such keys in the schema are correct. The query
    and the full table are in DEV_NOTES.md, "A unique key is powerless over a
    column that can be empty".

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
    are standard MySQL and are fine as they stand. *(`.claude/rules/database.md` →
    "SQL dialect trap".)*

    **One place opens every database connection (#593, 12 October 2026):**
    `Portal\Core\DbServer::connect()` (`web/_core/DbServer.php`). It makes the
    connection strict, so a value that does not fit is an error on every
    installation, not only on the test servers. Never write `new mysqli`
    anywhere else under `web/`; `tools/db-strict-mode-selftest.php` fails if it
    finds one. Send NULL, not an empty text, to a date or number column.

    **The mail library** lives in `web/_core/maillib/` (#592) and must reach for
    nothing of the portal; `php tools/maillib/tests/stands-alone.php` enforces
    that from a list of what the library may use
    (`check_maillib_stands_alone.py` only starts it). Its test is `php
    tools/maillib/tests/run.php`.
    Since part two (#592) `Mailer::send()` uses it for the SMTP route, through
    `web/_core/MailRoutes.php`, the ONE place that reads the SMTP settings and
    the installation's choice of route, and decrypts the mailbox's password
    (never read `$SETTINGS['mail']['provider']`). Its page test is `tools/mail-
    pages-selftest.php`. Since part three a message that cannot be sent at once is kept in the mail
    queue (`web/_core/MailQueue.php`, which holds every statement that moves a
    queue row; test `tools/mail-queue-selftest.php`) and sent by `/cron/mail-
    queue`; `Mailer::send()` answers true for sent OR kept, so anything that
    records or shows a result asks `Mailer::lastOutcome()`.
    `MailQueue::jobIsRunning()` is the ONE place that says whether the scheduled
    job is running (only the job's own page records that it ran); while it is
    not, the portal sends as it did before the queue. Every queue moment is UTC
    from `UTC_TIMESTAMP()`; a time on a clock becomes one through
    `MailQueue::useBy()`. An event's time zone is `tblEvents.timezone` (the only zone
    column an event has). The certificate is always checked unless a global administrator has
    deliberately decided otherwise: `MailRoutes::certificateCheck()` is the ONE
    place `mail.smtp.certCheck` is read (`on`, `off`, or `only:<fingerprint>`;
    anything else counts as `on`). A failure holds mail, and so does restoring a
    backup. The three ways past a failed check (one held message, accept only
    this certificate, switch off) are on the e-mail page, for a global
    administrator and SMTP only, through the one chain at the top of
    `web/_apps/admin/integrations/email.php`. **The e-mail page is the ONE place
    the mail server's name and the certificate setting are changed: the general
    Settings page refuses them (`MailRoutes::lockedInGeneralEditor()`).**
    Checking switches itself back on (`MailRoutes::recheckCertificate()`, asked
    at every run of the job whatever route is in use), and the standing warning
    is read from what is stored (`MailRoutes::standingWarning()`), never from a
    session and never from which route is in use; its `publicLine` names nobody
    and is what the health line shows. A send made for ONE message past the
    check must never clear the fault on record because it got through. **A fault
    on record for the SMTP mailbox stops mail only while SMTP is the route in
    use: `MailRoutes::standingProblem()` is the ONE place that says whether a
    fault stands in the way of mail, and `certificateOnRecord()` /
    `certificateProblem()` are for showing only.** Test: `tools/mail-queue-
    pages-selftest.php`.
    Sending a test, from the e-mail page or the Integrations page, is for a
    global administrator only: one mail route serves every organisation.

    **Several organisations (#502, #511).** `Site::preDetect()` is the ONE place
    the organisation of a request is decided, and `SiteGate` (called in
    `web/public_html/index.php` just before the router) is the ONE gate that
    refuses a signed-in person who is not a member. A page reached without the
    router (`error.php`) must ask `SiteGate::wouldStop()` itself. Do not write a
    second membership test in a page; `Site::organisationsFor()` is the one list
    of "your organisations". Test: `tools/site-modes-selftest.php`.

    **The release channel is decided in ONE place, `Portal\Core\Channel`**
    (#499, #500; owner, 12 October 2026): the `PORTAL_ENV` setting, else the
    `.channel` file beside `_core`, else a web folder named exactly
    `public_html`, `public_html_beta` or `public_html_dev` (that last one is the
    `dev` channel), else ASSUMED LIVE with errors hidden. Do not write a second
    channel check, do not read `DOCUMENT_ROOT`, and do not bring back "fail
    closed" (it was built first and locked members out). `PORTAL_WEBROOT` is the
    web folder: never build a path with `public_html` typed in. Test:
    `tools/channel-selftest.php`.

    **`Portal\Core\PortalAddress` is the ONE place that decides the address in a
    link the portal sends out** (#500): the `site.url` setting (installation-
    wide row only), else the request's address, else nothing. Never build an
    e-mailed link from `$_SERVER['HTTP_HOST']`, and never read `site.url`
    anywhere else. Whether a visitor can still choose the host name of a link is
    `PortalAddress::visitorCanChooseHost()`; do not work it out on a page. Test:
    `tools/portal-address-selftest.php`. The check is `python3 tools/audit-
    checks/check_no_hardcoded_domains.py` (it replaces the hand search below).
    Lines waiting for a change that needs approval are on the pending list
    beside it; `--strict` fails while that list holds anything.

    **Every address a page writes is built by `Site::url()`** (#536): never
    a bare `/page`. In a script printed by PHP use `Router::urlForScript()`;
    in a script file, `Portal.url()`.
    `tools/audit-checks/check_site_aware_addresses.py` fails on a bare
    address anywhere it reads: the list of places still to sweep,
    `site_aware_addresses_pending.txt`, has been EMPTY since 13 October 2026
    and nothing may be added to it (`--strict` fails if a line appears).
    `tools/site-addresses-selftest.php` reads the real pages.
    `tools/audit-checks/check_registered_addresses.py` fails on an address
    written out whole in `Site::url()`, `Router::url()`,
    `Router::urlForScript()` or `Portal.url()` that nothing answers (an
    `api/` address also needs its `api.{app}.{action}.enabled` switch
    seeded), and on two built addresses glued together; a new address the
    router answers by itself must be added to the check (#612). Three things
    the sweep taught: never join two built addresses (`Site::url('a') .
    Site::url('b')` prints the organisation's part twice); a page that
    ACCEPTS a come-back address by a pattern must accept it with the
    organisation's part too, and still refuse one that leaves the portal;
    and a class used by its short name needs its `use` line in THAT file,
    which only fails when the line runs. `Site::FIRST_PART_MODE_READY` STAYS
    `false`: the empty list is not enough. Still to settle, written up
    beside #536: the service worker, the installable app, the chat box on
    `/live`, the return address of a Microsoft or Google sign-in, and links
    in a push or e-mail made by a scheduled job (a job has no organisation's
    part; those belong to `PortalAddress`).

    **Secret settings (#597, #497).** Secret settings:
    `Portal\Core\SecretSettings` is the ONE place that decides what a stored
    secret means. Do not write a second mark comparison or a second decrypt.
    `$SETTINGS` holds real secrets: never decrypt a value taken from it, and
    never print one. Test: `tools/settings-secrets-selftest.php`. The key file:
    any length is a working key; only empty or "not a file" is no key. Never
    refuse a short one. `Captcha::verify()` takes the form's name as its second
    argument; there is no default. A form with no widget shows
    `Captcha::closedNotice()` itself. An address that is recorded or sent goes
    through `Logger::withoutJobToken()`.

    **Text messages held for quiet hours (#610).** `Portal\Core\Sms` holds
    every statement that moves a held text; `sendHeld()` is called only by
    `/cron/mail-queue`, after the mail step, and can never stop it.
    `Sms::NEVER_HELD` is the ONE list of what quiet hours never hold (a
    critical alert, an emergency notice: the owner, 13 October 2026 — D-028). A
    held text is `status = 'queued'` with `errorMsg = 'quiet-hours'` and a
    use-by moment (`heldUseBy`, UTC); it is taken once, by filling
    `claimedAt` in one statement, and a claim is never undone. Never twice
    comes before always delivered. A text with no use-by moment is never
    sent. When a held text comes due, `Sms::whyNotThisPerson()` asks
    `Site::isOwnOrganisation()` whether the person may still be sent it:
    do not write a second membership test. The job page's answer stays a
    status word and plain numbers (the texts step's begin `texts`). A
    restore calls `Sms::holdRestored()`. The broadcast page's sentence is
    `Sms::broadcastSummary()`, and neither it nor the preferences page may
    say a held text will NOT be sent: `MailQueue::jobIsRunning()` means
    "the job ran in the last 15 minutes" and no more. Test: `tools/held-
    texts-selftest.php`, which reads the Notification preferences page.

    **The PHP version is judged in ONE place (#640).**
    `Portal\Core\PhpRuntime` is the ONE place that decides anything
    about the PHP version: whether the portal can run on it
    (`MIN_PHP`, 8.4.0) and whether it is older than the release that
    fixed the October 2026 flaws (CVE-2026-109618 / CVE-2026-109619,
    "PHP2Shell", fixed 21 October 2026 in 8.5.12 and 8.4.27). The
    installer's checks screen, the admin dashboard, Server Information
    and the health page all ask it — **do not write a second version
    check; change the constants in that file.** `support` and
    `security` are returned SEPARATELY and must stay so: 8.3.36
    carries the fix and is still too old to run the portal. **A
    version number cannot tell a vendor-patched build from an
    unpatched one**, so the wording is always "older than the release
    that fixed it — ask your hosting company" and NEVER "you are
    vulnerable" (section G of the test fails on that wording), and an
    unpatched PHP never blocks an installation — that decision is the
    one constant `BLOCK_INSTALL_WHEN_UNPATCHED` (`false`), read by the
    installer.
    A FINISHED release of a line newer than `NEWEST_KNOWN_SERIES`
    counts as fixed; a PRE-RELEASE of one does NOT -- it is "cannot
    tell". That distinction is not a nicety: the first version of this
    said any newer line must be fixed "because it was published
    later", and PHP 8.6 disproved it (pre-releases from 2 July 2026,
    RC3 on 8 October, both BEFORE the 21 October fix), so 8.6.0RC3 was
    told it was safe. 8.6 is pinned at `8.6.0RC4` and the comparison
    keeps the pre-release marker, because `version_compare` ranks RC
    below the release -- never compare a tidied three-part number.
    Going stale must make this less informative, never wrong. Pin
    every fixed-release number by hand in the test: deriving "one
    below" from the constant proves only that the constant agrees with
    itself, and a wrong number passed 66 checks. The tripwire must
    match `phpversion()` as well as `PHP_VERSION`.
    Tests: `tools/php-runtime-selftest.php` (no database, which also
    proves the class has no dependencies) and
    `tools/audit-checks/check_php_version_one_place.py`.

    **DO NOT MOVE THE 8.6 PIN TO `8.6.0` WHEN 8.6.0 SHIPS (#705,
    measured 2 November 2026 against a real 8.6.0RC4).** #705 asks for
    exactly that and calls it a dated action with a security
    consequence; it is WRONG, and doing it causes the harm. The
    finished `8.6.0` ALREADY counts as supported and patched with the
    pin left at `8.6.0RC4`, because `version_compare` ranks a finished
    release above its own candidates -- so NOTHING falls due on
    19 November. Moving it warns everybody on 8.6.0RC4 that they are
    unpatched, and RC4 is the FIRST build carrying the fix: planted,
    that one edit turns RC4 from `ok` to `warn`. The constant means
    "the first BUILD carrying the fix", never "the first finished
    release". What does fall due is unrelated to it:
    `PREFERRED_SERIES` (the owner's, 8.5 on purpose) and
    `NEWEST_KNOWN_SERIES` (bump it when an 8.7 appears). Section C4 of
    the test pins this and explains it at the point of failure.
    Three more 8.6 facts, each measured rather than read: a
    deprecation survey of FUNCTIONS misses a LANGUAGE change -- the
    BACKTICK operator is deprecated, used by two audit fixtures on
    purpose and by no portal code, and the pre-push hook judges
    `php -l` by its exit code, which is 0 for a deprecation. **It is 8.5
    that deprecated it, NOT 8.6, and my first version of this line said
    8.6:** measured on all three, 8.4.27 is clean while 8.5.11 and
    8.6.0RC4 both warn, so the notices were already printing on the
    RECOMMENDED version before this work. Seeing a notice on the new
    version tells you nothing about whether the old one was quiet; seven
    filesystem TEST functions (`is_file`, `is_dir`, `file_exists`,
    `is_readable`, `is_writable`, `filesize`, `filemtime`) THROW on a
    NUL byte in the path where 8.4/8.5 answered `false`, which is
    unreachable here only because every request-derived path is an
    `(int)` or passes a strict allow-list before any file call (the
    table is in DEV_NOTES.md; `strtotime()` does NOT reject a trailing
    NUL); and `zend.exception_ignore_args` still exists, so argument
    values still cannot reach a logged trace.

    **A session id the portal never issued is REFUSED (#705).**
    `Auth::prepareSessionStart()` sets `session.use_strict_mode` and is
    the ONE place it is set for the portal (the installer sets its own,
    because it cannot load `Auth`). Up to and including PHP 8.5 PHP
    ADOPTS any well-formed session id a request sends, even one naming
    no session, and starts a session under it. 8.6 turns this on by
    default; setting it ourselves keeps the behaviour the SAME on 8.4,
    8.5 and 8.6, which matters more than the default. **DO NOT CALL IT A
    FIX FOR THE CLASSIC SIGN-IN FIXATION ATTACK -- my first version did
    and the independent check killed it.** All six grants of
    `$_SESSION['user_id']` are ALREADY preceded by
    `session_regenerate_id(true)`, so the id somebody is signed in UNDER
    was always one PHP minted. **THEREFORE THIS SETTING DOES NOT MAKE
    THOSE ROTATIONS REDUNDANT:** strict mode refuses an unknown id, it
    does not rotate one at a privilege boundary. What it DOES close is
    session state with no rotation in front of it -- above all the kiosk
    hand-over, `_apps/assets/kiosk-action.php` line 208, which puts a
    REAL MEMBER'S account in the session in a file that rotates
    nowhere.
    **It must be set BEFORE `session_start()`**, which is why it lives
    there and not in `bootstrap.php`. It is NOT the same check as
    #509's, which drops a MALFORMED id; this one is about a well-formed
    id that names nothing. A green suite proved only that nothing
    broke -- section L of `tools/sign-in-limits-selftest.php` is what
    proves it works, by sending such an id and finding, with the
    setting removed, `sess_<the id the request chose>` really written
    to disk. Its third check passes either way on purpose: it guards a
    hardening that breaks the page.

    **A file that could not be CLOSED is not written (#705).**
    `BackupArchive` discarded `fclose()`'s answer when finishing an
    archive and when writing each file out of one during a restore;
    `DbBackup` already did it right and is the pattern. A close fails
    on a full disk, so the old code left a truncated backup on disk
    named like a good one, and counted a truncated restored file as
    written. Two traps: **`fflush()` is NOT a substitute** (it pushes
    PHP's buffer at the OS, which can still fail at close -- the old
    code checked `fflush` and threw the close away), and **a second
    `fclose()` THROWS a `TypeError`** on both 8.5 and 8.6 (measured;
    the resource closes either way), so the extraction path nulls the
    handle before throwing or the enclosing `finally` masks the honest
    message. `fclose()` has ALWAYS returned `false` here; 8.6 only
    reports more of it -- that was NOT measured, so the claim to rely
    on needs no version at all.

    **The portal's own QR pictures (#623).** `Portal\Core\Qr` is the ONE
    place a QR picture is made (`QrEncoder` is its own helper); nothing
    under `web/` may ask another website to draw one, and
    `Totp::getQrUrl()`, which sent the authenticator key to
    `chart.googleapis.com`, is removed and must not come back. A picture
    of anything secret is shown with `Qr::dataUri()`, written into the
    page: a picture fetched from an address puts the text in that address,
    and addresses reach server logs and browser histories. The format
    marks were written back to front until 14 October 2026, so every
    picture the portal had ever drawn was unreadable by every reader and
    nothing noticed, because a wrong picture still draws — only reading
    one back catches it. Two pages made the same outside-request mistake
    and it was invisible on one and live on the other: a page that writes
    its own `<head>` (such as `calendar/public-landing.php`) sends no page
    rule, so its outside request really happens. Tests: `tools/qr-
    selftest.php` with `tools/qr-test-reader.php`, which is proved against
    `qrencode`'s own pictures before it is pointed at ours.

    **Scripts written into a page (#625).** Every inline script carries
    the answer's own nonce from `App::cspNonce()`; a script or an element
    handler without it is REFUSED by the browser and nothing is logged, so
    the page looks finished and the control does nothing. Never add an
    element handler (`onclick`, `onchange`): use `data-portal-submit-on-
    change` / `data-portal-select-on-click`, read by
    `web/public_html/assets/js/portal-actions.js`, or a listener carrying
    the nonce. The tripwire is `tools/audit-
    checks/check_inline_scripts_have_nonce.py` (its exemption list is
    explained file by file, and its header names the three shapes it
    cannot see); the page test is `tools/page-scripts-selftest.php`. Never
    loosen the rule itself to make a script run. **A page that writes more
    than once without reloading must be handed the replacement anti-
    forgery token and must keep it**, because `Auth::verifyCsrf()`
    replaces it on every acceptance — the house way is
    `ApiAuth::requireWrite()` with `ApiResponse`, whose `meta.csrfToken`
    exists for this; return it on the success AND on every refusal reached
    after the rotation. Such a page sends ONE request at a time (two in
    flight can never both succeed), arms its deadline across the BODY read
    and not just the wait for headers, never lets its own polling pile up,
    and SAYS when a command fails — and says only what is known, never
    "not sent" when the answer was merely lost.

    **The activity log and sessions (#622).**
    `Logger::sessionFingerprint()` is the ONE thing stored in place of a
    session identifier and `Logger::sessionFacts()` the ONE copy of a
    session a record keeps (six named facts; add one there on purpose,
    never "everything except a list"). Never store `session_id()` or the
    whole `$_SESSION`: `tools/audit-checks/check_session_id_not_stored.py`
    fails on it. The fingerprint is plain SHA-256 because the clean-up
    must make the same value in SQL, and the monthly sign-ins report joins
    on that column. Records written before #622 are cleaned by
    `Portal\Core\ActivityLogCleaner`, in pieces, from the Upgrade page; it
    is NOT part of the upgrade gate and never closes the portal. Migration
    220 deletes old backup codes once, and its "already done" mark is its
    own row in `tblMigrations`, which the install script writes only where
    no backup code exists. **A one-table restore of `tblMigrations`,
    `tblTotpBackupCodes` or `tblSettings` moves that mark and can lose
    data — a whole-database restore is safe, and ANY migration whose
    "once" test is its own row has the same exposure.** Test:
    `tools/activity-log-selftest.php`, which reads the records back from
    the database rather than believing the cleaner, and refuses to search
    the log for an empty text.

    **The rows a restore cannot put back are KEPT now, and two of my
    own mistakes here are the lesson (#694, migration 233).** A
    snapshot from before 27 October 2026 does not say it covers the
    whole database, so `restoreAll()` holds `tblSettings` and
    `tblTotpBackupCodes` back — while still replacing `tblSites` and
    `tblUsers`, their `ON DELETE CASCADE` parents. Every
    per-organisation settings row went — EVERY row with a `siteID`, not
    just the address ones #677 added — and every sign-in code, spent
    ones included, and the restore said success. They are now read BEFORE
    anything is emptied and written to `tblRestoreHeldRows` AFTER
    every table is back — **that order is the design**, because the
    holding table may itself be in the snapshot, and both halves sit
    inside the restore's own transaction so a rolled-back restore
    keeps nothing. `DbBackup::CASCADE_RESCUE` is the ONE allow-list of
    tables ever kept (exactly two), and it is what makes the table
    name safe to put in a statement; `CASCADE_RESCUE_MAX_ROWS` is
    20,000, past which **NOTHING** is kept and the count is said out
    loud, because keeping some would read as keeping all — **that path
    is exercised by no test and proof 27's header says so.** The rows
    wait until an administrator clears them, with **no timer** (the
    owner, 31 October 2026): a clock that deleted them mid-recovery
    would be this same silent loss one step on, so the Backup page's
    card is the only thing stopping them being forgotten. **`EXTRA
    LIKE '%GENERATED%'` IS NOT HOW YOU FIND A GENERATED COLUMN** —
    MySQL puts `DEFAULT_GENERATED` there for any `DEFAULT
    CURRENT_TIMESTAMP` column, so my first `putHeldRowsBack()` dropped
    `updatedAt` and let the database re-stamp it: the row went back
    with the right key, the right value and the wrong "last changed"
    date. `columnFacts()` already reads `GENERATION_EXPRESSION` and
    its comment already warned against exactly that test; **read the
    class before adding a helper to it.** Only `brs_checkSame()`,
    which compares every value of every table, caught it — every
    row-exists check passed. Second mistake: the "put back" mark was
    written `if ($put > 0)`, marking EVERY row as returned the moment
    ONE succeeded, so a row that could not go back would have stopped
    being offered — the one thing this feature exists to prevent.
    **The new warning is an ADDITION, not a replacement**, and a draft
    of the code comment claimed otherwise: the older "N table(s) that
    are NOT in this snapshot were changed as well" still runs and
    covers every table, so proof 6 pins BOTH sentences. **A one-table
    restore cannot cause any of this** — `restoreTable()` refuses a
    table another points at — and I said on the issue that it could
    before checking. A row that cannot go back has more than one cause
    (its parent is missing, OR it is already there), which cannot be
    told apart from the insert's failure, so the page says "the
    likeliest reason is…" and never asserts one. The holding table
    does not exist until migration 233 has run and files reach a
    server before anybody presses "Run migrations", so every reader
    asks `holdingTableExists()` first (`SHOW TABLES LIKE`, never a
    blanket try/catch) and the restore then proceeds with an honest
    warning — refusing it would block a recovery at the one moment
    somebody is recovering. A kept code row is a second copy of
    authentication material, so `GdprEraser` DELETES this table by
    `userID` (whole rows: a kept row with its person removed could not
    be put back anywhere) and it must never join the pinned "left
    behind on purpose" list. Proofs 6 and 17 cause the cascade, so
    they call `brs_clearHeldRows()` and **CHECK its count was not
    nothing** — a silent sweep would pass the day the rescue stopped
    working. Tests: proof 27 of `tools/backup-restore-selftest.php`,
    section P of `tools/backup-page-selftest.php`, section 18 of
    `tools/gdpr-eraser-selftest.php`. The technical record is
    DEV_NOTES.md, "The rows a restore cannot put back (#694)".

    **The independent check found five real problems in this, and
    three are shapes to recognise.** (a)
    `CASCADE_RESCUE['tblSettings']['describe']` said "its postal
    address, map position, what3words address and support e-mail" —
    UNTRUE: the rescue takes EVERY row with a `siteID`, whatever its key
    (`attend.detailRetentionDays`, `giving.retention.years`, the four
    `mail.queue.*` numbers), and **SEVEN** files under `web/` can write
    such a row. The sentence is general now and
    `rowsWaitingToBePutBack()` returns a `names` field — the settings
    KEY, lifted out by `nameInsideHeldRow()`, which NEVER returns the
    JSON, because a key says what a row IS and `settingValue` can be
    anything. (b) **A row put back kept its whole copy FOR EVER**:
    nothing deletes a marked row, so a code hash and a stored setting
    value would travel into every later snapshot and off-site copy —
    exactly the unbounded second copy of authentication material the
    owner was warned about. `rowJson` is now emptied IN THE SAME
    STATEMENT that sets `putBackAtUtc`, and the column is nullable for
    that; the test counts `COUNT(rowJson)` against `COUNT(*)` AND
    checks the other columns survive, because emptying the whole row
    would destroy the audit trail instead. (c) **The rescue asked "is
    this table HELD BACK" when the question is "is it being PUT
    BACK"** — `$heldBack` is built only from names the MANIFEST holds,
    so a snapshot that simply did not CONTAIN `tblSettings` (imported,
    or hand-edited) restored `tblSites`, let the cascade wipe every
    per-organisation row, rescued nothing and printed no #694 warning.
    NO EXISTING CHECK COULD CATCH IT, because every fixture was a
    genuine old-form snapshot, which holds all three tables — the I7d
    lesson (#645) mirrored. Section F of proof 27 is decisive because
    its snapshot still SAYS it covers the whole database. Also: the
    waiting-rows read takes `FOR UPDATE` (#647's rule — without it two
    administrators are told "1 could not be put back" about a row that
    is back); `discardHeldRows()` answers **-1**, not 0, for a table
    it keeps no rows for, because one answer for two facts made the
    page say "0 kept row(s) for <anything posted> discarded" and log
    that name; `DbBackup::keepsRowsFor()` is the ONE public test of
    the allow-list. **Two comments invented an e-mail column on
    `tblTotpBackupCodes` and two privacy clauses could never fail** —
    a clause that cannot fail makes a check LOOK stronger than it is.
    **#697 is FIXED (31 October 2026): a restore CARRIES the waiting
    rows through** — read before anything is emptied, written again
    after every table is back, the same order as the rescue and for
    the same reason. It used to replace them and merely warn, which
    asks somebody mid-recovery to act on a sentence.

    **A SECOND round found five more, and the two worst were mine
    twice over.** **I wrote "32 files" and it is SEVEN** — 32 was not
    even the count of the thing I cited (`grep -rln "INSERT INTO
    tblSettings" web/_apps web/_core | wc -l` gives 30, and counts
    files writing ANY settings row, nearly all binding `siteID` as
    literal `NULL`). This very file already said "seven
    per-organisation writers" from #668 and I contradicted it.
    **Establish it by reading each INSERT's column list against its
    VALUES, never by counting files that mention the table.** Same
    shape as the fault round one existed to repair, two rounds
    running. **And the `FOR UPDATE` TOOK NO LOCK**: it was on a SELECT
    running BEFORE `begin_transaction()`, and nothing in `web/_core/`
    touches autocommit, so the implicit transaction around it
    committed and released every lock at once — while an eleven-line
    comment and both instruction files claimed it stopped two
    administrators reading the same rows. **A lock outside a
    transaction is decoration.** The transaction starts first now.
    **"Because this snapshot does not cover tblSettings" is FALSE in
    the commonest case** and the restore contradicted itself in ONE
    answer: the held-back list is built from the MANIFEST's own list,
    so a table must be IN the snapshot to be held back, and the same
    answer already said "tblSettings … were left exactly as they are".
    An administrator mid-recovery was told their settings were not in
    their backup. The warning and the card now say the RESTORE could
    not put it back, which covers held-back and simply-absent alike;
    two new checks pin it, one of them checking the contradicting
    sentence is still present. **`put_held_back` still wrote a posted
    table name into the activity log** under an event type saying a
    put-back failed — the discard branch had just been fixed for that
    and the put-back branch had not; both route an unknown name to
    `BackupActionRefused` with no name. **Section 18 of the eraser
    test gave an untrue reason 25 lines below its own correction**
    ("still holds their sign-in codes" — a marked row has `rowJson`
    emptied, and never held an e-mail address); the real reason it
    must go is that it still NAMES THE PERSON, and its fixture now
    nulls `rowJson` as the portal does, because **a fixture in a state
    production can never reach is testing nothing**. Also: "UNUSED"
    two-factor codes was wrong in five places — the cascade takes
    every row with a `userID` and `verify.php` KEEPS a spent code with
    `isUsed = 1`, so the record of which codes were spent goes too,
    which is what `WHOLE_DATABASE_ONLY` exists to protect.

    **A THIRD round found three more, and two were failures to APPLY a
    correction I had already written down.** Round 2 recorded that
    "unused" two-factor codes was wrong in five places and that
    "address rows" understated the loss; round 3 found both wordings
    still live in the in-app help page, migration 233's own header,
    section 18 of the eraser test, `CHANGELOG.md`,
    `.claude/HANDOFF.md` and — on the very line recording the
    correction — this file and its mirror. **A document asserting a
    fix while stating the thing it fixed is worse than one that says
    nothing**, because the next reader stops looking. The help page
    mattered most: it is the one sentence a customer reads BEFORE
    deciding whether to accept the loss, and it understated it. **And
    making the lock real made a new path reachable that the broken
    version never could:**
    `prepare`/`bind_param`/`execute`/`get_result` sat OUTSIDE the
    `try`, and under `MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR` all four
    throw (it takes BOTH) — so with
    the lock genuinely held, a second administrator now BLOCKS,
    `TimeLimit::atLeast(900)` means PHP will not cut it off first, and
    at `innodb_lock_wait_timeout` (50s) `execute()` throws ERROR 1205
    and escapes with the transaction OPEN. That gave a bare 500
    instead of the honest message — and `Logger::db()` returns the
    SAME connection, so the `tblErrors` row was written inside the
    open transaction and rolled back on disconnect: **the fault left
    no record anywhere.** The read is inside the `try` now with a
    `finally` that frees and closes on every path. **A check made only
    of ABSENCES passes when the thing stops happening at all** —
    section P asserted the posted name was NOT logged and the event
    type was NOT a failed put-back, and deleting the `$record()` call
    left it green under a name claiming the refusal IS recorded; the
    positive clause comes first now.

    **A FIFTH round found three more. IT TAKES BOTH
    `MYSQLI_REPORT_STRICT` AND `MYSQLI_REPORT_ERROR`, AND I GOT THIS
    WRONG TWICE.** Measured on the STATEMENT path (`prepare()` on a
    missing table): `ERROR` alone returns false and warns, `STRICT`
    alone returns false SILENTLY, only both together throw. My first
    "correction" said `STRICT` does it — because I had measured a
    CONNECT error, which is reported whatever `ERROR` says. **Measuring
    the wrong code path and then writing "measured, not reasoned"
    beside the conclusion is worse than reasoning, because nobody has
    cause to doubt it.** I had corrected the two
    code comments a round earlier and left four documents naming the
    wrong flag: the fifth time in this package a correction was
    recorded and applied in one place. Also a pronoun first defined
    later in its own sentence, and a REFUSED discard logged as
    `BackupHeldRowsDiscarded`, the same type as a successful one, so a
    log search by type counted a run where nothing was discarded (now
    `BackupHeldRowsDiscardFailed`, also added to the page test's
    `BPS_OWN_LINES`). **THEN THE DESIGN CHANGED, and this is the part
    worth keeping:** the warning sentence had reached ninety lines and
    nine nested ternaries, and **four of the six wording faults rounds
    2-5 found were inside it**, every one a ternary in the middle of a
    clause, and NOT ONE findable by any check — nothing judges how an
    assembled sentence reads, and a test pinning a substring will pin
    a substring of nonsense. `DbBackup::cascadeWarning()` now holds
    **EIGHT COMPLETE SENTENCES** keyed by (ahead/done, kept/lost,
    one/many). **When a sentence is built from branches, spell out the
    whole sentence for each branch**, and PRINT the outputs by calling
    the real code rather than transcribing them.

    **A SIXTH round found four more, and the most useful lesson in the
    package: I MEASURED THE WRONG CODE PATH.** My round-5 "correction"
    said `MYSQLI_REPORT_STRICT` makes mysqli throw, with "measured,
    not reasoned" beside it — but I had measured a CONNECT error, and
    the sentence is about four STATEMENT calls. Measured properly
    (`prepare()` on a missing table): `ERROR` alone returns false and
    warns, `STRICT` alone returns false SILENTLY, only BOTH throw.
    `ERROR` makes a statement error get reported; `STRICT` turns a
    reported error into an exception; a CONNECT error is reported
    whatever `ERROR` says, which is why `STRICT` alone throws there.
    **"Measured, not reasoned" is only worth writing if the
    measurement is of the thing the sentence is about** — written
    against a conclusion from a neighbouring path it is WORSE than
    reasoning, because it removes the reader's reason to doubt.
    `bootstrap.php`'s own comment carried the same untruth and is
    corrected (one line outside this package's file list, declared not
    buried). **A hedge that covers one condition and ASSERTS the other
    is not a hedge:** the "what your backup may still hold" sentence
    was wrong BOTH ways round — first hedging table presence and
    ignoring row currency, then hedging currency and asserting
    presence, which is false for the not-in-the-snapshot case and
    false in the REASSURING direction. All three places state both
    conditions now, and the CAUSE is "most likely" because a
    hand-dropped table looks the same. **A FOURTH clause that cannot
    fail** (`discardHeldRows('tblSettings') >= 0`, when the method
    ends `return max(0, $gone)`) — the same shape I deleted forty
    lines below it in the same edit. **And HALF the eight sentences
    were exercised by NOTHING:** the whole `canKeep === false` branch,
    which proof 27's own "what this cannot show" list had not
    mentioned. Section G reaches all four by renaming the holding
    table outside `tbl%` AND taking the snapshot afterwards — an
    un-upgraded database has no holding table and no snapshot
    containing one — and `brs_tidy()` puts it back if that section is
    killed mid-rename.

    **The waiting rows survive a restore now (#697, 31 October
    2026).** `tblRestoreHeldRows` was an ordinary table, so a whole
    restore emptied it and filled it from the snapshot — and a
    snapshot taken BEFORE some rows were kept holds none of them, so
    the waiting rows went and the only copy of them outside that
    snapshot went with them. `readWaitingHeldRows()` and
    `carryWaitingHeldRowsThrough()` are the ONE place a waiting row
    survives a restore; do not write a second one.
    **`WHOLE_DATABASE_ONLY` now has FOUR names** — the holding table
    joined it, which refuses a ONE-table restore of it (that really
    would throw the rows away, with no carry to save them). **I ALSO
    claimed a "second effect", that an OLD-FORM snapshot now leaves
    the table alone, and it is a NO-OP for every snapshot a customer
    can have** — the independent check restored a genuine one and got
    THREE tables held back, not four: a snapshot with no
    `whole_database` flag predates that flag (`b7496b7`, 27 October)
    and the holding table arrived with migration 233 (`ac1b19e`, 31
    October), so it cannot contain it. **The claim survived because
    `brs_legacySnapshot()` read every live `tbl%` table and so built a
    snapshot production cannot produce** — the #645 lesson again — and
    it cost a TRUE sentence in the customer-facing help page ("leaves
    three tables alone") being turned into a false one, in the one
    place a customer reads before accepting a loss. The addition
    changes a WHOLE restore only for a manifest somebody has edited
    the flag out of (section G of proof 28); an old-form snapshot is
    safe for the simpler reason that the table is not in it, as is any
    snapshot that does not contain it. **TWO SAFETY NETS WERE INERT ON
    A SERVER WITH `lower_case_table_names = 1`, AND ONE WAS #694's.**
    `SHOW TABLES` reports lower-case names there, and both this carry
    and #694's cascade rescue compared them with `in_array(..., true)`
    against a name typed in the source — so neither ran and a waiting
    row was lost with the restore reporting SUCCESS and NO warning.
    Measured on `mysql:8.0.36 --lower-case-table-names=1`. **AND
    FIXING THE FIVE `in_array` SITES WAS NOT ENOUGH — I WROTE "all
    five sites use it" IN FOUR DOCUMENTS AND IT WAS FALSE.** An ARRAY
    SUBSCRIPT is the same comparison written another way:
    `$facts['tblRestoreHeldRows']` and `REMOVED_COLUMNS[$table]` went
    on matching exactly, and `$restored` was a FOURTH server-reported
    list holding two more (`tblMailQueue`, `tblSmsMessage`). Making
    the carry RUN without fixing the subscript turned the silent loss
    into a BLOCKED RECOVERY: the carry threw "the shape could not be
    read", so every whole-database restore AND rehearsal was refused
    while any row was waiting, naming the wrong cause — and the only
    way to reach it was #694's rescue succeeding. `namedIn()` compares
    names; `forTable()` is for arrays KEYED by table name;
    `isWholeDatabaseOnly()` had always used `strcasecmp()` for this
    reason, so the two halves of one method had disagreed about what a
    table IS. The two mail/text faults were PRE-EXISTING and cost
    double sends (`holdRestoredMail()`'s own docblock: "three waiting
    messages came out as six copies"). **Every self-test here runs ONE
    server set to 0, so NOTHING here can catch this coming back** —
    the #646 shape — which is why `tools/audit-
    checks/check_table_name_comparisons.py` is a hard gate in `pr-
    security.yml`. **AND THE CEILING USED TO LOSE EVERYTHING IT WAS
    MEANT TO PROTECT:** past `CASCADE_RESCUE_MAX_ROWS` the first
    version carried NOTHING and let the snapshot's older copy replace
    them — 20,001 rows in, ZERO out, measured — while the card and the
    help page both promised that could not happen. The ceiling is now
    decided ONCE, in `restoreAllWork()` BEFORE the transaction, and
    past it the holding table is not put back AT ALL: nothing is lost
    and the answer says so. Two places deciding one ceiling is #668's
    lesson. **And never promise anything about EVERY waiting row:**
    one branch legitimately ends with a row no longer waiting (the
    snapshot records it as already put back, and the restore honours
    that history), so the sentence speaks only of the rows it wrote
    again. **Identity is `tableName`, `siteID`, `userID` and
    `heldAtUtc`, NEVER `rowJson`**, and both reasons are load-bearing:
    a row already put back has had `rowJson` emptied, so comparing it
    would call that a different row and re-offer something the
    restored database says is finished; and MySQL REWRITES a JSON
    column on storage (keys reordered, a space after each colon) while
    MariaDB's `JSON` is an alias for `LONGTEXT` and rewrites nothing
    (measured on both), so a text compare means two different things
    on the two engines — the same trap as the `rowJson LIKE` clause
    that could never fail in #694's eraser test. **THREE OF THOSE FOUR
    COLUMNS WERE GUARDED BY NOTHING, and this is the most useful thing
    here:** narrowing the comparison to `tableName` alone left ALL
    THIRTY of proof 28's checks green while a real waiting row was
    SILENTLY LOST — success reported, no carry warning, row gone —
    because the only fixture reaching the clash branch made its two
    rows differ in `tableName`, the one column a narrowing keeps. **A
    fixture that differs in several ways at once tests only the first
    thing the code looks at.** Section E now has one sub-case per
    column. A row ALREADY PUT BACK is deliberately NOT carried (it is
    history, and a restore puts history back); what goes with it is
    the audit trail of the return, which is a cost, not nothing. A
    carried row keeps its own number where that number is free and
    takes a new one where a DIFFERENT row holds it. **The REHEARSAL
    carries too**, on purpose: it writes inside the transaction and
    rolls back (`holdRestoredMail()` already did), so one that skipped
    the carry could not tell you the carry would fail — but a rolled-
    back INSERT still consumes an AUTO_INCREMENT number, so say "no
    value survives it", never "it changes nothing". **And proof 26's
    refusal loop could not notice a deleted name** — it iterates the
    constant, so removing `tblRestoreHeldRows` just stops testing it;
    the explicit `array_keys($list) === […]` pin is what catches that,
    proved by planting. Three wording faults worth recognising, each a
    repeat: "All N" of a number counting only a SUBSET (the rows
    written again, not the rows waiting); a hedge covering one
    condition and ASSERTING the other, for the third time in two
    packages; and a clause that CANNOT FAIL in section P, forbidding a
    phrase that was never on that page — in the package that wrote
    that lesson down. Nothing exercises the over-20,000-rows path or
    the could-not-read path. **AND THE CLASS TOOK FOUR MORE ROUNDS TO
    CLOSE, which is the real lesson.** Round 3 found a SUBSCRIPT
    (`$facts['tblRestoreHeldRows']`) still exact, so making the carry
    RUN had turned the silent loss into a BLOCKED RECOVERY — every
    restore refused while a row waited. Round 4 found a THIRD site
    (`columnFacts()[$table]` in `putHeldRowsBack()`), where the Backup
    page offered a kept row and **only the button that throws it away
    worked.** Round 5 found that the RULE I had written to close the
    class was wrong BOTH ways. **The true rule: a table-keyed lookup
    is safe when BOTH SIDES COME FROM THE SAME PLACE** — keys typed
    here indexed by a name typed here is safe
    (`CASCADE_RESCUE[$table]`, where capitals matter by design); keys
    from `information_schema` indexed by a name from `SHOW TABLES` is
    safe on one server (#698 is the cross-server residue); one of each
    is the fault. My wrong version — "the fault is an index that came
    from the SOURCE" — CLEARED `REMOVED_COLUMNS[$table]`, one of the
    very faults it was generalising, and CONDEMNED three correct
    sites. **A rule written from one fault's symptoms describes that
    fault, not the class: test both halves against the other known
    sites before writing it down.** `forTable()` is the helper for
    arrays keyed by table name, `namedIn()` for lists of names, and
    neither is "the ONE way" — that phrasing, written while correcting
    "all five sites use it", is the same mistake again. The gate was
    bypassed FIVE times across versions 1-4 (the word rather than a
    call; `strcmp` in the unpinned helper; a two-argument `in_array`,
    which is no safer; a one-hop local; and a real `strcasecmp` call
    with `&& $table === $name` after it), so version 5 pins BOTH
    helper bodies WHOLE — the literal form of "pin the WHOLE
    condition" — and any edit to them fails it on purpose. Tests:
    proof 28 of `tools/backup-restore-selftest.php` (61 checks), proof
    26, section P of `tools/backup-page-selftest.php`, and the hard
    gate `tools/audit-checks/check_table_name_comparisons.py`; thirty-
    one plants over five rounds, each caught by the one check that
    should catch it. The technical record is DEV_NOTES.md, "Carrying
    the kept rows through a restore (#697)".

    **Who may be sent a text, and a one-table restore (#610, #622).**
    `Sms::whyNotThisPerson()` is the ONE place that decides whether a
    person may be sent a text, asked at BOTH moments — by `send()` for a
    text going now (AFTER the decision to hold, never before) and by
    `sendHeld()` when a held text comes due. It answers a REASON, and
    `heldPersonWords()` / `atOncePersonWords()` put that reason into the
    right words; never record the reason itself. JUDGE A PERSON AT THE
    MOMENT THEIR TEXT WOULD GO: asking before the hold was built first and
    silently refused a text for anybody suspended and reinstated inside
    two days. A refused text carries no "sent at", and `record()` is the
    ONE place that keeps the row and drops the NAME when the database
    refuses a person who has gone. Test: `tools/held-texts-selftest.php`.
    **`DbBackup::WHOLE_DATABASE_ONLY` is the ONE list of tables a restore
    of ONE table refuses** (`tblMigrations`, `tblTotpBackupCodes`,
    `tblSettings`, and `tblRestoreHeldRows` since #697), compared only by
    `isWholeDatabaseOnly()` and worded
    only by `wholeDatabaseOnlyReason()`, ignoring capitals because
    `lower_case_table_names` is a server setting; the Backup page asks
    both and keeps no copy. A WHOLE-database restore is untouched and is
    the supported route. Before adding a table, ask whether the rest of
    the database would still make sense beside it. Tests: `tools/backup-
    restore-selftest.php`, `tools/backup-page-selftest.php`.

    **Every two-factor gate takes the sign-in away FIRST (27 October 2026).**
    There are FOUR gates that demote a session to "waiting for a code" — the two
    sign-in callbacks in `Auth.php`, `_apps/auth/login/index.php` and
    `_apps/auth/login/webauthn.php` — and in each,
    `unset($_SESSION['user_id'], $_SESSION['2fa_passed'])` comes BEFORE anything
    else, `login_redirect` included. All four had it the other way round: a
    request chooses the TYPE of what it sends as well as the value, so
    `?redirect[]=x` made `Auth::safeRedirectUrl()` throw while `user_id` was
    still set, PHP wrote the session at shutdown, and the next request saw
    somebody signed in who was never asked for a code. **On the password form
    that needed nothing but the person's password.** `Auth::safeRedirectUrl()`
    therefore takes `mixed` and refuses a non-string with the fallback: never
    narrow it back to `string`, and never put a call that can throw between
    granting an identity and the check that earns it. A gate found by searching
    for `$_GET` will be missed — the passkey path reads the posted JSON. Checks,
    and BOTH are needed because neither catches the other's half:
    `tools/audit-checks/check_2fa_gate_order.py` (the order) and section Z of
    `tools/two-step-selftest.php` (the behaviour). Reversing the order alone is
    invisible to the self-test; that was measured, not assumed.

    **An OAuth `state` must be a non-empty STORED value (27 October 2026).**
    Both callbacks compared `$_SESSION['oauth_state'] ?? ''` with
    `$_GET['state'] ?? ''`, so an address carrying an empty `state=` passed when
    no sign-in had been started: `hash_equals('', '')` is true. That signed a
    visitor into whoever's authorization code was supplied. The stored value is
    now required to be non-empty before anything is compared, and a non-string
    is refused so `?state[]=x` cannot reach `hash_equals()`. Do not simplify
    that condition back.

    **Two-factor authentication (#613).** `auth/2fa/setup.php` is the ONE place
    backup codes are made, and it shows them only in the answer to the request
    that made them: never put a code, or the authenticator key, readable in
    `$_SESSION`. A code is single-use by `… AND isUsed = 0` plus "one row
    changed" in `auth/2fa/verify.php`, and is looked for among the person's own
    codes only. The old set is removed row by row after locking the person's own
    account row, never by `DELETE … WHERE userID = ?` (two people at once
    deadlock). Test: `tools/two-step-selftest.php`, alone on its server.

    **Time zones (#604).** `Site::timezone()` is the ONE place that answers
    an organisation's zone, and `Site::isZoneName()` the one test of what a
    zone name is. Do not read `tblSites.timezone` anywhere else, and do not
    bring back a `site.timezone` setting. `Site::zoneFor()` gives the zone
    to read a stored event time in. A stored event time is a reading of the
    event's own clock: never pass it through `strtotime()`/`date()`. Every
    `INSERT INTO tblEvents` names `timezone` (the column has no default).
    Migration 217's mark (`site.timezoneKeptAt`) is written by the install
    script only into a database that never held the old arrangement, so the
    installer's "carry on" choice keeps every organisation's zone. A column
    removed by a migration goes on `DbBackup::REMOVED_COLUMNS`. Giving
    retention deliberately does NOT follow the organisation's zone. Test:
    `tools/time-zones-selftest.php`.

    **A reminder e-mail now obeys the member's switch, and the morning
    summary belongs to ONE organisation (#666, #616, #683).**
    `WebPush::notifyPrefAllows()` is the ONE test of a notification
    preference and is public for that reason: the idiom is implemented FOUR
    times already (`WebPush::notifyPrefAllows()` public, `Workflow`'s
    private copy, `cron/user-reminders.php`'s function,
    `Giving::isOptedOutOfStatements()` inverted) and `sendReminderBatch()`
    in `_apps/cron/event-reminders.php` asks it rather than making a fifth.
    **`ImportNotifier` is NOT a copy — it already ASKS `WebPush`, which is
    the precedent this follows** — and `auth/account/save.php` only writes
    the column; counting those two gave "six copies", which reached four
    documents and two comments before the independent check killed it. Count
    function bodies, not file names. **A missing key, empty text or
    unreadable JSON all mean YES**, so nobody who has never opened the
    Notification preferences page loses a reminder. **The switch covers the
    DAY-BEFORE and ONE-HOUR reminders only** (both go through
    `sendReminderBatch()`): the DAY-OF SUMMARY is not filtered by it at all
    and never was — it goes to the event's coordinators and the
    organisation's administrators, not to members who answered "going", and
    the switch's own words are "Get notified ahead of events you have RSVP'd
    to". Never write "it stops the e-mails" unqualified; silencing the
    summary too would be a decision, not a tidy-up. Until 1 November 2026
    the reminder query asked only who had answered "going". **Be exact about
    how little the switch did: it was saved and read by NOTHING** — `git
    grep -n eventReminders 8810a06 -- web/` finds only the two account pages
    that write the key and draw its tick-box (each a view and a handler, so
    four files), plus two schema column comments — no reader. Quote the
    command WITH its `-- web/` filter: without it the citation returns more
    than the sentence claims. The push is gated on a DIFFERENT key,
    `pushServiceReminders` ("Service reminders"), so switching this one off
    changed nothing anywhere, e-mail or push. Saying it was "honoured for
    the companion push" is false, flatters the old code, and replaced a
    comment on the page that had been RIGHT; it reached five places before
    the independent check caught it. A consent failure on a live mail path,
    under a standing notice on the page admitting the e-mails were not
    filtered. **ONE ADDRESS, TWO ACCOUNTS:** the recipient map is keyed by
    e-mail and two accounts can share a mailbox, so a refused row is dropped
    BEFORE the merge — an address is written off only when every account
    reading it has said no, and a refusing account can never upgrade
    somebody else's e-mail to the one naming the location. The morning
    summary joins `tblUserSites` on the EVENT's organisation (#616): the
    portal-wide `isAdmin` flag still counts, because that is how a
    single-organisation install marks its administrator, but it now reaches
    only the organisations that person actually belongs to.
    `Mailer::renderBase()` takes a site id and
    `Mailer::send()`/`sendTemplated()` read it from `$options['siteId']`
    (#683) — a scheduled job sends across every organisation with
    `$SETTINGS` merged once, and `Site::forceContext()` does NOT re-merge
    it, so without that the footer's name and support mailbox stayed with
    whichever organisation the job resolved to while the LINK followed the
    context, which is the hardest kind of half-right output to notice. **The
    issue blamed the reminder job and could not have been right:** all three
    reminder bodies are HTML and `renderBase()` only wraps a body that does
    not look like HTML, so no reminder e-mail has ever carried a footer; the
    live instance was the giving statement. **Three traps in testing this,
    all measured.** `tblEventReminderLog.recipientCount` counts only a
    message a mail service really TOOK, and part T of the time-zones test
    deliberately points the portal at an empty SMTP mailbox, so it is 0 for
    every message that went out perfectly well — read
    `tblEmailLog.toRecipients` through `mailTo()` instead, which says WHO.
    **Part T switches every account but one member OFF**, and the summary's
    query asks `u.isActive = 1` where the reminder's does not, so the first
    #616 check PASSED ON THE PRE-FIX QUERY planted back from `git show
    HEAD:` — the administrator was unreachable, not excluded. And the plant
    tried before that one, loosening the join to `LEFT JOIN`, ALSO passed:
    **a plant that will not go red means the fixture is dead just as readily
    as it means the code does not matter, and the passing run cannot tell
    you which.** The check now asserts the positive half too — the same
    administrator IS told about their own organisation's event — because
    "not told" that could mean "unreachable" is the fault it just had. **AND
    IT STILL PROVED NOTHING ABOUT THE QUERY, which the independent check
    found and is the better lesson:** that administrator holds all THREE
    ADMINISTRATOR grounds at once (the fixture loop passes one `$isAdmin`
    into `isAdmin`, `isRootAdmin`, `isSiteAdmin` and `isSiteRootAdmin`
    alike, though the query never reads `isRootAdmin`), so **each of those
    three `OR` clauses could be deleted on its own with every check green**
    — measured, one at a time. **THREE, NOT FOUR, and I wrote four in five
    places:** the COORDINATOR clause was never deletable-green, because that
    administrator coordinates nothing. **Claiming a measurement that was
    never made is worse than claiming none**, since the next reader has no
    cause to re-run it. **The REFUSING conditions were worse and all three
    were INERT:** `AND us.isActive = 1`, `WHERE u.isActive = 1` and `AND
    revokedAt IS NULL` could EACH be deleted with all 134 checks green,
    because nothing in that world could trip any of them — three separate
    production leaks (a person removed from an organisation, a closed
    account, a coordinator stood down, each still sent the morning diary).
    **A condition no fixture can trip is not a condition**, and two of those
    three were only found in the THIRD round, because rounds 1 and 2 looked
    at the clauses that ADMIT. **One account holding several flags cannot
    tell them apart; give each ground a person who holds it and nothing
    else, and each refusing condition a person who trips it.** Seven people
    now stand in that world. `tools/mail-queue-selftest.php` also calls this
    job over HTTP, but its day-of check is satisfied by the portal-wide flag
    alone, so do not count it as cover. Test: the three #666/#616 checks in
    `tools/time-zones-selftest.php` (134).

    **The "We're live" push (#612).** In `web/_apps/admin/livestream/index.php`
    the action `notify_live` is the ONE action open to an event's coordinator:
    the posted event decides, and must belong to the organisation
    (`HostConsole::eventBelongsToSite()` and `Auth::isCoordinatorOf()`, the same
    two calls the Host Console uses to draw the button).
    `WebPush::sendToChannel()` addresses only a signed-in person's device, and
    `api/push/subscribe` refuses a request that is not signed in. The counts on
    Admin, Integrations, Push copy the sender's rule: change both together.
    Test: `tools/go-live-button-selftest.php`.

    **Outside calendars (#514).** `EventVisibility::where()` is the ONE
    place that decides who may see an event. Every place that reads
    `tblEvents` for a viewer uses it, or refuses imported events
    (`externalFeedID IS NULL`);
    `tools/audit-checks/check_event_visibility.py` enforces that only by
    finding one of those near the word `tblEvents`, so it cannot see a query
    built far from it. `FeedImporter` is the ONE writer of an imported
    event; nothing else may edit one. An address a person typed is fetched
    only through `SafeFetch`, and an address is never logged or printed.
    Every writer of a calendar takes the calendar's row lock first
    (`FeedAdmin::inFeedLock()`, `FeedImporter::lockFeed()`), and an
    incomplete download never removes anything. **A new kind of "who may see
    it" entry changes four places together** (the rule in `EventVisibility`,
    its fast copy `FeedAdmin::whoCanSee()`, `ExternalAudience`, and the
    picker `admin/calendar/_audience-picker.php`) and needs people of that
    kind in the made-up worlds of both `tools/event-visibility-selftest.php`
    and `tools/feed-admin-selftest.php`, or the tripwire cannot see a copy
    that ignores it. `Portal\Core\ImportNotifier` is the ONE place that
    decides who is told that imported events are waiting, and what they are
    told. A person is told only if `EventVisibility::isAdminOfSite()` says
    yes, and the events in the e-mail come from
    `FeedAdmin::pendingApprovals()`: do not write a second rule. Its only
    memory is `tblExternalApprovalNotices`; `emailLocalDate` there is a date
    on the organisation's own calendar, never a UTC date. The push goes
    before the e-mails, and a push claim is always followed by its call.
    Quiet hours hold nothing (owner, 13 October 2026). Nothing it logs or
    pushes may name a person, a calendar or an event, and a fault's own
    words are never logged. A notification switch is added to
    `notifications.php` and `notifications-save.php` together. The guide
    `/help/calendar-imports` is pinned by check W50 of
    `tools/feed-admin-selftest.php`: change both together. Tests:
    `tools/event-visibility-selftest.php`, `feed-resolver-selftest.php`,
    `feed-importer-selftest.php`, `feed-admin-selftest.php`,
    `ics-reader-selftest.php`, `safefetch-selftest.php` and
    `import-notifier-selftest.php`. The technical record is DEV_NOTES.md,
    "Outside calendars (#514)".

    **Changing a repeating series' pattern (#669, migration 232).**
    `Portal\Core\SeriesRepattern` is the ONE place that decides what a
    pattern change does: it takes FACTS and returns a plan (re-time, add,
    remove, leave alone with a reason), opens no connection and has no clock
    of its own, so the preview page and the handler that applies it cannot
    disagree. **A date that stays is UPDATED IN PLACE — only its start and
    finish — and that is the whole design:** it keeps a hand-changed title,
    venue or description safe without having to detect those changes at all,
    and re-creating instead would destroy every attached record through the
    CASCADE rules. **Pairing is by the DAY, never the time**, which is why
    one rule covers all four operations. `patternStart` AND `patternEnd`
    record where the pattern put each date, so "moved by hand" is WORKED OUT
    rather than remembered; **both are compared, and comparing the start
    alone let a hand-lengthened service be silently shortened again** while
    the docblock promised it caught "a changed TIME". A move must write BOTH
    or every date reads as hand-edited next time and the page stops working
    after one use. `updatedAt` cannot stand in, because `ON UPDATE
    CURRENT_TIMESTAMP` means the bulk-edit page already moves it. Neither
    column is backfilled — claiming every existing date is untouched would
    move exactly the dates they protect — and an unrecorded date falls back
    to re-reading the series' current rule, which judges the START only and
    is the only reason the feature works on an installation that already has
    series. **The recorded origin wins over the rule when both could
    answer.** `SeriesRepattern::ATTACHMENTS` names all **34** tables that
    NAME an event and is the only thing stopping a change hiding a date that
    carries a register; it LOOKS complete whatever is in it, so
    `tools/audit-checks/check_series_attachments_complete.py` is a hard gate
    failing BOTH ways. **A FOREIGN KEY IS NOT HOW EVERY TABLE SAYS IT, and
    building the list from the schema's keys is how the two most sensitive
    tables were missed:** `tblEventRSVPs` (`eventID INT NOT NULL`, no key,
    holding every member's going/maybe/no answer — the thing the owner's
    decision names out loud) and `tblZoomMeeting`. The check now looks for
    the `eventID` COLUMN, excluding a table whose own auto-increment key is
    called that (`tblWebhookEvent`). Its docstring had asserted "nothing in
    this schema does that today (checked)" — written WITHOUT checking, and
    false. Two parsing traps too, each got wrong first: a key can be
    declared by `ALTER TABLE` (that is how `tblAttendanceSessions` gets its,
    and a walk-back-to-CREATE parser blamed `tblEvents` AND missed it), and
    comments must be stripped before matching. **With no foreign key the
    schema never stops an event row being deleted out from under these two.
    Be precise about what that costs, measured rather than reasoned: this
    page SOFT-deletes, no ON DELETE rule fires on an UPDATE, so all three
    values behave IDENTICALLY here - every attached row survives pointing at
    a date nobody can see, for all 34 tables. `'no rule'` is the worst of
    the three only if something later really DELETEs the row.** A date the
    pattern ADDS takes the series' name, venue and settings and NONE of one
A date the pattern ADDS **arrives as a DRAFT** (#692, the owner, 31 October 2026 — D-033) — it does NOT
The dates it adds are **published from the series' BULK EDIT page**, which the handler redirects to
    — it lists every date including drafts with a tick-box each and a bulk `Status` control, so a
    whole change's worth goes in one step. **Do not claim it is one at a time** (#696 was raised on
    that assumption and closed). **And the message must NOT say subscribers see one repeating entry
    while any date is a draft:** `calendar/export.php` reads a run with `AND e.status = 'published'`
    and collapses only when the PUBLISHED dates match the rule, so the sentence has THREE answers
    (all published / rule written but drafts remain / no rule), pinned by R12b and S15d. The rule
    row itself is still decided on the dates alone.
    inherit the template's status, and the template's status is not read at all. It has no
    registration window of its own (the template's `registrationClosesAt` is a moment just before
    the TEMPLATE's date) and nothing beyond the series' description, so publishing it at once puts a
    half-finished entry in front of members; and a hidden date cannot be brought back (#691), so the
    recoverable mistake is the one to make. **The preview AND the message afterwards must BOTH say
    so** ("added as drafts"), pinned by S15b and S15c of `tools/event-repeat-pages-selftest.php`.
    Publishing a whole change's worth at once is #696.
    date's own history: never born cancelled, and carrying no cancellation
    reason, moderation note or submitter name and e-mail — the copy took
    every column but four, and `GdprEraser` clears neither `submitterName`
    nor `submitterEmail` on `tblEvents`, so those copies could not have been
    erased on request. **Its address is asked of the database, never built
    from the date's position in the add list:** `uq_event_site_slug` covers
    SOFT-DELETED rows, so a day removed and re-added collided and the whole
    change was rolled back for ever, intermittently. The old rule row is
    ALWAYS removed and a new one written only when the series then holds
    exactly the pattern's dates — otherwise subscribers see each date on its
    own (#670), which is the usual outcome and is said in the success
    message. The remedy link for a date left alone is `?edit=`, because
    `calendar/manage/index.php` reads that and reads `eventID` NOWHERE.
    **`(string)` on a numeric array key is load-bearing:** PHP makes `'1'
    =>` an INT, and under `strict_types` `htmlspecialchars(int)` is a
    TypeError — the page rendered most of its form, threw, and the error
    handler printed the footer, so the answer was HTTP 200 ending in an
    ordinary `</html>` with the preview silently missing, invisible to `php
    -l` and to every audit script. **And the gap worth keeping: removing the
    "did they press confirm" test failed NOTHING**, because the only request
    carrying a plan fingerprint was one that also confirmed — yet the hidden
    fingerprint sits in the same form, so a real browser's second "Show me
    what would change" would have applied the change. A guard never
    exercised alone is not covered. A date the pattern ADDS also takes NO
    registration window - the template's `registrationClosesAt` is a moment
    just before the TEMPLATE's own date, so a date added weeks later arrived
    with registration already closed, and both register pages treat that
    column as a hard gate. `registrationEnabled` is given its DEFAULT and
    not NULL, because it is `NOT NULL` and binding null fails the whole
    INSERT. The added date's `timezone` is the zone the READINGS were made
    in, never the template's. **And never say a hidden date can be put back:
    there is no restore path anywhere in this repository (#691), only
    `FeedImporter` ever clears `isDeleted`.** Tests:
    `tools/series-repattern-selftest.php` (72, no database) and sections R
    and S of `tools/event-repeat-pages-selftest.php` (247 over HTTP; it
    needed a third, non-administrator account because both existing ones are
    admins). **SIX of its checks were VACUOUS and planting found every
    one:** two passed on the rule row written at CREATION time; one pinned
    the recorded finish on a change that re-timed nothing; the slug check
    removed dates the SAVE HANDLER had named, whose addresses cannot collide
    with the page's; the "last entry in the constant" claim was false
    (`tblEventRSVPs` is 33rd of 34, so a loop stopping ONE table short still
    passed); the zone check could not fail while every event in the test
    world shared one zone; and the Zoom check attached a record to a date an
    earlier check had already removed. First, 33rd and LAST entries are
    exercised now - a loop skipping a MIDDLE table is still not caught, and
    the wording says so. Nothing covers two administrators racing on one
    series; the attachment list is re-asked inside the transaction, which
    closes the administrator-versus-door-steward window only. The technical
    record is DEV_NOTES.md, "Changing a repeating series' pattern (#669)".

    **The installer asks for an ownership code (#499, #500).**
    `web/_install/owner_code.php` is the ONE place a code is written, counted
    and compared (`hash_equals()`); nothing is shown or acted on without it, and
    the finish asks again. Read the code file only through `installCodeRead()`
    (it waits for the writer's lock). The installer refuses when opened
    directly, and when the address has the web folder's name in it. **A choice
    for a database that is not empty ("carry on" or "wipe") belongs to ONE
    database:** read it only through `installChoiceHeld()` in
    `web/_install/db_state.php`, never from the session. The private folders'
    "refuse everything" file and the words for the channel are in
    `web/_install/install_common.php`, shared with the upgrade page. A missing
    `.channel` file never stops an install. Test: `tools/installer-
    selftest.php`.

    **The installation package and upgrades (#499).**
    `tools/package/build.sh` makes the package from git, `check-package.py`
    guards it, and `docs/INSTALL.md` (shipped as `INSTALL.md`) is the
    customer's guide to installing AND upgrading. **The portal is closed for
    an upgrade only while a database upgrade is really waiting:** that is
    decided in ONE place, `Maintenance::isActive()`. **The portal never
    records the version by itself**: `Maintenance::recordVersion()` is the
    one statement that records it and only the Upgrade page's button calls
    it; until then every request asks `Migrator::waiting()` once (about half
    a millisecond), and with the versions equal nothing is asked. A page
    that only asks uses `Maintenance::versionIsBehind()` or
    `upgradeNotice()`. Admin, Migrations runs nothing while the version is
    behind, and is for a global administrator only. The recorded version is
    read from the installation's own row only: start-up passes over one
    organisation's row of that name, and Admin, Settings refuses the setting
    (`Maintenance::lockedInGeneralEditor()`). When the portal cannot tell,
    `Migrator::waiting()` gives the cause. **A package carries NO `.channel`
    file:** the installer's finish writes it from `VERSION.txt`, only when
    there is none (`installWriteChannelFile()`), so an upgrade can never
    replace it. Never commit `web/.channel`. The domain guard's names come
    from `OWN_DOMAINS` in
    `tools/audit-checks/check_no_hardcoded_domains.py`: that is the ONE
    list. Tests: `tools/upgrade-pages-selftest.php`,
    `tools/package/package-selftest.sh`.

15. **The ApiRouter trap.** Any address starting `api/` is intercepted
    before the normal routing table is ever consulted, and resolved purely
    by naming convention to `_apps/{appName}/api/{action}.php`. A handler
    placed anywhere else, or a row added for it in the normal routing table,
    is simply never reached. Each such endpoint also needs a setting
    `api.{appName}.{action}.enabled = 'true'` seeded in the database, or the
    router answers 403 regardless of the code being correct. A new `api`
    folder also needs a line in `AppRegistry::API_FOLDER_APPS` (#478) saying
    which app it belongs to, or `null` for none; without one the router
    refuses it, and an app switched off in Admin → Apps answers 403 for all
    its endpoints. Adjacent trap:
    the response helper class exposes `::success()`, not `::ok()`, and its
    header-setting method is private — check the real class before calling
    it. *(`.claude/rules/api-router.md`.)*

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
    security decision, not routine cleaning, and it needs the owner's
    explicit approval first (owner, 5 October 2026). Before adding a new address,
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
    perfectly fine, thing. *(`.claude/rules/app-pages.md` → "Two variables,
    and only two".)*

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

20. **Nothing may look unfinished, careless or AI-made (owner, 4 October 2026; this repo and every project).** Every page —
    including pages behind a sign-in (owner's choice) — has its own title, meta description, favicon, Open Graph tags and image,
    canonical address and social preview; there is a real "page not found" page, loading and empty states, and plain-English error
    messages; headings in order and alt text on images; no console errors, leftover debug output, exposed development files or
    oversized scripts; layouts hold at every screen size and with long text, empty data and errors; spacing, fonts, buttons, corners,
    icons and components match from page to page; every button and link really works; no default, preview or example address (#500).
    Checked automatically before every push by a git pre-push hook (not built yet — part of the audit package #569, queued after #514
    part 8; until then run the checklist by hand and say so), with a full browser audit before every release. The hook is one per
    repository, with its own installer, because git never copies hooks between clones (owner, 5 October 2026). *(`.claude/CLAUDE.md` →
    "Nothing may look unfinished…"; the full checklist is in `~/.claude/CLAUDE.md`.)*

21. **Narrow checks, then move on (owner, 28 September 2026).** For work built in chunks and committed once: after a chunk's first
    full check, each later round checks only the latest fix round's changes, still by a new agent; one fresh whole-part check
    happens once, just before the single commit; each round re-runs its own new planted faults plus a sample of earlier ones, and
    the full set runs once, before that commit. If a chunk goes past three check rounds, say so plainly in the next report, put the
    ways to speed up under "Blocked on me", and carry on meanwhile. If the same item fails two rounds running, consider simplifying
    the design before refining it again. This does not relax the final check before a commit.
    *(`.claude/CLAUDE.md` → "Working with the owner".)*

22b. **Every check now RUNS, and that was not true until 28 October 2026
    (#646).** Ten scripts in `tools/audit-checks/` and two `*-selftest.php`
    files were invoked by NO workflow — 26 of 36 checks and 51 of 53 tests were
    wired. Every one of the twelve guards something found broken at least once,
    so twelve correct guards sat over twelve faults that had each happened
    before. All twelve now run on every pull request; the ten checks are HARD
    GATES with `if: always()` and no `|| true`.

    The worst was `check_2fa_gate_order.py`, the tripwire for the sign-in bypass
    of 27 October. Its own note says TWO checks are needed because neither
    catches the other's half — the script watches the ORDER, section Z of
    `two-step-selftest.php` watches the BEHAVIOUR, and **reversing the order
    alone is invisible to the self-test**, which was measured at the time. The
    half that catches a repeat was the half that ran nowhere.

    THREE MORE, each found by an independent check of the wiring itself:
    - **Ask what a test NEEDS, not whether it passes here.** Wiring
      `qr-selftest.php` needed `gd`, which **no workflow in this repository had
      ever asked for** and which `setup-php` does not install by default. The
      test refuses outright without it, so the step would have reddened the job
      and checked nothing. It passed locally because this Mac has gd, and no
      green run had ever proved the runner did. Read a test's refusal
      conditions and check each against the job's `extensions:`.
    - **A step id must start with a letter or `_`.** `id: 2fa_gate_order` may
      make the entire workflow invalid — taking every other check with it — and
      `steps.2fa_gate_order.outcome` is not valid expression syntax regardless.
    - **A fallback needs `continue-on-error: true` on the step it falls back
      from.** Without it the job is already failed, so the fallback branch runs
      into a red run and changes nothing.

    TWO THINGS TO KNOW BEFORE EDITING A WORKFLOW FILE HERE:
    - **A `#` preceded by a space starts a comment.** `name: Role keys (hard
      gate, #646)` silently became `Role keys (hard gate,`. Twelve step names
      were truncated that way. Quote any name holding a `#`. Existing names like
      `(#552, …)` survive only because their `#` follows a bracket.
    - **Parse the file before pushing.** There is no `actionlint`, `yq` or
      `pyyaml` on this machine, but Ruby is there:
      `ruby -ryaml -e 'd=YAML.load_file(ARGV[0]); ...'` parses every workflow
      and counts its steps. That is what found the truncation and two stale
      counts in a header comment. A step skipped by its own `if:` looks wired
      and is not, and this repository has had a `|| true` that made a check
      "documented as always-blocking" unable to fail the build.

    **Not proved:** #646 asks for each check to be proved running by pushing a
    branch with a planted fault. That needs a push, which is the owner's. What
    is proved is that every step exists as the YAML parses it, none is skipped,
    none has an escape hatch, each check passes on current code, and three of
    the ten refuse the exact fault they guard.

22. **What the automatic checks are now (#499, 27 October 2026).** On every pull request: `pr-security.yml` (the hard
    gate — `php -l` on every file, a secrets scan, and most of the scripts in `tools/audit-checks/`),
    `php-static-analysis.yml` (`php -l` again, as its own required check), `codeql.yml`, and the self-test jobs
    `portal-selftests.yml`, `mail-selftests.yml` and `calendar-selftests.yml`. On a pull request that touches the
    files they are about, and only then: `backup-selftest.yml` and `e2e-migrations.yml`.
    - **Psalm is gone** (13 October 2026). It installed itself, failed to find an autoloader, swallowed the failure,
      wrote an empty report and went green. A check that cannot fail is worse than none, because it looks like a
      pass. Do not bring it back in that shape. With it gone, **no code-analysis check in this repository can turn a
      run red**: `codeql.yml` still reads the JavaScript but keeps `continue-on-error`, because GitHub's Code
      Scanning is not switched on here and the upload half of that step would fail on every pull request. The owner
      chose on 26 October 2026 to keep it and say so plainly rather than hide it. Tracked as #618.
    - **A path-filtered check must never be made a required status check.** GitHub has no "required only when it
      runs", so the branch rule waits for a report that never comes and the pull request can never merge. The same
      goes for anything in `package.yml`, which has no `pull_request` trigger at all. A checklist naming three such
      checks was written for the owner on 26 October and caught by its independent check the next day.
    - **Not every test runs automatically.** `tools/page-scripts-selftest.php`, `tools/qr-selftest.php` and seven of
      the 32 scripts in `tools/audit-checks/` are named in no workflow — two of those seven being the tripwires added
      for #625 and #622. A tripwire nothing runs is a comment. Tracked as #637.
    - **Installation zips and releases are automatic** (`package.yml`): a merge into `main` publishes the release, a
      merge into `rc` a pre-release, and a merge into `alpha` or `beta` keeps the zip as a download on the run.
      **Pushing a `v*` tag by hand publishes nothing and still takes the name.** `release.yml` is deleted. A merge
      GitHub makes by itself raises no event, so `auto-merge-alpha.yml` starts both `deploy.yml` and `package.yml`
      after an alpha merge; anything else that should happen on such a merge needs a line there too.
    *(`.claude/CLAUDE.md` → "Monitor and fix the pull-request security checks".)*

23. **Erasure and the activity log (#626).** Every activity record must NAME ITS PERSON in `userID`:
    `Logger::activity()` fills it from `sessionFacts()['userID']` when the caller passes none (never
    `waitingForCodeUserID`, and never `kioskUserID` — which is a real member's account, not the machine's, and is left
    out because a kiosk session outlives the person at it; a kiosk record therefore still names nobody). It never
    overrides an explicit number. That is what makes the ONE
    hand-written instruction in `GdprEraser::catalogue()` enough — an erasure matches one column. **If you are ever
    tempted to add a second way of finding a person's activity records, the fault is that something wrote a record
    without naming them: fix that instead.** Matching on the session fingerprint was rejected because a shared
    machine means one fingerprint is not proof of one person. Older records are brought into line by
    `ActivityLogCleaner::linkAccounts()`, which MUST stay inside the existing walk and BEFORE its blanking step (the
    old whole-session copy is the only place such a record names anybody), and which CLEARS rather than links a
    record whose account has been erased — linking it would undo that erasure. Migration 221 resets
    `activityLog.cleanedThrough` to send the cleaner back through the log. The pinned list `$leftBehindOnPurpose` in
    `tools/gdpr-coverage-selftest.php` is **EMPTY and pinned empty** since #635 (2 November 2026) — it used to name
    35 tables and this line used to say so, which is now the stale shape #678 warns about. The decision for every
    one of those tables moved into `web/_core/personal-data-catalogue.php`, beside the column, as `notEmptiedHere`.
    Nothing may be added to the pinned list. Tests: `tools/activity-log-selftest.php`, section 17 of `tools/gdpr-eraser-selftest.php`,
    `tools/gdpr-coverage-selftest.php`, phases 1 and 7 of the migration harness.
    *(`.claude/CLAUDE.md` → the erasure bullet.)*

24. **The money pages, and when a claim may be changed (#647, #651; 28 October 2026).**
    All FOUR writers of an expense claim — `approve/save.php`,
    `withdraw/save.php`, `treasury/save.php` and `edit/save.php` — open a
    transaction, re-fetch the claim with `SELECT … FOR UPDATE`, and refuse when
    what they expected has changed. **The payment page was the only one without
    that**, so a claim could be paid twice: two treasurers, or one person
    clicking Save twice, each wrote a payment reference, each set the claim to
    `Reimbursed`, and each sent the claimant a "you have been reimbursed"
    e-mail. A unique key on `tblExpenseClaimPayments(claimID)` is deliberately
    NOT the fix, because the `payAmount` column's own comment promises partial
    payments (#650).

    `Portal\Core\ExpenseRules::whyNotEditable()` is the ONE place that decides
    whether a claim may still be changed. Do not write a second status test in a
    page. It takes **facts rather than a database connection**, because the save
    handler asks it TWICE — once cheaply, and again on facts read INSIDE its row
    lock — and a method that did its own reading could not serve the second
    call.

    The rule is **"a claim nobody has decided on"**, never "a Pending claim". A
    claim in a department needing several approvals STAYS `Pending` after the
    first approval, so editing it then would leave an approver's recorded
    approval sitting against figures they never saw. Two details: the count test
    is `!== 0` and never `> 0` (the count answers -1 when it could not be read,
    and "we could not tell whether anybody has decided" must never become
    "nobody has, go ahead"); and ownership is answered BEFORE the status, so a
    stranger is never told that a claim exists or where it has got to. Only the
    claimant may change a claim — not an administrator, for the same reason as
    #545.

    **The HTTP harness cannot test the payment race.**
    `tools/access-pages-selftest.php` starts PHP's built-in server and
    deliberately does `unset($env['PHP_CLI_SERVER_WORKERS'])`, so it handles one
    request at a time and two payment requests never overlap. A race test built
    on it passes on the broken code. Tests:
    `tools/expense-payment-race-selftest.php` (two real database connections,
    plus the handler read as text, because neither catches the other's half) and
    `tools/expense-edit-selftest.php` (section D walks the fraud sequence on
    real rows, at the level of the rule — it does not load the handler, which
    is section C's job). The technical record is DEV_NOTES.md, "The four money
    writers all lock the claim".

    `ExpenseRules::paymentReferences()` is the ONE place payment references are
    read. It used to be inline on the payment page, and the page's self-test
    held its own COPY of those lines — so an independent check altered the
    PAGE's copy alone and every check still passed, while the drift would have
    written an EMPTY payment reference against a real claim. **A test holding
    its own copy of the logic it tests is not testing anything.**

    FOUR TRAPS FOUND BY PLANTING FAULTS, worth knowing before writing a check
    of this kind:
    - A check that searches a page for a CALL can be satisfied by an HTML
      COMMENT that merely mentions it. `codeOnly()` keeps inline HTML
      deliberately, so the `<script>` check can still fail; use `phpOnly()`
      when you mean "what does this page DO".
    - A brace-matching walk must STEP OVER STRING LITERALS. One `{` inside a
      log message made the walk swallow 2,432 characters — the rest of the
      function — and the check then passed on a security gate that had been
      deleted.
    - `substr_count` of a shared SQL prefix cannot tell statements apart.
      Three statements shared one WHERE prefix, so removing the organisation
      scoping from one left the count intact and the check passed while
      asserting the opposite. Name each statement.
    - **Run the audit scripts and READ THEM.** Several report a problem and
      still exit 0. `check_schema_seed_parity.py` named a missing migration
      mark in its output while exiting 0, and "36 of 36 passed" measured by
      exit code alone nearly shipped migration 223 unrecorded — which would
      have made every fresh installation offer an upgrade with nothing to do.


25. **A download that is not whole must never be trusted to remove anything
    (#645; 28 October 2026).** `IcsReader::parse()`'s `complete` flag is the ONE
    thing `FeedImporter` asks before letting a refresh remove events. It is
    false unless the calendar started, the calendar ended, nothing was dropped,
    we did not stop early, AND **nothing was left open**. That last term was
    missing, so a download cut off INSIDE an event was reported as a whole
    calendar and the importer marked every event after the cut as removed — a
    dropped connection part way through a transfer being an ordinary thing.

    The test is `count($stack) > 0`, **not** `$current !== null`. The stack also
    catches an unclosed `VALARM` inside an event that ended properly (previously
    reported whole, with no warning at all), and it works because `END` pops
    only on a match, so one unclosed nested block keeps everything above it on
    the stack. A well-formed file leaves the stack empty.

    **A comment beside the calculation claimed the flag was "false anyway".** It
    stated the guarantee the code had forgotten to implement, and that is why
    the fault survived several reviews: the comment stopped people looking. Check
    I7d guards the opposite risk — if this rule over-reaches, the portal
    silently stops tidying any calendar away, for ever.

    **A repeat landing in the hour that does not exist** (no 01:30 on the night
    the clocks go forward) moves FORWARD to 02:30, keeps its exact length, and
    still counts against `COUNT`, so `COUNT=3` gives three dates. That is right
    and deliberate — skipping it would mean a series quietly delivering two of
    three dates one night a year — and it matches Google, Apple and Outlook. It
    is now written beside `generateStarts()` with check C13 on it. Changing it is
    the owner's decision, not a tidy-up; change the docblock and C13 together.

    **The clocks going BACK is now settled too (#653, fixed 28 October 2026.)**
    `IcsReader::laterOfARepeatedReading()` is the ONE place that decides what a
    reading in the hour that happens TWICE means: **the LATEST moment carrying
    that reading**, worked out from the zone's own transitions. A check (I34c)
    holds the repeating and one-off paths to the same stored readings.

    **NEVER go back to `new DateTimeImmutable($text, $zone)` for this.** The
    first version did, believing PHP resolves an ambiguous reading to the later
    of the two. It does not: it takes the first matching offset in the zone's
    own table — standard time in Europe, Australia and New Zealand, but
    DAYLIGHT time across the Americas. So that version was a **no-op in
    New York, Chicago, Los Angeles, Havana, Santiago and the Azores**, and #653
    stayed live there, including in America/Santiago, the zone this repository
    already uses as its non-European test zone. All 357 checks passed, because
    `ics-reader-selftest.php` hard-codes `$orgZone` as Europe/London and every
    I34 fixture is written in it. An independent check caught it.

    The lesson is the project's own rule read one clause further on: it is not
    enough to test in a zone that really changes — it must be a zone that
    resolves THE OTHER WAY. I34i reads a New York series directly, and
    Australia/Lord_Howe for a THIRTY-minute change so nothing assumes an hour.

    **AND `setTimestamp()` IS NOT SAFE FOR BUILDING A CANDIDATE EITHER.** The
    rewrite scanned the zone's transitions but built each candidate with
    `$local->setTimestamp(...)`. On an object carrying a named zone PHP converts
    to the wall clock and resolves AGAIN, so when the instant asked for is
    itself inside a repeated hour it snaps back to PHP's first-matching offset.
    Measured in America/Metlakatla: `setTimestamp(1446373800)` returns
    1446370200. The candidate for the later offset came back as the INPUT
    instant, never newer, never chosen — so the function collapsed to PHP's pick
    a THIRD time. Build it as `new DateTimeImmutable('@' . $ts)` then
    `setTimezone()`.

    A sweep of every zone PHP knows, 1900–2050, against an exhaustive reference
    found it wrong in **81 zones** of 14,454 ambiguous readings; afterwards, 0.
    **Every one was a PERMANENT base-offset move** — standard time on both
    sides, a country changing its clocks for good — and it is unpredictable from
    the zone (Asia/Almaty 2024 works, Metlakatla 2015 does not). Today's data
    has no such move after 2025, so nobody is wrong now: luck, not design.
    Check I34j pins Metlakatla; I34i could not see it because every zone in it
    changes for summer time.

    **This one fix shipped as a no-op THREE times, each time with every check
    green.** The committed checks pin four shapes — an ordinary change, one that
    resolves the other way, a thirty-minute change, a permanent move. The
    all-zone sweep that found the third is NOT committed (reflection, about a
    minute); write it out again if this code is changed.

    It does NOT make the two paths produce the same INSTANT: `makeDate()` still
    takes PHP's pick, so in the Americas a one-off's instant is the earlier and
    a series date's the later. They agree on the stored READINGS, which is what
    is kept and shown.

    **It is called from `buildOccurrence()` and must stay there.** The identity
    of a date within its series, `recurrenceKeyFor()`, is the start turned into
    UTC, and it is STORED on the row as `externalRecurrenceKey`. The two moments
    sharing "01:30" that night have different keys, so resolving the reading any
    earlier renames every such date, and the next refresh sees the renamed date
    as new, adds it and removes the one already there. (An earlier version of
    this also said "a skipped date stops being recognised". That is BACKWARDS
    and was measured: an `EXDATE` written with the event's own `TZID` keys
    through `readDateTime()` and so ALREADY fails to match such a date — moving
    the resolution earlier would make that form START matching. The stored-key
    reason is correct and is enough on its own.) Check I34b pins that the key is unchanged.

    **It takes the zone the reading was WRITTEN in, never the organisation's,
    and that is the whole safety of it.** A `DTSTART` ending in `Z` states an
    INSTANT, not a reading; there is nothing to resolve. The first version
    judged every reading in the organisation's zone and so moved an event stated
    as `003000Z` an hour LATE. Every check in the reader's own test passed; only
    part G of `tools/feed-importer-selftest.php` — which needs a database and a
    web server — caught it. Check I34h now guards it in the test that needs
    neither, and it is the SOLE catcher of that mistake.

    The symptom was wider than the issue reported: a daily FOUR-hour series from
    inside the repeated hour read as THREE hours. Zero length is simply what
    that looks like when the date was an hour long to begin with. The instants
    were right throughout; it was the readings that were unreadable.

    **Two leftovers that look alike and are NOT the same thing.** A date whose
    END falls in the repeated hour LOOKS an hour short to a reader, but the
    stored data is right and reads back as the full length (I34f — appearance
    only; the fix for it is to show the offset, not to change what is stored).
    A date whose FILE stated instants in `Z` can read end-before-start, and its
    true length is NOT recoverable from the stored pair at all (I34h — a real
    limit, needing an offset stored beside the reading). Calling both "the known
    limit", as the first draft of these comments did, sends the next reader to
    fix the wrong one.

    TWO COMMENTS GOT THIS WRONG IN A ROW, and the shape is worth remembering.
    The first said the backward direction "needs nothing special here", which
    stopped anybody looking. Its replacement said the fault happened to an event
    "starting in" the repeated hour — the one reproduction that gives the RIGHT
    answer, so it would have sent the next reader away satisfied. **When a
    comment describes a fault, check that the reproduction it implies actually
    reproduces it.**

    TWO LESSONS ABOUT CHECKS from this package, both found by planting faults:
    - **Check that a fixture is actually ORDINARY.** I7d exists to prove the
      rule does not over-reach, and it guarded nothing: not one of the
      fifty-six fixtures held a correctly closed nested block (no `END:VALARM`,
      no `BEGIN:VTIMEZONE` anywhere). Narrowing the stack pop to
      `VEVENT`/`VCALENDAR` passed all 345 checks while ten of thirty-four
      well-formed shapes — a realistic Google export, any event with a
      reminder, VTODO, VJOURNAL, VFREEBUSY — became "incomplete", which would
      have stopped the portal tidying any calendar for ever. A fixture set
      grows to pin faults, and every fault is unusual, so the normal case is
      exactly the hole that forms.
    - **An overstated guarantee is worse than none.** C13's comment claimed it
      pinned the exact-versus-nominal duration rule (RFC 5545 §3.3.6) and would
      catch an interval from `diff()`. Restoring that `diff()` route passed all
      four C13 checks: none of its occurrences spans a transition. I26 and I29
      pin that rule; C13 pins something narrower, and now says so.

    A THIRD LESSON ABOUT CHECKS, from the #653 work: **plant with the operation
    you mean.** A plant meant to resolve the reading the EARLIER way used
    `modify('-1 hour')`, which PHP applies to the WALL CLOCK — on a moment
    reading 01:30 GMT that night it gives 00:30 BST, a different reading — so
    the plant fell through its own condition, changed nothing, and "passed" all
    357 checks. That looked exactly like a gap in the checks and was not. The
    genuine earlier instant with the same reading is
    `setTimestamp(getTimestamp() - 3600)`. Same family as the `diff()` trap:
    hours are exact, clock arithmetic is nominal, and the two part company twice
    a year.

    Tests: I7b, I7c, I7d, C13 and I34 to I34h of
    `tools/ics-reader-selftest.php`.

26. **One install, many organisations, and DIFFERENT PEOPLE at each level
    (owner, 29 October 2026). Applies throughout the application, not only to
    Calendar and Events — the owner named the noticeboard specifically.** One
    installation may serve many organisations, and whoever administers the
    INSTALLATION may be a different person from the administrators of the
    organisations on it (a hosting provider and its customers). The boundary
    between the two levels is therefore a boundary between PARTIES.

    For a setting that is a **limit**, the installation's value takes
    precedence: an organisation may hold itself to a LOWER limit and may never
    raise its own above what the installation allows. If the installation
    allows one year, an organisation may choose six months and may not choose
    three years. This is the OPPOSITE of the ordinary settings rule, where an
    organisation's row simply overrides the installation-wide one — and
    `$SETTINGS` has already done that merge by the time any page runs, so the
    merged array cannot express the rule on its own.

    `$SETTINGS_INSTALLATION` is kept beside `$SETTINGS` by the settings loader
    in `bootstrap.php` for exactly this, holding the installation-wide rows
    alone. It is a layer, not a list of limits. The effective value is the
    SMALLER of the two, then capped by a hard maximum in code: three tiers,
    each unable to loosen the one above it.

    Four things that are easy to get wrong, each found by a check rather than
    by reading: "not chosen" must follow the INSTALLATION, not the built-in
    default, or the default caps the installation and an installation can lower
    a limit but never raise one; the rule is enforced in the HANDLER, never
    only by a `max` on an input, because anything can be posted; the number is
    REFUSED with the reason rather than quietly clamped, because silently
    reducing somebody else's number is how a person comes to believe a limit is
    in force when it is not; and the refusal is recorded, because it crosses a
    line between two parties.

    `Portal\Core\EventRecurrence::limitsFrom()` is the first reader built this
    way and `installationAllows()` is how a page says what the installation
    permits. Tests: section H of `tools/event-recurrence-selftest.php`, section
    K of `tools/event-repeat-pages-selftest.php`.

    **NOT yet swept across the application.** Over thirty limit-shaped settings
    exist (`*max*`, `*limit*`, `*Days*`, `*quota*`, `*Retention*`), including
    `noticeboard.upload.maxBytes`, and one live instance is known:
    `/admin/settings/attendance` writes `attend.detailRetentionDays` per
    organisation, 0–3650 days, with no installation cap. The sweep is its own
    package.

27. **Transcription, and the reserved word that could not save a transcript (#276, migration 229).**
    `Portal\Core\Transcription` is the ONE place anything about transcription is decided:
    `whyNotQueueable()` answers whether a recording may be queued (the page draws the button from it,
    the handler accepts from it — never a second test in a page), `refusalWords()` and
    `statusWords()` are the only words, `capFrom()` is the monthly ceiling, and `queue()` /
    `processQueue()` are the only writers of the queue state.

    **`fullText` IS THE RESERVED WORD `FULLTEXT`.** MySQL ignores case in keywords, so a bare
    `fullText = ?` does not parse; it must be qualified (`t.fullText`) or quoted (`` `fullText` ``).
    The statement that SAVES a finished transcript was written bare and therefore could NEVER RUN,
    throwing after the outside company had been called and charged — and nothing noticed, because no
    page could queue a recording in the first place. **Ask of any camel-case column whether its name
    is a keyword: `php -l` cannot see it and no audit script here can either; only running the
    statement finds it.**

    **Both writers of the queue state take the RECORDING's row lock** (`SELECT … FOR UPDATE`) — the
    transcript row may not exist yet, and there is nothing to lock in a row that is not there — and
    the provider call is made only AFTER the transaction is committed, never inside it. Refusing a
    `processing` row outright was the WRONG fix: shared hosting kills long requests, so that wedged
    the recording for ever. The refusal is "processing AND started recently" (`startedAt`,
    `STUCK_AFTER_MINUTES` = 120, on the server's own clock to match `queuedAt` and `generatedAt`).

    **One API key pays for every organisation**, so `processQueue()`, `monthSpendPence()`,
    `queueStatsFor()` and `recentFor()` are all scoped to one organisation, and the budget follows
    rule 26: the installation's figure caps the organisation's, read from
    `InstallationSettings::layer()`. **Nothing drains the queue on its own (#672)** — never describe
    transcription as automatic.

    Three traps worth keeping. A `<form>` inside a `<form>` is DROPPED by the parser and its button
    is owned by the OUTER form — that is how "Process next" saved the settings and transcribed
    nothing, and the markup looks perfectly correct, so ask the PARSED document
    (`Dom\HTMLDocument`, PHP 8.4+) and never the text. `recordings/view.php` had NO flash block and
    nothing in this portal prints one centrally (141 pages print their own), so
    a handler's message is swallowed unless the page prints it. And a check searching for the TEXT of
    a guard stays green when `&& false` is added to its condition, so pin the WHOLE condition — the
    same weakness the QR-service guard had twice.

    Test: `tools/transcription-pages-selftest.php` (153 checks; section Q states
    plainly that a single-worker harness cannot create the race the lock exists for). **Two gates, not one:** `recordings/transcribe` AND `admin/transcription/run` must EACH ask `providerIsReady()` — the run page had only the button's `disabled`, and a posted request claimed every waiting row and marked the lot FAILED. **`processQueue()` must never re-throw** (one unwritable row gave a bare 500 and abandoned the rest). And a check that looks for the TEXT of a guard stays green when `&& false` is added to its condition — pin the WHOLE condition; two checks here failed that way and planting caught both.

- **What a shared link looks like, and the pre-push gate (#569).** `web/_core/templates/head-meta.php` is the ONE place the meta description, canonical address and Open Graph / Twitter tags are written. `header.php` requires it, and so does every page that draws its own `<head>`; set `$pageTitle` and, where the page can do better than the fallback, `$pageDescription`, before requiring it. **The canonical address and `og:url` are left out entirely while `PortalAddress::visitorCanChooseHost()` is true** — the fallback is a host name the requester chose, and a canonical built from it points search engines and preview services at a name an outsider picked. `og:image` only where the brand in use has the square raster on disk (two of seven; #656), and `twitter:card` is `summary`, not `summary_large_image`, because the picture is square. **Do not count the own-`<head>` pages by searching:** three reasonable searches gave 27, 15 and 12, because a doctype may sit inside an e-mail body built as a PHP string and because a page can draw its own head for the main view AND include `header.php` for an error branch. The list that matters is the exemption list in `tools/audit-checks/check_page_head_tags.py`, which names each exempt file and why. That check was WRONG THREE TIMES and planting caught each: string literals stripped across the whole file (an HTML attribute's quote paired with one inside PHP and swallowed the `<title>`); comments not stripped before pairing quotes (an apostrophe in `// don't` put every later quote out of step); and asking only whether the text `head-meta.php` appeared anywhere, which a commented-out require satisfied. **Its most valuable guard is on `header.php`'s own require**, because ordinary pages write no `<title>` of their own and a page-by-page check would never notice its deletion. Behaviour is proved by `tools/page-head-selftest.php` over real HTTP, whose part E plants the fault. **`.githooks/pre-push`** (turned on per clone by `tools/install-git-hooks.sh`, setting `core.hooksPath`; per repository on purpose — a global hooks folder overrides every other project's) runs `php -l` on the PHP the push range touches **as it stands on disk, not as committed** plus **every script in `tools/audit-checks/`** (the hook globs the folder, so a new check is picked up with no change to the hook), about nine seconds. **The count is deliberately not written down here** — it said 38 while there were 40, because two packages added checks without updating this line; count them with `ls tools/audit-checks/check_*.py | wc -l`. It does NOT run the self-tests or the migration harness, and note `tools/api-spec-check-selftest.py` lives in `tools/` and so is NOT globbed by the hook, and `git push --no-verify` skips it.
- **A check can name the right file and still guard nothing (31 October 2026).**
    `tools/gdpr-coverage-selftest.php` pins which pages may file an erasure request as ALREADY
    CONFIRMED — `_apps/account/erasure-request.php` (not confirmed),
    `_apps/auth/account/delete-confirm.php` and `_apps/offboarding/do.php` (both confirmed).
    **Callers are named by their PATH under `web/`, never by `basename()`:** moving the call into a
    new, completely ungated `zzplant/do.php` was indistinguishable from the gated
    `offboarding/do.php`, and `do.php` is a generic name. **Every gate must REFUSE, not merely
    exist:** matching `if (App::isAdmin() === false) {` and nothing else passed with the `exit`
    inside the braces deleted — #668's section-D lesson again — so the walk requires an `exit`
    before the next `}`, and the three gates (administrator, form token, `AccountGuard`) must come
    IN ORDER, which a comment had claimed while nothing checked it. The source is MASKED, not just
    stripped of comments: every string literal becomes a run of `x` of the same length, because a
    `}` or the word `exit` inside a message would answer the walk falsely either way. Cut the
    relative path with `strlen($webBase)`, never `strlen(realpath($webBase))` — the iterator returns
    the path as given (`tools/../web/…`) and realpath is shorter. Seven plants, all caught.
- **The password-reset page is compared with itself, and the nonce is noise (31 October 2026).**
    `landing()` in `tools/mail-pages-selftest.php` and `tools/sign-in-limits-selftest.php` proves a
    stranger cannot tell which addresses have accounts. Both tests sat RED because #625's
    asset-fallback script put `nonce="…"` on the page and `App::cspNonce()` is
    `bin2hex(random_bytes(16))` — **32 hex characters, where the helper stripped 64.** The portal
    was right; the test was comparing noise. **Strip the nonce BY NAME; never widen the pattern to
    32 hex**, which would blind the comparison to any real difference of that length. And a clause
    reading "the link is not used up" could never fail: it looked the row up by `hash('sha256',
    $link)` while the portal stores `hash('sha256', 'reset-link-utc:' . $token)` via
    `Auth::resetLinkFingerprint()`, so `null['usedAt']` was null and it passed on a row that did not
    exist. Ask the portal's own function; never keep a second copy of the rule in a test.
- **Two self-tests ran in NO workflow until 31 October 2026** — `tools/setting-limit-selftest.php`
    (#668) and `tools/offsite-settings-selftest.php` (#671), the #646 shape again. Both are now in
    `portal-selftests.yml`, which SAYS it runs on every pull request with no path filter and has
    never run on GitHub at all (#754) — the wiring is right and is not yet a live gate;
    `backup-selftest.yml` is path-filtered and does not list the off-site files, so a change to them
    would have run nothing. The off-site test REFUSES without `bash`, `openssl`, `rclone` or `tar`,
    and GitHub's image has no `rclone`, so the job installs it. **Nothing runs the whole set locally
    — that is #695**, and running it by hand is how all three of 31 October's red tests were found.
- **Offboarding really erases now, and the sweep has three traps (#569, migration 226).** `_apps/offboarding/do.php` **files** an erasure request through `GdprEraser::fileRequest($siteId, $userId, true)` — a fourth way a request gets confirmed, now written into that method's docblock — and never erases inline: there is ONE audited erasure path and a second would have to agree about 35 tables for ever. `offboarding.erasureMode` is `on_request` (the default; it waits on Admin, Erasure requests) or `after_undo_window` (the daily sweep runs it once `offboarding.undo_window_days` has passed). **Neither erases at the moment of offboarding**, because `rehire.php` exists; immediate erasure is #663. **`rehire.php` cancels a request still waiting** — without that the default mode left a live request to erase somebody who had returned. In `run_offboarding_erasure_sweep()` (`_apps/cron/_retention-sweep.php`): **join to the NEWEST offboarding row per person, never to any row** (an old row made a request filed today due tonight, bypassing the window — measured); **claim the request** with the same conditional `UPDATE … WHERE status = 'pending_review'` the staff page uses, or the Execute button stays armed mid-run and GdprEraser's own put-back and still-going updates match nothing; and **never log the fault's own words**, which can quote the value being erased. It cannot honour a per-organisation mode: `tblOffboarding` has no organisation column. The form offers TWO options, not three, because the portal does one kind of erasure. **Nothing yet covers this end to end over HTTP** — the SQL was proved against a real database and the rest was read, so a page-level test is still owed.
- **Removing a preference switch is two changes (#569, #662, #667).** `notifications-save.php` **rebuilds** `tblUsers.notifyPrefs` from its allowed-key list and writes the result over the whole column. Hide a switch and leave its key listed, and the next save rewrites that preference to off for everybody one at a time; take the key off the list and the stored value is DROPPED at the next save — not "left alone". Acceptable only while nothing reads it. The same replacing behaviour is why that page and the smaller card on `/account` overwrite each other's keys (#667). **And removing a feature must not remove the test guarding a past fault:** deleting that card broke section F of `go-live-button-selftest.php`, the only guard for #612, so the form was kept with its still-wired switch and section F's `count($storedNow) >= 15` became a named list of keys — stricter than a count, which is exactly what a deliberate change to the number of preferences invalidates. `||` is **logical OR** in MySQL, not concatenation. A **`disabled` control is not submitted**, so disabling the GoCardless option would have made Save switch the provider to Stripe silently.

29. **A form that SAVES a setting installation-wide must not PREFILL it from the merged array (#674, #668).** `$SETTINGS` has already merged the installation-wide rows with the open organisation's by the time a page runs, so a box filled from `App::settings()` shows the ORGANISATION's value while a Save handler writing `settingKey = ? AND siteID IS NULL` writes the INSTALLATION's. A global administrator who opens the page while in an organisation holding its own row sees that row, and pressing Save without touching anything makes it the installation's for every organisation — the wrong direction across a boundary between two parties, which may be a hosting provider and its customers. Read such a field from `Portal\Core\InstallationSettings::layer()`. Several admin forms had this shape — take the number from the check's own output, never from this file, which said fourteen were outstanding while twelve were (#637). `tools/audit-checks/check_install_wide_prefill.py` is a hard gate, with the outstanding pages on a pending list so no new page can join them. The check requires a `<form>` (a runner or webhook in the same folder correctly reads the merged value) and skips a page that reads the layer at all, because it reported the #674 fix itself as a fault before that term existed; the cost is that a half-fixed page passes. Behaviour is proved by section S of `tools/transcription-pages-selftest.php`, which puts the old read back and records the installation's provider changing on a Save that touched nothing. Claude reads the same rule in `.claude/CLAUDE.md`; change both together.

30. **One place hashes and stores a password (#728).** `Portal\Core\Auth::setPassword()` and `setUnusablePassword()` are the one place a sign-in password is hashed and written. Eight places used to do it with their own SQL, each correct about hashing; what could not be done was enforce a rule anywhere, and #586 — a password cannot be stored for an account with no sign-in row — was such a rule. They own the storing and deliberately not who may set a password: each caller keeps its own gate. The hard gate is `tools/audit-checks/check_one_password_writer.py`, failing both ways (an unlisted writer, and a named exception that has stopped being one); behaviour is proved by `tools/password-writer-selftest.php` against a real MySQL. The empty string is the unusable marker and it is enforced: `Auth::loginLocal()` refuses an empty hash before `password_verify()` is reached, `_apps/offboarding/do.php` writes it, the disaster-recovery runbook reads `passwordHash <> ''` as "has a password", and `admin/users/save.php`'s #586 read-back treats a zero-length hash as nothing stored — so a random hash is unmatchable but unrecognisable, and `users/api/create.php` left every account it created reading as though it had a working password. Refusing beats choosing: `tblLocalAccounts.userID` carries no unique key, so an account can hold two rows, and setting the password on one leaves the other's old password working. `_apps/offboarding/do.php` is a stated exception because taking a password away must reach every row. The installer has no autoloader, so the call needs a `require_once` that `php -l` cannot tell you about. And sections A-G of the self-test passed 49 of 49 with the read-back weakened to "does a row exist", because they prove the write landed while the read-back only matters where the write lands as something else; section H makes that state with a database trigger. **FOUR places READ the empty marker and ONE WRITES it**, and a first version of this rule said "three places read" while citing `admin/users/save.php`'s #586 read-back — which #728 DELETED, so it cited as evidence the thing it removed, missed two real readers and called the writer a reader. READ: `Auth::loginLocal()` (before `password_verify()` is reached), `auth/account/change-password.php`, `auth/2fa/disable.php`, and the disaster-recovery runbook's `passwordHash <> ''`. WRITES: `offboarding/do.php`. **AND I CLAIMED A RACE WAS IMPOSSIBLE WHEN IT WAS NOT** — "nothing in this portal creates a second one" stood in five places and is FALSE, measured: two connections, each in its own transaction, both setting a password for the SAME account with DIFFERENT sign-in names both reported success and left TWO rows, because under REPEATABLE READ neither `COUNT(*)` sees the other's insert. The harm is what follows — that account can never have a password set again while BOTH old passwords keep working, so the member who most needs to change theirs cannot. The unique key is on `username`, so it catches only the case where the names MATCH. It locks the account's own `tblUsers` row before counting (the PARENT, because the row being guarded against does not exist yet), and that lock is real ONLY inside a caller's transaction (#697) — every caller that can create a row is inside one, and the one that is not passes no sign-in name so can only update. Making the lock real made a lock-wait timeout reachable, which throws and would escape with the transaction open (#694's round-3 shape): checked, every creating caller catches and rolls back. Section I needs TWO REAL PROCESSES — two connections driven one after the other from one process just deadlock. Claude reads the same rule in `.claude/CLAUDE.md`; change both together.

31. **A member can add a password to their own account, and nobody else's (#731, migration 247).** `Portal\Core\SelfServicePassword` is the one place that decides whether somebody may, and the one place the proof that they have just signed in again is recorded, read and spent. It takes facts rather than a connection, so the page and the handler cannot disagree, and the handler asks it twice — once cheaply, once on facts read inside the transaction that writes. The proof may be recorded only by a path that really re-authenticated somebody; never put a privilege grant behind nothing but an existing session, because a session can be borrowed. It lasts five minutes, is single-use, is spent when the password is stored, and is refused when it names somebody else or comes from the future. `auth.password.selfAdd` is the setting and the stricter level wins (D-026): allowed only where the installation and the organisation both say yes, read through `InstallationSettings::layer()` because `$SETTINGS` has already let the organisation's row win. It ships allowed, because this is a recovery route and #499's "fail closed" locked members out. **But an organisation cannot express its own "no" through any page** — nothing writes the key outside the class and `_apps/settings/save.php` refuses a per-organisation row for a key the installation holds; that is #668's shape re-created, the rule is right and the control is #755. The password is stored only through `Auth::setPassword()` (#728); an administrator still cannot do it for somebody else (#586); the identity-provider link and every passkey survive; it is recorded as `PasswordAdded` and the account is e-mailed. Three things were pinned by nothing and an independent check found all three: deleting the handler's form-token gate, making the page renew the proof's moment, and deleting the second rule check inside the transaction all left the suite green. Never say "the transaction and the lock protect" two simultaneous adds — the lock makes them sequential, it does not refuse the second; the second rule check is what refuses it. Claude reads the same rule in `.claude/CLAUDE.md`; change both together.

32. **Gating a feature is a capability, and phase two converted nearly all of them (#739; D-017).** Hard-coded role gates went from 16 call sites to 3, and to 2 with #756. Count them with `php tools/role-gate-count.php`, which uses PHP's tokeniser — never `grep`, which counts comments, and converting a gate leaves a comment naming the gate it replaced, so the grep figure rose to 73. The three remaining: two take their key from a row a customer filled in and so are not page gates, and `Photos.php`'s `volunteer` rung is a no-op (#756). Six new capabilities cover thirteen gates, grouped by the door rather than the role. `giving.records` is deliberately not folded into `expenses.treasury` although both ship with `treasurer`, because folding them would hand the donor list to whoever a customer later made responsible for paying claims. Access changed for nobody: with no stored choice `App::can()` reduces to exactly what `App::hasRole()` answered, and every call site kept its own administrator ground, so no capability can be emptied unrecoverably. **The worst finding: the shipped roles were pinned by nothing, so "nobody's access changed" passed on broken code** — changing one word in the registry left all 60 audit scripts green, and on upgrade would have handed donors' names and addresses to expense approvers. The cause was a list named after a number (`$fiveShouldShipWith`) while eleven capabilities existed; add a capability to that list and to the holders list in the same change that adds it to the registry. The guard against a new hard-coded gate is `check_no_new_role_gates.py`, which had two holes: a heredoc containing an apostrophe made it report "nothing wrong" over a real gate, and it matched only two of the four `Roles` methods that answer a role question, so `hasAnywhere()` and `keysHeldBy()` — both live here — walked past. **#756 took it to two, and that one was a behaviour change (the owner, 4 November 2026 — D-047).** `Photos::userTierRank()`'s volunteer rung checked the role and returned 1 while the line below it returned 1 for every signed-in member — so the gate decided nothing, since `8625b9e`, under a comment claiming access was granted "ONLY if you've explicitly granted them": untrue in the REASSURING direction. The owner chose the comment, so the fall-through returns 0, the rung is real, and a real rung is a capability — `Capabilities::PHOTOS_VOLUNTEER`, shipping `['volunteer']`. **It takes photos away from people who can see them today:** a member holding neither tier now sees what a visitor sees, though everything shared with everybody is unaffected because `canView()` lets a `public` photo through before it asks about rank. #739 was right to leave it — converting it while both branches returned 1 would have put a switch on the Roles page that cannot change what anybody sees (#671) and hidden the product question; settle what a gate should DO, then convert it. Nothing drove the ladder, which is how the no-op survived (`grep -rln userTierRank tools/` answered only the audit script, which reads it as text), and it cannot be driven from the capability test because the method reads the session — so the fall-through's own value is pinned with comments stripped, both plants measured. And section O must not be read as proof this changed nothing: it proves the capability answers what `App::hasRole()` answered, which is true of the gate and silent about the ladder. Two wording faults found doing it: `PHOTOS_STAFF`'s `emptyMeans` said albums shared with volunteers were unaffected "because every signed-in member already reaches that tier", now false; and a check labelled "twelve capabilities are offered" went red on the thirteenth — the check working, but a label named after a number, the shape #739 itself recorded. Claude reads the same rule in `.claude/CLAUDE.md`; change both together.

28. **The API description is checked against the code (#641).** `web/_core/api-spec.json` is served to customers' developers by `/api-docs/`, and until 30 October 2026 nothing compared it with the handlers: 17 reachable endpoints were missing from it, two more were described only under their `api/v1/` address, and three operations said a legacy address did not exist when it does (19 addresses documented as a result). `tools/audit-checks/check_api_spec_matches_code.py` is a hard gate in `pr-security.yml`. It derives reachability from `ApiRouter`'s own `^[a-z0-9\-]+$` pattern, so helper files such as `_apps/assets/api/_coerce.php` need no exemption list; it resolves the `api/v1/` facade through the four tables in `ApiRouter` with comments stripped first; and it treats a `**Legacy alias:**` line as documentation for the 40 legacy addresses described that way — though that part is NOT load-bearing: measured, `aliases - targets` is empty, so removing it leaves the check green, and the figure originally used to justify it was false. Its named lists fail both ways — unlisted faults AND listed entries that have stopped being true — and two of them are now EMPTY for a reason worth carrying (#675, 30 October 2026): `seeded_switches()` was one regex over `full_schema.sql`, which both seeds AND deletes settings because it folds old migrations in, so a key inside a `DELETE` counted as a seed. Four `api.expenses.*` rows were reported as orphan seeds that no installation has held since migration 186 — loading the install script into MySQL 8.0.36 gives exactly three. The dangerous direction was check G: a handler whose switch is seeded and then deleted answers 403 to everybody, and the old reading called it seeded and passed (measured by appending a `DELETE` of `api.events.create.enabled`). The two statement kinds are now applied in file order — and the first version of that fix was ALSO wrong, caught by the independent check: it ended a DELETE at the next `;` character, so a semicolon inside the statement's own `--` comment ended the span early and the key was read as a seed. That is the silent direction, on the very fault the rewrite existed to catch. `read_install_script()` now scans the file once, blanking comments and tracking quoted literals, which also stopped a route target quoted inside a comment being read as a live route. A file in an `api` folder is also NOT necessarily an API endpoint: `tblRoutes` reaches `expenses/api/export.php` at the ordinary address `expenses/api/export`, where neither the `api.{app}.{action}.enabled` row nor the app's API-folder switch applies — two fewer gates than the folder implies. Check I names every such file; there is one. It does NOT judge whether a description is true, nor which HTTP verbs a handler accepts (`PORTAL_API_V1` relaxes `ApiAuth::requireMethod()` at run time). Note that five endpoints answer with their own flat JSON rather than the `{status,data,meta}` envelope, and `worship/advance`, `push/subscribe` and `push/unsubscribe` return the replacement anti-forgery token as a top-level field rather than in `meta`. `web/_core/api-spec.schema.json` is now the shape the description must have, executed by `tools/audit-checks/check_api_spec_schema.py` as a hard gate with a hand-written, dependency-free validator (no JSON Schema library exists on the development machine; the check fails when the schema uses a keyword the validator cannot see, so no part of the schema can become decorative). Every property and parameter carries a description. Facts worth carrying: asset money is in PENCE, expense money is in POUNDS; many limits quietly shorten or clamp rather than refuse; `licenseKey` is write-only and cannot be cleared; the attendance breakdown and the noticeboard poster list are whole-state, so an empty list wipes them; leadership's `assignmentID` is the assignment, not the role; and `PaginatedListResponse` describes a shape no handler returns. #676 (the events `status` allow-list not matching the column) is fixed in `28f56cb` with migration 230, and it was five writers not three. #675 is done and all three of its headline claims were wrong — with one qualifier: its narrower wording, that the `api/expenses/export` ADDRESS has never worked, is true and deliberately still is. What was wrong is the conclusion: `expenses/export` answers a real CSV, a live button links to it, and the four "orphan switches" do not exist. The real fault was that the Export button was drawn for everybody who could open `/expenses/approve` (a page with no gate beyond sign-in) and gave four of six account kinds a bare `Forbidden`. `ExpenseRules::mayExportClaims()` is the ONE place that decides, asked by the button and the handler; the department `isApprover` flag is not the `approver` role and is deliberately not enough (widening it scoped is #681). `api.expenses.export.enabled` stays unseeded on purpose. Section L2 of `tools/access-pages-selftest.php` pins the behaviour. Claude reads the same rule in `.claude/CLAUDE.md`; change both together.
29. **One organisation's address is not every organisation's (#677, migration 231).** `admin/settings/organisation/save.php` writes at two levels and its `$upsert()` takes the level as an argument with no default: the nine `org.*` keys and `portal.support.email` belong to the organisation, `site.url` alone is installation-wide and a global administrator's (#500). The support e-mail falls back to the installation's value, so migration 231 deliberately does not copy that row down, and an empty box DELETES the organisation's row because the merge falls back only where there is no row — a stored empty string wins and would mask the installation's address. `Mailer` needed no change for a web request, because `renderBase()` runs before `MailQueue::add()`; a scheduled job is the exception and sends one organisation's support address to another's members (#683), and no page now sets the installation's own value (#684). Three faults found by tests rather than review: the box prefilled from the merged array while Save wrote the organisation's row (#674 mirrored, and the prefill check cannot see that direction); `$SETTINGS_INSTALLATION` is not inherited by a page, only `$mysqli` and `$SETTINGS`, so the help text said the opposite of the truth; and `portal-address-selftest.php`, which covers this card and is wired into `portal-selftests.yml` (a file that has never run on GitHub — #754), was left failing 5 of 93. Two traps: ask whether a form field ARRIVED, never what it contains — the page disables the portal-address box for an ordinary administrator, a disabled control is not submitted, and writing `?? ''` regardless cleared `site.url` for every organisation; and `check_install_wide_prefill.py` went blind to the fix itself, because binding the level as a parameter stopped it matching the literal NULL it looks for, so such savers are now named in `LEVEL_CHOSEN_AT_RUN_TIME` and fail both ways. Nothing outside that page reads the nine `org.*` keys, so no address was shown to the wrong people; the support e-mail was the one genuinely shared. Test: section O of `tools/access-pages-selftest.php`. Claude reads the same rule in `.claude/CLAUDE.md`; change both together.
30. **A limit is capped by the installation, and `Portal\Core\SettingLimit` is the one general place that decides it (#668 part 1).** Three tiers — a hard maximum in code, then the installation, then the organisation — each able to tighten the one above and none to loosen it. The rule is `min(merged, installation)` and needs no query to find out whether an organisation has its own row, because the merged value already is the organisation's own where it has one and the installation's where it has not. Zero is the trap: where 0 means "for ever" or "no limit" it is the largest value, not the smallest, so as a number it passes every limit. Converting it in the handler was worse than not converting — an installation that chose "for ever" refused every organisation save including tighter ones, and one that chose 3650 let an organisation keep data for ever. Pass `$zeroIsLargest` so the class reads it at both levels; "for ever" is allowed only when the installation itself allows for ever. A page asks two questions (the ceiling, and whether for-ever is allowed) and must never prefill a value its own `max` forbids, or the browser refuses to submit the form at all. The live breach fixed: `/admin/settings/attendance` accepted 0–3650 days per organisation with no cap. #685 carries the organisations that already stored more. The issue's 31-key list is a starting point only: keys hide behind nested-array reads, `LIKE` queries and constants, so part 2's tripwire must resolve constants. Test: `tools/setting-limit-selftest.php`, whose section D passed on four different disabled gates before it pinned the whole condition. Claude reads the same rule in `.claude/CLAUDE.md`; change both together.
    **The giving-records ceiling is OPT-IN, and it is the one limit
    where capping is the DANGEROUS direction (#687, migration 234).**
    `giving.retention.years` is how long a donor's name and home
    address are kept, 1 to 50, per organisation.
    `GivingRetention::capInForce()` is the ONE place that answers the
    ceiling and it asks `Portal\Core\SettingLimit` — **0 means NO
    ceiling, so 0 is the LARGEST value**, #668's zero trap, passed as
    `$zeroIsLargest` rather than converted by hand. **It ships with no
    ceiling and nothing changes until somebody sets one** (the owner,
    31 October 2026: "can have the option to enforce such a limit -
    but can also choose not to. so customisable"), because the figure
    is a STATUTORY retention period and forcing it down could make a
    customer destroy records their own law requires. **TWO save-time
    gates are what make it a real cap and not #668's "a save-time cap
    is not a cap":** a figure above the ceiling is REFUSED with the
    reason, and a ceiling BELOW a figure an organisation has already
    chosen is also refused, NAMING the organisations
    (`organisationsAboveCap()`, which THROWS rather than answering an
    empty list it cannot stand behind). **BOTH GATES RE-READ THE
    CEILING LIVE AND LOCK THE INSTALLATION'S OWN ROW
    (`capFromDatabase($db, $siteId, true)`), inside the transaction
    that writes — #647's rule, and the first version did NOT.** It
    compared against the request's settings SNAPSHOT, and an
    independent check measured two ordinary web requests storing 50
    YEARS UNDER A CEILING OF 7 with both saves successful, in 60 of 60
    simultaneous pairs — while FIVE places claimed the only way in was
    hand-written SQL, which was the stated reason the job need not
    cap. **A claim that the portal "cannot" reach a state is the
    sentence that stops the next reader looking: measure it.**
    `whyYearsNotAllowed($years, $cap)` TAKES the ceiling rather than
    fetching one, so both gates ask the one rule (and an earlier
    version that fetched its own was left DEAD when the gate was
    inlined — a public method nothing calls, with an authoritative
    docblock, is worse than none). **The gate is on the figure the
    save really STORES, never on `$years`:** `$years` is the typed
    number OR the country suggestion, and gating it refused saves that
    store nothing — on a fresh installation with a ceiling, SWITCHING
    THE HOLD ON WAS REFUSED, the one control that STOPS records being
    cleared, with a message about "that number" when the box was
    empty. An organisation stores a figure only by typing one or by
    ticking "confirmed"; both are gated. **The one hole left is a
    figure written in SQL or restored from a backup, where the job
    keeps the records LONGER and the page says so**, because
    destroying a statutory record early cannot be undone. **The lock
    was then PROVED, which the first version never was:** 116
    simultaneous pairs ended 0 of 116 above the ceiling, both orders
    were forced with an external session holding the lock (each path
    blocked ~5s, woke, saw the committed value, refused), and removing
    just ` FOR UPDATE` brought the fault straight back — so the LOCK
    closes it, not the live read. **THREE MORE FALSE REFUSALS
    followed, and the gate was never too WEAK, only too EAGER** —
    worth knowing because each looked like safety: gating before the
    UN-TICK branch was decided refused an administrator who cleared
    the years box with the tick left on (how the page DRAWS it), so
    the gate now runs AFTER rules 1 and 2 on `$yearsOwn`, which is by
    then exactly what will be stored; judging the organisation's own
    ceiling when the save did not CHANGE it blocked the hold, the year
    end and a plain Save over a number the page itself had prefilled
    (#667/#677: ask whether the field CHANGED); and **the YEARS box
    was prefilled above its own `max`** when a stored figure exceeded
    the ceiling — #668's named fault, in the same edit that fixed it
    for the ceiling box five lines below, under the comment citing the
    rule, and in headless Edge the form would not submit at all, so
    the only way to use the page was to SHORTEN a statutory period.
    **`max` is the hard maximum on both boxes; the handler is the
    boundary.** A pre-existing deadlock on two organisations' first
    simultaneous save is #700 (proved pre-existing against `git show
    HEAD:`). Three more from the earlier check: the activity log said
    "Nothing was different" because the old ceiling was read AFTER the
    commit (`settingsAt()` has no cache); the own-ceiling box was
    PREFILLED ABOVE ITS OWN `max`, #668's named fault reproduced five
    lines under the comment citing it; and a comment justified the
    "did the field ARRIVE" design with a protection that does not
    exist. An organisation that has chosen NOTHING follows the country
    suggestion, which may exceed the ceiling harmlessly: nothing is
    cleared until "confirmed" is ticked, and ticking it WRITES the
    figure through the same refusal. **Set on the giving-records page,
    never the general settings editor** — the issue said
    `lockedInGeneralEditor()` "has to change" and it does NOT: that
    editor asks this class about the LOCK and nothing else (checked at
    `_apps/settings/save.php`), so a ceiling written there would skip
    the refusal. The refusal an organisation reads NEVER says who set
    the ceiling (the two levels may be different parties, as
    `SettingLimit::refusalWords()` already decided); naming
    organisations to the INSTALLATION's administrator is the other
    direction and is their own business, and the activity log carries
    only a count. The exemption in `check_limit_settings_capped.py` is
    DELETED, so that hard gate now requires the cap — it reported its
    own stale entry. Tests: section 6 of `tools/giving-retention-
    selftest.php` (9 of 63), the words only; **the two save paths are
    covered by NO automatic test and that is #699.**

31. **The limits sweep, part 2 (#668).** All 32 limit-shaped candidates are seeded installation-wide, and `_apps/settings/save.php` refuses to add a per-organisation row for a key the installation already has — so almost none is overridable. The reachable set was `attend.detailRetentionDays` (part 1), the four `mail.queue.*` numbers in `MailQueue::NUMBERS` (the one deliberate exception), and `giving.retention.years`. The mail-queue four are fixed in both places: the save check and `numbers()`, which the job uses — a save-time cap is not a cap, because a row that predates the check or came back from a whole-database restore was still obeyed. `giving.retention.years` is deliberately not capped (#687): it is 1 to 50 years of a donor's name and address, per organisation, but the figure is a statutory retention period and forcing it down could make a customer destroy records their law requires, so the owner decides. The noticeboard is written by no page. `check_limit_settings_capped.py` is a hard gate; it must read `web/_core` as well as `web/_apps` (two of seven per-organisation writers are there), must resolve constants, must join adjacent string literals, and judges a name by its LAST segment split into whole words — a narrower rule missed five keys, a wider one found "min" in reminder and "count" in countryCode. Claude reads the same rule in `.claude/CLAUDE.md`; change both together.

32. **The off-site backup's settings are real now, and a seeded setting nothing reads is a lie with a Save button (#671).** Until 30 October 2026 the six `backup.offsite.*` settings were read by nothing in the repository: the sync shell script used the variables written into its own copy on the server, and said so in its header and in the setup guide, so only the admin page was silent — and it said "Off-site backup settings saved." No automatic check here looks for a setting that is written and never read; finding it took grepping every reader of each key and counting the ones that were not the page itself. `tools/offsite-backup/read-offsite-settings.php` is now the one place a setting crosses from the database into the shell script, and it REFUSES rather than escapes: a value failing its pattern is not printed at all, the script keeps the value in its own file, and a WARNING names the setting and why — an escaped destination is still a destination nobody chose, and what is being sent is the whole database. A leading dash is refused separately, because rclone and the mail program each read one as an option. The script re-checks every value in bash as well, accepts only six keys, and never uses `source` or `eval`; bash does not re-read the content of a variable it has expanded, which was measured rather than assumed. `backup.offsite.enabled` is seeded '0', so copying the new script onto an installation already running it on a schedule makes it start SKIPPING — recorded as a skipped row, said plainly on the page and in the run log, but a change in behaviour to warn customers about. A second and separate fault: `log-offsite-result.php` required `'../../web/_core/bootstrap.php'`, which resolves only when the portal's folder is literally named `web` — true in a checkout of the repository, where it was tested on 12 October 2026, and false on a customer's server, where the deploy mirrors the contents of `web/` into a folder the customer names. So the recorder could not load the portal, `tblOffsiteSyncLog` stayed empty on every real installation, and the page showed "No runs logged yet" however many copies had really been sent, a FAILED run included. `docs/offsite-backup-setup.md` had written that down as a limitation and left it: writing a fault down accurately is not the same as deciding what to do about it. Both files now look for the portal rather than assuming its name. The page reads the script actually sitting on the server to say whether the settings are in force, ignored, or have no script to obey them, and strips comment lines starting with a hash BEFORE looking — because the script names the bridge several times in its own prose, so a plain search is satisfied by a script whose call has been commented out, which is the state the check exists to catch; measured, disabling the stripping fails exactly four checks in section O and no others. The S3 and SFTP addresses are still not on the page (#688). `Portal\Core\OffsiteSettings` is the ONE place that decides whether a stored off-site value can be used, asked by the bridge and the admin page so the two cannot drift: the rules lived only in the bridge at first, so the page accepted an rclone remote with a space or a capital letter, stored it, said "saved", showed it back and badged the settings in force, while every run used the remote written inside the script file — and where the customer had set that fallback to a real remote, as the guide tells them to, the copy went somewhere the administrator had not chosen. A refusal is only a safety feature if somebody is TOLD: the page now warns at the moment of saving and under the box itself, and lists each run's warnings. Three more faults from the independent review, each worth carrying. First, `log-offsite-result.php` threw away every run's log: its flag pattern `/^--([a-z\-]+)(=(.*))?$/` has no `s` modifier, so `.` cannot cross a newline and the WHOLE match fails rather than truncating at it — and a run's log is never one line, so `tblOffsiteSyncLog.output` was NULL for every run ever recorded, which was the only record that a setting had been refused. Split such an argument at the first `=` instead of matching it whole. Second, exit code 0 stopped meaning "a copy went" the moment the skip path was added, and the single reader of it was not changed, so on every fresh installation — the setting is seeded '0' — pressing "Run now" to check the setup answered "Off-site sync completed in 1s" in green for a run that sent nothing; it now reads the status of the row the run just wrote. Third, check every sentence a page states against the FALLBACK and not only the happy path: "Switched off, so nothing is being sent" was false in exactly the cases that matter, because the script's documented fallback when it cannot reach the database is to SEND, and this package's own setup guide said so. Also: never blank a value you cannot use — an empty alert address is a real choice meaning "do not e-mail", so blanking an over-long one would have silenced failure mail from a value nobody set; and a very large stored value broke the pipe the script read the bridge's first line through, aborting every backup under `set -o pipefail` while blaming a closing `fi`, which is where the ERR trap records LINENO. Two more faults from the second review round, both mine. "Run now" read `ORDER BY logID DESC LIMIT 1` with nothing tying the row to the run just made, so "this run recorded nothing" actually meant "the table has never held a row": measured with one older success row on file and a script that recorded nothing, the page answered "Off-site sync completed in 0s." in green, for a run that packed nothing and sent nothing — a fix that works only on a fresh installation is a fix for the one case where the fault is invisible. Read MAX(logID) before exec() and ask only about rows after it, defaulting to 0 so that every row counts as "after" when the table cannot be read, which is the safe direction. The same misreading had already disabled the fallback insert beside it, where `$mostRecent === null` meant "no row has ever existed", so on any installation with history a failed run that never reached its own logger recorded nothing at all. The testing lesson is the sharper half: a test that starts from a clean table cannot see a bug whose cause is state that was already there, so O11b of `tools/backup-page-selftest.php` is deliberately the only check in its section that an empty table cannot satisfy. Separately, one rule ended up in four copies — the save handler's literals, the display clamp, the input boxes' own min/max, and the bridge — and all four now read `Portal\Core\OffsiteSettings`; the class had been created to stop the page and the bridge drifting apart while two further copies were left in the same file, because they did not look like the thing being fixed. Final counts: `tools/offsite-settings-selftest.php` 195 checks, section O of `tools/backup-page-selftest.php` 77 of that file's 259, with 29 planted faults across the two, each proved to have applied, each caught, each reversed and verified byte-identical. Tests: `tools/offsite-settings-selftest.php`, 112 checks with a real MySQL database, real bash, real openssl and real rclone sending to a folder on the test machine, whose section D removes the override and proves the end-to-end check then fails; and section O of `tools/backup-page-selftest.php`, 25 checks over real HTTP. One more thing worth carrying: `tools/access-pages-selftest.php` MUST be run alone, because it counts the portal's account look-ups out of `mysql.general_log`, which is one table for the whole server — running it beside three other self-tests on one MySQL container gave two false failures that read exactly like a real regression (#689). Claude reads the same rule in `.claude/CLAUDE.md`; change both together.

33. **A claim that something "never worked" must carry the command that proved it (31 October
    2026).** Set after the owner asked why lessons already written down were not being applied: each
    had been stored as a story about one incident, so a new surface matched nothing. Any statement
    of the form "this has never worked", "it was always broken", "this has never been run", "nothing
    reads/prints/calls this" must be accompanied by the command that established it — normally `git
    show HEAD:<path>` or `git log -S'<text>' -- <path>` — and the commit it names. Same principle as
    citing issue numbers and commit hashes rather than line numbers (#678): a SHA does not move, and
    a claim about history needs a source in history. **A backup you made yourself is not evidence
    about the original:** a `.bak` taken AFTER a first edit was read as the original file, agreed
    with the hypothesis because the edit had caused it, and a WORKING page was then "fixed" into a
    broken one — `calendar/manage/series-edit.php`, which `git show HEAD` showed had always printed
    its flash message correctly. Only git, or an untouched checkout, answers "what did this do
    before I touched it". **And a rule written about pages applies to tools, tests and scripts:**
    #536's "never join two built addresses" was being quoted about portal code and broken inside a
    self-test harness in the same hour. Claude reads the same rule in `.claude/CLAUDE.md`; change
    both together.

34. **An erasure can match a row through its PARENT table, and the shortcut is the destructive one (#709).**
    `GdprEraser::whyParentMatchUnusable()` is the ONE test of whether an instruction may do this, and it is public so
    `tools/gdpr-coverage-selftest.php` asks it rather than keeping a copy; `GdprEraser::whereForEntry()` is the ONE
    place the "which rows are this person's" clause is written for BOTH shapes, asked by the probe, the statement and
    `inventory()`. Only ONE instruction uses it: `tblCareVisit.notes`, through `tblCareCase.personUserID`, because a
    visit's note is about the person being CARED FOR while the only account numbers on the row are the two WORKERS.
    **`personCol` must be in `LINK_COLUMNS` and NOT in `ACTOR_COLUMNS` — two of the eight refusals, and the pair that
    stops real harm:** pointing it at `tblCareCase.openedByID` empties the notes of every case the erased worker
    opened — a third party's safeguarding records — and reaches none of their own. `personUserID` was on NEITHER list
    until #709 although the database links it to `tblUsers`. **THE ORDER IS THE TRAP AND IT WAS LIVE:** the parent's
    own step empties the column the look-up uses, and in the list as it stood the parent came FIRST, so an instruction
    added in the obvious place would have found nothing, changed nothing, and written "nothing held for this person"
    into the sealed trail. Two guards, both needed — the coverage test pins the POSITION (it judges the list, before
    anybody runs an erasure) and `whyParentLookUpUnsafe()` judges the RUN. **THAT GUARD WAS WRONG TWICE, IN OPPOSITE
    DIRECTIONS, AND THE SECOND IS THE LESSON.** Version 1 read the whole request's trail, so it refused the step on
    every SECOND run — a run with a failed step is put back and run again, so the request could never be completed
    (section 6 of the eraser test found it). Version 2 therefore read only the CURRENT run's lines, **and that
    reopened the very fault the guard exists to stop, one step further on:** on a second run the parent's change is
    not "from this run", so the step was ALLOWED, the link was already gone, and it wrote "nothing held for this
    person" while the request closed as COMPLETED with the note still in it. **No mistake by anybody was needed** —
    one transient error on that one step in run 1 is enough, because the walk carries on and the parent's step still
    cuts the link. **The docblock is what let it stand:** it claimed a refusal "leaves every note in place for a
    second run, whereas a miss is silent and permanent", which was false in the reassuring direction — the #645 shape,
    a comment stating the guarantee the code had forgotten to implement — and four documents carried the same words.
    **Worse, the self-test ASSERTED the fault as correct** (`$saysClearAfterwards === false`), which is why nothing
    caught it. Version 3 asks all three questions: parent untouched (safe); changed EARLIER IN THIS RUN (an ordering
    mistake, recoverable); changed BY AN EARLIER RUN (the link is gone for good, and the answer turns on whether this
    step had already answered while it was intact — any line of its own with a LOWER `auditID` than the parent's
    first change, because **a `none` from before the change means the person held nothing, while a `none` from after
    it means only that the look-up could no longer see**). Where it never answered in time the step FAILS and says
    the rows must be checked by hand: **a request needing a human look beats one reporting success it has not
    earned**, and that is a product decision, not a tidy-up. `self::$runStartedAfterAuditId` (`MAX(auditID)` at the
    start) exists ONLY to tell those two "changed" cases apart.
    **And the child side was coarse too, found by reading:** the "had this step already answered" question was asked
    by TABLE NAME, and `tblCareVisit` carries THREE instructions, so another instruction's line counted as this one
    having run. The pinned order makes them agree TODAY, but nothing pins where the other two sit relative to the
    parent, so moving one above it — harmless in itself — would have made the guard allow a step it must refuse.
    Only a parent-matched step writes HOW it reached its rows, so that sentence is the discriminator (`details
    LIKE`), and it is deliberately a clause that CAN fail: case 3 reads a REAL via line back through it, so a
    pattern that stopped matching goes red rather than refusing everything. `childCol` is NEVER emptied (a visit detached from its case is
    rubble with the note still in it), and an instruction naming no `nullCols` is refused because without an account
    link to drop it would change nothing while reading as coverage. The audit line says HOW it matched, FIRST and
    untruncated — written last and cut to 250 characters it sliced `personUserID` in half, losing the one fact nothing
    else records. **THREE THINGS WERE PINNED BY NOTHING AND PLANTING FOUND ALL THREE:** replacing the run-time guard's
    whole CALL with `if (false)` left both self-tests and all 46 audit scripts green; reverting the `inventory()`
    half was equally invisible, although "what we hold about you counts these rows too" is listed as delivered; and
    swapping two of the four names in the clause builder could not be seen, because the one real instruction uses
    `caseID` for BOTH `childCol` and `parentKey` — **a pin whose fixture uses one value for two names cannot tell
    them apart.** Section 8d closes all three (the whole condition, a refusal that must return `STEP_FAILED`, and a
    made-up instruction whose four names all differ), and `$mustRefuse` is now pinned with
    `array_keys(...) === [...]` because ITERATING it meant deleting a case stopped testing it while the label still
    said eight (#697's remedy). **Nothing covers the LIVE path through `processEntry()` with a real refusal** — the
    audit line it writes and what an administrator sees — because reaching the ordering half needs a list the
    coverage test refuses and the earlier-run half needs a trail a correct run never produces. **`$where === ''`
    there is a clause that CANNOT FAIL** and is a backstop, not a tested one. Tests: sections 8c and 8d of
    `tools/gdpr-coverage-selftest.php` and section 19 of `tools/gdpr-eraser-selftest.php`; count the plants from
    the commit message, never from here (#671).
    **ROUND 2 FOUND FOUR MORE PINNED BY NOTHING, AND THE FIRST IS THE ONE TO REMEMBER:** `if (false && isset(...))`
    on the line ABOVE switched BOTH guards off in ONE WORD with all 336 checks green, because the inner pins matched
    text that never ran — **when you pin a call, pin the gate that decides whether its block is entered at all**, or
    you have pinned a label on a door nobody opens; the rule's own call was pinned by NOTHING. **A CHECK CAN NAME
    THE RIGHT FAULT AND MEASURE THE WRONG VALUE:** the check labelled "not a 250-character slice" measured the
    guard's RETURNED sentence, a literal in the same file, so putting `mb_substr(..., 0, 250)` back around the AUDIT
    LINE — where the fault was — left it green; the cut is applied by the CALLER and no value inside the guard
    changes when it is (measured: 261 and 317 characters as stored). **And widening a list of "good" actions put the
    whole fault back:** adding `'failed'` to the actions counting as "this step already answered" left both tests
    green and is LIVE HARM, because a probe failure writes "could not look for this person (matched through ...)" —
    carrying the very discriminator the `LIKE` clause matches — below the parent's change; `'skip'` is out for a
    different reason (the rows WERE found and left). Also: the comment justifying the unescaped pattern claimed the
    identifier rule allows neither `%` nor `_`, and it ALLOWS `_`, a single-character wildcard — harmless today only
    because neither name contains one, which is why it went unnoticed. **And error 1146 was the silent-success shape
    again:** a parent-matched step names TWO tables, and a missing PARENT was logged as `skip` under the CHILD's
    name, so the child's rows kept every word of the note while the request closed as completed; the parent's
    absence is a FAILED step now, asked of the database rather than read out of an error message. **The cost of all
    this was #710, now FIXED (3 November 2026):** a step that refuses for ever is right, but `closeRequest()` ran
    only when nothing failed, so such a request could never be closed and could not be cancelled either.
    `GdprEraser::finishByHand()` is the ONE other way to reach `closeRequest()`, and it RUNS the request one final
    time rather than asking whether a step *would* refuse — because that question says nothing about whether some
    OTHER step still has work left, so it would close a request with real work outstanding. It closes only when
    every failure of that run is the permanent kind, and `self::$onlyHandWorkLeft` is the ONE place a step is
    recorded as that kind: do not add a second, and do not match on the refusal's WORDS — an older run's line
    would be found too, and the request would be closed although its latest run failed for a quite different
    reason. There is no force-close and no free-text box (a description of what somebody did by hand to a
    safeguarding note would put that note's content into `tblErasureAudit`, which is deliberately absent from the
    catalogue by #510 rule 7d and is never erased). Cancelling such a request is STILL refused, correctly, because
    earlier steps have already changed records. Two things that were pinned by NOTHING and an independent check
    found both: the guard that leaves the administrator's number out when the request is THEIR OWN (every fixture
    used an administrator who was never the person being erased — a fixture in which a guard's condition cannot be
    true cannot test the guard), and the surface of `$onlyHandWorkLeft` itself, where the check counted ONE
    SPELLING of the write, so the same fault written `$x = $x + [...]` in the could-not-read branch passed every
    gate — and that branch needs no second mistake, one failed read of `tblErasureAudit` would close the request
    and write into the sealed trail that no further run could help. Section 22 of `gdpr-coverage-selftest.php`
    pins the whole surface now (exactly four mentions, comments removed by `token_get_all()`, never a regular
    expression). **Two fixture lessons worth keeping:** every case in the
    first version of that world was opened by the control person, so the actor-column plant matched NOTHING and went
    red only on the wrong check while the third-party check passed vacuously — a fourth case, opened BY the erased
    person and ABOUT somebody else, is what makes it fail for the right reason; and `['personUserID' => null]` does
    NOT give a null column, because `ge_insert()` reads the people list as `$people[$name] ?? $other` and `null ?? D`
    is D, so a null must go through `$fixed`. The technical record is DEV_NOTES.md, "Matching an erasure through a
    parent table (#709)".
    *(`.claude/CLAUDE.md` → the erasure bullet.)*

35. **Every table has a catalogue decision now, and the check that guards it asks about a column's SHAPE (#706).**
    `web/_core/personal-data-catalogue.php` had SIXTY-SIX tables missing entirely and THIRTY-SEVEN columns holding a
    person's details named in no entry at all. **The hard gate that exists to catch exactly this was GREEN over all
    thirty-seven**, because `tools/audit-checks/check_personal_data_coverage.py` decided what a personal column is
    from a hand-written list of LITERAL names — it knew `phone` but not `phoneNumber`, `address` but not
    `addressLine1`, `visitorIP` but not `createdIP`, nine particular `*Name` columns but not `walkinName`. Its own
    comments record ELEVEN separate occasions on which somebody had to remember to extend it. **It was somebody's
    memory wearing a schema-driven check's clothes — the #645 shape one level up: the guard meant to break the circle
    was inside it.** It now ALSO tests the SHAPE of the name: whole words split out of the column name, a contact word
    (email/phone/address/postcode/ip/dob/nhs), or `name`/`names` where the qualifier says WHOSE (reporter, submitter,
    walkin, counterparty, external, pickup, guardian). Only on a column that can really HOLD text, which keeps
    `emailOutcome` (an enum), `emailAt` (a datetime) and `emailLogID` (an integer) out. **The literal list STAYS
    beside it**, because no shape catches an account-link column (`actorUserID`, `giverID`, `decidedByID`), and those
    are most of its present value. **The judgement stays in the catalogue**, where a person reads it; the check only
    insists somebody has written one. **KEEP IT NARROW:** a first draft flagged 283 columns across 150 tables, because
    "contains the word name" matches `categoryName`, `deptName`, `typeName`, `themeName`, `tuneName`, `eventName`,
    `locationName`, `groupName` and `seriesName` — not one a person. A check that cries wolf gets switched off, and
    then it catches nothing (#483, and `check_limit_settings_capped.py`'s own lesson). As written it finds 37 and
    every one is real. **Free-text columns are an INVENTORY, not a gate** (the owner, 2 November 2026 — D-030): they are
    printed with a count (do not write the number down here - it moves every time a column is named), because most hold nothing personal while a few certainly do, and a written reason for each
    would be mostly boilerplate. **So a green run means every CONTACT-SHAPED column is named, NOT that every personal
    column is**, and the check says so itself. Three rules the catalogue enforces, each learnt the hard way here: a
    `notEmptiedHere` key must ALSO appear in `columns` (#635 — `columns` says what makes the table personal,
    `notEmptiedHere` why a named one survives); an entry may not promise an action the code cannot perform (#535), so
    `tblExpenseClaimItems` and `tblReadingPlanProgress` — which carry no account column anywhere — are `retain` with
    the truth in the reason rather than `unlink` and `erase`; and **`tblErasureAudit` must NOT be in the catalogue at
    all** (#510, rule 7d of the coverage test, which refuses ANY instruction naming it — **including a `retain` entry,
    because "keep this" is still an instruction**), so the file carries a comment at that spot, because a deliberate
    absence looks exactly like the #706 fault. **NOT ONE of the sixty-six carried a `userID`** — they are lists of
    kinds, settings, and child tables keyed by a parent (`tblExpenseClaimFiles.claimID`, `tblEventJobs.eventID`) — so
    they were unreachable BY CONSTRUCTION rather than by oversight, which is why nobody had written them down. #709's
    parent matching reaches some of them, **but NOT `tblExpenseClaimFiles`**: its parent is deliberately KEPT for
    HMRC, so there is no deletion to cascade and no parent-matched clause to write — a mechanism that reaches rows
    through a parent is no help when the parent is the thing being kept. **WRITING AN HONEST REASON IS WHAT FOUND THE
    FAULTS:** four of the sixty-six reasons could not be written truthfully without recording something broken, and
    each became an issue — #712 (two tables keep a visitor's network address in full and nothing ever clears them; one
    carries an index named `idx_ratelimit_prune` for a tidy-up that does not exist), #713 (`tblWebhookEvent` keeps the
    WHOLE Stripe/PayPal message, stored BEFORE the check on who sent it is acted on, on a table with no foreign keys
    at all, carrying the payer's name, e-mail and billing address), #714 (three places an erasure cannot reach). An
    entry saying "nothing clears this" is a description of today, not a defence of it. The technical record is
    DEV_NOTES.md, "A guard that looks schema-driven and is not (#706)".
    **ROUND 1 OF THE CHECK FOUND THE GUARD ITSELF BROKEN, and it is the
    best lesson here: a BRACKET-COUNTING parser is broken by a COMMENT,
    and the comment that broke mine was a note about this very parser.**
    One unbalanced `[` inside tblAnonymousCheckins' comment made that
    entry's "block" run 144,494 of the file's 153,340 characters,
    absorbing every other entry's `notEmptiedHere` keys -- so a
    contact-shaped column on that table was INVISIBLE if its name
    appeared anywhere else in the file. Live on the day it was written.
    **Stripping comments is the WRONG fix** (a reason may contain `//`,
    as any URL does): delimit each entry from its own header to the NEXT
    header and parse no structure at all. Same cause, second fault: the
    decision regex wanted `'decision'` immediately after `[`, so it
    MISSED the two entries carrying a comment there -- 226 seen where
    PHP sees 228, and the success line printed the wrong number as
    coverage. Four more: **"nothing erased them" conflated two faults**
    (about a dozen of the 37 were ALREADY erased and merely
    undocumented, which `git show HEAD:` proves, while the rest are
    genuinely unreached); **naming a column does NOT add it to the data
    copy** (`inventory()` reports table, count, action and reason, never
    a value); **a `period` must be checked against the purging code**
    (`tblAssetFoundReports` IS cleared at 180 days by the nightly asset
    job, and that method's own docblock records the same untruth being
    deleted once before); and **a reason may not cite the issue being
    closed** (four said "Tracked as #706" and now say #715). Also:
    listing a column under an `erase` table READS as erased --
    `externalName` means "not a portal user", so that row has no account
    number and the delete never reaches it.
    *(`.claude/CLAUDE.md` → the #706 bullet.)*

36. **The change history ABOUT the erased person is emptied now, and the EASY WAY WOULD DESTROY THIRD-PARTY RECORDS (#707).**
    `tblAuditTrail.userID` is the ACTOR; what was CHANGED is the pair `(tableName, recordID)`, and NO column says who a row
    is ABOUT — so an entry reading "somebody edited tblUsers id N" was reachable by NOTHING, while `Logger::audit()` records
    a creation as the WHOLE new row and a deletion as the WHOLE old one, so a change to somebody's own account wrote their
    NAME AND E-MAIL ADDRESS in there — measured, and NOT the telephone or postal address my first version claimed.
    `GdprEraser::eraseAuditTrailAboutPerson()` empties the three value
    columns; ANONYMISE, not delete (the owner, 2 November 2026 — D-031) — who acted, when, which table and which record all stay.
    **DO NOT "SIMPLIFY" IT BY ADDING THOSE COLUMNS TO THE ORDINARY INSTRUCTION'S `nullCols`:** that instruction matches
    `userID`, so it would empty the before-and-after of every change this person made to SOMEBODY ELSE'S record AND still
    reach no row about the person — wrong in both directions, #709's lesson in a new table. One check in section 20 of
    `tools/gdpr-eraser-selftest.php` exists purely to fail on that, and does. **And it is NOT in `DEDICATED_STEP_TABLES` on
    purpose, but MY FIRST REASON WAS FALSE and an independent check disproved it:** I wrote that registering it would make
    `fromPersonalDataCatalogue()` SKIP the table and lose its `ipAddress` instruction. It cannot — that instruction is
    HAND-WRITTEN and the deriver already skips every hand-written table. Measured: 222 instructions before and after,
    unchanged. The REAL reason is enforced — with it registered, `gdpr-coverage-selftest.php` fails its check that a
    dedicated-step table is erased, in time, AND EXPORTED, and this step empties three columns of a KEPT row. Called
    directly from the run, as `eraseProfileImages()` is. **THE ORDER IS LOAD-BEARING, #709's trap again:** rows about the person's ORGANISATION memberships are
    found by looking their `tblUserSites` rows up and the walk DELETES those, so the step runs BEFORE the walk; moving it
    after turns four checks red. A column a dedicated step empties is recorded in `notEmptiedHere` beginning "Emptied
    elsewhere, on purpose", the `tblUsers.avatarPath` precedent. Not reached: a row about some OTHER record whose values
    quote the person. Two fixture traps, both of which failed loudly: `ge_user()` does NOT make a membership row, and
    `ge_insert()` answers `{table, key}` with the key BY NAME, so `['id']` is 0. Adding a step shifts SIX hard-coded counts
    in that test. **#708:** `tblCareVisit.followUpAssignedToID`'s instruction is correct and can NEVER MATCH — nothing
    writes the column — so the limit is recorded beside it rather than the instruction being deleted or the page changed
    (the owner, 2 November 2026 — D-034). `tblKidCheckins.pickupName` was already recorded on 1 November 2026. The technical record
    is DEV_NOTES.md, "Reaching a row that nothing says is about anybody (#707)".
    *(`.claude/CLAUDE.md` → the #707 bullet.)*

    **The erasure review's two races are exercised now, and keep BOTH kinds of check (#724, #753,
    #735).** `ErasureReview::record()` is the one place a decision about a line of free text is
    recorded; it opens its transaction and THEN reads the cell with `FOR UPDATE` (#697: a lock
    outside a transaction is decoration). `GdprEraser` asks `whyNotReadyToClose()` twice — at the top
    of `execute()`, the refusal an administrator meets, and again inside `closeRequest()`'s
    transaction, which covers a reviewable row being written while a run is already going. "No test
    can reach this" was a wrong CONCLUSION from a true fact, and that is the transferable part: the
    comment said no end-to-end test could reach the second ask "because the test harness runs one
    request at a time", which is true and does not follow — one run is SEQUENTIAL (top check, every
    step, then the close), so a row appearing anywhere in the middle of that one run reaches it, with
    no second request, connection or process. Ask which two events have to be ORDERED, never how many
    actors there are. A trigger on `tblErasureAudit` provides the write (every step writes a line
    there), the same technique as `password-writer-selftest.php` section H. Measured with the second
    ask deleted: 233 steps, `failed: 0`, the request `completed`, and the person's own words still in
    the row. And section R provably could not catch the lock being deleted — R1-R4 take the lock IN
    THE TEST, so they prove the database locks rather than that `record()` asks it to; section T
    never takes it on the method's behalf (this connection holds the row, the real `record()` runs in
    its own process and blocks on it, which one process cannot do to itself). The harm, read out of
    `record()` rather than guessed: without the lock the second decider's in-transaction read is a
    consistent-snapshot read taken before the other committed, so it still sees the words, writes
    `keep`, and because the decision is written `ON DUPLICATE KEY UPDATE` that `keep` lands on top —
    the record then says the words are there over a cell somebody else emptied. `uq_errd_item` is
    not a substitute for the lock: no part of it can be empty so it really binds (unlike #487), but
    `ON DUPLICATE KEY UPDATE` makes it merge the second decision rather than refuse it. Measured
    three of three with ` FOR UPDATE` deleted: R1-R4 green, T2 and T3 red. Do not delete R5 as
    redundant now T exists: T would still pass if the lock were taken some other way, and R5 would
    still pass if it were taken outside the transaction. Sections S and T drive the classes — nothing
    presses two buttons on `review.php` at once. Test: `tools/erasure-review-selftest.php` (145
    checks, real MySQL; needs `CREATE TRIGGER` and `proc_open`, each refused loudly rather than
    skipped). **AND THE SECTION WRITTEN BECAUSE R WAS VACUOUS WAS ITSELF VACUOUS AT THE MARGINS — the same shape one level up, found by the independent check.** `record()` refuses an already-empty line in TWO places — before its transaction (`oneItem()`: not a review item) and inside it under the lock — and T2 matched only the word `REFUSED`, so it could not tell them apart while its own label asserted the second. Measured: with a short sleep the worker returns the FIRST refusal, so **at a sleep of ZERO the whole of section T was green in BOTH states**, and nothing asserted the 1.5-second sleep had been long enough (with the lock removed T2 went red at 50ms and green at 20ms — a 40-times margin here, narrower on a loaded runner, failing in the FALSE-GREEN direction). T2 now requires the in-transaction wording and T1b asserts the worker is still blocked. **Be exact about which closes it: at sleep zero T1b still PASSES** (the worker is not yet exec'd, so it counts as running) and T2 is what goes red — a first comment claimed the opposite, which is a claim about a measurement never made. **When a test's verdict depends on a sleep, ASSERT THE STATE THE SLEEP WAS MEANT TO PRODUCE:** a sleep is a hope, a `proc_get_status` is a premise. **And S2 was a clause that could not fail:** `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT` makes mysqli THROW (it takes BOTH — #694 rounds 5 and 6), so `query()` never returned the `false` it tested for, while four documents claimed the CREATE TRIGGER requirement was "refused loudly" — it gave an uncaught exception, no red check and no summary. **A check whose failure mode is an uncaught exception is a crash with a label, not a check that reports.** The technical record is DEV_NOTES.md, "The erasure review's two races (#735)". Claude
    reads the same rule in `.claude/CLAUDE.md`; change both together.

37. **Nothing asks for a reply it would decode with BROTLI (#716, CVE-2026-111497 "HTTP2Shell").**
    An out-of-bounds write in Google's Brotli decoder (1.0.3-1.2.0; fixed 1.0.10 / 1.1.1 / 1.2.1), remote,
    unauthenticated, CVSS 9.8, on CISA's actively-exploited list since 27 October 2026. The portal holds NO Brotli code,
    so its whole exposure was ONE LINE. **SETTING `CURLOPT_ENCODING` TO *ANY* NON-NULL VALUE SWITCHES LIBCURL'S
    CONTENT DECODING ON, AND LIBCURL THEN PICKS THE DECODER BY NAME FROM THE *REPLY*'S `Content-Encoding`** — what you
    advertise is irrelevant. **`'gzip, deflate'` WAS COMMITTED AS THE FIX (`8dc848a`) AND STILL DECODED BROTLI**;
    an independent check caught it. Measured against a server declaring `br` unasked (libcurl 8.22.0, brotli/1.2.0):
    unset → 33 raw bytes NOT decoded; `''` → decoded; `'gzip, deflate'` → STILL DECODED. **THE RULE IS: DO NOT SET THE
    OPTION AT ALL.** `SafeFetch` sets nothing now — what every other curl site here already did, and why they were all
    immune while the one "configured" site was the only vulnerable one. THE THREAT IS THE SERVER ITSELF, so "we did not
    ask for it" is worth nothing. The cost is compression on the wire, accepted deliberately. **FIX IT HERE, NEVER WAIT
    FOR A HOST:** this is a product on hosting we do not control, we cannot know which Brotli a customer's PHP links,
    and a version number cannot prove a vendor-patched build (#640's rule). **THE SIZE CAP IS NOT A GUARD** — its own
    comment says it counts bytes AFTER decompression, so the decode has already happened; and SafeFetch's other
    defences are all about WHERE it connects, not what the reply is decoded with. Every other curl site leaves the
    encoding UNSET, which is safe; `.woff2` fonts are decoded by the BROWSER; Apache's `mod_brotli` COMPRESSES, the
    other direction. Two guards, the behavioural one mattering more:
    `tools/audit-checks/check_no_brotli_decode.py` (a hard gate, and it must treat an EMPTY value as a FAILURE, the
    opposite of how empty usually reads) and two checks in `tools/safefetch-selftest.php` that ask the test server what
    `Accept-Encoding` it REALLY received, split into codings rather than searched as text. Restoring `''` turns BOTH
    red. **Not the product, and still outstanding:** this workstation's Node is v26.11.1 against a fixed 26.12.1 and
    its libcurl links the affected brotli/1.2.0; no workflow pins a Node version. Recorded on #716, not changed. The
    technical record is DEV_NOTES.md, "\"No compression preference\" means \"offer everything\" (#716)".
    *(`.claude/CLAUDE.md` → the #716 bullet.)*

38. **A RESERVED WORD USED AS AN IDENTIFIER MUST BE BACKTICKED OR QUALIFIED (#718, #276).**
    MySQL ignores case in keywords, so **`fullText` IS `FULLTEXT`** — a bare `fullText = ?` does not parse, `php -l`
    cannot see it, and that is how the statement that SAVES a transcript shipped unable to run, throwing AFTER the
    outside provider had been called and charged. Until #718 no audit script could see it either, exactly as the #276
    note said. `tools/audit-checks/check_reserved_words_quoted.py` is now a hard gate: every reserved identifier in
    `full_schema.sql` must be in `RESERVED_ON_PURPOSE` with a reason (fails BOTH ways), and every reference to a listed
    one must be backticked or qualified by an alias — **both work, because after a dot a reserved word is allowed**.
    **EXACTLY ONE of 229 tables needs it: `tblTranscript.fullText`.** Written because the reference host moves to MySQL
    8.4 on **9 November 2026**. **THE HOST'S NOTICE IS WRONG ABOUT HALF ITS LIST:** it names `MANUAL`, `PARALLEL`,
    `QUALIFY`, `TABLESAMPLE` as new reserved words; measured on a real MySQL 8.4.13, `manual` and `parallel` are NOT
    reserved and work unquoted. **Take the list from MySQL, never from a summary of MySQL** — a guard built from the
    notice would have failed on a column named `manual` (#483). **THE ALIAS RULE WAS WRITTEN AND DELETED ON PURPOSE:**
    `AS <word>` cannot be told from English ("as long as", "as not", "as if") and `CAST(x AS BINARY)` is valid SQL; it
    gave fifteen reports and not one was real. **And "does this string look like SQL" had to be strict:** asking for
    select/set/where/from matched ordinary prose in the help pages, so it now requires a `tbl[A-Z]` table name or a
    leading SQL verb. What it cannot see: a continuation fragment like `. 'fullText = ? '` alone. Nothing else was
    needed for the upgrade — the 8.0 end-of-life warning already existed (#489/#640) and the harness already runs
    8.4.11. The technical record is DEV_NOTES.md, "Take a reserved-word list from MySQL, not from a summary of MySQL".
    *(`.claude/CLAUDE.md` → the #718 bullet.)*

39. **The hard-coded reserved-word list is CHECKED AGAINST A REAL SERVER (#719).** It has to be hard-coded —
    an audit script must run with no database, which is what lets the pre-push hook run every one of them in about
    nine seconds — and a hard-coded list goes stale SILENTLY. `tools/reserved-words-live-selftest.php` reads the
    `RESERVED` set OUT OF the Python (never a second copy) and compares it with the server: on MySQL,
    `INFORMATION_SCHEMA.KEYWORDS WHERE RESERVED = 1`; on MariaDB, which has `WORD` and no `RESERVED` flag, by TRYING
    each candidate as a column name, because membership does not mean reserved. **Only "the server reserves a word
    we LACK" fails**; the other direction means the list is conservative, which is safe. It earned its place on the
    first run: 8.0.36 reserves `master_bind` and `master_ssl_verify_server_cert`, which the list lacked, and
    **MariaDB 11.4 reserves seventeen more including `offset` and `returning`** — both thoroughly plausible column
    names. The list is now the UNION of all FIVE engines the matrices run on (MySQL 8.0, 8.4 and 9.7; MariaDB 11.4 and 12.3). **It went STALE for the two newest the moment #475 part 1 added them to the harness and nothing was pointed at either** — measured 4 November 2026, the committed list FAILED on both: MySQL 9.7.4 reserves `external` and `library`, MariaDB 12.3.3 reserves `conversion`, `current_path`, `to_date` and `vector`, and none was in it. None is a column name here, so nothing broke — luck, not coverage. `vector` is the one to notice, since both families are adding vector types. The fix is two changes together: the six words, AND `calendar-selftests.yml`'s matrix taken to those same five engines, because adding a word without pointing the test at the server that reserves it leaves the next version just as blind. **A warning from doing it: a probe loop reported `phase` and `recover` as reserved on MariaDB too, and tried ONE AT A TIME both are ACCEPTED — the generated table names collided, and a collision looks exactly like a refusal. Probe one word at a time and read the error, never the exit code** (#483: a check that cries wolf gets switched off). `DbServer::TESTED_LINES` is NOT touched and must not be — it describes the MIGRATION HARNESS alone. Wired into `portal-selftests.yml` (8.0.36 and 8.4.11)
    and `calendar-selftests.yml` (8.0.36, 8.4, 9.7, MariaDB 11.4 and 12.3). **What it cannot see, measured:** a MariaDB reserved
    word containing a DIGIT — the probe tries only `^[a-z_]+$`, so `float4`, `float8` and `int1`-`int8` are never
    tried there. All seven are already in the list and no column here is named any of them, so it costs nothing
    today. *(`.claude/CLAUDE.md` → the #718 bullet.)*

40. **A person's own words about themselves are emptied on an erasure request (#715, migration 240; the owner,
    3 November 2026).** A prayer request's `subject`, `body` and `partnerNote`, and `tblCareCase.summary` — a third
    party's description of the erased person's circumstances, which sat beside visit notes #709 already empties. The
    rows stay. **Migration 240 makes three of those columns nullable, and the hard gate
    `check_erasure_columns_nullable.py` is what found it: a NOT NULL column CANNOT be emptied and the failure is
    QUIET** — the erasure records a line saying so, carries on, and the request can still close as completed with
    the person's words in place. **KEPT on purpose, because the words are about the organisation:** `presenterText`
    (twice), `deviceName`, `seatLabel`, `meetingNotes`, and the `tblErrors` columns, which expire on that table's
    own timer — with the honest condition stated, that the sweep is a scheduled job a customer must set up.
    **NOT reached, each recorded rather than forced:** a salvation card's `prayerRequest` (nothing on the card says
    whose it is) and a room booking's text, which `GdprEraser`'s own guard refuses because `bookedByID` is in
    `ACTOR_COLUMNS` — **#723 IS DONE (3 November 2026): `bookedByID` has LEFT `ACTOR_COLUMNS`** on the owner's decision that a booking is
    made BY the person it is about, so `purpose` is emptied. **BE EXACT: `purpose` AND NOTHING ELSE.**
    `tblResourceBooking.notes` is ALSO the booker's own words — `_apps/resources/book.php` is its only writer and
    reads `$_POST['notes']` from the booker's own form on the same row — and the catalogue claimed it was "the
    organisation's own note … rather than anything the booker wrote", which was FALSE; the owner was told that and
    chose to KEEP it, so the reason is now that decision. And `declineReason` is written by NOTHING (the decline
    branch sets only status, approver and approved-at), so its place in `nullCols` can never match: the limit is
    recorded in the INSTRUCTION's reason and not in `notEmptiedHere`, because the #635 check fails a column
    explained as not-emptied while an instruction empties it — the two mechanisms cannot both describe one column.
    #708's precedent. **`approvedByID` STAYS an actor column, and that pair is the whole point of the two lists** —
    emptying text on an approver's number is a blanket `= NULL` over every booking they approved. The guard was NOT
    weakened; the classification was the fault. **The eraser test now holds a booking somebody ELSE made and this
    person only APPROVED, and without it the reclassification could have been widened to both columns with every
    check still passing** (planted). No migration: both columns were already nullable.
    A case-by-case review role is #724, built next. **Two lessons from the
    independent check.** `prayer-requests/index.php` printed `$req['subject']` with NO cast, twice, and the
    congregation feed is not filtered by submitter — so the page would really have thrown
    `htmlspecialchars(): Argument #1 must be of type string, null given`, mid-render, giving an HTTP 200 ending in
    an ordinary `</html>` with the feed missing; migration 240's own header had claimed every reader cast, and said
    three writers where there are five. **And #715's whole behaviour was pinned by NOTHING:** dropping `body` and
    `summary` again, each with a plausible catalogue excuse, left 263 eraser checks, 93 coverage checks and both
    hard gates green. `MUST_BE_EMPTIED` in `gdpr-eraser-selftest.php` exists for exactly that and its own comment
    says so; #715 had added four columns to the instructions and not to the list.
    *(`.claude/CLAUDE.md` → the #715 bullet.)*

41. **A part-written PDF is reached by an erasure and swept (#721); a part-written upload is never recorded
    (#720).** `Pdf::PART_MARK` is the ONE place the temporary mark is named — the writer builds the name from it and
    `Pdf::sweepPartFiles()` looks for it, so two literals would be two places to change and **the half that broke
    would be the SWEEP**, which would simply stop finding anything: no error, no log line, and a folder with nothing
    to sweep looks identical. The hard gate `check_pdf_write_verified.py` WENT RED on that change, which is the gate
    working; it accepts the constant form now AND fails when the constant is missing or empty, and both forms still
    require the name to be built FROM `$filePath` (the temporary must be in the SAME folder — `rename()` is only
    atomic within one filesystem). **#721 was a cost of #711's own fix, recorded at the time:** a process killed
    between the write and the rename leaves `statement-7-2026.pdf.part-…`, and the eraser's glob was
    `statement-<donorId>-*.pdf`, which does not match it — a giving statement holds the donor's name, home address
    and every gift. **Before #711 the same crash left a TRUNCATED `…-2026.pdf`, which DID match and was erased**, so it was a narrow regression in erasure reach taken in exchange for not
    destroying good files. **THE PRIVACY HALF TOOK TWO CHANGES AND THE GLOB ALONE WAS NOT ENOUGH:** widening it made the erasure FIND the file
    and `statementFileIsKept()` then KEPT it — "every gift" answers kept for anything, and under "receipted only"
    the `-4` chop for `.pdf` left an unparseable period so the "no period means keep" fallback applied to rubble;
    measured, the genuine statement came back NOT kept and the part file KEPT, and the step then COUNTED it and told
    the administrator it was tax evidence. A file carrying `PART_MARK` is a write that never finished, so it is
    refused before the scope is looked at. **Nothing caught it because the test world never wrote a kept-donor row
    for the erased person, and the proof was inside the test** — its own fixture period does not parse either, so if
    that branch had run the EXISTING check would already have failed. **AND THE SWEEP DELETED A MEMBER'S OWN
    UPLOAD:** the first version walked all of `_uploads` for any name CONTAINING the mark, under a docblock saying
    "nothing else uses this mark today" — false, because `_apps/documents/upload.php` and
    `_apps/recordings/upload.php` take the stored extension STRAIGHT FROM THE CLIENT FILENAME, so `report.part-x`
    was stored as `…aabbccdd.part-x` and deleted an hour later while the row survived and the portal went on
    offering it. **`Pdf::PART_FOLDERS` is the ONE list of folders it may touch (the four `Pdf::create()` writes to;
    `documents` and `recordings` DELIBERATELY ABSENT) AND the name must END with the mark plus exactly twelve hex
    characters** — the owner chose both, because this method deletes and a one-condition guard rests on nobody
    editing that condition: removing the name test alone left all 31 checks green while a day-old statement, an
    expense PDF, a document and a recording all went. Symbolic links are refused TWO ways, both needed (no
    `FOLLOW_SYMLINKS` for a linked FOLDER, `isLink()` for a linked FILE), and `CATCH_GET_CHILD` is there because one
    unreadable subfolder otherwise makes the walk throw and the sweep remove nothing. **FOUR of that section's
    checks could not fail:** the "real PDF left alone" fixture was never aged so the age guard did the mark test's
    work; the symlink check planted a link to a FILE, which `isLink()` skips whatever the flags are, so
    `FOLLOW_SYMLINKS` passed 31/31; and nothing exercised the folder list or an unreadable folder. **Never describe
    the sweep as what makes an erasure complete** — a request run between the crash and the next sweep would still
    miss the file.
    ONE HOUR is the owner's choice (3 November 2026) and
    **the check that matters is the one that must NOT fire**: section H of `pdf-write-selftest.php` gives the sweep a
    file written THIS SECOND and requires it to survive, because a sweep that took a write in flight would be worse
    than the litter it removes. A symbolic link is NOT followed: the method deletes what it finds. **#720 is #711's
    fault in a second file:** the documents API's base64 branch tested `file_put_contents(...) !== false`, which
    accepts a SHORT COUNT, so a part-written document got a `tblDocuments` row and was offered for download. The
    multipart branch was already right (`move_uploaded_file()` answers a boolean), and **the two sharing one
    `$written` variable is how the wrong test looked like the right one** — they are judged separately now. It does
    NOT need temp-and-rename, checked rather than assumed: `$storedFilename` is `bin2hex(random_bytes(16))` plus an
    allowlisted extension, fresh every request, and the only other writer of that folder always has underscores in
    its names — **if stored filenames ever stop being freshly generated, that reasoning goes with them.** No
    behavioural test is possible (a short write needs a quota, the page refuses anything but an authenticated POST,
    and the basename is a fixed 36 characters so the long-name trick is unavailable), so the guard is static and
    **the guard is itself tested**: fifteen plants, each required to be caught by the expected complaint AND BY NO
    OTHER, with one case that must stay GREEN (a closing brace inside a string, because a brace walk that cannot
    step over literals cries wolf). Both are hard gates in `pr-security.yml` rather than left to the pre-push glob
    (#646). Open: **#725** (nothing exercises the documents API over HTTP at all) and **#726** (whether
    `move_uploaded_file()` can report success after a partial write — not established either way).
    *(`.claude/CLAUDE.md` → the #721/#720 bullet.)*

42. **A GREEN WORKFLOW LINTER PROVES NOTHING ABOUT WHETHER THE FILE RUNS (#745).** GitHub's Actions service puts a
    ceiling of 21,000 characters on any ONE value that carries a `${{ ... }}`, and a step's script IS one value — so
    a script holding a single `${{ ... }}` counts in its entirety, and over the ceiling GitHub refuses **the whole
    file** and starts no job at all. That cost a week here: commit `c61211e` (27 October 2026) took
    `pr-security.yml`'s heuristic script from 14,910 to 24,525 characters, and ALL THIRTY-FIVE of that file's
    hard gates ran on GitHub
    without running anything until 3 November. **Every parser on the machine accepted the file, and the `lint.yml`
    actionlint job was GREEN on every failing day**, because the ceiling belongs to the service and not to the
    workflow schema. **The tell is the run's own `name`: when GitHub cannot read a file it names the run after the
    FILE PATH instead of the workflow** — `gh api repos/<owner>/<repo>/actions/runs/<id> -q '{name,path}'`, and
    `name == path` means the file was never parsed; such a run also arrives under `event: push` for a
    `pull_request`-only workflow, because GitHub cannot tell whether the file matches the event. **Never read "This
    run likely failed because of a workflow file issue" as the answer** — it is a generic guess, and the API's
    `name` field is the fact. The cure is to pass the value in through `env:` and read it as an ordinary shell
    variable, never `${{ ... }}` inside a script, which is GitHub's own hardening advice as well, since the value
    becomes part of the script. Guarded by `tools/audit-checks/check_workflow_expression_length.py`, which **cannot
    be a step in `pr-security.yml`, because a workflow cannot run from a file GitHub will not read** — it is in the
    pre-push glob and a hard gate in `lint.yml`. It also fails on a workflow file over GitHub's 500 KB limit, and on
    a script over the ceiling carrying no expression that is not listed in `LONG_SCRIPTS_ON_PURPOSE` with a reason
    (the list fails BOTH ways). What it cannot see: a long `${{ ... }}` in a `with:`, `env:` or `if:` value, which
    hits the same ceiling — nothing here is near it, and measuring those needs a YAML parser this machine has not
    got. **The exact boundary was not measured**; 21,000 is the figure in GitHub's own error message, and what was
    measured against the real service is that 14,910 characters with an expression was accepted and 24,525 refused.

43. **WHAT THE PORTAL TELLS A CUSTOMER IS TESTED MUST BE DERIVED, NOT WRITTEN — AND TWO OF THE OLD SENTENCES
    WERE UNTRUE ON A CUSTOMER-FACING PAGE (#475 part 1, 3 November 2026).** `Portal\Core\DbServer` showed a
    customer a hand-written sentence about which database versions this project's automated tests run on. Every
    MariaDB answer said they "only ever run against MySQL" — **eight days after the migration harness began
    replaying every database change on MariaDB 11.4** (#487) — and every supported-MySQL answer said they "run
    against the MySQL 8.0 line, so if you are on something newer you are ahead of what is routinely tested here",
    which told somebody on MySQL 9.7 that nobody had tested their version. Five copies in all, including the
    class's own docblock and the 8.0 message. All stale in the MODEST direction, which is the safer one and is
    **not** a defence: the next one to go stale could as easily say a version is tested when nothing runs on it.
    **`DbServer::TESTED_LINES` is the ONE list of the lines the HARNESS runs on, `testedLinesCaveat()` the ONE
    place the sentence is built**, and it differs BY LINE because that is what is true — the harness runs on none
    of MariaDB 10.6, 10.11 or 11.8, all of which the portal still accepts. **A constant on its own just moves the
    staleness one step along**, so `tools/audit-checks/check_tested_db_lines.py` compares it with the `include:`
    matrix in `.github/workflows/e2e-migrations.yml` and **FAILS BOTH WAYS**, with no exception list, because
    there is no honest reason for the two to differ. It describes the migration harness and NOTHING ELSE: the
    calendar self-tests run on their own engines and folding them in would overstate both. Never write
    "supported" there, and never drop the sentence saying nobody runs this portal on MariaDB in production.
    **How it was found is the transferable part: by PRINTING what `classify()` really says to a customer, never by
    reading the code** — the sentence is correct English, correctly assembled, from a variable nobody had reason to
    re-read, and wrong only against the world. Print the WHOLE sentence: a first look truncated to 96 characters
    produced a confident finding about MariaDB 10.6 that was false. **MySQL 9.7.4 and MariaDB 12.3.3 now pass all
    seven phases and all 42 checks** (the same 42 as the other three engines, counted), where nothing here had ever
    been run on MySQL 9.x or MariaDB 12.x before. **MySQL 8.0 and MariaDB 10.6 STAY accepted with a warning — the
    owner's decision; do not tidy `MIN_MYSQL` or `MIN_MARIADB` upwards** — which makes the warning the only
    protection there is. **PHP 8.5 is linted now, and `php-static-analysis.yml` MUST NOT BECOME A MATRIX**: a
    matrix renames the check to `PHP -l lint (8.4)` and the ruleset "Protect main branch" requires `PHP -l lint`
    by that exact name (#474), so every pull request into `main` would wait for a check that never arrives.
    `tools/check-end-of-life.py` plus `end-of-life-watch.yml` are SET to watch for approaching end of support and have never run — a `schedule` trigger cannot fire from a non-default branch, so the monthly run begins at the merge (#754). Reading the
    version lists out of the two classes rather than copying them; it is deliberately **not** in
    `tools/audit-checks/`, which must run offline. It earned its place at once: **PHP 8.4 loses active support on
    31 December 2026** and `MIN_PHP` is 8.4.0.
    **AND THE GATE ITSELF RAN IN NO WORKFLOW AT ALL when it was written — the #646 shape re-created in the package
    that cites #646, and the only one of 56 audit scripts in that state.** The cause is a standing fact worth
    knowing: **`pr-security.yml` names every audit script INDIVIDUALLY and there is NO folder glob in any workflow
    here**, while `.githooks/pre-push` DOES glob the folder — which is what misled me, and a hook turned on per
    clone that `git push --no-verify` skips is not a gate. Test it with

    **And a workflow is not a gate until GitHub has registered it — six of ours never have (#754).**
    `portal-selftests.yml`, `calendar-selftests.yml`, `mail-selftests.yml`, `backup-selftest.yml`,
    `package.yml` and `end-of-life-watch.yml` exist only on the working branch, so GitHub has never
    listed them, let alone run one. That makes "wired into `portal-selftests.yml`" true of the file
    and false as a claim about what is checked today, and several recent packages said it without the
    qualifier. GitHub does use a pull request's own copy of a workflow file, so these would very
    likely start running the moment a pull request is opened; the finding is that nobody has ever
    seen them run, so none is known to work on GitHub's runners as distinct from on this Mac. The
    one-line test: `gh api repos/MWBMPartners/WebMS-Intra/actions/workflows --jq
    '.workflows[].path' | sort > /tmp/reg; ls .github/workflows/*.yml | sort | comm -13 /tmp/reg -`.
    `pr-security.yml` IS registered, so its hard gates are real. Two of the six are worse than
    "never run": they cannot run. `package.yml` — the thing that builds the customer's installation
    zip — triggers only on a push to `main`/`rc`/`beta`/`alpha` or a `v*` tag, and on
    `workflow_dispatch`, which needs the file on the DEFAULT branch; so pushing this working branch
    fires nothing and nothing can dispatch it, and the release build will first be executed by
    GitHub at the merge. `end-of-life-watch.yml`'s `schedule` is in the same position, though its
    path-filtered `pull_request` trigger can reach it sooner. The other four all carry
    `pull_request`, so a pull request would run them from its own copy. What #754 could be closed with
    locally, and was: asking what each test NEEDS and comparing it with what its job provides. That
    found exactly one gap in all six — `calendar-selftests.yml` did not request the `intl` extension,
    so `ics-reader-selftest.php` would have SKIPPED checks L5 and L6, the only guards on the
    committed Windows-time-zone list (#557), and no other workflow runs that test. Three other
    candidates were false alarms, worth naming so the next sweep is narrower: `rclone` in
    `backup-page-selftest.php` is a stored setting value and not a program; `package-selftest.sh`
    builds a stand-in when `gitleaks` is absent and three of its cases need it absent; and
    `build.sh`'s refusal without `shasum` is satisfied, because the runner image ships Perl 5.38.2
    and `shasum` comes with Perl (measured against the image's own README, not assumed).
    Claude reads the same rule in `.claude/CLAUDE.md`; change both together.
    `for f in tools/audit-checks/check_*.py; do grep -rqs "$(basename $f)" .github/workflows/ || echo "NOT WIRED: $f"; done`.
    Two more from the same check, both the usual shapes: **the gate could be switched off by an ordinary COMMENT**
    (it took the FIRST match in the file, and the house style asks for a comment recording the previous value, so a
    line the portal claimed and nothing tested passed with OK — comments are blanked now, keeping line and column
    so numbers stay right, and blanking them immediately broke `^(\s*)include:`, which matched across the blanked
    lines: use `[ \t]`, never `\s`); and **the customer-facing sentence was pinned by NOTHING** — `return '';` at
    the top of `testedLinesCaveat()` removed it from every answer with 36 of 36 checks green, because the clause
    that claimed to guard it tested `$got['untested'] !== true`, which is set for every MariaDB answer and so
    **could not fail**.

44. **THE IN-APP HELP IS READ BY SOMETHING NOW, AND NEITHER GUARD JUDGES WHETHER IT IS TRUE (#744, 4 November
    2026).** Until then **THIRTEEN of the twenty were loaded by nothing**, and the other seven were fetched over
    HTTP only for their WORDS by six other self-tests — **not one of the seven checked whether the page
    FINISHED**. (An earlier version of this rule said "nothing loaded any help page", which was untrue and
    carried no command, against the rule about claims of that shape. Established by
    `git grep -nE "(req|pw_req|[$]get)[(].*'/help" 55b0f78^ -- tools`.) Twenty pages read by
    end users, on code that can throw, and the worst case is `help/disaster-recovery.php` — opened when something
    has ALREADY gone wrong, and which #583 found telling customers to do two things that would have destroyed their
    data. Two guards: `tools/audit-checks/check_help_page_references.py` (a HARD GATE, the owner's choice) requires
    every runbook section number a help page SHOWS A READER to be a real heading in
    `docs/disaster-recovery-runbook.md`; `tools/help-pages-selftest.php` (50 checks over HTTP) requires every help
    address to answer a WHOLE page to an administrator and a whole page or a clean refusal to a member.
    **NEITHER CAN JUDGE WHETHER THE ADVICE IS CORRECT — no check can, and both say so in their headers rather than
    implying the help is verified.** **"Whole" is the load-bearing word: a page that throws part-way still answers
    HTTP 200**, because its header has already gone, so what arrives is the TOP of a page with nothing wrong IN it
    and only the closing `</html>` shows it finished (#669 and #715 are both that shape). Three traps, each
    measured rather than reasoned: (a) the reference check reads ONLY the text outside `<?php ... ?>`, because
    `help/disaster-recovery.php` still holds "section 4.4" TWICE **inside a comment recording the #583 fix** — a
    check reading the whole file would fail on the very comment documenting the repair; (b) replacing those blocks
    with a SPACE cried wolf three times, pulling a distant "runbook" into the 60-character window so that "Version
    4.4 of the portal", "About 4.4 million records" and the date "4.4.2026" were each reported as stale references
    — **when you remove a region to read what is left, replace it with a FENCE the window cannot read across, never
    with whitespace**; (c) **"a whole page OR a clean refusal" is satisfied completely by twenty refusals**, so
    check B99 counts how many a member really READ (20 of 20) and fails if it is none — the I7d lesson (#645): when
    a check exists to prove something ORDINARY still works, check the fixture is actually ordinary. All seven live
    runbook references are correct today, so the gate is a tripwire rather than a repair. And one fault of mine,
    caught by RUNNING it: the first version queried `tblRoutes.routePath`; the column is **`routeKey`** — check a
    name against the thing that uses it, not against what looks similar.

45. **EVERY DECISION THE OWNER MAKES LIVES IN `docs/decisions/` — ONE FILE EACH — AND ON ITS GITHUB ISSUE, AND THE
    REGISTER IS SEARCHED BEFORE ANY QUESTION IS ASKED (the owner, 4 November 2026 — D-027).** The folder is at the
    top of the repository, **outside `.claude/` and `.OpenAI/` deliberately**: a record inside one assistant's
    folder is invisible to every other tool unless it is copied, and copies are the fault being fixed. **This file
    carries POINTERS — a `D-NNN` number — never a restatement of a decision.**
    **Why:** whether `alpha` should refuse a change until the tests pass was put to the owner THREE times on
    3-4 November 2026 and answered three different ways (D-020, D-021, D-024). The second ask was defensible — a
    measured fact had unseated the first answer — and the third was the owner's own prompt. But three answers now
    exist for one question, and the owner's words for the cost are the reason this is a rule: it "delays
    development and release readiness but also risks convoluted/fragmented code". The structural cause was that
    decisions were recorded in FOUR places and none was complete — the project instruction file, several memory
    files, the handoff, and issue comments. **Scattered records read as no record, because there is nothing to
    search.**
    **A re-ask is legitimate only when a MEASURED fact makes the recorded answer unworkable**, and then the
    question must quote the decision, state the fact, and ask whether it still stands.
    **Never edit a decision file** — a decision taken again is a NEW file naming the one it supersedes.
    **Maintenance is part of the rule:** at every documentation sweep, check the register against the code and
    against this file and realign what has drifted. `tools/audit-checks/check_decisions_recorded.py` is a hard gate
    on the register's SHAPE and on this file pointing rather than restating; **it cannot tell whether anybody
    searched the register, nor whether a decision is true.**

46. **REPORT AT A COMMIT BOUNDARY, AND KEEP GOING IN THE SAME TURN (the owner, 4 November 2026 — D-045).**
    A commit is a place to SAY WHAT LANDED, not a place to stop. Commit, give a few lines on what landed and what
    is next, and **in the same turn make the next tool call**. "A clean working tree" and "everything is committed
    and pushed" are STATUS LINES, NOT CONCLUSIONS — a clean tree is the cheapest moment to start the next thing,
    because nothing is half-done. **Why it kept happening, named so it can be caught:** a commit boundary FEELS
    like a finish — tests green, something satisfying to report — so the report gets written as a closing statement
    instead of an aside, and the owner then has to nudge for work that was already decided. The only reasons to end
    a turn remain a question only the owner can answer (ask it and carry on with everything not blocked), or
    something genuinely destructive needing approval first. Sharpens rule D-009 (never wait for a nudge).

## Gating a feature is a CAPABILITY, never a hard-coded role (the owner, 3 November 2026 — D-017)

Set when the owner decided #727's scope: *"all remaining features pointable as
gated features, all standard roles' gates. with organisational global admin
having automatic access to everything, who can then re-assign later. **This
should also be the mechanism going forward for all new features**, giving
flexibility and security"*.

**So a new feature that needs gating DECLARES A CAPABILITY. It does not add an
`App::hasRole('some_role')` call.** `Portal\Core\Capabilities` is the one list,
and an organisation points a capability at the role or roles of its own choosing.

- **A capability maps to a SET of roles, ANY-OF.** Holding one listed role opens
  the door. All-of is a two-person rule, a different feature, and nobody has
  asked for it.
- **A capability is COARSE.** Group by what a person is trying to open, never one
  per call site: `asset_manager` alone accounted for 37 of the **61** literal
  gates on commit `b12ff44`, and all 37 are one capability. A page of 60
  switches is a page no customer can read, and 60 new ways to lock somebody out.
  *(This bullet said "37 of the 62" until the independent check measured it. 62
  is the FILE count, stated correctly two bullets below — one number doing two
  jobs, in the rule written to stop that.)*
- **An organisation's global administrator has automatic access to everything**
  and can re-assign later, so a capability declares
  `globalAdminFallsOpen = true`. **`erasure.review` is the deliberate exception
  and stays FALSE** (#724): a hosting provider's administrator must not be the
  person reading a member's own words, because the installation's administrator
  and the organisation's may be different parties. An entry that omits the
  property must fail loudly rather than pick a default.
- **An organisation that never opens the roles page sees NO difference.** Every
  capability ships with the role its gate had hard-coded. That is the only way
  this can be released — #499's "fail closed" was built first and locked members
  out.
- **An EMPTY set is allowed and is a decision, never an accident:** it needs a
  second confirmation carrying the capability's own sentence about what will
  happen, and "chosen and empty" is stored differently from "never chosen".
- **The installation may mark a capability not-re-pointable**
  (`organisationMayRepoint`, plus the installation-wide `capabilities.locked`).
  A layer that cannot be READ locks everything, not nothing.
- **A VARIABLE role key cannot be re-pointed, and there are only TWO of them.**
  This bullet said "roughly a quarter of the calls work their key out at run
  time — a workflow's assignee, a notification audience, a report filter", and
  **every part of that was wrong**, which matters because it is the sentence
  #739 would use to decide how much cannot be converted. Measured on commit
  `b12ff44` with PHP's tokeniser: **4 of 65** calls do not pass a literal, and
  **two of those four pass a class constant** (`ExpenseRules::EXPORT_ROLE`,
  `Giving::ROLE_KEY`), which is a fixed key written once rather than one worked
  out. Genuinely variable: **two**, about **3%** —
  `ReportRegistry::userPassesGates()`, which walks a report's own list of gate
  names, and `web/_apps/tours/api/active.php`, which walks a guided tour's
  comma-separated `forRoles` column. Both take the key from a row a customer
  filled in, so neither is a page gate. "A quarter" was 24 of 86, i.e. the
  comment-inclusive `grep` again, and **the three examples it named are not
  `App::hasRole()` call sites at all** — they are SQL joins against
  `tblUserRoles`. Never claim "every gate" while the two exist; list them with
  their reason instead.
- **Count the call sites, never quote a number — and this rule did not obey
  itself.** Run `php tools/role-gate-count.php`, which exists for this: PHP's
  tokeniser, comments and docblocks dropped, with the literal / run-time split
  and the comment-inclusive `grep` printed side by side so the gap is visible.
  **Never `grep`.** It counts every mention in a comment, and converting a gate
  LEAVES A COMMENT BEHIND naming the gate it replaced, so the grep figure does
  not even fall by one per conversion: on 3 November 2026
  `grep -rn "App::hasRole('asset_manager')"` returned 19 hits of which **zero
  were calls**. Three figures have stood in this repository's own files and
  every one was wrong — "66 call sites across 61 files", then "86, the real
  figure" (a grep), then "62 literal and 24 variable" (the file count and the
  grep). The honest tokeniser figure on `b12ff44` was 65 calls across 62 files;
  by `grep` the same commit answers 87. Cite the command, not the answer.

Phase two — converting the remaining standard-role gates, and an audit check
that fails when a new hard-coded gate appears — is **#739**. That check is the
part that stops the conversion being undone one feature at a time.

## A question put to the owner must define every term it uses

**A QUESTION PUT TO THE OWNER MUST DEFINE EVERY TERM IT USES.** Set by the owner
on 3 November 2026, after being asked whether "a loan's counterparty" was the
person the loan was about, and answering: *"your description isn't written
plainly enough. What exactly is a 'counterparty'?"*

The plain-English rule already covers replies, comments, commit messages and
anything an end user reads. This says it binds **questions** hardest of all, and
why: a question is the one place the owner has to make a decision from nothing
but my words. If a term in it is unexplained, the answer is either a guess or a
request to explain, and both waste a round.

So, in any question:

- **Use the word a person would use.** "The person or organisation something is
  lent to" beats "counterparty". Where a technical term is genuinely the
  clearest thing — a file name, a column name, a standard — use it and say in
  ordinary words what it is and why it matters.
- **Never let a column or table name carry the meaning.** `counterpartyUserID`
  may be what the code calls it; the question has to say what it HOLDS.
- **Say what each option would actually do to somebody**, not what it does to
  the code. "A member's telephone number stays on their own loan for ever" is
  the decision; "it remains in ACTOR_COLUMNS" is not.
- **If a term cannot be explained in a short phrase, the question is too big.**
  Split it, or explain the thing first and ask afterwards.

The test to apply before sending: *could somebody who has never seen this
codebase answer it?* If not, it is not ready.

## Throwaway containers: two measured facts that contradict the older rule

- **`docker run --rm` IS NOT ENOUGH ON ITS OWN, and `docker rm -f` IS NOT THE SAME AS `docker stop` —
  measured on 3 November 2026.** A container started with `--rm` and then killed with `docker rm -f`
  left an anonymous volume behind; the same image **stopped** with `docker stop` left nothing. This file
  treated the two as equivalent and they are not. So **stop a container rather than force-removing it**,
  and read the dangling count afterwards instead of assuming `--rm` did the work.
- **An agent cannot tidy its own leftover volume, and must not try.** `docker volume rm <id>` is refused
  by the permission classifier as "Irreversible Deletion" — refused three times on 3 November 2026, to
  two different agents and to the commissioning session, for a volume nothing referenced and which the
  session itself had created. **Report the exact id to the owner and leave it.** Do not look for a way
  round it, and never reach for `prune`: that is the thing the refusal exists to protect, and it would
  take other projects' volumes with it.

## A few more things worth knowing

- **Branches are meant to be promoted through four steps on the way to
  production, per the 7 September 2026 memory: `alpha` → `beta` → `rc` →
  `main`.** `main` is what customers actually run. **The third branch is
  called `rc`, not `release-candidate`, and it does not exist** — nothing in
  the repository creates it, and `.github/workflows/package.yml` says exactly
  that in its own header while describing what it would build if it did. So
  the four-step flow is the intended design; what runs today is `alpha` →
  `beta` → `main`. **One
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
