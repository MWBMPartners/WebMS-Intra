<?php
// Path: _apps/admin/calendar/feeds-edit.php
/**
 * -----------------------------------------------------------------------------
 * Admin — add an outside calendar, or change one's settings 🗓️⚙️
 * -----------------------------------------------------------------------------
 * `admin/calendar/feeds/edit` adds a new outside calendar; `…/edit?id=N`
 * changes calendar N of THIS organisation. The form posts to itself, so a
 * refusal draws the page again with the message and everything that was
 * typed (nothing is lost), and a save goes to the calendar's own page.
 *
 * WHAT CAN BE SET (the #514 part 8 plan, section 5.1)
 * ---------------------------------------------------
 * The name; the calendar's address; how often it is checked; the time zone
 * used for times the outside calendar gives WITHOUT a zone; a default
 * category; who can see its events (Everyone, Members of this organisation —
 * the choice for a new calendar — or Selected groups and people, with the
 * list); "Also show on the public website" (only for Everyone); and "Don't
 * show via API". Pausing, resuming and deleting are the list's buttons,
 * through the importer, so there is one way to do each, not two.
 *
 * EVERY RULE LIVES IN ONE CLASS
 * -----------------------------
 * This page decides nothing about what may be saved. It hands what was typed
 * to `FeedAdmin::addCalendar()` or `FeedAdmin::saveCalendar()`, which check
 * every field (and refuse, never cut short, anything too long), check the
 * acting account is an administrator of this organisation AGAIN, take the
 * calendar's row lock, write, and work out who may see each event with the
 * database's own clock, all in one transaction. The page shows the plain
 * sentence it gets back, escaped, exactly as it is.
 *
 * AFTER A SAVE
 * ------------
 * A new calendar, and a calendar whose ADDRESS or TIME ZONE changed, is
 * refreshed straight away — AFTER FeedAdmin's transaction has committed,
 * because a refresh manages its own transaction and may take real time (a
 * download). The refresh's own sentence is added to the message, so the
 * administrator finds out now whether the address works rather than hours
 * later. A refresh never throws. A changed address also sends every date
 * that had been approved to be shown MORE widely back for approval, and the
 * message says how many (C10 item 8): the old approval was given for the old
 * calendar's content.
 *
 * WHO MAY USE IT
 * --------------
 * Signed in, and an administrator OF THIS ORGANISATION (a site administrator
 * of it, or a global administrator), else the portal's 403 page. The
 * calendar is looked up by number AND organisation: another organisation's
 * number and a number that does not exist both get the portal's 404 page,
 * drawn by the same statements (the #503 rule).
 *
 * WHAT IT CANNOT DO
 * -----------------
 * - Show the time a new zone takes effect before the refresh has run: a
 *   changed zone re-dates the events only when the calendar is next read.
 *   The save forces that refresh; if it fails, the message says the zone
 *   applies from the next successful refresh.
 * - Keep a calendar address out of the browser's own memory entirely:
 *   `autocomplete="off"` asks the browser not to remember it (some addresses
 *   are secret links, and a shared computer's form history would keep them),
 *   but a browser may ignore the request.
 *
 * @package   Portal\App\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\EventVisibility;
use Portal\Core\ExternalAudience;
use Portal\Core\FeedAdmin;
use Portal\Core\FeedImporter;
use Portal\Core\FeedResolver;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 🛡️ An administrator OF THIS ORGANISATION — whatever number was sent.
if (EventVisibility::isAdminOfSite($mysqli, $userId, $siteId) === false) {
    Router::renderError(403);
    return;
}

$listUrl = Site::url('admin/calendar/feeds');
$editUrl = Site::url('admin/calendar/feeds/edit');
$viewUrl = Site::url('admin/calendar/feeds/view');

// 🔎 Which calendar. No `id` (or an empty one) is a new calendar. Anything
//    else must be a whole positive number of a calendar of THIS
//    organisation; "12abc", an array, another organisation's number and a
//    number that does not exist all get the same 404 page.
$feedId   = 0;
$calendar = null;
if (array_key_exists('id', $_GET) === true && $_GET['id'] !== '') {
    $idRaw  = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    $feedId = ($idRaw === false || $idRaw <= 0) ? 0 : (int) $idRaw;
    $calendar = ($feedId > 0) ? FeedAdmin::calendar($mysqli, $feedId, $siteId) : null;
    if ($calendar === null) {
        Router::renderError(404);
        return;
    }
}
$isNew   = ($calendar === null);
$selfUrl = ($isNew === true) ? $editUrl : $editUrl . '?id=' . $feedId;

/** Leave one message and go to `$url` (the house flash-and-return). */
$finish = static function (string $message, string $type, string $url): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $url, true, 302);
    exit();
};

