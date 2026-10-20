<?php
// public/super/expenses/index.php — simple expense log for the current shop:
// name, optional description, optional receipt photo, amount. Totals feed
// the dashboard's daily net profit. Staff reach this at staff/expenses/
// when the owner grants them "Record expenses".
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::EXPENSES_RECORD);
require_once APP_ROOT . '/helpers/uploads.php';

$pdo = Database::pdo();
$isStaffViewer = TenantContext::role() === 'staff';
$selfUrl = public_url($isStaffViewer ? 'staff/expenses/' : 'super/expenses/');
$F = new Models\FinanceModel($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $in = $_POST;
        $err = null;
        if (!empty($_FILES['receipt']['name'])) {
            $up = upload_image($_FILES['receipt'], PUBLIC_ROOT . '/assets/uploads/receipts/' . (int) TenantContext::tenantId(),
                               'assets/uploads/receipts/' . (int) TenantContext::tenantId(), ['jpg', 'jpeg', 'png', 'webp']);
            if ($up['ok']) { $in['receipt_path'] = $up['path']; } else { $err = 'Receipt photo: ' . $up['error']; }
        }
        if ($err === null) {
            $in['category'] = $in['category'] ?? 'General';
            $res = $F->recordExpense($in);
            $err = $res['ok'] ? null : $res['error'];
        }
        $_SESSION['flash'][$err ? 'error' : 'success'] = $err ?: 'Expense recorded.';
    } elseif ($action === 'delete' && !$isStaffViewer) {
        $_SESSION['flash']['success'] = $F->deleteExpense((int) ($_POST['id'] ?? 0)) ? 'Expense deleted.' : 'Expense not found.';
    }
    header('Location: ' . $selfUrl);
    exit;
}

$range = in_array($_GET['range'] ?? 'today', ['today', 'week', 'month'], true) ? ($_GET['range'] ?? 'today') : 'today';
$to = date('Y-m-d');
$from = match ($range) { 'week' => date('Y-m-d', strtotime('-6 days')), 'month' => date('Y-m-01'), default => $to };
$rows = $F->expensesBetween($from, $to);
// Staff only see what they recorded themselves.
if ($isStaffViewer) {
    $rows = array_values(array_filter($rows, fn($e) => (int) $e['created_by'] === (int) TenantContext::userId()));
}
$tid = (int) TenantContext::tenantId();
if (PageGuard::seesMoney()) {
    $todayTotal = $F->expenseTotal($tid, $to, $to);
    $monthTotal = $F->expenseTotal($tid, date('Y-m-01'), $to);
} else { // staff: only what they recorded — never the shop's spending
    $mine = array_values(array_filter($F->expensesBetween(date('Y-m-01'), $to), fn($e) => (int) $e['created_by'] === (int) TenantContext::userId()));
    $sumOf = fn(array $rs) => array_sum(array_map(fn($e) => (float) ($e['amount'] ?? 0), $rs));
    $todayTotal = $sumOf(array_filter($mine, fn($e) => substr((string) ($e['expense_date'] ?? $e['created_at'] ?? ''), 0, 10) === $to));
    $monthTotal = $sumOf($mine);
}
$__tenant = (new Models\TenantModel($pdo))->find($tid);
$currency = $__tenant['currency'] ?? 'KES';
$money = fn($n) => htmlspecialchars($currency) . ' ' . number_format((float) $n, 0);

$page_title = 'Expenses';
ob_start();
?>
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="tk-stat"><div class="tk-stat-icon tk-orange"><i class="fas fa-calendar-day"></i></div><div class="tk-stat-body"><div class="tk-stat-num"><?php echo $money($todayTotal); ?></div><div class="tk-stat-label">Spent today</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="tk-stat"><div class="tk-stat-icon tk-red"><i class="fas fa-calendar"></i></div><div class="tk-stat-body"><div class="tk-stat-num"><?php echo $money($monthTotal); ?></div><div class="tk-stat-label">This month</div></div></div></div>
</div>

