<?php
// Path: _apps/admin/integrations/api-keys.php
/**
 * -----------------------------------------------------------------------------
 * Admin — API key management 🔑 (#323 Phase 1)
 * -----------------------------------------------------------------------------
 * List + mint UI for tblApiKeys. The plaintext token is shown ONCE here on
 * mint, via a flash message — never persisted, never queryable later.
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/323
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

$keys = [];
$stmt = $mysqli->prepare(
    'SELECT k.keyID, k.name, k.keyPrefix, k.scopes, k.expiresAt, k.lastUsedAt, k.lastUsedIP, '
    . '       k.isActive, k.createdAt, k.revokedAt, u.fullName AS creatorName '
    . 'FROM tblApiKeys k LEFT JOIN tblUsers u ON u.userID = k.createdByID '
    . 'WHERE k.siteID = ? ORDER BY k.isActive DESC, k.createdAt DESC LIMIT 200'
);
$stmt->bind_param('i', $siteId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $keys[] = $r; }
$stmt->close();

$pageTitle = 'API Keys';
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

// 🎟️ Flash for newly-minted plaintext — shown ONCE.
if (isset($_SESSION['api_key_minted']) === true) {
    $minted = $_SESSION['api_key_minted'];
    unset($_SESSION['api_key_minted']);
    echo '<div class="container py-2" style="max-width:880px;">';
    echo '<div class="alert alert-warning">';
    echo '<h2 class="h5"><i class="fa-solid fa-key me-1"></i>Copy your new API key NOW</h2>';
    echo '<p class="mb-2">This is the only time the full plaintext will be shown. The portal stores only a hashed copy.</p>';
    echo '<code class="d-block p-2 bg-light" style="font-family:monospace; word-break:break-all; user-select:all;">';
    echo htmlspecialchars((string) $minted['plaintext'], ENT_QUOTES, 'UTF-8');
    echo '</code>';
    echo '<p class="small mt-2 mb-0">Key #' . (int) $minted['keyID'] . ' &middot; prefix <code>' . htmlspecialchars((string) $minted['prefix'], ENT_QUOTES, 'UTF-8') . '</code></p>';
    echo '</div></div>';
}
?>
<div class="container py-3" style="max-width:880px;">
    <h1 class="h4 mb-2"><i class="fa-solid fa-key me-2 text-primary"></i>API Keys</h1>
    <p class="text-muted small">
        Bearer tokens for the public REST API. Plaintext is shown ONCE at mint —
        copy it then. The portal stores only an SHA-256 hash. Phase 1 ships the
        infrastructure; Phase 2 wires the write endpoints.
    </p>

    <details class="mb-4" <?php echo count($keys) === 0 ? 'open' : ''; ?>>
        <summary class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-plus me-1"></i>Mint a new key</summary>
        <form method="post" action="/admin/integrations/api-keys/save" class="row g-2 mt-2 p-3 bg-light rounded">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <div class="col-md-5">
                <label class="form-label small">Name <span class="text-danger">*</span></label>
                <input type="text" name="name" required maxlength="120" class="form-control form-control-sm" placeholder="e.g. Mobile app — production">
            </div>
            <div class="col-md-4">
                <label class="form-label small">Scopes (comma separated)</label>
                <input type="text" name="scopes" maxlength="500" class="form-control form-control-sm" placeholder="events:read, attendance:write">
                <div class="form-text small">Wildcard: <code>events:*</code> matches every events scope.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Expires (optional)</label>
                <input type="date" name="expiresAt" class="form-control form-control-sm">
            </div>
            <div class="col-12">
                <button class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>Mint key</button>
            </div>
        </form>
    </details>

    <?php if (count($keys) === 0): ?>
        <div class="alert alert-info small">No API keys minted yet.</div>
    <?php else: ?>
        <div class="portal-data-list">
        <?php foreach ($keys as $k):
            $isActive = (int) $k['isActive'] === 1;
            $isExpired = $k['expiresAt'] !== null && strtotime((string) $k['expiresAt']) < time();
        ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <strong><?php echo htmlspecialchars((string) $k['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if ($isActive === false): ?>
                        <span class="badge bg-danger ms-1">Revoked</span>
                    <?php elseif ($isExpired): ?>
                        <span class="badge bg-warning text-dark ms-1">Expired</span>
                    <?php else: ?>
                        <span class="badge bg-success ms-1">Active</span>
                    <?php endif; ?>
                    <code class="ms-2 small text-muted"><?php echo htmlspecialchars((string) $k['keyPrefix'], ENT_QUOTES, 'UTF-8'); ?>…</code>
                    <div class="small text-muted">
                        <?php if (!empty($k['scopes'])): ?>
                            scopes: <code><?php echo htmlspecialchars((string) $k['scopes'], ENT_QUOTES, 'UTF-8'); ?></code>
                            &middot;
                        <?php endif; ?>
                        <?php if (!empty($k['expiresAt'])): ?>
                            expires <?php echo htmlspecialchars(date('j M Y', strtotime((string) $k['expiresAt'])), ENT_QUOTES, 'UTF-8'); ?>
                            &middot;
                        <?php endif; ?>
                        created <?php echo htmlspecialchars(date('j M Y', strtotime((string) $k['createdAt'])), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (!empty($k['creatorName'])): ?>
                            by <?php echo htmlspecialchars((string) $k['creatorName'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                        <?php if (!empty($k['lastUsedAt'])): ?>
                            <br>last used <?php echo htmlspecialchars(date('j M, H:i', strtotime((string) $k['lastUsedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                            <?php if (!empty($k['lastUsedIP'])): ?> from <code><?php echo htmlspecialchars((string) $k['lastUsedIP'], ENT_QUOTES, 'UTF-8'); ?></code><?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($isActive === true): ?>
                    <div class="portal-data-row-aside">
                        <form method="post" action="/admin/integrations/api-keys/rotate" class="d-inline" onsubmit="return confirm('Rotate this key? The existing key becomes invalid and a new plaintext is shown ONCE.');">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="keyID" value="<?php echo (int) $k['keyID']; ?>">
                            <button class="btn btn-sm btn-outline-warning" title="Rotate"><i class="fa-solid fa-arrows-rotate"></i></button>
                        </form>
                        <form method="post" action="/admin/integrations/api-keys/revoke" class="d-inline" onsubmit="return confirm('Revoke this key? Cannot be undone.');">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="keyID" value="<?php echo (int) $k['keyID']; ?>">
                            <button class="btn btn-sm btn-outline-danger" title="Revoke"><i class="fa-solid fa-ban"></i></button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
