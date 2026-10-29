<?php
// Path: _core/bootstrap.php
/**
 * -----------------------------------------------------------------------------
 * Portal Core Bootstrap 🚀
 * -----------------------------------------------------------------------------
 * Prepares environment constants, opens the database connection, loads settings
 * into a multidimensional array, initialises the App registry and Debug timer,
 * registers the PSR-4-lite autoloader, and wires global error/exception handlers.
 *
 * This file is loaded by the single front controller (index.php in the web
 * folder), and can also be conditionally loaded by individual app files for
 * standalone/debug use. There is one front controller for every release
 * channel. Which channel an installation is comes from the PORTAL_ENV
 * setting, else its `.channel` file, else one of three known web folder
 * names, else it is assumed to be live (see _core/Channel.php). The web
 * folder may be called anything the hosting calls it.
 *
 * All conditional logic uses full IF notation for human readability, per code
 * style guidelines.
 *
 * @see       https://www.php.net/manual/en/language.constants.predefined.php
 * @see       https://www.php.net/manual/en/function.spl-autoload-register.php
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.1
 * @link      https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🛡️ Prevent double-loading if an app file includes bootstrap.php directly
// while the Router has already loaded it
if (defined('PORTAL_ROOT') === true) {
    return;
}

// ⏱️ ---------------------------------------------------------------------------
// 0. Record start time for debug panel performance metrics
// -----------------------------------------------------------------------------
// We store this before anything else to get the most accurate page load timing.
// The Debug class will pick this up once the autoloader is available.
// See: https://www.php.net/manual/en/function.microtime.php
define('PORTAL_START_TIME', microtime(true));

// 🗂️ ---------------------------------------------------------------------------
// 1. Directory constants – platform-neutral
// -----------------------------------------------------------------------------
// Using DIRECTORY_SEPARATOR and dirname() ensures paths work on both
// Windows and Unix-based servers (DreamHost Linux, local macOS etc).
// See: https://www.php.net/manual/en/dir.constants.php

define('PORTAL_ROOT', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));

define('PORTAL_CORE',   PORTAL_ROOT . DIRECTORY_SEPARATOR . '_core');
define('PORTAL_APPS',   PORTAL_ROOT . DIRECTORY_SEPARATOR . '_apps');
define('PORTAL_VENDOR', PORTAL_ROOT . DIRECTORY_SEPARATOR . '_vendor');
define('PORTAL_SQL',    PORTAL_ROOT . DIRECTORY_SEPARATOR . '_sql');

// 🌐 PORTAL_WEBROOT — the web folder: the one the web server serves, which
//    holds index.php, the offline page, the manifest and /assets.
//
//    The two front controllers (index.php and error.php) define it as their
//    OWN folder before loading this file, so it is right whatever the hosting
//    calls that folder: public_html, httpdocs, www, admin_html.
//
//    WHAT WAS WRONG: five places built the path with the name 'public_html'
//    typed in (Router twice, Auth, ReservedKeys, manifest.php). On hosting
//    with any other name the manifest, robots.txt, the sitemap and the API
//    description answered "page not found", the offline page could not be
//    shown at all, and no name was reserved against an organisation's key
//    for being a real file or folder in the web folder.
//
//    The line below is only for code that loads this file WITHOUT a front
//    controller: a command-line tool, or a test. It falls back to the name the
//    product is shipped with. WHAT IT CANNOT DO: know a renamed web folder.
//    Such a tool must define PORTAL_WEBROOT itself, before loading this file,
//    if it needs anything from the web folder of a renamed installation.
//
//    $portalWebrootStated remembers which of the two happened, BEFORE the
//    stand-in is defined. The release channel (just below) may be read from
//    the web folder's name, and the stand-in is not this installation's
//    folder: a command-line tool on a beta copy would otherwise be told
//    "public_html, so live".
$portalWebrootStated = defined('PORTAL_WEBROOT');
if ($portalWebrootStated === false) {
    define('PORTAL_WEBROOT', PORTAL_ROOT . DIRECTORY_SEPARATOR . 'public_html');
}

// 📌 Authoritative portal version — single source of truth shared with the
// bootstrap-free installer (web/_install/) so they cannot drift apart.
// See _core/version.php for the release-bump procedure.
define('PORTAL_VERSION', (string) (require PORTAL_CORE . DIRECTORY_SEPARATOR . 'version.php'));

// 🏷️ Product brand fallbacks — system-level brand layer (issue #296).
// Sits ABOVE the existing tenant-level `branding.*` settings (Site::branding()):
//   tenant override > $SETTINGS['product']['*'] > these constants.
// The constants represent the FINAL fallback chain — the values shipped when
// settings haven't loaded yet or rows are missing. Once $SETTINGS is loaded
// the relevant rows take precedence. See _core/brand-defaults.php for the
// full preset registry and the documented two-layer brand model.
$PORTAL_BRAND_DEFAULTS = (require PORTAL_CORE . DIRECTORY_SEPARATOR . 'brand-defaults.php')[''] ?? [
    'name'      => 'WebMS Intra',
    'tagline'   => 'Internal Management System',
    'publisher' => 'MWBM Partners Ltd (t/a MWservices)',
];
define('PORTAL_PRODUCT_NAME_DEFAULT',      (string) $PORTAL_BRAND_DEFAULTS['name']);
define('PORTAL_PRODUCT_TAGLINE_DEFAULT',   (string) $PORTAL_BRAND_DEFAULTS['tagline']);
define('PORTAL_PRODUCT_PUBLISHER_DEFAULT', (string) $PORTAL_BRAND_DEFAULTS['publisher']);
unset($PORTAL_BRAND_DEFAULTS);

// 🌍 The release channel: PORTAL_ENV ('prod' | 'beta' | 'alpha' | 'dev') and
//    PORTAL_ENV_SOURCE (how it was decided: 'environment' | 'file' |
//    'folder' | 'assumed').
// -----------------------------------------------------------------------------
// Decided in ONE place, Portal\Core\Channel (_core/Channel.php), in this
// order: the PORTAL_ENV setting; else the first line of the `.channel` file
// beside _core; else the web folder's own name, when it is exactly
// public_html, public_html_beta or public_html_dev; else the portal ASSUMES
// IT IS LIVE ('prod', source 'assumed'). Read that file's header for the
// words understood, for what it cannot do, and for "fail closed", which was
// built first and replaced by the owner on 12 October 2026.
//
// THE FOLDER'S NAME IS GIVEN ONLY WHEN A FRONT CONTROLLER STATED IT (index.php
// and error.php set PORTAL_WEBROOT to their own folder). Never from
// DOCUMENT_ROOT: on some hosting a visitor's Host header shapes that value,
// and the last release searched it for the names anywhere in the path.
// tools/channel-selftest.php fails if this file reads it again.
//
// WHAT WAS WRONG, until 12 October 2026: an unrecognised folder name became
// 'dev' with errors SHOWN and the gate OFF. A live portal on hosting that
// names the web folder `httpdocs` or `www` printed PHP errors to the public.
//
// Channel.php is loaded by name because the class loader is not registered
// until further down this file.
require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'Channel.php';
$portalChannel = \Portal\Core\Channel::decide(PORTAL_ROOT, $portalWebrootStated === true ? PORTAL_WEBROOT : null);
unset($portalWebrootStated);
define('PORTAL_ENV', $portalChannel['channel']);
define('PORTAL_ENV_SOURCE', $portalChannel['source']);

// 🛡️ PHP error display hardening
// -----------------------------------------------------------------------------
// PHP's own error text can leak file paths, query fragments and the names of
// credential variables, so it is printed into a page ONLY on a pre-release
// channel that was stated (the setting, the file or the folder's name says
// beta, alpha or dev).
//
// THAT IS NOT THE SAME AS "ONLY STAFF SEE IT". On a pre-release copy the staff
// gate leaves the sign-in pages, Help, the offline page and /health open, and
// a PHP warning raised on one of those IS printed to a visitor who is not
// signed in. It was so before this rule was written. (This comment used to
// say the people who reach those pages are staff, behind the gate: untrue.)
//
// On the live channel, stated OR assumed, nothing is printed. Errors are
// still REPORTED either way, so Logger / tblErrors and PHP's own log see
// them for an administrator to read.
//
// WHAT WAS WRONG: this used to print errors whenever PORTAL_ENV was not
// 'prod', which included the last-resort guess. Channel::decide() now gives
// the answer, so the rule and the channel cannot disagree.
//
// See: https://www.php.net/manual/en/errorfunc.configuration.php
if ($portalChannel['showErrors'] === true) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('html_errors', '0');
    error_reporting(E_ALL);
}
unset($portalChannel);

// 🕐 Default timezone until settings load (overridden later)
date_default_timezone_set('UTC');

// 📝 ---------------------------------------------------------------------------
// 2. PSR-4-lite autoload (no Composer available on DreamHost shared hosting)
// -----------------------------------------------------------------------------
// Maps namespace prefixes to directories so classes can be loaded on demand.
// Portal\Core\Router → core/Router.php
// Portal\App\Expenses → public_html/Expenses.php
// SimpleJWT\JWT → vendor/simplejwt/JWT.php
// See: https://www.php.net/manual/en/function.spl-autoload-register.php

spl_autoload_register(function (string $class): void {
    // 📌 Namespace prefix → base directory mapping
    $prefixes = [
        'Portal\\Core\\'  => PORTAL_CORE . DIRECTORY_SEPARATOR,
        'Portal\\App\\'   => PORTAL_APPS . DIRECTORY_SEPARATOR,
        'SimpleJWT\\'     => PORTAL_VENDOR . DIRECTORY_SEPARATOR . 'simplejwt' . DIRECTORY_SEPARATOR,
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix) === true) {
            // 🔄 Convert namespace separators to directory separators
            $relativeClass = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

            if (is_readable($file) === true) {
                require_once $file;
            }
        }
    }
});

// 🔐 ---------------------------------------------------------------------------
// 3. Crypto helpers (libsodium wrapper for sensitive settings)
// -----------------------------------------------------------------------------
// Uses libsodium's authenticated encryption (XSalsa20 + Poly1305) with a
// random nonce prepended to the ciphertext. The encryption key is derived
// from a file stored outside the web root for security.
// See: https://www.php.net/manual/en/book.sodium.php
// See: https://paragonie.com/blog/2017/06/libsodium-quick-reference

if (function_exists('encrypt_setting') === false) {
    /**
     * 🗝️ What is in the key file, or null when there is NO KEY: the file is
     * missing, cannot be read, is not a file at all (a folder of that name,
     * say), or is EMPTY.
     *
     * WHAT WAS WRONG (found 13 October 2026, by running it): the two
     * functions below only asked is_readable(), and then used whatever
     * file_get_contents() gave. An EMPTY file, and a folder of that name
     * (which PHP reads as nothing), both made a key out of nothing, which
     * is the same key on every installation in the world. A secret saved
     * like that looked protected and was not, and turned into a scramble
     * the moment the real key file was put back.
     *
     * WHERE THE LINE IS: only "nothing at all" is refused. The key is the
     * SHA-256 of the WHOLE file, so a file of any other length, however
     * short, is a working key that secrets are already stored under, and it
     * is used exactly as before. Refusing short files was considered and
     * REJECTED: it would lose every secret on such an installation.
     *
     * The same test is in Portal\Core\SecretSettings::keyFileContents().
     * Change both together; tools/settings-secrets-selftest.php holds them
     * to the same answer.
     */
    function portal_key_file_contents(): ?string
    {
        $keyPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'enc.key';
        // is_file() first: is_readable() says yes to a folder, and reading one
        // gives nothing at all plus a PHP notice on every request.
        if (is_file($keyPath) === false || is_readable($keyPath) === false) {
            return null;
        }
        $contents = file_get_contents($keyPath);

        return ($contents === false || $contents === '') ? null : $contents;
    }

    /**
     * Encrypt a plaintext setting value for secure database storage.
     *
     * @param string $plain The plaintext value to encrypt
     *
     * @return string Base64-encoded ciphertext (nonce prepended)
     *
     * @throws \RuntimeException When there is no key: see portal_key_file_contents().
     */
    function encrypt_setting(string $plain): string
    {
        $keyFile = portal_key_file_contents();
        if ($keyFile === null) {
            // Was "Encryption key file not found", which is untrue of a file
            // that is there and empty.
            throw new \RuntimeException(
                'Encryption key file is missing, is empty or cannot be read: '
                . PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'enc.key'
            );
        }

        // 🔑 Derive a 256-bit key from the key file contents
        $keyHash = hash('sha256', $keyFile);
        $key     = sodium_hex2bin($keyHash);

        // 🎲 Generate a random nonce for this encryption operation
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);

        // 📦 Prepend nonce to ciphertext and base64-encode for DB storage
        return base64_encode($nonce . $cipher);
    }

    /**
     * Decrypt an encrypted setting value retrieved from the database.
     *
     * @param string $encoded Base64-encoded ciphertext (nonce prepended)
     *
     * @return string Decrypted plaintext, or empty string on failure
     */
    function decrypt_setting(string $encoded): string
    {
        // 🔍 Handle empty or invalid input gracefully
        if ($encoded === '') {
            return '';
        }

        $bin = base64_decode($encoded, true);
        if ($bin === false) {
            return '';
        }

        // 🔓 Split nonce from ciphertext
        $nonce  = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        // No key (see portal_key_file_contents()): nothing can be opened.
        $keyFile = portal_key_file_contents();
        if ($keyFile === null) {
            return '';
        }

        // 🔑 Derive the same key used during encryption
        $keyHash = hash('sha256', $keyFile);
        $key     = sodium_hex2bin($keyHash);

        // 🔓 Attempt decryption - returns false if tampered or wrong key
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plain === false) {
            return '';
        }

        return $plain;
    }
}

