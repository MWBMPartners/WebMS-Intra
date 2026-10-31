# Handoff — where the work is right now

> **OVERWRITTEN, never appended** (#678). Current state only, about one screen.
> What shipped is in `CHANGELOG.md`, the lessons in `DEV_NOTES.md`, and anything
> not-done has a GitHub issue. Never cite a document by line number — cite issue
> numbers and commit hashes.

**Branch:** `claude/alpha-wip`. **Nothing is pushed**, and nothing may be: the
owner reserves pushes, pull requests and merges, and no pull request is raised
until the one comprehensive Codex review of the whole branch. "It is only the
working branch" is not a reason to push.

## #694 is committed

Restoring a backup taken before 27 October 2026 silently lost every
organisation's own settings rows and everybody's two-factor backup codes to
`ON DELETE CASCADE`. They are now kept in `tblRestoreHeldRows` (migration 233)
and the Backup page offers them back or discards them. No timer — a waiting row
waits until an administrator clears it (the owner, 31 October 2026); once it is
back only the record remains, with the copy emptied.

**Six independent check rounds found 23 real problems.** Every one is fixed and
recorded. The behaviour was stable from round 2; rounds 3-6 were almost entirely
untrue sentences, numbers typed rather than established, and corrections recorded
but applied in one place. The four lessons worth carrying to the next package are
in `DEV_NOTES.md`:

1. **Read the class before adding a helper to it** — `columnFacts()` already knew
   that `EXTRA LIKE '%GENERATED%'` is wrong, a few hundred lines from where I
   wrote a worse copy.
2. **"Measured, not reasoned" is only worth writing if the measurement is of the
   thing the sentence is about.** I measured a connect error and wrote it against
   a claim about statement calls.
3. **When a sentence is built from branches, spell out the whole sentence for each
   branch** — `DbBackup::cascadeWarning()` holds eight; the ninety-line expression
   it replaced produced four of six wording faults.
4. **A hedge that covers one condition and asserts the other is not a hedge.**

## Verification, all against the committed tree

- `php -l` on every changed PHP file; every `tools/audit-checks/check_*.py`, with
  the parity check's output READ (231 migrations, 231 marks, 0 missing).
- `tools/run-all-selftests.sh` — **66 tests, 0 not green**.
- `tools/e2e-migrations/run.sh` — **all seven phases, all 42 checks**, MySQL
  8.0.36. **Not** run on 8.4.11 or MariaDB 11.4.13; the pull-request check does
  all three, so that is still owed from CI.
- **Codex has not reviewed any of this.** Six fresh Claude agents stood in.

## Next

1. **#687** — the donor-retention cap. The owner's answers are on the issue:
   opt-in, off by default; an installation global administrator OR an
   organisation's own global admin may set one; an organisation cap LOCKS its own
   figure; where an installation cap exists an organisation may only go LOWER; a
   cap below an organisation's stored figure is REFUSED, naming who blocks it.
   Organisations already over a new cap are the #685 shape and need their own
   issue.
2. Then the open list.

## Waiting on the owner

- **#697 is the pointed one**: restoring a snapshot taken before some rows were
  kept REPLACES them, and those waiting rows are the only copy outside the
  snapshot being restored from. The restore WARNS, before and after. A warning is
  not a fix; four options and a recommendation are on the issue.
- Also #688, #685, #681, #679, #564, #689, and the `~/.claude/settings.json`
  guards (#678). None blocks anything above.
