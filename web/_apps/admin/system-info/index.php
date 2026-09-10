<?php
// Path: _apps/admin/system-info/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Server Information 🖥️
 * -----------------------------------------------------------------------------
 * One page answering "what is this portal actually running on?" — the PHP
 * version, the database product and version, the settings of the connection
 * between them, and which optional pieces of PHP are installed.
 *
 * WHY IT EXISTS
 * -------------
 * Most installs of this portal sit on shared hosting, where the customer has no
 * command line and cannot inspect the server themselves. When something needs
 * diagnosing — a hosting company asking which database version you are on, or a
 * support question about an upload size limit — the answer had to be dug out of
 * three different admin screens or guessed at. This gathers it in one place.
 *
 * It also gives a straight answer to a question that could not previously be
 * answered from inside the product: is the database version we are running on
 * still supported? See Portal\Core\DbServer, which is where that judgement is
 * made and which the installation wizard uses too, so both agree.
 *
 * WHO CAN SEE IT
 * --------------
 * Administrators only. There are two levels, deliberately:
 *
 *   This page          — any administrator (App::isAdmin()). Nothing here is a
 *                        secret. Versions, limits and which extensions are
 *                        installed are the sort of thing an admin needs to be
 *                        able to read out to a support desk.
 *
 *   The PHP report     — umbrella administrators only, and on its own page.
 *   (system-info/       That report describes the whole SERVER, not this one
 *    phpinfo)           site: file paths, every setting, every installed
 *                       component. On a portal hosting several organisations,
 *                       an administrator of one of them is not necessarily the
 *                       person who runs the server. On the ordinary single-
 *                       organisation install the owner is the umbrella
 *                       administrator anyway, so nothing is lost. See the file
 *                       header of phpinfo.php for the full reasoning.
 *
 * THE DATABASE PASSWORD IS NEVER IN THIS REPORT
 * ---------------------------------------------
 * Every connection fact below is asked of the LIVE CONNECTION itself — which
 * host, which database, which user, which character set — rather than read out
 * of the credentials file. This page therefore never reads the password and
 * never prints it.
 *
 * Be precise about what that does and does not claim, because an earlier version
 * of this comment overstated it and a review caught that. It does NOT mean the
 * password is absent from the running program: the portal's start-up code
 * (`_core/bootstrap.php`) reads the credentials file into memory and uses the
 * password to open the connection, long before this page runs. What it means is
 * that this page never goes near that value, so no future edit HERE can print
 * it by accident. That is a real and useful property. It is just a smaller one
 * than "the password is never loaded at all", which was not true.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/475
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\DbServer;

// 🔐 Administrators only.
Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$db = App::db();

// 🛢️ ---------------------------------------------------------------------------
// The database: which product, which version, and is it supported?
// -----------------------------------------------------------------------------

$dbInfo = DbServer::inspect($db);

/**
 * 🔍 Ask the database one short question and hand back the single value.
 *
 * Shared hosting often refuses some of these — reading a server-wide setting
 * usually needs a privilege an ordinary hosting account does not have. That is
 * completely normal and is not a fault, so a refusal turns into a dash on the
 * page rather than an error.
 *
 * @param \mysqli $conn The open database connection.
 * @param string  $sql  A query returning exactly one column in one row.
 *
 * @return string The value, or an empty string if the database would not say.
 */
$askDb = static function (\mysqli $conn, string $sql): string {
    try {
        $rs = $conn->query($sql);
        if ($rs === false) {
            return '';
        }
        $row = $rs->fetch_row();
        $rs->free();
        return $row === null ? '' : (string) ($row[0] ?? '');
    } catch (\Throwable $e) {
        return '';
    }
};

