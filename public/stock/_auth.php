<?php
// public/stock/_auth.php — frame of the Stock Control sign-in pages (sign in,
// code, forgot password): a split card — the picture on the left (uploaded in
// Support → Settings → Login forms, else built-in artwork) with a welcome word,
// the form on the right with the organisation's logo and name. No sign-up link:
// accounts are created by support. Follows the device's dark / light setting.
// Expects $title, $content, optional $error / $notice, $__authLogin (sign-in page).
$__org = stock_org();
$__name = $__org['name'] ?? STOCK_NAME;
$__logo = $__org ? Branding::shopLogo($__org) : '';
$__brand = Branding::platform();
$__img = $__brand['stock_login_img'];
$__welcome = $__brand['stock_welcome'];
$__ini = strtoupper(implode('', array_map(fn($w) => substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($__name)) ?: [], 0, 2))));
$__art = '';
if ($__img === '') { // built-in artwork: a night hillside of foliage, a lit house, flowers
    mt_srand(11);
    $c = ['#0a1450', '#0f2170', '#16308f', '#1d46c4', '#2563eb', '#1f6f5c', '#2f8f6a', '#4cb98a', '#86d4a8', '#f59e0b', '#fbbf24', '#fde68a', '#ea580c'];
    $dots = '';
    for ($i = 0; $i < 1100; $i++) {
        $x = mt_rand(0, 400); $y = mt_rand(0, 600);
        $near = abs($y - (470 - 0.62 * $x)) < 85; // the lit band running up the hill
        $col = $near ? $c[mt_rand(5, 12)] : $c[mt_rand(0, 4)];
        $r = $near ? mt_rand(3, 8) : mt_rand(5, 13);
        $dots .= '<circle cx="' . $x . '" cy="' . $y . '" r="' . $r . '" fill="' . $col . '" opacity="' . (0.5 + mt_rand(0, 45) / 100) . '"/>';
    }
    $__art = '<svg viewBox="0 0 400 600" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#050b33"/><stop offset="1" stop-color="#0c1c66"/></linearGradient></defs>'
        . '<rect width="400" height="600" fill="url(#g)"/>' . $dots
        . '<g transform="translate(118 372)"><polygon points="0,18 34,0 68,18" fill="#fbbf24"/><rect x="6" y="18" width="56" height="26" fill="#fde68a"/><rect x="26" y="26" width="14" height="18" fill="#c2410c"/></g></svg>';
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#d9dbe0" id="themeColor">
<meta name="robots" content="noindex">
<title><?php echo sh($title); ?> · <?php echo sh($__name); ?></title>
<script>
(function () {
  try {
    var t = localStorage.getItem('sc-theme');
    if (t !== 'light' && t !== 'dark') t = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', t);
    var m = document.getElementById('themeColor'); if (m) m.content = t === 'dark' ? '#070c18' : '#d9dbe0';
  } catch (e) {}
})();
</script>
<?php if ($__logo): ?><link rel="icon" href="<?php echo sh($__logo); ?>"><?php endif; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="<?php echo public_url('assets/js/page-loader.js'); ?>?v=<?php echo @filemtime(ROOT_PATH . '/public/assets/js/page-loader.js'); ?>"></script>
<style>
:root{--page:#d9dbe0;--card:#fff;--ink:#0f172a;--ink2:#334155;--mute:#64748b;--line:#c9ced8;--btn:#0a1550;--btn2:#16248a;--link:#0a1550;--dz-accent:#16248a;color-scheme:light;}
html[data-theme=dark]{--page:#070c18;--card:#0f172a;--ink:#e8eefb;--ink2:#b9c4da;--mute:#8d9bb5;--line:#2a3856;--btn:#3b5bdb;--btn2:#5b7cf5;--link:#93a8ff;--dz-accent:#5b7cf5;color-scheme:dark;}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;min-height:100svh;font:15px/1.55 Inter,system-ui,sans-serif;color:var(--ink);background:var(--page);display:flex;align-items:center;justify-content:center;padding:28px 18px;}
.shell{width:100%;max-width:1000px;min-height:600px;display:grid;grid-template-columns:1.02fr 1fr;background:var(--card);border-radius:18px;overflow:hidden;box-shadow:0 30px 70px -30px rgba(40,50,120,.45);}
.art{position:relative;min-height:340px;background:#0a1450 center/cover no-repeat;<?php echo $__img !== '' ? "background-image:url('" . sh($__img) . "');" : ''; ?>}
.art svg{position:absolute;inset:0;width:100%;height:100%;}
.art::after{content:"";position:absolute;inset:0;background:linear-gradient(90deg,rgba(5,11,51,.35),transparent 60%);pointer-events:none;}
.welcome{position:absolute;left:11%;top:34%;z-index:2;margin:0;color:#e9edf7;font-weight:300;font-size:2.15rem;letter-spacing:.38em;text-transform:uppercase;text-shadow:0 2px 18px rgba(0,0,0,.45);}
.formside{display:flex;flex-direction:column;justify-content:center;padding:44px 56px 30px;}
.org{display:flex;align-items:center;justify-content:center;gap:11px;margin-bottom:26px;}
.mk{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#22c55e,#0ea5e9);color:#fff;font-weight:700;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:0 0 auto;}
.mk img{width:100%;height:100%;object-fit:contain;background:#fff;}
.org b{font-size:1.02rem;font-weight:600;letter-spacing:-.01em;}
.org small{display:block;color:var(--mute);font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;font-weight:500;}
h1.big{font-size:2.3rem;font-weight:500;margin:0 0 34px;text-align:center;letter-spacing:-.02em;}
h2{font-size:1.4rem;margin:0 0 6px;font-weight:500;text-align:center;}
.sub{color:var(--mute);margin:0 0 24px;font-size:.9rem;text-align:center;}
label{display:block;font-size:.78rem;font-weight:500;color:var(--mute);margin:0 0 2px;}
.lbl-hide label{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;}
.fld{position:relative;margin-bottom:24px;}
.fld>i{display:none;}
.in{width:100%;border:0;border-bottom:1.5px solid var(--line);border-radius:0;padding:12px 2px;font:inherit;font-size:1.05rem;background:transparent;color:var(--ink);}
.in::placeholder{color:var(--mute);opacity:.9;}
.in:focus{outline:none;border-bottom-color:var(--btn2);box-shadow:0 1.5px 0 0 var(--btn2);}
.in.code{letter-spacing:.55em;text-align:center;font-size:1.5rem;font-weight:600;}
.fld.pw .in{padding-right:128px;}
.flink{position:absolute;right:0;top:50%;transform:translateY(-50%);font-size:.78rem;font-weight:500;color:var(--link);text-decoration:none;}
.btn{width:100%;border:0;border-radius:14px;padding:15px;margin-top:10px;background:linear-gradient(135deg,var(--btn),var(--btn2));color:#fff;font:500 1.12rem Inter,system-ui,sans-serif;cursor:pointer;box-shadow:0 14px 26px -14px var(--btn);transition:filter .15s,transform .05s;}
.btn:hover{filter:brightness(1.12);} .btn:active{transform:scale(.99);}
.link{color:var(--link);font-weight:500;font-size:.88rem;text-decoration:none;}
.alt{display:flex;justify-content:space-between;align-items:center;margin-top:18px;font-size:.88rem;gap:10px;flex-wrap:wrap;}
.msg{padding:10px 12px;border-radius:12px;font-size:.86rem;margin-bottom:18px;}
.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;} .ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
html[data-theme=dark] .err{background:#2a1214;color:#fca5a5;border-color:#7f1d1d;} html[data-theme=dark] .ok{background:#0f2a1c;color:#86efac;border-color:#14532d;}
.secure{display:flex;gap:8px;align-items:center;justify-content:center;color:var(--mute);font-size:.78rem;margin-top:auto;padding-top:34px;text-align:center;}
.tbtn{position:fixed;right:14px;top:max(14px,env(safe-area-inset-top));z-index:5;width:38px;height:38px;border-radius:12px;border:1px solid var(--line);background:var(--card);color:var(--ink2);cursor:pointer;display:grid;place-items:center;font-size:.95rem;}
@media(max-width:820px){
  body{padding:0;align-items:stretch;background:var(--card);}
  .shell{grid-template-columns:1fr;border-radius:0;min-height:100svh;max-width:none;box-shadow:none;}
  .art{min-height:210px;} .welcome{font-size:1.5rem;left:8%;top:auto;bottom:26px;letter-spacing:.3em;}
  .formside{padding:26px 24px 24px;}
  h1.big{font-size:1.9rem;margin-bottom:26px;}
  .in{font-size:16px;}
}
</style>
</head>
<body>
<button class="tbtn" id="tbtn" type="button" aria-label="Dark / light mode"><i class="fas fa-moon"></i></button>
<main class="shell">
  <section class="art" aria-hidden="true">
    <?php echo $__art; ?>
    <p class="welcome"><?php echo sh($__welcome); ?></p>
  </section>
  <section class="formside<?php echo !empty($__authLogin) ? ' lbl-hide' : ''; ?>">
    <div class="org">
      <span class="mk"><?php if ($__logo): ?><img src="<?php echo sh($__logo); ?>" alt="<?php echo sh($__name); ?>"><?php else: echo sh($__ini ?: 'SC'); endif; ?></span>
      <span><b><?php echo sh($__name); ?></b><small>Inventory management</small></span>
    </div>
    <?php if (!empty($error)): ?><div class="msg err"><?php echo sh($error); ?></div><?php endif; ?>
    <?php if (!empty($notice)): ?><div class="msg ok"><?php echo sh($notice); ?></div><?php endif; ?>
    <?php echo $content; ?>
    <div class="secure"><i class="fas fa-shield-halved"></i> Every sign-in is confirmed with a code sent to your email.</div>
  </section>
</main>
<script>
(function () {
  var root = document.documentElement, b = document.getElementById('tbtn');
  function paint() { b.firstChild.className = 'fas ' + (root.getAttribute('data-theme') === 'dark' ? 'fa-sun' : 'fa-moon'); }
  b.addEventListener('click', function () {
    var t = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', t); try { localStorage.setItem('sc-theme', t); } catch (e) {}
    var m = document.getElementById('themeColor'); if (m) m.content = t === 'dark' ? '#070c18' : '#d9dbe0'; paint();
  });
  paint();
})();
</script>
</body>
</html>
