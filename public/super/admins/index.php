<?php
// public/super/admins/index.php — the PRIMARY owner adds/manages other
// admin accounts for this shop. Only the primary owner (tenants.owner_user_id)
// can reach this page (see PageGuard::primaryOwner()) — an admin created here
// cannot create further admins themselves.
require_once __DIR__ . '/../../../app/app.php';
require_once ROOT_PATH . '/app/services/emails/admin_credentials_email.php';
PageGuard::primaryOwner();

$pdo = Database::pdo();
$tenantId = TenantContext::tenantId();
$tenant = (new Models\TenantModel($pdo))->find($tenantId);
$primaryId = (int) ($tenant['owner_user_id'] ?? 0);
$shopName = $tenant['name'] ?? 'the shop';
$base = public_url('super/admins/');

/** A unique `users.username` derived from the email's local part. */
function admins_unique_username(PDO $pdo, string $email): string
{
    $base = preg_replace('/[^a-z0-9_]+/', '', strtolower(explode('@', $email)[0])) ?: 'admin';
    $name = $base;
    $i = 0;
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ? AND tenant_id = ? LIMIT 1'); // unique per shop
    while (true) {
        $stmt->execute([$name, (int) TenantContext::tenantId()]);
        if (!$stmt->fetchColumn()) {
            return $name;
        }
        $name = $base . (++$i);
    }
}

// What the primary owner can switch on/off for each admin. Stock value
// starts OFF for an admin; everything else (finances included) starts ON.
$adminToggles = [
    ['key' => 'finance',   'label' => 'Finances, profit & reports', 'desc' => 'Sales totals, profit, statements, reports and every person\'s opening/closing sales.', 'caps' => [Capabilities::FINANCE_VIEW, Capabilities::REPORTS_VIEW]],
    ['key' => 'stock_val', 'label' => 'Stock value & buying prices', 'desc' => 'See the value of stock and what products cost you.', 'caps' => [Capabilities::STOCK_VALUE]],
    ['key' => 'sell',      'label' => 'Make sales',                 'desc' => 'Use the POS, held sales and documents.', 'caps' => [Capabilities::SALES_RECORD]],
    ['key' => 'credit',    'label' => 'Credit sales & payments',    'desc' => 'Sell on credit, keep the credit book, take payments and returns.', 'caps' => [Capabilities::CREDIT_SALES, Capabilities::PAYMENTS_PROCESS]],
    ['key' => 'inventory', 'label' => 'Edit inventory & record stock', 'desc' => 'Products, categories, subcategories, suppliers and stock deliveries.', 'caps' => [Capabilities::INVENTORY_EDIT, Capabilities::STOCK_ENTER]],
    ['key' => 'expenses',  'label' => 'Record expenses',            'desc' => 'Log shop expenses.', 'caps' => [Capabilities::EXPENSES_RECORD]],
    ['key' => 'customers', 'label' => 'Customers',                  'desc' => 'Manage the customer list.', 'caps' => [Capabilities::CUSTOMERS_MANAGE]],
    ['key' => 'staff',     'label' => 'Manage staff',               'desc' => 'Add staff, set their permissions and see attendance.', 'caps' => [Capabilities::STAFF_MANAGE]],
    ['key' => 'settings',  'label' => 'Shop settings',              'desc' => 'Shop details, catalogue sharing.', 'caps' => [Capabilities::SETTINGS_MANAGE, Capabilities::CATALOGUE_SEND]],
];
$adminManageable = array_merge(...array_column($adminToggles, 'caps'));
$adminDefaults = array_values(array_diff(Capabilities::allTenantCaps(), Capabilities::adminDefaultOff()));
$adminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE role_name = 'tenant_owner' LIMIT 1")->fetchColumn();

