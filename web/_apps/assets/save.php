<?php
// Path: _apps/assets/save.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Save Asset 📦
 * -----------------------------------------------------------------------------
 * POST handler — creates or updates a tblAssets row via
 * Portal\Core\AssetRegister::createAsset() / updateAsset(). This is the ONE
 * place in the Asset Tracker app responsible for validating/coercing raw
 * `$_POST` strings into the correctly-typed values AssetRegister's methods
 * expect (int casts, ENUM allow-lists checked against AssetRegister's public
 * constants, pounds→pence money conversion, date format validation, FK
 * existence checks scoped to the current site). AssetRegister itself trusts
 * its caller completely and performs no re-validation — see that class's
 * createAsset()/updateAsset() docblocks.
 *
 * Manager-gated (admin OR asset_manager role) — mirrors every other
 * mutating Asset Tracker handler (#395's isResponsibleFor() ownership
 * bypass is deliberately NOT extended here: editing/deleting the asset
 * record itself is management-only, unlike attaching a resource, which an
 * owner-party may also do — see resource-save.php).
 *
 * ACTION DISPATCH (#401): this file's ONE additional action —
 * `action=regenerate-token` — rotates an asset's public lost-and-found
 * token via `AssetRegister::regeneratePublicToken()`. It's folded into
 * this existing route rather than registering a new `tblRoutes` row (per
 * #401's scope — no new routes/migrations), mirroring the
 * `categories.php`/`orgs.php` self-posting `action=`-dispatch convention
 * used elsewhere in this app. The dispatch check runs BEFORE the
 * create/update flow's own field coercion below (which expects a full
 * asset-edit form post, `name` included) so the small standalone
 * "Regenerate public token" form on item.php — which posts only
 * `assetID` + `action` — never falls through into that validation. Same
 * CSRF-first + manager-gate ordering as every other branch in this file.
 *
 * INSURANCE (#404 columns, first persisted this pass — #408):
 * insurerName/insurancePolicyNumber (plain `$str()` coercion, same as
 * every other free-text field here) and insuredValuePounds→
 * insuredValuePence/insuranceRenewalDate (the SAME `$pence()`/
 * `$dateOrNull()` helpers purchaseCostPounds/purchaseDate already use).
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/394
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/396
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/401
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/404
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/408
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Manager gate — admins or the asset_manager role only.
if (App::isAdmin() !== true && App::hasRole('asset_manager') !== true) {
    http_response_code(403);
    exit('Forbidden');
}

// 🔐 CSRF FIRST — before any side-effect, per house convention.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}

$db      = App::db();
$siteId  = Site::id();
$userId  = (int) ($_SESSION['user_id'] ?? 0);
$assetId = (int) ($_POST['assetID'] ?? 0);

// -----------------------------------------------------------------------------
// 🔁 Action dispatch (#401) — see file header. Every other posted `action`
// value (including the normal edit form, which never sends one) falls
// through unchanged to the create/update flow below.
// -----------------------------------------------------------------------------
if ((string) ($_POST['action'] ?? '') === 'regenerate-token') {
    $newToken = $assetId > 0 ? AssetRegister::regeneratePublicToken($assetId, $userId) : null;

    $_SESSION['flash_msg']  = $newToken !== null
        ? 'Public token regenerated — the old QR code/label link no longer works.'
        : 'Could not regenerate the public token — the asset may no longer exist.';
    $_SESSION['flash_type'] = $newToken !== null ? 'success' : 'danger';

    header('Location: /assets/item?id=' . $assetId);
    exit();
}

/**
 * Redirect back to the edit form (create) or item page (update) with a
 * flash message, then stop the request. Kept as a local closure so every
 * validation branch below can bail out identically.
 */
$fail = static function (string $msg) use ($assetId): never {
    $_SESSION['flash_msg']  = $msg;
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . ($assetId > 0 ? '/assets/edit?id=' . $assetId : '/assets/edit'));
    exit();
};

// -----------------------------------------------------------------------------
// 🧮 Small local coercion helpers. save.php is deliberately the ONE place
// that turns raw $_POST strings into the typed values AssetRegister expects
// — see file header.
// -----------------------------------------------------------------------------
$str = static function (string $key, int $maxLen): ?string {
    $v = trim((string) ($_POST[$key] ?? ''));
    if ($v === '') {
        return null;
    }
    return mb_substr($v, 0, $maxLen);
};
$intOrNull = static function (string $key): ?int {
    $v = trim((string) ($_POST[$key] ?? ''));
    return $v === '' ? null : (int) $v;
};
$pence = static function (string $key): ?int {
    $v = trim((string) ($_POST[$key] ?? ''));
    if ($v === '' || is_numeric($v) === false) {
        return null;
    }
    // 💷 Pounds → pence (house money convention, #266) — round to avoid
    // float-precision artefacts (e.g. 19.99 * 100 = 1998.9999999999998).
    return (int) round(((float) $v) * 100);
};
$dateOrNull = static function (string $key): ?string {
    $v = trim((string) ($_POST[$key] ?? ''));
    if ($v === '') {
        return null;
    }
    $d = \DateTime::createFromFormat('Y-m-d', $v);
    return ($d !== false && $d->format('Y-m-d') === $v) ? $v : null;
};
$enumOrDefault = static function (string $key, array $allowed, string $default): string {
    $v = (string) ($_POST[$key] ?? '');
    return in_array($v, $allowed, true) === true ? $v : $default;
};
$bool01 = static function (string $key): int {
    return isset($_POST[$key]) === true ? 1 : 0;
};

// -----------------------------------------------------------------------------
// 📋 Required field.
// -----------------------------------------------------------------------------
$name = trim((string) ($_POST['name'] ?? ''));
if ($name === '' || mb_strlen($name) > 255) {
    $fail('Asset name is required (max 255 characters).');
}

$assetKind = $enumOrDefault('assetKind', AssetRegister::ASSET_KINDS, 'physical');

// -----------------------------------------------------------------------------
// 🔗 FK fields — never trust a bare posted int; confirm the row exists AND
// belongs to this site before accepting it. Silently falls back to "not
// set" (NULL) rather than hard-failing the whole save over a stale
// dropdown value (e.g. a category deactivated in another tab).
// -----------------------------------------------------------------------------
$categoryId = $intOrNull('categoryID');
if ($categoryId !== null) {
    $chk = $db->prepare('SELECT 1 FROM tblAssetCategories WHERE categoryID = ? AND siteID = ? LIMIT 1');
    if ($chk !== false) {
        $chk->bind_param('ii', $categoryId, $siteId);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc() === null) {
            $categoryId = null;
        }
        $chk->close();
    }
}

