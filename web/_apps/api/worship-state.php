<?php
// Path: _apps/api/worship-state.php
/**
 * -----------------------------------------------------------------------------
 * Worship — Public state poll endpoint (#308 Phase 2 + Phase 3)
 * -----------------------------------------------------------------------------
 * Returns the current state JSON for a plan, gated by displayToken (not
 * login). Called every 500ms by /worship/display and every ~1.5s by the
 * operator console.
 *
 * Response shape:
 *   { ok, body, slideTitle, itemType, isBlank, isBlack,
 *     currentItemID, currentSlideIndex, totalSlides }
 *
 * Phase 3: songs are split into verses per worship.song_verse_separator,
 * and body returns the CURRENT verse only (totalSlides shows the count).
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/308
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Settings;
use Portal\Core\Site;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

$token = trim((string) ($_GET['t'] ?? ''));
if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid-token']);
    exit();
}

$siteId = Site::id();

$stmt = $mysqli->prepare(
    'SELECT p.planID, s.currentItemID, COALESCE(s.currentSlideIndex, 0) AS currentSlideIndex, '
    . '       COALESCE(s.isBlank, 0) AS isBlank, COALESCE(s.isBlack, 0) AS isBlack '
    . 'FROM tblServicePlans p '
    . 'LEFT JOIN tblServicePlanState s ON s.planID = p.planID '
    . 'WHERE p.displayToken = ? AND p.siteID = ? AND p.isActive = 1'
);
$stmt->bind_param('si', $token, $siteId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($row === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'plan-not-found']);
    exit();
}

$response = [
    'ok'                => true,
    'currentItemID'     => $row['currentItemID'] !== null ? (int) $row['currentItemID'] : null,
    'currentSlideIndex' => (int) $row['currentSlideIndex'],
    'totalSlides'       => 1,
    'isBlank'           => (int) $row['isBlank'] === 1,
    'isBlack'           => (int) $row['isBlack'] === 1,
    'body'              => '',
    'slideTitle'        => '',
    'itemType'          => '',
];

if ($row['currentItemID'] !== null) {
    $itemId = (int) $row['currentItemID'];
    $stmt = $mysqli->prepare(
        'SELECT i.itemType, i.slideTitle, i.slideBody, s.title AS songTitle, s.lyrics AS songLyrics '
        . 'FROM tblServicePlanItems i LEFT JOIN tblSongs s ON s.songID = i.songID '
        . 'WHERE i.itemID = ?'
    );
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($item !== null) {
        $response['itemType']   = (string) $item['itemType'];
        $response['slideTitle'] = (string) ($item['itemType'] === 'song' ? ($item['songTitle'] ?? '') : ($item['slideTitle'] ?? ''));

        // 🎵 Song split into verses; non-songs are single-slide.
        if ((string) $item['itemType'] === 'song' && !empty($item['songLyrics'])) {
            $verseRegex = (string) Settings::get('worship.song_verse_separator', '/\n\s*\n/');
            if (preg_match($verseRegex, '') === false) { $verseRegex = '/\n\s*\n/'; }
            $verses = preg_split($verseRegex, (string) $item['songLyrics']) ?: [];
            $verses = array_values(array_filter(array_map('trim', $verses), static fn($v) => $v !== ''));
            if (count($verses) === 0) { $verses = [(string) $item['songLyrics']]; }
            $response['totalSlides'] = count($verses);
            $response['body']        = (string) ($verses[(int) $row['currentSlideIndex']] ?? $verses[0]);
        } else {
            $response['body'] = (string) ($item['slideBody'] ?? '');
        }
    }
}

echo json_encode($response);
