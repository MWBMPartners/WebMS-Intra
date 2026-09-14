#!/usr/bin/env python3
"""
Static-method call check — THIN WRAPPER over check_static_calls.php (#494).

WHY THIS FILE IS NOW ALMOST EMPTY
----------------------------------
This used to be a 1,486-line hand-written PHP lexer. An independent review
(Codex) reproduced ten separate faults against it — six real, working PHP
patterns it wrongly accused of being broken, and four genuinely broken
patterns it let straight through. Every one was a direct consequence of
hand-rolling a lexer instead of using PHP's own. The checking logic now lives
entirely in `check_static_calls.php`, built on PHP's own tokenizer, alongside
a much longer explanation of what changed and why (start there, not here).

WHAT THIS FILE STILL DOES
--------------------------
It exists only so the two things that depend on a `.py` file at this exact
path keep working:

  1. The loop `for f in tools/audit-checks/check_*.py` (this project's own
     convention for running every audit check in one go) still finds it.
  2. Step 20 of `.github/workflows/pr-security.yml` runs it as
        STATIC_CALLS=$(python3 tools/audit-checks/check_static_calls.py 2>&1 || true)
     and builds the pull-request comment ONLY from lines that contain the
     bullet "•" (and, for the section body, lines that start with optional
     spaces then "•", or with "###").

It runs `check_static_calls.php` and passes PHP's standard output, standard
error and exit code through unchanged. The one thing it adds, never removes:
whenever the check could not run, or did not finish normally, it prints one
extra line that starts "  • check_static_calls:" and says in plain English
what went wrong, and exits non-zero. That line is always written at the start
of a line. If the output so far does not end with a line ending, the wrapper
writes one first. That is the only change ever made to PHP's own output.

That extra line matters because of the `|| true` in step 20. It throws away
the exit code, so a failure that prints no "•" line produces NO comment
section at all — the pull request looks exactly like a clean run. That is the
"checks only cover what they read" trap this project has been caught by
before.

HOW IT RUNS PHP, AND WHY EACH DETAIL MATTERS
--------------------------------------------
  - BYTES, NOT TEXT. PHP's output is read and written as raw bytes. The
    previous version read it as text (`text=True`), which quietly turned
    every Windows line ending (CR LF) into a plain LF, and raised an uncaught
    UnicodeDecodeError on any byte that is not valid UTF-8. That error
    printed a Python traceback with no "•" in it, so step 20 hid it
    completely. Codex reproduced both.
  - A TIME LIMIT (TIMEOUT_SECONDS below). The previous version had none, so a
    stuck PHP process held the job until GitHub's own six-hour limit, and even
    then printed nothing step 20 would show. A normal run of the real
    codebase takes well under two seconds on a laptop, so the limit is
    generous on purpose: it exists to stop a hang, not to race a slow runner.
  - ITS OWN PROCESS GROUP, KILLED AS A WHOLE. Killing only the process this
    wrapper started leaves anything THAT process started still running, and
    still holding the output pipes open. Checked with a stand-in `php` shell
    script that starts a background `sleep`: plain `subprocess.run(timeout=2)`
    returned on time but left the `sleep` running; a copy of this wrapper that
    killed only the stand-in then sat reading the pipes until its 5-second
    read limit gave up (7.1 seconds in all), and also left the `sleep`
    running; this wrapper, which starts PHP in a new session and kills the
    whole group, returned in 2.1 seconds with nothing left behind. The read
    after the kill keeps its own short limit as a second guard, for anything
    that escaped the group.
  - EVERY FAILURE PRINTS A "•" LINE AND EXITS NON-ZERO: PHP missing from PATH,
    the PHP file missing, PHP failing to start (for example a permission
    error), the time limit, an exit code other than 0 or 1, an exit code 1
    with no line that step 20's second filter would keep, an exit code 0
    without the check's own "check_static_calls: OK" line, or any other
    unexpected error inside this wrapper.
  - THAT LINE ALWAYS STARTS A LINE OF ITS OWN, and "a finding line" means
    exactly what step 20's second filter keeps. A third review by Codex
    reproduced two ways a failure still vanished. (1) A crash message with no
    line ending ("Fatal error", exit 7, on either stream) had the failure line
    glued onto its end. Step 20's first filter saw the bullet, its second
    filter did not keep a line whose bullet is not at the start, and the
    comment showed nothing. (2) An exit code 1 whose only bullet sat in the
    middle of a line ("error mentions • in middle") counted as "has a
    finding", so no failure line was added, and again nothing was kept. Now a
    line ending is written before the failure line whenever the last byte
    written is not one. That line ending is the ONLY thing ever added to
    PHP's output. And the exit code 1 test uses the same pattern as step 20.
    tools/static-calls-selftest.php runs the wrapper with a stand-in `php`
    and step 20's own shell lines, to keep both proven.

WHAT IT CANNOT DO
-----------------
  - Keep the exact interleaving of standard output and standard error. The
    two are read separately, so output is written first, then errors. The
    PHP check writes everything to standard output in normal use.
  - Make step 20's `grep` cope with bytes that are not valid UTF-8. The
    wrapper passes such bytes through unchanged, as it must. Checked with GNU
    grep 3.11 under the C.UTF-8 locale GitHub's Ubuntu runners use: an invalid
    byte on some OTHER line does no harm (the wrapper's own "•" line after a
    crash message containing one was still copied), but if a line grep would
    copy contains one, grep prints "binary file matches" in place of that
    line. That is in the workflow file, not here, and the PHP check only
    prints such a byte inside a finding line if a file name contains one.

Usage:
  python3 tools/audit-checks/check_static_calls.py [same arguments as the PHP file]
"""

