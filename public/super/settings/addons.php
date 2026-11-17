<?php
// public/super/settings/addons.php — optional extras the owner switches on themselves.
// "Integrate shop to your services": adds the retail shop (new sale, credit, payments, returns,
// products, suppliers, customers…) to a Services account. Off by default.
require_once __DIR__ . '/../../../app/app.php';
require_once ROOT_PATH . '/app/helpers/uploads.php';
PageGuard::auth();
if (TenantContext::role() !== 'tenant_owner' || !TenantContext::isPrimaryOwner()) {
    header('Location: ' . public_url('super/dashboard/'));
    exit;
}
$account = Features::accountId((int) TenantContext::tenantId());
$isServices = Features::servicesMode();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash']['error'] = 'Session expired — reload the page and try again.';
    } elseif ($isServices && ($_POST['action'] ?? '') === 'shop') {
        $on = !empty($_POST['on']);
        Addons::set($account, Addons::SERVICES_SHOP, $on);
        $_SESSION['flash']['success'] = $on ? 'Shop added — New sale, Credit sale, Payments, Products and more are now in your menu.' : 'Shop switched off. Your products and past sales are kept.';
    }
    header('Location: ' . public_url('super/settings/addons.php'));
    exit;
}
$shopOn = Features::enabled(Features::SHOP_POS);
$page_title = 'Add-ons';
ob_start();
?>
<div class="row g-3">
  <div class="col-12 col-lg-8">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <div class="d-flex gap-3 align-items-start">
        <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width:46px;height:46px;background:#e8f7ef;color:#1f9a5f;"><i class="fas fa-cash-register fa-lg"></i></div>
        <div class="flex-grow-1">
          <h2 class="h6 fw-bold mb-1">Integrate shop to your services</h2>
          <p class="small text-muted mb-2">Sell products over the counter next to your services: a full till (New sale), credit sales and the credit book, held sales, payments, returns, products &amp; categories, suppliers, purchases and customers.
            Products a client buys during a visit (dye, braids…) are already recorded under Serving — you don't need the shop for that.</p>
          <?php if (!$isServices): ?>
            <div class="alert alert-info small mb-0">Your package already includes the shop.</div>
          <?php else: ?>
            <form method="post" class="d-flex align-items-center gap-3 flex-wrap">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>">
              <input type="hidden" name="action" value="shop">
              <input type="hidden" name="on" value="<?php echo $shopOn ? '' : '1'; ?>">
              <span class="badge <?php echo $shopOn ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $shopOn ? 'On' : 'Off'; ?></span>
              <button class="btn btn-sm <?php echo $shopOn ? 'btn-outline-secondary' : 'btn-primary'; ?>"><?php echo $shopOn ? 'Switch off' : 'Add the shop'; ?></button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div></div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
