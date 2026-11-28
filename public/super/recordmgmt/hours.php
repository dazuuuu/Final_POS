<?php
// public/super/recordmgmt/hours.php — business hours: when a shop runs
// round the clock (e.g. 7am to 7am), "today" should never reset at
// midnight — it resets at the hour the owner picks here instead.
require_once __DIR__ . '/_common.php';
rm_require($isOwner);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hour = (int) ($_POST['start_hour'] ?? 0);
    $RM->setDayStartHour($hour);
    $_SESSION['flash']['success'] = $hour === 0
        ? 'Business day set back to midnight–midnight.'
        : 'Business day now runs ' . sprintf('%02d:00', $hour) . ' to ' . sprintf('%02d:00', $hour) . ' the next day — "today" on every page follows this.';
    header('Location: ' . public_url('super/recordmgmt/hours.php'));
    exit;
}

$current = $RM->dayStartHour();

ob_start();
?>
<div class="card border-0 shadow-sm" style="border-radius:14px;max-width:560px;"><div class="card-body p-4">
  <h2 class="h6 fw-bold mb-2">Business hours</h2>
  <p class="small text-muted">If this shop runs round the clock across midnight (e.g. open 7am, closes 7am the next day), set the start hour below so "today" — on Operations, the Dashboard, Commissions and the Tracker — always means one full 24-hour shift, not a calendar day that resets at midnight mid-shift.</p>
  <form method="post">
    <label class="form-label small mb-1">Business day starts at</label>
    <select name="start_hour" class="form-select mb-3">
      <?php for ($h = 0; $h < 24; $h++): ?>
        <option value="<?php echo $h; ?>" <?php echo $h === $current ? 'selected' : ''; ?>><?php echo sprintf('%02d:00', $h); ?><?php echo $h === 0 ? ' (midnight — default)' : ''; ?></option>
      <?php endfor; ?>
    </select>
    <button class="btn btn-primary w-100">Save</button>
  </form>
  <?php if ($current !== 0): ?>
    <div class="alert alert-light border mt-3 small mb-0">Right now, "today" runs from <?php echo sprintf('%02d:00', $current); ?> to <?php echo sprintf('%02d:00', $current); ?> the next day.</div>
  <?php endif; ?>
</div></div>
<?php
$content = ob_get_clean();
rm_render('Business hours', $content);
