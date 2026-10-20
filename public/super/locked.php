<?php
// public/super/locked.php — shown while support has locked the account (AccountLock).
// Owners / admins: the reason, the pages left open (by default Export) and help.
// Staff: the reason, then they are signed out.
require_once __DIR__ . '/../../app/app.php';
if (empty($_SESSION['logged_in']) || TenantContext::tenantId() === null) {
    header('Location: ' . public_url('auth/login.php'));
    exit;
}
$account = Features::accountId((int) TenantContext::tenantId());
$st = AccountLock::state($account);
if (!$st['locked']) {
    header('Location: ' . public_url(TenantContext::role() === 'staff' ? 'staff/dashboard/' : 'super/dashboard/'));
    exit;
}
$isStaff = TenantContext::role() === 'staff';
$links = $isStaff ? [] : AccountLock::openLinks($account);
if ($isStaff) { // show the reason, then end the staff session
    UserSessions::end('locked');
    $_SESSION = [];
    session_regenerate_id(true);
    TenantContext::reset();
}
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$page_title = 'Account locked';
$__authRole = 'Account locked';
ob_start();
?>
<div class="auth-title">This account is locked</div>
<div class="auth-sub">Locked by support on <?php echo date('j M Y', strtotime((string) $st['at'])); ?>.</div>
<?php if ($st['reason'] !== ''): ?>
  <div class="auth-alert err" style="text-align:left"><b>Reason:</b><br><?php echo nl2br($h($st['reason'])); ?></div>
<?php endif; ?>
<?php if ($isStaff): ?>
  <p class="small text-muted text-center">You have been signed out. Please speak to the shop owner.</p>
  <a class="btn-auth d-block text-center text-decoration-none" href="<?php echo $h(public_url('terminal/')); ?>">OK</a>
<?php else: ?>
  <?php if ($links): ?>
    <p class="small text-muted mb-2">You can still use:</p>
    <?php foreach ($links as [$label, $path]): ?>
      <a class="btn-auth d-block text-center text-decoration-none mb-2" href="<?php echo $h(public_url($path)); ?>"><?php echo $h($label); ?></a>
    <?php endforeach; ?>
  <?php endif; ?>
  <div class="auth-foot">
    <a href="<?php echo $h(public_url('super/help/tickets.php')); ?>">Contact support</a> ·
    <a href="<?php echo $h(public_url('auth/logout.php')); ?>">Sign out</a>
  </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
include ROOT_PATH . '/public/templates/auth/layout.php';
