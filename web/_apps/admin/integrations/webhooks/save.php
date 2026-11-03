<?php
// Path: _apps/admin/integrations/webhooks/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Create/update webhook POST (#324)
 * -----------------------------------------------------------------------------
 * Two modes, distinguished by presence of a positive `webhookID`:
 *
 *   (a) CREATE — validates name / targetUrl / eventTypes, mints a fresh
 *       HMAC signing secret (bin2hex(random_bytes(32))), INSERTs the row,
 *       and flashes the plaintext secret ONCE for index.php to display.
 *
 *   (b) UPDATE — re-validates and UPDATEs an existing webhook scoped to
 *       this site. Only fields explicitly present in the POST body
 *       override the stored value, so index.php's "pause/reactivate"
 *       button can post just `webhookID` + `isActive` without clobbering
 *       name/targetUrl/eventTypes on a simple toggle.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/324
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\InstallationSettings;
use Portal\Core\SettingLimit;
use Portal\Core\Site;
use Portal\Core\WebhookRetention;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('admin/integrations/webhooks'), true, 302);
    exit();
}

// 🛡️ Admin gate — identical to api-keys-save.php.
Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

/**
 * 🔍 Validate name + targetUrl. Returns an error string, or '' if OK.
 *
 * @param string $name      Webhook display name (≤100 chars, required).
 * @param string $targetUrl Delivery URL (required, http(s) only, ≤500 chars).
 */
$validateFields = static function (string $name, string $targetUrl): string {
    if ($name === '') {
        return 'Name is required.';
    }
    if (mb_strlen($name) > 100) {
        return 'Name must be 100 characters or fewer.';
    }
    if ($targetUrl === '') {
        return 'Target URL is required.';
    }
    if (mb_strlen($targetUrl) > 500) {
        return 'Target URL must be 500 characters or fewer.';
    }
    $validated = filter_var($targetUrl, FILTER_VALIDATE_URL);
    if ($validated === false) {
        return 'Target URL must be a valid URL.';
    }
    $scheme = parse_url($targetUrl, PHP_URL_SCHEME);
    if (in_array($scheme, ['http', 'https'], true) === false) {
        return 'Target URL must use http:// or https://.';
    }
    return '';
};

