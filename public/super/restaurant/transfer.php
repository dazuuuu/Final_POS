<?php
// public/super/restaurant/transfer.php — move stock out of the store to the
// Counter (to sell) or the Kitchen. A move is split into types, e.g. 1 crate
// of soda → Coke ×12 @ 80, Fanta ×12 @ 80; each type becomes its own counter
// item, so an order records exactly which type was sold.
require_once __DIR__ . '/_common.php';

$self = public_url('super/restaurant/transfer.php');
$error = '';

/**
 * Store → kitchen: each moved type tops up its recipe ingredient (created and
 * linked to the store item the first time), carrying the store's cost per
 * unit so dishes using it can be costed. Units: the store unit when it is
 * pcs/kg/g/l/ml, pieces when the store unit holds pieces (crate, packet…).
 */
function rest_kitchen_to_ingredients(PDO $pdo, int $tid, array $item, array $rows): void
{
    $ppu = (float) $item['pieces_per_unit'];
    $unit = $ppu > 0 ? 'pcs' : (isset(REST_UNITS[$item['unit']]) ? $item['unit'] : 'pcs');
    $uc = rest_stock_unit_cost($pdo, $tid, (int) $item['id']);
    if ($uc !== null && $ppu > 0) $uc = $uc / $ppu;
    $find = $pdo->prepare('SELECT * FROM rest_ingredients WHERE tenant_id = ? AND (stock_id = ? OR (stock_id IS NULL AND LOWER(name) = LOWER(?)))
                           ORDER BY stock_id IS NULL, id LIMIT 1');
    foreach ($rows as $r) {
        $name = $r['type'] !== '' ? $r['type'] : $item['name'];
        $find->execute([$tid, $r['type'] !== '' ? -1 : (int) $item['id'], $name]);
        $ing = $find->fetch(PDO::FETCH_ASSOC);
        if ($ing) {
            // Same family (kg↔g, l↔ml): convert into the ingredient's own unit.
            $qty = rest_to_base((float) $r['qty'], $unit === 'pcs' ? 'pc' : $unit, $ing);
            $ucIng = $uc !== null ? rest_to_base(1, $ing['unit'] === 'pcs' ? 'pc' : $ing['unit'], ['unit' => $unit, 'pieces_per_unit' => null]) * $uc : null;
            $pdo->prepare('UPDATE rest_ingredients SET quantity = quantity + ?, stock_id = COALESCE(stock_id, ?), unit_cost = COALESCE(?, unit_cost) WHERE id = ?')
                ->execute([$qty, (int) $item['id'], $ucIng, $ing['id']]);
        } else {
            $pdo->prepare('INSERT INTO rest_ingredients (tenant_id, stock_id, name, quantity, unit, unit_cost) VALUES (?,?,?,?,?,?)')
                ->execute([$tid, (int) $item['id'], $name, (float) $r['qty'], $unit, $uc]);
        }
    }
}

$stmt = $pdo->prepare('SELECT * FROM rest_stock WHERE tenant_id = ? ORDER BY name');
$stmt->execute([$tid]);
$stockList = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stockById = array_column($stockList, null, 'id');

// Kitchen requests ("Request from store" on the kitchen portal): mark sent or declined.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'kreq' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $pdo->prepare("UPDATE rest_kitchen_requests SET status = ?, done_by = ?, done_at = NOW() WHERE id = ? AND tenant_id = ? AND status = 'pending'")
        ->execute([($_POST['to'] ?? '') === 'declined' ? 'declined' : 'done', TenantContext::userId(), (int) ($_POST['id'] ?? 0), $tid]);
    header('Location: ' . $self);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $action = $_POST['action'] ?? 'move';

    if ($action === 'price') {
        $pdo->prepare('UPDATE rest_outlet_items SET selling_price = ? WHERE id = ? AND tenant_id = ?')
            ->execute([rest_money($_POST['selling_price'] ?? ''), (int) $_POST['id'], $tid]);
        rest_flash('Selling price updated.');
        header('Location: ' . $self . '#outlets');
        exit;
    }

    $stockId = (int) ($_POST['stock_id'] ?? 0);
    $outlet = isset(REST_OUTLETS[$_POST['outlet'] ?? '']) ? $_POST['outlet'] : '';
    $storeQty = (float) ($_POST['store_quantity'] ?? 0);
    $item = $stockById[$stockId] ?? null;

    $rows = [];
    foreach ((array) ($_POST['type_qty'] ?? []) as $i => $q) {
        $q = (float) $q;
        if ($q <= 0) continue;
        $rows[] = [
            'type'  => trim($_POST['type_name'][$i] ?? ''),
            'qty'   => $q,
            'price' => rest_money($_POST['type_price'][$i] ?? ''),
        ];
    }

    // Kitchen moves need no breakdown: the whole amount becomes kitchen stock
    // (pieces when the store unit holds pieces, e.g. a crate of 24 eggs).
    if ($outlet === 'kitchen' && !$rows && $item && $storeQty > 0) {
        $rows[] = ['type' => '', 'qty' => (float) $item['pieces_per_unit'] > 0 ? $storeQty * (float) $item['pieces_per_unit'] : $storeQty, 'price' => null];
    }
    if (!$item) {
        $error = 'Choose the stock item to move.';
    } elseif (!$outlet) {
        $error = 'Choose where the stock is going.';
    } elseif ($storeQty <= 0) {
        $error = 'Enter how much is leaving the store.';
    } elseif ($storeQty > (float) $item['quantity']) {
        $error = 'Only ' . rest_qty((float) $item['quantity']) . ' ' . $item['unit'] . ' of ' . $item['name'] . ' is in the store.';
    } elseif (!$rows) {
        $error = 'Add at least one type with a quantity.';
    } elseif ($outlet === 'counter' && array_filter($rows, fn($r) => $r['price'] === null)) {
        $error = 'Set a selling price for every type going to the counter.';
    } elseif (count(array_unique(array_column($rows, 'type'))) !== count($rows)) {
        $error = 'Each type can only be listed once.';
    } elseif ((float) $item['pieces_per_unit'] > 0 && array_sum(array_column($rows, 'qty')) > $storeQty * (float) $item['pieces_per_unit'] + 0.0001) {
        $error = rest_qty($storeQty) . ' ' . $item['unit'] . ' only hold ' . rest_qty($storeQty * (float) $item['pieces_per_unit']) . ' pieces, but the types add up to ' . rest_qty(array_sum(array_column($rows, 'qty'))) . '.';
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE rest_stock SET quantity = quantity - ? WHERE id = ? AND tenant_id = ?')->execute([$storeQty, $stockId, $tid]);
            $up = $pdo->prepare(
                'INSERT INTO rest_outlet_items (tenant_id, outlet, stock_id, type_name, quantity, selling_price) VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), selling_price = COALESCE(VALUES(selling_price), selling_price)'
            );
            foreach ($rows as $r) {
                $up->execute([$tid, $outlet, $stockId, $r['type'], $r['qty'], $r['price']]);
            }
            $pdo->prepare('INSERT INTO rest_transfers (tenant_id, stock_id, outlet, store_quantity, breakdown, user_id) VALUES (?,?,?,?,?,?)')
                ->execute([$tid, $stockId, $outlet, $storeQty, json_encode($rows), TenantContext::userId()]);
            if ($outlet === 'kitchen') {
                rest_kitchen_to_ingredients($pdo, $tid, $item, $rows);
            }
            $pdo->commit();
            rest_flash('Moved ' . rest_qty($storeQty) . ' ' . $item['unit'] . ' of ' . $item['name'] . ' to the ' . ($outlet === 'counter' ? 'counter' : 'kitchen') . '.');
            header('Location: ' . $self);
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'Could not move the stock: ' . $e->getMessage();
        }
    }
}

