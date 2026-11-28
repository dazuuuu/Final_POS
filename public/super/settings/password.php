<?php
// public/super/settings/password.php — self-service password change for
// anyone signing in with email + password (owner or admin). Staff use a PIN
// instead — see staff/change-pin.php.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::auth();
if (TenantContext::role() === 'staff') {
    header('Location: ' . public_url('staff/change-pin.php'));
    exit;
}

$pdo = Database::pdo();
$auth = new AuthService($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $me = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $me->execute([TenantContext::userId()]);
    $user = $me->fetch(PDO::FETCH_ASSOC);

    if (!$user || !$auth->verifyPassword($user, $current)) {
        $error = 'Your current password is wrong.';
    } elseif (strlen($new) < 8) {
        $error = 'The new password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'The new password and confirmation don\'t match.';
    } else {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), TenantContext::userId()]);
        $_SESSION['flash']['success'] = 'Password changed.';
        header('Location: ' . public_url('super/settings/'));
        exit;
    }
}

$page_title = 'Change password';
ob_start();
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h1 class="h5 mb-0 fw-bold">Change password</h1>
  <a class="btn btn-sm btn-outline-secondary" href="<?php echo public_url('super/settings/'); ?>"><i class="fas fa-arrow-left me-1"></i>Back</a>
</div>
<div class="row">
  <div class="col-12 col-md-6 col-lg-5">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <?php if ($error): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <form method="post" novalidate autocomplete="off">
          <div class="mb-3">
            <label class="form-label">Current password</label>
            <input name="current_password" type="password" class="form-control" required autofocus>
          </div>
          <div class="mb-3">
            <label class="form-label">New password</label>
            <input name="new_password" type="password" class="form-control" placeholder="At least 8 characters" required minlength="8">
          </div>
          <div class="mb-4">
            <label class="form-label">Confirm new password</label>
            <input name="confirm_password" type="password" class="form-control" required minlength="8">
          </div>
          <button class="btn btn-primary">Save new password</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
