<?php
// public/api/catalogue/feedback.php — likes & comments from the public,
// read-only catalogue. No login: visitors are told apart by a random cookie.
//   GET  ?shop=<slug>&product=<id>                → { comments: [...] }
//   POST shop, product, action=like|comment, name, body → { ok, ... }
require_once __DIR__ . '/../../../app/app.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::pdo();
$slug = trim((string) ($_REQUEST['shop'] ?? ''));
$tenant = $slug !== ''
    ? (function () use ($pdo, $slug) {
        $s = $pdo->prepare("SELECT * FROM tenants WHERE slug = ? AND status = 'active' LIMIT 1");
        $s->execute([$slug]);
        return $s->fetch() ?: null;
    })()
    : (new Models\TenantModel($pdo))->primary();
$productId = (int) ($_REQUEST['product'] ?? 0);
if (!$tenant || $productId <= 0) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Not found.']);
    exit;
}
$tenantId = (int) $tenant['id'];

// The product must be one this shop is showing.
$chk = $pdo->prepare("SELECT 1 FROM products WHERE id = ? AND tenant_id = ? AND status = 'active' LIMIT 1");
$chk->execute([$productId, $tenantId]);
if (!$chk->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Product not found.']);
    exit;
}

$visitor = preg_replace('/[^a-f0-9]/', '', (string) ($_COOKIE['cat_visitor'] ?? ''));
if (strlen($visitor) !== 32) {
    $visitor = bin2hex(random_bytes(16));
    setcookie('cat_visitor', $visitor, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
}

$F = new Models\CatalogueFeedbackModel($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'like') {
        echo json_encode(['ok' => true] + $F->toggleLike($tenantId, $productId, $visitor));
    } elseif ($action === 'comment') {
        $res = $F->addComment($tenantId, $productId, $visitor, (string) ($_POST['name'] ?? ''), (string) ($_POST['body'] ?? ''));
        if ($res['ok']) { $res['comments'] = $F->comments($tenantId, $productId); }
        echo json_encode($res);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    }
    exit;
}

echo json_encode(['ok' => true, 'comments' => $F->comments($tenantId, $productId)]);