$stmt = $pdo->prepare(
    'SELECT o.*, s.name AS stock_name, s.image_path FROM rest_outlet_items o JOIN rest_stock s ON s.id = o.stock_id
      WHERE o.tenant_id = ? ORDER BY s.name, o.type_name'
);
$stmt->execute([$tid]);
$outletItems = ['counter' => [], 'kitchen' => []];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $outletItems[$o['outlet']][] = $o;
}

$stmt = $pdo->prepare(
    'SELECT t.*, s.name AS stock_name, s.unit FROM rest_transfers t JOIN rest_stock s ON s.id = t.stock_id
      WHERE t.tenant_id = ? ORDER BY t.created_at DESC LIMIT 15'
);
$stmt->execute([$tid]);
$recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

$post = $_SERVER['REQUEST_METHOD'] === 'POST' && $error;
$kreq = $pdo->prepare("SELECT r.*, u.username FROM rest_kitchen_requests r LEFT JOIN users u ON u.id = r.requested_by WHERE r.tenant_id = ? AND r.status = 'pending' ORDER BY r.id");
$kreq->execute([$tid]);
$kreq = $kreq->fetchAll(PDO::FETCH_ASSOC);
ob_start();
if ($kreq): ?>
  <div class="card mb-3 border-warning"><div class="card-body p-3">
    <h2 class="h6 fw-bold"><i class="fas fa-fire-burner me-1"></i>Kitchen is asking for</h2>
    <?php foreach ($kreq as $r): ?>
      <div class="d-flex justify-content-between align-items-center border-top py-2 small">
        <span><b><?php echo htmlspecialchars($r['name']); ?></b><?php echo $r['quantity'] !== null ? ' · ' . rest_qty((float) $r['quantity']) . ' ' . htmlspecialchars((string) $r['unit']) : ''; ?>
          <span class="text-muted"> — <?php echo htmlspecialchars(($r['username'] ?? '') . ' ' . date('j M H:i', strtotime($r['created_at']))); ?><?php echo $r['note'] ? ' · ' . htmlspecialchars($r['note']) : ''; ?></span></span>
        <form method="post" class="d-flex gap-1"><?php echo csrf_field(); ?><input type="hidden" name="act" value="kreq"><input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
          <button class="btn btn-sm btn-success" name="to" value="done" title="Move it below first">Sent</button>
          <button class="btn btn-sm btn-outline-secondary" name="to" value="declined">Decline</button></form>
      </div>
    <?php endforeach; ?>
    <p class="small text-muted mb-0 mt-1">Move the stock to the Kitchen below, then mark the request Sent.</p>
  </div></div>
