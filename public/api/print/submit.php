<?php
// POST /api/print/submit.php — mark a receipt as handed over / submitted
// (and who it was given to). Used by the "Mark as submitted" button on a
// receipt page and from the Receipts tracking page.
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
$to = trim((string) ($body['to'] ?? ''));
if ($to === '') {
    json_response(['ok' => false, 'error' => 'Say who the receipt was given to.'], 422);
}
ReceiptTracking::markSubmitted($type, $id, $to);
json_response(['ok' => true]);
