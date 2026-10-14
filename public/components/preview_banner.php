<?php
// public/components/preview_banner.php — top bar while support is previewing
// an account as one of its users (support/accounts/preview.php).
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
