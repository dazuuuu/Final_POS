<?php
// public/components/install_button.php — "Install app" in the owner / staff top bar.
// Chrome / Edge / Android: opens the browser's own install prompt (the POS is a PWA:
// manifest + service worker from pwa_head.php). iPhone / iPad have no prompt —
// a short "Share → Add to Home Screen" guide shows instead. Hidden once installed
// or when already running as the installed app.
?>
<button type="button" class="btn btn-sm btn-success" id="dzInstall" style="display:none;white-space:nowrap" title="Install this POS on this device">
  <i class="fas fa-download me-1"></i><span>Install app</span>
</button>
<div id="dzInstallHelp" style="display:none;position:fixed;inset:0;z-index:4000;background:rgba(0,0,0,.45);align-items:flex-end;justify-content:center;padding:16px">
  <div style="background:#fff;border-radius:16px;max-width:380px;width:100%;padding:22px 20px;font-size:.92rem;line-height:1.55">
    <b style="font-size:1.05rem">Install on this iPhone / iPad</b>
    <ol style="margin:12px 0 16px;padding-left:20px">
      <li>Tap the <b>Share</b> button <i class="fas fa-arrow-up-from-bracket"></i> at the bottom of Safari.</li>
      <li>Choose <b>Add to Home Screen</b>, then <b>Add</b>.</li>
      <li>Open the POS from its new icon — it runs full screen like an app.</li>
    </ol>
    <button type="button" class="btn btn-success w-100" onclick="document.getElementById('dzInstallHelp').style.display='none'">Got it</button>
  </div>
</div>
<script>
(function () {
  var btn = document.getElementById('dzInstall'), help = document.getElementById('dzInstallHelp'), deferred = null;
  var installed = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  var ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  if (installed) return;
  if (ios) btn.style.display = '';
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; btn.style.display = ''; });
  window.addEventListener('appinstalled', function () { btn.style.display = 'none'; deferred = null; });
  btn.addEventListener('click', function () {
    if (deferred) {
      deferred.prompt();
      deferred.userChoice.then(function (c) { if (c.outcome === 'accepted') btn.style.display = 'none'; deferred = null; });
    } else if (ios) {
      help.style.display = 'flex';
    } else {
      alert('To install: open your browser menu (⋮) and choose "Install app" or "Add to Home screen".');
    }
  });
})();
</script>
