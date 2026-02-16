<?php
// Path: core/bootstrap.php
/**
 * -----------------------------------------------------------------------------
 * Portal Core Bootstrap 🚀
 * -----------------------------------------------------------------------------
 * Prepares environment constants, opens the database connection, loads settings
 * into a multidimensional array, initialises the App registry and Debug timer,
 * registers the PSR-4-lite autoloader, and wires global error/exception handlers.
 *
 * This file is loaded by every front controller (public_html/index.php,
 * public_html_dev/index.php) and can also be conditionally loaded by
 * individual app files for standalone/debug use.
 *
 * All conditional logic uses full IF notation for human readability, per code
 * style guidelines.
 *
 * @see       https://www.php.net/manual/en/language.constants.predefined.php
 * @see       https://www.php.net/manual/en/function.spl-autoload-register.php
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   MIT
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

define('PORTAL_CORE',   PORTAL_ROOT . DIRECTORY_SEPARATOR . 'core');
define('PORTAL_APPS',   PORTAL_ROOT . DIRECTORY_SEPARATOR . 'apps');
define('PORTAL_VENDOR', PORTAL_ROOT . DIRECTORY_SEPARATOR . 'vendor');
define('PORTAL_SQL',    PORTAL_ROOT . DIRECTORY_SEPARATOR . 'sql');

// 🌍 Determine runtime environment flag (dev | prod)
// Priority: 1) PORTAL_ENV env var  2) directory name detection  3) default 'dev'
// Primary directories: public_html (prod), public_html_dev (dev)
// Legacy alpha_html / beta_html checks kept for backwards compatibility
// See: https://www.php.net/manual/en/function.getenv.php
$env = getenv('PORTAL_ENV');
if ($env === false || $env === '') {
    // 🔍 Auto-detect from the front-controller directory name
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if (str_contains($docRoot, 'public_html_dev') === true || str_contains($docRoot, 'alpha_html') === true) {
        $env = 'dev';
    } elseif (str_contains($docRoot, 'beta_html') === true) {
        $env = 'beta';
    } elseif (str_contains($docRoot, 'public_html') === true) {
        $env = 'prod';
    } else {
        $env = 'dev'; // Default to dev for safety
    }
}
define('PORTAL_ENV', $env);

// 🕐 Default timezone until settings load (overridden later)
@date_default_timezone_set('UTC');

// 📝 ---------------------------------------------------------------------------
// 2. PSR-4-lite autoload (no Composer available on DreamHost shared hosting)
// -----------------------------------------------------------------------------
// Maps namespace prefixes to directories so classes can be loaded on demand.
// Portal\Core\Router → core/Router.php
// Portal\App\Expenses → apps/Expenses.php
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

// 🗝️ ---------------------------------------------------------------------------
// 6. Settings loader – dot-notation → multidimensional array
// -----------------------------------------------------------------------------
// Reads all settings from tblSettings, decrypts sensitive values, and builds
// a nested PHP array using the dot-separated settingKey as the hierarchy.
// Example: 'auth.ms365.clientID' → $SETTINGS['auth']['ms365']['clientID']

$SETTINGS = [];

$settingsStmt = $mysqli->prepare('SELECT settingKey, settingValue, isSensitive FROM tblSettings');
if ($settingsStmt !== false) {
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
}

// 🕐 Update timezone to the configured value (overrides the UTC default above)
if (isset($SETTINGS['site']['timezone']) === true && $SETTINGS['site']['timezone'] !== '') {
    @date_default_timezone_set($SETTINGS['site']['timezone']);
}

// 🏛️ ---------------------------------------------------------------------------
// 7. Initialise App registry
// -----------------------------------------------------------------------------
// The App class provides static access to core resources (db, settings, user)
// as a cleaner alternative to the `global` keyword. Both patterns work.
// See: core/App.php

\Portal\Core\App::init($mysqli, $SETTINGS);

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
        echo '<pre>' . htmlspecialchars((string) $ex, ENT_QUOTES) . '</pre>';
    } else {
        // 🚫 Show a generic error for non-admin users
        \Portal\Core\Router::renderError(500);
    }
});

// 🏁 Bootstrap completed – Router will now take over in the front controller.
