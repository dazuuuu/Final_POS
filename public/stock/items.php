<?php
// public/stock/items.php — the items of the current store: search, add, edit.
// Quantities change only by receiving, transferring and counting.
require_once __DIR__ . '/_boot.php';
stock_guard();
$store = stock_store();
$canEdit = StockControl::can('stock.items');
$value = StockControl::can('stock.value');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    if (!stock_csrf_ok()) stock_go('items.php', 'This page expired — try again.', 'err');
    $id = (int) ($_POST['id'] ?? 0) ?: null;
    [$ok, $res] = StockControl::saveItem($store, $_POST, $id);
    if (!$ok) stock_go('items.php' . ($id ? '?edit=' . $id : '?add=1'), $res, 'err');
    if (!$id && (float) ($_POST['opening'] ?? 0) > 0 && StockControl::can('stock.receive')) {
        StockControl::receive($store, [['item_id' => $res, 'qty' => $_POST['opening'], 'cost' => $_POST['cost'] ?? '']], '', 'Opening stock');
    }
    stock_go('items.php', $id ? 'Item saved.' : 'Item added.');
}

$q = trim((string) ($_GET['q'] ?? ''));
$cat = (int) ($_GET['cat'] ?? 0);
$cats = StockControl::categories($store);
if (!$cat && !empty($_GET['cat_name'])) { $cat = (int) (array_search((string) $_GET['cat_name'], $cats, true) ?: 0); } // from the Categories page
$items = StockControl::items($store, $q, $cat);

if (!empty($_GET['excel']) || !empty($_GET['pdf'])) {
    $head = ['Item', 'Code', 'Category', 'Unit', 'In stock', 'Reorder level', 'Selling price'];
    if ($value) $head = array_merge($head, ['Cost price', 'Stock value']);
    $rows = array_map(function ($i) use ($value) {
        $r = [$i['name'], $i['barcode'], $i['category_name'], $i['unit'], (float) $i['quantity'], (int) $i['low_stock_threshold'], (float) $i['retail_price'] ?: (float) $i['selling_price']];
        if ($value) { $r[] = (float) $i['buying_price']; $r[] = round((float) $i['quantity'] * (float) $i['buying_price'], 2); }
        return $r;
    }, $items);
    $sheet = ['Items' => ['headers' => $head, 'rows' => $rows, 'money' => $value ? [6, 7, 8] : [6], 'totals' => $value ? [4, 8] : [4]]];
    $name = StockControl::stores()[$store]['name'] ?? 'store';
    $file = 'items-' . preg_replace('/\W+/', '-', strtolower($name)) . '-' . date('Y-m-d');
    if (!empty($_GET['pdf'])) PdfExport::download($file . '.pdf', 'Items — ' . $name, date('j M Y'), $sheet, ['Items' => (string) count($items)]);
    ExcelExport::download($file . '.xlsx', $sheet);
}
$edit = isset($_GET['edit']) ? StockControl::item($store, (int) $_GET['edit']) : null;
$adding = $canEdit && (isset($_GET['add']) || $edit);
$tv = 0.0;
foreach ($items as $i) $tv += (float) $i['quantity'] * (float) $i['buying_price'];

$title = 'Items';
$tab = 'items.php';
ob_start();
?>
<div class="sc-head">
  <div><h1>Items</h1><p><?php echo count($items); ?> item<?php echo count($items) === 1 ? '' : 's'; ?><?php echo $value ? ' · worth ' . stock_money($tv) . ' at cost' : ''; ?></p></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn btn-l" href="?<?php echo sh(http_build_query(array_filter(['q' => $q, 'cat' => $cat, 'excel' => 1]))); ?>"><i class="fas fa-file-excel" style="color:#16a34a"></i> Excel</a>
    <a class="btn btn-l" href="?<?php echo sh(http_build_query(array_filter(['q' => $q, 'cat' => $cat, 'pdf' => 1]))); ?>"><i class="fas fa-file-pdf" style="color:#dc2626"></i> PDF</a>
    <?php if ($canEdit && !$adding): ?><a class="btn btn-p" href="?add=1"><i class="fas fa-plus"></i> New item</a><?php endif; ?>
  </div>
</div>

