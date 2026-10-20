<?php
// public/components/boot_splash.php — the start-up screen of the POS sign-in
// pages (owner login, staff PIN terminal). Shown once per app launch (a new
// tab or a fresh start of the installed app — sessionStorage), about a second:
// logo, name and a thin progress line, then it fades away.
// With it plays the boot sound: the one uploaded in Support → Settings (already
// trimmed to a few seconds when it was uploaded), or the built-in chime, made
// live with the Web Audio API — no file to download.
// Browsers only allow sound after the person has used the site; when a browser
// blocks it, the sound plays on the first tap instead (within a few seconds).
$__boot = Branding::platform();
if (empty($__boot['boot_on'])) {
    $__bootSound = 'off';
} else {
    $__bootSound = $__boot['boot_sound'] !== '' ? $__boot['boot_sound'] : 'default';
}
?>
<div id="dzBoot" aria-hidden="true" style="position:fixed;inset:0;z-index:99999;background:#fff;display:none;align-items:center;justify-content:center;flex-direction:column;gap:14px;transition:opacity .28s ease">
  <img src="<?php echo htmlspecialchars($__boot['logo']); ?>" alt="" style="width:64px;height:64px;object-fit:contain;border-radius:14px;border:1px solid #e4e4e7;padding:6px;background:#fff">
  <div style="font:600 15px/1.2 -apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:#09090b;letter-spacing:-.01em"><?php echo htmlspecialchars($__boot['name']); ?></div>
  <div style="width:140px;height:3px;border-radius:3px;background:#f4f4f5;overflow:hidden"><i id="dzBootBar" style="display:block;height:100%;width:0;background:#09090b;border-radius:3px;transition:width .9s cubic-bezier(.4,0,.2,1)"></i></div>
</div>
<script src="<?php echo htmlspecialchars(public_url('assets/js/boot-chime.js')); ?>"></script>
<script>
(function () {
  var KEY = 'dz-booted';
  try { if (sessionStorage.getItem(KEY)) return; sessionStorage.setItem(KEY, '1'); } catch (e) { return; }
  var el = document.getElementById('dzBoot'), bar = document.getElementById('dzBootBar');
  el.style.display = 'flex';
  requestAnimationFrame(function () { requestAnimationFrame(function () { bar.style.width = '100%'; }); });
  setTimeout(function () { el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 300); }, 1050);

  var SOUND = <?php echo json_encode($__bootSound); ?>;
  if (SOUND === 'off') return;

  function play() {
    if (SOUND === 'default') {
      var AC = window.AudioContext || window.webkitAudioContext; if (!AC) return Promise.reject();
      var ctx = new AC();
      return (ctx.state === 'suspended' ? ctx.resume() : Promise.resolve()).then(function () {
        if (ctx.state !== 'running') throw new Error('blocked');
        window.dzChime(ctx);
      });
    }
    var a = new Audio(SOUND); a.volume = 0.8;
    return a.play();
  }
  play().catch(function () {
    // Sound blocked until the person interacts: play on the first tap or key, if it comes soon.
    var until = Date.now() + 6000;
    function once() {
      document.removeEventListener('pointerdown', once, true); document.removeEventListener('keydown', once, true);
      if (Date.now() < until) play().catch(function () {});
    }
    document.addEventListener('pointerdown', once, true); document.addEventListener('keydown', once, true);
  });
})();
</script>
