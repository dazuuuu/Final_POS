<?php
// public/support/install.php — the Support app's own install link (share this one: …/support/install.php).
// Opens a page that installs the Support console as a standalone app — it never goes to the landing page.
// Already installed and opened as the app → straight to the console.
require_once __DIR__ . '/../../app/app.php';
$brand = Branding::platform();
$name = $brand['name'] . ' Support';
$go = public_url('support/');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#0c1711">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($name); ?>">
<link rel="manifest" href="<?php echo htmlspecialchars(public_url('support/manifest.php')); ?>">
<link rel="apple-touch-icon" href="<?php echo htmlspecialchars(public_url('assets/icons/apple-touch-icon.png')); ?>">
<link rel="icon" type="image/png" href="<?php echo htmlspecialchars(public_url('assets/icons/favicon-32.png')); ?>">
<title>Install <?php echo htmlspecialchars($name); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:#0c1711;color:#fff;font:16px/1.5 -apple-system,'Segoe UI',Roboto,Arial,sans-serif}
.c{max-width:380px;width:100%;text-align:center}.c img{width:92px;height:92px;border-radius:22px;box-shadow:0 10px 30px rgba(0,0,0,.4)}
h1{font-size:1.5rem;margin:18px 0 6px}p{color:#a8b8ae;margin:0 0 22px}
button,.b{display:block;width:100%;border:0;border-radius:12px;padding:14px;font:700 1rem inherit;background:#1f9a5f;color:#fff;text-decoration:none;cursor:pointer}
.b2{background:transparent;border:1px solid #2e4538;margin-top:10px;font-weight:600}
.n{margin-top:18px;font-size:.85rem;color:#a8b8ae;text-align:left;background:#13241b;border-radius:12px;padding:12px 14px;display:none}
</style>
<script>if ('serviceWorker' in navigator) { addEventListener('load', function () { navigator.serviceWorker.register('<?php echo public_url('sw.js'); ?>', { scope: '<?php echo public_url(); ?>' }).catch(function () {}); }); }</script>
</head>
<body>
<div class="c">
  <img src="<?php echo htmlspecialchars(public_url('assets/icons/icon-192.png')); ?>" alt="">
  <h1><?php echo htmlspecialchars($name); ?></h1>
  <p>Accounts, packages and tickets — as its own app on your phone.</p>
  <button type="button" id="inst">Install app</button>
  <a class="b b2" href="<?php echo htmlspecialchars($go); ?>">Open in the browser instead</a>
  <div class="n" id="ios"><b>On iPhone / iPad:</b> open this page in Safari, tap <b>Share</b>, then <b>Add to Home Screen</b>.</div>
  <div class="n" id="man"><b>If nothing popped up:</b> open your browser menu (⋮) and choose <b>Install app</b> / <b>Add to Home screen</b>. Use Chrome or Edge on Android.</div>
  <div class="n" id="done"><b>Installed.</b> Open <b>Support</b> from your home screen.</div>
</div>
<script>
(function () {
  var standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  if (standalone) { location.replace(<?php echo json_encode($go); ?>); return; } // opened as the app: go straight in
  var btn = document.getElementById('inst'), deferred = null;
  var ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  function show(id) { document.getElementById(id).style.display = 'block'; }
  if (ios) show('ios');
  addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; });
  addEventListener('appinstalled', function () { btn.style.display = 'none'; show('done'); });
  btn.onclick = function () {
    if (deferred) { deferred.prompt(); deferred.userChoice.then(function (c) { deferred = null; if (c.outcome === 'accepted') { btn.style.display = 'none'; show('done'); } }); }
    else if (ios) show('ios'); else show('man');
  };
})();
</script>
</body>
</html>