/**
 * One posted text field, or '' when it is missing or not text at all. A
 * field sent as a list (`name[]=x`) would otherwise reach FeedAdmin as an
 * array and be turned into the word "Array" (P8-1 check round 7).
 */
$postText = static function (string $field): string {
    $value = $_POST[$field] ?? '';

    return is_string($value) === true ? $value : '';
};

$refusal = '';   // FeedAdmin's sentence when the save was refused
$typed   = null; // what was posted, to draw the form again after a refusal

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // 🔐 The form token, before anything with a side effect.
    if (Auth::verifyCsrf($postText('csrf_token')) === false) {
        $finish('Invalid or expired form token. Nothing was changed. Please try again.', 'danger', $selfUrl);
    }
    if ($postText('action') !== 'save') {
        $finish('Unknown action. Nothing was changed.', 'danger', $selfUrl);
    }

    $post = [
        'name'           => $postText('name'),
        'url'            => $postText('url'),
        'fetchEveryMins' => $postText('fetchEveryMins'),
        'timezone'       => $postText('timezone'),
        'categoryID'     => $postText('categoryID'),
        'audienceLevel'  => $postText('audienceLevel'),
        'websiteOptIn'   => ($postText('websiteOptIn') === '1') ? '1' : '0',
        'apiOptOut'      => ($postText('apiOptOut') === '1') ? '1' : '0',
        // The list is checked entry by entry by ExternalAudience::fromPost()
        // (whole numbers, this organisation's, switched on if new).
        'audience'       => is_array($_POST['audience'] ?? null) === true ? $_POST['audience'] : [],
    ];

    try {
        $result = ($isNew === true)
            ? FeedAdmin::addCalendar($mysqli, $siteId, $userId, $post)
            : FeedAdmin::saveCalendar($mysqli, $feedId, $siteId, $userId, $post);
    } catch (\Throwable $e) {
        // FeedAdmin has already rolled back. The detail goes to the error
        // log for administrators; the person sees one plain sentence. The
        // calendar's ADDRESS is never put into a message or a log title.
        Logger::errorPlatform(
            'FeedAdmin',
            'Error',
            ($isNew === true) ? 'FEED_ADD' : 'FEED_SAVE',
            ($isNew === true) ? 'Adding an outside calendar failed' : 'Saving calendar #' . $feedId . ' failed',
            $e->getMessage()
        );
        $finish('That could not be saved, so nothing was changed. The problem has been recorded.', 'danger', $selfUrl);
    }

    if ($result['status'] === 'not_found') {
        // The calendar went between drawing the form and saving it.
        $finish('That calendar could not be found.', 'danger', $listUrl);
    }

    if ($result['ok'] !== true) {
        // Refused: draw the page again with the sentence and what was typed.
        $refusal = (string) $result['message'];
        $typed   = $post;
    } else {
        $savedId = ($isNew === true) ? (int) $result['feedId'] : $feedId;
        // The person is passed in (chunk P8-4 fix round 1): Logger::activity() names as the
        // line's user only the user it is GIVEN. It does read the session (the session's
        // number and a copy of it, less its form token and its sign-in secrets, go into every line), but it never takes the USER from it,
        // so without this the Activity page showed "System" for an administrator's own action.
        Logger::activity(($isNew === true) ? 'ExternalFeedAdded' : 'ExternalFeedSaved', 'Calendar #' . $savedId, $userId);

        $message = (string) $result['message'];
        $type    = 'success';
        // ⬇️ Refresh straight away — AFTER the save has committed (see the
        //    header): always for a new calendar; for a saved one only when
        //    FeedAdmin says the address or the time zone changed.
        if ($isNew === true || ($result['refreshNeeded'] ?? false) === true) {
            $answer   = FeedImporter::refresh($mysqli, $savedId, 'manual', $userId);
            $message .= ' ' . (string) $answer['message'];
            if ($answer['outcome'] === 'failed') {
                $type = 'warning';
            } elseif ($answer['outcome'] === 'skipped') {
                $type = 'info';
            }
            $zoneChanged = ($isNew === false) && (trim($post['timezone']) !== (string) ($calendar['timezone'] ?? ''));
            if ($zoneChanged === true && in_array($answer['outcome'], ['ok', 'partial'], true) === false) {
                $message .= ' The new time zone applies from the next successful refresh.';
            }
        }
        $finish($message, $type, $viewUrl . '?id=' . $savedId);
    }
}

