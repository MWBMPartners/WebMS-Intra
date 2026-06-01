<?php
// Path: public_html/admin/settings/group.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Settings group sub-pages 🎛️
 * -----------------------------------------------------------------------------
 * Definition-driven, friendly UI for the grouped portal.* setting families
 * (alerts / backups / headers / upgrade / maintenance). One controller,
 * five routes — the group is derived from the matched route path.
 *
 * Each field has a type (toggle / number / text / textarea / select), label,
 * help text, and (for select) options. Renders proper inputs instead of the
 * generic dot-notation editor, with inline explanations and validation.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/252
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// -----------------------------------------------------------------------------
// 🗂️ Group definitions. Each field: key (full dot-notation setting key),
//    type, label, help, and options (for select).
// -----------------------------------------------------------------------------
$groups = [
    'alerts' => [
        'title' => 'Critical-error alerting',
        'icon'  => 'fa-solid fa-bell',
        'intro' => 'Email alerts when the portal logs a Critical or Fatal error. Rate-limited per fingerprint so a runaway error does not spam you.',
        'fields' => [
            'portal.alerts.recipients'       => ['type' => 'textarea', 'label' => 'Recipient emails', 'help' => 'Comma-separated. Leave empty to disable alerting.'],
            'portal.alerts.severities'       => ['type' => 'text', 'label' => 'Severities to alert on', 'help' => 'Comma-separated. Default: Critical,Fatal'],
            'portal.alerts.cooldown_minutes' => ['type' => 'number', 'label' => 'Cooldown (minutes)', 'help' => 'Minimum gap between alerts for the same error fingerprint.', 'min' => 0],
        ],
    ],
    'backups' => [
        'title' => 'Backups',
        'icon'  => 'fa-solid fa-box-archive',
        'intro' => 'JSON snapshot retention + freshness alerting. The backup-freshness cron emails when the most recent snapshot is older than the threshold.',
        'fields' => [
            'portal.backups.max_age_hours'    => ['type' => 'number', 'label' => 'Max backup age (hours)', 'help' => 'Alert if no successful backup within this window. Default 36.', 'min' => 1],
            'portal.backups.alert_recipients' => ['type' => 'textarea', 'label' => 'Alert recipients', 'help' => 'Comma-separated emails for stale-backup alerts.'],
        ],
    ],
    'headers' => [
        'title' => 'Security headers',
        'icon'  => 'fa-solid fa-shield-halved',
        'intro' => 'HTTP response headers sent on every request. Defaults match the Mozilla Observatory baseline. Set a value empty to suppress that header.',
        'fields' => [
            'portal.headers.strict_transport_security' => ['type' => 'text', 'label' => 'Strict-Transport-Security', 'help' => 'HSTS. Only sent over HTTPS. Default: max-age=31536000; includeSubDomains'],
            'portal.headers.permissions_policy'        => ['type' => 'textarea', 'label' => 'Permissions-Policy', 'help' => 'Disables browser features the portal does not use.'],
            'portal.headers.coop'                      => ['type' => 'text', 'label' => 'Cross-Origin-Opener-Policy', 'help' => 'Default: same-origin'],
            'portal.headers.corp'                      => ['type' => 'text', 'label' => 'Cross-Origin-Resource-Policy', 'help' => 'Default: same-origin'],
            'portal.headers.referrer_policy'           => ['type' => 'text', 'label' => 'Referrer-Policy', 'help' => 'Default: strict-origin-when-cross-origin'],
            'portal.headers.x_frame_options'           => ['type' => 'select', 'label' => 'X-Frame-Options', 'help' => 'Clickjacking protection.', 'options' => ['SAMEORIGIN' => 'SAMEORIGIN', 'DENY' => 'DENY', '' => '(suppress)']],
        ],
    ],
    'upgrade' => [
        'title' => 'Upgrade policy',
        'icon'  => 'fa-solid fa-arrow-up-right-dots',
        'intro' => 'Controls the installer/upgrade gate and the auto-backup before migrations.',
        'fields' => [
            'portal.upgrade.fresh_required_below'     => ['type' => 'text', 'label' => 'Fresh-install required below version', 'help' => 'Installs older than this must drop-and-rebuild. Leave empty to always allow in-place upgrade.'],
            'portal.upgrade.require_hostname_confirm' => ['type' => 'toggle', 'label' => 'Require hostname confirmation for drop-and-rebuild', 'help' => 'Admin must type the portal hostname before a destructive rebuild.'],
        ],
    ],
    'maintenance' => [
        'title' => 'Maintenance mode',
        'icon'  => 'fa-solid fa-screwdriver-wrench',
        'intro' => 'The public-access gate shown during upgrades. Admins always bypass it.',
        'fields' => [
            'portal.maintenance.active'  => ['type' => 'toggle', 'label' => 'Maintenance mode active', 'help' => 'When on, non-admins see the maintenance page. Normally toggled automatically during upgrades.'],
            'portal.maintenance.message' => ['type' => 'textarea', 'label' => 'Custom message', 'help' => 'Optional message shown on the maintenance page.'],
        ],
    ],
];

