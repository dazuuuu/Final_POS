<?php
// public/super/restaurant/orders.php — order history and the printable
// receipt (?receipt=id). An unpaid order prints as an order slip (amount due);
// a paid one as the final receipt (received, change). Waiters see only their
// own orders; the owner sees everyone's, with their own in "My orders" and a
// per-waiter sales breakdown.
require_once __DIR__ . '/../../../app/app.php';
define('REST_CAP', Capabilities::SALES_RECORD);
require_once __DIR__ . '/_common.php';

$self = public_url('super/restaurant/orders.php');
$posUrl = public_url('super/restaurant/pos.php');

// ---------------------------------------------------------------- Receipt
if ($rid = (int) ($_GET['receipt'] ?? 0)) {
    $stmt = $pdo->prepare('SELECT o.*, u.username FROM rest_orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ? AND o.tenant_id = ?');
    $stmt->execute([$rid, $tid]);
    $o = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$o || (!rest_sees_all() && (int) $o['user_id'] !== (int) TenantContext::userId() && !TenantContext::can(Capabilities::PAYMENTS_PROCESS))) {
        http_response_code(404); exit('Order not found.');
    }
    $paid = $o['status'] === 'paid';
    $items = $pdo->prepare('SELECT * FROM rest_order_items WHERE order_id = ? ORDER BY id');
    $items->execute([$rid]);
    $pays = $pdo->prepare('SELECT * FROM rest_payments WHERE order_id = ? ORDER BY id');
    $pays->execute([$rid]);
    $m = fn($v) => number_format((float) $v, 2);
    ?><!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php echo $paid ? 'Receipt' : 'Order slip'; ?> #<?php echo htmlspecialchars($o['order_no']); ?></title>