/**
 * Assign a dotted-notation setting key into a nested array reference.
 * For example: assign_setting($arr, 'site.name', 'Portal') creates
 * $arr['site']['name'] = 'Portal'.
 *
 * @param array  $arr    Reference to the target array
 * @param string $dotKey Dot-separated key (e.g. "auth.ms365.clientID")
 * @param mixed  $value  The value to assign
 *
 * @return void
 */
function assign_setting(array &$arr, string $dotKey, mixed $value): void
{
    $parts = explode('.', $dotKey);
    $node  =& $arr;
    foreach ($parts as $part) {
        if (array_key_exists($part, $node) === false || is_array($node[$part]) === false) {
            $node[$part] = [];
        }
        $node =& $node[$part];
    }
    $node = $value;
}

// 🎛️ ---------------------------------------------------------------------------
// 4. Load database credentials (outside web-root for security)
// -----------------------------------------------------------------------------
// Credentials are stored in a PHP file that returns an associative array.
// This file must NOT be accessible via the web server.
// See: https://www.php.net/manual/en/function.is-readable.php

$authFile = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'auth_creds.php';
if (is_readable($authFile) === false) {
    http_response_code(500);
    error_log('❌ Auth credentials file missing: ' . $authFile);
    exit('Auth credentials file missing.');
}
$creds = require $authFile; // Returns array with db_host, db_user, db_pass, db_name, db_port

