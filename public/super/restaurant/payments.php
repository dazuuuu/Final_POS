<?php
// public/super/restaurant/payments.php — take payment for placed orders.
// Find an order by its receipt number (from the printed order slip) or pick
// it from the unpaid list, take cash / M-Pesa / card (or split), then print
// the final receipt. Stock is deducted here (see rest_pay_order).
require_once __DIR__ . '/../../../app/app.php';
define('REST_CAP', Capabilities::PAYMENTS_PROCESS);
require_once __DIR__ . '/_common.php';

$self = public_url('super/restaurant/payments.php');
$orders = public_url('super/restaurant/orders.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    header('Content-Type: application/json');
    if (!csrf_verify($in['csrf'] ?? null)) {
        echo json_encode(['ok' => false, 'error' => 'Your session expired. Reload the page.']);
        exit;
    }
    $id = (int) ($in['order_id'] ?? 0);
    $res = rest_pay_order($pdo, $tid, $id, (array) ($in['payments'] ?? []));
    if ($res['ok']) {
        $res['receipt'] = $orders . '?receipt=' . $id . '&print=1';
    }
    echo json_encode($res);
    exit;
}

$q = trim($_GET['q'] ?? '');
$sql = "SELECT o.*, u.username,
               (SELECT COUNT(*) FROM rest_order_items i WHERE i.order_id = o.id) AS item_count
          FROM rest_orders o LEFT JOIN users u ON u.id = o.user_id
         WHERE o.tenant_id = ? AND o.status IN ('open','held')";
$args = [$tid];
if ($q !== '') {
    $sql .= ' AND (o.order_no LIKE ? OR o.table_label = ? OR o.customer_name LIKE ?)';
    array_push($args, '%' . $q, $q, '%' . $q . '%');
}
$stmt = $pdo->prepare($sql . " ORDER BY o.status = 'open' DESC, o.created_at");
$stmt->execute($args);
$unpaid = $stmt->fetchAll(PDO::FETCH_ASSOC);

$itemsBy = [];
if ($unpaid) {
    $ids = implode(',', array_map('intval', array_column($unpaid, 'id')));
    foreach ($pdo->query("SELECT order_id, name, quantity, line_total, note FROM rest_order_items WHERE order_id IN ($ids) ORDER BY id") as $i) {
        $itemsBy[(int) $i['order_id']][] = ['name' => $i['name'], 'qty' => rest_qty((float) $i['quantity']), 'total' => (float) $i['line_total'], 'note' => $i['note']];
    }
}
// A search that matches exactly one order opens it straight away.
$autoOpen = $q !== '' && count($unpaid) === 1 ? (int) $unpaid[0]['id'] : 0;

ob_start();
?>
<div class="card border-0 shadow-sm mb-3" style="border-radius:12px;">
  <div class="card-body p-3">
    <form class="d-flex gap-2" method="get">
      <div class="input-group">
        <span class="input-group-text"><i class="fas fa-receipt"></i></span>
        <input name="q" class="form-control form-control-lg" placeholder="Receipt no. (e.g. 042 or 261009-042), table or customer" value="<?php echo htmlspecialchars($q); ?>" autofocus>
      </div>
      <button class="btn btn-primary px-4">Find</button>
      <?php if ($q !== ''): ?><a class="btn btn-link" href="<?php echo $self; ?>">Clear</a><?php endif; ?>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm" style="border-radius:12px;">
  <div class="card-body p-4">
    <h2 class="h5 mb-3">Unpaid orders <span class="badge bg-light text-dark"><?php echo count($unpaid); ?></span></h2>
    <?php if (!$unpaid): ?>
      <div class="text-muted"><?php echo $q !== '' ? 'No unpaid order matches "' . htmlspecialchars($q) . '".' : 'All orders are paid. 🎉'; ?></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead><tr class="text-muted small text-uppercase"><th>Receipt no.</th><th>Table / customer</th><th>Waiter</th><th>Items</th><th>Total</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($unpaid as $o): ?>
          <tr>
            <td class="fw-semibold">#<?php echo htmlspecialchars($o['order_no']); ?><div class="small text-muted"><?php echo date('H:i', strtotime($o['created_at'])); ?> · <?php echo REST_ORDER_TYPES[$o['order_type']]; ?></div></td>
            <td><?php echo htmlspecialchars(trim(($o['table_label'] ? 'Table ' . $o['table_label'] : '') . ' ' . ($o['customer_name'] ?? '')) ?: '—'); ?><?php if (($o['source'] ?? '') === 'online'): ?><div><span class="badge bg-primary">Online</span> <a class="small" href="tel:<?php echo htmlspecialchars($o['customer_phone']); ?>"><?php echo htmlspecialchars($o['customer_phone']); ?></a></div><?php if ($o['delivery_address']): ?><div class="small text-muted"><i class="fas fa-location-dot me-1"></i><?php echo htmlspecialchars($o['delivery_address']); ?></div><?php endif; ?><?php endif; ?></td>
            <td class="small"><?php echo htmlspecialchars($o['username'] ?? (($o['source'] ?? '') === 'online' ? 'Customer (online)' : '—')); ?></td>
            <td><?php echo (int) $o['item_count']; ?></td>
            <td class="fw-bold"><?php echo number_format((float) $o['total'], 2); ?></td>
            <td><span class="badge <?php echo REST_STATUS[$o['status']][1]; ?>"><?php echo REST_STATUS[$o['status']][0]; ?></span></td>
            <td class="text-end"><button class="btn btn-success btn-sm pay-open" data-id="<?php echo (int) $o['id']; ?>"><i class="fas fa-money-bill-wave me-1"></i>Pay</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Payment -->
