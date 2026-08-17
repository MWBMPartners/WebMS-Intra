<?php
// Path: _apps/assets/import.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Bulk CSV Import 📦⬆️ (#407, Phase 2 Pass 4)
 * -----------------------------------------------------------------------------
 * Four-action, one-endpoint upload flow — Post/Redirect/Get throughout, same
 * shape as `_apps/giving/reconcile/import.php` (#299 sub-feature 3), the
 * house model for this pattern:
 *
 *   action=upload  — multipart POST (csv_file). Reads raw bytes (1 MB / 2000
 *                    row caps), normalises encoding, sniffs the delimiter,
 *                    parses the header row, and attempts header-NAME
 *                    auto-mapping against the SAME column names
 *                    `_apps/assets/index.php`'s own `?export=csv` produces —
 *                    a round-trip export→import always auto-maps cleanly.
 *   action=map     — the manager's column choices + the opt-in "auto-create
 *                    unknown categories/locations" checkbox.
 *   action=confirm — re-runs the SAME dry-run parse fresh (never trusts a
 *                    possibly-stale stored preview — see below), blocks the
 *                    whole commit while any row is still `invalid`, then
 *                    creates every `create`-verdict row inside ONE
 *                    transaction (a genuine mid-batch DB failure rolls
 *                    everything back; an individual row's OWN create
 *                    failing — e.g. a same-batch tag collision the per-row
 *                    dry-run check didn't foresee — is counted and skipped,
 *                    never rolls back rows already written, since assets
 *                    are independent records with no cross-row constraint).
 *   action=cancel  — discards the in-progress upload.
 *
 * The uploaded file is NEVER written to disk — read once via
 * `file_get_contents()`, held only in `$_SESSION`, parsed from an in-memory
 * `php://temp` stream (same convention as giving's importer).
 *
 * DRY-RUN IS RE-DERIVED ON EVERY VIEW, NOT STORED — unlike giving's
 * importer (which parses once at upload/map time and caches the parsed
 * rows in session), this one re-runs `assets_import_parse_csv()` fresh on
 * every GET (preview) AND at `action=confirm`. Reasons: (1) duplicate
 * detection (`AssetRegister::findByTagOrSerial()`) depends on the LIVE
 * database, which can change between an admin opening the preview and
 * clicking Confirm (another admin creating a colliding tag code, e.g.);
 * (2) the auto-create-refs checkbox can toggle between page loads, which
 * changes category/location resolution; re-deriving avoids ever showing —
 * or worse, committing — a preview that's gone stale. The cost is a few
 * extra site-scoped SELECTs per page view, acceptable for an admin-only,
 * low-traffic screen.
 *
 * COLUMN ALLOW-LIST — importable fields are EXACTLY the columns
 * `index.php`'s CSV export produces (Name/Kind/Category/Location/
 * Manufacturer/Model/Serial Number/Asset Tag/Condition/Status/Purchase
 * Date/Purchase Cost), so an export→edit→re-import round-trip is lossless.
 * `licenseKey`, `publicToken`, every internal id, and `isConfidential` are
 * NEVER importable — new rows always default to non-confidential
 * (`AssetRegister::createAsset()`'s own default), and the confidentiality/
 * licence-key/public-page flags can only be set afterwards through the
 * normal edit screen or the REST API (#406).
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/407
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 🔐 Session + manager gate — mirrors every other mutating Asset Tracker
// screen (save.php, categories.php, …): admin OR the asset_manager role.
Auth::ensureSession();
Auth::requireLogin();
$canManage = App::isAdmin() === true || App::hasRole('asset_manager') === true;
if ($canManage === false) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$csrf   = Auth::csrfToken();

const ASSETS_IMPORT_MAX_BYTES   = 1048576; // 1 MB
const ASSETS_IMPORT_MAX_ROWS    = 2000;
const ASSETS_IMPORT_SESSION_KEY = 'assetsImportUpload';

// -----------------------------------------------------------------------------
// 🧰 Helper functions (import-only — nothing here is registered in
// tblRoutes; these are plain top-level functions local to this request).
// -----------------------------------------------------------------------------

/** Normalise a header cell for alias matching (house idiom, lowercase). */
function assets_import_normalise_header(string $h): string
{
    return strtolower(trim(str_replace(["\xEF\xBB\xBF", '"'], '', $h)));
}

/** Clean a header cell for DISPLAY (no lowercasing). */
function assets_import_display_header(string $h): string
{
    return trim(str_replace(["\xEF\xBB\xBF", '"'], '', $h));
}

/**
 * The importable field allow-list — keys match `AssetRegister::createAsset()`'s
 * own field names (or, for the two lookups, a `…Name` pseudo-field resolved
 * to an id below) — deliberately the EXACT set `index.php`'s `?export=csv`
 * produces, in the SAME order, so a round-trip always auto-maps. `aliases`
 * are normalised (lowercase) header names tried in order; the FIRST one is
 * always the literal export column name.
 *
 * @return array<string, array{label: string, required: bool, aliases: list<string>}>
 */
function assets_import_field_defs(): array
{
    return [
        'name'               => ['label' => 'Name',                 'required' => true,  'aliases' => ['name']],
        'assetKind'          => ['label' => 'Kind',                  'required' => false, 'aliases' => ['kind', 'asset kind', 'type']],
        'categoryName'       => ['label' => 'Category',              'required' => false, 'aliases' => ['category']],
        'locationName'       => ['label' => 'Location',              'required' => false, 'aliases' => ['location']],
        'manufacturer'       => ['label' => 'Manufacturer',          'required' => false, 'aliases' => ['manufacturer', 'make']],
        'model'              => ['label' => 'Model',                 'required' => false, 'aliases' => ['model']],
        'serialNumber'       => ['label' => 'Serial Number',         'required' => false, 'aliases' => ['serial number', 'serial', 'serial no', 's/n']],
        'assetTagCode'       => ['label' => 'Asset Tag',             'required' => false, 'aliases' => ['asset tag', 'tag', 'asset tag code']],
        'conditionState'     => ['label' => 'Condition',             'required' => false, 'aliases' => ['condition']],
        'status'             => ['label' => 'Status',                'required' => false, 'aliases' => ['status']],
        'purchaseDate'       => ['label' => 'Purchase Date',         'required' => false, 'aliases' => ['purchase date', 'date purchased']],
        'purchaseCostPounds' => ['label' => 'Purchase Cost (GBP)',   'required' => false, 'aliases' => ['purchase cost (gbp)', 'purchase cost', 'cost', 'purchase price']],
    ];
}

/**
 * Attempt to auto-map every field from normalised headers — first alias hit
 * wins per field.
 *
 * @param list<string> $normHeaders
 *
 * @return array<string, int|null>
 */
function assets_import_auto_map(array $normHeaders): array
{
    $mapping = [];
    foreach (assets_import_field_defs() as $key => $def) {
        $mapping[$key] = null;
        foreach ($def['aliases'] as $alias) {
            $idx = array_search($alias, $normHeaders, true);
            if ($idx !== false) {
                $mapping[$key] = (int) $idx;
                break;
            }
        }
    }
    return $mapping;
}

/** Count data rows (post-header) in raw CSV text — cheap pass for the row cap. */
function assets_import_count_data_rows(string $raw, string $delimiter): int
{
    $fh = fopen('php://temp', 'r+');
    if ($fh === false) {
        return 0;
    }
    fwrite($fh, $raw);
    rewind($fh);
    fgetcsv($fh, 0, $delimiter);
    $count = 0;
    while (fgetcsv($fh, 0, $delimiter) !== false) {
        $count++;
    }
    fclose($fh);
    return $count;
}

/** Read one mapped cell, trimmed; '' when the column isn't mapped or the row is ragged. */
function assets_import_cell(array $cells, ?int $idx): string
{
    if ($idx === null || array_key_exists($idx, $cells) === false) {
        return '';
    }
    return trim((string) $cells[$idx]);
}

/**
 * Validate + coerce one CSV data row into an `AssetRegister::createAsset()`
 * field array, or flag it `invalid` with a human-readable reason. Mirrors
 * `_apps/assets/save.php` (same ENUM allow-lists) with the CSV-appropriate
 * adaptations: category/location are matched BY NAME against this site's
 * existing rows (`$categoryMap`/`$locationMap`, both `[lowercase name =>
 * id]`), and money is pounds-decimal text (matching `index.php`'s own
 * export format) rather than save.php's `$_POST` convention.
 *
 * Does NOT check for duplicate tag/serial numbers — that's the caller's
 * job (`assets_import_parse_csv()`), since it needs cross-row + DB context
 * this function doesn't have.
 *
 * @param list<string|null>              $cells
 * @param array<string, int|null>        $mapping
 * @param array<string, int>             $categoryMap [lowercase categoryName => categoryID]
 * @param array<string, int>             $locationMap [lowercase locationName => locationID]
 *
 * @return array{skip: bool}|array{
 *     skip: bool, verdict: string, reason: ?string, notes: list<string>,
 *     data: ?array<string, mixed>, display: array<string, string>,
 *     assetTagCode: ?string, serialNumber: ?string,
 *     categoryNameRaw: string, locationNameRaw: string
 * }
 */
function assets_import_build_row(array $cells, array $mapping, int $rowNum, array $categoryMap, array $locationMap, bool $autoCreateRefs): array
{
    // 🈳 Genuinely blank physical line — skip silently, not counted.
    if (count(array_filter($cells, static fn ($v): bool => trim((string) $v) !== '')) === 0) {
        return ['skip' => true];
    }

    $get = static fn (string $key): string => assets_import_cell($cells, $mapping[$key] ?? null);

    $errors = [];
    $notes  = [];

    // 📋 name — required.
    $name = $get('name');
    if ($name === '') {
        $errors[] = 'Name is required.';
    } elseif (mb_strlen($name) > 255) {
        $errors[] = 'Name exceeds 255 characters.';
    }

    // 📦 assetKind
    $kindRaw   = $get('assetKind');
    $assetKind = 'physical';
    if ($kindRaw !== '') {
        $norm = strtolower($kindRaw);
        if (in_array($norm, AssetRegister::ASSET_KINDS, true) === false) {
            $errors[] = 'Kind "' . $kindRaw . '" must be Physical or Digital.';
        } else {
            $assetKind = $norm;
        }
    }

    // 🏷️ category / 📍 location — resolved BY NAME; an unresolved name is
    // a NOTE (still creatable), never a hard error — see file header.
    $categoryRaw = $get('categoryName');
    $categoryId  = null;
    if ($categoryRaw !== '') {
        $key = strtolower($categoryRaw);
        if (isset($categoryMap[$key]) === true) {
            $categoryId = $categoryMap[$key];
        } elseif ($autoCreateRefs === true) {
            $notes[] = 'Category "' . $categoryRaw . '" will be created.';
        } else {
            $notes[] = 'Category "' . $categoryRaw . '" not found — will be left blank.';
        }
    }
    $locationRaw = $get('locationName');
    $locationId  = null;
    if ($locationRaw !== '') {
        $key = strtolower($locationRaw);
        if (isset($locationMap[$key]) === true) {
            $locationId = $locationMap[$key];
        } elseif ($autoCreateRefs === true) {
            $notes[] = 'Location "' . $locationRaw . '" will be created.';
        } else {
            $notes[] = 'Location "' . $locationRaw . '" not found — will be left blank.';
        }
    }

    // 🧵 Plain text fields — truncated (not fatal), matching save.php's
    // own $str() column caps.
    $manufacturer = mb_substr($get('manufacturer'), 0, 150);
    $manufacturer = $manufacturer !== '' ? $manufacturer : null;
    $model        = mb_substr($get('model'), 0, 150);
    $model        = $model !== '' ? $model : null;
    $serialNumber = mb_substr($get('serialNumber'), 0, 150);
    $serialNumber = $serialNumber !== '' ? $serialNumber : null;
    $assetTagCode = mb_substr($get('assetTagCode'), 0, 50);
    $assetTagCode = $assetTagCode !== '' ? $assetTagCode : null;

    // 🩺 conditionState — export writes "Good"/"In Repair"-style Title
    // Case; reverse via lowercase + space→hyphen.
    $conditionRaw   = $get('conditionState');
    $conditionState = 'good';
    if ($conditionRaw !== '') {
        $norm = strtolower(str_replace(' ', '-', $conditionRaw));
        if (in_array($norm, AssetRegister::CONDITION_STATES, true) === false) {
            $errors[] = 'Condition "' . $conditionRaw . '" is not recognised.';
        } else {
            $conditionState = $norm;
        }
    }

    // 🚦 status — same Title-Case reversal as condition.
    $statusRaw = $get('status');
    $status    = 'in-service';
    if ($statusRaw !== '') {
        $norm = strtolower(str_replace(' ', '-', $statusRaw));
        if (in_array($norm, AssetRegister::ASSET_STATUSES, true) === false) {
            $errors[] = 'Status "' . $statusRaw . '" is not recognised.';
        } else {
            $status = $norm;
        }
    }

    // 📅 purchaseDate — strictly Y-m-d (matches the export format exactly;
    // no US/UK slash-date guessing, which would be ambiguous and unsafe).
    $dateRaw      = $get('purchaseDate');
    $purchaseDate = null;
    if ($dateRaw !== '') {
        $d = \DateTime::createFromFormat('Y-m-d', $dateRaw);
        if ($d === false || $d->format('Y-m-d') !== $dateRaw) {
            $errors[] = 'Purchase Date "' . $dateRaw . '" must be in YYYY-MM-DD format.';
        } else {
            $purchaseDate = $dateRaw;
        }
    }

    // 💷 purchaseCostPounds → purchaseCostPence — strips currency symbols/
    // thousands separators, then requires a non-negative number.
    $costRaw           = $get('purchaseCostPounds');
    $purchaseCostPence = null;
    if ($costRaw !== '') {
        $cleaned = str_replace(['£', '$', '€', ',', ' '], '', $costRaw);
        if (is_numeric($cleaned) === false || (float) $cleaned < 0) {
            $errors[] = 'Purchase Cost "' . $costRaw . '" is not a valid non-negative amount.';
        } else {
            $purchaseCostPence = (int) round(((float) $cleaned) * 100);
        }
    }

    $display = [
        'row'           => (string) $rowNum,
        'name'          => $name,
        'kind'          => ucfirst($assetKind),
        'category'      => $categoryRaw,
        'location'      => $locationRaw,
        'assetTagCode'  => (string) $assetTagCode,
        'serialNumber'  => (string) $serialNumber,
        'condition'     => ucwords(str_replace('-', ' ', $conditionState)),
        'status'        => ucwords(str_replace('-', ' ', $status)),
        'purchaseDate'  => (string) $purchaseDate,
        'purchaseCost'  => $purchaseCostPence !== null ? number_format($purchaseCostPence / 100, 2) : '',
    ];

    if (count($errors) > 0) {
        return [
            'skip'            => false,
            'verdict'         => 'invalid',
            'reason'          => implode(' ', $errors),
            'notes'           => $notes,
            'data'            => null,
            'display'         => $display,
            'assetTagCode'    => $assetTagCode,
            'serialNumber'    => $serialNumber,
            'categoryNameRaw' => $categoryRaw,
            'locationNameRaw' => $locationRaw,
        ];
    }

    $data = [
        'assetKind'         => $assetKind,
        'name'              => $name,
        'categoryID'        => $categoryId,
        'locationID'        => $locationId,
        'manufacturer'      => $manufacturer,
        'model'             => $model,
        'serialNumber'      => $serialNumber,
        'assetTagCode'      => $assetTagCode,
        'conditionState'    => $conditionState,
        'status'            => $status,
        'purchaseDate'      => $purchaseDate,
        'purchaseCostPence' => $purchaseCostPence,
        // 🔒 NEVER importable via bulk CSV — every new row defaults to
        // non-confidential (file header "COLUMN ALLOW-LIST" note).
        'isConfidential'    => 0,
    ];

    return [
        'skip'            => false,
        'verdict'         => 'create',
        'reason'          => null,
        'notes'           => $notes,
        'data'            => $data,
        'display'         => $display,
        'assetTagCode'    => $assetTagCode,
        'serialNumber'    => $serialNumber,
        'categoryNameRaw' => $categoryRaw,
        'locationNameRaw' => $locationRaw,
    ];
}

/**
 * Parse every data row of the raw CSV text into dry-run row results —
 * builds the site's category/location name maps ONCE, then flags
 * duplicate `assetTagCode`/`serialNumber` values both against the LIVE
 * database (`AssetRegister::findByTagOrSerial()`) and against EARLIER rows
 * in this same file (a fresh tag/serial repeated twice in one upload).
 *
 * Makes ZERO writes — read-only throughout (security musts).
 *
 * @param array<string, int|null> $mapping
 *
 * @return array{rows: list<array<string, mixed>>, skippedBlank: int}
 */
function assets_import_parse_csv(string $raw, array $mapping, string $delimiter, int $siteId, bool $autoCreateRefs): array
{
    $categoryMap = [];
    foreach (AssetRegister::listCategories($siteId) as $c) {
        $categoryMap[strtolower(trim((string) $c['categoryName']))] = (int) $c['categoryID'];
    }
    $locationMap = [];
    foreach (AssetRegister::listLocations($siteId) as $l) {
        $locationMap[strtolower(trim((string) $l['locationName']))] = (int) $l['locationID'];
    }

    $fh = fopen('php://temp', 'r+');
    if ($fh === false) {
        return ['rows' => [], 'skippedBlank' => 0];
    }
    fwrite($fh, $raw);
    rewind($fh);
    fgetcsv($fh, 0, $delimiter); // header — already consumed to build $mapping

    $rows         = [];
    $skippedBlank = 0;
    $rowNum       = 1;
    $seenTags     = [];
    $seenSerials  = [];

    while (($cells = fgetcsv($fh, 0, $delimiter)) !== false) {
        $rowNum++;
        $built = assets_import_build_row($cells, $mapping, $rowNum, $categoryMap, $locationMap, $autoCreateRefs);
        if ($built['skip'] === true) {
            $skippedBlank++;
            continue;
        }

        // 🔁 Duplicate detection — only meaningful for an otherwise-valid
        // row (an invalid row's tag/serial isn't trustworthy input).
        if ($built['verdict'] !== 'invalid') {
            $tag    = (string) ($built['assetTagCode'] ?? '');
            $serial = (string) ($built['serialNumber'] ?? '');

            $intraDupOfRow = null;
            if ($tag !== '' && isset($seenTags[$tag]) === true) {
                $intraDupOfRow = $seenTags[$tag];
            } elseif ($serial !== '' && isset($seenSerials[$serial]) === true) {
                $intraDupOfRow = $seenSerials[$serial];
            }

            if ($intraDupOfRow !== null) {
                $built['verdict'] = 'duplicate';
                $built['reason']  = 'Duplicate of row ' . $intraDupOfRow . ' within this file.';
            } else {
                $existingMatch = AssetRegister::findByTagOrSerial($siteId, $tag !== '' ? $tag : null, $serial !== '' ? $serial : null);
                if ($existingMatch !== null) {
                    $built['verdict'] = 'duplicate';
                    $built['reason']  = 'Matches existing asset #' . (int) $existingMatch['assetID'] . ' (' . (string) $existingMatch['name'] . ').';
                }
            }

            if ($intraDupOfRow === null) {
                if ($tag !== '') {
                    $seenTags[$tag] = $rowNum;
                }
                if ($serial !== '') {
                    $seenSerials[$serial] = $rowNum;
                }
            }
        }

        $rows[] = $built;
    }
    fclose($fh);

    return ['rows' => $rows, 'skippedBlank' => $skippedBlank];
}

/**
 * Resolve a category/location NAME to an id, creating it (once per name per
 * import run, via the in/out `$cache`) when `$autoCreateRefs` allowed it at
 * the dry-run stage. Only ever called from `action=confirm`, never from the
 * read-only dry-run.
 *
 * @param array<string, int|null> $cache [lowercase name => id] — accumulates across the whole confirm loop.
 */
function assets_import_resolve_ref_cached(array &$cache, string $rawName, string $type, int $siteId, int $actorUserId): ?int
{
    $key = strtolower($rawName);
    if (array_key_exists($key, $cache) === true) {
        return $cache[$key];
    }
    $newId = $type === 'category'
        ? AssetRegister::saveCategory($siteId, 0, ['categoryName' => $rawName], $actorUserId)
        : AssetRegister::saveLocation($siteId, 0, ['locationName' => $rawName], $actorUserId);
    $cache[$key] = $newId > 0 ? $newId : null;
    return $cache[$key];
}

/** Redirect back to the import page, optionally forcing the mapping view. */
function assetsImportRedirect(bool $adjust = false): void
{
    header('Location: /assets/import' . ($adjust === true ? '?adjust=1' : ''));
    exit();
}

// -----------------------------------------------------------------------------
// 📨 POST actions — Post/Redirect/Get throughout. CSRF checked FIRST, before
// any side-effect, on every single one (security musts).
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        http_response_code(400);
        exit('Bad request');
    }

    // -------------------------------------------------------------------
    // 📥 action=upload
    // -------------------------------------------------------------------
    if ($action === 'upload') {
        unset($_SESSION[ASSETS_IMPORT_SESSION_KEY]);

        $file = $_FILES['csv_file'] ?? null;
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_msg']  = 'File upload failed — please choose a CSV file and try again.';
            $_SESSION['flash_type'] = 'danger';
            assetsImportRedirect();
        }
        if ((int) $file['size'] > ASSETS_IMPORT_MAX_BYTES) {
            $_SESSION['flash_msg']  = 'File too large (max 1 MB).';
            $_SESSION['flash_type'] = 'danger';
            assetsImportRedirect();
        }
        $filename = basename((string) $file['name']);
        if (str_ends_with(strtolower($filename), '.csv') === false) {
            $_SESSION['flash_msg']  = 'Only .csv files are accepted.';
            $_SESSION['flash_type'] = 'danger';
            assetsImportRedirect();
        }

        // 📥 Read once into memory — NEVER written to disk ourselves (the
        // PHP upload tmp file is removed automatically at request end).
        $raw = file_get_contents((string) $file['tmp_name']);
        if ($raw === false || $raw === '') {
            $_SESSION['flash_msg']  = 'The uploaded file was empty or unreadable.';
            $_SESSION['flash_type'] = 'danger';
            assetsImportRedirect();
        }

        if (str_starts_with($raw, "\xEF\xBB\xBF") === true) {
            $raw = substr($raw, 3);
        }
        if (mb_check_encoding($raw, 'UTF-8') === false) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        $firstLineEnd = strpos($raw, "\n");
        $firstLine    = $firstLineEnd !== false ? substr($raw, 0, $firstLineEnd) : $raw;
        $delimiter    = (strpos($firstLine, ',') === false && strpos($firstLine, ';') !== false) ? ';' : ',';

        if (assets_import_count_data_rows($raw, $delimiter) > ASSETS_IMPORT_MAX_ROWS) {
            $_SESSION['flash_msg']  = 'This file has too many rows (max ' . ASSETS_IMPORT_MAX_ROWS . ') — split it into smaller files.';
            $_SESSION['flash_type'] = 'danger';
            assetsImportRedirect();
        }

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $headerRow = fgetcsv($fh, 0, $delimiter);
        fclose($fh);
        if ($headerRow === false || count($headerRow) === 0) {
            $_SESSION['flash_msg']  = 'Could not read a header row from this file.';
            $_SESSION['flash_type'] = 'danger';
            assetsImportRedirect();
        }
        $headersDisplay = array_map(static fn ($h): string => assets_import_display_header((string) $h), $headerRow);
        $headersNorm    = array_map(static fn ($h): string => assets_import_normalise_header((string) $h), $headerRow);

        $upload = [
            'siteID'         => $siteId,
            'filename'       => mb_substr($filename, 0, 255),
            'delimiter'      => $delimiter,
            'rawText'        => $raw,
            'headersDisplay' => $headersDisplay,
            'mapping'        => assets_import_auto_map($headersNorm),
            'autoCreateRefs' => false,
        ];
        $_SESSION[ASSETS_IMPORT_SESSION_KEY] = $upload;

        // 🗺️ Straight to preview only when the one REQUIRED field (Name)
        // auto-mapped; otherwise force the mapping screen.
        assetsImportRedirect($upload['mapping']['name'] === null);
    }

    // -------------------------------------------------------------------
    // 🗂️ action=map — manager's column choices + auto-create-refs opt-in
    // -------------------------------------------------------------------
    if ($action === 'map') {
        $upload = $_SESSION[ASSETS_IMPORT_SESSION_KEY] ?? null;
        if ($upload === null || (int) $upload['siteID'] !== $siteId) {
            unset($_SESSION[ASSETS_IMPORT_SESSION_KEY]);
            $_SESSION['flash_msg']  = 'Upload session expired or the active site changed — please re-upload.';
            $_SESSION['flash_type'] = 'danger';
            assetsImportRedirect();
        }

        $mapping = [];
        foreach (array_keys(assets_import_field_defs()) as $key) {
            $raw = (string) ($_POST['map_' . $key] ?? '');
            $mapping[$key] = ($raw !== '' && ctype_digit($raw) === true) ? (int) $raw : null;
        }
        $upload['mapping']        = $mapping;
        // ✅ Real HTML checkbox semantics — present in $_POST at all ⇒ checked.
        $upload['autoCreateRefs'] = isset($_POST['autoCreateRefs']) === true;
        $_SESSION[ASSETS_IMPORT_SESSION_KEY] = $upload;

        if ($mapping['name'] === null) {
            $_SESSION['flash_msg']  = 'Choose the column that contains the asset Name — it is required.';
            $_SESSION['flash_type'] = 'danger';
            assetsImportRedirect(true);
        }

        assetsImportRedirect();
    }

    // -------------------------------------------------------------------
    // ✅ action=confirm — re-derive + commit
    // -------------------------------------------------------------------
    if ($action === 'confirm') {
        $upload = $_SESSION[ASSETS_IMPORT_SESSION_KEY] ?? null;
        if ($upload === null || (int) $upload['siteID'] !== $siteId || $upload['mapping']['name'] === null) {
            unset($_SESSION[ASSETS_IMPORT_SESSION_KEY]);
            $_SESSION['flash_msg']  = 'Upload session expired or the active site changed — please re-upload.';
            $_SESSION['flash_type'] = 'danger';
            assetsImportRedirect();
        }

        $autoCreateRefs = (bool) $upload['autoCreateRefs'];
        $parsed = assets_import_parse_csv((string) $upload['rawText'], (array) $upload['mapping'], (string) $upload['delimiter'], $siteId, $autoCreateRefs);
        $rows   = $parsed['rows'];

        // 🚫 Commit BLOCKED while any row is still invalid — re-checked
        // here against a FRESH parse (see file header) rather than trusting
        // whatever the preview last showed, so a race can never let a bad
        // batch through.
        $invalidCount = count(array_filter($rows, static fn (array $r): bool => $r['verdict'] === 'invalid'));
        if ($invalidCount > 0) {
            $_SESSION['flash_msg']  = 'Cannot import — ' . $invalidCount . ' row(s) are still invalid. Fix your CSV and re-upload.';
            $_SESSION['flash_type'] = 'danger';
            assetsImportRedirect();
        }

        $createRows     = array_values(array_filter($rows, static fn (array $r): bool => $r['verdict'] === 'create'));
        $duplicateCount = count($rows) - count($createRows);
        $batchId        = bin2hex(random_bytes(8)); // tags every created asset's audit meta

        $refCache = ['category' => [], 'location' => []];
        $created  = 0;
        $failed   = 0;

        App::beginTransaction();
        try {
            foreach ($createRows as $row) {
                $data = (array) $row['data'];

                // 🏷️📍 Auto-create unresolved category/location names —
                // opt-in, off by default (file header). Cached per-name so
                // 50 rows sharing a brand-new category create it ONCE.
                if ($autoCreateRefs === true) {
                    if ($data['categoryID'] === null && (string) $row['categoryNameRaw'] !== '') {
                        $data['categoryID'] = assets_import_resolve_ref_cached($refCache['category'], (string) $row['categoryNameRaw'], 'category', $siteId, $userId);
                    }
                    if ($data['locationID'] === null && (string) $row['locationNameRaw'] !== '') {
                        $data['locationID'] = assets_import_resolve_ref_cached($refCache['location'], (string) $row['locationNameRaw'], 'location', $siteId, $userId);
                    }
                }

                $newId = AssetRegister::createAsset($data, $userId, ['importBatch' => $batchId]);
                if ($newId > 0) {
                    $created++;
                } else {
                    // 🔁 "Assets are independent" (file header) — an
                    // unforeseen single-row failure (e.g. a same-batch tag
                    // collision the dry-run's own de-dup missed because two
                    // BRAND-NEW rows share a tag) is counted and skipped,
                    // never a reason to roll back rows already committed
                    // in this same transaction.
                    $failed++;
                }
            }
            App::commit();
        } catch (\Throwable $ex) {
            App::rollback();
            Logger::exception($ex);
            $_SESSION['flash_msg']  = 'Error saving the import — nothing was written. Please try again.';
            $_SESSION['flash_type'] = 'danger';
            assetsImportRedirect();
        }

        Logger::activity(
            'AssetImportCompleted',
            'Imported ' . $created . ' asset(s) from CSV "' . (string) $upload['filename'] . '" (batch ' . $batchId . ')'
                . ($duplicateCount > 0 ? '; ' . $duplicateCount . ' duplicate row(s) skipped' : '')
                . ($failed > 0 ? '; ' . $failed . ' row(s) failed unexpectedly' : ''),
            $userId > 0 ? $userId : null
        );

        unset($_SESSION[ASSETS_IMPORT_SESSION_KEY]);
        $_SESSION['flash_msg'] = 'Imported ' . $created . ' asset' . ($created === 1 ? '' : 's') . '.'
            . ($duplicateCount > 0 ? ' ' . $duplicateCount . ' duplicate row(s) were skipped.' : '')
            . ($failed > 0 ? ' ' . $failed . ' row(s) failed unexpectedly — check the activity log.' : '');
        $_SESSION['flash_type'] = 'success';
        header('Location: /assets');
        exit();
    }

    // -------------------------------------------------------------------
    // 🗑️ action=cancel
    // -------------------------------------------------------------------
    if ($action === 'cancel') {
        unset($_SESSION[ASSETS_IMPORT_SESSION_KEY]);
        header('Location: /assets');
        exit();
    }

    http_response_code(400);
    exit('Bad request');
}

