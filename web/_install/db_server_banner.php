<?php
// Path: _install/db_server_banner.php
/**
 * -----------------------------------------------------------------------------
 * Installation wizard — "here is the database you are installing onto" 🛢️
 * -----------------------------------------------------------------------------
 * Draws one panel telling the person installing the portal exactly which
 * database product and version their server is running, and whether that is a
 * version this portal supports.
 *
 * The answer is worked out once, in step 2, at the moment the wizard first
 * manages to connect — that is the earliest point it can be known, because
 * before then nobody has typed any database details in. The result is kept in
 * the session so the screens after it can show it without connecting again.
 *
 * A database too old to run this portal never gets this far: step 2 refuses and
 * explains why. So anything this panel shows is, by definition, something the
 * install can proceed on. It is information, not a gate.
 *
 * This file is included from _install/index.php only. Like db_state.php it
 * depends on nothing but the session, so it works inside the wizard, which runs
 * before the portal's normal start-up code.
 *
 * @package   Portal\Install
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/475
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🚪 Nothing to draw if step 2 has not run yet — someone who typed a step
//    number straight into the address bar, for example.
$dbServerInfo = $_SESSION['install_db_server'] ?? null;

if (is_array($dbServerInfo) === false) {
    return;
}

$dbServerState = (string) ($dbServerInfo['state'] ?? 'warn');

// 🎨 Green for a version that is fully supported, amber for one that works but
//    is worth knowing about. 'crit' cannot reach this file — step 2 stops
//    first — but it is mapped anyway so a future change cannot make this file
//    silently pick the wrong colour.
$dbServerClass = 'info';
if ($dbServerState === 'ok') {
    $dbServerClass = 'success';
} elseif ($dbServerState === 'warn') {
    $dbServerClass = 'warning';
} elseif ($dbServerState === 'crit') {
    $dbServerClass = 'danger';
}
?>
<div class="alert alert-<?php echo $dbServerClass; ?>">
    <strong><?php echo htmlspecialchars((string) ($dbServerInfo['headline'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
    <p class="mb-1 mt-1 small"><?php echo htmlspecialchars((string) ($dbServerInfo['detail'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
    <p class="mb-0 small text-muted">
        Your database server reports itself as
        <code><?php echo htmlspecialchars((string) ($dbServerInfo['raw'] ?? 'unknown'), ENT_QUOTES, 'UTF-8'); ?></code>.
        You can see this again at any time after installing, under
        Admin &rarr; Server Information.
    </p>
</div>
