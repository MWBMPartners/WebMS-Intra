<?php
// Path: _apps/calendar/submit.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Public "Submit an Event" Form 📅✏️ (#326)
 * -----------------------------------------------------------------------------
 * Public form letting any visitor (logged-in OR anonymous) propose an event.
 * Submissions land in `tblEvents` with `submissionStatus='pending'`,
 * `isPublic=0`, `status='draft'` and require admin approval before going live.
 * Mirrors the moderation pattern shipped for Prayer Requests (PR #129).
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/326
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();

if ((App::settings('calendar.publicSubmit.enabled') ?? 'true') !== 'true') {
    Router::renderError(404);
    return;
}

$isLoggedIn   = isset($_SESSION['user_id']) === true && (int) $_SESSION['user_id'] > 0;
$allowAnon    = (App::settings('calendar.publicSubmit.allowAnonymous') ?? 'true') === 'true';
$requireCap   = (App::settings('calendar.publicSubmit.requireCaptcha')  ?? 'true') === 'true';

if ($isLoggedIn === false && $allowAnon === false) {
    header('Location: /auth/login?redirect=' . urlencode('/calendar/submit'), true, 302);
    exit();
}

$siteId = Site::id();

// 📂 Categories the public form can select from (admin restricts via setting).
$categories = [];
$stmt = $mysqli->prepare(
    'SELECT categoryID, categoryName FROM tblEventCategories WHERE siteID = ? OR siteID IS NULL ORDER BY categoryName ASC'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $categories[] = $r;
    }
    $stmt->close();
}

$pageTitle   = 'Submit an Event';
$pageSection = 'calendar';
$csrf        = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
$success     = isset($_GET['submitted']) === true;

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4" style="max-width: 720px;">
    <h1 class="h3 mb-2"><i class="fa-solid fa-calendar-plus me-2 text-primary"></i>Submit an Event</h1>
    <p class="text-muted">
        Propose an event for the
        <?php echo htmlspecialchars(Site::productName(), ENT_QUOTES, 'UTF-8'); ?>
        calendar. Submissions are reviewed by an admin before going live.
    </p>

    <?php if ($success === true): ?>
        <div class="alert alert-success">
            <i class="fa-solid fa-check-circle me-1"></i>
            Thank you — your event has been submitted. We'll review it shortly.
            <?php if ($isLoggedIn === false): ?>
                You'll receive an email at the address you provided when it's approved.
            <?php endif; ?>
        </div>
        <a href="/calendar" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back to calendar</a>
    <?php else: ?>
        <form method="post" action="/calendar/submit-save" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

            <div class="row g-3">
                <div class="col-md-12">
                    <label for="eventName" class="form-label">Event title <span class="text-danger">*</span></label>
                    <input type="text" id="eventName" name="eventName" required maxlength="255" class="form-control">
                </div>

                <div class="col-md-6">
                    <label for="startDateTime" class="form-label">Starts <span class="text-danger">*</span></label>
                    <input type="datetime-local" id="startDateTime" name="startDateTime" required class="form-control">
                </div>
                <div class="col-md-6">
                    <label for="endDateTime" class="form-label">Ends</label>
                    <input type="datetime-local" id="endDateTime" name="endDateTime" class="form-control">
                </div>

                <div class="col-md-6">
                    <label for="categoryID" class="form-label">Category</label>
                    <select id="categoryID" name="categoryID" class="form-select">
                        <option value="">— pick a category —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo (int) $c['categoryID']; ?>"><?php echo htmlspecialchars((string) $c['categoryName'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="locationName" class="form-label">Location</label>
                    <input type="text" id="locationName" name="locationName" maxlength="255" class="form-control" placeholder="e.g. Church Hall">
                </div>

                <div class="col-12">
                    <label for="description" class="form-label">Description</label>
                    <textarea id="description" name="description" rows="6" class="form-control" placeholder="What's happening? Who's it for? Anything attendees should bring?"></textarea>
                </div>

                <?php if ($isLoggedIn === false): ?>
                    <div class="col-md-6">
                        <label for="submitterName" class="form-label">Your name <span class="text-danger">*</span></label>
                        <input type="text" id="submitterName" name="submitterName" required maxlength="120" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label for="submitterEmail" class="form-label">Your email <span class="text-danger">*</span></label>
                        <input type="email" id="submitterEmail" name="submitterEmail" required maxlength="255" class="form-control">
                        <div class="form-text">We'll email you when the submission is reviewed.</div>
                    </div>

                    <?php if ($requireCap === true && class_exists(Captcha::class) === true): ?>
                        <div class="col-12">
                            <?php echo Captcha::renderWidget(); ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane me-1"></i> Submit for review</button>
                    <a href="/calendar" class="btn btn-outline-secondary ms-2">Cancel</a>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
