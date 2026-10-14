<?php
// public/support/settings/index.php — email (SMTP) settings used for activation
// keys, OTPs and receipts. Saved to app/config/mail.php (the file MailService reads).
require_once __DIR__ . '/../_boot.php';

$file = ROOT_PATH . '/app/config/mail.php';
$cfg = is_file($file) ? (include $file) : [];
$cfg = is_array($cfg) ? $cfg : [];
$cfg += ['host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => '',
         'from_email' => '', 'from_name' => 'Dazu Modern POS', 'skip_verify' => false];

if (support_posted()) {
    $action = $_POST['action'] ?? 'save';
    if ($action === 'save') {
        $new = [
            'host'        => trim($_POST['host'] ?? ''),
            'port'        => (int) ($_POST['port'] ?? 587),
            'encryption'  => in_array($_POST['encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $_POST['encryption'] : 'tls',
            'username'    => trim($_POST['username'] ?? ''),
            // Blank password keeps the saved one.
            'password'    => ($_POST['password'] ?? '') !== '' ? $_POST['password'] : $cfg['password'],
            'from_email'  => strtolower(trim($_POST['from_email'] ?? '')),
            'from_name'   => trim($_POST['from_name'] ?? '') ?: 'Dazu Modern POS',
            'skip_verify' => !empty($_POST['skip_verify']),
        ];
        if ($new['host'] === '' || !filter_var($new['from_email'], FILTER_VALIDATE_EMAIL)) {
            support_flash('SMTP host and a valid "from" email are required.', 'danger');
        } else {
            $php = "<?php\n// app/config/mail.php — SMTP settings. Edited from Support → Settings on " . date('Y-m-d H:i') . ".\n\nreturn " . var_export($new, true) . ";\n";
            if (@file_put_contents($file, $php, LOCK_EX) === false) {
                support_flash('Could not write app/config/mail.php — check the folder permissions.', 'danger');
            } else {
                if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
                support_flash('Email settings saved.');
            }
        }
    } elseif ($action === 'brand') {
        // Platform brand on the shared login / signup screens (Branding::platform()).
        $pfile = ROOT_PATH . '/app/config/platform.php';
        $brand = is_file($pfile) ? (include $pfile) : [];
        $brand = is_array($brand) ? $brand : [];
        $brand['name'] = trim($_POST['brand_name'] ?? '') ?: 'Dazu Modern POS';
        $err = '';
        $up = $_FILES['brand_logo'] ?? null;
        if ($up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
            $mime = $up['error'] === UPLOAD_ERR_OK ? (mime_content_type($up['tmp_name']) ?: '') : '';
            if (!isset($types[$mime]) || $up['size'] > 2 * 1024 * 1024) {
                $err = 'Logo must be a PNG, JPG, WEBP or SVG under 2 MB.';
            } else {
                $dir = ROOT_PATH . '/public/assets/uploads/platform';
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                $name = 'logo_' . bin2hex(random_bytes(4)) . '.' . $types[$mime];
                if (move_uploaded_file($up['tmp_name'], "$dir/$name")) {
                    $brand['logo'] = 'assets/uploads/platform/' . $name;
                } else {
                    $err = 'Could not save the logo — check folder permissions.';
                }
            }
        }
        if (!empty($_POST['brand_reset'])) unset($brand['logo']);
        if ($err) {
            support_flash($err, 'danger');
        } else {
            $php = "<?php\n// app/config/platform.php — platform brand for the login screens. Edited from Support → Settings on " . date('Y-m-d H:i') . ".\n\nreturn " . var_export($brand, true) . ";\n";
            if (@file_put_contents($pfile, $php, LOCK_EX) === false) {
                support_flash('Could not write app/config/platform.php — check the folder permissions.', 'danger');
            } else {
                if (function_exists('opcache_invalidate')) @opcache_invalidate($pfile, true);
                support_flash('Branding saved.');
            }
        }
    } elseif ($action === 'test') {
        $to = strtolower(trim($_POST['to'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            support_flash('Enter a valid email to send the test to.', 'danger');
        } else {
            $ok = (new MailService())->send($to, 'Test email — Dazu POS', '<p>Your SMTP settings work.</p>', 'Your SMTP settings work.');
            support_flash($ok ? "Test email sent to $to." : 'Test failed: ' . MailService::lastError(), $ok ? 'success' : 'danger');
        }
    }
    header('Location: ' . public_url('support/settings/'));
    exit;
}

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$brand = Branding::platform();
$page_title = 'Settings';
$nav = 'overview';
ob_start();
?>
<div class="row g-3" style="max-width:900px">
  <div class="col-md-7">
    <div class="s-card">
      <h2 class="h6 mb-1">SMTP (outgoing email)</h2>
      <p class="small text-muted">Used for activation keys, password-reset codes and emailed receipts. For Gmail use an <em>App password</em>, host <code>smtp.gmail.com</code>, port 587, TLS.</p>
      <form method="post" autocomplete="off">
        <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="save">
        <div class="row g-2">
          <div class="col-8"><label class="form-label small">Host</label><input class="form-control" name="host" value="<?php echo $h($cfg['host']); ?>" placeholder="mail.yourdomain.com" required></div>
          <div class="col-4"><label class="form-label small">Port</label><input class="form-control" name="port" type="number" value="<?php echo (int) $cfg['port']; ?>"></div>
          <div class="col-6"><label class="form-label small">Encryption</label>
            <select class="form-select" name="encryption"><?php foreach (['tls' => 'TLS (587)', 'ssl' => 'SSL (465)', 'none' => 'None'] as $k => $l): ?>
              <option value="<?php echo $k; ?>" <?php echo $cfg['encryption'] === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><label class="form-label small">Username</label><input class="form-control" name="username" value="<?php echo $h($cfg['username']); ?>" autocapitalize="off"></div>
          <div class="col-12"><label class="form-label small">Password <small class="text-muted"><?php echo $cfg['password'] !== '' ? '(saved — leave blank to keep)' : ''; ?></small></label>
            <input class="form-control" name="password" type="password" autocomplete="new-password"></div>
          <div class="col-6"><label class="form-label small">From email</label><input class="form-control" name="from_email" type="email" value="<?php echo $h($cfg['from_email']); ?>" required></div>
          <div class="col-6"><label class="form-label small">From name</label><input class="form-control" name="from_name" value="<?php echo $h($cfg['from_name']); ?>"></div>
          <div class="col-12"><label class="form-check small"><input class="form-check-input" type="checkbox" name="skip_verify" value="1" <?php echo $cfg['skip_verify'] ? 'checked' : ''; ?>>
            <span class="form-check-label">Skip SSL certificate check (only if your host's mail certificate is self-signed)</span></label></div>
        </div>
        <button class="btn btn-primary mt-3">Save</button>
      </form>
    </div>
  </div>
  <div class="col-md-5">
    <div class="s-card mb-3">
      <h2 class="h6 mb-1">Login screen branding</h2>
      <p class="small text-muted">Shown on the login, signup and password pages for every shop. Shops' own logos stay on their receipts and dashboards.</p>
      <form method="post" enctype="multipart/form-data">
        <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="brand">
        <div class="d-flex align-items-center gap-2 mb-2">
          <img src="<?php echo $h($brand['logo']); ?>" alt="" style="width:48px;height:48px;object-fit:contain;border-radius:10px;background:#f3f4f7">
          <input class="form-control form-control-sm" name="brand_name" value="<?php echo $h($brand['name']); ?>" placeholder="Platform name">
        </div>
        <input class="form-control form-control-sm mb-2" type="file" name="brand_logo" accept="image/png,image/jpeg,image/webp,image/svg+xml">
        <label class="form-check small mb-2"><input class="form-check-input" type="checkbox" name="brand_reset" value="1"><span class="form-check-label">Use the default logo</span></label>
        <button class="btn btn-sm btn-primary">Save branding</button>
      </form>
    </div>
    <div class="s-card">
      <h2 class="h6 mb-2">Send a test</h2>
      <form method="post" class="d-flex gap-2">
        <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="test">
        <input class="form-control form-control-sm" type="email" name="to" value="<?php echo $h(SupportAccount::email()); ?>" required>
        <button class="btn btn-sm btn-outline-primary text-nowrap">Send test</button>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../_layout.php';
