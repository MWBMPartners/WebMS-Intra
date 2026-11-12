#!/usr/bin/env python3
"""
No money is turned from text into hundredths through a float (#1004) — hard gate.

Path: tools/audit-checks/check_no_float_money.py

    python3 -I tools/audit-checks/check_no_float_money.py              run the check
    python3 -I tools/audit-checks/check_no_float_money.py --self-test  run its planted faults

WHAT IT GUARDS
--------------
Every money box used to turn its text into hundredths with
`(int) round((float) $typed * 100)`. A float holds about sixteen significant
figures and `(int)` of a float outside PHP's integer range is only a logged
warning, so an absurd amount WRAPPED: measured on PHP 8.5,
"184467440737095557.12" was stored as 40.96 and "1e17" came out negative.
`Portal\\Core\\Currency::parseAmount()` is now the ONE converter (and
`storedDecimalToHundredths()` the one reader of a stored DECIMAL). This check
fails when a NEW conversion of that shape appears anywhere under web/.

THE RULE (presence only, D-065: it reads code, it cannot run it)
-----------------------------------------------------------------
A PHP statement under web/ is reported when it multiplies by 100 (`* 100`,
`100 *`, `* 100.0`) AND, in the same statement, either
  - casts to a float: `(float)`, `(double)`, `(real)`, `floatval(`, `doubleval(`; or
  - reads a request value directly: `$_POST`, `$_GET`, `$_REQUEST`, `$_COOKIE`
    (PHP converts such text to a number by itself, through a float).
Statements are cut by PHP's own tokeniser (float-money-statements.php) at `;`,
`{` and `}`, with comments removed and string contents emptied, because every
handler #1004 changed QUOTES the old code in a comment explaining the fix, and
a text search would report the comment recording the repair.

A statement that must keep that shape is named in ALLOWED below by FILE and a
piece of its CODE, with the reason. The list FAILS BOTH WAYS: an entry that no
longer matches anything is reported, because a stale exception reads as a
guarantee. It is EMPTY today: nothing under web/ needs the shape.

WHAT IT CANNOT SEE, said plainly (measured by the self-test's "blind" cases):
  - the float made in one statement and multiplied in another
    (`$f = (float) $raw;` … `$p = (int) round($f * 100);`);
  - a multiplication by a variable or constant holding 100;
  - `bcmul()` or any other route that does not multiply by a literal 100;
  - JavaScript and markup (inline HTML is not PHP and is skipped);
  - whether a percentage that DOES cast to a float is money: such a statement
    is reported, and belongs in ALLOWED with its reason if it is not money.
The behaviour is driven by tools/money-amount-selftest.php; this check is the
tripwire that stops the pattern coming back one handler at a time.

Without `php` it REFUSES loudly (exit 2), never passes.
"""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))
HELPER = os.path.join(HERE, 'float-money-statements.php')

# file (relative to the repository) -> list of (a piece of the statement's code, the reason it may stay)
ALLOWED: dict[str, list[tuple[str, str]]] = {}

TIMES_100 = re.compile(r'\*\s*100(?:\.0*)?(?![0-9.])|(?<![0-9.$\w])100(?:\.0*)?\s*\*')
FLOAT_OR_REQUEST = re.compile(r'\(float\)|\b(?:floatval|doubleval)\s*\(|\$_(?:POST|GET|REQUEST|COOKIE)\b')


def statements(paths: list[str]) -> dict[str, list[list]]:
    """PHP's own reading of the files: {path: [[line, code], ...]}. Refuses without php."""
    php = shutil.which('php')
    if php is None:
        print('REFUSED: no `php` on the PATH. This check asks PHP\'s own tokeniser and has no second-best reading to fall back to.')
        sys.exit(2)
    out: dict[str, list[list]] = {}
    for i in range(0, len(paths), 200):
        chunk = paths[i:i + 200]
        run = subprocess.run([php, '-d', 'display_errors=stderr', HELPER, *chunk], capture_output=True, text=True, check=False)
        if run.returncode != 0:
            print('REFUSED: the PHP helper failed:\n' + run.stderr)
            sys.exit(2)
        out.update(json.loads(run.stdout))
    return out


def scan(paths: list[str], root: str, allowed: dict[str, list[tuple[str, str]]]) -> tuple[list[str], list[str], list[str]]:
    """Answers (findings, stale allow-list entries, files PHP could not read)."""
    found: list[str] = []
    used: set[tuple[str, str]] = set()
    unreadable: list[str] = []
    for path, stmts in statements(paths).items():
        rel = os.path.relpath(path, root).replace(os.sep, '/')
        if stmts is None:
            unreadable.append(rel)
            continue
        for line, code in stmts:
            if TIMES_100.search(code) is None or FLOAT_OR_REQUEST.search(code) is None:
                continue
            excused = False
            for piece, _reason in allowed.get(rel, []):
                if piece in code:
                    used.add((rel, piece))
                    excused = True
            if excused is False:
                found.append(f'{rel}:{line}: {code[:200]}')
    stale = [f'{rel}: "{piece}"' for rel, entries in allowed.items() for piece, _ in entries if (rel, piece) not in used]
    return found, stale, unreadable


