<?php
// public/super/sales/index.php — enhanced owner view of all sales + profit
require_once __DIR__ . '/../../../app/app.php';
PageGuard::auth();

$pdo  = Database::pdo();
$SA   = new Models\SaleModel($pdo);
$OR   = new Models\OrderModel($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reverse_duplicate') {
    $source = $_POST['source'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $res = ['ok' => false, 'error' => 'Choose a sale to reverse.'];
    if ($id > 0 && $source === 'sale') {
        $res = $SA->reverseDuplicate($id, (int) TenantContext::userId());
    } elseif ($id > 0 && $source === 'order') {
        $res = $OR->reverseDuplicate($id, (int) TenantContext::userId());
    }
    $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok']
        ? 'Duplicate sale reversed: stock was returned and its money was removed from reports.'
        : ($res['error'] ?? 'Could not reverse this duplicate sale.');
    $qs = [];
    foreach (['period', 'date'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') { $qs[$k] = $_GET[$k]; }
    }
    header('Location: ' . public_url('super/sales/' . ($qs ? '?' . http_build_query($qs) : '')));
    exit;
}

// Period / exact-day filter
$allowed = ['today', 'week', 'month', 'all'];
$period  = in_array($_GET['period'] ?? '', $allowed, true) ? $_GET['period'] : 'today';
$date = preg_replace('/[^0-9-]/', '', (string) ($_GET['date'] ?? ''));
$selectedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
if ($selectedDate !== null) { $period = 'date'; }

/** Direct sales (legacy) + paid tabs (current), merged newest-first. Tabs are
 *  now the only way staff record a sale — direct sales stay for history. */
function sales_and_orders(Models\SaleModel $SA, Models\OrderModel $OR, string $period, ?string $date = null): array
{
    if ($period === 'date' && $date !== null) {
        $tid = (int) TenantContext::tenantId();
        $sales = $SA->forTenantId($tid, $date);
        $orders = $OR->forTenantOnDate($tid, $date);
    } else {
        $sales = $SA->forTenant(1000, $period);
        $orders = $OR->forTenant(1000, $period);
    }
    foreach ($sales as &$s) {
        $s['receipt_url'] = 'super/sales/receipt.php?id=' . (int) $s['id'];
        $s['edit_url']    = 'super/sales/edit.php?id=' . (int) $s['id'];
        $s['source']      = 'sale';
    }
    unset($s);
    foreach ($orders as &$o) {
        $o['receipt_url'] = 'super/orders/receipt.php?id=' . (int) $o['id'];
        $o['edit_url']    = 'super/orders/edit.php?id=' . (int) $o['id'];
    }
    unset($o);
    $merged = array_merge($sales, $orders);
    usort($merged, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
    return $merged;
}

function sales_by_day(array $sales): array
{
    $days = [];
    foreach ($sales as $sale) {
        $key = date('Y-m-d', strtotime($sale['created_at']));
        $days[$key][] = $sale;
    }
    krsort($days);
    return $days;
}

$sales      = sales_and_orders($SA, $OR, $period, $selectedDate);
$sum        = Models\SaleModel::summarize($sales);
$staffBd    = Models\SaleModel::staffBreakdown($sales);

// Batch-load line items for the products column — one query per source,
// not one per row.
$saleIds  = array_column(array_filter($sales, fn($s) => ($s['source'] ?? 'sale') === 'sale'), 'id');
$orderIds = array_column(array_filter($sales, fn($s) => ($s['source'] ?? 'sale') === 'order'), 'id');
$itemsBySale  = $SA->itemsForMany($saleIds);
$itemsByOrder = $OR->itemsForMany($orderIds);
// Electronics: the serial / IMEI of each phone sold, shown with the products.
$devicesByOrder = Models\ElectronicsModel::enabled() ? (new Models\ElectronicsModel($pdo))->serialsForOrders($orderIds) : [];
foreach ($sales as &$s) {
    $s['items'] = (($s['source'] ?? 'sale') === 'order' ? $itemsByOrder : $itemsBySale)[(int) $s['id']] ?? [];
    $s['devices'] = ($s['source'] ?? 'sale') === 'order' ? ($devicesByOrder[(int) $s['id']] ?? []) : [];
}
unset($s);
$salesByDay = sales_by_day($sales);

// Always compute today stats for the header card
$todaySales = ($period === 'today') ? $sales : sales_and_orders($SA, $OR, 'today');
$todaySum   = ($period === 'today') ? $sum    : Models\SaleModel::summarize($todaySales);

$periodLabel = $period === 'date' && $selectedDate !== null ? date('l, j/m/Y', strtotime($selectedDate)) : match ($period) {
    'today' => 'Today',
    'week'  => 'Last 7 days',
    'month' => 'Last 30 days',
    default => 'All time',
};

/* -----------------------------------------------------------------------
 * PROFIT (per product) — owner reporting.
 * Cost is read from the CURRENT products cost column. Set it here to match
 * your schema. Find it with:  SHOW COLUMNS FROM products LIKE '%price%';
 * The cost column is auto-detected from the products schema. Leave
 * $COST_COLUMN = null for that; only set a name if your column isn't in the
 * detector's list. If products has no cost column at all, the profit section
 * shows a clear notice (and lists your columns) instead of crashing.
 * -------------------------------------------------------------------- */
$COST_COLUMN     = null;    // null = auto-detect; or set e.g. 'buy_price'
$productProfit   = [];
$profitAvailable = true;
$profitReason    = '';      // 'no_column' | 'error'
try {
    $productProfit = $period === 'date' && $selectedDate !== null
        ? $SA->productProfitOnDate($selectedDate, $COST_COLUMN)
        : $SA->productProfit($period, $COST_COLUMN);

    // Merge in paid tabs' product profit (orders always have a buying_price
    // column, so this doesn't depend on the sales-side cost detection).
    $byProduct = [];
    foreach ($productProfit as $pp) { $byProduct[$pp['product_id']] = $pp; }
    $orderProfit = $period === 'date' && $selectedDate !== null
        ? $OR->productProfitOnDate($selectedDate)
        : $OR->productProfit($period);
    foreach ($orderProfit as $op) {
        $pid = $op['product_id'];
        if (isset($byProduct[$pid])) {
            $byProduct[$pid]['qty']     += $op['qty'];
            $byProduct[$pid]['revenue'] += $op['revenue'];
            $byProduct[$pid]['cost']    += $op['cost'];
        } else {
            $byProduct[$pid] = $op;
        }
    }
    foreach ($byProduct as &$bp) {
        $bp['profit'] = round($bp['revenue'] - $bp['cost'], 2);
        $bp['margin'] = $bp['revenue'] > 0 ? round($bp['profit'] / $bp['revenue'] * 100, 1) : 0.0;
    }
    unset($bp);
    usort($byProduct, fn($a, $b) => $b['profit'] <=> $a['profit']);
    $productProfit = array_values($byProduct);
} catch (\Throwable $e) {
    $profitAvailable = false;
    $profitReason    = ($e->getMessage() === 'NO_COST_COLUMN') ? 'no_column' : 'error';
}
$cogs = 0.0;
foreach ($productProfit as $pp) { $cogs += $pp['cost']; }
$cogs         = round($cogs, 2);
$grossRevenue = round(array_sum(array_column($productProfit, 'revenue')), 2);
$grossProfit  = round(array_sum(array_column($productProfit, 'profit')), 2);
// Net profit deducts order-level discounts (already reflected in $sum['revenue']).
$netProfit    = round(($sum['revenue'] ?? 0) - $cogs, 2);

$page_title = 'Sales';
ob_start();
include __DIR__ . '/../../components/xsheet.php';
?>
<!-- ===== Period tabs ===== -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h1 class="h5 mb-0 fw-bold">Sales Overview</h1>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <form method="get" class="d-flex align-items-center gap-2">
      <input type="date" name="date" value="<?php echo htmlspecialchars($selectedDate ?? date('Y-m-d')); ?>" max="<?php echo date('Y-m-d'); ?>" class="form-control form-control-sm" style="width:auto;">
      <button class="btn btn-sm btn-primary" type="submit">View day</button>
    </form>
    <div class="btn-group">
      <?php foreach (['today'=>'Today','week'=>'7 days','month'=>'30 days','all'=>'All time'] as $p=>$lbl): ?>
      <a href="?period=<?php echo $p; ?>"
         class="btn btn-sm <?php echo $period===$p ? 'btn-primary' : 'btn-outline-secondary'; ?>">
        <?php echo $lbl; ?>
      </a>
      <?php endforeach; ?>
    </div>
    <a class="btn btn-sm btn-outline-danger" href="<?php echo public_url('super/sales/duplicates.php'); ?>"><i class="fas fa-clone me-1"></i>Fix duplicate sales</a>
  </div>
</div>

<!-- ===== Stat cards ===== -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm" style="border-radius:14px;overflow:hidden;">
      <div style="height:4px;background:linear-gradient(90deg,#2563eb,#7c3aed);"></div>
      <div class="card-body p-3">
        <div class="text-muted small text-uppercase fw-semibold mb-1">Today's Revenue</div>
        <div class="h4 mb-0 fw-bold">KES <?php echo number_format($todaySum['revenue'],0); ?></div>
        <div class="text-muted small"><?php echo $todaySum['count']; ?> sale<?php echo $todaySum['count']!==1?'s':''; ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm" style="border-radius:14px;overflow:hidden;">
      <div style="height:4px;background:linear-gradient(90deg,#059669,#10b981);"></div>
      <div class="card-body p-3">
        <div class="text-muted small text-uppercase fw-semibold mb-1"><?php echo htmlspecialchars($periodLabel); ?></div>
        <div class="h4 mb-0 fw-bold">KES <?php echo number_format($sum['revenue'],0); ?></div>
        <div class="text-muted small"><?php echo $sum['count']; ?> sale<?php echo $sum['count']!==1?'s':''; ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm" style="border-radius:14px;overflow:hidden;">
      <div style="height:4px;background:#f59e0b;"></div>
      <div class="card-body p-3">
        <div class="text-muted small text-uppercase fw-semibold mb-1">Cash</div>
        <div class="h5 mb-0 fw-bold">KES <?php echo number_format($sum['cash'],0); ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm" style="border-radius:14px;overflow:hidden;">
      <div style="height:4px;background:#10b981;"></div>
      <div class="card-body p-3">
        <div class="text-muted small text-uppercase fw-semibold mb-1">M-Pesa</div>
        <div class="h5 mb-0 fw-bold">KES <?php echo number_format($sum['mpesa'],0); ?></div>
      </div>
    </div>
  </div>
</div>
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
      <div class="card-body p-3">
        <div class="text-muted small text-uppercase fw-semibold mb-1">Retail sales</div>
        <div class="h5 mb-0 fw-bold"><?php echo (int)($sum['retail'] ?? 0); ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
      <div class="card-body p-3">
        <div class="text-muted small text-uppercase fw-semibold mb-1">Wholesale sales</div>
        <div class="h5 mb-0 fw-bold"><?php echo (int)($sum['wholesale'] ?? 0); ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
      <div class="card-body p-3">
        <div class="text-muted small text-uppercase fw-semibold mb-1">Discounts given</div>
        <div class="h5 mb-0 fw-bold text-danger">KES <?php echo number_format($sum['discount'] ?? 0, 0); ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm" style="border-radius:14px;overflow:hidden;">
      <div style="height:4px;background:linear-gradient(90deg,#7c3aed,#2563eb);"></div>
      <div class="card-body p-3">
        <div class="text-muted small text-uppercase fw-semibold mb-1">Net profit · <?php echo htmlspecialchars($periodLabel); ?></div>
        <?php if ($profitAvailable): ?>
          <div class="h5 mb-0 fw-bold <?php echo $netProfit < 0 ? 'text-danger' : 'text-success'; ?>">KES <?php echo number_format($netProfit,0); ?></div>
          <div class="text-muted" style="font-size:.7rem;">Revenue − cost of goods, after discounts</div>
        <?php else: ?>
          <div class="h5 mb-0 text-muted">—</div>
          <div class="text-danger" style="font-size:.7rem;"><?php echo $profitReason === 'no_column' ? 'No product cost recorded' : 'Profit unavailable'; ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ===== Breakdown row ===== -->
<?php if ($staffBd): ?>
<div class="row g-3 mb-4">
  <div class="col-12 col-md-6">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3"><i class="fas fa-users me-2 text-primary"></i>By Staff Member</h2>
        <table class="table table-sm align-middle mb-0">
          <thead><tr class="text-muted small text-uppercase"><th>Staff</th><th class="text-center">Sales</th><th class="text-end">Revenue</th></tr></thead>
          <tbody>
            <?php foreach ($staffBd as $name => $d): ?>
            <tr>
              <td class="fw-semibold"><?php echo htmlspecialchars($name); ?></td>
              <td class="text-center"><span class="badge bg-light text-dark"><?php echo $d['count']; ?></span></td>
              <td class="text-end fw-semibold text-primary">KES <?php echo number_format($d['revenue'],0); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ===== Profit by product ===== -->
<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
  <div class="card-body p-4">
    <div class="d-flex align-items-center justify-content-between mb-1 flex-wrap gap-2">
      <h2 class="h6 fw-bold mb-0"><i class="fas fa-coins me-2 text-warning"></i>Profit by product — <?php echo htmlspecialchars($periodLabel); ?></h2>
    </div>
    <p class="text-muted small mb-3">
      Revenue and profit here are <strong>before</strong> order-level discounts
      (KES <?php echo number_format($sum['discount'] ?? 0,0); ?> given this period, already deducted from the Net profit card above).
      Cost uses the <em>current</em> product cost, not the cost at time of sale.
    </p>

    <?php if (!$profitAvailable): ?>
      <?php if ($profitReason === 'no_column'):
        $cols = [];
        try { $cols = $SA->productColumns(); } catch (\Throwable $e) { $cols = []; }
      ?>
      <div class="alert alert-warning mb-0">
        <div class="fw-semibold mb-1">No cost column found in your <code>products</code> table.</div>
        Profit needs a buying/cost price per product. Your schema doesn't have one yet —
        sales only record selling prices, so there's nothing to subtract.
        <?php if ($cols): ?>
          <div class="small text-muted mt-2">Columns in <code>products</code>: <?php echo htmlspecialchars(implode(', ', $cols)); ?></div>
          <div class="small mt-1">If one of those is your cost column, set <code>$COST_COLUMN</code> to it near the top of this file.</div>
        <?php endif; ?>
        <div class="small mt-2">Otherwise add one and start recording costs:
          <code>ALTER TABLE products ADD COLUMN buying_price DECIMAL(12,2) NOT NULL DEFAULT 0;</code></div>
      </div>
      <?php else: ?>
      <div class="alert alert-danger mb-0">
        Couldn't calculate profit. Check the database connection and that the
        <code>sale_items</code> and <code>products</code> tables are reachable.
      </div>
      <?php endif; ?>
    <?php elseif (!$productProfit): ?>
      <div class="text-muted py-4 text-center">
        <i class="fas fa-box-open fa-2x mb-2 d-block" style="opacity:.3;"></i>
        No products sold in this period yet.
      </div>
    <?php else: ?>
      <?php xsheet_search('#prodTable', 'Search products…  e.g. sugar  profit:<0  margin:<15', ['Loss-making' => 'profit:<0', 'Low margin' => 'margin:<15']); ?>
      <div class="table-responsive">
        <table class="xsheet" id="prodTable" data-page-size="20">
          <thead><tr>
            <th class="xsheet-n">#</th>
            <th>Product</th>
            <th class="text-end">Sold</th>
            <th class="text-end">Revenue</th>
            <th class="text-end">Cost</th>
            <th class="text-end">Profit</th>
            <th class="text-end">Margin</th>
          </tr></thead>
          <tbody>
            <?php foreach ($productProfit as $ppNo => $pp):
              $qtyLabel = rtrim(rtrim(number_format($pp['qty'],2),'0'),'.');
            ?>
            <tr>
              <td class="xsheet-n"><?php echo $ppNo + 1; ?></td>
              <td class="fw-semibold small"><?php echo htmlspecialchars($pp['product_name']); ?></td>
              <td class="text-end small text-nowrap"><?php echo $qtyLabel; ?><?php echo $pp['unit'] ? ' '.htmlspecialchars($pp['unit']) : ''; ?></td>
              <td class="text-end small">KES <?php echo number_format($pp['revenue'],0); ?></td>
              <td class="text-end small text-muted"><?php echo $pp['cost'] > 0 ? 'KES '.number_format($pp['cost'],0) : '—'; ?></td>
              <td class="text-end fw-semibold <?php echo $pp['profit'] < 0 ? 'text-danger' : 'text-success'; ?>">KES <?php echo number_format($pp['profit'],0); ?></td>
              <td class="text-end small">
                <?php if ($pp['cost'] <= 0): ?>
                  <span class="text-muted" title="No cost recorded for this product">n/a</span>
                <?php else: ?>
                  <span class="badge <?php echo $pp['margin'] < 0 ? 'bg-danger' : ($pp['margin'] < 15 ? 'bg-warning text-dark' : 'bg-success'); ?>"><?php echo number_format($pp['margin'],1); ?>%</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="fw-bold border-top">
              <td></td>
              <td>Total (gross)</td>
              <td></td>
              <td class="text-end">KES <?php echo number_format($grossRevenue,0); ?></td>
              <td class="text-end text-muted">KES <?php echo number_format($cogs,0); ?></td>
              <td class="text-end <?php echo $grossProfit < 0 ? 'text-danger':'text-success'; ?>">KES <?php echo number_format($grossProfit,0); ?></td>
              <td class="text-end small text-muted"><?php echo $grossRevenue > 0 ? number_format($grossProfit / $grossRevenue * 100, 1).'%' : '—'; ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ===== Full sales table ===== -->
<div class="card border-0 shadow-sm" style="border-radius:14px;">
  <div class="card-body p-4">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h2 class="h6 fw-bold mb-0">
        Sales — <?php echo htmlspecialchars($periodLabel); ?>
        <span class="badge bg-light text-dark ms-1"><?php echo count($sales); ?></span>
      </h2>
    </div>
    <?php if (!$sales): ?>
      <div class="text-muted py-4 text-center">
        <i class="fas fa-receipt fa-2x mb-2 d-block text-muted" style="opacity:.3;"></i>
        No sales recorded for this period yet.
      </div>
    <?php else: ?>
      <?php xsheet_search('#saleTable', 'Search sales: receipt, staff, customer, product, serial…  e.g. staff:jane  total:>1000', [
          'Cash' => 'pay:cash', 'M-Pesa' => 'pay:m-pesa', 'Split' => 'pay:split', 'Tabs' => 'type:tab',
      ]); ?>
      <div class="table-responsive">
        <table class="xsheet" id="saleTable" data-page-size="20">
          <thead><tr>
            <th class="xsheet-n">#</th>
            <th>Receipt</th><th>Date &amp; time</th><th>Type</th><th>Staff</th><th>Customer</th><th>Products</th><th>Pay</th><th class="text-end">Total</th><th></th>
          </tr></thead>
          <tbody>
            <?php $saleNo = 0;
            foreach ($salesByDay as $day => $daySales):
              $daySum = Models\SaleModel::summarize($daySales);
            ?>
            <tr class="xsheet-group">
              <td colspan="10">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <strong><?php echo date('l, j/m/Y', strtotime($day)); ?></strong>
                  <span><?php echo $daySum['count']; ?> sale<?php echo $daySum['count'] !== 1 ? 's' : ''; ?> · KES <?php echo number_format($daySum['revenue'], 0); ?></span>
                </div>
              </td>
            </tr>
            <?php foreach ($daySales as $s):
                $itemNames = implode(' ', array_column($s['items'], 'name')) . ' ' . Models\ElectronicsModel::serialsText($s['devices']);
            ?>
            <tr data-search="<?php echo strtolower(htmlspecialchars($s['receipt_number'].' '.$s['staff_name'].' '.($s['customer_name']??'').' '.$itemNames.' '.date('l j/m/Y D j M Y', strtotime($s['created_at'])).' '.Models\SaleModel::paymentLabel($s).' '.$s['payment_method'].' '.(($s['source'] ?? 'sale') === 'order' ? 'tab' : 'sale').' '.number_format((float)$s['total'], 0, '.', ''))); ?>">
              <td class="xsheet-n"><?php echo ++$saleNo; ?></td>
              <td class="fw-semibold small"><?php echo htmlspecialchars($s['receipt_number']); ?></td>
              <td class="small text-nowrap"><?php echo date('D j M Y', strtotime($s['created_at'])); ?><div class="fw-semibold"><?php echo date('g:i:s a', strtotime($s['created_at'])); ?></div><?php if (!empty($s['edited_at'])): ?><div class="text-warning" style="font-size:.7rem;">edited <?php echo date('j M, g:i a', strtotime($s['edited_at'])); ?></div><?php endif; ?></td>
              <td><?php echo ($s['source'] ?? 'sale') === 'order' ? '<span class="badge bg-warning text-dark">Tab</span>' : Models\SaleModel::saleTypeBadge($s); ?></td>
              <td class="small"><?php echo htmlspecialchars($s['staff_name'] ?: '—'); ?></td>
              <td class="small">
                <?php if ($s['customer_name'] && $s['customer_name'] !== 'Walk-in Customer'): ?>
                  <a href="<?php echo public_url('super/sales/customer.php?name=' . urlencode($s['customer_name'])); ?>"><?php echo htmlspecialchars($s['customer_name']); ?></a>
                <?php else: ?>
                  <?php echo htmlspecialchars($s['customer_name'] ?: '—'); ?>
                <?php endif; ?>
              </td>
              <td class="small"><?php echo Models\SaleModel::itemsSummaryHtml($s['items']) . Models\ElectronicsModel::serialsHtml($s['devices']); ?></td>
              <td class="small"><?php
                $pm = $s['payment_method'] ?? 'cash';
                if ($pm === 'split') {
                    echo '<span class="badge bg-secondary">Split</span><div class="text-muted" style="font-size:.7rem;">' . htmlspecialchars(Models\SaleModel::paymentLabel($s)) . '</div>';
                } elseif ($pm === 'cash') {
                    echo '<span class="badge bg-light text-dark">Cash</span>';
                } else {
                    echo '<span class="badge bg-success text-white">M-Pesa</span>';
                }
                if ((float)($s['discount_amount'] ?? 0) > 0) {
                    echo '<div class="text-danger" style="font-size:.7rem;">−KES ' . number_format((float)$s['discount_amount'], 0) . '</div>';
                }
              ?></td>
              <td class="text-end fw-semibold">KES <?php echo number_format((float)$s['total'],0); ?></td>
              <td class="text-end">
                <div class="btn-group btn-group-sm">
                  <a class="btn btn-outline-secondary" href="<?php echo public_url($s['receipt_url']); ?>">Receipt</a>
                  <?php if (!empty($s['edit_url'])): ?>
                    <a class="btn btn-outline-primary" href="<?php echo public_url($s['edit_url']); ?>">Edit</a>
                  <?php endif; ?>
                </div>
                <?php
                  $canReverseDuplicate = (($s['source'] ?? 'sale') === 'sale')
                      || (($s['source'] ?? '') === 'order' && ($s['channel'] ?? '') === 'walkin');
                ?>
                <?php if ($canReverseDuplicate): ?>
                  <form method="post" class="mt-1" onsubmit="return confirm('Reverse this duplicate sale? Stock will be returned and the money removed from sales/profit reports.');">
                    <input type="hidden" name="action" value="reverse_duplicate">
                    <input type="hidden" name="source" value="<?php echo htmlspecialchars($s['source'] ?? 'sale'); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                    <button class="btn btn-sm btn-outline-danger">Reverse duplicate</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
