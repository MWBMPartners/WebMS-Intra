#!/usr/bin/env python3
"""
The test OF `tools/audit-checks/check_wall_clock_writes_not_moments.py` (#785).

Path: tools/wall-clock-writes-selftest.py

WHY IT EXISTS
-------------
A guard is only worth what its plants prove. This repository has a long record
of guards "verified by planting" the shapes their author happened to think of,
and of checks that passed on broken code because nothing ever made them fail
(#763, #775, #777). So every shape the check claims to see is PLANTED here, in
a fixture tree of its own, and each plant must be reported by EXACTLY the
complaint expected -- the right file, the right variable, the right column --
and by no other. The must-stay-green cases are run the same way, because a
check that cries wolf gets switched off (#483).

It needs PHP (the check's probe uses PHP's tokeniser) and nothing else: no
database and no web server. It lives in `tools/` rather than in
`tools/audit-checks/`, as `api-spec-check-selftest.py` does, so the pre-push
hook's glob does not run it as an audit check; it is a hard gate of its own in
`pr-security.yml`.

The fixture trees carry a minimal schema of their own -- two tables, a
`DATETIME` that declares itself WALL-CLOCK beside one that does not, and a
`TIME` beside a `DATE` -- so the cases test the CHECK, not today's portal.

WHAT IT CANNOT PROVE: that the real portal's handlers are correct. That is
what the check is for, and where the stored hour matters it is DRIVEN (section
I and K of tools/clock-change-selftest.php).

Package: WebMS-Intra / audit checks
Author: Claude Opus 5.5 (for MWBM Partners Ltd, t/a MWservices)
Copyright: All Rights Reserved -- MWBM Partners Ltd
Version: 1.0.0
"""

from __future__ import annotations

import contextlib
import io
import os
import re
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "audit-checks"))

import check_clock_time_not_a_moment as reader_check  # noqa: E402
import check_wall_clock_writes_not_moments as check  # noqa: E402

SCHEMA = """
CREATE TABLE `tblEvents` (
  `eventID` INT NOT NULL AUTO_INCREMENT,
  `eventName` VARCHAR(200) NOT NULL,
  `startDateTime` DATETIME NOT NULL COMMENT 'WALL-CLOCK reading on the event clock',
  `endDateTime` DATETIME NULL COMMENT 'WALL-CLOCK reading on the event clock',
  `createdAt` DATETIME NOT NULL COMMENT 'the moment the row was made',
  PRIMARY KEY (`eventID`)
) ENGINE=InnoDB;

CREATE TABLE `tblSessions` (
  `sessionID` INT NOT NULL,
  `sessionDate` DATE NOT NULL,
  `sessionTime` TIME NULL,
  PRIMARY KEY (`sessionID`)
) ENGINE=InnoDB;
"""

PIN = "# fixture pin\nendDateTime\nstartDateTime\n"

# A correct writer, present in every tree, so "judged nothing" never decides
# a case -- each case is about its own file.
BASELINE = """<?php
declare(strict_types=1);
$start = I18n::wallClockFromInput((string) ($_POST['start'] ?? ''));
$stmt = $mysqli->prepare('INSERT INTO tblEvents (startDateTime, createdAt) VALUES (?, NOW())');
$stmt->bind_param('s', $start);
$stmt->execute();
"""

HEAD = "<?php\ndeclare(strict_types=1);\n"

