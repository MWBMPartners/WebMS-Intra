<?php
// Path: public_html/service-plans/print.php
/**
 * Service Plans — print-friendly view. The .print-view body class
 * triggers the existing print.css overrides (#241).
 *
 * @package   Portal\ServicePlans
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/262
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Markdown;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$id     = (int) ($_GET['id'] ?? 0);

$plan = null;
$stmt = $db->prepare('SELECT * FROM tblServicePlan WHERE planID = ? AND siteID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $id, $siteId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($plan === null) {
    http_response_code(404);
    exit('Plan not found');
}

$items = [];
$stmt = $db->prepare(
    'SELECT i.sectionType, i.position, i.title, i.presenterText, i.durationMin, i.notes, '
    . '       u.fullName AS presenterName '
    . 'FROM tblServicePlanItem i LEFT JOIN tblUsers u ON u.userID = i.presenterID '
    . 'WHERE i.planID = ? ORDER BY i.position, i.itemID'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $items[] = $r;
    }
    $stmt->close();
}

$portalName = (string) (App::settings()['site']['name'] ?? 'Portal');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo htmlspecialchars((string) $plan['title'], ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="stylesheet" href="/assets/css/print.css">
<style>
body { font-family: Georgia, "Times New Roman", serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; color: #1b2330; line-height: 1.5; }
h1 { font-size: 1.5rem; margin-bottom: 0.25rem; }
.meta { color: #6b7280; font-size: 0.9rem; margin-bottom: 1.5rem; }
.section { border-bottom: 1px solid #e5e7eb; padding: 0.75rem 0; page-break-inside: avoid; }
.section-num { float: left; font-weight: bold; width: 2rem; color: #5e6ad2; }
.section-body { margin-left: 2rem; }
.section-title { font-weight: 600; }
.section-type { color: #6b7280; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
.section-meta { color: #6b7280; font-size: 0.9rem; margin-top: 0.25rem; }
.section-notes { margin-top: 0.5rem; font-size: 0.9rem; color: #374151; }
@media screen { .print-controls { position: fixed; top: 1rem; right: 1rem; } }
@media print { .print-controls { display: none; } body { margin: 0; padding: 0; } }
</style>
</head>
<body class="print-view">
<div class="print-controls">
    <button onclick="window.print()" style="padding: 0.5rem 1rem; background:#5e6ad2; color:#fff; border:none; border-radius:0.375rem; cursor:pointer;">Print</button>
    <a href="/service-plans/edit?id=<?php echo $id; ?>" style="margin-left:0.5rem;">Back</a>
</div>

<h1><?php echo htmlspecialchars((string) $plan['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
<div class="meta">
    <?php echo htmlspecialchars($portalName, ENT_QUOTES, 'UTF-8'); ?>
    &middot; <?php echo htmlspecialchars(date('l, j F Y', strtotime((string) $plan['serviceDate'])), ENT_QUOTES, 'UTF-8'); ?>
</div>

<?php foreach ($items as $idx => $it):
    $sectionLabel = ucwords(str_replace('_', ' ', (string) $it['sectionType']));
    $presenter = (string) ($it['presenterName'] ?? '') !== ''
        ? (string) $it['presenterName']
        : (string) ($it['presenterText'] ?? '');
?>
    <div class="section">
        <div class="section-num"><?php echo $idx + 1; ?>.</div>
        <div class="section-body">
            <div class="section-type"><?php echo htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php if (($it['title'] ?? '') !== ''): ?>
                <div class="section-title"><?php echo htmlspecialchars((string) $it['title'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($presenter !== '' || $it['durationMin'] !== null): ?>
                <div class="section-meta">
                    <?php if ($presenter !== ''): ?>
                        Presented by <?php echo htmlspecialchars($presenter, ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                    <?php if ($it['durationMin'] !== null): ?>
                        <?php echo $presenter !== '' ? '&middot;' : ''; ?> <?php echo (int) $it['durationMin']; ?> min
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (($it['notes'] ?? '') !== ''): ?>
                <div class="section-notes"><?php echo Markdown::render((string) $it['notes'], ['allow_links' => true]); ?></div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

</body>
</html>
