<?php
// Path: _apps/venues/schedule-pdf.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Schedule PDF Export 🏛️🖨️
 * -----------------------------------------------------------------------------
 * GET, viewer (cost column manager-only, exactly like `export.php`). Renders
 * the same year/range schedule as a landscape, month-sectioned PDF: one CSS
 * Grid row per date (Date · Day · Usage type · Times · Status · Notes),
 * status colour shown as a left-border chip, legend footer. NEVER a table
 * tag, even in PDF HTML — CSS Grid is dompdf's most reliable primitive AND
 * honours the house rule (`AssetRegister.php` l.7098-7100). Same
 * `Pdf::create()` → stream → `unlink()` pipeline as `giving/my-statement.php`
 * l.26-37, with the `assets/labels-pdf.php` l.192-238 print-CSS fallback
 * when dompdf isn't installed on this server.
 *
 * @package   Portal\Venues
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/429
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Pdf;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Venues;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$isMgr  = Venues::canManage();
$esc    = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// -----------------------------------------------------------------------------
// 🔎 Resolve the venue — same contract as export.php.
// -----------------------------------------------------------------------------
$venueId = (int) ($_GET['venue'] ?? 0);
$venue   = null;
if ($venueId > 0) {
    $venue = Venues::getVenue($venueId, $siteId);
    if ($venue === null) {
        Router::renderError(404);
        return;
    }
} else {
    $active = Venues::listVenues($siteId, true);
    if (count($active) === 1) {
        $venueId = (int) $active[0]['venueID'];
        $venue = $active[0];
    }
}
if ($venue === null) {
    $_SESSION['flash_msg']  = 'Choose a venue before printing its schedule.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /venues');
    exit();
}

$year = (int) ($_GET['year'] ?? date('Y'));
$from = (string) ($_GET['from'] ?? '');
$to   = (string) ($_GET['to'] ?? '');

$filters = ['venueID' => $venueId, 'includeRejected' => true];
if ($from !== '' && $to !== '') {
    $filters['dateFrom'] = $from;
    $filters['dateTo']   = $to;
} else {
    $filters['year'] = $year;
}

$bookings = Venues::listBookings($siteId, $filters);

$rowColor = static function (array $row): string {
    $kindColor = Venues::KIND_COLORS[(string) $row['usageKind']] ?? null;
    if ($kindColor !== null) {
        return $kindColor;
    }
    return Venues::statusColor(['color' => $row['statusColorRaw'] ?? null, 'statusCategory' => $row['statusCategory'] ?? null]);
};

// -----------------------------------------------------------------------------
// 🧱 Build the CSS-Grid HTML body (shared by both the dompdf path and the
// print-CSS fallback path below).
// -----------------------------------------------------------------------------
$legend = [];
foreach ($bookings as $b) {
    $legend[(string) $b['statusName']] = $rowColor($b);
}

ob_start();
?>
<div class="sched-title">
    <?php echo $esc($venue['venueName']); ?> — <?php echo $from !== '' && $to !== '' ? $esc($from . ' to ' . $to) : (int) $year; ?>
</div>
<div class="sched-grid sched-head">
    <div>Date</div><div>Day</div><div>Usage type</div><div>Times</div><div>Status</div><div>Notes</div>
    <?php if ($isMgr === true): ?><div>Cost</div><?php endif; ?>
