<?php
// public/super/credits/index.php — the credit book. Record that a customer
// owes the shop an amount, without any products (old debts, cash lent, …),
// and take payments against it. Credit SALES with products stay on the
// Credit sales page. Staff reach this at staff/credits/.
// "By customer": each credit-book name is a customer (Customers list), so their
// credit sales add to the same balance; take deposits of any size, applied to
// the invoice picked or the oldest first (CustomerCreditModel).
require_once __DIR__ . '/../../../app/app.php';
PageGuard::auth();

$canRecord = TenantContext::can(Capabilities::CREDIT_SALES);
$canCollect = TenantContext::can(Capabilities::PAYMENTS_PROCESS);
if (!$canRecord && !$canCollect) {
    header('Location: ' . public_url('auth/login.php') . '?denied=1');
    exit;
}

$pdo = Database::pdo();
$isStaffViewer = TenantContext::role() === 'staff';
$selfUrl = public_url($isStaffViewer ? 'staff/credits/' : 'super/credits/');
$C = new Models\ManualCreditModel($pdo);
$CC = new Models\CustomerCreditModel($pdo);
$C->linkCustomers(); // older entries → customers (once per entry)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'create' && $canRecord) {
        $res = $C->create($_POST);
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Credit recorded.' : $res['error'];
    } elseif ($action === 'deposit' && $canCollect) {
        $cid = (int) ($_POST['customer_id'] ?? 0);
        $res = $CC->deposit($cid, (float) ($_POST['amount'] ?? 0), (string) ($_POST['method'] ?? 'cash'), (string) ($_POST['target'] ?? 'auto'));
        if ($res['ok']) {
            $parts = implode(', ', array_map(fn($a) => $a[0] . ' ' . number_format($a[1], 0), $res['applied']));
            $_SESSION['flash']['success'] = 'Deposit received — ' . $parts . ($res['left'] > 0 ? '. ' . number_format($res['left'], 0) . ' was more than they owed — give it back as change.' : '.');
        } else {
            $_SESSION['flash']['error'] = $res['error'];
        }
        header('Location: ' . $selfUrl . '?tab=customers&customer=' . $cid);
        exit;
    } elseif ($action === 'pay' && $canCollect) {
        $res = $C->recordPayment($id, (float) ($_POST['amount'] ?? 0), (string) ($_POST['method'] ?? 'cash'));
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Payment received.' : $res['error'];
    } elseif ($action === 'delete' && !$isStaffViewer) {
        $res = $C->delete($id);
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Credit deleted.' : $res['error'];
    }
    header('Location: ' . $selfUrl . (isset($_GET['tab']) ? '?tab=' . urlencode($_GET['tab']) : ''));
    exit;
}

$tab = in_array($_GET['tab'] ?? '', ['open', 'paid', 'customers'], true) ? $_GET['tab'] : 'customers';
$q = trim($_GET['q'] ?? '');
$rows = $tab === 'customers' ? [] : $C->list($tab);
$owing = $tab === 'customers' ? $CC->owing($q) : [];
$sel = (int) ($_GET['customer'] ?? 0);
$selCustomer = $sel ? (new Models\CustomerModel($pdo))->find($sel) : null;
$allCustomers = $canRecord ? (new Models\CustomerModel($pdo))->listForTenant(1000) : [];
if ($q !== '') {
    $rows = array_values(array_filter($rows, fn($r) => stripos($r['customer_name'], $q) !== false || stripos((string) $r['phone'], $q) !== false));
}
$outstanding = Models\ManualCreditModel::outstandingFor($pdo, (int) TenantContext::tenantId());
$collectedToday = Models\ManualCreditModel::collectedOn($pdo, (int) TenantContext::tenantId(), date('Y-m-d'));
$__tenant = (new Models\TenantModel($pdo))->find(TenantContext::tenantId());
$currency = $__tenant['currency'] ?? 'KES';
$money = fn($n) => htmlspecialchars($currency) . ' ' . number_format((float) $n, 0);

