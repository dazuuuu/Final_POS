<?php
// public/super/electronics/repairs.php — a customer brings a device back.
// Look it up by serial / IMEI: under warranty → take it in for free repair;
// expired → take it in and charge a repair fee. The device is held until it's
// collected, and every return is counted against that serial.
require_once __DIR__ . '/../../../app/app.php';
define('ELEC_CAP', Capabilities::SALES_RECORD);
require_once __DIR__ . '/_common.php';

$selfUrl = public_url('super/electronics/repairs.php');
$serialQ = trim($_GET['serial'] ?? '');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'receive') {
        $res = $EL->receiveClaim((int) ($_POST['serial_id'] ?? 0), (string) ($_POST['issue'] ?? ''), (float) ($_POST['repair_fee'] ?? 0),
                                 !empty($_POST['not_covered']), $_POST['charge_reason'] ?? null);
        if ($res['ok']) {
            $_SESSION['flash']['success'] = $res['covered']
                ? 'Device taken in — covered by the warranty, no charge.'
                : 'Device taken in — not covered' . ($res['fee'] > 0 ? ', KES ' . number_format($res['fee'], 0) . ' to pay' : ' (enter the charge once it is known)') . ' when they collect it.';
            header('Location: ' . $selfUrl);
            exit;
        }
        $error = $res['error'];
        $serialQ = trim($_POST['serial'] ?? $serialQ);
    } elseif ($action === 'update') {
        $res = $EL->updateClaim((int) ($_POST['claim_id'] ?? 0), (string) ($_POST['status'] ?? ''), $_POST['resolution'] ?? null, $_POST['payment_method'] ?? null,
                                isset($_POST['repair_fee']) && $_POST['repair_fee'] !== '' ? (float) $_POST['repair_fee'] : null,
                                !empty($_POST['not_covered']), $_POST['charge_reason'] ?? null);
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Repair updated.' : $res['error'];
        header('Location: ' . $selfUrl);
        exit;
    }
}

$device = $serialQ !== '' ? $EL->lookup($serialQ) : null;
$openClaims = $EL->claims(true);
$doneClaims = $EL->claims(false, 30);
$income = $EL->repairIncome((int) TenantContext::tenantId());
$incomeMonth = $EL->repairIncome((int) TenantContext::tenantId(), date('Y-m-01'), date('Y-m-d'));
$money = fn($n) => 'KES ' . number_format((float) $n, 0);

ob_start();
?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo elec_h($error); ?></div><?php endif; ?>