<div class="row g-4">
  <div class="col-12 col-lg-4">
    <div class="card"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Record an expense</h2>
      <form method="post" enctype="multipart/form-data" class="row g-2">
        <input type="hidden" name="action" value="create">
        <div class="col-12"><input name="title" class="form-control" placeholder="Expense name, e.g. Electricity token" required></div>
        <div class="col-12"><textarea name="description" class="form-control" rows="2" placeholder="Description (optional)"></textarea></div>
        <div class="col-6"><input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="Amount spent" required></div>
        <div class="col-6">
          <select name="payment_method" class="form-select"><option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="bank">Bank</option></select>
        </div>
        <div class="col-6"><input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
        <div class="col-6"><input name="category" class="form-control" placeholder="Category" value="General"></div>
        <div class="col-12">
          <label class="form-label small mb-1">Receipt photo <span class="text-muted">(optional)</span></label>
          <input type="file" name="receipt" accept="image/*" capture="environment" class="form-control">
        </div>
        <div class="col-12"><button class="btn btn-primary w-100">Save expense</button></div>
      </form>
    </div></div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card"><div class="card-body p-4">
      <div class="btn-group btn-group-sm mb-3">
        <?php foreach (['today' => 'Today', 'week' => '7 days', 'month' => 'This month'] as $k => $lbl): ?>
          <a class="btn <?php echo $range === $k ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?range=<?php echo $k; ?>"><?php echo $lbl; ?></a>
        <?php endforeach; ?>
      </div>
      <?php if (!$rows): ?>
        <div class="text-muted small text-center py-4">No expenses in this period.</div>
      <?php else: foreach ($rows as $e): ?>
        <div class="ex-row d-flex gap-3">
          <?php if (!empty($e['receipt_path'])): ?>
            <a href="<?php echo htmlspecialchars(public_url($e['receipt_path'])); ?>" target="_blank" rel="noopener" class="ex-thumb"><img src="<?php echo htmlspecialchars(public_url($e['receipt_path'])); ?>" alt="Receipt"></a>
          <?php else: ?>
            <span class="ex-thumb ex-none"><i class="fas fa-receipt"></i></span>
          <?php endif; ?>
          <div class="flex-grow-1 min-w-0">
            <div class="d-flex justify-content-between gap-2">
              <div class="fw-semibold text-truncate"><?php echo htmlspecialchars($e['title']); ?></div>
              <div class="fw-bold text-nowrap"><?php echo $money($e['amount']); ?></div>
            </div>
            <?php if (!empty($e['description'])): ?><div class="small"><?php echo nl2br(htmlspecialchars($e['description'])); ?></div><?php endif; ?>
            <div class="small text-muted d-flex justify-content-between gap-2">
              <span><?php echo date('j M Y', strtotime($e['expense_date'])); ?> · <?php echo htmlspecialchars(ucfirst($e['payment_method'])); ?> · <?php echo htmlspecialchars($e['category']); ?><?php echo $e['by_name'] ? ' · ' . htmlspecialchars($e['by_name']) : ''; ?></span>
              <?php if (!$isStaffViewer): ?>
              <form method="post" onsubmit="return confirm('Delete this expense?');">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $e['id']; ?>">
                <button class="btn btn-link btn-sm text-danger p-0" style="min-height:0;"><i class="fas fa-trash"></i></button>
              </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div></div>
  </div>
</div>
<style>
.ex-row{border-bottom:1px solid #eee;padding:12px 0;}.ex-row:last-child{border-bottom:0;}
.ex-thumb{width:52px;height:52px;border-radius:8px;overflow:hidden;flex-shrink:0;background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:#9aa0ac;}
.ex-thumb img{width:100%;height:100%;object-fit:cover;}
</style>
<?php
$content = ob_get_clean();
$__layout = $isStaffViewer ? 'staff' : 'tenants';
include __DIR__ . '/../../templates/' . $__layout . '/layout.php';