# ── PLANTS: each must be reported, exactly as listed, and nothing else ──────
# (name, php, expected {(variable, column)})
PLANTS = [
    ("P01 resources/book.php before 04a6226e: strtotime, then date() lines later",
     HEAD + """
$startAt = $_POST['date'] . ' ' . $_POST['start'] . ':00';
$startTs = strtotime($startAt);
$approvedAt = date('Y-m-d H:i:s');
$stmt = $db->prepare('INSERT INTO tblEvents (createdAt, startDateTime) VALUES (?, ?)');
if ($stmt !== false) {
    $startDt = date('Y-m-d H:i:s', $startTs);
    $stmt->bind_param('ss', $approvedAt, $startDt);
}
""", {("$startDt", "tblEvents.startDateTime")}),

    ("P02 calendar/submit-save.php before 05a8097e: a cast and a ternary, two columns",
     HEAD + """
$startRaw = trim((string) ($_POST['s'] ?? ''));
$endRaw = trim((string) ($_POST['e'] ?? ''));
$startDt = strtotime($startRaw);
$endDt = $endRaw !== '' ? strtotime($endRaw) : 0;
$startUtc = date('Y-m-d H:i:s', (int) $startDt);
$endUtc = $endDt > 0 ? date('Y-m-d H:i:s', (int) $endDt) : null;
$stmt = $mysqli->prepare(
    'INSERT INTO tblEvents '
    . '(startDateTime, endDateTime, createdAt) '
    . 'VALUES (?, ?, NOW())'
);
$stmt->bind_param('ss', $startUtc, $endUtc);
""", {("$startUtc", "tblEvents.startDateTime"), ("$endUtc", "tblEvents.endDateTime")}),

    ("P03 events API before round 6: a constructor WITH the correct zone",
     HEAD + """
function saveStart(\\mysqli $db, string $raw, \\DateTimeZone $zone): void
{
    $startSql = (new \\DateTimeImmutable($raw, $zone))->setTimezone($zone)->format('Y-m-d H:i:s');
    $id = 1;
    $stmt = $db->prepare('UPDATE tblEvents SET startDateTime = ? WHERE eventID = ?');
    $stmt->bind_param('si', $startSql, $id);
}
""", {("$startSql", "tblEvents.startDateTime")}),

    ("P04 createFromFormat with a zone, into a TIME column through an alias",
     HEAD + """
$t = \\DateTimeImmutable::createFromFormat('Y-m-d H:i', $_POST['d'] . ' ' . $_POST['h'], $tz);
$time = $t->format('H:i:s');
$id = 3;
$stmt = $db->prepare('UPDATE tblSessions s SET s.sessionTime = ? WHERE s.sessionID = ?');
$stmt->bind_param('si', $time, $id);
""", {("$time", "tblSessions.sessionTime")}),

    ("P05 date_create_immutable (round 10's one-word bypass), stored with execute([...])",
     HEAD + """
$m = date_create_immutable((string) $_POST['when']);
$s = date_format($m, 'Y-m-d H:i:s');
$stmt = $db->prepare('INSERT INTO tblEvents (startDateTime, eventID) VALUES (?, ?)');
$stmt->execute([$s, 5]);
""", {("$s", "tblEvents.startDateTime")}),

    ("P06 mktime() from posted parts, beside a DATE column in the same UPDATE",
     HEAD + """
$ts = mktime((int) $_POST['h'], (int) $_POST['m'], 0);
$hm = date('H:i:s', $ts);
$day = '2026-01-01';
$id = 4;
$stmt = $db->prepare('UPDATE tblSessions SET sessionTime = ?, sessionDate = ? WHERE sessionID = ?');
$stmt->bind_param('ssi', $hm, $day, $id);
""", {("$hm", "tblSessions.sessionTime")}),

    ("P07 setTime() on a moment made from a literal",
     HEAD + """
$d = (new \\DateTimeImmutable('today', $tz))->setTime((int) $_POST['h'], (int) $_POST['m']);
$v = $d->format('Y-m-d H:i:s');
$stmt = $db->prepare('INSERT INTO tblEvents (startDateTime) VALUES (?)');
$stmt->bind_param('s', $v);
""", {("$v", "tblEvents.startDateTime")}),

    ("P08 a column list built at run time, the value pushed beside its SET literal",
     HEAD + """
$set = [];
$types = '';
$params = [];
if (isset($body['start']) === true) {
    $set[] = 'startDateTime = ?';
    $types .= 's';
    $params[] = date('Y-m-d H:i:s', strtotime((string) $body['start']));
}
$sql = 'UPDATE tblEvents SET ' . implode(', ', $set) . ' WHERE eventID = ?';
$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
""", {("$params", "tblEvents.startDateTime")}),

    ("P09 the statement and values inside a closure's use list (saveUnderFreeSlug)",
     HEAD + """
$start = date('Y-m-d H:i:s', strtotime((string) $_POST['s']));
$values = [(string) $_POST['name'], $start];
$stmt = $mysqli->prepare('INSERT INTO tblEvents (eventName, startDateTime) VALUES (?, ?)');
Events::saveUnderFreeSlug($mysqli, 1, 'x', static function (string $c) use ($stmt, $values): void {
    $v = $values;
    $stmt->bind_param('ss', ...$v);
});
""", {("$values", "tblEvents.startDateTime")}),

    ("P10 through a named function's return",
     HEAD + """
function toStore(string $x): string
{
    return date('Y-m-d H:i:s', strtotime($x));
}
$s = toStore((string) $_POST['s']);
$stmt = $mysqli->prepare('INSERT INTO tblEvents (startDateTime) VALUES (?)');
$stmt->bind_param('s', $s);
""", {("$s", "tblEvents.startDateTime")}),

    ("P11 a date-only slice from a moment, joined to the typed time (round 10's Nuuk class)",
     HEAD + """
$posted = (string) $_POST['when'];
$day = date('Y-m-d', strtotime($posted));
$reading = $day . ' ' . substr($posted, 11, 5) . ':00';
$id = 2;
$stmt = $db->prepare('UPDATE tblEvents SET endDateTime = ? WHERE eventID = ?');
$stmt->bind_param('si', $reading, $id);
""", {("$reading", "tblEvents.endDateTime")}),

    ("P12 a value written INTO the SQL text of query()",
     HEAD + """
$t = date('H:i:s', strtotime((string) $_POST['t']));
$mysqli->query("UPDATE tblSessions SET sessionTime = '$t' WHERE sessionID = 1");
""", {("$t", "tblSessions.sessionTime")}),

    ("P13 through a closure held in a variable and its return",
     HEAD + """
$fmt = static function (string $x): ?string {
    $m = strtotime($x);
    return $m === false ? null : date('Y-m-d H:i:s', $m);
};
$endSql = $fmt((string) $_POST['e']);
$stmt = $mysqli->prepare('INSERT INTO `tblEvents` (`endDateTime`) VALUES (?)');
$stmt->bind_param('s', $endSql);
""", {("$endSql", "tblEvents.endDateTime")}),

    # ⚠️ ADDED BECAUSE MUTATION FOUND IT UNTESTED: P09 reaches a closure's
    #    values through the ARRAY path, so following a SCALAR through a `use`
    #    list was switched off with every case green.
    ("P14 a single value carried into a closure through its use list",
     HEAD + """
$start = date('Y-m-d H:i:s', strtotime((string) $_POST['s']));
$stmt = $mysqli->prepare('INSERT INTO tblEvents (startDateTime) VALUES (?)');
Events::saveUnderFreeSlug($mysqli, 1, 'x', static function (string $c) use ($stmt, $start): void {
    $stmt->bind_param('s', $start);
});
""", {("$start", "tblEvents.startDateTime")}),
]

