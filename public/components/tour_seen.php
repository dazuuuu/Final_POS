<?php
// public/components/tour_seen.php — POST to dismiss the backup feature tour for good.
require_once __DIR__ . '/../../app/app.php';
PageGuard::auth();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SESSION['support_preview'])) {
    AccountBackup::markTourSeen((int) TenantContext::userId());
}
header('Content-Type: application/json');
echo json_encode(['ok' => true]);
