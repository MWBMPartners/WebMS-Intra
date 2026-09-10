<?php
// Path: _apps/admin/system-info/phpinfo.php
/**
 * -----------------------------------------------------------------------------
 * Admin — the full PHP report 📋
 * -----------------------------------------------------------------------------
 * A description of PHP on this server: its version, the settings that decide how
 * it behaves, and every optional part that is installed. This is what a hosting
 * company asks for when diagnosing an awkward problem, and the plain text
 * version of it is meant to be pasted straight into a support ticket.
 *
 * =============================================================================
 * READ THIS BEFORE CHANGING ANYTHING HERE
 * =============================================================================
 * This page has been rewritten three times, because three independent reviews
 * each got past the version before it. Every one of those attempts looked
 * reasonable when it was written. The history is short and worth knowing,
 * because the obvious change is the one that keeps failing.
 *
 * ATTEMPT 1 — call PHP's own phpinfo() and cut the dangerous parts out.
 *   Broken. phpinfo() prints the server's environment variables (which on shared
 *   hosting hold database passwords) and the current request (which holds the
 *   reader's own sign-in cookie). You can ask it to omit those two sections, but
 *   that is not enough: when PHP runs as an Apache module, the "apache2handler"
 *   entry inside the installed-parts section prints "Apache Environment" and
 *   "HTTP Headers Information" of its own accord, and the second carries the
 *   Cookie header. Worse, the filter written to remove them never matched
 *   anything at all, because PHP puts those headings in <h2> elements OUTSIDE
 *   the tables, not inside them.
 *
 * ATTEMPT 2 — build the report ourselves, and hide values that LOOK secret.
 *   Broken. There is always another shape. The one that got through was a Redis
 *   cache address written the documented way, with auth[]= array parameters,
 *   which no "auth=" pattern matches. A value can also be pulled in from the
 *   server's environment using PHP's ${NAME} syntax, arriving here as an
 *   ordinary string with nothing to mark it as a password.
 *
 * ATTEMPT 3 — only show settings on an approved list of NAMES.
 *   Still broken, in two ways. Approving whole families by prefix (opcache.,
 *   zend.) silently approved every future setting in those families, and several
 *   existing ones holding file paths and account names — opcache.preload_user
 *   and opcache.error_log among them. And approving a name says nothing about
 *   its value: a setting as innocent as arg_separator.output can be set to any
 *   string at all, and in testing it was.
 *
 * WHAT IS HERE NOW — approve the name AND check the value
 * -------------------------------------------------------
 * Every setting shown is named individually below, together with the KIND of
 * value it is expected to hold: a number, a switch, a size, a time zone, and so
 * on. The value is shown only if it actually looks like that kind of thing. A
 * number that is not a number, or a separator that is not one or two punctuation
 * marks, is withheld.
 *
 * That is what finally closes the hole, because it no longer depends on
 * recognising secrets. A secret placed in arg_separator.output fails the check
 * for "one to four punctuation marks" and never appears, without anybody having
 * had to imagine that particular hiding place.
 *
 * Rules for adding a setting here: name it individually, never by prefix; give
 * it the tightest kind that fits; and never add one whose natural value is a
 * file path, because on shared hosting a path contains the customer's account
 * name.
 *
 * WHO CAN SEE IT
 * --------------
 * Umbrella administrators only. This describes the whole SERVER rather than one
 * organisation on it. On an ordinary single-organisation install the owner is
 * the umbrella administrator, so nothing is taken away from anybody.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   3.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/489
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

// 🔐 Umbrella administrators only. This runs before a single byte of output.
Auth::ensureSession();
Auth::requireLogin();
if (App::isUmbrellaAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 📄 Two ways to read the same report: a normal page, or plain text. The plain
//    text one is the version meant for sending to somebody else.
$asText = isset($_GET['format']) === true && $_GET['format'] === 'text';

// -----------------------------------------------------------------------------
// ✅ Which settings are reported, and what each one is allowed to look like
// -----------------------------------------------------------------------------
// The kinds:
//   int      a whole number
//   bytes    a size, such as 512M or 64K or -1
//   bool     a switch: On, Off, 1, 0, empty, or one of PHP's word forms
//   sep      one to four punctuation marks (argument separators)
//   order    a short run of the letters G P C S E (which inputs PHP reads)
//   tz       a real time-zone name, checked against PHP's own list
//   charset  a character-set name
//   funcs    a comma-separated list of function or class names
//   samesite one of the four values PHP accepts for the cookie SameSite rule
//   savehandler  one of the session storage back-ends PHP knows about
//   mblang   one of the language names the mbstring extension accepts
//   jit      one of the accepted just-in-time compiler modes, or its number form
//   word     a short plain word or simple path
//
// A note on the two settings that use 'word'. Both session.name and
// session.cookie_path are sent to EVERY visitor's browser in the cookie itself,
// so neither can hold anything secret by its very nature — there is nowhere for
// a secret to hide in a value the browser is already told. Every other setting
// that once used this loose kind now has an explicit list of accepted values
// instead, because "a short run of letters" is a shape a password can also have.
//
// Deliberately absent: anything whose value is naturally a file path. On shared
// hosting a path contains the customer's account name and private directory
// layout, which is nobody else's business and is not needed to diagnose
// anything.

const REPORT_SETTINGS = [
    // — Size and time limits. The usual explanation for a puzzling failure. —
    'memory_limit'                   => 'bytes',
    'upload_max_filesize'            => 'bytes',
    'post_max_size'                  => 'bytes',
    'max_execution_time'             => 'int',
    'max_input_time'                 => 'int',
    'max_file_uploads'               => 'int',
    'max_input_vars'                 => 'int',
    'max_input_nesting_level'        => 'int',
    'default_socket_timeout'         => 'int',
    'file_uploads'                   => 'bool',
    'enable_post_data_reading'       => 'bool',

    // — How errors are handled. Never where they are written, which is a path. —
    'display_errors'                 => 'bool',
    'display_startup_errors'         => 'bool',
    'log_errors'                     => 'bool',
    'error_reporting'                => 'int',
    'log_errors_max_len'             => 'int',
    'ignore_repeated_errors'         => 'bool',
    'html_errors'                    => 'bool',
    'zend.exception_ignore_args'     => 'bool',
    'zend.assertions'                => 'int',

    // — Output and language behaviour —
    'default_charset'                => 'charset',
    'internal_encoding'              => 'charset',
    'output_buffering'               => 'bytes',
    'implicit_flush'                 => 'bool',
    'precision'                      => 'int',
    'serialize_precision'            => 'int',
    'short_open_tag'                 => 'bool',
    'expose_php'                     => 'bool',
    'zlib.output_compression'        => 'bool',
    'arg_separator.input'            => 'sep',
    'arg_separator.output'           => 'sep',
    'variables_order'                => 'order',
    'request_order'                  => 'order',

    // — What the host has switched off. Important, and not a secret. —
    'allow_url_fopen'                => 'bool',
    'allow_url_include'              => 'bool',
    'disable_functions'              => 'funcs',
    'disable_classes'                => 'funcs',

    // — Dates —
    'date.timezone'                  => 'tz',

    // — Sessions. Never save_path: that is where a cache password lives. —
    'session.name'                   => 'word',
    'session.save_handler'           => 'savehandler',
    'session.auto_start'             => 'bool',
    'session.use_strict_mode'        => 'bool',
    'session.use_cookies'            => 'bool',
    'session.use_only_cookies'       => 'bool',
    'session.use_trans_sid'          => 'bool',
    'session.cookie_lifetime'        => 'int',
    'session.cookie_path'            => 'word',
    'session.cookie_secure'          => 'bool',
    'session.cookie_httponly'        => 'bool',
    'session.cookie_samesite'        => 'samesite',
    'session.gc_probability'         => 'int',
    'session.gc_divisor'             => 'int',
    'session.gc_maxlifetime'         => 'int',
    'session.sid_length'             => 'int',
    'session.sid_bits_per_character' => 'int',
    'session.lazy_write'             => 'bool',

    // — Character handling —
    'mbstring.language'              => 'mblang',
    'mbstring.internal_encoding'     => 'charset',
    'mbstring.http_input'            => 'charset',
    'mbstring.http_output'           => 'charset',
    'mbstring.detect_order'          => 'charset',
    'iconv.input_encoding'           => 'charset',
    'iconv.output_encoding'          => 'charset',
    'iconv.internal_encoding'        => 'charset',

    // — Pattern-matching limits. These decide whether a big page can be
    //   processed at all, so they are worth seeing. —
    'pcre.backtrack_limit'           => 'int',
    'pcre.recursion_limit'           => 'int',
    'pcre.jit'                       => 'bool',

    // — The performance cache. Named one at a time on purpose: the same family
    //   also contains preload_user, error_log, file_cache, blacklist_filename
    //   and lockfile_path, every one of which is a path or an account name. —
    'opcache.enable'                  => 'bool',
    'opcache.enable_cli'              => 'bool',
    'opcache.memory_consumption'      => 'int',
    'opcache.interned_strings_buffer' => 'int',
    'opcache.max_accelerated_files'   => 'int',
    'opcache.max_wasted_percentage'   => 'int',
    'opcache.use_cwd'                 => 'bool',
    'opcache.validate_timestamps'     => 'bool',
    'opcache.revalidate_freq'         => 'int',
    'opcache.save_comments'           => 'bool',
    'opcache.huge_code_pages'         => 'bool',
    'opcache.jit'                     => 'jit',
    'opcache.jit_buffer_size'         => 'bytes',

    // — Images and documents —
    'gd.jpeg_ignore_warning'         => 'bool',
    'soap.wsdl_cache_enabled'        => 'bool',
    'soap.wsdl_cache_ttl'            => 'int',
];

/**
 * 🚦 Does this value actually look like the kind of thing we expect?
 *
 * This is the check that finally makes the report safe, and it works because it
 * does not try to recognise a secret. It only asks whether the value is the
 * shape it is supposed to be. A password put into a setting that should hold a
 * number is not a number, so it is withheld — and nobody had to think of that
 * particular hiding place in advance.
 *
 * @param string $kind  One of the kinds listed above.
 * @param string $value The value as PHP reports it.
 *
 * @return bool True if the value may be displayed.
 */
