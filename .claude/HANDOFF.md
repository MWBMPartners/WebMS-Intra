# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 5 November 2026.*

## Branch

`claude/alpha-wip` at **COMMIT_SHA**, pushed. Working tree clean.

**Standing approval (owner, 2 November 2026) to push this working branch after
each commit.** Never a release or protected branch, never a force push, and no
pull request until the Codex review is done (D-035, D-007).

## Just landed

**#763 — a member's saved interface language is applied now (migration 251,
D-060, D-061).** It was applied NOWHERE, on every request, since March 2026 —
and nothing was broken. `I18n::resolveLocale()`'s first two sources sit behind
`session_status() === PHP_SESSION_ACTIVE`; `init()` runs inside bootstrap and
the session starts in `index.php` AFTER it, so both were skipped every time and
`init()` latches so nothing asked again. **No condition was wrong and nothing
was unreachable in the ordinary sense: the question was asked too early.**
`php -l` could not see it and no audit script could.

**The switcher half was worse.** `switchLocale()` stores only with a session
active and its ONE caller sat in bootstrap, so `?lang=cy` died in its own
redirect — and it is the only thing that has ever written `tblUsers.locale`.

**Which made the fix dangerous.** The column was `DEFAULT 'en'`, so every row
held `'en'` put there by the database. Obeying it would have pinned every
signed-in member to English. **A default and a deliberate choice are
indistinguishable once stored, and the moment a column starts being obeyed is
when that stops being harmless.**

**The independent check found TWELVE problems, none in the fix, and the worst
two were claims I had put to the owner.** I said there was no control for
choosing a language anywhere in the portal; there is a globe menu in the top
bar of EVERY page (`grep -rn languageSwitcher web tools`). The owner declined
to build a picker on that sentence — D-061 — so **D-062 supersedes it** and the
account page now has a control too, with "Follow my browser". And "nothing
anybody sees changes" was untrue twice: the menu starts working, and the
Translate button starts really translating and **charging**, because it reads
the same column and falls back to the browser. The owner accepted that
deliberately (D-063). **Three of the first five plants also got past the hard
gate** — `if (false)`, the argument dropped (legal PHP, restores half the
fault), and `&& false` on the condition.

**Round 2 then found seven more, six in round 1's fixes, and the headline is one
sentence: I closed the spellings I was handed.** The gate was taught to ask
whether the brackets held anything, so `reconsiderNowSessionExists(null)` passed
it — one word, the same harm, while the check printed "the reconsider call is
passed a connection". Five whole statements are pinned now. **And the worst live
bug was the control I had just built: it deleted things.** The select listed
`enabledLocales()` (two languages on a stock install) while a choice is accepted
from a hard-coded thirteen, so a stored `ar` matched no option and nothing
carried `selected` — and a `<select>` with no `selected` option is a vote for
option one, which here means "forget my choice". Pressing Save to correct a
telephone number wiped the member's language.

**#768 (before it) — one place writes the form token, every carrier (D-050).**
Five copies, no two agreeing how many carriers a page has, and the shared one
was the least complete. Six check rounds, 6+4+5+4+6+4 findings, NONE in the
original build. `tools/csrf-carriers-selftest.mjs` drives a real browser.

## In flight

**#777 part one: rounds 3 to 6 are done; round 7's check is next.** The rounds so far found, in order: a display fault in four
pages (round 1, the build); a WRITE fault storing the wrong hour plus two API
faults refusing valid input (round 2); three READER faults my own write fix made
visible, and the fact that those three readers were guarded by nothing (round
3). **The pattern this repository keeps recording held again — every round after
the first found its faults in the PREVIOUS round's fix, not in the build.**

Round 3's answer was to stop listing column names in the check and make the
SCHEMA say which kind of value a column holds (migration 252), so the check
reads the declaration. Doing that immediately found **four columns that had
declared themselves wall-clock since before this work, and 28 files treating
them as moments** — listed in
`tools/audit-checks/clock_wall_clock_datetime_pending.txt`, failing both ways,
and the bulk of part two. #783 was raised rather than fixed: two queries compare
a wall-clock column with `NOW()`, the database server's own clock.

