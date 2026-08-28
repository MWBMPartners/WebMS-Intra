<?php
// Path: public_html/calendar/views/_venue_strip.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Venue Booking Strip Renderer 🏛️
 * -----------------------------------------------------------------------------
 * Renders the compact per-day "is this day booked externally?" indicator
 * used by the grid views (day/week/weekdays/weekend/month/year), fed by
 * Portal\Core\Venues::availabilityForRange()'s per-day row lists (#429).
 *
 * This file NEVER touches the database and NEVER references
 * Portal\Core\Venues or AppRegistry directly — it is a pure formatter over
 * whatever row arrays the caller hands it. Callers are responsible for the
 * AppRegistry::isEnabled('venues') + try/catch guard (see calendar/index.php
 * §5.0) and for only require_once'ing this file when the resulting
 * $venueOverlay is non-empty — that keeps the calendar byte-identical to
 * pre-#429 output when the Venues app is disabled/absent/throwing (security
 * item 14): no data in, nothing rendered, not even the scoped CSS below.
 *
 * Two entry points:
 *   render_venue_strip(array $dayRows): string
 *     — a small row of coloured chips for ONE day's booking rows, used
 *       inside the day/week/weekdays/weekend/month cells.
 *   venue_strip_day_color(array $dayRows): ?string
 *     — a single worst-first hex accent colour for ONE day, used by the
 *       year view's compact day-number cells (too small for chips).
 *
 * Both functions:
 *   - drop rejected booking rows (a rejected proposal never signals
 *     booked/closed/unavailable to a calendar viewer);
 *   - treat 'unavailable' and 'closed' usage kinds distinctly (different
 *     icon AND colour — never merged into one generic "blocked" state);
 *   - hatch (diagonal stripe) unconfirmed/tentative hire rows so they read
 *     as provisional at a glance, never solid like a confirmed booking;
 *   - re-validate every colour against a strict hex pattern before it is
 *     ever interpolated into an inline style="" attribute — defence in
 *     depth even though Venues::statusColor()/KIND_COLORS already only
 *     hand back safe values.
 *
 * Usage:
 *   require_once __DIR__ . '/_venue_strip.php';
 *   echo render_venue_strip($venueOverlay[$dateKey] ?? []);
 *   $accent = venue_strip_day_color($venueOverlay[$dateKey] ?? []);
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/429
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// 🎨 Scoped CSS — emitted exactly once per page. This file is only ever
// require_once'd (never plain require) precisely so multiple call sites
// (the shared header legend AND a grid view's own cells) share one
// inclusion instead of re-running this top-level block.
// -----------------------------------------------------------------------------
?>
<style>
.portal-venue-strip{display:flex;gap:2px;flex-wrap:wrap;margin-top:3px;}
.portal-venue-chip{display:inline-flex;align-items:center;justify-content:center;width:15px;height:15px;border-radius:3px;font-size:9px;line-height:1;color:#fff;background-color:var(--venue-color,#6c757d);border:1px solid rgba(0,0,0,.15);}
.portal-venue-chip.is-tentative{background-image:repeating-linear-gradient(45deg,var(--venue-color,#ffc107),var(--venue-color,#ffc107) 3px,rgba(255,255,255,.55) 3px,rgba(255,255,255,.55) 6px);}
.portal-venue-chip-more{font-size:9px;padding:0 2px;align-self:center;}
.portal-venue-legend .portal-venue-chip{width:14px;height:14px;}
</style>
<?php

if (function_exists('render_venue_strip') === false) {

    /**
     * Render the coloured-chip strip for one day's venue booking rows.
     *
     * @param list<array<string,mixed>> $dayRows One day's slice of
     *        Venues::availabilityForRange()'s per-date bucket (each row
     *        carries usageKind, countsAsConfirmed, isRejected,
     *        resolvedColor, venueName, roomName, statusName, …).
     *
     * @return string HTML — '' when there is nothing to show for this day.
     */
    function render_venue_strip(array $dayRows): string
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        // 🛡️ Only a strict 3/4/6/8-digit hex token is ever allowed into an
        // inline style="" attribute — re-validated here regardless of what
        // Venues:: already guarantees (defence in depth).
        $cleanHex = static function (mixed $c): ?string {
            $c = (string) ($c ?? '');
            return preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) === 1 ? $c : null;
        };

        // 🚫 Rejected rows never signal booked/closed/unavailable.
        $rows = array_values(array_filter(
            $dayRows,
            static fn (array $r): bool => ($r['isRejected'] ?? false) !== true
        ));
        if (count($rows) === 0) {
            return '';
        }

        $maxBadges = 4;
        $badges = [];
        foreach ($rows as $row) {
            $kind      = (string) ($row['usageKind'] ?? 'hire');
            $confirmed = (int) ($row['countsAsConfirmed'] ?? 0) === 1;
            $color     = $cleanHex($row['resolvedColor'] ?? null) ?? '#6c757d';

            if ($kind === 'unavailable') {
                // 🚫 Unavailable — distinct icon + colour from Closed below.
                $icon  = 'fa-ban';
                $class = 'is-unavailable';
            } elseif ($kind === 'closed') {
                // 🚪 Closed — distinct icon + colour from Unavailable above.
                $icon  = 'fa-door-closed';
                $class = 'is-closed';
            } elseif ($confirmed === true) {
                $icon  = 'fa-door-open';
                $class = 'is-confirmed';
            } else {
                // 〰️ Tentative/unconfirmed hire — hatched via CSS, never solid.
                $icon  = 'fa-clock';
                $class = 'is-tentative';
            }

            $venueName  = (string) ($row['venueName'] ?? '');
            $roomName   = (string) ($row['roomName'] ?? '');
            $statusName = (string) ($row['statusName'] ?? '');
            $label = $venueName;
            if ($roomName !== '') {
                $label .= ' — ' . $roomName;
            }
            if ($statusName !== '') {
                $label .= ' (' . $statusName . ')';
            }

            $badges[] = ['icon' => $icon, 'class' => $class, 'color' => $color, 'label' => $label];
        }

        $shown = array_slice($badges, 0, $maxBadges);
        $extra = count($badges) - count($shown);

        $html = '<div class="portal-venue-strip">';
        foreach ($shown as $b) {
            $html .= '<span class="portal-venue-chip ' . $esc((string) $b['class']) . '" '
                . 'style="--venue-color: ' . $esc((string) $b['color']) . ';" '
                . 'title="' . $esc((string) $b['label']) . '">'
                . '<i class="fa-solid ' . $esc((string) $b['icon']) . '" aria-hidden="true"></i>'
                . '</span>';
        }
        if ($extra > 0) {
            $html .= '<span class="portal-venue-chip-more text-muted">+' . (int) $extra . '</span>';
        }
        $html .= '</div>';

        return $html;
    }
}

if (function_exists('venue_strip_day_color') === false) {

    /**
     * Worst-first single accent colour for one day, for the year view's
     * compact day-number cells (too small for the chip strip above).
     * Precedence: unavailable > confirmed hire > closed > tentative hire.
     *
     * @param list<array<string,mixed>> $dayRows
     *
     * @return ?string A validated hex colour, or null when there is nothing
     *                  bookable/booked to show for this day.
     */
    function venue_strip_day_color(array $dayRows): ?string
    {
        $cleanHex = static function (mixed $c): ?string {
            $c = (string) ($c ?? '');
            return preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) === 1 ? $c : null;
        };

        $rows = array_values(array_filter(
            $dayRows,
            static fn (array $r): bool => ($r['isRejected'] ?? false) !== true
        ));
        if (count($rows) === 0) {
            return null;
        }

        $found = ['unavailable' => null, 'confirmed' => null, 'closed' => null, 'tentative' => null];
        foreach ($rows as $row) {
            $kind      = (string) ($row['usageKind'] ?? 'hire');
            $confirmed = (int) ($row['countsAsConfirmed'] ?? 0) === 1;
            $color     = $cleanHex($row['resolvedColor'] ?? null);

            if ($kind === 'unavailable' && $found['unavailable'] === null) {
                $found['unavailable'] = $color ?? '#dc3545';
            } elseif ($kind === 'closed' && $found['closed'] === null) {
                $found['closed'] = $color ?? '#6c757d';
            } elseif ($kind !== 'unavailable' && $kind !== 'closed' && $confirmed === true && $found['confirmed'] === null) {
                $found['confirmed'] = $color ?? '#198754';
            } elseif ($kind !== 'unavailable' && $kind !== 'closed' && $confirmed === false && $found['tentative'] === null) {
                $found['tentative'] = $color ?? '#ffc107';
            }
        }

        foreach (['unavailable', 'confirmed', 'closed', 'tentative'] as $key) {
            if ($found[$key] !== null) {
                return $found[$key];
            }
        }
        return null;
    }
}