// 📇 Facts about the connection, every one of them asked of the live connection
//    rather than read from the credentials file. See the note in the header
//    above about why that matters.
$connection = [
    'Server address'      => (string) ($db->host_info ?? ''),
    'Database name'       => $askDb($db, 'SELECT DATABASE()'),
    'Signed in as'        => $askDb($db, 'SELECT CURRENT_USER()'),
    'Port'                => $askDb($db, 'SELECT @@port'),
    'Character set'       => (string) $db->character_set_name(),
    'Database collation'  => $askDb($db, 'SELECT @@collation_database'),
    'Database time zone'  => $askDb($db, 'SELECT @@time_zone'),
    'Server time zone'    => $askDb($db, 'SELECT @@system_time_zone'),
    'Largest single statement' => $askDb($db, 'SELECT @@max_allowed_packet'),
    'Connection library'  => (string) ($db->client_info ?? ''),
    'Protocol version'    => (string) ($db->protocol_version ?? ''),
];

// ⏱️ How long the database server has been running. This one is refused more
//    often than the rest, because it reads a server-wide counter.
$uptimeText = '';
try {
    $rs = $db->query("SHOW GLOBAL STATUS LIKE 'Uptime'");
    if ($rs !== false) {
        $row = $rs->fetch_assoc();
        $rs->free();
        $seconds = (int) ($row['Value'] ?? 0);
        if ($seconds > 0) {
            $days  = intdiv($seconds, 86400);
            $hours = intdiv($seconds % 86400, 3600);
            $mins  = intdiv($seconds % 3600, 60);
            $uptimeText = sprintf('%d days, %d hours, %d minutes', $days, $hours, $mins);
        }
    }
} catch (\Throwable $e) {
    $uptimeText = '';
}
if ($uptimeText !== '') {
    $connection['Running for'] = $uptimeText;
}

// 🐘 ---------------------------------------------------------------------------
// PHP: the version, and whether it is one this portal supports.
// -----------------------------------------------------------------------------
// The same three-way answer the database check gives — supported, worth
// mentioning, or too old — but the rules are short enough to keep here rather
// than build a second class for them.

$phpState  = 'ok';
$phpNote   = 'This is a supported version of PHP and it is still receiving security fixes.';

if (version_compare(PHP_VERSION, '8.4.0', '<') === true) {
    $phpState = 'crit';
    $phpNote  = 'This portal needs PHP 8.4 or newer. Parts of the code use features '
              . 'that older versions of PHP do not have, so things will break in ways '
              . 'that are hard to diagnose. Ask your hosting provider to move this '
              . 'site to a newer PHP version.';
} elseif (version_compare(PHP_VERSION, '8.5.0', '<') === true) {
    $phpState = 'warn';
    $phpNote  = 'PHP 8.4 runs this portal perfectly well. Be aware that its active '
              . 'support ends on 31 December 2026, after which it gets security fixes '
              . 'only. PHP 8.5 is the version to move to when your hosting provider '
              . 'offers it.';
}

// 📦 The optional parts of PHP this portal relies on.
//
//    Every entry below was checked against the code before being listed, so a
//    missing one really does break the feature named beside it. Please do the
//    same before adding to this list: a warning about something the portal does
//    not actually use sends people to their hosting provider for no reason, and
//    teaches them to ignore the next warning. ('intl' was on this list at first
//    and was taken off for exactly that reason — nothing here uses it.)
$extensions = [
    'mysqli'   => 'Talking to the database. Nothing works without this one.',
    'sodium'   => 'Encrypting stored secrets, such as integration keys and pastoral notes.',
    'json'     => 'The REST API, and reading and writing stored settings.',
    'mbstring' => 'Handling accented characters, other alphabets and emoji correctly.',
    'openssl'  => 'Secure connections out to other services, and signing tokens.',
    'curl'     => 'Calling other services — email providers, payments, maps, transcription.',
    'gd'       => 'Resizing uploaded photos and generating QR codes.',
    'zip'      => 'Bundling several files into one download, such as year-end giving statements.',
    'fileinfo' => 'Checking that an uploaded file really is the kind of file it claims to be, '
                . 'rather than trusting the name it arrived with.',
];

