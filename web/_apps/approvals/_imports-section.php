<?php
// Path: _apps/approvals/_imports-section.php
/**
 * -----------------------------------------------------------------------------
 * Approvals — imported events waiting for approval (the inbox section) ⏳🗓️
 * -----------------------------------------------------------------------------
 * The Approvals inbox (`approvals/index.php`) lists workflow items. Dates
 * copied in from an outside calendar that wait for an administrator's
 * approval are NOT workflow items — they belong to #514's own approval
 * table, decided through `FeedAdmin::decide()` — so they get their own
 * section here, anchored `#imports`, between "Awaiting your decision" and
 * "History" (the #514 part 8 plan, section 6).
 *
 * It is a PARTIAL: it has no web address of its own, and `approvals/index.php`
 * includes it ONLY for somebody `EventVisibility::isAdminOfSite()` says is an
 * administrator of this organisation — its own site administrators, and
 * every global administrator. It checks that again itself, so including it
 * anywhere else can never show it to anybody else.
 *
 * WHAT IT LISTS
 * -------------
 * Every waiting date of THIS organisation only (`FeedAdmin::pendingApprovals()`
 * takes the organisation's number and reads nothing else), ordered by the
 * calendar's name and then by when it started waiting; at most 200 — then
 * "N more are waiting; each calendar's own page lists them all". Each row
 * is drawn by `admin/calendar/_approval-rows.php`, with Approve, Decline and
 * Preview, and decisions come back here (`back=approvals`).
 *
 * WHAT IT CANNOT DO
 * -----------------
 * - Show the dates of another organisation, even to a global administrator:
 *   that administrator opens the other organisation first (its own address
 *   prefix), exactly as on the calendar pages.
 *
 * @package   Portal\Approvals
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🚪 A partial is only ever included by a page the portal has already
//    started. Opened any other way, it quietly does nothing.
if (defined('PORTAL_ROOT') === false) {
    return;
}

use Portal\Core\Auth;
use Portal\Core\EventVisibility;
use Portal\Core\FeedAdmin;
use Portal\Core\FeedResolver;
use Portal\Core\Site;

$importsSiteId = Site::id();
$importsUserId = (int) ($_SESSION['user_id'] ?? 0);

// 🛡️ The page includes this only for an administrator of this
//    organisation; asked again here so it can never be shown to anybody
//    else, whoever includes it.
if (EventVisibility::isAdminOfSite($mysqli, $importsUserId, $importsSiteId) === true) {
    $importsWaiting = FeedAdmin::pendingApprovals($mysqli, $importsSiteId, null, FeedAdmin::MAX_BULK);
    $importsEsc     = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $importsMore    = (int) $importsWaiting['total'] - count($importsWaiting['rows']);
    require_once PORTAL_APPS . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'calendar' . DIRECTORY_SEPARATOR . '_approval-rows.php';
    ?>
    <section id="imports" class="mb-4" aria-labelledby="imports-heading">
        <h2 class="h5 mb-2" id="imports-heading">Imported events waiting for approval</h2>
        <p class="small text-muted">
            Dates copied in from an outside calendar that a rule or a choice would show more widely than the
            calendar's own setting — or, for a date the outside calendar marks private, to anyone but
            administrators. <a href="<?php echo $importsEsc(Site::url('help/calendar-imports') . '#approval'); ?>">How approval works</a>
        </p>
        <?php if ($importsWaiting['rows'] === []): ?>
            <div class="alert alert-info small mb-0" id="imports-none">No imported events are waiting.</div>
        <?php else: ?>
            <?php
            portal_feed_approval_rows([
                'rows'         => $importsWaiting['rows'],
                'csrf'         => Auth::csrfToken(),
                'back'         => 'approvals',
                'feedId'       => 0,
                'showCalendar' => true,
                'orgZone'      => FeedResolver::organisationZone($importsSiteId),
                'prefix'       => 'imports',
            ]);
            ?>
            <?php if ($importsMore > 0): ?>
                <p class="small text-muted mt-2" id="imports-more"><?php echo $importsEsc($importsMore === 1
                    ? '1 more is waiting; each calendar\'s own page lists them all.'
                    : $importsMore . ' more are waiting; each calendar\'s own page lists them all.'); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
}
