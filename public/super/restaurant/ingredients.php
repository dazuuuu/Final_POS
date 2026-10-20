<?php
// public/super/restaurant/ingredients.php — the ingredients dishes are made from.
// Cost (what it was bought for) and charge (price per unit, used to suggest dish
// prices) are optional. "Pieces per unit" (e.g. 24 onions in 1 kg) lets
// recipes use pieces — ½ onion, 1 onion — of an ingredient stocked by weight.
require_once __DIR__ . '/_common.php';

$self = public_url('super/restaurant/ingredients.php');
$error = '';
$editId = (int) ($_GET['edit'] ?? 0);
$old = ['name' => '', 'quantity' => '', 'unit' => 'pcs', 'pieces_per_unit' => '', 'cost_price' => '', 'charge_price' => ''];
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM rest_ingredients WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$editId, $tid]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC) ?: $old;
    if (empty($old['id'])) $editId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        $used = $pdo->prepare('SELECT COUNT(*) FROM rest_dish_ingredients di JOIN rest_dishes d ON d.id = di.dish_id WHERE di.ingredient_id = ? AND d.tenant_id = ?');
        $used->execute([$id, $tid]);
        if ($used->fetchColumn()) {
            rest_flash('That ingredient is used in a dish. Remove it from the dish first.', 'error');
        } else {
            $pdo->prepare('DELETE FROM rest_ingredients WHERE id = ? AND tenant_id = ?')->execute([$id, $tid]);
            rest_flash('Ingredient deleted.');
        }
        header('Location: ' . $self);
        exit;
    }

    $old = [
        'name'         => trim($_POST['name'] ?? ''),
        'quantity'     => trim($_POST['quantity'] ?? ''),
        'unit'         => isset(REST_UNITS[$_POST['unit'] ?? '']) ? $_POST['unit'] : 'pcs',
        'pieces_per_unit' => trim($_POST['pieces_per_unit'] ?? ''),
        'cost_price'   => trim($_POST['cost_price'] ?? ''),
        'charge_price' => trim($_POST['charge_price'] ?? ''),
    ];
    $ppu = $old['unit'] !== 'pcs' && (float) $old['pieces_per_unit'] > 0 ? (float) $old['pieces_per_unit'] : null;
    if ($old['name'] === '') {
        $error = 'Ingredient name is required.';
    } elseif ($old['quantity'] !== '' && (!is_numeric($old['quantity']) || $old['quantity'] < 0)) {
        $error = 'Quantity must be a number.';
    } else {
        $row = [$old['name'], (float) $old['quantity'], $old['unit'], $ppu, rest_money($old['cost_price']), rest_money($old['charge_price'])];
        if ($editId) {
            $pdo->prepare('UPDATE rest_ingredients SET name=?, quantity=?, unit=?, pieces_per_unit=?, cost_price=?, charge_price=? WHERE id=? AND tenant_id=?')
                ->execute([...$row, $editId, $tid]);
            rest_flash('Ingredient "' . $old['name'] . '" updated.');
        } else {
            $pdo->prepare('INSERT INTO rest_ingredients (name, quantity, unit, pieces_per_unit, cost_price, charge_price, tenant_id) VALUES (?,?,?,?,?,?,?)')
                ->execute([...$row, $tid]);
            rest_flash('Ingredient "' . $old['name'] . '" added.');
        }
        header('Location: ' . $self);
        exit;
    }
}

