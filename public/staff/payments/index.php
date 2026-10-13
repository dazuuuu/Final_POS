<?php
// public/staff/payments/index.php
// Payments desk: pick a credit sale (or look one up by its invoice number)
// and record what the customer pays — the full balance or just a part of it.
// Every instalment is logged and the printed receipt shows paid / balance.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::PAYMENTS_PROCESS);

$pdo = Database::pdo();
$O = new Models\OrderModel($pdo);
$isStaffViewer = TenantContext::role() === 'staff';
$area = $isStaffViewer ? 'staff' : 'super';
$selfUrl = public_url($area . '/payments/');

$error = '';
$receiptQuery = trim($_GET['receipt'] ?? $_POST['receipt_number'] ?? '');
$order = $receiptQuery !== '' ? $O->findByReceipt($receiptQuery) : null;
if (!$order && (int) ($_GET['id'] ?? 0) > 0) {
    $order = $O->find((int) $_GET['id']);
    if ($order) { $receiptQuery = $order['receipt_number']; }
}

if ($receiptQuery !== '' && !$order) {
    $error = 'No invoice found with that number. Check it and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'settle' && $order) {
    if ($order['status'] !== 'open') {
        $error = 'This invoice is not open for payment.';
    } else {
        $res = $O->recordPayment((int) $order['id'], [
            'method'          => $_POST['payment_method'] ?? '',
            'amount'          => $_POST['amount'] ?? 0,
            'cash_amount'     => $_POST['cash_amount'] ?? 0,
            'mpesa_amount'    => $_POST['mpesa_amount'] ?? 0,
            'amount_tendered' => $_POST['amount_tendered'] ?? 0,
        ], TenantContext::userId());
        if ($res['ok']) {
            $_SESSION['flash']['success'] = $res['fully_paid']
                ? 'Paid in full — ' . $order['receipt_number'] . ' is settled.'
                : 'KES ' . number_format($res['amount'], 0) . ' received. ' . $order['table_name'] . ' still owes KES ' . number_format($res['balance'], 0) . '.';
            header('Location: ' . public_url($area . '/orders/receipt.php?id=' . (int) $order['id']));
            exit;
        }
        $error = $res['error'] ?? 'Could not record the payment.';
    }
}

$items = $order ? $O->items((int) $order['id']) : [];
$payments = $order ? $O->payments((int) $order['id']) : [];
$openOrders = $order ? [] : $O->openOrders();
$page_title = 'Payments';
ob_start();
?>
<h1 class="h5 mb-4 fw-bold"><i class="fas fa-cash-register me-2 text-primary"></i>Payments</h1>

<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
  <div class="card-body p-4">
    <form method="get" action="<?php echo $selfUrl; ?>" class="row g-2 align-items-end">
      <div class="col-12 col-sm-8">
        <label class="form-label small mb-1">Invoice number</label>
        <input type="text" name="receipt" class="form-control form-control-lg text-uppercase" placeholder="e.g. ORD-000123"
               value="<?php echo htmlspecialchars($receiptQuery); ?>">
      </div>
      <div class="col-12 col-sm-4">
        <button class="btn btn-primary btn-lg w-100"><i class="fas fa-magnifying-glass me-1"></i>Find</button>
      </div>
    </form>
  </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<?php if (!$order): ?>
  <div class="card border-0 shadow-sm" style="border-radius:14px;">
    <div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Customers who owe you</h2>
      <?php if (!$openOrders): ?>
        <div class="text-muted small">No unpaid credit sales. 🎉</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr class="small text-muted text-uppercase"><th>Customer</th><th>Invoice</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($openOrders as $o): $bal = Models\OrderModel::balanceDue($o); ?>
            <tr>
              <td class="fw-semibold small"><?php echo htmlspecialchars($o['table_name']); ?><div class="text-muted" style="font-size:.72rem;"><?php echo date('j M Y', strtotime($o['created_at'])); ?><?php echo !empty($o['customer_phone']) ? ' · ' . htmlspecialchars($o['customer_phone']) : ''; ?></div></td>
              <td class="small"><?php echo htmlspecialchars($o['receipt_number']); ?></td>
              <td class="small text-end"><?php echo number_format((float) $o['total'], 0); ?></td>
              <td class="small text-end text-success"><?php echo number_format((float) $o['amount_paid'], 0); ?></td>
              <td class="small text-end fw-bold text-danger"><?php echo number_format($bal, 0); ?></td>
              <td class="text-end"><a class="btn btn-sm btn-success" href="<?php echo $selfUrl . '?receipt=' . urlencode($o['receipt_number']); ?>">Receive</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php else:
    $statusBadge = [
        'open' => '<span class="badge bg-warning text-dark">' . ((float) $order['amount_paid'] > 0 ? 'Part-paid' : 'Unpaid') . '</span>',
        'paid' => '<span class="badge bg-success">Paid</span>',
        'void' => '<span class="badge bg-secondary">Voided</span>',
    ][$order['status']] ?? '';
    $paidSoFar = Models\OrderModel::paidSoFar($order);
    $balance = Models\OrderModel::balanceDue($order);
