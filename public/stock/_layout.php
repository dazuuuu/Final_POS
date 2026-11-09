<?php
// public/stock/_layout.php — the Stock Control app frame.
//   Computers: a sidebar (colour-coded menu, store switcher, your card + Logout)
//              beside a work area with a breadcrumb bar.
//   Phones:    an app — slim top bar (page title, dark-mode button, logout, your
//              avatar), a bottom tab bar with a centre "+" (receive stock) and your
//              Profile on the right; Profile opens a sheet with every page, the
//              store switcher, dark mode and Logout.
//   Dark mode follows the device and can be switched with the sun/moon button.
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
    'Master'  => [['items.php', 'Items', 'fa-cubes', null], ['categories.php', 'Categories', 'fa-tags', 'stock.items'],
                  ['receive.php', 'Receive stock', 'fa-truck-ramp-box', 'stock.receive'],
                  ['transfer.php', 'Stock out', 'fa-right-from-bracket', 'stock.transfer'], ['deliveries.php', 'Deliveries', 'fa-truck-fast', null],
                  ['count.php', 'Stock count', 'fa-clipboard-check', 'stock.count'], ['stores.php', 'Stores', 'fa-warehouse', 'admin']],
    'Finance' => [['finance.php', 'Finance', 'fa-wallet', 'stock.finance']],
    'Support' => [['people.php', 'People & access', 'fa-user-shield', 'admin'], ['settings.php', 'Setting', 'fa-gear', 'admin'], ['password.php', 'My password', 'fa-key', null]],
];
$__color = ['' => '#22c55e', 'history.php' => '#a855f7', 'items.php' => '#3b82f6', 'categories.php' => '#ec4899', 'receive.php' => '#10b981',
            'transfer.php' => '#f97316', 'deliveries.php' => '#06b6d4', 'count.php' => '#f59e0b', 'stores.php' => '#6366f1',
            'finance.php' => '#14b8a6', 'people.php' => '#8b5cf6', 'settings.php' => '#64748b', 'password.php' => '#94a3b8'];
