#!/usr/bin/env python3
# -----------------------------------------------------------------------------
# tools/audit-checks/check_backup_column_types.py — can the portal's own backup
# carry everything the database holds?
# -----------------------------------------------------------------------------
# @package   WebMS-Intra (development tooling — never deployed; only web/ is)
# @author    MWBM Partners Ltd (t/a MWservices)
# @copyright MWBM Partners Ltd (t/a MWservices). All Rights Reserved.
# @version   1.1.0 (12 October 2026, #577)
"""
Backup column-type and snapshot-format check (#577, #578).

WHY THIS EXISTS
---------------
On 12 October 2026 the portal's own backup was found to fail the moment one
imported calendar event existed. The calendar import had added a column of raw
bytes (`BINARY(32)`), the backup wrote every value as text, and nothing
compared the two. The fault was in a class nobody had touched; it was caused by
a new column somewhere else.

So this check reads the install script and asks, for EVERY column and EVERY
link between tables, "can `Portal\\Core\\DbBackup` copy this and put it back?".
The next unusual column fails here, on the pull request that adds it.

WHAT IT CHECKS
--------------
Part A, the install script (`web/_sql/full_schema.sql`) against the class:

  A1. Every column's type is on one of the two lists in
      `web/_core/DbBackup.php` (`TEXT_TYPES`, `BYTE_TYPES`). The lists are read
      out of that file, so there is one source of truth. A type on neither
      list is one the backup REFUSES at snapshot time (BIT and FLOAT are left
      off on purpose: neither survives the trip), so a table holding one could
      never be backed up at all.
  A2. A table whose rows point at rows of the same table does so through a
      column that may be empty, and has a primary key. The restore writes such
      rows with the pointer empty and fills it in afterwards; it can do
      neither without those two things.
  A3. No tables point at each other in a ring. The restore empties and refills
      tables in an order, and a ring has none.

Part B, the snapshot's format against its two schema files
(`web/_core/data/backup-manifest.schema.json`, `backup-table-file.schema.json`):

  B1. Both schemas give a description to every property. JSON has no
      comments, so the schema is the format's only documentation.
  B2. A real sample snapshot (`tools/audit-checks/fixtures/backup_format/good/`,
      written by `tools/backup-restore-selftest.php --write-samples` from the
      real class) obeys both schemas, line by line.
  B3. What a schema cannot say: each row has as many values as the first line
      has columns; the last line's count, the manifest's count and the number
      of rows agree; the file's checksum is the manifest's; every base64 value
      really is base64.

It prints how many tables, columns and links it read. A check that found
nothing because it read nothing would otherwise look exactly like a pass.

`--self-test` proves each rule fires: it runs the check on small planted
files under `fixtures/backup_column_types/` and on damaged copies of the
sample, and fails unless every one is caught for the right reason.

WHAT THIS CANNOT DO
-------------------
  - It reads the install script as text. It has never asked a database
    anything, so it cannot see a column added by hand on a live server, or one
    a numbered migration adds that the install script does not have (a
    separate check compares those two).
  - It knows the type NAMES the install script uses today and the common
    other spellings (INTEGER, BOOLEAN, NUMERIC, REAL). A spelling it does not
    know is reported as a finding, not guessed at.
  - It reads only some forms of ALTER TABLE: adding a column, a link, a
    primary key or an index. Any other form (MODIFY, CHANGE, several columns
    in brackets, dropping or renaming a column) is a FINDING that names the
    statement, because the check cannot tell what the table looks like after
    it. The install script holds no ALTER TABLE that is not inside a guarded,
    quoted statement today.
  - It cannot tell whether a type on the lists really survives a backup. That
    is proved against a real database by `tools/backup-restore-selftest.php`
    (proof 14), which also fails if a type is on a list without being proved.
  - Part B checks ONE small sample, not every snapshot anybody will ever take.

The schema reader is borrowed from `check_giving_retention_data.py` in this
folder, so there is one of those, not two.

Exit codes
----------
  0 - no findings
  1 - at least one finding. Not gated behind --strict; --strict is accepted
      so every check in this folder can be run the same way.
  2 - a file it needs could not be read.

Usage
-----
  python3 tools/audit-checks/check_backup_column_types.py [--strict]
      [--schema FILE] [--class FILE] [--sample FOLDER] [--self-test]
"""

