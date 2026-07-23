<?php
// Path: _apps/giving/reconcile/import.php
/**
 * -----------------------------------------------------------------------------
 * Giving — Bank Reconciliation: CSV Statement Import 📥
 * -----------------------------------------------------------------------------
 * Four-action, one-endpoint upload flow (#299 sub-feature 3), Post/Redirect/
 * Get throughout — every action ends with a redirect and the GET renderer
 * decides which of four states to show from `$_SESSION['givingReconcileUpload']`:
 *
 *   action=upload  — multipart POST (csv_file). Reads raw bytes, hashes them
 *                    (duplicate-import guard), normalises encoding, sniffs
 *                    the delimiter, parses the header row, and attempts
 *                    header-NAME auto-mapping (never positional). If Date +
 *                    Description + (Credit or Amount) all resolve, the file
 *                    is fully parsed straight away; otherwise a mapping form
 *                    is shown.
 *   action=map     — the treasurer's role→column choices; re-parses the
 *                    session-held raw text with the new mapping.
 *   action=confirm — persists the batch + lines in one transaction, then
 *                    runs the shared auto-matcher outside it.
 *   action=cancel  — discards the in-progress upload.
 *
 * Credits (money in) ONLY are ever imported — debit/zero/blank lines are
 * silently skipped (counted); any OTHER malformed cell (a non-empty credit
 * that isn't a parseable amount, or an unparseable date) fails the WHOLE
 * upload, because silently dropping a bad credit would corrupt the
 * reconciliation. Partial imports are not possible by design.
 *
 * The uploaded file is never written to disk — read once via
 * file_get_contents(), held only in $_SESSION, parsed from an in-memory
 * php://temp stream.
 *
 * @package   Portal\Giving
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/299
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Giving;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Session + gate
Auth::ensureSession();
Auth::requireLogin();
if (Giving::canManage() === false) {
    Router::renderError(403);
    return;
}

// 🔗 Shared auto-matcher — plain require_once, NOT a tblRoutes entry.
require_once PORTAL_APPS . DIRECTORY_SEPARATOR . 'giving' . DIRECTORY_SEPARATOR . 'reconcile' . DIRECTORY_SEPARATOR . '_automatch.php';

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$csrf   = Auth::csrfToken();

const RECONCILE_MAX_BYTES = 1048576; // 1 MB
const RECONCILE_MAX_ROWS  = 2000;
const RECONCILE_SESSION_KEY = 'givingReconcileUpload';

// -----------------------------------------------------------------------------
// 🧰 Helper functions (import-only — the shared automatch lives in
// _automatch.php; nothing here is registered in tblRoutes).
// -----------------------------------------------------------------------------

/** Normalise a header cell for alias matching (house idiom, lowercase). */
function reconcile_normalise_header(string $h): string
{
    return strtolower(trim(str_replace(["\xEF\xBB\xBF", '"'], '', $h)));
}

/** Clean a header cell for DISPLAY (no lowercasing). */
function reconcile_display_header(string $h): string
{
    return trim(str_replace(["\xEF\xBB\xBF", '"'], '', $h));
}

/** Spreadsheet-style column letter for a zero-based index (0→A, 25→Z, 26→AA…). */
function reconcile_col_letter(int $idx): string
{
    $letter = '';
    $n = $idx + 1;
    while ($n > 0) {
        $rem = ($n - 1) % 26;
        $letter = chr(65 + $rem) . $letter;
        $n = intdiv($n - 1, 26);
    }
    return $letter;
}

/** Cosmetic bank-preset badge — never load-bearing for parsing/matching. */
function reconcile_detect_bank(array $headers): string
{
    if (in_array('sort code', $headers, true) === true) {
        return 'lloyds';
    }
    if (in_array('notes and #tags', $headers, true) === true) {
        return 'monzo';
    }
    if (in_array('counter party', $headers, true) === true) {
        return 'starling';
    }
    if (in_array('paid out', $headers, true) === true || in_array('paid in', $headers, true) === true) {
        return 'hsbc';
    }
    if (in_array('subcategory', $headers, true) === true && in_array('memo', $headers, true) === true) {
        return 'barclays';
    }
    return 'generic';
}

/**
 * Header-NAME alias map — normative, this IS the detector (#299 §3.4). First
 * alias hit wins per role, aliases tried in the order listed.
 *
 * @return array<string, list<string>>
 */
