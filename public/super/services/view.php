<?php
// public/super/services/view.php?id=N — one client visit: who, which
// services, who's doing each, and payment. The front desk assigns staff,
// checks bookings in and cancels; staff mark their own services done; the
// cashier takes payment and prints the receipt.
require_once __DIR__ . '/_common.php';

$id = (int) ($_GET['id'] ?? 0);
$t = $SD->find($id);
$me = (int) TenantContext::userId();
$assignedToMe = $t && in_array($me, array_map('intval', array_column($t['items'], 'staff_id')), true);
if (!$t || (!$isDesk && !$assignedToMe)) {
    $_SESSION['flash']['error'] = 'Visit not found.';
    header('Location: ' . public_url($isDesk ? 'super/services/' : 'super/services/mine.php'));
    exit;
}
$self = public_url('super/services/view.php?id=' . $id);
$canAssign = $isOwner || $canBook;
$canPay = $isOwner || $canCash;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $res = ['ok' => true, 'error' => null];
    $msg = 'Updated.';
    if ($action === 'done') {
        $res = $SD->markDone((int) ($_POST['item_id'] ?? 0), $me, $canAssign);
        $msg = 'Marked done.';
    } elseif ($action === 'assign' && $canAssign) {
        $res = $SD->assign((int) ($_POST['item_id'] ?? 0), (int) ($_POST['staff_id'] ?? 0) ?: null);
        $msg = 'Staff assigned.';
    } elseif ($action === 'add_product' && $isDesk) {
        $res = $SD->addProduct($id, (int) ($_POST['product_id'] ?? 0), (float) ($_POST['quantity'] ?? 1));
        $msg = 'Product added.';
    } elseif ($action === 'remove' && $isDesk) {
        $res = $SD->removeItem((int) ($_POST['item_id'] ?? 0));
        $msg = 'Removed.';
    } elseif ($action === 'checkin' && $canAssign) {
        $SD->checkIn($id);
        $msg = 'Client checked in.';
    } elseif ($action === 'cancel' && $canAssign) {
        $res = $SD->cancel($id);
        $msg = 'Visit cancelled.';
    } elseif ($action === 'pay' && $canPay) {
        $res = $SD->pay($id, [
            'method' => $_POST['method'] ?? '', 'discount_amount' => $_POST['discount_amount'] ?? 0,
            'amount_tendered' => $_POST['amount_tendered'] ?? 0, 'cash_amount' => $_POST['cash_amount'] ?? 0, 'mpesa_amount' => $_POST['mpesa_amount'] ?? 0,
        ]);
        if ($res['ok']) {
            $_SESSION['flash']['success'] = 'Paid — ' . svc_money($res['total']) . ($res['change'] > 0 ? '. Change: ' . svc_money($res['change']) : '') . '.';
            $_SESSION['receipt_popup'] = public_url('super/services/receipt.php?id=' . $id . '&new=1'); // new=1: print right away
            header('Location: ' . public_url('super/services/view.php?id=' . $id));
            exit;
        }
    }
    $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? $msg : $res['error'];
    header('Location: ' . $self);
    exit;
}

$staff = $canAssign ? $SD->staffForShop() : [];
$serviceItems = array_values(array_filter($t['items'], fn($i) => ($i['kind'] ?? 'service') === 'service'));
$productItems = array_values(array_filter($t['items'], fn($i) => ($i['kind'] ?? 'service') === 'product'));
$allDone = !array_filter($serviceItems, fn($i) => $i['status'] !== 'done');
$open = in_array($t['status'], ['booked', 'open'], true);
$stock = $isDesk && $open ? $SD->sellableProducts() : [];
$qf = fn($q) => rtrim(rtrim(number_format((float) $q, 2), '0'), '.');

ob_start();
?>
<div class="card border-0 shadow-sm mb-3" style="border-radius:14px;"><div class="card-body p-4">
  <div class="d-flex justify-content-between flex-wrap gap-2">
    <div>
      <div class="small text-muted"><?php echo svc_h($t['number']); ?> · <?php echo svc_status_badge($t['status']); ?>
        <?php if ($t['scheduled_at']): ?> · <i class="fas fa-calendar me-1"></i><?php echo date('D j M, g:i a', strtotime($t['scheduled_at'])); ?><?php endif; ?></div>
      <div class="fs-4 fw-bold"><?php echo svc_h($t['customer_name']); ?></div>
      <div class="small text-muted">
        <?php echo $t['customer_phone'] ? '<i class="fas fa-phone me-1"></i>' . svc_h($t['customer_phone']) : ''; ?>
        <?php echo $t['customer_email'] ? ' · <i class="fas fa-envelope me-1"></i>' . svc_h($t['customer_email']) : ''; ?>
      </div>
      <?php if ($t['notes']): ?><div class="small mt-1"><i class="fas fa-note-sticky me-1 text-warning"></i><?php echo svc_h($t['notes']); ?></div><?php endif; ?>
    </div>
    <div class="d-flex gap-2 align-items-start flex-wrap">
      <?php if ($isDesk): ?><a data-receipt-popup class="btn btn-sm btn-outline-secondary" href="<?php echo public_url('super/services/receipt.php?id=' . $id); ?>"><i class="fas fa-print me-1"></i><?php echo $t['status'] === 'paid' ? 'Receipt' : 'Invoice'; ?></a><?php endif; ?>
      <?php if ($canAssign && $t['status'] === 'booked'): ?>
        <form method="post"><input type="hidden" name="action" value="checkin"><button class="btn btn-sm btn-primary"><i class="fas fa-door-open me-1"></i>Client arrived</button></form>
      <?php endif; ?>
      <?php if ($canAssign && $open): ?>
        <form method="post" onsubmit="return confirm('Cancel this visit?');"><input type="hidden" name="action" value="cancel"><button class="btn btn-sm btn-outline-danger">Cancel visit</button></form>
      <?php endif; ?>
    </div>
  </div>
