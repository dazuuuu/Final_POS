<?php
// public/super/services/mine.php — "My clients": the services assigned to the
// signed-in person, waiting first. They mark each one done when finished; the
// cashier takes the payment. Amounts and commission show only to people the
// owner allows to see money.
require_once __DIR__ . '/_common.php';

$me = (int) TenantContext::userId();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'done') {
    $res = $SD->markDone((int) ($_POST['item_id'] ?? 0), $me, false);
    $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Marked done — the front desk / cashier will take it from here.' : $res['error'];
    header('Location: ' . public_url('super/services/mine.php'));
    exit;
}

$items = $SD->assignedTo($me);
$waiting = array_values(array_filter($items, fn($i) => $i['status'] === 'pending'));
$done = array_slice(array_values(array_filter($items, fn($i) => $i['status'] === 'done')), 0, 30);
$showMoney = $canMoney;
$week = $showMoney ? $SD->myEarnings($me, date('Y-m-d', strtotime('monday this week')), date('Y-m-d')) : null;
$month = $showMoney ? $SD->myEarnings($me, date('Y-m-01'), date('Y-m-d')) : null;
// What they're owed since their last payout — always visible to them; it
// goes back to zero when the owner (or whoever pays staff) pays them.
$owed = $SD->unpaidFor($me);
$lastPaid = $SD->payouts($me, 1)[0] ?? null;

ob_start();
?>
<div class="card border-0 shadow-sm mb-3" style="border-radius:14px;background:linear-gradient(135deg,#ecfdf5,#fff);"><div class="card-body p-3 p-md-4 d-flex flex-wrap align-items-center gap-3">
  <div class="flex-grow-1">
    <div class="small text-muted text-uppercase fw-semibold">Commission owed to me</div>
    <div class="fw-bold text-success" style="font-size:1.9rem;line-height:1.1"><?php echo svc_money($owed['amount']); ?></div>
    <div class="small text-muted"><?php echo $owed['count']; ?> paid service<?php echo $owed['count'] === 1 ? '' : 's'; ?> since <?php echo $lastPaid ? 'you were last paid (' . svc_money($lastPaid['amount']) . ' on ' . date('j M', strtotime($lastPaid['created_at'])) . ')' : 'you started'; ?>. Resets to zero when you're paid.</div>
  </div>
  <a href="<?php echo public_url('super/services/my-job.php'); ?>" class="btn btn-success"><i class="fas fa-plus me-1"></i>Record a job I did</a>
</div></div>

<?php if ($showMoney): ?>
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="tk-stat"><div class="tk-stat-icon tk-greenbg"><i class="fas fa-coins"></i></div><div class="tk-stat-body"><div class="tk-stat-num"><?php echo svc_money($week['commission']); ?></div><div class="tk-stat-label">My commission this week</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="tk-stat"><div class="tk-stat-icon tk-blue"><i class="fas fa-calendar"></i></div><div class="tk-stat-body"><div class="tk-stat-num"><?php echo svc_money($month['commission']); ?></div><div class="tk-stat-label">This month (<?php echo $month['count']; ?> paid)</div></div></div></div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3" style="border-radius:14px;"><div class="card-body p-3 p-md-4">
  <h2 class="h6 fw-bold mb-3"><i class="fas fa-user-clock me-1 text-warning"></i>Waiting for me <span class="badge bg-warning text-dark"><?php echo count($waiting); ?></span></h2>
  <?php if (!$waiting): ?>
    <div class="text-muted small py-3 text-center">No clients assigned to you right now.</div>
  <?php endif; ?>
  <?php foreach ($waiting as $i): ?>
    <div class="svc-job">
      <div class="min-w-0">
        <div class="fw-bold"><?php echo svc_h($i['customer_name']); ?> <span class="small text-muted fw-normal"><?php echo svc_h($i['number']); ?></span></div>
        <div><?php echo svc_h($i['service_name']); ?><?php echo $showMoney ? ' · ' . svc_money($i['price']) : ''; ?></div>
        <div class="small text-muted">
          <?php echo $i['scheduled_at'] ? '<i class="fas fa-calendar me-1"></i>' . date('D j M, g:i a', strtotime($i['scheduled_at'])) . ($i['ticket_status'] === 'booked' ? ' (booked)' : '') : 'Walk-in · ' . date('g:i a', strtotime($i['ticket_created'])); ?>
          <?php echo $i['customer_phone'] ? ' · <i class="fas fa-phone ms-1 me-1"></i>' . svc_h($i['customer_phone']) : ''; ?>
        </div>
        <?php if ($i['notes']): ?><div class="small"><i class="fas fa-note-sticky me-1 text-warning"></i><?php echo svc_h($i['notes']); ?></div><?php endif; ?>
      </div>
      <form method="post" onsubmit="return confirm('Mark <?php echo svc_h($i['service_name']); ?> for <?php echo svc_h($i['customer_name']); ?> as done?');">
        <input type="hidden" name="action" value="done"><input type="hidden" name="item_id" value="<?php echo (int) $i['id']; ?>">
        <button class="btn btn-success"><i class="fas fa-check me-1"></i>Done</button>
      </form>
    </div>
  <?php endforeach; ?>
</div></div>

<?php if ($done): ?>
<div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-3 p-md-4">
  <h2 class="h6 fw-bold mb-3">Recently done</h2>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr class="small text-muted text-uppercase"><th>Client</th><th>Service</th><th>Done</th><th>Payment</th><?php if ($showMoney): ?><th class="text-end">My commission</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($done as $i): ?>
        <tr class="small">
          <td><?php echo svc_h($i['customer_name']); ?></td>
          <td><?php echo svc_h($i['service_name']); ?></td>
          <td><?php echo $i['done_at'] ? date('j M, g:i a', strtotime($i['done_at'])) : ''; ?></td>
          <td><?php echo $i['ticket_status'] === 'paid' ? '<span class="badge bg-success">Paid</span>' : '<span class="badge bg-light text-dark border">Not paid yet</span>'; ?></td>
          <?php if ($showMoney): ?><td class="text-end"><?php echo $i['commission_amount'] !== null ? svc_money($i['commission_amount']) : '—'; ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div></div>
<?php endif; ?>

<style>
.svc-job{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px;border:1px solid #eef0f4;border-radius:12px;margin-bottom:10px;}
</style>
<?php
svc_render('My clients', ob_get_clean());