from __future__ import annotations

import argparse
import base64
import binascii
import hashlib
import json
import re
import shutil
import sys
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[1]
sys.path.insert(0, str(HERE))
import check_giving_retention_data as schema_reader  # noqa: E402  (the one schema reader in this folder)

SQL_FILE = REPO / 'web' / '_sql' / 'full_schema.sql'
CLASS_FILE = REPO / 'web' / '_core' / 'DbBackup.php'
MANIFEST_SCHEMA = REPO / 'web' / '_core' / 'data' / 'backup-manifest.schema.json'
TABLE_SCHEMA = REPO / 'web' / '_core' / 'data' / 'backup-table-file.schema.json'
SAMPLE = HERE / 'fixtures' / 'backup_format' / 'good'
SQL_FIXTURES = HERE / 'fixtures' / 'backup_column_types'

# Other spellings the database accepts for a type, and the name it then
# reports (which is the name on the class's lists).
SAME_TYPE = {
    'integer': 'int', 'bool': 'tinyint', 'boolean': 'tinyint', 'numeric': 'decimal',
    'dec': 'decimal', 'fixed': 'decimal', 'real': 'double', 'character': 'char',
}
# The first word of a line inside CREATE TABLE that is NOT a column.
NOT_A_COLUMN = {'primary', 'key', 'index', 'unique', 'constraint', 'foreign', 'check', 'fulltext', 'spatial'}

NAME = r'`?([A-Za-z_][A-Za-z0-9_]*)`?'
# A table may be named with its database in front: `portal`.`tblVenues`. The
# database's name is passed over and the table's own name is what is read.
# (Without this, `ALTER TABLE \`portal\`.\`tblVenues\` ADD COLUMN x FLOAT` matched
# nothing at all, so the statement was skipped in silence and the check
# passed: the second independent check of 12 October 2026 planted it.)
IN_DATABASE = r'(?:`?[A-Za-z_][A-Za-z0-9_]*`?\s*\.\s*)?'
CREATE_RE = re.compile(r'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' + IN_DATABASE + NAME + r'\s*\(', re.IGNORECASE)
ALTER_RE = re.compile(r'ALTER\s+TABLE\s+' + IN_DATABASE + NAME + r'\s+(.*?)(?:;|\Z)', re.IGNORECASE | re.DOTALL)
LINK_RE = re.compile(r'FOREIGN\s+KEY\s*\(([^)]*)\)\s*REFERENCES\s+' + IN_DATABASE + NAME + r'\s*\(([^)]*)\)', re.IGNORECASE)
# Every place the words ALTER TABLE or CREATE TABLE begin a statement, however
# the rest is written. Used to find the ones the two patterns above could not
# take at all, so that those are REPORTED and never skipped.
ANY_ALTER_RE = re.compile(r'ALTER\s+TABLE\b', re.IGNORECASE)
ANY_CREATE_RE = re.compile(r'CREATE\s+TABLE\b', re.IGNORECASE)
PRIMARY_RE = re.compile(r'PRIMARY\s+KEY\s*\(([^)]*)\)', re.IGNORECASE)
# One part of an ALTER TABLE statement that adds a column: "ADD [COLUMN] name type ...".
# The name may or may not be in backticks. (It used to have to be, and the word
# COLUMN was the only thing that made `ADD name BIT(4)` look like a column at
# all: without both, the column was skipped and the check passed.)
ADD_COLUMN_RE = re.compile(r'ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?' + NAME + r'\s+([A-Za-z]+)(.*)', re.IGNORECASE | re.DOTALL)
ADD_PRIMARY_RE = re.compile(r'ADD\s+(?:CONSTRAINT\s+(?:`?[A-Za-z_][A-Za-z0-9_]*`?\s+)?)?PRIMARY\s+KEY\s*\(([^)]*)\)', re.IGNORECASE)
# Parts of an ALTER TABLE statement that cannot add or change a column's type
# and cannot add a link: an index, a "no two rows the same" rule, a CHECK, the
# removal of any of those or of a link, and a table option. Safe to pass over.
HARMLESS_ALTER_RE = re.compile(
    r'(?:ADD\s+(?:CONSTRAINT\s+(?:`?[A-Za-z_][A-Za-z0-9_]*`?\s+)?)?(?:UNIQUE|FULLTEXT|SPATIAL|INDEX|KEY|CHECK)\b'
    r'|DROP\s+(?:INDEX|KEY|FOREIGN\s+KEY|CONSTRAINT|CHECK)\b'
    r'|(?:ENGINE|AUTO_INCREMENT|COMMENT|ROW_FORMAT)\b)',
    re.IGNORECASE)


