<?php
// public/super/recordmgmt/staff-report.php — a printable report of what each
// logged-in staff member (admin/supervisor/cashier) has recorded: operations
// entered, revenue processed, payments made to employees and expenses
// logged — one person or all, over a date range. Black & white, the
// owner's own logo, a quiet "from dazupos" footer.
require_once __DIR__ . '/_common.php';
rm_require($isOwner);

$staffUsers = $RM->staffUsers();
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$userId = (int) ($_GET['user_id'] ?? 0);
$generate = ($_GET['action'] ?? '') === 'generate';

if ($generate) {
    $rows = $RM->staffReport($userId ?: null, $from, $to);
    $one = $userId ? array_values(array_filter($staffUsers, fn($u) => (int) $u['id'] === $userId))[0] ?? null : null;
    $title = 'Staff report';
    $subtitle = ($one ? rm_h($one['username']) . ' — ' : 'All staff — ') . date('j M Y', strtotime($from)) . ' to ' . date('j M Y', strtotime($to));

    ob_start();
    $totJobs = 0; $totRevenue = 0; $totPaidOut = 0; $totExp = 0;
    ?>
    <table>
      <thead><tr><th>Staff</th><th>Operations recorded</th><th>Revenue processed</th><th>Payments made</th><th>Expenses logged</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $totJobs += $r['jobs']; $totRevenue += $r['revenue']; $totPaidOut += $r['payments_total']; $totExp += $r['expenses_total']; ?>
        <tr>
          <td><?php echo rm_h($r['username']); ?></td>
          <td><?php echo (int) $r['jobs']; ?> jobs — <?php echo rm_money($r['revenue']); ?></td>
          <td><?php echo rm_money($r['revenue']); ?></td>
          <td><?php echo (int) $r['payments_made']; ?> payment(s) — <?php echo rm_money($r['payments_total']); ?></td>
          <td><?php echo (int) $r['expenses_logged']; ?> expense(s) — <?php echo rm_money($r['expenses_total']); ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="5" style="text-align:center;">Nothing recorded by staff in this period.</td></tr><?php endif; ?>
      </tbody>
      <?php if (count($rows) > 1): ?>
      <tfoot><tr><td>Total</td><td><?php echo $totJobs; ?> jobs</td><td><?php echo rm_money($totRevenue); ?></td><td><?php echo rm_money($totPaidOut); ?></td><td><?php echo rm_money($totExp); ?></td></tr></tfoot>
      <?php endif; ?>
    </table>
    <?php
    $body = ob_get_clean();
    rm_report_page($title, $subtitle, $body);
}

ob_start();
?>
<div class="card border-0 shadow-sm" style="border-radius:14px;max-width:560px;"><div class="card-body p-4">
  <h2 class="h6 fw-bold mb-3">Staff report</h2>
  <p class="small text-muted">A printable report of what each staff login (admin, supervisor or cashier) has recorded — one person or all of them.</p>
  <?php if (!$staffUsers): ?>
    <div class="text-muted small py-3 text-center">No staff activity recorded yet.</div>
  <?php else: ?>
  <form method="get" target="_blank">
    <input type="hidden" name="action" value="generate">
    <label class="form-label small">Staff</label>
    <select name="user_id" class="form-select mb-2">
      <option value="0">All staff</option>
      <?php foreach ($staffUsers as $u): ?><option value="<?php echo (int) $u['id']; ?>"><?php echo rm_h($u['username']); ?></option><?php endforeach; ?>
    </select>
    <div class="row g-2">
      <div class="col-6"><label class="form-label small">From</label><input type="date" name="from" value="<?php echo rm_h($from); ?>" class="form-control"></div>
      <div class="col-6"><label class="form-label small">To</label><input type="date" name="to" value="<?php echo rm_h($to); ?>" class="form-control"></div>
    </div>
    <button class="btn btn-primary w-100 mt-3"><i class="fa fa-file-lines me-1"></i>Generate report</button>
  </form>
  <?php endif; ?>
</div></div>
<?php
$content = ob_get_clean();
rm_render('Staff report', $content);
