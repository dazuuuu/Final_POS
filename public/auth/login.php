<?php
// public/auth/login.php
// Single-step login: verify credentials + account state, then establish the
// full session and go to the dashboard. No 2FA on login. (OTP is still used for
// password reset — that flow is separate and untouched.)
require_once __DIR__ . '/../../app/app.php';

// Already fully logged in with a recognised role? Go to the right dashboard.
// A session that claims to be logged in but carries no valid role is stale or
// half-built; don't trust it — discard the auth state and show the login form.
if (!empty($_SESSION['logged_in']) && !empty($_SESSION['otp_verified'])) {
    $sessionRole = $_SESSION['role'] ?? null;
    if ($sessionRole === 'staff') {
        header('Location: ' . public_url('staff/dashboard/'));
        exit;
    }
    if ($sessionRole === 'tenant_owner') {
        header('Location: ' . public_url('super/dashboard/'));
        exit;
    }
    // Unknown/empty role — broken session. Clear it and fall through to login.
    unset(
        $_SESSION['logged_in'], $_SESSION['otp_verified'], $_SESSION['user_id'],
        $_SESSION['tenant_id'], $_SESSION['role'], $_SESSION['capabilities'],
        $_SESSION['username'], $_SESSION['must_reset']
    );
    TenantContext::reset();
}

$pdo  = Database::pdo();
$auth = new AuthService($pdo);
$error = '';
$notice = '';
if (($_GET['reset'] ?? '') === '1') {
    $notice = 'Your password has been set. Please sign in with your new password.';
} elseif (($_GET['denied'] ?? '') === '1') {
    $error = 'You don\'t have access to that page. Please sign in with the right account.';
} elseif (($_GET['imported'] ?? '') === '1') {
    $notice = 'Your shop has been imported. Sign in with the email and password you used on your old POS.';
} elseif (($_GET['elsewhere'] ?? '') === '1') {
    $error = 'You were signed out because your account was signed in on another device.';
} elseif (($_GET['locked'] ?? '') === '1') {
    $error = 'Your account needs attention before you can sign in.';
}
$email = (string) ($_GET['email'] ?? '');

$needsConfirm = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['resend'])) {
    // "Send the confirm email again" (shown when sign-in is refused for an unconfirmed email).
    $email = trim($_POST['email'] ?? '');
    $notice = EmailConfirm::resend($email) ? 'A new "Confirm your email" message is on its way to ' . $email . '.'
        : 'If that login is waiting for confirmation, a new email is on its way (one every 2 minutes).';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'] ?? null;

    $user = $auth->findByEmail($email);
    if (!$user || !$auth->verifyPassword($user, $password)) {
        $auth->logAttempt($email, $ip);
        $error = 'Invalid email or password.';
    } else {
$verdict = AccountGuard::evaluate($user);
        if (!$verdict['ok']) {
            $error = AccountGuard::message($verdict['reason']);
            $needsConfirm = $verdict['reason'] === 'not_activated';
        } else {
            // Credentials good — no 2FA. Establish the full session right away.
            // findByEmail already returns role_name + must_reset_password, so we
            // can use $user directly.
            session_regenerate_id(true);
            TenantContext::establish($pdo, $user);
            SingleSession::claim($pdo, $user); // any other device signed in as this owner is signed out
            UserSessions::start();
            if (!empty($user['tenant_id'])) { // remember the owner's shop address for install/branding
                $slugStmt = $pdo->prepare('SELECT slug FROM tenants WHERE id = ?');
                $slugStmt->execute([Features::accountId((int) $user['tenant_id'])]);
                $_SESSION['shop_slug'] = (string) $slugStmt->fetchColumn();
            }
            $_SESSION['username']     = $user['username'];
            $_SESSION['logged_in']    = true;
            $_SESSION['otp_verified'] = true;  // no 2FA step; kept true so existing guards pass
            $_SESSION['first_login']  = true;

            // First-time staff must change their temporary password before anything else.
            $_SESSION['must_reset'] = !empty($user['must_reset_password']);
            if ($_SESSION['must_reset'] && ($user['role_name'] ?? '') === 'staff') {
                header('Location: ' . public_url('staff/reset-password.php'));
                exit;
            }

            $dest = ($user['role_name'] === 'staff')
                ? public_url('staff/dashboard/')
                : public_url('super/dashboard/');
            header('Location: ' . $dest);
            exit;
        }
    }
}

