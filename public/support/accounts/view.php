<?php
// public/support/accounts/view.php — manage one account: packages, status,
// business details, owner login. Account-level only (no staff/sales/stock).
require_once __DIR__ . '/../_boot.php';

$pdo = Database::pdo();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT t.*, u.id AS owner_id, u.username AS owner_username, u.email AS owner_email
       FROM tenants t LEFT JOIN users u ON u.id = t.owner_user_id
      WHERE t.id = ? AND t.parent_tenant_id IS NULL'
);
$stmt->execute([$id]);
$acct = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$acct) {
    support_flash('Account not found.', 'danger');
    header('Location: ' . public_url('support/accounts/'));
    exit;
}
$self = public_url('support/accounts/view.php?id=' . $id);
$catalog = Features::catalog();

if (support_posted()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'package' && isset($catalog[$_POST['package'] ?? ''])) {
        $on = ($_POST['enabled'] ?? '') === '1';
        Features::set($id, $_POST['package'], $on);
        support_flash($catalog[$_POST['package']][0] . ($on ? ' switched ON' : ' switched OFF') . ' for ' . $acct['name'] . '.');
    } elseif ($action === 'multi_shop') {
        $on = ($_POST['enabled'] ?? '') === '1';
        Features::setMultiShop($id, $on);
        support_flash('Multiple shops ' . ($on ? 'enabled' : 'disabled') . ' for ' . $acct['name'] . '.');
    } elseif ($action === 'status' && in_array($_POST['status'] ?? '', ['active', 'suspended', 'cancelled'], true)) {
        $pdo->prepare('UPDATE tenants SET status = ? WHERE id = ? OR parent_tenant_id = ?')->execute([$_POST['status'], $id, $id]);
        support_flash('Account is now ' . $_POST['status'] . '.');
    } elseif ($action === 'details') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            support_flash('Business name cannot be empty.', 'danger');
        } else {
            $pdo->prepare('UPDATE tenants SET name = ?, phone = ? WHERE id = ?')->execute([$name, trim($_POST['phone'] ?? ''), $id]);
            support_flash('Business details saved.');
        }
    } elseif ($action === 'owner' && $acct['owner_id']) {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pw = $_POST['password'] ?? '';
        $dupe = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
        $dupe->execute([$email, $acct['owner_id']]);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            support_flash('Enter a valid email.', 'danger');
        } elseif ($dupe->fetchColumn()) {
            support_flash('Another user already uses that email.', 'danger');
        } elseif ($pw !== '' && strlen($pw) < 8) {
            support_flash('Password must be at least 8 characters.', 'danger');
        } else {
            $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $acct['owner_id']]);
            if ($pw !== '') {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $acct['owner_id']]);
            }
            support_flash('Owner login updated' . ($pw !== '' ? ' (new password set).' : '.'));
        }
    }
    header('Location: ' . $self);
    exit;
}

$packages = Features::forAccount($id);
$shops = $pdo->prepare('SELECT name, status, created_at FROM tenants WHERE id = ? OR parent_tenant_id = ? ORDER BY parent_tenant_id IS NOT NULL, created_at');
$shops->execute([$id, $id]);
$shops = $shops->fetchAll(PDO::FETCH_ASSOC);
$monthly = 0;
foreach ($packages as $k => $on) { if ($on) $monthly += $catalog[$k][1]; }

