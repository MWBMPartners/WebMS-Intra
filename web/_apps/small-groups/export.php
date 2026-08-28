<?php
// Path: _apps/small-groups/export.php
/**
 * -----------------------------------------------------------------------------
 * Small Groups — CSV Export 📥
 * -----------------------------------------------------------------------------
 * `?type=roster|attendance&id=&from=&to=`. Gate as report.php
 * (`SmallGroups::canManage()`). Uses the existing `Portal\Core\CsvExporter`
 * (leadership/attendance export precedent) — `CsvExporter::download()`.
 * Roster export includes name/role/status/joinedAt; attendance export is
 * the report matrix (one row per member with presence stats).
 *
 * @package   Portal\SmallGroups
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/150
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\CsvExporter;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\SmallGroups;

Auth::ensureSession();
Auth::requireLogin();

if (SmallGroups::isEnabled() === false) {
    Router::renderError(404);
    return;
}

$siteId  = Site::id();
$groupId = (int) ($_GET['id'] ?? 0);
$type    = (string) ($_GET['type'] ?? 'roster');

$group = SmallGroups::getGroup($siteId, $groupId);
if ($group === null) {
    Router::renderError(404);
    return;
}
if (SmallGroups::canManage($siteId, $groupId) === false) {
    Router::renderError(403);
    return;
}

$slug = (string) $group['groupSlug'];

if ($type === 'attendance') {
    $from = trim((string) ($_GET['from'] ?? ''));
    $to   = trim((string) ($_GET['to'] ?? ''));
    if ($from === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) {
        $from = date('Y-m-d', strtotime('-12 weeks'));
    }
    if ($to === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
        $to = date('Y-m-d');
    }

    $stats = SmallGroups::attendanceStats($siteId, $groupId, $from, $to);
    $rows = [];
    foreach ($stats['members'] as $mm) {
        $rows[] = [
            'Member'         => $mm['fullName'],
            'Present'        => $mm['presentCount'],
            'Meetings'       => $mm['meetingsCount'],
            'Attendance (%)' => $mm['percentage'],
        ];
    }
    CsvExporter::download('small-groups-' . $slug . '-attendance-' . $from . '-to-' . $to . '.csv', $rows);
    exit();
}

// 👥 Roster export (default).
$members = SmallGroups::membersOf($siteId, $groupId, null);
$rows = [];
foreach ($members as $m) {
    $rows[] = [
        'Name'      => $m['fullName'],
        'Email'     => $m['emailAddress'],
        'Role'      => $m['memberRole'],
        'Status'    => $m['status'],
        'Joined'    => $m['joinedAt'] ?? '',
        'Ended'     => $m['endedAt'] ?? '',
    ];
}
CsvExporter::download('small-groups-' . $slug . '-roster.csv', $rows);
exit();
