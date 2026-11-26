<?php
// public/super/recordmgmt/tracker.php — employee performance tracker: who's
// working the most, red/green against the account average, plus an
// Excel-style table (and a real .xlsx export).
require_once __DIR__ . '/_common.php';
rm_require($money);

$range = in_array($_GET['range'] ?? 'month', ['week', 'month'], true) ? $_GET['range'] : 'month';
$to = date('Y-m-d');
$from = $range === 'week' ? date('Y-m-d', strtotime('-6 days')) : date('Y-m-01');
$t = $RM->trackerTotals($from, $to);
$rows = $t['rows'];
$avg = $t['average'];

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div class="d-flex gap-2">
    <a href="?range=week" class="btn btn-sm <?php echo $range === 'week' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Last 7 days</a>
    <a href="?range=month" class="btn btn-sm <?php echo $range === 'month' ? 'btn-primary' : 'btn-outline-secondary'; ?>">This month</a>
  </div>
  <a href="tracker-export.php?range=<?php echo $range; ?>" class="btn btn-sm btn-outline-success"><i class="fa fa-file-excel"></i> Export Excel</a>
</div>

<div class="card border-0 shadow-sm mb-3" style="border-radius:14px;"><div class="card-body p-4">
  <h2 class="h6 fw-bold mb-3">Revenue vs. average (<?php echo rm_money($avg); ?>)</h2>
  <?php $max = max(array_column($rows, 'revenue') ?: [1]) ?: 1; ?>
  <?php foreach ($rows as $r): $over = $r['revenue'] >= $avg; ?>
    <div class="mb-2">
      <div class="d-flex justify-content-between small"><span><?php echo rm_h($r['name']); ?></span><span><?php echo rm_money($r['revenue']); ?></span></div>
      <div class="progress" style="height:8px;"><div class="progress-bar <?php echo $over ? 'bg-success' : 'bg-danger'; ?>" style="width:<?php echo round($r['revenue'] / $max * 100); ?>%"></div></div>
    </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><div class="text-muted small text-center py-3">No activity in this period.</div><?php endif; ?>
</div></div>

<div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
  <h2 class="h6 fw-bold mb-3">Table</h2>
  <div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>Employee</th><th>Role</th><th>Jobs</th><th>Revenue</th><th>Commission</th><th>vs. average</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $over = $r['revenue'] >= $avg; ?>
      <tr class="<?php echo $over ? 'table-success' : 'table-danger'; ?>">
        <td><?php echo rm_h($r['name']); ?></td>
        <td><?php echo rm_h($r['role']); ?></td>
        <td><?php echo (int) $r['jobs']; ?></td>
        <td><?php echo rm_money($r['revenue']); ?></td>
        <td><?php echo rm_money($r['commission']); ?></td>
        <td><?php echo $over ? 'Above' : 'Below'; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-3">No employees yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div></div>
<?php
$content = ob_get_clean();
rm_render('Employee tracker', $content);
