<?php
// public/stock/password.php — change your password (required on first sign-in).
require_once __DIR__ . '/_boot.php';
stock_guard();

$pdo = Database::pdo();
$first = !empty($_SESSION['must_reset']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && stock_csrf_ok()) {
    $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([TenantContext::userId()]);
    $pw = (string) ($_POST['password'] ?? '');
    if (!password_verify((string) ($_POST['current'] ?? ''), (string) $st->fetchColumn())) {
        stock_go('password.php', $first ? 'Type the temporary password from your email.' : 'Your current password is not right.', 'err');
    } elseif (strlen($pw) < 8 || $pw !== (string) ($_POST['password2'] ?? '')) {
        stock_go('password.php', 'The new password must be at least 8 characters, typed the same twice.', 'err');
    }
    $pdo->prepare('UPDATE users SET password_hash = ?, must_reset_password = 0 WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), TenantContext::userId()]);
    unset($_SESSION['must_reset']);
    stock_go('', 'Password saved.');
}

stock_store();
$title = 'Password';
$tab = '-';
ob_start();
?>
<div class="sc-head"><div><h1><?php echo $first ? 'Choose your own password' : 'Change password'; ?></h1>
  <p><?php echo $first ? 'You signed in with a temporary password. Choose your own to continue.' : 'You will still get a code by email each time you sign in.'; ?></p></div></div>
<div class="panel" style="max-width:460px">
  <form method="post"><?php echo stock_csrf_field(); ?>
    <label class="f"><?php echo $first ? 'Temporary password (from the email)' : 'Current password'; ?></label>
    <input class="in" type="password" name="current" autocomplete="current-password" required style="margin-bottom:12px">
    <label class="f">New password (8+ characters)</label>
    <input class="in" type="password" name="password" minlength="8" autocomplete="new-password" required style="margin-bottom:12px">
    <label class="f">New password again</label>
    <input class="in" type="password" name="password2" minlength="8" autocomplete="new-password" required style="margin-bottom:16px">
    <button class="btn btn-p"><i class="fas fa-key"></i> Save password</button>
  </form>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
