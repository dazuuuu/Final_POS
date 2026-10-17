<?php
// public/support/_layout.php — Support console shell.
//   Phones / tablets (< 992px): a mobile app — sticky app bar, bottom tab bar
//   with a centre "+" action, toast messages.
//   Desktop (≥ 992px): a web system — fixed side navigation, page header,
//   full-width content; the tab bar is hidden.
// Expects $page_title, $content, $nav (and optional $wide).
$__flash = support_flash();
$__tabs = [
    'overview' => ['Home', 'fa-house', 'support/'],
    'accounts' => ['Accounts', 'fa-table-cells', 'support/accounts/'],
    'new'      => null, // centre action button
    'packages' => ['Packages', 'fa-box-open', 'support/packages/'],
    'tickets'  => ['Tickets', 'fa-ticket', 'support/tickets/'],
];
$__newTickets = 0;
try {
    $__newTickets = (int) Database::pdo()->query('SELECT COUNT(*) FROM support_tickets WHERE unread_by_support = 1')->fetchColumn();
} catch (\Throwable $e) { /* tickets table not installed yet */ }
$__initial = strtoupper(substr(SupportAccount::email() ?? 'S', 0, 1));
$__brand = Branding::platform(); // Support → Settings → Platform brand: also used on the shared login pages
if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/support/settings/') !== false) $nav = 'settings';
// Desktop side navigation: [key, label, icon, url]
$__side = [
    ['overview', 'Overview', 'fa-gauge-high', 'support/'],
    ['accounts', 'Accounts', 'fa-table-cells', 'support/accounts/'],
    ['packages', 'Packages', 'fa-box-open', 'support/packages/'],
    ['tickets', 'Support tickets', 'fa-ticket', 'support/tickets/'],
    ['settings', 'Settings', 'fa-gear', 'support/settings/'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0c1711">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars(Branding::platform()['name']); ?> Support">
<title><?php echo htmlspecialchars($page_title); ?> · Support</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --s-accent:#22a447;--s-accent-2:#15803d;--s-dark:#0c1711;--s-bg:#eef3ef;--s-ink:#0f1f16;--s-mute:#6b7c72;
  --s-line:#e1e9e3;--s-green-soft:#dcefe0;--s-card:#fff;--tab-h:68px;--safe-b:env(safe-area-inset-bottom,0px);--safe-t:env(safe-area-inset-top,0px);
}
*{-webkit-tap-highlight-color:transparent;}
html,body{background:var(--s-bg);}
body{font-family:'Inter',system-ui,sans-serif;color:var(--s-ink);font-size:.92rem;
  padding-bottom:calc(var(--tab-h) + var(--safe-b) + 24px);overscroll-behavior-y:none;}

/* ---------- app bar ---------- */
.app-bar{position:sticky;top:0;z-index:1020;padding:calc(10px + var(--safe-t)) 16px 10px;
  background:rgba(242,244,248,.82);backdrop-filter:saturate(180%) blur(16px);-webkit-backdrop-filter:saturate(180%) blur(16px);
  border-bottom:1px solid transparent;transition:border-color .2s,background .2s;}
.app-bar.scrolled{border-bottom-color:var(--s-line);background:rgba(255,255,255,.88);}
.app-bar-in{max-width:1280px;margin:0 auto;display:flex;align-items:center;gap:12px;}
.app-logo{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;color:#fff;font-size:1rem;overflow:hidden;
  background:linear-gradient(135deg,var(--s-accent),var(--s-accent-2));box-shadow:0 6px 16px rgba(34,164,71,.3);}
.app-logo.has-img{background:#fff;box-shadow:none;}
.app-logo img{width:100%;height:100%;object-fit:contain;}
.btn-primary{background:var(--s-dark);border-color:var(--s-dark);color:#fff;}
.btn-primary:hover,.btn-primary:focus,.btn-primary:active{background:#1b2d22 !important;border-color:#1b2d22 !important;color:#fff;}
.btn-outline-primary{color:var(--s-accent-2);border-color:var(--s-accent);}
.btn-outline-primary:hover{background:var(--s-accent);border-color:var(--s-accent);color:#fff;}
.form-check-input:checked{background-color:var(--s-accent);border-color:var(--s-accent);}
.bg-primary{background-color:var(--s-accent) !important;}
.text-primary{color:var(--s-accent) !important;}
a{color:var(--s-accent-2);}
.pkg-on{background:var(--s-green-soft) !important;color:#14532d !important;}
.app-title{flex:1;min-width:0;}
.app-title small{display:block;color:var(--s-mute);font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;}
.app-title h1{font-size:1.25rem;font-weight:800;margin:0;letter-spacing:-.02em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.app-avatar{padding:0;cursor:pointer;width:38px;height:38px;border-radius:50%;background:#fff;border:1px solid var(--s-line);display:grid;place-items:center;
  font-weight:700;color:var(--s-accent);}

/* ---------- content ---------- */
.s-main{max-width:1280px;margin:0 auto;padding:14px 16px;animation:pageIn .28s ease-out;}
@keyframes pageIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.s-card{background:var(--s-card);border:1px solid var(--s-line);border-radius:18px;padding:18px;box-shadow:0 1px 2px rgba(15,23,42,.04);}
.s-stat{position:relative;overflow:hidden;}
.s-stat .n{font-size:1.55rem;font-weight:800;letter-spacing:-.02em;}
.s-stat .l{color:var(--s-mute);font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;font-weight:600;}
.pkg-on{background:#eef2ff;color:#3730a3;font-weight:600;}
.table td,.table th{vertical-align:middle;}
.btn{border-radius:12px;font-weight:600;}
.btn:active{transform:scale(.97);}
.form-control,.form-select{border-radius:12px;}
h1.h4{font-weight:800;letter-spacing:-.02em;}

/* ---------- bottom tab bar ---------- */
.tab-bar{position:fixed;left:0;right:0;bottom:0;z-index:1030;padding:0 12px calc(10px + var(--safe-b));pointer-events:none;}
.tab-bar-in{pointer-events:auto;max-width:520px;margin:0 auto;height:var(--tab-h);display:flex;align-items:center;justify-content:space-around;
  background:rgba(15,23,42,.94);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);
  border-radius:24px;box-shadow:0 12px 32px rgba(15,23,42,.28);padding:0 6px;}
.tab{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;color:#94a3b8;text-decoration:none;
  font-size:.66rem;font-weight:600;padding:8px 0;border-radius:16px;transition:color .15s;}
.tab i{font-size:1.15rem;transition:transform .2s;}
.tab.on{color:#fff;}
.tab.on i{transform:translateY(-2px);}
.tab.on::after{content:"";width:5px;height:5px;border-radius:50%;background:#4ade80;margin-top:-1px;}
.tab:active i{transform:scale(.85);}
.tab{position:relative;}
.tab-badge{position:absolute;top:6px;left:calc(50% + 6px);font-style:normal;background:#ef4444;color:#fff;font-size:.6rem;font-weight:800;
  min-width:18px;height:18px;border-radius:9px;display:grid;place-items:center;padding:0 5px;border:2px solid #0f172a;}
.tab-fab{width:56px;height:56px;margin-top:-30px;border-radius:18px;display:grid;place-items:center;color:#fff;font-size:1.3rem;
  background:linear-gradient(135deg,var(--s-accent),var(--s-accent-2));box-shadow:0 10px 24px rgba(79,70,229,.45);
  border:4px solid var(--s-bg);text-decoration:none;transition:transform .15s;box-shadow:0 10px 24px rgba(34,164,71,.4);}
.tab-fab:active{transform:scale(.9);}

/* ---------- toast ---------- */
.s-toast{position:fixed;left:50%;top:calc(14px + var(--safe-t));transform:translate(-50%,-140%);z-index:1100;max-width:min(92vw,520px);
  padding:12px 16px;border-radius:16px;color:#fff;font-weight:600;font-size:.86rem;box-shadow:0 12px 30px rgba(0,0,0,.2);
  display:flex;gap:10px;align-items:flex-start;transition:transform .35s cubic-bezier(.2,.9,.3,1.2);}
.s-toast.show{transform:translate(-50%,0);}
.s-toast.success{background:#059669;} .s-toast.danger{background:#dc2626;} .s-toast.warning{background:#d97706;}

/* ---------- desktop: side navigation system ---------- */
.side-nav{display:none;}
@media (min-width:992px){
  :root{--side-w:256px;}
  body{padding-bottom:0;}
  .tab-bar{display:none;}
  html,body{background:radial-gradient(120% 90% at 100% 0%,#1f9d47 0%,#0f4d25 38%,#08180e 100%) fixed;}
  .side-nav{display:flex;flex-direction:column;position:fixed;top:16px;bottom:16px;left:16px;width:var(--side-w);z-index:1030;
    background:var(--s-dark);color:#cbd5e1;padding:20px 14px;border-radius:22px;box-shadow:0 20px 50px rgba(0,0,0,.35);}
  .side-search{display:flex;align-items:center;gap:8px;margin:0 4px 18px;padding:8px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.1);
    background:rgba(255,255,255,.03);}
  .side-search i{color:#6b7c72;font-size:.8rem;}
  .side-search input{flex:1;min-width:0;background:none;border:0;color:#e5efe8;font-size:.82rem;outline:none;}
  .side-search kbd{background:rgba(255,255,255,.08);color:#9fb3a6;font-size:.62rem;border-radius:6px;padding:2px 6px;}
  .side-brand{display:flex;align-items:center;gap:12px;padding:4px 8px 22px;color:#fff;text-decoration:none;}
  .side-brand b{display:block;font-size:1rem;font-weight:800;letter-spacing:-.01em;}
  .side-brand small{display:block;color:#94a3b8;font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;}
  .side-new{display:flex;align-items:center;gap:10px;margin:0 4px 14px;padding:10px 12px;border-radius:14px;color:#fff;
    font-weight:700;text-decoration:none;background:rgba(34,164,71,.14);border:1px solid rgba(74,222,128,.22);}
  .side-new i{width:28px;height:28px;border-radius:9px;display:grid;place-items:center;background:var(--s-accent);color:#fff;font-size:.8rem;}
  .side-new:hover{color:#fff;filter:brightness(1.08);}
  .side-label{font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#64748b;padding:0 12px 8px;}
  .side-link{display:flex;align-items:center;gap:12px;padding:10px 12px;margin-bottom:2px;border-radius:10px;color:#cbd5e1;
    text-decoration:none;font-weight:600;font-size:.88rem;transition:background .15s,color .15s;}
  .side-link i{width:18px;text-align:center;color:#94a3b8;}
  .side-link:hover{background:rgba(255,255,255,.06);color:#fff;}
  .side-link i{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;background:rgba(255,255,255,.05);}
  .side-link.on{background:rgba(255,255,255,.07);color:#fff;}
  .side-link.on i{color:#fff;background:var(--s-accent);}
  .side-badge{margin-left:auto;background:var(--s-accent);color:#fff;font-size:.66rem;font-weight:800;border-radius:9px;padding:2px 7px;font-style:normal;}
  .side-foot{margin-top:auto;border-top:1px solid rgba(255,255,255,.08);padding:14px 8px 0;display:flex;align-items:center;gap:10px;}
  .side-foot .who{flex:1;min-width:0;font-size:.78rem;color:#94a3b8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .side-foot .av{width:34px;height:34px;border-radius:50%;background:#1b2d22;color:#4ade80;display:grid;place-items:center;font-weight:700;flex:0 0 auto;}
  .side-foot a{color:#94a3b8;} .side-foot a:hover{color:#f87171;}
  .app-bar,.s-main{margin-left:calc(var(--side-w) + 32px);margin-right:16px;}
  .app-bar{margin-top:16px;padding:18px 28px 6px;background:var(--s-bg);border:0;border-radius:22px 22px 0 0;position:sticky;top:0;}
  .app-bar.scrolled{background:var(--s-bg);border:0;box-shadow:0 8px 16px -12px rgba(0,0,0,.25);}
  .app-bar-in{max-width:none;}
  .app-bar .app-logo{display:none;}
  .app-bar .app-title small{display:none;}
  .app-bar .app-title h1{font-size:1.6rem;}
  .app-bar .app-avatar-wrap{display:none;}
  .s-main{max-width:none;padding:14px 28px 32px;background:var(--s-bg);border-radius:0 0 22px 22px;margin-bottom:16px;min-height:calc(100vh - 110px);}
  .s-toast{left:calc(50% + var(--side-w) / 2);}
}
</style>
<?php include __DIR__ . '/../components/password_toggle.php'; ?>
</head>
<body>
<aside class="side-nav" aria-label="Support navigation">
  <a class="side-brand" href="<?php echo public_url('support/'); ?>">
    <span class="app-logo has-img"><img src="<?php echo htmlspecialchars($__brand['logo']); ?>" alt=""></span>
    <span><b><?php echo htmlspecialchars($__brand['name']); ?></b><small>Support console</small></span>
  </a>
  <a class="side-new" href="<?php echo public_url('support/accounts/?new=1'); ?>"><i class="fas fa-plus"></i><span>New account<small class="d-block fw-normal" style="color:#9fb3a6;font-size:.7rem;">Create a shop owner</small></span></a>
  <form class="side-search" method="get" action="<?php echo public_url('support/accounts/'); ?>">
    <i class="fas fa-magnifying-glass"></i><input name="q" placeholder="Search accounts…" id="sideSearch"><kbd>/</kbd>
  </form>
  <div class="side-label">Navigation</div>
  <?php foreach ($__side as [$k, $label, $icon, $url]): ?>
    <a class="side-link <?php echo $nav === $k ? 'on' : ''; ?>" href="<?php echo public_url($url); ?>">
      <i class="fas <?php echo $icon; ?>"></i><span><?php echo $label; ?></span>
      <?php if ($k === 'tickets' && $__newTickets): ?><em class="side-badge"><?php echo $__newTickets; ?></em><?php endif; ?>
    </a>
  <?php endforeach; ?>
  <div class="side-foot">
    <span class="av"><?php echo htmlspecialchars($__initial); ?></span>
    <span class="who"><?php echo htmlspecialchars(SupportAccount::email() ?? ''); ?></span>
    <a href="<?php echo public_url('support/logout.php'); ?>" title="Sign out" aria-label="Sign out"><i class="fas fa-arrow-right-from-bracket"></i></a>
  </div>
</aside>

<header class="app-bar" id="appBar">
  <div class="app-bar-in">
    <div class="app-logo has-img"><img src="<?php echo htmlspecialchars($__brand['logo']); ?>" alt=""></div>
    <div class="app-title"><small><?php echo htmlspecialchars($__brand['name']); ?> Support</small><h1><?php echo htmlspecialchars($page_title); ?></h1></div>
    <div class="dropdown app-avatar-wrap">
      <button class="app-avatar" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account"><?php echo htmlspecialchars($__initial); ?></button>
      <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="border-radius:14px;">
        <li><span class="dropdown-item-text small text-muted"><?php echo htmlspecialchars(SupportAccount::email() ?? ''); ?></span></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="<?php echo public_url('support/settings/'); ?>"><i class="fas fa-envelope me-2"></i>Email (SMTP) settings</a></li>
        <li><a class="dropdown-item text-danger" href="<?php echo public_url('support/logout.php'); ?>"><i class="fas fa-arrow-right-from-bracket me-2"></i>Sign out</a></li>
      </ul>
    </div>
  </div>
</header>

<?php if ($__flash): ?>
  <div class="s-toast <?php echo htmlspecialchars($__flash[0]); ?>" id="sToast" role="status">
    <i class="fas <?php echo $__flash[0] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> mt-1"></i>
    <span><?php echo htmlspecialchars($__flash[1]); ?></span>
  </div>
<?php endif; ?>

<main class="s-main">
  <?php echo $content; ?>
</main>

<nav class="tab-bar" aria-label="Main">
  <div class="tab-bar-in">
    <?php foreach ($__tabs as $k => $t): ?>
      <?php if ($t === null): ?>
        <a class="tab-fab" href="<?php echo public_url('support/accounts/?new=1'); ?>" aria-label="New account"><i class="fas fa-plus"></i></a>
      <?php else: [$label, $icon, $url] = $t; ?>
        <a class="tab <?php echo $nav === $k ? 'on' : ''; ?>" href="<?php echo public_url($url); ?>">
          <i class="fas <?php echo $icon; ?>"></i><span><?php echo $label; ?></span>
          <?php if ($k === 'tickets' && $__newTickets): ?><em class="tab-badge"><?php echo $__newTickets; ?></em><?php endif; ?>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  var bar = document.getElementById('appBar');
  var onScroll = function () { bar.classList.toggle('scrolled', window.scrollY > 4); };
  window.addEventListener('scroll', onScroll, { passive: true }); onScroll();
  var ss = document.getElementById('sideSearch');
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && ss && !/input|textarea|select/i.test(document.activeElement.tagName)) { e.preventDefault(); ss.focus(); }
  });
  var toast = document.getElementById('sToast');
  if (toast) {
    requestAnimationFrame(function () { toast.classList.add('show'); });
    setTimeout(function () { toast.classList.remove('show'); }, 5200);
    toast.addEventListener('click', function () { toast.classList.remove('show'); });
  }
})();
</script>
</body>
</html>
