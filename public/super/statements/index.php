<?php
// public/super/statements/index.php — transaction statement, laid out like an
// M-Pesa statement: every shilling in and out of the shop with its date, what
// it was, and the receipt / invoice behind it. Owners only (any package).
//   In   shop sale payments, direct sales, payments on credit sales, credit-book
//        repayments, restaurant payments, services paid
//   Out  expenses (supplier purchases are paid through expenses), refunds on
//        returns, commission paid out to service staff
// ?csv=1 downloads the same rows.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::money();
if (TenantContext::role() !== 'tenant_owner') {
    header('Location: ' . public_url('super/dashboard/'));
    exit;
}

$pdo = Database::pdo();
$tid = (int) TenantContext::tenantId();
$tenant = (new Models\TenantModel($pdo))->find($tid);
$currency = $tenant['currency'] ?? 'KES';

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$dir = in_array($_GET['dir'] ?? '', ['in', 'out'], true) ? $_GET['dir'] : 'all';
$q = trim((string) ($_GET['q'] ?? ''));

$rows = [];
/** One source of transactions; a source whose tables aren't there yet is skipped. */
$add = function (string $sql, array $params, string $dir, callable $map) use ($pdo, &$rows) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $row = $map($r) + ['dir' => $dir];
            if ((float) $row['amount'] > 0) $rows[] = $row;
        }
    } catch (Throwable $e) {
        // table / column missing on this install
    }
};
$range = [$tid, $from . ' 00:00:00', $to . ' 23:59:59'];
$when = 'BETWEEN ? AND ?';
$method = fn($m) => ucfirst(strtolower((string) ($m ?: 'cash')));

// ---- money in
$add("SELECT p.id, p.amount, p.method, p.created_at, o.id AS oid, o.receipt_number, o.table_name, u.username
        FROM order_payments p JOIN orders o ON o.id = p.order_id LEFT JOIN users u ON u.id = p.received_by
       WHERE p.tenant_id = ? AND p.created_at $when", $range, 'in', fn($r) => [
    'ref' => 'PAY' . $r['id'], 'at' => $r['created_at'], 'amount' => $r['amount'], 'method' => $method($r['method']),
    'what' => 'Sale ' . $r['receipt_number'] . ($r['table_name'] ? ' · ' . $r['table_name'] : ''), 'by' => $r['username'],
    'receipt' => 'super/orders/receipt.php?id=' . (int) $r['oid']]);
$add("SELECT s.id, s.receipt_number, s.customer_name, s.payment_method, s.created_at, u.username,
             s.amount_paid - COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp WHERE sp.sale_id = s.id), 0) AS paid_at_sale
        FROM sales s LEFT JOIN users u ON u.id = s.staff_id
       WHERE s.tenant_id = ? AND s.created_at $when AND COALESCE(s.status, '') NOT IN ('void', 'voided', 'cancelled')", $range, 'in', fn($r) => [
    'ref' => 'SAL' . $r['id'], 'at' => $r['created_at'], 'amount' => $r['paid_at_sale'], 'method' => $method($r['payment_method']),
    'what' => 'Sale ' . $r['receipt_number'] . ($r['customer_name'] ? ' · ' . $r['customer_name'] : ''), 'by' => $r['username'],
    'receipt' => 'super/sales/receipt.php?id=' . (int) $r['id']]);
$add("SELECT p.id, p.amount, p.method, p.created_at, s.id AS sid, s.receipt_number, u.username
        FROM sale_payments p JOIN sales s ON s.id = p.sale_id LEFT JOIN users u ON u.id = p.staff_id
       WHERE p.tenant_id = ? AND p.created_at $when", $range, 'in', fn($r) => [
    'ref' => 'SPY' . $r['id'], 'at' => $r['created_at'], 'amount' => $r['amount'], 'method' => $method($r['method']),
    'what' => 'Payment on credit sale ' . $r['receipt_number'], 'by' => $r['username'],
    'receipt' => 'super/sales/receipt.php?id=' . (int) $r['sid']]);
$add("SELECT p.id, p.amount, p.method, p.created_at, c.customer_name, u.username
        FROM manual_credit_payments p JOIN manual_credits c ON c.id = p.credit_id LEFT JOIN users u ON u.id = p.received_by
       WHERE p.tenant_id = ? AND p.created_at $when", $range, 'in', fn($r) => [
    'ref' => 'CRB' . $r['id'], 'at' => $r['created_at'], 'amount' => $r['amount'], 'method' => $method($r['method']),
    'what' => 'Credit book repayment · ' . $r['customer_name'], 'by' => $r['username'], 'receipt' => null]);
