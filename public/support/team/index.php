<?php
// public/support/team/index.php — the support team (main admin only).
// Add support logins for staff working under the main admin. They can use the
// whole console except opening a client's POS (preview) and this page — so
// they can't add other support logins.
require_once __DIR__ . '/../_boot.php';
support_require_owner();

if (support_posted()) {
    $act = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($act === 'add') {
        $err = SupportAccount::addAgent((string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
        support_flash($err ?? 'Team member added. They sign in at the support login with that email and password.', $err ? 'danger' : 'success');
    } elseif ($act === 'toggle' && $id) {
        $on = !empty($_POST['on']);
        SupportAccount::setAgentActive($id, $on);
        support_flash($on ? 'Login switched on.' : 'Login switched off — they are signed out now.');
    } elseif ($act === 'password' && $id) {
        $err = SupportAccount::setAgentPassword($id, (string) ($_POST['password'] ?? ''));
        support_flash($err ?? 'Password changed.', $err ? 'danger' : 'success');
    } elseif ($act === 'delete' && $id) {
        SupportAccount::deleteAgent($id);
        support_flash('Team member removed.');
    }
    header('Location: ' . public_url('support/team/'));
    exit;
}

$agents = SupportAccount::agents();
$page_title = 'Support team';
$nav = 'team';
ob_start();
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="s-card">
      <h2 class="h6 mb-1">Team members</h2>
      <p class="small text-muted mb-3">They can do everything in the support console <strong>except</strong> open a client's POS (preview) and manage this team.
        You (<?php echo htmlspecialchars(SupportAccount::email()); ?>) remain the main admin.</p>
      <?php if (!$agents): ?>
        <p class="text-muted small mb-0">No team members yet.</p>
      <?php else: ?>
      <div class="list-group list-group-flush">
        <?php foreach ($agents as $a): $id = (int) $a['id']; ?>
        <div class="list-group-item px-0">
          <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="flex-grow-1">
              <strong><?php echo htmlspecialchars($a['name']); ?></strong>
              <?php echo (int) $a['is_active'] ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary">Switched off</span>'; ?>
              <div class="small text-muted"><?php echo htmlspecialchars($a['email']); ?> · last sign-in <?php echo $a['last_login_at'] ? date('j M Y H:i', strtotime($a['last_login_at'])) : 'never'; ?></div>
            </div>
            <form method="post" class="m-0"><?php echo support_csrf_field(); ?>
              <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="on" value="<?php echo (int) $a['is_active'] ? '' : '1'; ?>">
              <button class="btn btn-sm <?php echo (int) $a['is_active'] ? 'btn-outline-secondary' : 'btn-outline-success'; ?>"><?php echo (int) $a['is_active'] ? 'Switch off' : 'Switch on'; ?></button>
            </form>
            <form method="post" class="m-0" onsubmit="return confirm('Remove <?php echo htmlspecialchars(addslashes($a['name']), ENT_QUOTES); ?> from the support team?');"><?php echo support_csrf_field(); ?>
              <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $id; ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
            </form>
          </div>
          <form method="post" class="d-flex gap-2 mt-2" style="max-width:360px"><?php echo support_csrf_field(); ?>
            <input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?php echo $id; ?>">
            <input class="form-control form-control-sm" type="password" name="password" minlength="8" placeholder="New password" required autocomplete="new-password">
            <button class="btn btn-sm btn-outline-primary text-nowrap">Set password</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="s-card">
      <h2 class="h6 mb-3"><i class="fas fa-user-plus me-1 text-primary"></i>Add a team member</h2>
      <form method="post"><?php echo support_csrf_field(); ?><input type="hidden" name="action" value="add">
        <label class="form-label small fw-semibold">Name</label>
        <input class="form-control mb-2" name="name" maxlength="100" required>
        <label class="form-label small fw-semibold">Email (their login)</label>
        <input class="form-control mb-2" type="email" name="email" maxlength="150" required autocomplete="off">
        <label class="form-label small fw-semibold">Password</label>
        <input class="form-control mb-3" type="password" name="password" minlength="8" required autocomplete="new-password">
        <button class="btn btn-primary w-100">Add team member</button>
        <p class="small text-muted mt-2 mb-0">Share the email and password with them yourself; they sign in at the same support login page.</p>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../_layout.php';
