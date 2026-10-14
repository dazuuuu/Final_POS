<?php
// public/components/bottom_nav.php
// App-style bottom bar for phones/tablets (≤992px); desktop keeps the sidebar.
//   Home · <group> · [ big action ] · <group> · Menu
// A group tab slides up a sheet of its pages; the centre button is the main
// action (New sale / New order / New client); "Menu" opens a full-screen app
// drawer with every group and a search. Everything comes from PosMenu.

$__groups = PosMenu::groups();
$__byKey = array_column($__groups, null, 'key');
$__activePath = PosMenu::activePath($__groups);
$__activeGroup = PosMenu::activeGroup($__groups, $__activePath);

// The two group tabs: the first available of each preference list.
$__tabKeys = [];
foreach ([['shop', 'restaurant', 'services'], ['stock', 'restaurant', 'services', 'money']] as $__prefs) {
    foreach ($__prefs as $__k) {
        if (isset($__byKey[$__k]) && !in_array($__k, $__tabKeys, true)) { $__tabKeys[] = $__k; break; }
    }
}
// Centre action.
$__fab = null;
foreach ([['shop', 0], ['restaurant', 0], ['services', 1]] as [$__k, $__i]) {
    if (isset($__byKey[$__k]['links'][$__i]) && in_array($__byKey[$__k]['links'][$__i][0], ['New sale', 'New order', 'New client'], true)) {
        $__fab = $__byKey[$__k]['links'][$__i];
        break;
    }
}
$__home = $__byKey['home']['links'][0];
$__shopName = $shopName ?? 'My Shop';
$__drawerOn = !in_array($__activeGroup, array_merge(['home'], $__tabKeys), true) && $__activeGroup !== null;
?>
<nav class="bn" id="bnBar" aria-label="Main">
  <a class="bn-tab<?php echo $__activeGroup === 'home' ? ' on' : ''; ?>" href="<?php echo public_url($__home[2]); ?>"><i class="fas fa-house"></i><span>Home</span></a>
  <?php foreach (array_slice($__tabKeys, 0, 1) as $__k): $__g = $__byKey[$__k]; ?>
    <button type="button" class="bn-tab<?php echo $__activeGroup === $__k ? ' on' : ''; ?>" data-sheet="bn-<?php echo $__k; ?>"><i class="fas <?php echo $__g['icon']; ?>"></i><span><?php echo $__g['label']; ?></span></button>
  <?php endforeach; ?>
  <?php if ($__fab): ?>
    <a class="bn-fab<?php echo $__activePath === $__fab[2] ? ' on' : ''; ?>" href="<?php echo public_url($__fab[2]); ?>" aria-label="<?php echo htmlspecialchars($__fab[0]); ?>">
      <span class="bn-fab-btn"><i class="fas fa-plus"></i></span><span class="bn-fab-lbl"><?php echo $__fab[0] === 'New sale' ? 'Sell' : htmlspecialchars(ucfirst(substr($__fab[0], 4))); ?></span>
    </a>
  <?php endif; ?>
  <?php foreach (array_slice($__tabKeys, 1) as $__k): $__g = $__byKey[$__k]; ?>
    <button type="button" class="bn-tab<?php echo $__activeGroup === $__k ? ' on' : ''; ?>" data-sheet="bn-<?php echo $__k; ?>"><i class="fas <?php echo $__g['icon']; ?>"></i><span><?php echo $__g['label']; ?></span></button>
  <?php endforeach; ?>
  <button type="button" class="bn-tab<?php echo $__drawerOn ? ' on' : ''; ?>" id="bnMore"><i class="fas fa-grip"></i><span>Menu</span></button>
</nav>

