# Changelog


## [Unreleased] (alpha)
- **Fixed — ten automatic checks and two tests guarded nothing, because nothing ran them (#646)**
  - **What this means for the project.** Ten checks and two tests existed, were correct, and were
    **never invoked by any automated run.** Every one of them was written because the thing it
    watches had already been found broken once. So any of those faults could have been reintroduced
    and nothing would have objected. All twelve now run on every pull request, and the ten checks
    run as **hard gates** — they stop the build.
  - **The worst of them, said plainly.** `check_2fa_gate_order.py` is the tripwire for the sign-in
    bypass fixed on 27 October 2026, where a gate handed out an identity before the check that earns
    it, so a malformed web address left somebody signed in who was never asked for their second
    factor — **on the ordinary password form.** The note beside that fix says two checks are needed
    because neither catches the other's half: one watches the order of the code, the other watches
    the behaviour, and **reversing the order alone is invisible to the behaviour test.** That was
    measured, not assumed. The half that catches a repeat was the half that ran nowhere.
  - **Proved, not assumed.** Each of the twelve passes on the current code. Three were then checked
    the other way, by reintroducing the exact fault each one guards: the sign-in bypass above (caught,
    naming the file and line), storing a session identifier in a record, and a page script missing
    the token that lets a browser run it. All three were refused. The workflow files were parsed to
    confirm every new step really exists, that none is skipped by its own condition, and that none
    was given an escape hatch that would let it fail without stopping the build.
  - **Four faults in the wiring itself, caught before it was committed**, by a check of the wiring by
    a system that did not write it. Two of them would have shown up only on the build server:
    the picture test needed a graphics library that **no automated run here had ever asked for** (it
    passed on the developer's machine, which happens to have it); and one step was given a name
    starting with a digit, which may make the whole security run invalid — and that run carries
    every other check too. The other two: a count in a comment that was already wrong before this
    change, and a fallback that could never have worked because the step before it was not allowed
    to fail.
  - **The twelve step names that were being silently cut short were the new ones**, written in this
    change and corrected in it — not names that had been broken for a while. A `#` with a space
    before it starts a comment in those files, so a name ending `(hard gate, #646)` became
    `(hard gate,`. Said precisely because the first version of this entry implied the fault was
    older than it was.
  - **What is left for the owner.** All of this was proved on a developer's machine. Proving the
    steps really run on the build server needs a push, which is the owner's to make — and two of the
    four faults above are exactly the kind only the build server can disprove.
- **Fixed — a download cut off part way through could delete events from an imported calendar (#645)**
  - **What an administrator should know.** If a calendar download was cut off **inside an event** —
    a dropped connection part way through a transfer, which is an ordinary thing — the portal
    reported the file as a **whole calendar**. It then did what it does with a whole calendar: it
    marked every event the download did not contain as removed. So the events after the cut-off
    point disappeared from the calendar. They are removed rather than destroyed, so re-importing the
    calendar brings them back, and upgrading stops it happening again.
  - **The flag and the warning disagreed with each other.** The reader already said, in words, "the
    file ends in the middle of an event, so it was not complete" — and in the same breath reported
    the file as complete. The comment beside the code even claimed the flag was "false anyway". It
    described a guarantee the code did not keep: the flag was worked out from four things (the
    calendar started, the calendar ended, we did not stop early, no line was dropped) and not one of
    them mentioned a part of the file left unfinished.
  - **Why this one flag matters so much.** It is the single thing the importer uses to decide whether
    a download may be trusted to remove anything. Its own comment: "it is not the whole calendar, so
    nothing may be removed on the strength of it — and the simplest way to guarantee that is to stop
    here without touching a single event." The guard was right; what it was asking was wrong.
  - **It now also catches an unfinished part inside an event that ended properly** — a reminder block
    left open, say — which was never noticed before and was reported as a whole calendar with no
    warning at all.
  - **Measured.** Five shapes of file checked: whole, cut off inside an event, no end-of-calendar
    line, an unclosed reminder inside a closed event, and two events with the second cut off. Only
    the first is now called whole, and a well-formed calendar is unchanged — which matters, because
    getting that wrong would stop the portal tidying anything away on any calendar, for ever.
- **Found, not fixed — a repeating event's later date in the hour that happens twice shows no length at all (#653)**
  - On the night the clocks go **back**, 01:30 happens twice. A repeating event whose date **falls**
    on that reading comes out as **01:30 to 01:30** — no length — so a weekly 01:30 to 02:30 service
    looks like a zero-length entry one night a year. The underlying times really are an hour apart;
    it is the two clock readings that collide, because the clock reads 01:30 twice.
  - **It is the second or later date of a series, not the first.** A series that *starts* on that
    reading is correct throughout, and so is a one-off. That difference is the clearest sign
    something is wrong: the portal answers the same question two ways depending on which date of a
    series is asked about. (Said carefully because the first version of this entry, and the issue's
    own title, said "starting in" — the one reproduction that gives the right answer.)
  - **Not fixed**, because it needs a decision about what an ambiguous reading means, and that is
    the owner's to make — the same kind of decision as the forward direction below. Measurements and
    options are on #653. It is **not new**: it was confirmed against the released code.
- **Recorded — what the portal does with a repeat that lands in the hour that does not exist (#645)**
  - On the night the clocks go forward an hour simply does not happen. A repeating event at 01:30
    therefore has one date with nowhere to land. **The portal moves it forward to 02:30, keeps it
    exactly as long as it should be, and still counts it** — so a series set to three dates gives
    three dates. Nothing has changed: this is what it already did, and it matches what Google,
    Apple and Outlook all show.
  - **What changed is that it is now written down and checked.** It was neither, so somebody could
    have changed it into skipping the date and nothing would have objected — and a series quietly
    delivering two of its three dates, one night a year, is somebody not being told about a service.
    The alternative is defensible; it is simply not what this portal does, and that is now a
    decision on the record rather than an accident.
- **Added — a claimant can correct a claim nobody has decided on yet (#651)**
  - **What a member should know.** There is now a **Change Claim** button on your own expense claim,
    and a form at `/expenses/edit`. You can change what the claim is for, which department it is
    charged to, and its lines. The total is worked out again from the lines when you save.
  - **It stops the moment anybody decides.** Once an approver has approved or rejected the claim,
    the button disappears and the form refuses, telling you to withdraw the claim and submit a new
    one. **This is stricter than "while it is still waiting".** In a department that needs several
    approvals a claim stays "waiting" after the first one — and changing it then would leave an
    approver's recorded approval sitting against numbers they never saw. Before this existed,
    nothing could change a claim at all, so that guarantee came free; it is now a rule the code
    keeps, and it is checked.
  - **Only the person who made the claim** — not an administrator, not an approver. The portal
    already requires somebody else to approve and to pay a claim (#545); who may change the amount
    being claimed belongs to the same rule. An administrator who needs a claim corrected rejects it,
    or asks the claimant.
  - **What it does not do.** It does not add or remove receipts. Attachments already on a claim are
    left exactly as they are. Attaching a receipt that failed to upload is #649.
  - **Measured.** 53 checks, including the sequence above walked end to end against a real database:
    the claim is still "waiting" at the moment of saving, so a rule that read only the status would
    have let a £20 claim become £2,000. Six planted faults, five caught at the first attempt.
- **Fixed — an expense claim could be paid twice (#647)**
  - **What a treasurer should know.** The payment page read a claim's status, then paid it, with
    nothing holding the claim still in between. **Two treasurers recording payment for the same
    claim at the same time — or one person clicking Save twice because the first answer was slow —
    both succeeded.** The claim ended up with two payment references, and the claimant received two
    "you have been reimbursed" e-mails. If each treasurer had really sent the money, it went out
    twice. Nothing needs doing beyond upgrading; if you suspect it has happened, a claim's payment
    records show every reference against it and who recorded each one.
  - **Four things would each have stopped it, and not one was there:** a lock on the first read, a
    second read inside the transaction, a condition on the update, and a rule in the database
    forbidding two payments for one claim. The first three are now in place.
  - **Why this counts as something left out rather than a decision.** The approvals page and the
    withdrawal page have always done it correctly — they hold the claim still and look again before
    acting. The payment page was the only one of the three money pages without that.
  - **How it was found.** By reading the code, not by anything failing. No test had ever exercised
    two payments at once. The automated test that drives the real pages **cannot** test this: it
    runs a web server that handles one request at a time, so two payment requests can never
    overlap there and such a test would have passed on the broken code. A separate test races two
    real database connections instead. Six planted faults, all caught.
  - **The payment references are now read in one place**, `ExpenseRules::paymentReferences()`, rather
    than inline on the page. The reason is worth recording: the page's test held its own copy of
    those few lines, so when an independent check altered the page's copy alone, every check still
    passed — and the drift would have written an empty payment reference against a real claim. A test
    holding its own copy of the logic it tests is not testing anything.
  - **Two smaller faults in the same page, fixed with it.** A failed status update used to commit
    the payment records anyway, leaving the claim payable a second time. And a payment reference or
    comment sent as a list ended the page in an error, in one case storing a payment reference of
    literally "Array" against a real claim.
- **Fixed — a moved date of a PRIVATE imported event was shown to everybody (#644)**
  - **What an administrator should know.** If you import a calendar from elsewhere and one of its
    private repeating events had a single date moved, that moved date was imported as **not
    private** — its title and time visible to whoever could see the calendar's public events. The
    same thing happened to a cancelled series: the moved date lost its cancellation. Upgrading fixes
    how new imports are read; re-importing the calendar corrects dates already brought in.
  - **Why it happened.** The reader decides "private" and "cancelled" from the whole series, so that
    a private series stays private on every one of its dates even when the moved date does not say
    so — which matters because most calendar programs do not repeat that marking, and the standard
    does not ask them to. One path through the code handed the moved date to itself as its own
    series, so the series' two markings were simply dropped.
  - **Why the existing test did not catch it.** There was already a check for exactly this promise,
    and it passed: its moved date lines up with one of the series' own dates, which goes through a
    different piece of code that was always right. The faulty path is only used when the moved date
    lines up with nothing. Two paths, one checked.
  - **Measured.** A private daily series with its second date moved imported as `private=false`
    before and `private=true` after. The new check is the only one of 334 that fails on the old code.
- **Fixed — on the Expenses approvals page, anything that was not exactly "Rejected" recorded an APPROVAL (#642)**
  - **What an administrator should know.** A decision that did not arrive exactly as the word
    `Rejected` was recorded as an **approval**. If the person deciding was the last approver a claim
    was waiting for, **a claim somebody meant to reject became payable.** Nothing needs doing on an
    installation beyond upgrading. If you have reason to think a claim was approved against
    somebody's intention, the approvals history on the claim shows who recorded what and when.
  - **Why it did not show up in normal use.** Nothing in the portal sends anything else: the form is
    a two-option list whose values are exactly those words, and the database column accepts only
    those two. It needed something outside the normal path — a proxy that folds a repeated field
    into a list, a script that fails before setting the field, or a renamed field. That is why
    guessing a default on a page that releases money is wrong even when today's form is right.
  - **The fix.** The decision must say exactly "Approved" or "Rejected". Anything else is refused
    with a message saying plainly that nothing has been changed, before the claim is even looked up.
    There is no fallback, because only the approver knows which they meant, and a money page must
    never guess in the direction that pays out.
  - **The claim NUMBER had the same fault**, three lines above, and in the treasury and withdrawal
    pages too. A claim number sent as a list arrived as claim number **one**, so a decision could be
    recorded against whichever claim that is while the claim the person was looking at was left
    alone. All three pages now refuse it.
  - **How it is kept shut.** Checks in `tools/expense-rules-selftest.php` that read the code, and —
    more to the point — five checks in `tools/access-pages-selftest.php` that post **real requests**
    as a signed-in approver and read the database back. Proved both ways: on the original code four
    of those five fail (the page really did record approvals) and the fifth, the control, passes. The
    first version of this work claimed no such test harness existed; it did, and an independent check
    pointed it out.
- **Added — the portal now says whether its PHP carries the October 2026 security fix (#640)**
  - **What an administrator should know.** In October 2026 two serious flaws were found in PHP itself
    (CVE-2026-109618 and CVE-2026-109619, reported in the press as "PHP2Shell"). They let somebody who has
    not signed in to anything run their own code on the server. The PHP project fixed them on 21 October
    2026 in PHP 8.5.12 and 8.4.27. The portal now tells you, in words, whether the PHP serving your site is
    older than the release that fixed them — on the admin dashboard, on Admin, Server Information, on the
    health page, and on the installation wizard's checks screen.
  - **It warns; it does not block.** An unpatched version never stops an installation and never closes the
    portal. The reason is that **a version number cannot tell a patched build from an unpatched one**: most
    hosting companies apply security fixes without changing the version number PHP reports, so a server
    saying 8.4.11 may well already be fixed. Refusing to install on the strength of that number would lock
    customers out of their own portal over something they usually cannot change and that may already be put
    right. So the wording is always "this is older than the release that fixed it — ask your hosting
    company", never "you are vulnerable".
  - **What to do if you see the warning.** Ask your hosting company whether the fixes for CVE-2026-109618
    and CVE-2026-109619 have been applied to your website, or move the website to PHP 8.5.12 or newer in
    your hosting panel. The portal links to the PHP project's own announcement, which is the official
    source and cannot go out of date about PHP's releases.
  - **What was wrong before.** The installer accepted any PHP from 8.4.0 upwards, so **8.4.0 passed its
    checks with a tick**, and a running portal never looked at the version again at all: three pages printed
    it and left the reader to judge, and one judged it with its own rules written inline. Those rules even
    carried a comment saying they were "short enough to keep here rather than build a second class for
    them" — true until a security judgement had to be made as well, and a security judgement written in two
    places is one that can disagree with itself.
  - **Test builds of PHP are judged properly too.** A release candidate is NOT treated as the
    finished release it is named after, and this is not a detail: PHP 8.6 was already in its ninth
    pre-release when the fix shipped, so 8.6.0RC3 does not carry it even though 8.6 is "newer" than
    8.5. The first version of this work got that wrong and told such a server it was fine; an
    independent check caught it. A pre-release of a release line the portal has never heard of is now
    answered "we cannot tell", which is the honest answer and the one that does not go stale badly.
  - **How it is kept honest.** `Portal\Core\PhpRuntime` is the one place that decides, in the same shape
    as `DbServer` does for the database; `tools/audit-checks/check_php_version_one_place.py` fails if a page
    writes its own version rule again, and `tools/php-runtime-selftest.php` (82 checks, no database needed)
    includes checks that the wording never claims more than a version number can prove, and pins every
    fixed-release number by hand. Nine faults were planted to prove the test catches them, including one
    that made 8.4.0 look safe, one that tightened the wording to "you are vulnerable", and two that gave
    a wrong fix number for PHP 8.5 — which the first version of the test did not notice, because it
    checked the numbers only against themselves.
- **Fixed — two things an outside review found in the imported-calendar code (#514)**
  - A tick box confirming that an event another calendar marked private may be shown more widely accepted a
    value that was not a tick, if the request sent a list instead of a single value — because any non-empty
    list counts as 1 when read as a number. **It was never reachable through the portal**, because the one
    page that calls that code refuses anything which is not a single piece of text, but the code now refuses
    it too: the next page to use it may not be as careful.
  - The daily "events are waiting for approval" e-mail promised in its own notes that every calendar's count
    came from the same reading as its listed events. That was true only of the calendars whose events it
    lists; once the limit of twenty is reached, the rest keep a count taken a few seconds earlier, so a
    number can be slightly too high. The notes now say what the code does, and why re-reading every calendar
    was not worth one more query inside a scheduled job.
- **Fixed — controls that posted in the background worked only once (#634), and one of them was on a money page**
  - **What an administrator should know.** Several buttons stopped working after a single press, and said
    nothing when they did. Nothing needs doing on an installation beyond upgrading; no setting changes.
  - **Why it happened.** The portal replaces a page's form token every time it accepts one, which is right —
    it stops a token being used twice. But seven data handlers checked a token and never sent the replacement
    back, so a page that posts without reloading was left holding a spent one and every press after the first
    was refused. The refusal is a 403 that the scripts ignored, so the control simply did nothing.
  - **Where it showed.** The Host Console's "publish a prompt" form was the worst: it clears its fields and
    stays on the page, and it held its token in a value that could never be updated, so the second prompt of
    any session failed. Live-chat moderation had the same fault, hidden since #612 behind a full page reload
    after every press — that reload is kept, because an approved message has left the queue, but it is no
    longer the only thing holding the page together.
  - **The one on a money page.** The Expenses approvals page builds its "Export" link and its approve/reject
    form from the same token. Whichever was used first spent it, so **exporting a list broke the next
    approval, and approving broke the export link** — with nothing saying why. A download cannot be handed a
    replacement token, because its answer is the file; the export only reads, so it now uses a check that
    leaves the token alone.
  - **What was found to be wrong in the issue itself.** Three handlers it recorded as unreachable are
    reachable (the welcome tour's, the Expenses export and, through `portal-tour.js`, the tour completion),
    and one it thought likely in use is genuinely dead — which confirms that content translation has no way
    in (#485).
  - **How it is kept shut.** `tools/audit-checks/check_csrf_rotation_returned.py` refuses a handler that
    spends the token and returns no replacement. The live-chat self-test now asserts that the replacement
    really comes back — it had asserted the OPPOSITE, making the fault the expected behaviour, and it fails
    on the unfixed code (63 of 64) and passes on the fixed one (64 of 64).
- **Fixed — every pass of the installer over an existing database added three more e-mail templates (#594)**
  - **What an administrator should know.** If the installer has been run more than once over the same
    database, surplus copies of the three portal-wide e-mail templates have built up. The upgrade removes
    them and stops it happening again. Templates an organisation has customised are untouched.
  - **Why it happened.** A template belonging to every organisation leaves its organisation column empty,
    and MySQL never treats one empty value as equal to another — so the table's "no two rows the same" rule
    could not see duplicates, and migration 051's "insert this, or update it if it is already there" could
    never find the row it had just written. Three rows became six, then nine. Measured.
  - **The fix** is migration 222, the same arrangement migration 187 gave settings: the database works the
    value out into a column of its own and the rule goes on that. Proved on MySQL 8.0.36, MySQL 8.4.11 and
    MariaDB 11.4.13, including that it is safe to run twice and that a replay afterwards stays at three.
  - Also: the install script's header claimed to cover "migrations 000-187" when the highest is 222.
- **Security — three ways past sign-in, found by the end-of-queue review and closed (#B2 follow-up)**
  - **What an administrator should know.** Two-factor authentication could be skipped, and a visitor could be
    signed into somebody else's account. Nothing needs doing on an installation beyond upgrading; no setting
    changes. Nobody is known to have been affected — no portal was live when these were found.
  - **Microsoft and Google sign-in skipped two-factor entirely on 1.4.0.** The two callbacks signed the person
    in and never asked whether they had two-factor switched on, so anyone controlling their Microsoft or Google
    account — or able to have one assert their e-mail address, since accounts are linked by e-mail — signed
    straight in. The gate was added on this branch earlier (#B2); 1.4.0 does not have it.
  - **An OAuth callback was accepted when no sign-in had been started.** The guard compared the stored
    one-time value with the one in the address, and both defaulted to empty, so an address carrying an empty
    one passed. That let somebody fetch a sign-in code for their OWN account and hand a visitor a link that
    signed that visitor in as them — so anything the visitor then typed went into an account somebody else
    controls. It now refuses unless a sign-in really is in progress.
  - **The second factor could be skipped by sending a list where an address was expected.** Each of the four
    two-factor gates worked out where to send the person BEFORE it took the sign-in away. A request chooses
    the TYPE of what it sends as well as the value, so `?redirect[]=x` made that step fail part way, the
    sign-in was never removed, and the next page load saw somebody signed in who had never been asked for a
    code. **On the ordinary password form this needed nothing but the person's password**, which is the one
    thing two-factor exists to survive; it was reproduced on the old code before being fixed. The passkey
    path was the quietest: it answered "Authentication failed." and signed the person in anyway.
  - **How it is kept shut.** Every gate now takes the sign-in away first, and the shared address-checking
    helper refuses anything that is not a piece of text instead of failing. Two checks guard it, because
    neither covers it alone: three new checks in the two-step self-test (which fail on the old code), and a
    new `tools/audit-checks/check_2fa_gate_order.py`, which reads the shape of all four gates — proved
    necessary because reversing the order alone is invisible to the self-test.
  - **Found by** an automated review by a second system (OpenAI Codex) of the sign-in files, as part of the
    planned end-of-queue security pass. It reported the callbacks; the password path, the passkey path and
    the missing gate on 1.4.0 were found by following its lead and by the independent check of the first fix.
- **Security — an erasure now reaches every activity record a person has (#626, part two)**
  - **What an administrator should know after upgrading**
    1. **What was still wrong after part one.** An erasure finds a person's records by one column, and the portal
       was not filling it. Of the 582 places that write an activity record, 279 passed no account number — the
       line that records an ordinary password sign-in among them, which runs after the person is already signed
       in. Those records held an empty column while the session details beside them named the person, so an
       erasure went straight past them and left their address, browser name, headers and account number in place.
    2. **What is fixed, and where.** The fault was that the record did not name its person, so that is what was
       fixed: the portal now fills the column from the session whenever the caller names nobody. One rule then
       reaches every record there is. **It was deliberately NOT fixed by making the erasure guess** from the
       session's fingerprint: a shared machine or a kiosk means one fingerprint is not proof of one person, and
       guessing that way is how this portal has wiped the wrong person's records before.
    3. **Two other things quietly come right.** Your own data download ("what we hold about you") and the "filter
       by person" list on Admin, Activity both read that column, so a person's own sign-ins were missing from
       both. Nobody had reported it; the same gap caused it.
    3b. **One thing has deliberately been taken OUT of your own data download** (the owner's decision, 27 October
       2026). Because records that used to be invisible there are now included, and because about two dozen of the
       places that write an activity record put ANOTHER PERSON into the free-text description — an invited guest's
       e-mail address, an outside crew member's name — **the description is no longer part of the download.** What
       kind of thing happened, the address it came from and when are all still there. Nothing checks what goes
       into that free text, so it could not be made safe by reading it once; stripping addresses and names out of
       it was rejected because it would miss cases while looking as though it had not. Found by the independent
       check of this change, not by anybody being harmed.
    4. **Records written before the upgrade.** Press **Clean the old records** on the Upgrade page again. It
       walks the log in pieces, as it did for the earlier change, and now also gives each older record the account
       its session details name. **The portal is not closed while it runs** and you can stop and come back. Until
       it has finished, an erasure still cannot reach the records it has not got to. The Upgrade page and the
       health line both say which of the two jobs is still waiting, and that an unreached record cannot be erased.
    5. **A record belonging to somebody already erased is CLEARED, not linked.** If the account has been erased,
       or is no longer there, the address, the browser name, the headers and the session details are removed from
       the record instead and the column is left empty. Linking it would have tied records back to a person whose
       erasure had already been carried out — so this finishes the job those erasures could not reach.
    6. **Nothing already recorded is re-attributed.** The 303 places that do pass an account number are untouched,
       and a record that already names somebody is never changed. An account part way through its second step, and
       a kiosk's own account, are never used: neither is the person whose record it is.
  - **Database:** migration 221. It does one quick thing — it sends the cleaner back through the log — because
    going through the records itself would be a single statement over the whole activity log, and that was
    measured at 175 seconds for 500,000 records, long enough for a shared host to cut it off mid-upgrade.
- **Security — erasing a person now clears the details left on their activity records (#626)**
  - **What an administrator should know after upgrading**
    1. **What was left behind.** When somebody was erased, the portal emptied the account number on their
       activity records and nothing else. The address the request came from, the browser's name, the request's
       headers, the fingerprint of the browser session and the short list of facts about the session all stayed —
       and the facts held the very account number that had just been emptied in the column beside them. An
       address and a browser name together identify a household; the headers can hold more than that. So an
       erasure left records that still pointed at the person.
    2. **What happens now, and what it does NOT yet reach.** All six are emptied on that person's own records —
       **but only on the records that carry their account number in its own column.** The portal writes an activity
       record either way: of the 582 places that write one, 279 do not pass the account number, including the line
       that records an ordinary password sign-in. On those records the column is empty while the list of session
       facts still names the person, beside their address, browser name and headers, and an erasure does not reach
       them. **So this is a real improvement and not a complete job**, and saying otherwise would be the worst
       outcome: an incomplete erasure that looks complete is what #626 was reported for. Closing the rest needs a
       decision — matching on the session's fingerprint instead, which carries its own risks (a shared machine
       means one fingerprint is not proof of one person) — and that decision is recorded on #626.
    3. **What is kept, on purpose.** What happened, when, and which organisation it happened under. That is
       the reason for keeping an activity log at all, and none of it says who the person was.
    4. **Nobody else is touched.** Only records carrying that person's account number are changed. A record
       of somebody else's is not read, even when it carries the same session fingerprint and the same address —
       two people behind one router, or a shared machine. Nor is a record written with no session at all.
    5. **One figure changes.** The monthly sign-ins report links a password sign-in to the code page through
       the session fingerprint. With the fingerprint gone, an erased person's password-and-code sign-ins count
       twice in that figure. That cost was already written down in the report's own notes as though this change
       had been made; it is now true. Keeping a fingerprint to protect a count, on records meant to stop
       identifying somebody, is the wrong way round.
    6. **Records erased before this upgrade are not revisited.** This changes what an erasure does from now
       on. An erasure already carried out left those details in place and nothing here goes back for them.
  - **Found and NOT fixed here:** 35 other tables the written list marks "unlink" name a column that no erasure
    instruction empties (#635) — `tblErrors` holds an address, a browser name and headers, which is this same
    fault on another table. They are pinned by name in `tools/gdpr-coverage-selftest.php` so they cannot be
    forgotten, and each is the owner's decision. **At least one of the 35 is only a mis-measurement:**
    `tblUsers`' two image columns ARE emptied, by the erasure's own picture step, once the file itself is dealt
    with — the measurement cannot see code that does not use an instruction. The issue says so.
- **Changed — the automatic checks and the installation zip: most self-tests now run on a pull request, and a zip is built by itself (#499)**
  - **What this changes for anybody working on the portal**
    1. **Most self-tests now run on every pull request**, where before this nearly all of them ran nowhere but a
       developer's machine, so a change could break one and nobody would know until somebody happened to run it.
       Ten checks run on every pull request: four jobs of self-tests that need only PHP or a database (the database
       one twice, for MySQL 8.0.36 and 8.4.11), four of mail tests (two PHP versions by two MySQL versions), and the
       calendar tests, which already ran. **Two more run only when a pull request touches the files they are about**
       (the backup tests, and the migration harness). **Not every self-test is covered even so:** the page-scripts
       test (#625) and the QR test (#623) are named in no workflow at all, and neither are seven of the 32 scripts
       in `tools/audit-checks/` — including the two tripwires added this week. That gap is #637; do not read
       this entry as "everything is checked now".
    2. **Installation zips are built by themselves.** A merge into `main` publishes the release — `package.yml` works
       out the version, makes the tag and uploads the zip with its checksum. A merge into `rc` (a branch that does not
       exist yet) publishes a pre-release. A merge into `alpha` or `beta` builds and checks a zip and keeps it as a
       download on the run. **Pushing a `v*` tag by hand publishes nothing any more**, and the tag still counts as
       taken. `release.yml`, which used to do that, is gone. Publishing waits on a repository variable, so nothing is
       published until that is switched on.
    3. **A merge GitHub makes by itself now gets its zip.** Pull requests into `alpha` are merged by GitHub's own
       token, and GitHub raises no event for such a push — so `package.yml`, which listens for a push to `alpha`,
       would almost never have run there. `auto-merge-alpha.yml` now starts it, beside the deploy it already started.
       Anything else that should happen on a merge into `alpha` needs a line there too.
    4. **The Psalm job is gone.** It analysed nothing and still reported success, which looks exactly like a pass.
       With it gone, no code-analysis check in this repository can turn a run red: CodeQL still reads the JavaScript
       but keeps `continue-on-error`, because Code Scanning is not switched on and the half of that step which uploads
       its findings would fail on every pull request. The owner chose to keep it that way and say so plainly.
    5. **The deploy was rewritten** around one setting-resolving script with its own self-test, and it refuses a
       folder that does not look like the channel's own.
  - **Two things only the owner can do, and until they are done this is not finished.** Which checks a branch
    *requires* is a GitHub setting kept outside the repository: the deleted `Psalm` check must be taken off the
    required list for `main`, `beta` and `alpha`, or every pull request into those branches waits for ever, and the
    nine new test jobs have to be made required or a red test will not stop a merge. The automatic audit cannot catch
    this, because it returns success when GitHub refuses to answer. **Be careful which names are required:** three
    of them can never report on a pull request (the package build has no pull-request trigger; the backup tests and
    the migration harness are limited to the files they are about), and requiring any of those would leave every
    pull request waiting for ever — the same trap as the removed check. The checklist prepared for the owner names
    the ten that are safe and the six that are not.

    And the first run of the new deploy on each channel is one dry run started by hand — which needs automatic
    merges into `alpha` held back first, because on that branch nobody chooses the moment.
  - **None of it has run on GitHub.** Workflow files only run there. What was checked here: `actionlint` on every
    file, each step's commands run by hand against a made-up repository, and the deploy and package self-tests.
- **Fixed — a text is no longer sent to somebody who should not be sent one, and a restore of one table refuses three tables (#610, #622)**
  - **What an administrator should know after upgrading**
    1. **A text now checks the person before it goes.** The portal used to ask "may this person still be sent a
       text?" only about a text it had HELD for somebody's Sabbath quiet hours, at the moment that text came due. A
       text sent straight away was never asked — so a broadcast, or a prayer-chain text, still went to somebody whose
       account had been switched off, or who had been removed from the organisation, for as long as their number was
       on file. Every text is now asked, including a critical alert, an emergency notice and the code that confirms a
       number.
    2. **A person is judged at the moment their text would go.** A text going now is judged now; a text held for quiet
       hours is judged when it comes due, which can be up to two days later and can give a different answer. So
       somebody suspended on the Friday and reinstated on the Saturday still receives a text that was held in between
       — the portal behaves as it did before in that case, deliberately.
    3. **Nobody who should get a text stops getting one.** A global administrator with no membership row still does;
       an installation with one organisation never asks about membership at all; and confirming your own number is
       unaffected.
    4. **A restore of ONE table now refuses `tblMigrations`, `tblTotpBackupCodes` and `tblSettings`.** Admin,
       Maintenance, Backup used to accept any table. Putting back only the backup codes brought already-used sign-in
       codes back to life; putting back only the migrations record or the settings made a database upgrade that had
       already run look as though it were still waiting, so the portal closed itself and ran that upgrade again over
       data it had already changed — which was measured destroying a two-factor backup code made after the upgrade,
       and overwriting a time zone chosen after it. Those three are now refused before anything is read or emptied,
       with a message naming the table and pointing at "Restore ALL tables", and the page no longer offers a button
       for them. **A whole-database restore is unchanged and is still the supported way to put all three back.**
    5. **One thing you can no longer do:** a snapshot of `tblSettings` taken before migration 187 now has no route
       back through the portal. A whole-database restore already refused those snapshots; the single-table route that
       was the way round it is now closed too.
- **Security — the activity log no longer stores a person's sign-in identifier or a copy of their session (#622)**
  - **What an administrator should know after upgrading**
    1. **What was wrong.** Every activity record kept the browser session's identifier — which IS the sign-in cookie —
       and a copy of everything in that session. Anybody who could read that table, a backup, or a snapshot taken
       before this upgrade could use a live session, and could read every set of two-step backup codes and some
       authenticator keys.
    2. **What a record keeps now.** A fingerprint of the identifier (`sha256:` and 64 characters — the same for every
       record of one session, and no use for signing in) and a short list of six named facts: signed in or not, the
       account, the organisation chosen, an account waiting for its second step, and an account at a kiosk. The consent
       log is changed the same way.
    3. **Every two-step backup code made before this version is deleted, once.** People make a new set from their
       Account page. A second run of the upgrade never deletes codes made since. **Tell anyone who uses two-step
       sign-in to make a new set**, or they will have none left.
    4. **Old activity records are cleaned from the Upgrade page, in pieces.** A button, "Clean the old records", is
       offered until none are left, and **the portal stays open while it runs**. As one statement the clean-up took 176
       seconds for 500,000 records, which a shared host would cut off; in pieces it takes 60 to 90 seconds over three
       or four presses.
    5. **Accounts whose authenticator key the log held are recorded and counted.** Each gets one activity record, and
       the health page tells a global administrator how many of those people still sign in with such a key — so you can
       ask them to set two-step up again.
    6. **A WHOLE-database restore is safe. Restoring ONE table is not.** Putting back `tblMigrations`,
       `tblTotpBackupCodes` or `tblSettings` on its own can delete backup codes made since the upgrade, bring used ones
       back alive, or lose the count on the health page. The whole-database restore carries everything together and has
       none of these problems. (A guard against this is being built separately, on the owner's decision of 26 October
       2026.)
  - **What changed in the code**: `web/_core/Logger.php` (the fingerprint and the six facts), the new
    `web/_core/ActivityLogCleaner.php`, `web/_install/upgrade.php`, `web/_apps/privacy/consent.php`,
    `web/_apps/cron/_health-probes.php`, `web/_core/personal-data-catalogue.php`, migration
    `220_activity_log_fingerprints.sql` and the install script, the new check
    `tools/audit-checks/check_session_id_not_stored.py`, a change to `check_schema_seed_parity.py`, the new test
    `tools/activity-log-selftest.php`, and the migration harness.
  - **What its own tests could not catch, and now can.** The self-test passed 72 of 72 against three genuinely broken
    versions — the worst a one-character fault that made the cleaner skip one record at every piece boundary, leaving
    those records' session identifiers in place for ever while the Upgrade page and the health page both reported
    nothing left. The test now cleans a log dense enough to cross several boundaries and reads the records back from
    the database instead of believing the cleaner; it searches for every run of eight characters of a secret, not just
    the first; and it **refuses to search for an empty text**, which is how two checks had come to pass while proving
    nothing after the two-step page changed shape.
- **Fixed — buttons that did nothing, because the browser refused the script behind them (#625)**
  - **What an administrator should know after upgrading**
    1. **What was wrong.** Every page the portal serves carries a rule saying which scripts the browser may run. A
       script written straight into a page, and a handler written on an element (`onclick`, `onchange` and the like),
       carried nothing to identify it, so the browser refused to run it and said so only in its own console. The page
       looked finished and the button did nothing at all. There were 97 of them in 63 files.
    2. **What that means for you.** Controls across the portal now work that silently did nothing before — on Settings,
       Users, Roles, Groups, Departments, Sites, the calendar's event hub and series pages, Expenses (submit, approve
       and treasury), Giving, Forms, Documents, Venues, Small Groups, Attendance, the host console, live chat
       moderation, the noticeboard, Worship (plan and present) and your own account page. **Nothing was lost while they
       were broken**: a button that did nothing changed nothing.
    3. **"Register New Passkey" on your account page now works.** Its script ran for the first time and stopped
       immediately, because it built its dialog before the part of the page that draws dialogs had loaded.
    4. **Worship, Present: every control worked exactly ONCE per page load, and said nothing when it stopped.** The page
       took one form token when drawn, the portal replaces that token each time it accepts one, and the page was never
       given the replacement. Mid-service that is as bad as the page not loading. Commands now go through one at a time
       in the order pressed, nothing is lost on a quick double press, a command that cannot be sent is **said** in a
       badge beside the slide position instead of vanishing, and a request that never answers gives up after ten
       seconds rather than silently stopping the console for the rest of the service.
    5. **Nothing about the safety rule was loosened** to achieve this — checked independently, and the rule the portal
       sends is byte for byte what it was.
  - **What changed in the code**: 31 inline scripts in 28 pages now carry the page's own one-off nonce; 19 handlers
    written on elements are gone, replaced by two attributes read by the new shared file
    `web/public_html/assets/js/portal-actions.js`; 8 page-specific handlers became listeners; `Asset.php`,
    `Captcha.php`, `header.php` and `footer.php` rewritten the same way; `web/_apps/worship/api/advance.php` returns
    the replacement token and `web/_apps/worship/present.php` keeps it.
  - **New checks so it cannot come back**: `tools/audit-checks/check_inline_scripts_have_nonce.py` (with its own
    self-test and an exemption list explained line by line) and `tools/page-scripts-selftest.php`, which reads the real
    pages as an administrator and as a member.
  - **Still to do, raised as its own issue:** eight other handlers elsewhere in the portal have the same spent-token
    fault, and the asynchronous logic that solves it belongs in a shared file rather than in one page.
- **Fixed — the portal's QR codes could not be read, and one of them sent a key to another website (#623)**
  - **What an administrator should know after upgrading**
    1. **Every QR picture the portal has ever drawn was unreadable.** The fifteen squares that tell a reader which
       error-correction level and mask pattern a picture uses were written back to front, so no reader got as far as the
       data. Nothing reported it, because a wrong picture is still a picture: it draws, it has the right shape, and no
       page fails. **Reprint anything you printed from the portal that carries a QR code** — asset labels, noticeboard
       share codes, event posters — because none of the old ones can be scanned.
    2. **The two-step sign-in page now shows a QR code at all.** It used to ask Google to draw one, which the portal's
       own page rule then refused, so the box was simply empty. It draws its own now, with the key printed underneath in
       groups of four so it can be typed by hand.
    3. **That page was also sending the authenticator key to Google.** The address it built carried the key, so anyone
       setting up two-step sign-in had their key sent to another website. **If you want to be cautious, ask people who
       set up two-step sign-in before this upgrade to remove it and set it up again** — they will get a fresh key. The
       old picture never appeared, so in practice nobody completed the set-up from it, but the key was still sent.
    4. **The public event page was doing the same thing, and there it really did happen.** The page behind a public event
       link (`/e/...`) asked Google to draw its share code. That page sends no page rule of its own, so the picture
       loaded on every view, sending the event's address and each visitor's IP address to Google. It draws its own now.
       Nothing the portal shows asks another website for a QR picture any more.
    5. **QR codes now work at every size and correction level**, refuse politely instead of stopping with an error when
       the text is too long (the limit is now 2,331 bytes rather than 213), have the clear border readers need, and no
       longer come back blank as a small PNG.
  - **What changed in the code**: `web/_core/QrEncoder.php` (rewritten), `web/_core/Qr.php` (clear border, compact
    drawing, `dataUri()`, `longestText()`), `web/_apps/auth/2fa/setup.php`, `web/_apps/calendar/public-landing.php`,
    `web/_core/Totp.php` (`getQrUrl()` removed), comment corrections in `web/_apps/noticeboard/api/qr.php` and
    `web/_core/Barcode.php`, and the new tests `tools/qr-selftest.php` and `tools/qr-test-reader.php` with 23 kept
    fixture pictures.
  - **Still to do, raised as its own issue:** the public event page builds its address from the request's host, so a
    forged request can make the printed address and the picture point somewhere else. It is not new and not made worse
    by this change.
- **Fixed — a text message held for quiet hours is now sent when they end, or recorded as not sent (#610)**
  - **What an administrator should know after upgrading**
    1. **What was wrong.** A text that is not urgent is not sent to a person who is inside their Sabbath quiet hours. The
       portal recorded such a text as "queued" and then never looked at it again: it was dropped, while its record said it
       was waiting.
    2. **What happens now.** The text is kept. The scheduled job you already run for the mail queue (`/cron/mail-queue`)
       sends it once that person's quiet hours have ended. **Nothing new has to be added to the hosting company's
       scheduler.**
    3. **If you have not set that job up, a held text is still never sent.** Its record is corrected to "not sent" the next
       time the portal handles any text, instead of reading "queued" for ever. **A job that runs less often than every 15
       minutes still sends held texts**, as long as it runs before they are two days old. The portal can only tell whether
       the job has run in the last 15 minutes. When it has not, the Notification preferences page and the broadcast page
       say exactly that, and what a held text needs in order to be sent; they do not say it will not be sent.
    4. **Three kinds of text are NEVER held: a critical alert, the code that confirms a number, and an emergency notice.**
       Each is sent at once, whatever the hour. **The emergency notice is new** (the owner's decision of 13 October 2026):
       until now it was stopped for a person in their quiet hours like any other text. If you send an emergency broadcast
       on a Friday evening, everybody who asked for emergency texts gets it then.
    5. **A text that would be too old is not sent late.** Each held text has a use-by moment. A rota change, a newsletter
       digest and a prayer request keep for 48 hours, and nothing is ever sent more than 48 hours after it was written. A
       text past its use-by moment is recorded as "Not sent: quiet hours", with the reason.
    6. **Texts that were already recorded as "queued" before this upgrade are NOT sent.** They may be months old. The
       database upgrade (migration 219) records each as not sent. You do not need to do anything, and no burst of old
       texts goes out.
    7. **The daily limit still holds**, and a held text is counted, and costed, on the day it is really sent. If the day's
       limit is reached, a held text waits for the next day, as long as its use-by moment allows. At most 20 held texts go
       in one run of the job (every five minutes), so a large broadcast held for quiet hours goes out over a while, not
       all at once.
    8. **What is true of the person when the text comes due decides.** It is not sent if their account has been switched
       off or deleted, if they have changed their number or it is no longer confirmed, or if they have stopped that kind of
       text. Where the portal serves several organisations it is also not sent if the person is not a member of that
       organisation, or if the organisation has been switched off. The record says which. If they switch quiet hours off
       for themselves, a held text goes at the job's next run.
    9. **The broadcast page says "held", not "skipped".** After a broadcast it tells you how many texts were sent, how
       many are held for quiet hours (and that the portal sends those itself, so the broadcast must not be sent again),
       and how many were skipped. If the scheduled job has not run in the last 15 minutes it says so, and to look at
       Recent messages before sending again.
    10. **The list of recent texts shows the moment a text was handed over**, not the moment it was written, so a text
        held on a Friday and sent on the Saturday reads as sent on the Saturday.
    11. **After a backup is restored, texts that come back "held" are recorded as not sent.** The portal cannot tell
        whether they went after the backup was taken, and a second text costs money. The restore says how many.
    12. **The scheduled job's answer has seven more numbers**, each with a name that begins `texts` (`textsSent`,
        `textsNotSent` and so on). The status word and the numbers that were there before are unchanged. A monitor that
        reads the answer does not need changing. The answer can come a few seconds later than before when held texts are
        due.
    13. **Never twice comes first.** If the job is cut off while handing a text to the provider, nobody can tell whether
        it went. That text is recorded as "not known whether it was sent" and is not sent again.
  - **What changed in the code**: `web/_core/Sms.php` (holding, `sendHeld()`, the claim, who may still be sent a text),
    `web/_apps/cron/mail-queue.php` (the second step), `web/_core/DbBackup.php` (a restore records held texts as not
    sent), migration `219_held_texts.sql` and the install script (two columns and a key on `tblSmsMessage`), the sentences
    on Account, Notification preferences, on Account, SMS, and on Admin, SMS (the broadcast page and the list of recent
    texts), one sentence of the guide `/help/calendar-imports`, and the new test `tools/held-texts-selftest.php`.
  - **Not done here (tabled by the owner in #610):** holding e-mail or push for quiet hours, and letting each person
    choose which kinds of message wait.
- **Changed — an event's coordinator can send "We're live", and only a signed-in person's device can ask for notices (#612)**
  - **What an administrator should know after upgrading**
    - **Coordinators can now send "We're live".** An event coordinator who is not an administrator sees the button on
      their event's Host Console page and can send the notice for THAT event (not for one they do not coordinate). They
      still cannot open the livestream channels page or change channels and schedules. Each press counts against the
      organisation's limit of three in fifteen minutes, and each is recorded in the activity log with who pressed it and
      for which event.
    - **Devices stored with no account stop receiving pushes.** Before, a device could be stored without anybody being
      signed in. Now only a signed-in person's device can ask for notices, and devices stored earlier without an account
      are no longer sent anything (their records are kept, not deleted). **A person signs in and switches notices on
      again from that device** (Account, Notifications, "Enable notifications on this device", or the same button on the
      live page). Nothing changes for anybody who switched notices on while signed in.
    - **Admin, Integrations, Push now counts only devices that will be sent notices**, for each channel and in total. A
      new line, "Switched on but never sent anything", counts the rest (no account, or the account or the membership is
      switched off). The "starting soon" switch now reads "Broadcast "starting soon" to everyone who has reminders
      switched on"; it used to say "(anonymous)".
    - No database change. Nothing has to be run after upgrading.
  - Who may send: only the one action is widened. Opening the livestream channels page and every other action on it stay
    for administrators. The event must exist and belong to the organisation in hand. A made-up number, another
    organisation's event, an event the person does not coordinate, a withdrawn coordinator, a deleted event and (for a
    coordinator) a missing number are all refused and send nothing; to a person who is not an administrator every one of
    those refusals looks the same.
  - The subscribe address (`api/push/subscribe`) refuses a request that is not signed in, in plain words, and stores
    nothing. No page offered that to a visitor; this shuts a door that could be opened by hand. A device is always stored
    against the signed-in person, never against an account number sent in the request.
  - A form token, an action or a come-back address sent in an odd shape (a list) is refused the ordinary way. A list as
    the form token used to answer "Server Error".
  - Test: `tools/go-live-button-selftest.php` now has 127 checks and fails by itself if it runs a different number.
  - Not proved: a real push service or phone, a real browser, MariaDB.
  - Still open, on the issue: a coordinator or an administrator can send for a cancelled or draft event; the scheduled
    go-live job titles its push with the `site.name` setting, not the organisation's own name.
- **Fixed — backup codes for two-factor authentication are shown, and can be made again (#613)**
  - **What an administrator must tell people after upgrading**
    1. **Everybody who switched two-factor authentication on before this version has no backup codes.** The set-up
       page made eight and never showed them, so a lost phone locked that person out until an administrator
       stepped in. Ask each of them to open **Account**, find "Two-Factor Authentication", type the 6-digit code
       from their authenticator app under "Backup codes" and press **Make New Backup Codes** once. The page then
       shows eight codes, once. They should write them down or print them and keep them apart from the phone.
    2. **The codes nobody was shown are removed by the upgrade.** They had also been written into the activity log
       in readable text, so they could not be left working. That removal is part of the activity-log change
       (#622), in this same release. After it, the Account page tells each of those people "You have no unused
       backup codes, so a lost phone would lock you out. Make a set now."
    3. **Anybody who had the set-up page open while doing something else in the portal** may have had the key of
       their authenticator written into the activity log as well. Making new codes does not change that key.
       Those people should switch two-factor authentication off and set it up again. How an administrator finds
       out who they are is in the entry about the activity log (#622).
    4. **A backup code works once, and only for the account it was made for.** It is typed in the "Backup code"
       box on the page that asks for the 6-digit code. Small letters are accepted. Using one is written to the
       activity log as "2FA verification successful (backup code)".
    5. **Making a new set needs a code from the authenticator app**, not the password, so it also works for an
       account that signs in through Microsoft or Google. Five wrong codes stop the form for up to 15 minutes;
       that does not stop the person signing in.
    6. **The codes are shown only in the answer to the request that made them.** They are never kept in a session
       or in a log, and the portal stores only a one-way fingerprint of each, so nobody can show them a second
       time. If a person closes the page too soon, or the page never arrives, they make a new set.
    7. **If somebody presses the button twice.** The button switches itself off when the form is sent. Where
       scripts do not run, a second press can still leave a person with new codes they never saw; the Account
       page then says so ("That form had already been sent, or had expired…") and tells them to make a set
       again, pressing once.
    8. **Many people can do this at the same moment**, for example on the day everybody is asked to.
    9. No database change in this part. Nothing has to be run after upgrading.
  - Switching on and storing the codes now happen together or not at all. Before, if the codes could not be stored,
    two-factor authentication was left on with no codes.
  - One backup code could sign in more than one browser when they sent it at the same moment. The database now hands
    the code to one of them.
  - The key for the authenticator app is kept encrypted in the session while the set-up page is open. Before,
    anything else the person did meanwhile wrote the key into the activity log in readable text.
  - The page that asks for the code loads its styles the way the sign-in page does. It named three files itself:
    the icons' file never exists under that name, and the two Bootstrap files exist only in an installation made
    from a package.
  - The set-up page now draws its own messages ("That code is not right"). They used to stay in the session and
    appear later on some other page.
  - New test: `tools/two-step-selftest.php` (84 checks, MySQL 8.0.36 and 8.4.11). It runs the real pages.
  - Not proved: a real authenticator app, a real browser (that the button's script runs), an account with no
    password, MariaDB.
- **Fixed — buttons and links that reached nothing, and the "We're live" push that now really goes (#612)**
  - **What an administrator should know after upgrading**
    - Nothing to do. No database change, no new setting, no new address.
    - Admin, Live Chat: Approve, Hide and Flag now do something. All the buttons grey out when one is pressed, then the
      page reloads: approved and hidden messages have left the queue, a flagged message stays in it, marked. A refusal
      shows its words in a box and the page reloads too. The Live Chat app still has to be switched on, as before.
    - On the backup pages the "<- Maintenance" button is now "<- Admin", and "Maintenance" in the trail at the top is
      plain text, not a link. The same is true of the retention page (as before) and the health, demo-data and off-site
      backup pages.
    - Expenses: an expired form token on the CSV export now returns to the approvals page with its message.
    - **"Send We're live": the push notification now really goes out. It can be sent from TWO buttons: the Host Console's
      (on an event's page), which did nothing before, and the one on the livestream channels page
      (`admin/livestream/channels`).** Who receives it: the devices that subscribed to the livestream notifications of THIS
      organisation (a signed-in member's device, or a device with no account), once each, up to 500 devices; not another
      organisation's, not a person whose account or membership of the organisation is switched off. How a member switches it
      off: Account, Notifications, "We're live now" in the push notifications card (it is shown once Web Push is set up); it
      stays off when they press Save on the Account page's own card. The notice is "<the organisation's name> is live now".
      Each button is greyed unless Web Push is set up (Admin, Integrations, Push) and a livestream channel is inside its
      scheduled window. Only administrators can press it, and the Host Console shows the button only to them. There is a
      limit of three presses in fifteen minutes per organisation, taken before sending; each press sends again, it does not
      remember the last. Nothing records who pressed it.
  - **Fixed**
    - Admin, Live Chat: Approve, Hide and Flag now work. The page sent its request to `api/live/chat/moderate`, which
      nothing answers; the handler is at `api/livechat/moderate`. The handler also throws a form token away as soon as it
      has checked it and never sends the next one back, so any second press from the same page would have been refused
      ("CSRF check failed"). The page now reloads after EVERY answer (success or refusal) to fetch a fresh token, and the
      moment one button is pressed every button on the page is greyed out, so a second message cannot be pressed with a
      token that is already used. If no answer comes back at all (network down) a box says so and the buttons come back
      without a reload (#612).
    - The backup, health, demo-data and off-site backup pages: "Maintenance" in the trail of links at the top is no
      longer a link (it led to "page not found"; there is no Maintenance page), and the "<- Maintenance" button now reads
      "<- Admin" and goes to Admin (#612).
    - The trail of links at the top of every page: only the LAST entry is announced to a screen reader as the current
      page. An earlier entry with no address (for example "Maintenance") is plain words. Before, it was also marked as
      the current page, so seven Maintenance pages announced "current page" twice (#612).
    - Expenses, the CSV export: a person whose form token had expired was sent to `expenses`, which is not a page, and saw
      "page not found" instead of the message. They now go back to the approvals page (`expenses/approve`), which offers
      the export and shows the message (#612).
    - The Host Console's "Send We're live" button now reaches the page that acts on it. It posted to `admin/livestream`
      (the livestream dashboard, which reads nothing posted to it), so pressing it did nothing. It now posts to
      `admin/livestream/channels` and REALLY sends the "we are live" push notification. (The button on the livestream
      channels page itself has sent the same push since the 10 September work, #467; on the released versions neither
      button has worked, because they have no `admin/livestream/channels` address.) (#612)
    - The "we are live" push, now that it is switched on, goes to the right people and says the right thing (#612):
      a member who switched "We're live now" off stays switched off after pressing Save on the Account page's
      "Notification Preferences" card (that card used to throw away every other stored choice, and a missing choice counts as
      "on"); the push is in the ORGANISATION's own name ("<organisation> is live now"), not the installation's; a person
      whose account, or whose membership of the organisation, has been switched off is no longer sent it (this is the sender
      every push uses, so it applies to service reminders and the approvals push too); the Host Console shows the button only
      to administrators (a coordinator who is not one used to see it and be refused); the button switches itself off when
      pressed, and a form token that is already used gets a plain sentence and a link back instead of a bare "Bad request";
      and the limit of three presses in fifteen minutes now counts a press BEFORE sending, so several administrators
      pressing at the same moment cannot all send.
  - **Added (tests and a check)**
    - `tools/audit-checks/check_registered_addresses.py`: every address a page builds with `Site::url()`,
      `Router::url()`, `Router::urlForScript()` or `Portal.url()`, where it is written out whole, must be registered in
      `full_schema.sql`, be an `api/{app}/{action}` whose handler file exists AND whose switch
      `api.{app}.{action}.enabled` the install script seeds, be one the router answers itself, or be a real file in the
      web folder; and two built addresses may not be glued together (#612).
    - `tools/go-live-button-selftest.php` (70 checks): on the real pages, the button's form posts to the right address and ONE
      push, in the organisation's own name, addresses this organisation's subscribed devices only (not a member who switched
      "We're live now" off, even after pressing Save on the Account card, not a device that asked for reminders only, not a
      person whose account or membership is switched off, not another organisation's), a second press sends again, a fourth
      within fifteen minutes is refused, six presses at the same moment send at most three, a member / a coordinator who is not an administrator / a signed-out visitor / no or another
      person's form token cannot press it, the come-back address cannot leave the portal, and another organisation's
      administrator cannot open this organisation's console. It never sends a real push: every device address is
      `http://`, which the sender refuses before any network call (#612).
    - `tools/live-chat-moderation-selftest.php` (64 checks): opens the real moderation page, reads the address and field
      names its script uses, makes that request against the real handler and reads the stored message back; checks who may
      and may not (a member with a genuine token of their own, and a second organisation's administrator in both
      directions); runs the page's own script in Node.js when it is installed; and checks that the trail of links on the six
      Maintenance pages has exactly one current page (#612).
  - **How it was checked.** One independent check of the first part by a fresh Claude agent standing in for Codex: both
    fixes sound (it ran the real moderation handler through 48 checks on both MySQL versions; nobody who should not
    can hide a message), one test that would have passed with the permission check deleted, and six small things, all
    put right. A second, short check of the "We're live" button alone: the button's own change sound, and the older
    faults listed above found in code it now reaches, all put right. **The fixes after each check were not
    independently checked.** Not proved: a real browser, a real push service or phone, a real livestream, MariaDB.
    Codex has not reviewed this.
- **Fixed — every link keeps the organisation's part of the address (#536, part 2 of 2)**
  - **What it is for.** One installation can serve several organisations, told apart by the first part of the address
    (`/north/calendar`). A link written bare (`/calendar`) leaves the organisation. Part 1 did the shared page
    furniture and the sign-in flow; this part does every app page: about 2,700 addresses in 503 files, in the
    administration pages, the calendar, giving, forms, payments, projects, venues, and thirty-seven other folders.
    The list of places still to sweep is empty.
  - **Nothing changes for an installation today.** The mode this is for cannot be chosen yet
    (`Site::FIRST_PART_MODE_READY` stays `false`). With no first part in use, every page comes out as it did before.
  - **Older faults put right on the way.** The two-step sign-in page, and the calendar's "suggest an event" page
    and its save handler, sent a person who was not signed in to `/auth/login`, which is not an address ("page not
    found"). They now go to the sign-in page.
  - **What an administrator should know after upgrading.** Nothing has to be done. No database change.
  - **How it was done and checked.** A script rewrote nearly all of it; four builders each read the whole of their
    share of the change and caught three links the script had glued together wrongly. One independent check by a
    fresh Claude agent standing in for Codex then rendered 28,338 page requests on the old code and the new, as six
    kinds of person, with no first part in use: 28,241 came out the same in status, redirect and every byte, 38
    differ on purpose (the redirects above), and the rest differ only where the old code differs from itself
    (clocks, random keys). With a first part in use, 6,565 of 6,567 redirects keep it (the other two by design) and
    none carries it twice. It found five small things (two of the project's own tests had gone out of date, one
    link without its escape, two untrue sentences), all put right; those fixes were not independently checked.
    **Measured, and said plainly: 547 of the 2,441 rewritten lines never ran in any test** (most are redirects
    inside save handlers); those rest on reading and on every file still parsing. **Not proved:** a browser, a
    passkey sign-in, Apache, PHP 8.4 at run time, MariaDB. Codex has not reviewed this.
- **Added — administrators are told when imported events wait for approval (#514, part 9)**
  - **What an administrator should know after upgrading**
    1. **Administrators are now told when imported events are waiting for approval.** Three ways: a card on the dashboard
       ("Imported events waiting for approval", with the number), one e-mail a day on a day when something is waiting, and a push
       notification when something newer starts waiting. Only administrators of the organisation are told. A member gets none of
       the three.
    2. **The e-mail and the push need the calendar job to be running.** They are sent by the same scheduled job that refreshes outside
       calendars (`/cron/import-feeds`). If that job is not set up, the dashboard card still works and nothing is e-mailed or pushed.
       Nothing new has to be added to the hosting company's scheduler.
    3. **The e-mail also needs a working mail route** (Admin, Integrations, Email), and the push needs push notifications set up.
       Where neither is set up, nobody is recorded as "told", so the first run after you set one up tells people.
    4. **The daily e-mail lists each waiting event's title and time.** It goes only to people the approvals list would show those
       events to. It says when the list was made, because an event can change before the message is read. At most 20 events are
       listed; the rest are counted, with a link to the list. The push gives a number only, never a title.
    5. **When it is sent.** The first run at or after 07:00 on the organisation's own clock (its time zone at Admin, Sites). One a day.
       If the mail service refuses it, it is not tried again that day. If the mail queue has to keep it, it is sent that same day or
       not at all.
    6. **How a person stops it.** Account, Notification preferences: "Approval requests" switches the daily e-mail off (and the
       portal's other e-mails about approvals with it); the new switch "Imported events waiting for approval" (under Push
       notifications) switches the push off.
    7. **Sabbath quiet hours hold nothing here.** This notice is sent like every other e-mail of the portal, and so is its push,
       whether or not the person is in their quiet hours. (Quiet hours stop a text message that is not urgent, as before.)
    8. **An organisation with no administrator who can be e-mailed:** every global administrator is told as well.
    9. **A paused calendar's waiting events are not counted** on the card, in the e-mail or in the push. The approvals list still
       shows them, marked "(paused)".
    10. **The database upgrade adds one small table** (migration 218). Until it has been run, the calendar job prints
        `notify: not set up (the database upgrade has not been run)` and tells nobody.
    11. **If the mail queue's own job is not running**, a daily e-mail that the mail service cannot take at once is kept and will
        NOT go unless that job is started the same day. The calendar job's line counts those apart, under `keptNoMailJob`.
    12. **A fault put right on the Notification preferences page.** Pressing Save while push notifications were not set up used to
        switch "We're live now" and "Service reminders" off for that person. It no longer does. Anybody who pressed Save there
        before this version, and wants those two, should switch them on again once push is set up.
  - One pass, `Portal\Core\ImportNotifier::run()`, inside the calendar job (`cron/import-feeds`), works out from the data who has
    not been told. It does not matter how an event came to wait. The job prints one more line, second, in figures only:
    `notify: organisations=… emailed=… kept=… keptNoMailJob=… emailProblems=… pushPeople=… pushAccepted=… notReached=… notStarted=… problems=…`.
  - Nobody is told twice. Each person's turn is taken by changing their row in `tblExternalApprovalNotices` in one statement,
    so two runs at the same moment cannot both send.
  - The push goes before the e-mails. A person's push is claimed and sent within milliseconds, so a run that is killed or ends
    in a fault while e-mails are being sent loses nobody's push.
  - Times: "today" and "07:00" are read on the organisation's own clock (`Site::timezone()`); the day is compared as a calendar
    date, never as 24 hours. An event's time in the e-mail is the event's own clock time, never converted, with its time zone named.
  - A message the mail service cannot take at once is kept by the mail queue with a use-by moment: the start of the
    organisation's next day. The row then says `kept`, never `sent`.
  - Erasure and the copy of "my data" both cover the new table (catalogue entry `erase` on `userID`; section
    `importedEventNotices` in the data export).
  - `WebPush::notifyPrefAllows()` is now public, so a fourth copy of that test was not written. `WebPush::send()` no longer calls
    `curl_close()`, which does nothing since PHP 8.0 and raises a "deprecated" notice on PHP 8.5.
  - **Fixed on the way:** Save on the Notification preferences page, with push not set up, no longer switches the push choices off.
  - **How it was checked.** `tools/import-notifier-selftest.php` (new): 124 checks on MySQL 8.0.36 and 8.4.11, including both
    clock-change nights in London and Santiago. One independent check by a fresh Claude agent standing in for Codex found one
    medium and three small problems (the worst: a push that had been claimed could be lost for good if the run was cut short);
    all four were fixed, and the fixes were NOT checked a second time. NOT checked at all: a real mail or push service, a real
    browser, MariaDB. Codex has not reviewed it.
- **Added — outside calendars: three more kinds of "who may see it" (#514, part 10)**
  - A calendar, a choice or a rule set to "Selected groups and people" can now name a **role** (Admin → Roles, #516), a
    **user group** (Admin → Groups, #517) or a **department** (Admin → Departments, #517), as well as a small group, a
    leadership role or a named person. The form shows each kind as its own group of tick boxes, with one line under
    Roles, User groups and Departments saying what it is and where it is managed, because "roles" and "leadership
    roles", and "user groups" and "small groups", are easy to confuse.
  - A person is let in through one of these only with an active membership of the event's own organisation, and only
    while the user group is in use or the department is switched on. **Another organisation's role, user group or
    department on a list lets nobody in**, even a person who belongs to both organisations.
  - A retired user group or a switched-off department cannot be freshly added. One already on a list stays there, lets
    nobody in, and is shown under "No longer in effect" until an administrator unticks it.
  - No database change. Nothing has to be done after upgrading.
  - **How it was checked.** One independent check by a fresh Claude agent standing in for Codex. It built a world of
    its own (3 organisations, 170 accounts, 81 events), worked out by hand who should see each event, and found the
    rule and its faster copy right for every account, on every real page it tried. It found no wrong behaviour. It
    did find that both tests would have passed on a rule that mixed up the kinds, because no two things shared a
    number in the test data; the tests now hold one shared number across all six kinds. Those test fixes were not
    independently checked. Tests: `tools/event-visibility-selftest.php` (192 checks) and
    `tools/feed-admin-selftest.php` (932), on MySQL 8.0.36 and 8.4.11. **Not proved:** a browser, MariaDB, Apache, a
    real Google or Microsoft 365 calendar. Codex has not reviewed this.
- **Added — outside calendars with their own audiences, choices, rules and approvals (#514, parts 1 to 8; parts 9 and 10 are above)**
  - **What an administrator should know after upgrading** (the page is "Outside calendars"; it has no Admin menu entry: open it from the note on
    Calendar, Manage Events, or at `/admin/calendar/feeds`)
    1. **Calendars you already had keep their setting.** The upgrade (migrations 204 to 207) marks every calendar and every event already copied in as
       visible to everyone with full detail, exactly as before. A calendar you add from now on starts at "Members of this organisation". **One
       difference:** from the first refresh after upgrading, an event the outside calendar marks private or confidential is shown only to administrators,
       until an administrator chooses to show it. The older importer showed those to everyone.
    2. **Repeating events you had copied in are replaced.** The older importer stored a repeating event as one row. The first complete refresh after
       upgrading removes that row and writes one row per date. An "I am going" answer given on the old single row is lost; there was no date to move it to.
       A one-off event keeps its row and its answers. An event renamed in the outside calendar keeps only its newest row.
    3. **The scheduled job is the same address as before**, `/cron/import-feeds?key=…`, with the key in the `feeds.cron_token` setting (an empty key
       refuses every visit). Ask your hosting panel's scheduler to visit it every 15 minutes. Without it nothing is refreshed.
    4. **API keys now get an imported event exactly as a signed-out visitor would see it, never more**, and each calendar, choice and rule has a
       "Don't show via API" box. Before this version the API sent keys every imported event with every detail, so for a customer who upgrades this
       is a narrowing: events the outside calendar marks private stop being sent. The Upgrade page says how many calendars now send their
       public events to keys.
    5. **Imported events are read-only everywhere else.** The manage pages, series, crews, invitations, attendance, check-in, documents, bookings and
       the APIs all refuse an imported event as if it did not exist. Change an imported event at its source, or hide it with a choice.
    6. **Your own members-only events now show to your members in the calendar views** (the owner's answer 4 of 17 September 2026).
    7. **In three places the importer used to record a moment in the server's own local time; it now records UTC.** (Part 8.)
  - **Part 8 — the administrator pages** (`6abed16`). Add, pause, resume, refresh now and delete an outside calendar; choose who sees it; match its
    category words; make a choice for one date or every date; write rules with exceptions and start and end days; preview what people will see; approve
    or decline waiting dates one at a time or in bulk, from the Approvals inbox or from the calendar's own page (which works with the Approvals app off).
    A new guide, `/help/calendar-imports`. All writes go through one class, `FeedAdmin`, under the calendar's own row lock; another organisation's
    calendar looks exactly like one that does not exist. Migration 207 adds the page addresses. Checked: a new self-test, `tools/feed-admin-selftest.php`;
    one fresh Claude agent checked the whole part standing in for Codex, after Codex ran out of usage twice. **Not proved:** MariaDB, Apache, a real
    Google or Microsoft 365 calendar, the workflow step on GitHub. Codex has not reviewed it.
  - **Part 7 — who sees each date** (`12c234f`). `FeedResolver` works out, from the calendar's setting, the rules and the choices, who may see each imported
    date and how much, and writes the answer onto the event. A date whose audience would GROW waits for an administrator's approval and stays at the
    narrower setting; narrowing never waits. An event marked private at the source stays with administrators until a choice shows it. Later dates of a
    repeating event follow an identical decided date. Migration 206 (four tables, two opt-out columns). Checked: `tools/feed-resolver-selftest.php`; a separate
    challenge of the plan, then three rounds by fresh Claude agents. **Not proved:** a real Google or Microsoft 365 export, MariaDB beyond one section of the
    migration. Codex has not reviewed it.
  - **Part 6 — the importer and the scheduled job** (`c8c490b`). A refresh claims the calendar, downloads it, reads it, writes one row per date and removes what the
    calendar no longer holds. **An incomplete download never removes anything**; this was the whole risk, and three faults found in checking would each have
    deleted live events (all fixed and guarded). It reopened part 5: the reader now reports "no reliable end point" for a series cut short at its limit.
    Migration 205 (the importer's tables; the identity of an event as the SHA-256 of its UID, stored as raw bytes so capital letters never collide; the older
    rows brought forward). Checked: `tools/feed-importer-selftest.php` (its dates are all worked out from today); seven rounds of checking. **Not proved:** a
    real Google or Microsoft 365 export, an https download against a real certificate, MariaDB. Codex has not reviewed it.
  - **Part 5 — reading calendar files** (`31bd64e`). `IcsReader` and `WindowsTimeZones` turn a calendar file into dates: repeats, cancelled, added and moved
    dates, time zones including Microsoft's own names, both nights the clocks change. The file is untrusted, so every list it chooses the length of is counted before
    it is built. Checked: `tools/ics-reader-selftest.php`, nine rounds. **Not proved:** a real Google or Microsoft 365 export (two checks print SKIPPED
    and say so; every test calendar is hand-written). Codex has not reviewed it.
  - **Part 4 — a safe fetcher** (`51cb46b`). `SafeFetch` fetches only public http and https addresses (`webcal://` as https), checks every address a name
    gives and pins the connection to them, follows redirects one at a time, ignores proxies, caps size and time, and never logs the address. The hymn lookup
    now refuses what it refuses. Checked: `tools/safefetch-selftest.php`, five rounds. **Not proved:** a real Google or Microsoft 365 calendar, MariaDB,
    PHP 8.4, Linux, shared hosting, or a machine without the pcntl extension. Codex has not reviewed it.
  - **Part 3 — imported events are read-only everywhere else** (`4a915db`). Every page and handler that edits, deletes or attaches something to an event by its
    number refuses an imported one exactly as if it did not exist. New check `tools/audit-checks/check_event_visibility.py`, in the pull-request checks. It is
    blunt: it looks for a marker near the words `tblEvents` and cannot see a query built far from them. **Not proved:** MariaDB, Apache. Codex has not reviewed it.
  - **Part 2 — the rule in every place events are shown** (`de31cb8`). Calendar views and search, the event page, downloads, the personal feed, the public
    landing page, replies, registration, the dashboard, reminders, the newsletter, the countdown widget, the sitemap and the events API. A refused event looks the
    same as a missing one. At "title, date and time only" the description, place, images, host and partner names, venue and answer counts are removed, in e-mails,
    push and downloads too. Closes the event page and download parts of #534 and replaces #544's interim fix. **Not proved:** MariaDB, Apache, real mail or push
    providers. Codex has not reviewed it.
  - **Part 1 — one rule for who may see an event** (`2cd68f1`). `EventVisibility`, a piece of SQL each query adds to itself, with the strict membership meaning of #533.
    Migration 204. `tools/event-visibility-selftest.php`. Nothing a visitor saw changed in this part. **Not proved:** a real Google or Microsoft 365 calendar, MariaDB.
    Codex has not reviewed it.
  - **Parts 1 to 8 as a whole were checked by Claude agents that did not build them, and no second AI service has seen any of it.** The real-calendar proof (acceptance
    criterion 3 of #514) is NOT done.
- **Fixed — one answer to "which time zone?", for an event and for an organisation (#604)**
  - **What an administrator should look at after upgrading** (the organisations page is "Sites" in the Admin menu)
    1. **Admin, Sites: is the time zone listed for each organisation the one you want?** The upgrade wrote there the zone the portal's pages were really showing. If somebody had chosen a different zone on this page before, it has been replaced by that one. Change it with Edit if it is not what you want; from now on this page is the only place it is set.
    2. **Calendar, Manage Events: events marked "Europe/London".** Before this version the event form filled its time zone box with Europe/London for every organisation, and the file import, the events API and members' suggestions stored it whatever the organisation. Nobody outside London's zone saw a difference, because reminders ignored an event's zone. They no longer do: reminders, the calendar feed and the calendar download now go by the event's own zone, so such an event follows London's clock. If your organisation is not on London's clock, Manage Events shows a notice saying how many of your older events are marked that way and lists the ones still to come. **One button sets them all, past and coming, to your organisation's zone.** It changes the zone only, never the time stored. If one of them really is in London, do not press it: open the others one by one. Organisations on London's clock (Dublin and Lisbon too) are not shown the notice. The upgrade itself changes none of these events.
    3. **Subscribed calendars and downloaded calendar files now show the right time.** Until this release they were out by the time zone's distance from UTC: an event at 10:00 in London went out as 11:00 all summer, and an all-day event could go out a day early. People who subscribed will see times move to match the event page. Nothing needs doing, but tell them.
    4. **Admin, Health, the line "Time zones".** Green means every organisation and event has a time zone the portal knows. Amber says how many do not.
    5. **Calendar, Manage Events: events "saved with a time zone the portal does not know".** Open each one, choose its time zone and save. They were saved when the box was free text, or with the box emptied.
    6. **An organisation whose card says its zone "is not a time zone name the portal knows".** The portal uses UTC for it, as it always did. Outside calendars that give no time zone of their own are not imported for it until you choose a zone.
    7. **Giving records: when an erased donor's name is cleared.** The date is now taken at the last place on Earth to reach it, whatever the organisation's zone. A name is never cleared early. It is cleared LATER than before: about half a day for an organisation on London's clock, and up to 26 hours for one in the zones furthest ahead of UTC (it is not "at most a day"). The "Confirmed on" date on the Giving Records page is the organisation's own date.
    8. The general **Settings** page no longer has a time-zone setting, and refuses one being added.
    9. **The events API** no longer returns an `eventTimezone` field (it was never in the API description, and held "Europe/London" for every event made in the portal). `timezone` is the event's zone. Creating an event now takes an optional `timezone`; without one the event takes its organisation's zone.
    10. **If you move host with the installer's "carry on with what is there" choice**, each organisation keeps the time zone its pages were showing. (The first build of this work lost them on that path; its check caught it before release.)
  - An event's zone is kept in ONE column, `tblEvents.timezone`. The second, `eventTimezone`, is removed by migration 217. Nothing is copied out of it: only the calendar importer wrote it, always with the value it wrote into `timezone`. Every writer now stores a real zone: the event form (a list of zone names in place of the free-text box), a member's suggestion (it was always stored as "Europe/London"), the file import and the events API (both took the column's default, "Europe/London"), and the calendar importer. With none given, an event takes its organisation's zone. A zone that is not a zone name is refused by the form and the API. The column has no default any more, so a writer that names no zone is refused by the database.
  - An organisation's zone is kept in ONE place, its own row (`tblSites.timezone`), and `Site::timezone()` is the one function that answers it. Start-up, the mail queue, the health page, the admin dashboard, the e-mail page, the reminder jobs and the calendar importer all ask it. Migration 217 first writes into each row the zone start-up was answering, so no ordinary page moves, then removes the `site.timezone` setting. The install script writes the migration's mark only into a database that never held the old arrangement, so the installer's "carry on" choice over an older database keeps every organisation's zone.
  - **Giving retention does not follow the organisation's zone.** Its "today" is the date at the last place on Earth to reach it, so a donor's name is never cleared early; it is cleared later instead, by 12 or 13 hours for London and up to 26 hours in the zones furthest ahead of UTC. The "Confirmed on" date an administrator's tick stores is the one exception: it is the organisation's own date, because people read it and it decides nothing.
  - The reminder job decides "is it time?" from moments: the event's start in its own zone, turned into UTC, against the database's UTC clock. It used to compare the event's clock time with the database server's clock. Each reminder is made once. A day-before reminder is not sent when the moment exactly 24 hours before the start is already the event's own day (a start late on the day the clocks go back). It names the day in place of "tomorrow" when it is two days before on the calendar (the 23-hour day), and when the job's call is late and lands just after midnight on the event's own day (a 23:55 start with the job called on the hour): that one is still sent, and is kept until the event starts.
  - **The calendar feed and the calendar file download gave the wrong time.** They read a stored event time as if it were UTC and moved it into the event's zone (10:00 in London went out as 11:00 all summer), the feed named "Europe/London" for every event, and an all-day event went out a day early east of Greenwich and in London in summer. They now carry the stored time as it stands, in the event's own zone. A member's personal feed is on their own organisation's clock.
  - **Older events marked "Europe/London" in an organisation on another clock** are found and listed at Calendar, Manage Events, with one button to set them all to the organisation's zone. The upgrade changes none of them.
  - Events and organisations stored with text that is not a zone are found and listed for an administrator, never changed. Where an organisation's stored zone is in doubt, the calendar importer refuses a calendar with no zone of its own until an administrator chooses.
  - A snapshot taken before migration 217 can still be put back onto a database that has run it.
  - Before the database upgrade has run, Admin, Organisations and the Settings page take no time zone and say to run the upgrade first.
  - **How it was checked.** One independent check of the build by a fresh Claude agent standing in for Codex: the ordinary upgrade moved nobody's times and the feed fix is right, and it found eight real problems (the worst: the installer's "carry on" choice over an older database lost every organisation's zone). All were fixed. A second, narrow check of the four risky fixes by another fresh agent found nothing that loses data or lets the wrong person in, and five smaller things (two tests that did not hold what they should, a date stored a day early, a reminder dropped when the job ran late, an inexact comment). All were fixed; those last fixes were not independently checked. Tests: `tools/time-zones-selftest.php` (131 checks), `tools/gdpr-eraser-selftest.php` (225), the migration test with a seventh phase for the installer's path, on MySQL 8.0.36 and 8.4.11. **Not proved:** anything in a browser, a real mail server, a calendar program reading the files, MariaDB, Apache. Codex has not reviewed this.
  - **Also corrected:** the installation guide (`docs/INSTALL.md`, step 11) and the installer's closing list told a new customer to set the time zone under Admin, Settings. The one place is Admin, Sites (Edit).
- **The installation package (#499): a customer can install and upgrade from the zip**
  - **Upgrading — what changes for somebody who upgrades to this version. Nothing has to be done.**
    1. **The portal no longer closes for an upgrade unless a database upgrade is really waiting.** It used to
       show its holding page ("Portal Maintenance") from the moment newer files arrived until the Upgrade page's
       button was pressed. A newer version that brought NO database upgrade therefore left every member on the
       holding page for ever, because that button was only drawn when a database upgrade was waiting. Now: with
       a database upgrade waiting, the holding page is shown as before, until the button on `/admin/upgrade` has
       been pressed. With none waiting, the portal stays open. **It does not record the new version by itself:
       an administrator presses "Finish the upgrade" on `/admin/upgrade`.** Until then every request asks the
       database one small question (is an upgrade waiting?), about half a millisecond each; the health page's
       "Migrations" line and the Upgrade page say so. If the portal cannot tell (the folder `_sql` or the table
       of applied upgrades cannot be read) it stays closed, and the Upgrade page says why and what to do about
       that cause.
    2. **Admin, Migrations is not a way to upgrade.** While the files are newer than the version the database
       is recorded at, `/admin/migrations` runs nothing and points to the Upgrade page. It used to run the
       waiting upgrades with no snapshot first, without the check of stored secrets and without recording the
       version, which left the portal closed afterwards. **It is now for a global administrator only**, as the
       Upgrade page is: an administrator of one organisation is refused in words, and the refusal is written to
       the activity log.
    3. **The Upgrade page** offers a button, "Finish the upgrade", when the version is behind and nothing is
       waiting (it takes no snapshot then, because no database upgrade runs; it does still store encrypted any
       saved secret that was kept as readable text, and names each one); says "The upgrade is finished"
       when it has recorded the version, with one line in the activity log ("PortalVersionRecorded"); and says
       why when it cannot tell whether an upgrade is waiting. Its button is the only thing that records the
       version after installation. When the database's list of applied upgrades is there but cannot be read, the
       page is drawn (it was "Server Error") and offers no button.
    4. **A package no longer carries a `.channel` file**, the file that says which copy of the portal an
       installation is. Unpacking a package used to replace it: a test copy given a live package called itself
       live and lost its staff sign-in gate, and a live portal given a beta package shut out every member. The
       installer now writes the file when it finishes, from the word the package carries (the `Channel:` line
       of `VERSION.txt`), and only when there is none. An installation that already has the file keeps it. One
       that has none (its copy is told by the web folder's name, or assumed live) is exactly as it was.
    5. **A version that is still being uploaded is not taken for a finished one.** While some of the new files
       are in place and others not, the portal records nothing; if the new version brings a database upgrade,
       the holding page goes up on the first request after that file has arrived. The folders may be uploaded
       in any order.
    6. **The recorded version cannot be changed by hand in the portal.** Admin, Settings refuses to add, change
       or delete the setting `portal.installed_version`, for anybody, and points to the Upgrade page. A row of
       that name saved for ONE organisation is no longer read at all: it used to beat the installation's own and
       could open the portal with a database upgrade waiting.
    7. **A notice on the Admin dashboard** tells a global administrator when the files are newer than the version
       the database is recorded at, in the same words as the health page and the Upgrade page. The dashboard's
       "Pending Migrations" card, its "Migrations" button and the menu's "Migrations" entry are shown to a global
       administrator only, because the page they lead to now refuses everybody else.
  - **Proved by doing it, twice.** A package was built, unpacked into an empty folder, and installed by following
    `docs/INSTALL.md`, on MySQL 8.0.36 and on 8.4.11. Then two later packages were unpacked over each: one that
    differs only in its version number (a stranger and a member get the portal, not the holding page), and one
    with a database upgrade (the holding page until the Upgrade page's button; the portal afterwards). A beta
    installation given a live package, and a live one given a beta package, each kept their own word. By
    checksum, nothing under `_auth_keys`, `_uploads` or `_backups` changed. **Not proved:** a real hosting
    panel, and Apache (the installs ran behind PHP's own web server).
  - **The guide (`docs/INSTALL.md`, shipped in every package as `INSTALL.md`)** was corrected from what happened
    and has an upgrade section, a way back, what to do when the web folder is not called `admin_html`, and what
    to do on hosting that fixes the main address's folder, allows web folders only inside `public_html`, or
    will not take an 11 MB upload. It says that unpacking replaces the web folder's `.htaccess`, that the way
    back must not lose what somebody put inside the web folder, and that a database backup is put back into an
    EMPTIED database. The installer's seven numbered stages are steps 3 to 10 of the guide; each heading says
    which.
  - **The package's guards.** No package without the guide (`--allow-missing-guide` is gone). A committed
    `web/.channel` is refused, and so is a package that holds the file. One list of our own domains
    (`OWN_DOMAINS` in `tools/audit-checks/check_no_hardcoded_domains.py`). The search for secrets (`gitleaks`)
    must first find a made-up key it is shown, so a program that only calls itself `gitleaks` no longer passes.
    A zip may carry no comment and no byte that is not part of a file, and may unpack to at most 16 MB for one
    file and 150 MB in all.
  - **The first run of the real `gitleaks` on a real package (version 8.30.1) reported one line twice, wrongly:**
    the scheduled-job command in the Help and in these notes, whose "secret" is the placeholder `YOUR_TOKEN`.
    `tools/package/gitleaks-settings.toml` now lets through a find whose secret is exactly that word. Without
    that every release build would have stopped.
  - **Two faults on the first pages a new customer sees.** The dashboard asked for 15 pictures that do not exist
    (an app's icon is an icon font's name, and was written into a picture's address). And `imagedestroy()`,
    which PHP 8.5 reports as deprecated, printed a notice in front of a label PDF on a pre-release copy; the
    five calls are gone (the function has done nothing since PHP 8.0).
  - **Tests.** New: `tools/upgrade-pages-selftest.php` (when an upgrade closes the portal, on the real pages;
    95 checks; 24 faults planted one at a time each fail it, and so do four more planted in the dashboard's
    lines). `tools/site-addresses-selftest.php` has two more checks (103): the menu offers Migrations to a global
    administrator and to nobody else. More cases in `tools/installer-selftest.php`
    (the channel file) and `tools/package/package-selftest.sh` (`T14`, `B16` to `B19`, `C16` to `C26`). An
    independent check was made of the build, and a second, narrow one of the gate; their findings are what the
    second and fourth commits fix. The fourth commit's fixes were not independently checked, and nor were the
    lines joined by hand afterwards (the Settings page's two refusals side by side, the dashboard notice, the
    hidden "Migrations" links); the tests were run again on the joined result. Codex has not reviewed any of it.
- **Added — the mail queue's page, and three deliberate ways past a failed certificate check (#592, part 4 of 6)**
  - **Added**
    - **The mail queue has a page** (#592, part 4): on Admin, Integrations, Email. It lists what is waiting or held, most urgent first, with the kind of message, who it is for, its subject, when it was kept, why it waits, how many times it was tried and the time after which it is not sent. A held password reset is listed first with how long its link has left. It never shows a message's content. Every moment on the e-mail page is in ONE time zone, named beside it: the organisation's own (set at Admin, Organisations), or the `site.timezone` setting's when the organisation's own was never set. A global administrator sees every organisation's messages and can act; an organisation's administrator sees their own organisation's, with no button.
    - **A global administrator can send one held message again, or delete one.** "Send it again" is for a message held because nobody can tell whether it went (the job was interrupted, the mail server never answered, or a backup was restored); the page says, before the button can be pressed, that the person may get a second copy. "Send waiting mail now" tries what is waiting once (at most 25 messages), without waiting for the scheduled job, and does not make the portal believe the job is running. Each is written to the activity log.
    - **Three deliberate ways past a failed certificate check**, for a global administrator and an SMTP mailbox only, each written to the activity log with who and when:
      1. send ONE held message, accepting only the certificate the page showed (a different certificate at that moment refuses the send);
      2. accept only this certificate: mail goes while the mail server shows exactly that one, and is held again when it shows any other;
      3. switch certificate checking off, behind a plain warning, confirmed by typing the mail server's name.
      Mail is encrypted in all three, and a server that offers no encryption is refused in all three. Mail that goes by Microsoft 365 or Google never goes past a check. The second and third change the SMTP mailbox's own setting, so they can be decided while another route is still in use, once a "Send test" of the mailbox has found its certificate failing.
    - **Checking switches itself back on.** While it is off, or one certificate is accepted, the portal asks the SMTP mailbox's mail server at every run of the mail job (whatever mail route is in use), and before a page sends through SMTP, whether its certificate would pass the full check now. When it would, checking goes back on and the activity log says so.
    - **A standing warning** on the e-mail page, the admin dashboard (global administrators) and the health page for as long as checking is off or one certificate is accepted, with the date it was set. It is read from what is stored, so it shows the same to everybody, whatever mail route is in use and however the value got there. A global administrator is also shown who set it, on the e-mail page and the dashboard, when the activity log vouches for that; the health page and an organisation's administrator are shown no name.
    - **A fault found while testing an SMTP mailbox does not touch mail that goes by another route.** What a "Send test" finds (a refused sign-in, a certificate that fails) is put on record and shown on the e-mail page, but it stops the job, holds mail and turns the health line red only while SMTP is the route in use. When the portal is switched to SMTP the first message finds the fault again, and is held or waits.
    - **A notice on the admin dashboard when no e-mail is going out** because the certificate failed, for a global administrator, who cannot be told by e-mail.
    - `tools/mail-queue-pages-selftest.php`: 118 checks, through the real pages against stand-in mail servers.
  - **Changed**
    - **The mail server's name and the certificate setting are changed on the e-mail page only.** The general Settings page refuses to change, add or delete `mail.smtp.host`, `mail.smtp.certCheck` and `mail.smtp.certCheckOffAt`, for everybody, and links to the e-mail page in place of the Edit and Delete buttons. (With certificate checking off, a name changed there sent the next message, and the stored password, to the new server.)
    - **Changing the mail server's name or port on the e-mail page needs the mailbox's password typed again** (or removed). A stored password was kept for whatever answered at the new name or port. Certificate checking also goes back on if it had been switched off, or one certificate accepted, for the server set before.
    - **"Recent sends"** gives a waiting, held or expired line its own label and colour. It used to read "Not sent" in red for all of them.
    - **An organisation's administrator is not shown the mail server's own words** (which can name another person's address, or the mailbox) in the queue or in "Recent sends": they read the page's own words for what the line means.
    - **The mail library** (`web/_core/maillib/`) has a third way to send, "certificate not checked", and a question, "would the certificate pass now?". Its own test grew from 74 to 79 proofs.
  - **Security**
    - "Certificate not checked" is not a connection with the check switched off. On every new connection the certificate is first read on a connection that can write nothing once encryption is on, and the sending connection then accepts exactly the certificate that was read. Encryption, TLS 1.2 or later and "no sign-in before encryption" hold in every mode.
    - A value of `mail.smtp.certCheck` that the portal does not recognise counts as "checked in full". An organisation's own row of that name is never read.
    - The health line, which every organisation's administrator and an outside monitor read, names nobody.
  - **What an administrator must know after upgrading**
    - No database change. Nothing has to be run.
    - **If the mail server's certificate fails its check**, a global administrator now sees a notice on the admin dashboard as well as on the e-mail page and the health page, and can let mail past the check in three deliberate ways (below). Nobody else can.
    - **The mail server's name and the certificate setting are changed on the e-mail page only.** The general Settings page still lists `mail.smtp.host`, `mail.smtp.certCheck` and `mail.smtp.certCheckOffAt`, with a link to the e-mail page, and refuses to change, add or delete them, for everybody.
    - **Changing the mail server's name or its port on the e-mail page now asks for the mailbox's password again**, and puts certificate checking back on. A stored password is no longer kept for a different server name or port.
    - **An SMTP mailbox can be tested, and its certificate decided about, before the portal is switched to it.** A "Send test" through the SMTP mailbox that fails while Microsoft 365 or Google carries the mail stops and holds nothing. If it was the certificate, the e-mail page shows it, and a global administrator can accept it or switch checking off there before switching.
    - "Recent sends" labels a waiting, held or expired message as Waiting, Held or "Expired, not sent"; only a message that was given up on reads "Not sent" in red.
    - An organisation's administrator no longer sees the mail server's own words about a message on the e-mail page.
  - Review: one independent check by a fresh Claude agent standing in for Codex found the centre sound (nobody but a global administrator, by POST with their own form token, sends past a failed check; the certificate shown is the only one accepted; no mode drops encryption) and two medium and five small faults at the edges. One round fixed those, and a further small fix closed a fault the fix builder then read in the code (a failed SMTP test holding mail that goes by another route). Neither of those last two was independently checked. Run on MySQL 8.0.36 and 8.4.11; not against a real mail server or in a browser. Not reviewed by Codex yet.
- **The installer (#499, #500): it now asks who you are, and finishes a complete installation**
  - **What was wrong.** Anybody who reached a freshly uploaded portal before its owner could type the details of a
    database of their own, make themselves administrator, and own a portal running on the customer's address. The
    installer's only guard was "already installed".
  - **The ownership code.** The first time the installer is opened it writes a code into
    `_auth_keys/install-code.php` and asks for it. Only somebody who can open the hosting account's files can read
    that file (opened in a browser it shows nothing). Nothing past the first page is shown, and no form is acted on,
    without the code. The finish asks for it again. A wrong code is refused in the same words however close it was;
    the tenth wrong try in a row writes a new code, also when many wrong codes arrive at the same instant (200 at
    once are counted as 200). If somebody keeps the code changing by sending wrong ones, the owner can make the file
    read-only after reading it; the finish still removes it.
  - **A website pointed at the wrong folder is refused, two ways.** The installer will not run when it is opened
    from inside the portal's own folders, or when the address has the web folder's own name in it (for example
    `/admin_html/`). Both mean the website's address is set to the folder that holds `_core`. It says what to
    change in the hosting panel and writes nothing. What it cannot catch: at the plain address such a website
    shows a list of folders, "Forbidden" or "Not found", and no file of the portal runs at all.
  - **Web addresses.** The installer asks for the portal's own address (filled in with the address in use, for the
    person to check) and the support e-mail address, and checks both exactly as Admin, Settings, Organisation does.
    The address is stored only if the database holds none yet, and never as a second row. An address corrected
    after a finish that stopped part way replaces the one that finish had stored.
  - **The private folders.** The finish makes `_uploads` and `_backups`, and writes the "refuse everything" file
    into those and into `_auth_keys`. A rules file there that holds no rule (empty, comments only, or cut short) is
    written again; one of somebody's own that holds a rule is left alone, and the last page says so when it does not
    hold the refusal. The upgrade page does the same every time it is opened. The same file also ships in
    `_functions` and `_includes` (seven folders in all); the installation package leaves those two folders out, file
    included.
  - **The key file.** A key file that is already there is KEPT. The installer used to write a new one every time,
    which made every secret already stored in the database unreadable. An empty file in its place is replaced. The
    last page says to back the key file up together with the database.
  - **Which copy this is.** The first checks, the finish and the upgrade page say which copy of the portal this is
    and how that was decided. A missing `.channel` file never stops an installation: the copy is assumed to be the
    live one, the pages say ASSUMED, and they give the file to create and the word to put in it. A `.channel` file
    holding a word the portal does not know is said to be exactly that, with the words it does know.
  - **The last page is now real.** "Installation complete" could never be seen: the finish redirected, and by then
    the portal had taken over. It is now drawn in the same answer as the finish, once, and says the address used in
    e-mails, which copy this is, to back up the key file, and what to do next.
  - **A database that is not empty.**
    - The page for it ended in an error for everybody, so neither "carry on" nor "wipe and start again" could be
      reached. It now opens, names the database, and shows how many accounts the database holds. It says "no
      finished installation is recorded", not "none was ever finished": a working portal installed before the
      version was recorded looks the same.
    - **A choice belongs to the one database it was made for.** "Wipe" confirmed for one database is never carried
      out on another: typing database details again forgets any earlier choice; the build step builds nothing into
      a database that is not empty until a choice is held for it; and a page drawn for one database is not acted on
      for another (two tabs, or the browser's Back button). When the choice held is the wipe, the "Build the
      tables" page says so in amber and names the database. Uploaded files are emptied only as part of that wipe.
    - A session lost after the tables were built was a dead end: the installer demanded a signed-in global
      administrator of a portal that was not installed. A database with no finished installation recorded is now
      let through on the ownership code. A database holding a finished portal still demands the sign-in, and now
      says so (a stray redirect used to throw the explanation away).
    - After "wipe and start again", pressing "build the tables" a second time wiped everything again.
    - **A database shared with another program is refused.** "Wipe and start again" removes every table whose name
      begins with `tbl`. A database with no finished installation recorded that also holds a `tbl…` table the
      portal does not make is refused by name at the database step: not built into, not wiped.
  - The first administrator was saved in two halves. An e-mail address longer than 50 characters left an
    administrator who could not sign in and whose address could not be used again. It is now all or nothing.
  - **The upgrade page** builds its three redirects and its breadcrumb with `Site::url()`, and is off the pending
    list of `check_site_aware_addresses.py`.
  - PHP's own error text is never printed into the installer's pages, whatever the hosting's settings say.
  - Self-test: `tools/installer-selftest.php` (130 checks, 131 when a second database server is named; MySQL 8.0.36
    and 8.4.11). It drives the real installer in a scratch copy against a throwaway database.
- **Upgrading — nothing has to be done**
  - An installed portal never runs the installer. Open Admin, Upgrade once after uploading: it puts the "refuse
    everything" file into `_auth_keys`, `_uploads` and `_backups`, and tells you which copy the portal thinks it is.
  - **Seven "refuse everything" files** (`.htaccess` in `_apps`, `_core`, `_functions`, `_includes`, `_lang`,
    `_sql`, `_vendor`; the installer writes the same file into `_auth_keys`, `_uploads` and `_backups`). They matter
    only when a hosting panel is pointed at the wrong folder, and only on Apache.
  - Review of the installer (#499, #500): one independent check by a fresh Claude agent standing in for Codex found no way for a stranger to take the portal (21 addresses and 11 hand-made forms without the code all get the first page) and two medium and five small faults, one of which could wipe a second database by the owner's own slip. One round fixed those, with that scenario and four variations proved by counting the second database's rows before and after; that round was not independently checked. Run on MySQL 8.0.36 and 8.4.11; not on Apache, a real hosting panel, PHP 8.4 or MariaDB. Not reviewed by Codex yet.
- **The mail queue (#592, part 3): what an administrator must do after upgrading**
  **Add one scheduled job. It is the only thing that sends mail that is waiting.**
  1. Run the database upgrade (Admin, Upgrade). It adds migration 216.
  2. Make sure a maintenance token is set: Admin, Settings, `maintenance.cronToken`. It is the token the other scheduled jobs already use; an empty one refuses every job.
  3. In the hosting company's scheduler (a hosting panel's "scheduled tasks" or "cron jobs" page), add this job, every five minutes. The address is an example: use your portal's own.
     ```
     */5 * * * * curl -fsS -H "Authorization: Bearer YOUR_TOKEN" "https://YOUR-PORTAL/cron/mail-queue" > /dev/null
     ```
     If the scheduler can only fetch an address, or the web server does not pass that header to PHP (the answer is then `{"status":"forbidden"}`), use:
     ```
     https://YOUR-PORTAL/cron/mail-queue?token=YOUR_TOKEN
     ```
     The header form keeps the token out of the web server's access log. Either way the portal keeps it out of its own error and activity records. The same line is in the portal's help: Help, Admin Guide, "The mail queue: mail that cannot go at once".
  **Until the job is added, mail still goes as it did before the queue existed, a password reset included.**
  - The portal counts the job as running when it has run in the last 15 minutes. While it has not:
    - every e-mail is sent inside the request that asks for it, as before. That includes a password-reset e-mail on **every** kind of web server: Apache's own PHP module, FastCGI (mod_fcgid), CGI, PHP-FPM and LiteSpeed;
    - a page that sends to many people does not stop at 25 messages (it still stops after 20 seconds, so that the host cannot end the request half-way);
    - after a mail server has refused the mailbox's sign-in, ONE page that sends mail once the wait is over tries the mail server again (pages that send at the same moment do not each try: one of them claims the try).
  - **Anything that could not be sent at once waits, and nothing sends it until the job runs.** That is: mail met by a mail server that was down or said "later", mail over the hourly limit, and what did not fit in a request's 20 seconds. The page that sent it says so in words.
  - **The health page shows it in red.** The "Mail queue" line (Admin, Maintenance, Health, and the monitor's `/cron/health`) is **critical** when messages are waiting and the job is not running: it says how many, that the job has never run (or when it last did), and where the line for the scheduler is. With nothing waiting and no job it is a warning that says the same.
  - A message waits at most 48 hours. Past that it is marked as failed and kept 7 days.
  - A mail server whose certificate fails its check still **holds** all mail, job or no job. The health page says so, in red.
  - The one difference from before the queue on a web server that cannot answer before a script ends (Apache's own PHP module, FastCGI, CGI): **once the job is running**, a password-reset e-mail is no longer sent inside the request; it goes at the job's next run, up to five minutes later. Until the job is running it is sent in the request, so the answer takes a little longer for an address that has an account. That small difference was accepted so that the e-mail arrives.
  - On a pre-release copy (beta, alpha, dev) this one job gets past the staff sign-in gate, because it checks its own token. The other scheduled jobs are still behind that gate there, as before.
  **After a backup is restored, waiting mail is held.**
  A message that was waiting when the backup was taken may have been sent since, and the restored database cannot say. So a restore holds every such message (the health page counts held mail), and none is sent by itself. A held message is kept 7 days and then marked as failed. A page on which one can be released by hand is the next package. A rehearsal ("check only") changes nothing and says how many would be held.
  **What changes without anybody doing anything**
  - A page that sends to many people (an event broadcast, a newsletter, giving statements) sends the first 25, or as many as go in 20 seconds, and queues the rest. Before, it sent them all in one request, or was cut off part way. (While no job is running, only the 20 seconds apply.)
  - `Mailer::send()` answers `true` for a message that was sent **or kept**. A caller that records or shows the result asks `Mailer::lastOutcome()` or `Mailer::lastIsWaiting()`. The broadcast page, the newsletter page, the giving statements pages, the invitation page, the two "send a test" pages and the reminder jobs now say "waiting" for mail that is waiting; a newsletter copy and a giving statement are marked as delivered or e-mailed only when they have gone.
  - An hourly limit that is set no longer drops mail: mail over it waits. The last tenth of the limit (at least 5 where the limit is 10 or more, and never more than half) is kept for urgent mail, so ordinary mail has less room than the number typed: with a limit of 100, 90. One message addressed to more people than that room allows in a whole hour is refused at once.
  - A mail server that refuses the mailbox's sign-in makes mail **wait**; the portal signs in again only after 15 minutes, 1 hour, 3 hours, then every 6.
  - A reminder or an invitation that would be useless or misleading late is never sent late (see the CHANGELOG lines).
  - Review of the mail queue: one independent check by a fresh Claude agent standing in for Codex found the queue itself sound (nothing delivered twice by 9 workers on 400 messages or by killed runs; nothing lost without a record) and one high, six medium and five small faults around it. One round fixed those; because of the high fault a second, narrow check then read that round only and found one medium and four small faults, which a last round fixed. That last round was not independently checked. Run on MySQL 8.0.36 and 8.4.11; not against a real mail server, scheduler or Apache. Not reviewed by Codex yet.
- **Added — a mail queue: a message that cannot be sent at once is kept and tried again (#592, part 3 of 6)**
  - **What was wrong.** A message the portal could not send at that moment was gone: the mail server down, its
    certificate failing the check, the hourly limit reached. Only a line in the send log said so. And a page that
    sent to many people one after another kept going until the web server ended it: a broadcast to five people
    through a slow mail server was cut off with three sent, two logged and nothing recorded, and pressing Send
    again sent to all five once more.
  - **What happens now.** `Mailer::send()` sends a message at once when it can, and such a message is never
    stored. One that cannot go is kept in `tblMailQueue` and a scheduled job (`/cron/mail-queue`) sends it. It is
    tried again after 5 and 15 minutes, then 1, 3, 6 and 12 hours (urgent mail: 1, 5, 15 and 30 minutes), then
    marked as failed and kept 7 days. Nothing older than 48 hours is ever sent, and nothing is sent at or after
    its use-by moment.
  - **Until the job is added, the portal does as it did before.** One question, asked in one place
    (`MailQueue::jobIsRunning()`): has the real job run in the last 15 minutes? Only the job's own page records
    that it ran. While the answer is no, a password reset is sent in the request whatever the web server, a
    request does not stop at 25 messages, and a page tries the mail server again once the wait after a refused
    sign-in is over. What still cannot go waits, the page that sent it says so, and the health page's "Mail
    queue" line is critical and says where the line for the scheduler is.
  - **Never twice.** A run of the job takes rows with one statement and a random mark, and only one run works at
    a time. A run that dies leaves its rows marked; ten minutes later a message it had not started on goes back
    to the queue, and one it was handing over is HELD for a person, because nobody can tell whether the mail
    server took it. **Restoring a backup holds every message that was waiting in it**, for the same reason.
  - **Never late.** A message carries a use-by moment where a late one would be useless or misleading: a
    password reset and a portal invitation (when the link stops working), an event reminder and an event
    invitation (when the event starts; the "tomorrow" reminder at the start of the event's day), a rota reminder
    (the end of its last duty's day), the daily milestones message (the end of its day) and the e-mail that
    confirms an erasure request (23 hours). An event's moment is worked out in the time zone typed on the event's
    own form (`tblEvents.timezone`), and in the organisation's zone when that is not a zone PHP knows. A new
    request for a reset link removes that person's older waiting reset e-mails, whose links no longer work.
  - **What is wrong with the mail route is remembered.** A certificate that fails the check holds every message
    without the mail server being contacted, and each run of the job tries it once; held mail goes by itself when
    it passes. A refused sign-in makes mail wait, and it is tried again only after 15 minutes, 1 hour, 3 hours,
    then every 6: offering a refused password for every message is what gets a mailbox locked. Whoever tries
    must first claim the try, with one statement only one request can win, so pages sending at the same moment
    make one sign-in between them.
  - **Mailings.** A request sends at most 25 messages, or for 20 seconds, and the rest is queued. An event
    broadcast is recorded and all its messages are kept together or not at all, and a second press of Send within
    ten minutes sends nothing. The broadcast page shows what the send handler reports (it never did).
  - **"Sent" means sent.** `Mailer::send()` answers true for a message that was only kept, so every page and
    record that says what became of a message now asks. A newsletter copy or a giving statement that is waiting
    says "waiting", and is marked as delivered or e-mailed when it goes, or as failed when it never will.
  - **The hourly limit no longer drops mail.** Mail over it waits. The last tenth of the limit is kept for urgent
    mail. A message for more people than a whole hour allows is refused at once, in words that name the limit.
  - **Four numbers can be set**, for the installation by a global administrator and for one organisation by its
    own administrator, in Settings: `mail.queue.retryHours` (24), `mail.queue.maxAgeHours` (48),
    `mail.queue.keepFailedDays` (7), `mail.queue.holdDays` (7). Settings refuses a value outside the range and
    says what the installation's value is; clearing an organisation's own value removes it.
  - **Personal data.** A waiting message's body is stored encrypted with the installation's key. The queue, its
    files and the send log are in the written list of personal data; erasing a person deletes mail waiting for
    them alone (telling the record it came from, such as a giving statement, that it did not go), takes their
    address off mail that is also for others, empties the reason text of every send-log line to them and of the
    mail error lines that name their address by itself, and waits for a run of the job to end first. If that run
    is still working after 20 seconds the person is taken out of the queue all the same and the erasure is
    counted as not finished: running it again finishes it. "A copy of my data" lists what waits and what was sent
    to them. The job prints numbers only.
  - **Database: migration 216.** Three tables (`tblMailQueue`, `tblMailQueueFiles`, `tblMailRouteState`), six
    settings, the job's address, three more choices at the end of `tblEmailLog.status` (queued, held, expired) and
    `tblEventBroadcasts.sentAtUtc`.
  - **To do after upgrading: add the scheduled job.** See DEV_NOTES, "The mail queue". On a pre-release copy
    this one job gets past the staff sign-in gate; the other scheduled jobs do not.
  - **Also corrected.** The notification preferences page said non-urgent e-mail is held during Sabbath quiet
    hours. No e-mail is; the sentence now says what quiet hours do stop (a text message that is not urgent).
  - **Not done here.** The page that lists the queue, releases a held message, "accept only this certificate" and
    switching the check off are the next package (M4). Microsoft 365 and Google keep answering only yes or no, so
    a message one of them turns down in a request is still not kept.
  - **Tests.** `tools/mail-queue-selftest.php` (154 checks). An independent check and then a second, narrow
    one were made by fresh agents standing in for Codex, and their findings were fixed. The fixes after the
    second check were not independently checked. Codex has not reviewed this.
- **Secret settings (#597, #497): what somebody upgrading must know**
  - **1. What STARTS the moment the new files are uploaded**
    Not when Upgrade is pressed: as soon as the files are on the server.
    Until now the portal never decrypted a stored secret. So a feature that an
    administrator had switched on, and whose secret was saved on the feature's
    own page, was handed a scramble and silently did nothing. With this version
    each of those gets its real secret and **starts working by itself**:
    - **Error reports go to the outside error service** (Sentry or GlitchTip),
      from the first error onwards. Each report carries the error's text, the
      address that was asked for, and the number of the person signed in. (A
      scheduled job's token is cut out of that address.)
    - **The venue reminders job starts running**, and sends its reminder e-mails.
    - **Web push notices reach subscribers.**
    - **Text messages (which cost money), payments, AI, transcription and maps**
      start working as soon as somebody uses them. Nothing is queued up waiting.
    **If you switched one of these on and do not want it yet, switch it off
    BEFORE you upload this version.**
    Nothing starts for a feature that was never switched on, or whose secret was
    never saved. Content translation still cannot be reached (#485).
  - **2. What can STOP**
    **A scheduled job whose token was regenerated.** The page showed, and the
    portal accepted, the scrambled form of the token (a long text ending in `=`).
    The portal now expects the real token. Open that job's page, copy the token it
    now shows, and put it into the job's address in your hosting panel's
    scheduler. In practice this is the venue reminders job after "regenerate
    token" was used, or a token somebody added by hand as a secret.
  - **3. Back up the key file with the database**
    **Keep a copy of `_auth_keys/enc.key` with every database backup, from now
    on.** Every integration's secret depends on it after this version. A database
    without the key file that goes with it gives back secrets nobody can read, and
    each one then has to be typed in again; scheduled jobs then also need their
    new tokens put into their addresses in the hosting panel's scheduler. Before
    upgrading, check the file is there and is not empty: an empty key file is
    treated as no key at all, and nothing can be saved until the real one is back.
  - **4. What Upgrade does to older secrets**
    **Press Upgrade.** Migration 214 runs, and then the page encrypts, by itself,
    every secret an older version stored as readable text. It is the same secret
    afterwards: nothing that uses it changes. Read the "Stored secrets" card: it
    names what it encrypted, and anything it had to leave (one it cannot read, or
    could not rewrite). If the key file is missing or empty when Upgrade is
    pressed, nothing is encrypted and the card says so.
    Then open Settings as a global administrator. If a yellow notice is there, it
    names each secret that still needs entering again. If you no longer have a
    value, make a new one where it came from.
    **Older backups still hold those secrets as readable text**, and so does the
    snapshot Upgrade takes of the database before it starts. Keep them as
    carefully as the key file, or replace the secrets at their providers.
    **Rows already in the error log are not rewritten.** If a scheduled job's
    address ever appeared on Admin, Error log, give that job a new token.
- **Secret settings are stored encrypted, never shown back, and read correctly (#597, #497)**
  - **Security**
    - **Secrets saved on the Settings page are now stored encrypted and are never
      shown back (#597).** Editing a secret (a mail password, a client secret, a
      scheduled job's token) used to store it as readable text, take away its "this
      is a secret" mark, and then show it in full to every administrator, including
      an administrator of only one organisation. Now the page says only "Set" or
      "Not set". An empty box keeps the stored value; a new value replaces it; a
      tick removes it. The "eye" button has gone. An administrator of one
      organisation is not told whether an installation-wide secret is set.
    - **The portal now reads its encrypted secrets correctly (#497).** It never
      decrypted them: everything that used a secret saved through its own page
      was handed a scramble and silently did not work. Mail only worked because
      of the fault above. See "What STARTS" above.
    - **Upgrade now encrypts, by itself, every secret an older version stored as
      readable text (#497).** The Upgrade page says which ones, by name. It never
      touches a secret that is already encrypted, and leaves alone (and names) any
      it cannot read or cannot rewrite.
    - **When the bot check's keys are stored but cannot be read** (the key file
      was lost or replaced), sign-in and password reset go on WITHOUT the bot
      check, still with their own limits on wrong tries, and every other form the
      bot check protects is refused and says so in plain words. Before, every
      protected form failed, sign-in included, so nobody could get in to put it
      right. The visitor form and live chat, which have no place for a bot check,
      say that they cannot be sent at the moment; they used to answer only
      "Verification failed". Admin, Captcha, the health page and the monitor's
      address, the Settings page and the error log each tell administrators.
    - **Admin, Captcha no longer writes a secret key into its page**, and an empty
      secret-key box keeps the stored key. To stop using a provider, empty both of
      its boxes. **A key the portal cannot read is never removed by an empty
      box:** the boxes of such a provider are shown empty, and pressing Save used
      to empty both keys and switch the bot check off without a word. To stop
      using such a provider, remove its two keys on the Settings page. Saving a
      key while the portal's key file is missing or empty is refused with a plain
      message.
    - **An empty key file is no longer treated as a key.** It used to count as
      one that anybody could work out, so Upgrade "encrypted" secrets with it. A
      key file that is empty, or is not a file, now means "no key": nothing is
      saved or encrypted until the real file is back. A key file of any other
      length works exactly as before.
    - **A scheduled job's token is no longer recorded or sent with an error.** An
      error on a job's address used to be stored with the whole address, token
      included; Admin, Error log showed it, the alert e-mail carried it, and the
      outside error service was sent it. The token's value is now replaced with
      `[removed]`. Rows already stored are not rewritten.
    - **The QR settings page no longer shows how long the stored key is.**
    - **The error log no longer records what a failing function was handed.** It
      used to keep the first 15 characters of each value, which could be the start
      of a secret, and Admin, Error log shows that to every administrator.
    - **The health page has a "Stored secrets" check**: red when a stored secret
      cannot be read, amber when one is still stored as readable text, and amber
      when the key file is missing, empty or shorter than the installer writes
      (a short one still works; it is named so that it is compared with a backup).
  - **Database**
    - **Migration 214** puts the "this is a secret" mark back on every secret
      setting the old Settings page cleared it from (57 names), and takes it off
      `auth.ms365.tenantOnly`, a yes/no that was marked by mistake. It changes no
      value and encrypts nothing; the Upgrade page does that, straight after it.
  - **Checks**
    - New `tools/settings-secrets-selftest.php` (151 checks, on the real pages)
      and `tools/audit-checks/check_sensitive_seeds.py` (a seeded secret must be
      empty). The audit check is not yet run by any workflow.
  - Review: one independent check by a fresh Claude agent standing in for Codex (Upgrade could not be made to lose a secret or encrypt one twice; no secret on any of 1,472 fetched pages; one medium and three small faults), then one round of fixes with no second check. Run on MySQL 8.0.36 and 8.4.11; not on MariaDB, Apache, or against a real provider. Not reviewed by Codex yet.
- **Security — after signing in, a "come back to" address could lead to another website (every installation)**
  - `Auth::safeRedirectUrl()` accepted `/<tab>/other.example`. A browser takes tabs and line breaks out of an
    address before reading it, so that is `//other.example`: another site. A link to the sign-in page carrying such
    an address sent the person there once they had signed in. Any control character in a "come back to" address
    is now refused, written plainly or percent-encoded, and the person lands on their own front page. Found and
    closed while sweeping links for #536; it did not depend on having several organisations.

- **Fixed — links in the shared page furniture and the sign-in flow keep the organisation's part of the address (#536, part 1)**
  - **What was wrong.** One installation can serve several organisations, and one way of telling them apart is by
    the first part of the address (`/north/calendar`). The menu on every page had twenty links written without that
    part (the logo, the dashboard, the whole administration menu, "My account", "Sign out", "Sign in"). So did the
    footer, the three error pages, the "closed for maintenance" page, the sign-in redirect and the sign-out page.
    One click took a person out of their organisation and into the default one, and nothing on the page said so.
    This way of telling organisations apart is still marked "not ready" and cannot be chosen on the set-up page;
    this is the first part of the work that will let it be.
  - **The shared furniture.** `templates/nav.php`, `header.php`, `footer.php`, `error-403.php`, `error-404.php`,
    `error-500.php`, the holding page (`Maintenance.php`), the plain error page (`public_html/error.php`), the
    map partial and `Router::renderError()`'s fallback all build their addresses with `Site::url()`.
  - **Signing in.** A signed-out visitor at `/north/account` is sent to `/north/login` and is back at
    `/north/account` afterwards (it was the bare `/login`, then the bare `/`). With nowhere to go back to, a
    sign-in ends on the organisation's own front page. Signing out ends there too. After a sign-in through
    Microsoft or Google, the step to the two-step sign-in page keeps the organisation's part, and so does the
    way back to the sign-in page when such a sign-in fails.
  - **A "come back to" address may not lead out of the organisation.** `Auth::safeRedirectUrl()` now refuses an
    address with another organisation's first part, with none, or with `..` in it, and sends the person to their
    organisation's own front page. From the default organisation's sign-in page an address with another
    organisation's part is refused as well.
  - **An organisation's part typed in capitals works.** `/NORTH/account` was taken for the North organisation and
    then answered "page not found" on every page, because the page router compared a lower-cased path with the
    part as typed. The two now agree.
  - **For scripts.** A script file is the same for every organisation and has no PHP in it. The header prints the
    organisation's front address once (`<meta name="portal-base">`, only when a first part is in use) and
    `Portal.url('page/name')` in `portal.js` reads it. Four script files use it: `portal-tour.js`,
    `report-builder.js`, `event-hub-upload.js` and `push-subscribe.js` (the last two posted to bare addresses, so
    an upload to an event and switching notifications on or off were refused outside the default organisation).
    A fifth, `livechat-widget.js`, does not yet: see "Not done here". `Portal.url()` does not believe a tag, or take a page name, that a browser would read
    as another site. A script printed by a PHP page uses the new `Router::urlForScript()`. `Router::url()` is
    now one line that asks `Site::url()`, so there is one rule, not two copies of it.
  - **Nothing changes for an installation that does not use first parts.** Shown, not assumed: the code before
    and after the first commit was run against the same database and asked for 1,869 addresses, signed in and
    signed out, in the three set-ups with no first parts; every answer was the same in status, redirect and every
    byte of the page. The fixes that followed were compared the same way on a sample of 40 addresses (282
    answers): none differs.
  - **A check that stops bare addresses coming back.** `tools/audit-checks/check_site_aware_addresses.py` now
    reads every PHP and script file under `web/_apps`, `web/_core`, the web folder and `web/_install/upgrade.php`,
    not a short list. It sees an address after an opening bracket (`$ok ? '/a' : ('/b?id=' . $id)`), and, in a
    script file, an address handed to any function (`postForm('/x', …)`). Places not swept yet are named in
    `tools/audit-checks/site_aware_addresses_pending.txt` (45 places, 2,700 bare addresses on this branch: 2,696 in four groups
    for the four slices of the next package, 4 in one single file); the check fails if a place on that list
    turns out to be clean, so the list can only shrink. `--only <places>` judges one set of folders; a line that
    must stay bare says why with `// address-check: allow — reason`.
  - Self-test: `tools/site-addresses-selftest.php` (101 checks). It runs the portal with two organisations and
    reads every link, form target, picture or script address and redirect on all 585 registered page addresses,
    with first parts and without. It also puts an organisation key holding quotes, an angle bracket, an ampersand
    and a backtick straight into the database and requires every copy a page prints to be escaped for where it
    sits. `--only <places>` runs the crawl for one set of folders.
  - **Not done here:** the app folders (2,696 addresses; the next package), the database upgrade page, the installable app (`manifest.php` starts it at the top
    address), the service worker (it knows `/api/` only at the top of an address), the chat box on `/live`
    (`livechat-widget.js` asks four addresses with no organisation's part) and the return address of a sign-in
    through Microsoft or Google (it comes back with no organisation's part).
  - Review: one independent check by a fresh Claude agent standing in for Codex (525 tries at the sign-in redirects: none left the portal or reached another organisation; one medium and three small faults elsewhere), then one round of fixes with no second check. Run on MySQL 8.0.36 and 8.4.11; not on Apache, in a real browser, or with a real Microsoft or Google sign-in. Not reviewed by Codex yet.
- **No web address is built in, and e-mailed links use the portal's set address (#500).** The portal's own address is now a setting (`site.url`), typed on a new "Web addresses" card at Admin → Settings → Organisation (global administrators only). Every link the portal sends out is built in one place, `Portal\Core\PortalAddress`: the set address, else the address of the request, else no link. Before this, a password reset asked for with a made-up host name mailed a working reset link pointing at that host; with the address set, that can no longer happen. **Until an administrator sets it, links still follow the request.** The admin dashboard says so, and gives a global administrator a **Use this address** button that sets it in one press.
- A reminder for one organisation carries that organisation's own address ("own address each") or its own key ("first part of the address"), not the address of whoever started the job.
- Expense e-mails now carry whole links. Their buttons used to be bare `/expenses/…` addresses, which open nothing from a mail program.
- The support e-mail address is no longer seeded with the first customer's. It is typed on the same card; with none set, Help → Getting Support says so.
- A newsletter that cannot be sent because the portal has no address now says so, with what to do, and is left as it was. It used to show "Sending in progress" and a "Continue sending" button that could never finish. The Recipients page now shows the result of every send ("Sent 12, failed 0"), which it never did.
- The QR pictures on the forms list, the form page and the order-of-service page were broken on every installation: their address ended in `.php`, which the portal answers with "page not found". They now use the registered address `/qr`.
- Migration 215 seeds `site.url` (empty) and blanks the old seeded support address wherever a database still holds it. **Type the support address again on the card after upgrading.**
- `docs/openapi.yaml` is deleted (nothing read it; the served description is `web/_core/api-spec.json`).
- `docs/day2-support.md` names the maintainer by GitHub username only.
- `tools/audit-checks/check_no_hardcoded_domains.py`, with a pending list beside it, and `tools/portal-address-selftest.php` (93 checks).
- **After upgrading: three things to do, and one thing to know (#500)**
  1. **Set the portal's web address.** Sign in as a global administrator and open Admin. A notice says the
     address has not been set and shows the address you reached the page at. If that is the address people
     should use, press **Use this address**: one press saves it. Otherwise type it at Admin → Settings →
     Organisation, "Web addresses".
  2. **Until you do, a password-reset link follows whatever host name the request named.** Somebody can ask
     "Forgot password" for another person's e-mail address while naming a host of their own, and that person
     receives a genuine portal e-mail whose link points at the stranger's site. On ordinary shared hosting
     the web server only passes on requests for names the account serves, so this cannot be reached. It CAN
     be reached where the portal answers for any name: it is the server's default site, the account has a
     wildcard alias, or something in front of it passes the name through. Setting the address closes it on
     every kind of hosting. The same goes for invitation and erasure-confirmation links.
  3. **Type your support e-mail address again**, on the same card. The address that used to be built in
     belonged to the product's first customer, and this upgrade blanks it wherever a database still holds
     it. Until one is typed, Help → Getting Support says none has been set, and e-mails carry no Support
     link. An address you saved yourself is kept.
  **With no usable address at all** (none set, and the request has no host name the portal can use: a job
  started from a command line, or a Host header that is not a plain host name; or the setting cannot be
  read from the database):
  - NOT SENT, because the e-mail is nothing without its link: a password reset (the person is answered as
    always; Admin → Errors records `RESET_NO_ADDRESS`), an invitation (the form says why and its button is
    off), an erasure confirmation (the request is recorded and the page tells the person to ask an
    administrator), an event invitation (the page says why), a giving statement (shown as a failed send),
    and a newsletter (the page says why, and the newsletter stays as it was, not marked as sending;
    Admin → Errors records `NO_PORTAL_ADDRESS`).
  - SENT WITHOUT ITS LINK: task, venue and asset reminders, expense e-mails, approval requests and overdue
    approvals, the prayer chain, the found-item report, the backup alert, and the "Visit the portal" link
    at the foot of templated e-mails.
  - Nothing is recorded as sent that was not. On an installation whose timed jobs are web requests
    (`curl …/cron/…`), those jobs do have a request, so this state needs an unusual Host header or a
    database fault to arise.
  - Review of the built-in-address work (#500): one independent check by a fresh Claude agent standing in for Codex (with the address set, no trick made a secret-carrying link point elsewhere; one medium and three small faults), then one round of fixes with no second check. Run on MySQL 8.0.36 and 8.4.11; not on MariaDB, Apache or a real mail server. Not reviewed by Codex yet. Follow-ups: #600.
- **Added: the portal can send its e-mail through an ordinary SMTP mailbox (#592, M2 of six).** A third route beside Microsoft 365 and Google, through the portal's own mail library. A global administrator types the mail server's name, port, kind of encryption, the mailbox's user name and password, the "from" details and an optional hourly limit at Admin, Integrations, Email, and chooses the route there. One route serves every organisation on the installation.
  - **The password** is stored encrypted and is never shown again. Leaving its box empty keeps it; a tick box removes it. Type it on that page: a password typed into the general Settings page's Edit box is stored there in plain text (#597) and is **not used**; the e-mail page then asks for it again.
  - **The mail server's certificate is always checked**, and there is no way to switch that off yet. If it fails, nothing is sent, the reason is in the send log in plain words, and the e-mail page shows a notice. The notice shows what the last attempt to reach the mail server found: it goes when a later attempt ends another way (a message is accepted, or it fails for a different reason). A message stopped before the mail server is tried (the hourly limit, a missing setting, a message that cannot be made into an e-mail) leaves it showing.
  - **There is no queue yet.** A message that cannot be sent now (server down, certificate, sign-in refused, hourly limit) is **not kept and not tried again**. `Mailer::send()` answers false and the attempt is listed under "Recent sends" with its reason. Packages M3 and M4 add the queue and the certificate choices.
  - **A refused sign-in is tried once a request.** If the mail server refuses the mailbox's user name and password, the other messages of the same request are not offered to it again: each is listed as not sent, saying why. (Two hundred reminders with an out-of-date password used to be two hundred refused sign-ins in seconds, which can get a mailbox locked.) It is remembered for that one request only; the next request signs in once more.
  - **Hourly limit** (`mail.limit.perHour`, empty = none): the most recipients an hour, across **every** route. Counted in UTC, so it is right on the nights the clocks change. **It is not exact:** messages sent at the same moment can together go a little over it, so set it a little below what the hosting company allows; the form says so. A stored limit that is not a number makes the portal say it cannot send, on every route.
  - **"Send test"** shows how far it got (reached the server, encryption on and the certificate checked, signed in, message accepted), the server's last line and the reason in plain words. It can test the SMTP mailbox while another route is in use.
  - **Changed for everybody:**
    - Every action on the e-mail page (saving, choosing the route, sending a test) is for a **global administrator only**; "Send test" there used to be open to an administrator of one organisation.
    - The **Integrations page's own three tests** ("Send Test Email", the Microsoft 365 token test, "Send Test Email via Gmail") are for a **global administrator only** too. Anybody else is refused in plain words, the refusal is in the activity log, and the forms are not shown to them. They were open to any administrator, and "Send Test Email" goes through whichever route is in use.
    - An organisation's administrator sees only their own organisation's lines under "Recent sends".
    - `mail.provider = smtp` now means SMTP; until now it quietly meant Microsoft 365 (no page ever wrote that value).
    - **The choice of route is the installation's own setting only.** A `mail.provider` row belonging to one organisation is ignored, as that organisation's mail server and password rows always were. (No page adds such a row; it can only arrive by SQL or in an older database.)
  - `Mailer::send()` takes an optional fifth argument (`priority`, `expiresAt`, `repeatSafe`) for the queue to come; nothing reads it yet. New: `Mailer::lastOutcome()` (`sent`, `not-set-up`, `refused`, `not-sent`). A message the mail server took and then never answered for is recorded as **not** sent, with the words "nobody can tell whether this message went".
  - The dashboard's "set up e-mail" step is ticked only when the portal can really send. Two settings nothing read (`email.provider`, `email.from`) are deleted.
  - **Database: migration 213.** Six settings; `tblEmailLog` gains `sentAtUtc` (a moment in UTC) and `recipientCount`. **For anybody upgrading:** run the database upgrade after updating the files. Until it is run, mail is still sent and logged, but the e-mail page says its send log cannot be read, and an hourly limit cannot be counted. For the first hour after the upgrade the limit can count too few: earlier rows have no UTC moment.
  - Self-test: `tools/mail-pages-selftest.php` (**108 checks**), on the real pages against stand-in mail servers. Run on MySQL 8.0.36 and 8.4.11 with PHP 8.5. **Not tried:** a real mail server, MariaDB, Apache. One independent check by a fresh Claude agent standing in for Codex found nothing serious and six small faults; all six were fixed, and those fixes were not independently checked. Not reviewed by Codex yet.
- **Changed — which copy of the portal an installation is, and a web folder of any name (#499, #500)**
  - **The channel (live, beta, alpha or dev) is decided in one place, in a fixed order.** `Portal\Core\Channel`
    (`web/_core/Channel.php`) asks, in turn: (1) the `PORTAL_ENV` setting; (2) the first line of a file named
    `.channel` in the folder that holds `_core` (beside `_core`, outside the web folder); (3) the web folder's own
    name, only when it is exactly `public_html` (live), `public_html_beta` (beta) or `public_html_dev` (dev);
    (4) otherwise the portal **assumes it is live**: errors hidden, staff gate off. The words understood are `live`,
    `beta`, `alpha` and `dev` (`prod` still means live). A word the portal does not know, in the setting or in the
    file, counts as not being there, and the next step is tried.
  - **Nobody is locked out for want of a file.** An installation that says nothing carries on as the live portal.
    What it never does is show anything a pre-release copy would: no PHP error text, no debug mode, no debug panel.
  - **What was wrong before.** The channel was guessed from the path the web server reported, looking for the
    folder names anywhere in it, and an unrecognised name meant "dev": PHP errors on screen and no staff gate. A live
    portal on hosting that calls the web folder `httpdocs` or `www` printed PHP errors to the public.
  - **The web folder may have any name.** Five places built its path with `public_html` typed in. They now use
    `PORTAL_WEBROOT`, which `index.php`, `error.php` and the API documentation page set to the real folder. On hosting
    with another name the manifest, `robots.txt`, the sitemap and the API description answered "page not found", the
    offline page could not be shown, and no name was reserved against an organisation's key for being a real file or
    folder there.
  - **`/health` says how the channel was decided** (`channelSource`: `environment`, `file`, `folder` or `assumed`),
    and answers while the portal is closed for an upgrade, so an upload can be checked.
  - **Administrators are told when the channel was not written down.** The admin dashboard shows an amber note when
    the channel came from the folder's name or was assumed; when it was assumed, PHP's error log also gains one line
    per request.
  - **The API documentation page (`/api-docs/`) works.** The web server runs that page by itself, and it never loaded
    the portal, so it was a fatal error on every installation (and printed its own file path wherever the host shows
    PHP errors). It now loads the portal, never prints PHP's error text, is behind the staff gate on a pre-release
    copy and shows the holding page while the portal is closed for an upgrade.
  - **Five "refuse everything" files** (`.htaccess` in `_apps`, `_core`, `_lang`, `_sql`, `_vendor`). They matter only
    when a hosting panel is pointed at the wrong folder.
  - **Three defaults lean to "live"** when the channel has not been decided yet (`App::env()`, `App::isDebug()`,
    `Debug`), and the admin dashboard's channel badge is green on the live channel (it compared with a word the
    channel never is).
  - Self-test: `tools/channel-selftest.php` (220 checks). `tools/db-strict-mode-selftest.php` states its channel.
    `tools/package/check-package.py` names the five new files in its list of allowed `.htaccess` files.
- **Upgrading — nothing has to be done; one thing is recommended**
  - **Nothing is required.** An installation whose web folder is named `public_html`, `public_html_beta` or
    `public_html_dev` is the same channel as before. Any other installation with no `PORTAL_ENV` setting is treated
    as the live portal, with errors hidden.
  - **Recommended: say which copy it is.** In the folder that holds `_core` (NOT the web folder), create a plain text
    file named `.channel` holding one word on its first line: `live`, `beta`, `alpha` or `dev`. Then open `/health`:
    it should say `"channelSource":"file"`. The admin dashboard's amber note goes away.
  - **A PRE-RELEASE COPY IN A FOLDER OF ANOTHER NAME MUST BE GIVEN THE FILE (or the setting).** Without it that copy
    is assumed to be live: no staff gate, open to anybody who has its address. `/health` says
    `"channelSource":"assumed"` for such a copy. **Two layouts the last release recognised and this one does not:**
    a web folder named `alpha_html` or `beta_html` (the last release still knew those older names), and a copy whose
    web folder has another name but sits somewhere under a folder named `public_html_beta` or `public_html_dev` (the
    last release looked for the names anywhere in the path). Give either the file BEFORE uploading this version; the
    old code does not read it, so that is harmless.
  - **Where several copies share one `_core` folder, do NOT create the file.** It sits beside `_core`, so one file
    would speak for every copy, and it is asked before the folder's name: `live` in it would open the beta and dev
    copies to everybody. In that layout keep the web folders named `public_html`, `public_html_beta` and
    `public_html_dev`, or give each web folder its own `PORTAL_ENV`. (Our own three channels are laid out this way
    today, as one customer's set-up; the deploy package, F7, separates them.)
  - The file is one installation's own: it is on the ignore list (`/web/.channel`) and must never be committed.
    Today's deploy mirrors `web/` with deletion and would remove a hand-made file on each run; that is for the deploy
    package (F7).
  - Review: one independent check by a fresh Claude agent standing in for Codex (no way found for a visitor to see error text or to make the portal think it is live; four small faults), then one round that fixed those and built the owner's final rule, with no second check. Run on MySQL 8.0.36 and 8.4.11 and on a real Apache 2.4 with PHP 8.5; not on LiteSpeed, PHP run as CGI, or MariaDB. Not reviewed by Codex yet.
- **Several organisations on one installation: "sign-in session" mode now works (#502).**
  The organisation was worked out before the session had started, so everybody was in
  organisation 1 on every page and the switcher did nothing. A person with one
  organisation is now put in it at sign-in. A person with several chooses one on a new
  page (`/site/choose`) and sees nothing else until they have. Somebody taken out of an
  organisation is out of it on their next click, whether their membership was deleted or
  switched off.
- **The organisation switcher works in all three ways.** It used to post to
  `/site/switch` and go back to the front page whatever the set-up, which changed nothing
  where the organisation is part of the address. It now links to the other
  organisation's prefix or address. A global administrator with no membership row of
  their own is offered every organisation that is switched on. The current
  organisation's name shows at every screen width.
- **An organisation's own address is checked.** Only a plain host name is accepted, and
  an address another organisation holds is refused. A port, capitals or a trailing dot in
  the address a browser sends no longer stop it matching. Migration 212 tidies stored
  addresses and adds a "no two organisations share an address" rule to the database,
  unless two already do, in which case it changes neither and the set-up page says which.
- **Only an organisation's own members get in (#511, main hole).** A signed-in person
  who is not a member of the organisation an address opens now gets a refusal page
  (HTTP 403) on every address of it, data addresses included. Before, a member of one
  organisation could read another's internal events, attendance and dashboard.
- **The web server's own error page no longer shows another organisation's menu.**
  `/error.php` is reached without the gate. Anybody the gate would stop now gets a plain
  page there: the error's number and title, no menu, and no organisation's name.
- **Admin, Organisations, "How organisations are told apart"**: a set-up page for global
  administrators. One way is in use at a time: sign-in session (recommended), own
  address each, or first part of the address. The page refuses a way that would leave an
  organisation unreachable and says what each way cannot do. "First part of the address"
  is marked not ready and cannot be chosen until the link sweep (#536) is finished. "One
  organisation only" says that members of the other organisations then work in the
  default one.
- `tools/site-modes-selftest.php`: drives the real pages in each of the three ways and
  with one organisation (136 checks).
- The two settings `multisite.enabled` and `multisite.detectionMode` can no longer be
  changed, added or deleted in the general settings editor, by anybody; they are changed
  on the set-up page. The list shows no Edit or Delete button for them. A row for one
  organisation with either name is no longer read.
- **The last organisation that is switched on cannot be switched off.** With none on,
  nobody who signed in could open any page, administrators included.
- **While organisations are told apart by their own addresses, an organisation that is
  switched on cannot be saved with no address**: nobody could open it. The default
  organisation is the exception, because it answers at every address no other
  organisation holds.
- An account that belongs to no organisation, on an installation with several, is
  refused everywhere and told to ask an administrator.
- With several organisations in "sign-in session" mode, a global administrator who
  belongs to more than one chooses an organisation after signing in, before any other
  page except the database upgrade page.
- When the portal cannot work out which organisation a request belongs to, it shows a
  "try again" page (HTTP 503) and writes one line to PHP's error log.
- Several-organisations work above (#502, #511): one independent check by a fresh Claude agent standing in for Codex (about 190 hand-made requests got a member of one organisation nothing of another), then one round of fixes with no second check. Run on MySQL 8.0.36 and 8.4.11; not on MariaDB, Apache or in a real browser. Not reviewed by Codex yet. Follow-ups: #599.
- **Sign-in: five wrong passwords now means five (#580).** Each wrong password was counted twice, so three blocked an address, and a try made while blocked also counted, so the block kept moving forward. Now one wrong password is one record, and trying again while blocked does not make the block longer. An address is blocked while five failed sign-ins from it are less than 15 minutes old.
- **Sign-in: tries sent at the same moment no longer get past the limit (#580).** Forty tries at once from one address used to have up to 13 passwords checked. Tries from one address now take turns, so no more than five are checked.
- **Sign-in: the limit for one account is ten tries, not twenty (#580).** Tries by user name and tries by e-mail address were counted apart. They are now counted together, under the account.
- **Sign-in: a block on a name says so (#580).** It used to answer "Invalid credentials" to the account's owner typing the right password. It now says "Too many failed attempts" and how many minutes are left. A made-up name is treated exactly the same, so this gives nothing away. Ten failures for a longer name no longer block a shorter name inside it ("joann" and "ann").
- **Sign-in: the "try again in N minutes" on screen is now right (#580).** It was worked out on the page's clock from a time the database had written, so it could be hours out, and it said "0 minutes" in the last second of a block. The asset kiosk's PIN page reads the same figure: see the note under "Changed".
- **Sign-in and "Forgot password" no longer show by their SPEED which names and addresses have accounts (#580).** A refused sign-in now costs one password check whether or not the name has an account. "Forgot password" answers first and only then looks the address up and sends the e-mail. On a web server that holds a page's output back until the script has finished (Apache with mod_fcgid, or PHP run as a CGI program), "Forgot password" still takes longer for a known address: see DEV_NOTES.
- **"Forgot password" works for somebody who is blocked from signing in (#580).** It has a limit of its own: 5 requests, and 10 tries to use a link, from one address in 15 minutes. A completed reset frees the account's own name if somebody else's wrong passwords had blocked it. It does not end a block on an address early.
- **"Forgot password" sends through whichever mail service is set up (#580).** It used to test for Microsoft 365's settings only, so an organisation sending through Google was told "sent" and sent nothing.
- **A reset link lasts its 60 minutes on the nights the clocks change (#580).** Its expiry was written on the page's clock; a link could last 120 minutes, or none. The expiry is now a moment in UTC, written and compared by the database.
- **A reset link is refused for an account that has been switched off since the link was sent (#580).**
- **A reset link no longer ends up in the activity log (#580).** When the reset form was refused, the browser's "page I came from" header carried the whole link into `tblActivityLogs`. The logger now keeps that header only up to its "?", on every page, and the reset page tells the browser not to send it.
- **A list sent where text is expected no longer stops the sign-in, "Forgot password" and reset pages with an error 500 (#580).**
- **Administrators are told when the portal cannot send e-mail (#580).** Admin, Integrations, Email and the health page (and the monitor's address) say so in plain words, list what is missing, say where it is typed (the Settings page), and count the people who asked for a reset link and were sent nothing.
- **Reset links sent before this version stop working when it is installed (#580).** At most an hour's worth, once. The person asks for a new one. This is deliberate: their expiry was written on a different clock and cannot be read safely.
- **The asset kiosk's PIN page now refuses when it should (#580).** `RateLimiter::retryAfter()` used to answer 0 while over the limit whenever the portal's time zone was not the database server's, and the kiosk's PIN page refuses only when that figure is above 0. So on such an installation the PIN limit did not refuse. It now does. The kiosk page itself was not changed.
- Sign-in work above (#580): one independent check by a fresh Claude agent standing in for Codex, then one round of fixes with no second check. Run on MySQL 8.0.36 and 8.4.11; not on MariaDB, Apache or a real mail provider. Not reviewed by Codex yet.
- **Added: a mail library of our own, with an SMTP route (M1 of six; nothing uses it yet).** `web/_core/maillib/` holds a small mail library (`MWBM\Mail`) written so it can later be lifted out for our other PHP applications: it reaches for nothing of the portal, and settings, the clock, a log and the password are handed in. It builds a correct e-mail (plain text and HTML together, attachments, non-English names) and sends it through an SMTP mailbox. It is always encrypted and always checks the mail server's certificate, including the server's name by a stricter rule of its own, so the answer is the same on PHP 8.4 and 8.5. When that check fails it sends nothing and says why (expired, wrong name, signed by itself, a key too short, an old signature, not meant for a server, or most likely an unknown issuer). A connection fault during the handshake is reported as "try later", never as a certificate fault. Every wait has a time limit, and a message there is not enough memory for is refused in words. **No page or job calls it yet, so nothing changes for users until the next mail package.** Its tests and README are in `tools/maillib/`. An independent check found ten faults in the first build; all ten are fixed. Codex has not reviewed it. Tracked in #592.
- fix(core): **the portal now makes its own database connection strict** (#593; found through #587). It used to run with whatever strictness (`sql_mode`) its hosting company had set. On a loosely set server the database quietly changes a value that does not fit: a text is cut short, a number clipped, a value that is not on a column's list of choices stored as an empty one. The expenses "Withdraw" button stored an empty status that way. Every connection the portal opens now asks the database to refuse such a value with an error. That covers the portal itself and both of the installation wizard's connections, which all open through `Portal\Core\DbServer::connect()`, now the only place under `web/` that opens one. The host's own setting is replaced, not added to, so every installation runs under the same one.
  - The health page has a new check, **Database strictness**; the admin dashboard shows an alert, and Admin → Server Information a line, when the connection is not running under the setting the portal asks for. All three show the setting read back from the database, not the one that was asked for.
  - If the database refuses to be made strict, the portal keeps running and says so (PHP's error log on every request, the health page, the dashboard, Server Information). The installation wizard stops at step 2 instead, because nothing has been built yet.
  - New self-test: `tools/db-strict-mode-selftest.php` (128 checks). It needs a throwaway database server started loose (`--sql-mode=""`), and changes that server's own setting for about a minute.
  - **For anybody upgrading an existing installation:**
    - From this version the portal makes its own database connection strict. On a host already on MySQL's default nothing changes. On a host that ran loosely, a value that does not fit is now refused with an error where it used to be saved changed.
    - A database that was run loosely may hold dates of zero. The portal still reads those rows and can change their other columns. Through the portal's connection these are refused: writing a zero date back (for example a form saved unchanged), copying such rows to another table, and a table change that copies the table row by row (error 1292). A table change done in place still works.
    - To mend zero dates use `WHERE column = 0`. Asking for `'0000-00-00'` by name is itself refused.
    - After upgrading, open the health page: "Database strictness" should be green. Red means the database refused the setting; the portal then runs as loosely as before and says so on every request in PHP's error log.
    - MariaDB has only been tried by hand, and not at all since the fix round.
  - Checked by a fresh Claude agent standing in for Codex; not reviewed by Codex yet.
- **fix(expenses): withdrawing an expense claim now works (#587).** The Withdraw button set a
  claim's status to "Withdrawn", and the database column did not allow that value. On a database
  server with strict checking (MySQL 8's default) the person saw "Error withdrawing claim". On a
  server without it, the page said "withdrawn" and stored an EMPTY status: the claim then dropped
  off the approver's list and the dashboard, and showed elsewhere with a blank status. The button
  had never worked.
  - Migration `211_expense_claim_withdrawn.sql` adds "Withdrawn" to the end of the list of
    choices of `tblExpenseClaims.status`, and `full_schema.sql` has the same list. It also turns
    an empty status into "Withdrawn" (owner's decision, 12 October 2026); such a claim keeps its
    "last changed" time, which is the moment the person pressed the button. On a server with
    strict checking no row changes. Safe to run twice.
  - The rules, as the owner set them: only the person who made the claim may withdraw it, only
    while it is pending, and it is final. A claim that one of two approvers has approved is still
    pending, so it can be withdrawn. The claim, its receipts and any approvals already given are
    kept.
  - Somebody else's claim is now answered exactly as a claim that does not exist ("Claim not
    found"). It used to say "Access denied", which told anybody signed in which claim numbers
    were in use.
  - The person is told what happened on the page they are sent to. The claim's page and the
    submit page now show the one-off message another Expenses page left ("withdrawn
    successfully", "cannot be withdrawn", "Claim not found"); before, it turned up later on some
    other page. The submit page shows its own save page's refusals the same way.
  - If the e-mail to approvers, or the activity line, fails after the withdrawal is stored, the
    person still reads "withdrawn" and the failure is filed under Admin, Errors. It used to say
    "Please try again" about a claim that was already withdrawn.
  - A claim's page names the stage its stored document was last made with (read from
    `tblExpenseClaimFiles.stage`), not the claim's current status. A withdrawn claim's document
    is the one made when it was submitted, and the page says it was not remade.
  - A withdrawn claim counts in Admin, Reports under "Expense Claims" and "Total value", as a
    rejected claim does, and has its own bar under "by status".
  - The API's list accepts `?status=Withdrawn` (it answered 400), and the report builder can
    filter on it.
  - An approver who decides a claim a moment after it was withdrawn is told "This claim is no
    longer waiting for a decision…" instead of "already been decided by another approver".
  - The Expenses guide ("Status Meanings") and the FAQ ("What does each expense status mean?")
    each have an entry for Withdrawn.
  - New test `tools/expense-withdraw-selftest.php` (37 checks): the list of choices is the same
    in all four places, and the upgrade, run by the real upgrader on real rows, changes only
    claims with an empty status.
  - `tools/access-pages-selftest.php`: 240 to 278 checks (new part M). It presses the real
    button with real sign-ins and reads the STORED status back; presses with no form token, a
    made-up one and another person's are refused; each message is read from the page the person
    lands on, once, and not again on a reload. The check it replaces looked only at the address
    the person was sent to, which is how this went unnoticed.
  - Run on MySQL 8.0.36 and 8.4.11. Not run on MariaDB. Checked by a fresh Claude agent standing
    in for Codex; not reviewed by Codex yet.
- fix(core): **the portal's own backup works again once a calendar has been
  imported, and a snapshot it takes can be put back** (#577, #578, #579).
  Three faults, found by running a real restore:
  an imported calendar event keeps raw bytes that the old snapshot could not
  write, so from the first imported event no snapshot could be taken and the
  upgrade page refused to run; a record pointing at a newer record of the same
  table (a venue agreement replaced by a later one) made a snapshot that could
  never be restored; and a large activity log needed four times the memory a
  shared host allows. `DbBackup` now writes one file per table with one row per
  line (`tblName.jsonl`), reads and writes a row at a time (60,000 rows under a
  32 MB limit, 4 MB used), carries raw bytes as base64 and says which columns
  those are, writes self-pointing rows with the pointer empty and fills it in
  afterwards without disturbing "last changed" dates, reads every table as it
  stood at one moment, and carries moments in UTC whatever time zone the
  host's database uses. Snapshots in the old form still restore. A column type
  the backup has not been taught (BIT, FLOAT) is refused by name when the
  snapshot is taken, not copied wrongly.
- feat(admin): **"Check this snapshot" on Backup Management** (#578). It
  rehearses the whole restore: every row is written and then taken out again,
  so the answer is the restore's own answer and nothing is changed. It takes
  as long as a restore and closes the portal while it runs. The old check only
  read the files and said "fine" about snapshots that could not be restored.
- fix(admin): **a failed snapshot is no longer counted as a backup** (#577).
  The health page and the freshness alert read the newest folder, and a failed
  snapshot leaves the newest folder there is, so the day backups stopped
  working both said "Fresh". Both now read the newest FINISHED snapshot and
  say how many unfinished folders there are. The clean-up after an upgrade
  kept the unfinished folders and deleted the real backups; it now counts
  finished snapshots only, removes an unfinished folder only after 24 hours
  untouched, and never touches a folder the portal did not make. A snapshot
  that fails removes its own folder.
- fix(core): **a snapshot taken before an upgrade can be put back after it
  when the upgrade added a link between two tables** (found by package E's
  check). A row pointing at a row that is not in the snapshot is put back with
  that pointer empty, but only where the link's own rule in the database is
  "empty the pointer when the other row is deleted"; each one is counted and
  named in the result and the activity log. For any other rule the restore is
  refused, naming the table, the column and the missing row.
- fix(admin): the backup, check and restore pages ask PHP for 15 minutes
  (#579), a check or restore carries on if the browser stops waiting, and each
  leaves a line in the activity log saying how it ended. The confirmation
  boxes no longer say TRUNCATE.
- fix(core): **a restore no longer says yes when the database says it changed
  a value** (found by the independent checks of #577). The portal never set
  how strict the database is, so a restore ran with the host's setting, and on
  a loosely run host a value that no longer fitted its column (after an
  upgrade changed the column) was cut short, replaced or rounded while the
  restore reported success. A restore, a one-table restore and "Check this
  snapshot" now run in a strict session of their own on every server, with
  the database's notes switched on, refuse such a value naming the column
  (also when the database only notes that it changed it), and put the
  connection's own settings back afterwards; if those cannot be put back the
  connection is closed and the result says so. Dates of zero and a record
  numbered 0 go back exactly. What this cannot catch: some changes the
  database makes without any word at all when a column's kind was changed
  after the snapshot (seven kinds are known and listed at the top of
  `DbBackup.php` and in the recovery guide).
- fix(core): **a snapshot that cannot be put back whole now says so the
  moment it is taken.** On a host that runs its database loosely, a value
  that is not on its column's list of choices is stored as an empty text
  without a word, and a restore refuses it, so one such value made every
  snapshot of that database impossible to put back and nobody was told until
  the day it was needed. The snapshot is still taken; its result and its
  manifest (`unlisted_values`) name each table and column and how many, and
  "Run snapshot now", the snapshot list, the snapshot's own page and the
  health page all say that it cannot be put back whole and what to do (mend
  the rows, take a new snapshot). **The upgrade page stops before changing
  anything** when its own snapshot carries this warning, and offers a global
  administrator a second button to go on deliberately, which is recorded in
  the activity log. A database with no such value pays for no extra statement.
- fix(admin): **the Delete button on Backup Management removed files it should
  not** (older code). Given a shortcut (link) in the backups folder it deleted
  every file in the folder the link pointed at, and given the name "." it
  deleted the loose files in the backups folder. Every button that takes a
  snapshot's name now uses one test (letters, digits, hyphens and underscores
  only), applied before anything is closed or deleted; Delete removes a link
  as a link. A name holding a zero byte no longer leaves the portal closed.
  Inspect refuses a link that leads out of the backups folder, as the check
  and the restore do; it used to show the outside folder's manifest.
- fix(admin): **a restore or a check leaves the portal as it found it.** The
  two Restore buttons reopened a portal an administrator had closed and
  replaced their closing message; now the portal is still closed, with their
  message, afterwards. The portal is put back in every case, including when
  something goes wrong, except the one where the database connection had to
  be closed: the page then says so plainly, says where the portal stands,
  and says how to reopen it.
- fix(admin): **the upgrade page shows why an upgrade did not start.** "Backup
  failed" and "Invalid or expired form token" were stored and never shown, so
  the page simply came back unchanged. Both are now shown, once. The "Backup
  failed" message says what went wrong and what to do about the cause, and no
  longer names the setting that skips the backup.
- fix(core): **a restore says what it does to tables that are not in the
  snapshot.** A table added after the snapshot was taken can lose records
  linked to the ones being replaced, by the database's own rule for the link.
  That is now counted (following the links onward, and counting a table's
  links to itself), named in a warning and in the activity log, and shown
  beforehand by "Check this snapshot". A restore that fails now confirms that
  it was undone before saying so; a table that cannot undo (not InnoDB) is
  refused before anything is touched; a large record that the database
  accepts by itself goes back even when smaller ones were waiting with it,
  and costs about four times its size in memory to put back, not six; a
  record larger than the database's limit is refused in words before it is
  sent (it used to end the connection, and the page could only say "MySQL
  server has gone away"); and on the night the clocks go back a second
  snapshot an hour after the first is no longer refused for having the same
  name.
- test: new `tools/backup-restore-selftest.php` (real snapshots and restores,
  every value of every table compared by a reader that shares no code with the
  class; MySQL 8.0.36 and 8.4.11) and `tools/backup-page-selftest.php` (the
  real pages). New check `tools/audit-checks/check_backup_column_types.py`
  fails when the install script gains a column type or a link the backup
  cannot carry, or an `ALTER TABLE` or `CREATE TABLE` statement it cannot
  read (a table named with its database in front is read), and checks a
  sample snapshot against two new schema files in `web/_core/data/`.
- fix(upgrade): an older database no longer stops part-way through upgrading,
  and ends up the same as a new install (roll-out package E; #553, #554, #555).
  - **The upgrade stopped at migration 124 on any database installed before
    25 June 2026** ("Unknown column 'capacityCount'"), and nothing after it
    ran (#555). Migration 124 now adds that column itself when it is missing.
    The file was corrected in place, because a later migration would never
    have been reached. A database that already ran the old version is not
    changed by the new one.
  - **The podcast feed answered "page not found" on every upgraded database.**
    Its two addresses were written into the install script and into no
    migration. Migration 210 adds them, and two more addresses
    (`admin/release-notes`, `help/calendar`) and three Calendar settings that
    were missing the same way on older databases.
  - **A second organisation could not use a category short name the first one
    already used**, on databases installed before 8 March 2026 (#553).
    Migration 210 removes the old key that caused it.
  - **Deleting an event left its attendance sessions pointing at nothing**, on
    every database built by the install script (#554). Migration 210 adds the
    missing link, and the install script now adds it too, so a database built
    from that file alone has it. Before the link can be added, pointers to
    events that are gone are emptied: this is the one place a customer's own
    rows are changed. The session, its date, its counts, its notes and its
    "last changed" time are untouched. A link already there under another name
    is left as it is, and no second one is added.
  - **If the events table itself is missing** (a restore that got part-way)
    while sessions still point at events, both the upgrade and the installer
    stop with the database's own message ("Table 'tblEvents' doesn't exist")
    and change nothing. Put the table back from a backup and run again.
    Neither can tell an events table that is there but has not got its rows
    back yet: restore the whole database before running the installer over it.
  - **A backup taken before this upgrade can hold such a dead pointer**, and
    the database now refuses that row. Whatever restores an older backup has
    to empty those pointers first. That is the restore code's job
    (`web/_core/DbBackup.php`), and it is done by the backup fixes that
    follow this change (#577, #578, #579). **Until those are on the branch
    too, this change must not be released by itself.**
  - Two small tidy-ups in migration 210 so that an upgraded database matches a
    new install exactly: two keys on `tblTrustedDevices` renamed, and the
    default colour for a NEW organisation (existing ones keep theirs).
  - **The migration test now upgrades old databases** (two new phases): from
    the last release (the `main` branch), and from the install script of
    6 March 2026, kept as a frozen file guarded by a checksum. Each must end
    up equal to a new install: every column, index, link between tables and
    address must match exactly, every setting and recorded migration must be
    there, every setting both have must hold the same value, and a setting
    only the upgraded database has is a failure unless it is named.
  - **The test also holds the installer's replay against the install script
    alone**, and loads the install script a second time over a finished
    database, which must change nothing. Five existing differences are named
    in the test and printed on every run: two links only migration 179 adds,
    and three apps that migrations 008, 009 and 017 switch on over the install
    script's "off". A named difference that is no longer seen fails the test.
  - **The one statement that changes a customer's own rows has three checks**:
    attendance sessions are planted and every column of every session is
    looked at after migration 210 runs, and again after the install script
    runs; and with the events table taken away, both must stop and change
    nothing.
  - None of the test's new parts has run on GitHub yet.
  - Codex has not reviewed this.
- tooling: **scripts that build and guard the installation zip, part 1** (#499,
  roll-out package F6). `tools/package/build.sh` makes the package from git at
  one commit, never from a working folder, and `tools/package/check-package.py`
  checks the folder and then the zip; no zip exists unless both passed. A
  package may hold ONLY kinds of file that are positively allowed, each in its
  allowed place (17 kinds across 1,201 files today); anything else fails the
  build. Inside the web folder no name may carry a PHP ending after any of its
  dots except the eight named PHP files, and every `.htaccess` is named with a
  checksum. The secrets search (gitleaks) is made to read every kind of file.
  The PDF library is compared file by file against a committed list.
  `tools/package/package-selftest.sh` plants faults in a scratch clone and
  requires each to be refused for its own reason (226 checks; it needs the
  internet and takes tens of minutes). **Nothing can be built for release
  yet**: five lines in packaged files still carry one customer's address
  (#500), the installation guide is not written, and the code cannot yet run
  from a web folder with another name. The workflow that would publish a
  package is proposed text only.
- fix(tooling): **`tools/download-dompdf.sh` fetched a copy of the PDF library
  that could not make a PDF.** It downloaded the tag's source archive, which
  has no loader file (`web/_core/Pdf.php` needs it). It now downloads the
  publisher's release file, pinned by its SHA-256, and the deploy uses the same
  script. Its files are no longer installed writable by everybody, and the pin
  can no longer be replaced from the environment. Whether PDFs worked on any
  server before this is not known.
- tooling: new `tools/download-cdn-fallbacks.sh` fetches the local copies of
  Bootstrap and Font Awesome that pages fall back to when the outside copy
  cannot be reached. Nothing fetched them before.
- security(access): **who may see and do what, tightened** (roll-out packages B2a and B2b; #534, #548, #574, part of #511 and #536).

  **Security: what somebody can no longer see or do**

  - **A switched-off or erased account is signed out on its next click.** Somebody who was offboarded,
    switched off by an administrator or erased used to stay signed in on every browser they had open, with
    every right they had before. The portal now asks, once for each request that carries a signed-in session,
    whether the account is still switched on, and ends the session if it is not. Switching the account back on
    does not bring an old session back. Cost: one extra database look-up for a signed-in request; none for
    signed-out visitors, scheduled jobs or API keys. (`Auth::ensureSession()`)
  - **Event registrations can no longer be approved or rejected from another organisation.** An administrator
    of one organisation could decide a registration for another organisation's event by posting its numbers.
  - **Only an organisation's own people can record or delete attendance.** Any signed-in account, of any
    organisation, could add, change and delete another organisation's head counts. (#511 names the wider
    cause.)
  - **Venue bookings are shown only to the organisation's own people.** The year schedule, one booking, one
    venue's own page, the CSV and printable exports and the two data endpoints answered any signed-in
    account. The booked days on the calendar page (every view: month, week, day and year) were shown to
    everybody, signed-out visitors included, because `/calendar` is a public address. All of them now ask
    `Venues::canView()`. The rest of the calendar page is unchanged.
  - **The Team Hub data endpoints answer only the event's own team**, as the Team Hub page always did. An
    API key holding `eventhub:read` is unchanged.
  - **Live chat and the viewer count (#548).** Anybody, signed in or not, could read an internal event's
    approved chat messages and prompts, start a viewer session for any event number and then post into its
    chat. An internal event's chat now works only inside the portal: for a signed-in person who may see the
    event, and only when their browser says the request was made by one of the portal's own pages (in
    practice `/live`). A page on another website, or on another address of the same site, can no longer read,
    join or post through a signed-in member who happens to open it. A public event's chat still needs no
    sign-in.

  **Fixed**

  - **A child's check-in and a decision card are no longer lost over a bad event number.** A number that
    matched no event stopped the whole record being saved; another organisation's number was stored. Only the
    organisation's own event number is kept now, and the record is always saved.
  - **Expenses, and the erasure and "delete my account" pages, stay inside the organisation's own address.**
    44 redirects, links, form targets and breadcrumb links in the Expenses app, and 53 more in the eight
    erasure and "delete my account" pages, were bare addresses, which land on the default organisation when
    organisations are told apart by the first part of the address. (Part of #536; the rest of the portal is
    not done.)
  - **A treasurer or administrator opening their own approved claim is told who pays it.**
  - **A chat name longer than 40 characters no longer answers "500".** The server cut a name at 60 characters
    and the column holds 40. The chat box itself stops at 40, so only a program posting directly met it. A
    longer name is now cut to its first 40 characters and the message is stored.

  **Removed**

  - `web/_apps/api/livestream-ping.php`. No address could ever reach it.

  **Checks**

  - New: `tools/audit-checks/check_site_aware_addresses.py`. Fails on a bare address in the places on its own
    short list (the Expenses app, the administrator's erasure pages, five single account pages), and fails
    when any part of a listed place cannot be read. It reports a redirect (`Location:` in any capitals), a
    link or form target (with or without quotes), and any quoted text that starts with a single "/", followed
    through a space or a PHP tag to its closing quote. Its header lists what it cannot see. It is not yet
    run on pull requests; that workflow change is approved and is made once, later.
  - `tools/access-pages-selftest.php`: 94 to 240 checks (parts F to L).
  - `tools/erasure-pages-selftest.php`: 57 to 66 checks (new part I: every press on the erasure pages,
    "Execute" included, lands inside the organisation's own address, and that address opens).
  - `tools/feed-admin-selftest.php` W59: 294 to 298 checks in part W.

- tooling: **the rules for where a deploy puts files are now a tested script**
  (#500, roll-out package F7). `tools/deploy/resolve-settings.sh` decides, from
  the settings, which hosting login a deploy uses and which folder each release
  channel (live, beta, alpha) goes to, and refuses whenever it cannot be sure.
  Each channel is a complete, separate installation in its own folder; hosting
  may differ per channel; no web address is built in. It also judges whether
  the folder found on the server may be written to: one marked as this
  channel's, an empty one, or (only when a person ticks "adopt existing
  folder") a portal and nothing else. It never prints a setting's value.
  `tools/deploy/resolve-settings-selftest.sh` proves every rule and refusal
  (366 checks). **No workflow uses the script yet**: the replacement
  `deploy.yml` is written and proved against a real upload tool in a
  container, and is placed once the code can read its channel from a file.
- docs: **the disaster-recovery runbook now works** (#488). The old one told
  an administrator to restore from a folder name the portal has never used, to
  delete from a sessions table that does not exist, and to replace the
  encryption key in a way that makes every stored secret unreadable. It is
  rewritten. Every command in it was run on 12 October 2026 against a throwaway
  copy of release 1.4.0 (MySQL 8.0.36), and each is marked "Tested" or "Your
  host". A database damaged, then dropped outright, then lost together with
  every snapshot on the server, was put back each time with all 224 tables
  identical. It also says plainly what the portal cannot do today: no button
  signs everybody out or forces new passwords, and nothing changes the
  encryption key (#582); and that a restore after a break-in brings back every
  old password and switches back on any account that was switched off.
- docs: **three faults in the portal's own backup, found by this proof and not
  fixed in this change**, are written into the runbook with what to do
  meanwhile (keep a full dump as well; that route was proved too): #577, #578
  and #579.
- fix(tooling): **the example off-site backup script**
  (`tools/offsite-backup/sync-offsite.sh.example`) had four faults. It looked
  for a folder name the portal never makes, so it never sent anything. Once 20
  copies were stored it deleted the newest ones, including the one it had just
  sent, and reported success. It left the unencrypted snapshot readable by
  other accounts while it uploaded. And a failed upload was silent. It now
  takes the newest finished snapshot by name; counts and deletes only files of
  the portal's own exact name, never the copy just sent; works in a private
  folder that only its own account can open; and every way it can stop prints
  an ERROR line, records the failure and leaves nothing behind.
  `docs/offsite-backup-setup.md` is rewritten to match.
- docs: **the erasure policy no longer names tables that do not exist**
  (`docs/gdpr-erasure-policy.md`).
- tooling: new `tools/audit-checks/check_docs_table_refs.py`. Every table a
  document under `docs/` names must exist in `full_schema.sql`. It failed on
  six names before the documents were corrected.
- fix(account): **the "My volunteering" page no longer crashes for everyone**
  (#547). Its list of events asked MySQL 8 for a column it had not grouped,
  which MySQL 8 refuses by default (error 1055), so every member saw an error
  page. The list now comes from `Events::volunteeringFor()`, and the
  coordinator flag is wrapped in `MAX(...)`, which gives the same answer. New
  `tools/my-volunteering-selftest.php` runs it on a real database and runs the
  page itself for each kind of member.
- fix(core): **three wrong library fingerprints corrected** (#568). A page can
  pin a library to a fingerprint, and the browser refuses the file when the two
  differ. Bootstrap's stylesheet, Bootstrap's script and Font Awesome were
  pinned to wrong values in `Asset.php`, so pages lost their styling and logged
  a console error. A fourth wrong copy was in the installer
  (`web/_install/index.php`), the first screen a new customer sees. Each value
  was worked out again from the real file. New `tools/cdn-sri-selftest.php`
  downloads every library pinned in a PHP, HTML or JavaScript file under
  `web/` (14 today) and works its fingerprint out again, so a wrong value now
  FAILS. A fingerprint it cannot match to a file to download also fails, so
  nothing is stepped past quietly. It needs the internet, and reports
  "NOT CHECKED" and exits 1 for any file it cannot download.
  `check_cdn_sri.py` now reads the installer as well.
- fix(core): **asking PHP for more time no longer crashes on hosts that switch
  `set_time_limit` off** (#563). Six places called it bare, which on such a
  host ends the request with "Call to undefined function" (the `@` does not
  help). They now call `TimeLimit::atLeast()` (new, `web/_core/TimeLimit.php`),
  which checks the function exists, never lowers a limit, leaves "no limit"
  alone and does not restart the clock when the limit is already enough. A
  side effect: the four jobs that set 300 seconds without looking can no longer
  shorten a longer limit. New `tools/time-limit-selftest.php` and
  `tools/audit-checks/check_time_limit_calls.py`.
- feat(erasure): giving records keep a donor's name for a set time, then
  clear it by themselves (roll-out package A2b; the owner's decisions of
  11 October 2026). **Until this change an erasure took the donor off every
  gift at once, kept a tax declaration with no name anywhere, and nothing
  ever cleared what was kept.**
  - **What is kept.** When a donor is erased, the gifts their organisation
    has chosen to keep identified keep the donor's account number, and the
    donor's name and home address are held in a new table,
    `tblGivingKeptDonors`. By default that is only gifts covered by a tax
    declaration, a tax receipt or a giving statement; an organisation can
    choose every gift. Every other gift keeps its amount, date and method and
    loses the name at once, and so does a counted envelope (with the one
    exception further down). The account is emptied exactly as before.
  - **Until when.** A number of years, set by each organisation, counted
    from the END OF THE FINANCIAL YEAR of the donor's last kept gift. The
    portal had no financial-year setting; there is one now, and until it is
    filled in one full year after the last gift is used, which can keep a
    record up to a year longer than needed and never clears early.
  - **A country per organisation.** Each organisation says which country's
    rules it follows; the portal SUGGESTS a figure for it from a data file
    of researched figures (211 countries), shows how strong the source is
    and links to what was read. The installation's own address ("GB" unless
    changed) is deliberately not used. No country set, or no figure found:
    10 years, flagged as a cautious placeholder. The portal never suggests
    fewer than 6. **Every figure is a suggestion, not legal advice.**
  - **Nothing is cleared before an administrator ticks "confirmed with our
    adviser", and nothing while "hold" is on.** A change to the settings
    that would clear kept records straight away is refused until a second
    box is ticked. An organisation's rule is read from its own settings
    only: nothing set for the whole installation can confirm a figure, or
    choose a country, for an organisation. (A hold set for the whole
    installation does hold everybody.)
  - **When the time is up** the retention job removes the name, the address,
    the link to the donor and the kept statement files together, and adds a
    line to the original erasure's audit trail. No record is deleted and no
    amount changes. The job reports these figures separately and does not
    count them as deletions.
  - **Statement files** for kept gifts are kept with them, where they used
    to be deleted at erasure.
  - **The tax claim file** names an erased donor from the kept record while
    it is kept; it used to send "[Deleted User]". An erased donor is never
    offered, or sent, a new statement.
  - **New page:** Admin, Giving records (`/admin/settings/giving-records`),
    for an organisation's administrators.
  - **Migration 209** adds the table, seven settings and the page's two
    addresses. **Run the database upgrade after deploying:** until it has
    run, every erasure, of a donor or not, stops at its giving-records step
    before anything of the person's is changed by it, puts the request back
    and says to upgrade. A donor erased before this release has no name kept
    anywhere, and that cannot be put back.
  - **A kept name is cleared only once the donor's account has really been
    emptied.** The kept name is written near the start of an erasure and the
    account is emptied about half way through it, and a run can stop
    anywhere. So the clearing asks whether the account has been emptied,
    counts from the newest gift still linked to the donor, not only from the
    date written down at the time, and decides with the records locked. A
    request that is run again works the kept record out afresh; if the
    account was already emptied, it uses the wider of the rule in force on
    that day and the rule now, and never empties the kept name itself. A request whose run has already
    removed or changed anything **cannot be cancelled**: what a run changes
    cannot be put back, so it has to be run again to its end.
  - **An envelope in a count that is not yet closed keeps its giver** when
    the gift it will become is one that keeps the donor's name; every other
    envelope loses its giver at once.
  - **A statement a donor downloaded for themselves is kept** when every
    gift on it is a kept gift.
  - **The seven settings can only be changed on the Giving records page.**
    The general settings editor refuses them (a global administrator may
    still set the installation-wide hold there). The page shows an
    installation-wide hold separately from the organisation's own.
  - The privacy page no longer tells every member that financial records are
    kept "to comply with HMRC requirements" for one installation-wide number
    of years.
  - **Not done:** the scheduled job still has to be switched on by a global
    administrator; the page says so when it is off. Codex has not reviewed
    this.
- fix(erasure): erasing a person no longer deletes other people's records, and
  "Delete my account" now files an ordinary erasure request (roll-out package
  A2a; the owner's decisions of 11 October 2026). **Until this fix, erasing a
  follow-up worker deleted every visitor, salvation card, task and rota slot
  that had been given to them, and every swap request sent to them.**
  - **Kept, with the worker taken off:** visitors, salvation cards, tasks and
    rota slots. A swap request aimed at the erased person is kept and, if it
    was still waiting, cancelled. `assignedToID` and `targetUserID` are now
    classed as "acted on it", so the eraser refuses to delete on either.
  - **A visitor record that IS the erased person** (they later became a
    member) is deleted, with the follow-up notes under it. Nothing found it
    before.
  - **"Who did it" is cleared on thirteen tables that had no instruction at
    all:** shared documents, approval steps, visitor follow-up notes, service
    plans, project updates, event messages, the song-licence log, bank
    imports and bank lines, files attached to equipment and to hire
    agreements, expense payments, and children's check-ins. Also who made a
    pastoral visit and who its follow-up was given to, and the administrator
    named on an erasure request they handled. Every record itself stays.
  - **And on 31 more columns, where a table has a SECOND "who did it" column
    (#492).** An instruction used to match rows on one column only, so a gift
    somebody else gave and the erased person recorded kept their number; so
    did an event they last edited, a count they took, a prayer request given
    to them. Each such column now has an instruction of its own. For a table
    the eraser handles from the written list, naming the column in
    `web/_core/personal-data-catalogue.php` is all it takes.
  - **Migration 208** lets nine "who did it" columns be empty: who wrote a
    visitor's follow-up note, who attached a file to equipment or to a hire
    agreement, who made a pastoral visit, who gave out a licence seat, who
    asked for an asset loan, who processed somebody's leaving, who made a
    room booking, and who asked for a rota swap. It also adds
    `tblErasureRequest.claimedAt`. **Run the database upgrade after
    deploying:** until it has run, Execute refuses to start an erasure and
    says so.
  - **Kept records no longer vanish from the pages that list them** when the
    person who made them is forgotten: a visitor's follow-up notes, the
    visits on a pastoral case, a room booking (on the room's page and in the
    approvals queue), and an approval on an expense claim (on its page and
    its PDF). They show "Somebody no longer recorded", or "Approver's details
    removed". Without this a room looked free for a time it was booked for,
    and a claim looked as though nobody had approved it.
  - **An accepted invitation is deleted with its person.** It holds their own
    email address. An invitation they only sent to somebody else is kept,
    with who sent it removed.
  - **"Delete my account" no longer empties the account on the spot.** It
    files an erasure request, and an administrator carries it out. Typing
    DELETE MY ACCOUNT is the confirmation, so the request goes straight to
    the administrators' list and no confirmation email is sent. The person
    stays signed in and is told what happens next. There is now one way an
    account is emptied. One difference people may notice: the old page
    deleted leadership-only prayer requests; an erasure keeps their text and
    removes the name.
  - **An administrator can confirm a waiting request on the person's
    behalf.** A request filed from "Request data erasure" waits for the person
    to click an emailed link. If that email could not be sent, nobody could
    move the request on. Admin, Erasure requests now has "Confirm on the
    person's behalf" on such a request. The request's report records who
    confirmed it and that they state they checked the person's identity.
  - **The key to the person's private calendar feed is cleared**, so the feed
    address stops answering. It used to go on working after the erasure. So
    are their notification choices.
  - **The person's photo and biography are cleared**, and a photo file kept
    in the portal's uploads folder is deleted. An emptied account is no
    longer an administrator. A photo path that points outside the uploads
    folder, or at a file that is not an image, is refused and reported, not
    followed.
  - **Zoom:** a meeting made through the erased person's own Zoom connection
    goes with that connection (it always did; the written list now says so).
    One they made through somebody else's connection is kept.
  - **A request stuck at "processing"** (its run was stopped by the web host)
    can be put back from Admin, Erasure requests, once it has shown no sign
    of a run for 15 minutes. Another open request from the same account that
    was stuck is now closed together with the one that ran.
  - **Smaller fixes:** the send-log step replaced text inside longer
    addresses (erasing `ann@x.org` altered `joann@x.org`); Cancel could
    cancel a completed request; a request closed at another organisation
    pointed its administrator at a report they cannot open;
    `/account/erasure-request` printed "retaind".
  - **Also cleared:** who sent an event in for the calendar, who marked an
    error as dealt with, and which administrator ran a database upgrade. The
    database does not link those columns to an account, so nothing had found
    them. The real-database test now also looks for person columns by the
    shape of their name.
  - **A second press of "Ask for my account to be deleted"** (a double-click)
    is told the request is already recorded. It used to answer "Nothing was
    changed. Please try again." a moment after filing the request.
  - **Not done, and pinned by name in the test:** a step of an approval route
    that is given to one named person keeps that person's account number
    after they are erased (`tblWorkflowSteps.assigneeValue`). Emptying it
    would leave a step nobody can approve, so what the step should become
    needs a decision. The giver on a counted envelope waits for the
    giving-records package.
  - **Not done, and needs a change elsewhere:** an erased person who is still
    signed in on some device stays signed in there until that session ends.
    The portal keeps sessions as PHP's own files and does not check, page by
    page, that the account behind one is still active. After the erasure the
    session belongs to no organisation and has no administrator rights, and
    the person cannot sign in again.
  - New `tools/erasure-pages-selftest.php` drives the real pages, on the
    model of `tools/access-pages-selftest.php`. Run it with the two erasure
    self-tests; none of the three is wired into GitHub yet.
  - Proven on MySQL 8.0.36 and 8.4.11, on a fresh install and on a database
    upgraded from the previous release. Codex has not reviewed this.
- fix(core,api): switching an app off now switches off its data endpoints
  and its stray pages too (#478). Before this, Admin → Apps hid an app's
  pages and left every `/api/…` address of that app answering, for a
  signed-in person and for an API key alike; and a few pages that live in an
  app's folder under an address of their own were never tied to the app at
  all. Now:
  - An `/api/{folder}/…` or `/api/v1/{resource}` request whose app is off
    is answered 403: "The <app name> app is switched off for this
    organisation." For an API key the switch that counts is the KEY's own
    organisation's, whatever address the key was sent to.
  - Which app each `api` folder belongs to is written out by hand in
    `AppRegistry::API_FOLDER_APPS` (`events` belongs to Calendar, `livechat`
    to Live Chat). Three folders belong to the portal itself and are never
    refused: `push`, `tours` and `users`. **A new `api` folder needs a line
    there, or it is refused.**
  - `/attend`, `/attend/save`, `/calendar.ics` and `/account/calendar-feed`
    now follow the Calendar switch, `/recordings.rss` the Recordings switch
    and `/visit` the Visitors switch (`AppRegistry::ROUTES_OUTSIDE_PREFIX`).
  - **`/unsubscribe` is deliberately left alone.** Somebody already
    receiving mail must be able to stop it whatever the Newsletter switch
    says.
  - **Check Admin → Apps before upgrading.** An organisation whose Calendar
    app is off loses `/api/events/*`, the Team Hub endpoints, its calendar
    feed link and the check-in page for people not signed in. That is the
    fix working, but anybody relying on the old leak will notice. Measured
    on MySQL 8.0.36 on 11 October 2026: a fresh install (the install script
    and then every numbered migration, as the installer runs them) ends
    with Calendar, Attendance and Leadership ON, and with Livestream,
    Newsletter, Recordings, Service Plans, Venue Bookings, Visitors and Live
    Chat OFF. The install script on its own leaves Calendar, Attendance and
    Leadership off; later migrations switch them on.
  - Not covered: a switched-off app's scheduled jobs, the personal pages
    under `/account/` that belong to an app, `/auth/invite`, the countdown
    widget and the short addresses `/e/…`, `/f/…`, `/a/…`, `/os/…`.
  - New `tools/app-switch-selftest.php` (no database needed) fails for an
    `api` folder with no line, for a folder tied to the wrong app, for a
    page of a switchable app that is not tied to it, and for any change to
    the gate that its own copy does not match. New
    `tools/access-pages-selftest.php` (needs a throwaway database) asks the
    running portal the same questions, folder by folder, for a session and
    for an API key.
- fix(core): the "app is disabled" page names the right app (#570). It named
  whichever app came last in the menu ("Forms Builder is disabled" when
  Calendar was the one switched off), because the menu template reused the
  same variable. Its two links now stay inside the organisation's own
  address.
- fix(expenses): nobody can approve, reject or pay their own expense claim
  (#545, the owner's decision of 21 September 2026). Before this a department
  lead could approve a claim they had made, a treasurer could record their
  own claim as paid, and an administrator could do both. Now:
  - Both handlers refuse, administrators included: "You cannot approve or
    reject your own expense claim. It must be decided by the other leads and
    required approvers of its department or, when there are none, by another
    approver or an administrator." and "You cannot record payment of your own
    expense claim. Another treasurer or an administrator must pay it." A
    claimant who could otherwise have decided the claim is told the same on
    the claim's own page.
  - A claim no longer waits for its own claimant. The claimant is left off
    the list of leads and required approvers whose approval it needs; when
    that leaves nobody, one approval from anybody else with authority is
    enough. Every other lead and required approver is still needed, and an
    administrator's approval still does not stand in for theirs.
  - A person's own claims are left out of the approvals list and the
    treasury list (each says so in one line), of the "Go to Approvals" and
    "Go to Treasury" buttons on the claim page, of the e-mail that asks
    approvers for a decision, and of the administrator's "Pending Claims"
    number on the dashboard.
  - The rule is in one new class, `Portal\Core\ExpenseRules`, checked by
    the new `tools/expense-rules-selftest.php`, and through the real
    handlers by `tools/access-pages-selftest.php`.
  - Claims approved or paid by their own claimant before this change keep
    their history as recorded. A department whose only approver is the
    claimant needs an administrator, or a second approver, to decide.
- fix(calendar): two Team Hub data endpoints and one venue page no longer
  show an internal event to somebody outside its organisation (#534, items 3
  and 4).
  - `/api/calendar/hub-resources` and `/api/calendar/hub-videos` answered
    ANY signed-in account, from any organisation, with an internal event's
    team links, notes and videos. They now use the one shared rule
    (`EventVisibility`), as the event's page does: a refused event is the
    same "Event not found" as a missing one. An API key is answered as
    before.
  - The read-only card of a venue booking (`/venues/booking?id=N`) printed
    "Linked event: <name>" for any signed-in account, for an internal event
    and a draft alike. It now names the event only to somebody who may see
    it. The booking itself is still shown to any signed-in account; that is
    a separate question.
  - Still open, written up rather than changed: for a signed-in person the
    two hub endpoints answer anybody who may see the EVENT, while the Team
    Hub page itself lets in only the event's team.
  - The table #534 asked for (six kinds of viewer by five kinds of event, on
    the event's page, the single-event download and the two hub endpoints)
    is section W59 of `tools/feed-admin-selftest.php`, with a control that
    takes the rule out of one page and checks the table notices.
- fix(privacy): erasing a person's data now finishes, and says truthfully
  what it did (#510, #535). **Until this fix every erasure crashed part
  way.** The routine deleted the erasure request it was carrying out, the
  database deleted that request's audit lines with it, and the next audit
  line had nothing to belong to. The administrator saw a server error; the
  person was left half erased with no record of what had been done.
  - The request and its audit lines are now kept as the organisation's
    record. The request is closed as the last step, and the person's
    account number, name, email address and notes are cleared from it (and
    from any other request from the same account).
  - An erasure is a list of steps, each saved with its own audit line. A
    step that fails is recorded and the rest still run; the request then
    goes back to "pending review" unchanged, to be run again. If the audit
    trail itself cannot be written, the run stops.
  - Every step writes one audit line, including "nothing held".
  - The erasure page shows one of three plain messages (completed; some
    steps could not be done; stopped) on the list of requests. It used to
    say "Erasure completed" whatever had happened, and showed it on the
    wrong visit.
  - Eight tables the written list promised to deal with were never
    touched (#535, which named the wrong four). Now reached:
    `tblExternalFeedRuns.triggeredByID`, `tblRecording.presenterID`,
    `tblServicePlanItem.presenterID`, `tblLiveChatMessages.moderatedByID`.
    Four tables cannot be reached at all, because nothing ties a row to an
    account (`tblLivestreamSessions`, `tblAssetOrgs`, `tblVenueImportRows`,
    `tblServicePlanItems`): the list now says so, and every erasure's
    audit trail records them as kept and not searched. Nothing clears them
    on a timer yet.
  - A kept table is recorded as kept. Three used to be recorded as "table
    missing", and the account row's own line wrongly said its address had
    been left in place. Pastoral visits a person made are recorded as
    kept, with the true reason: their number stays on each visit because
    that column may not be empty.
  - **Gift Aid declarations are now KEPT on erasure** (owner's decision,
    11 October 2026). They were deleted. Kept for tax reasons: HMRC can
    ask for a declaration for six years after the last gift claimed under
    it. That period has not been confirmed with an accountant, and nothing
    removes a declaration when it is up.
  - The list of column names that mean "a person" existed four times and
    had drifted; there is now one (`GdprEraser::LINK_COLUMNS`).
  - New test `tools/gdpr-eraser-selftest.php` runs a real erasure on a
    throwaway database; `tools/gdpr-coverage-selftest.php` now checks the
    real instructions. Neither runs on GitHub yet. Proven on MySQL 8.0.36
    only. Codex has not reviewed this.
- fix(admin): the Upgrade page (`web/_install/upgrade.php`) no longer
  breaks off part-way after running migrations (#561). After running the
  pending migrations, the page tried to draw a results table by reading
  each result's `file` and `message` — but
  `Portal\Core\Migrator::runOne()`/`runAll()` (`web/_core/Migrator.php`)
  have always returned each row under the keys `filename`, `success` and
  `error`. So `htmlspecialchars()` was handed `null` for the file column,
  and because this file turns on PHP's strict type checking, that is a
  crash (a `TypeError`), not a blank cell. Every upgrade that ran
  anything broke off part-way, even though the migrations themselves had
  genuinely run. The administrator saw the top of the Upgrade page, then
  "Something Went Wrong" where the results should have been, and nothing
  below it. The pending and executed counts near the top were the only
  signs of how it had gone. The Details column would have shown nothing
  anyway, since a `message` key was never set either. As of 25 September
  2026 the main and alpha branches still read the same two wrong keys,
  but there the Upgrade page stops even earlier, on every visit, at its
  breadcrumb trail (#508, fixed alongside this one), so the fault further
  down could not be reached. Releasing this fix to main without #508's
  would not restore the Upgrade page there. The companion migrations
  page under Admin (`web/_apps/admin/migrations/index.php`) already read
  the correct keys, so only the Upgrade page had the fault. Fixed by
  reading `filename` and `error` instead.
- ci: three pull-request checks changed. (1) The calendar self-tests (#514)
  — the reader self-test approved by the owner on 23 September 2026, the
  three database-backed ones on 24 September — now run on every pull
  request to alpha, beta or main, in a new workflow
  (`calendar-selftests.yml`). Three of its four self-tests (event
  visibility, feed resolver, feed importer) each get their own throwaway
  MySQL 8.0.36 database, in a container GitHub builds beside the job and
  deletes, data included, when the job ends; the fourth, the calendar
  reader self-test, needs no database at all — just PHP. **The reader
  self-test's own "L4" check used to fail on a machine whose PHP lacks
  seven old Windows time-zone names** such as `Asia/Katmandu`. A machine
  built to match GitHub's runner is one; whether GitHub's runner itself is
  has not been verified. That was a real product gap, not a fault in this
  change, tracked as issue #557 (high priority) and now fixed (see the
  separate entry below), so this new check is not red from its very first
  run. (2) The #552 foreign-key check
  (`check_fk_references_unique_key.py`) — approved by the owner on
  24 September 2026 — now runs in the pull-request security checks as its
  own step, check 23, wired so a crash or a missing schema file is
  reported rather than looking clean, and so it keeps running even when an
  earlier step in the same job failed. Unlike checks 9-22, it also runs on
  every pull request, including one that changes only a migration file and
  no PHP (a gap in those other checks, tracked separately as #556, is not
  fixed here). (3) The end-to-end migration test — approved by the owner
  on 24 September 2026 — now runs on MySQL 8.4.11 as well as 8.0.36, as two
  jobs side by side; its test database no longer passes a server option
  MySQL 8.4 refuses (already the default on 8.0.36), and the harness
  itself refuses to run if the server it is given is not the exact version
  it asked for. **None of these three has yet run for real on GitHub.**
  (1) and (2) run on a pull request ((1) can also be started by hand once
  its file reaches the default branch), and this branch has not been
  opened as one. (3) runs on a pull request or when alpha, beta or main is
  updated, in both cases only when certain files change, and can be
  started by hand; none of that has happened yet. (2) and (3) were proved locally, including full runs of the
  migration harness against real MySQL 8.0.36 and 8.4.11 containers. (1)
  was also proved on this Mac, but the L4 fault above was found only once
  it was proved again on a Linux machine built to match GitHub's own
  runner (Ubuntu 24.04, the same PHP 8.4 packages GitHub's `setup-php`
  step installs).
- fix(calendar): the Windows time-zone list (`web/_core/WindowsTimeZones.php`)
  now gives the CURRENT spelling of every zone, not an old one (#557). Seven
  of the 139 Windows zone names — covering India, Nepal, Myanmar, Ukraine
  ("FLE Standard Time"), Argentina, Greenland and the old "US Eastern" zone
  — used to map to spellings that are old by IANA's standard, the one that
  actually decides what a zone is called (`Asia/Calcutta`, `Asia/Katmandu`,
  `Asia/Rangoon`, `Europe/Kiev`, `America/Buenos_Aires`, `America/Godthab`,
  `America/Indianapolis`). ICU has NOT retired these spellings —
  ICU's own preferred name for "Kolkata" is still `Asia/Calcutta` — it is
  the IANA database, the one ICU is built from, that moved on; ICU's
  `getIanaID()` method is what reports IANA's current name specifically.
  Those old spellings still work on a machine whose system time-zone data
  keeps every historical name, but a server built without the optional
  `tzdata-legacy` package — Ubuntu 24.04's own packages, among others — does
  not carry them, so `new DateTimeZone('Asia/Calcutta')` throws there. That
  is NOT silent — the calendar reader records a warning when a zone name
  does not resolve — but the event's TIME still comes out wrong: it falls
  back to the calendar's own zone instead of the zone it actually names.
  Proved: a 19:00 "India Standard Time" event, read on such a server, came
  out as 19:00 in the calendar's own (London) zone, not 13:30 there, which
  is what 19:00 in India actually is — exactly the fault the reader
  self-test's "L4" check above was found to catch on such a machine.
  `tools/generate-windows-timezones.php` now converts every answer to its
  current name with `IntlTimeZone::getIanaID()` (ICU's own answer to "what
  is the CURRENT (IANA) name for this zone"), and refuses outright — never
  a silent fallback to the old name — if that method is missing, gives no
  answer, gives a name this machine's own PHP will not accept, or (checked
  against ICU's own canonical identity) turns out to be a genuinely
  different zone rather than a pure rename. The committed list was
  regenerated; only those seven values changed. A new
  self-test check, "L6", was added: it asks the same question of every
  value already in the list, so an old spelling can never creep back in
  unnoticed, whether from a hand-edit (against the file's own warning) or a
  generator run on a machine too old to know better; it is SKIPPED, never
  PASSED, on a machine without the needed intl support. Proved on a
  throwaway Ubuntu 24.04 + PHP 8.4.26 + ICU 74.2 container matching
  GitHub's runner, without `tzdata-legacy` installed: the reader self-test
  now exits 0, with its skips named (it used to fail at "L4" on the OLD
  list); a deliberately planted old
  spelling was confirmed to still fail both "L4" and the new "L6",
  each naming the exact zone.
- test(calendar): the calendar reader self-test's check I22b now times only
  the reader's `expand()` step, not reading the file as well. It had failed on
  correct code on a busy machine (0.703 s against its 0.7 s limit). The
  limit is unchanged. A known gap remains, found at the same time: at its
  short budget, I22b often stops in an earlier loop and never reaches the
  walk through a series' dates, so it can pass with that walk's own
  deadline check removed. That is issue #558; until it is fixed, that
  deadline check must not be removed.
- fix(expenses): an expense claim can no longer get stuck for ever because a
  department's lead or required approver lacks the Expense Approver role
  (#542). Before this, the decision handler (`expenses/approve/save.php`) and
  the claim page (`expenses/view/index.php`) asked for the role before they
  looked at any department flag. The role (#516) and the flags (#517) are set
  on different pages, so a lead or required approver without the role was
  refused, while the claim still waited for their approval, which an
  administrator's approval never stands in for. Confirmed on a real database:
  the administrator's approval was recorded, the lead was refused on the claim
  page and by the handler, and the claim never reached the treasury. Now, by
  the owner's decision of 21 September 2026, a lead, approver or required
  approver of the claim's OWN department (through an active membership of the
  organisation) may open and decide that department's claims, with or without
  the role; the same kind of claim was taken to Approved and then Reimbursed.
  The join lives in one new reader, `Departments::approverDepts()`, used by
  both pages; a retired department's approvers still finish its pending
  claims. Role holders, administrators, claimants and treasurers see exactly
  what they did before, and someone with neither the role nor a flag is
  refused byte-for-byte as before, without any claim lookup. One deliberate
  change: a person whose only flag is in a DIFFERENT department now gets the
  same plain "Forbidden" answer a role holder without a flag has always had,
  instead of the "role required" redirect. The four pages that said the role
  was also needed now say the flags are enough. Not changed here: whether any
  role holder may open any claim, and who may export the CSV (#541); a person
  may still decide their own claim, as a role holder always could.
- fix(calendar): the calendar's series download (`/calendar/export?series=N`)
  no longer hands internal events to a visitor who is not signed in (#544).
  Before this, the address needed no sign-in — correctly, so that a public
  series can be subscribed to — but its lookup asked only whether an event
  was published, never whether it was public, so anybody could download every
  published event of an internal series, descriptions and locations included,
  by trying series=1, 2, 3 and so on (confirmed on a real database). The
  single-event download and the "all upcoming" download already refused
  internal events to a signed-out visitor; only the series path was missed.
  Now a signed-out visitor gets a series' public events only, and a series
  with nothing left for them answers the same "not available" page as a
  series number that matches nothing, at the same database cost. Signed-in
  behaviour is unchanged. Whether a signed-in account belongs to the event's
  own organisation is still not asked here; that comes with #514 part P2 and
  #534.
- feat(admin,core): user groups and departments now belong to one
  organisation each, and can finally be created and filled (#517). Before
  this, nothing anywhere could create a group or a department or add a
  person to one — no page, form, script or seed ever wrote the four
  tables — so department-based expense approval, workflow approval by
  group and asset ownership by group only worked if somebody edited the
  database by hand, and the installer seeds no department. Worse,
  `tblGroups` had no organisation column: measured on a real database, a
  workflow step in one organisation naming a hand-made group was offered to
  somebody who was not even a member of that organisation, a member could
  approve it through a group that belonged to no organisation at all, and
  an asset could be given to another organisation's group. Now: an organisation's
  administrators manage its groups at the new `/admin/groups` and its
  departments at `/admin/departments` (add, rename, retire, reinstate,
  delete only while unused), and add or remove people — with the lead,
  required-approver, approver, assistant and secretary flags on
  departments — on each one's Members page, through `AccountGuard`, so a
  refusal reads exactly like an account that does not exist. The two stay
  different things (owner, 21 September 2026). Every reader now matches
  only within the right organisation and only through an active
  membership: workflow approval and its e-mails by group, asset ownership
  and authority by group or department, expense approval by department
  and the expense e-mails (which now also reach a member flagged only as
  "required approver", who could see the claim but was never told). A
  retired group matches nobody; a retired department takes no new claims
  (the claim form, its save handler and the API all refuse it) while
  claims already submitted are finished by its own approvers (owner's
  answer Q3 — the alternative was proven to strand a claim for ever).
  Offboarding removes every group and department membership; "remove from
  site" removes only that organisation's. Erasure, the data export and the
  personal-data catalogue cover the new tables. New `Portal\Core\UserGroups`
  and `Portal\Core\Departments` classes, each with a proven `memberSql()`
  fragment for #514's shared-calendar audience query. Migration 203
  (`tblGroups` gains `siteID` and `isActive`; both membership tables gain
  `siteID`, `addedAt`, `addedByID`, a rule against duplicates and
  composite foreign keys tying each membership to the person's own
  membership of that organisation AND to the same organisation's group or
  department; a hand-edited database's rows are carried over the #533/#516
  way, with anything that cannot be placed without guessing listed at the
  new `/admin/users/memberships-unplaced` page for a global administrator;
  a parked group keeps its number). New automatic check
  `check_membership_queries_scoped.py` (a membership query with no
  organisation near it fails the build), and `check_account_writes_guarded.py`
  now covers the two membership tables. NOT covered: MariaDB (untested, as
  everywhere here); an independent review by Codex (not yet run — the
  comprehensive review after #514 covers it).
- fix(expenses): the treasury list (`/expenses/treasury`) is now refused to
  anyone who is not a treasurer of the organisation whose address is open,
  or an administrator (#538). Before this, the page needed only a sign-in
  and then listed every approved claim awaiting payment — title, claimant's
  full name, department, amount and date — to any signed-in account on the
  whole installation, including members of a different organisation
  (confirmed on a real database). Only the Pay button's handler ever
  checked for a role. The list page now asks the same question the Pay
  handler does, before it fetches anything, and answers with the standard
  "Access Denied" page; the Pay handler's own refusal is now that same page
  instead of a redirect back to a list the person can no longer see. The
  treasury help page now says who can open it.
- feat(admin,core): roles now belong to one organisation each, and can
  finally be given to somebody (#516). Before this, `tblRoles` was one
  portal-wide list and NOTHING anywhere could ever put a row into
  `tblUserRoles` — the members page showed a person's roles, the help page
  described where they live, but no button, form or script could give
  one, so every one of the 61 files (66 call sites) that call
  `App::hasRole()` only ever answered yes for a global administrator.
  Every organisation now gets its own copy of the same standard fourteen
  roles (treasurer, expense approver, care team, kids team, prayer team,
  asset manager, venue manager, announcement approver, small groups
  coordinator, stream moderator, staff, volunteer, visitor coordinator,
  event coordinator), may rename any of them, and may add roles of its
  own — the internal KEY each of those 61 files looks up never changes,
  only the LABEL a person sees. Holding a role is per organisation too:
  treasurer of one organisation is not treasurer of another. Grant or
  remove a role for somebody from the new "Roles" button on the Users
  page (an administrator of the organisation currently open, or a global
  administrator anywhere); rename or add roles at the new `/admin/roles`
  page. New `Portal\Core\Roles` class is the one place that knows the
  role tables, including `holdsSql()` — the exact SQL fragment #514's
  calendar-import audience picker will call to ask "does this person hold
  this role, in this organisation" directly inside a query. A hand-edited
  database's existing role holdings are carried over the same careful way
  #533 carries over accounts with no organisation: automatically where
  the portal has exactly one organisation, listed at the new
  `/admin/users/roles-unplaced` page for a global administrator to place
  by hand everywhere else. GDPR lockstep in the same change: erasure,
  the "what we hold about you" export and the personal-data catalogue all
  cover the new tables. Migration 202 (`tblRoles` gains `siteID`,
  `isStandard`, `description`; `tblUserRoles` gains `siteID`,
  `grantedAt`, `grantedByID`; new `tblUserRolesUnplaced` pen table), plus
  a new automatic check (`check_role_keys.py`) that fails the build if
  the standard role list, the migration's seed and the fresh-install
  fold-in ever disagree with each other or with what the code actually
  asks for.
- fix(core): a global administrator only, not any administrator, now walks
  past the closed sign while the portal is in maintenance mode (owner
  decision, 20 September 2026). Before this, `Maintenance::
  currentUserCanBypass()` returned `App::isAdmin()`, which is true for a
  site administrator of whichever one organisation happens to be open, and
  for an account still carrying the older, portal-wide `isAdmin` flag — so
  a half-upgraded database was reachable by far more people than the one
  kind of account that can actually finish an upgrade
  (`web/_install/upgrade.php` has always required a global administrator).
  The change is one word — `App::isUmbrellaAdmin()` — so the two gates now
  visibly agree. Nobody is locked out of anything they could actually use:
  signing in, the upgrade page itself, and the read-only health check stay
  open to everybody either way, because a site administrator who could not
  bypass maintenance was never going to be able to complete the upgrade
  regardless.
- fix(core,admin): an organisation's key can no longer take over one of the
  portal's own addresses (#515). When several organisations share one
  installation, a "path" address prefix (`/youth/calendar`) is built from
  each organisation's own key — and the key was checked only for its
  shape (lower-case letters, digits, hyphens), never for whether it
  clashed with an address the portal itself relies on. Measured on a real
  database: an organisation keyed `login` turns the bare `/login` address
  into a redirect loop that never resolves, and one keyed `offline` can
  make a signed-in visitor's browser store that organisation's own
  dashboard as the offline fallback page — the case
  `web/_core/Auth.php`'s `oldServiceWorkerWouldStore()`/`logout()`
  comments describe and point here to fix. A new class,
  `Portal\Core\ReservedKeys` (`web/_core/ReservedKeys.php`), is the one
  place that decides which keys are reserved, built from what the portal
  really answers on rather than a hand-typed list: every address
  registered in `tblRoutes` and every real file or folder in the web root
  are read LIVE, so a newly-seeded address or a newly-added folder is
  covered the instant it exists; the small set of addresses the router
  answers itself without ever consulting `tblRoutes` (sign-in, sign-out,
  the API prefix, health, the short public-link prefixes) is typed once
  as `Router::SPECIAL_ROUTE_FIRST_SEGMENTS`, and a new automatic check
  (`tools/audit-checks/check_reserved_site_keys.py`) fails the build the
  moment that list and the router's own code disagree in either
  direction. `web/_apps/admin/sites/save.php` now refuses to create an
  organisation with a reserved key, and refuses to change an EXISTING
  organisation's key to a reserved one, or to switch on an organisation
  whose already-stored key is reserved — but an organisation that already
  carried a reserved key before this fix existed is deliberately left
  working and NOT renamed automatically, because changing a key changes
  every address that organisation's members have bookmarked, shared or
  printed. Instead it is flagged in three places with the same wording
  everywhere: a new health-page probe ("Organisation keys" — amber only
  when the clash is doing real harm right now, i.e. address prefixes are
  actually in use and the clashing organisation is active; otherwise
  reported without alarming, since a light that goes amber for something
  currently harmless is exactly the kind of check people learn to
  ignore), a warning banner on the admin dashboard, and a badge on the
  organisations page. The check also confirms `save.php` is the ONLY
  code that writes `tblSites`, so a future second writer cannot quietly
  bypass the refusal.
- fix(calendar,privacy): dropped the anonymous check-in "browser
  description" column (#530). Every anonymous check-in has recorded the
  visitor's browser description alongside a scrambled sender address since
  the table was created, and nothing anywhere — not the attendance report,
  not the #525 door-figures panel built specifically to read this table —
  has ever read it. What is recorded about an anonymous check-in now is the
  count, the source (own phone / kiosk / QR code), the time, and a scrambled
  sender address that is cleared after the organisation's retention period
  (`attend.detailRetentionDays`, 90 days by default). Migration 201 drops
  the column outright rather than merely leaving it unwritten, so an
  automatic check can catch any future reference to it. Nothing that counts
  or displays a check-in ever used the dropped column, so figures before and
  after this change are unaffected; see the migration's own header for one
  narrow internal exception in how a rare edge-case row is counted while its
  detail is being cleared, which does not change any total a person sees.
- feat(attendance): the anonymous check-in counts the portal has recorded
  since September 2025 can finally be seen (#525). Somebody who checks in
  without signing in — a QR code scan, or a kiosk button at the door —
  has always been written to `tblAnonymousCheckins`, but no page, report,
  export or download ever read a single row of it. A new class,
  `Portal\Core\AnonymousCheckins` (`web/_core/AnonymousCheckins.php`), is
  now the only code that reads that table; the organisation check lives
  inside its own SQL, and none of its methods ever return the browser
  description or the scrambled sender address, so nothing built on top of
  it can leak either by accident. The counts now show in five places: the
  event's own attendance page (a per-day breakdown), the event hub (so a
  host can watch people arrive live), the attendance reports page
  (organisation totals and a per-month line, never an event name), a new
  administrators-only spreadsheet download, and the livestream dashboard
  (a door figure next to the viewer count). An administrator can also
  push an event's anonymous headcount into a real attendance session by
  hand — never automatic, and pressing the button twice replaces that one
  row instead of adding a second. Who may see the figures is a new
  three-way setting, `attend.anonCounts.visibleTo` — administrators only
  (the default), administrators and that event's coordinators, or
  whoever the page's own sign-in rule already lets in — set for the
  whole installation and changeable per organisation from a new page at
  `/admin/settings/attendance`; administrators can always see the
  figures regardless, and the spreadsheet download stays
  administrators-only no matter what the setting says, because a
  downloaded file keeps existing after a setting is tightened. The
  browser description and scrambled sender address behind each check-in
  are cleared out after `attend.detailRetentionDays` (90 days by
  default, 0 keeps them forever); each day's "probably different
  senders" figure is worked out and stored before that detail goes, so
  an old event's number does not drift afterwards, and a day that later
  gets late check-ins after its figure was stored is marked "includes
  late arrivals, so less exact". Migration 198 adds the new
  `tblAnonymousCheckinDays` table, the two settings and the four routes.
  Fixed in the same piece of work: the livestream dashboard's "top
  events" list had no organisation check at all, so an administrator of
  one organisation could see another organisation's livestreamed event
  names and viewer counts — the two figures shown above it were already
  scoped correctly, this list was not (#527).
- fix(calendar,security): the public "no login" check-in page showed the
  name and date of every published INTERNAL event to anyone who was signed
  out, and let them add a check-in against it — event numbers count up from
  1, so anyone could simply walk through them (#519). `/attend` and
  `/attend/save` (`web/_apps/calendar/anon-checkin.php` and
  `anon-checkin-save.php`) used to look an event up with only "belongs to
  this organisation, not deleted, published" and never asked whether it was
  PUBLIC. Now a public event stays open to everyone as before; an internal
  event needs a real, active member of that event's own organisation, a
  global root administrator, or — on a single-organisation installation
  only — an account with no switched-off membership row. Everybody else,
  a signed-in member of a DIFFERENT organisation included, gets exactly
  the same "Event not found." as a made-up event number, the same
  timing-safe shape #503 already gave four other calendar handlers. The
  save handler also gained a rate limit that did not exist before: 500
  check-ins per 5 minutes per internet connection per event (generous on
  purpose — a whole congregation on one venue's wifi is one connection;
  the planning step proposed 300, the owner chose 500), a new setting
  (`attend.rateLimit.max`/`windowSeconds`, migration 197), 0 switches it
  off. Also fixed in the same pass, found by the sweep this
  fix required (#520 asked for it): `Events::promoteFromWaitlist()`
  (`web/_core/Events.php`) selected a column called `email` from
  `tblUsers`, which has never existed — the real name is `emailAddress` —
  so every single call threw, was silently caught, rolled back and logged
  one line nobody reads, meaning **nobody has ever been moved up a
  waiting list** since the feature shipped. Two more, found by the same
  sweep and fixed alongside it because leaving a known crash next door
  was the wrong call: the Live Chat moderation queue (#523,
  `web/_apps/admin/live/chat.php`) selected `flaggedReason`, which is
  really `flagReason`, so the whole queue 500'd every time it was opened;
  and the Leadership API (#524, `web/_apps/leadership/api/list.php`)
  selected `assignedAt`, a column `tblLeadershipAssignments` has never
  had — fixed to `startDate` (when the person actually started;
  `createdAt` would have read as a start date and been wrong), the
  endpoint had never worked at all. `tools/audit-checks/check_sql_columns.py`
  can now also read a JOINed SELECT's `alias.column` names (never a bare
  name — still too ambiguous), which is precisely what found the
  `u.email` and `assignedAt` faults automatically (#501); measured on
  the real tree with zero false alarms, and it does NOT catch
  `flaggedReason` (that statement's column list holds a quoted value
  before FROM, an existing, documented blind spot). Neither this fix
  nor #519 made the anonymous check-in counts appear anywhere — that
  was raised separately as #525, and is now done too (see the entry
  above).
- fix(accounts,security): **critical** — an administrator of one
  organisation could change any account in any organisation in the whole
  installation, including setting a new password on a global
  administrator's account, changing that account's email address,
  switching off a member of another organisation, or granting the
  portal-wide administrator flag to any account, including their own
  (#518). Every page that changes an account asked only "is the signed-in
  person an administrator of the organisation that's currently open",
  never "may they change THIS account". Reproduced on a real two-organisation
  database before fixing it. One new class, `Portal\Core\AccountGuard`
  (`web/_core/AccountGuard.php`), is now asked first by every page and
  handler that changes or lists accounts for changing: an account number
  that does not exist is "not found"; an account outside the
  administrator's own organisation is ALSO "not found", so a refusal
  gives nothing away; only a global administrator may set the
  portal-wide administrator flag, change a global administrator's
  account, or change an account that belongs to more than one
  organisation. An "admin" invitation now only ever makes someone an
  administrator of the organisation that sent it — including invitations
  already sent and not yet accepted. Every refusal is logged twice, once
  against the organisation involved and once, without the target's name,
  email or attempted password, on a security line only global
  administrators see. Sixteen files were fixed across users, the users
  API, offboarding, invitations and safeguarding records; no database
  change was needed. Two new tools ship with it: a no-database self-test
  (`tools/account-guard-selftest.php`) and a new automatic check
  (`tools/audit-checks/check_account_writes_guarded.py`, wired into the
  pull-request checks as step 21) that fails if a future change writes to
  an account table without asking the guard first. Because this fix
  cannot take back rights already handed out before it shipped, a
  read-only checklist for a global administrator to run straight after
  deploying ships alongside it: `docs/post-deploy-check-518.md`.
- docs(offline): corrected the notes on
  `Auth::oldServiceWorkerWouldStore()` about which stored offline copy a
  sign-out cannot remove, and why (#507). The notes used to say sign-out
  and `sw.js` remove every offline copy stored before the portal started
  sending `Vary: *`; there is one they do not. It only exists for an
  organisation whose site key is literally "offline", on a server that
  answers `/offline/` through PHP, saved while an older version of
  `Auth.php` was still running — in that one case, the person's
  signed-in dashboard is stored under the address `/offline/`. The notes
  now say plainly which copy that is, that it carries the person's name,
  why sign-out keeps it (the three headers it checks are the same as on
  the real offline page), why fixing this with a different header rule
  was rejected (it would eventually delete the real offline page at
  every sign-out, with nothing logged), that it only goes once
  `CACHE_VERSION` next changes, and that the proper fix belongs where
  site keys are saved (tracked separately as #515). Comments only — no
  code changed.
- fix(checks): two low-severity faults in the automatic column check,
  `tools/audit-checks/check_sql_columns.py`, found by a stand-in review
  (a fresh Claude agent, while Codex was out of usage) (#501). First, its
  faster search found each query's first word with Python's
  `finditer()`, which only returns matches that do not overlap — a real
  query starting inside an earlier match could be stepped over and
  missed, even though the check's own notes promised results identical
  to its slower search; it now searches forward from each position in
  turn instead. Second, the pull-request checks
  (`.github/workflows/pr-security.yml`) ran the check in a way that made
  a crash — a Python error, or the check's own refusal when its figures
  do not add up — look exactly like a clean run in the pull-request
  report; a crash is now shown plainly, while genuine findings stay
  advisory, as before. (The check's newer ability to understand short
  table names such as `u.` is recorded in the #519/#520/#523/#524 entry
  above, where it found two of the four faults.)
- fix: a draft or deleted event now takes exactly the same amount of
  database work to refuse as an event that does not exist (#503).
  Codex's review found that four pages — the events API's single-event
  lookup, the event hub's resources and videos lists, and the RSVP page
  — asked the database about the signed-in member's own account only
  when an event was actually found, or only when it was a draft. That
  one extra database round trip made a refused draft answer
  fractionally slower than an event that truly does not exist, which
  could in principle hint that the draft is there. All four pages, plus
  the live page which had the same pattern, now decide "may this caller
  manage events" before looking the event up at all, on every request,
  and the draft rule now sits inside the lookup itself as one extra
  bound condition rather than a separate check afterwards. A refused
  draft, a deleted event, another organisation's event and a made-up
  event number now run the same query, get the same empty result and
  give the same reply.
- fix(routing): four faults fixed, none of them the fault its issue title
  said — all four were found by checking the code itself, not by trusting
  the report. Two of them share one cause: `web/public_html/.htaccess`
  has a rule saying that if a web address matches a REAL FOLDER on disk,
  the web server answers for that folder itself and never hands the
  request to the portal at all. That rule is a good one — it is what lets
  stylesheets and pictures load directly — but two folders in the web
  root were quietly swallowing addresses the portal needed.
  **The Admin area could not be opened (#483, now closed).** A real
  folder, `web/public_html/admin/`, held three pages belonging to the
  error-monitoring screen. So every visit to `/admin` — the first thing
  an administrator ever clicks — was answered by that folder instead of
  by the portal, showing a bare file listing or a flat refusal. Nothing
  showed up in the error log, because no portal code ever ran to log
  anything. Fixed by moving those three files to
  `web/_apps/admin/integrations/monitoring/`; the address itself did not
  change, because the router already looks under `web/_apps/` before it
  looks in the web root. One move fixed three addresses at once:
  `/admin`, `/admin/integrations` and `/admin/integrations/monitoring`.
  **A dead public address that would have published internal events
  (#478, partly).** The address `widget` was open to anyone, no sign-in
  needed, and pointed at `calendar/widget.php`. It never actually worked,
  because a real folder, `web/public_html/widget/`, held `countdown.js` —
  a script other people's websites already embed, so that folder cannot
  be moved without breaking their pages. The address was removed rather
  than repaired, because the page behind it was not safe to switch on:
  it had no sign-in check of its own, and its two database queries asked
  for every event that was published and not deleted — which includes
  INTERNAL events such as a leadership meeting. Every other public
  calendar page already required an event to be marked public; this one
  did not, and because nobody could reach it, nobody had noticed. Both
  queries now also require `isPublic = 1`, and the file explains what it
  is and what would need doing before anyone points a safe address at it
  again. Migration 189 removes the address, matched on both its name and
  where it pointed, so a genuinely safe future `widget` address cannot be
  deleted by accident on replay. Every other seeded address was checked
  against the web root; the only two remaining collisions (`assets`,
  `api-docs`) are deliberate.
  **Administrators were locked out during every upgrade (#477, partly).**
  The portal's maintenance-mode holding page keeps a short list of
  addresses that still work while everything else is closed. That list
  is compared against the ADDRESS a visitor types, but it contained
  `auth/login` and `auth/logout` — fragments of FILE PATHS, not
  addresses. The sign-in page's real address is `login`; the file that
  answers it is `auth/login/index.php`. Neither entry ever matched
  anything, so the sign-in page was shut along with everything else — and
  maintenance mode switches itself on automatically whenever the code is
  newer than the database, which is every single upgrade. The holding
  page's own "sign in" link pointed at the same address that did not
  exist. Fixed to the real addresses: `login`, `logout`,
  `forgot-password`, `reset-password`. The first attempt at this fix was
  only half a fix: it reopened the sign-in page but not the two-factor
  step after it, so an administrator with two-factor switched on could
  type the right password and then hit exactly the same wall. Found by
  checking every address to do with signing in, not just the one named in
  the report; `auth/2fa/verify` is now allowed too, while the account
  housekeeping pages `auth/2fa/setup` and `auth/2fa/disable` deliberately
  stay blocked.
  **Five Export CSV buttons crashed before producing anything (#482,
  partly).** When the portal loads a page, it hands that page exactly two
  things: the database connection (`$mysqli`) and the settings
  (`$SETTINGS`) — see the `global` line in `web/_core/Router.php` just
  before a page is loaded. Anything else a page reaches for simply is not
  there. Five export files reached for `$db` instead, which nobody ever
  sets, so each one stopped dead the instant its button was pressed, with
  no file produced and nothing shown on screen. Fixed to use `$mysqli`,
  the connection every other working page in the same folders already
  uses, on the members list, the audit trail, the attendance register,
  the leadership roster and the expenses queue. Every other file under
  `web/_apps/` reaching for a database variable it never defined was also
  checked; one more turned up
  (`web/_apps/announcements/_workflow-gate.php`) and is fine — it takes
  the connection as a parameter instead.
  `php -l` is clean on every touched file and all 11 audit checks pass.
  The end-to-end migration harness has not run — it needs Docker, and
  Docker is unavailable because the development machine is out of disk
  space — and Codex has not yet reviewed these fixes, because its usage
  limit was reached. Neither of those checks should be treated as done
  until they actually run.
- feat(admin): #489 — a straight answer, in one place, to "what is this
  portal running on, and is it a version we still support?" New
  `Portal\Core\DbServer` asks the database what product and version it is
  and judges it: **ok** (still receiving security fixes), **warn** (works
  fine, but worth knowing about — most often that the whole MySQL 8.0 line
  stopped getting security fixes in April 2026), or **crit** (too old for
  this portal's own database changes to run on). It knows nothing about
  the rest of the portal — no `App`, no `Site`, no `PORTAL_*` constant —
  because the installation wizard runs before any of that exists and needs
  to ask the same question the same way, exactly like `version.php` and
  `brand-defaults.php` already do. It also strips MariaDB's own fake
  "5.5.5-" version prefix (there so very old MySQL client programs will
  still agree to connect); without that, every current MariaDB server
  would be misread as fifteen-year-old MySQL and wrongly refused. A new
  dependency-free check, `tools/db-server-selftest.php`, runs 15 real
  version strings past the class with no database needed
  (`php tools/db-server-selftest.php`).
  The installation wizard now asks this question the moment it first
  connects (step 2 — the earliest point it can, since nobody has typed in
  any database details before that) and stops **only** when the verdict is
  'crit'; anything else is shown and never blocks, because on shared
  hosting the customer usually cannot change the database version and
  blocking would just lock them out of their own portal. The admin
  dashboard and the health page now read the same verdict instead of each
  showing something different — one of them was printing the word "MySQL"
  in front of whatever the database actually reported, which is wrong on a
  MariaDB server. The health page's traffic light deliberately stays green
  on an ageing-but-working database: that page is polled by uptime
  monitors, and a permanently amber light is one everybody learns to
  ignore, so a real problem arriving later would land in a warning nobody
  reads. New page, Admin → Server Information (`/admin/system-info`, any
  administrator): PHP version, the database verdict, connection facts
  asked of the live connection rather than read from the credentials file
  (so the password is never loaded into the page at all, not merely
  hidden), hosting limits, and which optional parts of PHP are installed.
  A linked full PHP report (`/admin/system-info/phpinfo`, umbrella
  administrators only, since it describes the whole server rather than one
  organisation on it) leaves out the server's environment variables and
  the current request — and also strips two tables that PHP's own Apache
  integration prints regardless of what was asked for ("Apache
  Environment", "HTTP Headers Information"), because the second of those
  carries the reader's own session cookie; simply not asking for the
  request section on its own is not enough to stop it. Settings whose name
  looks like a secret have their value hidden as a backstop — PHP masks
  nothing on its own, checked against a live server — and a last check
  before anything is shown refuses the whole page if the reader's own
  session token turns up anywhere in it. Migration 188 seeds the two new
  addresses only — no tables, no settings. `php -l` clean on every touched
  file, all 11 audit checks pass, the new self-test passes 15 of 15, and
  the end-to-end migration harness passes every phase against MySQL
  8.0.36.
- fix(settings): saving a portal-wide setting now changes it instead of
  adding another copy. The table's uniqueness rule covered (setting name,
  site), and a portal-wide setting stores no site — and MySQL never treats
  one empty value as equal to another. So "insert, or update if it already
  exists" never updated; it always inserted — so the settings-group screen
  and the app on/off switch added a row each time instead of changing one.
  (The separate dot-notation settings editor was unaffected: it looks the row
  up and updates it.) Counted from the seed files, a fresh install would begin
  with about 479 surplus rows. Two consequences had
  been live for months: an installation could still be enforcing an
  8-character minimum password while the code's own fallback, `/help/admin`
  and migration 041 all said 12; and
  a fresh install reported itself as version 0.1.0, because a stale setting
  overrode `version.php`. Fixed by a derived column (`siteScope` — the site
  number, or -1 for portal-wide) with the uniqueness rule moved onto it. It
  is VIRTUAL, not STORED, because MySQL refuses a cascading foreign key on
  the base column of a STORED derived column. Choosing which duplicate to
  keep could not be "the newest": several screens find their row with an
  unordered `SELECT … LIMIT 1` and update that one, in practice the oldest,
  so keeping the newest would have deleted saved payment credentials. The
  rule prefers the row that looks edited (its value differs from its own
  recorded default), then the most recently written, then the newest. That is
  a judgement, not proof: where several copies hold different chosen values,
  only one survives. What the migration deliberately does NOT do is impose a
  new password minimum or a new expenses setting on an existing site.
  Migration 187, issue #466.
- fix(routing): three finished features had no way in. The notification
  preferences page could not be opened — migration 093 gave its address to
  a newsletter-only page — which meant browser push opt-in was reachable
  only from the livestream page, so members could not subscribe to service
  reminders at all. The livestream channels and schedule page lost its
  address to migration 133, taking with it the only button that tells
  subscribers "we are live now". Six help guides existed with working
  addresses and no link from anywhere. Migration 186, issue #467.
- fix(ui): six menu and dashboard links led to "page not found" on a fresh
  install (`/expenses`, `/kids`, `/reports`, `/salvation`, `/worship`,
  `/webhooks`). New `Router::routeExists()` means those generated links are
  left out when a successful lookup finds no registered address, and a new optional `landing` field
  on an app's registry entry says where to link when the app's own prefix is
  not a page. The check covers those menu and dashboard links only; it looks
  at registered addresses rather than at files on disk, and answers "yes" if
  it cannot reach the database, so a menu is never emptied by a database
  hiccup. Separately, an app switched on at `/admin/apps` never appeared in
  the menu, because that screen writes `1` and the menu insisted on `true`.
  Issue #468.
- fix(admin): "Run all pending migrations" would have loaded demo data into
  a live site. The list accepted any `.sql` file, so `demo_data.sql` and
  `full_schema.sql` always showed as pending on a perfectly healthy portal.
  Issue #469.
- fix(core): every migration from 180 onwards reported failure after
  succeeding. Each records itself, then `Migrator::runOne()` tried to record
  it again against a unique key and threw. Also, a migration that stops
  deliberately now returns its explanation instead of a blank error page.
- feat(api-docs): Swagger UI is now committed inside the portal, so
  `/api-docs` works with no internet access — several customer networks
  block public content delivery networks, and the page was a blank white
  screen on those. Each committed file was verified to hash to the
  integrity value already recorded. New setting
  `api.docs.local_assets_only` skips the network entirely. "Try it out"
  could never save anything, because the page bypasses the template that
  publishes the anti-forgery token; it now publishes its own, and the
  replacement token is returned as `meta.csrfToken` so a second save works.
  The page no longer sends the address of the install to an outside
  service. Migration 185, issue #470.
- ci: all eleven checks in `tools/audit-checks/` now run on every pull
  request — four of them were called by no workflow at all. The end-to-end
  database test no longer breaks on a folder name containing a space, which
  had made it unusable on a developer's own machine. Issue #471.
- docs: counts corrected across `.claude/CLAUDE.md`, `README.md`,
  `FEATURES.md` and the schema header, with the commands to re-derive them
  so they can be checked rather than trusted. New `DEV_NOTES.md` sections on
  installing PHP locally (macOS, Windows, Linux, Raspberry Pi), the
  settings-table trap, and refreshing the self-hosted Swagger UI files.
  `README.md` gains an honest "Supported versions" section recording that
  **MySQL 8.0 reached the end of its extended support in April 2026**, that
  no minimum database version is enforced (the version is read and shown in
  three places, but nothing acts on it), and that MariaDB is not covered by
  any automated test here, so its compatibility is unverified. Issue #475
  tracks moving to supported versions.
- docs: automatic translation of **user-written content** is recorded as
  unreachable in `FEATURES.md` and `.claude/CLAUDE.md`. The engine, the
  admin configuration page (provider, API keys, monthly spend cap) and the
  member opt-in all exist, but nothing a user can reach ever calls it: the
  only caller of `Translation::translate()` sits at an address the router
  cannot resolve. This is separate from translation of the portal's own
  screens (`I18n` / the `t()` helper), which works normally. Issue #485.

- ci: stop the advisory CodeQL ("Analyse JavaScript") and Psalm ("PHP
  Static Analysis") jobs showing a false red X on every PR. Both jobs
  complete their analysis fine but then fail at the SARIF-upload step
  because GitHub Code Scanning (Advanced Security) is not enabled on the
  repo ("Code Security must be enabled …" / "Resource not accessible by
  integration"). Added a step-level `continue-on-error: true` to
  `codeql.yml`'s analyse step and to `php-static-analysis.yml`'s upload
  step (the Psalm job's existing job-level flag kept the workflow green
  but left the job's own check run red). These are advisory checks — the
  hard merge gates are the PHP -l lint job and the migration harness — so
  a disabled upload target no longer reads as a failure. Each carries an
  inline comment to remove the flag if/when Code Scanning is enabled, to
  restore genuine-failure visibility. No application code touched.
- feat(reports): #156 — Reports Builder: a whitelist-driven custom report
  builder alongside the existing #93 fixed dashboards, built entirely
  around an injection-safe registry architecture. `Portal\Core\
  ReportRegistry` is a PHP-code-only whitelist — six v1 sources (users,
  events, attendance, expenses, giving, tasks), each with a registry-owned
  table/alias/tenant-scope expression/curated JOINs and a closed
  per-column whitelist (expr/type/role-gates/allowed-aggregations); closed
  keyed sets for operators (`= != < > <= >= IN BETWEEN LIKE NOT LIKE` +
  `IS [NOT] NULL`), aggregations, and date-bucket transforms. Care, kids,
  safeguarding, prayer-requests, auth/credentials, settings, and API-key
  tables are structurally absent — `ReportRegistry::assertSelfConsistent()`
  hard-fails if a future edit ever references one of those domains,
  belt-and-braces against drift. `Portal\Core\ReportBuilder::compile()` is
  the ONE place report SQL is ever assembled: every identifier reaches the
  SQL string only via strict `array_key_exists()`/`in_array(...,true)`
  lookup against the registry — never concatenated from request input —
  and every value is bound via `bind_param()` with a lockstep-built types
  string plus an explicit `strlen()===count()` assert. `siteID = ?` from
  `Site::id()` is force-injected FIRST, outside the user-filter
  parentheses, so no `OR` in a filter row can ever bypass tenancy. Column
  gates (a `tblRoles.roleKey`, `@siteAdmin`, or `@rootAdmin`) apply
  identically in SELECT and WHERE — filtering on a column your role can't
  see is refused exactly like selecting it, closing the filter-as-oracle
  leak; financial totals need Treasurer/Site Admin, Giving donor identity
  needs Treasurer strictly (A8), member email needs Site Admin (A6). A
  saved definition (`tblReportDefinitions.definition`, JSON) is
  re-validated against the registry on EVERY run, so a hand-edited DB row
  fails closed rather than compiling stale/malicious SQL. Builder UI:
  column checkboxes + a drag-reorderable chip row (`Asset::sortableJs()`,
  the #156 "drag-and-drop" ask), repeatable filter rows joined by a single
  AND/OR toggle (A2 — nested groups deferred), optional group-by +
  aggregates, an AJAX preview endpoint (`preview.php`, session-authed,
  outside `api/*` — the `geo/` AJAX-proxy precedent), paginated run +
  CSV export (`CsvExporter`), and a bar/line chart on grouped results via
  a new SRI-pinned `Asset::chartJs()` (Chart.js 4.4.4, jsdelivr — hash
  independently re-derived from the npm registry tarball's
  `dist/chart.umd.js`, the location-chunkA Leaflet precedent, since
  jsdelivr itself was unreachable from the build sandbox). `isShared`
  reports are visible to this site's admins (A5 — never truly public,
  the whole area stays admin-gated); manage rights (edit/delete) are
  author-or-site-admin. Migration 184: `tblReportDefinitions` (DEVIATION
  from #156's literal `tblReports` wording — A4, documented in the
  migration header), 3 settings seeds (`reports.enabled` seeded ON so the
  pre-existing #93 dashboards survive the upgrade unchanged — A7 — plus
  the two per-site row-cap guardrails), 8 route seeds (7 builder routes,
  all `isProtected=1`, no `api/*` rows; 1 `/help/reports` guide route).
  New AppRegistry entry (`reports`, slug folds BOTH the #93 dashboards and
  the new builder under one toggle). GDPR lockstep in the same PR:
  `GdprEraser::catalogue()` anonymises `tblReportDefinitions.createdByID`/
  `updatedByID` (authorship attribution detached, definition retained —
  it's query metadata, not personal data about anyone but its author);
  `data-export.php` gains a `reportDefinitions` block. A committed,
  dependency-free red-team self-test
  (`tools/report-builder-selftest.php`) exercises the real
  `ReportRegistry`/`ReportBuilder` classes (no DB, no network) — asserts
  registry self-consistency, a benign definition compiles with the tenant
  scope providably first, and 15 hostile/malformed definitions (SQL-
  injection-shaped source/column/operator/conjunction/sort-direction
  strings, oversized `IN` lists, arity mismatches, gated-column probes
  with no session, a stale format version) each throw
  `InvalidArgumentException` before any SQL string exists — all pass. All
  11 audit checks green, `php -l` clean on every touched file, `node
  --check` clean on the new JS.
- feat(forms): #153 — Forms Builder app. New `web/_apps/forms/` (16 pages/
  handlers) + `Portal\Core\FormEngine` — the single injection-safety
  boundary for a 12-type field registry (text/textarea/email/phone/number/
  date/time/select/radio/checkboxes/checkbox/heading), a whitelist config
  sanitiser (`sanitiseConfig()` — `configJson` is DATA, never SQL, never
  trusted raw even on read), an escaped-everything renderer (`f_{fieldID}`
  server-integer field names; choice options submit as bounds-checked
  integer indexes, never raw option text), per-type server-side validators,
  and immutable `answersJson` snapshot persistence (`{fieldKey: {label,
  type, value}}` — survives later field edits/deletes without corrupting
  history). Admins build a form (title/description/audience/window) and its
  fields at `/forms/edit`, publish/close/rotate its link from
  `/forms/manage`; members fill published internal/both forms at
  `/forms/fill`; a public link `/f/{token}` (Router special route, cloned
  from service-plans' `/os/{token}`) is default OFF
  (`forms.allowPublic='false'`) and six-gate uniform-404s exactly like its
  precedent, scoped to the FORM's own siteID throughout (never ambient
  `Site::id()`). Public POST layers honeypot → CSRF → `Captcha::verify()` →
  `RateLimiter` (fake-success on `isBlocked()`) → a 5/15min per-IP bucket.
  Responses reviewed/exported at `/forms/responses` (admin-only,
  new/reviewed tabs, CSV export via `FormEngine::csvRows()` +
  `CsvExporter`). GDPR lockstep in this PR: `GdprEraser::catalogue()` hard-
  deletes a user's responses by `submitterID`; `auth/account/data-export.php`
  gained a matching `formResponses` block — public (anonymous) responses
  carry no `submitterID` and so sit outside both by design (documented in
  `/help/forms` + DEV_NOTES). Migration 182: 3 new tables (`tblForms`,
  `tblFormFields`, `tblFormResponses`), 3 settings seeds (`forms.enabled`,
  `forms.allowPublic`, `forms.responseRetentionDays` stub), 15 route seeds
  (13 protected + 2 public — no `api/*` rows, ApiRouter trap). All 11 audit
  checks green, `php -l` clean on every touched file.
- feat(small-groups): #150 — new Small Groups app (groups/classes register:
  Sabbath School classes, home groups, Bible studies). Roster with
  leader/co-leader/member roles, optional self-service join requests
  (pending → approve/decline, with a last-active-leader guard on
  remove/demote/leave), per-meeting roll (`tblSmallGroupMeetingAttendance`,
  presence-row model mirroring `tblEventAttendance`) with an ADDITIVE
  headcount push into the existing Attendance app via a group's linked
  `tblAttendanceServiceTypes` row — several groups can share one service
  type/session, each contributing its own labelled headcount line; zero
  changes to Attendance's own schema or code. Meeting location reuses the
  #456 shared partials byte-identical to migration 180's `tblVenues` block,
  plus a new `locationVisibility` gate (leaders/members/site, default
  members, no public tier — groups often meet in a member's home). New
  `Portal\Core\SmallGroups` class is the tenant-safety choke-point and the
  stable contract #304 (group messaging) and #321 (watch-party rooms) are
  expected to consume (`groupID` scope anchor, `status='active'` membership
  predicate, `isLeader()` leader gate) — the denormalised `siteID` on
  member/meeting rows is written only after confirming an active
  `tblUserSites` row for the group's site, making cross-site membership
  structurally impossible. New `groups_coordinator` role (manages every
  group at a site without needing to be admin). GDPR lockstep in the same
  PR: six `GdprEraser::catalogue()` entries, four `data-export.php` blocks,
  and an `offboarding/do.php` step that ends a leaver's memberships. **v1
  is adults-only** — membership rows are portal users only; no named-child
  rows anywhere (the Kids app's `tblKidProfiles` remains the sole place
  child identity lives; verified zero references). Migration 183: 4 new
  tables (`tblSmallGroups`/`tblSmallGroupMembers`/`tblSmallGroupMeetings`/
  `tblSmallGroupMeetingAttendance`), 7 settings seeds (`small-groups.enabled`
  defaults `'0'`, opt-in), 1 role seed, 13 route seeds, zero ALTERs to any
  existing table. New help page (`/help/small-groups`) + help-index card.
  All 11 audit checks green, `php -l` clean on every touched file.
- feat(pwa): #141 residual + #306 — the two remaining PWA install-prompt
  pieces (Web Push itself already shipped as #322) plus starter sub-brand
  artwork. **#141**: a self-hosted `assets/js/pwa-install.js` captures
  `beforeinstallprompt`, calls `event.preventDefault()` to suppress the
  browser's own mini-infobar, and reveals a dismissible bottom-sheet banner
  (`#portal-install-prompt`, footer.php — same visual pattern as the
  existing cookie-consent banner) with a brand-aware "Install {product
  name}" heading; a dismissal is remembered in localStorage for 30 days,
  an actual install is remembered permanently via `appinstalled`, and
  both reads/writes are try/catched (private browsing never throws).
  `manifest.php` gains a brand-aware `shortcuts[]` array (Dashboard /
  Calendar / Giving / Prayer Requests) filtered through
  `AppRegistry::isEnabled()` — a disabled app's shortcut is silently
  dropped, and any `AppRegistry` exception fails CLOSED (shortcut
  omitted) so a manifest fetch can never break. `header.php` gains the
  missing iOS/PWA meta trio: brand-aware `apple-mobile-web-app-title`
  (was absent — iOS would have captioned every install "Portal"
  regardless of brand) plus the standard-track `mobile-web-app-capable`
  twin of the existing `apple-mobile-web-app-capable` tag (icon,
  status-bar-style, and manifest link were already present from earlier
  PWA work). Push notifications, VAPID, and the offline shell were
  already fully shipped by #322 — not touched here.
  **#306**: functional starter SVG brand kits (`icon.svg` /
  `icon-192.svg` / `icon-512.svg` / `logo.svg`) for the four presets that
  previously fell back to the generic WebMS-Intra assets —
  `assets/images/brandkit/assets/{schoolms,charityms,communityms,businessms}/`
  — each a distinct geometric emblem (mortarboard, heart, interlocking
  rings, ascending bar chart) on the same indigo tile + gradient tokens
  as the WebMS/ChurchMS kits, so all six presets read as one family.
  `logo.svg`'s wordmark line is set in a local system-font stack (no
  outlined vector glyphs, no external font/network reference) — a
  designer pass to match the WebMS/ChurchMS outlined-vector treatment is
  recommended before any of these four presets ships to a real customer
  (documented in each `logo.svg`'s own `<desc>` and in `brand-defaults.php`
  comments). `brand-defaults.php`'s four stub presets now point
  `assetFolder` at their new kits instead of falling back to
  `webms-intra`; the installer's Step 1.5 copy and code comment updated
  to match (icon/PWA-manifest SVGs ship; the installer's PNG/.ico favicon
  variants for these four presets do not — those `<link>` tags 404
  harmlessly in browsers without SVG-favicon support). No migration in
  either half. All 10 audit checks green, `php -l` clean on every touched
  PHP file, all 16 new SVGs well-formed XML.
- feat(push): #322 — Web Push notifications ("we're live now" + service
  reminders). Migration 111 shipped `tblPushSubscriptions` + the VAPID
  settings keys, but the subscribe/unsubscribe handlers sat at
  `_apps/api/push/*` — a path ApiRouter never resolves — and no sender
  existed anywhere. Ships `Portal\Core\WebPush`: VAPID (RFC 8292) ES256
  JWT signing with the mandatory DER→JOSE signature conversion (shipping
  the raw DER bytes is the classic silent-401 bug), RFC 8291 aes128gcm
  payload encryption (fresh ephemeral P-256 keypair per message,
  `openssl_pkey_derive()` ECDH, triple `hash_hkdf()`), and the RFC 8030
  delivery POST. A committed, dependency-free crypto self-test
  (`tools/webpush-selftest.php`) exercises the real private methods via
  reflection and passes (sign→verify + encrypt→decrypt round-trip).
  Relocates the dead `push/subscribe`/`push/unsubscribe` handlers to the
  ApiRouter convention path + seeds the two `api.push.*.enabled` flags;
  adds rate limiting and SSRF-guarded endpoint validation (https-only, no
  IP-literal/local host, admin-editable host-suffix allowlist) enforced at
  BOTH subscribe and send time. VAPID private key is sodium-encrypted at
  rest, never redisplayed, never sent to the client — only the public key
  reaches the browser. New client subscribe UI
  (`assets/js/push-subscribe.js`) + `sw.js` `push`/`notificationclick`
  handlers (neither existed before), surfaced on `/account/notifications`
  and `/live`. Two channels wired: "we're live now" (manual admin button
  on `/admin/livestream` + Host Console, plus a default-OFF auto-detect
  cron and a default-OFF anonymous "starting soon" broadcast, both in the
  new `cron/push-golive.php`) and service reminders (a Web Push companion
  riding `cron/event-reminders.php`'s existing 1h-window email dedupe).
  New `/admin/integrations/push` config page (generate-or-paste keys,
  TTLs, toggles, per-channel subscription counts, test-send).
  `tblPushSubscriptions` added to `GdprEraser` + offboarding revocation.
  INERT until an admin sets VAPID keys. Migration 177 (renumbered from 175
  — #423's UPC-E took 175, #234's shared-mailbox took 176): 3 additive
  `tblPushSubscriptions` columns, settings seeds, 4 route seeds, no new
  tables. All 11 audit checks green, `php -l` clean on every touched file.
- feat(service-plans): gap #128 residual — hymnal lookup + public Order of
  Service (migration 178). #128 ("Order of Service planner with iHymns
  integration") was re-scoped: service-plans (#262/#300) + the Worship
  Presentation Engine (#308/#355) already covered item CRUD, reorder,
  presenters, durations, notes, templates, and the song/CCLI library — this
  ships ONLY the genuine residual, additively, on the existing tables (no
  third service-plan model, no new app). **R1 — local hymnal index:** new
  `tblHymnals` + `tblHymnalEntries` (metadata only — number/title/tune/
  author/CCLI/copyright, NEVER lyrics), searched via new
  `Portal\Core\Hymnal::searchLocal()`; admin CRUD + CSV import at
  `/admin/hymns` (multi-hymnal, upsert on hymn number, idempotent
  re-import). **R2 — remote ("iHymns") lookup, Tier 2, default OFF:** a
  generic HTTPS JSON client (`Hymnal::searchRemote()`) behind
  `hymns.remote.enabled = 'false'` — https-only, single-host allowlist,
  private/reserved-IP refusal (both at save and at request time),
  `CURLOPT_FOLLOWLOCATION=false`, HTTPS-only protocols, 3s/5s timeouts, a
  ~512 KB response cap, JSON-only parsing, and a 24h server-side cache
  (`tblHymnLookupCache`) — ANY failure degrades silently to local-only
  results; the API key is encrypted at rest and never logged. New
  session-authenticated `service-plans/api/hymn-search.php` (ApiRouter
  convention path, gated by `api.service-plans.hymn-search.enabled`, NOT a
  tblRoutes row per the ApiRouter trap) merges local hymnal + song-library +
  remote results for the run-sheet editor's typeahead picker. **R3 —
  congregation-facing public Order of Service:** new `tblServicePlan.
  publicToken`/`isPublicShared` (mirrors `tblAssets.publicToken`) + a
  Router special route `/os/{token}` (cloned from `/a/{token}`) →
  `service-plans/public.php` — congregation fields only (order, titles,
  presenter names), internal AV/tech `notes` NEVER queried let alone
  rendered, `noindex`, uniform 404 for unknown/unshared/unpublished/
  disabled tokens (no oracle), OFF by default at both site
  (`service_plans.public_share.enabled = 'false'`) and plan level; new
  CSRF'd `service-plans/share.php` (enable/disable/rotate) + a QR code via
  the existing `/qr.php` utility. `print.php` gains `?version=leader|
  congregation` (default `leader`, byte-identical to before) — the
  congregation variant suppresses `notes` and the internal sectionType tag.
  **R4 (glue):** nullable `tblServicePlanItem.songID` FK → `tblSongs`
  (`ON DELETE SET NULL`) — picking a hymn/song auto-promotes it into
  `tblSongs` (check-first upsert, metadata only) and links it, so run-sheet
  items and the Worship CCLI log share one canonical song entity; free-text
  `title` remains the universal fallback (every existing item has `songID`
  NULL and renders unchanged). `tblSongs` gains `hymnalCode`/`hymnNumber`/
  `tuneName`, surfaced in `worship/song.php`/`songs.php`. All 11 audit
  checks green, `php -l` clean on every touched file.
- feat(calendar/venues): gap #436 — per-event venueID/roomID links +
  room-aware venue coverage, an additive follow-up to the shipped Venue
  Bookings app (#429, migration 170). `tblEvents` gains two optional
  nullable columns, `venueID`/`roomID` (migration 179, FKs
  `ON DELETE SET NULL`, guarded MySQL-8-safe DDL, folded into
  `full_schema.sql`) — NULL on every pre-existing event, zero backfill from
  `tblVenueBookings.eventID` (a hire hosting an event isn't necessarily
  "the event's venue"). `Portal\Core\Venues::classifyEventCoverage()`
  gains an optional trailing `?int $roomId = null`: when set, per-day
  booking rows are filtered to `roomID IS NULL OR roomID = $roomId`
  (a whole-venue booking still covers every room) before the existing
  wall-clock day-cascade runs unmodified, and a new
  `COVERAGE_ROOM_NOT_COVERED` verdict (severity danger, new
  `venues.coverage.room_not_covered` i18n key with a `:room` placeholder)
  fires when the room itself has no cover but the venue has a confirmed
  bookable hire for a *different* room that day. Omitted/null `$roomId`
  (both pre-#436 call sites) reproduces today's output bit-for-bit; an
  unresolvable room (wrong venue/site, deleted, foreign) silently degrades
  to venue-level coverage — no existence oracle, same philosophy as the
  venue-missing sentinel. New public `Venues::getRoom()` accessor (mirrors
  `getVenue()`) for save.php's site+venue-scoped room validation. The
  calendar manage form's venue picker (`calendar/manage/_event_form.php`)
  is upgraded from a transient advisory-only Surface A check into a
  **persisted** link: a cascading room `<select>` (disabled, not hidden,
  when the chosen venue has no rooms) sits beside the venue picker,
  server-rendered for the initially selected venue and client-side
  repopulated on venue change; `calendar/manage/save.php` validates and
  writes both links on create/update (site-scoped `Venues::getVenue()`/
  `getRoom()`, invalid posts silently NULL — never a save-blocking error)
  and, when the Venues app is disabled/absent/throws, OMITS the columns
  from the UPDATE entirely so an app toggle can never wipe an existing
  link. An event can only ever link a venue/room belonging to its own
  site — never cross-tenant. Also fixed in this PR: the venue-check live
  "is it booked?" JS (`_event_form.php`) posted `startDateTime`/
  `endDateTime`/`timezone`, but `venues/api/check.php` has always read
  `start`/`end`/`tz` — every live check silently 400'd and the JS's
  `.catch()` hid the alert, so Surface A never actually worked. Canonicalised
  on `check.php`'s existing `start`/`end`/`tz` contract (it already matched
  its own documented header) and fixed the JS to match, adding `roomID`
  alongside. Deferred (see the build plan's Open Questions): per-event
  coverage badges on calendar grid views; venueID/roomID on the events
  REST write API (read side is automatic via `SELECT e.*`); room-flavouring
  the pre-existing coverage messages.
- feat(location): #456 Chunk A — full address + geocoordinates + What3Words
  platform layer (foundation, non-PII, interactive map). New
  `Portal\Core\GeoLocation` (address normalise/format, DECIMAL(10,7) coord
  validation, W3W canonicalisation, map link-outs, and the cross-repo
  `toLocationObject()`/`fromLocationObject()` serializer shared byte-for-
  byte with ProjectBookIT/ProjectEPass), `Portal\Core\What3Words` (v3 API
  client — key passed as a query param, never logged; default OFF;
  `w3w.enabled` gates only the API, the `///word.word.word` input is
  always present as a stored-field fallback), and `Portal\Core\Geocoder`
  (Google primary → Nominatim/OSM fallback, policy-compliant User-Agent +
  ≤1 req/s throttle + `tblGeocodeCache` result cache, `geo.autoGeocode`
  default OFF). Every new class is a zero-runtime-dependency standalone
  implementation — no call ever crosses to another repo. Three new shared
  partials (`web/_core/partials/` — first in the codebase):
  `location-display.php`, `location-input.php`, `location-map-assets.php`
  (pinned Leaflet 1.9.4 from cdn.jsdelivr.net with SRI, hashes
  independently re-derived from the npm registry tarball and matched
  exactly — see DEV_NOTES). New admin integration pages
  (`/admin/integrations/what3words`, `/admin/integrations/geocoding`) and
  a site-HQ address page (`/admin/settings/organisation`); two session-
  authed AJAX proxies (`/geo/w3w-suggest`, `/geo/lookup`) outside `api/*`
  so the browser never sees either API key. Wired into Venues (structured
  address + coords/W3W + interactive map), Events (existing
  `locationGeoLat/locationGeoLng/locationW3W` columns now validated on
  save, JSON-LD `geo`, interactive map, canonical `location` object
  additively emitted by the events REST API create/update/list/detail),
  Event occurrence overrides (hand-entered `overrideGeoLat/overrideGeoLng/
  overrideW3W`, NULL = inherit), Resources, and Asset Locations. Migration
  180: five new columns (`latitude/longitude/what3words/geocodedAt/
  geocodeSource`) on `tblVenues`/`tblResource`/`tblAssetLocations`, three
  override columns on `tblEventOccurrenceOverrides`, new `tblGeocodeCache`,
  14 settings seeds (all default OFF/empty), 10 route seeds — a fresh
  upgrade is a full no-op until an admin opts in. No PII table touched in
  this chunk (tblUsers/directory/GiftAid/Salvation land in a later Chunk
  B PR with the matching GDPR export/erasure wiring in the same commit).
  All 11 audit checks green, `php -l` clean on every touched file.
- feat(location): #456 Chunk B — the PII + GDPR half of the location/
  geocoding platform, branched off Chunk A. Migration 181 adds FOUR
  columns to `tblUsers` ONLY: `latitude`/`longitude`/`what3words` (member
  home coordinates, PRIVATE by default, NEVER auto-geocoded — no
  `geocodedAt`/`geocodeSource` pair, set only by the member's own explicit
  "Look up coordinates" action or hand entry) and `visibilityCoords`
  (ENUM, default `'private'`, INDEPENDENT of the existing
  `visibilityAddress` — sharing address text never implies consent to
  show a map pin). Directory capture on the owner surface
  (`directory/me.php`, NOT `/account`): compact coords/W3W trio under the
  existing Address textarea + a `visibilityCoords` visibility row;
  `directory/save.php` validates the pair via `GeoLocation::
  validateCoords()`/`validateW3W()` (10 → 14 bind_param placeholders).
  Directory display (`directory/profile.php`) gates coords through a
  SEPARATE `$can($u['visibilityCoords'])` check, stricter than the
  address text: the owner/admin always sees full precision + the exact
  what3words; any other viewer permitted by the tier sees coordinates
  coarsened to 3dp (~110m, `GeoLocation::coarsenCoords()`) with an
  "Approximate location" badge, and the what3words value is suppressed
  entirely (a 3m-precise W3W square cannot be meaningfully coarsened).
  The pre-existing `$can()` "team tier behaves as private" quirk is
  inherited verbatim, not fixed. GDPR lockstep shipped in the SAME PR:
  `data-export.php` gained a `giftAidDeclarations` export block (closed a
  pre-existing address-PII export gap — Gift Aid declarations were never
  exported at all); `delete-confirm.php`'s tblUsers anonymise UPDATE now
  also nulls `displayAddress`/`displayPhone` (a separate pre-existing
  miss) plus the four new columns; `GdprEraser::catalogue()`'s tblUsers
  `nullCols` extended with `latitude`/`longitude`/`what3words`. GiftAid
  (`giving/gift-aid.php`) and Salvation (`salvation/card.php`) reuse the
  Chunk A `location-input` partial in TEXT-ONLY "reduced names map" mode
  (`showCoords`/`showW3W` both false) mapped onto their EXISTING
  `address`/`postcode` POST fields — no new columns on
  `tblGiftAidDeclaration`/`tblSalvationCards`, so no new erasure surface.
  Kids/Care/Visitors are hard-excluded — zero diff touches those apps
  (verified by grep). API surface (`UserCreate`/`UserUpdate` schemas)
  documents that member coordinates/W3W are never readable or writable
  via the REST API in any mode, regardless of visibility tier — a bearer/
  session caller has no per-viewer tier context to apply the gate
  against. All 11 audit checks green, `php -l` clean on every touched
  file.
- feat(mail): gap #234 — MS365 Graph email via an admin-configured shared
  mailbox, plus hardening of the whole `Mailer::sendViaGraph()` path. The
  portal already sent every email app-only through Microsoft Graph
  (`/users/{mail.defaultFromAddress}/sendMail`); this formalises and
  hardens that path rather than adding a new auth model. New
  `mail.ms365.sharedMailbox` setting (empty = off, today's behaviour
  unchanged) routes `sendMail` through a dedicated shared mailbox instead
  — same app-only client-credentials token
  (`Mailer::accessToken()`, unchanged), explicit `message.from` identity
  (address + display name), and `saveToSentItems` so a copy lands in the
  shared mailbox's own Sent Items. New `effectiveSender()` resolver falls
  back to `mail.defaultFromAddress` when the shared mailbox is empty OR
  invalid (hand-edited via the generic settings editor) — mail keeps
  flowing either way. Benign fold-in: the `from` object is now built in
  **both** modes, so `mail.defaultFromName` finally takes effect on the
  MS365 path (previously the URL mailbox alone decided the display name;
  the Google path has always honoured this setting). `sender` is
  intentionally never set — that is the delegated send-on-behalf field
  for the `Mail.Send.Shared` model named in the issue body, which is
  explicitly deferred (app-only + an Application Access Policy covers the
  same ground with zero new secrets and no dependency on a licensed
  "delegate" human account). Error handling: 401 clears the cached token
  and retries once; 429 retries once ONLY when Graph's own `Retry-After`
  is ≤5s (never an unbounded wait inside a web request); 403/404 parse
  Graph's own `error.code` for a targeted admin-facing hint instead of a
  bare HTTP code; an optional `mail.fallbackProvider='google'` (default
  `''` = fail loudly) makes one fallback attempt via `MailerGoogle::send()`
  on any Graph failure. New `tblEmailLog` (migration 176) records every
  send attempt from BOTH providers via the new `Mailer::logSend()` — also
  closes the #230 audit-trail dependency; opportunistic retention prune
  (`mail.log.retentionDays`, default 90) piggybacks on ~1-in-50 writes, no
  new cron endpoint. `GdprEraser` gained a bespoke step scrubbing an
  erased user's address out of `tblEmailLog.toRecipients` (a comma-joined
  free-text column, not a catalogue()-shaped `userCol` FK) — captured
  BEFORE the catalogue's own `tblUsers` anonymisation step nulls it out.
  Admin UI: `/admin/integrations`' MS365 Graph API card gained a
  Shared-Mailbox Sending sub-section (status, mode badge, last-send
  indicator, CSRF'd save form → new `admin/integrations/ms365-mail-save.php`
  handler); its Send Test Email button now calls the real
  `Mailer::send()` instead of duplicating the token+sendMail cURL flow
  inline, permanently closing the test-vs-production drift risk, and
  surfaces the just-written `tblEmailLog` row as the diagnostic detail.
  Fold-in fix: `/admin/integrations/email` was reading a dead
  `email.provider`/`email.from` settings vocabulary (seeded, never
  written) and always reported "smtp" — now reports the real
  `Mailer::provider()` + effective sender, plus a new "Recent sends (last
  10)" `portal-data-list` from `tblEmailLog`. The shared mailbox is
  ADMIN-CONFIG ONLY — read exclusively from `tblSettings` via
  `effectiveSender()`, written only by the CSRF'd, admin-gated save
  handler; no request/user input ever reaches it. No secret is ever
  logged (`tblEmailLog.errorDetail` holds only Graph's own truncated
  `error.code`/`error.message`). Migration 176: `tblEmailLog` +
  5 non-sensitive settings seeds + 1 route seed, folded into
  `full_schema.sql`. All 11 audit checks green, `php -l` clean on every
  touched file.
- feat(workflow): gap #7 (#443) — Workflow Execution Engine +
  generic `/approvals` inbox. Migration 034 shipped four workflow tables
  and an admin definition CRUD, but no code anywhere started, advanced,
  completed, or timed out an instance — this ships the engine
  (`Portal\Core\Workflow`, `web/_core/Workflow.php`): `start()`/`act()`/
  `cancelForSubject()`/`timeoutSweep()`, each an atomic `begin_transaction`
  + `SELECT … FOR UPDATE` + `UPDATE … WHERE currentStep=? AND status IN
  (…)` claim gated on `affected_rows === 1` (the
  `expenses/approve/save.php` / `Payments::markPaymentSucceeded`
  discipline) — a losing racer gets `'conflict'` and does nothing.
  Authorisation (role/user/group match on the current step, plus a
  default-on `workflows.admin_override` site-admin override) lives INSIDE
  `act()`, never trusted from the HTTP layer; a cross-tenant instanceID is
  indistinguishable from a missing one. New generic inbox app
  `/approvals` (AppRegistry entry, `approvals.enabled` default on) — an
  "awaiting your decision" queue, a CSRF'd approve/reject/comment
  handler, and a decision-timeline history view. New token-gated hourly
  `cron/workflow-timeouts.php` — escalates an overdue step (notify + keep
  waiting) unless it explicitly sets `autoAction=approve|reject`; NEVER
  auto-acts on a bare timeout. Reference consumer wired: Announcements
  publish approval behind per-site `workflows.announcements.enabled`
  (default OFF — the manual publish path is byte-for-byte unchanged when
  off); final approval flips `tblAnnouncements.isPublished` inside the
  SAME transaction as the approval claim, so there is no double-publish
  and no approved-but-unpublished ghost; a gate that's on but has no
  active/steppable definition fails OPEN (publishes directly + logs a
  platform warning) rather than blocking every publish on a
  half-configured site. Admin CRUD completion at `/admin/workflows`: new
  per-step delete handler (site-ownership-checked, refuses while the
  workflow has an active instance, catches the `tblWorkflowActions`
  RESTRICT FK as a friendly refusal), an `isActive` toggle, and an
  `autoAction` selector on the Add Step row. The seeded `expense_approval`
  definition (migration 034) stays intentionally dormant — Expenses keeps
  its own independent, department-scoped multi-approver system. New
  `approvalRequests` notification preference (default on) on
  `/account/notifications`. Migration 174: four additive
  `tblWorkflowInstances` columns (`subjectLabel`, `contextJson`,
  `currentStepStartedAt`, `outcome`) + one composite index
  (`idx_wfi_site_status`) + one `tblWorkflowActions.action` enum value
  (`'commented'`) — no new tables, no redesign — plus the
  `announcement_approver` role, the `announcement_publish` definition +
  step, 8 settings seeds, and 4 route seeds.

## [1.4.0] - 2026-07-22 (alpha)
- feat(giving): gap #4 — bulk year-end statements (#440). Treasurer-only
  batch generate + email at `/giving/statements`, built by generalising
  `Portal\Core\Giving::renderStatementPdf()` to `(siteId, donorId, from,
  to, label)` so it is the SAME function called by both the self-service
  page (`giving/my-statement.php`) and the new bulk path — byte-identical
  output by construction. Three deliberate upgrades landed on that shared
  renderer: (1) the donor lookup is now site-scoped (active membership OR
  giving history at the site — an `OR` of two `EXISTS` clauses, closing a
  latent "any userID renders for any site" hole while still letting a
  treasurer pull a statement for a donor who has since left the site);
  (2) a Gift-Aid-eligible column + summary, computed with a correlated
  `EXISTS` (never a JOIN, so overlapping declarations can't double-count
  — mirrors `buildHmrcCsv()`'s own known hazard) and deliberately showing
  no projected 25% reclaim figure; (3) the output path is now namespaced
  by `{siteID}/{periodKey}`, fixing a cross-site filename overwrite the
  old flat `statement-{donor}-{year}.pdf` naming had. New
  `tblGivingStatementLog` (migration 172) is a `UNIQUE(siteID, donorID,
  periodKey)` dedupe/audit log — batches cap at
  `giving.statements.batchPerRun` (default 25) per request (Newsletter-
  dispatch pattern, re-trigger to continue), a "resend to already-emailed
  donors" override is explicit and audit-logged, ZIP download
  (`ZipArchive`, never a combined PDF) degrades to per-row links when
  unavailable, and an optional token-gated `cron/giving-statements.php`
  sweeps a larger queue unattended (`giving.cron_token`, empty ⇒ 403
  fail-closed). New `givingStatements` notifyPrefs opt-out (default on),
  enforced at both queue and live-send time; `GdprEraser` now also
  unlinks an erased donor's rendered statement PDFs from disk.
- feat(service-plans): gap #6 (#442) — additive, non-destructive bridge
  between the two parallel "service plan" data models that never knew about
  each other: the run-sheet builder (`tblServicePlan` SINGULAR, migration
  089, #262/#300) and the worship presentation engine (`tblServicePlans`
  PLURAL, migration 137, #308/#355). New nullable, UNIQUE
  `tblServicePlans.runSheetPlanID` FK (`ON DELETE SET NULL` →
  `tblServicePlan.planID`) lets a worship plan optionally declare which
  run-sheet it presents — NULL (unpaired) is the state of every existing
  row, zero data migration. New `Portal\Core\ServicePlanLink` resolver is
  the ONLY code that knows about both models: pairing enforces same-site
  (hard), same-event when both sides declare one (hard, refused
  otherwise), and 1:1 (hard — UNIQUE key + errno-1062 race catch, never a
  fatal). A NULL-only, one-directional (worship → run-sheet) convenience
  backfill wakes the run-sheet's dormant, write-dead `eventID` column at
  pair time — never the reverse, since the worship side's `eventID` is
  ACL-bearing. New CSRF'd `worship/plan/link` POST handler
  (`worship/plan-link.php`) reuses the worship app's existing
  admin-or-coordinator write gate verbatim; own-row re-pairing overwrites,
  a foreign run-sheet claim is refused with a flash. Read-only
  "counterpart plan" panels added to both editors
  (`service-plans/edit.php`, `worship/plan.php`), each guarded by
  `AppRegistry::isEnabled()` + try/catch so a disabled counterpart app or
  any resolver exception leaves the panel empty rather than breaking the
  page (venue-overlay resilience precedent). No field sync in v1 — the
  song representations are structurally incompatible (free-text title vs
  canonical `songID` FK) — read-only visibility only. Migration 173
  (guarded MySQL-8-safe DDL, one route seed, no new settings keys, folded
  into `full_schema.sql`).
- feat(cron): gap #3 (#439) — new `cron/user-reminders.php` sweeps three
  reminder fields that earlier migrations shipped but no code ever
  consumed: `tblTasks.reminderDate`/`reminderSent` (036), `tblRotaSlot.
  reminderSentAt` + `rota.reminder_days_before` (074), and the milestones
  daily digest promised by `milestones.digest_recipients` (076). Token-
  gated (`user_reminders.cron_token`, empty-fails-closed), 15-minute
  cadence, one grouped email per assignee for rota duties, an atomic
  `WHERE reminderSent = 0` / `WHERE reminderSentAt IS NULL` claim as the
  dedupe for tasks/rota, and a new generic `tblUserReminderLog`
  `(refType, refID, dueDate)` table for milestone-digest (the only family
  without its own sent-flag column) — reserved so a future family like
  DBS-expiry can reuse it with zero DDL. Milestone-digest is explicit
  opt-in only: an empty `milestones.digest_recipients` skips the site
  rather than silently falling back to admins for birthday data. Two new
  notification preferences, `taskReminders`/`rotaReminders` on
  `/account/notifications` (default on) — the first prefs this codebase
  actually honours when sending. Write-path fixes so the dedupe stamps
  stay correct as rows change: `tasks/save.php` re-arms `reminderSent` on
  a future reminder edit, `tasks/complete.php` carries the reminder
  forward (interval-shifted) into a recurring task's spawned next
  occurrence, `rota/swap-respond.php` clears `reminderSentAt` on an
  accepted swap. Migration 171 (new `tblUserReminderLog` table + six
  settings seeds + route seed, zero ALTERs).
- fix(cron): `cron/event-reminders.php` selected `u.email` from
  `tblUsers` — the real column is `emailAddress` — so under this app's
  strict mysqli reporting the very first `prepare()` threw and the
  event-reminder cron 500'd on every single invocation. Fixed throughout
  (`u.emailAddress AS email`). Three other `u.email` call sites found
  during this work are tracked separately in #438, not touched here.
- feat(payments): gap #1 — PayPal Orders v2 adapter fully wired into
  `Portal\Core\Payments` (create checkout, capture-on-return +
  `CHECKOUT.ORDER.APPROVED` webhook backstop, verified webhooks via
  PayPal's own verify-webhook-signature API, refunds by capture id), plus
  the previously-missing user-facing checkout UI: `giving/give.php` ("Give
  online") and a "Pay now" form on `projects/my-pledges.php` (hidden when
  the pledge's project currency doesn't match `payments.currency`). The
  security-critical control is a single choke point:
  `Payments::markPaymentSucceeded()` gained `?int $observedAmountPence,
  ?string $observedCurrency` — every PayPal success path asserts the
  captured amount+currency EXACTLY against the pending row before any
  Giving/Projects fan-out, marking the row `failed` with
  `errorMsg='amount-mismatch…'` and logging `PaymentIntegrityFail` on any
  mismatch instead of booking the wrong amount. The status transition is
  now a single atomic `UPDATE … WHERE status = "pending"` gated on
  `affected_rows === 1`, closing the return-path-vs-webhook race (Stripe
  keeps passing null observed values unchanged — extending the same gate
  to Stripe is a follow-up). `checkout.php` (already hardened by #430 for
  purpose/purposeRef validation) gained the two remaining pieces: a
  £10,000 ceiling on self-service online giving (`GIVING_MAX_AMOUNT_PENCE`)
  and server-built order descriptions (`'Giving — {category}'` /
  `'Pledge — {project}'`) — the POSTed `description` field is removed
  from the flow entirely, closing a provider-page text-injection vector.
  Migration 167 (seeds only): `payments.paypal.webhookId` /
  `payments.paypal.mode`, `payments.paypal.clientId` flipped to
  encrypted-at-rest (predicate-guarded — only where still empty, since
  `decrypt_setting()` returns `''` on a plaintext value), and the new
  `giving/give` route.
- fix(api): #373 (ApiRouter half) — `ApiRouter::dispatch()` and
  `dispatchV1()` included handler files in static-method scope with no
  `global $mysqli, $SETTINGS;` import, so any legacy handler reading the
  bootstrap DB handle as a bare `$mysqli` fatally errored (`null->
  prepare()`) on first hit. `Router::dispatch()` got this fix for #373
  already (commit `58871ca`); `ApiRouter` never did. Six live handlers were
  affected and are unbroken by this fix: `livechat/api/{send,list,moderate,
  prompts,prompt-publish}.php` (viewer chat + admin moderation, #313) and
  `livestream/api/ping.php` (viewer session analytics, #317/#318). No-op
  for handlers already using `App::db()`.
- fix(calendar): #339 residual — `calendar/manage/save.php`'s create-flow
  slug-uniqueness probe (`SELECT eventID FROM tblEvents WHERE eventSlug =
  ?`) had no `siteID` filter, so a slug already taken on one site needlessly
  suffixed `-2` on another and leaked a cross-tenant existence oracle. Now
  scoped `AND siteID = ?`. Completes #339 (the schema half — the
  `uq_event_site_slug` composite unique key — shipped in migration 112).
- fix(worship): #308 live-sync unreachable — the operator console
  (`worship/present.php`) and public projector display
  (`worship/display.php`) poll `/api/worship/state` + POST
  `/api/worship/advance` every 500ms-1s, but the handlers sat at the
  unreachable legacy path `_apps/api/worship-{state,advance}.php` (dead
  `tblRoutes` rows — `api/*` paths are dispatched by `ApiRouter` directly
  and never consult `tblRoutes`) with no `api.worship.*.enabled` flags —
  both calls 404'd and the operator↔display sync could never work.
  Relocated verbatim to the ApiRouter convention path
  `_apps/worship/api/{state,advance}.php` (precedent: migration 144's
  `api/livestream/ping` relocation); migration 158 seeds the two
  `api.worship.*.enabled` flags. Depends on the #373 ApiRouter fix above
  (both handlers read bare `$mysqli`).
- feat(admin): #255 — added AppRegistry entries `_core/apps/{noticeboard,
  worship,salvation,kids}.php` so all four surface in the `/admin/apps`
  marketplace toggle (37 → 41 registered apps). `AppRegistry::isEnabled()`
  returns false when an app's settingKey is missing and `Router::dispatch()`
  403s a registered-but-disabled app's routes, so migration 158 also seeds
  `worship.enabled` / `salvation.enabled` / `kids.enabled` = `'true'` —
  these three had no enable flag before (always-on by virtue of not being
  registered) and would otherwise have gone dark the moment they were
  registered. `noticeboard.enabled` was already seeded (migration 145).
- fix(core): dead `api/*` `tblRoutes` cleanup — 19 rows across migrations
  035(→056)/082/099/100/106/111/133/138 that `Router::handleSpecialRoutes`
  guarantees can never match (`api/*` paths go straight to `ApiRouter`,
  which resolves by URL segment, not by a `tblRoutes` lookup — see
  .claude/CLAUDE.md → "ApiRouter routing trap"). Migration 158 deletes
  them (`DELETE ... WHERE routeKey IN (...)`, idempotent — precedent:
  migration 056 did the same for an earlier batch of 5); the matching 19
  `INSERT` rows are pruned from `full_schema.sql`'s seed blocks too. No
  `api.*.enabled` SETTINGS rows were touched. The orphaned handler files at
  `_apps/api/{tours,push,translate,ai-improve}.php` are left in place,
  parked (no live caller for any of them today).
- feat(admin): #386 fold-in — `Portal\Core\CloudflareStream::testConnection()`
  (a minimal `GET /accounts/{acct}/stream?per_page=1`, machine-safe
  success/message only, never Cloudflare's raw error text) plus a "Test
  connection" button on `/admin/integrations/cloudflare-stream` (new
  admin+CSRF-gated `test.php` handler, migration 158 route) — parity with
  the BookIT Phase 2 affordance, and the cheapest legitimate way to confirm
  the account ID + API token are valid together ahead of the first real
  upload.
- docs: `.claude/CLAUDE.md` — migration coverage note bumped 000-145 →
  000-158; apps table's "not AppRegistry-registered" markers removed for
  noticeboard/worship/salvation/kids (now registered, see above) and the
  app count corrected 37 → 41; refreshed the "Recent ships" entry for this
  fold-in batch.
- feat(api): #387 Event Team Hub REST API read endpoints — projectBookIT
  Event Team Hub Phase 3 integration (projectbookit#347). Two new
  `_apps/calendar/api/{action}.php` handlers, `hub-resources.php` and
  `hub-videos.php`, mirroring the `events/list.php`/`detail.php`
  dual-mode-auth pattern: `ApiAuth::requireRead('eventhub:read')`, a
  tenant guard confirming the requested `eventID` belongs to `Site::id()`
  (404s rather than leaking another tenant's event), then a prepared-
  statement read of that event's `tblEventHubResources` /
  `tblEventHubVideos` rows (#386). The video endpoint never emits a
  signing key, playback token, or any `cfstream.*` credential — only
  `videoRef` (the public YouTube/Vimeo ID or Cloudflare Stream UID a
  player embeds against) plus playback-policy metadata. New bearer-key
  scope `eventhub:read` added to `ApiKey::SCOPES` (surfaces automatically
  in the Admin → Integrations → API Keys mint-form checkbox grid).
  Migration 157 seeds `api.calendar.hub-resources.enabled` /
  `api.calendar.hub-videos.enabled` in `tblSettings` — settings-only, no
  schema, no `tblRoutes` rows (`api/*` paths are dispatched directly by
  `ApiRouter`, which never consults `tblRoutes` — see .claude/CLAUDE.md →
  "ApiRouter routing trap"). OpenAPI: new `Event Team Hub` tag +
  `EventHubResource`/`EventHubVideo` schemas + the two `GET
  /api/calendar/hub-*` paths in `_core/api-spec.json`.
- feat(calendar): #386 Phase 1.5 — Event Team Hub direct Cloudflare Stream
  upload. New `Portal\Core\CloudflareStream` management-API client (Bearer
  `cfstream.apiToken`, TLS at cURL defaults, token never logged;
  `createDirectUpload`/`getVideo`/`updateVideo`/`deleteVideo`). Three
  page-route JSON/form handlers (`calendar/event/hub/upload-url`,
  `/video-status`, `/video-settings`; migration 156, routes only — 155
  already shipped every column + setting) let a coordinator/admin mint a
  one-time direct-upload URL and push a file straight from the browser to
  Cloudflare (basic ≤200 MB; file never touches the server), poll
  readiness, and edit Require-Signed-URLs / Allowed-Origins CF-first.
  Per-user hourly mint rate limit via `tblActivityLogs`; new core
  `$cspConnectExtra` widens `connect-src` to Cloudflare's upload hosts only
  for a manager on a configured install; `event-hub-upload.js` enforces the
  size cap + a host allowlist and tracks CSRF-token rotation across the
  poll loop. `removeVideo` best-effort-deletes a portal-uploaded video from
  Cloudflare first (logged, never blocks the local removal).
- feat(calendar): #386 Phase 1 — Event Team Hub. Per-event staff/volunteer/
  organiser landing page (`/calendar/event/hub?eventID=N`) extending the
  calendar app (Calendar/Events/Preaching Plan stays ONE app). New tables
  `tblEventHubResources` (links/notes grouped by a free-text section,
  notes rendered via `Portal\Core\Markdown::render()`) and
  `tblEventHubVideos` — the latter ships its FINAL shape from day one
  (`uploadStatus` default `'external'` plus the Phase 1.5 upload-lifecycle
  columns: `errorDetail`, `uploadedAt`, `lastCheckedAt`, `allowedOrigins`)
  so the Cloudflare direct-upload follow-up never needs an ALTER. New
  `Portal\Core\VideoEmbed` core helper: allowlist `parse()` detects
  YouTube/Vimeo/Cloudflare Stream from a pasted URL or bare ID (never
  stores/embeds an arbitrary raw URL), `embedUrl()` builds the safe iframe
  src (re-validating the ref's character class immediately before
  interpolation), `frameSrcOrigins()` computes the page-scoped
  `$cspFrameExtra` list, and `signedToken()` hand-signs an RS256 JWT via
  `openssl_sign()` for Cloudflare Stream's signed-URL playback (the
  vendored `simplejwt` library is verify-only, so this is new signing
  code, not an extension of it) — never throws, logs via `Logger` and
  returns null on a missing/invalid signing key so the page renders an
  "unavailable — check Stream settings" tile instead of a broken iframe.
  New `Auth::isEventTeamMember()` (coordinator OR crew leader/participant
  OR job assignee OR a `tblEventPeople` row) gates VIEW access; the
  existing `isCoordinatorOf() || isAdmin()` idiom still gates MANAGE
  (inline add/edit/remove/reorder forms for resources + videos, plus a
  tool strip linking the previously-unlinked
  `/calendar/event/{crews,jobs,attendance,broadcast}` +
  `/admin/calendar/registrations` pages). New admin page
  `/admin/integrations/cloudflare-stream` ships the FULL `cfstream.*`
  field list — including `apiToken`, which nothing in this release uses —
  so the Phase 1.5 direct-upload build needs no follow-up migration or
  admin-page change, only new POST handlers gated behind
  `CloudflareStream::isConfigured()`; secrets (`apiToken`,
  `signingKeyPem`) are `isSensitive`-encrypted, never re-displayed, and a
  blank submit preserves the existing value (mirrors
  `admin/integrations/zoom/save.php`). "Team Hub" entry-point links added
  to `my-events.php` rows and the event detail page for any viewer
  passing `Auth::isEventTeamMember()`. Migration 155 seeds only the four
  routes whose handler files ship in this PR (the Phase 1.5 upload-url/
  status JSON endpoints are deliberately NOT seeded yet) plus the full
  `cfstream.*` settings defaults, all off/blank. Explicitly OUT of scope
  (Phase 1.5): the Cloudflare *management* API (`CloudflareStream` class),
  direct-upload endpoints, upload JS, and the `$cspConnectExtra` core
  change. All 10 `tools/audit-checks/` scripts clean; `full_schema.sql`
  parity maintained (header bumped to "Covers migrations: 000-155").
- docs: refresh `.claude/CLAUDE.md` apps table — was ~17 rows, now covers all
  37 AppRegistry apps (`_core/apps/*.php`) plus `noticeboard`/`worship`/
  `salvation`/`kids` (working, shipped, but not yet AppRegistry-registered)
  and a note distinguishing user-facing apps from infrastructure dirs
  (`account/`, `cron/`, `events/api`, `users/api`, `live/`, `livechat/`,
  `privacy/`, `widget/`, `qr.php`). Prepended this session's PR #372 ships
  (migrations 150-154) to "Recent ships". Rewrote `.claude/HANDOFF.md` from a
  stale, superseded roadmap snapshot to the current PR #372 state (draft,
  CI-green through migration 154) with an accurate Bucket-B / autonomous-item
  breakdown. Fixed #183 — stale bare `core/`/`vendor/`/`sql/` path references
  in `DEV_NOTES.md`'s "file disappeared from the server" code block (should
  read `_core/`/`_vendor/`/`_sql/`, matching the actual repo layout); the rest
  of #183's original findings were already fixed by PR #286.
- feat(service-plans): #300 v2 — operator → confidence-monitor message channel.
  Closes the last open piece of #300 (v1 shipped clock-only in migration 110).
  New `tblServicePlanMessages` (migration 154) — `isCleared`/`clearedAt` rather
  than DELETE, so cleared messages remain as part of the service's audit
  record; indexed `(planID, isCleared, messageID)` for an O(1) poll. Two new
  plain `service-plans/*` page routes (NOT under `api/*` — the ApiRouter
  routing trap doesn't apply): `live-message.php` (admin-only POST, CSRF-
  checked, send/clear, 303 redirect back to `/service-plans/live`, matching
  the app's only existing submit idiom) and `message-poll.php` (GET-only JSON
  poll, any logged-in user, `Cache-Control: no-store`, `ApiResponse::success()`
  envelope, `sinceID`/`lastID` dedup short-circuiting to `changed:false`).
  `live.php` gained an operator panel (current message + clear form + send
  form, hidden once the plan is closed); `confidence.php` gained a themed
  banner polled every 4s (matching the `livechat-widget.js` house cadence) —
  injected via `textContent` only, NEVER `innerHTML`, as the client-side XSS
  line of defence alongside the server's `htmlspecialchars()` escaping on
  `live.php`. No new `tblSettings` — inherits the existing
  `service_plans.enabled` app gate. All 10 `tools/audit-checks/` scripts clean.
- fix(giving): #299 follow-up — auto-attribute the two remaining automatic
  `tblGivingEntry` writers that were deliberately left unhooked when pledge
  campaigns shipped (migration 151): online card giving
  (`Payments::markPaymentSucceeded()`, purpose `'giving'`) and project-pledge
  fulfilment (`Projects::fulfilPledge()`). Both now call
  `Giving::attributeGift($siteId, $donorId, $donatedAt, 0)` (Auto — neither
  flow has an explicit campaign selector) immediately before their existing
  `tblGivingEntry` INSERT, using the same `$siteId` and gift date the row
  itself is stamped with, and append the resolved `campaignID`/`pledgeID` to
  the INSERT's column list/binds. A `0`/unknown donor (no signed-in user, no
  member on the pledge) is passed to `attributeGift()` as `null` rather than
  `0` so it is never mistaken for a real donor row — matching
  `attributeGift()`'s own anonymous-gift contract. No amount, currency,
  category, donor, method, reference, or date logic touched; no schema
  change (the columns already exist). All three `tblGivingEntry` writers
  (manual entry, offering-count close, and now these two automatic paths)
  are consistently attributed.
- chore(ci): new `tools/audit-checks/check_php_table_refs.py` audit check — closes the gap
  that let the GDPR eraser table-name bug (below) slip past review: `check_sql_columns.py`
  only ever parsed `.sql` files, so a wrong table name hard-coded straight into a PHP query
  string (or comment) was invisible to CI. The new check builds the authoritative table set
  from every `CREATE TABLE [IF NOT EXISTS] \`tblX\`` in `web/_sql/full_schema.sql` (155
  tables, migrations 000-153 folded in) and flags any `tblXxx`-shaped identifier referenced
  under `web/_apps/`, `web/_core/`, `web/_install/`, `web/public_html/` that isn't in it. PHP
  comments (`//`, `#`, `/* … */`) are stripped before scanning so comment-only mentions of a
  deliberately-nonexistent table name (e.g. the explanatory comments left behind by the GDPR
  fix itself, in `GdprEraser.php`/`Livestream.php`/`discipleship.php`/`data-export.php`/
  `denominational.php`) don't false-positive — 0 unknown tables on the current tree.
  Non-blocking (`--strict` opt-in), matching the `check_route_targets.py` /
  `check_sql_columns.py` convention; wired into `.github/workflows/pr-security.yml` as
  check 14, alongside the other `check_*.py` invocations.
- fix(ui): replaced the last 11 native `onsubmit="return confirm(...)"` /
  `onclick="return confirm(...)"` call sites (flagged by `check_no_native_confirm.py`) with
  the house `data-confirm="message"` (+ `data-confirm-destructive="true"` on delete/revoke/
  archive/remove actions) pattern from `web/public_html/assets/js/portal-confirm.js` —
  `web/_apps/admin/discipleship/pathways.php`, `pathway-form.php`,
  `web/_apps/admin/integrations/api-keys.php` (rotate + revoke),
  `web/_apps/admin/integrations/webhooks/index.php` (pause/reactivate + delete),
  `web/_apps/admin/calendar/feeds.php`, `web/_apps/worship/song.php`, `worship/plan.php`,
  `web/_apps/calendar/event-jobs.php`, `calendar/event-crews.php`. Confirmation message text
  and form/button behaviour unchanged; `check_no_native_confirm.py` now reports 0 findings.
- fix(core): GDPR eraser table-name corrections + auth-residue coverage — data-protection
  correctness fix, no issue yet. `Portal\Core\GdprEraser::catalogue()` referenced four
  non-existent or mis-cased tables (`tblAttendanceCheckIns`, `tblExpenseClaim`/`submittedByID`,
  `tblSessions`, `tblWebauthnCredentials`) that made `prepare()` fail on every one — because
  `processEntry()` skips (rather than aborts) on a prepare failure, by design, to tolerate
  not-installed apps, `execute()` reported a completed erasure while leaving that PII behind.
  Corrected to `tblEventAttendance`.`userID`, `tblExpenseClaims`.`userID`, removed the
  non-existent `tblSessions` entry (PHP sessions are file-based, never DB-backed), and fixed
  the case on `tblWebAuthnCredentials` (MySQL on Linux is case-sensitive for table names).
  Added the auth-residue tables that a GDPR erasure previously missed entirely: since
  `tblUsers` is anonymised in place rather than deleted, no FK `ON DELETE CASCADE` ever
  swept up `tblLocalAccounts`, `tblLinkedAccounts`, `tblTrustedDevices` or `tblPasswordResets`
  — all four are now hard-deleted (table/column names cross-checked against the already-correct
  export list in `_apps/auth/account/data-export.php`). Added `tblKidProfiles` (a child profile
  links to exactly one parent via `parentUserID` — no multi-guardian junction table exists —
  so erasing the parent now erases the child's profile; `tblKidCheckins` already
  `ON DELETE CASCADE`s from it, so no separate entry was needed there). Also fixed the
  `tblUsers` anonymise entry itself: its `nullCols` referenced four columns that don't exist
  on that table (`address`, `passwordHash`, `msAccountID`, `googleAccountID` — the address
  field is actually `displayAddress`, and the credential/SSO columns live on the tables above,
  not on `tblUsers`), which meant the single most important step — anonymising the user's own
  row — silently failed to prepare and never ran. Hardening: `processEntry()` now writes a
  `skip` entry to the same `tblErasureAudit` HMAC chain when a catalogued table/column can't
  be prepared, instead of returning `false` with no trace, so the next schema drift is visible
  rather than hidden.
- fix(admin): demo-data wipe (#242) referenced `tblExpenses`/`submittedByID`, neither of which
  exist (real: `tblExpenseClaims`/`userID`), so that statement threw inside the wipe
  transaction and rolled back the entire wipe on every run — corrected the table/column names.
- feat(discipleship): #303 Phase 2 — per-user progress + auto-completion.
  Two new tables (migration 153): `tblPathwayEnrolments` (who is assigned to
  which pathway; `status` active/completed/withdrawn) and
  `tblPathwayProgress` (one row per step per member; UNIQUE(stepID, userID)
  — unmarking a step sets `revokedAt` rather than deleting the row, so the
  auto-sweep never resurrects a step a coordinator deliberately unmarked).
  Two guarded new columns on the existing `tblPathwaySteps`
  (`autoRule`/`autoRefID`) let a step opt into auto-completion from
  per-user evidence only (adopted issue #303 blocker decision, option (a)):
  attended a specific event, attended any event in a category, or RSVP'd
  "going" to an event that has since started — `tblSalvationCards` (no
  userID) and `tblDecisionMoments` (aggregate counter) are structurally
  excluded, revisit later. New `Portal\Core\Discipleship` helper —
  `autoSweep()` runs three set-based `INSERT IGNORE … SELECT` statements
  (idempotent via the unique key) then calls `refreshEnrolmentStatuses()`,
  which flips an enrolment to `completed` once every non-optional step has
  an unrevoked progress row (and reverts it to `active` if one is later
  revoked). No scheduler dependency — the sweep runs lazily at the top of
  every discipleship page view; an optional `cron/discipleship-sweep.php`
  endpoint (token-gated like `reminders.cron_token`) adds freshness without
  page views. New member-facing routes fix the Phase 1 dead dashboard link
  (`discipleship.enabled` had no `discipleship` route seeded): `/discipleship`
  ("My pathways" with progress bars) and `/discipleship/view` (step list,
  auto/manual source badges) — every query scoped to `Site::id()` AND
  `$_SESSION['user_id']`, so a member can never see another member's
  progress even via a tampered `?id=`. New admin/pastor surface:
  `/admin/discipleship/progress` (pathway list with enrolment counts),
  `/admin/discipleship/progress/pathway` (roster — a flat `portal-data-list`
  of enrolled members with progress bars, never a members×steps `<table>`
  matrix, per the house `<table>` ban and issue #303's own decision 2;
  includes the enrol form and withdraw button), `/admin/discipleship/progress/member`
  (per-member step list with mark-complete/unmark + notes, showing
  auto-evidence and revocation state). Existing `pathway-form.php` /
  `step-save.php` extended with the `autoRule` select + a site-scoped
  event/category ref picker; `step-save.php` validates the ref resolves at
  THIS site before saving, and a stale ref (event/category later deleted —
  deliberately no FK on `autoRefID`) renders a "(missing)" warning.
  Registered the two new per-user tables in `GdprEraser`'s hard-delete
  catalogue. Mentor relationships remain deferred to a later phase (issue
  #303's third blocker decision).
- feat(giving): #299 bank reconciliation (sub-feature 3 of the "Giving
  polish" issue — offering counting and pledge campaigns shipped as
  sub-features 1/2, above; account-updater for recurring giving remains not
  started). New `tblBankImports` (one row per uploaded statement CSV batch)
  and `tblBankTxns` (one row per imported CREDIT line + its match state,
  migration 152) — debits are never stored, and `matchedCount` is
  deliberately NOT a stored column (it mutates on every match/unmatch and is
  derived with one aggregate join). CSV import (`/giving/reconcile/import`)
  parses bank statement columns by HEADER NAME, never position, against a
  UK-bank alias table (Lloyds/HSBC/Barclays/Monzo/Starling-style headers);
  when auto-mapping can't resolve every required column a manual mapping
  screen lets the treasurer pick columns explicitly before previewing.
  SHA-256 file-hash + `UNIQUE(siteID, fileHash)` blocks re-importing the same
  statement twice. A non-empty credit that fails amount/date parsing fails
  the WHOLE upload (nothing partial is ever stored) — only genuine
  debit/zero/blank lines are silently skipped. Matching is exact-amount and
  window-based (a gift can appear in the bank on or after its date, within
  `giving.reconcile.toleranceDays`, default 5) with a dual candidate universe:
  `matchedEntryID` for a 1:1 match to a single `tblGivingEntry` row, or
  `matchedCountSessionID` for a whole offering-count deposit (which
  `giving/count/close.php` writes as multiple gift-log rows sharing a
  `Count #<id>` reference — excluded from entry-matching to avoid double-
  counting against its deposit). Two or more equal-amount in-window
  candidates is always left unmatched — the matcher never guesses. New
  `/giving/reconcile` (imports dashboard), `/giving/reconcile/view` (per-
  statement matched/unmatched/ignored lists with inline match-suggestion
  mini-forms, plus a two-way "gift log not in this statement" gap panel with
  in-transit-vs-missing badges), and `/giving/reconcile/match` (manual
  match/unmatch/ignore/rematch/delete-import actions) — all gated by
  `Portal\Core\Giving::canManage()`. "Count"/"Reconcile" nav buttons added to
  `giving/manage.php` (the offering-count feature, shipped as sub-feature 1,
  previously had no page linking to it anywhere).
- feat(giving): #299 pledge campaigns (sub-feature 2 of the "Giving polish"
  issue — offering counting shipped as sub-feature 1, above; bank
  reconciliation and account-updater remain not started). New
  `tblPledgeCampaigns` (goal amount, currency, date window, active flag) and
  `tblPledges` (migration 151) — one row per member per campaign, `UNIQUE
  (campaignID, userID)` upsert so re-pledging (including after a
  cancellation) updates the same row rather than duplicating it. Two nullable
  columns added to the existing `tblGivingEntry` (`campaignID`, `pledgeID`,
  both `ON DELETE SET NULL`) carry auto-attribution instead of a link table —
  every gift that can be tied to a campaign/pledge keeps the thermometer and
  progress math a single indexed `SUM`. New `Portal\Core\Giving::attributeGift()`
  is the ONLY code path allowed to produce that pair: an explicit treasurer
  campaign choice is honoured (even outside the campaign's window — a
  deliberate override); "Auto" attributes only when the donor holds exactly
  one open pledge to a currently-active, in-window campaign — two-or-more
  open pledges is a genuine tie and is left unattributed rather than guessed.
  New `Giving::pledgeExpectedToDate()` computes on-schedule expectation
  (one-off pledges owe their full amount immediately; weekly/monthly owe
  their first instalment from the pledge's start; monthly uses calendar-month
  arithmetic, never `/30`). Hooked into BOTH manual `tblGivingEntry` writers:
  `giving/entry-save.php` (new Campaign selector: Auto / None / explicit) and
  the sub-feature-1 offering-count close path (named-envelope rows only, the
  only ones with a real donor). `Projects.php`/`Payments.php` online/project
  giving were deliberately NOT hooked in this PR — those rows were left with
  the new columns NULL; auto-attributing them was a documented follow-up
  (now completed — see the #299 follow-up entry above), not an oversight.
  New UI: `/giving/campaigns` (card grid + thermometers +
  member's own pledge chip + canManage "new campaign" form), `/giving/campaign`
  (detail: thermometer, stats strip, pledge/cancel form, and — canManage —
  pledger list with on-schedule badges, attributed-gifts log, edit-campaign
  form). No hard-delete route for campaigns — `isActive=0` retires one,
  keeping pledge/gift history intact.
- feat(giving): #299 two-person offering-count session (sub-feature 1 of the
  "Giving polish" issue — pledge campaigns/reconciliation/account-updater are
  separate sub-features, not built here). New `tblCountSessions` (migration
  150) tracks a service date's count: two counters independently key cash /
  cheque / envelope totals; once both are in, the system compares them and
  flags `status='discrepancy'` on any mismatch (blocking close) or
  auto-agrees when they match. A discrepancy is cleared either by a counter
  re-entering matching totals or — admin-only — by resolving with agreed
  totals directly. New `tblCountEnvelopes` child table logs named/numbered
  giving-envelope amounts against the session's envelope total; closing a
  session (`/giving/count/close`) validates the named envelopes reconcile to
  the agreed envelope total, then writes the real gift log in one
  transaction — one `tblGivingEntry` row per named envelope (attributed to
  the giver where matched) plus aggregate "loose cash"/"loose cheque" rows
  for anything not itemised, so the total written always balances to
  cashTotal + chequeTotal + envelopeTotal. New UI under `/giving/count`
  (list/start, session detail with counter-entry cards + comparison table +
  named-envelope log) gated by the same `Portal\Core\Giving::canManage()`
  (admin or `treasurer` role) as the rest of `giving`; new
  `Portal\Core\Giving::parseDecimal()` helper for validated
  DECIMAL(10,2)-safe amount parsing. Note: the issue body names the write
  target `tblGiftEntries`, but the giving app actually shipped (#266) as
  `tblGivingEntry` — this migration writes to the real table.
- feat(noticeboard): #363 real media upload pipeline — replaces the `data:`
  URI rejection in `save.php` with `POST /api/noticeboard/upload`
  (site-admin, CSRF, finfo-sniffed MIME allowlist: png/jpeg/gif/webp +
  mp4/webm, hard size cap via `noticeboard.upload.maxBytes` default 15 MB,
  server-generated random filename). Files land under
  `_uploads/noticeboard/` (outside the webroot, mirroring
  `documents/api/create.php`) and are served back with NO auth by the new
  public `GET /noticeboard/media?f=<token>` route — posters must keep
  rendering for an anonymous QR scanner. New `tblNoticeboardUploads` ledger
  (migration 149) + `Portal\Core\NoticeboardMedia` helper links each upload
  to its saved poster and purges orphans (abandoned in the editor, or whose
  poster was later soft-deleted) after every save.
- feat(prayer-requests): #311 prayer-chain assignment residuals — private
  partner notes (`partnerNote`/`partnerLastPrayedAt`, ACL: assignee-or-admin
  only, cleared on reassignment), manual assign dropdowns with an
  open-assignment load-balancing hint on `manage`/`view` (eligible partner =
  active site member holding the new `prayer_team` role), opt-in round-robin
  auto-assign on submission (`prayer-requests.autoAssign`), and email + SMS
  assignment notifications (`prayer-requests.notifyOnAssign`, respecting the
  partner's SMS opt-in) — new `Portal\Core\PrayerChain` helper, migration 148.
- feat(api): REST API v1 write surface (#323 Phase 2, PR #372) — dual-mode `Portal\Core\ApiAuth`
  (bearer API key OR session, resolved centrally); `/api/v1/{resource}[/{id}]` RESTful facade that
  maps HTTP verbs onto the SAME `_apps/{app}/api/{action}.php` handlers + `api.{app}.{action}.enabled`
  flags as the existing `/api/{app}/{action}` routes (no new gating vocabulary); bearer requests are
  tenant-pinned to the key's own site (`Site::forceContext`) and rate-limited per key; new write
  endpoints closing the #157 remnant — Attendance + Documents (create/update/delete) and Expenses
  (create/delete — status-transition update deferred to Phase 3), plus new Users create/update
  (admin-gated, default-off flags); canonical `ApiKey::SCOPES` vocabulary + rotation grace windows;
  admin API-keys UI gains a scope checkbox multi-select (validated server-side against `SCOPES`) and
  a rotation-grace selector; admin audit viewer gains a source (session/apikey) badge + key-prefix;
  OpenAPI spec documents every `/api/v1/*` path alongside the existing legacy aliases.
- feat(admin): outbound webhooks admin CRUD UI (#324)

## [1.2.0] - 2026-07-07 (alpha)
- 2 apps + iCal feed + admin polish — 7 issues (#258, #261, #271, #251, #254, #253, #252) (#281)
- 4 community/pastoral apps: Rota + Praise + Milestones + Care (#256, #260, #259, #257) (#280)
- Apps wave 3: Reading Plans + QR + Invite onboarding + Offboarding (#265, #275, #239, #240) (#283)
- Apps wave 4: 10 apps — Resources, Service Plans, Livestream, Recordings, Zoom, Newsletter, Giving, SMS, Projects, Payments (#284)
- Apps wave 5: Transcription, Translation, AI Assist, GDPR, Photos + 5 infra/security items (#285)
- Audit fixes: bootstrap try/catch + schema drift port + admin gates + CI paths + cleanup (#173-#194) (#197)
- Foundation: App Registry + Markdown + X-Robots-Tag + CHANGELOG (#246, #247, #255, #270) (#279)
- Pre-rollout omnibus: 19 issues, 13 migrations, 1.1.1 → 1.2.0 (#245)
- chore(noticeboard): remove unusable eval-variant bundle (#360)
- chore+feat: post-merge cleanups + installer brand-aware favicons (#354)
- chore: v1.0.0 follow-ups — installer path fix + X-Powered-By branding (#165)
- ci: add cross-source consistency checks to pr-security.yml (#213) (#214)
- docs: sweep stale rename-aftermath references (#189, #182, #183, #194) (#286)
- feat(api): API key infrastructure — mint/revoke/rotate + requireApiKey helper (#323 Phase 1)
- feat(api): write-side CRUD for Announcements, Tasks, Prayer Requests, Leadership (#157) (#291)
- feat(auth): authorised-use notice on the login screen (1.1.1) (#221)
- feat(brand): embed Plus Jakarta Sans across the portal — self-hosted, modular
- feat(brand): product brand layer — runtime ChurchMS / SchoolMS sub-brands (#296)
- feat(brand): wire in Claude Design brand kit — six-asset structure per brand
- feat(brand+easywins): 5 follow-ups bundled — 2 deferred from #297 + 3 church-vertical easy wins
- feat(cop): trio of Church Online Platform easy wins — countdown widget + push + webhooks
- feat(core): page-scoped CSP extension variables in header template (#360)
- feat(discipleship): Phase 1 — pathway + step schema + admin CRUD (#303)
- feat(events): anonymous email-link RSVP — no portal account needed (#335)
- feat(events): anonymous self check-in for events (#314)
- feat(events): auto-build crews + auto-assign jobs (#349)
- feat(events): bundle 7 Events Calendar easy wins from competitive audit
- feat(events): decision moments tracker — tap-to-count per service (#315)
- feat(events): embeddable event widgets — iframe + JS drop-in (#336)
- feat(events): event broadcast / bulk-email by crew/job/segment (#350)
- feat(events): event coordinator role — delegate single-event management (#341)
- feat(events): event crew / group builder (forms-only v1) (#343)
- feat(events): event lifecycle email reminders — 24h + 1h + day-of (#329)
- feat(events): event volunteer job board with capacity indicators (#344)
- feat(events): external calendar feed aggregator — ICS importer (#327)
- feat(events): faceted filter bar — location + search + date range (#330)
- feat(events): multi-day attendance grid + walk-in enrol (#345)
- feat(events): multiple primary organisers per event (#332)
- feat(events): per-event document library link on public event page (#351)
- feat(events): per-event public landing page at /e/<slug> (#346)
- feat(events): per-event registration with VBS-relevant fields (#347)
- feat(events): per-occurrence overrides on recurring series (#333)
- feat(events): public registration — captcha + email confirmation (#348)
- feat(events): surface auto-build / auto-assign buttons on crews + jobs UIs (#349)
- feat(events): volunteer resource portal — /my-volunteering composite read (#342)
- feat(host-console): read-only host cockpit composing COP primitives (#317 Phase 1)
- feat(host-console+live-chat): push prompts + viewer chat widget + ping route fix (#317 Phase 2 + #313 Phase 2)
- feat(install/upgrade): migration runner + state detection + maintenance mode + JSON backups (1.0.1 → 1.1.0) (#220)
- feat(kids): children's ministry check-in / out with badge code (#298)
- feat(live-chat): viewer chat + admin moderation (#313 Phase 1)
- feat(livestream): livestream session analytics (#318)
- feat(noticeboard): self-host React 18.3.1 UMD + wire board under nonce CSP (#360)
- feat(noticeboard): static assets (frontend bundle)
- feat(ops): external error monitor (Sentry / GlitchTip) (#143) (#290)
- feat(pwa): offline write queue + sync-on-reconnect (#233) (#292)
- feat(reports): denominational reporting templates (#305)
- feat(safeguarding): DBS tracking + Auth::isCoordinatorOf gate (#310)
- feat(salvation): decision card tracker (#316)
- feat(worship): SortableJS drag-reorder + song verse split + CCLI usage log (#308 Phase 3)
- feat(worship): live operator + projector display + state polling (#308 Phase 2)
- feat(worship): service plans — schema + CRUD (#308 Phase 1)
- feat(worship): song library + CCLI tracking (#309)
- fix(audit): codebase sweep — duplicate cookie banner + missing Auth import + SQL concat cleanups (#293)
- fix(core): add Portal\Core\Settings wrapper class
- fix(events): wire /e/<slug> prefix into Router::handleSpecialRoutes (#346)
- fix(installer): catch mysqli_sql_exception in steps 3 + 4 (#169) (#170)
- fix(live-chat): ApiResponse::ok→success + drop private setJsonHeaders call (#313 Phase 1 hotfix)
- fix(noticeboard): qr.php — use Qr::generate, strict host pinning, encoder-safe length cap (#360)
- fix(noticeboard): save.php — bind_param arity, cross-site poster guard, URL scheme allowlist (#360)
- fix(schema): backfill full_schema.sql with 35 tables from migrations 105+
- fix(security): post-#281 schema-drift + CSRF findings (#282)
- fix(security-check): inline ALTER columns into CREATE TABLE blocks + CSRF on rsvp-by-link form
- fix(security-check): real bugs + schema backfill + noticeboard app (PR #358)
- fix(security-check): rename \$publish → \$shouldPublish to dodge heuristic false-positive
- fix(security-check): static SQL in notes-save + openapi.json route → openapi.php
- fix(sql): migration 145 header + full_schema noticeboard terminator and seed parity (#360)
- fix(sql): seed default tblSites row in full_schema.sql (#171) (#172)
- fix(ui): installer link colours + portal alert link/code polish (#167) (#168)
- fix(ui): mobile-readiness sweep — 29 → 0 findings (#225) (#295)
- fix: SQL column-name mismatches across installer + import + GDPR export (#198) (#199)
- fix: cross-source consistency audit follow-up #3 (#201 #202 #204 #205 #206 #207) (#212)
- i18n: partial-coverage badge in the language switcher (#210) (#217)
- i18n: remove 24 truly-dead translation keys from en.php (#211) (#216)
- i18n: wrap user-facing hardcoded strings with t() (#209) (#215)
- ops(security): SRI audit — fill missing integrity hashes (#161)
- refactor(brand): /assets/images/brands/ → /assets/images/brandkit/assets/
- refactor(security): move app controllers from public_html/ into _apps/ (#159) (#288)
- refactor(version): single source of truth in _core/version.php (#166)
- release: v1.0.0 launch sprint — 16 commits, 17 deferred issues, full security audit (#158)
- security(csp): nonce-based script-src tightening (#144) (#289)

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Automated sections are appended by `.github/workflows/changelog.yml` per push
to `alpha`, `beta`, and `main` using the heading format
`## [VERSION] - YYYY-MM-DD (branch)`.

## [1.4.0-dev] - Unreleased

Post-1.3.0 Phase-1 ships. Brand font + worship engine landed first; the rest is foundational primitives for the COP / engagement / discipleship surfaces.

### Worship Presentation Engine — full v1 (#308, PR #355)

- Service plans (3-table model: `tblServicePlans` + `tblServicePlanItems` + `tblServicePlanState`).
- Live operator console at `/worship/present?planID=N` with touch + keyboard shortcuts (arrow / space / pageup-down / B / W / S).
- Public projector display at `/worship/display?t=<token>` — 64-char hex display token, polled at 500ms.
- SortableJS drag-reorder + song verse auto-split (per `worship.song_verse_separator` regex) + `tblCcliUsage` audit log + quarterly CSV export at `/admin/reports/ccli`.

### Plus Jakarta Sans modular embed (#356)

- Self-hosted WOFF2 (5 weights, latin subset, ~12 KB each).
- One-line swap via `--portal-font-family` in `portal.css`.
- New `Asset::brandFontsCss()` helper. Installer also picks up the font (bootstrap-free wiring in `_install/index.php`).
- Brand assets relocated from `/assets/images/brands/` to `/assets/images/brandkit/assets/` (the older path no longer exists; manifest.php `is_readable` path bug surfaced + fixed in the same commit).

### Virtual Host Console Phase 1 (#317, PR #357)

- New `Portal\Core\HostConsole` helper class (5 static methods composing existing COP table reads).
- `/admin/host-console` event picker + `/admin/host-console/event?id=N` cockpit with 'Watching now' tile + 7-day sparkline + 6 decision-moment tallies + latest 15 salvation cards.
- Auto-refresh via `<meta refresh content="30">` (JS / SSE upgrade is Phase 2).

### Public API key infrastructure Phase 1 (#323, PR #357)

- `tblApiKeys` — siteID-scoped, sha256-hashed at rest, visible prefix for admin identification.
- `Portal\Core\ApiKey` class — `mint` / `findByPlaintext` / `hasScope` / `revoke` / `rotate`. Token format `wbms_` + 32 hex (128 bits entropy).
- `ApiResponse::requireApiKey($scopes = [])` helper — reads `Authorization: Bearer`, verifies, returns key row.
- `keyHash` added to `ApiResponse::$defaultSensitive` so future JSON handlers can't leak it.
- Admin CRUD at `/admin/integrations/api-keys` with one-time plaintext display banner.
- NOT yet wired: `/api/v1/*` router refactor + write-side CRUD (Phase 2+).

### Discipleship Pathway Tracker Phase 1 (#303, PR #358)

- `tblPathways` + `tblPathwaySteps` (siteID-scoped, sortOrder, isActive).
- AppRegistry entry at `_core/apps/discipleship.php`, gated by `discipleship.enabled` setting (default `false`).
- Admin CRUD: `/admin/discipleship` (list) + pathway form (combined new + edit + step picker) + 7 routes.
- NO per-user progress yet, NO auto-completion, NO mentor relationships, NO member-facing routes — Phase 2+.

### COP Online Engagement Phase 1 — viewer chat + admin moderation (#313, PR #358)

- `tblLiveChatMessages` + `tblLiveRateLimits` + `stream_moderator` role seed (idempotent `WHERE NOT EXISTS`).
- `Portal\Core\LiveChat` helper class — sessionToken validation, sliding-window rate limit (fail-CLOSED on prepare failure), profanity stub, IP detection.
- 3 public/admin API endpoints (`api/livechat/{send,list,moderate}`) — routed via ApiRouter's 3-segment convention with `api.livechat.*.enabled = 'true'` settings seeded.
- `/admin/live/chat` moderation queue page.
- Public `/send`: NO CSRF (third-party embed cookies break it); instead a `sessionToken`-exists guard against `tblLivestreamSessions` plus first-message-only captcha (Turnstile / reCAPTCHA / hCaptcha tokens are single-use).
- Viewer-side chat UI on the `/live` embed page is Phase 2.

### Community Noticeboard — poster wall Phase 1 (#360, PR #358)

- New app: visual poster wall for churches / community sites (Canva embeds, image/video/text posters, once/weekly scheduling, colour/aspect/serif styling, QR share).
- Handlers: `web/_apps/noticeboard/index.php` + `api/{list,save,qr}.php`. Enablement-flag gated via `api.noticeboard.{list,save,qr}.enabled` (ApiRouter convention).
- Schema: `tblNoticeboardPosters` (migration 145). Seeds route + display settings.
- Frontend: prebuilt React bundle at `/assets/noticeboard/noticeboard.noeval.js` + `.css`. React 18.3.1 UMD self-hosted at `/assets/vendor/react/` (SRI-verified against hashes embedded in the bundle).
- Page-scoped CSP extension mechanism in `_core/templates/header.php` (`$cspImgExtra` / `$cspMediaExtra` / `$cspFrameExtra`) — widens img/media/frame directives on the /noticeboard page only. Global CSP unchanged.
- Security hardening: cross-site poster write guard on save (foreign posterIDs insert as new); URL scheme allowlist on `link` / `image` / `thumb` (http(s):// or root-relative only); Canva URL pinned to `www.canva.com`; QR endpoint pins to current host via strict parse_url + honours encoder's ~250-char ceiling; `Qr::pngBytes()`→`Qr::generate()` fatal fixed; `save.php` bind_param arity 20→21 fatal fixed.

### Added — Venue Bookings app (#429)
- New `/venues` marketplace app (migration 170, 15 tables): tenant-side hire
  schedule for rented buildings — per-date bookings, per-venue usage types
  with effective-dated default hours, per-site configurable statuses with
  countsAsConfirmed flags, multi-day groups + recurring generator with
  duplicate-skip, native XLSX/CSV import wizard (Mill Road workbook shape),
  hire agreements with renewal reminders, payable invoice + payment ledger,
  calendar overlay + "is it booked?" event warnings, CSV/PDF exports,
  `venue_manager` role, token-gated reminders cron, GDPR eraser/export
  coverage, `/help/venues` guide.

## [1.3.0] - 2026-06-19 (main)

PR #340 — events platform overhaul + COP + ChurchMS verticals + ops hygiene. **36 issues across 39 commits in one consolidated PR.** The Multi-brand product layer section originally drafted for 1.2.0 (see further down) shipped as part of this release.

### 🗓️ Events Calendar — easy wins (7)

- **#326** Public Submit-an-Event + moderation queue.
- **#328** Schema.org JSON-LD on event page.
- **#331** Photo view alongside the seven existing view modes.
- **#334** Guest +N RSVPs with auto-waitlist on capacity.
- **#337** Cancellation / postponement broadcast banner.
- **#338** VTIMEZONE + TZID + RRULE in iCal output (RFC 5545).
- **#339** Per-site unique event slug.

### 🛡️ Ops hygiene (3)

- **#161** SRI audit — 5 missing integrity hashes filled.
- **#145** `composer.json` manifest + Dependabot composer ecosystem entry for CVE watching.
- **#147** `auto-merge-alpha.yml` re-verified; still dormant, kept.

### 📚 Calendar extension (1)

- **#351** Per-event document library link (`tblDocuments.eventID` + event-page render + upload prefill).

### 🎯 VBS Pro bundle — foundation (5)

- **#341** Event coordinator role — `Auth::isCoordinatorOf()` + `tblEventCoordinators` junction. Foundation primitive composed by every other VBS-flow endpoint.
- **#342** **Volunteer resource portal** at `/account/my-volunteering` — the user-explicit differentiator vs VBS Pro. Pure read-side composition; per-event team/role/docs.
- **#345** Multi-day attendance grid + walk-in enrol (`tblEventAttendance`).
- **#343** Crew / group builder (forms-only v1; data model SortableJS-ready).
- **#344** Volunteer job board with capacity indicators.

### 🎯 VBS workflow completion (3)

- **#350** Bulk email by crew / job / RSVP segment (`tblEventBroadcasts`).
- **#346** Per-event public landing page at `/e/<slug>` — branded hero + live countdown + QR + register CTA.
- **#347** Per-event registration with VBS-relevant fields (`tblEventRegistrations`) + admin moderation list.

### 🗓️ Calendar polish trio (3)

- **#332** Multiple primary organisers per event (`tblEventOrgs` junction).
- **#335** Anonymous email-link RSVP — 256-bit token, `hash_equals`, server-side expiry (`tblEventRSVPInvites`).
- **#329** Event lifecycle email reminders — 24h / 1h / day-of, cron-driven (`tblEventReminderLog`).

### 🛡️ Safeguarding + auto-distribute (2)

- **#310** DBS safeguarding tracking (`tblDbsChecks`). Optional coordinator gate via `safeguarding.dbs_required_for_coordinators`.
- **#349** Auto-build crews + auto-assign volunteers by deepest-deficit-first.

### 🗓️ TEC remaining (4)

- **#336** Embeddable event widgets — iframe + `widget.js` drop-in.
- **#330** Faceted filter bar — location + search + date range on `/calendar`.
- **#333** Per-occurrence overrides on recurring series (`tblEventOccurrenceOverrides`).
- **#327** External ICS calendar feed aggregator — in-house parser, cron-driven (`tblExternalFeeds`).

### ⛪ COP / public-facing (5)

- **#348** Public registration: captcha gate + email confirmation (extends #347).
- **#314** Anonymous self check-in for events — SHA-256-hashed IP, kiosk / QR / self source.
- **#315** Decision Moments tracker — 6 quick-tap categories per event.
- **#316** Salvation / decision card tracker — public form + admin follow-up workflow.
- **#318** Livestream session analytics — CORS-friendly ping endpoint + admin dashboard.

### 🎵 ChurchMS verticals (3)

- **#305** Denominational reporting templates with CSV export.
- **#309** Song library + CCLI tracking — FULLTEXT-ready (`tblSongs`).
- **#298** Kids check-in / check-out with 6-digit safeguarding badge codes.

### 🧰 Infrastructure

- **`Portal\Core\Settings`** — new thin static wrapper around `$SETTINGS` (fixed latent bug in earlier files that called `Settings::get()` against a class that didn't exist).
- **Router::handleSpecialRoutes** — prefix-match dispatcher for `/e/<slug>`.
- 25 idempotent SQL migrations (112–136).

### Multi-brand product layer — `ChurchMS` / `SchoolMS` / … sub-brands (#296) — folded in from 1.2.0 draft

System-level brand identity that sits ABOVE the existing per-tenant `branding.*` cascade. The installer's new **Step 1.5 — Organisation Type** picks a preset bundle; the install rebrands display surfaces (header meta, footer attribution, X-Powered-By header, PWA manifest name, installer wizard itself) to the matching short name and tagline. Tenant branding (`Site::branding()`) still beats product defaults — a `Mill Road SDA Cambridge` site on a `ChurchMS` install keeps its own siteName in chrome.

**Decisions baked in:**
- **Runtime-only brand** — same codebase, settings-driven; admins can change `portal.industry` post-install via `/admin/settings`.
- **Preset bundles include defaults but not enforcement** — same app mix across all brands; admins can re-enable anything (existing `/admin/apps` mechanism from #255).
- **Per-brand asset folders shipped** — `assets/images/brands/{generic,church}/` with placeholder copies; designers replace with distinct artwork in a follow-up.
- **Publisher always "MWBM Partners Ltd"** — sub-brands are MWBM products, not white-labels.

**Shipping in this PR:**
- `_core/brand-defaults.php` — preset registry (generic / church / school / nonprofit / community / small-business).
- `_core/Site.php::productName() / productTagline() / productPublisher()` — resolution helpers with full fallback chain.
- `bootstrap.php` — `PORTAL_PRODUCT_NAME_DEFAULT` / `…_TAGLINE_DEFAULT` / `…_PUBLISHER_DEFAULT` constants loaded from brand-defaults; X-Powered-By header reads from `$SETTINGS['product']['name']` with cold-start fallback to the constant.
- `_core/templates/header.php` + `footer.php` — meta generator + powered-by mark resolve via `Site::productName()`.
- `_install/index.php` — new Step 1.5 dropdown picker; Step 3 schema install seeds `portal.industry` + `product.{name,tagline,publisher}` after `full_schema.sql` runs.
- `public_html/manifest.php` (new) — replaces static `manifest.json`. Routed via tblRoutes; emits brand-aware PWA manifest + per-brand icons (with fallback to generic asset folder when sub-brand assets aren't present yet).
- Migration `108_product_brand_layer.sql` — seeds the 3 new `product.*` rows and the `manifest.json` route on existing installs.
- `full_schema.sql` — same seeds for fresh installs.

**Known follow-ups deliberately deferred:**
- `openapi.json` brand-aware conversion (developer-facing surface, lower priority).
- Distinct sub-brand artwork (asset folders currently contain placeholder copies of generic).
- School / Charity / Community / Small-Business preset polish (presets present but unverified beyond round-trip).

### Pre-rollout omnibus — 19 issues addressed in one branch

Substantial pre-production-rollout hardening. Each item shipped, scaffolded, or scoped to a follow-up PR per the per-issue status comments.

**Security + ops:**
- `#160` Baseline security response headers (HSTS, Permissions-Policy, COOP, CORP, Referrer-Policy, X-Content-Type-Options, X-Frame-Options) — all per-site overridable via `portal.headers.*`.
- `#142` Backup freshness check `/admin/maintenance/backup-check` with cron mode + email alerting on stale/critical state.
- `#227` Admin backup UI `/admin/maintenance/backup` — list / inspect / run-now / per-table-restore / full-restore / delete, wraps `DbBackup`.
- `#228` System Health page `/admin/maintenance/health` — 8 live probes (DB, disk, backups, errors, sessions, migrations, PHP, maintenance flag). JSON via `?cron=1`.
- `#229` Critical-error alerting — Logger hook with rate-limited email dispatch, sentinel-based cooldown, severity filtering.
- `#230` Email Deliverability admin `/admin/integrations/email` — provider summary, test send, SPF/DKIM/DMARC DNS probe.

**Onboarding / UX:**
- `#222` First-run dashboard empty-state panel with auto-detected setup checklist.
- `#223` Help: Admin First Steps `/help/admin-first-steps` accordion.
- `#224` UK ICO cookie consent banner in global footer.
- `#242` Demo data load/wipe `/admin/maintenance/demo-data` (gated by `portal.demo_mode.enabled`).
- `#237` Tour engine scaffold (`tblTours` + `tblUserTours` + welcome tour seed + `/admin/tours`). Playback JS in follow-up.
- `#241` Print stylesheets at `assets/css/print.css`, wired into `header.php` with `data-portal-name` / `data-print-date` running header.

**Core features:**
- `#231` `Portal\Core\Sabbath` quiet-hours class with `isQuietNow()` + window computation. Two-level (org + per-user `tblUsers.sabbathHonour`). Integrated into Logger alerting.
- `#238` `tblEvents.eventTimezone` column (display-layer conversion in follow-up).

**Docs:**
- `#226` `docs/day2-support.md` + user-facing `/help/support`.
- `#232` `docs/rollout-plan.md` + `portal.rollout.pilot_mode` flag.

### Verified already-fixed (closed with audit trail)

`#173`, `#174`, `#175` — bootstrap try/catch wrappers present.
`#176` — CI workflows already reference `web/_core/version.php`.
`#177`, `#178`, `#179`, `#180` — export controllers use `App::isAdmin()` / `App::hasRole('Approver')`.
`#181`, `#188` — upgrade.php + Migrator have try/catch.
`#184`, `#185`, `#186`, `#187` — schema drift (siteID on tblRecurrenceRules, tblAnnouncements, tblDocCategories+tblDocuments, totp columns+tblTotpBackupCodes) all present in full_schema.sql.

### Scoped to follow-up PRs (left open)

`#161` SRI audit (gaps in Sortable + Swagger CDN tags; recommend vendoring).
`#225` Mobile audit (requires physical devices).
`#233` PWA offline-first with sync queue (XL).
`#234` MS365 Graph delegate sending (XL).
`#235` GDPR right-to-erasure (XL; needs policy doc first).
`#236` Photo approval queue (XL; needs photo upload pipeline first).
`#239` Invite-based onboarding (L).
`#240` Offboarding workflow (L).

### Added — HTML email templates + Portal.Confirm modal (post-omnibus polish)

- **#243** `Mailer::send()` signature broadened to `string|array $to` with auto-HTML detection; new `Mailer::sendTemplated($to, $subject, $template, $vars)` helper. 4 templates shipped under `web/_core/templates/email/`: `base`, `password-reset`, `invite`, `critical-alert`. Logger alerts now use the templated `critical-alert` for nicely-formatted email. Admin preview at `/admin/integrations/email-templates`. **Side fix:** original omnibus calls to `Mailer::send()` passed string `$to` to an array-typed param — TypeError under `strict_types=1`. Signature now accepts both.
- **#244** `Portal.Confirm` themed Bootstrap modal replacement for native `window.confirm()`. Promise-returning `Portal.Confirm.show({title, body, destructive, confirmLabel, cancelLabel})`. Auto-binds via `data-confirm` attribute on forms AND on buttons (preserving button name/value via hidden shim for multi-action forms). All 19 `confirm()` call sites migrated. CI guard `tools/audit-checks/check_no_native_confirm.py` prevents regression.

### Added — Apache-level themed error pages

`.htaccess` `ErrorDocument` directives route 403/404/500/503 through `/error.php` → `Router::renderError()` → themed templates. Fallback to self-contained themed HTML when bootstrap is broken (so even a bootstrap fatal renders the right brand).

### Added — Welsh locale gating + per-user Sabbath override

- `portal.i18n.minimum_coverage_for_switcher` setting — hides locales below threshold from the language switcher (except current). Set to `0.95` to hide Welsh until parity reached. Current locale always shown so user can switch back.
- `/account/notifications` surfaces the `tblUsers.sabbathHonour` per-user override (inherit / on / off) when `portal.sabbath.enabled = '1'` at org level.

### Fixed — Polish from omnibus review

- Cookie banner class-name mismatch (`print.css` now hides `.portal-cookie-banner`).
- Demo users now `isActive = 0` with valid bcrypt format so they cannot sign in regardless of password hash exposure.
- System Health "Active sessions" probe rewritten to count files in `session_save_path()` (codebase uses PHP native sessions, not the non-existent `tblSessions`).
- CSRF guard added to `/admin/settings/dismiss-first-run` handler (raised in `pr-security.yml` review of PR #245).
- Defence-in-depth `htmlspecialchars()` on top of `urlencode()` for email-template inspect iframe src (raised in same review).

### Migrations 061–072 + `demo_data.sql`

13 idempotent migrations added in this release. Fresh installs pick them up from `full_schema.sql`; stale-DB retries run them via the migration runner from PR #218.

### Changed — Version bumped 1.1.1 → 1.2.0

Net-new feature surface across security, ops, UX, and core → minor bump per semver.

### Added — Waves 3 / 4 / 5 install-on-demand apps (PRs #283 / #284 / #285)

16 new apps shipped across three branches, all on top of the App Registry pattern (`_core/apps/{slug}.php` auto-discovery, `enabled = 0` default, per-site toggle at `/admin/apps`).

**Wave 3 (#283):** `#265` Reading Plans (Bible-in-a-Year, streak counter) · `#275` QR generator + CueRCode adapter slot · `#239` Invite-based onboarding (SHA-256 hashed tokens, public acceptance route) · `#240` One-click offboarding (7-step atomic revocation + 7-day rehire window).

**Wave 4 (#284):** `#263` Resources (room/asset booking with overlap conflict detection) · `#262` Service Plans (printable run-sheet builder) · `#273` Livestream (YouTube/Vimeo/Twitch/Facebook embed + countdown) · `#264` Recordings (RSS podcast feed + HTTP Range streaming + FULLTEXT search) · `#274` Zoom (OAuth + meeting creation from calendar + webhook HMAC verification) · `#269` Newsletter (composer with auto-pulled content blocks, provider abstraction reserves a `webMailerMatt` slot) · `#266` Giving (tithe log, Gift Aid digital declaration, HMRC Schedule CSV, dompdf year-end statement) · `#272` SMS (Twilio + MessageBird + SigV4-signed AWS SNS, verification, per-category opt-in, Sabbath quiet hours) · `#267` Projects (public fundraising pages with captcha-gated anonymous pledges, thermometer, updates feed) · `#268` Payments (Stripe Checkout + v1 HMAC webhook + refund; side-effects route into Giving + Projects via `tblPayment.purpose`).

**Wave 5 (#285):** `#276` Transcription (Whisper / AssemblyAI / local whisper.cpp, FULLTEXT search, click-to-timestamp) · `#278` Translation (Anthropic / OpenAI / Google / DeepL / LibreTranslate, content-addressable cache, 10-locale heuristic detection including Welsh) · `#277` AI Assist (Anthropic / OpenAI / ollama, 4 editable prompt-template kinds, monthly cap + per-user daily limit + audit trail) · `#235` GDPR Article 17 erasure engine (19-table catalogue, sealed audit chain via chained SHA-256, one-month SLA queue) · `#236` Photos (4-tier role visibility, moderation queue, EXIF-aware GD re-encode strips metadata for non-privileged downloads) · `#249` Off-site backup (weekly AES-256-CBC to rclone/S3/SFTP) · `#250` Disaster-recovery runbook + in-portal landing · `#161` CDN SRI audit + Asset helpers for Sortable + Swagger UI · `#248` End-to-end MySQL migration test harness · `#225` Static mobile readiness audit + worksheet.

### Added — Post-wave-5 infrastructure hardening (PRs #286-#293)

- **PR #286** — Doc sweep cleaning up `core/` / `vendor/` / `sql/` → `_core/` / `_vendor/` / `_sql/` references (#189, #182, #183, #194). Issues #190, #191, #192, #193 verified already-resolved.
- **PR #287** — `auto-merge-alpha.yml` verification (#147). Workflow structurally correct; 0 runs to date because no PR has ever targeted `alpha`. Documented re-verification procedure + 6-month delete-if-still-unused criterion in DEV_NOTES.
- **PR #288** — Defence-in-depth refactor (#159): **317 PHP files** in 37 app dirs `git mv`'d from `web/public_html/` to `web/_apps/`. Only entry-point files (`index.php`, `error.php`, `api-docs/index.php`) remain in the webroot. Router falls back to `public_html/` for legitimate-entry-point routes.
- **PR #289** — Nonce-based CSP `script-src` tightening (#144). New `App::cspNonce()` static method (16-byte hex, request-memoised). Modern browsers strictly enforce the nonce; `'unsafe-inline'` retained as a fallback for older browsers per CSP3 semantics.
- **PR #290** — External error monitor (#143). New `Portal\Core\ErrorMonitor` adapter — Sentry- and GlitchTip-compatible store-API envelope; SigV4-style auth via `X-Sentry-Auth` header; 2 s connect + 5 s total timeout; sample-rate gate; admin smoke-test endpoint. Hooked into `Logger::errorPlatform()` alongside the existing critical-alert dispatch.
- **PR #291** — REST API write-side CRUD (#157): 10 new endpoints — Announcements full CRUD; Tasks create/complete/delete; Prayer Requests create/moderate; Leadership assign/unassign. OpenAPI spec now has 23 paths (up from 13). Documents/Attendance/Expenses deferred (different module shapes).
- **PR #292** — PWA offline write queue (#233). New `Portal.OfflineQueue` IndexedDB module; `data-offline-queueable` form interceptor; Background Sync API integration in `sw.js`; `/account/offline-queue` user inspector; connection indicator dot in footer. `X-Offline-Queued-At` header carries the original submission timestamp to receiving endpoints.
- **PR #293** — Codebase audit sweep: duplicate cookie banner in `footer.php` removed (was bypassing the GDPR consent pipeline); missing `Auth` import in `footer.php` fixed (would have thrown `ClassNotFoundException` on first cookie-banner render); 6 SQL `int`-concatenation queries converted to prepared statements for consistency with the rest of the codebase (no security impact — all int-cast at source).

### Changed — `_apps/` defence-in-depth refactor (PR #288)

Architectural-level change: every app controller now lives **outside** `public_html/`. The `PORTAL_APPS` constant in `bootstrap.php` now points at `web/_apps/`. Router-level fallback to `public_html/` for the small set of routes that legitimately render entry-point pages (Swagger UI, openapi.json, PWA offline fallback). `.htaccess` deny-`.php` rule simplified — exempts only the 3 known entry points (`index.php`, `api-docs/index.php`, `error.php`). All 7 audit scripts updated to scan `_apps/` alongside `public_html/`.

### Audit scripts (`tools/audit-checks/`)

Three new static-analysis scripts added across the post-wave-5 work:

- `check_cdn_sri.py` (#161) — flags `<script>`/`<link>` tags pointing at known CDN hosts without `integrity=` attributes.
- `check_migration_idempotency.py` (#248) — flags `CREATE TABLE` / `ADD COLUMN` / `CREATE INDEX` without `IF NOT EXISTS` and `INSERT` without `ON DUPLICATE KEY UPDATE` / `INSERT IGNORE`. Quote-aware splitter respects `;` inside string literals.
- `check_mobile_readiness.py` (#225) — flags missing `<meta viewport>`, hard-coded widths > 320 px, bare `<table>` outside `.table-responsive`, file inputs without `accept=`, modals without `modal-fullscreen-sm-down`.

### Audit pass status (2026-06-03)

- `check_route_targets.py`: 304 routes, **0 missing target files**
- `check_sql_columns.py`: 109 tables, **0 mismatches**
- `check_no_native_confirm.py`: **0 findings**
- `check_cdn_sri.py`: **0 findings**
- `check_settings_keys.py`: 3 informational (all have `??` fallbacks)
- `check_migration_idempotency.py`: 19 informational (pre-multi-site historical cohort, Migrator-protected)
- `check_mobile_readiness.py`: 29 informational fix targets (concrete sweep candidates)

## [1.1.1] - Unreleased

### Added — Authorised-use notice on the login screen (#221)

All sign-in paths (local, MS365, Google, passkey) land on `/auth/login`, so a single notice at the bottom of that page covers every entry point. The notice is mandatory and cannot be excluded — there's no opt-out toggle:

> **Authorised use only.** {site_name} is a private intranet for staff and volunteers. All sign-ins, page views, and actions are logged and retained for audit purposes. By signing in you acknowledge that you are an authorised user and consent to monitoring of your activity.

i18n keys: `auth.terms_notice_heading`, `auth.terms_notice` (with `:site_name` placeholder). English + Welsh translations supplied.

## [1.1.0] - Unreleased

### Added — Installer state detection + Drop-and-rebuild option (#220)

The installer now probes the target database after credentials validate and classifies into one of:

- `EMPTY` — fresh install (current path)
- `PARTIAL` — tables exist, no `.installed` lock → render step 2.5 (Continue / Drop)
- `INSTALLED_CURRENT` — same version → redirect to portal
- `INSTALLED_UPGRADE` — older version → step 2.5 with upgrade messaging
- `FRESH_REQUIRED` — installed below `fresh_required_below` policy threshold → step 2.5 with continue disabled

Drop-and-rebuild is gated by a typed hostname confirmation (configurable via `portal.upgrade.require_hostname_confirm`).

New files:
- `web/_install/upgrade-policy.php` — static version-threshold + backup defaults
- `web/_install/db_state.php` — `detectDbState()` helper (bootstrap-free)

Installer modifications:
- Step 2 calls `detectDbState()` after credentials validate
- New step `2.5` renders the choice page with state-specific messaging
- Step 3 honours `$_SESSION['install_action']` — `'drop'` triggers `DROP TABLE` of every `tbl*` table (FK_CHECKS off) before re-running schema
- Step 5 writes `portal.installed_version = INSTALL_VERSION` and clears any leftover maintenance flag

### Added — Auto-backup before upgrade migrations + JSON snapshot engine

New `Portal\Core\DbBackup` class. Used by `/admin/upgrade`:

- Snapshots every `tbl*` table to `web/_backups/upgrade-YYYYMMDD-HHMMSS/{tableName}.json` before any migration runs.
- `_manifest.json` per snapshot with schema metadata, row counts, SHA-256 hashes.
- JSON per-table format chosen for programmatic restore (the future per-migration "rescue script" path for Tier-3 breaking changes).
- Retention: `portal.upgrade.backup.keep_last_n` (default 10) — pruned LIFO on each successful upgrade.
- Can be disabled via `portal.upgrade.backup.enabled = 0` (not recommended).

`DbBackup::restoreTable()` reads a snapshot and re-INSERTs in a transaction. The admin restore UI is a follow-up PR.

### Added — Maintenance mode (auto on during upgrade, auto off when done)

New `Portal\Core\Maintenance` class + front-controller gate:

- Active when EITHER `portal.maintenance.active = '1'` OR `portal.installed_version < PORTAL_VERSION` (version drift).
- Front controller (`public_html/index.php`) renders a themed 503 maintenance page (with `prefers-color-scheme`, auto-refresh every 60s) for non-admin / non-allow-listed requests.
- Allow list: `/auth/login`, `/auth/logout`, `/admin/upgrade`, `/admin/maintenance`, `/assets/`, `/offline`. Admins always pass through.
- `/admin/upgrade` flips the flag on at the start of migrations, updates `portal.installed_version` on success, flips the flag off. Both signals clear automatically.

### Added — Migration 060 + tblSettings registrations

`web/_sql/060_portal_versioning_and_maintenance.sql` seeds the new keys:
- `portal.installed_version`
- `portal.maintenance.active`, `portal.maintenance.message`
- `portal.upgrade.backup.enabled`, `portal.upgrade.backup.keep_last_n`
- `portal.upgrade.fresh_required_below`, `portal.upgrade.require_hostname_confirm`

All idempotent. Fresh installs pick them up from `full_schema.sql`'s seed; stale-DB retries get them via the migration runner from #218 / PR #219.

### Changed — Version bumped 1.0.1 → 1.1.0

Net-new feature surface → minor bump per semver.

### Not in this PR (follow-ups)

- Admin UI for browsing / restoring snapshots (`/admin/maintenance/backup`). The `DbBackup` engine is in place; the UI is a separate PR.
- Per-migration "rescue script" mechanism for Tier-3 breaking schema changes (needed when `fresh_required_below` is first bumped above `0.0.0`).
- Existing `/admin/settings` UI already supports editing the new `portal.upgrade.*` and `portal.maintenance.*` keys (they're regular tblSettings rows).

## [1.0.1] - Unreleased

### Fixed — Installer is now resilient to schema drift across retries

The installer's step 3 ran `full_schema.sql` and stopped there. Because
`CREATE TABLE IF NOT EXISTS` is a no-op when a table already exists,
any failed install that reached step 3 successfully locked the database
into whatever shape the schema had at that moment. If a later code
change added a column to one of those tables (via a numbered migration),
the user's retry would still hit the old shape — and step 4's INSERTs,
which assume the current shape, would fatal with
`Unknown column '<colname>' in 'field list'`.

This was the root cause of three consecutive support rounds:
- `tblUsers.siteID` doesn't exist (#198 / PR #199 — fixed the schema
   but stale DBs still fatalled)
- `tblLocalAccounts.isVerified` doesn't exist (this round)

The schema-vs-runtime audits the project ran in #197/#199/#212/#214
were all looking at the wrong dimension — schema vs PHP code — when
the actual breakage was schema-on-disk vs schema-in-this-DB.

**Fix**: step 3 now runs `full_schema.sql` AND then runs every
numbered migration in `web/_sql/0NN_*.sql` order. Every migration
uses idempotent constructs (`ADD COLUMN IF NOT EXISTS`,
`INSERT ... ON DUPLICATE KEY UPDATE`, `DELETE FROM ... WHERE`), so
fresh installs see them as no-ops (`full_schema.sql` already has
every column the migrations would add) and stale-DB retries pick up
the missing columns. The installer doesn't go through
`Portal\Core\Migrator` because (a) Migrator filters by
`tblMigrations`, which `full_schema.sql` seeds as fully-applied, and
(b) the installer is bootstrap-free.

### Fixed — `tblTasks` SELECT uses non-existent `dueAt` column (#218)

`web/public_html/tasks/api/list.php` issued a `SELECT taskID, title,
description, dueAt, ...` against `tblTasks`. The schema column is
`dueDate`, not `dueAt`. The `/api/tasks/list` endpoint would 500 on
every call. Surfaced by the widened
`tools/audit-checks/check_sql_columns.py` — see next entry.

### Changed — `check_sql_columns.py` now scans UPDATE + SELECT, not just INSERT

The CI consistency check added in #214 only walked `INSERT INTO tbl
(col, col)` column lists. It missed bugs in `UPDATE tbl SET col = ?`
clauses and `SELECT col, col FROM tbl` lists — which is how
`tblTasks.dueAt` slipped past the previous "exhaustive" sweep.

The widened check now matches:

- `INSERT INTO tbl (cols…)` (already supported)
- `UPDATE tbl SET col = …, col = …` — bounded so we don't span across
  PHP-concatenated multi-statement strings or beyond `WHERE` / `ORDER`
- `SELECT col, col FROM tbl` — only when the column list is simple
  identifiers (no functions, joins, aliases, or `*`); skips pure
  numeric "columns" so `SELECT 1 FROM tbl WHERE …` existence checks
  don't false-positive

Line-number reporting also fixed: under PHP string concatenation,
`'INSERT (' . 'col1, col2)'` is reconstructed for matching, which
collapses newlines and confused the previous offset lookup. We now
compute line numbers against the reconstructed text, which keeps the
report within a few lines of the actual SQL.

### Changed — Version bumped to 1.0.1

## [Unreleased]

### Fixed — Audit follow-up #3: cross-source consistency

A four-dimension parallel audit (SQL ↔ schema, routes ↔ files, settings ↔
code, i18n keys ↔ translations) surfaced 13 more findings. This entry
covers the 6 actionable runtime bugs (#201, #202, #204, #205, #206, #207).
Two settings findings (#203 + #208) were re-investigated and closed as
false positives — both keys were seeded further down in
`full_schema.sql` than the audit looked. The i18n findings (#209, #210,
#211) are tracked separately for cleanup / triage.

- **`tblEvents.deletedAt` missing column** (#201) — `events/api/delete.php`
  sets `deletedAt = NOW()` alongside `isDeleted = 1` in its soft-delete
  UPDATE, but the column was never added. Every admin event-delete
  fatalled with `Unknown column 'deletedAt'`. Added to schema +
  migration `054_events_deleted_at.sql`.
- **`admin/upgrade` route had invalid targetFile** (#202) — pointed at
  `../install/upgrade.php` (escapes `public_html/`, uses the
  pre-rename `install/` name). Clicking the link 404'd. New proxy
  file `web/public_html/admin/upgrade.php` `require`s the real handler
  at `_install/upgrade.php`; route now points at the proxy. Migration
  `055_admin_upgrade_route_fix.sql` repoints the route on existing
  installs.
- **Five redundant `api/*` routes** (#204) — migration 035 inserted
  `api/announcements/list`, `api/attendance/list`, `api/events/detail`,
  `api/events/list`, `api/users/list` against a routing shape the
  router doesn't actually use (the `api/` prefix is special-cased to
  `ApiRouter::dispatch()` which resolves `{app}/api/{action}.php`).
  Pure dead config. Migration `056_remove_redundant_api_routes.sql`
  DELETEs them on existing installs.
- **Dead `account/linked-accounts` route** (#205) — target file
  `auth/account/linked-accounts.php` was never created. Removed from
  schema + migration `057_remove_dead_linked_accounts_route.sql`.
- **`login/webauthn` route missing** (#206) — the WebAuthn AJAX
  endpoint at `auth/login/webauthn.php` was called from the login
  form via `fetch('/login/webauthn')` but had no route entry. Worked
  via `.htaccess` fall-through until the v1.0 security hardening
  tightened direct-PHP access; now needs the route. Added to schema +
  migration `058_login_webauthn_route.sql`. `isProtected=0` (pre-auth).
- **`api.expenses.delete.enabled` wrongly marked `isSensitive=1`**
  (#207) — the value is the boolean flag `'false'`, not a credential.
  Sensitive=1 makes the bootstrap loader try to libsodium-decrypt the
  plaintext on every request. Flipped to 0 in schema + migration
  `059_fix_api_expenses_delete_isSensitive.sql`.

### Fixed — Audit follow-up #2: SQL column-name mismatches (#198)

A class of bug my prior audit didn't check for: runtime SQL statements
that reference columns which don't exist on the named table. Surfaced
when the user hit step 4 of the installer with `Unknown column 'siteID'
in 'field list'`. A targeted re-audit found 5 more instances of the
same shape across 3 files.

- **Installer step 4** — `web/_install/index.php:306` removed `siteID`
  from the `tblUsers` INSERT. `tblUsers` has no `siteID` column; multi-
  site assignment is via `tblUserSites` (already inserted on the next
  statement).
- **Bulk user import** — `web/public_html/admin/users/import.php:156`
  had the identical `tblUsers.siteID` bug. Removed; `bind_param` updated.
- **GDPR data export** — `web/public_html/auth/account/data-export.php`:
  - `tblLinkedAccounts`: `createdAt` → `linkedAt`
  - `tblWebAuthnCredentials`: `label` → `friendlyName`
  - `tblExpenseClaims`: `submittedByID` → `userID`
  Three columns the schema never had / had renamed — would have fatalled
  every `/account/data-export` request.
- **tblLocalAccounts schema drift** — runtime code (installer step 4 +
  GDPR export) referenced `isVerified` and `createdAt` columns that no
  migration ever added to `tblLocalAccounts`. Both clearly intended
  (installer sets `isVerified = 1` for the admin; GDPR export reads
  both). Added to `full_schema.sql` CREATE TABLE; new migration
  `053_local_accounts_columns.sql` adds them to existing installs via
  idempotent `ADD COLUMN IF NOT EXISTS`.

The honest reason this slipped past the previous "thorough check": my
audit prompts covered uncaught exceptions, schema-vs-migration drift,
path drift, and CSRF/auth gates — but not "do runtime SQL column names
exist on the named tables". Added that dimension to the audit
playbook for the next sweep.


### Fixed — Codebase audit follow-ups

- **Bootstrap critical-path mysqli exceptions** (#173, #174, #175) — wrapped
  three unguarded mysqli call sites in `web/_core/bootstrap.php` (settings
  load at line 341, user-locale lookup at line 469, and `Site::preDetect()`
  at line 326) in try/catch with graceful fallbacks. Without these, any DB
  hiccup in the bootstrap critical path produced a bare HTTP 500 for every
  request to the portal — bypassing the global exception handler because
  these queries run before / outside its safety net.
- **CI workflow paths** (#176) — `version-bump.yml` and `changelog.yml`
  pointed at `web/core/App.php` which no longer exists (renamed to
  `_core/version.php`). The next push to `alpha`/`beta` would have failed.
  Workflows now read from `web/_core/version.php` using a `^return '...';`
  grep pattern matching the new file shape.
- **Admin/approver gates routed through Auth instead of App** (#177, #178,
  #179, #180) — four export handlers (`admin/activity/export.php`,
  `attendance/export.php`, `admin/users/export.php`,
  `expenses/api/export.php`) called `Auth::isAdmin()` and `Auth::isApprover()`
  which don't exist on `Portal\Core\Auth` — fatal on any access. Replaced
  with `App::isAdmin()` and `App::hasRole('Approver')` (the patterns used
  consistently elsewhere).
- **Schema drift — missing app tables** (#184, #185, #186, #187, #191,
  #192, #193, #194) — `web/_sql/full_schema.sql` was missing entire tables
  from 6 migrations even though `tblMigrations` was seeded marking them
  executed. Ported:
  - Migration 018: `siteID` column + FK on `tblRecurrenceRules`
  - Migration 029: `tblAnnouncements` + app routes + settings
  - Migration 030: `tblDocCategories`, `tblDocuments` + routes + settings
  - Migration 031: `tblAuditTrail` + admin/audit route
  - Migration 032: `totpSecret` / `totpEnabled` columns on `tblUsers`,
    `tblTotpBackupCodes` table, 2FA routes + settings
  - Migration 034: `tblWorkflows`, `tblWorkflowSteps`,
    `tblWorkflowInstances`, `tblWorkflowActions` + routes + default
    expense-approval workflow seed
  - Migration 036: `tblTasks` + routes + settings
  - Schema header updated to reflect actual coverage (000-052, was
    claiming 000-036).

### Fixed

- **`full_schema.sql` missing default site seed** (#171) — every
  fresh install since the migrations were consolidated into
  `web/_sql/full_schema.sql` was failing at step 3 with
  `Cannot add or update a child row: a foreign key constraint
  fails (...\`tblEventTypes\`, CONSTRAINT \`fk_etype_site\` FOREIGN KEY
  (\`siteID\`) REFERENCES \`tblSites\`(\`siteID\`))`. A dozen tables
  declare `siteID INT NOT NULL DEFAULT 1` with an FK back to
  `tblSites`, but the consolidated schema had dropped the
  `INSERT INTO tblSites … siteID=1 …` seed that the original
  `015_multisite.sql` migration provided. Restored the seed at the
  top of the data-seed section using the same idempotent
  `INSERT … SELECT … WHERE NOT EXISTS` pattern. This was masked by
  the original HTTP 500 (#169); now that the installer surfaces the
  underlying MySQL message the cause is visible and fixable.
- **Installer HTTP 500 on steps 3 and 4** (#169) — the `install_schema`
  and `create_admin` POST handlers in `web/_install/index.php` ran
  `multi_query` / `prepare` / `execute` without try/catch wrappers.
  Under PHP 8.1+ mysqli defaults to strict-exception mode (and
  `installGetDb()` re-asserts it), so ANY SQL error in
  `full_schema.sql` (DDL conflict, FK ordering, version-specific
  syntax, privilege denied, charset mismatch, …) — or a duplicate-email
  INSERT during admin creation — threw an uncaught
  `mysqli_sql_exception` and fatalled the request with a bare
  HTTP 500. The existing `if ($stmt === false)` / `$db->errno`
  branches were dead code under strict mode. Both handlers are now
  wrapped in `try { … } catch (\mysqli_sql_exception $e) { … }` that
  surfaces the real MySQL message to the user and re-renders the
  current step. The schema-error capture loop now keeps the FIRST
  error message (was overwriting with later "Commands out of sync"
  follow-ups).
- **Installer link colours** (#167) — fixed truncated Bootstrap 5.3.3 CSS
  SRI hash in `web/_install/index.php` that was causing the browser to
  reject the stylesheet, leaving anchors (most visibly the `← Back`
  button on every step) rendered in browser-default blue. Added a
  defensive `a { color: var(--bs-link-color); }` rule so the installer
  stays readable even if the CDN is blocked or the integrity check
  fails again. Added dark-mode overrides for `.alert-info`,
  `.alert-warning`, `.alert-success`, `.alert-danger` to mirror the
  runtime portal (`portal.css` already had them — the installer was
  missing them because it bundles its own standalone CSS). Re-themed
  the "Already Installed" lockout page to use the indigo palette with
  `prefers-color-scheme` awareness.
- **Alert links + code chips, portal-wide** (#167) — added `.alert a`
  and `.alert code` polish to `portal.css` so anchors and inline code
  inside any `.alert-*` box pick up the alert's own tonal foreground
  via `currentColor`, instead of falling through to the global indigo
  link colour (which clashes against alert-warning amber /
  alert-success green).

## [1.0.0] - 2026-05-22 — 🚀 v1.0 launch

A "bumper" release pulling everything needed for the v1.0 launch into one
cohesive ship. Includes every privacy / security / operational item that
was either launch-blocking or worth landing pre-launch, plus the API
surface + admin tooling to support it.

### Added — Operational basics

- **Healthcheck endpoint** at `/health` — JSON status with `db` ping
  (returns 503 + `db: "error"` if the database is unreachable). Designed
  for CloudFlare / Pingdom / GitHub Actions uptime probes.
- **`robots.txt`** + meta-robots fallback. Internal portal by default —
  general search engines + AI training crawlers (GPTBot, ClaudeBot,
  anthropic-ai, Google-Extended, PerplexityBot, CCBot, Bytespider, …) all
  blocked. Two per-site settings (`site.allowIndexing`,
  `site.allowAiIndexing`) for opt-in.
- **Release Notes viewer** at `/admin/release-notes` — renders this
  CHANGELOG with a paranoid mini-Markdown renderer (no script
  execution risk).
- **Audit-log retention sweeper** at `/admin/maintenance/retention` —
  hard-deletes rows from `tblActivityLogs` + `tblErrors` past the
  configured window (default 365 days). Two-mode: web UI for one-off
  cleanup, token-gated cron endpoint for nightly scheduling.

### Added — Security hardening

- **Composite IP + username rate-limit** (#52) — defends single-account
  targeted attacks rotating IPs (which pure IP limiting missed). New
  `RateLimiter::isUserOrIpBlocked()` checks both per-IP (default 5/15min)
  and per-username (default 10/15min) counters.
- **2FA "Remember this device"** cookie — after a successful TOTP
  challenge, users can opt in to a 30-day device-trust cookie (only the
  SHA-256 hash is stored). Revoked on password change for security.
- **Wired up the dormant TOTP gate** — the 2FA verify page existed but
  `$_SESSION['2fa_user_id']` was never set; `Auth::loginLocal()` skipped
  the challenge. Now correctly demotes the session post-password and
  routes through `/auth/2fa/verify` when `totpEnabled = 1`.
- **SVG upload XSS fix** — `calendar/manage/save.php` allowed SVG event
  images which could carry inline scripts. SVG removed from allow-list;
  added 5 MB size cap + `finfo_file()` MIME sniff so the detected type
  must match the claimed extension.

### Added — Privacy & GDPR (#47)

- **`/privacy`** — auto-generated public privacy policy reading
  admin-editable settings (`privacy.controllerName`, `contactEmail`,
  `dataRetentionDays`). Honours `privacy.policyURL` if set (external
  redirect).
- **Cookie consent banner** in footer — Accept / Necessary only / More
  info. Each decision recorded in the new `tblConsentLog` with a
  SHA-256 hash of the policy snapshot at decision time + IP + UA.
- **Data export** at `/account/data-export` — bundles every record about
  the user (profile, local-account, SSO links, passkeys, password-reset
  history, activity logs, expense claims, prayer requests, consent log,
  trusted devices) into a downloadable JSON. Sensitive fields
  (passwordHash, totpSecret, tokenHash) explicitly stripped.
- **Account self-deletion** at `/account/delete` + confirm — two-step
  flow with typed confirmation phrase. Hard-deletes secrets tables and
  anonymises everything else via `ON DELETE SET NULL` FKs. Root admins
  can't self-delete (would lock the umbrella).

### Added — REST API + Documentation

- **Relocated** 5 existing API endpoints from `public_html/api/{app}/{action}.php`
  to `public_html/{app}/api/{action}.php` (the path `ApiRouter::dispatch()`
  actually looks at — they were unreachable before).
- **Events full CRUD** at `/api/events/{list,detail,create,update,delete}`
  as the canonical write-side example (admin-only, CSRF via header or
  body, JSON request/response).
- **List endpoints for previously API-less modules**: leadership,
  tasks, prayer-requests, documents — all visibility-rule-aware
  (tasks own-only for non-admins; prayer-requests masks anonymous
  submitter for non-admin readers).
- **Per-endpoint enable flags** (`api.{module}.{action}.enabled`) so
  admins can selectively disable any endpoint per-site.
- **OpenAPI 3.0 spec** at `/openapi.json` describing every endpoint
  (paths, parameters, request/response schemas, security model).
- **Swagger UI** at `/api-docs` — loads the spec, includes a
  `requestInterceptor` that injects `X-CSRF-TOKEN` on writes so the
  interactive docs drive real authenticated requests against the
  portal.

### Added — Admin tooling

- **Notification preferences UI** at `/account/notifications` — 8
  per-channel switches stored as JSON in `tblUsers.notifyPrefs`.
  Delivery gated by `notifications.deliveryReady` (default false in v1.0);
  the UI explains preferences are saved but emails aren't sent yet.
- **Email template editor** at `/admin/email-templates` — DB-backed
  templates with Mustache-style `{{token}}` substitution. Global
  defaults + per-site overrides. Sandboxed `<iframe srcdoc>` preview
  with sample-token substitution. Seeded with three real templates
  (`auth.passwordReset`, `expenses.statusUpdate`, `expenses.approverNudge`).
- **Bulk import (CSV)** for events (`/calendar/manage/import`) and
  leadership assignments (`/leadership/manage/import`). Two-step
  preview/confirm UX matching `/admin/users/import`. Excel users
  directed to "Save As → CSV".

### Added — CI / DevEx

- **Dependabot** — weekly GitHub Actions version-bump PRs (grouped).
- **CodeQL** scanning JavaScript on every push + weekly schedule.
- **Psalm** PHP static analysis with SARIF upload to GitHub Security
  tab + a `php -l` lint sweep as a hard gate.
- **Existing**: `gitleaks` secret scanning (already in `pr-security.yml`),
  branch-protection ruleset audit (`repo-config-audit.yml`).

### Changed

- **Removed `web/public_html_dev/`** from the repo — never deployed
  anywhere (deploy source is `web/public_html/` for every branch,
  destinations differ). Documented branch-based deploy model in
  `bootstrap.php`, Gatekeeper, Router, README, DEV_NOTES, .claude/CLAUDE.md.
- **CHANGELOG.md now ships in `web/CHANGELOG.md`** at deploy time (copied
  from repo root) so the new Release Notes viewer can find it on the server.
- **Deprecated `curl_close()` calls** removed from `Auth.php` and
  `MailerGoogle.php` (PHP 8+ auto-closes cURL handles).

### Schema

Migrations **044 → 052**:

| # | What |
| --- | --- |
| 044 | `site.allowIndexing` + `site.allowAiIndexing` settings |
| 045 | `auth.rateLimit.maxAttemptsByUsername` (#52) |
| 046 | Audit-log retention settings + admin route |
| 047 | `tblTrustedDevices` + `auth.twoFactor.trustedDeviceDays` |
| 048 | `tblConsentLog` + `privacy.*` settings + 5 new routes |
| 049 | REST API enable-flag seeds + relocated endpoints + Swagger routes |
| 050 | `notifications.deliveryReady` gate + 2 new routes |
| 051 | `tblEmailTemplates` + 3 seed templates + 4 admin routes |
| 052 | Bulk-importer routes (events + leadership) |

### Known follow-ups (issues filed)

- #141 — PWA install prompt + push notifications
- #142–#147 — Operational tweaks (backup verification, Sentry monitoring,
  CSP nonces, composer.json/Dependabot, visual regression tests,
  auto-merge-alpha rollforward)
- #148–#154 — Brand-new app proposals (Pastoral Care, Stewardship /
  Giving, Sabbath School / Small Groups, Communications Hub, Resource
  Booking, Forms Builder, Library / Resources)
- #155 — Plugin / Extension framework
- #156 — Reports builder UI (drag-and-drop)
- #157 — Complete REST API write-side CRUD on remaining modules

## [0.12.0] - 2026-05-22

### Added — Calendar: per-month strap-lines + category display-style toggle (follow-ups to #136)

Two enhancements deferred from the original calendar-views PR (#137):

- **Per-month strap-lines / themes** on the year planner. A new table
  `tblCalendarMonthThemes` stores one text line per (site, year, month)
  that appears under each month name on `/calendar?view=year`. Managed
  via a new admin page at `/calendar/manage/month-themes` (year picker
  with 12 inputs; empty values delete the existing row).
- **`tblEventCategories.color` + `tblEventCategories.displayStyle`**
  columns. Categories can now carry a colour and choose whether that
  colour renders as a **tinted background band** (default — used for
  organisational scopes like "Area 8", "Conference", "Union") or as
  **coloured text** on the default background (used for events like
  Bank Holidays / Notable Days that just want to flag the day, not
  fill the band).
- Category management UI at `/calendar/manage/types` exposes a colour
  picker + display-style dropdown for every category. Existing
  categories get an inline form to update appearance in-place.
- Year-planner legend renders text-style categories as a coloured
  word rather than a swatch + name, mirroring how they'll appear in
  the planner.
- Migration `web/sql/043_calendar_categories_and_month_themes.sql`
  adds the new columns, the new table, and the admin route.

### Added — Calendar multi-view modes (#136)

The calendar app was previously list-view only. It now supports **seven**
view modes that the spec originally called for:

- **Day** — single day, vertical hour timeline.
- **Week** — full 7-day grid (Mon → Sun), hour timeline.
- **Weekdays** — 5-column grid (Mon → Fri).
- **Weekend** — 2-column grid (Sat → Sun).
- **Month** — 7×5/6 calendar grid; up to 3 event pills per day, "+ N more"
  link to the day view for busier days.
- **Year** — 12-column wall-planner grid (months across, days down).
  Cells show day-of-week initial + up to 3 colour-coded category dots.
- **List** — the original card-grid layout, refactored into the new shell.

Implementation:

- `web/public_html/calendar/index.php` is now a thin view router that
  validates `?view=`, resolves the visible date range, fetches events in
  one query, and delegates rendering to a per-view partial under
  `web/public_html/calendar/views/`.
- Day / Week / Weekdays / Weekend share a single hour-timeline renderer
  (`views/_day_columns.php`) parametrised by column count.
- Shared header (`views/_shared_header.php`) provides date navigation,
  view-switcher buttons, filters (category / type / show-past).
- Last-used view persists in `localStorage` (`portal-calendar-view`); a
  new admin setting `calendar.defaultView` controls the first-visit
  landing view (default: `month`).
- Events colour-code by `tblEventCategories.color` via an `--ev-color`
  CSS custom property; falls back to `--portal-primary`.
- Mobile-responsive: hour timelines scroll horizontally; month cells
  shrink; pill names hide below 640px.
- Migration `web/sql/042_calendar_default_view.sql` seeds
  `calendar.defaultView`.

### Fixed — Anchor colour falling back to browser default in dark mode

- `portal.css` now binds `--portal-link` (and its hover / RGB variants) to
  Bootstrap's `--bs-link-color`, `--bs-link-color-rgb`,
  `--bs-link-hover-color`, and `--bs-link-hover-color-rgb` in both the
  light `:root` and the `[data-bs-theme="dark"]` blocks. Every `<a>`,
  `.btn-link`, `.alert-link`, and `.link-*` Bootstrap utility now stays
  on the indigo brand colour instead of the browser-default blue that
  clashed in dark mode (visible on installer Step 1 "Continue →" link).
- `install/index.php` mirrors the same binding in its self-contained
  inline `<style>` block so the installer renders consistently with
  the rest of the portal.

### Changed — Deploy workflow: dry-run dispatch + DEV_NOTES troubleshooting (#107)

- `deploy.yml` now accepts a `dry_run` `workflow_dispatch` input. When
  set to `true`, lftp `--dry-run` is threaded into every `mirror`
  invocation (both per-branch `public_html/` and the `--delete` shared
  upload). The run prints exactly what would be uploaded **and what would
  be deleted** on the server, without making any changes — useful before
  structurally-significant deploys (renamed/moved files in `core/`,
  `vendor/`, `sql/`).
- `DEV_NOTES.md → Troubleshooting` gains a "file disappeared from the
  server after deploy" entry documenting the `--delete` mirror semantics
  and the survival rules for `_auth_keys/` / `_uploads/` / `_backups/`.
- Debug-panel troubleshooting note updated to mention the new prod
  refusal (cross-references #54).

### Security — Debug mode hardening in production (#54)

- `Debug::isEnabled()` and `App::isDebug()` now **unconditionally refuse**
  to enable debug mode when `PORTAL_ENV === 'prod'`, regardless of who is
  signed in or what query parameters are present.
- Attempts to set `?debug=true` in prod are logged once per request
  (`DebugBlocked` activity entry with IP + request path) so probing shows
  up in the activity log.
- `bootstrap.php` now hardens PHP error display: in prod `display_errors`,
  `display_startup_errors`, and `html_errors` are all forced to `0`.
  Errors are still **reported** so they're captured by `Logger::phpError()`
  and surface in the admin error log — they just aren't echoed into the
  response.
- The global exception handler already routed detailed traces through
  `App::isDebug()`; since that now refuses prod, no stack traces / file
  paths can leak in production even on unhandled exceptions.

### Security — Password policy hardening (#53)

- **Default minimum length raised from 8 to 12** characters (configurable
  via `auth.password.minLength`).
- Added `auth.password.maxLength` (default `128` — defends against
  pathological inputs; bcrypt truncates at 72 anyway).
- Added `auth.password.requireLowercase` setting (default `true`). The
  validator previously gated the lowercase check on the `requireUppercase`
  flag — fixed so each case requirement is independent.
- Server-side validation is now enforced on **every** password-set flow:
  - Reset password (`/reset-password`) — already validated; now uses
    the shared `Auth::passwordPolicy()` helper for hints.
  - Account change-password — already validated; same shared helper.
  - **Admin user create/update (`/admin/users`)** — previously unvalidated.
  - **Installer (`/install`)** — previously only checked length ≥ 8.
- New helper `Auth::passwordPolicy()` returns the active policy as a
  structured array (rules list + minLength/maxLength/required flags) so
  forms can render the policy consistently.
- **Client-side strength meter** added to all password-set forms via
  `data-portal-password-input` + `data-portal-password-meter` attributes
  (Bootstrap progress bar, 5-step score). Wired into `portal.js` and a
  self-contained inline copy for the bootstrap-free installer.
- Migration `web/sql/041_password_policy_hardening.sql`.

## [0.11.0] - 2026-05-22

### Added — Multi-provider Captcha

The captcha layer now supports three providers and admin-configurable priority:

- **Providers**: Cloudflare Turnstile (default first), Google reCAPTCHA
  (v2 checkbox or v3 invisible/score), and hCaptcha.
- **Admin UI** at `/admin/captcha` with drag-and-drop priority ordering
  (SortableJS) and per-provider site/secret-key inputs. reCAPTCHA gets
  a v2/v3 toggle plus action name + score-threshold inputs for v3.
- **Priority** is stored as `auth.captcha.priority` (comma-separated keys).
  The active provider is the first in the list with both keys configured;
  if nothing is configured the captcha is silently skipped.
- **reCAPTCHA v3** verification enforces both action match (anti-replay)
  and score threshold; default action `submit`, default threshold `0.5`.
- Migration `web/sql/040_captcha_providers.sql` adds hCaptcha key slots,
  v3 action/threshold settings, priority setting, and admin routes.
- `Captcha::activeProvider()`, `Captcha::listProviders()`, and
  `Captcha::normalisePriority()` are new public helpers used by the UI.

### Added — Prayer Requests app

A new portal app at `/prayer-requests` for collecting and tracking prayer
requests, with built-in moderation and an anonymous public-submission route.

- **Logged-in submissions** with per-request visibility — *leadership only*
  (default) or *congregation feed* (visible to all members of the site).
- **Anonymous "display as Anonymous" toggle** for logged-in submitters —
  members see "Anonymous"; leaders still see who submitted for pastoral
  follow-up.
- **Public anonymous route** at `/prayer-requests/anonymous` (no login).
  Protected by CSRF + Captcha + RateLimiter. Hard-coded to leadership-only
  visibility and `pending` status for moderator review.
- **Lifecycle**: pending → active → answered (with optional praise /
  testimony note) → archived. Moderators see a status-grouped queue and
  per-request quick actions.
- **Per-site settings** seeded with sane defaults — feature toggle,
  anonymous allowed, congregation feed allowed, require moderation,
  allow testimony.
- **Help guide** at `/help/prayer-requests` and a tile on the Help Centre
  landing page.
- Migration `web/sql/039_prayer_requests.sql` + `tblPrayerRequests`
  definition added to `full_schema.sql`.

## [0.10.0] - 2026-05-22

### Added — UI refresh: design system, theme modes, per-site branding

A six-PR sweep across the portal and the standalone installer (#111).
The portal now has a coherent design language with full per-site /
per-user customisation.

- **Design tokens** (#114) — `web/public_html/assets/css/portal.css`
  `:root` block refreshed with a Linear-style indigo palette
  (`#5e6ad2` default), expanded type scale, modern semi-bold (600)
  headings, Stripe-style multi-layer shadows, refined motion tokens
  (cubic-bezier easings), and an extended spacing scale.
- **Per-site branding flow** (#116) — `tblSites.primaryColor` and
  `tblSites.faviconPath` (new column via migration `037_site_favicon.sql`)
  flow into the portal's CSS tokens at render time. `header.php`
  injects `--portal-primary` and `--portal-primary-rgb` as an inline
  style on `<html>`; the hover / active / subtle variants are derived
  with `color-mix()` so the whole indigo family shifts when an admin
  picks a different brand colour. `Site::branding('favicon')` added.
- **Theme modes + colour-blind safe palette** (#117) — three theme
  modes (light / dark / `auto` following `prefers-color-scheme`)
  with a 3-state toggle in the navbar. New CB-safe palette via
  `[data-portal-cb="on"]` swapping the semantic tokens (success /
  danger / warning / accent) for a Wong-derived palette
  distinguishable for deutan + protan colour blindness. The
  standalone installer mirrors all of it inline.
- **Navbar + top chrome polish** (#118) — active nav-link state
  becomes primary-coloured on a primary-subtle background (was just
  font-weight); avatar gains primary-tinted ring on dropdown
  hover/open; dropdown menus use layered shadow + branded item
  hover/active states; mobile collapse gets a top-border separator.
- **Forms / cards / alerts / buttons / data-list** (#119) — every
  Bootstrap class consuming pages already use (`.form-control`,
  `.form-label`, `.form-text`, `.card`, `.alert`, `.btn-success`,
  `.btn-outline-*`, `.form-check-input`, validation classes) now
  reads from the design tokens. `portal-data-list` wrapped in a
  single rounded container with branded row hover. Empty-state and
  badge components use tokens (CB-safe automatically). New auth-shell
  card pattern picks up login + forgot/reset password screens
  without markup changes.
- **Dashboard hero + stat widgets + app card grid** (#120) — new
  greeting hero at the top (time-of-day aware, first-name, site-aware
  subtitle); stat widgets get 4 px brand-coloured left accent + lift
  on hover with token-driven colours (CB-safe-aware); app cards get a
  proper hover treatment (lift + layered shadow + primary border
  accent), inline `<style>` block removed.
- **Final polish + dark-mode audit** (#121) — Bootstrap tables, modals,
  pagination, list-group, progress, spinners, tooltips all now read
  from the design tokens. Breadcrumb uses a typographic `›` separator
  (was `/`). Footer gets an inset top-shadow. Dropzone hover uses
  `--portal-primary-subtle`. Explicit dark-mode overrides for tables,
  modals, progress, and portal-badge variants.

### Added — Installation wizard styling polish (#114 + #115)

- `web/install/index.php` standalone styling rewritten across all six
  step layouts (welcome, DB config, schema, admin, finalize, complete)
  to match the portal's design language. Step indicator badges, card
  shadows, form fields, button-row separators, and alert palettes all
  refreshed inline (the installer runs before `bootstrap.php` so it
  cannot load `portal.css`).
- Same theme + CB toggles in the installer header (inline SVG icons
  so the installer doesn't load Font Awesome). Identical FOUC script
  and `localStorage` keys as the portal.

### Added — Multi-branch SFTP deploy + auto-versioning

- **`alpha` / `beta` / `main` branch model** replacing the single-branch
  FTP deploy. `main` → `public_html/`, `beta` → `public_html_beta/`,
  `alpha` → `public_html_dev/`. Shared `core/`, `vendor/`, `sql/` deploy to
  the same remote base from every branch.
- **`.github/workflows/deploy.yml`** rewritten to SFTP (lftp),
  SSH-key-preferred with password fallback, per-branch target resolution,
  change detection, `[deploy all]` / `[skip ci]` flags, and a
  `vars.SFTP_ENABLED` kill switch. New secrets: `SFTP_HOST`, `SFTP_USER`,
  `SFTP_BASE_PATH`, plus `SFTP_KEY` and/or `SFTP_PASSWORD`.
- **`.github/workflows/version-bump.yml`** auto-bumps `web/core/App.php`
  `$version` on push: alpha = PATCH always; beta = Conventional Commits.
- **`.github/workflows/changelog.yml`** auto-appends CHANGELOG entries from
  commit messages since the last `v*` tag.
- **`.github/workflows/release.yml`** creates a GitHub Release on `v*` tag,
  extracting notes from CHANGELOG.md. Tags with `-beta` / `-rc` are pre-release.
- **`.github/workflows/auto-merge-alpha.yml`** enables GitHub auto-merge on
  PRs whose base is `alpha` and dispatches `deploy.yml` once merged.

### Added — dompdf at build time

- **`tools/download-dompdf.sh`** — idempotent fetch of pinned dompdf v3.1.5.
- `deploy.yml` runs the fetch script before SFTP so dompdf rides along in
  the shared upload; manual server-side install is no longer needed.

### Fixed

- **`web/core/bootstrap.php`** — environment detection now matches the new
  `public_html_beta/` directory name (previously only `beta_html`); ordering
  documented to avoid the `public_html` substring trap that would
  misclassify dev/beta as prod.

---

## [0.8.2] - 2026-03-08

### Added — Installation & Upgrade System (Issue #84)

- **Web-based installation wizard** (`install/index.php`) — 6-step guided setup for first-time installations: prerequisites check, database configuration, schema installation, admin user creation, encryption key generation, finalization
- **Upgrade handler** (`install/upgrade.php`) — admin-only page to detect and run pending SQL migrations with progress display and migration history
- **Auto-detection** — front controller redirects to installer when credentials file is missing
- **Shared hosting support** — graceful error handling when database creation permissions are restricted (prompts user to create DB via hosting panel)
- **Security protections** — lock file prevents re-installation; credentials stored outside web root; restrictive file permissions
- SQL migration `025_install_upgrade_route.sql` — route for admin upgrade page

### Added — Dashboard Widgets (Issue #85)

- **Stat widgets** on dashboard — pending expenses, events this week, active users (admin), 24h activity (admin) with quick-link cards

### Added — Email Digest / Notification Preferences (Issue #86)

- **Notification preferences** on account page — toggles for weekly email digest, expense updates, event reminders
- `notifyPrefs` JSON column on `tblUsers` for per-user preferences
- SQL migration `026_notification_preferences.sql`

### Added — Bulk User Import (Issue #87)

- **CSV import** for users (`admin/users/import`) — preview/validate/confirm workflow with duplicate detection
- SQL migration `027_user_import_route.sql`

### Added — Event RSVP / Registration (Issue #88)

- **RSVP system** for calendar events — Going / Maybe / Not Going with capacity limits
- RSVP card on event detail page with response counts and status display
- `tblEventRSVPs` table with UPSERT pattern for response changes
- `capacity` column on `tblEvents` for optional attendee limits
- SQL migration `028_event_rsvp.sql`

### Added — Announcements / Noticeboard (Issue #89)

- **Announcements module** — listing, detail view, admin CRUD with priority levels (normal/important/urgent), pinning, scheduled publish/expiry
- Pinned announcements displayed on dashboard
- SQL migration `029_announcements.sql`

### Added — File / Document Library (Issue #90)

- **Document library** — category browsing, file upload/download with counter, admin category management, file type icons
- SQL migration `030_document_library.sql`

### Added — Audit Trail Improvements (Issue #91)

- **Logger::audit()** method for before/after change tracking with automatic diff generation
- Admin audit trail viewer with table/action filters and pagination
- SQL migration `031_audit_trail.sql`

### Added — Two-Factor Authentication TOTP (Issue #92)

- **TOTP 2FA** — QR code setup, backup codes, post-login verification challenge, disable option
- Pure PHP RFC 6238 implementation (`Totp.php` core class)
- SQL migration `032_totp_2fa.sql`

### Added — Reporting / Analytics Dashboard (Issue #93)

- **Reports dashboard** — summary cards, expense status breakdown, activity trend chart, JSON data endpoint
- SQL migration `033_reports.sql`

### Added — Configurable Workflow Engine (Issue #94)

- **Workflow engine** — definitions, ordered steps, instance tracking, action logs, admin management UI
- SQL migration `034_workflow_engine.sql`

### Added — REST API Expansion (Issue #95)

- **New API endpoints** — events list/detail, attendance list, users list (admin), announcements list
- SQL migration `035_api_expansion.sql`

### Added — Recurring Task / Reminder System (Issue #96)

- **Tasks app** — task management with priorities, due dates, user assignment, recurring tasks
- Auto-spawns next occurrence when a recurring task is completed
- SQL migration `036_tasks_reminders.sql`

- Updated `full_schema.sql` — covers migrations 000–036

## [0.9.0] - 2026-03-08

### Added — Multi-Site Support (Phase 5, Issue #45)

- **Multi-site architecture** — single installation serving multiple sites/divisions with full data isolation via `siteID` foreign keys on all data tables
- **Three detection modes** (configurable via `multisite.detectionMode`): subdomain (`cambridge.portal.example.com`), path-prefix (`/cambridge/expenses`), session (navbar switcher dropdown)
- **4-tier permission hierarchy**: Umbrella Admin → Site Root Admin → Site Admin → User, with per-site role flags in `tblUserSites`
- **New tables**: `tblSites` (site definitions with branding), `tblUserSites` (user-to-site assignments with admin flags)
- **New core class**: `Site.php` — central site-context manager with detection, branding, user assignment, and URL generation
- **Site-aware bootstrap**: pre-settings site detection, settings query loads global defaults then site-specific overrides
- **Per-site branding**: logo, primary colour, copyright org, timezone per site — reflected in navbar, header meta, footer copyright
- **Site switcher** in navbar (shown when multisite enabled and user has 2+ sites)
- **Admin site management** (`admin/sites`) — create/edit sites, manage user-to-site assignments, toggle admin roles (umbrella admin only)
- **Site switch handler** (`site/switch`) — CSRF-protected POST handler for switching active site
- SQL migration `015_multisite.sql` — tblSites, tblUserSites, siteID columns on 12 tables, seed data, routes

### Changed — Multi-Site Core Updates

- `bootstrap.php` — pre-settings site detection via `Site::preDetect()`, site-aware settings query (`WHERE siteID IS NULL OR siteID = ?`), `Site::init()` after App
- `App.php` — added `siteId()`, `isSiteAdmin()`, `isSiteRootAdmin()`, `isUmbrellaAdmin()`; `user()` JOINs `tblUserSites`; `isAdmin()` uses 4-tier hierarchy
- `Router.php` — `extractPath()` strips site-key prefix in path mode; `url()` prepends site prefix
- `Auth.php` — sets `$_SESSION['active_site_id']` after all login flows; `createUser()` inserts into `tblUserSites`
- `Logger.php` — `activity()` and `errorPlatform()` include `siteID` column
- `nav.php` — site switcher dropdown, per-site logo/name, "Sites" admin link
- `header.php` — per-site theme-color meta tag
- `footer.php` — per-site copyright org
- All expense, calendar, attendance, admin, and settings queries — `AND siteID = ?` filtering
- `full_schema.sql` — consolidated with tblSites, tblUserSites, siteID columns, multisite settings/routes

### Added — JS Graceful Degradation (Issue #49)

- **Global `<noscript>` banner** in `header.php` — informs users when JS is disabled, listing affected features
- **No-JS CSS overrides** — accordion panels expanded, nav dropdowns open on hover/focus-within, Bootstrap collapse sections visible
- **Expense submit fallback** — 5 static line-item rows rendered inside `<noscript>` when JS can't generate dynamic rows; visible file input fallback for dropzone
- **Attendance record note** — contextual `<noscript>` message explaining limited row management
- **Account page passkey note** — `<noscript>` message explaining passkey registration requires JS
- **Settings page** — `<noscript>` style expands all accordion groups; note about search requiring JS
- **Admin modals** — `<noscript>` warnings on user management and site management pages where modal dialogs require JS
- **CSS-only nav dropdowns** — hover/focus-within fallback for navbar dropdown menus
- **Dark mode noscript support** — dark theme variant for noscript banner

### Added — Leadership App (Phase 9, Issue #38)

- **Leadership directory** — card-based view of all roles and current holders, with term dates, vacancy indicators, and summary stats
- **Role management** (`leadership/manage`) — admin CRUD for leadership role definitions with activate/deactivate toggle
- **Role assignment** (`leadership/assign`) — assign portal users or external people to roles, with start/end term dates and notes
- **Historical records** (`leadership/history`) — full audit trail of all assignments (current, past, removed) with role filter
- **17 default SDA church roles** seeded: Pastor, Elder, Deacon/Deaconess, Clerk, Treasurer, SS Superintendent, Youth Leader, etc.
- **Multi-site scoped** — all tables include `siteID` FK; queries filter by `Site::id()`
- **New tables**: `tblLeadershipRoles`, `tblLeadershipAssignments`
- SQL migration `017_leadership.sql` — tables, seed roles, routes, settings

### Added — Google Workspace Email Sending (Issue #48)

- **`MailerGoogle.php`** — Gmail API email backend using service account with domain-wide delegation (RS256 JWT, RFC 2822 MIME, attachments up to 25MB)
- **`Mailer.php` refactored** — multi-provider dispatcher; `mail.provider` setting toggles between `ms365` (default) and `google`
- **Integration Diagnostics** updated — Google email config panel with key file validation, delegate user display, active provider indicator, and test email sending via Gmail API
- **New settings**: `mail.provider`, `mail.google.serviceAccountKeyFile`, `mail.google.delegateUser`
- SQL migration `016_google_mail.sql` — new Google email settings

---

## [0.8.1] - 2026-03-08

### Added

- Event series bulk edit page for calendar management (#75)
- Leadership role transition workflow — auto-end outgoing holders (#76)
- CSV export across 5 apps: expenses, attendance, leadership, admin users, activity logs (#77)
- Input validation framework — Validator class with pipe-separated rules (#78)
- Transaction helpers — App::beginTransaction/commit/rollback (#79)
- Lightweight DI container — Container class alongside existing statics (#81)
- **Integration Diagnostics** (`admin/integrations/index.php`, Issue #46) — admin-only page to test MS365 OAuth login configuration, Graph API token acquisition (client-credentials flow), test email sending from shared mailbox via SendAs/delegate, and Google OAuth configuration status. Includes pass/fail badges, Azure AD permissions reference, and CSRF-protected forms.
- SQL migration `014_admin_integrations_route.sql` — adds `admin/integrations` protected route
- Integrations quick-link on Admin Dashboard

### Changed

- Separated API routing into dedicated ApiRouter class (#80)
- Standardized error handling — flash+redirect for all CSRF/OAuth errors (#82)

### Fixed

- Version seed in full_schema.sql and App.php now shows 0.8.1 (#83)

### Security

- All codebase passes lint with zero syntax errors
- No SQL injection, die(), or bare exit patterns remaining

---

## [0.8.0] - 2026-03-07

### Added - Polish & Hardening (Phase 9, Issues #35–#37)

- **PWA Support** (`manifest.json`, `sw.js`, `offline/index.php`) — web app manifest with standalone display, service worker with cache-first for static assets and network-first for HTML, offline fallback page with retry, SVG PWA icons (192 & 512)
- **WCAG 2.1 Accessibility** — skip-to-main-content link with focus-visible styling (WCAG 2.4.1), enhanced keyboard focus indicators (`*:focus-visible` with `outline`), ARIA `aria-live` regions on login alerts for screen readers, `aria-hidden="true"` on decorative Font Awesome icons, `role="main"` and `id="main-content"` on `<main>` element
- **Security Hardening** — Content-Security-Policy header (restricts scripts, styles, fonts, frames to known CDNs and self), Permissions-Policy header (blocks camera, microphone, geolocation), existing open redirect protections and CSRF coverage verified across all endpoints

### Changed — Polish & Hardening

- `portal.css` — added section 18 (Accessibility) with skip-link and focus-visible styles, renumbered RTL to 19 and Print to 20
- `header.php` — added CSP and Permissions-Policy security headers
- `login/index.php` — added `aria-live`, `aria-hidden` attributes for accessibility

---

## [0.7.0] - 2026-03-07

### Added - Translations / i18n (Phase 8, Issues #42–#44)

- **I18n Framework** (`core/I18n.php`) — translation class with `t('key')` helper, parameterised strings (`:name`), pluralisation (`singular|plural` and `zero|one|many`), fallback chain (user locale → default locale → raw key), Accept-Language browser auto-detection, per-user locale persistence in `tblUsers.locale`
- **Language Switcher** — dropdown in navbar (both authenticated and unauthenticated views) using `?lang=` query parameter with redirect, stores preference in session and database
- **English Baseline** (`lang/en.php`) — comprehensive translation file covering nav, auth, dashboard, expenses, calendar, attendance, admin, settings, help, errors, common UI, email, and date/number formatting
- **Welsh Proof-of-Concept** (`lang/cy.php`) — demonstrates multi-language support with nav, auth, error, and common UI translations
- **RTL Layout Support** — `dir="rtl"` on `<html>` tag when RTL locale active, Bootstrap RTL CSS variant (`bootstrap.rtl.min.css`) loaded conditionally via `Asset::bootstrapCss(true)`, CSS logical property overrides in `portal.css` for margin/text-alignment mirroring
- **Date/Time/Number/Currency Formatting** — `I18n::formatDate()`, `I18n::formatDateTime()`, `I18n::formatNumber()`, `I18n::formatCurrency()` with per-locale format strings from translation files
- **Template Integration** — header, footer, nav, and all three error pages (403, 404, 500) updated with `t()` calls; login page fully translated with all error/success messages using translation keys
- **Bootstrap Integration** — `bootstrap.php` initialises I18n, handles `?lang=` switcher with redirect, loads user locale from DB into session, defines global `t()` helper function
- **SQL Migration** (`012_i18n_phase8.sql`) — adds `locale` column to `tblUsers`, adds `i18n.defaultLocale` and `i18n.enabled` settings
- **Locale Metadata** — 13 locales defined with name, native name, and LTR/RTL direction (en, cy, fr, de, es, pt, ar, he, fa, ur, zh, ja, ko)

---

## [0.6.0] - 2026-03-07

### Added - SSO & Auth Enhancement (Phase 7, Issues #32–#34)

- **Google Workspace OAuth** (`core/Auth.php`) — full OAuth2 flow with JWKS JWT verification via Google's discovery endpoint, hosted domain restriction, profile sync (name, avatar), auto-link by email match, conditional login button
- **WebAuthn / PassKeys** (`core/WebAuthn.php`) — server-side WebAuthn implementation with no external dependencies: CBOR decoder, COSE-to-PEM key conversion (ES256 + RS256), registration (attestation) and authentication (assertion) flows, sign count tracking for clone detection
- **Account Linking** (`core/Auth.php`) — `linkAccount()`, `unlinkAccount()`, `getLinkedAccounts()`, `countLoginMethods()` methods; safety check prevents unlinking last login method; auto-link on OAuth login by email match
- **Login Page** (`auth/login/index.php`) — conditional Google sign-in button, passkey sign-in button with WebAuthn browser API integration, discoverable credential support
- **Account Page** (`auth/account/index.php`) — linked accounts card showing all providers with unlink buttons, passkeys card with registration modal and delete, account type badges, provider icons
- **WebAuthn Login Endpoint** (`auth/login/webauthn.php`) — AJAX API for passkey authentication with credential lookup, signature verification, session creation
- **Account Endpoints** — `auth/account/webauthn.php` (registration options + verify), `auth/account/webauthn-delete.php`, `auth/account/unlink.php`
- **MS365 OAuth Updated** — refactored to use account linking system (`tblLinkedAccounts`), backward-compatible with existing logins via email-based auto-linking
- **SQL Migration** (`011_auth_phase7.sql`) — creates `tblLinkedAccounts` and `tblWebAuthnCredentials` tables, adds Google hosted domain and WebAuthn RP settings, registers account management routes
- **Settings** — `auth.google.hostedDomain`, `auth.webauthn.rpName`, `auth.webauthn.rpID`
- **Routes** — `account/linked-accounts`, `account/unlink`, `account/webauthn`, `account/webauthn/delete`; special routes: `login/google`, `login/google/callback`, `login/webauthn`

---

## [0.5.0] - 2026-03-07

### Added - Expenses Completion (Phase 6)

- **Multi-Approver Workflow** (`expenses/approve/save.php`) — dept-based approver authorisation using `tblUserDepts` roles (dept lead, mandatory approver, dept approver, admin), rejection by any approver immediately rejects, all mandatory approvers must approve for final approval
- **Claim Detail Page** (`expenses/view/index.php`) — comprehensive view showing claim header, summary cards, line items, evidence files with stage badges, approval history with roles/decisions/comments, payment records, PDF download section, and context-aware action buttons
- **Email Notifications** (`core/ExpenseMailer.php`) — HTML email notifications via Microsoft Graph at each workflow stage (submitted → approvers, approved/rejected → claimant, reimbursed → claimant + approvers), with PDF attachment, graceful fallback if mail unconfigured
- **Enhanced PDF Generation** (`core/ExpensePdf.php`) — PDFs now include approval history (approver names, roles, decisions, dates) and payment records alongside line items; file versioning via `stage` column in `tblExpenseClaimFiles`
- **Treasury Improvements** (`expenses/treasury/save.php`) — proper flash messages, CSRF validation with redirect, claim status verification, `paidByID` tracking, email notification on reimbursement
- **Submit Improvements** (`expenses/submit/save.php`) — email notification to dept approvers on submission, flash messages instead of bare `exit()` calls
- **SQL Migration** (`010_expenses_phase6.sql`) — adds approval threshold/treasury/follow-up/email settings, `stage` column to files table, `approverRole` to approvals table, claim view route
- **Settings** — `expenses.approvalThreshold`, `expenses.requireTreasuryApproval`, `expenses.followUpDays`, `expenses.emailNotifications`

---

## [0.4.0] - 2026-03-07

### Added - Attendance Tracker App (Phase 5)

- **Attendance Session Recording** (`attendance/record.php`) — form for recording headcounts by service type and date, with dynamic group breakdown (Adults, Children, Visitors, etc.) and running total calculation
- **Attendance Dashboard** (`attendance/index.php`) — lists recent sessions with headcount totals, monthly stats cards, filters by service type and date range, pagination
- **Service Type Management** (`attendance/manage/`) — admin UI for viewing, creating, and activating/deactivating attendance service types with hierarchical parent/child structure
- **Attendance Reports** (`attendance/report.php`) — yearly and monthly breakdown views with totals by service type and headcount group, average-per-session calculations
- **SDA Church Service Types** seeded — Sabbath School (with 10 children's divisions: Babies through Baptismal Class), Family Worship, Afternoon Service, Prayer Meeting, Bible Study, Youth Programme, Special Event
- **Database Tables** — `tblAttendanceServiceTypes` (hierarchical service types), `tblAttendanceSessions` (sessions with optional event link), `tblAttendanceCounts` (headcount breakdowns per session)
- **SQL Migration** (`009_attendance_schema.sql`) — creates tables, seeds service types, registers routes, enables app in settings
- **Settings** — `attendance.enabled`, `attendance.displayName`, `attendance.displayIcon`, `attendance.brandColor` for dashboard and nav integration
- **Full Schema** (`full_schema.sql`) updated with attendance tables, routes, settings, and migration tracking

---

## [0.3.0] - 2026-03-06

### Changed - Directory Restructure (Phase 2.5)

- **Consolidated all deployable files under `web/`** — `core/`, `vendor/`, `sql/` now live inside `web/` alongside `public_html/`, matching the ProjectBrief server structure
- **App controllers inside web root** — app PHP files live in `web/public_html/{app}/` (e.g. `public_html/expenses/`, `public_html/auth/`) as specified by the ProjectBrief
- **Deploy workflow** updated to sync only `web/` to the server (was syncing entire repo root)
- **Added missing directories** from ProjectBrief: `_includes/`, `_functions/`, `_libraries/` with `.gitkeep` files
- **Updated `.gitignore`** for new `web/` prefixed paths

### Fixed — Directory Restructure

- **`Pdf.php`** — dompdf `require_once` at class load time caused fatal error when dompdf wasn't installed; now loads conditionally inside `create()` with graceful error logging
- **`Logger.php`** — `bind_param` type string `'sssssssiss'` had `i` at wrong position (8th param for `$ua` string); corrected to `'sssssissss'` (6th param for `$userId` int)
- **`settings/save.php`** — `dirname(__DIR__, 3)` resolved to wrong directory after restructure; corrected to `dirname(__DIR__, 2)`
- **Git case sensitivity** — removed duplicate `ProjectBrief_chat.claude` (lowercase) from tracking; only `ProjectBrief_Chat.claude` tracked

---

## [0.2.0] - 2026-03-06

### Added - Local Auth Enhancement (Phase 2)

- **Forgot Password** flow (`apps/auth/forgot-password/`) - email input, rate-limited token generation, timing-safe enumeration prevention, graceful Mailer fallback
- **Reset Password** flow (`apps/auth/reset-password/`) - token validation, password policy enforcement, rate limiting, token invalidation after use
- **Account/Profile** page (`apps/auth/account/`) - edit profile (name, email, phone), change password with policy validation, view roles and last login
- **Password Policy** engine (`Auth::validatePassword()`) - configurable min length, uppercase, number, special char requirements via tblSettings
- **MS365 Conditional UI** (`Auth::isMS365Configured()`) - login page shows MS365 button only when OAuth is configured
- **Consolidated Schema** (`sql/full_schema.sql`) - single-file schema for fresh installs with safe `IF NOT EXISTS` / `ON DUPLICATE KEY` semantics
- **Migration 006** (`sql/006_local_auth_enhancement.sql`) - tblPasswordResets, password policy settings, auth routes

### Changed — Local Auth

- **Login page** (`apps/auth/login/index.php`) - redesigned with local login as primary, MS365 conditional
- **Auth::loginLocal()** - fixed to query `tblLocalAccounts JOIN tblUsers` (was incorrectly querying tblUsers for passwordHash)
- **Nav dropdown** - added "My Account" link
- **Gatekeeper** - added forgot-password and reset-password to OPEN_PATHS

### Security - Full Codebase Audit (Issue #14)

- **Open Redirect** fixed in `Auth.php` and `login/index.php` - all `$_GET['redirect']` values now validated
- **Broken Authorization** fixed in `settings/save.php` - operator precedence bug allowed any user to edit settings; now requires `App::isAdmin()`
- **DOM XSS** eliminated in `approve/index.php` and `treasury/index.php` - `innerHTML` replaced with safe DOM API (`textContent`)
- **File Upload Validation** added to `submit/save.php` - extension allowlist, 10MB size limit, server-side MIME detection
- **Server-side Total** - expense total now recalculated server-side instead of trusting client hidden field
- **Gatekeeper bind_param bug** fixed - `$types` variable was defined but never used in dynamic query binding
- **Session Data Logging** - sensitive keys (CSRF, OAuth state) now stripped before serializing to activity logs
- **SSRF Prevention** - dompdf `isRemoteEnabled` set to `false`
- **Role Authorization** added to `approve/save.php` (Approver) and `treasury/save.php` (Treasurer)
- **Strict Comparisons** - all `==` changed to `===` in `App.php`, `Router.php`, `Gatekeeper.php`, `settings/index.php`
- **Timing-safe OAuth** - state comparison now uses `hash_equals()`
- **SSL Verification** explicitly enabled on all cURL calls (`Auth.php`, `Mailer.php`)
- **Rate Limiting** added to `reset-password/save.php`
- **htmlspecialchars Charset** - all calls now include `'UTF-8'` parameter
- **Timezone Validation** - validated against `timezone_identifiers_list()` before setting
- **Mailer Reformatted** - full code style compliance with SSL verification

---

## [0.1.0] - 2025-present

### Added - Initial Build (Phase 1)

#### Core Framework
- **Router** (`core/Router.php`) - Front-controller dispatcher with clean URL routing via tblRoutes, hardcoded special routes (login, logout, MS365 OAuth, health check, API), and error page rendering
- **App Registry** (`core/App.php`) - Static service registry replacing `global $mysqli, $SETTINGS` pattern. Methods: `db()`, `settings()`, `user()`, `isDebug()`, `version()`, `env()`, `hasRole()`, `isAdmin()`, `isRootAdmin()`
- **Asset Loader** (`core/Asset.php`) - CDN-with-fallback asset loading for Bootstrap 5.3.3, Font Awesome 6.5.1, with SRI integrity checks and onerror fallback handlers
- **Avatar System** (`core/Avatar.php`) - Avatar cascade: MS365 URL -> local file -> Gravatar -> placeholder SVG
- **Debug Panel** (`core/Debug.php`) - Admin-only diagnostic overlay showing page load time, peak memory, PHP version, DB queries, session data (activated via `?debug=true`)
- **Captcha Helper** (`core/Captcha.php`) - Centralised CloudFlare Turnstile / reCAPTCHA support replacing duplicated loose functions
- **API Response** (`core/ApiResponse.php`) - Standardised JSON API response builder with consistent envelope format
- **Rate Limiter** (`core/RateLimiter.php`) - Database-backed login rate limiting via tblActivityLogs
- **Migrator** (`core/Migrator.php`) - Web-based SQL migration runner for environments without CLI access

#### Authentication
- RS256 JWT verification (`vendor/simplejwt/JWT.php`) using JWKS key fetching, ASN.1 DER key conversion, and standard claim validation
- Session hardening: HttpOnly, Secure, SameSite=Lax cookie parameters
- Session fixation prevention via `session_regenerate_id(true)` after login
- CSRF token rotation after successful verification
- Local account authentication with bcrypt password verification
- Rate limiting integration on login attempts

#### Template System
- Shared header/footer templates eliminating boilerplate duplication
- Responsive navbar with dynamic app links, user avatar dropdown, dark mode toggle
- Breadcrumb support
- Error pages: 404, 403, 500

#### Design System
- Custom CSS (`portal.css`) with CSS custom properties for theming
- Dark mode support via `[data-bs-theme="dark"]`
- `portal-data-list` / `portal-data-row` responsive table replacement
- Status badges, avatar component, file dropzone, empty state
- WCAG-compliant focus indicators
- Print styles

#### JavaScript
- Dark mode toggle with localStorage persistence
- AJAX helper with CSRF token support
- Toast notification system
- File dropzone drag-and-drop feedback

#### Infrastructure
- `.htaccess` URL rewriting for 3 deployment channels (public, alpha, beta)
- SQL migration system with 5 initial migrations (000-004)
- Health check endpoint (`/health`) for CI/CD monitoring
- API routing: `api/{app}/{action}` pattern

#### API Endpoints
- `GET /api/expenses/list` - Paginated expense claim listing with status filtering

### Changed - Core Framework Refactor

- `core/Auth.php` - `ensureSession()` made public, `curlPost()` made public, added JWT verification via JWKS, added local login, improved logout with proper cookie deletion
- `core/bootstrap.php` - Integrated App registry, Debug timer, SimpleJWT autoloader, improved error handlers
- All app files refactored to use template system

### Fixed - Initial Release Issues

- Filesystem case-sensitivity bugs (`Core/` -> `core/`, `logger.php` -> `Logger.php`) that would break on Linux
- `vendor/simplejwt/JWT.php` was a copy of Auth.php - replaced with real JWT library
- Inline DDL (`CREATE TABLE IF NOT EXISTS`) in save handlers moved to proper migrations
- Duplicate captcha functions consolidated into Captcha class
