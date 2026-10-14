<?php
// public/components/activation_notice.php — the first-time activation section.
// Shown at the top of every dashboard page while a new account is inside its
// 2-hour grace period. Once the key is entered it never appears again.
if (!empty($_SESSION['license_just_activated'])):
    unset($_SESSION['license_just_activated']); ?>
    <div class="t-flash ok"><i class="fas fa-circle-check me-1"></i>Your account is activated. Thank you!</div>
<?php
    return;
endif;

$__tid = TenantContext::tenantId();
if ($__tid === null) {
    return;
}
try {
    $__lic = License::forTenant($__tid);
} catch (\PDOException $e) {
    return;
}
if ($__lic['status'] !== 'grace') {
    return;
}
$_SESSION['license_csrf'] ??= bin2hex(random_bytes(16));
$__canRequest = License::canRequestKey(Features::accountId($__tid));
$__mins = (int) floor($__lic['seconds_left'] / 60);
?>
<div class="t-activate" style="background:#fff8e6;border:1px solid #f4d48a;border-radius:14px;padding:16px 20px;margin-bottom:20px;">
    <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between;">
        <div style="min-width:220px;flex:1;">
            <strong><i class="fas fa-key me-1"></i>Activate your account</strong>
            <div class="small" style="color:#7a5a00;">
                Enter the activation key from support within
                <strong><?php echo $__mins >= 60 ? floor($__mins / 60) . 'h ' . ($__mins % 60) . 'm' : $__mins . ' min'; ?></strong>.
                After that, the account and all its shops and staff are locked until it's activated.
            </div>
        </div>
        <?php if ($__canRequest): ?>
        <form method="post" action="<?php echo public_url('auth/activate-account.php'); ?>">
            <input type="hidden" name="csrf" value="<?php echo $_SESSION['license_csrf']; ?>">
            <input type="hidden" name="action" value="request">
            <button class="btn btn-sm btn-primary"><i class="fas fa-envelope me-1"></i>Request activation key</button>
        </form>
        <?php else: ?>
        <form method="post" action="<?php echo public_url('auth/activate-account.php'); ?>" style="display:flex;gap:8px;flex-wrap:wrap;" autocomplete="off">
            <input type="hidden" name="csrf" value="<?php echo $_SESSION['license_csrf']; ?>">
            <input class="form-control form-control-sm" name="key" placeholder="XXXX-XXXX-XXXX-XXXX" required style="width:210px;text-transform:uppercase;">
            <button class="btn btn-sm btn-warning"><i class="fas fa-unlock me-1"></i>Activate</button>
        </form>
        <?php endif; ?>
    </div>
</div>
