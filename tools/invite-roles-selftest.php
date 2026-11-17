<?php
// Path: tools/invite-roles-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Invitations grant the role they offer, the privacy notice's receipt wording,
 * and the Admin home's links 🎟️🧪 (#539, #939, #606)
 * -----------------------------------------------------------------------------
 * REAL MySQL and PHP's built-in web server, driving the real pages.
 *
 * WHAT IT PROVES
 * --------------
 *  A. #539. An invitation for "volunteer" or "staff" used to be offered,
 *     stored and then ignored, so the invited person arrived holding nothing.
 *     Each of the four roles is accepted through the real invitation page:
 *     "user" holds no role; "volunteer" and "staff" hold exactly that role in
 *     the inviting organisation and in no other; "admin" is an administrator
 *     of that organisation only and holds no role row. An invitation written
 *     straight into the table (as one sent before this fix was) is honoured.
 *  B. A role deleted between sending and accepting refuses the WHOLE
 *     acceptance: no account, no membership, no role, no password, and the
 *     invitation stays usable (it works once the role is back).
 *  C. D-093. With "Set up the public website" pointed at "volunteer", an
 *     ordinary administrator's volunteer invitation is refused when SAVED, a
 *     global administrator's is stored; an invitation saved before the
 *     re-pointing is refused at ACCEPTANCE with nothing written and the
 *     refusal recorded; a global administrator's invitation is still honoured.
 *  D. The New invitation form offers volunteer and staff only where they can
 *     be handed out.
 *  E. #939 / D-167. The privacy notice, the help page and the giving-records
 *     page say "tax receipt" only when the organisation ticked that it issues
 *     them; the tick saves per organisation; a post without the marker changes
 *     nothing; the general settings editor refuses the key.
 *  F. #606. The Admin home links Upgrade, Organisation settings and E-mail to
 *     a global administrator and only the organisation's page to an ordinary
 *     administrator; the sign-in page carries the favicon link; the Release
 *     Notes page has one top-level heading.
 *  G. PLANTS. Each new guard is switched off in a COPY of the page and the
 *     matching check must go red; the copy is put back byte for byte.
 *
 * WHAT IT CANNOT PROVE
 * --------------------
 *  - Two people accepting at once (the harness runs one worker).
 *  - That a sentence is TRUE: that is a person's judgement at review.
 *  - It proves the lock is ASKED, not that it blocks: see
 *    tools/public-website-tick-selftest.php for the lock itself.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… [SELFTEST_DB_PASS=…] \
 *         [SELFTEST_WEB_PORT=9939] php tools/invite-roles-selftest.php
 *         The database must already hold web/_sql/full_schema.sql. Needs curl,
 *         proc_open and a mail setup that does not hang (mail is best-effort).
 * Exit:   0 only when every check ran and passed; 1 on a failure; 2 refused.
 *
 * It changes, and puts back: made-up people numbered 9396xx, invitations to
 * addresses ending @p2e.invalid, the installation's `site.url` row, the
 * website-setup capability choice, a second organisation, and one role it
 * deletes and restores. A shutdown function does that even after an early stop.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/539
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

const IR_GLOBAL = 939601; // global administrator
const IR_ORG    = 939602; // ordinary administrator of this organisation only

$sep    = DIRECTORY_SEPARATOR;
$repo   = dirname(__DIR__);
$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'child' => null];

function refuse(string $why): never
{
    echo "REFUSED: {$why}.\nA skipped test reads exactly like a passing one, so this refuses.\n";
    exit(2);
}

function check(string $name, bool $ok, string $detail = ''): bool
{
    global $passed, $failed;
    if ($ok === true) {
        $passed++;
        echo "  ✓ {$name}\n";

        return true;
    }
    $failed++;
    echo "  ✗ {$name}\n";
    if ($detail !== '') {
        echo '      ' . substr($detail, 0, 400) . "\n";
    }

    return false;
}

function head(string $text): void
{
    echo "\n{$text}\n";
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (preg_match('/^selftest_[A-Za-z0-9_]+$/', $dbName) !== 1) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}')");
}
foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("PHP's {$needed}() is missing");
    }
}
$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$db->set_charset('utf8mb4');
if ((int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetch_row()[0] < 100) {
    refuse('load web/_sql/full_schema.sql first');
}

/** @param list<mixed> $params @return list<array<string,mixed>> */
function rows(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $out    = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $out;
}

/** @param list<mixed> $params */
function run(mysqli $db, string $sql, string $types = '', array $params = []): int
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();

    return $n;
}

function one(mysqli $db, string $sql, string $types = '', array $params = []): int
{
    $r = rows($db, $sql, $types, $params);

    return $r === [] ? 0 : (int) array_values($r[0])[0];
}

spl_autoload_register(static function (string $class) use ($repo, $sep): void {
    if (str_starts_with($class, 'Portal\\Core\\') === false) {
        return;
    }
    $file = $repo . $sep . 'web' . $sep . '_core' . $sep . substr($class, strlen('Portal\\Core\\')) . '.php';
    if (is_file($file) === true) {
        require_once $file;
    }
});

