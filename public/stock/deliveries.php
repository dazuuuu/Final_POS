<?php
// public/stock/deliveries.php — every delivery: stock received from suppliers
// and stock sent between your shops and warehouses, with who delivered it.
require_once __DIR__ . '/_boot.php';
stock_guard();
$store = stock_store();
$stores = StockControl::myStores();
$value = StockControl::can('stock.value');
$scope = ($_GET['scope'] ?? 'all') === 'here' || count($stores) < 2 ? [$store] : array_keys($stores);
$rows = StockControl::deliveries($scope, 300);

if (!empty($_GET['excel']) || !empty($_GET['pdf'])) {
    $head = ['Date', 'Reference', 'Type', 'From', 'To', 'Delivery / invoice no.', 'Delivered by', 'Vehicle', 'Items', 'Units'];
    if ($value) $head[] = 'Value';
    $head[] = 'Recorded by';
    $out = array_map(function ($d) use ($value) {
        $in = $d['type'] === 'receive';
        $r = [$d['at'], $d['ref'], $in ? 'In' : 'Transfer', $in ? ($d['supplier'] ?: 'Supplier') : $d['store'], $in ? $d['store'] : $d['other'], $d['doc_no'], $d['delivered_by'], $d['vehicle'], (int) $d['n_lines'], (float) $d['units']];
        if ($value) $r[] = round((float) $d['value'], 2);
        $r[] = $d['username'];
        return $r;
    }, $rows);
    $sheet = ['Deliveries' => ['headers' => $head, 'rows' => $out, 'money' => $value ? [10] : []]];
    if (!empty($_GET['pdf'])) PdfExport::download('deliveries-' . date('Y-m-d') . '.pdf', 'Deliveries', date('j M Y'), $sheet, ['Deliveries' => (string) count($rows)]);
    ExcelExport::download('deliveries-' . date('Y-m-d') . '.xlsx', $sheet);
}

$title = 'Deliveries';
$tab = 'deliveries.php';
ob_start();
?>
<div class="sc-head">
  <div><h1>Deliveries</h1><p>Stock that came in from suppliers and stock moved between your stores — with who delivered it.</p></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
  <a class="btn btn-l" href="?excel=1&scope=<?php echo count($scope) > 1 ? 'all' : 'here'; ?>"><i class="fas fa-file-excel" style="color:#16a34a"></i> Excel</a>
  <a class="btn btn-l" href="?pdf=1&scope=<?php echo count($scope) > 1 ? 'all' : 'here'; ?>"><i class="fas fa-file-pdf" style="color:#dc2626"></i> PDF</a>
  <?php if (count($stores) > 1): ?>
  <div class="seg"><a class="<?php echo count($scope) > 1 ? 'on' : ''; ?>" href="?scope=all">All stores</a><a class="<?php echo count($scope) === 1 ? 'on' : ''; ?>" href="?scope=here">This store</a></div>
  <?php endif; ?>
  </div>
</div>
<div class="panel">
  <?php if (!$rows): ?><div class="empty">No deliveries yet. They appear here when you receive stock or transfer it to another store.</div><?php else: ?>
  <div class="wrap-t"><table class="t">
    <thead><tr><th>Date</th><th>Ref</th><th>Type</th><th>From → To</th><th>Supplier / note no.</th><th>Delivered by</th><th class="r">Items</th><th class="r">Units</th><?php if ($value): ?><th class="r">Value</th><?php endif; ?><th>Recorded by</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $d): $in = $d['type'] === 'receive'; ?>
      <tr>
        <td class="small" style="white-space:nowrap"><?php echo date('j M Y, H:i', strtotime($d['at'])); ?></td>
        <td class="small"><a style="text-decoration:none;font-weight:500" href="<?php echo stock_url('history.php?scope=all&q=' . urlencode($d['ref'])); ?>"><?php echo sh($d['ref']); ?></a></td>
        <td><span class="pill <?php echo $in ? 'p-in' : 'p-mv'; ?>"><?php echo $in ? 'In' : 'Transfer'; ?></span></td>
        <td class="small"><?php echo $in ? sh($d['supplier'] ?: 'Supplier') . ' → <b>' . sh($d['store']) . '</b>' : '<b>' . sh($d['store']) . '</b> → <b>' . sh($d['other']) . '</b>'; ?></td>
        <td class="small mute"><?php echo sh($d['doc_no'] ?? '') ?: '—'; ?></td>
        <td class="small"><?php echo $d['delivered_by'] ? sh($d['delivered_by']) . ($d['vehicle'] ? ' <span class="mute">· ' . sh($d['vehicle']) . '</span>' : '') : '<span class="mute">—</span>'; ?></td>
        <td class="r num"><?php echo (int) $d['n_lines']; ?></td>
        <td class="r num"><?php echo stock_qty($d['units']); ?></td>
        <?php if ($value): ?><td class="r num"><?php echo stock_money($d['value']); ?></td><?php endif; ?>
        <td class="small mute"><?php echo sh($d['username']); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