$page_title = 'Sign in';
// Staff PIN goes to the shop's own terminal when this page is opened at the shop's address.
$__pinAction = ShopUrl::requestSlug() !== null ? rtrim((string) BASE_URL, '/') . '/' . ShopUrl::requestSlug() . '/' : public_url('terminal/');
$staffTab = ($_GET['as'] ?? '') === 'staff';
ob_start();
?>
<style>
.who{display:grid;grid-template-columns:1fr 1fr;border:1.5px solid var(--pos-green);border-radius:2px;margin-bottom:22px;}
.who button{background:#fff;border:0;padding:10px;font:600 .9rem Archivo,system-ui,sans-serif;color:var(--pos-green);cursor:pointer;}
.who button.on{background:var(--pos-green);color:#fff;}
.who button i{margin-right:6px;}
</style>
<div class="auth-title">Sign in</div>
<div class="auth-sub" id="whoSub"><?php echo $staffTab ? 'Staff: enter your PIN to sell, clock in or clock out.' : 'Owners and admins: use your email and password.'; ?></div>
<div class="who" role="tablist">
  <button type="button" class="<?php echo $staffTab ? '' : 'on'; ?>" data-who="owner" role="tab"><i class="fas fa-user-tie"></i>Owner</button>
  <button type="button" class="<?php echo $staffTab ? 'on' : ''; ?>" data-who="staff" role="tab"><i class="fas fa-users"></i>Staff</button>
</div>
<?php if ($error): ?><div class="auth-alert err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($notice): ?><div class="auth-alert ok"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

<div id="paneOwner" <?php echo $staffTab ? 'hidden' : ''; ?>>
  <form method="post" novalidate>
    <div class="mb-3">
      <label class="form-label">E-mail</label>
      <input name="email" type="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" placeholder="you@example.com" <?php echo $staffTab ? '' : 'autofocus'; ?>>
    </div>
    <div class="mb-4">
      <label class="form-label">Password</label>
      <input name="password" type="password" class="form-control" placeholder="Your password">
    </div>
    <button class="btn-auth">Continue</button>
  </form>
  <?php if ($needsConfirm): ?>
    <form method="post" class="mt-2"><input type="hidden" name="resend" value="1"><input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
      <button class="btn-line">Send the confirm email again</button></form>
  <?php endif; ?>
  <div class="auth-foot" style="margin-top:14px"><a href="<?php echo public_url('auth/forgot-password.php'); ?>">Forgot password?</a></div>
</div>

<div id="paneStaff" <?php echo $staffTab ? '' : 'hidden'; ?>>
  <?php include ROOT_PATH . '/public/components/staff_pin.php'; ?>
</div>
<script>
document.querySelectorAll('.who button').forEach(function (b) {
  b.addEventListener('click', function () {
    var staff = b.dataset.who === 'staff';
    document.querySelectorAll('.who button').forEach(function (x) { x.classList.toggle('on', x === b); });
    document.getElementById('paneOwner').hidden = staff; document.getElementById('paneStaff').hidden = !staff;
    document.getElementById('whoSub').textContent = staff ? 'Staff: enter your PIN to sell, clock in or clock out.' : 'Owners and admins: use your email and password.';
    if (!staff) { var f = document.querySelector('#paneOwner input[name=email]'); if (f) f.focus(); } else if (document.activeElement) document.activeElement.blur();
  });
});
</script>
<?php
$content = ob_get_clean();
$__authBelow = Signup::enabled() ? '<div class="auth-foot">New shop? <a href="' . public_url('auth/signup.php') . '">Create your account</a></div>' : '';
include ROOT_PATH . '/public/templates/auth/layout.php';
