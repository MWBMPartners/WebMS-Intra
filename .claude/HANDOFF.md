# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 10 November 2026. Updated at every commit boundary and every
change of a package's state (the owner, 10 November 2026: "update our handoff document regularly at each stage").*

## Branch

`claude/alpha-wip`, pushed after every commit (D-088). Landed since 9 November, all pushed and closed: #887,
#493 (P4a-P4d, the whole public door), #869-#871, #900, #902, #903, #615, #620, #912, #648 (+ #915), #598, #529 (+ #917, #921).
Last whole suite (with #529): 103/103 green. Decisions taken this stretch: D-102 to D-119 (D-116 change history kept for ever; D-117 activity log may be kept for ever; D-118 an organisation's admin sets its own log period; D-119 lowering the installation's below an organisation's is refused).

## In flight (side by side, no shared files — the 12 October 2026 rule)

| Package | Worktree / branch | State |
|---|---|---|
| #703 — keep the worker's name on care/safeguarding/check-in records (D-016, D-110 to D-113; migration 272) | `agent-afd8a19e73dcfa23c` / `p703-build`, 87d01592 | Check round 8 NOT CLEAN (narrowing): keep-step refactor measured behaviour-neutral (old vs new byte-identical on 75 givers); outlook wrong for the 'due' case (past keep-until) and 'none of your gifts' ignores envelopes; tblUsers reason still groups statements with number-only records. Round 9 building |
| #932 — activity log may be kept for ever (D-117, D-118, D-119) | `agent-a0a937a0283ea6499` / `p932-build`, 154be792 | Round 2's findings fixed (installation 'never' with organisations present pinned; D-118 driven with a true organisation admin, section M; comments say 'for example'). Check round 3 running. Member wording after #703 |
| #782 — hard-coded Europe/London zones and two zone defaults (migration 273) | `agent-aacc4726f08c2aabc` / `p782-build`, 4e7b5813 | Clean: round 3 was a comment and gate fix, reviewed by me (no further round needed). Cherry-picked with docs; whole suite running, then push. Raised #935-#937 |

Each worktree's `.claude-work/evidence/<pkg>/notes.md` holds its doc entries and any workflow wiring.
**Migration numbers taken:** 269 (#648, landed), 270 (#598), 271 (#529), 272 (#703, if needed), 273 (#782). #932 needs none. Next free: 274.

## Next, in this order

1. #703 round 6 → check → integrate. #932 (activity log "never delete", D-117): code part may build now (sweep, retention page, retention-caps test — none of #703's files); the member-facing wording follows after #703.
2. Raised this stretch, in the backlog: #919, #920, #922-#930, #933-#939. #931 closed by D-116.
3. The rest of the backlog (D-055: everything before the release), highest priority first, up to three builders
   at a time where files do not overlap; one migration number per package, assigned up front.
4. #821 items 4-8. 5. #754 (waits on the merge). 6. The Codex review (D-007), then the merge.

## Working pattern that is paying off

Builders and checkers run in their own worktrees and their own scratch folders under /tmp/claude-501/<pkg>/
(never the shared session scratchpad — one builder overwrote another's files there). The commissioning session
cherry-picks a clean package, writes its docs FROM THE FINAL STATE (builders' first drafts carry claims later
rounds corrected), runs the whole suite, pushes, closes the issue. Brief checkers neutrally. When the same item
fails two rounds running, simplify (P4d's sentences, #620's and #902's static checks — D-065: presence only,
behaviour proven over HTTP). A round whose findings are all in tooling counts toward the three-round cap (D-064).
**The e2e migration harness has ONE fixed container name — never run it while another package might (#923); it corrupted #703's run once.** Recurring findings: a refusal tested on only ONE form of address; a test that passes on broken code; an existence
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
first release. Raised this stretch: #892-#899, #901, #904-#911, #913, #914, #916-#919.