<div class="bn-backdrop" id="bnBackdrop"></div>
<?php foreach ($__tabKeys as $__k): $__g = $__byKey[$__k]; ?>
<div class="bn-sheet" id="bn-<?php echo $__k; ?>" role="dialog" aria-label="<?php echo $__g['label']; ?>">
  <div class="bn-grip"></div>
  <div class="bn-title"><i class="fas <?php echo $__g['icon']; ?> me-2"></i><?php echo $__g['label']; ?></div>
  <div class="bn-grid">
    <?php foreach ($__g['links'] as $__l): ?>
      <a href="<?php echo public_url($__l[2]); ?>" class="<?php echo $__l[2] === $__activePath ? 'on' : ''; ?>"><i class="fas <?php echo $__l[1]; ?>"></i><span><?php echo htmlspecialchars($__l[0]); ?></span><?php if ($__l[3]): ?><em><?php echo htmlspecialchars($__l[3]); ?></em><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<div class="bn-drawer" id="bnDrawer" role="dialog" aria-label="Menu">
  <div class="bn-dhead">
    <div><small>Menu</small><strong><?php echo htmlspecialchars($__shopName); ?></strong></div>
    <button type="button" class="bn-x" id="bnDrawerClose" aria-label="Close"><i class="fas fa-xmark"></i></button>
  </div>
  <div class="bn-search"><i class="fas fa-magnifying-glass"></i><input type="search" id="bnFind" placeholder="Find a page… (e.g. returns, stock)" autocomplete="off"></div>
  <div class="bn-dbody">
    <?php foreach ($__groups as $__g): ?>
    <section class="bn-sec" data-sec>
      <h6><i class="fas <?php echo $__g['icon']; ?>"></i><?php echo htmlspecialchars($__g['label']); ?></h6>
      <div class="bn-grid">
        <?php foreach ($__g['links'] as $__l): ?>
          <a href="<?php echo public_url($__l[2]); ?>" class="<?php echo $__l[2] === $__activePath ? 'on' : ''; ?>" data-name="<?php echo htmlspecialchars(strtolower($__l[0] . ' ' . $__g['label'])); ?>"><i class="fas <?php echo $__l[1]; ?>"></i><span><?php echo htmlspecialchars($__l[0]); ?></span><?php if ($__l[3]): ?><em><?php echo htmlspecialchars($__l[3]); ?></em><?php endif; ?></a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
    <p class="bn-none" id="bnNone">Nothing matches. Try the <a href="<?php echo public_url(TenantContext::role() === 'staff' ? 'staff/guide/' : 'super/guide/'); ?>">Help centre</a>.</p>
    <a class="bn-logout" href="<?php echo public_url('auth/logout.php'); ?>"><i class="fas fa-arrow-right-from-bracket me-2"></i>Log out</a>
  </div>
</div>