// 🛢️ ---------------------------------------------------------------------------
// 5. Open MySQLi connection
// -----------------------------------------------------------------------------
// Using MYSQLI_REPORT_STRICT enables exceptions instead of silent failures.
// See: https://www.php.net/manual/en/mysqli-driver.report-mode.php

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
try {
    $dbPort = 3306;
    if (isset($creds['db_port']) === true) {
        $dbPort = (int) $creds['db_port'];
    }

    // 🔒 Opened through Portal\Core\DbServer::connect() rather than with
    //    `new mysqli` here, since 12 October 2026. That one method sets the
    //    character set (utf8mb4, as this block always did) AND makes the
    //    connection strict, so the database refuses a value that does not fit
    //    instead of quietly changing it. The portal used to run as loosely as
    //    its host allowed; the reasons are beside the STRICT_MODE lists in
    //    DbServer.php. The installation wizard opens its connections the same
    //    way, so there is one place to get this right.
    //
    //    If the database refuses to be made strict, the portal still starts:
    //    stopping would lock people out of their own data on every page,
    //    including the page that explains why. DbServer writes the refusal to
    //    the error log, and the health page, the admin dashboard and Admin →
    //    Server Information each read the setting back and say so.
    $mysqli = \Portal\Core\DbServer::connect(
        $creds['db_host'],
        $creds['db_user'],
        $creds['db_pass'],
        $creds['db_name'],
        $dbPort
    );

} catch (\mysqli_sql_exception $e) {
    error_log('❌ DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection error.');
}

