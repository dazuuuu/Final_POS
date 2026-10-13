<?php
// public/components/staff/sidebar.php
// Staff sidebar — focused nav for the 'staff' role. Capability-gated, so a
// person only sees what the owner has granted them. Mirrors the tenant
// sidebar's look (light theme, red accent).

require_once __DIR__ . '/../../../app/helpers/PathConfig.php';

$__tenant   = $__tenant ?? null;
$shopName   = $__tenant['name'] ?? 'My Shop';
$logo = Branding::tenantLogo($__tenant);
$username   = $_SESSION['username'] ?? 'User';
$uri        = $_SERVER['REQUEST_URI'] ?? '';
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
            <span class="t-role">Staff</span>
        </div>
    </div>

    <nav class="t-nav">
        <a class="t-link <?php echo $isOn('/dashboard'); ?>" href="<?php echo public_url('staff/dashboard/'); ?>">
            <i class="fas fa-house"></i><span>Home</span>
        </a>

        <?php if (Features::enabled(Features::RESTAURANT_POS)): ?>
        <a class="t-link <?php echo $isOn('/restaurant/pos'); ?>" href="<?php echo public_url('super/restaurant/pos.php'); ?>"><i class="fas fa-cash-register"></i><span>New order</span></a>
        <a class="t-link <?php echo $isOn('/restaurant/orders'); ?>" href="<?php echo public_url('super/restaurant/orders.php'); ?>"><i class="fas fa-receipt"></i><span>My orders</span></a>
        <?php if (TenantContext::can(Capabilities::PAYMENTS_PROCESS)): ?>
        <a class="t-link <?php echo $isOn('/restaurant/payments'); ?>" href="<?php echo public_url('super/restaurant/payments.php'); ?>"><i class="fas fa-money-bill-wave"></i><span>Payments</span></a>
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
        <?php if (TenantContext::can(Capabilities::CREDIT_SALES)): ?>
        <a class="t-link <?php echo ($isOn('/staff/orders/held') || $isOn('/staff/orders/return')) ? '' : $isOn('/staff/orders'); ?>" href="<?php echo public_url('staff/orders/'); ?>">
            <i class="fas fa-hand-holding-dollar"></i><span>Credit sales</span>
        </a>
        <?php endif; ?>
        <?php if (TenantContext::can(Capabilities::CREDIT_SALES) || TenantContext::can(Capabilities::PAYMENTS_PROCESS)): ?>
        <a class="t-link <?php echo $isOn('/staff/credits'); ?>" href="<?php echo public_url('staff/credits/'); ?>">
            <i class="fas fa-book"></i><span>Credit book</span>
        </a>
        <?php endif; ?>
        <?php if (TenantContext::can(Capabilities::SALES_RECORD)): ?>
        <a class="t-link <?php echo $isOn('/staff/orders/held'); ?>" href="<?php echo public_url('staff/orders/held.php'); ?>">
            <i class="fas fa-pause"></i><span>Held sales</span>
        </a>
        <?php endif; ?>

        <?php if (TenantContext::can(Capabilities::PAYMENTS_PROCESS)): ?>
        <a class="t-link <?php echo $isOn('/staff/payments'); ?>" href="<?php echo public_url('staff/payments/'); ?>">
            <i class="fas fa-cash-register"></i><span>Payments</span>
        </a>
        <a class="t-link <?php echo $isOn('/staff/orders/return'); ?>" href="<?php echo public_url('staff/orders/return.php'); ?>">
            <i class="fas fa-rotate-left"></i><span>Returns</span>
        </a>
        <?php endif; ?>

        <?php if (TenantContext::can(Capabilities::STOCK_ENTER)): ?>
        <a class="t-link <?php echo $isOn('/staff/stock'); ?>" href="<?php echo public_url('staff/stock/new.php'); ?>">
            <i class="fas fa-truck-loading"></i><span>Record stock</span>
        </a>
        <?php endif; ?>
        <?php if (TenantContext::can(Capabilities::INVENTORY_VIEW)): ?>
        <a class="t-link <?php echo $isOn('/staff/inventory'); ?>" href="<?php echo public_url('staff/inventory/'); ?>">
            <i class="fas fa-warehouse"></i><span>Inventory</span>
        </a>
        <?php endif; ?>
        <?php if (TenantContext::can(Capabilities::EXPENSES_RECORD)): ?>
        <a class="t-link <?php echo $isOn('/staff/expenses'); ?>" href="<?php echo public_url('staff/expenses/'); ?>">
            <i class="fas fa-file-invoice"></i><span>Expenses</span>
        </a>
        <?php endif; ?>

        <?php if (TenantContext::can(Capabilities::SALES_VIEW)): ?>
        <a class="t-link <?php echo $isOn('/staff/sales'); ?>" href="<?php echo public_url('staff/sales/'); ?>">
            <i class="fas fa-receipt"></i><span>Sales</span>
        </a>
        <?php endif; ?>

        <a class="t-link <?php echo $isOn('/staff/guide'); ?>" href="<?php echo public_url('staff/guide/'); ?>">
            <i class="fas fa-circle-question"></i><span>Guide</span>
        </a>

        <?php endif; /* shop POS */ ?>

        <hr>

        <a class="t-link <?php echo $isOn('/staff/change-pin'); ?>" href="<?php echo public_url('staff/change-pin.php'); ?>">
            <i class="fas fa-key"></i><span>Change PIN</span>
        </a>
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
})();
</script>