# -----------------------------------------------------------------------------
# Part A: reading the install script
# -----------------------------------------------------------------------------

def strip_sql(sql: str) -> tuple[str, list[str]]:
    """Take the comments out and empty every quoted text, keeping the quotes.

    Returns the cleaned SQL and the quoted texts that were emptied. They are
    kept because this codebase adds some columns and links from INSIDE a
    quoted text (the guarded `PREPARE` ... `EXECUTE` form), and those are as
    real as any other.
    """
    out: list[str] = []
    texts: list[str] = []
    i, n = 0, len(sql)
    while i < n:
        ch = sql[i]
        two = sql[i:i + 2]
        if two == '--' and (i + 2 >= n or sql[i + 2] in ' \t\r\n'):
            while i < n and sql[i] != '\n':
                i += 1
        elif ch == '#':
            while i < n and sql[i] != '\n':
                i += 1
        elif two == '/*':
            end = sql.find('*/', i + 2)
            i = n if end == -1 else end + 2
            out.append(' ')
        elif ch == "'":
            i += 1
            text: list[str] = []
            while i < n:
                if sql[i] == '\\' and i + 1 < n:
                    text.append(sql[i + 1])
                    i += 2
                elif sql[i] == "'" and sql[i + 1:i + 2] == "'":
                    text.append("'")
                    i += 2
                elif sql[i] == "'":
                    i += 1
                    break
                else:
                    text.append(sql[i])
                    i += 1
            texts.append(''.join(text))
            out.append("''")
        else:
            out.append(ch)
            i += 1
    return ''.join(out), texts


def split_top_level(body: str) -> list[str]:
    """Split the inside of CREATE TABLE ( ... ) at commas that are not inside brackets."""
    parts, depth, start = [], 0, 0
    for i, ch in enumerate(body):
        if ch == '(':
            depth += 1
        elif ch == ')':
            depth -= 1
        elif ch == ',' and depth == 0:
            parts.append(body[start:i])
            start = i + 1
    parts.append(body[start:])
    return [part.strip() for part in parts if part.strip()]


def names_in(column_list: str) -> list[str]:
    return re.findall(r'[A-Za-z_][A-Za-z0-9_]*', re.sub(r'\(\d+\)', '', column_list))


