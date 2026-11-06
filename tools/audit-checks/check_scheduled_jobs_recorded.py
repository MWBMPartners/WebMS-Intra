#!/usr/bin/env python3
"""
Every scheduled job records that it ran, and records it AFTER its token gate.
=============================================================================

WHAT WENT WRONG (#775). Fifteen addresses under `cron/` are called by whatever
runs scheduled tasks on a customer's hosting, and until #775 only ONE of them
left any trace that it had run: the mail queue. So a portal could silently stop
sending reminders, stop clearing out personal data past its retention period,
or stop taking backups, and nothing anywhere would show it. No error, no log
line, no badge. The first sign was somebody asking why their reminders had
stopped.

It matters more than it looks because several of these jobs ARE the mechanism
behind a promise the portal makes. The retention sweep is how personal data
stops being kept; a portal whose sweep died six months ago is keeping records
it told people it would delete.

WHY A CHECK AND NOT JUST THE CODE. The recording is advisory by design --
`ScheduledJobs::began()` answers false rather than throwing, because a
recording that could stop the mail queue would have caused the very silence it
exists to reveal. **So a job that stops recording looks exactly like a job that
is running fine and simply has not been looked at.** Nothing at run time can
tell the difference, which is precisely the #775 shape one level up. This check
is what notices.

WHAT IT CHECKS, and each one has already been a real fault somewhere in this
repository:

  A  Every `cron/` address registered in `full_schema.sql` is in
     `ScheduledJobs::JOBS`, and every entry in `JOBS` is a registered address.
     FAILS BOTH WAYS. A page nobody listed records nothing; an entry naming no
     page reports "nothing is known yet" for ever about a job that cannot
     exist.

  B  Every one of those pages calls `began()` exactly once and `finished()`
     AT LEAST once, each with ITS OWN key. A page carrying a neighbour's key --
     easy to produce by copying the line, and this package wired fifteen files
     by script -- would make two jobs share one row, so one of them would read
     as healthy whenever the other ran.

     **At least one, not exactly one, and the first version of this check had
     that wrong.** Nine of the pages exit early when a setting switches them
     off, when another run holds the lock, or when nothing is due yet, and each
     such path records a finish of its own: without it, a job somebody had
     deliberately switched off read as "started and never finished" on the
     check page, as a problem, for ever.

  C  `began()` comes AFTER the token gate. THIS IS THE SECURITY PROPERTY: a
     request with the wrong token is a stranger knocking, and one that could
     write here would let anybody on the internet make a dead job look alive --
     turning this page from a true report into a forgeable one.

  D  `finished()` comes after `began()`. A page recording only a finish, or
     recording them the wrong way round, cannot show a job that died part-way,
     which is the one state that distinguishes "nobody is calling it" from "it
     is being called and failing".

WHAT IT CANNOT SEE, measured rather than assumed:

  * **Whether `finished()` is really at the END of the work.** It checks the
    ORDER of the two calls, not that the work sits between them. A
    `finished()` moved to just after `began()` would record every run as a
    success, and this check would pass. Nothing static can judge that; it needs
    a test that drives the page, which is why `tools/scheduled-jobs-selftest.php`
    exists and why this check is not the only guard.

  * **ONE exit path losing its finish**, which is a measured blind spot and
    not a hypothetical: it is the exact fault this package shipped first. Nine
    of these pages exit early when a setting switches them off, and each such
    path records a finish of its own. Because a page may legitimately hold
    several, this check requires only that it holds ONE -- so deleting the
    finish from the switched-off path alone leaves it green, while a job
    somebody deliberately switched off goes back to reading as "started and
    never finished", as a problem, for ever. Planted, and confirmed green.

    Requiring a finish before every `exit()` was tried and rejected: the
    NORMAL end of these pages is also an `exit()`, reached after a finish
    further up, and two of them end inside a `catch` that RE-THROWS, where
    recording nothing is correct. A rule that cannot tell those apart cries
    wolf, and a check that cries wolf gets switched off (#483). The property is
    behavioural, so section H of the self-test drives a switched-off job and
    reads the row back.
  * A key built by concatenation, or held in a variable.
  * A call inside `if (false)`, or a region made unreachable above it. That is
    the class D-065 settled: where the property is "this code RUNS", drive it,
    and the self-test does.

It reads the source with PHP's own tokeniser rather than searching text,
because every one of these pages QUOTES its own token gate in a docblock -- a
text search finds the comment and calls it a gate.
"""
from __future__ import annotations

