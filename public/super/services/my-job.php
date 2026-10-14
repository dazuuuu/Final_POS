<?php
// public/super/services/my-job.php — a staff member records a service they
// did and were paid for: what it was (from the price list or typed), the
// client, how much they charged and how it was paid. They tick whether it
// earns their usual commission or the whole amount is theirs. It counts
// towards "Commission owed to me" until they're paid out.
require_once __DIR__ . '/_common.php';

$me = (int) TenantContext::userId();
$self = public_url('super/services/my-job.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = $_POST;
    $in['keeps_all'] = ($_POST['share'] ?? 'commission') === 'all';
    $res = $SD->recordOwnJob($me, $in);
    $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Job ' . $res['number'] . ' recorded — it\'s added to your commission owed.' : $res['error'];
    header('Location: ' . $self);
    exit;
}

$services = $SD->servicesForShop();
$jobs = $SD->ownJobs($me);
$owed = $SD->unpaidFor($me);

ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-1">Record a job I did</h2>
      <p class="small text-muted mb-3">For work you did and got paid for. It's added to your commission owed: <strong class="text-success"><?php echo svc_money($owed['amount']); ?></strong>.</p>
      <form method="post" id="jobForm">
        <label class="form-label small mb-1">Service</label>
        <select name="service_id" id="svcPick" class="form-select mb-2">
          <option value="0" data-price="">Something else (type it below)</option>
          <?php foreach ($services as $s): ?>
            <option value="<?php echo (int) $s['id']; ?>" data-price="<?php echo (float) $s['price']; ?>"><?php echo svc_h($s['name']); ?> · <?php echo svc_money($s['price']); ?></option>
          <?php endforeach; ?>
        </select>
        <input name="service_name" id="svcName" class="form-control mb-2" placeholder="What did you do? e.g. Braids + wash" maxlength="150">
        <label class="form-label small mb-1">Amount charged</label>
        <div class="input-group mb-2"><span class="input-group-text">KES</span><input type="number" name="amount" id="svcAmount" class="form-control" min="1" step="0.01" required></div>
        <div class="row g-2 mb-2">
          <div class="col-7"><label class="form-label small mb-1">Client name <span class="text-muted">(optional)</span></label><input name="customer_name" class="form-control" maxlength="150"></div>
          <div class="col-5"><label class="form-label small mb-1">Phone</label><input name="customer_phone" class="form-control" inputmode="tel"></div>
        </div>
        <label class="form-label small mb-1">Client paid by</label>
        <div class="btn-group w-100 mb-3" role="group">
          <input type="radio" class="btn-check" name="method" id="mCash" value="cash" checked><label class="btn btn-outline-secondary" for="mCash">Cash</label>
          <input type="radio" class="btn-check" name="method" id="mMpesa" value="mpesa"><label class="btn btn-outline-secondary" for="mMpesa">M-Pesa</label>
        </div>
        <label class="form-label small mb-1">My share</label>
        <label class="d-flex gap-2 border rounded p-2 mb-2"><input class="form-check-input mt-1" type="radio" name="share" value="commission" checked><span><strong class="d-block small">Commissioned</strong><span class="small text-muted">I get my usual commission % of this job.</span></span></label>
        <label class="d-flex gap-2 border rounded p-2 mb-3"><input class="form-check-input mt-1" type="radio" name="share" value="all"><span><strong class="d-block small">All the money is mine</strong><span class="small text-muted">The whole amount is owed to me.</span></span></label>
        <input name="notes" class="form-control mb-3" placeholder="Note (optional)" maxlength="255">
        <button class="btn btn-success w-100"><i class="fas fa-check me-1"></i>Save job</button>
      </form>
    </div></div>
  </div>
  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Jobs I recorded</h2>
      <?php if (!$jobs): ?><div class="text-muted small">Nothing recorded yet.</div><?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr class="small text-muted text-uppercase"><th>Job</th><th class="text-end">Charged</th><th class="text-end">Mine</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $j): ?>
          <tr class="small">
            <td><strong><?php echo svc_h($j['service_name']); ?></strong><div class="text-muted"><?php echo svc_h($j['customer_name']); ?> · <?php echo date('j M, g:i a', strtotime($j['paid_at'])); ?> · <?php echo $j['payment_method'] === 'mpesa' ? 'M-Pesa' : 'Cash'; ?></div></td>
            <td class="text-end"><?php echo svc_money($j['price']); ?></td>
            <td class="text-end"><?php echo $j['commission_amount'] !== null ? svc_money($j['commission_amount']) : '<span class="text-muted">no rate</span>'; ?><div class="text-muted"><?php echo $j['staff_keeps_all'] ? 'all mine' : 'commission'; ?></div></td>
            <td><?php echo $j['payout_id'] ? '<span class="badge bg-success">Paid</span>' : '<span class="badge bg-warning text-dark">Owed</span>'; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<script>
(function () {
  var pick = document.getElementById('svcPick'), name = document.getElementById('svcName'), amt = document.getElementById('svcAmount');
  pick.addEventListener('change', function () {
    var o = pick.options[pick.selectedIndex];
    name.style.display = pick.value === '0' ? '' : 'none';
    name.required = pick.value === '0';
    if (o.dataset.price) amt.value = o.dataset.price; // usual price; they can change it
  });
  name.required = true;
})();
</script>
<?php
svc_render('Record my job', ob_get_clean());
