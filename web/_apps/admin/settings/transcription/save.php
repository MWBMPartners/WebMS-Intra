<?php
// Path: _apps/admin/settings/transcription/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — save YOUR organisation's transcript retention period 💾 (#722)
 * -----------------------------------------------------------------------------
 * The form target for `/admin/settings/transcription`. POST only, form token
 * first, an administrator of this organisation, and nothing written outside
 * this organisation's own settings row.
 *
 * WHAT THIS FILE DOES AND DELIBERATELY DOES NOT DO
 * ------------------------------------------------
 * It reads the form and records what happened. It does NOT decide whether the
 * number is allowed, and it does not write the row: both are
 * `Portal\Core\TranscriptRetention::saveForSite()`, which re-reads the
 * installation's ceiling LIVE with that row LOCKED, inside the transaction
 * that writes.
 *
 * THAT SPLIT IS THE POINT, not tidiness. #687 measured two ordinary
 * overlapping web requests storing fifty years under a ceiling of seven —
 * 60 pairs out of 60 — because the gate compared against the request's
 * settings snapshot, taken at start-up. A snapshot cannot see a ceiling
 * committed a moment ago. And a page cannot be driven by two simultaneous
 * connections, so a page-level test could never prove the lock either:
 * `tools/retention-caps-selftest.php` drives the method directly on two real
 * connections.
 *
 * THE RULES IT FOLLOWS, EACH ONE PAID FOR ALREADY
 * -----------------------------------------------
 *   - ASK WHETHER THE FIELD ARRIVED, never only what it contains (#677). A
 *     disabled control is not submitted, and reading an absent field as empty
 *     is how saving one setting cleared `site.url` for every organisation.
 *     An ABSENT field changes nothing; an EMPTY one means "follow the
 *     installation" and removes this organisation's own row.
 *   - REFUSE WITH THE REASON, never clamp (#668). Silently shortening a period
 *     somebody typed is how a person comes to believe a limit is in force when
 *     it is not. The refusal never says WHO set the ceiling — the two levels
 *     may be different parties.
 *   - RECORD THE REFUSAL. A request to exceed the installation's limit crosses
 *     a line between two parties who may be two different people.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/722
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\TranscriptRetention;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    Router::renderError(403);

    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('admin/settings/transcription'));
    exit();
}

// 🔐 Form token FIRST, before anything with a side effect — the activity-log
//    entries below included, so a forged request from another website cannot
//    fill the log.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Nothing was changed. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/settings/transcription'));
    exit();
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 📨 DID THE FIELD ARRIVE? Not "what does it contain" (#677).
if (array_key_exists('transcriptDays', $_POST) === false) {
    $_SESSION['flash_msg']  = 'The form did not include a period, so nothing was changed.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/settings/transcription'));
    exit();
}

$raw = trim((string) $_POST['transcriptDays']);

// An EMPTY box means "follow the installation", which removes this
// organisation's own row. A box holding anything else must be a whole number
// of days; the hard maximum in code and the installation's ceiling are both
// judged inside saveForSite().
$days = null;
if ($raw !== '') {
    if (preg_match('/^\d{1,6}$/', $raw) !== 1) {
        Logger::activity(
            'TranscriptRetentionRefused',
            'Organisation ' . $siteId . ' asked for a transcript period of "' . mb_substr($raw, 0, 20)
            . '", which is not a whole number of days. Nothing was saved.',
            $userId > 0 ? $userId : null
        );
        $_SESSION['flash_msg']  = 'How long transcripts are kept must be a whole number of days, or empty '
            . 'to follow the period this installation sets. Nothing was saved.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/settings/transcription'));
        exit();
    }
    $days = (int) $raw;
}

$result = TranscriptRetention::saveForSite($mysqli, $siteId, $days);

if ($result['saved'] === false) {
    Logger::activity(
        'TranscriptRetentionRefused',
        'Organisation ' . $siteId . ' asked to keep transcripts for '
        . ($days === null ? 'the installation\'s period' : ($days === 0 ? 'ever' : $days . ' days'))
        . ' and it was refused' . ($result['refusal'] === '' ? '' : ' (' . $result['refusal'] . ')')
        . '. Nothing was saved.',
        $userId > 0 ? $userId : null
    );
    $_SESSION['flash_msg']  = $result['message'];
    $_SESSION['flash_type'] = $result['type'];
    header('Location: ' . Site::url('admin/settings/transcription'));
    exit();
}

Logger::activity(
    'TranscriptRetentionSaved',
    'Organisation ' . $siteId . ' now keeps transcripts for '
    . ($result['cleared'] === true
        ? 'the period this installation sets, having removed its own figure'
        : ($days === 0 ? 'ever' : $days . ' days')),
    $userId > 0 ? $userId : null
);

$_SESSION['flash_msg']  = $result['message'];
$_SESSION['flash_type'] = $result['type'];
header('Location: ' . Site::url('admin/settings/transcription'));
exit();
