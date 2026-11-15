# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 15 November 2026. Updated at every commit boundary and every
change of a package's state (the owner, 10 November 2026: "update our handoff document regularly at each stage").*

## Branch

`claude/alpha-wip`, pushed after each landing (D-088; the owner approved pushing on 15 November). HEAD `93c81c87`.
Landed 15 November (all with independent Opus checks, docs written from the final state): #1018 #813 #970 #1015,
#1051 #1050 #1053, #848, #1030 #1048, #1037 #1036 #1029 (migration 290), #1042 #1049 (294), #1019 #938 #1065 (295),
#989 #992 (298), #847 #965 #952, #863 #910 #930 (296). Decisions recorded 15 November: D-186 (Claude checks and reviews
always run on Opus, here and machine-wide), D-187 to D-189 (network-address periods). Earlier landings are in the changelog.
Migration numbers in use: 290, 292, 294, 295, 296, 298. 291, 293, 297 free.

## Waiting on the owner

- **#993** (outside-provider spending counted in "pence" priced from US-dollar lists, D-173): where do exchange rates
  come from, and are the limits in the installation's or each organisation's currency? Recommended: an administrator
  types one rate per currency with its date, spending refused until set; limits in each organisation's own currency.
- **#1067** an erasure cannot remove a cached home position (D-183) and **#1070** which organisation an alert e-mail
  follows, **#1073** a signed-in member linking an identity that matches nobody gets a new account, **#1077/#1078** the
  Gift Aid and rate-limit address periods: decisions or builds still open, see each issue.

## In flight

Nothing was running at the time of writing; the next batch is being dispatched (see the table in the next update).

## Next, in this order

1. The fix list (D-156): #1044, #990/#1007, #1063 (one package: error rows, erasure and the data copy), #1064
   (merge accounts, D-185), #1080/#1081/#1084 (small-group and suggestion follow-ups), #808 (D-158), #934, #939, #986,
   #1016, #1065 done; then the decisions to build and issues #1049-#1084 raised this stretch needing triage.
2. The 5 November backlog (D-055): #777, #569, #821, then medium and low.
3. #754 (waits on the merge). 4. The Codex review (D-007), then the merge.
Flaky under load (#1046): sign-in-limits 0b, public-website-tick sign-in, erasure-pages O6/O19, port clashes in the
whole-suite runner (re-run alone before calling red).

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
