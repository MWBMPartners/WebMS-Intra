# Handoff — where the work is right now

*Overwritten, never appended. Current state only. 2 November 2026.*

## Branch

`claude/alpha-wip` at **`0e0ddf4`**, pushed. 582 ahead of `alpha`.

**The owner gave standing approval on 2 November 2026 to push this working
branch after each commit** — never a release or protected branch, never a force
push, and no pull request until the Codex review is done. Everything else
(pull requests, merges, tags, releases) still needs their explicit say-so.

## In flight right now

**#706 — the personal-data catalogue is incomplete.** Planned and part drafted,
nothing applied to the tree yet. The owner's decisions of 2 November: ONE
package covering all of it, and a NARROW sweep rule with the judgement kept in
the catalogue. Drafts and measurements are in this session's scratchpad.

**The root cause is one level up from the issue.**
`tools/audit-checks/check_personal_data_coverage.py` already reads the schema
and is a green hard gate — but its detector is a hand-maintained allow-list of
LITERAL column names, so it only ever finds a column somebody already thought
of (it has `phone` anchored, so `phoneNumber` misses; `address`, so
`addressLine1` misses; `visitorIP`, so `createdIP` misses). **Extend that
check; do not add a second one.** Measured: 66 tables have no entry at all, 37
contact- or person-name-shaped columns are named nowhere (zero false positives
on the narrow rule), and 258 free-text columns are an inventory, not a gate.

## Just landed

- **`0e0ddf4` — #705, #627.** PHP 8.6 supported, measured against a real
  8.6.0RC4. `session.use_strict_mode` set explicitly; two `fclose()` return
  values checked in `BackupArchive` plus one pre-existing instance of the same
  class. **The issue's dated action is WRONG and was not done** — the 8.6 pin
  must NOT move to `8.6.0`, because the finished release already passes and
  moving it would warn every 8.6.0RC4 user. Nothing falls due on 19 November.
  The independent check was NOT CLEAN: two untrue claims of mine, both
  corrected (the backtick deprecation is 8.5's, not 8.6's; and the portal
  already rotated the session id at every sign-in, so strict mode does NOT
  replace those rotations).
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

1. **#706** (high) — in flight, see above.
2. **#707** — an audit-trail row *about* the erased person is reachable by
   nothing: `userID` is the actor and the subject is a table-and-id pair.
3. **#708** — three loose ends from reading the catalogue.
4. **#710** — a "dealt with by hand" action so a permanently-refusing erasure
   step can be closed. The owner chose this remedy on 2 November 2026.
5. **New, raised 2 November, all high/security and all found while gathering
   facts for #706:** **#712** raw IP addresses in two rate-limit tables that
   nothing ever clears (one table has an index literally named
   `idx_ratelimit_prune` and nothing prunes); **#713** payment webhook bodies
   stored in full BEFORE verification is acted on and never cleared, carrying
   donor name, e-mail and postal address, on a table with no foreign keys at
   all; **#714** three places an erasure cannot reach (a recording's
   transcript and its byte-for-byte search mirror, an expense receipt's
   original filename — where the parent is deliberately KEPT for HMRC so no
   cascade fires — and a workflow's subject label). Also **#711**,
   `Pdf::create()` trusting `is_file()` after an unchecked write.
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
- **#705's installer half is guarded by nothing**: the installer self-test
  sends only #509's malformed cookie, never a well-formed id naming no
  session, so deleting the installer's `ini_set` turns nothing red.
- **Neither new `fclose()` refusal branch in `BackupArchive` is executed by any
  test, and none can be** without a custom stream wrapper; nothing in `tools/`
  registers one.

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