**Round 3 reported nine findings and six were lost to a context break** before
reaching a file. Round 4 ran on the current state instead of guessing at them,
and found **eight — every one in a previous round's fix, and two of them guards
that passed on broken code.** The backlog list exempted the FILE rather than the
column, so part one's own rule was switched off in 28 of 912 files; and nothing
pinned WHICH columns declare themselves wall-clock, so rewording two comments
took the set from six to four with a real fault live and every check green. Both
are fixed and both plants now go red. The gate was also REFUSING the constructor
spelling of the correct construction, which is what made its own "28 files"
count wrong — it is 25, with two more the check cannot see, named in the list's
header. #784 raised: two helpers now turn a wall-clock reading into words and
part two needs one answer before it converts 27 files.

**Round 5 found SIX, and the first is the worst thing in the package: a form
open to the PUBLIC was still storing the moved hour.** `calendar/submit-save.php`
turned a `datetime-local` value into a moment and back before the INSERT, so an
event submitted for 01:30 on the spring-forward morning was stored as 02:30 FOR
EVER — nothing cancels it, unlike the reader faults. **Four rounds missed it,
and it was found by reading the FORM, not by any check**, because this gate
matches a column being READ and a write path reads no column at all. That class
is #785. Also: an explicit `null` zone defeated round 4's new rule in one word
(`new DateTimeImmutable($clock, null)` is PHP's default zone, and the gate
counted the comma rather than reading the argument); and round 4's per-column
exemption forgave all SIX declared wall-clock columns where three places said
FOUR, so a `startAt` fault in any listed file was green. All six fixed, each
planted both ways.

**Round 6 found NINE and the first inverts the package's own rule: the events
API had the same fault WITH THE CORRECT ZONE PASSED, and my own gate declared
it correct.** `new DateTimeImmutable($reading, $theRightZone)` still moves the
skipped hour — building a moment AT ALL is the fault, so "we passed the right
zone" is no defence. Round 4's date-and-zone exemption is sound for a READ and
wrong for a WRITE, and nothing in the check knew the difference: a check that
passed on broken code, in the round that added it. Both endpoints are seeded
live. A THIRD write path (`calendar/manage/import.php`) was on the part-two
backlog, which describes its entries as DISPLAY work — so stored-data
corruption was queued behind cosmetic work. **The rule now lives in ONE place**
(`I18n::wallClockFromInput()` / `inputCarriesOffset()`), because the four fixes
started as four copies. Round 5's fix was pinned by nothing (section I of the
clock test, 20 checks, now drives the real methods); `--repin` still wrote a
wrong pin on an equal-sized SWAP; the public form's EIGHT error codes were
never shown to anybody; and `submit-save.php` stored every submitted venue as
`'0'` from a one-character `bind_param` fault predating this work. #785's
specification was wrong in all three parts and is re-measured on the issue.
**Part two is NOT cosmetic: 24 listed files now disagree visibly with correct
data, the moderation screen among them.**

#763 and #781 are DONE (D-064, D-065) — the protected arm of D-051, since they
write and delete stored data, so rounds ran until one was clean. Twelve
findings, then seven, six, six, three, three, six — and **every one after the
first round was in a previous round's fix, all of them in the GUARD rather than
the fix.** Round 7 stopped hand-parsing PHP and asked PHP, which ended the whole
bypass class; round 8's six were the probe's own remaining faults, three of them
CRY-WOLF on ordinary edits. **#781 is the behavioural test that should have been
built instead of inferring reachability statically**, and the one remaining hole
(`while (true) { }` above a region) is the halting problem, so it is #781's to
close, not a parser's.

## Next, in this order