// =============================================================================
// 🏗️ A copy of web/, so a planted fault never touches the repository
// =============================================================================
$child = sys_get_temp_dir() . $sep . 'invite-roles-' . bin2hex(random_bytes(6));
$state['child'] = $child;
register_shutdown_function(static function () use (&$state): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['child'] !== null && is_file($state['child'] . DIRECTORY_SEPARATOR . 'invite-roles.marker') === true) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($state['child'], FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            $entry->isDir() === true ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($state['child']);
    }
});
mkdir($child, 0700, true);
file_put_contents($child . $sep . 'invite-roles.marker', 'made by tools/invite-roles-selftest.php');
$copied = 0;
$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repo . $sep . 'web', FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($walk as $entry) {
    $relative = substr($entry->getPathname(), strlen($repo . $sep . 'web'));
    if (str_starts_with($relative, $sep . '_auth_keys') === true) {
        continue;
    }
    $to = $child . $sep . 'web' . $relative;
    if ($entry->isDir() === true) {
        @mkdir($to, 0700, true);
        continue;
    }
    copy($entry->getPathname(), $to);
    $copied++;
}
if ($copied < 100) {
    refuse("the copy of web/ holds only {$copied} files");
}
@mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
mkdir($child . $sep . 'sessions', 0700);
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\nreturn " . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true) . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));
file_put_contents($child . $sep . 'router.php', <<<'ROUTER'
<?php
// Written by tools/invite-roles-selftest.php for ONE run. Test only.
declare(strict_types=1);
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
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
ROUTER);

$port = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9939;
$probe = @fsockopen('127.0.0.1', $port, $errNo, $errText, 0.5);
if (is_resource($probe) === true) {
    fclose($probe);
    refuse("something already answers on port {$port}");
}
$jar = $child . $sep . 'cookies.txt';
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc'] = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log',
        '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'max_execution_time=120',
        '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
        '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server.log', 'a'], 2 => ['file', $child . $sep . 'server.log', 'a']],
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false) {
    refuse('could not start PHP\'s web server');
}

/** One request: status, Location and body. Redirects are NOT followed. */
$http = static function (string $method, string $path, array $post = []) use ($port, $jar): array {
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', substr((string) $raw, 0, $headSize), $mm) === 1 ? $mm[1] : '',
        'body'     => substr((string) $raw, $headSize),
    ];
};
$tokenOn = static function (string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $mm) === 1
        || preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $mm) === 1) {
        return html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8');
    }

    return '';
};
$answered = false;
for ($i = 0; $i < 80; $i++) {
    if ($http('GET', '/login')['status'] > 0) {
        $answered = true;
        break;
    }
    usleep(250000);
}
if ($answered === false) {
    refuse('the test server never answered');
}

$site = \Portal\Core\Site::preDetect($db);
if (\Portal\Core\Site::detectionFailed() === true) {
    refuse('Site::preDetect() failed');
}

// =============================================================================
// 🧹 Setup and clean-up
// =============================================================================
$otherSite = 0;
$siteUrlBefore = rows($db, "SELECT * FROM `tblSettings` WHERE `settingKey` = 'site.url' AND `siteID` IS NULL");
$capBefore     = one($db, "SELECT COUNT(*) FROM `tblCapabilityMap` WHERE `siteID` = ? AND `capabilityKey` = 'website.setup'", 'i', [$site]);
$receiptsBefore = rows($db, "SELECT * FROM `tblSettings` WHERE `settingKey` = 'giving.retention.taxReceipts'");
$roleSnapshots = [];

