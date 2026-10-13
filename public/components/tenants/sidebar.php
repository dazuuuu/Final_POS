<?php
// public/components/tenants/sidebar.php
// Tenant (shop owner) sidebar. Capability-gated POS navigation with the tenant's
// own business name + logo. Expects $__tenant (array|null) and a booted TenantContext.
// NOTE: this is the TENANT sidebar; the staff version will be duplicated from it later.

require_once __DIR__ . '/../../../app/helpers/PathConfig.php';

$__tenant   = $__tenant ?? null;
$shopName   = $__tenant['name'] ?? 'My Shop';
$logo = Branding::tenantLogo($__tenant);
$username   = $_SESSION['username'] ?? 'User';
$roleLabel  = TenantContext::role() === 'tenant_owner' ? 'Owner' : 'Staff';
$uri        = $_SERVER['REQUEST_URI'] ?? '';
$dashUrl    = TenantContext::role() === 'staff' ? public_url('staff/dashboard/') : public_url('super/dashboard/');

$isOn = function (string $needle) use ($uri): string {
    return strpos($uri, $needle) !== false ? 'active' : '';
};
?>
<div class="t-sidebar-overlay" id="tSidebarOverlay"></div>

<aside class="t-sidebar" id="tSidebar">
    <div class="t-brand">
        <button class="t-close" id="tSidebarClose" aria-label="Close"><i class="fas fa-times"></i></button>
        <img class="t-logo" src="<?php echo htmlspecialchars($logo); ?>" alt="<?php echo htmlspecialchars($shopName); ?>">
        <div class="t-shop"><?php echo htmlspecialchars($shopName); ?></div>
        <div class="t-user">
            <?php echo htmlspecialchars($username); ?>
            <span class="t-role"><?php echo htmlspecialchars($roleLabel); ?></span>
        </div>
    </div>

    <nav class="t-nav">
        <div class="t-section">Home</div>
        <a class="t-link <?php echo $isOn('/dashboard'); ?>" href="<?php echo $dashUrl; ?>">
            <i class="fas fa-house"></i><span>Home</span>
        </a>

        <?php if (Features::enabled(Features::RESTAURANT_POS)): ?>
        <div class="t-section">Restaurant</div>
        <?php if (TenantContext::can(Capabilities::SALES_RECORD)): ?>
        <a class="t-link <?php echo $isOn('/restaurant/pos'); ?>" href="<?php echo public_url('super/restaurant/pos.php'); ?>"><i class="fas fa-cash-register"></i><span>New order</span></a>
        <a class="t-link <?php echo $isOn('/restaurant/orders'); ?>" href="<?php echo public_url('super/restaurant/orders.php'); ?>"><i class="fas fa-receipt"></i><span>Orders</span></a>
        <?php endif; ?>
        <?php if (TenantContext::can(Capabilities::PAYMENTS_PROCESS)):
            $__online = 0;
            try {
                $__st = Database::pdo()->prepare("SELECT COUNT(*) FROM rest_orders WHERE tenant_id = ? AND source = 'online' AND status = 'open'");
                $__st->execute([TenantContext::tenantId()]);
                $__online = (int) $__st->fetchColumn();
            } catch (Throwable $e) { /* tables not created yet */ }
        ?>
        <a class="t-link <?php echo $isOn('/restaurant/payments'); ?>" href="<?php echo public_url('super/restaurant/payments.php'); ?>"><i class="fas fa-money-bill-wave"></i><span>Payments</span><?php if ($__online): ?><span class="t-soon" style="background:#1f6feb;color:#fff;"><?php echo $__online; ?> online</span><?php endif; ?></a>
        <?php endif; ?>
        <?php if (TenantContext::can(Capabilities::SETTINGS_MANAGE)): ?>
        <a class="t-link <?php echo $isOn('/restaurant/share'); ?>" href="<?php echo public_url('super/restaurant/share.php'); ?>"><i class="fas fa-qrcode"></i><span>Share menu / QR</span></a>
        <?php endif; ?>
        <?php if (TenantContext::can(Capabilities::STAFF_MANAGE)): ?>
        <a class="t-link <?php echo $isOn('/restaurant/staff'); ?>" href="<?php echo public_url('super/restaurant/staff.php'); ?>"><i class="fas fa-user-tie"></i><span>Waiters &amp; staff</span></a>
        <?php endif; ?>
        <?php if (TenantContext::can(Capabilities::INVENTORY_EDIT)): ?>
        <a class="t-link <?php echo $isOn('/restaurant/menu'); ?>" href="<?php echo public_url('super/restaurant/menu.php'); ?>"><i class="fas fa-utensils"></i><span>Menu</span></a>
        <a class="t-link <?php echo $isOn('/restaurant/categories'); ?>" href="<?php echo public_url('super/restaurant/categories.php'); ?>"><i class="fas fa-tags"></i><span>Menu categories</span></a>
        <a class="t-link <?php echo $isOn('/restaurant/ingredients'); ?>" href="<?php echo public_url('super/restaurant/ingredients.php'); ?>"><i class="fas fa-carrot"></i><span>Ingredients</span></a>
        <a class="t-link <?php echo $isOn('/restaurant/stock'); ?>" href="<?php echo public_url('super/restaurant/stock.php'); ?>"><i class="fas fa-boxes-stacked"></i><span>Store stock</span></a>
        <a class="t-link <?php echo $isOn('/restaurant/transfer'); ?>" href="<?php echo public_url('super/restaurant/transfer.php'); ?>"><i class="fas fa-right-left"></i><span>Transfers</span></a>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (Features::enabled(Features::SERVICES)):
            $__su = $_SERVER['REQUEST_URI'] ?? '';
            $__sOn = fn($n) => strpos($__su, $n) !== false ? 'active' : ''; ?>
        <div class="t-section">Services</div>
        <?php if (TenantContext::role() === 'tenant_owner' || TenantContext::can(Capabilities::SERVICES_BOOK) || TenantContext::can(Capabilities::SERVICES_CASHIER)): ?>
        <a class="t-link <?php echo preg_match('#/services/(index\.php|view\.php|receipt\.php)?(\?|$)#', $__su) ? 'active' : ''; ?>" href="<?php echo public_url('super/services/'); ?>"><i class="fas fa-clipboard-list"></i><span>Front desk</span></a>
        <?php endif; ?>
        <?php if (TenantContext::can(Capabilities::SERVICES_BOOK)): ?>
        <a class="t-link <?php echo $__sOn('/services/new.php'); ?>" href="<?php echo public_url('super/services/new.php'); ?>"><i class="fas fa-user-plus"></i><span>New client</span></a>
        <?php endif; ?>
        <a class="t-link <?php echo $__sOn('/services/mine.php'); ?>" href="<?php echo public_url('super/services/mine.php'); ?>"><i class="fas fa-user-check"></i><span>My clients</span></a>
        <?php if (TenantContext::can(Capabilities::SERVICES_MANAGE)): ?>
        <a class="t-link <?php echo $__sOn('/services/catalog.php'); ?>" href="<?php echo public_url('super/services/catalog.php'); ?>"><i class="fas fa-list"></i><span>Services &amp; prices</span></a>
        <?php endif; ?>
        <?php if (TenantContext::role() === 'tenant_owner'): ?>
        <a class="t-link <?php echo $__sOn('/services/commissions.php'); ?>" href="<?php echo public_url('super/services/commissions.php'); ?>"><i class="fas fa-percent"></i><span>Commissions</span></a>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (Features::enabled(Features::ELECTRONICS)): ?>
        <div class="t-section">Electronics</div>
        <?php if (TenantContext::can(Capabilities::INVENTORY_VIEW)): ?>
        <a class="t-link <?php echo strpos($_SERVER['REQUEST_URI'] ?? '', '/electronics') !== false && strpos($_SERVER['REQUEST_URI'] ?? '', '/repairs') === false ? 'active' : ''; ?>" href="<?php echo public_url('super/electronics/'); ?>"><i class="fas fa-mobile-screen"></i><span>Devices &amp; serials</span></a>
        <?php endif; ?>
        <?php if (TenantContext::can(Capabilities::SALES_RECORD)): ?>
        <a class="t-link <?php echo strpos($_SERVER['REQUEST_URI'] ?? '', '/electronics/repairs') !== false ? 'active' : ''; ?>" href="<?php echo public_url('super/electronics/repairs.php'); ?>"><i class="fas fa-screwdriver-wrench"></i><span>Warranty &amp; repairs</span></a>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (Features::enabled(Features::SHOP_POS)): ?>
        <?php if (TenantContext::role() === 'tenant_owner'): ?>
        <div class="t-section"><?php echo ShopContext::isMainShop() ? 'Admin portal' : 'My business'; ?></div>
        <a class="t-link <?php echo $isOn('/super/shops'); ?>" href="<?php echo public_url('super/shops/'); ?>"><i class="fas fa-shop"></i><span>My shops</span></a>
        <a class="t-link <?php echo $isOn('/super/purchases'); ?>" href="<?php echo public_url('super/purchases/'); ?>"><i class="fas fa-cart-flatbed"></i><span>Purchases</span></a>
        <?php if (ShopContext::isMainShop()): ?>
        <a class="t-link <?php echo $isOn('/super/warehouse'); ?>" href="<?php echo public_url('super/warehouse/'); ?>"><i class="fas fa-warehouse"></i><span>Warehouse</span></a>
        <a class="t-link <?php echo $isOn('/super/transfer'); ?>" href="<?php echo public_url('super/transfer/'); ?>"><i class="fas fa-right-left"></i><span>Transfer to shops</span></a>
        <?php $__pendingUpdates = count(Updates::pending()); ?>
        <a class="t-link <?php echo $isOn('/super/updates'); ?>" href="<?php echo public_url('super/updates/'); ?>"><i class="fas fa-download"></i><span>Updates</span><?php if ($__pendingUpdates): ?><span class="t-soon" style="background:#f2473f;color:#fff;"><?php echo $__pendingUpdates; ?> new</span><?php endif; ?></a>
        <?php endif; ?>
        <?php endif; ?>

        <div class="t-section">Shop</div>
        <?php if (TenantContext::can(Capabilities::SALES_RECORD)): ?>
        <?php if (TenantContext::role() === 'tenant_owner'): ?>
        <a class="t-link <?php echo $isOn('/super/pos'); ?>" href="<?php echo public_url('super/pos/'); ?>"><i class="fas fa-cash-register"></i><span>New sale</span></a>
        <?php endif; ?>
        <?php if (TenantContext::can(Capabilities::CREDIT_SALES)): ?>
        <a class="t-link <?php echo ($isOn('/super/orders/held') || $isOn('/super/orders/return')) ? '' : $isOn('/super/orders'); ?>" href="<?php echo public_url('super/orders/'); ?>"><i class="fas fa-hand-holding-dollar"></i><span>Credit sales</span></a>
        <a class="t-link <?php echo $isOn('/super/credits'); ?>" href="<?php echo public_url('super/credits/'); ?>"><i class="fas fa-book"></i><span>Credit book</span></a>
        <?php endif; ?>
        <a class="t-link <?php echo $isOn('/super/orders/held'); ?>" href="<?php echo public_url('super/orders/held.php'); ?>"><i class="fas fa-pause"></i><span>Held sales</span></a>
        <?php endif; ?>
        <?php if (TenantContext::can(Capabilities::PAYMENTS_PROCESS)): ?>
        <a class="t-link <?php echo $isOn('/super/payments'); ?>" href="<?php echo public_url('super/payments/'); ?>"><i class="fas fa-money-check-dollar"></i><span>Payments</span></a>
        <a class="t-link <?php echo $isOn('/super/orders/return'); ?>" href="<?php echo public_url('super/orders/return.php'); ?>"><i class="fas fa-rotate-left"></i><span>Returns</span></a>
        <?php endif; ?>
        <?php if (TenantContext::can(Capabilities::CATALOGUE_SEND)): ?>
        <a class="t-link <?php echo $isOn('/super/catalogue'); ?>" href="<?php echo public_url('super/catalogue/'); ?>"><i class="fas fa-store"></i><span>Share catalogue</span></a>
        <?php endif; ?>
        <a class="t-link <?php echo $isOn('/super/documents'); ?>" href="<?php echo public_url('super/documents/'); ?>">
            <i class="fas fa-file-invoice"></i><span>Documents</span>
        </a>
        <?php if (TenantContext::can(Capabilities::INVENTORY_EDIT)): ?>
        <a class="t-link <?php echo strpos($uri, '/super/categories') !== false ? 'active' : ''; ?>" href="<?php echo public_url('super/categories/'); ?>">
            <i class="fas fa-tags"></i><span>Category</span>
        </a>
        <a class="t-link <?php echo $isOn('/super/suppliers'); ?>" href="<?php echo public_url('super/suppliers/'); ?>">
            <i class="fas fa-truck-field"></i><span>Suppliers</span>
        </a>
        <a class="t-link <?php echo $isOn('/super/stock'); ?>" href="<?php echo public_url('super/stock/new.php'); ?>">
            <i class="fas fa-truck-loading"></i><span>Record stock</span>
        </a>
        <?php endif; ?>
        <?php if (TenantContext::can(Capabilities::INVENTORY_VIEW) || TenantContext::can(Capabilities::INVENTORY_EDIT)): ?>
        <a class="t-link <?php echo $isOn('/super/inventory'); ?>" href="<?php echo public_url('super/inventory/'); ?>">
            <i class="fas fa-warehouse"></i><span>Inventory</span>
        </a>
        <?php endif; ?>

        <div class="t-section">Management</div>
        <?php if (TenantContext::can(Capabilities::STAFF_MANAGE)):
            $onPermissions = strpos($uri, 'permissions') !== false;
            $onAttendance  = strpos($uri, 'attendance') !== false;
        ?>
        <a class="t-link <?php echo ($isOn('/super/staff') && !$onPermissions && !$onAttendance) ? 'active' : ''; ?>" href="<?php echo public_url('super/staff/'); ?>">
            <i class="fas fa-user-gear"></i><span>Staff</span>
        </a>
        <a class="t-link <?php echo $onPermissions ? 'active' : ''; ?>" href="<?php echo public_url('super/staff/permissions.php'); ?>">
            <i class="fas fa-key"></i><span>Permissions</span>
        </a>
        <a class="t-link <?php echo $onAttendance ? 'active' : ''; ?>" href="<?php echo public_url('super/staff/attendance.php'); ?>">
            <i class="fas fa-clock"></i><span>Attendance</span>
        </a>
        <?php endif; ?>
        <?php if (TenantContext::can(Capabilities::EXPENSES_RECORD)): ?>
        <a class="t-link <?php echo $isOn('/super/expenses'); ?>" href="<?php echo public_url('super/expenses/'); ?>">
            <i class="fas fa-file-invoice"></i><span>Expenses</span>
        </a>
        <?php endif; ?>
        <a class="t-link <?php echo $isOn('/super/finances'); ?>" href="<?php echo public_url('super/finances/'); ?>">
            <i class="fas fa-scale-balanced"></i><span>Finances</span>
        </a>
        <?php if (TenantContext::can(Capabilities::CUSTOMERS_MANAGE)): ?>
        <a class="t-link <?php echo $isOn('/super/customers'); ?>" href="<?php echo public_url('super/customers/'); ?>">
            <i class="fas fa-address-book"></i><span>Customers</span>
        </a>
        <?php endif; ?>

        <div class="t-section">Reports</div>
        <?php if (TenantContext::role() === 'tenant_owner'): ?>
        <a class="t-link <?php echo $isOn('/super/sales'); ?>" href="<?php echo public_url('super/sales/'); ?>"><i class="fas fa-receipt"></i><span>Sales</span></a>
        <?php endif; ?>
        <?php if (TenantContext::can(Capabilities::REPORTS_VIEW)): ?>
        <a class="t-link <?php echo $isOn('/super/reports'); ?>" href="<?php echo public_url('super/reports/'); ?>"><i class="fas fa-chart-line"></i><span>Reports</span></a>
        <?php endif; ?>

        <div class="t-section">Settings</div>
        <?php if (TenantContext::role() === 'tenant_owner' && (int) ($__tenant['owner_user_id'] ?? 0) === (int) TenantContext::userId()): ?>
        <a class="t-link <?php echo $isOn('/super/admins'); ?>" href="<?php echo public_url('super/admins/'); ?>">
            <i class="fas fa-user-shield"></i><span>Admins</span>
        </a>
        <?php endif; ?>
        <?php if (TenantContext::can(Capabilities::SETTINGS_MANAGE)): ?>
        <a class="t-link <?php echo $isOn('/super/settings'); ?>" href="<?php echo public_url('super/settings/'); ?>"><i class="fas fa-gear"></i><span>Settings</span></a>
        <?php endif; ?>
        <?php if (TenantContext::role() === 'tenant_owner' && (int) ($__tenant['owner_user_id'] ?? 0) === (int) TenantContext::userId()): ?>
        <a class="t-link <?php echo $isOn('/super/cleanup'); ?>" href="<?php echo public_url('super/cleanup/'); ?>">
            <i class="fas fa-broom"></i><span>Cleanup</span>
        </a>
        <?php endif; ?>

        <div class="t-section">Help</div>
        <a class="t-link <?php echo $isOn('/super/guide'); ?>" href="<?php echo public_url('super/guide/'); ?>"><i class="fas fa-circle-question"></i><span>Guide</span></a>

        <?php endif; /* shop POS */ ?>

        <hr>

   
        <a class="t-link t-danger" href="<?php echo public_url('auth/logout.php'); ?>">
            <i class="fas fa-arrow-right-from-bracket"></i><span>Logout</span>
        </a>
    </nav>
</aside>

<script>
(function(){
  var tg=document.getElementById('tSidebarToggle'),sb=document.getElementById('tSidebar'),
      ov=document.getElementById('tSidebarOverlay'),cl=document.getElementById('tSidebarClose');
  function open(){sb&&sb.classList.add('active');ov&&ov.classList.add('active');document.body.style.overflow='hidden';}
  function close(){sb&&sb.classList.remove('active');ov&&ov.classList.remove('active');document.body.style.overflow='';}
  tg&&tg.addEventListener('click',open); cl&&cl.addEventListener('click',close); ov&&ov.addEventListener('click',close);
  document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});
  document.querySelectorAll('[data-soon]').forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();});});
})();
</script>