$stmt = $pdo->prepare(
    'SELECT i.*, (SELECT COUNT(*) FROM rest_dish_ingredients di WHERE di.ingredient_id = i.id) AS dish_count
       FROM rest_ingredients i WHERE i.tenant_id = ? ORDER BY i.name'
);
$stmt->execute([$tid]);
$ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-4">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-1"><?php echo $editId ? 'Edit ingredient' : 'Add an ingredient'; ?></h2>
        <p class="text-muted small mb-3">Ingredients make up your dishes, e.g. bun, meat pie, onion, tomato.</p>
        <?php if ($error): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <form method="post" action="<?php echo $self . ($editId ? '?edit=' . $editId : ''); ?>">
          <?php echo csrf_field(); ?>
          <div class="mb-3">
            <label class="form-label">Ingredient name</label>
            <input name="name" class="form-control" placeholder="e.g. Onion" value="<?php echo htmlspecialchars($old['name']); ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Quantity in stock</label>
            <div class="input-group">
              <input name="quantity" type="number" step="0.001" min="0" class="form-control" placeholder="e.g. 5" value="<?php echo $old['quantity'] === '' ? '' : (float) $old['quantity']; ?>">
              <select name="unit" class="form-select" style="max-width:110px;" id="unitSel">
                <?php foreach (REST_UNITS as $u => $label): ?><option value="<?php echo $u; ?>" <?php echo $old['unit'] === $u ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mb-3" id="ppuBox">
            <label class="form-label">Pieces in 1 <span class="unit-lbl">kg</span> <span class="text-muted">(optional)</span></label>
            <input name="pieces_per_unit" type="number" step="0.001" min="0" class="form-control" placeholder="e.g. 24 onions make 1 kg" value="<?php echo $old['pieces_per_unit'] === '' || $old['pieces_per_unit'] === null ? '' : (float) $old['pieces_per_unit']; ?>">
            <div class="form-text">Lets dishes use pieces (½ onion, 1 onion). Leave blank to work in <span class="unit-lbl">kg</span> only.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Bought for <span class="text-muted">(optional)</span></label>
            <div class="input-group"><span class="input-group-text"><?php echo $currency; ?></span>
              <input name="cost_price" type="number" step="0.01" min="0" class="form-control" placeholder="Total you paid" value="<?php echo htmlspecialchars((string) $old['cost_price']); ?>"></div>
          </div>
          <div class="mb-3">
            <label class="form-label">Charge per <span class="unit-lbl">kg</span> <span class="text-muted">(optional)</span></label>
            <div class="input-group"><span class="input-group-text"><?php echo $currency; ?></span>
              <input name="charge_price" type="number" step="0.01" min="0" class="form-control" placeholder="Used to suggest dish prices" value="<?php echo htmlspecialchars((string) $old['charge_price']); ?>"></div>
            <div class="form-text">Recipes multiply this by the amount used to suggest a dish price.</div>
          </div>
          <button class="btn btn-primary"><?php echo $editId ? 'Save changes' : 'Add ingredient'; ?></button>
          <?php if ($editId): ?><a class="btn btn-link" href="<?php echo $self; ?>">Cancel</a><?php endif; ?>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-3">Ingredients <span class="badge bg-light text-dark"><?php echo count($ingredients); ?></span></h2>
        <?php if (!$ingredients): ?>
          <div class="text-muted">No ingredients yet. Add your first one on the left.</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead><tr class="text-muted small text-uppercase"><th>Name</th><th>In stock</th><?php if (PageGuard::seesMoney()): ?><th>Bought for</th><?php endif; ?><th>Charge</th><th class="text-center">Dishes</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($ingredients as $i): ?>
              <tr>
                <td class="fw-semibold"><?php echo htmlspecialchars($i['name']); ?></td>
                <td>
                  <span class="badge <?php echo (float) $i['quantity'] > 0 ? 'bg-success' : 'bg-danger'; ?>"><?php echo rest_qty((float) $i['quantity']) . ' ' . htmlspecialchars(REST_UNITS[$i['unit']] ?? $i['unit']); ?></span>
                  <?php if ((float) $i['pieces_per_unit'] > 0): ?><div class="small text-muted">≈ <?php echo rest_qty(round((float) $i['quantity'] * (float) $i['pieces_per_unit'], 1)); ?> pcs (<?php echo rest_qty((float) $i['pieces_per_unit']); ?>/<?php echo $i['unit']; ?>)</div><?php endif; ?>
                </td>
                <?php if (PageGuard::seesMoney()): ?><td><?php echo rest_price($i['cost_price'] === null ? null : (float) $i['cost_price'], $currency); ?></td><?php endif; ?>
                <td><?php echo rest_price($i['charge_price'] === null ? null : (float) $i['charge_price'], $currency); ?><?php if ($i['charge_price'] !== null): ?><small class="text-muted">/<?php echo $i['unit']; ?></small><?php endif; ?></td>
                <td class="text-center"><span class="badge bg-light text-dark"><?php echo (int) $i['dish_count']; ?></span></td>
                <td class="text-end text-nowrap">
                  <a class="btn btn-sm btn-outline-secondary" href="<?php echo $self . '?edit=' . (int) $i['id']; ?>"><i class="fas fa-pen"></i></a>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this ingredient?');">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $i['id']; ?>">
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
  var sel = document.getElementById('unitSel');
  function sync() {
    var u = sel.value, label = sel.options[sel.selectedIndex].text;
    document.getElementById('ppuBox').style.display = u === 'pcs' ? 'none' : '';
    document.querySelectorAll('.unit-lbl').forEach(function (e) { e.textContent = u === 'pcs' ? 'piece' : label; });
  }
  sel.onchange = sync; sync();
})();
</script>
<?php
rest_render('Ingredients', ob_get_clean());