// 🌐 ---------------------------------------------------------------------------
// 5b. Multi-site pre-detection (before settings load)
// -----------------------------------------------------------------------------
// Lightweight site detection runs before settings are loaded so we know which
// siteID to use when fetching site-specific settings overrides.
// Returns siteID=1 if multisite is disabled or detection fails.
// See: _core/Site.php — preDetect() method
//
// Since 12 October 2026 (#502) this is the ONE place the organisation is
// decided, in all three ways of telling organisations apart. Two things
// follow that the lines above do not say:
//   - When organisations are told apart by the sign-in session, preDetect()
//     takes a read-only look at the session here (Auth::peekSession()) and
//     closes it again at once. No session is left active, so the rest of
//     this file runs exactly as before; web/public_html/index.php starts the
//     session properly afterwards. A request with no session cookie, and
//     anything run from the command line, is not given a session at all.
//   - "Returns siteID=1 if detection fails" is still what this file gets,
//     because the lines below need a number. But a failed detection is now
//     remembered (Site::detectionFailed()), and web/public_html/index.php
//     then shows a "try again" page (SiteGate) instead of organisation 1's
//     pages. A front controller that does not call SiteGate does not get
//     that protection.
//
// 🛡️ Wrapped in try/catch because this runs BEFORE the global exception
//    handler is registered (line ~420) and BEFORE settings are loaded. Under
//    PHP 8.1+ strict mysqli mode any error in the detection queries (tblSites
//    schema drift, FK issue, transient DB error) would otherwise produce a
//    bare HTTP 500 for every request to the portal. Falling back to
//    siteID=1 keeps the request alive with the default site selected.

$preDetectedSiteID = 1;
try {
    $preDetectedSiteID = \Portal\Core\Site::preDetect($mysqli);
} catch (\mysqli_sql_exception $e) {
    error_log('[WebMS-Intra] Site::preDetect failed, falling back to siteID=1: ' . $e->getMessage());
}

// 🗝️ ---------------------------------------------------------------------------
// 6. Settings loader – dot-notation → multidimensional array
// -----------------------------------------------------------------------------
// Reads all settings from tblSettings, decrypts sensitive values, and builds
// a nested PHP array using the dot-separated settingKey as the hierarchy.
// Example: 'auth.ms365.clientID' → $SETTINGS['auth']['ms365']['clientID']
//
// Site-aware: fetches global defaults (siteID IS NULL) and site-specific
// overrides (siteID = N). Global defaults are loaded first, then site-specific
// values overwrite them so the final array reflects the merged config.

$SETTINGS = [];

// 🏢 THE INSTALLATION-WIDE LAYER, KEPT SEPARATELY (#569, 29 October 2026).
//
//    $SETTINGS below is the MERGED answer: installation-wide rows first, then
//    this organisation's own laid over them. That is right for almost every
//    setting, where an organisation's choice should simply win.
//
//    It is WRONG for a setting that is a LIMIT. One installation may serve
//    many organisations, and the owner's rule for a limit is that the
//    installation's value takes precedence over an organisation's: an
//    organisation may hold itself to a LOWER limit, never raise its own above
//    what the installation allows. Otherwise one organisation on a shared
//    install could help itself to resources the host had deliberately capped.
//
//    The merged array cannot express that, because by then the two values have
//    become one and there is no way to tell "the organisation chose 500" from
//    "the installation allows 500". This second array keeps ONLY the
//    installation-wide rows, so a reader that needs the distinction has it
//    without a second query — the loop below is already reading both layers
//    and was simply throwing the first away.
//
//    It is a LAYER, not a list of limits: nothing here decides which settings
//    are limits. `EventRecurrence::limitsFrom()` is the first reader to use
//    it. Code that just wants a setting's value should keep using $SETTINGS.
//
//    IT IS HANDED TO `Portal\Core\InstallationSettings` just below, and that
//    is how a PAGE reads it. This variable alone is not enough: Router.php
//    does `global $mysqli, $SETTINGS;` before loading a page and those two are
//    the only things a page inherits, so a third global is simply not there
//    (see .claude/rules/app-pages.md). The first version of this got that
//    wrong and an organisation's 500-date series sailed past an installation
//    limit of 6, because the page read an empty layer.
$SETTINGS_INSTALLATION = [];

// 🤫 From here on this request holds REAL secrets (see the load just below),
//    so the record PHP keeps of an error must not carry what a function was
//    called with.
//
//    WHY: when something fails, PHP notes each function on the way there
//    together with the first 15 characters of every argument, unless this
//    option is on. The portal stores that note (Logger::exception() writes
//    it to tblErrors, which Admin, Error log shows to every administrator,
//    and the alert e-mail carries it). So one failure inside any code that
//    was handed a secret would have written the start of that secret into
//    the error log. Run on 12 October 2026 with a planted fault: the start
//    of a client secret, typed while the key file was missing, turned up on
//    that page, for an administrator of one organisation too.
//
//    Most hosts already have it on (PHP's own production settings do). This
//    makes it so everywhere, for every release channel: a development copy
//    holds real secrets too. The file and line of each step are still kept.
//
//    WHAT IT CANNOT DO: stop code that puts a secret into the TEXT of an
//    error it raises itself. Nor does it change what else is stored with an
//    error: the address that was asked for is, and a scheduled job's
//    address carries that job's token.
ini_set('zend.exception_ignore_args', '1');

