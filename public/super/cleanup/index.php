<?php
// public/super/cleanup/index.php — primary-owner data cleanup tools.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::primaryOwner();

$pdo = Database::pdo();
$tenantId = (int) TenantContext::tenantId();
$base = public_url('super/cleanup/');

function cleanup_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function cleanup_count(PDO $pdo, string $table, int $tenantId): int
{
    if (!cleanup_table_exists($pdo, $table)) { return 0; }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE tenant_id = ?");
    $stmt->execute([$tenantId]);
    return (int) $stmt->fetchColumn();
}

function cleanup_delete(PDO $pdo, string $table, int $tenantId): int
{
    if (!cleanup_table_exists($pdo, $table)) { return 0; }
    $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE tenant_id = ?");
    $stmt->execute([$tenantId]);
    return $stmt->rowCount();
}

function cleanup_null_product_refs(PDO $pdo, int $tenantId): void
{
    foreach (['sale_items', 'order_items', 'held_order_items'] as $table) {
        if (cleanup_table_exists($pdo, $table)) {
            $pdo->prepare("UPDATE `{$table}` SET product_id = NULL WHERE tenant_id = ?")->execute([$tenantId]);
        }
    }
}

function cleanup_sales(PDO $pdo, int $tenantId): int
{
    $n = 0;
    $n += cleanup_delete($pdo, 'sale_payments', $tenantId);
    $n += cleanup_delete($pdo, 'sale_items', $tenantId);
    $n += cleanup_delete($pdo, 'sales', $tenantId);
    $n += cleanup_delete($pdo, 'order_items', $tenantId);
    $n += cleanup_delete($pdo, 'orders', $tenantId);
    $n += cleanup_delete($pdo, 'held_order_items', $tenantId);
    $n += cleanup_delete($pdo, 'held_orders', $tenantId);
    return $n;
}

function cleanup_products(PDO $pdo, int $tenantId): int
{
    cleanup_null_product_refs($pdo, $tenantId);
    $n = 0;
    $n += cleanup_delete($pdo, 'stock_intake_items', $tenantId);
    $n += cleanup_delete($pdo, 'stock_intakes', $tenantId);
    $n += cleanup_delete($pdo, 'products', $tenantId);
    $n += cleanup_delete($pdo, 'subcategories', $tenantId);
    $n += cleanup_delete($pdo, 'categories', $tenantId);
    $n += cleanup_delete($pdo, 'book_attributes', $tenantId);
    $n += cleanup_delete($pdo, 'suppliers', $tenantId);
    return $n;
}

function cleanup_reports(PDO $pdo, int $tenantId): int
{
    $n = 0;
    $n += cleanup_delete($pdo, 'finance_expenses', $tenantId);
    $n += cleanup_delete($pdo, 'staff_time_logs', $tenantId);
    return $n;
}

function cleanup_customers(PDO $pdo, int $tenantId): int
{
    return cleanup_delete($pdo, 'customers', $tenantId);
}