# ── MUST STAY GREEN: each alone, nothing reported ──────────────────────────
GREEN = [
    ("G01 a real MOMENT column (createdAt) built from a posted value, beside a correct clock column",
     HEAD + """
$made = date('Y-m-d H:i:s', strtotime((string) $_POST['made']));
$start = I18n::wallClockFromInput((string) $_POST['start']);
$stmt = $mysqli->prepare('INSERT INTO tblEvents (startDateTime, createdAt) VALUES (?, ?)');
$stmt->bind_param('ss', $start, $made);
"""),
    ("G02 a relative strtotime('+60 days'), a moment built from nothing anybody wrote",
     HEAD + """
$until = date('Y-m-d H:i:s', strtotime('+60 days'));
$stmt = $mysqli->prepare('INSERT INTO tblEvents (startDateTime) VALUES (?)');
$stmt->bind_param('s', $until);
"""),
    ("G03 an offset-bearing value converted on purpose, through I18n (the events API's shape)",
     HEAD + """
$readingFor = static function (string $raw, \\DateTimeZone $zone): ?string {
    return I18n::inputCarriesOffset($raw) === false
        ? I18n::wallClockFromInput($raw)
        : I18n::instantFromInput($raw, $zone);
};
$startSql = $readingFor((string) $_POST['s'], $zone);
$id = 1;
$stmt = $db->prepare('UPDATE tblEvents SET startDateTime = ? WHERE eventID = ?');
$stmt->bind_param('si', $startSql, $id);
"""),
    ("G04 a reading stored as text",
     HEAD + """
$t = substr((string) $_POST['t'], 0, 5) . ':00';
$stmt = $db->prepare('UPDATE tblSessions SET sessionTime = ? WHERE sessionID = 1');
$stmt->bind_param('s', $t);
"""),
    ("G05 the old code QUOTED in comments and a string, the shape every fixed file has",
     HEAD + """
// $startDt = date('Y-m-d H:i:s', strtotime($raw));
/* $x = strtotime($raw); $stmt->bind_param('s', $x); */
$note = 'this used to call strtotime($raw) and stored the moved hour';
$start = I18n::wallClockFromInput((string) $_POST['s']);
$stmt = $mysqli->prepare('INSERT INTO tblEvents (startDateTime) VALUES (?)');
$stmt->bind_param('s', $start);
"""),
    ("G06 a moment bound to a SELECT, which stores nothing",
     HEAD + """
$w = date('Y-m-d H:i:s', strtotime((string) $_POST['s']) - 600);
$stmt = $db->prepare('SELECT 1 FROM tblEvents WHERE startDateTime < ?');
$stmt->bind_param('s', $w);
"""),
    ("G07 a date-only value in the DATE column of a statement that also writes a TIME column",
     HEAD + """
$dObj = \\DateTimeImmutable::createFromFormat('Y-m-d', (string) $_POST['d']);
$sessionDate = $dObj->format('Y-m-d');
$time = substr((string) $_POST['t'], 0, 5) . ':00';
$stmt = $db->prepare('INSERT INTO tblSessions (sessionDate, sessionTime) VALUES (?, ?)');
$stmt->bind_param('ss', $sessionDate, $time);
"""),
    ("G08 the same in a column list built at run time, paired by its SET literal",
     HEAD + """
$set = [];
$params = [];
$dObj = \\DateTimeImmutable::createFromFormat('Y-m-d', (string) $_POST['d']);
if ($dObj !== false) {
    $set[] = 'sessionDate = ?';
    $params[] = $dObj->format('Y-m-d');
}
if (isset($_POST['t']) === true) {
    $set[] = 'sessionTime = ?';
    $params[] = substr((string) $_POST['t'], 0, 5) . ':00';
}
$sql = 'UPDATE tblSessions SET ' . implode(', ', $set) . ' WHERE sessionID = ?';
$stmt = $db->prepare($sql);
$stmt->bind_param(str_repeat('s', count($params)), ...$params);
"""),
    ("G09 date_parse(), which reads the parts as written and resolves nothing",
     HEAD + """
$p = date_parse((string) $_POST['t']);
$t = sprintf('%02d:%02d:00', $p['hour'], $p['minute']);
$stmt = $db->prepare('UPDATE tblSessions SET sessionTime = ? WHERE sessionID = 1');
$stmt->bind_param('s', $t);
"""),
    ("G10 a moment passed to a call that returns something else entirely",
     HEAD + """
$stored = date('Y-m-d H:i:s', strtotime((string) $_POST['s']));
$there = Events::alreadyInCalendar($mysqli, 1, 'x', $stored);
$name = (string) $there['eventName'];
$start = I18n::wallClockFromInput((string) $_POST['s']);
$stmt = $mysqli->prepare('INSERT INTO tblEvents (eventName, startDateTime) VALUES (?, ?)');
$stmt->bind_param('ss', $name, $start);
"""),
    # ⚠️ ADDED BECAUSE MUTATION FOUND IT UNTESTED: G10's call is a STATIC one,
    #    walled off by its own branch, so letting a value flow through every
    #    PLAIN function's arguments left every case green. The wall is what
    #    stopped twenty-six false reports in `import.php` (see the probe).
    #    And the looked-up value must land in a CLOCK column, or the column
    #    matching drops it whatever the wall does: copying a stored event's
    #    reading, as a series copy does, is that shape.
    ("G11 the same through a plain function and a method, the result stored in a clock column",
     HEAD + """
$stored = date('Y-m-d H:i:s', strtotime((string) $_POST['s']));
$row = lookUpRow($mysqli, $stored);
$other = $finder->find($stored);
$copyStart = (string) $row['startDateTime'];
$copyEnd = (string) $other['endDateTime'];
$stmt = $mysqli->prepare('INSERT INTO tblEvents (startDateTime, endDateTime) VALUES (?, ?)');
$stmt->bind_param('ss', $copyStart, $copyEnd);
"""),
]

