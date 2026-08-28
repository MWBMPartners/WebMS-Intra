<?php
// Path: public_html/admin/hymns.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Hymnal index + remote (iHymns) lookup configuration 📖
 * -----------------------------------------------------------------------------
 * Gap #128 residual. Admin-gated exactly like `/admin/captcha` (no
 * AppRegistry entry of its own — this supports the existing service-plans
 * + worship apps rather than being a marketplace app in its own right):
 *   • Hymnal CRUD (add/rename/deactivate a hymn book) — portal-data-list.
 *   • Per-hymnal CSV import of a hymn index (metadata only, never lyrics).
 *   • Remote ("iHymns") provider settings — https-only, single-host
 *     allowlist, encrypted API key, "Test connection" button.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/128
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Hymnal;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$csrf   = Auth::csrfToken();

$hymnals = Hymnal::listHymnals($siteId, false);

// 🔍 Optional: viewing one hymnal's entries (?hymnalID=).
$viewHymnalId = (int) ($_GET['hymnalID'] ?? 0);
$viewHymnal   = null;
$entries      = [];
if ($viewHymnalId > 0) {
    foreach ($hymnals as $h) {
        if ((int) $h['hymnalID'] === $viewHymnalId) {
            $viewHymnal = $h;
            break;
        }
    }
    if ($viewHymnal !== null) {
        $db   = App::db();
        $stmt = $db->prepare(
            'SELECT entryID, number, title, firstLine, author, tuneName, ccliNumber '
            . 'FROM tblHymnalEntries WHERE hymnalID = ? ORDER BY numberSort, number LIMIT 500'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $viewHymnalId);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                $entries[] = $r;
            }
            $stmt->close();
        }
    }
}

$remote = (array) (App::settings('hymns') ?? []);
$remoteEnabled = (string) ($remote['remote']['enabled']  ?? 'false') === 'true';
$remoteHost    = (string) ($remote['remote']['host']     ?? '');
$remoteBaseUrl = (string) ($remote['remote']['baseUrl']  ?? '');
$remoteHasKey  = (string) ($remote['remote']['apiKey']   ?? '') !== '';
$remoteTtl     = (int) ($remote['remote']['cacheTtl']    ?? 86400);

$publicShareEnabled = (string) (App::settings('service_plans.public_share.enabled') ?? 'false') === 'true';

