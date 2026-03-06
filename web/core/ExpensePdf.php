<?php
// Path: core/ExpensePdf.php
/**
 * -----------------------------------------------------------------------------
 * Expense PDF Builder 🧾
 * -----------------------------------------------------------------------------
 * Generates HTML for an expense claim and pipes it through Pdf::create().
 * Meant to be called by submission / approval / reimbursement handlers.
 * -----------------------------------------------------------------------------
 * Usage:
 *   ExpensePdf::generate($claimID, 'Pending');      // on submission
 *   ExpensePdf::generate($claimID, 'Not Approved'); // on rejection
 *   ExpensePdf::generate($claimID, 'Complete');     // on reimbursement
 * Returns saved file path or false.
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class ExpensePdf
{
    public static function generate(int $claimID, string $status): string|false
    {
        global $mysqli, $SETTINGS;

        // Load claim header
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

        // Load items
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

        // Build simple HTML
        ob_start(); ?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><style>
body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#000}
.header{border-bottom:2px solid #444;margin-bottom:1em;padding-bottom:.5em}
.table{width:100%;border-collapse:collapse;margin-top:1em}
.table th,.table td{border:1px solid #ccc;padding:4px;text-align:right}
.table th:first-child,.table td:first-child{text-align:left}
.total{font-weight:bold}
</style></head>
<body>
<div class="header">
    <h2 style="margin:0">Expense Claim #<?php echo $claimID; ?></h2>
    <small><?php echo htmlspecialchars($claim['fullName']); ?> &bullet; <?php echo htmlspecialchars($claim['deptName']); ?> &bullet; <?php echo htmlspecialchars($claim['claimDate']); ?></small>
</div>
<table class="table">
    <thead><tr><th>Description</th><th>Qty</th><th>Unit £</th><th>Line £</th></tr></thead>
    <tbody>
    <?php foreach($items as $it): ?>
        <tr><td><?php echo htmlspecialchars($it['itemName']); ?></td><td><?php echo $it['quantity']; ?></td><td><?php echo number_format($it['unitCost'],2); ?></td><td><?php echo number_format($it['lineTotal'],2); ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="3" class="total">Total</td><td class="total">£<?php echo number_format($claim['totalAmount'],2); ?></td></tr></tfoot>
</table>
</body>
</html>
<?php
        $html = ob_get_clean();

        // Choose watermark
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

        $storeDir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'pdfs';
        $filename = 'claim_' . $claimID . '.pdf';
        $path = $storeDir . DIRECTORY_SEPARATOR . $filename;
        $saved = Pdf::create($html, $path, $wm);
        if ($saved !== false) {
            // Update tblExpenseClaims.fileName
            $upd = $mysqli->prepare('UPDATE tblExpenseClaims SET fileName=? WHERE claimID=?');
            $upd->bind_param('si', $filename, $claimID);
            $upd->execute();
            $upd->close();
            return $saved;
        }
        return false;
    }
}