function portalValueMatchesKind(string $kind, string $value): bool
{
    // An empty value is always safe to show as "no value".
    if ($value === '') {
        return true;
    }

    // Nothing legitimate in this report is long. A very long value is a strong
    // sign that something unexpected has been put there.
    if (strlen($value) > 300) {
        return false;
    }

    switch ($kind) {
        case 'int':
            return preg_match('/^-?\d{1,20}$/', $value) === 1;

        case 'bytes':
            return preg_match('/^-?\d{1,20}[KMGkmg]?$/', $value) === 1;

        case 'bool':
            return in_array(
                strtolower($value),
                ['0', '1', 'on', 'off', 'true', 'false', 'yes', 'no', 'stderr', 'stdout'],
                true
            );

        case 'sep':
            return preg_match('/^[&;,|]{1,4}$/', $value) === 1;

        case 'order':
            return preg_match('/^[GPCSE]{1,5}$/i', $value) === 1;

        case 'tz':
            return in_array($value, timezone_identifiers_list(), true) === true
                || strtoupper($value) === 'UTC';

        case 'charset':
            return preg_match('/^[A-Za-z0-9_.:\/-]{1,40}(,[A-Za-z0-9_.:\/-]{1,40}){0,9}$/', $value) === 1;

        case 'funcs':
            return preg_match('/^[A-Za-z0-9_\\\\,\s:]{1,300}$/', $value) === 1;

        case 'samesite':
            return in_array(strtolower($value), ['lax', 'strict', 'none'], true);

        case 'savehandler':
            return in_array(
                strtolower($value),
                ['files', 'user', 'redis', 'rediscluster', 'memcached', 'memcache', 'sqlite', 'mm'],
                true
            );

        case 'mblang':
            return in_array(
                strtolower($value),
                [
                    'neutral', 'english', 'uni', 'japanese', 'ja', 'korean', 'ko',
                    'simplified chinese', 'zh-cn', 'traditional chinese', 'zh-tw',
                    'russian', 'ru', 'armenian', 'hy', 'turkish', 'tr', 'german', 'de',
                ],
                true
            );

        case 'jit':
            return in_array(strtolower($value), ['off', 'disable', 'on', 'tracing', 'function'], true)
                || preg_match('/^\d{1,4}$/', $value) === 1;

        case 'word':
            // Only used for session.name and session.cookie_path, both of which
            // the browser is told anyway. See the note above the settings list.
            return preg_match('/^[A-Za-z0-9_.\/-]{1,64}$/', $value) === 1;

        default:
            // An unknown kind means somebody added a setting without saying what
            // it should look like. Withhold it rather than guess.
            return false;
    }
}