$page_title = 'Credit book';
ob_start();
?>
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="tk-stat"><div class="tk-stat-icon tk-red"><i class="fas fa-book"></i></div><div class="tk-stat-body"><div class="tk-stat-num"><?php echo $money($outstanding); ?></div><div class="tk-stat-label">Still owed</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="tk-stat"><div class="tk-stat-icon tk-greenbg"><i class="fas fa-hand-holding-dollar"></i></div><div class="tk-stat-body"><div class="tk-stat-num"><?php echo $money($collectedToday); ?></div><div class="tk-stat-label">Collected today</div></div></div></div>
  <div class="col-12 col-lg-6 d-flex align-items-center">
    <a class="btn btn-outline-secondary btn-sm" href="<?php echo public_url($isStaffViewer ? 'staff/orders/' : 'super/orders/'); ?>"><i class="fas fa-cart-shopping me-1"></i>Credit sales with products →</a>
  </div>
</div>

<div class="row g-4">
  <?php if ($canRecord): ?>
  <div class="col-12 col-lg-4">
    <div class="card"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-1">Record a credit</h2>
      <p class="small text-muted mb-3">Just who owes and how much — no products needed.</p>
      <form method="post" class="row g-2">
        <input type="hidden" name="action" value="create">
        <div class="col-12"><input name="customer_name" id="cbName" class="form-control" placeholder="Customer name" list="cbCustomers" autocomplete="off" required>
          <input type="hidden" name="customer_id" id="cbCustomerId">
          <datalist id="cbCustomers"><?php foreach ($allCustomers as $cu): ?><option value="<?php echo htmlspecialchars($cu['name']); ?>" data-id="<?php echo (int) $cu['id']; ?>" data-phone="<?php echo htmlspecialchars((string) $cu['phone']); ?>"><?php echo htmlspecialchars((string) $cu['phone']); ?></option><?php endforeach; ?></datalist>
          <div class="form-text">Pick an existing customer to add to their balance, or type a new name — it's saved as a customer.</div></div>
        <div class="col-6"><input name="phone" id="cbPhone" class="form-control" placeholder="Phone (optional)"></div>
        <div class="col-6"><input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="Amount owed" required></div>
        <div class="col-6"><input type="date" name="credit_date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
        <div class="col-6"><input name="notes" class="form-control" placeholder="Note (optional)"></div>
        <div class="col-12"><button class="btn btn-primary w-100">Save credit</button></div>
      </form>
    </div></div>
  </div>
  <?php endif; ?>

  <div class="col-12 <?php echo $canRecord ? 'col-lg-8' : ''; ?>">
    <div class="card"><div class="card-body p-4">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div class="btn-group btn-group-sm">
          <a class="btn <?php echo $tab === 'customers' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?tab=customers">By customer</a>
          <a class="btn <?php echo $tab === 'open' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?tab=open">Book entries</a>
          <a class="btn <?php echo $tab === 'paid' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?tab=paid">Cleared</a>
        </div>
        <form class="d-flex gap-2" method="get">
          <input type="hidden" name="tab" value="<?php echo $tab; ?>">
          <input name="q" class="form-control form-control-sm" placeholder="Search name / phone" value="<?php echo htmlspecialchars($q); ?>">
        </form>
      </div>
      <?php if ($tab === 'customers'): ?>
        <?php if ($selCustomer): $inv = $CC->openInvoices($sel); $dep = $CC->deposits($sel, 30); $owed = array_sum(array_column($inv, 'balance')); ?>
          <a class="small text-decoration-none" href="?tab=customers"><i class="fas fa-arrow-left me-1"></i>All customers</a>
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mt-2 mb-3">
            <div><div class="h5 fw-bold mb-0"><?php echo htmlspecialchars($selCustomer['name']); ?></div>
              <div class="small text-muted"><?php echo $selCustomer['phone'] ? '<a href="tel:' . htmlspecialchars($selCustomer['phone']) . '">' . htmlspecialchars($selCustomer['phone']) . '</a> · ' : ''; ?><?php echo count($inv); ?> open invoice<?php echo count($inv) === 1 ? '' : 's'; ?></div></div>
            <div class="text-end"><div class="small text-muted">Owes in total</div><div class="h4 fw-bold mb-0 <?php echo $owed > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $money($owed); ?></div></div>
          </div>
          <?php if ($canCollect && $inv): ?>
          <form method="post" class="border rounded p-3 mb-3" style="background:#f8faf9">
            <input type="hidden" name="action" value="deposit"><input type="hidden" name="customer_id" value="<?php echo $sel; ?>">
            <div class="fw-semibold small mb-2"><i class="fas fa-hand-holding-dollar me-1 text-success"></i>Receive a deposit — any amount</div>
            <div class="row g-2">
              <div class="col-6 col-md-3"><input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="Amount" required></div>
              <div class="col-6 col-md-3"><select name="method" class="form-select"><option value="cash">Cash</option><option value="mpesa">M-Pesa</option></select></div>
              <div class="col-12 col-md-4"><select name="target" class="form-select">
                <option value="auto">Pay oldest invoices first</option>
                <?php foreach ($inv as $i): ?><option value="<?php echo htmlspecialchars($i['key']); ?>">Only <?php echo htmlspecialchars($i['label']); ?> (owes <?php echo number_format($i['balance'], 0); ?>)</option><?php endforeach; ?>
              </select></div>
              <div class="col-12 col-md-2"><button class="btn btn-success w-100">Receive</button></div>
            </div>
          </form>
          <?php endif; ?>
          <div class="small fw-semibold text-muted mb-1">Open invoices</div>
          <?php if (!$inv): ?><div class="text-muted small py-2">Nothing owed — all cleared.</div><?php endif; ?>
          <?php foreach ($inv as $i): ?>
            <div class="cb-row d-flex justify-content-between gap-2">
              <div><span class="badge bg-light text-dark me-1"><?php echo $i['kind']; ?></span><b><?php echo htmlspecialchars($i['label']); ?></b>
                <div class="small text-muted"><?php echo date('j M Y', strtotime($i['date'])); ?> · <?php echo $money($i['total']); ?><?php echo $i['paid'] > 0 ? ' · paid ' . $money($i['paid']) : ''; ?></div></div>
              <div class="fw-bold text-danger text-nowrap"><?php echo $money($i['balance']); ?></div>
            </div>
          <?php endforeach; ?>
          <?php if ($dep): ?>
            <div class="small fw-semibold text-muted mt-3 mb-1">Deposits</div>
            <?php foreach ($dep as $d): ?>
              <div class="small text-muted"><i class="fas fa-check text-success me-1"></i><?php echo $money($d['amount']); ?> <?php echo htmlspecialchars(ucfirst($d['method'])); ?> → <?php echo htmlspecialchars($d['invoice']); ?> · <?php echo date('j M, g:i a', strtotime($d['created_at'])); ?><?php echo $d['by_name'] ? ' · ' . htmlspecialchars($d['by_name']) : ''; ?></div>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php elseif (!$owing): ?>
          <div class="text-muted small text-center py-4">Nobody owes the shop anything.</div>
        <?php else: foreach ($owing as $o): ?>
          <a class="cb-row d-flex justify-content-between gap-2 text-decoration-none text-reset" href="?tab=customers&customer=<?php echo (int) $o['id']; ?>">
            <div><div class="fw-semibold"><?php echo htmlspecialchars($o['name']); ?></div>
              <div class="small text-muted"><?php echo $o['phone'] ? htmlspecialchars($o['phone']) . ' · ' : ''; ?><?php echo (int) $o['invoices']; ?> open · book <?php echo number_format((float) $o['book'], 0); ?> · sales <?php echo number_format((float) $o['sales'], 0); ?></div></div>
            <div class="text-end"><div class="fw-bold text-danger"><?php echo $money($o['balance']); ?></div><div class="small text-muted">Open <i class="fas fa-chevron-right"></i></div></div>
          </a>
        <?php endforeach; endif; ?>
      <?php elseif (!$rows): ?>
        <div class="text-muted small text-center py-4"><?php echo $tab === 'open' ? 'Nobody owes the shop anything here.' : 'No cleared credits yet.'; ?></div>
      <?php else: foreach ($rows as $r):
          $bal = round((float) $r['amount'] - (float) $r['amount_paid'], 2); $rid = (int) $r['id']; ?>
        <div class="cb-row">
          <div class="d-flex justify-content-between gap-2">
            <div class="min-w-0">
              <div class="fw-semibold"><?php echo htmlspecialchars($r['customer_name']); ?></div>
              <div class="small text-muted">
                <?php echo date('j M Y', strtotime($r['credit_date'])); ?>
                <?php if ($r['phone']): ?> · <a href="tel:<?php echo htmlspecialchars($r['phone']); ?>"><?php echo htmlspecialchars($r['phone']); ?></a><?php endif; ?>
                <?php if ($r['notes']): ?> · <?php echo htmlspecialchars($r['notes']); ?><?php endif; ?>
              </div>
            </div>
            <div class="text-end flex-shrink-0">
              <div class="fw-bold <?php echo $bal > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $money($bal); ?></div>
              <div class="small text-muted">of <?php echo $money($r['amount']); ?></div>
            </div>
          </div>
          <?php if ($r['status'] === 'open'): ?>
          <div class="d-flex flex-wrap gap-2 mt-2">
            <?php if ($canCollect): ?>
            <form method="post" class="d-flex gap-2 flex-wrap">
              <input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?php echo $rid; ?>">
              <input type="number" step="0.01" min="0.01" max="<?php echo $bal; ?>" name="amount" value="<?php echo $bal; ?>" class="form-control form-control-sm" style="width:120px;" required>
              <select name="method" class="form-select form-select-sm" style="width:110px;"><option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="bank">Bank</option></select>
              <button class="btn btn-sm btn-success">Receive</button>
            </form>
            <?php endif; ?>
            <?php if (!$isStaffViewer && (float) $r['amount_paid'] <= 0): ?>
            <form method="post" onsubmit="return confirm('Delete this credit entry?');">
              <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $rid; ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <?php $pays = (float) $r['amount_paid'] > 0 ? $C->payments($rid) : []; if ($pays): ?>
          <div class="small text-muted mt-2">
            <?php foreach ($pays as $p): ?>
              <div><i class="fas fa-check text-success me-1"></i><?php echo $money($p['amount']); ?> <?php echo htmlspecialchars(ucfirst($p['method'])); ?> · <?php echo date('j M, g:i a', strtotime($p['created_at'])); ?><?php echo $p['by_name'] ? ' · ' . htmlspecialchars($p['by_name']) : ''; ?></div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div></div>
  </div>
</div>
<style>.cb-row{border-bottom:1px solid #eee;padding:12px 0;}.cb-row:last-child{border-bottom:0;}</style>
<script>
(function () { // picking an existing customer links the credit to them (and fills their phone)
  var n = document.getElementById('cbName'); if (!n) return;
  n.addEventListener('input', function () {
    var o = Array.prototype.find.call(document.querySelectorAll('#cbCustomers option'), function (x) { return x.value === n.value; });
    document.getElementById('cbCustomerId').value = o ? o.dataset.id : '';
    if (o && o.dataset.phone && !document.getElementById('cbPhone').value) document.getElementById('cbPhone').value = o.dataset.phone;
  });
})();
</script>
<?php
$content = ob_get_clean();
$__layout = $isStaffViewer ? 'staff' : 'tenants';
include __DIR__ . '/../../templates/' . $__layout . '/layout.php';
