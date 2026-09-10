# Handoff — branch clean-up, full audit, documentation refresh

**Updated:** 2026-09-07 (session in progress — this file is kept current as work
proceeds, so the session can be picked up at any point).
**Working branch:** `claude/alpha-wip` — the single work-in-progress branch.
**Target:** one pull request into `alpha` at the end. No stacked pull requests.

---

## Read this first — where we are right now

**Updated 10 September 2026, evening.** Working branch `claude/alpha-wip`, 27+
commits ahead of `alpha`, nothing open against it. The repository is fully
aligned with GitHub: no stale local branches, `alpha` in step with the remote,
and the working branch is ahead-only so a rebase would change nothing.

### THE QUESTION THAT MATTERS: can this go to a first real customer yet?

**Not yet, and there is one reason.** It is not missing features — the product is
broad and largely built. It is that **there is currently no recovery path anybody
should trust.**

- **#472** — restoring a backup can DESTROY the data it was protecting.
  `web/_core/DbBackup.php:596` empties the table with `TRUNCATE`, which commits
  immediately and cannot be undone. If a row fails half way through, the original
  contents are already gone. On top of that, MySQL refuses that command outright
  on any table other tables point at — 71 of 209 — so restoring those has never
  worked at all.
- **#488** — the emergency instructions somebody would follow at 2am do not work.

Until both are fixed, a customer with a problem could end up worse off than
before they asked for help. Everything else on the list can be fixed while live.
These cannot.

### The agreed order of work

| # | Task | Why this position |
| --- | --- | --- |
| 1 | **#472** restore must never destroy data | The go/no-go. Nothing else matters if recovery cannot be trusted. |
| 2 | **#488** make the emergency instructions true | No point having a safe restore nobody can find in a crisis. |
| 3 | **#479** data deletion and data download | Legal exposure, and it is cheapest to fix before real data exists. |
| 4 | **Noticeboard** enhancements | Owner-requested. Sound today, but a noticeboard that cannot be displayed is half a feature. |

### The owner's decisions on data protection, settled 10 September 2026

- **ONE standard for everybody.** Behaviour does NOT vary by where a person
  lives. Working out somebody's country is itself processing their personal
  data, it is unreliable, and two sets of rules doubles the chance of a mistake
  in the sensitive one. The right to have data deleted exists under UK and EU
  law, and now under Brazilian and Californian law too. One high standard is
  simpler and holds up everywhere.
- **Erase by default, with a SHORT NAMED LIST of lawful exceptions.** Deleting
  everything regardless would break the law in the other direction. Kept:
  Gift Aid declarations (6 years after the tax year, HMRC), financial and
  expense records (6 years), safeguarding records (kept and flagged, never
  silently deleted). Each exception recorded so the organisation can show why.
- **Event registrations** get linked to an account where one exists, AND are
  deleted automatically 90 days after the event.
- **The download** must be ONE file a person can open, holding readable copies
  plus a machine-readable copy, covering EVERY table with their personal data.

### ⚠️ The deletion list is longer than the issue says

#479 named five tables. It is at least seven: `tblNoticeboardPosters` and
`tblNoticeboardUploads` were found by ACCIDENT while looking at something else,
and are in neither the deletion list nor the download.

That is the real lesson, and it changes the fix. **Build the list from the
database structure itself**, not from tables somebody happens to name, or the
next table added repeats this exactly. `tblNoticeboardUploads` also points at a
file on disk — deleting the row is not enough, or the picture outlives every
record that it existed.

---



**Updated 10 September 2026, later in the day.** Working branch `claude/alpha-wip`.
Nothing is open against `alpha`. The owner has NOT installed on the live server
yet and wants the product fleshed out as much as possible first — that changes
what is worth doing, because anything cheap now and expensive once real customer
data exists is at its cheapest moment.

### The current queue

