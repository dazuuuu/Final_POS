<?php
// public/components/support_install_banner.php — a small "Install the Support app" bar for the Support console.
// Appears on phones / browsers that can install it (Chrome, Edge, Android) and, on iPhone, with the Add-to-Home-Screen steps.
// Hidden once installed, and after "Not now" (remembered for a week).
$__sbName = htmlspecialchars(Branding::platform()['name'] . ' Support');
?>
<div id="spBanner" style="display:none;position:fixed;left:12px;right:12px;bottom:calc(12px + env(safe-area-inset-bottom));z-index:4000;background:#0c1711;color:#fff;border-radius:14px;padding:12px 14px;box-shadow:0 10px 30px rgba(0,0,0,.35);align-items:center;gap:10px;font-size:.9rem">
  <img src="<?php echo htmlspecialchars(public_url('assets/icons/icon-192.png')); ?>" alt="" style="width:38px;height:38px;border-radius:9px">
  <div style="flex:1;line-height:1.25"><b><?php echo $__sbName; ?></b><br><span style="opacity:.75;font-size:.8rem">Install it as an app on this device</span></div>
  <button type="button" id="spInstall" style="border:0;background:#1f9a5f;color:#fff;border-radius:9px;padding:8px 14px;font-weight:700">Install</button>
  <button type="button" id="spLater" aria-label="Not now" style="border:0;background:transparent;color:#fff;opacity:.6;font-size:1.2rem">&times;</button>
</div>
<div id="spIos" style="display:none;position:fixed;inset:0;z-index:4100;background:rgba(0,0,0,.5);align-items:flex-end;justify-content:center;padding:16px">
  <div style="background:#fff;color:#111;border-radius:16px;max-width:380px;width:100%;padding:22px 20px;font-size:.92rem;line-height:1.55">
    <b style="font-size:1.05rem">Install on this iPhone / iPad</b>
    <ol style="margin:12px 0 16px;padding-left:20px"><li>Open this page in <b>Safari</b> and tap <b>Share</b> (the square with an arrow).</li><li>Choose <b>Add to Home Screen</b>, then <b>Add</b>.</li><li>Open <b>Support</b> from its icon — it runs full screen like an app.</li></ol>
    <button type="button" style="width:100%;border:0;background:#1f9a5f;color:#fff;border-radius:10px;padding:11px;font-weight:700" onclick="document.getElementById('spIos').style.display='none'">Got it</button>
  </div>
</div>
<script>
(function () {
  var bar = document.getElementById('spBanner'), ios = document.getElementById('spIos'), deferred = null;
  var installed = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  var isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var snooze = 0; try { snooze = +localStorage.getItem('spInstallLater') || 0; } catch (e) {}
  if (installed || Date.now() < snooze) return;
  function show() { bar.style.display = 'flex'; }
  if (isIos) show();
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; show(); });
  window.addEventListener('appinstalled', function () { bar.style.display = 'none'; });
  document.getElementById('spInstall').onclick = function () {
    if (deferred) { deferred.prompt(); deferred.userChoice.then(function () { deferred = null; bar.style.display = 'none'; }); }
    else if (isIos) { ios.style.display = 'flex'; }
    else { alert('Open your browser menu (⋮) and choose "Install app" or "Add to Home screen".'); }
  };
  document.getElementById('spLater').onclick = function () { bar.style.display = 'none'; try { localStorage.setItem('spInstallLater', String(Date.now() + 7 * 864e5)); } catch (e) {} };
})();
</script>
