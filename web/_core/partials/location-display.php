<?php
// Path: _core/partials/location-display.php
/**
 * -----------------------------------------------------------------------------
 * Shared partial — Location display 📍 (#456 Chunk A)
 * -----------------------------------------------------------------------------
 * `portal_location_display(array $cfg): void` — echoes label + address +
 * map-link row (Directions / OSM / ///w3w) + optional interactive map div.
 * First shared partial in the codebase (`web/_core/partials/` — no such
 * directory existed before this feature); guarded
 * `function_exists()` wrapper so a repeated `require_once` from multiple
 * pages in the same request is always safe.
 *
 * Every value is escaped at output. Zero network — this partial only
 * renders what its caller already resolved (GeoLocation is pure string/
 * number work); the interactive map itself is wired up by the separate
 * `location-map-assets.php` partial's init script, included once per page.
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

use Portal\Core\GeoLocation;

if (function_exists('portal_location_display') === false) {
    /**
     * @param array{
     *     address?: array<string, mixed>|string|null,
     *     lat?: ?float,
     *     lng?: ?float,
     *     w3w?: ?string,
     *     visible?: bool,
     *     coarsen?: bool,
     *     showMap?: bool,
     *     mapId?: string,
     *     label?: ?string,
     *     showEmptyDash?: bool
     * } $cfg
     */
    function portal_location_display(array $cfg): void
    {
        // 🚪 Visibility gate — callers pass the result of their OWN check
        // (e.g. directory's $can($u['visibilityAddress'])); false renders
        // nothing at all.
        if (($cfg['visible'] ?? true) === false) {
            return;
        }

        $label   = $cfg['label'] ?? null;
        $lat     = $cfg['lat'] ?? null;
        $lng     = $cfg['lng'] ?? null;
        $w3w     = $cfg['w3w'] ?? null;
        $coarsen = (bool) ($cfg['coarsen'] ?? false);

        // 🔭 Chunk B precision floor — coarsen coords for non-owner
        // viewers; a 3m W3W square cannot be meaningfully coarsened, so it
        // is suppressed entirely rather than shown alongside an approx pin.
        if ($coarsen === true && $lat !== null && $lng !== null) {
            $coarsened = GeoLocation::coarsenCoords((float) $lat, (float) $lng);
            $lat = $coarsened['lat'];
            $lng = $coarsened['lng'];
            $w3w = null;
        }

        // 📮 Address — array parts go through GeoLocation::formatAddress();
        // a preformatted free-text string (events' locationAddress) is
        // echoed via nl2br() instead.
        $addressRaw = $cfg['address'] ?? null;
        $addressLine = '';
        $addressIsFreeText = false;
        if (is_array($addressRaw) === true) {
            $addressLine = GeoLocation::formatAddress($addressRaw);
        } elseif (is_string($addressRaw) === true && trim($addressRaw) !== '') {
            $addressLine = trim($addressRaw);
            $addressIsFreeText = true;
        }

        $hasAnything = ($label !== null && $label !== '')
            || $addressLine !== ''
            || ($lat !== null && $lng !== null)
            || ($w3w !== null && $w3w !== '');

        if ($hasAnything === false) {
            if (($cfg['showEmptyDash'] ?? false) === true) {
                echo '<span class="text-muted">&mdash;</span>';
            }
            return;
        }

        $esc = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        if ($label !== null && $label !== '') {
            echo '<p class="mb-1"><strong>' . $esc($label) . '</strong></p>';
        }

        if ($addressLine !== '') {
            if ($addressIsFreeText === true) {
                echo '<p class="mb-1 small">' . nl2br($esc($addressLine)) . '</p>';
            } else {
                echo '<p class="mb-1 small">' . $esc($addressLine) . '</p>';
            }
        }

        if ($coarsen === true && $lat !== null && $lng !== null) {
            echo '<p class="mb-1"><span class="badge bg-secondary">' . $esc(t('location.coords_approx')) . '</span></p>';
        }

        // 🔗 Link row — Directions / OSM / ///w3w, built ONLY by
        // GeoLocation::mapLinks() and escaped here at the point of echo.
        $links = GeoLocation::mapLinks(['lat' => $lat, 'lng' => $lng, 'w3w' => $w3w, 'address' => $addressLine]);
        $linkParts = [];
        if ($links['directions'] !== null) {
            $linkParts[] = '<a href="' . $esc($links['directions']) . '" target="_blank" rel="noopener">'
                . '<i class="fa-solid fa-diamond-turn-right me-1"></i>' . $esc(t('location.get_directions')) . '</a>';
        }
        if ($links['osm'] !== null) {
            $linkParts[] = '<a href="' . $esc($links['osm']) . '" target="_blank" rel="noopener">'
                . '<i class="fa-solid fa-map me-1"></i>' . $esc(t('location.open_in_osm')) . '</a>';
        }
        if ($links['w3w'] !== null && $w3w !== null) {
            $linkParts[] = '<a href="' . $esc($links['w3w']) . '" target="_blank" rel="noopener">'
                . '<i class="fa-solid fa-location-crosshairs me-1"></i>///' . $esc($w3w) . '</a>';
        }
        if (count($linkParts) > 0) {
            echo '<p class="mb-1 small d-flex flex-wrap gap-3">' . implode('', array_map(
                static fn ($l) => '<span>' . $l . '</span>',
                $linkParts
            )) . '</p>';
        }

        // 🗺️ Interactive map div — the init script in location-map-assets.php
        // finds every .portal-location-map[data-lat] on the page.
        if (($cfg['showMap'] ?? false) === true && $lat !== null && $lng !== null) {
            $mapId = (string) ($cfg['mapId'] ?? 'locationMap');
            $popupParts = [];
            if ($label !== null && $label !== '') {
                $popupParts[] = '<strong>' . $esc($label) . '</strong>';
            }
            if ($addressLine !== '' && $addressIsFreeText === false) {
                $popupParts[] = $esc($addressLine);
            }
            if ($links['w3w'] !== null && $w3w !== null) {
                $popupParts[] = '<a href="' . $esc($links['w3w']) . '" target="_blank" rel="noopener">///' . $esc($w3w) . '</a>';
            }
            $popupHtml = implode('<br>', $popupParts);

            echo '<div id="' . $esc($mapId) . '" class="portal-location-map rounded border" style="height: 260px;"'
                . ' data-lat="' . $esc((string) $lat) . '" data-lng="' . $esc((string) $lng) . '"'
                . ($coarsen === true ? ' data-approx="1"' : '')
                . ' data-popup="' . $esc($popupHtml) . '"></div>';
        }
    }
}
