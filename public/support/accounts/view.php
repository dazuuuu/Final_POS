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
    } elseif ($action === 'license_email') {
        $to = strtolower(trim($_POST['to'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            support_flash('Enter a valid email to send the key to.', 'danger');
        } else {
            $err = License::emailKey($id, $to);
            support_flash($err === null ? 'Activation key emailed to ' . $to . '.' : 'Email failed: ' . $err, $err === null ? 'success' : 'danger');
        }
    } elseif ($action === 'license_regenerate') {
        $key = License::issue($id);
        support_flash('New activation key: ' . $key . '. The old key no longer works.');
    } elseif ($action === 'waive') {
        $n = License::waive($id);
        support_flash('Activation waived: the account never locks and ' . ($n ? "$n login" . ($n === 1 ? ' was' : 's were') . ' switched on' : 'all logins were already on') . ' — they can sign in now.');
    } elseif ($action === 'activate_user') {
        $chk = $pdo->prepare('SELECT u.username FROM users u JOIN tenants t ON t.id = u.tenant_id WHERE u.id = ? AND (t.id = ? OR t.parent_tenant_id = ?)');
        $chk->execute([(int) ($_POST['user'] ?? 0), $id, $id]);
        if (($uname = $chk->fetchColumn()) !== false) {
            License::activateUser((int) $_POST['user']);
            support_flash("$uname can sign in now.");
        }
    } elseif ($action === 'new_owner') {
        // A brand-new owner login next to the existing one(s) — e.g. when the
        // imported owner's old password isn't known. The existing owner stays.
        $username = trim($_POST['username'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pw = $_POST['password'] ?? '';
        $taken = $pdo->prepare('SELECT username, email FROM users WHERE email = ? OR username = ? LIMIT 1');
        $taken->execute([$email, $username]);
        $hit = $taken->fetch(PDO::FETCH_ASSOC);
        if (!preg_match('/^[A-Za-z0-9_.@-]{3,50}$/', $username)) {
            support_flash('Username must be 3–50 letters, numbers, dots, dashes or underscores.', 'danger');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            support_flash('Enter a valid email.', 'danger');
        } elseif (strlen($pw) < 8) {
            support_flash('Password must be at least 8 characters.', 'danger');
        } elseif ($hit) {
            support_flash("That " . ($hit['email'] === $email ? 'email' : 'username') . ' is already used. Pick another one for the new owner.', 'danger');
        } else {
            $roleId = (int) $pdo->query("SELECT id FROM roles WHERE role_name = 'tenant_owner'")->fetchColumn();
            $pdo->prepare(
                'INSERT INTO users (tenant_id, username, email, password_hash, role_id, is_active, email_verified, activated_at)
                 VALUES (?, ?, ?, ?, ?, 1, 1, NOW())'
            )->execute([$id, $username, $email, password_hash($pw, PASSWORD_DEFAULT), $roleId]);
            $newId = (int) $pdo->lastInsertId();
            $main = !empty($_POST['main']) || empty($acct['owner_id']);
            if ($main) {
                $pdo->prepare('UPDATE tenants SET owner_user_id = ?, signup_completed_at = COALESCE(signup_completed_at, NOW()) WHERE id = ?')->execute([$newId, $id]);
            }
            License::forceActivate($id);
            support_flash("New owner $username created — sign in at /auth/login.php with $email and the password you set."
                . ($main ? ' It is now the main owner' . ($acct['owner_id'] ? " ({$acct['owner_username']} still works too)." : '.') : ' The main owner is unchanged.'));
        }
    } elseif ($action === 'delete_account') {
        if (trim($_POST['confirm_name'] ?? '') !== $acct['name']) {
            support_flash('Type the business name exactly ("' . $acct['name'] . '") to delete the account.', 'danger');
        } else {
            try {
                $done = AccountDeletion::delete($id);
                support_flash('Account "' . $acct['name'] . '" was deleted permanently (' . number_format(array_sum($done)) . ' records removed).');
                header('Location: ' . public_url('support/accounts/'));
                exit;
            } catch (Throwable $e) {
                support_flash('Could not delete the account: ' . $e->getMessage(), 'danger');
            }
        }
    } elseif ($action === 'license_activate') {
        License::forceActivate($id);
        support_flash('Account activated. It will never lock.');
    } elseif ($action === 'owner') {
        // Create the owner, or change the current one. "take_over" frees an
        // email/username used by another account (that copy is switched off and
        // renamed …_old<ID>) — e.g. before importing a shop whose owner's login
        // already exists here.
        $username = trim($_POST['username'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pw = $_POST['password'] ?? '';
        $ownerId = (int) ($acct['owner_id'] ?? 0);
        $err = '';
        if (!preg_match('/^[A-Za-z0-9_.@-]{3,50}$/', $username)) $err = 'Username must be 3–50 letters, numbers, dots, dashes or underscores.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email.';
        elseif (!$ownerId && strlen($pw) < 8) $err = 'Set a password of at least 8 characters for the new owner.';
        elseif ($pw !== '' && strlen($pw) < 8) $err = 'Password must be at least 8 characters.';
        if (!$err) {
            $hit = $pdo->prepare(
                'SELECT u.id, u.username, u.email, t.name AS shop, (t.id = ? OR t.parent_tenant_id = ?) AS mine
                   FROM users u LEFT JOIN tenants t ON t.id = u.tenant_id
                  WHERE (u.email = ? OR u.username = ?) AND u.id <> ?'
            );
            $hit->execute([$id, $id, $email, $username, $ownerId]);
            $hits = $hit->fetchAll(PDO::FETCH_ASSOC);
            $others = array_filter($hits, fn($h) => !$h['mine']);
            if ($others && empty($_POST['take_over'])) {
                $o = reset($others);
                $err = "That login is already used by {$o['username']} ({$o['email']}) on \"" . ($o['shop'] ?? 'another account')
                     . '". Tick "Take over this login" to free it and use it here.';
            }
        }
        if ($err) {
            support_flash($err, 'danger');
        } else {
            $notes = [];
            $pdo->beginTransaction();
            try {
                $mine = !empty($_POST['take_over']) ? ShopImport::freeLogin($pdo, $email, $username, $id, $notes) : null;
                if ($mine === $ownerId) $mine = null;
                if (!$mine) { // a user of this account (e.g. staff) with that login can't stay alongside the owner
                    foreach ($hits as $h) if ($h['mine']) $mine = (int) $h['id'];
                }
                $roleId = (int) $pdo->query("SELECT id FROM roles WHERE role_name = 'tenant_owner'")->fetchColumn();
                $target = $mine ?: $ownerId;
                if ($target) {
                    $pdo->prepare('UPDATE users SET username = ?, email = ?, role_id = ?, is_active = 1, email_verified = 1, must_reset_password = 0 WHERE id = ?')
                        ->execute([$username, $email, $roleId, $target]);
                    if ($pw !== '') {
                        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $target]);
                    }
                } else {
                    $pdo->prepare(
                        'INSERT INTO users (tenant_id, username, email, password_hash, role_id, is_active, email_verified, activated_at)
                         VALUES (?, ?, ?, ?, ?, 1, 1, NOW())'
                    )->execute([$id, $username, $email, password_hash($pw, PASSWORD_DEFAULT), $roleId]);
                    $target = (int) $pdo->lastInsertId();
                }
                if ($ownerId && $target !== $ownerId) { // replaced: the old owner login is switched off, records stay
                    $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$ownerId]);
                    $notes[] = "Previous owner {$acct['owner_username']} switched off.";
                }
                $pdo->prepare('UPDATE tenants SET owner_user_id = ?, signup_completed_at = COALESCE(signup_completed_at, NOW()) WHERE id = ?')->execute([$target, $id]);
                $pdo->commit();
                License::forceActivate($id);
                support_flash(($ownerId ? 'Owner login updated' : 'Owner created') . ": $email" . ($pw !== '' ? ' (password set)' : '') . '. ' . implode(' ', $notes));
            } catch (Throwable $e) {
                $pdo->rollBack();
                support_flash('Could not save the owner: ' . $e->getMessage(), 'danger');
            }
        }
    }
    header('Location: ' . $self);
    exit;
}

$packages = Features::forAccount($id);
$shops = $pdo->prepare('SELECT name, status, created_at FROM tenants WHERE id = ? OR parent_tenant_id = ? ORDER BY parent_tenant_id IS NOT NULL, created_at');
$shops->execute([$id, $id]);
$shops = $shops->fetchAll(PDO::FETCH_ASSOC);

$page_title = $acct['name'];
$__css = '<style>.sw{width:52px;height:30px;border-radius:99px;border:0;background:#cbd5e1;position:relative;transition:background .2s;padding:0;}
.sw span{position:absolute;top:3px;left:3px;width:24px;height:24px;border-radius:50%;background:#fff;box-shadow:0 2px 5px rgba(0,0,0,.25);transition:transform .22s cubic-bezier(.3,1.4,.5,1);}
.sw.on{background:#22c55e;}.sw.on span{transform:translateX(22px);}</style>';
$nav = 'accounts';
ob_start();
echo $__css;
?>
<a class="small text-decoration-none" href="<?php echo public_url('support/accounts/'); ?>"><i class="fas fa-arrow-left me-1"></i>All accounts</a>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2 mb-4">
  <h1 class="h4 mb-0"><?php echo htmlspecialchars($acct['name']); ?> <?php echo support_status_badge($acct['status']); ?></h1>
  <span class="text-muted small">Account #<?php echo $id; ?> · since <?php echo date('d M Y', strtotime($acct['created_at'])); ?></span>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="s-card mb-3">
      <div class="d-flex justify-content-between mb-3"><h2 class="h6 mb-0">Packages</h2></div>
      <?php foreach ($catalog as $k => [$label, $price, $desc, $icon]): $on = $packages[$k]; ?>
        <div class="d-flex justify-content-between align-items-start gap-3 border-bottom py-3">
          <div>
            <strong><i class="fas <?php echo $icon; ?> fa-fw me-1 text-primary"></i><?php echo $label; ?></strong>
            <div class="small text-muted mt-1"><?php echo htmlspecialchars($desc); ?></div>
          </div>
          <form method="post" class="flex-shrink-0" <?php if ($on): ?>onsubmit="return confirm('Turn off <?php echo $label; ?> for this account? Its pages disappear for the owner and staff.');"<?php endif; ?>>
            <?php echo support_csrf_field(); ?>
            <input type="hidden" name="action" value="package"><input type="hidden" name="package" value="<?php echo $k; ?>">
            <input type="hidden" name="enabled" value="<?php echo $on ? '0' : '1'; ?>">
            <button class="sw <?php echo $on ? 'on' : ''; ?>" aria-label="<?php echo $on ? 'Turn off' : 'Turn on'; ?> <?php echo $label; ?>"><span></span></button>
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
    <?php $shopUrl = Signup::shopUrl($acct['slug']); ?>
    <div class="s-card mb-3" style="border-color:#93c5fd">
      <h2 class="h6 mb-1"><i class="fas fa-store me-1 text-primary"></i>Shop address</h2>
      <?php if (!$acct['owner_id']): ?>
        <p class="small text-muted mb-2">Waiting for the owner to sign up. Send them this link — it opens signup with <strong><?php echo htmlspecialchars($acct['name']); ?></strong> filled in.
          After signing up it becomes their POS address. (Or share the general signup page: <code><?php echo htmlspecialchars(Signup::signupUrl()); ?></code>)</p>
      <?php else: ?>
        <p class="small text-muted mb-2">The shop's POS. Staff sign in here with their PIN.</p>
      <?php endif; ?>
      <div class="input-group input-group-sm">
        <input class="form-control font-monospace" id="shopUrl" value="<?php echo htmlspecialchars($shopUrl); ?>" readonly onclick="this.select()">
        <button class="btn btn-primary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('shopUrl').value);this.textContent='Copied'">Copy</button>
      </div>
    </div>
    <?php if (!empty($acct['signup_completed_at'])): ?>
    <div class="s-card mb-3"><p class="small mb-0"><i class="fas fa-user-check text-success me-1"></i>Owner signed up via link on <?php echo date('d M Y H:i', strtotime($acct['signup_completed_at'])); ?>
      <?php echo !empty($acct['license_requested_at']) ? '· key requested ' . date('d M H:i', strtotime($acct['license_requested_at'])) : '· key not requested yet'; ?>.</p></div>
    <?php endif; ?>
    <div class="s-card mb-3">
      <h2 class="h6 mb-1"><i class="fas fa-file-import me-1 text-primary"></i>Import shop data</h2>
      <p class="small text-muted mb-2">Bring in a shop's old POS database (.sql export): inventory, sales, staff and more.
        <?php if (!empty($acct['imported_at'])): ?><br><strong>Last import: <?php echo date('d M Y H:i', strtotime($acct['imported_at'])); ?></strong><?php endif; ?></p>
      <a class="btn btn-sm btn-outline-primary" href="<?php echo public_url('support/accounts/import.php?id=' . $id); ?>">Import .sql</a>
    </div>
    <?php
      // Everyone who can sign in to this account, for "Open as" previews.
      $pvUsers = $pdo->prepare(
          "SELECT u.id, u.username, u.position, u.is_active, u.email_verified, r.role_name, t.name AS shop
             FROM users u JOIN roles r ON r.id = u.role_id JOIN tenants t ON t.id = u.tenant_id
            WHERE (t.id = ? OR t.parent_tenant_id = ?) AND r.role_name IN ('tenant_owner', 'staff')
         ORDER BY r.role_name = 'staff', u.username"
      );
      $pvUsers->execute([$id, $id]);
      $pvUsers = $pvUsers->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="s-card mb-3">
      <h2 class="h6 mb-1"><i class="fas fa-user-secret me-1 text-primary"></i><?php echo SupportAccount::isOwner() ? 'Preview their POS' : 'Logins'; ?></h2>
      <?php if (!SupportAccount::isOwner()): ?>
      <p class="small text-muted mb-2">Everyone who can sign in to this account. Opening their POS is limited to the main support admin.</p>
      <?php else: ?>
      <p class="small text-muted mb-2">Open the shop's real POS as the owner or a staff member — no password or PIN needed — to check the import
        (inventory, sales, staff…). Exit from the purple bar to come back here.</p>
      <?php endif; ?>
      <?php if (!$pvUsers): ?>
        <p class="small text-muted mb-0">No users yet — import their data or create the owner login first.</p>
      <?php else: ?>
      <div class="list-group list-group-flush">
        <?php foreach ($pvUsers as $u): ?>
        <div class="list-group-item px-0 d-flex align-items-center gap-2">
          <span class="badge <?php echo $u['role_name'] === 'staff' ? 'bg-secondary' : 'bg-primary'; ?>"><?php echo $u['role_name'] === 'staff' ? 'Staff' : 'Owner'; ?></span>
          <span class="small flex-grow-1"><strong><?php echo htmlspecialchars($u['username']); ?></strong>
            <span class="text-muted"><?php echo htmlspecialchars(trim(($u['position'] ?: '') . ' · ' . $u['shop'], ' ·')); ?></span>
            <?php if (!$u['is_active'] || !$u['email_verified']): ?><span class="badge bg-light text-danger"><?php echo !$u['is_active'] ? 'switched off' : 'unverified'; ?></span><?php endif; ?></span>
          <?php if (!$u['is_active'] || !$u['email_verified']): ?>
          <form method="post" class="m-0"><?php echo support_csrf_field(); ?><input type="hidden" name="action" value="activate_user"><input type="hidden" name="user" value="<?php echo (int) $u['id']; ?>">
            <button class="btn btn-sm btn-outline-success">Activate</button></form>
          <?php endif; ?>
          <?php if (SupportAccount::isOwner()): ?>
          <form method="post" action="<?php echo public_url('support/accounts/preview.php?id=' . $id); ?>" class="m-0">
            <?php echo support_csrf_field(); ?><input type="hidden" name="user" value="<?php echo (int) $u['id']; ?>">
            <button class="btn btn-sm btn-outline-primary"><i class="fas fa-arrow-up-right-from-square me-1"></i>Open as</button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php $lic = License::state($id); ?>
    <div class="s-card mb-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Activation</h2><?php echo support_license_badge($id) ?: '<span class="badge bg-light text-muted">No key required</span>'; ?>
      </div>
      <?php
        $blocked = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN tenants t ON t.id = u.tenant_id
                                   WHERE (t.id = ? OR t.parent_tenant_id = ?) AND (u.is_active = 0 OR u.email_verified = 0)
                                     AND u.username NOT REGEXP '_old[0-9]+$'");
        $blocked->execute([$id, $id]);
        $blocked = (int) $blocked->fetchColumn();
      ?>
      <form method="post" class="mb-2 p-2 rounded" style="background:#f0fdf4">
        <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="waive">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span class="small flex-grow-1"><strong>Waive activation</strong> — no key, no email link: the owner and staff sign in straight away.
            <?php if ($blocked): ?><br><span class="text-danger"><?php echo $blocked; ?> login<?php echo $blocked === 1 ? ' is' : 's are'; ?> switched off or unverified.</span><?php endif; ?></span>
          <button class="btn btn-sm btn-success"><i class="fas fa-check me-1"></i>Waive</button>
        </div>
      </form>
      <?php if ($lic['status'] === 'active'): ?>
        <p class="small text-muted mb-0">
          <?php echo $lic['activated_at'] ? 'Activated on ' . date('d M Y H:i', strtotime($lic['activated_at'])) . '. This account never locks.' : 'Created before activation keys, so it is not locked. Generate a key to require activation within 2 hours.'; ?>
        </p>
        <?php if (!$lic['activated_at']): ?>
          <form method="post" class="mt-2"><?php echo support_csrf_field(); ?><input type="hidden" name="action" value="license_regenerate">
            <button class="btn btn-sm btn-outline-secondary" onclick="return confirm('Require activation? The account locks in 2 hours unless the key is entered.');">Generate key</button></form>
        <?php endif; ?>
      <?php else: ?>
        <p class="small text-muted mb-2">
          <?php echo $lic['status'] === 'locked' ? 'The grace period ended ' . date('d M Y H:i', strtotime($lic['deadline'])) . '. Owner, staff and all shops are locked until the key is entered.' : 'Locks at ' . date('d M Y H:i', strtotime($lic['deadline'])) . ' unless the key is entered.'; ?>
        </p>
        <div class="mb-2"><label class="form-label small mb-1">Activation key</label>
          <input class="form-control font-monospace" value="<?php echo htmlspecialchars($lic['key'] ?? ''); ?>" readonly onclick="this.select()"></div>
        <form method="post" class="d-flex gap-2 mb-2">
          <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="license_email">
          <input class="form-control form-control-sm" type="email" name="to" value="<?php echo htmlspecialchars($acct['owner_email'] ?? ''); ?>" required>
          <button class="btn btn-sm btn-primary text-nowrap"><i class="fas fa-envelope me-1"></i>Email key</button>
        </form>
        <div class="d-flex gap-2">
          <form method="post"><?php echo support_csrf_field(); ?><input type="hidden" name="action" value="license_regenerate">
            <button class="btn btn-sm btn-outline-secondary" onclick="return confirm('Replace the key? The old one stops working.');">Regenerate</button></form>
          <form method="post"><?php echo support_csrf_field(); ?><input type="hidden" name="action" value="license_activate">
            <button class="btn btn-sm btn-outline-success" onclick="return confirm('Activate this account without a key?');">Activate now</button></form>
        </div>
      <?php endif; ?>
    </div>

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
      <h2 class="h6 mb-1">Owner login</h2>
      <p class="small text-muted mb-3"><?php echo $acct['owner_id'] ? 'Change the owner\'s username, email or password — or replace the owner.' : 'This account has no owner yet. Create one here (or import a shop — its owner comes with it).'; ?></p>
      <form method="post" autocomplete="off">
        <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="owner">
        <div class="mb-2"><label class="form-label small">Username</label><input class="form-control" name="username" value="<?php echo htmlspecialchars($acct['owner_username'] ?? ''); ?>" autocapitalize="off" required></div>
        <div class="mb-2"><label class="form-label small">Email (login)</label><input class="form-control" type="email" name="email" value="<?php echo htmlspecialchars($acct['owner_email'] ?? ''); ?>" required></div>
        <div class="mb-2"><label class="form-label small"><?php echo $acct['owner_id'] ? 'New password <small class="text-muted">(leave blank to keep)</small>' : 'Password'; ?></label>
          <input class="form-control" type="text" name="password" minlength="8" <?php echo $acct['owner_id'] ? '' : 'required'; ?>></div>
        <label class="form-check small mb-3"><input class="form-check-input" type="checkbox" name="take_over" value="1">
          <span class="form-check-label"><strong>Take over this login</strong> if another account already uses this email/username
            (that copy is switched off and renamed). Use it for a shop whose owner already has a login here.</span></label>
        <button class="btn btn-sm btn-primary"><?php echo $acct['owner_id'] ? 'Save owner' : 'Create owner'; ?></button>
      </form>
    </div>

    <div class="s-card mb-3">
      <h2 class="h6 mb-1"><i class="fas fa-user-plus me-1 text-primary"></i>Add a new owner</h2>
      <p class="small text-muted mb-3">Creates a fresh owner login with a password you choose — use it when the current owner's password (e.g. one imported from their old POS) isn't known.
        The existing owner keeps working.</p>
      <form method="post" autocomplete="off">
        <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="new_owner">
        <div class="mb-2"><label class="form-label small">Username</label><input class="form-control" name="username" autocapitalize="off" required></div>
        <div class="mb-2"><label class="form-label small">Email (login)</label><input class="form-control" type="email" name="email" required></div>
        <div class="mb-2"><label class="form-label small">Password</label><input class="form-control" type="text" name="password" minlength="8" required></div>
        <label class="form-check small mb-3"><input class="form-check-input" type="checkbox" name="main" value="1" checked>
          <span class="form-check-label">Make this the <strong>main owner</strong> (gets "Admins", "Import shop" and "Cleanup"; untick for an extra owner)</span></label>
        <button class="btn btn-sm btn-primary"><i class="fas fa-user-plus me-1"></i>Create owner</button>
      </form>
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

    <?php $del = AccountDeletion::preview($id); ?>
    <div class="s-card mt-3" style="border:1px solid #fecaca">
      <h2 class="h6 mb-1 text-danger"><i class="fas fa-trash me-1"></i>Delete account</h2>
      <p class="small text-muted mb-2">Permanently deletes <strong><?php echo htmlspecialchars(implode(', ', $del['shops'])); ?></strong>,
        <?php echo $del['users']; ?> login<?php echo $del['users'] === 1 ? '' : 's'; ?> and <?php echo number_format($del['rows']); ?> records
        (inventory, sales, customers, staff, attendance, services, settings…), plus uploaded logos and product photos. <strong>This cannot be undone.</strong></p>
      <form method="post" autocomplete="off" onsubmit="return confirm('Delete this account and ALL its data forever?');">
        <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="delete_account">
        <label class="form-label small">Type <strong><?php echo htmlspecialchars($acct['name']); ?></strong> to confirm</label>
        <div class="d-flex gap-2">
          <input class="form-control form-control-sm" name="confirm_name" required>
          <button class="btn btn-sm btn-danger text-nowrap">Delete forever</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../_layout.php';
