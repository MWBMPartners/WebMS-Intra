<?php
// Path: tools/data-copy-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A member's data copy hands over nobody else's account number, nobody else's
 * words and no secret — self-test, block by block 🧾 (#1063)
 * -----------------------------------------------------------------------------
 * WHAT THIS HOLDS IN PLACE
 * ------------------------
 * `account/data-export` builds a member's full copy of what the portal holds
 * about them from dozens of queries, each naming its columns by hand. The rule
 * every one of them follows: hand over what is YOURS; leave out another
 * person's account number (who granted, added, marked or entered it), words
 * another person wrote (a worker's or administrator's notes), and any secret
 * that works without signing in. Until #1063's check round 1 that rule was
 * guarded by nothing for most blocks: adding `c.grantedByID` back to one
 * stayed green.
 *
 * WHAT IT DOES
 * ------------
 * It reads every `'name' => $fetchUserRows(...)` block out of the REAL page,
 * works out each block's SQL by evaluating the page's own expression, and runs
 * it against a world built for that block alone, inside a transaction that is
 * rolled back afterwards:
 *   - every table the block reads gets one row, with the columns its WHERE
 *     clause matches on set to ME (the member asking);
 *   - every OTHER column that holds an account number (one linked to
 *     tblUsers, or named like one: `…ByID`, `…UserID`, `userID`, `donorID`,
 *     `viewerID`, `assignedToID`) is set to OTHER, another real person;
 *   - columns that hold another person's words (OTHER_WORDS below) say
 *     "OTHERWORDS", and secrets (SECRETS) say "SECRETVAL".
 * Then every value the block hands over is read: another person's number, or
 * either marker, fails the block - naming the column.
 *
 * WHAT IT CANNOT SEE, said plainly: a block written as a closure (the mail
 * blocks, the imported-event notices, the demo-data list, and the ones asked
 * of GdprEraser helpers), which the page builds by other means and section 6
 * of erasure-pages-selftest.php drives; an account-number column named in
 * none of the shapes above and not linked to tblUsers; another person's words
 * in a column not on OTHER_WORDS (that list is this test's own judgement, and
 * a new free-text column must be judged and added); and whether a block's
 * rows are the SAME rows the erasure deletes (section 27 of
 * gdpr-coverage-selftest.php judges which tables, not which rows).
 *
 * Needs: SELFTEST_DB_* naming a database built from web/_sql/full_schema.sql.
 * Nothing it writes survives: two people are added and removed, and each
 * block's world is rolled back.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1063
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

const ME    = 918801;
const OTHER = 918802;
const PAGE  = __DIR__ . '/../web/_apps/auth/account/data-export.php';
// Columns that hold words ANOTHER person wrote, by this test's judgement.
const OTHER_WORDS = [
    'tblAssetOwners.notes', 'tblLeadershipAssignments.notes', 'tblPathwayProgress.notes', 'tblVisitor.notes',
    'tblOffboarding.reason', 'tblOffboarding.stepsLog', 'tblVenueBookings.notes', 'tblSmsMessage.body',
];
// Secrets that work without signing in, or a sign-in secret even scrambled.
const SECRETS = [
    'tblPaymentMethod.customerRef', 'tblPaymentMethod.methodRef', 'tblPushSubscriptions.endpoint', 'tblPushSubscriptions.p256dhKey',
    'tblPushSubscriptions.authKey', 'tblInvitation.tokenHash', 'tblNewsletterRecipient.unsubToken', 'tblNewsletterSubscription.unsubToken',
    'tblUserSmsPreference.verificationCode', 'tblLocalAccounts.passwordHash', 'tblUsers.totpSecret', 'tblPasswordResets.tokenHash',
    'tblSmsMessage.providerRef', 'tblAssetKioskPins.pinHash', 'tblZoomAccount.accessToken', 'tblZoomAccount.refreshToken',
];
// A value fixed for one column, where the block's own rule depends on it: a
// prayer-chain text's words are another member's request (D-016), so the
// block must leave its body out.
const FIXED = ['tblSmsMessage.category' => 'prayer_assignment'];

$passes   = 0;
$failures = 0;
function dc_check(string $label, bool $good, string $detail = ''): void
{
    global $passes, $failures;
    if ($good === true) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label}\n" . ($detail !== '' ? "        {$detail}\n" : '');
    }
}