// ============================================================================
// (a2) HOW LONG A PAYLOAD IS KEPT (#713)
// ============================================================================
// A separate action, because it saves a SETTING and not a webhook. Two levels:
// this organisation's own figure, and - for a global administrator only - the
// installation's ceiling that every organisation is held to.
//
// WHY THE LEVELS ARE DIFFERENT PARTIES. Whoever administers the installation
// may be a different person from the administrators of the organisations on it
// (a hosting provider and its customers is the ordinary case). So for a LIMIT
// the installation's value takes precedence: an organisation may hold itself to
// a shorter period and may never choose a longer one than the installation
// allows. `Portal\Core\WebhookRetention` is the one place that decides it, and
// it asks `Portal\Core\SettingLimit`.
if ((string) ($_POST['action'] ?? '') === 'payload_retention') {
    $back = Site::url('admin/integrations/webhooks');

    // 🪤 ASK WHETHER THE FIELD ARRIVED, NEVER WHAT IT CONTAINS (#677, #667).
    //    The installation box is not rendered at all for an ordinary
    //    administrator, and a control that is not rendered is not submitted.
    //    Reading it with `?? ''` would store an empty period for EVERY
    //    organisation the moment an ordinary administrator saved their own.
    $ownArrived   = array_key_exists('ownDays', $_POST);
    $instArrived  = array_key_exists('installationDays', $_POST);
    $isGlobal     = App::isRootAdmin();

    if ($instArrived === true && $isGlobal === false) {
        // Somebody posted a field the page never offered them.
        Logger::activity(
            'WebhookRetentionRefused',
            'An installation-wide webhook retention period was posted by an administrator who is not '
            . 'a global administrator; nothing was saved',
            $userId
        );
        $_SESSION['flash_msg']  = 'Only a global administrator can set the installation\'s own period. '
            . 'Nothing was saved.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $back);
        exit();
    }

    // 🏛️ The installation's ceiling first, so that the organisation's figure
    //    below is judged against what was just chosen rather than against what
    //    it used to be.
    if ($instArrived === true) {
        $instDays = (int) $_POST['installationDays'];
        if ($instDays < 0 || $instDays > WebhookRetention::HARD_MAX_DAYS) {
            $_SESSION['flash_msg']  = 'The installation\'s period must be between 0 and '
                . WebhookRetention::HARD_MAX_DAYS . ' days, where 0 means "keep for ever". Nothing was saved.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $back);
            exit();
        }
        $up = $mysqli->prepare(
            'INSERT INTO tblSettings (siteID, settingKey, settingValue) VALUES (NULL, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue)'
        );
        if ($up !== false) {
            $key   = WebhookRetention::KEY;
            $value = (string) $instDays;
            $up->bind_param('ss', $key, $value);
            $up->execute();
            $up->close();
        }
        // 🔄 THE LAYER HAS TO BE TOLD, IN THIS REQUEST, and that is not
        //    tidiness: the organisation's figure below is judged by
        //    `WebhookRetention::whyNotAllowed()`, which reads the installation
        //    layer. Leave the layer stale and a global administrator who
        //    lowered the ceiling and set their organisation's figure in one
        //    press would have the figure judged against the OLD ceiling — #687's
        //    fault exactly, where a gate compared against the request's
        //    settings snapshot instead of the live value.
        //
        //    There is no `InstallationSettings::put()`; `remember()` replaces
        //    the whole layer, so the key is set inside a copy of it.
        $layer = InstallationSettings::layer();
        $node  = &$layer;
        $parts = explode('.', WebhookRetention::KEY);
        $last  = array_pop($parts);
        foreach ($parts as $part) {
            if (isset($node[$part]) === false || is_array($node[$part]) === false) {
                $node[$part] = [];
            }
            $node = &$node[$part];
        }
        $node[$last] = (string) $instDays;
        unset($node);
        InstallationSettings::remember($layer);
    }

    if ($ownArrived === true) {
        $ownDays = (int) $_POST['ownDays'];

        // 🚫 REFUSED WITH THE REASON, NEVER CLAMPED (#668). Silently reducing a
        //    number somebody else typed is how a person comes to believe a
        //    period is in force when it is not.
        $why = WebhookRetention::whyNotAllowed($ownDays);
        if ($why !== '') {
            Logger::activity(
                'WebhookRetentionRefused',
                'A webhook retention period of ' . $ownDays . ' day(s) was refused for organisation #'
                . $siteId . ' because the installation allows less; nothing was saved',
                $userId
            );
            $_SESSION['flash_msg']  = SettingLimit::refusalWords(
                $why,
                WebhookRetention::installationAllows(),
                'days'
            ) . ' Nothing was saved.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $back);
            exit();
        }

        $up = $mysqli->prepare(
            'INSERT INTO tblSettings (siteID, settingKey, settingValue) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue)'
        );
        if ($up !== false) {
            $key   = WebhookRetention::KEY;
            $value = (string) $ownDays;
            $up->bind_param('iss', $siteId, $key, $value);
            $up->execute();
            $up->close();
        }
    }

    Logger::activity(
        'WebhookRetentionSaved',
        'Webhook payload retention saved'
        . ($ownArrived === true ? '; organisation #' . $siteId . ' = ' . (int) $_POST['ownDays'] . ' day(s)' : '')
        . ($instArrived === true ? '; installation = ' . (int) $_POST['installationDays'] . ' day(s)' : ''),
        $userId
    );
    $_SESSION['flash_msg']  = 'How long webhook payloads are kept has been saved. Payloads past the '
        . 'period are emptied by the nightly clear-out; the rows themselves stay, so delivery history '
        . 'and the replay guard are untouched.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . $back);
    exit();
}

$webhookId = (int) ($_POST['webhookID'] ?? 0);

