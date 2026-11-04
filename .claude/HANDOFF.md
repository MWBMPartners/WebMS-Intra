# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 4 November 2026.*

## Branch

`claude/alpha-wip` at **`52098f3`**, pushed. **An uncommitted fix round for
#475 part 1 is in the working tree** — nine real problems found by the
independent check, all fixed, waiting on the whole self-test suite before it is
committed.

**The owner gave standing approval on 2 November 2026 to push this working
branch after each commit** — never a release or protected branch, never a force
push, and no pull request until the Codex review is done.

## In flight

| | |
|---|---|
| **What** | #475 part 1 fix round — 9 real problems from the independent check |
| **State** | Fixed and verified: 56 audit scripts 0 red, actionlint clean, 16 workflows parse, `db-server-selftest.php` 36/36, every plant re-proved |
| **Waiting on** | `tools/run-all-selftests.sh` (round 2). Round 1 was 74 tests, 0 not green |

## Last four commits, all pushed, all with their issues recorded

| Commit | What |
|---|---|
| `52098f3` | #475 part 1 — MySQL 9.7.4 and MariaDB 12.3.3 proved, five untrue "what is tested" sentences, PHP 8.5 linted, end-of-life watch |
| `1c2c495` | #745 the guard for the 21,000-character ceiling |
| `812d992` | #745 **every security gate had stopped running on GitHub for a week** |
| `c2c9f49` | #492 parts 2-4 · #472 · #581 (migration 246) |

## Two standing facts a reader needs before touching CI

- **A new script in `tools/audit-checks/` is NOT wired up until
  `pr-security.yml` NAMES it.** That file lists every script individually;
  **there is no folder glob in any workflow here.** `.githooks/pre-push` does
  glob the folder, which is what misled me — #475's new gate was the only one
  of 56 scripts in no workflow at all. Test it:
  `for f in tools/audit-checks/check_*.py; do grep -rqs "$(basename "$f")" .github/workflows/ || echo "NOT WIRED: $f"; done`
- **A step's script carrying a `${{ ... }}` is capped at 21,000 characters**
  (#745). Over it, GitHub refuses the whole file and starts no job. Every
  parser here accepts it and actionlint is green. The tell is the run's `name`
  equalling its `path`. Guarded by `check_workflow_expression_length.py`.

## Next, in this order

The owner reordered on 3 November: **everything critical and high priority
first, then the rest of the queue.**

1. Commit and push the #475 fix round.
2. **#475 part 2** is NOT started. What is left on that issue: confirm in
   writing what DreamHost shared hosting offers; a systematic list of the real
   differences between the engines; whether the calendar self-tests should also
   run on MySQL 9.7 and MariaDB 12.3.
3. The high-priority block: #225, #477, #485, #493, #499, #500, #511, #549,
   #569, #594, #637, #654, #678, #732, #739, #742, #743, #744.
4. Then: #582 · #620, #596, #588 · #649, #541 · #703 (decided), #539, #619 ·
   #476, #146.
5. Then the comprehensive Codex review of the whole branch, then the merge.

## Decisions in force that a reader needs

- **MySQL 8.0 and MariaDB 10.6 stay ACCEPTED, with a warning** (the owner,
  3 November 2026). **Do not raise `MIN_MYSQL` or `MIN_MARIADB` as a tidy-up.**
  It also makes the warning the only protection, which is why `TESTED_LINES`
  and its gate exist.
- **Branch rules (3 November).** `main` requires `PHP -l lint` **and**
  `Static security checks`, and **administrators no longer bypass** its rules.
  `alpha`/`beta` are deliberately unguarded — blocked on #748. #474 stays open.
  **Do not turn `php-static-analysis.yml` into a matrix:** it would rename the
  required check and soft-lock every pull request into `main`.
- **A capability, never a hard-coded role** (#727). `erasure.review` is the one
  capability a global administrator does not fall open on.
- **#703:** keep the name of who made a pastoral visit or safeguarding check.
- **#723:** the booker IS the booking's subject — `purpose` and nothing else.
- **Full check depth stays on every package.**
- **Codex has reviewed none of this.**

## Nothing has run the new CI on GitHub

`end-of-life-watch.yml` cannot be dispatched (`HTTP 404: not found on the
default branch`), and `e2e-migrations.yml`'s five-engine matrix needs a pull
request touching `web/_sql/`. So **both are proved locally only.** Recorded on
#475.

## Blocked on the owner

| What | Why |
|---|---|
| `docker volume rm cfbd1277f4cb2a4a8de5367a4cdd5e00087486d6c0ff381dec946379f2b870f7` | The permission classifier refused it three times. Reported, left alone. |
| #731 | SSO fallback password — the decision spans SIGNula.id. Five questions drafted on the issue. |

## Open, unscheduled, each with an issue

| Issue | What |
|---|---|
| #725 | Nothing exercises the documents API over HTTP |
| #726 | Can `move_uploaded_file()` report success after a partial write? |
| #728 | No single place sets a password — six writers |
| #729 | The Edit dialog's "leave blank to keep current" for an account with none |
| #730 | `check_bind_param_arity.py` cries wolf |
| #732 | **No HTTP test for the two review pages** |
| #733 | Migration 241 can grant the role to an account that cannot sign in |
| #734 | A person erased at one organisation keeps free text at another |
| #735 | The review's two races are backstops no test forces |
| #736 | Three small things: breadcrumb, twice-written address, unpinned refusal |
| #747 | `check_fk_references_unique_key.py` cries wolf over an index reached by `RENAME INDEX` |
| #748 | version-bump and changelog write straight to `alpha`/`beta`, blocking #474 |