// 🛡️ Wrapped in try/catch because this runs BEFORE the global exception
//    handler is registered (line ~420). Under PHP 8.1+ strict mysqli mode
//    any error here — tblSettings missing/corrupt, connection blip, charset
//    issue — would otherwise produce a bare HTTP 500 for every request.
//    Falling back to an empty $SETTINGS array allows the portal to render
//    a degraded page (logged for admin diagnosis) instead of fataling.
try {
    $settingsStmt = $mysqli->prepare(
        'SELECT settingKey, settingValue, isSensitive, siteID '
        . 'FROM tblSettings '
        . 'WHERE siteID IS NULL OR siteID = ? '
        . 'ORDER BY siteID IS NULL DESC'
    );
    if ($settingsStmt !== false) {
        $settingsStmt->bind_param('i', $preDetectedSiteID);
        $settingsStmt->execute();
        $result = $settingsStmt->get_result();
        $secretsReadAsStored = '';
        while ($row = $result->fetch_assoc()) {
            // 🔢 ONE SETTING IS READ FROM THE INSTALLATION'S OWN ROW ONLY: the
            //    version the database is recorded at (#499). The upgrade gate
            //    (Maintenance) compares it with the files' version to decide
            //    whether the portal is closed. A row of the same name saved
            //    for ONE organisation used to be laid over it like any other
            //    setting, and one that said a newer version opened the
            //    portal, for that organisation, with a database upgrade
            //    waiting (found 13 October 2026). Such a row is passed over
            //    here, so nothing in the portal ever sees it; the Settings
            //    page refuses to make one (Maintenance::lockedInGeneralEditor()).
            //    The name is written out, not asked of that class: this runs
            //    before anything can be trusted to be loaded, and files reach
            //    a shared host one at a time. Only rows of ONE organisation
            //    are tested, so an installation with none pays nothing.
            if ($row['siteID'] !== null && strcasecmp((string) $row['settingKey'], 'portal.installed_version') === 0) {
                continue;
            }

            $value = $row['settingValue'];

            // 🔓 A secret (a row whose isSensitive mark is on) is handed to
            //    the rest of the portal as its REAL value.
            //
            //    WHAT WAS HERE, AND WHY IT NEVER RAN (#497):
            //        if ($row['isSensitive'] === '1' && …) { decrypt_setting($value) }
            //    This row comes from a prepared statement, which hands the
            //    mark back as the NUMBER 1. The text '1' never equals it, so
            //    no secret was ever decrypted and every reader got the
            //    scramble (run on 12 October 2026, MySQL 8.0.36 and 8.4.11).
            //
            //    WHY NOT JUST CORRECT THAT COMPARISON: decrypt_setting()
            //    throws on a marked row that holds short plain text, and a
            //    fresh install seeds one (auth.ms365.tenantOnly = 'true').
            //    This runs before any error handler exists, and the catch
            //    below only catches database errors, so every page would
            //    have stopped. SecretSettings::forReader() never throws: a
            //    marked row this installation's key does not open is used
            //    exactly as stored, which is what readers are handed today.
            //    The reasons, and what it cannot do, are at the top of
            //    web/_core/SecretSettings.php.
            //
            //    WHY IT IS WRAPPED ANYWAY: files reach a shared host one at a
            //    time. If this file arrives before SecretSettings.php, the
            //    class is not there yet, and without the catch every page
            //    would stop until it was. With it, that request reads the
            //    row as stored, as before this change. One try per row, not
            //    one round the loop: a single try would drop every setting
            //    after the first row that failed.
            //
            //    THE NAME IS PASSED TOO, so that a secret which cannot be read
            //    at all is remembered by name for step 7c below, for the bot
            //    check (Captcha.php) and for the health page.
            try {
                $value = \Portal\Core\SecretSettings::forReader($row['isSensitive'], $value, (string) $row['settingKey']);
            } catch (\Throwable $e) {
                $secretsReadAsStored = $e->getMessage();
            }

            assign_setting($SETTINGS, $row['settingKey'], $value);

            // 🏢 And again into the installation-wide layer, for the rows
            //    that ARE installation-wide. Kept so that a limit can be
            //    capped by the installation rather than overridden by an
            //    organisation; see the note where $SETTINGS_INSTALLATION is
            //    declared.
            if ($row['siteID'] === null) {
                assign_setting($SETTINGS_INSTALLATION, $row['settingKey'], $value);
            }
        }
        $settingsStmt->close();

        // 🏢 Hand the installation-wide layer over, so a PAGE can read it:
        //    a page inherits only $mysqli and $SETTINGS, never a third global.
        //    Wrapped because files reach a shared host one at a time; if this
        //    file arrives before the class, the request carries on with the
        //    layer simply unknown, which every reader is written to survive.
        try {
            \Portal\Core\InstallationSettings::remember($SETTINGS_INSTALLATION);
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] The installation-wide settings layer could not be kept: ' . $e->getMessage());
        }

        if ($secretsReadAsStored !== '') {
            // One line for the request, not one per row, and never a value.
            error_log('[WebMS-Intra] Secret settings were read as stored, not decrypted: ' . $secretsReadAsStored);
        }
    } else {
        // 🚨 Settings query failed — log error so it's visible in admin error log
        error_log('[WebMS-Intra] CRITICAL: Failed to prepare settings query: ' . $mysqli->error);
    }
} catch (\mysqli_sql_exception $e) {
    error_log('[WebMS-Intra] CRITICAL: Settings load threw mysqli_sql_exception, continuing with empty settings: ' . $e->getMessage());
}

