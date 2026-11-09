# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 9 November 2026.*

## Branch

`claude/alpha-wip`, pushed after every commit (D-088). Latest: `5c19a8cd`/`4362a1bd`/`6209c0f0` (#823,
closed), `e077b1f8` (#821 docs + D-093/D-094/D-095), `f934c7df`/`e0cee3bb` (#821 items 1-3), `9c1cecfb`
(#493 P2b, migration 261). Nothing uncommitted in the main checkout. Whole suite 93/93.

## In flight (side by side, no shared files — the 12 October 2026 rule)

- **#493 P3** — the Admin → Public website page (D-085, D-093, D-094, D-095), migration 262. Built in
  worktree `.claude/worktrees/agent-a9965bc08d040d0f1`, branch `p3-build`, commit `61ba3808` (on
  `f934c7df`); its test 216/216. **Check round 1 running** (protected arm: runs until clean). Its
  CHANGELOG/DEV_NOTES/FEATURES entries are in that worktree's `.claude-work/evidence/p3/notes.md`; add them
  when it is cherry-picked here.
- **#649** — a receipt that fails to attach (money: protected arm). Being built in its own worktree, branch
  `p649-build`.
- Worktrees for #821 (`agent-a94ac…`) and #823 (`agent-aacab…`) are finished and merged; their notes are in.

## Next, in this order

1. P3 to commit; then **P4** (the public door, D-056/D-084/D-087; it makes the door answer on
   `tblPublicHosts` and sends `frame-ancestors` from `PublicWebsiteSetup::frameAncestorsValue()`).
2. The rest of the backlog (D-055), two at a time where files do not overlap and only one package at a
   time changes `web/_sql/` (migration numbers and full_schema.sql clash otherwise).
3. #821 items 4-8 (the issue stays open). 4. #754 (waits on the merge). 5. The Codex review (D-007), the merge.

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
