<?php
// public/super/dashboard/index.php — owner analytics dashboard
require_once __DIR__ . '/../../../app/app.php';
PageGuard::auth();

// Restaurant-only accounts get the restaurant dashboard, not the shop one.
if (!Features::enabled(Features::SHOP_POS) && Features::enabled(Features::RESTAURANT_POS)) {
    header('Location: ' . public_url('super/restaurant/dashboard.php'));
    exit;
}

$pdo = Database::pdo();
$__tenant = (new Models\TenantModel($pdo))->find(TenantContext::tenantId());
$currency = $__tenant['currency'] ?? 'KES';

/** Paid orders + legacy direct sales, merged newest-first (mirrors super/sales/index.php). */
function dash_sales(Models\SaleModel $SA, Models\OrderModel $OR, string $period): array
{
    $sales = $SA->forTenant(1000, $period);
    foreach ($sales as &$s) { $s['receipt_url'] = 'super/sales/receipt.php?id=' . (int) $s['id']; $s['source'] = 'sale'; }
    unset($s);
    $orders = $OR->forTenant(1000, $period);
    foreach ($orders as &$o) { $o['receipt_url'] = 'super/orders/receipt.php?id=' . (int) $o['id']; }
    unset($o);
    $merged = array_merge($sales, $orders);
    usort($merged, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
    return $merged;
}

$SA = new Models\SaleModel($pdo);
$OR = new Models\OrderModel($pdo);
$P  = new Models\ProductModel($pdo);
$svc = new StaffService($pdo);
$TL = new Models\TimeLogModel($pdo);

$today = dash_sales($SA, $OR, 'today');
$week  = dash_sales($SA, $OR, 'week');
$todaySum = Models\SaleModel::summarize($today);
$weekSum  = Models\SaleModel::summarize($week);

$lowStock  = $P->lowStock();
$staffList = $svc->listForTenant((int) TenantContext::tenantId());
$openTabs  = $OR->openOrders();
$currentlyIn = $TL->currentlyIn((int) TenantContext::tenantId());
$todayAttendance = $TL->todaysEvents((int) TenantContext::tenantId());

$profitAvailable = true;
$todayCogs = 0.0;
try {
    $profitRows = $SA->productProfit('today');
    $byProduct = [];
    foreach ($profitRows as $pp) { $byProduct[$pp['product_id']] = $pp; }
    foreach ($OR->productProfit('today') as $op) {
        $pid = $op['product_id'];
        if (isset($byProduct[$pid])) {
            $byProduct[$pid]['cost'] += $op['cost'];
        } else {
            $byProduct[$pid] = $op;
        }
    }
    foreach ($byProduct as $pp) { $todayCogs += (float) $pp['cost']; }
} catch (Throwable $e) {
    $profitAvailable = false;
}
$todayProfit = $profitAvailable ? round($todaySum['revenue'] - $todayCogs, 2) : null;
$todayLoss = $todayProfit !== null && $todayProfit < 0 ? abs($todayProfit) : 0.0;

// Last 7 days revenue for the chart (oldest first).
$chartLabels = [];
$chartValues = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $chartLabels[] = date('D', strtotime($date));
    $dayTotal = 0.0;
    foreach ($week as $r) {
        if (date('Y-m-d', strtotime($r['created_at'])) === $date) { $dayTotal += (float) $r['total']; }
    }
    $chartValues[] = round($dayTotal, 2);
}

$recent = array_slice($today ?: $week, 0, 8);
$recentSaleIds  = array_column(array_filter($recent, fn($s) => ($s['source'] ?? 'sale') === 'sale'), 'id');
$recentOrderIds = array_column(array_filter($recent, fn($s) => ($s['source'] ?? 'sale') === 'order'), 'id');
$recentItemsBySale  = $SA->itemsForMany($recentSaleIds);
$recentItemsByOrder = $OR->itemsForMany($recentOrderIds);
foreach ($recent as &$r) {
    $r['items'] = (($r['source'] ?? 'sale') === 'order' ? $recentItemsByOrder : $recentItemsBySale)[(int) $r['id']] ?? [];
}
unset($r);

