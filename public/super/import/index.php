<?php
// public/super/import/index.php — owner imports their old shop's POS database.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::primaryOwner();

$_SESSION['import_csrf'] ??= bin2hex(random_bytes(16));
$__impAccount = Features::accountId((int) TenantContext::tenantId());
$__impUrl = public_url('super/import/');
$__impCsrf = $_SESSION['import_csrf'];

$page_title = 'Import shop';
ob_start();
?>
<h1 class="h4 mb-1">Import your shop</h1>
<p class="text-muted mb-4">Move your inventory, sales, reports history and staff from your old POS into this one.</p>
<?php include __DIR__ . '/../../components/shop_import.php'; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
