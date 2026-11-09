#!/usr/bin/env python3
# Path: tools/audit-checks/check_no_removed_sql_functions.py
"""
No SQL here may call a function a supported database no longer has (#887).

WHAT WENT WRONG
---------------
MySQL 9.7 has NO `MD5()`, `SHA1()` or `SHA()` functions at all. Measured on
mysql:9.7.4 on 9 November 2026:

    SELECT SHA1('x');   -> ERROR 1305 (42000): FUNCTION <db>.SHA1 does not exist
    SELECT MD5('x');    -> ERROR 1305 (42000): FUNCTION <db>.MD5 does not exist
    SELECT SHA('x');    -> ERROR 1305 (42000): FUNCTION <db>.SHA does not exist
    SELECT SHA2('x', 256);  -> works

Every named database lock in the portal was built with `SHA1(DATABASE())`,
so on MySQL 9.7 — a version `Portal\\Core\\DbServer` calls supported and the
migration harness runs on — every one of them failed:

  - the sign-in lock (`Auth::SIGN_IN_LOCK_SQL`): every password sign-in was
    refused with "Too many failed attempts" and no password was checked;
  - the mail queue's lock: the scheduled job threw on every run, and so did
    erasing an address from the queue;
  - the held-texts lock, the activity-log cleaner's lock and the event
    broadcast's lock.

Nothing noticed, because every test that drives those paths ran on MySQL 8.0
and 8.4, where the functions still exist. It was found only when two calendar
self-tests were pointed at 9.7 and could not sign in.

The portal now uses `LEFT(SHA2(…, 256), 40)`, which is forty characters
exactly as `SHA1()`'s answer was (so no lock name grows), and which works on
MySQL 8.0, 8.4 and 9.7 and on MariaDB 11.4 and 12.3, each measured.

WHAT THIS CHECKS
----------------
That none of the three names is CALLED in SQL anywhere it reads:

  - inside a PHP string literal (single- or double-quoted, heredoc or nowdoc)
    in any `.php` file under `web/` or `tools/`. Only string contents are
    read, so PHP's OWN `md5()` and `sha1()` functions — which are fine — are
    not reported, and neither is a comment explaining this fault;
  - anywhere in a `.sql` file under `web/`, with `--` and `/* */` comments
    taken out first;
  - anywhere in a `.sh` file under `tools/`, with whole-line `#` comments
    taken out first.

SQL ignores capitals in function names, so `sha1(` is caught as well as
`SHA1(`. `SHA2(` is not matched: the name must be followed by its bracket.

WHAT IT CANNOT SEE, SAID PLAINLY
--------------------------------
  - A function name built by joining strings (`'SH' . 'A1('`).
  - SQL held in a file type it does not read (Python, JavaScript).
  - In a `.sql` file, a call that sits on the same line AFTER a `--` that is
    inside a quoted value, because comments are taken out without tracking
    quotes. Nothing in this repository has that shape today.
  - Any OTHER function a future database version removes. The list below is
    what was MEASURED missing on 9.7.4 among the names this code uses; it is
    not a list of everything any version has ever removed. When a new engine
    joins a test matrix, probe the names the code uses on it (the method is in
    #887) and add what it lacks.

Exit code: 0 — nothing found; 1 — at least one call found.

Usage:
  python3 tools/audit-checks/check_no_removed_sql_functions.py
  python3 tools/audit-checks/check_no_removed_sql_functions.py --selftest
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

# Measured missing on mysql:9.7.4 (ERROR 1305), 9 November 2026 (#887).
REMOVED = ("MD5", "SHA1", "SHA")

# The name, then optional space, then its opening bracket. The look-behind
# keeps `SHA` from matching the tail of a longer name such as `XSHA(`. The
# look-ahead lets a regular expression held in a string through: `sha(?:256|…)`
# in tools/cdn-sri-selftest.php was reported by the first version, and SQL
# never writes `(?`.
CALL = re.compile(r"(?<![A-Za-z0-9_$])(" + "|".join(REMOVED) + r")\s*\((?!\?)", re.IGNORECASE)

# Folders never read: copies of other people's code, and generated output.
SKIP_PARTS = {"_vendor", "vendor", "node_modules", ".git"}

HEREDOC_START = re.compile(r"<<<[ \t]*(['\"]?)([A-Za-z_][A-Za-z0-9_]*)\1\r?\n")


def php_strings(src: str) -> list[tuple[int, str]]:
    """Every string literal's contents in a PHP file, with the line it starts on.

    Reads PHP mode only (between `<?php`/`<?=` and `?>`); inline HTML is not
    PHP and its quotes are attribute quotes. Comments are stepped over, and a
    `?>` inside a `//` or `#` comment ends PHP mode, as PHP itself does. `#[`
    is an attribute, not a comment.
    """
    out: list[tuple[int, str]] = []
    i, n = 0, len(src)
    in_php = False
    while i < n:
        if in_php is False:
            j = src.find("<?", i)
            if j < 0:
                break
            if src.startswith("<?php", j):
                i = j + 5
            elif src.startswith("<?=", j):
                i = j + 3
            else:
                i = j + 2
            in_php = True
            continue
        c = src[i]
        if src.startswith("?>", i):
            in_php = False
            i += 2
            continue
        if src.startswith("//", i) or (c == "#" and src.startswith("#[", i) is False):
            while i < n and src[i] != "\n" and src.startswith("?>", i) is False:
                i += 1
            continue
        if src.startswith("/*", i):
            j = src.find("*/", i + 2)
            i = n if j < 0 else j + 2
            continue
        m = HEREDOC_START.match(src, i)
        if m is not None:
            ident = m.group(2)
            body_start = m.end()
            end = re.compile(r"^[ \t]*" + re.escape(ident) + r"(?![A-Za-z0-9_])", re.MULTILINE)
            e = end.search(src, body_start)
            stop = n if e is None else e.start()
            out.append((src.count("\n", 0, body_start) + 1, src[body_start:stop]))
            i = n if e is None else e.end()
            continue
        if c in ("'", '"'):
            start = i
            i += 1
            while i < n and src[i] != c:
                i += 2 if src[i] == "\\" else 1
            out.append((src.count("\n", 0, start) + 1, src[start + 1:i]))
            i += 1
            continue
        i += 1
    return out


def sql_text(src: str) -> str:
    """A .sql file with `--` and `/* */` comments blanked, line breaks kept."""
    src = re.sub(r"/\*.*?\*/", lambda m: re.sub(r"[^\n]", " ", m.group(0)), src, flags=re.DOTALL)
    return re.sub(r"--[^\n]*", "", src)


def sh_text(src: str) -> str:
    """A shell script with whole-line `#` comments blanked."""
    return "\n".join("" if line.lstrip().startswith("#") else line for line in src.split("\n"))


def findings_in(name: str, src: str) -> list[tuple[int, str]]:
    """Every call found in one file's text: (line, the name called)."""
    found: list[tuple[int, str]] = []
    if name.endswith(".php"):
        for line, text in php_strings(src):
            for m in CALL.finditer(text):
                found.append((line + text.count("\n", 0, m.start()), m.group(1)))
    else:
        text = sql_text(src) if name.endswith(".sql") else sh_text(src)
        for m in CALL.finditer(text):
            found.append((text.count("\n", 0, m.start()) + 1, m.group(1)))
    return found


