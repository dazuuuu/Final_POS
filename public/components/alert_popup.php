<?php
// public/components/alert_popup.php — pops up the oldest support alert this
// owner hasn't closed yet (Alerts), on any owner page. Text by default; a poster
// image (if support added one) fills the left half. A vote / feedback alert
// marked "required" can't be closed until they vote / answer.
if (TenantContext::role() !== 'tenant_owner' || !empty($_SESSION['support_preview']) || !empty($_SESSION['demo']) || !class_exists('Alerts')) return;
try {
    $__acct = Features::accountId((int) TenantContext::tenantId());
    $__al = Alerts::pending($__acct, (int) TenantContext::userId());
} catch (Throwable $e) {
    return;
}
if (!$__al) return;
Alerts::seen((int) $__al['id'], $__acct, (int) TenantContext::userId());
require_once ROOT_PATH . '/app/helpers/uploads.php';
$__img = Alerts::image($__al['image_path']);
$__must = (int) $__al['required'] === 1 && $__al['kind'] !== 'notice';
$__e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$__back = (string) ($_SERVER['REQUEST_URI'] ?? '');
?>
<link href="https://fonts.googleapis.com/css2?family=Jost:wght@700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
.dz-al{position:fixed;inset:0;z-index:3000;background:rgba(20,20,20,.58);display:flex;align-items:center;justify-content:center;padding:16px;}
.dz-al-box{position:relative;background:#fff;width:100%;max-width:<?php echo $__img ? '906px' : '560px'; ?>;display:grid;grid-template-columns:<?php echo $__img ? '1fr 1fr' : '1fr'; ?>;
  max-height:calc(100vh - 32px);overflow:auto;animation:dzAlIn .25s ease-out;}
@keyframes dzAlIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.dz-al-img{background:#f4f4f4 center/cover no-repeat;min-height:320px;}
.dz-al-c{padding:56px 52px 48px;color:#000;}
.dz-al-x{position:absolute;top:18px;right:20px;background:none;border:0;font-size:22px;line-height:1;color:#111;cursor:pointer;padding:6px;}
.dz-al h2{font-family:'Jost',system-ui,sans-serif;font-weight:700;font-size:clamp(1.9rem,4vw,2.65rem);line-height:1.05;letter-spacing:-.01em;margin:0 0 22px;}
.dz-al p,.dz-al .dz-mono{font-family:'IBM Plex Mono',ui-monospace,Menlo,monospace;font-size:.88rem;line-height:1.7;margin:0;white-space:pre-line;}
.dz-al-tag{font-family:'IBM Plex Mono',monospace;font-size:.7rem;letter-spacing:.12em;text-transform:uppercase;color:#666;display:block;margin-bottom:14px;}
.dz-al-row{display:flex;margin-top:40px;border:1.5px solid #000;}
.dz-al-row input,.dz-al-row textarea{flex:1;border:0;outline:0;padding:16px 18px;font-family:'IBM Plex Mono',monospace;font-size:.88rem;min-width:0;resize:vertical;background:#fff;}
.dz-al-btn{background:#000;color:#fff;border:0;padding:0 30px;min-height:56px;font-family:'Jost',system-ui,sans-serif;font-weight:700;letter-spacing:.06em;text-transform:uppercase;font-size:.9rem;cursor:pointer;}
.dz-al-btn.solo{margin-top:40px;width:100%;}
.dz-al-opts{margin-top:30px;display:grid;gap:8px;}
.dz-al-opts label{display:flex;align-items:center;gap:12px;border:1.5px solid #000;padding:12px 16px;cursor:pointer;font-family:'IBM Plex Mono',monospace;font-size:.88rem;}
.dz-al-opts input{accent-color:#000;width:16px;height:16px;}
.dz-al-opts label:has(input:checked){background:#000;color:#fff;}
.dz-al-err{font-family:'IBM Plex Mono',monospace;font-size:.8rem;color:#b00020;margin-top:12px;}
.dz-al-need{font-family:'IBM Plex Mono',monospace;font-size:.72rem;color:#666;margin-top:12px;}
@media (max-width:720px){
  .dz-al-box{grid-template-columns:1fr;}
  .dz-al-img{min-height:200px;}
  .dz-al-c{padding:44px 24px 28px;}
  .dz-al-row{flex-direction:column;} .dz-al-btn{min-height:52px;}
}
</style>
<div class="dz-al" role="dialog" aria-modal="true" aria-labelledby="dzAlTitle">
  <div class="dz-al-box">
    <?php if ($__img): ?><div class="dz-al-img" style="background-image:url('<?php echo $__e($__img); ?>')"></div><?php endif; ?>
    <div class="dz-al-c">
      <?php if (!$__must): ?>
        <form method="post" action="<?php echo $__e(public_url('super/alerts/respond.php')); ?>" style="display:contents">
          <?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int) $__al['id']; ?>"><input type="hidden" name="back" value="<?php echo $__e($__back); ?>">
          <button class="dz-al-x" aria-label="Close">&#x2715;</button>
        </form>
      <?php endif; ?>
      <span class="dz-al-tag"><?php echo $__al['target_account'] ? 'For you' : 'News'; ?> · <?php echo date('j M Y', strtotime($__al['created_at'])); ?></span>
      <h2 id="dzAlTitle"><?php echo $__e($__al['title']); ?></h2>
      <p><?php echo $__e($__al['body']); ?></p>
      <?php if (!empty($_SESSION['alert_error'])): ?><div class="dz-al-err"><?php echo $__e($_SESSION['alert_error']); unset($_SESSION['alert_error']); ?></div><?php endif; ?>

      <form method="post" action="<?php echo $__e(public_url('super/alerts/respond.php')); ?>">
        <?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int) $__al['id']; ?>"><input type="hidden" name="back" value="<?php echo $__e($__back); ?>">
        <?php if ($__al['kind'] === 'poll'): ?>
          <div class="dz-al-opts">
            <?php foreach ($__al['option_list'] as $o): ?><label><input type="radio" name="choice" value="<?php echo $__e($o); ?>" <?php echo $__must ? 'required' : ''; ?>><?php echo $__e($o); ?></label><?php endforeach; ?>
          </div>
          <button class="dz-al-btn solo"><?php echo $__e($__al['button_label'] ?: 'Vote'); ?></button>
        <?php elseif ($__al['kind'] === 'feedback'): ?>
          <div class="dz-al-row">
            <textarea name="feedback" rows="2" maxlength="2000" placeholder="Your feedback" <?php echo $__must ? 'required' : ''; ?>></textarea>
            <button class="dz-al-btn"><?php echo $__e($__al['button_label'] ?: 'Submit'); ?></button>
          </div>
        <?php else: ?>
          <button class="dz-al-btn solo"><?php echo $__e($__al['button_label'] ?: 'Got it'); ?></button>
        <?php endif; ?>
        <?php if ($__must): ?><div class="dz-al-need"><?php echo $__al['kind'] === 'poll' ? 'Vote to continue to your dashboard.' : 'Leave your feedback to continue to your dashboard.'; ?></div><?php endif; ?>
      </form>
    </div>
  </div>
</div>