class Schema:
    """What the install script declares: tables, their columns, keys and links."""

    def __init__(self) -> None:
        self.columns: dict[str, dict[str, dict]] = {}    # table -> column -> {type, nullable, generated}
        self.primary: dict[str, list[str]] = {}          # table -> key columns
        self.links: list[tuple[str, tuple[str, ...], str]] = []   # (table, pointing columns, table pointed at)
        self.unread: list[tuple[str, str]] = []          # (table, an ALTER TABLE statement this check could not fully read)
        self.unreadable: list[str] = []                  # statements whose table NAME could not even be read

    def add_column(self, table: str, name: str, type_word: str, rest: str) -> None:
        if name in self.columns.setdefault(table, {}):
            return
        generated = re.search(r'\bGENERATED\s+ALWAYS\b|\bAS\s*\(', rest, re.IGNORECASE) is not None
        inline_key = re.search(r'\bPRIMARY\s+KEY\b', rest, re.IGNORECASE) is not None
        self.columns[table][name] = {
            'type': SAME_TYPE.get(type_word.lower(), type_word.lower()),
            'nullable': re.search(r'\bNOT\s+NULL\b', rest, re.IGNORECASE) is None and not inline_key,
            'generated': generated,
        }
        if inline_key:
            self.primary.setdefault(table, [name])

    def add_link(self, table: str, columns: str, parent: str) -> None:
        link = (table, tuple(names_in(columns)), parent)
        if link not in self.links:
            self.links.append(link)

    def read_alter(self, sql: str) -> None:
        """Read every ALTER TABLE statement, part by part, and REMEMBER any part it cannot read.

        A statement is a list of parts separated by commas. Each part is either
        read (a column, a link, a primary key), known to be harmless (see
        HARMLESS_ALTER_RE), or neither. "Neither" used to be skipped in
        silence, so `MODIFY latitude FLOAT`, `CHANGE ... BIT(8)`, `ADD (a INT,
        b FLOAT)` and `ADD flags BIT(4)` all changed what the backup would
        meet and the check still said OK (the independent check of 12 October
        2026 planted all four). Now an unread part is a finding that names the
        statement. A check that cannot read something must say so, not pass.
        """
        # First, every ALTER TABLE the pattern cannot take at all (a table
        # name in a form it does not know). Its table cannot be named, so it
        # cannot be judged, and it is reported whatever it is.
        read_from = {match.start() for match in ALTER_RE.finditer(sql)}
        for found in ANY_ALTER_RE.finditer(sql):
            if found.start() not in read_from:
                end = sql.find(';', found.start())
                self.unreadable.append(re.sub(r'\s+', ' ', sql[found.start():end if end != -1 else len(sql)]).strip())

        for match in ALTER_RE.finditer(sql):
            table, body = match.group(1), match.group(2)
            statement = re.sub(r'\s+', ' ', f'ALTER TABLE {table} {body}').strip()
            if table not in self.columns:
                # A table this check never saw being made: nothing about the
                # statement can be judged.
                self.unread.append((table, statement))
                continue
            for part in split_top_level(body):
                link = LINK_RE.search(part)
                key = ADD_PRIMARY_RE.match(part)
                column = ADD_COLUMN_RE.match(part)
                if part.upper().startswith('ADD') and link:
                    self.add_link(table, link.group(1), link.group(2))
                elif key:
                    self.primary.setdefault(table, names_in(key.group(1)))
                elif HARMLESS_ALTER_RE.match(part):
                    continue
                elif column and column.group(1).lower() not in NOT_A_COLUMN:
                    self.add_column(table, column.group(1), column.group(2), column.group(3))
                else:
                    self.unread.append((table, statement))
                    break


def read_schema(sql: str) -> Schema:
    schema = Schema()
    clean, texts = strip_sql(sql)

    # A CREATE TABLE the pattern cannot take at all is reported, for the same
    # reason as an ALTER TABLE it cannot take (see read_alter()).
    made_from = {match.start() for match in CREATE_RE.finditer(clean)}
    for found in ANY_CREATE_RE.finditer(clean):
        if found.start() not in made_from:
            schema.unreadable.append(re.sub(r'\s+', ' ', clean[found.start():found.start() + 200]).strip())

    for match in CREATE_RE.finditer(clean):
        table = match.group(1)
        if table in schema.columns:
            continue   # CREATE TABLE IF NOT EXISTS for a table that exists does nothing
        depth, i = 1, match.end()
        while i < len(clean) and depth > 0:
            depth += {'(': 1, ')': -1}.get(clean[i], 0)
            i += 1
        schema.columns[table] = {}
        for item in split_top_level(clean[match.end():i - 1]):
            first = re.match(r'`?([A-Za-z_][A-Za-z0-9_]*)`?', item)
            word = first.group(1).lower() if first else ''
            if word in NOT_A_COLUMN and not item.startswith('`'):
                key = PRIMARY_RE.match(item)
                if key:
                    schema.primary[table] = names_in(key.group(1))
                link = LINK_RE.search(item)
                if link:
                    schema.add_link(table, link.group(1), link.group(2))
                continue
            column = re.match(r'`?([A-Za-z_][A-Za-z0-9_]*)`?\s+([A-Za-z]+)(.*)', item, re.DOTALL)
            if column:
                schema.add_column(table, column.group(1), column.group(2), column.group(3))
                link = LINK_RE.search(item)   # a link written on the column's own line
                if link:
                    schema.add_link(table, column.group(1), link.group(2))

    schema.read_alter(clean)
    for text in texts:
        if re.search(r'ALTER\s+TABLE', text, re.IGNORECASE):
            schema.read_alter(strip_sql(text)[0])
    return schema


