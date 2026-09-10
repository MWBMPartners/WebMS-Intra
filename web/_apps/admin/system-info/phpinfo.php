<?php
// Path: _apps/admin/system-info/phpinfo.php
/**
 * -----------------------------------------------------------------------------
 * Admin — the full PHP report 📋
 * -----------------------------------------------------------------------------
 * The detailed description of PHP on this server: its version, every setting it
 * is running with, and every optional part that is installed. This is the report
 * a hosting company asks for when they are diagnosing an awkward problem.
 *
 * WHY THIS DOES NOT USE PHP'S OWN phpinfo() FUNCTION
 * --------------------------------------------------
 * The obvious way to build this page is to call PHP's built-in `phpinfo()` and
 * send what it produces straight to the browser. **That is not safe, and this
 * page deliberately does not do it.** The reasoning is worth reading before
 * anyone changes it back.
 *
 * `phpinfo()` prints things that must never appear on a screen:
 *
 *   - The server's environment variables. On shared hosting these frequently
 *     hold database passwords and service keys.
 *   - The current request, including the browser's cookies — which contain the
 *     reader's own sign-in token. Anyone who saw that screen could sign in as
 *     them. That is not far-fetched: pasting this report into a support ticket
 *     is the main reason the page exists.
 *
 * You can ask `phpinfo()` to leave those two sections out. **It is not enough.**
 * When PHP runs as an Apache module, the "apache2handler" entry inside the
 * list-of-installed-parts section prints two further tables of its own accord —
 * "Apache Environment" and "HTTP Headers Information" — and the second contains
 * the request's Cookie header. The leak arrives through a different door.
 *
 * A first version of this page tried to solve that by capturing `phpinfo()`'s
 * output and cutting the dangerous parts out of it with pattern matching. An
 * independent review took that apart in minutes, and it was wrong in two ways
 * that are worth recording, because both look fine until someone checks:
 *
 *   1. The section headings are `<h2>` elements that sit OUTSIDE the tables they
 *      introduce, not inside them. A filter that removed tables containing a
 *      heading removed nothing at all. (Verified by rendering a report through a
 *      real web server and looking at the markup, which is how it should have
 *      been checked the first time.)
 *   2. Even a filter that searched for the reader's sign-in token could be
 *      slipped past, because PHP decodes percent-escapes in cookies. A token
 *      sent as `%61bc...` reads as `abc...` inside PHP but stays as `%61bc...`
 *      in the Apache table, so the two never matched.
 *
 * The lesson is that cutting dangerous parts out of a large block of text
 * somebody else produced is a losing game. Every new version of PHP, and every
 * different way of running it, can add something new to that block, and the
 * filter finds out last.
 *
 * SO THIS PAGE BUILDS THE REPORT ITSELF
 * -------------------------------------
 * Everything below is assembled from a small number of PHP functions that return
 * plain data — `ini_get_all()` for the settings, `get_loaded_extensions()` for
 * the installed parts, and a handful of constants. Nothing is asked for that
 * could contain the environment or the request, so there is nothing to cut out.
 * Anything new that a future PHP adds simply does not appear, rather than
 * appearing and having to be caught.
 *
 * The information is the same information. A hosting company gets what they
 * need. Use the "plain text" link to copy the whole thing into a support ticket.
 *
 * WHICH VALUES ARE SHOWN, AND WHY IT IS AN ALLOWLIST
 * ---------------------------------------------------
 * PHP hides nothing on its own — checked against a running server, not assumed.
 * A `mysqli.default_pw` and a `sendmail_path` carrying a password in its
 * arguments both print in full.
 *
 * A first attempt hid values that LOOKED like secrets. A second review showed
 * why that can never be trusted: a Redis cache address written the documented
 * way, `tcp://cache:6379?auth[]=user&auth[]=SECRET`, slips past any "auth="
 * pattern. And a setting's value can be pulled in from the server's environment
 * with PHP's `${VARIABLE}` syntax, arriving here as an ordinary-looking string
 * with nothing to mark it as a password.
 *
 * So the rule is inverted. **A value is shown only if its setting is on an
 * allowlist** of settings known not to carry credentials. Everything else shows
 * the name and withholds the value. A setting introduced by a future PHP is
 * therefore withheld by default rather than displayed until somebody notices.
 * Names are always shown, because knowing a setting exists is useful and cannot
 * hurt.
 *
 * WHO CAN SEE IT
 * --------------
 * Umbrella administrators only. This describes the whole SERVER rather than one
 * organisation on it, so on an install shared by several organisations an
 * administrator of one of them should not necessarily see it. On an ordinary
 * single-organisation install the owner is the umbrella administrator, so
 * nothing is taken away from anybody.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
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

// 📄 Two ways to read the same report: a normal page, or plain text for pasting
//    into a support ticket.
$asText = isset($_GET['format']) === true && $_GET['format'] === 'text';

// -----------------------------------------------------------------------------
// 🙈 Hiding secrets
// -----------------------------------------------------------------------------

/**
 * ✅ The settings whose VALUES are safe to display, listed by exact name.
 *
 * This is an ALLOWLIST, and it is deliberately the other way round from the
 * obvious design. Two independent reviews took apart two earlier versions that
 * tried to spot secrets and hide them, and the second review put the reason
 * plainly: a list of every setting, filtered by patterns that look for
 * secret-shaped values, cannot promise secrecy. There is always another shape.
 * The one that got through was a cache address written as
 * `tcp://cache:6379?auth[]=user&auth[]=SECRET` — a documented way to configure
 * Redis, and one no "auth=" pattern would match.
 *
 * A setting's value can also be pulled in from the server's environment, using
 * PHP's `${VARIABLE}` syntax in its configuration file. By the time it reaches
 * this page it is an ordinary-looking string, with nothing to say it began life
 * as a password.
 *
 * So the rule is inverted. A value is shown only if the setting is named below.
 * Everything else shows the setting's NAME — which is useful and harmless — and
 * withholds the value. A setting added by a future version of PHP is therefore
 * withheld by default, rather than displayed until somebody notices.
 *
 * The list covers what people actually need when diagnosing a problem: the size
 * and time limits, how errors are handled, the performance cache, and the
 * session cookie settings. Add to it when something is genuinely needed AND
 * genuinely cannot carry a credential.
 */
