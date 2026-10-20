<?php
// GET /api/v1/products.php — the shop's live products.
//   ?q=search  ?category=ID  ?subcategory=ID  ?on_offer=1  ?in_stock=1
//   ?sort=name|price|price_desc|newest|popular   ?page=1  ?per_page=24 (max 100)
//   ?ids=1,2,3 (exactly these)   ?stats=1 (adds views / likes / comments)
require __DIR__ . '/_api.php';
[$key, $tid] = api_start('products');
$pdo = Database::pdo();
$where = ["p.tenant_id = ?", "p.status = 'active'"];
$args = [$tid];
if (($q = trim((string) ($_GET['q'] ?? ''))) !== '') { $where[] = '(p.name LIKE ? OR p.barcode = ? OR c.name LIKE ?)'; array_push($args, "%$q%", $q, "%$q%"); }
if (!empty($_GET['category'])) { $where[] = 'p.category_id = ?'; $args[] = (int) $_GET['category']; }
if (!empty($_GET['subcategory'])) { $where[] = 'p.subcategory_id = ?'; $args[] = (int) $_GET['subcategory']; }
if (!empty($_GET['in_stock'])) { $where[] = 'p.quantity > 0'; }
if (!empty($_GET['on_offer'])) { $where[] = 'p.offer_price IS NOT NULL AND p.offer_ends_at > NOW() AND (p.offer_starts_at IS NULL OR p.offer_starts_at <= NOW())'; }
if (!empty($_GET['ids'])) {
    $ids = array_slice(array_filter(array_map('intval', explode(',', (string) $_GET['ids']))), 0, 100);
    $where[] = $ids ? 'p.id IN (' . implode(',', $ids) . ')' : '0';
}
$sort = (string) ($_GET['sort'] ?? 'name');
$order = [
    'price' => 'COALESCE(NULLIF(p.retail_price,0), p.selling_price) ASC', 'price_desc' => 'COALESCE(NULLIF(p.retail_price,0), p.selling_price) DESC',
    'newest' => 'p.id DESC', 'popular' => '(SELECT COALESCE(SUM(v.views),0) FROM shop_api_views v WHERE v.tenant_id = p.tenant_id AND v.product_id = p.id) DESC',
][$sort] ?? 'p.name ASC';
$per = max(1, min(100, (int) ($_GET['per_page'] ?? 24)));
$page = max(1, (int) ($_GET['page'] ?? 1));
$from = 'FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN subcategories s ON s.id = p.subcategory_id WHERE ' . implode(' AND ', $where);
$cnt = $pdo->prepare("SELECT COUNT(*) $from");
$cnt->execute($args);
$total = (int) $cnt->fetchColumn();
$st = $pdo->prepare("SELECT p.*, c.name AS category_name, s.name AS subcategory_name $from ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$stats = !empty($_GET['stats']) ? ShopApi::stats($tid, array_column($rows, 'id')) : [];
api_ok(array_map(fn($p) => ShopApi::product($p, $stats[(int) $p['id']] ?? []), $rows),
       ['page' => $page, 'per_page' => $per, 'total' => $total, 'pages' => (int) ceil($total / $per)]);