function reconcile_alias_map(): array
{
    return [
        'date'        => ['transaction date', 'date', 'posting date', 'value date', 'created'],
        'credit'      => ['credit amount', 'paid in', 'money in', 'credit', 'in', 'deposits'],
        'debit'       => ['debit amount', 'paid out', 'money out', 'debit', 'out', 'withdrawals'],
        'amount'      => ['amount', 'amount (gbp)', 'value'],
        'description' => ['transaction description', 'description', 'counter party', 'counterparty', 'name', 'details', 'narrative', 'memo', 'transaction details'],
        'reference'   => ['reference', 'transaction reference', 'notes and #tags', 'notes'],
    ];
}

/**
 * Attempt to auto-map every role from normalised headers.
 *
 * @param list<string> $headers
 * @return array{date: ?int, credit: ?int, debit: ?int, amount: ?int, description: ?int, reference: ?int, mode: ?string}
 */
function reconcile_auto_map(array $headers): array
{
    $mapping = ['date' => null, 'credit' => null, 'debit' => null, 'amount' => null, 'description' => null, 'reference' => null];
    foreach (reconcile_alias_map() as $role => $aliases) {
        foreach ($aliases as $alias) {
            $idx = array_search($alias, $headers, true);
            if ($idx !== false) {
                $mapping[$role] = (int) $idx;
                break;
            }
        }
    }
    // 🏦 Credit/debit pair wins over a single signed Amount column (Lloyds-style).
    if ($mapping['credit'] !== null) {
        $mapping['amount'] = null;
        $mapping['mode'] = 'credit';
    } elseif ($mapping['amount'] !== null) {
        $mapping['mode'] = 'amount';
    } else {
        $mapping['mode'] = null;
    }
    return $mapping;
}

/** Read a posted mapping-form index field ('' → null; else validated int). */
function reconcile_read_idx(string $raw): ?int
{
    $v = trim($raw);
    if ($v === '' || ctype_digit($v) === false) {
        return null;
    }
    return (int) $v;
}

/** Count data rows (post-header) in raw CSV text — cheap pass for the row cap. */
function reconcile_count_data_rows(string $raw, string $delimiter): int
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

/**
 * Normalise one data row into a gift-log-shaped array, or flag it as a
 * silent skip (debit/zero/blank) or a fatal error (bad amount format, bad
 * date, ragged row missing a mapped cell).
 *
 * @param list<string|null>            $data
 * @param array<string, int|string|null> $mapping
 *
 * @return array{ok: bool, skip: bool, error: ?string, row: ?array{date: string, pence: int, desc: string, ref: ?string}}
 */
