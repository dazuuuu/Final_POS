<?php
// public/support/accounts/index.php — every shop-owner account, plus "create account".
// Support only sees account-level info (owner, shops, packages) — never the
// shop's staff, sales or stock.
require_once __DIR__ . '/../_boot.php';

$pdo = Database::pdo();
$catalog = Features::catalog();
$errors = [];
$in = ['business_name' => '', 'username' => '', 'email' => '', 'phone' => '', 'packages' => [Features::SHOP_POS]];

// Move from an old POS: create the shop with its packages, keep the uploaded
// dump and open the import preview. The import makes the old owner this
// account's owner (same email + password) and activates it — nothing for the
// owner to do but sign in.
if (support_posted() && ($_POST['action'] ?? '') === 'migrate') {
    $back = public_url('support/accounts/?new=1');
    $pk = array_values(array_intersect((array) ($_POST['packages'] ?? []), array_keys($catalog)));
    try {
        if (!$pk) throw new RuntimeException('Choose at least one package.');
        $file = ShopImport::storeUpload($_FILES['dump'] ?? []);
        $tables = ShopImport::load($file);
        $shops = ShopImport::sourceShops($tables);
        $name = trim($_POST['business_name'] ?? '') ?: (count($shops) === 1 ? (string) reset($shops) : '');
        if ($name === '') {
            ShopImport::discard($file);
            throw new RuntimeException('The file has ' . (count($shops) ?: 'no') . ' shops in it — type the business name.');
        }
        if (Signup::nameTaken($name)) {
            ShopImport::discard($file);
            throw new RuntimeException('A shop called "' . $name . '" already exists. Use a different business name.');
        }
        $newId = Signup::invite($name, $pk, trim($_POST['phone'] ?? ''));
        $_SESSION['shop_import_' . $newId] = $file;
        support_flash('Shop "' . $name . '" created. Check the preview and import — the old owner then signs in with their old login.');
        header('Location: ' . public_url('support/accounts/import.php?id=' . $newId));
    } catch (Throwable $e) {
        support_flash($e->getMessage(), 'danger');
        header('Location: ' . $back);
    }
    exit;
}

if (support_posted() && ($_POST['action'] ?? '') === 'invite') {
    $name = trim($_POST['business_name'] ?? '');
    $pk = array_values(array_intersect((array) ($_POST['packages'] ?? []), array_keys($catalog)));
    if ($name === '') {
        support_flash('Business name is required.', 'danger');
        header('Location: ' . public_url('support/accounts/'));
        exit;
    }
    if (Signup::nameTaken($name)) {
        support_flash('A shop called "' . $name . '" already exists. Use a different business name.', 'danger');
        header('Location: ' . public_url('support/accounts/'));
        exit;
    }
    $ownerEmail = strtolower(trim($_POST['owner_email'] ?? ''));
    if ($ownerEmail !== '' && !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
        support_flash('"' . $ownerEmail . '" is not a valid email address.', 'danger');
        header('Location: ' . public_url('support/accounts/'));
        exit;
    }
    $newId = Signup::invite($name, $pk, trim($_POST['phone'] ?? ''));
    if ($ownerEmail === '') {
        support_flash('Shop "' . $name . '" created. Send the owner their shop link below.');
    } elseif (($err = Signup::emailInvite($newId, $ownerEmail)) === null) {
        support_flash('Shop "' . $name . '" created and the signup link was emailed to ' . $ownerEmail . '.'
            . (Signup::enabled() ? '' : ' Note: the signup page is OFF — turn it on from Overview.'));
    } else {
        support_flash('Shop "' . $name . '" created, but the signup email failed: ' . $err . ' Send them the link below instead.', 'warning');
    }
    header('Location: ' . public_url('support/accounts/view.php?id=' . $newId));
    exit;
}

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
            $newOwner = (int) $pdo->lastInsertId();
            $T->setOwner($tenantId, $newOwner);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Could not create the account: ' . $e->getMessage();
        }
        if (!$errors) {
            Features::setForAccount($tenantId, $in['packages']);
            $cf = EmailConfirm::start('user', $newOwner, $in['email'], $in['username'], $password, EmailConfirm::absolute('admin/'), $tenantId);
            if (!empty($_POST['skip_activation'])) {
                License::waive($tenantId);
                $pdo->prepare('UPDATE users SET email_verified = 0 WHERE id = ?')->execute([$newOwner]); // still waits for "Confirm your email"
                support_flash('Account "' . $in['business_name'] . '" created and activated. ' . ($cf['sent'] ? 'A "Confirm your email" message went to ' . $in['email'] . ' — they can sign in once they confirm, and then get their login details.'
                    : 'The confirm email could not be sent (check Settings → SMTP). Send the owner this link: ' . $cf['link']));
            } else {
                $key = License::issue($tenantId);
                support_flash('Account "' . $in['business_name'] . '" created. ' . ($cf['sent'] ? 'A "Confirm your email" message went to ' . $in['email'] . '; they sign in after confirming.' : 'Confirm link (email not sent): ' . $cf['link'] . '.')
                    . ' Activation key: ' . $key . ' — email it below; the account locks in 2 hours without it.');
            }
            header('Location: ' . public_url('support/accounts/view.php?id=' . $tenantId));
            exit;
        }
    }
}

