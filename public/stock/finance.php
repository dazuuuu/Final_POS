<?php
// public/stock/finance.php — money side, all optional: record income and
// expenses, see cash flow and profit for a period, download Excel / PDF.
//   Money in  = income you record (incl. stock cleared with a selling price)
//   Money out = stock purchases (received stock with a buying price) + expenses
//   Cash flow = money in − money out
//   Profit    = income − cost of the stock that went out − expenses
require_once __DIR__ . '/_boot.php';
stock_guard('stock.finance');
$store = stock_store();
$stores = StockControl::myStores();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!stock_csrf_ok()) stock_go('finance.php', 'This page expired — try again.', 'err');
    if (($_POST['act'] ?? '') === 'add') {
        [$ok, $e] = StockControl::addCash((int) ($_POST['store'] ?? $store), (string) ($_POST['kind'] ?? ''), (string) ($_POST['date'] ?? ''),
            (string) ($_POST['category'] ?? ''), (string) ($_POST['description'] ?? ''), (float) ($_POST['amount'] ?? 0), (string) ($_POST['method'] ?? ''));
        stock_go('finance.php', $ok ? 'Recorded.' : $e, $ok ? 'ok' : 'err');
    }
    if (($_POST['act'] ?? '') === 'delete' && StockControl::isAdmin()) {
        StockControl::deleteCash((int) ($_POST['id'] ?? 0));
        stock_go('finance.php', 'Entry deleted.');
    }
}

$valid = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d) ? $d : null;
$from = $valid($_GET['from'] ?? '') ?? date('Y-m-01');
$to = $valid($_GET['to'] ?? '') ?? date('Y-m-t');
if ($to < $from) { [$from, $to] = [$to, $from]; }
$scope = ($_GET['scope'] ?? 'all') === 'here' || count($stores) < 2 ? [$store] : array_keys($stores);
$F = StockControl::finance($scope, $from, $to);
$entries = StockControl::cashEntries($scope, $from, $to);
$cur = stock_money(0); $cur = trim(preg_replace('/[\d.,\s]+$/', '', $cur));
$m2 = fn($v) => number_format((float) $v, 2);
$label = date('j M Y', strtotime($from)) . ' – ' . date('j M Y', strtotime($to));

if (!empty($_GET['excel']) || !empty($_GET['pdf'])) {
    $sheets = [
        'Summary' => ['headers' => ['', $cur ?: 'KES'], 'money' => [1], 'rows' => [
            ['Money in (income)', round($F['income'], 2)], ['Stock purchases', round($F['purchases'], 2)], ['Expenses', round($F['expenses'], 2)],
            ['Money out (purchases + expenses)', round($F['money_out'], 2)], ['Cash flow (in − out)', round($F['cash_flow'], 2)],
            ['Cost of stock that went out', round($F['cogs'], 2)], ['Profit (income − cost of stock out − expenses)', round($F['profit'], 2)]]],
        'By month' => ['headers' => ['Month', 'Income', 'Stock purchases', 'Expenses', 'Cash flow', 'Cost of stock out', 'Profit'], 'money' => [1, 2, 3, 4, 5, 6],
            'rows' => array_map(fn($mk, $r) => [date('F Y', strtotime($mk . '-01')), round($r['income'], 2), round($r['purchases'], 2), round($r['expenses'], 2),
                round($r['income'] - $r['purchases'] - $r['expenses'], 2), round($r['cogs'], 2), round($r['income'] - $r['cogs'] - $r['expenses'], 2)], array_keys($F['months']), $F['months'])],
        'Cash book' => ['headers' => ['Date', 'Type', 'Category', 'Description', 'Store', 'Method', 'Amount', 'Recorded by'], 'money' => [6],
            'rows' => array_map(fn($e) => [$e['entry_date'], $e['kind'] === 'income' ? 'Income' : 'Expense', $e['category'], $e['description'], $e['store_name'], ucfirst((string) $e['method']), (float) $e['amount'], $e['username']], $entries)],
    ];
    $file = 'finance-' . $from . '-to-' . $to;
    if (!empty($_GET['pdf'])) PdfExport::download($file . '.pdf', 'Finance report', $label, $sheets, [
        'Money in' => $m2($F['income']), 'Money out' => $m2($F['money_out']), 'Cash flow' => $m2($F['cash_flow']), 'Profit' => $m2($F['profit'])]);
    ExcelExport::download($file . '.xlsx', $sheets);
}

