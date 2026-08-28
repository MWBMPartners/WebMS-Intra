<?php
// Path: _apps/venues/settings.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Settings ⚙️
 * -----------------------------------------------------------------------------
 * Admin-only curated form over the `venues.*` keys (02-app-design.md §4.8).
 *
 * WRITE IDIOM (03-build-plan.md §2.4 / 02b open Q5 resolution):
 *   - Per-site keys (calendar_default_venue, reminder_roles, the three
 *     lead-day ints, currency, reminders_enabled) upsert with
 *     `siteID = Site::id()` via `INSERT … ON DUPLICATE KEY UPDATE` — the
 *     unique key `(settingKey, siteID)` fires normally because BOTH columns
 *     are non-NULL.
 *   - The GLOBAL `venues.cron_token` (siteID IS NULL, isSensitive = 1) MUST
 *     NOT use ON DUPLICATE KEY UPDATE: MySQL treats every NULL as a distinct
 *     value for uniqueness, so `(settingKey, NULL)` rows are never deduped —
 *     an ODKU insert would just accumulate duplicate NULL-siteID rows every
 *     time the token was regenerated. This uses update-then-insert instead
 *     (`admin/translation/save.php` l.43-55 shape: SELECT-by-id, UPDATE if
 *     found, else INSERT).
 *
 * SENSITIVE-VALUE ENCRYPTION: `_core/bootstrap.php`'s settings loader calls
 * `decrypt_setting()` on every `isSensitive = 1` row (regardless of whether
 * it was actually encrypted at write time) — an unencrypted value fed
 * through libsodium's authenticated decryption fails its MAC check and
 * `decrypt_setting()` silently returns `''`. Concretely: `qr/index.php`'s
 * own "plain value + isSensitive=1" write is a LATENT bug for this reason —
 * a real API key stored that way reads back as empty on every subsequent
 * request. To keep `venues.cron_token` actually functional (readable by
 * both this page's own status indicator and the reminders cron's
 * `Settings::get()` gate), the token is run through the SAME
 * `encrypt_setting()` call the generic `/settings` editor and
 * `admin/translation/save.php` already use for `isSensitive = 1` values —
 * this is a corrected application of the cited house precedent, not a
 * deviation from the (non-ON-DUPLICATE) write-idiom requirement, which is
 * unaffected by whether the stored bytes are encrypted.
 *
 * @package   Portal\Venues
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/429
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Settings;
use Portal\Core\Site;
use Portal\Core\Venues;

/**
 * Upsert a PER-SITE settings row. Safe to use ON DUPLICATE KEY UPDATE here
 * because `siteID` is a real (non-NULL) value — the composite unique key
 * `(settingKey, siteID)` fires normally.
 */
function venues_settings_upsert_per_site(\mysqli $db, int $siteId, string $key, string $value): void
{
    $stmt = $db->prepare(
        'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive, updatedAt) '
        . 'VALUES (?, ?, ?, ?, 0, NOW()) '
        . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue), updatedAt = NOW()'
    );
    if ($stmt === false) {
        return;
    }
    $stmt->bind_param('isss', $siteId, $key, $value, $value);
    $stmt->execute();
    $stmt->close();
}

/**
 * Generate + store a new `venues.cron_token` (siteID IS NULL, isSensitive
 * = 1) via update-then-insert — see file header for why ON DUPLICATE KEY
 * UPDATE must never be used for a NULL-siteID row. Returns the new
 * PLAINTEXT token (for the one-time flash reveal); the stored value is
 * encrypted at rest.
 */
function venues_settings_regenerate_cron_token(\mysqli $db): string
{
    $token = bin2hex(random_bytes(32));
    $stored = $token;
    if (function_exists('encrypt_setting') === true) {
        $stored = encrypt_setting($token);
    }

    $key = 'venues.cron_token';
    $sel = $db->prepare('SELECT settingID FROM tblSettings WHERE settingKey = ? AND siteID IS NULL LIMIT 1');
    $existingId = 0;
    if ($sel !== false) {
        $sel->bind_param('s', $key);
        $sel->execute();
        $row = $sel->get_result()->fetch_assoc();
        $sel->close();
        $existingId = $row !== null ? (int) $row['settingID'] : 0;
    }

    if ($existingId > 0) {
        $upd = $db->prepare('UPDATE tblSettings SET settingValue = ?, isSensitive = 1, updatedAt = NOW() WHERE settingID = ?');
        if ($upd !== false) {
            $upd->bind_param('si', $stored, $existingId);
            $upd->execute();
            $upd->close();
        }
    } else {
        $ins = $db->prepare(
            'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive, updatedAt) '
            . 'VALUES (NULL, ?, ?, ?, 1, NOW())'
        );
        if ($ins !== false) {
            $default = '';
            $ins->bind_param('sss', $key, $stored, $default);
            $ins->execute();
            $ins->close();
        }
    }

    return $token;
}

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Admin-only — settings affects every site, not just the ones a
// venue_manager happens to manage.
if (App::isAdmin() !== true) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$db     = App::db();

$oneTimeTokenUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🔐 CSRF FIRST — before any side-effect.
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /venues/settings');
        exit();
    }

    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'regenerate_token') {
        $newToken = venues_settings_regenerate_cron_token($db);
        Logger::activity('VenueSettingsSaved', 'Regenerated the venue reminders cron token', $userId > 0 ? $userId : null);

        $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host    = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $cronUrl = $scheme . '://' . $host . '/cron/venue-reminders?key=' . $newToken;

        $_SESSION['flash_msg']  = 'Cron token regenerated. Copy this URL now — it will not be shown again: ' . $cronUrl;
        $_SESSION['flash_type'] = 'warning';
        header('Location: /venues/settings');
        exit();
    }

    // 💾 action=save — the seven per-site keys.
    $activeVenueIds = array_map(static fn (array $v): int => (int) $v['venueID'], Venues::listVenues($siteId, true));
    $calendarDefaultVenue = (int) ($_POST['calendar_default_venue'] ?? 0);
    if ($calendarDefaultVenue !== 0 && in_array($calendarDefaultVenue, $activeVenueIds, true) === false) {
        $calendarDefaultVenue = 0;
    }

    $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'GBP')));
    if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
        $currency = 'GBP';
    }

    $unagreedLeadDays    = max(0, min(3650, (int) ($_POST['unagreed_lead_days'] ?? 21)));
    $renewalLeadDays     = max(0, min(3650, (int) ($_POST['renewal_lead_days'] ?? 60)));
    $invoiceDueLeadDays  = max(0, min(3650, (int) ($_POST['invoice_due_lead_days'] ?? 7)));
    $remindersEnabled    = isset($_POST['reminders_enabled']) === true ? '1' : '0';

    // 🎭 reminder_roles — CSV of valid tblRoles.roleKey values only.
    $validRoleKeys = [];
    $roleResult = $db->query('SELECT roleKey FROM tblRoles ORDER BY roleName');
    if ($roleResult !== false) {
        while ($r = $roleResult->fetch_assoc()) {
            $validRoleKeys[] = (string) $r['roleKey'];
        }
    }
    $postedRoles = array_filter((array) ($_POST['reminder_roles'] ?? []), static fn ($v): bool => is_string($v) === true);
    $chosenRoles = array_values(array_intersect($validRoleKeys, $postedRoles));
    $reminderRolesCsv = implode(',', $chosenRoles);

    venues_settings_upsert_per_site($db, $siteId, 'venues.calendar_default_venue', (string) $calendarDefaultVenue);
    venues_settings_upsert_per_site($db, $siteId, 'venues.currency', $currency);
    venues_settings_upsert_per_site($db, $siteId, 'venues.unagreed_lead_days', (string) $unagreedLeadDays);
    venues_settings_upsert_per_site($db, $siteId, 'venues.renewal_lead_days', (string) $renewalLeadDays);
    venues_settings_upsert_per_site($db, $siteId, 'venues.invoice_due_lead_days', (string) $invoiceDueLeadDays);
    venues_settings_upsert_per_site($db, $siteId, 'venues.reminders_enabled', $remindersEnabled);
    venues_settings_upsert_per_site($db, $siteId, 'venues.reminder_roles', $reminderRolesCsv);

    Logger::activity('VenueSettingsSaved', 'Updated venue booking settings', $userId > 0 ? $userId : null);

    $_SESSION['flash_msg']  = 'Settings saved.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /venues/settings');
    exit();
}

// 🖼️ GET — render current effective values for this site.
$activeVenues = Venues::listVenues($siteId, true);
$roles = [];
$roleResult = $db->query('SELECT roleKey, roleName FROM tblRoles ORDER BY roleName');
if ($roleResult !== false) {
    while ($r = $roleResult->fetch_assoc()) {
        $roles[] = $r;
    }
}

