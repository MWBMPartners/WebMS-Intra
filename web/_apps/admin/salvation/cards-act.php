<?php
// _apps/admin/salvation/cards-act.php (#316)
declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /admin/decision-cards', true, 302); exit(); }

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) { http_response_code(403); exit('Forbidden'); }
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) { http_response_code(400); exit('Bad request'); }

$cardId = (int) ($_POST['cardID'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$siteId = Site::id();

// 🔙 Whitelist the "return to this tab" hint — falls back to 'new' for any
// value not one of the tabs cards.php actually renders.
$returnStatus = (string) ($_POST['returnStatus'] ?? 'new');
if (in_array($returnStatus, ['new', 'assigned', 'contacted', 'complete', 'archived', 'all'], true) === false) {
    $returnStatus = 'new';
}

if ($cardId <= 0) {
    header('Location: /admin/decision-cards', true, 302); exit();
}

// 🙋 Assign / unassign (#gap-fix D5) — sets assignedToID + status='assigned'
// when a user is chosen; clearing the picker (assignedToID=0) unassigns
// without forcing the status backwards (a card already 'contacted'/'complete'
// stays there when simply unassigned).
if ($action === 'assign') {
    $assignedToID = (int) ($_POST['assignedToID'] ?? 0);
    if ($assignedToID > 0) {
        $stmt = $mysqli->prepare(
            "UPDATE tblSalvationCards SET assignedToID = ?, status = 'assigned' WHERE cardID = ? AND siteID = ?"
        );
        $stmt->bind_param('iii', $assignedToID, $cardId, $siteId);
    } else {
        $stmt = $mysqli->prepare('UPDATE tblSalvationCards SET assignedToID = NULL WHERE cardID = ? AND siteID = ?');
        $stmt->bind_param('ii', $cardId, $siteId);
    }
    $stmt->execute();
    $stmt->close();

    Logger::activity(
        'SalvationCardAssign',
        'Card #' . $cardId . ' → ' . ($assignedToID > 0 ? 'assigned to user #' . $assignedToID : 'unassigned')
    );

    header('Location: /admin/decision-cards?status=' . ($assignedToID > 0 ? 'assigned' : $returnStatus), true, 302);
    exit();
}

// 📝 Save follow-up notes (#gap-fix D5) — column is nullable, so a blank
// textarea clears it rather than storing an empty string.
if ($action === 'notes') {
    $notes = trim((string) ($_POST['notes'] ?? ''));
    if (mb_strlen($notes) > 2000) {
        $notes = mb_substr($notes, 0, 2000);
    }
    $notesValue = $notes === '' ? null : $notes;

    $stmt = $mysqli->prepare('UPDATE tblSalvationCards SET notes = ? WHERE cardID = ? AND siteID = ?');
    $stmt->bind_param('sii', $notesValue, $cardId, $siteId);
    $stmt->execute();
    $stmt->close();

    Logger::activity('SalvationCardNotes', 'Card #' . $cardId . ' notes updated');

    header('Location: /admin/decision-cards?status=' . $returnStatus, true, 302);
    exit();
}

$map = ['contacted' => 'contacted', 'complete' => 'complete', 'archive' => 'archived'];
if (isset($map[$action]) === false) {
    header('Location: /admin/decision-cards', true, 302); exit();
}
$newStatus = $map[$action];

$stmt = $mysqli->prepare('UPDATE tblSalvationCards SET status = ? WHERE cardID = ? AND siteID = ?');
$stmt->bind_param('sii', $newStatus, $cardId, $siteId);
$stmt->execute();
$stmt->close();

Logger::activity('SalvationCardStatus', 'Card #' . $cardId . ' → ' . $newStatus);

header('Location: /admin/decision-cards?status=' . ($newStatus === 'archived' ? 'archived' : 'new'), true, 302);
exit();
