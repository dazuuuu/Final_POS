<?php
// public/auth/signup.php — the ONE shared signup page for invited shops.
// Step 1: type your business name; it must be a shop support created that
//         has no owner yet (one owner signup per shop).
// Step 2: create the owner login, then continue to auth/welcome.php
//         (import old data → your POS link → install → activation key).
// domain/<slug> forwards here with ?shop=<slug> while the shop is unclaimed.
// Step 2 can instead IMPORT the owner's export file (Settings → Export &
// import on their old POS): their shop, staff and data come in, the owner
// login is recreated with their old email and password, and they go to sign in.
require_once __DIR__ . '/../../app/app.php';

// Support can close signup from their Overview.
if (!Signup::enabled()) {
    $page_title = 'Signup closed';
    ob_start(); ?>
  <div class="auth-title">Signup is closed</div>
  <div class="auth-sub">New shop accounts can't be created right now. Please contact support.</div>
  <div class="auth-foot"><a href="<?php echo public_url('auth/login.php'); ?>">Already have an account? Sign in</a></div>
<?php
    $content = ob_get_clean();
    include ROOT_PATH . '/public/templates/auth/layout.php';
    exit;
}

$_SESSION['signup_csrf'] ??= bin2hex(random_bytes(16));
$posted = $_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['signup_csrf'], $_POST['csrf'] ?? '');
$errors = [];
$in = ['business' => '', 'username' => '', 'email' => ''];

if (!empty($_GET['shop']) && ($pre = Signup::findBySlug((string) $_GET['shop']))) {
    $in['business'] = $pre['name'];
}

// The shop verified in step 1 (kept by id in the session).
$acct = null;
if (!empty($_SESSION['signup_account'])) {
    $stmt = Database::pdo()->prepare('SELECT * FROM tenants WHERE id = ? AND owner_user_id IS NULL');
    $stmt->execute([(int) $_SESSION['signup_account']]);
    $acct = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$acct) unset($_SESSION['signup_account']);
}

if ($posted) {
    $step = $_POST['step'] ?? '';
    if ($step === 'verify') {
        $in['business'] = trim($_POST['business'] ?? '');
        $wait = Signup::throttle();
        if ($wait > 0) {
            $errors[] = 'Too many tries. Try again in ' . ceil($wait / 60) . ' minute(s).';
        } elseif ($found = Signup::findByName($in['business'])) {
            $_SESSION['signup_account'] = (int) $found['id'];
            unset($_SESSION['signup_attempts']);
            $acct = $found;
        } elseif (Signup::findByName($in['business'], false)) {
            $errors[] = 'This shop already has an owner account. Sign in instead, or ask the owner to add you as staff.';
        } else {
            Signup::throttle(true);
            $errors[] = 'We couldn\'t find that shop. Type the business name exactly as you gave it to support.';
        }
    } elseif (in_array($step, ['import', 'import_pick'], true) && $acct) {
        $file = null;
        try {
            if ($step === 'import_pick' && !empty($_SESSION['signup_import'])) {
                $file = (string) $_SESSION['signup_import']; // the file uploaded a moment ago
            } else {
                if (!empty($_SESSION['signup_import'])) ShopImport::discard($_SESSION['signup_import']);
                $file = ShopImport::storeUpload($_FILES['dump'] ?? []);
            }
            unset($_SESSION['signup_import']);
            $tables = ShopImport::load($file);
            $shops = ShopImport::sourceShops($tables);
            if (!$shops) {
                throw new RuntimeException('This file has no shop in it. Use the file from Settings → Export & import on your POS.');
            }
            // The shop in the file: the one picked, the only one, or the one named like this account; otherwise ask.
            $src = null;
            if ($step === 'import_pick' && isset($shops[(int) ($_POST['src'] ?? 0)])) $src = (int) $_POST['src'];
            if ($src === null && count($shops) === 1) $src = array_key_first($shops);
            if ($src === null) foreach ($shops as $sid => $sname) { if (Signup::nameMatches($acct['name'], (string) $sname)) { $src = $sid; break; } }
            if ($src === null) {
                $_SESSION['signup_import'] = $file;
                $pickShops = $shops;
                $file = null; // keep it for the next step
                throw new RuntimeException('');
            }
            $oldOwner = ShopImport::sourceOwner($tables, (int) $src);
            if (!$oldOwner) {
                throw new RuntimeException('No owner login was found in this file. Create the owner account instead, then import from Settings.');
            }
            ShopImport::run($tables, (int) $src, (int) $acct['id'], false);
            ShopImport::discard($file);
            unset($_SESSION['signup_account']);
            header('Location: ' . public_url('auth/login.php?imported=1&email=' . urlencode((string) ($oldOwner['email'] ?? ''))));
            exit;
        } catch (Throwable $e) {
            if ($file) ShopImport::discard($file);
            if ($e->getMessage() !== '') {
                error_log('signup import: ' . $e->getMessage());
                $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'The file could not be imported. Check it is the export file from your POS, or contact support.';
            }
        }
    } elseif ($step === 'back') {
        unset($_SESSION['signup_account']);
        $acct = null;
    } elseif ($step === 'owner' && $acct) {
        $in['username'] = trim($_POST['username'] ?? '');
        $in['email'] = strtolower(trim($_POST['email'] ?? ''));
        $pw = (string) ($_POST['password'] ?? '');
        if ($pw !== ($_POST['password2'] ?? '')) {
            $errors[] = 'The two passwords don\'t match.';
        } else {
            [$userId, $errors] = Signup::complete((int) $acct['id'], $in['username'], $in['email'], $pw);
            if ($userId) {
                unset($_SESSION['signup_account']);
                $user = (new AuthService(Database::pdo()))->findByEmail($in['email']);
                session_regenerate_id(true);
                TenantContext::establish(Database::pdo(), $user);
                SingleSession::claim(Database::pdo(), $user);
                UserSessions::start();
                $_SESSION['username']     = $user['username'];
                $_SESSION['logged_in']    = true;
                $_SESSION['otp_verified'] = true;
                $_SESSION['first_login']  = true;
                $_SESSION['must_reset']   = false;
                $_SESSION['shop_slug']    = $acct['slug'];
                header('Location: ' . public_url('auth/welcome.php'));
                exit;
            }
        }
    }
}

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$__authTenant = $acct; // brand the card with the shop once it's known
$page_title = 'Create your account';
ob_start();
?>
<?php if (!$acct): ?>
  <div class="auth-title">Set up your shop</div>
  <div class="auth-sub">Step 1 of 3 · Type your business name exactly as you registered it with us.</div>
  <?php if ($errors): ?><div class="auth-alert err"><?php echo implode('<br>', array_map($h, $errors)); ?></div><?php endif; ?>
  <form method="post" autocomplete="off" novalidate>
    <input type="hidden" name="csrf" value="<?php echo $_SESSION['signup_csrf']; ?>"><input type="hidden" name="step" value="verify">
    <div class="mb-4"><label class="form-label">Business name</label>
      <input name="business" class="form-control" value="<?php echo $h($in['business']); ?>" required autofocus></div>
    <button class="btn-auth">Find my shop</button>
  </form>
