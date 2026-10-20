<?php
// GET  /api/v1/comments.php?product_id=ID[&page=1]  approved comments of a product
// POST /api/v1/comments.php  {"product_id":ID, "name":"…", "comment":"…", "rating":1-5 (optional)}
//      New comments wait for the owner's approval unless the shop auto-approves them.
require __DIR__ . '/_api.php';
[$key, $tid] = api_start('comments', ['GET', 'POST']);
$s = api_settings($key);
if (!$s['comments_on']) api_fail(403, 'Comments are switched off for this shop.', 'comments_off');
$pdo = Database::pdo();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = api_input();
    $p = api_product($tid, (int) ($in['product_id'] ?? 0));
    if (!$p) api_fail(404, 'No such product in this shop.', 'not_found');
    $name = trim((string) ($in['name'] ?? ''));
    $text = trim((string) ($in['comment'] ?? ''));
    $rating = isset($in['rating']) && $in['rating'] !== '' ? max(1, min(5, (int) $in['rating'])) : null;
    if ($name === '' || mb_strlen($name) > 80) api_fail(422, 'Enter a name (up to 80 characters).', 'invalid_name');
    if ($text === '' || mb_strlen($text) > 1000) api_fail(422, 'Enter a comment (up to 1000 characters).', 'invalid_comment');
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $recent = $pdo->prepare('SELECT COUNT(*) FROM shop_api_comments WHERE tenant_id = ? AND ip = ? AND created_at >= NOW() - INTERVAL 10 MINUTE');
    $recent->execute([$tid, $ip]);
    if ((int) $recent->fetchColumn() >= 5) api_fail(429, 'Too many comments — please wait a few minutes.', 'rate_limited');
    $status = $s['comments_auto'] ? 'approved' : 'pending';
    $pdo->prepare('INSERT INTO shop_api_comments (tenant_id, product_id, name, comment, rating, status, ip) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$tid, (int) $p['id'], strip_tags($name), strip_tags($text), $rating, $status, $ip]);
    api_ok(['id' => (int) $pdo->lastInsertId(), 'status' => $status,
            'message' => $status === 'approved' ? 'Thanks — your comment is up.' : 'Thanks — your comment will show once the shop approves it.']);
}
$pid = (int) ($_GET['product_id'] ?? 0);
if (!api_product($tid, $pid)) api_fail(404, 'No such product in this shop.', 'not_found');
$per = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$c = $pdo->prepare("SELECT id, name, comment, rating, created_at FROM shop_api_comments WHERE tenant_id = ? AND product_id = ? AND status = 'approved'
                    ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$c->execute([$tid, $pid]);
api_ok(array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'comment' => $r['comment'],
    'rating' => $r['rating'] !== null ? (int) $r['rating'] : null, 'created_at' => $r['created_at']], $c->fetchAll(PDO::FETCH_ASSOC)), ['page' => $page]);
