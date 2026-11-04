# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 4 November 2026.*

## Branch

`claude/alpha-wip` at **`1cbf567`**, pushed. Working tree clean.

**The owner gave standing approval on 2 November 2026 to push this working
branch after each commit** — never a release or protected branch, never a force
push, and no pull request until the Codex review is done (D-035, D-007).

## In flight

| | |
|---|---|
| **What** | The whole self-test suite, `tools/run-all-selftests.sh` (#695) |
| **Why** | Eight issues closed this run across six commits. A test broken by one package is invisible to the next, and that has already failed twice (#690, #695) |
| **Next** | Read its log, fix anything red, then the remaining high block — which is now large standing pieces and items needing owner decisions |

## Commits this run, newest first

| Commit | What |
|---|---|
| `1cbf567` | #511 granting a coordinator reached the whole installation, and the refusal was invisible |
| `c4470cd` | #594 the installer's six gaps — four already closed, one fixed, one inherent |
| `bd4776e` | #678 how work is recorded — and two corrections were stale the other way |
| `6ff7ea5` | #756 the volunteer photo tier is a real gate (D-047) — **a behaviour change** |
| `5ae05f4` | #735 two erasure races exercised; #730 and #747 become hard gates (D-046) |
| `ef44f5a` | #754 the six workflows that have never run, and the one real gap in them |

**Closed this run:** #475 (critical), #511, #594, #678, #730, #735, #747, #756.
**Opened:** #758 (`assigneeValue` undecided), #759 (#594's item 6, not
re-measured), #760 (nothing lints the migration harness's shell).
**Recorded:** D-046, D-047, D-048.

## Five standing facts a reader needs

- **A new script in `tools/audit-checks/` is NOT wired up until
  `pr-security.yml` NAMES it.** That file lists every script individually;
  **there is no folder glob in any workflow.** The pre-push hook does glob the
  folder, which is what misled me once already.
  `for f in tools/audit-checks/check_*.py; do grep -rqs "$(basename "$f")" .github/workflows/ || echo "NOT WIRED: $f"; done`
- **Six workflows have never run on GitHub, and two CANNOT** (#754).
  `package.yml` — which builds the customer's zip — triggers only on a push to
  `main`/`rc`/`beta`/`alpha` or a `v*` tag, and `workflow_dispatch` needs the
  file on the default branch. So the release build first runs at the merge.
  `gh api repos/MWBMPartners/WebMS-Intra/actions/workflows --jq '.workflows[].path' | sort > /tmp/reg; ls .github/workflows/*.yml | sort | comm -13 /tmp/reg -`
- **A step's script carrying a `${{ ... }}` is capped at 21,000 characters**
  (#745). Over it, GitHub refuses the whole file and starts no job while every
  local parser stays green. The tell is the run's `name` equalling its `path`.
- **MySQL 8.0 and MariaDB 10.6 stay ACCEPTED with a warning** (D-019, D-038).
  Do not raise `MIN_MYSQL` or `MIN_MARIADB` as a tidy-up. #475 is closed on that
  basis.
- **Do not turn `php-static-analysis.yml` into a matrix** — it would rename the
  check `main` requires and soft-lock every pull request into it.

## Next, in this order

The owner's order of 3 November: **everything critical and high priority first,
then the rest of the queue.** Critical is now empty.

**The high block is now only large pieces and things needing a decision.** Four
of the eight remaining are features rather than fixes, which is a change in the
character of the queue and worth saying before picking one.

1. **Read the suite's log** and fix anything red (in flight).
2. **#477** — a "check my portal" page for administrators. A new feature, and
   the September audit's point was that almost every fault was invisible from
   inside the product.
3. **#485** — content translation is unreachable. The engine, its admin page and
   the member opt-in exist; nothing a user can reach calls it. **Finishing it or
   removing it is an owner decision**, not a tidy-up.
4. **#493** — portal content viewable from the main website. A feature.
5. **#499** and **#500** — the downloadable package and no built-in addresses.
   Large, and #500 is partly done (`PortalAddress`).
6. The three standing audit passes: **#569** (every page + a pre-push browser
   check), **#549** (time zones everywhere), **#225** (mobile).
7. Then the medium block (30), then low (30).
8. Then the comprehensive Codex review of the whole branch, then the merge.

## Decisions in force — POINTERS ONLY

**Every decision lives once, in `docs/decisions/`, one file each** (D-027). This
list names them; it never restates them. 47 on file.

| | Decision |
|---|---|
| **D-048** | The three safety settings are added to `~/.claude/settings.json` |
| **D-047** | Only volunteers see the volunteer photo tier — **a behaviour change** |
| **D-046** | Two checks that only printed a problem now stop the work |
| **D-045** | Report at a commit boundary, and keep going in the same turn |
| **D-038** | Ship on the MySQL 8.0 convention and say so plainly |
| **D-035** | The Codex review is split, and starts only when the work is done |
| **D-027** | Decisions are recorded in `docs/decisions/` and searched before asking |
| **D-024** | The version number moves to release time |
| **D-019** | MySQL 8.0 and MariaDB 10.6 stay accepted, with a warning |
| **D-017** | Gating a feature is a capability, never a hard-coded role |
| **D-015** | Full check depth on every package |
| **D-013** | For a limit, the installation's value wins |
| **D-012** | Packages sharing no files may run side by side |
| **D-007** | One comprehensive Codex review at the end of the queue |
| **D-005** | Commit and push after an independent Claude check |

**Codex has reviewed none of this work.**

## Blocked on the owner

| What | Why |
|---|---|
| `docker volume rm cfbd1277f4cb2a4a8de5367a4cdd5e00087486d6c0ff381dec946379f2b870f7` (3 Nov) and `f4c7e62cac21f7efebe318b12013d95e5864424a3d2f8d7bca8c111b0133ad24` (30 Oct) | The permission classifier refuses `docker volume rm`, correctly. Reported, left alone. Both pre-existing; nothing this run added one. |
| SIGNula.id | Its archive is still in that repository's history — removing it needs a force-push. It also has the same eight-writer password fault #728 fixed here. |

## Open, unscheduled, each with an issue

| Issue | What |
|---|---|
| #679 | What an approval step becomes when its one person is erased |
| #759 | Settings with an empty stored default where a fresh install has a value |
| #760 | Nothing lints `tools/e2e-migrations/run.sh`; two SC2071 in it |
| #725 | Nothing exercises the documents API over HTTP |
| #726 | Can `move_uploaded_file()` report success after a partial write? |
| #729 | The Edit dialog's "leave blank to keep current" for an account with none |
| #733 | Migration 241 can grant the role to an account that cannot sign in |
| #734 | A person erased at one organisation keeps free text at another |
| #736 | Three small things: breadcrumb, twice-written address, unpinned refusal |
| #748 | version-bump and changelog write straight to `alpha`/`beta` |
| #749 | The in-string blindness is wider than `.php` — a bare address too |
| #750 | Four self-tests cut a code block four ways; only one knows heredocs |
| #755 | An organisation cannot express its own "no" for the self-add password |
| #758 | What `tblWorkflowSteps.assigneeValue` may hold is undecided |
