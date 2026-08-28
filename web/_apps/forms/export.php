<?php
// Path: _apps/forms/export.php
/**
 * -----------------------------------------------------------------------------
 * Forms Builder — CSV Export 📊
 * -----------------------------------------------------------------------------
 * Admin-only, GET with a CSRF token in the query string (leadership/
 * export.php precedent — a download link can't POST). Delegates row/header
 * assembly to `Portal\Core\FormEngine::csvRows()`; `CsvExporter::download()`
 * handles quoting and CWE-1236 formula-injection neutralisation.
 *
 * @package   Portal\Forms
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/153
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\CsvExporter;
use Portal\Core\FormEngine;
use Portal\Core\Logger;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

if (Auth::verifyCsrf($_GET['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /forms/manage', true, 302);
    exit();
}

$siteId = Site::id();
$formId = (int) ($_GET['id'] ?? 0);

$form = FormEngine::getForm($formId, $siteId);
if ($form === null) {
    $_SESSION['flash_msg']  = 'Form not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /forms/manage', true, 302);
    exit();
}

['headers' => $headers, 'rows' => $rows] = FormEngine::csvRows($formId, $siteId);

Logger::activity('FormResponsesExported', 'Exported responses for form #' . $formId);

$filename = 'form-' . $formId . '-responses-' . date('Ymd') . '.csv';
CsvExporter::download($filename, $rows, $headers);
