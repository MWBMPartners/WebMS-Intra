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
$map = ['contacted' => 'contacted', 'complete' => 'complete', 'archive' => 'archived'];
if ($cardId <= 0 || isset($map[$action]) === false) {
    header('Location: /admin/decision-cards', true, 302); exit();
}
$newStatus = $map[$action];
$siteId = Site::id();

$stmt = $mysqli->prepare('UPDATE tblSalvationCards SET status = ? WHERE cardID = ? AND siteID = ?');
$stmt->bind_param('sii', $newStatus, $cardId, $siteId);
$stmt->execute();
$stmt->close();

Logger::activity('SalvationCardStatus', 'Card #' . $cardId . ' → ' . $newStatus);

header('Location: /admin/decision-cards?status=' . ($newStatus === 'archived' ? 'archived' : 'new'), true, 302);
exit();
