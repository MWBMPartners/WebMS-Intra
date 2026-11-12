#!/usr/bin/env python3
# Path: tools/audit-checks/check_worker_records_listed.py
"""
-----------------------------------------------------------------------------
Every record that KEEPS a worker's name is listed in that worker's own
download - and nothing else is (#929; D-016, D-110, D-111, D-142, D-151) 🧾
-----------------------------------------------------------------------------
WHAT IT GUARDS. Since #703 an erasure KEEPS the worker's account number (and,
beside it, their name) on the records they made: a pastoral visit, a care
case they opened, a DBS check they recorded, a child they checked in or out.
Each of those is one "retain" instruction in `GdprEraser::catalogue()` on a
column naming who DID something (`visitedByID`, `openedByID`, ...). D-142
says the worker's own download must LIST those records (kind and day only),
and `GdprEraser::recordsCarryingYourName()` is the ONE place that does.

The two are written in different places, so they can drift: a new kept-worker
column (a D-016-style decision) added to the catalogue and not to the list
would leave that worker's records out of their download again - #929's fault
re-created, silently. So this check fails BOTH ways:

  - a "retain" instruction whose column ends in `ByID` (who did it) that the
    list does not read, and
  - a (table, column) the list reads that is NOT such an instruction (a list
    reading a column the erasure does not keep would hand a worker records
    the portal does not, in fact, keep against their name).

HOW IT READS THEM, and why each way.
  - The catalogue is asked of PHP: the real `GdprEraser::catalogue()`, which
    needs no database (`tools/gdpr-coverage-selftest.php` does the same). A
    text search of the instruction list would miss the one written over
    several lines, and the derived instructions are not written there at all.
    No PHP, or a file that does not load: REFUSED, never "nothing wrong".
  - The list is read from the method's source: each kind's `'sql'` string
    pieces joined, its first `FROM `tbl...` alias`, and its ONE
    `WHERE alias.`column` = ?`. The alias must be the FROM table's, or the
    check cannot tell which table the column is on and REFUSES.

WHAT IT CANNOT SEE: whether the list's query RUNS, or what it hands over.
That is behaviour, and section O of `tools/erasure-pages-selftest.php` drives
it over HTTP (D-065). It also knows a worker's column only by its NAME ending
in `ByID` - all five kept today do (`visitedByID`, `openedByID`,
`recordedByID`, `checkedInByID`, `checkedOutByID`); a kept worker column named
otherwise (`helperID`) would pass unseen. This only pins that the two lists
name the same things.

Usage:
    python3 -I tools/audit-checks/check_worker_records_listed.py
    python3 -I tools/audit-checks/check_worker_records_listed.py --self-test

Exit: 0 when the two agree; 1 when they do not, or when either could not be
read.

@package   Portal\\Tools
@author    MWBM Partners Ltd (t/a MWservices)
@copyright 2026-present MWBM Partners Ltd (t/a MWservices)
@license   All Rights Reserved
@version   1.0.0
-----------------------------------------------------------------------------
"""
from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys
import tempfile

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
CORE = os.path.join(REPO, 'web', '_core')
ERASER = 'GdprEraser.php'
CATALOGUE = 'personal-data-catalogue.php'

# A column naming who DID something: `visitedByID`, `checkedOutByID`.
WORKER_COLUMN = re.compile(r'^[a-z][A-Za-z]*ByID$')

# Asks the REAL class for its instructions. `$argv[1]` is the file to load.
PHP_DUMP = (
    'require $argv[1];'
    '$o = [];'
    'foreach (\\Portal\\Core\\GdprEraser::catalogue() as $e) {'
    '  $o[] = [(string) ($e["table"] ?? ""), (string) ($e["userCol"] ?? ""), (string) ($e["action"] ?? "")];'
    '}'
    'echo json_encode($o);'
)


class Unreadable(Exception):
    """Something this check needs could not be read: it refuses, it never passes."""


def kept_worker_columns(core_dir: str) -> set[tuple[str, str]]:
    """Every (table, column) the erasure KEEPS on a worker's column."""
    php = shutil.which('php')
    if php is None:
        raise Unreadable('there is no `php` on this machine, so the real instruction list cannot be asked')
    done = subprocess.run([php, '-r', PHP_DUMP, os.path.join(core_dir, ERASER)],
                          capture_output=True, text=True, timeout=120, check=False)
    if done.returncode != 0:
        raise Unreadable('PHP could not load GdprEraser::catalogue(): ' + (done.stderr or done.stdout).strip()[:400])
    try:
        rows = json.loads(done.stdout)
    except json.JSONDecodeError as problem:
        raise Unreadable('the instruction list did not come back as JSON: ' + str(problem)) from problem
    if not rows:
        raise Unreadable('GdprEraser::catalogue() answered no instructions at all')
    return {(t, c) for t, c, a in rows if a == 'retain' and WORKER_COLUMN.match(c)}


