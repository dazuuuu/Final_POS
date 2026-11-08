<?php
// public/stock/stores.php — the organisation's stores: add, rename, see each one's stock.
require_once __DIR__ . '/_boot.php';
stock_guard(null, true);
$store = stock_store();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!stock_csrf_ok()) stock_go('stores.php', 'This page expired — try again.', 'err');
    if (($_POST['act'] ?? '') === 'add') {
        [$ok, $res] = StockControl::createStore((string) ($_POST['name'] ?? ''), (string) ($_POST['location'] ?? ''), (string) ($_POST['kind'] ?? 'shop'));
        stock_go('stores.php', $ok ? 'Store added.' : $res, $ok ? 'ok' : 'err');
    }
    if (($_POST['act'] ?? '') === 'rename') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '' && isset(StockControl::stores()[$id])) {
            Database::pdo()->prepare('UPDATE tenants SET name = ?, address = ?, store_kind = ? WHERE id = ?')
                ->execute([$name, trim((string) ($_POST['location'] ?? '')) ?: null, ($_POST['kind'] ?? '') === 'warehouse' ? 'warehouse' : 'shop', $id]);
        }
        stock_go('stores.php', 'Store saved.');
    }
}

$stores = StockControl::stores();
$sum = StockControl::summary(array_keys($stores));
$title = 'Stores';
$tab = 'stores.php';
ob_start();
?>
<div class="sc-head"><div><h1>Stores</h1><p>Your warehouses and shops. Each keeps its own stock; move stock between them with Stock out → Transfer.</p></div></div>
<div class="grid g2">
  <div class="panel">
    <h2>Your stores</h2>
    <div class="wrap-t"><table class="t">
      <thead><tr><th>Store</th><th class="r">Items</th><th class="r">Units</th><th class="r">Value</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($stores as $sid => $s): $r = $sum[$sid]; ?>
        <tr>
          <td><b><?php echo sh($s['name']); ?></b> <span class="pill <?php echo StockControl::kindOf($s) === 'Warehouse' ? 'p-mv' : 'p-in'; ?>"><?php echo StockControl::kindOf($s); ?></span><br><span class="small mute"><?php echo sh($s['address'] ?: ''); ?></span></td>
          <td class="r num"><?php echo number_format($r['items']); ?></td>
          <td class="r num"><?php echo stock_qty($r['units']); ?></td>
          <td class="r num"><?php echo stock_money($r['value']); ?></td>
          <td class="r"><button class="btn btn-l btn-sm" type="button" onclick="var f=document.getElementById('ren<?php echo $sid; ?>');f.hidden=!f.hidden"><i class="fas fa-pen"></i></button></td>
        </tr>
        <tr id="ren<?php echo $sid; ?>" hidden><td colspan="5">
          <form method="post" style="display:flex;gap:8px;flex-wrap:wrap"><?php echo stock_csrf_field(); ?><input type="hidden" name="act" value="rename"><input type="hidden" name="id" value="<?php echo $sid; ?>">
            <input class="in" name="name" value="<?php echo sh($s['name']); ?>" style="max-width:220px" required>
            <input class="in" name="location" value="<?php echo sh($s['address'] ?? ''); ?>" placeholder="Location" style="max-width:220px">
            <select class="in" name="kind" style="max-width:150px"><option value="warehouse" <?php echo StockControl::kindOf($s) === 'Warehouse' ? 'selected' : ''; ?>>Warehouse</option><option value="shop" <?php echo StockControl::kindOf($s) === 'Shop' ? 'selected' : ''; ?>>Shop</option></select>
            <button class="btn btn-d btn-sm">Save</button></form>
        </td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="panel">
    <h2>Add a warehouse or shop</h2>
    <form method="post"><?php echo stock_csrf_field(); ?><input type="hidden" name="act" value="add">
      <label class="f">Type</label>
      <select class="in" name="kind" style="margin-bottom:12px"><option value="warehouse">Warehouse — where stock is kept</option><option value="shop" selected>Shop — where stock goes out</option></select>
      <label class="f">Name</label><input class="in" name="name" required placeholder="e.g. Nakuru warehouse" style="margin-bottom:12px">
      <label class="f">Location</label><input class="in" name="location" placeholder="optional" style="margin-bottom:14px">
      <button class="btn btn-p"><i class="fas fa-plus"></i> Add</button>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
