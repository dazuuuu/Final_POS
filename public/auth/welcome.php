<?php
// public/auth/welcome.php — what a new owner sees right after signing up.
//   ?step=import (default)  bring in the old shop's database (optional)
//   ?step=done              their POS link (domain/<slug>), install, activation key
require_once __DIR__ . '/../../app/app.php';

if (empty($_SESSION['logged_in']) || TenantContext::role() !== 'tenant_owner') {
    header('Location: ' . public_url('auth/login.php'));
    exit;
}
$accountId = Features::accountId((int) TenantContext::tenantId());
$stmt = Database::pdo()->prepare('SELECT * FROM tenants WHERE id = ?');
$stmt->execute([$accountId]);
$acct = $stmt->fetch(PDO::FETCH_ASSOC);
$_SESSION['shop_slug'] = $acct['slug'];
$step = ($_GET['step'] ?? '') === 'done' ? 'done' : 'import';
$posUrl = Signup::shopUrl($acct['slug']);
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);

$_SESSION['import_csrf'] ??= bin2hex(random_bytes(16));
$_SESSION['license_csrf'] ??= bin2hex(random_bytes(16));
$__authTenant = $acct;
$page_title = 'Welcome';
ob_start();
?>
<style>.auth-card{width:620px;}</style>
<?php if ($step === 'import'):
    $__impAccount = $accountId;
    $__impUrl = public_url('auth/welcome.php');
    $__impCsrf = $_SESSION['import_csrf']; ?>
  <div class="auth-title">Bring your shop's data</div>
  <div class="auth-sub">Step 3 of 3 · Moving from another POS? Upload its database export (.sql) and we'll bring in your
    products and stock, sales history, staff (with their PINs), suppliers, customers and more. New shop? Just skip.</div>
  <?php include __DIR__ . '/../components/shop_import.php'; ?>
  <a class="btn-auth mt-3" style="display:block;text-align:center;text-decoration:none" href="<?php echo public_url('super/dashboard/'); ?>">
    <?php echo !empty($__impResult) ? 'Go to my account' : 'Skip — go to my account'; ?></a>
<?php else:
    $canRequest = License::canRequestKey($accountId);
    $lic = License::state($accountId); ?>
  <div class="auth-title">You're all set 🎉</div>
  <div class="auth-sub"><strong><?php echo $h($acct['name']); ?></strong> is ready. This is your shop's POS address — bookmark it and share it with your staff:</div>
  <div class="input-group mb-2">
    <input class="form-control" id="posUrl" value="<?php echo $h($posUrl); ?>" readonly onclick="this.select()">
    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('posUrl').value);this.textContent='Copied'">Copy</button>
  </div>
  <button class="btn btn-success w-100 mb-1" type="button" id="installBtn" style="display:none"><i class="fas fa-download me-1"></i>Install the POS on this device</button>
  <p class="small text-muted" id="installHelp">To install: open the address above, then use your browser's menu → <em>Install app</em> / <em>Add to Home screen</em>.</p>

  <?php if ($lic['status'] !== 'active'): ?>
  <div class="auth-alert <?php echo $canRequest ? 'ok' : ''; ?>" style="background:#fff8e6;color:#7a5a00">
    <strong>Activate within 2 hours.</strong>
    <?php if ($canRequest): ?> Request your activation key — it's emailed to you straight away.
      <form method="post" action="<?php echo public_url('auth/activate-account.php'); ?>" class="mt-2">
        <input type="hidden" name="csrf" value="<?php echo $_SESSION['license_csrf']; ?>"><input type="hidden" name="action" value="request">
        <button class="btn btn-sm btn-primary">Request activation key</button></form>
    <?php else: ?> Your key was emailed to you. <a href="<?php echo public_url('auth/activate-account.php'); ?>">Enter it here</a>.<?php endif; ?>
  </div>
  <?php endif; ?>
  <a class="btn-auth mt-2" style="display:block;text-align:center;text-decoration:none" href="<?php echo $h($posUrl); ?>">Open my POS</a>
  <script>
  (function () {
    var btn = document.getElementById('installBtn'), ev = null;
    window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); ev = e; btn.style.display = ''; document.getElementById('installHelp').style.display = 'none'; });
    btn.addEventListener('click', function () { if (ev) { ev.prompt(); ev = null; btn.style.display = 'none'; } });
  })();
  </script>
<?php endif; ?>
<?php
$content = ob_get_clean();
include ROOT_PATH . '/public/templates/auth/layout.php';