def read_type_lists(class_text: str) -> tuple[list[str], list[str]]:
    lists = []
    for name in ('TEXT_TYPES', 'BYTE_TYPES'):
        match = re.search(r'public\s+const\s+' + name + r'\s*=\s*\[(.*?)\];', class_text, re.DOTALL)
        lists.append(re.findall(r"'([a-z]+)'", match.group(1)) if match else [])
    return lists[0], lists[1]


def check_schema(sql_path: Path, class_path: Path) -> tuple[list[str], str]:
    """Part A. Returns (findings, a line saying how much was read)."""
    findings: list[str] = []
    text_types, byte_types = read_type_lists(class_path.read_text(encoding='utf-8'))
    if not text_types or not byte_types:
        return [f'the lists TEXT_TYPES and BYTE_TYPES could not be read out of {class_path.name}, so no column could be checked'], ''
    known = set(text_types) | set(byte_types)

    schema = read_schema(sql_path.read_text(encoding='utf-8'))
    tables = {table: columns for table, columns in schema.columns.items() if table.lower().startswith('tbl')}
    copied = worked_out = 0
    for table, columns in sorted(tables.items()):
        for name, column in columns.items():
            if column['generated']:
                worked_out += 1
                continue
            copied += 1
            if column['type'] not in known:
                findings.append(
                    f'{table}.{name} has the type "{column["type"]}", which the backup has not been taught to copy '
                    f'faithfully. A snapshot of this database would be refused. Use a type on the lists in '
                    f'{class_path.name}, or teach the backup this one and prove it in tools/backup-restore-selftest.php.')

    for table, statement in schema.unread:
        if table.lower().startswith('tbl'):
            findings.append(
                f'an ALTER TABLE statement changes {table} in a way this check cannot read, so it cannot tell '
                f'whether the backup can still copy that table: "{statement[:200]}". Write the change into the '
                f'CREATE TABLE statement for {table} instead (the install script builds a new database, so it has '
                f'nothing to alter), or teach this check that form.')

    for statement in schema.unreadable:
        findings.append(
            f'a statement names its table in a form this check cannot read, so it cannot tell which table is changed '
            f'or whether the backup can still copy it: "{statement[:200]}". Name the table plainly (letters, digits '
            f'and underscores, with or without backticks, with or without its database in front), or teach this '
            f'check that form.')

    links = [link for link in schema.links if link[0] in tables]
    to_itself = [link for link in links if link[0] == link[2]]
    for table, pointers, _ in to_itself:
        if not schema.primary.get(table):
            findings.append(f'{table} points at rows of itself and has no primary key, so a restore could not find '
                            'each row again to fill its pointer in.')
        for pointer in pointers:
            if not tables[table].get(pointer, {}).get('nullable', False):
                findings.append(f'{table}.{pointer} points at rows of the same table and may not be empty. A restore '
                                'writes such rows with the pointer empty first, so this table could not be restored.')

    # A ring: peel off, again and again, every table all of whose parents are
    # already placed. Whatever is left when nothing more can be placed is one.
    parents = {table: set() for table in tables}
    for table, _, parent in links:
        if parent != table and parent in tables:
            parents[table].add(parent)
    left = set(tables)
    while True:
        ready = {table for table in left if not (parents[table] & left)}
        if not ready:
            break
        left -= ready
    if left:
        findings.append('these tables point at each other in a ring, so a restore has no order to refill them in: '
                        + ', '.join(sorted(left)))

    read = (f'read {len(tables)} tables, {copied + worked_out} columns ({copied} copied by the backup, {worked_out} worked '
            f'out by the database and left out) and {len(links)} links between tables ({len(to_itself)} from a table to '
            f'itself); the backup knows {len(text_types)} text types and {len(byte_types)} byte types')
    if copied == 0 or not links:
        findings.append('no columns or no links were read from the install script, so nothing was really checked')
    return findings, read


