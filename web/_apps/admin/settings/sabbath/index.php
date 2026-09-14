<?php
// Path: public_html/admin/settings/sabbath/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Sabbath quiet hours dedicated settings page 🕯️
 * -----------------------------------------------------------------------------
 * Friendlier UI for the 8 portal.sabbath.* settings shipped with #231.
 *
 * WHO MAY SAVE HERE, AND WHY IT IS NARROWER THAN WHO MAY LOOK
 * -----------------------------------------------------------------------------
 * The form on this page saves portal-wide rows (the INSERT below puts NULL in
 * the siteID column), so the quiet-hours window applies to EVERY organisation
 * on the installation.
 *
 * Until 13 September 2026 the only check was App::isAdmin(), which is true for
 * an administrator of a SINGLE organisation as well as for a global
 * administrator (see web/_core/App.php). So an administrator of one
 * organisation could switch quiet hours on or off, or move the window, for
 * every organisation — holding back, or releasing, everybody's non-urgent
 * email. The owner decided that settings affecting every organisation are for
 * a global administrator only.
 *
 * An administrator of one organisation can still open the page and see the
 * current window. They see the form read-only: the reason on the page, every
 * field disabled and no Save button. That is a courtesy only. The refusal in
 * the handler below is what enforces the rule, because a form can be sent
 * without ever opening the page.
 *
 * Probably the wrong way round, and deliberately left alone. A window worked
 * out from a timezone, a latitude and a longitude describes where ONE
 * organisation meets, and web/_core/Sabbath.php itself calls these settings
 * the "org default". The settings loader (web/_core/bootstrap.php) already
 * lets an organisation's own row override the portal-wide one. But saving per
 * organisation from this page would change what every existing installation
 * does, so it needs its own decision. Reserving the portal-wide save for a
 * global administrator is safe either way.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/251
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/495
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Sabbath;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🛡️ Looking is allowed for any administrator; saving is not. See the note in
//    the file header for why. Worked out once and used by both the handler and
//    the form below, so the two can never disagree.
$mayChangePortalWideSettings = App::isRootAdmin();