$page_title = 'Dashboard';
$shop = $__tenant['name'] ?? 'your shop';
ob_start();
?>
<?php
$owed = 0.0; $tabTotal = 0.0; $tabPaid = 0.0;
foreach ($openTabs as $t) { $owed += Models\OrderModel::balanceDue($t); $tabTotal += (float) $t['total']; $tabPaid += (float) $t['amount_paid']; }
$sellableCount = count($P->sellable());
$pct = fn($n) => max(0, min(100, (int) round($n)));
$marginPct   = $profitAvailable && $todaySum['revenue'] > 0 ? $pct($todayProfit / $todaySum['revenue'] * 100) : 0;
$collectPct  = $tabTotal > 0 ? $pct($tabPaid / $tabTotal * 100) : 100;
$stockPct    = $sellableCount > 0 ? $pct(($sellableCount - count($lowStock)) / $sellableCount * 100) : 0;
$avgDay      = $weekSum['revenue'] / 7;
$vsAvgPct    = $avgDay > 0 ? $pct($todaySum['revenue'] / $avgDay * 100) : ($todaySum['revenue'] > 0 ? 100 : 0);
$money = fn($n) => htmlspecialchars($currency) . ' ' . number_format((float) $n, 0);

// Today's money per shop: sales − cost of goods = gross profit, − expenses = net.
$__report = new ShopReport($pdo);
$__isOwner = TenantContext::role() === 'tenant_owner';
$__shops = $__isOwner ? ShopContext::shops(true) : [];
if (!$__shops) { $__shops = [$__tenant]; }
$__day = $__report->compare($__shops, date('Y-m-d'), date('Y-m-d'));
$__here = null;
foreach ($__day['rows'] as $__r) { if ((int) $__r['shop']['id'] === (int) TenantContext::tenantId()) { $__here = $__r; } }
$__here = $__here ?? $__report->forShop((int) TenantContext::tenantId(), date('Y-m-d'), date('Y-m-d'));
$owed += Models\ManualCreditModel::outstandingFor($pdo, (int) TenantContext::tenantId());
?>
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <a class="tk-stat" href="<?php echo public_url('super/sales/'); ?>">
      <div class="tk-stat-icon tk-red"><i class="fas fa-sack-dollar"></i></div>
      <div class="tk-stat-body"><div class="tk-stat-num"><?php echo $money($todaySum['revenue']); ?></div><div class="tk-stat-label">Revenue today</div></div>
    </a>
  </div>
  <div class="col-6 col-lg-3">
    <a class="tk-stat" href="<?php echo public_url('super/pos/'); ?>">
      <div class="tk-stat-icon tk-orange"><i class="fas fa-cart-shopping"></i></div>
      <div class="tk-stat-body"><div class="tk-stat-num"><?php echo number_format($todaySum['count']); ?></div><div class="tk-stat-label">Sales today</div></div>
    </a>
  </div>
  <div class="col-6 col-lg-3">
    <a class="tk-stat" href="<?php echo public_url('super/payments/'); ?>">
      <div class="tk-stat-icon tk-blue"><i class="fas fa-hand-holding-dollar"></i></div>
      <div class="tk-stat-body"><div class="tk-stat-num"><?php echo $money($owed); ?></div><div class="tk-stat-label">Owed on credit (<?php echo count($openTabs); ?>)</div></div>
    </a>
  </div>
  <div class="col-6 col-lg-3">
    <a class="tk-stat" href="<?php echo public_url('super/staff/attendance.php'); ?>">
      <div class="tk-stat-icon tk-greenbg"><i class="fas fa-users"></i></div>
      <div class="tk-stat-body"><div class="tk-stat-num"><?php echo count($currentlyIn); ?></div><div class="tk-stat-label">Staff clocked in</div></div>
    </a>
  </div>