$errors = [];
$success = '';
$mailResult = null;
$mailError = '';
$old = ['name' => '', 'email' => '', 'password' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $old = [
        'name'     => trim($_POST['name'] ?? ''),
        'email'    => trim($_POST['email'] ?? ''),
        'password' => (string) ($_POST['password'] ?? ''),
    ];
    if ($old['name'] === '') {
        $errors[] = "The new admin's name is required.";
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    } else {
        $chk = $pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $chk->execute([$old['email']]);
        if ($chk->fetchColumn()) { $errors[] = 'That email is already registered.'; }
    }
    if (strlen($old['password']) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if (!$errors) {
        $roleId = (int) $pdo->query("SELECT id FROM roles WHERE role_name = 'tenant_owner' LIMIT 1")->fetchColumn();
        $username = admins_unique_username($pdo, $old['email']);
        $pdo->prepare(
            'INSERT INTO users (tenant_id, username, email, password_hash, role_id, is_active, email_verified, must_reset_password)
             VALUES (?,?,?,?,?,1,1,0)'
        )->execute([$tenantId, $username, $old['email'], password_hash($old['password'], PASSWORD_DEFAULT), $roleId]);
        $newId = (int) $pdo->lastInsertId();

        // The new admin confirms their email first; their login details follow once they do.
        $success = 'Admin account created for ' . htmlspecialchars($old['email']) . ' — they confirm their email, then receive their login details.';
        $cf = EmailConfirm::start('user', $newId, $old['email'], $old['name'], $old['password'], EmailConfirm::absolute('admin/'), Features::accountId((int) $tenantId));
        if ($cf['sent']) {
            $mailResult = 'ok';
        } else {
            $mailResult = 'error';
            $mailError = (MailService::lastError() ?: 'Could not send the confirm email.') . ' Send them this link: ' . $cf['link'];
        }
        $old = ['name' => '', 'email' => '', 'password' => ''];
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'perms') {
    $id = (int) ($_POST['id'] ?? 0);
    $chk = $pdo->prepare('SELECT 1 FROM users WHERE id = ? AND tenant_id = ?');
    $chk->execute([$id, $tenantId]);
    if ($id !== $primaryId && $chk->fetchColumn()) {
        $posted = array_keys($_POST['caps'] ?? []);
        $desired = [];
        foreach ($adminToggles as $t) {
            if (in_array($t['key'], $posted, true)) { $desired = array_merge($desired, $t['caps']); }
        }
        (new StaffService($pdo))->setCapabilities($tenantId, $id, $desired, $adminManageable, $adminDefaults);
        $_SESSION['flash']['success'] = 'Admin permissions saved — they apply within a minute.';
    }
    header('Location: ' . $base . '?perms=' . $id); exit;

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id !== $primaryId) {
        $makeActive = ($_POST['make_active'] ?? '') === '1';
        $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ? AND tenant_id = ?')->execute([$makeActive ? 1 : 0, $id, $tenantId]);
        $_SESSION['flash']['success'] = $makeActive ? 'Admin unblocked.' : 'Admin blocked — they can no longer log in.';
    }
    header('Location: ' . $base); exit;

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id !== $primaryId) {
        $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
        $_SESSION['flash']['success'] = 'Admin account deleted.';
    }
    header('Location: ' . $base); exit;
}

$stmt = $pdo->prepare(
    "SELECT u.id, u.username, u.email, u.is_active
       FROM users u JOIN roles r ON r.id = u.role_id
      WHERE u.tenant_id = ? AND r.role_name = 'tenant_owner'
   ORDER BY (u.id = ?) DESC, u.username ASC"
);
$stmt->execute([$tenantId, $primaryId]);
$admins = $stmt->fetchAll();

$permsFor = null;
foreach ($admins as $a) {
    if ((int) $a['id'] === (int) ($_GET['perms'] ?? 0) && (int) $a['id'] !== $primaryId) { $permsFor = $a; }
}
$permsEff = $permsFor ? Capabilities::effective($pdo, (int) $permsFor['id'], $adminRoleId, $tenantId) : [];

$page_title = 'Admins';
ob_start();
?>
<?php if (!empty($_SESSION['flash']['success'])): ?>
  <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['flash']['success']); unset($_SESSION['flash']['success']); ?></div>
