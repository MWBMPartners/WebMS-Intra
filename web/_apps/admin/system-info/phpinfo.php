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
 * TWO FURTHER PRECAUTIONS
 * -----------------------
 * PHP hides nothing on its own — that was checked against a running server, not
 * assumed. A `mysqli.default_pw` setting and a `sendmail_path` with a password
 * in its arguments both print in full. So values are hidden when EITHER:
 *
 *   - the setting's NAME suggests it holds a secret, or
 *   - the VALUE looks like a credential, whatever the setting is called. This
 *     second rule matters: a perfectly innocent-sounding `session.save_path` can
 *     hold `tcp://cache:6379?auth=SECRET` when sessions are kept in Redis, and
 *     no name-based rule would ever catch that.
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
 * 🏷️ Does this setting's NAME suggest it holds a secret?
 *
 * Catches the settings that announce what they are. It cannot catch a secret
 * kept in an innocently-named setting, which is what the value check below is
 * for. The two together are much harder to slip past than either alone.
 */
const SECRET_NAME_PATTERN =
    '/(pass|passwd|pwd|_pw$|\.pw$|secret|token|credential|apikey|api[_ ]?key|private[_ ]?key|sendmail)/i';

/**
 * 🔍 Does this VALUE look like a credential, whatever the setting is called?
 *
 * Two shapes, both of which occur in ordinary hosting configurations:
 *
 *   - An address with a username and password built into it, of the form
 *     `something://user:password@host`. Database and cache addresses are
 *     routinely written this way.
 *   - A setting written as a web address with `auth=`, `password=` or similar in
 *     its query string. `session.save_path` looks exactly like this when
 *     sessions are stored in Redis with a password.
 */
const SECRET_VALUE_PATTERN =
    '#(://[^/\s:@]+:[^/\s@]+@)|((?:auth|password|passwd|pwd|secret|token|api[_-]?key)=[^&\s;"\']+)#i';

/**
 * 🙈 Decide whether a setting's value may be shown, and hide it if not.
 *
 * @param string $name  The setting's name.
 * @param string $value The setting's value.
 *
 * @return string Either the value unchanged, or a short note that it is hidden.
 */
function portalHideSecret(string $name, string $value): string
{
    if ($value === '') {
        return '';
    }
    if (preg_match(SECRET_NAME_PATTERN, $name) === 1) {
        return '[hidden — the name of this setting suggests it holds a secret]';
    }
    if (preg_match(SECRET_VALUE_PATTERN, $value) === 1) {
        return '[hidden — this value looks like it contains a password or key]';
    }
    return $value;
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

            $settings[$name] = [
                'local'  => portalHideSecret($name, $local),
                'global' => portalHideSecret($name, $globl),
            ];
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
 * @param string $haystack The finished report.
 *
 * @return bool True if the token was found in any form.
 */
function portalReportLeaksSessionToken(string $haystack): bool
{
    $token = session_id();
    if (is_string($token) === false || strlen($token) < 8) {
        return false;
    }

    $forms = [$token, rawurlencode($token), urlencode($token)];
    $decoded = rawurldecode($token);
    if ($decoded !== $token) {
        $forms[] = $decoded;
    }

    foreach (array_unique($forms) as $form) {
        if ($form !== '' && strpos($haystack, $form) !== false) {
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
            $lines[] = sprintf('  %-44s %s | %s', $k, $v['local'], $v['global']);
        }
    }
    $lines[] = '';
    $lines[] = 'Values shown as [hidden] were withheld because the setting name or its';
    $lines[] = 'value suggested it holds a password or key.';

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

// 🛑 Same check for the normal page. Run it before drawing anything.
if (portalReportLeaksSessionToken(implode("\n", array_merge(
    array_keys($general),
    array_values($general),
    array_keys($extensions),
    array_values($extensions),
    array_keys($settings),
    array_map(static fn (array $r): string => $r['local'] . ' ' . $r['global'], $settings)
))) === true) {
    http_response_code(500);
    exit('This report was not shown, because it turned out to contain your own sign-in token.');
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
        <strong>Some values are deliberately hidden.</strong>
    </p>
    <p class="mb-0 small">
        Where a setting's name or its value suggests it holds a password or a key, the
        value is replaced rather than shown. The setting's name is always left visible,
        because knowing a setting exists is useful and harmless. Your own sign-in token
        and the server's stored passwords are never gathered for this page in the first
        place. Use the <strong>Plain text</strong> button to copy the report into a
        support ticket.
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
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