def listed_columns(source: str) -> set[tuple[str, str]]:
    """Every (table, column) recordsCarryingYourName() reads, from its source."""
    m = re.search(r'public static function recordsCarryingYourName\(.*?\n    \}\n', source, re.S)
    if m is None:
        raise Unreadable('GdprEraser::recordsCarryingYourName() was not found')
    body = m.group(0)
    blocks = re.findall(r"'sql'\s*=>\s*((?:'(?:[^'\\]|\\.)*'\s*(?:\.\s*)?)+)", body)
    if not blocks:
        raise Unreadable("recordsCarryingYourName() has no `'sql' =>` entries this check can read")
    found: set[tuple[str, str]] = set()
    for block in blocks:
        sql = ''.join(re.findall(r"'((?:[^'\\]|\\.)*)'", block))
        start = re.search(r'FROM\s+`(\w+)`\s+(\w+)', sql)
        where = re.findall(r'WHERE\s+(\w+)\.`(\w+)`\s*=\s*\?', sql)
        if start is None or len(where) != 1 or where[0][0] != start.group(2):
            raise Unreadable('could not tell which table and column this query reads: ' + sql[:300])
        found.add((start.group(1), where[0][1]))
    return found


def problems(core_dir: str) -> list[str]:
    """What is wrong, in words (empty when the two agree). Raises Unreadable."""
    with open(os.path.join(core_dir, ERASER), encoding='utf-8') as handle:
        source = handle.read()
    kept = kept_worker_columns(core_dir)
    listed = listed_columns(source)
    if not kept:
        raise Unreadable('no "retain" instruction on a worker column was found, so this check would prove nothing')
    out = []
    for table, column in sorted(kept - listed):
        out.append(f'MISSING: the erasure keeps the worker on {table}.{column}, but recordsCarryingYourName() does '
                   f'not list those records, so that worker\'s download leaves them out (#929, D-142). Add a kind to it.')
    for table, column in sorted(listed - kept):
        out.append(f'EXTRA: recordsCarryingYourName() lists {table}.{column}, which is not a "retain" instruction on a '
                   f'worker column in GdprEraser::catalogue(). Either the erasure keeps it and the instruction is '
                   f'missing, or the list should not read it.')
    return out


def run(core_dir: str) -> int:
    try:
        found = problems(core_dir)
    except Unreadable as why:
        print('check_worker_records_listed: REFUSED - ' + str(why))
        return 1
    if found:
        for line in found:
            print('check_worker_records_listed: ' + line)
        return 1
    print('check_worker_records_listed: OK - every record the erasure keeps against a worker is listed in their '
          'own download, and nothing else is.')
    return 0


def self_test() -> int:
    """Planted faults on copies of the two files: each must be caught by its own complaint and by no other."""
    with open(os.path.join(CORE, ERASER), encoding='utf-8') as handle:
        eraser = handle.read()
    with open(os.path.join(CORE, CATALOGUE), encoding='utf-8') as handle:
        catalogue = handle.read()

    def outcome(text: str) -> set[str]:
        with tempfile.TemporaryDirectory(prefix='check-worker-records-') as folder:
            with open(os.path.join(folder, ERASER), 'w', encoding='utf-8') as handle:
                handle.write(text)
            with open(os.path.join(folder, CATALOGUE), 'w', encoding='utf-8') as handle:
                handle.write(catalogue)
            try:
                return {line.split(':', 1)[0] for line in problems(folder)}
            except Unreadable:
                return {'REFUSED'}

    out_block = re.search(r"            'child-checked-out' => \[\n.*?\n            \],\n", eraser, re.S)
    extra_kind = ("            'p929-planted' => [\n"
                  "                'what' => 'x', 'yourPart' => 'y', 'heldBy' => true,\n"
                  "                'sql' => 'SELECT v.`followUpAt` AS `onDate` FROM `tblCareVisit` v WHERE v.`followUpAssignedToID` = ?',\n"
                  "            ],\n")
    cases = [
        ('the real files', eraser, set()),
        ('a kind taken out of the list (no check-outs)',
         eraser.replace(out_block.group(0), '', 1) if out_block else '', {'MISSING'}),
        ('a kind added for a column the erasure does NOT keep',
         eraser.replace("            'child-checked-out' => [\n", extra_kind + "            'child-checked-out' => [\n", 1), {'EXTRA'}),
        ('a new "retain" instruction on a worker column, not listed',
         eraser.replace("'userCol' => 'approvedByID', 'action' => 'anonymise'",
                        "'userCol' => 'approvedByID', 'action' => 'retain'", 1), {'MISSING'}),
        ('a query whose WHERE names no table alias',
         eraser.replace("WHERE k.`checkedOutByID` = ?'", "WHERE `checkedOutByID` = ?'", 1), {'REFUSED'}),
        ('the method renamed away', eraser.replace('function recordsCarryingYourName(', 'function recordsRenamed(', 1), {'REFUSED'}),
        # Must stay green: spacing and a comment between kinds change nothing.
        ('spacing around `=>` and a comment between kinds (must stay green)',
         eraser.replace("'sql'      => 'SELECT d.", "'sql'=>'SELECT d.", 1)
               .replace("            'child-checked-in' => [\n", "            // a note between kinds\n            'child-checked-in' => [\n", 1), set()),
    ]
    failed = 0
    for label, text, expected in cases:
        if text == eraser and expected:
            print(f'FAIL - {label}: the planted change did not apply, so it tests nothing')
            failed += 1
            continue
        got = outcome(text)
        verdict = 'PASS' if got == expected else 'FAIL'
        failed += verdict == 'FAIL'
        print(f'{verdict} - {label}: expected {sorted(expected) or "nothing wrong"}, got {sorted(got) or "nothing wrong"}')
    total = len(cases)
    print(f'{total - failed} of {total} self-test cases passed ({total - 2} refused or caught, 2 must stay green).')
    return 1 if failed else 0


if __name__ == '__main__':
    if '--self-test' in sys.argv[1:]:
        sys.exit(self_test())
    sys.exit(run(CORE))
