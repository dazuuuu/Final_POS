<?php
// GET /api/v1/insights.php[?days=30] — views, likes and comments per product, and website orders.
// Needs a key with the "insights" permission — call it from your server, not from the browser.
require __DIR__ . '/_api.php';
[$key, $tid] = api_start('insights');
$pdo = Database::pdo();
$days = max(1, min(365, (int) ($_GET['days'] ?? 30)));
$since = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
$top = $pdo->prepare("SELECT p.id, p.name, COALESCE(SUM(v.views),0) views FROM products p
                        LEFT JOIN shop_api_views v ON v.tenant_id = p.tenant_id AND v.product_id = p.id AND v.day >= ?
                       WHERE p.tenant_id = ? AND p.status = 'active' GROUP BY p.id HAVING views > 0 ORDER BY views DESC LIMIT 50");
$top->execute([$since, $tid]);
$rows = $top->fetchAll(PDO::FETCH_ASSOC);
$stats = ShopApi::stats($tid, array_column($rows, 'id'));
$daily = $pdo->prepare('SELECT day, SUM(views) FROM shop_api_views WHERE tenant_id = ? AND day >= ? GROUP BY day ORDER BY day');
$daily->execute([$tid, $since]);
$orders = $pdo->prepare('SELECT status, COUNT(*) n, COALESCE(SUM(total),0) total FROM shop_web_orders WHERE tenant_id = ? AND created_at >= ? GROUP BY status');
$orders->execute([$tid, $since]);
$pending = $pdo->prepare("SELECT COUNT(*) FROM shop_api_comments WHERE tenant_id = ? AND status = 'pending'");
$pending->execute([$tid]);
api_ok([
    'days' => $days,
    'views_per_day' => array_map(fn($v) => (int) $v, $daily->fetchAll(PDO::FETCH_KEY_PAIR)),
    'products' => array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'views' => (int) $r['views'],
        'likes' => $stats[(int) $r['id']]['likes'], 'comments' => $stats[(int) $r['id']]['comments']], $rows),
    'orders' => array_map(fn($r) => ['status' => $r['status'], 'count' => (int) $r['n'], 'total' => round((float) $r['total'], 2)], $orders->fetchAll(PDO::FETCH_ASSOC)),
    'comments_waiting' => (int) $pending->fetchColumn(),
]);