# -----------------------------------------------------------------------------
# Part B: the snapshot's format against its schemas
# -----------------------------------------------------------------------------

def load_json(path: Path):
    return json.loads(path.read_text(encoding='utf-8'), object_pairs_hook=schema_reader.no_duplicates)


def is_base64(text) -> bool:
    try:
        base64.b64decode(text, validate=True)
        return isinstance(text, str)
    except (binascii.Error, ValueError, TypeError):
        return False


def check_format(sample: Path, manifest_schema_path: Path, table_schema_path: Path) -> tuple[list[str], str]:
    """Part B. Returns (findings, a line saying how much was read)."""
    findings: list[str] = []
    manifest_schema = load_json(manifest_schema_path)
    table_schema = load_json(table_schema_path)
    schema_reader.undescribed(manifest_schema, 'manifest schema', findings)
    schema_reader.undescribed(table_schema, 'table-file schema', findings)
    for name, schema in (('manifest schema', manifest_schema), ('table-file schema', table_schema)):
        if not isinstance(schema.get('description'), str) or not isinstance(schema.get('$comment'), str):
            findings.append(f'the {name} has no description or no $comment of its own')

    manifest = load_json(sample / '_manifest.json')
    schema_reader.validate(manifest, manifest_schema, manifest_schema, 'manifest', findings)

    rows_read = 0
    tables = manifest.get('tables', {}) if isinstance(manifest, dict) else {}
    for table, entry in (tables.items() if isinstance(tables, dict) else []):
        if not isinstance(entry, dict):
            continue
        path = sample / str(entry.get('file', ''))
        if entry.get('file') != table + '.jsonl' or not path.is_file():
            findings.append(f'{table}: the manifest names the file {entry.get("file")!r}, which is not {table}.jsonl or is missing')
            continue
        raw = path.read_bytes()
        if hashlib.sha256(raw).hexdigest() != entry.get('sha256'):
            findings.append(f'{table}: the file\'s checksum is not the one in the manifest')
        lines = raw.decode('utf-8').split('\n')
        if lines[-1] != '':
            findings.append(f'{table}: the file does not end with a line-feed')
        lines = lines[:-1] if lines[-1] == '' else lines
        try:
            decoded = [json.loads(line, object_pairs_hook=schema_reader.no_duplicates) for line in lines]
        except ValueError as problem:
            findings.append(f'{table}: a line is not valid JSON ({problem})')
            continue
        if len(decoded) < 2:
            findings.append(f'{table}: the file has no first line and last line')
            continue

        first, rows, last = decoded[0], decoded[1:-1], decoded[-1]
        defs = table_schema['$defs']
        ok = schema_reader.validate(first, defs['firstLine'], table_schema, f'{table} first line', findings)
        last_ok = schema_reader.validate(last, defs['lastLine'], table_schema, f'{table} last line', findings)
        for n, row in enumerate(rows, start=1):
            schema_reader.validate(row, defs['row'], table_schema, f'{table} row {n}', findings)
        if not ok or not last_ok:
            continue

        if first['table'] != table:
            findings.append(f'{table}: the first line says the table is {first["table"]}')
        if first['columns'] != entry.get('columns'):
            findings.append(f'{table}: the first line\'s columns are not the manifest\'s columns')
        if not set(first['base64']) <= set(first['columns']):
            findings.append(f'{table}: base64 names a column that is not in columns')
        if last['rows'] != len(rows) or entry.get('rows') != len(rows):
            findings.append(f'{table}: {len(rows)} rows are present, the last line says {last["rows"]} and the manifest says {entry.get("rows")}')
        as_bytes = [name in first['base64'] for name in first['columns']]
        for n, row in enumerate(rows, start=1):
            if not isinstance(row, list):
                continue
            if len(row) != len(first['columns']):
                findings.append(f'{table} row {n}: {len(row)} values for {len(first["columns"])} columns')
                continue
            for value, bytes_column, column in zip(row, as_bytes, first['columns']):
                packed = value.get('b64') if isinstance(value, dict) else (value if bytes_column else None)
                if packed is not None and not is_base64(packed):
                    findings.append(f'{table} row {n}: the value of {column} should be base64 and is not')
        rows_read += len(rows)

    read = f'read a sample snapshot of {len(tables)} tables and {rows_read} rows against both schema files'
    if not tables or rows_read == 0:
        findings.append('the sample snapshot holds no tables or no rows, so the format was not really checked')
    return findings, read