/** Every account made here (the two administrators and every invited person), and what names them. */
$wipePeople = static function () use ($db): void {
    $ids = [IR_GLOBAL, IR_ORG];
    foreach (rows($db, "SELECT `userID` FROM `tblUsers` WHERE `emailAddress` LIKE '%@p2e.invalid'") as $r) {
        $ids[] = (int) $r['userID'];
    }
    $ids = array_values(array_unique($ids));
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach (rows($db, "SELECT c.`TABLE_NAME` AS `t` FROM `information_schema`.`COLUMNS` c JOIN `information_schema`.`TABLES` t "
            . "ON t.`TABLE_SCHEMA` = c.`TABLE_SCHEMA` AND t.`TABLE_NAME` = c.`TABLE_NAME` AND t.`TABLE_TYPE` = 'BASE TABLE' "
            . "WHERE c.`TABLE_SCHEMA` = DATABASE() AND c.`COLUMN_NAME` = 'userID'") as $row) {
            foreach ($ids as $id) {
                run($db, 'DELETE FROM `' . $row['t'] . '` WHERE `userID` = ?', 'i', [$id]);
            }
        }
        foreach ($ids as $id) {
            run($db, 'DELETE FROM `tblUsers` WHERE `userID` = ?', 'i', [$id]);
        }
        run($db, "DELETE FROM `tblInvitation` WHERE `email` LIKE '%@p2e.invalid'");
    } finally {
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
};
$cleaned = false;
$cleanUp = static function () use (&$cleaned, $db, $wipePeople, $site, $capBefore, $siteUrlBefore, $receiptsBefore, &$otherSite, &$roleSnapshots): void {
    if ($cleaned === true) {
        return;
    }
    $cleaned = true;
    foreach ($roleSnapshots as $snap) {
        if (rows($db, 'SELECT 1 FROM `tblRoles` WHERE `roleID` = ?', 'i', [(int) $snap['roleID']]) === []) {
            run($db, 'INSERT INTO `tblRoles` (`roleID`, `siteID`, `roleKey`, `roleName`, `description`, `isStandard`) VALUES (?, ?, ?, ?, ?, ?)',
                'iisssi', [(int) $snap['roleID'], (int) $snap['siteID'], $snap['roleKey'], $snap['roleName'], $snap['description'], (int) $snap['isStandard']]);
        }
    }
    $wipePeople();
    if ($capBefore === 0) {
        run($db, "DELETE FROM `tblCapabilityMap` WHERE `siteID` = ? AND `capabilityKey` = 'website.setup'", 'i', [$site]);
    }
    run($db, "DELETE FROM `tblSettings` WHERE `settingKey` = 'site.url' AND `siteID` IS NULL");
    foreach ($siteUrlBefore as $r) {
        run($db, "INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, 'site.url', ?, ?, ?)",
            'ssi', [$r['settingValue'], (string) $r['defaultValue'], (int) $r['isSensitive']]);
    }
    run($db, "DELETE FROM `tblSettings` WHERE `settingKey` = 'giving.retention.taxReceipts' AND `siteID` = ?", 'i', [$site]);
    foreach ($receiptsBefore as $r) {
        if ($r['siteID'] !== null && (int) $r['siteID'] === $site) {
            run($db, "INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, 'giving.retention.taxReceipts', ?, '', 0)",
                'is', [$site, $r['settingValue']]);
        }
    }
    if ($otherSite > 0) {
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        run($db, 'DELETE FROM `tblUserRoles` WHERE `siteID` = ?', 'i', [$otherSite]);
        run($db, 'DELETE FROM `tblRoles` WHERE `siteID` = ?', 'i', [$otherSite]);
        run($db, 'DELETE FROM `tblSites` WHERE `siteID` = ?', 'i', [$otherSite]);
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
};
register_shutdown_function(static function () use ($cleanUp): void {
    try {
        $cleanUp();
    } catch (Throwable $e) {
        echo "  ! the clean-up could not finish ({$e->getMessage()})\n";
    }
});
$wipePeople();

// The invitation e-mail must be able to carry a link, or save.php stores nothing.
run($db, "DELETE FROM `tblSettings` WHERE `settingKey` = 'site.url' AND `siteID` IS NULL");
run($db, "INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, 'site.url', ?, '', 0)",
    's', ['http://127.0.0.1:' . $port]);
run($db, "DELETE FROM `tblSettings` WHERE `settingKey` = 'giving.retention.taxReceipts' AND `siteID` = ?", 'i', [$site]);

// A second organisation, with the standard roles, so "in no other" means something.
run($db, "INSERT INTO `tblSites` (`siteName`, `siteKey`, `isActive`) VALUES ('Selftest other organisation', 'p2e-other', 1)");
$otherSite = (int) $db->insert_id;
\Portal\Core\Roles::seedStandardSet($db, $otherSite);

$password = 'Ir' . bin2hex(random_bytes(8)) . '!9x';
$makeAdmin = static function (int $id, string $name, bool $global) use ($db, $site, $password): void {
    run($db, 'INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) VALUES (?, ?, ?, 1, ?, ?, NOW())',
        'issii', [$id, 'Selftest ' . $name, $name . '@p2e.invalid', $global ? 1 : 0, $global ? 1 : 0]);
    run($db, 'INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, 1, ?)', 'iii', [$site, $id, $global ? 1 : 0]);
    run($db, 'DELETE FROM `tblLocalAccounts` WHERE `username` = ?', 's', ['p2e-' . $name]);
    run($db, 'INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)',
        'iss', [$id, 'p2e-' . $name, password_hash($password, PASSWORD_DEFAULT)]);
};
$makeAdmin(IR_GLOBAL, 'global', true);
$makeAdmin(IR_ORG, 'orgadmin', false);

/** Sign in as one of the two administrators, or as nobody (null). */
$signIn = static function (?string $who) use ($http, $tokenOn, $jar, $password): void {
    @unlink($jar);
    if ($who === null) {
        return;
    }
    $token = $tokenOn($http('GET', '/login')['body']);
    $in = $http('POST', '/login', ['csrf_token' => $token, 'username' => 'p2e-' . $who, 'password' => $password]);
    if ($in['status'] !== 302 && $in['status'] !== 303) {
        refuse("signing in as {$who} did not answer a redirect (HTTP {$in['status']})");
    }
};

// ── helpers for invitations ─────────────────────────────────────────────────
$counter = 0;
/** Write an invitation straight into the table, as one sent before the fix would be. Returns [token, email]. */
$seedInvite = static function (string $role, int $creator, int $siteId = 0) use ($db, $site, &$counter): array {
    $counter++;
    $token = bin2hex(random_bytes(32));
    $email = 'p2e-inv' . $counter . '-' . substr($token, 0, 6) . '@p2e.invalid';
    run($db, 'INSERT INTO `tblInvitation` (`siteID`, `email`, `tokenHash`, `intendedRole`, `expiresAt`, `createdByID`) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY), ?)',
        'isssi', [$siteId > 0 ? $siteId : $site, $email, hash('sha256', $token), $role, $creator]);

    return [$token, $email];
};
/** Accept through the real page, signed out. Returns the two answers and the new person's number (0 if none). */
$accept = static function (string $token, string $email) use ($http, $tokenOn, $signIn, $db, $password): array {
    $signIn(null);
    $form = $http('GET', '/auth/invite?token=' . $token);
    $csrf = $tokenOn($form['body']);
    $name = 'p2eacc' . substr(hash('sha256', $email), 0, 10);
    $post = $http('POST', '/auth/invite?token=' . $token, [
        'csrf_token' => $csrf, 'token' => $token, 'fullName' => 'Selftest invited', 'username' => $name,
        'password' => $password, 'passwordConfirm' => $password,
    ]);
    $uid = one($db, 'SELECT `userID` FROM `tblUsers` WHERE `emailAddress` = ?', 's', [$email]);

    return ['form' => $form, 'post' => $post, 'uid' => $uid];
};
/** What an account holds: [role keys in this organisation, role rows anywhere else, isSiteAdmin, isAdmin, memberships]. */
$holds = static function (int $uid) use ($db, $site): array {
    $mine = array_map(static fn (array $r): string => (string) $r['roleKey'], rows($db,
        'SELECT r.`roleKey` FROM `tblUserRoles` ur JOIN `tblRoles` r ON r.`roleID` = ur.`roleID` WHERE ur.`userID` = ? AND ur.`siteID` = ? ORDER BY r.`roleKey`', 'ii', [$uid, $site]));

    return [
        'mine'        => $mine,
        'elsewhere'   => one($db, 'SELECT COUNT(*) FROM `tblUserRoles` WHERE `userID` = ? AND `siteID` <> ?', 'ii', [$uid, $site]),
        'isSiteAdmin' => one($db, 'SELECT `isSiteAdmin` FROM `tblUserSites` WHERE `userID` = ? AND `siteID` = ?', 'ii', [$uid, $site]),
        'isAdmin'     => one($db, 'SELECT `isAdmin` FROM `tblUsers` WHERE `userID` = ?', 'i', [$uid]),
        'members'     => one($db, 'SELECT COUNT(*) FROM `tblUserSites` WHERE `userID` = ?', 'i', [$uid]),
    ];
};
/** Everything an accepted-then-refused invitation must NOT have left. */
$nothingWritten = static function (string $email, string $token) use ($db): bool {
    return one($db, 'SELECT COUNT(*) FROM `tblUsers` WHERE `emailAddress` = ?', 's', [$email]) === 0
        && one($db, 'SELECT COUNT(*) FROM `tblUserSites` us JOIN `tblUsers` u ON u.`userID` = us.`userID` WHERE u.`emailAddress` = ?', 's', [$email]) === 0
        && one($db, 'SELECT COUNT(*) FROM `tblLocalAccounts` WHERE `username` LIKE ?', 's', ['p2eacc' . substr(hash('sha256', $email), 0, 10) . '%']) === 0
        && one($db, 'SELECT COUNT(*) FROM `tblInvitation` WHERE `tokenHash` = ? AND `acceptedAt` IS NULL', 's', [hash('sha256', $token)]) === 1;
};
$roleRow = static function (string $key, int $siteId) use ($db): array {
    return rows($db, 'SELECT * FROM `tblRoles` WHERE `siteID` = ? AND `roleKey` = ?', 'is', [$siteId, $key])[0] ?? [];
};
/** Point "Set up the public website" at one role key, as a global administrator would. */
$pointSetUpAt = static function (array $keys) use ($db, $site): string {
    return \Portal\Core\Capabilities::save($db, $site, \Portal\Core\Capabilities::WEBSITE_SETUP, $keys, null, []);
};
$post = static function (string $path, array $fields) use ($http, $tokenOn): array {
    $csrf = $tokenOn($http('GET', '/invites/new')['body']);

    return $http('POST', $path, $fields + ['csrf_token' => $csrf]);
};

// =============================================================================
// A. Each role, accepted through the real page (#539)
// =============================================================================
head('A. Each invited role is granted — and only in the inviting organisation');
$sendFor = static function (string $who, string $role, string $email) use ($signIn, $post): array {
    $signIn($who);

    return $post('/invites/save', ['emails' => $email, 'role' => $role, 'expiryDays' => '7']);
};

// A0. The save page stores what was asked, with the inviter's number, for all four.
foreach (['user', 'volunteer', 'staff', 'admin'] as $role) {
    $email = 'p2e-save-' . $role . '@p2e.invalid';
    $answer = $sendFor('orgadmin', $role, $email);
    $row = rows($db, 'SELECT `intendedRole`, `createdByID` FROM `tblInvitation` WHERE `email` = ?', 's', [$email])[0] ?? [];
    check("A0 an ordinary administrator's \"{$role}\" invitation is stored with that role and the inviter's number",
        ($row['intendedRole'] ?? '') === $role && (int) ($row['createdByID'] ?? 0) === IR_ORG, json_encode([$answer['status'], $row]) ?: '');
}

$roleCase = static function (string $role) use ($seedInvite, $accept, $holds, $db): array {
    [$token, $email] = $seedInvite($role, IR_ORG);
    $got = $accept($token, $email);

    return ['uid' => $got['uid'], 'holds' => $got['uid'] > 0 ? $holds($got['uid']) : [], 'post' => $got['post'],
        'accepted' => one($db, 'SELECT COUNT(*) FROM `tblInvitation` WHERE `tokenHash` = ? AND `acceptedAt` IS NOT NULL', 's', [hash('sha256', $token)])];
};
$volunteerCase = static function () use ($roleCase, $db, $site): bool {
    $r = $roleCase('volunteer');
    $byWho = $r['uid'] > 0 ? one($db, 'SELECT `grantedByID` FROM `tblUserRoles` WHERE `userID` = ?', 'i', [$r['uid']]) : 0;

    return $r['uid'] > 0 && $r['holds']['mine'] === ['volunteer'] && $r['holds']['elsewhere'] === 0
        && $r['holds']['isSiteAdmin'] === 0 && $r['accepted'] === 1 && $byWho === IR_ORG;
};
$r = $roleCase('user');
check('A1 "user": the account exists, belongs to this organisation, holds NO role and is no administrator',
    $r['uid'] > 0 && $r['holds']['mine'] === [] && $r['holds']['elsewhere'] === 0 && $r['holds']['isSiteAdmin'] === 0
    && $r['holds']['isAdmin'] === 0 && $r['holds']['members'] === 1 && $r['accepted'] === 1, json_encode($r['holds']) ?: '');
check('A2 "volunteer": holds exactly the volunteer role here, none anywhere else, granted by the inviter; not an administrator',
    $volunteerCase());
$r = $roleCase('staff');
check('A3 "staff": holds exactly the staff role here and none anywhere else',
    $r['uid'] > 0 && $r['holds']['mine'] === ['staff'] && $r['holds']['elsewhere'] === 0 && $r['holds']['isSiteAdmin'] === 0 && $r['accepted'] === 1,
    json_encode($r['holds']) ?: '');
$r = $roleCase('admin');
check('A4 "admin": an administrator of THIS organisation only (never portal-wide), and no role row — as before',
    $r['uid'] > 0 && $r['holds']['isSiteAdmin'] === 1 && $r['holds']['isAdmin'] === 0 && $r['holds']['mine'] === [] && $r['holds']['elsewhere'] === 0,
    json_encode($r['holds']) ?: '');

// A5. An invitation for ANOTHER organisation grants the role of THAT organisation only.
[$tokenO, $emailO] = $seedInvite('volunteer', IR_GLOBAL, $otherSite);
$got = $accept($tokenO, $emailO);
$inOther = one($db, 'SELECT COUNT(*) FROM `tblUserRoles` WHERE `userID` = ? AND `siteID` = ?', 'ii', [$got['uid'], $otherSite]);
$inThis  = one($db, 'SELECT COUNT(*) FROM `tblUserRoles` WHERE `userID` = ? AND `siteID` = ?', 'ii', [$got['uid'], $site]);
check('A5 an invitation to ANOTHER organisation gives the role there and nothing in this one', $got['uid'] > 0 && $inOther === 1 && $inThis === 0);

// A6. #1138 item 8. The new member is signed in by the acceptance; the very next page's top
//     bar must show THEIR name (it used to read "User" until they signed in again).
//     The jar still holds the session the acceptance made.
$nameShown = static function () use ($seedInvite, $accept, $http): array {
    [$tokenN, $emailN] = $seedInvite('user', IR_ORG);
    $got  = $accept($tokenN, $emailN);
    $page = $http('GET', '/dashboard');

    return ['uid' => $got['uid'], 'status' => $page['status'], 'body' => $page['body']];
};
$n = $nameShown();
$inTopBar = static fn (string $html): bool => preg_match('/portal-navbar-user">\s*Selftest invited\s*</', $html) === 1;
check("A6 the new member's name ('Selftest invited') is in the TOP BAR of the next page, not \"User\"",
    $n['uid'] > 0 && $n['status'] === 200 && $inTopBar($n['body']) === true,
    'HTTP ' . $n['status']);

// =============================================================================
// B. A role deleted between sending and accepting refuses the whole acceptance
// =============================================================================
head('B. A role that cannot be granted takes the whole acceptance back');
$roleGoneCase = static function () use ($seedInvite, $accept, $nothingWritten, $roleRow, $db, $site, &$roleSnapshots, $http, $signIn, $tokenOn): array {
    $snap = $roleRow('volunteer', $site);
    $roleSnapshots[] = $snap;
    [$token, $email] = $seedInvite('volunteer', IR_ORG);
    run($db, 'DELETE FROM `tblRoles` WHERE `roleID` = ?', 'i', [(int) $snap['roleID']]);
    $got = $accept($token, $email);
    $clean = $nothingWritten($email, $token);
    $roles = one($db, 'SELECT COUNT(*) FROM `tblUserRoles` ur JOIN `tblUsers` u ON u.`userID` = ur.`userID` WHERE u.`emailAddress` = ?', 's', [$email]);
    // Put the role back (same number), then the SAME link must still work.
    run($db, 'INSERT INTO `tblRoles` (`roleID`, `siteID`, `roleKey`, `roleName`, `description`, `isStandard`) VALUES (?, ?, ?, ?, ?, ?)',
        'iisssi', [(int) $snap['roleID'], (int) $snap['siteID'], $snap['roleKey'], $snap['roleName'], $snap['description'], (int) $snap['isStandard']]);
    $again = $accept($token, $email);

    return ['clean' => $clean, 'roles' => $roles, 'saysSo' => str_contains($got['post']['body'], 'cannot be accepted as it stands'),
        'noSignIn' => $got['post']['status'] !== 302, 'usableAgain' => $again['uid'] > 0 && $again['post']['status'] === 302];
};
$g = $roleGoneCase();
check('B1 role deleted between sending and accepting: NO account, membership, password or role, the invitation is not spent, and the person is told plainly',
    $g['clean'] === true && $g['roles'] === 0 && $g['saysSo'] === true && $g['noSignIn'] === true, json_encode($g) ?: '');
check('B2 once the role is back the very same link works', $g['usableAgain'] === true);

// B3. save.php refuses to invite for a role the organisation does not have.
$snap = $roleRow('staff', $site);
$roleSnapshots[] = $snap;
run($db, 'DELETE FROM `tblRoles` WHERE `roleID` = ?', 'i', [(int) $snap['roleID']]);
$emailNo = 'p2e-norole@p2e.invalid';
$answer = $sendFor('orgadmin', 'staff', $emailNo);
$stored = one($db, 'SELECT COUNT(*) FROM `tblInvitation` WHERE `email` = ?', 's', [$emailNo]);
$refusedPage = $http('GET', $answer['location'] !== '' ? (string) parse_url($answer['location'], PHP_URL_PATH) : '/invites/new');
run($db, 'INSERT INTO `tblRoles` (`roleID`, `siteID`, `roleKey`, `roleName`, `description`, `isStandard`) VALUES (?, ?, ?, ?, ?, ?)',
    'iisssi', [(int) $snap['roleID'], (int) $snap['siteID'], $snap['roleKey'], $snap['roleName'], $snap['description'], (int) $snap['isStandard']]);
check('B3 inviting for a role the organisation no longer has stores nothing and says why on the form',
    $stored === 0 && str_contains(html_entity_decode($refusedPage['body'], ENT_QUOTES, 'UTF-8'), 'has no "staff" role') === true, (string) $answer['status']);

// =============================================================================
// C. D-093 — who may hand out a role that opens "Set up the public website"
// =============================================================================
head('C. D-093: the inviter is asked when the invitation is saved and again when it is accepted');
$preFix = $seedInvite('volunteer', IR_ORG);   // saved while volunteer opened nothing
check('C0 the website-setup capability is pointed at "volunteer" by a global administrator', $pointSetUpAt(['volunteer']) === 'ok');
$beforeRefusals = one($db, "SELECT COUNT(*) FROM `tblActivityLogs` WHERE `activityType` = 'PublicWebsiteSetupHandOutRefused'");

$emailD = 'p2e-d093-org@p2e.invalid';
$answer = $sendFor('orgadmin', 'volunteer', $emailD);
$refusedPageC = $http('GET', $answer['location'] !== '' ? (string) parse_url($answer['location'], PHP_URL_PATH) : '/invites/new');
check('C1 an ordinary administrator\'s volunteer invitation is REFUSED at save, nothing is stored, the form says why, and the refusal is recorded',
    str_contains($refusedPageC['body'], 'data-invite-refusal') === true && one($db, 'SELECT COUNT(*) FROM `tblInvitation` WHERE `email` = ?', 's', [$emailD]) === 0
    && one($db, "SELECT COUNT(*) FROM `tblActivityLogs` WHERE `activityType` = 'PublicWebsiteSetupHandOutRefused'") === $beforeRefusals + 1);
$emailD2 = 'p2e-d093-global@p2e.invalid';
$sendFor('global', 'volunteer', $emailD2);
check('C2 a global administrator\'s volunteer invitation is stored',
    one($db, 'SELECT COUNT(*) FROM `tblInvitation` WHERE `email` = ? AND `intendedRole` = ?', 'ss', [$emailD2, 'volunteer']) === 1);
$emailD3 = 'p2e-d093-staff@p2e.invalid';
$sendFor('orgadmin', 'staff', $emailD3);
check('C3 an ordinary administrator still invites "staff" (the rule touches only roles that open the permission)',
    one($db, 'SELECT COUNT(*) FROM `tblInvitation` WHERE `email` = ?', 's', [$emailD3]) === 1);

$lateCase = static function () use ($preFix, $accept, $nothingWritten, $db): array {
    $got = $accept($preFix[0], $preFix[1]);
    $logged = one($db, "SELECT COUNT(*) FROM `tblActivityLogs` WHERE `activityType` = 'PublicWebsiteSetupHandOutRefused' AND `userID` = ?", 'i', [IR_ORG]);

    return ['clean' => $nothingWritten($preFix[1], $preFix[0]), 'logged' => $logged,
        'says' => str_contains($got['post']['body'], 'cannot be accepted as it stands'), 'status' => $got['post']['status']];
};
$late = $lateCase();
check('C4 an invitation saved BEFORE the re-pointing is refused at acceptance: nothing written, the person told, the refusal recorded after the rollback',
    $late['clean'] === true && $late['says'] === true && $late['logged'] >= 1 && $late['status'] !== 302, json_encode($late) ?: '');
[$tokenG, $emailG] = $seedInvite('volunteer', IR_GLOBAL);
$got = $accept($tokenG, $emailG);
check('C5 a volunteer invitation made by a GLOBAL administrator is still honoured after the re-pointing',
    $got['uid'] > 0 && $holds($got['uid'])['mine'] === ['volunteer']);

// D. The form offers the roles that can be handed out.
$signIn('orgadmin');
$orgForm = $http('GET', '/invites/new')['body'];
$signIn('global');
$globalForm = $http('GET', '/invites/new')['body'];
$offers = static fn (string $html, string $role): bool => str_contains($html, '<option value="' . $role . '"');
check('D1 an ordinary administrator is NOT offered "volunteer" (it opens website set-up) but is offered "staff", "user" and "admin"',
    $offers($orgForm, 'volunteer') === false && $offers($orgForm, 'staff') === true && $offers($orgForm, 'user') === true && $offers($orgForm, 'admin') === true);
check('D2 a global administrator is offered every one of the four', $offers($globalForm, 'volunteer') && $offers($globalForm, 'staff') && $offers($globalForm, 'user') && $offers($globalForm, 'admin'));
check('C6 putting website set-up back to its standard role (forget) lets an ordinary administrator invite volunteers again',
    \Portal\Core\Capabilities::forget($db, $site, \Portal\Core\Capabilities::WEBSITE_SETUP, null, []) !== 'failed'
    && (function () use ($signIn, $http): bool { $signIn('orgadmin'); return str_contains($http('GET', '/invites/new')['body'], '<option value="volunteer"'); })());

// =============================================================================
// E. The receipt wording (#939, D-167)
// =============================================================================
head('E. "Tax receipt" appears only where the organisation says it issues them');
$givingId = (int) one($db, "SELECT COUNT(*) FROM `tblRoutes` WHERE `routeKey` = 'admin/settings/giving-records'");
$pageWords = static function () use ($http, $signIn): array {
    $signIn(null);
    $privacy = $http('GET', '/privacy/policy')['body'];
    $signIn('global');
    $help = $http('GET', '/help/admin')['body'];
    $rules = $http('GET', '/admin/settings/giving-records')['body'];

    return ['privacy' => $privacy, 'help' => $help, 'rules' => $rules];
};
$sayReceipt = static function (string $html): bool {
    return preg_match('/tax\s+receipt/i', strip_tags($html)) === 1;
};
$offWords = 'gifts covered by a tax declaration or a giving statement';
$onWords  = 'gifts covered by a tax declaration, or by a giving statement (this organisation\'s tax receipt)';
$decode   = static fn (string $html): string => html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '', ENT_QUOTES, 'UTF-8');
$off = $pageWords();
$privacyOff = $decode($off['privacy']);
$helpOff    = $decode($off['help']);
check('E1 OFF (no row stored): the privacy notice names the gifts without any receipt, and says "tax receipt" nowhere',
    str_contains($privacyOff, $offWords) && $sayReceipt($off['privacy']) === false);