from __future__ import annotations

import os
import re
import shutil
import signal
import subprocess
import sys
from pathlib import Path

# The only two exit codes the real checking logic (check_static_calls.php)
# produces on purpose: 0 for a clean run, 1 for real findings. Anything else
# means it did not finish normally.
_EXPECTED_EXIT_CODES = (0, 1)

# How long PHP may run before it is treated as stuck. See the module docstring
# for why this is far above a normal run's time.
TIMEOUT_SECONDS = 120

# How long to wait for any remaining output after killing a stuck run.
_DRAIN_SECONDS = 5

# The PHP check prints this on every clean run (see sc_run() in
# check_static_calls.php). An exit code 0 WITHOUT it means something other
# than the check answered — a broken or substituted `php` — and must not pass
# as "nothing to report".
_CLEAN_RUN_MARKER = b"check_static_calls: OK"


# Step 20's SECOND filter, `grep -a -E '^[[:space:]]*•|^###'`, keeps a bullet
# line only when nothing but spaces comes before the bullet on that line. This
# is the same test in Python, applied to each line (MULTILINE makes `^` match
# after every "\n", and the space class leaves out "\n" so a match can never
# run across two lines).
#
# The space class is the six ASCII whitespace characters grep's [[:space:]]
# covers, minus "\n". grep under a UTF-8 locale may also accept some Unicode
# spaces. So this test can only ever be STRICTER than grep, never looser. The
# worst case is an unneeded extra failure line, never a hidden failure.
#
# Checking only "is there a bullet somewhere", as this file used to, was the
# third review's finding 3. A line with the bullet in the middle, like
# "error mentions • in middle", passes step 20's FIRST filter
# (`grep -a -q '•'`) but not its second, so nothing reached the comment.
_FINDING_LINE = re.compile(rb"^[ \t\v\f\r]*\xe2\x80\xa2", re.MULTILINE)

# The last byte written to each stream, or None before anything has been
# written. _report_failure() uses this to start its line on a line of its own.
_last_byte_written: dict[str, bytes | None] = {"stdout": None, "stderr": None}


def _write(stream_name: str, data: bytes) -> None:
    """Write bytes unchanged to stdout or stderr, and remember the last one."""
    if not data:
        return
    stream = sys.stdout if stream_name == "stdout" else sys.stderr
    stream.flush()
    stream.buffer.write(data)
    stream.buffer.flush()
    _last_byte_written[stream_name] = data[-1:]


def _report_failure(message: str) -> int:
    """
    Print one plain-English line that step 20's filter will copy into the
    pull-request comment, and return the exit code 1.

    The line must START a line, or step 20's second filter drops it. The
    previous version wrote it straight after whatever PHP had printed. After
    a crash message with no line ending, the merged output read
    "Fatal error  • check_static_calls: ...". That line passed step 20's first
    filter and failed its second, so the pull-request comment showed nothing
    at all. So when the last byte written to EITHER stream is not a line
    ending, a line ending is written first. Both streams matter because step
    20 merges them (`2>&1`), and this file writes standard error after
    standard output. That can leave one blank line in a view of standard
    output alone, which is harmless.
    """
    needs_line_break = any(last not in (None, b"\n") for last in _last_byte_written.values())
    prefix = "\n" if needs_line_break else ""
    _write("stdout", f"{prefix}  • check_static_calls: {message}\n".encode("utf-8"))
    return 1


