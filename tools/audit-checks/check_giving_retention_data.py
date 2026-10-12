#!/usr/bin/env python3
"""
Giving-records data file check (roll-out package A2b, #574).

WHY THIS EXISTS
---------------
`web/_core/data/giving-retention-countries.json` holds, for each country, the
number of years the portal SUGGESTS an organisation keeps a donor's name on
its giving records after that donor has asked to be erased. An organisation
that has not typed a figure of its own follows the suggestion. So a slip in
this file - a figure with no source, a row cut off half way, a "not found"
that still carries a number - reaches every such customer at once, and it
decides how long personal data is kept.

The file has a schema beside it (`giving-retention-countries.schema.json`).
A schema nothing runs is a document, not a check. This script runs it.

WHAT THIS CHECKS
----------------
  A. The data file is valid JSON, with no key written twice. (Python's reader
     quietly keeps the LAST of two equal keys, so a country pasted in twice
     would otherwise lose its first row without a word.)
  B. The data file obeys the schema: every rule the schema states is applied
     by the small reader below (types, allowed words, number ranges, patterns,
     required and unexpected properties, and "no figure exactly when the
     strength says not found").
  C. Every property the schema describes has a `description`, because the
     schema is this file's only documentation: JSON has no comments.
  D. Rules the schema cannot state: the minimum suggestion is not above the
     placeholder; every date is a real calendar date; no link appears twice
     under one country; and every figure of 15 years or more is flagged
     `needsAdvice`, whatever the strength of its source, because a figure
     that long is for company books or annual accounts, not donor records.
     (Uruguay's 20 years had no flag at first: the rule had asked for a weak
     source as well.)
  E. The file is not cut short: at least 200 countries (the schema says so
     too; it is repeated here in words so the failure message is plain).

WHAT THIS CANNOT DO
-------------------
  - It cannot tell whether a figure is RIGHT. Every figure is a researcher's
    reading and none is legal fact. The research is in
    docs/research/giving-retention/.
  - It does not open any link.
  - It reads only the parts of JSON Schema this schema uses. A keyword it does
    not know is REPORTED as a finding, not skipped: a rule that was silently
    not applied would look exactly like a rule that passed.

Standard library only: no schema package is installed for this repository's
checks, and none is needed.

Exit codes
----------
  0 - no findings
  1 - at least one finding. Not gated behind --strict; --strict is accepted
      so every check in this folder can be run the same way.

Usage
-----
  python3 tools/audit-checks/check_giving_retention_data.py [--strict]
      [--data FILE] [--schema FILE]

Copyright (c) 2026-present MWBM Partners Ltd (t/a MWservices). All Rights Reserved.
"""

from __future__ import annotations

import argparse
import datetime
import json
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
DATA = REPO / 'web' / '_core' / 'data' / 'giving-retention-countries.json'
SCHEMA = REPO / 'web' / '_core' / 'data' / 'giving-retention-countries.schema.json'

# The schema keywords this reader applies. Anything else in the schema is a
# finding (see "WHAT THIS CANNOT DO").
APPLIED = {
    'type', 'enum', 'const', 'pattern', 'minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'minProperties',
    'required', 'properties', 'additionalProperties', 'propertyNames', 'items', '$ref', 'allOf', 'if', 'then', 'else',
}
# Words that say something about a schema and are not rules to apply.
ANNOTATIONS = {'$schema', '$id', 'title', 'description', '$comment', '$defs', 'format'}


class DuplicateKey(ValueError):
    """Raised when one JSON object holds the same key twice."""


def no_duplicates(pairs):
    seen = {}
    for key, value in pairs:
        if key in seen:
            raise DuplicateKey(key)
        seen[key] = value
    return seen


def type_is(value, name: str) -> bool:
    # bool is a kind of int in Python, and it is NOT a JSON integer.
    if name == 'object':
        return isinstance(value, dict)
    if name == 'array':
        return isinstance(value, list)
    if name == 'string':
        return isinstance(value, str)
    if name == 'integer':
        return isinstance(value, int) and not isinstance(value, bool)
    if name == 'boolean':
        return isinstance(value, bool)
    if name == 'null':
        return value is None
    return False


