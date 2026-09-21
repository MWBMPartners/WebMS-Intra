# WebMS-Intra — lessons learned, for Codex and other OpenAI-based agents

This mirrors the lessons kept in Claude Code's own memory for this project,
**as of 16 September 2026**. It leaves out anything that is purely about how
Claude Code's own tools behave and would mean nothing here; a few entries
that started as a Claude-specific note have been reworded to state the
general lesson instead. It contains no secrets, credentials or personal
data. Where a real customer web address appears below, it is clearly
labelled as an example of one customer's set-up, never a default — see
"Nothing hard-coded" in `.OpenAI/CONTEXT.md`.

Update this file, the same way `.claude/`'s own memory is updated, whenever
a piece of work turns up a real trap or teaches a real lesson — not just at
the end of a session.

---

## What the product is and how it is built

**The project, in one paragraph.** WebMS-Intra is an internal portal for
churches and similar organisations, sold to many separate customers, run on
ordinary shared web hosting with no command line, no Composer, and no build
step on the server. Everything proposed has to work as plain PHP files
copied onto the server, and production runs some version of MySQL 8, so
MariaDB-only SQL syntax is a hard error there. Anything pulled in from a
public content-delivery network (a CDN — a shared server elsewhere on the
internet used for common scripts and stylesheets) at page-load time needs a
local copy it can fall back to, because some customers' own networks block
those servers outright. Full detail in `.OpenAI/CONTEXT.md`.

**Branches are meant to move through four steps on the way to production —
per the 7 September 2026 memory: `alpha` → `beta` → `release-candidate` →
`main`, where `main` is what customers actually run — and one working
branch at a time, judged by contents not commit titles.** No
`release-candidate` branch exists on the remote today (checked 16 September
2026); treat the four-step flow as the intended design, to be confirmed
with the owner, not as already in place. All new work happens on a single
branch created from `alpha`; only one pull request is open against `alpha` at once, to avoid
two pull requests racing to merge and conflicting. When several branches
are created together just to split up one piece of work, treat them as
scaffolding only — delete them the same day their combined output has been
gathered and merged into the real branch, so the branch list does not fill
up with clutter. Several branches here have looked like unfinished feature
work in their commit messages while actually being fully superseded —
always compare real file contents against the target branch before
trusting a branch's history.

**The building and reviewing method.** Deep analysis and planning are done
by sequential agents, one at a time, each building on the one before —
never two analysis runs going at once. Building the actual code uses a
lighter, cheaper model unless the work is genuinely complex. Every change is
reviewed by a different AI system from the one that built it — in practice,
today, Claude plans and builds, and Codex reviews, or the reverse when
Codex is the one that built something. After every finished piece of work:
commit and push, update the related GitHub issue(s), update both `.claude/`
and `.OpenAI/`, and update the handoff document (`.claude/HANDOFF.md`).

**How committing and reviewing fit together (settled 16 September 2026).**
Commit-and-push and cross-system-review-before-finished sound like they
pull in opposite directions — do not wait for one piece before committing
the next, but also do not call anything finished until a different system
has reviewed it. The owner resolved this, but only for when the other system is
unavailable: **when Codex is available, review before committing remains
the normal order — nothing below changes that.** When Codex is NOT
available, commit and push each piece as soon as its INDEPENDENT check
passes instead — a separate Claude agent that did NOT write the code, run
after the builder's own mechanical checks already pass (`php -l`, the
`tools/audit-checks/` scripts, and the migration harness where relevant —
see `.OpenAI/CONTEXT.md` rule 2). This is a stricter test than the
builder's own checks alone. The commit message must say so plainly when
the cross-system review (the Claude/Codex review in rule 2) has not
happened yet. The other system reviews those commits afterwards, at the
next opportunity, and any fix it finds lands as its own new commit. Either
way, review by a different system is still required for every change
before it counts as reviewed — only the timing relative to the commit
changes.
**What this means for reading this repository's history: which commits
Codex has actually reviewed is recorded in `.claude/HANDOFF.md`, never
inferred from the commit message.** A commit that says nothing about
review is NOT necessarily reviewed — commit `110e47d`, the owner's own
precautionary backup from 15 September 2026, says nothing about review
either way and is genuinely not reviewed; the handoff records that
everything from that commit onward is still waiting for the Codex
catch-up review. Always check the handoff's own record before assuming a
commit is, or is not, reviewed.

## Codex-specific things worth knowing