$qs = fn(array $x) => '?' . http_build_query(array_filter(['from' => $from, 'to' => $to, 'scope' => $_GET['scope'] ?? ''] + $x));
$title = 'Finance';
$tab = 'finance.php';
ob_start();
?>
<div class="sc-head">
  <div><h1>Finance</h1><p>Money in, money out, cash flow and profit — from what you record. Everything here is optional.</p></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn btn-l" href="<?php echo sh($qs(['excel' => 1])); ?>"><i class="fas fa-file-excel" style="color:#16a34a"></i> Excel</a>
    <a class="btn btn-l" href="<?php echo sh($qs(['pdf' => 1])); ?>"><i class="fas fa-file-pdf" style="color:#dc2626"></i> PDF</a></div>
</div>
<form method="get" class="panel" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
  <div><label class="f">From</label><input class="in" type="date" name="from" value="<?php echo sh($from); ?>"></div>
  <div><label class="f">To</label><input class="in" type="date" name="to" value="<?php echo sh($to); ?>"></div>
  <?php if (count($stores) > 1): ?><div><label class="f">Stores</label><select class="in" name="scope"><option value="all" <?php echo count($scope) > 1 ? 'selected' : ''; ?>>All stores</option><option value="here" <?php echo count($scope) === 1 ? 'selected' : ''; ?>>This store only</option></select></div><?php endif; ?>
  <button class="btn btn-d"><i class="fas fa-filter"></i> Show</button>
</form>

<div class="grid g4" style="margin-bottom:18px">
  <div class="kpi"><div class="hd">Money in</div><b style="color:#15803d"><?php echo $m2($F['income']); ?></b><span class="vs">income recorded</span></div>
  <div class="kpi"><div class="hd">Money out</div><b style="color:#b91c1c"><?php echo $m2($F['money_out']); ?></b><span class="vs">stock <?php echo $m2($F['purchases']); ?> · expenses <?php echo $m2($F['expenses']); ?></span></div>
  <div class="kpi"><div class="hd">Cash flow</div><b style="color:<?php echo $F['cash_flow'] < 0 ? '#b91c1c' : '#15803d'; ?>"><?php echo $m2($F['cash_flow']); ?></b><span class="vs">in − out</span></div>
  <div class="kpi"><div class="hd">Profit</div><b style="color:<?php echo $F['profit'] < 0 ? '#b91c1c' : '#15803d'; ?>"><?php echo $m2($F['profit']); ?></b><span class="vs">after cost of stock out</span></div>
</div>
<p class="small mute" style="margin:-6px 0 18px"><i class="fas fa-circle-info"></i> Profit = income − cost of the stock that went out (at its buying price) − expenses. Stock purchases count as money out when you give a buying price while receiving stock. Clearing stock with a selling price records the income for you.</p>

