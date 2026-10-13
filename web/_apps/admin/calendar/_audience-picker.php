<?php
// Path: _apps/admin/calendar/_audience-picker.php
/**
 * -----------------------------------------------------------------------------
 * Partial — the "who may see it" list for an outside calendar 👥🗓️
 * -----------------------------------------------------------------------------
 * When an administrator chooses "Selected groups and people" for an outside
 * calendar (and, from chunk P8-3 of #514 part 8, for a choice or a rule),
 * they tick WHICH small groups, leadership roles, roles, user groups,
 * departments and named people may see its events (the middle three since
 * part 10 of #514). This file draws those ticks. It is a PARTIAL: it has no web
 * address of its own (its name starts with an underscore and no route points
 * at it), so nothing can reach it directly — a page includes it and calls
 * `portal_feed_audience_picker()`.
 *
 * WHAT IT DRAWS
 * -------------
 * - "No longer in effect": entries already on the saved list that are not
 *   fully live any more — a small group, leadership role, user group or
 *   department that has been switched off or retired, a person whose
 *   membership here has ended, an entry whose group, role, department or
 *   person no longer exists in this organisation, and a kind this version
 *   does not offer. Each carries the plain-English state label
 *   `ExternalAudience::stateLabel()` gives. A switched-off group, role or
 *   department, and a person who has left, can be KEPT (their box starts
 *   ticked, and untick it to remove them) — a person who rejoins then sees
 *   the events again, which the #514 plan settled (D18). An entry that is
 *   gone, or of a kind this version does not offer, CANNOT be kept: it has
 *   no box, and the page says it will be removed when the form is saved,
 *   BEFORE the save (the #514 part 8 decision of 25 September 2026, item 4).
 * - Six groups of boxes, each a `<fieldset>` with a `<legend>`, in this
 *   order: "Small groups", "Leadership roles", "Roles", "User groups",
 *   "Departments" and "Named people" — every one this organisation could
 *   pick today (`ExternalAudience::options()`).
 * - Under "Roles", "User groups" and "Departments" (part 10 of #514), one
 *   plain line saying what the thing is and where it is managed. Two pairs
 *   are easy to confuse — roles with leadership roles, and user groups
 *   with small groups — and a tick in the wrong one shows a calendar to the
 *   wrong people. Each line's link opens in a NEW TAB, and says so: this
 *   is a form, and following a link in the same tab would throw away every
 *   tick not yet saved. The addresses come from `Site::url()`, so they
 *   keep the organisation's own part of the address (#536).
 * - A "Find a person" box above the people, which only HIDES rows that do
 *   not match (it never unticks anything, and a hidden ticked box is still
 *   sent), with a polite live region saying how many are shown.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * - It decides nothing. What may be saved is decided by
 *   `ExternalAudience::fromPost()` inside `Portal\Core\FeedAdmin`, on every
 *   save: every entry must belong to this organisation, and a NEW entry must
 *   be switched on today. A box added to the page by hand for another
 *   organisation's group is refused there with one sentence for every
 *   reason (`ExternalAudience::REFUSED_MESSAGE`).
 * - It reads nothing from the database itself. The page hands it the
 *   options and the saved list it has already read through FeedAdmin and
 *   ExternalAudience.
 * - It needs no script to work. The script only filters the people list;
 *   without it every person is shown and every box works.
 *
 * Why a FUNCTION inside a partial, not plain HTML: the calendar form uses
 * it now, and the choice and rule forms (chunk P8-3) will use it too; a
 * function with its own settings can be drawn with different element ids on
 * each page without two copies of this markup drifting apart. The
 * `function_exists()` guard lets several pages include this file safely.
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
//    started. Opened any other way (the portal's own constants missing), it
//    quietly does nothing rather than draw half a form or show an error.
if (defined('PORTAL_ROOT') === false) {
    return;
}

use Portal\Core\App;
use Portal\Core\ExternalAudience;
use Portal\Core\Site;

if (function_exists('portal_feed_audience_picker') === false) {
    /**
     * Draw the "who may see it" ticks.
     *
     * @param array{
     *     prefix: string,
     *     options: array{small_group: list<array{groupID:int, groupName:string}>,
     *                    leadership_role: list<array{roleID:int, roleName:string}>,
     *                    role: list<array{roleID:int, roleName:string}>,
     *                    user_group: list<array{groupID:int, groupName:string}>,
     *                    department: list<array{deptID:int, deptName:string}>,
     *                    person: list<array{userID:int, fullName:string}>},
     *     current: list<array{kind:string, refID:int, label:string, state:string}>,
     *     ticked: array<string, bool>
     * } $cfg
     *   - `prefix`  : a short word that makes every element id on the page
     *                 unique (for example "feed"), letters, digits and
     *                 hyphens only.
     *   - `options` : `ExternalAudience::options()` for this organisation.
     *   - `current` : the SAVED list (`ExternalAudience::current()`), used for
     *                 the "No longer in effect" entries. Empty for a new one.
     *   - `ticked`  : which boxes start ticked, keyed "kind:refID" — the saved
     *                 list on a first visit, or exactly what was posted when
     *                 the page is drawn again after a refusal (so nothing the
     *                 administrator ticked is lost).
     */
    function portal_feed_audience_picker(array $cfg): void
    {
        $esc    = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $prefix = (string) preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($cfg['prefix'] ?? 'audience')));
        if ($prefix === '') {
            $prefix = 'audience';
        }
        $options = (array) ($cfg['options'] ?? []);
        $current = (array) ($cfg['current'] ?? []);
        $ticked  = (array) ($cfg['ticked'] ?? []);

        // 🗂️ Which saved entries are not fully live, and so are not among the
        //    options (options() lists only what is switched on today).
        $notLive = [];
        foreach ($current as $entry) {
            if (($entry['state'] ?? 'ok') !== 'ok') {
                $notLive[] = $entry;
            }
        }

        // 🗂️ The six kinds, in the order they are drawn. The order is the
        //    #514 part 10 plan's: the two kinds people already knew, then
        //    the three new ones, then named people last (it is the longest
        //    list, and has the search box).
        //    `about` and `manage` are set only for the three kinds part 10
        //    added: one plain sentence saying what the thing is, and the
        //    page where it is managed. `manage` is a ROUTE, turned into an
        //    address by Site::url() below — never a bare "/admin/…", which
        //    would lose the organisation's part of the address (#536).
        //    The three pages are open to every administrator who can reach
        //    this form (they ask App::isAdmin(); this form's pages ask for
        //    an administrator of the organisation, which is narrower), so
        //    the link never leads to a refusal.
        $kinds = [
            'small_group'     => ['legend' => 'Small groups',     'id' => 'groupID', 'name' => 'groupName', 'none' => 'This organisation has no small groups switched on.'],
            'leadership_role' => ['legend' => 'Leadership roles', 'id' => 'roleID',  'name' => 'roleName',  'none' => 'This organisation has no leadership roles switched on.'],
            'role'            => [
                'legend' => 'Roles', 'id' => 'roleID', 'name' => 'roleName', 'none' => 'This organisation has no roles.',
                'about'  => 'A role says what a person may do in the portal, such as Treasurer or Expense Approver. It is not a leadership role.',
                'manage' => 'admin/roles', 'manageText' => 'Admin → Roles',
            ],
            'user_group'      => [
                'legend' => 'User groups', 'id' => 'groupID', 'name' => 'groupName', 'none' => 'This organisation has no user groups in use.',
                'about'  => 'A user group is a committee or working group, such as a finance committee. It is not a small group.',
                'manage' => 'admin/groups', 'manageText' => 'Admin → Groups',
            ],
            'department'      => [
                'legend' => 'Departments', 'id' => 'deptID', 'name' => 'deptName', 'none' => 'This organisation has no departments in use.',
                'about'  => 'A department is what expense claims are charged to. Everyone in the department is included, whatever part they play in it.',
                'manage' => 'admin/departments', 'manageText' => 'Admin → Departments',
            ],
            'person'          => ['legend' => 'Named people',     'id' => 'userID',  'name' => 'fullName',  'none' => 'This organisation has no active members.'],
        ];
        ?>
        <?php // `text-break`: a group or person name with no spaces wraps inside its
              // column instead of spilling into the next one (fix round 1, M1). ?>
        <div class="border rounded p-2 p-md-3 text-break" id="<?php echo $esc($prefix); ?>-picker" data-audience-picker="<?php echo $esc($prefix); ?>">
            <p class="small text-muted mb-2" id="<?php echo $esc($prefix); ?>-picker-help">
                Tick the small groups, leadership roles, roles, user groups, departments and people who may see these events.
                Only members of this organisation can be chosen, and a list can name at most
                <?php echo (int) ExternalAudience::MAX_MEMBERS; ?> groups and people.
                A person who leaves the organisation stops seeing the events at once, and sees them again if they rejoin.
            </p>

            <?php if ($notLive !== []): ?>
                <fieldset class="mb-3" aria-describedby="<?php echo $esc($prefix); ?>-notlive-help">
                    <legend class="h6 mb-1">No longer in effect</legend>
                    <p class="small text-muted mb-1" id="<?php echo $esc($prefix); ?>-notlive-help">
                        These are on the saved list but are not fully in effect today. Untick one to remove it.
                    </p>
                    <?php foreach ($notLive as $i => $entry): ?>
                        <?php
                        $kind  = (string) $entry['kind'];
                        $refId = (int) $entry['refID'];
                        $state = (string) $entry['state'];
                        $label = (string) $entry['label'] . ' ' . ExternalAudience::stateLabel($state);
                        // 🚫 "gone" and "not_offered" cannot be kept (see the
                        //    file header): no box, so nothing is posted for
                        //    them, and the words say what will happen.
                        $keepable = ($state === 'switched_off' || $state === 'left')
                            && in_array($kind, ExternalAudience::OFFERED_KINDS, true) === true;
                        $boxId = $prefix . '-keep-' . (int) $i;
                        ?>
                        <?php if ($keepable === true): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="<?php echo $esc($boxId); ?>"
                                       name="audience[<?php echo $esc($kind); ?>][]" value="<?php echo $refId; ?>"
                                       <?php echo isset($ticked[$kind . ':' . $refId]) === true ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="<?php echo $esc($boxId); ?>"><?php echo $esc($label); ?></label>
                            </div>
                        <?php else: ?>
                            <p class="small mb-1"><i class="fa-solid fa-circle-minus me-1 text-secondary" aria-hidden="true"></i><?php echo $esc($label); ?></p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>

            <?php foreach ($kinds as $kind => $meta): ?>
                <?php
                $list    = (array) ($options[$kind] ?? []);
                // The id of this kind's plain line, when it has one. The
                // fieldset points at it (aria-describedby), so a screen
                // reader reads the line with the group's name.
                $aboutId = (isset($meta['about']) === true) ? ($prefix . '-' . $kind . '-about') : '';
                ?>
                <fieldset class="mb-3" <?php echo $aboutId !== '' ? 'aria-describedby="' . $esc($aboutId) . '"' : ''; ?>>
                    <legend class="h6 mb-1"><?php echo $esc($meta['legend']); ?></legend>
                    <?php if ($aboutId !== ''): ?>
                        <?php // 📎 target="_blank": following the link in this tab would
                              //    lose every tick not yet saved, so it opens beside
                              //    the form — and the words say so, for everybody,
                              //    not only in a tooltip. rel="noopener" stops the
                              //    new tab reaching back into this one. ?>
                        <p class="small text-muted mb-1" id="<?php echo $esc($aboutId); ?>">
                            <?php echo $esc($meta['about']); ?>
                            Managed under
                            <a href="<?php echo $esc(Site::url((string) $meta['manage'])); ?>" target="_blank" rel="noopener"><?php echo $esc($meta['manageText']); ?></a>
                            (opens in a new tab).
                        </p>
                    <?php endif; ?>
                    <?php if ($list === []): ?>
                        <p class="small text-muted mb-0"><?php echo $esc($meta['none']); ?></p>
                    <?php else: ?>
                        <?php if ($kind === 'person'): ?>
                            <div class="mb-2">
                                <label class="form-label small mb-1" for="<?php echo $esc($prefix); ?>-find">Find a person</label>
                                <input type="search" class="form-control form-control-sm" id="<?php echo $esc($prefix); ?>-find"
                                       autocomplete="off" aria-describedby="<?php echo $esc($prefix); ?>-find-count"
                                       data-audience-find="<?php echo $esc($prefix); ?>">
                                <p class="small text-muted mb-0" id="<?php echo $esc($prefix); ?>-find-count" aria-live="polite"></p>
                            </div>
                        <?php endif; ?>
                        <div class="row g-1" <?php echo $kind === 'person' ? 'data-audience-people="' . $esc($prefix) . '"' : ''; ?>>
                            <?php foreach ($list as $option): ?>
                                <?php
                                $refId = (int) $option[$meta['id']];
                                $name  = (string) $option[$meta['name']];
                                $boxId = $prefix . '-' . $kind . '-' . $refId;
                                ?>
                                <div class="col-12 col-md-6 col-lg-4" data-audience-row="<?php echo $esc(mb_strtolower($name, 'UTF-8')); ?>">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="<?php echo $esc($boxId); ?>"
                                               name="audience[<?php echo $esc($kind); ?>][]" value="<?php echo $refId; ?>"
                                               <?php echo isset($ticked[$kind . ':' . $refId]) === true ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="<?php echo $esc($boxId); ?>"><?php echo $esc($name); ?></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </fieldset>
            <?php endforeach; ?>
        </div>
        <?php // 🔎 The people filter. Convenience only: it hides rows that do not
              //    match what was typed, and says how many are shown. It never
              //    changes a tick, and a hidden ticked box is still sent. The
              //    nonce is required: the page's content-security policy
              //    ignores 'unsafe-inline' once a nonce is present. ?>
        <script nonce="<?php echo $esc(App::cspNonce()); ?>">
        (function () {
            'use strict';
            var prefix = <?php echo json_encode($prefix, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var box = document.querySelector('[data-audience-find="' + prefix + '"]');
            var list = document.querySelector('[data-audience-people="' + prefix + '"]');
            var count = document.getElementById(prefix + '-find-count');
            if (box === null || list === null || count === null) {
                return;
            }
            var rows = list.querySelectorAll('[data-audience-row]');
            box.addEventListener('input', function () {
                var wanted = box.value.trim().toLowerCase();
                var shown = 0;
                rows.forEach(function (row) {
                    var match = wanted === '' || row.getAttribute('data-audience-row').indexOf(wanted) !== -1;
                    row.hidden = (match === false);
                    if (match === true) {
                        shown++;
                    }
                });
                count.textContent = (wanted === '') ? '' : ('Showing ' + shown + ' of ' + rows.length + (rows.length === 1 ? ' person.' : ' people.'));
            });
        })();
        </script>
        <?php
    }
}
