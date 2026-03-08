<?php
// Path: core/ExpenseMailer.php
/**
 * -----------------------------------------------------------------------------
 * Expense Email Notification Helper 📧
 * -----------------------------------------------------------------------------
 * Sends HTML email notifications at each stage of the expense workflow:
 *   - On submission: notify dept approvers + treasury
 *   - On approval: notify claimant + treasury
 *   - On rejection: notify claimant + approvers
 *   - On reimbursement: notify claimant + approvers
 *
 * Uses Mailer::send() (Microsoft Graph) with graceful fallback if
 * email is not configured (logs warning, does not throw).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.4.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class ExpenseMailer
{
    /**
     * 📧 Send notification for a workflow event.
     *
     * @param int    $claimID  The expense claim ID
     * @param string $event    One of: submitted, approved, rejected, reimbursed
     * @param array  $extra    Optional extra data (e.g. approver name, comments)
     */
    public static function notify(int $claimID, string $event, array $extra = []): void
    {
        global $mysqli, $SETTINGS;

        // 🔍 Check if email notifications are enabled
        if (($SETTINGS['expenses']['emailNotifications'] ?? 'false') !== 'true') {
            return;
        }

        // 🔍 Check if Mailer is configured (avoid throwing if not)
        $fromAddr = $SETTINGS['mail']['defaultFromAddress'] ?? '';
        if ($fromAddr === '') {
            Logger::errorPlatform('ExpenseMailer', 'Warning', 'NO_FROM', 'Mail from address not configured', '');
            return;
        }

        // 📋 Fetch claim details
        $siteId = Site::id();
        $stmt = $mysqli->prepare(
            'SELECT EC.*, U.fullName AS claimantName, U.emailAddress AS claimantEmail, D.deptName '
            . 'FROM tblExpenseClaims EC '
            . 'JOIN tblUsers U ON U.userID = EC.userID '
            . 'JOIN tblDepts D ON D.deptID = EC.deptID '
            . 'WHERE EC.claimID = ? AND EC.siteID = ? LIMIT 1'
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('ii', $claimID, $siteId);
        $stmt->execute();
        $claim = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($claim === null) {
            return;
        }

        // 📋 Build recipient lists and content based on event
        $recipients = [];
        $subject    = '';
        $body       = '';
        $pdfPath    = null;

        // 📧 Get the PDF path if it exists
        if ($claim['fileName'] !== null && $claim['fileName'] !== '') {
            $pdfPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR
                     . 'pdfs' . DIRECTORY_SEPARATOR . $claim['fileName'];
            if (is_file($pdfPath) === false) {
                $pdfPath = null;
            }
        }

        $siteName   = $SETTINGS['site']['name'] ?? 'Portal';
        $claimTitle = htmlspecialchars($claim['claimTitle'], ENT_QUOTES, 'UTF-8');
        $claimant   = htmlspecialchars($claim['claimantName'], ENT_QUOTES, 'UTF-8');
        $dept       = htmlspecialchars($claim['deptName'], ENT_QUOTES, 'UTF-8');
        $total      = '&pound;' . number_format((float) $claim['totalAmount'], 2);
        $claimDate  = date('j M Y', strtotime($claim['claimDate']));

        switch ($event) {
            case 'submitted':
                // 📧 Notify dept approvers + treasury
                $recipients = self::getApproverEmails((int) $claim['deptID']);
                $subject    = '[' . $siteName . '] New Expense Claim #' . $claimID . ' — ' . $claim['claimTitle'];
                $body       = self::buildHtml(
                    'New Expense Claim Submitted',
                    '<p>A new expense claim has been submitted and requires your review.</p>'
                    . self::claimSummaryHtml($claimID, $claimTitle, $claimant, $dept, $total, $claimDate)
                    . '<p><a href="/expenses/approve" style="' . self::btnStyle() . '">Review Claims</a></p>'
                );
                break;

            case 'approved':
                // 📧 Notify claimant + treasury
                $recipients   = [$claim['claimantEmail']];
                $recipients   = array_merge($recipients, self::getTreasuryEmails());
                $approverName = htmlspecialchars($extra['approverName'] ?? 'An approver', ENT_QUOTES, 'UTF-8');
                $subject      = '[' . $siteName . '] Expense Claim #' . $claimID . ' Approved';
                $body         = self::buildHtml(
                    'Expense Claim Approved',
                    '<p>Your expense claim has been <strong style="color:#198754">approved</strong> by ' . $approverName . '.</p>'
                    . self::claimSummaryHtml($claimID, $claimTitle, $claimant, $dept, $total, $claimDate)
                    . (($extra['comments'] ?? '') !== '' ? '<p><strong>Comments:</strong> ' . htmlspecialchars($extra['comments'], ENT_QUOTES, 'UTF-8') . '</p>' : '')
                    . '<p><a href="/expenses/view?id=' . $claimID . '" style="' . self::btnStyle() . '">View Claim</a></p>'
                );
                break;

            case 'rejected':
                // 📧 Notify claimant
                $recipients   = [$claim['claimantEmail']];
                $approverName = htmlspecialchars($extra['approverName'] ?? 'An approver', ENT_QUOTES, 'UTF-8');
                $subject      = '[' . $siteName . '] Expense Claim #' . $claimID . ' Not Approved';
                $body         = self::buildHtml(
                    'Expense Claim Not Approved',
                    '<p>Your expense claim has been <strong style="color:#dc3545">declined</strong> by ' . $approverName . '.</p>'
                    . self::claimSummaryHtml($claimID, $claimTitle, $claimant, $dept, $total, $claimDate)
                    . (($extra['comments'] ?? '') !== '' ? '<p><strong>Reason:</strong> ' . htmlspecialchars($extra['comments'], ENT_QUOTES, 'UTF-8') . '</p>' : '')
                    . '<p><a href="/expenses/view?id=' . $claimID . '" style="' . self::btnStyle() . '">View Claim</a></p>'
                );
                break;

            case 'reimbursed':
                // 📧 Notify claimant + approvers
                $recipients = [$claim['claimantEmail']];
                $recipients = array_merge($recipients, self::getApproverEmails((int) $claim['deptID']));
                $subject    = '[' . $siteName . '] Expense Claim #' . $claimID . ' Reimbursed';
                $followUp   = (int) ($SETTINGS['expenses']['followUpDays'] ?? 7);
                $body       = self::buildHtml(
                    'Expense Claim Reimbursed',
                    '<p>Expense claim #' . $claimID . ' has been reimbursed.</p>'
                    . self::claimSummaryHtml($claimID, $claimTitle, $claimant, $dept, $total, $claimDate)
                    . '<p>If you have not received payment within <strong>' . $followUp . ' working days</strong>, '
                    . 'please contact the treasury team.</p>'
                    . '<p><a href="/expenses/view?id=' . $claimID . '" style="' . self::btnStyle() . '">View Claim</a></p>'
                );
                break;

            default:
                return;
        }

        // 🔍 Filter out empty/invalid email addresses
        $recipients = array_filter(array_unique($recipients), function ($email) {
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        });

        if (count($recipients) === 0) {
            return;
        }

        // 📧 Send email (with PDF attachment if available)
        $attachments = [];
        if ($pdfPath !== null) {
            $attachments[] = $pdfPath;
        }

        try {
            Mailer::send(array_values($recipients), $subject, $body, $attachments);
            Logger::activity('ExpenseEmail', 'Sent ' . $event . ' email for claim #' . $claimID . ' to ' . count($recipients) . ' recipients');
        } catch (\Throwable $e) {
            // 🔇 Don't let email failure break the workflow
            Logger::errorPlatform('ExpenseMailer', 'Error', 'SEND_FAIL', 'Failed to send ' . $event . ' email for claim #' . $claimID, $e->getMessage());
        }
    }

    /**
     * 📋 Get email addresses of dept approvers
     */
    private static function getApproverEmails(int $deptID): array
    {
        global $mysqli;
        $emails = [];
        $stmt = $mysqli->prepare(
            'SELECT U.emailAddress FROM tblUserDepts UD '
            . 'JOIN tblUsers U ON U.userID = UD.userID '
            . 'WHERE UD.deptID = ? AND (UD.isApprover = 1 OR UD.isDeptLead = 1) AND U.isActive = 1'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $deptID);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($r = $result->fetch_assoc()) {
                if ($r['emailAddress'] !== null && $r['emailAddress'] !== '') {
                    $emails[] = $r['emailAddress'];
                }
            }
            $stmt->close();
        }
        return $emails;
    }

    /**
     * 💳 Get email addresses of treasury users
     */
    private static function getTreasuryEmails(): array
    {
        global $mysqli;
        $emails = [];
        $result = $mysqli->query(
            'SELECT U.emailAddress FROM tblUserRoles UR '
            . 'JOIN tblRoles R ON R.roleID = UR.roleID '
            . 'JOIN tblUsers U ON U.userID = UR.userID '
            . "WHERE R.roleKey = 'Treasurer' AND U.isActive = 1"
        );
        if ($result !== false) {
            while ($r = $result->fetch_assoc()) {
                if ($r['emailAddress'] !== null && $r['emailAddress'] !== '') {
                    $emails[] = $r['emailAddress'];
                }
            }
        }
        return $emails;
    }

    /**
     * 📊 Build claim summary HTML block
     */
    private static function claimSummaryHtml(
        int $claimID,
        string $title,
        string $claimant,
        string $dept,
        string $total,
        string $date
    ): string {
        return '<table style="border-collapse:collapse;margin:1em 0;width:100%;max-width:500px;">'
            . '<tr><td style="padding:6px 12px;border:1px solid #dee2e6;font-weight:bold;">Claim #</td>'
            . '<td style="padding:6px 12px;border:1px solid #dee2e6;">' . $claimID . '</td></tr>'
            . '<tr><td style="padding:6px 12px;border:1px solid #dee2e6;font-weight:bold;">Title</td>'
            . '<td style="padding:6px 12px;border:1px solid #dee2e6;">' . $title . '</td></tr>'
            . '<tr><td style="padding:6px 12px;border:1px solid #dee2e6;font-weight:bold;">Claimant</td>'
            . '<td style="padding:6px 12px;border:1px solid #dee2e6;">' . $claimant . '</td></tr>'
            . '<tr><td style="padding:6px 12px;border:1px solid #dee2e6;font-weight:bold;">Department</td>'
            . '<td style="padding:6px 12px;border:1px solid #dee2e6;">' . $dept . '</td></tr>'
            . '<tr><td style="padding:6px 12px;border:1px solid #dee2e6;font-weight:bold;">Total</td>'
            . '<td style="padding:6px 12px;border:1px solid #dee2e6;">' . $total . '</td></tr>'
            . '<tr><td style="padding:6px 12px;border:1px solid #dee2e6;font-weight:bold;">Date</td>'
            . '<td style="padding:6px 12px;border:1px solid #dee2e6;">' . $date . '</td></tr>'
            . '</table>';
    }

    /**
     * 🎨 Build full HTML email wrapper
     */
    private static function buildHtml(string $heading, string $content): string
    {
        global $SETTINGS;
        $siteName = htmlspecialchars($SETTINGS['site']['name'] ?? 'Portal', ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '</head><body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica Neue,Arial,sans-serif;'
            . 'font-size:14px;color:#212529;background:#f8f9fa;margin:0;padding:20px;">'
            . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;'
            . 'box-shadow:0 1px 3px rgba(0,0,0,.1);">'
            . '<div style="background:#0d6efd;color:#fff;padding:16px 24px;">'
            . '<h2 style="margin:0;font-size:18px;">' . $heading . '</h2>'
            . '</div>'
            . '<div style="padding:24px;">' . $content . '</div>'
            . '<div style="padding:16px 24px;background:#f8f9fa;border-top:1px solid #dee2e6;'
            . 'font-size:12px;color:#6c757d;text-align:center;">'
            . 'Sent by ' . $siteName . ' &mdash; Please do not reply to this email.'
            . '</div>'
            . '</div></body></html>';
    }

    /**
     * 🎨 Inline button style for email CTAs
     */
    private static function btnStyle(): string
    {
        return 'display:inline-block;padding:10px 20px;background:#0d6efd;color:#fff;'
            . 'text-decoration:none;border-radius:6px;font-weight:600;';
    }
}
