<?php
// Path: apps/expenses/treasury/save.php
/**
 * -----------------------------------------------------------------------------
 * Treasury – Record Reimbursement Handler 💸
 * -----------------------------------------------------------------------------
 * Marks claim as Reimbursed, saves payment references, logs action, triggers
 * claimant + authoriser notification (todo), and stores PDF with COMPLETE stamp
 * (pdf generation stub).
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;
use Portal\Core\Logger;

Auth::ensureSession();
if (Auth::verifyCsrf($_POST['csrf_token']??'')===false){echo'Bad CSRF';exit();}

$claimID=intval($_POST['claimID']??0);
$comments=trim($_POST['comments']??'');
$refs=array_map('trim',explode(',',implode(',',$_POST['payRef']??[]))); // flatten
$refs=array_filter($refs);
if($claimID===0||empty($refs)){echo'Missing fields';exit();}

$mysqli->begin_transaction();
try{
    // Add file table for payment refs if not exists
    $mysqli->query('CREATE TABLE IF NOT EXISTS tblExpenseClaimPayments (
        payID INT AUTO_INCREMENT PRIMARY KEY,
        claimID INT NOT NULL,
        payReference VARCHAR(255) NOT NULL,
        addedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (claimID) REFERENCES tblExpenseClaims(claimID) ON DELETE CASCADE) ENGINE=InnoDB');

    $stmt=$mysqli->prepare('INSERT INTO tblExpenseClaimPayments (claimID,payReference) VALUES (?,?)');
    foreach($refs as $r){$stmt->bind_param('is',$claimID,$r);$stmt->execute();}
    $stmt->close();

    // Update claim status
    $stmt=$mysqli->prepare('UPDATE tblExpenseClaims SET status="Reimbursed", updatedAt=NOW() WHERE claimID=?');
    $stmt->bind_param('i',$claimID);$stmt->execute();$stmt->close();

    $mysqli->commit();
    Logger::activity('ExpensePay','Reimbursed claim #'.$claimID,$userId=$_SESSION['user_id']);

    // TODO PDF generation & email

    header('Location:/expenses/treasury?done=1');exit();
}catch(Throwable $ex){$mysqli->rollback();Logger::exception($ex);echo'Error';exit();}