def files() -> list[Path]:
    picked: list[Path] = []
    for base, suffixes in ((REPO_ROOT / "web", (".php", ".sql")), (REPO_ROOT / "tools", (".php", ".sh"))):
        for p in sorted(base.rglob("*")):
            if p.is_file() and p.suffix in suffixes and SKIP_PARTS.isdisjoint(p.relative_to(REPO_ROOT).parts):
                picked.append(p)
    return picked


def selftest() -> int:
    """Cases each way. A check nobody has seen fail is not known to work."""
    must_find = [
        ("a.php", "<?php $q = \"SELECT GET_LOCK(CONCAT('x:', SHA1(DATABASE())), 0)\";"),
        ("a.php", "<?php $q = 'SELECT md5(name) FROM t';"),
        ("a.php", "<?php $q = 'SELECT sha (name) FROM t';"),
        ("a.php", "<?php $q = <<<SQL\nSELECT SHA1(x)\nSQL;\n"),
        ("a.php", "<?php $q = <<<'SQL'\nSELECT MD5(x)\nSQL;\n"),
        ("a.php", "<html><?= 'SHA1(' ?></html>"),
        ("a.sql", "INSERT INTO t VALUES (MD5('x'));"),
        ("a.sh", "mysql -e \"SELECT SHA1(DATABASE())\""),
    ]
    must_pass = [
        ("a.php", "<?php $h = sha1($x); $m = md5($y);"),                      # PHP's own functions
        ("a.php", "<?php // SHA1(DATABASE()) used to be here\n$q = 'SELECT 1';"),  # a comment
        ("a.php", "<?php /* MD5( */ $q = 'x';"),
        ("a.php", "<?php $q = \"SELECT LEFT(SHA2(DATABASE(), 256), 40)\";"),  # the replacement
        ("a.php", "<?php #[Attr] $q = 'SELECT 1'; # SHA1( in a comment\n"),
        ("a.php", "<p>SHA1( in inline HTML is not SQL</p><?php $x = 1;"),
        ("a.php", "<?php $q = 'SELECT XSHA(1)';"),
        ("a.sql", "-- SHA1( was used here once\nSELECT SHA2('x', 256); /* MD5( */"),
        ("a.sh", "# SHA1( in a comment\nshasum -a 256 file"),
        ("a.php", "<?php preg_match('/sha(?:256|384)-x/', $t);"),           # a regex, not SQL
    ]
    bad = 0
    for name, src in must_find:
        if not findings_in(name, src):
            print(f"SELFTEST FAIL: not found in {name}: {src!r}")
            bad += 1
    for name, src in must_pass:
        if findings_in(name, src):
            print(f"SELFTEST FAIL: wrongly found in {name}: {src!r}")
            bad += 1
    total = len(must_find) + len(must_pass)
    # Both counts pinned, so deleting a case is noticed (#709's lesson).
    if (len(must_find), len(must_pass)) != (8, 10):
        print(f"SELFTEST FAIL: expected 8 must-find and 10 must-pass cases, have {len(must_find)} and {len(must_pass)}")
        bad += 1
    print(f"selftest: all {total} cases right" if bad == 0 else f"selftest: {bad} problem(s)")
    return 1 if bad else 0


def main() -> int:
    if "--selftest" in sys.argv[1:]:
        return selftest()
    found = 0
    for p in files():
        try:
            src = p.read_text(encoding="utf-8", errors="replace")
        except OSError as exc:
            print(f"Could not read {p.relative_to(REPO_ROOT)}: {exc}")
            return 1
        for line, fn in findings_in(p.name, src):
            print(f"{p.relative_to(REPO_ROOT)}:{line}: SQL calls {fn.upper()}(), which MySQL 9.7 does not have. "
                  f"Use SHA2(…, 256) — LEFT(SHA2(…, 256), 40) where a 40-character answer is needed (#887).")
            found += 1
    if found:
        print(f"\n{found} call(s) to a function MySQL 9.7 removed.")
        return 1
    print(f"OK: no SQL here calls {', '.join(n + '()' for n in REMOVED)}, which MySQL 9.7 does not have.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
