#!/usr/bin/env python3
"""
CI guard — the mail library must not lean on the portal.

`web/_core/maillib/` is a mail library written so that it can later be lifted
out and used in other applications. That only works while NOTHING in it
reaches for the portal: no portal class, setting or constant, no database, no
request data. Everything it needs (settings, the clock, a log, the password)
is handed to it.

THIS FILE DOES NOT DO THE CHECKING. It starts the real check, which belongs to
the library and lives with the library's own tests:

    php tools/maillib/tests/stands-alone.php

and passes its answer on. It exists so that the check is found where every
other check of this repository is found, and runs with them.

WHY THE CHECK MOVED. It used to be here, in Python: a search of each line of
text for ten patterns. An independent check planted 27 ways of reaching
outside the library and it caught 7. The new one reads PHP's own tokens and
works from a list of what the library MAY use, so it had to be PHP; and it had
to live in the library's test folder, so that a copy of the library's two
folders can still run its whole test. Its header says what it checks, what it
cannot see, and how it proves itself on planted files before it is believed.

WHAT THIS WRAPPER CANNOT DO: run without PHP. If `php` is not found it FAILS
and says so; it never reports a pass for a check that did not run.

Exit code:
  0 — the real check ran, proved itself and found nothing.
  1 — a finding, the check failed its own proof, or PHP could not be started.
      (--strict is accepted and changes nothing: this check always fails on a
      finding.)

Usage:
  python3 tools/audit-checks/check_maillib_stands_alone.py [--strict]
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
REAL_CHECK = REPO_ROOT / "tools" / "maillib" / "tests" / "stands-alone.php"


def main() -> int:
    if [a for a in sys.argv[1:] if a != "--strict"]:
        print(__doc__)
        return 1
    php = shutil.which("php")
    if php is None:
        print("PHP was not found, so the stand-alone check of the mail library did NOT run. That is a failure, not a pass.")
        return 1
    if not REAL_CHECK.is_file():
        print(f"The real check is missing: {REAL_CHECK}. Nothing was checked, and that is a failure, not a pass.")
        return 1
    finished = subprocess.run([php, str(REAL_CHECK)], capture_output=True, text=True)
    print(finished.stdout, end="")
    print(finished.stderr, end="", file=sys.stderr)
    # A crash must not look like a pass: the real check's last line has to be there as well as exit code 0.
    if finished.returncode == 0 and "the check reached its end" not in finished.stdout:
        print("The real check ended with exit code 0 but never said it reached its end. Treated as a failure.")
        return 1
    return 0 if finished.returncode == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