// 🏷️ ---------------------------------------------------------------------------
// 6b. Security / SEO / branding response headers
// -----------------------------------------------------------------------------
// MOVED HERE (was originally emitted right after step 1, directory constants
// — BEFORE $SETTINGS existed) because every `?? default` fallback below reads
// $SETTINGS, which is only assigned starting at step 6 immediately above.
// At the old location $SETTINGS was undefined, so every `??` fallback always
// won: `branding.hidePoweredBy` was dead (X-Powered-By always sent), the
// documented `portal.headers.*` overrides never applied, and — worst —
// X-Robots-Tag ALWAYS sent `noindex, nofollow` even for a tenant that had
// enabled indexing via `site.allowIndexing`, silently blocking SEO. Settings
// are now fully loaded (including site-specific overrides — see step 6's own
// comment) so these reads resolve real tenant configuration. Still runs
// before Router::dispatch() / any app output, satisfying the "headers before
// body" requirement the original placement was chasing.
//
// 🏷️ Replace the default `X-Powered-By: PHP/8.x.y` response header with our
// own branded value (matching the in-page "Powered by" attribution + the
// <meta name="generator"> tag). The PHP default leaks the backend stack
// AND the exact PHP version, useful only to attackers looking up CVEs.
//
// The actual header value depends on:
//   - `branding.hidePoweredBy = 'true'`  → strip entirely (no brand reveal)
//   - else  → "<product.name>/<portal version>"  where product.name comes from
//             the settings row (set by the installer's organisation-type step)
//             or falls back to PORTAL_PRODUCT_NAME_DEFAULT.
//
// See: https://www.php.net/manual/en/function.header.php
// See: web/_core/brand-defaults.php for the product brand layer (issue #296).
if (function_exists('header_remove') === true) {
    header_remove('X-Powered-By');
}
$hidePoweredBy = ($SETTINGS['branding']['hidePoweredBy'] ?? 'false') === 'true';
if ($hidePoweredBy === false) {
    // 📋 Resolve product name + version with full fallback chain.
    //    The header() call refuses values with CRLF since PHP 5.1.2, so even
    //    a malicious admin editing the setting cannot smuggle headers — the
    //    call emits a warning and skips rather than including them.
    $brandedName    = (string) ($SETTINGS['product']['name']    ?? PORTAL_PRODUCT_NAME_DEFAULT);
    $brandedVersion = (string) ($SETTINGS['portal']['version']  ?? PORTAL_VERSION);
    // 🧹 Replace whitespace in the brand name (e.g. "WebMS Intra" → "WebMS-Intra")
    //    so the header parses as a single token. Header field values can contain
    //    spaces in theory, but tokens (the de-facto X-Powered-By convention) cannot.
    $brandedNameToken = (string) preg_replace('/\s+/', '-', trim($brandedName));
    if ($brandedNameToken === '') {
        $brandedNameToken = PORTAL_PRODUCT_NAME_DEFAULT;
    }
    header('X-Powered-By: ' . $brandedNameToken . '/' . $brandedVersion);
}