# -----------------------------------------------------------------------------
# Proving the check itself fires
# -----------------------------------------------------------------------------

def self_test() -> int:
    """Run the check on planted faults; fail unless each is caught for the right reason."""
    problems: list[str] = []
    passed = 0

    def expect(what: str, findings: list[str], wanted: str | None) -> None:
        nonlocal passed
        text = ' | '.join(findings)
        if wanted is None:
            good = not findings
        else:
            good = wanted in text
        if good:
            passed += 1
            print(f'  ok    {what}')
        else:
            problems.append(what)
            print(f'  FAIL  {what}\n          wanted: {wanted or "no findings"}\n          got:    {text[:300] or "no findings"}')

    sql_cases = [
        ('good.sql', None, 'a small sound schema passes'),
        ('float.sql', 'tblFixtureReadings.ratio has the type "float"', 'a FLOAT column is caught'),
        ('bit.sql', 'tblFixtureFlags.flags has the type "bit"', 'a BIT column is caught'),
        ('not_null_self_pointer.sql', 'tblFixtureTree.parentID points at rows of the same table and may not be empty',
         'a self-pointer that may not be empty is caught'),
        ('self_pointer_no_key.sql', 'tblFixtureTree points at rows of itself and has no primary key', 'a self-pointing table with no key is caught'),
        ('ring.sql', 'point at each other in a ring', 'two tables pointing at each other are caught'),
        ('link_in_quoted_statement.sql', 'point at each other in a ring', 'a link added from inside a quoted statement is read'),
        ('alter_add_without_column_word.sql', 'tblFixtureFlags.flags has the type "bit"',
         'a column added by ALTER TABLE without the word COLUMN and without backticks is read'),
        ('alter_add_in_brackets.sql', 'ALTER TABLE tblFixtureReadings ADD (', 'ALTER TABLE ... ADD (two columns in brackets) is reported as unread'),
        ('alter_modify.sql', 'ALTER TABLE tblFixtureReadings MODIFY', 'ALTER TABLE ... MODIFY is reported as unread'),
        ('alter_change.sql', 'ALTER TABLE tblFixtureReadings CHANGE', 'ALTER TABLE ... CHANGE is reported as unread'),
        ('alter_harmless.sql', None, 'ALTER TABLE statements that only add an index, a key or a link are read and pass'),
        ('alter_database_in_front.sql', 'tblFixtureFlags.flags has the type "bit"',
         'a column added by ALTER TABLE that names the table with its database in front is read'),
        ('alter_name_not_readable.sql', 'names its table in a form this check cannot read',
         'an ALTER TABLE whose table name this check cannot read at all is reported, not skipped'),
    ]
    for name, wanted, what in sql_cases:
        findings, _ = check_schema(SQL_FIXTURES / name, CLASS_FILE)
        expect(what, findings, wanted)

    def damaged(change) -> list[str]:
        with tempfile.TemporaryDirectory() as scratch:
            copy = Path(scratch) / 'sample'
            shutil.copytree(SAMPLE, copy)
            change(copy)
            return check_format(copy, MANIFEST_SCHEMA, TABLE_SCHEMA)[0]

    def edit_manifest(copy: Path, change) -> None:
        manifest = json.loads((copy / '_manifest.json').read_text(encoding='utf-8'))
        change(manifest)
        (copy / '_manifest.json').write_text(json.dumps(manifest), encoding='utf-8')

    def edit_lines(copy: Path, change) -> None:
        """Change the choices file's lines, then make the manifest's checksum match, so only the format is at fault."""
        path = copy / 'tblExternalEventChoices.jsonl'
        lines = path.read_text(encoding='utf-8').split('\n')[:-1]
        text = '\n'.join(change(lines)) + '\n'
        path.write_text(text, encoding='utf-8')
        edit_manifest(copy, lambda m: m['tables']['tblExternalEventChoices'].__setitem__('sha256', hashlib.sha256(text.encode()).hexdigest()))

    def number_in_a_row(lines: list[str]) -> list[str]:
        row = json.loads(lines[1])
        row[0] = 970801
        return [lines[0], json.dumps(row)] + lines[2:]

    def broken_base64(lines: list[str]) -> list[str]:
        head, row = json.loads(lines[0]), json.loads(lines[1])
        row[head['columns'].index('externalUidHash')] = 'not base64!'
        return [lines[0], json.dumps(row)] + lines[2:]

    expect('the committed sample obeys both schemas', check_format(SAMPLE, MANIFEST_SCHEMA, TABLE_SCHEMA)[0], None)
    expect('a manifest with no "format" is caught', damaged(lambda c: edit_manifest(c, lambda m: m.pop('format'))), '"format" is missing')
    expect('a manifest with a property the schema does not describe is caught',
           damaged(lambda c: edit_manifest(c, lambda m: m.__setitem__('note', 'x'))), 'unexpected property "note"')
    expect('a number where a row should hold text is caught', damaged(lambda c: edit_lines(c, number_in_a_row)), 'expected string or null or object')
    expect('a file with its last line missing is caught', damaged(lambda c: edit_lines(c, lambda lines: lines[:-1])), 'last line')
    expect('a row with one value too many is caught',
           damaged(lambda c: edit_lines(c, lambda lines: [lines[0], lines[1][:-1] + ',"extra"]'] + lines[2:])), 'values for')
    expect('a bytes column that is not base64 is caught', damaged(lambda c: edit_lines(c, broken_base64)), 'should be base64 and is not')
    expect('a file changed after the snapshot (checksum) is caught',
           damaged(lambda c: (c / 'tblExternalFeeds.jsonl').write_text((c / 'tblExternalFeeds.jsonl').read_text(encoding='utf-8').replace('Self-test', 'Changed'), encoding='utf-8')),
           'checksum is not the one in the manifest')

    print(f'\n{passed} of {passed + len(problems)} self-test cases passed.')
    print('SELF-TEST REACHED THE END')
    return 0 if not problems else 1


