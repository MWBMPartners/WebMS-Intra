# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 12 November 2026. Updated at every commit boundary and every
change of a package's state (the owner, 10 November 2026: "update our handoff document regularly at each stage").*

## Branch

`claude/alpha-wip`, pushed after every commit (D-088). Landed and closed since 12 November: #1004, #1003, #880,
#1021, #1031, #824, #865, #1028, #1025, #1024, #1043, #1040, #1013, #999, #820, #967, #991, #815, #866, #1039,
#982 (+ #985), #922, #926, #973, #1045, #1047, #1041 (and everything listed in earlier handoffs).
Decisions recorded 12-13 November: D-180 to D-185 (D-181 event years 1900-2200+; D-182 map-memory period is a capped
setting; D-183 a home's remembered position is personal data, homes vs public places chosen; D-184 care cases in the
data copy; D-185 Microsoft/Google sign-in may create accounts by setting, default allow, plus a merge feature #1064 —
the owner says D-185 applies to SIGNula.id too: record it there when that repository is next worked on).

## In flight (no shared files between them)

| Package | Branch | State |
|---|---|---|
| #1037, #1036, #1029 — map memory (D-182, D-183) | `p1037-build` | Round 2 being checked |
| #1018, #813, #970, #1015 — erasure words, data copy, giving edges, care cases (D-184) | `p1018-build` | Round 2 building |
| #954, #801 — expense PDF, translation counter through a restore | `p954-build` | Round 2 being checked (migration 292) |
| #1051, #1050, #1053 — send-log lines | `p1051-build` | Round 1 being checked |
| #1049, #1042 — Users-page refusal wording; sign-in may create accounts (D-185) | `p1042-build` | Round 2 building (migration 294) |

Migration numbers: 289 (#1041, landed), 290 (#1037), 292 (#954), 294 (#1042). 291 and 293 free.

**Fix list still to build (D-156):** #1019, #1044, #1030, #848, #990/#1007, #1048, #1063, #1065 (with #938), #1064
(merge accounts, D-185), and the decisions to build: #808 (D-158), #847 (D-159), #863 (D-160), #910 (D-161),
#930 (D-162), #938 (D-164), #934 (D-166), #939 (D-167), #952 (D-168), #965 (D-169), #986 (D-170), #989 (D-171),
#992 (D-172), #993 (D-173). Raised this stretch and needing triage under D-156: #1049-#1062.
Flaky under load (#1046): sign-in-limits 0b, public-website-tick sign-in, erasure-pages O6/O19, and port clashes in
the whole-suite runner (re-run alone before calling red).

## Next, in this order

1. Land the five packages above as each comes back clean.
2. The fix list and decision builds.
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
