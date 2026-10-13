<?php
// public/super/warehouse/index.php — the owner's central store. Record stock
// at buying price only, then move it into any shop; the selling price
// (retail and/or wholesale — at least one) is set at the moment of moving.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::tenant(); // owner only
if (!ShopContext::isMainShop()) {
    // The warehouse belongs to the Admin portal, not to individual shops.
    $_SESSION['flash']['error'] = 'The warehouse is in the Admin portal. Switch to it from the shop menu at the top.';
    header('Location: ' . public_url('super/shops/'));
    exit;
}

$pdo = Database::pdo();
$W = new Models\WarehouseModel($pdo);
// Electronics package: serial / IMEI per unit in the warehouse, moved with the stock.
$EL = Models\ElectronicsModel::enabled() ? new Models\ElectronicsModel($pdo) : null;
$homeId = (int) ShopContext::homeTenantId();
$selfUrl = public_url('super/warehouse/');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'receive') {
        $serials = $EL ? Models\ElectronicsModel::parseList((string) ($_POST['serials'] ?? '')) : [];
        $res = ['ok' => true];
        if (count($serials) > (int) floor((float) ($_POST['quantity'] ?? 0))) {
            $res = ['ok' => false, 'error' => count($serials) . ' serials entered for ' . (int) floor((float) ($_POST['quantity'] ?? 0)) . ' units.'];
        } elseif ($serials && ($taken = $EL->taken($serials))) {
            $res = ['ok' => false, 'error' => 'Already recorded: ' . implode(', ', $taken) . '.'];
        }
        if ($res['ok']) { $res = $W->receive($_POST); }
        $msg = 'Stock recorded in the warehouse.';
        if ($res['ok'] && $EL && (!empty($_POST['is_serialized']) || $serials)) {
            $months = Models\ElectronicsModel::warrantyFromInput($_POST['warranty_years'] ?? 0, $_POST['warranty_months'] ?? 0);
            $pdo->prepare('UPDATE warehouse_items SET is_serialized = 1, warranty_months = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$months > 0 ? $months : null, (int) $res['item_id'], $homeId]);
            $sn = $EL->addWarehouseSerials($homeId, (int) $res['item_id'], $serials);
            if (!$sn['ok']) { $res = ['ok' => false, 'error' => 'Stock saved, but serials were not: ' . $sn['error']]; }
            elseif ($sn['added']) { $msg .= ' ' . $sn['added'] . ' serial(s) recorded.'; }
        }
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? $msg : $res['error'];
    } elseif ($action === 'serials' && $EL) {
        $sn = $EL->addWarehouseSerials($homeId, (int) ($_POST['item_id'] ?? 0), Models\ElectronicsModel::parseList((string) ($_POST['serials'] ?? '')));
        $_SESSION['flash'][$sn['ok'] ? 'success' : 'error'] = $sn['ok'] ? $sn['added'] . ' serial(s) recorded.' : $sn['error'];
    } elseif ($action === 'transfer') {
        $picked = $EL ? (array) ($_POST['serial_ids'] ?? []) : [];
        $res = $W->transfer((int) ($_POST['item_id'] ?? 0), (int) ($_POST['shop_id'] ?? 0), (float) ($_POST['quantity'] ?? 0),
                            $_POST['retail_price'] ?? '', $_POST['wholesale_price'] ?? '', $picked);
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Stock moved — it is now on sale in that shop and on its catalogue.' : $res['error'];
    } elseif ($action === 'delete') {
        $res = $W->deleteItem((int) ($_POST['item_id'] ?? 0));
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Item removed.' : $res['error'];
    }
    header('Location: ' . $selfUrl);
    exit;
}

