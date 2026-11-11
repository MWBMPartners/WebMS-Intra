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

if (function_exists('portal_place_start_again') === false) {
    /**
     * 🧭 How somebody who is NOT a place's host can make the group meet
     * somewhere else (#809, check round 4). It differs by FORM, because each
     * form offers a different route and the other one does not work there:
     *   - the USUAL place has no "remove" control, so the way is to clear
     *     every part of it except the country and save (`whyHostChangeRefused()`
     *     lets an emptied place through);
     *   - a LISTED home cannot be saved with every part cleared
     *     (`place-save.php` refuses "Enter at least part of the address…"
     *     before the rule), so the way is to remove it from the list.
     * One sentence was drawn on both forms before, and each form offered a
     * route that does not work on it.
     *
     * @param bool $listedHome True on the listed-home form, false on the group's own form.
     */
    function portal_place_start_again(bool $listedHome): string
    {
        return $listedHome === true
            ? 'To meet somewhere else, remove this home from the group\'s list, then add the new one.'
            : 'To meet somewhere else, clear every part of this place except the country ('
                . \Portal\Core\SmallGroups::placePartsInWords() . ') and save, then enter the new place.';
    }
}

if (function_exists('portal_place_listed_home') === false) {
    /**
     * The form's `listedHome` setting, which MUST be given: a missing one would
     * silently draw the other form's route, which is the fault this replaces.
     *
     * @param array<string,mixed> $cfg
     */
    function portal_place_listed_home(array $cfg): bool
    {
        if (is_bool($cfg['listedHome'] ?? null) === false) {
            throw new \InvalidArgumentException('listedHome must be given as true or false');
        }

        return $cfg['listedHome'];
    }
}

if (function_exists('portal_place_map_agreement') === false) {
    /**
     * @param array{
     *     hostId: int,
     *     hostName: string,
     *     agreed: bool,
     *     viewerId: int,
     *     what: string,
     *     listedHome: bool,
     *     notAHome?: bool
     * } $cfg  `what` names the place, e.g. "this meeting place" or "this home".
     *          `listedHome` says which form this is (see portal_place_start_again()).
     *          `hostId` is the host as STORED (0 = none yet). `notAHome` is the
     *          stored "not anybody's home" mark (D-128).
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
        } elseif ($hostId === 0 && ($cfg['notAHome'] ?? false) === true) {
            echo '<p class="small text-secondary mb-0 portal-place-host-status">This place is marked as not anybody\'s '
                . 'home, so a map of it may be shown to whoever can see its address, if their own map settings allow it.</p>';
        } elseif ($hostId === 0) {
            echo '<p class="small text-secondary mb-0 portal-place-host-status">No host is named for this place yet, '
                . 'so no map of it is shown. Once a host is named, only they can agree to one.</p>';
        } else {
            echo '<p class="small text-secondary mb-0 portal-place-host-status">This is ' . $esc($name) . '\'s home. '
                . $esc($name) . ' has ' . ($agreed === true ? 'agreed' : 'not agreed') . ' to a map of it. Only '
                . $esc($name) . ' can change that, by opening this form themselves. Only ' . $esc($name)
                . ' can name a different host or mark it as not anybody\'s home. You may correct the address, but '
                . 'then any agreement given so far no longer counts. ' . $esc(portal_place_start_again(portal_place_listed_home($cfg)))
                . '</p>';
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
     * 🏛️ "NOT ANYBODY'S HOME" (D-128). Somebody who may choose the host may
     * instead mark the place as nobody's home — a church hall, a café — and a
     * map of it may then be shown with no host. The two cannot go together: the
     * handler refuses a save that names a host AND ticks the mark. The words say
     * plainly what ticking means, because a home address ticked by mistake
     * would be shown. Somebody adding their OWN home is its host, so they are
     * never offered the mark. A hidden `notAHomeAsked` says the box was drawn
     * (#677).
     *
     * @param array{
     *     canChoose: bool,
     *     members: list<array<string,mixed>>,
     *     hostId: int,
     *     hostName: string,
     *     listedHome: bool,
     *     isNew?: bool,
     *     notAHome?: bool
     * } $cfg  `isNew` is true on the ADD-a-home form, which draws no route: a
     *          home not yet on the list cannot be removed from it.
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
        // "Nobody named yet" — and NOT "(no map can be shown)", which was untrue
        // the moment "not anybody's home" below is ticked (D-128).
        echo '<option value=""' . ($hostId === 0 ? ' selected' : '') . '>Nobody named yet</option>';
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
        echo '<small class="text-muted">A member of the group. Only they can agree to a map of their home. With nobody '
            . 'named, no map is shown unless the place is marked as not anybody\'s home below. Once a host is named, only '
            . 'they can name somebody else or mark it.'
            . ((($cfg['isNew'] ?? false) === true && portal_place_listed_home($cfg) === true)
                ? '' : ' ' . $esc(portal_place_start_again(portal_place_listed_home($cfg))))
            . '</small>';
        echo '<input type="hidden" name="notAHomeAsked" value="1">';
        echo '<div class="form-check mt-2">';
        echo '<input type="checkbox" class="form-check-input" name="notAHome" id="notAHome" value="1"'
            . (($cfg['notAHome'] ?? false) === true ? ' checked' : '') . '>';
        // ⚠️ D-131: the warning is part of the tick's own words, on BOTH the
        //    usual-place form and the listed-home form (this partial draws both).
        //    A home emptied and typed back in as "not anybody's home" cannot be
        //    recognised, so this sentence is the protection there is.
        echo '<label class="form-check-label" for="notAHome">This is not anybody\'s home (for example, a church hall). '
            . 'A map of it may be shown to whoever can see its address. <strong class="portal-not-a-home-warning">'
            . 'Ticking this for somebody\'s home shows that home on a map without them agreeing.</strong></label>';
        echo '</div>';
        echo '<small class="text-muted">Only tick this if nobody lives here: a home address ticked by mistake would be '
            . 'shown on a map. A place with a host named cannot be marked, and changing the address clears the mark '
            . '(tick it again afterwards).</small>';
        echo '</div>';
    }
}
