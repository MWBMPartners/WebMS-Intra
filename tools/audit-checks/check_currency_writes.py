#!/usr/bin/env python3
"""
A money record's currency is written by ONE rule (#966, D-139) — hard gate.

Path: tools/audit-checks/check_currency_writes.py

    python3 -I tools/audit-checks/check_currency_writes.py              run the check
    python3 -I tools/audit-checks/check_currency_writes.py --self-test  run its planted faults

WHAT IT GUARDS
--------------
Every record that holds money keeps its own currency (D-139). "An edit relabels
a record" failed two independent check rounds running: a pledge campaign's
UPDATE wrote today's currency, then the venue booking, agreement and invoice
edits fell back to today's currency for an unrecorded record while its
payments stayed unrecorded, then a count wrote its gifts in today's currency.
Each was a caller with its own "fall back to the organisation's" branch.
`Currency::onEdit()` is now the ONE rule, and this check is what stops a sixth
caller writing its own.

THE TWO RULES (presence only, D-065: it reads code, it cannot run it)
---------------------------------------------------------------------
For every table in `Currency::OWN_TABLES` and `Currency::CHILD_TABLES` (read
from web/_core/Currency.php, never typed here):

  1. An UPDATE of the table that SETS `currency` (in its SET clause, or as a
     `'currency' =>` field beside the UPDATE in the same function) must have
     `Currency::onEdit(` in the SAME function — code, not a comment.
  2. An INSERT into a CHILD table that names `currency` must take the
     PARENT's value: a subquery reading the parent table inside the statement.
     A writer that takes it another way (the parent's value read just above,
     or the parent row it has just written) is named in ON_PURPOSE with the
     reason, and that list fails BOTH ways: an entry that no longer matches a
     write is reported, because a stale exception reads as a guarantee.

An INSERT of a NEW own-table record is not judged: a new record takes the
currency posted for it or the organisation's, which is right. A WHERE clause
comparing `currency` is not a write and is not judged.

WHAT IT CANNOT SEE, said plainly
--------------------------------
  - whether the helper's answer is the value actually bound (it checks the
    helper is CALLED in the function, not that its result reaches the `?`);
  - SQL built from a table name held in a variable (Currency::labelUnlabelled()
    is one, and is the deliberate labelling of '' rows: it is not an edit);
  - a write in a file outside web/_apps, web/_core and web/_install.
  The behaviour is driven by tools/currency-selftest.php (D3b, D3c, H10-H13).

It asks PHP's own tokeniser (currency-writes-scopes.php) for each function's
code with comments removed; without `php` it REFUSES loudly, never passes.
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
SCOPES = os.path.join(HERE, 'currency-writes-scopes.php')

# (path under the repository, child table) -> why the parent's value is taken
# another way than a subquery. Fails BOTH ways.
ON_PURPOSE = {
    ('web/_apps/expenses/submit/save.php', 'tblExpenseClaimItems'):
        'the claim row was INSERTed a few lines above with $claimCurrency, and every line is bound with that same value through ExpenseRules::expenseLine()',
    ('web/_apps/expenses/edit/save.php', 'tblExpenseClaimItems'):
        "the lines are rebuilt with $claimCurrency, which is Currency::onEdit() of the claim's own stored currency",
    ('web/_apps/expenses/api/create.php', 'tblExpenseClaimItems'):
        'the claim row was INSERTed just above with $claimCurrency, and every line carries that value from ExpenseRules::expenseLine()',
    ('web/_apps/giving/reconcile/import.php', 'tblBankTxns'):
        "the import row was INSERTed just above with $currency, and every bank line is bound with it",
    ('web/_core/AssetRegister.php', 'tblAssetMaintenance'):
        "the repair's currency is self::currencyOfAsset(), the asset row's own value read in the same function",
}

IGNORED_FILES = {
    # Currency::labelUnlabelled() names its tables from a variable: it is the
    # deliberate labelling of rows with NO currency, never an edit.
    'web/_core/Currency.php',
}


def money_tables(root: str) -> tuple[list[str], dict[str, str]]:
    """OWN_TABLES and CHILD_TABLES (child -> parent), read out of Currency.php."""
    src = open(os.path.join(root, 'web', '_core', 'Currency.php'), encoding='utf-8').read()
    own_m = re.search(r'const\s+OWN_TABLES\s*=\s*\[(.*?)\];', src, re.S)
    child_m = re.search(r'const\s+CHILD_TABLES\s*=\s*\[(.*?)\];', src, re.S)
    if own_m is None or child_m is None:
        raise SystemExit('check_currency_writes: could not find OWN_TABLES or CHILD_TABLES in web/_core/Currency.php — refusing to pass')
    own = re.findall(r"'(tbl\w+)'", re.sub(r'//[^\n]*', '', own_m.group(1)))
    child = dict(re.findall(r"'(tbl\w+)'\s*=>\s*\[\s*'(tbl\w+)'", re.sub(r'//[^\n]*', '', child_m.group(1))))
    if own == [] or child == {}:
        raise SystemExit('check_currency_writes: OWN_TABLES or CHILD_TABLES read as empty — refusing to pass')
    return own, child


def scopes_of(path: str) -> list[dict]:
    php = shutil.which('php')
    if php is None:
        raise SystemExit('check_currency_writes: `php` is not on the PATH, so the code cannot be read without its comments — REFUSING rather than passing')
    out = subprocess.run([php, SCOPES, path], capture_output=True, text=True)
    if out.returncode != 0:
        raise SystemExit(f'check_currency_writes: could not read {path}: {out.stderr.strip()} — refusing to pass')
    return json.loads(out.stdout)


SET_CURRENCY = re.compile(r'\bcurrency`?\s*=(?!=)')
FIELD_CURRENCY = re.compile(r"""['"]currency['"]\s*=>""")


