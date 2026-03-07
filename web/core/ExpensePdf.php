<?php
// Path: core/ExpensePdf.php
/**
 * -----------------------------------------------------------------------------
 * Expense PDF Builder 🧾
 * -----------------------------------------------------------------------------
 * Generates HTML for an expense claim and pipes it through Pdf::create().
 * Includes claim header, line items, approver decision history, and payment
 * details (when available). Applies status-based watermarks.
 *
 * Usage:
 *   ExpensePdf::generate($claimID, 'Pending');      // on submission
 *   ExpensePdf::generate($claimID, 'Approved');      // on approval
 *   ExpensePdf::generate($claimID, 'Not Approved');  // on rejection
 *   ExpensePdf::generate($claimID, 'Complete');       // on reimbursement
 * Returns saved file path or false.
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

class ExpensePdf
{
    /**
     * 📄 Generate PDF for a given claim at a particular stage
     */
    public static function generate(int $claimID, string $status): string|false
    {
        global $mysqli, $SETTINGS;

        // 📋 Load claim header
        $stmt = $mysqli->prepare(
            'SELECT EC.*, U.fullName, D.deptName '
            . 'FROM tblExpenseClaims EC '
            . 'JOIN tblUsers U ON U.userID = EC.userID '
            . 'JOIN tblDepts D ON D.deptID = EC.deptID '
            . 'WHERE EC.claimID = ?'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $claimID);
        $stmt->execute();
        $claim = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($claim === null) {
            return false;
        }

        // 📦 Load line items
        $it = $mysqli->prepare(
            'SELECT itemName, quantity, unitCost, lineTotal FROM tblExpenseClaimItems WHERE claimID = ?'
        );
        if ($it === false) {
            return false;
        }
        $it->bind_param('i', $claimID);
        $it->execute();
        $items = $it->get_result()->fetch_all(MYSQLI_ASSOC);
        $it->close();

        // ✅ Load approval history (if any approvals exist)
        $approvals = [];
        $apStmt = $mysqli->prepare(
            'SELECT A.decision, A.comments, A.approverRole, A.decidedAt, U.fullName AS approverName '
            . 'FROM tblExpenseClaimApprovals A '
            . 'JOIN tblUsers U ON U.userID = A.userID '
            . 'WHERE A.claimID = ? '
            . 'ORDER BY A.decidedAt ASC'
        );
        if ($apStmt !== false) {
            $apStmt->bind_param('i', $claimID);
            $apStmt->execute();
            $approvals = $apStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $apStmt->close();
        }

        // 💳 Load payment records (if any)
        $payments = [];
        $pyStmt = $mysqli->prepare(
            'SELECT P.payReference, P.addedAt, U.fullName AS paidByName '
            . 'FROM tblExpenseClaimPayments P '
            . 'LEFT JOIN tblUsers U ON U.userID = P.paidByID '
            . 'WHERE P.claimID = ? '
            . 'ORDER BY P.addedAt ASC'
        );
        if ($pyStmt !== false) {
            $pyStmt->bind_param('i', $claimID);
            $pyStmt->execute();
            $payments = $pyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $pyStmt->close();
        }

        // 📋 Get org name from settings for PDF header
        $orgName = $SETTINGS['site']['name'] ?? 'Organisation';

        // 🖨️ Build HTML
        ob_start(); ?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><style>
body{font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#000;margin:0;padding:20px}
.header{border-bottom:2px solid #333;margin-bottom:1em;padding-bottom:.5em}
.header h2{margin:0 0 4px 0;font-size:18px}
.header .meta{color:#555;font-size:10px}
.table{width:100%;border-collapse:collapse;margin-top:1em}
.table th,.table td{border:1px solid #ccc;padding:4px 6px;text-align:right;font-size:11px}
.table th:first-child,.table td:first-child{text-align:left}
.table th{background:#f5f5f5;font-weight:bold}
.total{font-weight:bold}
.section-title{margin-top:1.5em;margin-bottom:0.3em;font-size:13px;font-weight:bold;border-bottom:1px solid #ddd;padding-bottom:3px}
.approval-row{margin:4px 0;font-size:10px;color:#333}
.badge{display:inline-block;padding:1px 6px;border-radius:3px;font-size:9px;font-weight:bold;color:#fff}
.badge-approved{background:#198754}
.badge-rejected{background:#dc3545}
.badge-pending{background:#6c757d}
.payment-info{font-size:10px;color:#333;margin:4px 0}
.footer{margin-top:2em;border-top:1px solid #ddd;padding-top:0.5em;font-size:9px;color:#888;text-align:center}
</style></head>
<body>

<!-- 📋 Claim Header -->
<div class="header">
    <h2>Expense Claim #<?php echo $claimID; ?></h2>
    <div class="meta">
        <?php echo htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8'); ?> |
        <?php echo htmlspecialchars($claim['fullName'], ENT_QUOTES, 'UTF-8'); ?> &bull;
        <?php echo htmlspecialchars($claim['deptName'], ENT_QUOTES, 'UTF-8'); ?> &bull;
        <?php echo htmlspecialchars($claim['claimDate'], ENT_QUOTES, 'UTF-8'); ?> |
        Status: <?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php if (isset($claim['claimTitle']) === true && $claim['claimTitle'] !== ''): ?>
        <div class="meta" style="margin-top:2px"><strong><?php echo htmlspecialchars($claim['claimTitle'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
    <?php endif; ?>
</div>

<!-- 📦 Line Items -->
<table class="table">
    <thead><tr><th>Description</th><th>Qty</th><th>Unit &pound;</th><th>Line &pound;</th></tr></thead>
    <tbody>
    <?php foreach ($items as $item): ?>
        <tr>
            <td><?php echo htmlspecialchars($item['itemName'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo (int) $item['quantity']; ?></td>
            <td><?php echo number_format((float) $item['unitCost'], 2); ?></td>
            <td><?php echo number_format((float) $item['lineTotal'], 2); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="3" class="total">Total</td>
            <td class="total">&pound;<?php echo number_format((float) $claim['totalAmount'], 2); ?></td>
        </tr>
    </tfoot>
</table>

<?php if (count($approvals) > 0): ?>
<!-- ✅ Approval History -->
<div class="section-title">Approval History</div>
<table class="table">
    <thead><tr><th>Approver</th><th>Role</th><th>Decision</th><th>Comments</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($approvals as $ap): ?>
        <tr>
            <td><?php echo htmlspecialchars($ap['approverName'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars(self::formatRole($ap['approverRole'] ?? 'approver'), ENT_QUOTES, 'UTF-8'); ?></td>
            <td>
                <span class="badge badge-<?php echo strtolower($ap['decision']) === 'approved' ? 'approved' : 'rejected'; ?>">
                    <?php echo htmlspecialchars($ap['decision'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </td>
            <td><?php echo htmlspecialchars($ap['comments'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo date('d M Y H:i', strtotime($ap['decidedAt'])); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php if (count($payments) > 0): ?>
<!-- 💳 Payment Records -->
<div class="section-title">Payment Records</div>
<table class="table">
    <thead><tr><th>Reference</th><th>Processed By</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($payments as $py): ?>
        <tr>
            <td><?php echo htmlspecialchars($py['payReference'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($py['paidByName'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo date('d M Y H:i', strtotime($py['addedAt'])); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- 📄 Footer -->
<div class="footer">
    Generated <?php echo date('d M Y H:i'); ?> &bull; <?php echo htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8'); ?> Expense Management System
</div>

</body>
</html>
<?php
        $html = ob_get_clean();

        // 🎨 Choose watermark based on status
        $wm = '';
        switch (strtolower($status)) {
            case 'pending':
                $wm = 'PENDING';
                break;
            case 'not approved':
                $wm = 'NOT APPROVED';
                break;
            case 'complete':
                $wm = 'COMPLETE';
                break;
            case 'approved':
                $wm = 'APPROVED';
                break;
        }

        // 💾 Save PDF to uploads directory
        $storeDir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'pdfs';
        $filename = 'claim_' . $claimID . '.pdf';
        $path = $storeDir . DIRECTORY_SEPARATOR . $filename;
        $saved = Pdf::create($html, $path, $wm);

        if ($saved !== false) {
            // 📋 Update tblExpenseClaims.fileName
            $upd = $mysqli->prepare('UPDATE tblExpenseClaims SET fileName = ? WHERE claimID = ?');
            if ($upd !== false) {
                $upd->bind_param('si', $filename, $claimID);
                $upd->execute();
                $upd->close();
            }

            // 📋 Record file version in tblExpenseClaimFiles with stage
            $fileStmt = $mysqli->prepare(
                'INSERT INTO tblExpenseClaimFiles (claimID, originalFilename, storedFilename, fileSize, fileType, stage) '
                . 'VALUES (?, ?, ?, 0, ?, ?)'
            );
            if ($fileStmt !== false) {
                $origName = 'Claim_' . $claimID . '_' . ucfirst(strtolower($status)) . '.pdf';
                $pdfType  = 'application/pdf';
                $fileStmt->bind_param('issss', $claimID, $origName, $filename, $pdfType, $status);
                $fileStmt->execute();
                $fileStmt->close();
            }

            return $saved;
        }
        return false;
    }

    /**
     * 🏷️ Format approver role for display
     */
    private static function formatRole(string $role): string
    {
        $labels = [
            'admin'              => 'Admin',
            'dept_lead'          => 'Dept Lead',
            'mandatory_approver' => 'Mandatory Approver',
            'dept_approver'      => 'Dept Approver',
            'approver'           => 'Approver',
        ];
        return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
    }
}
