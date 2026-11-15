#!/usr/bin/env python3
# Path: tools/audit-checks/check_network_address_periods.py
"""
-----------------------------------------------------------------------------
Every column that keeps a network address says how long it is kept
(#1019; the owner's decision D-178) 🌐
-----------------------------------------------------------------------------
WHAT IT GUARDS. D-178 keeps network (IP) addresses for audit, security and
support, and handles them as personal data: every place that keeps one has a
set keeping period, an erasure reaches the ones on a person's own records, and
the privacy notice names them. The personal-data catalogue holds one sentence
per such column (`addressKept`), and the privacy pages print those sentences
through `Portal\\Core\\NetworkAddresses`. Before #1019 nothing tied the two
together, so an anonymous prayer request kept its sender's address for ever
while nothing said so, and a new feature could add an address column that no
page mentioned.

So this check fails on:

  MISSING   a column in `web/_sql/full_schema.sql` SHAPED like an address (see
            below) with no `addressKept` sentence in the catalogue - the
            #1019 acceptance line "a check fails when a new address column
            appears without one";
  STALE     a sentence naming a table or column the schema does not have;
  SHORT     a sentence too short to tell a member anything, or one that does
            not say "network address" (a member must be able to tell what it
            is about);
  PERIOD    (#1019 round 2) a column the nightly clear-out empties on the
            network-address period (`NetworkAddressRetention::PLACES`) whose
            sentence does not say it follows "the period for network
            addresses", or a sentence that says so for a column the clear-out
            does NOT empty, or a place on that list the schema does not have.
            Both ways, so the privacy pages cannot claim the period for a
            column nothing clears, nor leave out one that is cleared;
  PENDING   a column whose sentence says "No time limit is set yet" that is not
            on `network_address_period_pending.txt`, or a column on that list
            whose sentence does NOT say so, or a listed column with no
            sentence. Both ways, so the privacy pages can never promise a
            limit that has not been built and the list empties as each one is.

`--strict` also fails while the pending list holds anything: the release
should not ship with an address kept for ever (D-178).

WHAT "SHAPED LIKE AN ADDRESS" MEANS. The column's name split into words
(`createdIP` -> created, ip; `ipHash` -> ip, hash) contains `ip`, followed by
nothing, `address` or `hash`. So `ipCountry` (a country worked out from an
address, which is not kept) does not count, and `membershipID` does not
either. Both the CREATE TABLE bodies and any `ALTER TABLE ... ADD COLUMN` are
read, with comments removed first.

WHAT IT CANNOT SEE, said plainly. An address kept under a name that does not
say so: `tblLiveRateLimits.senderFingerprint` and the fingerprint inside
`tblApiRateLimits.bucket` are both listed in the catalogue BY HAND, and a new
one would pass unseen. An address inside free text (an error's detail, a
request's headers). And whether a sentence is TRUE - that is checked by a
person against the code that clears the column (#706's rule).

HOW IT READS THE CATALOGUE AND THE PLACES: it asks PHP to load the real files
and print the catalogue's `addressKept` keys and `NetworkAddressRetention::PLACES`
as JSON, because a bracket-counting parser over that file
has already been broken by a comment once (#706 round 1). No PHP, or a file
that does not load: REFUSED, never "nothing wrong".

Usage:
    python3 -I tools/audit-checks/check_network_address_periods.py
    python3 -I tools/audit-checks/check_network_address_periods.py --strict
    python3 -I tools/audit-checks/check_network_address_periods.py --self-test

Exit: 0 when every address column has an honest sentence; 1 otherwise, or when
something could not be read.

@package   Portal\\Tools
@author    MWBM Partners Ltd (t/a MWservices)
@copyright 2026-present MWBM Partners Ltd (t/a MWservices)
@license   All Rights Reserved
@version   1.1.0
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
SCHEMA = os.path.join(REPO, 'web', '_sql', 'full_schema.sql')
CATALOGUE = os.path.join(REPO, 'web', '_core', 'personal-data-catalogue.php')
PENDING = os.path.join(REPO, 'tools', 'audit-checks', 'network_address_period_pending.txt')
PLACES = os.path.join(REPO, 'web', '_core', 'NetworkAddressRetention.php')

NO_LIMIT = 'No time limit is set yet'
# The words a sentence uses to say it follows the network-address period; the
# privacy pages print that period, for the reader's organisation, above the list.
FOLLOWS_PERIOD = 'the period for network addresses'
MIN_WORDS_LENGTH = 60

# Loads the catalogue named by `$argv[1]` and prints table => {column: words}.
PHP_DUMP = (
    '$c = require $argv[1];'
    'if (!is_array($c)) { fwrite(STDERR, "the catalogue did not return a list"); exit(2); }'
    '$o = [];'
    'foreach ($c as $t => $e) {'
    '  if (is_array($e) && isset($e["addressKept"])) {'
    '    if (!is_array($e["addressKept"])) { fwrite(STDERR, "addressKept on $t is not a list"); exit(2); }'
    '    foreach ($e["addressKept"] as $col => $w) { $o[(string) $t][(string) $col] = (string) $w; }'
    '  }'
    '}'
    'echo json_encode((object) $o);'
)


# Loads the class file named by `$argv[1]` and prints its PLACES as [table, column] pairs.
PHP_PLACES = (
    'require $argv[1];'
    '$p = \\Portal\\Core\\NetworkAddressRetention::PLACES;'
    'if (!is_array($p) || $p === []) { fwrite(STDERR, "PLACES is empty"); exit(2); }'
    '$o = [];'
    'foreach ($p as $one) { $o[] = [(string) $one["table"], (string) $one["column"]]; }'
    'echo json_encode($o);'
)


class Unreadable(Exception):
    """Something this check needs could not be read: it refuses, it never passes."""


def words_of(name: str) -> list[str]:
    """`createdIP` -> ['created', 'ip']; `ipHash` -> ['ip', 'hash']."""
    return [w.lower() for w in re.findall(r'[A-Z]{2,}(?=[A-Z][a-z]|\b|$)|[A-Z]?[a-z]+|[A-Z]+|\d+', name)]


def is_address_shaped(name: str) -> bool:
    words = words_of(name)
    if 'ip' not in words:
        return False
    rest = words[words.index('ip') + 1:]
    return rest in ([], ['address'], ['hash'])


def schema_columns(path: str) -> dict[str, set[str]]:
    """table -> every column, from CREATE TABLE bodies and ALTER TABLE ... ADD COLUMN."""
    if not os.path.isfile(path):
        raise Unreadable('the install script ' + path + ' is not there')
    with open(path, encoding='utf-8', errors='replace') as handle:
        text = handle.read()
    text = re.sub(r'/\*.*?\*/', '', text, flags=re.S)
    text = re.sub(r'--[^\n]*', '', text)
    tables: dict[str, set[str]] = {}
    for m in re.finditer(r'CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*?)\n\)\s*ENGINE', text, re.S):
        cols = tables.setdefault(m.group(1), set())
        cols.update(re.findall(r'^\s+`(\w+)`\s+[A-Za-z]', m.group(2), re.M))
    for m in re.finditer(r'ALTER TABLE\s+`?(\w+)`?(.*?);', text, re.S):
        cols = tables.setdefault(m.group(1), set())
        cols.update(re.findall(r'ADD\s+(?:COLUMN\s+)?`(\w+)`\s+[A-Za-z]', m.group(2), re.I))
    if not tables:
        raise Unreadable('no CREATE TABLE was found in the install script')
    return tables


def catalogue_sentences(path: str) -> dict[str, dict[str, str]]:
    php = shutil.which('php')
    if php is None:
        raise Unreadable('there is no `php` on this machine, so the catalogue cannot be loaded')
    done = subprocess.run([php, '-r', PHP_DUMP, path], capture_output=True, text=True, timeout=120, check=False)
    if done.returncode != 0:
        raise Unreadable('PHP could not load the catalogue: ' + (done.stderr or done.stdout).strip()[:400])
    try:
        found = json.loads(done.stdout)
    except json.JSONDecodeError as problem:
        raise Unreadable('the catalogue did not come back as JSON: ' + str(problem)) from problem
    if not found:
        raise Unreadable('the catalogue has no `addressKept` sentence at all, so this check would prove nothing')
    return found


def cleared_places(path: str) -> set[tuple[str, str]]:
    """(table, column) for every place the network-address clear-out empties."""
    php = shutil.which('php')
    if php is None:
        raise Unreadable('there is no `php` on this machine, so the clear-out\'s list cannot be loaded')
    done = subprocess.run([php, '-r', PHP_PLACES, path], capture_output=True, text=True, timeout=120, check=False)
    if done.returncode != 0:
        raise Unreadable('PHP could not load NetworkAddressRetention::PLACES: ' + (done.stderr or done.stdout).strip()[:400])
    try:
        return {(t, c) for t, c in json.loads(done.stdout)}
    except (json.JSONDecodeError, ValueError, TypeError) as problem:
        raise Unreadable('NetworkAddressRetention::PLACES did not come back as pairs: ' + str(problem)) from problem


def pending_columns(path: str) -> set[tuple[str, str]]:
    if not os.path.isfile(path):
        raise Unreadable('the pending list ' + path + ' is not there')
    out: set[tuple[str, str]] = set()
    with open(path, encoding='utf-8') as handle:
        for number, raw in enumerate(handle, start=1):
            line = raw.split('#', 1)[0].strip()
            if line == '':
                continue
            m = re.fullmatch(r'(\w+)\.(\w+)', line)
            if m is None:
                raise Unreadable(f'line {number} of the pending list is not `table.column`: {raw.strip()}')
            out.add((m.group(1), m.group(2)))
    return out


def problems(schema: str, catalogue: str, pending: str, places: str = PLACES) -> list[str]:
    """What is wrong, in words (empty when all is well). Raises Unreadable."""
    tables = schema_columns(schema)
    sentences = catalogue_sentences(catalogue)
    waiting = pending_columns(pending)
    cleared = cleared_places(places)
    out: list[str] = []

    shaped = {(t, c) for t, cols in tables.items() for c in cols if is_address_shaped(c)}
    named = {(t, c) for t, cols in sentences.items() for c in cols}
    for table, column in sorted(shaped - named):
        out.append(f'MISSING: {table}.{column} keeps a network address, and the personal-data catalogue has no '
                   f'`addressKept` sentence for it, so the privacy pages do not say how long it is kept (D-178). '
                   f'Decide its keeping period, build it, and write the sentence.')
    for table, column in sorted(named):
        if column not in tables.get(table, set()):
            out.append(f'STALE: the catalogue\'s `addressKept` names {table}.{column}, which the install script '
                       f'does not have. The privacy pages would describe a place that does not exist.')
    for table, column in sorted(named):
        text = sentences[table][column].strip()
        if len(text) < MIN_WORDS_LENGTH or 'network address' not in text:
            out.append(f'SHORT: the sentence for {table}.{column} is too short, or does not say "network address", '
                       f'so a member reading the privacy page cannot tell what it is about.')
    for table, column in sorted(named):
        says_none = NO_LIMIT in sentences[table][column]
        listed = (table, column) in waiting
        if says_none and not listed:
            out.append(f'PENDING: the sentence for {table}.{column} says "{NO_LIMIT}", but the column is not on '
                       f'network_address_period_pending.txt. Put it there, or build its period and reword.')
        if listed and not says_none:
            out.append(f'PENDING: {table}.{column} is on network_address_period_pending.txt, but its sentence does '
                       f'not say "{NO_LIMIT}". If a period is built now, take it off the list; if not, the privacy '
                       f'pages must not imply one.')
    for table, column in sorted(cleared):
        if column not in tables.get(table, set()):
            out.append(f'PERIOD: NetworkAddressRetention::PLACES names {table}.{column}, which the install script does '
                       f'not have, so the nightly clear-out would fail on it.')
        elif FOLLOWS_PERIOD not in sentences.get(table, {}).get(column, ''):
            out.append(f'PERIOD: the nightly clear-out empties {table}.{column} after the network-address period, but '
                       f'its sentence does not say it follows "{FOLLOWS_PERIOD}", so the privacy pages do not tell a '
                       f'member how long it is kept.')
    for table, column in sorted(named):
        if FOLLOWS_PERIOD in sentences[table][column] and (table, column) not in cleared:
            out.append(f'PERIOD: the sentence for {table}.{column} says it follows "{FOLLOWS_PERIOD}", but '
                       f'NetworkAddressRetention::PLACES does not list it, so nothing empties it on that period.')
    for table, column in sorted(waiting - named):
        out.append(f'PENDING: {table}.{column} is on network_address_period_pending.txt and has no `addressKept` '
                   f'sentence at all.')
    return out


def run(strict: bool) -> int:
    try:
        found = problems(SCHEMA, CATALOGUE, PENDING)
        waiting = pending_columns(PENDING)
    except Unreadable as why:
        print('check_network_address_periods: REFUSED - ' + str(why))
        return 1
    if found:
        for line in found:
            print('check_network_address_periods: ' + line)
        return 1
    print('check_network_address_periods: OK - every column shaped like a network address has a sentence saying '
          'how long it is kept, and the sentences agree with the pending list.')
    if waiting:
        print(f'check_network_address_periods: {len(waiting)} column(s) still have no keeping period (D-178, #1019):')
        for table, column in sorted(waiting):
            print(f'  - {table}.{column}')
        if strict:
            print('check_network_address_periods: --strict fails while the pending list holds anything.')
            return 1
    return 0


def self_test() -> int:
    """Planted faults on copies of the three files: each must be caught by its own complaint and by no other."""
    with open(SCHEMA, encoding='utf-8') as handle:
        schema = handle.read()
    with open(CATALOGUE, encoding='utf-8') as handle:
        catalogue = handle.read()
    with open(PENDING, encoding='utf-8') as handle:
        pending = handle.read()
    with open(PLACES, encoding='utf-8') as handle:
        places = handle.read()
    with open(os.path.join(os.path.dirname(PLACES), 'RetentionPeriod.php'), encoding='utf-8') as handle:
        base = handle.read()

    def outcome(s: str, c: str, p: str, pl: str = places) -> set[str]:
        with tempfile.TemporaryDirectory(prefix='check-network-address-') as folder:
            paths = [os.path.join(folder, n) for n in ('schema.sql', 'catalogue.php', 'pending.txt', 'NetworkAddressRetention.php')]
            for path, text in zip(paths, (s, c, p, pl)):
                with open(path, 'w', encoding='utf-8') as handle:
                    handle.write(text)
            # The class loads its base class from beside itself.
            with open(os.path.join(folder, 'RetentionPeriod.php'), 'w', encoding='utf-8') as handle:
                handle.write(base)
            try:
                return {line.split(':', 1)[0] for line in problems(*paths)}
            except Unreadable:
                return {'REFUSED'}

    new_table = ('\nCREATE TABLE IF NOT EXISTS `tblP1019Planted` (\n    `plantID` INT NOT NULL AUTO_INCREMENT,\n'
                 '    `ipAddress` VARCHAR(45) DEFAULT NULL,\n    PRIMARY KEY (`plantID`)\n) ENGINE=InnoDB;\n')
    added_column = '\nALTER TABLE `tblUsers` ADD COLUMN `lastSeenIP` VARCHAR(45) DEFAULT NULL;\n'
    country_column = '\nALTER TABLE `tblUsers` ADD COLUMN `ipCountry` CHAR(2) DEFAULT NULL;\n'
    commented_column = '\n-- ALTER TABLE `tblUsers` ADD COLUMN `oldIP` VARCHAR(45) DEFAULT NULL;\n'
    consent_words = "choice was made from. No time limit is set yet: a choice made while signed in is kept until "
    chat_place = "['table' => 'tblLiveChatMessages', 'column' => 'senderIP',"
    cases = [
        ('the real files', schema, catalogue, pending, places, set()),
        ('a new table with an `ipAddress` column', schema + new_table, catalogue, pending, places, {'MISSING'}),
        ('a column added by ALTER TABLE (`lastSeenIP`)', schema + added_column, catalogue, pending, places, {'MISSING'}),
        ('a scrambled address column (`ipHash`) taken out of the catalogue (and so off the period too)',
         schema, catalogue.replace("'addressKept' => [\n            'ipHash' => 'Plays of a recording", "'addressKeptX' => [\n            'ipHash' => 'Plays of a recording", 1),
         pending, places, {'MISSING', 'PERIOD'}),
        ('a sentence for a column the schema does not have',
         schema, catalogue.replace("'visitorIP' => 'Error records keep", "'visitorIPX' => 'Error records keep", 1), pending, places,
         {'MISSING', 'STALE'}),
        ('a sentence cut short (it still says there is no limit, so only SHORT)', schema,
         re.sub(r"'ipAddress' => 'The record of your choices.*?',\n", "'ipAddress' => 'Kept. " + NO_LIMIT + ".',\n", catalogue, count=1, flags=re.S),
         pending, places, {'SHORT'}),
        ('a column taken off the pending list while its sentence says there is no limit',
         schema, catalogue, pending.replace('tblConsentLog.ipAddress', '', 1), places, {'PENDING'}),
        ('a pending column whose sentence stops saying there is no limit',
         schema, catalogue.replace(consent_words, "choice was made from. A time limit applies: a choice made while signed in is kept until ", 1),
         pending, places, {'PENDING'}),
        ('a pending line that is not table.column', schema, catalogue, pending + '\nnot a column\n', places, {'REFUSED'}),
        # #1019 round 2: the sentences and the clear-out's list, both ways.
        ('a place the clear-out empties whose sentence stops naming the period',
         schema, catalogue.replace("The address follows the period for network addresses; the message itself is kept.",
                                   "The address is kept with the message.", 1), pending, places, {'PERIOD'}),
        ('a place taken off the clear-out\'s list while its sentence still names the period',
         schema, catalogue, pending, re.sub(re.escape(chat_place) + r"[^\n]*\n", '', places, count=1), {'PERIOD'}),
        ('a place on the clear-out\'s list the schema does not have',
         schema, catalogue, pending, places.replace(chat_place, "['table' => 'tblLiveChatMessages', 'column' => 'senderIPX',", 1),
         {'PERIOD'}),
        # Must stay green.
        ('a country worked out from an address (`ipCountry`, must stay green)', schema + country_column, catalogue, pending, places, set()),
        ('an address column only inside an SQL comment (must stay green)', schema + commented_column, catalogue, pending, places, set()),
    ]
    failed = 0
    for label, s, c, p, pl, expected in cases:
        if (s, c, p, pl) == (schema, catalogue, pending, places) and expected:
            print(f'FAIL - {label}: the planted change did not apply, so it tests nothing')
            failed += 1
            continue
        got = outcome(s, c, p, pl)
        verdict = 'PASS' if got == expected else 'FAIL'
        failed += verdict == 'FAIL'
        print(f'{verdict} - {label}: expected {sorted(expected) or "nothing wrong"}, got {sorted(got) or "nothing wrong"}')
    total = len(cases)
    green = sum(1 for case in cases if not case[5])
    print(f'{total - failed} of {total} self-test cases passed ({total - green} caught or refused, {green} must stay green).')
    return 1 if failed else 0


if __name__ == '__main__':
    if '--self-test' in sys.argv[1:]:
        sys.exit(self_test())
    sys.exit(run('--strict' in sys.argv[1:]))
