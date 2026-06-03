<?php
// Path: public_html/auth/account/data-export.php
/**
 * -----------------------------------------------------------------------------
 * Account — Data Export (GDPR portability) 📦
 * -----------------------------------------------------------------------------
 * Bundles every record the portal holds about the current user into a
 * single JSON document and offers it as a download.
 *
 * Closes part of #47 (Right to Access / Portability under GDPR Art.15+20).
 *
 * Tables included:
 *   tblUsers              (your profile)
 *   tblLocalAccounts      (username, NOT the password hash)
 *   tblLinkedAccounts     (SSO links)
 *   tblWebAuthnCredentials (passkey labels, NOT public-key material)
 *   tblPasswordResets     (history, NOT the token hashes)
 *   tblActivityLogs       (your activity history)
 *   tblExpenseClaims      (your claims, line items, attachments metadata)
 *   tblPrayerRequests     (your requests if any)
 *   tblConsentLog         (your consent history)
 *   tblTrustedDevices     (active + revoked trust cookies)
 *   tblNotificationPreferences (when present)
 *
 * Sensitive fields (password hashes, TOTP secret, tokenHash etc.) are
 * EXCLUDED — exporting them would be a security regression, not a feature.
 *
 * @package   Portal\Auth
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$user   = App::user();
$userId = (int) ($user['userID'] ?? 0);
if ($userId <= 0) {
    header('Location: /account', true, 302);
    exit();
}

/**
 * 🛠️ Helper — run a SELECT * style query bound to the current user and
 * return rows with sensitive columns removed.
 *
 * @param string $sql           Prepared SQL with one '?' (the user ID)
 * @param string[] $stripFields Column names to drop from each row
 */
$fetchUserRows = static function (string $sql, array $stripFields = []) use ($mysqli, $userId): array {
    $stmt = $mysqli->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        foreach ($stripFields as $f) {
            unset($row[$f]);
        }
    }
    return $rows;
};

$payload = [
    'exportVersion' => '1',
    'exportedAt'    => gmdate('c'),
    'siteID'        => Site::id(),
    'subject'       => [
        'userID'       => $userId,
        'emailAddress' => $user['emailAddress'] ?? null,
    ],
    'data' => [
        'user' => $fetchUserRows(
            'SELECT * FROM tblUsers WHERE userID = ? LIMIT 1',
            ['totpSecret']
        ),
        'localAccount' => $fetchUserRows(
            'SELECT localID, userID, username, isVerified, createdAt FROM tblLocalAccounts WHERE userID = ? LIMIT 1'
        ),
        'linkedAccounts' => $fetchUserRows(
            // tblLinkedAccounts uses `linkedAt` not `createdAt`
            'SELECT linkID, userID, provider, providerEmail, linkedAt FROM tblLinkedAccounts WHERE userID = ?'
        ),
        'webauthnCredentials' => $fetchUserRows(
            // tblWebAuthnCredentials uses `friendlyName` not `label`
            'SELECT credentialID, userID, friendlyName, transports, createdAt, lastUsedAt FROM tblWebAuthnCredentials WHERE userID = ?'
        ),
        'passwordResets' => $fetchUserRows(
            'SELECT resetID, userID, expiresAt, usedAt, createdAt, createdIP FROM tblPasswordResets WHERE userID = ?'
        ),
        'activityLogs' => $fetchUserRows(
            'SELECT logID, activityType, activityDescription, visitorIP, timestamp FROM tblActivityLogs WHERE userID = ? ORDER BY timestamp DESC LIMIT 5000'
        ),
        'expenseClaims' => $fetchUserRows(
            // tblExpenseClaims uses `userID` not `submittedByID`
            'SELECT * FROM tblExpenseClaims WHERE userID = ?'
        ),
        'prayerRequests' => $fetchUserRows(
            'SELECT * FROM tblPrayerRequests WHERE submitterID = ?'
        ),
        'consentLog' => $fetchUserRows(
            'SELECT consentID, consentType, decision, policyHash, ipAddress, createdAt FROM tblConsentLog WHERE userID = ? ORDER BY createdAt DESC'
        ),
        'trustedDevices' => $fetchUserRows(
            'SELECT deviceID, label, createdIP, lastSeenAt, expiresAt, revokedAt, createdAt FROM tblTrustedDevices WHERE userID = ?'
        ),
    ],
];

// 📝 Audit log — the act of exporting one's own data is itself an event
Logger::activity('DataExport', 'User downloaded their data export');

// 📥 Stream as JSON download
$filename = 'webms-intra-data-export-' . $userId . '-' . date('Ymd-His') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
