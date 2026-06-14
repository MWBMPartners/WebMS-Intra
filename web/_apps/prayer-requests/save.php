<?php
// Path: public_html/prayer-requests/save.php
/**
 * -----------------------------------------------------------------------------
 * Prayer Requests — Save Handler (Logged-in) 🙏
 * -----------------------------------------------------------------------------
 * POST endpoint for logged-in members submitting a new prayer request.
 *   - Verifies CSRF
 *   - Validates subject / body / visibility / anonymous flag
 *   - Honours requireModeration setting (pending vs active)
 *   - Honours allowCongregationFeed setting (downgrades to leadership if off)
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
use Portal\Core\Logger;
use Portal\Core\Site;

// 🛡️ Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /prayer-requests/submit', true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();

// 🚦 Feature gate
if ((App::settings('prayer-requests.enabled') ?? 'true') !== 'true') {
    header('Location: /prayer-requests', true, 302);
    exit();
}

// 🔐 CSRF
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activity('PrayerRequestRejected', 'Invalid CSRF on prayer-requests/save');
    header('Location: /prayer-requests/submit?err=' . urlencode('Security check failed. Please try again.'), true, 302);
    exit();
}

// 📥 Input
$subject     = trim((string) ($_POST['subject'] ?? ''));
$body        = trim((string) ($_POST['body'] ?? ''));
$visibility  = (string) ($_POST['visibility'] ?? 'leadership');
$isAnonymous = isset($_POST['isAnonymous']) === true && $_POST['isAnonymous'] === '1' ? 1 : 0;

// 🛡️ Validate
if ($subject === '' || $body === '') {
    header('Location: /prayer-requests/submit?err=' . urlencode('Subject and request text are required.'), true, 302);
    exit();
}
if (mb_strlen($subject) > 255) {
    $subject = mb_substr($subject, 0, 255);
}
if (mb_strlen($body) > 4000) {
    $body = mb_substr($body, 0, 4000);
}
if ($visibility !== 'leadership' && $visibility !== 'congregation') {
    $visibility = 'leadership';
}

// 🔒 Downgrade visibility if congregation feed is disabled site-wide
if ($visibility === 'congregation'
    && (App::settings('prayer-requests.allowCongregationFeed') ?? 'true') !== 'true'
) {
    $visibility = 'leadership';
}

// 🚦 Decide initial status based on moderation setting
$requireModeration = (App::settings('prayer-requests.requireModeration') ?? 'true') === 'true';
$initialStatus     = $requireModeration === true ? 'pending' : 'active';

// 🌐 Capture context
$siteId = Site::id();
$user   = App::user();
$userId = (int) ($user['userID'] ?? 0);

$ip = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR']
    ?? '';
if (str_contains((string) $ip, ',') === true) {
    $ip = trim(explode(',', (string) $ip)[0]);
}
$ip = mb_substr((string) $ip, 0, 45);

// 💾 Insert
$stmt = $mysqli->prepare(
    'INSERT INTO tblPrayerRequests '
    . '(siteID, submitterID, submitterIP, subject, body, visibility, status, isAnonymous) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'PR_INSERT_PREP', $mysqli->error, '');
    header('Location: /prayer-requests/submit?err=' . urlencode('Something went wrong. Please try again.'), true, 302);
    exit();
}

$stmt->bind_param(
    'iissssi' . 'i',
    $siteId,
    $userId,
    $ip,
    $subject,
    $body,
    $visibility,
    $initialStatus,
    $isAnonymous
);
$ok = $stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();

if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'PR_INSERT_FAIL', $mysqli->error, '');
    header('Location: /prayer-requests/submit?err=' . urlencode('Failed to save your request. Please try again.'), true, 302);
    exit();
}

Logger::activity('PrayerRequestSubmitted', 'Request #' . $newId . ' submitted (status=' . $initialStatus . ')');

// 🪝 Outbound webhook (#324) — fire-and-forget. Never blocks the user flow.
\Portal\Core\WebhookDispatcher::emit('prayer-requests.created', [
    'requestID'    => $newId,
    'subject'      => $subject,
    'isAnonymous'  => $isAnonymous === 1,
    'status'       => $initialStatus,
    'visibility'   => $visibility,
]);

// 🔀 Redirect — pending shows a "thanks, awaiting review" message; active jumps straight to view
if ($initialStatus === 'pending') {
    header('Location: /prayer-requests?submitted=1', true, 302);
} else {
    header('Location: /prayer-requests/view?id=' . $newId, true, 302);
}
exit();
