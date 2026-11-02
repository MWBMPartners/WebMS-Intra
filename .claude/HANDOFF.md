# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 2 November 2026.*

## Branch

`claude/alpha-wip` at **`a62872e`**, working tree clean. 580 ahead of `alpha`.

**The owner gave standing approval on 2 November 2026 to push this working
branch after each commit** — never a release or protected branch, never a force
push, and no pull request until the Codex review is done. Everything else
(pull requests, merges, tags, releases) still needs their explicit say-so.

## In flight right now

Nothing. #709 is committed and closed; the next package is #705.

## Just landed

- **`a62872e` — #709.** An erasure can now reach a row through its PARENT
  table: `tblCareVisit.notes` is emptied through `tblCareCase.personUserID`,
  the only instruction in the catalogue that matches that way.
  `whyParentMatchUnusable()` is the one test of whether an instruction may;
  `whereForEntry()` the one place the clause is written. The ordering trap was
  LIVE — the parent's step empties the column the look-up uses, so an
  instruction added in the obvious place would have reported "nothing held"
  into the sealed trail. Two guards: the coverage test pins the POSITION, and
  `whyParentLookUpUnsafe()` judges the RUN. That run-time guard was wrong
  twice in opposite directions; version 3 tells three cases apart and FAILS
  the step where it cannot be sure. Coverage 81 → 90, eraser 244 → 251.
  No database change, so no migration — the highest is still 232.
- **`17dce1b` — #635.** Every one of the 35 tables that named a personal
  column now has a decision and a written reason beside the column.
  `$leftBehindOnPurpose` is empty and pinned empty.
- **`95f6232` — #666, #616, #683.** The "Event reminders" switch now stops the
  reminder e-mails; the day-of summary no longer crosses organisations; a
  scheduled job's footer takes its own organisation's name and support address.

**Codex has reviewed none of this branch.** Each package has had an independent
check by a fresh Claude agent that did not build it, named in the commit
message as Codex's stand-in.

## Next, in order

1. **#705 — PHP 8.6.** Four owner decisions in hand: support 8.6 with
   `PREFERRED_SERIES` staying 8.5; `MIN_PHP` stays 8.4.0; move the 8.6 pin
   autonomously when 8.6.0 final ships on 19 November; and do both
   version-neutral fixes in the same package — audit the `fclose()` /
   `file_put_contents()` / `copy()` return values in the backup, archive and
   off-site paths, and set `session.use_strict_mode` explicitly (#627).
   Unmeasured: 23 `scandir()` / `pathinfo()` call sites against 8.6 turning
   some warnings into `ValueError`.
2. **#706** (high) — the personal-data catalogue is incomplete: personal
   columns that appear in no entry at all, so nothing can see them. Eleven
   tables already measured. Found by #635.
3. **#707** — an audit-trail row *about* the erased person is reachable by
   nothing: `userID` is the actor and the subject is a table-and-id pair.
4. **#708** — three loose ends from reading the catalogue.
5. **#710** — a "dealt with by hand" action so a permanently-refusing erasure
   step can be closed. The owner chose this remedy on 2 November 2026; see the
   issue for why the other two were rejected.
6. **#582** (the key-loss escape only), **#586**, **#583g**.
7. **#685 / #491 / #492.2 / #481**, then **#620 / #596.3 / #588.1**.
8. **#649 / #596.7**, then **#541.2 / #703 / #539 / #619**, then
   **#476 / #146 / #637**.
9. **#474 / #678** — the owner's own items. #474 (branch protection) goes on
   AFTER the merge, not before.
10. **The one comprehensive Codex review of the whole branch**, split across
    its resets. Only once 1–9 are done. No pull request before it is finished.
11. Merge.

## Waiting on the owner

Nothing. The seven decisions of 1 November, the four PHP 8.6 decisions and the
three of 2 November (#709's close-out route, #710's remedy, pushing this
branch) are all in hand.

## Known weak points on this branch

- Codex has reviewed none of it.
- `tools/access-pages-selftest.php` must run **alone**: it counts through
  `mysql.general_log`, which is server-wide (#689).
- `tools/gdpr-eraser-selftest.php` is **not idempotent** — it needs a freshly
  created database per run, or it fails on a duplicate primary key.
- `~/.claude/settings.json` has no `permissions` and no `env` block, so the
  written rules on force-push, `docker prune` and the six-agent ceiling are
  what bind — not a deny rule (#678).
- `tools/watchdog.sh` reads a symbolic link's own mtime, so a watchdog pointed
  at a link fires at once. Point `quiet` at the real file (#564).
- Nothing exercises the over-20,000-rows path or the could-not-read path in
  the backup rescue (#694 / #697).
- The two giving-retention save paths are covered by no automatic test (#699).
- Nothing covers the LIVE path through `processEntry()` with a real refusal
  (#709) — the audit line it writes and what an administrator sees. Reaching
  the ordering half needs a list the coverage test refuses outright, and the
  earlier-run half needs a trail a correct run never produces.

## How to verify this branch

```bash
bash tools/run-all-selftests.sh          # ~15 min, three MySQL servers, 66 tests
for c in tools/audit-checks/check_*.py; do python3 "$c"; done
python3 tools/audit-checks/check_schema_seed_parity.py   # READ it: exits 0 with a missing mark
```

Last full run: **66 of 66 green**. 46 audit scripts green. Parity: 0 missing
marks, 0 missing seeds.

## Docker hygiene

Check the dangling-volume count at the **end** of a session against the start,
not inside one agent's own window: `docker run --rm` plus `docker rm -f` is not
always enough, and on 2 November a 738 MB MySQL data volume survived a checker
that had honestly reported "1 before, 1 after". One dangling volume stands,
`f4c7e62…` from 30 October, which belongs to another project. Never prune.