FINDING = re.compile(r"^FAIL: (\S+?):\d+ stores `([^`]+)` into (\S+) after")


def write_tree(root: str, files: dict[str, str]) -> None:
    for relative, text in {
        os.path.join("web", "_sql", "full_schema.sql"): SCHEMA,
        os.path.join("tools", "audit-checks", "clock_wall_clock_columns.pinned.txt"): PIN,
        os.path.join("web", "_apps", "base.php"): BASELINE,
        **files,
    }.items():
        path = os.path.join(root, relative)
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "w", encoding="utf-8") as handle:
            handle.write(text)


def run(files: dict[str, str], allowed: dict | None = None) -> tuple[int, set, list[str]]:
    """Run the check on a fresh fixture tree: exit code, findings, other problems."""
    with tempfile.TemporaryDirectory() as root:
        write_tree(root, files)
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            code = check.main(["--root", root], allowed=allowed or {})
    findings = set()
    other = []
    for line in out.getvalue().splitlines():
        if line.startswith("FAIL: ") is False:
            continue
        hit = FINDING.match(line)
        if hit is not None:
            findings.add((hit.group(1), hit.group(2), hit.group(3)))
        else:
            other.append(line)
    return code, findings, other


results: list[tuple[str, bool, str]] = []


def record(name: str, ok: bool, detail: str = "") -> None:
    results.append((name, ok, detail))
    print(f"  {'✓' if ok else '✗'} {name}" + ("" if ok else f"\n      {detail}"))


