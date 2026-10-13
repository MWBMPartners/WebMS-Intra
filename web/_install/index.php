<?php
// Path: _install/index.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — Installation Wizard
 * -----------------------------------------------------------------------------
 * The first-time set-up of a portal, for somebody who has nothing but a
 * hosting panel: no command line, no file-transfer program, no GitHub.
 *
 * This file runs with NONE of the portal loaded, because the portal cannot
 * start without the database login this wizard exists to write. It is reached
 * through the web folder's index.php, whenever `_auth_keys/auth_creds.php` is
 * missing. It loads only files that need nothing but PHP: version.php,
 * brand-defaults.php, DbServer.php, Channel.php, PortalAddress.php, and its
 * own helpers in this folder.
 *
 * WHO MAY USE IT (#499, #500). Until October 2026 the only guard was "already
 * installed", so a stranger who reached a fresh upload first could point it
 * at a database of their own, make themselves administrator, and own a portal
 * running on the customer's address. Now:
 *   - It refuses to run at all when it shows one of the two signs that the
 *     website is pointed at the folder that holds `_core`, where every
 *     private file is in reach of a browser: it was opened directly and not
 *     through the web folder, or the address asked for has the web folder's
 *     own name in it. (It cannot see the third sign: a folder list,
 *     "Forbidden" or "Not found" at the plain address. That is the web
 *     server's answer, and no file of the portal runs.)
 *   - Nothing past the first page is shown, and NO form is acted on, until
 *     the visitor has typed the ownership code the installer wrote into
 *     `_auth_keys/install-code.php` (see owner_code.php). The first page
 *     shows a stranger nothing about the server: no version, no path, no
 *     checks.
 *   - The finish asks for the code again.
 *   - Every form carries a token kept in the session, so another website
 *     cannot post a database of its choosing into a wizard somebody has
 *     already unlocked.
 *
 * THE PAGES, in the order a person meets them:
 *   code   Prove this is your hosting: the ownership code
 *   1      Checks: what the server has, and which copy of the portal this is
 *   1.5    What kind of organisation this is (the product name)
 *   2      The database: where it is and how to sign in to it
 *   2.5    What to do when that database already holds tables
 *   3      Build the tables
 *   4      The first administrator
 *   4.5    Web addresses: the portal's own address and the support e-mail
 *   5      Finish: the private folders, the key file, the settings, the lock
 *   done   What was set up and what to do next (drawn in the SAME answer as
 *          the finish: from the next request on, the web folder's index.php
 *          starts the portal and this wizard can no longer be reached)
 *
 * WHAT IT CANNOT DO
 *   - Know that the address typed on the Web addresses page really reaches
 *     this portal. That is set in the hosting panel.
 *   - Protect an installation on a web server that ignores `.htaccess` files
 *     AND is pointed at the wrong folder.
 *   - Notice a website pointed at the wrong folder when the web server
 *     hides the web folder's name from the address (a rewrite or an alias
 *     set up in the hosting panel).
 *   - Send anything. No e-mail leaves the installer, and it calls nothing
 *     outside the server except the database it is told about.
 *
 * Test: tools/installer-selftest.php
 *
 * @package   Portal\Install
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/84
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/499
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/500
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Constants for this self-contained installer
// ---------------------------------------------------------------------------
define('INSTALL_ROOT', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));
define('INSTALL_SQL', INSTALL_ROOT . DIRECTORY_SEPARATOR . '_sql');
define('INSTALL_AUTH_DIR', INSTALL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys');
define('INSTALL_CREDS_FILE', INSTALL_AUTH_DIR . DIRECTORY_SEPARATOR . 'auth_creds.php');
define('INSTALL_ENC_KEY_FILE', INSTALL_AUTH_DIR . DIRECTORY_SEPARATOR . 'enc.key');
define('INSTALL_LOCK_FILE', INSTALL_AUTH_DIR . DIRECTORY_SEPARATOR . '.installed');
// 📌 Single source of truth — same file App.php and bootstrap.php read.
// Even though this installer is bootstrap-free, _core/version.php is a
// plain `return <string>` and safe to `require` independently.
define('INSTALL_VERSION', (string) (require INSTALL_ROOT . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'version.php'));

// 🙈 PHP's own error text is never printed into these pages.
//
//    The portal's start-up decides this for every other page, but the wizard
//    runs without it, so until October 2026 it printed whatever the hosting's
//    settings said, and on much shared hosting that is "print everything". A
//    warning carries the full path of this file, which tells a stranger the
//    account's name and how the server is laid out. Errors still go to PHP's
//    error log, where the hosting panel shows them. An error nothing catches
//    gets the plain page drawn by the handler further down.
ini_set('display_errors', '0');

// 🔌 What steps 3 and 4 say when installGetDb() hands back no connection.
//    It used to read "Database connection lost. Please go back to Step 2.",
//    which was untrue for one of the three reasons installGetDb() gives up:
//    the database answered, but its connection is not strict. (The other two:
//    the details step 2 kept are gone, or the server cannot be reached.)
//    installGetDb() does not say which it was, so this names all three and
//    says how to find out.
const INSTALL_NO_CONNECTION_MESSAGE = 'The installer has no database connection it can use. The details may have been '
    . 'lost, the database may not be reachable, or it may no longer refuse values that do not fit (it is not '
    . '"strict"). Please enter the database details below again. If something is still wrong, this step will say '
    . 'what it is and what to do.';

// 🛢️ Portal\Core\DbServer opens BOTH of this wizard's database connections
//    (step 2's test connection, and installGetDb() further down this
//    file), and judges the server they reach. It is written to the same
//    bootstrap-free contract as version.php and brand-defaults.php: it needs
//    nothing but the connection, so this wizard can load it directly. It used
//    to be loaded part way through step 2; it moved up here on 12 October 2026
//    because the connection itself is now opened through it.
require_once INSTALL_ROOT . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'DbServer.php';

// 🧭 Portal\Core\Channel says which copy of the portal this is, and
//    🌐 Portal\Core\PortalAddress checks the address typed on the Web
//    addresses page. Both are written to be loaded alone. ONLY
//    PortalAddress::check() may be called here: everything below it in that
//    file needs the database and the rest of the portal.
require_once INSTALL_ROOT . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'Channel.php';
require_once INSTALL_ROOT . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'PortalAddress.php';

// 🔑 The ownership code, and (through it) the helpers this wizard shares with
//    the upgrade page: the private folders' "refuse everything" file and the
//    words for the channel.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'owner_code.php';

// 🏷️ Brand presets (issue #296) — loaded from _core/brand-defaults.php.
// Same bootstrap-free contract: plain `return <array>` so we can require
// it without the autoloader. We resolve the active preset from the session
// (set by the new Step 1.5 "organisation type" pick) and fall back to the
// generic preset for any earlier step where the user hasn't chosen yet.
$INSTALL_BRAND_PRESETS = (array) (require INSTALL_ROOT . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'brand-defaults.php');

// 🌐 INSTALL_WEB_FOLDER — the web folder, or null when this file was opened
//    directly.
//
//    The wizard is meant to be reached in ONE way: the web folder's index.php
//    finds no database login and hands the request here. PHP records which
//    file did that, and the folder of that file is the web folder, whatever
//    the hosting calls it. It is the same folder the portal's start-up will
//    be told once the installation is finished (`__DIR__` in that index.php),
//    so the channel shown here is the channel the portal will then have.
//
//    When nothing handed the request here, the web server ran this file by
//    itself. That can only happen when the website is pointed at the folder
//    that holds `_core`: see the refusal just below.
//
//    REJECTED: DOCUMENT_ROOT and SCRIPT_FILENAME. Both are what the web
//    server reports, and on some hosting a visitor's Host header shapes them;
//    Channel.php says why the channel must never be read from either.
$installCalledFrom = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? null;
$installWebFolder  = is_string($installCalledFrom) === true ? dirname($installCalledFrom) : null;
// Only a folder that sits directly inside the folder that holds `_core` is a
// web folder of THIS installation. Anything else is treated as "not known".
if ($installWebFolder !== null && realpath(dirname($installWebFolder)) !== INSTALL_ROOT) {
    $installWebFolder = null;
}
define('INSTALL_WEB_FOLDER', $installWebFolder);

/**
 * 🧾 One small page that stands by itself, then stop.
 *
 * For the three moments when the wizard cannot draw its ordinary page: it is
 * already installed, it was opened directly, or something broke that nothing
 * caught. The page picks up the visitor's light or dark setting and uses the
 * portal's colours, with no outside stylesheet, so it reads properly even
 * when nothing else on the server works.
 *
 * @param int    $status   The HTTP status to answer with.
 * @param string $heading  The page's title and its one top-level heading. Plain text.
 * @param string $bodyHtml What goes under the heading. HTML: every value in
 *                         it must already be escaped by whoever built it.
 */
function installPlainPage(int $status, string $heading, string $bodyHtml): never
{
    if (headers_sent() === false) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        // Never kept by a browser or a cache in between, and never indexed.
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
    }
    $title = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><title>' . $title . '</title>'
       . '<meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow">'
       . '<style>'
       . ':root{'
       .   '--bg:#f7f8fa;--surface:#ffffff;--text:#1b2330;--muted:#6b7280;'
       .   '--border:#e5e7eb;--primary:#5e6ad2;--primary-hover:#4f5bbf;'
       .   '--code-bg:#eef0fb;--code-text:#1b2330;'
       . '}'
       . '@media (prefers-color-scheme: dark){:root{'
       .   '--bg:#0f1115;--surface:#161a22;--text:#e8eaf0;--muted:#9aa3b2;'
       .   '--border:#2c3441;--primary:#7b86e8;--primary-hover:#8f99eb;'
       .   '--code-bg:#1f2347;--code-text:#e8eaf0;'
       . '}}'
       . 'html,body{height:100%;}'
       . 'body{font-family:"Plus Jakarta Sans",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;'
       .   'background:var(--bg);color:var(--text);'
       .   'padding:4rem 1.25rem;margin:0;line-height:1.55;}'
       . '.card{max-width:600px;margin:0 auto;background:var(--surface);'
       .   'border:1px solid var(--border);border-radius:.75rem;padding:2.5rem 2rem;'
       .   'box-shadow:0 4px 6px -1px rgba(16,24,40,.08),0 2px 4px -2px rgba(16,24,40,.06);}'
       . 'h1{margin:0 0 1rem;font-weight:600;letter-spacing:-.01em;font-size:1.6rem;}'
       . 'p,li{margin:0 0 1rem;color:var(--text);}'
       . 'ol,ul{padding-left:1.25rem;margin:0 0 1rem;}'
       . 'code{background:var(--code-bg);color:var(--code-text);'
       .   'padding:.125rem .4rem;border-radius:.375rem;font-size:.9em;overflow-wrap:anywhere;'
       .   'font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;}'
       . '.btn{display:inline-block;padding:.625rem 1.25rem;background:var(--primary);'
       .   'color:#fff;text-decoration:none;border-radius:.375rem;margin-top:.5rem;'
       .   'font-weight:500;transition:background 120ms ease;}'
       . '.btn:hover,.btn:focus{background:var(--primary-hover);color:#fff;}'
       . '</style></head>'
       . '<body><main class="card"><h1>' . $title . '</h1>' . $bodyHtml . '</main></body></html>';
    exit();
}

/**
 * 🚫 The page for a website that is pointed at the wrong folder, then stop.
 *
 * One page for the two signs of that mistake (see the two refusals further
 * down), so that both give the same plain words about what to change in the
 * hosting panel. It writes nothing and offers no form.
 *
 * @param string|null $webFolderName The web folder's own name, when the
 *                                   installer knows it (the request came
 *                                   through the web folder); null when it was
 *                                   opened directly and cannot know.
 */
function installWrongFolderPage(?string $webFolderName): never
{
    $name = $webFolderName === null ? '' : htmlspecialchars($webFolderName, ENT_QUOTES, 'UTF-8');

    // The three steps, the same for both signs. Only the second differs: it
    // names the web folder when the installer knows what it is called.
    $whatToDo = '<p><strong>What to do, in your hosting panel:</strong></p>'
        . '<ol><li>Find the setting for the folder this website is served from. Hosting companies call it the '
        . '&ldquo;web directory&rdquo;, the &ldquo;document root&rdquo; or the &ldquo;site folder&rdquo;.</li>'
        . ($webFolderName === null
            ? '<li>Change it to the web folder <em>inside</em> the folder it points at now: the one that holds '
              . '<code>index.php</code> and a folder named <code>assets</code>. The installation guide (<code>INSTALL.md</code>, '
              . 'in the package) says what that folder is called.</li>'
            : '<li>Change it to the web folder <em>inside</em> the folder it points at now. That folder is named <code>' . $name
              . '</code>: it is the one that holds <code>index.php</code> and a folder named <code>assets</code>.</li>')
        . '<li>Wait until the change has taken effect (some hosts take a few minutes), then open your website&rsquo;s plain '
        . 'address, with nothing after it. The installer starts by itself.</li></ol>'
        . '<p>Nothing has been installed and nothing has been changed.</p>';

    if ($webFolderName === null) {
        installPlainPage(
            403,
            'The website is pointing at the wrong folder',
            '<p>The portal&rsquo;s files are on the server, but this page was opened from inside them. That means the website&rsquo;s '
            . 'address is set to the folder that holds <code>_core</code>, <code>_apps</code> and <code>_install</code>. Those folders '
            . 'are private: with the address set like this, anybody could ask the server for the files in them.</p>'
            . $whatToDo
        );
    }

    // The heading here does not say "is pointing at the wrong folder",
    // because from an address alone that is likely and not certain: the
    // folder's name may simply have been typed into the address of a rightly
    // pointed website. The page says which case is which.
    installPlainPage(
        403,
        'The installer will not run at this address',
        '<p>The address you opened has the name of the portal&rsquo;s web folder in it (<code>' . $name . '</code>). On a website '
        . 'that is set up correctly that name is never part of an address, so the installer has stopped.</p>'
        . '<p><strong>If your website&rsquo;s plain address, with nothing after it, shows a list of folders, '
        . '&ldquo;Forbidden&rdquo; or &ldquo;Not found&rdquo;,</strong> the website is pointing at the wrong folder: its address is '
        . 'set to the folder that holds <code>_core</code>, <code>_apps</code> and <code>_install</code>. Those folders are '
        . 'private: with the address set like this, anybody could ask the server for the files in them.</p>'
        . $whatToDo
        . '<p>If your website&rsquo;s plain address already shows the installer, the setting is right. Use the plain address, and '
        . 'leave <code>' . $name . '</code> out of it.</p>'
    );
}

// 🧯 An error that nothing below catches.
//
//    Without this the visitor gets a blank page, or the hosting company's own
//    error page, at the one moment they most need to be told what to do. The
//    real message goes to PHP's error log and is NOT shown: it can hold a
//    path, or part of a database query.
set_exception_handler(static function (\Throwable $problem): void {
    error_log('[WebMS-Intra] Installer: ' . get_class($problem) . ': ' . $problem->getMessage()
        . ' at ' . $problem->getFile() . ':' . $problem->getLine());
    installPlainPage(
        500,
        'The installer hit a problem',
        '<p>Something went wrong that the installer did not expect, so it stopped. It has not gone on to change anything else.</p>'
        . '<p><strong>What to do:</strong> go back and try the step again. If this page comes back, look in your hosting panel for '
        . 'the PHP error log (often called &ldquo;Error log&rdquo;): the newest line that begins <code>[WebMS-Intra] Installer</code> '
        . 'says what happened. Send that line to whoever supports your portal.</p>'
        . '<p><a class="btn" href="?step=1">Back to the installer</a></p>'
    );
});

// ---------------------------------------------------------------------------
// Block re-installation if lock file exists
// ---------------------------------------------------------------------------
// The lock file OR the database login: either one means a portal is, or was,
// installed here. Once the login exists the web folder's index.php starts the
// portal and never hands a request to this wizard again, so in practice this
// page is seen only by somebody who opened this file directly.
if (is_file(INSTALL_LOCK_FILE) === true || is_file(INSTALL_CREDS_FILE) === true) {
    installPlainPage(
        403,
        'Already installed',
        '<p>The portal has already been installed here, so the installer will not run.</p>'
        . '<p>Running it again is for whoever looks after the hosting: it needs the files <code>.installed</code> and '
        . '<code>auth_creds.php</code> to be removed from the <code>_auth_keys</code> folder first. Do not do that on a '
        . 'working portal without a backup of the database and of the whole <code>_auth_keys</code> folder.</p>'
        . '<p><a class="btn" href="/">Go to the portal</a></p>'
    );
}

// ---------------------------------------------------------------------------
// 🚫 Opened directly: the website is pointed at the wrong folder
// ---------------------------------------------------------------------------
// See INSTALL_WEB_FOLDER above. An installation finished from here would
// have its database login, its key file, its uploads and its backups all
// inside the folder the web server hands out. So nothing is offered: no
// code is written, no form is shown. The web folder is not named, because
// from here the installer cannot know which folder it is. The page itself is
// installWrongFolderPage(), shared with the second sign just below.
if (INSTALL_WEB_FOLDER === null) {
    installWrongFolderPage(null);
}

// ---------------------------------------------------------------------------
// 🚫 Reached through the web folder's own name: the same mistake, half hidden
// ---------------------------------------------------------------------------
// WHAT WAS WRONG (found by the check of 13 October 2026). With the website
// pointed at the folder that holds `_core`, the plain address shows a folder
// list, "Forbidden" or "Not found", and `/_install/` is refused just above.
// But `/<the web folder's name>/` ran the web folder's index.php, which
// handed the request here exactly as it does on a rightly pointed website:
// the installer ran with no warning and finished, and a stranger was then
// handed `/_auth_keys/enc.key`.
//
// The sign is in the ADDRESS: on a rightly pointed website the web folder is
// the top of the address, so its name never appears in one. So when any part
// of the address asked for IS the web folder's name, nothing is offered: no
// code is written, no form is shown. The name is read from the folder itself
// (INSTALL_WEB_FOLDER, which is where the file that handed the request over
// really sits) and never from anything typed or from what the web server
// reports about itself.
//
// EVERY part of the address is looked at, not only the first, so that a
// package unpacked into a folder of an existing website
// (`/portal/<web folder>/`) is caught as well. Capitals are ignored, because
// many disks do not tell `HTTPDOCS` from `httpdocs`. The address is decoded
// first: `/%68ttpdocs/` reaches the same folder. A full stop or a space at
// the end of a part is ignored, because Windows drops both from a name.
//
// The address is taken apart BY HAND, not with parse_url(). REJECTED:
// parse_url(), which was the first draft. It reads `//httpdocs/index.php` as
// a host named "httpdocs" with the path `/index.php`, while a web server
// reads the same request as the folder `httpdocs`: the one address that
// mattered would have been waved through.
//
// REJECTED: SCRIPT_NAME, which would say the same thing more directly. It is
// worked out by the web server from its own settings, and a host that sets it
// oddly would shut an owner out of a rightly pointed website with advice to
// change a setting that is already right. The address is what the person
// typed, so a refusal here can always be explained to them.
//
// WHAT IT COSTS: on a rightly pointed website, an address with the web
// folder's name typed into it by mistake is refused too. The page says so,
// and says to use the plain address. A website served on purpose under a
// path named after the web folder cannot use the installer at all; the
// installation guide already asks for the portal to be at the top of its
// address.
//
// WHAT IT CANNOT DO: see the mistake when the web server hides the folder's
// name from the address, reports no address at all, or is reached by the
// short name Windows gives a long folder name (`ADMIN_~1`).
$installAddressAsked = is_string($_SERVER['REQUEST_URI'] ?? null) === true ? $_SERVER['REQUEST_URI'] : '';
// Only the path: what comes before the first "?" or "#".
$installPathAsked = explode('#', explode('?', $installAddressAsked, 2)[0], 2)[0];
// A request may carry the whole address (`https://name/path`; proxies send
// that). The scheme and the name are then taken off, so that a web folder
// named after the website's own name is not mistaken for a part of the path.
if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*://[^/\\\\]*#', $installPathAsked, $installAddressLead) === 1) {
    $installPathAsked = substr($installPathAsked, strlen($installAddressLead[0]));
}
$installWebFolderName = strtolower(basename(INSTALL_WEB_FOLDER));
foreach (preg_split('#[/\\\\]+#', rawurldecode($installPathAsked)) ?: [] as $installPathPart) {
    if ($installWebFolderName !== '' && strtolower(rtrim($installPathPart, ' .')) === $installWebFolderName) {
        installWrongFolderPage(basename(INSTALL_WEB_FOLDER));
    }
}

// ---------------------------------------------------------------------------
// Session for wizard state
// ---------------------------------------------------------------------------
// 🔒 The same cookie rules the portal itself uses (Auth::prepareSessionStart()),
//    written out here because the wizard cannot load Auth. They matter more
//    than they did: the session now carries the proof that this visitor typed
//    the ownership code. `httponly` keeps the cookie from scripts; `samesite`
//    stops another website's form bringing the cookie with it; `secure` keeps
//    it off plain http where the site has https. The session's NAME is left
//    alone on purpose: step 2 recognises somebody who is signed in to the
//    portal by reading the portal's own session.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (isset($_SERVER['HTTPS']) === true && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // A session cookie PHP itself would refuse is dropped before the session
    // starts, as the portal does (#509): otherwise session_start() raises a
    // warning and the visitor is left with no session at all, so the code
    // they type is forgotten at once and asked for again, for ever.
    $installCookie = session_name();
    if (isset($_COOKIE[$installCookie]) === true
        && (is_string($_COOKIE[$installCookie]) === false
            || preg_match('/^[a-zA-Z0-9,-]{1,256}$/', $_COOKIE[$installCookie]) !== 1)
    ) {
        unset($_COOKIE[$installCookie]);
    }
    session_start();
}

/**
 * ✍️ One form field or address field as text. A hand-made request can send a
 * list where text is expected (`db_host[]=x`); handing a list to trim() or
 * htmlspecialchars() ends the page with an error. A list is read as empty.
 *
 * @param string $name   The field's name.
 * @param string $source 'post' (the default), or 'get'.
 */
function installField(string $name, string $source = 'post'): string
{
    $value = $source === 'get' ? ($_GET[$name] ?? '') : ($_POST[$name] ?? '');

    return is_string($value) === true ? $value : '';
}

/**
 * 🎫 The token every form of the wizard carries, kept in the session.
 *
 * WHY. The ownership code keeps strangers out, but once the owner has typed
 * it their browser holds an unlocked session. Without a token, a page on
 * another website could post the database step for them, with a database of
 * its own choosing, and the owner would carry on installing onto it without
 * knowing. A form that does not carry this token is not acted on.
 */
