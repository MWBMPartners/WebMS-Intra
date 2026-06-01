<?php
// Path: public_html/admin/settings/sabbath/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Sabbath quiet hours dedicated settings page 🕯️
 * -----------------------------------------------------------------------------
 * Friendlier UI for the 8 portal.sabbath.* settings shipped with #231.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/251
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Sabbath;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$flash = '';
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf($_POST['csrf_token'] ?? '') === true) {
    $db = App::db();
    $values = [
        'portal.sabbath.enabled'              => isset($_POST['enabled']) ? '1' : '0',
        'portal.sabbath.method'               => in_array($_POST['method'] ?? 'fixed', ['fixed','sunset_calc'], true) ? (string) $_POST['method'] : 'fixed',
        'portal.sabbath.timezone'             => trim((string) ($_POST['timezone'] ?? 'Europe/London')),
        'portal.sabbath.location_lat'         => (string) ((float) ($_POST['location_lat'] ?? 0)),
        'portal.sabbath.location_lng'         => (string) ((float) ($_POST['location_lng'] ?? 0)),
        'portal.sabbath.start_offset_minutes' => (string) ((int) ($_POST['start_offset_minutes'] ?? 0)),
        'portal.sabbath.end_offset_minutes'   => (string) ((int) ($_POST['end_offset_minutes'] ?? 0)),
        'portal.sabbath.bypass_critical'      => isset($_POST['bypass_critical']) ? '1' : '0',
    ];
    // Validate timezone string against known list
    try {
        new \DateTimeZone($values['portal.sabbath.timezone']);
    } catch (\Throwable $e) {
        $values['portal.sabbath.timezone'] = 'Europe/London';
    }
    try {
        foreach ($values as $key => $val) {
            $stmt = $db->prepare(
                "INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) "
                . "VALUES (NULL, ?, ?, ?, 0) "
                . "ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue)"
            );
            if ($stmt !== false) {
                $stmt->bind_param('sss', $key, $val, $val);
                $stmt->execute();
                $stmt->close();
            }
        }
        $flash = 'Sabbath settings saved.';
        $flashType = 'success';
    } catch (\Throwable $e) {
        $flash = 'Save failed: ' . $e->getMessage();
        $flashType = 'danger';
    }
}

$settings = App::settings();
$enabled       = (string) ($settings['portal']['sabbath']['enabled']              ?? '0') === '1';
$method        = (string) ($settings['portal']['sabbath']['method']               ?? 'fixed');
$timezone      = (string) ($settings['portal']['sabbath']['timezone']             ?? 'Europe/London');
$lat           = (float)  ($settings['portal']['sabbath']['location_lat']         ?? 52.205);
$lng           = (float)  ($settings['portal']['sabbath']['location_lng']         ?? 0.119);
$startOffset   = (int)    ($settings['portal']['sabbath']['start_offset_minutes'] ?? 0);
$endOffset     = (int)    ($settings['portal']['sabbath']['end_offset_minutes']   ?? 0);
$bypassCritical = (string) ($settings['portal']['sabbath']['bypass_critical']     ?? '1') === '1';

// 🪞 Preview: current/next window
[$winStart, $winEnd] = Sabbath::computeWindow(time());

$pageTitle   = 'Sabbath quiet hours';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Settings' => '/admin/settings', 'Sabbath' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();

// All IANA timezones the system knows about
$allTimezones = \DateTimeZone::listIdentifiers();
?>

<h1 class="mb-3"><i class="fa-solid fa-moon me-2"></i>Sabbath quiet hours</h1>
<p class="text-muted">Defer non-urgent emails / notifications between Friday and Saturday windows. Critical alerts can optionally bypass.</p>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php if ($enabled === true): ?>
    <div class="alert alert-info">
        <strong>Current window (your settings):</strong>
        <?php echo htmlspecialchars(date('D j M H:i', $winStart), ENT_QUOTES, 'UTF-8'); ?>
        → <?php echo htmlspecialchars(date('D j M H:i', $winEnd), ENT_QUOTES, 'UTF-8'); ?>
        (<?php echo htmlspecialchars($timezone, ENT_QUOTES, 'UTF-8'); ?>)
    </div>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

    <div class="card mb-3">
        <div class="card-body">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="enabled" name="enabled" <?php echo $enabled === true ? 'checked' : ''; ?>>
                <label class="form-check-label" for="enabled"><strong>Enable Sabbath quiet hours</strong></label>
            </div>
            <p class="form-text">When enabled, non-critical email notifications scheduled during the window are deferred until after.</p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Window calculation</h2>
            <div class="form-check">
                <input class="form-check-input" type="radio" id="m_fixed" name="method" value="fixed" <?php echo $method === 'fixed' ? 'checked' : ''; ?>>
                <label class="form-check-label" for="m_fixed">Fixed local time (Friday 18:00 → Saturday 18:00)</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" id="m_sunset" name="method" value="sunset_calc" <?php echo $method === 'sunset_calc' ? 'checked' : ''; ?>>
                <label class="form-check-label" for="m_sunset">Sunset calculation from latitude / longitude</label>
            </div>

            <div class="row g-2 mt-3">
                <div class="col-md-6">
                    <label class="form-label small">Timezone</label>
                    <select name="timezone" class="form-select form-select-sm">
                        <?php foreach ($allTimezones as $tz): ?>
                            <option value="<?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $tz === $timezone ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Latitude</label>
                    <input type="number" step="0.0001" name="location_lat" class="form-control form-control-sm" value="<?php echo htmlspecialchars((string) $lat, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Longitude</label>
                    <input type="number" step="0.0001" name="location_lng" class="form-control form-control-sm" value="<?php echo htmlspecialchars((string) $lng, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Start offset (mins)</label>
                    <input type="number" name="start_offset_minutes" class="form-control form-control-sm" value="<?php echo (int) $startOffset; ?>">
                    <div class="form-text">Negative = earlier than sunset</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">End offset (mins)</label>
                    <input type="number" name="end_offset_minutes" class="form-control form-control-sm" value="<?php echo (int) $endOffset; ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="bypass" name="bypass_critical" <?php echo $bypassCritical === true ? 'checked' : ''; ?>>
                <label class="form-check-label" for="bypass"><strong>Critical alerts bypass quiet hours</strong></label>
            </div>
            <p class="form-text">When on, Critical/Fatal severity alerts (from #229) still fire during the window. Non-critical alerts always defer.</p>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">Save</button>
    <a href="/admin/settings" class="btn btn-outline-secondary">Cancel</a>
</form>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
