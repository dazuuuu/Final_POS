<?php
// public/super/restaurant/platters.php — family / group platters: several
// meals sold together for one price (e.g. "Family Feast: 4 ugali, 2 sukuma,
// 1kg mbuzi, 4 sodas free"). Each item can be marked FREE and/or a drink.
// Used when taking table bookings.
require_once __DIR__ . '/../../../app/app.php';
define('REST_CAP', Capabilities::TABLE_BOOKINGS);
require_once __DIR__ . '/_hospitality.php';

$self = public_url('super/restaurant/platters.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $action = $_POST['action'] ?? 'save';
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'delete') {
        $pdo->prepare('UPDATE rest_platters SET is_active = 0 WHERE id = ? AND tenant_id = ?')->execute([$id, $tid]);
        rest_flash('Platter removed.');
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $price = round((float) ($_POST['price'] ?? 0), 2);
        $dishes = [];
        $ds = $pdo->prepare('SELECT id, name FROM rest_dishes WHERE tenant_id = ?');
        $ds->execute([$tid]);
        foreach ($ds->fetchAll(PDO::FETCH_KEY_PAIR) as $did => $dn) { $dishes[(int) $did] = $dn; }
        $items = [];
        foreach ((array) ($_POST['items'] ?? []) as $it) {
            $did = (int) ($it['dish_id'] ?? 0);
            $iname = $did && isset($dishes[$did]) ? $dishes[$did] : trim((string) ($it['name'] ?? ''));
            $qty = round((float) ($it['quantity'] ?? 0), 3);
            if ($iname === '' || $qty <= 0) { continue; }
            $items[] = [$did && isset($dishes[$did]) ? $did : null, mb_substr($iname, 0, 120), $qty, !empty($it['is_free']) ? 1 : 0, !empty($it['is_drink']) ? 1 : 0];
        }
        if ($name === '' || $price <= 0) {
            rest_flash('Give the platter a name and a price.', 'error');
        } elseif (!$items) {
            rest_flash('Add at least one meal or drink to the platter.', 'error');
        } else {
            $pdo->beginTransaction();
            $fields = [mb_substr($name, 0, 120), trim((string) ($_POST['description'] ?? '')) ?: null, trim((string) ($_POST['serves'] ?? '')) ?: null, $price];
            if ($id) {
                $pdo->prepare('UPDATE rest_platters SET name = ?, description = ?, serves = ?, price = ? WHERE id = ? AND tenant_id = ?')->execute(array_merge($fields, [$id, $tid]));
                $pdo->prepare('DELETE FROM rest_platter_items WHERE platter_id = ?')->execute([$id]);
            } else {
                $pdo->prepare('INSERT INTO rest_platters (tenant_id, name, description, serves, price) VALUES (?,?,?,?,?)')->execute(array_merge([$tid], $fields));
                $id = (int) $pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO rest_platter_items (platter_id, dish_id, name, quantity, is_free, is_drink) VALUES (?,?,?,?,?,?)');
            foreach ($items as $it) { $ins->execute(array_merge([$id], $it)); }
            $pdo->commit();
            rest_flash('Platter "' . $name . '" saved.');
        }
    }
    header('Location: ' . $self);
    exit;
}

$platters = platter_list($pdo, $tid);
$dishes = $pdo->prepare('SELECT id, name, price FROM rest_dishes WHERE tenant_id = ? AND is_active = 1 ORDER BY name');
$dishes->execute([$tid]);
$dishes = $dishes->fetchAll(PDO::FETCH_ASSOC);
$edit = null;
foreach ($platters as $p) { if ((int) $p['id'] === (int) ($_GET['edit'] ?? 0)) { $edit = $p; } }

ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-6">
    <div class="card border-0 shadow-sm" style="border-radius:12px;"><div class="card-body p-4">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 fw-bold mb-0"><?php echo $edit ? 'Edit ' . hosp_h($edit['name']) : 'New family platter'; ?></h2>
        <?php if ($edit): ?><a href="<?php echo $self; ?>" class="btn btn-sm btn-link">Cancel</a><?php endif; ?>
      </div>
      <p class="small text-muted">Several meals for one price. Tick <em>Free</em> for items given at no charge and <em>Drink</em> for drinks — both show on the booking and receipt.</p>
      <form method="post" id="pForm">
        <?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int) ($edit['id'] ?? 0); ?>">
        <div class="row g-2 mb-2">
          <div class="col-7"><label class="form-label small mb-1">Name</label><input name="name" class="form-control" value="<?php echo hosp_h($edit['name'] ?? ''); ?>" placeholder="e.g. Family Feast" required></div>
          <div class="col-5"><label class="form-label small mb-1">Serves</label><input name="serves" class="form-control" value="<?php echo hosp_h($edit['serves'] ?? ''); ?>" placeholder="e.g. 6–8 people"></div>
        </div>
        <input name="description" class="form-control mb-3" value="<?php echo hosp_h($edit['description'] ?? ''); ?>" placeholder="Short description (optional)">
        <label class="form-label small fw-semibold mb-1">What's inside</label>
        <div id="pItems"></div>
        <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="addItem"><i class="fas fa-plus me-1"></i>Add item</button>
        <div class="d-flex justify-content-between small text-muted mb-1"><span>Menu value of paid items</span><span id="worth">—</span></div>
        <label class="form-label small fw-semibold mb-1">Platter price</label>
        <div class="input-group mb-3"><span class="input-group-text"><?php echo hosp_h($currency); ?></span><input name="price" type="number" step="0.01" min="0" class="form-control fw-bold" value="<?php echo hosp_h($edit['price'] ?? ''); ?>" required></div>
        <button class="btn btn-primary w-100">Save platter</button>
      </form>
    </div></div>
  </div>
  <div class="col-12 col-lg-6">
    <?php if (!$platters): ?><div class="text-muted small">No platters yet.</div><?php endif; ?>
    <?php foreach ($platters as $p): ?>
      <div class="card border-0 shadow-sm mb-3" style="border-radius:12px;"><div class="card-body p-3">
        <div class="d-flex justify-content-between"><div><strong><?php echo hosp_h($p['name']); ?></strong> <?php echo $p['serves'] ? '<span class="small text-muted">· serves ' . hosp_h($p['serves']) . '</span>' : ''; ?></div><strong><?php echo hosp_money($p['price']); ?></strong></div>
        <?php if ($p['description']): ?><div class="small text-muted"><?php echo hosp_h($p['description']); ?></div><?php endif; ?>
        <ul class="small mb-2 mt-2 ps-3">
          <?php foreach ($p['items'] as $i): ?>
            <li><?php echo rest_qty((float) $i['quantity']); ?> × <?php echo hosp_h($i['name']); ?>
              <?php echo $i['is_drink'] ? '<span class="badge bg-info text-dark">Drink</span>' : ''; ?> <?php echo $i['is_free'] ? '<span class="badge bg-success">FREE</span>' : ''; ?></li>
          <?php endforeach; ?>
        </ul>
        <div class="d-flex gap-2">
          <a href="<?php echo $self; ?>?edit=<?php echo (int) $p['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
          <form method="post" onsubmit="return confirm('Remove this platter?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button></form>
        </div>
      </div></div>
    <?php endforeach; ?>
  </div>
