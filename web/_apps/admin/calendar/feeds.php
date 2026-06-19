<?php
// Path: _apps/admin/calendar/feeds.php
/**
 * -----------------------------------------------------------------------------
 * Admin — External calendar feeds (#327)
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) { http_response_code(403); exit('Forbidden'); }

$siteId = Site::id();

$feeds = [];
$stmt = $mysqli->prepare('SELECT feedID, name, url, fetchEveryMins, isActive, lastFetchedAt, lastFetchStatus, lastImportCount FROM tblExternalFeeds WHERE siteID = ? ORDER BY name');
$stmt->bind_param('i', $siteId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $feeds[] = $r; }
$stmt->close();

$pageTitle = 'External Calendar Feeds';
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:880px;">
    <h1 class="h4 mb-2"><i class="fa-solid fa-rss me-2 text-primary"></i>External Calendar Feeds</h1>
    <p class="text-muted small">Subscribe to ICS / iCal feeds from denominational bulletins, partner orgs, or public-holiday calendars. Cron auto-fetches; events appear in /calendar.</p>

    <?php if (count($feeds) === 0): ?>
        <div class="alert alert-info small">No feeds configured yet.</div>
    <?php else: ?>
        <div class="portal-data-list mb-4">
        <?php foreach ($feeds as $f): ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <strong><?php echo htmlspecialchars((string) $f['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span class="badge <?php echo (int) $f['isActive'] === 1 ? 'bg-success' : 'bg-secondary'; ?> ms-1"><?php echo (int) $f['isActive'] === 1 ? 'Active' : 'Paused'; ?></span>
                    <div class="small text-muted">
                        Refetch every <strong><?php echo (int) $f['fetchEveryMins']; ?> min</strong>
                        <?php if (!empty($f['lastFetchedAt'])): ?>
                            &middot; last: <?php echo htmlspecialchars(date('j M H:i', strtotime((string) $f['lastFetchedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                            (<?php echo (int) $f['lastImportCount']; ?> events,
                            <?php echo htmlspecialchars((string) ($f['lastFetchStatus'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>)
                        <?php endif; ?>
                    </div>
                    <div class="small text-muted text-truncate" style="max-width:540px;"><?php echo htmlspecialchars((string) $f['url'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="portal-data-row-aside">
                    <form method="post" action="/admin/calendar/feeds/save" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="feedID" value="<?php echo (int) $f['feedID']; ?>">
                        <input type="hidden" name="action" value="<?php echo (int) $f['isActive'] === 1 ? 'pause' : 'resume'; ?>">
                        <button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-<?php echo (int) $f['isActive'] === 1 ? 'pause' : 'play'; ?>"></i></button>
                    </form>
                    <form method="post" action="/admin/calendar/feeds/save" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="feedID" value="<?php echo (int) $f['feedID']; ?>">
                        <input type="hidden" name="action" value="remove">
                        <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete feed and ALL its imported events?');"><i class="fa-solid fa-xmark"></i></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 class="h6">Add feed</h2>
    <form method="post" action="/admin/calendar/feeds/save" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="action" value="add">
        <div class="col-md-4"><label class="form-label small">Name</label><input type="text" name="name" required maxlength="120" class="form-control form-control-sm" placeholder="e.g. SDA UK Conference Calendar"></div>
        <div class="col-md-6"><label class="form-label small">ICS URL</label><input type="url" name="url" required maxlength="2000" class="form-control form-control-sm" placeholder="https://example.com/feed.ics"></div>
        <div class="col-md-2"><label class="form-label small">Refetch (min)</label><input type="number" name="fetchEveryMins" min="15" max="10080" value="360" class="form-control form-control-sm"></div>
        <div class="col-md-12"><button class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>Add feed</button></div>
    </form>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