function reconcile_parse_row(array $data, array $mapping, string $mode, int $rowNum): array
{
    $get = static function (?int $idx) use ($data): ?string {
        if ($idx === null) {
            return '';
        }
        if (array_key_exists($idx, $data) === false) {
            return null; // 🚩 ragged row — the mapped column doesn't exist on this line.
        }
        return trim((string) $data[$idx]);
    };

    // 🈳 Genuinely blank physical line — skip silently, not counted.
    if (count(array_filter($data, static fn ($v): bool => trim((string) $v) !== '')) === 0) {
        return ['ok' => false, 'skip' => true, 'error' => null, 'row' => null];
    }

    // 🚩 Ragged-row guard — any REQUIRED mapped column missing on this line
    // is fatal (silently treating it as blank would corrupt the import).
    $requiredRaw = [$mapping['date'], $mode === 'credit' ? $mapping['credit'] : $mapping['amount'], $mapping['description']];
    $requiredIdx = [];
    foreach ($requiredRaw as $v) {
        if ($v !== null) {
            $requiredIdx[] = (int) $v;
        }
    }
    foreach ($requiredIdx as $idx) {
        if (array_key_exists($idx, $data) === false) {
            return ['ok' => false, 'skip' => false, 'error' => 'Row ' . $rowNum . ': ragged row — a mapped column is missing on this line.', 'row' => null];
        }
    }

    $amountRegex = '/^-?[£$€]?[\d,]+(\.\d{1,2})?$/';

    // 💷 Amount → pence (credits only; debit/zero/blank silently skipped).
    if ($mode === 'credit') {
        $creditCell = (string) $get($mapping['credit']);
        if ($creditCell === '') {
            return ['ok' => false, 'skip' => true, 'error' => null, 'row' => null];
        }
        $cleaned = str_replace(' ', '', $creditCell);
        if (preg_match($amountRegex, $cleaned) !== 1) {
            return ['ok' => false, 'skip' => false, 'error' => 'Row ' . $rowNum . ': unparseable credit amount "' . $creditCell . '".', 'row' => null];
        }
        $pence = Giving::parseAmount($creditCell);
        if ($pence <= 0) {
            return ['ok' => false, 'skip' => true, 'error' => null, 'row' => null];
        }
    } else {
        $amountCell = (string) $get($mapping['amount']);
        if ($amountCell === '') {
            return ['ok' => false, 'skip' => true, 'error' => null, 'row' => null];
        }
        $cleaned = str_replace(' ', '', $amountCell);
        if (preg_match($amountRegex, $cleaned) !== 1) {
            return ['ok' => false, 'skip' => false, 'error' => 'Row ' . $rowNum . ': unparseable amount "' . $amountCell . '".', 'row' => null];
        }
        $pence = Giving::parseAmount($amountCell);
        if ($pence <= 0) {
            // Zero or negative (debit encoded as a negative signed amount).
            return ['ok' => false, 'skip' => true, 'error' => null, 'row' => null];
        }
    }

    // 📅 Date → Y-m-d. Never pass slash-dates to strtotime — it assumes US
    // ordering; UK banks are always DD/MM/YYYY.
    $dateCell = (string) $get($mapping['date']);
    $isoDate = null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dateCell, $m) === 1) {
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if (checkdate($mo, $d, $y) === false) {
            return ['ok' => false, 'skip' => false, 'error' => 'Row ' . $rowNum . ': invalid date "' . $dateCell . '".', 'row' => null];
        }
        $isoDate = sprintf('%04d-%02d-%02d', $y, $mo, $d);
    } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $dateCell, $m) === 1) {
        [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($y < 100) {
            $y += 2000;
        }
        if (checkdate($mo, $d, $y) === false) {
            return ['ok' => false, 'skip' => false, 'error' => 'Row ' . $rowNum . ': invalid date "' . $dateCell . '".', 'row' => null];
        }
        $isoDate = sprintf('%04d-%02d-%02d', $y, $mo, $d);
    } elseif (preg_match('/[A-Za-z]{3}/', $dateCell) === 1) {
        $ts = strtotime($dateCell);
        if ($ts === false) {
            return ['ok' => false, 'skip' => false, 'error' => 'Row ' . $rowNum . ': unparseable date "' . $dateCell . '".', 'row' => null];
        }
        $isoDate = date('Y-m-d', $ts);
    } else {
        return ['ok' => false, 'skip' => false, 'error' => 'Row ' . $rowNum . ': unparseable date "' . $dateCell . '".', 'row' => null];
    }

    $descCell = (string) $get($mapping['description']);
    $desc     = mb_substr(trim($descCell), 0, 255);

    $refIdx  = $mapping['reference'];
    $refCell = $refIdx !== null ? trim((string) $get((int) $refIdx)) : '';
    $ref     = $refCell !== '' ? mb_substr($refCell, 0, 100) : null;

    return ['ok' => true, 'skip' => false, 'error' => null, 'row' => ['date' => $isoDate, 'pence' => $pence, 'desc' => $desc, 'ref' => $ref]];
}

/**
 * Parse every data row of the (already encoding-normalised) raw CSV text.
 *
 * @return array{rows: list<array{date: string, pence: int, desc: string, ref: ?string}>, skipped: int, errors: list<string>}
 */
function reconcile_parse_csv(string $raw, array $mapping, string $mode, string $delimiter): array
{
    $fh = fopen('php://temp', 'r+');
    if ($fh === false) {
        return ['rows' => [], 'skipped' => 0, 'errors' => ['Could not open the upload for parsing.']];
    }
    fwrite($fh, $raw);
    rewind($fh);
    fgetcsv($fh, 0, $delimiter); // header — already consumed when building $mapping

    $rows = [];
    $skipped = 0;
    $errors = [];
    $rowNum = 1;
    while (($data = fgetcsv($fh, 0, $delimiter)) !== false) {
        $rowNum++;
        $parsed = reconcile_parse_row($data, $mapping, $mode, $rowNum);
        if ($parsed['skip'] === true) {
            $skipped++;
            continue;
        }
        if ($parsed['ok'] === false) {
            $errors[] = (string) $parsed['error'];
            continue;
        }
        $rows[] = $parsed['row'];
    }
    fclose($fh);
    return ['rows' => $rows, 'skipped' => $skipped, 'errors' => $errors];
}

