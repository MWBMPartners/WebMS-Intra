<?php
// Path: tools/network-address-retention-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The network-address clear-out, driven against a real database 🌐🧹 (#1019)
 * -----------------------------------------------------------------------------
 * Proves the nightly clear-out (`address_sweep()` in
 * `web/_apps/cron/_retention-sweep.php`) empties a network address from every
 * place `NetworkAddressRetention::PLACES` lists once it is older than the
 * period that applies to it, and NOTHING ELSE:
 *
 *   A  two organisations with different figures, an address inside and
 *      outside each period in every one of the seven places, the rows that
 *      belong to an account (password resets, trusted devices) following the
 *      installation's figure; the count shown first equals what is then
 *      emptied; an access key never used keeps its address; a second run
 *      empties nothing; and, in EVERY scenario, every row of every table the
 *      clear-out could reach is compared WHOLE before and after — every
 *      column but the address, which the fixture fills (nullable ones too,
 *      or emptying one would change nothing), with each column the database
 *      re-stamps on an update (ON UPDATE CURRENT_TIMESTAMP) set years back
 *      first, or a re-stamp in the same second would compare equal (#1019
 *      round 3: the first version of this test passed with a sweep that also
 *      emptied a change-history entry's contents, and with a trusted device's
 *      "last seen" re-stamped to the night of the clear-out);
 *   A2 the change histories (tblAuditTrail, tblAssetAudit) and Gift Aid
 *      declarations keep their addresses however old (D-187, D-189): their
 *      rows are in that whole-row comparison, address included;
 *   B  an organisation's own "keep for ever" under an installation of "keep
 *      for ever" is obeyed, and the same choice under an installation of 30
 *      days is held to 30;
 *   C  an installation of "keep for ever": the account rows keep their
 *      addresses, while an organisation that chose a number of days is still
 *      cleared after it;
 *   D  an organisation's own figure ABOVE the installation's (only SQL or a
 *      restore can store one) is held to the installation's;
 *   E  the class names its OWN setting all the way down (late static binding):
 *      saving the network-address figure never touches the activity log's;
 *   F  every column of a place's table that the database re-stamps on an
 *      update is in that place's `hold` list, read from information_schema of
 *      the loaded install script — so a column added later fails here even
 *      before anybody writes a fixture for it.
 *
 * THEN EACH GUARD IS PLANTED OUT OF A COPY and the scenario that depends on it
 * must go red, and must be green on the real files first (#709: a plant that
 * stays green means the scenario or the fixture is dead). Each scenario runs
 * in its own PHP process against a chosen copy of the files, because a class
 * cannot be loaded twice into one process.
 *
 * WHAT IT CANNOT SHOW: the scheduled job's own page calling the sweep (that is
 * one line, `run_retention_sweep()`, covered by the other retention tests),
 * nor the Retention page's forms (tools/activity-log-retention-selftest.php
 * section N drives them over HTTP).
 *
 * Usage:  SELFTEST_DB_NAME=selftest_… SELFTEST_DB_HOST=… SELFTEST_DB_PORT=… \
 *         SELFTEST_DB_USER=… SELFTEST_DB_PASS=… php tools/network-address-retention-selftest.php
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1019
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const NA_EXPECTED_CHECKS = 22; // 17 in round 2; round 3 added scenario F and plants P10-P14 and dropped P3 (D-187 left it nothing to match).

$naDbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($naDbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$naDbName}').\n";
    exit(1);
}
$naEnv = [
    'SELFTEST_DB_NAME' => $naDbName,
    'SELFTEST_DB_HOST' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'SELFTEST_DB_USER' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'SELFTEST_DB_PASS' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'SELFTEST_DB_PORT' => getenv('SELFTEST_DB_PORT') !== false ? (string) getenv('SELFTEST_DB_PORT') : '3306',
];

