<?php
// public/super/recordmgmt/expenses.php — expense categories and expenses
// under them, with proof (a receipt/M-Pesa message photo, or a text note).
// Shows daily totals and a by-category breakdown so high spend stands out.
require_once __DIR__ . '/_common.php';
rm_require($canExpenses);
require_once APP_ROOT . '/helpers/uploads.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_category') {
        $res = $RM->addExpenseCategory((string) ($_POST['name'] ?? ''));
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Category added.' : $res['error'];
    } elseif ($action === 'add_expense') {
        $in = $_POST;
        if (!empty($_FILES['proof']['name'])) {
            $up = upload_image($_FILES['proof'], PUBLIC_ROOT . '/assets/uploads/rm-expenses/' . (int) TenantContext::tenantId(),
                               'assets/uploads/rm-expenses/' . (int) TenantContext::tenantId());
            if ($up['ok']) { $in['proof_path'] = $up['path']; }
        }
        $res = $RM->addExpense($in);
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Expense recorded.' : $res['error'];
    }
    header('Location: ' . public_url('super/recordmgmt/expenses.php'));
    exit;
}

$categories = $RM->expenseCategories();
$range = $_GET['range'] ?? 'today';
$range = in_array($range, ['today', 'yesterday', 'week', 'month'], true) ? $range : 'today';
$to = $RM->businessDayStart();
$from = match ($range) {
    'yesterday' => $to = $RM->businessYesterdayStart(),
    'week' => $RM->businessDayStart(date('Y-m-d', strtotime('-6 days'))),
    'month' => $RM->businessDayStart(date('Y-m-01')),
    default => $to,
};
$expenses = $RM->expenses($from, $to);
$byCategory = $RM->expensesByCategory($from, $to);
$total = array_sum(array_column($expenses, 'amount'));

ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-2">Expense categories</h2>
      <form method="post" class="d-flex gap-2 mb-2">
        <input type="hidden" name="action" value="add_category">
        <input name="name" class="form-control" placeholder="e.g. Rent, Utilities" required>
        <button class="btn btn-outline-primary">Add</button>
      </form>
      <div class="small text-muted mb-3"><?php echo rm_h(implode(', ', array_column($categories, 'name')) ?: 'None yet.'); ?></div>

      <h2 class="h6 fw-bold mb-2">Log an expense</h2>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_expense">
        <label class="form-label small">Category</label>
        <select name="category_id" class="form-select" required>
          <?php foreach ($categories as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo rm_h($c['name']); ?></option><?php endforeach; ?>
        </select>
        <label class="form-label small mt-2">Description</label>
        <input name="description" class="form-control" required>
        <label class="form-label small mt-2">Amount</label>
        <input type="number" step="0.01" name="amount" class="form-control" required>
        <label class="form-label small mt-2">Proof photo (receipt / M-Pesa message)</label>
        <input type="file" name="proof" class="form-control" accept="image/*">
        <label class="form-label small mt-2">Or a note (e.g. M-Pesa code)</label>
        <input name="proof_note" class="form-control">
        <button class="btn btn-primary w-100 mt-3">Save expense</button>
      </form>
    </div></div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="d-flex gap-2 mb-3">
      <?php foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'Last 7 days', 'month' => 'This month'] as $k => $l): ?>
        <a href="?range=<?php echo $k; ?>" class="btn btn-sm <?php echo $range === $k ? 'btn-primary' : 'btn-outline-secondary'; ?>"><?php echo $l; ?></a>
      <?php endforeach; ?>
    </div>
    <div class="card border-0 shadow-sm mb-3" style="border-radius:14px;"><div class="card-body p-4">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 fw-bold mb-0">By category</h2>
        <div class="fw-bold">Total: <?php echo rm_money($total); ?></div>
      </div>
      <?php $max = max(array_column($byCategory, 'total') ?: [1]) ?: 1; ?>
      <?php foreach ($byCategory as $c): if (!$c['items']) continue; ?>
        <div class="mb-2">
          <div class="d-flex justify-content-between small"><span><?php echo rm_h($c['name']); ?> (<?php echo (int) $c['items']; ?>)</span><span><?php echo rm_money($c['total']); ?></span></div>
          <div class="progress" style="height:6px;"><div class="progress-bar <?php echo $c['total'] / $max > 0.6 ? 'bg-danger' : 'bg-primary'; ?>" style="width:<?php echo round($c['total'] / $max * 100); ?>%"></div></div>
        </div>
      <?php endforeach; ?>
    </div></div>

    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Expenses</h2>
      <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Proof</th></tr></thead>
        <tbody>
        <?php foreach ($expenses as $e): ?>
          <tr>
            <td><?php echo date('j M, H:i', strtotime($e['created_at'])); ?></td>
            <td><?php echo rm_h($e['category_name']); ?></td>
            <td><?php echo rm_h($e['description']); ?></td>
            <td><?php echo rm_money($e['amount']); ?></td>
            <td><?php if ($e['proof_path']): ?><a href="<?php echo public_url($e['proof_path']); ?>" target="_blank">View</a><?php else: ?><?php echo rm_h($e['proof_note'] ?? '—'); ?><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$expenses): ?><tr><td colspan="5" class="text-center text-muted py-3">No expenses in this period.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div></div>
  </div>
</div>
<?php
$content = ob_get_clean();
rm_render('Expenses', $content);
