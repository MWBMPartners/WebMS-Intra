# WebMS Intra - Claude Code Instructions

<!-- Maintainer note. Claude Code strips block HTML comments before loading this file, so this costs
     a session nothing. Trimmed on 5 October 2026 at the owner's decision (Salem874), following two
     reviews of the instruction files: .claude-work/resume/instructions-review.md and
     .claude-work/resume/instructions-audit-2.md. What went where:
     - "Recent ships" -> .claude/history/recent-ships.md, word for word. CHANGELOG.md is the record
       from now on.
     - The counts table, directory layout, apps table and key constants ->
       .claude/history/claude-md-inventory-2026-10-04.md, word for word. FEATURES.md and the code
       hold those facts.
     - The database, ApiRouter and "two variables" traps -> .claude/rules/, which Claude Code loads
       only when it opens a file in the folder each one is about. One-line summaries stay below.
     - Rules that also live in ~/.claude/CLAUDE.md keep a short copy here, because this repository
       must work on machines without the owner's home folder. -->

Every rule in this file is a standing rule set by the owner (Salem874) unless it says otherwise.

## Project

Internal portal platform (PHP 8.5, backward-compatible with 8.4, Bootstrap 5.3.3) hosted on DreamHost shared hosting. No CLI, no Composer.

> ⚠️ **Database versions are in flux — read this before writing any SQL.**
> **MySQL 8.0 reached the end of its extended support in April 2026.**
> Everything here still assumes it. The automated database test covered only
> `mysql:8.0.36` until 25 September 2026; it is now built to run on MySQL
> 8.4.11 as well (not yet run on GitHub — it runs on pull requests and on
> updates to the release branches that change `web/_sql/`). **It still never
> upgrades a database built by an older release** — every phase starts from
> today's `full_schema.sql` — so faults that only show on older databases
> (#553, #554, #555) are invisible to it. MySQL 8.4 refuses a link that
> points at a non-unique key (ERROR 6125, #552);
> `tools/audit-checks/check_fk_references_unique_key.py` checks for that.
>
> Two things to be precise about, because loose wording has already caused
> confusion. First, **the version is now read, judged and acted on** — this
> changed on 10 September 2026 (#489). `Portal\Core\DbServer`
> (`web/_core/DbServer.php`) is the ONE place that decides whether a version is
> supported; the admin dashboard, the health page, the Server Information page
> and the installation wizard all call it, so they agree. The installer refuses
> to install onto a database too old to run the schema, and warns without
> blocking on anything else. **Do not write a second version check** — change
> the constants at the top of that file instead. Second, **MariaDB is not
> covered by any automated test here**, so its compatibility is unverified —
> which is not the same as saying it does not work.
>
> Moving to MySQL 9.7 / MariaDB 12.3 (with 8.4 / 11.4 as fallbacks) is tracked
> as **#475**. Until that lands, keep writing SQL to the MySQL 8.0 ∩ MariaDB
> convention below. It remains the sensible choice while the target is unsettled
> — but following a convention is not the same as proving compatibility.

- **Version:** 1.4.0 (on `main`; bump in `web/_core/version.php` — single source of truth)
- **Brand layer:** the product name is chosen at install (#296); presets and per-brand assets are set in `web/_core/brand-defaults.php` and read through `Site::productName()`.
- **Licence:** All Rights Reserved — MWBM Partners Ltd (t/a MWservices)
- **Repo:** github.com/MWBMPartners/WebMS-Intra
- **Server (an example of one customer's set-up, not a default):** portal.millrdsdacambridge.uk
- **Full brief:** `.claude/ProjectBrief_Chat.claude`
- **Living feature inventory:** [FEATURES.md](../FEATURES.md) (always check this first)
- **Chronological history:** [CHANGELOG.md](../CHANGELOG.md)
- **Dev-facing technical notes:** [DEV_NOTES.md](../DEV_NOTES.md)
- **Content translation is NOT reachable (#485) — never describe it as working.** The engine, its admin page and the member opt-in exist, but nothing a user can reach calls it. Interface translation (`I18n` / `t()`) is a separate system and works normally.
- **Calendar, Events and the Preaching Plan are ONE app ("Events").** `/calendar` covers viewing, listing and subscribing; the manage pages handle preaching-plan and worship event types and series.
- **Counts are not kept in this file** (apps, classes, tables, checks): they went stale within days. Count from the code; when any document disagrees with the code, the code is right. Migration numbers 168, 169 and 195 were never used, and nothing depends on the numbering being unbroken.
- **History** that used to sit here is in `.claude/history/` (moved 5 October 2026). `CHANGELOG.md` is the record from now on.

## Rules that load only with their folder

Three traps matter only in one part of the code, so they live in `.claude/rules/`, and Claude Code loads each one when it opens a matching file. Here they are in one line each, so that a plan made before any file is opened still sees them:

- **`.claude/rules/database.md`** (files under `web/_sql/`) — "Every database change goes in the install script too" and "SQL dialect trap": **every database change goes in BOTH `web/_sql/full_schema.sql` and a numbered migration, and the two must agree** (owner, 11 September 2026); migrations must be safe to run twice; the storage engine stays InnoDB; and the MySQL 8 dialect trap — no MariaDB-only `IF [NOT] EXISTS` on columns or indexes.
- **`.claude/rules/api-router.md`** (handlers under `web/_apps/**/api/`) — "ApiRouter routing trap": `api/*` addresses ignore `tblRoutes`; a handler is reachable only at `_apps/{app}/api/{action}.php`, and only once `api.{app}.{action}.enabled` is seeded.
- **`.claude/rules/app-pages.md`** (pages under `web/_apps/`) — "Two variables, and only two": a page inherits only `$mysqli` and `$SETTINGS` — nothing else, not even `$db`.

The quoted names are the old section headings, kept here because code comments and other documents still point at them by name.
Codex reads the same rules as rules 13, 14, 15 and 17 of `.OpenAI/CONTEXT.md`. Change both together.

## Plain English — applies to everything written

Write the way you would explain something to a capable colleague who does not work on this system. The customer asked for this on 2026-09-07 because jargon "can sometimes be confusing even for some technically proficient users/developers". It covers chat replies, code comments and file headers, commit messages, pull request and issue text, every `.md` file, the in-app help under `web/_apps/help/`, and everything an end user sees: labels, buttons, error messages, tooltips.

- Use ordinary words. When a technical term is genuinely needed — a file name, a function name, a standard such as WCAG or OpenAPI — use it, then say in ordinary words what it means and why it matters. No unexplained abbreviations, no impressive-sounding filler.
- Keep replies short. Lead with the answer or the outcome, then the detail someone needs to act on it. Keep caveats to a line. Use more words only where they make the meaning clearer — never to fill space, and never squeeze meaning into jargon to save it.
- Prefer short sentences. Explain the "why", not just the "what".
- This does not lower the standard of the work. Only the way it is explained changes.
- **When reporting on work done**, be direct about what is finished, what is not, what was not checked, and what went wrong. Say "I could not test this because there is no database on this machine" rather than implying it was verified.

## Codex review — what gets a second system's review, and who does it

*(Heading and "When to run it" reworded 16 September 2026. They used to say
"before it is committed" throughout. The requirement for a different
system's review has not changed; what changed is the owner's decision that
work is committed and pushed after an independent Claude check while Codex
is unavailable, rather than waiting for Codex — see "When to run it" below
and `.claude/HANDOFF.md`, decision A, 16 September.)*

**What gets a review round here.** Any change that could do real harm: code,
database changes, anything that deletes or overwrites data, touches money,
credentials or personal details, or changes a safety gate. Those are reviewed by a
different system — Codex — before they count as finished. **What does not:**
documentation of every kind (the instruction files, memory, the handoff and
progress notes included), renames and comment-only changes. The owner set the
narrowing on 5 October 2026 ("narrow exactly as machine-wide"), knowing it means
an instruction rewrite is committed without a review round. Until then every
change here was reviewed, notes included; the customer first asked for reviews
on 2026-09-10.

The point is a genuinely independent second opinion. Claude plans and builds;
Codex reviews. If Codex built something, Claude reviews it instead. Two
different systems rarely make the same mistake in the same place, so this
catches things one reviewer alone would wave through. It supports the project's
stated aim of getting things right first time.

**How to run it.** Codex is installed and signed in on the development machine. Name the
model and close stdin, or it refuses or sits waiting for typing (see below):

```bash
codex exec --skip-git-repo-check -c model="gpt-6-astra" "<what you want reviewed>" < /dev/null
```

- `codex exec` is the non-interactive mode: it prints its answer and exits.
- It runs read-only by default, which is exactly what a review needs.
- Give it the real change — a diff, or the paths of the files — and ask for
  specific things: is it correct, is it safe, would anything here fail on
  MySQL 8.0, would anything here break on shared hosting with no command line.

**TEMPORARY ARRANGEMENT, set by the owner on 20 September 2026 and MOVED on
24 September 2026 — read this before scheduling any review.** Reviews are NOT
being run package by package at the moment. One comprehensive Codex review of the
whole branch happens **at the END OF THE WHOLE QUEUE** — after #514, the follow-up
issues, #549 and the documentation sweep. **If more tasks are added to the queue,
the review moves to the very end again.** (It used to be "after the #514 build";
the owner moved it on 24 September when asked whether to review sooner.)

**Run Codex with the model named**, or it refuses: every command needs
`-c model="gpt-6-astra"`, and stdin closed so it does not wait for typing — for
example `codex review --uncommitted -c model="gpt-6-astra" < /dev/null`. A refusal
naming `gpt-6-sol` is that, not a lack of credit; a credit refusal names a reset
time. Its allowance is roughly one large review per reset.

**The cost of this, said plainly so nobody mistakes it for an oversight.** The
machine-wide rule says catch-up reviews should happen frequently rather than once
at the end, because the longer a stretch of unreviewed work runs, the harder it is
to unpick anything found wrong. Moving the review to the end makes that stretch as
long as it can be. The owner chose it knowing Codex's allowance is small; the
Claude-side independent check of every package, and the plain statement in every
commit message that Codex has not seen it, are what carry the work until then. It covers both the work Codex never saw while it was out of
usage from 13 to 20 September (some of which the owner committed to GitHub so it
could not be lost) and everything built since. **No pull request is raised until
that review is done** and every finding is either fixed and re-reviewed clean, or
written up as its own issue the owner has seen and agreed to leave for later.
Once that pull request is raised, the normal arrangement below returns: each
finished piece goes to Codex as it lands.

Why: Codex ran out of usage part way through the catch-up on 20 September, after
four of five areas. Spending what is left on small per-package reviews would
leave nothing for the review that judges the whole body of work as one piece.

Two things did NOT change. Every code package still gets an independent check
before it is committed, by a fresh agent that did not build it — named in the
commit message as standing in for Codex, because the machine-wide rule prefers
the other service and treats a helper as a stand-in. And every commit message
still says plainly that Codex has not reviewed it yet, so silence never implies
a review happened.

**One exception, the owner's decision of 5 October 2026: the final whole-of-part-8
check of #514 goes to Codex**, not to a fresh Claude agent. Part 8 is heavy on
security and privacy, and that check is a single review — exactly where Codex's
small allowance is worth most. The end-of-queue whole-branch review still
happens, possibly after waiting for a reset.

**When to run it.** After the work is written and the mechanical checks pass
(`php -l`, every script in `tools/audit-checks/`, and the end-to-end
migration harness where the database is involved). When Codex is available,
review before committing remains the normal order. When Codex is not
available, the owner's decision of 16 September 2026 applies instead:
commit and push once a different Claude agent — one that did not write the
code — has independently checked the work, with the commit message stating
plainly that Codex has not reviewed it yet; Codex reviews the commit as
soon as it is available again, and any fix it finds lands as a new,
separate commit. Either way, review by a different system is still
required for every change that gets a review round (see the top of this
section) before it counts as reviewed — only the timing relative to the commit
has changed.

**How to treat the result.** As a second opinion, not a verdict. Check each
point against the code before acting on it. Codex will sometimes be wrong;
saying so plainly, with the evidence, is the right response. Record in the
commit message that Codex reviewed the change and what came of it.

**What counts as a real problem, and when the loop stops.** A review round — by
Codex, or by an independent Claude checker — is NOT CLEAN only for a real problem:
wrong behaviour, a security or privacy gap, possible data loss, a message or comment
that is untrue, or a test that passes on broken code. The reviewer still reports
everything it sees, but hardening ideas, wording preferences and polish go under
"Found" (or into an issue) and do not start another round. Stop when a round finds
no real problem. The machine-wide file's narrowing of WHAT gets reviewed applies
here too (owner, 5 October 2026); this file adds only the timing above and the
part 8 exception.

**Why this sits alongside the other checks, not instead of them.** The audit
scripts and the migration harness catch mechanical faults — a mistyped
column, SQL that only works on MariaDB, a route pointing at a missing file.
They cannot judge whether the design is right or whether a change has an
unintended consequence. That is what the second reviewer is for.

## Deep analysis: sequential, one run at a time

Deep analysis and deep planning use **sequential agents, never parallel**,
whichever model is doing the thinking. Confirmed by the owner on
10 September 2026, and unchanged by the move to Opus on 23 September 2026.

Two parts, and the second is the one that gets missed:

1. Within a run, each agent waits for the previous one and builds on what it
   found. A second opinion formed without seeing the first is worth much less.
2. **Never have two analysis runs going at once.** Ordering the agents correctly
   inside each run and then starting two runs together defeats the purpose. That
   exact mistake was made and corrected on 10 September 2026.
   **This covers checking too (owner, 20 September 2026): nothing new starts —
   not even planning the next package — while a finished one is being
   independently checked.** One package is in flight at a time, from planning
   through checking to commit. Asked directly whether the next package could be
   planned during a check with an hour left to run, the owner said no; the idle
   time is deliberate. Two reasons it holds up: the next plan would be read
   against a working tree still holding the current package's uncommitted
   changes, which may shift under it; and the checker verifies exactly which
   files have changed, so anything else editing the tree muddies that test.

Stopping a run to keep the order is cheap: relaunch with `resumeFromRunId` and
the script path, and every agent that already finished returns its cached answer
immediately.

**Fact-gathering may run in parallel; judgements may not.** Reading code, checking
a long list of issues, web research — work that only finds out what is already
true — can be split across several agents at once, because none of them makes a
judgement the others need to see first. Every analysis or planning judgement still
happens one agent at a time, each seeing what the one before it established.
(Decided 21 September 2026: seven gatherers took an hour where one at a time would
have taken most of a day.)

**Keep helper agents few.** Start one only for a large piece of work that is
genuinely separate — a wide search across many files, a build, or the independent
check this project requires. Do not start one for work you can finish yourself in a
handful of steps, and do not start one to re-check your own work: the independent
checker is the one exception, and it must be a fresh agent that did not build the
thing. If one helper can do it, use one. No more than 6 at once. On the owner's Mac
that ceiling is also enforced by `CLAUDE_CODE_MAX_CONCURRENT_SUBAGENTS=6` in
`~/.claude/settings.json` (owner, 5 October 2026): a seventh agent is REFUSED, not
queued, so a workflow must be shaped to run at most six at a time.

**Deep analysis and deep planning run on Opus**, one agent at a time, in
sequence. Implementation stays on Sonnet or Haiku, whichever fits — or Opus when
the work is genuinely complex. **Verification is never done by a weaker model
than the build.**

**This changed on 23 September 2026.** Planning used to go to Fable, falling
back to Opus. The owner's reason: the newest Opus is cheaper than Fable and at
least as good at this, so there is nothing left to fall back from. Written down
because an older note will name Fable as the planner, and somebody reading it
should know it was replaced deliberately rather than forgotten.

**The rule is about the tier, not the name.** "The strongest reasoning
available, one agent at a time" is the instruction; which model fills that tier
will change again. Do not read a model name here as permanent.

**Give planning the strongest model**, because a plan that is right saves every
token a wrong plan would have spent on rework. How much the model thinks is set by
the effort setting (`high` since 5 October 2026, in `~/.claude/settings.json`), not
by wording in this file.

**Use the planning and orchestration features the tool provides** — in Claude
Code, workflows and agents — where they fit. The owner has opted in to them.

## When one system runs out: handing over, and handing back (all projects)

The full rule is in `~/.claude/CLAUDE.md`; this short form travels with the repository.

- If whatever is doing the work becomes unavailable — out of credit, rate limited, refusing, or down, and a retry has already failed once — hand the work to another suitable system or agent rather than stopping. Not merely because something is slow.
- **The work must survive the move.** Hand over only with what is being attempted, what is established, which files matter, what was tried and rejected, and how the result will be checked. If that cannot be carried across, do not hand over: say plainly that the work is blocked, and why.
- That is why the handoff (`.claude/HANDOFF.md` — there is only one) is kept current **as the work happens**: after each step, before anything long-running starts, and the moment something is learned that would change how somebody continues.
- Go back to the usual system at the next natural break, and try it first on every new run even if it failed last time. When it comes back, run a full review of everything done while it was away, as one body of work.
- **A review must never change hands silently.** If the usual reviewer cannot run: say so in the report and the commit message; get what independence exists (a different model, or a fresh agent that did not build it) and name it; and treat the change as not yet fully reviewed until the catch-up review.
- Record every fallback in the commit message, the handoff and the progress report.

This is reasonably safe only because every change is checked by a different system from the one that made it; if that review is skipped, the case for handing over falls with it. Project trap: a Codex refusal that names `gpt-6-sol` is the model-name problem described under "Codex review", not a lack of credit — read the message before recording Codex as unavailable.

## Never put ".php" in a web address (all projects)

Links, form targets, redirects and background requests use the **clean address** the portal registers, never the file that answers it.

    /expenses/submit/save          yes
    /expenses/submit/save.php      no

It tells a stranger what the site is built with, and **in this portal such an address does not work at all**: `.htaccess` answers 404 for every address ending in `.php` except the three pages that genuinely live in the web root (`/index.php`, `/error.php`, `/api-docs/index.php`). On 11 September 2026 this rule uncovered three live Expenses forms (submit, approve and treasury) posting to `.php` addresses — pressing Save would have shown "page not found" — and the database upgrade page redirecting to a 404 whenever a form token expired. Nothing else caught it, because every file existed and every address was registered; the mistake was in what the pages pointed AT. `tools/audit-checks/check_no_php_in_urls.py` checks this on every pull request.

## Nothing may look unfinished, careless or AI-made (this repo and every project on the device)

**Set by the owner on 4 October 2026**, for this repository and, through `~/.claude/CLAUDE.md` ("Nothing may look unfinished,
careless or AI-made"), for every other project on the owner's machine. That machine-wide section holds the full eight-point checklist;
this section records what is specific to WebMS-Intra.

- **The checklist covers every page, including pages behind a sign-in** — the owner's choice, made on 4 October 2026 after being told
  that a sitemap of signed-in pages would list their addresses publicly. Title, meta description, favicon, Open Graph tags and image,
  canonical address and social preview on every page; a sitemap and `robots.txt` that were thought about.
- **"No default or example address" here means #500:** no customer's real address (for example `portal.millrdsdacambridge.uk`) built
  into code, templates, workflows or the API description — the existing "No web address is ever built in" rule below.
- **When it is checked: automatically, before every push**, by a git pre-push hook that runs this repository's fast checks and refuses
  the push on a problem; the browser parts (layouts at many screen widths, visual consistency, how pages actually look) run as a full
  audit before every release. **The pre-push check does not exist yet** — building it is part of the audit package queued straight
  after #514 part 8 is committed (#569). It will be one check per repository, with a small installer that turns
  the hook on — git never copies hooks between clones, so each clone runs it once (the FileMoCo shape; owner,
  5 October 2026; not a global hooks folder). Until then, run the checklist by hand before pushing a change that
  touches pages, and say so; never claim the automatic check ran.
- **The first full audit and its fixes** are that same package, after part 8 (owner, 4 October 2026: "After part 8 only").

## No web address is ever built in — WebMS-Intra is a product

**WebMS-Intra is used by many customers. No web address or domain name may be hard-coded** in code, GitHub
workflows, settings seeds, templates, emails or the API specification. The owner set this on 13 September 2026:
"Any domains should be configurable during the installation process (for real customers)."

- **Our own addresses are only examples of one customer's set-up.** That covers `portal.millrdsdacambridge.uk` in
  this file, in DEV_NOTES and in the plans. Where a document shows one, label it as an example.
- **Addresses come from the installer, a setting, or a GitHub secret or variable.** A missing address gives a clear
  message or a skipped step with a warning, never a quiet fallback to ours.
- **Hosting is configurable too.** We deploy every channel over SFTP to one DreamHost user. Other customers may use
  other hosts, or separate accounts per channel. Nothing may assume the channels share a user, a server or a host.
- **Customers will install from a downloadable zip package** (#499), so installing and upgrading must work for
  someone with only a hosting panel.

Before committing, check: `grep -rn millrdsdacambridge web .github tools` should find nothing except clearly
labelled examples. Known offender to remove: the live health-check address in `.github/workflows/deploy.yml`.
Tracked in #500.

## Times always respect the time zone, including clock changes

**Set by the owner on 23 September 2026.** Every time this portal stores, works
out, compares or shows must respect the time zone it belongs to, and must stay
correct across the two nights a year when the clocks change.

This is not a nicety. The portal is a diary: services, lessons, rotas, room
bookings, reminders and livestreams. An hour wrong is a congregation arriving to
a locked building, a volunteer missing a shift, or a reminder that goes out after
the event it was reminding people about.

**Where the time zone comes from.** A site has one (`tblSites.timezone`, default
`UTC`), and an event may have its own (`tblEvents.eventTimezone`, default
`Europe/London`). Neither may be assumed: a customer in another country, or a
church running an online service for a different region, is the ordinary case,
not the exotic one. **Never hard-code a zone**, for the same reason no web
address is hard-coded — this is a product, not one customer's installation.

**The three mistakes this project has actually made**, all found by checking
rather than by anything failing:

1. **Turning a wall-clock time into a moment.** A venue's booking said "every
   Tuesday, 19:00 to 21:00". Comparing those as moments broke on the clock-change
   weekend. Fixed in #435; recorded in the note that wall-clock times compare as
   text. The general rule: a time written on a form is a time on a clock, not a
   point in history, until something gives it a date AND a zone.
2. **Using a length taken from `diff()`.** PHP applies an interval that came out
   of `DateTimeImmutable::diff()` as a wall-clock offset, and an interval you
   built yourself as an exact one — the same numbers, two different answers.
   An overnight event read from a calendar file ended an hour late in October and
   an hour early in March. Found on 23 September 2026 in the calendar reader, and
   five rounds of checking walked past it first, because it is correctness rather
   than a crash.
3. **Assuming a day is 24 hours.** It is 23 or 25 on those two nights. Anything
   that adds 86,400 seconds to get "tomorrow", or divides a span by 86,400 to
   count days, is wrong twice a year.

**What to do instead**

- Store a moment in UTC; store a wall-clock time as text with the zone beside it.
  Keep the two kinds apart, and say in the column comment which one it is.
- Add days, weeks and months with `DateTimeImmutable::modify()` or an interval
  you construct, so they follow the clock. Add hours, minutes and seconds as
  seconds, so they stay exact. **RFC 5545 §3.3.6 calls these "nominal" and
  "exact" durations, and the distinction is the whole point** — "the same time
  next Tuesday" and "in exactly five hours" are different requests.
- Convert to the display zone as late as possible, and only once.
- **Test both change nights, in both directions**, and test them in a zone that
  actually changes. A test written in December passes in London whatever the code
  does, because London is on UTC that month — which is exactly how mistake 2
  hid for so long.

**When something cannot be right, say so rather than guessing.** A repeating
event that spans the change hour is genuinely ambiguous between calendar
programs. The calendar reader follows RFC 5545 §3.8.5.3 — every instance keeps
the same exact length — **because the owner chose the standard on 23 September
2026** when the alternative was matching whatever Google happens to show. Where a
judgement like that is made, write it beside the code and put a check on it, so
nobody quietly reverses it later.

## Comment everything, in every language (all projects)

Detailed comments in the code you write or change — HTML, PHP, CSS, JavaScript, XML, JSON and SQL. Explain the WHY, not the what. Record what was tried and rejected ("this used to do X, which was wrong because Y"): it stops the next person reintroducing the fault, or tidying away something load-bearing. Say what code CANNOT do where that is not obvious; an overstated guarantee is worse than none. **JSON has no comments** — never put `//` in a `.json` file; put the explanation in its schema (`description` on every property, `$comment` for maintainers). File headers: see "Code style".

## A schema for every JSON and XML format (all projects)

Where this project produces or consumes JSON or XML, a JSON Schema file (`*.schema.json`) or an XSD lives beside it, with a `description` on every property — the schema is the documentation as well as the validator. Wire the validation into a check so it actually runs; a schema nothing executes is a document, not a check.

## The documentation sweep is a standing task

After each real body of work, and before its pull request is opened, update the
documentation thoroughly. Not a skim — every one of these:

- **Every `.md` file in the repository** that the work touched or made untrue:
  `README.md`, `CHANGELOG.md`, `FEATURES.md` (the living feature inventory —
  check it first, it is the one people read), `DEV_NOTES.md`, and any others.
- **The in-app help and guides** under `web/_apps/help/` — these are what an end
  user reads, so they matter more than the developer notes, not less.
- **The memory and context files for every assistant that works on this
  project**: `.claude/` (including this file and the handoff) and its
  `.OpenAI/` mirror. Both, in the same sitting, or they drift apart.
- **The API description.** This project offers a REST API, so `_core/api-spec.json`
  and anything serving it must describe what the code actually does.

**Swagger UI is already here and already suits shared hosting.**
`web/public_html/api-docs/index.php` serves a browsable view of the API
description, loading the Swagger UI files from a content delivery network and
falling back to a copy in `/assets/vendor/swagger-ui/` when there is no internet.
**It needs no Docker and no command line**, which is the condition this project
builds everything to. So do not add a second viewer — keep this one correct.

**Thorough does not mean long.** Cover the substance, with no filler sections, repeated
summaries or boilerplate; where something no longer applies, delete it rather than explaining
that it no longer applies. A report the owner reads opens with its verdict and fits on one
screen, with the evidence below it or in a separate file. A handoff entry says what changed,
what was learned and what is next, in about 30 lines. When the handoff passes about 1,000
lines, older entries move into an archive file beside it, marked as history — for
`.claude/HANDOFF.md` the owner decided on 5 October 2026 that this happens after #514 part 8
is committed.

## Order and bundle the work sensibly

The order of tasks in a brief is a suggestion. Reorder and combine where that is more efficient — one documentation pass after three related fixes, one review round over two small changes — **as long as nothing is dropped and the progress table shows what was bundled**. Efficiency that hides work is not efficiency.

## Set a watchdog whenever you wait for something to finish (this repo and every repo on the device)

Set by the owner on 24 September 2026, after a background agent's "finished" notice was lost and the queue sat idle until the owner asked "are we stuck?". Whenever you start something that finishes later without you — a background agent, a long test run, the migration harness, a review, a deploy — start a watchdog beside it as its own background command, usually:

```bash
tools/watchdog.sh quiet .claude-work/resume/<that agent's report>.md 900 14400
```

This repository commits its own copy, `tools/watchdog.sh`, so the rule works on any machine; the owner's Mac also has `~/.claude/bin/watchdog.sh`, and the two copies' logic is kept identical. Other modes: `exists <file>`, `contains <file> <text>`, `pid <pid>`; every mode has a hard deadline (four hours by default). When it fires, **look** — it cannot tell "finished" from "stalled" — then act, or start it again if the work is still going. **When a step finishes, start the next in the same turn.**

## Throwaway databases and containers are removed, data included

Set by the owner on 24 September 2026. Every agent here starts MySQL containers to test against, and **a plain `docker rm` leaves the container's data behind** in a separate volume: on 24 September this machine held 415 of them, taking 101 GB, though every agent had reported its clean-up as done.

- Start test containers with `docker run --rm`, or remove them with `docker rm -v`.
- Name each after its work (`p514p6chk-mysql`), so it can be identified later.
- **Report the count of left-behind volumes before and after** (`docker volume ls -q -f dangling=true | wc -l`), and `docker ps -a`. Your own work must add none. Remove only your own containers and volumes, by name.
- Never remove another project's container, a running one, or a NAMED volume without asking — `g2ml-mysql` and `wrapper-v2` belong to other projects. Never run `docker volume prune` or `docker system prune`: they remove every unused unnamed volume, other projects' included. Claude Code's settings on the owner's Mac refuse both (5 October 2026); Codex is not bound by those settings.

## Code style (house rules that differ from the usual defaults)

- `declare(strict_types=1)` in every PHP file
- **Full IF notation:** `if ($x === true)` not `if ($x)`
- **Platform-neutral paths:** `DIRECTORY_SEPARATOR`, `dirname()`, PHP constants
- **Emoji-annotated comments** for major code sections
- **No `<table>` tags** for data display -- use `portal-data-list` component
- **MySQLi prepared statements only** -- never interpolate user input
- `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` for all output
- Detailed inline comments with reference links where applicable
- File header comments must include: file path, description, package, author, copyright (All Rights Reserved), version

## Web-root shadowing trap (check whenever you add an address or a file)

**A real file or folder in `web/public_html/` silently beats any seeded address
of the same name.** `web/public_html/.htaccess` contains
`RewriteCond %{REQUEST_FILENAME} !-d` (and the same for `-f`), which means the
web server answers for anything that really exists on disk and never hands the
request to the portal. That rule is correct and necessary — it is what serves
stylesheets and images directly.

**It fails silently and invisibly.** No portal code runs, so nothing is written
to the error log. The visitor gets a bare folder listing or a flat refusal, and
there is nothing anywhere to explain it.

This has already bitten twice, found 10 September 2026:

- A folder `web/public_html/admin/` made the whole **Admin area** unreachable
  (#483). Fixed by moving its three files to `web/_apps/`, which needed no
  address change because `Router::dispatch` looks under `PORTAL_APPS` first and
  only falls back to the web root.
- A folder `web/public_html/widget/` hid a **public, sign-in-free** address
  (#478). That one was the dangerous shape: the address was unreachable, so
  nobody had noticed that the page behind it had no access check AND returned
  internal events. Removing the folder as a "tidy-up" would have published it.
  The address was deleted instead (migration 189); the folder holds
  `countdown.js`, which other people's websites embed, so it cannot move.

**Deleting a folder from the web root that hides an address needs the owner's explicit approval first** (owner, 5 October 2026). The danger is invisible: the folder looks like clutter, and removing it silently publishes whatever page sat behind it. Ask in one line, naming the address it would expose and what the page behind it checks.

**Two collisions remain and both are deliberate:** `assets` has its own
`RewriteRule ^assets/?$ index.php` ahead of the folder rule, and `api-docs` is
meant to be served directly by the web server.

**There is now an automatic check for this** — `check_webroot_shadowing.py`,
wired into the pull-request checks. It compares every seeded address against
what really exists in the web root and reports any that the portal will never
see. Run it directly with:

```bash
python3 tools/audit-checks/check_webroot_shadowing.py
```

Note it compares the WHOLE address, because that is what the web server does.
`/admin` is hidden by a folder called `admin`, but `/admin/activity` is not —
there is no such folder, so that request gets through perfectly well even while
`/admin` is broken. The first draft compared only the first segment and reported
a dozen addresses that were entirely fine; a check that cries wolf gets switched
off, and then it catches nothing.

## Name things by the right identifier (the shape behind several bugs)

Three separate faults on 10 September 2026 were the same mistake: something was
named by the wrong kind of identifier, and nothing caught it.

- `Maintenance.php`'s allow list is compared against the **address** a visitor
  typed. It contained `auth/login`, which is a **file path**. The sign-in page's
  address is `login`. So administrators were locked out during every upgrade,
  and the holding page's own sign-in link pointed at the same non-address.
- A search for `tblActivityLog` found nothing, because the table is
  `tblActivityLogs`. "Nothing found" reads exactly like "nothing to fix".

**When checking whether a name is right, check it against the thing that
actually uses it** — seeded addresses against `full_schema.sql`, table names
against the schema — not against the folder layout, which merely looks similar.

## Working with the owner (set 16 September 2026, added to since)

The sections above cover plain English, reviews, planning, the handoff and the documentation sweep. This is the rest of how the owner wants work run.

- **GIRFT — Get It Right First Time.** Spend tokens efficiently, never at the cost of correctness. Verify before asserting, and say plainly what was not verified.
- **After each piece of work:** 1. commit and push to the single working branch that will later be merged into `alpha` (no extra pull requests, no stacking); 2. update the related GitHub issue(s), one by one; 3. update the Claude memory and `.claude/`; 4. update `.OpenAI/` in the same sitting, or the two drift apart; 5. update the handoff.
- **Never wait for a nudge** (owner, 24 September 2026): "continue autonomously, don't wait for me to nudge or give the ok." When a step finishes, start the next in the same turn, with a watchdog beside anything you wait on.
- **Questions up front.** Work through the whole queue. Raise every decision only the owner can make at the START, in one numbered block — what is needed, why, the recommended answer, and what carries on meanwhile — then continue with everything not blocked. A question never stops the queue. A decision the owner has already taken is final; do not reopen it.
- **When NOT to stop:** stop and ask only when you cannot continue without an answer, or before anything destructive — deleting data, force-pushing, or changing anything outside this repository. Everything else, carry on and report afterwards.
- **Hold the scope.** Deliver what was asked, at the scope intended. Make routine judgement calls yourself. If the request looks mistaken, or a better way exists, say so in one sentence and carry on with the task as asked — do not quietly narrow, widen or change it. Anything found outside the task goes under "Found", or into an issue; it is not built unless the owner says so.
- **Keeping the owner posted.** Before the first step of a task, say in one sentence what you are about to do. While working, give a short update only when you find something important, change direction, or finish a piece of work — always in the same message as your next action, never as a place to stop. For a queue, the update includes the task table, at least after each finished unit and whenever the queue changes: columns #, Task, Issue, Status, Notes; status words Queued · In progress · In review · Blocked (say on what) · Done (say the commit) · Dropped (say why).
- **End a long run with three headings**, in this order: **Blocked on me** (what needs the owner and why, one line each — questions that came up part way through go here too); **Changed** (what landed, with the commits); **Found** (anything discovered and not done, each with an issue number). Write "nothing" under a heading with nothing in it.
- **Narrow checks, then move on** (owner, 28 September 2026), for work built in chunks and committed once: after a chunk's first full check, each later round checks only the latest fix round's changes, still by a new agent; one fresh whole-part check happens once, just before the single commit; each round re-runs its own new planted faults plus a sample of earlier ones, and the full set runs once, before that commit. If a chunk goes past three check rounds, say so plainly in the next report, put the ways to speed up under "Blocked on me", and carry on meanwhile. If the same item fails two rounds running, consider simplifying the design before refining it again. This does not relax the final check before a commit.
- **Plugins:** the dev-team plugins may be used for any of this, including suggesting fixes and features and routing reviews to a different AI system.

## GitHub Labels

- `type:` -- feature, enhancement, bug, security, docs, infrastructure, refactor
- `priority:` -- critical, high, medium, low
- `scope:` (blue) -- core, admin, auth, ui, i18n (cross-cutting concerns)
- `app:` (salmon) -- calendar, attendance, expenses, admin, dashboard, help, settings, prayer-requests
- `phase:` (purple) -- 3 through 13
- `status:` -- blocked, in-progress, review

## Standing Instructions (per ProjectBrief)

When making changes:

1. Create a GitHub Issue with description, scope, and acceptance criteria
2. Run a real check that exercises the change: the self-test, the scripts in
   `tools/audit-checks/`, and the migration test when the database is touched; also
   `php -l` on every changed PHP file. Fix every problem they report in what you
   changed. If a real check cannot run, say which one and why.
3. Update CHANGELOG.md, **FEATURES.md**, DEV_NOTES.md, README.md as appropriate
4. Update `.claude/` memory and context
5. Add the issue to the project board if there is one; say so if that fails
6. Commit and push to the single working branch after each task; do not
   open extra pull requests *(updated 16 September 2026 to match the
   owner's decision to commit and push finished work right away — see
   "Codex review" above and `.claude/HANDOFF.md`, decision A. This step
   used to read "COMMIT changes (DO NOT PUSH unless the user explicitly
   asks for a PR)".)*
7. Close the GitHub issue once the work has merged, with the commit or pull request reference

### Monitor and fix the pull-request security checks (always applies)

On EVERY PR you touch, actively monitor GitHub's own automated checks — the
`pr-security.yml` "PR Security Checks" bot comment (route-target-missing,
MariaDB-only DDL, migration idempotency, SQL column drift, schema/seed parity,
etc.), CodeQL, Psalm, static-security, actionlint, and the migration harness —
and **fix any real issue each surfaces**, not only the hard PHP-lint gate. These
checks are non-blocking heuristics but a flagged item is treated as actionable:
resolve it correctly (e.g. a route pointing at a missing handler → build the
handler or remove the route + add the cleanup migration), or, only if it is a
genuine false positive, record why in the PR thread. Re-check after each push
until the security comment is clean. This applies regardless of session.

## Git Notes

- macOS case-insensitive: use two-step rename for case changes
- Never commit: `_auth_keys/`, `_uploads/`, `_backups/`, `_libraries/`, `.env`, `*.key`
- Deploy workflow syncs `web/` only, excluding server-managed dirs
- Shared dirs (`_core/`, `_vendor/`, `_sql/`, `_includes/`, `_functions/`, `_libraries/`) mirror with `--delete` — manual server-side edits to these dirs vanish on the next deploy (see DEV_NOTES.md → Troubleshooting)
- Branch-based deploy (one customer's set-up — see `DEV_NOTES.md` for the full table): `alpha` → `public_html_dev/`, `beta` → `public_html_beta/`, `main` → `public_html/`.
- `web/public_html/` is the only web root, and the web server answers directly for just three PHP addresses: `/index.php`, `/error.php` and `/api-docs/index.php`. Every app page lives under `web/_apps/`, outside the web root (#159), and is reached only through the router.
- Never force-push, hard-reset or change a remote without an explicit instruction, and never push straight to a release or protected branch. Claude Code's settings on the owner's Mac refuse force-push and hard reset outright (5 October 2026), but the written rule still stands: those settings cannot catch every form of the command, and they do not bind Codex.

Keep replies and reports reasonably short.
