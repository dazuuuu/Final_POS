<?php
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::CUSTOMERS_MANAGE);

$pdo = Database::pdo();
$C = new Models\CustomerModel($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = $C->save($_POST);
    $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Customer saved.' : ($res['error'] ?? 'Could not save customer.');
    header('Location: ' . public_url('super/customers/'));
    exit;
}

$customers = $C->listWithBalances();
$totalOwed = array_sum(array_map(fn($c) => max(0, (float) $c['owed']), $customers));
$page_title = 'Customers';
ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-4">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3">Add customer</h2>
        <form method="post" class="row g-2">
          <div class="col-12"><label class="form-label small">Name</label><input name="name" class="form-control" required></div>
          <div class="col-12"><label class="form-label small">Company</label><input name="company_name" class="form-control"></div>
          <div class="col-12"><label class="form-label small">Email</label><input type="email" name="email" class="form-control"></div>
          <div class="col-12"><label class="form-label small">Phone</label><input name="phone" class="form-control"></div>
          <div class="col-12"><label class="form-label small">Location</label><input name="location" class="form-control"></div>
          <div class="col-12"><label class="form-label small">Notes</label><input name="notes" class="form-control"></div>
          <div class="col-12"><button class="btn btn-primary w-100">Save customer</button></div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-8">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2 class="h6 fw-bold mb-0">Loyal customers</h2>
          <a class="btn btn-sm btn-outline-primary" href="<?php echo public_url('super/documents/'); ?>">Create invoice</a>
        </div>
        <?php if (!$customers): ?>
          <div class="text-muted small">No customers saved yet. Customers are also saved automatically when you make a credit sale, or tick "Save as a loyal customer" at checkout.</div>
        <?php else: ?>
        <?php if ($totalOwed > 0): ?>
          <div class="alert alert-warning py-2 small">Customers owe you <strong>KES <?php echo number_format($totalOwed, 0); ?></strong> on credit. <a href="<?php echo public_url('super/payments/'); ?>">Collect payments</a></div>
        <?php endif; ?>
        <input type="search" class="form-control form-control-sm mb-2" id="custFilter" placeholder="Filter by name or phone…">
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0" id="custTable">
            <thead><tr class="text-muted small text-uppercase"><th>Name</th><th>Phone</th><th class="text-end">Visits</th><th class="text-end">Bought</th><th class="text-end">Owes</th><th>Last visit</th></tr></thead>
            <tbody>
              <?php foreach ($customers as $c): ?>
              <tr>
                <td class="fw-semibold small"><?php echo htmlspecialchars($c['name']); ?><?php if (!empty($c['company_name'])): ?><div class="text-muted fw-normal"><?php echo htmlspecialchars($c['company_name']); ?></div><?php endif; ?></td>
                <td class="small"><?php echo htmlspecialchars($c['phone'] ?? ''); ?><?php if (!empty($c['email'])): ?><div class="text-muted"><?php echo htmlspecialchars($c['email']); ?></div><?php endif; ?></td>
                <td class="small text-end"><?php echo (int) $c['visits']; ?></td>
                <td class="small text-end"><?php echo number_format((float) $c['total_bought'], 0); ?></td>
                <td class="small text-end <?php echo (float) $c['owed'] > 0 ? 'fw-bold text-danger' : 'text-muted'; ?>"><?php echo (float) $c['owed'] > 0 ? number_format((float) $c['owed'], 0) : '—'; ?></td>
                <td class="small text-muted"><?php echo $c['last_visit'] ? date('j M Y', strtotime($c['last_visit'])) : '—'; ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <script>
          document.getElementById('custFilter').addEventListener('input', function () {
            var q = this.value.toLowerCase();
            document.querySelectorAll('#custTable tbody tr').forEach(function (tr) { tr.style.display = tr.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none'; });
          });
        </script>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