$add("SELECT p.id, p.amount, p.method, p.reference, p.created_at, o.id AS oid, o.order_no, u.username
        FROM rest_payments p JOIN rest_orders o ON o.id = p.order_id LEFT JOIN users u ON u.id = p.user_id
       WHERE p.tenant_id = ? AND p.created_at $when", $range, 'in', fn($r) => [
    'ref' => 'RST' . $r['id'], 'at' => $r['created_at'], 'amount' => $r['amount'], 'method' => $method($r['method']) . ($r['reference'] ? ' ' . $r['reference'] : ''),
    'what' => 'Restaurant order #' . $r['order_no'], 'by' => $r['username'],
    'receipt' => 'super/restaurant/orders.php?receipt=' . (int) $r['oid']]);
$add("SELECT t.id, t.number, t.total, t.payment_method, t.paid_at, t.customer_name, u.username
        FROM svc_tickets t LEFT JOIN users u ON u.id = t.paid_by
       WHERE t.tenant_id = ? AND t.status = 'paid' AND t.paid_at $when", $range, 'in', fn($r) => [
    'ref' => 'SVC' . $r['id'], 'at' => $r['paid_at'], 'amount' => $r['total'], 'method' => $method($r['payment_method']),
    'what' => 'Service ' . $r['number'] . ($r['customer_name'] ? ' · ' . $r['customer_name'] : ''), 'by' => $r['username'],
    'receipt' => 'super/services/receipt.php?id=' . (int) $r['id']]);

// ---- money out
$add("SELECT e.id, e.title, e.category, e.amount, e.payment_method, e.expense_date, e.created_at, e.receipt_path, u.username
        FROM finance_expenses e LEFT JOIN users u ON u.id = e.created_by
       WHERE e.tenant_id = ? AND e.expense_date BETWEEN DATE(?) AND DATE(?)", $range, 'out', fn($r) => [
    'ref' => 'EXP' . $r['id'], 'at' => $r['expense_date'] . ' ' . date('H:i:s', strtotime($r['created_at'])), 'amount' => $r['amount'],
    'method' => $method($r['payment_method']), 'what' => 'Expense · ' . $r['title'] . ($r['category'] ? ' (' . $r['category'] . ')' : ''),
    'by' => $r['username'], 'receipt' => $r['receipt_path'] ?: null, 'file' => (bool) $r['receipt_path']]);
$add("SELECT r.id, r.refund_amount, r.refund_method, r.created_at, r.reason, o.id AS oid, o.receipt_number, u.username
        FROM order_returns r JOIN orders o ON o.id = r.order_id LEFT JOIN users u ON u.id = r.processed_by
       WHERE r.tenant_id = ? AND r.created_at $when", $range, 'out', fn($r) => [
    'ref' => 'RFD' . $r['id'], 'at' => $r['created_at'], 'amount' => $r['refund_amount'], 'method' => $method($r['refund_method']),
    'what' => 'Refund on ' . $r['receipt_number'] . ($r['reason'] ? ' · ' . $r['reason'] : ''), 'by' => $r['username'],
    'receipt' => 'super/orders/receipt.php?id=' . (int) $r['oid']]);
$add("SELECT p.id, p.amount, p.method, p.created_at, s.username AS staff, u.username
        FROM svc_payouts p LEFT JOIN users s ON s.id = p.staff_id LEFT JOIN users u ON u.id = p.paid_by
       WHERE p.account_id = ? AND p.created_at $when", [Features::accountId($tid), $range[1], $range[2]], 'out', fn($r) => [
    'ref' => 'COM' . $r['id'], 'at' => $r['created_at'], 'amount' => $r['amount'], 'method' => $method($r['method']),
    'what' => 'Commission paid · ' . $r['staff'], 'by' => $r['username'], 'receipt' => null]);

usort($rows, fn($a, $b) => strcmp($a['at'], $b['at']) ?: strcmp($a['ref'], $b['ref']));
// Running balance over the period (oldest first), then newest on top like a statement.
$bal = 0; $totIn = 0; $totOut = 0;
foreach ($rows as &$r) {
    $amt = (float) $r['amount'];
    if ($r['dir'] === 'in') { $bal += $amt; $totIn += $amt; } else { $bal -= $amt; $totOut += $amt; }
    $r['balance'] = $bal;
}
unset($r);
$rows = array_reverse(array_values(array_filter($rows, function ($r) use ($dir, $q) {
    if ($dir !== 'all' && $r['dir'] !== $dir) return false;
    return $q === '' || stripos($r['what'] . ' ' . $r['ref'] . ' ' . $r['method'] . ' ' . ($r['by'] ?? ''), $q) !== false;
})));