| # | Task | Issue | Status |
| --- | --- | --- | --- |
| 1 | Deep analysis and plan (Fable, run one after another) | — | in progress |
| 2 | Admin area unreachable + widget gated in the SAME commit | #483, #478 | ✅ `20d7a05` |
| 3 | Maintenance mode locks administrators out | #477 | ✅ `f0eb9d5` + `bf2399e` |
| 4 | Five Export CSV buttons crash before producing anything | #482 | ✅ `f0eb9d5` |
| 5 | Codex review loop until a round comes back clean | — | ⏸️ queued for 18:33, limit hit |
| 6 | Documentation sweep (.md, in-app help, OpenAPI/Swagger) | — | ✅ done |
| 7 | GitHub issue sweep, open and closed | — | in progress |
| 8 | Memory, context and this handoff | — | ✅ updated |
| 9 | *(added)* A twelfth automatic check for the shadowing fault | #483 | ✅ `be9afcd` |
| 10 | *(added)* The API was reporting version 1.2.0, not 1.4.0 | #482 | ✅ `eb0443c` |

### A note on commit `be9afcd`, so the history makes sense

That commit's message describes the new automatic check. It ALSO contains a
documentation agent's edits to `CHANGELOG.md` and `DEV_NOTES.md`, which the
message does not mention. The cause was mine: `git add -A` while a background
agent was still writing in the same working folder. Everything landed intact and
was checked afterwards, but the commit boundary is not what the message implies.

The practice that avoids it — stage by explicit path while any agent is running,
never `git add -A` — is written into the memory notes.

### What was checked and deliberately NOT changed

- `FEATURES.md` needed no change. The only mention of embeddable widgets sits
  inside a dated historical snapshot of what an earlier release contained, not a
  claim about today, and the countdown widget genuinely still works. Verified
  rather than taken on the agent's word.
- Both remaining widget addresses (`/widget/countdown` and
  `/widget/countdown.json`) reach the portal correctly. Only the calendar one
  was removed.

### ⏸️ BOTH review systems hit their limits today

- **Fable** reached its MONTHLY SPEND limit. Deep analysis fell back to Opus,
  which is what the owner's standing instruction says to do. Retry Fable on the
  next deep-analysis run.
- **Codex** reached its usage limit at about 15:40, resets **18:32**. A review of
  commits `20d7a05`, `f0eb9d5` and `bf2399e` is queued to run automatically and
  retry every five minutes. **Those three commits are NOT yet reviewed** and must
  not be treated as signed off until that round has run and come back clean.

### What was fixed, and the one thing that was nearly missed

The four faults are described further down. Two things worth carrying forward:

**The widget could not be fixed the way the plan assumed.** The plan said delete
the shadowing folder. But `/widget/countdown.js` is a script other people's
websites already load from this server — moving it breaks their pages with no
warning. So the folder stays and the dead ADDRESS was removed instead. That
turned out better anyway, because it exposed a second fault: the page's queries
returned INTERNAL events, since it lacked the `isPublic = 1` filter every other
public calendar page already had.

**The maintenance fix was a half-fix at first.** Opening the sign-in page was not
enough: anyone with two-factor turned on typed the right password and was sent to
`/auth/2fa/verify`, straight back into the same wall. Fixed in `bf2399e`. Signing
in is a journey, not a page — and a half-fix that looks whole is worse than none,
because it fails only for the administrators careful enough to use two-factor.

### Four faults confirmed against the code, not taken from issue text

A 35-agent assessment checked every open work-queue issue against the actual
code, then challenged each assessment. Four faults came out of it, and **none of
them is what its issue title says**. Every one below was then re-verified by
hand before being acted on.