$items = $W->items();
$totals = $W->totals();
$moves = $W->movements();
$shops = ShopContext::shops(true);
$__tenant = (new Models\TenantModel($pdo))->find(TenantContext::tenantId());
$currency = $__tenant['currency'] ?? 'KES';
$money = fn($n) => htmlspecialchars($currency) . ' ' . number_format((float) $n, 0);
$qty = fn($q) => rtrim(rtrim(number_format((float) $q, 2), '0'), '.');
$names = array_column($items, 'name');
$cats = $W->categories(); // the same categories every shop uses

$page_title = 'Warehouse · Admin portal';
ob_start();
?>
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-4"><div class="tk-stat"><div class="tk-stat-icon tk-blue"><i class="fas fa-boxes-stacked"></i></div><div class="tk-stat-body"><div class="tk-stat-num"><?php echo (int) $totals['items']; ?></div><div class="tk-stat-label">Items in warehouse</div></div></div></div>
  <div class="col-6 col-lg-4"><div class="tk-stat"><div class="tk-stat-icon tk-greenbg"><i class="fas fa-coins"></i></div><div class="tk-stat-body"><div class="tk-stat-num"><?php echo $money($totals['value']); ?></div><div class="tk-stat-label">Stock value (at cost)</div></div></div></div>
  <div class="col-12 col-lg-4"><div class="tk-stat"><div class="tk-stat-icon tk-orange"><i class="fas fa-store"></i></div><div class="tk-stat-body"><div class="tk-stat-num"><?php echo count($shops); ?></div><div class="tk-stat-label">Shops you can supply</div></div></div></div>
</div>