if (!empty($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="statement_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Receipt No.', 'Completion Time', 'Details', 'Method', 'By', 'Paid In', 'Withdrawn', 'Balance']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['ref'], $r['at'], $r['what'], $r['method'], $r['by'] ?? '',
            $r['dir'] === 'in' ? number_format((float) $r['amount'], 2, '.', '') : '',
            $r['dir'] === 'out' ? number_format((float) $r['amount'], 2, '.', '') : '', number_format($r['balance'], 2, '.', '')]);
    }
    exit;
}

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$m = fn($v) => number_format((float) $v, 2);
$page_title = 'Statement';
ob_start();
?>
<style>
.st-head{background:#fff;border:1px solid #e4e7ec;border-radius:12px;padding:16px 18px;margin-bottom:14px;}
.st-sum{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:12px;}
.st-sum div{background:#f8fafc;border-radius:10px;padding:10px 12px;}
.st-sum small{display:block;color:#64748b;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;}
.st-sum b{font-size:1.15rem;}
.st-table{background:#fff;border:1px solid #e4e7ec;border-radius:12px;overflow:auto;}
.st-table table{width:100%;font-size:.86rem;border-collapse:collapse;min-width:760px;}
.st-table th{background:#1f9a5f;color:#fff;font-weight:600;padding:9px 10px;text-align:left;white-space:nowrap;}
.st-table td{padding:8px 10px;border-top:1px solid #f1f5f9;vertical-align:top;}
.st-table tr:nth-child(even) td{background:#fafafa;}
.st-in{color:#15803d;font-weight:600;} .st-out{color:#b91c1c;font-weight:600;}
.st-table .num{text-align:right;white-space:nowrap;}
@media (max-width:600px){.st-sum{grid-template-columns:1fr;}}
@media print{.st-filter,.st-actions{display:none !important;}}
</style>
<div class="st-head">
  <div class="d-flex justify-content-between flex-wrap gap-2">
    <div><div class="fw-bold fs-5"><?php echo $h($tenant['name'] ?? ''); ?> — Transaction statement</div>
      <div class="small text-muted">Statement period: <?php echo date('j M Y', strtotime($from)); ?> – <?php echo date('j M Y', strtotime($to)); ?> · <?php echo $h($currency); ?></div></div>
    <div class="st-actions d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="?<?php echo $h(http_build_query(['from' => $from, 'to' => $to, 'dir' => $dir, 'q' => $q, 'csv' => 1])); ?>"><i class="fas fa-file-csv me-1"></i>CSV</a>
      <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
    </div>
  </div>
  <div class="st-sum">
    <div><small>Paid in</small><b class="st-in"><?php echo $m($totIn); ?></b></div>
    <div><small>Withdrawn / paid out</small><b class="st-out"><?php echo $m($totOut); ?></b></div>
    <div><small>Net for the period</small><b><?php echo $m($totIn - $totOut); ?></b></div>
  </div>
  <form class="st-filter row g-2 mt-2" method="get">
    <div class="col-6 col-md-2"><input class="form-control form-control-sm" type="date" name="from" value="<?php echo $h($from); ?>"></div>
    <div class="col-6 col-md-2"><input class="form-control form-control-sm" type="date" name="to" value="<?php echo $h($to); ?>"></div>
    <div class="col-6 col-md-2"><select class="form-select form-select-sm" name="dir">
      <?php foreach (['all' => 'All transactions', 'in' => 'Money in', 'out' => 'Money out'] as $k => $v): ?><option value="<?php echo $k; ?>" <?php echo $dir === $k ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-4"><input class="form-control form-control-sm" name="q" value="<?php echo $h($q); ?>" placeholder="Search receipt, customer, M-Pesa…"></div>
    <div class="col-12 col-md-2"><button class="btn btn-sm btn-success w-100">Show</button></div>
  </form>
</div>
<div class="st-table">
  <table>
    <thead><tr><th>Receipt No.</th><th>Completion time</th><th>Details</th><th>Method</th><th class="num">Paid in</th><th class="num">Withdrawn</th><th class="num">Balance</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No transactions in this period.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="fw-semibold"><?php echo $h($r['ref']); ?></td>
        <td style="white-space:nowrap"><?php echo date('d-m-Y H:i', strtotime($r['at'])); ?></td>
        <td><?php echo $h($r['what']); ?><?php if (!empty($r['by'])): ?><div class="small text-muted">by <?php echo $h($r['by']); ?></div><?php endif; ?></td>
        <td><?php echo $h($r['method']); ?></td>
        <td class="num st-in"><?php echo $r['dir'] === 'in' ? $m($r['amount']) : ''; ?></td>
        <td class="num st-out"><?php echo $r['dir'] === 'out' ? '-' . $m($r['amount']) : ''; ?></td>
        <td class="num"><?php echo $m($r['balance']); ?></td>
        <td class="st-actions"><?php if (!empty($r['receipt'])): ?>
          <?php if (!empty($r['file'])): ?><a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="<?php echo $h(public_url($r['receipt'])); ?>">Receipt</a>
          <?php else: ?><a class="btn btn-sm btn-outline-secondary" data-receipt-popup href="<?php echo $h(public_url($r['receipt'])); ?>">Receipt</a><?php endif; ?>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="small text-muted mt-2">Balance runs from the start of the period. Supplier purchases show as the expense they were paid with.</p>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
