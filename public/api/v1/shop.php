<?php
// GET /api/v1/shop.php — the shop this key belongs to, and which website features are on.
require __DIR__ . '/_api.php';
[$key, $tid] = api_start('products');
$t = (new Models\TenantModel(Database::pdo()))->find($tid) ?: [];
$s = api_settings($key);
api_ok([
    'name'     => $t['name'] ?? null,
    'logo'     => ShopApi::imageUrl($t['logo_path'] ?? null),
    'currency' => $t['currency'] ?? 'KES',
    'phone'    => $t['phone'] ?? null,
    'address'  => $t['address'] ?? null,
    'features' => ['comments' => (bool) $s['comments_on'], 'likes' => (bool) $s['likes_on'], 'orders' => (bool) $s['orders_on']],
    'order_note' => $s['order_note'],
]);
