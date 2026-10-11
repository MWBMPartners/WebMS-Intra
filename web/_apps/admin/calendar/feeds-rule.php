<?php
// Path: _apps/admin/calendar/feeds-rule.php
/**
 * -----------------------------------------------------------------------------
 * Admin — a rule for one outside calendar's events 🗓️📐
 * -----------------------------------------------------------------------------
 * `admin/calendar/feeds/rule?feed=N` writes a new RULE for calendar N of THIS
 * organisation; `…&rule=M` changes rule M of that calendar. A rule says: the
 * events whose category, title or location match these conditions (and none
 * of these exceptions) may be seen by these people, in this much detail,
 * between these days. The form posts to itself:
 *
 *   preview  draws the page again with what the rule WOULD do to each event
 *            right now — and writes nothing at all;
 *   save     saves it and returns to the calendar's own page;
 *   delete   (a saved rule only) deletes it and returns there.
 *
 * A refusal draws the page again with the one plain sentence and everything
 * typed, so nothing is lost.
 *
 * EVERY RULE OF THE FORM LIVES IN ONE CLASS
 * ------------------------------------------
 * `FeedAdmin::previewRule()`, `saveRule()` and `deleteRule()` check who is
 * asking again, refuse anything the resolver would treat as matching nothing
 * (C10 item 10 — no ordinary condition, a value that is empty once spaces and
 * invisible characters are removed, unreadable text, a category condition that
 * is not "is exactly", a last day before the first), take the calendar's lock
 * for a save or a delete, and work out who may see each event with the
 * database's own clock. The preview is validated the same way, with the same
 * sentences, and never takes a lock or writes. The page shows FeedAdmin's own
 * sentence and preview labels (`OUTCOME_LABELS`, C10 item 9), escaped.
 *
 * CONDITIONS AND EXCEPTIONS
 * -------------------------
 * Up to 10 of each. All 10 rows of each are always in the page (a row left
 * empty is ignored, so spare rows cost nothing); the first few show and the
 * rest are hidden until "Add a condition" / "Add an exception" reveals the
 * next one. Without a script, the rows already showing (at least three
 * conditions and two exceptions, always one spare) still work, and saving
 * and editing again gives another spare.
 *
 * WHO MAY USE IT
 * --------------
 * Signed in, and an administrator OF THIS ORGANISATION, else the portal's 403
 * page. The calendar is looked up by number AND organisation, and a rule by
 * number AND that calendar AND organisation: another organisation's calendar
 * or rule, a rule of a DIFFERENT calendar of this organisation (challenge
 * L15) and a number that does not exist all get the same 404 page.
 *
 * WHAT IT CANNOT DO
 * -----------------
 * - Promise the preview stays true: it is what the rule would do at the
 *   moment it is drawn. A refresh, another rule or a choice can change that
 *   before the save; the save's own message says what really changed.
 * - Show the events a rule matches outside its days: the preview lists every
 *   event the rule matches and says "Outside the rule's dates" for those.
 *
 * Twin page: `feeds-choice.php` draws the same "who may see it" block (level,
 * list, detail, website box, API box, days). Change one, look at the other.
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
use Portal\Core\FeedResolver;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 🛡️ An administrator OF THIS ORGANISATION — whatever numbers were sent.
if (EventVisibility::isAdminOfSite($mysqli, $userId, $siteId) === false) {
    Router::renderError(403);
    return;
}

// 🔎 The calendar, then (when given) the rule of THAT calendar. Every way of
//    not finding either gets the same 404 page (see the header).
$feedRaw  = filter_var($_GET['feed'] ?? null, FILTER_VALIDATE_INT);
$feedId   = ($feedRaw === false || $feedRaw === null || $feedRaw <= 0) ? 0 : (int) $feedRaw;
$calendar = ($feedId > 0) ? FeedAdmin::calendar($mysqli, $feedId, $siteId) : null;
if ($calendar === null) {
    Router::renderError(404);
    return;
}
$ruleId = 0;
$rule   = null;
if (array_key_exists('rule', $_GET) === true && $_GET['rule'] !== '') {
    $ruleRaw = filter_var($_GET['rule'], FILTER_VALIDATE_INT);
    $ruleId  = ($ruleRaw === false || $ruleRaw <= 0) ? 0 : (int) $ruleRaw;
    $rule    = ($ruleId > 0) ? FeedAdmin::rule($mysqli, $ruleId, $feedId, $siteId) : null;
    if ($rule === null) {
        Router::renderError(404);
        return;
    }
}
$isNew   = ($rule === null);
$listUrl = Site::url('admin/calendar/feeds');
$viewUrl = Site::url('admin/calendar/feeds/view') . '?id=' . $feedId;
$selfUrl = Site::url('admin/calendar/feeds/rule') . '?feed=' . $feedId . ($isNew === true ? '' : '&rule=' . $ruleId);

/** Leave one message and go to `$url` (the house flash-and-return). */
$finish = static function (string $message, string $type, string $url): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $url, true, 302);
    exit();
};

