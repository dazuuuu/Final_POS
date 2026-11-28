<?php
// public/super/recordmgmt/employees-report.php — a printable report of what
// employees have done: jobs, revenue, commission earned and amounts paid,
// for one employee or all of them, over a date range. Black & white, the
// owner's own logo, a quiet "from dazupos" footer — meant to be printed or
// saved as a PDF and handed to someone, not a dashboard page.
require_once __DIR__ . '/_common.php';
rm_require($money || $canManage);

$employees = $RM->employees(false);
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$empId = (int) ($_GET['employee_id'] ?? 0);
$generate = ($_GET['action'] ?? '') === 'generate';

if ($generate) {
    $rows = $RM->employeeReport($empId ?: null, $from, $to);
    $one = $empId ? ($RM->employee($empId)) : null;
    $title = 'Employees report';
    $subtitle = ($one ? rm_h(rm_name($one)) . ' — ' : 'All employees — ') . date('j M Y', strtotime($from)) . ' to ' . date('j M Y', strtotime($to));

    ob_start();
    $totJobs = 0; $totRevenue = 0; $totCommission = 0; $totPaid = 0;
    ?>
    <table>
      <thead><tr><th>Employee</th><th>Role</th><th>Jobs</th><th>Revenue</th><th>Commission</th><th>Paid (period)</th><th>Owed now</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $totJobs += (int) $r['jobs']; $totRevenue += (float) $r['revenue']; $totCommission += (float) $r['commission']; $totPaid += (float) $r['paid']; ?>
        <tr>
          <td><?php echo rm_h(rm_name($r)); ?></td>
          <td><?php echo rm_h($r['role']); ?></td>
          <td><?php echo (int) $r['jobs']; ?></td>
          <td><?php echo rm_money($r['revenue']); ?></td>
          <td><?php echo rm_money($r['commission']); ?></td>
          <td><?php echo rm_money($r['paid']); ?></td>
          <td><?php echo rm_money($r['owed']); ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" style="text-align:center;">No activity in this period.</td></tr><?php endif; ?>
      </tbody>
      <?php if (count($rows) > 1): ?>
      <tfoot><tr><td colspan="2">Total</td><td><?php echo $totJobs; ?></td><td><?php echo rm_money($totRevenue); ?></td><td><?php echo rm_money($totCommission); ?></td><td><?php echo rm_money($totPaid); ?></td><td></td></tr></tfoot>
      <?php endif; ?>
    </table>
    <?php
    $body = ob_get_clean();
    rm_report_page($title, $subtitle, $body);
}

ob_start();
?>
<div class="card border-0 shadow-sm" style="border-radius:14px;max-width:560px;"><div class="card-body p-4">
  <h2 class="h6 fw-bold mb-3">Employees report</h2>
  <p class="small text-muted">A printable report of jobs, revenue, commission and amounts paid — one employee or all of them.</p>
  <form method="get" target="_blank">
    <input type="hidden" name="action" value="generate">
    <label class="form-label small">Employee</label>
    <select name="employee_id" class="form-select mb-2">
      <option value="0">All employees</option>
      <?php foreach ($employees as $e): ?><option value="<?php echo (int) $e['id']; ?>"><?php echo rm_h(rm_name($e)); ?></option><?php endforeach; ?>
    </select>
    <div class="row g-2">
      <div class="col-6"><label class="form-label small">From</label><input type="date" name="from" value="<?php echo rm_h($from); ?>" class="form-control"></div>
      <div class="col-6"><label class="form-label small">To</label><input type="date" name="to" value="<?php echo rm_h($to); ?>" class="form-control"></div>
    </div>
    <button class="btn btn-primary w-100 mt-3"><i class="fa fa-file-lines me-1"></i>Generate report</button>
  </form>
</div></div>
<?php
$content = ob_get_clean();
rm_render('Employees report', $content);
