<?php
// Path: public_html/newsletter/edit.php
/**
 * Newsletter — composer (new + edit). Title/subject/segment header at top,
 * block list below with type-specific quick-add forms.
 *
 * @package   Portal\Newsletter
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/269
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$id     = (int) ($_GET['id'] ?? 0);

// New newsletter — create a draft on first hit so block-save endpoints have something to attach to.
if ($id === 0) {
    $stmt = $db->prepare('INSERT INTO tblNewsletter (siteID, title, subject, status, createdByID) VALUES (?, ?, ?, "draft", ?)');
    if ($stmt !== false) {
        $title   = 'Untitled newsletter';
        $subject = '';
        $stmt->bind_param('issi', $siteId, $title, $subject, $userId);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
    }
    header('Location: /newsletter/edit?id=' . $id);
    exit();
}

$newsletter = null;
$stmt = $db->prepare('SELECT * FROM tblNewsletter WHERE newsletterID = ? AND siteID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $id, $siteId);
    $stmt->execute();
    $newsletter = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($newsletter === null) {
    http_response_code(404);
    exit('Newsletter not found');
}

$segments = [];
$stmt = $db->prepare('SELECT segmentID, name FROM tblNewsletterSegment WHERE siteID = ? ORDER BY name');
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $segments[] = $r;
    }
    $stmt->close();
}

$blocks = [];
$stmt = $db->prepare('SELECT contentID, blockType, position, payload FROM tblNewsletterContent WHERE newsletterID = ? ORDER BY position, contentID');
if ($stmt !== false) {
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $blocks[] = $r;
    }
    $stmt->close();
}

$csrf = Auth::csrfToken();

$pageTitle   = 'Edit Newsletter';
$pageSection = 'newsletter';
$breadcrumbs = ['Dashboard' => '/', 'Newsletter' => '/newsletter', 'Edit' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-pen-to-square me-2"></i>Edit newsletter</h1>
    <div class="d-flex gap-2">
        <a href="/newsletter/preview?id=<?php echo $id; ?>" class="btn btn-outline-secondary btn-sm" target="_blank">Preview</a>
        <a href="/newsletter/recipients?id=<?php echo $id; ?>" class="btn btn-outline-secondary btn-sm">Recipients</a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="post" action="/newsletter/save" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="newsletterID" value="<?php echo $id; ?>">
            <div class="col-md-6">
                <label class="form-label">Title</label>
                <input type="text" class="form-control" name="title" value="<?php echo htmlspecialchars((string) $newsletter['title'], ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Email subject</label>
                <input type="text" class="form-control" name="subject" value="<?php echo htmlspecialchars((string) ($newsletter['subject'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Defaults to title">
            </div>
            <div class="col-md-6">
                <label class="form-label">Segment</label>
                <select class="form-select" name="segmentID">
                    <option value="">All opted-in members</option>
                    <?php foreach ($segments as $s): ?>
                        <option value="<?php echo (int) $s['segmentID']; ?>" <?php echo (int) ($newsletter['segmentID'] ?? 0) === (int) $s['segmentID'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $s['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Schedule for</label>
                <input type="datetime-local" class="form-control" name="scheduledFor" value="<?php echo htmlspecialchars((string) ($newsletter['scheduledFor'] !== null ? date('Y-m-d\TH:i', (int) strtotime((string) $newsletter['scheduledFor'])) : ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button class="btn btn-primary w-100" type="submit">Save</button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><strong>Content blocks</strong></div>
    <div class="card-body">
        <?php if (count($blocks) === 0): ?>
            <p class="text-muted">No blocks yet. Add the first below.</p>
        <?php else: ?>
            <div class="portal-data-list mb-3">
                <?php foreach ($blocks as $b):
                    $cfg = json_decode((string) ($b['payload'] ?? '{}'), true);
                    if (is_array($cfg) === false) { $cfg = []; }
                ?>
                    <div class="row py-2 border-bottom">
                        <div class="col-md-2"><span class="badge bg-secondary"><?php echo htmlspecialchars((string) $b['blockType'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-8 small">
                            <?php
                            $summary = '';
                            if (isset($cfg['text']) === true) {
                                $summary = mb_substr((string) $cfg['text'], 0, 120);
                            } elseif (isset($cfg['label']) === true) {
                                $summary = (string) $cfg['label'] . ' → ' . (string) ($cfg['url'] ?? '');
                            } elseif (isset($cfg['url']) === true) {
                                $summary = (string) $cfg['url'];
                            } elseif (isset($cfg['count']) === true) {
                                $summary = 'Pulls last ' . (int) $cfg['count'];
                            } elseif (isset($cfg['days']) === true) {
                                $summary = 'Pulls next ' . (int) $cfg['days'] . ' days';
                            }
                            echo htmlspecialchars($summary, ENT_QUOTES, 'UTF-8');
                            ?>
                        </div>
                        <div class="col-md-2 text-end">
                            <form method="post" action="/newsletter/block-delete" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="newsletterID" value="<?php echo $id; ?>">
                                <input type="hidden" name="contentID" value="<?php echo (int) $b['contentID']; ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Delete this block?">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/newsletter/block-save" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="newsletterID" value="<?php echo $id; ?>">
            <div class="col-md-2">
                <label class="form-label small">Type</label>
                <select class="form-select form-select-sm" name="blockType" id="blockType">
                    <option value="heading">Heading</option>
                    <option value="text">Text / markdown</option>
                    <option value="image">Image</option>
                    <option value="divider">Divider</option>
                    <option value="cta">Call-to-action</option>
                    <option value="announcements">Announcements (auto)</option>
                    <option value="events">Upcoming events (auto)</option>
                    <option value="prayers">Prayer requests (auto)</option>
                    <option value="sermon">Latest sermon (auto)</option>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label small">Content / config</label>
                <textarea class="form-control form-control-sm font-monospace" name="content" rows="2" placeholder="text: prose / heading: title / image: URL / cta: label|url / announcements/events: number"></textarea>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-primary btn-sm w-100" type="submit"><i class="fa-solid fa-plus me-1"></i>Add block</button>
            </div>
        </form>
    </div>
</div>

<?php if ((string) $newsletter['status'] !== 'sent' && (string) $newsletter['status'] !== 'sending'): ?>
    <form method="post" action="/newsletter/send" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="newsletterID" value="<?php echo $id; ?>">
        <button class="btn btn-success" type="submit" data-confirm="Send this newsletter now? Recipients will be locked in.">
            <i class="fa-solid fa-paper-plane me-1"></i>Send now
        </button>
    </form>
<?php else: ?>
    <p class="text-success"><i class="fa-solid fa-check-circle me-1"></i>This newsletter has been sent (<?php echo (int) $newsletter['sentCount']; ?> recipients).</p>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