// 🛡️ Baseline security response headers (#160)
// -----------------------------------------------------------------------------
// Industry-standard defence-in-depth. All headers are unconditional except
// HSTS (only sent when the request arrived over HTTPS, which is the case on
// our own installation, where the host redirects http to https at its
// edge). Headers are
// sent BEFORE any app handler so they apply to every response including
// error pages and the maintenance gate.
//
// Each can be overridden via tblSettings (`portal.headers.<header_name>`).
// Set the setting to an empty string to suppress the header for that key.
if (headers_sent() === false) {
    // 🔒 Strict-Transport-Security — only on HTTPS requests. NOT preloaded;
    //    we want to retain the option to revert (see issue #160 caveats).
    $isHttps = (isset($_SERVER['HTTPS']) === true && $_SERVER['HTTPS'] === 'on')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($isHttps === true) {
        $hsts = (string) ($SETTINGS['portal']['headers']['strict_transport_security']
                       ?? 'max-age=31536000; includeSubDomains');
        if ($hsts !== '') {
            header('Strict-Transport-Security: ' . $hsts);
        }
    }

    // 🔐 Permissions-Policy — disable APIs we don't use. Widening individual
    //    permissions to `(self)` is a deliberate per-feature decision.
    $perms = (string) ($SETTINGS['portal']['headers']['permissions_policy']
                    ?? 'camera=(), microphone=(), geolocation=(), payment=(), '
                     . 'usb=(), magnetometer=(), accelerometer=(), gyroscope=(), '
                     . 'browsing-topics=(), interest-cohort=()');
    if ($perms !== '') {
        header('Permissions-Policy: ' . $perms);
    }

    // 🪟 COOP / CORP — cross-origin isolation (Spectre mitigation + resource
    //    leak prevention). `same-origin` is the strictest sensible default.
    $coop = (string) ($SETTINGS['portal']['headers']['coop'] ?? 'same-origin');
    if ($coop !== '') {
        header('Cross-Origin-Opener-Policy: ' . $coop);
    }
    $corp = (string) ($SETTINGS['portal']['headers']['corp'] ?? 'same-origin');
    if ($corp !== '') {
        header('Cross-Origin-Resource-Policy: ' . $corp);
    }

    // 📌 Referrer-Policy — limit what's sent to cross-origin links.
    $referrer = (string) ($SETTINGS['portal']['headers']['referrer_policy']
                       ?? 'strict-origin-when-cross-origin');
    if ($referrer !== '') {
        header('Referrer-Policy: ' . $referrer);
    }

    // 🛡️ X-Content-Type-Options — defeats MIME-sniffing attacks. Always on.
    header('X-Content-Type-Options: nosniff');

    // 🖼️ X-Frame-Options — block clickjacking via iframe embedding.
    //    `SAMEORIGIN` allows our own iframes (e.g. the help app inside admin).
    $xfo = (string) ($SETTINGS['portal']['headers']['x_frame_options'] ?? 'SAMEORIGIN');
    if ($xfo !== '') {
        header('X-Frame-Options: ' . $xfo);
    }

    // 🤖 X-Robots-Tag — intranet by default; per-site override (#247).
    //    Mirrors the <meta name="robots"> logic from header.php so API
    //    responses, redirects, and error pages that don't render the full
    //    template still carry the correct policy.
    //    Indexability is governed by the same `site.allowIndexing` +
    //    `site.allowAiIndexing` settings as the meta tags so policy stays
    //    consistent across HTML and non-HTML responses.
    $allowIndex   = (string) ($SETTINGS['site']['allowIndexing']   ?? 'false') === 'true';
    $allowAiIndex = (string) ($SETTINGS['site']['allowAiIndexing'] ?? 'false') === 'true';
    $robotsParts  = [];
    if ($allowIndex === false) {
        $robotsParts[] = 'noindex';
        $robotsParts[] = 'nofollow';
    }
    if ($allowAiIndex === false) {
        $robotsParts[] = 'noai';
        $robotsParts[] = 'noimageai';
    }
    if (count($robotsParts) > 0) {
        header('X-Robots-Tag: ' . implode(', ', $robotsParts));
    }
}

// 🏛️ ---------------------------------------------------------------------------
// 7. Initialise App registry
// -----------------------------------------------------------------------------
// The App class provides static access to core resources (db, settings, user)
// as a cleaner alternative to the `global` keyword. Both patterns work.
// See: core/App.php

\Portal\Core\App::init($mysqli, $SETTINGS);

// 🌐 ---------------------------------------------------------------------------
// 7b. Initialise Site context
// -----------------------------------------------------------------------------
// Completes multi-site initialisation now that settings are fully loaded.
// Uses the pre-detected siteID from step 5b plus the full settings array
// to determine detection mode, branding, and site-level permissions.
// See: core/Site.php

\Portal\Core\Site::init($mysqli, $SETTINGS, $preDetectedSiteID);

// 🕐 ---------------------------------------------------------------------------
// 7b-2. PHP's time zone: the organisation's own (#604)
// -----------------------------------------------------------------------------
// From here on every `date()` on every page is in the organisation's zone.
// Site::timezone() is the ONE place that answers which zone that is, and it
// always answers a name PHP knows (UTC when none has been chosen), so this
// cannot fail.
//
// WHAT WAS HERE, AND WHY IT MOVED. This used to sit just above step 7, and
// read the `site.timezone` SETTING, under a comment saying the organisation's
// own row took priority. It never read the row. So an installation had two
// places for one fact, and the pages followed one while the mail lines, the
// rota reminder and giving retention followed the other. It moved below
// Site::init() because Site has to know the organisation before it can be
// asked. Nothing between the old place and this one uses the clock: only
// App::init() and Site::init() run there, and neither reads the time (both
// were read through for this on 13 October 2026).
//
// On a database that has not run migration 217 yet, Site::timezone() answers
// exactly what the old lines did, from the same setting, so the hour between
// new files arriving and Upgrade being pressed moves nothing.
date_default_timezone_set(\Portal\Core\Site::timezone());

// 🚨 ---------------------------------------------------------------------------
// 7c. Say so if a stored secret cannot be read (#497)
// -----------------------------------------------------------------------------
// A secret that looks encrypted and that this installation's key file does
// not open is handed on exactly as stored (step 6), so nothing stops; but
// whatever uses it will not work, and nobody would know why. This writes it
// down, by name: see SecretSettings::reportUnreadable() for where, how often,
// and why not while the portal is closed for an upgrade.
//
// It costs nothing when every secret reads. It sits AFTER App::init and
// Site::init, because the error log needs both, and BEFORE the handlers
// below, so that nothing here can be the first thing they catch. It is
// wrapped as well: reporting a problem must never create one, and the class
// file may not have arrived yet on a host that receives files one at a time.
try {
    \Portal\Core\SecretSettings::reportUnreadable($mysqli);
} catch (\Throwable $e) {
    error_log('[WebMS-Intra] Could not report on stored secrets: ' . get_class($e));
}

// ⏱️ ---------------------------------------------------------------------------
// 8. Initialise Debug timer
// -----------------------------------------------------------------------------
// The Debug class records performance metrics visible via ?debug=true (admin only).
// The start time was captured at the top of this file (PORTAL_START_TIME constant).
// See: core/Debug.php