// -----------------------------------------------------------------------------
// 🔒 Last line of defence
// -----------------------------------------------------------------------------

/**
 * 🔎 Does the finished report contain the reader's own sign-in token?
 *
 * IT SEARCHES THE DECODED REPORT, NOT AN ENCODED TOKEN, and that distinction is
 * the whole point. An earlier version encoded the token and searched for that,
 * which does nothing: a session token is letters and digits, so encoding it
 * returns the identical string. Meanwhile the token can appear in a report
 * escaped, as %61bc... where the real one is abc... . Decoding the REPORT closes
 * that gap, whichever way it was escaped on the way in.
 *
 * @param string $haystack The finished report, exactly as it would be sent.
 *
 * @return bool True if the token appears in any form.
 */
function portalReportLeaksSessionToken(string $haystack): bool
{
    $token = session_id();
    if (is_string($token) === false || strlen($token) < 8) {
        return false;
    }

    foreach ([$haystack, rawurldecode($haystack), urldecode($haystack)] as $form) {
        if (strpos($form, $token) !== false) {
            return true;
        }
    }
    return false;
}

// -----------------------------------------------------------------------------
// 📊 Gather the report
// -----------------------------------------------------------------------------

// 🧾 General facts. Every one is chosen individually.
//
//    Note what is NOT here: the location of PHP's settings files. Those are full
//    paths, and on shared hosting a path such as /home/{account}/private/php.ini
//    gives away the customer's account name and directory layout. Whether the
//    files exist, and how many there are, answers the diagnostic question
//    without any of that.
$scanned      = php_ini_scanned_files();
$scannedCount = ($scanned === false || trim((string) $scanned) === '')
    ? 0
    : count(array_filter(array_map('trim', explode(',', (string) $scanned))));

