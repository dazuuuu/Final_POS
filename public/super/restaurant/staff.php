<?php
// public/super/restaurant/staff.php — the owner's waiters / cashiers.
// Staff log in on the PIN screen (clock in, then log in) and land on the
// order screen. By default they can place, hold and print orders; "Can take
// payments" adds the Payments page (payments.process).
require_once __DIR__ . '/../../../app/app.php';
define('REST_CAP', Capabilities::STAFF_MANAGE);
require_once __DIR__ . '/_common.php';

$self = public_url('super/restaurant/staff.php');
$svc = new StaffService($pdo);
$roleDefaults = $svc->roleDefaultCaps('staff');
$positions = ['Waiter', 'Cashier', 'Chef', 'Manager'];
$errors = [];
$old = ['name' => '', 'position' => 'Waiter', 'pin' => '', 'payments' => false];

function rest_set_payments(StaffService $svc, int $tid, int $uid, bool $on, array $roleDefaults): void
{
    $caps = $on ? [Capabilities::PAYMENTS_PROCESS] : [];
    $svc->setCapabilities($tid, $uid, $caps, [Capabilities::PAYMENTS_PROCESS], $roleDefaults);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $action = $_POST['action'] ?? 'create';
    $uid = (int) ($_POST['id'] ?? 0);

    if ($action === 'create') {
        $old = [
            'name'     => trim($_POST['name'] ?? ''),
            'position' => in_array($_POST['position'] ?? '', $positions, true) ? $_POST['position'] : 'Waiter',
            'pin'      => trim($_POST['pin'] ?? ''),
            'payments' => !empty($_POST['payments']),
        ];
        $res = $svc->createWithPin($tid, $old);
        if ($res['ok']) {
            rest_set_payments($svc, $tid, $res['user_id'], $old['payments'], $roleDefaults);
            rest_flash($old['name'] . ' added. They log in on the PIN screen with PIN ' . $old['pin'] . '.');
            header('Location: ' . $self);
            exit;
        }
        $errors = $res['errors'];
    } elseif ($svc->findStaff($tid, $uid)) {
        if ($action === 'payments') {
            rest_set_payments($svc, $tid, $uid, ($_POST['on'] ?? '') === '1', $roleDefaults);
            rest_flash('Payment access updated. It applies the next time they log in.');
        } elseif ($action === 'active') {
            $svc->setActive($tid, $uid, ($_POST['on'] ?? '') === '1');
            rest_flash(($_POST['on'] ?? '') === '1' ? 'Staff member unblocked.' : 'Staff member blocked — they can no longer log in.');
        } elseif ($action === 'pin') {
            $r = $svc->setPin($tid, $uid, trim($_POST['pin'] ?? ''));
            rest_flash($r['ok'] ? 'PIN changed.' : $r['error'], $r['ok'] ? 'success' : 'error');
        } elseif ($action === 'delete') {
            $svc->deleteStaff($tid, $uid);
            rest_flash('Staff member removed. Their past orders are kept.');
        }
        header('Location: ' . $self);
        exit;
    }
}

$staff = $svc->listForTenant($tid);
$stats = [];
$stmt = $pdo->prepare("SELECT user_id, COUNT(*) AS orders,
                              SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END) AS sales,
                              SUM(status IN ('open','held')) AS unpaid
                         FROM rest_orders WHERE tenant_id = ? AND DATE(created_at) = CURDATE() AND status <> 'cancelled' GROUP BY user_id");
