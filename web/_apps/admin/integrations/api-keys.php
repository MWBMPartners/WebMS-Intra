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

use Portal\Core\ApiKey;
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
    . '       k.isActive, k.createdAt, k.revokedAt, k.rotatedToID, u.fullName AS creatorName '
    . 'FROM tblApiKeys k LEFT JOIN tblUsers u ON u.userID = k.createdByID '
    . 'WHERE k.siteID = ? ORDER BY k.isActive DESC, k.createdAt DESC LIMIT 200'
);
$stmt->bind_param('i', $siteId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $keys[] = $r; }
$stmt->close();

// 🧮 Group the canonical ApiKey::SCOPES vocabulary into resource => [read, write]
//    pairs for the mint form's checkbox grid. Rendered from the constant so the
//    UI and the server-side validator (api-keys-save.php) can never drift apart.
$scopeGroups = [];
foreach (ApiKey::SCOPES as $scopeStr) {
    [$resource, $verb] = array_pad(explode(':', $scopeStr, 2), 2, '');
    $scopeGroups[$resource][$verb] = $scopeStr;
}
$knownScopeSet = array_flip(ApiKey::SCOPES);

// 🔍 Is a stored scope token part of the canonical vocabulary (including the
//    `*` and `{resource}:*` wildcard forms)? Anything else is legacy free text
//    that predates the checkbox UI — flagged read-only, never re-validated.
$isKnownScope = static function (string $token) use ($knownScopeSet, $scopeGroups): bool {
    if ($token === '*') {
        return true;
    }
    if (isset($knownScopeSet[$token]) === true) {
        return true;
    }
    if (str_ends_with($token, ':*') === true) {
        $base = substr($token, 0, -2);
        return array_key_exists($base, $scopeGroups) === true;
    }
    return false;
};

$graceOptions = [
    0  => 'Immediate (revoke now)',
    1  => '1 hour grace',
    24 => '24 hours grace',
    72 => '72 hours grace',
];

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
        Bearer tokens for the public REST API — see <code>/api/v1/{resource}</code> and its
        legacy <code>/api/{app}/{action}</code> aliases. Plaintext is shown ONCE at mint —
        copy it then. The portal stores only an SHA-256 hash. Scopes are enforced against the
        canonical <code>resource:read</code> / <code>resource:write</code> vocabulary below;
        rotation carries a grace window so in-flight callers can switch over before the old
        key stops working.
    </p>

    <details class="mb-4" <?php echo count($keys) === 0 ? 'open' : ''; ?>>
        <summary class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-plus me-1"></i>Mint a new key</summary>
        <form method="post" action="/admin/integrations/api-keys/save" class="row g-2 mt-2 p-3 bg-light rounded">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <div class="col-md-6">
                <label class="form-label small">Name <span class="text-danger">*</span></label>
                <input type="text" name="name" required maxlength="120" class="form-control form-control-sm" placeholder="e.g. Mobile app — production">
            </div>
            <div class="col-md-3">
                <label class="form-label small">Expires (optional)</label>
                <input type="date" name="expiresAt" class="form-control form-control-sm">
            </div>
            <div class="col-12">
                <label class="form-label small mb-1">Scopes</label>
                <div class="form-text small mb-2">Pick the resource:verb pairs this key may use. Leave every box unchecked to mint a scope-less key (fails every scope check until edited).</div>
                <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-2">
                    <?php foreach ($scopeGroups as $resource => $verbs): ?>
                        <div class="col">
                            <div class="border rounded p-2 h-100">
                                <div class="small fw-semibold text-capitalize mb-1"><?php echo htmlspecialchars(str_replace('-', ' ', $resource), ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php foreach (['read', 'write'] as $verb): ?>
                                    <?php if (isset($verbs[$verb]) === true): ?>
                                        <div class="form-check form-check-sm">
                                            <input class="form-check-input" type="checkbox" name="scopes[]"
                                                   value="<?php echo htmlspecialchars($verbs[$verb], ENT_QUOTES, 'UTF-8'); ?>"
                                                   id="scope-<?php echo htmlspecialchars($verbs[$verb], ENT_QUOTES, 'UTF-8'); ?>">
                                            <label class="form-check-label small" for="scope-<?php echo htmlspecialchars($verbs[$verb], ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo htmlspecialchars(ucfirst($verb), ENT_QUOTES, 'UTF-8'); ?>
                                            </label>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
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
            $isRotated = $k['rotatedToID'] !== null && $isActive === true;

            // 🔍 Split the stored CSV into recognised-vocabulary vs legacy free-text
            //    tokens — existing keys minted before this UI shipped may carry
            //    scopes that never went through the checkbox validator.
            $scopeTokens  = [];
            $legacyTokens = [];
            if (!empty($k['scopes'])) {
                foreach (array_map('trim', explode(',', (string) $k['scopes'])) as $tok) {
                    if ($tok === '') {
                        continue;
                    }
                    if ($isKnownScope($tok) === true) {
                        $scopeTokens[] = $tok;
                    } else {
                        $legacyTokens[] = $tok;
                    }
                }
            }
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
                    <?php if ($isRotated === true): ?>
                        <span class="badge bg-info text-dark ms-1" title="A replacement key has been minted; this key stays live only until its grace-window expiry.">Expiring (rotated)</span>
                    <?php endif; ?>
                    <code class="ms-2 small text-muted"><?php echo htmlspecialchars((string) $k['keyPrefix'], ENT_QUOTES, 'UTF-8'); ?>…</code>
                    <div class="small text-muted">
                        <?php if (count($scopeTokens) > 0): ?>
                            scopes: <code><?php echo htmlspecialchars(implode(', ', $scopeTokens), ENT_QUOTES, 'UTF-8'); ?></code>
                            &middot;
                        <?php endif; ?>
                        <?php if (count($legacyTokens) > 0): ?>
                            <span class="fst-italic" title="Stored before the scope checkbox UI shipped — read-only here; re-mint or edit via a future scope-edit endpoint to normalise.">legacy scopes: <code><?php echo htmlspecialchars(implode(', ', $legacyTokens), ENT_QUOTES, 'UTF-8'); ?></code></span>
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
                        <form method="post" action="/admin/integrations/api-keys/rotate" class="d-inline-flex align-items-center gap-1" data-confirm="Rotate this key? A new plaintext is shown ONCE; the existing key follows the selected grace window before it stops working.">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="keyID" value="<?php echo (int) $k['keyID']; ?>">
                            <select name="graceHours" class="form-select form-select-sm" style="width:auto;" title="Rotation grace period">
                                <?php foreach ($graceOptions as $hours => $label): ?>
                                    <option value="<?php echo (int) $hours; ?>"<?php echo $hours === 24 ? ' selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-outline-warning" title="Rotate"><i class="fa-solid fa-arrows-rotate"></i></button>
                        </form>
                        <form method="post" action="/admin/integrations/api-keys/revoke" class="d-inline" data-confirm="Revoke this key? Cannot be undone." data-confirm-destructive="true">
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
