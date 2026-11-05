# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 5 November 2026.*

## Branch

`claude/alpha-wip` at **`e54fa7f`**, pushed. Working tree clean.

**Standing approval (owner, 2 November 2026) to push this working branch after
each commit.** Never a release or protected branch, never a force push, and no
pull request until the Codex review is done (D-035, D-007).

## Just landed

**`e54fa7f` — content translation is reachable (#485, #761, migration 248, D-049).**
It was a REWRITE, not a re-route: the old `_apps/api/translate.php` took the
words from `$_POST['text']`, read back any organisation's cached translation
from a posted row number, and checked nothing about whether the caller could
see the row. None of it was exposed because the address was unreachable — and
finishing #485 was the act that would have exposed all three.

**A prayer request is deliberately not translatable** (D-049's own requirement,
and D-016 already treats that table as a person's own words about themselves).
The owner can overrule it: the allow-list is one constant in
`Portal\Core\ContentTranslation` and a hard gate pins it.

## In flight

Nothing. The next item is unstarted.

## Next, in this order

The owner's order of 3 November: **everything critical and high priority first.**
Critical is empty. **Eight high remain, and four are features rather than
fixes** — a change in the character of the queue worth noticing before picking.

1. **#477** — a "check my portal" page for administrators. The September audit's
   point was that almost every fault was invisible from inside the product.
2. **#493** — portal content viewable from the main website.
3. **#499** / **#500** — the downloadable package; no built-in addresses (#500
   is partly done: `PortalAddress`).
4. **#569**, **#549**, **#225** — the three standing audit passes (every page +
   a pre-push browser check; time zones; mobile).
5. **#754**'s remaining half — blocked on the pull request, which waits on
   D-035/D-007.
6. Then medium (62), then low (51), then the comprehensive Codex review, then
   the merge.

## Blocked on the owner

| What | Why |
|---|---|
| **#768** — fold the shared token-helper fix into the next package, or leave it? | `Portal.rememberCsrf()` in the shared file is the LEAST complete of three copies of "keep the rotated form token", so pages where an `apiRequest` succeeds already leave their forms holding a spent token. Fixing it means changing JavaScript that loads on every page, for features #485 never touched. **Asked twice, unanswered.** |
| **How many check rounds** a package should get | #485 took SEVEN. Findings per round: 5, 5, 3, 2, 1, 2, 1 — and rounds 2-7 found faults in the previous round's FIXES, not the build. **Asked twice, unanswered.** The guideline is three. |
| `docker volume rm cfbd1277…` (3 Nov) and `f4c7e62c…` (30 Oct) | The permission classifier refuses it, correctly. Both pre-existing; the count was 2 before and after this package. |
| SIGNula.id | Its archive is still in that repository's history (removing it needs a force-push), and it has the same eight-writer password fault #728 fixed here. |

## Five standing facts a reader needs

- **A new script in `tools/audit-checks/` is NOT wired until `pr-security.yml`
  NAMES it.** That file lists every script individually; there is no folder glob
  in any workflow. The pre-push hook DOES glob the folder, which is what misled
  me once.
  `for f in tools/audit-checks/check_*.py; do grep -rqs "$(basename "$f")" .github/workflows/ || echo "NOT WIRED: $f"; done`
- **Six workflows have never run on GitHub, and two CANNOT** (#754).
  `package.yml`, which builds the customer's zip, triggers only on a push to
  `main`/`rc`/`beta`/`alpha` or a `v*` tag, and `workflow_dispatch` needs the
  file on the default branch — so the release build first runs at the merge.
- **A step's script carrying a `${{ ... }}` is capped at 21,000 characters**
  (#745). Over it, GitHub refuses the whole file and starts no job while every
  local parser stays green. The tell is the run's `name` equalling its `path`.
- **MySQL 8.0 and MariaDB 10.6 stay ACCEPTED with a warning** (D-019, D-038).
  Do not raise `MIN_MYSQL` or `MIN_MARIADB` as a tidy-up.
- **Do not turn `php-static-analysis.yml` into a matrix** — it would rename the
  check `main` requires and soft-lock every pull request.

## The lesson this run earned, in one line

**Closing the spellings you were handed is not closing the class.** Four
consecutive rounds got past a guard on one 220-line script by a one-edit change,
each time after I had written a comment saying the class was shut. The remedy
was to stop probing for spellings and pin the file whole — right there because
nothing executes it, and wrong almost anywhere else. The long form is in
DEV_NOTES.md under the round-by-round headings.

## Decisions in force — POINTERS ONLY

**Every decision lives once, in `docs/decisions/`, one file each** (D-027). This
list names them; it never restates them. 49 on file.

| | Decision |
|---|---|
| **D-049** | Content translation is finished for the first release |
| **D-048** | The three safety settings are added to `~/.claude/settings.json` |
| **D-047** | Only volunteers see the volunteer photo tier — a behaviour change |
| **D-046** | Two checks that only printed a problem now stop the work |
| **D-045** | Report at a commit boundary, and keep going in the same turn |
| **D-038** | Ship on the MySQL 8.0 convention and say so plainly |
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
| #762 | The spend limit cannot bite on the LibreTranslate route (it reports no cost) |
| #763 | A member's saved interface language is applied nowhere |
| #764 | Three smaller findings from #485/#761 |
| #765 | Translation spend is counted installation-wide, so one organisation can exhaust another's limit |
| #766 | No rate limit on a money-spending endpoint; two presses can both pass the cap |
| #767 | A cookie-consent decision is silently lost after any token-rotating post |
| #768 | One shared helper for keeping the rotated form token |
| #679 | What an approval step becomes when its one person is erased |
| #725 | Nothing exercises the documents API over HTTP |
| #726 | Can `move_uploaded_file()` report success after a partial write? |
| #729 | The Edit dialog's "leave blank to keep current" for an account with none |
| #733 | Migration 241 can grant the role to an account that cannot sign in |
| #734 | A person erased at one organisation keeps free text at another |
| #736 | Three small things from #724 |
| #748 | version-bump and changelog write straight to `alpha`/`beta` |
| #749 | The in-string blindness is wider than `.php` — a bare address too |
| #750 | Four self-tests cut a code block four ways; only one knows heredocs |
| #755 | An organisation cannot express its own "no" for the self-add password |
| #758 | What `tblWorkflowSteps.assigneeValue` may hold is undecided |
| #759 | Settings with an empty stored default where a fresh install has a value |
| #760 | Nothing lints `tools/e2e-migrations/run.sh` |
