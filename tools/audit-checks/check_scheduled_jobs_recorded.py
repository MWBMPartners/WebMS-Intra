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
    further up, and ONE of them (`health.php`) ends inside a `catch` that
    RE-THROWS, where recording nothing is correct.

    (That said "two of them" in four places. Counted with PHP's tokeniser,
    `T_THROW` appears exactly once under `web/_apps/cron/` — `health.php` line
    321. The conclusion stands on the first half alone; the evidence was
    overstated, which is the shape this repository keeps recording.) A rule that cannot tell those apart cries
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
    The file's PHP CODE, with every comment blanked and every string literal's
    content blanked EXCEPT a `cron/...` job key, via PHP's own tokeniser.

    ⚠️ AN EARLIER VERSION OF THIS SENTENCE SAID "every string literal's
       CONTENTS removed" AND THE FUNCTION KEPT THEM. It re-quoted each literal
       with its content intact, and emitted heredoc bodies raw. That sentence
       is what made the bypass below invisible to anybody reading the file.

    ⚠️ A TEXT SEARCH CANNOT DO THIS JOB HERE. Every one of these pages
       describes its own token gate in a docblock -- "authenticates via
       ?key=<...>, constant-time hash_equals()" -- so a search for
       `hash_equals` finds the PROSE. The gate must be found in code or the
       whole of check C is decided by a comment.
    """
    # ⚠️ NO `<?php` HERE. `php -r` is already in PHP mode and refuses the tag
    #    with "unexpected token <", which looks exactly like the file under
    #    inspection being broken.
    # ⚠️ A STRING'S CONTENT IS BLANKED UNLESS IT IS A JOB KEY, and the first
    #    version kept every content — which defeated check C with ONE ordinary
    #    line. Measured: putting
    #
    #        $why = 'compared with hash_equals( so timing gives nothing away';
    #
    #    above `began()` in `retention-sweep.php` moved the start ABOVE the
    #    real token gate with the gate at exit 0, all 66 audit scripts green
    #    and the self-test at 83 of 83 — on the job whose own description is
    #    "clearing out personal data past the period this portal said it would
    #    keep it". Any internet request would then have written a start.
    #
    #    Only `cron/...` keys are kept, because that is the one thing the check
    #    reads out of a literal. Heredoc and interpolated bodies are blanked
    #    too: they are not T_CONSTANT_ENCAPSED_STRING, so the first version
    #    emitted them raw and a heredoc containing `hash_equals(` worked just
    #    as well.
    script = (
        '$t = token_get_all(file_get_contents($argv[1])); $out = "";'
        'foreach ($t as $x) { if (is_array($x)) {'
        'if (in_array($x[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML,'
        ' T_ENCAPSED_AND_WHITESPACE, T_START_HEREDOC, T_END_HEREDOC], true)) { '
        '$out .= str_repeat("\\n", substr_count($x[1], "\\n")); continue; }'
        'if ($x[0] === T_CONSTANT_ENCAPSED_STRING) {'
        ' $v = trim($x[1], "\'\\"");'
        ' $out .= preg_match("#^cron/[a-z0-9-]+$#", $v) === 1 ? "\'" . $v . "\'" : "\'\'";'
        ' continue; }'
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


def sql_without_comments(text: str) -> str:
    """
    The SQL with `--` and `/* */` comments blanked, **tracking quoted strings**
    so a `--` INSIDE a literal is left alone.

    ⚠️ THE OBVIOUS ONE-LINER IS WRONG, AND IT COST A REAL FAULT IN THIS SAME
       PACKAGE (#788). `re.sub(r"--[^\n]*", "", text)` chops from `--` to the
       end of the line whether or not it is inside a string — and
       `full_schema.sql` has NINE `COMMENT` strings containing ` -- `, one of
       which this package wrote. Each one loses its closing quote, so every
       following quote pairs up wrongly. In `check_schema_seed_parity.py` that
       made 37 migrations read as missing their mark while the check went
       blind to real drift in all of them. Same file, same shape, so this
       function does it properly.

    Newlines are preserved so reported positions stay meaningful.
    """
    out = []
    i, n = 0, len(text)
    quote = None
    while i < n:
        ch = text[i]
        if quote is not None:
            out.append(ch)
            if ch == "\\" and i + 1 < n:
                out.append(text[i + 1])
                i += 2
                continue
            if ch == quote:
                # A doubled quote is an escaped one and does not close.
                if i + 1 < n and text[i + 1] == quote:
                    out.append(quote)
                    i += 2
                    continue
                quote = None
            i += 1
            continue
        if ch in "'\"":
            quote = ch
            out.append(ch)
            i += 1
            continue
        if text.startswith("--", i):
            while i < n and text[i] != "\n":
                i += 1
            continue
        if text.startswith("/*", i):
            end = text.find("*/", i + 2)
            end = n if end == -1 else end + 2
            out.append("\n" * text.count("\n", i, end))
            i = end
            continue
        out.append(ch)
        i += 1
    return "".join(out)


def registered_cron_routes() -> set[str]:
    """
    The `cron/` addresses `full_schema.sql` really registers.

    ⚠️ NOT A SEARCH OVER THE WHOLE FILE, which is what this did first — in the
       check whose own docstring makes the point that a text search finds prose
       rather than code. `full_schema.sql` is eleven thousand lines of dense
       commentary, so one future comment mentioning a removed address, say

           -- An old address we removed in migration 99: 'cron/old-tidy' is gone.

       made this report a registered job that does not exist and BLOCKED EVERY
       PULL REQUEST until somebody added a `JOBS` entry for it. Measured. A
       check that cries wolf gets switched off (#483).

       It also ignored the statement KIND, so a route later removed by a
       `DELETE FROM tblRoutes` still read as registered — `seeded_switches()`'s
       measured fault in #675. Comments are blanked first, then only
       `INSERT INTO tblRoutes` statements are read, then any address a `DELETE`
       takes away is removed, in file order.
    """
    text = sql_without_comments(SCHEMA.read_text(encoding="utf-8"))
    routes: set[str] = set()
    for stmt in re.split(r";", text):
        lowered = stmt.lower()
        if "tblroutes" not in lowered:
            continue
        found = set(re.findall(r"'(cron/[a-z0-9-]+)'", stmt))
        if len(found) == 0:
            continue
        if re.search(r"\binsert\s+(?:ignore\s+)?into\b", lowered) is not None:
            routes |= found
        elif re.search(r"\bdelete\s+from\b", lowered) is not None:
            routes -= found
    return routes


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


def gate_refuses(code: str, gate_at: int) -> bool:
    """
    Whether the block holding the token comparison ENDS THE REQUEST.

    Walks forward from the comparison to the end of its `{ ... }` block and
    asks whether an `exit` or `die` appears inside it. Braces are matched on
    the masked code, so a `{` inside a message cannot swallow the rest of the
    function — this repository's recorded trap from the money pages.

    WHAT IT CANNOT SEE, stated rather than implied: a refusal that happens by
    some other means (a `return` at file scope, a thrown exception, a helper
    that exits), and a gate whose condition is inverted so the block runs on
    success. The first would be reported as a fault and is worth a loud
    failure rather than a silent pass; the second is behavioural, and section I
    of the self-test is what drives it.
    """
    opened = code.find("{", gate_at)
    if opened == -1:
        return False
    depth = 0
    for i in range(opened, len(code)):
        ch = code[i]
        if ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                block = code[opened:i]
                return re.search(r"\b(?:exit|die)\b", block) is not None
    return False

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

        # ── C. the gate comes FIRST, AND IT REFUSES. The security property.
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
        elif gate_refuses(code, min(gate)) is False:
            # ⚠️ A GATE MUST REFUSE, NOT MERELY EXIST. This repository has
            #    recorded that twice already (#668's section D, and
            #    gdpr-coverage-selftest.php: "Every gate must REFUSE, not
            #    merely exist") and this check shipped without it.
            #
            #    Measured: deleting `exit();` from the token block of
            #    `webhook-retry.php` left `php -l` clean and this check at exit
            #    0, printing the success line "with the start after its token
            #    gate" — while the self-test went to 80 passed, 3 FAILED, with
            #    I2, I4 and I6 ("and NOTHING was recorded") all red. That is an
            #    unauthenticated request writing the record.
            problems.append(
                "%s checks its token but the block that fails the check does not "
                "end the request — no exit() or die() before it closes. The "
                "comparison then decides nothing, so an unauthenticated request "
                "runs on and records that the job began." % path.name
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