// =============================================================================
// 🧒 THE CHILD: run ONE scenario against the files under `$argv[3]`.
// =============================================================================
if (($argv[1] ?? '') === '--scenario') {
    $web = (string) ($argv[3] ?? '');
    $sep = DIRECTORY_SEPARATOR;
    foreach (['InstallationSettings', 'App', 'SettingLimit', 'RetentionPeriod', 'ActivityLogRetention', 'NetworkAddressRetention'] as $class) {
        require_once $web . $sep . '_core' . $sep . $class . '.php';
    }
    require_once $web . $sep . '_apps' . $sep . 'cron' . $sep . '_retention-sweep.php';

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($naEnv['SELFTEST_DB_HOST'], $naEnv['SELFTEST_DB_USER'], $naEnv['SELFTEST_DB_PASS'],
        $naEnv['SELFTEST_DB_NAME'], (int) $naEnv['SELFTEST_DB_PORT']);
    $db->set_charset('utf8mb4');
    \Portal\Core\App::init($db, []);

    try {
        $answer = na_scenario($db, (string) $argv[2]);
    } catch (\Throwable $e) {
        $answer = ['ok' => false, 'detail' => 'threw ' . get_class($e) . ': ' . $e->getMessage()];
    }
    echo json_encode($answer);
    exit(0);
}

/**
 * 🏗️ Put one row in, filling every NOT NULL column with no default from its
 *    type, so the fixture does not have to know each table's whole shape.
 *    Foreign keys are off on this connection while the world is built: the
 *    sweep reads no link but the recording's organisation, which is real.
 *
 * @param array<string, int|string|null> $given Columns to set; a value
 *        starting `@ago:` is "so many days before now".
 */