?>
<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
  <div class="card-body p-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
      <div>
        <div class="fw-bold fs-5"><?php echo htmlspecialchars($order['table_name']); ?> <?php echo $statusBadge; ?></div>
        <div class="text-muted small">Invoice <?php echo htmlspecialchars($order['receipt_number']); ?> · opened by <?php echo htmlspecialchars($order['opened_by_name'] ?? '—'); ?> · <?php echo date('j M Y, g:i a', strtotime($order['created_at'])); ?></div>
      </div>
      <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?php echo $selfUrl; ?>"><i class="fas fa-list me-1"></i>All balances</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?php echo public_url($area . '/orders/receipt.php?id=' . (int) $order['id']); ?>"><i class="fas fa-receipt me-1"></i>Receipt</a>
      </div>
    </div>

    <?php foreach ($items as $it): ?>
      <div class="d-flex justify-content-between border-bottom py-2">
        <div>
          <div class="fw-semibold" style="font-size:.9rem;"><?php echo htmlspecialchars($it['product_name']); ?></div>
          <small class="text-muted">KES <?php echo number_format((float) $it['unit_price'], 0); ?> × <?php echo rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.'); ?></small>
        </div>
        <div class="fw-bold">KES <?php echo number_format((float) $it['line_total'], 0); ?></div>
      </div>
    <?php endforeach; ?>

    <div class="d-flex justify-content-between pt-3"><span class="fw-semibold">Total</span><span class="fw-bold">KES <?php echo number_format((float) $order['total'], 0); ?></span></div>
    <div class="d-flex justify-content-between"><span class="text-muted">Paid so far</span><span class="text-success fw-semibold">KES <?php echo number_format($paidSoFar, 0); ?></span></div>
    <div class="d-flex justify-content-between mb-3"><span class="fw-semibold">Balance due</span><span class="fw-bold fs-4 <?php echo $balance > 0 ? 'text-danger' : ''; ?>">KES <?php echo number_format($balance, 0); ?></span></div>

    <?php if ($payments): ?>
      <div class="bg-light rounded p-2 mb-3 small">
        <div class="fw-semibold mb-1">Payments received</div>
        <?php foreach ($payments as $pm): ?>
          <div class="d-flex justify-content-between">
            <span><?php echo date('j M Y, g:i a', strtotime($pm['created_at'])); ?> · <?php echo $pm['method'] === 'mpesa' ? 'M-Pesa' : ucfirst($pm['method']); ?><?php echo $pm['received_by_name'] ? ' · ' . htmlspecialchars($pm['received_by_name']) : ''; ?></span>
            <span class="fw-semibold">KES <?php echo number_format((float) $pm['amount'], 0); ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($order['status'] === 'open'): ?>
      <form method="post" id="paymentForm" action="<?php echo $selfUrl . '?receipt=' . urlencode($order['receipt_number']); ?>">
        <input type="hidden" name="action" value="settle">
        <input type="hidden" name="receipt_number" value="<?php echo htmlspecialchars($order['receipt_number']); ?>">
        <div class="mb-3">
          <label class="form-label small fw-semibold">Amount paying now</label>
          <div class="input-group">
            <span class="input-group-text">KES</span>
            <input type="number" step="0.01" min="0.01" max="<?php echo $balance; ?>" name="amount" id="payAmount" class="form-control form-control-lg" value="<?php echo $balance; ?>">
            <button type="button" class="btn btn-outline-secondary" id="fullBtn">Full balance</button>
          </div>
          <div class="form-text">A deposit or part-payment is fine — the rest stays on their balance.</div>
        </div>
        <div class="btn-group w-100 mb-3" role="group">
          <input type="radio" class="btn-check" name="payment_method" id="payCash" value="cash" checked>
          <label class="btn btn-outline-primary" for="payCash"><i class="fas fa-money-bill-wave me-1"></i>Cash</label>
          <input type="radio" class="btn-check" name="payment_method" id="payMpesa" value="mpesa">
          <label class="btn btn-outline-success" for="payMpesa"><i class="fas fa-mobile-screen me-1"></i>M-Pesa</label>
          <input type="radio" class="btn-check" name="payment_method" id="paySplit" value="split">
          <label class="btn btn-outline-secondary" for="paySplit"><i class="fas fa-divide me-1"></i>Split (both)</label>
        </div>
        <div id="cashBox" class="row g-2 mb-2">
          <div class="col-6">
            <label class="form-label small">Cash handed over <span class="text-muted">(optional)</span></label>
            <input type="number" step="0.01" min="0" id="cashGiven" class="form-control" placeholder="Same as amount">
          </div>
        </div>
        <div id="splitBox" style="display:none;" class="row g-2 mb-2">
          <div class="col-6">
            <label class="form-label small">Cash portion</label>
            <input type="number" step="0.01" min="0" name="cash_amount" id="cashPortion" class="form-control">
          </div>
          <div class="col-6">
            <label class="form-label small">M-Pesa portion</label>
            <input type="number" step="0.01" min="0" name="mpesa_amount" id="mpesaPortion" class="form-control">
          </div>
        </div>
        <input type="hidden" name="amount_tendered" id="amountTendered" value="">
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label small">Change to give back</label>
            <div class="form-control bg-light fw-semibold" id="changeOut">KES 0</div>
          </div>
          <div class="col-6">
            <label class="form-label small">Balance after this</label>
            <div class="form-control bg-light fw-semibold" id="afterOut">KES 0</div>
          </div>
        </div>
        <button type="submit" class="btn btn-success btn-lg w-100" id="payBtn"><i class="fas fa-check me-1"></i>Record payment</button>
      </form>
      <script>
        (function () {
          var BALANCE = <?php echo json_encode($balance); ?>;
          var amountEl = document.getElementById('payAmount');
          var cashBox = document.getElementById('cashBox'), splitBox = document.getElementById('splitBox');
          function num(id) { return parseFloat(document.getElementById(id).value) || 0; }
          function money(n) { return 'KES ' + (Math.round(n * 100) / 100).toLocaleString('en-KE', {maximumFractionDigits: 2}); }
          function mode() { return document.querySelector('input[name=payment_method]:checked').value; }
          function update() {
            var m = mode();
            cashBox.style.display = m === 'cash' ? 'flex' : 'none';
            splitBox.style.display = m === 'split' ? 'flex' : 'none';
            amountEl.readOnly = m === 'split';
            var amount, handed;
            if (m === 'split') {
              handed = num('cashPortion') + num('mpesaPortion');
              amount = Math.min(handed, BALANCE);
              amountEl.value = Math.round(amount * 100) / 100;
            } else {
              amount = Math.min(parseFloat(amountEl.value) || 0, BALANCE);
              handed = m === 'cash' ? (num('cashGiven') || amount) : amount;
            }
            document.getElementById('amountTendered').value = m === 'mpesa' ? '' : handed;
            document.getElementById('changeOut').textContent = money(Math.max(0, handed - amount));
            document.getElementById('afterOut').textContent = money(Math.max(0, BALANCE - amount));
            document.getElementById('payBtn').innerHTML = '<i class="fas fa-check me-1"></i>' + (amount + 0.004 >= BALANCE ? 'Mark fully paid — ' : 'Record part-payment — ') + money(amount);
          }
          document.getElementById('fullBtn').addEventListener('click', function () { amountEl.value = BALANCE; update(); });
          document.querySelectorAll('input[name=payment_method]').forEach(function (r) { r.addEventListener('change', update); });
          ['payAmount', 'cashGiven', 'cashPortion', 'mpesaPortion'].forEach(function (id) { document.getElementById(id).addEventListener('input', update); });
          document.getElementById('paymentForm').addEventListener('submit', function (e) {
            var m = mode(), amount = parseFloat(amountEl.value) || 0;
            if (amount <= 0) { e.preventDefault(); alert('Enter the amount the customer is paying.'); return; }
            if (m === 'cash' && num('cashGiven') > 0 && num('cashGiven') < amount) { e.preventDefault(); alert('Cash handed over is less than the amount being paid.'); return; }
            document.getElementById('payBtn').disabled = true;
          });
          update();
        })();
      </script>
    <?php elseif ($order['status'] === 'paid'): ?>
      <div class="alert alert-success mb-0">Fully settled<?php echo !empty($order['paid_at']) ? ' on ' . date('j M Y, g:i a', strtotime($order['paid_at'])) : ''; ?>.</div>
    <?php else: ?>
      <div class="alert alert-secondary mb-0">This sale was voided — nothing to collect.</div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
$__layout = $isStaffViewer ? 'staff' : 'tenants';
include __DIR__ . '/../../templates/' . $__layout . '/layout.php';
