<?php
// public/super/restaurant/deliveries.php — process delivery orders (from the
// POS "Delivery" option or the online menu): move each one through
// New → Preparing → Packed → Out for delivery (rider) → Delivered, and take
// payment when the customer pays (on delivery or before). Payment goes through
// rest_pay_order(), so it is recorded as a sale exactly like a counter
// payment: stock and recipe ingredients are deducted and it shows in Orders,
// Payments and Reports.
require_once __DIR__ . '/../../../app/app.php';
define('REST_CAP', Capabilities::SALES_RECORD);
require_once __DIR__ . '/_common.php';

$self = public_url('super/restaurant/deliveries.php');
$ordersUrl = public_url('super/restaurant/orders.php');
$steps = array_keys(REST_DELIVERY_STEPS);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        rest_flash('Your session expired. Try again.', 'error');
        header('Location: ' . $self);
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $own = rest_sees_all() || TenantContext::can(Capabilities::PAYMENTS_PROCESS) ? '' : ' AND user_id = ' . (int) TenantContext::userId();
    $st = $pdo->prepare("SELECT * FROM rest_orders WHERE id = ? AND tenant_id = ? AND order_type = 'delivery' AND status <> 'cancelled'$own");
    $st->execute([$id, $tid]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    $action = $_POST['action'] ?? '';
    if (!$order) {
        rest_flash('Delivery order not found.', 'error');
    } elseif ($action === 'status' && in_array($_POST['to'] ?? '', $steps, true)) {
        $to = $_POST['to'];
        $rider = mb_substr(trim((string) ($_POST['rider'] ?? '')), 0, 80);
        if ($to === 'out' && $rider === '' && empty($order['rider'])) {
            rest_flash('Enter the rider\'s name before sending it out.', 'error');
        } else {
            $pdo->prepare('UPDATE rest_orders SET delivery_status = ?, rider = COALESCE(NULLIF(?, \'\'), rider),
                                  delivered_at = IF(? = \'delivered\', NOW(), delivered_at) WHERE id = ?')
                ->execute([$to, $rider, $to, $id]);
            rest_flash('Order ' . $order['order_no'] . ': ' . REST_DELIVERY_STEPS[$to][0] . '.');
        }
    } elseif ($action === 'pay') {
        // One or two payment lines (e.g. part cash, part M-Pesa).
        $payments = [];
        foreach ((array) ($_POST['method'] ?? []) as $i => $m) {
            $payments[] = ['method' => $m, 'amount' => $_POST['amount'][$i] ?? 0, 'ref' => $_POST['ref'][$i] ?? ''];
        }
        $res = rest_pay_order($pdo, $tid, $id, $payments);
        if ($res['ok']) {
            if (!empty($_POST['delivered'])) {
                $pdo->prepare("UPDATE rest_orders SET delivery_status = 'delivered', delivered_at = COALESCE(delivered_at, NOW()) WHERE id = ?")->execute([$id]);
            }
            rest_flash('Payment recorded for ' . $order['order_no'] . ($res['change'] > 0 ? ' — change ' . $currency . ' ' . number_format($res['change'], 2) : '') . '. It is now a sale.');
            $_SESSION['receipt_popup'] = $ordersUrl . '?receipt=' . $id . '&print=1'; // previewed on this page
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        rest_flash($res['error'], 'error');
    }
    header('Location: ' . $self . (isset($_GET['view']) ? '?view=' . urlencode($_GET['view']) : ''));
    exit;
}

// Views: active (not finished), delivered today, all recent.
$view = in_array($_GET['view'] ?? '', ['active', 'today', 'all'], true) ? $_GET['view'] : 'active';
$where = [
    'active' => "AND (COALESCE(o.delivery_status, 'new') <> 'delivered' OR o.status <> 'paid')",
    'today'  => "AND o.delivery_status = 'delivered' AND DATE(o.delivered_at) = CURDATE()",
    'all'    => 'AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)',
][$view];
$own = rest_sees_all() || TenantContext::can(Capabilities::PAYMENTS_PROCESS) ? '' : ' AND o.user_id = ' . (int) TenantContext::userId();
$st = $pdo->prepare("SELECT o.*, u.username FROM rest_orders o LEFT JOIN users u ON u.id = o.user_id
                      WHERE o.tenant_id = ? AND o.order_type = 'delivery' AND o.status NOT IN ('cancelled','held') $where $own
                      ORDER BY FIELD(COALESCE(o.delivery_status,'new'),'new','preparing','packed','out','delivered'), o.created_at");
$st->execute([$tid]);
$orders = $st->fetchAll(PDO::FETCH_ASSOC);
$items = [];
if ($orders) {
    $ids = implode(',', array_map('intval', array_column($orders, 'id')));
    foreach ($pdo->query("SELECT order_id, name, quantity FROM rest_order_items WHERE order_id IN ($ids) ORDER BY id") as $i) {
        $items[(int) $i['order_id']][] = rest_qty((float) $i['quantity']) . '× ' . $i['name'];
    }
}
$counts = $pdo->prepare("SELECT COALESCE(delivery_status,'new') s, COUNT(*) n FROM rest_orders
                          WHERE tenant_id = ? AND order_type = 'delivery' AND status NOT IN ('cancelled','held')
                            AND (COALESCE(delivery_status,'new') <> 'delivered' OR status <> 'paid') GROUP BY s");
$counts->execute([$tid]);
$counts = $counts->fetchAll(PDO::FETCH_KEY_PAIR);

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
ob_start();
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <div class="btn-group">
    <?php foreach (['active' => 'To process', 'today' => 'Delivered today', 'all' => 'Last 30 days'] as $k => $l): ?>
      <a class="btn btn-sm <?php echo $view === $k ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?view=<?php echo $k; ?>"><?php echo $l; ?></a>
    <?php endforeach; ?>
  </div>
  <div class="d-flex flex-wrap gap-1 ms-md-auto">
    <?php foreach (REST_DELIVERY_STEPS as $k => [$label, $cls, $icon]): if ($k === 'delivered') continue; ?>
      <span class="badge <?php echo $cls; ?>"><i class="fas <?php echo $icon; ?> me-1"></i><?php echo $label; ?>: <?php echo (int) ($counts[$k] ?? 0); ?></span>
    <?php endforeach; ?>
  </div>
  <a class="btn btn-sm btn-success" href="<?php echo public_url('super/restaurant/pos.php'); ?>"><i class="fas fa-plus me-1"></i>New delivery order</a>
</div>

<?php if (!$orders): ?>
  <div class="card border-0 shadow-sm" style="border-radius:12px;"><div class="card-body p-4 text-muted text-center">
    <i class="fas fa-motorcycle fa-2x mb-2 d-block"></i>No delivery orders <?php echo $view === 'active' ? 'to process right now' : 'here'; ?>.
    New ones come from the POS (choose <strong>Delivery</strong>) and the online menu.</div></div>
<?php endif; ?>

<div class="row g-3">
<?php foreach ($orders as $o):
    $ds = $o['delivery_status'] ?: 'new';
    $idx = array_search($ds, $steps, true);
    $next = $steps[$idx + 1] ?? null;
    $paid = $o['status'] === 'paid';
    [$dLabel, $dCls, $dIcon] = REST_DELIVERY_STEPS[$ds]; ?>
  <div class="col-12 col-lg-6 col-xxl-4">
    <div class="card border-0 shadow-sm h-100" style="border-radius:12px;">
      <div class="card-body p-3 d-flex flex-column">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <div>
            <div class="fw-bold">#<?php echo $h($o['order_no']); ?> <?php if (($o['source'] ?? '') === 'online'): ?><span class="badge bg-primary">Online</span><?php endif; ?></div>
            <div class="small text-muted"><?php echo date('D j M, g:i a', strtotime($o['created_at'])); ?><?php echo $o['username'] ? ' · ' . $h($o['username']) : ''; ?></div>
          </div>
          <div class="text-end">
            <span class="badge <?php echo $dCls; ?>"><i class="fas <?php echo $dIcon; ?> me-1"></i><?php echo $dLabel; ?></span>
            <div class="mt-1"><span class="badge <?php echo $paid ? 'bg-success' : 'bg-danger'; ?>"><?php echo $paid ? 'Paid' : 'Not paid'; ?></span></div>
          </div>
        </div>
        <!-- progress -->
        <div class="d-flex gap-1 mb-2">
          <?php foreach ($steps as $i => $k): ?><div class="flex-fill rounded" style="height:5px;background:<?php echo $i <= $idx ? '#16a34a' : '#e9ecef'; ?>"></div><?php endforeach; ?>
        </div>
        <div class="small mb-1"><i class="fas fa-user me-1 text-muted"></i><?php echo $h($o['customer_name'] ?: 'Customer'); ?>
          <?php if ($o['customer_phone']): ?> · <a href="tel:<?php echo $h($o['customer_phone']); ?>"><?php echo $h($o['customer_phone']); ?></a><?php endif; ?></div>
        <div class="small mb-1"><i class="fas fa-location-dot me-1 text-muted"></i><?php echo $h($o['delivery_address'] ?: '—'); ?></div>
        <?php if ($o['rider']): ?><div class="small mb-1"><i class="fas fa-motorcycle me-1 text-muted"></i>Rider: <?php echo $h($o['rider']); ?></div><?php endif; ?>
        <?php if ($o['delivered_at']): ?><div class="small mb-1 text-success"><i class="fas fa-circle-check me-1"></i>Delivered <?php echo date('D j M, g:i a', strtotime($o['delivered_at'])); ?></div><?php endif; ?>
        <div class="small text-muted mb-2"><?php echo $h(implode(', ', $items[(int) $o['id']] ?? [])); ?></div>
        <div class="d-flex justify-content-between align-items-center border-top pt-2 mt-auto">
          <strong><?php echo $currency . ' ' . number_format((float) $o['total'], 2); ?></strong>
          <a class="small" data-receipt-popup href="<?php echo $ordersUrl . '?receipt=' . (int) $o['id']; ?>"><?php echo $paid ? 'Receipt' : 'Order slip'; ?></a>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-2">
          <?php if ($next): ?>
            <form method="post" class="d-flex gap-1 flex-grow-1">
              <?php echo csrf_field(); ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?php echo (int) $o['id']; ?>">
              <input type="hidden" name="to" value="<?php echo $next; ?>">
              <?php if ($next === 'out'): ?><input name="rider" class="form-control form-control-sm" placeholder="Rider name" value="<?php echo $h($o['rider']); ?>" required><?php endif; ?>
              <button class="btn btn-sm btn-outline-primary text-nowrap flex-grow-1"><i class="fas <?php echo REST_DELIVERY_STEPS[$next][2]; ?> me-1"></i>Mark <?php echo strtolower(REST_DELIVERY_STEPS[$next][0]); ?></button>
            </form>
          <?php endif; ?>
          <?php if (!$paid): ?>
            <button type="button" class="btn btn-sm btn-success flex-grow-1" data-bs-toggle="modal" data-bs-target="#payModal"
              data-id="<?php echo (int) $o['id']; ?>" data-no="<?php echo $h($o['order_no']); ?>" data-total="<?php echo (float) $o['total']; ?>"
              data-delivered="<?php echo $ds === 'out' || $ds === 'delivered' ? '1' : '0'; ?>"><i class="fas fa-money-bill-wave me-1"></i>Take payment</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- Take payment: how they paid → recorded as a sale (stock deducted, receipt printed). -->
<div class="modal fade" id="payModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content" style="border-radius:16px;">
      <?php echo csrf_field(); ?><input type="hidden" name="action" value="pay"><input type="hidden" name="id" id="pmId">
      <div class="modal-header border-0 pb-0"><h5 class="modal-title">Payment for #<span id="pmNo"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="d-flex justify-content-between fs-5 fw-bold mb-3"><span>Amount due</span><span id="pmTotal"></span></div>
        <?php for ($i = 0; $i < 2; $i++): ?>
        <div class="row g-2 mb-2 <?php echo $i ? 'pm-split' : ''; ?>" <?php echo $i ? 'style="display:none"' : ''; ?>>
          <div class="col-4"><select name="method[]" class="form-select"><?php foreach (REST_PAY_METHODS as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $i && $k === 'mpesa' ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
          <div class="col-4"><input name="amount[]" type="number" step="0.01" min="0" class="form-control pm-amt" placeholder="Amount"></div>
          <div class="col-4"><input name="ref[]" class="form-control" placeholder="M-Pesa code"></div>
        </div>
        <?php endfor; ?>
        <button type="button" class="btn btn-sm btn-link px-0" id="pmSplit">+ Split payment (e.g. part cash, part M-Pesa)</button>
        <label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="delivered" value="1" id="pmDelivered">
          <span class="form-check-label small">Also mark as delivered</span></label>
      </div>
      <div class="modal-footer border-0"><button class="btn btn-success w-100"><i class="fas fa-check me-1"></i>Record payment &amp; print receipt</button></div>
    </form>
  </div>
</div>
<script>
document.getElementById('payModal').addEventListener('show.bs.modal', function (e) {
  var b = e.relatedTarget, cur = <?php echo json_encode($currency); ?>;
  document.getElementById('pmId').value = b.dataset.id;
  document.getElementById('pmNo').textContent = b.dataset.no;
  document.getElementById('pmTotal').textContent = cur + ' ' + parseFloat(b.dataset.total).toFixed(2);
  document.querySelector('.pm-amt').value = parseFloat(b.dataset.total).toFixed(2);
  document.querySelectorAll('.pm-split').forEach(function (r) { r.style.display = 'none'; r.querySelector('.pm-amt').value = ''; });
  document.getElementById('pmDelivered').checked = b.dataset.delivered === '1';
});
document.getElementById('pmSplit').onclick = function () { document.querySelectorAll('.pm-split').forEach(function (r) { r.style.display = ''; }); };
</script>
<?php
rest_render('Deliveries', ob_get_clean());
