<?php
// public/super/recordmgmt/record.php — record a service an employee performed:
// pick the service, the amount paid, cash or M-Pesa (with change/txn code),
// and any products used (their cost is deducted before commission is worked
// out). Saves to Operations and credits the employee's running balance.
require_once __DIR__ . '/_common.php';
rm_require($canOperate);

$employeeId = (int) ($_GET['employee_id'] ?? $_POST['employee_id'] ?? 0);
$employee = $RM->employee($employeeId);
if (!$employee) {
    $_SESSION['flash']['error'] = 'Employee not found.';
    header('Location: ' . public_url('super/recordmgmt/'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $products = [];
    foreach ((array) ($_POST['product_id'] ?? []) as $i => $pid) {
        $pid = (int) $pid;
        $qty = (float) ($_POST['product_qty'][$i] ?? 0);
        if ($pid && $qty > 0) { $products[] = ['product_id' => $pid, 'quantity' => $qty]; }
    }
    $res = $RM->recordOperation($employeeId, $_POST, $products);
    if ($res['ok']) {
        $msg = 'Recorded. Commission: ' . rm_money($res['commission']);
        if ($res['change_due'] !== null) { $msg .= ' · Change due: ' . rm_money($res['change_due']); }
        $_SESSION['flash']['success'] = $msg;
        header('Location: ' . public_url('super/recordmgmt/'));
    } else {
        $_SESSION['flash']['error'] = $res['error'];
        header('Location: ' . public_url('super/recordmgmt/record.php?employee_id=' . $employeeId));
    }
    exit;
}

$services = $RM->services();
$products = $RM->products();

ob_start();
?>
<div class="row justify-content-center">
  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-1">Record service — <?php echo rm_h(rm_name($employee)); ?></h2>
      <div class="small text-muted mb-3"><?php echo rm_h($employee['role']); ?></div>
      <form method="post" id="opForm">
        <input type="hidden" name="employee_id" value="<?php echo $employeeId; ?>">
        <label class="form-label small">Service</label>
        <select name="service_id" class="form-select" required onchange="document.getElementById('amount').value = this.selectedOptions[0].dataset.amount || ''">
          <option value="">Choose…</option>
          <?php foreach ($services as $s): ?>
            <option value="<?php echo (int) $s['id']; ?>" data-amount="<?php echo $s['charge_type'] === 'fixed' ? rm_h($s['amount']) : ''; ?>"><?php echo rm_h($s['name']); ?></option>
          <?php endforeach; ?>
        </select>

        <label class="form-label small mt-2">Amount paid</label>
        <input type="number" step="0.01" id="amount" name="amount" class="form-control" required>

        <label class="form-label small mt-2">Payment mode</label>
        <div class="btn-group w-100" role="group">
          <input type="radio" class="btn-check" name="payment_mode" id="pm_cash" value="cash" checked onclick="document.getElementById('cashBox').style.display='block';document.getElementById('mpesaBox').style.display='none'">
          <label class="btn btn-outline-primary" for="pm_cash">Cash</label>
          <input type="radio" class="btn-check" name="payment_mode" id="pm_mpesa" value="mpesa" onclick="document.getElementById('cashBox').style.display='none';document.getElementById('mpesaBox').style.display='block'">
          <label class="btn btn-outline-primary" for="pm_mpesa">M-Pesa / Paybill</label>
        </div>

        <div id="cashBox" class="mt-2">
          <label class="form-label small">Cash given</label>
          <input type="number" step="0.01" name="cash_given" class="form-control">
        </div>
        <div id="mpesaBox" class="mt-2" style="display:none;">
          <div class="row g-2">
            <div class="col-6"><label class="form-label small">M-Pesa name</label><input name="mpesa_name" class="form-control"></div>
            <div class="col-6"><label class="form-label small">Transaction code</label><input name="mpesa_code" class="form-control"></div>
          </div>
        </div>

        <label class="form-label small mt-3">Products used (optional)</label>
        <div id="prodRows">
          <div class="row g-2 mb-2 prod-row">
            <div class="col-7"><select name="product_id[]" class="form-select"><option value="">None</option>
              <?php foreach ($products as $p): ?><option value="<?php echo (int) $p['id']; ?>"><?php echo rm_h($p['name']); ?> (<?php echo rm_money($p['cost']); ?>)</option><?php endforeach; ?>
            </select></div>
            <div class="col-5"><input type="number" step="0.01" name="product_qty[]" class="form-control" placeholder="Qty"></div>
          </div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('prodRows').insertAdjacentHTML('beforeend', document.querySelector('.prod-row').outerHTML)">+ Add another product</button>

        <button class="btn btn-primary w-100 mt-4">Save operation</button>
        <a href="<?php echo public_url('super/recordmgmt/'); ?>" class="btn btn-link w-100">Cancel</a>
      </form>
    </div></div>
  </div>
</div>
<?php
$content = ob_get_clean();
rm_render('Record service', $content);
