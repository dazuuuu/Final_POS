<?php
// public/super/restaurant/reports.php — the restaurant's general REPORT for a
// date range: everything that happened, as one flow (newest first) plus a
// summary per activity — orders placed / paid / held / cancelled, payments,
// deliveries, store stock received, transfers to counter & kitchen, table
// bookings and their payments, guest stays and room payments, expenses and
// staff attendance. ?excel=1 / ?pdf=1 download it. Sales alone: sales.php.
require_once __DIR__ . '/../../../app/app.php';
define('REST_CAP', Capabilities::REPORTS_VIEW);
require_once __DIR__ . '/_common.php';
PageGuard::money(); // takings, costs and expenses: owners/admins only

$d = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : null;
$from = $d($_GET['from'] ?? '') ?? date('Y-m-d');
$to = $d($_GET['to'] ?? '') ?? $from;
if ($to < $from) [$from, $to] = [$to, $from];
$range = [$from . ' 00:00:00', $to . ' 23:59:59'];

/** Rows of $sql (params: tenant id, then the range), or [] when that package's tables aren't there. */
$rows = function (string $sql, array $extra = []) use ($pdo, $tid, $range): array {
    try {
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$tid], $range, $extra));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
};
$type = fn($t) => REST_ORDER_TYPES[$t] ?? $t;
$pay = fn($m) => REST_PAY_METHODS[$m] ?? ucfirst((string) $m);
$who = 'LEFT JOIN users u ON u.id = x.user_id';

// [time, activity, reference, details, by, amount (+ in / − out / null)]
$flow = [];
$add = function (string $at, string $act, string $ref, string $details, ?string $by, $amount = null) use (&$flow) {
    $flow[] = [$at, $act, $ref, $details, $by ?: '—', $amount === null ? null : (float) $amount];
};