$calendarDefaultVenue = (int) Settings::get('venues.calendar_default_venue', '0');
$currency             = (string) Settings::get('venues.currency', 'GBP');
$unagreedLeadDays     = (int) Settings::get('venues.unagreed_lead_days', '21');
$renewalLeadDays      = (int) Settings::get('venues.renewal_lead_days', '60');
$invoiceDueLeadDays   = (int) Settings::get('venues.invoice_due_lead_days', '7');
$remindersEnabled     = (string) Settings::get('venues.reminders_enabled', '1') === '1';
$reminderRolesCsv     = (string) Settings::get('venues.reminder_roles', '');
$chosenRoleKeys       = array_filter(array_map('trim', explode(',', $reminderRolesCsv)));
$tokenSet             = (string) Settings::get('venues.cron_token', '') !== '';

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Venue Bookings Settings';
$pageSection = 'venues';
$breadcrumbs = ['Dashboard' => '/', 'Venues' => '/venues', 'Settings' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-gear me-2"></i>Venue Bookings Settings</h1>
    <a href="/venues/manage" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to venues</a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="post" action="/venues/settings" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save">

            <div class="col-md-4">
                <label class="form-label small" for="calendar_default_venue">Calendar "is it booked?" venue</label>
                <select class="form-select form-select-sm" id="calendar_default_venue" name="calendar_default_venue">
                    <option value="0" <?php echo $calendarDefaultVenue === 0 ? 'selected' : ''; ?>>— disabled —</option>
                    <?php foreach ($activeVenues as $v): ?>
                        <option value="<?php echo (int) $v['venueID']; ?>" <?php echo $calendarDefaultVenue === (int) $v['venueID'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $v['venueName'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Which venue the calendar's event-save "is it booked?" check and the live event-form widget run against.</div>
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="currency">Currency</label>
                <input type="text" class="form-control form-control-sm text-uppercase" id="currency" name="currency" maxlength="3"
                       value="<?php echo htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label small" for="unagreed_lead_days">Unagreed booking reminder (days)</label>
                <input type="number" min="0" max="3650" class="form-control form-control-sm" id="unagreed_lead_days" name="unagreed_lead_days" value="<?php echo $unagreedLeadDays; ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="renewal_lead_days">Agreement renewal reminder (days)</label>
                <input type="number" min="0" max="3650" class="form-control form-control-sm" id="renewal_lead_days" name="renewal_lead_days" value="<?php echo $renewalLeadDays; ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="invoice_due_lead_days">Invoice due reminder (days)</label>
                <input type="number" min="0" max="3650" class="form-control form-control-sm" id="invoice_due_lead_days" name="invoice_due_lead_days" value="<?php echo $invoiceDueLeadDays; ?>">
            </div>

            <div class="col-12 form-check">
                <input type="checkbox" class="form-check-input" id="reminders_enabled" name="reminders_enabled" value="1" <?php echo $remindersEnabled === true ? 'checked' : ''; ?>>
                <label class="form-check-label small" for="reminders_enabled">Reminders enabled for this site</label>
            </div>

            <div class="col-12">
                <label class="form-label small d-block">Reminder recipients (roles)</label>
                <?php if (count($roles) === 0): ?>
                    <p class="small text-muted mb-0">No roles defined yet.</p>
                <?php else: ?>
                    <?php foreach ($roles as $role): ?>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" id="role_<?php echo htmlspecialchars((string) $role['roleKey'], ENT_QUOTES, 'UTF-8'); ?>"
                                   name="reminder_roles[]" value="<?php echo htmlspecialchars((string) $role['roleKey'], ENT_QUOTES, 'UTF-8'); ?>"
                                   <?php echo in_array((string) $role['roleKey'], $chosenRoleKeys, true) === true ? 'checked' : ''; ?>>
                            <label class="form-check-label small" for="role_<?php echo htmlspecialchars((string) $role['roleKey'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars((string) $role['roleName'], ENT_QUOTES, 'UTF-8'); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-text">Leave every role unchecked to fall back to site admins + Venue Manager holders.</div>
                <?php endif; ?>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-check me-1"></i>Save settings</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h5">Reminders cron token</h2>
        <p class="small text-muted">
            The <code>/cron/venue-reminders</code> endpoint is inert until this token is set — a scheduled job (DreamHost cron tab or similar)
            must call it with <code>?key=&lt;token&gt;</code> on a daily schedule.
        </p>
        <p>
            Status:
            <span class="badge <?php echo $tokenSet === true ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $tokenSet === true ? 'Set' : 'Not set'; ?></span>
        </p>
        <form method="post" action="/venues/settings" data-confirm="Regenerate the reminders cron token? Any existing scheduled job using the OLD token will stop working until it is updated.">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="regenerate_token">
            <button type="submit" class="btn btn-outline-warning btn-sm"><i class="fa-solid fa-key me-1"></i>Regenerate token</button>
        </form>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