def validate(value, schema: dict, root: dict, where: str, findings: list, quiet: bool = False) -> bool:
    """Apply one schema to one value. Returns True when it fits."""
    found: list = []

    for keyword in schema:
        if keyword not in APPLIED and keyword not in ANNOTATIONS:
            found.append(f'{where}: the schema uses "{keyword}", which this check does not know how to apply')

    if '$ref' in schema:
        target = root
        for part in schema['$ref'].lstrip('#/').split('/'):
            target = target[part]
        validate(value, target, root, where, found)

    if 'type' in schema:
        names = schema['type'] if isinstance(schema['type'], list) else [schema['type']]
        if not any(type_is(value, name) for name in names):
            found.append(f'{where}: expected {" or ".join(names)}, found {json.dumps(value)[:60]}')
    if 'enum' in schema and value not in schema['enum']:
        found.append(f'{where}: {json.dumps(value)} is not one of {json.dumps(schema["enum"])}')
    if 'const' in schema and value != schema['const']:
        found.append(f'{where}: must be {json.dumps(schema["const"])}, found {json.dumps(value)}')

    if isinstance(value, str):
        if 'pattern' in schema and re.search(schema['pattern'], value) is None:
            found.append(f'{where}: {json.dumps(value)[:80]} does not have the shape {schema["pattern"]}')
        if 'minLength' in schema and len(value) < schema['minLength']:
            found.append(f'{where}: shorter than {schema["minLength"]} characters')
        if 'maxLength' in schema and len(value) > schema['maxLength']:
            found.append(f'{where}: longer than {schema["maxLength"]} characters ({len(value)})')

    if isinstance(value, int) and not isinstance(value, bool):
        if 'minimum' in schema and value < schema['minimum']:
            found.append(f'{where}: {value} is below the minimum of {schema["minimum"]}')
        if 'maximum' in schema and value > schema['maximum']:
            found.append(f'{where}: {value} is above the maximum of {schema["maximum"]}')

    if isinstance(value, list):
        if 'minItems' in schema and len(value) < schema['minItems']:
            found.append(f'{where}: needs at least {schema["minItems"]} entr{"y" if schema["minItems"] == 1 else "ies"}, has {len(value)}')
        if 'items' in schema:
            for n, item in enumerate(value):
                validate(item, schema['items'], root, f'{where}[{n}]', found)

    if isinstance(value, dict):
        if 'minProperties' in schema and len(value) < schema['minProperties']:
            found.append(f'{where}: needs at least {schema["minProperties"]} entries, has {len(value)}')
        for name in schema.get('required', []):
            if name not in value:
                found.append(f'{where}: "{name}" is missing')
        known = schema.get('properties', {})
        for name, sub in known.items():
            if name in value:
                validate(value[name], sub, root, f'{where}.{name}', found)
        if 'propertyNames' in schema:
            for name in value:
                validate(name, schema['propertyNames'], root, f'{where} (the key {json.dumps(name)})', found)
        extra = schema.get('additionalProperties')
        if extra is False:
            for name in value:
                if name not in known:
                    found.append(f'{where}: unexpected property "{name}"')
        elif isinstance(extra, dict):
            for name, item in value.items():
                if name not in known:
                    validate(item, extra, root, f'{where}.{name}', found)

    for sub in schema.get('allOf', []):
        validate(value, sub, root, where, found)
    if 'if' in schema:
        branch = 'then' if validate(value, schema['if'], root, where, [], quiet=True) else 'else'
        if branch in schema:
            validate(value, schema[branch], root, where, found)

    if not quiet:
        findings.extend(found)
    return not found


def undescribed(schema, where: str, out: list) -> None:
    """Every property the schema describes must say what it is."""
    if not isinstance(schema, dict):
        return
    for name, sub in schema.get('properties', {}).items():
        if not isinstance(sub.get('description'), str) or sub['description'].strip() == '':
            out.append(f'{where}.{name}: the schema gives this property no description')
        undescribed(sub, f'{where}.{name}', out)
    for name, sub in schema.get('$defs', {}).items():
        if not isinstance(sub.get('description'), str) or sub['description'].strip() == '':
            out.append(f'$defs.{name}: the schema gives this no description')
        undescribed(sub, f'$defs.{name}', out)