1. **#777 part TWO** — the bulk of the reading half, and round 3 handed it a
   starting point it did not have before. Part one shipped: four display sites
   fixed, a write path that stored the wrong hour, two API validators,
   three readers, `I18n::formatClockTime()` and `formatClockDateTime()` as the
   two formatters, and a hard gate that reads the schema. **What remains is
   measured: 486 time-shaped columns and only 125 with any comment**, so "does
   this column hold a moment or a clock reading" is unanswered for three
   quarters of them — and **28 named files are already waiting**, in
   `tools/audit-checks/clock_wall_clock_datetime_pending.txt`, for four columns
   that DO say. Start there: the list is concrete, each entry needs a person
   (an event's zone is `tblEvents.timezone`, not the organisation's), and
   emptying it is what lets `--strict` go green. #783 belongs with it. ~2–3.
2. **#775** — every scheduled job records that it ran. ~1. On a fresh install
   all eleven are inert, the personal-data sweep among them.
3. **#778** — an outside service is permitted in three tiers, strictest wins
   (D-058). ~2. **The gravatar leak is still live**: an MD5 of an e-mail address
   is not anonymous, and the portal sends one to a third party per avatar.
4. **#762** — translation counts calls as well as money (D-059). ~1.
5. **#493** — portal content on the public website, IN FULL (D-052, D-056,
   D-057). ~4–6, the largest item, a new surface reachable without signing in.
6. **#569** — the page findings left: `/api-docs` (a script error, no `<h1>`, no
   description), `/help/admin` (391px overflow at 320px), `/assets/kiosk` (no
   description). ~1–2.
7. **The whole backlog — 62 medium, 52 low, 8 unlabelled. ALL of it (D-055).**
   No triage. ~36–46.
8. **#754** — the six workflows GitHub has never registered. **Last, D-054**,
   because its only blocker is the pull request.
9. The comprehensive Codex review (D-007), then the merge.

**Done since the last handoff:** #477 (the check-my-portal page, migration 249),
#500 and #499 (both closed as already built, with the 340/340 package self-test
as the evidence), #549's arithmetic half, #767, #765, #768, #763.

**#225 (mobile) is DEFERRED — D-053**, labelled, with the cost recorded on it.

## Blocked on the owner

| What | Why |
|---|---|
| `docker volume rm cfbd1277…` (3 Nov) and `f4c7e62c…` (30 Oct) | The permission classifier refuses it, correctly. Both pre-existing; 2 before and 2 after every package this run. |
| SIGNula.id | Its archive is still in that repository's history (removing it needs a force-push), and it has the same eight-writer password fault #728 fixed here. |

## Six standing facts a reader needs

- **A new script in `tools/audit-checks/` is NOT wired until `pr-security.yml`
  NAMES it.** That file lists every script individually; no workflow has a
  folder glob. The pre-push hook DOES glob the folder, which is what misled me.
  `for f in tools/audit-checks/check_*.py; do grep -rqs "$(basename "$f")" .github/workflows/ || echo "NOT WIRED: $f"; done`
