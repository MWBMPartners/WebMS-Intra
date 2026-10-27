<?php
// Path: public_html/api/translate.php
/**
 * AJAX endpoint — translate a piece of user content into the requested
 * target language. Called by the "Translate" link on prayer requests,
 * announcements, etc.
 *
 *   POST sourceTable=tblPrayerRequests sourceID=42 sourceField=body
 *        targetLanguage=cy text=<original>
 *
 * Returns JSON { translation, cached }.
 *
 * @package   Portal\Api
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/278
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Translation;

Auth::ensureSession();
Auth::requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    echo json_encode(['error' => 'bad-request']);
    exit();
}

// 🔄 The check above consumed the token and issued a replacement. Every answer
//    below therefore carries it, so a page posting more than once without
//    reloading is not left holding a spent one (#634). The refusal ABOVE
//    carries nothing on purpose: a failed check rotates nothing.
$freshCsrf = Auth::csrfToken();

// Only allow translation of content from a whitelist of known tables.
// Add new translatable tables here as apps wire them up.
$allowed = [
    'tblPrayerRequests',
    'tblAnnouncements',
    'tblNewsletter',
    'tblProject',
    'tblProjectUpdate',
];
$sourceTable = (string) ($_POST['sourceTable'] ?? '');
if (in_array($sourceTable, $allowed, true) === false) {
    http_response_code(400);
    echo json_encode(['error' => 'table-not-allowed', 'csrfToken' => $freshCsrf]);
    exit();
}

$sourceID    = (int) ($_POST['sourceID'] ?? 0);
$sourceField = (string) ($_POST['sourceField'] ?? 'body');
$targetLang  = Translation::normaliseLocale((string) ($_POST['targetLanguage'] ?? 'en'));
$text        = (string) ($_POST['text'] ?? '');

if ($sourceID <= 0 || $text === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing-fields', 'csrfToken' => $freshCsrf]);
    exit();
}

$sourceLang = Translation::detect($text);
$out = Translation::translate($sourceTable, $sourceID, $sourceField, $sourceLang, $targetLang, $text);

if ($out === null) {
    echo json_encode(['translation' => $text, 'cached' => true, 'sameLanguage' => true, 'csrfToken' => $freshCsrf]);
    exit();
}
if ($out === false) {
    http_response_code(503);
    echo json_encode(['error' => 'translation-unavailable', 'csrfToken' => $freshCsrf]);
    exit();
}

echo json_encode(['translation' => $out, 'cached' => false, 'sourceLanguage' => $sourceLang, 'csrfToken' => $freshCsrf]);
exit();
