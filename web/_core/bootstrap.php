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
 * This file is loaded by the single front controller (public_html/index.php),
 * and can also be conditionally loaded by individual app files for
 * standalone/debug use. Branch-based deploy targets the same source dir to
 * different server destinations (alpha → public_html_dev/ on server,
 * beta → public_html_beta/ on server, main → public_html/ on server) — so
 * there's no longer a per-channel front controller in the repo.
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
 * @version   0.1.0
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

// 🌍 Determine runtime environment flag (dev | beta | prod)
// Priority: 1) PORTAL_ENV env var  2) directory name detection  3) default 'dev'
// Primary directories: public_html (prod), public_html_beta (beta), public_html_dev (dev)
// Legacy alpha_html / beta_html checks kept for backwards compatibility.
// IMPORTANT: order matters — public_html_dev / public_html_beta must be tested
// BEFORE public_html, since str_contains() would otherwise match "public_html"
// inside the beta/dev directory names and misclassify them as prod.
// See: https://www.php.net/manual/en/function.getenv.php
$env = getenv('PORTAL_ENV');
if ($env === false || $env === '') {
    // 🔍 Auto-detect from the front-controller directory name
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if (str_contains($docRoot, 'public_html_dev') === true || str_contains($docRoot, 'alpha_html') === true) {
        $env = 'dev';
    } elseif (str_contains($docRoot, 'public_html_beta') === true || str_contains($docRoot, 'beta_html') === true) {
        $env = 'beta';
    } elseif (str_contains($docRoot, 'public_html') === true) {
        $env = 'prod';
    } else {
        $env = 'dev'; // Default to dev for safety
    }
}
define('PORTAL_ENV', $env);

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
// $SETTINGS is read directly because App::init() runs later in bootstrap.
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
// portal.millrdsdacambridge.uk via DreamHost's edge-redirect). Headers are
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

// 🛡️ PHP error display hardening
// -----------------------------------------------------------------------------
// In production we MUST NOT echo PHP errors / warnings into the response —
// they can leak file paths, query fragments, and credential variable names.
// Errors are still captured by Logger / tblErrors for admin diagnosis.
//
// In dev / beta we surface errors at the top of the page so developers see
// them during active work. Admin staff on beta are expected to be technical.
//
// See: https://www.php.net/manual/en/errorfunc.configuration.php
if (PORTAL_ENV === 'prod') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('html_errors', '0');
    // Errors are still REPORTED so PHP's logger sees them; just not DISPLAYED.
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

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
     * Encrypt a plaintext setting value for secure database storage.
     *
     * @param string $plain The plaintext value to encrypt
     *
     * @return string Base64-encoded ciphertext (nonce prepended)
     */
    function encrypt_setting(string $plain): string
    {
        $keyPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'enc.key';

        if (is_readable($keyPath) === false) {
            throw new \RuntimeException('Encryption key file not found: ' . $keyPath);
        }

        // 🔑 Derive a 256-bit key from the key file contents
        $keyHash = hash('sha256', file_get_contents($keyPath));
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

        $keyPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'enc.key';

        if (is_readable($keyPath) === false) {
            return '';
        }

        // 🔑 Derive the same key used during encryption
        $keyHash = hash('sha256', file_get_contents($keyPath));
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

    $mysqli = new mysqli(
        $creds['db_host'],
        $creds['db_user'],
        $creds['db_pass'],
        $creds['db_name'],
        $dbPort
    );

    // 🔤 Set charset to utf8mb4 for full Unicode support (including emoji)
    // See: https://dev.mysql.com/doc/refman/8.0/en/charset-unicode-utf8mb4.html
    $mysqli->set_charset('utf8mb4');

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
        while ($row = $result->fetch_assoc()) {
            $value = $row['settingValue'];

            // 🔓 Decrypt sensitive settings (API keys, secrets etc)
            if ($row['isSensitive'] === '1' && $value !== '' && $value !== null) {
                $value = decrypt_setting($value);
            }

            assign_setting($SETTINGS, $row['settingKey'], $value);
        }
        $settingsStmt->close();
    } else {
        // 🚨 Settings query failed — log error so it's visible in admin error log
        error_log('[WebMS-Intra] CRITICAL: Failed to prepare settings query: ' . $mysqli->error);
    }
} catch (\mysqli_sql_exception $e) {
    error_log('[WebMS-Intra] CRITICAL: Settings load threw mysqli_sql_exception, continuing with empty settings: ' . $e->getMessage());
}

// 🕐 Update timezone to the configured value (overrides the UTC default above)
// Site-specific timezone from tblSites takes priority over the settings value
if (isset($SETTINGS['site']['timezone']) === true && $SETTINGS['site']['timezone'] !== '') {
    $tz = $SETTINGS['site']['timezone'];
    if (in_array($tz, timezone_identifiers_list(), true) === true) {
        date_default_timezone_set($tz);
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

// 📋 Handle ?lang= query parameter for language switching
if (isset($_GET['lang']) === true && $_GET['lang'] !== '') {
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