def _pass_through(stdout: bytes, stderr: bytes) -> None:
    """Write PHP's own output back out, byte for byte."""
    _write("stdout", stdout)
    _write("stderr", stderr)


def _has_finding_line(output: bytes) -> bool:
    """True when step 20's second filter would keep at least one bullet line."""
    return _FINDING_LINE.search(output) is not None


def _kill_process_group(process: subprocess.Popen) -> None:
    """
    Kill PHP and anything it started. Falls back to killing just PHP where
    process groups do not exist (Windows), which is the best available there.
    """
    try:
        os.killpg(process.pid, signal.SIGKILL)
    except (AttributeError, OSError):
        process.kill()


def main() -> int:
    """
    Run check_static_calls.php (next to this file) and return its exit code,
    or 1 with a "•" line when it could not run or did not finish normally.
    """
    php_script = Path(__file__).resolve().parent / "check_static_calls.php"

    php_bin = shutil.which("php")
    if php_bin is None:
        return _report_failure(
            "php is not available on PATH, so the real checking logic "
            "(tools/audit-checks/check_static_calls.php) could not run at all. "
            "Install PHP or add it to PATH. Treated as a failure, not skipped, so "
            "a missing interpreter can never pass as 'nothing to report'."
        )

    if not php_script.is_file():
        return _report_failure(
            f"{php_script} is missing, so the checking logic itself is gone. "
            "Treated as a failure, not skipped."
        )

    try:
        process = subprocess.Popen(
            [php_bin, str(php_script), *sys.argv[1:]],
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            start_new_session=True,
        )
    except OSError as error:
        return _report_failure(
            f"PHP could not be started ({php_bin}): {type(error).__name__}: {error}. "
            "Nothing was checked."
        )

    try:
        stdout, stderr = process.communicate(timeout=TIMEOUT_SECONDS)
    except subprocess.TimeoutExpired:
        _kill_process_group(process)
        try:
            stdout, stderr = process.communicate(timeout=_DRAIN_SECONDS)
        except subprocess.TimeoutExpired:
            stdout, stderr = b"", b""
        _pass_through(stdout, stderr)
        return _report_failure(
            f"the check was still running after {TIMEOUT_SECONDS} seconds, so it was "
            "stopped. A normal run takes a few seconds, so it was almost certainly "
            "stuck. Nothing it had not already printed was checked."
        )

    _pass_through(stdout, stderr)
    code = process.returncode

    if code not in _EXPECTED_EXIT_CODES:
        # A PHP fatal error, an uncaught exception, exhausted memory, or being
        # killed by a signal (a negative code). The crash message itself has no
        # "•" in it, so without this line step 20 would show nothing.
        _report_failure(
            f"the checking logic exited with code {code}, not 0 (clean) or 1 "
            "(findings), so it almost certainly crashed rather than finished. See "
            "the raw output above, or the job log, for the crash itself."
        )
        return code if code > 0 else 1

    # Tested on standard output followed by standard error, which is the order
    # this file wrote them in, and so the order step 20's `2>&1` sees. Testing
    # each stream alone would miss a bullet line on standard error pushed
    # into the middle of a line by standard output text with no line ending.
    if code == 1 and not _has_finding_line(stdout + stderr):
        return _report_failure(
            "the checking logic exited with code 1 (findings) but printed no "
            "finding line the pull-request workflow would keep (one whose first "
            "character, after any spaces, is the bullet), so it failed without "
            "saying why. See the raw output above, or the job log."
        )

    if code == 0 and _CLEAN_RUN_MARKER not in stdout:
        return _report_failure(
            "PHP exited with code 0 but never printed the check's own "
            "'check_static_calls: OK' line, so whatever answered was not the real "
            "check running to the end. Treated as a failure, not as a clean run."
        )

    return code


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as error:  # noqa: BLE001 — every failure must be visible
        sys.exit(_report_failure(
            f"the wrapper itself failed unexpectedly: {type(error).__name__}: {error}"
        ))
