#!/usr/bin/env python3
# Path: tools/audit-checks/check_snapshot_one_moment.py
# -----------------------------------------------------------------------------
# A snapshot is read at ONE moment, and says so 🧊 (#581)
# -----------------------------------------------------------------------------
# @package   WebMS-Intra (development tooling — never deployed; only web/ is)
# @author    MWBM Partners Ltd (t/a MWservices)
# @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
# @license   All Rights Reserved
# @link      https://github.com/MWBMPartners/WebMS-Intra/issues/581
#
# WHAT WENT WRONG, AND WHY ONE DATABASE SERVER CANNOT CATCH IT COMING BACK
#   `DbBackup::snapshot()` reads the tables one after another. Without one
#   consistent view of the whole database, a record saved while the snapshot
#   runs can have one half copied and not the other — a snapshot of a database
#   that never existed, which the restore refuses partway with a message about a
#   row pointing at a row that is not in the snapshot.
#
#   Two lines give that view, and the FIRST of them is the one that does the
#   work on a host whose own setting is READ COMMITTED:
#
#       $this->ask('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
#       $this->ask('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
#
#   Measured on 3 November 2026, on `mysql:8.0.36` started with
#   `--transaction-isolation=READ-COMMITTED`: deleting the first line alone made
#   every snapshot stop being taken at one moment. A parent row saved into a
#   table the snapshot had already read, and its child into one it had not,
#   produced a snapshot holding the child and not the parent; the snapshot still
#   answered "success"; and the restore then refused it. On a server whose
#   setting is REPEATABLE READ — MySQL's own default, and what EVERY self-test
#   in this repository runs on — that same deletion changes NOTHING, and proof
#   11 of tools/backup-restore-selftest.php stays green. That is the #646
#   situation: a correct guard over a fault that has already happened, with
#   nothing able to run it.
#
# THE DECOY, because it is the obvious thing to reach for and it is wrong.
#   `SELECT @@transaction_isolation` does NOT answer "is this transaction
#   consistent". Measured on the same READ-COMMITTED server: inside a
#   transaction that the SET line had already put right, and which really did
#   give a consistent view, that variable still answered READ-COMMITTED —
#   because `SET TRANSACTION` with no scope word sets the NEXT transaction only
#   and never touches the session value the variable reports. A check or a
#   guard built on it would condemn a working snapshot on every such host. The
#   statement's own WARNING is the only thing measured to answer the real
#   question: MySQL says `Warning 138: InnoDB: WITH CONSISTENT SNAPSHOT was
#   ignored because this phrase can only be used with REPEATABLE READ isolation
#   level.`
#
# WHAT IT CHECKS
#   A. The two statements are both in `snapshot()`, in that order, spelled
#      exactly — not "the words appear somewhere in the file".
#   B. The verdict is asked for IMMEDIATELY after them, and written into the
#      manifest as `one_moment`.
#   C. `consistentReadVerdict()` has EXACTLY the body named below. Not "it
#      mentions SHOW WARNINGS": this repository has been caught twice by a check
#      that looked for the TEXT of a guard and stayed green when `&& false` was
#      added to its condition. Any edit, even a harmless one, fails this check
#      on purpose — which is the moment somebody has to think about it.
#   D. Nothing in DbBackup.php decides anything from `@@transaction_isolation`.
#      Comments are blanked before this is asked, so the measurement recorded in
#      the comments above consistentReadVerdict() is not itself a finding.
#   E. `restoreAll()` repeats the answer to whoever is putting the snapshot
#      back, through the one sentence-maker.
#   F. `oneMomentWarning()` really holds a complete sentence for each of the
#      five branches that can be reached — the #694 rule, "when a sentence is
#      built from branches, spell out the whole sentence for each branch".
#   G. The scheduled backup job records that it was called AFTER the token check
#      and BEFORE both the "switched off" answer and the one-run-at-a-time lock.
#      Both matter: before the token check, a stranger could make the page
#      claim the scheduler is working; after the "switched off" answer, an
#      installation that has chosen `off` could never find out whether its
#      scheduler was calling. There is no third clause about the lock, because
#      planting proved one could never fail on its own — see the note beside
#      it. What is checked instead is that the lock still comes after that
#      answer, which is what makes the lock ordering follow.
#   H. The Backup page's colour comes from `DbBackup::scheduleIsRunning()` and
#      not from reading how the sentence starts, which is how the first version
#      of that page did it.
#
# WHAT IT CANNOT SEE, said plainly rather than left to be discovered:
#   - Whether the portal really is consistent on any particular server. Only a
#     server set to READ COMMITTED proves that, and nothing in this repository
#     runs one. Section 29 of tools/backup-restore-selftest.php reproduces the
#     fault on ONE server instead, by taking the isolation level away from the
#     backup's own connection.
#   - Anything about MariaDB. Measured on 11.4.13: it prints NO warning when it
#     ignores the phrase, so the portal's answer there is always "yes" and means
#     only "nothing was said". What was NOT measured is whether MariaDB's read
#     is really inconsistent in that case.
#   - Whether the SENTENCES are true or well written. Nothing judges prose.
#   - A statement built by concatenation or held in a variable.
# -----------------------------------------------------------------------------

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BACKUP_CLASS = ROOT / "web" / "_core" / "DbBackup.php"
CRON_JOB = ROOT / "web" / "_apps" / "cron" / "backup-run.php"
BACKUP_PAGE = ROOT / "web" / "_apps" / "admin" / "maintenance" / "backup.php"
MANIFEST_SCHEMA = ROOT / "web" / "_core" / "data" / "backup-manifest.schema.json"

