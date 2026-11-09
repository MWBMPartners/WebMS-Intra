<?php
// Path: _apps/noticeboard/website.php
/**
 * -----------------------------------------------------------------------------
 * Noticeboard — which posters are on the public website 🌐 (#493, package P2a)
 * -----------------------------------------------------------------------------
 * Lists the organisation's posters, each with "Show on the public website" and
 * the optional first and last day (D-057, D-078). Address: /noticeboard/website.
 *
 * WHY A PAGE OF ITS OWN
 * ---------------------
 * The board's own editor is a generated bundle built from `dc-runtime`, which
 * is not in this repository (#623), so it cannot gain a control. Its save
 * (`api/noticeboard/save`) rewrites the whole board but names only the columns
 * it is given, so the three website columns survive every editor save and a
 * poster it creates starts OFF the website (migration 260). Deleting a poster
 * there takes it off the website too, so one brought back (an old editor tab
 * saving its id) is off until somebody ticks it again.
 *
 * WHO MAY CHANGE IT
 * -----------------
 * Whoever holds "Choose what appears on the public website" (`website.publish`,
 * D-077) — out of the box the organisation's administrators, and anybody given
 * the permission (the Website Editor role exists for it); this is the first
 * page a non-administrator can open that asks it. Anybody else
 * signed in sees each poster's state in words and no controls; a change they
 * post anyway is REFUSED with the reason and recorded, by the same judgement
 * events use (`PublicWebsite::judgePosterFields()`), never silently dropped.
 * The judgement is asked on the values read under a row lock inside the
 * transaction that writes (#647), so two people saving one poster cannot both
 * be judged against the value from before the other's save.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * It does not show anything to the public: the public door is #493 P4, and the
 * foyer slideshow is P2b. What reaches them is decided ONLY by
 * `PublicWebsite::posterWhere()`.
 *
 * @package   Portal\Apps\Noticeboard
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright All Rights Reserved — MWBM Partners Ltd (t/a MWservices)
 * @version   1.0.0
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\PublicWebsite;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId  = Site::id();
$userId  = (int) ($_SESSION['user_id'] ?? 0);
$self    = Site::url('noticeboard/website');
$mayEdit = PublicWebsite::mayPublish($mysqli, $userId, $siteId);
$esc     = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$today   = date('Y-m-d'); // the organisation's calendar: start-up sets PHP's zone to it

// =============================================================================
// 💾 Saving one poster's website fields
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $finish = static function (string $message, string $type) use ($self): never {
        $_SESSION['flash_msg']  = $message;
        $_SESSION['flash_type'] = $type;
        header('Location: ' . $self);
        exit();
    };
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $finish('Invalid or expired form token. Nothing was changed. Please try again.', 'danger');
    }
    $posterId = (is_string($_POST['posterID'] ?? null) === true && ctype_digit($_POST['posterID']) === true) ? (int) $_POST['posterID'] : 0;

    // ONLY what arrived is judged (#677): the marker says the form drew the
    // controls, so an unticked box (sent as nothing) means OFF; with no marker
    // the switch is left alone. The dates arrive as text fields when drawn.
    $arrived = [];
    if (($_POST['websiteFields'] ?? null) === '1') {
        $arrived['showOnWebsite'] = array_key_exists('showOnWebsite', $_POST) === true ? $_POST['showOnWebsite'] : '0';
    }
    foreach (['websiteFrom', 'websiteUntil'] as $field) {
        if (array_key_exists($field, $_POST) === true) {
            $arrived[$field] = $_POST[$field];
        }
    }

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare(
            'SELECT `posterID`, `title`, `showOnWebsite`, `websiteFrom`, `websiteUntil` FROM `tblNoticeboardPosters` '
            . 'WHERE `posterID` = ? AND `siteID` = ? AND `isDeleted` = 0 FOR UPDATE'
        );
        $stmt->bind_param('ii', $posterId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            $mysqli->rollback();
            $finish('That poster could not be found on this noticeboard. Nothing was changed.', 'danger');
        }
        $judged = PublicWebsite::judgePosterFields($arrived, $row, PublicWebsite::mayPublish($mysqli, $userId, $siteId));
        if ($judged['ok'] !== true) {
            $mysqli->rollback();
            if ($judged['kind'] === 'forbidden') {
                PublicWebsite::recordPosterRefusal($posterId, (string) $row['title'], $judged, $userId > 0 ? $userId : null, 'Noticeboard, Public website');
            }
            $finish((string) $judged['reason'], 'danger');
        }
        if ($judged['changed'] === true) {
            $v   = $judged['values'];
            $upd = $mysqli->prepare(
                'UPDATE `tblNoticeboardPosters` SET `showOnWebsite` = ?, `websiteFrom` = ?, `websiteUntil` = ?, `updatedByID` = ? '
                . 'WHERE `posterID` = ? AND `siteID` = ?'
            );
            $upd->bind_param('issiii', $v['showOnWebsite'], $v['websiteFrom'], $v['websiteUntil'], $userId, $posterId, $siteId);
            $upd->execute();
            $upd->close();
        }
        $mysqli->commit();
    } catch (\Throwable $e) {
        try {
            $mysqli->rollback();
        } catch (\Throwable $ignored) {
        }
        throw $e;
    }
    if ($judged['changed'] === true) {
        PublicWebsite::recordPosterChange($posterId, (string) $row['title'], $judged['changes'], $userId > 0 ? $userId : null, 'Noticeboard, Public website');
        $finish('Saved "' . (string) $row['title'] . '": ' . implode('; ', $judged['changes']) . '.', 'success');
    }
    $finish('Nothing was different for "' . (string) $row['title'] . '", so nothing changed.', 'info');
}

// =============================================================================
// 📖 What to draw
// =============================================================================
$posters = [];
$stmt = $mysqli->prepare(
    'SELECT `posterID`, `title`, `scheduleType`, `eventDate`, `weekday`, `showOnWebsite`, `websiteFrom`, `websiteUntil` '
    . 'FROM `tblNoticeboardPosters` WHERE `siteID` = ? AND `isDeleted` = 0 ORDER BY `sortOrder` ASC, `eventDate` ASC, `posterID` ASC'
);
$stmt->bind_param('i', $siteId);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $posters[] = $r;
}
$stmt->close();

// Which of them the ONE rule puts on the public website today — asked of the
// rule itself, never worked out again here.
$onToday = [];
$rule = PublicWebsite::posterWhere('p', $today);
$q = $mysqli->prepare('SELECT p.`posterID` FROM `tblNoticeboardPosters` p WHERE p.`siteID` = ?' . $rule['sql']);
$q->bind_param('i' . $rule['types'], $siteId, ...$rule['params']);
$q->execute();
$qr = $q->get_result();
while ($r = $qr->fetch_assoc()) {
    $onToday[(int) $r['posterID']] = true;
}
$q->close();

$weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$pageTitle   = 'Noticeboard: public website';
$pageSection = 'noticeboard';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Noticeboard' => Site::url('noticeboard'), 'Public website' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3">
    <h1 class="h3 mb-2"><i class="fa-solid fa-globe me-2"></i>Noticeboard: which posters are on the public website</h1>
    <p class="text-muted">
        A poster is on your organisation's public website only while <strong>Show on the public website</strong> is
        ticked and today is between its optional first and last day (both days count; an empty date means no limit;
        the dates follow your organisation's calendar). Every poster starts off the website, including new ones.
        <?php if ($mayEdit === false): ?>
            You can see each poster's setting here; only an administrator of this organisation, or somebody given
            &ldquo;Choose what appears on the public website&rdquo; on the Roles page, can change it.
        <?php endif; ?>
    </p>

    <?php if (isset($_SESSION['flash_msg']) === true): ?>
        <div class="alert alert-<?php echo $esc((string) ($_SESSION['flash_type'] ?? 'info')); ?>" role="alert"><?php echo $esc((string) $_SESSION['flash_msg']); ?></div>
        <?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <?php if ($posters === []): ?>
        <p>There are no posters on the noticeboard yet.</p>
    <?php else: ?>
        <div class="portal-data-list">
            <?php foreach ($posters as $p): ?>
                <?php
                $pid  = (int) $p['posterID'];
                $when = $p['scheduleType'] === 'weekly'
                    ? 'Every ' . ($weekdays[(int) ($p['weekday'] ?? 0)] ?? '')
                    : ((string) ($p['eventDate'] ?? '') !== '' ? (string) $p['eventDate'] : 'No date');
                $on   = (int) $p['showOnWebsite'] === 1;
                ?>
                <div class="portal-data-row" id="poster-<?php echo $pid; ?>">
                    <div class="portal-data-row-main">
                        <strong><?php echo $esc((string) $p['title']); ?></strong>
                        <div class="text-muted small"><?php echo $esc($when); ?></div>
                        <div class="small mt-1" data-poster-state="<?php echo $pid; ?>">
                            <?php if (isset($onToday[$pid]) === true): ?>
                                <span class="badge text-bg-success">On the public website today</span>
                            <?php elseif ($on === true): ?>
                                <span class="badge text-bg-secondary">Ticked, but not today (outside its dates)</span>
                            <?php else: ?>
                                <span class="badge text-bg-light border">Not on the public website</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($mayEdit === true): ?>
                            <form method="post" action="<?php echo $esc($self); ?>" class="row g-2 align-items-end mt-1">
                                <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">
                                <input type="hidden" name="posterID" value="<?php echo $pid; ?>">
                                <input type="hidden" name="websiteFields" value="1">
                                <div class="col-12 col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="showOnWebsite" value="1" id="show-<?php echo $pid; ?>"<?php echo $on === true ? ' checked' : ''; ?>>
                                        <label class="form-check-label" for="show-<?php echo $pid; ?>">Show on the public website</label>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small mb-0" for="from-<?php echo $pid; ?>">From</label>
                                    <input class="form-control form-control-sm" type="date" name="websiteFrom" id="from-<?php echo $pid; ?>" value="<?php echo $esc((string) ($p['websiteFrom'] ?? '')); ?>">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small mb-0" for="until-<?php echo $pid; ?>">Until</label>
                                    <input class="form-control form-control-sm" type="date" name="websiteUntil" id="until-<?php echo $pid; ?>" value="<?php echo $esc((string) ($p['websiteUntil'] ?? '')); ?>">
                                </div>
                                <div class="col-12 col-md-2">
                                    <button type="submit" class="btn btn-primary btn-sm w-100">Save</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="small text-muted">
                                <?php echo $on === true ? 'Ticked to show on the public website' : 'Not ticked to show on the public website'; ?><?php
                                $from  = (string) ($p['websiteFrom'] ?? '');
                                $until = (string) ($p['websiteUntil'] ?? '');
                                if ($from !== '' || $until !== '') {
                                    echo $esc(' (from ' . ($from !== '' ? $from : 'no limit') . ' until ' . ($until !== '' ? $until : 'no limit') . ')');
                                }
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
