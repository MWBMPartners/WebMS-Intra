<?php
// Path: public_html/auth/account/notifications.php
/**
 * -----------------------------------------------------------------------------
 * Account — Notification preferences UI 📬
 * -----------------------------------------------------------------------------
 * Lets each user opt in/out of the various notification channels:
 *
 *   • Weekly email digest
 *   • Per-category event reminders (calendar)
 *   • Expense workflow updates (approver decisions, treasury, withdrawal)
 *   • Prayer-request moderation (for moderators)
 *   • Announcement notifications
 *   • Task reminders / Rota duty reminders (gap #439, cron/user-reminders)
 *   • Workflow approval requests (#443, Portal\Core\Workflow)
 *   • Web Push master prefs (#322) — pushLivestream / pushServiceReminders.
 *     These gate the NOTIFICATION content only; per-DEVICE opt-in (which
 *     browsers actually receive a push at all) is the separate
 *     `[data-push-optin]` widget below, backed by Portal\Core\WebPush.
 *   • Imported events waiting for approval (#514 part 9), for
 *     administrators: the daily e-mail is switched by `approvalRequests`
 *     and the push by `pushApprovals` (Portal\Core\ImportNotifier). A
 *     person who is not an administrator is never sent either, whatever
 *     these say.
 *
 * ADDING A SWITCH: add the key to `$defaults` here AND to `$allowedKeys` in
 * notifications-save.php in the same change. The save page stores "off"
 * for every key on its list that the form did not send, so a key on its
 * list with no switch here is switched off for everybody who presses Save.
 *
 * Stored in tblUsers.notifyPrefs (JSON column from migration 026).
 *
 * REMOVED — the "delivery gate" banner (#569). This page used to read
 * `notifications.deliveryReady`, seeded 'false', and show every member a
 * banner saying notifications were "not yet being sent on this site". That
 * was UNTRUE: the setting was read in this one place, no sender ever
 * consulted it, and the mail queue sends these messages regardless. So on a
 * fresh install every member was told their preferences would not take
 * effect, while they did. The banner also printed the raw setting name at
 * the member, which the plain-English rule rules out. The setting itself is
 * removed by migration 225; do not reintroduce either.
 *
 * @package   Portal\Auth
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\MailQueue;
use Portal\Core\Site;
use Portal\Core\WebPush;

Auth::ensureSession();
Auth::requireLogin();

$pageTitle   = 'Notification preferences';
$pageSection = 'auth';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Account' => Site::url('account'), 'Notifications' => ''];

$user   = App::user();
$userId = (int) ($user['userID'] ?? 0);

$siteDigestOn  = (App::settings('notifications.digestEnabled')  ?? 'true')  === 'true';
$digestDay     = (string) (App::settings('notifications.digestDay') ?? 'monday');

// 📋 Read the user's current prefs JSON + Sabbath override (#231)
$prefsJson = '';
$sabbathHonour = 'inherit';
$stmt = $mysqli->prepare('SELECT notifyPrefs, sabbathHonour FROM tblUsers WHERE userID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row !== null) {
        if (isset($row['notifyPrefs']) && $row['notifyPrefs'] !== null) {
            $prefsJson = (string) $row['notifyPrefs'];
        }
        if (isset($row['sabbathHonour']) && in_array($row['sabbathHonour'], ['inherit', 'on', 'off'], true)) {
            $sabbathHonour = (string) $row['sabbathHonour'];
        }
    }
    $stmt->close();
}

// 🕯️ Sabbath quiet-hours feature on at the org level?
$sabbathOrgOn = (string) (App::settings()['portal']['sabbath']['enabled'] ?? '0') === '1';
$prefs = json_decode($prefsJson, true);
if (is_array($prefs) === false) {
    $prefs = [];
}

// 🎛️ Defaults — opt-in for digest, opt-in for transactional updates that affect the user directly.
$defaults = [
    'emailDigest'           => true,
    'eventReminders'        => true,
    'eventRsvpConfirmation' => true,
    'expenseStatusUpdates'  => true,
    'expenseApproverNudges' => true,
    'announcementsNew'      => true,
    'prayerModeration'      => true,
    'accountSecurity'       => true,
    'givingStatements'      => true,
    'taskReminders'         => true,
    'rotaReminders'         => true,
    'approvalRequests'      => true,
    'pushLivestream'        => true,
    'pushServiceReminders'  => true,
    'pushApprovals'         => true,
];
foreach ($defaults as $k => $v) {
    if (array_key_exists($k, $prefs) === false) {
        $prefs[$k] = $v;
    }
}

// 📰 Newsletter opt-in.
//    This used to live on a separate page of its own, also called
//    "Notification preferences", which is where the /account/notifications
//    address actually led — so this page, the fuller one, could not be opened
//    at all. Both are now one page, and this is the newsletter half of it.
//
//    The opt-in is stored in its own table rather than in the preferences
//    above, because a newsletter unsubscribe link has to work for someone who
//    is not signed in, which needs a token that only that table holds.
//
//    Someone who has never made a choice counts as opted in, matching what
//    the old page did.
$newsletterOptedIn = true;
$newsletterOn      = (string) (App::settings()['newsletter']['enabled'] ?? '0');
$newsletterOn      = ($newsletterOn === '1' || $newsletterOn === 'true');
$siteId            = Site::id();

if ($newsletterOn === true) {
    $stmt = $mysqli->prepare(
        'SELECT optedIn FROM tblNewsletterSubscription WHERE siteID = ? AND userID = ? LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $siteId, $userId);
        $stmt->execute();
        $nlRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($nlRow !== null) {
            $newsletterOptedIn = ((int) $nlRow['optedIn'] === 1);
        }
    }
}

$flashMsg  = $_SESSION['notifications_flash'] ?? '';
$flashType = $_SESSION['notifications_flash_type'] ?? '';
unset($_SESSION['notifications_flash'], $_SESSION['notifications_flash_type']);

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

/**
 * 🎨 Helper — render a single switch row
 */