check('E2 OFF: the help page says the same words and "tax receipt" nowhere', str_contains($helpOff, $offWords) && $sayReceipt($off['help']) === false);
check('E3 OFF: the giving-records page words the choice without a receipt in its radio labels and its "in force" line',
    str_contains($decode($off['rules']), 'Only gifts covered by a tax declaration or a giving statement')
    && str_contains($off['rules'], 'id="taxReceipts"') && str_contains($off['rules'], 'name="taxReceiptsShown"'));
check('E3b OFF: the tick is drawn unticked', preg_match('/id="taxReceipts"[^>]*checked/', $off['rules']) === 0);

// The tick saves, through the real handler, for THIS organisation.
$savePage = static function (array $extra) use ($http, $tokenOn, $signIn): array {
    $signIn('global');
    $csrf = $tokenOn($http('GET', '/admin/settings/giving-records')['body']);
    $fields = ['csrf_token' => $csrf, 'countryCode' => '', 'scope' => 'receipted', 'years' => '', 'yearEndMonth' => '', 'yearEndDay' => ''] + $extra;

    return $http('POST', '/admin/settings/giving-records/save', $fields);
};
$stored = static fn (): ?string => (rows($db, "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'giving.retention.taxReceipts' AND `siteID` = ?", 'i', [$site])[0]['settingValue'] ?? null);
$savePage(['taxReceiptsShown' => '1', 'taxReceipts' => '1']);
check('E4 ticking and saving writes `true` on THIS organisation\'s own row', $stored() === 'true');
$on = $pageWords();
$privacyOn = $decode($on['privacy']);
check('E5 ON: the privacy notice says the giving statement is this organisation\'s tax receipt (AWAITING THE OWNER\'S CONFIRMATION of the wording, Q-2e-1)',
    str_contains($privacyOn, $onWords) && str_contains($decode($on['help']), $onWords));