</div></div>

<div class="card border-0 shadow-sm mb-3" style="border-radius:14px;"><div class="card-body p-4">
  <?php if ($serviceItems): ?>
  <h2 class="h6 fw-bold mb-3">Services <span class="text-muted fw-normal">(<?php echo count(array_filter($serviceItems, fn($i) => $i['status'] === 'done')); ?>/<?php echo count($serviceItems); ?> done)</span></h2>
  <?php endif; ?>
  <?php foreach ($serviceItems as $it): $mine = (int) $it['staff_id'] === $me; ?>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 border-bottom py-2">
      <div>
        <div class="fw-semibold"><?php echo svc_h($it['service_name']); ?> <?php echo $it['status'] === 'done' ? '<span class="badge bg-success">Done</span>' : '<span class="badge bg-light text-dark border">Waiting</span>'; ?></div>
        <div class="small text-muted">
          <?php if ($canMoney): ?><?php echo svc_money($it['price']); ?> · <?php endif; ?>
          <?php echo $it['staff_name'] ? '<i class="fas fa-user me-1"></i>' . svc_h($it['staff_name']) : 'Not assigned'; ?>
          <?php if ($it['done_at']): ?> · done <?php echo date('g:i a', strtotime($it['done_at'])); ?><?php endif; ?>
          <?php if ($it['commission_amount'] !== null && ($isOwner || ($mine && $canMoney))): ?> · commission <?php echo svc_money($it['commission_amount']); ?> (<?php echo rtrim(rtrim((string) $it['commission_pct'], '0'), '.'); ?>%)<?php endif; ?>
        </div>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <?php if ($canAssign && $t['status'] !== 'cancelled'): ?>
          <form method="post" class="d-flex gap-1">
            <input type="hidden" name="action" value="assign"><input type="hidden" name="item_id" value="<?php echo (int) $it['id']; ?>">
            <select name="staff_id" class="form-select form-select-sm" onchange="this.form.submit()" style="max-width:170px;">
              <option value="0">Not assigned</option>
              <?php foreach ($staff as $u): ?><option value="<?php echo (int) $u['id']; ?>" <?php echo (int) $it['staff_id'] === (int) $u['id'] ? 'selected' : ''; ?>><?php echo svc_h($u['username']); ?></option><?php endforeach; ?>
            </select>
          </form>
        <?php endif; ?>
        <?php if ($it['status'] !== 'done' && $t['status'] !== 'cancelled' && ($mine || $canAssign)): ?>
          <form method="post"><input type="hidden" name="action" value="done"><input type="hidden" name="item_id" value="<?php echo (int) $it['id']; ?>"><button class="btn btn-sm btn-success"><i class="fas fa-check me-1"></i>Done</button></form>
        <?php endif; ?>
        <?php if ($isDesk && $open && count($t['items']) > 1): ?>
          <form method="post" onsubmit="return confirm('Remove <?php echo svc_h($it['service_name']); ?> from this visit?');"><input type="hidden" name="action" value="remove"><input type="hidden" name="item_id" value="<?php echo (int) $it['id']; ?>"><button class="btn btn-sm btn-link text-muted p-0" title="Remove"><i class="fas fa-xmark"></i></button></form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($productItems || ($isDesk && $open && $stock)): ?>
  <h2 class="h6 fw-bold mt-4 mb-2"><i class="fas fa-bag-shopping me-1 text-primary"></i>Products</h2>
  <?php foreach ($productItems as $it): ?>
    <div class="d-flex justify-content-between align-items-center border-bottom py-2 small">
      <div><strong><?php echo $qf($it['quantity']); ?> × <?php echo svc_h($it['service_name']); ?></strong><?php if ($canMoney): ?> <span class="text-muted">@ <?php echo svc_money($it['price']); ?> = <?php echo svc_money((float) $it['price'] * (float) $it['quantity']); ?></span><?php endif; ?></div>
      <?php if ($isDesk && $open && count($t['items']) > 1): ?>
        <form method="post" onsubmit="return confirm('Remove this product? It goes back into stock.');"><input type="hidden" name="action" value="remove"><input type="hidden" name="item_id" value="<?php echo (int) $it['id']; ?>"><button class="btn btn-sm btn-link text-muted p-0" title="Remove"><i class="fas fa-xmark"></i></button></form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if ($isDesk && $open && $stock): ?>
    <form method="post" class="d-flex gap-2 mt-2 flex-wrap">
      <input type="hidden" name="action" value="add_product">
      <select name="product_id" class="form-select form-select-sm" style="flex:1;min-width:180px;" required>
        <option value="">Add a product the client is buying…</option>
        <?php foreach ($stock as $p): ?><option value="<?php echo (int) $p['id']; ?>"><?php echo svc_h($p['name']); ?><?php echo $canMoney ? ' — ' . svc_money($p['sell_price']) : ''; ?> · <?php echo $qf($p['quantity']); ?> left</option><?php endforeach; ?>
      </select>
      <input type="number" name="quantity" value="1" min="0.01" step="any" class="form-control form-control-sm" style="width:80px;" aria-label="Quantity">
      <button class="btn btn-sm btn-outline-primary"><i class="fas fa-plus me-1"></i>Add</button>
    </form>
  <?php endif; ?>
  <?php endif; ?>
  <?php if ($canMoney): ?>
    <div class="d-flex justify-content-end gap-4 mt-3 small">
      <?php if ((float) $t['discount_amount'] > 0): ?><span>Discount <strong>− <?php echo svc_money($t['discount_amount']); ?></strong></span><?php endif; ?>
      <span class="fs-6">Total <strong><?php echo svc_money($t['status'] === 'paid' ? $t['total'] : $t['subtotal']); ?></strong></span>
    </div>
  <?php endif; ?>
