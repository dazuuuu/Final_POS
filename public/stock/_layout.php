<?php
// public/stock/_layout.php — the Stock Control app frame: a light sidebar
// (organisation logo, store switcher, GENERAL / MASTER / SUPPORT sections,
// the signed-in person and Logout at the bottom) beside a soft grey work area
// with a breadcrumb bar, a notice banner and rounded white cards; green accents.
// Expects $title, $content, $tab. Page markup uses the classes defined here.
$__stores = StockControl::myStores();
$__store = (int) TenantContext::tenantId();
$__orgRow = StockControl::stores()[StockControl::account()] ?? [];
$__org = $__orgRow['name'] ?? '';
$__logo = Branding::shopLogo($__orgRow);
$__flash = stock_flash();
$__who = $_SESSION['username'] ?? '';
$__email = '';
try {
    $st = Database::pdo()->prepare('SELECT email FROM users WHERE id = ?');
    $st->execute([TenantContext::userId()]);
    $__email = (string) $st->fetchColumn();
} catch (\Throwable $e) {}
$__role = StockControl::isOwner() ? 'Owner' : (StockControl::isAdmin() ? 'Administrator' : 'Staff');
$__ini = function (string $s): string {
    $w = preg_split('/\s+|[._-]/', trim($s)) ?: [''];
    return strtoupper(substr($w[0], 0, 1) . substr($w[1] ?? '', 0, 1)) ?: '?';
};
$__members = 0;
try {
    $in = implode(',', array_map('intval', array_keys(StockControl::stores())));
    $__members = (int) Database::pdo()->query("SELECT COUNT(*) FROM users WHERE tenant_id IN ($in) AND is_active = 1")->fetchColumn();
} catch (\Throwable $e) {}
// [file, label, icon, right needed: null | 'admin' | right]
$__nav = [
    'General' => [['', 'Home', 'fa-house', null], ['history.php', 'Stock history', 'fa-clock-rotate-left', null]],
    'Master'  => [['items.php', 'Items', 'fa-cubes', null], ['categories.php', 'Categories', 'fa-tags', 'stock.items'], ['receive.php', 'Receive stock', 'fa-truck-ramp-box', 'stock.receive'],
                  ['transfer.php', 'Stock out', 'fa-right-from-bracket', 'stock.transfer'], ['deliveries.php', 'Deliveries', 'fa-truck-fast', null],
                  ['count.php', 'Stock count', 'fa-clipboard-check', 'stock.count'],
                  ['stores.php', 'Stores', 'fa-warehouse', 'admin']],
    'Finance' => [['finance.php', 'Finance', 'fa-wallet', 'stock.finance']],
    'Support' => [['people.php', 'People & access', 'fa-user-shield', 'admin'], ['settings.php', 'Setting', 'fa-gear', 'admin'], ['password.php', 'My password', 'fa-key', null]],
];
$__show = fn($need) => $need === null || ($need === 'admin' ? StockControl::isAdmin() : StockControl::can($need));
$__lowCount = 0;
try { $__lowCount = count(StockControl::lowStock(array_keys($__stores), 999)); } catch (\Throwable $e) {}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f6f7f9">
<meta name="robots" content="noindex">
<title><?php echo sh($title); ?> · <?php echo sh($__org); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="<?php echo public_url('assets/js/page-loader.js'); ?>?v=<?php echo @filemtime(ROOT_PATH . '/public/assets/js/page-loader.js'); ?>"></script>
<style>
:root{--g:#16a34a;--g2:#22c55e;--gl:#ecfdf3;--bg:#f6f7f9;--side:#fafbfc;--card:#fff;--ink:#111827;--ink2:#374151;--mute:#6b7280;--line:#e9ebef;--bad:#dc2626;--warn:#f59e0b;--r:14px;}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font:14px/1.5 Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);background:#eceef1;}
a{color:inherit}
.app{display:grid;grid-template-columns:268px 1fr;min-height:100vh;background:linear-gradient(to right,var(--side) 268px,var(--bg) 268px);}
/* ---- sidebar ---- */
.side{background:var(--side);border-right:1px solid var(--line);padding:22px 16px 16px;display:flex;flex-direction:column;gap:16px;position:sticky;top:0;height:100vh;overflow-y:auto;}
.brand{display:flex;align-items:center;gap:10px;padding:0 6px;text-decoration:none;}
.brand .mk{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,var(--g2),var(--g));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;overflow:hidden;flex:0 0 auto;}
.brand .mk img{width:100%;height:100%;object-fit:contain;background:#fff;}
.brand b{font-size:1.25rem;font-weight:700;letter-spacing:-.02em;line-height:1.1;}
.ws{border:1px solid var(--line);background:#fff;border-radius:12px;padding:10px 12px;display:flex;align-items:center;gap:10px;}
.ws .ic{width:36px;height:36px;border-radius:9px;background:#f1f3f5;display:flex;align-items:center;justify-content:center;color:var(--ink2);flex:0 0 auto;}
.ws .tx{flex:1;min-width:0;}
.ws .tx b{display:block;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ws .tx small{color:var(--mute);font-size:.75rem;}
.ws select{position:absolute;inset:0;opacity:0;cursor:pointer;}
.ws .chev{width:26px;height:26px;border-radius:7px;border:1px solid var(--line);display:flex;align-items:center;justify-content:center;color:var(--mute);font-size:.7rem;}
.navsec{font-size:.72rem;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.08em;padding:4px 10px;display:flex;justify-content:space-between;}
.nav a{display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:9px;color:var(--ink2);text-decoration:none;font-size:.88rem;margin-bottom:2px;}
.nav a i{width:18px;text-align:center;color:#6b7280;}
.nav a:hover{background:#f1f3f5;}
.nav a.on{background:#eef0f3;color:var(--ink);font-weight:600;}
.nav a.on i{color:var(--ink);}
.nav .badge{margin-left:auto;font-size:.7rem;background:#eef0f3;border:1px solid var(--line);color:var(--ink2);border-radius:6px;padding:0 6px;}
.me{margin-top:auto;border:1px solid var(--line);background:#fff;border-radius:12px;overflow:hidden;}
.me .row1{display:flex;align-items:center;gap:10px;padding:10px 12px;}
.me .av{width:38px;height:38px;border-radius:9px;background:var(--g);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex:0 0 auto;}
.me .tx{min-width:0;} .me .tx b{display:block;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.me .tx small{color:var(--mute);font-size:.75rem;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.me a.out{display:flex;align-items:center;gap:10px;padding:10px 14px;border-top:1px solid var(--line);text-decoration:none;color:var(--ink2);}
.me a.out:hover{background:#fafafa;}
/* ---- main ---- */
.main{background:var(--bg);min-width:0;display:flex;flex-direction:column;}
.top{display:flex;align-items:center;gap:12px;padding:14px 26px;border-bottom:1px solid var(--line);background:var(--bg);position:sticky;top:0;z-index:20;}
.top .tog{width:30px;height:30px;border-radius:50%;border:1px solid var(--line);background:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--ink2);}
.crumb{color:var(--mute);font-size:.86rem;} .crumb b{color:var(--ink);font-weight:500;}
.top .rt{margin-left:auto;display:flex;align-items:center;gap:12px;color:var(--mute);font-size:.8rem;}
.top .chip{width:30px;height:30px;border-radius:50%;background:var(--g);color:#fff;font-size:.7rem;font-weight:700;display:flex;align-items:center;justify-content:center;}
.content{padding:20px 26px 50px;max-width:1240px;width:100%;}
.banner{display:flex;align-items:center;gap:12px;background:linear-gradient(90deg,#dcfce7,#f0fdf4);border:1px solid #bbf7d0;border-radius:12px;padding:12px 16px;margin-bottom:20px;font-weight:500;}
.banner .btn{margin-left:auto;}
.banner .x{background:none;border:0;font-size:1.1rem;cursor:pointer;color:var(--ink2);}
.banner.warn{background:linear-gradient(90deg,#fef3c7,#fffbeb);border-color:#fde68a;}
.sc-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px;}
.sc-head h1{font-size:1.45rem;margin:0;font-weight:600;letter-spacing:-.02em;}
.sc-head p{margin:3px 0 0;color:var(--mute);font-size:.85rem;}
.panel{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:20px;margin-bottom:18px;}
.panel h2{font-size:1.05rem;margin:0 0 14px;font-weight:600;display:flex;align-items:center;gap:7px;}
.panel h2 .i{color:#9ca3af;font-size:.8rem;}
.grid{display:grid;gap:16px;}
.g4{grid-template-columns:repeat(4,1fr);} .g3{grid-template-columns:repeat(3,1fr);} .g2{grid-template-columns:1fr 1fr;}
.kpi{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:18px 20px;}
.kpi .hd{display:flex;align-items:center;justify-content:space-between;color:var(--ink2);font-size:.86rem;font-weight:500;}
.kpi .hd i{color:#9ca3af;}
.kpi b{display:block;font-size:1.65rem;font-weight:700;letter-spacing:-.02em;margin:10px 0 8px;}
.kpi .trend{vertical-align:middle;}
.trend{display:inline-flex;align-items:center;gap:4px;font-size:.75rem;font-weight:600;border-radius:6px;padding:2px 7px;}
.trend.up{background:#dcfce7;color:#15803d;} .trend.dn{background:#fee2e2;color:#b91c1c;} .trend.eq{background:#f1f3f5;color:var(--mute);}
.vs{color:var(--mute);font-size:.75rem;margin-left:6px;vertical-align:middle;}
table.t{width:100%;border-collapse:separate;border-spacing:0;font-size:.86rem;}
.t th{text-align:left;color:var(--mute);font-weight:500;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;padding:10px 12px;background:#fafbfc;border-bottom:1px solid var(--line);white-space:nowrap;}
.t td{padding:11px 12px;border-bottom:1px solid #f0f2f4;vertical-align:middle;}
.t tr:last-child td{border-bottom:0;}
.t .r{text-align:right;} .t .num{font-variant-numeric:tabular-nums;}
.wrap-t{overflow-x:auto;border:1px solid var(--line);border-radius:12px;}
.btn{display:inline-flex;align-items:center;gap:7px;border:1px solid transparent;border-radius:9px;padding:8px 14px;font:500 .86rem Inter,system-ui,sans-serif;cursor:pointer;text-decoration:none;white-space:nowrap;}
.btn-p{background:var(--g);color:#fff;} .btn-p:hover{background:#15803d;}
.btn-d{background:var(--ink);color:#fff;} .btn-d:hover{background:#000;}
.btn-l{background:#fff;color:var(--ink);border-color:var(--line);} .btn-l:hover{background:#f9fafb;}
.btn-sm{padding:5px 10px;font-size:.78rem;}
label.f{display:block;font-size:.78rem;font-weight:500;color:var(--ink2);margin-bottom:5px;}
.in{width:100%;border:1px solid var(--line);border-radius:9px;padding:8px 11px;font:inherit;background:#fff;color:var(--ink);}
.in:focus{outline:none;border-color:var(--g2);box-shadow:0 0 0 3px #22c55e22;}
.row{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:12px;}
.pill{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:7px;font-size:.73rem;font-weight:500;border:1px solid transparent;}
.pill::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor;}
.p-in{background:#f0fdf4;color:#15803d;border-color:#bbf7d0;} .p-out{background:#fef2f2;color:#b91c1c;border-color:#fecaca;}
.p-mv{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;} .p-cnt{background:#fffbeb;color:#b45309;border-color:#fde68a;} .p-mute{background:#f3f4f6;color:#4b5563;border-color:var(--line);}
.msg{padding:11px 14px;border-radius:10px;margin-bottom:16px;font-weight:500;}
.msg.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;} .msg.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.low{color:var(--bad);font-weight:600;} .mute{color:var(--mute);} .small{font-size:.8rem;}
.empty{text-align:center;color:var(--mute);padding:34px 10px;}
.search{position:relative;} .search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;}
.search .in{padding-left:36px;padding-right:36px;min-width:250px;} .search kbd{position:absolute;right:9px;top:50%;transform:translateY(-50%);font:500 .72rem Inter;border:1px solid var(--line);border-radius:5px;padding:0 6px;color:var(--mute);background:#f9fafb;}
.scrim{display:none;}
@media(max-width:1100px){.g4{grid-template-columns:1fr 1fr;}}
@media(max-width:900px){
  .app{grid-template-columns:1fr;background:var(--bg);}
  .side{position:fixed;left:0;top:0;bottom:0;width:280px;z-index:60;transform:translateX(-105%);transition:transform .2s;box-shadow:0 10px 40px rgba(0,0,0,.15);}
  body.nav-open .side{transform:none;}
  body.nav-open .scrim{display:block;position:fixed;inset:0;background:rgba(17,24,39,.35);z-index:50;}
  .g3,.g2{grid-template-columns:1fr;} .content,.top{padding-left:14px;padding-right:14px;}
  .search .in{min-width:0;width:100%;}
}
@media(max-width:560px){.g4{grid-template-columns:1fr;}.top .rt .when{display:none;}}
</style>
</head>
<body>
<?php if (!empty($_SESSION['support_preview']) && !empty($_SESSION['support_auth'])): $__pv = $_SESSION['support_preview']; /* support is previewing this account */ ?>
<div style="position:sticky;top:0;z-index:2000;background:#7c3aed;color:#fff;font:600 13px/1.3 Inter,system-ui,sans-serif;padding:8px 14px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:center">
  <span><i class="fas fa-user-secret"></i> Support preview — signed in as <strong><?php echo sh($__pv['user']); ?></strong> (<?php echo sh($__pv['role']); ?>). Changes you make are real.</span>
  <a href="<?php echo public_url('support/accounts/preview.php?exit=1'); ?>" style="background:#fff;color:#7c3aed;border-radius:999px;padding:3px 12px;text-decoration:none">Exit preview</a>
</div>
<?php endif; ?>
<div class="app">
  <aside class="side" aria-label="Menu">
    <a class="brand" href="<?php echo stock_url(); ?>">
      <span class="mk"><?php if ($__logo): ?><img src="<?php echo sh($__logo); ?>" alt=""><?php else: ?><i class="fas fa-boxes-stacked"></i><?php endif; ?></span>
      <b><?php echo sh($__org); ?></b>
    </a>
    <div class="ws" style="position:relative">
      <span class="ic"><i class="fas fa-warehouse"></i></span>
      <span class="tx"><b><?php echo sh($__stores[$__store]['name'] ?? ''); ?></b><small><?php echo StockControl::kindOf($__stores[$__store] ?? []); ?> · <?php echo count(StockControl::stores()); ?> store<?php echo count(StockControl::stores()) === 1 ? '' : 's'; ?> • <?php echo $__members; ?> member<?php echo $__members === 1 ? '' : 's'; ?></small></span>
      <?php if (count($__stores) > 1): ?>
        <span class="chev"><i class="fas fa-chevron-down"></i></span>
        <form method="get" action="<?php echo sh(stock_url(basename($_SERVER['SCRIPT_NAME']) === 'index.php' ? '' : basename($_SERVER['SCRIPT_NAME']))); ?>">
          <select name="store" onchange="this.form.submit()" aria-label="Switch store">
            <?php foreach ($__stores as $sid => $s): ?><option value="<?php echo $sid; ?>" <?php echo $sid === $__store ? 'selected' : ''; ?>><?php echo sh($s['name']); ?> (<?php echo StockControl::kindOf($s); ?>)</option><?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>
    </div>
    <?php foreach ($__nav as $sec => $links):
      $links = array_filter($links, fn($l) => $__show($l[3])); if (!$links) continue; ?>
      <div>
        <div class="navsec"><?php echo $sec; ?></div>
        <nav class="nav">
          <?php foreach ($links as [$file, $label, $icon]): ?>
            <a class="<?php echo $tab === $file ? 'on' : ''; ?>" href="<?php echo stock_url($file); ?>"><i class="fas <?php echo $icon; ?>"></i><?php echo $label; ?>
              <?php if ($file === 'items.php' && $__lowCount): ?><span class="badge" title="Running low"><?php echo $__lowCount; ?></span><?php endif; ?></a>
          <?php endforeach; ?>
        </nav>
      </div>
    <?php endforeach; ?>
    <div class="me">
      <div class="row1"><span class="av"><?php echo sh($__ini($__who)); ?></span>
        <span class="tx"><b><?php echo sh($__who); ?></b><small><?php echo sh($__email ?: $__role); ?></small></span></div>
      <a class="out" href="<?php echo stock_url('logout.php'); ?>"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a>
    </div>
  </aside>
  <div class="scrim" onclick="document.body.classList.remove('nav-open')"></div>
  <div class="main">
    <header class="top">
      <button class="tog" type="button" aria-label="Menu" onclick="document.body.classList.toggle('nav-open')"><i class="fas fa-bars" style="font-size:.75rem"></i></button>
      <span class="crumb"><?php echo sh($__stores[$__store]['name'] ?? $__org); ?> &nbsp;/&nbsp; <b><?php echo sh($title); ?></b></span>
      <span class="rt"><span class="when"><?php echo date('D j M Y'); ?> · <?php echo $__role; ?></span><span class="chip" title="<?php echo sh($__who); ?>"><?php echo sh($__ini($__who)); ?></span></span>
    </header>
    <div class="content">
      <?php if ($__flash): ?><div class="msg <?php echo $__flash[1] === 'err' ? 'err' : 'ok'; ?>"><?php echo sh($__flash[0]); ?></div><?php endif; ?>
      <?php echo $content; ?>
    </div>
  </div>
</div>
<script>
// "/" jumps to the page's search box
document.addEventListener('keydown', function (e) {
  if (e.key === '/' && !/input|textarea|select/i.test(document.activeElement.tagName)) {
    var s = document.querySelector('.search input, input[name=q], #find'); if (s) { e.preventDefault(); s.focus(); }
  }
});
</script>
</body>
</html>
