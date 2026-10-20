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
$__initial = strtoupper(substr(SupportAccount::currentEmail() ?: 'S', 0, 1));
$__brand = Branding::platform(); // Support → Settings → Platform brand: also used on the shared login pages
if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/support/settings/') !== false) $nav = 'settings';
// Desktop side navigation: [key, label, icon, url]
$__side = [
    ['overview', 'Overview', 'fa-gauge-high', 'support/'],
    ['accounts', 'Accounts', 'fa-table-cells', 'support/accounts/'],
    ['packages', 'Packages', 'fa-box-open', 'support/packages/'],
    ['tickets', 'Support tickets', 'fa-ticket', 'support/tickets/'],
    ['quotes', 'Quote requests', 'fa-file-signature', 'support/quotes/'],
    ['alerts', 'News & alerts', 'fa-bullhorn', 'support/alerts/'],
    ['settings', 'Settings', 'fa-gear', 'support/settings/'],
];
if (SupportAccount::isOwner()) { // the support team is managed by the main admin only
    array_splice($__side, 6, 0, [['team', 'Support team', 'fa-user-shield', 'support/team/']]);
}
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
/* Support console — monochrome, flat: white surfaces, hairline borders, black for emphasis. No gradients, no shadows. */
:root{
  --s-accent:#18181b;--s-accent-2:#18181b;--s-dark:#09090b;--s-bg:#ffffff;--s-ink:#09090b;--s-mute:#71717a;
  --s-line:#e4e4e7;--s-soft:#f4f4f5;--s-green-soft:#f4f4f5;--s-card:#fff;--tab-h:64px;--safe-b:env(safe-area-inset-bottom,0px);--safe-t:env(safe-area-inset-top,0px);
}
*{-webkit-tap-highlight-color:transparent;}
html,body{background:var(--s-bg);}
body{font-family:'Inter',system-ui,sans-serif;color:var(--s-ink);font-size:.9rem;
  padding-bottom:calc(var(--tab-h) + var(--safe-b) + 20px);overscroll-behavior-y:none;}
