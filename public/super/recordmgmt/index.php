<?php
// public/super/recordmgmt/index.php — Operations: every employee as a card;
// click one to record the service they just performed. Below, today's log
// (visible to the owner/cashier/supervisor; an employee's own total shows
// on their card).
require_once __DIR__ . '/_common.php';
rm_require($canOperate);

$employees = $RM->employees();
$today = date('Y-m-d');
$ops = $RM->operations($today, $today);
$earnedToday = [];
foreach ($ops as $o) {
    $earnedToday[$o['employee_id']] = ($earnedToday[$o['employee_id']] ?? 0) + (float) $o['net_amount'];
}

ob_start();
?>
<?php $egroups = rm_alpha_groups($employees, 'rm_name'); ?>
<?php foreach ($egroups as $letter => $rows): ?>
<div class="small fw-bold text-uppercase text-muted mb-2"><?php echo rm_h($letter); ?></div>
<div class="row g-3 mb-4">
  <?php foreach ($rows as $e): ?>
    <div class="col-6 col-md-4 col-lg-3">
      <a href="<?php echo public_url('super/recordmgmt/record.php?employee_id=' . (int) $e['id']); ?>" class="text-decoration-none">
        <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
          <div class="card-body p-3 text-center">
            <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center mb-2" style="width:48px;height:48px;font-weight:700;">
              <?php echo rm_h(mb_substr($e['first_name'], 0, 1)); ?>
            </div>
            <div class="fw-semibold text-dark"><?php echo rm_h(rm_name($e)); ?></div>
            <div class="small text-muted"><?php echo rm_h($e['role']); ?></div>
            <div class="small text-success mt-1">Today: <?php echo rm_money($earnedToday[$e['id']] ?? 0); ?></div>
          </div>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php if (!$employees): ?>
  <div class="text-center text-muted py-4">No employees yet. Add them from <a href="<?php echo public_url('super/recordmgmt/employees.php'); ?>">Employees</a>.</div>
<?php endif; ?>

<div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
  <h2 class="h6 fw-bold mb-3">Today's operations</h2>
  <div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>Time</th><th>Employee</th><th>Service</th><th>Amount</th><th>Mode</th><th>Commission</th></tr></thead>
    <tbody>
    <?php foreach ($ops as $o): ?>
      <tr>
        <td><?php echo date('H:i', strtotime($o['created_at'])); ?></td>
        <td><?php echo rm_h($o['first_name'] . ' ' . $o['last_name']); ?></td>
        <td><?php echo rm_h($o['service_name']); ?><?php if ($o['product_cost'] > 0): ?><div class="small text-muted">− <?php echo rm_money($o['product_cost']); ?> products</div><?php endif; ?></td>
        <td><?php echo rm_money($o['amount']); ?></td>
        <td><?php echo $o['payment_mode'] === 'mpesa' ? 'M-Pesa (' . rm_h($o['mpesa_code']) . ')' : 'Cash'; ?></td>
        <td class="text-success fw-semibold"><?php echo rm_money($o['commission']); ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ops): ?><tr><td colspan="6" class="text-center text-muted py-3">Nothing recorded yet today.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div></div>
<?php
$content = ob_get_clean();
rm_render('Operations', $content);
