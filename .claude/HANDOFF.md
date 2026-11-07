# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 7 November 2026.*

## Branch

`claude/alpha-wip` at `45406be`, pushed. Standing approval (owner, 2 November
2026) to push this branch after each commit — never a release or protected
branch, never a force push, no pull request until the Codex review (D-035, D-007).

## Landed today (all pushed, issues closed)

#812/#819 `1e49d48` · #799 `4f43cfa` · #830 (comment) · #792 `b7872c3` (D-075)
· #822 `45406be`. Earlier the same day: #775, #777 p2a, #797, #762, #804-806,
#810, #793 (D-076), #569 part, #778 (D-058/072/073).

## In flight (side by side — they share no files, D-012)

| Package | State |
|---|---|
| #816 | Being built (D-074: optional registration-retention ceiling, off by default, D-032 approach). Protected arm. Notes to `scratchpad/816-doc-entries.md`; it must not edit `portal-selftests.yml` or `run-all-selftests.sh` itself — the lines to add are in its notes. Its in-progress files are in the working tree (retention sweep, settings pages, schema, `RegistrationRetention.php`) — stage nothing of them with another package. |
| #831 + #829 | Being built (test-only: the `'true'` translation switch and withdrawal-while-off; failed `preg_match()` read as "absent" in four tests). Notes to `scratchpad/829-831-doc-entries.md`. |

Each needs an independent check after the build.

## Next, in this order

1. #493 — portal content on the public website, in full (D-052, D-056, D-057). The largest item.
2. #569 — the page findings left; #832, #833, #834 from #822.
3. The whole backlog (D-055).
4. #754 — the six workflows GitHub has never registered (last, D-054).
5. The comprehensive Codex review (D-007), then the merge.

## Blocked on the owner

| What | Why |
|---|---|
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

Every decision lives once, in `docs/decisions/`, one file each (D-027). 76 on file; read the README index there. **Codex has reviewed none of this work.**

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
