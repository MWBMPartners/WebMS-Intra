<?php
// Path: public_html/invites/save.php
/**
 * Invite Onboarding — POST handler. Issues one invitation per email address,
 * stores SHA-256 hash, sends invite email when Mailer is configured.
 *
 * @package   Portal\Invites
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/239
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$emailsRaw = (string) ($_POST['emails'] ?? '');
$role      = (string) ($_POST['role'] ?? 'user');
$days      = max(1, min(90, (int) ($_POST['expiryDays'] ?? 7)));
$message   = trim((string) ($_POST['welcomeMessage'] ?? ''));

$emails = array_filter(
    array_map('trim', preg_split('/[\r\n,]+/', $emailsRaw) ?: []),
    static fn (string $e): bool => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) !== false
);
if (count($emails) === 0) {
    header('Location: /invites/new');
    exit();
}

$expiresAt = date('Y-m-d H:i:s', strtotime('+' . $days . ' days'));
$host      = $_SERVER['HTTP_HOST'] ?? 'portal';
$portalName = (string) (App::settings()['site']['name'] ?? 'the portal');
$siteUrl    = (string) (App::settings()['site']['url'] ?? ('https://' . $host));

$issued = 0;
foreach (array_unique($emails) as $email) {
    // Skip if an unredeemed unrevoked unexpired invite already exists.
    $stmt = $db->prepare(
        'SELECT 1 FROM tblInvitation '
        . 'WHERE siteID = ? AND email = ? AND acceptedAt IS NULL AND revokedAt IS NULL AND expiresAt > NOW() '
        . 'LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('is', $siteId, $email);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        if ($exists === true) {
            continue;
        }
    }

    $plaintext = bin2hex(random_bytes(32));
    $hash      = hash('sha256', $plaintext);

    try {
        $stmt = $db->prepare(
            'INSERT INTO tblInvitation (siteID, email, tokenHash, intendedRole, welcomeMessage, expiresAt, createdByID) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt !== false) {
            $msg = $message !== '' ? $message : null;
            $stmt->bind_param('isssssi', $siteId, $email, $hash, $role, $msg, $expiresAt, $userId);
            $stmt->execute();
            $stmt->close();
            $issued++;
        }
    } catch (\Throwable $e) {
        \Portal\Core\Logger::errorPlatform('Invites', 'Warning', 'SAVE', $e->getMessage(), '');
        continue;
    }

    // Send invite email via Mailer + templates/email/invite.html.php (#243).
    if (class_exists('Portal\\Core\\Mailer') === true) {
        try {
            \Portal\Core\Mailer::sendTemplated(
                $email,
                'You\'re invited to ' . $portalName,
                'invite',
                [
                    'inviterName' => '',
                    'portalName'  => $portalName,
                    'inviteUrl'   => rtrim($siteUrl, '/') . '/auth/invite?token=' . $plaintext,
                    'expiresAt'   => $days . ' days',
                    'message'     => $message,
                ]
            );
        } catch (\Throwable $ignored) {
            // Mailer is best-effort here; the invite row exists and the admin
            // can copy the URL from /invites if email isn't configured.
        }
    }
}

$_SESSION['flash_msg']  = sprintf('Issued %d invitation(s).', $issued);
$_SESSION['flash_type'] = 'success';
header('Location: /invites');
exit();