<?php else: ?>
  <div class="auth-title">Create the owner account</div>
  <div class="auth-sub">Step 2 of 3 · Shop found: <strong><?php echo $h($acct['name']); ?></strong>. You'll sign in with this email and password.</div>
  <?php if ($errors): ?><div class="auth-alert err"><?php echo implode('<br>', array_map($h, $errors)); ?></div><?php endif; ?>
  <form method="post" autocomplete="off" novalidate>
    <input type="hidden" name="csrf" value="<?php echo $_SESSION['signup_csrf']; ?>"><input type="hidden" name="step" value="owner">
    <div class="mb-3"><label class="form-label">Username</label><input name="username" class="form-control" autocapitalize="off" value="<?php echo $h($in['username']); ?>" required></div>
    <div class="mb-3"><label class="form-label">Email</label><input name="email" type="email" class="form-control" value="<?php echo $h($in['email']); ?>" required></div>
    <div class="mb-3"><label class="form-label">Password</label><input name="password" type="password" class="form-control" minlength="8" required></div>
    <div class="mb-4"><label class="form-label">Confirm password</label><input name="password2" type="password" class="form-control" minlength="8" required></div>
    <button class="btn-auth">Create account</button>
  </form>
  <div style="display:flex;align-items:center;gap:10px;margin:18px 0 14px;color:#a1a1aa;font-size:.8rem"><span style="flex:1;height:1px;background:#e4e4e7"></span>or<span style="flex:1;height:1px;background:#e4e4e7"></span></div>
  <?php if (!empty($pickShops)): ?>
  <div class="auth-alert ok" style="text-align:left">Your file holds more than one shop. Which one is <strong><?php echo $h($acct['name']); ?></strong>?</div>
  <form method="post" class="mb-3" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Importing…';">
    <input type="hidden" name="csrf" value="<?php echo $_SESSION['signup_csrf']; ?>"><input type="hidden" name="step" value="import_pick">
    <select name="src" class="form-control mb-3"><?php foreach ($pickShops as $sid => $sname): ?><option value="<?php echo (int) $sid; ?>"><?php echo $h($sname); ?></option><?php endforeach; ?></select>
    <button class="btn-auth" style="background:#09090b!important">Import this shop</button>
  </form>
  <?php endif; ?>
  <div class="auth-sub" style="margin-bottom:12px"><strong>Moving from another Dazu POS?</strong> Import your export file — your shop, staff and data come in, and you sign in with your <strong>old email and password</strong>.</div>
  <form method="post" enctype="multipart/form-data" novalidate onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Importing… this can take a minute';">
    <input type="hidden" name="csrf" value="<?php echo $_SESSION['signup_csrf']; ?>"><input type="hidden" name="step" value="import">
    <div class="mb-3"><label class="form-label">Export file (.sql)</label><input name="dump" type="file" accept=".sql" class="form-control" required></div>
    <button class="btn-auth" style="background:#09090b!important">Import my data</button>
  </form>
  <form method="post" class="mt-2 text-center"><input type="hidden" name="csrf" value="<?php echo $_SESSION['signup_csrf']; ?>"><input type="hidden" name="step" value="back">
    <button class="btn btn-link btn-sm">Not your shop? Go back</button></form>
<?php endif; ?>
<div class="auth-foot"><a href="<?php echo public_url('auth/login.php'); ?>">Already have an account? Sign in</a></div>
<?php
$content = ob_get_clean();
include ROOT_PATH . '/public/templates/auth/layout.php';