/** Redirect back to the import page, optionally forcing the mapping view. */
function reconcileRedirect(bool $adjust = false): void
{
    header('Location: /giving/reconcile/import' . ($adjust === true ? '?adjust=1' : ''));
    exit();
}

// -----------------------------------------------------------------------------
// 📨 POST actions — Post/Redirect/Get throughout.
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
        unset($_SESSION[RECONCILE_SESSION_KEY]);

        $file = $_FILES['csv_file'] ?? null;
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_msg']  = 'File upload failed — please choose a CSV file and try again.';
            $_SESSION['flash_type'] = 'danger';
            reconcileRedirect();
        }
        if ((int) $file['size'] > RECONCILE_MAX_BYTES) {
            $_SESSION['flash_msg']  = 'File too large (max 1 MB).';
            $_SESSION['flash_type'] = 'danger';
            reconcileRedirect();
        }
        $filename = basename((string) $file['name']);
        if (str_ends_with(strtolower($filename), '.csv') === false) {
            $_SESSION['flash_msg']  = 'Only .csv files are accepted.';
            $_SESSION['flash_type'] = 'danger';
            reconcileRedirect();
        }

        $raw = file_get_contents((string) $file['tmp_name']);
        if ($raw === false || $raw === '') {
            $_SESSION['flash_msg']  = 'The uploaded file was empty or unreadable.';
            $_SESSION['flash_type'] = 'danger';
            reconcileRedirect();
        }

        // 🔒 Hash the ORIGINAL bytes, before any BOM-strip / encoding conversion.
        $fileHash = hash('sha256', $raw);

        if (str_starts_with($raw, "\xEF\xBB\xBF") === true) {
            $raw = substr($raw, 3);
        }
        if (mb_check_encoding($raw, 'UTF-8') === false) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        $firstLineEnd = strpos($raw, "\n");
        $firstLine    = $firstLineEnd !== false ? substr($raw, 0, $firstLineEnd) : $raw;
        $delimiter    = (strpos($firstLine, ',') === false && strpos($firstLine, ';') !== false) ? ';' : ',';

        if (reconcile_count_data_rows($raw, $delimiter) > RECONCILE_MAX_ROWS) {
            $_SESSION['flash_msg']  = 'This file has too many rows (max ' . RECONCILE_MAX_ROWS . ') — split it into smaller statements.';
            $_SESSION['flash_type'] = 'danger';
            reconcileRedirect();
        }

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $headerRow = fgetcsv($fh, 0, $delimiter);
        fclose($fh);
        if ($headerRow === false || count($headerRow) === 0) {
            $_SESSION['flash_msg']  = 'Could not read a header row from this file.';
            $_SESSION['flash_type'] = 'danger';
            reconcileRedirect();
        }
        $headersDisplay = array_map(static fn ($h): string => reconcile_display_header((string) $h), $headerRow);
        $headersNorm    = array_map(static fn ($h): string => reconcile_normalise_header((string) $h), $headerRow);

        $bankKey  = reconcile_detect_bank($headersNorm);
        $currency = (string) (App::settings('giving.currency') ?? 'GBP');
        $mapping  = reconcile_auto_map($headersNorm);
        $mode     = $mapping['mode'];

        $upload = [
            'siteID'         => $siteId,
            'filename'       => mb_substr($filename, 0, 255),
            'fileHash'       => $fileHash,
            'bankKey'        => $bankKey,
            'currency'       => $currency,
            'delimiter'      => $delimiter,
            'rawText'        => $raw,
            'headers'        => $headersNorm,
            'headersDisplay' => $headersDisplay,
            'mapping'        => $mapping,
            'mode'           => $mode,
        ];

        $autoMapped = $mapping['date'] !== null && $mapping['description'] !== null && $mode !== null;
        if ($autoMapped === true) {
            $parsed = reconcile_parse_csv($raw, $mapping, (string) $mode, $delimiter);
            if (count($parsed['errors']) > 0) {
                $upload['fatalErrors'] = $parsed['errors'];
            } else {
                $upload['rows']    = $parsed['rows'];
                $upload['skipped'] = $parsed['skipped'];
            }
        }

        $_SESSION[RECONCILE_SESSION_KEY] = $upload;
        reconcileRedirect();
    }

    // -------------------------------------------------------------------
    // 🗂️ action=map — treasurer's manual column choices
    // -------------------------------------------------------------------
    if ($action === 'map') {
        $upload = $_SESSION[RECONCILE_SESSION_KEY] ?? null;
        if ($upload === null || (int) $upload['siteID'] !== $siteId) {
            unset($_SESSION[RECONCILE_SESSION_KEY]);
            $_SESSION['flash_msg']  = 'Upload session expired or the active site changed — please re-upload.';
            $_SESSION['flash_type'] = 'danger';
            reconcileRedirect();
        }

        $dateIdx   = reconcile_read_idx((string) ($_POST['dateIdx'] ?? ''));
        $creditIdx = reconcile_read_idx((string) ($_POST['creditIdx'] ?? ''));
        $debitIdx  = reconcile_read_idx((string) ($_POST['debitIdx'] ?? ''));
        $amountIdx = reconcile_read_idx((string) ($_POST['amountIdx'] ?? ''));
        $descIdx   = reconcile_read_idx((string) ($_POST['descIdx'] ?? ''));
        $refIdx    = reconcile_read_idx((string) ($_POST['refIdx'] ?? ''));

        $mapping = [
            'date' => $dateIdx, 'credit' => $creditIdx, 'debit' => $debitIdx,
            'amount' => $amountIdx, 'description' => $descIdx, 'reference' => $refIdx,
        ];
        // 💾 Reflect the attempt back into session so a validation failure
        // re-shows the form with the treasurer's choices, not the stale guess.
        $upload['mapping'] = $mapping;

        $hasCredit = $creditIdx !== null;
        $hasAmount = $amountIdx !== null;
        $mapErrors = [];
        if ($dateIdx === null) {
            $mapErrors[] = 'Choose the Date column.';
        }
        if ($descIdx === null) {
            $mapErrors[] = 'Choose the Description column.';
        }
        if ($hasCredit === $hasAmount) {
            $mapErrors[] = 'Choose exactly one of Credit ("money in") or a single signed Amount column.';
        }

        if (count($mapErrors) > 0) {
            $_SESSION[RECONCILE_SESSION_KEY] = $upload;
            $_SESSION['flash_msg']  = implode(' ', $mapErrors);
            $_SESSION['flash_type'] = 'danger';
            reconcileRedirect(true);
        }

        $mode = $hasCredit === true ? 'credit' : 'amount';
        $upload['mode'] = $mode;

        $parsed = reconcile_parse_csv((string) $upload['rawText'], $mapping, $mode, (string) $upload['delimiter']);
        if (count($parsed['errors']) > 0) {
            $upload['fatalErrors'] = $parsed['errors'];
            unset($upload['rows'], $upload['skipped']);
        } else {
            $upload['rows']    = $parsed['rows'];
            $upload['skipped'] = $parsed['skipped'];
            unset($upload['fatalErrors']);
        }
        $_SESSION[RECONCILE_SESSION_KEY] = $upload;
        reconcileRedirect();
    }

    // -------------------------------------------------------------------
    // ✅ action=confirm — persist + auto-match
    // -------------------------------------------------------------------
    if ($action === 'confirm') {
        $upload = $_SESSION[RECONCILE_SESSION_KEY] ?? null;
        if ($upload === null || (int) $upload['siteID'] !== $siteId || isset($upload['rows']) === false) {
            unset($_SESSION[RECONCILE_SESSION_KEY]);
            $_SESSION['flash_msg']  = 'Upload session expired or the active site changed — please re-upload.';
            $_SESSION['flash_type'] = 'danger';
            reconcileRedirect();
        }

        // 🛡️ Belt-and-braces re-check — the preview screen already showed
        // this, but a race between two tabs must never hit the UNIQUE key.
        $dupImportId = null;
        $stmt = $db->prepare('SELECT importID FROM tblBankImports WHERE siteID = ? AND fileHash = ? LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('is', $siteId, $upload['fileHash']);
            $stmt->execute();
            $dupRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($dupRow !== null) {
                $dupImportId = (int) $dupRow['importID'];
            }
        }
        if ($dupImportId !== null) {
            $_SESSION['flash_msg']  = 'This file was already imported (import #' . $dupImportId . ').';
            $_SESSION['flash_type'] = 'danger';
            reconcileRedirect();
        }

        $rows        = (array) $upload['rows'];
        $filename    = (string) $upload['filename'];
        $bankKey     = (string) $upload['bankKey'];
        $currency    = (string) $upload['currency'];
        $fileHash    = (string) $upload['fileHash'];
        $rowCount    = count($rows);
        $skippedCount = (int) ($upload['skipped'] ?? 0);
        $importedByID = $userId > 0 ? $userId : null;

        $importId = 0;
        $db->begin_transaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO tblBankImports (siteID, filename, bankKey, currency, fileHash, rowCount, skippedCount, importedByID) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if ($stmt === false) {
                throw new \RuntimeException('Failed to prepare bank import insert: ' . $db->error);
            }
            $stmt->bind_param('issssiii', $siteId, $filename, $bankKey, $currency, $fileHash, $rowCount, $skippedCount, $importedByID);
            $stmt->execute();
            $importId = (int) $stmt->insert_id;
            $stmt->close();

            $lineStmt = $db->prepare(
                'INSERT INTO tblBankTxns (importID, siteID, txnDate, amountPence, description, reference) VALUES (?, ?, ?, ?, ?, ?)'
            );
            if ($lineStmt === false) {
                throw new \RuntimeException('Failed to prepare bank txn insert: ' . $db->error);
            }
            foreach ($rows as $r) {
                $rDate = (string) $r['date'];
                $rPence = (int) $r['pence'];
                $rDesc = (string) $r['desc'];
                $rRef = $r['ref'];
                $lineStmt->bind_param('iisiss', $importId, $siteId, $rDate, $rPence, $rDesc, $rRef);
                $lineStmt->execute();
            }
            $lineStmt->close();

            $db->commit();
        } catch (\Throwable $ex) {
            $db->rollback();
            Logger::exception($ex);
            $_SESSION['flash_msg']  = 'Error saving the import — nothing was written. Please try again.';
            $_SESSION['flash_type'] = 'danger';
            reconcileRedirect();
        }

        // 🤖 Auto-match OUTSIDE the transaction — idempotent + re-runnable,
        // so a matcher hiccup must never roll back the import itself.
        $tolDays = max(0, (int) (App::settings('giving.reconcile.toleranceDays') ?? '5'));
        $matchedCount = giving_reconcile_automatch($db, $siteId, $importId, $userId, $tolDays, $currency);

        Logger::activity(
            'BankImportCreated',
            'Imported bank statement "' . $filename . '" — ' . $rowCount . ' credit lines',
            $userId
        );

        unset($_SESSION[RECONCILE_SESSION_KEY]);
        $_SESSION['flash_msg']  = 'Imported ' . $rowCount . ' credit line' . ($rowCount === 1 ? '' : 's') . '; ' . $matchedCount . ' matched automatically.';
        $_SESSION['flash_type'] = 'success';
        header('Location: /giving/reconcile/view?id=' . $importId);
        exit();
    }

    // -------------------------------------------------------------------
    // 🗑️ action=cancel
    // -------------------------------------------------------------------
    if ($action === 'cancel') {
        unset($_SESSION[RECONCILE_SESSION_KEY]);
        header('Location: /giving/reconcile');
        exit();
    }

    http_response_code(400);
    exit('Bad request');
}