print("Plants -- each reported exactly as expected, and by nothing else:")
for name, php, want in PLANTS:
    rel = os.path.join("web", "_apps", "plant.php")
    code, found, other = run({rel: php})
    expected = {(rel, var, col) for var, col in want}
    record(name, code == 1 and found == expected and other == [],
           f"exit {code}; found {sorted(found)}; expected {sorted(expected)}; other {other}")

print("\nMust stay green:")
for name, php in GREEN:
    code, found, other = run({os.path.join("web", "_apps", "green.php"): php})
    record(name, code == 0 and found == set() and other == [],
           f"exit {code}; found {sorted(found)}; other {other}")

print("\nThe check refuses rather than passes when it cannot look, and its exemptions fail both ways:")

# E01 a file that does not parse is a refusal, never "nothing found".
# ⚠️ ITS BRACKETS BALANCE ON PURPOSE. The first version was `strtotime(;`,
#    whose unclosed bracket the probe's own matcher refuses anyway -- so
#    removing TOKEN_PARSE left this green. A missing semicolon is invisible to
#    a bracket count, and only PHP's own parse can refuse it.
code, found, other = run({os.path.join("web", "_apps", "broken.php"):
                          HEAD + "$x = strtotime($y) $z = 1;\n"})
record("E01 a file PHP cannot parse is reported as unreadable, not as clean",
       code == 1 and any("could not be read by the probe" in o for o in other), f"exit {code}; {other}")

# E02 nothing writing a clock column anywhere: the check is broken, not the portal
with tempfile.TemporaryDirectory() as root:
    write_tree(root, {})
    os.remove(os.path.join(root, "web", "_apps", "base.php"))
    os.makedirs(os.path.join(root, "web", "_apps"), exist_ok=True)
    with open(os.path.join(root, "web", "_apps", "read.php"), "w", encoding="utf-8") as handle:
        handle.write(HEAD + "$stmt = $db->prepare('SELECT 1 FROM tblEvents');\n$stmt->execute();\n")
    out = io.StringIO()
    with contextlib.redirect_stdout(out):
        code = check.main(["--root", root], allowed={})
record("E02 a run that judged no clock-column write at all FAILS",
       code == 1 and "no statement writing a clock column" in out.getvalue(), out.getvalue()[-300:])

# E03 MOMENT_BUILDERS gains a name this check has not classified
original = reader_check.MOMENT_BUILDERS
try:
    reader_check.MOMENT_BUILDERS = re.compile(original.pattern + "|date_create_wibble")
    code, found, other = run({})
finally:
    reader_check.MOMENT_BUILDERS = original
