<?php
// POST /api/v1/orders.php — place an order from the website (when the shop allows website orders):
//   {"name":"…", "phone":"07…", "address":"…", "note":"… (optional)",
//    "items":[{"product_id":ID, "quantity":2}, …]}
//   Prices come from the POS (today's price, offers applied) — never from the website.
//   The order lands in the owner's POS (Website API → Orders) as "new"; stock is not taken until the shop confirms it there.
// GET  /api/v1/orders.php?number=WEB-000123&phone=07…  — the order's status, for a "track my order" page.
require __DIR__ . '/_api.php';
[$key, $tid] = api_start('orders', ['GET', 'POST']);
if (!api_settings($key)['orders_on']) api_fail(403, 'This shop does not take orders from the website yet.', 'orders_off');
$pdo = Database::pdo();
$money = fn($n) => round((float) $n, 2);
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = $pdo->prepare('SELECT * FROM shop_web_orders WHERE tenant_id = ? AND number = ? AND REPLACE(phone, " ", "") = REPLACE(?, " ", "")');
    $st->execute([$tid, (string) ($_GET['number'] ?? ''), (string) ($_GET['phone'] ?? '')]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) api_fail(404, 'No order with that number and phone.', 'not_found');
    $it = $pdo->prepare('SELECT product_id, name, unit_price, quantity, line_total FROM shop_web_order_items WHERE order_id = ?');
    $it->execute([(int) $o['id']]);
    api_ok(['number' => $o['number'], 'status' => $o['status'], 'total' => $money($o['total']), 'created_at' => $o['created_at'],
            'items' => array_map(fn($r) => ['product_id' => (int) $r['product_id'], 'name' => $r['name'], 'unit_price' => $money($r['unit_price']),
                'quantity' => (float) $r['quantity'], 'line_total' => $money($r['line_total'])], $it->fetchAll(PDO::FETCH_ASSOC))]);
}
$in = api_input();
$name = trim((string) ($in['name'] ?? ''));
$phone = trim((string) ($in['phone'] ?? ''));
$address = trim((string) ($in['address'] ?? ''));
$note = trim((string) ($in['note'] ?? ''));
if ($name === '' || mb_strlen($name) > 120) api_fail(422, 'Enter the customer name.', 'invalid_name');
if (!preg_match('/^[0-9 +()-]{7,20}$/', $phone)) api_fail(422, 'Enter a valid phone number.', 'invalid_phone');
if ($address === '' || mb_strlen($address) > 255) api_fail(422, 'Enter the delivery address.', 'invalid_address');
$items = is_array($in['items'] ?? null) ? array_slice($in['items'], 0, 50) : [];
if (!$items) api_fail(422, 'Add at least one item.', 'no_items');
$ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
$recent = $pdo->prepare('SELECT COUNT(*) FROM shop_web_orders WHERE tenant_id = ? AND ip = ? AND created_at >= NOW() - INTERVAL 10 MINUTE');
$recent->execute([$tid, $ip]);
if ((int) $recent->fetchColumn() >= 5) api_fail(429, 'Too many orders from this device — please wait a few minutes.', 'rate_limited');

$lines = [];
$total = 0.0;
foreach ($items as $i) {
    $p = api_product($tid, (int) ($i['product_id'] ?? 0));
    $q = (float) ($i['quantity'] ?? 1);
    if (!$p) api_fail(422, 'Product ' . (int) ($i['product_id'] ?? 0) . ' is not in this shop.', 'invalid_item');
    if ($q <= 0 || $q > 10000) api_fail(422, 'Check the quantity of ' . $p['name'] . '.', 'invalid_quantity');
    if ((float) $p['quantity'] < $q) api_fail(409, 'Only ' . (float) $p['quantity'] . ' of ' . $p['name'] . ' left.', 'out_of_stock');
    $price = Models\ProductModel::effectivePrice($p)['price'];
    $lines[] = [$p, $q, $price, $money($price * $q)];
    $total += $price * $q;
}
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO shop_web_orders (tenant_id, number, customer_name, phone, address, note, total, key_id, ip) VALUES (?, '', ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$tid, strip_tags($name), $phone, strip_tags($address), $note !== '' ? mb_substr(strip_tags($note), 0, 500) : null, $money($total), (int) $key['id'], $ip]);
    $oid = (int) $pdo->lastInsertId();
    $number = 'WEB-' . str_pad((string) $oid, 6, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE shop_web_orders SET number = ? WHERE id = ?')->execute([$number, $oid]);
    $ins = $pdo->prepare('INSERT INTO shop_web_order_items (tenant_id, order_id, product_id, name, unit_price, quantity, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($lines as [$p, $q, $price, $lt]) $ins->execute([$tid, $oid, (int) $p['id'], $p['name'], $money($price), $q, $lt]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('api orders: ' . $e->getMessage());
    api_fail(500, 'The order could not be saved. Please try again.', 'server_error');
}
api_ok(['number' => $number, 'status' => 'new', 'total' => $money($total),
        'message' => 'Order received — the shop will call ' . $phone . ' to confirm.']);
