<?php
// Path: _apps/admin/calendar/feeds-save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — External feeds CRUD (#327)
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /admin/calendar/feeds', true, 302); exit(); }

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) { http_response_code(403); exit('Forbidden'); }
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) { http_response_code(400); exit('Bad request'); }

$action  = (string) ($_POST['action'] ?? '');
$siteId  = Site::id();
$userId  = (int) ($_SESSION['user_id'] ?? 0);

if ($action === 'add') {
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
    $url  = mb_substr(trim((string) ($_POST['url'] ?? '')), 0, 2000);
    $mins = max(15, min(10080, (int) ($_POST['fetchEveryMins'] ?? 360)));
    if ($name === '' || filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('/^https?:\/\//i', $url) !== 1) {
        $_SESSION['flash_msg']  = 'Invalid name or URL (must be http/https).';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/calendar/feeds', true, 302); exit();
    }
    // 👁️ #514 part P1: `audienceLevel` (the calendar's own "who may see its
    //    events" setting, added by migration 204) is written as 'public'
    //    until the calendar pages let an administrator choose (part P8).
    //    Every calendar has been public in effect since #327, and migration
    //    204 marked every existing one public for that reason; a new one
    //    must behave the same until the choice exists. The column's own
    //    default is 'members', the narrow one, so leaving it out here would
    //    quietly change what a newly added calendar shows.
    $stmt = $mysqli->prepare("INSERT INTO tblExternalFeeds (siteID, name, url, fetchEveryMins, createdByID, audienceLevel) VALUES (?, ?, ?, ?, ?, 'public')");
    $stmt->bind_param('issii', $siteId, $name, $url, $mins, $userId);
    $stmt->execute();
    $stmt->close();
    Logger::activity('ExternalFeedAdded', $name);
} else {
    $feedId = (int) ($_POST['feedID'] ?? 0);
    if ($feedId <= 0) { header('Location: /admin/calendar/feeds', true, 302); exit(); }
    if ($action === 'pause') {
        $stmt = $mysqli->prepare('UPDATE tblExternalFeeds SET isActive = 0 WHERE feedID = ? AND siteID = ?');
        $stmt->bind_param('ii', $feedId, $siteId);
        $stmt->execute();
        $stmt->close();
    } elseif ($action === 'resume') {
        $stmt = $mysqli->prepare('UPDATE tblExternalFeeds SET isActive = 1 WHERE feedID = ? AND siteID = ?');
        $stmt->bind_param('ii', $feedId, $siteId);
        $stmt->execute();
        $stmt->close();
    } elseif ($action === 'remove') {
        // 🗑️ Also remove every event originating from this feed.
        $stmt = $mysqli->prepare('DELETE FROM tblEvents WHERE externalFeedID = ? AND siteID = ?');
        $stmt->bind_param('ii', $feedId, $siteId);
        $stmt->execute();
        $stmt->close();
        $stmt = $mysqli->prepare('DELETE FROM tblExternalFeeds WHERE feedID = ? AND siteID = ?');
        $stmt->bind_param('ii', $feedId, $siteId);
        $stmt->execute();
        $stmt->close();
    }
    Logger::activity('ExternalFeed' . ucfirst($action), 'Feed #' . $feedId);
}

header('Location: /admin/calendar/feeds', true, 302); exit();
