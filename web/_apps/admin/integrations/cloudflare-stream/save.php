<?php
// Path: _apps/admin/integrations/cloudflare-stream/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Cloudflare Stream credentials save handler 💾 (#386 Phase 1)
 * -----------------------------------------------------------------------------
 * Per-key upsert (mirrors admin/integrations/zoom/save.php): sensitive
 * values (`apiToken`, `signingKeyPem`) are encrypted at rest via
 * `encrypt_setting()` and a blank submitted value PRESERVES the existing
 * secret rather than clearing it — the admin page never re-displays a
 * secret once saved, so there is nothing to "clear back to" except a
 * deliberate blank-then-resave, which this form doesn't offer (matches
 * the Zoom precedent).
 *
 * `accountID` / `customerCode` are validated server-side and the WHOLE
 * save is rejected (nothing persisted) on failure — `customerCode` feeds
 * directly into the page-scoped CSP `frame-src` extension
 * (VideoEmbed::frameSrcOrigins()), so a malformed value must never reach
 * the database.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/386
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$redirect = '/admin/integrations/cloudflare-stream';

/**
 * 🚩 Flash an error and redirect back WITHOUT saving anything.
 */
$reject = static function (string $message) use ($redirect): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $redirect, true, 302);
    exit();
};

$enabled                  = isset($_POST['enabled']) === true ? 'true' : 'false';
$accountId                = trim((string) ($_POST['accountID'] ?? ''));
$customerCode             = strtolower(trim((string) ($_POST['customerCode'] ?? '')));
$apiToken                 = trim((string) ($_POST['apiToken'] ?? ''));
$signingKeyId             = trim((string) ($_POST['signingKeyID'] ?? ''));
$signingKeyPem            = trim((string) ($_POST['signingKeyPem'] ?? ''));
$tokenTtlSeconds          = (int) ($_POST['tokenTtlSeconds'] ?? 21600);
$maxUploadDurationSeconds = (int) ($_POST['maxUploadDurationSeconds'] ?? 3600);
$uploadMintPerHour        = (int) ($_POST['uploadMintPerHour'] ?? 20);
$defaultRequireSigned     = isset($_POST['defaultRequireSignedUrls']) === true ? 'true' : 'false';
$allowedOriginsRaw        = trim((string) ($_POST['allowedOrigins'] ?? ''));

// 🛡️ accountID — 32-character hex, matching Cloudflare's account ID shape.
if ($accountId !== '' && preg_match('/^[0-9a-f]{32}$/i', $accountId) !== 1) {
    $reject('Account ID must be 32 hex characters.');
}

// 🛡️ customerCode — feeds the page-scoped CSP frame-src extension
//    (VideoEmbed::frameSrcOrigins()) verbatim, so it MUST be restricted to
//    a safe character class before it ever reaches the database.
if ($customerCode !== '' && preg_match('/^[a-z0-9-]+$/', $customerCode) !== 1) {
    $reject('Customer code may only contain lowercase letters, digits, and hyphens.');
}

// 🛡️ allowedOrigins — comma-separated bare hostnames, capped at 10.
$allowedOrigins = '';
if ($allowedOriginsRaw !== '') {
    $parts = array_filter(array_map('trim', explode(',', $allowedOriginsRaw)));
    $parts = array_slice(array_values($parts), 0, 10);
    foreach ($parts as $part) {
        if (preg_match('/^[a-z0-9.-]+$/i', $part) !== 1) {
            $reject('Allowed origins must be bare hostnames (no scheme, no path).');
        }
    }
    $allowedOrigins = implode(',', array_map('strtolower', $parts));
}

// 🔢 Numeric fields — clamp to sane bounds rather than reject; these are
//    tuning knobs, not security-critical inputs.
if ($tokenTtlSeconds < 60 || $tokenTtlSeconds > 86400) {
    $tokenTtlSeconds = 21600;
}
if ($maxUploadDurationSeconds < 1 || $maxUploadDurationSeconds > 21600) {
    $maxUploadDurationSeconds = 3600;
}
if ($uploadMintPerHour < 1 || $uploadMintPerHour > 1000) {
    $uploadMintPerHour = 20;
}

$db = App::db();

// Per-key upsert; sensitive values encrypted, blanks preserve existing.
$upsert = static function (mysqli $db, string $key, string $value, bool $isSensitive): void {
    if ($isSensitive === true && $value !== '' && function_exists('encrypt_setting') === true) {
        $value = encrypt_setting($value);
    }
    $sens = $isSensitive === true ? 1 : 0;
    $stmt = $db->prepare('SELECT settingID FROM tblSettings WHERE settingKey = ? AND siteID IS NULL LIMIT 1');
    if ($stmt === false) {
        return;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->bind_result($id);
    $exists = $stmt->fetch() === true;
    $stmt->close();
    if ($exists === true) {
        $u = $db->prepare('UPDATE tblSettings SET settingValue = ?, isSensitive = ?, updatedAt = NOW() WHERE settingID = ?');
        if ($u !== false) {
            $u->bind_param('sii', $value, $sens, $id);
            $u->execute();
            $u->close();
        }
    } else {
        $u = $db->prepare('INSERT INTO tblSettings (settingKey, settingValue, isSensitive, siteID, updatedAt) VALUES (?, ?, ?, NULL, NOW())');
        if ($u !== false) {
            $u->bind_param('ssi', $key, $value, $sens);
            $u->execute();
            $u->close();
        }
    }
};

$upsert($db, 'cfstream.enabled',                  $enabled,                  false);
$upsert($db, 'cfstream.accountID',                $accountId,                false);
$upsert($db, 'cfstream.customerCode',             $customerCode,             false);
$upsert($db, 'cfstream.signingKeyID',             $signingKeyId,             false);
$upsert($db, 'cfstream.tokenTtlSeconds',          (string) $tokenTtlSeconds, false);
$upsert($db, 'cfstream.maxUploadDurationSeconds', (string) $maxUploadDurationSeconds, false);
$upsert($db, 'cfstream.uploadMintPerHour',        (string) $uploadMintPerHour, false);
$upsert($db, 'cfstream.defaultRequireSignedUrls', $defaultRequireSigned,     false);
$upsert($db, 'cfstream.allowedOrigins',           $allowedOrigins,           false);

// 🔒 Secrets — only touched when a new value was actually submitted;
//    blank input preserves whatever is already encrypted at rest, and
//    NEITHER is ever logged or echoed back.
if ($apiToken !== '') {
    $upsert($db, 'cfstream.apiToken', $apiToken, true);
}
if ($signingKeyPem !== '') {
    $upsert($db, 'cfstream.signingKeyPem', $signingKeyPem, true);
}

Logger::activity('CfStreamSettingsSaved', 'Cloudflare Stream integration settings updated');

$_SESSION['flash_msg']  = 'Cloudflare Stream settings saved.';
$_SESSION['flash_type'] = 'success';
header('Location: ' . $redirect, true, 302);
exit();