// 🖊️ The values to draw: what was typed (after a refusal), else the saved
//    calendar, else a new calendar's starting values. A new calendar starts at
//    "Members of this organisation" (the #514 plan): nothing is shown more
//    widely than that until somebody chooses to.
if ($typed !== null) {
    $v = $typed;
} elseif ($calendar !== null) {
    $v = [
        'name'           => (string) $calendar['name'],
        'url'            => (string) $calendar['url'],
        'fetchEveryMins' => (string) $calendar['fetchEveryMins'],
        'timezone'       => (string) ($calendar['timezone'] ?? ''),
        'categoryID'     => $calendar['categoryID'] === null ? '' : (string) $calendar['categoryID'],
        'audienceLevel'  => (string) $calendar['audienceLevel'],
        'websiteOptIn'   => ((int) $calendar['websiteOptIn'] === 1) ? '1' : '0',
        'apiOptOut'      => ((int) $calendar['apiOptOut'] === 1) ? '1' : '0',
        'audience'       => [],
    ];
} else {
    $v = [
        'name' => '', 'url' => '', 'fetchEveryMins' => '360', 'timezone' => '', 'categoryID' => '',
        'audienceLevel' => 'members', 'websiteOptIn' => '0', 'apiOptOut' => '0', 'audience' => [],
    ];
}

// ✅ Which "who may see it" boxes start ticked: exactly what was posted after
//    a refusal, else every entry on the saved list (a switched-off group or a
//    person who has left starts ticked, so saving without touching it keeps
//    it — see the picker's header).
$currentList = ($calendar !== null) ? (array) ($calendar['list'] ?? []) : [];
$ticked      = [];
if ($typed !== null) {
    foreach ((array) $typed['audience'] as $kind => $values) {
        foreach ((array) $values as $value) {
            if (is_scalar($value) === true && ctype_digit((string) $value) === true) {
                $ticked[(string) $kind . ':' . (int) $value] = true;
            }
        }
    }
} else {
    foreach ($currentList as $entry) {
        $ticked[$entry['kind'] . ':' . $entry['refID']] = true;
    }
}

// 🌍 Time zones. The calendar's STORED zone is always offered and selected,
//    even when this server's own list lacks it (the lists differ between
//    servers — #557; challenge L7), so saving the form unchanged never fails.
$zones      = \DateTimeZone::listIdentifiers();
$storedZone = ($calendar !== null) ? (string) ($calendar['timezone'] ?? '') : '';
$orgZone    = FeedResolver::organisationZone($siteId)->getName();

// 🏷️ Categories: the organisation's switched-on ones. A STORED category that
//    has since been switched off stays selectable as it is (FeedAdmin keeps it
//    when it is saved unchanged), shown without a name, because a page may
//    read only what FeedAdmin hands it.
$categories       = FeedAdmin::categories($mysqli, $siteId);
$storedCategoryId = ($calendar !== null && $calendar['categoryID'] !== null) ? (int) $calendar['categoryID'] : 0;
$storedIsListed   = false;
foreach ($categories as $c) {
    if ($c['categoryID'] === $storedCategoryId) {
        $storedIsListed = true;
    }
}

$options = ExternalAudience::options($mysqli, $siteId);

// 💬 A message left by a redirect back to this page (a stale form token).
$flashMsg  = (string) ($_SESSION['flash_msg']  ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? '');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
if (in_array($flashType, ['success', 'info', 'warning', 'danger'], true) === false) {
    $flashType = 'info';
}