// ⚙️ The PHP limits that most often explain a puzzling problem — an upload that
//    stops half way, a long report that gives up, a big form that loses fields.
$phpLimits = [
    'Memory limit'                 => (string) ini_get('memory_limit'),
    'Longest a page may run'       => (string) ini_get('max_execution_time') . ' seconds',
    'Largest single upload'        => (string) ini_get('upload_max_filesize'),
    'Largest form submission'      => (string) ini_get('post_max_size'),
    'Most files in one upload'     => (string) ini_get('max_file_uploads'),
    'Most fields in one form'      => (string) ini_get('max_input_vars'),
    'Default time zone'            => date_default_timezone_get(),
    'How PHP is being run'         => PHP_SAPI,
];

$pageTitle   = 'Server Information';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Server Information' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

/**
 * 🎨 Turn one of the three verdicts into a Bootstrap colour name.
 *
 * @param string $state One of 'ok', 'warn' or 'crit'.
 *
 * @return string A Bootstrap colour name.
 */
$colourFor = static function (string $state): string {
    return match ($state) {
        'ok'   => 'success',
        'warn' => 'warning',
        'crit' => 'danger',
        default => 'secondary',
    };
};
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-server me-2"></i>Server Information</h1>
        <p class="text-secondary mb-0">
            What this portal is running on: the version of PHP, the database it is
            connected to, and the limits set by your hosting.
        </p>
    </div>
    <a href="/admin" class="btn btn-outline-secondary btn-sm">&larr; Admin</a>
</div>

