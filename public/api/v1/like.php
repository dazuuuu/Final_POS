<?php
// GET  /api/v1/like.php?product_id=ID&visitor=V   → {liked, likes}
// POST /api/v1/like.php {"product_id":ID, "visitor":"V", "like":true|false}  (omit "like" to toggle)
// "visitor" is any random id your website keeps for the visitor (e.g. in localStorage) — one like per visitor.
require __DIR__ . '/_api.php';
[$key, $tid] = api_start('likes', ['GET', 'POST']);
if (!api_settings($key)['likes_on']) api_fail(403, 'Likes are switched off for this shop.', 'likes_off');
$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? api_input() : $_GET;
$p = api_product($tid, (int) ($in['product_id'] ?? 0));
if (!$p) api_fail(404, 'No such product in this shop.', 'not_found');
$pdo = Database::pdo();
$v = ShopApi::visitor((string) ($in['visitor'] ?? ''));
$has = $pdo->prepare('SELECT 1 FROM shop_api_likes WHERE product_id = ? AND visitor = ?');
$has->execute([(int) $p['id'], $v]);
$liked = (bool) $has->fetchColumn();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $want = array_key_exists('like', $in) ? filter_var($in['like'], FILTER_VALIDATE_BOOLEAN) : !$liked;
    if ($want && !$liked) $pdo->prepare('INSERT IGNORE INTO shop_api_likes (tenant_id, product_id, visitor) VALUES (?, ?, ?)')->execute([$tid, (int) $p['id'], $v]);
    if (!$want && $liked) $pdo->prepare('DELETE FROM shop_api_likes WHERE product_id = ? AND visitor = ?')->execute([(int) $p['id'], $v]);
    $liked = $want;
}
$n = $pdo->prepare('SELECT COUNT(*) FROM shop_api_likes WHERE product_id = ?');
$n->execute([(int) $p['id']]);
api_ok(['product_id' => (int) $p['id'], 'liked' => $liked, 'likes' => (int) $n->fetchColumn()]);
