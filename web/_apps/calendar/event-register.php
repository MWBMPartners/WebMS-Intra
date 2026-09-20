<?php
// Path: _apps/calendar/event-register.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Public event registration form 📝 (#347)
 * -----------------------------------------------------------------------------
 * GET. Public, no-login. Validates that the event has registration enabled
 * AND is within the open/close window. Renders the VBS-relevant fixed
 * field set: participant info + grade + shirt size + allergies + medical
 * notes + parent details + consent + emergency contact.
 *
 * POST goes to /calendar/event/register/save.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/347
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Site;

$slug = trim((string) ($_GET['slug'] ?? ''));
if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9\-]{0,79}$/i', $slug) !== 1) {
    http_response_code(400); exit('Invalid event.');
}

// 🛡️ #532 (20 September 2026): WHAT WAS WRONG BEFORE. The lookup above
//    tested only "published, not deleted", and the sign-in decision ran
//    AFTERWARDS, in PHP, once the row was already known to exist. That let a
//    signed-out visitor tell a REAL internal event apart from a MADE-UP
//    slug: a real one sent them to sign in (302), a made-up one said "Event
//    not found." (404). Event slugs are made from event names — often
//    guessable ("staff-meeting-march", "safeguarding-review") — so that
//    difference alone told a stranger which internal events exist, even
//    though the same visitor could never actually open one. #503 and #519
//    already closed this exact shape of leak on four other calendar
//    handlers; this is the same fix applied here.
//
//    THE RULE NOW. The viewer test moves INSIDE the WHERE clause, so a
//    refused row and a genuinely missing one cost the database exactly the
//    same work and come back as exactly the same "no row" — nothing is
//    decided in PHP afterwards. This WHERE clause is deliberately
//    byte-for-byte the same shape as `calendar/anon-checkin.php`'s (after
//    #533 removed that page's single-organisation branch), so a future
//    change (#514) can replace all such call sites with one shared method
//    without any of them behaving differently first.
//
//    A public, published event: open to everyone, exactly as before.
//    An internal event: a member of THAT event's own organisation, or a
//    global root administrator. Everybody else — a signed-in member of a
//    DIFFERENT organisation, an account holding only the older portal-wide
//    `isAdmin` flag with no membership row here, or a signed-out visitor —
//    gets refused. The older `isAdmin` flag is deliberately NOT tested here,
//    for the same reason anon-checkin.php does not test it: any site
//    administrator can switch it on for any account in any organisation, so
//    it proves nothing about which organisation somebody actually belongs
//    to (#511, #514 finding 3).
//
//    WHY SIGN-IN, NOT 404, FOR A SIGNED-OUT VISITOR. The check-in page
//    (anon-checkin.php) chose 404 for everyone refused, because it is
//    designed to be used AT THE DOOR, signed out, with no expectation of
//    ever signing in. This page is different: a member gets sent a
//    registration LINK by email and is expected to sign in to use it, so a
//    signed-out visitor is sent to sign in for anything that is not a
//    public published event — a real internal event, a draft, a deleted
//    event and a made-up slug ALL alike — and only finds out afterwards,
//    once signed in, whether the event was real. A SIGNED-IN visitor who
//    may not see the event gets the plain 404 straight away, the same as a
//    made-up slug.
//
//    WHAT THIS CANNOT DO. Inside MySQL, a slug that matches a real internal
//    row still costs reading that row before the WHERE clause rejects it —
//    microseconds, the same limit `rsvp.php`'s own comment already accepts
//    for the same reason. It also cannot stop a signed-out visitor with a
//    WRONG slug being sent to sign in rather than told "not found"
//    straight away — accepted, deliberately: see the settled plan's
//    "Rejected" list for why 404-for-everyone-signed-out was not chosen.
$viewerId = (int) ($_SESSION['user_id'] ?? 0); // 0 when signed out — both EXISTS branches below are then false
$siteId   = Site::id();
$stmt = $mysqli->prepare(
    'SELECT eventID, eventName, eventSlug, startDateTime, registrationEnabled, '
    . '       registrationOpensAt, registrationClosesAt, isPublic '
    . 'FROM tblEvents '
    . 'WHERE eventSlug = ? AND siteID = ? AND isDeleted = 0 '
    . "  AND status = 'published' "
    . '  AND ( isPublic = 1 '
    . '        OR EXISTS (SELECT 1 FROM tblUsers va '
    . '                    WHERE va.userID = ? AND va.isActive = 1 AND va.isRootAdmin = 1) '
    . '        OR EXISTS (SELECT 1 FROM tblUsers vm '
    . '                    WHERE vm.userID = ? AND vm.isActive = 1 '
    . '                      AND EXISTS (SELECT 1 FROM tblUserSites ms '
    . '                                   WHERE ms.userID = vm.userID AND ms.siteID = ? AND ms.isActive = 1)) '
    . '      ) LIMIT 1'
);
$stmt->bind_param('siiii', $slug, $siteId, $viewerId, $viewerId, $siteId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if ($event === null) {
    if (Auth::check() === false) {
        Auth::requireLogin(); // sends to /login?redirect=… and exits; never returns
    }
    http_response_code(404); exit('Event not found.');
}

if ((int) $event['registrationEnabled'] !== 1) {
    http_response_code(404); exit('Registration is not open for this event.');
}
$now = time();
if (!empty($event['registrationOpensAt']) && strtotime((string) $event['registrationOpensAt']) > $now) {
    http_response_code(403); exit('Registration opens ' . htmlspecialchars(date('j M Y', strtotime((string) $event['registrationOpensAt'])), ENT_QUOTES, 'UTF-8'));
}
if (!empty($event['registrationClosesAt']) && strtotime((string) $event['registrationClosesAt']) < $now) {
    http_response_code(403); exit('Registration has closed.');
}

$pageTitle = 'Register — ' . (string) $event['eventName'];
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
$captchaConfigured = class_exists(Captcha::class) === true && Captcha::isConfigured() === true;

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
if ($captchaConfigured === true) {
    echo Captcha::scriptTag();
}
?>

<div class="container py-4" style="max-width:720px;">
    <h1 class="h3 mb-1"><i class="fa-solid fa-user-plus me-2 text-primary"></i>Register for <?php echo htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="text-muted small mb-4">Please fill in the details below. Required fields are marked with <span class="text-danger">*</span>.</p>

    <form method="post" action="/calendar/event/register/save" class="needs-validation">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="slug" value="<?php echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>">

        <fieldset class="mb-4">
            <legend class="h6">Participant</legend>
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label small">Full name <span class="text-danger">*</span></label>
                    <input type="text" name="fullName" required maxlength="120" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Date of birth</label>
                    <input type="date" name="dateOfBirth" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Grade / year</label>
                    <input type="text" name="grade" maxlength="10" class="form-control" placeholder="e.g. 5">
                </div>
                <div class="col-md-6">
                    <label class="form-label small">Gender</label>
                    <select name="gender" class="form-select">
                        <option value="">Prefer not to say</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small">Shirt size</label>
                    <select name="shirtSize" class="form-select">
                        <option value="">Choose…</option>
                        <option>YS</option><option>YM</option><option>YL</option>
                        <option>XS</option><option>S</option><option>M</option>
                        <option>L</option><option>XL</option><option>XXL</option>
                    </select>
                </div>
            </div>
        </fieldset>

        <fieldset class="mb-4">
            <legend class="h6">Health &amp; safety</legend>
            <div class="mb-2">
                <label class="form-label small">Allergies (food, environmental, medication)</label>
                <textarea name="allergies" rows="2" maxlength="500" class="form-control"></textarea>
            </div>
            <div class="mb-2">
                <label class="form-label small">Medical notes (asthma, anxiety triggers, etc.)</label>
                <textarea name="medicalNotes" rows="3" maxlength="1000" class="form-control"></textarea>
            </div>
        </fieldset>

        <fieldset class="mb-4">
            <legend class="h6">Parent / guardian (for under-18s)</legend>
            <div class="row g-2">
                <div class="col-md-6"><label class="form-label small">Parent / guardian name</label><input type="text" name="parentName" maxlength="120" class="form-control"></div>
                <div class="col-md-3"><label class="form-label small">Parent phone</label><input type="tel" name="parentPhone" maxlength="40" class="form-control"></div>
                <div class="col-md-3"><label class="form-label small">Parent email</label><input type="email" name="parentEmail" maxlength="255" class="form-control"></div>
            </div>
        </fieldset>

        <fieldset class="mb-4">
            <legend class="h6">Emergency contact</legend>
            <div class="row g-2">
                <div class="col-md-7"><label class="form-label small">Name</label><input type="text" name="emergencyContactName" maxlength="120" class="form-control"></div>
                <div class="col-md-5"><label class="form-label small">Phone</label><input type="tel" name="emergencyContactPhone" maxlength="40" class="form-control"></div>
            </div>
        </fieldset>

        <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" id="photoConsent" name="photoConsent" value="1">
            <label class="form-check-label small" for="photoConsent">
                I consent to photos of the participant being used in event-related materials.
            </label>
        </div>

        <?php if ($captchaConfigured === true): ?>
            <div class="mb-3"><?php echo Captcha::widget(); ?></div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary btn-lg"><i class="fa-solid fa-paper-plane me-1"></i>Submit registration</button>
        <a href="/calendar/event?slug=<?php echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-link">Cancel</a>
    </form>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
