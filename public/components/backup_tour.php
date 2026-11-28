<?php
// public/components/backup_tour.php — a mandatory, blocking walkthrough of
// the new backup feature (save it, and how to restore it) shown once per
// user. No skip, no backdrop-close — only "Next"/"Got it, start using it"
// moves it along, and the last step requires them to actually find the real
// menu item before it lets go.
if (empty($_SESSION['logged_in']) || !empty($_SESSION['demo']) || !class_exists('AccountBackup')) return;
// Support previews DO see this (so support can check the experience), but
// finishing it in preview never marks it seen for the real account — see tour_seen.php.
try {
    if (AccountBackup::tourSeen((int) TenantContext::userId())) return;
} catch (Throwable $e) { return; }
$__isStaff = TenantContext::role() === 'staff';
$__isOwner = TenantContext::isPrimaryOwner();
$__backupUrl = public_url(($__isStaff ? 'staff' : 'super') . '/account-backup.php');
$__settingsUrl = public_url('super/backup/');
?>
<style>
.dz-tr-veil{position:fixed;inset:0;z-index:3050;background:rgba(15,15,20,.7);}
.dz-tr{position:fixed;inset:0;z-index:3100;display:flex;align-items:center;justify-content:center;padding:16px;pointer-events:none;}
.dz-tr-box{pointer-events:auto;background:#fff;border-radius:18px;max-width:480px;width:100%;padding:30px 28px 26px;box-shadow:0 24px 60px rgba(0,0,0,.35);}
.dz-tr-dots{display:flex;gap:6px;margin-bottom:18px;}
.dz-tr-dots span{height:5px;flex:1;border-radius:3px;background:#e5e7eb;}
.dz-tr-dots span.on{background:#1f9a5f;}
.dz-tr-icon{width:54px;height:54px;border-radius:14px;background:#eef2ff;color:#4338ca;display:flex;align-items:center;justify-content:center;font-size:22px;margin-bottom:16px;}
.dz-tr-step{display:none;}
.dz-tr-step.on{display:block;}
.dz-tr h2{font-size:1.2rem;font-weight:700;margin:0 0 10px;}
.dz-tr p{font-size:.92rem;color:#444;line-height:1.6;margin:0 0 8px;}
.dz-tr ol{font-size:.9rem;color:#333;line-height:1.7;margin:10px 0 0;padding-left:20px;}
.dz-tr-nav{display:flex;gap:10px;margin-top:22px;}
.dz-tr-btn{flex:1;background:#1f9a5f;color:#fff;border:0;text-align:center;font-weight:700;padding:13px;border-radius:10px;font-size:.92rem;cursor:pointer;}
.dz-tr-btn[disabled]{background:#9ca3af;cursor:not-allowed;}
.dz-tr-note{font-size:.78rem;color:#9ca3af;margin-top:10px;text-align:center;}
.dz-spot{position:fixed;z-index:3080;border-radius:10px;box-shadow:0 0 0 4px #1f9a5f,0 0 0 9999px rgba(15,15,20,.7);transition:all .25s ease;pointer-events:none;}
</style>
<div class="dz-tr-veil" id="dzTourVeil"></div>
<div class="dz-tr" id="dzTour" role="dialog" aria-modal="true">
  <div class="dz-tr-box">
    <div class="dz-tr-dots"><span class="on" data-dot="1"></span><span data-dot="2"></span><span data-dot="3"></span><span data-dot="4"></span></div>

    <div class="dz-tr-step on" data-step="1">
      <div class="dz-tr-icon"><i class="fas fa-shield-halved"></i></div>
      <h2>Before you continue — a new safety feature</h2>
      <p>After the recent data issue, we've added automatic backups so this never costs you records again. This will only take a minute — please go through it once.</p>
    </div>

    <div class="dz-tr-step" data-step="2">
      <div class="dz-tr-icon"><i class="fas fa-download"></i></div>
      <h2>Step 1 — saving a backup</h2>
      <p>Every 2 hours, a fresh backup zip of <?php echo $__isStaff ? "this account's" : "your account's"; ?> data is prepared automatically.</p>
      <ol>
        <li>A pop-up like this will appear on its own: "Your account's safety backup is ready."</li>
        <li>Click <strong>"Download backup now"</strong> — it saves a <code>.zip</code> file straight to your device.</li>
        <li>Keep it somewhere you'll find again: a folder, email it to yourself, or Google Drive.</li>
      </ol>
    </div>

    <div class="dz-tr-step" data-step="3">
      <div class="dz-tr-icon"><i class="fas fa-rotate-left"></i></div>
      <h2>Step 2 — if anything ever goes wrong</h2>
      <?php if ($__isOwner): ?>
      <p>Only the main owner can restore a backup, from <strong>Settings → Export &amp; import</strong>:</p>
      <ol>
        <li>Unzip the backup file you downloaded — you'll find a <code>.sql</code> file inside.</li>
        <li>Open <strong>Settings → Export &amp; import</strong> and upload that <code>.sql</code> file under "Import a file."</li>
        <li>Check the preview of what it contains, tick the confirm box, then click <strong>Import</strong>.</li>
        <li>Your data from that backup is added back into your account.</li>
      </ol>
      <?php else: ?>
      <p>Only the shop owner can restore a backup. If anything ever looks wrong, tell the owner — they go to <strong>Settings → Export &amp; import</strong>, upload the <code>.sql</code> file from inside the downloaded zip, and import it back in.</p>
      <?php endif; ?>
    </div>

    <div class="dz-tr-step" data-step="4">
      <div class="dz-tr-icon"><i class="fas fa-circle-check"></i></div>
      <h2><?php echo $__isOwner ? 'One last thing — find the real menu' : "You're ready"; ?></h2>
      <?php if ($__isOwner): ?>
      <p>We've highlighted <strong>Settings → Export &amp; import</strong> on your menu — that's the real page you'll use to restore a backup. Click it now so you know exactly where it is (don't worry, nothing is imported just by opening it).</p>
      <?php else: ?>
      <p>That's everything — watch for the backup pop-up every couple of hours, and download it when it appears.</p>
      <?php endif; ?>
    </div>

    <div class="dz-tr-nav">
      <button type="button" class="dz-tr-btn" id="dzTourNext"><?php echo $__isOwner ? 'Next' : 'Next'; ?></button>
    </div>
    <div class="dz-tr-note">This only needs to be completed once.</div>
  </div>
</div>
<script>
(function () {
  var step = 1, total = 4;
  var isOwner = <?php echo $__isOwner ? 'true' : 'false'; ?>;
  var settingsUrl = <?php echo json_encode($__settingsUrl); ?>;
  var box = document.getElementById('dzTour');
  var veil = document.getElementById('dzTourVeil');
  var spot = null;

  function clearSpot() { if (spot) { spot.remove(); spot = null; } }

  function spotlight(el) {
    clearSpot();
    if (!el) return;
    var r = el.getBoundingClientRect();
    spot = document.createElement('div');
    spot.className = 'dz-spot';
    spot.style.left = (r.left - 6) + 'px';
    spot.style.top = (r.top - 6) + 'px';
    spot.style.width = (r.width + 12) + 'px';
    spot.style.height = (r.height + 12) + 'px';
    document.body.appendChild(spot);
  }

  function findSettingsLink() {
    var links = document.querySelectorAll('a[href*="super/backup/"]');
    return links.length ? links[0] : null;
  }

  function finish() {
    clearSpot();
    fetch('<?php echo public_url('components/tour_seen.php'); ?>', { method: 'POST' }).catch(function () {});
    box.remove(); veil.remove();
  }

  function render() {
    document.querySelectorAll('.dz-tr-step').forEach(function (el) { el.classList.toggle('on', +el.dataset.step === step); });
    document.querySelectorAll('.dz-tr-dots span').forEach(function (el) { el.classList.toggle('on', +el.dataset.dot <= step); });
    var btn = document.getElementById('dzTourNext');
    if (step === total) {
      if (isOwner) {
        var link = findSettingsLink();
        if (link) {
          spotlight(link);
          btn.textContent = 'Got it — finish';
          btn.onclick = function () { finish(); };
        } else {
          btn.textContent = "Got it, start using it";
          btn.onclick = function () { finish(); };
        }
      } else {
        btn.textContent = "Got it, start using it";
        btn.onclick = function () { finish(); };
      }
    } else {
      btn.textContent = 'Next';
      btn.onclick = function () { step++; render(); };
      clearSpot();
    }
  }

  // Clicking the real Settings → Export & import link while it's spotlighted also finishes the tour.
  document.addEventListener('click', function (e) {
    if (step !== total) return;
    var link = findSettingsLink();
    if (link && (e.target === link || link.contains(e.target))) finish();
  }, true);

  window.addEventListener('resize', function () { if (step === total) { var l = findSettingsLink(); if (l) spotlight(l); } });

  render();
})();
</script>
