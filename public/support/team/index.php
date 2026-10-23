<?php
// public/support/team/index.php — Create support account.
// Every support login is a primary account of its own (SupportOrg): its own
// private console with the same powers as every other. Nobody — not even the
// one who creates it — can see another support account, its name or its shop
// accounts. So this page only creates; it never lists anyone.
require_once __DIR__ . '/../_boot.php';
support_require_owner();

if (support_posted()) {
    [$org, $err] = SupportOrg::create((string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
    if ($err) {
        support_flash($err, 'danger');
    } else {
        $cf = SupportOrg::$lastConfirm;
        support_flash($cf === null ? 'Support account created. It signs in at the support login with that email and password.'
            : ($cf['sent'] ? 'Support account created. A "Confirm your email" message is on its way — after confirming, the login details follow.'
                : 'Support account created, but the confirm email could not be sent (check Settings → SMTP). Send this link yourself: ' . $cf['link']));
    }
    header('Location: ' . public_url('support/team/'));
    exit;
}

$page_title = 'Create support account';
$nav = 'team';
ob_start();
?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="s-card">
      <h2 class="h6 mb-1"><i class="fas fa-user-plus me-1"></i>Create a support account</h2>
      <p class="small text-muted">The new account is a <b>primary</b> support login with the same powers as yours, in its own private console.
        It cannot see your account or your shop accounts — and you will not see it either. It is not listed anywhere.</p>
      <form method="post" autocomplete="off"><?php echo support_csrf_field(); ?>
        <label class="form-label small fw-semibold mb-1">Name</label>
        <input class="form-control mb-2" name="name" maxlength="120" required>
        <label class="form-label small fw-semibold mb-1">Email (their login)</label>
        <input class="form-control mb-2" type="email" name="email" maxlength="150" required autocomplete="off">
        <label class="form-label small fw-semibold mb-1">Password</label>
        <input class="form-control mb-3" type="password" name="password" minlength="8" required autocomplete="new-password">
        <button class="btn btn-primary w-100">Create support account</button>
        <p class="small text-muted mt-2 mb-0">They confirm their email first, then receive their login details by email.</p>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../_layout.php';
