<?php
// public/stock/count.php — a stock count: type what is actually there; any
// difference is corrected and recorded with the reason (damaged, found, count…).
require_once __DIR__ . '/_boot.php';
stock_guard('stock.count');
$store = stock_store();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!stock_csrf_ok()) stock_go('count.php', 'This page expired — try again.', 'err');
    [$ok, $res] = StockControl::count($store, (array) ($_POST['counted'] ?? []), (string) ($_POST['reason'] ?? ''));
    stock_go($ok ? ($res ? 'history.php?type=correction' : 'count.php') : 'count.php',
        $ok ? ($res ? $res . ' item' . ($res === 1 ? '' : 's') . ' corrected.' : 'Everything matched — nothing to correct.') : $res, $ok ? 'ok' : 'err');
}

$items = StockControl::items($store);
$title = 'Count stock';
$tab = 'count.php';
ob_start();
?>
<div class="sc-head"><div><h1>Count stock</h1><p>In <b><?php echo sh(StockControl::stores()[$store]['name'] ?? ''); ?></b>. Type what you actually count. Leave an item blank to skip it — only differences are recorded.</p></div></div>
<?php if (!$items): ?><div class="panel empty">No items in this store yet.</div><?php else: ?>
<form method="post" class="panel" id="cnt"><?php echo stock_csrf_field(); ?>
  <div class="row">
    <div><label class="f">Reason *</label><input class="in" name="reason" required placeholder="e.g. Monthly count, Damaged, Expired, Found"></div>
    <div><label class="f">Find</label><input class="in" id="find" placeholder="Type to filter items"></div>
  </div>
  <div class="wrap-t" style="max-height:62vh;overflow:auto"><table class="t">
    <thead><tr><th>Item</th><th>Code</th><th class="r">System says</th><th style="width:150px">Counted</th><th class="r" style="width:110px">Difference</th></tr></thead>
    <tbody>
    <?php foreach ($items as $i): ?>
      <tr data-n="<?php echo sh(strtolower($i['name'] . ' ' . $i['barcode'])); ?>">
        <td style="font-weight:600"><?php echo sh($i['name']); ?></td>
        <td class="mute small"><?php echo sh($i['barcode'] ?: '—'); ?></td>
        <td class="r num"><?php echo stock_qty($i['quantity']) . ' ' . sh($i['unit']); ?></td>
        <td><input class="in cnt" type="number" step="0.01" min="0" name="counted[<?php echo (int) $i['id']; ?>]" data-sys="<?php echo (float) $i['quantity']; ?>"></td>
        <td class="r num diff mute">—</td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div style="margin-top:12px"><button class="btn btn-p"><i class="fas fa-clipboard-check"></i> Save count</button></div>
</form>
<script>
document.getElementById('find').addEventListener('input', function () {
  var q = this.value.toLowerCase().trim();
  document.querySelectorAll('#cnt tbody tr').forEach(function (r) { r.style.display = !q || r.dataset.n.indexOf(q) !== -1 ? '' : 'none'; });
});
document.querySelectorAll('.cnt').forEach(function (i) {
  i.addEventListener('input', function () {
    var cell = i.closest('tr').querySelector('.diff');
    if (i.value === '') { cell.textContent = '—'; cell.style.color = ''; return; }
    var d = Math.round((parseFloat(i.value) - parseFloat(i.dataset.sys)) * 100) / 100;
    cell.textContent = d === 0 ? 'matches' : (d > 0 ? '+' : '') + d;
    cell.style.color = d === 0 ? 'var(--ok)' : (d < 0 ? 'var(--bad)' : '#3730a3');
  });
});
</script>
<?php endif;
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