$accounts = support_accounts($pdo);
$q = trim($_GET['q'] ?? '');
$showForm = $errors || !empty($_GET['new']);

// One spreadsheet row per account.
$rows = [];
foreach ($accounts as $a) {
    $id = (int) $a['id'];
    $pk = [];
    foreach (Features::forAccount($id) as $k => $on) {
        if ($on) { $pk[] = $catalog[$k][0]; }
    }
    $lic = License::state($id);
    $licLabel = !$a['owner_user_id'] ? 'Awaiting signup' : ($lic['status'] === 'locked' ? 'Locked'
        : ($lic['status'] === 'grace' ? 'Awaiting key' : ($lic['activated_at'] ? 'Activated' : '—')));
    $rows[] = [
        'id' => $id, 'name' => $a['name'], 'owner' => $a['owner_username'] ?? '', 'email' => $a['owner_email'] ?? '',
        'phone' => $a['phone'] ?? '', 'shops' => 1 + (int) $a['branch_count'], 'packages' => implode(', ', $pk),
        'license' => $licLabel, 'lic' => $lic['status'], 'status' => $a['status'],
        'created' => date('Y-m-d', strtotime($a['created_at'])),
    ];
}
$count = fn(callable $f) => count(array_filter($rows, $f));
$sheets = [
    'all'     => ['All accounts', count($rows)],
    'active'  => ['Active', $count(fn($r) => $r['status'] === 'active')],
    'grace'   => ['Awaiting key', $count(fn($r) => $r['lic'] === 'grace')],
    'locked'  => ['Locked', $count(fn($r) => $r['lic'] === 'locked')],
    'stopped' => ['Suspended', $count(fn($r) => $r['status'] !== 'active')],
];
$cols = [ // key => [header, align, width]
    'name' => ['Business', 'l', 190], 'owner' => ['Owner', 'l', 120], 'email' => ['Email', 'l', 210],
    'phone' => ['Phone', 'l', 120], 'shops' => ['Shops', 'r', 70], 'packages' => ['Packages', 'l', 220],
    'license' => ['Activation', 'l', 120],
    'status' => ['Status', 'l', 100], 'created' => ['Created', 'r', 105],
];

$page_title = 'Accounts';
$nav = 'accounts';
ob_start();
?>
<style>
.xl{--xl-green:#217346;--xl-grid:#d4d4d4;--xl-head:#f3f3f3;background:#fff;border:1px solid #c8c8c8;border-radius:14px;overflow:hidden;
  box-shadow:0 1px 3px rgba(0,0,0,.06);font-family:'Segoe UI',Calibri,Inter,sans-serif;}