def findings(root: str, on_purpose: dict) -> tuple[list[str], list[tuple[str, str]]]:
    own, child = money_tables(root)
    tables = sorted(set(own) | set(child))
    problems: list[str] = []
    used: set[tuple[str, str]] = set()
    for base in ('web/_apps', 'web/_core', 'web/_install'):
        top = os.path.join(root, base)
        for d, _, files in os.walk(top):
            for f in sorted(files):
                if f.endswith('.php') is False:
                    continue
                full = os.path.join(d, f)
                rel = os.path.relpath(full, root).replace(os.sep, '/')
                if rel in IGNORED_FILES:
                    continue
                raw = open(full, encoding='utf-8', errors='replace').read()
                if 'currency' not in raw:
                    continue
                for sc in scopes_of(full):
                    code = sc['code']
                    where = f"{rel}: {sc['name']}() (line {sc['line']})"
                    for t in tables:
                        # Rule 1: an UPDATE that sets the currency.
                        # A statement is often built in pieces ('UPDATE tblX ' . 'SET …'),
                        # so the gap between the table and SET may hold quotes and dots.
                        for m in re.finditer(r'\bUPDATE\s+`?' + t + r'`?(\s+`?\w+`?)?[\s\'".]+SET\b', code):
                            window = code[m.end():m.end() + 1500]
                            cut = window.find('WHERE')
                            set_part = window if cut < 0 else window[:cut]
                            sets = SET_CURRENCY.search(set_part) is not None
                            fielded = FIELD_CURRENCY.search(code) is not None and re.search(r"\bUPDATE\s+`?" + t + r"`?\s+SET\s+['\"]?\s*\.", code) is not None
                            if (sets or fielded) and 'Currency::onEdit(' not in code:
                                problems.append(f'{where}: an UPDATE of {t} writes `currency` without Currency::onEdit() in the same function — an edit would relabel the record')
                        # Rule 2: an INSERT into a child table that names the currency.
                        if t not in child:
                            continue
                        # The column list may start in the NEXT piece of the string
                        # ('INSERT INTO tblX ' . '(…)'): the first version needed the
                        # bracket straight after the name and missed such a writer.
                        for m in re.finditer(r'\bINSERT\s+(?:IGNORE\s+)?INTO\s+`?' + t + r'`?(?=[\s\'".(])', code):
                            window = code[m.end():m.end() + 1200]
                            values_at = window.find('VALUES')
                            columns = window if values_at < 0 else window[:values_at]
                            named = re.search(r'\bcurrency\b', columns) is not None or FIELD_CURRENCY.search(code) is not None
                            if named is False:
                                continue
                            takes_parent = re.search(r'\bFROM\s+`?' + child[t] + r'`?\b', window) is not None
                            if takes_parent:
                                continue
                            key = (rel, t)
                            if key in on_purpose:
                                used.add(key)
                                continue
                            problems.append(f"{where}: an INSERT into {t} names `currency` but does not take its parent's ({child[t]}) — use a subquery reading the parent, or name it in ON_PURPOSE with the reason")
    stale = [k for k in on_purpose if k not in used]
    return problems, stale


def run(root: str, on_purpose: dict) -> int:
    problems, stale = findings(root, on_purpose)
    for p in problems:
        print('  • ' + p)
    for rel, t in stale:
        print(f'  • ON_PURPOSE names {rel} writing {t}, which no longer writes it that way — remove the entry (a stale exception reads as a guarantee)')
    if problems or stale:
        print(f'\nA money record\'s currency: {len(problems)} write(s) outside the one rule, {len(stale)} stale exception(s).')
        return 1
    print('✓ Every UPDATE that writes a money record\'s currency goes through Currency::onEdit(), and every child row takes its parent\'s currency.')
    return 0


# ─────────────────────────────────────────────────────────────────────────────
# Self-test: each plant in its own small made-up tree, judged alone.
# ─────────────────────────────────────────────────────────────────────────────
FAKE_CURRENCY = """<?php
namespace Portal\\Core;
final class Currency {
    public const OWN_TABLES = ['tblParent'];
    public const CHILD_TABLES = [
        'tblKid' => ['tblParent', 'parentID', 'parentID'],
    ];
}
"""

