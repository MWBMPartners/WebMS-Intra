<?php
// Path: public_html/admin/hymns-save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Hymnal index + remote lookup — POST handler 📖
 * -----------------------------------------------------------------------------
 * Gap #128 residual. CSRF-first on every action, admin-only, every hymnal/
 * entry write re-checks site ownership before touching a row. Actions:
 *   hymnal_save, hymnal_toggle, entry_save, entry_delete, csv_import,
 *   remote_save, remote_test, public_share_toggle.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/128
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Hymnal;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

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

$db     = App::db();
$siteId = Site::id();
$action = (string) ($_POST['action'] ?? '');

/** Redirect back, optionally to a specific hymnal's entry view. */
$redirect = static function (?int $hymnalId = null): never {
    $qs = ($hymnalId !== null && $hymnalId > 0) ? ('?hymnalID=' . $hymnalId) : '';
    header('Location: /admin/hymns' . $qs);
    exit();
};

/** Confirm a hymnalID belongs to this site — every entry mutation re-checks this. */
$ownsHymnal = static function (int $hymnalId) use ($db, $siteId): bool {
    if ($hymnalId <= 0) {
        return false;
    }
    $stmt = $db->prepare('SELECT 1 FROM tblHymnals WHERE hymnalID = ? AND siteID = ? LIMIT 1');
    if ($stmt === false) {
        return false;
    }
    $stmt->bind_param('ii', $hymnalId, $siteId);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $ok;
};