function na_insert(mysqli $db, string $table, array $given): int
{
    static $shapes = [];
    if (isset($shapes[$table]) === false) {
        $stmt = $db->prepare(
            'SELECT `COLUMN_NAME`, `DATA_TYPE`, `COLUMN_TYPE`, `CHARACTER_MAXIMUM_LENGTH` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() '
            // Round 3: nullable columns are filled too, so that a statement
            // emptying one can be SEEN by the whole-row comparison.
            . 'AND `TABLE_NAME` = ? AND (`IS_NULLABLE` = \'YES\' OR `COLUMN_DEFAULT` IS NULL) AND `EXTRA` NOT LIKE \'%auto_increment%\' '
            . 'AND `GENERATION_EXPRESSION` = \'\''
        );
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $shapes[$table] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    foreach ($shapes[$table] as $col) {
        $name = (string) $col['COLUMN_NAME'];
        if (array_key_exists($name, $given) === true) {
            continue;
        }
        $type = strtolower((string) $col['DATA_TYPE']);
        $given[$name] = match (true) {
            in_array($type, ['int', 'tinyint', 'smallint', 'bigint', 'mediumint', 'decimal', 'float', 'double', 'year'], true) => 1,
            $type === 'time' => '10:00:00',
            $type === 'set' => trim(explode(',', substr((string) $col['COLUMN_TYPE'], 4))[0], "')"),
            in_array($type, ['datetime', 'timestamp'], true) => '2026-01-01 00:00:00',
            $type === 'date' => '2026-01-01',
            $type === 'enum' => trim(explode(',', substr((string) $col['COLUMN_TYPE'], 5))[0], "')"),
            $type === 'json' => '{}',
            // Random, so a unique key never collides; cut to the column's own length.
            default => substr(bin2hex(random_bytes(16)), 0, max(1, min(32, (int) ($col['CHARACTER_MAXIMUM_LENGTH'] ?? 32)))),
        };
    }
    $cols   = [];
    $values = [];
    $binds  = [];
    foreach ($given as $name => $value) {
        $cols[] = '`' . $name . '`';
        if (is_string($value) === true && str_starts_with($value, '@ago:') === true) {
            $values[] = 'DATE_SUB(NOW(), INTERVAL ? DAY)';
            $binds[]  = (int) substr($value, 5);
        } else {
            $values[] = '?';
            $binds[]  = $value;
        }
    }
    $stmt = $db->prepare('INSERT INTO `' . $table . '` (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $values) . ')');
    $stmt->bind_param(str_repeat('s', count($binds)), ...array_map(static fn ($v) => $v === null ? null : (string) $v, $binds));
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();

    return $id;
}

/** Set the network-address figure at one level (null = installation) and hand start-up's layer over. */
function na_set(mysqli $db, ?int $siteId, ?string $value): void
{
    $key = \Portal\Core\NetworkAddressRetention::KEY;
    $del = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND ' . ($siteId === null ? '`siteID` IS NULL' : '`siteID` = ?'));
    if ($siteId === null) {
        $del->bind_param('s', $key);
    } else {
        $del->bind_param('si', $key, $siteId);
    }
    $del->execute();
    $del->close();
    if ($value !== null) {
        $ins = $db->prepare('INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, ?, \'\', 0)');
        $ins->bind_param('iss', $siteId, $key, $value);
        $ins->execute();
        $ins->close();
    }
    $inst = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
    $inst->bind_param('s', $key);
    $inst->execute();
    $row = $inst->get_result()->fetch_assoc();
    $inst->close();
    \Portal\Core\InstallationSettings::remember($row === null ? [] : ['privacy' => ['networkAddressDays' => (string) $row['settingValue']]]);
}

/**
 * 🌍 A fresh world: every place emptied of fixture rows, two organisations,
 *    and for each place an address 5, 20 and 40 days old at each
 *    organisation (or, for a place belonging to an account, once each).
 *
 * @return array<string, array{table: string, column: string, key: string, id: int}> label => row
 */
function na_world(mysqli $db): array
{
    // 📋 THE TEST'S OWN description of each place, written here and NOT read
    //    from the code under test: a world built from `PLACES` would follow a
    //    planted change to `PLACES` and could never catch one (plant P6 stayed
    //    green until this was written down separately).
    //    table => [address column, date column, key column, how it names its organisation]
    $places = [
        'tblPasswordResets'   => ['createdIP',   'createdAt',  'resetID',    'account'],
        'tblTrustedDevices'   => ['createdIP',   'createdAt',  'deviceID',   'account'],
        'tblPrayerRequests'   => ['submitterIP', 'createdAt',  'requestID',  'own'],
        'tblApiKeys'          => ['lastUsedIP',  'lastUsedAt', 'keyID',      'own'],
        'tblLiveChatMessages' => ['senderIP',    'createdAt',  'messageID',  'own'],
        'tblFormResponses'    => ['submitterIP', 'createdAt',  'responseID', 'own'],
        'tblRecordingPlay'    => ['ipHash',      'playedAt',   'playID',     'recording'],
    ];
    // 🚫 Places the clear-out must NEVER empty, however old the address: the
    //    change histories (D-187) and Gift Aid declarations (D-189).
    //    table => [address column, date column, key column]
    $untouched = [
        'tblAuditTrail'         => ['ipAddress',  'createdAt',  'auditID'],
        'tblAssetAudit'         => ['ipHash',     'createdAt',  'auditID'],
        'tblGiftAidDeclaration' => ['acceptedIP', 'acceptedAt', 'declarationID'],
    ];
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $db->query("INSERT INTO `tblSites` (`siteID`, `siteName`, `siteKey`) VALUES (2, 'Second organisation', 'second') "
        . 'ON DUPLICATE KEY UPDATE `siteName` = VALUES(`siteName`)');
    foreach (array_merge(array_keys($places), array_keys($untouched)) as $table) {
        $db->query('DELETE FROM `' . $table . '`');
    }
    $db->query('DELETE FROM `tblRecording`');
    $recording = [1 => na_insert($db, 'tblRecording', ['siteID' => 1]), 2 => na_insert($db, 'tblRecording', ['siteID' => 2])];

    $rows = [];
    foreach ($places as $t => [$column, $dated, $key, $kind]) {
        $where = $kind === 'account' ? [null] : [1, 2];
        foreach ($where as $site) {
            foreach ([5, 20, 40] as $age) {
                $given = [$column => 'addr-' . $t . '-' . ($site ?? 'acct') . '-' . $age, $dated => '@ago:' . $age];
                if ($kind === 'recording') {
                    $given['recordingID'] = $recording[$site];
                } elseif ($site !== null) {
                    $given['siteID'] = $site;
                }
                if ($t === 'tblPrayerRequests') {
                    $given['body'] = 'kept words';
                }
                $rows[$t . '|' . ($site ?? 'acct') . '|' . $age] = ['table' => $t, 'column' => $column, 'key' => $key,
                    'id' => na_insert($db, $t, $given)];
            }
        }
    }
    // 🏛️ The places that keep their address for ever: old rows at both
    //    organisations, and a change-history entry with no organisation.
    foreach ($untouched as $t => [$column, $dated, $key]) {
        foreach ($t === 'tblAuditTrail' ? [1, 2, null] : [1, 2] as $site) {
            $rows[$t . '|untouched|' . ($site ?? 'none')] = ['table' => $t, 'column' => $column, 'key' => $key,
                'id' => na_insert($db, $t, [$column => 'addr-kept-' . $t . '-' . ($site ?? 'none'), $dated => '@ago:4000', 'siteID' => $site])];
        }
    }
    // 🔑 An access key never used: no `lastUsedAt`, so its address is not "old".
    $rows['tblApiKeys|never-used'] = ['table' => 'tblApiKeys', 'column' => 'lastUsedIP', 'key' => 'keyID',
        'id' => na_insert($db, 'tblApiKeys', ['siteID' => 1, 'lastUsedIP' => 'addr-unused', 'lastUsedAt' => null, 'createdAt' => '@ago:400'])];

    // ⏪ Every column the database re-stamps on an update, set years back, so
    //    a re-stamp by the clear-out is visible even when it runs in the same
    //    second as the fixture was written.
    foreach (na_tables() as $t => $address) {
        foreach (na_on_update_columns($db, $t) as $col) {
            $db->query('UPDATE `' . $t . '` SET `' . $col . "` = '2020-01-02 03:04:05'");
        }
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    $GLOBALS['naBefore'] = na_fingerprint($db);

    return $rows;
}

/**
 * The tables whose rows are compared whole, and the one column of each the
 * clear-out may change (null = none may change). The test's OWN list, like
 * na_world()'s, never read from the code under test.
 *
 * @return array<string, ?string>
 */
function na_tables(): array
{
    return [
        'tblPasswordResets' => 'createdIP', 'tblTrustedDevices' => 'createdIP', 'tblPrayerRequests' => 'submitterIP',
        'tblApiKeys' => 'lastUsedIP', 'tblLiveChatMessages' => 'senderIP', 'tblFormResponses' => 'submitterIP',
        'tblRecordingPlay' => 'ipHash', 'tblRecording' => null,
        'tblAuditTrail' => null, 'tblAssetAudit' => null, 'tblGiftAidDeclaration' => null,
    ];
}

/** @return list<string> The columns of `$table` the database re-stamps on any update. */
function na_on_update_columns(mysqli $db, string $table): array
{
    $stmt = $db->prepare(
        'SELECT `COLUMN_NAME` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? '
        . 'AND `EXTRA` LIKE \'%on update%\' ORDER BY `COLUMN_NAME`'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $out = array_map(static fn (array $r): string => (string) $r['COLUMN_NAME'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();

    return $out;
}

/**
 * 🔎 Every row of every table in na_tables(), every column but the one the
 *    clear-out may change, as one fingerprint per table.
 *
 * @return array<string, string>
 */
function na_fingerprint(mysqli $db): array
{
    $out = [];
    foreach (na_tables() as $t => $address) {
        $rows = $db->query('SELECT * FROM `' . $t . '` ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$row) {
            if ($address !== null) {
                unset($row[$address]);
            }
        }
        unset($row);
        $out[$t] = count($rows) . ':' . hash('sha256', json_encode($rows));
    }

    return $out;
}

/** Which labelled rows still hold their address, and whether every row is still there. */
function na_state(mysqli $db, array $rows): array
{
    $held = [];
    $gone = [];
    foreach ($rows as $label => $r) {
        $stmt = $db->prepare('SELECT `' . $r['column'] . '` AS `a` FROM `' . $r['table'] . '` WHERE `' . $r['key'] . '` = ?');
        $stmt->bind_param('i', $r['id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            $gone[] = $label;
        } elseif ($row['a'] !== null) {
            $held[] = $label;
        }
    }
    sort($held);

    return ['held' => $held, 'gone' => $gone];
}

/** The labels expected to KEEP their address, given each organisation's figure and the installation's (0 = for ever). */
function na_expected(array $rows, int $site1, int $site2, int $installation): array
{
    $keep = [];
    foreach (array_keys($rows) as $label) {
        $parts = explode('|', $label);
        if (in_array($parts[1] ?? '', ['never-used', 'untouched'], true)) {
            $keep[] = $label;
            continue;
        }
        $age  = (int) $parts[2];
        $days = match ($parts[1]) { '1' => $site1, '2' => $site2, default => $installation };
        if ($days === 0 || $age < $days) {
            $keep[] = $label;
        }
    }
    sort($keep);

    return $keep;
}

/**
 * 🎬 One scenario, as a verdict: `ok` and, when not, what was seen.
 *
 * @return array{ok: bool, detail: string}
 */
function na_scenario(mysqli $db, string $name): array
{
    $NA = \Portal\Core\NetworkAddressRetention::class;
    $judge = static function (array $rows, int $s1, int $s2, int $inst, int $counted, int $emptied) use ($db): array {
        $state  = na_state($db, $rows);
        $expect = na_expected($rows, $s1, $s2, $inst);
        $should = count($rows) - count($expect);
        // 🔎 Every other column of every row, as it was (round 3).
        $after   = na_fingerprint($db);
        $changed = array_keys(array_diff_assoc($after, $GLOBALS['naBefore']));
        $ok     = $state['held'] === $expect && $state['gone'] === [] && $counted === $should && $emptied === $should
            && $changed === [];

        return ['ok' => $ok, 'detail' => json_encode(['counted' => $counted, 'emptied' => $emptied, 'should' => $should,
            'tables-with-other-columns-changed' => $changed,
            'kept-but-should-not' => array_values(array_diff($state['held'], $expect)),
            'emptied-but-should-not' => array_values(array_diff($expect, $state['held'])), 'rows-gone' => $state['gone']])];
    };

    switch ($name) {
        case 'two-organisations':
            // 30 for the installation, 10 of its own for organisation 1, nothing for 2.
            $rows = na_world($db);
            na_set($db, null, '30');
            na_set($db, 1, '10');
            na_set($db, 2, null);
            $counted = address_sweep(false);
            $emptied = address_sweep(true);
            $verdict = $judge($rows, 10, 30, 30, $counted, $emptied);
            // 🔁 A second run finds nothing to count or empty, and the prayer
            //    request's words are untouched.
            $again = address_sweep(false) + address_sweep(true);
            $words = $db->query("SELECT COUNT(*) AS `n` FROM `tblPrayerRequests` WHERE `body` = 'kept words'")->fetch_assoc();

            return ['ok' => $verdict['ok'] && $again === 0 && (int) $words['n'] === 6,
                'detail' => $verdict['detail'] . ' second run: ' . $again . ', prayer words kept: ' . $words['n']];

        case 'own-never-under-never':
            $rows = na_world($db);
            na_set($db, null, '0');
            na_set($db, 1, '10');
            na_set($db, 2, '0');

            return $judge($rows, 10, 0, 0, address_sweep(false), address_sweep(true));

        case 'own-never-under-days':
            $rows = na_world($db);
            na_set($db, null, '30');
            na_set($db, 1, null);
            na_set($db, 2, '0');

            return $judge($rows, 30, 30, 30, address_sweep(false), address_sweep(true));

        case 'installation-never':
            // Account rows keep theirs; organisation 1's 10 still applies.
            $rows = na_world($db);
            na_set($db, null, '0');
            na_set($db, 1, '10');
            na_set($db, 2, null);

            return $judge($rows, 10, 0, 0, address_sweep(false), address_sweep(true));

        case 'own-above-installation':
            $rows = na_world($db);
            na_set($db, null, '30');
            na_set($db, 1, '100');
            na_set($db, 2, null);

            return $judge($rows, 30, 30, 30, address_sweep(false), address_sweep(true));

        case 'own-key-only':
            // Saving the network-address figure writes ITS key and leaves the activity log's alone.
            $db->query("DELETE FROM `tblSettings` WHERE `siteID` = 1 AND `settingKey` IN ('audit.retentionDays', 'privacy.networkAddressDays')");
            na_set($db, null, '365');
            $before = \Portal\Core\App::settingForSite('audit.retentionDays', 1);
            $saved  = $NA::saveForSite($db, 1, 45, true);
            $after  = \Portal\Core\App::settingForSite('audit.retentionDays', 1);
            $mine   = \Portal\Core\App::settingForSite($NA::KEY, 1);
            $refuse = $NA::saveForSite($db, 1, 0, true);
            $db->query("DELETE FROM `tblSettings` WHERE `siteID` = 1 AND `settingKey` IN ('audit.retentionDays', 'privacy.networkAddressDays')");

            return ['ok' => $saved['outcome'] === 'stored' && $before === $after && $mine === '45'
                    && $refuse['outcome'] === 'refused' && str_contains($refuse['message'], 'Keep for ever'),
                'detail' => json_encode([$saved, $before, $after, $mine, $refuse])];

        case 'holds-every-on-update-column':
            // Every re-stamped column of every place's table is held, and the
            // premise: there IS at least one, or the check proves nothing.
            $wrong = [];
            $found = 0;
            foreach ($NA::PLACES as $place) {
                $real = na_on_update_columns($db, $place['table']);
                $held = $place['hold'];
                sort($held);
                $found += count($real);
                if ($real !== $held) {
                    $wrong[] = $place['table'] . ': re-stamped ' . json_encode($real) . ', held ' . json_encode($held);
                }
            }

            return ['ok' => $wrong === [] && $found > 0, 'detail' => 'found ' . $found . '; ' . implode('; ', $wrong)];
    }

    return ['ok' => false, 'detail' => 'no such scenario'];
}

// =============================================================================
// 🧑‍⚖️ THE PARENT
// =============================================================================
$naPass  = 0;
$naFail  = 0;
$naCheck = static function (string $what, bool $ok, string $detail = '') use (&$naPass, &$naFail): void {
    $ok === true ? $naPass++ : $naFail++;
    echo ($ok === true ? 'PASS' : 'FAIL') . ' — ' . $what . ($ok === true || $detail === '' ? '' : "\n       " . $detail) . "\n";
};
$naRun = static function (string $scenario, string $web) use ($naEnv): array {
    $cmd   = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --scenario ' . escapeshellarg($scenario) . ' ' . escapeshellarg($web);
    $pipes = [];
    $proc  = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, $naEnv + ['PATH' => (string) getenv('PATH')]);
    $out   = stream_get_contents($pipes[1]);
    $err   = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $answer = json_decode((string) $out, true);

    return is_array($answer) === true ? $answer : ['ok' => false, 'detail' => 'no answer: ' . trim($out . ' ' . $err)];
};
$naWeb = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web';

echo "A-E. The real files\n";
$naScenarios = [
    'two-organisations'      => 'A two organisations (installation 30, own 10, the other following): exactly the addresses past each period are emptied, the count matches, every other column of every row is as it was (re-stamped dates included), the change histories and Gift Aid declarations keep their addresses (D-187, D-189), a never-used key keeps its address, a second run empties nothing',
    'own-never-under-never'  => 'B1 an organisation\'s own "keep for ever" under an installation of "keep for ever" is obeyed',
    'own-never-under-days'   => 'B2 the same choice under an installation of 30 days is held to 30',
    'installation-never'     => 'C under an installation of "keep for ever", account rows keep theirs; an organisation\'s own 10 still applies',
    'own-above-installation' => 'D an organisation\'s own figure above the installation\'s (SQL or a restore) is held to the installation\'s',
    'own-key-only'           => 'E saving the network-address figure writes its own setting, never the activity log\'s, and refuses "Keep for ever" under a number of days in its own words',
    'holds-every-on-update-column' => 'F every column a place\'s table re-stamps on an update (read from the loaded install script) is in that place\'s hold list, and there is at least one',
];
foreach ($naScenarios as $scenario => $label) {
    $answer = $naRun($scenario, $naWeb);
    $naCheck($label, $answer['ok'] === true, (string) ($answer['detail'] ?? ''));
}

echo "P. Each guard planted out of a copy: its scenario must go red\n";
/** Copy the files a scenario loads, apply ONE edit, run, and answer whether the edit applied and the scenario still passed. */
$naPlant = static function (string $relPath, string $from, string $to, string $scenario) use ($naWeb, $naRun): array {
    $copy = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'na-plant-' . bin2hex(random_bytes(4));
    $sep  = DIRECTORY_SEPARATOR;
    foreach (['_core', '_apps' . $sep . 'cron'] as $dir) {
        mkdir($copy . $sep . $dir, 0700, true);
    }
    // GeocodeCacheRetention since #1037: the sweep loads it beside the others.
    foreach (['InstallationSettings', 'App', 'SettingLimit', 'RetentionPeriod', 'ActivityLogRetention', 'NetworkAddressRetention', 'GeocodeCacheRetention'] as $class) {
        copy($naWeb . $sep . '_core' . $sep . $class . '.php', $copy . $sep . '_core' . $sep . $class . '.php');
    }
    copy($naWeb . $sep . '_apps' . $sep . 'cron' . $sep . '_retention-sweep.php', $copy . $sep . '_apps' . $sep . 'cron' . $sep . '_retention-sweep.php');
    // App::init() reads the version file beside it.
    copy($naWeb . $sep . '_core' . $sep . 'version.php', $copy . $sep . '_core' . $sep . 'version.php');
    $path   = $copy . $sep . str_replace('/', $sep, $relPath);
    $before = (string) file_get_contents($path);
    $applied = substr_count($before, $from) === 1;
    if ($applied === true) {
        file_put_contents($path, str_replace($from, $to, $before));
    }
    $answer = $applied === true ? $naRun($scenario, $copy) : ['ok' => null, 'detail' => ''];
    // 🚫 A plant that CRASHED the copy proves nothing about the guard (the
    //    first version of this test went "red" on all nine plants because the
    //    copy lacked a file App needs). Only a real verdict of "not ok" counts.
    $detail = (string) ($answer['detail'] ?? '');
    if ($answer['ok'] === false && (str_starts_with($detail, 'no answer') === true || str_starts_with($detail, 'threw ') === true)) {
        $answer['ok'] = 'crashed: ' . substr($detail, 0, 200);
    }
    foreach (glob($copy . $sep . '_core' . $sep . '*') ?: [] as $f) {
        unlink($f);
    }
    unlink($copy . $sep . '_apps' . $sep . 'cron' . $sep . '_retention-sweep.php');
    rmdir($copy . $sep . '_apps' . $sep . 'cron');
    rmdir($copy . $sep . '_apps');
    rmdir($copy . $sep . '_core');
    rmdir($copy);

    // NA_SHOW_PLANTS=1 prints what each planted run saw, to confirm it went
    // red for the reason its label gives rather than some other one.
    if (getenv('NA_SHOW_PLANTS') === '1') {
        echo '       planted run saw: ' . substr((string) ($answer['detail'] ?? ''), 0, 600) . "\n";
    }

    return [$applied, $answer['ok']];
};
$naPlants = [
    ['P1 the per-organisation "keep for ever" skip taken out', '_apps/cron/_retention-sweep.php',
        "            if (\$days === NetworkAddressRetention::NEVER) {\n                continue; // ♾️", "            if (false) {\n                continue; // ♾️", 'own-never-under-never'],
    ['P2 the installation\'s "keep for ever" skip taken out for account rows', '_apps/cron/_retention-sweep.php',
        "            if (\$installationDays !== NetworkAddressRetention::NEVER) {\n                \$run(\$head . \$old, 'i', [\$installationDays]);",
        "            if (true) {\n                \$run(\$head . \$old, 'i', [\$installationDays]);", 'installation-never'],
    // (P3, the installation's "keep for ever" skip for rows with NO
    //    organisation, was dropped in round 3: only tblAuditTrail allowed an
    //    empty organisation, and it left the places under D-187, so that
    //    statement now matches no row and a plant of it could not go red.)
    ['P4 the organisation scoping loosened (one organisation\'s figure reaching the other\'s rows)', '_apps/cron/_retention-sweep.php',
        "\$siteCol . ' = ? AND '", "\$siteCol . ' >= ? AND '", 'two-organisations'],
    ['P5 the age test turned round', '_apps/cron/_retention-sweep.php',
        "\$place['dated'] . '` < DATE_SUB", "\$place['dated'] . '` > DATE_SUB", 'two-organisations'],
    ['P6 the recording play\'s organisation join dropped (plays swept as if they had none)', '_core/NetworkAddressRetention.php',
        "'site' => 'siteID', 'via' => ['tblRecording', 'recordingID'], 'hold' => []]", "'site' => null,     'via' => null, 'hold' => []]", 'two-organisations'],
    ['P7 the reader\'s cap taken out (an organisation\'s own figure obeyed above the installation\'s)', '_core/RetentionPeriod.php',
        "        return SettingLimit::cappedForSite((int) \$text, static::KEY, static::DEFAULT_DAYS, self::HARD_MAX_DAYS, true);",
        "        return (int) \$text;", 'own-above-installation'],
    ['P8 the base class writing the activity log\'s key instead of the subclass\'s (late static binding lost)', '_core/RetentionPeriod.php',
        "        \$key = static::KEY;\n        // The installation's row keeps the built-in figure",
        "        \$key = \\Portal\\Core\\ActivityLogRetention::KEY;\n        // The installation's row keeps the built-in figure", 'own-key-only'],
    ['P9 the "never emptied" check dropped (rows without an address, or never-used keys, counted)', '_apps/cron/_retention-sweep.php',
        "'` IS NOT NULL AND `t`.`'", "'` <=> `t`.`' . \$place['column'] . '` AND `t`.`'", 'two-organisations'],
    // 🔒 Round 3: the clear-out must change NOTHING but the address.
    ['P10 a prayer request\'s re-stamped date no longer held (the database stamps it with the night of the clear-out)', '_core/NetworkAddressRetention.php',
        "'via' => null, 'hold' => ['updatedAt']]", "'via' => null, 'hold' => []]", 'two-organisations'],
    ['P11 a trusted device\'s "last seen" no longer held: the hold list no longer names every re-stamped column', '_core/NetworkAddressRetention.php',
        "'via' => null, 'hold' => ['lastSeenAt']]", "'via' => null, 'hold' => []]", 'holds-every-on-update-column'],
    ['P12 the hold loop taken out of the statement', '_apps/cron/_retention-sweep.php',
        "        foreach (\$place['hold'] as \$held) {", "        foreach ([] as \$held) {", 'two-organisations'],
    ['P13 the statement also emptying another column (a chat message\'s flag reason)', '_apps/cron/_retention-sweep.php',
        "        \$set = '`t`.`' . \$place['column'] . '` = NULL';",
        "        \$set = '`t`.`' . \$place['column'] . '` = NULL' . (\$place['table'] === 'tblLiveChatMessages' ? ', `t`.`flagReason` = NULL' : '');", 'two-organisations'],
    ['P14 a change history put back on the clear-out\'s list (D-187)', '_core/NetworkAddressRetention.php',
        "        // 🚫 NOT tblAuditTrail.ipAddress", "        ['table' => 'tblAuditTrail', 'column' => 'ipAddress', 'dated' => 'createdAt', 'site' => 'siteID', 'via' => null, 'hold' => []],\n        // 🚫 NOT tblAuditTrail.ipAddress", 'two-organisations'],
];
foreach ($naPlants as [$label, $file, $from, $to, $scenario]) {
    [$applied, $stillOk] = $naPlant($file, $from, $to, $scenario);
    $naCheck($label . ': applied, and scenario "' . $scenario . '" goes red', $applied === true && $stillOk === false,
        'applied: ' . var_export($applied, true) . ', scenario still passed: ' . var_export($stillOk, true));
}

$naCheck('P0 with every plant gone again, the first scenario is green once more', $naRun('two-organisations', $naWeb)['ok'] === true);
$naCheck('the run made the number of checks it should (' . NA_EXPECTED_CHECKS . ')', $naPass + $naFail + 1 === NA_EXPECTED_CHECKS,
    'made ' . ($naPass + $naFail + 1));

echo "\n" . $naPass . ' passed, ' . $naFail . ' failed, ' . ($naPass + $naFail) . " checks.\n";
if ($naFail > 0) {
    echo "FAIL — see above.\n";
    exit(1);
}
echo 'PASS — all ' . ($naPass + $naFail) . " checks passed.\n";
exit(0);
