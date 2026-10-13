<?php
// public/components/bottom_nav.php
// App-style bottom navigation for phones/tablets (≤992px). Desktop keeps the
// sidebar. Related menus are grouped: a tab either opens a page directly or
// slides up a sheet of its links; "More" opens the full sidebar. Every link
// is capability-gated the same way as the sidebar.

$__isStaff = TenantContext::role() === 'staff';
$__a = $__isStaff ? 'staff' : 'super';
$__uri = $_SERVER['REQUEST_URI'] ?? '';
$__can = fn(string $c) => TenantContext::can($c);
$__credit = $__can(Capabilities::CREDIT_SALES);
$__pay = $__can(Capabilities::PAYMENTS_PROCESS);
$__invView = $__can(Capabilities::INVENTORY_VIEW) || $__can(Capabilities::INVENTORY_EDIT);

// [label, icon, path, visible]
if ($__isStaff) {
    $__tabs = [
        ['key' => 'pos', 'label' => 'POS', 'icon' => 'fa-cash-register', 'url' => 'staff/dashboard/', 'match' => ['/staff/dashboard']],
        ['key' => 'credit', 'label' => 'Credit', 'icon' => 'fa-hand-holding-dollar', 'links' => [
            ['Credit sales', 'fa-hand-holding-dollar', 'staff/orders/', $__credit],
            ['Credit book', 'fa-book', 'staff/credits/', $__credit || $__pay],
            ['Payments', 'fa-money-check-dollar', 'staff/payments/', $__pay],
            ['Held sales', 'fa-pause', 'staff/orders/held.php', $__can(Capabilities::SALES_RECORD)],
            ['Returns', 'fa-rotate-left', 'staff/orders/return.php', $__pay],
        ]],
        ['key' => 'inv', 'label' => 'Inventory', 'icon' => 'fa-boxes-stacked', 'links' => [
            ['Inventory', 'fa-warehouse', 'staff/inventory/', $__invView],
            ['Record stock', 'fa-truck-loading', 'staff/stock/new.php', $__can(Capabilities::STOCK_ENTER)],
            ['Suppliers', 'fa-truck-field', 'staff/suppliers/', $__can(Capabilities::INVENTORY_EDIT)],
        ]],
        ['key' => 'fin', 'label' => 'Finance', 'icon' => 'fa-wallet', 'links' => [
            ['Expenses', 'fa-file-invoice', 'staff/expenses/', $__can(Capabilities::EXPENSES_RECORD)],
            ['My sales', 'fa-receipt', 'staff/sales/', $__can(Capabilities::SALES_VIEW)],
            ['Payments', 'fa-money-check-dollar', 'staff/payments/', $__pay],
        ]],
    ];
} else {
    $__tabs = [
        ['key' => 'home', 'label' => 'Dashboard', 'icon' => 'fa-house', 'url' => 'super/dashboard/', 'match' => ['/super/dashboard']],
        ['key' => 'pos', 'label' => 'POS', 'icon' => 'fa-cash-register', 'url' => 'super/pos/', 'match' => ['/super/pos']],
        ['key' => 'shops', 'label' => 'Shops', 'icon' => 'fa-shop', 'links' => [
            ['My shops', 'fa-shop', 'super/shops/', true],
            ['Guide', 'fa-circle-question', 'super/guide/', true],
            ['Credit sales', 'fa-hand-holding-dollar', 'super/orders/', $__credit],
            ['Credit book', 'fa-book', 'super/credits/', $__credit],
            ['Held sales', 'fa-pause', 'super/orders/held.php', $__can(Capabilities::SALES_RECORD)],
            ['Customers', 'fa-address-book', 'super/customers/', $__can(Capabilities::CUSTOMERS_MANAGE)],
            ['Catalogue', 'fa-store', 'super/catalogue/', $__can(Capabilities::CATALOGUE_SEND)],
        ]],
        ['key' => 'inv', 'label' => 'Inventory', 'icon' => 'fa-boxes-stacked', 'links' => [
            ['Inventory', 'fa-warehouse', 'super/inventory/', $__invView],
            ['Record stock', 'fa-truck-loading', 'super/stock/new.php', $__can(Capabilities::STOCK_ENTER)],
            ['Purchases', 'fa-cart-flatbed', 'super/purchases/', TenantContext::role() === 'tenant_owner'],
            ['Warehouse', 'fa-building', 'super/warehouse/', ShopContext::isMainShop()],
            ['Transfer to shops', 'fa-right-left', 'super/transfer/', ShopContext::isMainShop()],
            ['Products', 'fa-box', 'super/products/', $__can(Capabilities::INVENTORY_EDIT)],
            ['Categories', 'fa-tags', 'super/categories/', $__can(Capabilities::INVENTORY_EDIT)],
            ['Suppliers', 'fa-truck-field', 'super/suppliers/', $__can(Capabilities::INVENTORY_EDIT)],
        ]],
        ['key' => 'fin', 'label' => 'Finance', 'icon' => 'fa-wallet', 'links' => [
            ['Expenses', 'fa-file-invoice', 'super/expenses/', true],
            ['Finances', 'fa-scale-balanced', 'super/finances/', true],
            ['Payments', 'fa-money-check-dollar', 'super/payments/', $__pay],
            ['Returns', 'fa-rotate-left', 'super/orders/return.php', $__pay],
            ['Sales', 'fa-receipt', 'super/sales/', true],
            ['Reports', 'fa-chart-line', 'super/reports/', $__can(Capabilities::REPORTS_VIEW)],
        ]],
    ];
}

