<?php
// public/super/timezone/index.php — the account's time zone. Asked once (the owner is sent here until
// it is confirmed); can be changed here at any time. Sales days, opening / closing sales and the
// morning summary all follow it.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::auth();

$isOwner = TenantContext::isPrimaryOwner();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
$next = (preg_match('#^/[^/\\\\]#', $next) && strpos($next, '//') === false) ? $next : public_url('super/dashboard/');
$info = AccountTime::info();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOwner) {
    if (AccountTime::save((int) TenantContext::tenantId(), (string) ($_POST['zone'] ?? ''))) {
        $_SESSION['flash']['success'] = 'Time zone saved — your sales days now follow it.';
        unset($_SESSION['day_until']); // today's opening figure is worked out again in the new zone
        header('Location: ' . $next);
        exit;
    }
    $_SESSION['flash']['error'] = 'Choose a time zone from the list.';
}

$page_title = 'Time zone';
ob_start();
?>
<div class="card border-0 shadow-sm mx-auto" style="max-width:620px;border-radius:14px;">
  <div class="card-body p-4">
    <h1 class="h5 fw-bold mb-1"><i class="fas fa-globe me-2 text-primary"></i>Your time zone</h1>
    <p class="text-muted small">Where is this business? Every sales day (12:00 am – 11:59 pm), the opening and closing sales and the morning summary are worked out in this zone. Pick it once; you can change it later.</p>
    <?php foreach (['success' => 'success', 'error' => 'danger'] as $k => $cls): if (!empty($_SESSION['flash'][$k])): ?><div class="alert alert-<?php echo $cls; ?>"><?php echo $h($_SESSION['flash'][$k]); unset($_SESSION['flash'][$k]); ?></div><?php endif; endforeach; ?>
    <?php if (!$isOwner): ?>
      <div class="alert alert-info mb-0">The time zone is <strong><?php echo $h($info['zone']); ?></strong>. Only the business owner can change it.</div>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="next" value="<?php echo $h($next); ?>">
      <div class="small mb-2" id="tzGuess" style="display:none">Your device says: <strong id="tzGuessName"></strong> <a href="#" id="tzUse">use it</a></div>
      <select name="zone" id="tzSel" class="form-select form-select-lg mb-3" required>
        <?php if (!$info['set']): ?><option value="">— choose your time zone —</option><?php endif; ?>
        <?php foreach (AccountTime::options() as $region => $zones): ?>
          <optgroup label="<?php echo $h($region); ?>">
          <?php foreach ($zones as $id => $label): ?><option value="<?php echo $h($id); ?>" <?php echo $info['set'] && $info['zone'] === $id ? 'selected' : ''; ?>><?php echo $h($label); ?></option><?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-primary btn-lg w-100"><i class="fas fa-check me-1"></i>Save time zone</button>
      <?php if ($info['set']): ?><div class="form-text mt-2">Sales already recorded keep the clock time they were saved with, so changing the zone later can shift old days by the difference.</div><?php endif; ?>
    </form>
    <script>
    (function () {
      var g; try { g = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (e) {}
      var sel = document.getElementById('tzSel');
      if (!g || !sel || !sel.querySelector('option[value="' + g + '"]')) return;
      document.getElementById('tzGuessName').textContent = g.replace(/_/g, ' ');
      document.getElementById('tzGuess').style.display = 'block';
      if (!sel.value) sel.value = g;
      document.getElementById('tzUse').addEventListener('click', function (e) { e.preventDefault(); sel.value = g; });
    })();
    </script>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
$isStaff = TenantContext::role() === 'staff';
include __DIR__ . '/../../templates/' . ($isStaff ? 'staff' : 'tenants') . '/layout.php';
