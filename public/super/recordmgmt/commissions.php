<?php
// public/super/recordmgmt/commissions.php — company earnings and commissions
// earned (total and per employee) for a chosen day/period.
require_once __DIR__ . '/_common.php';
rm_require($money || $canOperate);

$range = $_GET['range'] ?? 'today';
$range = in_array($range, ['today', 'yesterday', 'week', 'month'], true) ? $range : 'today';
$to = $RM->businessDayStart();
$from = match ($range) {
    'yesterday' => $to = $RM->businessYesterdayStart(),
    'week' => $RM->businessDayStart(date('Y-m-d', strtotime('-6 days'))),
    'month' => $RM->businessDayStart(date('Y-m-01')),
    default => $to,
};

$totals = $RM->companyTotals($from, $to);
$byEmployee = $RM->commissionSummary($from, $to);
$trend = $money ? $RM->dailyTrend(7) : [];
$maxCash = $trend ? max(1.0, (float) max(array_merge(array_column($trend, 'cash_in'), array_column($trend, 'cash_out')))) : 1.0;
$netCash = array_sum(array_column($trend, 'net'));

ob_start();
?>
<div class="d-flex gap-2 mb-3">
  <?php foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'Last 7 days', 'month' => 'This month'] as $k => $l): ?>
    <a href="?range=<?php echo $k; ?>" class="btn btn-sm <?php echo $range === $k ? 'btn-primary' : 'btn-outline-secondary'; ?>"><?php echo $l; ?></a>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm p-3" style="border-radius:14px;"><div class="small text-muted">Revenue</div><div class="h5 fw-bold mb-0"><?php echo rm_money($totals['revenue']); ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm p-3" style="border-radius:14px;"><div class="small text-muted">After products</div><div class="h5 fw-bold mb-0"><?php echo rm_money($totals['net']); ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm p-3" style="border-radius:14px;"><div class="small text-muted">Commissions</div><div class="h5 fw-bold text-success mb-0"><?php echo rm_money($totals['commissions']); ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm p-3" style="border-radius:14px;"><div class="small text-muted">Jobs</div><div class="h5 fw-bold mb-0"><?php echo (int) $totals['jobs']; ?></div></div></div>
</div>

<?php if ($money && $trend): ?>
<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;"><div class="card-body p-4">
  <h2 class="h6 fw-bold mb-1">Company cash flow — last 7 days</h2>
  <div class="small text-muted mb-3">Net: <span class="fw-bold <?php echo $netCash >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo rm_money($netCash); ?></span> (revenue in, minus expenses and payouts out)</div>
  <div class="row g-3">
  <?php foreach ($trend as $t): $inPct = round($t['cash_in'] / $maxCash * 100); $outPct = round($t['cash_out'] / $maxCash * 100); ?>
    <div class="col-6 col-md-3 col-lg-12">
      <div class="d-flex justify-content-between small"><span><?php echo rm_h(date('D j M', strtotime($t['date']))); ?></span><span class="text-success">+<?php echo rm_money($t['cash_in']); ?></span><span class="text-danger">−<?php echo rm_money($t['cash_out']); ?></span></div>
      <div class="progress mb-1" style="height:6px;"><div class="progress-bar bg-success" style="width:<?php echo $inPct; ?>%"></div></div>
      <div class="progress" style="height:6px;"><div class="progress-bar bg-danger" style="width:<?php echo $outPct; ?>%"></div></div>
    </div>
  <?php endforeach; ?>
  </div>
</div></div>
<?php endif; ?>

<div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
  <h2 class="h6 fw-bold mb-3">Per employee <span class="small fw-normal text-muted">— grouped A–Z</span></h2>
  <div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>Employee</th><th>Role</th><th>Jobs</th><th>Earned (net)</th><th>Commission</th><th>Owed now</th></tr></thead>
    <tbody>
    <?php $groups = rm_alpha_groups($byEmployee, fn($e) => trim($e['first_name'] . ' ' . $e['last_name'])); foreach ($groups as $letter => $rows): ?>
      <tr class="table-light"><th colspan="6" class="small text-uppercase text-muted py-1"><?php echo rm_h($letter); ?></th></tr>
      <?php foreach ($rows as $e): ?>
      <tr>
        <td><?php echo rm_h(trim($e['first_name'] . ' ' . $e['last_name'])); ?></td>
        <td><?php echo rm_h($e['role']); ?></td>
        <td><?php echo (int) $e['jobs']; ?></td>
        <td><?php echo rm_money($e['earned']); ?></td>
        <td class="text-success fw-semibold"><?php echo rm_money($e['commission']); ?></td>
        <td class="fw-bold"><?php echo rm_money($e['owed']); ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <?php if (!$byEmployee): ?><tr><td colspan="6" class="text-center text-muted py-3">No employees yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div></div>
<?php
$content = ob_get_clean();
rm_render('Commissions', $content);