def php_files(root: str) -> list[str]:
    files = []
    for base, dirs, names in os.walk(os.path.join(root, 'web')):
        dirs[:] = [d for d in dirs if d not in ('_vendor', '_libraries', '_uploads', '_auth_keys')]
        for name in names:
            if name.endswith('.php'):
                files.append(os.path.join(base, name))
    return sorted(files)


def main() -> int:
    found, stale, unreadable = scan(php_files(REPO), REPO, ALLOWED)
    if unreadable:
        print('REFUSED: PHP could not read these files, so they were not checked:\n  ' + '\n  '.join(unreadable))
        return 2
    if found or stale:
        if found:
            print('Money turned from text into hundredths through a float (#1004). Use '
                  'Portal\\Core\\Currency::parseAmount() for a typed amount, or '
                  'Currency::storedDecimalToHundredths() for a stored DECIMAL; if this is not money, '
                  'name it in ALLOWED in this file with the reason:')
            for f in found:
                print('  ' + f)
        if stale:
            print('ALLOWED entries that no longer match any statement (a stale exception reads as a guarantee):')
            for s in stale:
                print('  ' + s)
        return 1
    print(f'OK: no money-from-text float conversion under web/ ({len(ALLOWED)} file(s) allowed by name).')
    return 0


SELF_TEST_CASES = [
    # (name, php source, should it be reported?)
    ('the issue\'s own line', "<?php\n$p = (int) round(((float) $v) * 100);\n", True),
    ('without the extra brackets', "<?php\n$p = (int) round((float) $v * 100);\n", True),
    ('floatval', "<?php\n$p = (int) (floatval($v) * 100);\n", True),
    ('(double), 100 first', "<?php\n$p = 100 * (double) $raw;\n", True),
    ('* 100.0', "<?php\n$p = (int) round((float) $v * 100.0);\n", True),
    ('a request value, no cast', "<?php\n$p = (int) round($_POST['amount'] * 100);\n", True),
    ('inside a closure', "<?php\n$f = static fn (string $v): int => (int) round(((float) $v) * 100);\n", True),
    ('in a return inside a function', "<?php\nfunction f($v) {\n    return (int) round(((float) $v) * 100);\n}\n", True),
    ('quoted in a // comment', "<?php\n// was (int) round((float) $v * 100)\n$p = 1;\n", False),
    ('quoted in a docblock', "<?php\n/** (int) round((float) $v * 100) */\n$p = 1;\n", False),
    ('quoted in a string', "<?php\n$s = '(int) round((float) $v * 100)';\n", False),
    ('a percentage with no cast', "<?php\n$pct = (int) round(($a / $b) * 100);\n", False),
    ('a float cast times 1000', "<?php\n$x = (int) ((float) $v * 1000);\n", False),
    ('a float cast times 10', "<?php\n$x = (float) $v * 10;\n", False),
    ('a cast and a * 100 in two statements (a stated blind spot)', "<?php\n$f = (float) $v;\n$p = (int) round($f * 100);\n", False),
    ('times 1001', "<?php\n$x = (float) $v * 1001;\n", False),
]


def self_test() -> int:
    bad = 0
    with tempfile.TemporaryDirectory() as tmp:
        web = os.path.join(tmp, 'web')
        os.makedirs(web)
        paths = []
        for i, (_name, src, _want) in enumerate(SELF_TEST_CASES):
            p = os.path.join(web, f'case{i:02d}.php')
            with open(p, 'w', encoding='utf-8') as fh:
                fh.write(src)
            paths.append(p)
        found, stale, unreadable = scan(paths, tmp, {})
        hit = {f.split(':', 1)[0] for f in found}
        for i, (name, _src, want) in enumerate(SELF_TEST_CASES):
            got = f'web/case{i:02d}.php' in hit
            ok = got is want
            bad += 0 if ok else 1
            print(('  ✓ ' if ok else '  ✗ ') + f'{name}: ' + ('reported' if got else 'not reported') + ('' if ok else f' (wanted {"reported" if want else "not reported"})'))
        # The allow-list: an entry excuses its statement, and a stale entry is reported.
        allow = {'web/case00.php': [('round(((float) $v) * 100)', 'test')], 'web/case11.php': [('never present', 'test')]}
        found, stale, unreadable = scan(paths, tmp, allow)
        excused = not any(f.startswith('web/case00.php:') for f in found)
        print(('  ✓ ' if excused else '  ✗ ') + 'an ALLOWED entry excuses the statement it names')
        stale_ok = stale == ['web/case11.php: "never present"']
        print(('  ✓ ' if stale_ok else '  ✗ ') + 'an ALLOWED entry that matches nothing is reported as stale')
        broken = os.path.join(web, 'broken.php')
        with open(broken, 'w', encoding='utf-8') as fh:
            fh.write("<?php\n$p = (int) round(((float) $v * 100);\n")
        _f, _s, unreadable = scan([broken], tmp, {})
        unread_ok = unreadable == ['web/broken.php']
        print(('  ✓ ' if unread_ok else '  ✗ ') + 'a file PHP cannot parse is reported as unreadable, never passed')
        bad += (0 if excused else 1) + (0 if stale_ok else 1) + (0 if unread_ok else 1)
    total = len(SELF_TEST_CASES) + 3
    print(f'{total} cases: {total - bad} behaved as expected, {bad} did not.')
    return 0 if bad == 0 else 1


if __name__ == '__main__':
    if '--self-test' in sys.argv[1:]:
        sys.exit(self_test())
    sys.exit(main())
