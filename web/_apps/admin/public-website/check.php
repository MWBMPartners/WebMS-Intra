<?php
// Path: _apps/admin/public-website/check.php
/**
 * -----------------------------------------------------------------------------
 * Admin → Public website: "Check my set-up" 🔎 (#493, package P3)
 * -----------------------------------------------------------------------------
 * Address: /admin/public-website/check (POST only, with the form token). Runs
 * `PublicWebsiteSetup::checkSetUp()` for the organisation open now, keeps the
 * answer in the session for ONE showing (under that organisation's number),
 * and sends the person back to the page. It changes no setting. It does leave
 * two traces, said plainly: a row in the rate-limit table counting this run
 * (`RateLimiter::recordHit()`, removed by its own clear-out), and — for most
 * ways the main website can fail to answer — a notice in the portal's own
 * error log (Admin, Error log) written by `SafeFetch`, naming the website's
 * host and the HTTP status.
 *
 * WHY POST AND A LIMIT: the check makes the portal's server fetch the
 * organisation's main website through `SafeFetch` — at most
 * `PublicWebsiteSetup::CHECK_TIMEOUT_SECONDS` for each step and
 * `CHECK_DEADLINE_SECONDS` in all, following up to `CHECK_MAX_REDIRECTS`
 * redirects; looking a name up is not limited by either, because PHP's name
 * lookup cannot be given a time limit — and look the public address up. A GET could be triggered by a link on any website, and an
 * unlimited button is a way to make the server knock on somebody's door over
 * and over. So it needs the form token, and one person may run it
 * `PublicWebsiteSetup::CHECK_LIMIT` times in `CHECK_WINDOW_SECONDS`
 * (`RateLimiter`, the portal's generic limiter).
 *
 * A WEBSITE IS NEVER TICKED. The check says whether the public door is on
 * and gives its addresses, and since #493 P4d it also fetches the
 * organisation's public address — once, at the door's check address, when
 * that address is approved and the door would answer there — and says
 * whether the answer carried the proof only a door holding this
 * organisation's current door name can work out
 * (`PublicWebsiteSetup::publicAddressRow()`). That is one more outside
 * fetch, inside the same limit below. Whether a WEBSITE really shows the
 * door is still always "not tested": the only page of the organisation's
 * own website the check fetches is the main website's front page, to see
 * that it answers, and it never looks for the door there, so a tick would be
 * a claim nobody can stand behind.
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

use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\PublicWebsiteSetup;
use Portal\Core\RateLimiter;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$back   = Site::url('admin/public-website');

// POST only: a GET runs nothing and is sent back to the page.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $back, true, 302);
    exit();
}

if (PublicWebsiteSetup::maySetUp($mysqli, $userId, $siteId) === false) {
    Logger::activity(
        'PublicWebsiteSetupRefused',
        'Refused: "Check my set-up" for organisation #' . $siteId . ' by somebody who may not set up its public website.',
        $userId > 0 ? $userId : null
    );
    Router::renderError(403);
    return;
}

$finish = static function (string $message, string $type) use ($back): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $back, true, 302);
    exit();
};

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $finish('Invalid or expired form token. Nothing was checked. Please try again.', 'danger');
}

$bucket = 'pwsetup:check:' . $userId;
if (RateLimiter::tooMany($bucket, PublicWebsiteSetup::CHECK_LIMIT, PublicWebsiteSetup::CHECK_WINDOW_SECONDS) === true) {
    $finish('The set-up check has been run ' . PublicWebsiteSetup::CHECK_LIMIT . ' times in the last '
        . (int) (PublicWebsiteSetup::CHECK_WINDOW_SECONDS / 60) . ' minutes. Please wait a few minutes and try again. Nothing was checked.', 'warning');
}
RateLimiter::recordHit($bucket, PublicWebsiteSetup::CHECK_WINDOW_SECONDS, 'pwsetup:check:');

// Kept under the ORGANISATION's number, so another tab open on a different
// organisation can never show this one's result (round 1 of P3's check).
$_SESSION['public_website_check'][$siteId] = PublicWebsiteSetup::checkSetUp($mysqli, $siteId);
header('Location: ' . $back, true, 302);
exit();