# -----------------------------------------------------------------------------

def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split('\n\n')[0])
    parser.add_argument('--strict', action='store_true', help='accepted for consistency; findings always fail')
    parser.add_argument('--schema', type=Path, default=SQL_FILE, help='the install script to read')
    parser.add_argument('--class', dest='class_file', type=Path, default=CLASS_FILE, help='the PHP class holding the two type lists')
    parser.add_argument('--sample', type=Path, default=SAMPLE, help='the sample snapshot folder')
    parser.add_argument('--self-test', action='store_true', help='prove each rule fires, on planted faults')
    args = parser.parse_args()

    try:
        if args.self_test:
            return self_test()
        schema_findings, schema_read = check_schema(args.schema, args.class_file)
        format_findings, format_read = check_format(args.sample, MANIFEST_SCHEMA, TABLE_SCHEMA)
    except (OSError, UnicodeDecodeError, ValueError, KeyError) as problem:
        print(f'FAIL - a file this check needs could not be read: {problem}')
        return 2

    findings = schema_findings + format_findings
    if findings:
        print(f'FAIL - {len(findings)} problem(s) with what the portal\'s own backup can carry:')
        for line in findings[:60]:
            print(f'  • {line}')
        if len(findings) > 60:
            print(f'  ... and {len(findings) - 60} more')
        print(f'({schema_read}; {format_read}.)')
        return 1

    print(f'OK - {schema_read}.')
    print(f'OK - {format_read}; every schema property is described.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