<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;"><div class="card-body p-4">
  <h2 class="h6 fw-bold mb-3"><i class="fas fa-magnifying-glass me-1"></i>Check a device</h2>
  <form method="get" class="d-flex gap-2">
    <input name="serial" value="<?php echo elec_h($serialQ); ?>" class="form-control form-control-lg text-uppercase" placeholder="Scan or type the serial / IMEI" autofocus autocomplete="off">
    <button class="btn btn-primary btn-lg px-4">Check</button>
  </form>

  <?php if ($serialQ !== '' && !$device): ?>
    <div class="alert alert-warning mt-3 mb-0">No device with serial <strong><?php echo elec_h(Models\ElectronicsModel::clean($serialQ)); ?></strong> in this business. Check the number on the device (dial *#06# for a phone's IMEI).</div>
  <?php elseif ($device): $under = $device['under_warranty']; ?>
    <div class="mt-3 p-3 rounded" style="background:<?php echo $under ? '#f0fdf4' : '#fef2f2'; ?>;border:1px solid <?php echo $under ? '#bbf0d1' : '#fecaca'; ?>;">
      <div class="d-flex justify-content-between flex-wrap gap-2">
        <div>
          <div class="fw-bold fs-5"><?php echo elec_h($device['product_name'] ?? 'Unknown product'); ?><?php if (!empty($device['color'])): ?> <span class="fw-normal fs-6 text-muted">· <?php echo elec_h($device['color']); ?></span><?php endif; ?></div>
          <div class="font-monospace small"><?php echo elec_h($device['serial']); ?><?php echo $device['serial2'] ? ' · ' . elec_h($device['serial2']) : ''; ?></div>
        </div>
        <div class="text-end">
          <?php if ($device['status'] === 'sold' || $device['status'] === 'in_repair'): ?>
            <span class="badge fs-6 <?php echo $under ? 'bg-success' : 'bg-danger'; ?>"><?php echo $under ? 'Under warranty' : ($device['warranty_ends_at'] ? 'Warranty expired' : 'No warranty'); ?></span>
          <?php endif; ?>
          <div class="mt-1"><?php echo elec_status_badge($device['status']); ?></div>
        </div>
      </div>
      <div class="row g-2 small mt-2">
        <div class="col-6 col-md-3"><div class="text-muted">Sold</div><?php echo $device['sold_at'] ? date('j M Y', strtotime($device['sold_at'])) : '—'; ?><?php echo $device['receipt_number'] ? '<div class="text-muted">' . elec_h($device['receipt_number']) . '</div>' : ''; ?></div>
        <div class="col-6 col-md-3"><div class="text-muted">Customer</div><?php echo elec_h($device['customer_name'] ?: '—'); ?><?php echo $device['customer_phone'] ? '<div>' . elec_h($device['customer_phone']) . '</div>' : ''; ?></div>
        <div class="col-6 col-md-3"><div class="text-muted">Warranty</div><?php echo elec_h(Models\ElectronicsModel::warrantyLabel((int) $device['warranty_months'])); ?><?php echo $device['warranty_ends_at'] ? '<div>until ' . date('j M Y', strtotime($device['warranty_ends_at'])) . '</div>' : ''; ?></div>
        <div class="col-6 col-md-3"><div class="text-muted">Times returned</div><span class="fw-bold fs-5"><?php echo (int) $device['return_count']; ?></span></div>
      </div>
    </div>

    <?php if ($device['status'] === 'sold'): ?>
      <form method="post" class="mt-3" id="receiveForm">
        <input type="hidden" name="action" value="receive">
        <input type="hidden" name="serial_id" value="<?php echo (int) $device['id']; ?>">
        <input type="hidden" name="serial" value="<?php echo elec_h($device['serial']); ?>">
        <label class="form-label small fw-semibold">What's wrong with it?</label>
        <textarea name="issue" class="form-control mb-2" rows="2" required placeholder="e.g. screen not responding, won't charge"><?php echo elec_h($_POST['issue'] ?? ''); ?></textarea>
        <?php if ($under): ?>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="not_covered" value="1" id="notCovered" <?php echo !empty($_POST['not_covered']) ? 'checked' : ''; ?>>
            <label class="form-check-label small" for="notCovered"><strong>Issue not covered by the warranty</strong> — e.g. water damage, cracked screen, misuse. The customer pays for this repair.</label>
          </div>
        <?php endif; ?>
        <div id="chargeBox" <?php echo $under && empty($_POST['not_covered']) ? 'style="display:none;"' : ''; ?>>
          <?php if ($under): ?>
            <input name="charge_reason" class="form-control mb-2" maxlength="255" placeholder="Why it isn't covered (e.g. water damage)" value="<?php echo elec_h($_POST['charge_reason'] ?? ''); ?>">
          <?php endif; ?>
          <label class="form-label small fw-semibold">Repair charge <span class="text-muted fw-normal">(leave blank if you'll quote after checking it)</span></label>
          <input type="number" step="1" min="0" name="repair_fee" class="form-control mb-1" style="max-width:220px;" placeholder="KES" value="<?php echo elec_h($_POST['repair_fee'] ?? ''); ?>">
          <div class="form-text mb-2"><?php echo $under ? 'Not covered' : 'Out of warranty'; ?> — paid when the customer collects the device.</div>
        </div>
        <div id="freeNote" class="form-text mb-2 text-success" <?php echo $under && empty($_POST['not_covered']) ? '' : 'style="display:none;"'; ?>><i class="fas fa-shield-halved me-1"></i>Covered by the warranty — repaired at no charge.</div>
        <button class="btn btn-success"><i class="fas fa-hand-holding me-1"></i>Take in for repair</button>
      </form>
    <?php elseif ($device['status'] === 'in_repair'): ?>
      <div class="alert alert-info mt-3 mb-0">This device is already with you for repair — see <strong>In for repair</strong> below.</div>
    <?php else: ?>
      <div class="alert alert-secondary mt-3 mb-0">This unit hasn't been sold — it's <?php echo $device['status'] === 'warehouse' ? 'in the warehouse' : 'in stock' . ($device['shop_name'] ? ' at ' . elec_h($device['shop_name']) : ''); ?>.</div>
    <?php endif; ?>

    <?php if ($device['claims']): ?>
      <h3 class="h6 fw-bold mt-4 mb-2">Return history</h3>
      <?php foreach ($device['claims'] as $c): ?>
        <div class="border-bottom py-2 small">
          <div class="d-flex justify-content-between"><strong><?php echo date('j M Y', strtotime($c['received_at'])); ?></strong>
            <span><?php echo $c['covered'] ? '<span class="text-success">warranty</span>' : ($c['under_warranty'] ? 'not covered · ' : '') . $money($c['repair_fee']); ?> · <?php echo Models\ElectronicsModel::CLAIM_STATUSES[$c['status']]; ?></span></div>
          <?php if ($c['charge_reason']): ?><div class="text-danger">Not covered: <?php echo elec_h($c['charge_reason']); ?></div><?php endif; ?>
          <div><?php echo elec_h($c['issue']); ?></div>
          <?php if ($c['resolution']): ?><div class="text-muted">Fix: <?php echo elec_h($c['resolution']); ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>
</div></div>

<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;"><div class="card-body p-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h2 class="h6 fw-bold mb-0">In for repair <span class="badge bg-warning text-dark"><?php echo count($openClaims); ?></span></h2>
    <div class="small text-muted">Repair earnings: <strong class="text-success"><?php echo $money($incomeMonth['total']); ?></strong> this month · <?php echo $money($income['total']); ?> all time</div>
  </div>
  <?php if (!$openClaims): ?>
    <div class="text-muted small">No devices in for repair.</div>
  <?php endif; ?>
  <?php foreach ($openClaims as $c): $fee = (float) $c['repair_fee']; ?>
    <div class="border rounded p-3 mb-2">
      <div class="d-flex justify-content-between flex-wrap gap-2">
        <div>
          <strong><?php echo elec_h($c['product_name'] ?? 'Device'); ?></strong>
          <span class="font-monospace small text-muted"><?php echo elec_h($c['serial']); ?></span>
          <div class="small"><?php echo elec_h($c['customer_name'] ?: '—'); ?><?php echo $c['customer_phone'] ? ' · ' . elec_h($c['customer_phone']) : ''; ?> · in since <?php echo date('j M', strtotime($c['received_at'])); ?> · returned <?php echo (int) $c['return_count']; ?>×</div>
          <div class="small text-muted"><?php echo elec_h($c['issue']); ?></div>
        </div>
        <div class="text-end small">
          <?php if ($c['covered']): ?><span class="badge bg-success">Warranty — free</span>
          <?php else: ?><span class="badge bg-danger"><?php echo $fee > 0 ? 'To pay ' . $money($fee) : 'Charge not set'; ?></span>
            <div class="text-muted mt-1"><?php echo $c['under_warranty'] ? 'Not covered' . ($c['charge_reason'] ? ': ' . elec_h($c['charge_reason']) : '') : 'Out of warranty'; ?></div>
          <?php endif; ?>
          <div class="mt-1"><?php echo Models\ElectronicsModel::CLAIM_STATUSES[$c['status']]; ?></div>
        </div>
      </div>
      <form method="post" class="row g-2 mt-1 align-items-end claim-form">
        <input type="hidden" name="action" value="update"><input type="hidden" name="claim_id" value="<?php echo (int) $c['id']; ?>">
        <div class="col-6 col-md-3">
          <select name="status" class="form-select form-select-sm claim-status" data-fee="<?php echo $fee; ?>">
            <?php foreach (Models\ElectronicsModel::CLAIM_STATUSES as $k => $label): ?>
              <option value="<?php echo $k; ?>" <?php echo $c['status'] === $k ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-4"><input name="resolution" class="form-control form-control-sm" placeholder="What was fixed (optional)" value="<?php echo elec_h($c['resolution'] ?? ''); ?>"></div>
        <?php if (!$c['covered']): ?>
        <div class="col-6 col-md-2"><input type="number" step="1" min="0" name="repair_fee" class="form-control form-control-sm" placeholder="Charge KES" value="<?php echo $fee > 0 ? (int) $fee : ''; ?>" title="Repair charge"></div>
        <div class="col-6 col-md-2 claim-pay" style="display:none;">
          <select name="payment_method" class="form-select form-select-sm"><option value="">Paid by…</option><option value="cash">Cash</option><option value="mpesa">M-Pesa</option></select>
        </div>
        <?php endif; ?>
        <div class="col-6 col-md-2"><button class="btn btn-sm btn-outline-success w-100">Update</button></div>
        <?php if ($c['covered']): ?>
        <div class="col-12">
          <a href="#" class="small text-danger nc-toggle">Found an issue the warranty doesn't cover?</a>
          <div class="row g-2 mt-1 nc-box" style="display:none;">
            <input type="hidden" name="not_covered" value="" class="nc-flag">
            <div class="col-7 col-md-6"><input name="charge_reason" class="form-control form-control-sm" placeholder="Why it isn't covered (e.g. water damage)"></div>
            <div class="col-5 col-md-3"><input type="number" step="1" min="0" name="repair_fee" class="form-control form-control-sm" placeholder="Charge KES" disabled></div>
          </div>
        </div>
        <?php endif; ?>
      </form>
    </div>
  <?php endforeach; ?>
</div></div>

<?php if ($doneClaims): ?>
<div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
  <h2 class="h6 fw-bold mb-3">Recently collected</h2>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr class="small text-muted text-uppercase"><th>Collected</th><th>Device</th><th>Issue</th><th class="text-end">Charged</th></tr></thead>
      <tbody>
      <?php foreach ($doneClaims as $c): ?>
        <tr class="small">
          <td><?php echo $c['collected_at'] ? date('j M Y', strtotime($c['collected_at'])) : '—'; ?></td>
          <td><?php echo elec_h($c['product_name'] ?? ''); ?><div class="font-monospace text-muted"><?php echo elec_h($c['serial']); ?></div></td>
          <td><?php echo elec_h($c['issue']); ?><?php echo $c['resolution'] ? '<div class="text-muted">' . elec_h($c['resolution']) . '</div>' : ''; ?></td>
          <td class="text-end"><?php echo $c['covered'] ? 'Warranty' : $money($c['repair_fee']) . ' <span class="text-muted">' . ($c['payment_method'] === 'mpesa' ? 'M-Pesa' : 'cash') . '</span>' . ($c['under_warranty'] ? '<div class="text-muted">not covered</div>' : ''); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div></div>
<?php endif; ?>

<script>
var nc = document.getElementById('notCovered');
if (nc) { nc.addEventListener('change', function () {
  document.getElementById('chargeBox').style.display = nc.checked ? '' : 'none';
  document.getElementById('freeNote').style.display = nc.checked ? 'none' : '';
}); }
document.querySelectorAll('.nc-toggle').forEach(function (a) {
  a.addEventListener('click', function (e) {
    e.preventDefault();
    var box = a.parentNode.querySelector('.nc-box'), on = box.style.display === 'none';
    box.style.display = on ? '' : 'none';
    box.querySelector('.nc-flag').value = on ? '1' : '';
    box.querySelector('input[name=repair_fee]').disabled = !on;
    var form = a.closest('.claim-form'), pay = form.querySelector('.claim-pay');
    if (!pay && on) {
      pay = document.createElement('div'); pay.className = 'col-6 col-md-2 claim-pay';
      pay.innerHTML = '<select name="payment_method" class="form-select form-select-sm"><option value="">Paid by…</option><option value="cash">Cash</option><option value="mpesa">M-Pesa</option></select>';
      box.appendChild(pay);
    }
    form.querySelector('.claim-status').dispatchEvent(new Event('change'));
  });
});
document.querySelectorAll('.claim-status').forEach(function (sel) {
  function sync() { sel.closest('.claim-form').querySelectorAll('.claim-pay').forEach(function (pay) { pay.style.display = sel.value === 'collected' ? '' : 'none'; }); }
  sel.addEventListener('change', sync); sync();
});
</script>
<?php
elec_render('Warranty & repairs', ob_get_clean());
