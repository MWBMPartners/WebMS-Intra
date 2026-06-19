<?php
// Path: _apps/api/worship-advance.php
/**
 * -----------------------------------------------------------------------------
 * Worship — Operator command POST handler (#308 Phase 2 + Phase 3)
 * -----------------------------------------------------------------------------
 * Login-required. Admin OR coordinator of the plan's event. Actions:
 *   next   — advance to next verse within song, or to next item
 *   prev   — back one verse / item
 *   goto   — jump to a specific itemID (verse index resets to 0)
 *   blank  — toggle isBlank ON, isBlack OFF
 *   black  — toggle isBlack ON, isBlank OFF
 *   show   — clear both isBlank and isBlack
 *
 * Phase 3 additions:
 *   • Song-slide auto-split: song lyrics are split into verses on the
 *     worship.song_verse_separator regex. Next/prev advance within a
 *     song before moving to the next item.
 *   • CCLI usage log: every advance INTO a song item (where the prior
 *     item wasn't the same song) writes a tblCcliUsage row.
 *
 * Returns JSON identical in shape to /api/worship/state.
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/308
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Settings;
use Portal\Core\Site;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method']); exit();
}

Auth::ensureSession();
if (Auth::check() === false) {
    http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit();
}
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'csrf']); exit();
}

$planId = (int) ($_POST['planID'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$userId = (int) ($_SESSION['user_id'] ?? 0);
$siteId = Site::id();

if (in_array($action, ['next', 'prev', 'goto', 'blank', 'black', 'show'], true) === false) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'action']); exit();
}

$stmt = $mysqli->prepare('SELECT planID, eventID FROM tblServicePlans WHERE planID = ? AND siteID = ? AND isActive = 1');
$stmt->bind_param('ii', $planId, $siteId);
$stmt->execute();
$plan = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($plan === null) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'plan']); exit(); }

$canOperate = App::isAdmin() === true
           || ((int) ($plan['eventID'] ?? 0) > 0 && Auth::isCoordinatorOf((int) $plan['eventID']));
if ($canOperate === false) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit();
}

// 📋 Current state (NULL row OK — defaults).
$stmt = $mysqli->prepare('SELECT currentItemID, currentSlideIndex, isBlank, isBlack FROM tblServicePlanState WHERE planID = ?');
$stmt->bind_param('i', $planId);
$stmt->execute();
$state = $stmt->get_result()->fetch_assoc() ?: ['currentItemID' => null, 'currentSlideIndex' => 0, 'isBlank' => 0, 'isBlack' => 0];
$stmt->close();

$prevItemId = $state['currentItemID'] !== null ? (int) $state['currentItemID'] : null;
$newItemId  = $prevItemId;
$newSlide   = (int) $state['currentSlideIndex'];
$newBlank   = (int) $state['isBlank'];
$newBlack   = (int) $state['isBlack'];

// 🎵 Helper to load an item + count its verses (1 for non-songs).
$loadItem = static function (\mysqli $db, int $itemId, string $verseRegex): ?array {
    $stmt = $db->prepare(
        'SELECT i.itemID, i.itemType, i.songID, i.slideTitle, i.slideBody, '
        . '       s.title AS songTitle, s.lyrics AS songLyrics '
        . 'FROM tblServicePlanItems i LEFT JOIN tblSongs s ON s.songID = i.songID '
        . 'WHERE i.itemID = ?'
    );
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($r === null) { return null; }
    if ((string) $r['itemType'] === 'song' && !empty($r['songLyrics'])) {
        $verses = preg_split($verseRegex, (string) $r['songLyrics']) ?: [];
        $verses = array_values(array_filter(array_map('trim', $verses), static fn($v) => $v !== ''));
        if (count($verses) === 0) { $verses = [(string) $r['songLyrics']]; }
        $r['_verses'] = $verses;
    } else {
        $r['_verses'] = [(string) ($r['slideBody'] ?? '')];
    }
    return $r;
};

$verseRegex = (string) Settings::get('worship.song_verse_separator', '/\n\s*\n/');
if (preg_match($verseRegex, '') === false) {
    // 🛡️ Malformed regex (admin error) — fall back to safe default.
    $verseRegex = '/\n\s*\n/';
}

if ($action === 'next' || $action === 'prev') {
    $currentItem = $prevItemId !== null ? $loadItem($mysqli, $prevItemId, $verseRegex) : null;

    // 🎵 First: try to advance WITHIN the current song's verses.
    if ($currentItem !== null && (string) $currentItem['itemType'] === 'song') {
        $verseCount = count((array) $currentItem['_verses']);
        if ($action === 'next' && $newSlide < $verseCount - 1) {
            $newSlide++;
            $newBlank = 0; $newBlack = 0;
            goto persist; // jump to upsert block
        }
        if ($action === 'prev' && $newSlide > 0) {
            $newSlide--;
            $newBlank = 0; $newBlack = 0;
            goto persist;
        }
    }

    // 🧮 Otherwise jump to next/prev ITEM by sortOrder.
    if ($prevItemId === null) {
        $sql = 'SELECT itemID FROM tblServicePlanItems WHERE planID = ? ORDER BY sortOrder, itemID '
             . ($action === 'next' ? 'ASC' : 'DESC') . ' LIMIT 1';
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $planId);
    } else {
        $stmt = $mysqli->prepare('SELECT sortOrder FROM tblServicePlanItems WHERE itemID = ? AND planID = ?');
        $stmt->bind_param('ii', $prevItemId, $planId);
        $stmt->execute();
        $cur = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $currentOrder = (int) ($cur['sortOrder'] ?? 0);
        $cmp = $action === 'next' ? '>' : '<';
        $dir = $action === 'next' ? 'ASC' : 'DESC';
        $stmt = $mysqli->prepare(
            "SELECT itemID FROM tblServicePlanItems WHERE planID = ? AND sortOrder $cmp ? ORDER BY sortOrder $dir LIMIT 1"
        );
        $stmt->bind_param('ii', $planId, $currentOrder);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row !== null) {
        $newItemId = (int) $row['itemID'];
        $newSlide  = 0;
        // 🎵 If we're stepping BACKWARD into a song, jump to its last verse.
        if ($action === 'prev') {
            $newItem = $loadItem($mysqli, $newItemId, $verseRegex);
            if ($newItem !== null && (string) $newItem['itemType'] === 'song') {
                $newSlide = max(0, count((array) $newItem['_verses']) - 1);
            }
        }
    }
    $newBlank = 0; $newBlack = 0;
} elseif ($action === 'goto') {
    $target = (int) ($_POST['itemID'] ?? 0);
    if ($target <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'itemID']); exit(); }
    $stmt = $mysqli->prepare('SELECT itemID FROM tblServicePlanItems WHERE itemID = ? AND planID = ?');
    $stmt->bind_param('ii', $target, $planId);
    $stmt->execute();
    $ok = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($ok === false) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'item']); exit(); }
    $newItemId = $target;
    $newSlide = 0; $newBlank = 0; $newBlack = 0;
} elseif ($action === 'blank') {
    $newBlank = 1; $newBlack = 0;
} elseif ($action === 'black') {
    $newBlack = 1; $newBlank = 0;
} elseif ($action === 'show') {
    $newBlank = 0; $newBlack = 0;
}

persist:
$stmt = $mysqli->prepare(
    'INSERT INTO tblServicePlanState (planID, currentItemID, currentSlideIndex, isBlank, isBlack, updatedByID) '
    . 'VALUES (?, ?, ?, ?, ?, ?) '
    . 'ON DUPLICATE KEY UPDATE currentItemID = VALUES(currentItemID), currentSlideIndex = VALUES(currentSlideIndex), '
    . '                       isBlank = VALUES(isBlank), isBlack = VALUES(isBlack), updatedByID = VALUES(updatedByID)'
);
$stmt->bind_param('iiiiii', $planId, $newItemId, $newSlide, $newBlank, $newBlack, $userId);
$stmt->execute();
$stmt->close();

Logger::activity('ServicePlanAdvanced', 'Plan #' . $planId . ' ' . $action . ' → item=' . ($newItemId ?? 'null') . ' verse=' . $newSlide . ' blank=' . $newBlank . ' black=' . $newBlack);

// 📊 CCLI usage log: write a row whenever we cross INTO a song item that
//     wasn't the previously-active item. Verse-within-song advances don't
//     re-log the same song.
if ($newItemId !== null && $newItemId !== $prevItemId) {
    $newItem = $loadItem($mysqli, $newItemId, $verseRegex);
    if ($newItem !== null && (string) $newItem['itemType'] === 'song' && $newItem['songID'] !== null) {
        $songId = (int) $newItem['songID'];
        $itemId = (int) $newItem['itemID'];
        $stmt = $mysqli->prepare(
            'INSERT INTO tblCcliUsage (siteID, songID, planID, itemID, operatorID) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iiiii', $siteId, $songId, $planId, $itemId, $userId);
        $stmt->execute();
        $stmt->close();
    }
}

// 📤 Return the resolved state — body is the CURRENT verse for songs.
$response = [
    'ok'                => true,
    'currentItemID'     => $newItemId,
    'currentSlideIndex' => $newSlide,
    'totalSlides'       => 1,
    'isBlank'           => (bool) $newBlank,
    'isBlack'           => (bool) $newBlack,
    'body'              => '',
    'slideTitle'        => '',
    'itemType'          => '',
];

if ($newItemId !== null) {
    $item = $loadItem($mysqli, $newItemId, $verseRegex);
    if ($item !== null) {
        $verses = (array) $item['_verses'];
        $response['itemType']    = (string) $item['itemType'];
        $response['slideTitle']  = (string) ($item['itemType'] === 'song' ? ($item['songTitle'] ?? '') : ($item['slideTitle'] ?? ''));
        $response['totalSlides'] = count($verses);
        $response['body']        = (string) ($verses[$newSlide] ?? '');
    }
}

echo json_encode($response);
