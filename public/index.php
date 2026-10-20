<?php
// public/index.php — the staff terminal. Type your PIN, then Login, Clock In
// or Clock Out. Owners go to /admin instead.
// domain/<shop-slug>/<page> lands here (no such file) — run that page.
require_once __DIR__ . '/../app/helpers/ShopUrl.php';
if ($__shopPage = ShopUrl::dispatch()) {
    require $__shopPage;
    exit;
}
require_once __DIR__ . '/../app/app.php';

// Already fully logged in? Skip straight to the dashboard.
if (!empty($_SESSION['logged_in']) && !empty($_SESSION['otp_verified'])) {
    $dest = ($_SESSION['role'] ?? '') === 'staff' ? public_url('staff/dashboard/') : public_url('super/dashboard/');
    header('Location: ' . $dest);
    exit;
}

$error = '';
$notice = '';

// Single-tenant deployment: there's only ever one shop, so its branding can
// be shown before anyone logs in. No database / no tables yet (fresh install
// or just reset) → send them to the setup wizard instead of an error page.
// Opened at domain/<slug>/ → that shop's terminal (its name, logo and staff
// only). Opened at the plain domain → the shared terminal with the platform
// brand, open to every shop's staff.
$shopSlug = ShopUrl::requestSlug();

// The plain domain is the public landing page (product, packages, demo, quote).
// Staff terminals: domain/terminal/ (shared) and domain/<slug>/ (a shop's own).
if ($shopSlug === null && !defined('DAZU_TERMINAL')) {
    require __DIR__ . '/components/landing/page.php';
    exit;
}
try {
    $pdo = Database::pdo();
    $shop = $shopSlug !== null ? (new Models\TenantModel($pdo))->primary() : null;
    if ($shopSlug === null) {
        $pdo->query('SELECT 1 FROM tenants LIMIT 1'); // no tables yet → setup wizard
    }
} catch (Throwable $e) {
    header('Location: ' . public_url('setup/'));
    exit;
}
if ($shop) {
    $shopName = $shop['name'];
    $shopLogo = Branding::shopLogo($shop);
} else {
    $__brand = Branding::platform();
    $shopName = $__brand['name'] ?: 'Dazu Modern POS';
    $shopLogo = $__brand['logo'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pin    = trim($_POST['pin'] ?? '');
    $action = in_array($_POST['action'] ?? '', ['login', 'clock_in', 'clock_out'], true) ? $_POST['action'] : 'login';

    $stmt = $pdo->prepare(
        "SELECT u.*, r.role_name FROM users u JOIN roles r ON r.id = u.role_id
           JOIN tenants t ON t.id = u.tenant_id
          WHERE r.role_name = 'staff' AND u.is_active = 1 AND u.pin_hash IS NOT NULL AND t.status = 'active'"
        // Opened through a shop's address: only that shop's (and its branches') staff.
        . (!empty($shop['id'])
            ? ' AND (t.id = ' . (int) $shop['id'] . ' OR t.parent_tenant_id = ' . (int) $shop['id'] . ')' : '')
    );
    $stmt->execute();
    $user = null;
    foreach ($stmt->fetchAll() as $row) {
        if (password_verify($pin, $row['pin_hash'])) {
            $user = $row;
            break;
        }
    }

    if (!$user) {
        $error = 'Wrong PIN. Try again.';
    } elseif ($action === 'clock_in') {
        $res = (new Models\TimeLogModel($pdo))->clockIn((int) $user['tenant_id'], (int) $user['id']);
        if ($res['ok']) { $notice = htmlspecialchars($user['username']) . ' clocked in at ' . $res['at'] . '.'; }
        else { $error = $res['error']; }
    } elseif ($action === 'clock_out') {
        $res = (new Models\TimeLogModel($pdo))->clockOut((int) $user['tenant_id'], (int) $user['id']);
        if ($res['ok']) { $notice = htmlspecialchars($user['username']) . ' clocked out at ' . $res['at'] . '.'; }
        else { $error = $res['error']; }
    } elseif (!(new Models\TimeLogModel($pdo))->hasClockedInToday((int) $user['tenant_id'], (int) $user['id'])) {
        $error = 'Clock in first — tap Clock In above before logging in.';
    } else { // login
        session_regenerate_id(true);
        TenantContext::establish($pdo, $user);
        UserSessions::start();
        $_SESSION['username']     = $user['username'];
        $_SESSION['logged_in']    = true;
        $_SESSION['otp_verified'] = true;
        $_SESSION['must_reset']   = false;
        header('Location: ' . public_url('staff/dashboard/'));
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo htmlspecialchars($shopName); ?> — Staff terminal</title>
<?php include __DIR__ . '/components/pwa_head.php'; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
  *{box-sizing:border-box;}
  body{min-height:100svh;margin:0;display:flex;align-items:center;justify-content:center;
       background:#f7f7fb;font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif;
       padding:24px;padding-top:max(24px,env(safe-area-inset-top));padding-bottom:max(24px,env(safe-area-inset-bottom));}
  .panel{width:100%;max-width:640px;background:#fff;border-radius:14px;
         border:1px solid #e4e4e7;display:flex;overflow:hidden;}
  .msg{width:100%;max-width:640px;margin:0 auto 14px;border-radius:10px;padding:10px 16px;font-size:.88rem;text-align:center;}
  .msg.err{background:#fee2e2;color:#991b1b;}
  .msg.ok{background:#dcfce7;color:#166534;}
  .wrap{width:100%;display:flex;flex-direction:column;align-items:center;}

  .pad{flex:1 1 58%;padding:26px;}
  .pad-display{background:#f7f7fb;border:1px solid #eef0f4;border-radius:12px;padding:14px;text-align:center;
               font-size:1.6rem;letter-spacing:.5em;color:#1f2330;margin-bottom:18px;min-height:56px;}
  .pad-display.placeholder{color:#b7bac3;letter-spacing:normal;font-size:1rem;}
  .keys{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
  .key{padding:16px 0;font-size:1.25rem;font-weight:700;border:1px solid #eef0f4;border-radius:12px;
       background:#f7f7fb;color:#1f2330;}
  .key:active{background:#eef0f4;}

  .side{flex:1 1 42%;background:#f0fdf4;border-left:1px solid #eef0f4;padding:26px;display:flex;flex-direction:column;justify-content:center;}
  .brand{text-align:center;margin-bottom:22px;}
  .brand-icon{width:56px;height:56px;border-radius:14px;overflow:hidden;background:#fff;border:1px solid #bbf0d1;
              display:flex;align-items:center;justify-content:center;margin:0 auto 10px;color:#16a34a;font-size:1.4rem;}
  .brand-icon img{width:100%;height:100%;object-fit:contain;}
  .brand h1{color:#1f2330;font-size:1.1rem;font-weight:800;margin:0 0 2px;letter-spacing:-.02em;}
  .brand p{color:#9aa0ac;font-size:.76rem;margin:0;text-transform:uppercase;letter-spacing:.1em;}
  .actions{display:flex;flex-direction:column;gap:10px;}
  .act{border:0;border-radius:12px;padding:15px;font-weight:800;font-size:.92rem;letter-spacing:.02em;cursor:pointer;}
  .act i{margin-right:6px;}
  .act-login{background:#16a34a;color:#fff;}
  .act-in{background:#2563eb;color:#fff;}
  .act-out{background:#fff;color:#1f2330;border:1px solid #e2e4ea;}
  .act-owner{display:block;order:9;margin-top:8px;padding:0;background:none;color:#9aa0ac;font-size:.78rem;font-weight:400;
             letter-spacing:0;text-align:center;text-decoration:none;}
  .act-owner:hover{color:#16a34a;}

  /* Phones: one centred card — brand, then the buttons at the top
     (Login | Owner login, Clock In | Clock Out), then the PIN pad. */
  @media (max-width: 560px){
    body{padding:16px;}
    .panel{flex-direction:column;border-radius:18px;max-width:420px;padding:20px 18px;gap:16px;}
    .side{display:contents;}
    .brand{order:0;display:flex;align-items:center;justify-content:center;gap:10px;margin:0;text-align:left;}
    .brand-icon{width:42px;height:42px;border-radius:11px;margin:0;}
    .brand h1{font-size:1rem;}
    .brand p{font-size:.68rem;}
    .pad{order:2;padding:0;}
    .pad-display{margin-bottom:14px;}
    .key{padding:14px 0;}
    #pinForm{order:1;}
    .actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
    .act{padding:14px 8px;font-size:.88rem;}
    .act-login{order:0;}
    .act-owner{order:1;display:flex;align-items:center;justify-content:center;margin:0;border-radius:12px;
               background:#fff;color:#16a34a;border:1px solid #bbf0d1;font-weight:800;font-size:.88rem;}
    .act-in{order:2;}
    .act-out{order:3;}
  }
</style>
</head>
<body>
<?php include __DIR__ . '/components/boot_splash.php'; ?>
<div class="wrap">
  <?php if ($error): ?><div class="msg err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <?php if ($notice): ?><div class="msg ok"><?php echo $notice; ?></div><?php endif; ?>

  <div class="panel">
    <div class="pad">
      <div class="pad-display placeholder" id="pinDisplay">Enter PIN</div>
      <div class="keys" id="keys">
        <?php foreach ([1,2,3,4,5,6,7,8,9] as $n): ?><button type="button" class="key" data-digit="<?php echo $n; ?>"><?php echo $n; ?></button><?php endforeach; ?>
        <button type="button" class="key" id="pinBack"><i class="fas fa-delete-left"></i></button>
        <button type="button" class="key" data-digit="0">0</button>
        <button type="button" class="key" id="pinClear"><i class="fas fa-xmark"></i></button>
      </div>
    </div>

    <div class="side">
      <div class="brand">
        <div class="brand-icon">
          <?php if ($shopLogo): ?><img src="<?php echo htmlspecialchars($shopLogo); ?>" alt="">
          <?php else: ?><i class="fas fa-store"></i><?php endif; ?>
        </div>
        <h1><?php echo htmlspecialchars($shopName); ?></h1>
        <p>Staff terminal</p>
      </div>
      <form method="post" id="pinForm">
        <input type="hidden" name="pin" id="pinValue">
        <input type="hidden" name="action" id="actionValue" value="login">
        <div class="actions">
          <button type="submit" class="act act-login" data-action="login"><i class="fas fa-right-to-bracket me-1"></i> Login</button>
          <button type="submit" class="act act-in" data-action="clock_in"><i class="fas fa-clock me-1"></i> Clock In</button>
          <button type="submit" class="act act-out" data-action="clock_out"><i class="fas fa-clock me-1"></i> Clock Out</button>
          <a class="act act-owner" href="<?php echo $shop ? rtrim((string) BASE_URL, '/') . '/' . $shopSlug . '/admin/' : public_url('admin/'); ?>"><i class="fas fa-user-tie me-1"></i> Owner login</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  var pin = '', MAXLEN = 6;
  var display = document.getElementById('pinDisplay');
  var pinValue = document.getElementById('pinValue');
  var actionValue = document.getElementById('actionValue');
  var form = document.getElementById('pinForm');

  function render() {
    if (pin.length === 0) {
      display.textContent = 'Enter PIN';
      display.classList.add('placeholder');
    } else {
      display.textContent = '•'.repeat(pin.length);
      display.classList.remove('placeholder');
    }
  }

  document.getElementById('keys').addEventListener('click', function (e) {
    var key = e.target.closest('.key'); if (!key) return;
    if (key.id === 'pinBack') { pin = pin.slice(0, -1); render(); return; }
    if (key.id === 'pinClear') { pin = ''; render(); return; }
    if (key.dataset.digit !== undefined && pin.length < MAXLEN) {
      pin += key.dataset.digit;
      render();
    }
  });

  document.querySelectorAll('button.act').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      if (pin.length < 4) {
        e.preventDefault();
        alert('Enter at least 4 digits.');
        return;
      }
      pinValue.value = pin;
      actionValue.value = btn.dataset.action;
    });
  });
})();
</script>
</body>
</html>
