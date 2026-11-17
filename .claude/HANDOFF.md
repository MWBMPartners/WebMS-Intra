# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 17 November 2026. Updated at every landing and every change of a
package's state (the owner, 10 November 2026).*

## Aim: the PILOT install (D-205)

The first install is a pilot for the owner's own organisation; D-055 (whole backlog) and the full Codex review (D-007)
still apply before any release to anybody else. No Codex before the pilot (D-206: the owner chose speed; the pilot
runs on independent Opus checks). The pilot ships with AI, translation, transcription, text messages and online
payments switched OFF (D-207; confirm each default before the zip). Until the pilot: at most THREE Opus check rounds per
package, only realistic harm blocks, narrow findings become issues (D-204). The plan is `/tmp/claude-501/firstinstall/plan.md`
(may be gone after a reboot: its content is the list below).

## Branch

`claude/alpha-wip`, pushed. HEAD `a0ab6b67`. Everything planned for the pilot has landed (see CHANGELOG and the issue
tracker: milestone "Pilot install" holds what remains). Migration numbers in use: 290, 292, 294-306. **After the pilot is
installed, every schema change needs a numbered migration again and the migration harness must upgrade from the installed
commit (D-135 ends).**

## The pilot zip

`webms-intra-1.4.1-live-a0ab6b67.zip` (14 MB, LIVE channel, never published, no tag; D-222), with its `.sha256`, kept in
`Build Infrastructure/Releases/LocalReview/WebMS-Intra-pilot/`. Built by `bash tools/package/build.sh a0ab6b67 live <empty
folder>`; the package self-test passed 340 of 340. Three independent local rehearsals (fresh install, first-use journey,
every fix re-proved over real HTTP and a real browser) found no data leak, wrongful access, money misstatement or broken
install in the last one. The whole suite (169 tests) was run once after the last package; six reds were test faults and are
fixed. **Rebuild the zip if any file under `web/` or `docs/INSTALL.md` changes.**

## Waiting on the owner

- A spare DreamHost subdomain and an empty database for the real-host rehearsal (secrets never in chat), then the pilot
  install itself (the owner's action).
- Run `gh auth refresh -s read:project -s project` so the plan board (#1132) can be made.
- #1140: may an API key holder open `/api-docs`? Built as No.

## In flight

Nothing.

## Next

1. Real-host rehearsal on DreamHost (not tested so far: PHP-FPM/CGI hosting, MariaDB, https, a hosting panel's scheduler;
   on such hosts the Authorization header may not reach PHP, so API keys need the host's own setting: say so in the report).
2. The pilot install. Then record the installed commit and add it to the migration harness as an upgrade starting point.
3. After the pilot: the backlog (D-055: #777 before 28 March 2027, #1077/#1078, #591, #1082), #754, the full Codex
   review (about 40 allowances for the branch, about 5 for the core), then the merge to main.

## Raised, not built (selection; the tracker is the record)

#1150-#1162 (rehearsal findings), #1097, #1105 (money limit not exact under two simultaneous requests), #1106 (regenerate the noticeboard bundle), #1108,
#1123, #1127-#1130, #1143-#1149 (#1148: beta gate versus scheduled jobs; #1149: noticeboard save timeout and notice).

## Working pattern

Builders and checkers run in their own worktrees and scratch folders under `/tmp/claude-501/<pkg>/`. Model tiers (D-202):
Opus plans and checks, Sonnet/Haiku build. The commissioning session merges a clean package, writes its docs FROM THE FINAL
STATE, adds runner and workflow wiring (builders may not edit shared documents, `tools/run-all-selftests.sh` or
workflows), runs pre-push, pushes (only `claude/alpha-wip`, owner-approved), closes the issue with the `status: done`
label. Run DB self-tests ONE at a time on their own database and port; whole-suite runs collide when several sessions
run (re-run a red alone before calling it a fault). Runner row format: `name|script|database|env` (a lone `-` in the env
field is wrong; leave it empty). `check_outside_hosts_named.py --self-test` takes about five minutes (the audit
self-test cap is 900 s). Never wait with `pgrep -f`.

## Blocked on the owner

| What | Why |
|---|---|
| The board command, #1140, the rehearsal host | See "Waiting on the owner" |

## Standing facts a reader needs

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

Every decision lives once, in `docs/decisions/`, one file each (D-027); the README there is the index. Count
them from the folder. **Codex has reviewed none of this work.**

## What is open

The issue tracker is the record (`gh issue list --state open`); by D-055 every open issue is cleared before the
first release. Raised this stretch: #892-#899, #901, #904-#911, #913, #914, #916-#919, #941-#1020.