$name = getenv('SELFTEST_DB_NAME');
if ($name === false || $name === '') {
    fwrite(STDERR, "REFUSED: SELFTEST_DB_NAME is not set (a database built from web/_sql/full_schema.sql).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli((string) (getenv('SELFTEST_DB_HOST') ?: '127.0.0.1'), (string) (getenv('SELFTEST_DB_USER') ?: 'root'),
    (string) (getenv('SELFTEST_DB_PASS') ?: ''), $name, (int) (getenv('SELFTEST_DB_PORT') ?: 3306));
$db->set_charset('utf8mb4');

// ── The blocks, as the page writes them ─────────────────────────────────────
$source = (string) file_get_contents(PAGE);
if (preg_match('/^const DATA_COPY_ROWS_PER_KIND = (\d+);/m', $source, $limitHit) === 1) {
    define('DATA_COPY_ROWS_PER_KIND', (int) $limitHit[1]);
}
$blocks = [];
preg_match_all("/'(\w+)' => \\\$fetchUserRows\(/", $source, $starts, PREG_OFFSET_CAPTURE);
foreach ($starts[1] as $i => [$key]) {
    $open  = $starts[0][$i][1] + strlen($starts[0][$i][0]);
    $depth = 1;
    $j     = $open;
    $end   = null;   // end of the first argument
    for ($n = strlen($source); $j < $n && $depth > 0; $j++) {
        $c = $source[$j];
        if ($c === "'" || $c === '"') {
            for ($j++; $j < $n && $source[$j] !== $c; $j++) {
                if ($source[$j] === '\\') {
                    $j++;
                }
            }
            continue;
        }
        if ($c === '/' && ($source[$j + 1] ?? '') === '/') {
            $j = (int) strpos($source, "\n", $j);
            continue;
        }
        if ($c === '(' || $c === '[') {
            $depth++;
        } elseif ($c === ')' || $c === ']') {
            $depth--;
        } elseif ($c === ',' && $depth === 1 && $end === null) {
            $end = $j;
        }
    }
    $expr = trim(substr($source, $open, ($end ?? $j - 1) - $open));
    $expr = (string) preg_replace('~^\s*//[^\n]*\n~m', '', $expr);
    // The page's own expression: string pieces and the row limit, nothing else.
    $blocks[$key] = (string) eval('return ' . $expr . ';');
}
dc_check('premise: the page\'s blocks are found and read (more than 40, and the ones #1063 added among them)',
    count($blocks) > 40 && isset($blocks['eventsYouCoordinate'], $blocks['textMessagesSentToYou'], $blocks['yourVisitorRecord']),
    count($blocks) . ' found');

// ── The two people ──────────────────────────────────────────────────────────
foreach ([ME => 'Me', OTHER => 'Other'] as $id => $who) {
    $db->query('DELETE FROM `tblUsers` WHERE `userID` = ' . $id);
    $stmt = $db->prepare('INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`) VALUES (?, ?, ?, 1)');
    $mail = strtolower($who) . '.p1063@selftest.invalid';
    $full = 'P1063 ' . $who;
    $stmt->bind_param('iss', $id, $full, $mail);
    $stmt->execute();
}

// ── What the schema says about each table ───────────────────────────────────
$columnsOf = static function (string $table) use ($db, $name): array {
    $stmt = $db->prepare('SELECT `COLUMN_NAME`, `DATA_TYPE`, `COLUMN_TYPE`, `EXTRA`, `CHARACTER_MAXIMUM_LENGTH`, `COLUMN_KEY`, `GENERATION_EXPRESSION` '
        . 'FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = ? AND `TABLE_NAME` = ? ORDER BY `ORDINAL_POSITION`');
    $stmt->bind_param('ss', $name, $table);
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
};
$userLinked = [];
$stmt = $db->prepare("SELECT `TABLE_NAME`, `COLUMN_NAME` FROM `information_schema`.`KEY_COLUMN_USAGE` WHERE `TABLE_SCHEMA` = ? AND `REFERENCED_TABLE_NAME` = 'tblUsers'");
$stmt->bind_param('s', $name);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $userLinked[$r['TABLE_NAME'] . '.' . $r['COLUMN_NAME']] = true;
}
$isAccountColumn = static fn (string $table, string $col): bool => isset($userLinked[$table . '.' . $col])
    || preg_match('/(ByID|UserID)$|^(userID|donorID|viewerID|assignedToID)$/', $col) === 1;

