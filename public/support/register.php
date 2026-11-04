<?php
// public/support/register.php?t=TOKEN — a partner registers their support company
// with a one-time link from Support → Settings (SupportOrg). They become the main
// admin of their own support console: their own team and shop accounts only.
require_once __DIR__ . '/../../app/app.php';
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$valid = $token !== '' && SupportOrg::inviteValid($token);
$error = '';
$in = ['company' => '', 'email' => ''];
$_SESSION['org_reg_csrf'] ??= bin2hex(random_bytes(16));
if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['org_reg_csrf'], (string) ($_POST['csrf'] ?? ''))) {
    $in = ['company' => trim((string) ($_POST['company'] ?? '')), 'email' => trim((string) ($_POST['email'] ?? ''))];
    if ((string) ($_POST['password'] ?? '') !== (string) ($_POST['password2'] ?? '')) {
        $error = 'The two passwords don\'t match.';
    } else {
        [$org, $error] = SupportOrg::register($token, $in['company'], $in['email'], (string) $_POST['password']);
        if ($org) {
            SupportAccount::signIn(['role' => 'owner', 'id' => 0, 'email' => strtolower($in['email']), 'name' => $in['company'], 'org' => $org]);
            $_SESSION['support_flash'] = ['success', 'Welcome! Your support company is ready. Create your first shop account with "New account".'];
            header('Location: ' . public_url('support/'));
            exit;
        }
    }
}
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$page_title = 'Register your support company';
$__authMono = true;
$__authRole = 'Support';
ob_start();
?>
<?php if (!$valid): ?>
  <div class="auth-title">Link not valid</div>
  <div class="auth-sub">This registration link was already used or has expired. Ask for a new one.</div>
  <a class="btn-auth d-block text-center text-decoration-none" href="<?php echo $h(public_url('support/login.php')); ?>">Go to support sign-in</a>
<?php else: ?>
  <div class="auth-title">Register your support company</div>
  <div class="auth-sub">You'll be the main admin of your own support console — your own team and your own shop accounts.</div>
  <?php if ($error): ?><div class="auth-alert err"><?php echo $h($error); ?></div><?php endif; ?>
  <form method="post" novalidate>
    <input type="hidden" name="t" value="<?php echo $h($token); ?>"><input type="hidden" name="csrf" value="<?php echo $h($_SESSION['org_reg_csrf']); ?>">
    <div class="mb-3"><label class="form-label">Company name</label><input name="company" class="form-control" value="<?php echo $h($in['company']); ?>" required maxlength="120"></div>
    <div class="mb-3"><label class="form-label">Your email (your login)</label><input name="email" type="email" class="form-control" value="<?php echo $h($in['email']); ?>" required></div>
    <div class="mb-3"><label class="form-label">Password</label><input name="password" type="password" class="form-control" minlength="8" required></div>
    <div class="mb-4"><label class="form-label">Confirm password</label><input name="password2" type="password" class="form-control" minlength="8" required></div>
    <button class="btn-auth">Create my support console</button>
  </form>
<?php endif; ?>
<?php
$content = ob_get_clean();
include ROOT_PATH . '/public/templates/auth/layout.php';
