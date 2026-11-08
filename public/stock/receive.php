<?php
// public/stock/receive.php — stock arriving at the current store (a purchase or
// delivery): several items at once, with the supplier, invoice / reference,
// who delivered it and the buying price — all optional. Cost is averaged in.
require_once __DIR__ . '/_boot.php';
stock_guard('stock.receive');
$store = stock_store();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!stock_csrf_ok()) stock_go('receive.php', 'This page expired — try again.', 'err');
    $lines = [];
    foreach ((array) ($_POST['item'] ?? []) as $k => $id) {
        $lines[] = ['item_id' => (int) $id, 'qty' => $_POST['qty'][$k] ?? 0, 'cost' => $_POST['cost'][$k] ?? ''];
    }
    $meta = ['supplier' => $_POST['supplier'] ?? '', 'doc_no' => $_POST['doc_no'] ?? '', 'delivered_by' => $_POST['delivered_by'] ?? '', 'vehicle' => $_POST['vehicle'] ?? ''];
    [$ok, $res] = StockControl::receive($store, $lines, '', (string) ($_POST['note'] ?? ''), $meta);
    stock_go($ok ? 'history.php?q=' . urlencode($res) : 'receive.php', $ok ? 'Stock received (' . $res . ').' : $res, $ok ? 'ok' : 'err');
}

$items = StockControl::items($store);
$title = 'Receive stock';
$tab = 'receive.php';
ob_start();
?>
<div class="sc-head"><div><h1>Receive stock</h1><p>Into <b><?php echo sh(StockControl::stores()[$store]['name'] ?? ''); ?></b>. New item? <a href="<?php echo stock_url('items.php?add=1'); ?>">Add it first</a>.</p></div></div>
<?php if (!$items): ?><div class="panel empty">No items in this store yet. <a href="<?php echo stock_url('items.php?add=1'); ?>">Add your first item</a>.</div><?php else: ?>
<form method="post" class="panel"><?php echo stock_csrf_field(); ?>
  <div class="row">
    <div><label class="f">Supplier <span class="mute">(optional)</span></label><input class="in" name="supplier" list="sups" placeholder="Who you bought from">
      <datalist id="sups"><?php foreach (StockControl::suppliers() as $sp): ?><option value="<?php echo sh($sp); ?>"><?php endforeach; ?></datalist></div>
    <div><label class="f">Invoice / reference no. <span class="mute">(optional)</span></label><input class="in" name="doc_no" placeholder="e.g. INV-2041"></div>
    <div><label class="f">Delivered by <span class="mute">(optional)</span></label><input class="in" name="delivered_by" placeholder="Driver or courier name"></div>
    <div><label class="f">Vehicle <span class="mute">(optional)</span></label><input class="in" name="vehicle" placeholder="e.g. KCA 123X"></div>
  </div>
  <div class="row"><div><label class="f">Note <span class="mute">(optional)</span></label><input class="in" name="note"></div></div>
  <div class="wrap-t"><table class="t" id="lines">
    <thead><tr><th style="min-width:240px">Item</th><th style="width:140px">Quantity</th><th style="width:180px">Buying price / unit <span class="mute">(optional)</span></th><th style="width:40px"></th></tr></thead>
    <tbody></tbody>
  </table></div>
  <template id="rowT"><tr>
    <td><select class="in" name="item[]"><option value="">Choose an item…</option>
      <?php foreach ($items as $i): ?><option value="<?php echo (int) $i['id']; ?>" data-cost="<?php echo (float) $i['buying_price']; ?>"><?php echo sh($i['name'] . ($i['barcode'] ? ' · ' . $i['barcode'] : '') . ' (' . stock_qty($i['quantity']) . ' ' . $i['unit'] . ')'); ?></option><?php endforeach; ?>
    </select></td>
    <td><input class="in" type="number" step="0.01" min="0" name="qty[]"></td>
    <td><input class="in" type="number" step="0.01" min="0" name="cost[]" placeholder="optional"></td>
    <td><button type="button" class="btn btn-l btn-sm" onclick="this.closest('tr').remove()" title="Remove"><i class="fas fa-xmark"></i></button></td>
  </tr></template>
  <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
    <button type="button" class="btn btn-l" id="addRow"><i class="fas fa-plus"></i> Another item</button>
    <button class="btn btn-p"><i class="fas fa-check"></i> Receive stock</button>
  </div>
</form>
<script>
(function () {
  var body = document.querySelector('#lines tbody'), t = document.getElementById('rowT');
  function add() { body.appendChild(t.content.cloneNode(true)); }
  document.getElementById('addRow').addEventListener('click', add);
  add(); add(); add();
})();
</script>
<?php endif;
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