<?php endif;
?>
<div class="row g-4">
  <div class="col-12 col-xl-5">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-1">Move stock out of the store</h2>
        <p class="text-muted small mb-3">E.g. take 1 crate of soda to the counter and list the types inside it with their selling prices.
          Moving to the <strong>Kitchen</strong> (e.g. 30 kg onions) tops up that ingredient, with its cost per kg, ready for recipes — no types needed.</p>
        <?php if ($error): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (!$stockList): ?>
          <div class="text-muted">Nothing in the store yet. <a href="<?php echo public_url('super/restaurant/stock.php'); ?>">Receive stock first.</a></div>
        <?php else: ?>
        <form method="post" id="moveForm">
          <?php echo csrf_field(); ?>
          <div class="mb-3">
            <label class="form-label">Stock item</label>
            <select name="stock_id" class="form-select" id="stockSel" required>
              <option value="">— Choose —</option>
              <?php foreach ($stockList as $s): ?>
                <option value="<?php echo (int) $s['id']; ?>" data-unit="<?php echo htmlspecialchars($s['unit']); ?>" data-ppu="<?php echo (float) $s['pieces_per_unit']; ?>" data-name="<?php echo htmlspecialchars($s['name']); ?>"
                  <?php echo $post && (int) $_POST['stock_id'] === (int) $s['id'] ? 'selected' : ''; ?> <?php echo (float) $s['quantity'] <= 0 ? 'disabled' : ''; ?>>
                  <?php echo htmlspecialchars($s['name']) . ' — ' . rest_qty((float) $s['quantity']) . ' ' . htmlspecialchars($s['unit']) . ' in store'; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label d-block">Going to</label>
            <?php foreach (REST_OUTLETS as $k => $label): ?>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="outlet" value="<?php echo $k; ?>" id="o_<?php echo $k; ?>" <?php echo ($post ? ($_POST['outlet'] ?? '') : 'counter') === $k ? 'checked' : ''; ?>>
                <label class="form-check-label" for="o_<?php echo $k; ?>"><?php echo $label; ?></label>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="mb-3">
            <label class="form-label">Leaving the store</label>
            <div class="input-group">
              <input name="store_quantity" type="number" step="0.001" min="0" class="form-control" placeholder="e.g. 1" value="<?php echo $post ? htmlspecialchars($_POST['store_quantity'] ?? '') : ''; ?>" required>
              <span class="input-group-text" id="unitLbl">units</span>
            </div>
          </div>

          <label class="form-label">Types inside <span class="text-muted small">(e.g. Coke, Fanta, Sprite — leave the type blank if there's only one)</span></label>
          <table class="table table-sm align-middle mb-2" id="typesTbl">
            <thead><tr class="small text-muted"><th>Type</th><th style="width:90px;">Qty</th><th style="width:120px;" class="price-col">Selling price</th><th style="width:36px;"></th></tr></thead>
            <tbody></tbody>
          </table>
          <div class="small mb-2" id="pieceHint"></div>
          <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="addType"><i class="fas fa-plus me-1"></i>Add type</button>
          <div><button class="btn btn-primary"><i class="fas fa-right-left me-1"></i>Move stock</button></div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-7" id="outlets">
    <?php foreach (REST_OUTLETS as $k => $label): ?>
    <div class="card border-0 shadow-sm mb-4" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-3"><i class="fas <?php echo $k === 'counter' ? 'fa-cash-register' : 'fa-fire-burner'; ?> me-2 text-primary"></i><?php echo $k === 'counter' ? 'At the counter' : 'In the kitchen'; ?> <span class="badge bg-light text-dark"><?php echo count($outletItems[$k]); ?></span></h2>
        <?php if (!$outletItems[$k]): ?>
          <div class="text-muted small">Nothing here yet.</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead><tr class="text-muted small text-uppercase"><th>Item</th><th>Type</th><th>Qty</th><th>Selling price</th></tr></thead>
            <tbody>
            <?php foreach ($outletItems[$k] as $o): ?>
              <tr>
                <td class="fw-semibold"><?php echo htmlspecialchars($o['stock_name']); ?></td>
                <td><?php echo $o['type_name'] !== '' ? htmlspecialchars($o['type_name']) : '<span class="text-muted">—</span>'; ?></td>
                <td><span class="badge <?php echo (float) $o['quantity'] > 0 ? 'bg-success' : 'bg-secondary'; ?>"><?php echo rest_qty((float) $o['quantity']); ?></span></td>
                <td>
                  <form method="post" class="d-flex gap-1" style="max-width:170px;">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="price"><input type="hidden" name="id" value="<?php echo (int) $o['id']; ?>">
                    <input name="selling_price" type="number" step="0.01" min="0" class="form-control form-control-sm" value="<?php echo $o['selling_price'] === null ? '' : (float) $o['selling_price']; ?>" placeholder="—">
                    <button class="btn btn-sm btn-outline-secondary" title="Save price"><i class="fas fa-check"></i></button>
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
    <?php endforeach; ?>

    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-3">Recent moves</h2>
        <?php if (!$recent): ?><div class="text-muted small">No moves yet.</div><?php endif; ?>
        <?php foreach ($recent as $t): $parts = json_decode($t['breakdown'] ?? '[]', true) ?: []; ?>
          <div class="border-bottom py-2 small">
            <strong><?php echo rest_qty((float) $t['store_quantity']) . ' ' . htmlspecialchars($t['unit'] . ' ' . $t['stock_name']); ?></strong>
            → <?php echo $t['outlet'] === 'counter' ? 'Counter' : 'Kitchen'; ?>
            <span class="text-muted">· <?php echo date('d M H:i', strtotime($t['created_at'])); ?></span><br>
            <span class="text-muted"><?php echo htmlspecialchars(implode(', ', array_map(fn($p) => ($p['type'] !== '' ? $p['type'] : 'units') . ' ×' . rest_qty((float) $p['qty']), $parts))); ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var tbody = document.querySelector('#typesTbl tbody');
  if (!tbody) return;
  var prev = <?php echo json_encode($post ? array_map(null, (array) ($_POST['type_name'] ?? []), (array) ($_POST['type_qty'] ?? []), (array) ($_POST['type_price'] ?? [])) : []); ?>;
  function esc(v) { return String(v == null ? '' : v).replace(/"/g, '&quot;'); }
  function addRow(t, q, p) {
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><input name="type_name[]" class="form-control form-control-sm" placeholder="e.g. Coke" value="' + esc(t) + '"></td>'
      + '<td><input name="type_qty[]" type="number" step="0.001" min="0" class="form-control form-control-sm" value="' + esc(q) + '"></td>'
      + '<td class="price-col"><input name="type_price[]" type="number" step="0.01" min="0" class="form-control form-control-sm" placeholder="<?php echo $currency; ?>" value="' + esc(p) + '"></td>'
      + '<td><button type="button" class="btn btn-sm btn-link text-danger p-0"><i class="fas fa-times"></i></button></td>';
    tr.querySelector('button').onclick = function () { if (tbody.rows.length > 1) { tr.remove(); hint(); } };
    tr.querySelector('input[name="type_qty[]"]').oninput = hint;
    tbody.appendChild(tr);
    syncOutlet();
  }
  function syncOutlet() {
    var counter = document.getElementById('o_counter').checked;
    document.querySelectorAll('.price-col').forEach(function (c) { c.style.display = counter ? '' : 'none'; });
  }
  document.getElementById('addType').onclick = function () { addRow('', '', ''); };
  document.querySelectorAll('input[name=outlet]').forEach(function (r) { r.onchange = syncOutlet; });
  var sel = document.getElementById('stockSel');
  function syncUnit() { var o = sel.options[sel.selectedIndex]; document.getElementById('unitLbl').textContent = (o && o.dataset.unit) || 'units'; }
  // "1 crate = 24 pcs · listed 20" so the types add up to what's inside.
  function hint() {
    var o = sel.options[sel.selectedIndex], ppu = o ? parseFloat(o.dataset.ppu || 0) : 0, box = document.getElementById('pieceHint');
    var q = parseFloat(document.querySelector('input[name=store_quantity]').value || 0);
    if (!ppu || !q) { box.textContent = ''; return; }
    var listed = [].reduce.call(document.querySelectorAll('input[name="type_qty[]"]'), function (s, i) { return s + (parseFloat(i.value) || 0); }, 0);
    var cap = ppu * q;
    box.className = 'small mb-2 ' + (listed > cap ? 'text-danger' : 'text-muted');
    box.textContent = q + ' ' + o.dataset.unit + ' = ' + cap + ' pcs · listed ' + listed + (listed > cap ? ' (too many)' : '');
  }
  document.querySelector('input[name=store_quantity]').oninput = hint;
  sel.onchange = function () { syncUnit(); hint(); }; syncUnit();
  if (prev.length) prev.forEach(function (r) { addRow(r[0], r[1], r[2]); }); else addRow('', '', '');
})();
</script>
<?php
rest_render('Transfers', ob_get_clean());