// -----------------------------------------------------------------------------
// 🚏 Determine the group from the matched route path.
// -----------------------------------------------------------------------------
$path = Router::extractPath(); // e.g. "admin/settings/headers"
$parts = explode('/', trim($path, '/'));
$groupKey = end($parts);
if (isset($groups[$groupKey]) === false) {
    http_response_code(404);
    exit('Unknown settings group');
}
$group = $groups[$groupKey];

// -----------------------------------------------------------------------------
// 💾 Save handler.
// -----------------------------------------------------------------------------
$flash = '';
$flashType = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf($_POST['csrf_token'] ?? '') === true) {
    $db = App::db();
    try {
        foreach ($group['fields'] as $key => $def) {
            // Field name in the form is the key with dots → underscores.
            $formName = str_replace('.', '_', $key);
            if ($def['type'] === 'toggle') {
                $value = isset($_POST[$formName]) ? '1' : '0';
            } else {
                $value = trim((string) ($_POST[$formName] ?? ''));
                if ($def['type'] === 'number') {
                    $value = (string) ((int) $value);
                }
                if ($def['type'] === 'select' && isset($def['options'][$value]) === false) {
                    // Reject values not in the option set.
                    $value = (string) array_key_first($def['options']);
                }
            }
            $stmt = $db->prepare(
                'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) '
                . 'VALUES (NULL, ?, ?, ?, 0) '
                . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue)'
            );
            if ($stmt !== false) {
                $stmt->bind_param('sss', $key, $value, $value);
                $stmt->execute();
                $stmt->close();
            }
        }
        $flash = $group['title'] . ' settings saved.';
        $flashType = 'success';
    } catch (\Throwable $e) {
        $flash = 'Save failed: ' . $e->getMessage();
        $flashType = 'danger';
    }
}

// -----------------------------------------------------------------------------
// 📖 Read current values via dot-notation traversal of App::settings().
// -----------------------------------------------------------------------------
$settings = App::settings();
$currentValue = static function (string $key) use ($settings): string {
    $value = $settings;
    foreach (explode('.', $key) as $part) {
        if (is_array($value) === false || isset($value[$part]) === false) {
            return '';
        }
        $value = $value[$part];
    }
    return is_scalar($value) ? (string) $value : '';
};

$pageTitle   = $group['title'];
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Settings' => '/admin/settings', $group['title'] => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<h1 class="mb-1"><i class="<?php echo htmlspecialchars((string) $group['icon'], ENT_QUOTES, 'UTF-8'); ?> me-2"></i><?php echo htmlspecialchars((string) $group['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
<p class="text-muted"><?php echo htmlspecialchars((string) $group['intro'], ENT_QUOTES, 'UTF-8'); ?></p>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="card">
        <div class="card-body">
            <?php foreach ($group['fields'] as $key => $def):
                $formName = str_replace('.', '_', $key);
                $val = $currentValue($key);
            ?>
                <div class="mb-3">
                    <?php if ($def['type'] === 'toggle'): ?>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="<?php echo htmlspecialchars($formName, ENT_QUOTES, 'UTF-8'); ?>"
                                   name="<?php echo htmlspecialchars($formName, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($val === '1' || $val === 'true') ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="<?php echo htmlspecialchars($formName, ENT_QUOTES, 'UTF-8'); ?>">
                                <strong><?php echo htmlspecialchars((string) $def['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            </label>
                        </div>
                    <?php else: ?>
                        <label class="form-label" for="<?php echo htmlspecialchars($formName, ENT_QUOTES, 'UTF-8'); ?>">
                            <strong><?php echo htmlspecialchars((string) $def['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        </label>
                        <?php if ($def['type'] === 'textarea'): ?>
                            <textarea class="form-control form-control-sm" id="<?php echo htmlspecialchars($formName, ENT_QUOTES, 'UTF-8'); ?>"
                                      name="<?php echo htmlspecialchars($formName, ENT_QUOTES, 'UTF-8'); ?>" rows="2"><?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <?php elseif ($def['type'] === 'select'): ?>
                            <select class="form-select form-select-sm" id="<?php echo htmlspecialchars($formName, ENT_QUOTES, 'UTF-8'); ?>"
                                    name="<?php echo htmlspecialchars($formName, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php foreach ((array) $def['options'] as $optVal => $optLabel): ?>
                                    <option value="<?php echo htmlspecialchars((string) $optVal, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $val === (string) $optVal ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string) $optLabel, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="<?php echo $def['type'] === 'number' ? 'number' : 'text'; ?>"
                                   <?php echo isset($def['min']) ? 'min="' . (int) $def['min'] . '"' : ''; ?>
                                   class="form-control form-control-sm" id="<?php echo htmlspecialchars($formName, ENT_QUOTES, 'UTF-8'); ?>"
                                   name="<?php echo htmlspecialchars($formName, ENT_QUOTES, 'UTF-8'); ?>"
                                   value="<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (($def['help'] ?? '') !== ''): ?>
                        <div class="form-text"><?php echo htmlspecialchars((string) $def['help'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <div class="small text-muted"><code><?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?></code></div>
                </div>
            <?php endforeach; ?>

            <button type="submit" class="btn btn-primary">Save</button>
            <a href="/admin/settings" class="btn btn-outline-secondary">All settings</a>
        </div>
    </div>
</form>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
