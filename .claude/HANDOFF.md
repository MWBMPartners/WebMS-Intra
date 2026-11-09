# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 9 November 2026.*

## Branch

`claude/alpha-wip`, pushed after every commit (D-088). Latest: #493 P3 (ten commits from its worktree +
docs; migration 262), #649 (`3e111466..a00ca32a` + docs), D-096, #823, #821 items 1-3. Whole suite 94/94;
migration harness 42/42 on MySQL 8.4.11 and MariaDB 11.4.13. Nothing uncommitted in the main checkout.

## In flight (side by side, no shared files — the 12 October 2026 rule)

- **#667** — one writer of a member's notification choices (`NotifyPrefs`), merging only the keys a form
  showed. Worktree `.claude/worktrees/agent-ab53a7bdf5c7e4f44`, branch `p667-build`, commits `8fe7944c`,
  `7be0f1e4`; test 41/41. **Check round 2 running** (protected arm: stored data). Its doc entries and a
  replacement for CLAUDE.md's "Removing a preference switch is two changes" bullet are in that worktree's
  `.claude-work/evidence/667/notes.md`.

## Next, in this order

1. **#493 P4** — the public door (D-056: script, frame, feed; D-084: delete `web/public_html/widget/` with a
   test that `/widget` answers nothing; D-087: the test-copy door setting, a card on Admin → Public website).
   It must send `PublicWebsiteSetup::frameAncestorsFor()` and answer on `tblPublicHosts`. Plan it first and
   post the plan on #493. It shares files with P3, so nothing touching those runs beside it.
2. The rest of the backlog (D-055), two at a time where files do not overlap; only one package at a time
   changes `web/_sql/`.
3. #821 items 4-8 (open). 4. #754 (waits on the merge). 5. The Codex review (D-007), then the merge.

## Working pattern that is paying off

Builders and checkers run in their own worktrees; the commissioning session cherry-picks a clean package,
adds its docs from the worktree's notes, runs the whole suite, pushes. When the same item fails two check
rounds running, simplify the design (P3's verdict, #649's after-commit path both landed that way).

## Blocked on the owner

| What | Why |
|---|---|
| Dangling volume `293105ed…` | A checker's leftover; only the owner can remove it (D-067). Count is 1 |
| SIGNula.id | Five local commits waiting for the owner's push approval |

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

## Decisions in force

Every decision lives once, in `docs/decisions/`, one file each (D-027). 91 on file; read the README
index there. **Codex has reviewed none of this work.**

## Open, unscheduled, each with an issue

| Issue | What |
|---|---|
| #769 | Nothing exercises `api/livechat/prompt-publish`; no test drove two rotating actions in sequence |
| #779 | No setting for which languages an installation offers (D-062) |
| #782 | Eighteen hard-coded `Europe/London` literals, and two columns that default to it |
| #780 | Saved reports filtering on a member's language changed answer at migration 251 |
| #764 | Three smaller findings from #485/#761 |
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
| #789 | A real run of a scheduled job is driven on two of fifteen pages |
| #791 | Record WHICH of three reasons a job refused |
| #794 | Shapes the scheduled-jobs nesting check cannot see |
| #795 | A self-referential test, a duplicated helper, four wording faults (#775) |
| #843 | The events API's list and detail do not report the website fields (#493) |
| #844 | The series bulk-edit page has no website control (#493) |
| #847 | A repeating series copies one website date window onto every date (#493) |
| #851 | Ten gaps in the top-bar and phone-width self-test (#832/#833 rounds 2 and 3) |
| #856 | Some public-website-tick plant checks pass when their scenario fails for any reason |
| #858 | Without JavaScript, a saved outside-calendar choice's redraw can show a Yes the person unticked |
| #859 | The public event-suggestion form promises an e-mail on approval, and nothing sends one |
| #865 | The offline page never retries by itself; nothing tests that the service worker refuses `private` |
| #866 | D-093's hand-out rule is not judged inside the transaction that writes |
| #867-#871 | Expense receipts: cannot be opened, added later, PDF after rollback, refused form loses typing, chooser hides types |
| #872 | The portal's own database connection has no read deadline |
| #873 | SafeFetch reads any 'HTTP/x NNN' header line as a new status |
| #874 | tblUsers.notifyPrefs' column comment names two retired keys |
