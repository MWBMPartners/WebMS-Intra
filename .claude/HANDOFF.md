# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 10 November 2026.*

## Branch

`claude/alpha-wip`, pushed after every commit (D-088). Latest pushed: 29e33d77 (#902). Landed this stretch:
#887 (MySQL 9.7 sign-in), #493 P4a and P4b, #869/#870/#871 (expense claim form), #900 (a sign-in keeps nothing
of the previous person's session), #902 (its guard; passkey and kiosk driven). Whole suite 100/100.

## In flight (side by side, no shared files — the 12 October 2026 rule)

| Package | Worktree / branch | State |
|---|---|---|
| #493 P4c — the frameable page | `agent-a11d2cd63347a435e` / `p4c-build`, ed4aad97 | Round 2 check running (protected arm) |
| #903 — passkey RP ID empty on a fresh install | `agent-a59d23f3de8a73c08` / `p903-build` | Being built (protected arm) |
| #615 — outbound webhooks to private addresses | `agent-ad58911c67b8b8018` / `p615-build` | Being built (protected arm) |

Each worktree's `.claude-work/evidence/<pkg>/notes.md` holds its doc entries and any workflow wiring.
**P4c's browser test needs PHP in pr-security.yml's `security` job** (its notes recommend a separate job).

## Next, in this order

1. P4c to commit, then **P4d** (D-087 card; the check confirming a public address reaches this door).
2. The rest of the backlog (D-055: everything before the release), highest priority first, up to three at a
   time where files do not overlap; one package at a time in `web/_sql/`.
3. #821 items 4-8. 4. #754 (waits on the merge). 5. The Codex review (D-007), then the merge.

## Working pattern that is paying off

Builders and checkers run in their own worktrees; the commissioning session cherry-picks a clean package, adds
its docs from the worktree's notes, runs the whole suite, pushes. Brief checkers neutrally (not with the
builder's conclusions). When the same item fails two rounds running, simplify (P3's verdict, #649's
after-commit path, #902's static check). A round whose findings are all in tooling counts toward the three-round
cap (D-064). Recurring finding: a refusal tested on only ONE form of address — test both.

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
first release. New ones this stretch: #892-#899, #901, #903, #904.
