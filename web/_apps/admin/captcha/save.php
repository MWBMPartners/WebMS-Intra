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
 * WHO MAY SAVE HERE
 * -----------------------------------------------------------------------------
 * Every setting this handler saves is portal-wide: it is written with nothing
 * in the siteID column, so it is the value EVERY organisation on this
 * installation uses. These are the keys for the "are you a person?" check
 * (captcha) that protects sign-in and the public forms, and the order the
 * providers are tried in.
 *
 * Until 11 September 2026 the only check was App::isAdmin(), which is true for
 * an administrator of a SINGLE organisation as well as for a global
 * administrator (see web/_core/App.php). So an administrator of any one
 * organisation could remove that protection for every organisation, or swap in
 * keys of their own. The owner decided that settings affecting every
 * organisation are for a global administrator only. Anybody else is now
 * refused, is told why on screen, and nothing is saved.
 *
 * AN EMPTY SECRET-KEY BOX KEEPS THE STORED KEY (#597, 12 October 2026)
 * -----------------------------------------------------------------------------
 * The page no longer writes a secret key into its boxes, so an empty box can
 * no longer mean "wipe it". The rule is beside the write, in the `keys` action.
 *
 * A STORED KEY THE PORTAL CANNOT READ IS NEVER REPLACED BY AN EMPTY BOX
 * (13 October 2026). The page draws both boxes of such a provider empty, so
 * pressing Save used to empty both keys and switch the bot check off without
 * a word. The rule, and why, is in the same place.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.13.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\SecretSettings;

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

// 🚧 Portal-wide settings are for a global administrator only — see "WHO MAY
//    SAVE HERE" in the file header. The refusal gives the reason rather than a
//    bare "forbidden": an administrator who presses Save and is told nothing
//    assumes the portal is broken and tries again. It comes after the
//    form-token check, so a forged request from another website cannot fill
//    the activity log with refusals.
//
//    What this cannot do on its own: the captcha page
//    (web/_apps/admin/captcha/index.php) now shows this form read-only to such
//    an administrator, with the reason. But a form can be sent without ever
//    opening the page, so this refusal is what actually enforces the rule.
if (App::isRootAdmin() === false) {
    Logger::activity(
        'SettingsGroupSaveRefused',
        'Refused: portal-wide settings group "captcha" may only be changed by a global administrator',
        $_SESSION['user_id'] ?? null
    );
    $_SESSION['flash_msg']  = 'These settings are portal-wide: they apply to every '
        . 'organisation on this installation, not only yours. Only a global '
        . 'administrator can change them. Your own organisation\'s settings '
        . 'are on the main Settings page and are unaffected.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/captcha', true, 302);
    exit();
}

$action = (string) ($_POST['action'] ?? '');

/**
 * 🔧 Upsert a global (siteID = NULL) setting key/value.
 *
 * A secret (isSensitive true) is encrypted first, through
 * SecretSettings::seal(). If it cannot be encrypted, NOTHING is written and
 * false comes back.
 *
 * WHAT WAS HERE: `encrypt_setting($value)` called directly, and only "if the
 * helper is available". That helper throws when the portal's key file is
 * missing, so saving ended in the portal's error page. That mattered little
 * until #497: since then this page is where an administrator is sent to type
 * the keys in again when the stored ones cannot be read, and a missing key
 * file is one of the ways to get there. And had the helper ever not been
 * there, the key would have been stored as readable text with the mark on.
 */
