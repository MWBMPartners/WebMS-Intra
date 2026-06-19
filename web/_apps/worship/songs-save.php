<?php
// _apps/worship/songs-save.php (#309)
declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /worship/songs', true, 302); exit(); }

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) { http_response_code(403); exit('Forbidden'); }
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) { http_response_code(400); exit('Bad request'); }

$songId = (int) ($_POST['songID'] ?? 0);
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

if (($_POST['delete'] ?? '') === '1' && $songId > 0) {
    $stmt = $mysqli->prepare('UPDATE tblSongs SET isActive = 0 WHERE songID = ? AND siteID = ?');
    $stmt->bind_param('ii', $songId, $siteId);
    $stmt->execute();
    $stmt->close();
    Logger::activity('SongArchived', 'Song #' . $songId);
    header('Location: /worship/songs', true, 302); exit();
}

$title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 255);
if ($title === '') { http_response_code(400); exit('Title required.'); }
$author    = mb_substr(trim((string) ($_POST['author'] ?? '')),        0, 255);
$ccli      = mb_substr(trim((string) ($_POST['ccliNumber'] ?? '')),    0, 40);
$copyright = mb_substr(trim((string) ($_POST['copyrightLine'] ?? '')), 0, 500);
$key       = mb_substr(trim((string) ($_POST['defaultKey'] ?? '')),    0, 10);
$tempo     = mb_substr(trim((string) ($_POST['defaultTempo'] ?? '')),  0, 20);
$tags      = mb_substr(trim((string) ($_POST['tags'] ?? '')),          0, 255);
$lyrics    = (string) ($_POST['lyrics'] ?? '');
if (mb_strlen($lyrics) > 50000) { $lyrics = mb_substr($lyrics, 0, 50000); }

if ($songId === 0) {
    $stmt = $mysqli->prepare(
        'INSERT INTO tblSongs (siteID, title, author, ccliNumber, copyrightLine, defaultKey, defaultTempo, lyrics, tags, createdByID) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issssssssi', $siteId, $title, $author, $ccli, $copyright, $key, $tempo, $lyrics, $tags, $userId);
    $stmt->execute();
    $songId = (int) $stmt->insert_id;
    $stmt->close();
    Logger::activity('SongCreated', 'Song #' . $songId . ' "' . $title . '"');
} else {
    $stmt = $mysqli->prepare(
        'UPDATE tblSongs SET title=?, author=?, ccliNumber=?, copyrightLine=?, defaultKey=?, defaultTempo=?, lyrics=?, tags=? '
        . 'WHERE songID=? AND siteID=?'
    );
    $stmt->bind_param('ssssssssii', $title, $author, $ccli, $copyright, $key, $tempo, $lyrics, $tags, $songId, $siteId);
    $stmt->execute();
    $stmt->close();
    Logger::activity('SongUpdated', 'Song #' . $songId);
}

header('Location: /worship/song?id=' . $songId, true, 302); exit();
