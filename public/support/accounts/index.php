<?php
// public/support/accounts/index.php — every shop-owner account, plus "create account".
// Support only sees account-level info (owner, shops, packages) — never the
// shop's staff, sales or stock.
require_once __DIR__ . '/../_boot.php';

$pdo = Database::pdo();
$catalog = Features::catalog();
$errors = [];
$in = ['business_name' => '', 'username' => '', 'email' => '', 'phone' => '', 'packages' => [Features::SHOP_POS]];

if (support_posted()) {
    $in = [
        'business_name' => trim($_POST['business_name'] ?? ''),
        'username'      => trim($_POST['username'] ?? ''),
        'email'         => strtolower(trim($_POST['email'] ?? '')),
        'phone'         => trim($_POST['phone'] ?? ''),
        'packages'      => array_values(array_intersect((array) ($_POST['packages'] ?? []), array_keys($catalog))),
    ];
    $password = $_POST['password'] ?? '';

    if ($in['business_name'] === '') $errors[] = 'Business name is required.';
    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $in['username'])) $errors[] = 'Username must be 3–50 letters, numbers, dots, dashes or underscores.';
    if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid owner email.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if (!$errors) {
        $chk = $pdo->prepare('SELECT SUM(email = ?), SUM(username = ?) FROM users');
        $chk->execute([$in['email'], $in['username']]);
        [$emailTaken, $userTaken] = $chk->fetch(PDO::FETCH_NUM);
        if ($emailTaken) $errors[] = 'That email already has an account.';
        if ($userTaken) $errors[] = 'That username is taken.';
    }
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE role_name = 'tenant_owner' LIMIT 1")->fetchColumn();
    if (!$roleId) $errors[] = 'tenant_owner role is missing from the roles table.';

    if (!$errors) {
        $T = new Models\TenantModel($pdo);
        $pdo->beginTransaction();
        try {
            $tenantId = $T->create($in['business_name'], $T->uniqueSlug($in['business_name']));
            if ($in['phone'] !== '') {
                $pdo->prepare('UPDATE tenants SET phone = ? WHERE id = ?')->execute([$in['phone'], $tenantId]);
            }
            $pdo->prepare(
                'INSERT INTO users (tenant_id, username, email, password_hash, role_id, is_active, email_verified, activated_at)
                 VALUES (?, ?, ?, ?, ?, 1, 1, NOW())'
            )->execute([$tenantId, $in['username'], $in['email'], password_hash($password, PASSWORD_DEFAULT), $roleId]);
            $T->setOwner($tenantId, (int) $pdo->lastInsertId());
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Could not create the account: ' . $e->getMessage();
        }
        if (!$errors) {
            Features::setForAccount($tenantId, $in['packages']);
            support_flash('Account "' . $in['business_name'] . '" created. The owner can sign in at /auth/login.php with ' . $in['email'] . '.');
            header('Location: ' . public_url('support/accounts/view.php?id=' . $tenantId));
            exit;
        }
    }
}

$accounts = support_accounts($pdo);
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $accounts = array_filter($accounts, fn($a) => stripos($a['name'] . ' ' . $a['owner_email'] . ' ' . $a['owner_username'], $q) !== false);
}
$showForm = $errors || !empty($_GET['new']);

$page_title = 'Accounts';
$nav = 'accounts';
ob_start();
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <h1 class="h4 mb-0">Accounts</h1>
  <div class="d-flex gap-2">
    <form class="d-flex" method="get"><input class="form-control form-control-sm" name="q" placeholder="Search name or email" value="<?php echo htmlspecialchars($q); ?>"></form>
    <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#newAccount"><i class="fas fa-plus me-1"></i>New account</button>
  </div>
</div>

<div class="collapse <?php echo $showForm ? 'show' : ''; ?> mb-4" id="newAccount">
  <div class="s-card">
    <h2 class="h6 mb-3">Register a shop owner</h2>
    <?php if ($errors): ?><div class="alert alert-danger"><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?php echo support_csrf_field(); ?>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Business name</label><input class="form-control" name="business_name" value="<?php echo htmlspecialchars($in['business_name']); ?>" required></div>
        <div class="col-md-6"><label class="form-label">Business phone <small class="text-muted">(optional)</small></label><input class="form-control" name="phone" value="<?php echo htmlspecialchars($in['phone']); ?>"></div>
        <div class="col-md-4"><label class="form-label">Owner username</label><input class="form-control" name="username" value="<?php echo htmlspecialchars($in['username']); ?>" required></div>
        <div class="col-md-4"><label class="form-label">Owner email (login)</label><input class="form-control" type="email" name="email" value="<?php echo htmlspecialchars($in['email']); ?>" required></div>
        <div class="col-md-4"><label class="form-label">Initial password</label><input class="form-control" type="text" name="password" minlength="8" required></div>
        <div class="col-12">
          <label class="form-label d-block">Packages</label>
          <?php foreach ($catalog as $k => [$label, $price, , $icon]): ?>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="packages[]" value="<?php echo $k; ?>" id="p_<?php echo $k; ?>" <?php echo in_array($k, $in['packages'], true) ? 'checked' : ''; ?>>
              <label class="form-check-label" for="p_<?php echo $k; ?>"><i class="fas <?php echo $icon; ?> me-1"></i><?php echo $label; ?> · KES <?php echo number_format($price / 1000); ?>K</label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <button class="btn btn-primary mt-3"><i class="fas fa-user-plus me-1"></i>Create account</button>
    </form>
  </div>
</div>

<div class="s-card p-0">
  <div class="table-responsive">
    <table class="table mb-0">
      <thead class="table-light"><tr><th>Business</th><th>Owner</th><th>Shops</th><th>Packages</th><th>Status</th><th>Created</th><th></th></tr></thead>
      <tbody>
      <?php if (!$accounts): ?><tr><td colspan="7" class="text-center text-muted py-4">No accounts found.</td></tr><?php endif; ?>
      <?php foreach ($accounts as $a): ?>
        <tr>
          <td><strong><?php echo htmlspecialchars($a['name']); ?></strong></td>
          <td><?php echo htmlspecialchars($a['owner_username'] ?? '—'); ?><br><small class="text-muted"><?php echo htmlspecialchars($a['owner_email'] ?? ''); ?></small></td>
          <td><?php echo 1 + (int) $a['branch_count']; ?></td>
          <td><?php echo support_package_badges((int) $a['id']); ?></td>
          <td><?php echo support_status_badge($a['status']); ?></td>
          <td><small><?php echo date('d M Y', strtotime($a['created_at'])); ?></small></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?php echo public_url('support/accounts/view.php?id=' . (int) $a['id']); ?>">Manage</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../_layout.php';
