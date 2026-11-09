<?php
// Path: _apps/admin/calendar/feeds-website-rule.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Save how copied-in events reach the public website 🌐 (#493 P1)
 * -----------------------------------------------------------------------------
 * The form target for the "Copied-in events on your public website" card on
 * /admin/calendar/feeds (D-080, D-081). POST only; the form token is checked
 * FIRST, before anything with a side effect, the activity log included; the
 * organisation is always `Site::id()`, never anything posted.
 *
 * Two actions, each asked of the right person here AND again by the writer in
 * `Portal\Core\PublicWebsite` (the installation and an organisation may be
 * different parties — D-013, the #687 precedent):
 *
 *   installation  a global administrator only (`App::isRootAdmin()`);
 *   organisation  this organisation's global administrator
 *                 (`App::isSiteRootAdmin()`, which counts a global
 *                 administrator too) — D-080's "organisation's global admin".
 *
 * ASK WHETHER THE FIELD ARRIVED (#677). A missing `rule` changes nothing; a
 * value that is not text is passed on as '' so the writer refuses it. A
 * looser choice than the installation allows is REFUSED with the reason, never
 * quietly lowered (#668). Every save and every refusal that got past the form
 * token is recorded: this decides what reaches the public internet.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/493
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\EventVisibility;
use Portal\Core\FeedAdmin;
use Portal\Core\Logger;
use Portal\Core\PublicWebsite;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$back   = Site::url('admin/calendar/feeds') . '#website-copied-rule';

// 🛡️ The page's own gate first: an administrator OF THIS ORGANISATION (#514).
if (EventVisibility::isAdminOfSite($mysqli, $userId, $siteId) === false) {
    Router::renderError(403);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $back);
    exit();
}

// 🔐 Form token FIRST, before anything with a side effect, the log included.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Nothing was changed. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $back);
    exit();
}

$action = is_string($_POST['action'] ?? null) === true ? $_POST['action'] : '';

if (array_key_exists('rule', $_POST) === false) {
    $outcome = ['saved' => true, 'type' => 'info', 'message' => 'No rule was sent, so nothing changed.', 'change' => ''];
} else {
    $posted = is_string($_POST['rule']) === true ? $_POST['rule'] : '';
    if ($action === 'installation') {
        $outcome = PublicWebsite::saveRuleForInstallation($mysqli, $posted, App::isRootAdmin());
    } elseif ($action === 'organisation') {
        $outcome = PublicWebsite::saveRuleForOrganisation($mysqli, $siteId, $posted, App::isSiteRootAdmin());
    } else {
        $outcome = ['saved' => false, 'type' => 'danger', 'message' => 'That form was not recognised. Nothing was saved.', 'change' => ''];
    }
    // 🌐 Round 6 of #493 P1's check: the rule decides what the resolver stores
    //    and what needs approval, so every calendar it governs is re-worked
    //    NOW. Left to each calendar's next refresh, the old answer could stand
    //    for about a day: a refresh that finds the outside file unchanged does
    //    not re-work anything.
    //    It runs on EVERY successful save, a save that changed nothing
    //    included (round 7): that is what makes "save the rule again to retry
    //    them" true. The save is recorded BEFORE the sweep, so a sweep a host's
    //    time-out ends part-way still leaves the record of the save.
    $reworkAfter = $outcome['saved'] === true;
    // An unrecognised form is said as such: it used to be logged as a change
    // "for organisation N", which was not what anybody asked for.
    $level = match ($action) {
        'installation' => 'the whole installation',
        'organisation' => 'organisation ' . $siteId,
        default        => 'a form that was not recognised (organisation ' . $siteId . ')',
    };
    Logger::activity(
        $outcome['saved'] === true ? 'WebsiteCopiedRuleSaved' : 'WebsiteCopiedRuleRefused',
        $outcome['saved'] === true
            ? 'How copied-in events reach the public website, for ' . $level . ': '
              . ($outcome['change'] === '' ? 'nothing was different.' : $outcome['change'] . '.')
            : 'A change to how copied-in events reach the public website, for ' . $level . ', was refused: ' . $outcome['message'],
        $userId > 0 ? $userId : null
    );
    if ($reworkAfter === true) {
        $reworked = FeedAdmin::reworkCalendars($mysqli, $action === 'installation' ? null : $siteId);
        $outcome['message'] .= ' ' . ($reworked['done'] === 1 ? '1 outside calendar was' : $reworked['done'] . ' outside calendars were')
            . ' re-worked at once to follow it.';
        if ($reworked['failed'] > 0) {
            $outcome['type']     = 'warning';
            $outcome['message'] .= ' ' . $reworked['failed'] . ' could not be; save the rule again to retry them.';
        }
        Logger::activity('WebsiteCopiedRuleReworked',
            'Outside calendars re-worked after the copied-in events rule was saved, for ' . $level . ': '
            . $reworked['done'] . ' done, ' . $reworked['failed'] . ' failed.',
            $userId > 0 ? $userId : null);
    }
}

$_SESSION['flash_msg']  = $outcome['message'];
$_SESSION['flash_type'] = $outcome['type'];
header('Location: ' . $back);
exit();
