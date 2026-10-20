<?php
// public/auth/signup.php — the ONE shared signup page for invited shops.
// Step 1: type your business name; it must be a shop support created that
//         has no owner yet (one owner signup per shop).
// Step 2: create the owner login, then continue to auth/welcome.php
//         (import old data → your POS link → install → activation key).
// domain/<slug> forwards here with ?shop=<slug> while the shop is unclaimed.
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
  <form method="post" class="mt-2 text-center"><input type="hidden" name="csrf" value="<?php echo $_SESSION['signup_csrf']; ?>"><input type="hidden" name="step" value="back">
    <button class="btn btn-link btn-sm">Not your shop? Go back</button></form>
<?php endif; ?>
<div class="auth-foot"><a href="<?php echo public_url('auth/login.php'); ?>">Already have an account? Sign in</a></div>
<?php
$content = ob_get_clean();
include ROOT_PATH . '/public/templates/auth/layout.php';
