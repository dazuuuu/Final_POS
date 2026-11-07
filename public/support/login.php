<?php
// public/support/login.php — vendor support sign-in (separate from shop users).
require_once __DIR__ . '/../../app/app.php';

if (!empty($_SESSION['support_auth'])) {
    header('Location: ' . public_url('support/'));
    exit;
}

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if ($who = SupportAccount::authenticate($email, (string) ($_POST['password'] ?? ''))) {
        SupportAccount::signIn($who);
        header('Location: ' . public_url('support/'));
        exit;
    }
    usleep(500000); // slow down guessing
    $error = SupportAccount::$unconfirmed ? 'Confirm your email first — open the link we emailed you.' : 'Invalid support credentials.';
}

$page_title = 'Support';
ob_start();
?>
<div class="auth-title">Support</div>
<div class="auth-sub">Sign in to manage licensed packages.</div>
<?php if (!SupportAccount::exists()): ?><div class="auth-alert err">No support account yet. Create one in <a href="<?php echo public_url('setup/account.php'); ?>">setup</a>.</div><?php endif; ?>
<?php if ($error): ?><div class="auth-alert err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<form method="post" novalidate>
    <div class="mb-3">
        <label class="form-label">Email</label>
        <div class="lh-field"><span class="lh-ic"><i class="fa-regular fa-user"></i></span>
        <input name="email" type="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" placeholder="Email" autofocus></div>
    </div>
    <div class="mb-4">
        <label class="form-label">Password</label>
        <div class="lh-field lh-right"><span class="lh-ic"><i class="fas fa-lock-open"></i></span>
        <input name="password" type="password" class="form-control" placeholder="Password"></div>
    </div>
    <button class="btn-auth"><span class="d-none d-sm-inline">Sign in</span><span class="d-sm-none">Login</span></button>
</form>
<?php
$content = ob_get_clean();
$__authHero = true;   // phones: full-screen background login (Support → Settings)
$__authMono = true;   // support login: black and white
$__authRole = 'Support';
include ROOT_PATH . '/public/templates/auth/layout.php';