const SAFE_SETTING_NAMES = [
    // Size and time limits — the usual explanation for a puzzling failure
    'memory_limit', 'max_execution_time', 'max_input_time', 'upload_max_filesize',
    'post_max_size', 'max_file_uploads', 'max_input_vars', 'max_input_nesting_level',
    'default_socket_timeout', 'file_uploads', 'enable_post_data_reading',
    // Errors and logging behaviour (not the log's location, which is a path)
    'display_errors', 'display_startup_errors', 'error_reporting', 'log_errors',
    'log_errors_max_len', 'ignore_repeated_errors', 'html_errors', 'track_errors',
    'zend.exception_ignore_args', 'zend.assertions', 'assert.active',
    // Language and output behaviour
    'default_charset', 'internal_encoding', 'output_buffering', 'implicit_flush',
    'precision', 'serialize_precision', 'short_open_tag', 'expose_php',
    'zlib.output_compression', 'zlib.output_compression_level',
    'variables_order', 'request_order', 'arg_separator.input', 'arg_separator.output',
    // What the host has switched off — important, and not a secret
    'disable_functions', 'disable_classes', 'allow_url_fopen', 'allow_url_include',
    // Dates
    'date.timezone', 'date.default_latitude', 'date.default_longitude',
    // Mail transport, but never the sendmail command line, which takes arguments
    'SMTP', 'smtp_port', 'mail.add_x_header',
    // Sessions: everything except save_path, which is where a cache password lives
    'session.name', 'session.auto_start', 'session.use_strict_mode',
    'session.use_cookies', 'session.use_only_cookies', 'session.use_trans_sid',
    'session.cookie_lifetime', 'session.cookie_path', 'session.cookie_domain',
    'session.cookie_secure', 'session.cookie_httponly', 'session.cookie_samesite',
    'session.gc_probability', 'session.gc_divisor', 'session.gc_maxlifetime',
    'session.sid_length', 'session.sid_bits_per_character', 'session.lazy_write',
    'session.save_handler',
    // Uploads and character handling
    'mbstring.language', 'mbstring.internal_encoding', 'mbstring.http_input',
    'mbstring.http_output', 'mbstring.detect_order', 'mbstring.func_overload',
    'iconv.input_encoding', 'iconv.output_encoding', 'iconv.internal_encoding',
    // Pattern matching limits — these decide whether a big page can be processed
    'pcre.backtrack_limit', 'pcre.recursion_limit', 'pcre.jit',
    // Images and documents
    'gd.jpeg_ignore_warning', 'exif.encode_unicode', 'exif.decode_unicode_motorola',
    'libxml.streams_custom', 'soap.wsdl_cache_enabled', 'soap.wsdl_cache_ttl',
];