import pathlib
import re
import subprocess
import sys

REPO = pathlib.Path(__file__).resolve().parents[2]
CRON = REPO / "web" / "_apps" / "cron"
SCHEMA = REPO / "web" / "_sql" / "full_schema.sql"
CLASS = REPO / "web" / "_core" / "ScheduledJobs.php"


class ProbeUnavailable(RuntimeError):
    """PHP could not be run, so nothing here was checked."""


def php_code_only(path: pathlib.Path) -> str:
    """
    The file's PHP CODE, with every comment and every string literal's CONTENTS
    removed, via PHP's own tokeniser.

    ⚠️ A TEXT SEARCH CANNOT DO THIS JOB HERE. Every one of these pages
       describes its own token gate in a docblock -- "authenticates via
       ?key=<...>, constant-time hash_equals()" -- so a search for
       `hash_equals` finds the PROSE. The gate must be found in code or the
       whole of check C is decided by a comment.
    """
    # ⚠️ NO `<?php` HERE. `php -r` is already in PHP mode and refuses the tag
    #    with "unexpected token <", which looks exactly like the file under
    #    inspection being broken.
    script = (
        '$t = token_get_all(file_get_contents($argv[1])); $out = "";'
        'foreach ($t as $x) { if (is_array($x)) {'
        'if (in_array($x[0], [T_COMMENT, T_DOC_COMMENT], true)) { '
        '$out .= str_repeat("\\n", substr_count($x[1], "\\n")); continue; }'
        'if ($x[0] === T_CONSTANT_ENCAPSED_STRING) { $out .= "\'" . trim($x[1], "\'\\"") . "\'"; continue; }'
        '$out .= $x[1]; } else { $out .= $x; } } echo $out;'
    )
    lint = subprocess.run(
        ["php", "-l", str(path)], capture_output=True, text=True
    )
    if lint.returncode != 0:
        # ⚠️ ASK `php -l` FIRST. `token_get_all()` does NOT fail on a file that
        #    will not parse -- it returns what it could make of it -- so a
        #    broken file would come back with no gate and no calls, and this
        #    check would report a fault it cannot actually see, or none at all.
        raise ProbeUnavailable("%s does not parse, so nothing was checked" % path.name)
    run = subprocess.run(
        ["php", "-r", script, str(path)], capture_output=True, text=True
    )
    if run.returncode != 0:
        raise ProbeUnavailable("php could not read %s" % path.name)
    return run.stdout


def registered_cron_routes() -> set[str]:
    """The `cron/` addresses `full_schema.sql` registers, read from the file."""
    text = SCHEMA.read_text(encoding="utf-8")
    return set(re.findall(r"'(cron/[a-z0-9-]+)'", text))


def listed_jobs() -> list[str]:
    """The keys of `ScheduledJobs::JOBS`, in the order they are written."""
    code = php_code_only(CLASS)
    start = code.find("JOBS=[")
    if start == -1:
        start = code.find("JOBS = [")
    if start == -1:
        raise ProbeUnavailable("ScheduledJobs::JOBS was not found")
    depth, end = 0, None
    for i in range(code.index("[", start), len(code)):
        if code[i] == "[":
            depth += 1
        elif code[i] == "]":
            depth -= 1
            if depth == 0:
                end = i
                break
    if end is None:
        raise ProbeUnavailable("ScheduledJobs::JOBS is not closed")
    block = code[start:end]
    return re.findall(r"'(cron/[a-z0-9-]+)'\s*=>", block)