function cleanup_staff_users(PDO $pdo, int $tenantId): int
{
    if (!cleanup_table_exists($pdo, 'users')) { return 0; }
    $stmt = $pdo->prepare(
        "SELECT u.id
           FROM users u
      LEFT JOIN roles r ON r.id = u.role_id
          WHERE u.tenant_id = ? AND COALESCE(r.role_name, '') <> 'tenant_owner'"
    );
    $stmt->execute([$tenantId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
    if (!$ids) { return 0; }
    $in = implode(',', array_fill(0, count($ids), '?'));
    if (cleanup_table_exists($pdo, 'user_permissions')) {
        $pdo->prepare("DELETE FROM user_permissions WHERE user_id IN ($in)")->execute($ids);
    }
    $del = $pdo->prepare("DELETE FROM users WHERE id IN ($in) AND tenant_id = ?");
    $del->execute([...$ids, $tenantId]);
    return $del->rowCount();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $picked = $_POST['clean'] ?? [];
    if (!is_array($picked)) { $picked = []; }

    try {
        $pdo->beginTransaction();
        $deleted = 0;
        if ($action === 'reset_all') {
            if (trim($_POST['confirm_reset'] ?? '') !== 'RESET') {
                throw new RuntimeException('Type RESET to confirm the full reset.');
            }
            $deleted += cleanup_sales($pdo, $tenantId);
            $deleted += cleanup_reports($pdo, $tenantId);
            $deleted += cleanup_customers($pdo, $tenantId);
            $deleted += cleanup_products($pdo, $tenantId);
            $deleted += cleanup_staff_users($pdo, $tenantId);
            $_SESSION['flash']['success'] = 'Website reset complete. Admin accounts were kept.';
        } else {
            if (!$picked) {
                throw new RuntimeException('Choose at least one area to clean.');
            }
            if (in_array('sales', $picked, true)) { $deleted += cleanup_sales($pdo, $tenantId); }
            if (in_array('reports', $picked, true)) { $deleted += cleanup_reports($pdo, $tenantId); }
            if (in_array('customers', $picked, true)) { $deleted += cleanup_customers($pdo, $tenantId); }
            if (in_array('products', $picked, true)) { $deleted += cleanup_products($pdo, $tenantId); }
            $_SESSION['flash']['success'] = 'Cleanup complete. Removed ' . number_format($deleted) . ' record' . ($deleted === 1 ? '' : 's') . '.';
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $_SESSION['flash']['error'] = $e->getMessage();
    }
    header('Location: ' . $base);
    exit;
}

$counts = [
    'sales' => cleanup_count($pdo, 'sales', $tenantId) + cleanup_count($pdo, 'orders', $tenantId) + cleanup_count($pdo, 'held_orders', $tenantId),
    'products' => cleanup_count($pdo, 'products', $tenantId) + cleanup_count($pdo, 'categories', $tenantId) + cleanup_count($pdo, 'subcategories', $tenantId) + cleanup_count($pdo, 'book_attributes', $tenantId) + cleanup_count($pdo, 'suppliers', $tenantId) + cleanup_count($pdo, 'stock_intakes', $tenantId) + cleanup_count($pdo, 'stock_intake_items', $tenantId),
    'reports' => cleanup_count($pdo, 'finance_expenses', $tenantId) + cleanup_count($pdo, 'staff_time_logs', $tenantId),
    'customers' => cleanup_count($pdo, 'customers', $tenantId),
];

$page_title = 'Cleanup';
ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-body p-4">
        <h2 class="h5 mb-1">Clean selected data</h2>
        <p class="text-muted small mb-4">Deletes records only for this shop. Admin accounts are not touched.</p>
        <form method="post" onsubmit="return confirm('Delete the selected data for this shop?');">
          <input type="hidden" name="action" value="clean_selected">
          <div class="cleanup-options">
            <label class="cleanup-option">
              <input type="checkbox" name="clean[]" value="sales">
              <span><strong>Sales</strong><small>Direct sales, paid/open tabs, held orders, line items and sale payments. <?php echo number_format($counts['sales']); ?> parent records.</small></span>
            </label>
            <label class="cleanup-option">
              <input type="checkbox" name="clean[]" value="products">
              <span><strong>Products</strong><small>Products, categories, subcategories, book attributes, suppliers and stock intake history. <?php echo number_format($counts['products']); ?> records.</small></span>
            </label>
            <label class="cleanup-option">
              <input type="checkbox" name="clean[]" value="reports">
              <span><strong>Reports</strong><small>Finance expense entries and attendance/time-log report data. <?php echo number_format($counts['reports']); ?> records.</small></span>
            </label>
            <label class="cleanup-option">
              <input type="checkbox" name="clean[]" value="customers">
              <span><strong>Customers</strong><small>Saved loyal/customer contact records. <?php echo number_format($counts['customers']); ?> records.</small></span>
            </label>
          </div>
          <button class="btn btn-primary mt-3"><i class="fas fa-broom me-1"></i>Clean selected</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm border-danger">
      <div class="card-body p-4">
        <h2 class="h5 mb-1 text-danger">Reset website</h2>
        <p class="text-muted small mb-3">Deletes sales, products, reports, customers, staff users and shop operating data. Tenant owner/admin accounts remain.</p>
        <form method="post" onsubmit="return confirm('This will reset the shop data and cannot be undone. Continue?');">
          <input type="hidden" name="action" value="reset_all">
          <label class="form-label small fw-semibold">Type RESET to confirm</label>
          <input name="confirm_reset" class="form-control mb-3" autocomplete="off">
          <button class="btn btn-danger w-100"><i class="fas fa-triangle-exclamation me-1"></i>Reset website data</button>
        </form>
      </div>
    </div>
  </div>
</div>
<style>
.cleanup-options{display:grid;gap:10px;}
.cleanup-option{display:flex;gap:12px;align-items:flex-start;border:1px solid #eef0f4;border-radius:10px;padding:14px;background:#fff;cursor:pointer;}
.cleanup-option input{margin-top:4px;}
.cleanup-option strong{display:block;color:#1f2330;}
.cleanup-option small{display:block;color:#64748b;margin-top:2px;line-height:1.35;}
</style>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
