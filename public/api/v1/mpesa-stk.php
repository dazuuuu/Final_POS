<?php
// POST /api/v1/mpesa-stk.php — pay for an order placed via /api/v1/orders.php with M-Pesa STK push.
//   {"number":"WEB-000123", "phone":"07…"}
// Sends the prompt to the customer's phone. The order is marked paid — and
// its stock taken off — only once Safaricom confirms via mpesa-callback.php,
// never from this endpoint's response (the customer can close the page
// before entering their PIN).
require __DIR__ . '/_api.php';
[$key, $tid] = api_start('orders', ['POST']);
if (!api_settings($key)['orders_on']) api_fail(403, 'This shop does not take orders from the website yet.', 'orders_off');
$account = (int) $key['account_id'];
if (!MpesaService::enabled($account)) api_fail(403, 'This shop has not set up M-Pesa payments yet.', 'mpesa_off');

$pdo = Database::pdo();
$in = api_input();
$number = trim((string) ($in['number'] ?? ''));
$phone = trim((string) ($in['phone'] ?? ''));
if ($number === '') api_fail(422, 'Order number is required.', 'invalid_number');
if (!preg_match('/^[0-9 +()-]{7,20}$/', $phone)) api_fail(422, 'Enter a valid phone number.', 'invalid_phone');

$st = $pdo->prepare('SELECT * FROM shop_web_orders WHERE tenant_id = ? AND number = ? AND REPLACE(phone, " ", "") = REPLACE(?, " ", "")');
$st->execute([$tid, $number, $phone]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if (!$o) api_fail(404, 'No order with that number and phone.', 'not_found');
if ($o['payment_status'] === 'paid') api_fail(409, 'This order is already paid.', 'already_paid');
if ($o['payment_status'] === 'pending') api_fail(409, 'A payment prompt was already sent for this order — check your phone.', 'already_pending');

$callbackUrl = rtrim((string) (defined('BASE_URL') ? BASE_URL : ''), '/');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$callbackUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . $callbackUrl . '/api/v1/mpesa-callback.php';

$res = MpesaService::stkPush($account, $o, $phone, $callbackUrl);
if (!$res['ok']) api_fail(502, $res['error'], 'mpesa_error');

$pdo->prepare("UPDATE shop_web_orders SET payment_status = 'pending', mpesa_checkout_id = ?, mpesa_merchant_id = ? WHERE id = ?")
    ->execute([$res['checkout_id'], $res['merchant_id'], (int) $o['id']]);

api_ok(['message' => 'Check your phone and enter your M-Pesa PIN to complete payment.', 'checkout_id' => $res['checkout_id']]);