def real_date(text) -> bool:
    try:
        datetime.date.fromisoformat(text)
        return isinstance(text, str) and len(text) == 10
    except (TypeError, ValueError):
        return False


def check(data_path: Path, schema_path: Path) -> int:
    findings: list = []

    try:
        schema = json.loads(schema_path.read_text(encoding='utf-8'), object_pairs_hook=no_duplicates)
    except (OSError, ValueError) as problem:
        print(f'FAIL - the schema could not be read ({schema_path}): {problem}')
        return 1
    try:
        data = json.loads(data_path.read_text(encoding='utf-8'), object_pairs_hook=no_duplicates)
    except DuplicateKey as problem:
        print(f'FAIL - the data file holds the key {problem} twice in one place, so one of the two rows would be lost')
        return 1
    except (OSError, ValueError) as problem:
        print(f'FAIL - the data file is not valid JSON ({data_path}): {problem}')
        return 1

    validate(data, schema, schema, 'file', findings)
    undescribed(schema, 'schema', findings)

    if isinstance(data, dict):
        countries = data.get('countries') if isinstance(data.get('countries'), dict) else {}
        if len(countries) < 200:
            findings.append(f'only {len(countries)} countries are present; at least 200 are expected, so the file looks cut short')
        minimum, placeholder = data.get('minimumSuggestedYears'), data.get('placeholderYears')
        if type_is(minimum, 'integer') and type_is(placeholder, 'integer') and minimum > placeholder:
            findings.append(f'minimumSuggestedYears ({minimum}) is above placeholderYears ({placeholder}): a country with no '
                            'figure would be suggested LESS than one with a short figure')
        if not real_date(data.get('updatedOn')):
            findings.append(f'updatedOn is not a real date: {json.dumps(data.get("updatedOn"))}')
        for code, row in countries.items():
            if not isinstance(row, dict):
                continue
            if not real_date(row.get('checkedOn')):
                findings.append(f'{code}: checkedOn is not a real date: {json.dumps(row.get("checkedOn"))}')
            years = row.get('yearsFound')
            if type_is(years, 'integer') and years >= 15 and row.get('needsAdvice') is not True:
                findings.append(f'{code}: a figure of {years} years is not flagged needsAdvice; a figure that long is for '
                                'company books or annual accounts, and the page must say to take care with it')
            links = [s.get('url') for s in row.get('sources', []) if isinstance(s, dict)]
            for url in sorted({u for u in links if links.count(u) > 1}):
                # The same SITE may be linked for two documents (a report that
                # recorded only the site); the same title AND link twice is a slip.
                titles = [s.get('title') for s in row['sources'] if isinstance(s, dict) and s.get('url') == url]
                if len(set(titles)) < len(titles):
                    findings.append(f'{code}: the same source appears twice ({url})')

    if findings:
        print(f'FAIL - {len(findings)} problem(s) in {data_path.name}:')
        for line in findings[:60]:
            print(f'  - {line}')
        if len(findings) > 60:
            print(f'  ... and {len(findings) - 60} more')
        return 1

    countries = data['countries']
    with_figure = sum(1 for row in countries.values() if row['yearsFound'] is not None)
    print(f'OK - {len(countries)} countries, {with_figure} with a figure and {len(countries) - with_figure} without; '
          f'placeholder {data["placeholderYears"]} years, minimum suggestion {data["minimumSuggestedYears"]} years; '
          'the data file obeys its schema and every schema property is described.')
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split('\n\n')[0])
    parser.add_argument('--strict', action='store_true', help='accepted for consistency; findings always fail')
    parser.add_argument('--data', type=Path, default=DATA, help='the data file to check (for tests of this check)')
    parser.add_argument('--schema', type=Path, default=SCHEMA, help='the schema to check it against')
    args = parser.parse_args()
    return check(args.data, args.schema)


if __name__ == '__main__':
    sys.exit(main())
