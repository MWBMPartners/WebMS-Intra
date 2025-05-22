<?php
/**
 * -----------------------------------------------------------------------------
 * Portal Core Bootstrap 🚀
 * -----------------------------------------------------------------------------
 * Prepares environment constants, opens the database connection, loads settings
 * into a multidimensional array, and wires global error / exception handlers.
 * -----------------------------------------------------------------------------
 * All conditional logic uses full IF notation for human readability, per code
 * style guidelines.
 * -----------------------------------------------------------------------------
 * @package   Portal\Core
 * @author    Cambridge SDA
 * @copyright 2025
 * @license   MIT
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🗂️ ---------------------------------------------------------------------------
// 1. Directory constants – platform‑neutral
// -----------------------------------------------------------------------------

define('PORTAL_ROOT', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));

define('PORTAL_CORE',   PORTAL_ROOT . DIRECTORY_SEPARATOR . 'core');
define('PORTAL_APPS',   PORTAL_ROOT . DIRECTORY_SEPARATOR . 'apps');
define('PORTAL_VENDOR', PORTAL_ROOT . DIRECTORY_SEPARATOR . 'vendor');

// Determine runtime environment flag (dev | beta | prod)
$env = getenv('PORTAL_ENV');
if ($env === false || $env === '') {
    $env = 'dev';
}
define('PORTAL_ENV', $env);

// Default timezone until settings load
@date_default_timezone_set('UTC');

// 🎛️ ---------------------------------------------------------------------------
// 2. Load database credentials (outside web‑root)
// -----------------------------------------------------------------------------
$authFile = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'auth_creds.php';
if (is_readable($authFile) === false) {
    http_response_code(500);
    exit('Auth credentials file missing.');
}
$creds = require $authFile; // returns array with db_host, db_user, …

// 🛢️ ---------------------------------------------------------------------------
// 3. Open MySQLi connection
// -----------------------------------------------------------------------------
mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
try {
    $dbPort = 3306;
    if (isset($creds['db_port'])) {
        $dbPort = $creds['db_port'];
    }

    $mysqli = new mysqli(
        $creds['db_host'],
        $creds['db_user'],
        $creds['db_pass'],
        $creds['db_name'],
        $dbPort
    );
    $mysqli->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    error_log('❌ DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection error.');
}

// 🗝️ ---------------------------------------------------------------------------
// 4. Settings loader – dot‑notation → multidimensional array
// -----------------------------------------------------------------------------
$SETTINGS = [];

$settingsStmt = $mysqli->prepare('SELECT settingKey, settingValue, isSensitive FROM tblSettings');
$settingsStmt->execute();
$result = $settingsStmt->get_result();
while ($row = $result->fetch_assoc()) {
    $value = $row['settingValue'];
    if ($row['isSensitive'] === '1') {
        $value = decrypt_setting($value);
    }
    assign_setting($SETTINGS, $row['settingKey'], $value);
}
$settingsStmt->close();

// Update timezone once we have site settings
if (isset($SETTINGS['site']) && isset($SETTINGS['site']['timezone']) && $SETTINGS['site']['timezone'] !== '') {
    @date_default_timezone_set($SETTINGS['site']['timezone']);
}

// 🔐 ---------------------------------------------------------------------------
// 5. Crypto helpers (libsodium wrapper)
// -----------------------------------------------------------------------------
if (function_exists('encrypt_setting') === false) {
    function encrypt_setting(string $plain): string
    {
        $keyPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'enc.key';
        $keyHash = hash('sha256', file_get_contents($keyPath));
        $key     = sodium_hex2bin($keyHash);

        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);

        return base64_encode($nonce . $cipher);
    }

    function decrypt_setting(string $encoded): string
    {
        $bin = base64_decode($encoded, true);
        if ($bin === false) {
            return '';
        }

        $nonce  = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $keyPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'enc.key';
        $keyHash = hash('sha256', file_get_contents($keyPath));
        $key     = sodium_hex2bin($keyHash);

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plain === false) {
            return '';
        }
        return $plain;
    }
}

/**
 * Assign a dotted‑notation setting key into a nested array reference.
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

// 📝 ---------------------------------------------------------------------------
// 6. PSR‑4‑lite autoload (no Composer)
// -----------------------------------------------------------------------------
spl_autoload_register(function (string $class): void {
    $prefixes = [
        'Portal\\Core\\' => PORTAL_CORE . DIRECTORY_SEPARATOR,
        'Portal\\App\\'  => PORTAL_APPS . DIRECTORY_SEPARATOR,
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix) === true) {
            $relativeClass = substr($class, strlen($prefix));
            $file          = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
            if (is_readable($file) === true) {
                require_once $file;
            }
        }
    }
});

// 📜 ---------------------------------------------------------------------------
// 7. Global error / exception handlers
// -----------------------------------------------------------------------------
set_error_handler(function (int $errno, string $errstr, string $file, int $line): bool {
    \Portal\Core\Logger::phpError($errno, $errstr, $file, $line);
    // Allow PHP’s own handler to proceed after logging
    return false;
});

set_exception_handler(function (Throwable $ex): void {
    \Portal\Core\Logger::exception($ex);
    http_response_code(500);
    if (isset($_GET['debug']) && $_GET['debug'] === 'true') {
        echo '<pre>' . htmlspecialchars((string) $ex, ENT_QUOTES) . '</pre>';
    } else {
        echo 'An internal error occurred.';
    }
});

// 🏁 Bootstrap completed – Router will now take over.
