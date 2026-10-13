<?php
// public/staff/orders/held.php — sales parked with "Hold" (no stock touched
// yet). Resume opens one back on the selling screen; Discard drops it.
// Owners reach it at super/orders/held.php (thin wrapper).
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::SALES_RECORD);

$pdo = Database::pdo();
$HO = new Models\HeldOrderModel($pdo);
$isStaffViewer = TenantContext::role() === 'staff';
$posUrl = public_url($isStaffViewer ? 'staff/dashboard/' : 'super/pos/');
$selfUrl = public_url(($isStaffViewer ? 'staff' : 'super') . '/orders/held.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'discard') {
    $id = (int) ($_POST['id'] ?? 0);
    $ok = $HO->discard($id);
    $_SESSION['flash'][$ok ? 'success' : 'error'] = $ok ? 'Held sale discarded.' : 'Could not discard that sale.';
    header('Location: ' . $selfUrl);
    exit;
}

$held = $HO->listForTenant();
$page_title = 'Held sales';
ob_start();
?>
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h1 class="h5 mb-0 fw-bold"><i class="fas fa-pause me-2 text-primary"></i>Held sales</h1>
  <a href="<?php echo $posUrl; ?>" class="btn btn-sm btn-primary"><i class="fas fa-plus me-1"></i>New sale</a>
</div>

<?php if (!$held): ?>
  <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-5 text-center text-muted">
    <i class="fas fa-pause fa-2x mb-2 d-block" style="opacity:.3;"></i>
    No sales on hold right now. Use <strong>Hold</strong> on the selling screen to park a customer's cart and serve the next one.
  </div></div>
<?php else: ?>
  <div class="row g-3">
    <?php foreach ($held as $h): ?>
    <div class="col-12 col-sm-6 col-lg-4">
      <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
        <div class="card-body p-3">
          <div class="fw-semibold mb-1"><?php echo htmlspecialchars($h['customer_name']); ?>
            <?php if (($h['sale_type'] ?? 'retail') === 'wholesale'): ?><span class="badge bg-secondary">Wholesale</span><?php endif; ?></div>
          <div class="text-muted small mb-2">
            <?php echo (int) $h['item_count']; ?> item<?php echo (int) $h['item_count'] === 1 ? '' : 's'; ?>
            · by <?php echo htmlspecialchars($h['staff_name'] ?? '—'); ?>
            · <?php echo date('j M, g:i a', strtotime($h['created_at'])); ?>
          </div>
          <div class="fw-bold fs-5 mb-3">KES <?php echo number_format((float) $h['total'], 0); ?></div>
          <div class="d-flex gap-2">
            <a class="btn btn-sm btn-primary flex-fill" href="<?php echo $posUrl . '?resume=' . (int) $h['id']; ?>">Resume</a>
            <form method="post" class="d-inline" onsubmit="return confirm('Discard this held sale?');">
              <input type="hidden" name="action" value="discard">
              <input type="hidden" name="id" value="<?php echo (int) $h['id']; ?>">
              <button class="btn btn-sm btn-outline-danger">Discard</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
$__layout = $isStaffViewer ? 'staff' : 'tenants';
include __DIR__ . '/../../templates/' . $__layout . '/layout.php';
