<?php
// public/super/profits/index.php — Retail vs wholesale profit, always both
// on screen side by side, plus a daily graph over the chosen range
// (day/month/year navigable via from/to).
require_once __DIR__ . '/../../../app/app.php';
PageGuard::auth(Capabilities::FINANCE_VIEW);

$pdo = Database::pdo();
$SA = new Models\SaleModel($pdo);
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);

$range = $_GET['range'] ?? 'month';
$range = in_array($range, ['today', 'week', 'month', 'year'], true) ? $range : 'month';
$to = date('Y-m-d');
$from = match ($range) {
    'today' => $to,
    'week'  => date('Y-m-d', strtotime('-6 days')),
    'year'  => date('Y-01-01'),
    default => date('Y-m-01'),
};
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '')) { $from = $_GET['from']; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')) { $to = $_GET['to']; }
if ($from > $to) { [$from, $to] = [$to, $from]; }

$error = null;
$days = [];
try {
    $days = $SA->profitByPriceType($from, $to);
} catch (\RuntimeException $e) {
    $error = 'This shop\'s products have no cost price set up yet, so profit can\'t be calculated.';
}

$totals = ['retail_revenue' => 0.0, 'retail_cost' => 0.0, 'retail_profit' => 0.0, 'wholesale_revenue' => 0.0, 'wholesale_cost' => 0.0, 'wholesale_profit' => 0.0];
foreach ($days as $d) {
    foreach ($totals as $k => $v) { $totals[$k] += $d[$k]; }
}
$maxProfit = max(1.0, (float) max(array_merge([0], array_column($days, 'retail_profit'), array_column($days, 'wholesale_profit'))));

$page_title = 'Profits';
ob_start();
?>
<div class="card border-0 shadow-sm mb-3" style="border-radius:14px;"><div class="card-body p-3">
  <form class="row g-2 align-items-end">
    <div class="col-auto">
      <label class="form-label small mb-1">Range</label>
      <select name="range" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach (['today' => 'Today', 'week' => 'Last 7 days', 'month' => 'This month', 'year' => 'This year'] as $k => $l): ?>
          <option value="<?php echo $k; ?>" <?php echo $range === $k ? 'selected' : ''; ?>><?php echo $l; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto"><label class="form-label small mb-1">From</label><input type="date" name="from" value="<?php echo $h($from); ?>" class="form-control form-control-sm"></div>
    <div class="col-auto"><label class="form-label small mb-1">To</label><input type="date" name="to" value="<?php echo $h($to); ?>" class="form-control form-control-sm"></div>
    <div class="col-auto"><button class="btn btn-sm btn-primary">Apply</button></div>
  </form>
</div></div>

<?php if ($error): ?>
  <div class="alert alert-warning"><?php echo $h($error); ?></div>
<?php else: ?>

<div class="row g-3 mb-3">
  <div class="col-6">
    <div class="card border-0 shadow-sm h-100" style="border-radius:14px;"><div class="card-body p-3">
      <div class="small text-muted text-uppercase fw-bold mb-1">Retail profit</div>
      <div class="h4 fw-bold text-success mb-1">KES <?php echo number_format($totals['retail_profit'], 0); ?></div>
      <div class="small text-muted">Revenue KES <?php echo number_format($totals['retail_revenue'], 0); ?> · Cost KES <?php echo number_format($totals['retail_cost'], 0); ?></div>
    </div></div>
  </div>
  <div class="col-6">
    <div class="card border-0 shadow-sm h-100" style="border-radius:14px;"><div class="card-body p-3">
      <div class="small text-muted text-uppercase fw-bold mb-1">Wholesale profit</div>
      <div class="h4 fw-bold text-primary mb-1">KES <?php echo number_format($totals['wholesale_profit'], 0); ?></div>
      <div class="small text-muted">Revenue KES <?php echo number_format($totals['wholesale_revenue'], 0); ?> · Cost KES <?php echo number_format($totals['wholesale_cost'], 0); ?></div>
    </div></div>
  </div>
</div>

<div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-3">
  <h2 class="h6 fw-bold mb-3">Which is making the most money — day by day</h2>
  <?php if (!$days): ?>
    <p class="small text-muted mb-0">No sales in this period.</p>
  <?php else: ?>
  <div class="d-flex gap-1 align-items-end" style="height:160px;overflow-x:auto;">
    <?php foreach ($days as $d): ?>
      <div class="d-flex flex-column align-items-center" style="min-width:34px;" title="<?php echo $h($d['date']); ?>">
        <div class="d-flex align-items-end gap-1" style="height:130px;">
          <div class="bg-success rounded-top" style="width:10px;height:<?php echo max(2, round($d['retail_profit'] / $maxProfit * 130)); ?>px;" title="Retail: KES <?php echo number_format($d['retail_profit'], 0); ?>"></div>
          <div class="bg-primary rounded-top" style="width:10px;height:<?php echo max(2, round($d['wholesale_profit'] / $maxProfit * 130)); ?>px;" title="Wholesale: KES <?php echo number_format($d['wholesale_profit'], 0); ?>"></div>
        </div>
        <div class="small text-muted mt-1" style="font-size:10px;"><?php echo date('d/m', strtotime($d['date'])); ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="small text-muted mt-3"><span class="badge bg-success">&nbsp;</span> Retail &nbsp; <span class="badge bg-primary">&nbsp;</span> Wholesale</div>
  <?php endif; ?>
</div></div>

<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