/** One posted text field, or '' when it is missing or not text (a list would become "Array"). */
$postText = static function (string $field): string {
    $value = $_POST[$field] ?? '';

    return is_string($value) === true ? $value : '';
};

$failed     = 'That could not be saved, so nothing was changed. The problem has been recorded.';
$refusal    = '';
$typed      = null;
$preview    = null; // FeedAdmin's preview answer, when "Preview" was pressed and accepted

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // 🔐 The form token, before anything else — the preview included,
    //    although it writes nothing (plan 3.2, rule 3).
    if (Auth::verifyCsrf($postText('csrf_token')) === false) {
        $finish('Invalid or expired form token. Nothing was changed. Please try again.', 'danger', $selfUrl);
    }
    $action = $postText('action');

    if ($action === 'delete') {
        if ($isNew === true) {
            $finish('Unknown action. Nothing was changed.', 'danger', $selfUrl);
        }
        try {
            $result = FeedAdmin::deleteRule($mysqli, $ruleId, $feedId, $siteId, $userId);
        } catch (\Throwable $e) {
            Logger::errorPlatform('FeedAdmin', 'Error', 'RULE_DELETE', 'Deleting rule #' . $ruleId . ' of calendar #' . $feedId . ' failed', $e->getMessage());
            $finish($failed, 'danger', $selfUrl);
        }
        if ($result['ok'] !== true) {
            $finish((string) $result['message'], 'danger', $listUrl);
        }
        // The person is passed in (chunk P8-4 fix round 1): Logger::activity() names as the
        // line's user only the user it is GIVEN. It does read the session (the session's
        // number and a copy of it, less its form token and its sign-in secrets, go into every line), but it never takes the USER from it,
        // so without this the Activity page showed "System" for an administrator's own action.
        Logger::activity('ExternalRuleDeleted', 'Rule #' . $ruleId . ' (calendar #' . $feedId . ')', $userId);
        $finish((string) $result['message'], 'success', $viewUrl . '#rules');
    }

    if ($action !== 'preview' && $action !== 'save') {
        $finish('Unknown action. Nothing was changed.', 'danger', $selfUrl);
    }

    // Only TEXT reaches FeedAdmin: each condition row keeps its four fields as
    // text, and a row that is not a list of fields is dropped.
    $conditions = [];
    foreach ((is_array($_POST['conditions'] ?? null) === true ? $_POST['conditions'] : []) as $row) {
        if (is_array($row) === false) {
            continue;
        }
        $conditions[] = [
            'matchField'  => is_string($row['matchField'] ?? null) === true ? $row['matchField'] : '',
            'matchType'   => is_string($row['matchType'] ?? null) === true ? $row['matchType'] : '',
            'matchValue'  => is_string($row['matchValue'] ?? null) === true ? $row['matchValue'] : '',
            'isException' => (($row['isException'] ?? '') === '1') ? '1' : '0',
        ];
    }
    $post = [
        'name'          => $postText('name'),
        'isActive'      => ($postText('isActive') === '1') ? '1' : '0',
        'audienceLevel' => $postText('audienceLevel'),
        'audience'      => is_array($_POST['audience'] ?? null) === true ? $_POST['audience'] : [],
        'detailLevel'   => $postText('detailLevel'),
        'websiteOptIn'  => ($postText('websiteOptIn') === '1') ? '1' : '0',
        'apiOptOut'     => ($postText('apiOptOut') === '1') ? '1' : '0',
        'fromDate'      => $postText('fromDate'),
        'toDate'        => $postText('toDate'),
        'conditions'    => $conditions,
    ];

    try {
        $result = ($action === 'preview')
            ? FeedAdmin::previewRule($mysqli, $feedId, $ruleId, $siteId, $userId, $post)
            : FeedAdmin::saveRule($mysqli, $feedId, $ruleId, $siteId, $userId, $post);
    } catch (\Throwable $e) {
        Logger::errorPlatform(
            'FeedAdmin', 'Error', ($action === 'preview') ? 'RULE_PREVIEW' : 'RULE_SAVE',
            (($action === 'preview') ? 'Previewing' : 'Saving') . ' a rule of calendar #' . $feedId . ' failed', $e->getMessage()
        );
        $finish(($action === 'preview') ? 'The preview could not be worked out. The problem has been recorded.' : $failed, 'danger', $selfUrl);
    }
    if ($result['status'] === 'not_found') {
        $finish((string) $result['message'], 'danger', $listUrl);
    }
    $typed = $post;
    if ($result['ok'] !== true) {
        $refusal = (string) $result['message'];
    } elseif ($action === 'preview') {
        $preview = $result;
    } else {
        $savedId = (int) ($result['ruleId'] ?? $ruleId);
        Logger::activity('ExternalRuleSaved', 'Rule #' . $savedId . ' (calendar #' . $feedId . ')', $userId);
        $finish((string) $result['message'], 'success', $viewUrl . '#rules');
    }
}

