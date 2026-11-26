<?php
// public/super/recordmgmt/employees.php — record employees: names, phone,
// optional ID number, and a role (barber, saloon, stylist, cashier,
// supervisor, manager by default — more can be added). This does not create
// a login; only admin, supervisor and cashier get accounts, from Staff.
require_once __DIR__ . '/_common.php';
rm_require($canManage);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $res = $RM->saveEmployee($_POST, (int) ($_POST['id'] ?? 0) ?: null);
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Employee saved.' : $res['error'];
    } elseif ($action === 'deactivate') {
        $RM->setEmployeeActive((int) ($_POST['id'] ?? 0), false);
        $_SESSION['flash']['success'] = 'Employee deactivated.';
    } elseif ($action === 'activate') {
        $RM->setEmployeeActive((int) ($_POST['id'] ?? 0), true);
        $_SESSION['flash']['success'] = 'Employee reactivated.';
    } elseif ($action === 'add_role') {
        $RM->addRole((string) ($_POST['role_name'] ?? ''));
        $_SESSION['flash']['success'] = 'Role added.';
    }
    header('Location: ' . public_url('super/recordmgmt/employees.php'));
    exit;
}

$employees = $RM->employees(false);
$roles = $RM->roles();

ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Add employee</h2>
      <form method="post">
        <input type="hidden" name="action" value="save">
        <div class="row g-2">
          <div class="col-4"><label class="form-label small">First name</label><input name="first_name" class="form-control" required></div>
          <div class="col-4"><label class="form-label small">Middle name</label><input name="middle_name" class="form-control"></div>
          <div class="col-4"><label class="form-label small">Last name</label><input name="last_name" class="form-control"></div>
        </div>
        <div class="row g-2 mt-1">
          <div class="col-6"><label class="form-label small">Phone number</label><input name="phone" class="form-control" required></div>
          <div class="col-6"><label class="form-label small">ID No. (optional)</label><input name="id_no" class="form-control"></div>
        </div>
        <div class="mt-2">
          <label class="form-label small">Role</label>
          <select name="role" class="form-select" required>
            <?php foreach ($roles as $r): ?><option value="<?php echo rm_h($r['name']); ?>"><?php echo rm_h($r['name']); ?></option><?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-primary w-100 mt-3">Save employee</button>
      </form>
    </div></div>

    <div class="card border-0 shadow-sm mt-3" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-2">Add a role</h2>
      <form method="post" class="d-flex gap-2">
        <input type="hidden" name="action" value="add_role">
        <input name="role_name" class="form-control" placeholder="e.g. Nail tech" required>
        <button class="btn btn-outline-primary">Add</button>
      </form>
      <div class="small text-muted mt-2">Current roles: <?php echo rm_h(implode(', ', array_column($roles, 'name'))); ?></div>
    </div></div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Employees (<?php echo count($employees); ?>)</h2>
      <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Name</th><th>Role</th><th>Phone</th><th>Owed</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($employees as $e): ?>
          <tr class="<?php echo $e['active'] ? '' : 'text-muted'; ?>">
            <td><?php echo rm_h(rm_name($e)); ?><?php if (!$e['active']): ?> <span class="badge bg-secondary">Inactive</span><?php endif; ?></td>
            <td><?php echo rm_h($e['role']); ?></td>
            <td><?php echo rm_h($e['phone']); ?></td>
            <td><?php echo rm_money($e['owed']); ?></td>
            <td class="text-end">
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="<?php echo $e['active'] ? 'deactivate' : 'activate'; ?>">
                <input type="hidden" name="id" value="<?php echo (int) $e['id']; ?>">
                <button class="btn btn-sm btn-link text-decoration-none"><?php echo $e['active'] ? 'Deactivate' : 'Reactivate'; ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$employees): ?><tr><td colspan="5" class="text-center text-muted py-3">No employees yet.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div></div>
  </div>
</div>
<?php
$content = ob_get_clean();
rm_render('Employees', $content);
