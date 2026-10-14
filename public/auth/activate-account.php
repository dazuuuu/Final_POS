<?php
// public/auth/activate-account.php
// Enter the activation key support emailed. Once the 2-hour grace period is
// over, License::gate() sends every user of an unactivated account here.
// The dashboard's activation section posts here too.
require_once __DIR__ . '/../../app/app.php';

$tenantId = TenantContext::tenantId();
if ($tenantId === null || empty($_SESSION['logged_in'])) {
    header('Location: ' . public_url('auth/login.php'));
    exit;
}
$accountId = Features::accountId($tenantId);
$state = License::state($accountId);
if ($state['status'] === 'active') {
    header('Location: ' . License::homeUrl());
    exit;
}

$_SESSION['license_csrf'] ??= bin2hex(random_bytes(16));
$error = '';
$notice = !empty($_GET['welcome']) ? 'Your account is created. Request your activation key below — it is emailed to you straight away.' : '';
$wait = License::throttle();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['license_csrf'], $_POST['csrf'] ?? '')
    && ($_POST['action'] ?? '') === 'request') {
    $err = License::requestKey($accountId);
    if ($err === null) {
        $notice = 'Your activation key has been emailed to you. Enter it below.';
    } else {
        $error = $err;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['license_csrf'], $_POST['csrf'] ?? '')) {
    if ($wait > 0) {
        $error = 'Too many wrong keys. Try again in ' . ceil($wait / 60) . ' minute(s).';
    } elseif (License::activate($accountId, (string) ($_POST['key'] ?? ''))) {
        unset($_SESSION['license_attempts']);
        $_SESSION['license_just_activated'] = true;
        header('Location: ' . License::homeUrl());
        exit;
    } else {
        $wait = License::throttle(true);
        $error = $wait > 0
            ? 'Too many wrong keys. Try again in ' . ceil($wait / 60) . ' minute(s).'
            : 'That activation key is not correct. Check the email from support and try again.';
    }
}

$stmt = Database::pdo()->prepare('SELECT name FROM tenants WHERE id = ?');
$stmt->execute([$accountId]);
$business = (string) $stmt->fetchColumn();
$locked = $state['status'] === 'locked';
$canRequest = License::canRequestKey($accountId);

$page_title = 'Activate your account';
ob_start();
?>
<div class="auth-title"><?php echo $locked ? 'Account locked' : 'Activate your account'; ?></div>
<div class="auth-sub">
  <?php if ($locked): ?>
    <strong><?php echo htmlspecialchars($business); ?></strong> hasn't been activated yet, so it is locked for everyone on the account.
    Enter the activation key from support to unlock it.
  <?php else: ?>
    Enter the activation key from support. You have
    <strong><?php echo (int) floor($state['seconds_left'] / 60); ?> minute(s)</strong> before <?php echo htmlspecialchars($business); ?> locks.
  <?php endif; ?>
</div>
<?php if ($error): ?><div class="auth-alert err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($notice): ?><div class="auth-alert ok"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
<?php if ($canRequest): ?>
<form method="post" class="mb-3">
  <input type="hidden" name="csrf" value="<?php echo $_SESSION['license_csrf']; ?>">
  <input type="hidden" name="action" value="request">
  <button class="btn-auth" type="submit" style="background:#2563eb">Request activation key</button>
</form>
<?php else: ?>
<form method="post" autocomplete="off" novalidate>
  <input type="hidden" name="csrf" value="<?php echo $_SESSION['license_csrf']; ?>">
  <div class="mb-3">
    <label class="form-label">Activation key</label>
    <input name="key" class="form-control" required autofocus placeholder="XXXX-XXXX-XXXX-XXXX" style="text-transform:uppercase;letter-spacing:1px;">
  </div>
  <button class="btn-auth" type="submit">Activate</button>
</form>
<?php endif; ?>
<div class="auth-foot">
  <?php echo $canRequest ? 'The key is sent to your owner email.' : 'Don\'t have a key? Check your email, or contact support.'; ?><br>
  <?php if (!$locked): ?><a href="<?php echo License::homeUrl(); ?>">Back to dashboard</a> · <?php endif; ?>
  <a href="<?php echo public_url('auth/logout.php'); ?>">Sign out</a>
</div>
<?php
$content = ob_get_clean();
include ROOT_PATH . '/public/templates/auth/layout.php';