$upsert = static function (mysqli $db, string $key, string $value, bool $isSensitive): bool {
    if ($isSensitive === true && $value !== '') {
        $sealed = SecretSettings::seal($value);
        if ($sealed === null) {
            return false;
        }
        $value = $sealed;
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
        ['auth.recaptcha.version',         $recaptchaVer,    false],
        ['auth.recaptcha.v3.action',       $recaptchaV3Act,  false],
        ['auth.recaptcha.v3.threshold',    $recaptchaV3Thr,  false],
    ];

    // 🔑 Each provider's two keys.
    //
    //    THE SECRET KEY: an empty box keeps what is stored (#597).
    //    WHAT WAS HERE: all three were written on every save, with whatever
    //    the form sent. That was right only while the page filled each box
    //    with the stored key. The page no longer writes a secret key into its
    //    boxes (web/_apps/admin/captcha/index.php), so they arrive empty
    //    unless somebody typed in them, and writing an empty value would wipe
    //    the key every time a global administrator changed anything else here.
    //    A provider with no secret key counts as not set up, so the "are you
    //    a person?" check would have gone off without anybody choosing that.
    //
    //    THE RULE, for a provider whose stored keys the portal CAN read:
    //      - something typed          → it replaces the stored key;
    //      - empty, site key filled   → the stored key is kept (nothing written);
    //      - empty, site key empty    → the stored key is removed. This is how
    //                                   a provider is taken out of use, and the
    //                                   page says so.
    //
    //    🚫 A STORED KEY THE PORTAL CANNOT READ IS NEVER REPLACED BY AN EMPTY
    //    BOX (13 October 2026). WHAT WAS WRONG, found by running it: the page
    //    draws BOTH boxes empty for a provider whose keys cannot be read (what
    //    is stored is a scramble, and showing it would invite somebody to save
    //    it back). So "both boxes empty" arrived from the form AS DRAWN, the
    //    rule above read it as "take this provider out of use", and one press
    //    of Save emptied both keys. The bot check then went off on every
    //    form, the warnings stopped (nothing was unreadable any more), and
    //    putting the key file back could no longer bring the keys back: the
    //    opposite of the owner's decision that public forms are refused in
    //    that state. So, for a provider with a stored key that cannot be read:
    //      - both boxes empty         → NEITHER key is written, and the page
    //                                   says so;
    //      - a key typed              → it replaces the stored one, as above;
    //      - site box empty, secret   → the unreadable site key is left as
    //        typed                      it is. Emptying it would leave a
    //                                   secret key with no site key, which
    //                                   counts as "not set up": the same
    //                                   silent switch-off by another road.
    //    Such a provider stays "Cannot be read" until BOTH keys have been
    //    typed again, and the warnings stay with it.
    //
    //    WHAT THIS CANNOT DO: take a provider whose keys cannot be read out
    //    of use from this page. That is done on the Settings page, where each
    //    key has a "Remove the stored value" tick; the message below says so.
    //    SecretSettings::isUnreadable() goes by what was loaded at the start
    //    of THIS request, so two administrators saving at the same moment
    //    are not told about each other.
    $leftAlone = [];
    foreach ([
        ['Cloudflare Turnstile', 'auth.turnstile.siteKey', $turnstileSite, 'auth.turnstile.secretKey', $turnstileSecret],
        ['Google reCAPTCHA',     'auth.recaptcha.siteKey', $recaptchaSite, 'auth.recaptcha.secretKey', $recaptchaSecret],
        ['hCaptcha',             'auth.hcaptcha.siteKey',  $hcaptchaSite,  'auth.hcaptcha.secretKey',  $hcaptchaSecret],
    ] as [$providerLabel, $siteName, $siteKeyNow, $secretName, $secretTyped]) {
        $siteUnreadable = SecretSettings::isUnreadable($siteName);
        if (($siteUnreadable === true || SecretSettings::isUnreadable($secretName) === true) && $siteKeyNow === '' && $secretTyped === '') {
            $leftAlone[] = $providerLabel . ': nothing was changed, because one of its stored keys cannot be read and both of its boxes were empty.';
            continue;
        }
        if ($siteKeyNow !== '' || $siteUnreadable === false) {
            $writes[] = [$siteName, $siteKeyNow, true];
        } else {
            $leftAlone[] = $providerLabel . ': its stored site key cannot be read and its box was empty, so it was left as it is.';
        }
        if ($secretTyped !== '' || $siteKeyNow === '') {
            $writes[] = [$secretName, $secretTyped, true];
        }
    }

    // 🔑 If a key was typed and the portal cannot encrypt, say so plainly and
    //    write NOTHING, not even the settings that are not secrets: a page
    //    that saved half of what was sent would have to explain which half.
    //    seal() answers null when the key file is missing, empty or cannot be read.
    $keyTyped = false;
    foreach ($writes as [$key, $value, $sensitive]) {
        $keyTyped = $keyTyped === true || ($sensitive === true && $value !== '');
    }
    if ($keyTyped === true && SecretSettings::seal('can the portal encrypt at all?') === null) {
        Logger::activity('CaptchaKeysRefused', 'Captcha provider keys were not saved: the portal could not encrypt them');
        $_SESSION['flash_msg']  = 'Nothing was saved. The keys are secrets, and the portal could not encrypt them: its key file '
            . '(enc.key, in the _auth_keys folder) is missing, is empty or cannot be read. Put that file back and save again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/captcha', true, 302);
        exit();
    }

    $allOk = true;
    foreach ($writes as [$key, $value, $sensitive]) {
        if ($upsert($mysqli, (string) $key, (string) $value, (bool) $sensitive) === false) {
            Logger::errorPlatform('Settings', 'Error', 'CAPTCHA_KEY_FAIL', $mysqli->error, 'key=' . $key);
            $allOk = false;
        }
    }

    if ($allOk === true && $leftAlone !== []) {
        // 🚫 Something was deliberately NOT written (see "A STORED KEY THE
        //    PORTAL CANNOT READ" above). Said in words, as a warning, so that
        //    "saved" is never read as "the empty boxes were saved too".
        Logger::activity('CaptchaKeysUpdated', 'Captcha provider keys updated; stored keys that cannot be read were left as they are');
        $_SESSION['flash_msg']  = 'Saved, except for this. ' . implode(' ', $leftAlone)
            . ' An empty box never removes a key the portal cannot read. To put it right, type BOTH of that provider\'s keys'
            . ' in again. To stop using that provider instead, remove its two keys on the Settings page (edit each one and tick'
            . ' "Remove the stored value").';
        $_SESSION['flash_type'] = 'warning';
    } elseif ($allOk === true) {
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