// ============================================================================
// (b) UPDATE — existing webhook, scoped to this site
// ============================================================================
if ($webhookId > 0) {
    $stmt = $mysqli->prepare('SELECT name, eventTypes, targetUrl, isActive FROM tblWebhooks WHERE webhookID = ? AND siteID = ?');
    $stmt->bind_param('ii', $webhookId, $siteId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing === null) {
        http_response_code(404);
        exit('Webhook not found');
    }

    $name       = isset($_POST['name']) === true ? trim((string) $_POST['name']) : (string) $existing['name'];
    $targetUrl  = isset($_POST['targetUrl']) === true ? trim((string) $_POST['targetUrl']) : (string) $existing['targetUrl'];
    $eventTypes = isset($_POST['eventTypes']) === true ? trim((string) $_POST['eventTypes']) : (string) $existing['eventTypes'];
    if ($eventTypes === '') {
        $eventTypes = 'all';
    }
    $isActive = ((string) ($_POST['isActive'] ?? (string) $existing['isActive']) === '1') ? 1 : 0;

    $error = $validateFields($name, $targetUrl);
    if ($error !== '') {
        $_SESSION['flash_msg']  = $error;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/integrations/webhooks'), true, 302);
        exit();
    }

    $stmt = $mysqli->prepare(
        'UPDATE tblWebhooks SET name = ?, eventTypes = ?, targetUrl = ?, isActive = ? '
        . 'WHERE webhookID = ? AND siteID = ?'
    );
    if ($stmt === false) {
        Logger::errorPlatform('MySQL', 'Error', 'WEBHOOK_SAVE', $mysqli->error, '');
        $_SESSION['flash_msg']  = 'Could not update webhook — database error.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/integrations/webhooks'), true, 302);
        exit();
    }
    $stmt->bind_param('sssiii', $name, $eventTypes, $targetUrl, $isActive, $webhookId, $siteId);
    $ok = $stmt->execute();
    if ($ok === false) {
        Logger::errorPlatform('MySQL', 'Error', 'WEBHOOK_SAVE', $mysqli->error, '');
        $stmt->close();
        $_SESSION['flash_msg']  = 'Could not update webhook — database error.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/integrations/webhooks'), true, 302);
        exit();
    }
    $stmt->close();

    Logger::activity('WebhookUpdated', 'Webhook #' . $webhookId . ' isActive=' . $isActive . ' siteID=' . $siteId, $userId);

    $_SESSION['flash_msg']  = 'Webhook updated.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . Site::url('admin/integrations/webhooks'), true, 302);
    exit();
}

// ============================================================================
// (a) CREATE — new webhook
// ============================================================================
$name       = trim((string) ($_POST['name'] ?? ''));
$targetUrl  = trim((string) ($_POST['targetUrl'] ?? ''));
$eventTypes = trim((string) ($_POST['eventTypes'] ?? ''));
if ($eventTypes === '') {
    $eventTypes = 'all';
}
$isActive = ((string) ($_POST['isActive'] ?? '0') === '1') ? 1 : 0;

$error = $validateFields($name, $targetUrl);
if ($error !== '') {
    $_SESSION['flash_msg']  = $error;
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/integrations/webhooks'), true, 302);
    exit();
}

// 🔐 HMAC-SHA256 signing secret — 32 random bytes → 64 hex chars.
//    Shown to the admin exactly ONCE via the flash below.
$signingSecret = bin2hex(random_bytes(32));

$stmt = $mysqli->prepare(
    'INSERT INTO tblWebhooks (siteID, name, eventTypes, targetUrl, signingSecret, isActive, createdByID) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'WEBHOOK_SAVE', $mysqli->error, '');
    $_SESSION['flash_msg']  = 'Could not create webhook — database error.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/integrations/webhooks'), true, 302);
    exit();
}
$stmt->bind_param('issssii', $siteId, $name, $eventTypes, $targetUrl, $signingSecret, $isActive, $userId);
$ok = $stmt->execute();
if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'WEBHOOK_SAVE', $mysqli->error, '');
    $stmt->close();
    $_SESSION['flash_msg']  = 'Could not create webhook — database error.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/integrations/webhooks'), true, 302);
    exit();
}
$newId = (int) $mysqli->insert_id;
$stmt->close();

Logger::activity('WebhookCreated', 'Webhook #' . $newId . ' (' . $name . ') siteID=' . $siteId, $userId);

$_SESSION['webhook_secret_minted'] = [
    'plaintext' => $signingSecret,
    'webhookID' => $newId,
    'name'      => $name,
];
$_SESSION['flash_msg']  = 'Webhook created.';
$_SESSION['flash_type'] = 'success';
header('Location: ' . Site::url('admin/integrations/webhooks'), true, 302);
exit();
