<?php
// Path: tools/settings-row-rule-sql-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The DATABASE half of the Settings row rule, run on a real database 🧱 (#1048)
 * -----------------------------------------------------------------------------
 * `Portal\Core\SettingsRowRule` decides which rows of the general settings
 * editor (`/settings`) an administrator of ONE organisation may not change. It
 * has two halves: PHP (`whyNotChangeable()`), which decides what the person is
 * TOLD, and SQL (`nameRuleSql()`), which the save's UPDATE and the delete's
 * DELETE carry inside the statement, so a row renamed between the page being
 * read and the button being pressed is still not written. The PHP half decides
 * what to say; the SQL half decides what HAPPENS.
 *
 * WHAT WAS WRONG (#1048). Nothing ran the SQL half. The check of #1021
 * (02ad04fa) replaced the whole rule with `1 = 1` and every test stayed green,
 * and that was true before #1021 as well. It could not be otherwise:
 * `settings-locks-selftest.php` presses the buttons, the PHP half refuses
 * first, and the statement is only ever reached with a row the PHP half has
 * already allowed. A rule that only fires in a race was guarded by nothing.
 *
 * WHAT THIS DOES. It makes a TEMPORARY copy of `tblSettings` (`CREATE
 * TEMPORARY TABLE … LIKE`), so the name column has exactly the real column's
 * type and collation, and puts one row in it for each name below. It drops the
 * copy's unique keys, because several of the names are EQUAL to each other
 * under that collation (that is the whole point of rule 3) and could not both
 * be stored otherwise. Then, for every name, it runs the rule as the save
 * handler runs it — the real `nameRuleSql()` text, the same `siteID`
 * condition, the same placeholders — once as a SELECT and once as the UPDATE
 * itself, and reads what the database answers.
 *
 *   A  every name must be allowed or refused exactly as written here by hand,
 *      AND exactly as `SettingsRowRule::whyNotChangeable()` answers for it — so
 *      the two halves cannot drift apart without this going red;
 *   B  the UPDATE, as `settings/save.php` sends it, changes the allowed rows
 *      and leaves every refused row's value as it was;
 *   C  a global administrator's flag lets every row through;
 *   D  plants: the rule's text is changed in memory, one fault at a time, and
 *      run again. Each must go red where it should — and two are shown to
 *      stay green ON PURPOSE, because the rule carries two tests for the same
 *      job ("every character is a single byte" and "no character outside the
 *      safe set") so that it does not rest on one engine alone: with either
 *      taken away, the other must still refuse every name outside A to Z.
 *
 * WHAT IT CANNOT DO, said plainly
 *   - It runs the rule on the names below. A look-alike nobody has thought of
 *     is caught only if the rule is right, which is what this tests for the
 *     ones listed in `SettingsRowRule::isSafeName()`'s own note.
 *   - It does not create the race itself (two requests, a row renamed in
 *     between). It runs the very statement that race would reach.
 *   - The plants change the TEXT this file is handed, in memory. A plant in the
 *     source file itself was run by hand when this was written (#1048's
 *     commit message records it); a test cannot safely edit the repository.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_rowrule SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         php tools/settings-row-rule-sql-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql` (only
 *         `tblSettings` is used, and only to copy its shape). It writes
 *         nothing to any real table: everything is in a temporary table that
 *         goes when the connection closes. It REFUSES rather than skipping
 *         when anything is missing, because a skipped test reads exactly like
 *         a passing one.
 *
 * Exit:   0 only when every check ran and passed and the number of checks is
 *         the number expected; 1 otherwise; 2 when it refused to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1048
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;

function refuse(string $why): never
{
    echo "REFUSED: {$why}.\n";
    echo "Nothing more was checked, and this run fails. A skipped test reads exactly\n";
    echo "like a passing one, which is why this refuses rather than skipping.\n";
    exit(2);
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok === true) {
        $passed += 1;
        echo "  ✓ {$name}\n";
        return;
    }
    $failed += 1;
    echo "  ✗ {$name}\n";
    if ($detail !== '') {
        echo "      {$detail}\n";
    }
}

// ── What it needs ────────────────────────────────────────────────────────────
$dbName = (string) getenv('SELFTEST_DB_NAME');
if (preg_match('/^selftest_[A-Za-z0-9_]+$/', $dbName) !== 1) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}')");
}
$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (Throwable $e) {
    refuse("could not reach {$dbName} on {$dbHost}:{$dbPort}");
}
// The portal's own connection is utf8mb4 (DbServer::connect()); so is this one,
// or the multi-byte names below would reach the server as something else.
$db->set_charset('utf8mb4');

require $repo . $sep . 'web' . $sep . '_core' . $sep . 'SettingsRowRule.php';
use Portal\Core\SettingsRowRule;

$column = $db->query(
    "SELECT `COLLATION_NAME` AS `c` FROM `information_schema`.`COLUMNS` "
    . "WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblSettings' AND `COLUMN_NAME` = 'settingKey'"
)->fetch_assoc();
if ($column === null) {
    refuse("{$dbName} has no tblSettings.settingKey — load web/_sql/full_schema.sql first");
}
$realCollation = (string) $column['c'];

// ── The copy: the real shape, in a temporary table ──────────────────────────
$db->query('CREATE TEMPORARY TABLE `tmpRowRule1048` LIKE `tblSettings`');
// A temporary table is not listed in information_schema.COLUMNS, so its own
// collation is asked of SHOW FULL COLUMNS, which does list it.
$tmpCollation = '';
$cols = $db->query("SHOW FULL COLUMNS FROM `tmpRowRule1048` WHERE `Field` = 'settingKey'");
if (($row = $cols->fetch_assoc()) !== null) {
    $tmpCollation = (string) ($row['Collation'] ?? '');
}
$cols->free();
// Several names below are EQUAL to each other under this collation (that is
// rule 3), so the copy's unique keys are dropped or they could not all be put in.
$keys = $db->query("SHOW INDEX FROM `tmpRowRule1048` WHERE `Non_unique` = 0 AND `Key_name` <> 'PRIMARY'");
$drop = [];
while (($row = $keys->fetch_assoc()) !== null) {
    $drop[(string) $row['Key_name']] = true;
}
$keys->free();
foreach (array_keys($drop) as $keyName) {
    // A key name read from the server, never from a request.
    $db->query('ALTER TABLE `tmpRowRule1048` DROP INDEX `' . str_replace('`', '``', $keyName) . '`');
}

echo "\nPremises\n";
check('P0 the copy\'s name column has the REAL column\'s collation (' . $realCollation . '), so the rule is judged under the comparison the portal really uses',
    $tmpCollation !== '' && $tmpCollation === $realCollation, "real '{$realCollation}', copy '{$tmpCollation}'");

/**
 * The names, each with what an administrator of ONE organisation must get:
 * true = the rule lets the row through, false = it refuses it. Written by hand
 * on purpose: section A also asks the PHP half, but a test that only compared
 * the two halves would pass with both wrong in the same way.
 *
 * The note says which part of the rule refuses each, because the plants in
 * section D rely on there being a name that only ONE part refuses.
 *
 * @var list<array{0: string, 1: bool, 2: string}>
 */
$names = [
    ['mail.queue.keepDays',        true,  'an ordinary setting, capital letters and all'],
    ['portal.trustedProxies',      true,  'names here are not all lower case'],
    ['a-b_c.d',                    true,  'every character in the safe set'],
    ['0',                          true,  'one digit is a name'],
    ['publicity.x',                true,  'begins "public" but not "public."'],
    ['public_x',                   true,  'an underscore is not a full stop'],
    ['notpublic.x',                true,  '"public." in the middle is not the beginning'],
    ['x.public',                   true,  '"public" at the end is not the beginning'],
    ['public',                     false, 'the bare name: refused only by <> \'public\''],
    ['PUBLIC',                     false, 'any letter case: refused only by <> \'public\''],
    ['Public',                     false, 'any letter case: refused only by <> \'public\''],
    ['public.',                    false, 'refused only by NOT LIKE \'public.%\''],
    ['public.example',             false, 'refused only by NOT LIKE \'public.%\''],
    ['PUBLIC.Website.enabled',     false, 'refused only by NOT LIKE \'public.%\''],
    ['',                           false, 'an empty name: refused only by <> \'\''],
    ["pu\u{0001}blic.example",     false, 'a control character, one byte: refused only by the safe-set REGEXP'],
    ["abc\n",                      false, 'a final line break, one byte: refused only by the REGEXP'],
    ['has space',                  false, 'a space: refused only by the REGEXP'],
    ['semi;colon',                 false, 'refused only by the REGEXP'],
    ["tab\tkey",                   false, 'refused only by the REGEXP'],
    ['a/b',                        false, 'refused only by the REGEXP'],
    ['x%y',                        false, 'a LIKE wildcard: refused only by the REGEXP'],
    ["pu\u{0301}blic.example",     false, 'an accent typed as a separate mark: single-byte test AND REGEXP'],
    ["\u{FF50}\u{FF55}\u{FF42}\u{FF4C}\u{FF49}\u{FF43}.example", false, 'full-width letters: single-byte test AND REGEXP, and NOT LIKE as well, because the collation folds them to "public." (measured)'],
    ["public\u{FF0E}example",      false, 'a full-width full stop: single-byte test AND REGEXP, and NOT LIKE as well (folded, measured)'],
    ["pu\u{200B}blic.example",     false, 'an invisible zero-width space: single-byte test AND REGEXP'],
    ["\u{212A}elvin.key",          false, 'the Kelvin sign, which looks like K: single-byte test AND REGEXP'],
    ["caf\u{00E9}.x",              false, 'an accented letter: single-byte test AND REGEXP'],
];
$multiByte = array_values(array_filter($names, static fn (array $n): bool => strlen($n[0]) !== mb_strlen($n[0], 'UTF-8')));
$asciiBad  = array_values(array_filter($names, static fn (array $n): bool => $n[1] === false && strlen($n[0]) === mb_strlen($n[0], 'UTF-8')));

$ids = [];
$ins = $db->prepare("INSERT INTO `tmpRowRule1048` (`siteID`, `settingKey`, `settingValue`) VALUES (1, ?, 'before')");
foreach ($names as [$key]) {
    $ins->bind_param('s', $key);
    $ins->execute();
    $ids[$key] = (int) $db->insert_id;
}
$ins->close();
$stored = 0;
$st = $db->prepare('SELECT `settingKey` FROM `tmpRowRule1048` WHERE `settingID` = ?');
foreach ($ids as $key => $id) {
    $st->bind_param('i', $id);
    $st->execute();
    $back = $st->get_result()->fetch_assoc();
    // Byte for byte: the database must hold the very name, or the rule would
    // be judged on something else (a connection in another character set).
    if ($back !== null && (string) $back['settingKey'] === (string) $key) {
        $stored += 1;
    }
}
$st->close();
check('P1 every one of the ' . count($names) . ' names is stored byte for byte as written', $stored === count($names), "{$stored} of " . count($names));
check('P2 the list has a name each part of the rule refuses alone: ' . count($asciiBad) . ' single-byte names and ' . count($multiByte) . ' multi-byte ones',
    count($asciiBad) >= 5 && count($multiByte) >= 5);

/**
 * Which of the names the rule lets through, run as the save handler runs it:
 * `settingID = ?`, this organisation's own row or a portal-wide one for a global
 * administrator, AND the name half. Every row here is organisation 1's own, so
 * only the name half decides. Asked one row at a time, so a fault in one row
 * cannot hide behind another.
 *
 * @return array<string, bool>
 */
$allowedBy = static function (string $rule, int $globalFlag) use ($db, $ids): array {
    $site = 1;
    $sql  = 'SELECT 1 FROM `tmpRowRule1048` WHERE `settingID` = ? '
        . 'AND (`siteID` = ? OR (`siteID` IS NULL AND ? = 1)) AND ' . $rule;
    $st = $db->prepare($sql);
    $out = [];
    foreach ($ids as $key => $id) {
        $st->bind_param('iiii', $id, $site, $globalFlag, $globalFlag);
        $st->execute();
        $out[(string) $key] = $st->get_result()->fetch_row() !== null;
    }
    $st->close();

    return $out;
};

/** A name shown so that an invisible character can be seen in a failure. */
$show = static fn (string $key): string => json_encode($key, JSON_UNESCAPED_SLASHES) ?: '?';

$rule = SettingsRowRule::nameRuleSql();
check('P3 the rule has exactly ONE placeholder, the global administrator\'s flag (the statements bind it once)',
    substr_count($rule, '?') === 1, $rule);

// ── A ────────────────────────────────────────────────────────────────────────
echo "\nA. Each name is allowed or refused, by the database, as it must be\n";
$got = $allowedBy($rule, 0);
foreach ($names as [$key, $want, $why]) {
    $php = SettingsRowRule::whyNotChangeable($key, false, false) === null;
    check('A ' . $show($key) . ' is ' . ($want === true ? 'ALLOWED' : 'REFUSED') . ' (' . $why . '), and the PHP half agrees',
        $got[$key] === $want && $php === $want,
        'database: ' . ($got[$key] === true ? 'allowed' : 'refused') . ', PHP: ' . ($php === true ? 'allowed' : 'refused'));
}

// ── B ────────────────────────────────────────────────────────────────────────
echo "\nB. The UPDATE itself, as settings/save.php sends it\n";
// The same statement shape as the save (`settingValue`, the row, `isSensitive`,
// the two siteID placeholders, then the rule's one), on the temporary copy.
$up = $db->prepare(
    'UPDATE `tmpRowRule1048` SET `settingValue` = ? WHERE `settingID` = ? AND `isSensitive` = ? '
    . 'AND (`siteID` = ? OR (`siteID` IS NULL AND ? = 1)) AND ' . $rule
);
$value = 'after';
$mark  = 0;
$site  = 1;
$flag  = 0;
$wroteWhere = 0;
$wroteRight = 0;
foreach ($names as [$key, $want]) {
    $id = $ids[$key];
    $up->bind_param('siiiii', $value, $id, $mark, $site, $flag, $flag);
    $up->execute();
    $changed = $up->affected_rows === 1;
    $wroteWhere += $changed === true ? 1 : 0;
    $wroteRight += $changed === $want ? 1 : 0;
}
$up->close();
$after = [];
$rs = $db->query('SELECT `settingKey`, `settingValue` FROM `tmpRowRule1048`');
while (($row = $rs->fetch_assoc()) !== null) {
    $after[] = $row;
}
$rs->free();
$refusedUntouched = 0;
$allowedChanged   = 0;
foreach ($after as $row) {
    foreach ($names as [$key, $want]) {
        if ((string) $row['settingKey'] === (string) $key) {
            if ($want === false && $row['settingValue'] === 'before') {
                $refusedUntouched += 1;
            }
            if ($want === true && $row['settingValue'] === 'after') {
                $allowedChanged += 1;
            }
        }
    }
}
$allowedCount = count(array_filter($names, static fn (array $n): bool => $n[1] === true));
check('B1 the UPDATE wrote exactly the allowed rows (' . $allowedCount . ' of ' . count($names) . '), and no refused one',
    $wroteRight === count($names) && $wroteWhere === $allowedCount, "wrote {$wroteWhere}, {$wroteRight} as expected");
check('B2 ...read back: every refused row still holds its old value',
    $refusedUntouched === count($names) - $allowedCount, "{$refusedUntouched} untouched");
check('B3 ...and every allowed row holds the new one, so B2 is not a statement that never wrote anything',
    $allowedChanged === $allowedCount, "{$allowedChanged} changed");

// ── C ────────────────────────────────────────────────────────────────────────
echo "\nC. A global administrator is never refused by the name half\n";
$global = $allowedBy($rule, 1);
check('C1 with the flag set, every one of the ' . count($names) . ' names is let through',
    count(array_filter($global)) === count($names), count(array_filter($global)) . ' let through');

// ── D ────────────────────────────────────────────────────────────────────────
echo "\nD. Plants: the rule's text changed in memory, one fault at a time\n";
/**
 * Run the matrix with one piece of the rule replaced. Refuses when the piece is
 * not there, so a rule that has been reworded makes this say so rather than
 * "plant" nothing and report the run green.
 *
 * @return array{applied: bool, wrong: list<string>, allowed: array<string, bool>}
 */
$plant = static function (string $from, string $to, int $flag = 0) use ($rule, $allowedBy, $names): array {
    if (substr_count($rule, $from) !== 1) {
        return ['applied' => false, 'wrong' => [], 'allowed' => []];
    }
    $planted = str_replace($from, $to, $rule);
    $allowed = $allowedBy($planted, $flag);
    $wrong = [];
    foreach ($names as [$key, $want]) {
        if ($allowed[$key] !== ($flag === 1 ? true : $want)) {
            $wrong[] = (string) $key;
        }
    }

    return ['applied' => true, 'wrong' => $wrong, 'allowed' => $allowed];
};
$redFor = static function (array $r, array $mustBeWrong) use ($show): array {
    $missing = array_values(array_diff($mustBeWrong, $r['wrong']));

    return [$r['applied'] === true && $r['wrong'] !== [] && $missing === [],
        $r['applied'] === false ? 'NOT APPLIED: the text to change is not in the rule' : 'wrong for: '
            . implode(', ', array_map($show, $r['wrong'])) . ($missing !== [] ? '; expected also: ' . implode(', ', array_map($show, $missing)) : '')];
};

// D1 is the issue's own plant: the whole name half made true.
// If the rule has been rewritten so that its name half no longer begins with
// `(CHAR_LENGTH`, the plant reports NOT APPLIED and goes red, rather than crash.
$innerAt = strpos($rule, '(CHAR_LENGTH');
$r = $innerAt === false
    ? ['applied' => false, 'wrong' => [], 'allowed' => []]
    : $plant(substr($rule, $innerAt, -1), '(1 = 1)');
[$ok, $detail] = $redFor($r, array_map(static fn (array $n): string => $n[0], array_values(array_filter($names, static fn (array $n): bool => $n[1] === false))));
check('D1 the whole name half replaced by 1 = 1 (#1048\'s own plant): EVERY refused name gets through, and this run sees it', $ok, $detail);

[$ok, $detail] = $redFor($plant("AND LOWER(`settingKey`) <> 'public' ", ''), ['public', 'PUBLIC', 'Public']);
check('D2 the bare-name test taken out: "public" in every letter case gets through', $ok, $detail);

[$ok, $detail] = $redFor($plant("AND LOWER(`settingKey`) NOT LIKE 'public.%'", ''), ['public.', 'public.example', 'PUBLIC.Website.enabled']);
check('D3 the "public." test taken out: every "public." name gets through', $ok, $detail);

[$ok, $detail] = $redFor($plant("AND `settingKey` <> '' ", ''), ['']);
check('D4 the empty-name test taken out: the empty name gets through', $ok, $detail);

$r = $plant("AND `settingKey` COLLATE utf8mb4_bin NOT REGEXP '[^A-Za-z0-9._-]' ", '');
[$ok, $detail] = $redFor($r, ["pu\u{0001}blic.example", "abc\n", 'has space', 'semi;colon', "tab\tkey", 'a/b', 'x%y']);
check('D5 the safe-set REGEXP taken out: every single-byte name outside the safe set gets through', $ok, $detail);
$stillRefused = 0;
foreach ($multiByte as [$key]) {
    $stillRefused += ($r['allowed'][$key] ?? true) === false ? 1 : 0;
}
check('D5b ...while the single-byte test ALONE still refuses all ' . count($multiByte) . ' multi-byte names (that half does its own job)',
    $r['applied'] === true && $stillRefused === count($multiByte), "{$stillRefused} still refused");

$r = $plant('CHAR_LENGTH(`settingKey`) = LENGTH(`settingKey`) AND ', '');
check('D6 the single-byte test taken out: stays GREEN on purpose, because the REGEXP alone still refuses every name (the two halves overlap so the rule rests on no one engine)',
    $r['applied'] === true && $r['wrong'] === [], $r['applied'] === false ? 'NOT APPLIED' : 'wrong for: ' . implode(', ', array_map($show, $r['wrong'])));

[$ok, $detail] = $redFor($plant(
    "CHAR_LENGTH(`settingKey`) = LENGTH(`settingKey`) AND `settingKey` COLLATE utf8mb4_bin NOT REGEXP '[^A-Za-z0-9._-]' ",
    "1 = 1 "
), ["pu\u{0301}blic.example", "pu\u{200B}blic.example", "\u{212A}elvin.key", "caf\u{00E9}.x"]);
// The two FULL-WIDTH names are not in that list, and that was measured, not
// assumed: under utf8mb4_unicode_ci, LIKE 'public.%' folds full-width letters
// and the full-width full stop to plain ones, so NOT LIKE still refuses them
// with both character tests gone. The separate accent, the zero-width space,
// the Kelvin sign and an ordinary accented letter are refused by the two
// character tests and by nothing else.
check('D7 BOTH character tests taken out: the look-alikes LIKE cannot see (a separate accent, a zero-width space, the Kelvin sign, an accented letter) get through, so between them the two really are what refuses them', $ok, $detail);

[$ok, $detail] = $redFor($plant('(? = 1 OR', '(? = 2 OR', 1), ['public', 'public.example', '', "pu\u{0001}blic.example", "pu\u{200B}blic.example"]);
check('D8 the global administrator\'s flag compared with the wrong number: rows a global administrator may change are refused', $ok, $detail);

// ── Summary ─────────────────────────────────────────────────────────────────
$expected = 4 + count($names) + 3 + 1 + 9;
printf("\n%d passed, %d failed, %d ran of %d expected (%d names)\n",
    $passed, $failed, $passed + $failed, $expected, count($names));
if ($passed + $failed !== $expected) {
    echo "The number of checks is not the number expected, so a block was skipped or ran twice.\n";
    exit(1);
}
echo "It runs the rule's real text on a temporary copy of tblSettings; it does not\n";
echo "create the race (two requests, a row renamed in between) that would reach it.\n";
exit($failed === 0 ? 0 : 1);
