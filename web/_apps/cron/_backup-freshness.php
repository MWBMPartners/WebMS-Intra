<?php
// Path: _apps/cron/_backup-freshness.php
/**
 * -----------------------------------------------------------------------------
 * Shared — Backup freshness (the verdict both callers show) 🔔
 * -----------------------------------------------------------------------------
 * NOT a page. No address points at this file; it is only ever loaded with
 * require_once by:
 *
 *   - web/_apps/admin/maintenance/backup-check.php — the staff page, which
 *     shows the verdict and the most recent snapshots;
 *   - web/_apps/cron/backup-check.php — the scheduled job,
 *     /cron/backup-check?token=…, which returns the verdict as JSON and emails
 *     the alert recipients when the backups are stale or missing.
 *
 * WHY THIS FILE EXISTS
 * The job used to be a "?cron=1" mode of the staff page. Once the Router
 * started enforcing sign-in (issue #497) a scheduler with a token and no
 * session would have been redirected to the sign-in page, and no alert would
 * ever have been sent — the one failure a backup alert exists to prevent. The
 * job moved to its own address, and the verdict moved here so the page and the
 * job always agree about whether the backups are fresh.
 *
 * The code below was moved from the staff page. Sending the alert email is
 * NOT here: only the scheduled job sends it, so that an administrator who is
 * simply looking at the page is not emailed as well.
 *
 * WHAT THIS FILE CANNOT DO
 * No permission check. Each caller decides who may see the result.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/142
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/497
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/577
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\DbBackup;

/**
 * Work out how old the newest backup snapshot is, and what that means.
 *
 * Only a FINISHED snapshot counts. This used to read the first folder in the
 * list, and a folder left behind by a snapshot that FAILED is the newest folder
 * there is, so the day backups stopped working this said they were fresh.
 *
 * @return array{snapshots: array<int, array<string, mixed>>, thresholdHours: int, recipients: array<int, string>, state: string, message: string, ageHours: int|null, unfinished: int}
 *         state is 'ok', 'stale' or 'critical'. ageHours is null when there is
 *         no finished snapshot, or its time could not be read. unfinished is
 *         how many snapshot folders were never finished.
 */
function backup_freshness_check(): array
{
    // 📦 Probe snapshots
    $db        = App::db();
    $backup    = new DbBackup($db);
    $snapshots = $backup->listSnapshots();

    $thresholdHours = (int) (App::settings()['portal']['backups']['max_age_hours'] ?? 36);
    $recipientsRaw  = (string) (App::settings()['portal']['backups']['alert_recipients'] ?? '');
    $recipients     = array_filter(array_map('trim', explode(',', $recipientsRaw)));

    $mostRecent = $backup->newestFinished();
    $unfinished = $backup->unfinishedCount();
    $ageHours   = null;
    $state      = 'ok';
    $message    = '';

    if ($mostRecent === null) {
        $state   = 'critical';
        $message = 'No finished snapshot found in web/_backups/.';
    } else {
        $createdAt = strtotime((string) $mostRecent['created_at']);
        if ($createdAt === false) {
            $state   = 'critical';
            $message = 'Most recent snapshot has unparseable created_at timestamp.';
        } else {
            $ageHours = (int) round((time() - $createdAt) / 3600);
            if ($ageHours > $thresholdHours) {
                $state   = 'stale';
                $message = sprintf(
                    'Most recent backup is %d hours old (threshold: %d hours).',
                    $ageHours,
                    $thresholdHours
                );
            } else {
                $state   = 'ok';
                $message = sprintf(
                    'Most recent backup is %d hours old (within %d-hour threshold).',
                    $ageHours,
                    $thresholdHours
                );
            }
        }
    }

    // 🚧 An unfinished folder means a snapshot failed or was cut off (or one
    //    is being taken at this moment). The verdict above is about the last
    //    snapshot that WORKED, so say this as well: a run of failures would
    //    otherwise stay quiet until the last good one grew stale.
    if ($unfinished > 0) {
        $message .= sprintf(
            ' %d unfinished snapshot folder%s found: a snapshot failed or was cut off. See Backup Management.',
            $unfinished,
            $unfinished === 1 ? ' was' : 's were'
        );
    }

    return [
        'unfinished'     => $unfinished,
        'snapshots'      => $snapshots,
        'thresholdHours' => $thresholdHours,
        'recipients'     => $recipients,
        'state'          => $state,
        'message'        => $message,
        'ageHours'       => $ageHours,
    ];
}
