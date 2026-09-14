#!/bin/sh
# Path: tools/audit-checks/fixtures/static_calls/review3/r3_03_wrapper/fake-php.sh
#
# Third review, finding 3: a stand-in for `php`, used ONLY by
# tools/static-calls-selftest.php to test the Python wrapper
# (tools/audit-checks/check_static_calls.py) against outputs the real check
# does not normally produce: a crash, or a failure that says nothing useful.
#
# The self-test copies this file into a temporary folder under the name `php`,
# makes it executable, and puts that folder first on PATH, so the wrapper
# finds it instead of real PHP. Copying it there, rather than relying on this
# file's own executable bit, means the test does not depend on how the
# repository was checked out. It deliberately does not end in ".php": it is
# not PHP, and the self-test lints every ".php" file in the fixtures folder.
#
# The wrapper passes the path of check_static_calls.php as the first
# argument; this stand-in ignores it. SC_FAKE_PHP_MODE picks what to print.
# "\342\200\242" is the bullet character, written as its three UTF-8 bytes so
# this file stays plain ASCII.

case "$SC_FAKE_PHP_MODE" in
    crash_stdout_no_newline)
        # A crash message with no line ending, on standard output.
        printf 'Fatal error'
        exit 7
        ;;
    crash_stderr_no_newline)
        # The same, on standard error. The workflow merges the two streams.
        printf 'Fatal error' >&2
        exit 7
        ;;
    exit1_mid_line_bullet)
        # Exit code 1 ("findings"), but the only bullet is in the middle of a
        # line, where the workflow's second filter will not keep it.
        printf 'error mentions \342\200\242 in middle\n'
        exit 1
        ;;
    exit1_bullet_split_across_streams)
        # A properly formed finding line on standard error, straight after
        # standard output text with no line ending. Once the workflow merges
        # the two, the bullet is no longer at the start of its line.
        printf 'partial'
        printf '  \342\200\242 web/x.php:3 - Site::name() - no such method\n' >&2
        exit 1
        ;;
    exit1_real_finding)
        # A genuine findings run: must pass through unchanged, with nothing
        # added.
        printf '### Calls to a method that does not exist\n\n  \342\200\242 web/x.php:3 - Site::name() - no such method\n'
        exit 1
        ;;
    clean)
        # A genuine clean run: must pass through unchanged, with nothing added.
        printf 'check_static_calls: OK - nothing to report\n'
        exit 0
        ;;
    *)
        printf 'fake-php.sh: unknown SC_FAKE_PHP_MODE "%s"\n' "$SC_FAKE_PHP_MODE" >&2
        exit 99
        ;;
esac