check('E5b ON: the giving-records page words the choice the same way and draws the tick as ticked',
    str_contains($decode($on['rules']), 'Only ' . $onWords) && preg_match('/id="taxReceipts"[^>]*checked/', $on['rules']) === 1);
$savePage([]);
check('E6 a post with NO marker (an old form, a script) leaves the saved answer alone', $stored() === 'true');
$savePage(['taxReceiptsShown' => '1']);
check('E7 the marker with the box unticked switches it off, on the organisation\'s own row', $stored() === 'false');
$offAgain = $pageWords();
check('E8 and the wording returns to the version without a receipt',
    str_contains($decode($offAgain['privacy']), $offWords) && $sayReceipt($offAgain['privacy']) === false);
// The general editor refuses the key (the page above is its one editor).
$signIn('global');
$csrf = $tokenOn($http('GET', '/settings')['body']);
$before = $stored();
$http('POST', '/settings/save', ['csrf_token' => $csrf, 'settingKey' => 'giving.retention.taxReceipts', 'settingValue' => 'true', 'siteScope' => 'site']);
check('E9 the general settings editor does not write the key', $stored() === $before);
check('E10 the keep rule never reads the setting: no SQL builder in GivingRetention names it',
    (static function () use ($repo, $sep): bool {
        $src = (string) file_get_contents($repo . $sep . 'web' . $sep . '_core' . $sep . 'GivingRetention.php');
        // The key may appear in the constant, ruleForSite(), the save and the words — never in a statement that
        // decides whose name is kept (keptWhenSql / keptGiftSql).
        foreach (['keptWhenSql', 'keptGiftSql'] as $fn) {
            if (preg_match('/function ' . $fn . '\(.*?\n    }\n/s', $src, $mm) === 1 && str_contains($mm[0], 'TAX_RECEIPTS') === true) {
                return false;
            }
        }

        return true;
    })());

