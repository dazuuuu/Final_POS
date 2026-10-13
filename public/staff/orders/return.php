<?php
// public/staff/orders/return.php[?id=N | ?receipt=RCP-000123] — sales returns.
// Find the sale, tick how many of each item came back, and the stock goes
// back on the shelf. On a paid sale the refund is taken off the recorded
// cash / M-Pesa; on a credit sale the customer's balance just drops.
// Owners reach it at super/orders/return.php (thin wrapper).
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::PAYMENTS_PROCESS);

$pdo = Database::pdo();
$O = new Models\OrderModel($pdo);
$isStaffViewer = TenantContext::role() === 'staff';
$area = $isStaffViewer ? 'staff' : 'super';
$selfUrl = public_url($area . '/orders/return.php');

$error = '';
$query = trim($_GET['receipt'] ?? '');
$id = (int) ($_GET['id'] ?? 0);
$order = $id > 0 ? $O->find($id) : ($query !== '' ? $O->findByReceipt($query) : null);
if ($query !== '' && !$order) {
    // Edited sales get a -R2 suffix; let them type just the base number.
    $stmt = $pdo->prepare('SELECT id FROM orders WHERE tenant_id = ? AND receipt_number LIKE ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([TenantContext::tenantId(), strtoupper($query) . '%']);
    $found = (int) $stmt->fetchColumn();
    $order = $found ? $O->find($found) : null;
    if (!$order) { $error = 'No sale found with that receipt number.'; }
}

// Electronics: a tracked item is returned by ticking its serials (the quantity
// follows), and those exact units go back on the shelf.
$EL = $order && Models\ElectronicsModel::enabled() ? new Models\ElectronicsModel($pdo) : null;
$soldSerials = $EL ? $EL->soldOnOrder((int) $order['id']) : [];

if ($order && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'return') {
    $qtyIn = (array) ($_POST['qty'] ?? []);
    $pickedSerials = [];
    foreach ($soldSerials as $itemId => $units) {
        $valid = array_column($units, 'id');
        $picked = array_values(array_intersect(array_map('intval', (array) ($_POST['serial'][$itemId] ?? [])), $valid));
        $qtyIn[$itemId] = count($picked);
        $pickedSerials = array_merge($pickedSerials, $picked);
    }
    $res = $O->returnItems((int) $order['id'], $qtyIn, (string) ($_POST['reason'] ?? ''), (string) ($_POST['refund_method'] ?? 'cash'), TenantContext::userId());
    if ($res['ok']) {
        if ($EL) { $EL->restockFromRefund($pickedSerials, (int) $order['id']); }
        $msg = 'Return recorded — KES ' . number_format($res['return_value'], 0) . ' of goods back in stock.';
        if ($res['refund'] > 0) { $msg .= ' Give the customer KES ' . number_format($res['refund'], 0) . '.'; }
        elseif ($order['status'] === 'open') { $msg .= ' Their credit balance has been reduced.'; }
        $_SESSION['flash']['success'] = $msg;
        header('Location: ' . public_url($area . '/orders/receipt.php?id=' . (int) $order['id']));
        exit;
    }
    $error = $res['error'];
}

$items = $order ? $O->items((int) $order['id']) : [];
$recent = $order ? [] : $O->recentReturns(30);
$page_title = 'Returns';
ob_start();
?>
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h1 class="h5 mb-0 fw-bold"><i class="fas fa-rotate-left me-2 text-danger"></i>Returns</h1>
  <?php if ($order): ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo $selfUrl; ?>"><i class="fas fa-arrow-left me-1"></i>All returns</a><?php endif; ?>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<?php if (!$order): ?>
  <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
    <div class="card-body p-4">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-12 col-sm-8">
          <label class="form-label small mb-1">Receipt / invoice number on the customer's slip</label>
          <input type="text" name="receipt" class="form-control form-control-lg text-uppercase" placeholder="e.g. RCP-000123" value="<?php echo htmlspecialchars($query); ?>" autofocus>
        </div>
        <div class="col-12 col-sm-4"><button class="btn btn-primary btn-lg w-100"><i class="fas fa-magnifying-glass me-1"></i>Find sale</button></div>
      </form>
    </div>
  </div>

  <div class="card border-0 shadow-sm" style="border-radius:14px;">
    <div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Recent returns</h2>
      <?php if (!$recent): ?>
        <div class="text-muted small">No returns recorded yet.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr class="small text-muted text-uppercase"><th>Date</th><th>Sale</th><th>Items</th><th class="text-end">Value</th><th class="text-end">Refunded</th><th>Reason</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $r): ?>
            <tr class="small">
              <td><?php echo date('j M, g:i a', strtotime($r['created_at'])); ?></td>
              <td><a href="<?php echo public_url($area . '/orders/receipt.php?id=' . (int) $r['order_id']); ?>"><?php echo htmlspecialchars($r['receipt_number']); ?></a><div class="text-muted"><?php echo htmlspecialchars($r['table_name']); ?></div></td>
              <td><?php echo htmlspecialchars($r['summary'] ?? ''); ?></td>
              <td class="text-end"><?php echo number_format((float) $r['return_value'], 0); ?></td>
              <td class="text-end"><?php echo (float) $r['refund_amount'] > 0 ? number_format((float) $r['refund_amount'], 0) . ' <span class="text-muted">' . ($r['refund_method'] === 'mpesa' ? 'M-Pesa' : 'cash') . '</span>' : '—'; ?></td>
              <td><?php echo htmlspecialchars($r['reason'] ?? ''); ?><div class="text-muted">by <?php echo htmlspecialchars($r['processed_by_name'] ?? '—'); ?></div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php elseif ($order['status'] === 'void' || !$items): ?>
  <div class="alert alert-secondary">Sale <?php echo htmlspecialchars($order['receipt_number']); ?> has nothing left to return (it was voided or everything already came back).</div>