$general = [
    'PHP version'          => PHP_VERSION,
    'How PHP is being run' => PHP_SAPI,
    'Operating system'     => PHP_OS_FAMILY,
    'Engine version'       => zend_version(),
    'Integer size (bytes)' => (string) PHP_INT_SIZE,
    'Main settings file'   => php_ini_loaded_file() !== false ? 'loaded' : 'none found',
    'Extra settings files' => (string) $scannedCount,
    'Default time zone'    => date_default_timezone_get(),
];

// 📦 Which optional parts of PHP are installed, and their versions.
$extensions = [];
if (function_exists('get_loaded_extensions') === true) {
    $names = get_loaded_extensions();
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($names as $ext) {
        $ver = phpversion($ext);
        $extensions[(string) $ext] = ($ver === false || $ver === '') ? '(no version reported)' : (string) $ver;
    }
}

// ⚙️ The settings, filtered as described at the top of this file.
$settingsAvailable = function_exists('ini_get_all');
$settings          = [];
$withheldCount     = 0;

if ($settingsAvailable === true) {
    $raw = ini_get_all(null, true);
    if (is_array($raw) === true) {
        foreach (REPORT_SETTINGS as $name => $kind) {
            if (array_key_exists($name, $raw) === false) {
                continue;
            }

            $local = $raw[$name]['local_value'] ?? '';
            $globl = $raw[$name]['global_value'] ?? '';
            $local = is_array($local) === true ? implode(', ', $local) : (string) $local;
            $globl = is_array($globl) === true ? implode(', ', $globl) : (string) $globl;

            if (portalValueMatchesKind($kind, $local) === false
                || portalValueMatchesKind($kind, $globl) === false
            ) {
                $settings[$name] = ['local' => '', 'global' => '', 'shown' => false];
                $withheldCount++;
                continue;
            }

            $settings[$name] = ['local' => $local, 'global' => $globl, 'shown' => true];
        }
    }
}

