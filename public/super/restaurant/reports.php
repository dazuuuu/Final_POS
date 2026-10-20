<?php
// public/super/restaurant/reports.php — restaurant sales report for a date
// range (paid orders, by the time they were paid): revenue, payment methods,
// order types, waiters, best sellers with estimated cost & profit (recipe +
// store costs), sales by hour, and every order with its date and time.
// ?excel=1 downloads the same report as an .xlsx workbook.
require_once __DIR__ . '/../../../app/app.php';
define('REST_CAP', Capabilities::REPORTS_VIEW);
require_once __DIR__ . '/_common.php';
PageGuard::money(); // restaurant takings and profit: owners/admins only

$self = public_url('super/restaurant/reports.php');
$d = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : null;
$from = $d($_GET['from'] ?? '') ?? date('Y-m-d');
$to = $d($_GET['to'] ?? '') ?? $from;
if ($to < $from) [$from, $to] = [$to, $from];
$range = [$from . ' 00:00:00', $to . ' 23:59:59'];

// ---- paid orders in range ----
$st = $pdo->prepare("SELECT o.*, u.username AS waiter, pb.username AS cashier
                       FROM rest_orders o LEFT JOIN users u ON u.id = o.user_id LEFT JOIN users pb ON pb.id = o.paid_by
                      WHERE o.tenant_id = ? AND o.status = 'paid' AND o.paid_at BETWEEN ? AND ?
                   ORDER BY o.paid_at");
$st->execute([$tid, ...$range]);
$orders = $st->fetchAll(PDO::FETCH_ASSOC);
$ids = array_map('intval', array_column($orders, 'id'));
$in = $ids ? implode(',', $ids) : '0';

$items = $pdo->query("SELECT * FROM rest_order_items WHERE order_id IN ($in) AND quantity > 0")->fetchAll(PDO::FETCH_ASSOC);
$pays = $pdo->query("SELECT * FROM rest_payments WHERE order_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);

// ---- costs: dishes from their recipe, counter items from the store's cost per unit ----
$dishCost = rest_dish_costs($pdo, $tid);
$counterCost = [];
foreach ($pdo->query("SELECT o.id, o.stock_id, s.pieces_per_unit FROM rest_outlet_items o JOIN rest_stock s ON s.id = o.stock_id WHERE o.tenant_id = " . (int) $tid) as $c) {
    $uc = rest_stock_unit_cost($pdo, $tid, (int) $c['stock_id']);
    $counterCost[(int) $c['id']] = $uc === null ? null : ((float) $c['pieces_per_unit'] > 0 ? $uc / (float) $c['pieces_per_unit'] : $uc);
}

$sum = ['orders' => count($orders), 'revenue' => 0.0, 'discount' => 0.0, 'cost' => 0.0, 'uncosted' => 0.0];
$byType = $byWaiter = $byHour = $byItem = $byMethod = [];
foreach ($orders as $o) {
    $sum['revenue'] += (float) $o['total'];
    $sum['discount'] += (float) $o['discount'];
    $t = REST_ORDER_TYPES[$o['order_type']] ?? $o['order_type'];
    $byType[$t] = ['n' => ($byType[$t]['n'] ?? 0) + 1, 'rev' => ($byType[$t]['rev'] ?? 0) + (float) $o['total']];
    $w = $o['waiter'] ?: '—';
    $byWaiter[$w] = ['n' => ($byWaiter[$w]['n'] ?? 0) + 1, 'rev' => ($byWaiter[$w]['rev'] ?? 0) + (float) $o['total']];
    $hr = (int) date('G', strtotime($o['paid_at']));
    $byHour[$hr] = ($byHour[$hr] ?? 0) + (float) $o['total'];
}
foreach ($items as $i) {
    $key = $i['item_type'] . ':' . $i['ref_id'];
    $unit = $i['item_type'] === 'dish' ? (($dishCost[(int) $i['ref_id']]['cost'] ?? null) ?: null) : ($counterCost[(int) $i['ref_id']] ?? null);
    $cost = $unit === null ? null : $unit * (float) $i['quantity'];
    $byItem[$key] ??= ['name' => $i['name'], 'kind' => $i['item_type'] === 'dish' ? 'Dish' : 'Counter', 'qty' => 0.0, 'rev' => 0.0, 'cost' => 0.0, 'costed' => true];
    $byItem[$key]['qty'] += (float) $i['quantity'];
    $byItem[$key]['rev'] += (float) $i['line_total'];
    if ($cost === null) {
        $byItem[$key]['costed'] = false;
        $sum['uncosted'] += (float) $i['line_total'];
    } else {
        $byItem[$key]['cost'] += $cost;
        $sum['cost'] += $cost;
    }
}
foreach ($pays as $p) {
    $m = REST_PAY_METHODS[$p['method']] ?? $p['method'];
    $byMethod[$m] = ($byMethod[$m] ?? 0) + (float) $p['amount'];
}
// Change given back comes out of cash.
$change = array_sum(array_map(fn($o) => (float) $o['change_due'], $orders));
if ($change > 0 && isset($byMethod['Cash'])) $byMethod['Cash'] -= $change;
uasort($byItem, fn($a, $b) => $b['rev'] <=> $a['rev']);
arsort($byWaiter);
ksort($byHour);

$itemsByOrder = [];
foreach ($items as $i) {
    $itemsByOrder[(int) $i['order_id']][] = rest_qty((float) $i['quantity']) . '× ' . $i['name'];
}
$methodsByOrder = [];
foreach ($pays as $p) {
    $methodsByOrder[(int) $p['order_id']][] = (REST_PAY_METHODS[$p['method']] ?? $p['method']) . ($p['reference'] ? ' (' . $p['reference'] . ')' : '');
}

// ---- Excel ----
if (!empty($_GET['excel'])) {
    $label = $from === $to ? $from : "$from to $to";
    ExcelExport::download('restaurant-report-' . str_replace(' ', '_', $label) . '.xlsx', [
        'Orders' => [
            'headers' => ['Order no', 'Paid date & time', 'Ordered at', 'Type', 'Table / customer', 'Phone', 'Waiter', 'Cashier', 'Items', 'Payment', 'Subtotal', 'Discount', 'Total'],
            'rows' => array_map(fn($o) => [
                $o['order_no'], $o['paid_at'], $o['created_at'], REST_ORDER_TYPES[$o['order_type']] ?? $o['order_type'],
                trim(($o['table_label'] ? 'Table ' . $o['table_label'] . ' ' : '') . ($o['customer_name'] ?? '')),
                $o['customer_phone'], $o['waiter'], $o['cashier'], implode(', ', $itemsByOrder[(int) $o['id']] ?? []),
                implode(' + ', $methodsByOrder[(int) $o['id']] ?? []), (float) $o['subtotal'], (float) $o['discount'], (float) $o['total'],
            ], $orders),
            'money' => [10, 11, 12], 'totals' => [10, 11, 12],
        ],
        'Items sold' => [
            'headers' => ['Item', 'Kind', 'Quantity', 'Revenue', 'Est. cost', 'Est. profit'],
            'rows' => array_map(fn($r) => [$r['name'], $r['kind'], $r['qty'], round($r['rev'], 2),
                $r['costed'] ? round($r['cost'], 2) : null, $r['costed'] ? round($r['rev'] - $r['cost'], 2) : null], array_values($byItem)),
            'money' => [3, 4, 5], 'totals' => [2, 3, 4, 5],
        ],
        'Payments' => [
            'headers' => ['Order no', 'Date & time', 'Method', 'Amount', 'Reference'],
            'rows' => array_map(function ($p) use ($orders) {
                $o = array_values(array_filter($orders, fn($x) => (int) $x['id'] === (int) $p['order_id']))[0] ?? [];
                return [$o['order_no'] ?? '', $p['created_at'], REST_PAY_METHODS[$p['method']] ?? $p['method'], (float) $p['amount'], $p['reference']];
            }, $pays),
            'money' => [3], 'totals' => [3],
        ],
        'By waiter' => [
            'headers' => ['Waiter', 'Orders', 'Revenue'],
            'rows' => array_map(fn($k, $v) => [$k, $v['n'], round($v['rev'], 2)], array_keys($byWaiter), $byWaiter),
            'money' => [2], 'totals' => [1, 2],
        ],
    ]);
}

$money = fn($v) => $currency . ' ' . number_format((float) $v, 2);
$qs = fn(array $x) => '?' . http_build_query($x + ['from' => $from, 'to' => $to]);
$quick = [
    'Today' => [date('Y-m-d'), date('Y-m-d')],
    'Yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    'This week' => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
    'This month' => [date('Y-m-01'), date('Y-m-d')],
    'Last 30 days' => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
];
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
ob_start();
?>
<style>@media print{.no-print,.sidebar,nav,header{display:none!important}}</style>
<div class="card border-0 shadow-sm mb-3 no-print" style="border-radius:12px;"><div class="card-body p-3">
  <form class="d-flex flex-wrap gap-2 align-items-end" method="get">
    <div><label class="form-label small mb-1">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?php echo $from; ?>" max="<?php echo date('Y-m-d'); ?>"></div>
    <div><label class="form-label small mb-1">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?php echo $to; ?>" max="<?php echo date('Y-m-d'); ?>"></div>
    <button class="btn btn-sm btn-primary">View</button>
    <div class="btn-group flex-wrap">
      <?php foreach ($quick as $l => [$a, $b]): ?>
        <a class="btn btn-sm <?php echo $from === $a && $to === $b ? 'btn-secondary' : 'btn-outline-secondary'; ?>" href="?from=<?php echo $a; ?>&to=<?php echo $b; ?>"><?php echo $l; ?></a>
      <?php endforeach; ?>
    </div>
    <div class="ms-md-auto d-flex gap-2">
      <a class="btn btn-sm btn-success" href="<?php echo $qs(['excel' => 1]); ?>"><i class="fas fa-file-excel me-1"></i>Excel</a>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
    </div>
  </form>
</div></div>

<h1 class="h5 fw-bold mb-1">Restaurant report · <?php echo $from === $to ? date('l, j M Y', strtotime($from)) : date('j M Y', strtotime($from)) . ' – ' . date('j M Y', strtotime($to)); ?></h1>
<p class="small text-muted"><i class="far fa-clock me-1"></i>Generated <?php echo date('D j M Y, g:i:s a'); ?> by <?php echo $h($_SESSION['username'] ?? ''); ?> · paid orders, by time of payment</p>

<div class="row g-3 mb-3">
  <?php foreach ([
      ['Revenue', $money($sum['revenue']), 'fa-sack-dollar', 'text-success'],
      ['Orders', number_format($sum['orders']), 'fa-receipt', 'text-primary'],
      ['Average order', $money($sum['orders'] ? $sum['revenue'] / $sum['orders'] : 0), 'fa-scale-balanced', 'text-info'],
      // Profit only over items whose cost is known (item revenue − cost); see the note below.
      ['Est. gross profit', $money(array_sum(array_map(fn($r) => $r['costed'] ? $r['rev'] - $r['cost'] : 0, $byItem))) . ($sum['uncosted'] > 0 ? '*' : ''), 'fa-chart-line', 'text-success'],
  ] as [$l, $v, $i, $c]): ?>
  <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100" style="border-radius:12px;"><div class="card-body p-3">
    <div class="small text-muted text-uppercase fw-semibold"><i class="fas <?php echo $i; ?> me-1 <?php echo $c; ?>"></i><?php echo $l; ?></div>
    <div class="h5 fw-bold mb-0 mt-1"><?php echo $v; ?></div></div></div></div>
  <?php endforeach; ?>
</div>
<?php if ($sum['uncosted'] > 0): ?>
  <p class="small text-muted">* Est. cost <?php echo $money($sum['cost']); ?> covers items with a known cost; <?php echo $money($sum['uncosted']); ?> of sales are from items with no cost yet
    (move their stock to the kitchen, or set prices on the store stock). Discounts given: <?php echo $money($sum['discount']); ?>.</p>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card border-0 shadow-sm h-100" style="border-radius:12px;"><div class="card-body p-3">
    <h2 class="h6 fw-bold">Payments</h2>
    <?php if (!$byMethod): ?><p class="small text-muted mb-0">None.</p><?php endif; ?>
    <?php foreach ($byMethod as $m => $v): ?><div class="d-flex justify-content-between small py-1 border-bottom"><span><?php echo $h($m); ?></span><strong><?php echo $money($v); ?></strong></div><?php endforeach; ?>
  </div></div></div>
  <div class="col-md-4"><div class="card border-0 shadow-sm h-100" style="border-radius:12px;"><div class="card-body p-3">
    <h2 class="h6 fw-bold">Order types</h2>
    <?php if (!$byType): ?><p class="small text-muted mb-0">None.</p><?php endif; ?>
    <?php foreach ($byType as $t => $v): ?><div class="d-flex justify-content-between small py-1 border-bottom"><span><?php echo $h($t); ?> (<?php echo $v['n']; ?>)</span><strong><?php echo $money($v['rev']); ?></strong></div><?php endforeach; ?>
  </div></div></div>
  <div class="col-md-4"><div class="card border-0 shadow-sm h-100" style="border-radius:12px;"><div class="card-body p-3">
    <h2 class="h6 fw-bold">By waiter</h2>
    <?php if (!$byWaiter): ?><p class="small text-muted mb-0">None.</p><?php endif; ?>
    <?php foreach ($byWaiter as $w => $v): ?><div class="d-flex justify-content-between small py-1 border-bottom"><span><?php echo $h($w); ?> (<?php echo $v['n']; ?>)</span><strong><?php echo $money($v['rev']); ?></strong></div><?php endforeach; ?>
  </div></div></div>
</div>

<?php if ($byHour): $max = max($byHour); ?>
<div class="card border-0 shadow-sm mb-3" style="border-radius:12px;"><div class="card-body p-3">
  <h2 class="h6 fw-bold">Sales by hour</h2>
  <?php foreach ($byHour as $hr => $v): ?>
    <div class="d-flex align-items-center gap-2 small mb-1">
      <span style="width:80px" class="text-muted"><?php echo date('g a', mktime($hr, 0)); ?>–<?php echo date('g a', mktime($hr + 1, 0)); ?></span>
      <div class="flex-grow-1 bg-light rounded" style="height:14px"><div class="rounded" style="height:14px;width:<?php echo max(2, round($v / $max * 100)); ?>%;background:#16a34a"></div></div>
      <span style="width:120px" class="text-end fw-semibold"><?php echo $money($v); ?></span>
    </div>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3" style="border-radius:12px;"><div class="card-body p-3">
  <h2 class="h6 fw-bold">Items sold</h2>
  <?php if (!$byItem): ?><p class="small text-muted mb-0">Nothing sold in this period.</p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr class="small text-muted text-uppercase"><th>Item</th><th class="text-end">Qty</th><th class="text-end">Revenue</th><th class="text-end">Est. cost</th><th class="text-end">Est. profit</th></tr></thead>
    <tbody>
    <?php foreach ($byItem as $r): ?>
      <tr class="small"><td><?php echo $h($r['name']); ?> <span class="badge bg-light text-muted"><?php echo $r['kind']; ?></span></td>
        <td class="text-end"><?php echo rest_qty($r['qty']); ?></td><td class="text-end fw-semibold"><?php echo $money($r['rev']); ?></td>
        <td class="text-end"><?php echo $r['costed'] ? $money($r['cost']) : '<span class="text-muted">—</span>'; ?></td>
        <td class="text-end <?php echo $r['costed'] && $r['rev'] - $r['cost'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $r['costed'] ? $money($r['rev'] - $r['cost']) : '<span class="text-muted">—</span>'; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div></div>

<div class="card border-0 shadow-sm" style="border-radius:12px;"><div class="card-body p-3">
  <h2 class="h6 fw-bold">Orders <span class="text-muted">(<?php echo count($orders); ?>)</span></h2>
  <?php if (!$orders): ?><p class="small text-muted mb-0">No paid orders in this period.</p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr class="small text-muted text-uppercase"><th>#</th><th>Paid</th><th>Type</th><th>Table / customer</th><th>Waiter</th><th>Items</th><th>Payment</th><th class="text-end">Total</th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
      <tr class="small">
        <td><a href="<?php echo public_url('super/restaurant/orders.php?receipt=' . (int) $o['id']); ?>"><?php echo $h($o['order_no']); ?></a></td>
        <td class="text-nowrap"><?php echo date('D j M Y', strtotime($o['paid_at'])); ?><div class="fw-semibold"><?php echo date('g:i:s a', strtotime($o['paid_at'])); ?></div></td>
        <td><?php echo $h(REST_ORDER_TYPES[$o['order_type']] ?? $o['order_type']); ?></td>
        <td><?php echo $h(trim(($o['table_label'] ? 'Table ' . $o['table_label'] . ' ' : '') . ($o['customer_name'] ?? '')) ?: '—'); ?></td>
        <td><?php echo $h($o['waiter'] ?: '—'); ?></td>
        <td><?php echo $h(implode(', ', $itemsByOrder[(int) $o['id']] ?? [])); ?></td>
        <td><?php echo $h(implode(' + ', $methodsByOrder[(int) $o['id']] ?? [])); ?></td>
        <td class="text-end fw-semibold"><?php echo $money($o['total']); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div></div>
<?php
rest_render('Restaurant reports', ob_get_clean());