$stmt->execute([$tid]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $stats[(int) $r['user_id']] = $r; }
$canPay = [];
foreach ($staff as $s) {
    $canPay[(int) $s['id']] = in_array(Capabilities::PAYMENTS_PROCESS, $svc->effectiveCaps((int) $s['id'], (int) $svc->staffRoleId()), true);
}

ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-4">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-1">Add a waiter or staff</h2>
        <p class="text-muted small mb-3">They log in on the staff PIN screen. They can place, hold and print orders, and only see their own orders.</p>
        <?php if ($errors): ?><div class="alert alert-danger py-2"><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
          <?php echo csrf_field(); ?>
          <div class="mb-3"><label class="form-label">Name</label><input name="name" class="form-control" placeholder="e.g. Mary" value="<?php echo htmlspecialchars($old['name']); ?>" required></div>
          <div class="mb-3"><label class="form-label">Role</label>
            <select name="position" class="form-select"><?php foreach ($positions as $p): ?><option <?php echo $old['position'] === $p ? 'selected' : ''; ?>><?php echo $p; ?></option><?php endforeach; ?></select></div>
          <div class="mb-3"><label class="form-label">Login PIN</label><input name="pin" class="form-control" inputmode="numeric" pattern="\d{4,6}" maxlength="6" placeholder="4–6 digits" value="<?php echo htmlspecialchars($old['pin']); ?>" required></div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="payments" id="payCk" value="1" <?php echo $old['payments'] ? 'checked' : ''; ?>>
            <label class="form-check-label" for="payCk">Can take payments <span class="text-muted small">(cashier)</span></label>
          </div>
          <button class="btn btn-primary">Add staff</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2 class="h5 mb-0">Your staff <span class="badge bg-light text-dark"><?php echo count($staff); ?></span></h2>
          <a class="btn btn-sm btn-outline-secondary" href="<?php echo public_url('super/restaurant/orders.php'); ?>"><i class="fas fa-chart-line me-1"></i>Sales by waiter</a>
        </div>
        <?php if (!$staff): ?><div class="text-muted">No staff yet. Add your first waiter on the left.</div><?php endif; ?>
        <?php foreach ($staff as $s): $id = (int) $s['id']; $st = $stats[$id] ?? ['orders' => 0, 'sales' => 0, 'unpaid' => 0]; ?>
          <div class="border rounded-3 p-3 mb-2 <?php echo $s['is_active'] ? '' : 'bg-light'; ?>">
            <div class="d-flex flex-wrap justify-content-between gap-2">
              <div>
                <strong><?php echo htmlspecialchars($s['username']); ?></strong>
                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($s['position'] ?? 'Staff'); ?></span>
                <?php if ($canPay[$id]): ?><span class="badge bg-success">Takes payments</span><?php endif; ?>
                <?php if (!$s['is_active']): ?><span class="badge bg-danger">Blocked</span><?php endif; ?>
                <div class="small text-muted mt-1">Today: <?php echo (int) $st['orders']; ?> orders · <?php echo $currency . ' ' . number_format((float) $st['sales'], 2); ?> paid<?php if ((int) $st['unpaid']): ?> · <span class="text-warning"><?php echo (int) $st['unpaid']; ?> unpaid</span><?php endif; ?></div>
              </div>
              <div class="d-flex flex-wrap gap-1 align-items-start">
                <a class="btn btn-sm btn-outline-secondary" href="<?php echo public_url('super/restaurant/orders.php?waiter=' . $id); ?>">Orders</a>
                <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="payments"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="on" value="<?php echo $canPay[$id] ? '0' : '1'; ?>">
                  <button class="btn btn-sm <?php echo $canPay[$id] ? 'btn-outline-success' : 'btn-outline-secondary'; ?>"><?php echo $canPay[$id] ? 'Stop payments' : 'Allow payments'; ?></button></form>
                <form method="post" class="d-flex gap-1"><?php echo csrf_field(); ?><input type="hidden" name="action" value="pin"><input type="hidden" name="id" value="<?php echo $id; ?>">
                  <input name="pin" class="form-control form-control-sm" style="width:90px;" inputmode="numeric" maxlength="6" placeholder="New PIN" required>
                  <button class="btn btn-sm btn-outline-secondary" title="Change PIN"><i class="fas fa-key"></i></button></form>
                <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="active"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="on" value="<?php echo $s['is_active'] ? '0' : '1'; ?>">
                  <button class="btn btn-sm btn-outline-warning" title="<?php echo $s['is_active'] ? 'Block' : 'Unblock'; ?>"><i class="fas <?php echo $s['is_active'] ? 'fa-ban' : 'fa-unlock'; ?>"></i></button></form>
                <form method="post" onsubmit="return confirm('Remove <?php echo htmlspecialchars(addslashes($s['username'])); ?>? Their past orders are kept.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $id; ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button></form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php
rest_render('Waiters & staff', ob_get_clean());