</div>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <h2 class="h6 fw-bold mb-0"><i class="fas fa-scale-balanced me-2 text-primary"></i>Today's profit &amp; expenses <span class="text-muted fw-normal small">· <?php echo date('l j M'); ?></span></h2>
      <a class="btn btn-sm btn-outline-secondary" href="<?php echo public_url('super/expenses/'); ?>"><i class="fas fa-plus me-1"></i>Add expense</a>
    </div>
    <div class="dp-tiles mb-3">
      <div><span>Sales</span><strong><?php echo $money($__here['revenue']); ?></strong></div>
      <div><span>Gross profit</span><strong><?php echo $money($__here['gross']); ?></strong></div>
      <div><span>Expenses</span><strong class="text-warning"><?php echo $money($__here['expenses']); ?></strong></div>
      <div><span>Net profit</span><strong class="<?php echo $__here['net'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $money($__here['net']); ?></strong></div>
    </div>
    <?php if (count($__day['rows']) > 1): ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0 dp-table">
        <thead><tr class="text-uppercase small"><th>Shop</th><th class="text-end">Sales</th><th class="text-end">Gross profit</th><th class="text-end">Expenses</th><th class="text-end">Net profit</th></tr></thead>
        <tbody>
        <?php foreach ($__day['rows'] as $__r): ?>
          <tr>
            <td class="small fw-semibold"><?php echo htmlspecialchars($__r['shop']['name']); ?></td>
            <td class="small text-end"><?php echo $money($__r['revenue']); ?></td>
            <td class="small text-end"><?php echo $money($__r['gross']); ?></td>
            <td class="small text-end text-warning"><?php echo $money($__r['expenses']); ?></td>
            <td class="small text-end fw-semibold <?php echo $__r['net'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $money($__r['net']); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="fw-bold">
            <td>All shops</td>
            <td class="text-end"><?php echo $money($__day['total']['revenue']); ?></td>
            <td class="text-end"><?php echo $money($__day['total']['gross']); ?></td>
            <td class="text-end text-warning"><?php echo $money($__day['total']['expenses']); ?></td>
            <td class="text-end <?php echo $__day['total']['net'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $money($__day['total']['net']); ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <div class="small text-muted mt-2">Net profit = sales − cost of goods sold − expenses. <a href="<?php echo public_url('super/shops/'); ?>">Compare shops →</a></div>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="tk-ring-card"><div class="tk-ring-title">Profit margin</div>
      <div class="tk-ring" style="--p:<?php echo $marginPct; ?>;--c:#2196f3;"><span><?php echo $profitAvailable ? $marginPct . '%' : 'N/A'; ?></span></div>
      <div class="tk-ring-sub"><?php echo $profitAvailable ? ($todayLoss > 0 ? 'Loss ' . $money($todayLoss) : 'Profit ' . $money($todayProfit)) : 'cost prices missing'; ?></div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="tk-ring-card"><div class="tk-ring-title">Today vs average</div>
      <div class="tk-ring" style="--p:<?php echo $vsAvgPct; ?>;--c:#e0383e;"><span><?php echo $vsAvgPct; ?>%</span></div>
      <div class="tk-ring-sub">7-day avg <?php echo $money($avgDay); ?>/day</div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="tk-ring-card"><div class="tk-ring-title">Credit collected</div>
      <div class="tk-ring" style="--p:<?php echo $collectPct; ?>;--c:#1abc9c;"><span><?php echo $collectPct; ?>%</span></div>
      <div class="tk-ring-sub"><?php echo $money($tabPaid); ?> of <?php echo $money($tabTotal); ?></div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="tk-ring-card"><div class="tk-ring-title">Stock health</div>
      <div class="tk-ring" style="--p:<?php echo $stockPct; ?>;--c:#f1b44c;"><span><?php echo $stockPct; ?>%</span></div>
      <div class="tk-ring-sub"><?php echo count($lowStock); ?> product<?php echo count($lowStock) === 1 ? '' : 's'; ?> running low</div></div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-12 col-lg-8">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3">Sales — last 7 days <span class="text-muted fw-normal small">· 7-day revenue <?php echo $money($weekSum['revenue']); ?></span></h2>
        <canvas id="salesChart" height="90"></canvas>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3"><i class="fas fa-user-clock me-2 text-primary"></i>Attendance now</h2>
        <?php if (!$currentlyIn): ?>
          <div class="text-muted small mb-3">No staff clocked in right now.</div>
        <?php else: ?>
          <?php foreach (array_slice($currentlyIn, 0, 4) as $log): ?>
            <div class="d-flex justify-content-between border-bottom py-2">
              <span class="small fw-semibold"><?php echo htmlspecialchars($log['username']); ?></span>
              <span class="small text-muted"><?php echo date('g:i a', strtotime($log['clock_in_at'])); ?></span>
            </div>
          <?php endforeach; ?>
          <a class="small d-block mt-2 mb-3" href="<?php echo public_url('super/staff/attendance.php'); ?>">View attendance</a>
        <?php endif; ?>
        <h2 class="h6 fw-bold mb-3"><i class="fas fa-triangle-exclamation me-2 text-warning"></i>Low stock</h2>
        <?php if (!$lowStock): ?>
          <div class="text-muted small">Nothing running low. Nice.</div>
        <?php else: ?>
          <?php foreach (array_slice($lowStock, 0, 6) as $p): ?>
            <div class="d-flex justify-content-between border-bottom py-2">
              <span class="small fw-semibold"><?php echo htmlspecialchars($p['name']); ?></span>
              <span class="small text-danger fw-semibold"><?php echo rtrim(rtrim(number_format((float) $p['quantity'], 2), '0'), '.'); ?> left</span>
            </div>
          <?php endforeach; ?>
          <a class="small d-block mt-2" href="<?php echo public_url('super/inventory/'); ?>">View inventory →</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <h2 class="h6 fw-bold mb-0">Recent sales</h2>
      <a class="btn btn-sm btn-outline-secondary" href="<?php echo public_url('super/sales/'); ?>">View all</a>
    </div>
    <?php if (!$recent): ?>
      <div class="text-muted small text-center py-4">No sales recorded yet.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr class="text-muted small text-uppercase"><th>Customer</th><th>Products</th><th>When</th><th>Staff</th><th>Pay</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
            <?php foreach ($recent as $r): ?>
            <tr>
              <td class="small fw-semibold">
                <?php if ($r['customer_name'] && $r['customer_name'] !== 'Walk-in Customer'): ?>
                  <a href="<?php echo public_url('super/sales/customer.php?name=' . urlencode($r['customer_name'])); ?>"><?php echo htmlspecialchars($r['customer_name']); ?></a>
                <?php else: ?>
                  <?php echo htmlspecialchars($r['customer_name'] ?: '—'); ?>
                <?php endif; ?>
              </td>
              <td class="small"><?php echo Models\SaleModel::itemsSummaryHtml($r['items']); ?></td>
              <td class="small text-muted"><?php echo date('j M, g:i a', strtotime($r['created_at'])); ?></td>
              <td class="small"><?php echo htmlspecialchars($r['staff_name'] ?: '—'); ?></td>
              <td class="small"><span class="badge bg-light text-dark"><?php echo htmlspecialchars(ucfirst($r['payment_method'] ?? 'cash')); ?></span></td>
              <td class="text-end small fw-semibold"><?php echo htmlspecialchars($currency); ?> <?php echo number_format((float) $r['total'], 0); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<style>
.dp-tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;}
.dp-tiles>div{background:#f7f9f8;border-radius:10px;padding:12px;}
.dp-tiles span{display:block;font-size:.7rem;color:#8a8f96;text-transform:uppercase;letter-spacing:.04em;}
.dp-tiles strong{font-size:1.1rem;overflow-wrap:anywhere;}
@media (max-width:576px){ .dp-tiles{grid-template-columns:1fr 1fr;} .dp-table{min-width:480px!important;} }
.dash-ic{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex:0 0 auto;}
@media (max-width:576px){
  #salesChart{min-height:220px;}
  .dash-ic{width:32px;height:32px;}
  .card .text-uppercase{font-size:.68rem;line-height:1.25;}
  .card .h4{overflow-wrap:anywhere;}
}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('salesChart'), {
  type: 'bar',
  data: {
    labels: <?php echo json_encode($chartLabels); ?>,
    datasets: [{ data: <?php echo json_encode($chartValues); ?>, backgroundColor: '#8ee2b5', hoverBackgroundColor: '#3cb878', borderRadius: 0, maxBarThickness: 40 }]
  },
  options: {
    plugins: { legend: { display: false } },
    scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return (v / 1000) + 'k'; } } } }
  }
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
