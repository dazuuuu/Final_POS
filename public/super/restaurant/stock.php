<?php
// public/super/restaurant/stock.php — goods received into the store (soda
// crates, flour, cooking oil…). Moving them to the Counter/Kitchen happens on
// the Transfers page.
require_once __DIR__ . '/_common.php';

$self = public_url('super/restaurant/stock.php');
$SUP = new Models\SupplierModel($pdo);
$error = '';
$old = ['name' => '', 'quantity' => '', 'unit' => 'pcs', 'pieces_per_unit' => '', 'supplier_id' => '', 'new_supplier' => '', 'cost_price' => ''];

/** Existing supplier id, or create one from the typed name. Returns [id|null, error|null]. */
function rest_supplier(Models\SupplierModel $SUP, string $id, string $newName): array
{
    if (trim($newName) !== '') {
        $res = $SUP->create($newName);
        return $res['ok'] ? [(int) $res['id'], null] : [null, $res['error']];
    }
    return [$id !== '' && $SUP->find((int) $id) ? (int) $id : null, null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'restock') {
        $id = (int) $_POST['id'];
        $qty = (float) ($_POST['quantity'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM rest_stock WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, $tid]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($item && $qty > 0) {
            $cost = rest_money($_POST['cost_price'] ?? '');
            $pdo->prepare('UPDATE rest_stock SET quantity = quantity + ? WHERE id = ?')->execute([$qty, $id]);
            $pdo->prepare('INSERT INTO rest_stock_receipts (tenant_id, stock_id, quantity, supplier_id, cost_price, user_id) VALUES (?,?,?,?,?,?)')
                ->execute([$tid, $id, $qty, $item['supplier_id'], $cost, TenantContext::userId()]);
            rest_flash('Received ' . rest_qty($qty) . ' ' . $item['unit'] . ' of ' . $item['name'] . '.');
        } else {
            rest_flash('Enter a quantity greater than zero.', 'error');
        }
        header('Location: ' . $self);
        exit;
    }

    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        $moved = $pdo->prepare('SELECT COUNT(*) FROM rest_transfers WHERE stock_id = ? AND tenant_id = ?');
        $moved->execute([$id, $tid]);
        if ($moved->fetchColumn()) {
            rest_flash('Stock that has been moved to the counter or kitchen can\'t be deleted.', 'error');
        } else {
            $pdo->prepare('DELETE FROM rest_stock WHERE id = ? AND tenant_id = ?')->execute([$id, $tid]);
            $pdo->prepare('DELETE FROM rest_stock_receipts WHERE stock_id = ? AND tenant_id = ?')->execute([$id, $tid]);
            rest_flash('Stock item deleted.');
        }
        header('Location: ' . $self);
        exit;
    }

    $old = [
        'name'         => trim($_POST['name'] ?? ''),
        'quantity'     => trim($_POST['quantity'] ?? ''),
        'unit'         => isset(REST_STOCK_UNITS[$_POST['unit'] ?? '']) ? $_POST['unit'] : 'pcs',
        'supplier_id'  => $_POST['supplier_id'] ?? '',
        'new_supplier' => trim($_POST['new_supplier'] ?? ''),
        'cost_price'   => trim($_POST['cost_price'] ?? ''),
        'pieces_per_unit' => trim($_POST['pieces_per_unit'] ?? ''),
    ];
    $ppu = in_array($old['unit'], REST_CONTAINER_UNITS, true) && (float) $old['pieces_per_unit'] > 0 ? (float) $old['pieces_per_unit'] : null;
    if ($old['name'] === '') {
        $error = 'Stock name is required.';
    } elseif (!is_numeric($old['quantity']) || $old['quantity'] <= 0) {
        $error = 'Enter the quantity received.';
    } else {
        [$supplierId, $error] = rest_supplier($SUP, $old['supplier_id'], $old['new_supplier']);
        $image = null;
        if (!$error) {
            [$image, $error] = rest_image('image', $tid);
        }
        if (!$error) {
            $cost = rest_money($old['cost_price']);
            $pdo->prepare('INSERT INTO rest_stock (tenant_id, name, unit, pieces_per_unit, quantity, supplier_id, image_path, cost_price) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$tid, $old['name'], $old['unit'], $ppu, (float) $old['quantity'], $supplierId, $image, $cost]);
            $pdo->prepare('INSERT INTO rest_stock_receipts (tenant_id, stock_id, quantity, supplier_id, cost_price, user_id) VALUES (?,?,?,?,?,?)')
                ->execute([$tid, (int) $pdo->lastInsertId(), (float) $old['quantity'], $supplierId, $cost, TenantContext::userId()]);
            rest_flash('"' . $old['name'] . '" received into the store.');
            header('Location: ' . $self);
            exit;
        }
    }
}

$suppliers = $SUP->all([], 'name ASC');
$stmt = $pdo->prepare('SELECT s.*, sp.name AS supplier_name FROM rest_stock s LEFT JOIN suppliers sp ON sp.id = s.supplier_id WHERE s.tenant_id = ? ORDER BY s.name');
$stmt->execute([$tid]);
$stock = $stmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-4">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-1">Receive stock</h2>
        <p class="text-muted small mb-3">Goods arriving at the store, e.g. a crate of soda or a bag of flour.</p>
        <?php if ($error): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <form method="post" enctype="multipart/form-data">
          <?php echo csrf_field(); ?>
          <div class="mb-3">
            <label class="form-label">Stock name</label>
            <input name="name" class="form-control" placeholder="e.g. Soda 300ml" value="<?php echo htmlspecialchars($old['name']); ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Quantity received</label>
            <div class="input-group">
              <input name="quantity" type="number" step="0.001" min="0" class="form-control" placeholder="e.g. 2" value="<?php echo htmlspecialchars($old['quantity']); ?>" required>
              <select name="unit" class="form-select" style="max-width:120px;" id="unitSel">
                <?php foreach (REST_STOCK_UNITS as $u => $label): ?><option value="<?php echo $u; ?>" <?php echo $old['unit'] === $u ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mb-3" id="ppuBox">
            <label class="form-label">How many inside 1 <span id="ppuLbl">crate</span>? <span class="text-muted">(optional)</span></label>
            <input name="pieces_per_unit" type="number" step="1" min="0" class="form-control" placeholder="e.g. 24 bottles" value="<?php echo htmlspecialchars((string) $old['pieces_per_unit']); ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Supplier <span class="text-muted">(optional)</span></label>
            <select name="supplier_id" class="form-select mb-2">
              <option value="">— None —</option>
              <?php foreach ($suppliers as $s): ?><option value="<?php echo (int) $s['id']; ?>" <?php echo (string) $old['supplier_id'] === (string) $s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option><?php endforeach; ?>
            </select>
            <input name="new_supplier" class="form-control form-control-sm" placeholder="…or type a new supplier name" value="<?php echo htmlspecialchars($old['new_supplier']); ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Bought for <span class="text-muted">(optional)</span></label>
            <div class="input-group"><span class="input-group-text"><?php echo $currency; ?></span>
              <input name="cost_price" type="number" step="0.01" min="0" class="form-control" placeholder="Total you paid" value="<?php echo htmlspecialchars($old['cost_price']); ?>"></div>
          </div>
          <div class="mb-3">
            <label class="form-label">Image <span class="text-muted">(optional)</span></label>
            <input name="image" type="file" accept="image/*" class="form-control">
          </div>
          <button class="btn btn-primary">Receive stock</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2 class="h5 mb-0">In the store <span class="badge bg-light text-dark"><?php echo count($stock); ?></span></h2>
          <a class="btn btn-sm btn-primary" href="<?php echo public_url('super/restaurant/transfer.php'); ?>"><i class="fas fa-right-left me-1"></i>Move to counter / kitchen</a>
        </div>
        <?php if (!$stock): ?>
          <div class="text-muted">The store is empty. Receive your first stock on the left.</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead><tr class="text-muted small text-uppercase"><th></th><th>Item</th><th>In store</th><th>Supplier</th><?php if (PageGuard::seesMoney()): ?><th>Bought for</th><?php endif; ?><th>Receive more</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($stock as $s): $img = settings_asset_url($s['image_path']); ?>
              <tr>
                <td style="width:52px;">
                  <?php if ($img): ?><img src="<?php echo htmlspecialchars($img); ?>" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:8px;">
                  <?php else: ?><div style="width:44px;height:44px;border-radius:8px;background:#f1f3f5;display:flex;align-items:center;justify-content:center;color:#adb5bd;"><i class="fas fa-box"></i></div><?php endif; ?>
                </td>
                <td class="fw-semibold"><?php echo htmlspecialchars($s['name']); ?></td>
                <td><span class="badge <?php echo (float) $s['quantity'] > 0 ? 'bg-success' : 'bg-secondary'; ?>"><?php echo rest_qty((float) $s['quantity']) . ' ' . htmlspecialchars($s['unit']); ?></span>
                  <?php if ((float) $s['pieces_per_unit'] > 0): ?><div class="small text-muted">= <?php echo rest_qty((float) $s['quantity'] * (float) $s['pieces_per_unit']); ?> pcs (<?php echo rest_qty((float) $s['pieces_per_unit']); ?> each)</div><?php endif; ?></td>
                <td class="text-muted small"><?php echo htmlspecialchars($s['supplier_name'] ?? '—'); ?></td>
                <?php if (PageGuard::seesMoney()): ?><td><?php echo rest_price($s['cost_price'] === null ? null : (float) $s['cost_price'], $currency); ?></td><?php endif; ?>
                <td>
                  <form method="post" class="d-flex gap-1" style="min-width:170px;">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="restock"><input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                    <input name="quantity" type="number" step="0.001" min="0" class="form-control form-control-sm" placeholder="Qty" required>
                    <input name="cost_price" type="number" step="0.01" min="0" class="form-control form-control-sm" placeholder="Cost">
                    <button class="btn btn-sm btn-outline-primary" title="Receive"><i class="fas fa-plus"></i></button>
                  </form>
                </td>
                <td>
                  <form method="post" onsubmit="return confirm('Delete this stock item?');">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php
?>
<script>
(function () {
  var sel = document.getElementById('unitSel'), containers = <?php echo json_encode(REST_CONTAINER_UNITS); ?>;
  function sync() {
    var on = containers.indexOf(sel.value) !== -1;
    document.getElementById('ppuBox').style.display = on ? '' : 'none';
    document.getElementById('ppuLbl').textContent = sel.value === 'boxes' ? 'box' : sel.value.replace(/s$/, '');
  }
  sel.onchange = sync; sync();
})();
</script>
<?php
rest_render('Store stock', ob_get_clean());
