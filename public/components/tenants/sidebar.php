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

    <?php include __DIR__ . '/../pos_nav.php'; ?>
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