<?php endif; ?>
<?php if ($permsFor): ?>
<div class="card border-0 shadow-sm mb-4" style="border-radius:12px;" id="perms">
  <div class="card-body p-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
      <h2 class="h5 mb-0">What <?php echo htmlspecialchars($permsFor['username']); ?> can do</h2>
      <a class="btn btn-sm btn-outline-secondary" href="<?php echo $base; ?>">Close</a>
    </div>
    <p class="text-muted small">Switch off anything this admin shouldn't reach. Only you (the primary owner) can change this, and nobody can restrict you.</p>
    <form method="post">
      <input type="hidden" name="action" value="perms">
      <input type="hidden" name="id" value="<?php echo (int) $permsFor['id']; ?>">
      <div class="row">
      <?php foreach ($adminToggles as $t): $on = !array_diff($t['caps'], $permsEff); ?>
        <div class="col-12 col-md-6">
          <div class="form-check form-switch py-2" style="padding-left:3.2em;">
            <input class="form-check-input mt-1" type="checkbox" role="switch" id="ap_<?php echo $t['key']; ?>" name="caps[<?php echo $t['key']; ?>]" value="1" <?php echo $on ? 'checked' : ''; ?>>
            <label class="form-check-label" for="ap_<?php echo $t['key']; ?>">
              <span class="fw-semibold"><?php echo htmlspecialchars($t['label']); ?></span>
              <span class="d-block small text-muted"><?php echo htmlspecialchars($t['desc']); ?></span>
            </label>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
      <button class="btn btn-primary mt-2"><i class="fas fa-floppy-disk me-1"></i>Save permissions</button>
    </form>
  </div>
</div>
<?php endif; ?>
<div class="row g-4">
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-1">Add an admin</h2>
        <p class="text-muted small mb-3">They'll get access to <?php echo htmlspecialchars($shopName); ?> and their credentials by email. <strong>Stock value starts switched off</strong> — use <em>Permissions</em> next to their name to choose exactly what they can do. They can't add other admins.</p>
        <?php if ($errors): ?><div class="alert alert-danger py-2"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success py-2"><?php echo $success; ?></div><?php endif; ?>
        <?php if ($mailResult === 'ok'): ?><div class="alert alert-success py-2">✓ Credentials email sent.</div>
        <?php elseif ($mailResult === 'error'): ?><div class="alert alert-warning py-2">Account created, but the email failed: <?php echo htmlspecialchars($mailError); ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
          <input type="hidden" name="action" value="create">
          <div class="mb-3">
            <label class="form-label">Full name</label>
            <input name="name" class="form-control" value="<?php echo htmlspecialchars($old['name']); ?>" required autofocus>
          </div>
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input name="email" type="email" class="form-control" value="<?php echo htmlspecialchars($old['email']); ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Password</label>
            <input name="password" type="text" class="form-control" minlength="8" placeholder="Min. 8 characters" autocomplete="off" required>
            <small class="text-muted">Shown as plain text so you can confirm it — it's hashed before storing, and emailed to them.</small>
          </div>
          <button class="btn btn-primary">Create admin</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-3">Admins <span class="badge bg-light text-dark"><?php echo count($admins); ?></span></h2>
        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead><tr class="text-muted small text-uppercase"><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($admins as $a): $isPrimary = (int) $a['id'] === $primaryId; ?>
              <tr>
                <td class="fw-semibold"><?php echo htmlspecialchars($a['username']); ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($a['email']); ?></td>
                <td><?php echo $isPrimary ? '<span class="badge bg-primary">Primary</span>' : '<span class="badge bg-light text-dark">Admin</span>'; ?></td>
                <td>
                  <?php if ((int) $a['is_active'] === 1): ?><span class="badge bg-success">Active</span>
                  <?php else: ?><span class="badge bg-secondary">Blocked</span>
                  <?php endif; ?>
                </td>
                <td class="text-end" style="white-space:nowrap;">
                  <?php if (!$isPrimary): ?>
                    <a class="btn btn-sm btn-outline-primary" href="?perms=<?php echo (int) $a['id']; ?>#perms"><i class="fas fa-key me-1"></i>Permissions</a>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="toggle_active">
                      <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                      <input type="hidden" name="make_active" value="<?php echo (int) $a['is_active'] === 1 ? '0' : '1'; ?>">
                      <button class="btn btn-sm btn-outline-<?php echo (int) $a['is_active'] === 1 ? 'warning' : 'success'; ?>"><?php echo (int) $a['is_active'] === 1 ? 'Block' : 'Unblock'; ?></button>
                    </form>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete <?php echo htmlspecialchars($a['username'], ENT_QUOTES); ?>? This can\'t be undone.');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                      <button class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