**A Codex run that hit its usage limit still reports success.** When Codex
exhausts its own separate usage limit (or hits "model at capacity"), `codex
exec` can still exit with status 0, and the output file can still contain
the word `codex` (because it prints that before every step) — so a shallow
check for "did it run" or "does the word codex appear" can call a review
"complete" when nothing was actually reviewed. **Why it matters:** the whole
cross-system-review rule depends on the review genuinely happening; an
unnoticed failure here looks identical to a clean pass. **How to apply:** a
review only counts as real when ALL of these hold: there is no usage-limit
or "at capacity" text near the end; no trailing `ERROR:` line; a line
reporting how many tokens were used is actually present; the final block is
a genuine new answer rather than an old file being re-read; and the answer
is at least 300 characters long and ends with an explicit CLEAN / NOT CLEAN
verdict — a short answer, or one with no verdict, does not count. When
queuing several reviews, run them one at a time so a limit only loses the
review in progress, not the whole batch. A working script that already
applies every one of these checks automatically — `.claude-work/codex-
queue.sh` — is described in the note on `.claude-work/` below; use it
rather than re-implementing these checks by hand.

**A scheduled background job tied to one agent's session does not survive
past that session ending**, even if it was meant to fire later (for example,
a review queue meant to start once a usage limit resets). Nothing on screen
signals this at the time — it just silently never runs. **How to apply:**
before assuming a previously-scheduled job will still fire, check running
processes for it; if a task is scheduled to run later, write the exact
manual command and the intended time into the handoff, so it can be run by
hand if the session that scheduled it is gone.

## Real bugs and traps already found in this codebase