<div class="grid" style="grid-template-columns:1.5fr 1fr">
  <div>
    <div class="panel">
      <h2>By month</h2>
      <?php if (!$F['months']): ?><div class="empty">Nothing recorded in this period.</div><?php else: ?>
      <div class="wrap-t"><table class="t">
        <thead><tr><th>Month</th><th class="r">Income</th><th class="r">Stock bought</th><th class="r">Expenses</th><th class="r">Cash flow</th><th class="r">Profit</th></tr></thead>
        <tbody>
        <?php foreach ($F['months'] as $mk => $r): $cf = $r['income'] - $r['purchases'] - $r['expenses']; $pf = $r['income'] - $r['cogs'] - $r['expenses']; ?>
          <tr><td style="font-weight:600"><?php echo date('F Y', strtotime($mk . '-01')); ?></td><td class="r num"><?php echo $m2($r['income']); ?></td><td class="r num"><?php echo $m2($r['purchases']); ?></td><td class="r num"><?php echo $m2($r['expenses']); ?></td>
            <td class="r num" style="color:<?php echo $cf < 0 ? '#b91c1c' : '#15803d'; ?>"><?php echo $m2($cf); ?></td><td class="r num" style="color:<?php echo $pf < 0 ? '#b91c1c' : '#15803d'; ?>"><?php echo $m2($pf); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
    <div class="panel">
      <h2>Cash book <span class="mute small"><?php echo count($entries); ?> entries</span></h2>
      <?php if (!$entries): ?><div class="empty">No income or expenses recorded in this period.</div><?php else: ?>
      <div class="wrap-t"><table class="t">
        <thead><tr><th>Date</th><th>Type</th><th>Category</th><th>Description</th><?php if (count($scope) > 1): ?><th>Store</th><?php endif; ?><th class="r">Amount</th><th>By</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($entries as $e): $inc = $e['kind'] === 'income'; ?>
          <tr><td class="small" style="white-space:nowrap"><?php echo date('j M Y', strtotime($e['entry_date'])); ?></td>
            <td><span class="pill <?php echo $inc ? 'p-in' : 'p-out'; ?>"><?php echo $inc ? 'Income' : 'Expense'; ?></span></td>
            <td class="small"><?php echo sh($e['category'] ?: '—'); ?></td><td class="small"><?php echo sh($e['description']); ?></td>
            <?php if (count($scope) > 1): ?><td class="small mute"><?php echo sh($e['store_name']); ?></td><?php endif; ?>
            <td class="r num" style="font-weight:600;color:<?php echo $inc ? '#15803d' : '#b91c1c'; ?>"><?php echo ($inc ? '+' : '−') . $m2($e['amount']); ?></td>
            <td class="small mute"><?php echo sh($e['username']); ?></td>
            <td class="r"><?php if (StockControl::isAdmin()): ?><form method="post" onsubmit="return confirm('Delete this entry?')" style="margin:0"><?php echo stock_csrf_field(); ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?php echo (int) $e['id']; ?>"><button class="btn btn-l btn-sm" title="Delete"><i class="fas fa-trash"></i></button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="panel" style="align-self:start">
    <h2>Record income or an expense</h2>
    <form method="post"><?php echo stock_csrf_field(); ?><input type="hidden" name="act" value="add">
      <div class="seg2"><label><input type="radio" name="kind" value="expense" checked> Expense</label><label><input type="radio" name="kind" value="income"> Income</label></div>
      <div class="row" style="grid-template-columns:1fr 1fr">
        <div><label class="f">Date</label><input class="in" type="date" name="date" value="<?php echo date('Y-m-d'); ?>"></div>
        <div><label class="f">Amount *</label><input class="in" type="number" step="0.01" min="0.01" name="amount" required></div>
      </div>
      <label class="f">Category</label><input class="in" name="category" list="fcat" placeholder="e.g. Transport" style="margin-bottom:12px">
      <datalist id="fcat"><?php foreach (array_merge(StockControl::EXPENSE_CATEGORIES, ['Stock sales', 'Other income']) as $c): ?><option value="<?php echo sh($c); ?>"><?php endforeach; ?></datalist>
      <label class="f">Description</label><input class="in" name="description" placeholder="optional" style="margin-bottom:12px">
      <div class="row" style="grid-template-columns:1fr 1fr">
        <div><label class="f">Paid / received by</label><select class="in" name="method"><option value="">—</option><option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="bank">Bank</option><option value="other">Other</option></select></div>
        <div><label class="f">Store</label><select class="in" name="store"><?php foreach ($stores as $sid => $s): ?><option value="<?php echo $sid; ?>" <?php echo $sid === $store ? 'selected' : ''; ?>><?php echo sh($s['name']); ?></option><?php endforeach; ?></select></div>
      </div>
      <button class="btn btn-p"><i class="fas fa-plus"></i> Record</button>
    </form>
  </div>
</div>
<style>
.seg2{display:flex;gap:8px;margin-bottom:12px;} .seg2 label{flex:1;border:1px solid var(--line);border-radius:9px;padding:8px 12px;cursor:pointer;font-weight:500;}
.seg2 label:has(input:checked){border-color:var(--g);background:#f0fdf4;}
@media(max-width:900px){.grid[style*="1.5fr"]{grid-template-columns:1fr!important}}
</style>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
