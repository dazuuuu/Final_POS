<?php
// public/super/services/payouts.php — pay staff their commission. Shows what
// each staff member is owed since they were last paid; "Pay" records the
// payout and their balance goes back to zero. Owner, or staff given
// "Services: pay staff commission".
require_once __DIR__ . '/_common.php';
svc_require($isOwner || TenantContext::can(Capabilities::SERVICES_PAYOUT));

$self = public_url('super/services/payouts.php');
$shopIds = array_column(ShopContext::shops() ?: [['id' => TenantContext::tenantId()]], 'id');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = $SD->payStaff((int) ($_POST['staff_id'] ?? 0), $shopIds, (string) ($_POST['method'] ?? 'cash'), trim((string) ($_POST['note'] ?? '')));
    $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok']
        ? 'Paid ' . svc_money($res['amount']) . ' for ' . $res['items'] . ' service(s). Their commission balance is now zero.'
        : $res['error'];
    header('Location: ' . $self);
    exit;
}

$owed = $SD->unpaidBalances($shopIds);
$history = $SD->payouts();
$methods = ['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'bank' => 'Bank'];

ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-1">Owed to staff</h2>
      <p class="small text-muted mb-3">Commission earned since each person was last paid. Paying them sets their balance back to zero; it grows again as they do more services.</p>
      <?php if (!$owed): ?><div class="text-muted small py-3 text-center">Nobody is owed commission right now.</div><?php endif; ?>
      <?php foreach ($owed as $o): ?>
        <form method="post" class="border rounded-3 p-3 mb-2" onsubmit="return confirm('Pay <?php echo svc_h($o['username']); ?> <?php echo svc_money($o['amount']); ?>? Their balance goes back to zero.');">
          <input type="hidden" name="staff_id" value="<?php echo (int) $o['staff_id']; ?>">
          <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
            <div><strong><?php echo svc_h($o['username'] ?? '?'); ?></strong>
              <div class="small text-muted"><?php echo (int) $o['services']; ?> service(s)<?php echo $o['own_jobs'] ? ' · ' . (int) $o['own_jobs'] . ' recorded by them' : ''; ?> · since <?php echo date('j M', strtotime($o['since'])); ?></div></div>
            <div class="fw-bold text-success fs-5"><?php echo svc_money($o['amount']); ?></div>
          </div>
          <div class="d-flex gap-2 mt-2 flex-wrap">
            <select name="method" class="form-select form-select-sm" style="max-width:120px"><?php foreach ($methods as $k => $l): ?><option value="<?php echo $k; ?>"><?php echo $l; ?></option><?php endforeach; ?></select>
            <input name="note" class="form-control form-control-sm" style="flex:1;min-width:140px" placeholder="Note / M-Pesa code (optional)" maxlength="255">
            <button class="btn btn-sm btn-success"><i class="fas fa-hand-holding-dollar me-1"></i>Pay <?php echo svc_money($o['amount']); ?></button>
          </div>
        </form>
      <?php endforeach; ?>
    </div></div>
  </div>
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Payment history</h2>
      <?php if (!$history): ?><div class="text-muted small">No payouts yet.</div><?php endif; ?>
      <?php foreach ($history as $p): ?>
        <div class="d-flex justify-content-between border-bottom py-2 small">
          <div><strong><?php echo svc_h($p['staff_name']); ?></strong> · <?php echo $methods[$p['method']] ?? $p['method']; ?>
            <div class="text-muted"><?php echo date('j M Y, g:i a', strtotime($p['created_at'])); ?> · by <?php echo svc_h($p['paid_by_name'] ?? '—'); ?><?php echo $p['note'] ? ' · ' . svc_h($p['note']) : ''; ?></div></div>
          <div class="fw-semibold"><?php echo svc_money($p['amount']); ?></div>
        </div>
      <?php endforeach; ?>
    </div></div>
  </div>
</div>
<?php
svc_render('Pay staff', ob_get_clean());
