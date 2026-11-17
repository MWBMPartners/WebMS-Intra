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

`claude/alpha-wip`. HEAD `e8cb581f` (local; last push `1703db10`, 17 November). Landed since the plan began: #808, #1064
(merge accounts, migrations 297, 299), #993 (301, 302), #575, #1066, #1095, #1088 (303), #539, #939, #606 (1, 2, 4, 5),
#1096, #1073, #1067, #1092, #934 (consent records, 305), #1131 (API docs restricted), #1136/#1142 (erasure notice, Offboard
link, Remove hidden on one-organisation portals), #1137 (a whole restore ends every sign-in), #1141 (noticeboard saves).
Migration numbers in use: 290, 292, 294-305. **After the pilot is installed, every schema change needs a numbered
migration again and the migration harness must upgrade from the installed commit (D-135 ends).**

## Waiting on the owner

- Run `gh auth refresh -s read:project -s project` so the plan board (#1132) can be made (milestones exist: 19 Pilot
  install, 20 Release, 21 After release).
- #1140: may an API key holder open `/api-docs`? Built as No.
- For the real-host rehearsal: a spare DreamHost subdomain and an empty database (give a short checklist when the
  local rehearsal passes; secrets never in chat).

## In flight

- **R1** (#1133, #1139: install guide corrections): built on `pR1-build` (26999fb2); an Opus read-through is running.
  Land it, then push.

## Next, in this order

1. Land R1; push `claude/alpha-wip`.
2. Build the LIVE pilot zip from the final commit (D-222): `bash tools/package/build.sh <commit> live <new empty folder>`,
   no `--tag`, never published; then `tools/package/package-selftest.sh` and `check-package.py`. A pilot zip with a
   database change takes a new last version number (D-218).
3. Second local install and upgrade rehearsal (Opus agent) from that zip, following INSTALL.md (live channel, first-use
   journey, offboard and rehire, erasure notice, restore, noticeboard saves, API docs restricted). Fix defects.
4. DreamHost rehearsal on the owner's subdomain, then the pilot install (the owner's action). Record the installed
   commit and add it to the migration harness as an upgrade starting point.
5. After the pilot: the backlog (D-055: #777 before 28 March 2027, #1077/#1078, #591, #1082), #754, the full Codex
   review (about 40 allowances for the branch, about 5 for the core), then the merge to main.

## Raised, not built (selection; the tracker is the record)

#1097, #1105 (money limit not exact under two simultaneous requests), #1106 (regenerate the noticeboard bundle), #1108,
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
