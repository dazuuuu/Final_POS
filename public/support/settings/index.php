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
    } elseif ($action === 'boot') {
        // Boot sound on the sign-in pages. The browser already trimmed the upload (a short WAV).
        $pfile = ROOT_PATH . '/app/config/platform.php';
        $brand = is_file($pfile) ? (include $pfile) : [];
        $brand = is_array($brand) ? $brand : [];
        $brand['boot_on'] = !empty($_POST['boot_on']);
        $err = '';
        if (!empty($_POST['boot_reset'])) {
            unset($brand['boot_sound']);
        }
        $up = $_FILES['boot_file'] ?? null;
        if ($up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $head = (string) @file_get_contents($up['tmp_name'], false, null, 0, 12);
            if ($up['size'] > 1024 * 1024 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') {
                $err = 'The sound could not be prepared — try another file (MP3, WAV, M4A or OGG).';
            } else {
                $dir = ROOT_PATH . '/public/assets/uploads/platform';
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                $name = 'boot_' . bin2hex(random_bytes(4)) . '.wav';
                if (move_uploaded_file($up['tmp_name'], "$dir/$name")) {
                    $brand['boot_sound'] = 'assets/uploads/platform/' . $name;
                } else {
                    $err = 'Could not save the sound — check folder permissions.';
                }
            }
        }
        if ($err) {
            support_flash($err, 'danger');
        } else {
            $php = "<?php\n// app/config/platform.php — platform brand for the login screens. Edited from Support → Settings on " . date('Y-m-d H:i') . ".\n\nreturn " . var_export($brand, true) . ";\n";
            if (@file_put_contents($pfile, $php, LOCK_EX) === false) {
                support_flash('Could not write app/config/platform.php — check the folder permissions.', 'danger');
            } else {
                if (function_exists('opcache_invalidate')) @opcache_invalidate($pfile, true);
                support_flash('Boot sound saved.');
            }
        }
    } elseif ($action === 'brand') {
        // Platform brand on the shared login / signup screens (Branding::platform()).
        $pfile = ROOT_PATH . '/app/config/platform.php';
        $brand = is_file($pfile) ? (include $pfile) : [];
        $brand = is_array($brand) ? $brand : [];
        $brand['name'] = trim($_POST['brand_name'] ?? '') ?: 'Dazu Modern POS';
        foreach (['phone', 'whatsapp', 'email'] as $c) { $brand[$c] = trim((string) ($_POST['brand_' . $c] ?? '')); }
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
    } elseif ($action === 'stock_login') {
        // Picture + welcome word of the Stock Control sign-in page (Branding::platform()).
        $pfile = ROOT_PATH . '/app/config/platform.php';
        $brand = is_file($pfile) ? (include $pfile) : [];
        $brand = is_array($brand) ? $brand : [];
        $err = '';
        $brand['stock_welcome'] = mb_substr(trim((string) ($_POST['stock_welcome'] ?? '')), 0, 24);
        $up = $_FILES['stock_login_img'] ?? null;
        if (!empty($_POST['stock_login_reset'])) {
            unset($brand['stock_login_img']);
        } elseif ($up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
            $mime = $up['error'] === UPLOAD_ERR_OK ? (mime_content_type($up['tmp_name']) ?: '') : '';
            if (!isset($types[$mime]) || $up['size'] > 8 * 1024 * 1024) {
                $err = 'The picture must be a JPG, PNG or WEBP under 8 MB.';
            } else {
                $dir = ROOT_PATH . '/public/assets/uploads/platform';
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                $name = 'stock_login_' . bin2hex(random_bytes(4)) . '.' . $types[$mime];
                if (move_uploaded_file($up['tmp_name'], "$dir/$name")) {
                    $brand['stock_login_img'] = 'assets/uploads/platform/' . $name;
                } else {
                    $err = 'Could not save the picture — check folder permissions.';
                }
            }
        }
        if ($err) {
            support_flash($err, 'danger');
        } else {
            $php = "<?php\n// app/config/platform.php — platform brand for the login screens. Edited from Support → Settings on " . date('Y-m-d H:i') . ".\n\nreturn " . var_export($brand, true) . ";\n";
            if (@file_put_contents($pfile, $php, LOCK_EX) === false) {
                support_flash('Could not write app/config/platform.php — check the folder permissions.', 'danger');
            } else {
                if (function_exists('opcache_invalidate')) @opcache_invalidate($pfile, true);
                support_flash('Stock Control sign-in page saved.');
            }
        }
    } elseif ($action === 'login_bg') {
        // Background picture of the sign-in screen on phones (Branding::platform()['login_bg']).
        $pfile = ROOT_PATH . '/app/config/platform.php';
        $brand = is_file($pfile) ? (include $pfile) : [];
        $brand = is_array($brand) ? $brand : [];
        $err = '';
        $up = $_FILES['login_bg'] ?? null;
        if (!empty($_POST['login_bg_reset'])) {
            unset($brand['login_bg']);
        } elseif ($up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
            $mime = $up['error'] === UPLOAD_ERR_OK ? (mime_content_type($up['tmp_name']) ?: '') : '';
            if (!isset($types[$mime]) || $up['size'] > 6 * 1024 * 1024) {
                $err = 'The background must be a JPG, PNG or WEBP picture under 6 MB.';
            } else {
                $dir = ROOT_PATH . '/public/assets/uploads/platform';
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                $name = 'login_bg_' . bin2hex(random_bytes(4)) . '.' . $types[$mime];
                if (move_uploaded_file($up['tmp_name'], "$dir/$name")) {
                    $brand['login_bg'] = 'assets/uploads/platform/' . $name;
                } else {
                    $err = 'Could not save the picture — check folder permissions.';
                }
            }
        } else {
            $err = 'Choose a picture first.';
        }
        if ($err) {
            support_flash($err, 'danger');
        } else {
            $php = "<?php\n// app/config/platform.php — platform brand for the login screens. Edited from Support → Settings on " . date('Y-m-d H:i') . ".\n\nreturn " . var_export($brand, true) . ";\n";
            if (@file_put_contents($pfile, $php, LOCK_EX) === false) {
                support_flash('Could not write app/config/platform.php — check the folder permissions.', 'danger');
            } else {
                if (function_exists('opcache_invalidate')) @opcache_invalidate($pfile, true);
                support_flash(!empty($_POST['login_bg_reset']) ? 'Login background removed — phones show the dark gradient.' : 'Login background saved. Open the sign-in page on a phone to see it.');
            }
        }
    } elseif ($action === 'seo') {
        // Website sharing & search wording (Branding::platform()['seo_*'], used by Seo).
        $pfile = ROOT_PATH . '/app/config/platform.php';
        $brand = is_file($pfile) ? (include $pfile) : [];
        $brand = is_array($brand) ? $brand : [];
        $err = '';
        $brand['seo_title'] = mb_substr(trim((string) ($_POST['seo_title'] ?? '')), 0, 70);
        $brand['seo_description'] = mb_substr(trim((string) ($_POST['seo_description'] ?? '')), 0, 200);
        $url = rtrim(trim((string) ($_POST['seo_url'] ?? '')), '/');
        if ($url !== '' && !preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#i', $url)) {
            $err = 'Website address must look like https://pos.dazuhub.com (no path).';
        }
        $brand['seo_url'] = $url;
        $up = $_FILES['seo_image'] ?? null;
        if (!empty($_POST['seo_image_reset'])) {
            unset($brand['seo_image']);
        } elseif (!$err && $up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $types = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
            $mime = $up['error'] === UPLOAD_ERR_OK ? (mime_content_type($up['tmp_name']) ?: '') : '';
            if (!isset($types[$mime]) || $up['size'] > 5 * 1024 * 1024) {
                $err = 'The share picture must be a JPG or PNG under 5 MB (1200 × 630 is ideal).';
            } else {
                $dir = ROOT_PATH . '/public/assets/uploads/platform';
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                $name = 'share_' . bin2hex(random_bytes(4)) . '.' . $types[$mime];
                if (move_uploaded_file($up['tmp_name'], "$dir/$name")) {
                    $brand['seo_image'] = 'assets/uploads/platform/' . $name;
                } else {
                    $err = 'Could not save the picture — check folder permissions.';
                }
            }
        }
        if ($err) {
            support_flash($err, 'danger');
        } else {
            $php = "<?php\n// app/config/platform.php — platform brand for the login screens. Edited from Support → Settings on " . date('Y-m-d H:i') . ".\n\nreturn " . var_export($brand, true) . ";\n";
            if (@file_put_contents($pfile, $php, LOCK_EX) === false) {
                support_flash('Could not write app/config/platform.php — check the folder permissions.', 'danger');
            } else {
                if (function_exists('opcache_invalidate')) @opcache_invalidate($pfile, true);
                support_flash('Website sharing & search details saved.');
            }
        }
    } elseif ($action === 'look') {
        // Landing hero colour / background and the side picture of the sign-in pages (Branding hero_*, login_media).
        $pfile = ROOT_PATH . '/app/config/platform.php';
        $brand = is_file($pfile) ? (include $pfile) : [];
        $brand = is_array($brand) ? $brand : [];
        $err = '';
        $color = strtolower(trim((string) ($_POST['hero_color'] ?? '')));
        if (!empty($_POST['hero_color_reset']) || $color === '') {
            unset($brand['hero_color']);
        } elseif (preg_match('/^#[0-9a-f]{6}$/', $color)) {
            $brand['hero_color'] = $color;
        } else {
            $err = 'Pick the hero colour with the colour box.';
        }
        $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mp4'];
        foreach (['hero_media' => 'hero', 'login_media' => 'login'] as $key => $pre) {
            if ($err) break;
            $link = trim((string) ($_POST[$key . '_link'] ?? ''));
            $up = $_FILES[$key] ?? null;
            if (!empty($_POST[$key . '_reset'])) {
                unset($brand[$key]);
            } elseif ($up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $mime = $up['error'] === UPLOAD_ERR_OK ? (mime_content_type($up['tmp_name']) ?: '') : '';
                if ($up['error'] === UPLOAD_ERR_INI_SIZE || $up['error'] === UPLOAD_ERR_FORM_SIZE) {
                    $err = 'That file is bigger than the server allows (' . ini_get('upload_max_filesize') . '). Use a shorter / smaller video, or paste a YouTube link instead.';
                } elseif (!isset($types[$mime]) || $up['size'] > 80 * 1024 * 1024) {
                    $err = 'Upload a JPG, PNG, WEBP picture or an MP4 / WEBM video under 80 MB — or paste a link.';
                } else {
                    $dir = ROOT_PATH . '/public/assets/uploads/platform';
                    if (!is_dir($dir)) @mkdir($dir, 0775, true);
                    $name = $pre . '_media_' . bin2hex(random_bytes(4)) . '.' . $types[$mime];
                    if (move_uploaded_file($up['tmp_name'], "$dir/$name")) {
                        $brand[$key] = 'assets/uploads/platform/' . $name;
                    } else {
                        $err = 'Could not save the file — check folder permissions.';
                    }
                }
            } elseif ($link !== '') {
                if (!preg_match('#^https://[^\s<>"\']+$#i', $link)) {
                    $err = 'Paste a full https:// link (YouTube, a .mp4 / .webm video, or a picture).';
                } else {
                    $brand[$key] = $link;
                }
            }
        }
        if ($err) {
            support_flash($err, 'danger');
        } else {
            $php = "<?php\n// app/config/platform.php — platform brand for the login screens. Edited from Support → Settings on " . date('Y-m-d H:i') . ".\n\nreturn " . var_export($brand, true) . ";\n";
            if (@file_put_contents($pfile, $php, LOCK_EX) === false) {
                support_flash('Could not write app/config/platform.php — check the folder permissions.', 'danger');
            } else {
                if (function_exists('opcache_invalidate')) @opcache_invalidate($pfile, true);
                support_flash('Look saved — open the landing page and the sign-in pages to see it.');
            }
        }
    } elseif ($action === 'org_create' && SupportOrg::isPlatformAdmin()) {
        [$org, $err] = SupportOrg::create((string) ($_POST['company'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
        support_flash($err ?? 'Support company created. Sign in at the support login with that email and password — it has its own accounts and team, hidden from every other company.', $err ? 'danger' : 'success');
    } elseif ($action === 'org_invite' && SupportOrg::isPlatformAdmin()) {
        $_SESSION['org_invite_link'] = SupportOrg::invite((string) ($_POST['note'] ?? ''));
        support_flash('Registration link created — send it to the partner. It works once, for 7 days.');
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
      <p class="small text-muted">Shown on this support console and on the shared login, signup and password pages. Each shop's own logo stays on its own pages, receipts and dashboards.</p>
      <form method="post" enctype="multipart/form-data">
        <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="brand">
        <div class="d-flex align-items-center gap-2 mb-2">
          <img src="<?php echo $h($brand['logo']); ?>" alt="" style="width:48px;height:48px;object-fit:contain;border-radius:10px;background:#f3f4f7">
          <input class="form-control form-control-sm" name="brand_name" value="<?php echo $h($brand['name']); ?>" placeholder="Platform name">
        </div>
        <div class="row g-2 mb-2">
          <div class="col-md-4"><label class="form-label small mb-0">Sales phone</label><input class="form-control form-control-sm" name="brand_phone" value="<?php echo $h($brand['phone']); ?>" placeholder="0712 345 678"></div>
          <div class="col-md-4"><label class="form-label small mb-0">WhatsApp</label><input class="form-control form-control-sm" name="brand_whatsapp" value="<?php echo $h($brand['whatsapp']); ?>" placeholder="254712345678"></div>
          <div class="col-md-4"><label class="form-label small mb-0">Sales email</label><input class="form-control form-control-sm" type="email" name="brand_email" value="<?php echo $h($brand['email']); ?>" placeholder="sales@…"></div>
          <div class="col-12 form-text mt-0">Shown on the landing page ("Talk to us"); quote requests are emailed to the sales email (else the support login email).</div>
        </div>
        <input class="form-control form-control-sm mb-2" type="file" name="brand_logo" accept="image/png,image/jpeg,image/webp,image/svg+xml">
        <label class="form-check small mb-2"><input class="form-check-input" type="checkbox" name="brand_reset" value="1"><span class="form-check-label">Use the default logo</span></label>
        <button class="btn btn-sm btn-primary">Save branding</button>
      </form>
    </div>
    <div class="s-card mb-3">
      <h2 class="h6 mb-1"><i class="fas fa-boxes-stacked me-1 text-primary"></i>Login forms — Stock Control</h2>
      <p class="small text-muted">The big picture on the left of every Stock Control organisation's sign-in page, with a welcome word over it. Each organisation's own name and logo appear on the form beside it. A tall picture (about 3:4) works best.</p>
      <form method="post" enctype="multipart/form-data">
        <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="stock_login">
        <div class="d-flex align-items-center gap-2 mb-2">
          <div style="width:72px;height:96px;border-radius:10px;flex:0 0 auto;background:<?php echo $brand['stock_login_img'] !== '' ? 'url(' . $h(json_encode($brand['stock_login_img'])) . ') center/cover' : 'linear-gradient(160deg,#0b1650,#1d4ed8 60%,#22c55e)'; ?>;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.55rem;letter-spacing:.2em;font-weight:600"><?php echo $h(strtoupper($brand['stock_welcome'])); ?></div>
          <div class="small">Now: <strong><?php echo $brand['stock_login_img'] !== '' ? 'your picture' : 'the built-in artwork (no picture uploaded)'; ?></strong></div>
        </div>
        <input class="form-control form-control-sm mb-2" type="file" name="stock_login_img" accept="image/jpeg,image/png,image/webp">
        <label class="form-label small mb-0">Welcome word over the picture</label>
        <input class="form-control form-control-sm mb-2" name="stock_welcome" maxlength="24" value="<?php echo $h($brand['stock_welcome']); ?>" placeholder="Welcome">
        <?php if ($brand['stock_login_img'] !== ''): ?><label class="form-check small mb-2"><input class="form-check-input" type="checkbox" name="stock_login_reset" value="1"><span class="form-check-label">Remove the picture (use the built-in artwork)</span></label><?php endif; ?>
        <button class="btn btn-sm btn-primary">Save sign-in page</button>
      </form>
    </div>
    <div class="s-card mb-3">
      <h2 class="h6 mb-1">Look — landing hero &amp; sign-in pictures</h2>
      <p class="small text-muted">Make the public page and the owner / staff sign-in pages match the season. A picture or video can be uploaded or linked (YouTube plays muted, in a loop, behind the text). Leave a box empty to keep what is there now.</p>
      <form method="post" enctype="multipart/form-data">
        <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="look">
        <div class="d-flex align-items-center gap-2 mb-3">
          <label class="small mb-0">Hero colour</label>
          <input type="color" name="hero_color" value="<?php echo $h($brand['hero_color'] !== '' ? $brand['hero_color'] : '#167d4c'); ?>" style="width:48px;height:32px;padding:0;border:0">
          <?php if ($brand['hero_color'] !== ''): ?><label class="small"><input type="checkbox" name="hero_color_reset" value="1"> back to green</label><?php else: ?><span class="small text-muted">(green until you save)</span><?php endif; ?>
        </div>
        <div class="border rounded p-2 mb-3">
          <div class="fw-semibold small mb-1">Landing hero background</div>
          <div class="small text-muted mb-2">Behind the headline at the top of the public page (over the colour). A video should be short, 1080p or less, and under about 15 MB so it loads fast.</div>
          <?php if ($brand['hero_media'] !== ''): ?><div class="small mb-2">Now: <strong><?php echo ['youtube' => 'YouTube video', 'video' => 'video', 'image' => 'picture'][SiteMedia::kind($brand['hero_media'])] ?? ''; ?></strong>
            — <a href="<?php echo $h($brand['hero_media']); ?>" target="_blank" rel="noopener">view</a>
            <label class="ms-2"><input type="checkbox" name="hero_media_reset" value="1"> remove</label></div><?php endif; ?>
          <input class="form-control form-control-sm mb-1" type="file" name="hero_media" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm">
          <input class="form-control form-control-sm" name="hero_media_link" placeholder="…or paste a link: https://youtu.be/…  or  https://…/video.mp4  or a picture link">
        </div>
        <div class="border rounded p-2 mb-3">
          <div class="fw-semibold small mb-1">Side picture — owner / admin login and staff terminal</div>
          <div class="small text-muted mb-2">Fills the left panel on computers and tablets; the sign-in form is not touched. (On phones the “Mobile login background” below is used.)</div>
          <?php if ($brand['login_media'] !== ''): ?><div class="small mb-2">Now: <strong><?php echo ['youtube' => 'YouTube video', 'video' => 'video', 'image' => 'picture'][SiteMedia::kind($brand['login_media'])] ?? ''; ?></strong>
            — <a href="<?php echo $h($brand['login_media']); ?>" target="_blank" rel="noopener">view</a>
            <label class="ms-2"><input type="checkbox" name="login_media_reset" value="1"> remove</label></div><?php endif; ?>
          <input class="form-control form-control-sm mb-1" type="file" name="login_media" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm">
          <input class="form-control form-control-sm" name="login_media_link" placeholder="…or paste a link: https://youtu.be/…  or  https://…/video.mp4  or a picture link">
        </div>
        <button class="btn btn-sm btn-primary">Save look</button>
      </form>
    </div>
    <div class="s-card mb-3">
      <h2 class="h6 mb-1">Website sharing &amp; SEO</h2>
      <p class="small text-muted">What Google shows and what WhatsApp / Facebook / X display when the link is shared. Leave a box empty to use the built-in wording and picture. After saving, test the link at the Facebook Sharing Debugger.</p>
      <form method="post" enctype="multipart/form-data">
        <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="seo">
        <label class="form-label small mb-0">Page title (max 70)</label>
        <input class="form-control form-control-sm mb-2" name="seo_title" maxlength="70" value="<?php echo $h($brand['seo_title']); ?>" placeholder="<?php echo $h(Seo::title()); ?>">
        <label class="form-label small mb-0">Description (max 200 — about 155 shows in Google)</label>
        <textarea class="form-control form-control-sm mb-2" name="seo_description" rows="3" maxlength="200" placeholder="<?php echo $h(Seo::description()); ?>"><?php echo $h($brand['seo_description']); ?></textarea>
        <label class="form-label small mb-0">Website address</label>
        <input class="form-control form-control-sm mb-2" name="seo_url" value="<?php echo $h($brand['seo_url']); ?>" placeholder="<?php echo $h(Seo::origin()); ?>">
        <label class="form-label small mb-0">Share picture (JPG/PNG, 1200 × 630)</label>
        <div class="mb-2"><img src="<?php echo $h(Seo::image()[0]); ?>" alt="" style="max-width:240px;border-radius:8px;border:1px solid #ddd"></div>
        <input class="form-control form-control-sm mb-2" type="file" name="seo_image" accept="image/jpeg,image/png">
        <?php if ($brand['seo_image'] !== ''): ?><label class="form-check small mb-2"><input class="form-check-input" type="checkbox" name="seo_image_reset" value="1"><span class="form-check-label">Go back to the built-in picture</span></label><?php endif; ?>
        <button class="btn btn-sm btn-primary">Save</button>
        <a class="btn btn-sm btn-outline-secondary" href="<?php echo $h(Seo::abs('sitemap.xml')); ?>" target="_blank" rel="noopener">View sitemap</a>
      </form>
    </div>
    <div class="s-card mb-3">
      <h2 class="h6 mb-1">Mobile login background</h2>
      <p class="small text-muted">The picture behind the sign-in screen on phones (owner login and support login), under a dark tint so the white fields stay readable. A tall photo works best. Computers keep their usual sign-in page.</p>
      <form method="post" enctype="multipart/form-data">
        <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="login_bg">
        <div class="d-flex align-items-center gap-2 mb-2">
          <div style="width:54px;height:96px;border-radius:10px;flex:0 0 auto;background:linear-gradient(135deg,rgba(0,38,40,.82) 50%,rgba(0,26,38,.9) 50%)<?php echo $brand['login_bg'] !== '' ? ', url(' . $h(json_encode($brand['login_bg'])) . ') center/cover' : ''; ?>,#00262c;"></div>
          <div class="small">Now: <strong><?php echo $brand['login_bg'] !== '' ? 'your picture' : 'dark gradient (no picture)'; ?></strong></div>
        </div>
        <input class="form-control form-control-sm mb-2" type="file" name="login_bg" accept="image/jpeg,image/png,image/webp">
        <?php if ($brand['login_bg'] !== ''): ?><label class="form-check small mb-2"><input class="form-check-input" type="checkbox" name="login_bg_reset" value="1"><span class="form-check-label">Remove the picture</span></label><?php endif; ?>
        <button class="btn btn-sm btn-primary">Save background</button>
      </form>
    </div>
    <div class="s-card mb-3">
      <h2 class="h6 mb-1">Boot sound</h2>
      <p class="small text-muted">Plays with the start-up screen when the POS sign-in opens (owner login and staff terminal). Choose any sound —
        it is trimmed here automatically: silence at the start removed, at most 3 seconds kept, the end faded out and the volume evened out.</p>
      <form method="post" enctype="multipart/form-data" id="bootForm">
        <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="boot">
        <label class="form-check form-switch small mb-2"><input class="form-check-input" type="checkbox" name="boot_on" value="1" <?php echo $brand['boot_on'] ? 'checked' : ''; ?>>
          <span class="form-check-label">Play a sound when the POS starts</span></label>
        <div class="small mb-2">Now: <strong><?php echo $brand['boot_sound'] !== '' ? 'your sound' : 'built-in chime'; ?></strong>
          <button type="button" class="btn btn-sm btn-outline-secondary ms-2" id="bootPlayNow"><i class="fas fa-play me-1"></i>Play</button></div>
        <input class="form-control form-control-sm mb-1" type="file" id="bootPick" accept="audio/*">
        <input type="file" name="boot_file" id="bootFile" hidden>
        <div class="small text-muted mb-2" id="bootInfo"></div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <button type="button" class="btn btn-sm btn-outline-secondary" id="bootPreview" disabled><i class="fas fa-play me-1"></i>Preview trimmed</button>
          <button class="btn btn-sm btn-primary">Save boot sound</button>
          <?php if ($brand['boot_sound'] !== ''): ?><label class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="boot_reset" value="1"><span class="form-check-label">Use the built-in chime</span></label><?php endif; ?>
        </div>
      </form>
    </div>
    <script src="<?php echo $h(public_url('assets/js/boot-chime.js')); ?>"></script>
    <script>
    (function () {
      var MAX = 3.0, FADE = 0.45, RATE = 32000, trimmed = null;
      var pick = document.getElementById('bootPick'), file = document.getElementById('bootFile'), info = document.getElementById('bootInfo'), prev = document.getElementById('bootPreview');
      var current = <?php echo json_encode($brand['boot_sound']); ?>;
      function wav(buf) { // mono 16-bit PCM WAV
        var d = buf.getChannelData(0), n = d.length, b = new ArrayBuffer(44 + n * 2), v = new DataView(b), p = 0;
        function s(str) { for (var i = 0; i < str.length; i++) v.setUint8(p++, str.charCodeAt(i)); }
        s('RIFF'); v.setUint32(p, 36 + n * 2, true); p += 4; s('WAVEfmt '); v.setUint32(p, 16, true); p += 4; v.setUint16(p, 1, true); p += 2; v.setUint16(p, 1, true); p += 2;
        v.setUint32(p, buf.sampleRate, true); p += 4; v.setUint32(p, buf.sampleRate * 2, true); p += 4; v.setUint16(p, 2, true); p += 2; v.setUint16(p, 16, true); p += 2;
        s('data'); v.setUint32(p, n * 2, true); p += 4;
        for (var i = 0; i < n; i++, p += 2) { var x = Math.max(-1, Math.min(1, d[i])); v.setInt16(p, x < 0 ? x * 0x8000 : x * 0x7fff, true); }
        return new Blob([b], { type: 'audio/wav' });
      }
      pick.addEventListener('change', function () {
        var f = pick.files[0]; if (!f) return;
        info.textContent = 'Preparing…';
        f.arrayBuffer().then(function (ab) {
          var AC = window.AudioContext || window.webkitAudioContext;
          return new AC().decodeAudioData(ab);
        }).then(function (src) {
          // Mix to mono, skip leading silence, keep MAX seconds.
          var ch = src.numberOfChannels, len = src.length, mono = new Float32Array(len);
          for (var c = 0; c < ch; c++) { var x = src.getChannelData(c); for (var i = 0; i < len; i++) mono[i] += x[i] / ch; }
          var start = 0; while (start < len && Math.abs(mono[start]) < 0.01) start++;
          var count = Math.min(len - start, Math.round(MAX * src.sampleRate));
          if (count <= 0) throw new Error('silent');
          var off = new OfflineAudioContext(1, Math.ceil(count / src.sampleRate * RATE), RATE);
          var b = off.createBuffer(1, count, src.sampleRate); b.copyToChannel(mono.subarray(start, start + count), 0);
          var node = off.createBufferSource(); node.buffer = b; node.connect(off.destination); node.start();
          return off.startRendering();
        }).then(function (out) {
          var d = out.getChannelData(0), n = d.length, peak = 0, fadeN = Math.min(n, Math.round(FADE * out.sampleRate)), inN = Math.round(0.01 * out.sampleRate);
          for (var i = 0; i < n; i++) peak = Math.max(peak, Math.abs(d[i]));
          var g = peak > 0 ? 0.9 / peak : 1;
          for (var j = 0; j < n; j++) {
            var e = j < inN ? j / inN : 1, tail = n - j; if (tail < fadeN) e *= tail / fadeN;
            d[j] *= g * e;
          }
          trimmed = wav(out);
          var dt = new DataTransfer(); dt.items.add(new File([trimmed], 'boot.wav', { type: 'audio/wav' })); file.files = dt.files;
          info.textContent = 'Ready: ' + (n / out.sampleRate).toFixed(1) + ' s, ' + Math.round(trimmed.size / 1024) + ' KB. Preview it, then save.';
          prev.disabled = false;
        }).catch(function () { info.textContent = 'This file could not be read as audio. Try MP3, WAV, M4A or OGG.'; trimmed = null; prev.disabled = true; });
      });
      prev.addEventListener('click', function () { if (trimmed) new Audio(URL.createObjectURL(trimmed)).play(); });
      document.getElementById('bootPlayNow').addEventListener('click', function () {
        if (current) { new Audio(current).play(); return; }
        var AC = window.AudioContext || window.webkitAudioContext; if (AC) window.dzChime(new AC());
      });
    })();
    </script>
    <div class="s-card">
      <h2 class="h6 mb-2">Send a test</h2>
      <form method="post" class="d-flex gap-2">
        <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="test">
        <input class="form-control form-control-sm" type="email" name="to" value="<?php echo $h(SupportAccount::email()); ?>" required>
        <button class="btn btn-sm btn-outline-primary text-nowrap">Send test</button>
      </form>
    </div>
    <?php if (SupportOrg::isPlatformAdmin()): $orgLink = $_SESSION['org_invite_link'] ?? null; unset($_SESSION['org_invite_link']); ?>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../_layout.php';
