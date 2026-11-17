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

`claude/alpha-wip`, pushed after each landing. HEAD `85ae1438` (pushed 17 November). Landed and pushed 15-17 November:
#808, #1064 (merge accounts, migrations 297 and 299), #993 (exchange rates 301, spend in the provider's own currency
302), #575, #1066, #1095, #1088 (migration 303), #539, #939, #606 (items 1, 2, 4, 5), #1096, plus decisions D-186 to D-209.
Migration numbers in use: 290, 292, 294-303 (291, 293 never existed). **After the pilot is installed, every schema
change needs a numbered migration again and the migration harness must upgrade from the installed commit (D-135 ends).**

## Waiting on the owner

- **2g (#934, consent-record clear-out)** needs two answers: a visitor's cookie-choice record is never "replaced"
  (recommended: when its cookie runs out, 365 days); the portal does not record when a member left (recommended:
  "leaves" means the account is erased, already deleted at once; an issue for the rest).
- **Q-2e-1:** the privacy notice's "on" wording for "we issue tax receipts" was built on the recommended answer (the
  giving statement is the receipt) and awaits confirmation.
- Later, for the real-host rehearsal: a spare DreamHost subdomain and empty database; and how pilot zips are numbered
  (D-024 keeps `version.php` at 1.4.1, so two pilot zips carry one number: measure whether Admin, Migrations still runs a
  new migration with equal versions).

## In flight

- **2b-ii** (#1092 part two: the robot check's hosts in the page rule on four forms plus `salvation/card.php`): Sonnet
  builder running on `p2bii-build`; then an Opus check (max three rounds).

## Next, in this order

1. **#1073** (D-208: linking never creates an account) and **#1067** (D-209: an erasure removes remembered map positions
   matching the person's own address): plan (Opus), build (Sonnet), check (Opus, max three rounds). #1067 touches
   GdprEraser and Geocoder.
2. **2g (#934)** once the owner answers (protected arm; migration 304 only if needed).
3. Verification before the install, in order: the whole suite; the migration harness on all engines; a zip built on a
   clean worktree (`bash tools/package/build.sh <commit> beta <empty folder>`, then `tools/package/package-selftest.sh`);
   confirm each paid service ships off; an install rehearsal locally then on the spare subdomain, walking the first-use
   journey (sign in, organisation settings, invite two members, events, noticeboard, a gift and statement, backups,
   privacy pages, My data, one erasure); an upgrade rehearsal. Then the install (the owner's action).
4. After the pilot: the backlog (D-055: #777 before 28 March 2027, #1077/#1078 within months, #591, #1082), #754, the
   full Codex review (about 40 allowances for the branch; about 5 for the sign-in/erasure/money/backup core and the
   installer), then the merge.

## Raised, not built (selection; the tracker is the record)

#1097 (an address changed during an erasure run), #1098-#1105 (spend limits: own-limit page, per-currency maxima,
"Pence" keys, other billing currencies, unverified prices, removed organisations' typed rates, month-boundary test, limits
not exact under two simultaneous requests), #1106 (regenerate the noticeboard bundle with a Babel fingerprint),
#1107/#1108 (care case: edit who it is about; the picker lists every member), #1109-#1111 (header overrides, custom
live embed, empty test database password), #1112/#1113 (events API mark; home event coordinates), #1114-#1118
(invitation races, wording, #606 item 3), #1119 (sign-in redirect double-encodes an address).

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
| SIGNula.id | Five local commits waiting for the owner's push approval |
| 2g answers, Q-2e-1 | See above |

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
