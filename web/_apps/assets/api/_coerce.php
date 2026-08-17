<?php
// Path: _apps/assets/api/_coerce.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker API — Shared Field Coercion 🧮 (#406, Phase 2 Pass 4)
 * -----------------------------------------------------------------------------
 * NOT an API action — the leading underscore keeps this filename outside
 * ApiRouter::dispatch()'s `^[a-z0-9\-]+$` action-name pattern (mirrors
 * `_apps/giving/reconcile/_automatch.php`'s "helper, not a route"
 * convention), so it can only ever be reached via `require_once` from
 * `create.php` / `update.php` in this same directory — never directly by a
 * request.
 *
 * Mirrors `_apps/assets/save.php`'s `$_POST` → `AssetRegister` coercion
 * (same ENUM allow-lists, same site-scoped FK existence checks, same
 * `licenseKey` "blank means leave unchanged" convention) with TWO
 * deliberate differences from that file, both because this is a JSON API
 * consumed by machines rather than an HTML `<form>` submitted by a
 * browser:
 *
 *   1. Money fields (`purchaseCostPence`/`salvageValuePence`/
 *      `insuredValuePence`) are accepted as PENCE INTEGERS directly, not
 *      pounds-decimal strings — save.php's pounds→pence parsing exists
 *      solely to handle an HTML `<input type="number" step="0.01">`
 *      string; a JSON API talking to another system should use the same
 *      minor-units convention the database and house money rule (#266)
 *      already use, with no lossy string round-trip in between.
 *
 *   2. An invalid ENUM value (assetKind/conditionState/status/currency/
 *      depreciationMethod/labelSymbology) is a HARD 400 error, not a
 *      silent fallback to the default — save.php can silently coalesce
 *      because the value only ever came from ITS OWN `<select>`, so an
 *      invalid value can only mean a stale/tampered form; here it far more
 *      likely means the calling integration has a bug, and silently
 *      accepting the request while quietly substituting a different value
 *      than what was sent would hide that bug rather than surface it.
 *
 * PATCH-style partial updates: `AssetRegister::updateAsset()` was built for
 * save.php's "the form always posts every field" model — any key ABSENT
 * from its `$data` array is treated as NULL/0/false, not "leave
 * unchanged". `create.php` passing `$existing = null` sets that behaviour:
 * safe, because create.php builds a brand-new row from scratch. Only pass
 * a non-null `$existing` (the current `AssetRegister::get()` row) for an
 * UPDATE — every field this function returns is then either freshly
 * coerced from `$body` (when the caller's JSON key was present, even if
 * `null`) or copied straight through from `$existing` (when the key was
 * absent), so an update.php caller who only sends `{"status":"in-repair"}`
 * can never accidentally wipe every other column.
 *
 * @package   Portal\API\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/406
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/**
 * Build the `AssetRegister::createAsset()`/`updateAsset()` field array from
 * a decoded JSON request body, terminating with `ApiResponse::error()`
 * (400) on any hard validation failure — required-field/ENUM/URL-scheme
 * errors only; an unresolvable category/location/parent FK reference
 * silently falls back to NULL, exactly like save.php (a stale id isn't a
 * malformed request, just a reference that no longer exists).
 *
 * @param array<string, mixed>      $body     Decoded JSON body (ApiAuth::body()/requireWrite()).
 * @param array<string, mixed>|null $existing The current `AssetRegister::get()` row for an
 *                                             UPDATE, or null for a CREATE — see file header.
 *
 * @return array<string, mixed> Ready to hand straight to createAsset()/updateAsset().
 */
function assets_api_build_data(array $body, ?array $existing): array
{
    $db     = \Portal\Core\App::db();
    $siteId = \Portal\Core\Site::id();
    // 🔁 0 for create (nothing to self-reference against yet); the row's
    // own id for update (so parentAssetID can't point at itself).
    $selfAssetId = $existing !== null ? (int) ($existing['assetID'] ?? 0) : 0;

    $has = static fn (string $key): bool => array_key_exists($key, $body);
    // 📤 Raw existing column value (string|int|null, as mysqli returned it),
    // or null when there's no existing row (create) — callers below cast
    // to the right PHP type themselves.
    $was = static fn (string $key): mixed => $existing !== null ? ($existing[$key] ?? null) : null;

    // -------------------------------------------------------------------
    // 🧵 String field — present in body: trim + cap length ('' → NULL,
    // matching save.php's own `$str()`); absent: carry the existing value
    // through unchanged (update) or NULL (create).
    // -------------------------------------------------------------------
    $str = static function (string $key, int $maxLen) use ($body, $has, $was): ?string {
        if ($has($key) === false) {
            $v = $was($key);
            return $v !== null ? mb_substr((string) $v, 0, $maxLen) : null;
        }
        $v = trim((string) ($body[$key] ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $maxLen);
    };

    // -------------------------------------------------------------------
    // 🔢 Plain nullable integer (licenseSeats/usefulLifeMonths/…) — same
    // present/absent contract as $str() above.
    // -------------------------------------------------------------------
    $intOrNull = static function (string $key) use ($body, $has, $was): ?int {
        if ($has($key) === false) {
            $v = $was($key);
            return $v !== null ? (int) $v : null;
        }
        $v = $body[$key];
        if ($v === null || $v === '') {
            return null;
        }
        return (int) $v;
    };

    // -------------------------------------------------------------------
    // 💷 Pence integer (purchaseCostPence/salvageValuePence/
    // insuredValuePence) — see file header point 1. Rejects a negative or
    // non-numeric value with a 400 rather than silently clamping/dropping
    // it (money is exactly the kind of field a caller needs a clear error
    // on, not a silent substitution).
    // -------------------------------------------------------------------
    $penceOrNull = static function (string $key) use ($body, $has, $was): ?int {
        if ($has($key) === false) {
            $v = $was($key);
            return $v !== null ? (int) $v : null;
        }
        $v = $body[$key];
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v) === false || (float) $v < 0) {
            \Portal\Core\ApiResponse::error("$key must be a non-negative integer number of pence", 400);
        }
        return (int) $v;
    };

    // -------------------------------------------------------------------
    // 📅 Date (Y-m-d) — present: must parse as a real Y-m-d date or the
    // request 400s (an unparseable date is a caller bug, not a stale
    // reference); absent: carry through / NULL, same contract as above.
    // -------------------------------------------------------------------
    $dateOrNull = static function (string $key) use ($body, $has, $was): ?string {
        if ($has($key) === false) {
            $v = $was($key);
            if ($v === null) {
                return null;
            }
            // 🪞 mysqli DATE columns come back as 'Y-m-d' already.
            return mb_substr((string) $v, 0, 10);
        }
        $v = trim((string) ($body[$key] ?? ''));
        if ($v === '') {
            return null;
        }
        $d = \DateTime::createFromFormat('Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            \Portal\Core\ApiResponse::error("$key must be a valid date in Y-m-d format", 400);
        }
        return $v;
    };

    // -------------------------------------------------------------------
    // 📋 ENUM with a default — present: must be one of $allowed or 400
    // (file header point 2); absent: existing value (update) or
    // $default (create — simply omitted, letting createAsset()'s own
    // `?? $default` fallback apply).
    // -------------------------------------------------------------------
    $enumOrExisting = static function (string $key, array $allowed, string $default) use ($body, $has, $was, $existing): ?string {
        if ($has($key) === false) {
            return $existing !== null ? (string) $was($key) : null; // null ⇒ caller omits the array key entirely
        }
        $v = (string) $body[$key];
        if (in_array($v, $allowed, true) === false) {
            \Portal\Core\ApiResponse::error("$key must be one of: " . implode(', ', $allowed), 400);
        }
        return $v;
    };

    // -------------------------------------------------------------------
    // ✅ Boolean → 0/1 — present: real JSON boolean semantics (unlike
    // save.php's "checkbox present in $_POST at all" HTML convention);
    // absent: existing 0/1 (update) or null (create — createAsset()'s own
    // default applies: 0 for isConfidential, 1 for publicPageEnabled).
    // -------------------------------------------------------------------
    $boolOrExisting = static function (string $key) use ($body, $has, $was, $existing): ?int {
        if ($has($key) === false) {
            return $existing !== null ? (int) $was($key) : null;
        }
        return (bool) $body[$key] === true ? 1 : 0;
    };

    // -------------------------------------------------------------------
    // 🔗 Site-scoped FK existence check — mirrors save.php exactly: an
    // id that doesn't resolve on THIS site silently becomes NULL rather
    // than failing the whole request (a stale dropdown/reference, not a
    // malformed one).
    // -------------------------------------------------------------------
    $fkOrNull = static function (?int $candidate, string $table, string $pk, bool $excludeDeleted) use ($db, $siteId): ?int {
        if ($candidate === null || $candidate <= 0) {
            return null;
        }
        $sql = 'SELECT 1 FROM `' . $table . '` WHERE `' . $pk . '` = ? AND siteID = ?'
             . ($excludeDeleted === true ? ' AND isDeleted = 0' : '') . ' LIMIT 1';
        $chk = $db->prepare($sql);
        if ($chk === false) {
            return null;
        }
        $chk->bind_param('ii', $candidate, $siteId);
        $chk->execute();
        $found = $chk->get_result()->fetch_assoc() !== null;
        $chk->close();
        return $found === true ? $candidate : null;
    };

    // -------------------------------------------------------------------
    // 📋 name — required on CREATE; on UPDATE, present-but-blank still
    // 400s (never silently keep the old name for an explicit-but-empty
    // submission), absent keeps the existing name untouched.
    // -------------------------------------------------------------------
    if ($has('name') === true) {
        $name = trim((string) $body['name']);
        if ($name === '' || mb_strlen($name) > 255) {
            \Portal\Core\ApiResponse::error('name is required and must be ≤255 characters', 400);
        }
    } elseif ($existing !== null) {
        $name = (string) $was('name');
    } else {
        \Portal\Core\ApiResponse::error('name is required and must be ≤255 characters', 400);
    }

    // -------------------------------------------------------------------
    // 🔗 categoryID / locationID / parentAssetID
    // -------------------------------------------------------------------
    $categoryId = null;
    if ($has('categoryID') === true) {
        $categoryCandidate = $body['categoryID'] !== null ? (int) $body['categoryID'] : null;
        $categoryId        = $fkOrNull($categoryCandidate, 'tblAssetCategories', 'categoryID', false);
    } elseif ($existing !== null) {
        $existingCategoryId = $was('categoryID');
        $categoryId          = $existingCategoryId !== null ? (int) $existingCategoryId : null;
    }

    $locationId = null;
    if ($has('locationID') === true) {
        $locationCandidate = $body['locationID'] !== null ? (int) $body['locationID'] : null;
        $locationId        = $fkOrNull($locationCandidate, 'tblAssetLocations', 'locationID', false);
    } elseif ($existing !== null) {
        $existingLocationId = $was('locationID');
        $locationId          = $existingLocationId !== null ? (int) $existingLocationId : null;
    }

    $parentAssetId = null;
    if ($has('parentAssetID') === true) {
        $parentCandidate = $body['parentAssetID'] !== null ? (int) $body['parentAssetID'] : null;
        if ($parentCandidate !== null && $parentCandidate === $selfAssetId) {
            $parentAssetId = null; // 🔁 an asset can't be its own parent/bundle
        } else {
            $parentAssetId = $fkOrNull($parentCandidate, 'tblAssets', 'assetID', true);
        }
    } elseif ($existing !== null) {
        $existingParentAssetId = $was('parentAssetID');
        $parentAssetId          = $existingParentAssetId !== null ? (int) $existingParentAssetId : null;
    }

    // -------------------------------------------------------------------
    // 💷 currency — 3-letter ISO 4217 shape check, falls back to GBP/
    // existing (never a hard 400 — a shape-invalid currency is rare
    // enough, and low-stakes enough, to match save.php's silent-fallback
    // treatment rather than the stricter ENUM-list rule above).
    // -------------------------------------------------------------------
    if ($has('currency') === true) {
        $currency = strtoupper(trim((string) $body['currency']));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $currency = 'GBP';
        }
    } else {
        $currency = $existing !== null ? (string) $was('currency') : null; // null ⇒ createAsset() defaults to GBP
    }

    // -------------------------------------------------------------------
    // 🌐 accessUrl — http(s) only, mirrors save.php's rule exactly (a
    // malformed scheme DOES 400 here too, same as save.php's $fail()).
    // -------------------------------------------------------------------
    $accessUrl = $str('accessUrl', 500);
    if ($accessUrl !== null && $has('accessUrl') === true) {
        $scheme = parse_url($accessUrl, PHP_URL_SCHEME);
        if (in_array(strtolower((string) $scheme), ['http', 'https'], true) === false) {
            \Portal\Core\ApiResponse::error('accessUrl must start with http:// or https://', 400);
        }
    }

    // -------------------------------------------------------------------
    // 📦 Assemble — see AssetRegister::createAsset()/updateAsset()
    // docblocks for the full key list. Keys resolved to `null` above from
    // an ABSENT-on-create body key are safe to include as `null` (that's
    // exactly what an omitted key means to createAsset()'s own `?? default`
    // fallback), EXCEPT isConfidential/publicPageEnabled/labelSymbology/
    // assetKind/conditionState/status/currency/depreciationMethod which
    // must be OMITTED (not passed as null) on create so createAsset()'s
    // own default applies rather than a literal NULL landing in a
    // NOT-NULL column — enumOrExisting()/boolOrExisting() already return
    // PHP null for exactly that case, so array_filter() below strips them
    // (create only; every key is always populated on update).
    // -------------------------------------------------------------------
    $data = [
        'assetKind'          => $enumOrExisting('assetKind', \Portal\Core\AssetRegister::ASSET_KINDS, 'physical'),
        'name'               => $name,
        'description'        => $str('description', 65535),
        'categoryID'         => $categoryId,
        'locationID'         => $locationId,
        'manufacturer'       => $str('manufacturer', 150),
        'model'              => $str('model', 150),
        'serialNumber'       => $str('serialNumber', 150),
        'assetTagCode'       => $str('assetTagCode', 50),
        'features'           => $str('features', 65535),
        'conditionState'     => $enumOrExisting('conditionState', \Portal\Core\AssetRegister::CONDITION_STATES, 'good'),
        'status'             => $enumOrExisting('status', \Portal\Core\AssetRegister::ASSET_STATUSES, 'in-service'),
        'purchaseDate'       => $dateOrNull('purchaseDate'),
        'purchaseStore'      => $str('purchaseStore', 255),
        'purchaseCostPence'  => $penceOrNull('purchaseCostPence'),
        'currency'           => $currency,
        'warrantyExpiry'     => $dateOrNull('warrantyExpiry'),
        'warrantyDetails'    => $str('warrantyDetails', 500),
        // 🔐 licenseKey — ALWAYS present in $data (never conditional on
        // $has()), same as save.php: an absent/blank value means "leave
        // unchanged" on update / "no licence key" on create — see
        // AssetRegister::updateAsset()'s own docblock.
        'licenseKey'         => (string) ($body['licenseKey'] ?? ''),
        'licenseSeats'       => $intOrNull('licenseSeats'),
        'renewalDate'        => $dateOrNull('renewalDate'),
        'accessUrl'          => $accessUrl,
        'depreciationMethod' => $enumOrExisting('depreciationMethod', \Portal\Core\AssetRegister::DEPRECIATION_METHODS, 'none'),
        'usefulLifeMonths'   => $intOrNull('usefulLifeMonths'),
        'salvageValuePence'  => $penceOrNull('salvageValuePence'),
        'insurerName'           => $str('insurerName', 150),
        'insurancePolicyNumber' => $str('insurancePolicyNumber', 100),
        'insuredValuePence'     => $penceOrNull('insuredValuePence'),
        'insuranceRenewalDate'  => $dateOrNull('insuranceRenewalDate'),
        'isConfidential'     => $boolOrExisting('isConfidential'),
        'publicPageEnabled'  => $boolOrExisting('publicPageEnabled'),
        'labelSymbology'     => $enumOrExisting('labelSymbology', \Portal\Core\AssetRegister::LABEL_SYMBOLOGIES, 'qr'),
        'parentAssetID'      => $parentAssetId,
    ];

    // 🧹 On CREATE only, strip keys that resolved to PHP null purely
    // because the body omitted them (see the comment above) — letting
    // createAsset()'s own `?? default` fill them in rather than writing a
    // literal NULL into a NOT-NULL ENUM column. On UPDATE every one of
    // these keys is always populated (existing value at worst), so this
    // is a no-op there.
    if ($existing === null) {
        foreach (['assetKind', 'conditionState', 'status', 'currency', 'depreciationMethod', 'labelSymbology', 'isConfidential', 'publicPageEnabled'] as $defaultedKey) {
            if ($data[$defaultedKey] === null) {
                unset($data[$defaultedKey]);
            }
        }
    }

    return $data;
}