// -----------------------------------------------------------------------------
// 🖼️ GET — render the current state. Dry-run rows are re-derived fresh here
// too (see file header) — never stored in session.
// -----------------------------------------------------------------------------
$upload = $_SESSION[ASSETS_IMPORT_SESSION_KEY] ?? null;
if ($upload !== null && (int) $upload['siteID'] !== $siteId) {
    // 🛡️ Active site changed mid-flow — discard rather than risk
    // cross-site category/location resolution.
    unset($_SESSION[ASSETS_IMPORT_SESSION_KEY]);
    $upload = null;
    $_SESSION['flash_msg']  = 'Active site changed during import — the in-progress upload was discarded.';
    $_SESSION['flash_type'] = 'warning';
}

$forceMapping = isset($_GET['adjust']);

$state  = 'upload';
$dryRun = null;
if ($upload !== null) {
    if ($forceMapping === true || $upload['mapping']['name'] === null) {
        $state = 'mapping';
    } else {
        $dryRun = assets_import_parse_csv((string) $upload['rawText'], (array) $upload['mapping'], (string) $upload['delimiter'], $siteId, (bool) $upload['autoCreateRefs']);
        $state  = 'preview';
    }
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle   = 'Import Assets';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'Import' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-file-csv me-2"></i>Import Assets</h1>
        <p class="text-secondary mb-0">Bulk-add assets from a CSV file — columns match the register's own CSV export.</p>
    </div>
    <a href="/assets" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a>
</div>

<?php if ($state === 'upload'): ?>
    <div class="card mb-3">
        <div class="card-header"><strong>Format</strong></div>
        <div class="card-body">
            <p class="small mb-0">
                Columns are matched by header name against the Asset Tracker's own
                <a href="/assets?export=csv&csrf_token=<?php echo htmlspecialchars(urlencode($csrf), ENT_QUOTES, 'UTF-8'); ?>">CSV export</a>
                — Name, Kind, Category, Location, Manufacturer, Model, Serial Number, Asset Tag, Condition, Status,
                Purchase Date, Purchase Cost (GBP). Only <strong>Name</strong> is required; you can adjust the column
                mapping if it doesn't match automatically. Max 1&nbsp;MB / 2000 rows. New assets always import as
                <strong>non-confidential</strong> — licence keys and confidential flags aren't importable in bulk.
            </p>
        </div>
    </div>
    <div class="card">
        <div class="card-body">
            <form method="post" action="/assets/import" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="upload">
                <div class="mb-3">
                    <label for="csv_file" class="form-label">CSV file (max 1 MB)</label>
                    <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required class="form-control">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-upload me-1"></i>Upload &amp; preview</button>
            </form>
        </div>
    </div>

<?php elseif ($state === 'mapping'): ?>
    <?php
    $headersDisplay = (array) $upload['headersDisplay'];
    $mapping        = (array) $upload['mapping'];
    ?>
    <div class="alert alert-warning">Confirm (or correct) which column holds each field — Name is required, everything else is optional.</div>
    <div class="card mb-3">
        <div class="card-header"><strong><?php echo htmlspecialchars((string) $upload['filename'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div class="card-body">
            <form method="post" action="/assets/import" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="map">
                <?php foreach (assets_import_field_defs() as $key => $def): ?>
                    <div class="col-md-4 col-lg-3">
                        <label class="form-label small">
                            <?php echo htmlspecialchars($def['label'], ENT_QUOTES, 'UTF-8'); ?><?php echo $def['required'] === true ? ' <span class="text-danger">*</span>' : ''; ?>
                        </label>
                        <select class="form-select form-select-sm" name="map_<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                            <option value="">— Not present —</option>
                            <?php foreach ($headersDisplay as $idx => $label): ?>
                                <option value="<?php echo (int) $idx; ?>" <?php echo ($mapping[$key] ?? null) === $idx ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
                <div class="col-12"><hr class="my-1"></div>
                <div class="col-12 form-check">
                    <input type="checkbox" class="form-check-input" id="autoCreateRefs" name="autoCreateRefs" <?php echo ((bool) ($upload['autoCreateRefs'] ?? false)) === true ? 'checked' : ''; ?>>
                    <label class="form-check-label small" for="autoCreateRefs">
                        Automatically create any Category/Location name that doesn't already exist (off by default — unmatched names are left blank instead).
                    </label>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check me-1"></i>Apply mapping &amp; preview</button>
                </div>
            </form>
        </div>
    </div>
    <form method="post" action="/assets/import" class="mt-2">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="btn btn-outline-secondary btn-sm">Cancel</button>
    </form>

<?php else: /* preview */ ?>
    <?php
    $rows = (array) $dryRun['rows'];
    $counts = ['create' => 0, 'duplicate' => 0, 'invalid' => 0];
    foreach ($rows as $r) {
        $counts[$r['verdict']] = ($counts[$r['verdict']] ?? 0) + 1;
    }
    $canConfirm = $counts['invalid'] === 0 && $counts['create'] > 0;

    // 📋 Invalid rows ALWAYS shown in full (surface every problem); the
    // remainder capped so a 2000-row file doesn't render 2000 DOM rows.
    $invalidRows    = array_values(array_filter($rows, static fn (array $r): bool => $r['verdict'] === 'invalid'));
    $otherRows      = array_values(array_filter($rows, static fn (array $r): bool => $r['verdict'] !== 'invalid'));
    $otherShown     = array_slice($otherRows, 0, 150);
    $otherMore      = count($otherRows) - count($otherShown);
    ?>
    <div class="card mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-3"><strong>File:</strong> <?php echo htmlspecialchars((string) $upload['filename'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-md-3"><span class="badge bg-success"><?php echo $counts['create']; ?> to create</span></div>
                <div class="col-md-3"><span class="badge bg-secondary"><?php echo $counts['duplicate']; ?> duplicate</span></div>
                <div class="col-md-3"><span class="badge <?php echo $counts['invalid'] > 0 ? 'bg-danger' : 'bg-secondary'; ?>"><?php echo $counts['invalid']; ?> invalid</span></div>
            </div>
            <?php if ((int) ($dryRun['skippedBlank'] ?? 0) > 0): ?>
                <p class="small text-muted mt-2 mb-0"><?php echo (int) $dryRun['skippedBlank']; ?> blank line(s) ignored.</p>
            <?php endif; ?>
            <a href="/assets/import?adjust=1" class="small">Adjust mapping</a>
            <?php if (((bool) ($upload['autoCreateRefs'] ?? false)) === true): ?>
                <span class="badge bg-info text-dark ms-2">Auto-create categories/locations: ON</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($counts['invalid'] > 0): ?>
        <div class="alert alert-danger">
            <strong>Import is blocked</strong> — <?php echo $counts['invalid']; ?> row(s) are invalid. Fix your CSV and
            re-upload before importing (rows can't be edited here).
        </div>
    <?php elseif ($counts['create'] === 0): ?>
        <div class="alert alert-info">No new assets to create — every row either matched an existing asset or was blank.</div>
    <?php endif; ?>

    <?php if (count($rows) > 0): ?>
        <div class="card mb-3">
            <div class="card-body p-0">
                <div class="portal-data-list">
                    <div class="portal-data-row portal-data-header d-none d-md-flex">
                        <div class="col-md-1">Row</div>
                        <div class="col-md-2">Verdict</div>
                        <div class="col-md-2">Name</div>
                        <div class="col-md-2">Category / Location</div>
                        <div class="col-md-2">Tag / Serial</div>
                        <div class="col-md-3">Notes</div>
                    </div>
                    <?php foreach (array_merge($invalidRows, $otherShown) as $r): ?>
                        <?php
                        $verdict = (string) $r['verdict'];
                        $badge = ['create' => 'success', 'duplicate' => 'secondary', 'invalid' => 'danger'][$verdict] ?? 'secondary';
                        $disp = (array) $r['display'];
                        $noteText = implode(' ', array_merge((array) $r['notes'], $r['reason'] !== null ? [(string) $r['reason']] : []));
                        ?>
                        <div class="portal-data-row">
                            <div class="col-3 col-md-1 small text-muted"><?php echo htmlspecialchars($disp['row'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-3 col-md-2"><span class="badge bg-<?php echo htmlspecialchars($badge, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst($verdict), ENT_QUOTES, 'UTF-8'); ?></span></div>
                            <div class="col-6 col-md-2"><?php echo htmlspecialchars($disp['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-6 col-md-2 small text-muted"><?php echo htmlspecialchars(trim($disp['category'] . ($disp['category'] !== '' && $disp['location'] !== '' ? ' / ' : '') . $disp['location']), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-6 col-md-2 small text-muted"><?php echo htmlspecialchars(trim($disp['assetTagCode'] . ($disp['assetTagCode'] !== '' && $disp['serialNumber'] !== '' ? ' / ' : '') . $disp['serialNumber']), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-12 col-md-3 small <?php echo $verdict === 'invalid' ? 'text-danger' : 'text-muted'; ?>"><?php echo htmlspecialchars($noteText, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($otherMore > 0): ?>
                    <p class="small text-muted p-2 mb-0">…and <?php echo $otherMore; ?> more create/duplicate row(s) not shown (every invalid row IS shown above).</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canConfirm === true): ?>
        <form method="post" action="/assets/import" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="confirm">
            <button type="submit" class="btn btn-success"><i class="fa-solid fa-check me-1"></i>Confirm &amp; import <?php echo $counts['create']; ?> asset<?php echo $counts['create'] === 1 ? '' : 's'; ?></button>
        </form>
    <?php endif; ?>
    <form method="post" action="/assets/import" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="btn btn-outline-secondary">Cancel</button>
    </form>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
