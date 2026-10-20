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
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../_layout.php';
