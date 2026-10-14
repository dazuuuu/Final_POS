<?php
// public/staff/sales/index.php — this staff member's own sales.
// Defaults to TODAY only (resets every day); pass ?period=all for history.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::SALES_VIEW);

$pdo = Database::pdo();
$SA  = new Models\SaleModel($pdo);
$OR  = new Models\OrderModel($pdo);
$myId = TenantContext::userId();

/** This staff member's legacy sales + paid orders, merged newest-first. */
function my_sales(Models\SaleModel $SA, Models\OrderModel $OR, int $staffId, string $period, ?string $date = null): array
{
    $salesDate = $period === 'date' && $date !== null ? $date : ($period === 'today' ? date('Y-m-d') : null);
    $sales = $SA->forStaff($staffId, 500, $salesDate);
    if ($period !== 'today' && $period !== 'all') {
        // forStaff() only supports "today" or "all" — filter week/month here.
        if ($period !== 'date') {
            $cut = $period === 'week' ? strtotime('-7 days') : strtotime('-30 days');
            $sales = array_values(array_filter($sales, fn($s) => strtotime($s['created_at']) >= $cut));
        }
    }
    foreach ($sales as &$s) {
        $s['receipt_url'] = 'staff/sales/receipt.php?id=' . (int) $s['id'];
        $s['edit_url'] = 'staff/sales/edit.php?id=' . (int) $s['id'];
        $s['source'] = 'sale';
    }
    unset($s);
    $orders = ($period === 'date' && $date !== null)
        ? $OR->forTenantOnDate((int) TenantContext::tenantId(), $date, $staffId)
        : $OR->forTenant(500, $period, $staffId);
    foreach ($orders as &$o) {
        $o['edit_url'] = 'staff/orders/edit.php?id=' . (int) $o['id'];
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

$allowed = ['today', 'week', 'month', 'all'];
$period  = in_array($_GET['period'] ?? '', $allowed, true) ? $_GET['period'] : 'today';
$date = preg_replace('/[^0-9-]/', '', (string) ($_GET['date'] ?? ''));
$selectedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
if ($selectedDate !== null) { $period = 'date'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reverse_duplicate') {
    $source = $_POST['source'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $res = ['ok' => false, 'error' => 'Choose a sale to reverse.'];
    if (!TenantContext::can(Capabilities::SALES_RECORD)) {
        $res = ['ok' => false, 'error' => 'You are not allowed to reverse sales.'];
    } elseif ($id > 0 && $source === 'sale') {
        $res = $SA->reverseDuplicate($id, (int) $myId, (int) $myId);
    } elseif ($id > 0 && $source === 'order') {
        $res = $OR->reverseDuplicate($id, (int) $myId, (int) $myId);
    }
    $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok']
        ? 'Duplicate sale reversed: stock was returned and the money was removed from reports.'
        : ($res['error'] ?? 'Could not reverse this duplicate sale.');
    $qs = [];
    foreach (['period', 'date'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') { $qs[$k] = $_GET[$k]; }
    }
    header('Location: ' . public_url('staff/sales/' . ($qs ? '?' . http_build_query($qs) : '')));
    exit;
}

$todaySales = my_sales($SA, $OR, $myId, 'today');
$todaySum   = Models\SaleModel::summarize($todaySales);

$sales = $period === 'today' ? $todaySales : my_sales($SA, $OR, $myId, $period, $selectedDate);
$sum   = $period === 'today' ? $todaySum : Models\SaleModel::summarize($sales);

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

$__tenant     = (new Models\TenantModel($pdo))->find(TenantContext::tenantId());
$tenantSlug   = $__tenant['slug'] ?? '';
$shopName     = $__tenant['name'] ?? 'Our Shop';
$catalogueUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
              . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
              . public_url('catalogue.php?shop=' . urlencode($tenantSlug));

$periodLabel = $period === 'date' && $selectedDate !== null
    ? date('l, j/m/Y', strtotime($selectedDate))
    : ['today' => "Today — " . date('l, j/m/Y'), 'week' => 'Last 7 days', 'month' => 'Last 30 days', 'all' => 'All time'][$period];

$page_title = 'My sales';
ob_start();
?>
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
      <div class="card-body p-3">
        <div class="text-muted small text-uppercase fw-semibold">Today</div>
        <div class="h5 mb-0 mt-1 fw-bold">KES <?php echo number_format($todaySum['revenue'], 0); ?></div>
        <div class="text-muted small"><?php echo $todaySum['count']; ?> sale<?php echo $todaySum['count'] !== 1 ? 's' : ''; ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
      <div class="card-body p-3">
        <div class="text-muted small text-uppercase fw-semibold"><?php echo $period === 'today' ? 'Shown' : htmlspecialchars($periodLabel); ?></div>
        <div class="h5 mb-0 mt-1 fw-bold">KES <?php echo number_format($sum['revenue'], 0); ?></div>
        <div class="text-muted small"><?php echo $sum['count']; ?> sale<?php echo $sum['count'] !== 1 ? 's' : ''; ?></div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-6 d-flex align-items-center gap-2 flex-wrap">
    <a href="<?php echo public_url('staff/dashboard/'); ?>" class="btn btn-primary"><i class="fas fa-cash-register me-1"></i>Home</a>
    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#shareCatalogueModal">
      <i class="fas fa-share-nodes me-1"></i>Share Catalogue
    </button>
  </div>
</div>

<div class="card border-0 shadow-sm" style="border-radius:14px;">
  <div class="card-body p-4">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h2 class="h6 fw-bold mb-0">
        <?php echo htmlspecialchars($periodLabel); ?>
        <span class="badge bg-light text-dark ms-1"><?php echo count($sales); ?></span>
      </h2>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <form method="get" class="d-flex align-items-center gap-2">
          <input type="date" name="date" value="<?php echo htmlspecialchars($selectedDate ?? date('Y-m-d')); ?>" max="<?php echo date('Y-m-d'); ?>" class="form-control form-control-sm" style="width:auto;">
          <button class="btn btn-sm btn-primary" type="submit">View day</button>
        </form>
        <div class="btn-group">
          <?php foreach (['today' => 'Today', 'week' => '7 days', 'month' => '30 days', 'all' => 'History'] as $p => $lbl): ?>
            <a href="?period=<?php echo $p; ?>" class="btn btn-sm <?php echo $period === $p ? 'btn-primary' : 'btn-outline-secondary'; ?>"><?php echo $lbl; ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <?php if (!$sales): ?>
      <div class="text-muted py-4 text-center">
        <i class="fas fa-receipt fa-2x d-block mb-2" style="opacity:.25;"></i>
        <?php echo $period === 'today' ? 'No sales recorded today yet.' : 'No sales in this period.'; ?>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-bordered align-middle mb-0 sales-sheet">
          <thead><tr class="text-muted small text-uppercase"><th>Receipt</th><th>Date &amp; time</th><th>Customer</th><th>Products</th><th>Pay</th><th class="text-end">Total</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($salesByDay as $day => $daySales):
              $daySum = Models\SaleModel::summarize($daySales);
            ?>
            <tr class="sheet-day-row">
              <td colspan="7">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <strong><?php echo date('l, j/m/Y', strtotime($day)); ?></strong>
                  <span><?php echo $daySum['count']; ?> sale<?php echo $daySum['count'] !== 1 ? 's' : ''; ?> · KES <?php echo number_format($daySum['revenue'], 0); ?></span>
                </div>
              </td>
            </tr>
            <?php foreach ($daySales as $s): ?>
            <tr>
              <td class="fw-semibold small"><?php echo htmlspecialchars($s['receipt_number']); ?></td>
              <td class="small text-nowrap"><?php echo date('D j M Y', strtotime($s['created_at'])); ?><div class="fw-semibold"><?php echo date('g:i:s a', strtotime($s['created_at'])); ?></div><?php if (!empty($s['edited_at'])): ?><div class="text-warning" style="font-size:.7rem;">edited <?php echo date('j M, g:i a', strtotime($s['edited_at'])); ?></div><?php endif; ?></td>
              <td class="small">
                <?php if ($s['customer_name'] && $s['customer_name'] !== 'Walk-in Customer'): ?>
                  <a href="<?php echo public_url('staff/sales/customer.php?name=' . urlencode($s['customer_name'])); ?>"><?php echo htmlspecialchars($s['customer_name']); ?></a>
                <?php else: ?>
                  <?php echo htmlspecialchars($s['customer_name'] ?: '—'); ?>
                <?php endif; ?>
              </td>
              <td class="small"><?php echo Models\SaleModel::itemsSummaryHtml($s['items']) . Models\ElectronicsModel::serialsHtml($s['devices']); ?></td>
              <td class="small"><?php
                $pm = $s['payment_method'] ?? 'cash';
                echo $pm === 'split' ? '<span class="badge bg-secondary">Split</span>' : ($pm === 'mpesa' ? '<span class="badge bg-success">M-Pesa</span>' : '<span class="badge bg-light text-dark">Cash</span>');
              ?></td>
              <td class="text-end fw-semibold small">KES <?php echo number_format((float) $s['total'], 0); ?></td>
              <td class="text-end">
                <div class="btn-group btn-group-sm">
                  <a class="btn btn-outline-secondary" href="<?php echo public_url($s['receipt_url']); ?>">Receipt</a>
                  <?php if (!empty($s['edit_url']) && TenantContext::can(Capabilities::SALES_RECORD)): ?>
                    <a class="btn btn-outline-primary" href="<?php echo public_url($s['edit_url']); ?>">Edit</a>
                  <?php endif; ?>
                </div>
                <?php
                  $canReverseDuplicate = (($s['source'] ?? 'sale') === 'sale')
                      || (($s['source'] ?? '') === 'order' && ($s['channel'] ?? '') === 'walkin');
                ?>
                <?php if ($canReverseDuplicate && TenantContext::can(Capabilities::SALES_RECORD)): ?>
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

<?php include __DIR__ . '/../../components/tenants/share_modal.php'; ?>
<style>
.sales-sheet{background:#fff;border-color:#cbd5e1;font-size:.88rem;}
.sales-sheet th{background:#f8fafc;border-color:#cbd5e1;font-weight:700;white-space:nowrap;}
.sales-sheet td{border-color:#dbe3ef;vertical-align:middle;}
.sales-sheet tbody tr:not(.sheet-day-row):hover td{background:#f9fbff;}
.sheet-day-row td{background:#eaf2ff!important;color:#0f172a;border-color:#b6c8e3!important;font-size:.9rem;}
</style>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/staff/layout.php';
