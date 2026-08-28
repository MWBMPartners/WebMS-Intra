<?php
// Path: _core/partials/location-input.php
/**
 * -----------------------------------------------------------------------------
 * Shared partial — Location input fields 📍 (#456 Chunk A)
 * -----------------------------------------------------------------------------
 * `portal_location_input(array $cfg): void` — echoes a Bootstrap `row g-3`
 * fieldset of address/coordinate/what3words inputs. Field NAMEs are
 * configurable via `$cfg['names']` (canonical -> POST field name) so
 * legacy column names (events' `locationGeoLat`/`locationGeoLng`/
 * `locationW3W`) keep working with zero schema churn while venues/
 * directory/resources use the canonical names directly.
 *
 * The W3W input is ALWAYS rendered (locked decision: `w3w.enabled` gates
 * only the API — autosuggest/validation — never the input's presence; a
 * typed value is a pure stored-field fallback). `w3wSuggest` only adds the
 * `data-w3w-suggest` wiring hook when `What3Words::isConfigured()` is
 * true; the plain input still works either way.
 *
 * @package   Portal\Core\Partials
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/456
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\What3Words;

if (function_exists('portal_location_input') === false) {
    /**
     * @param array{
     *     values?: array<string, mixed>,
     *     names?: array<string, string>,
     *     showAddress?: bool,
     *     showCoords?: bool,
     *     showW3W?: bool,
     *     compact?: bool,
     *     lookup?: bool,
     *     w3wSuggest?: bool
     * } $cfg
     */
    function portal_location_input(array $cfg): void
    {
        $values = $cfg['values'] ?? [];
        $rawNames = $cfg['names'] ?? [];
        $names  = array_merge([
            'line1' => 'addressLine1', 'line2' => 'addressLine2', 'city' => 'city',
            'region' => 'region', 'postcode' => 'postcode', 'countryCode' => 'countryCode',
            'lat' => 'latitude', 'lng' => 'longitude', 'w3w' => 'what3words',
        ], $rawNames);

        // 🔻 "Reduced names map" mode (GiftAid/Salvation single-line text-
        // only capture, Chunk B): when the caller's OWN `names` override
        // maps at least one address field, render ONLY the address fields
        // it explicitly mapped. No override at all (or an override that
        // only touches lat/lng/w3w, e.g. events' legacy names) renders the
        // full six-field address as normal.
        $addressKeys = ['line1', 'line2', 'city', 'region', 'postcode', 'countryCode'];
        $addressOverride = array_intersect_key($rawNames, array_flip($addressKeys));
        $reducedAddress = count($addressOverride) > 0;
        $showField = static fn (string $key): bool => $reducedAddress === false || array_key_exists($key, $addressOverride);

        $showAddress = (bool) ($cfg['showAddress'] ?? true);
        $showCoords  = (bool) ($cfg['showCoords'] ?? true);
        $showW3W     = (bool) ($cfg['showW3W'] ?? true);
        $compact     = (bool) ($cfg['compact'] ?? false);
        $lookup      = (bool) ($cfg['lookup'] ?? false);
        $w3wSuggest  = (bool) ($cfg['w3wSuggest'] ?? false) && What3Words::isConfigured() === true;

        $ctrlClass = $compact === true ? 'form-control form-control-sm' : 'form-control';
        $selClass  = $compact === true ? 'form-select form-select-sm' : 'form-select';
        $lblClass  = $compact === true ? 'form-label small' : 'form-label';

        $esc = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $val = static fn (string $canonical): string => $esc((string) ($values[$canonical] ?? ''));
        $name = static fn (string $canonical): string => $esc($names[$canonical]);

        echo '<div class="row g-3 portal-location-input">';

        if ($showAddress === true) {
            $showLine2 = $showField('line2');
            $showCity  = $showField('city');
            $showRegion = $showField('region');
            $showPostcode = $showField('postcode');
            $showCountry = $showField('countryCode');

            if ($showField('line1') === true) {
                echo '<div class="col-12 ' . ($showLine2 === true ? 'col-md-6' : '') . '">'
                    . '<label class="' . $lblClass . '" for="' . $name('line1') . '">' . $esc(t('location.address_line1')) . '</label>'
                    . '<input type="text" class="' . $ctrlClass . '" id="' . $name('line1') . '" name="' . $name('line1') . '" maxlength="255" value="' . $val('line1') . '">'
                    . '</div>';
            }

            if ($showLine2 === true) {
                echo '<div class="col-12 col-md-6">'
                    . '<label class="' . $lblClass . '" for="' . $name('line2') . '">' . $esc(t('location.address_line2')) . '</label>'
                    . '<input type="text" class="' . $ctrlClass . '" id="' . $name('line2') . '" name="' . $name('line2') . '" maxlength="255" value="' . $val('line2') . '">'
                    . '</div>';
            }

            if ($showCity === true) {
                echo '<div class="col-12 col-md-3">'
                    . '<label class="' . $lblClass . '" for="' . $name('city') . '">' . $esc(t('location.city')) . '</label>'
                    . '<input type="text" class="' . $ctrlClass . '" id="' . $name('city') . '" name="' . $name('city') . '" maxlength="100" value="' . $val('city') . '">'
                    . '</div>';
            }
            if ($showRegion === true) {
                echo '<div class="col-12 col-md-3">'
                    . '<label class="' . $lblClass . '" for="' . $name('region') . '">' . $esc(t('location.region')) . '</label>'
                    . '<input type="text" class="' . $ctrlClass . '" id="' . $name('region') . '" name="' . $name('region') . '" maxlength="100" value="' . $val('region') . '">'
                    . '</div>';
            }
            if ($showPostcode === true) {
                echo '<div class="col-12 col-md-2">'
                    . '<label class="' . $lblClass . '" for="' . $name('postcode') . '">' . $esc(t('location.postcode')) . '</label>'
                    . '<input type="text" class="' . $ctrlClass . '" id="' . $name('postcode') . '" name="' . $name('postcode') . '" maxlength="20" value="' . $val('postcode') . '">'
                    . '</div>';
            }
            if ($showCountry === true) {
                echo '<div class="col-12 col-md-2">'
                    . '<label class="' . $lblClass . '" for="' . $name('countryCode') . '">' . $esc(t('location.country')) . '</label>'
                    . '<input type="text" class="' . $ctrlClass . ' text-uppercase" id="' . $name('countryCode') . '" name="' . $name('countryCode') . '" maxlength="2" value="' . ($val('countryCode') !== '' ? $val('countryCode') : 'GB') . '">'
                    . '</div>';
            }
        }

        if ($showCoords === true) {
            echo '<div class="col-12 col-md-4">'
                . '<label class="' . $lblClass . '" for="' . $name('lat') . '">' . $esc(t('location.latitude')) . '</label>'
                . '<input type="number" class="' . $ctrlClass . '" id="' . $name('lat') . '" name="' . $name('lat') . '" step="0.0000001" min="-90" max="90" value="' . $val('lat') . '">'
                . '</div>';
            echo '<div class="col-12 col-md-4">'
                . '<label class="' . $lblClass . '" for="' . $name('lng') . '">' . $esc(t('location.longitude')) . '</label>'
                . '<input type="number" class="' . $ctrlClass . '" id="' . $name('lng') . '" name="' . $name('lng') . '" step="0.0000001" min="-180" max="180" value="' . $val('lng') . '">'
                . '</div>';
        }

        if ($showW3W === true) {
            echo '<div class="col-12 ' . ($showCoords === true ? 'col-md-4' : 'col-md-6') . '">'
                . '<label class="' . $lblClass . '" for="' . $name('w3w') . '">' . $esc(t('location.what3words')) . '</label>'
                . '<input type="text" class="' . $ctrlClass . '" id="' . $name('w3w') . '" name="' . $name('w3w') . '"'
                . ' maxlength="100" placeholder="' . $esc(t('location.w3w_placeholder')) . '" value="' . $val('w3w') . '"'
                . ($w3wSuggest === true ? ' data-w3w-suggest list="' . $name('w3w') . '_list"' : '')
                . '>'
                . ($w3wSuggest === true ? '<datalist id="' . $name('w3w') . '_list"></datalist>' : '')
                . '</div>';
        }

        if ($lookup === true && $showCoords === true) {
            // 🧩 Address source for the lookup button: either the structured
            // parts (when shown) or a single free-text field name the
            // caller names via `lookupAddressField` (e.g. events' existing
            // `locationAddress` textarea, which this partial never renders
            // itself when showAddress=false).
            $lookupAddressField = $cfg['lookupAddressField'] ?? null;
            $addressAttrs = $lookupAddressField !== null
                ? ' data-address-freetext="' . $esc((string) $lookupAddressField) . '"'
                : ' data-address-line1="' . $name('line1') . '" data-address-line2="' . $name('line2') . '"'
                    . ' data-address-city="' . $name('city') . '" data-address-region="' . $name('region') . '"'
                    . ' data-address-postcode="' . $name('postcode') . '" data-address-country="' . $name('countryCode') . '"';

            echo '<div class="col-12">'
                . '<button type="button" class="btn btn-outline-secondary btn-sm portal-geo-lookup" data-geo-lookup'
                . ' data-lat-target="' . $name('lat') . '" data-lng-target="' . $name('lng') . '"'
                . $addressAttrs
                . ' data-w3w-field="' . $name('w3w') . '">'
                . '<i class="fa-solid fa-magnifying-glass-location me-1"></i>' . $esc(t('location.lookup_coords'))
                . '</button> <span class="small text-muted portal-geo-lookup-status" role="status" aria-live="polite"></span>'
                . '</div>';
        }

        echo '</div>';
    }
}
