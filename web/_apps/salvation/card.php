<?php
// _apps/salvation/card.php — public decision card form (#316)
declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Site;

$eventId = (int) ($_GET['eventID'] ?? 0);
$pageTitle = 'Decision Card';
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
$captchaConfigured = class_exists(Captcha::class) === true && Captcha::isConfigured() === true;

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
if ($captchaConfigured === true) { echo Captcha::scriptTag(); }
?>
<div class="container py-4" style="max-width:560px;">
    <h1 class="h3 mb-2"><i class="fa-solid fa-hand-holding-heart me-2 text-primary"></i>Decision Card</h1>
    <p class="text-muted small">Tell us how we can support you. Your details are confidential and seen only by the follow-up team.</p>

    <form method="post" action="/decision-card/save">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <?php if ($eventId > 0): ?>
            <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
        <?php endif; ?>

        <div class="mb-3"><label class="form-label">Full name <span class="text-danger">*</span></label><input type="text" name="fullName" required maxlength="120" class="form-control"></div>
        <div class="row g-2 mb-3">
            <div class="col-md-7"><label class="form-label">Email</label><input type="email" name="email" maxlength="255" class="form-control"></div>
            <div class="col-md-5"><label class="form-label">Phone</label><input type="tel" name="phone" maxlength="40" class="form-control"></div>
        </div>
        <div class="mb-3"><label class="form-label">Address (optional)</label><textarea name="address" rows="2" maxlength="500" class="form-control"></textarea></div>

        <div class="mb-3">
            <label class="form-label">My decision</label>
            <select name="decision" class="form-select">
                <option value="first-time">Accepting Christ for the first time</option>
                <option value="rededication">Rededicating my life</option>
                <option value="baptism">Interested in baptism</option>
                <option value="membership">Interested in church membership</option>
                <option value="bible-study">Want Bible study</option>
                <option value="prayer">Just want prayer</option>
                <option value="other">Other</option>
            </select>
        </div>

        <div class="mb-3"><label class="form-label">Prayer request (optional)</label><textarea name="prayerRequest" rows="3" maxlength="1000" class="form-control"></textarea></div>

        <?php if ($captchaConfigured === true): ?>
            <div class="mb-3"><?php echo Captcha::widget(); ?></div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary btn-lg"><i class="fa-solid fa-paper-plane me-1"></i>Submit</button>
    </form>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