$locationId = $intOrNull('locationID');
if ($locationId !== null) {
    $chk = $db->prepare('SELECT 1 FROM tblAssetLocations WHERE locationID = ? AND siteID = ? LIMIT 1');
    if ($chk !== false) {
        $chk->bind_param('ii', $locationId, $siteId);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc() === null) {
            $locationId = null;
        }
        $chk->close();
    }
}

$parentAssetId = $intOrNull('parentAssetID');
if ($parentAssetId !== null) {
    if ($parentAssetId === $assetId) {
        // 🔁 An asset can't be its own parent/bundle.
        $parentAssetId = null;
    } else {
        $chk = $db->prepare('SELECT 1 FROM tblAssets WHERE assetID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1');
        if ($chk !== false) {
            $chk->bind_param('ii', $parentAssetId, $siteId);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc() === null) {
                $parentAssetId = null;
            }
            $chk->close();
        }
    }
}

// -----------------------------------------------------------------------------
// 💷 Currency — 3-letter ISO 4217 shape check, falls back to GBP.
// -----------------------------------------------------------------------------
$currency = strtoupper(trim((string) ($_POST['currency'] ?? 'GBP')));
if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
    $currency = 'GBP';
}

// -----------------------------------------------------------------------------
// 🌐 accessUrl — http(s) only, mirrors resource-save.php's linkUrl rule.
// Rejects javascript:/data:/file: and any other non-web scheme rather than
// silently stripping it, so a manager gets a clear correction instead of a
// silently-dropped field.
// -----------------------------------------------------------------------------
$accessUrl = $str('accessUrl', 500);
if ($accessUrl !== null) {
    $scheme = parse_url($accessUrl, PHP_URL_SCHEME);
    if (in_array(strtolower((string) $scheme), ['http', 'https'], true) === false) {
        $fail('Access URL must start with http:// or https://.');
    }
}

