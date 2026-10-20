<?php
// public/super/reports/index.php — daily sales report (view / print / PDF)
require_once __DIR__ . '/../../../app/app.php';
PageGuard::money(); // owners/admins only — staff never see finances, profit or day totals

$pdo  = Database::pdo();
$date = preg_replace('/[^0-9-]/', '', $_GET['date'] ?? '') ?: date('Y-m-d');
$type = $_GET['type'] ?? 'general';
$type = in_array($type, ['general', 'sales', 'inventory', 'products', 'attendance', 'finance'], true) ? $type : 'general';
$data = SalesReport::data($pdo, TenantContext::tenantId(), $date, $type);

$cur = $data['shop']['currency'] ?: 'KES';
$sum = $data['sum'];
$money = fn($v) => $cur . ' ' . number_format((float) $v, 0);
$isToday = ($date === date('Y-m-d'));
$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
$canGoNext = $nextDate <= date('Y-m-d');

$page_title = 'Reports';
ob_start();
include __DIR__ . '/../../components/xsheet.php';
?>
<style>
@media print {
  .no-print, .sidebar, nav, header, footer { display:none !important; }
  .print-area { box-shadow:none !important; border:0 !important; }
  body { background:#fff !important; }
}
</style>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2 no-print">
  <h1 class="h5 mb-0 fw-bold"><?php echo htmlspecialchars(ucfirst($type)); ?> Report · <?php echo date('l, j F Y', strtotime($date)); ?></h1>
  <form method="get" class="d-flex align-items-center gap-2">
    <select name="type" class="form-select form-select-sm" style="width:auto;">
      <?php foreach (['general' => 'General', 'sales' => 'Sales only', 'inventory' => 'Inventory only', 'products' => 'Products only', 'attendance' => 'Attendance only', 'finance' => 'Finance only'] as $k => $label): ?>
      <option value="<?php echo $k; ?>" <?php echo $type === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
      <?php endforeach; ?>
    </select>
    <input type="date" name="date" value="<?php echo htmlspecialchars($date); ?>" max="<?php echo date('Y-m-d'); ?>" class="form-control form-control-sm" style="width:auto;">
    <button class="btn btn-sm btn-primary" type="submit">View</button>
    <a class="btn btn-sm btn-outline-secondary" href="?date=<?php echo urlencode($prevDate); ?>&type=<?php echo urlencode($type); ?>">Previous day</a>
    <?php if ($canGoNext): ?><a class="btn btn-sm btn-outline-secondary" href="?date=<?php echo urlencode($nextDate); ?>&type=<?php echo urlencode($type); ?>">Next day</a><?php endif; ?>
    <a class="btn btn-sm btn-outline-secondary" href="download.php?date=<?php echo urlencode($date); ?>&type=<?php echo urlencode($type); ?>"><i class="fas fa-file-pdf me-1"></i>PDF</a>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
  </form>
</div>

<div class="card border-0 shadow-sm print-area" style="border-radius:14px;">
  <div class="card-body p-4">
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
      <div>
        <div class="h5 fw-bold mb-0"><?php echo htmlspecialchars($data['shop']['name'] ?: 'Shop'); ?></div>
        <div class="text-muted"><?php echo htmlspecialchars(ucfirst($type)); ?> report · <?php echo date('l, j F Y', strtotime($date)); ?><?php echo $isToday ? ' · today' : ''; ?></div>
        <div class="small text-muted"><i class="far fa-clock me-1"></i>Generated <?php echo date('l, j M Y \a\t g:i:s a', strtotime($data['generated_at'])); ?> by <?php echo htmlspecialchars($data['generated_by']); ?></div>
        <?php if ($data['sales']): ?><div class="small text-muted">First sale <?php echo date('g:i:s a', strtotime($data['sales'][0]['created_at'])); ?> · last sale <?php echo date('g:i:s a', strtotime(end($data['sales'])['created_at'])); ?></div><?php endif; ?>
      </div>
      <div class="text-end small text-muted">
        <?php if ($data['shop']['phone']): ?><div><?php echo htmlspecialchars($data['shop']['phone']); ?></div><?php endif; ?>
        <?php if ($data['shop']['address']): ?><div><?php echo htmlspecialchars($data['shop']['address']); ?></div><?php endif; ?>
      </div>
    </div>

    <?php xsheet_search('table.rep-sheet', 'Search this report: receipt, staff, customer, product…  e.g. staff:jane  total:>1000', [
        'Cash' => 'pay:cash', 'M-Pesa' => 'pay:m-pesa', 'Credit' => 'pay:credit', 'Late' => 'status:late',
    ]); ?>
    <div class="row g-3 mb-4">
      <?php foreach ([['Sales',$sum['count']],['Revenue',$money($sum['revenue'])],['Cash',$money($sum['cash'])],['M-Pesa',$money($sum['mpesa'])]] as $b): ?>
      <div class="col-6 col-md-3">
        <div class="p-3" style="background:#f7f8fa;border:1px solid #e6e8ec;border-radius:10px;">
          <div class="text-muted small text-uppercase fw-semibold"><?php echo $b[0]; ?></div>
          <div class="h5 mb-0 fw-bold"><?php echo is_string($b[1]) ? htmlspecialchars($b[1]) : $b[1]; ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if (in_array($type, ['general', 'sales'], true)): ?>
    <h2 class="h6 fw-bold mb-2">Sales <span class="text-muted">(<?php echo $sum['count']; ?>)</span></h2>
    <?php if (!$data['sales']): ?>
      <p class="text-muted">No sales recorded on this day.</p>
    <?php else: ?>
    <div class="table-responsive mb-4">
      <table class="xsheet rep-sheet" data-page-size="20">
        <?php $hasDevices = (bool) array_filter(array_column($data['sales'], 'devices')); ?>
        <thead><tr><th>#</th><th>Receipt</th><th>Date &amp; time</th><th>Staff</th><th>Customer</th><th>Items</th><?php if ($hasDevices): ?><th>Serial / IMEI</th><?php endif; ?><th>Pay</th><th class="text-end">Total</th></tr></thead>
        <tbody>
          <?php foreach ($data['sales'] as $n => $s): ?>
          <tr>
            <td class="small text-muted"><?php echo $n + 1; ?></td>
            <td class="small fw-semibold"><?php echo htmlspecialchars($s['receipt_number']); ?></td>
            <td class="small text-nowrap"><?php echo date('D j M Y', strtotime($s['created_at'])); ?><div class="fw-semibold"><?php echo date('g:i:s a', strtotime($s['created_at'])); ?></div></td>
            <td class="small"><?php echo htmlspecialchars($s['staff_name'] ?: '—'); ?></td>
            <td class="small"><?php echo htmlspecialchars($s['customer_name'] ?: '—'); ?><?php if (!empty($s['customer_phone'])): ?><div class="text-muted"><?php echo htmlspecialchars($s['customer_phone']); ?></div><?php endif; ?></td>
            <td class="small"><?php echo htmlspecialchars(SalesReport::itemsText($s['items'] ?? [])) ?: '—'; ?></td>
            <?php if ($hasDevices): ?><td class="small"><?php echo Models\ElectronicsModel::serialsHtml($s['devices'] ?? []) ?: '—'; ?></td><?php endif; ?>
            <td><?php echo ['cash' => '<span class="badge bg-light text-dark">Cash</span>', 'mpesa' => '<span class="badge bg-success">M-Pesa</span>', 'split' => '<span class="badge bg-info text-dark">Split</span>', 'credit' => '<span class="badge bg-warning text-dark">Credit</span>'][$s['payment_method']] ?? htmlspecialchars((string) $s['payment_method']); ?></td>
            <td class="text-end fw-semibold"><?php echo $money($s['total']); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (in_array($type, ['general', 'sales'], true) && !empty($data['services'])): ?>
    <h2 class="h6 fw-bold mb-2">Services <span class="text-muted">(<?php echo count($data['services']); ?>)</span>
      <span class="badge bg-success ms-1">Earnings <?php echo $money(array_sum(array_column($data['services'], 'total'))); ?></span></h2>
    <div class="table-responsive mb-4">
      <table class="xsheet rep-sheet" data-page-size="20">
        <thead><tr><th>Visit</th><th>Time</th><th>Client</th><th>Services (staff)</th><th>Pay</th><th class="text-end">Total</th></tr></thead>
        <tbody>
          <?php foreach ($data['services'] as $v): ?>
          <tr class="small">
            <td class="fw-semibold"><?php echo htmlspecialchars($v['number']); ?></td>
            <td class="text-nowrap"><?php echo date('D j M Y, g:i:s a', strtotime($v['paid_at'])); ?></td>
            <td><?php echo htmlspecialchars($v['customer_name']); ?></td>
            <td><?php echo htmlspecialchars($v['services'] ?? ''); ?></td>
            <td><?php echo $v['payment_method'] === 'split' ? 'Split' : ($v['payment_method'] === 'mpesa' ? 'M-Pesa' : 'Cash'); ?></td>
            <td class="text-end fw-semibold"><?php echo $money($v['total']); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if (in_array($type, ['general', 'sales'], true) && !empty($data['repairs'])):
        $repairTotal = array_sum(array_map(fn($r) => (int) $r['fee_paid'] ? (float) $r['repair_fee'] : 0, $data['repairs'])); ?>
    <h2 class="h6 fw-bold mb-2">Repairs collected <span class="text-muted">(<?php echo count($data['repairs']); ?>)</span>
      <span class="badge bg-success ms-1">Earnings <?php echo $money($repairTotal); ?></span></h2>
    <div class="table-responsive mb-4">
      <table class="xsheet rep-sheet" data-page-size="20">
        <thead><tr><th>Device</th><th>Customer</th><th>Issue</th><th>Cover</th><th class="text-end">Charged</th></tr></thead>
        <tbody>
          <?php foreach ($data['repairs'] as $r): ?>
          <tr class="small">
            <td><?php echo htmlspecialchars($r['product_name'] ?? ''); ?><div class="font-monospace text-muted"><?php echo htmlspecialchars($r['serial']); ?></div></td>
            <td><?php echo htmlspecialchars($r['customer_name'] ?: '—'); ?></td>
            <td><?php echo htmlspecialchars($r['issue']); ?></td>
            <td><?php echo $r['covered'] ? '<span class="badge bg-success">Warranty</span>' : ($r['under_warranty'] ? '<span class="badge bg-warning text-dark">Not covered</span>' . ($r['charge_reason'] ? '<div class="text-muted">' . htmlspecialchars($r['charge_reason']) . '</div>' : '') : '<span class="badge bg-secondary">Out of warranty</span>'); ?></td>
            <td class="text-end fw-semibold"><?php echo $r['covered'] ? '—' : $money($r['repair_fee']) . ' <span class="text-muted fw-normal">' . ($r['payment_method'] === 'mpesa' ? 'M-Pesa' : 'cash') . '</span>'; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if (in_array($type, ['general', 'products'], true) && $data['products']): ?>
    <h2 class="h6 fw-bold mb-2">Products sold</h2>
    <div class="table-responsive mb-4">
      <table class="xsheet rep-sheet" data-page-size="20">
        <thead><tr><th>Product</th><th>First sold</th><th>Last sold</th><th class="text-end">Qty</th><th class="text-end">Revenue</th></tr></thead>
        <tbody>
          <?php foreach ($data['products'] as $p): ?>
          <tr>
            <td class="small"><?php echo htmlspecialchars($p['product_name']); ?></td>
            <td class="small text-nowrap"><?php echo !empty($p['first_at']) ? date('g:i:s a', strtotime($p['first_at'])) : '—'; ?></td>
            <td class="small text-nowrap"><?php echo !empty($p['last_at']) ? date('g:i:s a', strtotime($p['last_at'])) : '—'; ?></td>
            <td class="text-end small"><?php echo rtrim(rtrim(number_format((float)$p['qty'],2),'0'),'.'); ?></td>
            <td class="text-end fw-semibold"><?php echo $money($p['revenue']); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if (in_array($type, ['general', 'inventory'], true)): ?>
    <h2 class="h6 fw-bold mb-2">Inventory</h2>
    <div class="table-responsive mb-4">
      <table class="xsheet rep-sheet" data-page-size="20">
        <thead><tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Cost</th><th class="text-end">Retail</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($data['inventory'] as $p): ?>
          <tr><td class="small"><?php echo htmlspecialchars($p['name']); ?></td><td class="text-end small"><?php echo rtrim(rtrim(number_format((float)$p['quantity'],2),'0'),'.'); ?></td><td class="text-end small"><?php echo $money($p['buying_price']); ?></td><td class="text-end small"><?php echo $money($p['retail_price']); ?></td><td class="small"><?php echo htmlspecialchars($p['status']); ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if (in_array($type, ['general', 'attendance'], true)): ?>
    <h2 class="h6 fw-bold mb-2">Attendance</h2>
    <?php if (!$data['attendance']): ?><p class="text-muted">No attendance recorded on this day.</p><?php else: ?>
    <div class="table-responsive mb-4">
      <table class="xsheet rep-sheet" data-page-size="20">
        <thead><tr><th>Staff</th><th>Clock in</th><th>Clock out</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($data['attendance'] as $a): ?>
          <tr><td class="small"><?php echo htmlspecialchars($a['username']); ?></td><td class="small"><?php echo date('D j M Y, g:i:s a', strtotime($a['clock_in_at'])); ?></td><td class="small"><?php echo $a['clock_out_at'] ? date('D j M Y, g:i:s a', strtotime($a['clock_out_at'])) : 'Still in'; ?></td><td><?php echo !empty($a['late_clock_in']) ? '<span class="badge bg-danger">Late</span>' : (!empty($a['auto_closed']) ? '<span class="badge bg-warning text-dark">Auto closed</span>' : '<span class="badge bg-success">On time</span>'); ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (in_array($type, ['general', 'finance'], true)): $fs = $data['finance']['summary']; ?>
    <h2 class="h6 fw-bold mb-2">Finance</h2>
    <div class="row g-2 mb-4">
      <?php foreach ([['Assets',$fs['assets']],['Liabilities',$fs['liabilities']],['Equity',$fs['equity']],['Supplier debt',$fs['supplier_debt']],['Expenses',$fs['expenses']]] as $f): ?>
      <div class="col-6 col-md"><div class="p-3 bg-light rounded"><div class="text-muted small"><?php echo htmlspecialchars($f[0]); ?></div><strong><?php echo $money($f[1]); ?></strong></div></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (in_array($type, ['general', 'sales'], true) && $data['staff']): ?>
    <h2 class="h6 fw-bold mb-2">By staff member</h2>
    <div class="table-responsive">
      <table class="xsheet rep-sheet" data-page-size="20">
        <thead><tr><th>Staff</th><th class="text-end">Sales</th><th class="text-end">Revenue</th></tr></thead>
        <tbody>
          <?php foreach ($data['staff'] as $name => $d): ?>
          <tr><td class="small"><?php echo htmlspecialchars($name); ?></td><td class="text-end small"><?php echo $d['count']; ?></td><td class="text-end fw-semibold"><?php echo $money($d['revenue']); ?></td></tr>
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