$page_title = $acct['name'];
$nav = 'accounts';
ob_start();
?>
<a class="small text-decoration-none" href="<?php echo public_url('support/accounts/'); ?>"><i class="fas fa-arrow-left me-1"></i>All accounts</a>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2 mb-4">
  <h1 class="h4 mb-0"><?php echo htmlspecialchars($acct['name']); ?> <?php echo support_status_badge($acct['status']); ?></h1>
  <span class="text-muted small">Account #<?php echo $id; ?> · since <?php echo date('d M Y', strtotime($acct['created_at'])); ?></span>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="s-card mb-3">
      <div class="d-flex justify-content-between mb-3"><h2 class="h6 mb-0">Packages</h2><span class="small text-muted">Licensed: <strong>KES <?php echo number_format($monthly); ?></strong></span></div>
      <?php foreach ($catalog as $k => [$label, $price, $desc, $icon]): $on = $packages[$k]; ?>
        <div class="d-flex justify-content-between align-items-start gap-3 border-bottom py-3">
          <div>
            <strong><i class="fas <?php echo $icon; ?> fa-fw me-1 text-primary"></i><?php echo $label; ?></strong>
            <small class="text-muted">· KES <?php echo number_format($price / 1000); ?>K</small>
            <div class="small text-muted mt-1"><?php echo htmlspecialchars($desc); ?></div>
          </div>
          <form method="post" class="flex-shrink-0" <?php if ($on): ?>onsubmit="return confirm('Turn off <?php echo $label; ?> for this account? Its pages disappear for the owner and staff.');"<?php endif; ?>>
            <?php echo support_csrf_field(); ?>
            <input type="hidden" name="action" value="package"><input type="hidden" name="package" value="<?php echo $k; ?>">
            <input type="hidden" name="enabled" value="<?php echo $on ? '0' : '1'; ?>">
            <button class="btn btn-sm <?php echo $on ? 'btn-success' : 'btn-outline-secondary'; ?>" style="min-width:90px;">
              <i class="fas <?php echo $on ? 'fa-toggle-on' : 'fa-toggle-off'; ?> me-1"></i><?php echo $on ? 'ON' : 'OFF'; ?>
            </button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="s-card">
      <?php $multi = Features::multiShop($id); ?>
      <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
        <div>
          <h2 class="h6 mb-1">Shops (<?php echo count($shops); ?>)</h2>
          <div class="small text-muted"><?php echo $multi ? 'Multiple shops: the owner can open branch shops.' : 'Single shop: the owner runs everything from the main shop and cannot open branches.'; ?></div>
        </div>
        <form method="post" class="flex-shrink-0" <?php if ($multi && count($shops) > 1): ?>onsubmit="return confirm('Turn off multiple shops? Existing branches keep working, but no new ones can be opened.');"<?php endif; ?>>
          <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="multi_shop"><input type="hidden" name="enabled" value="<?php echo $multi ? '0' : '1'; ?>">
          <button class="btn btn-sm <?php echo $multi ? 'btn-success' : 'btn-outline-secondary'; ?>"><?php echo $multi ? 'Multi-shop ON' : 'Multi-shop OFF'; ?></button>
        </form>
      </div>
      <?php foreach ($shops as $i => $s): ?>
        <div class="d-flex justify-content-between border-bottom py-2">
          <span><?php echo htmlspecialchars($s['name']); ?> <?php if ($i === 0): ?><span class="badge bg-light text-dark">Main</span><?php endif; ?></span>
          <span><?php echo support_status_badge($s['status']); ?></span>
        </div>
      <?php endforeach; ?>
      <p class="small text-muted mt-2 mb-0">Branch shops are opened by the owner and share the account's packages.</p>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="s-card mb-3">
      <h2 class="h6 mb-3">Business details</h2>
      <form method="post">
        <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="details">
        <div class="mb-2"><label class="form-label small">Business name</label><input class="form-control" name="name" value="<?php echo htmlspecialchars($acct['name']); ?>"></div>
        <div class="mb-3"><label class="form-label small">Phone</label><input class="form-control" name="phone" value="<?php echo htmlspecialchars($acct['phone'] ?? ''); ?>"></div>
        <button class="btn btn-sm btn-primary">Save</button>
      </form>
    </div>

    <div class="s-card mb-3">
      <h2 class="h6 mb-3">Owner login</h2>
      <?php if ($acct['owner_id']): ?>
      <form method="post" autocomplete="off">
        <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="owner">
        <div class="mb-2"><label class="form-label small">Username</label><input class="form-control" value="<?php echo htmlspecialchars($acct['owner_username']); ?>" disabled></div>
        <div class="mb-2"><label class="form-label small">Email</label><input class="form-control" type="email" name="email" value="<?php echo htmlspecialchars($acct['owner_email']); ?>"></div>
        <div class="mb-3"><label class="form-label small">New password <small class="text-muted">(leave blank to keep)</small></label><input class="form-control" type="text" name="password" minlength="8"></div>
        <button class="btn btn-sm btn-primary">Update owner</button>
      </form>
      <?php else: ?><p class="text-muted small mb-0">This account has no owner user.</p><?php endif; ?>
    </div>

    <div class="s-card">
      <h2 class="h6 mb-2">Account status</h2>
      <p class="small text-muted">Suspended or cancelled accounts are signed out and can't use any shop.</p>
      <form method="post" class="d-flex gap-2" onsubmit="return confirm('Change this account\'s status?');">
        <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="status">
        <select class="form-select form-select-sm" name="status">
          <?php foreach (['active', 'suspended', 'cancelled'] as $st): ?>
            <option value="<?php echo $st; ?>" <?php echo $acct['status'] === $st ? 'selected' : ''; ?>><?php echo ucfirst($st); ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-danger">Apply</button>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../_layout.php';
