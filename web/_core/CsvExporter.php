<?php
// Path: _core/CsvExporter.php
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
 * SECURITY (CWE-1236 — CSV/formula/DDE injection): every cell (header and
 * data) is passed through neutraliseFormulaCell() immediately before it is
 * handed to fputcsv(, ',', '"', ''). A cell whose first character is one of = + - @ TAB
 * or CR is a live formula/DDE trigger in Excel/LibreOffice/Sheets when the
 * exported file is opened — it is neutralised by prefixing a single
 * leading apostrophe, which forces spreadsheet apps to treat the cell as
 * literal text. This is the shared exporter used by every app's CSV
 * export, so the fix protects all of them at once.
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

        // 📋 Write header row (CWE-1236: same formula-cell neutralisation as data rows)
        if (count($headers) > 0) {
            fputcsv($output, array_map([self::class, 'neutraliseFormulaCell'], array_map('strval', $headers)), ',', '"', '');
        }

        // 📋 Write data rows
        foreach ($rows as $row) {
            if (count($headers) > 0) {
                // 📋 Map values to header order (handles missing keys gracefully)
                $orderedRow = [];
                foreach ($headers as $key) {
                    // 🛡️ CWE-1236: neutralise a leading formula/DDE trigger character
                    //     before the value is placed into the row for fputcsv().
                    $orderedRow[] = self::neutraliseFormulaCell((string) ($row[$key] ?? ''));
                }
                fputcsv($output, $orderedRow, ',', '"', '');
            } else {
                // 🛡️ CWE-1236: same neutralisation for the no-explicit-headers path.
                fputcsv($output, array_map(
                    static fn ($value): string => self::neutraliseFormulaCell((string) $value),
                    array_values($row)
                ), ',', '"', '');
            }
        }

        fclose($output);
        exit();
    }

    /**
     * 🛡️ CSV/formula/DDE injection guard (CWE-1236).
     *
     * Spreadsheet applications (Excel, LibreOffice Calc, Google Sheets)
     * treat a cell as a formula/DDE expression when it opens with one of
     * = + - @ TAB or CR — a stored value like `=HYPERLINK(...)` or a
     * legacy `=cmd|'/c calc'!A1` DDE payload then executes for whoever
     * opens the exported CSV. Prefixing a single leading apostrophe forces
     * every spreadsheet app to render the cell as literal text instead,
     * without altering the value for any other consumer (CSV re-import,
     * plain-text viewers). An empty string has no first character and is
     * left untouched.
     *
     * @param string $value
     * @return string
     */
    private static function neutraliseFormulaCell(string $value): string
    {
        if ($value === '') {
            return $value;
        }
        if (strpbrk($value[0], "=+-@\t\r") !== false) {
            return "'" . $value;
        }
        return $value;
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
