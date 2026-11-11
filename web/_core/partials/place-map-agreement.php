<?php
// Path: _core/partials/place-map-agreement.php
/**
 * -----------------------------------------------------------------------------
 * Shared partial — "a map of this place may be shown" 🗺️ (#809, D-120, D-123)
 * -----------------------------------------------------------------------------
 * `portal_place_map_agreement(array $cfg): void` — echoes the tick-box with
 * which the person who entered a small group's meeting place agrees (or not)
 * that a MAP of it may be shown, and says plainly whose agreement stands.
 *
 * Used by BOTH places a meeting place is entered: the group's usual place
 * (`small-groups/manage.php`) and each host's home in a group that takes turns
 * (`small-groups/place.php`). One partial, so the two can never describe the
 * rule differently.
 *
 * WHY THE WORDS MATTER. A small group very often meets in somebody's home, and
 * the map is drawn by OpenStreetMap, an outside company, which then learns
 * where that home is and who is looking. The owner decided (D-120) the map is
 * shown only where the person who entered the place agreed, and (D-123) that
 * each place is its own host's: one host's agreement never stands for
 * another's home. What a save does with the tick is decided by
 * `SmallGroups::placeAgreementAfterSave()`; this partial only describes it.
 *
 * The hidden `placeMapAsked` field tells the handler the box was DRAWN, so an
 * unticked box can be told from a form that never offered one (#677: an
 * unticked checkbox is not submitted at all).
 *
 * @package   Portal\Core\Partials
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/809
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\OutsideServices;

if (function_exists('portal_place_map_agreement') === false) {
    /**
     * @param array{
     *     isNew: bool,
     *     enteredBy: int,
     *     agreed: bool,
     *     viewerId: int,
     *     entererName: string,
     *     what: string
     * } $cfg  `what` names the place in the label, e.g. "this meeting place" or "this home".
     */
    function portal_place_map_agreement(array $cfg): void
    {
        $esc        = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $enteredBy  = (int) $cfg['enteredBy'];
        $isMine     = $enteredBy > 0 && $enteredBy === (int) $cfg['viewerId'];
        $agreed     = $cfg['agreed'] === true;
        $blocked    = OutsideServices::meetingPlaceTickBlockedFor();

        echo '<div class="mt-3 portal-place-map-agreement">';
        echo '<input type="hidden" name="placeMapAsked" value="1">';
        echo '<div class="form-check">';
        echo '<input type="checkbox" class="form-check-input" name="placeMapAgreed" id="placeMapAgreed" value="1"'
            . (($isMine === true && $agreed === true) ? ' checked' : '') . '>';
        echo '<label class="form-check-label" for="placeMapAgreed">A map of ' . $esc($cfg['what'])
            . ' may be shown on the group\'s page</label>';
        echo '</div>';
        echo '<p class="small text-secondary mb-1">The map is drawn by OpenStreetMap, an outside company: showing it '
            . 'tells OpenStreetMap where the group meets, together with the internet address of whoever is looking. '
            . 'A group often meets in somebody\'s home, so the map is shown only when the person who entered the '
            . 'place has agreed. The address itself is still shown as text, to whoever may see it, either way.</p>';
        echo '<p class="small text-secondary mb-0">';
        if ($cfg['isNew'] === true || $enteredBy === 0) {
            if ($cfg['isNew'] === false) {
                echo 'Nobody is recorded as having entered this place. ';
            }
            echo 'If you tick this, you are recorded as the person who entered it, and your agreement counts.';
        } elseif ($isMine === true) {
            echo 'You entered this place, so this is your agreement to give or take back.';
        } else {
            $name = (string) $cfg['entererName'];
            echo 'This place was entered by ' . $esc($name !== '' ? $name : 'somebody else') . ', who has '
                . ($agreed === true ? 'agreed' : 'not agreed') . ' to a map. Only they can change that. If you change '
                . 'the place, you become the person who entered it, and your own tick counts instead.';
        }
        if ($blocked !== '') {
            echo '<br><strong>No map will be shown from your agreement:</strong> ' . $esc($blocked);
        }
        echo '</p>';
        echo '</div>';
    }
}
