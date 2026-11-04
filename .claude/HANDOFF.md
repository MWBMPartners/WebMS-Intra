# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 4 November 2026.*

## Branch

`claude/alpha-wip` at **`6ff7ea5`**, pushed. Working tree holds the #678
documentation corrections, uncommitted.

**The owner gave standing approval on 2 November 2026 to push this working
branch after each commit** — never a release or protected branch, never a force
push, and no pull request until the Codex review is done (D-035, D-007).

## In flight

| | |
|---|---|
| **What** | #678 — how work is recorded |
| **State** | Parts 1 and 2 resolved; the three instruction-file passages about the settings were stale in the OPPOSITE direction and are corrected; #758 opened for the one question that lived only in prose |
| **Next** | Commit it, then #594 (installer gaps) |

## Commits this run, newest first

| Commit | What |
|---|---|
| `6ff7ea5` | #756 the volunteer photo tier is a real gate (D-047) — **a behaviour change** |
| `5ae05f4` | #735 two erasure races exercised; #730 and #747 stop crying wolf and become hard gates (D-046) |
| `ef44f5a` | #754 the six workflows that have never run, and the one real gap in them |
| `3c0a57d` | #757 a check looking for the wrong text, because PHP interpolated it away |
| `e86d944` | #739 gating a feature is a capability now, almost everywhere |

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

1. **#678** — in flight, above.
2. **#594** — installer and install script: the continue-or-wipe page cannot be
   used, and five more gaps.
3. **#511** — being signed in counts as membership of whichever organisation the
   address opens as.
4. **#477** — a "check my portal" page for administrators.
5. **#485** — content translation has no way in; **#493**; **#499**; **#500**.
6. The three large standing pieces: **#569** (page audit + pre-push browser
   checks), **#549** (time zones everywhere), **#225** (mobile pass).
7. Then the medium block (30), then low (30).
8. Then the comprehensive Codex review of the whole branch, then the merge.

## Decisions in force — POINTERS ONLY

**Every decision lives once, in `docs/decisions/`, one file each** (D-027). This
list names them; it never restates them. 47 on file.

| | Decision |
|---|---|
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