$__show = fn($need) => $need === null || ($need === 'admin' ? StockControl::isAdmin() : StockControl::can($need));
$__lowCount = 0;
try { $__lowCount = count(StockControl::lowStock(array_keys($__stores), 999)); } catch (\Throwable $e) {}
$__flat = [];
foreach ($__nav as $links) foreach ($links as $l) if ($__show($l[3])) $__flat[$l[0]] = $l;
$__self = basename($_SERVER['SCRIPT_NAME']);
$__selfUrl = stock_url($__self === 'index.php' ? '' : $__self);
$__fab = StockControl::can('stock.receive') ? ['receive.php', 'Receive'] : (StockControl::can('stock.items') ? ['items.php?add=1', 'New item'] : ['history.php', 'History']);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f6f7f9" id="themeColor">
<meta name="robots" content="noindex">
<title><?php echo sh($title); ?> · <?php echo sh($__org); ?></title>
<script>
// Theme before first paint: the saved choice, else the device setting.
(function () {
  try {
    var t = localStorage.getItem('sc-theme');
    if (t !== 'light' && t !== 'dark') t = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', t);
    var m = document.getElementById('themeColor'); if (m) m.content = t === 'dark' ? '#0a1020' : '#f6f7f9';
  } catch (e) {}
})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="<?php echo public_url('assets/js/page-loader.js'); ?>?v=<?php echo @filemtime(ROOT_PATH . '/public/assets/js/page-loader.js'); ?>"></script>
<style>
:root{--dz-accent:#22c55e;--g:#16a34a;--g2:#22c55e;--gl:#ecfdf3;--bg:#f6f7f9;--side:#fafbfc;--card:#fff;--inbg:#fff;--thbg:#fafbfc;--ink:#111827;--ink2:#374151;--mute:#6b7280;--line:#e9ebef;--rowline:#f0f2f4;--hov:#f1f3f5;--bad:#dc2626;--warn:#f59e0b;--r:14px;
  --h0:#f3f4f6;--h1:#dcfce7;--h2:#a7f3c0;--h3:#4ade80;--h4:#16a34a;--shadow:0 1px 2px rgba(16,24,40,.04),0 8px 24px -12px rgba(16,24,40,.10);--page:#eceef1;color-scheme:light;}
html[data-theme=dark]{--g:#22c55e;--g2:#4ade80;--gl:#0f2a1c;--bg:#0a1020;--side:#0e1527;--card:#121b30;--inbg:#0c1424;--thbg:#0f1830;--ink:#e8eefb;--ink2:#b9c4da;--mute:#8d9bb5;--line:#1f2a44;--rowline:#192440;--hov:#17233d;--bad:#f87171;--warn:#fbbf24;
  --h0:#17233d;--h1:#14532d;--h2:#15803d;--h3:#22c55e;--h4:#86efac;--shadow:0 1px 2px rgba(0,0,0,.3),0 10px 30px -14px rgba(0,0,0,.6);--page:#070c18;color-scheme:dark;}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font:14px/1.5 Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);background:var(--page);-webkit-tap-highlight-color:transparent;}
a{color:inherit}
.app{display:grid;grid-template-columns:268px 1fr;min-height:100vh;background:linear-gradient(to right,var(--side) 268px,var(--bg) 268px);}
/* ---- sidebar ---- */
.side{background:var(--side);border-right:1px solid var(--line);padding:22px 16px 16px;display:flex;flex-direction:column;gap:16px;position:sticky;top:0;height:100vh;overflow-y:auto;}
.brand{display:flex;align-items:center;gap:10px;padding:0 6px;text-decoration:none;}
.brand .mk{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#22c55e,#0ea5e9);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;overflow:hidden;flex:0 0 auto;box-shadow:0 6px 16px -6px #22c55e88;}
.brand .mk img{width:100%;height:100%;object-fit:contain;background:#fff;}
.brand b{font-size:1.2rem;font-weight:700;letter-spacing:-.02em;line-height:1.1;}
.ws{border:1px solid var(--line);background:var(--card);border-radius:12px;padding:10px 12px;display:flex;align-items:center;gap:10px;position:relative;box-shadow:var(--shadow);}
.ws .ic{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;flex:0 0 auto;}
.ws .tx{flex:1;min-width:0;}
.ws .tx b{display:block;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ws .tx small{color:var(--mute);font-size:.75rem;}
.ws select{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;}
.ws .chev{width:26px;height:26px;border-radius:7px;border:1px solid var(--line);display:flex;align-items:center;justify-content:center;color:var(--mute);font-size:.7rem;}
.navsec{font-size:.7rem;font-weight:600;color:var(--mute);text-transform:uppercase;letter-spacing:.09em;padding:4px 10px;}
.nav a{display:flex;align-items:center;gap:11px;padding:7px 10px;border-radius:11px;color:var(--ink2);text-decoration:none;font-size:.88rem;margin-bottom:2px;}
.nav a i.ci{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;font-size:.82rem;background:color-mix(in srgb,var(--c) 15%,transparent);color:var(--c);flex:0 0 auto;transition:transform .15s;}
.nav a:hover{background:var(--hov);} .nav a:hover i.ci{transform:scale(1.08);}
.nav a.on{background:color-mix(in srgb,var(--c) 13%,var(--card));color:var(--ink);font-weight:600;box-shadow:inset 3px 0 0 var(--c);}
.nav a.on i.ci{background:var(--c);color:#fff;}
.nav .badge{margin-left:auto;font-size:.7rem;background:#fee2e2;border:1px solid #fecaca;color:#b91c1c;border-radius:999px;padding:0 7px;font-weight:600;}
.me{margin-top:auto;border:1px solid var(--line);background:var(--card);border-radius:12px;overflow:hidden;box-shadow:var(--shadow);}
.me .row1{display:flex;align-items:center;gap:10px;padding:10px 12px;}
.av{width:38px;height:38px;border-radius:11px;background:linear-gradient(135deg,#22c55e,#14b8a6);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex:0 0 auto;}
.me .tx{min-width:0;} .me .tx b{display:block;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.me .tx small{color:var(--mute);font-size:.75rem;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.me a.out{display:flex;align-items:center;gap:10px;padding:10px 14px;border-top:1px solid var(--line);text-decoration:none;color:var(--bad);font-weight:500;}
.me a.out:hover{background:var(--hov);}
/* ---- main ---- */
.main{background:var(--bg);min-width:0;display:flex;flex-direction:column;}
.top{display:flex;align-items:center;gap:12px;padding:12px 26px;border-bottom:1px solid var(--line);background:color-mix(in srgb,var(--bg) 88%,transparent);backdrop-filter:blur(10px);position:sticky;top:0;z-index:20;}
.crumb{color:var(--mute);font-size:.86rem;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;} .crumb b{color:var(--ink);font-weight:600;}
.top .rt{margin-left:auto;display:flex;align-items:center;gap:10px;color:var(--mute);font-size:.8rem;}
.ibtn{width:36px;height:36px;border-radius:11px;border:1px solid var(--line);background:var(--card);color:var(--ink2);display:grid;place-items:center;cursor:pointer;text-decoration:none;font-size:.95rem;padding:0;}
.ibtn:hover{background:var(--hov);}
.ibtn.out{color:var(--bad);}
.chip{border:0;cursor:pointer;padding:0;width:36px;height:36px;border-radius:11px;font-size:.78rem;}
.tbrand{display:none;width:32px;height:32px;border-radius:10px;background:linear-gradient(135deg,#22c55e,#0ea5e9);color:#fff;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;overflow:hidden;flex:0 0 auto;}
.tbrand img{width:100%;height:100%;object-fit:contain;background:#fff;}
.content{padding:20px 26px 50px;max-width:1240px;width:100%;}
.banner{display:flex;align-items:center;gap:12px;background:linear-gradient(100deg,#bbf7d0,#a5f3fc 70%,#e0f2fe);border:0;border-radius:14px;padding:13px 16px;margin-bottom:20px;font-weight:500;color:#064e3b;box-shadow:var(--shadow);}
.banner .btn{margin-left:auto;} .banner .x{background:none;border:0;font-size:1.2rem;cursor:pointer;color:#064e3b;margin-left:auto;}
.banner.warn{background:linear-gradient(100deg,#fde68a,#fed7aa 70%,#fecaca);color:#78350f;}
.sc-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px;}
.sc-head h1{font-size:1.5rem;margin:0;font-weight:700;letter-spacing:-.02em;}
.sc-head p{margin:3px 0 0;color:var(--mute);font-size:.86rem;}
.panel{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:20px;margin-bottom:18px;box-shadow:var(--shadow);}
.panel h2{font-size:1.05rem;margin:0 0 14px;font-weight:600;display:flex;align-items:center;gap:7px;}
.panel h2 .i{color:var(--mute);font-size:.8rem;}
.grid{display:grid;gap:16px;}
.g4{grid-template-columns:repeat(4,1fr);} .g3{grid-template-columns:repeat(3,1fr);} .g2{grid-template-columns:1fr 1fr;}
.kpi{--c:#16a34a;position:relative;overflow:hidden;background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:18px 20px;box-shadow:var(--shadow);}
.kpi::after{content:"";position:absolute;right:-34px;top:-34px;width:120px;height:120px;border-radius:50%;background:color-mix(in srgb,var(--c) 11%,transparent);pointer-events:none;}
.kpi::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--c);}
.kpi .hd{display:flex;align-items:center;justify-content:space-between;color:var(--ink2);font-size:.86rem;font-weight:500;position:relative;z-index:1;}
.kpi .hd i.ic{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;background:color-mix(in srgb,var(--c) 17%,transparent);color:var(--c);font-size:1rem;}
.kpi b{display:block;font-size:1.65rem;font-weight:700;letter-spacing:-.02em;margin:8px 0 8px;position:relative;z-index:1;}
.kpi .trend{vertical-align:middle;position:relative;z-index:1;}
.trend{display:inline-flex;align-items:center;gap:4px;font-size:.75rem;font-weight:600;border-radius:6px;padding:2px 7px;}
.trend.up{background:#dcfce7;color:#15803d;} .trend.dn{background:#fee2e2;color:#b91c1c;} .trend.eq{background:var(--hov);color:var(--mute);}
.vs{color:var(--mute);font-size:.75rem;margin-left:6px;vertical-align:middle;position:relative;z-index:1;}
table.t{width:100%;border-collapse:separate;border-spacing:0;font-size:.86rem;}
.t th{text-align:left;color:var(--mute);font-weight:500;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;padding:10px 12px;background:var(--thbg);border-bottom:1px solid var(--line);white-space:nowrap;}
.t td{padding:11px 12px;border-bottom:1px solid var(--rowline);vertical-align:middle;}
.t tr:last-child td{border-bottom:0;} .t tbody tr:hover td{background:var(--hov);}
.t .r{text-align:right;} .t .num{font-variant-numeric:tabular-nums;}
.wrap-t{overflow-x:auto;border:1px solid var(--line);border-radius:12px;}
.btn{display:inline-flex;align-items:center;gap:7px;border:1px solid transparent;border-radius:10px;padding:8px 14px;font:500 .86rem Inter,system-ui,sans-serif;cursor:pointer;text-decoration:none;white-space:nowrap;transition:filter .15s,transform .05s;}
.btn:active{transform:scale(.98);}
.btn-p{background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;box-shadow:0 6px 14px -8px #16a34a;} .btn-p:hover{filter:brightness(1.07);}
.btn-d{background:linear-gradient(135deg,#334155,#0f172a);color:#fff;} html[data-theme=dark] .btn-d{background:linear-gradient(135deg,#3b82f6,#6366f1);} .btn-d:hover{filter:brightness(1.1);}
.btn-l{background:var(--card);color:var(--ink);border-color:var(--line);} .btn-l:hover{background:var(--hov);}
.btn-sm{padding:5px 10px;font-size:.78rem;}
label.f{display:block;font-size:.78rem;font-weight:500;color:var(--ink2);margin-bottom:5px;}
.in{width:100%;border:1px solid var(--line);border-radius:10px;padding:9px 11px;font:inherit;background:var(--inbg);color:var(--ink);}
.in::placeholder{color:var(--mute);opacity:.8;}
.in:focus{outline:none;border-color:var(--g2);box-shadow:0 0 0 3px #22c55e26;}
.row{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:12px;}
.pill{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:999px;font-size:.73rem;font-weight:500;border:1px solid transparent;}
.pill::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor;}
.p-in{background:#f0fdf4;color:#15803d;border-color:#bbf7d0;} .p-out{background:#fef2f2;color:#b91c1c;border-color:#fecaca;}
.p-mv{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;} .p-cnt{background:#fffbeb;color:#b45309;border-color:#fde68a;} .p-mute{background:#f3f4f6;color:#4b5563;border-color:var(--line);}
html[data-theme=dark] .p-in{background:#0f2a1c;color:#86efac;border-color:#14532d;} html[data-theme=dark] .p-out{background:#2a1214;color:#fca5a5;border-color:#7f1d1d;}
html[data-theme=dark] .p-mv{background:#10203f;color:#93c5fd;border-color:#1e3a8a;} html[data-theme=dark] .p-cnt{background:#2a200c;color:#fcd34d;border-color:#78350f;} html[data-theme=dark] .p-mute{background:#17233d;color:#b9c4da;}
html[data-theme=dark] .trend.up{background:#0f2a1c;color:#86efac;} html[data-theme=dark] .trend.dn{background:#2a1214;color:#fca5a5;}
html[data-theme=dark] .nav .badge{background:#2a1214;border-color:#7f1d1d;color:#fca5a5;}
.msg{padding:11px 14px;border-radius:12px;margin-bottom:16px;font-weight:500;}
.msg.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;} .msg.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
html[data-theme=dark] .msg.ok{background:#0f2a1c;color:#86efac;border-color:#14532d;} html[data-theme=dark] .msg.err{background:#2a1214;color:#fca5a5;border-color:#7f1d1d;}
.low{color:var(--bad);font-weight:600;} .mute{color:var(--mute);} .small{font-size:.8rem;}
.empty{text-align:center;color:var(--mute);padding:34px 10px;}
.search{position:relative;} .search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--mute);}
.search .in{padding-left:36px;padding-right:36px;min-width:250px;} .search kbd{position:absolute;right:9px;top:50%;transform:translateY(-50%);font:500 .72rem Inter;border:1px solid var(--line);border-radius:5px;padding:0 6px;color:var(--mute);background:var(--hov);}
.seg{display:flex;background:var(--hov);border:1px solid var(--line);border-radius:11px;padding:3px;flex-wrap:wrap;}
.seg a{padding:7px 14px;border-radius:9px;text-decoration:none;color:var(--mute);font-weight:500;font-size:.85rem;display:flex;gap:6px;align-items:center;}
.seg a.on{background:var(--card);color:var(--ink);box-shadow:0 1px 3px rgba(0,0,0,.12);}
/* ---- phone bottom bar + sheet (hidden on computers) ---- */
.bnav,.sheet,.sheet-bg{display:none;}
@media(max-width:1100px){.g4{grid-template-columns:1fr 1fr;}}
@media(max-width:900px){
  .app{grid-template-columns:1fr;background:var(--bg);}
  .side{display:none;}
  .top{padding:10px 14px;padding-top:max(10px,env(safe-area-inset-top));gap:10px;}
  .tbrand{display:flex;}
  .crumb .org{display:none;} .crumb b{font-size:1.05rem;}
  .top .rt .when{display:none;}
  .content{padding:14px 14px calc(96px + env(safe-area-inset-bottom));}
  .g3,.g2{grid-template-columns:1fr;} .g4{grid-template-columns:1fr 1fr;}
  .sc-head h1{font-size:1.3rem;} .panel{padding:14px;border-radius:16px;}
  .search .in{min-width:0;width:100%;}
  .kpi{padding:14px;} .kpi b{font-size:1.25rem;} .kpi .hd i.ic{width:32px;height:32px;font-size:.85rem;}
  .btn{padding:9px 14px;} .in{font-size:16px;padding:11px 12px;}
  .bnav{display:grid;grid-template-columns:1fr 1fr 70px 1fr 1fr;align-items:end;position:fixed;left:0;right:0;bottom:0;z-index:70;background:color-mix(in srgb,var(--card) 92%,transparent);backdrop-filter:blur(14px);border-top:1px solid var(--line);padding:6px 6px calc(6px + env(safe-area-inset-bottom));}
  .bnav a,.bnav button{display:flex;flex-direction:column;align-items:center;gap:3px;border:0;background:none;color:var(--mute);font:500 .68rem Inter,system-ui,sans-serif;text-decoration:none;padding:6px 0;cursor:pointer;}
  .bnav a i,.bnav button i{font-size:1.15rem;}
  .bnav a.on{color:var(--g);} .bnav a.on i{filter:drop-shadow(0 4px 8px #22c55e66);}
  .bnav a.fab{margin-top:-24px;}
  .bnav a.fab i{width:54px;height:54px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(135deg,#22c55e,#0ea5e9);color:#fff;font-size:1.3rem;box-shadow:0 10px 22px -6px #16a34aaa;border:4px solid var(--bg);}
  .bnav .me-tab .av{width:28px;height:28px;border-radius:50%;font-size:.66rem;}
  .bnav .me-tab{color:var(--ink2);}
  .sheet-bg{display:block;position:fixed;inset:0;z-index:80;background:rgba(2,6,23,.55);opacity:0;pointer-events:none;transition:opacity .2s;}
  .sheet{display:block;position:fixed;left:0;right:0;bottom:0;z-index:90;background:var(--card);border-radius:22px 22px 0 0;padding:10px 16px calc(18px + env(safe-area-inset-bottom));max-height:88vh;overflow-y:auto;transform:translateY(105%);transition:transform .25s ease;box-shadow:0 -20px 50px rgba(0,0,0,.3);}
  body.sheet-open .sheet{transform:none;} body.sheet-open .sheet-bg{opacity:1;pointer-events:auto;}
  .sheet .grip{width:42px;height:5px;border-radius:5px;background:var(--line);margin:4px auto 14px;}
  .sheet .who{display:flex;align-items:center;gap:12px;margin-bottom:14px;}
  .sheet .who .tx{min-width:0;} .sheet .who b{display:block;} .sheet .who small{color:var(--mute);display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .sheet .st{margin-bottom:14px;}
  .sheet .gridl{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px;}
  .sheet .gridl a{display:flex;flex-direction:column;align-items:center;gap:7px;padding:12px 6px;border-radius:14px;background:var(--hov);text-decoration:none;font-size:.74rem;font-weight:500;text-align:center;color:var(--ink2);}
  .sheet .gridl a i{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;background:color-mix(in srgb,var(--c) 16%,transparent);color:var(--c);font-size:1rem;}
  .sheet .gridl a.on{outline:2px solid var(--c);}
  .sheet .acts{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
  .sheet .acts .btn{justify-content:center;padding:12px;}
  .sheet .logout{background:#fee2e2;color:#b91c1c;border-color:#fecaca;font-weight:600;} html[data-theme=dark] .sheet .logout{background:#2a1214;color:#fca5a5;border-color:#7f1d1d;}
  /* tables become stacked cards */
  .wrap-t{border:0;overflow:visible;border-radius:0;}
  table.t,.t tbody,.t tr,.t td{display:block;width:100%;} .t thead{display:none;}
  .t tr{background:var(--card);border:1px solid var(--line);border-radius:14px;margin:0 0 10px;padding:6px 14px;box-shadow:var(--shadow);}
  .t tr[hidden]{display:none;} .t tbody tr:hover td{background:none;}
  .t td{display:block;overflow:hidden;border:0;padding:7px 0;text-align:right!important;}
  .t td::before{content:attr(data-label);float:left;color:var(--mute);font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;padding-top:2px;text-align:left;max-width:42%;}
  .t td:not([data-label])::before{display:none;}
  .t td[colspan]{text-align:left!important;} .t td[colspan]::before{display:none;}
  .t td .in{max-width:150px;}
}
@media(max-width:380px){.g4{grid-template-columns:1fr;}}
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
    <div class="ws">
      <span class="ic"><i class="fas fa-warehouse"></i></span>
      <span class="tx"><b><?php echo sh($__stores[$__store]['name'] ?? ''); ?></b><small><?php echo StockControl::kindOf($__stores[$__store] ?? []); ?> · <?php echo count(StockControl::stores()); ?> store<?php echo count(StockControl::stores()) === 1 ? '' : 's'; ?> • <?php echo $__members; ?> member<?php echo $__members === 1 ? '' : 's'; ?></small></span>
      <?php if (count($__stores) > 1): ?>
        <span class="chev"><i class="fas fa-chevron-down"></i></span>
        <form method="get" action="<?php echo sh($__selfUrl); ?>">
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
            <a class="<?php echo $tab === $file ? 'on' : ''; ?>" style="--c:<?php echo $__color[$file] ?? '#64748b'; ?>" href="<?php echo stock_url($file); ?>"><i class="ci fas <?php echo $icon; ?>"></i><?php echo $label; ?>
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
  <div class="main">
    <header class="top">
      <span class="tbrand"><?php if ($__logo): ?><img src="<?php echo sh($__logo); ?>" alt=""><?php else: echo sh($__ini($__org)); endif; ?></span>
      <span class="crumb"><span class="org"><?php echo sh($__stores[$__store]['name'] ?? $__org); ?> &nbsp;/&nbsp; </span><b><?php echo sh($title); ?></b></span>
      <span class="rt"><span class="when"><?php echo date('D j M Y'); ?> · <?php echo $__role; ?></span>
        <button class="ibtn" id="themeBtn" type="button" aria-label="Switch dark / light mode" title="Dark / light mode"><i class="fas fa-moon"></i></button>
        <a class="ibtn out" href="<?php echo stock_url('logout.php'); ?>" aria-label="Logout" title="Logout"><i class="fas fa-arrow-right-from-bracket"></i></a>
        <button class="chip av" id="chipBtn" type="button" aria-label="Profile" title="<?php echo sh($__who); ?>"><?php echo sh($__ini($__who)); ?></button></span>
    </header>
    <div class="content">
      <?php if ($__flash): ?><div class="msg <?php echo $__flash[1] === 'err' ? 'err' : 'ok'; ?>"><?php echo sh($__flash[0]); ?></div><?php endif; ?>
      <?php echo $content; ?>
    </div>
  </div>
</div>

<!-- phone: bottom tab bar (Profile on the right) + profile / menu sheet -->
<nav class="bnav" aria-label="Main">
  <a href="<?php echo stock_url(); ?>" class="<?php echo $tab === '' ? 'on' : ''; ?>"><i class="fas fa-house"></i><span>Home</span></a>
  <a href="<?php echo stock_url('items.php'); ?>" class="<?php echo $tab === 'items.php' ? 'on' : ''; ?>"><i class="fas fa-cubes"></i><span>Items</span></a>
  <a href="<?php echo stock_url($__fab[0]); ?>" class="fab <?php echo $tab === $__fab[0] ? 'on' : ''; ?>" aria-label="<?php echo sh($__fab[1]); ?>"><i class="fas fa-plus"></i><span><?php echo sh($__fab[1]); ?></span></a>
  <?php if (isset($__flat['transfer.php'])): ?><a href="<?php echo stock_url('transfer.php'); ?>" class="<?php echo $tab === 'transfer.php' ? 'on' : ''; ?>"><i class="fas fa-right-from-bracket"></i><span>Stock out</span></a>
  <?php else: ?><a href="<?php echo stock_url('deliveries.php'); ?>" class="<?php echo $tab === 'deliveries.php' ? 'on' : ''; ?>"><i class="fas fa-truck-fast"></i><span>Deliveries</span></a><?php endif; ?>
  <button type="button" class="me-tab" id="moreBtn" aria-label="Profile and menu"><span class="av"><?php echo sh($__ini($__who)); ?></span><span>Profile</span></button>
</nav>
<div class="sheet-bg" id="sheetBg"></div>
<div class="sheet" id="sheet" role="dialog" aria-label="Profile and menu">
  <div class="grip"></div>
  <div class="who"><span class="av"><?php echo sh($__ini($__who)); ?></span><span class="tx"><b><?php echo sh($__who); ?></b><small><?php echo sh($__role . ' · ' . ($__email ?: $__org)); ?></small></span></div>
  <?php if (count($__stores) > 1): ?>
  <form method="get" action="<?php echo sh($__selfUrl); ?>" class="st"><label class="f"><i class="fas fa-warehouse"></i> Store</label>
    <select class="in" name="store" onchange="this.form.submit()"><?php foreach ($__stores as $sid => $s): ?><option value="<?php echo $sid; ?>" <?php echo $sid === $__store ? 'selected' : ''; ?>><?php echo sh($s['name']); ?> (<?php echo StockControl::kindOf($s); ?>)</option><?php endforeach; ?></select></form>
  <?php else: ?><div class="st small mute"><i class="fas fa-warehouse"></i> <?php echo sh($__stores[$__store]['name'] ?? ''); ?> · <?php echo StockControl::kindOf($__stores[$__store] ?? []); ?></div><?php endif; ?>
  <div class="gridl">
    <?php foreach ($__flat as $file => $l): ?>
      <a href="<?php echo stock_url($file); ?>" class="<?php echo $tab === $file ? 'on' : ''; ?>" style="--c:<?php echo $__color[$file] ?? '#64748b'; ?>"><i class="fas <?php echo $l[2]; ?>"></i><?php echo sh($l[1]); ?></a>
    <?php endforeach; ?>
  </div>
  <div class="acts">
    <button class="btn btn-l" type="button" id="themeBtn2"><i class="fas fa-moon"></i> <span>Dark mode</span></button>
    <a class="btn logout" href="<?php echo stock_url('logout.php'); ?>"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a>
  </div>
</div>
<script>
(function () {
  var root = document.documentElement, body = document.body;
  // Dark / light switch (remembered on this device)
  function paint() {
    var dark = root.getAttribute('data-theme') === 'dark';
    var t1 = document.querySelector('#themeBtn i'); if (t1) t1.className = 'fas ' + (dark ? 'fa-sun' : 'fa-moon');
    var b2 = document.getElementById('themeBtn2'); if (b2) { b2.querySelector('i').className = 'fas ' + (dark ? 'fa-sun' : 'fa-moon'); b2.querySelector('span').textContent = dark ? 'Light mode' : 'Dark mode'; }
    var m = document.getElementById('themeColor'); if (m) m.content = dark ? '#0a1020' : '#f6f7f9';
  }
  function flip() {
    var t = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', t); try { localStorage.setItem('sc-theme', t); } catch (e) {}
    paint();
  }
  ['themeBtn', 'themeBtn2'].forEach(function (id) { var b = document.getElementById(id); if (b) b.addEventListener('click', flip); });
  paint();
  // Profile / menu sheet: the Profile tab and the avatar in the top bar
  function openSheet() { body.classList.add('sheet-open'); }
  ['moreBtn', 'chipBtn'].forEach(function (id) { var b = document.getElementById(id); if (b) b.addEventListener('click', openSheet); });
  document.getElementById('sheetBg').addEventListener('click', function () { body.classList.remove('sheet-open'); });
  // Tables become cards on phones: every cell remembers its column heading
  document.querySelectorAll('table.t').forEach(function (t) {
    var heads = Array.prototype.map.call(t.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
    t.querySelectorAll('tbody tr').forEach(function (tr) {
      Array.prototype.forEach.call(tr.children, function (td, i) { if (!td.hasAttribute('colspan') && heads[i]) td.setAttribute('data-label', heads[i]); });
    });
  });
  // "/" jumps to the page's search box
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && !/input|textarea|select/i.test(document.activeElement.tagName)) {
      var s = document.querySelector('.search input, input[name=q], #find'); if (s) { e.preventDefault(); s.focus(); }
    }
  });
})();
</script>
</body>
</html>