$switchRow = static function (string $key, string $label, string $helpText) use ($prefs): string {
    $checked = (bool) ($prefs[$key] ?? false) === true ? 'checked' : '';
    $id = 'np-' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
    return '<div class="form-check form-switch py-2 border-bottom">'
         . '<input class="form-check-input" type="checkbox" role="switch" '
         . 'name="prefs[' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . ']" value="1" '
         . 'id="' . $id . '" ' . $checked . '>'
         . '<label class="form-check-label" for="' . $id . '">'
         . '<strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong>'
         . '<div class="small text-muted">' . htmlspecialchars($helpText, ENT_QUOTES, 'UTF-8') . '</div>'
         . '</label></div>';
};
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-bell me-2"></i>Notification preferences</h1>
    <a href="<?php echo htmlspecialchars(Site::url('account'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Account
    </a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>


<form method="post" action="<?php echo htmlspecialchars(Site::url('account/notifications/save'), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

    <?php
    // 🧹 Three whole cards were removed here on 29 October 2026 (#569, #662):
    //    "Email digest", "Expenses" and "Other", together with the RSVP
    //    confirmation switch in Events below.
    //
    //    Every switch on them was SAVED AND NEVER READ. Measured, not assumed:
    //    nothing under web/_core or web/_apps/cron — the only places anything
    //    is sent from — mentions emailDigest, eventRsvpConfirmation,
    //    expenseStatusUpdates, expenseApproverNudges, announcementsNew,
    //    prayerModeration or accountSecurity. There is no weekly digest job at
    //    all, and "Security alerts" said "Strongly recommended — leave on"
    //    for something that has never sent anything.
    //
    //    A preference that does nothing is a quieter version of a control
    //    whose label does not match its effect: the member believes they have
    //    chosen, and has not. Each switch goes back on this page WITH its
    //    sender, never before it — see #662, which lists what each one needs.
    //
    //    They are removed from `notifications-save.php`'s allowed-key list in
    //    the same change. That matters: the handler writes `false` for every
    //    key on its list that the form did not send, so leaving them listed
    //    while hiding the switches would have quietly rewritten everybody's
    //    stored preferences to off.
    ?>

    <div class="card shadow-sm mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Events &amp; Calendar</h2></div>
        <div class="card-body">
            <?php echo $switchRow('eventReminders',        'Event reminders',                'Get notified ahead of events you have RSVP\'d to.'); ?>
            <?php
            // ⚠️ KEPT, unlike the other dead switches, and said plainly rather
            //    than hidden. The event reminder job honours only the PUSH
            //    switch ("Service reminders", further down); it does not read
            //    this one, so reminder e-mails go out even to somebody who has
            //    switched this off. That is not a switch that does nothing —
            //    it is a switch that says "off" while mail still arrives, and
            //    hiding it would remove the only sign that anything is wrong.
            //    Tracked as its own defect, #666, at a higher priority than
            //    the switches that merely do nothing.
            ?>
            <div class="alert alert-warning small mb-0 mt-2" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-1"></i>
                Switching event reminders off does not stop the e-mails yet &mdash; only
                the push notification below is affected. We are fixing this; until then,
                reminders are sent to everybody who said they are going.
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Giving</h2></div>
        <div class="card-body">
            <?php echo $switchRow('givingStatements', 'Year-end giving statements', 'An annual statement of your recorded giving, emailed to you as a PDF.'); ?>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Tasks &amp; Rota</h2></div>
        <div class="card-body">
            <?php echo $switchRow('taskReminders', 'Task reminders', 'Get notified when a task you\'re assigned to reaches its reminder time.'); ?>
            <?php echo $switchRow('rotaReminders', 'Rota reminders', 'Get notified ahead of duties you\'re rostered on for.'); ?>
        </div>
    </div>

    <?php $pushPublicKey = WebPush::publicKey(); ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Push notifications</h2></div>
        <div class="card-body">
            <?php if ($pushPublicKey === ''): ?>
                <p class="small text-muted mb-0">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    Browser push notifications are not configured on this site yet.
                </p>
                <?php
                // 🧷 The push switches are not shown while push is not set
                //    up, and the save page stores "off" for every key the
                //    form does not send. So each push choice that is ON is
                //    carried in a hidden field: without it, pressing Save
                //    here would switch that push off for the person, and it
                //    would stay off after an administrator set push up.
                //
                //    WHAT WAS WRONG. Until #514 part 9 none was carried, so
                //    Save on this page (to change something else entirely)
                //    switched "We're live now" and "Service reminders" off
                //    for that person. Part 9 first carried only its own new
                //    switch; the independent check of 13 October 2026 ran the
                //    page and confirmed the two older ones were still lost.
                //    All three are carried now.
                //
                //    A choice that is OFF gets no field, so it stays off.
                ?>
                <?php foreach (['pushLivestream', 'pushServiceReminders', 'pushApprovals'] as $pushKey): ?>
                    <?php if ((bool) ($prefs[$pushKey] ?? false) === true): ?>
                        <input type="hidden" name="prefs[<?php echo htmlspecialchars($pushKey, ENT_QUOTES, 'UTF-8'); ?>]" value="1">
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <?php echo $switchRow('pushLivestream', 'We\'re live now', 'Notify me when a livestream starts (if I\'ve enabled notifications on this device below).'); ?>
                <?php echo $switchRow('pushServiceReminders', 'Service reminders', 'Notify me shortly before a service I\'ve RSVP\'d to starts.'); ?>
                <?php echo $switchRow('pushApprovals', 'Imported events waiting for approval', 'For administrators: notify me when an event copied in from an outside calendar starts waiting for approval (if I\'ve enabled notifications on this device below). The notification gives a number only, never an event\'s title.'); ?>
                <div class="pt-3">
                    <p class="small text-muted mb-2">
                        These preferences control WHAT you're notified about. Whether THIS device
                        actually receives push notifications at all is a separate, per-device
                        browser permission:
                    </p>
                    <div data-push-optin
                         data-vapid-key="<?php echo htmlspecialchars($pushPublicKey, ENT_QUOTES, 'UTF-8'); ?>"
                         data-channels="livestream,reminders"></div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Approvals</h2></div>
        <div class="card-body">
            <?php echo $switchRow('approvalRequests', 'Approval requests', 'Get notified when a workflow step is waiting on you, or when a request you submitted is approved/rejected. For administrators it also covers one e-mail a day, on a day when events copied in from an outside calendar are waiting for approval.'); ?>
        </div>
    </div>

    <?php if ($newsletterOn === true): ?>
    <!-- 📰 Newsletter opt-in — merged in from the old separate page -->
    <div class="card shadow-sm mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Newsletter</h2></div>
        <div class="card-body">
            <div class="form-check form-switch py-2">
                <input class="form-check-input" type="checkbox" role="switch"
                       name="newsletterOptedIn" value="1" id="np-newsletterOptedIn"
                       <?php echo $newsletterOptedIn === true ? 'checked' : ''; ?>>
                <label class="form-check-label" for="np-newsletterOptedIn">
                    <strong>Receive newsletters from this portal</strong>
                    <div class="small text-muted">
                        You can also unsubscribe using the link at the bottom of any
                        newsletter, without signing in.
                    </div>
                </label>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($sabbathOrgOn === true): ?>
    <!-- 🕯️ Sabbath quiet hours per-user override (#231) -->
    <div class="card mb-3">
        <div class="card-header">
            <i class="fa-solid fa-moon me-1"></i> Sabbath quiet hours
        </div>
        <div class="card-body">
            <?php
            // 🗣️ This sentence used to say that non-urgent e-mail is held back
            //    until quiet hours end. No e-mail is: nothing in the portal
            //    holds e-mail for quiet hours, and the owner (Salem874)
            //    decided on 12 October 2026 that the mail queue will not
            //    either. What quiet hours do hold is a text message that is
            //    not urgent (Sms::send()).
            //
            //    WHAT BECOMES OF A HELD TEXT changed with #610. Until then
            //    nothing sent it afterwards, and this sentence said "it is
            //    not sent afterwards either". Now the scheduled job sends it
            //    once the person's quiet hours have ended, unless its use-by
            //    moment has passed (Sms::sendHeld()). ONLY THE JOB does
            //    that, so the sentence asks whether the job is running
            //    (MailQueue::jobIsRunning(), the one place that says) and
            //    says what is true on THIS installation. REJECTED: saying
            //    "sent afterwards" everywhere. With no job running that
            //    would be the same untrue promise the old record made.
            //
            //    WHAT THE JOB'S ANSWER MEANS, AND WHAT IT DOES NOT.
            //    jobIsRunning() says only that the job has run in the last
            //    15 minutes. When it has not, the sentence used to say "On
            //    this portal it is not sent afterwards either". The second
            //    check showed that untrue for a job that runs once an hour,
            //    a job that is late, and a job set up the next day: each
            //    sent the held texts at its next call. So that half now says
            //    what is known (the job has not run lately) and what a held
            //    text needs (the job to run before the text is two days
            //    old), and promises nothing either way.
            //
            //    WHAT IS NEVER HELD: a critical alert (the code that confirms
            //    a number is sent as one) and an emergency notice. The owner
            //    decided the second on 13 October 2026, "Never hold an
            //    emergency text"; the list is Sms::NEVER_HELD. The example of
            //    an out-of-date text used to be "a notice about that same
            //    morning", which is exactly the kind that is no longer held.
            //    tools/held-texts-selftest.php reads these lines in both
            //    states of the job: change the two together.
            //
            //    NO EXCEPTION. #514 part 9 was first built to hold its own
            //    notice (imported events waiting for approval) during quiet
            //    hours, and for a few hours on 13 October 2026 this sentence
            //    named that exception. The owner then decided "hold nothing":
            //    that notice is sent like every other e-mail, and so is its
            //    push. Do not add an exception here without that decision
            //    being changed.
            ?>
            <p class="small text-muted">
                E-mail from the portal is not held back during Sabbath quiet hours:
                it is sent as usual. Quiet hours apply to text messages. While they
                are active, a text message that is not urgent is not sent to you.
                <?php if (MailQueue::jobIsRunning($mysqli) === true): ?>
                    It is kept, and sent once your quiet hours have ended, unless by
                    then it is too old to be worth sending (nothing waits more than
                    two days).
                <?php else: ?>
                    It is kept. Whether it is sent afterwards depends on the portal's
                    scheduled job, which has not run in the last 15 minutes: the text
                    is sent only if that job runs before the text is two days old. An
                    administrator can check that the job is set up.
                <?php endif; ?>
                Three things are never held and are sent at once, whatever the hour:
                a critical alert, an emergency notice, and the code that confirms
                your number.
            </p>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="sabbathHonour" id="sabInherit" value="inherit" <?php echo $sabbathHonour === 'inherit' ? 'checked' : ''; ?>>
                <label class="form-check-label" for="sabInherit"><strong>Use the portal default</strong> (recommended)</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="sabbathHonour" id="sabOn"      value="on"      <?php echo $sabbathHonour === 'on'      ? 'checked' : ''; ?>>
                <label class="form-check-label" for="sabOn">Always honour Sabbath quiet hours for my notifications</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="sabbathHonour" id="sabOff"     value="off"     <?php echo $sabbathHonour === 'off'     ? 'checked' : ''; ?>>
                <label class="form-check-label" for="sabOff">Send notifications normally — don't apply quiet hours to me</label>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <button type="submit" class="btn btn-success">
        <i class="fa-solid fa-save me-1"></i> Save preferences
    </button>
    <a href="<?php echo htmlspecialchars(Site::url('account'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Cancel</a>
</form>

<?php if ($pushPublicKey !== ''): ?>
<script src="/assets/js/push-subscribe.js" defer></script>
<?php endif; ?>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