// 🖊️ The values to draw: what was typed (after a refusal or a preview), else
//    the saved rule, else a new rule's starting values (switched on, Members).
if ($typed !== null) {
    $v = $typed;
} elseif ($rule !== null) {
    $v = [
        'name' => (string) $rule['name'], 'isActive' => ((int) $rule['isActive'] === 1) ? '1' : '0',
        'audienceLevel' => (string) $rule['audienceLevel'], 'detailLevel' => (string) $rule['detailLevel'],
        'websiteOptIn' => ((int) $rule['websiteOptIn'] === 1) ? '1' : '0', 'apiOptOut' => ((int) $rule['apiOptOut'] === 1) ? '1' : '0',
        'fromDate' => (string) ($rule['fromDate'] ?? ''), 'toDate' => (string) ($rule['toDate'] ?? ''),
        'audience' => [], 'conditions' => array_map(static fn (array $c): array => [
            'matchField' => (string) $c['matchField'], 'matchType' => (string) $c['matchType'],
            'matchValue' => (string) $c['matchValue'], 'isException' => ((int) $c['isException'] === 1) ? '1' : '0',
        ], (array) $rule['conditions']),
    ];
} else {
    $v = [
        'name' => '', 'isActive' => '1', 'audienceLevel' => 'members', 'detailLevel' => 'basic', 'websiteOptIn' => '0',
        'apiOptOut' => '0', 'fromDate' => '', 'toDate' => '', 'audience' => [], 'conditions' => [],
    ];
}
// The rows: conditions first, then exceptions, each padded to 10 (see the
// header). Empty rows posted back (the spares) are left out here, so a page
// drawn again after a preview or a refusal shows the filled rows plus one
// spare, not all twenty.
$ordinaryRows  = array_values(array_filter($v['conditions'], static fn (array $c): bool => $c['isException'] !== '1' && trim($c['matchValue']) !== ''));
$exceptionRows = array_values(array_filter($v['conditions'], static fn (array $c): bool => $c['isException'] === '1' && trim($c['matchValue']) !== ''));
$showOrdinary  = min(FeedAdmin::MAX_CONDITIONS, max(3, count($ordinaryRows) + 1));
$showException = min(FeedAdmin::MAX_CONDITIONS, max(2, count($exceptionRows) + 1));