function installFormToken(): string
{
    if (is_string($_SESSION['install_form_token'] ?? null) === false || strlen($_SESSION['install_form_token']) !== 64) {
        $_SESSION['install_form_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['install_form_token'];
}

/** The hidden field that carries the token, ready to print inside a form. */
function installTokenField(): string
{
    return '<input type="hidden" name="install_token" value="' . htmlspecialchars(installFormToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * 🪪 A mark for the database a page was drawn for.
 *
 * WHY. The choice page ("carry on, or wipe") and the "Build the tables" page
 * are each ABOUT one database, and the session holds only the database typed
 * last. With two tabs open, or after the browser's Back button, a page drawn
 * for database ONE can still be on the screen when the session has moved on
 * to database TWO. Its button would then be acted on for TWO: the person
 * would confirm a wipe on a page that describes one database and have it
 * recorded for another. So both forms carry this mark, and a form whose mark
 * is not the mark of the database the installer holds NOW is not acted on.
 *
 * It is made from the database's identity (see installDbIdentity()) and this
 * session's form token, so it says nothing readable about the database and
 * is worth nothing in another session.
 *
 * @return string 64 characters; '' when the installer holds no database.
 */
function installDatabaseMark(): string
{
    $identity = installDbIdentity($_SESSION['install_db'] ?? null);

    return $identity === '' ? '' : hash_hmac('sha256', $identity, installFormToken());
}

/** The hidden field that carries that mark, ready to print inside a form. */
function installDatabaseField(): string
{
    return '<input type="hidden" name="install_for" value="' . htmlspecialchars(installDatabaseMark(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Was the form just sent drawn for the database the installer holds now? Never true when it holds none. */
function installFormIsForThisDatabase(): bool
{
    $mark = installDatabaseMark();

    return $mark !== '' && hash_equals($mark, installField('install_for')) === true;
}

/** What a form is told when it was drawn for another database. Nothing has been done by then. */
const INSTALL_OTHER_DATABASE_MESSAGE = 'That button was on a page drawn for a different database from the one the installer '
    . 'holds now. This happens when the database details are typed again in another tab, or after the browser\'s Back '
    . 'button. Nothing was built and nothing was removed. This page is about the database the installer holds now: '
    . 'check its name below before you choose.';

/**
 * 🔑 Has this visitor typed the ownership code that is in the file NOW?
 *
 * The session holds a fingerprint of the code that was accepted, never the
 * code. It must equal the fingerprint of the code file as it stands on this
 * request. So when the code is replaced (ten wrong tries by anybody), or the
 * file goes, every session let in by the old code is asked again.
 */
function installOwnerPassed(): bool
{
    $kept = $_SESSION['install_owner'] ?? null;
    $now  = installCodeFingerprint(INSTALL_AUTH_DIR);

    return is_string($kept) === true && $kept !== '' && $now !== '' && hash_equals($now, $kept) === true;
}

// ---------------------------------------------------------------------------
// 🔑 The ownership code: make sure there is one, and see who has typed it
// ---------------------------------------------------------------------------
// Written on the first visit by anybody, which is the point: the stranger
// who gets here first causes a code to be written that they cannot read.
// `$ownerCodeProblem` is '' unless the file could not be written.
$ownerCodeProblem = installCodeEnsure(INSTALL_ROOT, INSTALL_AUTH_DIR);
$ownerPassed      = installOwnerPassed();
// Somebody who typed a code that has since been replaced is told so, once
// they are asked again: otherwise the same code would simply be "wrong".
$ownerCodeChanged = ($ownerPassed === false && is_string($_SESSION['install_owner'] ?? null) === true);

// 🧭 Which copy of the portal this is, and how that was decided. Asked of
//    the one place that decides it, with the same two things the portal's
//    start-up will hand it, so the answer is the one the finished portal has.
$installChannel      = \Portal\Core\Channel::decide(INSTALL_ROOT, INSTALL_WEB_FOLDER);
$installChannelWords = installChannelWords(
    $installChannel['channel'],
    $installChannel['source'],
    basename(INSTALL_WEB_FOLDER),
    installChannelFileIgnored(INSTALL_ROOT, $installChannel['source'])
);
// 📦 A package carries its word in VERSION.txt, and the finish writes it into
//    the installation's own `.channel` file when there is none (see
//    installWriteChannelFile()). Until then the sentence above is true as it
//    stands (nothing says yet), so only the advice changes: "create the file
//    yourself" becomes "the installer will".
$installPackageWord = installPackageChannelWord(INSTALL_ROOT);
if ($installPackageWord !== '' && file_exists(\Portal\Core\Channel::filePath(INSTALL_ROOT)) === false
    && $installChannel['source'] !== \Portal\Core\Channel::SOURCE_ENVIRONMENT
) {
    $installChannelWords['advice'] = [installChannelFileWords(['state' => 'will-write', 'word' => $installPackageWord])];
}

// ---------------------------------------------------------------------------
// Determine current step
// ---------------------------------------------------------------------------
// 🪞 Step is normally 1-6, with three in-between pages encoded as string
//    steps so they remain unambiguous in URLs without changing the integer
//    comparisons used throughout the file:
//      • '1.5' — organisation type (brand) picker (issue #296)
//      • '2.5' — existing-data choice (continue / drop)
//      • '4.5' — web addresses (#500)
//    Tracked via separate booleans so the integer $step still flows linearly.
//    Step 6, "done", is never reached by its number: see $justFinished.
$rawStep = installField('step', 'get');
if ($rawStep === '') {
    $rawStep = installField('step');
}
$isIndustryStep = ($rawStep === '1.5');
$isChoiceStep   = ($rawStep === '2.5');
$isAddressStep  = ($rawStep === '4.5');
$step = $isChoiceStep ? 2 : ($isIndustryStep ? 1 : ($isAddressStep ? 4 : (int) $rawStep));
if ($step < 1 || $step > 5) {
    $step = 1;
    $isChoiceStep = false;
    $isIndustryStep = false;
    $isAddressStep = false;
}
// Set by the finish, in the request that finishes: the only way to the last
// page. It used to be `?step=6`, which nobody could ever see: the finish
// wrote the database login and redirected, and from that moment the web
// folder's index.php started the portal on every request, this one included.
$justFinished = false;
// What the finish did, for the last page to say.
$finishReport = [];

// Enforce step progression — cannot skip ahead without completing prior steps
if (($step >= 3 || $isChoiceStep) && isset($_SESSION['install_db']) === false) {
    $step = 2; // DB credentials not yet configured
    $isChoiceStep = false;
    $isAddressStep = false;
}
// The finish needs the two addresses: without them, back to their page.
if ($step === 5 && is_array($_SESSION['install_addresses'] ?? null) === false) {
    $step = 4;
    $isAddressStep = true;
}

// 🏷️ Resolve the active brand preset from the session pick (Step 1.5).
//    Defaults to the generic preset for steps that haven't picked yet.
//    All hardcoded "WebMS Intra" references in the installer use these
//    variables, so the wizard rebrands the moment the admin chooses.
$selectedIndustry = is_string($_SESSION['install_industry'] ?? null) === true ? $_SESSION['install_industry'] : '';
$INSTALL_BRAND = $INSTALL_BRAND_PRESETS[$selectedIndustry]
              ?? $INSTALL_BRAND_PRESETS['']
              ?? ['name' => 'WebMS Intra', 'tagline' => 'Internal Management System', 'publisher' => 'MWBM Partners Ltd (t/a MWservices)'];
$INSTALL_PRODUCT_NAME      = (string) ($INSTALL_BRAND['name']      ?? 'WebMS Intra');
$INSTALL_PRODUCT_TAGLINE   = (string) ($INSTALL_BRAND['tagline']   ?? 'Internal Management System');
$INSTALL_PRODUCT_PUBLISHER = (string) ($INSTALL_BRAND['publisher'] ?? 'MWBM Partners Ltd (t/a MWservices)');

// 🎨 Asset folder slug — points at the web folder's assets/images/brandkit/assets/<slug>/.
//    Drives the favicon + apple-touch-icon in the installer head. The default
//    (webms-intra) ships first; once Step 1.5 saves the picked industry into
//    $_SESSION['install_industry'], the next render of the wizard automatically
//    swaps in that brand's icons (ChurchMS → churchms/icon.svg, etc.). The
//    school/charity/community/business presets ship starter SVG icon.svg /
//    icon-192.svg / icon-512.svg sets (#306) but NOT the PNG/.ico variants
//    below (icon-32.png etc.) — those `<link>`s 404 harmlessly for those
//    four presets in browsers that don't support SVG favicons.
$INSTALL_BRAND_ASSET_FOLDER = (string) ($INSTALL_BRAND['assetFolder'] ?? 'webms-intra');
// Light defensive cleanup — only allow lowercase alphanumerics + dashes in the
// path segment, even though brand-defaults.php is trusted source. Avoids any
// chance of a malformed preset injecting /../ into the favicon URL.
if (preg_match('/^[a-z0-9\-]{1,40}$/', $INSTALL_BRAND_ASSET_FOLDER) !== 1) {
    $INSTALL_BRAND_ASSET_FOLDER = 'webms-intra';
}

// ---------------------------------------------------------------------------
// Handle POST submissions
// ---------------------------------------------------------------------------
$error = '';
$success = '';

// 🔍 The words for what a database already holds (DB_STATE_…). Loaded for
//    every request. It used to be loaded only inside step 2's handler, while
//    the page AFTER it (2.5, "this database already holds tables") and that
//    page's own handler both use its constants: so that page ended with
//    "Undefined constant" for everybody, and neither "continue with what is
//    there" nor "start again" could be reached.
require_once INSTALL_ROOT . DIRECTORY_SEPARATOR . '_install' . DIRECTORY_SEPARATOR . 'db_state.php';

// What the code page says when a code is refused. Never how close it was.
const INSTALL_WRONG_CODE_MESSAGE = 'That is not the code in the file. Open the file again and copy the code exactly: '
    . 'sixteen letters in four groups. Nothing has been changed.';
const INSTALL_NEW_CODE_MESSAGE = 'That is not the code in the file. It was the tenth wrong code in a row, so the installer '
    . 'has written a NEW code into the same file and the old one no longer works. Open the file again and type the new code.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = installField('action');

    // 🎫 A form that does not carry this session's token is not acted on:
    //    see installFormToken(). It is the first test, before the code is
    //    even looked at, so a form posted from another website can do
    //    nothing at all, not even use up a wrong try.
    if (hash_equals(installFormToken(), installField('install_token')) === false) {
        $error = 'That form could not be used: it came from a page that had gone out of date. This happens when the '
               . 'installer has been left open for a long time, or when the browser is not keeping the installer\'s '
               . 'cookie. Nothing has been changed. Please try again; if you see this every time, allow cookies for '
               . 'this website.';
        $action = '';
    }

    // 🔑 The ownership code, typed on the first page.
    if ($action === 'owner_code') {
        $judged = installCodeJudge(INSTALL_AUTH_DIR, installField('owner_code'));
        if ($judged === INSTALL_CODE_RIGHT) {
            // A new session number for the session that is now trusted, so a
            // number somebody else knew before this moment is worth nothing.
            session_regenerate_id(true);
            $_SESSION['install_owner'] = installCodeFingerprint(INSTALL_AUTH_DIR);
            header('Location: ?step=1');
            exit();
        }
        if ($judged === INSTALL_CODE_REPLACED) {
            $error = INSTALL_NEW_CODE_MESSAGE;
        } elseif ($judged === INSTALL_CODE_BLANK) {
            $error = 'Type the code from the file into the box, then press Continue.';
        } elseif ($judged === INSTALL_CODE_NO_FILE) {
            $error = 'The file that holds the code could not be read just now. Reload this page: the installer writes a '
                   . 'new one when it is missing.';
        } else {
            $error = INSTALL_WRONG_CODE_MESSAGE;
        }
        // Whatever the answer, this request is not let in.
        $ownerPassed = false;
        $action      = '';
    }

    // 🚪 THE GATE. Without the code, no other form is acted on: not the
    //    database step (which would make this server connect to any address
    //    a stranger names), not the finish, nothing. The page drawn below is
    //    then the code page, whatever step was asked for.
    if ($ownerPassed === false) {
        $action = '';
    }

    // Step 2: Test and save database credentials
    if ($action === 'db_config') {
        $dbHost = trim(installField('db_host'));
        $dbUser = trim(installField('db_user'));
        $dbPass = installField('db_pass');
        $dbName = trim(installField('db_name'));
        $dbPort = installField('db_port') === '' ? 3306 : (int) installField('db_port');

        if ($dbHost === '' || $dbUser === '' || $dbName === '') {
            $error = 'Please fill in the database host, the user name and the database name.';
            $step = 2;
        } elseif (preg_match('//u', $dbName) !== 1
            || preg_match_all('/./us', $dbName) > 64
            || preg_match('/[\x00-\x1F\/\\\\.]/', $dbName) === 1
        ) {
            // 🚧 MySQL will not accept these anyway, and refusing them here means
            //    a clear message instead of a puzzling database error. The
            //    control characters, slashes and full stops are the ones it
            //    forbids because they have a meaning in the files it stores
            //    databases in.
            //
            //    The length is counted in CHARACTERS, not bytes. MySQL's limit
            //    of 64 is 64 characters, and a name written in an alphabet with
            //    accents takes two bytes per letter — so counting bytes would
            //    have refused a 33-letter name that MySQL is perfectly happy
            //    with. Ordinary names are unaffected either way; this only
            //    matters for names outside plain English, which is exactly the
            //    kind of thing that gets missed.
            //
            //    ⚠️ COUNTED WITHOUT mbstring, ON PURPOSE. The obvious way to do
            //    this is mb_strlen() and mb_check_encoding(). Both live in an
            //    OPTIONAL part of PHP, and this wizard has to work on a server
            //    that does not have it — that is one of the things it exists to
            //    tell you about. Calling a function that is not there raises an
            //    error the handler below cannot catch, so the wizard would die
            //    with a blank page on exactly the server whose owner most needs
            //    to be told what is wrong.
            //
            //    The first screen does check for mbstring, but that is a screen,
            //    not a gate: this handler runs whether or not anybody looked at
            //    it. So the check below uses pattern matching instead, which is
            //    always built into PHP. The `u` at the end of a pattern means
            //    "treat this as UTF-8", and a pattern with it fails outright on
            //    text that is not valid UTF-8 — which gives us both answers we
            //    need, with nothing optional involved. Checked against the
            //    mbstring versions: they agree on every case.
            $error = 'That database name cannot be used. Database names can be up to 64 '
                   . 'characters long and cannot contain a full stop, a slash, a backslash, '
                   . 'or any invisible control characters. Check the name in your hosting '
                   . 'control panel and enter it exactly as it appears there.';
            $step = 2;
        } else {
            // Test connection without database first
            mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
            try {
                // 🔒 Opened through DbServer::connect(), which sets the same
                //    character set this line always did (utf8mb4) and also
                //    makes the connection strict. See the note beside the
                //    require at the top of this file.
                $testConn = \Portal\Core\DbServer::connect($dbHost, $dbUser, $dbPass, '', $dbPort);

                // 🛢️ Now that we are actually talking to a database server, ask
                //    it which product and version it is, and whether that is one
                //    this portal can run on.
                //
                //    This cannot be part of the first screen's checks, because
                //    at that point nobody has typed any database details in yet.
                //    Here is the first moment the answer exists.
                //
                //    See the file header of DbServer.php before adding to it.
                $dbServer = \Portal\Core\DbServer::inspect($testConn);
                $_SESSION['install_db_server'] = $dbServer;

                // 🛑 'crit' means the database is older than this portal's own
                //    database changes can run on. Carrying on would fail
                //    part-way through and leave a half-built database behind,
                //    with an error message that explains nothing. Stop here
                //    instead and say why, in words.
                //
                //    Only 'crit' stops. A 'warn' — most often "this version no
                //    longer gets security fixes" — is shown on the next screen
                //    and never blocks: on shared hosting the customer usually
                //    cannot change the database version at all, so refusing to
                //    install would simply lock them out of their own portal.
                if ($dbServer['state'] === 'crit') {
                    $error = $dbServer['headline'] . '. ' . $dbServer['detail'];
                    $step  = 2;
                    // 🚫 Deliberately NOT closing the connection here. The end
                    //    of this try block already closes it on every path that
                    //    does not redirect away, and closing a mysqli
                    //    connection twice throws an `Error` — which is NOT a
                    //    `mysqli_sql_exception`, so the catch below would miss
                    //    it and the wizard would die with a blank HTTP 500 on
                    //    the very screen meant to explain the problem.
                }

                // 🔒 Will this database refuse a value that does not fit?
                //
                //    The portal asks every connection to be strict, so that a
                //    text too long for its column, or a value that is not on a
                //    column's list of choices, is refused with an error
                //    instead of being stored changed. DbServer::connect()
                //    above has already asked; this reads the answer back.
                //
                //    Only 'crit' stops, which means "not strict at all": the
                //    database refused, and the host's own setting is loose.
                //    Stopping here costs nothing, because nothing has been
                //    built yet, and it is the same rule as the version check
                //    just above. Carrying on would build a portal that
                //    quietly alters what people type into it.
                //
                //    A 'warn' (strict, but not the exact list asked for) never
                //    blocks. Once the portal is installed, its health page,
                //    admin dashboard and Server Information page report it.
                //
                //    The version check is asked first on purpose: on a server
                //    too old to run the portal that is the message to show.
                if ($error === '') {
                    $dbStrictness = \Portal\Core\DbServer::strictness($testConn);
                    if ($dbStrictness['state'] === 'crit') {
                        $error = $dbStrictness['headline'] . '. ' . $dbStrictness['detail'];
                        $step  = 2;
                    }
                }

                // 🚧 Everything below is skipped once we have an error. Creating
                //    a database on a server too old to hold the schema would
                //    leave an empty database behind for no reason.
                if ($error === '') {
                    // 🔎 Is the database there already?
                    //
                    //    This looks like it should be a simple true/false, and it
                    //    used to be written that way. It is not. Line 187 above
                    //    puts mysqli into its strict mode, where a failure raises
                    //    an exception instead of returning false. So on a server
                    //    where the database does not exist yet, select_db() THROWS
                    //    — it never returns false — and the create-it-for-you code
                    //    below could never run. The outer catch then reported
                    //    "Database connection failed", which is both wrong and
                    //    unhelpful: the sign-in worked perfectly, and the account
                    //    may well have been allowed to create the database.
                    //
                    //    Catching it here, and only here, restores the intended
                    //    behaviour. Any other database error is deliberately left
                    //    to the outer catch, because "it is missing" and "the
                    //    server refused us" need different answers.
                    //    TWO error numbers matter here, not one, and the second
                    //    is the one that actually turns up on shared hosting:
                    //
                    //      1049  "Unknown database" — what an account with full
                    //            rights sees when the database is not there.
                    //
                    //      1044  "Access denied for user ... to database ..." —
                    //            what a NORMAL shared-hosting account sees, for
                    //            the same situation. A restricted account is not
                    //            allowed to know whether the database exists, so
                    //            the server refuses rather than saying "missing".
                    //            Tested against a deliberately limited account:
                    //            this, not 1049, is what comes back.
                    //
                    //    Both mean the same thing to us — we cannot use that
                    //    database — so both fall through to the attempt to create
                    //    it, which then either works or produces its own clear
                    //    message. Every other error is a genuinely different
                    //    problem and is left to the outer catch.
                    $dbExists = false;
                    try {
                        $dbExists = $testConn->select_db($dbName);
                    } catch (\mysqli_sql_exception $e) {
                        if (in_array($e->getCode(), [1049, 1044], true) === false) {
                            throw $e;
                        }
                        $dbExists = false;
                    }

                    if ($dbExists === false) {
                        // 🏗️ Try to create it.
                        //
                        //    Same trap as select_db() above: in strict mode a
                        //    refusal THROWS rather than returning false, so the
                        //    helpful "create it yourself in your hosting control
                        //    panel" message below could never be reached. On
                        //    shared hosting a refusal here is the NORMAL case —
                        //    most accounts are not allowed to create databases —
                        //    so this is the message people most need to see.
                        //    Catch only the refusals that mean "you are not
                        //    allowed to do this", and let anything else through.
                        //    An earlier version caught everything, which turned a
                        //    lost connection or a disk problem into the words
                        //    "you were not allowed" — hiding the real cause and
                        //    sending the reader off to fix the wrong thing.
                        //
                        //      1044 / 1045 / 1142  the account is not permitted
                        //      1007               it already exists (a second
                        //                         person got there first, or the
                        //                         earlier check was refused
                        //                         rather than answered)
                        $createResult = false;
                        try {
                            // 🔐 A database NAME is an identifier, not a piece of
                            //    text, and the two are escaped differently.
                            //
                            //    This used to call real_escape_string(), which is
                            //    the tool for text inside quotes. It escapes
                            //    quotes and backslashes and leaves the backtick
                            //    alone — and the backtick is exactly the
                            //    character that ends an identifier. A name
                            //    containing one therefore broke straight out of
                            //    the surrounding backticks, so a name like
                            //    evil`;DROP DATABASE something;-- would have run
                            //    as its own statement.
                            //
                            //    MySQL's own rule for identifiers is to double
                            //    any backtick inside them, which is what this
                            //    does. A legitimate name containing a backtick
                            //    now also works, where before it simply failed.
                            //    See: https://dev.mysql.com/doc/refman/8.0/en/identifiers.html
                            $quotedName = '`' . str_replace('`', '``', $dbName) . '`';

                            $createResult = $testConn->query(
                                'CREATE DATABASE ' . $quotedName . ' '
                                . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
                            );
                        } catch (\mysqli_sql_exception $e) {
                            $code = $e->getCode();

                            if ($code === 1007) {
                                // It is there after all. Use it.
                                $testConn->select_db($dbName);
                                $createResult = true;
                            } elseif (in_array($code, [1044, 1045, 1142], true) === true) {
                                $createResult = false;
                            } else {
                                // Something genuinely different. Let the outer
                                // handler report it as itself.
                                //
                                //    Error 1006 ("can't create database") lives
                                //    here on purpose, though at a glance it
                                //    looks like it belongs above. It carries an
                                //    underlying operating-system error — a full
                                //    disk, a read-only filesystem, a permissions
                                //    problem on the server's own data directory.
                                //    Calling any of those "your account is not
                                //    allowed" would send the reader to their
                                //    hosting control panel to fix something that
                                //    is not broken there.
                                throw $e;
                            }
                        }
                        if ($createResult === false) {
                            $error = 'The database "' . $dbName . '" could not be used. '
                                   . 'Either it does not exist, or this database user is not '
                                   . 'allowed to open it — the database server does not say '
                                   . 'which, on purpose. This portal then tried to create it '
                                   . 'and was not allowed to do that either. '
                                   . 'On shared hosting that is completely normal: most '
                                   . 'accounts cannot create databases. Please create the '
                                   . 'database in your hosting control panel (for example the '
                                   . 'DreamHost panel, or cPanel), give this user permission '
                                   . 'to use it, then come back and try again. Also check the '
                                   . 'database name above for a typo.';
                            $step = 2;
                        } else {
                            $testConn->select_db($dbName);
                        }
                    }
                }

                if ($error === '') {
                    // Save credentials to session for later steps
                    $_SESSION['install_db'] = [
                        'host' => $dbHost,
                        'user' => $dbUser,
                        'pass' => $dbPass,
                        'name' => $dbName,
                        'port' => $dbPort,
                    ];

                    // 🧽 A DATABASE HAS BEEN LET THROUGH: ANY EARLIER CHOICE
                    //    IS FORGOTTEN, here and nowhere later.
                    //
                    //    A choice ("carry on" or "wipe") is an answer about
                    //    what one database held when the installer looked at
                    //    it. Typing the details again is asking the question
                    //    again, even for the same database: it may hold
                    //    something else by now. WHAT WAS WRONG: a database
                    //    that was not empty was sent on to the choice page
                    //    with the earlier choice still in the session, so a
                    //    wipe confirmed for one database was carried out on
                    //    the next one typed (see db_state.php).
                    installChoiceForget();

                    // 🔍 Probe DB state so we can route the user appropriately.
                    //    See _install/db_state.php for the classification rules.
                    $policy = (array) (require INSTALL_ROOT
                        . DIRECTORY_SEPARATOR . '_install'
                        . DIRECTORY_SEPARATOR . 'upgrade-policy.php');
                    $state = detectDbState(
                        $testConn,
                        INSTALL_VERSION,
                        $policy,
                        is_file(INSTALL_LOCK_FILE)
                    );
                    // 👥 How many accounts it holds, for the choice page to
                    //    show: somebody about to wipe what they take for a
                    //    half-built database should see that people are in it.
                    $state['accounts'] = installAccountCount($testConn);
                    $_SESSION['install_db_state'] = $state;

                    // 🔐 THERE IS ALREADY A PORTAL HERE. WHO IS ASKING?
                    //
                    //    Everything from this point on either upgrades an
                    //    existing installation or destroys it. Neither is
                    //    something a passer-by should be able to set off.
                    //
                    //    On a FRESH install there is nobody to check - no users
                    //    exist yet - and that is the only case where this
                    //    question cannot be asked. So it is asked in exactly the
                    //    cases where it CAN be: when tables are already there.
                    //
                    //    The check is for an UMBRELLA administrator, not merely
                    //    "an administrator". A site administrator looks after one
                    //    organisation; wiping or reshaping the database affects
                    //    every organisation on the installation, and everybody's
                    //    data. On an ordinary single-organisation portal the
                    //    owner is the umbrella administrator anyway.
                    //
                    //    Done here with a plain query rather than the portal's
                    //    usual helpers, because this wizard deliberately runs
                    //    without the portal's start-up code - that is what lets
                    //    it work on a broken installation.
                    //
                    //    🔑 ONE CASE IS LET THROUGH WITHOUT A SIGN-IN (#499):
                    //    a database that holds tables but has NEVER FINISHED
                    //    an installation, which is one with no installed
                    //    version recorded (the finish is what records it).
                    //
                    //    Why: that is what this wizard itself leaves behind
                    //    when somebody's session ends between building the
                    //    tables and the finish. Shared hosting ends a session
                    //    after about twenty minutes of quiet, which is less
                    //    time than it takes to think of a good password.
                    //    Until October 2026 the question below was asked
                    //    there too, and nobody could ever answer it: there
                    //    is no installed portal to sign in to, and often no
                    //    account. The only way forward was to empty the
                    //    database by hand in another tool.
                    //
                    //    What stands in for the sign-in is the ownership
                    //    code, which every visitor who gets this far has
                    //    typed: proof that they can open this hosting
                    //    account's files. With that, and the database login
                    //    they have just typed, they could already do
                    //    anything to this database with any other tool.
                    //
                    //    WHAT THIS CANNOT TELL APART: an installation that
                    //    never finished, and a finished portal so old that
                    //    it never recorded its version (or one whose record
                    //    was emptied). Both are let through on the code. So
                    //    the choice page never says "nothing was finished
                    //    here": it says no finished installation is
                    //    RECORDED, and how many accounts the database holds.
                    //
                    //    🧳 BUT ONLY WHEN EVERYTHING THERE IS THE PORTAL'S.
                    //    The next page offers to wipe, and the wipe removes
                    //    every table whose name begins with "tbl". That is
                    //    right for the portal's own tables and a disaster
                    //    for another program's that happen to be named the
                    //    same way and share the database. While this page
                    //    could not be reached that could not happen; now
                    //    that it can, a database holding any such table the
                    //    install script does not make is refused outright:
                    //    not built into, not wiped, nothing kept.
                    $whoIsAsking = '';
                    if ($state['state'] !== DB_STATE_EMPTY && $state['installed_version'] !== null) {
                        $whoIsAsking = installElevatedAdminCheck($testConn);
                    } elseif ($state['state'] !== DB_STATE_EMPTY) {
                        $notOurs = installTablesNotOurs($testConn);
                        if ($notOurs === null) {
                            $whoIsAsking = 'This database is not empty, and the installer could not work out whether what is in '
                                         . 'it belongs to this portal. To be safe it will not build into it or wipe it. Give the '
                                         . 'portal a database of its own: create a new, empty one in your hosting panel and type '
                                         . 'its name here. Nothing has been changed.';
                        } elseif ($notOurs !== []) {
                            $whoIsAsking = 'This database is not empty, and not everything in it is this portal\'s: it holds '
                                         . count($notOurs) . (count($notOurs) === 1 ? ' table' : ' tables') . ' the portal does not '
                                         . 'make (' . implode(', ', array_slice($notOurs, 0, 3)) . (count($notOurs) > 3 ? ' and others' : '')
                                         . '). The installer will not build into such a database or wipe it, because it could not '
                                         . 'do either without touching what is not its own. Give the portal a database of its '
                                         . 'own: create a new, empty one in your hosting panel and type its name here. Nothing '
                                         . 'has been changed.';
                        }
                    }
                    if ($whoIsAsking !== '') {
                        // 🚫 THROW THE CREDENTIALS AWAY, not just the page.
                        //
                        //    Refusing to show the next page is not enough.
                        //    The database details had already been kept in
                        //    the session by the lines above, and the step
                        //    that actually builds or wipes the database
                        //    reads them from there. So somebody refused here
                        //    could simply send the next step directly and it
                        //    would run, using the details this step had
                        //    helpfully stored for them.
                        //
                        //    Removing them means the refused visitor has
                        //    nothing to carry forward: every later step
                        //    finds no connection details and stops.
                        unset($_SESSION['install_db'], $_SESSION['install_db_state']);
                        installChoiceForget();

                        // Deliberately NOT closed here. The line below closes
                        // it on every path, and closing a mysqli connection
                        // twice throws an Error - which is not a
                        // mysqli_sql_exception, so the catch below would miss
                        // it and the wizard would die with a blank page
                        // instead of showing the explanation.
                        $error = $whoIsAsking;
                        $step  = 2;
                    }

                    $testConn->close();

                    if ($error === '') {
                        if ($state['state'] === DB_STATE_EMPTY) {
                            // Fresh install — straight to schema install.
                            // "Empty when looked at" is kept as this
                            // database's choice, so that the build step has
                            // one to go on and never has to guess.
                            installChoiceKeep('fresh');
                            header('Location: ?step=3');
                            exit();
                        }
                        // Anything else holds tables already: the choice
                        // page. That includes a portal that is installed and
                        // already at this exact version. That case used to
                        // write the lock file back and bounce the visitor
                        // straight to the portal, which was unhelpful in the
                        // one situation where somebody has deliberately come
                        // to the installer: they want to start again from
                        // nothing. The choice page offers that behind the
                        // same confirmation as every other destructive path
                        // here, and decides nothing for them.
                        header('Location: ?step=2.5');
                        exit();
                    }

                    // 🚫 The gate above refused. The step is drawn again
                    //    with its explanation, and nothing is changed.
                    //
                    //    WHAT WAS WRONG: the redirect to the choice page just
                    //    above used to be outside any test, so a refusal ran
                    //    into it too. The visitor was sent to the choice
                    //    page, which (the login having been thrown away)
                    //    sent them back to this form with no word of
                    //    explanation: the careful sentences the gate returns
                    //    were never shown to anybody. The connection is
                    //    closed already, so it is marked as such for the
                    //    line after this block: closing it twice throws.
                    $testConn = null;
                }

                if ($testConn !== null) {
                    $testConn->close();
                }
            } catch (\mysqli_sql_exception $e) {
                // What to check, by what the database (or the attempt to
                // reach it) said. The server's own words are kept at the end:
                // they are what a hosting company's support will ask for.
                $code = $e->getCode();
                if ($code === 1045) {
                    $what = 'The database refused that user name and password. Check both against your hosting panel; '
                          . 'copying and pasting them avoids a mistyped character.';
                } elseif ($code === 1044 || $code === 1049) {
                    $what = 'The user name and password were accepted, but that user is not allowed to use a database of '
                          . 'that name. Check the database\'s name, and in your hosting panel check that this user has been '
                          . 'given access to it.';
                } elseif ($code >= 2000 && $code < 2100) {
                    $what = 'No database server answered at that address. Check the host name and the port against your '
                          . 'hosting panel. Many hosts use "localhost"; others give the database a host name of its own.';
                } else {
                    $what = 'The database could not be used.';
                }
                $error = $what . ' Nothing has been changed. What the server said: ' . $e->getMessage();
                $step = 2;
            }
        }
    }

    // Step 2.5: Existing-data choice (Continue vs Drop)
    if ($action === 'install_action') {
        $choice = installField('install_action_choice');
        $state = $_SESSION['install_db_state'] ?? null;
        $allowContinue = is_array($state)
            && ($state['state'] ?? '') !== DB_STATE_FRESH_REQUIRED;

        if (isset($_SESSION['install_db']) === false) {
            // No database is held, so there is nothing to choose about. This
            // used to record the choice anyway and send the visitor round to
            // the database step without a word.
            $error = 'The installer no longer holds the database details, so nothing was chosen and nothing has been '
                   . 'changed. That happens when it has been left open for a long time. Please type them again.';
            $isChoiceStep = false;
            $step = 2;
        } elseif (installFormIsForThisDatabase() === false) {
            // 🪪 The page this form was on described ANOTHER database: see
            //    installDatabaseMark(). No choice is recorded. The choice
            //    page is drawn again, for the database the installer holds
            //    now.
            $error = INSTALL_OTHER_DATABASE_MESSAGE;
            $isChoiceStep = true;
        } elseif ($choice !== 'continue' && $choice !== 'drop') {
            $error = 'Please choose one of the two: carry on with what is there, or wipe it and start again.';
            $isChoiceStep = true;
        } elseif ($choice === 'continue' && $allowContinue === false) {
            $error = 'Carrying on is not possible here: the portal in this database is too old to be brought up to '
                   . 'date in place. The only choice is to wipe it and start again. Back it up first if you need anything in it.';
            $isChoiceStep = true;
        } elseif ($choice === 'drop') {
            // 🛡️ Destructive — require the user to confirm by typing
            //    the configured hostname (or 'DROP' if no hostname is
            //    enforced by policy).
            $policy = (array) (require INSTALL_ROOT
                . DIRECTORY_SEPARATOR . '_install'
                . DIRECTORY_SEPARATOR . 'upgrade-policy.php');
            $needHost = (bool) ($policy['require_hostname_confirmation_for_drop'] ?? true);
            $expected = $needHost === true
                ? (is_string($_SERVER['HTTP_HOST'] ?? null) === true ? $_SERVER['HTTP_HOST'] : 'DROP')
                : 'DROP';
            $typed = installField('drop_confirm');
            if (trim($typed) !== trim($expected)) {
                // Not escaped here: the page escapes every message once, as
                // it prints it. Escaping it twice showed "&amp;" to the reader.
                $error = 'Nothing was wiped: the text typed to confirm was not the text asked for. To wipe, type exactly: '
                       . $expected;
                $isChoiceStep = true;
            } else {
                // Kept for THIS database and no other: installChoiceKeep().
                installChoiceKeep('drop');
                header('Location: ?step=3');
                exit();
            }
        } else {
            installChoiceKeep('continue');
            header('Location: ?step=3');
            exit();
        }
    }

    // Step 1.5: Save organisation type (industry preset pick).
    //    Stores the choice in session only — actual tblSettings seeding
    //    happens in Step 3 after full_schema.sql runs.
    if ($action === 'select_industry') {
        $picked = installField('industry');
        // Defensive: only accept keys that exist in the brand presets file.
        if (isset($INSTALL_BRAND_PRESETS[$picked]) === false) {
            $picked = '';
        }
        $_SESSION['install_industry'] = $picked;
        header('Location: ?step=2');
        exit();
    }

    // Step 3: Install schema
    if ($action === 'install_schema') {
        $db = installGetDb();

        // 🚦 MAY THIS DATABASE BE BUILT INTO, AND MAY IT BE WIPED FIRST?
        //
        //    Asked here, of the database this request is about to touch, and
        //    answered from the choice held FOR THAT DATABASE
        //    (installChoiceHeld() in db_state.php). Three rules:
        //
        //    1. The form must have been drawn for this database. A "Build
        //       the tables" button left on the screen from an earlier
        //       database is not acted on (see installDatabaseMark()).
        //    2. With no choice held, the database is LOOKED AT now. Empty:
        //       there is nothing to choose, so it is built into. Not empty,
        //       or it cannot be looked at: nothing is built and nothing is
        //       removed, and the choice page is drawn so that the choice is
        //       made for this database. WHAT WAS WRONG: this step went ahead
        //       on whatever the session said, so opening it by its address
        //       skipped the choice page altogether.
        //    3. It wipes ONLY when the choice held for this database is the
        //       wipe: `$wipeThisDatabase` below is the one place that is
        //       decided, and the uploaded files are emptied only inside that
        //       same wipe.
        //
        //    `$buildChoice` stays '' when the build is refused.
        $buildChoice  = '';
        $buildRefused = '';
        if ($db !== null) {
            if (installFormIsForThisDatabase() === false) {
                $buildRefused = INSTALL_OTHER_DATABASE_MESSAGE;
            } else {
                $buildChoice = installChoiceHeld();
                if ($buildChoice === '') {
                    $tablesNow = installPortalTableCount($db);
                    if ($tablesNow === 0) {
                        installChoiceKeep('fresh');
                        $buildChoice = 'fresh';
                    } elseif ($tablesNow > 0) {
                        $buildRefused = 'Nothing was built and nothing was removed. This database is not empty, and no choice '
                                      . 'has been made for it yet. Choose below what to do with it.';
                    } else {
                        $buildRefused = 'Nothing was built and nothing was removed. The installer could not look at what this '
                                      . 'database holds, and no choice has been made for it yet. Choose below what to do with '
                                      . 'it; if this message comes back, go Back and type the database details again.';
                    }
                }
            }
            if ($buildRefused !== '') {
                $db->close();
            }
        }

        if ($db === null) {
            $error = INSTALL_NO_CONNECTION_MESSAGE;
            $step = 2;
        } elseif ($buildRefused !== '') {
            // The choice page, for the database the installer holds now.
            $error = $buildRefused;
            $step = 2;
            $isChoiceStep = true;
        } else {
            // 🪦 Drop-and-rebuild path. If the user explicitly opted for
            //    a fresh install from step 2.5, wipe every portal table
            //    before running full_schema.sql. We disable FK checks
            //    for the duration so dependent tables can drop in any
            //    order. This intentionally destroys data — the choice
            //    page has already confirmed the user's intent, FOR THIS
            //    DATABASE: see the three rules above.
            $wipeThisDatabase = ($buildChoice === 'drop');
            if ($wipeThisDatabase === true) {
                try {
                    $db->query('SET FOREIGN_KEY_CHECKS = 0');
                    $rs = $db->query("SHOW TABLES LIKE 'tbl%'");
                    if ($rs !== false) {
                        while (($r = $rs->fetch_array(MYSQLI_NUM)) !== null) {
                            $t = (string) $r[0];
                            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $t) === 1) {
                                $db->query('DROP TABLE IF EXISTS `' . $t . '`');
                            }
                        }
                        $rs->free();
                    }
                    $db->query('SET FOREIGN_KEY_CHECKS = 1');

                    // 🗑️ Now the files. Emptying the database on its own
                    //    leaves every uploaded photograph, document, poster and
                    //    receipt sitting on the disk - with nothing left in the
                    //    database that refers to them.
                    //
                    //    That is worse than untidy. Those files hold real
                    //    personal information: photographs of people, scanned
                    //    receipts, documents about members. After a wipe there
                    //    is no record they exist, so nobody knows to remove
                    //    them, and no "delete my data" request can ever reach
                    //    them because nothing points at them any more.
                    //
                    //    So a wipe means a wipe. Anybody who has reached this
                    //    point has already typed their own site's address to
                    //    confirm they mean it.
                    //
                    //    Backups in _backups/ are deliberately NOT touched. If
                    //    somebody wipes the wrong installation, the snapshots
                    //    are the only way back, and destroying them at the same
                    //    moment would remove the last line of defence.
                    //
                    //    NOTHING ON THE DISK IS EMPTIED ANYWHERE ELSE. These
                    //    lines are inside the wipe of this database and run
                    //    only as part of it; the installer calls
                    //    installClearDirectory() nowhere but here.
                    $uploadsDir = INSTALL_ROOT . DIRECTORY_SEPARATOR . '_uploads';
                    if (is_dir($uploadsDir) === true) {
                        installClearDirectory($uploadsDir);
                    }
                    // 🧹 The wipe has been done ONCE, as asked. The choice is
                    //    changed to "built into an empty database" so that
                    //    pressing this step's button a second time (after
                    //    going Back from the administrator step, say) builds
                    //    over what is there and does not wipe it all again,
                    //    the new administrator included.
                    installChoiceKeep('fresh');
                } catch (\mysqli_sql_exception $e) {
                    $error = 'The old tables could not all be removed. What the database said: '
                           . $e->getMessage();
                    $step = 3;
                }
                // 🪞 Step 5 will write portal.installed_version =
                //    INSTALL_VERSION; no extra state to clear here.
            }

            $schemaFile = INSTALL_SQL . DIRECTORY_SEPARATOR . 'full_schema.sql';
            if (is_readable($schemaFile) === false) {
                $error = 'The tables were not built: the install script (the file full_schema.sql, in the folder _sql) is '
                       . 'not on the server or cannot be read. Upload the folder _sql again from the package, whole, then press the button again.';
                $step = 3;
            } else {
                $sql = file_get_contents($schemaFile);
                // 🛡️ Wrap multi_query + result-set walking in try/catch.
                // mysqli is in strict-exception mode (PHP 8.1+ default,
                // also re-asserted by installGetDb()), so ANY SQL error
                // in full_schema.sql — DDL conflict, version-specific
                // syntax, FK ordering issue, privilege denied, etc. —
                // would otherwise throw an uncaught mysqli_sql_exception
                // and fatal the request with a bare HTTP 500. Catching
                // here surfaces the real MySQL message to the user
                // instead. The $db->errno checks below remain in place
                // as belt-and-braces for the (now unreachable) silent-
                // failure mode on legacy PHP <8.1 hosts.
                try {
                    $db->multi_query($sql);

                    // Consume all result sets from multi_query
                    $queryError = '';
                    do {
                        $result = $db->store_result();
                        if ($result !== false) {
                            $result->free();
                        }
                        // 🪞 Keep the FIRST error — a later "Commands out
                        //    of sync" follow-up would otherwise overwrite
                        //    the useful root-cause message.
                        if ($db->errno !== 0 && $queryError === '') {
                            $queryError = $db->error;
                        }
                    } while ($db->more_results() === true && $db->next_result());

                    if ($queryError !== '') {
                        $error = 'The tables could not all be built. Nothing was removed. What the database said: ' . $queryError
                               . ' Press the button again; if the same message comes back, send it to whoever supports your portal.';
                        $step = 3;
                    } else {
                        // 🪞 Apply all numbered migrations after full_schema.sql.
                        //
                        // Why this matters: full_schema.sql uses CREATE TABLE
                        // IF NOT EXISTS, which is a no-op when a table already
                        // exists. So if an earlier install attempt created
                        // tblLocalAccounts (or any other table) under an
                        // older shape — say without the `isVerified` column —
                        // and then the schema was updated, the existing
                        // table will NOT pick up the new column when the
                        // user retries the installer. Subsequent steps
                        // referencing that column fatal with
                        // "Unknown column 'isVerified' in 'field list'".
                        //
                        // Every numbered migration uses idempotent
                        // constructs (information_schema-guarded DDL,
                        // INSERT ... ON DUPLICATE KEY UPDATE,
                        // DELETE FROM ... WHERE ...) — so on a true-fresh
                        // install they're harmless no-ops (the CREATE
                        // TABLE in full_schema.sql already contains every
                        // column the migrations would add), but on a
                        // stale DB they fill in the gaps and bring the
                        // schema up to date.
                        //
                        // We multi_query each migration file directly
                        // rather than going through Portal\Core\Migrator
                        // because (a) Migrator's `pending()` filter is
                        // tblMigrations-based, and full_schema.sql's seed
                        // block marks every migration as already-applied
                        // (so Migrator would see nothing pending on a
                        // stale DB), and (b) the installer is bootstrap-
                        // free — it can't autoload \Portal\Core\Migrator
                        // without pulling in the rest of the framework.
                        //
                        // See: https://github.com/MWBMPartners/WebMS-Intra/issues/218
                        $migrationFiles = glob(
                            INSTALL_SQL . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9]_*.sql'
                        );
                        if ($migrationFiles === false) {
                            $migrationFiles = [];
                        }
                        sort($migrationFiles, SORT_STRING);
                        $migError = '';
                        foreach ($migrationFiles as $migFile) {
                            $migSql = file_get_contents($migFile);
                            if ($migSql === false || trim($migSql) === '') {
                                continue;
                            }
                            try {
                                $db->multi_query($migSql);
                                do {
                                    $r = $db->store_result();
                                    if ($r !== false) {
                                        $r->free();
                                    }
                                    if ($db->errno !== 0 && $migError === '') {
                                        $migError = basename($migFile)
                                            . ' — ' . $db->error;
                                    }
                                } while ($db->more_results() === true && $db->next_result());
                            } catch (\mysqli_sql_exception $e) {
                                $migError = basename($migFile)
                                    . ' — ' . $e->getMessage();
                                break;
                            }
                            if ($migError !== '') {
                                break;
                            }
                        }

                        if ($migError !== '') {
                            $error = 'The tables were built, but one of the numbered database upgrades that follow could not be '
                                   . 'applied. Nothing was removed. The file, and what the database said: ' . $migError
                                   . ' Press the button again; if the same message comes back, send it to whoever supports your portal.';
                            $step = 3;
                        } else {
                            // 🏷️ Seed the product brand preset chosen in Step 1.5
                            //    (issue #296). Writes the resolved name / tagline
                            //    / publisher plus portal.industry into tblSettings.
                            //    For the default (generic) pick we still upsert so
                            //    the rows exist with sensible values for /admin/settings.
                            $brandError = '';
                            try {
                                $brandSql = 'INSERT INTO `tblSettings` '
                                          . '(`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) '
                                          . 'VALUES (NULL, ?, ?, ?, 0) '
                                          . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`), '
                                          . '`defaultValue` = VALUES(`defaultValue`)';
                                $brandStmt = $db->prepare($brandSql);
                                if ($brandStmt !== false) {
                                    foreach ([
                                        'portal.industry'   => $selectedIndustry,
                                        'product.name'      => $INSTALL_PRODUCT_NAME,
                                        'product.tagline'   => $INSTALL_PRODUCT_TAGLINE,
                                        'product.publisher' => $INSTALL_PRODUCT_PUBLISHER,
                                    ] as $key => $value) {
                                        $strValue = (string) $value;
                                        $brandStmt->bind_param('sss', $key, $strValue, $strValue);
                                        $brandStmt->execute();
                                    }
                                    $brandStmt->close();
                                }
                            } catch (\mysqli_sql_exception $e) {
                                // Non-fatal — the rows already have safe defaults
                                // from migration 108 / 073. Log a soft warning so
                                // the admin can re-pick via /admin/settings.
                                $brandError = $e->getMessage();
                                error_log('[WebMS-Intra] Installer brand seed: ' . $brandError);
                            }

                            $db->close();
                            header('Location: ?step=4');
                            exit();
                        }
                    }
                } catch (\mysqli_sql_exception $e) {
                    $error = 'The tables could not all be built. Nothing was removed. What the database said: ' . $e->getMessage()
                           . ' Press the button again; if the same message comes back, send it to whoever supports your portal.';
                    $step = 3;
                }
            }
            $db->close();
        }
    }

    // Step 4: Create admin user
    if ($action === 'create_admin') {
        $adminName  = trim(installField('admin_name'));
        $adminEmail = trim(installField('admin_email'));
        $adminPass  = installField('admin_pass');
        $adminPass2 = installField('admin_pass2');

        // 🛡️ Self-contained password policy — mirrors Auth::validatePassword() defaults
        // (the installer runs before bootstrap.php is available).
        $pwErrors = [];
        if (strlen($adminPass) < 12) {
            $pwErrors[] = 'Password must be at least 12 characters.';
        }
        if (strlen($adminPass) > 128) {
            $pwErrors[] = 'Password must be no more than 128 characters.';
        }
        if (preg_match('/[A-Z]/', $adminPass) !== 1) {
            $pwErrors[] = 'Password must contain at least one uppercase letter.';
        }
        if (preg_match('/[a-z]/', $adminPass) !== 1) {
            $pwErrors[] = 'Password must contain at least one lowercase letter.';
        }
        if (preg_match('/[0-9]/', $adminPass) !== 1) {
            $pwErrors[] = 'Password must contain at least one number.';
        }
        if (preg_match('/[^a-zA-Z0-9]/', $adminPass) !== 1) {
            $pwErrors[] = 'Password must contain at least one special character.';
        }

        if ($adminName === '' || $adminEmail === '' || $adminPass === '') {
            $error = 'Please fill in every box: your name, your e-mail address and the password twice.';
            $step = 4;
        } elseif (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            $error = 'That is not an e-mail address. Type it as name@example.org.';
            $step = 4;
        } elseif (strlen($adminEmail) > 50 || strlen($adminName) > 255) {
            // 📏 The e-mail address is also this account's sign-in name, and a
            //    sign-in name holds 50 characters (tblLocalAccounts.username).
            //    The database refuses anything longer, as it should. Until
            //    October 2026 that refusal came AFTER the account's first half
            //    had been saved, which left an administrator who could not
            //    sign in and whose address could not be used again.
            $error = strlen($adminEmail) > 50
                ? 'That e-mail address is longer than 50 characters, which is the most a sign-in name can hold. Use a '
                  . 'shorter address for this first account; other administrators can be added once the portal is running.'
                : 'That name is longer than 255 characters. Please shorten it.';
            $step = 4;
        } elseif (count($pwErrors) > 0) {
            $error = 'That password cannot be used. ' . implode(' ', $pwErrors);
            $step = 4;
        } elseif ($adminPass !== $adminPass2) {
            $error = 'The two passwords are not the same. Type the same password in both boxes.';
            $step = 4;
        } else {
            $db = installGetDb();
            if ($db === null) {
                $error = INSTALL_NO_CONNECTION_MESSAGE;
                $step = 2;
            } else {
                // 🛡️ Wrap the whole admin-create flow in try/catch — see
                // step 3 above for the rationale. A duplicate-email INSERT,
                // an FK violation, or any other constraint failure would
                // otherwise throw an uncaught mysqli_sql_exception and
                // fatal the request. The `$stmt === false` and
                // `if ($stmtLocal !== false)` guards below are kept as
                // belt-and-braces — under strict mode they're effectively
                // dead code, but they keep the handler safe if a future
                // change toggles mysqli_report back to silent.
                //
                // 🧾 ALL OR NOTHING. The account is four writes (the person,
                // their password, their place in the organisation, the
                // site's contact address). They are made as one piece, so a
                // failure in the second cannot leave the first behind: a
                // person with no password is an administrator nobody can
                // sign in as, holding an e-mail address that cannot be used
                // for a second attempt.
                try {
                    $db->begin_transaction();
                    // Insert admin user. Note: tblUsers has NO siteID column —
                    // multi-site assignment is via tblUserSites (inserted
                    // below). An earlier version of this handler included
                    // `siteID = 1` directly on tblUsers, which fatalled the
                    // installer step 4 with "Unknown column 'siteID'" on every
                    // fresh install. See issue #200.
                    $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                    $stmt = $db->prepare(
                        'INSERT INTO tblUsers (fullName, emailAddress, isAdmin, isRootAdmin, isActive, createdAt) '
                        . 'VALUES (?, ?, 1, 1, 1, NOW())'
                    );
                    if ($stmt === false) {
                        $db->rollback();
                        $error = 'The administrator account could not be created, and nothing was saved. What the database '
                               . 'said: ' . $db->error;
                        $step = 4;
                    } else {
                        $stmt->bind_param('ss', $adminName, $adminEmail);
                        $stmt->execute();
                        $newUserId = $stmt->insert_id;
                        $stmt->close();

                        // Create local auth account
                        $stmtLocal = $db->prepare(
                            'INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified, createdAt) '
                            . 'VALUES (?, ?, ?, 1, NOW())'
                        );
                        if ($stmtLocal !== false) {
                            $stmtLocal->bind_param('iss', $newUserId, $adminEmail, $hash);
                            $stmtLocal->execute();
                            $stmtLocal->close();
                        }

                        // Assign to default site
                        $stmtSite = $db->prepare(
                            'INSERT INTO tblUserSites (userID, siteID, isSiteAdmin, isSiteRootAdmin, isActive) '
                            . 'VALUES (?, 1, 1, 1, 1)'
                        );
                        if ($stmtSite !== false) {
                            $stmtSite->bind_param('i', $newUserId);
                            $stmtSite->execute();
                            $stmtSite->close();
                        }

                        // Update default site settings with the admin's email
                        $stmtSetting = $db->prepare(
                            'UPDATE tblSettings SET settingValue = ? WHERE settingKey = \'site.adminEmail\' AND siteID IS NULL'
                        );
                        if ($stmtSetting !== false) {
                            $stmtSetting->bind_param('s', $adminEmail);
                            $stmtSetting->execute();
                            $stmtSetting->close();
                        }

                        $db->commit();
                        $db->close();
                        header('Location: ?step=4.5');
                        exit();
                    }
                } catch (\mysqli_sql_exception $e) {
                    // Undo whatever part of the account was written.
                    try {
                        $db->rollback();
                    } catch (\mysqli_sql_exception) {
                        // The connection has gone; the database undoes an
                        // unfinished piece of work by itself in that case.
                    }
                    if ($e->getCode() === 1062) {
                        // 1062: "duplicate entry". An account with this
                        // address is already there.
                        $error = 'An account with that e-mail address is already in this database, most likely made by an '
                               . 'earlier run of this installer. Nothing was changed. If you know that account\'s password, '
                               . 'use "Keep the administrator that is already there" below. If you do not, type a different '
                               . 'e-mail address here, or go back to the database step and choose to start again from nothing.';
                    } else {
                        error_log('[WebMS-Intra] Installer: could not create the administrator: ' . $e->getMessage());
                        $error = 'The administrator account could not be created, and nothing was saved. What the database '
                               . 'said: ' . $e->getMessage();
                    }
                    $step = 4;
                }
                $db->close();
            }
        }
    }

    // Step 4.5: Web addresses (#500)
    //
    //    Nothing is written to the database here. The two addresses are kept
    //    in the session and stored by the finish. Going Back and correcting
    //    a mistyped address works before the finish is pressed, and also
    //    after a finish that stopped part way: the finish fills a setting
    //    that holds nothing yet, and may replace a value THIS RUN filled in
    //    itself, and only that (see installFillSetting() and step 5 of the
    //    finish). An address the database held before this run is never
    //    replaced.
    if ($action === 'web_addresses') {
        // The same two checks as the "Web addresses" card on the
        // organisation settings page (_apps/admin/settings/organisation/save.php),
        // so that nothing can be stored here that the portal would later
        // refuse or ignore. ONE DIFFERENCE, on purpose: here the portal's
        // address may not be left empty. With none stored, every link in an
        // e-mail is built from whatever address the request arrived at, and
        // a visitor chooses that; the installation is the one moment when
        // somebody is certain to be looking at the question.
        $checked = \Portal\Core\PortalAddress::check(installField('portal_address'));
        $support = trim(installField('support_email'));
        if ($checked['problem'] !== '') {
            $error = 'Nothing was saved. ' . $checked['problem'];
        } elseif ($checked['address'] === '') {
            $error = 'Nothing was saved. Type the address people will use to reach this portal, for example '
                   . 'https://portal.example.org. It is needed so that links in e-mails point at this portal and '
                   . 'nowhere else. You can change it later in Admin, Settings, Organisation.';
        } elseif ($support !== '' && (strlen($support) > 254 || filter_var($support, FILTER_VALIDATE_EMAIL) === false)) {
            $error = 'Nothing was saved. The support e-mail address is not an e-mail address: type it as '
                   . 'name@example.org, or leave the box empty to show none.';
        } else {
            $_SESSION['install_addresses'] = ['address' => $checked['address'], 'support' => $support];
            header('Location: ?step=5');
            exit();
        }
        $step = 4;
        $isAddressStep = true;
    }

    // Step 5: The finish
    //
    //    Everything that turns a database with tables in it into a working,
    //    private installation, in an order chosen so that a failure part way
    //    leaves something the same form can simply be sent again for:
    //
    //      1. the ownership code, typed again
    //      2. the database still answers, and an administrator exists
    //      3. the three private folders, each with its "refuse everything" file
    //      4. the key file (an existing one is KEPT)
    //      5. the two addresses, into the settings
    //      6. the database login file   <- from here the portal can start
    //      7. the installed version, the lock file, and the code file removed
    //
    //    The login file comes after everything that can fail and be retried,
    //    because the moment it exists this wizard can no longer be reached.
    //    The installed version comes after it, because a database that has a
    //    version recorded is treated as a finished portal (see step 2).
    if ($action === 'finalize') {
        $step      = 5;
        $dbCreds   = is_array($_SESSION['install_db'] ?? null) === true ? $_SESSION['install_db'] : null;
        $addresses = is_array($_SESSION['install_addresses'] ?? null) === true ? $_SESSION['install_addresses'] : null;
        $writeDb   = null;

        // 1. 🔑 The code AGAIN, from the form and not from the session. A
        //    session is a cookie; this is the request that writes the
        //    database login and makes the installation permanent, so it asks
        //    for the one thing only the owner can read.
        $judged = installCodeJudge(INSTALL_AUTH_DIR, installField('owner_code'));
        if ($judged !== INSTALL_CODE_RIGHT) {
            if ($judged === INSTALL_CODE_REPLACED) {
                $error = INSTALL_NEW_CODE_MESSAGE;
            } elseif ($judged === INSTALL_CODE_BLANK) {
                $error = 'Type the installation code into the box to finish. It is the same code you typed at the start, '
                       . 'from the same file. Nothing has been changed.';
            } elseif ($judged === INSTALL_CODE_NO_FILE) {
                $error = 'The file that holds the installation code could not be read just now, so nothing was done. '
                       . 'Reload this page: the installer writes a new code file when there is none, and asks for it.';
            } else {
                $error = INSTALL_WRONG_CODE_MESSAGE;
            }
            // A code that was replaced, or a file that went, ends this
            // session's pass as well: the code page is what comes next.
            $ownerPassed      = installOwnerPassed();
            $ownerCodeChanged = ($ownerPassed === false);
        } elseif ($dbCreds === null) {
            $error = 'The database details have been lost, most likely because the installer was left open for a long '
                   . 'time. Please type them again: the installer keeps nothing of them. What was already built in the '
                   . 'database is still there, and nothing has been changed.';
            $step  = 2;
        } elseif ($addresses === null) {
            $error = 'The portal\'s address has not been given yet. Please fill in this page first.';
            $step  = 4;
            $isAddressStep = true;
        }

        // 2. The database, and somebody to sign in as.
        if ($error === '') {
            $writeDb = installGetDb();
            if ($writeDb === null) {
                $error = INSTALL_NO_CONNECTION_MESSAGE;
                $step  = 2;
            }
        }
        if ($error === '') {
            // A finished installation nobody can sign in to is the worst
            // result this wizard could give: it locks itself, and the only
            // way back in is by hand. So the finish checks first.
            $administrators = installAdministratorCount($writeDb);
            if ($administrators < 0) {
                $error = 'The portal\'s tables could not be read, so the installation cannot be finished yet. Go back to '
                       . 'the step that builds the tables and press its button again.';
                $step  = 3;
            } elseif ($administrators === 0) {
                $error = 'There is no administrator account yet, so nobody could sign in to the finished portal. '
                       . 'Please create one first.';
                $step  = 4;
            }
        }

        // 3. 🔒 The private folders. Before the key and the login are
        //    written, so that neither is ever in a folder without the file.
        $folders = ['made' => [], 'written' => [], 'problems' => []];
        if ($error === '') {
            $folders = installProtectPrivateFolders(INSTALL_ROOT);
            if ($folders['problems'] !== []) {
                $error = 'The installation was not finished, because ' . implode(', and ', $folders['problems'])
                       . '. In your hosting panel\'s file manager, open the folder that holds _core. Make sure the folders '
                       . implode(', ', INSTALL_PRIVATE_FOLDERS) . ' exist there and that the website may write to them '
                       . '(your host\'s help pages say which permissions that needs; 755 is the usual answer). Then '
                       . 'press Finish again.';
            }
        }

        // 4. 🗝️ The key file. Every password and key later saved in Settings
        //    is encrypted with it.
        //
        //    AN EXISTING KEY IS KEPT. This used to write a new key every
        //    time, without looking. On a database that already holds
        //    encrypted settings (the "continue with what is there" choice),
        //    that silently made every one of them unreadable for good.
        //    A key that is "there" is exactly what the portal counts as a
        //    key: a real file, readable, and not empty
        //    (SecretSettings::keyFileContents()).
        $keyWasThere = false;
        if ($error === '') {
            clearstatcache(true, INSTALL_ENC_KEY_FILE);
            $keyWasThere = is_file(INSTALL_ENC_KEY_FILE) === true && is_readable(INSTALL_ENC_KEY_FILE) === true
                && (string) @file_get_contents(INSTALL_ENC_KEY_FILE) !== '';
            if ($keyWasThere === false) {
                // 32 random bytes, written as 64 characters. Read back and
                // compared before it counts: a full disk writes an empty
                // file without complaint, and an empty key file is no key.
                if (installWriteFile(INSTALL_ENC_KEY_FILE, bin2hex(random_bytes(32))) === false) {
                    $error = 'The installation was not finished, because the key file (enc.key) could not be written into the '
                           . '_auth_keys folder. Check that the disk is not full and that the website may write to that '
                           . 'folder; if something else named enc.key is in it that is not a file, remove it. Then press '
                           . 'Finish again.';
                }
            }
        }

        // 5. 🌐 The two addresses.
        //
        //    🧾 WHAT THIS RUN FILLED IN ITSELF IS REMEMBERED, in the session,
        //    with the database it was filled into. WHAT WAS WRONG: this step
        //    stores the addresses BEFORE the database login is written, so
        //    that a failure there can be retried. But after such a failure
        //    the address typed first was already in the database. Somebody
        //    who then went Back and corrected it had the correction thrown
        //    away, and the last page told them the database "already had an
        //    address saved": true only because the installer itself had
        //    saved it, a minute earlier. Now a value this run wrote may be
        //    replaced by this run. A value that was there before, or that
        //    anybody has changed since, is still kept, and the memory is
        //    worth nothing for another database or another session.
        if ($error === '') {
            $filledBefore = is_array($_SESSION['install_filled'] ?? null) === true
                && is_array($_SESSION['install_filled']['values'] ?? null) === true
                && is_string($_SESSION['install_filled']['database'] ?? null) === true
                && hash_equals(installDbIdentity($dbCreds), $_SESSION['install_filled']['database']) === true
                ? $_SESSION['install_filled']['values'] : [];
            try {
                $finishReport['addressTyped'] = (string) ($addresses['address'] ?? '');
                $finishReport['supportTyped'] = (string) ($addresses['support'] ?? '');
                foreach ([
                    'address' => [\Portal\Core\PortalAddress::SETTING, $finishReport['addressTyped']],
                    'support' => ['portal.support.email', $finishReport['supportTyped']],
                ] as $which => [$settingKey, $typedValue]) {
                    $ownEarlier = is_string($filledBefore[$settingKey] ?? null) === true ? $filledBefore[$settingKey] : null;
                    $stored     = installFillSetting($writeDb, $settingKey, $typedValue, $ownEarlier);
                    $finishReport[$which] = $stored['value'];
                    if ($stored['wrote'] === true) {
                        $filledBefore[$settingKey] = $stored['value'];
                    }
                }
                // Kept at once, so that it is there if a later step of this
                // finish fails. A finish that completes throws it away.
                $_SESSION['install_filled'] = ['database' => installDbIdentity($dbCreds), 'values' => $filledBefore];
            } catch (\mysqli_sql_exception $e) {
                error_log('[WebMS-Intra] Installer: could not store the web addresses: ' . $e->getMessage());
                $error = 'The installation was not finished, because the portal\'s address could not be saved in the '
                       . 'database. Nothing else was written. Press Finish again; if this comes back, go back to the step '
                       . 'that builds the tables and press its button again first.';
            }
        }

        // 6. The database login. From this line on the portal can start.
        if ($error === '') {
            $credsContent = "<?php\n"
                          . "// Database credentials — auto-generated by installer\n"
                          . "// DO NOT commit this file to version control.\n"
                          . "return [\n"
                          . "    'db_host' => " . var_export($dbCreds['host'], true) . ",\n"
                          . "    'db_user' => " . var_export($dbCreds['user'], true) . ",\n"
                          . "    'db_pass' => " . var_export($dbCreds['pass'], true) . ",\n"
                          . "    'db_name' => " . var_export($dbCreds['name'], true) . ",\n"
                          . "    'db_port' => " . var_export($dbCreds['port'], true) . ",\n"
                          . "];\n";
            if (installWriteFile(INSTALL_CREDS_FILE, $credsContent) === false) {
                // Whatever part of it did reach the disk is removed: a
                // half-written login file would lock this wizard out and
                // start a portal that cannot connect.
                @unlink(INSTALL_CREDS_FILE);
                $error = 'The installation was not finished, because the file that holds the database login could not be '
                       . 'written into the _auth_keys folder. Check that the disk is not full and that the website may '
                       . 'write to that folder, then press Finish again.';
            }
        }

        // 7. The version, the lock, and the code's work is done.
        if ($error === '') {
            // 🆔 Record the version this database is now at, and clear any
            //    maintenance flag a drop-and-rebuild might have left on.
            //    Not fatal: the portal runs without either (the maintenance
            //    gate falls back to "an older install: assume current").
            $finishReport['versionRecorded'] = false;
            try {
                $stmt = $writeDb->prepare(
                    "INSERT INTO `tblSettings` "
                    . "(`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) "
                    . "VALUES (NULL, 'portal.installed_version', ?, '', 0) "
                    . "ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)"
                );
                if ($stmt !== false) {
                    $v = INSTALL_VERSION;
                    $stmt->bind_param('s', $v);
                    $stmt->execute();
                    $stmt->close();
                    $finishReport['versionRecorded'] = true;
                }
                $writeDb->query(
                    "UPDATE `tblSettings` "
                    . "SET `settingValue` = '0' "
                    . "WHERE `settingKey` = 'portal.maintenance.active'"
                );
            } catch (\mysqli_sql_exception $e) {
                error_log('[WebMS-Intra] Installer: could not record the installed version: ' . $e->getMessage());
            }

            // Create lock file to prevent re-installation
            @file_put_contents(INSTALL_LOCK_FILE, date('c') . "\n" . 'Installed by ' . $INSTALL_PRODUCT_NAME . ' installer v' . INSTALL_VERSION);
            @chmod(INSTALL_LOCK_FILE, 0640);

            // 🔑 The code has done its work. Removed so that it is not left
            //    lying in the folder that is about to hold real secrets. A
            //    code file the owner made read-only (the code page suggests
            //    it) is removed all the same; where the hosting will not let
            //    it go, the last page says what is left and what to do.
            $finishReport['codeRemoved'] = installCodeRemove(INSTALL_AUTH_DIR);

            // 🧭 The installation's own `.channel` file, from the package's
            //    word, only when there is none. Last, so that a finish which
            //    stopped earlier has not yet turned a fresh upload into a
            //    gated test copy. Then the channel is decided again, so that
            //    the last page says what the finished portal will say.
            $finishReport['channelFile'] = installWriteChannelFile(INSTALL_ROOT);
            $installChannel      = \Portal\Core\Channel::decide(INSTALL_ROOT, INSTALL_WEB_FOLDER);
            $installChannelWords = installChannelWords(
                $installChannel['channel'],
                $installChannel['source'],
                basename(INSTALL_WEB_FOLDER),
                installChannelFileIgnored(INSTALL_ROOT, $installChannel['source'])
            );

            $finishReport['keyWasThere'] = $keyWasThere;
            $finishReport['folders']     = $folders;
            $finishReport['database']    = (string) $dbCreds['name'];

            // Clear everything the wizard kept in the session. The session
            // itself is left: it may be a signed-in administrator's.
            unset(
                $_SESSION['install_db'],
                $_SESSION[INSTALL_CHOICE_KEY],
                $_SESSION['install_filled'],
                $_SESSION['install_db_state'],
                $_SESSION['install_db_server'],
                $_SESSION['install_addresses'],
                $_SESSION['install_industry'],
                $_SESSION['install_owner'],
                $_SESSION['install_form_token']
            );

            $justFinished = true;
        }

        if ($writeDb !== null) {
            $writeDb->close();
        }
    }
}

/**
 * 💾 Write one of the installation's own files, and make sure it arrived.
 *
 * file_put_contents() reports how many bytes it wrote, and on a full disk
 * that can be fewer than it was given, or none, with no error raised. So the
 * count is checked AND the file is read back and compared. The file is then
 * made readable by its owner and the owner's group only; a host that refuses
 * that change loses nothing.
 *
 * @return bool True only when the file now holds exactly `$contents`.
 */
function installWriteFile(string $file, string $contents): bool
{
    if (@file_put_contents($file, $contents, LOCK_EX) !== strlen($contents)) {
        return false;
    }
    clearstatcache(true, $file);
    if (is_file($file) === false || @file_get_contents($file) !== $contents) {
        return false;
    }
    @chmod($file, 0640);

    return true;
}

/**
 * 👤 How many accounts could sign in to the finished portal as its
 * administrator: active, and a global administrator.
 *
 * @return int The count; -1 when the table could not be read at all.
 */
function installAdministratorCount(\mysqli $db): int
{
    try {
        $result = $db->query('SELECT COUNT(*) FROM tblUsers WHERE isRootAdmin = 1 AND isActive = 1');
        if ($result === false) {
            return -1;
        }
        $count = (int) ($result->fetch_row()[0] ?? 0);
        $result->free();

        return $count;
    } catch (\mysqli_sql_exception) {
        return -1;
    }
}

/**
 * 🔢 How many tables whose names begin with "tbl" the database holds now:
 * the same question step 2 asks before it calls a database empty.
 *
 * @return int The count; -1 when it could not be counted, which the build
 *             step treats as "not empty" (it then builds nothing).
 */
function installPortalTableCount(\mysqli $db): int
{
    try {
        $result = $db->query("SHOW TABLES LIKE 'tbl%'");
        if ($result === false) {
            return -1;
        }
        $count = (int) $result->num_rows;
        $result->free();

        return $count;
    } catch (\mysqli_sql_exception) {
        return -1;
    }
}

/**
 * 👥 How many accounts the database holds: every row of the table of people,
 * switched on or off, administrator or not.
 *
 * @return int The count; -1 when there is no such table or it could not be
 *             read (a database that was built only part of the way).
 */
function installAccountCount(\mysqli $db): int
{
    try {
        $result = $db->query('SELECT COUNT(*) FROM tblUsers');
        if ($result === false) {
            return -1;
        }
        $count = (int) ($result->fetch_row()[0] ?? 0);
        $result->free();

        return $count;
    } catch (\mysqli_sql_exception) {
        return -1;
    }
}

/**
 * 🧩 Give ONE installation-wide setting its value, ONLY IF IT HOLDS NOTHING
 * YET or holds what this run of the installer put there itself, and say what
 * it holds afterwards.
 *
 * NEVER A PLAIN INSERT. The install script has already seeded a row for each
 * of these settings, empty. An installation-wide row has nothing in its
 * organisation column, and the table's first unique key (setting name and
 * organisation) does not see two such rows as duplicates: MySQL never treats
 * one "nothing" as equal to another. So on a database without the second
 * unique key (uq_setting_key_scope, migration 187) a plain insert ADDS a
 * second row, and the portal reads whichever it happens to find; on a
 * database with it, the insert is refused with an error. Wrong both ways.
 * This looks for the row first and changes it by its own number. It inserts
 * only when there is no row at all.
 *
 * ONLY IF EMPTY, because the wizard may be finishing over a database that
 * already holds a portal ("continue with what is there"). An address somebody
 * already saved there is the one every sent e-mail's links rely on, and it is
 * kept. The last page says so when what is stored is not what was typed.
 *
 * THE ONE EXCEPTION is `$ownEarlier`: the value this same run wrote into this
 * setting during an earlier press of Finish that stopped part way. When the
 * setting still holds exactly that, it is this run's own value and not
 * somebody's saved one, so the corrected value replaces it. If anything else
 * is there (somebody changed it in between), it is kept like any other.
 *
 * @param string|null $ownEarlier What this run itself wrote here earlier, or
 *                                null when it wrote nothing.
 *
 * @return array{value: string, wrote: bool} What the setting holds now, and
 *         whether this call is what put it there.
 *
 * @throws \mysqli_sql_exception When the table cannot be read or written.
 */
function installFillSetting(\mysqli $db, string $key, string $value, ?string $ownEarlier = null): array
{
    $find = $db->prepare('SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL ORDER BY settingID ASC LIMIT 1');
    $find->bind_param('s', $key);
    $find->execute();
    $row = $find->get_result()->fetch_assoc();
    $find->close();

    if ($row === null) {
        $add = $db->prepare('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, ?, \'\', 0)');
        $add->bind_param('ss', $key, $value);
        $add->execute();
        $add->close();

        return ['value' => $value, 'wrote' => true];
    }

    $held = (string) ($row['settingValue'] ?? '');
    // An empty setting may be filled. A setting that holds this run's own
    // earlier value may be changed, to nothing as well: a support address
    // typed by mistake can be taken out again.
    $isOurs = ($ownEarlier !== null && $ownEarlier !== '' && hash_equals($ownEarlier, $held) === true);
    if (($held !== '' && $isOurs === false) || $held === $value) {
        // Kept. It still counts as this run's own when it is the value this
        // run wrote and nothing has changed it.
        return ['value' => $held, 'wrote' => false];
    }
    $id   = (int) $row['settingID'];
    $fill = $db->prepare('UPDATE tblSettings SET settingValue = ?, updatedAt = NOW() WHERE settingID = ?');
    $fill->bind_param('si', $value, $id);
    $fill->execute();
    $fill->close();

    return ['value' => $value, 'wrote' => true];
}

/**
 * 🗑️ Empty a folder, leaving the folder itself in place.
 *
 * Used when somebody has asked to wipe an installation and start again. It
 * removes what is inside `_uploads/` so that no orphaned personal information
 * is left on the disk.
 *
 * Two deliberate safety measures, because this deletes real files:
 *
 *   - It refuses to follow a symbolic link OUT of the folder it was given. A
 *     link pointing somewhere else entirely is unlinked, never followed. Without
 *     that, one stray link could turn "empty the uploads folder" into something
 *     far worse.
 *   - It never removes the folder it was given, only the contents. The portal
 *     expects that folder to exist.
 *
 * @param string $dir The folder to empty. Must already exist.
 *
 * @return void
 */
function installClearDirectory(string $dir): void
{
    $entries = @scandir($dir);
    if (is_array($entries) === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;

        // A link is removed as a link. It is never followed, so a link
        // pointing outside this folder cannot lead the deletion astray.
        if (is_link($path) === true) {
            @unlink($path);
            continue;
        }
        if (is_dir($path) === true) {
            installClearDirectory($path);
            @rmdir($path);
            continue;
        }
        @unlink($path);
    }
}

/**
 * 🔐 Is the person at the keyboard allowed to change an existing portal?
 *
 * Called only when a portal is ALREADY installed. From that point the wizard can
 * only upgrade the database or destroy it, and neither should be possible for
 * somebody who simply found the address.
 *
 * Deliberately written with a plain database query rather than the portal's own
 * sign-in helpers. This wizard runs WITHOUT the portal's start-up code, on
 * purpose - that is exactly what lets it work when the portal itself is too
 * broken to start. Reaching for those helpers here would mean the wizard could
 * only run when the thing it exists to repair is already working.
 *
 * It reads the session the portal itself writes, so somebody who signed in
 * normally is recognised.
 *
 * @param \mysqli $conn An open connection to the portal's database.
 *
 * @return string An empty string when the person may continue. Otherwise a
 *                sentence explaining, in plain words, why not.
 */
function installElevatedAdminCheck(\mysqli $conn): string
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($userId <= 0) {
        return 'There is already a portal installed on this database, so this '
             . 'wizard can only be used to upgrade it or to wipe it and start '
             . 'again. Both are serious, so you have to be signed in first. '
             . 'Please sign in to the portal as an umbrella administrator, then '
             . 'come back to this page. Nothing has been changed.';
    }

    try {
        $stmt = $conn->prepare(
            'SELECT isRootAdmin, isActive FROM tblUsers WHERE userID = ? LIMIT 1'
        );
        if ($stmt === false) {
            return 'This portal\'s list of users could not be read, so there is no '
                 . 'way to confirm you are allowed to do this. Nothing has been '
                 . 'changed.';
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\mysqli_sql_exception $e) {
        return 'This portal\'s list of users could not be read, so there is no way '
             . 'to confirm you are allowed to do this. Nothing has been changed.';
    }

    if ($row === null) {
        return 'The account you are signed in with no longer exists on this '
             . 'portal. Nothing has been changed.';
    }
    if ((string) ($row['isActive'] ?? '0') !== '1') {
        return 'The account you are signed in with has been switched off, so it '
             . 'cannot make changes of this kind. Nothing has been changed.';
    }
    if ((string) ($row['isRootAdmin'] ?? '0') !== '1') {
        return 'You are signed in, but this needs an UMBRELLA administrator - the '
             . 'person who looks after the whole installation, rather than one '
             . 'organisation within it. Upgrading or wiping the database affects '
             . 'everybody using this portal, which is why it is kept to that one '
             . 'role. Nothing has been changed.';
    }

    return '';
}

/**
 * 🧳 The tables in a database that are NOT this portal's, among those the
 * wipe would remove (every table whose name begins with "tbl").
 *
 * "This portal's" means: a table the install script makes. Names are
 * compared without regard to capitals, because some database servers store
 * every table name in small letters.
 *
 * WHAT IT CANNOT DO: recognise a table an OLDER release of the portal made
 * and a later one removed. Such a table is called "not the portal's", and
 * the database is refused: the safe mistake. It also says nothing about
 * tables whose names do not begin with "tbl": the wipe never touches those.
 *
 * @param \mysqli $conn An open connection, with the database chosen.
 *
 * @return list<string>|null Their names (empty when everything is the
 *                           portal's); null when it could not be worked out,
 *                           which the caller must treat as a refusal.
 */
function installTablesNotOurs(\mysqli $conn): ?array
{
    $script = @file_get_contents(INSTALL_SQL . DIRECTORY_SEPARATOR . 'full_schema.sql');
    if (is_string($script) === false
        || (int) preg_match_all('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?(tbl[A-Za-z0-9_]+)`?/i', $script, $made) < 1
    ) {
        return null;
    }
    $ours = array_flip(array_map('strtolower', $made[1]));

    try {
        $result = $conn->query("SHOW TABLES LIKE 'tbl%'");
        if ($result === false) {
            return null;
        }
        $notOurs = [];
        while (($row = $result->fetch_array(MYSQLI_NUM)) !== null) {
            $name = (string) $row[0];
            if (isset($ours[strtolower($name)]) === false) {
                $notOurs[] = $name;
            }
        }
        $result->free();

        return $notOurs;
    } catch (\mysqli_sql_exception) {
        return null;
    }
}

/**
 * 🔌 Open the wizard's working connection, from the details step 2 kept.
 *
 * This is the connection that loads the install script and every migration,
 * creates the first administrator and writes the first settings. It is opened
 * through Portal\Core\DbServer::connect(), the same way the portal itself
 * connects, so it uses the same character set and is made strict: a value that
 * does not fit its column is refused, not stored changed.
 *
 * It hands back null, exactly as it does when the server cannot be reached,
 * if the connection turns out not to be strict at all. Step 2 has already
 * refused that case and explained it, so this can only happen if the server
 * changed between two steps. The two steps that build things (3 and 4) answer
 * null by showing step 2's form again with INSTALL_NO_CONNECTION_MESSAGE. That
 * message cannot say WHICH reason it was, because null carries no reason; the
 * full explanation appears once the visitor sends step 2's form again, and
 * the message tells them so. The finish (step 5) answers null the same way:
 * it stores the portal's address through this connection, and writes nothing
 * at all without it. (It used to carry on without one, because recording the
 * installed version was all it did with it.)
 *
 * WHAT IT DOES NOT CATCH: a database that will not SAY how strict the
 * connection is. That is judged a warning, not a refusal, so the connection
 * is handed back.
 *
 * @return mysqli|null An open connection that is not loose; or null.
 */
function installGetDb(): ?mysqli
{
    $creds = $_SESSION['install_db'] ?? null;
    if ($creds === null) {
        return null;
    }

    mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
    try {
        $db = \Portal\Core\DbServer::connect(
            $creds['host'],
            $creds['user'],
            $creds['pass'],
            $creds['name'],
            $creds['port']
        );
        if (\Portal\Core\DbServer::strictness($db)['state'] === 'crit') {
            $db->close();
            return null;
        }
        return $db;
    } catch (\mysqli_sql_exception) {
        return null;
    }
}

// ---------------------------------------------------------------------------
// The first checks (drawn at step 1)
// ---------------------------------------------------------------------------
// Each row says what is needed ('label'), whether it is there ('pass'), what
// was found ('value') and, for a row that can fail, what to do about it in a
// hosting panel ('fix'). A row with 'note' set is information: it is never a
// pass or a fail and never hides the Continue button.
//
// These are a SCREEN, not a gate: a later step can still be asked for by its
// address. That is deliberate. Each thing checked here that a later step
// truly depends on is refused again at that step, with its own message.
$installExtension = static function (string $name, string $label, string $why): array {
    $loaded = extension_loaded($name);

    return [
        'label' => $label,
        'pass'  => $loaded,
        'value' => $loaded === true ? 'Switched on' : 'Not switched on',
        'fix'   => $why . ' In your hosting panel, look for the list of PHP extensions for this website and tick "'
                 . $name . '". If there is no such list, ask your hosting company to switch it on.',
    ];
};
// A folder the finish will need: there and writable, or not there yet and
// able to be made (the folder that holds _core is writable).
$installCanHave = static function (string $name): bool {
    $folder = INSTALL_ROOT . DIRECTORY_SEPARATOR . $name;

    return is_dir($folder) === true ? is_writable($folder) : (file_exists($folder) === false && is_writable(INSTALL_ROOT) === true);
};
$installSchemaFile  = INSTALL_SQL . DIRECTORY_SEPARATOR . 'full_schema.sql';
$installWebRules    = INSTALL_WEB_FOLDER . DIRECTORY_SEPARATOR . '.htaccess';
$installWebRulesOk  = is_file($installWebRules) === true && (int) filesize($installWebRules) > 0;
$installFoldersOk   = $installCanHave('_auth_keys') === true && $installCanHave('_uploads') === true && $installCanHave('_backups') === true;

$prereqs = [
    'php_version' => [
        'label' => 'PHP 8.4 or newer',
        'pass'  => version_compare(PHP_VERSION, '8.4.0', '>='),
        'value' => 'This website runs PHP ' . PHP_VERSION,
        'fix'   => 'The portal is written for PHP 8.4 and does not run on anything older. In your hosting panel, find the '
                 . 'PHP version setting for this website and choose 8.4 or newer.',
    ],
    'mysqli'   => $installExtension('mysqli', 'PHP can talk to a MySQL database (the "mysqli" extension)', 'Everything the portal keeps is in the database.'),
    'sodium'   => $installExtension('sodium', 'PHP can encrypt (the "sodium" extension)', 'Passwords and keys saved in Settings are stored encrypted.'),
    'json'     => $installExtension('json', 'PHP can read and write JSON (the "json" extension)', 'Settings, backups and the programming interface all use it.'),
    'mbstring' => $installExtension('mbstring', 'PHP can handle text in every alphabet (the "mbstring" extension)', 'Names and messages are not always in plain English letters.'),
    'schema_file' => [
        'label' => 'The database scripts were uploaded (the _sql folder)',
        'pass'  => is_dir(INSTALL_SQL) === true && is_readable($installSchemaFile) === true,
        'value' => is_dir(INSTALL_SQL) === false ? 'The folder _sql is missing' : (is_readable($installSchemaFile) === true ? 'Found' : 'The file full_schema.sql is missing from it'),
        'fix'   => 'The installer builds the database from the files in the folder _sql, beside _core. Upload that folder '
                 . 'again from the package, whole.',
    ],
    'private_folders' => [
        'label' => 'The portal can keep its private folders (_auth_keys, _uploads and _backups)',
        'pass'  => $installFoldersOk,
        'value' => $installFoldersOk === true ? 'Can be made and written to' : 'At least one cannot be made or written to',
        'fix'   => 'These three folders hold the database login and key, everything people upload, and the database '
                 . 'backups. The installer makes them beside _core. In your hosting panel\'s file manager, open the folder '
                 . 'that holds _core and either allow the website to write to it, or create the three folders yourself and '
                 . 'allow the website to write to each (your host\'s help pages say which permissions that needs; 755 '
                 . 'is the usual answer).',
    ],
    'web_rules' => [
        'label' => 'The web folder\'s rules file (.htaccess) was uploaded',
        'pass'  => $installWebRulesOk,
        'value' => $installWebRulesOk === true ? 'Found' : 'Missing, or empty',
        'fix'   => 'Files whose names begin with a full stop are hidden by many file managers, and are often left behind '
                 . 'when a folder is uploaded. Without this one, no address in the portal works except its front page. In '
                 . 'your file manager switch on "show hidden files", then upload the file named .htaccess from the '
                 . 'package\'s web folder into this website\'s web folder (the folder that holds index.php and assets).',
    ],
    'channel' => [
        'label'  => 'Which copy of the portal this is',
        'pass'   => true,
        'note'   => true,
        'value'  => 'This is ' . $installChannelWords['copy'] . '. ' . $installChannelWords['how'],
        'advice' => $installChannelWords['advice'],
    ],
];
$allPrereqsPassed = true;
foreach ($prereqs as $p) {
    if ($p['pass'] === false) {
        $allPrereqsPassed = false;
        break;
    }
}

// ---------------------------------------------------------------------------
// Which page is drawn
// ---------------------------------------------------------------------------
// One of these, in this order of precedence. Without the ownership code
// nothing but the code page is ever drawn, whatever step the address asks for
// and whatever a form said. `done` is tested first only because the finish
// has by then thrown the session's pass away; it is set in one place, by a
// finish that passed the gate AND was given the code again.
//
// 🚦 The "Build the tables" page is never drawn for a database no choice is
//    held for: the choice page is drawn in its place. With no choice held,
//    the database was not empty when the database step looked at it (an
//    empty one is given its choice there and then), so the question has
//    still to be answered. This is what somebody meets who opens the build
//    step by its address, or lands on it with the browser's Back button,
//    after typing another database.
if ($step === 3 && $isChoiceStep === false && $isIndustryStep === false && $isAddressStep === false
    && isset($_SESSION['install_db']) === true && installChoiceHeld() === ''
) {
    $step = 2;
    $isChoiceStep = true;
}
if ($justFinished === true) {
    $installPage = 'done';
} elseif ($ownerPassed === false) {
    $installPage = 'code';
} elseif ($isIndustryStep === true) {
    $installPage = 'organisation';
} elseif ($isChoiceStep === true) {
    $installPage = 'existing';
} elseif ($isAddressStep === true) {
    $installPage = 'addresses';
} else {
    $installPage = [1 => 'checks', 2 => 'database', 3 => 'tables', 4 => 'administrator', 5 => 'finish'][$step];
}

// The stages shown as the row of numbers at the top, and which one each page
// belongs to. Seven, not the old six: the code and the web addresses are
// stages a person passes through, and a row that skipped them would show
// the same number for two different pages.
$installStages = ['Your code', 'Checks', 'Database', 'Tables', 'Administrator', 'Web addresses', 'Finish'];
$installStage  = [
    'code' => 1, 'checks' => 2, 'organisation' => 2, 'database' => 3, 'existing' => 3, 'tables' => 4,
    'administrator' => 5, 'addresses' => 6, 'finish' => 7, 'done' => 8,
][$installPage];

$pageTitle = 'Install — ' . [
    'code'          => 'Your installation code',
    'checks'        => 'Checks',
    'organisation'  => 'Kind of organisation',
    'database'      => 'The database',
    'existing'      => 'A database that is not empty',
    'tables'        => 'Build the tables',
    'administrator' => 'The first administrator',
    'addresses'     => 'Web addresses',
    'finish'        => 'Finish',
    'done'          => 'Installation complete',
][$installPage];

// What the address and administrator pages need from the database, read
// only for those pages and only once the code has been given.
$installAdministrators = 0;
if ($installPage === 'administrator') {
    $installPeek = installGetDb();
    if ($installPeek !== null) {
        $installAdministrators = max(0, installAdministratorCount($installPeek));
        $installPeek->close();
    }
}
// The address offered on the Web addresses page: what was typed last time,
// else what was kept, else the address this very request arrived at. That
// last one is only a suggestion for the person to check, because a visitor
// chooses it; it is run through the same check as anything typed, and left
// out when it does not pass. (PortalAddress::fromRequest() works it out the
// same way, but belongs to the part of that file that needs the portal.)
$installAddressOffered = '';
$installSupportOffered = '';
if ($installPage === 'addresses' || $installPage === 'finish') {
    $kept = is_array($_SESSION['install_addresses'] ?? null) === true ? $_SESSION['install_addresses'] : [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && installField('action') === 'web_addresses') {
        $installAddressOffered = installField('portal_address');
        $installSupportOffered = installField('support_email');
    } elseif ($kept !== []) {
        $installAddressOffered = (string) ($kept['address'] ?? '');
        $installSupportOffered = (string) ($kept['support'] ?? '');
    } else {
        $https  = strtolower(is_string($_SERVER['HTTPS'] ?? null) === true ? $_SERVER['HTTPS'] : '');
        $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
        $host   = is_string($_SERVER['HTTP_HOST'] ?? null) === true ? $_SERVER['HTTP_HOST'] : '';
        $guess  = $host === '' ? ['address' => '', 'problem' => ''] : \Portal\Core\PortalAddress::check($scheme . '://' . $host);
        $installAddressOffered = $guess['problem'] === '' ? $guess['address'] : '';
    }
}

// Nothing here is kept by a browser or a cache in between, and no search
// engine is invited in: these pages hold a form for a database password.
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php
        // 🎨 Brand-aware favicons. Default is WebMS Intra; once the admin picks
        // "Church" (or any other industry) at Step 1.5, the session-backed
        // $INSTALL_BRAND_ASSET_FOLDER swaps in that brand's icon folder on the
        // very next render — no JS needed, the form POST → redirect cycle
        // re-resolves brand on the way back to the next step.
        //
        // 📐 Format fallback order matters: browsers pick the FIRST format
        // they understand. Order is therefore:
        //   1. image/svg+xml  → Chrome 80+, Firefox 41+, Safari 9+, Edge.
        //   2. image/png      → everything else with multi-size hinting.
        //   3. apple-touch-icon (PNG) → iOS/iPadOS home-screen.
        //   4. alternate icon (favicon.ico) → legacy IE, very old Safari.
        // SVG sources are vector; PNG variants are rendered from the same
        // SVG at build time via rsvg-convert and committed to the repo so
        // no runtime image processing is needed on DreamHost shared.
        $faviconBase = '/assets/images/brandkit/assets/' . htmlspecialchars($INSTALL_BRAND_ASSET_FOLDER, ENT_QUOTES, 'UTF-8');
    ?>
    <link rel="icon"             type="image/svg+xml"            href="<?php echo $faviconBase; ?>/icon.svg">
    <link rel="icon"             type="image/png" sizes="32x32"  href="<?php echo $faviconBase; ?>/icon-32.png">
    <link rel="icon"             type="image/png" sizes="16x16"  href="<?php echo $faviconBase; ?>/icon-16.png">
    <link rel="apple-touch-icon" sizes="192x192"                 href="<?php echo $faviconBase; ?>/icon-192.png">
    <link rel="apple-touch-icon" sizes="512x512"                 href="<?php echo $faviconBase; ?>/icon-512.png">
    <link rel="alternate icon"   type="image/x-icon"             href="<?php echo $faviconBase; ?>/favicon.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        /* =====================================================================
         * Installer styles — comprehensive across all 6 step layouts.
         * Tokens mirror web/public_html/assets/css/portal.css (kept in sync
         * manually since the installer runs standalone, before bootstrap.php
         * loads the portal). Kept here inline so the installer has zero
         * external CSS dependencies beyond Bootstrap.
         * =================================================================*/

        :root {
            --portal-primary:        #5e6ad2;
            --portal-primary-rgb:    94, 106, 210;
            --portal-primary-hover:  #4f5bbf;
            --portal-primary-subtle: #eef0fb;
            --portal-success:        #16a34a;
            --portal-warning:        #d97706;
            --portal-danger:         #dc2626;
            --portal-bg:             #f7f8fa;
            --portal-surface:        #ffffff;
            --portal-border:         #e5e7eb;
            --portal-border-strong:  #d1d5db;
            --portal-text:           #1b2330;
            --portal-text-muted:     #6b7280;
            --portal-text-subtle:    #9ca3af;
            --portal-radius-sm:      0.375rem;
            --portal-radius-md:      0.5rem;
            --portal-radius-lg:      0.75rem;
            --portal-shadow-md:      0 4px 6px -1px rgba(16,24,40,0.08),
                                     0 2px 4px -2px rgba(16,24,40,0.06);
            --portal-shadow-lg:      0 12px 20px -8px rgba(16,24,40,0.12),
                                     0 4px 8px -4px rgba(16,24,40,0.08);
            --portal-easing:         cubic-bezier(0.4,0,0.2,1);

            /* 🔗 Bind Bootstrap link variables to the indigo brand colour so
             * anchors don't fall back to the browser-default blue (which
             * clashes hard in dark mode — see issue / screenshot). */
            --bs-link-color:           var(--portal-primary);
            --bs-link-color-rgb:       var(--portal-primary-rgb);
            --bs-link-hover-color:     var(--portal-primary-hover);
            --bs-link-hover-color-rgb: var(--portal-primary-rgb);
        }

        /* =====================================================================
         * Dark mode — mirrors portal.css's [data-bs-theme="dark"] block
         * (applied to <html> by the FOUC script below).
         * =================================================================*/
        [data-bs-theme="dark"] {
            --portal-primary:        #7b86e8;
            --portal-primary-rgb:    123, 134, 232;
            --portal-primary-hover:  #8f99eb;
            --portal-primary-subtle: #1f2347;
            --portal-success:        #4ade80;
            --portal-warning:        #fbbf24;
            --portal-danger:         #f87171;
            --portal-bg:             #0f1115;
            --portal-surface:        #161a22;
            --portal-border:         #2c3441;
            --portal-border-strong:  #3a4252;
            --portal-text:           #e8eaf0;
            --portal-text-muted:     #9aa3b2;
            --portal-text-subtle:    #6b7280;
            --portal-shadow-md:      0 4px 6px -1px rgba(0,0,0,0.4),
                                     0 2px 4px -2px rgba(0,0,0,0.3);
            --portal-shadow-lg:      0 12px 20px -8px rgba(0,0,0,0.5),
                                     0 4px 8px -4px rgba(0,0,0,0.4);

            /* 🔗 Brighter link colour for dark surfaces, matched to portal.css. */
            --bs-link-color:           #9aa5f0;
            --bs-link-color-rgb:       154, 165, 240;
            --bs-link-hover-color:     #b3bcf6;
            --bs-link-hover-color-rgb: 179, 188, 246;
        }

        /* =====================================================================
         * Colour-blind safe palette — opt-in via [data-portal-cb="on"].
         * Mirrors portal.css; Wong-based palette distinguishable for
         * deutan + protan colour blindness (~95% of CB cases).
         * =================================================================*/
        [data-portal-cb="on"] {
            --portal-success: #009e73;
            --portal-danger:  #d55e00;
            --portal-warning: #e69f00;
        }
        [data-portal-cb="on"][data-bs-theme="dark"] {
            --portal-success: #5dd1a8;
            --portal-danger:  #ff8a4d;
            --portal-warning: #ffc04d;
        }

        /* =====================================================================
         * Theme / accessibility toggle buttons in the installer header
         * =================================================================*/
        .install-toggles {
            position: absolute;
            top: 1rem;
            right: 1rem;
            display: flex;
            gap: 0.375rem;
        }
        .install-toggle {
            background: var(--portal-surface);
            border: 1px solid var(--portal-border);
            color: var(--portal-text-muted);
            border-radius: var(--portal-radius-md);
            width: 2.25rem;
            height: 2.25rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1rem;
            transition: background 120ms var(--portal-easing),
                        color      120ms var(--portal-easing),
                        border-color 120ms var(--portal-easing);
        }
        .install-toggle:hover {
            background: var(--portal-bg);
            color: var(--portal-text);
            border-color: var(--portal-border-strong);
        }
        .install-toggle[aria-pressed="true"] {
            color: var(--portal-primary);
            background: var(--portal-primary-subtle);
            border-color: var(--portal-primary);
        }

        /* =====================================================================
         * Page shell
         * =================================================================*/
        html, body { height: 100%; }
        body {
            background: var(--portal-bg);
            color: var(--portal-text);
            font-family: "Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", Roboto,
                         "Helvetica Neue", Arial, sans-serif;
            font-size: 0.9375rem;
            line-height: 1.5;
        }
        .install-shell {
            max-width: 720px;
            margin: 0 auto;
            padding: 2.5rem 1.25rem 3rem;
        }

        /* =====================================================================
         * Header (above the wizard card)
         * =================================================================*/
        .install-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .install-header h1 {
            color: var(--portal-text);
            letter-spacing: -0.01em;
            font-weight: 600;
            font-size: 1.875rem;
            margin: 0;
        }
        .install-header .install-tagline {
            color: var(--portal-text-muted);
            margin: 0.375rem 0 0;
            font-size: 0.9375rem;
        }

        /* =====================================================================
         * Step indicator row
         * =================================================================*/
        .step-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 0.75rem;
            margin: 0 0 2rem;
            padding: 0;
            list-style: none;
        }
        /* Seven dots do not fit across a narrow phone at full size. */
        @media (max-width: 575.98px) {
            .step-row { gap: 0.375rem; }
            .step-row .step-badge { width: 2rem; height: 2rem; font-size: 0.8125rem; }
        }
        /* Read out by a screen reader, not shown. Written here, not borrowed
         * from Bootstrap, so the dots still read as bare numbers when the
         * outside stylesheet does not load. */
        .install-sr {
            position: absolute;
            width: 1px;
            height: 1px;
            margin: -1px;
            padding: 0;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        .step-badge {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.875rem;
            transition: background 200ms var(--portal-easing),
                        color      200ms var(--portal-easing),
                        box-shadow 200ms var(--portal-easing);
        }
        .step-active {
            background: var(--portal-primary);
            color: #fff;
            box-shadow: 0 0 0 4px rgba(var(--portal-primary-rgb), 0.18);
        }
        .step-done {
            background: var(--portal-success);
            color: #fff;
        }
        .step-pending {
            background: var(--portal-surface);
            color: var(--portal-text-muted);
            border: 1px solid var(--portal-border-strong);
        }

        /* =====================================================================
         * Wizard card
         * =================================================================*/
        .install-card {
            background: var(--portal-surface);
            border: 1px solid var(--portal-border);
            border-radius: var(--portal-radius-lg);
            box-shadow: var(--portal-shadow-md);
            overflow: hidden;
        }
        .install-card .card-body { padding: 2.5rem; }
        @media (max-width: 575.98px) {
            .install-card .card-body { padding: 1.5rem; }
            .install-shell { padding: 1.5rem 1rem 2rem; }
        }

        /* Step heading + lead paragraph */
        .install-card h2 {
            color: var(--portal-text);
            font-weight: 600;
            letter-spacing: -0.01em;
            font-size: 1.5rem;
            line-height: 1.3;
            margin: 0 0 0.5rem;
        }
        .install-card h2 + p {
            color: var(--portal-text);
            font-size: 0.9375rem;
            line-height: 1.55;
            margin: 0 0 0.75rem;
        }
        .install-card h2 + p + p.text-muted,
        .install-card h2 + p + p.small {
            color: var(--portal-text-muted) !important;
            font-size: 0.875rem;
            margin: 0 0 1.5rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--portal-border);
        }
        /* If h2 is followed by only ONE p (no "small" follow-up), still pad it */
        .install-card h2 + p:not(:has(+ p)) {
            margin-bottom: 1.5rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--portal-border);
        }
        .install-card h3 {
            color: var(--portal-text);
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin: 1.75rem 0 0.875rem;
        }

        /* =====================================================================
         * Forms (steps 2 and 4)
         * =================================================================*/
        .install-card .mb-3 { margin-bottom: 1.25rem !important; }
        .install-card .row  { --bs-gutter-x: 1rem; }

        .install-card .form-label {
            font-weight: 500;
            color: var(--portal-text);
            font-size: 0.875rem;
            margin-bottom: 0.375rem;
        }
        .install-card .form-control,
        .install-card .form-select {
            border: 1px solid var(--portal-border-strong);
            border-radius: var(--portal-radius-md);
            padding: 0.5625rem 0.75rem;
            font-size: 0.9375rem;
            line-height: 1.4;
            color: var(--portal-text);
            background-color: var(--portal-surface);
            transition: border-color 150ms var(--portal-easing),
                        box-shadow   150ms var(--portal-easing);
        }
        .install-card .form-control:hover:not(:focus),
        .install-card .form-select:hover:not(:focus) {
            border-color: var(--portal-text-subtle);
        }
        .install-card .form-control:focus,
        .install-card .form-select:focus {
            border-color: var(--portal-primary);
            box-shadow: 0 0 0 3px rgba(var(--portal-primary-rgb), 0.18);
            outline: none;
        }
        .install-card .form-control::placeholder { color: var(--portal-text-subtle); }
        .install-card .form-text {
            color: var(--portal-text-muted);
            font-size: 0.8125rem;
            margin-top: 0.375rem;
            line-height: 1.4;
        }
        .install-card .form-text code,
        .install-card p code,
        .install-card li code {
            background: var(--portal-bg);
            color: var(--portal-text);
            padding: 0.125rem 0.375rem;
            border-radius: var(--portal-radius-sm);
            font-size: 0.875em;
            font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Monaco, Consolas, monospace;
        }

        /* =====================================================================
         * Button row (step navigation: Back / Continue)
         * =================================================================*/
        .install-card .d-flex.justify-content-between {
            margin-top: 1.75rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--portal-border);
            align-items: center;
            /* Two buttons with long labels do not fit side by side on a
             * narrow phone: the second drops below the first. */
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .install-card .btn {
            font-weight: 500;
            transition: background 120ms var(--portal-easing),
                        border-color 120ms var(--portal-easing),
                        color 120ms var(--portal-easing),
                        box-shadow 120ms var(--portal-easing);
        }
        .install-card .btn-primary {
            --bs-btn-bg:                  var(--portal-primary);
            --bs-btn-border-color:        var(--portal-primary);
            --bs-btn-hover-bg:            var(--portal-primary-hover);
            --bs-btn-hover-border-color:  var(--portal-primary-hover);
            --bs-btn-active-bg:           var(--portal-primary-hover);
            --bs-btn-active-border-color: var(--portal-primary-hover);
            padding: 0.5625rem 1.25rem;
            border-radius: var(--portal-radius-sm);
        }
        .install-card .btn-primary:hover {
            box-shadow: 0 2px 8px -2px rgba(var(--portal-primary-rgb), 0.4);
        }
        .install-card .btn-success {
            --bs-btn-bg:                  var(--portal-success);
            --bs-btn-border-color:        var(--portal-success);
            --bs-btn-hover-bg:            #15803d;
            --bs-btn-hover-border-color:  #15803d;
            --bs-btn-active-bg:           #166534;
            --bs-btn-active-border-color: #166534;
            padding: 0.5625rem 1.25rem;
            border-radius: var(--portal-radius-sm);
        }
        /* Back is a quiet secondary — text-link style */
        .install-card .btn-outline-secondary {
            --bs-btn-color:               var(--portal-text-muted);
            --bs-btn-bg:                  transparent;
            --bs-btn-border-color:        transparent;
            --bs-btn-hover-color:         var(--portal-text);
            --bs-btn-hover-bg:            var(--portal-bg);
            --bs-btn-hover-border-color:  transparent;
            --bs-btn-active-color:        var(--portal-text);
            --bs-btn-active-bg:           var(--portal-bg);
            --bs-btn-active-border-color: transparent;
            padding: 0.5rem 0.875rem;
            border-radius: var(--portal-radius-sm);
            font-size: 0.875rem;
        }
        .install-card .btn-lg {
            padding: 0.75rem 1.75rem;
            font-size: 1rem;
            border-radius: var(--portal-radius-sm);
        }

        /* =====================================================================
         * The list of checks (step 1), and the summaries at the finish.
         * A list, not a table: each row has a sentence of "what to do"
         * under it, and a table cannot hold that on a narrow phone.
         * =================================================================*/
        .install-checks {
            list-style: none;
            margin: 0 0 1.5rem;
            padding: 0;
            border-top: 1px solid var(--portal-border);
        }
        .install-check {
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--portal-border);
            overflow-wrap: anywhere;
        }
        .install-check > span,
        .install-check > strong { display: block; }
        .install-check-label { font-weight: 600; margin-top: 0.125rem; }
        .install-check-value { color: var(--portal-text-muted); }
        .install-check-fix   { margin-top: 0.375rem; font-size: 0.875rem; }
        .prereq-pass { color: var(--portal-success); font-weight: 500; font-size: 0.8125rem; }
        .prereq-fail { color: var(--portal-danger);  font-weight: 500; font-size: 0.8125rem; }
        .prereq-warn { color: var(--portal-warning); font-weight: 500; font-size: 0.8125rem; }
        .prereq-note { color: var(--portal-text-muted); font-weight: 500; font-size: 0.8125rem; }

        /* The numbered instructions on the code page. */
        .install-steps { padding-left: 1.25rem; margin-bottom: 1.5rem; }
        .install-steps li { margin-bottom: 0.5rem; overflow-wrap: anywhere; }

        /* The box the code is typed into: wide letters, shown as capitals
         * whatever is typed (the installer does not mind which). */
        .install-card .install-code-input {
            font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Monaco, Consolas, monospace;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            max-width: 22rem;
        }
        .install-card .install-code-input::placeholder { letter-spacing: 0.04em; }

        /* =====================================================================
         * 🔗 Defensive anchor styling — bind <a> directly to the brand link
         * colour. `--bs-link-color` is already pointed at the indigo palette
         * above, but if Bootstrap fails to load (CDN outage / SRI mismatch)
         * Bootstrap's own `a { color: var(--bs-link-color); }` rule never
         * runs and anchors fall back to the browser default (underlined
         * blue, visited purple — particularly ugly in dark mode). This
         * rule guarantees readability even with zero external CSS.
         * =================================================================*/
        a {
            color: var(--bs-link-color);
            text-decoration: underline;
            text-underline-offset: 0.15em;
        }
        a:hover,
        a:focus {
            color: var(--bs-link-hover-color);
        }
        a:visited {
            color: var(--bs-link-color);
        }
        /* Buttons-as-links keep their button look, never link-blue */
        a.btn,
        a.btn:hover,
        a.btn:focus,
        a.btn:visited {
            text-decoration: none;
            color: var(--bs-btn-color, inherit);
        }

        /* =====================================================================
         * Alerts (steps 3 / 5 / 6 use these heavily)
         * =================================================================*/
        .install-card .alert {
            border-radius: var(--portal-radius-md);
            border: 1px solid;
            padding: 1rem 1.125rem;
            font-size: 0.9375rem;
            line-height: 1.5;
            margin-bottom: 1.5rem;
        }
        .install-card .alert strong { font-weight: 600; }
        .install-card .alert ul,
        .install-card .alert ol {
            padding-left: 1.25rem;
            margin: 0.5rem 0 0;
        }
        .install-card .alert li { margin: 0.25rem 0; }
        /* Code chips inside alerts: tonal-on-tonal so they read clearly on
         * both the light-mode pastel and the dark-mode deep-shade backgrounds,
         * without the bright white pill that previously washed out the text. */
        .install-card .alert code {
            background:    rgba(0, 0, 0, 0.08);
            color:         currentColor;
            padding:       0.125rem 0.4rem;
            border-radius: var(--portal-radius-sm);
            font-size:     0.875em;
            font-family:   ui-monospace, SFMono-Regular, "SF Mono", Menlo,
                           Monaco, Consolas, monospace;
        }
        [data-bs-theme="dark"] .install-card .alert code {
            background: rgba(255, 255, 255, 0.12);
        }
        /* Links inside alerts — pull from currentColor so the underline
         * inherits the alert's own tonal foreground (dark-on-light or
         * light-on-dark) and is always readable against the tinted bg. */
        .install-card .alert a {
            color: currentColor;
            text-decoration: underline;
            font-weight: 600;
        }
        .install-card .alert a:hover,
        .install-card .alert a:focus {
            color: currentColor;
            opacity: 0.8;
        }

        /* Light-mode alert palettes — pastel backgrounds, deep tonal text */
        .install-card .alert-info {
            background:    #eef2ff;     /* indigo-tinted */
            border-color:  #c7d2fe;
            color:         #312e81;
        }
        .install-card .alert-warning {
            background:    #fffbeb;
            border-color:  #fde68a;
            color:         #92400e;
        }
        .install-card .alert-success {
            background:    #f0fdf4;
            border-color:  #bbf7d0;
            color:         #166534;
        }
        .install-card .alert-danger {
            background:    #fef2f2;
            border-color:  #fecaca;
            color:         #991b1b;
        }
        /* Dark-mode alert palettes — mirrors portal.css so the installer
         * stays consistent with the runtime portal. Deep-shade bg, light
         * tonal text — readable, no white "punch" in dark mode. */
        [data-bs-theme="dark"] .install-card .alert-info {
            background:    #1e1b4b;
            border-color:  #312e81;
            color:         #c7d2fe;
        }
        [data-bs-theme="dark"] .install-card .alert-warning {
            background:    #451a03;
            border-color:  #92400e;
            color:         #fde68a;
        }
        [data-bs-theme="dark"] .install-card .alert-success {
            background:    #052e16;
            border-color:  #166534;
            color:         #bbf7d0;
        }
        [data-bs-theme="dark"] .install-card .alert-danger {
            background:    #450a0a;
            border-color:  #991b1b;
            color:         #fecaca;
        }
        /* Top-of-card error/success messages (different markup: alert + install-card class) */
        .alert.install-card { box-shadow: none; margin-bottom: 1.5rem; }

        /* =====================================================================
         * Step 6 (Complete) — success heading + final CTA
         * =================================================================*/
        .install-card h2.text-success {
            color: var(--portal-success) !important;
        }
        .install-card .text-center {
            margin-top: 0.5rem;
        }
        .install-card .text-center .btn-lg {
            min-width: 220px;
        }

        /* =====================================================================
         * Footer
         * =================================================================*/
        .install-footer {
            text-align: center;
            color: var(--portal-text-muted);
            font-size: 0.8125rem;
            margin-top: 2rem;
            padding-top: 1rem;
        }

        /* =====================================================================
         * Focus indicators (WCAG)
         * =================================================================*/
        :focus-visible {
            outline: 3px solid var(--portal-primary);
            outline-offset: 2px;
        }
    </style>

    <!-- 🌙 Prevent FOUC: apply saved theme + CB prefs before first paint -->
    <script>
    (function(){
        var html = document.documentElement;
        var t = localStorage.getItem('portal-theme');
        if (t === 'auto' || t === null) {
            var prefersDark = window.matchMedia
                && window.matchMedia('(prefers-color-scheme: dark)').matches;
            html.setAttribute('data-bs-theme', prefersDark ? 'dark' : 'light');
        } else if (t === 'dark' || t === 'light') {
            html.setAttribute('data-bs-theme', t);
        }
        if (localStorage.getItem('portal-cb') === 'on') {
            html.setAttribute('data-portal-cb', 'on');
        }
    })();
    </script>
</head>
<body>
<div class="install-shell" style="position: relative;">

    <!-- 🌙 / 🎨 Theme + accessibility toggles
         Inline SVG icons so the installer doesn't need Font Awesome loaded. -->
    <div class="install-toggles">
        <button type="button" class="install-toggle" id="installThemeToggle"
                title="Theme: auto (system) — click for light"
                aria-label="Toggle theme">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="9"/>
                <path d="M12 3v18" />
                <path d="M12 3a9 9 0 0 1 0 18 9 9 0 0 1 0-18z" fill="currentColor" opacity="0.3"/>
            </svg>
        </button>
        <button type="button" class="install-toggle" id="installCbToggle"
                title="Colour-blind safe palette: off — click to turn on"
                aria-label="Toggle colour-blind safe palette"
                aria-pressed="false">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/>
                <circle cx="12" cy="12" r="3"/>
            </svg>
        </button>
    </div>

    <!-- Header. The version is shown only once the ownership code has been
         given: which release a server runs is the first thing somebody
         looking for a way in wants to know. -->
    <div class="install-header">
        <h1><?php echo htmlspecialchars($INSTALL_PRODUCT_NAME, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="install-tagline">Installation<?php if ($ownerPassed === true): ?> &mdash; version <?php echo htmlspecialchars(INSTALL_VERSION, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></p>
    </div>

    <!-- Step indicator: one numbered dot for each stage, named for a screen
         reader and on hover. -->
    <ol class="step-row" aria-label="The stages of the installation">
        <?php foreach ($installStages as $index => $stageName): ?>
            <?php
            $number = $index + 1;
            $cls    = 'step-pending';
            $when   = 'to come';
            if ($number < $installStage) {
                $cls  = 'step-done';
                $when = 'done';
            } elseif ($number === $installStage) {
                $cls  = 'step-active';
                $when = 'this page';
            }
            ?>
            <li class="step-badge <?php echo $cls; ?>" title="<?php echo htmlspecialchars($stageName, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $number === $installStage ? ' aria-current="step"' : ''; ?>>
                <span class="install-sr">Stage </span><?php echo $number; ?><span class="install-sr">: <?php echo htmlspecialchars($stageName . ' (' . $when . ')', ENT_QUOTES, 'UTF-8'); ?></span>
            </li>
        <?php endforeach; ?>
    </ol>

    <!-- Error / success messages -->
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger install-card" role="alert" id="install-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="alert alert-success install-card"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <!-- Step content -->
    <div class="card install-card shadow-sm" id="install-page" data-install-page="<?php echo htmlspecialchars($installPage, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="card-body p-4">

<?php if ($installPage === 'code'): ?>
            <!-- THE OWNERSHIP CODE. Everything on this page is the same for
                 every visitor: it says nothing about the server. -->
            <h2 class="h5 mb-3">First, show that this is your hosting</h2>
            <p>Anybody on the internet can open this page. So before it does anything, the installer has to know that you are the person who put the portal on this server. It has written a code into a file on the server. Only somebody who can open the hosting account&rsquo;s files can read it.</p>

            <?php if ($ownerCodeChanged === true && $ownerCodeProblem === ''): ?>
                <div class="alert alert-info" id="install-code-changed">
                    <strong>The code has changed since you typed it.</strong>
                    That happens when ten wrong codes are tried in a row, by anybody, or when the file was removed. Open the file again and type the code that is in it now. Nothing you have done so far has been lost.
                </div>
            <?php endif; ?>

            <?php if ($ownerCodeProblem !== ''): ?>
                <div class="alert alert-warning" id="install-code-problem">
                    <strong>The installer could not write its code file,</strong> because <?php echo htmlspecialchars($ownerCodeProblem, ENT_QUOTES, 'UTF-8'); ?>.
                    <p class="mb-0 mt-2"><strong>What to do:</strong> in your hosting panel&rsquo;s file manager, open the folder you uploaded the portal into (the one that holds <code>_apps</code>, <code>_core</code> and <code>_install</code>). If there is no folder named <code>_auth_keys</code> there, create one. Then allow the website to write to it: your host&rsquo;s help pages say which permissions that needs, and 755 is the usual answer. Come back and press the button below.</p>
                </div>
                <a href="?step=1" class="btn btn-primary">Try again</a>
            <?php else: ?>
                <h3 class="h6 mt-4 mb-2">Where the code is</h3>
                <ol class="install-steps">
                    <li>Open your hosting panel&rsquo;s <strong>file manager</strong>: the page where the portal&rsquo;s files were uploaded.</li>
                    <li>Go to the folder the portal was uploaded into. It is the one that holds the folders <code>_apps</code>, <code>_core</code> and <code>_install</code>, one level above the web folder (the folder that holds <code>index.php</code>).</li>
                    <li>Open the folder <code>_auth_keys</code>. The installer has just made it; if you do not see it, press the file manager&rsquo;s Refresh or Reload button.</li>
                    <li>Open the file <code>install-code.php</code> with <strong>View</strong> or <strong>Edit</strong>, and do not change it. If your file manager will not open it, download it and open it with any text editor, such as Notepad.</li>
                    <li>The code is on the line that begins <strong>YOUR CODE IS</strong>: sixteen letters in four groups of four.</li>
                </ol>

                <form method="post" autocomplete="off">
                    <?php echo installTokenField(); ?>
                    <input type="hidden" name="action" value="owner_code">
                    <div class="mb-3">
                        <label for="owner_code" class="form-label">Installation code</label>
                        <input type="text" class="form-control install-code-input" id="owner_code" name="owner_code"
                               required maxlength="40" autocomplete="off" autocapitalize="characters" spellcheck="false"
                               placeholder="XXXX-XXXX-XXXX-XXXX" aria-describedby="owner_code_help">
                        <div class="form-text" id="owner_code_help">Capital or small letters, with or without the dashes. There are no digits in it.</div>
                    </div>
                    <button type="submit" class="btn btn-primary">Continue &rarr;</button>
                </form>
                <p class="text-muted small mt-3 mb-0">After ten wrong codes in a row the installer writes a new code into the same file. If your code is refused, open the file again.</p>
                <p class="text-muted small mt-2 mb-0" id="install-code-readonly">If the code keeps changing because somebody else keeps sending wrong ones, set the file <code>install-code.php</code> to read-only in the file manager after you have read it (usually under &ldquo;Permissions&rdquo;): the code then stays as it is.</p>
                <p class="text-muted small mt-2 mb-0" id="install-code-again">If you are asked for this code again part way through, the installer was left open too long and has forgotten where it was. Type the code again. The database details have to be typed again too; what was already built in the database is kept.</p>
            <?php endif; ?>

<?php elseif ($installPage === 'organisation'): ?>
            <!-- STEP 1.5: Organisation Type — picks the product brand preset (#296) -->
            <h2 class="h5 mb-3">What kind of organisation is this?</h2>
            <p>This decides the name the portal goes by and the artwork it starts with. For a place of worship it is called <strong>ChurchMS</strong>; the general choice keeps the name <strong>WebMS Intra</strong>. Every feature is there whichever you choose.</p>
            <p class="text-muted small">It can be changed later in Admin, Settings, where it is called <code>portal.industry</code>.</p>

            <form method="post" autocomplete="off">
                <?php echo installTokenField(); ?>
                <input type="hidden" name="action" value="select_industry">
                <input type="hidden" name="step" value="1.5">

                <div class="mb-3">
                    <label for="industry" class="form-label">Kind of organisation</label>
                    <select id="industry" name="industry" class="form-select" required>
                        <?php foreach ($INSTALL_BRAND_PRESETS as $key => $preset):
                            // Skip the unnamed alias of generic — '' and 'generic' map to the same preset
                            if ($key === '') { continue; }
                            $isSelected = ($key === $selectedIndustry);
                        ?>
                            <option value="<?php echo htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo $isSelected === true ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) ($preset['displayLabel'] ?? $key), ENT_QUOTES, 'UTF-8'); ?>
                                — will be called <?php echo htmlspecialchars((string) ($preset['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">
                        The school, charity, community and small-business choices come with simple starter artwork, not finished logos. They are fine to install, but worth replacing with your own artwork before the public sees it.
                    </div>
                </div>

                <div class="d-flex justify-content-between">
                    <a href="?step=1" class="btn btn-outline-secondary">&larr; Back</a>
                    <button type="submit" class="btn btn-primary">Continue &rarr;</button>
                </div>
            </form>

<?php elseif ($installPage === 'checks'): ?>
            <!-- STEP 1: the first checks -->
            <h2 class="h5 mb-3">Checks</h2>
            <p>Thank you. Before anything is installed, here is what <?php echo htmlspecialchars($INSTALL_PRODUCT_NAME, ENT_QUOTES, 'UTF-8'); ?> needs from this server, and whether it is there.</p>

            <ul class="install-checks" id="install-checks">
                <?php foreach ($prereqs as $checkKey => $p): ?>
                    <?php
                    $isNote  = ($p['note'] ?? false) === true;
                    $assumed = ($checkKey === 'channel' && $installChannelWords['assumed'] === true);
                    ?>
                    <li class="install-check" data-check="<?php echo htmlspecialchars((string) $checkKey, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php if ($isNote === true): ?>
                            <span class="<?php echo $assumed === true ? 'prereq-warn' : 'prereq-note'; ?>"><?php echo $assumed === true ? '&#9888; Assumed' : '&#9432; For your information'; ?></span>
                        <?php elseif ($p['pass'] === true): ?>
                            <span class="prereq-pass">&#10004; In order</span>
                        <?php else: ?>
                            <span class="prereq-fail">&#10008; Needs attention</span>
                        <?php endif; ?>
                        <strong class="install-check-label"><?php echo htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <span class="install-check-value"><?php echo htmlspecialchars($p['value'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php if ($isNote === false && $p['pass'] === false): ?>
                            <span class="install-check-fix"><strong>What to do:</strong> <?php echo htmlspecialchars((string) ($p['fix'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <?php foreach (($p['advice'] ?? []) as $advice): ?>
                            <span class="install-check-fix"><?php echo htmlspecialchars((string) $advice, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endforeach; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <p class="text-muted small">
                Your database is checked separately, at the database step. It cannot be
                checked here, because the installer has to connect to it first and it does
                not yet know where it is or how to sign in. Once it connects it will
                tell you which database product and version you are running, and whether
                it is one this portal supports.
            </p>

            <?php if ($allPrereqsPassed === true): ?>
                <a href="?step=1.5" class="btn btn-primary">Continue &rarr;</a>
            <?php else: ?>
                <div class="alert alert-warning mt-3">
                    Something above needs attention before the portal can be installed. Each one says what to do. When you have done it, check again.
                </div>
                <a href="?step=1" class="btn btn-outline-secondary">Check again</a>
            <?php endif; ?>

<?php elseif ($installPage === 'database'): ?>
            <!-- STEP 2: the database -->
            <?php
            // What was typed, shown again after a refusal. NOT the password:
            // a password is never printed back into a page.
            $typedOr = static fn (string $name, string $default): string => array_key_exists($name, $_POST) === true ? installField($name) : $default;
            ?>
            <h2 class="h5 mb-3">The database</h2>
            <p>The portal keeps everything in a MySQL database. Your hosting panel has a page for databases, often called &ldquo;MySQL Databases&rdquo;. Create an empty database there and a database user, give that user full rights to the database, then type the details below exactly as the panel shows them.</p>
            <p class="text-muted small" id="install-database-button">Pressing the button tests the connection and looks at what the database holds. If there is no database of the name you type and this user is allowed to create one, the button creates it, empty. No table is built yet, and nothing in a database that is already there is changed.</p>

            <form method="post" autocomplete="off">
                <?php echo installTokenField(); ?>
                <input type="hidden" name="action" value="db_config">
                <input type="hidden" name="step" value="2">

                <div class="mb-3">
                    <label for="db_host" class="form-label">Database host</label>
                    <input type="text" class="form-control" id="db_host" name="db_host"
                           value="<?php echo htmlspecialchars($typedOr('db_host', 'localhost'), ENT_QUOTES, 'UTF-8'); ?>" required>
                    <div class="form-text">Often <code>localhost</code>. Some hosts give the database a host name of its own; the panel shows it.</div>
                </div>

                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label for="db_user" class="form-label">Database user name</label>
                        <input type="text" class="form-control" id="db_user" name="db_user"
                               value="<?php echo htmlspecialchars($typedOr('db_user', ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="db_port" class="form-label">Port</label>
                        <input type="number" class="form-control" id="db_port" name="db_port" min="1" max="65535"
                               value="<?php echo (int) $typedOr('db_port', '3306'); ?>">
                        <div class="form-text">3306 unless your host says otherwise.</div>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="db_pass" class="form-label">Database password</label>
                    <input type="password" class="form-control" id="db_pass" name="db_pass" autocomplete="off">
                    <?php if ($error !== ''): ?>
                        <div class="form-text">Please type the password again: it is never shown back.</div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label for="db_name" class="form-label">Database name</label>
                    <input type="text" class="form-control" id="db_name" name="db_name"
                           value="<?php echo htmlspecialchars($typedOr('db_name', 'webms_intra'), ENT_QUOTES, 'UTF-8'); ?>" required>
                    <div class="form-text">The name of the empty database you made. If there is no database of this name and this user is allowed to create one, the installer creates it.</div>
                </div>

                <div class="d-flex justify-content-between">
                    <a href="?step=1.5" class="btn btn-outline-secondary">&larr; Back</a>
                    <button type="submit" class="btn btn-primary">Test the connection &rarr;</button>
                </div>
            </form>

<?php elseif ($installPage === 'existing'): ?>
            <!-- STEP 2.5: Existing-data choice -->
            <?php
            $stateInfo  = is_array($_SESSION['install_db_state'] ?? null) === true ? $_SESSION['install_db_state'] : ['state' => DB_STATE_PARTIAL];
            $stateCode  = (string) ($stateInfo['state'] ?? DB_STATE_PARTIAL);
            $tableCount = (int) ($stateInfo['table_count'] ?? 0);
            $installedV = $stateInfo['installed_version'] ?? null;
            $canContinue = ($stateCode !== DB_STATE_FRESH_REQUIRED);
            $isUpgrade  = ($stateCode === DB_STATE_INSTALLED_UPGRADE);
            $isPartial  = ($stateCode === DB_STATE_PARTIAL);
            $isForce    = ($stateCode === DB_STATE_FRESH_REQUIRED);
            // 🔄 Already installed, and already at exactly this version. There
            //    is nothing to upgrade, so the only reasons to be here are to go
            //    back to the portal or to start again from nothing.
            $isCurrent  = ($stateCode === DB_STATE_INSTALLED_CURRENT);
            $policyArr  = (array) (require INSTALL_ROOT
                . DIRECTORY_SEPARATOR . '_install'
                . DIRECTORY_SEPARATOR . 'upgrade-policy.php');
            $confirmExpected = ((bool) ($policyArr['require_hostname_confirmation_for_drop'] ?? true))
                ? (is_string($_SERVER['HTTP_HOST'] ?? null) === true ? $_SERVER['HTTP_HOST'] : 'DROP')
                : 'DROP';
            // 🪪 Which database this page is about, and 👥 how many accounts
            //    it held when the database step looked: -1 when they could
            //    not be counted (no table of people, in a database built
            //    only part of the way).
            $existingDb       = is_array($_SESSION['install_db'] ?? null) === true ? $_SESSION['install_db'] : [];
            $existingAccounts = is_int($stateInfo['accounts'] ?? null) === true ? $stateInfo['accounts'] : -1;
            ?>
            <h2 class="h5 mb-3"><?php echo $isCurrent === true
                ? 'This portal is already installed and up to date'
                : 'This database is not empty'; ?></h2>

            <?php
            // 🛢️ Same database version panel as step 3 — shown here too, because
            //    an upgrade onto an unsupported database is worth knowing about
            //    before you commit to it, not after.
            require INSTALL_ROOT
                . DIRECTORY_SEPARATOR . '_install'
                . DIRECTORY_SEPARATOR . 'db_server_banner.php';
            ?>

            <?php if ($isCurrent === true): ?>
                <div class="alert alert-success">
                    <strong>Everything is already up to date.</strong>
                    This portal is installed at version
                    <code><?php echo htmlspecialchars((string) $installedV, ENT_QUOTES, 'UTF-8'); ?></code>,
                    which is the same version as the files on the server. There is
                    nothing to upgrade.
                </div>
                <p>
                    You have two choices. Most people want the first one.
                </p>
                <ul>
                    <li>
                        <strong>Go back to the portal.</strong> Nothing is changed.
                        This is almost certainly what you want.
                    </li>
                    <li>
                        <strong>Wipe everything and install again from nothing.</strong>
                        This is for starting over on a test installation, or before
                        handing a portal to somebody else. It cannot be undone.
                    </li>
                </ul>
            <?php elseif ($isUpgrade === true): ?>
                <div class="alert alert-info">
                    <strong>This portal is already installed</strong> at version
                    <code><?php echo htmlspecialchars((string) $installedV, ENT_QUOTES, 'UTF-8'); ?></code>.
                    The code on disk is at
                    <code><?php echo htmlspecialchars(INSTALL_VERSION, ENT_QUOTES, 'UTF-8'); ?></code>
                    — an upgrade is needed.
                </div>
            <?php elseif ($isPartial === true && $installedV === null): ?>
                <?php
                // ✍️ "No finished installation is RECORDED", never "none was
                //    ever finished". The installer cannot tell an install
                //    that stopped part way from a working portal whose
                //    version was never written down (the version has only
                //    been recorded since May 2026) or was emptied. It used to
                //    say "no installation was ever finished in it" to
                //    somebody looking at a portal with three people in it.
                ?>
                <div class="alert alert-warning" id="install-existing-unfinished">
                    <strong>This database already holds <?php echo $tableCount; ?> of the portal&rsquo;s tables, and no finished installation is recorded in it.</strong>
                    That is what this installer leaves behind when it is interrupted part way: for example when it was left open so long that it forgot where it was. It is also what a working portal looks like if it was installed before the portal began to record its version. The number of accounts below helps to tell which this is: an interrupted installation holds none, or only the administrator it had just made. You can carry on with what is there, or wipe it and start again.
                </div>
            <?php elseif ($isPartial === true): ?>
                <div class="alert alert-warning" id="install-existing-finished">
                    <strong>This database already holds a portal,</strong> installed at version
                    <code><?php echo htmlspecialchars((string) $installedV, ENT_QUOTES, 'UTF-8'); ?></code>, with <?php echo $tableCount; ?> tables.
                    These files have not been set up to use it yet. You can carry on with what is there, which keeps everything in it, or wipe it and start again.
                </div>
            <?php elseif ($isForce === true): ?>
                <div class="alert alert-danger">
                    <strong>In-place upgrade not available.</strong>
                    The installed version
                    (<code><?php echo htmlspecialchars((string) $installedV, ENT_QUOTES, 'UTF-8'); ?></code>)
                    is older than the upgrade policy's
                    <code>fresh_required_below</code> threshold. You must drop
                    and rebuild to install this version. Any data you wish to
                    preserve should be backed up first.
                </div>
            <?php endif; ?>

            <p id="install-existing-accounts">
                This page is about the database <code><?php echo htmlspecialchars((string) ($existingDb['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code> on <code><?php echo htmlspecialchars((string) ($existingDb['host'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code>.
                <?php if ($existingAccounts > 0): ?>
                    <strong>It holds <?php echo $existingAccounts === 1 ? '1 account' : (int) $existingAccounts . ' accounts'; ?>:</strong> people who can sign in to a portal. Wiping it removes <?php echo $existingAccounts === 1 ? 'that account' : 'every one of them'; ?>, with everything else in it.
                <?php elseif ($existingAccounts === 0): ?>
                    <strong>It holds no accounts:</strong> nobody has been added to it yet.
                <?php else: ?>
                    The installer could not count the accounts in it, because the table that holds them is not there or cannot be read.
                <?php endif; ?>
            </p>

            <form method="post">
                <?php echo installTokenField(); ?>
                <?php echo installDatabaseField(); ?>
                <input type="hidden" name="action" value="install_action">
                <input type="hidden" name="step"   value="2.5">

                <div class="install-card mb-3" style="padding:1rem;border:1px solid var(--bs-border-color);border-radius:.5rem;">
                    <div class="form-check">
                        <input class="form-check-input" type="radio"
                               name="install_action_choice" id="choice_continue"
                               value="continue"
                               <?php echo $canContinue === false ? 'disabled' : 'checked'; ?>>
                        <label class="form-check-label" for="choice_continue">
                            <strong>Carry on with what is there</strong>
                            <?php if ($isUpgrade === true): ?>
                                <span class="badge bg-info text-dark ms-1">Upgrade</span>
                            <?php elseif ($isPartial === true): ?>
                                <span class="badge bg-success text-dark ms-1">Recommended</span>
                            <?php endif; ?>
                            <span class="d-block text-muted small mt-1">
                                Any table that is missing is built, and any that is out of date is brought up to date. Nothing in the database is removed.
                            </span>
                        </label>
                    </div>
                </div>

                <div class="install-card mb-3" style="padding:1rem;border:1px solid var(--bs-border-color);border-radius:.5rem;">
                    <div class="form-check">
                        <input class="form-check-input" type="radio"
                               name="install_action_choice" id="choice_drop"
                               value="drop"
                               <?php echo $isForce === true ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="choice_drop">
                            <strong>Wipe it and start again from nothing</strong>
                            <span class="badge bg-danger ms-1">Cannot be undone</span>
                            <span class="d-block text-muted small mt-1">
                                Every table of the portal is deleted from this database (every table whose name begins with <code>tbl</code>), with everything in it, and so is every file people have uploaded. Backups already in the <code>_backups</code> folder are kept. <strong>To confirm, type the text shown below.</strong>
                            </span>
                        </label>
                        <div class="mt-2">
                            <label class="form-label small" for="drop_confirm">
                                To wipe, type
                                <code><?php echo htmlspecialchars($confirmExpected, ENT_QUOTES, 'UTF-8'); ?></code> here:
                            </label>
                            <input type="text" class="form-control form-control-sm"
                                   id="drop_confirm" name="drop_confirm" autocomplete="off">
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between">
                    <a href="?step=2" class="btn btn-outline-secondary">&larr; Back</a>
                    <button type="submit" class="btn btn-primary">Continue &rarr;</button>
                </div>
            </form>

<?php elseif ($installPage === 'tables'): ?>
            <!-- STEP 3: build the tables -->
            <?php
            // 🪪 Which database this page is about, and whether the choice
            //    HELD FOR IT is the wipe. Read through installChoiceHeld(),
            //    like the build step itself, so the page and the button can
            //    never disagree about what is going to happen.
            $tablesDb   = is_array($_SESSION['install_db'] ?? null) === true ? $_SESSION['install_db'] : [];
            $tablesWipe = (installChoiceHeld() === 'drop');
            $tablesName = '<code>' . htmlspecialchars((string) ($tablesDb['name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</code> on <code>'
                        . htmlspecialchars((string) ($tablesDb['host'] ?? ''), ENT_QUOTES, 'UTF-8') . '</code>';
            ?>
            <h2 class="h5 mb-3">Build the tables</h2>
            <p>The connection works. The button below builds the portal&rsquo;s tables in the database <?php echo $tablesName; ?> and fills in its starting settings.</p>

            <?php
            // 🛢️ What database did we actually connect to, and is it a version
            //    this portal supports? Worked out in step 2; see the file header
            //    in _install/db_server_banner.php.
            require INSTALL_ROOT
                . DIRECTORY_SEPARATOR . '_install'
                . DIRECTORY_SEPARATOR . 'db_server_banner.php';
            ?>

            <?php if ($tablesWipe === true): ?>
                <?php
                // ⚠️ THE WIPE IS SAID HERE, on the page whose button carries
                //    it out. This page used to say only "a table that is
                //    already there is kept", even when the next press was
                //    about to delete every one of them.
                ?>
                <div class="alert alert-warning" id="install-tables-wipe" role="alert">
                    <strong>This will WIPE the database <?php echo $tablesName; ?> first.</strong>
                    You chose &ldquo;Wipe it and start again from nothing&rdquo; for this database. Pressing the button deletes every table of the portal in it (every table whose name begins with <code>tbl</code>), with everything in those tables, and every file people have uploaded. It cannot be undone. Backups already in the <code>_backups</code> folder are kept.
                    <span class="d-block mt-2">If that is not the database you mean, or not what you mean to do, press Back: nothing has been removed yet.</span>
                </div>
            <?php endif; ?>

            <div class="alert alert-info">
                <strong>What will happen:</strong>
                <ul class="mb-0 mt-1">
                    <?php if ($tablesWipe === true): ?>
                        <li>First the portal&rsquo;s tables in this database are deleted, and so is every uploaded file.</li>
                        <li>Then every table the portal needs is created again, empty, and the starting settings and the list of the portal&rsquo;s pages are filled in.</li>
                    <?php else: ?>
                        <li>Every table the portal needs is created. A table that is already there is kept, with what is in it.</li>
                        <li>The starting settings and the list of the portal&rsquo;s pages are filled in. A setting that already has a value keeps it.</li>
                    <?php endif; ?>
                    <li>This can take a minute or two. Wait for the next page, and do not press the button twice.</li>
                </ul>
            </div>

            <form method="post">
                <?php echo installTokenField(); ?>
                <?php echo installDatabaseField(); ?>
                <input type="hidden" name="action" value="install_schema">
                <input type="hidden" name="step" value="3">
                <div class="d-flex justify-content-between">
                    <a href="?step=2" class="btn btn-outline-secondary">&larr; Back</a>
                    <button type="submit" class="btn btn-primary">Build the tables &rarr;</button>
                </div>
            </form>

<?php elseif ($installPage === 'administrator'): ?>
            <!-- STEP 4: the first administrator -->
            <h2 class="h5 mb-3">The first administrator</h2>
            <p>This makes the first account: yours. It is a global administrator, which means it can change everything in the portal and add everybody else. Its e-mail address is also the name it signs in with.</p>

            <?php if ($installAdministrators > 0): ?>
                <div class="alert alert-info" id="install-administrator-exists">
                    <strong>This database already has <?php echo $installAdministrators === 1 ? 'an administrator account' : (int) $installAdministrators . ' administrator accounts'; ?>,</strong>
                    made before now, most likely by an earlier run of this installer. If you know its password you do not need another.
                    <p class="mb-0 mt-2"><a href="?step=4.5" class="btn btn-sm btn-outline-secondary">Keep the administrator that is already there &rarr;</a></p>
                </div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <?php echo installTokenField(); ?>
                <input type="hidden" name="action" value="create_admin">
                <input type="hidden" name="step" value="4">

                <div class="mb-3">
                    <label for="admin_name" class="form-label">Your name</label>
                    <input type="text" class="form-control" id="admin_name" name="admin_name" maxlength="255"
                           value="<?php echo htmlspecialchars(installField('admin_name'), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="mb-3">
                    <label for="admin_email" class="form-label">Your e-mail address</label>
                    <input type="email" class="form-control" id="admin_email" name="admin_email" maxlength="50"
                           value="<?php echo htmlspecialchars(installField('admin_email'), ENT_QUOTES, 'UTF-8'); ?>" required>
                    <div class="form-text">You sign in with this address, so it can be 50 characters at most.</div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="admin_pass" class="form-label">Password</label>
                        <input type="password" class="form-control" id="admin_pass" name="admin_pass"
                               required minlength="12" maxlength="128" autocomplete="new-password"
                               data-portal-password-input>
                        <div class="portal-password-strength mt-2" data-portal-password-meter hidden>
                            <div class="progress" style="height:6px;">
                                <div class="progress-bar" role="progressbar" style="width:0%"></div>
                            </div>
                            <small class="form-text" data-portal-password-meter-label>Password strength</small>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="admin_pass2" class="form-label">The same password again</label>
                        <input type="password" class="form-control" id="admin_pass2" name="admin_pass2"
                               required minlength="12" maxlength="128" autocomplete="new-password">
                    </div>
                </div>
                <div class="alert alert-light border small mb-3">
                    <strong>The password needs all of these:</strong>
                    <ul class="mb-0 ps-3">
                        <li>At least 12 characters</li>
                        <li>At least one capital letter (A-Z)</li>
                        <li>At least one small letter (a-z)</li>
                        <li>At least one digit (0-9)</li>
                        <li>At least one other character, such as ! @ # $ % ^ &amp; *</li>
                    </ul>
                </div>

                <div class="d-flex justify-content-between">
                    <a href="?step=3" class="btn btn-outline-secondary">&larr; Back</a>
                    <button type="submit" class="btn btn-primary">Create the account &rarr;</button>
                </div>
            </form>

<?php elseif ($installPage === 'addresses'): ?>
            <!-- STEP 4.5: web addresses (#500) -->
            <h2 class="h5 mb-3">Web addresses</h2>
            <p>Two addresses the portal cannot work out for itself. Both can be changed later in Admin, Settings, Organisation.</p>

            <form method="post" autocomplete="off">
                <?php echo installTokenField(); ?>
                <input type="hidden" name="action" value="web_addresses">
                <input type="hidden" name="step" value="4.5">

                <div class="mb-3">
                    <label for="portal_address" class="form-label">The address of this portal</label>
                    <input type="text" inputmode="url" class="form-control" id="portal_address" name="portal_address"
                           maxlength="255" autocomplete="off" autocapitalize="none" spellcheck="false" required
                           value="<?php echo htmlspecialchars($installAddressOffered, ENT_QUOTES, 'UTF-8'); ?>" aria-describedby="portal_address_help">
                    <div class="form-text text-break" id="portal_address_help">
                        The address people type to reach this portal, with nothing after the name: for example <code>https://portal.example.org</code>. Every link in an e-mail the portal sends, such as a link to set a new password, begins with it. The installer has filled in the address you are using now. <strong>Check it,</strong> and make sure it begins with <code>https://</code> if your website has a security certificate.
                    </div>
                </div>

                <div class="mb-3">
                    <label for="support_email" class="form-label">Support e-mail address <span class="text-muted">(you may leave this empty)</span></label>
                    <input type="email" class="form-control" id="support_email" name="support_email" maxlength="254" autocomplete="off"
                           value="<?php echo htmlspecialchars($installSupportOffered, ENT_QUOTES, 'UTF-8'); ?>" aria-describedby="support_email_help">
                    <div class="form-text" id="support_email_help">
                        Where people should write when they need help. It is shown on the Help page &ldquo;Getting Support&rdquo; and at the foot of e-mails the portal sends. With none, the Help page tells people to ask an administrator.
                    </div>
                </div>

                <div class="d-flex justify-content-between">
                    <a href="?step=4" class="btn btn-outline-secondary">&larr; Back</a>
                    <button type="submit" class="btn btn-primary">Continue &rarr;</button>
                </div>
            </form>

<?php elseif ($installPage === 'finish'): ?>
            <!-- STEP 5: the finish -->
            <?php
            $finishDb = is_array($_SESSION['install_db'] ?? null) === true ? $_SESSION['install_db'] : [];
            ?>
            <h2 class="h5 mb-3">Finish</h2>
            <p>Everything is ready. This last step makes the installation permanent. Please read what it is about to set up.</p>

            <ul class="install-checks" id="install-summary">
                <li class="install-check">
                    <strong class="install-check-label">The database</strong>
                    <span class="install-check-value"><code><?php echo htmlspecialchars((string) ($finishDb['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code> on <code><?php echo htmlspecialchars((string) ($finishDb['host'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code>. If that is not the database you typed in, stop here and go back.</span>
                </li>
                <li class="install-check">
                    <strong class="install-check-label">The address of this portal</strong>
                    <span class="install-check-value"><code><?php echo htmlspecialchars($installAddressOffered, ENT_QUOTES, 'UTF-8'); ?></code></span>
                    <?php if (str_starts_with($installAddressOffered, 'http://') === true): ?>
                        <span class="install-check-fix">This begins with http, not https, so what people send to it is not encrypted. Use https for a real installation: go back and change it if your website has a security certificate.</span>
                    <?php endif; ?>
                </li>
                <li class="install-check">
                    <strong class="install-check-label">Support e-mail address</strong>
                    <span class="install-check-value"><?php echo $installSupportOffered === '' ? 'None' : '<code>' . htmlspecialchars($installSupportOffered, ENT_QUOTES, 'UTF-8') . '</code>'; ?></span>
                </li>
                <li class="install-check">
                    <strong class="install-check-label">Which copy of the portal this is</strong>
                    <span class="install-check-value">This is <?php echo htmlspecialchars($installChannelWords['copy'] . '. ' . $installChannelWords['how'], ENT_QUOTES, 'UTF-8'); ?></span>
                </li>
            </ul>

            <div class="alert alert-info">
                <strong>What pressing the button does:</strong>
                <ul class="mb-0 mt-1">
                    <li>Makes the folders <code>_uploads</code> and <code>_backups</code>, and puts a file in them and in <code>_auth_keys</code> that tells the web server to refuse every request for anything inside.</li>
                    <li>Writes the key file, <code>_auth_keys/enc.key</code>, which every password and key saved in Settings is encrypted with. A key file that is already there is kept.</li>
                    <li>Saves the two addresses above, and writes the database login into <code>_auth_keys/auth_creds.php</code>.</li>
                    <li>Locks the installer, so that it cannot be run again, and deletes the installation code.</li>
                </ul>
            </div>

            <form method="post" autocomplete="off">
                <?php echo installTokenField(); ?>
                <input type="hidden" name="action" value="finalize">
                <input type="hidden" name="step" value="5">
                <div class="mb-3">
                    <label for="owner_code" class="form-label">Your installation code, once more</label>
                    <input type="text" class="form-control install-code-input" id="owner_code" name="owner_code"
                           required maxlength="40" autocomplete="off" autocapitalize="characters" spellcheck="false"
                           placeholder="XXXX-XXXX-XXXX-XXXX" aria-describedby="owner_code_help">
                    <div class="form-text" id="owner_code_help">The same code as at the start, from the file <code>install-code.php</code> in the folder <code>_auth_keys</code>. It is asked for again because this is the step that makes the installation permanent.</div>
                </div>
                <div class="d-flex justify-content-between">
                    <a href="?step=4.5" class="btn btn-outline-secondary">&larr; Back</a>
                    <button type="submit" class="btn btn-success">Finish the installation</button>
                </div>
            </form>

<?php elseif ($installPage === 'done'): ?>
            <!-- DONE. Drawn once, in the answer to the finish itself. -->
            <?php
            $doneAddress = (string) ($finishReport['address'] ?? '');
            $doneSupport = (string) ($finishReport['support'] ?? '');
            ?>
            <h2 class="h5 mb-3 text-success">Installation complete</h2>
            <p><?php echo htmlspecialchars($INSTALL_PRODUCT_NAME, ENT_QUOTES, 'UTF-8'); ?> is installed and ready to use. <strong>This page is shown only once:</strong> read it now, and note the part about backing up the key file.</p>

            <ul class="install-checks" id="install-done">
                <li class="install-check" data-done="address">
                    <strong class="install-check-label">The address used in e-mails</strong>
                    <span class="install-check-value">Every link in an e-mail the portal sends will begin with <code><?php echo htmlspecialchars($doneAddress, ENT_QUOTES, 'UTF-8'); ?></code>.</span>
                    <?php if ($doneAddress !== (string) ($finishReport['addressTyped'] ?? '')): ?>
                        <span class="install-check-fix">That is not the address you typed (<code><?php echo htmlspecialchars((string) ($finishReport['addressTyped'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code>). This database already had an address saved, and it was kept. To change it, sign in and open Admin, Settings, Organisation.</span>
                    <?php endif; ?>
                    <?php if (str_starts_with($doneAddress, 'http://') === true): ?>
                        <span class="install-check-fix">It begins with http, not https, so what people send to it is not encrypted. Once your website has a security certificate, change it in Admin, Settings, Organisation.</span>
                    <?php endif; ?>
                </li>
                <li class="install-check" data-done="support">
                    <strong class="install-check-label">Support e-mail address</strong>
                    <span class="install-check-value"><?php echo $doneSupport === '' ? 'None is set. The Help page tells people to ask an administrator.' : '<code>' . htmlspecialchars($doneSupport, ENT_QUOTES, 'UTF-8') . '</code>'; ?></span>
                </li>
                <li class="install-check" data-done="channel" data-channel-source="<?php echo htmlspecialchars((string) $installChannel['source'], ENT_QUOTES, 'UTF-8'); ?>">
                    <strong class="install-check-label">Which copy of the portal this is</strong>
                    <span class="install-check-value">This is <?php echo htmlspecialchars($installChannelWords['copy'] . '. ' . $installChannelWords['how'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php $doneChannelFile = installChannelFileWords(is_array($finishReport['channelFile'] ?? null) === true ? $finishReport['channelFile'] : ['state' => 'kept', 'word' => '']); ?>
                    <?php if ($doneChannelFile !== ''): ?>
                        <span class="install-check-fix" data-channel-file="<?php echo htmlspecialchars((string) $finishReport['channelFile']['state'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($doneChannelFile, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                    <?php foreach ($installChannelWords['advice'] as $advice): ?>
                        <span class="install-check-fix"><?php echo htmlspecialchars((string) $advice, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endforeach; ?>
                    <?php if ($installChannel['channel'] !== 'prod'): ?>
                        <span class="install-check-fix">Because this is a test copy, the staff sign-in gate stands in front of it: every page asks for a staff sign-in first, unless that gate has been switched off in Settings. The account you have just made is staff.</span>
                    <?php endif; ?>
                </li>
            </ul>

            <div class="alert alert-warning" id="install-done-key">
                <strong>Back up the key file together with the database.</strong>
                The file <code>enc.key</code>, in the folder <code>_auth_keys</code>, is what every password and key saved in the portal&rsquo;s Settings is encrypted with<?php echo ($finishReport['keyWasThere'] ?? false) === true ? ' (a key file was already there, and it was kept)' : ''; ?>. A backup of the database is not complete without it: restored without the same key file, none of those saved secrets can be read, and each has to be typed in again. Keep a copy of the whole <code>_auth_keys</code> folder somewhere safe and private, and keep it with every backup of the database.
            </div>

            <?php
            // 🔒 What is said about the three private folders is what the
            //    finish FOUND, folder by folder. It used to say that each
            //    "holds a file that tells the web server to refuse every
            //    request" whatever was in them, which was untrue for a
            //    folder holding a rules file of somebody's own that does not
            //    refuse (the installer leaves such a file alone).
            $doneOwnRules = array_values(array_filter(($finishReport['folders']['own'] ?? []), 'is_string'));
            $doneRefusing = array_values(array_diff(INSTALL_PRIVATE_FOLDERS, $doneOwnRules));
            $doneNames    = static function (array $names): string {
                $names = array_map(static fn (string $name): string => '<code>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</code>', $names);
                $last  = array_pop($names);

                return $names === [] ? (string) $last : implode(', ', $names) . ' and ' . $last;
            };
            ?>
            <div class="alert <?php echo $doneOwnRules === [] ? 'alert-success' : 'alert-warning'; ?>" id="install-done-private">
                <strong>The private folders.</strong>
                <?php if ($doneRefusing !== []): ?>
                    <?php echo $doneNames($doneRefusing); ?> <?php echo count($doneRefusing) === 1 ? 'holds' : 'each hold'; ?> a file that tells the web server to refuse every request for anything inside. That is a second lock, not the first: it works on Apache web servers only.
                <?php endif; ?>
                <?php if ($doneOwnRules !== []): ?>
                    <span id="install-done-own-rules"><?php echo $doneNames($doneOwnRules); ?> <?php echo count($doneOwnRules) === 1 ? 'holds a rules file (<code>.htaccess</code>) of its own, which the installer left as it is. It does' : 'each hold a rules file (<code>.htaccess</code>) of their own, which the installer left as they are. They do'; ?> not hold the line <code><?php echo htmlspecialchars(INSTALL_REFUSE_RULE, ENT_QUOTES, 'UTF-8'); ?></code>, so the installer cannot say that requests are refused there. If you did not write that file, delete it in the file manager and open Admin, Upgrade: the portal then writes its own.</span>
                <?php endif; ?>
                What really keeps these folders private is that your website&rsquo;s address points at the web folder and not at the folder above it.
                <?php if (($finishReport['codeRemoved'] ?? false) === true): ?>
                    The installation code has been deleted.
                <?php else: ?>
                    <span id="install-done-code-left">The file that held the installation code, <code>install-code.php</code> in the folder <code>_auth_keys</code>, could not be deleted. Its code opens nothing any more, because the installer is locked. Delete the file yourself in the file manager.</span>
                <?php endif; ?>
            </div>

            <?php if (($finishReport['versionRecorded'] ?? false) === false): ?>
                <div class="alert alert-warning" id="install-done-version">
                    <strong>One thing could not be recorded:</strong> which version the database is at. The portal works without it. If the portal later says an upgrade is waiting, sign in as the administrator and open Admin, Upgrade.
                </div>
            <?php endif; ?>

            <div class="alert alert-info">
                <strong>What to do next:</strong>
                <ol class="mb-0 mt-1">
                    <li>Sign in with the e-mail address and password of the administrator account.</li>
                    <?php // The time zone is set on the Sites page and nowhere else since #604: this line used to send people to Admin, Settings for it. ?>
                    <li>In Admin, Sites, press Edit and set your organisation&rsquo;s name and time zone. In Admin, Settings, set the name shown on the sign-in page, and the artwork.</li>
                    <li>In Admin, Integrations, Email, tell the portal how to send e-mail, and send yourself a test.</li>
                    <li>Add the people who will use the portal.</li>
                </ol>
            </div>

            <div class="text-center mt-4">
                <a href="/" class="btn btn-primary btn-lg">Go to the sign-in page &rarr;</a>
            </div>

<?php endif; ?>

        </div>
    </div>

    <!-- Footer -->
    <div class="install-footer">
        <?php echo htmlspecialchars($INSTALL_PRODUCT_NAME, ENT_QUOTES, 'UTF-8'); ?><?php if ($ownerPassed === true || $justFinished === true): ?> v<?php echo htmlspecialchars(INSTALL_VERSION, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?> &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($INSTALL_PRODUCT_PUBLISHER, ENT_QUOTES, 'UTF-8'); ?>
    </div>

</div>

<script>
// Theme + CB toggle wiring for the installer (standalone — doesn't load portal.js)
(function(){
    var html = document.documentElement;
    var themeBtn = document.getElementById('installThemeToggle');
    var cbBtn    = document.getElementById('installCbToggle');
    var mql      = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function savedTheme() {
        var v = localStorage.getItem('portal-theme');
        return (v === 'light' || v === 'dark' || v === 'auto') ? v : 'auto';
    }
    function applyTheme(pref) {
        if (pref === 'auto') {
            html.setAttribute('data-bs-theme', (mql && mql.matches) ? 'dark' : 'light');
        } else {
            html.setAttribute('data-bs-theme', pref);
        }
        updateThemeBtn(pref);
    }
    function updateThemeBtn(pref) {
        if (!themeBtn) return;
        var titles = {
            light: 'Theme: light — click for dark',
            dark:  'Theme: dark — click for auto',
            auto:  'Theme: auto (system) — click for light'
        };
        themeBtn.setAttribute('title', titles[pref] || titles.auto);
    }
    function savedCb() { return localStorage.getItem('portal-cb') === 'on'; }
    function applyCb(on) {
        if (on) {
            html.setAttribute('data-portal-cb', 'on');
        } else {
            html.removeAttribute('data-portal-cb');
        }
        if (cbBtn) {
            cbBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            cbBtn.setAttribute('title', on
                ? 'Colour-blind safe palette: on — click to turn off'
                : 'Colour-blind safe palette: off — click to turn on');
        }
    }

    if (themeBtn) {
        themeBtn.addEventListener('click', function(){
            var cur = savedTheme();
            var next = (cur === 'light') ? 'dark' : (cur === 'dark' ? 'auto' : 'light');
            localStorage.setItem('portal-theme', next);
            applyTheme(next);
        });
    }
    if (cbBtn) {
        cbBtn.addEventListener('click', function(){
            var next = !savedCb();
            if (next) { localStorage.setItem('portal-cb', 'on'); }
            else      { localStorage.removeItem('portal-cb'); }
            applyCb(next);
        });
    }
    if (mql && typeof mql.addEventListener === 'function') {
        mql.addEventListener('change', function(){
            if (savedTheme() === 'auto') applyTheme('auto');
        });
    }

    // Initial state — FOUC script already applied the data-* attrs; just sync the icons/titles.
    updateThemeBtn(savedTheme());
    applyCb(savedCb());
})();
</script>

<script>
// Password strength meter for the installer (standalone — doesn't load portal.js)
(function () {
    function score(value, minLength) {
        if (!value) { return 0; }
        var s = 0;
        if (value.length >= minLength)  { s += 1; }
        if (/[a-z]/.test(value))        { s += 1; }
        if (/[A-Z]/.test(value))        { s += 1; }
        if (/[0-9]/.test(value))        { s += 1; }
        if (/[^a-zA-Z0-9]/.test(value)) { s += 1; }
        return s;
    }
    function labelFor(s) {
        if (s <= 1)  { return ['bg-danger',  'Very weak']; }
        if (s === 2) { return ['bg-danger',  'Weak']; }
        if (s === 3) { return ['bg-warning', 'Fair']; }
        if (s === 4) { return ['bg-info',    'Strong']; }
        return ['bg-success', 'Very strong'];
    }
    document.querySelectorAll('input[data-portal-password-input]').forEach(function (input) {
        var scope = input.closest('.col-md-6, .mb-3, form') || input.parentNode;
        var meter = scope ? scope.querySelector('[data-portal-password-meter]') : null;
        if (!meter) { return; }
        var bar = meter.querySelector('.progress-bar');
        var lbl = meter.querySelector('[data-portal-password-meter-label]');
        if (!bar) { return; }
        var minLength = parseInt(input.getAttribute('minlength'), 10);
        if (!minLength || minLength < 1) { minLength = 12; }
        input.addEventListener('input', function () {
            var v = input.value || '';
            if (v === '') {
                meter.hidden = true;
                bar.style.width = '0%';
                bar.className = 'progress-bar';
                if (lbl) { lbl.textContent = 'Password strength'; }
                return;
            }
            meter.hidden = false;
            var sc = score(v, minLength);
            var pct = Math.round((sc / 5) * 100);
            var sl = labelFor(sc);
            bar.style.width = pct + '%';
            bar.className = 'progress-bar ' + sl[0];
            bar.setAttribute('aria-valuenow', String(pct));
            if (lbl) { lbl.textContent = 'Password strength: ' + sl[1]; }
        });
    });
})();
</script>

</body>
</html>