<div class="row g-4">
  <div class="col-12 col-lg-4">
    <div class="card mb-4"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-1">Record stock</h2>
      <p class="small text-muted mb-3">Buying price only — you set the selling price when you move it into a shop. Same name = restock.</p>
      <form method="post" class="row g-2">
        <input type="hidden" name="action" value="receive">
        <div class="col-12"><input name="name" list="whNames" class="form-control" placeholder="Item name" required></div>
        <div class="col-6"><input type="number" step="0.01" min="0.01" name="quantity" class="form-control" placeholder="Quantity" required></div>
        <div class="col-6">
          <select name="unit" class="form-select">
            <?php foreach (Models\ProductModel::UNITS as $u): ?><option value="<?php echo $u; ?>"><?php echo ucfirst($u); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-6"><input type="number" step="0.01" min="0" name="buying_price" class="form-control" placeholder="Buying price / unit" required></div>
        <div class="col-6">
          <select name="category_id" class="form-select" id="whCatSel">
            <option value="">No category</option>
            <?php foreach (['subject' => 'Categories', 'stationery' => 'Stationery categories'] as $type => $label):
                $group = array_filter($cats, fn($c) => ($c['type'] ?? 'subject') === $type); if (!$group) { continue; } ?>
            <optgroup label="<?php echo $label; ?>">
              <?php foreach ($group as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
            <option value="new">+ New category…</option>
          </select>
        </div>
        <div class="col-12" id="whNewCat" style="display:none;"><input name="new_category" class="form-control" placeholder="New category name (added to every shop)"></div>
        <div class="col-12"><input name="notes" class="form-control" placeholder="Notes, e.g. supplier (optional)"></div>
        <?php if ($EL): ?>
        <div class="col-12">
          <div class="p-2 rounded" style="background:#f0fdf4;border:1px solid #bbf0d1;">
            <div class="form-check form-switch mb-1">
              <input class="form-check-input" type="checkbox" role="switch" name="is_serialized" value="1" id="whSn">
              <label class="form-check-label small fw-semibold" for="whSn"><i class="fas fa-mobile-screen me-1"></i>Track serial / IMEI</label>
            </div>
            <div class="mb-1"><?php echo Models\ElectronicsModel::warrantyInputs('', 12); ?></div>
            <textarea name="serials" class="form-control form-control-sm text-uppercase" rows="2" placeholder="Serial / IMEI, one per line (optional)"></textarea>
          </div>
        </div>
        <?php endif; ?>
        <div class="col-12"><button class="btn btn-primary w-100"><i class="fas fa-truck-ramp-box me-1"></i>Save to warehouse</button></div>
      </form>
      <datalist id="whNames"><?php foreach ($names as $n): ?><option value="<?php echo htmlspecialchars($n); ?>"><?php endforeach; ?></datalist>
    </div></div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card mb-4"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Warehouse stock</h2>
      <?php if (!$items): ?>
        <div class="text-muted small">Nothing recorded yet.</div>
      <?php else: ?>
      <div class="wh-list">
        <?php foreach ($items as $it): $iid = (int) $it['id']; $empty = (float) $it['quantity'] <= 0; ?>
        <div class="wh-row">
          <div class="d-flex justify-content-between align-items-center gap-2">
            <div class="min-w-0">
              <div class="fw-semibold text-truncate"><?php echo htmlspecialchars($it['name']); ?></div>
              <div class="small text-muted"><?php echo htmlspecialchars($it['category'] ?: 'Uncategorised'); ?> · cost <?php echo $money($it['buying_price']); ?>/<?php echo htmlspecialchars($it['unit']); ?></div>
            </div>
            <div class="text-end flex-shrink-0">
              <div class="fw-bold <?php echo $empty ? 'text-danger' : ''; ?>"><?php echo $qty($it['quantity']); ?> <span class="small text-muted"><?php echo htmlspecialchars($it['unit']); ?></span></div>
              <div class="small text-muted"><?php echo $money((float) $it['quantity'] * (float) $it['buying_price']); ?></div>
            </div>
          </div>
          <div class="d-flex gap-2 mt-2">
            <?php if (!$empty && $shops): ?>
            <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#mv<?php echo $iid; ?>"><i class="fas fa-right-left me-1"></i>Move to shop</button>
            <?php endif; ?>
            <?php if ($empty): ?>
            <form method="post" onsubmit="return confirm('Remove this item from the warehouse list?');">
              <input type="hidden" name="action" value="delete"><input type="hidden" name="item_id" value="<?php echo $iid; ?>">
              <button class="btn btn-sm btn-outline-danger">Remove</button>
            </form>
            <?php endif; ?>
          </div>
          <?php $whSerials = $EL ? $EL->warehouseSerials($homeId, $iid) : []; ?>
          <?php if ($EL && !empty($it['is_serialized'])): ?>
            <div class="small text-muted mt-1"><i class="fas fa-mobile-screen me-1"></i><?php echo count($whSerials); ?> of <?php echo $qty($it['quantity']); ?> units have a serial
              <?php if (count($whSerials) < (int) floor((float) $it['quantity'])): ?>· <a href="#" data-bs-toggle="collapse" data-bs-target="#sn<?php echo $iid; ?>">add serials</a><?php endif; ?></div>
            <form method="post" class="collapse mt-2" id="sn<?php echo $iid; ?>">
              <input type="hidden" name="action" value="serials"><input type="hidden" name="item_id" value="<?php echo $iid; ?>">
              <textarea name="serials" class="form-control form-control-sm text-uppercase mb-2" rows="3" placeholder="One serial / IMEI per line"></textarea>
              <button class="btn btn-sm btn-outline-success">Save serials</button>
            </form>
          <?php endif; ?>
          <form method="post" class="collapse mt-2 wh-move" id="mv<?php echo $iid; ?>">
            <input type="hidden" name="action" value="transfer"><input type="hidden" name="item_id" value="<?php echo $iid; ?>">
            <?php if ($whSerials): ?>
              <div class="mb-2">
                <label class="form-label small mb-1">Units to move <span class="text-muted">(tick each one — the quantity follows)</span></label>
                <div class="d-flex flex-wrap gap-2">
                  <?php foreach ($whSerials as $u): ?>
                    <label class="small border rounded px-2 py-1"><input type="checkbox" class="form-check-input me-1 wh-sn" name="serial_ids[]" value="<?php echo (int) $u['id']; ?>"><?php echo htmlspecialchars($u['serial']); ?></label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
            <div class="row g-2">
              <div class="col-12 col-md-4">
                <label class="form-label small mb-1">Shop</label>
                <select name="shop_id" class="form-select form-select-sm" required>
                  <?php foreach ($shops as $s): ?><option value="<?php echo (int) $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="col-4 col-md-2">
                <label class="form-label small mb-1">Qty</label>
                <input type="number" step="0.01" min="0.01" max="<?php echo (float) $it['quantity']; ?>" name="quantity" class="form-control form-control-sm<?php echo $whSerials ? ' wh-sn-qty' : ''; ?>" required<?php echo $whSerials ? ' readonly' : ''; ?>>
              </div>
              <div class="col-4 col-md-3">
                <label class="form-label small mb-1">Retail price</label>
                <input type="number" step="0.01" min="0" name="retail_price" class="form-control form-control-sm wh-price" placeholder="optional">
              </div>
              <div class="col-4 col-md-3">
                <label class="form-label small mb-1">Wholesale price</label>
                <input type="number" step="0.01" min="0" name="wholesale_price" class="form-control form-control-sm wh-price" placeholder="optional">
              </div>
              <div class="col-12 small text-muted">Fill retail, wholesale or both (at least one). Cost is <?php echo $money($it['buying_price']); ?>.</div>
              <div class="col-12"><button class="btn btn-sm btn-success w-100">Move stock</button></div>
            </div>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div></div>

    <div class="card"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Recent movements</h2>
      <?php if (!$moves): ?>
        <div class="text-muted small">No movements yet.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr class="text-uppercase small"><th>When</th><th>Item</th><th>Type</th><th class="text-end">Qty</th><th>Prices</th></tr></thead>
          <tbody>
          <?php foreach ($moves as $m): ?>
            <tr>
              <td class="small text-muted"><?php echo date('j M, g:i a', strtotime($m['created_at'])); ?></td>
              <td class="small fw-semibold"><?php echo htmlspecialchars($m['item_name']); ?></td>
              <td class="small"><?php echo $m['type'] === 'in'
                  ? '<span class="badge bg-info text-dark">Received</span>'
                  : '<span class="badge bg-success">To ' . htmlspecialchars($m['shop_name'] ?? 'shop') . '</span>'; ?></td>
              <td class="small text-end"><?php echo $qty($m['quantity']); ?></td>
              <td class="small text-muted">
                cost <?php echo $money($m['buying_price']); ?>
                <?php if ($m['retail_price'] !== null): ?> · R <?php echo $money($m['retail_price']); ?><?php endif; ?>
                <?php if ($m['wholesale_price'] !== null): ?> · W <?php echo $money($m['wholesale_price']); ?><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<style>
.wh-row{border-bottom:1px solid #eee;padding:12px 0;}
.wh-row:last-child{border-bottom:0;}
.wh-move{background:#f7f9f8;border-radius:10px;padding:12px;}
</style>
<script>
// Serial-tracked items: the quantity moved is the number of units ticked.
document.querySelectorAll('.wh-move').forEach(function (f) {
  var q = f.querySelector('.wh-sn-qty'); if (!q) { return; }
  f.addEventListener('change', function () { var n = f.querySelectorAll('.wh-sn:checked').length; q.value = n || ''; });
});
(function () {
  var sel = document.getElementById('whCatSel'), box = document.getElementById('whNewCat');
  sel && sel.addEventListener('change', function () { box.style.display = sel.value === 'new' ? '' : 'none'; });
})();
document.querySelectorAll('.wh-move').forEach(function (f) {
  f.addEventListener('submit', function (e) {
    var p = f.querySelectorAll('.wh-price');
    if (!(parseFloat(p[0].value) > 0) && !(parseFloat(p[1].value) > 0)) {
      e.preventDefault();
      alert('Enter a retail price, a wholesale price, or both.');
    }
  });
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