$currentList = ($rule !== null) ? (array) $rule['list'] : [];
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
$options = ExternalAudience::options($mysqli, $siteId);
$orgZone = FeedResolver::organisationZone($siteId);

$flashMsg  = (string) ($_SESSION['flash_msg']  ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? '');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
if (in_array($flashType, ['success', 'info', 'warning', 'danger'], true) === false) {
    $flashType = 'info';
}

$esc       = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
// The title and the trail's last entry say the same thing (fix round 1 of
// chunk P8-3): the rule's own name is shown under the title instead, where a
// long name can wrap, rather than in the trail.
$pageTitle = ($isNew === true) ? 'Add a rule' : 'Edit a rule';
$trailName = (string) $calendar['name'];
$trailName = (mb_strlen($trailName, 'UTF-8') > 40) ? mb_substr($trailName, 0, 39, 'UTF-8') . '…' : $trailName;
$breadcrumbs = [
    'Dashboard'            => Site::url(''),
    'Admin'                => Site::url('admin'),
    'Outside calendars'    => $listUrl,
    '“' . $trailName . '”' => $viewUrl,
    $pageTitle             => '',
];
$levels  = ['public', 'members', 'groups', 'hidden'];
$details = ['basic', 'full'];

/**
 * One condition row: field, way of matching, value. `$n` is its place in the
 * posted list (0-9 conditions, 10-19 exceptions); `$visible` whether it shows
 * before any "Add …" press.
 *
 * @param array{matchField:string, matchType:string, matchValue:string} $c
 */