</div>
<template id="itemTpl">
  <div class="border rounded-3 p-2 mb-2 pitem">
    <div class="d-flex gap-2 mb-1">
      <select class="form-select form-select-sm" data-f="dish_id">
        <option value="0" data-price="0">Type an item…</option>
        <?php foreach ($dishes as $d): ?><option value="<?php echo (int) $d['id']; ?>" data-price="<?php echo (float) $d['price']; ?>"><?php echo hosp_h($d['name']); ?> (<?php echo number_format((float) $d['price']); ?>)</option><?php endforeach; ?>
      </select>
      <input type="number" step="0.001" min="0" class="form-control form-control-sm" style="max-width:80px" data-f="quantity" placeholder="Qty" value="1">
      <button type="button" class="btn btn-sm btn-link text-danger rm"><i class="fas fa-trash"></i></button>
    </div>
    <input class="form-control form-control-sm mb-1 nm" data-f="name" placeholder="Item, e.g. 1kg Mbuzi choma">
    <label class="small me-3"><input type="checkbox" class="form-check-input me-1" data-f="is_free" value="1">Free</label>
    <label class="small"><input type="checkbox" class="form-check-input me-1" data-f="is_drink" value="1">Drink</label>
  </div>
</template>
<script>
(function () {
  var box = document.getElementById('pItems'), tpl = document.getElementById('itemTpl'), n = 0, CUR = <?php echo json_encode($currency); ?>;
  function worth() {
    var t = 0;
    box.querySelectorAll('.pitem').forEach(function (el) {
      var s = el.querySelector('[data-f=dish_id]'), q = parseFloat(el.querySelector('[data-f=quantity]').value) || 0;
      if (!el.querySelector('[data-f=is_free]').checked) t += (parseFloat(s.options[s.selectedIndex].dataset.price) || 0) * q;
    });
    document.getElementById('worth').textContent = t ? CUR + ' ' + t.toLocaleString() : '—';
  }
  function add(d) {
    d = d || {};
    var el = tpl.content.firstElementChild.cloneNode(true), i = n++;
    el.querySelectorAll('[data-f]').forEach(function (f) {
      f.name = 'items[' + i + '][' + f.dataset.f + ']';
      var v = d[f.dataset.f];
      if (f.type === 'checkbox') f.checked = v == 1; else if (v !== undefined && v !== null) f.value = f.dataset.f === 'quantity' ? parseFloat(v) : v;
    });
    var sel = el.querySelector('[data-f=dish_id]'), nm = el.querySelector('.nm');
    var sync = function () { nm.style.display = sel.value === '0' ? '' : 'none'; worth(); };
    sel.addEventListener('change', sync); el.addEventListener('input', worth); el.addEventListener('change', worth);
    el.querySelector('.rm').onclick = function () { el.remove(); worth(); };
    box.appendChild(el); sync();
  }
  document.getElementById('addItem').onclick = function () { add(); };
  var start = <?php echo json_encode(array_map(fn($i) => ['dish_id' => (int) $i['dish_id'], 'name' => $i['name'], 'quantity' => $i['quantity'], 'is_free' => $i['is_free'], 'is_drink' => $i['is_drink']], $edit['items'] ?? [])); ?>;
  (start.length ? start : [{}]).forEach(add);
})();
</script>
<?php
rest_render('Family platters', ob_get_clean());
