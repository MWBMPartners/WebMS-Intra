<?php
// Path: _apps/announcements/_workflow-gate.php
/**
 * -----------------------------------------------------------------------------
 * Announcements — Shared Publish-Approval Gate 🚦 (#443 security hardening)
 * -----------------------------------------------------------------------------
 * NOT a route — no tblRoutes row ever points at this file and it lives
 * outside `api/`, so it is reachable only via `require_once` from a sibling
 * handler in this app (mirrors `_apps/assets/api/_coerce.php`'s "helper, not
 * a page" convention, generalised to a non-`api/` file since this one is
 * shared by BOTH the HTML `save.php` handler and the two JSON API handlers).
 *
 * Single source of truth for "what happens when a publish request needs the
 * `announcement_publish` workflow gate" — extracted so `announcements/
 * save.php`, `announcements/api/create.php`, and `announcements/api/
 * update.php` can never drift into three different (and possibly
 * inconsistent, or outright missing) implementations of the same security
 * gate again. Each caller still decides FOR ITSELF whether a given request
 * IS a publish request (create: posted isPublished=1 while the gate is on;
 * update: a genuine 0→1 flip while the gate is on) and which per-site
 * settings reader is correct for its own context (`save.php` is a normal
 * session request so `Settings::get()`'s ambient snapshot is fine; the API
 * handlers can be reached via a bearer key whose site may differ from the
 * host-detected snapshot, so they use `App::settingForSite()` instead) —
 * this file only owns what happens AFTER that decision is made.
 *
 * 🛡️ SEC-03: fails open (publishes directly) ONLY when
 * `Workflow::start()` reports `reason === 'no_definition'` — a concurrent
 * double-submit that raced `Workflow::start()`'s own atomic duplicate-active
 * INSERT ('duplicate_active'), or an unexpected error ('error'), is treated
 * exactly like the pre-checked "already awaiting approval" case: blocked,
 * never published directly. See `Workflow::start()`'s own docblock for the
 * full `reason` contract.
 *
 * @package   Portal\Announcements
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/443
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Logger;
use Portal\Core\Workflow;

/**
 * Run the announcement_publish gate for one withheld publish request and
 * report what happened. Never publishes on anything other than a genuine
 * "no definition configured" — see file header.
 *
 * @return array{status: string, message: string, flashType: string}
 *         `status` is one of:
 *         - 'submitted'        Workflow::start() succeeded — an approval
 *                               instance is now running.
 *         - 'already_pending'  an active instance already exists for this
 *                               subject (the caller's own pre-check hit, OR
 *                               start()'s race-safe INSERT/an unexpected
 *                               error reported 'duplicate_active'/'error')
 *                               — never published.
 *         - 'published_direct' fail-open: no active/steppable definition
 *                               exists for the site — published directly,
 *                               a platform warning was logged.
 */
function announcements_workflow_gate_publish(
    \mysqli $db,
    int $siteId,
    int $announcementId,
    int $actorId,
    string $label,
    string $slug,
    string $verb
): array {
    // 1️⃣ Pre-check — cheap, and gives the common case ("someone already
    //    submitted this for approval") a clean message without ever
    //    reaching Workflow::start()'s own duplicate-active guard.
    $already = Workflow::activeInstanceForSubject('tblAnnouncements', $announcementId);
    if ($already !== null) {
        return [
            'status'    => 'already_pending',
            'message'   => 'Announcement ' . $verb . ' — already awaiting approval.',
            'flashType' => 'info',
        ];
    }

    $result = Workflow::start(
        'announcement_publish',
        'tblAnnouncements',
        $announcementId,
        $actorId,
        $label,
        ['url' => '/announcements/view?slug=' . $slug]
    );

    if ($result['instanceId'] !== null) {
        return [
            'status'    => 'submitted',
            'message'   => 'Announcement ' . $verb . ' — publication submitted for approval.',
            'flashType' => 'success',
        ];
    }

    // 🛡️ SEC-03: 'duplicate_active' (lost the pre-check-to-INSERT race
    // above to a concurrent submit) and 'error' (unexpected exception) must
    // NEVER fail open — from here they're indistinguishable from "an
    // approval is already running", so they get the identical blocked
    // response as the pre-check hit above.
    if ($result['reason'] !== 'no_definition') {
        return [
            'status'    => 'already_pending',
            'message'   => 'Announcement ' . $verb . ' — already awaiting approval.',
            'flashType' => 'info',
        ];
    }

    // 🛟 Fail-open (#443 decision 4): no active/steppable definition ⇒
    // publish directly rather than block the user. Never a security
    // boundary — an editorial convenience gate only.
    $pubStmt = $db->prepare('UPDATE tblAnnouncements SET isPublished = 1 WHERE announcementID = ? AND siteID = ?');
    if ($pubStmt !== false) {
        $pubStmt->bind_param('ii', $announcementId, $siteId);
        $pubStmt->execute();
        $pubStmt->close();
    }
    Logger::errorPlatform(
        'Workflow',
        'Warning',
        'WF_MISCONFIG',
        'workflows.announcements.enabled is on but no active/steppable definition exists — published directly',
        'announcementID=' . $announcementId . ' siteID=' . $siteId
    );

    return [
        'status'    => 'published_direct',
        'message'   => 'Announcement ' . $verb . ' — approval workflow not configured, published directly.',
        'flashType' => 'warning',
    ];
}
