<?php
// POST /api/v1/mpesa-callback.php — Safaricom's STK push result for a push
// sent from mpesa-stk.php. Not key-authed (Safaricom can't send our headers):
// trust comes from matching the CheckoutRequestID we generated and stored
// ourselves at push time. Always answers 200 — Daraja retries aggressively
// on anything else, which would re-fire the whole flow.
define('DAZU_API', true);
require_once __DIR__ . '/../../../app/app.php';

function mpesa_ack(): void
{
    header('Content-Type: application/json');
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
$cb = $body['Body']['stkCallback'] ?? null;
if (!is_array($cb) || empty($cb['CheckoutRequestID'])) {
    error_log('mpesa-callback: unrecognised payload: ' . $raw);
    mpesa_ack();
}

$checkoutId = (string) $cb['CheckoutRequestID'];
$pdo = Database::pdo();
MpesaService::ensure();
$st = $pdo->prepare('SELECT * FROM shop_web_orders WHERE mpesa_checkout_id = ? LIMIT 1');
$st->execute([$checkoutId]);
$order = $st->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    error_log('mpesa-callback: no order for CheckoutRequestID ' . $checkoutId);
    mpesa_ack();
}

$resultCode = (int) ($cb['ResultCode'] ?? 1);
if ($resultCode !== 0) {
    MpesaService::markFailed((int) $order['id']);
    mpesa_ack();
}

$items = $cb['CallbackMetadata']['Item'] ?? [];
$meta = [];
foreach ($items as $it) {
    if (isset($it['Name'])) $meta[$it['Name']] = $it['Value'] ?? null;
}
$receipt = (string) ($meta['MpesaReceiptNumber'] ?? '');
MpesaService::markPaid((int) $order['id'], $receipt);
mpesa_ack();