/**
 * ✅ Whole families of settings that are safe by their prefix.
 *
 * The performance cache in particular has dozens of settings, all of them
 * numbers and switches, and all of them worth seeing when a page is slow.
 */
const SAFE_SETTING_PREFIXES = [
    'opcache.',
    'apcu.',
    'zend.',
    'assert.',
];

/**
 * 🚦 May this setting's value be displayed?
 *
 * @param string $name The setting's name.
 *
 * @return bool True only if the setting is on the allowlist above.
 */
function portalSettingValueIsShowable(string $name): bool
{
    if (in_array($name, SAFE_SETTING_NAMES, true) === true) {
        return true;
    }
    foreach (SAFE_SETTING_PREFIXES as $prefix) {
        if (str_starts_with($name, $prefix) === true) {
            return true;
        }
    }
    return false;
}

// -----------------------------------------------------------------------------
// 📊 Gather the report
// -----------------------------------------------------------------------------

// 🧾 General facts about PHP itself. Every one of these is chosen by name, so
//    nothing unexpected can arrive here.
$general = [
    'PHP version'                 => PHP_VERSION,
    'How PHP is being run'        => PHP_SAPI,
    'Operating system'            => PHP_OS_FAMILY,
    'Engine version'              => zend_version(),
    'Integer size (bytes)'        => (string) PHP_INT_SIZE,
    'Main settings file'          => (string) (php_ini_loaded_file() !== false ? php_ini_loaded_file() : 'none found'),
    'Extra settings files'        => (string) (php_ini_scanned_files() !== false ? trim((string) php_ini_scanned_files()) : 'none'),
    'Default time zone'           => date_default_timezone_get(),
    'Maximum uploaded file size'  => (string) ini_get('upload_max_filesize'),
    'Maximum form submission'     => (string) ini_get('post_max_size'),
    'Memory limit'                => (string) ini_get('memory_limit'),
    'Longest a page may run'      => (string) ini_get('max_execution_time') . ' seconds',
];

// 📦 Which optional parts of PHP are installed, and what version each one is.
$extensions = [];
if (function_exists('get_loaded_extensions') === true) {
    $names = get_loaded_extensions();
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($names as $ext) {
        $ver = phpversion($ext);
        $extensions[(string) $ext] = ($ver === false || $ver === '') ? '(no version reported)' : (string) $ver;
    }
}

// ⚙️ Every configuration setting, with the value in force here and the server's
//    own default. This is the bulk of what a hosting company wants to see.
//
//    A few hosts switch ini_get_all() off. Say so plainly rather than showing an
//    empty list that looks like "there are no settings".
$settingsAvailable = function_exists('ini_get_all');
$settings          = [];
$withheldCount     = 0;

