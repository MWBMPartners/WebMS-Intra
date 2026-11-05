# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 5 November 2026.*

## Branch

`claude/alpha-wip` at **COMMIT_SHA**, pushed. Working tree clean.

**Standing approval (owner, 2 November 2026) to push this working branch after
each commit.** Never a release or protected branch, never a force push, and no
pull request until the Codex review is done (D-035, D-007).

## Just landed

**#768 — one place writes the form token, and it writes every carrier (D-050).**
FIVE copies of that rule existed and no two agreed how many carriers a page has.
The shared one was the LEAST complete. Live cost: on the Host Console, publish a
chat prompt then press "Send 'We're live'" — refused, press lost.

**SIX independent check rounds: 6+4+5+4+6+4 findings, NONE in the original
build** — every one in a previous round's fix. The detector took six versions;
the first five each had a hole and each was "verified by planting" the shapes
its author had thought of. Rounds 4-6 found almost nothing but untrue comments
and gaps in the guard: **the code converged at round 3, the writing about it
did not.**

**Three MORE live faults of the same family, found by looking for the RULE
rather than its instances** — #770 (a member cannot add a passkey at all),
#774 (push subscription loses the notification preferences on Save), #771 (the
worship console). The gate is right to pass all three and cannot see them.

`tools/csrf-carriers-selftest.mjs` drives a real browser, 22 checks, nothing
installed. **It is NOT the first here** — `tools/layout-audit.mjs` has since
`0186531` (30 October, #569) — but it IS the first wired into a workflow, and
`pr-security.yml` IS registered, so it really runs.

## In flight

Nothing. The next item is unstarted.

## Next, in this order — FIVE decisions changed this on 5 November

1. **#477** — a "check my portal" page for administrators. ~2 packages.
2. **#500** — no built-in web addresses. Partly done (`PortalAddress`). ~1–2.
3. **#499** — the downloadable zip package. Mostly built. ~1–2.
4. **#549** — the time-zone pass. **IN the release — D-053.** ~2–3.
5. **#569** — page audit + pre-push browser check. Browser automation now
   proved (see #768), which makes this far cheaper than it looked. ~3–4.
6. **#493** — portal content on the public website. **IN the release, IN FULL —
   D-052.** The largest item; a new surface reachable without signing in. ~4–6.
7. **Medium (63), then low (52), then the 8 unlabelled.** **ALL of them —
   D-055: the whole backlog is cleared before the release.** No triage. ~36–46.
8. **#754** — **moved here from position 10, D-054**, because its only blocker
   is the pull request.
9. The comprehensive Codex review (D-007), then the merge.

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

**A published helper is a single source of truth only while it is the BEST
copy.** `portal.js` had told callers for months to use its token helper rather
than writing their own; it was the worst of the five, so four of them did, each
copying the nearest neighbour rather than the shared one — and the gap
travelled while the fix never did.

## Decisions in force — POINTERS ONLY

**Every decision lives once, in `docs/decisions/`, one file each** (D-027). This
list names them; it never restates them. **55 on file.**

| | Decision |
|---|---|
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
| #762 | The spend limit cannot bite on the LibreTranslate route |
| #763 | A member's saved interface language is applied nowhere |
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