// -----------------------------------------------------------------------------
// 📝 Render
// -----------------------------------------------------------------------------

if ($asText === true) {
    $lines   = [];
    $lines[] = 'PHP report — ' . date('Y-m-d H:i:s T');
    $lines[] = str_repeat('=', 70);
    $lines[] = '';
    $lines[] = 'GENERAL';
    foreach ($general as $k => $v) {
        $lines[] = sprintf('  %-28s %s', $k, $v);
    }
    $lines[] = '';
    $lines[] = 'INSTALLED PARTS OF PHP (' . count($extensions) . ')';
    foreach ($extensions as $k => $v) {
        $lines[] = sprintf('  %-28s %s', $k, $v);
    }
    $lines[] = '';
    if ($settingsAvailable === false) {
        $lines[] = 'SETTINGS: not available — this hosting has switched off the function that lists them.';
    } else {
        $lines[] = 'SETTINGS (' . count($settings) . ') — value here | server default';
        foreach ($settings as $k => $v) {
            if ($v['shown'] === false) {
                $lines[] = sprintf('  %-40s [value not shown]', $k);
                continue;
            }
            $lines[] = sprintf(
                '  %-40s %s | %s',
                $k,
                $v['local'] === '' ? '(no value)' : $v['local'],
                $v['global'] === '' ? '(no value)' : $v['global']
            );
        }
    }
    $lines[] = '';
    $lines[] = 'WHAT THIS REPORT LEAVES OUT, ON PURPOSE';
    $lines[] = '  This is a chosen list of settings, not every setting PHP has. Each one is';
    $lines[] = '  also checked against the kind of value it should hold, and withheld if it';
    $lines[] = '  does not match. File locations are left out entirely, because on shared';
    $lines[] = '  hosting a file path gives away the account name.';
    if ($withheldCount > 0) {
        $lines[] = sprintf('  %d setting(s) did not match their expected kind and were withheld.', $withheldCount);
    }
    $lines[] = '  If your hosting provider needs something that is not here, they can ask you';
    $lines[] = '  for that one setting.';

    $body = implode("\n", $lines);

    if (portalReportLeaksSessionToken($body) === true) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("This report was not shown, because it turned out to contain your own sign-in token.\n"
           . "That should not be possible and is worth reporting.\n");
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    echo $body;
    exit();
}

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, no-cache, must-revalidate, private');

// 🛑 A guard around everything drawn below. It throws away whatever it is given
//    unless the page has been explicitly approved.
//
//    Why it is built this way. The check that matters happens AFTER the page is
//    drawn. If drawing it fails half way — an exception, or an error that stops
//    PHP altogether — that check is never reached, and PHP sends whatever is
//    sitting in the buffer as it shuts down. A partly-drawn, unchecked report
//    would go out. Wrapping the drawing in try/finally does not help, because a
//    fatal error runs neither.
//
//    This does hold, because the buffer's own handler decides what is allowed
//    out and refuses by default. It runs during shutdown as well, so the failure
//    path sends nothing rather than something unchecked.
$reportApproved = false;
ob_start(static function (string $chunk) use (&$reportApproved): string {
    return $reportApproved === true ? $chunk : '';
});

$pageTitle   = 'Full PHP report';
$pageSection = 'admin';
$breadcrumbs = [
    'Dashboard'          => '/',
    'Admin'              => '/admin',
    'Server Information' => '/admin/system-info',
    'Full PHP report'    => '',
];

// A second, ordinary buffer, so the finished page can be inspected before the
// guard above is told to let it through.
ob_start();

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-file-lines me-2"></i>Full PHP report</h1>
        <p class="text-secondary mb-0">
            How PHP is set up on this server. This is what a hosting company usually
            asks to see.
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="/admin/system-info/phpinfo?format=text" class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener">
            <i class="fa-solid fa-align-left me-1"></i>Plain text
        </a>
        <a href="/admin/system-info" class="btn btn-outline-secondary btn-sm">&larr; Server Information</a>
    </div>