if ($settingsAvailable === true) {
    $raw = ini_get_all(null, true);
    if (is_array($raw) === true) {
        ksort($raw, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($raw as $name => $info) {
            $name  = (string) $name;
            $local = $info['local_value'] ?? '';
            $globl = $info['global_value'] ?? '';

            // An array-valued setting is rare but real; flatten it for display.
            $local = is_array($local) === true ? implode(', ', $local) : (string) $local;
            $globl = is_array($globl) === true ? implode(', ', $globl) : (string) $globl;

            if (portalSettingValueIsShowable($name) === true) {
                $settings[$name] = ['local' => $local, 'global' => $globl, 'shown' => true];
            } else {
                $settings[$name] = ['local' => '', 'global' => '', 'shown' => false];
                $withheldCount++;
            }
        }
    }
}

// -----------------------------------------------------------------------------
// 🔒 Last line of defence, applied to the finished report
// -----------------------------------------------------------------------------
// Everything above is reasoning about where a secret could appear. This checks
// the one thing that would be worst to get wrong: the reader's own sign-in
// token. It is a plain text search over the finished report, so unlike the
// reasoning it cannot be mistaken.
//
// The token is checked in BOTH its plain and its percent-escaped forms. PHP
// decodes percent-escapes in cookies, so a token that reads as `abc...` inside
// PHP can sit in a server table as `%61bc...`. Checking only one form is exactly
// the gap an earlier version of this page had.

/**
 * 🔎 Does the finished report contain the reader's own sign-in token?
 *
 * This is the last line of defence. Everything else on this page is reasoning
 * about where a secret could appear; this checks the one thing that would be
 * worst to get wrong, by looking for it.
 *
 * IT SEARCHES THE DECODED REPORT, NOT AN ENCODED TOKEN — and that distinction
 * is the whole point. A first version encoded the token and searched for that,
 * which does nothing at all: a session token is letters and digits, so
 * percent-encoding it returns the very same string. Meanwhile a token can
 * appear in a report percent-escaped, as `%61bc...` where the real token is
 * `abc...`. The two never meet. Decoding the REPORT closes that gap, because
 * however the token was escaped on the way in, decoding brings it back to the
 * form we are looking for.
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

    // The report as-is, and the report with any percent-escapes undone. The two
    // decoders differ only in how they treat a plus sign, so both are checked.
    $forms = [$haystack, rawurldecode($haystack), urldecode($haystack)];

    foreach ($forms as $form) {
        if (strpos($form, $token) !== false) {
            return true;
        }
    }
    return false;
}

// -----------------------------------------------------------------------------
// 📝 Render
// -----------------------------------------------------------------------------

if ($asText === true) {
    $lines = [];
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
        $lines[] = 'SETTINGS (' . count($settings) . ') — name, value in force here, server default';
        foreach ($settings as $k => $v) {
            if ($v['shown'] === false) {
                $lines[] = sprintf('  %-44s [value not shown]', $k);
                continue;
            }
            $lines[] = sprintf('  %-44s %s | %s', $k, $v['local'], $v['global']);
        }
    }
    $lines[] = '';
    $lines[] = 'ABOUT THE VALUES THAT ARE NOT SHOWN';
    $lines[] = sprintf('  %d of %d settings show their name only.', $withheldCount, count($settings));
    $lines[] = '  Values are shown only for settings that are known not to hold a password';
    $lines[] = '  or a key. Anything else shows its name and withholds its value, so that';
    $lines[] = '  this report is safe to send to somebody else. If your hosting provider';
    $lines[] = '  needs one of the withheld values, they can ask you for that one setting.';

    $body = implode("\n", $lines);

    // 🛑 Refuse rather than risk it. Nothing above should be able to contain the
    //    sign-in token, but "should not" is not "cannot".
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

$pageTitle   = 'Full PHP report';
$pageSection = 'admin';
$breadcrumbs = [
    'Dashboard'          => '/',
    'Admin'              => '/admin',
    'Server Information' => '/admin/system-info',
    'Full PHP report'    => '',
];
// 🛑 Build the whole page into memory rather than sending it as it is drawn.
//    The check at the bottom has to see EXACTLY what the reader would see —
//    including the shared header, navigation and footer. An earlier version
//    checked a separate string built from the gathered data, which left every
//    part of the page drawn by the template unchecked.
ob_start();

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-file-lines me-2"></i>Full PHP report</h1>
        <p class="text-secondary mb-0">
            Everything about the version of PHP running this portal. This is what a
            hosting company usually asks for.
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
    <p class="mb-1">
        <strong>This report is built to be safe to send to somebody else.</strong>
    </p>
    <p class="mb-0 small">
        Setting names are always shown. Their <em>values</em> are shown only for
        settings that are known not to hold a password or a key &mdash; everything else
        shows its name and withholds the value. That is deliberately cautious: a
        harmless-sounding setting really can hold a database or cache password, and this
        report is meant to be pasted into a support ticket without a second thought. If
        your hosting provider needs one of the withheld values, they can ask you for
        that one setting. Use the <strong>Plain text</strong> button to copy the whole
        report.
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
        <h2 class="h5 mb-0">Installed parts of PHP (<?php echo count($extensions); ?>)</h2>
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
            Settings<?php echo $settingsAvailable === true ? ' (' . count($settings) . ')' : ''; ?>
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
                            <i class="fa-solid fa-eye-slash me-1"></i>Value not shown
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

// 🛑 Refuse rather than risk it. Nothing gathered above should be able to hold
//    the reader's sign-in token, but "should not" is not "cannot", and this is
//    the page most likely to be photographed or pasted somewhere.
if (portalReportLeaksSessionToken($html) === true) {
    http_response_code(500);
    exit('This report was not shown, because it turned out to contain your own '
       . 'sign-in token. That should not be possible and is worth reporting.');
}

echo $html;

