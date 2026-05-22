<?php
// Path: public_html/admin/captcha/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Captcha Save Handler 💾
 * -----------------------------------------------------------------------------
 * POST handler for /admin/captcha. Two actions:
 *   • action=priority  — persists the drag-and-drop ordering (single setting)
 *   • action=keys      — persists site/secret keys + reCAPTCHA v2/v3 toggle
 *
 * All captcha settings are stored at siteID = NULL (global / shared across
 * all sites in this installation).
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.10.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Logger;
use Portal\Core\Router;

// 🛡️ Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/captcha', true, 302);
    exit();
}

// 🛡️ Admin only
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🔐 CSRF
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/captcha', true, 302);
    exit();
}

$action = (string) ($_POST['action'] ?? '');

/**
 * 🔧 Upsert a global (siteID = NULL) setting key/value.
 *
 * Encrypts the value first if isSensitive is true and the encrypt_setting()
 * helper is available.
 */
$upsert = static function (mysqli $db, string $key, string $value, bool $isSensitive): bool {
    if ($isSensitive === true && function_exists('encrypt_setting') === true && $value !== '') {
        $value = encrypt_setting($value);
    }
    $isSensInt = $isSensitive === true ? 1 : 0;

    // 🔎 Lookup existing row at global scope (siteID IS NULL)
    $stmt = $db->prepare('SELECT settingID FROM tblSettings WHERE settingKey = ? AND siteID IS NULL LIMIT 1');
    if ($stmt === false) {
        return false;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->bind_result($id);
    $exists = $stmt->fetch() === true;
    $stmt->close();

    if ($exists === true) {
        $stmt = $db->prepare(
            'UPDATE tblSettings SET settingValue = ?, isSensitive = ?, updatedAt = NOW() '
            . 'WHERE settingID = ?'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('sii', $value, $isSensInt, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    $stmt = $db->prepare(
        'INSERT INTO tblSettings (settingKey, settingValue, isSensitive, siteID, updatedAt) '
        . 'VALUES (?, ?, ?, NULL, NOW())'
    );
    if ($stmt === false) {
        return false;
    }
    $stmt->bind_param('ssi', $key, $value, $isSensInt);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
};

// -----------------------------------------------------------------------------
// 🪜 Save provider priority
// -----------------------------------------------------------------------------
if ($action === 'priority') {
    $rawPriority = (string) ($_POST['priority'] ?? '');
    $clean       = Captcha::normalisePriority($rawPriority);

    $ok = $upsert($mysqli, 'auth.captcha.priority', $clean, false);

    if ($ok === true) {
        Logger::activity('CaptchaPriorityUpdated', 'Set priority to: ' . $clean);
        $_SESSION['flash_msg']  = 'Captcha priority updated.';
        $_SESSION['flash_type'] = 'success';
    } else {
        Logger::errorPlatform('Settings', 'Error', 'CAPTCHA_PRIORITY_FAIL', $mysqli->error, '');
        $_SESSION['flash_msg']  = 'Failed to save captcha priority.';
        $_SESSION['flash_type'] = 'danger';
    }

    header('Location: /admin/captcha', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 🔑 Save provider keys
// -----------------------------------------------------------------------------
if ($action === 'keys') {
    $turnstileSite   = trim((string) ($_POST['turnstile_site']   ?? ''));
    $turnstileSecret = trim((string) ($_POST['turnstile_secret'] ?? ''));

    $recaptchaSite   = trim((string) ($_POST['recaptcha_site']   ?? ''));
    $recaptchaSecret = trim((string) ($_POST['recaptcha_secret'] ?? ''));
    $recaptchaVer    = (string) ($_POST['recaptcha_version'] ?? 'v2');
    $recaptchaV3Act  = trim((string) ($_POST['recaptcha_v3_action']    ?? 'submit'));
    $recaptchaV3Thr  = trim((string) ($_POST['recaptcha_v3_threshold'] ?? '0.5'));

    $hcaptchaSite    = trim((string) ($_POST['hcaptcha_site']   ?? ''));
    $hcaptchaSecret  = trim((string) ($_POST['hcaptcha_secret'] ?? ''));

    // 🛡️ Normalise enum + score values
    if ($recaptchaVer !== 'v2' && $recaptchaVer !== 'v3') {
        $recaptchaVer = 'v2';
    }
    if ($recaptchaV3Act === '') {
        $recaptchaV3Act = 'submit';
    }
    $thresholdFloat = (float) $recaptchaV3Thr;
    if ($thresholdFloat < 0.0) {
        $thresholdFloat = 0.0;
    }
    if ($thresholdFloat > 1.0) {
        $thresholdFloat = 1.0;
    }
    $recaptchaV3Thr = (string) $thresholdFloat;

    $writes = [
        ['auth.turnstile.siteKey',         $turnstileSite,   true],
        ['auth.turnstile.secretKey',       $turnstileSecret, true],
        ['auth.recaptcha.siteKey',         $recaptchaSite,   true],
        ['auth.recaptcha.secretKey',       $recaptchaSecret, true],
        ['auth.recaptcha.version',         $recaptchaVer,    false],
        ['auth.recaptcha.v3.action',       $recaptchaV3Act,  false],
        ['auth.recaptcha.v3.threshold',    $recaptchaV3Thr,  false],
        ['auth.hcaptcha.siteKey',          $hcaptchaSite,    true],
        ['auth.hcaptcha.secretKey',        $hcaptchaSecret,  true],
    ];

    $allOk = true;
    foreach ($writes as [$key, $value, $sensitive]) {
        if ($upsert($mysqli, (string) $key, (string) $value, (bool) $sensitive) === false) {
            Logger::errorPlatform('Settings', 'Error', 'CAPTCHA_KEY_FAIL', $mysqli->error, 'key=' . $key);
            $allOk = false;
        }
    }

    if ($allOk === true) {
        Logger::activity('CaptchaKeysUpdated', 'Captcha provider keys updated');
        $_SESSION['flash_msg']  = 'Captcha provider keys saved.';
        $_SESSION['flash_type'] = 'success';
    } else {
        $_SESSION['flash_msg']  = 'One or more captcha settings failed to save — see error log.';
        $_SESSION['flash_type'] = 'danger';
    }

    header('Location: /admin/captcha', true, 302);
    exit();
}

// 🚫 Unknown action — bounce back
header('Location: /admin/captcha', true, 302);
exit();
