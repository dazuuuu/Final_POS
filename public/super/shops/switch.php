<?php
// public/super/shops/switch.php — POST id: point the owner's session at
// another of their shops, then land on that shop's dashboard.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if (ShopContext::switchTo($id)) {
        $shop = (new Models\TenantModel(Database::pdo()))->find($id);
        $_SESSION['flash']['success'] = 'You are now managing ' . ($shop['name'] ?? 'this shop') . '.';
    } else {
        $_SESSION['flash']['error'] = 'You can\'t open that shop.';
    }
}
header('Location: ' . public_url('super/dashboard/'));
exit;
