# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 12 November 2026. Updated at every commit boundary and every
change of a package's state (the owner, 10 November 2026: "update our handoff document regularly at each stage").*

## Branch

`claude/alpha-wip`, pushed after every commit (D-088). Landed since 9 November, all pushed and closed: #887,
#493 (P4a-P4d), #869-#871, #900, #902, #903, #615, #620, #912, #648 (+ #915), #598, #529 (+ #917, #921), #782, #932, #703, #785, #935, #936, #940, #895, #947 (+ #937, #894, #911), #923, #943, #809, #867, #891 (+ #892, #951), #882 (+ #898), #963, #859, #919, #907, #942, #941 (+ #927), #968, #977, #933, #619, #655, #657, #691, #966, #998, #1000, #964, #929, #1012, #845, #840, #817, #1008, #988, #1009, #1001, #918, #987, #945, #1014, #960, #885, #853, #854, #855, #811, #971, #972, #875, #1020, #1034, #1035, #1006, #1017; #826, #788, #886, #1033 closed as already done or not a fault.
Decisions D-129 to D-180 recorded 11-12 November (D-178: network addresses kept and handled as personal data; D-180: an imported date is decided as it stands now).

**Merged locally, not yet pushed (whole suite running):** #1004 (strict money amounts), #1003 + #880 (deprecated PHP functions, byte ranges), #1021 + #1031 (settings row rule, deleted asset), #824 + #865 (discipleship sweep, offline page). Docs and wiring for these are uncommitted on the branch.

## In flight (side by side, no shared files — the 12 October 2026 rule)

| Package | Worktree / branch | State |
|---|---|---|
| #982 (+ #922, #926, #973) — page messages | `p982-build` | Round 9 building (rehire wording now facts only) |
| #1028, #1025, #1024 — time faults | `p1028-build` | Round 4 building: pin "live zones first" in `zonesInUse()`; correct five docblocks about the ELSE |
| #1013, #999, #820 — money | builder af9dac2d | Building |
| #967, #991, #815 — settings | builder a65b7de9 | Building |
| #866, #1039 | builder a620a293 | Building |

**Fix list still to build (D-156):** #1019 and #1044 (privacy; after #982, which shares Logger/GdprEraser), #1037, #813, #1015, #1018, #801, #848, #954, #970, #990/#1007, #1041, #1045, #1029/#1030, #1036, #1040, #1042, #1043, #1047, #1048. Decisions to build: #808 (D-158), #847 (D-159), #863 (D-160), #910 (D-161), #930 (D-162), #938 (D-164), #934 (D-166), #939 (D-167), #952 (D-168), #965 (D-169), #986 (D-170), #989 (D-171), #992 (D-172), #993 (D-173).
Flaky under load (#1046): sign-in-limits 0b and public-website-tick's sign-in — re-run alone before calling red.

**Migration numbers taken:** through 287. 280, 281, 284, 285 unused. Next free: 288.
**The migration harness takes `E2E_NAME_SUFFIX` and `E2E_HOST_PORT` (#923); the whole-suite runner does not yet (#983).**

## Next, in this order

1. Push the four merged packages once the suite is green; land the five in flight.
2. The fix list and decision builds above.
3. The 5 November backlog (D-055): #777, #569, #821, then medium and low.
4. #754 (waits on the merge). 5. The Codex review (D-007), then the merge.

## Working pattern that is paying off

Builders and checkers run in their own worktrees and their own scratch folders under /tmp/claude-501/<pkg>/
(never the shared session scratchpad — one builder overwrote another's files there). The commissioning session
cherry-picks a clean package, writes its docs FROM THE FINAL STATE (builders' first drafts carry claims later
rounds corrected), runs the whole suite, pushes, closes the issue. Brief checkers neutrally. When the same item
fails two rounds running, simplify (P4d's sentences, #620's and #902's static checks — D-065: presence only,
behaviour proven over HTTP). A round whose findings are all in tooling counts toward the three-round cap (D-064).
Recurring findings: a refusal tested on only ONE form of address; a test that passes on broken code; an existence
leak (403 vs 404) on one endpoint of a class — enumerate the class.

## Blocked on the owner

| What | Why |
|---|---|
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

Every decision lives once, in `docs/decisions/`, one file each (D-027); the README there is the index. Count
them from the folder. **Codex has reviewed none of this work.**

## What is open

The issue tracker is the record (`gh issue list --state open`); by D-055 every open issue is cleared before the
first release. Raised this stretch: #892-#899, #901, #904-#911, #913, #914, #916-#919, #941-#1020.
