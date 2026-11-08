<?php
// public/stock/history.php — every stock movement (received, sent, arrived,
// count corrections) with who and when; filter, then Excel / PDF.
require_once __DIR__ . '/_boot.php';
stock_guard();
$store = stock_store();
$stores = StockControl::myStores();
$value = StockControl::can('stock.value');

$f = [
    'type' => in_array($_GET['type'] ?? '', ['receive', 'transfer_out', 'transfer_in', 'clear', 'correction'], true) ? $_GET['type'] : '',
    'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '',
    'to'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '',
    'q'    => trim((string) ($_GET['q'] ?? '')),
];
$scope = ($_GET['scope'] ?? '') === 'all' && count($stores) > 1 ? array_keys($stores) : [$store];
$moves = StockControl::moves($scope, $f, 2000);

if (!empty($_GET['excel']) || !empty($_GET['pdf'])) {
    $head = ['Date & time', 'Reference', 'What', 'Item', 'Store', 'Other store', 'Quantity', 'Balance after'];
    if ($value) $head[] = 'Unit cost';
    $head = array_merge($head, ['Supplier', 'Delivery / ref no.', 'Delivered by', 'Vehicle', 'Recorded by', 'Note']);
    $rows = array_map(function ($m) use ($value) {
        $r = [$m['created_at'], $m['ref'], StockControl::typeLabel($m['type']), $m['product_name'], $m['store_name'], $m['other_store'] ?? '', (float) $m['qty'], (float) $m['balance']];
        if ($value) $r[] = (float) $m['unit_cost'];
        return array_merge($r, [$m['supplier'] ?? '', $m['doc_no'] ?? '', $m['delivered_by'] ?? '', $m['vehicle'] ?? '', $m['username'], $m['note']]);
    }, $moves);
    $sheet = ['Stock history' => ['headers' => $head, 'rows' => $rows, 'money' => $value ? [8] : []]];
    $file = 'stock-history-' . date('Y-m-d');
    $label = ($f['from'] ? date('j M Y', strtotime($f['from'])) : 'Start') . ' – ' . ($f['to'] ? date('j M Y', strtotime($f['to'])) : date('j M Y'));
    if (!empty($_GET['pdf'])) PdfExport::download($file . '.pdf', 'Stock history', $label, $sheet, ['Movements' => (string) count($moves)]);
    ExcelExport::download($file . '.xlsx', $sheet);
}

$pill = ['receive' => 'p-in', 'transfer_in' => 'p-in', 'transfer_out' => 'p-mv', 'correction' => 'p-cnt', 'opening' => 'p-mute', 'clear' => 'p-out'];
$qs = fn(array $x) => '?' . http_build_query(array_filter($f + ['scope' => $_GET['scope'] ?? ''] + $x));
$title = 'History';
$tab = 'history.php';
ob_start();
?>
<div class="sc-head">
  <div><h1>History</h1><p><?php echo count($moves); ?> movement<?php echo count($moves) === 1 ? '' : 's'; ?> · <?php echo count($scope) > 1 ? 'all stores' : sh($stores[$store]['name'] ?? ''); ?></p></div>
  <div style="display:flex;gap:8px"><a class="btn btn-l" href="<?php echo sh($qs(['excel' => 1])); ?>"><i class="fas fa-file-excel" style="color:#16a34a"></i> Excel</a>
    <a class="btn btn-l" href="<?php echo sh($qs(['pdf' => 1])); ?>"><i class="fas fa-file-pdf" style="color:#dc2626"></i> PDF</a></div>
</div>
<form method="get" class="panel" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
  <div><label class="f">Search</label><input class="in" name="q" value="<?php echo sh($f['q']); ?>" placeholder="Item, reference, note"></div>
  <div><label class="f">What</label><select class="in" name="type"><option value="">Everything</option>
    <?php foreach (['receive', 'transfer_out', 'transfer_in', 'clear', 'correction'] as $t): ?><option value="<?php echo $t; ?>" <?php echo $f['type'] === $t ? 'selected' : ''; ?>><?php echo StockControl::typeLabel($t); ?></option><?php endforeach; ?></select></div>
  <div><label class="f">From</label><input class="in" type="date" name="from" value="<?php echo sh($f['from']); ?>"></div>
  <div><label class="f">To</label><input class="in" type="date" name="to" value="<?php echo sh($f['to']); ?>"></div>
  <?php if (count($stores) > 1): ?><div><label class="f">Stores</label><select class="in" name="scope"><option value="">This store</option><option value="all" <?php echo count($scope) > 1 ? 'selected' : ''; ?>>All stores</option></select></div><?php endif; ?>
  <button class="btn btn-d"><i class="fas fa-filter"></i> Show</button>
</form>
<div class="panel">
  <?php if (!$moves): ?><div class="empty">No movements match.</div><?php else: ?>
  <div class="wrap-t"><table class="t">
    <thead><tr><th>When</th><th>Ref</th><th>What</th><th>Item</th><th>Store</th><th class="r">Qty</th><th class="r">Balance</th><?php if ($value): ?><th class="r">Unit cost</th><?php endif; ?><th>Delivery</th><th>By</th><th>Note</th></tr></thead>
    <tbody>
    <?php foreach ($moves as $m): ?>
      <tr>
        <td class="small mute" style="white-space:nowrap"><?php echo date('j M Y, H:i', strtotime($m['created_at'])); ?></td>
        <td class="small"><a href="?q=<?php echo urlencode((string) $m['ref']); ?>&scope=all" style="text-decoration:none"><?php echo sh($m['ref']); ?></a></td>
        <td><span class="pill <?php echo $pill[$m['type']] ?? 'p-mute'; ?>"><?php echo StockControl::typeLabel($m['type']); ?></span></td>
        <td style="font-weight:600"><?php echo sh($m['product_name']); ?></td>
        <td class="small"><?php echo sh($m['store_name']); ?><?php echo $m['other_store'] ? ' <span class="mute">' . ($m['type'] === 'transfer_out' ? '→ ' : '← ') . sh($m['other_store']) . '</span>' : ''; ?></td>
        <td class="r num" style="font-weight:600;color:<?php echo $m['qty'] < 0 ? 'var(--bad)' : 'var(--ok)'; ?>"><?php echo ($m['qty'] > 0 ? '+' : '') . stock_qty($m['qty']); ?></td>
        <td class="r num"><?php echo stock_qty($m['balance']); ?></td>
        <?php if ($value): ?><td class="r num"><?php echo number_format((float) $m['unit_cost'], 2); ?></td><?php endif; ?>
        <td class="small mute"><?php echo sh(implode(' · ', array_filter([$m['supplier'] ?? '', $m['doc_no'] ?? '', $m['delivered_by'] ? 'by ' . $m['delivered_by'] : '', $m['vehicle'] ?? '']))) ?: '—'; ?></td>
        <td class="small"><?php echo sh($m['username']); ?></td>
        <td class="small mute"><?php echo sh($m['note']); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