a{color:var(--s-ink);}
.text-primary{color:var(--s-ink) !important;} .bg-primary{background-color:var(--s-ink) !important;}
.btn{border-radius:8px;font-weight:500;box-shadow:none !important;}
.btn-primary{background:var(--s-dark);border-color:var(--s-dark);color:#fff;}
.btn-primary:hover,.btn-primary:focus,.btn-primary:active{background:#27272a !important;border-color:#27272a !important;color:#fff;}
.btn-outline-primary,.btn-outline-secondary{color:var(--s-ink);border-color:var(--s-line);background:#fff;}
.btn-outline-primary:hover,.btn-outline-secondary:hover{background:var(--s-soft);border-color:var(--s-line);color:var(--s-ink);}
.btn-light{background:#fff;border:1px solid var(--s-line);}
.form-control,.form-select{border-radius:8px;border-color:var(--s-line);box-shadow:none !important;}
.form-control:focus,.form-select:focus{border-color:#a1a1aa;}
.form-check-input:checked{background-color:var(--s-ink);border-color:var(--s-ink);}
.badge.bg-success{background:var(--s-ink) !important;} .pkg-on{background:var(--s-soft) !important;color:var(--s-ink) !important;font-weight:600;}
.table td,.table th{vertical-align:middle;}
h1.h4{font-weight:600;letter-spacing:-.02em;}

/* ---------- app bar ---------- */
.app-bar{position:sticky;top:0;z-index:1020;padding:calc(10px + var(--safe-t)) 16px 10px;background:#fff;border-bottom:1px solid var(--s-line);}
.app-bar-in{max-width:1280px;margin:0 auto;display:flex;align-items:center;gap:12px;}
.app-logo{width:34px;height:34px;border-radius:8px;display:grid;place-items:center;overflow:hidden;border:1px solid var(--s-line);background:#fff;}
.app-logo img{width:100%;height:100%;object-fit:contain;}
.app-title{flex:1;min-width:0;}
.app-title small{display:block;color:var(--s-mute);font-size:.7rem;font-weight:500;}
.app-title h1{font-size:1.1rem;font-weight:600;margin:0;letter-spacing:-.01em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.app-avatar{padding:0;cursor:pointer;width:34px;height:34px;border-radius:50%;background:var(--s-soft);border:1px solid var(--s-line);display:grid;place-items:center;font-weight:600;color:var(--s-ink);}
.dropdown-menu{box-shadow:none !important;border:1px solid var(--s-line) !important;border-radius:10px !important;}

/* ---------- content ---------- */
.s-main{max-width:1280px;margin:0 auto;padding:16px;}
.s-card{background:#fff;border:1px solid var(--s-line);border-radius:12px;padding:18px;}
.s-stat .n{font-size:1.5rem;font-weight:600;letter-spacing:-.02em;}
.s-stat .l{color:var(--s-mute);font-size:.78rem;font-weight:500;}

/* ---------- bottom tab bar (phones) ---------- */
.tab-bar{position:fixed;left:0;right:0;bottom:0;z-index:1030;background:#fff;border-top:1px solid var(--s-line);padding-bottom:var(--safe-b);}
.tab-bar-in{max-width:560px;margin:0 auto;height:var(--tab-h);display:flex;align-items:center;justify-content:space-around;padding:0 6px;}
.tab{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;color:var(--s-mute);text-decoration:none;font-size:.66rem;font-weight:500;padding:8px 0;position:relative;}
.tab i{font-size:1.1rem;}
.tab.on{color:var(--s-ink);font-weight:600;}
.tab-badge{position:absolute;top:4px;left:calc(50% + 6px);font-style:normal;background:var(--s-ink);color:#fff;font-size:.6rem;font-weight:700;
  min-width:17px;height:17px;border-radius:9px;display:grid;place-items:center;padding:0 5px;}
.tab-fab{width:46px;height:46px;border-radius:12px;display:grid;place-items:center;color:#fff;font-size:1.1rem;background:var(--s-ink);text-decoration:none;}

/* ---------- toast ---------- */
.s-toast{position:fixed;left:50%;top:calc(14px + var(--safe-t));transform:translate(-50%,-160%);z-index:1100;max-width:min(92vw,520px);
  padding:11px 15px;border-radius:10px;font-weight:500;font-size:.86rem;display:flex;gap:10px;align-items:flex-start;
  background:#fff;color:var(--s-ink);border:1px solid var(--s-line);transition:transform .3s ease;}
.s-toast.show{transform:translate(-50%,0);}
.s-toast.danger{border-color:#fecaca;color:#991b1b;} .s-toast.warning{border-color:#fde68a;}

/* ---------- desktop: side navigation ---------- */
.side-nav{display:none;}
@media (min-width:992px){
  :root{--side-w:248px;}
  body{padding-bottom:0;}
  .tab-bar{display:none;}
  .side-nav{display:flex;flex-direction:column;position:fixed;top:0;bottom:0;left:0;width:var(--side-w);z-index:1030;
    background:#fafafa;border-right:1px solid var(--s-line);color:var(--s-ink);padding:16px 12px;}
  .side-brand{display:flex;align-items:center;gap:10px;padding:2px 6px 16px;color:var(--s-ink);text-decoration:none;}
  .side-brand b{display:block;font-size:.92rem;font-weight:600;}
  .side-brand small{display:block;color:var(--s-mute);font-size:.72rem;}
  .side-search{display:flex;align-items:center;gap:8px;margin:0 2px 14px;padding:7px 10px;border-radius:8px;border:1px solid var(--s-line);background:#fff;}
  .side-search i{color:var(--s-mute);font-size:.78rem;}
  .side-search input{flex:1;min-width:0;background:none;border:0;color:var(--s-ink);font-size:.82rem;outline:none;}
  .side-search kbd{background:var(--s-soft);color:var(--s-mute);font-size:.62rem;border-radius:5px;padding:2px 6px;border:1px solid var(--s-line);}
  .side-new{display:flex;align-items:center;gap:10px;margin:0 2px 14px;padding:8px 10px;border-radius:8px;color:#fff;background:var(--s-ink);
    font-weight:500;font-size:.85rem;text-decoration:none;}
  .side-new i{font-size:.8rem;} .side-new small{color:#a1a1aa !important;}
  .side-new:hover{color:#fff;background:#27272a;}
  .side-label{font-size:.72rem;font-weight:500;color:var(--s-mute);padding:0 10px 6px;}
  .side-link{display:flex;align-items:center;gap:10px;padding:7px 10px;margin-bottom:1px;border-radius:7px;color:#3f3f46;text-decoration:none;font-weight:500;font-size:.86rem;}
  .side-link i{width:16px;text-align:center;color:#71717a;font-size:.85rem;}
  .side-link:hover{background:#f4f4f5;color:var(--s-ink);}
  .side-link.on{background:#f4f4f5;color:var(--s-ink);font-weight:600;}
  .side-link.on i{color:var(--s-ink);}
  .side-badge{margin-left:auto;background:var(--s-ink);color:#fff;font-size:.64rem;font-weight:600;border-radius:9px;padding:1px 7px;font-style:normal;}
  .side-foot{margin-top:auto;border-top:1px solid var(--s-line);padding:12px 6px 0;display:flex;align-items:center;gap:10px;}
  .side-foot .who{flex:1;min-width:0;font-size:.78rem;color:var(--s-mute);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .side-foot .av{width:30px;height:30px;border-radius:50%;background:var(--s-soft);border:1px solid var(--s-line);color:var(--s-ink);display:grid;place-items:center;font-weight:600;flex:0 0 auto;}
  .side-foot a{color:var(--s-mute);} .side-foot a:hover{color:var(--s-ink);}
  .app-bar,.s-main{margin-left:var(--side-w);}
  .app-bar{padding:14px 28px;}
  .app-bar-in{max-width:none;}
  .app-bar .app-logo,.app-bar .app-title small,.app-bar .app-avatar-wrap{display:none;}
  .app-bar .app-title h1{font-size:1.35rem;}
  .s-main{max-width:none;padding:20px 28px 32px;}
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
      <?php if ($k === 'quotes' && class_exists('Quote') && ($__nq = Quote::newCount())): ?><em class="side-badge"><?php echo $__nq; ?></em><?php endif; ?>
    </a>
  <?php endforeach; ?>
  <div class="side-foot">
    <span class="av"><?php echo htmlspecialchars($__initial); ?></span>
    <span class="who"><?php echo htmlspecialchars(SupportAccount::currentEmail()); ?></span>
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
        <li><span class="dropdown-item-text small text-muted"><?php echo htmlspecialchars(SupportAccount::currentEmail()); ?><br><?php echo SupportAccount::isOwner() ? 'Main admin' : 'Support team'; ?></span></li>
        <?php if (SupportAccount::isOwner()): ?><li><a class="dropdown-item" href="<?php echo public_url('support/team/'); ?>"><i class="fas fa-user-shield me-2"></i>Support team</a></li><?php endif; ?>
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
