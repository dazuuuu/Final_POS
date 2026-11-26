<?php
// public/super/recordmgmt/commissions.php — company earnings and commissions
// earned (total and per employee) for a chosen day/period.
require_once __DIR__ . '/_common.php';
rm_require($money || $canOperate);

$range = in_array($_GET['range'] ?? 'today', ['today', 'week', 'month'], true) ? $_GET['range'] : 'today';
$to = date('Y-m-d');
$from = match ($range) { 'week' => date('Y-m-d', strtotime('-6 days')), 'month' => date('Y-m-01'), default => $to };

$totals = $RM->companyTotals($from, $to);
$byEmployee = $RM->commissionSummary($from, $to);

ob_start();
?>
<div class="d-flex gap-2 mb-3">
  <?php foreach (['today' => 'Today', 'week' => 'Last 7 days', 'month' => 'This month'] as $k => $l): ?>
    <a href="?range=<?php echo $k; ?>" class="btn btn-sm <?php echo $range === $k ? 'btn-primary' : 'btn-outline-secondary'; ?>"><?php echo $l; ?></a>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm p-3" style="border-radius:14px;"><div class="small text-muted">Revenue</div><div class="h5 fw-bold mb-0"><?php echo rm_money($totals['revenue']); ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm p-3" style="border-radius:14px;"><div class="small text-muted">After products</div><div class="h5 fw-bold mb-0"><?php echo rm_money($totals['net']); ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm p-3" style="border-radius:14px;"><div class="small text-muted">Commissions</div><div class="h5 fw-bold text-success mb-0"><?php echo rm_money($totals['commissions']); ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm p-3" style="border-radius:14px;"><div class="small text-muted">Jobs</div><div class="h5 fw-bold mb-0"><?php echo (int) $totals['jobs']; ?></div></div></div>
</div>

<div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
  <h2 class="h6 fw-bold mb-3">Per employee</h2>
  <div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>Employee</th><th>Role</th><th>Jobs</th><th>Earned (net)</th><th>Commission</th><th>Owed now</th></tr></thead>
    <tbody>
    <?php foreach ($byEmployee as $e): ?>
      <tr>
        <td><?php echo rm_h(trim($e['first_name'] . ' ' . $e['last_name'])); ?></td>
        <td><?php echo rm_h($e['role']); ?></td>
        <td><?php echo (int) $e['jobs']; ?></td>
        <td><?php echo rm_money($e['earned']); ?></td>
        <td class="text-success fw-semibold"><?php echo rm_money($e['commission']); ?></td>
        <td class="fw-bold"><?php echo rm_money($e['owed']); ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$byEmployee): ?><tr><td colspan="6" class="text-center text-muted py-3">No employees yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div></div>
<?php
$content = ob_get_clean();
rm_render('Commissions', $content);