$flashMsg  = (string) ($_SESSION['flash_msg']  ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? '');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle   = 'Hymnal Lookup';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Hymnal Lookup' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-book-bible me-2"></i>Hymnal Lookup</h1>
<p class="text-secondary">
    Local hymn-book index used by the Service Plans hymn picker (gap #128). Metadata
    only — number, title, tune, author, CCLI — never lyrics; lyrics stay hand-entered
    in the Worship <a href="/worship/songs">Song Library</a> under your own CCLI licence.
</p>

<?php if ($viewHymnal !== null): ?>
    <!-- ═══════════════ Hymnal entries + CSV import ═══════════════ -->
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h5 mb-0"><?php echo htmlspecialchars((string) $viewHymnal['name'], ENT_QUOTES, 'UTF-8'); ?>
            <span class="badge bg-secondary"><?php echo htmlspecialchars((string) $viewHymnal['code'], ENT_QUOTES, 'UTF-8'); ?></span>
        </h2>
        <a href="/admin/hymns" class="btn btn-outline-secondary btn-sm">&larr; All hymnals</a>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>CSV import</strong></div>
        <div class="card-body">
            <p class="small text-muted">
                Header row: <code>number,title,firstLine,author,tuneName,meter,ccliNumber,copyrightLine</code>
                — only <code>number</code> and <code>title</code> are required; column order doesn't matter.
                Re-importing the same file is safe (upsert on hymn number). Max
                <?php echo number_format(Hymnal::CSV_MAX_BYTES / 1024); ?> KB / <?php echo Hymnal::CSV_MAX_ROWS; ?> rows.
            </p>
            <form method="post" action="/admin/hymns/save" enctype="multipart/form-data" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="csv_import">
                <input type="hidden" name="hymnalID" value="<?php echo (int) $viewHymnal['hymnalID']; ?>">
                <div class="col-md-8">
                    <input type="file" name="csv_file" accept=".csv,text/csv" required class="form-control form-control-sm">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-upload me-1"></i>Import CSV</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Add a single entry</strong></div>
        <div class="card-body">
            <form method="post" action="/admin/hymns/save" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="entry_save">
                <input type="hidden" name="hymnalID" value="<?php echo (int) $viewHymnal['hymnalID']; ?>">
                <div class="col-md-2"><input type="text" name="number" maxlength="20" required class="form-control form-control-sm" placeholder="Number"></div>
                <div class="col-md-4"><input type="text" name="title" maxlength="255" required class="form-control form-control-sm" placeholder="Title"></div>
                <div class="col-md-3"><input type="text" name="author" maxlength="255" class="form-control form-control-sm" placeholder="Author (optional)"></div>
                <div class="col-md-2"><input type="text" name="tuneName" maxlength="120" class="form-control form-control-sm" placeholder="Tune (optional)"></div>
                <div class="col-md-1"><button type="submit" class="btn btn-success btn-sm w-100"><i class="fa-solid fa-plus"></i></button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><strong><?php echo count($entries); ?> entr<?php echo count($entries) === 1 ? 'y' : 'ies'; ?></strong> <span class="text-muted small">(first 500 shown)</span></div>
        <div class="card-body p-0">
            <div class="portal-data-list">
                <?php foreach ($entries as $e): ?>
                    <div class="portal-data-row">
                        <div class="col-2 col-md-1 fw-semibold"><?php echo htmlspecialchars((string) $e['number'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-6 col-md-4"><?php echo htmlspecialchars((string) $e['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-4 col-md-3 small text-muted"><?php echo htmlspecialchars((string) ($e['author'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-6 col-md-2 small text-muted"><?php echo htmlspecialchars((string) ($e['tuneName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-6 col-md-2 text-end">
                            <form method="post" action="/admin/hymns/save" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="entry_delete">
                                <input type="hidden" name="hymnalID" value="<?php echo (int) $viewHymnal['hymnalID']; ?>">
                                <input type="hidden" name="entryID" value="<?php echo (int) $e['entryID']; ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Delete this hymn entry?" data-confirm-destructive="true"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (count($entries) === 0): ?>
                    <p class="text-muted small p-3 mb-0">No entries yet — import a CSV or add one above.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ═══════════════ Hymnal list ═══════════════ -->
    <div class="card mb-3">
        <div class="card-header"><strong>Hymnals</strong></div>
        <div class="card-body p-0">
            <div class="portal-data-list">
                <?php foreach ($hymnals as $h): ?>
                    <div class="portal-data-row">
                        <div class="portal-data-row-main">
                            <a href="/admin/hymns?hymnalID=<?php echo (int) $h['hymnalID']; ?>" class="text-decoration-none fw-semibold">
                                <?php echo htmlspecialchars((string) $h['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars((string) $h['code'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php if ((int) $h['isActive'] === 0): ?><span class="badge bg-light text-dark border ms-1">Inactive</span><?php endif; ?>
                            <div class="small text-muted mt-1"><?php echo (int) $h['entryCount']; ?> entr<?php echo (int) $h['entryCount'] === 1 ? 'y' : 'ies'; ?></div>
                        </div>
                        <div class="portal-data-row-aside">
                            <a href="/admin/hymns?hymnalID=<?php echo (int) $h['hymnalID']; ?>" class="btn btn-sm btn-outline-primary">Open</a>
                            <form method="post" action="/admin/hymns/save" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="hymnal_toggle">
                                <input type="hidden" name="hymnalID" value="<?php echo (int) $h['hymnalID']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo (int) $h['isActive'] === 1 ? 'Deactivate' : 'Activate'; ?></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (count($hymnals) === 0): ?>
                    <p class="text-muted small p-3 mb-0">No hymnals yet — add one below.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><strong>Add a hymnal</strong></div>
        <div class="card-body">
            <form method="post" action="/admin/hymns/save" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="hymnal_save">
                <div class="col-md-3"><input type="text" name="code" maxlength="20" required class="form-control form-control-sm" placeholder="Code (e.g. SDAH)"></div>
                <div class="col-md-5"><input type="text" name="name" maxlength="120" required class="form-control form-control-sm" placeholder="Name (e.g. Seventh-day Adventist Hymnal)"></div>
                <div class="col-md-3"><input type="text" name="publisher" maxlength="255" class="form-control form-control-sm" placeholder="Publisher (optional)"></div>
                <div class="col-md-1"><button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-plus"></i></button></div>
            </form>
        </div>
    </div>

    <!-- ═══════════════ Remote (Tier 2) provider settings ═══════════════ -->
    <div class="card mb-3">
        <div class="card-header"><strong>Remote lookup (optional, off by default)</strong></div>
        <div class="card-body">
            <p class="small text-muted">
                A generic HTTPS JSON provider (e.g. an in-house "iHymns" deployment) the hymn
                picker can also query. <strong>Off until you turn it on</strong> — the local
                index above always works with zero configuration. See DEV_NOTES.md for the
                exact request/response contract an owner-controlled endpoint must speak.
                Single host only; https:// required; API key is encrypted at rest and never
                shown again once saved.
            </p>
            <form method="post" action="/admin/hymns/save" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="remote_save">
                <div class="col-md-3 d-flex align-items-center">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="remoteEnabled" name="enabled" value="1" <?php echo $remoteEnabled === true ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="remoteEnabled">Enable remote lookup</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Host (allowlist)</label>
                    <input type="text" name="host" maxlength="255" value="<?php echo htmlspecialchars($remoteHost, ENT_QUOTES, 'UTF-8'); ?>" class="form-control form-control-sm" placeholder="hymns.example.org">
                </div>
                <div class="col-md-5">
                    <label class="form-label small">Base URL (must be https:// on the host above)</label>
                    <input type="text" name="baseUrl" maxlength="500" value="<?php echo htmlspecialchars($remoteBaseUrl, ENT_QUOTES, 'UTF-8'); ?>" class="form-control form-control-sm" placeholder="https://hymns.example.org/api/v1">
                </div>
                <div class="col-md-6">
                    <label class="form-label small">API key <?php echo $remoteHasKey === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                    <input type="password" name="apiKey" class="form-control form-control-sm" placeholder="<?php echo $remoteHasKey === true ? 'Leave blank to keep' : 'optional'; ?>" autocomplete="off">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Cache TTL (seconds)</label>
                    <input type="number" name="cacheTtl" min="60" value="<?php echo $remoteTtl; ?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Save</button>
                </div>
            </form>
            <form method="post" action="/admin/hymns/save" class="mt-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="remote_test">
                <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-plug me-1"></i>Test connection</button>
            </form>
        </div>
    </div>

    <!-- ═══════════════ Public Order of Service — site-level switch ═══════════════ -->
    <div class="card mb-3">
        <div class="card-header"><strong>Public Order of Service</strong></div>
        <div class="card-body">
            <p class="small text-muted">
                Site-level switch for the congregation-facing <code>/os/{token}</code> view.
                Off by default — even when on here, a plan is only public once its own editor
                enables sharing (see the Share panel on a service plan). Presenter names are
                shown; internal notes/AV cues are never included.
            </p>
            <form method="post" action="/admin/hymns/save" class="d-flex align-items-center gap-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="public_share_toggle">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="publicShareEnabled" name="enabled" value="1" <?php echo $publicShareEnabled === true ? 'checked' : ''; ?> onchange="this.form.submit()">
                    <label class="form-check-label" for="publicShareEnabled">Allow public Order-of-Service sharing on this site</label>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