SET_LEVEL = "$this->ask('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');"
START_READ = "$this->ask('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');"
ASK_VERDICT = "$oneMoment = $this->consistentReadVerdict();"
WRITE_VERDICT = "'one_moment'     => $oneMoment,"

# The verdict method's body, comments out and whitespace collapsed.
EXPECTED_VERDICT_BODY = (
    "try { $answer = $this->db->query('SHOW WARNINGS'); } "
    "catch (\\Throwable $e) { return self::ONE_MOMENT_CANNOT_TELL; } "
    "if ($answer === false) { return self::ONE_MOMENT_CANNOT_TELL; } "
    "$said = []; while (($row = $answer->fetch_assoc()) !== null) { "
    "$said[] = (string) ($row['Message'] ?? ''); } "
    "$answer->free(); "
    "if ($said === []) { return self::ONE_MOMENT_YES; } "
    "foreach ($said as $message) { "
    "if (stripos($message, 'CONSISTENT SNAPSHOT') !== false) { return self::ONE_MOMENT_NO; } } "
    "return self::ONE_MOMENT_CANNOT_TELL;"
)

# The five branches oneMomentWarning() must each answer with a whole sentence.
# One line per reachable pair; there is no not-recorded/taking case, and
# ONE_MOMENT_YES has no sentence on purpose.
EXPECTED_BRANCHES = [
    "$verdict === self::ONE_MOMENT_NOT_RECORDED && $restoring === true",
    "$verdict === self::ONE_MOMENT_NO && $restoring === false",
    "$verdict === self::ONE_MOMENT_NO && $restoring === true",
    "$verdict === self::ONE_MOMENT_CANNOT_TELL && $restoring === false",
    "$verdict === self::ONE_MOMENT_CANNOT_TELL && $restoring === true",
]