<!-- 🛢️ Database server -->
<div class="card mb-4 border-<?php echo $colourFor((string) $dbInfo['state']); ?>">
    <div class="card-header">
        <h2 class="h5 mb-0"><i class="fa-solid fa-database me-2"></i>Database server</h2>
    </div>
    <div class="card-body">
        <p class="mb-1">
            <span class="badge bg-<?php echo $colourFor((string) $dbInfo['state']); ?>">
                <?php echo htmlspecialchars((string) $dbInfo['engine'], ENT_QUOTES, 'UTF-8'); ?>
                <?php echo htmlspecialchars((string) $dbInfo['version'], ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </p>
        <p class="fw-semibold mb-1"><?php echo htmlspecialchars((string) $dbInfo['headline'], ENT_QUOTES, 'UTF-8'); ?></p>
        <p class="mb-3"><?php echo htmlspecialchars((string) $dbInfo['detail'], ENT_QUOTES, 'UTF-8'); ?></p>

        <div class="portal-data-list">
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">Reports itself as</div>
                <div class="col-12 col-md-8"><code><?php echo htmlspecialchars((string) $dbInfo['raw'], ENT_QUOTES, 'UTF-8'); ?></code></div>
            </div>
            <?php if ((string) $dbInfo['comment'] !== ''): ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">Edition</div>
                <div class="col-12 col-md-8"><?php echo htmlspecialchars((string) $dbInfo['comment'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 🔌 Database connection -->
<div class="card mb-4">
    <div class="card-header">
        <h2 class="h5 mb-0"><i class="fa-solid fa-plug me-2"></i>Database connection</h2>
    </div>
    <div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($connection as $label => $value): ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold"><?php echo htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-12 col-md-8">
                    <?php if ((string) $value === ''): ?>
                        <span class="text-muted">&mdash; not available on this hosting</span>
                    <?php else: ?>
                        <code><?php echo htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?></code>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">Password</div>
                <div class="col-12 col-md-8">
                    <span class="text-muted">Never shown here.</span>
                </div>
            </div>
        </div>
        <p class="small text-muted mt-3 mb-0">
            A dash means your hosting account is not permitted to read that particular
            setting. That is normal on shared hosting and is not a fault.
            The database password is not shown here and is not hidden behind dots
            either &mdash; this page never reads it. Every detail above is asked of the
            live connection rather than taken from the file that holds the password.
            That file is <code>web/_auth_keys/auth_creds.php</code>, which sits outside
            the part of the site the web server will hand out.
        </p>
    </div>
</div>

<!-- 🐘 PHP -->
<div class="card mb-4 border-<?php echo $colourFor($phpState); ?>">
    <div class="card-header">
        <h2 class="h5 mb-0"><i class="fa-brands fa-php me-2"></i>PHP</h2>
    </div>
    <div class="card-body">
        <p class="mb-1">
            <span class="badge bg-<?php echo $colourFor($phpState); ?>">PHP <?php echo htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8'); ?></span>
        </p>
        <p class="mb-3"><?php echo htmlspecialchars($phpNote, ENT_QUOTES, 'UTF-8'); ?></p>

        <div class="portal-data-list">
            <?php foreach ($phpLimits as $label => $value): ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold"><?php echo htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-12 col-md-8"><code><?php echo htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?></code></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- 📦 Extensions -->
<div class="card mb-4">
    <div class="card-header">
        <h2 class="h5 mb-0"><i class="fa-solid fa-cubes me-2"></i>Optional parts of PHP</h2>
    </div>
    <div class="card-body">
        <p class="text-secondary">
            These are add-ons to PHP that your hosting either installed or did not.
            Each one below is used by something real in this portal, so a missing one
            means a feature that will not work. If one is missing, your hosting
            provider is the person who can turn it on.
        </p>
        <div class="portal-data-list">
            <?php foreach ($extensions as $ext => $why):
                $loaded = extension_loaded($ext);
            ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-3 fw-semibold"><code><?php echo htmlspecialchars($ext, ENT_QUOTES, 'UTF-8'); ?></code></div>
                <div class="col-12 col-md-2">
                    <?php if ($loaded === true): ?>
                        <span class="badge bg-success">Installed</span>
                    <?php else: ?>
                        <span class="badge bg-warning">Not installed</span>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-7 small text-muted"><?php echo htmlspecialchars($why, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- 🖥️ This portal -->
<div class="card mb-4">
    <div class="card-header">
        <h2 class="h5 mb-0"><i class="fa-solid fa-circle-info me-2"></i>This portal</h2>
    </div>
    <div class="card-body">
        <div class="portal-data-list">
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">Portal version</div>
                <div class="col-12 col-md-8"><code><?php echo htmlspecialchars(App::version(), ENT_QUOTES, 'UTF-8'); ?></code></div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">Which copy of the site this is</div>
                <div class="col-12 col-md-8">
                    <code><?php echo htmlspecialchars((string) (defined('PORTAL_ENV') ? PORTAL_ENV : 'unknown'), ENT_QUOTES, 'UTF-8'); ?></code>
                    <span class="small text-muted d-block">
                        &ldquo;prod&rdquo; is the live site, &ldquo;beta&rdquo; and &ldquo;dev&rdquo; are the test copies.
                    </span>
                </div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">Web server</div>
                <div class="col-12 col-md-8"><code><?php echo htmlspecialchars((string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'), ENT_QUOTES, 'UTF-8'); ?></code></div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">Server time now</div>
                <div class="col-12 col-md-8"><code><?php echo htmlspecialchars(date('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8'); ?></code></div>
            </div>
        </div>
    </div>
</div>

<!-- 📋 Full PHP report — umbrella administrators only -->
<div class="card mb-4">
    <div class="card-header">
        <h2 class="h5 mb-0"><i class="fa-solid fa-file-lines me-2"></i>Full PHP report</h2>
    </div>
    <div class="card-body">
        <?php if (App::isUmbrellaAdmin() === true): ?>
            <p>
                PHP can print a complete description of itself &mdash; every setting, every
                installed component, and the paths it is working from. It is the report a
                hosting provider will usually ask you for when diagnosing an awkward
                problem.
            </p>
            <p class="small text-muted">
                The report is built here rather than handed straight over from PHP, so
                that the parts which can contain passwords, keys and your own sign-in
                token are never gathered in the first place. Values that look like a
                secret are hidden; the setting names are always shown. There is a plain
                text version for pasting into a support ticket.
            </p>
            <a href="/admin/system-info/phpinfo" class="btn btn-outline-primary" target="_blank" rel="noopener">
                <i class="fa-solid fa-up-right-from-square me-1"></i>Open the full PHP report
            </a>
        <?php else: ?>
            <p class="mb-0 text-muted">
                The full PHP report describes the whole server rather than this one
                organisation, so it is limited to umbrella administrators. Everything on
                this page above is available to you, and it covers the questions a
                hosting provider normally asks.
            </p>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
