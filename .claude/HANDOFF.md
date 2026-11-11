# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 11 November 2026. Updated at every commit boundary and every
change of a package's state (the owner, 10 November 2026: "update our handoff document regularly at each stage").*

## Branch

`claude/alpha-wip`, pushed after every commit (D-088). Landed since 9 November, all pushed and closed: #887,
#493 (P4a-P4d, the whole public door), #869-#871, #900, #902, #903, #615, #620, #912, #648 (+ #915), #598, #529 (+ #917, #921), #782, #932, #703, #785, #935, #936, #940, #895, #947 (+ #937, #894, #911), #923, #943, #809, #867, #891 (+ #892, #951), #882 (+ #898).
Last whole suite (with all of these): 106/106 green; e2e all phases passed. Decisions D-129 to D-134 recorded on 11 November.

## In flight (side by side, no shared files — the 12 October 2026 rule)

| Package | Worktree / branch | State |
|---|---|---|
| #942 — no country built in; installer asks, suggested from the browser's time zone; each organisation its own (D-133; migration 279 if needed) | `p942-build` | Building |
| #859 — an approved event suggestion e-mails its suggester (D-134; migration 280 if needed) | `p859-build` | Building |
| #941 + #927 — erasure: a cleared kept-gifts row; the expense-claims label that reassures falsely (migration 281 if needed) | `p941-build` | Building (protected arm) |

Each worktree's `.claude-work/evidence/<pkg>/notes.md` holds its doc entries and any workflow wiring.
**Migration numbers taken:** 269-278 landed; 279 (#942), 280 (#859), 281 (#941). Next free: 282.
**The migration harness takes `E2E_NAME_SUFFIX` and `E2E_HOST_PORT` (#923):** give every side-by-side run its own.
**Keep each agent's scripts in its own `/tmp/claude-501/<pkg>/` folder** — the shared session scratchpad was overwritten once.

## Next, in this order

1. Finish the three in flight (check → integrate → whole suite → push).
2. The rest of the backlog (D-055: everything before the release), highest priority first, up to three builders
   at a time where files do not overlap; one migration number per package, assigned up front.
3. #821 items 4-8. 4. #754 (waits on the merge). 5. The Codex review (D-007), then the merge.

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
first release. Raised this stretch: #892-#899, #901, #904-#911, #913, #914, #916-#919, #941-#962.
