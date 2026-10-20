<?php
// GET /api/v1/categories.php — categories with their subcategories and how many products are in stock.
require __DIR__ . '/_api.php';
[$key, $tid] = api_start('products');
$pdo = Database::pdo();
$cats = $pdo->prepare("SELECT c.id, c.name, COUNT(p.id) AS products, SUM(p.quantity > 0) AS in_stock
                         FROM categories c LEFT JOIN products p ON p.category_id = c.id AND p.tenant_id = c.tenant_id AND p.status = 'active'
                        WHERE c.tenant_id = ? GROUP BY c.id HAVING products > 0 ORDER BY c.name");
$cats->execute([$tid]);
$subs = $pdo->prepare("SELECT id, category_id, name FROM subcategories WHERE tenant_id = ? ORDER BY name");
$subs->execute([$tid]);
$byCat = [];
foreach ($subs->fetchAll(PDO::FETCH_ASSOC) as $s) { $byCat[(int) $s['category_id']][] = ['id' => (int) $s['id'], 'name' => $s['name']]; }
api_ok(array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'products' => (int) $c['products'], 'in_stock' => (int) $c['in_stock'],
    'subcategories' => $byCat[(int) $c['id']] ?? []], $cats->fetchAll(PDO::FETCH_ASSOC)));
