<?php
// public/stock/transfer.php — Stock out of the current store, two ways:
//   Transfer: to one of your shops or warehouses. It leaves here and arrives
//             there straight away, with the delivery (who delivered it,
//             vehicle, delivery note) recorded.
//   Clear:    out of the store (issued, used, written off). The quantity goes
//             down — to 0 with "All" — but the item stays in the list, and the
//             movement stays in the history.
require_once __DIR__ . '/_boot.php';
stock_guard('stock.transfer');
$store = stock_store();
$all = StockControl::stores();
$targets = array_diff_key($all, [$store => true]);
$mode = ($_GET['mode'] ?? $_POST['mode'] ?? 'transfer') === 'clear' ? 'clear' : 'transfer';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!stock_csrf_ok()) stock_go('transfer.php?mode=' . $mode, 'This page expired — try again.', 'err');
    $meta = ['delivered_by' => $_POST['delivered_by'] ?? '', 'vehicle' => $_POST['vehicle'] ?? '', 'doc_no' => $_POST['doc_no'] ?? ''];
    $lines = (array) ($_POST['qty'] ?? []);
    foreach ((array) ($_POST['all'] ?? []) as $id => $on) { if ($on) $lines[$id] = 'all'; }
    if ($mode === 'clear') {
        [$ok, $res] = StockControl::clear($store, $lines, (string) ($_POST['reason'] ?? ''), $meta, (array) ($_POST['price'] ?? []));
        stock_go($ok ? 'history.php?q=' . urlencode($res) : 'transfer.php?mode=clear', $ok ? 'Stock cleared (' . $res . ').' : $res, $ok ? 'ok' : 'err');
    }
    $to = (int) ($_POST['to'] ?? 0);
    foreach ($lines as $id => $q) { // "All" in a transfer = everything here
        if ($q === 'all') { $it = StockControl::item($store, (int) $id); $lines[$id] = $it ? (float) $it['quantity'] : 0; }
    }
    [$ok, $res] = StockControl::transfer($store, $to, $lines, (string) ($_POST['note'] ?? ''), $meta);
    stock_go($ok ? 'deliveries.php' : 'transfer.php', $ok ? 'Sent to ' . ($all[$to]['name'] ?? '') . ' (' . $res . ').' : $res, $ok ? 'ok' : 'err');
}

$items = array_filter(StockControl::items($store), fn($i) => (float) $i['quantity'] > 0);
$title = 'Stock out';
$tab = 'transfer.php';
ob_start();
?>
<div class="sc-head">
  <div><h1>Stock out</h1><p>From <b><?php echo sh($all[$store]['name'] ?? ''); ?></b> (<?php echo StockControl::kindOf($all[$store] ?? []); ?>).<?php if (StockControl::isAdmin() && count($all) > 1): ?> To send from another store, switch store in the sidebar.<?php endif; ?></p></div>
  <div class="seg"><a class="<?php echo $mode === 'transfer' ? 'on' : ''; ?>" href="?mode=transfer"><i class="fas fa-right-left"></i> Transfer to a store</a><a class="<?php echo $mode === 'clear' ? 'on' : ''; ?>" href="?mode=clear"><i class="fas fa-box-open"></i> Clear from store</a></div>
</div>
<?php if ($mode === 'transfer' && !$targets): ?>
  <div class="panel empty">You have only one store. <?php echo StockControl::isAdmin() ? '<a href="' . stock_url('stores.php') . '">Add a shop or warehouse</a> to move stock between them.' : 'Ask an administrator to add another store.'; ?></div>
<?php elseif (!$items): ?>
  <div class="panel empty">Nothing in stock here.</div>