</div>

<div class="alert alert-info">
    <p class="mb-1"><strong>Send the plain text version, not this page.</strong></p>
    <p class="mb-0 small">
        The <strong>Plain text</strong> button gives you a copy built to be safe to hand
        to somebody else. This web page is an ordinary signed-in admin screen, so a saved
        copy of it carries the same hidden security details every admin page does &mdash;
        harmless while it stays with you, but not something to email or paste into a
        public forum. The plain text version has none of that.
    </p>
</div>

<div class="alert alert-secondary">
    <p class="mb-1"><strong>This is a chosen list of settings, not every setting.</strong></p>
    <p class="mb-0 small">
        Each setting below is also checked against the kind of value it ought to hold
        &mdash; a number, a switch, a size &mdash; and withheld if it does not match.
        That is deliberately strict: it means a password stored somewhere unexpected
        cannot appear here, without anybody having to guess where somebody might hide
        one. File locations are left out entirely, because on shared hosting a file path
        gives away your account name.
    </p>
</div>

<!-- 🧾 General -->
<div class="card mb-4">
    <div class="card-header"><h2 class="h5 mb-0">General</h2></div>
    <div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($general as $label => $value): ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold"><?php echo htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-12 col-md-8"><code><?php echo htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?></code></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- 📦 Installed parts -->
<div class="card mb-4">
    <div class="card-header">
        <h2 class="h5 mb-0">Installed parts of PHP (<?php echo htmlspecialchars((string) count($extensions), ENT_QUOTES, 'UTF-8'); ?>)</h2>
    </div>
    <div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($extensions as $name => $ver): ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold"><code><?php echo htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8'); ?></code></div>
                <div class="col-12 col-md-8"><?php echo htmlspecialchars((string) $ver, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ⚙️ Settings -->
<div class="card mb-4">
    <div class="card-header">
        <h2 class="h5 mb-0">
            Settings<?php echo $settingsAvailable === true ? ' (' . htmlspecialchars((string) count($settings), ENT_QUOTES, 'UTF-8') . ')' : ''; ?>
        </h2>
    </div>
    <div class="card-body">
        <?php if ($settingsAvailable === false): ?>
            <p class="mb-0 text-muted">
                Your hosting provider has switched off the part of PHP that lists its own
                settings, so this section cannot be shown. That is a server-wide choice and
                cannot be changed from inside this portal. Everything else on this page and
                on the Server Information page still works.
            </p>
        <?php else: ?>
            <p class="text-secondary small">
                The first value is what is in force for this portal. The second is the
                server's own default, which can differ when a setting has been changed for
                this site only.
            </p>
            <div class="portal-data-list">
                <?php foreach ($settings as $name => $row): ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-4 fw-semibold"><code><?php echo htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8'); ?></code></div>
                    <?php if ($row['shown'] === false): ?>
                        <div class="col-12 col-md-8 text-muted small">
                            <i class="fa-solid fa-eye-slash me-1"></i>Value not shown &mdash; it is not the kind of value this setting should hold
                        </div>
                    <?php else: ?>
                        <div class="col-12 col-md-4">
                            <?php if ($row['local'] === ''): ?>
                                <span class="text-muted">no value</span>
                            <?php else: ?>
                                <code><?php echo htmlspecialchars($row['local'], ENT_QUOTES, 'UTF-8'); ?></code>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-4 text-muted">
                            <?php if ($row['global'] === ''): ?>
                                <span class="small">no value</span>
                            <?php else: ?>
                                <code class="small"><?php echo htmlspecialchars($row['global'], ENT_QUOTES, 'UTF-8'); ?></code>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';

$html = (string) ob_get_clean();

// 🛑 The last check, on exactly what would be sent.
if (portalReportLeaksSessionToken($html) === true) {
    http_response_code(500);
    $reportApproved = true;
    echo 'This report was not shown, because it turned out to contain your own '
       . 'sign-in token. That should not be possible and is worth reporting.';
    exit();
}

// ✅ Approve, then release. Nothing reaches the browser before this line.
$reportApproved = true;
echo $html;
