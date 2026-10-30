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
> updates to the release branches that change `web/_sql/`). **Since 12 October
> 2026 it also upgrades two older databases** — one built from the last release
> (the `main` branch) and one from the install script of 6 March 2026 — and
> requires each to end up equal to a fresh install. It also holds the
> installer's replay against the install script alone, and loads the install
> script a second time. It still cannot stand for a database whose history
> matches neither, and none of this has yet run on GitHub. MySQL 8.4 refuses a
> link that points at a non-unique key (ERROR 6125, #552);
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
> the constants at the top of that file instead. **`DbServer::connect()` is also
> the ONE place under `web/` that opens a database connection (#593, 12 October
> 2026): it makes the connection strict, so a value that does not fit is an
> error on every installation. Never write `new mysqli` anywhere else under
> `web/`; `tools/db-strict-mode-selftest.php` fails if it finds one.** Second, **MariaDB IS now
> covered, but only by the calendar self-tests (#487, 28 October 2026).**
> `calendar-selftests.yml` runs its six tests on THREE engines —
> `mysql:8.0.36`, `mysql:8.4` and `mariadb:11.4` — and all of them pass on
> each. Measured locally first, on MariaDB 11.4.13: feed-admin 933 passed with
> **one** skip, event-visibility 192, import-notifier 134, feed-resolver 208,
> feed-importer 139, and `full_schema.sql` loads clean (228 tables). The one
> skip is a limit of the TEST, not of MariaDB: `performance_schema` is OFF by
> default there and there is no `PS_CURRENT_THREAD_ID()`, so one part-D check
> has no instrument to ask — the portal queries `performance_schema` NOWHERE,
> which was checked rather than assumed. **The END-TO-END MIGRATION HARNESS NOW
> RUNS ON MARIADB TOO (#487, 28 October 2026), and that is the one that replays
> every migration.** All seven phases and all 42 checks pass on MariaDB
> 11.4.13 — the SAME 42 that pass on MySQL 8.0.36 and 8.4.11, counted rather
> than assumed, because the MariaDB log is 1,284 lines shorter and the
> difference is exactly the password warning the `mariadb` client does not
> print (2,976 + 1,284 = 4,260 precisely). `e2e-migrations.yml` now runs three
> jobs side by side. Two things in the HARNESS, never in the portal, had
> stopped it: MariaDB 11.x ships no `mysql` or `mysqladmin` program (only
> `mariadb` and `mariadb-admin`), and the version check compared the server's
> whole reported string, which `11.4.13-MariaDB-ubu2404` never matches.
> `E2E_DB_ENGINE` (`mysql` or `mariadb`) is now the ONE place that decides the
> image and both client program names; the version check compares the leading
> three-part number, which keeps the **moving-tag guarantee** exactly as it was
> — `8.4` still does not pass for `8.4.11`. **Never say it is "exactly as
> strict as before" without that qualifier:** it accepts `8.0.36-log` and
> `8.0.36-0ubuntu0.24.04.1`, which the old whole-string compare refused, so it
> is a deliberate loosening in that one way. It also refuses when the engine
> asked for is not the engine that answered — a cheap last line of defence
> only, since the engine and the image come from one variable and
> `wait_for_mysql` fails first on MariaDB 11.x.
>
> **The one real difference it found, and the trap in it.** Phase 2 failed on
> MariaDB because `tblEvents`' two links to `tblVenues` and `tblVenueRooms`
> came out `on update RESTRICT` where MySQL says `on update NO ACTION`. The
> first guess — that the servers normalise what they are told — was WRONG, and
> measuring it on all three disproved it: every engine records a DECLARED rule
> faithfully, and **only the default for an OMITTED clause differs** (MySQL
> 8.0/8.4 → `NO ACTION`, MariaDB 11.4 → `RESTRICT`). Migration 179 gives those
> two links `ON DELETE SET NULL` and no `ON UPDATE` at all, which is why they
> were the pair that showed it. The two words name the same rule in InnoDB, so
> `dump_inventory()` folds the two together in ONE place and prints them as the
> PAIR `NO ACTION/RESTRICT`. **Be careful how that is reported.** The run's
> verdict and all 42 `✓` lines are unchanged on MySQL (diffed), but saying the
> MySQL output is "byte-identical" is WRONG and was corrected after the
> independent check caught it: 26 links in `full_schema.sql` DECLARE
> `ON DELETE RESTRICT`, so their recorded wording changes. That is also why the
> fold prints the pair rather than one word — picking `NO ACTION` made a
> failure line read `on delete NO ACTION` for a constraint whose SQL says
> `RESTRICT`, so anybody grepping the schema for the word in the message would
> find nothing. **What the fold gives up, plainly: the harness can no
> longer tell a declared `RESTRICT` from a declared `NO ACTION`, and 54 places
> in `web/_sql` do declare `RESTRICT`.** That costs nothing real — the two
> behave identically — but it is not the place to catch such an edit, and on
> MariaDB an OMITTED clause and an explicit `RESTRICT` are the same text — but
> do NOT stretch that into "MariaDB cannot tell them apart", which is false and
> was measured false: it records a DECLARED `NO ACTION` and a DECLARED
> `RESTRICT` distinctly, exactly like MySQL. Note too that the failure was
> never in the comparison, which is always same-engine and found the same two
> links on both: it was the HARD-CODED exception list, written in one engine's
> spelling. Also worth knowing: MariaDB's default server collation is
> `utf8mb4_uca1400_ai_ci`, a third value alongside MySQL 8.0's
> `utf8mb4_0900_ai_ci` and the schema's own `utf8mb4_general_ci`.
>
> **Still do not say "MariaDB is supported" without saying what was measured.**
> Two automated bodies of work now pass on MariaDB 11.4 — the calendar
> self-tests and the migration harness — and that is a great deal more than
> before, but it is not the same as a customer running on it in production.
>
> `Portal\Core\DbServer` reads DreamHost's real version string correctly —
> `8.0.41-0ubuntu0.24.04.1` is parsed as MySQL 8.0.41 and judged `warn`,
> non-blocking, advising the customer to ask their host about 8.4. Measured
> against the owner's own `SELECT VERSION()` on 28 October 2026, together with
> the `-log` suffix, Ubuntu packaging, MariaDB's fake `5.5.5-` prefix and full
> Debian packaging. Nothing needed fixing.
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
- **The mail library** lives in `web/_core/maillib/` (#592) and must reach for nothing of the portal; `php tools/maillib/tests/stands-alone.php` enforces that from a list of what the library may use (`check_maillib_stands_alone.py` only starts it). Its test is `php tools/maillib/tests/run.php`. Since part two (#592) `Mailer::send()` uses it for the SMTP route, through `web/_core/MailRoutes.php`, the ONE place that reads the SMTP settings and the installation's choice of route, and decrypts the mailbox's password (never read `$SETTINGS['mail']['provider']`). Its page test is `tools/mail-pages-selftest.php`. Since part three a message that cannot be sent at once is kept in the mail queue (`web/_core/MailQueue.php`, which holds every statement that moves a queue row; test `tools/mail-queue-selftest.php`) and sent by `/cron/mail-queue`; `Mailer::send()` answers true for sent OR kept, so anything that records or shows a result asks `Mailer::lastOutcome()`. `MailQueue::jobIsRunning()` is the ONE place that says whether the scheduled job is running (only the job's own page records that it ran); while it is not, the portal sends as it did before the queue. Every queue moment is UTC from `UTC_TIMESTAMP()`; a time on a clock becomes one through `MailQueue::useBy()`. An event's time zone is `tblEvents.timezone` (the only zone column an event has). The certificate is always checked unless a global administrator has deliberately decided otherwise: `MailRoutes::certificateCheck()` is the ONE place `mail.smtp.certCheck` is read (`on`, `off`, or `only:<fingerprint>`; anything else counts as `on`). A failure holds mail, and so does restoring a backup. The three ways past a failed check (one held message, accept only this certificate, switch off) are on the e-mail page, for a global administrator and SMTP only, through the one chain at the top of `web/_apps/admin/integrations/email.php`. **The e-mail page is the ONE place the mail server's name and the certificate setting are changed: the general Settings page refuses them (`MailRoutes::lockedInGeneralEditor()`).** Checking switches itself back on (`MailRoutes::recheckCertificate()`, asked at every run of the job whatever route is in use), and the standing warning is read from what is stored (`MailRoutes::standingWarning()`), never from a session and never from which route is in use; its `publicLine` names nobody and is what the health line shows. A send made for ONE message past the check must never clear the fault on record because it got through. **A fault on record for the SMTP mailbox stops mail only while SMTP is the route in use: `MailRoutes::standingProblem()` is the ONE place that says whether a fault stands in the way of mail, and `certificateOnRecord()` / `certificateProblem()` are for showing only.** Test: `tools/mail-queue-pages-selftest.php`. Sending a test, from the e-mail page or the Integrations page, is for a global administrator only: one mail route serves every organisation.
- **Several organisations (#502, #511).** `Site::preDetect()` is the ONE place the organisation of a request is decided, and `SiteGate` (called in `web/public_html/index.php` just before the router) is the ONE gate that refuses a signed-in person who is not a member. A page reached without the router (`error.php`) must ask `SiteGate::wouldStop()` itself. Do not write a second membership test in a page; `Site::organisationsFor()` is the one list of "your organisations". Test: `tools/site-modes-selftest.php`. **Every address a page writes is built by `Site::url()`** (#536): never a bare `/page`. In a script printed by PHP use `Router::urlForScript()`; in a script file, `Portal.url()`. `tools/audit-checks/check_site_aware_addresses.py` fails on a bare address anywhere it reads: the list of places still to sweep, `site_aware_addresses_pending.txt`, has been EMPTY since 13 October 2026 and nothing may be added to it (`--strict` fails if a line appears). `tools/site-addresses-selftest.php` reads the real pages. `tools/audit-checks/check_registered_addresses.py` fails on an address written out whole in `Site::url()`, `Router::url()`, `Router::urlForScript()` or `Portal.url()` that nothing answers (an `api/` address also needs its `api.{app}.{action}.enabled` switch seeded), and on two built addresses glued together; a new address the router answers by itself must be added to the check (#612). Three things the sweep taught: never join two built addresses (`Site::url('a') . Site::url('b')` prints the organisation's part twice); a page that ACCEPTS a come-back address by a pattern must accept it with the organisation's part too, and still refuse one that leaves the portal; and a class used by its short name needs its `use` line in THAT file, which only fails when the line runs. `Site::FIRST_PART_MODE_READY` STAYS `false`: the empty list is not enough. Still to settle, written up beside #536: the service worker, the installable app, the chat box on `/live`, the return address of a Microsoft or Google sign-in, and links in a push or e-mail made by a scheduled job (a job has no organisation's part; those belong to `PortalAddress`).
- **The release channel is decided in ONE place, `Portal\Core\Channel`** (#499, #500; owner, 12 October 2026): the `PORTAL_ENV` setting, else the `.channel` file beside `_core`, else a web folder named exactly `public_html`, `public_html_beta` or `public_html_dev` (that last one is the `dev` channel), else ASSUMED LIVE with errors hidden. Do not write a second channel check, do not read `DOCUMENT_ROOT`, and do not bring back "fail closed" (it was built first and locked members out). `PORTAL_WEBROOT` is the web folder: never build a path with `public_html` typed in. Test: `tools/channel-selftest.php`.
- **Secret settings (#597, #497).** Secret settings: `Portal\Core\SecretSettings` is the ONE place that decides what a stored secret means. Do not write a second mark comparison or a second decrypt. `$SETTINGS` holds real secrets: never decrypt a value taken from it, and never print one. Test: `tools/settings-secrets-selftest.php`. The key file: any length is a working key; only empty or "not a file" is no key. Never refuse a short one. `Captcha::verify()` takes the form's name as its second argument; there is no default. A form with no widget shows `Captcha::closedNotice()` itself. An address that is recorded or sent goes through `Logger::withoutJobToken()`.
- **Text messages held for quiet hours (#610).** `Portal\Core\Sms` holds every statement that moves a held text; `sendHeld()` is called only by `/cron/mail-queue`, after the mail step, and can never stop it. `Sms::NEVER_HELD` is the ONE list of what quiet hours never hold (a critical alert, an emergency notice: the owner, 13 October 2026). A held text is `status = 'queued'` with `errorMsg = 'quiet-hours'` and a use-by moment (`heldUseBy`, UTC); it is taken once, by filling `claimedAt` in one statement, and a claim is never undone. Never twice comes before always delivered. A text with no use-by moment is never sent. When a held text comes due, `Sms::whyNotThisPerson()` asks `Site::isOwnOrganisation()` whether the person may still be sent it: do not write a second membership test. The job page's answer stays a status word and plain numbers (the texts step's begin `texts`). A restore calls `Sms::holdRestored()`. The broadcast page's sentence is `Sms::broadcastSummary()`, and neither it nor the preferences page may say a held text will NOT be sent: `MailQueue::jobIsRunning()` means "the job ran in the last 15 minutes" and no more. Test: `tools/held-texts-selftest.php`, which reads the Notification preferences page.
- **The portal's own QR pictures (#623).** `Portal\Core\Qr` is the ONE place a QR picture is made (`QrEncoder` is its own helper); nothing under `web/` may ask another website to draw one, and `Totp::getQrUrl()`, which sent the authenticator key to `chart.googleapis.com`, is removed and must not come back. A picture of anything secret is shown with `Qr::dataUri()`, written into the page: a picture fetched from an address puts the text in that address, and addresses reach server logs and browser histories. The format marks were written back to front until 14 October 2026, so every picture the portal had ever drawn was unreadable by every reader and nothing noticed, because a wrong picture still draws — only reading one back catches it. Two pages made the same outside-request mistake and it was invisible on one and live on the other: a page that writes its own `<head>` (such as `calendar/public-landing.php`) sends no page rule, so its outside request really happens. **The rule had NO GUARD FOR JAVASCRIPT until 29 October 2026**, and that is how `web/public_html/assets/noticeboard/noticeboard.noeval.js` kept an `api.qrserver.com` fallback in `qrSrc()`. It is UNREACHABLE — `web/_apps/noticeboard/index.php` sets `NoticeboardHost.qrUrl`, which always returns the portal's own `api/noticeboard/qr` address, and `qrSrc()` takes that first — but nothing checked that, and the bundle is GENERATED from `dc-runtime`, which is NOT in this repository, so the fallback cannot be removed here. `tools/audit-checks/check_no_outside_qr_service.py` now guards both halves: no new outside QR service anywhere under `web/` (counted per file, so a second one in an already-listed file fails too), and **the `qrUrl` host function must still be there** — because the moment it goes, the fallback is live and nothing looks broken. **That guard was WRONG TWICE and planting caught both — keep this, it is the most useful thing here.** Version 1 searched for the plain string `qrUrl`, so renaming the function to `qrUrlRENAMED` left it as a PREFIX and the check passed on a guard that no longer existed. Version 2 required the name and the address as TWO INDEPENDENT patterns anywhere in the file, which passed on BOTH states it exists to catch — the function COMMENTED OUT (all the text still there) and the function kept but `return '';` (the address still in a note above it) — while going RED on `"qrUrl": function`, a quoted key any formatter may produce. **Missing the real fault and crying wolf, in one check.** Version 3 asks the question the guard is about: is there a LIVE `qrUrl` property whose body RETURNS the portal's own address, with comments stripped and the two facts in ONE place? It states plainly what it still cannot see (a QR host not on its list, and a host name built by concatenation — both measured). Tests: `tools/qr-selftest.php` with `tools/qr-test-reader.php`, which is proved against `qrencode`'s own pictures before it is pointed at ours.
- **What a shared link looks like, and the pre-push gate (#569).** `web/_core/templates/head-meta.php` is the ONE place the meta description, canonical address and Open Graph / Twitter tags are written. `header.php` requires it, and so does every page that draws its own `<head>`; set `$pageTitle` and, where the page can do better than the fallback, `$pageDescription`, before requiring it. **The canonical address and `og:url` are left out entirely while `PortalAddress::visitorCanChooseHost()` is true** — the fallback is a host name the requester chose, and a canonical built from it points search engines and preview services at a name an outsider picked. `og:image` only where the brand in use has the square raster on disk (two of seven; #656), and `twitter:card` is `summary`, not `summary_large_image`, because the picture is square. **Do not count the own-`<head>` pages by searching:** three reasonable searches gave 27, 15 and 12, because a doctype may sit inside an e-mail body built as a PHP string and because a page can draw its own head for the main view AND include `header.php` for an error branch. The list that matters is the exemption list in `tools/audit-checks/check_page_head_tags.py`, which names each exempt file and why. That check was WRONG THREE TIMES and planting caught each: string literals stripped across the whole file (an HTML attribute's quote paired with one inside PHP and swallowed the `<title>`); comments not stripped before pairing quotes (an apostrophe in `// don't` put every later quote out of step); and asking only whether the text `head-meta.php` appeared anywhere, which a commented-out require satisfied. **Its most valuable guard is on `header.php`'s own require**, because ordinary pages write no `<title>` of their own and a page-by-page check would never notice its deletion. Behaviour is proved by `tools/page-head-selftest.php` over real HTTP, whose part E plants the fault. **`.githooks/pre-push`** (turned on per clone by `tools/install-git-hooks.sh`, setting `core.hooksPath`; per repository on purpose — a global hooks folder overrides every other project's) runs `php -l` on the PHP the push range touches **as it stands on disk, not as committed** plus **every script in `tools/audit-checks/`** (the hook globs the folder, so a new check is picked up with no change to the hook), about nine seconds. **The count is deliberately not written down here** — it said 38 while there were 40, because two packages added checks without updating this line; count them with `ls tools/audit-checks/check_*.py | wc -l`. It does NOT run the self-tests or the migration harness, and note `tools/api-spec-check-selftest.py` lives in `tools/` and so is NOT globbed by the hook, and `git push --no-verify` skips it.
- **Offboarding really erases now, and the sweep has three traps (#569, migration 226).** `_apps/offboarding/do.php` **files** an erasure request through `GdprEraser::fileRequest($siteId, $userId, true)` — a fourth way a request gets confirmed, now written into that method's docblock — and never erases inline: there is ONE audited erasure path and a second would have to agree about 35 tables for ever. `offboarding.erasureMode` is `on_request` (the default; it waits on Admin, Erasure requests) or `after_undo_window` (the daily sweep runs it once `offboarding.undo_window_days` has passed). **Neither erases at the moment of offboarding**, because `rehire.php` exists; immediate erasure is #663. **`rehire.php` cancels a request still waiting** — without that the default mode left a live request to erase somebody who had returned. In `run_offboarding_erasure_sweep()` (`_apps/cron/_retention-sweep.php`): **join to the NEWEST offboarding row per person, never to any row** (an old row made a request filed today due tonight, bypassing the window — measured); **claim the request** with the same conditional `UPDATE … WHERE status = 'pending_review'` the staff page uses, or the Execute button stays armed mid-run and GdprEraser's own put-back and still-going updates match nothing; and **never log the fault's own words**, which can quote the value being erased. It cannot honour a per-organisation mode: `tblOffboarding` has no organisation column. The form offers TWO options, not three, because the portal does one kind of erasure. **Nothing yet covers this end to end over HTTP** — the SQL was proved against a real database and the rest was read, so a page-level test is still owed.
- **Removing a preference switch is two changes (#569, #662, #667).** `notifications-save.php` **rebuilds** `tblUsers.notifyPrefs` from its allowed-key list and writes the result over the whole column. Hide a switch and leave its key listed, and the next save rewrites that preference to off for everybody one at a time; take the key off the list and the stored value is DROPPED at the next save — not "left alone". Acceptable only while nothing reads it. The same replacing behaviour is why that page and the smaller card on `/account` overwrite each other's keys (#667). **And removing a feature must not remove the test guarding a past fault:** deleting that card broke section F of `go-live-button-selftest.php`, the only guard for #612, so the form was kept with its still-wired switch and section F's `count($storedNow) >= 15` became a named list of keys — stricter than a count, which is exactly what a deliberate change to the number of preferences invalidates. `||` is **logical OR** in MySQL, not concatenation. A **`disabled` control is not submitted**, so disabling the GoCardless option would have made Save switch the provider to Stripe silently.
- **The PHP version is judged in ONE place (#640).** `Portal\Core\PhpRuntime` is the ONE place that decides anything about the PHP version: whether the portal can run on it (`MIN_PHP`, 8.4.0) and whether it is older than the release that fixed the October 2026 flaws (CVE-2026-109618 / CVE-2026-109619, "PHP2Shell", fixed 21 October 2026 in 8.5.12 and 8.4.27). The installer's checks screen, the admin dashboard, Server Information and the health page all ask it — **do not write a second version check; change the constants in that file.** `support` and `security` are returned SEPARATELY and must stay so: 8.3.36 carries the fix and is still too old to run the portal. **A version number cannot tell a vendor-patched build from an unpatched one**, so the wording is always "older than the release that fixed it — ask your hosting company" and NEVER "you are vulnerable" (section G of the test fails on that wording), and an unpatched PHP never blocks an installation — that decision is the one constant `BLOCK_INSTALL_WHEN_UNPATCHED` (`false`), read by the installer. **A FINISHED release of a line newer than `NEWEST_KNOWN_SERIES` counts as fixed; a PRE-RELEASE of one does NOT — it is "cannot tell".** That distinction is not a nicety: the first version of this said any newer line must be fixed "because it was published later", and PHP 8.6 disproved it (pre-releases from 2 July 2026, RC3 on 8 October, both BEFORE the 21 October fix), so 8.6.0RC3 was told it was safe. 8.6 is pinned at `8.6.0RC4` and the comparison keeps the pre-release marker, because `version_compare` ranks RC below the release — never compare a tidied three-part number. Going stale must make this less informative, never wrong. Pin every fixed-release number by hand in the test: deriving "one below" from the constant proves only that the constant agrees with itself, and a wrong number passed 66 checks. The tripwire must match `phpversion()` as well as `PHP_VERSION`. Tests: `tools/php-runtime-selftest.php` (no database, which also proves the class has no dependencies) and `tools/audit-checks/check_php_version_one_place.py`.
- **The money pages: all four writers lock the claim, and a claim is editable only before anybody decides (#647, #651).** The FOUR writers of an expense claim — `approve/save.php`, `withdraw/save.php`, `treasury/save.php` and `edit/save.php` — each open a transaction, re-fetch the claim with `SELECT … FOR UPDATE`, and refuse when what they expected has changed. The payment page was the ONLY one without that until 28 October 2026, so **a claim could be paid twice**: two treasurers, or one double-click, each wrote a payment reference, each set `Reimbursed`, each sent the claimant an e-mail. A unique key on `tblExpenseClaimPayments(claimID)` is deliberately NOT the fix — `payAmount`'s own comment promises partial payments (#650). **`Portal\Core\ExpenseRules::whyNotEditable()` is the ONE place that decides whether a claim may be changed**, and it takes FACTS rather than a connection because the handler asks it TWICE: once cheaply, once on facts read inside the lock. Do not write a second status test in a page. The rule is "a claim NOBODY HAS DECIDED ON", never "a Pending claim": a claim in a department needing several approvals STAYS Pending after the first approval, so editing it then would leave an approval sitting against figures nobody approved. `$approvalCount !== 0`, never `> 0` — `approvalCount()` answers -1 when it could not read, and "we could not tell" must never become "nobody has, go ahead". Ownership is answered BEFORE the status, so a stranger is never told a claim exists or where it has got to. **The HTTP harness cannot test the payment race**: `access-pages-selftest.php` runs the built-in server with one worker (`unset($env['PHP_CLI_SERVER_WORKERS'])`), so two requests never overlap and such a test PASSES ON THE BROKEN CODE. `ExpenseRules::paymentReferences()` is the ONE place payment references are read — it was inline on the page, and the page's test held its own COPY, so a change to the page alone passed every check and would have written an EMPTY payment reference against a real claim. **A test holding its own copy of the logic it tests is not testing anything.** Three more traps, all found by planting and all worth knowing: a check that searches a page for a CALL can be satisfied by an HTML COMMENT mentioning it (`codeOnly()` keeps inline HTML on purpose, so the `<script>` check can fail — use `phpOnly()` when you mean code); a brace-matching walk must step over STRING LITERALS, or one `{` in a log message swallows the rest of the function and the check passes on a removed gate; and `substr_count` of a shared SQL prefix cannot tell three statements apart, so name each one. Tests: `tools/expense-payment-race-selftest.php` (two real connections), `tools/expense-edit-selftest.php` (section D walks the fraud sequence at the level of the rule), `tools/expense-rules-selftest.php`. **And run the audit scripts and READ THEM: `check_schema_seed_parity.py` names a missing migration mark while exiting 0, which is how migration 223 nearly shipped unrecorded.** The technical record is DEV_NOTES.md, "The four money writers all lock the claim".
- **A unique key is POWERLESS over a column that can be empty (#487, migration 224).** In MySQL and MariaDB a unique key STANDS ASIDE for any row where any part of it is empty — an empty value is not even equal to itself — so the key is a rule only for rows that fill in every one of its columns. **`INSERT IGNORE` and `ON DUPLICATE KEY UPDATE` lean entirely on the key**, so where it stands aside they stop meaning "do nothing the second time" and start meaning "insert another row". That is how `tblEventAttendance` came to hold duplicate rows: `uq_attendance_unique` covered `(eventID, userID, walkinName, dayDate)`, a row names its person by EITHER `userID` OR `walkinName`, so no row ever filled in all four and the key refused NOTHING, for any row, ever — under a comment reading "idempotent via unique key", which stated the guarantee the schema had forgotten to implement (the #645 shape). **Do NOT say it "counted people twice in every figure": three code comments and two documents claimed that and it was false, caught by the independent check.** The grid keys its display by person and day and lists walk-ins with `SELECT DISTINCT`, and the two `Discipleship` joins feed an `INSERT IGNORE` whose key has no empty part, so nothing double-counted and nothing on screen was wrong. The cost was a duplicated "who marked this" trail and a figure that WOULD be wrong the moment anything counted rows rather than distinct people — which is the reason to fix it rather than trust every future reader to de-duplicate. The cure is a worked-out column, as `tblSettings`/`tblEmailTemplates` already do with `siteScope` = `COALESCE(siteID, -1)`; `VIRTUAL`, never `STORED`, because MySQL refuses a STORED generated column on a table carrying an ON DELETE CASCADE foreign key (ERROR 1215). **`attendeeRef` is deliberately EMPTY when the person is unknown, and that is the point, not an oversight:** `GdprEraser` ANONYMISES attendance rather than deleting it so the aggregate survives, two erased people cannot be told apart, and a key that collapsed them would delete the counts erasure exists to preserve — so the de-duplication's `WHERE` clause is load-bearing. Fourteen of the fifteen such keys in the schema are RIGHT, including every token column, and `tblWebhookEvent` is deliberately left alone (coalescing its reference would make every unparseable webhook collide and be silently dropped, losing audit rows; the money path is guarded by `WHERE status = 'pending'` instead). **Read these out of a loaded database, never from the SQL: `information_schema` calls a generated column nullable because that is DECLARED rather than worked out, so `siteScope` looks like a fault and is not.** The query, the full fifteen-key table and the one question to ask of each are in DEV_NOTES.md, "A unique key is powerless over a column that can be empty".
- **Scripts written into a page (#625).** Every inline script carries the answer's own nonce from `App::cspNonce()`; a script or an element handler without it is REFUSED by the browser and nothing is logged, so the page looks finished and the control does nothing. Never add an element handler (`onclick`, `onchange`): use `data-portal-submit-on-change` / `data-portal-select-on-click`, read by `web/public_html/assets/js/portal-actions.js`, or a listener carrying the nonce. The tripwire is `tools/audit-checks/check_inline_scripts_have_nonce.py` (its exemption list is explained file by file, and its header names the three shapes it cannot see); the page test is `tools/page-scripts-selftest.php`. Never loosen the rule itself to make a script run. **A page that writes more than once without reloading must be handed the replacement anti-forgery token and must keep it**, because `Auth::verifyCsrf()` replaces it on every acceptance — the house way is `ApiAuth::requireWrite()` with `ApiResponse`, whose `meta.csrfToken` exists for this; return it on the success AND on every refusal reached after the rotation. Such a page sends ONE request at a time (two in flight can never both succeed), arms its deadline across the BODY read and not just the wait for headers, never lets its own polling pile up, and SAYS when a command fails — and says only what is known, never "not sent" when the answer was merely lost.
- **The activity log and sessions (#622).** `Logger::sessionFingerprint()` is the ONE thing stored in place of a session identifier and `Logger::sessionFacts()` the ONE copy of a session a record keeps (six named facts; add one there on purpose, never "everything except a list"). Never store `session_id()` or the whole `$_SESSION`: `tools/audit-checks/check_session_id_not_stored.py` fails on it. The fingerprint is plain SHA-256 because the clean-up must make the same value in SQL, and the monthly sign-ins report joins on that column. Records written before #622 are cleaned by `Portal\Core\ActivityLogCleaner`, in pieces, from the Upgrade page; it is NOT part of the upgrade gate and never closes the portal. Migration 220 deletes old backup codes once, and its "already done" mark is its own row in `tblMigrations`, which the install script writes only where no backup code exists. **A one-table restore of `tblMigrations`, `tblTotpBackupCodes` or `tblSettings` moves that mark and can lose data — a whole-database restore is safe, and ANY migration whose "once" test is its own row has the same exposure.** Test: `tools/activity-log-selftest.php`, which reads the records back from the database rather than believing the cleaner, and refuses to search the log for an empty text.
- **Who may be sent a text, and a one-table restore (#610, #622).** `Sms::whyNotThisPerson()` is the ONE place that decides whether a person may be sent a text, asked at BOTH moments — by `send()` for a text going now (AFTER the decision to hold, never before) and by `sendHeld()` when a held text comes due. It answers a REASON, and `heldPersonWords()` / `atOncePersonWords()` put that reason into the right words; never record the reason itself. JUDGE A PERSON AT THE MOMENT THEIR TEXT WOULD GO: asking before the hold was built first and silently refused a text for anybody suspended and reinstated inside two days. A refused text carries no "sent at", and `record()` is the ONE place that keeps the row and drops the NAME when the database refuses a person who has gone. Test: `tools/held-texts-selftest.php`. **`DbBackup::WHOLE_DATABASE_ONLY` is the ONE list of tables a restore of ONE table refuses** (`tblMigrations`, `tblTotpBackupCodes`, `tblSettings`), compared only by `isWholeDatabaseOnly()` and worded only by `wholeDatabaseOnlyReason()`, ignoring capitals because `lower_case_table_names` is a server setting; the Backup page asks both and keeps no copy. A WHOLE-database restore is untouched and is the supported route. Before adding a table, ask whether the rest of the database would still make sense beside it. Tests: `tools/backup-restore-selftest.php`, `tools/backup-page-selftest.php`.
- **Erasure and the activity log (#626).** Every activity record must NAME ITS PERSON in `userID`:
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
  `tools/gdpr-coverage-selftest.php` names the 35 tables still leaving a named column (#635): nothing may be added
  to it. Tests: `tools/activity-log-selftest.php`, section 17 of `tools/gdpr-eraser-selftest.php`,
  `tools/gdpr-coverage-selftest.php`, phases 1 and 7 of the migration harness.
- **Every two-factor gate takes the sign-in away FIRST (27 October 2026).** There are FOUR gates that demote a session to "waiting for a code" — the two sign-in callbacks in `Auth.php`, `_apps/auth/login/index.php` and `_apps/auth/login/webauthn.php` — and in each, `unset($_SESSION['user_id'], $_SESSION['2fa_passed'])` comes BEFORE anything else, `login_redirect` included. All four had it the other way round: a request chooses the TYPE of what it sends as well as the value, so `?redirect[]=x` made `Auth::safeRedirectUrl()` throw while `user_id` was still set, PHP wrote the session at shutdown, and the next request saw somebody signed in who was never asked for a code. **On the password form that needed nothing but the person's password.** `Auth::safeRedirectUrl()` therefore takes `mixed` and refuses a non-string with the fallback: never narrow it back to `string`, and never put a call that can throw between granting an identity and the check that earns it. A gate found by searching for `$_GET` will be missed — the passkey path reads the posted JSON. Checks, and BOTH are needed because neither catches the other's half: `tools/audit-checks/check_2fa_gate_order.py` (the order) and section Z of `tools/two-step-selftest.php` (the behaviour). Reversing the order alone is invisible to the self-test; that was measured, not assumed.
- **An OAuth `state` must be a non-empty STORED value (27 October 2026).** Both callbacks compared `$_SESSION['oauth_state'] ?? ''` with `$_GET['state'] ?? ''`, so an address carrying an empty `state=` passed when no sign-in had been started: `hash_equals('', '')` is true. That signed a visitor into whoever's authorization code was supplied. The stored value is now required to be non-empty before anything is compared, and a non-string is refused so `?state[]=x` cannot reach `hash_equals()`. Do not simplify that condition back.
- **Two-factor authentication (#613).** `auth/2fa/setup.php` is the ONE place backup codes are made, and it shows them only in the answer to the request that made them: never put a code, or the authenticator key, readable in `$_SESSION`. A code is single-use by `… AND isUsed = 0` plus "one row changed" in `auth/2fa/verify.php`, and is looked for among the person's own codes only. The old set is removed row by row after locking the person's own account row, never by `DELETE … WHERE userID = ?` (two people at once deadlock). Test: `tools/two-step-selftest.php`, alone on its server.
- **The installer asks for an ownership code (#499, #500).** `web/_install/owner_code.php` is the ONE place a code is written, counted and compared (`hash_equals()`); nothing is shown or acted on without it, and the finish asks again. Read the code file only through `installCodeRead()` (it waits for the writer's lock). The installer refuses when opened directly, and when the address has the web folder's name in it. **A choice for a database that is not empty ("carry on" or "wipe") belongs to ONE database:** read it only through `installChoiceHeld()` in `web/_install/db_state.php`, never from the session. The private folders' "refuse everything" file and the words for the channel are in `web/_install/install_common.php`, shared with the upgrade page. A missing `.channel` file never stops an install. Test: `tools/installer-selftest.php`.
- **The installation package and upgrades (#499).** `tools/package/build.sh` makes the package from git, `check-package.py` guards it, and `docs/INSTALL.md` (shipped as `INSTALL.md`) is the customer's guide to installing AND upgrading. **The portal is closed for an upgrade only while a database upgrade is really waiting:** that is decided in ONE place, `Maintenance::isActive()`. **The portal never records the version by itself**: `Maintenance::recordVersion()` is the one statement that records it and only the Upgrade page's button calls it; until then every request asks `Migrator::waiting()` once (about half a millisecond), and with the versions equal nothing is asked. A page that only asks uses `Maintenance::versionIsBehind()` or `upgradeNotice()`. Admin, Migrations runs nothing while the version is behind, and is for a global administrator only. The recorded version is read from the installation's own row only: start-up passes over one organisation's row of that name, and Admin, Settings refuses the setting (`Maintenance::lockedInGeneralEditor()`). When the portal cannot tell, `Migrator::waiting()` gives the cause. **A package carries NO `.channel` file:** the installer's finish writes it from `VERSION.txt`, only when there is none (`installWriteChannelFile()`), so an upgrade can never replace it. Never commit `web/.channel`. The domain guard's names come from `OWN_DOMAINS` in `tools/audit-checks/check_no_hardcoded_domains.py`: that is the ONE list. Tests: `tools/upgrade-pages-selftest.php`, `tools/package/package-selftest.sh`.
- **Time zones (#604).** `Site::timezone()` is the ONE place that answers an organisation's zone, and `Site::isZoneName()` the one test of what a zone name is. Do not read `tblSites.timezone` anywhere else, and do not bring back a `site.timezone` setting. `Site::zoneFor()` gives the zone to read a stored event time in. A stored event time is a reading of the event's own clock: never pass it through `strtotime()`/`date()`. Every `INSERT INTO tblEvents` names `timezone` (the column has no default). Migration 217's mark (`site.timezoneKeptAt`) is written by the install script only into a database that never held the old arrangement, so the installer's "carry on" choice keeps every organisation's zone. A column removed by a migration goes on `DbBackup::REMOVED_COLUMNS`. Giving retention deliberately does NOT follow the organisation's zone. Test: `tools/time-zones-selftest.php`.
- **The "We're live" push (#612).** In `web/_apps/admin/livestream/index.php` the action `notify_live` is the ONE action open to an event's coordinator: the posted event decides, and must belong to the organisation (`HostConsole::eventBelongsToSite()` and `Auth::isCoordinatorOf()`, the same two calls the Host Console uses to draw the button). `WebPush::sendToChannel()` addresses only a signed-in person's device, and `api/push/subscribe` refuses a request that is not signed in. The counts on Admin, Integrations, Push copy the sender's rule: change both together. Test: `tools/go-live-button-selftest.php`.
- **A download that is not whole must never be trusted to remove anything (#645).** `IcsReader::parse()`'s `complete` flag is the ONE thing `FeedImporter` asks before it lets a refresh remove events, and its own comment says so. It is false unless the calendar started, the calendar ended, nothing was dropped, we did not stop early, AND **nothing was left open** — that last term was missing until 28 October 2026, so a download cut off INSIDE an event was called whole and the importer removed every event after the cut. The test is `count($stack) > 0`, NOT `$current !== null`: the stack catches an unclosed `VALARM` inside an event that ended, and `END` pops only on a match so one unclosed nested block keeps everything above it on the stack. **A comment claimed the flag was "false anyway" — it stated the guarantee the code had forgotten to implement**, which is why the fault survived. Check I7d guards the opposite direction: if this rule over-reaches, the portal silently stops tidying any calendar, for ever. **A repeat landing in the hour that does not exist moves FORWARD, keeps its exact length and still counts against `COUNT`** (so `COUNT=3` gives three dates) — right, deliberate, matching Google, Apple and Outlook, and now written beside `generateStarts()` with check C13 on it; changing it is the owner's decision, not a tidy-up. **The clocks going BACK is now settled too (#653, 28 October 2026).** `IcsReader::laterOfARepeatedReading()` is the ONE place that decides what a reading in the hour that happens TWICE means: the LATEST moment carrying it, worked out from the zone's own transitions. **Never go back to `new DateTimeImmutable($text, $zone)`: PHP does NOT give the later one** — it gives standard time in Europe and Australasia but DAYLIGHT time across the Americas, so that version was a no-op in New York, Chicago, Los Angeles, Havana, Santiago and the Azores and #653 stayed live there, with every check passing because every fixture was London. Testing "a zone that really changes" was not enough; it must be one that resolves THE OTHER WAY (I34i, plus Australia/Lord_Howe for a thirty-minute change). **Nor is `setTimestamp()` safe for building a candidate: on a zone-carrying object PHP re-resolves through the wall clock and hands back a DIFFERENT instant when the one asked for is inside a repeated hour, so it snapped back to PHP's pick in 81 zones — every one a PERMANENT base-offset move, standard time both sides (I34j, America/Metlakatla 2015).** Build such a moment as `new DateTimeImmutable('@' . $ts)` then `setTimezone()`. This ONE fix shipped as a no-op THREE times, each time with every check green; the committed checks pin four shapes (ordinary change, resolves-the-other-way, thirty-minute, permanent move) and the all-zone sweep that found the third is NOT committed. It is called from `buildOccurrence()` and **must stay there** — the recurrence key is the start turned into UTC and is STORED as `externalRecurrenceKey`, so resolving any earlier renames every such date and the importer deletes and re-adds it (check I34b). **It takes the zone the reading was WRITTEN in, never the organisation's:** a time ending in `Z` is an instant, not a reading, and the first version judged it in the organisation's zone and moved a real event an hour late — every reader check passed and only part G of `feed-importer-selftest.php` caught it (now I34h, the sole catcher). The symptom was wider than the issue said: a FOUR-hour series read as THREE: zero length is just the one-hour case. **Two leftovers that look alike and are not:** a date whose END falls in the repeated hour LOOKS an hour short but the stored data is right and reads back correctly (I34f, appearance only); a date whose FILE stated instants in `Z` can read end-before-start and its true length is NOT recoverable (I34h, a real limit needing a stored offset). Tests: I7b, I7c, I7d, C13 and I34–I34h of `tools/ics-reader-selftest.php`. **Two lessons about CHECKS from this package, both found by planting:** I7d guards the over-reach direction and guarded NOTHING, because not one fixture that existed before this work held a correctly closed nested block — no `END:VALARM`, no `VTIMEZONE` anywhere — so narrowing the stack pop to `VEVENT`/`VCALENDAR` passed all 345 checks while nearly every real calendar became "incomplete" (fixed by `nested-blocks-closed.ics`); **when a check exists to prove something ORDINARY still works, check that any fixture is actually ordinary.** And C13's comment claimed it pinned the exact-versus-nominal duration rule when its occurrences never cross a transition — I26 and I29 are what pin that. An overstated guarantee is worse than none.
- **Transcription, and the reserved word that could not save a transcript (#276, migration 229).** `Portal\Core\Transcription` is the ONE place anything about transcription is decided: `whyNotQueueable()` answers whether a recording may be queued (the page draws the button from it, the handler accepts from it — never a second test in a page), `refusalWords()` and `statusWords()` are the only words, `capFrom()` is the monthly ceiling, and `queue()` / `processQueue()` are the only writers of the queue state. **`fullText` IS THE RESERVED WORD `FULLTEXT`** — MySQL ignores case in keywords, so a bare `fullText = ?` does not parse; it must be qualified (`t.fullText`) or quoted. The statement that SAVES a finished transcript was written bare and therefore could NEVER RUN, throwing after the outside company had been called and charged; nothing noticed because no page could queue a recording in the first place. **Ask of any camel-case column whether its name is a keyword: `php -l` cannot see it and no audit script here can either.** The two writers of the queue state BOTH take the RECORDING's row lock (`FOR UPDATE`) — the transcript row may not exist yet — and the provider call is made only after the transaction is committed, never inside it. Refusing a `processing` row outright was the WRONG fix and wedged any recording whose request the host killed, so the refusal is "processing AND started recently" (`startedAt`, `STUCK_AFTER_MINUTES` = 120, on the server's own clock to match `queuedAt`). **One API key pays for every organisation**, so everything is scoped to one organisation and the budget follows #668: the installation's figure caps the organisation's, read from `InstallationSettings::layer()`. **Nothing drains the queue on its own (#672)** — do not describe transcription as automatic. Three traps worth keeping: a `<form>` inside a `<form>` is DROPPED by the parser and its button is owned by the outer form (that is how "Process next" saved the settings and transcribed nothing — ask the PARSED document with `Dom\HTMLDocument`, never the markup); `recordings/view.php` had NO flash block and nothing here prints one centrally (141 pages print their own), so a handler's message is swallowed unless the page prints it; and a check searching for the text of a guard passes when `&& false` is added to its condition, so pin the WHOLE condition. Test: `tools/transcription-pages-selftest.php` (153 checks, section Q states plainly what a single-worker harness cannot prove; section S guards #674). **Two gates, not one:** `recordings/transcribe` AND `admin/transcription/run` must EACH ask `providerIsReady()` — the run page had only the button's `disabled`, and a posted request claimed every waiting row and marked the lot FAILED. **`processQueue()` must never re-throw** (one unwritable row gave a bare 500 and abandoned the rest). And a check that looks for the TEXT of a guard stays green when `&& false` is added to its condition — pin the WHOLE condition; two checks here failed that way and planting caught both.
- **The API description is checked against the code (#641).** `web/_core/api-spec.json` is what a customer's developer reads, and until 30 October 2026 **nothing had ever compared it with the handlers** — 17 reachable endpoints were absent from it, two more were described only under their `api/v1/` address, and three operations denied a legacy address that works (19 addresses documented as a result). `tools/audit-checks/check_api_spec_matches_code.py` is now a hard gate. **It takes reachability from the router's own `^[a-z0-9\-]+$` pattern, so there is NO exemption list for helper files** — `_coerce.php`'s leading underscore is what both makes it unreachable and excludes it, and a hand-written list would need a line per helper and go red the day somebody forgot. It resolves the `api/v1/` facade through the four tables in `ApiRouter` (comments stripped first, so a commented-out resource is not read as live) and counts a `**Legacy alias:**` line as documentation. **That last part is NOT load-bearing, and the first version of its comment claimed it was** — measured, `aliases - targets` is EMPTY, because an alias sits inside the v1 operation already resolving to the same pair, so removing the parse leaves the check green. The "28 versus 19" figure that justified it came from a different model entirely and was false. The facade resolution IS load-bearing: without it `noticeboard/save` reads as a missing handler. Its FOUR named lists (`UNREACHABLE_ON_PURPOSE`, `UNSEEDED_ON_PURPOSE`, `ORPHAN_SEEDS_ON_PURPOSE`, `PAGE_ROUTED_ON_PURPOSE`) **fail BOTH ways** — an unlisted fault, and a listed entry that has stopped being true, which is how the migration harness's exceptions went stale unnoticed. **Two of those four are now EMPTY and the reason is the most useful thing here (#675, 30 October 2026).** `seeded_switches()` was ONE REGEX over `full_schema.sql`, which both seeds AND DELETES settings — it folds old migrations in — so a key written inside a `DELETE` counted as a seed. Four `api.expenses.*` rows were reported as orphan seeds that **no installation has held since migration 186**; loading the install script into MySQL 8.0.36 gives exactly three `api.expenses.*` rows. **Crying wolf was the harmless half. The dangerous half is check G:** a handler whose switch is seeded and then deleted is 403 to everybody, and the old reading called it seeded and PASSED — measured by appending a `DELETE` of `api.events.create.enabled` and watching the old regex still answer True. The two statement kinds are now applied IN FILE ORDER, and the function states what it still cannot see (a `DELETE` by `LIKE`, by subquery, or from application code). **And a file in an `api` folder is NOT necessarily an API endpoint:** `tblRoutes` holds `expenses/api/export` → `expenses/api/export.php`, and `Router::dispatch()` resolves a target under `PORTAL_APPS` first, so that address reaches the same file with **NEITHER** the `api.{app}.{action}.enabled` row **NOR** the app's API-folder switch in front of it — two fewer gates than the folder name implies. New **check I** (`PAGE_ROUTED_ON_PURPOSE`) names every such file; there is exactly one. `api.expenses.export.enabled` stays UNSEEDED on purpose (owner, 30 October 2026): seeding it publishes a second address for the same CSV that a bearer token still cannot use. **`seeded_switches()` was then WRONG AGAIN and the independent check caught it:** it ended a `DELETE` at the next `;` character, so a semicolon inside the statement's own `--` comment ended the span early, the key fell outside it and was read as a SEED — the SILENT direction, on the exact fault the rewrite existed to catch, and its docstring had claimed that case was merely over-reporting. One character decided it. `read_install_script()` now scans the file once, blanking comments and tracking quoted literals, which also fixed a second finding: a route target left quoted in a comment was being read as a live route. **`tools/api-spec-check-selftest.py` is the test OF both checks** — 33 plants, each required to be caught by the expected check AND BY NOTHING ELSE (comparing the whole set found two impure plants a looser test had hidden), plus 35 cases run against the schema validator directly in both directions, because an end-to-end plant cannot reach a keyword the file does not happen to use. Wired as its own hard gate because `and False` on any of eight conditions across the two checks leaves the check green — measured. **What it cannot do: judge whether a description is TRUE** (a documented parameter the handler ignores passes), or work out which verbs a handler accepts — `PORTAL_API_V1` relaxes `requireMethod()` at run time. **Five endpoints answer with their own flat JSON, not the portal's envelope** (`worship/state`, `worship/advance`, `livestream/ping`, `push/subscribe`, `push/unsubscribe`), and **`worship/advance`, `push/subscribe` and `push/unsubscribe`** return the replacement form token as a **top-level** field, never `meta.csrfToken` — a console drawn once and posting repeatedly must carry it forward or every control works once. **`livestream/ping` asks for no form token and returns none**, so it is in the flat-JSON five but not in that trio; getting this wrong once already put two memory files out of step. **`web/_core/api-spec.schema.json` is the shape the description must have**, executed by `tools/audit-checks/check_api_spec_schema.py` (a hard gate). Its validator is **hand-written and dependency-free** because there is no JSON Schema library on this machine and a check that cannot run before a push is the #646 failure again; `UNDERSTOOD` in that file is the keyword list, and **the check FAILS when the schema uses a keyword it cannot see**, because a keyword it ignores would make that part of the schema decorative. Every property and parameter now carries a `description`, and writing them found what matters: **asset money is in PENCE and expense money is in POUNDS**, many limits **quietly shorten or clamp rather than refuse**, `licenseKey` is **write-only and cannot be cleared**, an attendance breakdown and the noticeboard poster list are **whole-state** (an empty list wipes them), and leadership's `assignmentID` is the assignment and not the role. `PaginatedListResponse` describes a shape **no handler returns** and now says so. #676 (the events `status` allow-list not matching the column) is FIXED in `28f56cb` with migration 230, and it was FIVE writers not three — `calendar/manage/save.php` had no allow-list at all and `calendar/submit-save.php` supplies the value as a literal inside its INSERT. #675 is DONE and all three of its headline claims were wrong — **with one qualifier that matters: its narrower wording, that the `api/expenses/export` ADDRESS has never worked, is TRUE and still is on purpose.** What was wrong is the conclusion drawn from it: `expenses/export` answers a real CSV at `expenses/api/export`, a live button links to it, and the four "orphan switches" do not exist. What WAS wrong is that the Export button was drawn for everybody who could open `/expenses/approve` — a page with no gate beyond sign-in — and gave four of six account kinds a bare `Forbidden`. `ExpenseRules::mayExportClaims()` is now the ONE place that decides, asked by the button AND the handler; `tblUserDepts.isApprover` (one department) is NOT the `approver` ROLE (the whole organisation) and is deliberately not enough — widening it scoped is #681. Behaviour is pinned by section L2 of `tools/access-pages-selftest.php`.
- **A form that SAVES installation-wide must not PREFILL from the merged array (#674, #668).** `$SETTINGS` is already merged by the time a page runs, so a box filled from `App::settings()` shows the OPEN ORGANISATION's value while a Save writing `siteID IS NULL` writes the INSTALLATION's. **A global administrator opens the page while in an organisation that has its own row, sees it, presses Save without touching anything, and it is now everyone's — no mistake required.** Read such a field from `InstallationSettings::layer()`. **Fifteen admin forms had this shape**; `tools/audit-checks/check_install_wide_prefill.py` is a hard gate and the fourteen still to fix are on its pending list, so no NEW page can join them (`--strict` fails while any remain, and a listed page that no longer has the fault also fails). Two things about that check: it requires a `<form>`, because a runner, callback or webhook in the same folder reads the merged value *because it needs the effective one* (five such files would otherwise be false positives out of nineteen); and it skips a page that reads the layer **at all**, because **it reported the #674 fix itself as a fault** before that term existed — a fixed page often reads both deliberately, one for its boxes and one to REPORT a difference. The cost, in its header: a page fixed for one field and not another passes. Section S of `tools/transcription-pages-selftest.php` demonstrates the harm rather than arguing it — the old read put back changes the installation's provider on a Save that touched nothing.
- **One organisation's address is NOT every organisation's (#677, migration 231).** `admin/settings/organisation/save.php` writes at TWO levels and `$upsert()` takes the level as an argument **with no default**: the nine `org.*` keys and `portal.support.email` go to the organisation's own row, `site.url` alone goes installation-wide (#500) and is a global administrator's. **The support e-mail falls back to the installation's**, which is why migration 231 deliberately does NOT copy that row down — copying it would mean changing it later reached nobody. **An empty box DELETES the organisation's row:** the merge falls back only where there is NO row, so a stored `''` wins and would mask the installation's address for ever. `Mailer` needed no change FOR A WEB REQUEST — `renderBase()` runs BEFORE `MailQueue::add()`, so a queued copy keeps the sending request's footer — but **a scheduled job sends across every organisation with `$SETTINGS` merged once, so one organisation's support address reaches another's members (#683)**, and no page sets the INSTALLATION's value any more (#684). **Three faults of mine here, each caught by a test and none by reading:** the box prefilled from the MERGED array while Save wrote the organisation's row, so one press of Save on an untouched form pinned the installation's value as the organisation's own — #674 mirrored, and `check_install_wide_prefill.py` cannot see that direction; the inherited value was read from `$SETTINGS_INSTALLATION`, which **a page does not inherit** (only `$mysqli` and `$SETTINGS`), so the help text told administrators the opposite of the truth; and **I did not run `portal-address-selftest.php`, which covers this exact card and runs on every pull request — five of its 93 checks were red** while the package's own new test passed throughout. **Two traps, both real.** First, **ask whether a field ARRIVED, never what it contains**: the page disables the portal-address box for an ordinary administrator, a disabled control is not submitted, and the first version wrote `?? ''` anyway — which `PortalAddress::check()` reads as "no address", so saving a SUPPORT address **cleared `site.url` for every organisation** (the #667 shape, twice in a month). Second, **the check went blind to its own fix**: binding the level as a parameter stopped `check_install_wide_prefill.py` matching the literal `NULL` it looks for, so the whole folder stopped being examined — and binding the level is exactly what the fourteen pages still on its pending list should do, so the list would have emptied while the coverage did. Such savers are now named in `LEVEL_CHOSEN_AT_RUN_TIME`, failing both ways; measured first, only two files have that shape. Be precise about the old harm: **nothing outside that page reads the nine `org.*` keys**, so no address was ever shown to the wrong people — the support e-mail was the one that really was shared. Test: section O of `tools/access-pages-selftest.php`.
- **A LIMIT is capped by the installation, and `Portal\Core\SettingLimit` is the ONE general place that decides it (#668 part 1).** Three tiers, each able to tighten the one above and none to loosen it: a hard maximum in code, then the installation, then the organisation. **The rule is `min(merged, installation)` and needs NO query** to discover whether an organisation has its own row — the merged value already IS the organisation's own where it has one and the installation's where it has not; the independent check tried to break that and could not. `EventRecurrence::limitsFrom()` stays as the two-limit reader for repeating events. **ZERO IS THE TRAP AND IT WAS WRONG IN BOTH DIRECTIONS:** where 0 means "for ever" or "no limit" it is the LARGEST value, not the smallest, so as a number it passes every limit. Converting it in the HANDLER was worse than not converting — an installation that chose "for ever" refused EVERY organisation save including a tighter one (and blocked the page's other two settings, because the refusal exits before all three writes), while an installation that chose 3650 let an organisation store 0 and keep data FOR EVER. Pass `$zeroIsLargest` and let the class read it at BOTH levels; the rule is **"for ever" is allowed only when the installation itself allows for ever**, which is not expressible as arithmetic. A page must ask TWO questions (`installationAllows()` and `installationAllowsForEver()`), because `min="0"` offers for-ever whatever the max says, and must never prefill a value its own `max` forbids — the browser then refuses to submit the whole form. **The live breach fixed:** `/admin/settings/attendance` took 0–3650 days per organisation with no cap, so attendance personal data could be kept ten years on an installation intending ninety. It refuses with the reason and RECORDS the refusal. **#685** carries what part 1 does not fix: an organisation that ALREADY stored more keeps it, because the clear-out job reads the uncapped value. **The issue's 31-key list is a starting point, not the answer:** four shapes no key-name grep can see — nested-array reads, a `LIKE 'mail.queue.%'` query, keys behind CONSTANTS (how `attend.detailRetentionDays` hid), and four settings seeded but read nowhere. Only ONE dedicated page names a limit key directly, so part 2's tripwire must resolve constants. Test: `tools/setting-limit-selftest.php` (47 checks). **Its section D passed on FOUR disabled gates** — `if (false)`, `&& false`, a refusal that logs "nothing was saved" then writes, and one that silently clamps — so pin the WHOLE condition, require the block to `exit()`, and require it not to assign to the number; note `/\$days\s*=/` matches `$days === 0`, so use `=(?!=)`.
- **Content translation is NOT reachable (#485) — never describe it as working.** The engine, its admin page and the member opt-in exist, but nothing a user can reach calls it. Interface translation (`I18n` / `t()`) is a separate system and works normally. **It ships switched OFF (`translation.enabled` = `0`) and `api/translate` has no `api.*.enabled` seed, so neither is reachable on a fresh install; and since 29 October 2026 the admin page carries a standing notice saying nothing is being translated** — not dismissible and not behind the flash block, because the page otherwise looks exactly like a finished feature with an API key box. Delete that notice, and this sentence, only when the member-facing half ships.
- **Calendar, Events and the Preaching Plan are ONE app ("Events").** `/calendar` covers viewing, listing and subscribing; the manage pages handle preaching-plan and worship event types and series.
- **Outside calendars (#514).** `EventVisibility::where()` is the ONE place that decides who may see an event. Every place that reads `tblEvents` for a viewer uses it, or refuses imported events (`externalFeedID IS NULL`); `tools/audit-checks/check_event_visibility.py` enforces that only by finding one of those near the word `tblEvents`, so it cannot see a query built far from it. `FeedImporter` is the ONE writer of an imported event; nothing else may edit one. An address a person typed is fetched only through `SafeFetch`, and an address is never logged or printed. Every writer of a calendar takes the calendar's row lock first (`FeedAdmin::inFeedLock()`, `FeedImporter::lockFeed()`), and an incomplete download never removes anything. **A new kind of "who may see it" entry changes four places together** (the rule in `EventVisibility`, its fast copy `FeedAdmin::whoCanSee()`, `ExternalAudience`, and the picker `admin/calendar/_audience-picker.php`) and needs people of that kind in the made-up worlds of both `tools/event-visibility-selftest.php` and `tools/feed-admin-selftest.php`, or the tripwire cannot see a copy that ignores it. `Portal\Core\ImportNotifier` is the ONE place that decides who is told that imported events are waiting, and what they are told. A person is told only if `EventVisibility::isAdminOfSite()` says yes, and the events in the e-mail come from `FeedAdmin::pendingApprovals()`: do not write a second rule. Its only memory is `tblExternalApprovalNotices`; `emailLocalDate` there is a date on the organisation's own calendar, never a UTC date. The push goes before the e-mails, and a push claim is always followed by its call. Quiet hours hold nothing (owner, 13 October 2026). Nothing it logs or pushes may name a person, a calendar or an event, and a fault's own words are never logged. A notification switch is added to `notifications.php` and `notifications-save.php` together. The guide `/help/calendar-imports` is pinned by check W50 of `tools/feed-admin-selftest.php`: change both together. Tests: `tools/event-visibility-selftest.php`, `feed-resolver-selftest.php`, `feed-importer-selftest.php`, `feed-admin-selftest.php`, `ics-reader-selftest.php`, `safefetch-selftest.php` and `import-notifier-selftest.php`. The technical record is DEV_NOTES.md, "Outside calendars (#514)".
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

**Part 8 of #514, as it actually went (owner, 5 October 2026).** The owner first
chose Codex for the final whole-of-part-8 check. Codex then ran out of usage
twice: the second time it spent its whole allowance (about 260,000 tokens) on
reading before giving any verdict. The owner then decided: a fresh Opus agent
does that final check as Codex's named stand-in, so part 8 can be committed and
the queue can move on; **Codex reviews part 8 later, split into pieces across its
resets, and no other work waits for those pieces.** Anything a piece finds is
fixed as its own commit. Lesson for sizing a Codex review: give it a fixed file
list and tell it not to read the instruction files, the plan or the handoff —
a review of about 8,000 lines does not fit one allowance otherwise.

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

**Packages that share no files may run side by side (owner, 12 October 2026).** Asked how to speed up, the owner chose this: a package may be planned, built and checked while another is in flight, as long as the two change no file in common. Where files overlap, one at a time still holds. In practice: each builder is given its exact file list and told which shared files it must not touch; the shared documents (`CHANGELOG.md`, `DEV_NOTES.md`, `FEATURES.md`) are never edited by two packages at once — a builder working alongside another writes its entries to a note in its evidence folder, and the commissioning session adds them when that package is committed; each package is staged by its own file list, never from `git status`; each uses its own named containers and ports. Analysis and planning judgements about ONE package still happen one agent at a time.

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

- **`Portal\Core\PortalAddress` is the ONE place that decides the address in a link the portal sends out** (#500): the `site.url` setting (installation-wide row only), else the request's address, else nothing. Never build an e-mailed link from `$_SERVER['HTTP_HOST']`, and never read `site.url` anywhere else. Whether a visitor can still choose the host name of a link is `PortalAddress::visitorCanChooseHost()`; do not work it out on a page. Test: `tools/portal-address-selftest.php`.
- The check is `python3 tools/audit-checks/check_no_hardcoded_domains.py` (it replaces the hand search below). Lines waiting for a change that needs approval are on the pending list beside it; `--strict` fails while that list holds anything.

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

**Where the time zone comes from.** An organisation has one, kept in its own row (`tblSites.timezone`) and answered
ONLY by `Site::timezone()`; there is no `site.timezone` setting since migration 217 (#604). An event has its own,
**`tblEvents.timezone`**, the one column an event's zone is kept in; it has no default, and there is no
`eventTimezone` column. With no zone given an event takes its organisation's. Giving retention is the one reader
that does not follow the organisation's zone: it takes the last place on Earth's date, so a name is never cleared
early. Neither zone may be assumed: a customer in another country, or a church running an online service for a
different region, is the ordinary case, not the exotic one. **Never hard-code a zone**, for the same reason no web
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

## One install, many organisations, and DIFFERENT PEOPLE at each level

**Set by the owner on 29 October 2026**, and it applies **throughout the application — not only to
Calendar and Events. The owner named the noticeboard specifically.**

One installation may serve many organisations, and **whoever administers the installation may be a
different person from the administrators of the organisations on it** — a hosting provider and its
customers is the ordinary case. So the boundary between the two levels is a boundary between
*parties*, not a convenience for one person wearing two hats.

**For a setting that is a LIMIT, the installation's value takes precedence.** An organisation may
hold itself to a **lower** limit; it may **never** raise its own above what the installation allows.
If the installation allows one year, an organisation may choose six months and may not choose three
years. This is the opposite of the ordinary settings rule, where an organisation's row simply
overrides the installation-wide one — and `$SETTINGS` has **already** done that merge by the time any
page runs, so the merged array **cannot** express the rule on its own.

- **`$SETTINGS_INSTALLATION`** is kept beside `$SETTINGS` by the settings loader in `bootstrap.php`
  for exactly this: it holds the installation-wide rows alone, so a reader that needs the two layers
  apart has them with no second query. It is a **layer, not a list of limits** — nothing in it decides
  which settings are limits.
- **The effective value is the smaller of the two, then capped by a hard maximum in code.** Three
  tiers, each unable to loosen the one above it: code, then installation, then organisation.
- **"Not chosen" must follow the installation, not the built-in default.** Resolving an organisation's
  missing value to the default makes the default cap the installation, so an installation could lower
  a limit but never raise one. Caught by a planted check, not by reading.
- **Enforce it in the handler, never only in the form.** A `max` on an input is a courtesy to somebody
  filling it in; it is not a boundary between two parties, because anything can be posted.
- **Refuse the number with the reason; never quietly clamp it.** Silently reducing a number somebody
  else typed is how a person comes to believe a limit is in force when it is not. Record the refusal:
  a request to exceed the installation's limit crosses a line between two parties.

`Portal\Core\EventRecurrence::limitsFrom()` is the first reader built this way, and
`installationAllows()` is how a page says what the installation permits. Tests: section H of
`tools/event-recurrence-selftest.php` and section K of `tools/event-repeat-pages-selftest.php`.

**This has NOT yet been swept across the application.** There are over thirty limit-shaped settings
(`*max*`, `*limit*`, `*Days*`, `*quota*`, `*Retention*`), `noticeboard.upload.maxBytes` among them, and
a known live instance: `/admin/settings/attendance` writes `attend.detailRetentionDays` per
organisation, 0–3650 days, with no installation cap — so an organisation can keep personal detail for
ten years on an installation whose administrator intended ninety days. The sweep is its own package.

## Comment everything, in every language (all projects)

Detailed comments in the code you write or change — HTML, PHP, CSS, JavaScript, XML, JSON and SQL. Explain the WHY, not the what. Record what was tried and rejected ("this used to do X, which was wrong because Y"): it stops the next person reintroducing the fault, or tidying away something load-bearing. Say what code CANNOT do where that is not obvious; an overstated guarantee is worse than none. **JSON has no comments** — never put `//` in a `.json` file; put the explanation in its schema (`description` on every property, `$comment` for maintainers). File headers: see "Code style".

## A schema for every JSON and XML format (all projects)

Where this project produces or consumes JSON or XML, a JSON Schema file (`*.schema.json`) or an XSD lives beside it, with a `description` on every property — the schema is the documentation as well as the validator. Wire the validation into a check so it actually runs; a schema nothing executes is a document, not a check.

## One job per document, and nothing not-done lives only in prose (#678)

**Set by the owner on 30 October 2026**, after they asked why finished handoff entries were being
archived rather than deleted. The answer turned out to be that the handoff was doing three jobs at
once, and the fix is to stop that rather than to archive more carefully.

| Document | Its ONE job | Lifecycle |
|---|---|---|
| **GitHub issues** | The record of what is and is not done, **and every deferral or open decision** | Authoritative; closed when done |
| **`.claude/HANDOFF.md`** | Where we are **right now** — pick up where we left off | **OVERWRITTEN, never appended.** Current state only, about one screen. No history |
| **`CHANGELOG.md`** | What shipped | Append-only |
| **`DEV_NOTES.md`** | Technical lessons worth keeping | Append-only |
| **`.claude/plans/`** | Point-in-time thinking | Terminal status line, then `.claude/history/plans/` (see its README) |

**The rule that makes it work: anything NOT-DONE gets a GitHub issue in the same action that
decides it.** A deferral, an open question, a "moved to the next package" — an issue, there and
then. If it is not worth an issue, it is not worth recording. Nothing checks prose.

**Why, with the actual example.** The handoff entry titled "PACKAGE A2a IS COMMITTED" carried, in
its body, "NOT BUILT, moved to package B2: ending an erased person's open session" and "Decisions
still open: `tblWorkflowSteps.assigneeValue`". So a FINISHED entry was the only record of
unfinished work, which is what made deleting old entries look dangerous. Checked on 30 October:
the session item **had** been built (`Auth::ensureSession()` calls
`Auth::endSessionOfInactiveAccount()` on every request), so the handoff was claiming an unfixed
security hole that no longer existed — the worse direction for a stale claim to point. The
`assigneeValue` question was still genuinely open and had no issue anywhere.

**Never cite another document by line number.** Cite **issue numbers and commit SHAs**, which do
not move. Two plans cited the handoff as "HANDOFF line 264", "line 214", "lines 68-73", "line 185",
"lines 100-102" and "line 138"; every one was already dangling, because the handoff had been
rewritten and archived twice. That fragility is what made the archives look load-bearing.

**Closing an issue is TWO actions, deliberately (owner, 30 October 2026).** Always try
`gh issue close`, **and** also add the `status: done` label — belt and braces, so a close that is
refused is still visible.

**And beware the diagnosis that went with this.** On 30 October 2026 one `gh issue close` was
refused by the permission classifier, and that single refusal was reported as a standing block —
the reason "every issue these files describe as built is still open". **That was wrong.** Tried
again the same day, `gh issue close` worked, and 17 finished issues were closed in one go. The
real reason they were open is simply that **nobody had closed them**. One failure is not a
standing block: try it before concluding, and never generalise a permission refusal into a
capability you do not have.

**A document may not claim a setting or guard is in force without it being checked.** On 30 October
2026 three settings recorded as "DONE and PROVED" did not exist: the deny rules for force-push and
hard reset, the deny rules for `docker volume prune` / `docker system prune`, and
`CLAUDE_CODE_MAX_CONCURRENT_SUBAGENTS=6`. `~/.claude/settings.json` had no `permissions` block and
no `env` block at all. The written rules had been followed, so no harm came of it — what was
missing was the backstop, not the practice. Tracked as #678.

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

This repository commits its own copy, `tools/watchdog.sh`, so the rule works on any machine. **There is no `~/.claude/bin/watchdog.sh` on this Mac — checked 30 October 2026.** This line used to say there was one and that the two copies were kept identical; that was false, so use the repository's copy and do not go looking for a second. **And the `-L` fix approved on 25 September 2026 (#564) is still not applied**: `mtime()` reads the shortcut's age, not the real file's, so a watchdog pointed at a symbolic link fires immediately. Point `quiet` at the real file until that lands. Other modes: `exists <file>`, `contains <file> <text>`, `pid <pid>`; every mode has a hard deadline (four hours by default). When it fires, **look** — it cannot tell "finished" from "stalled" — then act, or start it again if the work is still going. **When a step finishes, start the next in the same turn.**

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
- **The status table is STANDING, not on request (owner, 29 October 2026).** The owner had to ask
  "i'd also asked you to give me regular status checks in tabular form, but havent been seeing this
  (in fact this should also be a standing item, right? why not following my request)?" — and they
  were right: the rule below already said to give it, it was given once when asked and then quietly
  dropped. It is how the owner tracks an autonomous run without reading a narrative, so producing it
  only when prompted defeats the point. Give it in every substantial report, unprompted, and end a
  long run with Blocked on me / Changed / Found.
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

### Every check RUNS now — and until 28 October 2026 twelve of them did not (#646)

Ten scripts in `tools/audit-checks/` and two `*-selftest.php` files were invoked by **no workflow at all** (26 of 36 checks, 51 of 53 tests). Each guards something found broken at least once, so they were twelve correct guards over twelve faults that had each already happened. All twelve now run on every pull request, and the ten checks are **hard gates** (`if: always()`, no `|| true`). The worst was `check_2fa_gate_order.py`, the tripwire for the sign-in bypass of 27 October: its own note says the self-test CANNOT catch a re-reversal of the order, so the half that would was the half that ran nowhere.

**ASK WHAT A TEST NEEDS, NOT WHETHER IT PASSES HERE.** Wiring `qr-selftest.php` in needed `gd`, which **no workflow in this repository had ever asked for** and which `setup-php` does not install by default — the test REFUSES outright without it. It passed locally only because this Mac's PHP has gd, and no green run had ever proved the runner did. When wiring a test, read its refusal conditions and check each one against the job's `extensions:` list. **A step id must also start with a letter or underscore**: `id: 2fa_gate_order` may make the whole workflow invalid, taking every other check with it, and `steps.2fa_gate_order.outcome` is not valid syntax anyway.

**Two traps when editing a workflow file here.** A `#` with a space before it starts a comment, so `name: Role keys (hard gate, #646)` silently became `Role keys (hard gate,` — twelve names were truncated that way; quote any name holding a `#`. And **parse the file before pushing**: there is no `actionlint`, `yq` or `pyyaml` on this machine, but Ruby is (`ruby -ryaml -e 'YAML.load_file(ARGV[0])'`), and that is what caught the truncation and two stale counts. A step skipped by its own `if:` looks wired and is not.

### Monitor and fix the pull-request security checks (always applies)

On EVERY PR you touch, actively monitor GitHub's own automated checks — the
`pr-security.yml` "PR Security Checks" bot comment (route-target-missing,
MariaDB-only DDL, migration idempotency, SQL column drift, schema/seed parity,
etc.), CodeQL, static-security, actionlint, the migration harness, and the
self-test jobs added by #499 (`portal-selftests.yml`, `mail-selftests.yml`,
`calendar-selftests.yml` on every pull request; `backup-selftest.yml` and
`e2e-migrations.yml` only when a pull request touches the files they are
about) — **Psalm is gone**, removed on 13 October 2026 because it analysed
nothing and still reported success; with it gone no code-analysis check here
can turn a run red (#618) —
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
