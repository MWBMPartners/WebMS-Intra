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
     * Render a year-end statement PDF for one donor over an arbitrary
     * `$fromDate`…`$toDate` range. Returns the saved file path, or false
     * on failure.
     *
     * SAME renderer for both the self-service page (my-statement.php) and
     * the treasurer's bulk batch (giving/statements-generate.php) — both
     * paths call this exact function so their output is byte-identical by
     * construction (gap #4 / #440 build plan §3.2).
     *
     * 🛡️ Donor lookup is scoped to THIS site: a donor renders here only if
     * they are a currently-active member of $siteId OR have at least one
     * giving entry recorded under $siteId. The first arm covers the normal
     * case (including a member who has never yet given anything, which the
     * old unscoped query also allowed); the second arm deliberately lets a
     * treasurer pull a single historical statement for a donor who has
     * since left the site (#440 Q3) without reopening the "any userID
     * renders for any site" hole the original query had — a donor with
     * neither an active membership NOR any recorded gift at this site can
     * never match either arm, so cross-tenant rendering stays impossible.
     */
    public static function renderStatementPdf(int $siteId, int $donorId, string $fromDate, string $toDate, string $periodLabel): string|false
    {
        // 🛡️ Reject anything that isn't a real, ordered date range before
        // it touches SQL or a filename — statementPeriodKey() below trusts
        // its callers to have already done this.
        $fromTs = strtotime($fromDate);
        $toTs   = strtotime($toDate);
        if ($fromTs === false || $toTs === false || $fromTs > $toTs) {
            return false;
        }

        $db = App::db();
        $donor = null;
        $stmt = $db->prepare(
            'SELECT u.userID, u.fullName, u.emailAddress FROM tblUsers u '
            . 'WHERE u.userID = ? AND ('
            . '  EXISTS (SELECT 1 FROM tblUserSites us WHERE us.userID = u.userID AND us.siteID = ? AND us.isActive = 1)'
            . '  OR EXISTS (SELECT 1 FROM tblGivingEntry e WHERE e.donorID = u.userID AND e.siteID = ?)'
            . ') LIMIT 1'
        );
        if ($stmt !== false) {
            $stmt->bind_param('iii', $donorId, $siteId, $siteId);
            $stmt->execute();
            $donor = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        if ($donor === null) {
            return false;
        }

        $entries = [];
        $stmt = $db->prepare(
            'SELECT e.donatedAt, e.amountPence, e.currency, e.method, e.reference, '
            . '       c.name AS categoryName, '
            . '       EXISTS (SELECT 1 FROM tblGiftAidDeclaration d '
            . '               WHERE d.siteID = e.siteID AND d.donorID = e.donorID AND d.status = "active" '
            . '                 AND d.validFrom <= e.donatedAt AND (d.validTo IS NULL OR d.validTo >= e.donatedAt)'
            . '              ) AS giftAidEligible '
            . 'FROM tblGivingEntry e INNER JOIN tblGivingCategory c ON c.categoryID = e.categoryID '
            . 'WHERE e.siteID = ? AND e.donorID = ? AND e.donatedAt BETWEEN ? AND ? '
            . 'ORDER BY e.donatedAt, e.entryID'
        );
        if ($stmt !== false) {
            $stmt->bind_param('iiss', $siteId, $donorId, $fromDate, $toDate);
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
        $giftAidTotal = 0;
        foreach ($entries as $e) {
            $amount = (int) $e['amountPence'];
            $total += $amount;
            // 🎁 Gift Aid eligibility per entry, computed above via EXISTS
            // (never a JOIN against tblGiftAidDeclaration) — an overlapping
            // pair of active declarations can never double-count a single
            // entry's amount into this sum, mirroring buildHmrcCsv()'s own
            // known JOIN hazard note above.
            if ((int) $e['giftAidEligible'] === 1) {
                $giftAidTotal += $amount;
            }
        }

        $html = '<html><head><meta charset="utf-8"><style>'
            . 'body{font-family:Helvetica,Arial,sans-serif;color:#1b2330;margin:0;padding:24px;}'
            . 'h1{font-size:20px;margin:0 0 4px;}'
            . '.muted{color:#6b7280;font-size:12px;}'
            . 'table{width:100%;border-collapse:collapse;margin-top:16px;font-size:12px;}'
            . 'th,td{border-bottom:1px solid #e5e7eb;padding:6px 8px;text-align:left;}'
            . 'th{background:#f3f4f6;}'
            . '.right{text-align:right;}'
            . '.center{text-align:center;}'
            . '.total{font-weight:bold;font-size:14px;margin-top:12px;}'
            . '</style></head><body>'
            . '<h1>Giving statement — ' . $esc($periodLabel) . '</h1>'
            . '<div class="muted">'
            . ($charityName !== '' ? $esc($charityName) : '')
            . ($charityNo   !== '' ? ' · Charity ' . $esc($charityNo) : '')
            . '</div>'
            . '<p>Recipient: <strong>' . $esc((string) $donor['fullName']) . '</strong>'
            . ' &middot; ' . $esc((string) ($donor['emailAddress'] ?? ''))
            . '</p>'
            . '<table><thead><tr>'
            . '<th>Date</th><th>Category</th><th>Method</th><th>Reference</th><th class="center">Gift Aid</th><th class="right">Amount</th>'
            . '</tr></thead><tbody>';
        foreach ($entries as $e) {
            $html .= '<tr>'
                . '<td>' . $esc(date('d/m/Y', (int) strtotime((string) $e['donatedAt']))) . '</td>'
                . '<td>' . $esc((string) $e['categoryName']) . '</td>'
                . '<td>' . $esc((string) $e['method']) . '</td>'
                . '<td>' . $esc((string) ($e['reference'] ?? '')) . '</td>'
                . '<td class="center">' . ((int) $e['giftAidEligible'] === 1 ? 'Yes' : '–') . '</td>'
                . '<td class="right">' . $esc(self::formatAmount((int) $e['amountPence'], (string) ($e['currency'] ?? $currency))) . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table>'
            // 🎁 Gift-Aid-eligible SUM only — deliberately NO projected 25%
            // reclaim figure (#440 Q2 default): the reclaim is the
            // charity's own figure, calculated and claimed via the
            // existing HMRC CSV export, not a donor-facing estimate.
            . '<p class="total right">Total given: ' . $esc(self::formatAmount($total, $currency))
            . ' &middot; of which Gift Aid eligible: ' . $esc(self::formatAmount($giftAidTotal, $currency)) . '</p>'
            . '<p class="muted" style="margin-top:32px;">'
            . 'This statement is for your records. Please retain a copy for tax purposes. '
            . 'The "Gift Aid eligible" figure is the portion of your giving covered by an active Gift Aid '
            . 'declaration on file — it is not the amount reclaimed, which is calculated and claimed by the charity.'
            . '</p></body></html>';

        // 📁 Namespaced by siteID + periodKey — the old flat
        // statement-{donor}-{year}.pdf naming let the same donor in two
        // sites overwrite one file with the other site's data (#440).
        $periodKey = self::statementPeriodKey($fromDate, $toDate);
        $dir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'giving'
             . DIRECTORY_SEPARATOR . 'statements' . DIRECTORY_SEPARATOR . (string) $siteId;
        if (is_dir($dir) === false) {
            mkdir($dir, 0755, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . 'statement-' . $donorId . '-' . $periodKey . '.pdf';
        return Pdf::create($html, $path);
    }

    /**
     * Canonical `{from}_{to}` key used by filenames, tblGivingStatementLog,
     * and dedupe. Callers MUST validate the dates first (see
     * renderStatementPdf() above) — this is a pure string builder, not a
     * second validation pass.
     */
    public static function statementPeriodKey(string $from, string $to): string
    {
        return $from . '_' . $to;
    }

    /**
     * Human-readable period label for a from/to range — "2025" for a full
     * calendar year, otherwise "d M Y – d M Y". Used for PDF headings and
     * email subject placeholders.
     */
    public static function periodLabel(string $from, string $to): string
    {
        $fromTs = strtotime($from);
        $toTs   = strtotime($to);
        if ($fromTs === false || $toTs === false) {
            return $from . ' – ' . $to;
        }
        $isCalendarYear = date('m-d', $fromTs) === '01-01'
            && date('m-d', $toTs) === '12-31'
            && date('Y', $fromTs) === date('Y', $toTs);
        if ($isCalendarYear === true) {
            return date('Y', $fromTs);
        }
        return date('j M Y', $fromTs) . ' – ' . date('j M Y', $toTs);
    }

    /**
     * Per-donor preview for the bulk statements page — site-scoped totals,
     * Gift Aid eligible sum (via EXISTS, never a declaration JOIN, so
     * overlapping declarations can't double-count — same rule as
     * buildHmrcCsv() above), opt-out flag, and this period's log status.
     *
     * Donors with no active tblUserSites row for this site (#440 Q3 —
     * "left the site but gave during the period") are INCLUDED here
     * (flagged `usActive` = false) so the treasurer can still see and
     * individually download their statement; they are simply never
     * queued for bulk email (enforced separately by the email handler).
     *
     * @return array<int, array{donorID:int, fullName:string, emailAddress:string,
     *   usActive:bool, optedOut:bool, entryCount:int, totalPence:int,
     *   giftAidPence:int, pdfPath:?string, queuedAt:?string, emailedAt:?string,
     *   emailedTo:?string, errorMsg:?string}>
     */
    public static function statementsPreview(int $siteId, string $from, string $to): array
    {
        $db = App::db();
        $rows = [];
        $stmt = $db->prepare(
            'SELECT e.donorID, u.fullName, u.emailAddress, u.notifyPrefs, '
            . '       MAX(us.userID IS NOT NULL) AS usActive, '
            . '       COUNT(*) AS entryCount, '
            . '       SUM(e.amountPence) AS totalPence, '
            . '       SUM(CASE WHEN EXISTS ('
            . '             SELECT 1 FROM tblGiftAidDeclaration d '
            . '             WHERE d.siteID = e.siteID AND d.donorID = e.donorID AND d.status = "active" '
            . '               AND d.validFrom <= e.donatedAt AND (d.validTo IS NULL OR d.validTo >= e.donatedAt)'
            . '           ) THEN e.amountPence ELSE 0 END) AS giftAidPence '
            . 'FROM tblGivingEntry e '
            . 'INNER JOIN tblUsers u ON u.userID = e.donorID '
            . 'LEFT JOIN tblUserSites us ON us.userID = e.donorID AND us.siteID = e.siteID AND us.isActive = 1 '
            . 'WHERE e.siteID = ? AND e.donorID IS NOT NULL AND e.donatedAt BETWEEN ? AND ? '
            . 'GROUP BY e.donorID, u.fullName, u.emailAddress, u.notifyPrefs '
            . 'HAVING SUM(e.amountPence) > 0 '
            . 'ORDER BY u.fullName '
            . 'LIMIT 2000'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('iss', $siteId, $from, $to);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $rows[(int) $r['donorID']] = [
                'donorID'      => (int) $r['donorID'],
                'fullName'     => (string) $r['fullName'],
                'emailAddress' => (string) ($r['emailAddress'] ?? ''),
                'usActive'     => (int) $r['usActive'] === 1,
                'optedOut'     => self::isOptedOutOfStatements((string) ($r['notifyPrefs'] ?? '')),
                'entryCount'   => (int) $r['entryCount'],
                'totalPence'   => (int) $r['totalPence'],
                'giftAidPence' => (int) $r['giftAidPence'],
                'pdfPath'      => null,
                'queuedAt'     => null,
                'emailedAt'    => null,
                'emailedTo'    => null,
                'errorMsg'     => null,
            ];
        }
        $stmt->close();

        if (count($rows) === 0) {
            return [];
        }

        // 🔗 Keyed lookup against this period's log rows rather than a SQL
        // JOIN — keeps the aggregate above simple and avoids re-deriving
        // periodKey inside SQL.
        $periodKey = self::statementPeriodKey($from, $to);
        $stmt = $db->prepare(
            'SELECT donorID, pdfPath, queuedAt, emailedAt, emailedTo, errorMsg '
            . 'FROM tblGivingStatementLog WHERE siteID = ? AND periodKey = ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('is', $siteId, $periodKey);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                $did = (int) $r['donorID'];
                if (isset($rows[$did]) === true) {
                    $rows[$did]['pdfPath']   = $r['pdfPath']   !== null ? (string) $r['pdfPath']   : null;
                    $rows[$did]['queuedAt']  = $r['queuedAt']  !== null ? (string) $r['queuedAt']  : null;
                    $rows[$did]['emailedAt'] = $r['emailedAt'] !== null ? (string) $r['emailedAt'] : null;
                    $rows[$did]['emailedTo'] = $r['emailedTo'] !== null ? (string) $r['emailedTo'] : null;
                    $rows[$did]['errorMsg']  = $r['errorMsg']  !== null ? (string) $r['errorMsg']  : null;
                }
            }
            $stmt->close();
        }

        return array_values($rows);
    }

    /**
     * Decode a tblUsers.notifyPrefs JSON blob and return whether the donor
     * has explicitly opted OUT of the `givingStatements` channel. Absent
     * key (or malformed JSON) defaults to opted-IN, matching every other
     * notifyPrefs key's convention (auth/account/notifications.php).
     */
    private static function isOptedOutOfStatements(string $notifyPrefsJson): bool
    {
        if ($notifyPrefsJson === '') {
            return false;
        }
        $prefs = json_decode($notifyPrefsJson, true);
        if (is_array($prefs) === false) {
            return false;
        }
        return array_key_exists('givingStatements', $prefs) === true && $prefs['givingStatements'] === false;
    }

    /**
     * Count + total of this period's gifts that have NO member donor
     * (anonymous cash, or a free-text `donorName` that didn't resolve to a
     * member) — #440 Q6 out-of-scope for statements, surfaced only as an
     * informational line on the bulk page.
     *
     * @return array{count:int, totalPence:int}
     */
    public static function statementsExcludedSummary(int $siteId, string $from, string $to): array
    {
        $db = App::db();
        $count = 0;
        $totalPence = 0;
        $stmt = $db->prepare(
            'SELECT COUNT(*), COALESCE(SUM(amountPence), 0) FROM tblGivingEntry '
            . 'WHERE siteID = ? AND donorID IS NULL AND donatedAt BETWEEN ? AND ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('iss', $siteId, $from, $to);
            $stmt->execute();
            $stmt->bind_result($count, $totalPence);
            $stmt->fetch();
            $stmt->close();
        }
        return ['count' => (int) $count, 'totalPence' => (int) $totalPence];
    }

    /**
     * Upsert one tblGivingStatementLog row per eligible donor for this
     * period (totals refreshed even when the row already exists — a
     * correction to a giving entry flows through on the next "Generate"
     * click). Never touches `pdfPath`/`queuedAt`/`emailedAt` — those are
     * the render/send steps' job, so dedupe survives a totals refresh.
     *
     * @return int Number of donor rows upserted.
     */
    public static function upsertStatementLogRows(int $siteId, string $from, string $to, ?int $createdByID): int
    {
        $donors = self::statementsPreview($siteId, $from, $to);
        if (count($donors) === 0) {
            return 0;
        }
        $periodKey = self::statementPeriodKey($from, $to);
        $db = App::db();
        $stmt = $db->prepare(
            'INSERT INTO tblGivingStatementLog '
            . '(siteID, donorID, periodKey, fromDate, toDate, totalPence, giftAidPence, createdByID) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE totalPence = VALUES(totalPence), giftAidPence = VALUES(giftAidPence), updatedAt = NOW()'
        );
        if ($stmt === false) {
            return 0;
        }
        $count = 0;
        foreach ($donors as $d) {
            $donorId      = (int) $d['donorID'];
            $totalPence   = (int) $d['totalPence'];
            $giftAidPence = (int) $d['giftAidPence'];
            $stmt->bind_param(
                'iisssiii',
                $siteId,
                $donorId,
                $periodKey,
                $from,
                $to,
                $totalPence,
                $giftAidPence,
                $createdByID
            );
            if ($stmt->execute() === true) {
                $count++;
            }
        }
        $stmt->close();
        return $count;
    }

    /**
     * Render up to `$batchCap` still-missing PDFs for this (site, period) —
     * the "Generate" step's actual work, called repeatedly (Newsletter-
     * dispatch pattern, Newsletter::dispatch()) until nothing remains.
     * `$regenerate = true` first nulls every pdfPath for the period (a
     * post-correction refresh) — it never touches emailedAt, so dedupe
     * survives a regenerate. A row whose render fails gets `errorMsg` set
     * and is excluded from the next batch's selection (manual retry only —
     * never auto-retried, so a systemic failure can't loop forever).
     *
     * @return array{rendered:int, failed:int, remaining:int}
     */
    public static function renderStatementBatch(int $siteId, string $from, string $to, int $batchCap, bool $regenerate): array
    {
        $db = App::db();
        $periodKey   = self::statementPeriodKey($from, $to);
        $periodLabel = self::periodLabel($from, $to);

        if ($regenerate === true) {
            $u = $db->prepare('UPDATE tblGivingStatementLog SET pdfPath = NULL WHERE siteID = ? AND periodKey = ?');
            if ($u !== false) {
                $u->bind_param('is', $siteId, $periodKey);
                $u->execute();
                $u->close();
            }
        }

        $pending = [];
        $stmt = $db->prepare(
            'SELECT logID, donorID FROM tblGivingStatementLog '
            . 'WHERE siteID = ? AND periodKey = ? AND pdfPath IS NULL AND errorMsg IS NULL '
            . 'ORDER BY donorID LIMIT ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('isi', $siteId, $periodKey, $batchCap);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                $pending[] = $r;
            }
            $stmt->close();
        }

        $rendered = 0;
        $failed   = 0;
        foreach ($pending as $p) {
            $logId   = (int) $p['logID'];
            $donorId = (int) $p['donorID'];
            $path    = self::renderStatementPdf($siteId, $donorId, $from, $to, $periodLabel);
            if ($path !== false) {
                $u = $db->prepare('UPDATE tblGivingStatementLog SET pdfPath = ?, errorMsg = NULL WHERE logID = ?');
                if ($u !== false) {
                    $u->bind_param('si', $path, $logId);
                    $u->execute();
                    $u->close();
                }
                $rendered++;
            } else {
                $errMsg = 'pdf-render-failed';
                $u = $db->prepare('UPDATE tblGivingStatementLog SET errorMsg = ? WHERE logID = ?');
                if ($u !== false) {
                    $u->bind_param('si', $errMsg, $logId);
                    $u->execute();
                    $u->close();
                }
                $failed++;
            }
        }

        $remaining = 0;
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM tblGivingStatementLog WHERE siteID = ? AND periodKey = ? AND pdfPath IS NULL AND errorMsg IS NULL'
        );
        if ($stmt !== false) {
            $stmt->bind_param('is', $siteId, $periodKey);
            $stmt->execute();
            $stmt->bind_result($remaining);
            $stmt->fetch();
            $stmt->close();
        }

        return ['rendered' => $rendered, 'failed' => $failed, 'remaining' => (int) $remaining];
    }

    /**
     * Email one already-rendered statement to its donor, re-validating
     * everything live (never trusting whatever was true at queue time):
     * current email address, current opt-out preference, and that the PDF
     * still exists and is small enough for Mailer to actually attach
     * (`Mailer::attach()` silently DROPS attachments over 4 MB — turned
     * into an explicit row failure here so a statement is never emailed
     * bodiless without the PDF). Shared by both the interactive handler
     * (giving/statements-email.php) and the optional cron sweeper
     * (cron/giving-statements.php) so there is exactly one send code path.
     * Recipient is ALWAYS read fresh from tblUsers by `$logRow['donorID']`
     * — never anything POSTed.
     *
     * @param array{logID:int, siteID:int, donorID:int, pdfPath:string,
     *   fromDate:string, toDate:string} $logRow
     */
    public static function sendStatementEmail(array $logRow): bool
    {
        $db      = App::db();
        $logId   = (int) $logRow['logID'];
        $siteId  = (int) $logRow['siteID'];
        $donorId = (int) $logRow['donorID'];
        $pdfPath = (string) $logRow['pdfPath'];

        $donor = null;
        $stmt = $db->prepare('SELECT fullName, emailAddress, notifyPrefs FROM tblUsers WHERE userID = ? LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('i', $donorId);
            $stmt->execute();
            $donor = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        if ($donor === null) {
            self::markStatementError($logId, 'donor-not-found');
            return false;
        }

        // 📧 tblUsers.emailAddress — never `u.email` (that spelling is a
        // pre-existing bug elsewhere in the codebase, not a convention).
        $email = trim((string) ($donor['emailAddress'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            self::markStatementError($logId, 'invalid-email');
            return false;
        }
        // 🔕 Re-check the opt-out at SEND time, not just preview/queue
        // time — a donor may have flipped the switch in between.
        if (self::isOptedOutOfStatements((string) ($donor['notifyPrefs'] ?? '')) === true) {
            self::markStatementError($logId, 'opted-out');
            return false;
        }

        // 🚪 SEC-01 — re-check LIVE active membership at SEND time, not
        // just whatever was true when this row was queued. A donor who
        // has since left the site is preview/download-only per
        // statementsPreview()'s own contract (#440 Q3 — "left the site
        // but gave during the period" rows are flagged `usActive = false`
        // and never auto-emailed); download stays unaffected — this gate
        // sits only in the send path, and runs BEFORE the SEC-03 claim
        // below so a row that will not be sent is never claimed.
        $stillActive = 0;
        $stmt = $db->prepare(
            'SELECT EXISTS(SELECT 1 FROM tblUserSites WHERE userID = ? AND siteID = ? AND isActive = 1)'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $donorId, $siteId);
            $stmt->execute();
            $stmt->bind_result($stillActive);
            $stmt->fetch();
            $stmt->close();
        }
        if ((int) $stillActive !== 1) {
            self::markStatementError($logId, 'donor-left-site');
            return false;
        }

        if ($pdfPath === '' || is_file($pdfPath) === false) {
            self::markStatementError($logId, 'pdf-missing');
            return false;
        }
        if (filesize($pdfPath) > 4 * 1024 * 1024) {
            self::markStatementError($logId, 'pdf-too-large');
            return false;
        }

        // ⚛️ SEC-03 — claim-then-send. Only the caller that wins this
        // `WHERE ... AND emailedAt IS NULL` UPDATE proceeds to actually
        // mail the donor; a concurrent UI+cron race on the same row loses
        // here (affected_rows !== 1) and does nothing — no double email.
        // Mirrors Payments::markPaymentSucceeded()'s atomic
        // `WHERE status = "pending"` transition. If Mailer then fails,
        // the claim is rolled back (emailedAt reset to NULL) so the row
        // remains eligible for a future retry.
        $claimed = 0;
        $claim = $db->prepare('UPDATE tblGivingStatementLog SET emailedAt = NOW() WHERE logID = ? AND emailedAt IS NULL');
        if ($claim !== false) {
            $claim->bind_param('i', $logId);
            $claim->execute();
            $claimed = $claim->affected_rows;
            $claim->close();
        }
        if ($claimed !== 1) {
            // Another caller already claimed (or already sent) this row.
            return false;
        }

        // 🌍 Per-site settings read via settingForSite(), NOT
        // App::settings() — this function is also called from the cron
        // sweeper looping across every site in one process, where the
        // bootstrap $SETTINGS snapshot reflects only the FIRST resolved
        // site of that request (venues/asset cron precedent —
        // cron/venue-reminders.php's own header note).
        $charityName = (string) (App::settingForSite('giving.charityName', $siteId) ?? '');
        $charityNo   = (string) (App::settingForSite('giving.charityNumber', $siteId) ?? '');
        $subjectTpl  = (string) (App::settingForSite('giving.statements.emailSubject', $siteId) ?? 'Your {year} giving statement');

        $periodLabel = self::periodLabel((string) $logRow['fromDate'], (string) $logRow['toDate']);
        $year        = date('Y', (int) strtotime((string) $logRow['toDate']));
        $subject     = str_replace(['{year}', '{period}', '{charity}'], [$year, $periodLabel, $charityName], $subjectTpl);

        $fullName  = (string) $donor['fullName'];
        $firstName = trim((string) (explode(' ', $fullName)[0] ?? ''));

        $vars = [
            'donorName'     => $fullName,
            'firstName'     => $firstName !== '' ? $firstName : $fullName,
            'periodLabel'   => $periodLabel,
            'charityName'   => $charityName,
            'charityNumber' => $charityNo,
            'portalUrl'     => self::absoluteUrl('/giving'),
        ];

        try {
            $ok = Mailer::sendTemplated($email, $subject, 'giving-statement', $vars, [$pdfPath]);
        } catch (\Throwable $e) {
            Logger::errorPlatform('GivingStatements', 'Error', 'EMAIL_SEND_FAIL', 'Failed to email giving statement', $e->getMessage());
            self::rollbackStatementClaim($logId, 'send-exception');
            return false;
        }
        if ($ok !== true) {
            self::rollbackStatementClaim($logId, 'send-failed');
            return false;
        }

        // ✅ emailedAt was already stamped by the SEC-03 claim above —
        // only the actually-mailed address remains to record.
        $u = $db->prepare('UPDATE tblGivingStatementLog SET emailedTo = ? WHERE logID = ?');
        if ($u !== false) {
            $u->bind_param('si', $email, $logId);
            $u->execute();
            $u->close();
        }
        return true;
    }

    /**
     * Record a generate/send failure on one log row. Manual-retry-only —
     * nothing in this file ever clears `errorMsg` automatically; a
     * treasurer must explicitly retry (see giving/statements-generate.php).
     */
    private static function markStatementError(int $logId, string $message): void
    {
        $db = App::db();
        $stmt = $db->prepare('UPDATE tblGivingStatementLog SET errorMsg = ? WHERE logID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('si', $message, $logId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * SEC-03 rollback — undo a claim-then-send claim (sendStatementEmail())
     * when Mailer actually fails AFTER the atomic claim already stamped
     * `emailedAt`. Resets it back to NULL so the row's claim predicate
     * (`emailedAt IS NULL`) makes it eligible for a future retry, instead
     * of being permanently (and falsely) stuck looking "sent".
     */
    private static function rollbackStatementClaim(int $logId, string $message): void
    {
        $db = App::db();
        $stmt = $db->prepare('UPDATE tblGivingStatementLog SET emailedAt = NULL, errorMsg = ? WHERE logID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('si', $message, $logId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Absolute URL builder for statement emails — scheme+host resolution
     * mirrors `venueReminderUrl()` in cron/venue-reminders.php. $_SERVER
     * values come from the webserver/connection itself, never a request
     * body.
     */
    private static function absoluteUrl(string $path): string
    {
        $https  = (string) ($_SERVER['HTTPS'] ?? '');
        $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host . $path;
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