// =============================================================================
// F. #606
// =============================================================================
head('F. The Admin home links the pages an administrator needs');
$signIn('global');
$adminGlobal = $http('GET', '/admin');
$signIn('orgadmin');
$adminOrg = $http('GET', '/admin');
$tile = static fn (string $html, string $marker, string $path): bool =>
    preg_match('/data-admin-setup-tile="' . $marker . '".*?href="[^"]*\/' . preg_quote($path, '/') . '"/s', $html) === 1;
check('F1 a global administrator\'s Admin home links Upgrade, Organisation settings and E-mail',
    $adminGlobal['status'] === 200 && $tile($adminGlobal['body'], 'upgrade', 'admin/upgrade') && $tile($adminGlobal['body'], 'organisation', 'admin/settings/organisation')
    && $tile($adminGlobal['body'], 'email', 'admin/integrations/email'));
check('F2 an ordinary administrator\'s Admin home links Organisation settings only (no dead-end links to refusals)',
    $adminOrg['status'] === 200 && $tile($adminOrg['body'], 'organisation', 'admin/settings/organisation')
    && str_contains($adminOrg['body'], 'data-admin-setup-tile="upgrade"') === false && str_contains($adminOrg['body'], 'data-admin-setup-tile="email"') === false);
$signIn('global');
check('F3 the three linked pages answer a global administrator with a whole page',
    $http('GET', '/admin/upgrade')['status'] === 200 && $http('GET', '/admin/settings/organisation')['status'] === 200 && $http('GET', '/admin/integrations/email')['status'] === 200);