1. **The Admin area never reaches the portal (#483).** The web server's rule
   says a request matching a REAL FOLDER is answered by the web server itself
   and never handed to the portal (`RewriteCond %{REQUEST_FILENAME} !-d`).
   `web/public_html/admin/` is a real folder, and there is a seeded address
   `admin`. So an administrator clicking Admin gets a folder listing or a
   refusal, and nothing appears in the error log because no portal code ran.

2. **Fixing that on its own would publish something to the internet (#478).**
   `web/public_html/widget/` shadows the seeded address `widget`, which points
   at `calendar/widget.php` with **no login required**. That file contains no
   access check of any kind — verified. Right now nobody can reach it BECAUSE
   the folder is in the way. Delete the folder as a tidy-up and it goes live.
   **These two must land in one commit.**

3. **Maintenance mode locks administrators out of their own portal (#477).**
   The seeded sign-in address is `login`. `Maintenance.php:55` allows
   `auth/login`, which is the target FILE PATH, not an address. The holding
   page's own sign-in link points at the same non-address. Maintenance mode
   switches itself on whenever the code is newer than the database — every
   upgrade. One line to fix.

4. **Five Export CSV buttons crash instantly (#482).** `Router.php:138` puts
   only `$mysqli` and `$SETTINGS` into a page's scope. These five reach for
   `$db`, which is never defined: `attendance/export.php`,
   `admin/users/export.php`, `admin/activity/export.php`,
   `leadership/export.php`, `expenses/api/export.php`.

### Decisions taken without asking

- The public calendar widget will be **off by default**, opt-in per site.
  Nothing depends on it today because it is unreachable, and switching
  something public on by default during a bug fix is the wrong direction.
- #480 (minimum password length) is still the owner's decision and is not in
  this batch.

### Not verified, and not claimed

- The GDPR erasure gap (#479) — reportedly five tables missed. Not checked here.
- The database compatibility result that downgraded #475. The assessment itself
  flagged this as unverified.
- The end-to-end migration harness has not run since the disk filled up (see
  below).

### ⚠️ This machine has run out of disk space

926 GB disk, about 2 GB free. Docker's daemon is down as a result, so the
end-to-end migration harness CANNOT run. `~/_ENCODES` is 301 GB and is the
obvious candidate, but it is the owner's data and has been left alone. Only
temporary files created by this session were removed. Any commit made while
this is true says plainly that the harness did not run.

---



**Updated 10 September 2026.** Working branch `claude/alpha-wip`, cut from the
updated `alpha`. **Nothing is open against `alpha`** — pull request #473 merged
and deployed earlier today.

### This session, part two: the work queue

Fifteen items were agreed and added to the queue. **Every one now has a GitHub
issue.** The index is `.claude/plans/work-queue.md`; the issues carry the detail.

| Item | Issue |
| --- | --- |
| Drop end-of-life MySQL 8.0; target MySQL 9.7 / MariaDB 12.3 / PHP 8.5 | **#475** ⛔ blocked |
| Repair version + changelog automation on `alpha` | **#476** |
| "Check my portal" page for administrators | **#477** |
| Make switching an app off actually switch it off | **#478** |
| Prove the "delete my data" list is complete | **#479** |
| Decide the minimum password length | **#480** ⛔ needs a decision |
| Stop three migrations switching apps back on | **#481** |
| Document the other 29 data endpoints | **#482** |
| Finish the mobile pass on a real phone | #225 (existing) |
| Small clean-ups worth doing together | **#483** |
| Warn when a deploy is about to remove files | #107 (existing) |
| Keep a history of settings changes | **#484** |
| In-app messaging | #304 (existing) |
| Update the self-hosted Swagger UI | **#486** |
| Re-verify the database structure before the first customer | **#487** |
| *(added)* Translation is a shell | **#485** |

### Latest task: the database version question, answered from inside the product

**Shipped 10 September 2026, commit `64ad87a`, issue #489.** The owner asked
three things: check DreamHost's MySQL version again against their own sources;
add a database check to the installation wizard; and add an admin-only page
showing server information — database version, connection details and the PHP
report.

**On the first**: DreamHost's own documentation says only **"MySQL 8"** and
never a point release, and explicitly answers *"Can I update the MySQL
version?"* with *"No"*. Their pages were checked again on 10 September 2026.
**So the documents cannot answer this**, and waiting for them to is pointless.
One earlier assumption corrected: **DreamHost shared hosting does not offer
MariaDB.**

**So the product now asks the server itself.** That is what unblocks #475.
Somebody needs to open `/admin/system-info` on the live site and report the
version; then #475 can be planned properly.

What was built:

| Piece | Where | What it does |
| --- | --- | --- |
| The judgement | `web/_core/DbServer.php` | Reads the database product and version and says: supported, worth knowing about, or too old to run on. **The one place that decides.** Rules are constants at the top of the file. |
| Its self-test | `tools/db-server-selftest.php` | 15 real version strings, no database needed. `php tools/db-server-selftest.php` |
| Wizard check | `web/_install/index.php` (the `db_config` handler) + `db_server_banner.php` | Asks the moment it first connects — the earliest it can be known, since before that nobody has typed any database details in. |
| The page | `web/_apps/admin/system-info/index.php` | Route `/admin/system-info`. Any administrator. |
| The PHP report | `web/_apps/admin/system-info/phpinfo.php` | Route `/admin/system-info/phpinfo`. Umbrella administrators only. |
| Routes | `web/_sql/188_server_information_page.sql` | Two route seeds. Folded into `full_schema.sql`. |

**Three decisions worth not re-litigating:**

1. **The installer warns but does not block** on an out-of-support database. It
   stops only when the database is genuinely too old to hold the schema. On
   shared hosting the customer cannot change the version, so blocking would
   only lock them out of their own portal.
2. **The health page's traffic light stays green** on an ageing database. That
   page is polled by uptime monitors, and a permanently amber light is one
   everybody learns to ignore — so a real problem arriving later would land in
   a warning nobody reads.
3. **The database password is not on the Server Information page and is not
   redacted** — it is never loaded into the page at all. Connection facts are
   asked of the live connection rather than read from the credentials file, so
   no future edit to that page can print something it never had.

**The one real defect found and fixed during this work**, worth reading because
it would have been easy to ship: leaving `INFO_ENVIRONMENT` and
`INFO_VARIABLES` out of `phpinfo()` is **not enough**. When PHP runs as an
Apache module, the `apache2handler` entry inside the components section prints
"Apache Environment" and "HTTP Headers Information" **of its own accord** — and
the second contains the request's `Cookie` header, which is the reader's
session token. It would have arrived by a different door, on the page most
likely to be pasted into a support ticket. The report is now captured and those
tables removed before anything reaches the browser, and as a final check the
page refuses to render at all if `session_id()` appears in the output.

Two related things checked rather than assumed: `phpinfo()` masks nothing (a
`mysqli.default_pw` and a `sendmail_path` carrying a password both printed in
full), and the command line prints plain **text** not HTML, so a filter tested
only from the command line silently matches nothing and looks like it works.
Verified through `php -S` instead.

**Verified:** `php -l` clean on every touched file · all 11 audit checks pass ·
the new self-test passes 15 of 15 · the end-to-end migration harness passes
every phase against MySQL 8.0.36. **The Codex round for this work has not run
yet** — the account was still rate-limited. See the section below.

---

### ⏸️ One thing left undone: a final Codex review round

The review rule says keep going until a round finds nothing. Four rounds ran on
this documentation and each found something real. **The account hit its usage
limit part-way through round four** (resets 1:30 PM). Everything round four
surfaced before stopping has been fixed, but **a clean pass has not been
observed** — so treat this documentation as reviewed-and-corrected rather than
signed off.

Re-run when the limit clears:

```bash
codex exec --skip-git-repo-check "$(cat <the brief>)" < /dev/null
```

The briefs used are in the session scratchpad as `codex-brief-5.txt` through
`codex-brief-8.txt`, and the reviews as `codex-review-5.txt` onward.

**What the four rounds caught is worth reading**, because the pattern was mine
rather than random: narrow a search, then state the result as an absolute. That
produced one flatly false claim that reached a GitHub issue
(`Translation::translate()` "has no caller anywhere" — the unreachable file does
call it), then the same mistake a second time, plus several guarantees the
evidence did not support. It also caught that migration 187's own header — which
had already merged — still described the approach that was tried and rejected.

### ⛔ Two things are blocked on the owner

**1. Which MySQL 8 the live site is running** — this decides most of #475.

DreamHost's published documentation says shared hosting runs **MySQL version 8**,
offers no MariaDB, and does **not** let a customer choose the version — a newer
one needs a Dedicated or DreamCompute plan. (An earlier draft of this note
guessed MariaDB. That was wrong, and it was corrected after a review.)

So "target MySQL 9.7" cannot mean what customers on shared hosting will run. It
can only mean what the code supports. Still worth having, but forward planning
rather than a fix.

**The question that matters is which MySQL 8.** On **8.4 LTS**, support runs to
2029 and there is no urgent problem. On **8.0**, the product is on a database
that stopped receiving security fixes in April 2026 — an owner decision: stay
put, move hosting tier, or support other hosts.

**PHP is settled.** A `phpinfo()` page from the server was shared on
10 September 2026 showing `mysqlnd 8.5.5` — and mysqlnd's version number is
PHP's own, because it is the driver bundled inside PHP. (Checked rather than
assumed: a local PHP 8.5.10 reports `mysqlnd 8.5.10`.) So the server runs
**PHP 8.5.5**, which is the target version. Nothing to do there.

That same page does **not** reveal the database server version — `mysqlnd`
describes the client library inside PHP, not the server it talks to. The same
driver version appears whether the server is 5.7, 8.0, 8.4 or 9.x.

**Quick to check:** the portal already reads and shows the database version on
the admin dashboard (`web/_apps/admin/index.php:133`) and on the health page.
Sign in to the live site and look.

Still true either way: no minimum database version is enforced, the automated
test covers only MySQL 8.0.36, and MariaDB is not covered by any test here.

Also unsettled: how far #475 should go. Raising the minimums and testing against
the new versions is a few days. Adding conditional code so one codebase runs
correctly on old and new versions is considerably more and adds a branch to
maintain forever. Recommendation recorded in #475: do the first stage on its
own, because it is the foundation the second would need anyway.

**2. The minimum password length** — #480. An existing site could still be
enforcing an 8-character minimum while the documentation, the help page and the
code's own fallback all say 12. Raise them automatically, or tell administrators
and let them choose? Recommendation in the issue: tell them. The 8 is almost
certainly the ghost of an old seed rather than anybody's decision, but that
cannot be shown for any particular site.

### 🔴 The finding worth reading before anything else

**#485 — automatic translation of user-written content has no way in.** An
administrator can pick a paid provider (Anthropic, OpenAI, Google, DeepL), enter
an API key and set a spending cap. A member can go to their account page and opt
in. **Neither does anything**, because nothing a user can reach ever calls the
translation code.

Be precise about two things here, because loose wording caused a false claim in
the first draft of this note. The only caller of `Translation::translate()` is
`web/_apps/api/translate.php` — so it is not true that there is "no caller
anywhere". That file simply cannot be reached: addresses starting `api/` go to
ApiRouter, which needs `api/{app}/{action}` and looks elsewhere. And this is
**separate from interface translation** (`I18n` and the `t()` function), which
works normally — the portal does translate its own screens.

Two reasons it is urgent rather than merely wrong. It goes to a first customer
soon, and somebody could enter a billable credential for something that will
never make a request. And it must be settled **before** #483, because that
clean-up deletes the unreachable file that is the only surviving description of
how it was meant to work.

Note migration 158 removed that file's routing-table row, but the address was
**already** unreachable — `api/` paths never consult the routing table. So 158
tidied a dead row; it did not break a working feature.

Issue #278 was closed as delivering this. It has been commented, not reopened.

**This is the fourth time this pattern has appeared** — #322 web push, #273
livestream, #278 translation, and six unreachable help guides. Built, reviewed,
merged, closed; nobody could reach it. Recorded in memory as
`webms-shipped-but-unreachable`. It is the strongest argument for #477.

### Suggested order when work resumes

1. **#476** — about an hour, and stops `alpha` reporting the wrong version.
2. **#485** — decide before #483 destroys the evidence.
3. **#477** — widest reach; would have caught most of this audit.
4. **#481, #483** — small and mechanical.
5. **#475** once the hosting question is answered, then **#487** after it.

## 1. The branch clean-up (done)

Six branches were sitting on GitHub with no pull request attached.

**`claude/venue-b` … `claude/venue-f`** were five workers building one job — the
Venues app — side by side from a shared starting point. Their output was
gathered into `claude/venue-bookings` and merged into `alpha` as pull request
**#431** on 28 August. Checked file by file, every one of them was either
already identical to `alpha` or an older version `alpha` has since improved. A
trial merge of each produced conflicts that would have **undone** later work —
most importantly the room-aware booking coverage added for issue #436 in pull
request #452. **Nothing was outstanding; merging would have caused a
regression.** All five deleted.

**`claude/dependabot-alpha-beta-branches-q24zd7`** had a misleading name — no
dependency updates and no program code, only twelve planning documents that
existed nowhere else, covering work that has since shipped in nine pull
requests (434, 441, 444, 445, 446, 451, 452, 454 and 455) and including two
security reviews. Those twelve documents were copied onto `claude/alpha-wip` under
`.claude/plans/gap-items/`. The branch was then deleted.

The full method and evidence is in `.claude/plans/branch-audit-2026-09-07.md`.
**The lesson worth keeping: never judge a branch by its commit messages.**

## 2. Swagger UI + the /api-docs page (done — commit `8f21094`)

Three problems, all fixed:

1. **The page broke without internet access.** Swagger UI was only ever fetched
   from a public content delivery network, with no local copy to fall back on —
   unlike Bootstrap and Font Awesome, which have always had one. On a network
   that blocks public CDNs the page was a blank white screen. The three files
   are now committed under `public_html/assets/vendor/swagger-ui/` and wired
   into the existing fallback machinery. Each was verified to hash to the SRI
   value `Asset.php` already recorded, so the local copy is provably identical
   to what the CDN serves.
2. **"Try it out" could never write anything.** The page read the anti-forgery
   token from a `<meta name="csrf-token">` tag, but the page deliberately
   bypasses the shared template that emits that tag — so the token was always
   empty and every write was refused with "CSRF check failed". The page now
   emits its own. And because the portal replaces the token every time it
   accepts one, `ApiAuth::requireWrite()` now hands the replacement back as
   `meta.csrfToken` so a second write does not fail.
3. **The page phoned home** to `validator.swagger.io`. Switched off.

New setting `api.docs.local_assets_only` (migration 185, seeded off) skips the
CDN entirely for sites whose network blocks it.

## 3. PHP on the development machine (done)

There was no PHP installed, so Visual Studio Code could not check files and the
build's hard lint gate could not be reproduced locally. Installed **PHP 8.5.10**
via Homebrew (the project's exact target version), pointed the editor at it via
`.vscode/settings.json` (which git ignores, so it stays on this machine), and
confirmed all 785 PHP files pass `php -l`.

`DEV_NOTES.md` now has **"Installing PHP on your own machine"** with
step-by-step instructions for macOS, Windows, Linux and Raspberry Pi.

---

## 4. What the audit found, and what has been done about it

Audit reports so far, in the session scratchpad under `analysis/`:
`01-inventory.md` (apps, routes, handlers) and `02-core-schema.md` (core
classes, migrations, tables, CI). Every finding below was independently
re-verified before being acted on.

### ✅ Already fixed — commit `6c01c47` (migration 186)

- **The notification preferences page could not be opened.** Two pages were
  both called "Notification preferences"; migration 093 gave the address to
  the newsletter-only one, hiding the real page. Browser push opt-in was
  therefore only reachable from the livestream page. The newsletter checkbox
  is now a card on the real page, the newsletter-only page is deleted, and
  the address points back where it belongs.
- **The livestream channels and schedule page could not be opened** — with it
  went the only button that tells subscribers "we are live now". It now has
  its own address, `/admin/livestream/channels`, linked from the analytics
  page.
- **Six help guides nobody could find** — Venue Bookings, Forms Builder,
  Reports, Admin First Steps, Disaster Recovery and Getting Support existed
  with working addresses but no link anywhere. All 18 guides are now on the
  Help Centre index.
- Removed four settings that switched on API endpoints whose handler files do
  not exist.

### ✅ Already fixed — commit `82241bc`

- **Six menu and dashboard links led to "page not found"** (`/expenses`,
  `/kids`, `/reports`, `/salvation`, `/worship`, `/webhooks`). New
  `Router::routeExists()` means those generated links are left out when a
  successful lookup finds no registered address for them, and a new optional `landing` field on an app's registry entry
  says where to link when the app's own prefix is not a page. The `route`
  field could not simply be corrected — it is the prefix used to decide which
  app owns a page for the on/off switch.
- **An app switched on at `/admin/apps` never appeared in the menu.** That
  screen writes `'1'`; the menu insisted on `'true'`. Both now accepted.
- **"Run all pending migrations" would have put demo data into a live site.**
  `Migrator::allFiles()` accepted any `.sql` file, so `full_schema.sql` and
  `demo_data.sql` always showed as pending. Both `allFiles()` and `runOne()`
  now accept only numbered files.
- **Four of the eleven safety checks were never run by any workflow.** All
  four now run on every pull request.

### ⚠️ SUPERSEDED — this section described problems that are now FIXED

**Everything that used to be listed here has shipped**, in pull request #473
(merged 10 September 2026). The section has been removed rather than left in
place, because it did more than go stale: it recommended an approach that was
later tried and **proved wrong**.

For the record, so nobody repeats it:

- It proposed de-duplicating the settings table by **keeping the newest row**.
  That would have deleted saved payment credentials. Several screens find their
  row with an unordered `SELECT … LIMIT 1` and update that one, which in
  practice is the OLDEST copy. The rule that shipped prefers the row that looks
  edited, then the most recently written, then the newest.
- It proposed a **STORED** derived column. MySQL refuses a cascading foreign key
  on the base column of a STORED derived column, and `tblSettings.siteID` has
  one, so `full_schema.sql` would not load at all. The column that shipped is
  **VIRTUAL**.
- It proposed `COALESCE(siteID, 0)`. The value that shipped is **-1**, because a
  site numbered 0 is not impossible on an imported database, and 0 would then be
  confused with "applies to every site".

Both mistakes were caught by the Codex review, not by any automated check —
every one of those checks passed on the wrong version. That is the argument for
the review rule in `.claude/CLAUDE.md`.

What actually shipped is described in the changelog, in migration 187, and in
`DEV_NOTES.md` under "The settings table". Issues #466, #467, #468, #469, #470
and #471 are all closed with evidence.

## 5. Verified-clean baseline

Measured on this branch, so a later regression is obvious:

- **785** PHP files, all pass `php -l` (the exact command CI uses).
- **All 11** checks in `tools/audit-checks/` pass.
- **559** live routes, **0** pointing at a missing file.
- **566** settings keys seeded, **0** read without being seeded and without a
  fallback.
- **184** numbered migrations (000-184; 168 and 169 were never used), all
  re-runnable, **no** MariaDB-only syntax.
- **209** database tables, **0** column-name mismatches.
- **54** app directories, **47** in the installable-app registry, **19** help
  pages, **62** API handlers, **63** documented API operations.

---

## 6. How this session is being run

- **Deep analysis and planning:** sequential Fable 5 agents, never parallel.
  Fall back to Opus only if Fable is unavailable, and retry Fable next time.
- **Building:** Sonnet or Haiku; Opus only when genuinely hard.
- **Everything written in plain English** — replies, comments, commits, pull
  requests, issues, documentation, in-app help. Now a standing rule in
  `.claude/CLAUDE.md`.
- **After each piece of work:** commit and push, update the GitHub issue, update
  `.claude/`, update this file.
- **One pull request only**, at the end, into `alpha`.

## 7. If you are picking this up cold

1. `git checkout claude/alpha-wip && git pull`
2. Read `.claude/plans/branch-audit-2026-09-07.md` for why the branches went.
3. Read the audit reports in the session scratchpad under `analysis/` —
   `01-inventory.md` is the ground truth for apps, routes and handlers.
4. The findings in section 4 above are the work queue. None is fixed yet.
5. `php -l` and `python3 tools/audit-checks/*.py` are the local gates. Both were
   clean at the last commit.
