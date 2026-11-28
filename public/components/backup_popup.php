<?php
// public/components/backup_popup.php — every 2 hours a fresh safety backup
// zip is generated for the account (AccountBackup); this pops up for anyone
// signed in (owner, admin or staff) until THEY personally download it, so a
// copy ends up on their own device too, not just this server.
if (empty($_SESSION['logged_in']) || !empty($_SESSION['demo']) || !class_exists('AccountBackup')) return;
// Support previews see this too now (so support can check the experience);
// downloading during a preview never marks it seen for the real account — see account-backup.php.
if (!AccountBackup::tourSeen((int) TenantContext::userId())) return; // the tour goes first, one overlay at a time
try {
    $__acct = Features::accountId((int) TenantContext::tenantId());
    $__bk = AccountBackup::pendingFor($__acct, (int) TenantContext::userId());
} catch (Throwable $e) {
    return;
}
if (!$__bk) return;
$__isStaff = TenantContext::role() === 'staff';
$__url = public_url(($__isStaff ? 'staff' : 'super') . '/account-backup.php');
$__e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<style>
.dz-bk{position:fixed;inset:0;z-index:2900;background:rgba(20,20,20,.5);display:flex;align-items:center;justify-content:center;padding:16px;}
.dz-bk-box{background:#fff;border-radius:16px;max-width:460px;width:100%;padding:30px 28px;box-shadow:0 20px 50px rgba(0,0,0,.25);}
.dz-bk-icon{width:52px;height:52px;border-radius:50%;background:#e8f5ee;color:#1f9a5f;display:flex;align-items:center;justify-content:center;font-size:22px;margin-bottom:16px;}
.dz-bk h2{font-size:1.25rem;font-weight:700;margin:0 0 10px;}
.dz-bk p{font-size:.92rem;color:#444;line-height:1.55;margin:0 0 18px;}
.dz-bk-btn{display:block;width:100%;background:#1f9a5f;color:#fff;text-decoration:none;text-align:center;font-weight:700;padding:13px;border-radius:10px;font-size:.95rem;}
.dz-bk-btn:hover{background:#167d4c;color:#fff;}
.dz-bk-later{display:block;width:100%;text-align:center;background:none;border:0;color:#6b7280;font-size:.85rem;padding:12px 0 0;cursor:pointer;}
</style>
<div class="dz-bk" id="dzBkPopup" role="dialog" aria-modal="true">
  <div class="dz-bk-box">
    <div class="dz-bk-icon"><i class="fas fa-shield-halved"></i></div>
    <h2>Your account's safety backup is ready</h2>
    <p>Every 2 hours, a fresh backup of your account's data is prepared automatically. Please download and keep this one somewhere safe on your own device (phone, computer, email to yourself, etc.) — it only takes a second, and it's your own extra copy in case anything ever needs restoring.</p>
    <a class="dz-bk-btn" href="<?php echo $__e($__url); ?>" onclick="setTimeout(function(){document.getElementById('dzBkPopup').remove();}, 600);"><i class="fas fa-download me-1"></i>Download backup now</a>
    <button type="button" class="dz-bk-later" onclick="document.getElementById('dzBkPopup').remove();">Remind me later</button>
  </div>
</div>