def mask_comments(php: str) -> str:
    """Blank every comment, keeping the file's length and its line breaks.

    Length and newlines are preserved so reported line numbers stay right and a
    `//` inside a string literal (a web address) cannot eat the rest of a line.
    Learned twice over in this repository: #716's guard was wrong because a
    `//` inside a string ate a line, and deleting block comments outright put
    reported line numbers 370 out.
    """
    out = []
    i = 0
    n = len(php)
    while i < n:
        two = php[i:i + 2]
        if two == "/*":
            end = php.find("*/", i + 2)
            end = n if end == -1 else end + 2
            out.append("".join("\n" if c == "\n" else " " for c in php[i:end]))
            i = end
            continue
        if two == "//" or php[i] == "#":
            end = php.find("\n", i)
            end = n if end == -1 else end
            out.append(" " * (end - i))
            i = end
            continue
        if php[i] in "'\"":
            quote = php[i]
            out.append(php[i])
            i += 1
            while i < n:
                if php[i] == "\\" and i + 1 < n:
                    out.append(php[i:i + 2])
                    i += 2
                    continue
                out.append(php[i])
                if php[i] == quote:
                    i += 1
                    break
                i += 1
            continue
        out.append(php[i])
        i += 1
    return "".join(out)


def collapse(text: str) -> str:
    """Comments out, runs of whitespace down to one space."""
    return re.sub(r"\s+", " ", mask_comments(text)).strip()


def body_of(source: str, name: str) -> str | None:
    """The body of one method of DbBackup, by name, to its closing brace."""
    found = re.search(
        r"function\s+" + re.escape(name) + r"\s*\([^)]*\)\s*:[^{]*\{(.*?)\n    \}",
        source,
        flags=re.S,
    )
    return None if found is None else found.group(1)


