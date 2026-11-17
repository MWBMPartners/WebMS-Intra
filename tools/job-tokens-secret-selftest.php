<?php
// Path: tools/job-tokens-secret-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Self-test: all eleven job tokens are secret, and the header reader (#1154, #1153)
 * -----------------------------------------------------------------------------
 * NO DATABASE, NO SERVER. Reads the real install script, the real migration
 * 306 and the real classes, and checks:
 *   A  every seeded `*.cron_token` row (and `maintenance.cronToken`) in
 *      full_schema.sql is marked secret, `reminders.cron_token` and
 *      `feeds.cron_token` (#1154) included;
 *   B  migration 306 marks exactly those two, only where the mark is 0, and
 *      is recorded in the install script;
 *   C  a marked row still holding PLAIN text is used exactly as stored by
 *      SecretSettings::forReader() - which is why existing tokens keep
 *      working until somebody saves them again;
 *   D  the two cron pages read their token through Settings::get() (the one
 *      place that applies SecretSettings), never from the raw row;
 *   E  ApiAuth::authorizationHeader() (#1153) reads HTTP_AUTHORIZATION, then
 *      REDIRECT_HTTP_AUTHORIZATION, and answers '' for neither or a non-string.
 * What it cannot do: run the cron pages against a database, or a real Apache
 * (tools/htaccess-apache-selftest.php is the Apache test).
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1154
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/web/_core/SecretSettings.php';
require_once $root . '/web/_core/ApiAuth.php';

$pass = 0;
$fail = 0;
$check = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    if ($ok === true) {
        $pass++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label\n";
    }
};

// A -------------------------------------------------------------------------
$schema = (string) file_get_contents($root . '/web/_sql/full_schema.sql');
preg_match_all("/\\(NULL,\\s*'([A-Za-z_.]*(?:cron_token|cronToken))',\\s*'[^']*',\\s*'[^']*',\\s*([01])\\)/", $schema, $m, PREG_SET_ORDER);
$marks = [];
foreach ($m as $row) {
    $marks[$row[1]][] = $row[2];
}
$check('A1 at least eleven job-token seeds found (' . count($marks) . ')', count($marks) >= 11);
foreach (['reminders.cron_token', 'feeds.cron_token'] as $name) {
    $check("A2 $name is seeded, and only ever with the secret mark", isset($marks[$name]) && array_unique($marks[$name]) === ['1']);
}
foreach ($marks as $name => $flags) {
    $check("A3 $name is secret in every seed", array_unique($flags) === ['1']);
}

// B -------------------------------------------------------------------------
$mig = (string) file_get_contents($root . '/web/_sql/306_two_job_tokens_secret.sql');
$check('B1 migration 306 sets the mark only where it is 0', str_contains($mig, 'SET `isSensitive` = 1') && str_contains($mig, 'WHERE `isSensitive` = 0'));
$check('B2 migration 306 names exactly the two tokens', str_contains($mig, "IN ('reminders.cron_token', 'feeds.cron_token')"));
$check('B3 migration 306 changes no value and inserts no setting', preg_match('/settingValue\s*=|INSERT INTO `tblSettings`/', $mig) === 0);
$check('B4 the install script records migration 306', str_contains($schema, "('306_two_job_tokens_secret.sql')"));

// C -------------------------------------------------------------------------
$check('C1 a marked row holding plain text is used as stored', \Portal\Core\SecretSettings::forReader(1, 'plain-token-value') === 'plain-token-value');
$check('C2 an empty marked row stays empty', \Portal\Core\SecretSettings::forReader(1, '') === '');

// D -------------------------------------------------------------------------
$pages = [
    'web/_apps/cron/event-reminders.php' => 'reminders.cron_token',
    'web/_apps/cron/import-feeds.php'    => 'feeds.cron_token',
];
foreach ($pages as $file => $key) {
    $src = (string) file_get_contents($root . '/' . $file);
    $check("D $file reads $key through Settings::get()", str_contains($src, "Settings::get('$key'"));
}

// E -------------------------------------------------------------------------
$keep = $_SERVER;
unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
$h = \Portal\Core\ApiAuth::class;
$check('E1 no header anywhere gives an empty answer', function_exists('getallheaders') === true || $h::authorizationHeader() === '');
$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer wbms_redirect';
$check('E2 the redirected copy is read', $h::authorizationHeader() === 'Bearer wbms_redirect');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wbms_direct';
$check('E3 the direct copy wins', $h::authorizationHeader() === 'Bearer wbms_direct');
$_SERVER['HTTP_AUTHORIZATION'] = '';
$check('E4 an empty direct copy falls back to the redirected one', $h::authorizationHeader() === 'Bearer wbms_redirect');
$_SERVER['HTTP_AUTHORIZATION'] = ['x'];
$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 7;
$check('E5 a non-text value is ignored', is_string($h::authorizationHeader()) === true && $h::authorizationHeader() !== 'x');
$_SERVER = $keep;

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
