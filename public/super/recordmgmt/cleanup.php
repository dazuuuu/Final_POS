<?php
// public/super/recordmgmt/cleanup.php — Clean records: shows everything the
// account has actually recorded (employees, services, products, operations,
// payments, expenses) and lets the owner delete the specific entries they
// pick. Employees/services/products already used on an operation are
// skipped (deactivate those instead) to keep history intact. Deleting an
// operation or a payment reverses its effect on the employee's owed balance.
require_once __DIR__ . '/_common.php';
rm_require($isOwner);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $type = (string) ($_POST['type'] ?? '');
    $ids = (array) ($_POST['ids'] ?? []);
    $res = $RM->deleteRecords($type, $ids);
    if ($res['ok']) {
        $msg = $res['removed'] . ' record(s) deleted.';
        if (!empty($res['skipped'])) { $msg .= ' ' . $res['skipped'] . ' skipped (already used on an operation — deactivate them instead).'; }
        $_SESSION['flash']['success'] = $msg;
    } else {
        $_SESSION['flash']['error'] = $res['error'];
    }
    header('Location: ' . public_url('super/recordmgmt/cleanup.php?tab=' . urlencode($type)));
    exit;
}

$records = $RM->allRecords();
$tab = in_array($_GET['tab'] ?? '', \Models\RecordMgmtModel::RECORD_TYPES, true) ? $_GET['tab'] : 'employees';
$labels = [
    'employees' => 'Employees', 'services' => 'Services', 'products' => 'Products',
    'operations' => 'Operations', 'payments' => 'Payments', 'expenses' => 'Expenses',
];

ob_start();
?>
<p class="small text-muted mb-3">Everything your account has recorded, by type. Tick the ones you want gone and delete them — this is for correcting mistakes, not a date sweep. An employee, service or product already used on an operation can't be deleted here (it would break that history); deactivate it instead from its own page.</p>

<ul class="nav nav-pills mb-3">
  <?php foreach ($labels as $k => $l): ?>
    <li class="nav-item"><a class="nav-link <?php echo $tab === $k ? 'active' : ''; ?>" href="?tab=<?php echo $k; ?>"><?php echo $l; ?> (<?php echo count($records[$k]); ?>)</a></li>
  <?php endforeach; ?>
</ul>

<div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
<form method="post" onsubmit="return confirm('Permanently delete the selected <?php echo rm_h($labels[$tab]); ?>? This cannot be undone.');">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="type" value="<?php echo rm_h($tab); ?>">
  <div class="table-responsive"><table class="table align-middle">
    <thead><tr>
      <th style="width:30px;"><input type="checkbox" onclick="this.closest('table').querySelectorAll('.rm-pick').forEach(c=>c.checked=this.checked)"></th>
      <?php if ($tab === 'employees'): ?><th>Name</th><th>Role</th><th>Phone</th><th>Owed</th>
      <?php elseif ($tab === 'services'): ?><th>Name</th><th>Charge</th><th>Description</th>
      <?php elseif ($tab === 'products'): ?><th>Name</th><th>Qty</th><th>Cost</th>
      <?php elseif ($tab === 'operations'): ?><th>Date</th><th>Employee</th><th>Service</th><th>Amount</th><th>Commission</th>
      <?php elseif ($tab === 'payments'): ?><th>Date</th><th>Employee</th><th>Amount</th><th>Method</th>
      <?php else: ?><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($records[$tab] as $r): ?>
      <tr>
        <td><input type="checkbox" class="rm-pick" name="ids[]" value="<?php echo (int) $r['id']; ?>"></td>
        <?php if ($tab === 'employees'): ?>
          <td><?php echo rm_h(rm_name($r)); ?></td><td><?php echo rm_h($r['role']); ?></td><td><?php echo rm_h($r['phone']); ?></td><td><?php echo rm_money($r['owed']); ?></td>
        <?php elseif ($tab === 'services'): ?>
          <td><?php echo rm_h($r['name']); ?></td><td><?php echo $r['charge_type'] === 'percent' ? rm_h($r['amount']) . '%' : rm_money($r['amount']); ?></td><td class="small text-muted"><?php echo rm_h($r['description']); ?></td>
        <?php elseif ($tab === 'products'): ?>
          <td><?php echo rm_h($r['name']); ?></td><td><?php echo rm_h($r['quantity']); ?></td><td><?php echo rm_money($r['cost']); ?></td>
        <?php elseif ($tab === 'operations'): ?>
          <td><?php echo date('j M, H:i', strtotime($r['created_at'])); ?></td><td><?php echo rm_h($r['employee_name']); ?></td><td><?php echo rm_h($r['service_name']); ?></td><td><?php echo rm_money($r['amount']); ?></td><td class="text-success"><?php echo rm_money($r['commission']); ?></td>
        <?php elseif ($tab === 'payments'): ?>
          <td><?php echo date('j M, H:i', strtotime($r['created_at'])); ?></td><td><?php echo rm_h($r['first_name'] . ' ' . $r['last_name']); ?></td><td><?php echo rm_money($r['amount']); ?></td><td><?php echo rm_h(ucfirst($r['method'])); ?></td>
        <?php else: ?>
          <td><?php echo date('j M, H:i', strtotime($r['created_at'])); ?></td><td><?php echo rm_h($r['category_name']); ?></td><td class="small text-muted"><?php echo rm_h($r['description']); ?></td><td><?php echo rm_money($r['amount']); ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$records[$tab]): ?><tr><td colspan="6" class="text-center text-muted py-3">Nothing recorded here yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php if ($records[$tab]): ?><button class="btn btn-danger mt-2">Delete selected</button><?php endif; ?>
</form>
</div></div>
<?php
$content = ob_get_clean();
rm_render('Clean records', $content);
