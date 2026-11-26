<?php
// public/super/recordmgmt/services.php — the services the business offers:
// name, how they charge (fixed amount or percentage), optional description.
require_once __DIR__ . '/_common.php';
rm_require($canManage);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $res = $RM->saveService($_POST, (int) ($_POST['id'] ?? 0) ?: null);
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Service saved.' : $res['error'];
    } elseif ($action === 'deactivate') {
        $RM->setServiceActive((int) ($_POST['id'] ?? 0), false);
        $_SESSION['flash']['success'] = 'Service removed.';
    } elseif ($action === 'activate') {
        $RM->setServiceActive((int) ($_POST['id'] ?? 0), true);
        $_SESSION['flash']['success'] = 'Service restored.';
    }
    header('Location: ' . public_url('super/recordmgmt/services.php'));
    exit;
}

$services = $RM->services(false);

ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Add service</h2>
      <form method="post">
        <input type="hidden" name="action" value="save">
        <label class="form-label small">Service name</label>
        <input name="name" class="form-control" required>
        <div class="row g-2 mt-1">
          <div class="col-6">
            <label class="form-label small">Charge type</label>
            <select name="charge_type" class="form-select">
              <option value="fixed">Fixed amount</option>
              <option value="percent">Percentage</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label small">Amount</label>
            <input type="number" step="0.01" name="amount" class="form-control" required>
          </div>
        </div>
        <label class="form-label small mt-2">Description (optional)</label>
        <textarea name="description" class="form-control" rows="2"></textarea>
        <button class="btn btn-primary w-100 mt-3">Save service</button>
      </form>
    </div></div>
  </div>
  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Services (<?php echo count($services); ?>)</h2>
      <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Name</th><th>Charge</th><th>Description</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($services as $s): ?>
          <tr class="<?php echo $s['active'] ? '' : 'text-muted'; ?>">
            <td><?php echo rm_h($s['name']); ?></td>
            <td><?php echo $s['charge_type'] === 'percent' ? rm_h($s['amount']) . '%' : rm_money($s['amount']); ?></td>
            <td class="small text-muted"><?php echo rm_h($s['description']); ?></td>
            <td class="text-end">
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="<?php echo $s['active'] ? 'deactivate' : 'activate'; ?>">
                <input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                <button class="btn btn-sm btn-link text-decoration-none"><?php echo $s['active'] ? 'Remove' : 'Restore'; ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$services): ?><tr><td colspan="4" class="text-center text-muted py-3">No services yet.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div></div>
  </div>
</div>
<?php
$content = ob_get_clean();
rm_render('Services', $content);
