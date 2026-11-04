<?php
// public/super/alerts/index.php — News & alerts: everything support has sent this account, and the owner's answers.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::tenant();
require_once APP_ROOT . '/helpers/uploads.php';
$account = Features::accountId((int) TenantContext::tenantId());
$list = Alerts::forAccount($account, (int) TenantContext::userId());
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$page_title = 'News & alerts';
ob_start();
?>
<style>
.al-item{background:#fff;border:1px solid #e4e4e7;border-radius:12px;display:grid;grid-template-columns:auto 1fr;overflow:hidden;margin-bottom:14px;}
.al-item .im{width:180px;background:#f4f4f5 center/cover no-repeat;}
.al-item .bd{padding:18px 20px;}
.al-item h2{font-size:1.1rem;font-weight:700;margin:0 0 6px;}
.al-item p{white-space:pre-line;font-size:.9rem;color:#3f3f46;margin:0;}
.al-meta{font-size:.74rem;color:#71717a;margin-bottom:6px;text-transform:uppercase;letter-spacing:.06em;}
.al-ans{margin-top:10px;font-size:.84rem;border-top:1px solid #f4f4f5;padding-top:10px;}
@media (max-width:600px){.al-item{grid-template-columns:1fr}.al-item .im{width:auto;height:160px}}
</style>
<?php if (!$list): ?><p class="text-muted">No news yet. Messages from support appear here.</p><?php endif; ?>
<?php foreach ($list as $a): ?>
  <div class="al-item" style="<?php echo $a['image_path'] ? '' : 'grid-template-columns:1fr'; ?>">
    <?php if ($a['image_path']): ?><div class="im" style="background-image:url('<?php echo $h(Alerts::image($a['image_path'])); ?>')"></div><?php endif; ?>
    <div class="bd">
      <div class="al-meta"><?php echo $a['target_account'] ? 'For you' : 'News'; ?> · <?php echo date('j M Y', strtotime($a['created_at'])); ?><?php echo empty($a['closed_at']) ? ' · <b style="color:#09090b">New</b>' : ''; ?></div>
      <h2><?php echo $h($a['title']); ?></h2>
      <p><?php echo $h($a['body']); ?></p>
      <?php if ($a['kind'] === 'poll'): ?>
        <div class="al-ans"><?php echo $a['choice'] ? 'Your vote: <b>' . $h($a['choice']) . '</b>' : '<span class="text-muted">You didn\'t vote on this one.</span>'; ?></div>
      <?php elseif ($a['kind'] === 'feedback'): ?>
        <div class="al-ans"><?php echo $a['feedback'] ? 'Your feedback: <i>' . nl2br($h($a['feedback'])) . '</i>' : '<span class="text-muted">No feedback sent yet.</span>'; ?></div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