- **Six workflows have never run on GitHub, and two CANNOT** (#754).
  `package.yml`, which builds the customer's zip, first runs at the merge.
  **`pr-security.yml` IS registered**, so put anything that must really run there.
- **A step's script carrying a `${{ ... }}` is capped at 21,000 characters**
  (#745). Over it GitHub refuses the whole file and starts no job while every
  local parser stays green. The tell is the run's `name` equalling its `path`.
- **A `pgrep -f "<name>"` watchdog matches its OWN command line** and waits for
  ever. Six did, for 25 hours, on 4–5 November. Use `tools/watchdog.sh`, which
  has a `pid` mode and matches no process name.
- **MySQL 8.0 and MariaDB 10.6 stay ACCEPTED with a warning** (D-019, D-038).
- **Do not turn `php-static-analysis.yml` into a matrix** — it would rename the
  check `main` requires and soft-lock every pull request.

## The lesson this run earned, in one line

**Where the property is "this code RUNS", drive it; where it is "this code LOOKS
like this", pin it.** #763's fix was correct from its first review. Its guard
then took **eight review rounds and 52 findings, every one in the guard and none
in the fix**, because I had chosen a source-reading check to answer a question
about behaviour and then spent six rounds making the wrong instrument sharper.
The owner's call (D-065) cut it from ~1,235 lines to 521 and built the HTTP test
that measures the thing directly — 24 checks, two of them driven by nothing else,
one of them a plant that proves the test can fail. **Protection went up and the
line count went down.**

**And the runner-up, which is how to notice it sooner (D-064):** judge each
review round by where its findings landed. Eight rounds on scaffolding is a
signal about the instrument, not a reason to sharpen it again.
Six rounds of regular expressions over PHP were defeated by `$endif`, a `}`
inside a heredoc, a `#[Attribute]`, a `'?>'` in a string, and PHP inside an HTML
comment — plus one cry-wolf, `$exit` read as an exit. None of those distinctions
is in the text; they are all in the grammar. `token_get_all()` ended the class
in one change, and the file written to do it broke itself on the same quirk
(a closing PHP tag inside a `//` comment ends PHP mode), which is the best
argument for it there is.

**And the bigger one, which cost the six rounds:** the property being guarded
was "does this code RUN", and that is behavioural. A static pin can only infer
it. #781 is the HTTP test that measures it. **Where the property is "it runs",
drive it; where it is "it looks like this", pin it.**

**Runner-up, from round 5:** when a rule keeps being defeated, the rule is
asking the wrong question. Four
versions asked about the CHARACTER before a pinned region; the fifth checker said
why none could work — nothing about one character can tell "a fresh statement at
the level this belongs to" from "a fresh statement inside a dead block". The
question was always NESTING. Pinning three numbers (open braces, open
alternative-syntax blocks, returns at the region's own level) caught all nine
spellings, including five nobody handed me.

**And its corollary, which is where the four wasted rounds went:** an allow-list
written from the spellings you were handed is not a rule. It
is the same mistake four times over, each time one level up: I answered
`if (false) { }` with a brace count, "the argument dropped" with "are the
brackets empty", and then — having finally pinned the regions as text — wrote a
"begins a fresh statement" rule whose allowed characters included the two that
let `if (false):` and `function neverCalled() {` wrap a whole region in dead
code. **Before writing a rule, try the two spellings nobody handed you.**

**And the runner-up, which is the one to reach for first:** when a guard has
been bypassed three times, stop writing patterns and pin the text. Round 1 handed me `if (false) { }`, so I answered with a brace count;
round 2 handed me "the argument dropped", so I answered "are the brackets
empty"; each answer closed one spelling of "the statement is still there and
does nothing" and the next round found another. #485 had already learnt this and
written it down. Three whole regions are pinned now, with `--repin` to change
them deliberately — and the one hole a text pin has is worth carrying forward:
**it cannot see something glued in front of its first statement**, so a pinned
region must begin a fresh statement.

**Runner-up:** closing the spelling you were handed is not closing the class, and
**writing a correction is not the same as applying it to the thing you are
writing** — D-060 was created in the commit that corrected its own two
falsehoods everywhere else. Both are old lessons here (#485 took four rounds for
the first), re-made inside a single package.

**Runner-up, and still worth its own line:** a claim that something does not
exist ANYWHERE needs the search that establishes it — exactly as a claim about history needs its `git log`.** This
repository already has that rule, written for the past tense ("a claim that
something never worked must carry the command that proved it"), and I broke it
in the present tense: "there is no control anywhere for choosing a language"
went into a question to the owner, a decision record and seven documents, and
one `grep -rn languageSwitcher web tools` disproved it. The owner then declined
to build something on the strength of it.

**The runner-up, which is also real:** a default value and a deliberate choice
are indistinguishable once stored, and the moment a column starts being obeyed
is the moment that stops being harmless. **And the corollary the check found:**
ask of any column you are about to empty who ELSE reads it and what their
fallback does — the Translate button's did, and it costs money. `tblUsers.locale` was `DEFAULT 'en'` and nothing could write it, so
every row said English. Simply honouring the column — the obvious fix — would
have taken a French portal away from everybody using one, with no page
explaining why. The fix was safe only because `git log -S"switchLocale" -- web/`
proved no choice could ever have been stored.

**And the runner-up, from #768:** a published helper is a single source of truth
only while it is the BEST copy.

## Decisions in force — POINTERS ONLY

**Every decision lives once, in `docs/decisions/`, one file each** (D-027). This
list names them; it never restates them. **63 on file.**

| | Decision |
|---|---|
| **D-063** | Translation may start charging on upgrade day, and the changelog says so |
| **D-062** | The language menu exists, and the account page needs one too |
| **D-061** | The language picker is its own piece of work — SUPERSEDED by D-062 |
| **D-060** | An untouched stored language counts as no choice |
| **D-059** | Translation counts calls as well as money |
| **D-058** | An outside service is permitted in three tiers |
| **D-057** | Public content is opt-in, one item at a time |
| **D-056** | Portal content reaches a public website all three ways |
| **D-055** | The whole backlog is cleared before the first release |
| **D-054** | A blocked item moves to the end of the queue |
| **D-053** | Time zones in the release; the mobile pass deferred |
| **D-052** | Portal content on the public website is in the first release |
| **D-051** | Check rounds cap at three, except money, privacy and data loss |
| **D-050** | One shared helper for keeping the rotated form token |
| **D-049** | Content translation is finished for the first release |
| **D-045** | Report at a commit boundary, and keep going in the same turn |
| **D-041** | What goes into the first usable release (superseded in part) |
| **D-035** | The Codex review is split, and starts only when the work is done |
| **D-027** | Decisions are recorded in `docs/decisions/` and searched before asking |
| **D-024** | The version number moves to release time |
| **D-019** | MySQL 8.0 and MariaDB 10.6 stay accepted, with a warning |
| **D-017** | Gating a feature is a capability, never a hard-coded role |
| **D-016** | A person's own words about themselves are emptied on erasure |
| **D-015** | Full check depth on every package |
| **D-013** | For a limit, the installation's value wins |
| **D-012** | Packages sharing no files may run side by side |
| **D-007** | One comprehensive Codex review at the end of the queue |
| **D-005** | Commit and push after an independent Claude check |

**Codex has reviewed none of this work.**

## Open, unscheduled, each with an issue

| Issue | What |
|---|---|
| #769 | Nothing exercises `api/livechat/prompt-publish`; no test drove two rotating actions in sequence |
| #779 | No setting for which languages an installation offers (D-062) |
| #782 | Eighteen hard-coded `Europe/London` literals, and two columns that default to it |
| #780 | Saved reports filtering on a member's language changed answer at migration 251 |
| #762 | The spend limit cannot bite on the LibreTranslate route (D-059 answers it) |
| #764 | Three smaller findings from #485/#761 |
| #765 | Translation spend is counted installation-wide |
| #766 | No rate limit on a money-spending endpoint |
| #767 | A cookie-consent decision is silently lost after a token-rotating post |
| #679 | What an approval step becomes when its one person is erased |
| #725 | Nothing exercises the documents API over HTTP |
| #726 | Can `move_uploaded_file()` report success after a partial write? |
| #729 | The Edit dialog's "leave blank to keep current" for an account with none |
| #733 | Migration 241 can grant the role to an account that cannot sign in |
| #734 | A person erased at one organisation keeps free text at another |
| #736 | Three small things from #724 |
| #748 | version-bump and changelog write straight to `alpha`/`beta` |
| #749 | The in-string blindness is wider than `.php` |
| #750 | Four self-tests cut a code block four ways; only one knows heredocs |
| #755 | An organisation cannot express its own "no" for the self-add password |
| #758 | What `tblWorkflowSteps.assigneeValue` may hold is undecided |
| #759 | Settings with an empty stored default where a fresh install has a value |
| #760 | Nothing lints `tools/e2e-migrations/run.sh` |