<div class="modal fade" id="payModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content" style="border-radius:16px;">
    <div class="modal-header border-0 pb-0"><h5 class="modal-title" id="pmTitle">Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div id="pmItems" class="small mb-3"></div>
      <div class="text-center mb-3"><div class="small text-muted">Amount due</div><div class="display-6 fw-bold" id="pmDue"></div></div>
      <div id="pmRows"></div>
      <button class="btn btn-sm btn-outline-secondary mb-3" id="pmSplit"><i class="fas fa-plus me-1"></i>Split payment</button>
      <div class="d-flex flex-wrap gap-1 mb-3" id="pmQuick"></div>
      <div class="d-flex justify-content-between"><span>Received</span><strong id="pmPaid"></strong></div>
      <div class="d-flex justify-content-between"><span id="pmBalLbl">Balance</span><strong id="pmBal"></strong></div>
      <div class="alert alert-danger small py-2 mt-2 mb-0" id="pmErr" style="display:none;"></div>
    </div>
    <div class="modal-footer border-0"><button class="btn btn-success w-100 btn-lg" id="pmConfirm"><i class="fas fa-check me-1"></i>Complete payment</button></div>
  </div></div>
</div>

<div class="modal fade" id="doneModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content text-center p-3" style="border-radius:16px;">
    <i class="fas fa-circle-check text-success" style="font-size:3rem;"></i>
    <h5 class="mt-2 mb-1" id="dnTitle"></h5>
    <div class="fs-4 fw-bold mb-3" id="dnChange"></div>
    <a class="btn btn-outline-primary mb-2" id="dnReceipt" data-receipt-popup><i class="fas fa-print me-1"></i>Print receipt</a>
    <a class="btn btn-primary" href="<?php echo $self; ?>">Done</a>
  </div></div>
</div>