<?php if ($adding): $e = $edit ?? []; ?>
<div class="panel">
  <h2><?php echo $edit ? 'Edit ' . sh($edit['name']) : 'New item'; ?></h2>
  <form method="post"><?php echo stock_csrf_field(); ?>
    <?php if ($edit): ?><input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>"><?php endif; ?>
    <div class="row">
      <div><label class="f">Name *</label><input class="in" name="name" value="<?php echo sh($e['name'] ?? ''); ?>" required autofocus></div>
      <div><label class="f">Code / SKU</label><input class="in" name="code" value="<?php echo sh($e['barcode'] ?? ''); ?>" placeholder="optional"></div>
      <div><label class="f">Category</label><input class="in" name="category" list="cats" value="<?php echo sh($e['category_name'] ?? ''); ?>" placeholder="e.g. Stationery">
        <datalist id="cats"><?php foreach ($cats as $c): ?><option value="<?php echo sh($c); ?>"><?php endforeach; ?></datalist></div>
      <div><label class="f">Unit</label><select class="in" name="unit"><?php foreach (StockControl::UNITS as $u): ?><option <?php echo ($e['unit'] ?? 'piece') === $u ? 'selected' : ''; ?>><?php echo $u; ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="row">
      <div><label class="f">Selling price <span class="mute">(optional)</span></label><input class="in" type="number" step="0.01" min="0" name="price" value="<?php echo sh(($e['retail_price'] ?? 0) > 0 ? $e['retail_price'] : ''); ?>" placeholder="leave empty if not needed"></div>
      <?php if ($value): ?><div><label class="f">Cost price <span class="mute">(optional)</span></label><input class="in" type="number" step="0.01" min="0" name="cost" value="<?php echo sh(($e['buying_price'] ?? 0) > 0 ? $e['buying_price'] : ''); ?>" placeholder="leave empty if not needed"></div><?php endif; ?>
      <div><label class="f">Reorder level</label><input class="in" type="number" min="0" name="reorder" value="<?php echo sh($e['low_stock_threshold'] ?? ''); ?>" placeholder="warn at or below"></div>
      <?php if (!$edit && StockControl::can('stock.receive')): ?><div><label class="f">Opening quantity</label><input class="in" type="number" step="0.01" min="0" name="opening" placeholder="0"></div><?php endif; ?>
      <div><label class="f">Notes</label><input class="in" name="description" value="<?php echo sh($e['description'] ?? ''); ?>"></div>
    </div>
    <div style="display:flex;gap:8px"><button class="btn btn-p"><i class="fas fa-floppy-disk"></i> Save</button><a class="btn btn-l" href="<?php echo stock_url('items.php'); ?>">Cancel</a></div>
    <?php if ($edit): ?><p class="small mute" style="margin:10px 0 0">Quantity (<?php echo stock_qty($edit['quantity']) . ' ' . sh($edit['unit']); ?>) changes only by Receive, Transfer and Count — so every change is in the history.</p><?php endif; ?>
  </form>
</div>
<?php endif; ?>

<div class="panel">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
    <input class="in" name="q" value="<?php echo sh($q); ?>" placeholder="Search name or code" style="max-width:280px">
    <select class="in" name="cat" style="max-width:220px" onchange="this.form.submit()"><option value="0">All categories</option>
      <?php foreach ($cats as $cid => $c): ?><option value="<?php echo $cid; ?>" <?php echo $cat === $cid ? 'selected' : ''; ?>><?php echo sh($c); ?></option><?php endforeach; ?></select>
    <button class="btn btn-l"><i class="fas fa-magnifying-glass"></i> Search</button>
  </form>
  <?php if (!$items): ?><div class="empty"><?php echo $q || $cat ? 'No items match.' : 'No items in this store yet.'; ?></div><?php else: ?>
  <div class="wrap-t"><table class="t">
    <thead><tr><th>Item</th><th>Code</th><th>Category</th><th class="r">In stock</th><th class="r">Reorder at</th><th class="r">Price</th><?php if ($value): ?><th class="r">Cost</th><th class="r">Value</th><?php endif; ?><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $i): $isLow = $i['low_stock_threshold'] > 0 && $i['quantity'] <= $i['low_stock_threshold']; ?>
      <tr>
        <td style="font-weight:600"><?php echo sh($i['name']); ?></td>
        <td class="mute small"><?php echo sh($i['barcode'] ?: '—'); ?></td>
        <td class="small"><?php echo sh($i['category_name'] ?: '—'); ?></td>
        <td class="r num <?php echo $isLow ? 'low' : ''; ?>"><?php echo stock_qty($i['quantity']) . ' <span class="mute small">' . sh($i['unit']) . '</span>'; ?></td>
        <td class="r num mute"><?php echo $i['low_stock_threshold'] ? (int) $i['low_stock_threshold'] : '—'; ?></td>
        <td class="r num"><?php $pr = (float) $i['retail_price'] ?: (float) $i['selling_price']; echo $pr > 0 ? number_format($pr, 2) : '<span class="mute">—</span>'; ?></td>
        <?php if ($value): ?><td class="r num"><?php echo (float) $i['buying_price'] > 0 ? number_format((float) $i['buying_price'], 2) : '<span class="mute">—</span>'; ?></td><td class="r num"><?php echo (float) $i['buying_price'] > 0 ? number_format((float) $i['quantity'] * (float) $i['buying_price'], 2) : '<span class="mute">—</span>'; ?></td><?php endif; ?>
        <td class="r" style="white-space:nowrap">
          <a class="btn btn-l btn-sm" href="<?php echo stock_url('history.php?q=' . urlencode($i['name'])); ?>" title="History"><i class="fas fa-clock-rotate-left"></i></a>
          <?php if ($canEdit): ?><a class="btn btn-l btn-sm" href="?edit=<?php echo (int) $i['id']; ?>" title="Edit"><i class="fas fa-pen"></i></a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
