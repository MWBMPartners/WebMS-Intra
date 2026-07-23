<?php
// Path: _apps/cron/discipleship-sweep.php
/**
 * -----------------------------------------------------------------------------
 * Cron — Discipleship auto-completion sweep 🧭 (#303 Phase 2)
 * -----------------------------------------------------------------------------
 * Optional endpoint for freshness without any page views — the auto-sweep
 * already runs lazily on every discipleship page view
 * (`Discipleship::autoSweep()`), so this cron is a convenience, not a
 * dependency. Cloned from `cron/event-reminders.php`'s gate: authenticates
 * via `?key=<discipleship.cron_token>` (constant-time compare), 403s when
 * the stored token is empty (same semantics as `reminders.cron_token`).
 *
 * Loops every distinct site that owns at least one active pathway and
 * runs one site-wide `Discipleship::autoSweep()` per site, printing plain
 * text counts.
 *
 * @package   Portal\App\Cron
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/303
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Discipleship;
use Portal\Core\Settings;

// 🔑 Token gate (constant-time compare) — mirrors reminders.cron_token.
$incoming = (string) ($_GET['key'] ?? '');
$expected = (string) (Settings::get('discipleship.cron_token', '') ?? '');
if ($expected === '' || hash_equals($expected, $incoming) === false) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

if (Discipleship::isEnabled() === false) {
    echo 'Discipleship disabled';
    exit();
}

$db = App::db();

// 🌍 Every distinct site that owns at least one active pathway.
$siteIds = [];
$stmt = $db->prepare('SELECT DISTINCT siteID FROM tblPathways WHERE isActive = 1');
if ($stmt !== false) {
    $stmt->execute();
    $result = $stmt->get_result();
    while (($row = $result->fetch_assoc()) !== null) {
        $siteIds[] = (int) $row['siteID'];
    }
    $stmt->close();
}

$stats = [];
foreach ($siteIds as $siteId) {
    $stats[$siteId] = Discipleship::autoSweep($siteId);
}

echo 'OK ' . json_encode(['sitesSwept' => count($siteIds), 'inserted' => $stats]);
