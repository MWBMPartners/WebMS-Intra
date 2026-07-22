<?php
// Path: _core/Giving.php
/**
 * -----------------------------------------------------------------------------
 * Giving / contributions helpers 💷
 * -----------------------------------------------------------------------------
 * Money formatting (minor-units → display), treasurer access check, HMRC
 * Gift Aid CSV emitter, year-end PDF statement renderer.
 *
 * Amounts are stored in PENCE (minor units) to avoid float drift — every
 * read/write goes through these helpers.
 *
 * @package   Portal\Core
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/266
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Giving
{
    public const ROLE_KEY = 'treasurer';

    /**
     * Whether the current user is allowed to see / record all entries
     * (treasurer-or-admin). Members without this can only see their own.
     */
    public static function canManage(): bool
    {
        return App::isAdmin() === true || App::hasRole(self::ROLE_KEY) === true;
    }

    /**
     * Convert a free-form user-entered amount (e.g. "12.50", "12", "£12.50")
     * into integer pence. Returns 0 for invalid input — caller validates.
     */
    public static function parseAmount(string $input): int
    {
        $clean = preg_replace('/[^0-9.\-]/', '', $input);
        if ($clean === '' || $clean === null) {
            return 0;
        }
        $f = (float) $clean;
        return (int) round($f * 100);
    }

    /**
     * Convert a free-form user-entered amount (e.g. "125.50", "£125", "")
     * into a validated non-negative DECIMAL(10,2) string suitable for
     * binding to a `DECIMAL` column — used by the offering-count workflow
     * (#299 sub-feature 1), which stores independent counter totals as
     * DECIMAL rather than pence. Returns null for blank/invalid/negative
     * input so the caller can distinguish "not entered yet" from "0.00".
     *
     * Deliberately round-trips through float → round → number_format
     * server-side rather than trusting the client string verbatim, so a
     * value like "12.999" or "1e2" never reaches SQL unnormalised.
     */
    public static function parseDecimal(string $input): ?string
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return null;
        }
        $clean = preg_replace('/[^0-9.\-]/', '', $trimmed);
        if ($clean === '' || $clean === null || is_numeric($clean) === false) {
            return null;
        }
        $f = (float) $clean;
        if ($f < 0) {
            return null;
        }
        return number_format(round($f, 2), 2, '.', '');
    }

    /**
     * Format pence as a currency display string. Default GBP — caller
     * passes the currency code from `giving.currency` or per-entry.
     */
    public static function formatAmount(int $pence, string $currency = 'GBP'): string
    {
        $symbol = match ($currency) {
            'GBP' => '£',
            'EUR' => '€',
            'USD' => '$',
            default => $currency . ' ',
        };
        return $symbol . number_format($pence / 100, 2);
    }

    /**
     * Build an HMRC Gift Aid Schedule-spreadsheet-compatible CSV for a
     * date range. Columns match the "Schedule spreadsheet" online claim
     * format: title, first name, last name, house name/number, postcode,
     * aggregated donations (Y/N), donation date, amount.
     *
     * Only includes entries from donors with an `active` declaration that
     * was valid on the donation date.
     *
     * @link https://www.gov.uk/claim-gift-aid-online
     */
    public static function buildHmrcCsv(int $siteId, string $fromDate, string $toDate): string
    {
        $db = App::db();
        $rows = [];
        $stmt = $db->prepare(
            'SELECT u.userID, u.fullName, u.emailAddress, '
            . '       e.donatedAt, e.amountPence, '
            . '       d.address, d.postcode '
            . 'FROM tblGivingEntry e '
            . 'INNER JOIN tblUsers u ON u.userID = e.donorID '
            . 'INNER JOIN tblGiftAidDeclaration d ON d.donorID = e.donorID '
            . '    AND d.siteID = e.siteID '
            . '    AND d.status = "active" '
            . '    AND d.validFrom <= e.donatedAt '
            . '    AND (d.validTo IS NULL OR d.validTo >= e.donatedAt) '
            . 'WHERE e.siteID = ? AND e.donatedAt BETWEEN ? AND ? '
            . 'ORDER BY u.fullName, e.donatedAt'
        );
        if ($stmt !== false) {
            $stmt->bind_param('iss', $siteId, $fromDate, $toDate);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                $rows[] = $r;
            }
            $stmt->close();
        }

        $out = fopen('php://temp', 'w+');
        fputcsv($out, [
            'Title', 'First name', 'Last name', 'House name or number',
            'Postcode', 'Aggregated donations', 'Sponsored event',
            'Donation date', 'Amount',
        ]);
        foreach ($rows as $r) {
            $name = trim((string) $r['fullName']);
            $parts = explode(' ', $name, 2);
            $first = $parts[0] ?? '';
            $last  = $parts[1] ?? '';
            $address = trim((string) ($r['address'] ?? ''));
            // House name/number = first comma-separated segment of address.
            $houseSeg = explode(',', $address);
            $house    = trim($houseSeg[0] ?? '');
            fputcsv($out, [
                '',
                $first,
                $last,
                $house,
                (string) ($r['postcode'] ?? ''),
                '',
                '',
                date('d/m/Y', (int) strtotime((string) $r['donatedAt'])),
                number_format(((int) $r['amountPence']) / 100, 2, '.', ''),
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv === false ? '' : (string) $csv;
    }

    /**
     * Render a year-end statement PDF for one donor. Returns the saved
     * file path, or false on failure. Path is suitable for download via
     * Pdf::create's saved location.
     */
    public static function renderStatementPdf(int $siteId, int $donorId, int $year): string|false
    {
        $db = App::db();
        $donor = null;
        $stmt = $db->prepare('SELECT userID, fullName, emailAddress FROM tblUsers WHERE userID = ? LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('i', $donorId);
            $stmt->execute();
            $donor = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        if ($donor === null) {
            return false;
        }

        $from = $year . '-01-01';
        $to   = $year . '-12-31';
        $entries = [];
        $stmt = $db->prepare(
            'SELECT e.donatedAt, e.amountPence, e.currency, e.method, e.reference, '
            . '       c.name AS categoryName '
            . 'FROM tblGivingEntry e INNER JOIN tblGivingCategory c ON c.categoryID = e.categoryID '
            . 'WHERE e.siteID = ? AND e.donorID = ? AND e.donatedAt BETWEEN ? AND ? '
            . 'ORDER BY e.donatedAt, e.entryID'
        );
        if ($stmt !== false) {
            $stmt->bind_param('iiss', $siteId, $donorId, $from, $to);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                $entries[] = $r;
            }
            $stmt->close();
        }

        $settings    = App::settings()['giving'] ?? [];
        $charityName = (string) ($settings['charityName'] ?? '');
        $charityNo   = (string) ($settings['charityNumber'] ?? '');
        $currency    = (string) ($settings['currency'] ?? 'GBP');

        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $total = 0;
        foreach ($entries as $e) {
            $total += (int) $e['amountPence'];
        }

        $html = '<html><head><meta charset="utf-8"><style>'
            . 'body{font-family:Helvetica,Arial,sans-serif;color:#1b2330;margin:0;padding:24px;}'
            . 'h1{font-size:20px;margin:0 0 4px;}'
            . '.muted{color:#6b7280;font-size:12px;}'
            . 'table{width:100%;border-collapse:collapse;margin-top:16px;font-size:12px;}'
            . 'th,td{border-bottom:1px solid #e5e7eb;padding:6px 8px;text-align:left;}'
            . 'th{background:#f3f4f6;}'
            . '.right{text-align:right;}'
            . '.total{font-weight:bold;font-size:14px;margin-top:12px;}'
            . '</style></head><body>'
            . '<h1>Giving statement — ' . $year . '</h1>'
            . '<div class="muted">'
            . ($charityName !== '' ? $esc($charityName) : '')
            . ($charityNo   !== '' ? ' · Charity ' . $esc($charityNo) : '')
            . '</div>'
            . '<p>Recipient: <strong>' . $esc((string) $donor['fullName']) . '</strong>'
            . ' &middot; ' . $esc((string) ($donor['emailAddress'] ?? ''))
            . '</p>'
            . '<table><thead><tr>'
            . '<th>Date</th><th>Category</th><th>Method</th><th>Reference</th><th class="right">Amount</th>'
            . '</tr></thead><tbody>';
        foreach ($entries as $e) {
            $html .= '<tr>'
                . '<td>' . $esc(date('d/m/Y', (int) strtotime((string) $e['donatedAt']))) . '</td>'
                . '<td>' . $esc((string) $e['categoryName']) . '</td>'
                . '<td>' . $esc((string) $e['method']) . '</td>'
                . '<td>' . $esc((string) ($e['reference'] ?? '')) . '</td>'
                . '<td class="right">' . $esc(self::formatAmount((int) $e['amountPence'], (string) ($e['currency'] ?? $currency))) . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table>'
            . '<p class="total right">Total: ' . $esc(self::formatAmount($total, $currency)) . '</p>'
            . '<p class="muted" style="margin-top:32px;">'
            . 'This statement is for your records. Please retain a copy for tax purposes.'
            . '</p></body></html>';

        $dir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'giving';
        if (is_dir($dir) === false) {
            mkdir($dir, 0755, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . 'statement-' . $donorId . '-' . $year . '.pdf';
        return Pdf::create($html, $path);
    }

    /**
     * Whether `userID` has an active Gift Aid declaration covering `onDate`.
     */
    public static function hasActiveDeclaration(int $siteId, int $userId, string $onDate): bool
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT 1 FROM tblGiftAidDeclaration '
            . 'WHERE siteID = ? AND donorID = ? AND status = "active" '
            . 'AND validFrom <= ? AND (validTo IS NULL OR validTo >= ?) LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iiss', $siteId, $userId, $onDate, $onDate);
        $stmt->execute();
        $ok = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $ok;
    }
}
