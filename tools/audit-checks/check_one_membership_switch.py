#!/usr/bin/env python3
"""
A membership is switched off or on by ONE class (#934, D-211) — hard gate.

Path: tools/audit-checks/check_one_membership_switch.py

    python3 -I tools/audit-checks/check_one_membership_switch.py              run the check
    python3 -I tools/audit-checks/check_one_membership_switch.py --self-test  run its planted faults

WHAT IT GUARDS
--------------
`tblUserSites.leftAtUtc` is the moment a membership was switched off, and the
privacy clear-out deletes a member's consent records a period after it
(D-166, D-211). A date is only true if EVERY statement that switches a
membership off records it, and only safe if every one that switches it on
empties it. `Portal\\Core\\Memberships` holds that SQL; this check stops a
second writer appearing.

THE RULES (presence only, D-065: it reads code, it cannot run it; the
BEHAVIOUR is driven by section M13s-M13v of tools/erasure-pages-selftest.php
(real offboarding and rehire over HTTP) and by section L of
tools/consent-retention-selftest.php (the clear-out, with the database clock
ten hours from UTC))
------------------------------------------------------------------------------
  R1  An UPDATE of tblUserSites whose SET assigns isActive or leftAtUtc fails
      anywhere but web/_core/Memberships.php.
  R2  An INSERT into tblUserSites: fails when its column list names leftAtUtc,
      when it has no column list, when an ON DUPLICATE KEY UPDATE names
      isActive or leftAtUtc, when it names isActive and the matching VALUES
      entry is not the literal 1 (a membership born switched off would have
      no date), or when it is an INSERT ... SELECT naming isActive.
      `REPLACE INTO tblUserSites` fails.
  R3  Every `DELETE FROM tblUserSites` must be in ON_PURPOSE_DELETES, per file,
      and the list fails BOTH ways (a new delete, and a listed one that is gone).
  R4  Memberships.php must hold the three constants with exactly the pinned SQL
      (whitespace aside) and the function hasLeftSql(); and the two callers
      must really USE the constants (EXPECTED_USES, read from code — both files
      quote their old SQL in comments, so a text search would pass on it).

It asks PHP's own tokeniser (membership-writes-probe.php) for each statement
with comments removed and string pieces joined; without `php` it REFUSES
loudly, never passes. It reads web/ only. SQL keywords are matched in any case
and identifiers with or without backticks.

WHAT IT CANNOT SEE, said plainly
--------------------------------
  - a table or column named in a variable, or a statement built by sprintf() or
    a helper: GdprEraser (catalogue walk), AccountMerge (moves userID, removes
    the duplicate's copy on a same-values clash), DbBackup (restore) and the
    demo-data removal name tblUserSites from a list and never set isActive;
    that was read, not checked here;
  - a multi-table UPDATE or DELETE that names tblUserSites only in a join
    (`UPDATE tblUsers u JOIN tblUserSites us SET us.isActive`, `DELETE x FROM
    tblY x JOIN tblUserSites`): none exists (measured with the probe, 17 November
    2026). R1 would cry wolf on `UPDATE tblUsers u JOIN tblUserSites ... SET
    u.isActive`; narrow it with evidence if one appears;
  - a write outside web/;
  - whether the helper's SQL is CORRECT: that is driven by the tests above.
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
PROBE = os.path.join(HERE, 'membership-writes-probe.php')
ROOT = os.path.dirname(os.path.dirname(HERE))

MEMBERSHIPS = 'web/_core/Memberships.php'

# file -> how many `DELETE FROM tblUserSites` it may hold, and why. Fails BOTH ways.
ON_PURPOSE_DELETES = {
    'web/_apps/admin/sites/users.php': (
        1,
        'Remove deletes the membership, so it records no leaving date (D-212); the person\'s consent '
        'records are kept until replaced or erased',
    ),
}

# The three statements, whitespace-normalised. Changing one is a decision about
# what the clear-out will delete: change the tests with it.
PINNED = {
    'SWITCH_OFF_ALL_SQL': 'UPDATE `tblUserSites` SET `leftAtUtc` = UTC_TIMESTAMP(), `isActive` = 0 WHERE `userID` = ? AND `isActive` = 1',
    'SWITCH_ON_ALL_SQL': 'UPDATE `tblUserSites` SET `isActive` = 1, `leftAtUtc` = NULL WHERE `userID` = ?',
    'SWITCH_ON_ONE_SQL': 'UPDATE `tblUserSites` SET `isActive` = 1, `leftAtUtc` = NULL WHERE `userID` = ? AND `siteID` = ?',
}

# file -> the constants it must use in CODE.
EXPECTED_USES = {
    'web/_apps/offboarding/do.php': {'Memberships::SWITCH_OFF_ALL_SQL'},
    'web/_apps/offboarding/rehire.php': {'Memberships::SWITCH_ON_ALL_SQL', 'Memberships::SWITCH_ON_ONE_SQL'},
}

TABLE = r'`?tblUserSites`?(?![A-Za-z0-9_])'


def norm(sql: str) -> str:
    return re.sub(r'\s+', ' ', sql).strip()


def probe(root: str) -> dict:
    php = shutil.which('php')
    if php is None:
        raise SystemExit('check_one_membership_switch: `php` is not on the PATH, so the code cannot be read without its comments — REFUSING rather than passing')
    out = subprocess.run([php, PROBE, os.path.join(root, 'web')], capture_output=True, text=True)
    if out.returncode != 0:
        raise SystemExit(f'check_one_membership_switch: the probe failed: {out.stderr.strip()[:300]} — refusing to pass')
    data = json.loads(out.stdout)
    return {os.path.relpath(p, root).replace(os.sep, '/'): v for p, v in data.items()}


def split_top(text: str) -> list[str]:
    """Split on commas that are not inside brackets (a VALUES entry may hold NOW() or a subquery)."""
    parts, depth, cur = [], 0, ''
    for ch in text:
        if ch == '(':
            depth += 1
        elif ch == ')':
            depth -= 1
        if ch == ',' and depth == 0:
            parts.append(cur)
            cur = ''
        else:
            cur += ch
    parts.append(cur)
    return [p.strip() for p in parts]


def balanced_after(text: str, start: int) -> tuple[str, int] | None:
    """The text inside the bracket opening at text[start], and the index after its closing bracket."""
    if start >= len(text) or text[start] != '(':
        return None
    depth = 0
    for i in range(start, len(text)):
        if text[i] == '(':
            depth += 1
        elif text[i] == ')':
            depth -= 1
            if depth == 0:
                return text[start + 1:i], i + 1
    return None


ASSIGNS = re.compile(r'(?<![A-Za-z0-9_.`])(?:`?\w+`?\.)?`?(isActive|leftAtUtc)`?\s*=(?!=)', re.I)
ON_DUP = re.compile(r'\bON\s+DUPLICATE\s+KEY\s+UPDATE\b', re.I)


def statement_problems(rel: str, line: int, sql: str) -> tuple[list[str], int]:
    """Problems one statement has, and how many `DELETE FROM tblUserSites` it holds."""
    found: list[str] = []
    where = f'{rel} (line {line})'
    s = norm(sql)
    # An ON DUPLICATE KEY UPDATE tail is its own question (R2); cut it off before looking for an UPDATE.
    dup = ON_DUP.search(s)
    head, tail = (s[:dup.start()], s[dup.end():]) if dup else (s, '')

    if rel != MEMBERSHIPS:
        # R1
        for m in re.finditer(r'\bUPDATE\b(?:\s+IGNORE\b)?(.*?)\bSET\b(.*)', head, re.I | re.S):
            tables, rest = m.group(1), m.group(2)
            if re.search(TABLE, tables, re.I) is None:
                continue
            cut = re.search(r'\bWHERE\b', rest, re.I)
            set_part = rest if cut is None else rest[:cut.start()]
            if ASSIGNS.search(set_part) is not None:
                found.append(f'[R1] {where}: an UPDATE of tblUserSites sets isActive or leftAtUtc outside Memberships — use Memberships::SWITCH_OFF_ALL_SQL / SWITCH_ON_ALL_SQL / SWITCH_ON_ONE_SQL')
        # R2
        if re.search(r'\bREPLACE\s+INTO\s+' + TABLE, head, re.I):
            found.append(f'[R2] {where}: REPLACE INTO tblUserSites deletes and re-inserts a membership, losing or inventing its date')
        for m in re.finditer(r'\bINSERT\s+(?:IGNORE\s+)?INTO\s+' + TABLE + r'\s*', head, re.I):
            rest = head[m.end():]
            cols = balanced_after(rest, 0)
            if cols is None:
                found.append(f'[R2] {where}: an INSERT into tblUserSites with no column list cannot be read')
                continue
            names = [c.strip().strip('`') for c in cols[0].split(',')]
            if 'leftAtUtc' in names:
                found.append(f'[R2] {where}: an INSERT into tblUserSites names leftAtUtc — only Memberships writes it')
            after = rest[cols[1]:]
            vm = re.match(r'\s*VALUES\s*', after, re.I)
            if 'isActive' in names:
                if vm is None:
                    found.append(f'[R2] {where}: an INSERT ... SELECT into tblUserSites names isActive — a membership born switched off would have no date')
                else:
                    i = vm.end()
                    while i < len(after) and after[i] == '(':
                        tup = balanced_after(after, i)
                        if tup is None:
                            break
                        entries = split_top(tup[0])
                        if len(entries) != len(names) or entries[names.index('isActive')] != '1':
                            found.append(f'[R2] {where}: an INSERT into tblUserSites names isActive but its value is not the literal 1 — a membership born switched off would have no date')
                        i = tup[1]
                        while i < len(after) and after[i] in ' ,':
                            i += 1
        if tail != '' and ASSIGNS.search(tail) is not None and re.search(TABLE, head, re.I):
            found.append(f'[R2] {where}: an ON DUPLICATE KEY UPDATE on tblUserSites sets isActive or leftAtUtc')
    deletes = len(re.findall(r'\bDELETE\s+(?:\w+(?:\s*,\s*\w+)*\s+)?FROM\s+' + TABLE, head, re.I))
    return found, deletes


def findings(root: str) -> list[str]:
    data = probe(root)
    problems: list[str] = []
    delete_counts: dict[str, int] = {}
    uses: dict[str, set[str]] = {}
    for rel, v in sorted(data.items()):
        for st in v['statements']:
            if rel == MEMBERSHIPS:
                continue
            found, dels = statement_problems(rel, st['line'], st['sql'])
            problems += found
            if dels:
                delete_counts[rel] = delete_counts.get(rel, 0) + dels
            for c in st['consts']:
                uses.setdefault(rel, set()).add(c)
    # R3, both ways
    for rel, n in sorted(delete_counts.items()):
        allowed = ON_PURPOSE_DELETES.get(rel, (0, ''))[0]
        if n > allowed:
            problems.append(f'[R3] {rel}: {n} DELETE FROM tblUserSites, {allowed} allowed — a deleted membership has nowhere to record a leaving date; switch it off through Memberships, or add it to ON_PURPOSE_DELETES with the reason')
    for rel, (allowed, _why) in sorted(ON_PURPOSE_DELETES.items()):
        if delete_counts.get(rel, 0) < allowed:
            problems.append(f'[R3] ON_PURPOSE_DELETES names {rel} for {allowed} delete(s) but it holds {delete_counts.get(rel, 0)} — remove the entry (a stale exception reads as a guarantee)')
    # R4
    mem = data.get(MEMBERSHIPS)
    if mem is None:
        problems.append(f'[R4] {MEMBERSHIPS} is missing')
    else:
        # A constant's statement is the joined string pieces of its `const X = '…' . '…';`.
        pieces = {}
        for st in mem['statements']:
            for c in PINNED:
                if st['sql'] and norm(st['sql']) == PINNED[c]:
                    pieces[c] = True
        for const, text in PINNED.items():
            if pieces.get(const) is not True:
                problems.append(f'[R4] {MEMBERSHIPS}: {const} is missing or is not exactly the pinned statement: {text}')
        if 'hasLeftSql' not in mem['functions']:
            problems.append(f'[R4] {MEMBERSHIPS}: the function hasLeftSql() is missing')
    for rel, wanted in sorted(EXPECTED_USES.items()):
        got = uses.get(rel, set())
        for w in sorted(wanted - got):
            problems.append(f'[R4] {rel}: does not use {w} in code — it must switch memberships through Memberships')
    return problems


def run(root: str) -> int:
    problems = findings(root)
    for p in problems:
        print('  • ' + p)
    if problems:
        print(f'\nMembership switching: {len(problems)} problem(s).')
        return 1
    print('✓ Nothing outside Memberships switches a membership off or on, no INSERT can be born switched off or carry a date, '
          f'the {len(ON_PURPOSE_DELETES)} listed DELETE is the only one, and the three statements and both callers are as pinned. '
          'It reads PRESENCE only; the behaviour is driven by tools/erasure-pages-selftest.php (M13s-M13v) and tools/consent-retention-selftest.php (section L).')
    return 0


# ─────────────────────────────────────────────────────────────────────────────
# Self-test: each plant is the REAL files plus one change, judged alone.
# ─────────────────────────────────────────────────────────────────────────────
REAL = ['web/_core/Memberships.php', 'web/_apps/offboarding/do.php', 'web/_apps/offboarding/rehire.php',
        'web/_apps/admin/sites/users.php']


def make_tree(edits: dict[str, tuple[str, str]], extra: dict[str, str], drop: set[str]) -> str:
    tmp = tempfile.mkdtemp(prefix='cms-')
    for rel in REAL:
        if rel in drop:
            continue
        dest = os.path.join(tmp, rel)
        os.makedirs(os.path.dirname(dest), exist_ok=True)
        src = open(os.path.join(ROOT, rel), encoding='utf-8').read()
        if rel in edits:
            old, new = edits[rel]
            if src.count(old) != 1:
                raise SystemExit(f'self-test: the plant text for {rel} is not found exactly once: {old!r}')
            src = src.replace(old, new)
        open(dest, 'w', encoding='utf-8').write(src)
    for rel, text in extra.items():
        dest = os.path.join(tmp, rel)
        os.makedirs(os.path.dirname(dest), exist_ok=True)
        open(dest, 'w', encoding='utf-8').write(text)
    return tmp


def php(body: str) -> str:
    return '<?php\n' + body + '\n'


# (name, edits, extra files, files dropped, expected rule or None for "must stay green")
PLANTS = [
    ('UPDATE isActive = 0 in another file', {}, {'web/_apps/x/a.php': php("$s = $db->prepare('UPDATE tblUserSites SET isActive = 0 WHERE userID = ?');")}, set(), 'R1'),
    ('the same, split over two literals', {}, {'web/_apps/x/a.php': php("$s = $db->prepare('UPDATE tblUserSites ' . 'SET isActive = 0 ' . 'WHERE userID = ?');")}, set(), 'R1'),
    ('the same, backticked, lower case', {}, {'web/_apps/x/a.php': php("$s = $db->prepare('update `tblUserSites` set `isActive` = 0 where `userID` = ?');")}, set(), 'R1'),
    ('UPDATE setting leftAtUtc', {}, {'web/_apps/x/a.php': php("$s = $db->prepare('UPDATE tblUserSites SET leftAtUtc = NOW() WHERE userID = ?');")}, set(), 'R1'),
    ('ON DUPLICATE KEY UPDATE isActive = 1', {}, {'web/_apps/x/a.php': php("$s = $db->prepare('INSERT INTO tblUserSites (userID, siteID, isActive) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE isActive = 1');")}, set(), 'R2'),
    ('INSERT with isActive 0', {}, {'web/_apps/x/a.php': php("$s = $db->prepare('INSERT INTO tblUserSites (userID, siteID, isActive) VALUES (?, ?, 0)');")}, set(), 'R2'),
    ('INSERT with isActive bound', {}, {'web/_apps/x/a.php': php("$s = $db->prepare('INSERT INTO tblUserSites (userID, siteID, isActive) VALUES (?, ?, ?)');")}, set(), 'R2'),
    ('INSERT naming leftAtUtc', {}, {'web/_apps/x/a.php': php("$s = $db->prepare('INSERT INTO tblUserSites (userID, siteID, leftAtUtc) VALUES (?, ?, NULL)');")}, set(), 'R2'),
    ('INSERT ... SELECT naming isActive', {}, {'web/_apps/x/a.php': php("$s = $db->prepare('INSERT INTO tblUserSites (userID, siteID, isActive) SELECT userID, ?, 0 FROM tblUsers');")}, set(), 'R2'),
    ('REPLACE INTO', {}, {'web/_apps/x/a.php': php("$s = $db->prepare('REPLACE INTO tblUserSites (userID, siteID) VALUES (?, ?)');")}, set(), 'R2'),
    ('a second DELETE in a new file', {}, {'web/_apps/x/a.php': php("$s = $db->prepare('DELETE FROM tblUserSites WHERE userID = ?');")}, set(), 'R3'),
    ('the listed DELETE removed (stale entry)', {}, {}, {'web/_apps/admin/sites/users.php'}, 'R3'),
    ('SWITCH_OFF_ALL_SQL without leftAtUtc', {'web/_core/Memberships.php': ('`leftAtUtc` = UTC_TIMESTAMP(), `isActive` = 0', '`isActive` = 0')}, {}, set(), 'R4'),
    ('SWITCH_OFF_ALL_SQL without AND isActive = 1', {'web/_core/Memberships.php': (" . 'WHERE `userID` = ? AND `isActive` = 1';", " . 'WHERE `userID` = ?';")}, {}, set(), 'R4'),
    ('SWITCH_ON_ONE_SQL not emptying the date', {'web/_core/Memberships.php': ("SET `isActive` = 1, `leftAtUtc` = NULL '", "SET `isActive` = 1 '")}, {}, set(), 'R4'),
    ('hasLeftSql removed', {'web/_core/Memberships.php': ('public static function hasLeftSql(', 'public static function hasLeftSqlRenamed(')}, {}, set(), 'R4'),
    ('rehire no longer using SWITCH_ON_ALL_SQL', {'web/_apps/offboarding/rehire.php': ('$db->prepare(Memberships::SWITCH_ON_ALL_SQL)', '$db->prepare($elsewhere)')}, {}, set(), 'R4'),
    # must stay green
    ('GREEN: the isSiteAdmin toggle', {}, {'web/_apps/x/a.php': php("$s = $db->prepare('UPDATE tblUserSites SET isSiteAdmin = ? WHERE userID = ? AND siteID = ?');")}, set(), None),
    ('GREEN: a comment quoting the old statements', {}, {'web/_apps/x/a.php': php("// was: UPDATE tblUserSites SET isActive = 0 WHERE userID = ?\n/* DELETE FROM tblUserSites WHERE userID = 1 */\n$x = 1;")}, set(), None),
    ('GREEN: a SELECT reading us.isActive and WHERE isActive = 0', {}, {'web/_apps/x/a.php': php("$s = $db->prepare('SELECT us.isActive FROM tblUserSites us WHERE us.isActive = 0');")}, set(), None),
    ('GREEN: INSERT with isActive 1, and one not naming it', {}, {'web/_apps/x/a.php': php("$a = $db->prepare('INSERT INTO tblUserSites (userID, siteID, isActive) VALUES (?, ?, 1)');\n$b = $db->prepare('INSERT IGNORE INTO tblUserSites (userID, siteID, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 0, 0)');")}, set(), None),
    ('GREEN: a DELETE from another table with a subquery reading tblUserSites', {}, {'web/_apps/x/a.php': php("$s = $db->prepare('DELETE FROM `tblConsentLog` WHERE EXISTS (SELECT 1 FROM `tblUserSites` `us` WHERE `us`.`isActive` = 0)');")}, set(), None),
    ('GREEN: UPDATE of tblUsers.isActive', {}, {'web/_apps/x/a.php': php("$s = $db->prepare('UPDATE tblUsers SET isActive = 0 WHERE userID = ?');")}, set(), None),
]


def self_test() -> int:
    base = make_tree({}, {}, set())
    try:
        base_problems = findings(base)
    finally:
        shutil.rmtree(base, ignore_errors=True)
    if base_problems:
        print('self-test: the unplanted copy of the real files is not clean:')
        for p in base_problems:
            print('  • ' + p)
        return 1
    bad = 0
    for name, edits, extra, drop, expect in PLANTS:
        tree = make_tree(edits, extra, drop)
        try:
            got = findings(tree)
        finally:
            shutil.rmtree(tree, ignore_errors=True)
        rules = sorted({m.group(1) for p in got if (m := re.match(r'\[(R\d)\]', p))})
        ok = (rules == [expect]) if expect is not None else (got == [])
        print(('  ok   ' if ok else '  FAIL ') + name + ('' if ok else f' -> expected {expect}, got {rules or "nothing"}: {got}'))
        bad += 0 if ok else 1
    print(f'\nself-test: {len(PLANTS)} plants ({sum(1 for p in PLANTS if p[4] is not None)} must be caught, '
          f'{sum(1 for p in PLANTS if p[4] is None)} must stay green), {bad} wrong.')
    if len(PLANTS) != 23:
        print('self-test: the number of plants changed; update the pinned count (23) on purpose.')
        return 1
    return 1 if bad else 0


if __name__ == '__main__':
    if '--self-test' in sys.argv:
        sys.exit(self_test())
    sys.exit(run(ROOT))
