<?php
// GET /api/v1/product.php?id=ID — one product with its views, likes and latest approved comments.
// Each call counts as a view (send ?view=0 to read without counting, e.g. from your own server).
require __DIR__ . '/_api.php';
[$key, $tid] = api_start('products');
$p = api_product($tid, (int) ($_GET['id'] ?? 0));
if (!$p) api_fail(404, 'No such product in this shop.', 'not_found');
$pdo = Database::pdo();
if (($_GET['view'] ?? '1') !== '0') {
    $pdo->prepare('INSERT INTO shop_api_views (tenant_id, product_id, day, views) VALUES (?, ?, CURDATE(), 1) ON DUPLICATE KEY UPDATE views = views + 1')
        ->execute([$tid, (int) $p['id']]);
}
$s = api_settings($key);
$comments = [];
if ($s['comments_on']) {
    $c = $pdo->prepare("SELECT id, name, comment, rating, created_at FROM shop_api_comments WHERE tenant_id = ? AND product_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 20");
    $c->execute([$tid, (int) $p['id']]);
    $comments = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'comment' => $r['comment'],
        'rating' => $r['rating'] !== null ? (int) $r['rating'] : null, 'created_at' => $r['created_at']], $c->fetchAll(PDO::FETCH_ASSOC));
}
api_ok(ShopApi::product($p, ShopApi::stats($tid, [(int) $p['id']])[(int) $p['id']]) + ['comments' => $comments]);