</div></div>

<?php if ($canPay && $open): ?>
<div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
  <h2 class="h6 fw-bold mb-1">Take payment</h2>
  <?php if (!$allDone): ?><div class="small text-warning mb-2"><i class="fas fa-triangle-exclamation me-1"></i>Not every service is marked done yet — you can still take payment.</div><?php endif; ?>
  <form method="post" class="row g-2 align-items-end" id="payForm" data-subtotal="<?php echo (float) $t['subtotal']; ?>">
    <input type="hidden" name="action" value="pay">
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Discount</label><input type="number" step="1" min="0" name="discount_amount" class="form-control" id="payDisc" placeholder="0"></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Paid by</label>
      <select name="method" class="form-select" id="payMethod"><option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="split">Cash + M-Pesa</option></select></div>
    <div class="col-6 col-md-3 pay-cash"><label class="form-label small mb-1">Cash given</label><input type="number" step="1" min="0" name="amount_tendered" class="form-control" id="payGiven"></div>
    <div class="col-6 col-md-3 pay-split" style="display:none;"><label class="form-label small mb-1">Cash part</label><input type="number" step="1" min="0" name="cash_amount" class="form-control"></div>
    <div class="col-6 col-md-3 pay-split" style="display:none;"><label class="form-label small mb-1">M-Pesa part</label><input type="number" step="1" min="0" name="mpesa_amount" class="form-control"></div>
    <div class="col-12 d-flex justify-content-between align-items-center mt-2">
      <div>To pay <strong class="fs-5" id="payTotal"><?php echo svc_money($t['subtotal']); ?></strong> <span class="small text-muted" id="payChange"></span></div>
      <button class="btn btn-success px-4"><i class="fas fa-cash-register me-1"></i>Record payment</button>
    </div>
  </form>
</div></div>
<script>
(function () {
  var f = document.getElementById('payForm'), sub = parseFloat(f.dataset.subtotal);
  function upd() {
    var d = Math.min(Math.max(parseFloat(document.getElementById('payDisc').value) || 0, 0), sub), tot = sub - d, m = document.getElementById('payMethod').value;
    document.getElementById('payTotal').textContent = 'KES ' + tot.toLocaleString('en-KE');
    f.querySelectorAll('.pay-cash').forEach(function (x) { x.style.display = m === 'cash' ? '' : 'none'; });
    f.querySelectorAll('.pay-split').forEach(function (x) { x.style.display = m === 'split' ? '' : 'none'; });
    var g = parseFloat(document.getElementById('payGiven').value) || 0;
    document.getElementById('payChange').textContent = m === 'cash' && g > tot ? '· change KES ' + (g - tot).toLocaleString('en-KE') : '';
  }
  f.addEventListener('input', upd); f.addEventListener('change', upd); upd();
})();
</script>
<?php elseif ($t['status'] === 'paid' && $canMoney): ?>
  <div class="alert alert-success">Paid <?php echo svc_money($t['total']); ?> by <?php echo $t['payment_method'] === 'split' ? 'cash + M-Pesa' : ($t['payment_method'] === 'mpesa' ? 'M-Pesa' : 'cash'); ?> on <?php echo date('j M Y, g:i a', strtotime($t['paid_at'])); ?><?php echo $t['paid_by_name'] ? ' · received by ' . svc_h($t['paid_by_name']) : ''; ?>.</div>
<?php endif; ?>
<?php
svc_render('Visit ' . $t['number'], ob_get_clean());