$conditionRow = static function (int $n, array $c, bool $isException, bool $visible, int $shownNumber) use ($esc): void {
    $id     = 'rule-c' . $n;
    $what   = ($isException === true) ? 'Exception ' . $shownNumber : 'Condition ' . $shownNumber;
    ?>
    <div class="row g-2 mb-2 align-items-end" data-rule-row="<?php echo $isException === true ? 'exception' : 'condition'; ?>"<?php echo $visible === true ? '' : ' hidden'; ?>>
        <input type="hidden" name="conditions[<?php echo $n; ?>][isException]" value="<?php echo $isException === true ? '1' : '0'; ?>">
        <div class="col-12 col-md-3">
            <label class="form-label small mb-0" for="<?php echo $esc($id); ?>-field"><?php echo $esc($what); ?>: field</label>
            <select class="form-select form-select-sm" id="<?php echo $esc($id); ?>-field" name="conditions[<?php echo $n; ?>][matchField]">
                <?php foreach (FeedAdmin::MATCH_FIELD_LABELS as $key => $label): ?>
                    <option value="<?php echo $esc($key); ?>"<?php echo $c['matchField'] === $key ? ' selected' : ''; ?>><?php echo $esc($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-3">
            <label class="form-label small mb-0" for="<?php echo $esc($id); ?>-type"><?php echo $esc($what); ?>: match</label>
            <select class="form-select form-select-sm" id="<?php echo $esc($id); ?>-type" name="conditions[<?php echo $n; ?>][matchType]">
                <?php foreach (FeedAdmin::MATCH_TYPE_LABELS as $key => $label): ?>
                    <option value="<?php echo $esc($key); ?>"<?php echo $c['matchType'] === $key ? ' selected' : ''; ?>><?php echo $esc($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label small mb-0" for="<?php echo $esc($id); ?>-value"><?php echo $esc($what); ?>: value</label>
            <input type="text" class="form-control form-control-sm" id="<?php echo $esc($id); ?>-value" name="conditions[<?php echo $n; ?>][matchValue]"
                   maxlength="255" value="<?php echo $esc($c['matchValue']); ?>">
        </div>
    </div>
    <?php
};
$blank = ['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => ''];

require_once __DIR__ . DIRECTORY_SEPARATOR . '_audience-picker.php';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:960px;">
    <h1 class="h4 mb-1 text-break"><i class="fa-solid fa-ruler me-2 text-primary" aria-hidden="true"></i><?php echo $esc($pageTitle); ?></h1>
    <?php if ($isNew === false): ?>
        <p class="mb-1 text-break" id="rule-saved-name"><strong><?php echo $esc($rule['name']); ?></strong></p>
    <?php endif; ?>
    <?php // "More widely than the calendar's own setting", not "than before"
          // (chunk P8-4 fix round 1): that is what the resolver compares with —
          // the calendar's own setting, or "administrators only" for a date
          // marked private. The same words as the inbox and the guide. ?>
    <p class="small text-muted text-break mb-3">
        For the outside calendar <a href="<?php echo $esc($viewUrl); ?>"><?php echo $esc($calendar['name']); ?></a>.
        A choice for one date, or for every date of a repeating event, decides those dates instead of any rule. Showing an
        event more widely than the calendar's own setting — or, for a date the outside calendar marks private, to anyone
        but administrators — waits for an administrator's approval.
    </p>

    <?php if ($flashMsg !== ''): ?>
        <div class="alert alert-<?php echo $esc($flashType); ?>" role="alert"><?php echo $esc($flashMsg); ?></div>
    <?php endif; ?>
    <?php if ($refusal !== ''): ?>
        <div class="alert alert-danger" role="alert"><?php echo $esc($refusal); ?></div>
    <?php endif; ?>

    <?php if ($preview !== null): ?>
        <section class="mb-4" id="rule-preview" aria-labelledby="rule-preview-heading">
            <h2 class="h5" id="rule-preview-heading"><?php echo $esc($preview['message']); ?></h2>
            <?php if ((int) ($preview['apiOptOut'] ?? 0) === 1): ?>
                <p class="small">These events will not be sent to API keys.</p>
            <?php endif; ?>
            <?php if ($preview['lines'] === []): ?>
                <p class="small text-muted">No event of this calendar matches this rule now.</p>
            <?php else: ?>
                <div class="portal-data-list">
                <?php foreach ($preview['lines'] as $line): ?>
                    <div class="portal-data-row">
                        <div class="portal-data-row-main text-break" style="flex: 1 1 auto; min-width: 0;">
                            <strong><?php echo $esc($line['title']); ?></strong>
                            <?php // `text-wrap text-start`: a label can be 70 characters long, and a
                                  // badge never wraps by itself — at phone width it reached 49
                                  // pixels past its row (measured in Edge at 390 pixels wide). ?>
                            <span class="badge bg-light text-dark border ms-1 text-wrap text-start"><?php echo $esc($line['label']); ?></span>
                            <?php // The event's own zone and all-day flag, as on the choice and
                                  // calendar pages (fix round 1 of chunk P8-3, check finding L1:
                                  // passing null and false printed an all-day event as "00:00" and
                                  // dropped a New York event's zone). ?>
                            <div class="small text-muted"><?php echo $esc(FeedAdmin::showWallClock((string) $line['startDateTime'], $line['timezone'] === null ? null : (string) $line['timezone'], (int) $line['isAllDay'] === 1, $orgZone)); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <form method="post" action="<?php echo $esc($selfUrl); ?>" class="row g-3" id="rule-form">
        <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">

        <div class="col-12 col-md-8">
            <label class="form-label" for="rule-name">Name</label>
            <input type="text" class="form-control" id="rule-name" name="name" required maxlength="120" value="<?php echo $esc($v['name']); ?>"
                   placeholder="e.g. Outreach events for everyone">
        </div>
        <div class="col-12 col-md-4 d-flex align-items-end">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="rule-active" name="isActive" value="1"<?php echo $v['isActive'] === '1' ? ' checked' : ''; ?>>
                <label class="form-check-label" for="rule-active">Switched on</label>
            </div>
        </div>

        <div class="col-12">
            <fieldset aria-describedby="rule-conditions-help">
                <legend class="form-label fs-6 mb-1">Events must match ALL of these</legend>
                <p class="form-text mt-0" id="rule-conditions-help">
                    A row left empty is ignored. A category condition can only use "is exactly". Up to <?php echo (int) FeedAdmin::MAX_CONDITIONS; ?>.
                </p>
                <?php for ($i = 0; $i < FeedAdmin::MAX_CONDITIONS; $i++): ?>
                    <?php $conditionRow($i, $ordinaryRows[$i] ?? $blank, false, $i < $showOrdinary, $i + 1); ?>
                <?php endfor; ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-rule-add="condition" hidden>Add a condition</button>
            </fieldset>
        </div>
        <div class="col-12">
            <fieldset>
                <legend class="form-label fs-6 mb-1">Except when ANY of these match</legend>
                <?php for ($i = 0; $i < FeedAdmin::MAX_CONDITIONS; $i++): ?>
                    <?php $conditionRow(FeedAdmin::MAX_CONDITIONS + $i, $exceptionRows[$i] ?? $blank, true, $i < $showException, $i + 1); ?>
                <?php endfor; ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-rule-add="exception" hidden>Add an exception</button>
            </fieldset>
        </div>

        <div class="col-12">
            <fieldset>
                <legend class="form-label fs-6 mb-1">Who can see the events it matches</legend>
                <?php foreach ($levels as $level): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="audienceLevel" id="rule-level-<?php echo $esc($level); ?>"
                               value="<?php echo $esc($level); ?>"<?php echo $v['audienceLevel'] === $level ? ' checked' : ''; ?>>
                        <label class="form-check-label" for="rule-level-<?php echo $esc($level); ?>"><?php echo $esc(FeedAdmin::LEVEL_LABELS[$level]); ?></label>
                    </div>
                <?php endforeach; ?>
            </fieldset>
        </div>

        <div class="col-12" id="rule-audience-block">
            <h2 class="h6">Selected groups and people</h2>
            <?php portal_feed_audience_picker(['prefix' => 'rule', 'options' => $options, 'current' => $currentList, 'ticked' => $ticked]); ?>
        </div>

        <div class="col-12">
            <fieldset aria-describedby="rule-detail-help">
                <legend class="form-label fs-6 mb-1">How much detail</legend>
                <?php foreach ($details as $detail): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="detailLevel" id="rule-detail-<?php echo $esc($detail); ?>"
                               value="<?php echo $esc($detail); ?>"<?php echo $v['detailLevel'] === $detail ? ' checked' : ''; ?>>
                        <label class="form-check-label" for="rule-detail-<?php echo $esc($detail); ?>"><?php echo $esc(FeedAdmin::DETAIL_LABELS[$detail]); ?></label>
                    </div>
                <?php endforeach; ?>
                <div class="form-text" id="rule-detail-help">Detail for people outside the calendar's own audience.</div>
            </fieldset>
        </div>

        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="rule-website" name="websiteOptIn" value="1"
                       aria-describedby="rule-website-help"<?php echo $v['websiteOptIn'] === '1' ? ' checked' : ''; ?>>
                <label class="form-check-label" for="rule-website">Also show on the public website</label>
            </div>
            <div class="form-text" id="rule-website-help">Adds the matching events to the countdown widget and the sitemap. Only for "Everyone".</div>
        </div>
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="rule-api" name="apiOptOut" value="1"
                       aria-describedby="rule-api-help"<?php echo $v['apiOptOut'] === '1' ? ' checked' : ''; ?>>
                <label class="form-check-label" for="rule-api">Don't show via API</label>
            </div>
            <div class="form-text" id="rule-api-help">API keys will not receive the matching events. Leave it clear and API keys receive exactly what a signed-out visitor sees.</div>
            <?php if ((int) $calendar['apiOptOut'] === 1): ?>
                <?php // C10 item 3: one tick anywhere is enough — the calendar's own wins. ?>
                <div class="small text-warning-emphasis mt-1" id="rule-calendar-api-note">This calendar is set not to show via API, so the events this rule matches will not be sent to API keys whatever you choose here.</div>
            <?php endif; ?>
        </div>

        <div class="col-12 col-md-6">
            <label class="form-label" for="rule-from">First day</label>
            <input type="date" class="form-control" id="rule-from" name="fromDate" value="<?php echo $esc($v['fromDate']); ?>" aria-describedby="rule-days-help">
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label" for="rule-to">Last day</label>
            <input type="date" class="form-control" id="rule-to" name="toDate" value="<?php echo $esc($v['toDate']); ?>" aria-describedby="rule-days-help">
        </div>
        <div class="col-12 form-text mt-0" id="rule-days-help">
            Days on the organisation's own calendar. Empty means no start or no end. When a rule's days begin or end, its events
            change only when the scheduled refresh next runs.
        </div>

        <div class="col-12 d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-outline-primary" name="action" value="preview">Preview (saves nothing)</button>
            <button type="submit" class="btn btn-primary" name="action" value="save"><?php echo $isNew === true ? 'Add rule' : 'Save'; ?></button>
            <a class="btn btn-outline-secondary" href="<?php echo $esc($viewUrl . '#rules'); ?>">Cancel</a>
        </div>
    </form>

    <?php if ($isNew === false): ?>
        <form method="post" action="<?php echo $esc($selfUrl); ?>" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn btn-outline-danger btn-sm"
                    data-confirm="Delete this rule? Events it was showing will follow any other rule that matches them, or else the calendar's own setting."
                    data-confirm-destructive="true">Delete this rule</button>
        </form>
    <?php endif; ?>
</div>
<?php // 🪄 Convenience only (the server decides everything): the list shows only
      //    for "Selected groups and people", the website box only for
      //    "Everyone", and "Add …" reveals the next hidden spare row. ?>
<script nonce="<?php echo $esc(App::cspNonce()); ?>">
(function () {
    'use strict';
    var form = document.getElementById('rule-form');
    var block = document.getElementById('rule-audience-block');
    var website = document.getElementById('rule-website');
    if (form === null || block === null || website === null) {
        return;
    }
    function apply() {
        var picked = form.querySelector('input[name="audienceLevel"]:checked');
        var level = picked === null ? '' : picked.value;
        block.hidden = (level !== 'groups');
        website.disabled = (level !== 'public');
    }
    form.querySelectorAll('input[name="audienceLevel"]').forEach(function (radio) {
        radio.addEventListener('change', apply);
    });
    apply();
    form.querySelectorAll('[data-rule-add]').forEach(function (button) {
        var kind = button.getAttribute('data-rule-add');
        function refresh() {
            button.hidden = (form.querySelector('[data-rule-row="' + kind + '"][hidden]') === null);
        }
        button.addEventListener('click', function () {
            var next = form.querySelector('[data-rule-row="' + kind + '"][hidden]');
            if (next !== null) {
                next.hidden = false;
                var input = next.querySelector('input[type="text"]');
                if (input !== null) {
                    input.focus();
                }
            }
            refresh();
        });
        refresh();
    });
})();
</script>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