/** Upsert one global (siteID NULL) tblSettings row — encrypts when isSensitive. */
$upsertSetting = static function (string $key, string $value, bool $isSensitive) use ($db): void {
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

switch ($action) {
    // -----------------------------------------------------------------------
    // 📖 Hymnal CRUD
    // -----------------------------------------------------------------------
    case 'hymnal_save': {
        $code = mb_substr(trim((string) ($_POST['code'] ?? '')), 0, 20);
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
        $pub  = mb_substr(trim((string) ($_POST['publisher'] ?? '')), 0, 255);
        if ($code === '' || $name === '') {
            $_SESSION['flash_msg']  = 'Code and name are both required.';
            $_SESSION['flash_type'] = 'danger';
            $redirect();
        }
        $pubVal = $pub !== '' ? $pub : null;
        $stmt = $db->prepare('INSERT INTO tblHymnals (siteID, code, name, publisher) VALUES (?, ?, ?, ?)');
        if ($stmt !== false) {
            $stmt->bind_param('isss', $siteId, $code, $name, $pubVal);
            try {
                $stmt->execute();
                Logger::activity('HymnalCreated', 'Hymnal "' . $name . '" (' . $code . ')');
                $_SESSION['flash_msg']  = 'Hymnal added.';
                $_SESSION['flash_type'] = 'success';
            } catch (\mysqli_sql_exception $e) {
                $_SESSION['flash_msg']  = 'A hymnal with that code already exists.';
                $_SESSION['flash_type'] = 'danger';
            }
            $stmt->close();
        }
        $redirect();
    }

    case 'hymnal_toggle': {
        $hymnalId = (int) ($_POST['hymnalID'] ?? 0);
        if ($ownsHymnal($hymnalId) === true) {
            $stmt = $db->prepare('UPDATE tblHymnals SET isActive = 1 - isActive WHERE hymnalID = ? AND siteID = ?');
            if ($stmt !== false) {
                $stmt->bind_param('ii', $hymnalId, $siteId);
                $stmt->execute();
                $stmt->close();
            }
        }
        $redirect();
    }

    // -----------------------------------------------------------------------
    // 📝 Single-entry CRUD (owning hymnal re-checked every time)
    // -----------------------------------------------------------------------
    case 'entry_save': {
        $hymnalId = (int) ($_POST['hymnalID'] ?? 0);
        if ($ownsHymnal($hymnalId) === false) {
            Router::renderError(404);
            return;
        }
        $number = mb_substr(trim((string) ($_POST['number'] ?? '')), 0, 20);
        $title  = mb_substr(trim((string) ($_POST['title']  ?? '')), 0, 255);
        $author = mb_substr(trim((string) ($_POST['author'] ?? '')), 0, 255);
        $tune   = mb_substr(trim((string) ($_POST['tuneName'] ?? '')), 0, 120);
        if ($number === '' || $title === '') {
            $_SESSION['flash_msg']  = 'Number and title are both required.';
            $_SESSION['flash_type'] = 'danger';
            $redirect($hymnalId);
        }
        $numberSort = (int) (preg_match('/^\d+/', $number, $m) === 1 ? $m[0] : 0);
        $authorVal  = $author !== '' ? $author : null;
        $tuneVal    = $tune !== '' ? $tune : null;
        $stmt = $db->prepare(
            'INSERT INTO tblHymnalEntries (hymnalID, number, numberSort, title, author, tuneName) VALUES (?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE title = VALUES(title), numberSort = VALUES(numberSort), author = VALUES(author), tuneName = VALUES(tuneName)'
        );
        if ($stmt !== false) {
            $stmt->bind_param('isisss', $hymnalId, $number, $numberSort, $title, $authorVal, $tuneVal);
            $stmt->execute();
            $stmt->close();
        }
        $redirect($hymnalId);
    }

    case 'entry_delete': {
        $hymnalId = (int) ($_POST['hymnalID'] ?? 0);
        $entryId  = (int) ($_POST['entryID']  ?? 0);
        if ($ownsHymnal($hymnalId) === true) {
            $stmt = $db->prepare('DELETE FROM tblHymnalEntries WHERE entryID = ? AND hymnalID = ?');
            if ($stmt !== false) {
                $stmt->bind_param('ii', $entryId, $hymnalId);
                $stmt->execute();
                $stmt->close();
            }
        }
        $redirect($hymnalId);
    }

    // -----------------------------------------------------------------------
    // 📤 CSV import
    // -----------------------------------------------------------------------
    case 'csv_import': {
        $hymnalId = (int) ($_POST['hymnalID'] ?? 0);
        if ($ownsHymnal($hymnalId) === false) {
            Router::renderError(404);
            return;
        }
        $file = $_FILES['csv_file'] ?? null;
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_msg']  = 'File upload failed — choose a CSV file and try again.';
            $_SESSION['flash_type'] = 'danger';
            $redirect($hymnalId);
        }
        if ((int) $file['size'] > Hymnal::CSV_MAX_BYTES) {
            $_SESSION['flash_msg']  = 'File too large (max ' . number_format(Hymnal::CSV_MAX_BYTES / 1024) . ' KB).';
            $_SESSION['flash_type'] = 'danger';
            $redirect($hymnalId);
        }
        $raw = file_get_contents((string) $file['tmp_name']);
        if ($raw === false || $raw === '') {
            $_SESSION['flash_msg']  = 'The uploaded file was empty or unreadable.';
            $_SESSION['flash_type'] = 'danger';
            $redirect($hymnalId);
        }
        $result = Hymnal::importCsv($siteId, $hymnalId, $raw);
        Logger::activity('HymnalCsvImported', 'Hymnal #' . $hymnalId . ': ' . $result['ok'] . ' row(s) imported, ' . count($result['errors']) . ' error(s)');
        $msg = $result['ok'] . ' row(s) imported.';
        if (count($result['errors']) > 0) {
            $msg .= ' ' . count($result['errors']) . ' row(s) had problems: ' . implode(' ', array_slice($result['errors'], 0, 5));
        }
        $_SESSION['flash_msg']  = $msg;
        $_SESSION['flash_type'] = $result['ok'] > 0 ? 'success' : 'warning';
        $redirect($hymnalId);
    }

    // -----------------------------------------------------------------------
    // 🌐 Remote (Tier 2) provider settings
    // -----------------------------------------------------------------------
    case 'remote_save': {
        $enabled = isset($_POST['enabled']) === true ? '1' : '0';
        $host    = mb_substr(trim((string) ($_POST['host'] ?? '')), 0, 255);
        $baseUrl = mb_substr(trim((string) ($_POST['baseUrl'] ?? '')), 0, 500);
        $ttl     = max(60, (int) ($_POST['cacheTtl'] ?? 86400));
        $apiKey  = trim((string) ($_POST['apiKey'] ?? ''));

        // 🛡️ https-only + single-host-allowlist + non-private-IP — refused
        // at save time (validateRemoteConfig() is re-run at request time
        // too, in Hymnal::searchRemote() — defence in depth).
        $configErr = Hymnal::validateRemoteConfig($baseUrl, $host);
        if ($configErr !== '' && $enabled === '1') {
            $_SESSION['flash_msg']  = $configErr;
            $_SESSION['flash_type'] = 'danger';
            $redirect();
        }

        $upsertSetting('hymns.remote.enabled',  $enabled === '1' && $configErr === '' ? 'true' : 'false', false);
        $upsertSetting('hymns.remote.host',     $host, false);
        $upsertSetting('hymns.remote.baseUrl',  $baseUrl, false);
        $upsertSetting('hymns.remote.cacheTtl', (string) $ttl, false);
        if ($apiKey !== '') {
            $upsertSetting('hymns.remote.apiKey', $apiKey, true);
        }
        Logger::activity('HymnalRemoteSettingsSaved', 'Remote hymnal lookup settings updated (enabled=' . $enabled . ')');
        $_SESSION['flash_msg']  = 'Remote lookup settings saved.';
        $_SESSION['flash_type'] = 'success';
        $redirect();
    }

    case 'remote_test': {
        $result = Hymnal::testRemoteConnection($siteId);
        $_SESSION['flash_msg']  = $result['message'];
        $_SESSION['flash_type'] = $result['success'] === true ? 'success' : 'danger';
        $redirect();
    }

    // -----------------------------------------------------------------------
    // 🔗 Public Order-of-Service site-level switch
    // -----------------------------------------------------------------------
    case 'public_share_toggle': {
        $enabled = isset($_POST['enabled']) === true ? 'true' : 'false';
        $upsertSetting('service_plans.public_share.enabled', $enabled, false);
        Logger::activity('PublicShareToggled', 'Public Order-of-Service sharing set to ' . $enabled);
        $redirect();
    }

    default:
        $redirect();
}
