<?php
// Path: _core/partials/place-map-agreement.php
/**
 * -----------------------------------------------------------------------------
 * Shared partial — whose home this is, and "a map of it may be shown" 🗺️
 * (#809; D-120, D-123, D-124)
 * -----------------------------------------------------------------------------
 * `portal_place_map_agreement(array $cfg): void` — echoes the part of a
 * meeting-place form that says WHOSE HOME the place is and whether that person
 * has agreed to a map of it, and — to that person ALONE — the tick-box with
 * which they agree or withdraw.
 *
 * Used by BOTH places a meeting place is entered: the group's usual place
 * (`small-groups/manage.php`) and each home on the group's list
 * (`small-groups/place.php`). One partial, so the two can never describe the
 * rule differently.
 *
 * WHY ONLY THE HOST SEES THE BOX. A small group very often meets in somebody's
 * home, and the map is drawn by OpenStreetMap, an outside company, which then
 * learns where that home is and who is looking. The owner decided (D-124) that
 * each place names its HOST, a member, and that only that member can agree to
 * a map of it: "a leader typing in a member's address does not decide for that
 * member". So a leader filling in Ann's address sees whether Ann has agreed and
 * cannot tick for her; Ann ticks when she opens the form herself. The handler
 * enforces the same thing whatever is posted (`SmallGroups::
 * placeAgreementAfterSave()`); this partial only draws and describes it.
 *
 * The hidden `placeMapAsked` field is drawn only with the box, so the handler
 * can tell an unticked box from a form that never offered one (#677: an
 * unticked checkbox is not submitted at all).
 *
 * @package   Portal\Core\Partials
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/809
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\OutsideServices;

if (function_exists('portal_place_map_agreement') === false) {
    /**
     * @param array{
     *     hostId: int,
     *     hostName: string,
     *     agreed: bool,
     *     viewerId: int,
     *     what: string
     * } $cfg  `what` names the place, e.g. "this meeting place" or "this home".
     *          `hostId` is the host as STORED (0 = none yet).
     */
    function portal_place_map_agreement(array $cfg): void
    {
        $esc     = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $hostId  = (int) $cfg['hostId'];
        $isHost  = $hostId > 0 && $hostId === (int) $cfg['viewerId'];
        $agreed  = $cfg['agreed'] === true;
        $name    = (string) $cfg['hostName'] !== '' ? (string) $cfg['hostName'] : 'the host';

        echo '<div class="mt-3 portal-place-map-agreement">';
        echo '<p class="small text-secondary mb-1">A map of ' . $esc($cfg['what']) . ' is drawn by OpenStreetMap, an '
            . 'outside company: showing it tells OpenStreetMap where the group meets, together with the internet '
            . 'address of whoever is looking. A group often meets in somebody\'s home, so the map is shown only when '
            . 'the member whose home it is (its host) has agreed. The address itself is still shown as text, to '
            . 'whoever may see it, either way.</p>';

        if ($isHost === true) {
            echo '<input type="hidden" name="placeMapAsked" value="1">';
            echo '<div class="form-check">';
            echo '<input type="checkbox" class="form-check-input" name="placeMapAgreed" id="placeMapAgreed" value="1"'
                . ($agreed === true ? ' checked' : '') . '>';
            echo '<label class="form-check-label" for="placeMapAgreed">This is my home, and a map of it may be shown '
                . 'on the group\'s page</label>';
            echo '</div>';
            $blocked = OutsideServices::meetingPlaceTickBlockedFor();
            if ($blocked !== '') {
                echo '<p class="small text-secondary mb-0"><strong>No map will be shown from your agreement:</strong> '
                    . $esc($blocked) . '</p>';
            }
        } elseif ($hostId === 0) {
            echo '<p class="small text-secondary mb-0 portal-place-host-status">No host is named for this place yet, '
                . 'so no map of it is shown. Once a host is named, only they can agree to one.</p>';
        } else {
            echo '<p class="small text-secondary mb-0 portal-place-host-status">This is ' . $esc($name) . '\'s home. '
                . $esc($name) . ' has ' . ($agreed === true ? 'agreed' : 'not agreed') . ' to a map of it. Only '
                . $esc($name) . ' can change that, by opening this form themselves. If you name a different host or '
                . 'change the address, any agreement given so far no longer counts.</p>';
        }
        echo '</div>';
    }
}

if (function_exists('portal_place_host_select') === false) {
    /**
     * 🏠 "Whose home is this?" (D-124, D-125).
     *
     * Somebody who may change any home (an administrator, a small-groups
     * manager, one of the group's leaders) chooses the host from the group's
     * ACTIVE members, or "nobody named yet". Anybody else may only add or change
     * their OWN home, so they are shown that they are its host and offered no
     * choice; the handler enforces that whatever is posted.
     *
     * A host who has since left the group stays shown as the current choice,
     * marked as no longer a member, so an untouched Save does not silently
     * change who the host is.
     *
     * @param array{
     *     canChoose: bool,
     *     members: list<array<string,mixed>>,
     *     hostId: int,
     *     hostName: string
     * } $cfg
     */
    function portal_place_host_select(array $cfg): void
    {
        $esc    = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $hostId = (int) $cfg['hostId'];

        echo '<div class="mb-3 portal-place-host">';
        if ($cfg['canChoose'] === false) {
            echo '<p class="mb-1"><strong>Whose home is this?</strong> Yours: you are its host.</p>';
            echo '</div>';

            return;
        }
        echo '<label for="hostUserID" class="form-label">Whose home is this? (its host)</label>';
        echo '<select name="hostUserID" id="hostUserID" class="form-select">';
        echo '<option value=""' . ($hostId === 0 ? ' selected' : '') . '>Nobody named yet (no map can be shown)</option>';
        $listed = false;
        foreach ($cfg['members'] as $member) {
            $id = (int) $member['userID'];
            if ($id === $hostId) {
                $listed = true;
            }
            echo '<option value="' . $id . '"' . ($id === $hostId ? ' selected' : '') . '>' . $esc($member['fullName']) . '</option>';
        }
        if ($hostId > 0 && $listed === false) {
            echo '<option value="' . $hostId . '" selected>' . $esc($cfg['hostName'] !== '' ? $cfg['hostName'] : 'The current host')
                . ' (no longer a member of the group)</option>';
        }
        echo '</select>';
        echo '<small class="text-muted">A member of the group. Only they can agree to a map of their home.</small>';
        echo '</div>';
    }
}
