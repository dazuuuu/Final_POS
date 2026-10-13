<?php
// public/staff/orders/index.php — open tabs shop-wide (add rounds, void, or
// — for a credit sale — email the customer an invoice/delivery note from
// the detail page). Settling payment is a separate job — see staff/payments/.
// Reached from both the staff till and the owner's side (super/orders/
// thin-wraps this) — content adapts by role rather than duplicating the page.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::CREDIT_SALES);

$pdo = Database::pdo();
$O = new Models\OrderModel($pdo);
$orders = $O->openOrders();

$isStaffViewer = TenantContext::role() === 'staff';
$viewBase = $isStaffViewer ? public_url('staff/orders/view.php') : public_url('super/orders/view.php');
$newOrderUrl = public_url(($isStaffViewer ? 'staff/dashboard/' : 'super/pos/') . '?mode=credit');
$payBase = public_url(($isStaffViewer ? 'staff' : 'super') . '/payments/?receipt=');
$canPay = TenantContext::can(Capabilities::PAYMENTS_PROCESS);
$owedTotal = array_sum(array_map(fn($o) => Models\OrderModel::balanceDue($o), $orders));

$page_title = 'Credit sales';
ob_start();
?>
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h1 class="h5 mb-0 fw-bold"><i class="fas fa-receipt me-2 text-warning"></i>Credit sales</h1>
  <a href="<?php echo $newOrderUrl; ?>" class="btn btn-sm btn-primary"><i class="fas fa-plus me-1"></i>New credit sale</a>
</div>
<?php if ($orders): ?>
  <div class="alert alert-warning py-2 small"><strong>KES <?php echo number_format($owedTotal, 0); ?></strong> owed across <?php echo count($orders); ?> credit sale<?php echo count($orders) === 1 ? '' : 's'; ?>.</div>
<?php endif; ?>

<?php if (!$orders): ?>
  <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-5 text-center text-muted">
    <i class="fas fa-inbox fa-2x mb-2 d-block" style="opacity:.3;"></i>
    No credit sales right now.
  </div></div>
<?php else: ?>
  <div class="row g-3">
    <?php foreach ($orders as $o): ?>
    <div class="col-12 col-sm-6 col-lg-4">
      <?php $bal = Models\OrderModel::balanceDue($o); $paid = (float) $o['amount_paid']; ?>
      <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
        <a class="card-body p-3 text-decoration-none text-reset" href="<?php echo $viewBase . '?id=' . (int) $o['id']; ?>">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="fw-semibold"><?php echo htmlspecialchars($o['table_name']); ?></div>
            <span class="badge bg-warning text-dark"><?php echo $paid > 0 ? 'Part-paid' : 'Unpaid'; ?></span>
          </div>
          <div class="text-muted small mb-2">
            <?php echo htmlspecialchars($o['receipt_number']); ?>
            · <?php echo (int) $o['item_count']; ?> item<?php echo (int) $o['item_count'] === 1 ? '' : 's'; ?>
            · <?php echo date('j M, g:i a', strtotime($o['created_at'])); ?>
            <?php if (!empty($o['customer_phone'])): ?> · <?php echo htmlspecialchars($o['customer_phone']); ?><?php endif; ?>
          </div>
          <div class="d-flex justify-content-between small"><span class="text-muted">Total</span><span>KES <?php echo number_format((float) $o['total'], 0); ?></span></div>
          <div class="d-flex justify-content-between small"><span class="text-muted">Paid</span><span class="text-success">KES <?php echo number_format($paid, 0); ?></span></div>
          <div class="d-flex justify-content-between fw-bold"><span>Balance</span><span class="text-danger">KES <?php echo number_format($bal, 0); ?></span></div>
        </a>
        <?php if ($canPay): ?>
        <div class="px-3 pb-3"><a class="btn btn-sm btn-success w-100" href="<?php echo $payBase . urlencode($o['receipt_number']); ?>"><i class="fas fa-money-bill-wave me-1"></i>Receive payment</a></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
$__layout = $isStaffViewer ? 'staff' : 'tenants';
include __DIR__ . '/../../templates/' . $__layout . '/layout.php';
