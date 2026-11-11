<?php
// Path: tools/install-wide-prefill-selftest.php
/**
 * -----------------------------------------------------------------------------
 * An installation-wide form, saved untouched, changes nothing — self-test 🏢 (#943)
 * -----------------------------------------------------------------------------
 * WHY THIS FILE EXISTS
 * -----------------------------------------------------------------------------
 * Thirteen admin pages SAVE their settings installation-wide (`siteID IS NULL`)
 * and used to FILL their boxes from the merged settings, where the open
 * organisation's own row has already won (#674's shape, listed by
 * `check_install_wide_prefill.py` and tracked as #943). So a global
 * administrator who opened one inside an organisation holding its own value saw
 * THAT value, pressed Save without touching anything, and made it every
 * organisation's. It needed no mistake by anybody, and it crosses the boundary
 * between two parties: the installation's administrator may be a hosting
 * provider and the organisations its customers (#668).
 *
 * The audit check can only read source, and its own header says so: a page that
 * reads the installation layer for ONE field and the merged array for another
 * passes it. Whether the page RUNS correctly is behaviour, so this drives it
 * (D-065): for EACH page,
 *
 *   1. organisation A is given its own row, different from the installation's,
 *      for every key the form shows;
 *   2. a global administrator opens the page inside A;
 *   3. the form is submitted EXACTLY as the browser would send it as drawn
 *      (the fields are read out of the parsed page, never typed here);
 *   4. the installation's rows and A's rows must both be unchanged.
 *
 * Section S of `tools/transcription-pages-selftest.php` does the same for the one
 * page that was fixed first; this covers the other thirteen.
 *
 * WHY EACH PAGE HAS A "PROBE" (the check that keeps step 4 from being vacuous)
 * -----------------------------------------------------------------------------
 * "Nothing changed" is also what a save that never ran looks like: a refused
 * form token, a validation refusal, a page that drew no form. So after the
 * untouched save, the same form is submitted again with ONE field changed, and
 * that change must reach the installation's row. A page whose save path is dead
 * goes red there instead of passing step 4 for the wrong reason.
 *
 * WHAT THIS CANNOT SHOW, said plainly
 * -----------------------------------------------------------------------------
 *   - It reads the form the way a browser would SUBMIT it, from the parsed page.
 *     It runs no JavaScript, so a page whose script rewrote a field before
 *     sending would not be imitated. (The captcha priority list's drag-and-drop
 *     writes its hidden field only when a row is MOVED, so an untouched submit
 *     sends what the page drew — which is what is posted here.)
 *   - It does not prove the pages' "Test connection" buttons do anything; they
 *     reach outside services and are deliberately never pressed.
 *   - Secrets are seeded at BOTH levels (the installation's and A's own). That
 *     a blank secret box keeps both is checked (steps 4 and 5 cover those rows),
 *     and so is that neither secret reaches the page anywhere (check 10) and
 *     that every password box is drawn empty (check 11). What a page does with
 *     an organisation's secret beyond not showing it is not.
 *   - Check 09 judges the NOTICE only: the rest of the page legitimately shows
 *     the installation's values (in its boxes) and A's (in the "in use here"
 *     cards), so "no seeded value anywhere" would be untrue of a correct page.
 *     It matches a value as a whole token, so a value too ordinary to tell
 *     apart from the notice's own words would not be seen; none of the seeded
 *     values is such a word, which is why they were chosen as they were.
 *   - One worker, one request at a time (the built-in server), so nothing here
 *     says anything about two administrators saving at once.
 *
 * Needs: a MySQL/MariaDB database whose name starts with `selftest_`, already
 * loaded with `web/_sql/full_schema.sql`; PHP 8.4+ (for `Dom\HTMLDocument`);
 * curl and proc_open.
 *
 *     SELFTEST_DB_NAME=selftest_installwide SELFTEST_DB_PORT=… \
 *     SELFTEST_WEB_PORT=9943 php tools/install-wide-prefill-selftest.php
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/943
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/674
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes and deletes rows");
}
// sodium: to read what an encrypted row MEANS (see meaning() below).
foreach (['curl_init', 'proc_open', 'sodium_crypto_secretbox_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this PHP has no {$needed}()");
    }
}
// 🧪 The form is read the way a browser reads it, with the engine a browser uses.
//    Required, not skipped: a regular expression gets `selected` and `checked`
//    wrong on attribute order, and a check that quietly does not run is believed.
if (class_exists('Dom\HTMLDocument') === false) {
    refuse('this PHP has no Dom\HTMLDocument (PHP 8.4+), which this test needs to read a form as a browser would');
}

$repo      = (string) realpath(dirname(__DIR__));
$webSrc    = $repo . DIRECTORY_SEPARATOR . 'web';
$parentRaw = getenv('SELFTEST_WEB_DIR');
$parent    = realpath(($parentRaw === false || $parentRaw === '') ? sys_get_temp_dir() : (string) $parentRaw);
if ($parent === false || is_dir($parent) === false) {
    refuse('the scratch parent folder (SELFTEST_WEB_DIR) does not exist');
}
if (str_starts_with($repo . DIRECTORY_SEPARATOR, $parent . DIRECTORY_SEPARATOR) === true
    || str_starts_with($parent . DIRECTORY_SEPARATOR, $repo . DIRECTORY_SEPARATOR) === true) {
    refuse("the scratch parent folder '{$parent}' and the repository must not contain each other; choose another with SELFTEST_WEB_DIR");
}
$portRaw = getenv('SELFTEST_WEB_PORT');
$port    = ($portRaw === false || $portRaw === '') ? 9943 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
$db = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
$db->set_charset('utf8mb4');

$pass = 0;
$fail = 0;

/** ✅ Report one check. `$detail` is shown only on failure. */
function ok(string $label, bool $passed, string $detail = ''): void
{
    global $pass, $fail;
    if ($passed === true) {
        $pass++;
        echo 'PASS — ' . $label . "\n";

        return;
    }
    $fail++;
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . mb_substr($detail, 0, 1200)) . "\n";
}