</div>
<?php
$lastMonth = '';
foreach ($bookings as $b) {
    $date = (string) $b['bookingDate'];
    $month = date('F Y', strtotime($date));
    if ($month !== $lastMonth) {
        $lastMonth = $month;
        echo '<div class="sched-month">' . $esc($month) . '</div>';
    }
    $times = ($b['startTime'] !== null && $b['endTime'] !== null)
        ? $esc(substr((string) $b['startTime'], 0, 5) . '–' . substr((string) $b['endTime'], 0, 5))
        : '—';
    ?>
    <div class="sched-grid sched-row" style="border-left-color: <?php echo $esc($rowColor($b)); ?>;">
        <div><?php echo $esc(date('j M Y', strtotime($date))); ?></div>
        <div><?php echo $esc(date('D', strtotime($date))); ?></div>
        <div><?php echo $esc($b['usageTypeName']); ?></div>
        <div><?php echo $times; ?></div>
        <div><?php echo $esc($b['statusName']); ?></div>
        <div><?php echo $esc((string) ($b['notes'] ?? '')); ?></div>
        <?php if ($isMgr === true): ?>
            <div><?php echo $b['costPence'] !== null ? number_format((int) $b['costPence'] / 100, 2) : '—'; ?></div>
        <?php endif; ?>
    </div>
    <?php
}
if (count($bookings) === 0) {
    echo '<p>No bookings in this range.</p>';
}
?>
<?php if (count($legend) > 0): ?>
    <div class="sched-legend">
        <?php foreach ($legend as $name => $color): ?>
            <span class="sched-legend-item"><span class="sched-swatch" style="background-color: <?php echo $esc($color); ?>;"></span><?php echo $esc($name); ?></span>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
$bodyHtml = (string) ob_get_clean();

$colCount = $isMgr === true ? 7 : 6;
$css = '
    @page { size: landscape; margin: 12mm; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #212529; }
    .sched-title { font-size: 16px; font-weight: bold; margin-bottom: 8px; }
    .sched-month { font-weight: bold; background: #eee; padding: 3px 6px; margin-top: 6px; }
    .sched-grid { display: grid; grid-template-columns: repeat(' . $colCount . ', 1fr); gap: 4px; padding: 3px 6px; align-items: center; }
    .sched-head { font-weight: bold; border-bottom: 1px solid #333; }
    .sched-row { border-bottom: 1px solid #ddd; border-left: 4px solid #6c757d; padding-left: 6px; }
    .sched-legend { margin-top: 10px; }
    .sched-legend-item { display: inline-block; margin-right: 12px; }
    .sched-swatch { display: inline-block; width: 10px; height: 10px; margin-right: 4px; }
';

$pdfHtml = '<!doctype html><html><head><meta charset="utf-8"><title>Venue schedule</title><style>' . $css . '</style></head><body>' . $bodyHtml . '</body></html>';

// -----------------------------------------------------------------------------
// 📁 Ensure the temp output directory exists, generate, stream, unlink.
// -----------------------------------------------------------------------------
$tmpDir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'venues' . DIRECTORY_SEPARATOR . 'tmp';
if (is_dir($tmpDir) === false) {
    mkdir($tmpDir, 0755, true);
}
$pdfPath = $tmpDir . DIRECTORY_SEPARATOR . 'schedule-' . $venueId . '-' . time() . '-' . bin2hex(random_bytes(4)) . '.pdf';

$generated = Pdf::create($pdfHtml, $pdfPath);

if ($generated !== false) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="venue-schedule-' . $venueId . '-' . (int) $year . '.pdf"');
    header('Content-Length: ' . (string) filesize($generated));
    readfile($generated);
    @unlink($generated);
    exit();
}

// -----------------------------------------------------------------------------
// 🛟 FALLBACK — dompdf not installed. Render the same HTML/CSS as a browser
// print-CSS page (`assets/labels-pdf.php` l.206-238 pattern).
// -----------------------------------------------------------------------------
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Venue schedule</title>
<style><?php echo $css; ?></style>
<style>
@media screen { .print-controls { position: fixed; top: 1rem; right: 1rem; z-index: 100; font-family: system-ui, sans-serif; } }
@media print { .print-controls { display: none; } }
</style>
</head>
<body>
<div class="print-controls">
    <button onclick="window.print()" style="padding:0.5rem 1rem; background:#198754; color:#fff; border:none; border-radius:0.375rem; cursor:pointer;">Print</button>
    <a href="/venues?venue=<?php echo (int) $venueId; ?>" style="margin-left:0.5rem;">Back to schedule</a>
    <p style="max-width:220px; font-size:0.8rem; color:#555;">A PDF library isn't installed on this server — use your browser's print dialog (landscape, minimal margins) instead.</p>
</div>
<?php echo $bodyHtml; ?>
</body>
</html>
