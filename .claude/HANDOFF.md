# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 1 November 2026.*

## Branch

`claude/alpha-wip` at **`95f6232`**. **15 commits unpushed**, 577 ahead of
`alpha`. Working tree clean. The owner reserves pushes, pull requests and
merges: nothing is pushed.

## Just landed

**`95f6232` — #666, #616, #683.** Switching "Event reminders" off now stops the
day-before and one-hour reminder e-mails; the day-of summary no longer crosses
organisations; a scheduled job's e-mail footer takes its organisation's name and
support address. All three issues closed and labelled `status: done`.

Three independent check rounds by fresh agents, standing in for Codex. **Codex
has not reviewed any of this branch.** 18 findings, 16 stood, 2 refuted. The
code was right from round 1; rounds 2 and 3 found test-coverage and wording
faults of mine. The lessons are in DEV_NOTES.md under "A preference that was
saved and read by nothing" — the useful ones being that a plant which will not
go red may mean the *fixture* is dead, that one account holding several flags
cannot tell those flags apart, and that a two-character bracket move lifts a
whole `WHERE` clause with `php -l` clean.

## Next, in order

1. **#635** — needs roughly 35 owner decisions before it can start.
2. **#582**, then **#586**, then **#583g**.
3. **#685 / #491 / #492.2 / #481**, then **#620 / #596.3 / #588.1**.
4. **#649 / #596.7**, then **#541.2 / #703 / #539 / #619**, then
   **#476 / #146 / #637**.
5. **#474 / #678** — owner's own items.
6. **#705 — PHP 8.6**, new today. See below.
7. **The one comprehensive Codex review of the whole branch**, split across its
   resets. Only after 1–6. No pull request before it is done.
8. Merge.

## Waiting on the owner

- **#705, PHP 8.6** (due 19 November 2026, RC5 on the 5th). Two decisions: does
  8.6 become a supported runtime for the first release and does
  `PhpRuntime::PREFERRED_SERIES` move to 8.6 or stay 8.5; and when does 8.4
  support end. The second is what unlocks 8.6's new *syntax* — partial function
  application and readonly property defaults are parse errors on 8.4 and 8.5, so
  they cannot be used behind a runtime guard. DreamHost offers up to 8.5 today.
  Measured and recorded on the issue: **none of 8.6's deprecations is used
  anywhere in this repository**, there is no `return` inside any `finally` block,
  and the session-default change is already a no-op because the portal sets
  `httponly` and `samesite` itself. **Dated action with a security consequence:**
  when 8.6.0 final ships, `PhpRuntime`'s 8.6 pin must move off the pre-release,
  because a finished release of a newer line counts as carrying the PHP2Shell fix
  and a pre-release does not (#640).
- **#635** — about 35 decisions, listed on the issue.

## Known weak points on this branch

- Codex has reviewed none of it.
- `tools/access-pages-selftest.php` must run **alone**: it counts through
  `mysql.general_log`, which is server-wide (#689).
- `~/.claude/settings.json` has no `permissions` and no `env` block, so the
  written rules on force-push, `docker prune` and the six-agent ceiling are what
  bind — not a deny rule (#678).
- Nothing exercises the over-20,000-rows path or the could-not-read path in the
  backup rescue (#694 / #697).
- The two giving-retention save paths are covered by no automatic test (#699).

## How to verify this branch

```bash
bash tools/run-all-selftests.sh          # ~15 min, three MySQL servers, 66 tests
for c in tools/audit-checks/check_*.py; do python3 "$c"; done
python3 tools/audit-checks/check_schema_seed_parity.py   # READ it: exits 0 with a missing mark
```

Last full run: **66 of 66 green**, four consecutive times. 46 audit scripts
green. Parity: 0 missing marks, 0 missing seeds.