<style>
.bn,.bn-sheet,.bn-backdrop,.bn-drawer{display:none;}
@media (max-width:992px){
  body{padding-bottom:calc(84px + env(safe-area-inset-bottom));-webkit-tap-highlight-color:transparent;}
  .t-main{animation:bnIn .25s ease-out;}
  @keyframes bnIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
  .bn{display:flex;align-items:flex-end;position:fixed;left:10px;right:10px;bottom:calc(8px + env(safe-area-inset-bottom));z-index:1004;
      height:64px;padding:0 4px;background:rgba(255,255,255,.94);backdrop-filter:saturate(180%) blur(16px);-webkit-backdrop-filter:saturate(180%) blur(16px);
      border:1px solid rgba(15,23,42,.06);border-radius:22px;box-shadow:0 10px 30px rgba(15,23,42,.14);}
  .bn-tab{flex:1 1 0;min-width:0;height:100%;border:0;background:none;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;
          color:#8a8f96 !important;text-decoration:none;font-size:.66rem;font-weight:700;position:relative;}
  .bn-tab i{font-size:1.18rem;transition:transform .18s;}
  .bn-tab:active i{transform:scale(.82);}
  .bn-tab span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;}
  .bn-tab.on{color:var(--tk-green-dark,#15803d) !important;}
  .bn-tab.on::after{content:'';position:absolute;bottom:5px;width:5px;height:5px;border-radius:50%;background:var(--tk-green,#16a34a);}
  .bn-fab{flex:1 1 0;display:flex;flex-direction:column;align-items:center;gap:3px;text-decoration:none;transform:translateY(-14px);}
  .bn-fab-btn{width:58px;height:58px;border-radius:20px;display:grid;place-items:center;color:#fff;font-size:1.35rem;
      background:linear-gradient(135deg,var(--tk-green,#16a34a),#0d9488);border:4px solid var(--pos-bg,#f7f7fb);
      box-shadow:0 10px 22px rgba(22,163,74,.4);transition:transform .15s;}
  .bn-fab:active .bn-fab-btn{transform:scale(.9);}
  .bn-fab-lbl{font-size:.66rem;font-weight:800;color:var(--tk-green-dark,#15803d);}
  .bn-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.42);z-index:1005;opacity:0;transition:opacity .2s;}
  .bn-backdrop.show{display:block;opacity:1;}
  .bn-sheet{display:block;position:fixed;left:0;right:0;bottom:0;z-index:1006;background:#fff;border-radius:24px 24px 0 0;
            padding:8px 16px calc(96px + env(safe-area-inset-bottom));transform:translateY(105%);transition:transform .28s cubic-bezier(.2,.9,.3,1);
            box-shadow:0 -10px 30px rgba(0,0,0,.15);}
  .bn-sheet.show{transform:translateY(0);}
  .bn-grip{width:42px;height:5px;border-radius:5px;background:#d1d5db;margin:4px auto 12px;}
  .bn-title{font-weight:800;font-size:1rem;margin-bottom:14px;color:#1f2330;}
  .bn-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
  .bn-grid a{position:relative;display:flex;flex-direction:column;align-items:center;gap:8px;padding:14px 6px;border-radius:16px;background:#f5f7f6;
             color:#333 !important;text-decoration:none;font-size:.76rem;font-weight:600;text-align:center;transition:transform .12s;}
  .bn-grid a:active{transform:scale(.95);background:#e6f6ee;}
  .bn-grid a.on{background:#e6f6ee;box-shadow:inset 0 0 0 1.5px var(--tk-green,#16a34a);}
  .bn-grid a i{width:42px;height:42px;border-radius:13px;background:#fff;color:var(--tk-green-dark,#15803d);display:flex;align-items:center;justify-content:center;
               font-size:1.05rem;box-shadow:0 1px 3px rgba(0,0,0,.08);}
  .bn-grid a em{position:absolute;top:6px;right:6px;font-style:normal;font-size:.58rem;font-weight:800;background:#ef4444;color:#fff;border-radius:99px;padding:1px 6px;}
  .bn-drawer{display:flex;flex-direction:column;position:fixed;inset:0;z-index:1010;background:var(--pos-bg,#f7f7fb);
             transform:translateY(100%);transition:transform .32s cubic-bezier(.2,.9,.3,1);padding-top:env(safe-area-inset-top);}
  .bn-drawer.show{transform:none;}
  .bn-dhead{display:flex;align-items:center;justify-content:space-between;padding:16px 18px 8px;}
  .bn-dhead small{display:block;font-size:.68rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#8a8f96;}
  .bn-dhead strong{font-size:1.3rem;font-weight:800;letter-spacing:-.02em;}
  .bn-x{width:40px;height:40px;border-radius:50%;border:0;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.1);font-size:1.1rem;}
  .bn-search{margin:6px 18px 10px;position:relative;}
  .bn-search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#9aa0a6;}
  .bn-search input{width:100%;border:1px solid #e5e7eb;border-radius:14px;padding:12px 14px 12px 40px;font-size:16px;background:#fff;outline:none;}
  .bn-search input:focus{border-color:var(--tk-green,#16a34a);}
  .bn-dbody{flex:1;overflow-y:auto;padding:4px 18px calc(30px + env(safe-area-inset-bottom));-webkit-overflow-scrolling:touch;}
  .bn-sec{margin-bottom:18px;}
  .bn-sec h6{font-size:.72rem;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#6b7280;margin:0 0 10px;display:flex;gap:8px;align-items:center;}
  .bn-sec .bn-grid a{background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.05);}
  .bn-sec .bn-grid a i{background:#f0fdf4;box-shadow:none;}
  .bn-none{display:none;text-align:center;color:#6b7280;padding:30px 0;}
  .bn-logout{display:block;text-align:center;padding:14px;border-radius:14px;background:#fff;color:#dc2626 !important;font-weight:700;text-decoration:none;margin-top:8px;}
  /* The header hamburger and slide-out sidebar are replaced by Menu on phones. */
  .tk-toggle{display:none !important;}
}
</style>
<script>
(function () {
  var back = document.getElementById('bnBackdrop'), drawer = document.getElementById('bnDrawer'), open = null;
  function closeAll() {
    document.querySelectorAll('.bn-sheet.show').forEach(function (s) { s.classList.remove('show'); });
    back.classList.remove('show'); open = null;
  }
  document.querySelectorAll('.bn-tab[data-sheet]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = b.getAttribute('data-sheet'), was = open === id;
      closeAll();
      if (!was) { document.getElementById(id).classList.add('show'); back.classList.add('show'); open = id; }
    });
  });
  back.addEventListener('click', closeAll);
  function closeDrawer() { drawer.classList.remove('show'); document.body.style.overflow = ''; }
  document.getElementById('bnMore').addEventListener('click', function () {
    closeAll(); drawer.classList.add('show'); document.body.style.overflow = 'hidden';
  });
  document.getElementById('bnDrawerClose').addEventListener('click', closeDrawer);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeAll(); closeDrawer(); } });
  var find = document.getElementById('bnFind'), none = document.getElementById('bnNone');
  find.addEventListener('input', function () {
    var q = find.value.trim().toLowerCase(), any = false;
    document.querySelectorAll('.bn-sec').forEach(function (sec) {
      var hit = 0;
      sec.querySelectorAll('a[data-name]').forEach(function (a) {
        var m = !q || a.getAttribute('data-name').indexOf(q) !== -1;
        a.style.display = m ? '' : 'none'; if (m) hit++;
      });
      sec.style.display = hit ? '' : 'none'; if (hit) any = true;
    });
    none.style.display = any ? 'none' : 'block';
  });
})();
</script>