$flash = '';
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🔑 The form token is checked exactly ONCE per request, and only then is
    //    the person's permission looked at.
    //
    //    WHAT WAS WRONG: the first version of this handler asked
    //    Auth::verifyCsrf() in the refusal branch AND again in the save branch.
    //    A successful check replaces the token with a new one (see
    //    Auth::verifyCsrf() in web/_core/Auth.php — it does that so a captured
    //    form cannot be replayed). So for a global administrator the first
    //    check passed and used the token up, and the second check then compared
    //    the form against a token that no longer existed and failed. The save
    //    never ran, nothing was written and no message appeared: Save looked as
    //    if it worked and did nothing.
    //
    //    Asking once and keeping the answer in a variable cannot go wrong that
    //    way, however the branches below are rearranged later.
    $tokenIsValid = Auth::verifyCsrf($_POST['csrf_token'] ?? '');

    if ($tokenIsValid === false) {
        // 🚫 Missing, wrong or expired token (for example the page was left
        //    open for a long time, or the request came from another website).
        //    This used to be ignored in silence, which reads as "Save is
        //    broken". Nothing is written and nothing is logged, so a forged
        //    request from another website cannot fill the activity log.
        $flash = 'This form had expired or was not sent from this page, so nothing '
            . 'has been changed. Please try again.';
        $flashType = 'danger';
    } elseif ($mayChangePortalWideSettings === false) {
        // 🚧 Refused BEFORE anything is written: every key on this page is
        //    portal-wide, so this one check covers the whole form. The refusal
        //    is worded rather than a bare "forbidden", because an administrator
        //    who presses Save and is told nothing assumes the portal is broken
        //    and tries again. It sits after the form-token check, so a forged
        //    request from another website cannot fill the activity log with
        //    refusals.
        $flash = 'These settings are portal-wide: they apply to every '
            . 'organisation on this installation, not only yours. Only a global '
            . 'administrator can change them. Nothing has been changed.';
        $flashType = 'danger';
        Logger::activity(
            'SettingsGroupSaveRefused',
            'Refused: portal-wide settings group "sabbath" may only be changed by a global administrator',
            $_SESSION['user_id'] ?? null
        );
    } else {
        // 💾 Only a global administrator with a valid token reaches this point.
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

<?php
// 🔒 Added to every control when the person looking may not save. This is a
//    COURTESY, not the control: a disabled field is only a hint to the browser
//    and anybody can send the form anyway. The refusal in the save handler at
//    the top of this file is what actually enforces the rule.
$readOnlyAttr = $mayChangePortalWideSettings === false ? ' disabled' : '';
?>

<?php if ($mayChangePortalWideSettings === false): ?>
    <!-- 👀 Read-only notice. Shown instead of letting somebody fill the form in
         and only find out it was refused after they pressed Save. -->
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-2"></i>
        These settings apply to <strong>every organisation</strong> on this
        installation, not only yours, so only a global administrator can change
        them. You can see the current values here.
    </div>
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
                <input class="form-check-input" type="checkbox" id="enabled" name="enabled" <?php echo $enabled === true ? 'checked' : ''; ?><?php echo $readOnlyAttr; ?>>
                <label class="form-check-label" for="enabled"><strong>Enable Sabbath quiet hours</strong></label>
            </div>
            <p class="form-text">When enabled, non-critical email notifications scheduled during the window are deferred until after.</p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Window calculation</h2>
            <div class="form-check">
                <input class="form-check-input" type="radio" id="m_fixed" name="method" value="fixed" <?php echo $method === 'fixed' ? 'checked' : ''; ?><?php echo $readOnlyAttr; ?>>
                <label class="form-check-label" for="m_fixed">Fixed local time (Friday 18:00 → Saturday 18:00)</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" id="m_sunset" name="method" value="sunset_calc" <?php echo $method === 'sunset_calc' ? 'checked' : ''; ?><?php echo $readOnlyAttr; ?>>
                <label class="form-check-label" for="m_sunset">Sunset calculation from latitude / longitude</label>
            </div>

            <div class="row g-2 mt-3">
                <div class="col-md-6">
                    <label class="form-label small">Timezone</label>
                    <select name="timezone" class="form-select form-select-sm"<?php echo $readOnlyAttr; ?>>
                        <?php foreach ($allTimezones as $tz): ?>
                            <option value="<?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $tz === $timezone ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Latitude</label>
                    <input type="number" step="0.0001" name="location_lat" class="form-control form-control-sm" value="<?php echo htmlspecialchars((string) $lat, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $readOnlyAttr; ?>>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Longitude</label>
                    <input type="number" step="0.0001" name="location_lng" class="form-control form-control-sm" value="<?php echo htmlspecialchars((string) $lng, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $readOnlyAttr; ?>>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Start offset (mins)</label>
                    <input type="number" name="start_offset_minutes" class="form-control form-control-sm" value="<?php echo (int) $startOffset; ?>"<?php echo $readOnlyAttr; ?>>
                    <div class="form-text">Negative = earlier than sunset</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">End offset (mins)</label>
                    <input type="number" name="end_offset_minutes" class="form-control form-control-sm" value="<?php echo (int) $endOffset; ?>"<?php echo $readOnlyAttr; ?>>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="bypass" name="bypass_critical" <?php echo $bypassCritical === true ? 'checked' : ''; ?><?php echo $readOnlyAttr; ?>>
                <label class="form-check-label" for="bypass"><strong>Critical alerts bypass quiet hours</strong></label>
            </div>
            <p class="form-text">When on, Critical/Fatal severity alerts (from #229) still fire during the window. Non-critical alerts always defer.</p>
        </div>
    </div>

    <?php if ($mayChangePortalWideSettings === true): ?>
        <button type="submit" class="btn btn-primary">Save</button>
    <?php endif; ?>
    <a href="/admin/settings" class="btn btn-outline-secondary">Cancel</a>
</form>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
