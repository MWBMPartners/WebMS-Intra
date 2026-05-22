<?php
// Path: core/CsvExporter.php
/**
 * -----------------------------------------------------------------------------
 * CSV Exporter 📊
 * -----------------------------------------------------------------------------
 * Lightweight utility for generating CSV file downloads from associative arrays.
 * Streams output directly to the browser (no temp file needed).
 *
 * Usage:
 *   $rows = [['Name' => 'Alice', 'Email' => 'alice@x.com'], ...];
 *   CsvExporter::download('users.csv', $rows);
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.1
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/77
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class CsvExporter
{
    /**
     * 📊 Stream a CSV file download to the browser.
     *
     * @param string               $filename  Download filename (e.g. 'report.csv')
     * @param array<int, array>    $rows      Array of associative arrays (keys = column headers)
     * @param array<int, string>   $headers   Optional explicit column headers (overrides row keys)
     * @return void
     */
    public static function download(string $filename, array $rows, array $headers = []): void
    {
        // 🛡️ Clean any existing output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // 📋 Set download headers
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . self::sanitiseFilename($filename) . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        if ($output === false) {
            return;
        }

        // 📋 BOM for Excel UTF-8 compatibility
        fwrite($output, "\xEF\xBB\xBF");

        // 📋 Determine headers from first row if not explicitly provided
        if (count($headers) === 0 && count($rows) > 0) {
            $headers = array_keys($rows[0]);
        }

        // 📋 Write header row
        if (count($headers) > 0) {
            fputcsv($output, $headers);
        }

        // 📋 Write data rows
        foreach ($rows as $row) {
            if (count($headers) > 0) {
                // 📋 Map values to header order (handles missing keys gracefully)
                $orderedRow = [];
                foreach ($headers as $key) {
                    $orderedRow[] = (string) ($row[$key] ?? '');
                }
                fputcsv($output, $orderedRow);
            } else {
                fputcsv($output, array_values($row));
            }
        }

        fclose($output);
        exit();
    }

    /**
     * 🛡️ Sanitise a filename for Content-Disposition header.
     *
     * @param string $filename
     * @return string
     */
    private static function sanitiseFilename(string $filename): string
    {
        // 📋 Remove path traversal and non-ASCII characters
        $filename = basename($filename);
        $filename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $filename);
        if ($filename === '' || $filename === '.csv') {
            $filename = 'export.csv';
        }
        return $filename;
    }
}