\Portal\Core\Debug::start();

// 📜 ---------------------------------------------------------------------------
// 9. Global error / exception handlers
// -----------------------------------------------------------------------------
// These handlers log all PHP errors and uncaught exceptions to tblErrors via
// the Logger class, providing a centralised error audit trail.
// See: https://www.php.net/manual/en/function.set-error-handler.php
// See: https://www.php.net/manual/en/function.set-exception-handler.php

set_error_handler(function (int $errno, string $errstr, string $file, int $line): bool {
    // 📝 Log the error to tblErrors for the admin error log viewer
    \Portal\Core\Logger::phpError($errno, $errstr, $file, $line);

    // Return false to allow PHP's built-in error handler to run as well
    return false;
});

set_exception_handler(function (\Throwable $ex): void {
    // 📝 Log the exception to tblErrors
    \Portal\Core\Logger::exception($ex);
    http_response_code(500);

    // 🐛 Show detailed error info only in debug mode for admin users
    if (\Portal\Core\App::isDebug() === true) {
        echo '<pre>' . htmlspecialchars((string) $ex, ENT_QUOTES, 'UTF-8') . '</pre>';
    } else {
        // 🚫 Show a generic error for non-admin users
        \Portal\Core\Router::renderError(500);
    }
});

// 🌐 ---------------------------------------------------------------------------
// 10. Internationalisation (i18n)
// -----------------------------------------------------------------------------
// Initialise the translation framework. Determines the active locale from
// user preference, session, or browser Accept-Language header.
// Provides the global t() helper function for translating strings.
// See: core/I18n.php

// 📋 Define PORTAL_LANG constant for the language directory
if (defined('PORTAL_LANG') === false) {
    define('PORTAL_LANG', PORTAL_ROOT . DIRECTORY_SEPARATOR . '_lang');
}

\Portal\Core\I18n::init();

// 📋 Handle ?lang= query parameter for language switching.
// #509 point 2: I18n::switchLocale() takes a `string`, so a REQUEST sent as
// a list — ?lang[]=x — used to arrive here as an array, and PHP's typed
// parameter refused it with a TypeError: a 500 and one error row, on every
// address, open or closed, because this runs before the maintenance gate.
// Anything that is not text is now simply ignored rather than crashing.
if (isset($_GET['lang']) === true && is_string($_GET['lang']) === true && $_GET['lang'] !== '') {
    \Portal\Core\I18n::switchLocale($_GET['lang']);
    // 🔀 Redirect to same page without the lang parameter to avoid sticky URL
    $currentUri = $_SERVER['REQUEST_URI'] ?? '/';
    $parsed     = parse_url($currentUri);
    $path       = $parsed['path'] ?? '/';
    parse_str($parsed['query'] ?? '', $queryParams);
    unset($queryParams['lang']);
    $newQuery = http_build_query($queryParams);
    $redirect = $path . ($newQuery !== '' ? '?' . $newQuery : '');
    header('Location: ' . $redirect, true, 302);
    exit();
}

// 📋 Load user locale from DB into session on login (if not already set)
//
// 🛡️ Wrapped in try/catch — although the global exception handler IS active
//    by this point (set above at line ~420), locale lookup failure should
//    NOT 500 the whole request. The user's row may have been deleted, the
//    `locale` column may be missing on a fresh install, or the DB may blip
//    transiently. In any of those cases we silently fall back to whatever
//    locale I18n already picked from the session / Accept-Language header,
//    which is far better UX than a 500.
if (session_status() === PHP_SESSION_ACTIVE
    && isset($_SESSION['user_id']) === true
    && isset($_SESSION['user_locale']) === false
) {
    try {
        $localeStmt = $mysqli->prepare('SELECT locale FROM tblUsers WHERE userID = ? LIMIT 1');
        if ($localeStmt !== false) {
            $localeUserId = (int) $_SESSION['user_id'];
            $localeStmt->bind_param('i', $localeUserId);
            $localeStmt->execute();
            $localeRow = $localeStmt->get_result()->fetch_assoc();
            $localeStmt->close();
            if ($localeRow !== null && $localeRow['locale'] !== null && $localeRow['locale'] !== '') {
                $_SESSION['user_locale'] = $localeRow['locale'];
                \Portal\Core\I18n::setLocale($localeRow['locale']);
            }
        }
    } catch (\mysqli_sql_exception $e) {
        error_log('[WebMS-Intra] User locale lookup failed, continuing with default locale: ' . $e->getMessage());
    }
}

/**
 * Global translation helper function.
 * Shortcut for I18n::t() — use in templates and app files.
 *
 * @param string $key    Translation key
 * @param array  $params Replacement parameters
 *
 * @return string Translated string
 */
if (function_exists('t') === false) {
    function t(string $key, array $params = []): string
    {
        return \Portal\Core\I18n::t($key, $params);
    }
}

// 📦 ---------------------------------------------------------------------------
// 11. Service Container
// -----------------------------------------------------------------------------
// Register core services in the lightweight DI container. This coexists with
// the existing static singletons, enabling gradual migration to injectable deps.
// See: core/Container.php

$container = \Portal\Core\Container::getInstance();
$container->instance('db', $mysqli);
$container->instance('settings', $SETTINGS);
$container->set('site', function () {
    return \Portal\Core\Site::current();
});

// 🏁 Bootstrap completed – Router will now take over in the front controller.