def main() -> int:
    problems: list[str] = []

    for path in (BACKUP_CLASS, CRON_JOB, BACKUP_PAGE, MANIFEST_SCHEMA):
        if path.is_file() is False:
            print("FAIL: " + str(path.relative_to(ROOT)) + " is missing, so nothing could be checked.")
            return 1

    source = BACKUP_CLASS.read_text(encoding="utf-8")
    masked = mask_comments(source)

    # ---- A and B: the two statements, in order, and the verdict after them ---
    snapshot_body = body_of(source, "snapshot")
    if snapshot_body is None:
        problems.append("web/_core/DbBackup.php — snapshot() was not found, so the consistent read could not be checked.")
    else:
        places = [snapshot_body.find(SET_LEVEL), snapshot_body.find(START_READ), snapshot_body.find(ASK_VERDICT)]
        wanted = [
            ("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ", SET_LEVEL),
            ("START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY", START_READ),
            ("the verdict is asked for", ASK_VERDICT),
        ]
        for (what, text), at in zip(wanted, places):
            if at == -1:
                problems.append(
                    "web/_core/DbBackup.php — snapshot() no longer contains, exactly: " + text
                    + "  Without it a snapshot on a host whose own isolation level is READ COMMITTED is "
                    "read table by table and can hold half a record, while still answering success "
                    "(measured, 3 November 2026)."
                )
        if -1 not in places and places != sorted(places):
            problems.append(
                "web/_core/DbBackup.php — snapshot() has those three statements in the wrong order. The "
                "isolation level must be set BEFORE the transaction starts (it applies to the next "
                "transaction only), and the verdict can only be read AFTER it."
            )
        if WRITE_VERDICT not in snapshot_body:
            problems.append(
                "web/_core/DbBackup.php — snapshot() no longer writes the answer into the manifest as "
                "`one_moment`. Whoever puts the snapshot back is not usually whoever took it, and the "
                "manifest is the only thing that reaches them."
            )

    # ---- C: the verdict method's whole body ---------------------------------
    verdict_body = body_of(source, "consistentReadVerdict")
    if verdict_body is None:
        problems.append(
            "web/_core/DbBackup.php — consistentReadVerdict() was not found. It is the ONE place that "
            "answers whether the database really gave a consistent view."
        )
    elif collapse(verdict_body) != EXPECTED_VERDICT_BODY:
        problems.append(
            "web/_core/DbBackup.php — consistentReadVerdict()'s body is not the one this check expects. "
            "Any edit fails here on purpose: it is a dozen lines, it decides what every snapshot on disk "
            "claims about itself, and a deliberate change means updating the expected text in this file in "
            "the same commit.\n      expected: " + EXPECTED_VERDICT_BODY
            + "\n      found:    " + collapse(verdict_body)
        )

    # ---- D: the decoy ------------------------------------------------------
    for found in re.finditer(r"@@transaction_isolation", masked):
        line = masked[: found.start()].count("\n") + 1
        problems.append(
            "web/_core/DbBackup.php:" + str(line) + " reads @@transaction_isolation. That variable does "
            "NOT answer whether a transaction is consistent: measured on a READ-COMMITTED server, it "
            "still says READ-COMMITTED inside a transaction the SET line had already put right and which "
            "really did give a consistent view, because `SET TRANSACTION` with no scope word sets the "
            "next transaction only. Read the statement's own warning instead."
        )

    # ---- E: the restore repeats the answer ---------------------------------
    restore_body = body_of(source, "restoreAllWork")
    if restore_body is None:
        problems.append("web/_core/DbBackup.php — restoreAllWork() was not found.")
    elif "self::oneMomentWarning(self::oneMomentIn($manifest), true)" not in restore_body:
        problems.append(
            "web/_core/DbBackup.php — restoreAllWork() no longer tells the person putting a snapshot back "
            "whether it was read at one moment. It is expected to call, exactly: "
            "self::oneMomentWarning(self::oneMomentIn($manifest), true)"
        )

    # ---- F: a whole sentence for every reachable branch --------------------
    warning_body = body_of(source, "oneMomentWarning")
    if warning_body is None:
        problems.append("web/_core/DbBackup.php — oneMomentWarning() was not found.")
    else:
        flat = collapse(warning_body)
        for branch in EXPECTED_BRANCHES:
            if re.sub(r"\s+", " ", branch) not in flat:
                problems.append(
                    "web/_core/DbBackup.php — oneMomentWarning() no longer answers the branch `" + branch
                    + "`. Five branches can be reached and each needs its own whole sentence: #694 "
                    "collected four wording faults inside ONE assembled sentence, every one a ternary in "
                    "the middle of a clause and not one of them findable by any check."
                )
        returns = len(re.findall(r"\breturn\b", flat))
        if returns < 6:
            problems.append(
                "web/_core/DbBackup.php — oneMomentWarning() has " + str(returns) + " returns. Six are "
                "expected: one complete sentence for each of the five reachable branches, and the empty "
                "answer for ONE_MOMENT_YES, which deliberately says nothing at all."
            )

    # ---- G: the job records that it was called, in the right place ----------
    job = mask_comments(CRON_JOB.read_text(encoding="utf-8"))
    at_record = job.find("->recordScheduledCall()")
    at_token = job.find("hash_equals($expected, $supplied)")
    at_off = job.find("'reason'    => 'scheduled backups are switched off'")
    at_lock = job.find("flock($lockHandle")
    if at_record == -1:
        problems.append(
            "web/_apps/cron/backup-run.php does not call recordScheduledCall(). Nothing then knows whether "
            "the hosting company's scheduler is calling the job, and the Backup page shows a schedule "
            "nobody set up as though it were running. The setting ships as `daily`, so that is every new "
            "installation."
        )
    else:
        if at_token == -1 or at_record < at_token:
            problems.append(
                "web/_apps/cron/backup-run.php records the call BEFORE the token is checked, so a stranger "
                "who guesses the address could make the Backup page claim the scheduler is working."
            )
        if at_off != -1 and at_record > at_off:
            problems.append(
                "web/_apps/cron/backup-run.php records the call AFTER the 'switched off' answer, so an "
                "installation that has chosen `off` can never be told that its scheduler IS calling — the "
                "only way to find out would be to switch backups on."
            )
        # ⚠️ THERE IS NO "before the lock" CLAUSE HERE, AND THAT IS DELIBERATE.
        #    The first version had one, and PLANTING PROVED IT COULD NEVER FAIL
        #    ON ITS OWN: the lock sits after the "switched off" answer in this
        #    job, so a call recorded after the lock is already a call recorded
        #    after that answer, and the clause above fires first. A clause that
        #    cannot fail makes a check LOOK stronger than it is — four were
        #    found in #694's package across five rounds. What IS checked instead
        #    is the thing that makes the lock ordering follow: that the lock
        #    really does come after the switched-off answer. That clause CAN
        #    fail, and if it ever does the "before the lock" requirement needs
        #    a clause of its own again.
        if at_off != -1 and at_lock != -1 and at_lock < at_off:
            problems.append(
                "web/_apps/cron/backup-run.php now takes the one-run-at-a-time lock BEFORE it answers "
                "'switched off'. Nothing above still guarantees that the call is recorded before the "
                "lock, so a second overlapping call may no longer count as a call. Either put the lock "
                "back after that answer, or add a clause here comparing the call with the lock."
            )

    # ---- H: the page's colour comes from the one test -----------------------
    page = mask_comments(BACKUP_PAGE.read_text(encoding="utf-8"))
    if "DbBackup::scheduleWords(" not in page:
        problems.append(
            "web/_apps/admin/maintenance/backup.php no longer shows DbBackup::scheduleWords(), so the page "
            "no longer says whether anything is calling the backup job (#581)."
        )
    if "DbBackup::scheduleIsRunning(" not in page:
        problems.append(
            "web/_apps/admin/maintenance/backup.php no longer asks DbBackup::scheduleIsRunning(). The "
            "colour of that answer must come from the same ONE test the words come from; the first version "
            "worked it out by looking at how the sentence STARTED, which would have gone wrong the first "
            "time anybody improved the wording."
        )
    if re.search(r"str_starts_with\s*\(\s*\$scheduleRunning", page) is not None:
        problems.append(
            "web/_apps/admin/maintenance/backup.php decides something by reading how the schedule sentence "
            "starts. Ask DbBackup::scheduleIsRunning() instead."
        )

    # ---- the manifest's description ----------------------------------------
    schema = MANIFEST_SCHEMA.read_text(encoding="utf-8")
    if '"one_moment"' not in schema:
        problems.append(
            "web/_core/data/backup-manifest.schema.json does not describe `one_moment`, and that object "
            "sets additionalProperties to false — so every manifest the portal writes would fail its own "
            "schema check."
        )
    else:
        for word in ("yes", "no", "cannot tell"):
            if '"' + word + '"' not in schema:
                problems.append(
                    "web/_core/data/backup-manifest.schema.json no longer lists `" + word + "` among the "
                    "answers `one_moment` can hold."
                )

    if problems:
        print("FAIL: the snapshot's one-moment read, or what it says about itself, is not as expected (#581).")
        for problem in problems:
            print("  - " + problem)
        print(
            "\n  Why this matters: the tables are read one after another, so without ONE consistent view a\n"
            "  snapshot can hold one half of a record and not the other - and it still answers success.\n"
            "  Measured on mysql:8.0.36 with --transaction-isolation=READ-COMMITTED: deleting one line\n"
            "  caused exactly that. No self-test here can see it, because they all run a server whose own\n"
            "  level is REPEATABLE READ, where that deletion changes nothing."
        )
        return 1

    print("OK: snapshot() asks the database for one consistent view, in the right order, and then asks")
    print("    whether it was really given; the answer is written into the manifest, described by the")
    print("    manifest's schema and repeated by the restore; nothing decides it from")
    print("    @@transaction_isolation, which is a decoy; the scheduled job records that it was called")
    print("    after the token and before both the switched-off answer and the lock; and the Backup page")
    print("    takes both its words and its colour from DbBackup (#581).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