// Shop POS package off: only the dashboard tab remains.
if (!Features::enabled(Features::SHOP_POS)) {
    $__tabs = [$__isStaff
        ? ['key' => 'pos', 'label' => 'Home', 'icon' => 'fa-house', 'url' => 'staff/dashboard/', 'match' => ['/staff/dashboard']]
        : $__tabs[0]];
}
if ($__isStaff && Features::enabled(Features::RESTAURANT_POS)) {
    $__rest = [
        ['key' => 'rpos', 'label' => 'Order', 'icon' => 'fa-cash-register', 'url' => 'super/restaurant/pos.php', 'match' => ['/restaurant/pos']],
        ['key' => 'rord', 'label' => 'My orders', 'icon' => 'fa-receipt', 'url' => 'super/restaurant/orders.php', 'match' => ['/restaurant/orders']],
    ];
    if ($__pay) {
        $__rest[] = ['key' => 'rpay', 'label' => 'Payments', 'icon' => 'fa-money-bill-wave', 'url' => 'super/restaurant/payments.php', 'match' => ['/restaurant/payments']];
    }
    $__tabs = Features::enabled(Features::SHOP_POS) ? array_merge($__rest, $__tabs) : $__rest;
}
if (!$__isStaff && Features::enabled(Features::RESTAURANT_POS)) {
    $__inv = $__can(Capabilities::INVENTORY_EDIT);
    array_splice($__tabs, 1, 0, [['key' => 'rest', 'label' => 'Restaurant', 'icon' => 'fa-utensils', 'links' => [
        ['New order', 'fa-cash-register', 'super/restaurant/pos.php', $__can(Capabilities::SALES_RECORD)],
        ['Orders', 'fa-receipt', 'super/restaurant/orders.php', $__can(Capabilities::SALES_RECORD)],
        ['Payments', 'fa-money-bill-wave', 'super/restaurant/payments.php', $__pay],
        ['Waiters', 'fa-user-tie', 'super/restaurant/staff.php', $__can(Capabilities::STAFF_MANAGE)],
        ['Share menu', 'fa-qrcode', 'super/restaurant/share.php', $__can(Capabilities::SETTINGS_MANAGE)],
        ['Menu', 'fa-utensils', 'super/restaurant/menu.php', $__inv],
        ['Categories', 'fa-tags', 'super/restaurant/categories.php', $__inv],
        ['Ingredients', 'fa-carrot', 'super/restaurant/ingredients.php', $__inv],
        ['Store stock', 'fa-boxes-stacked', 'super/restaurant/stock.php', $__inv],
        ['Transfers', 'fa-right-left', 'super/restaurant/transfer.php', $__inv],
    ]]]);
}

