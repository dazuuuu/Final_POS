<?php
// public/api/customers/credit.php?id=…  or  ?name=…
// What a customer owes right now and what they took on credit: their open
// invoices (credit sales and credit-book entries) with the items on each.
// Used by the till when a customer is picked or their name is typed.
require_once __DIR__ . '/../../../app/app.php';

header('Content-Type: application/json; charset=utf-8');

if (!TenantContext::check() || !(TenantContext::can(Capabilities::CUSTOMERS_MANAGE) || TenantContext::can(Capabilities::SALES_RECORD) || TenantContext::can(Capabilities::CREDIT_SALES))) {
    http_response_code(403);
    echo json_encode(['found' => false]);
    exit;
}

$pdo = Database::pdo();
$tid = (int) TenantContext::tenantId();
$id = (int) ($_GET['id'] ?? 0);
$name = trim((string) ($_GET['name'] ?? ''));
if ($id > 0) {
    $st = $pdo->prepare('SELECT id, name, phone FROM customers WHERE tenant_id = ? AND id = ?');
    $st->execute([$tid, $id]);
} elseif ($name !== '') {
    $st = $pdo->prepare('SELECT id, name, phone FROM customers WHERE tenant_id = ? AND LOWER(TRIM(name)) = LOWER(?) LIMIT 1');
    $st->execute([$tid, $name]);
} else {
    echo json_encode(['found' => false]);
    exit;
}
$c = $st->fetch();
if (!$c) {
    echo json_encode(['found' => false]);
    exit;
}

$CC = new Models\CustomerCreditModel($pdo);
$inv = $CC->openInvoices((int) $c['id']);
$orderIds = [];
foreach ($inv as $i) { if (strpos($i['key'], 'o:') === 0) { $orderIds[] = (int) substr($i['key'], 2); } }
$items = $orderIds ? (new Models\OrderModel($pdo))->itemsForMany($orderIds) : [];

echo json_encode([
    'found' => true,
    'id' => (int) $c['id'],
    'name' => $c['name'],
    'phone' => (string) ($c['phone'] ?? ''),
    'balance' => round(array_sum(array_column($inv, 'balance')), 2),
    'invoices' => array_map(function ($i) use ($items) {
        $lines = strpos($i['key'], 'o:') === 0 ? ($items[(int) substr($i['key'], 2)] ?? []) : [];
        return [
            'kind' => $i['kind'], 'label' => $i['label'], 'date' => $i['date'],
            'total' => $i['total'], 'paid' => $i['paid'], 'balance' => $i['balance'],
            'items' => array_map(fn($l) => ['name' => $l['name'], 'qty' => $l['qty'], 'total' => $l['total']], $lines),
        ];
    }, $inv),
], JSON_UNESCAPED_UNICODE);