def main() -> int:
    problems: list[str] = []

    try:
        routes = registered_cron_routes()
        jobs = listed_jobs()
    except ProbeUnavailable as exc:
        print("REFUSED: %s" % exc)
        print("Nothing was checked. This is not a pass.")
        return 1

    # ── A. the two lists must agree, both ways ────────────────────────────
    for missing in sorted(routes - set(jobs)):
        problems.append(
            "%s is a registered scheduled job but is not in ScheduledJobs::JOBS, "
            "so it records nothing and the Check this portal page can never "
            "say whether it has stopped (#775)." % missing
        )
    for extra in sorted(set(jobs) - routes):
        problems.append(
            "ScheduledJobs::JOBS names %s, which no route registers. The check "
            "page will report 'nothing is known yet' about it for ever, because "
            "there is no page to record anything." % extra
        )
    seen: set[str] = set()
    for key in jobs:
        if key in seen:
            problems.append("ScheduledJobs::JOBS names %s twice." % key)
        seen.add(key)

    # ── B, C, D. each page's own calls ────────────────────────────────────
    for key in sorted(routes & set(jobs)):
        name = key.split("/", 1)[1]
        path = CRON / ("%s.php" % name)
        if path.is_file() is False:
            problems.append("%s is registered but %s does not exist." % (key, path.name))
            continue
        try:
            code = php_code_only(path)
        except ProbeUnavailable as exc:
            print("REFUSED: %s" % exc)
            print("Nothing was checked. This is not a pass.")
            return 1

        # ⚠️ NOT `[^)]*?` FOR THE ARGUMENTS, and that mistake made this check
        #    report all fifteen pages as recording nothing while every one of
        #    them did. The first argument is `App::db()`, which CONTAINS a
        #    closing bracket, so a class excluding `)` can never reach the key
        #    after it. `[^;]` is the right exclusion: the statement's own end.
        began = [m.start() for m in re.finditer(
            r"ScheduledJobs::began\([^;]{0,160}?'%s'" % re.escape(key), code)]
        done = [m.start() for m in re.finditer(
            r"ScheduledJobs::finished\([^;]{0,160}?'%s'" % re.escape(key), code)]

        # A neighbour's key is the fault a copied line produces, so say so
        # by name rather than only reporting "no call found".
        others = [k for k in jobs if k != key
                  and re.search(r"ScheduledJobs::(?:began|finished)\([^;]{0,160}?'%s'" % re.escape(k), code)]
        for wrong in sorted(set(others)):
            problems.append(
                "%s records itself under %s. Two jobs sharing one row means one "
                "of them reads as healthy whenever the other runs." % (path.name, wrong)
            )

        if len(began) != 1:
            problems.append(
                "%s calls ScheduledJobs::began() with its own key %d times, not once. "
                "A job that records no start cannot be reported as stopped (#775)."
                % (path.name, len(began))
            )
        # ⚠️ SEVERAL FINISHES ARE RIGHT, AND REQUIRING EXACTLY ONE WAS WRONG.
        #    Nine of these pages exit early when a setting switches them off,
        #    when another run holds the lock, or when nothing is due, and each
        #    of those paths must record a finish of its own — otherwise a job
        #    working exactly as configured reads as having died part-way, for
        #    ever, as a problem on the check page. What is required is at least
        #    one, and that the FIRST comes after the start.
        if len(done) == 0:
            problems.append(
                "%s never calls ScheduledJobs::finished() with its own key. "
                "Without a finish, every run reads as having died part-way."
                % path.name
            )
        if len(began) != 1 or len(done) == 0:
            continue

        # ── C. the gate comes FIRST. The security property.
        gate = [m.start() for m in re.finditer(r"hash_equals\s*\(", code)]
        if len(gate) == 0:
            problems.append(
                "%s has no token gate in its CODE. Either it is unprotected, or "
                "the gate is only described in a comment." % path.name
            )
        elif began[0] < min(gate):
            problems.append(
                "%s records that the job began BEFORE its token is checked. "
                "Anybody on the internet could then make a job that has stopped "
                "look alive, which turns the Check this portal page from a true "
                "report into a forgeable one." % path.name
            )

        # ── D. the finish comes after the start.
        if done[0] < began[0]:
            problems.append(
                "%s records the finish before the start, so a run that died "
                "part-way cannot be told from one that completed." % path.name
            )

    if len(problems) > 0:
        print("Scheduled jobs: %d problem(s)." % len(problems))
        for line in problems:
            print("  - %s" % line)
        print()
        print("`Portal\\Core\\ScheduledJobs` is the ONE place a job's run is recorded")
        print("and read. See the note at the top of that file, and #775.")
        return 1

    print(
        "Scheduled jobs: all %d registered cron addresses are listed, and each "
        "records its own start and finish, with the start after its token gate."
        % len(routes)
    )
    print(
        "NOT CHECKED HERE, and said plainly: whether `finished()` really sits at "
        "the END of the work. Only the order of the two calls is checked, so a "
        "finish moved up next to the start would record every run as a success "
        "and pass. That needs a test that drives the page "
        "(tools/scheduled-jobs-selftest.php)."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