<?php else: ?>
<form method="post" class="panel" id="trf"><?php echo stock_csrf_field(); ?><input type="hidden" name="mode" value="<?php echo $mode; ?>">
  <?php if ($mode === 'transfer'): ?>
    <div class="row">
      <div><label class="f">Send to</label><select class="in" name="to" required>
        <?php foreach ($targets as $sid => $s): ?><option value="<?php echo $sid; ?>"><?php echo sh($s['name']); ?> — <?php echo StockControl::kindOf($s); ?></option><?php endforeach; ?></select></div>
      <div><label class="f">Delivered by <span class="mute">(optional)</span></label><input class="in" name="delivered_by" placeholder="Driver / staff name"></div>
      <div><label class="f">Vehicle <span class="mute">(optional)</span></label><input class="in" name="vehicle" placeholder="e.g. KCA 123X"></div>
      <div><label class="f">Delivery note no. <span class="mute">(optional)</span></label><input class="in" name="doc_no" placeholder="e.g. DN-0045"></div>
    </div>
    <div class="row"><div><label class="f">Note <span class="mute">(optional)</span></label><input class="in" name="note"></div>
      <div><label class="f">Find</label><input class="in" id="find" placeholder="Type to filter items"></div></div>
  <?php else: ?>
    <div class="row">
      <div><label class="f">Reason / issued to *</label><input class="in" name="reason" required placeholder="e.g. Issued to Accounts dept, Used on site, Damaged, Expired"></div>
      <div><label class="f">Collected by <span class="mute">(optional)</span></label><input class="in" name="delivered_by" placeholder="Who took it"></div>
      <div><label class="f">Reference <span class="mute">(optional)</span></label><input class="in" name="doc_no" placeholder="e.g. requisition no."></div>
      <div><label class="f">Find</label><input class="in" id="find" placeholder="Type to filter items"></div>
    </div>
    <p class="small mute" style="margin-top:-4px">Clearing takes the quantity out of this store. The item stays in your list, and the movement stays in the history.<?php if (StockControl::can('stock.finance')): ?> If it was sold, type the selling price per unit and the income is recorded in Finance.<?php endif; ?></p>
  <?php endif; ?>
  <div class="wrap-t" style="max-height:60vh;overflow:auto"><table class="t">
    <thead><tr><th>Item</th><th>Code</th><th class="r">In this store</th><th style="width:150px"><?php echo $mode === 'clear' ? 'Clear' : 'Send'; ?></th><th style="width:70px">All</th><?php if ($mode === 'clear' && StockControl::can('stock.finance')): ?><th style="width:170px">Sold at / unit <span class="mute">(optional)</span></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($items as $i): ?>
      <tr data-n="<?php echo sh(strtolower($i['name'] . ' ' . $i['barcode'])); ?>">
        <td style="font-weight:600"><?php echo sh($i['name']); ?></td>
        <td class="mute small"><?php echo sh($i['barcode'] ?: '—'); ?></td>
        <td class="r num"><?php echo stock_qty($i['quantity']) . ' ' . sh($i['unit']); ?></td>
        <td><input class="in q" type="number" step="0.01" min="0" max="<?php echo (float) $i['quantity']; ?>" name="qty[<?php echo (int) $i['id']; ?>]" placeholder="0"></td>
        <td><label class="small"><input type="checkbox" class="a" name="all[<?php echo (int) $i['id']; ?>]" value="1" data-max="<?php echo (float) $i['quantity']; ?>"> All</label></td>
        <?php if ($mode === 'clear' && StockControl::can('stock.finance')): ?><td><input class="in" type="number" step="0.01" min="0" name="price[<?php echo (int) $i['id']; ?>]" placeholder="<?php echo ($pp = (float) $i['retail_price']) > 0 ? number_format($pp, 2, '.', '') : 'optional'; ?>"></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
    <button class="btn btn-p"><i class="fas <?php echo $mode === 'clear' ? 'fa-box-open' : 'fa-paper-plane'; ?>"></i> <?php echo $mode === 'clear' ? 'Clear stock' : 'Send stock'; ?></button>
    <button type="button" class="btn btn-l" id="allBtn">Select everything</button>
  </div>
</form>
<script>
document.getElementById('find').addEventListener('input', function () {
  var q = this.value.toLowerCase().trim();
  document.querySelectorAll('#trf tbody tr').forEach(function (r) { r.style.display = !q || r.dataset.n.indexOf(q) !== -1 ? '' : 'none'; });
});
document.querySelectorAll('#trf .a').forEach(function (c) {
  c.addEventListener('change', function () { var q = c.closest('tr').querySelector('.q'); q.value = c.checked ? c.dataset.max : ''; q.readOnly = c.checked; });
});
document.getElementById('allBtn').addEventListener('click', function () {
  document.querySelectorAll('#trf tbody tr').forEach(function (r) { if (r.style.display !== 'none') { var c = r.querySelector('.a'); c.checked = true; c.dispatchEvent(new Event('change')); } });
});
document.getElementById('trf').addEventListener('submit', function (e) {
  var any = Array.prototype.some.call(this.querySelectorAll('.q'), function (i) { return parseFloat(i.value) > 0; });
  if (!any) { e.preventDefault(); alert('Enter how much of at least one item.'); return; }
  <?php if ($mode === 'clear'): ?>if (!confirm('Clear this stock out of the store?')) e.preventDefault();<?php endif; ?>
});
</script>
<?php endif; ?>
<style>
.seg{display:flex;background:#f3f4f6;border:1px solid var(--line);border-radius:10px;padding:3px;flex-wrap:wrap;}
.seg a{padding:7px 14px;border-radius:8px;text-decoration:none;color:var(--mute);font-weight:500;font-size:.85rem;display:flex;gap:6px;align-items:center;}
.seg a.on{background:#fff;color:var(--ink);box-shadow:0 1px 2px rgba(0,0,0,.08);}
</style>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