record("E03 a new MOMENT_BUILDERS entry that is not classified here FAILS",
       code == 1 and any("has not classified" in o for o in other), f"exit {code}; {other}")

# E04-E07: an exemption, its pinned guard, and both ways it can go stale
GUARD = "if ($ok === true && $sure === true) { $why = 'refused'; "
guarded = HEAD + """
function store(\\mysqli $db, string $raw): void
{
    $ok = true;
    $sure = true;
    if ($ok === true && $sure === true) {
        $why = 'refused';
        return;
    }
    $startSql = date('Y-m-d H:i:s', strtotime($raw));
    $stmt = $db->prepare('INSERT INTO tblEvents (startDateTime) VALUES (?)');
    $stmt->bind_param('s', $startSql);
}
"""
rel = os.path.join("web", "_apps", "exempt.php")
key = f"{rel}::$startSql::tblEvents.startDateTime::store"
code, found, other = run({rel: guarded}, {key: ("fixture", GUARD)})
record("E04 an exemption whose guard is present, once, passes", code == 0 and other == [],
       f"exit {code}; {found}; {other}")
code, found, other = run({rel: guarded.replace("$ok === true && $sure === true", "$ok === true && $sure === true && false")},
                         {key: ("fixture", GUARD)})
record("E05 `&& false` appended to the guard makes the exemption FAIL",
       code == 1 and any("that guard is in" in o for o in other), f"exit {code}; {other}")
code, found, other = run({rel: guarded.replace("    $ok = true;\n", "    $ok = true;\n    if ($ok === true && $sure === true) { $why = 'refused'; }\n")},
                         {key: ("fixture", GUARD)})
record("E06 a second, dead copy of the guard makes the exemption FAIL",
       code == 1 and any("2 time(s)" in o for o in other), f"exit {code}; {other}")
code, found, other = run({rel: guarded.replace("date('Y-m-d H:i:s', strtotime($raw))", "I18n::wallClockFromInput($raw)")},
                         {key: ("fixture", GUARD)})
record("E07 an exemption the probe no longer reports FAILS (stale)",
       code == 1 and any("no longer reports it" in o for o in other), f"exit {code}; {other}")

#  E08 the exemption names a PLACE, not just a variable: the same variable
#  and column, with a moment built in a DIFFERENT function of the same file,
#  is still reported. Measured before this existed: the CSV import before
#  round 6 used the very names today's exemption forgives.
elsewhere = guarded + """
$startSql = date('Y-m-d H:i:s', strtotime((string) $_POST['again']));
$stmt = $db->prepare('INSERT INTO tblEvents (startDateTime) VALUES (?)');
$stmt->bind_param('s', $startSql);
"""
code, found, other = run({rel: elsewhere}, {key: ("fixture", GUARD)})
record("E08 the same variable and column, built somewhere else in the file, is NOT forgiven",
       code == 1 and found == {(rel, "$startSql", "tblEvents.startDateTime")} and other == [],
       f"exit {code}; {found}; {other}")

#  E09 an exemption may carry SEVERAL pins (the import's step (d) has two);
#  every one must be there, so a present first pin cannot carry a missing
#  second one.
code, found, other = run({rel: guarded}, {key: ("fixture", (GUARD, "if ($neverWritten === true) {"))})
#  (E04 proves GUARD alone passes in this very file, so exactly one guard
#  complaint here can only be the second pin's.)
record("E09 with two pins, the second one missing makes the exemption FAIL",
       code == 1 and len([o for o in other if "that guard is in" in o]) == 1,
       f"exit {code}; {other}")

failed = [r for r in results if r[1] is False]
plants = len(PLANTS)
greens = len(GREEN)
print(f"\n{len(results)} checks: {len(results) - len(failed)} passed, {len(failed)} failed "
      f"({plants} plants, {greens} must-stay-green, {len(results) - plants - greens} refusals).")
# ⚠️ THE COUNTS ARE PINNED, so a case deleted from a list cannot leave this
#    printing "all passed" over less than it used to test (#697, #485).
if plants != 14 or greens != 11:
    print(f"FAIL: the plant or green list has changed size ({plants} / {greens}, "
          f"pinned 14 / 11). Change the pin in the same edit, deliberately.")
    sys.exit(1)
sys.exit(1 if len(failed) > 0 else 0)
