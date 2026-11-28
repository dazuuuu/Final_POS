<?php
// public/super/recordmgmt/dashboard.php — overview for a Record Management
// (standalone) account: today's operations, today's commissions, low stock
// and today's expenses. Reached from super/dashboard/ which redirects here
// for accounts with no shop/services/restaurant package alongside this one.
require_once __DIR__ . '/_common.php';

$today = $RM->businessDayStart();
$totals = $RM->companyTotals($today, $today);
$lowStock = $RM->lowStock();
$expensesToday = array_sum(array_column($RM->expenses($today, $today), 'amount'));
$ops = $RM->operations($today, $today);
$yesterday = $RM->businessYesterdayStart();
$totalsYesterday = $RM->companyTotals($yesterday, $yesterday);
$trend = $RM->dailyTrend(7);
$maxCash = max(1.0, (float) max(array_merge(array_column($trend, 'cash_in'), array_column($trend, 'cash_out'))));
$netCash = array_sum(array_column($trend, 'net'));

ob_start();
?>
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm p-3" style="border-radius:14px;"><div class="small text-muted">Today's revenue</div><div class="h5 fw-bold mb-0"><?php echo rm_money($totals['revenue']); ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm p-3" style="border-radius:14px;"><div class="small text-muted">Today's commissions</div><div class="h5 fw-bold text-success mb-0"><?php echo rm_money($totals['commissions']); ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm p-3" style="border-radius:14px;"><div class="small text-muted">Today's expenses</div><div class="h5 fw-bold text-danger mb-0"><?php echo rm_money($expensesToday); ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm p-3" style="border-radius:14px;"><div class="small text-muted">Jobs today</div><div class="h5 fw-bold mb-0"><?php echo (int) $totals['jobs']; ?></div></div></div>
</div>

<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;"><div class="card-body p-3">
  <div class="small text-muted text-uppercase fw-bold mb-2">Yesterday (<?php echo date('j M', strtotime($yesterday)); ?>)</div>
  <div class="row g-3">
    <div class="col-4"><div class="small text-muted">Revenue</div><div class="fw-bold"><?php echo rm_money($totalsYesterday['revenue']); ?></div></div>
    <div class="col-4"><div class="small text-muted">Commissions</div><div class="fw-bold text-success"><?php echo rm_money($totalsYesterday['commissions']); ?></div></div>
    <div class="col-4"><div class="small text-muted">Jobs</div><div class="fw-bold"><?php echo (int) $totalsYesterday['jobs']; ?></div></div>
  </div>
</div></div>

<div class="row g-4 mb-4">
  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Performance — last 7 days</h2>
      <?php foreach ($trend as $t): $pct = round($t['revenue'] / $maxCash * 100); ?>
        <div class="mb-2">
          <div class="d-flex justify-content-between small"><span><?php echo rm_h(date('D j M', strtotime($t['date']))); ?></span><span><?php echo rm_money($t['revenue']); ?> revenue · <span class="text-success"><?php echo rm_money($t['commissions']); ?> commission</span></span></div>
          <div class="progress" style="height:8px;"><div class="progress-bar bg-primary" style="width:<?php echo $pct; ?>%"></div></div>
        </div>
      <?php endforeach; ?>
      <a href="<?php echo public_url('super/recordmgmt/tracker.php'); ?>" class="btn btn-sm btn-outline-primary mt-2">Full employee tracker</a>
    </div></div>
  </div>
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Cash flow — last 7 days</h2>
      <div class="small text-muted mb-2">Net: <span class="fw-bold <?php echo $netCash >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo rm_money($netCash); ?></span> (revenue minus expenses and payouts)</div>
      <?php foreach ($trend as $t): $inPct = round($t['cash_in'] / $maxCash * 100); $outPct = round($t['cash_out'] / $maxCash * 100); ?>
        <div class="mb-2">
          <div class="d-flex justify-content-between small"><span><?php echo rm_h(date('D j M', strtotime($t['date']))); ?></span><span class="text-success">+<?php echo rm_money($t['cash_in']); ?></span></div>
          <div class="progress mb-1" style="height:6px;"><div class="progress-bar bg-success" style="width:<?php echo $inPct; ?>%"></div></div>
          <div class="d-flex justify-content-between small"><span></span><span class="text-danger">−<?php echo rm_money($t['cash_out']); ?></span></div>
          <div class="progress" style="height:6px;"><div class="progress-bar bg-danger" style="width:<?php echo $outPct; ?>%"></div></div>
        </div>
      <?php endforeach; ?>
    </div></div>
  </div>
</div>

<div class="row g-4">
  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Today's operations</h2>
      <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Time</th><th>Employee</th><th>Service</th><th>Amount</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($ops, 0, 10) as $o): ?>
          <tr>
            <td><?php echo date('H:i', strtotime($o['created_at'])); ?></td>
            <td><?php echo rm_h($o['first_name'] . ' ' . $o['last_name']); ?></td>
            <td><?php echo rm_h($o['service_name']); ?></td>
            <td><?php echo rm_money($o['amount']); ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$ops): ?><tr><td colspan="4" class="text-center text-muted py-3">Nothing recorded yet today.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
      <a href="<?php echo public_url('super/recordmgmt/'); ?>" class="btn btn-sm btn-primary">Go to Operations</a>
    </div></div>
  </div>
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Low stock</h2>
      <?php foreach ($lowStock as $p): ?>
        <div class="d-flex justify-content-between border-bottom py-2">
          <span><?php echo rm_h($p['name']); ?></span>
          <span class="text-danger fw-semibold"><?php echo rm_h($p['quantity']); ?> left</span>
        </div>
      <?php endforeach; ?>
      <?php if (!$lowStock): ?><div class="text-muted small text-center py-3">Stock levels look fine.</div><?php endif; ?>
      <a href="<?php echo public_url('super/recordmgmt/products.php'); ?>" class="btn btn-sm btn-outline-primary mt-3">Manage products</a>
    </div></div>
  </div>
</div>
<?php
$content = ob_get_clean();
rm_render('Dashboard', $content);