function head(string $text): void
{
    echo "\n=== " . $text . " ===\n";
}

/** @param list<mixed> $params @return list<array<string,mixed>> */
function q(string $sql, string $types = '', array $params = []): array
{
    global $db;
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/** @param list<mixed> $params */
function one(string $sql, string $types = '', array $params = []): ?array
{
    return q($sql, $types, $params)[0] ?? null;
}

// =============================================================================
// 🗺️ The made-up world
// =============================================================================
const ORG         = 943001;   // organisation A: holds its own value for every key
const GLOBALADMIN = 943011;   // a GLOBAL administrator (tblUsers.isRootAdmin), a member of A
const ZONE        = 'Europe/London';

/**
 * 🌐 The first part of every address this run asks for. With no organisation in
 * the address `Site::preDetect()` would fall back to organisation 1, and the page
 * would merge SOMEBODY ELSE'S rows — the whole fault would be invisible. So the
 * installation is switched to telling organisations apart by the first part of
 * the address (recorded and put back by the clean-up).
 */
const ADDR = '/selftest-pf';

/**
 * 📋 THE PAGES. For each: where it is, how to find its form, every key the form
 * shows (with the installation's value, A's own value, and what the browser
 * would submit for that control as drawn from the INSTALLATION's value — null
 * meaning "not submitted", which is an unticked box), the secrets it must leave
 * alone, and one probe change that a working save must write.
 *
 * Every installation value is written in the shape its saver writes back (for
 * example `0.250` for a sample rate, because the saver formats it `%.3f`), so an
 * untouched save that works writes back exactly what was seeded.
 *
 * `org` null means "no row for A": used where a different value for A would make
 * the saver REFUSE the old faulty prefill for an unrelated reason (the hymn
 * lookup validates its address over the network only when switched on), which
 * would hide the fault rather than show it.
 *
 * @var array<string,array{path:string,match:array{0:string,1?:string},seed:list<array{0:string,1:string,2:string,3:?string,4:?string}>,secrets:list<string>,probe:array{set:array<string,string>,key:string,expect:string},appSwitch?:string}>
 */
$pages = [
    'ai-assist' => [
        'path'  => '/admin/ai-assist',
        'match' => ['monthCap'],
        'seed'  => [
            // key                          control         installation             A's own                  submitted as drawn
            ['ai_assist.enabled',           'enabled',      '0',                     '1',                     null],
            ['ai_assist.provider',          'provider',     'openai',                'local',                 'openai'],
            ['ai_assist.monthCapPence',     'monthCap',     '1234',                  '99900',                 '12.34'],
            ['ai_assist.userDailyCap',      'userDailyCap', '7',                     '150',                   '7'],
            ['ai_assist.audience',          'audience',     'inst-audience',         'org-audience',          'inst-audience'],
            ['ai_assist.anthropic.model',   'anth_model',   'inst-anth',             'org-anth',              'inst-anth'],
            ['ai_assist.openai.model',      'oa_model',     'inst-oa',               'org-oa',                'inst-oa'],
            ['ai_assist.local.baseUrl',     'local_base',   'http://inst.invalid:1', 'http://org.invalid:2',  'http://inst.invalid:1'],
            ['ai_assist.local.model',       'local_model',  'inst-local',            'org-local',             'inst-local'],
        ],
        'secrets' => ['ai_assist.anthropic.apiKey', 'ai_assist.openai.apiKey'],
        'appSwitch' => 'ai_assist.enabled',   // see the note on zoom
        'probe'   => ['set' => ['audience' => 'probe-audience'], 'key' => 'ai_assist.audience', 'expect' => 'probe-audience'],
    ],
    'captcha-keys' => [
        'path'  => '/admin/captcha',
        'match' => ['turnstile_site'],
        'seed'  => [
            ['auth.turnstile.siteKey',      'turnstile_site',         'inst-ts-site', 'org-ts-site', 'inst-ts-site'],
            ['auth.recaptcha.siteKey',      'recaptcha_site',         'inst-rc-site', 'org-rc-site', 'inst-rc-site'],
            ['auth.recaptcha.version',      'recaptcha_version',      'v3',           'v2',          'v3'],
            ['auth.recaptcha.v3.action',    'recaptcha_v3_action',    'instaction',   'orgaction',   'instaction'],
            ['auth.recaptcha.v3.threshold', 'recaptcha_v3_threshold', '0.7',          '0.3',         '0.7'],
            ['auth.hcaptcha.siteKey',       'hcaptcha_site',          'inst-hc-site', 'org-hc-site', 'inst-hc-site'],
        ],
        'secrets' => ['auth.turnstile.secretKey', 'auth.recaptcha.secretKey', 'auth.hcaptcha.secretKey'],
        'probe'   => ['set' => ['recaptcha_v3_action' => 'probeaction'], 'key' => 'auth.recaptcha.v3.action', 'expect' => 'probeaction'],
    ],
    'captcha-priority' => [
        'path'  => '/admin/captcha',
        'match' => ['priority'],
        'seed'  => [
            ['auth.captcha.priority', 'priority', 'hcaptcha,recaptcha,turnstile', 'recaptcha,turnstile,hcaptcha', 'hcaptcha,recaptcha,turnstile'],
        ],
        'secrets' => [],
        'probe'   => ['set' => ['priority' => 'turnstile,hcaptcha,recaptcha'], 'key' => 'auth.captcha.priority', 'expect' => 'turnstile,hcaptcha,recaptcha'],
    ],
    'hymns-remote' => [
        'path'  => '/admin/hymns',
        'match' => ['cacheTtl'],
        'seed'  => [
            ['hymns.remote.enabled',  'enabled',  'false',                            null,                              null],
            ['hymns.remote.host',     'host',     'inst.example.invalid',             'org.example.invalid',             'inst.example.invalid'],
            ['hymns.remote.baseUrl',  'baseUrl',  'https://inst.example.invalid/api', 'https://org.example.invalid/api', 'https://inst.example.invalid/api'],
            ['hymns.remote.cacheTtl', 'cacheTtl', '7200',                             '3600',                            '7200'],
        ],
        'secrets' => ['hymns.remote.apiKey'],
        'probe'   => ['set' => ['cacheTtl' => '4000'], 'key' => 'hymns.remote.cacheTtl', 'expect' => '4000'],
    ],
    'hymns-public-share' => [
        'path'  => '/admin/hymns',
        'match' => ['action', 'public_share_toggle'],
        'seed'  => [
            ['service_plans.public_share.enabled', 'enabled', 'false', 'true', null],
        ],
        'secrets' => [],
        'probe'   => ['set' => ['enabled' => '1'], 'key' => 'service_plans.public_share.enabled', 'expect' => 'true'],
    ],
    'cloudflare-stream' => [
        'path'  => '/admin/integrations/cloudflare-stream',
        'match' => ['customerCode'],
        'seed'  => [
            ['cfstream.enabled',                  'enabled',                  'false',                            'true',                             null],
            ['cfstream.accountID',                'accountID',                'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
            ['cfstream.customerCode',             'customerCode',             'instcode',                         'orgcode',                          'instcode'],
            ['cfstream.signingKeyID',             'signingKeyID',             'inst-kid',                         'org-kid',                          'inst-kid'],
            ['cfstream.tokenTtlSeconds',          'tokenTtlSeconds',          '7200',                             '3600',                             '7200'],
            ['cfstream.maxUploadDurationSeconds', 'maxUploadDurationSeconds', '1800',                             '900',                              '1800'],
            ['cfstream.uploadMintPerHour',        'uploadMintPerHour',        '15',                               '30',                               '15'],
            ['cfstream.defaultRequireSignedUrls', 'defaultRequireSignedUrls', 'false',                            'true',                             null],
            ['cfstream.allowedOrigins',           'allowedOrigins',           'inst.example.invalid',             'org.example.invalid',              'inst.example.invalid'],
        ],
        'secrets' => ['cfstream.apiToken', 'cfstream.signingKeyPem'],
        'probe'   => ['set' => ['customerCode' => 'probecode'], 'key' => 'cfstream.customerCode', 'expect' => 'probecode'],
    ],
    'geocoding' => [
        'path'  => '/admin/integrations/geocoding',
        'match' => ['googleApiKey'],
        'seed'  => [
            ['geo.autoGeocode', 'autoGeocode', 'false', 'true', null],
        ],
        'secrets' => ['geo.google.apiKey'],
        'probe'   => ['set' => ['autoGeocode' => '1'], 'key' => 'geo.autoGeocode', 'expect' => 'true'],
    ],
    'monitoring' => [
        'path'  => '/admin/integrations/monitoring',
        'match' => ['sampleRate'],
        'seed'  => [
            ['monitoring.enabled',     'enabled',     '0',        '1',       null],
            ['monitoring.environment', 'environment', 'inst-env', 'org-env', 'inst-env'],
            ['monitoring.sampleRate',  'sampleRate',  '0.250',    '0.750',   '0.250'],
        ],
        'secrets' => ['monitoring.sentryDsn'],
        'probe'   => ['set' => ['environment' => 'probe-env'], 'key' => 'monitoring.environment', 'expect' => 'probe-env'],
    ],
    'integrations-ms365-mailbox' => [
        'path'  => '/admin/integrations',
        'match' => ['sharedMailbox'],
        'seed'  => [
            ['mail.ms365.sharedMailbox',     'sharedMailbox',     'inst@example.invalid', 'org@example.invalid', 'inst@example.invalid'],
            ['mail.ms365.sharedMailboxName', 'sharedMailboxName', 'Inst Office',          'Org Office',          'Inst Office'],
            ['mail.ms365.saveToSentItems',   'saveToSentItems',   'false',                'true',                null],
            ['mail.fallbackProvider',        'fallbackProvider',  '',                     'google',              ''],
        ],
        'secrets' => [],
        'probe'   => ['set' => ['sharedMailboxName' => 'Probe Office'], 'key' => 'mail.ms365.sharedMailboxName', 'expect' => 'Probe Office'],
    ],
    'push' => [
        'path'  => '/admin/integrations/push',
        'match' => ['ttlGolive'],
        'seed'  => [
            ['push.enabled',               'enabled',               'false',                      'true',                      null],
            ['push.contact',               'contact',               'mailto:inst@example.invalid', 'mailto:org@example.invalid', 'mailto:inst@example.invalid'],
            ['push.ttl.golive',            'ttlGolive',             '600',                        '1200',                      '600'],
            ['push.ttl.reminder',          'ttlReminder',           '1800',                       '7200',                      '1800'],
            ['push.golive.auto',           'golive_auto',           'false',                      'true',                      null],
            ['push.reminders.broadcast',   'reminders_broadcast',   'false',                      'true',                      null],
            ['push.endpointHostAllowlist', 'endpointHostAllowlist', 'inst.example.invalid',       'org.example.invalid',       'inst.example.invalid'],
        ],
        'secrets' => ['push.vapidPrivateKey'],
        'probe'   => ['set' => ['contact' => 'mailto:probe@example.invalid'], 'key' => 'push.contact', 'expect' => 'mailto:probe@example.invalid'],
    ],
    'what3words' => [
        'path'  => '/admin/integrations/what3words',
        'match' => ['apiKey'],
        'seed'  => [
            ['w3w.enabled', 'enabled', 'false', 'true', null],
        ],
        'secrets' => ['w3w.apiKey'],
        'probe'   => ['set' => ['enabled' => '1'], 'key' => 'w3w.enabled', 'expect' => 'true'],
    ],
    'zoom' => [
        'path'  => '/admin/integrations/zoom',
        'match' => ['clientID'],
        'seed'  => [
            ['zoom.clientID', 'clientID', 'inst-client', 'org-client', 'inst-client'],
            ['zoom.mode',     'mode',     'user',        'org',        'user'],
            ['zoom.enabled',  'enabled',  '0',           '1',          null],
        ],
        'secrets' => ['zoom.clientSecret', 'zoom.webhookSecret'],
        // 🔌 This switch is also the APP's switch: with A's own "on" row removed the
        //    installation's "off" closes the page (403). Check 08 makes the two agree
        //    instead of deleting A's row.
        'appSwitch' => 'zoom.enabled',
        'probe'   => ['set' => ['clientID' => 'probe-client'], 'key' => 'zoom.clientID', 'expect' => 'probe-client'],
    ],
    'sms' => [
        'path'  => '/admin/sms',
        'match' => ['dailyCap'],
        'seed'  => [
            ['sms.enabled',       'enabled',    '0',             '1',             null],
            ['sms.provider',      'provider',   'messagebird',   'aws',           'messagebird'],
            ['sms.dailyCap',      'dailyCap',   '42',            '500',           '42'],
            ['sms.fromNumber',    'fromNumber', '+440000000001', '+440000000002', '+440000000001'],
            ['sms.twilio.sid',    'twilio_sid', 'inst-sid',      'org-sid',       'inst-sid'],
            ['sms.aws.accessKey', 'aws_key',    'inst-ak',       'org-ak',        'inst-ak'],
            ['sms.aws.region',    'aws_region', 'eu-west-2',     'us-east-1',     'eu-west-2'],
        ],
        'secrets' => ['sms.twilio.token', 'sms.messagebird.apiKey', 'sms.aws.secret'],
        'appSwitch' => 'sms.enabled',   // see the note on zoom
        'probe'   => ['set' => ['dailyCap' => '43'], 'key' => 'sms.dailyCap', 'expect' => '43'],
    ],
    'backup-schedule' => [
        'path'  => '/admin/maintenance/backup',
        'match' => ['action', 'save_schedule'],
        'seed'  => [
            ['backup.schedule.frequency', 'frequency', 'weekly', 'monthly', 'weekly'],
            ['backup.schedule.keep',      'keep',      '9',      '30',      '9'],
        ],
        'secrets' => [],
        'probe'   => ['set' => ['keep' => '11'], 'key' => 'backup.schedule.keep', 'expect' => '11'],
    ],
    'qr' => [
        'path'  => '/admin/settings/qr',
        'match' => ['endpoint'],
        'seed'  => [
            ['portal.qr.provider',              'provider', 'cuercode',                      'local',                        'cuercode'],
            ['portal.qr.cuercode.api_endpoint', 'endpoint', 'https://inst.example.invalid/v1', 'https://org.example.invalid/v1', 'https://inst.example.invalid/v1'],
        ],
        'secrets' => ['portal.qr.cuercode.api_key'],
        'probe'   => ['set' => ['endpoint' => 'https://probe.example.invalid/v1'], 'key' => 'portal.qr.cuercode.api_endpoint', 'expect' => 'https://probe.example.invalid/v1'],
    ],
];

/** Every key this run may touch installation-wide, so the clean-up can put each back. */
function keysTouched(array $pages): array
{
    $keys = ['multisite.enabled', 'multisite.detectionMode'];
    foreach ($pages as $page) {
        foreach ($page['seed'] as $row) {
            $keys[] = $row[0];
        }
        foreach ($page['secrets'] as $key) {
            $keys[] = $key;
        }
        $keys[] = $page['probe']['key'];
    }

    return array_values(array_unique($keys));
}

$encKeyContents = '';   // this run's enc.key, set when the scratch copy is made

$state = [
    'proc'    => null,
    'child'   => '',
    'token'   => bin2hex(random_bytes(16)),
    'started' => false,
    'cleaned' => false,
    // 🗄️ Every installation-wide row this run may touch, AS FOUND (null = no row),
    //    so the clean-up puts each back exactly — "there was no row" included.
    //    These rows belong to the whole installation and a throwaway database is
    //    still somebody's.
    'found'   => [],
];

function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

/** Everything this run put in the database, taken out again. */
function removeWorld(): void
{
    global $db;
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    q('DELETE FROM `tblLocalAccounts` WHERE `userID` BETWEEN 943000 AND 943099');
    q('DELETE FROM `tblActivityLogs` WHERE `userID` BETWEEN 943000 AND 943099 OR `siteID` = ?', 'i', [ORG]);
    q('DELETE FROM `tblErrors` WHERE `userID` BETWEEN 943000 AND 943099 OR `siteID` = ?', 'i', [ORG]);
    q('DELETE FROM `tblUserSites` WHERE `siteID` = ? OR `userID` BETWEEN 943000 AND 943099', 'i', [ORG]);
    q('DELETE FROM `tblUsers` WHERE `userID` BETWEEN 943000 AND 943099');
    q('DELETE FROM `tblSettings` WHERE `siteID` = ?', 'i', [ORG]);
    q('DELETE FROM `tblSites` WHERE `siteID` = ?', 'i', [ORG]);
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
}

/** Put back everything this run changed, ONCE. Also a shutdown function. */
function cleanup(): void
{
    global $state;
    if ($state['started'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        $until = microtime(true) + 5;
        while (proc_get_status($state['proc'])['running'] === true && microtime(true) < $until) {
            usleep(50000);
        }
        if (proc_get_status($state['proc'])['running'] === true) {
            proc_terminate($state['proc'], 9);
        }
        proc_close($state['proc']);
        $state['proc'] = null;
    }
    try {
        foreach ($state['found'] as $key => $before) {
            if ($before === null) {
                q('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [(string) $key]);
                continue;
            }
            q(
                'UPDATE `tblSettings` SET `settingValue` = ?, `defaultValue` = ?, `isSensitive` = ? WHERE `settingID` = ?',
                'ssii',
                [$before['settingValue'], $before['defaultValue'], (int) $before['isSensitive'], (int) $before['settingID']]
            );
        }
        removeWorld();
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.install-wide-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * One real HTTP request to the server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else and is
 * returned as status -1, which no check expects.
 *
 * `$body` is the request body already encoded, because a browser may send one
 * name twice and an array cannot hold that.
 *
 * @return array{status:int, location:string, body:string}
 */
function req(string $jar, string $method, string $path, ?string $body = null): array
{
    global $port, $state;
    $handle = curl_init('http://127.0.0.1:' . $port . ADDR . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => ['Expect:'], CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body ?? '');
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Install-Wide-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start'];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
    ];
}

/** A list of [name, value] pairs encoded as a browser encodes a form. */
function encodePairs(array $pairs): string
{
    return implode('&', array_map(
        static fn (array $p): string => rawurlencode($p[0]) . '=' . rawurlencode($p[1]),
        $pairs
    ));
}

/**
 * 📨 The form on a page whose controls include `$match` (a name, or a name and a
 * value), read the way a browser SUBMITS it as drawn: enabled controls only; a
 * box or radio only when ticked (and as "on" when it has no value); a list's
 * selected option, or its FIRST when none is selected — which is exactly what
 * makes a list with nothing selected dangerous, and why it is imitated rather
 * than skipped; a text area's text.
 *
 * @param array{0:string,1?:string} $match
 *
 * @return array{action:?string, pairs:list<array{0:string,1:string}>}|null
 */
function formOnPage(string $html, array $match): ?array
{
    $doc = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    foreach ($doc->getElementsByTagName('form') as $form) {
        $pairs = [];
        $found = false;
        foreach ($form->querySelectorAll('input, select, textarea') as $control) {
            $name = (string) $control->getAttribute('name');
            if ($name === '' || $control->hasAttribute('disabled') === true) {
                continue;
            }
            $tag = strtolower($control->localName);
            if ($tag === 'input') {
                $type = strtolower((string) ($control->getAttribute('type') ?? 'text'));
                if (in_array($type, ['submit', 'button', 'reset', 'image', 'file'], true) === true) {
                    continue;
                }
                $value = (string) ($control->getAttribute('value') ?? (in_array($type, ['checkbox', 'radio'], true) === true ? 'on' : ''));
                if (in_array($type, ['checkbox', 'radio'], true) === true && $control->hasAttribute('checked') === false) {
                    if ($name === $match[0] && isset($match[1]) === false) {
                        $found = true;   // the control is in this form even if unticked
                    }
                    continue;
                }
                $pairs[] = [$name, $value];
            } elseif ($tag === 'select') {
                $chosen = [];
                $first  = null;
                foreach ($control->getElementsByTagName('option') as $option) {
                    $optionValue = $option->getAttribute('value') ?? (string) $option->textContent;
                    $first ??= (string) $optionValue;
                    if ($option->hasAttribute('selected') === true) {
                        $chosen[] = (string) $optionValue;
                    }
                }
                if ($chosen === [] && $first !== null && $control->hasAttribute('multiple') === false) {
                    $chosen = [$first];
                }
                foreach ($control->hasAttribute('multiple') === true ? $chosen : array_slice($chosen, -1) as $value) {
                    $pairs[] = [$name, $value];
                }
            } else {
                $pairs[] = [$name, (string) $control->textContent];
            }
            if ($name === $match[0] && (isset($match[1]) === false || end($pairs)[1] === $match[1])) {
                $found = true;
            }
        }
        if ($found === true) {
            return ['action' => $form->getAttribute('action'), 'pairs' => $pairs];
        }
    }

    return null;
}

/** The value a list of pairs holds for one name (the last, as PHP reads it), or null. */
function pairValue(array $pairs, string $name): ?string
{
    $value = null;
    foreach ($pairs as [$n, $v]) {
        if ($n === $name) {
            $value = $v;
        }
    }

    return $value;
}

/** The address a form posts to, as this run's req() wants it (without ADDR). */
function postPath(?string $action, string $pagePath): string
{
    if ($action === null || $action === '') {
        return $pagePath;
    }
    $path = (string) parse_url($action, PHP_URL_PATH);
    if (str_starts_with($path, ADDR . '/') === true) {
        return substr($path, strlen(ADDR));
    }

    return $path;
}

/**
 * 🔓 What a stored value MEANS. A saver may store a value encrypted (the bot-check
 * site keys are, and every secret), and encryption takes a fresh random nonce each
 * time, so the same meaning is a different text after every save. Comparing the
 * text would call an untouched save a change. So a row marked sensitive is opened
 * here exactly as `decrypt_setting()` in `web/_core/bootstrap.php` opens it — the
 * key is the SHA-256 of this run's own `enc.key` — and compared by what it says.
 * A row that cannot be opened reads as a distinct marker, never as '' (which would
 * match an empty value by accident).
 */
function meaning(?array $row): ?string
{
    global $encKeyContents;
    if ($row === null) {
        return null;
    }
    $stored = (string) $row['settingValue'];
    if ((int) $row['isSensitive'] !== 1 || $stored === '') {
        return $stored;
    }
    $bin = base64_decode($stored, true);
    if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return $stored;   // stored as plain text under the sensitive flag: it means itself
    }
    $opened = sodium_crypto_secretbox_open(
        substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        sodium_hex2bin(hash('sha256', $encKeyContents))
    );

    return $opened === false ? '(could not be opened: ' . $stored . ')' : $opened;
}

/** The installation-wide value of a key, by what it means (null = no row). */
function installValue(string $key): ?string
{
    return meaning(one('SELECT `settingValue`, `isSensitive` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key]));
}

/** Organisation A's own value of a key (null = no row). */
function orgValue(string $key): ?string
{
    return meaning(one('SELECT `settingValue`, `isSensitive` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?', 'si', [$key, ORG]));
}

/** Set the installation's own value of a key (inserting the row if there is none). */
function setInstall(string $key, string $value): void
{
    $row = one('SELECT `settingID` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key]);
    if ($row === null) {
        q(
            'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, ?, ?, ?, 0)',
            'sss',
            [$key, $value, $value]
        );

        return;
    }
    // isSensitive = 0: the seeded secrets here are plain test strings, and a
    // sensitive row holding plain text would be read as a scramble.
    q('UPDATE `tblSettings` SET `settingValue` = ?, `isSensitive` = 0 WHERE `settingID` = ?', 'si', [$value, (int) $row['settingID']]);
}

/** Give organisation A its own value for a key (replacing any it had), or take it away (null). */
function setOrg(string $key, ?string $value): void
{
    q('DELETE FROM `tblSettings` WHERE `siteID` = ? AND `settingKey` = ?', 'is', [ORG, $key]);
    if ($value === null) {
        return;
    }
    q(
        'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, ?, ?, 0)',
        'isss',
        [ORG, $key, $value, $value]
    );
}

/**
 * 🔑 The test secret seeded for one key at one level. Distinctive on purpose:
 * long, and unlike anything a page prints, so finding it means it leaked.
 */
function secretFor(string $whose, string $key): string
{
    return 'selftest-' . ($whose === 'installation' ? 'instsecret' : 'orgsecret') . '-' . $key;
}

/** The text of the install-wide notice on a page, or null when there is none. */
function noticeText(string $html): ?string
{
    $doc    = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $notice = $doc->querySelector('[data-install-wide-notice]');

    return $notice === null ? null : (string) $notice->textContent;
}

/**
 * Does `$text` hold `$value` as a whole token? Whole, so that a value such as
 * `org` is not found inside the notice's own word "organisation", and `1` not
 * inside `ms365`. The characters that count as "part of the same token" are
 * letters, digits and `_ . @ -`; anything else (a space, `=`, `:`, a bracket)
 * separates, so `sms.enabled=1` or `name (value)` is caught.
 */
function holdsValue(string $text, string $value): bool
{
    if ($value === '') {
        return false;
    }

    return preg_match('/(?<![A-Za-z0-9_.@-])' . preg_quote($value, '/') . '(?![A-Za-z0-9_.@-])/', $text) === 1;
}

/**
 * Every password box on a page drawn with a non-empty `value`, named. A
 * password box is filled only by the page that drew it, so a value here is a
 * secret handed to whoever opens the page (P12 put the installation's API key
 * into one and left every earlier check green).
 *
 * @return list<string>
 */
function filledPasswordBoxes(string $html): array
{
    $doc   = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $named = [];
    foreach ($doc->querySelectorAll('input') as $input) {
        if (strtolower((string) ($input->getAttribute('type') ?? '')) === 'password'
            && (string) ($input->getAttribute('value') ?? '') !== '') {
            $named[] = (string) ($input->getAttribute('name') ?? '(no name)');
        }
    }

    return $named;
}

// =============================================================================
// 🌍 Build the world
// =============================================================================
removeWorld();
foreach (keysTouched($pages) as $key) {
    $state['found'][$key] = one(
        'SELECT `settingID`, `settingValue`, `defaultValue`, `isSensitive` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL',
        's',
        [$key]
    );
}

q(
    "INSERT INTO `tblSites` (`siteID`, `siteKey`, `siteName`, `isActive`, `timezone`) VALUES (?, 'selftest-pf', 'Selftest prefill', 1, ?)",
    'is',
    [ORG, ZONE]
);
// 👤 ONE person: a GLOBAL administrator (tblUsers.isRootAdmin, the flag
//    App::isRootAdmin() reads), and a member of A. Every saver refuses anybody
//    else, so this is the only person whose untouched Save can do the harm.
q(
    'INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`) VALUES (?, ?, ?, 1, 0, 1)',
    'iss',
    [GLOBALADMIN, 'Gail Global', 'u943011.pf@selftest.invalid']
);
q(
    'INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, 0, 0)',
    'ii',
    [GLOBALADMIN, ORG]
);
setInstall('multisite.enabled', 'true');
setInstall('multisite.detectionMode', 'path');

$password = bin2hex(random_bytes(12)) . 'Aa1!';
q(
    'INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)',
    'iss',
    [GLOBALADMIN, 'selftest-pf-global', password_hash($password, PASSWORD_DEFAULT)]
);

// The scratch copy of web/, with a throwaway connection file and key.
$sep   = DIRECTORY_SEPARATOR;
$child = $parent . $sep . 'install-wide-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.install-wide-selftest-owner', $state['token']); // the marker FIRST
register_shutdown_function('cleanup');
$state['started'] = true;

mkdir($child . $sep . 'web', 0700);
$copied = 0;
$tree   = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($webSrc, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($tree as $path => $info) {
    $rel = substr((string) $path, strlen($webSrc) + 1);
    if (in_array(explode($sep, $rel)[0], ['_auth_keys', '_uploads', '_backups', '_libraries'], true) === true) {
        continue;
    }
    if ($info->isLink() === true) {
        refuse('web/ holds a link (' . $rel . '); refusing to copy it');
    }
    if ($info->isDir() === true) {
        mkdir($child . $sep . 'web' . $sep . $rel, 0700);
    } else {
        copy((string) $path, $child . $sep . 'web' . $sep . $rel);
        $copied++;
    }
}
if ($copied < 100) {
    refuse("the copy of web/ holds only {$copied} files");
}
foreach (['_auth_keys', '_uploads', '_backups'] as $folder) {
    mkdir($child . $sep . 'web' . $sep . $folder, 0700);
}
mkdir($child . $sep . 'sessions', 0700);

$creds = [
    'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'db_name' => $dbName,
    'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
];
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/install-wide-prefill-selftest.php for one run; deleted with its folder.\nreturn "
    . var_export($creds, true) . ";\n"
);
$encKeyContents = bin2hex(random_bytes(32));
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', $encKeyContents);

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/install-wide-prefill-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Install-Wide-Selftest: __TOKEN__');
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
// Required at the TOP LEVEL, never inside a function: the portal's start-up
// sets $mysqli and $SETTINGS as globals.
$target = $root . '/index.php';
$real   = ($uri === '/' || preg_match('#^/assets/?$#', $uri) === 1) ? false : realpath($root . $uri);
if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) === true && is_file($real) === true) {
    if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'php') { $target = $real; } else { readfile($real); return true; }
}
$_SERVER['SCRIPT_NAME']     = substr($target, strlen($root));
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME'] = $target;
chdir(dirname($target));
require $target;
return true;
ROUTER;
file_put_contents($child . $sep . 'router.php', str_replace('__TOKEN__', $state['token'], $router));

// The server: its command as a LIST (no shell in between), ONE process.
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc']     = proc_open(
    [
        PHP_BINARY,
        '-d', 'session.save_path=' . $child . $sep . 'sessions',
        '-d', 'error_log=' . $child . $sep . 'php-errors.log',
        '-d', 'log_errors=1',
        '-d', 'display_errors=0',
        '-S', '127.0.0.1:' . $port,
        '-t', $child . $sep . 'web' . $sep . 'public_html',
        $child . $sep . 'router.php',
    ],
    [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $child . $sep . 'server-output.log', 'a'],
        2 => ['file', $child . $sep . 'server-output.log', 'a'],
    ],
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false) {
    refuse('the web server could not be started');
}
$deadline = microtime(true) + 10;
while (portAnswers($port) === false && microtime(true) < $deadline) {
    usleep(100000);
}
if (portAnswers($port) === false) {
    refuse('the web server never answered on port ' . $port);
}

echo "An installation-wide form, saved untouched, changes nothing — self-test (#943)\n";
echo 'server process ' . (int) proc_get_status($state['proc'])['pid'] . ', port ' . $port
    . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v']
    . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

// 🔐 Signed in BEFORE any setting is seeded. Seeding a bot-check site key and
//    secret installation-wide switches the bot check ON for the sign-in form,
//    which this run cannot answer; the session outlives that.
$jar = $child . $sep . 'jar-global.txt';
if (preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', req($jar, 'GET', '/login')['body'], $m) !== 1) {
    refuse('the sign-in page drew no form token');
}
$signed = req($jar, 'POST', '/login', encodePairs([
    ['csrf_token', $m[1]], ['username', 'selftest-pf-global'], ['password', $password],
]));
if ($signed['status'] !== 302 && $signed['status'] !== 303) {
    refuse("could not sign the global administrator in (status {$signed['status']}); nothing below would mean anything");
}

// =============================================================================
// 🏢 Each page, one at a time
// =============================================================================
foreach ($pages as $label => $page) {
    head($label . ' — ' . $page['path']);

    // 1️⃣ The installation says one thing; organisation A says another.
    foreach ($page['seed'] as [$key, $control, $install, $org]) {
        setInstall($key, $install);
        setOrg($key, $org);
    }
    foreach ($page['secrets'] as $key) {
        setInstall($key, secretFor('installation', $key));
        setOrg($key, secretFor('organisation', $key));
    }
    $snapshot = static function () use ($page): array {
        $keys = array_merge(array_column($page['seed'], 0), $page['secrets']);
        $out  = [];
        foreach ($keys as $key) {
            $out['installation ' . $key] = installValue($key);
            $out['organisation ' . $key] = orgValue($key);
        }

        return $out;
    };
    $before = $snapshot();

    // 2️⃣ Opened by a global administrator INSIDE organisation A.
    $shown = req($jar, 'GET', $page['path']);
    ok(
        "{$label}: 01 the page answers a WHOLE page inside the organisation",
        $shown['status'] === 200 && str_contains($shown['body'], '</html>'),
        'status ' . $shown['status'] . '; a page that throws part-way still answers 200, so the closing tag is what shows it finished'
    );
    $form = formOnPage($shown['body'], $page['match']);
    ok(
        "{$label}: 02 the form is on the page",
        $form !== null,
        'no form holding a control named ' . implode('=', $page['match'])
    );
    if ($form === null) {
        continue;
    }

    // Every box shows the INSTALLATION's value, as the browser would send it.
    $wrongBoxes = [];
    foreach ($page['seed'] as [$key, $control, $install, $org, $submitted]) {
        $actual = pairValue($form['pairs'], $control);
        if ($actual !== $submitted) {
            $wrongBoxes[] = sprintf('%s (%s) would be sent as %s; the installation holds %s and the organisation %s',
                $control, $key, var_export($actual, true), var_export($install, true), var_export($org, true));
        }
    }
    ok(
        "{$label}: 03 every box shows the INSTALLATION's value, not organisation A's",
        $wrongBoxes === [],
        implode("\n        ", $wrongBoxes)
    );

    // The page SAYS this organisation is using something else, naming a key.
    $differing = array_values(array_filter($page['seed'], static fn (array $s): bool => $s[3] !== null));
    ok(
        "{$label}: 04 the page says this organisation has its own value, naming the setting",
        str_contains($shown['body'], 'data-install-wide-notice') === true
            && str_contains($shown['body'], '<code>' . $differing[0][0] . '</code>') === true,
        'hiding the difference is how the old fault stayed invisible; looked for the notice and ' . $differing[0][0]
    );

    // 🔒 The notice names settings and NEVER their values: a value here could be
    //    an API key, and the notice is drawn for every global administrator who
    //    opens the page anywhere. Every seeded value, the installation's and A's,
    //    must be absent from it (P11 planted the value beside each name and left
    //    every earlier check green).
    $notice  = noticeText($shown['body']) ?? '';
    $leaked  = [];
    foreach ($page['seed'] as [$key, $control, $install, $org]) {
        foreach (['installation' => $install, 'organisation' => $org] as $whose => $value) {
            if ($value !== null && holdsValue($notice, $value) === true) {
                $leaked[] = sprintf('%s value of %s (%s)', $whose, $key, var_export($value, true));
            }
        }
    }
    foreach ($page['secrets'] as $key) {
        foreach (['installation', 'organisation'] as $whose) {
            if (holdsValue($notice, secretFor($whose, $key)) === true) {
                $leaked[] = sprintf('%s secret %s', $whose, $key);
            }
        }
    }
    ok(
        "{$label}: 09 the notice shows setting NAMES only, none of their values",
        $notice !== '' && $leaked === [],
        $notice === '' ? 'no notice to judge (04 explains why)' : 'the notice holds ' . implode('; ', $leaked)
    );

    // 🔐 No secret, at either level, anywhere in what was sent — markup, a
    //    password box's `value=`, a script, an attribute. Searched in the raw
    //    answer AND with its HTML entities undone, so an escaped copy counts.
    $decoded = html_entity_decode($shown['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $exposed = [];
    foreach ($page['secrets'] as $key) {
        foreach (['installation', 'organisation'] as $whose) {
            $secret = secretFor($whose, $key);
            if (str_contains($shown['body'], $secret) === true || str_contains($decoded, $secret) === true) {
                $exposed[] = $whose . ' ' . $key;
            }
        }
    }
    ok(
        "{$label}: 10 no stored secret, the installation's or the organisation's, appears anywhere in the page",
        $exposed === [],
        'found in the page: ' . implode('; ', $exposed)
    );
    $filledBoxes = filledPasswordBoxes($shown['body']);
    ok(
        "{$label}: 11 every password box on the page is drawn EMPTY",
        $filledBoxes === [],
        'password boxes drawn with a value: ' . implode(', ', $filledBoxes)
    );

    // 3️⃣ Save, untouched.
    $saved = req($jar, 'POST', postPath($form['action'], $page['path']), encodePairs($form['pairs']));
    $after = $snapshot();
    $changed = [];
    foreach ($before as $name => $value) {
        if (str_starts_with($name, 'installation ') === true && $after[$name] !== $value) {
            $changed[] = sprintf('%s: %s became %s', $name, var_export($value, true), var_export($after[$name], true));
        }
    }
    ok(
        "{$label}: 05 Save pressed untouched leaves every installation-wide row as it was",
        $saved['status'] > 0 && $changed === [],
        'status ' . $saved['status'] . '; ' . implode('; ', $changed)
    );
    $orgChanged = [];
    foreach ($before as $name => $value) {
        if (str_starts_with($name, 'organisation ') === true && $after[$name] !== $value) {
            $orgChanged[] = sprintf('%s: %s became %s', $name, var_export($value, true), var_export($after[$name], true));
        }
    }
    ok(
        "{$label}: 06 and organisation A's own rows are untouched by it",
        $orgChanged === [],
        implode('; ', $orgChanged)
    );

    // 🧪 The probe: the SAME form, one field changed, must reach the installation's
    //    row. Without this, 05 would also pass on a save that never ran.
    $again = formOnPage(req($jar, 'GET', $page['path'])['body'], $page['match']);
    $probePairs = [];
    foreach ($again['pairs'] ?? [] as [$n, $v]) {
        if (array_key_exists($n, $page['probe']['set']) === false) {
            $probePairs[] = [$n, $v];
        }
    }
    foreach ($page['probe']['set'] as $n => $v) {
        $probePairs[] = [$n, $v];
    }
    $probed = req($jar, 'POST', postPath($again['action'] ?? null, $page['path']), encodePairs($probePairs));
    ok(
        "{$label}: 07 a field CHANGED on the same form does reach the installation's row (05 is not vacuous)",
        installValue($page['probe']['key']) === $page['probe']['expect'],
        'status ' . $probed['status'] . '; ' . $page['probe']['key'] . ' is '
            . var_export(installValue($page['probe']['key']), true) . ', expected ' . var_export($page['probe']['expect'], true)
    );

    // 🚫 04 would pass on a page that always shows the notice, so prove it does not.
    foreach ($page['seed'] as [$key, $control, $install, $org]) {
        if ($key === ($page['appSwitch'] ?? null)) {
            setInstall($key, (string) $org);   // agree, so the app stays open and nothing differs
            continue;
        }
        setOrg($key, null);
    }
    foreach ($page['secrets'] as $key) {
        setOrg($key, null);
    }
    $plain = req($jar, 'GET', $page['path']);
    ok(
        "{$label}: 08 with no organisation row the notice is GONE (04 is not vacuous)",
        $plain['status'] === 200 && str_contains($plain['body'], 'data-install-wide-notice') === false,
        'status ' . $plain['status'] . '; a notice that is always shown says nothing'
    );
}

// =============================================================================
head('Z. Nothing went wrong quietly');
// =============================================================================
$errorLog   = $child . $sep . 'php-errors.log';
$logged     = is_file($errorLog) === true ? (string) file_get_contents($errorLog) : '';
$realFaults = array_values(array_filter(
    preg_split('/\R/', $logged) ?: [],
    static fn (string $line): bool => $line !== ''
        && str_contains($line, 'Cannot modify header information') === false
));
ok(
    'Z01 no PHP error, warning or notice was logged by any page in this run',
    $realFaults === [],
    implode("\n        ", array_slice($realFaults, 0, 8))
);
$serverLog = $child . $sep . 'server-output.log';
$serverOut = is_file($serverLog) === true ? (string) file_get_contents($serverLog) : '';
ok(
    'Z02 and no page answered 500',
    preg_match('/ 500 /', $serverOut) !== 1,
    (string) (preg_match('/^.* 500 .*$/m', $serverOut, $m) === 1 ? $m[0] : '')
);
// The count of pages is pinned so a page deleted from the list above is noticed
// (#697: iterating a list cannot see an entry that is no longer in it).
ok(
    'Z03 all fifteen forms (on thirteen pages) were driven',
    count($pages) === 15,
    'the list holds ' . count($pages)
);

echo "\n" . str_repeat('-', 72) . "\n";
echo 'checks: ' . ($pass + $fail) . ', passed ' . $pass . ', failed ' . $fail . "\n";
if ($fail > 0) {
    echo "VERDICT: FAILED\n";
    exit(1);
}
echo "VERDICT: PASSED\n";
exit(0);