<?php else:
    $isCredit = $order['status'] === 'open';
    $paidSoFar = Models\OrderModel::paidSoFar($order);
?>
  <div class="card border-0 shadow-sm" style="border-radius:14px;">
    <div class="card-body p-4">
      <div class="mb-3">
        <div class="fw-bold fs-5"><?php echo htmlspecialchars($order['table_name']); ?>
          <span class="badge <?php echo $isCredit ? 'bg-warning text-dark' : 'bg-success'; ?>"><?php echo $isCredit ? 'Credit — unpaid balance' : 'Paid'; ?></span></div>
        <div class="text-muted small"><?php echo htmlspecialchars($order['receipt_number']); ?> · <?php echo date('j M Y, g:i a', strtotime($order['created_at'])); ?> · total KES <?php echo number_format((float) $order['total'], 0); ?> · paid KES <?php echo number_format($paidSoFar, 0); ?></div>
      </div>
      <?php if ($soldSerials): ?>
        <div class="alert alert-info small py-2">This is a refund. For a faulty device the customer wants repaired, use <a href="<?php echo public_url('super/electronics/repairs.php'); ?>">Electronics → Warranty &amp; repairs</a> instead.</div>
      <?php endif; ?>
      <form method="post" id="returnForm">
        <input type="hidden" name="action" value="return">
        <div class="table-responsive">
          <table class="table align-middle">
            <thead><tr class="small text-muted text-uppercase"><th>Item</th><th class="text-center">Sold</th><th style="width:130px;">Returning</th><th class="text-end">Value</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
              <tr>
                <td class="small fw-semibold"><?php echo htmlspecialchars($it['product_name']); ?><div class="text-muted fw-normal">KES <?php echo number_format((float) $it['unit_price'], 2); ?> each</div></td>
                <td class="text-center small"><?php echo rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.'); ?></td>
                <td>
                  <?php if (!empty($soldSerials[(int) $it['id']])): ?>
                    <input type="hidden" class="retQty" value="0" max="<?php echo (float) $it['quantity']; ?>" data-price="<?php echo (float) $it['unit_price']; ?>">
                    <?php foreach ($soldSerials[(int) $it['id']] as $u): ?>
                      <label class="d-block small text-nowrap"><input type="checkbox" class="form-check-input me-1 retSerial" name="serial[<?php echo (int) $it['id']; ?>][]" value="<?php echo (int) $u['id']; ?>"><?php echo htmlspecialchars($u['serial']); ?></label>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <input type="number" name="qty[<?php echo (int) $it['id']; ?>]" class="form-control form-control-sm retQty" min="0" max="<?php echo (float) $it['quantity']; ?>" step="any" value="0" data-price="<?php echo (float) $it['unit_price']; ?>">
                  <?php endif; ?>
                </td>
                <td class="text-end small retVal">0</td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <label class="form-label small">Reason <span class="text-muted">(optional)</span></label>
            <input type="text" name="reason" class="form-control" maxlength="255" placeholder="e.g. damaged, wrong size">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label small">Refund paid out by</label>
            <select name="refund_method" class="form-select">
              <option value="cash">Cash</option>
              <option value="mpesa">M-Pesa</option>
            </select>
            <div class="form-text"><?php echo $isCredit ? 'Credit sale: the return reduces what they owe; money is only refunded if they had already paid more than the new total.' : 'Paid sale: the value of the goods is refunded.'; ?></div>
          </div>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
          <div class="fw-semibold">Returning goods worth <span id="retTotal">KES 0</span></div>
          <button class="btn btn-danger" id="retBtn" disabled><i class="fas fa-rotate-left me-1"></i>Record return</button>
        </div>
      </form>
    </div>
  </div>
  <script>
  (function () {
    function upd() {
      var t = 0;
      document.querySelectorAll('tr').forEach(function (tr) {
        var boxes = tr.querySelectorAll('.retSerial');
        if (boxes.length) { tr.querySelector('.retQty').value = tr.querySelectorAll('.retSerial:checked').length; }
      });
      document.querySelectorAll('.retQty').forEach(function (inp) {
        var q = Math.min(parseFloat(inp.value) || 0, parseFloat(inp.max));
        var v = q * parseFloat(inp.dataset.price);
        t += v;
        inp.closest('tr').querySelector('.retVal').textContent = v.toLocaleString('en-KE', {maximumFractionDigits: 2});
      });
      document.getElementById('retTotal').textContent = 'KES ' + t.toLocaleString('en-KE', {maximumFractionDigits: 2});
      document.getElementById('retBtn').disabled = t <= 0;
    }
    document.querySelectorAll('.retQty').forEach(function (i) { i.addEventListener('input', upd); });
    document.querySelectorAll('.retSerial').forEach(function (i) { i.addEventListener('change', upd); });
    document.getElementById('returnForm').addEventListener('submit', function (e) {
      if (!confirm('Record this return? The items go back into stock.')) { e.preventDefault(); return; }
      document.getElementById('retBtn').disabled = true;
    });
  })();
  </script>
<?php endif; ?>
<?php
$content = ob_get_clean();
$__layout = $isStaffViewer ? 'staff' : 'tenants';
include __DIR__ . '/../../templates/' . $__layout . '/layout.php';