// Electronics package: device serials + warranty/repairs under Inventory.
if (Features::enabled(Features::ELECTRONICS)) {
    foreach ($__tabs as &$__t) {
        if (($__t['key'] ?? '') === 'inv' && isset($__t['links'])) {
            $__t['links'][] = ['Devices & serials', 'fa-mobile-screen', 'super/electronics/', $__can(Capabilities::INVENTORY_VIEW)];
            $__t['links'][] = ['Warranty & repairs', 'fa-screwdriver-wrench', 'super/electronics/repairs.php', $__can(Capabilities::SALES_RECORD)];
        }
    }
    unset($__t);
}

// Services package: its own tab (front desk, new client, my clients…).
if (Features::enabled(Features::SERVICES)) {
    $__desk = !$__isStaff || $__can(Capabilities::SERVICES_BOOK) || $__can(Capabilities::SERVICES_CASHIER);
    array_splice($__tabs, 2, 0, [['key' => 'svc', 'label' => 'Services', 'icon' => 'fa-user-gear', 'links' => [
        ['Front desk', 'fa-clipboard-list', 'super/services/', $__desk],
        ['New client', 'fa-user-plus', 'super/services/new.php', $__can(Capabilities::SERVICES_BOOK)],
        ['My clients', 'fa-user-check', 'super/services/mine.php', true],
        ['Services & prices', 'fa-list', 'super/services/catalog.php', $__can(Capabilities::SERVICES_MANAGE)],
        ['Commissions', 'fa-percent', 'super/services/commissions.php', !$__isStaff],
    ]]]);
}

// Drop hidden links and empty groups; work out which tab is active.
$__active = null;
foreach ($__tabs as $i => &$__t) {
    if (isset($__t['links'])) {
        $__t['links'] = array_values(array_filter($__t['links'], fn($l) => $l[3]));
        if (!$__t['links']) { unset($__tabs[$i]); continue; }
        foreach ($__t['links'] as $l) {
            $path = '/' . rtrim(preg_replace('/index\.php$/', '', $l[2]), '/');
            if ($__active === null && strpos($__uri, $path) !== false) { $__active = $__t['key']; }
        }
    } else {
        foreach ($__t['match'] as $m) { if ($__active === null && strpos($__uri, $m) !== false) { $__active = $__t['key']; } }
    }
}
unset($__t);
?>
<nav class="bn" id="bnBar" aria-label="Main">
  <?php foreach ($__tabs as $t): $on = $__active === $t['key'] ? ' on' : ''; ?>
    <?php if (isset($t['url'])): ?>
      <a class="bn-tab<?php echo $on; ?>" href="<?php echo public_url($t['url']); ?>"><i class="fas <?php echo $t['icon']; ?>"></i><span><?php echo $t['label']; ?></span></a>
    <?php else: ?>
      <button type="button" class="bn-tab<?php echo $on; ?>" data-sheet="bn-<?php echo $t['key']; ?>"><i class="fas <?php echo $t['icon']; ?>"></i><span><?php echo $t['label']; ?></span></button>
    <?php endif; ?>
  <?php endforeach; ?>
  <button type="button" class="bn-tab" id="bnMore"><i class="fas fa-ellipsis"></i><span>More</span></button>
</nav>

<div class="bn-backdrop" id="bnBackdrop"></div>
<?php foreach ($__tabs as $t): if (!isset($t['links'])) { continue; } ?>
<div class="bn-sheet" id="bn-<?php echo $t['key']; ?>" role="dialog" aria-label="<?php echo $t['label']; ?>">
  <div class="bn-grip"></div>
  <div class="bn-title"><?php echo $t['label']; ?></div>
  <div class="bn-grid">
    <?php foreach ($t['links'] as $l): ?>
      <a href="<?php echo public_url($l[2]); ?>"><i class="fas <?php echo $l[1]; ?>"></i><span><?php echo htmlspecialchars($l[0]); ?></span></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<style>
