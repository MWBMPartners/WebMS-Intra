# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 6 November 2026.*

## Branch

`claude/alpha-wip` at `e66507f`, pushed. Standing approval (owner, 2 November
2026) to push this branch after each commit — never a release or protected
branch, never a force push, no pull request until the Codex review (D-035, D-007).

## Just landed

**#775 — every scheduled job records that it ran (migration 253, D-070, D-071).**
Review closed at round 7 under D-064/D-066 (`e66507f`): round 7's findings were
all in the gate, its test or the documents; the fifteen token gates are driven
over HTTP and correct. Left on issues: #794, #795, #789, #793.

**#777 rounds 20-22** (`d9f66f0`, `57aae2e`, `f9851c5`): the CSV import's output
guard (`$fmtStore` over `$fmtClockRaw`), section K of `clock-change-selftest.php`.

## In flight (side by side — they share no files, D-012)

| Package | State |
|---|---|
| #777 round 23 | Independent check of round 22's #777 changes. Report lands at `scratchpad/r23-report.md`. Protected arm (stored data), so rounds continue until clean; the first all-tooling round is the last (D-066). |
| #762 | Being built (D-059: count translations as well as money). Builder must not touch #777's files or the shared documents; its document entries land at `scratchpad/762-doc-entries.md`, to be added by the commissioning session at commit. Protected arm (money). Needs its own independent check after the build. |

## Next, in this order

1. #777 part two — `tools/audit-checks/clock_wall_clock_datetime_pending.txt`, with #783, #784, #785, #787.
2. #778 — outside services in three tiers (D-058); the gravatar leak is still live.
3. #493 — portal content on the public website, in full (D-052, D-056, D-057). The largest item.
4. #569 — the page findings left.
5. The whole backlog (D-055).
6. #754 — the six workflows GitHub has never registered (last, D-054).
7. The comprehensive Codex review (D-007), then the merge.

## Blocked on the owner

| What | Why |
|---|---|
| #792 | Two self-tests red: should the translation app be switchable off, and the dashboard's not-strict alert. Pre-existing. |
| #793 | D-070's 36-hour allowance is too loose for six frequent jobs — a re-ask with the measured fact. |
| Dangling volume `293105ed…` | A checker's leftover; only the owner can remove it (D-067). Count is 1. |
| SIGNula.id | Five local commits waiting for the owner's push approval. |

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

Every decision lives once, in `docs/decisions/`, one file each (D-027). 71 on file; read the README index there. **Codex has reviewed none of this work.**

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