$signIn(null); // a signed-in browser is redirected away from /login
check('F4 the sign-in page carries the favicon link (#606 item 5, already done)', preg_match('/<link[^>]+rel="[^"]*icon[^"]*"/i', $http('GET', '/login')['body']) === 1);
$signIn('global');
$notes = $http('GET', '/admin/release-notes');
check('F5 the Release Notes page has ONE top-level heading', $notes['status'] === 200 && preg_match_all('/<h1[\s>]/i', $notes['body']) === 1, 'h1 count: ' . preg_match_all('/<h1[\s>]/i', $notes['body']));
check('F6 the Backup page names the private _backups folder, not web/_backups',
    str_contains($http('GET', '/admin/maintenance/backup')['body'], 'web/_backups') === false);

// =============================================================================
// G. PLANTS — every new guard switched off in the copy, and its check must go red
// =============================================================================
head('G. Plants: each guard, removed in a copy of the page, turns its check red');
$plant = static function (string $label, string $file, string $from, string $to, callable $redWhen) use ($child, $sep): bool {
    $path = $child . $sep . 'web' . $sep . str_replace('/', $sep, $file);
    $orig = (string) file_get_contents($path);
    if (substr_count($orig, $from) !== 1) {
        return check("G {$label}: the line to plant is present exactly once", false, 'found ' . substr_count($orig, $from));
    }
    file_put_contents($path, str_replace($from, $to, $orig));
    try {
        $red = $redWhen() === false;
    } finally {
        file_put_contents($path, $orig);
    }
    $same = (string) file_get_contents($path) === $orig;

    return check("G {$label}: red when planted, and the copy is put back byte for byte", $red === true && $same === true);
};
// Point website set-up at volunteer again for the D-093 plants, undone after each.
$plant('dropping the grant turns A2 (volunteer holds the role) red', '_apps/invites/accept.php',
    "? Roles::grant(\$db, \$newUserId, \$roleId, \$inviteSiteId, \$inviterId > 0 ? \$inviterId : null)\n                        : 'not_found';",
    "? 'ok'\n                        : 'not_found';", $volunteerCase);
