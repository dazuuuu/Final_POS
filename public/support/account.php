<?php
// public/support/account.php — self-service "change my password" for
// whoever is signed in to the support console: the platform's main admin,
// a partner company's main admin, or one of their team members.
require_once __DIR__ . '/_boot.php';

$error = '';
$role = $_SESSION['support_role'] ?? '';
$isMainPlatformAdmin = $role === 'owner' && (int) ($_SESSION['support_org'] ?? 0) === SupportOrg::MAIN;
$isCompanyOwner = $role === 'owner' && !$isMainPlatformAdmin;
$isAgent = $role === 'agent';

if (support_posted()) {
    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if ($new !== $confirm) {
        $error = 'The new password and confirmation don\'t match.';
    } elseif (strlen($new) < 8) {
        $error = 'The new password must be at least 8 characters.';
    } elseif ($isMainPlatformAdmin) {
        if (!SupportAccount::verify((string) $_SESSION['support_email'], $current)) {
            $error = 'Your current password is wrong.';
        } elseif (!SupportAccount::save((string) $_SESSION['support_email'], $new)) {
            $error = 'Could not save the new password.';
        } else {
            support_flash('Password changed.');
            header('Location: ' . public_url('support/account.php'));
            exit;
        }
    } elseif ($isCompanyOwner) {
        $org = SupportOrg::find((int) ($_SESSION['support_org'] ?? 0));
        if (!$org || !password_verify($current, $org['password_hash'])) {
            $error = 'Your current password is wrong.';
        } elseif (($err = SupportOrg::setPassword((int) $org['id'], $new)) !== null) {
            $error = $err;
        } else {
            support_flash('Password changed.');
            header('Location: ' . public_url('support/account.php'));
            exit;
        }
    } elseif ($isAgent) {
        $pdo = Database::pdo();
        $a = $pdo->prepare('SELECT password_hash FROM support_agents WHERE id = ?');
        $a->execute([(int) ($_SESSION['support_agent'] ?? 0)]);
        $hash = $a->fetchColumn();
        if ($hash === false || !password_verify($current, $hash)) {
            $error = 'Your current password is wrong.';
        } elseif (($err = SupportAccount::setAgentPassword((int) $_SESSION['support_agent'], $new)) !== null) {
            $error = $err;
        } else {
            support_flash('Password changed.');
            header('Location: ' . public_url('support/account.php'));
            exit;
        }
    } else {
        $error = 'Could not tell which account you\'re signed in as — sign out and back in, then try again.';
    }
}

$page_title = 'My account';
ob_start();
?>
<div class="s-card mb-3" style="max-width:480px;">
  <h2 class="h6 mb-1">Change password</h2>
  <p class="small text-muted mb-3">Signed in as <strong><?php echo htmlspecialchars((string) ($_SESSION['support_name'] ?? $_SESSION['support_email'] ?? '')); ?></strong> (<?php echo htmlspecialchars((string) ($_SESSION['support_email'] ?? '')); ?>).</p>
  <?php if ($error): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?php echo support_csrf_field(); ?>
    <div class="mb-2"><label class="form-label small">Current password</label><input class="form-control" type="password" name="current_password" required autofocus></div>
    <div class="mb-2"><label class="form-label small">New password</label><input class="form-control" type="password" name="new_password" minlength="8" required></div>
    <div class="mb-3"><label class="form-label small">Confirm new password</label><input class="form-control" type="password" name="confirm_password" minlength="8" required></div>
    <button class="btn btn-sm btn-primary">Save new password</button>
  </form>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