<style>
body{font-family:'Courier New',monospace;font-size:13px;color:#000;background:#f4f4f4;margin:0;}
.r{width:300px;margin:16px auto;background:#fff;padding:14px;}
.c{text-align:center;} .row{display:flex;justify-content:space-between;gap:8px;} .muted{color:#555;font-size:11px;}
hr{border:0;border-top:1px dashed #000;margin:8px 0;} h1{font-size:16px;margin:0 0 2px;}
.tot{font-weight:bold;font-size:15px;} .big{font-size:20px;font-weight:bold;letter-spacing:1px;}
@media print{body{background:#fff;} .r{margin:0;width:auto;} .np{display:none;}}
</style></head><body>
<div class="r">
  <div class="c">
    <h1><?php echo htmlspecialchars($__tenant['name'] ?? ''); ?></h1>
    <?php if (!empty($__tenant['phone'])): ?><div class="muted"><?php echo htmlspecialchars($__tenant['phone']); ?></div><?php endif; ?>
    <?php if (!empty($__tenant['address'])): ?><div class="muted"><?php echo htmlspecialchars($__tenant['address']); ?></div><?php endif; ?>
  </div>
  <hr>
  <div class="c"><?php echo $paid ? 'RECEIPT' : 'ORDER SLIP'; ?></div>
  <div class="c big">#<?php echo htmlspecialchars($o['order_no']); ?></div>
  <div class="row"><span><?php echo $o['status'] === 'cancelled' ? 'CANCELLED' : ''; ?></span><span><?php echo REST_ORDER_TYPES[$o['order_type']]; ?><?php echo $o['source'] === 'online' ? ' · Online' : ''; ?></span></div>
  <?php if ($o['table_label']): ?><div>Table: <?php echo htmlspecialchars($o['table_label']); ?></div><?php endif; ?>
  <?php if ($o['customer_name']): ?><div>Customer: <?php echo htmlspecialchars($o['customer_name']); ?></div><?php endif; ?>
  <?php if ($o['customer_phone']): ?><div>Phone: <?php echo htmlspecialchars($o['customer_phone']); ?></div><?php endif; ?>
  <?php if ($o['delivery_address']): ?><div>Deliver to: <?php echo htmlspecialchars($o['delivery_address']); ?></div><?php endif; ?>
  <div class="muted"><?php echo date('d M Y H:i', strtotime($o['paid_at'] ?? $o['created_at'])); ?> · <?php echo $o['source'] === 'online' ? 'Ordered online' : 'Served by ' . htmlspecialchars($o['username'] ?? '—'); ?></div>
  <hr>
  <?php foreach ($items as $i): ?>
    <div><?php echo htmlspecialchars($i['name']); ?></div>
    <?php if ($i['note']): ?><div class="muted">* <?php echo htmlspecialchars($i['note']); ?></div><?php endif; ?>
    <div class="row muted"><span><?php echo rest_qty((float) $i['quantity']); ?> × <?php echo $m($i['unit_price']); ?></span><span><?php echo $m($i['line_total']); ?></span></div>
  <?php endforeach; ?>
  <hr>
  <div class="row"><span>Subtotal</span><span><?php echo $m($o['subtotal']); ?></span></div>
  <?php if ((float) $o['discount'] > 0): ?><div class="row"><span>Discount</span><span>-<?php echo $m($o['discount']); ?></span></div><?php endif; ?>
  <div class="row tot"><span>TOTAL <?php echo htmlspecialchars($currency); ?></span><span><?php echo $m($o['total']); ?></span></div>
  <hr>
  <?php if ($paid): ?>
    <?php foreach ($pays as $p): ?>
      <div class="row"><span>Received · <?php echo htmlspecialchars(REST_PAY_METHODS[$p['method']] ?? $p['method']); ?><?php echo $p['reference'] ? ' (' . htmlspecialchars($p['reference']) . ')' : ''; ?></span><span><?php echo $m($p['amount']); ?></span></div>
    <?php endforeach; ?>
    <div class="row"><span>Total received</span><span><?php echo $m($o['paid_amount']); ?></span></div>
    <div class="row tot"><span>Change</span><span><?php echo $m($o['change_due']); ?></span></div>
    <div class="row"><span>Balance</span><span>0.00</span></div>
  <?php elseif ($o['status'] !== 'cancelled'): ?>
    <div class="row tot"><span>AMOUNT DUE</span><span><?php echo $m($o['total']); ?></span></div>
    <div class="c muted" style="margin-top:4px;">Pay at the counter with receipt no. #<?php echo htmlspecialchars($o['order_no']); ?></div>
  <?php endif; ?>
  <?php if (!empty($__tenant['receipt_footer'])): ?><hr><div class="c muted"><?php echo htmlspecialchars($__tenant['receipt_footer']); ?></div><?php endif; ?>
  <div class="c muted" style="margin-top:8px;">Thank you!</div>
  <div class="c np" style="margin-top:12px;"><button onclick="window.print()">Print</button></div>
</div>
<?php if (isset($_GET['print'])): ?><script>window.print();</script><?php endif; ?>
</body></html>
<?php
    exit;
}

// ---------------------------------------------------------------- List
$status = isset(REST_STATUS[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$period = in_array($_GET['period'] ?? '', ['today', 'week', 'month', 'all'], true) ? $_GET['period'] : 'today';
$where = ['o.tenant_id = ?'];
$args = [$tid];
// Waiters: only their own orders. Owner: everyone's, or just theirs (?mine=1), or one waiter's (?waiter=id).
$mine = !rest_sees_all() || !empty($_GET['mine']);
$waiter = rest_sees_all() ? (int) ($_GET['waiter'] ?? 0) : 0;
if ($mine) { $where[] = 'o.user_id = ?'; $args[] = TenantContext::userId(); }
elseif ($waiter) { $where[] = 'o.user_id = ?'; $args[] = $waiter; }
if ($status) { $where[] = 'o.status = ?'; $args[] = $status; }
$statusFree = $where; // per-waiter breakdown ignores the status filter
$statusArgs = $args;
if ($status) { array_pop($statusArgs); $statusFree = array_values(array_filter($where, fn($w) => $w !== 'o.status = ?')); }
$where[] = ['today' => 'DATE(o.created_at) = CURDATE()', 'week' => 'o.created_at >= CURDATE() - INTERVAL 6 DAY',
            'month' => 'o.created_at >= CURDATE() - INTERVAL 29 DAY', 'all' => '1=1'][$period];
$stmt = $pdo->prepare('SELECT o.*, u.username, (SELECT GROUP_CONCAT(DISTINCT p.method) FROM rest_payments p WHERE p.order_id = o.id) AS methods
                         FROM rest_orders o LEFT JOIN users u ON u.id = o.user_id WHERE ' . implode(' AND ', $where) . ' ORDER BY o.created_at DESC LIMIT 300');
$stmt->execute($args);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sum = ['paid' => 0, 'count' => 0, 'open' => 0];
foreach ($orders as $o) {
    if ($o['status'] === 'paid') { $sum['paid'] += (float) $o['total']; $sum['count']++; }
    if (in_array($o['status'], ['open', 'held'], true)) { $sum['open'] += (float) $o['total']; }
}

// Sales per waiter (owner, "everyone" view only).
$perWaiter = [];
if (rest_sees_all() && !$mine && !$waiter) {
    $w = $pdo->prepare("SELECT o.user_id, COALESCE(u.username, '—') AS username,
                               SUM(o.status = 'paid') AS paid_count, SUM(CASE WHEN o.status = 'paid' THEN o.total ELSE 0 END) AS paid_total,
                               SUM(o.status IN ('open','held')) AS unpaid_count, SUM(CASE WHEN o.status IN ('open','held') THEN o.total ELSE 0 END) AS unpaid_total
                          FROM rest_orders o LEFT JOIN users u ON u.id = o.user_id
                         WHERE " . implode(' AND ', $statusFree) . ' GROUP BY o.user_id, u.username ORDER BY paid_total DESC');
    $w->execute($statusArgs);
    $perWaiter = $w->fetchAll(PDO::FETCH_ASSOC);
}
$byMethod = $pdo->prepare('SELECT p.method, SUM(p.amount) - 0 AS amt FROM rest_payments p JOIN rest_orders o ON o.id = p.order_id WHERE ' . implode(' AND ', $where) . ' GROUP BY p.method');
$byMethod->execute($args);
$byMethod = $byMethod->fetchAll(PDO::FETCH_KEY_PAIR);
$cashChange = 0.0;
foreach ($orders as $o) { $cashChange += (float) $o['change_due']; }
if (isset($byMethod['cash'])) $byMethod['cash'] -= $cashChange; // cash kept = tendered − change

$q = fn(array $over) => $self . '?' . http_build_query(array_filter(array_merge(['status' => $status, 'period' => $period, 'mine' => rest_sees_all() && $mine ? 1 : '', 'waiter' => $waiter ?: ''], $over)));

ob_start();
?>
<?php if (rest_sees_all()): ?>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?php echo !$mine && !$waiter ? 'active' : ''; ?>" href="<?php echo $q(['mine' => '', 'waiter' => '']); ?>"><i class="fas fa-users me-1"></i>All orders</a></li>
  <li class="nav-item"><a class="nav-link <?php echo $mine ? 'active' : ''; ?>" href="<?php echo $q(['mine' => 1, 'waiter' => '']); ?>"><i class="fas fa-user me-1"></i>My orders</a></li>
  <?php if ($waiter): ?><li class="nav-item"><a class="nav-link active" href="#"><?php echo htmlspecialchars($orders[0]['username'] ?? 'Waiter'); ?></a></li><?php endif; ?>
</ul>
<?php endif; ?>
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm p-3" style="border-radius:12px;"><div class="small text-muted">Paid sales</div><div class="fs-5 fw-bold"><?php echo $currency . ' ' . number_format($sum['paid'], 2); ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm p-3" style="border-radius:12px;"><div class="small text-muted">Paid orders</div><div class="fs-5 fw-bold"><?php echo $sum['count']; ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm p-3" style="border-radius:12px;"><div class="small text-muted">Unpaid</div><div class="fs-5 fw-bold text-warning"><?php echo $currency . ' ' . number_format($sum['open'], 2); ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm p-3" style="border-radius:12px;"><div class="small text-muted">By method</div>
    <div class="small"><?php if (!$byMethod): ?>—<?php endif; foreach ($byMethod as $k => $v): ?><div class="d-flex justify-content-between"><span><?php echo REST_PAY_METHODS[$k] ?? $k; ?></span><strong><?php echo number_format((float) $v, 2); ?></strong></div><?php endforeach; ?></div></div></div>
</div>

<?php if ($perWaiter): ?>
<div class="card border-0 shadow-sm mb-3" style="border-radius:12px;">
  <div class="card-body p-4">
    <h2 class="h6 mb-3">Sales by waiter</h2>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr class="text-muted small text-uppercase"><th>Waiter</th><th>Paid orders</th><th>Paid sales</th><th>Unpaid</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($perWaiter as $w): ?>
        <tr>
          <td class="fw-semibold"><?php echo htmlspecialchars($w['username']); ?><?php if ((int) $w['user_id'] === (int) TenantContext::userId()): ?> <span class="badge bg-light text-dark">you</span><?php endif; ?></td>
          <td><?php echo (int) $w['paid_count']; ?></td>
          <td class="fw-semibold"><?php echo $currency . ' ' . number_format((float) $w['paid_total'], 2); ?></td>
          <td class="<?php echo (float) $w['unpaid_total'] > 0 ? 'text-warning fw-semibold' : 'text-muted'; ?>"><?php echo (int) $w['unpaid_count']; ?> · <?php echo number_format((float) $w['unpaid_total'], 2); ?></td>
          <td class="text-end"><?php if ($w['user_id']): ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo $q(['waiter' => (int) $w['user_id'], 'mine' => '']); ?>">View orders</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm" style="border-radius:12px;">
  <div class="card-body p-4">
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
      <div class="btn-group btn-group-sm">
        <?php foreach (['' => 'All'] + array_map(fn($x) => $x[0], REST_STATUS) as $k => $l): ?>
          <a class="btn <?php echo $status === $k ? 'btn-primary' : 'btn-outline-primary'; ?>" href="<?php echo $q(['status' => $k]); ?>"><?php echo $l; ?></a>
        <?php endforeach; ?>
      </div>
      <div class="btn-group btn-group-sm">
        <?php foreach (['today' => 'Today', 'week' => '7 days', 'month' => '30 days', 'all' => 'All time'] as $k => $l): ?>
          <a class="btn <?php echo $period === $k ? 'btn-secondary' : 'btn-outline-secondary'; ?>" href="<?php echo $q(['period' => $k]); ?>"><?php echo $l; ?></a>
        <?php endforeach; ?>
      </div>
      <a class="btn btn-sm btn-success" href="<?php echo $posUrl; ?>"><i class="fas fa-plus me-1"></i>New order</a>
    </div>
    <?php if (!$orders): ?><div class="text-muted">No orders for this filter.</div><?php else: ?>
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead><tr class="text-muted small text-uppercase"><th>Order</th><th>Table / customer</th><th>Total</th><th>Paid with</th><th>Status</th><th>Time</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td class="fw-semibold">#<?php echo htmlspecialchars($o['order_no']); ?><div class="small text-muted"><?php echo REST_ORDER_TYPES[$o['order_type']]; ?></div></td>
            <td><?php echo htmlspecialchars(trim(($o['table_label'] ? 'Table ' . $o['table_label'] : '') . ' ' . ($o['customer_name'] ?? '')) ?: '—'); ?><?php if (($o['source'] ?? '') === 'online'): ?><div><span class="badge bg-primary">Online</span> <a class="small" href="tel:<?php echo htmlspecialchars($o['customer_phone']); ?>"><?php echo htmlspecialchars($o['customer_phone']); ?></a></div><?php if ($o['delivery_address']): ?><div class="small text-muted"><i class="fas fa-location-dot me-1"></i><?php echo htmlspecialchars($o['delivery_address']); ?></div><?php endif; ?><?php endif; ?></td>
            <td class="fw-semibold"><?php echo number_format((float) $o['total'], 2); ?></td>
            <td class="small"><?php echo htmlspecialchars(implode(', ', array_map(fn($k) => REST_PAY_METHODS[$k] ?? $k, array_filter(explode(',', (string) $o['methods']))))) ?: '—'; ?></td>
            <td><span class="badge <?php echo REST_STATUS[$o['status']][1]; ?>"><?php echo REST_STATUS[$o['status']][0]; ?></span></td>
            <td class="small text-muted"><?php echo date($period === 'today' ? 'H:i' : 'd M H:i', strtotime($o['created_at'])); ?><div><?php echo htmlspecialchars($o['username'] ?? ''); ?></div></td>
            <td class="text-end text-nowrap">
              <?php if (in_array($o['status'], ['open', 'held'], true) && (rest_sees_all() || (int) $o['user_id'] === (int) TenantContext::userId())): ?>
                <a class="btn btn-sm btn-outline-warning" href="<?php echo $posUrl . '?order=' . (int) $o['id']; ?>" title="Add or remove items"><i class="fas fa-pen"></i></a>
                <?php if (TenantContext::can(Capabilities::PAYMENTS_PROCESS)): ?><a class="btn btn-sm btn-success" href="<?php echo public_url('super/restaurant/payments.php?q=' . urlencode($o['order_no'])); ?>">Pay</a><?php endif; ?>
              <?php endif; ?>
              <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?php echo $self . '?receipt=' . (int) $o['id']; ?>"><i class="fas fa-receipt"></i></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php
rest_render('Orders', ob_get_clean());
