<?php
// public/components/shell_header.php
// Top bar shared by the owner and staff layouts: green brand block (shop logo
// + name) on the left, menu toggle, then date / user / logout on the right.
// Expects $__tenant (array|null) and $shopName from the including layout.
$__shellLogo = Branding::shopLogo($__tenant ?? null); // the shop's (or its main shop's) logo, never the system's
$__shellUser = $_SESSION['username'] ?? 'User';
$__shellHome = public_url(TenantContext::role() === 'staff' ? 'staff/dashboard/' : 'super/dashboard/');
$__shellShops = TenantContext::role() === 'tenant_owner' ? ShopContext::shops(true) : [];
?>
<header class="tk-header">
    <a class="tk-brand" href="<?php echo $__shellHome; ?>">
        <?php if ($__shellLogo): ?><img src="<?php echo htmlspecialchars($__shellLogo); ?>" alt="">
        <?php else: ?><i class="fas fa-store" style="font-size:1.2rem;"></i><?php endif; ?>
        <span><?php echo htmlspecialchars($shopName ?? 'My Shop'); ?></span>
    </a>
    <div class="tk-bar">
        <button type="button" class="tk-toggle" id="tkToggle" aria-label="Toggle menu"><i class="fas fa-bars"></i></button>
        <div class="tk-right">
            <?php if (count($__shellShops) > 1): ?>
            <div class="dropdown tk-shopswitch">
                <button class="btn btn-sm btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-shop me-1"></i><span><?php echo htmlspecialchars($shopName ?? 'Shop'); ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><h6 class="dropdown-header">Switch shop</h6></li>
                    <?php foreach ($__shellShops as $__s): ?>
                    <li>
                        <form method="post" action="<?php echo public_url('super/shops/switch.php'); ?>">
                            <input type="hidden" name="id" value="<?php echo (int) $__s['id']; ?>">
                            <button class="dropdown-item <?php echo (int) $__s['id'] === (int) TenantContext::tenantId() ? 'active' : ''; ?>"><?php echo htmlspecialchars($__s['name']); ?><?php echo $__s['parent_tenant_id'] ? '' : ' <small>· Admin portal</small>'; ?></button>
                        </form>
                    </li>
                    <?php endforeach; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?php echo public_url('super/shops/'); ?>"><i class="fas fa-plus me-1"></i>Manage shops</a></li>
                </ul>
            </div>
            <?php endif; ?>
            <?php include __DIR__ . '/install_button.php'; ?>
            <span class="tk-date"><?php echo date('l, j M Y'); ?></span>
            <span class="tk-user"><i class="fas fa-user"></i><span><?php echo htmlspecialchars($__shellUser); ?></span></span>
            <a href="<?php echo public_url('auth/logout.php'); ?>" title="Logout" aria-label="Logout"><i class="fas fa-arrow-right-from-bracket"></i></a>
        </div>
    </div>
</header>
<script>
(function () {
  var btn = document.getElementById('tkToggle');
  btn && btn.addEventListener('click', function () {
    var sb = document.getElementById('tSidebar'), ov = document.getElementById('tSidebarOverlay');
    if (window.matchMedia('(max-width: 992px)').matches) {
      var open = !(sb && sb.classList.contains('active'));
      sb && sb.classList.toggle('active', open);
      ov && ov.classList.toggle('active', open);
    } else {
      document.body.classList.toggle('tk-collapsed');
    }
  });
})();
</script>
