<?php
// public/support/accounts/preview.php — support opens an account's real POS
// as its owner or one of its staff, to check an import (inventory, sales,
// staff…) without knowing their password or PIN and without adding a user
// to their account. A banner (components/preview_banner.php) stays on every
// page; "Exit preview" (or Log out) drops the shop login and returns here,
// keeping the support login.
//   POST  user=<id>       start a preview as that user
//   GET   ?exit=1         end the preview
require_once __DIR__ . '/../_boot.php';

$pdo = Database::pdo();

if (isset($_GET['exit'])) {
    $back = (int) ($_SESSION['support_preview']['account'] ?? 0);
    unset($_SESSION['support_preview'], $_SESSION['logged_in'], $_SESSION['otp_verified'], $_SESSION['user_id'],
          $_SESSION['tenant_id'], $_SESSION['role'], $_SESSION['capabilities'], $_SESSION['username'],
          $_SESSION['must_reset'], $_SESSION['home_tenant_id'], $_SESSION['shop_slug'], $_SESSION['first_login']);
    TenantContext::reset();
    setcookie('shop_slug', '', ['expires' => time() - 3600, 'path' => '/']);
    header('Location: ' . public_url($back ? 'support/accounts/view.php?id=' . $back : 'support/accounts/'));
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
support_require_owner(); // only the main admin may open a client's POS (exiting a preview stays open to all)
if (!support_posted()) {
    header('Location: ' . public_url('support/accounts/view.php?id=' . $id));
    exit;
}

// The user must belong to this account (main shop or a branch).
$st = $pdo->prepare(
    "SELECT u.*, r.role_name FROM users u
       JOIN roles r ON r.id = u.role_id
       JOIN tenants t ON t.id = u.tenant_id
      WHERE u.id = ? AND (t.id = ? OR t.parent_tenant_id = ?) AND r.role_name IN ('tenant_owner', 'staff')"
);
$st->execute([(int) ($_POST['user'] ?? 0), $id, $id]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    support_flash('That user is not part of this account.', 'danger');
    header('Location: ' . public_url('support/accounts/view.php?id=' . $id));
    exit;
}

session_regenerate_id(true);
unset($_SESSION['preview_alerts_closed']); // each preview starts fresh: pending alerts pop up again
TenantContext::establish($pdo, $user);
$slug = $pdo->prepare('SELECT slug FROM tenants WHERE id = ?');
$slug->execute([$id]);
$_SESSION['shop_slug']    = (string) $slug->fetchColumn();
$_SESSION['username']     = $user['username'];
$_SESSION['logged_in']    = true;
$_SESSION['otp_verified'] = true;
$_SESSION['must_reset']   = false; // never push support into their password-reset screen
$_SESSION['support_preview'] = [
    'account' => $id,
    'user'    => $user['username'],
    'role'    => $user['role_name'] === 'staff' ? 'staff' : 'owner',
    'started' => date('Y-m-d H:i:s'),
];
header('Location: ' . public_url($user['role_name'] === 'staff' ? 'staff/dashboard/' : 'super/dashboard/'));
exit;