$esc       = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$pageTitle = ($isNew === true) ? 'Add an outside calendar' : 'Settings: ' . (string) $calendar['name'];
$breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Outside calendars' => $listUrl];
if ($isNew === false) {
    // 🍞 Quoted, so a calendar named "Dashboard", "Settings" or "Add" can never
    //    replace one of the trail's own entries (the trail is keyed by its
    //    words; fix round 1, L5 — see feeds-view.php).
    //    A name over 40 characters is cut short there, as on the calendar's
    //    page (a long name with no spaces pushed the trail off a phone's screen).
    $trailName = (string) $calendar['name'];
    $trailName = (mb_strlen($trailName, 'UTF-8') > 40) ? mb_substr($trailName, 0, 39, 'UTF-8') . '…' : $trailName;
    $breadcrumbs['“' . $trailName . '”'] = $viewUrl . '?id=' . $feedId;
}
$breadcrumbs[($isNew === true) ? 'Add' : 'Settings'] = '';
$levels = ['public', 'members', 'groups']; // a calendar offers these three (its column has no "hidden")

require_once __DIR__ . DIRECTORY_SEPARATOR . '_audience-picker.php';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:880px;">
    <h1 class="h4 mb-3 text-break"><i class="fa-solid fa-rss me-2 text-primary" aria-hidden="true"></i><?php echo $esc($pageTitle); ?></h1>

    <?php if ($flashMsg !== ''): ?>
        <div class="alert alert-<?php echo $esc($flashType); ?>" role="alert"><?php echo $esc($flashMsg); ?></div>
    <?php endif; ?>
    <?php if ($refusal !== ''): ?>
        <div class="alert alert-danger" role="alert"><?php echo $esc($refusal); ?></div>
    <?php endif; ?>

    <form method="post" action="<?php echo $esc($selfUrl); ?>" class="row g-3" id="feed-form">
        <?php // The token is printed after the POST was handled, so it is the current one. ?>
        <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">
        <input type="hidden" name="action" value="save">

        <div class="col-12 col-md-6">
            <label class="form-label" for="feed-name">Name</label>
            <input type="text" class="form-control" id="feed-name" name="name" required maxlength="120"
                   value="<?php echo $esc($v['name']); ?>" placeholder="e.g. Conference calendar">
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label" for="feed-every">Check every (minutes)</label>
            <input type="number" class="form-control" id="feed-every" name="fetchEveryMins" required min="15" max="10080"
                   value="<?php echo $esc($v['fetchEveryMins']); ?>" aria-describedby="feed-every-help">
            <div class="form-text" id="feed-every-help">Between 15 minutes and 7 days (10080 minutes).</div>
        </div>
        <div class="col-12">
            <label class="form-label" for="feed-url">Calendar address</label>
            <input type="url" class="form-control" id="feed-url" name="url" required maxlength="<?php echo (int) FeedAdmin::MAX_ADDRESS_LENGTH; ?>"
                   autocomplete="off" value="<?php echo $esc($v['url']); ?>" aria-describedby="feed-url-help"
                   placeholder="https://example.com/calendar.ics">
            <div class="form-text" id="feed-url-help">
                webcal://, webcals://, http:// and https:// addresses work. Addresses inside a private network are refused.
            </div>
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label" for="feed-zone">Time zone</label>
            <select class="form-select" id="feed-zone" name="timezone" aria-describedby="feed-zone-help">
                <option value=""<?php echo $v['timezone'] === '' ? ' selected' : ''; ?>>The organisation's own time zone (<?php echo $esc($orgZone); ?>)</option>
                <?php if ($storedZone !== '' && in_array($storedZone, $zones, true) === false): ?>
                    <option value="<?php echo $esc($storedZone); ?>"<?php echo $v['timezone'] === $storedZone ? ' selected' : ''; ?>><?php echo $esc($storedZone); ?> (as saved)</option>
                <?php endif; ?>
                <?php foreach ($zones as $zone): ?>
                    <option value="<?php echo $esc($zone); ?>"<?php echo $v['timezone'] === $zone ? ' selected' : ''; ?>><?php echo $esc($zone); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text" id="feed-zone-help">Used only for times the outside calendar gives without a zone.</div>
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label" for="feed-category">Default category</label>
            <select class="form-select" id="feed-category" name="categoryID" aria-describedby="feed-category-help">
                <option value=""<?php echo $v['categoryID'] === '' ? ' selected' : ''; ?>>None</option>
                <?php if ($storedCategoryId > 0 && $storedIsListed === false): ?>
                    <option value="<?php echo $storedCategoryId; ?>"<?php echo $v['categoryID'] === (string) $storedCategoryId ? ' selected' : ''; ?>>The current category (switched off)</option>
                <?php endif; ?>
                <?php foreach ($categories as $c): ?>
                    <option value="<?php echo (int) $c['categoryID']; ?>"<?php echo $v['categoryID'] === (string) $c['categoryID'] ? ' selected' : ''; ?>><?php echo $esc($c['categoryName']); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text" id="feed-category-help">Given to an event whose own words match no category on the calendar's page.</div>
        </div>

        <div class="col-12">
            <fieldset aria-describedby="feed-level-help">
                <legend class="form-label fs-6 mb-1">Who can see its events</legend>
                <?php foreach ($levels as $level): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="audienceLevel" id="feed-level-<?php echo $esc($level); ?>"
                               value="<?php echo $esc($level); ?>"<?php echo $v['audienceLevel'] === $level ? ' checked' : ''; ?>>
                        <label class="form-check-label" for="feed-level-<?php echo $esc($level); ?>"><?php echo $esc(FeedAdmin::LEVEL_LABELS[$level]); ?></label>
                    </div>
                <?php endforeach; ?>
                <div class="form-text" id="feed-level-help">
                    Single dates, repeating events and rules can show some events more widely or more narrowly later, on the calendar's own page.
                </div>
            </fieldset>
        </div>

        <div class="col-12" id="feed-audience-block">
            <h2 class="h6">Selected groups and people</h2>
            <?php portal_feed_audience_picker(['prefix' => 'feed', 'options' => $options, 'current' => $currentList, 'ticked' => $ticked]); ?>
        </div>

        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="feed-website" name="websiteOptIn" value="1"
                       aria-describedby="feed-website-help"<?php echo $v['websiteOptIn'] === '1' ? ' checked' : ''; ?>>
                <label class="form-check-label" for="feed-website">Also show on the public website</label>
            </div>
            <div class="form-text" id="feed-website-help">Adds these events to the countdown widget and the sitemap. Only for "Everyone".</div>
        </div>
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="feed-api" name="apiOptOut" value="1"
                       aria-describedby="feed-api-help"<?php echo $v['apiOptOut'] === '1' ? ' checked' : ''; ?>>
                <label class="form-check-label" for="feed-api">Don't show via API</label>
            </div>
            <div class="form-text" id="feed-api-help">
                API keys will not receive this calendar's events. Leave it clear and API keys receive exactly what a signed-out visitor sees.
            </div>
        </div>

        <div class="col-12 d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary"><?php echo $isNew === true ? 'Add calendar' : 'Save'; ?></button>
            <a class="btn btn-outline-secondary" href="<?php echo $esc($isNew === true ? $listUrl : $viewUrl . '?id=' . $feedId); ?>">Cancel</a>
        </div>
    </form>
</div>
<?php // 🪄 Convenience only — the page works without it, and the server
      //    enforces both rules anyway: the list is shown only for "Selected
      //    groups and people", and the website box can be ticked only for
      //    "Everyone" (FeedAdmin stores it as 0 at any other level). ?>
<script nonce="<?php echo $esc(App::cspNonce()); ?>">
(function () {
    'use strict';
    var form = document.getElementById('feed-form');
    var block = document.getElementById('feed-audience-block');
    var website = document.getElementById('feed-website');
    if (form === null || block === null || website === null) {
        return;
    }
    function chosen() {
        var picked = form.querySelector('input[name="audienceLevel"]:checked');
        return picked === null ? '' : picked.value;
    }
    function apply() {
        var level = chosen();
        block.hidden = (level !== 'groups');
        website.disabled = (level !== 'public');
    }
    form.querySelectorAll('input[name="audienceLevel"]').forEach(function (radio) {
        radio.addEventListener('change', apply);
    });
    apply();
})();
</script>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
