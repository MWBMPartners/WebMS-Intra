<?php
// Path: public_html/admin/integrations/zoom/callback.php
/**
 * Admin — Zoom OAuth callback. Validates state, exchanges the code for
 * tokens, fetches the Zoom user profile, and persists an encrypted
 * tblZoomAccount row (org-level or per-user).
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/274
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;
use Portal\Core\Zoom;

Auth::ensureSession();
Auth::requireLogin();

$code         = (string) ($_GET['code'] ?? '');
$state        = (string) ($_GET['state'] ?? '');
$expectedSt   = (string) ($_SESSION['zoom_oauth_state'] ?? '');
$mode         = (string) ($_SESSION['zoom_oauth_mode']  ?? 'org');
unset($_SESSION['zoom_oauth_state'], $_SESSION['zoom_oauth_mode']);

$returnTo = $mode === 'user' ? '/account/integrations/zoom' : '/admin/integrations/zoom';

if ($code === '' || $state === '' || $expectedSt === '' || hash_equals($expectedSt, $state) === false) {
    $_SESSION['flash_msg']  = 'Zoom OAuth: invalid state or missing code.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $returnTo);
    exit();
}

$settings     = App::settings()['zoom'] ?? [];
$clientId     = (string) ($settings['clientID'] ?? '');
$clientSecret = (string) ($settings['clientSecret'] ?? '');

$scheme = (($_SERVER['HTTPS'] ?? '') !== '' && (string) ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http';
$redirect = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/admin/integrations/zoom/callback';

$tokens = Zoom::exchangeCode($clientId, $clientSecret, $code, $redirect);
if ($tokens === null || isset($tokens['access_token']) === false) {
    $_SESSION['flash_msg']  = 'Zoom token exchange failed.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $returnTo);
    exit();
}

$me = Zoom::fetchMe((string) $tokens['access_token']);
if ($me === null) {
    $_SESSION['flash_msg']  = 'Zoom profile fetch failed.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $returnTo);
    exit();
}

$db        = App::db();
$siteId    = Site::id();
$userId    = $mode === 'user' ? (int) ($_SESSION['user_id'] ?? 0) : null;
$zoomUid   = (string) ($me['id'] ?? '');
$zoomEmail = (string) ($me['email'] ?? '');
$scope     = (string) ($tokens['scope'] ?? '');
$expiresAt = time() + (int) ($tokens['expires_in'] ?? 3599);
$accessEnc  = encrypt_setting((string) $tokens['access_token']);
$refreshEnc = encrypt_setting((string) ($tokens['refresh_token'] ?? ''));

// Upsert by (siteID, userID). UNIQUE constraint disambiguates null userIDs.
$stmt = $db->prepare(
    'SELECT accountID FROM tblZoomAccount WHERE siteID = ? AND '
    . ($userId === null ? 'userID IS NULL' : 'userID = ?')
    . ' LIMIT 1'
);
$existingId = 0;
if ($stmt !== false) {
    if ($userId === null) {
        $stmt->bind_param('i', $siteId);
    } else {
        $stmt->bind_param('ii', $siteId, $userId);
    }
    $stmt->execute();
    $stmt->bind_result($existingId);
    $stmt->fetch();
    $stmt->close();
}

if ($existingId > 0) {
    $u = $db->prepare(
        'UPDATE tblZoomAccount SET zoomUserId = ?, zoomAccountEmail = ?, refreshTokenEnc = ?, '
        . 'accessTokenEnc = ?, accessTokenExpiresAt = FROM_UNIXTIME(?), scopes = ? WHERE accountID = ?'
    );
    if ($u !== false) {
        $u->bind_param('ssssisi', $zoomUid, $zoomEmail, $refreshEnc, $accessEnc, $expiresAt, $scope, $existingId);
        $u->execute();
        $u->close();
    }
} else {
    $i = $db->prepare(
        'INSERT INTO tblZoomAccount (siteID, userID, zoomUserId, zoomAccountEmail, refreshTokenEnc, accessTokenEnc, accessTokenExpiresAt, scopes) '
        . 'VALUES (?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), ?)'
    );
    if ($i !== false) {
        $i->bind_param('iissssis', $siteId, $userId, $zoomUid, $zoomEmail, $refreshEnc, $accessEnc, $expiresAt, $scope);
        $i->execute();
        $i->close();
    }
}

$_SESSION['flash_msg']  = 'Zoom account connected: ' . $zoomEmail;
$_SESSION['flash_type'] = 'success';
header('Location: ' . $returnTo);
exit();