<script>
(function () {
  var CUR = <?php echo json_encode($currency); ?>, CSRF = <?php echo json_encode(csrf_token()); ?>, API = <?php echo json_encode($self); ?>;
  var ORDERS = <?php echo json_encode((object) array_column(array_map(fn($o) => [
      'id' => (int) $o['id'], 'no' => $o['order_no'], 'total' => (float) $o['total'], 'discount' => (float) $o['discount'],
      'items' => $itemsBy[(int) $o['id']] ?? [],
  ], $unpaid), null, 'id')); ?>;
  var METHODS = <?php echo json_encode(REST_PAY_METHODS); ?>;
  var cur = null, pm;
  function $(id) { return document.getElementById(id); }
  function money(n) { return CUR + ' ' + (Math.round(n * 100) / 100).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

  function payRow(method, amount) {
    var el = document.createElement('div');
    el.className = 'd-flex gap-2 mb-2 pay-row';
    var opts = Object.keys(METHODS).map(function (k) { return '<option value="' + k + '"' + (k === method ? ' selected' : '') + '>' + METHODS[k] + '</option>'; }).join('');
    el.innerHTML = '<select class="form-select pm-m" style="max-width:120px;">' + opts + '</select>'
      + '<input type="number" step="0.01" min="0" class="form-control pm-a" placeholder="Amount" value="' + (amount || '') + '">'
      + '<input class="form-control pm-r" placeholder="Ref (M-Pesa code)" style="max-width:150px;">'
      + '<button class="btn btn-link text-danger p-0 pm-x"><i class="fas fa-times"></i></button>';
    el.querySelector('.pm-x').onclick = function () { if ($('pmRows').children.length > 1) { el.remove(); calc(); } };
    el.querySelector('.pm-a').oninput = el.querySelector('.pm-m').onchange = calc;
    $('pmRows').appendChild(el);
  }
  function payments() {
    return [].map.call($('pmRows').querySelectorAll('.pay-row'), function (r) {
      return {method: r.querySelector('.pm-m').value, amount: parseFloat(r.querySelector('.pm-a').value) || 0, ref: r.querySelector('.pm-r').value};
    });
  }
  function calc() {
    var paid = payments().reduce(function (s, p) { return s + p.amount; }, 0), bal = Math.round((paid - cur.total) * 100) / 100;
    $('pmPaid').textContent = money(paid);
    $('pmBalLbl').textContent = bal >= 0 ? 'Change' : 'Balance';
    $('pmBal').textContent = money(Math.abs(bal));
    $('pmBal').className = bal >= 0 ? 'text-success' : 'text-danger';
    $('pmConfirm').disabled = bal < 0;
  }
  function open(id) {
    cur = ORDERS[id]; if (!cur) return;
    $('pmTitle').textContent = 'Pay order #' + cur.no;
    $('pmItems').innerHTML = cur.items.map(function (i) {
      return '<div class="d-flex justify-content-between border-bottom py-1"><span>' + esc(i.qty) + ' × ' + esc(i.name) + (i.note ? ' <span class="text-muted">(' + esc(i.note) + ')</span>' : '') + '</span><span>' + money(i.total) + '</span></div>';
    }).join('') + (cur.discount > 0 ? '<div class="d-flex justify-content-between py-1 text-muted"><span>Discount</span><span>-' + money(cur.discount) + '</span></div>' : '');
    $('pmDue').textContent = money(cur.total);
    $('pmRows').innerHTML = ''; $('pmErr').style.display = 'none';
    payRow('cash', cur.total.toFixed(2));
    var quick = [cur.total, Math.ceil(cur.total / 100) * 100, Math.ceil(cur.total / 500) * 500, Math.ceil(cur.total / 1000) * 1000]
      .filter(function (v, i, a) { return v > 0 && a.indexOf(v) === i; });
    $('pmQuick').innerHTML = quick.map(function (v) { return '<button class="btn btn-sm btn-light border" data-v="' + v + '">' + money(v) + '</button>'; }).join('');
    $('pmQuick').querySelectorAll('button').forEach(function (b) {
      b.onclick = function () { $('pmRows').querySelector('.pm-a').value = b.dataset.v; $('pmRows').querySelector('.pm-m').value = 'cash'; calc(); };
    });
    calc();
    (pm = pm || new bootstrap.Modal($('payModal'))).show();
  }
  $('pmSplit').onclick = function () {
    var paid = payments().reduce(function (s, p) { return s + p.amount; }, 0);
    payRow('mpesa', Math.max(0, Math.round((cur.total - paid) * 100) / 100) || ''); calc();
  };
  $('pmConfirm').onclick = function () {
    var btn = this; btn.disabled = true;
    fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({csrf: CSRF, order_id: cur.id, payments: payments()})})
      .then(function (r) { return r.json(); }).catch(function () { return {ok: false, error: 'Network error — try again.'}; })
      .then(function (r) {
        btn.disabled = false;
        if (!r.ok) { $('pmErr').textContent = r.error; $('pmErr').style.display = ''; return; }
        pm.hide();
        // Preview the receipt right here (it prints itself); closing it refreshes the list for the next payment.
        if (window.TasoReceipt) { TasoReceipt.open(r.receipt, function () { location.href = API; }); return; }
        $('dnTitle').textContent = 'Order #' + cur.no + ' paid';
        $('dnChange').textContent = r.change > 0 ? 'Change: ' + money(r.change) : '';
        $('dnReceipt').href = r.receipt;
        // Print the receipt straight away: load it (it prints itself) in a hidden frame.
        var pf = document.getElementById('autoPrintFrame') || document.body.appendChild(Object.assign(document.createElement('iframe'),
          { id: 'autoPrintFrame', style: 'position:fixed;width:0;height:0;border:0;visibility:hidden' }));
        pf.src = r.receipt;
        new bootstrap.Modal($('doneModal'), {backdrop: 'static'}).show();
      });
  };
  document.querySelectorAll('.pay-open').forEach(function (b) { b.onclick = function () { open(b.dataset.id); }; });
  <?php if ($autoOpen): ?>open(<?php echo $autoOpen; ?>);<?php endif; ?>
})();
</script>
<?php
rest_render('Payments', ob_get_clean());