$plant('ignoring the grant\'s answer turns B (role deleted) red', '_apps/invites/accept.php',
    "if (\$granted !== 'ok') {", 'if (false) {', static function () use ($roleGoneCase): bool {
        $g = $roleGoneCase();

        return $g['clean'] === true && $g['roles'] === 0;
    });
$pointSetUpAt(['volunteer']);
$preFix2 = $seedInvite('volunteer', IR_ORG);
$plant('dropping the under-lock ask turns C4 (saved before the re-pointing) red', '_apps/invites/accept.php',
    '$whyNot = PublicWebsiteSetup::whyRoleChangeRefusedUnderLock($db, $inviterId, $inviteSiteId, [$intendedRole]);', "\$whyNot = '';",
    static function () use ($preFix2, $accept, $nothingWritten): bool {
        $accept($preFix2[0], $preFix2[1]);

        return $nothingWritten($preFix2[1], $preFix2[0]);
    });
$plant('dropping the save-time ask turns C1 (refused at save) red', '_apps/invites/save.php',
    '$whyNot = PublicWebsiteSetup::whyRoleChangeRefused($db, $userId, $siteId, [$role]);', "\$whyNot = '';",
    static function () use ($sendFor, $db): bool {
        $e = 'p2e-plant-save@p2e.invalid';
        $sendFor('orgadmin', 'volunteer', $e);

        return one($db, 'SELECT COUNT(*) FROM `tblInvitation` WHERE `email` = ?', 's', [$e]) === 0;
    });
$plant('dropping the form\'s own check turns D1 (not offered) red', '_apps/invites/new.php',
    "&& PublicWebsiteSetup::whyRoleChangeRefused(\$dbForRoles, \$inviterNumber, Site::id(), [\$roleRow['roleKey']]) === ''", '',
    static function () use ($signIn, $http, $offers): bool {
        $signIn('orgadmin');

        return $offers($http('GET', '/invites/new')['body'], 'volunteer') === false;
    });
$pointSetUpAt([]); // undo; the cleanUp also removes the choice
\Portal\Core\Capabilities::forget($db, $site, \Portal\Core\Capabilities::WEBSITE_SETUP, null, []);
$plant('a tax receipt named for every organisation turns E1 red', '_core/GivingRetention.php',
    ": 'gifts covered by a tax declaration or a giving statement';", ": 'gifts covered by a tax declaration, a tax receipt or a giving statement';",
    static function () use ($pageWords, $sayReceipt): bool {
        return $sayReceipt($pageWords()['privacy']) === false;
    });
$plant('reading an absent marker as "unticked" turns E6 red', '_core/GivingRetention.php',
    "\$receiptsOwn = (\$text('taxReceiptsShown') === '1') ? (\$text('taxReceipts') === '1') : null;", "\$receiptsOwn = \$text('taxReceipts') === '1';",
    static function () use ($savePage, $stored, $db, $site): bool {
        $savePage(['taxReceiptsShown' => '1', 'taxReceipts' => '1']);
        $savePage([]);
        $kept = $stored() === 'true';
        run($db, "DELETE FROM `tblSettings` WHERE `settingKey` = 'giving.retention.taxReceipts' AND `siteID` = ?", 'i', [$site]);

        return $kept;
    });
$plant('not setting the new member\'s name in the session turns A6 red', '_apps/invites/accept.php',
    '$_SESSION[\'user_name\']  = $name;', '',
    static function () use ($nameShown, $inTopBar): bool {
        $n = $nameShown();

        return $n['uid'] > 0 && $inTopBar($n['body']) === true;
    });
$plant('removing the Upgrade tile\'s gate turns F2 red', '_apps/admin/index.php',
    "['show' => \$isUmbrella === true, 'colour' => 'secondary', 'icon' => 'fa-arrow-up-from-bracket'", "['show' => true, 'colour' => 'secondary', 'icon' => 'fa-arrow-up-from-bracket'",
    static function () use ($signIn, $http): bool {
        $signIn('orgadmin');

        return str_contains($http('GET', '/admin')['body'], 'data-admin-setup-tile="upgrade"') === false;
    });

// =============================================================================
echo "\n" . ($failed === 0 ? 'PASS' : 'FAIL') . " — {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
