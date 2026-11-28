<?php
// public/api/print/track.php — fired (fire-and-forget) by printer.js every
// time a receipt is actually printed, including reprints. Records who
// printed it and when, for the Receipts page.
require_once __DIR__ . '/../../../app/app.php';
require_once __DIR__ . '/../_lib/respond.php';
require_once ROOT_PATH . '/app/helpers/uploads.php';
PageGuard::auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST only.'], 405);
}
$body = read_json_body();
if (!csrf_verify($body['csrf_token'] ?? null)) {
    json_response(['ok' => false, 'error' => 'Session expired.'], 403);
}
$entity = ReceiptTracking::parseEntity((string) ($body['entity'] ?? ''));
if (!$entity) {
    json_response(['ok' => false, 'error' => 'Unknown receipt.'], 422);
}
[$type, $id] = $entity;

$receiptNumber = null;
$pdo = Database::pdo();
$tid = (int) TenantContext::tenantId();
if ($type === 'sale') {
    $st = $pdo->prepare('SELECT receipt_number FROM sales WHERE id = ? AND tenant_id = ?');
} elseif ($type === 'order') {
    $st = $pdo->prepare('SELECT receipt_number FROM orders WHERE id = ? AND tenant_id = ?');
} else {
    $st = null;
}
if ($st) {
    $st->execute([$id, $tid]);
    $receiptNumber = $st->fetchColumn() ?: null;
}

ReceiptTracking::recordPrint($type, $id, $receiptNumber);
json_response(['ok' => true]);
