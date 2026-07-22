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

    // 🎯 -------------------------------------------------------------------
    // Pledge campaigns (#299 sub-feature 2)
    // -------------------------------------------------------------------

    /**
     * Resolve which campaign (and, more specifically, which pledge) a gift
     * should be attributed to. This is the ONLY code path allowed to produce
     * a `[campaignID, pledgeID]` pair — every writer of `tblGivingEntry` that
     * wants auto-attribution must call this and bind its result, never
     * derive the pair itself. Pure lookup, no writes.
     *
     * `$campaignSel` carries the treasurer's (or count-close's) choice:
     *   -1  → explicit "None" — never attribute this gift to any campaign.
     *    0  → "Auto" — attribute only if the donor holds exactly one open
     *         pledge to a campaign that is currently active and within its
     *         date window; two-or-more matches is a genuine tie, and money
     *         is never guessed into a bucket, so it resolves to no
     *         attribution (the treasurer must pick explicitly instead).
     *   >0  → an explicit campaign choice. Honoured even if the campaign is
     *         inactive or outside its date window (a deliberate treasurer
     *         override — e.g. a late cheque for a campaign that has since
     *         ended) PROVIDED the campaign belongs to this site; an invalid
     *         (wrong-site or non-existent) selection is treated as Auto.
     *
     * Anonymous gifts (`$donorId === null`) never auto-attribute (rule 0
     * needs a donor to look up pledges for) but CAN still receive an
     * explicit `campaignID` (with `pledgeID` left NULL — there is no member
     * pledge to credit).
     *
     * @return array{campaignID: ?int, pledgeID: ?int}
     */
    public static function attributeGift(int $siteId, ?int $donorId, string $donatedAt, int $campaignSel = 0): array
    {
        if ($campaignSel === -1) {
            return ['campaignID' => null, 'pledgeID' => null];
        }

        $db = App::db();

        // 🖊️ Explicit treasurer choice — validate it belongs to this site.
        if ($campaignSel > 0) {
            $explicitCampaignId = null;
            $stmt = $db->prepare('SELECT campaignID FROM tblPledgeCampaigns WHERE campaignID = ? AND siteID = ? LIMIT 1');
            if ($stmt !== false) {
                $stmt->bind_param('ii', $campaignSel, $siteId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row !== null) {
                    $explicitCampaignId = (int) $row['campaignID'];
                }
            }

            if ($explicitCampaignId !== null) {
                $pledgeId = null;
                if ($donorId !== null) {
                    $stmt = $db->prepare(
                        'SELECT pledgeID FROM tblPledges '
                        . 'WHERE siteID = ? AND userID = ? AND campaignID = ? AND status = \'open\' LIMIT 1'
                    );
                    if ($stmt !== false) {
                        $stmt->bind_param('iii', $siteId, $donorId, $explicitCampaignId);
                        $stmt->execute();
                        $prow = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if ($prow !== null) {
                            $pledgeId = (int) $prow['pledgeID'];
                        }
                    }
                }
                return ['campaignID' => $explicitCampaignId, 'pledgeID' => $pledgeId];
            }
            // Invalid (wrong-site or non-existent) explicit selection — fall
            // through to Auto below rather than silently discard the gift's
            // attribution.
        }

        // 🤖 Auto — only ever fires for a known donor.
        if ($donorId === null) {
            return ['campaignID' => null, 'pledgeID' => null];
        }

        $matches = [];
        $stmt = $db->prepare(
            'SELECT p.pledgeID, p.campaignID '
            . 'FROM tblPledges p '
            . 'INNER JOIN tblPledgeCampaigns c ON c.campaignID = p.campaignID '
            . 'WHERE p.siteID = ? AND p.userID = ? AND p.status = \'open\' '
            . '  AND c.isActive = 1 AND c.startDate <= ? '
            . '  AND (c.endDate IS NULL OR c.endDate >= ?)'
        );
        if ($stmt !== false) {
            $stmt->bind_param('iiss', $siteId, $donorId, $donatedAt, $donatedAt);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                $matches[] = $r;
            }
            $stmt->close();
        }

        if (count($matches) === 1) {
            return [
                'campaignID' => (int) $matches[0]['campaignID'],
                'pledgeID'   => (int) $matches[0]['pledgeID'],
            ];
        }

        // Zero matches (nothing open right now) or 2+ matches (an
        // unresolvable tie between multiple open pledges) both decline —
        // never guess where the money goes.
        return ['campaignID' => null, 'pledgeID' => null];
    }

    /**
     * How much a pledge is expected to have raised by `$asOf`, in integer
     * pence, given its per-instalment `$amountPence` and `$schedule`.
     *
     * Semantics (deliberate, see #299 build notes):
     *   - A one-off pledge owes its FULL amount from day one of its window
     *     (`periodsElapsed = 1`) — it counts as "behind" until paid in full.
     *   - A weekly/monthly pledge owes its first instalment as soon as its
     *     window opens (the `+ 1`), not after a full period has elapsed.
     *   - Monthly uses calendar-month arithmetic (`Y*12 + n` difference),
     *     never `daysElapsed / 30` — that would drift against real month
     *     boundaries (28-31 days).
     *
     * `$pledgeStart` = max(campaign.startDate, DATE(pledge.createdAt)) and
     * `$asOf` = min(today, campaign.endDate ?? today) — both computed by the
     * caller (they need the campaign row, which this helper deliberately
     * does not fetch, keeping it a pure date/amount function).
     */
    public static function pledgeExpectedToDate(int $amountPence, string $schedule, string $pledgeStart, string $asOf): int
    {
        $startTs = strtotime($pledgeStart);
        $asOfTs  = strtotime($asOf);
        if ($startTs === false || $asOfTs === false || $asOfTs < $startTs) {
            // Not started yet (or unparseable dates) — nothing owed yet.
            return 0;
        }

        $daysElapsed = max(0, intdiv($asOfTs - $startTs, 86400));

        $periodsElapsed = match ($schedule) {
            'one-off' => 1,
            'weekly'  => intdiv($daysElapsed, 7) + 1,
            'monthly' => (
                ((int) date('Y', $asOfTs) * 12 + (int) date('n', $asOfTs))
                - ((int) date('Y', $startTs) * 12 + (int) date('n', $startTs))
            ) + 1,
            default => 1,
        };

        return $amountPence * $periodsElapsed;
    }
}
