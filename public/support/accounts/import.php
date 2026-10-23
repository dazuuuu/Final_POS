<?php
// public/support/accounts/import.php — support imports a shop's old POS database for an account.
require_once __DIR__ . '/../_boot.php';

$id = (int) ($_GET['id'] ?? 0);
SupportOrg::guard($id); // only this support company's accounts
$stmt = Database::pdo()->prepare('SELECT name FROM tenants WHERE id = ? AND parent_tenant_id IS NULL');
$stmt->execute([$id]);
$name = $stmt->fetchColumn();
if ($name === false) {
    support_flash('Account not found.', 'danger');
    header('Location: ' . public_url('support/accounts/'));
    exit;
}
$__impAccount = $id;
$__impUrl = public_url('support/accounts/import.php?id=' . $id);
$__impCsrf = $_SESSION['support_csrf'];
$__impSupport = true; // support may take over logins that already exist elsewhere

$page_title = 'Import · ' . $name;
$nav = 'accounts';
ob_start();
?>
<a class="small text-decoration-none" href="<?php echo public_url('support/accounts/view.php?id=' . $id); ?>"><i class="fas fa-arrow-left me-1"></i><?php echo htmlspecialchars($name); ?></a>
<h1 class="h4 mt-2 mb-4">Import shop data</h1>
<?php include __DIR__ . '/../../components/shop_import.php'; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../_layout.php';