// -----------------------------------------------------------------------------
// 📦 Assemble the coerced field set — see AssetRegister::createAsset() /
// updateAsset() docblocks for the expected keys.
// -----------------------------------------------------------------------------
$data = [
    'assetKind'          => $assetKind,
    'name'               => $name,
    'description'        => $str('description', 65535),
    'categoryID'         => $categoryId,
    'locationID'         => $locationId,
    'manufacturer'       => $str('manufacturer', 150),
    'model'              => $str('model', 150),
    'serialNumber'       => $str('serialNumber', 150),
    'assetTagCode'       => $str('assetTagCode', 50),
    'features'           => $str('features', 65535),
    'conditionState'     => $enumOrDefault('conditionState', AssetRegister::CONDITION_STATES, 'good'),
    'status'             => $enumOrDefault('status', AssetRegister::ASSET_STATUSES, 'in-service'),
    'purchaseDate'       => $dateOrNull('purchaseDate'),
    'purchaseStore'      => $str('purchaseStore', 255),
    'purchaseCostPence'  => $pence('purchaseCostPounds'),
    'currency'           => $currency,
    'warrantyExpiry'     => $dateOrNull('warrantyExpiry'),
    'warrantyDetails'    => $str('warrantyDetails', 500),
    // 🔐 licenseKey travels as PLAINTEXT here — AssetRegister::createAsset()/
    // updateAsset() encrypt it via encrypt_setting() before it ever reaches
    // the database. An empty string means "leave unchanged" on an edit (see
    // updateAsset()'s docblock) and "no licence key" on a create.
    'licenseKey'         => (string) ($_POST['licenseKey'] ?? ''),
    'licenseSeats'       => $intOrNull('licenseSeats'),
    'renewalDate'        => $dateOrNull('renewalDate'),
    'accessUrl'          => $accessUrl,
    'depreciationMethod' => $enumOrDefault('depreciationMethod', AssetRegister::DEPRECIATION_METHODS, 'none'),
    'usefulLifeMonths'   => $intOrNull('usefulLifeMonths'),
    'salvageValuePence'  => $pence('salvageValuePounds'),
    // 🛡️ Insurance (#404 columns, first persisted this pass — #408). Same
    // pounds→pence/date coercion helpers as every other money/date field
    // in this file — insuredValuePence follows the house pence convention
    // (#266), insuranceRenewalDate the same Y-m-d validation as
    // purchaseDate/warrantyExpiry above.
    'insurerName'           => $str('insurerName', 150),
    'insurancePolicyNumber' => $str('insurancePolicyNumber', 100),
    'insuredValuePence'     => $pence('insuredValuePounds'),
    'insuranceRenewalDate'  => $dateOrNull('insuranceRenewalDate'),
    'isConfidential'     => $bool01('isConfidential'),
    'publicPageEnabled'  => $bool01('publicPageEnabled'),
    // 🏷️ #404 — printed label barcode choice; validated against the same
    // allow-list AssetRegister::buildLabelSheets() checks, falling back to
    // 'qr' for anything not in it (tampered POST, stale form, etc).
    'labelSymbology'     => $enumOrDefault('labelSymbology', AssetRegister::LABEL_SYMBOLOGIES, 'qr'),
    'parentAssetID'      => $parentAssetId,
];

// -----------------------------------------------------------------------------
// 💾 Create or update.
// -----------------------------------------------------------------------------
if ($assetId > 0) {
    $ok = AssetRegister::updateAsset($assetId, $data, $userId);
    if ($ok === false) {
        $fail('Could not save the asset — it may no longer exist, or the asset tag code is already in use on this site.');
    }
    $_SESSION['flash_msg']  = 'Asset updated.';
    $_SESSION['flash_type'] = 'success';
} else {
    $newId = AssetRegister::createAsset($data, $userId);
    if ($newId <= 0) {
        $fail('Could not create the asset — the asset tag code may already be in use on this site.');
    }
    $assetId = $newId;
    $_SESSION['flash_msg']  = 'Asset created.';
    $_SESSION['flash_type'] = 'success';
}

// -----------------------------------------------------------------------------
// 📜 Ownership terms (#396) — a SEPARATE write path from the field set
// above, via AssetRegister::updateOwnershipTerms() (its own audit entry,
// entityType 'asset'). edit.php offers this field for convenience
// alongside the rest of the asset record, but the underlying mutation is
// the SAME method the Owners panel's "set-terms" quick-edit
// (owners-save.php) calls — one choke point regardless of which screen
// triggered the change. Always called (even with an empty string, which
// clears the column) since the textarea is always present in the posted
// form, whether or not the manager touched it.
// -----------------------------------------------------------------------------
AssetRegister::updateOwnershipTerms($assetId, (string) ($_POST['ownershipTerms'] ?? ''), $userId);

header('Location: /assets/item?id=' . $assetId);
exit();