**An administrator gate that asks who you are, but never what you may touch.**
Every page in this portal that changed an account asked one question: is the
person signed in an administrator of the organisation currently open? None asked
whether the ACCOUNT being changed was one they were entitled to change. So an
administrator of one organisation could change any account in the whole
installation: set a new password on a global administrator's account and sign in
as them, change an email address (which redirects a password reset), switch an
account off, or hand out the older portal-wide administrator flag, which grants
administration of every organisation. It was reproduced on a real database on
17 September 2026 (issue #518) and found in eight places at once: the members
page, the user import, the users API, offboarding and rehire, invitations with
the "admin" role, and safeguarding records. The fix put the second question in
one place (`web/_core/AccountGuard.php`) and made every one of those places ask
it first; an account outside the administrator's organisation now answers
exactly as a missing one does, so a refusal gives nothing away.

**The lesson is general, not specific to this codebase.** Wherever a system has
tenants, organisations or teams, check the permission against the TARGET of the
change, not only against the person making it. When reviewing such code, look
for an identifier taken from the request and used in a WHERE clause without a
tenant condition beside it. A second habit worth copying: a new automatic check
(`tools/audit-checks/check_account_writes_guarded.py`) now fails the pull request
if a future change writes to an account table without asking the guard, with a
short allow list that carries a reason for every entry.


**A prepared-statement query hands back whole numbers, not text — so
comparing a database flag against the text `'1'` is always false.** With
the MySQLi driver used here, a row loaded through a prepared statement
returns integer columns as PHP integers; a flag column compared with
`=== '1'` will never match, and this once silently locked every real
administrator out of an admin-only area, with all mechanical checks still
green. Compare database flags as `(int) ($row['x'] ?? 0) === 1`, and test
permission logic against a real database rather than a stand-in that
returns everything as text.

**Comparing two "wall clock" date-times by converting them to timestamps
breaks once a year, on the day clocks change.** A wall-clock value is the
time written on a poster — not a fixed point on a worldwide timeline. On
the day clocks go forward, an hour does not exist, and PHP's `strtotime()`
silently shifts a time inside that missing hour forward by an hour — so a
check like "does this event end before it starts" can pass when it should
fail. Because these fields are always written largest-unit-first (year,
month, day, hour, minute), comparing them as plain text is both simpler and
strictly more correct, and needs no time zone handling at all.

**An automated check only proves something about the kind of statement it
actually reads.** A column-name checker here compared `INSERT`, `UPDATE`
and `SELECT` statements against the real database schema, but never read a
`DELETE` — so a delete that referred to a column which had never existed
passed every check, and would only have failed in production, unattended,
on the most sensitive table in the system. Before trusting a new automated
check, deliberately introduce the exact fault it is meant to catch and
confirm it gets reported — a check that finds nothing can mean the code is
clean, or it can mean the check never actually looked. A check that cries
wolf on things that are actually fine gets switched off by frustrated
developers, and then it protects nothing at all — prefer a check that
sometimes misses a real fault over one that produces false alarms.

Two more lessons from the same checker (16 September 2026, commit `bda3d84`,
issue #501). **First, a check that crashes must never look like a check that
found nothing.** The pull-request workflow ran the column checker in a way
that printed the same clean-looking report whether the checker finished or
fell over part way. A crash now shows plainly, while real findings stay
advisory. **Second, a "faster" way of searching that promises the same results
as the slow way has to be proven the same, not assumed.** The faster search
jumped from one match to the next without overlaps, so a real query starting
inside an earlier match could be stepped over. It now tries every position
in turn, and always moves forward by at least one character. Without that,
a pattern able to match nothing would loop forever, which the builder
confirmed by reproducing it.

**A real file or folder in the public web root silently wins over any
address the application itself has registered with the same name.** This
is a general web-server behaviour (`RewriteCond %{REQUEST_FILENAME} !-d`
answering for anything that truly exists on disk), not specific to this
codebase, but it has twice made a real, working page completely
unreachable here with nothing written to any log — once hiding a public,
sign-in-free page that also turned out to have no access check at all,
because nobody had ever been able to open it to notice. **The lesson to
carry forward is bigger than the mechanism itself:** a page nobody can
reach is a page nobody has actually looked at, so whatever is wrong with
it — a missing access check included — simply accumulates unnoticed for as
long as it stays unreachable. So before making a shadowed address
reachable again, read what is genuinely behind it first; deleting the
shadowing folder as a "tidy-up" is a security decision, not routine
cleaning. Before adding a new address anywhere, check whether a real file
or folder of that name already exists in the served web root.

**Checking whether a "feature is shipped" is not the same as checking
whether anyone can actually reach it.** Several features here were fully
built, reviewed and merged, and still unusable — because a link that should
have pointed to them was never added, or a later change moved the address
they lived at without updating anything that pointed to it. None of the
usual checks (tests, code review, static analysis) ask "can a person get
here from somewhere in the product?" — they all look only at the code that
was written, not at whether anything reaches it. When a piece of work is
described as finished, it should be possible to say, in one sentence, how a
person actually gets to it.

**When writing this up as an issue, say the feature "has no reachable way
in", not that it "has no caller anywhere".** A first draft of one such
issue (#485) made the stronger claim, and it was false — the feature's own
code did have a caller. The problem was that the caller itself sat at an
address nobody could reach: the only caller of `Translation::translate()`
is `_apps/api/translate.php`, at an address the router can never resolve,
so the code runs for nobody even though something in the codebase does
call it. A second system caught the overstatement. An overstated claim in
an issue is worse than a vague one, because somebody acts on it.

**Two things named alike but not identical is a recurring shape behind real
bugs here**, and each one failed completely silently: an address compared
against a file path that looked similar but was not the same string; a
lookup for a database table name that was very close to correct and
therefore found nothing at all — which reads exactly like "there is nothing
to fix" rather than "the search itself was wrong." A related trap is a fix
that LOOKED complete but was not: reopening the sign-in page during an
upgrade was not enough on its own, because anyone using two-factor sign-in
still typed their password correctly and was then sent straight on to a
SECOND address that was still blocked. Signing in is a JOURNEY through
several addresses, not a single page, so a fix has to cover every address
in that journey, not only the one a bug report happened to name. When
checking whether a name is right, check it against the thing that actually
consumes it (the real routing table, the real database schema) — never
against something that merely resembles it, like a folder layout.

**`phpinfo()`-style diagnostic output leaks the viewer's own session cookie
and, on shared hosting, database passwords — even after filtering out the
"obvious" sections.** Excluding the environment-variables and
request-variables sections is not enough on its own: when a language
runtime runs as a web-server module, its own diagnostics module tends to
print full copies of every server environment variable and every request
header (including the session cookie) in separate tables that a naive
filter misses entirely. The one check that cannot be fooled by a missed
section is checking the finished output for the session token itself,
rather than reasoning about which sections might contain it.

**Handing work to a different AI system when the usual one runs out of
capacity is fine, and expected — provided the work can actually survive the
move.** If a service or agent refuses work (out of credit, rate limited, an
outage) and a retry has already failed once for a reason that will not
change by itself, hand the piece of work to another suitable system rather
than stopping, then return to the usual one as soon as it will take work
again. This only stays safe because of the review rule above — a hand-over
of the BUILDING can be reasonably safe, but a hand-over of the REVIEWING
must never happen silently, because then nothing independent is checking
the work at all. Always try the preferred system again on the next new run,
even if it failed moments ago — limits reset and outages end.

## More project facts worth knowing

**A "portal-wide" setting and a "per-site" setting share one database
table, and an empty site value does not equal another empty site value in
MySQL — so the old "insert this, or update it if already there" pattern
never actually updated a portal-wide setting; it always inserted a second
copy.** This was fixed by adding a derived column that stands in for "no
particular site." Two things stay true whenever this table is touched: a
seed's "if it already exists, do nothing" clause must never overwrite a
value an administrator may have deliberately changed, and the row actually
in use is never simply "the newest one" — several screens here update
whichever row an unordered query happens to return first, which in
practice can be the OLDEST copy. Two more traps surfaced while building the
fix, worth knowing before touching this table again. First, the derived
column has to be declared VIRTUAL and never STORED: the site-number column
it is based on carries a cascading foreign key, and MySQL refuses to add
one of those on the base column of a STORED derived column — with STORED,
the fresh-install script does not merely warn, it fails to load at all
(MySQL error 1215). Second, there is no way to raise a plain, readable
error with `SIGNAL` from inside one of these SQL files, because they run
through the prepared-statement route and MySQL 8.0 refuses that with error
1295 ("this command is not supported in the prepared statement protocol
yet") — the working alternative used here is to deliberately select from a
table whose NAME is the intended error message, so the resulting "unknown
table" error carries that name.

**Which exact database version is running in production is not something
to assume — it is answerable, and there is exactly one place in the code
that decides whether a version is supported: `web/_core/DbServer.php`.**
Every document assumes MySQL 8.0, whose extended support ended in April
2026; the real running version should be read from the system's own
health/diagnostic page rather than assumed. **Do not write a second version
check anywhere else in the codebase.** If which versions count as supported
ever needs to change, change the constants at the top of that one file
(`MIN_MYSQL`, `MIN_MARIADB`, `SUPPORTED_MYSQL`, `SUPPORTED_MARIADB`)
instead — a second check risks disagreeing with the first one silently.
Compatibility with MariaDB is followed carefully as a coding convention but
is not covered by any automated test here — "we write SQL that should work
on both" is not the same claim as "this has been tested on both," and the
two should not be confused when describing the state of the work.

**This portal is the back office only — it lives on its own separate web
address, and content meant for the public has to be shown on the
customer's separate main website, which this project generally cannot
deploy to.** The organisation's main website is usually somebody else's
system entirely (a different content platform, sometimes with restrictions
on what can be embedded), and a request arriving from that separate site
carries no session and an unfamiliar hostname — so working out which
customer's content to show has to be done deliberately and safely, never
by quietly defaulting to "the first customer in the database," which would
leak one organisation's content to another's visitors on a system serving
several customers at once.

**The owner decided, on 13 September 2026, that each release channel (for
example: an early/testing version of the product, and the live version
customers actually use) should get its own separate folder and its own
separate database on the server — nothing shared between them.** This was
prompted by an earlier setup where several channels shared one database and
one copy of the code, so testing on the early channel could read and write
real customers' live data. **This is the decided design, not yet built:**
as of 16 September 2026, the deploy workflow (`.github/workflows/
deploy.yml`) still uses the old shared folder layout, and the portal still
works out which channel it is running as from an environment variable and
then the folder name (`web/_core/bootstrap.php`), not from a marker file
the deploy step writes. **The deploy layout that allowed sharing has not changed since, and on
13 September the channels did share one database. Whether they still do
depends on the deploy settings held as GitHub secrets, which nothing in
the repository can show — so until the owner confirms otherwise, treat
testing on alpha as if it might still read and write real members' data,
the exact problem the decision was meant to end.** The rule to build by is the opposite of "wait for the
rebuild": **never design new code that assumes one database shared across
channels.** A difference in what one channel does compared to another
should come from that channel's own settings, never from a
channel-conditional branch written into the code, because once the
separation lands such a branch would be not just unnecessary but wrong. In
the target design the channel is read from a marker file the deploy step
writes, never from the folder name, because in that design every channel's
folders share the same names (`admin_html/`, `public_html/`) and only
their parent folder differs. Today's folders are still named differently
(`public_html`, `public_html_beta`, `public_html_dev` — see
`web/_core/bootstrap.php`), which is itself a reason not to lean on the
folder name: it will not mean the same thing once the rebuild lands.

## Rules already stated in `.OpenAI/CONTEXT.md`, restated here as lessons

**Every database structure change has to land in two different places, and
they have to agree.** One is how a brand-new customer's database gets
built from nothing; the other is how an existing customer's database gets
brought up to date. A change reaching only one of them is quiet and
dangerous: two installations of the same product version end up behaving
differently, and nobody notices until somebody hits the specific gap,
often much later. Migrations also have to be safe to run twice, because a
fresh install replays every one of them regardless of which already ran.

**Nothing about one customer's set-up — a domain, a web address, a hosting
assumption — is ever a literal value anywhere in this codebase.** This is a
product used by many customers; an address like
`portal.millrdsdacambridge.uk` that appears anywhere in this project's own
documentation is only an EXAMPLE of one customer's real configuration, not
something to fall back to. Real addresses always come from the installer, a
setting, or a deployment secret.

**Every AI system used on this project reviews its own kind of mistake
poorly, so a second, genuinely different system reviews every change before
it counts as finished** — and that review must never be silently skipped or
done by the system that built the thing, because a skipped review looks
identical from the outside to one that actually happened. If a review had
to be handed to a different system because the usual reviewer was
unavailable, or work had to be handed to a different builder because the
usual one ran out of capacity, that fact is written down plainly wherever
the work itself is recorded — the commit message, the handoff, the report —
so nobody mistakes an unreviewed change for a reviewed one, and so a full
catch-up review can find it later.

**Write everything in plain, ordinary English — code comments, commit
messages, pull request and issue text, and any explanation given about the
work — the way it would be explained to a capable colleague who does not
work on this particular system.** Jargon confuses even technically skilled
readers of this project. Where a technical term is genuinely necessary, use
it and then say in ordinary words what it means and why it matters.

## Working-environment traps on this machine (general, not project-specific)

**An unquoted shell variable holding several file paths does not split on
spaces in zsh the way it does in bash.** A command built as `git diff --
$FILES`, where `$FILES` holds several paths, passes the whole string as
ONE argument in zsh — matching nothing — so the diff comes back completely
empty and the command still exits successfully. This has already produced
an empty "here is the change to review" handed to a reviewer, which can
easily come back "looks fine" with nothing actually having been looked at.
Before handing any diff or list of files to anything else, check its size
and the number of files it actually contains, and prefer writing paths out
literally, using a real array, or doing the file handling in a script file
rather than relying on inline variable splitting in a shell command.

**A temporary or scratch directory on this machine is not guaranteed to
survive between working sessions**, and has already been cleared within a
day or two, taking a finished-but-unread piece of work with it. Anything
that genuinely needs to survive an interruption — a review transcript, a
decision, a piece of context another session or another tool will need —
belongs in the repository itself: either committed (for anything meant to
be read later, such as the handoff), or in **`.claude-work/`**, a working
folder inside the repository that is deliberately excluded from git (never
committed) but does survive on disk between sessions. It already holds
real, currently-useful material — saved plans, briefs and run reports, plus
the `codex-queue.sh` script mentioned above — so it is worth checking there
for context before assuming something has been lost.

**Staging "everything that changed" in git is unsafe whenever more than one
process might be editing the same checkout at the same time** — a
background agent, another AI tool, or a person working in parallel. Doing
so can sweep somebody else's half-finished edit into a commit whose message
never mentions it. Stage files by explicit path, and check the full list of
what is about to be committed before committing it, rather than assuming
everything currently different is meant to be included.

**Roles belong to one organisation each (since #516, commit `c99dc87`, 21
September 2026).** Every organisation has its own copy of the fourteen
standard roles and may rename them or add its own. The role's KEY
(`roleKey`, for example `treasurer`) never changes and is what the code
checks with `App::hasRole()`; only the label (`roleName`) is renameable.
Holding a role is per organisation, and the database refuses a holding
without an active membership of that organisation. `Portal\Core\Roles`
(`web/_core/Roles.php`) owns granting, revoking, seeding and the shared
lookups, but it is NOT the only code that touches the role tables: the
role-list page writes `tblRoles` itself, and about a dozen hand-written
queries read both tables directly, each repeating the per-organisation join.
When reviewing a change to how roles are matched, search for `tblUserRoles`
and check every site, not just the class.

**Dependency (Dependabot) pull requests.** Dependabot raises a separate
GitHub Actions update for each of `alpha`, `beta` and `main` (`.github/
dependabot.yml`). `security-backport.yml` exists on `main` only and copies
merged Dependabot or security PRs from `main` down to the other branches. For
a workflow-only update that copy always fails, because the built-in token may
not push workflow files. On 21 September 2026 the owner approved skipping
`dependabot/github_actions/` branches there. Merging a PR into `alpha` can
happen automatically (`auto-merge-alpha.yml`) the moment its checks go green.
Nothing deploys unless `web/**` or `deploy.yml` changes.