/** One row for one table: every column given a value by the rules above. */
$rowFor = static function (string $table, array $matchCols) use ($columnsOf, $isAccountColumn): array {
    $row = [];
    foreach ($columnsOf($table) as $c) {
        $col  = (string) $c['COLUMN_NAME'];
        $type = strtolower((string) $c['DATA_TYPE']);
        $full = $table . '.' . $col;
        if ((string) ($c['GENERATION_EXPRESSION'] ?? '') !== '') {
            continue;
        }
        if (in_array($col, $matchCols, true) === true) {
            $row[$col] = ME;
        } elseif (isset(FIXED[$full]) === true) {
            $row[$col] = FIXED[$full];
        } elseif ($c['COLUMN_KEY'] === 'PRI' && str_contains((string) $c['EXTRA'], 'auto_increment') === true) {
            $row[$col] = 1;
        } elseif (in_array($type, ['int', 'bigint', 'smallint', 'mediumint', 'tinyint'], true) === true) {
            $row[$col] = $isAccountColumn($table, $col) === true ? OTHER : 1;
        } elseif (in_array($type, ['decimal', 'float', 'double'], true) === true) {
            $row[$col] = 1;
        } elseif (in_array($type, ['date'], true) === true) {
            $row[$col] = '2026-01-02';
        } elseif (in_array($type, ['datetime', 'timestamp'], true) === true) {
            $row[$col] = '2026-01-02 03:04:05';
        } elseif ($type === 'time') {
            $row[$col] = '03:04:05';
        } elseif ($type === 'year') {
            $row[$col] = 2026;
        } elseif ($type === 'enum' || $type === 'set') {
            preg_match("/'((?:[^']|'')*)'/", (string) $c['COLUMN_TYPE'], $opt);
            $row[$col] = (string) ($opt[1] ?? '');
        } elseif ($type === 'json') {
            $row[$col] = in_array($full, OTHER_WORDS, true) === true ? '{"w":"OTHERWORDS"}' : (in_array($full, SECRETS, true) === true ? '{"w":"SECRETVAL"}' : '{}');
        } elseif (in_array($type, ['binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob'], true) === true) {
            $row[$col] = 'x';
        } else {
            $word = in_array($full, OTHER_WORDS, true) === true ? 'OTHERWORDS' : (in_array($full, SECRETS, true) === true ? 'SECRETVAL' : 'mine');
            $max  = (int) ($c['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
            $row[$col] = $max > 0 && strlen($word) > $max ? substr('mine', 0, $max) : $word;
        }
    }

    return $row;
};

// ── Each block in its own world ─────────────────────────────────────────────
$db->query('SET SESSION foreign_key_checks = 0');
$wrong   = [];
$checked = 0;
foreach ($blocks as $key => $sql) {
    $outer = (string) preg_replace('/\(\s*SELECT[^()]*\)/i', ' ', $sql);
    preg_match_all('/\b(?:FROM|JOIN)\s+`?(tbl\w+)`?/i', $outer, $t);
    $tables = array_values(array_unique($t[1]));
    $where  = (string) substr($outer, (int) stripos($outer, ' WHERE '));
    preg_match_all('/`?(\w+)`?\s*=\s*\?/', $where, $m);
    $matchCols = array_values(array_unique($m[1]));
    if ($tables === [] || $matchCols === []) {
        $wrong[] = $key . ': its tables or the column it matches on could not be read from its SQL';
        continue;
    }
    $db->begin_transaction();
    try {
        foreach ($tables as $table) {
            $row  = $rowFor($table, $matchCols);
            $cols = array_keys($row);
            $sqlI = 'INSERT IGNORE INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
            $ins  = $db->prepare($sqlI);
            $vals = array_values($row);
            $ins->bind_param(str_repeat('s', count($vals)), ...array_map('strval', $vals));
            $ins->execute();
        }
        $run   = $db->prepare($sql);
        $marks = substr_count($sql, '?');
        $me    = array_fill(0, max(1, $marks), ME);
        $run->bind_param(str_repeat('i', count($me)), ...$me);
        $run->execute();
        $rows = $run->get_result()->fetch_all(MYSQLI_ASSOC);
        if ($rows === []) {
            $wrong[] = $key . ': its query found nothing in a world built for it, so it could not be judged';
        }
        foreach ($rows as $r) {
            foreach ($r as $col => $value) {
                if ($value === null) {
                    continue;
                }
                if ((string) $value === (string) OTHER) {
                    $wrong[] = $key . '.' . $col . ' hands over ANOTHER person\'s account number';
                }
                if (str_contains((string) $value, 'OTHERWORDS') === true) {
                    $wrong[] = $key . '.' . $col . ' hands over words ANOTHER person wrote';
                }
                if (str_contains((string) $value, 'SECRETVAL') === true) {
                    $wrong[] = $key . '.' . $col . ' hands over a secret';
                }
            }
        }
        $checked++;
    } catch (\mysqli_sql_exception $e) {
        $wrong[] = $key . ': could not be run (' . $e->getMessage() . ')';
    } finally {
        $db->rollback();
    }
}
$db->query('SET SESSION foreign_key_checks = 1');
dc_check('every $fetchUserRows() block of the data copy, run against a world where another person\'s number, words and secrets sit in every other column, hands over none of them (#1063)',
    $wrong === [] && $checked === count($blocks), implode("\n        ", $wrong));

// ── Tidy ────────────────────────────────────────────────────────────────────
$db->query('DELETE FROM `tblUsers` WHERE `userID` IN (' . ME . ', ' . OTHER . ')');

echo "\n" . str_repeat('=', 78) . "\n";
printf("%d passed, %d failed (%d blocks judged)\n", $passes, $failures, $checked);
exit($failures === 0 && $passes === 2 ? 0 : 1);
