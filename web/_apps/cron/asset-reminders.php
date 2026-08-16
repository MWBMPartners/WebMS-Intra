<?php
// Path: _apps/cron/asset-reminders.php
/**
 * -----------------------------------------------------------------------------
 * Cron — Asset Tracker reminder sweep 📦⏰ (STUB — Phase 2 Pass 1, #404)
 * -----------------------------------------------------------------------------
 * Placeholder endpoint for the future maintenance/warranty/insurance/
 * loan-overdue reminder sweep (#405), backed by `tblAssetReminderLog`
 * (migration 160, single-shot dedupe log). Registered as the
 * `cron/asset-reminders` route (unprotected — token-gated internally,
 * same as every other `cron/*` route in this codebase) so
 * `check_route_targets.py` stays green while the schema foundation ships
 * ahead of the actual sweep logic.
 *
 * Token gate cloned from `cron/discipleship-sweep.php` (itself cloned from
 * `cron/event-reminders.php`): authenticates via
 * `?key=<assets.cron_token>` (constant-time compare), 403s when the
 * stored token is empty — migration 160 seeds `assets.cron_token` as an
 * EMPTY string, so this endpoint refuses every request until an admin
 * sets a real token.
 *
 * @package   Portal\App\Cron
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/404
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/405
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Settings;

// 🔑 Token gate (constant-time compare) — mirrors reminders.cron_token /
// discipleship.cron_token. An empty stored token ALWAYS 403s, so this
// endpoint is inert until an admin explicitly sets assets.cron_token.
$incoming = (string) ($_GET['key'] ?? '');
$expected = (string) (Settings::get('assets.cron_token', '') ?? '');
if ($expected === '' || hash_equals($expected, $incoming) === false) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

// 🚧 Real sweep logic (maintenance/warranty/insurance/loan-overdue due-date
// scans, tblAssetReminderLog single-shot writes, notification dispatch)
// lands in the #405 pass — this stub only proves the token gate + route
// wiring work end-to-end.
echo 'asset-reminders: not yet implemented';
exit();
