<?php
// Path: _apps/admin/calendar/_approval-rows.php
/**
 * -----------------------------------------------------------------------------
 * Partial — imported dates waiting for an administrator's approval ⏳🗓️
 * -----------------------------------------------------------------------------
 * When a rule or a choice would show an imported date MORE widely than its
 * outside calendar's own setting — or, for a date the outside calendar
 * marks private, to anyone but administrators (a private date starts from
 * "administrators only", FeedResolver's base answer) — the date waits for
 * an administrator to approve it (#514 part 7). This file draws those waiting dates, one row
 * each, with Approve, Decline and Preview — and, above them, one form for
 * deciding several ticked dates at once. It is a PARTIAL: it has no web
 * address of its own (its name starts with an underscore and no route points
 * at it), so nothing can reach it directly. Two pages include it and call
 * `portal_feed_approval_rows()`:
 *   - the Approvals inbox (`approvals/_imports-section.php`), with every
 *     waiting date of the organisation and each date's calendar name;
 *   - a calendar's own page (`feeds-view.php`, the `#waiting` section),
 *     with that calendar's waiting dates only. This is the only place to
 *     decide when the organisation has switched the Approvals app off.
 *
 * WHAT EACH ROW SAYS (the #514 part 8 plan, section 3.3, and its top-section
 * item 10)
 * --------------------------------------------------------------------------
 * - The title, and the date and time as the outside calendar wrote them
 *   (a wall-clock time, with its zone when that differs from the
 *   organisation's) — both from the snapshot taken when the row was made,
 *   so the administrator decides on exactly what will be shown.
 * - On the inbox, the calendar's name, with "(paused)" after it when the
 *   calendar is paused (challenge L9).
 * - "Asks to show it to: <who>, <how much>", plus "and on the public
 *   website" when that is asked for; for Selected groups, the names on the
 *   list as they are NOW (read live, so a person taken off a list or erased
 *   disappears from here at once — part 7 A11).
 * - Why it waits, in words, and since when (in the organisation's own time).
 * - When other dates of the same repeating event wait for the very same
 *   decision: "N other dates of this repeating event are waiting for the
 *   same decision." That is a PRESENT fact, counted as the rows stand. It
 *   is deliberately NOT "your decision applies to them too" — a prediction
 *   the owner rejected on 28 September 2026 (plan item 10): the resolver
 *   can file, withdraw or settle rows in the same save, so no count of what
 *   WILL follow is always true.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * - It decides nothing and reads nothing from the database. The page hands
 *   it the rows it has already read through `FeedAdmin::pendingApprovals()`
 *   (this organisation's only). Every decision goes to
 *   `admin/calendar/feeds/approvals/act`, which checks who is asking, the
 *   form token, and that each date still says what the page showed.
 * - It needs no script. HTML forbids a form inside a form, and each row has
 *   its own Approve / Decline form, so the bulk form is a SEPARATE, empty
 *   `<form>` above the rows; each row's tick box joins it from where it sits
 *   with the `form="…"` attribute.
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

// 🚪 A partial is only ever included by a page the portal has already
//    started. Opened any other way, it quietly does nothing.
if (defined('PORTAL_ROOT') === false) {
    return;
}

use Portal\Core\FeedAdmin;
use Portal\Core\Site;

if (function_exists('portal_feed_approval_rows') === false) {
    /**
     * Draw the waiting dates and the bulk form.
     *
     * @param array{
     *   rows: list<array<string,mixed>>,
     *   csrf: string,
     *   back: string,
     *   feedId: int,
     *   showCalendar: bool,
     *   orgZone: \DateTimeZone,
     *   prefix: string
     * } $o `back` is `approvals` (the inbox) or `feed` (a calendar's own
     *   page); `feedId` is the calendar the BULK form returns to (0 on the
     *   inbox, whose ticked dates can come from several calendars — each
     *   row's own form always names its own calendar);
     *   `prefix` keeps element ids unique on the page ("imports" or
     *   "waiting").
     */
    function portal_feed_approval_rows(array $o): void
    {
        $esc     = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $actUrl  = Site::url('admin/calendar/feeds/approvals/act');
        $preview = Site::url('admin/calendar/feeds/preview');
        $prefix  = (string) $o['prefix'];
        $bulkId  = $prefix . '-bulk';
        $back    = ((string) $o['back'] === 'feed') ? 'feed' : 'approvals';
        $feedId  = (int) $o['feedId'];
        $csrf    = (string) $o['csrf'];
        /** @var \DateTimeZone $zone */
        $zone    = $o['orgZone'];

        // The state words for a name on a list, as they are true HERE: this
        // page never saves the list, so ExternalAudience::stateLabel()'s
        // "…will be removed when you save" would be untrue (the same words
        // as the choice page's leftover box, chunk P8-3 fix round 4). An
        // entry that no longer exists keeps the label it was given ("someone
        // no longer in this organisation") with nothing added.
        $stateWords = [
            'switched_off' => ' (switched off)',
            'left'         => ' (no longer a member)',
            'not_offered'  => ' (not used by this version)',
            'gone'         => '',
        ];
        ?>
        <?php // 📦 The bulk form: empty of rows, so no form sits inside another. ?>
        <form method="post" action="<?php echo $esc($actUrl); ?>" id="<?php echo $esc($bulkId); ?>" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
            <input type="hidden" name="back" value="<?php echo $esc($back); ?>">
            <input type="hidden" name="feed" value="<?php echo $feedId; ?>">
            <div class="col-12 col-md-6">
                <label class="form-label small mb-0" for="<?php echo $esc($prefix); ?>-bulk-note">Note for the ticked dates (optional)</label>
                <input type="text" class="form-control form-control-sm" id="<?php echo $esc($prefix); ?>-bulk-note" name="note" maxlength="<?php echo (int) FeedAdmin::MAX_DECISION_NOTE; ?>">
            </div>
            <div class="col-12 col-md-6 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-sm btn-success" name="decision" value="approved">Approve the ticked dates</button>
                <button type="submit" class="btn btn-sm btn-outline-danger" name="decision" value="declined"
                        data-confirm="Decline showing the ticked dates more widely? Each keeps the calendar's own setting, or stays hidden if the outside calendar marks it private."
                        data-confirm-destructive="true">Decline the ticked dates</button>
            </div>
        </form>

        <div class="portal-data-list">
        <?php foreach ((array) $o['rows'] as $row): ?>
            <?php
            $aid      = (int) $row['approvalID'];
            $level    = (string) $row['requestedLevel'];
            $detail   = (string) $row['requestedDetail'];
            $when     = FeedAdmin::showWallClock(
                (string) $row['snapStart'],
                $row['snapTimezone'] === null ? null : (string) $row['snapTimezone'],
                (int) $row['snapIsAllDay'] === 1,
                $zone
            );
            $asks     = (FeedAdmin::LEVEL_LABELS[$level] ?? $level)
                . (isset(FeedAdmin::DETAIL_LABELS[$detail]) === true ? ', ' . FeedAdmin::DETAIL_LABELS[$detail] : '')
                . ((int) $row['requestedWebsite'] === 1 ? ', and on the public website' : '');
            $names    = [];
            foreach ((array) ($row['names'] ?? []) as $entry) {
                $names[] = (string) $entry['label'] . ($stateWords[(string) $entry['state']] ?? '');
            }
            $siblings = (int) $row['siblingsWaiting'];
            $item     = $aid . ':' . (string) $row['requestHash'] . ':' . (string) $row['contentHash'];
            $rowId    = $prefix . '-approval-' . $aid;
            ?>
            <div class="portal-data-row" id="<?php echo $esc($rowId); ?>">
                <div class="portal-data-row-main text-break" style="flex: 1 1 auto; min-width: 0;">
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="items[]" value="<?php echo $esc($item); ?>"
                               form="<?php echo $esc($bulkId); ?>" id="<?php echo $esc($rowId); ?>-tick">
                        <label class="form-check-label" for="<?php echo $esc($rowId); ?>-tick">
                            <?php // The hidden words follow the title with NO white space between, so a
                                  // screen reader says "Title, Mon 5 Oct…" — the line break that used to
                                  // sit here was read as a stray space ("Title , Mon…", chunk P8-4 fix
                                  // round 1). ?>
                            <strong><?php echo $esc($row['snapTitle']); ?></strong><span class="visually-hidden">, <?php echo $esc($when); ?> — tick to include it in the decision above</span>
                        </label>
                    </div>
                    <div class="small text-muted"><?php echo $esc($when); ?></div>
                    <?php if ((bool) $o['showCalendar'] === true): ?>
                        <div class="small">From <?php echo $esc($row['feedName']); ?><?php echo (int) $row['feedActive'] === 1 ? '' : ' (paused)'; ?></div>
                    <?php endif; ?>
                    <div class="small">Asks to show it to: <?php echo $esc($asks); ?></div>
                    <?php if ($names !== []): ?>
                        <div class="small text-muted">Chosen: <?php echo $esc(implode(', ', $names)); ?></div>
                    <?php endif; ?>
                    <div class="small text-muted">Why: <?php echo $esc(FeedAdmin::REASON_LABELS[(string) $row['reason']] ?? (string) $row['reason']); ?>
                        &middot; waiting since <?php echo $esc(FeedAdmin::showMoment((string) $row['createdAt'], $zone)); ?></div>
                    <?php if ($siblings > 0): ?>
                        <div class="small" data-siblings="<?php echo $siblings; ?>"><?php echo $esc($siblings === 1
                            ? '1 other date of this repeating event is waiting for the same decision.'
                            : $siblings . ' other dates of this repeating event are waiting for the same decision.'); ?></div>
                    <?php endif; ?>
                    <form method="post" action="<?php echo $esc($actUrl); ?>" class="row g-2 align-items-end mt-1">
                        <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                        <input type="hidden" name="approvalID" value="<?php echo $aid; ?>">
                        <input type="hidden" name="requestHash" value="<?php echo $esc($row['requestHash']); ?>">
                        <input type="hidden" name="contentHash" value="<?php echo $esc($row['contentHash']); ?>">
                        <input type="hidden" name="back" value="<?php echo $esc($back); ?>">
                        <?php // The row's OWN calendar, even on the inbox: if the organisation
                              // switches the Approvals app off before this is pressed, the
                              // handler returns to that calendar's page rather than the list. ?>
                        <input type="hidden" name="feed" value="<?php echo (int) $row['feedID']; ?>">
                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-0" for="<?php echo $esc($rowId); ?>-note">Note (optional)</label>
                            <input type="text" class="form-control form-control-sm" id="<?php echo $esc($rowId); ?>-note" name="note" maxlength="<?php echo (int) FeedAdmin::MAX_DECISION_NOTE; ?>">
                        </div>
                        <div class="col-12 col-md-6 d-flex flex-wrap gap-1">
                            <button type="submit" class="btn btn-sm btn-success" name="decision" value="approved">Approve<span class="visually-hidden"> <?php echo $esc($row['snapTitle']); ?></span></button>
                            <button type="submit" class="btn btn-sm btn-outline-danger" name="decision" value="declined"
                                    data-confirm="Decline showing this date more widely? It keeps the calendar's own setting, or stays hidden if the outside calendar marks it private."
                                    data-confirm-destructive="true">Decline<span class="visually-hidden"> <?php echo $esc($row['snapTitle']); ?></span></button>
                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo $esc($preview . '?approval=' . $aid); ?>">Preview<span class="visually-hidden"> <?php echo $esc($row['snapTitle']); ?></span></a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php
    }
}