.xl-ribbon{background:var(--xl-green);color:#fff;display:flex;align-items:center;gap:8px;padding:8px 12px;flex-wrap:wrap;}
.xl-ribbon .ttl{font-weight:700;display:flex;align-items:center;gap:8px;margin-right:auto;font-size:.95rem;}
.xl-ribbon .ttl i{background:#fff;color:var(--xl-green);width:24px;height:24px;border-radius:5px;display:grid;place-items:center;font-size:.8rem;}
.xl-btn{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.25);color:#fff;border-radius:8px;padding:5px 10px;font-size:.8rem;font-weight:600;cursor:pointer;white-space:nowrap;}
.xl-btn:hover{background:rgba(255,255,255,.26);color:#fff;}
.xl-fx{display:flex;align-items:stretch;border-bottom:1px solid var(--xl-grid);font-size:.82rem;background:#fff;}
.xl-fx .ref{width:84px;flex-shrink:0;border-right:1px solid var(--xl-grid);padding:6px 8px;font-weight:600;color:#333;}
.xl-fx .fx{width:34px;flex-shrink:0;border-right:1px solid var(--xl-grid);display:grid;place-items:center;color:#777;font-style:italic;font-family:Georgia,serif;}
.xl-fx input{flex:1;border:0;outline:0;padding:6px 10px;min-width:0;font-size:.82rem;}
.xl-wrap{overflow:auto;max-height:calc(100vh - 330px);min-height:240px;-webkit-overflow-scrolling:touch;}
.xl-t{border-collapse:separate;border-spacing:0;font-size:.82rem;width:max-content;min-width:100%;}
.xl-t th,.xl-t td{border-right:1px solid var(--xl-grid);border-bottom:1px solid var(--xl-grid);padding:0 8px;height:28px;white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis;max-width:320px;}
.xl-t thead th{position:sticky;top:0;z-index:2;background:var(--xl-head);font-weight:400;color:#444;text-align:center;}
.xl-t thead tr.letters th{height:22px;font-size:.72rem;color:#666;}
.xl-t thead tr.heads th{top:22px;font-weight:700;color:#222;background:#e9efe9;cursor:pointer;user-select:none;}
.xl-t thead tr.heads th:hover{background:#dce8dc;}
.xl-t .rn{position:sticky;left:0;z-index:1;background:var(--xl-head);color:#666;text-align:center;width:44px;min-width:44px;font-size:.74rem;}
.xl-t thead .rn{z-index:3;}
.xl-t td.r{text-align:right;font-variant-numeric:tabular-nums;}
.xl-t tbody tr{cursor:cell;}
.xl-t tbody tr:hover td{background:#f4f9f4;}
.xl-t td.sel{outline:2px solid var(--xl-green);outline-offset:-2px;background:#fff !important;}
.xl-t tr.selrow .rn{background:#d3e7d9;color:var(--xl-green);font-weight:700;}
.xl-t tfoot td{position:sticky;bottom:0;background:#f7f7f7;font-weight:700;border-top:2px solid #bbb;}
.pill{display:inline-block;padding:1px 8px;border-radius:999px;font-size:.72rem;font-weight:700;}
.pill.ok{background:#dcfce7;color:#166534}.pill.wait{background:#fef3c7;color:#92400e}.pill.bad{background:#fee2e2;color:#991b1b}.pill.mute{background:#f1f5f9;color:#475569}
.xl-tabs{display:flex;align-items:flex-end;gap:2px;background:#f0f0f0;border-top:1px solid var(--xl-grid);padding:0 8px;overflow-x:auto;}
.xl-tab{border:0;background:transparent;padding:7px 14px;font-size:.8rem;color:#444;border-bottom:3px solid transparent;white-space:nowrap;cursor:pointer;}
.xl-tab.on{background:#fff;color:var(--xl-green);font-weight:700;border-bottom-color:var(--xl-green);box-shadow:0 0 0 1px var(--xl-grid);}
.xl-tab b{font-weight:600;color:#888;margin-left:4px;font-size:.72rem;}
.xl-status{display:flex;gap:18px;justify-content:flex-end;background:var(--xl-green);color:#e8f5ec;font-size:.74rem;padding:4px 12px;}
.xl-empty{padding:40px;text-align:center;color:#777;}
/* bottom sheet */
.modal.sheet .modal-dialog{margin:0 auto;max-width:720px;display:flex;align-items:flex-end;min-height:100%;}
.modal.sheet .modal-content{border:0;border-radius:24px 24px 0 0;padding-bottom:env(safe-area-inset-bottom,0);}
.modal.sheet.fade .modal-dialog{transform:translateY(100%);transition:transform .3s cubic-bezier(.2,.9,.3,1);}
.modal.sheet.show .modal-dialog{transform:none;}
.sheet-grip{width:42px;height:5px;border-radius:3px;background:#cbd5e1;margin:10px auto 0;}
.pkg-chip{display:flex;align-items:center;gap:10px;border:1.5px solid #e2e8f0;border-radius:14px;padding:10px 12px;cursor:pointer;height:100%;}
.pkg-chip input{display:none;}
.pkg-chip .ic{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:#f1f5f9;color:#475569;}
.pkg-chip:has(input:checked){border-color:#2563eb;background:#eff6ff;}
.pkg-chip:has(input:checked) .ic{background:#2563eb;color:#fff;}
.xl-status span{white-space:nowrap;}
@media (max-width:640px){ .xl-wrap{max-height:calc(100vh - 300px);} .xl-fx .ref{width:64px;}
  .xl-btn .lbl,.xl-status .hint{display:none;} .xl-btn i{margin:0 !important;} .xl-btn{padding:6px 11px;} }
</style>

<div class="xl">
  <div class="xl-ribbon">
    <span class="ttl"><i class="fas fa-table"></i>Accounts.xlsx</span>
    <button class="xl-btn" type="button" data-bs-toggle="modal" data-bs-target="#newAccount"><i class="fas fa-plus me-1"></i><span class="lbl">New</span></button>
    <button class="xl-btn" type="button" id="xlOpen" disabled><i class="fas fa-up-right-from-square me-1"></i><span class="lbl">Open</span></button>
    <button class="xl-btn" type="button" id="xlCsv"><i class="fas fa-file-csv me-1"></i><span class="lbl">Export</span></button>
  </div>
  <div class="xl-fx">
    <div class="ref" id="xlRef">A1</div>
    <div class="fx">fx</div>
    <input id="xlFind" placeholder="Search any cell — business, owner, email, phone, package…" value="<?php echo htmlspecialchars($q); ?>" autocomplete="off">
  </div>
  <div class="xl-wrap">
    <?php if (!$rows): ?>
      <div class="xl-empty"><i class="fas fa-table-cells fa-2x mb-2 d-block"></i>No accounts yet. Tap <strong>+</strong> to register the first shop owner.</div>
    <?php else: ?>
    <table class="xl-t" id="xlT">
      <thead>
        <tr class="letters"><th class="rn"></th><?php $i = 0; foreach ($cols as $c): ?><th style="min-width:<?php echo $c[2]; ?>px"><?php echo chr(65 + $i++); ?></th><?php endforeach; ?></tr>
        <tr class="heads"><th class="rn">1</th><?php $i = 0; foreach ($cols as $k => $c): ?><th data-col="<?php echo $i++; ?>" data-num="<?php echo $c[1] === 'r' ? 1 : 0; ?>"><?php echo $c[0]; ?> <i class="fas fa-sort small text-muted"></i></th><?php endforeach; ?></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $n => $r):
        $lp = ['Activated' => 'ok', 'Awaiting key' => 'wait', 'Locked' => 'bad'][$r['license']] ?? 'mute';
        $sp = ['active' => 'ok', 'suspended' => 'wait'][$r['status']] ?? 'bad'; ?>
        <tr data-id="<?php echo $r['id']; ?>" data-status="<?php echo $r['status']; ?>" data-lic="<?php echo $r['lic']; ?>">
          <td class="rn"><?php echo $n + 2; ?></td>
          <td><strong><?php echo htmlspecialchars($r['name']); ?></strong></td>
          <td><?php echo htmlspecialchars($r['owner']); ?></td>
          <td><?php echo htmlspecialchars($r['email']); ?></td>
          <td><?php echo htmlspecialchars($r['phone']); ?></td>
          <td class="r"><?php echo $r['shops']; ?></td>
          <td><?php echo htmlspecialchars($r['packages'] ?: '—'); ?></td>
          <td><span class="pill <?php echo $lp; ?>"><?php echo $r['license']; ?></span></td>
          <td><span class="pill <?php echo $sp; ?>"><?php echo ucfirst($r['status']); ?></span></td>
          <td class="r"><?php echo $r['created']; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td class="rn">Σ</td><td id="xlCount"></td><td></td><td></td><td></td><td class="r" id="xlShops"></td><td></td><td></td><td></td><td></td></tr></tfoot>
    </table>
    <?php endif; ?>
  </div>
  <div class="xl-tabs">
    <?php foreach ($sheets as $k => [$label, $n]): ?>
      <button class="xl-tab <?php echo $k === 'all' ? 'on' : ''; ?>" type="button" data-sheet="<?php echo $k; ?>"><?php echo $label; ?><b><?php echo $n; ?></b></button>
    <?php endforeach; ?>
  </div>
  <div class="xl-status"><span id="xlStatCount"></span><span class="hint">Double-tap a row to manage</span></div>
</div>

<div class="modal fade sheet" id="newAccount" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="sheet-grip"></div>
      <div class="modal-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2 class="h5 fw-bold mb-0">Register a shop owner</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <?php if ($errors): ?><div class="alert alert-danger py-2"><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div><?php endif; ?>
        <ul class="nav nav-pills nav-fill mb-3 small" role="tablist">
          <li class="nav-item"><button class="nav-link active" type="button" data-bs-toggle="pill" data-bs-target="#modeInvite">Shop name only (owner signs up)</button></li>
          <li class="nav-item"><button class="nav-link" type="button" data-bs-toggle="pill" data-bs-target="#modeMigrate">Move from old POS</button></li>
          <li class="nav-item"><button class="nav-link" type="button" data-bs-toggle="pill" data-bs-target="#modeFull">Full account</button></li>
        </ul>
        <div class="tab-content">
        <form method="post" autocomplete="off" class="tab-pane fade show active" id="modeInvite">
          <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="invite">
          <div class="mb-3"><label class="form-label small fw-semibold">Business name</label><input class="form-control" name="business_name" required>
            <div class="form-text">Their address becomes <code><?php echo htmlspecialchars(Signup::shopUrl('your-shop-name')); ?></code>. The owner types this name on the signup page.</div></div>
          <div class="mb-3"><label class="form-label small fw-semibold">Owner email <span class="text-muted fw-normal">(optional)</span></label><input class="form-control" type="email" name="owner_email" autocapitalize="off">
            <div class="form-text">If given, the signup link is emailed to the owner.</div></div>
          <div class="mb-3"><label class="form-label small fw-semibold">Business phone <span class="text-muted fw-normal">(optional)</span></label><input class="form-control" name="phone" inputmode="tel"></div>
          <label class="form-label small fw-semibold d-block">Packages</label>
          <div class="row g-2">
          <?php foreach ($catalog as $k => [$label, $price, , $icon]): ?>
            <div class="col-6"><label class="pkg-chip">
              <input type="checkbox" name="packages[]" value="<?php echo $k; ?>" <?php echo $k === Features::SHOP_POS ? 'checked' : ''; ?>>
              <span class="ic"><i class="fas <?php echo $icon; ?>"></i></span>
              <span><strong class="d-block small"><?php echo $label; ?></strong></span>
            </label></div>
          <?php endforeach; ?>
          </div>
          <button class="btn btn-primary w-100 mt-4 py-2"><i class="fas fa-store me-1"></i>Create shop</button>
          <p class="small text-muted text-center mt-2 mb-0">The owner creates their own login on the signup page, imports their data and goes straight in — no activation key.</p>
        </form>
        <form method="post" autocomplete="off" enctype="multipart/form-data" class="tab-pane fade" id="modeMigrate">
          <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="migrate">
          <div class="mb-3"><label class="form-label small fw-semibold">Old POS database (.sql)</label>
            <input class="form-control" type="file" name="dump" accept=".sql,text/plain" required>
            <div class="form-text">phpMyAdmin → Export → SQL from their old system.</div></div>
          <div class="row g-3 mb-3">
            <div class="col-md-6"><label class="form-label small fw-semibold">Business name <span class="text-muted fw-normal">(blank = name in the file)</span></label><input class="form-control" name="business_name"></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Business phone <span class="text-muted fw-normal">(optional)</span></label><input class="form-control" name="phone" inputmode="tel"></div>
          </div>
          <label class="form-label small fw-semibold d-block">Packages</label>
          <div class="row g-2">
          <?php foreach ($catalog as $k => [$label, $price, , $icon]): ?>
            <div class="col-6"><label class="pkg-chip">
              <input type="checkbox" name="packages[]" value="<?php echo $k; ?>" <?php echo $k === Features::SHOP_POS ? 'checked' : ''; ?>>
              <span class="ic"><i class="fas <?php echo $icon; ?>"></i></span>
              <span><strong class="d-block small"><?php echo $label; ?></strong></span>
            </label></div>
          <?php endforeach; ?>
          </div>
          <button class="btn btn-primary w-100 mt-4 py-2"><i class="fas fa-file-import me-1"></i>Upload &amp; preview</button>
          <p class="small text-muted text-center mt-2 mb-0">After the import the owner signs in with their <strong>old email and password</strong>, staff with their old PINs. Already activated — no key needed.</p>
        </form>
        <form method="post" autocomplete="off" class="tab-pane fade" id="modeFull">
          <?php echo support_csrf_field(); ?>
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label small fw-semibold">Business name</label><input class="form-control" name="business_name" value="<?php echo htmlspecialchars($in['business_name']); ?>" required></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Business phone <span class="text-muted fw-normal">(optional)</span></label><input class="form-control" name="phone" inputmode="tel" value="<?php echo htmlspecialchars($in['phone']); ?>"></div>
            <div class="col-md-4"><label class="form-label small fw-semibold">Owner username</label><input class="form-control" name="username" autocapitalize="off" value="<?php echo htmlspecialchars($in['username']); ?>" required></div>
            <div class="col-md-4"><label class="form-label small fw-semibold">Owner email (login)</label><input class="form-control" type="email" name="email" value="<?php echo htmlspecialchars($in['email']); ?>" required></div>
            <div class="col-md-4"><label class="form-label small fw-semibold">Initial password</label><input class="form-control" type="text" name="password" minlength="8" required></div>
            <div class="col-12"><label class="form-label small fw-semibold d-block">Packages</label>
              <div class="row g-2">
              <?php foreach ($catalog as $k => [$label, $price, , $icon]): ?>
                <div class="col-6"><label class="pkg-chip">
                  <input type="checkbox" name="packages[]" value="<?php echo $k; ?>" <?php echo in_array($k, $in['packages'], true) ? 'checked' : ''; ?>>
                  <span class="ic"><i class="fas <?php echo $icon; ?>"></i></span>
                  <span><strong class="d-block small"><?php echo $label; ?></strong></span>
                </label></div>
              <?php endforeach; ?>
              </div>
            </div>
          </div>
          <label class="form-check small mt-3"><input class="form-check-input" type="checkbox" name="skip_activation" value="1" checked>
            <span class="form-check-label"><strong>Already activated</strong> — no activation key or email link; the owner signs in straight away.</span></label>
          <button class="btn btn-primary w-100 mt-3 py-2"><i class="fas fa-user-plus me-1"></i>Create account</button>
          <p class="small text-muted text-center mt-2 mb-0">Untick to require an activation key (the account locks in 2 hours without it).</p>
        </form>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('newAccount');
  <?php if ($showForm): ?>document.addEventListener('DOMContentLoaded', function () { new bootstrap.Modal(modal).show();<?php if ($errors): ?> bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="#modeFull"]')).show();<?php endif; ?> });<?php endif; ?>
  var t = document.getElementById('xlT');
  var find = document.getElementById('xlFind'), ref = document.getElementById('xlRef'), openBtn = document.getElementById('xlOpen');
  var sheet = 'all', selRow = null, lastFx = '';
  var viewUrl = <?php echo json_encode(public_url('support/accounts/view.php?id=')); ?>;
  function rows() { return t ? Array.prototype.slice.call(t.tBodies[0].rows) : []; }
  function fmt(n) { return n.toLocaleString('en-KE'); }
  function apply() {
    var q = find.value === lastFx ? '' : find.value.toLowerCase();
    var n = 0, shops = 0;
    rows().forEach(function (r) {
      var inSheet = sheet === 'all' || (sheet === 'active' && r.dataset.status === 'active') || (sheet === 'grace' && r.dataset.lic === 'grace')
        || (sheet === 'locked' && r.dataset.lic === 'locked') || (sheet === 'stopped' && r.dataset.status !== 'active');
      var show = inSheet && (!q || r.textContent.toLowerCase().indexOf(q) !== -1);
      r.style.display = show ? '' : 'none';
      if (show) { n++; shops += +r.cells[5].textContent; }
    });
    if (!t) return;
    document.getElementById('xlCount').textContent = 'COUNT ' + n;
    document.getElementById('xlShops').textContent = shops;
    document.getElementById('xlStatCount').textContent = 'Count: ' + n;
  }
  find.addEventListener('input', function () { lastFx = ''; apply(); });
  document.querySelectorAll('.xl-tab').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.xl-tab').forEach(function (x) { x.classList.remove('on'); });
      b.classList.add('on'); sheet = b.dataset.sheet; apply();
    });
  });
  if (t) {
    t.tBodies[0].addEventListener('click', function (e) {
      var td = e.target.closest('td'); if (!td || td.classList.contains('rn')) return;
      t.querySelectorAll('td.sel').forEach(function (c) { c.classList.remove('sel'); });
      if (selRow) selRow.classList.remove('selrow');
      td.classList.add('sel'); selRow = td.parentNode; selRow.classList.add('selrow');
      ref.textContent = String.fromCharCode(64 + td.cellIndex) + selRow.cells[0].textContent;
      lastFx = td.textContent.trim(); find.value = lastFx; openBtn.disabled = false;
    });
    t.tBodies[0].addEventListener('dblclick', function (e) {
      var tr = e.target.closest('tr'); if (tr) location.href = viewUrl + tr.dataset.id;
    });
    t.querySelectorAll('tr.heads th[data-col]').forEach(function (th) {
      var dir = 1;
      th.addEventListener('click', function () {
        var c = +th.dataset.col + 1, num = th.dataset.num === '1';
        var list = rows().sort(function (a, b) {
          var x = a.cells[c].dataset.v || a.cells[c].textContent.trim(), y = b.cells[c].dataset.v || b.cells[c].textContent.trim();
          return (num ? (parseFloat(x.replace(/,/g, '')) || 0) - (parseFloat(y.replace(/,/g, '')) || 0) : x.localeCompare(y)) * dir;
        });
        list.forEach(function (r, i) { t.tBodies[0].appendChild(r); r.cells[0].textContent = i + 2; });
        dir = -dir;
      });
    });
  }
  openBtn.addEventListener('click', function () { if (selRow) location.href = viewUrl + selRow.dataset.id; });
  document.getElementById('xlCsv').addEventListener('click', function () {
    if (!t) return;
    var out = [Array.prototype.slice.call(t.querySelectorAll('tr.heads th[data-col]')).map(function (h) { return h.textContent.trim(); })];
    rows().forEach(function (r) {
      if (r.style.display === 'none') return;
      out.push(Array.prototype.slice.call(r.cells, 1).map(function (c) { return c.dataset.v || c.textContent.trim(); }));
    });
    var csv = out.map(function (r) { return r.map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(','); }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv' }));
    a.download = 'accounts.csv'; a.click();
  });
  apply();
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../_layout.php';