PLANTS = [
    # (name, php source, expect a finding, on_purpose entries)
    ('UPDATE sets currency with no helper', """<?php
function save($db, $id, $cur) {
    $s = $db->prepare('UPDATE tblParent SET name = ?, '
        . '`currency` = ? WHERE parentID = ?');
}
""", True, {}),
    ('UPDATE sets currency through the helper', """<?php
function save($db, $id, $old, $cur) {
    $cur = Currency::onEdit($old, $cur);
    $s = $db->prepare('UPDATE tblParent SET name = ?, `currency` = ? WHERE parentID = ?');
}
""", False, {}),
    ('the helper named only in a COMMENT does not count', """<?php
function save($db, $id, $cur) {
    // Currency::onEdit( would go here
    $s = $db->prepare('UPDATE tblParent SET currency = ? WHERE parentID = ?');
}
""", True, {}),
    ('a WHERE comparing currency is not a write', """<?php
function look($db) {
    $s = $db->prepare('UPDATE tblParent SET name = ? WHERE `currency` = ? AND parentID = ?');
}
""", False, {}),
    ('a field map beside the UPDATE sets currency', """<?php
function save($db, $old, $data) {
    $fields = ['name' => [$data['name'], 's'], 'currency' => [$data['currency'], 's']];
    $s = $db->prepare('UPDATE tblParent SET ' . implode(', ', array_keys($fields)) . ' WHERE parentID = ?');
}
""", True, {}),
    ('a child INSERT taking its parent by subquery', """<?php
function add($db) {
    $s = $db->prepare('INSERT INTO tblKid (parentID, amount, `currency`) '
        . 'VALUES (?, ?, (SELECT `p`.`currency` FROM `tblParent` `p` WHERE `p`.`parentID` = ?))');
}
""", False, {}),
    ('a child INSERT binding some other value', """<?php
function add($db, $cur) {
    $s = $db->prepare('INSERT INTO tblKid (parentID, amount, `currency`) VALUES (?, ?, ?)');
}
""", True, {}),
    ('the same, named in ON_PURPOSE', """<?php
function add($db, $cur) {
    $s = $db->prepare('INSERT INTO tblKid (parentID, amount, `currency`) VALUES (?, ?, ?)');
}
""", False, {('web/_apps/x/a.php', 'tblKid'): 'reason'}),
    ('a stale ON_PURPOSE entry', """<?php
function add($db) {
    $s = $db->prepare('INSERT INTO tblKid (parentID, amount) VALUES (?, ?)');
}
""", True, {('web/_apps/x/a.php', 'tblKid'): 'reason'}),
    ('a new OWN record is not judged', """<?php
function create($db, $cur) {
    $s = $db->prepare('INSERT INTO tblParent (name, `currency`) VALUES (?, ?)');
}
""", False, {}),
    ('a child INSERT whose column list is in the next piece of the string', """<?php
function add($db) {
    $s = $db->prepare('INSERT INTO tblKid '
        . '(parentID, amount, `currency`) VALUES (?, ?, ?)');
}
""", True, {}),
    ('an UPDATE whose SET is in the next piece of the string', """<?php
function save($db) {
    $s = $db->prepare('UPDATE tblParent '
        . 'SET `currency` = ? WHERE parentID = ?');
}
""", True, {}),
    ('an UPDATE at file level, outside any function', """<?php
$s = $db->prepare('UPDATE `tblParent` SET `currency` = ? WHERE `parentID` = ?');
""", True, {}),
]


def self_test() -> int:
    failed = 0
    for name, src, expect, op in PLANTS:
        tmp = tempfile.mkdtemp(prefix='currency-writes-')
        try:
            os.makedirs(os.path.join(tmp, 'web', '_core'))
            os.makedirs(os.path.join(tmp, 'web', '_apps', 'x'))
            open(os.path.join(tmp, 'web', '_core', 'Currency.php'), 'w').write(FAKE_CURRENCY)
            open(os.path.join(tmp, 'web', '_apps', 'x', 'a.php'), 'w').write(src)
            problems, stale = findings(tmp, op)
            got = bool(problems or stale)
            ok = got == expect
            failed += 0 if ok else 1
            print(('  ✓ ' if ok else '  ✗ ') + name + (' — reported, as it must be' if expect else ' — passes, as it must'))
            if ok is False:
                print('      got: ' + json.dumps([problems, stale]))
        finally:
            shutil.rmtree(tmp)
    print(f'\n{len(PLANTS)} plants: {len(PLANTS) - failed} behaved as expected, {failed} did not.')
    return 0 if failed == 0 else 1


if __name__ == '__main__':
    if '--self-test' in sys.argv[1:]:
        sys.exit(self_test())
    sys.exit(run(os.path.dirname(os.path.dirname(HERE)), ON_PURPOSE))