// -----------------------------------------------------------------------------
// 🖼️ GET — render the current state
// -----------------------------------------------------------------------------
$upload = $_SESSION[RECONCILE_SESSION_KEY] ?? null;
if ($upload !== null && (int) $upload['siteID'] !== $siteId) {
    // 🛡️ Active site changed mid-flow — the upload was scoped to a
    // different site, so discard rather than risk cross-site data.
    unset($_SESSION[RECONCILE_SESSION_KEY]);
    $upload = null;
    $_SESSION['flash_msg']  = 'Active site changed during import — the in-progress upload was discarded.';
    $_SESSION['flash_type'] = 'warning';
}

$forceMapping = isset($_GET['adjust']);

$state = 'upload';
if ($upload !== null) {
    if (isset($upload['fatalErrors']) === true && count($upload['fatalErrors']) > 0 && $forceMapping === false) {
        $state = 'errors';
    } elseif (isset($upload['rows']) === true && $forceMapping === false) {
        $state = 'preview';
    } elseif (isset($upload['headers']) === true) {
        $state = 'mapping';
    }
}

// 🔁 Duplicate-import check — also re-checked server-side at confirm
// (belt and braces because the UNIQUE key would otherwise throw).
$dupImportId   = null;
$dupImportedAt = null;
if ($state === 'preview') {
    $stmt = $db->prepare('SELECT importID, importedAt FROM tblBankImports WHERE siteID = ? AND fileHash = ? LIMIT 1');
    if ($stmt !== false) {
        $fileHash = (string) $upload['fileHash'];
        $stmt->bind_param('is', $siteId, $fileHash);
        $stmt->execute();
        $dupRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($dupRow !== null) {
            $dupImportId   = (int) $dupRow['importID'];
            $dupImportedAt = (string) $dupRow['importedAt'];
        }
    }
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle   = 'Import Bank Statement';
$pageSection = 'giving';
$breadcrumbs = ['Dashboard' => '/', 'Giving' => '/giving', 'Reconcile' => '/giving/reconcile', 'Import' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-file-csv me-2"></i>Import Bank Statement</h1>
        <p class="text-secondary mb-0">Upload a bank statement CSV to reconcile against the gift log.</p>
    </div>
    <a href="/giving/reconcile" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Reconcile</a>
</div>

<?php if ($state === 'upload'): ?>
    <div class="card mb-3">
        <div class="card-header"><strong>Format</strong></div>
        <div class="card-body">
            <p class="small mb-0">
                Supported: Lloyds, HSBC, Barclays, Monzo, Starling and any CSV with date / amount (or paid-in) /
                description columns — columns are matched by header name; you can adjust the mapping if needed.
                Credits (money in) only are imported; max 1&nbsp;MB / 2000 rows.
            </p>
        </div>
    </div>
    <div class="card">
        <div class="card-body">
            <form method="post" action="/giving/reconcile/import" enctype="multipart/form-data">
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
    <div class="alert alert-warning">Couldn't automatically map every column — pick the right column for each field below.</div>
    <div class="card">
        <div class="card-header"><strong><?php echo htmlspecialchars((string) $upload['filename'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div class="card-body">
            <form method="post" action="/giving/reconcile/import" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="map">
                <div class="col-md-3">
                    <label class="form-label small">Date</label>
                    <select class="form-select form-select-sm" name="dateIdx">
                        <option value="">— Not present —</option>
                        <?php foreach ($headersDisplay as $idx => $label): ?>
                            <option value="<?php echo (int) $idx; ?>" <?php echo $mapping['date'] === $idx ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(reconcile_col_letter((int) $idx) . ': ' . $label, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Description</label>
                    <select class="form-select form-select-sm" name="descIdx">
                        <option value="">— Not present —</option>
                        <?php foreach ($headersDisplay as $idx => $label): ?>
                            <option value="<?php echo (int) $idx; ?>" <?php echo $mapping['description'] === $idx ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(reconcile_col_letter((int) $idx) . ': ' . $label, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Reference (optional)</label>
                    <select class="form-select form-select-sm" name="refIdx">
                        <option value="">— Not present —</option>
                        <?php foreach ($headersDisplay as $idx => $label): ?>
                            <option value="<?php echo (int) $idx; ?>" <?php echo $mapping['reference'] === $idx ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(reconcile_col_letter((int) $idx) . ': ' . $label, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12"><hr class="my-1"></div>
                <div class="col-12">
                    <p class="small text-muted mb-2">Choose EITHER Credit (+ optional Debit) OR a single signed Amount column — not both.</p>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Credit / "Money in"</label>
                    <select class="form-select form-select-sm" name="creditIdx">
                        <option value="">— Not present —</option>
                        <?php foreach ($headersDisplay as $idx => $label): ?>
                            <option value="<?php echo (int) $idx; ?>" <?php echo $mapping['credit'] === $idx ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(reconcile_col_letter((int) $idx) . ': ' . $label, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Debit / "Money out" (optional)</label>
                    <select class="form-select form-select-sm" name="debitIdx">
                        <option value="">— Not present —</option>
                        <?php foreach ($headersDisplay as $idx => $label): ?>
                            <option value="<?php echo (int) $idx; ?>" <?php echo $mapping['debit'] === $idx ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(reconcile_col_letter((int) $idx) . ': ' . $label, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Amount — single signed (optional)</label>
                    <select class="form-select form-select-sm" name="amountIdx">
                        <option value="">— Not present —</option>
                        <?php foreach ($headersDisplay as $idx => $label): ?>
                            <option value="<?php echo (int) $idx; ?>" <?php echo $mapping['amount'] === $idx ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(reconcile_col_letter((int) $idx) . ': ' . $label, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check me-1"></i>Apply mapping &amp; preview</button>
                </div>
            </form>
        </div>
    </div>
    <form method="post" action="/giving/reconcile/import" class="mt-2">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="btn btn-outline-secondary btn-sm">Cancel</button>
    </form>

<?php elseif ($state === 'errors'): ?>
    <?php
    $fatalErrors = (array) ($upload['fatalErrors'] ?? []);
    $shown = array_slice($fatalErrors, 0, 10);
    $more  = count($fatalErrors) - count($shown);
    ?>
    <div class="alert alert-danger">
        <strong>This file could not be imported</strong> — every row must have a valid credit amount and date before
        anything is stored (a silently-dropped bad row would corrupt the reconciliation).
    </div>
    <div class="card mb-3">
        <div class="card-header"><strong>Row errors</strong></div>
        <div class="card-body">
            <ul class="mb-0 small">
                <?php foreach ($shown as $msg): ?>
                    <li><?php echo htmlspecialchars((string) $msg, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($more > 0): ?>
                <p class="small text-muted mt-2 mb-0">…and <?php echo (int) $more; ?> more.</p>
            <?php endif; ?>
        </div>
    </div>
    <a href="/giving/reconcile/import?adjust=1" class="btn btn-outline-primary btn-sm">Adjust mapping</a>
    <form method="post" action="/giving/reconcile/import" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="btn btn-outline-secondary btn-sm">Cancel</button>
    </form>

<?php else: /* preview */ ?>
    <?php
    $rows    = (array) $upload['rows'];
    $total   = array_sum(array_map(static fn (array $r): int => (int) $r['pence'], $rows));
    $preview = array_slice($rows, 0, 20);
    ?>
    <div class="card mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-3"><strong>File:</strong> <?php echo htmlspecialchars((string) $upload['filename'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-md-2"><strong>Bank:</strong> <span class="badge bg-secondary"><?php echo htmlspecialchars((string) $upload['bankKey'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="col-md-2"><strong>Currency:</strong> <?php echo htmlspecialchars((string) $upload['currency'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-md-3"><strong><?php echo count($rows); ?></strong> credits, <?php echo htmlspecialchars(Giving::formatAmount($total, (string) $upload['currency']), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-md-2"><span class="text-muted"><?php echo (int) ($upload['skipped'] ?? 0); ?> skipped</span></div>
            </div>
            <a href="/giving/reconcile/import?adjust=1" class="small">Adjust mapping</a>
        </div>
    </div>

    <?php if ($dupImportId !== null): ?>
        <div class="alert alert-danger">
            This exact file was already imported as
            <a href="/giving/reconcile/view?id=<?php echo $dupImportId; ?>" class="alert-link">import #<?php echo $dupImportId; ?></a>
            on <?php echo htmlspecialchars(date('d/m/Y H:i', (int) strtotime((string) $dupImportedAt)), ENT_QUOTES, 'UTF-8'); ?>. Re-importing the same file is blocked.
        </div>
    <?php endif; ?>

    <?php if (count($rows) === 0): ?>
        <div class="alert alert-info">No credit lines were found in this file (only debit/zero/blank rows).</div>
    <?php else: ?>
        <div class="card mb-3">
            <div class="card-body p-0">
                <div class="portal-data-list">
                    <div class="portal-data-row portal-data-header d-none d-md-flex">
                        <div class="col-md-2">Date</div>
                        <div class="col-md-5">Description</div>
                        <div class="col-md-3">Reference</div>
                        <div class="col-md-2 text-end">Amount</div>
                    </div>
                    <?php foreach ($preview as $r): ?>
                        <div class="portal-data-row">
                            <div class="col-6 col-md-2"><?php echo htmlspecialchars(date('d/m/Y', (int) strtotime((string) $r['date'])), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-6 col-md-5"><?php echo htmlspecialchars((string) $r['desc'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-6 col-md-3 text-muted small"><?php echo htmlspecialchars((string) ($r['ref'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-6 col-md-2 text-end"><?php echo htmlspecialchars(Giving::formatAmount((int) $r['pence'], (string) $upload['currency']), ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($rows) > 20): ?>
                    <p class="small text-muted p-2 mb-0">Showing the first 20 of <?php echo count($rows); ?> credits.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($dupImportId === null): ?>
        <form method="post" action="/giving/reconcile/import" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="confirm">
            <button type="submit" class="btn btn-success" <?php echo count($rows) === 0 ? 'disabled' : ''; ?>><i class="fa-solid fa-check me-1"></i>Confirm &amp; import</button>
        </form>
    <?php endif; ?>
    <form method="post" action="/giving/reconcile/import" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="btn btn-outline-secondary">Cancel</button>
    </form>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