.bn,.bn-sheet,.bn-backdrop{display:none;}
@media (max-width:992px){
  body{padding-bottom:calc(64px + env(safe-area-inset-bottom));}
  .bn{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:1004;background:#fff;border-top:1px solid #e5e7eb;
      box-shadow:0 -4px 18px rgba(16,24,40,.08);padding:0 4px env(safe-area-inset-bottom);height:calc(62px + env(safe-area-inset-bottom));}
  .bn-tab{flex:1 1 0;min-width:0;border:0;background:none;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;
          color:#8a8f96 !important;text-decoration:none;font-size:.66rem;font-weight:600;padding:6px 0;position:relative;-webkit-tap-highlight-color:transparent;}
  .bn-tab i{font-size:1.15rem;}
  .bn-tab span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;}
  .bn-tab.on{color:var(--tk-green-dark) !important;}
  .bn-tab.on::before{content:'';position:absolute;top:0;left:28%;right:28%;height:3px;border-radius:0 0 3px 3px;background:var(--tk-green);}
  .bn-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.4);z-index:1005;}
  .bn-backdrop.show{display:block;}
  .bn-sheet{display:block;position:fixed;left:0;right:0;bottom:0;z-index:1006;background:#fff;border-radius:20px 20px 0 0;
            padding:8px 16px calc(78px + env(safe-area-inset-bottom));transform:translateY(105%);transition:transform .22s ease;box-shadow:0 -10px 30px rgba(0,0,0,.15);}
  .bn-sheet.show{transform:translateY(0);}
  .bn-grip{width:40px;height:4px;border-radius:4px;background:#d1d5db;margin:4px auto 10px;}
  .bn-title{font-weight:700;font-size:.95rem;margin-bottom:12px;color:#333;}
  .bn-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
  .bn-grid a{display:flex;flex-direction:column;align-items:center;gap:8px;padding:14px 6px;border-radius:14px;background:#f5f7f6;
             color:#333 !important;text-decoration:none;font-size:.76rem;font-weight:600;text-align:center;}
  .bn-grid a i{width:40px;height:40px;border-radius:12px;background:#fff;color:var(--tk-green-dark);display:flex;align-items:center;justify-content:center;font-size:1.05rem;box-shadow:0 1px 2px rgba(0,0,0,.08);}
  .bn-grid a:active{background:#e6f6ee;}
  /* Header hamburger is redundant with "More" on phones. */
  .tk-toggle{display:none !important;}
  .t-sidebar{bottom:calc(62px + env(safe-area-inset-bottom)) !important;}
}
</style>
<script>
(function () {
  var back = document.getElementById('bnBackdrop'), open = null;
  function closeAll() {
    document.querySelectorAll('.bn-sheet.show').forEach(function (s) { s.classList.remove('show'); });
    back.classList.remove('show'); open = null;
  }
  document.querySelectorAll('.bn-tab[data-sheet]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = b.getAttribute('data-sheet'), was = open === id;
      closeAll(); closeSidebar();
      if (!was) { document.getElementById(id).classList.add('show'); back.classList.add('show'); open = id; }
    });
  });
  back.addEventListener('click', closeAll);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(); });
  function closeSidebar() {
    var sb = document.getElementById('tSidebar'), ov = document.getElementById('tSidebarOverlay');
    sb && sb.classList.remove('active'); ov && ov.classList.remove('active'); document.body.style.overflow = '';
  }
  document.getElementById('bnMore').addEventListener('click', function () {
    closeAll();
    var sb = document.getElementById('tSidebar'), ov = document.getElementById('tSidebarOverlay');
    var show = !(sb && sb.classList.contains('active'));
    sb && sb.classList.toggle('active', show); ov && ov.classList.toggle('active', show);
  });
})();
</script>
