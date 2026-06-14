<?php
// Path: public_html/prayer-requests/submit.php
/**
 * -----------------------------------------------------------------------------
 * Prayer Requests — Submit Form (Logged-in) 🙏
 * -----------------------------------------------------------------------------
 * Renders the prayer-request submission form for authenticated members.
 * Members can choose visibility (leadership-only or congregation feed) and
 * may opt to display their request anonymously. The POST handler lives in
 * save.php and validates + persists the row.
 *
 * @package   Portal\PrayerRequests
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.10.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

// 📌 Page metadata
$pageTitle   = 'Submit Prayer Request';
$pageSection = 'prayer-requests';
$breadcrumbs = [
    'Dashboard'        => '/',
    'Prayer Requests'  => '/prayer-requests',
    'Submit'           => '',
];

// 🛡️ Require login
Auth::ensureSession();
Auth::requireLogin();

// 🚦 Feature gate
$featureEnabled      = (App::settings('prayer-requests.enabled') ?? 'true') === 'true';
$congregationEnabled = (App::settings('prayer-requests.allowCongregationFeed') ?? 'true') === 'true';
$requireModeration   = (App::settings('prayer-requests.requireModeration') ?? 'true') === 'true';

if ($featureEnabled === false) {
    header('Location: /prayer-requests', true, 302);
    exit();
}

// 💬 Read flash error from query string (set by save.php)
$flashError = (string) ($_GET['err'] ?? '');

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-pen-to-square me-2"></i>Submit a Prayer Request</h1>
    <a href="/prayer-requests" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back
    </a>
</div>

<?php if ($flashError !== ''): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-circle-exclamation me-1"></i>
        <?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" action="/prayer-requests/save" novalidate>
            <input type="hidden" name="csrf_token"
                   value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

            <!-- 📝 Subject -->
            <div class="mb-3">
                <label for="subject" class="form-label">
                    Subject <span class="text-danger">*</span>
                </label>
                <input type="text" class="form-control" id="subject" name="subject"
                       maxlength="255" required autofocus
                       placeholder="A short title (e.g. 'Healing for my mother')">
            </div>

            <!-- 📜 Body -->
            <div class="mb-3">
                <label for="body" class="form-label">
                    Request <span class="text-danger">*</span>
                </label>
                <textarea class="form-control" id="body" name="body" rows="6"
                          maxlength="4000" required
                          placeholder="Share what you'd like prayer for…"></textarea>
                <div class="form-text">Up to 4000 characters.</div>
            </div>

            <!-- 👁️ Visibility -->
            <div class="mb-3">
                <label class="form-label d-block">Visibility</label>

                <div class="form-check">
                    <input class="form-check-input" type="radio" name="visibility"
                           id="vis-leadership" value="leadership" checked>
                    <label class="form-check-label" for="vis-leadership">
                        <i class="fa-solid fa-user-shield me-1"></i>
                        <strong>Leadership only</strong>
                        <div class="small text-muted">
                            Visible only to pastors/leaders for confidential prayer.
                        </div>
                    </label>
                </div>

                <?php if ($congregationEnabled === true): ?>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="radio" name="visibility"
                               id="vis-congregation" value="congregation">
                        <label class="form-check-label" for="vis-congregation">
                            <i class="fa-solid fa-people-group me-1"></i>
                            <strong>Share with the congregation</strong>
                            <div class="small text-muted">
                                Will appear on the public prayer feed for all logged-in members.
                            </div>
                        </label>
                    </div>
                <?php endif; ?>
            </div>

            <!-- 🕶️ Anonymous toggle -->
            <div class="form-check mb-4">
                <input class="form-check-input" type="checkbox" name="isAnonymous"
                       id="isAnonymous" value="1">
                <label class="form-check-label" for="isAnonymous">
                    Display my name as <strong>Anonymous</strong> on the congregation feed
                </label>
                <div class="form-text">
                    Leaders will still see who submitted the request for follow-up.
                </div>
            </div>

            <?php if ($requireModeration === true): ?>
                <div class="alert alert-info small">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    Your request will be reviewed by a moderator before it goes live.
                </div>
            <?php endif; ?>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success">
                    <i class="fa-solid fa-paper-plane me-1"></i> Submit Request
                </button>
                <a href="/prayer-requests" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
