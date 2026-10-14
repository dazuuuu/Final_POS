<?php
// public/support/_layout.php — Support console shell, built to look and behave
// like a mobile app: sticky app bar, bottom tab bar with a centre "+" action,
// toast messages. Expects $page_title, $content, $nav (and optional $wide).
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
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f172a">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Dazu Support">
<title><?php echo htmlspecialchars($page_title); ?> · Support</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --s-accent:#2563eb;--s-accent-2:#7c3aed;--s-dark:#0f172a;--s-bg:#f2f4f8;--s-ink:#0f172a;--s-mute:#64748b;
  --s-line:#e6e9f0;--s-card:#fff;--tab-h:68px;--safe-b:env(safe-area-inset-bottom,0px);--safe-t:env(safe-area-inset-top,0px);
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
.app-logo{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;color:#fff;font-size:1rem;
  background:linear-gradient(135deg,var(--s-accent),var(--s-accent-2));box-shadow:0 6px 16px rgba(37,99,235,.3);}
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
.tab.on::after{content:"";width:5px;height:5px;border-radius:50%;background:#60a5fa;margin-top:-1px;}
.tab:active i{transform:scale(.85);}
.tab{position:relative;}
.tab-badge{position:absolute;top:6px;left:calc(50% + 6px);font-style:normal;background:#ef4444;color:#fff;font-size:.6rem;font-weight:800;
  min-width:18px;height:18px;border-radius:9px;display:grid;place-items:center;padding:0 5px;border:2px solid #0f172a;}
.tab-fab{width:56px;height:56px;margin-top:-30px;border-radius:18px;display:grid;place-items:center;color:#fff;font-size:1.3rem;
  background:linear-gradient(135deg,var(--s-accent),var(--s-accent-2));box-shadow:0 10px 24px rgba(79,70,229,.45);
  border:4px solid var(--s-bg);text-decoration:none;transition:transform .15s;}
.tab-fab:active{transform:scale(.9);}

/* ---------- toast ---------- */
.s-toast{position:fixed;left:50%;top:calc(14px + var(--safe-t));transform:translate(-50%,-140%);z-index:1100;max-width:min(92vw,520px);
  padding:12px 16px;border-radius:16px;color:#fff;font-weight:600;font-size:.86rem;box-shadow:0 12px 30px rgba(0,0,0,.2);
  display:flex;gap:10px;align-items:flex-start;transition:transform .35s cubic-bezier(.2,.9,.3,1.2);}
.s-toast.show{transform:translate(-50%,0);}
.s-toast.success{background:#059669;} .s-toast.danger{background:#dc2626;} .s-toast.warning{background:#d97706;}

@media (min-width:992px){ .s-main{padding:20px 24px;} }
</style>
<?php include __DIR__ . '/../components/password_toggle.php'; ?>
</head>
<body>
<header class="app-bar" id="appBar">
  <div class="app-bar-in">
    <div class="app-logo"><i class="fas fa-headset"></i></div>
    <div class="app-title"><small>Dazu Support</small><h1><?php echo htmlspecialchars($page_title); ?></h1></div>
    <div class="dropdown">
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
