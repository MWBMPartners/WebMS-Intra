<?php
// Path: _apps/cron/webhook-retry.php
/**
 * -----------------------------------------------------------------------------
 * Cron — Outbound webhook async retry sweep 🪝⏰ (#324 v1.1 follow-up)
 * -----------------------------------------------------------------------------
 * Endpoint expected to be called periodically (e.g. every 5-15 minutes) by
 * an external scheduler. Delegates entirely to
 * `Portal\Core\WebhookDispatcher::retryDue()` (migration 166), which sweeps
 * up to 100 `tblWebhookDeliveries` rows still in `status = 'failed'` with
 * `attemptCount` under the class's `MAX_ATTEMPTS` (6) and whose
 * `nextRetryAt` backoff window has elapsed (or was never set), re-delivers
 * each through the SAME sign+POST+record-result path `emit()` uses, and
 * advances every row to 'delivered', back to 'failed' with a fresh
 * exponential-backoff `nextRetryAt`, or to the terminal 'dead' state.
 *
 * Token gate cloned EXACTLY from `cron/event-reminders.php` / `cron/asset-
 * reminders.php`: authenticates via `?key=<webhooks.cron_token>` (constant-
 * time `hash_equals`), 403s when the stored token is empty — migration 166
 * seeds `webhooks.cron_token` as an EMPTY string, so this endpoint refuses
 * every request until an admin sets a real token at /admin (settings key
 * `webhooks.cron_token`, isSensitive = 1, encrypted at rest).
 *
 * `WebhookDispatcher::retryDue()` NEVER throws (see its own doc) — any
 * cURL/DB failure is caught there and folded into the returned summary, so
 * this endpoint can call it unconditionally and always emit a clean
 * text/plain response.
 *
 * @package   Portal\App\Cron
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/324
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\WebhookDispatcher;

// -----------------------------------------------------------------------------
// 🔑 Token gate (constant-time compare) — mirrors reminders.cron_token /
// assets.cron_token / discipleship.cron_token. An empty stored token
// ALWAYS 403s, so this endpoint is inert until an admin explicitly sets
// webhooks.cron_token. Read via App::settings() (NOT Settings::get()) —
// this app's own settings namespace already reads through App::settings()
// elsewhere in WebhookDispatcher.php, so this endpoint stays consistent
// with that.
// -----------------------------------------------------------------------------
$incoming = (string) ($_GET['key'] ?? '');
$expected = (string) (App::settings('webhooks.cron_token') ?? '');
if ($expected === '' || hash_equals($expected, $incoming) === false) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

if ((string) (App::settings('webhooks.enabled') ?? 'true') !== 'true') {
    echo 'Webhooks disabled';
    exit();
}

// -----------------------------------------------------------------------------
// 🔁 Delegate the entire sweep to WebhookDispatcher::retryDue() — see that
// method's own doc for the full selection/backoff/dead-letter contract.
// -----------------------------------------------------------------------------
$summary = WebhookDispatcher::retryDue();

echo 'OK ' . json_encode($summary);
