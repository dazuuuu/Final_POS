<?php
// public/super/recordmgmt/payments.php — pay an employee against what they're
// owed. Supports a partial payment now (the rest stays owed) and shows
// payout history.
require_once __DIR__ . '/_common.php';
rm_require($canPayout);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = $RM->payEmployee((int) ($_POST['employee_id'] ?? 0), (float) ($_POST['amount'] ?? 0), (string) ($_POST['method'] ?? 'cash'), (string) ($_POST['note'] ?? ''));
    $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Payment recorded.' : $res['error'];
    header('Location: ' . public_url('super/recordmgmt/payments.php'));
    exit;
}

$owed = array_filter($RM->employees(), fn($e) => (float) $e['owed'] > 0);
$history = $RM->payments();

ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-6">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Owed to employees</h2>
      <?php if (!$owed): ?><div class="text-muted small py-3 text-center">Nobody is owed anything right now.</div><?php endif; ?>
      <?php foreach ($owed as $e): ?>
        <form method="post" class="border rounded-3 p-3 mb-2" onsubmit="return confirm('Pay <?php echo rm_h(rm_name($e)); ?>?');">
          <input type="hidden" name="employee_id" value="<?php echo (int) $e['id']; ?>">
          <div class="d-flex justify-content-between align-items-center">
            <div><strong><?php echo rm_h(rm_name($e)); ?></strong><div class="small text-muted"><?php echo rm_h($e['role']); ?></div></div>
            <div class="fw-bold text-success"><?php echo rm_money($e['owed']); ?></div>
          </div>
          <div class="row g-2 mt-1">
            <div class="col-5"><input type="number" step="0.01" name="amount" class="form-control form-control-sm" placeholder="Amount" value="<?php echo rm_h($e['owed']); ?>" max="<?php echo rm_h($e['owed']); ?>" required></div>
            <div class="col-4">
              <select name="method" class="form-select form-select-sm">
                <option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="bank">Bank</option>
              </select>
            </div>
            <div class="col-3"><button class="btn btn-sm btn-primary w-100">Pay</button></div>
          </div>
          <input name="note" class="form-control form-control-sm mt-1" placeholder="Note (optional)">
        </form>
      <?php endforeach; ?>
    </div></div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Payment history</h2>
      <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Date</th><th>Employee</th><th>Amount</th><th>Method</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td><?php echo date('j M, H:i', strtotime($h['created_at'])); ?></td>
            <td><?php echo rm_h($h['first_name'] . ' ' . $h['last_name']); ?></td>
            <td><?php echo rm_money($h['amount']); ?></td>
            <td><?php echo rm_h(ucfirst($h['method'])); ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$history): ?><tr><td colspan="4" class="text-center text-muted py-3">No payments yet.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div></div>
  </div>
</div>
<?php
$content = ob_get_clean();
rm_render('Pay employees', $content);
