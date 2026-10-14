<?php
// public/super/upgrade.php — where Basic (20k) accounts land when they open a
// Pro feature (Features::PRO_PAGES, the staff limit, Bluetooth / Wi-Fi printing).
require_once __DIR__ . '/../../app/app.php';
PageGuard::auth();

$feature = trim((string) ($_GET['f'] ?? '')) ?: 'This feature';
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$page_title = 'Upgrade to Pro';
ob_start();
?>
<div class="card border-0 shadow-sm mx-auto text-center" style="border-radius:16px;max-width:560px">
  <div class="card-body p-4 p-md-5">
    <div class="mx-auto mb-3 d-grid" style="width:64px;height:64px;border-radius:18px;place-items:center;background:linear-gradient(135deg,#f59e0b,#ea580c);color:#fff;font-size:1.6rem">
      <i class="fas fa-crown"></i>
    </div>
    <h1 class="h4 fw-bold mb-1"><?php echo $h($feature); ?> is a Pro feature</h1>
    <p class="text-muted mb-4">Your shop is on the <strong>Basic</strong> package (KES 20,000). Upgrade to <strong>Pro POS</strong> (KES 30,000) to unlock it.</p>
    <ul class="list-unstyled text-start small mx-auto mb-4" style="max-width:360px">
      <?php foreach (['Multiple shops, warehouse and transfers', 'Purchases', 'Unlimited staff and extra admins', 'Bluetooth and Wi-Fi receipt printers'] as $f): ?>
        <li class="mb-2"><i class="fas fa-circle-check text-success me-2"></i><?php echo $h($f); ?></li>
      <?php endforeach; ?>
    </ul>
    <a class="btn btn-warning fw-semibold px-4" href="<?php echo public_url('super/help/tickets.php?new=1&subject=' . urlencode('Upgrade to Pro POS')); ?>"><i class="fas fa-arrow-up me-1"></i>Request upgrade</a>
    <div class="mt-3"><a class="small" href="javascript:history.back()">Go back</a></div>
  </div>
</div>
<?php
$content = ob_get_clean();
$__layout = TenantContext::role() === 'staff' ? 'staff' : 'tenants';
include __DIR__ . '/../templates/' . $__layout . '/layout.php';
