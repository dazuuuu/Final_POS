<?php
// public/components/preview_banner.php — top bar while support is previewing
// an account as one of its users (support/accounts/preview.php).
if (Demo::active()): $__left = Demo::minutesLeft(); ?>
<div style="position:sticky;top:0;z-index:2000;background:#1f2421;color:#fff;font:600 13px/1.3 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
            padding:8px 14px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:center">
  <span><span style="background:#f4a62a;color:#1f2421;border-radius:6px;padding:1px 7px;margin-right:6px">DEMO</span>A private practice shop — try anything.
    It is deleted when you end the demo (<?php echo $__left; ?> min left). Emails are not sent.</span>
  <a href="<?php echo public_url('auth/logout.php'); ?>" style="background:#1f9a5f;color:#fff;border-radius:999px;padding:3px 12px;text-decoration:none">End demo</a>
</div>
<?php return; endif;
if (!empty($_SESSION['logged_in']) && empty($_SESSION['support_preview']) && TenantContext::tenantId() !== null
    && AccountLock::isLocked($__lockAcct = Features::accountId((int) TenantContext::tenantId()))): ?>
<div style="position:sticky;top:0;z-index:2000;background:#09090b;color:#fff;font:500 13px/1.3 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
            padding:8px 14px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:center">
  <span><b>Account locked by support.</b> Only some pages are open. <?php echo htmlspecialchars(mb_strimwidth(AccountLock::state($__lockAcct)['reason'], 0, 140, '…')); ?></span>
  <a href="<?php echo public_url('super/locked.php'); ?>" style="background:#fff;color:#09090b;border-radius:999px;padding:3px 12px;text-decoration:none">Details</a>
</div>
<?php return; endif;
if (empty($_SESSION['support_preview']) || empty($_SESSION['support_auth'])) {
    return;
}
$__pv = $_SESSION['support_preview'];
?>
<div style="position:sticky;top:0;z-index:2000;background:#7c3aed;color:#fff;font:600 13px/1.3 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
            padding:8px 14px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:center">
  <span><i class="fas fa-user-secret me-1"></i>Support preview — signed in as
    <strong><?php echo htmlspecialchars($__pv['user']); ?></strong> (<?php echo $__pv['role']; ?>). Changes you make are real.</span>
  <a href="<?php echo public_url('support/accounts/preview.php?exit=1'); ?>"
     style="background:#fff;color:#7c3aed;border-radius:999px;padding:3px 12px;text-decoration:none">Exit preview</a>
</div>