foreach ($rows("SELECT x.*, u.username FROM rest_orders x $who WHERE x.tenant_id = ? AND x.created_at BETWEEN ? AND ?") as $o) {
    $where = trim(($o['table_label'] ? 'Table ' . $o['table_label'] . ' · ' : '') . ($o['customer_name'] ?: ''), ' ·');
    $add($o['created_at'], 'Order placed', '#' . $o['order_no'], $type($o['order_type']) . ($o['source'] === 'online' ? ' · online' : '') . ($where ? ' · ' . $where : '')
        . ' · ' . (REST_STATUS[$o['status']][0] ?? $o['status']), $o['username'] ?: ($o['source'] === 'online' ? 'Online' : null), $o['total']);
    if ($o['status'] === 'cancelled') {
        $add($o['updated_at'] ?: $o['created_at'], 'Order cancelled', '#' . $o['order_no'], $type($o['order_type']), $o['username'], null);
    }
}
foreach ($rows("SELECT x.*, o.order_no, o.order_type, u.username FROM rest_orders x JOIN rest_orders o ON o.id = x.id LEFT JOIN users u ON u.id = x.paid_by
                 WHERE x.tenant_id = ? AND x.status = 'paid' AND x.paid_at BETWEEN ? AND ?") as $o) {
    $add($o['paid_at'], 'Order paid', '#' . $o['order_no'], $type($o['order_type']) . ((float) $o['discount'] > 0 ? ' · discount ' . number_format((float) $o['discount'], 2) : '')
        . ((float) $o['change_due'] > 0 ? ' · change ' . number_format((float) $o['change_due'], 2) : ''), $o['username'], $o['total']);
}
foreach ($rows("SELECT x.*, o.order_no, u.username FROM rest_payments x JOIN rest_orders o ON o.id = x.order_id $who WHERE x.tenant_id = ? AND x.created_at BETWEEN ? AND ?") as $p) {
    $add($p['created_at'], 'Payment', '#' . $p['order_no'], $pay($p['method']) . ($p['reference'] ? ' · ' . $p['reference'] : ''), $p['username'], $p['amount']);
}
foreach ($rows("SELECT x.* FROM rest_orders x WHERE x.tenant_id = ? AND x.order_type = 'delivery' AND x.delivered_at BETWEEN ? AND ?") as $o) {
    $add($o['delivered_at'], 'Delivered', '#' . $o['order_no'], trim(($o['delivery_address'] ?: '') . ($o['rider'] ? ' · rider ' . $o['rider'] : ''), ' ·'), $o['rider'], null);
}
foreach ($rows("SELECT x.*, s.name, s.unit, sp.name AS supplier, u.username FROM rest_stock_receipts x JOIN rest_stock s ON s.id = x.stock_id
                 LEFT JOIN suppliers sp ON sp.id = x.supplier_id $who WHERE x.tenant_id = ? AND x.created_at BETWEEN ? AND ?") as $r) {
    $add($r['created_at'], 'Stock received', $r['name'], rest_qty((float) $r['quantity']) . ' ' . $r['unit'] . ($r['supplier'] ? ' · from ' . $r['supplier'] : ''), $r['username'],
        $r['cost_price'] !== null ? -(float) $r['cost_price'] : null);
}
foreach ($rows("SELECT x.*, s.name, s.unit, u.username FROM rest_transfers x JOIN rest_stock s ON s.id = x.stock_id $who WHERE x.tenant_id = ? AND x.created_at BETWEEN ? AND ?") as $t) {
    $add($t['created_at'], 'Stock transfer', $t['name'], rest_qty((float) $t['store_quantity']) . ' ' . $t['unit'] . ' → ' . (REST_OUTLETS[$t['outlet']] ?? $t['outlet'])
        . ($t['breakdown'] ? ' (' . $t['breakdown'] . ')' : ''), $t['username'], null);
}
foreach ($rows("SELECT x.*, u.username FROM rest_table_bookings x LEFT JOIN users u ON u.id = x.created_by WHERE x.tenant_id = ? AND x.created_at BETWEEN ? AND ?") as $b) {
    $add($b['created_at'], 'Table booking', '#' . $b['booking_no'], $b['customer_name'] . ' · ' . (int) $b['party_size'] . ' people · for ' . date('D j M, g:i a', strtotime($b['booking_at'])) . ' · ' . $b['status'], $b['username'], $b['total']);
}
foreach ($rows("SELECT x.*, b.booking_no, u.username FROM rest_table_booking_payments x JOIN rest_table_bookings b ON b.id = x.booking_id $who WHERE x.tenant_id = ? AND x.created_at BETWEEN ? AND ?") as $p) {
    $add($p['created_at'], 'Booking payment', '#' . $p['booking_no'], $pay($p['method']) . ($p['reference'] ? ' · ' . $p['reference'] : ''), $p['username'], $p['amount']);
}
foreach ($rows("SELECT x.*, u.username FROM room_stays x LEFT JOIN users u ON u.id = x.created_by WHERE x.tenant_id = ? AND x.created_at BETWEEN ? AND ?") as $s) {
    $add($s['created_at'], 'Guest stay', '#' . $s['stay_no'], $s['guest_name'] . ' · ' . $s['room_name'] . ' · ' . (int) $s['nights'] . ' night(s) · ' . $s['status'], $s['username'], $s['total']);
}
foreach ($rows("SELECT x.*, s.stay_no, u.username FROM room_stay_charges x JOIN room_stays s ON s.id = x.stay_id $who WHERE x.tenant_id = ? AND x.created_at BETWEEN ? AND ?") as $c) {
    $add($c['created_at'], 'Room extra', '#' . $c['stay_no'], $c['description'], $c['username'], $c['amount']);
}
foreach ($rows("SELECT x.*, s.stay_no, u.username FROM room_stay_payments x JOIN room_stays s ON s.id = x.stay_id $who WHERE x.tenant_id = ? AND x.created_at BETWEEN ? AND ?") as $p) {
    $add($p['created_at'], 'Room payment', '#' . $p['stay_no'], $pay($p['method']) . ($p['reference'] ? ' · ' . $p['reference'] : ''), $p['username'], $p['amount']);
}
foreach ($rows("SELECT x.*, u.username FROM finance_expenses x LEFT JOIN users u ON u.id = x.created_by WHERE x.tenant_id = ? AND x.created_at BETWEEN ? AND ?") as $e) {
    $add($e['created_at'], 'Expense', $e['title'], trim(($e['category'] ?: '') . ($e['payment_method'] ? ' · ' . $e['payment_method'] : ''), ' ·'), $e['username'], -(float) $e['amount']);
}
foreach ($rows("SELECT x.*, u.username FROM staff_time_logs x $who WHERE x.tenant_id = ? AND x.clock_in_at BETWEEN ? AND ?") as $t) {
    $add($t['clock_in_at'], 'Clock in', $t['username'] ?? '', !empty($t['late_clock_in']) ? 'Late' : 'On time', $t['username'], null);
    if ($t['clock_out_at']) {
        $add($t['clock_out_at'], 'Clock out', $t['username'] ?? '', !empty($t['auto_closed']) ? 'Closed automatically' : '', $t['username'], null);
    }
}
usort($flow, fn($a, $b) => strcmp($b[0], $a[0]));

// Summary per activity: count + money.
$summary = [];
foreach ($flow as [, $act, , , , $amt]) {
    $summary[$act] ??= ['n' => 0, 'v' => 0.0, 'money' => false];
    $summary[$act]['n']++;
    if ($amt !== null) { $summary[$act]['v'] += $amt; $summary[$act]['money'] = true; }
}
$moneyIn = array_sum(array_map(fn($k) => $summary[$k]['v'] ?? 0, ['Payment', 'Booking payment', 'Room payment']));
$moneyOut = -array_sum(array_map(fn($k) => $summary[$k]['v'] ?? 0, ['Stock received', 'Expense']));
$money = fn($v) => $currency . ' ' . number_format((float) $v, 2);
$label = $from === $to ? date('l, j M Y', strtotime($from)) : date('j M Y', strtotime($from)) . ' – ' . date('j M Y', strtotime($to));

if (!empty($_GET['excel']) || !empty($_GET['pdf'])) {
    $sheets = [
        'Summary' => [
            'headers' => ['Activity', 'Count', 'Amount'],
            'rows' => array_map(fn($k, $v) => [$k, $v['n'], $v['money'] ? round($v['v'], 2) : null], array_keys($summary), $summary),
            'money' => [2], 'totals' => [1],
        ],
        'Activity' => [
            'headers' => ['Date & time', 'Activity', 'Reference', 'Details', 'By', 'Amount'],
            'rows' => array_map(fn($r) => [date('D j M Y, H:i:s', strtotime($r[0])), $r[1], $r[2], $r[3], $r[4], $r[5] === null ? null : round($r[5], 2)], $flow),
            'money' => [5],
        ],
    ];
    $file = 'restaurant-report-' . ($from === $to ? $from : "$from-to-$to");
    if (!empty($_GET['pdf'])) {
        PdfExport::download($file . '.pdf', 'Restaurant report — all activity', $label, $sheets, [
            'Activities' => number_format(count($flow)), 'Money in' => $money($moneyIn), 'Money out' => $money($moneyOut), 'Net' => $money($moneyIn - $moneyOut),
        ]);
    }
    ExcelExport::download($file . '.xlsx', $sheets);
}

$qs = fn(array $x) => '?' . http_build_query($x + ['from' => $from, 'to' => $to]);
$quick = [
    'Today' => [date('Y-m-d'), date('Y-m-d')],
    'Yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    'This week' => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
    'This month' => [date('Y-m-01'), date('Y-m-d')],
    'Last 30 days' => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
];
$icons = ['Order placed' => 'fa-receipt', 'Order paid' => 'fa-circle-check', 'Order cancelled' => 'fa-ban', 'Payment' => 'fa-money-bill-wave',
          'Delivered' => 'fa-motorcycle', 'Stock received' => 'fa-truck-ramp-box', 'Stock transfer' => 'fa-right-left', 'Table booking' => 'fa-users-rectangle',
          'Booking payment' => 'fa-money-check', 'Guest stay' => 'fa-bed', 'Room extra' => 'fa-bell-concierge', 'Room payment' => 'fa-money-check-dollar',
          'Expense' => 'fa-file-invoice-dollar', 'Clock in' => 'fa-right-to-bracket', 'Clock out' => 'fa-right-from-bracket'];
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
ob_start();
include PUBLIC_ROOT . '/components/xsheet.php';
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
      <a class="btn btn-sm btn-danger" href="<?php echo $qs(['pdf' => 1]); ?>"><i class="fas fa-file-pdf me-1"></i>PDF</a>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
    </div>
  </form>
</div></div>

<h1 class="h5 fw-bold mb-1">Report · <?php echo $label; ?></h1>
<p class="small text-muted"><i class="far fa-clock me-1"></i>Generated <?php echo date('D j M Y, g:i:s a'); ?> by <?php echo $h($_SESSION['username'] ?? ''); ?> · everything that happened in the restaurant.
  For takings day by day see <a href="<?php echo public_url('super/restaurant/sales.php') . $qs([]); ?>">Sales</a>.</p>

<div class="row g-3 mb-3">
  <?php foreach ([
      ['Activities', number_format(count($flow)), 'fa-list-check', 'text-primary'],
      ['Money in', $money($moneyIn), 'fa-arrow-down', 'text-success'],
      ['Money out', $money($moneyOut), 'fa-arrow-up', 'text-danger'],
      ['Net', $money($moneyIn - $moneyOut), 'fa-scale-balanced', $moneyIn - $moneyOut < 0 ? 'text-danger' : 'text-success'],
  ] as [$l, $v, $i, $c]): ?>
  <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100" style="border-radius:12px;"><div class="card-body p-3">
    <div class="small text-muted text-uppercase fw-semibold"><i class="fas <?php echo $i; ?> me-1 <?php echo $c; ?>"></i><?php echo $l; ?></div>
    <div class="h5 fw-bold mb-0 mt-1"><?php echo $v; ?></div></div></div></div>
  <?php endforeach; ?>
</div>
<p class="small text-muted">Money in: order, booking and room payments. Money out: stock bought and expenses.</p>

<div class="card border-0 shadow-sm mb-3" style="border-radius:12px;"><div class="card-body p-3">
  <h2 class="h6 fw-bold">Summary</h2>
  <?php if (!$summary): ?><p class="small text-muted mb-0">Nothing happened in this period.</p><?php else: ?>
  <div class="row g-2">
    <?php foreach ($summary as $act => $v): ?>
    <div class="col-6 col-md-4 col-xl-3">
      <button type="button" class="w-100 text-start border rounded-3 p-2 bg-white d-flex align-items-center gap-2" data-flow-filter="<?php echo $h('activity:"' . strtolower($act) . '"'); ?>">
        <i class="fas <?php echo $icons[$act] ?? 'fa-circle'; ?> text-primary" style="width:20px;text-align:center"></i>
        <span class="flex-grow-1"><span class="d-block small fw-semibold"><?php echo $h($act); ?></span>
          <span class="small text-muted"><?php echo $v['n']; ?><?php echo $v['money'] ? ' · ' . $money($v['v']) : ''; ?></span></span>
      </button>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div></div>

<div class="card border-0 shadow-sm" style="border-radius:12px;"><div class="card-body p-3">
  <h2 class="h6 fw-bold">Activity <span class="text-muted">(<?php echo count($flow); ?>)</span></h2>
  <?php if (!$flow): ?><p class="small text-muted mb-0">Nothing happened in this period.</p><?php else: ?>
  <?php xsheet_search('#flowSheet', 'Search activity: order no, item, guest, staff…  e.g. by:jane  amount:>1000', ['Orders' => 'activity:order', 'Payments' => 'activity:payment', 'Stock' => 'activity:stock', 'Cancelled' => 'cancelled', 'Staff' => 'activity:clock']); ?>
  <div class="table-responsive"><table class="xsheet" id="flowSheet" data-page-size="20">
    <thead><tr><th class="xsheet-n">#</th><th>Date &amp; time</th><th>Activity</th><th>Reference</th><th>Details</th><th>By</th><th class="text-end">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($flow as $n => [$at, $act, $ref, $det, $by, $amt]): ?>
      <tr>
        <td class="xsheet-n"><?php echo $n + 1; ?></td>
        <td class="text-nowrap"><?php echo date('D j M Y', strtotime($at)); ?> <span class="fw-semibold"><?php echo date('g:i:s a', strtotime($at)); ?></span></td>
        <td class="text-nowrap"><i class="fas <?php echo $icons[$act] ?? 'fa-circle'; ?> me-1 text-muted"></i><?php echo $h($act); ?></td>
        <td class="fw-semibold"><?php echo $h($ref); ?></td>
        <td><?php echo $h($det); ?></td>
        <td><?php echo $h($by); ?></td>
        <td class="text-end <?php echo $amt !== null && $amt < 0 ? 'text-danger' : ''; ?>"><?php echo $amt === null ? '' : $money($amt); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div></div>
<script>
document.querySelectorAll('[data-flow-filter]').forEach(function (b) {
  b.addEventListener('click', function () {
    var inp = document.querySelector('[data-xsheet-search="#flowSheet"]'); if (!inp) return;
    inp.value = inp.value === b.dataset.flowFilter ? '' : b.dataset.flowFilter;
    inp.dispatchEvent(new Event('input'));
    inp.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });
});
</script>
<?php
rest_render('Reports', ob_get_clean());
