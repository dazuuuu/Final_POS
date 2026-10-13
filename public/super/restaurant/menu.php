<?php
// public/super/restaurant/menu.php — dishes on the menu. A dish shows as
// name + image + price; its default ingredients (e.g. burger = 1 bun,
// 1 meat pie, ½ onion, 1 tomato) appear in the order details, where the
// customer can drop some ("Burger — without Onion").
//
// Recipe lines can be measured in pieces (needs "pieces per kg" on the
// ingredient), kg/g or l/ml; each is converted to the ingredient's own unit
// (base_quantity) so orders can deduct it. Line prices are optional and only
// suggest the dish price — the owner can set any price.
require_once __DIR__ . '/_common.php';

$self = public_url('super/restaurant/menu.php');
$error = '';
$editId = (int) ($_GET['edit'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM rest_ingredients WHERE tenant_id = ? ORDER BY name');
$stmt->execute([$tid]);
$ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
$ingById = array_column($ingredients, null, 'id');
$categories = rest_categories($pdo, $tid);
$catById = array_column($categories, null, 'id');

function rest_dish(PDO $pdo, int $id, int $tid): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM rest_dishes WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$id, $tid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$old = ['name' => '', 'category_id' => '', 'description' => '', 'price' => '', 'available_from' => '', 'available_to' => '', 'image_path' => null, 'lines' => []];
if ($editId && ($d = rest_dish($pdo, $editId, $tid))) {
    $old = $d;
    $stmt = $pdo->prepare('SELECT ingredient_id, quantity, measure, line_price FROM rest_dish_ingredients WHERE dish_id = ?');
    $stmt->execute([$editId]);
    $old['lines'] = array_map(fn($r) => [
        'id' => (int) $r['ingredient_id'], 'qty' => (float) $r['quantity'], 'measure' => $r['measure'],
        'price' => $r['line_price'] === null ? '' : (float) $r['line_price'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
} else {
    $editId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete' || $action === 'toggle') {
        $id = (int) $_POST['id'];
        if (rest_dish($pdo, $id, $tid)) {
            if ($action === 'delete') {
                $pdo->prepare('DELETE FROM rest_dish_ingredients WHERE dish_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM rest_dishes WHERE id = ? AND tenant_id = ?')->execute([$id, $tid]);
                rest_flash('Dish deleted.');
            } else {
                $pdo->prepare('UPDATE rest_dishes SET is_active = 1 - is_active WHERE id = ? AND tenant_id = ?')->execute([$id, $tid]);
                rest_flash('Dish availability updated.');
            }
        }
        header('Location: ' . $self);
        exit;
    }

    $lines = [];
    foreach ((array) ($_POST['ing_id'] ?? []) as $i => $ingId) {
        $ingId = (int) $ingId;
        $q = (float) ($_POST['ing_qty'][$i] ?? 0);
        if (!isset($ingById[$ingId]) || $q <= 0) continue;
        $measure = $_POST['ing_measure'][$i] ?? '';
        if (!isset(rest_measures($ingById[$ingId])[$measure])) {
            $measure = array_key_first(rest_measures($ingById[$ingId]));
        }
        $lines[$ingId] = ['id' => $ingId, 'qty' => $q, 'measure' => $measure, 'price' => rest_money($_POST['ing_price'][$i] ?? '')];
    }
    $old = array_merge($old, [
        'name'        => trim($_POST['name'] ?? ''),
        'category_id' => isset($catById[(int) ($_POST['category_id'] ?? 0)]) ? (int) $_POST['category_id'] : null,
        'description' => trim($_POST['description'] ?? ''),
        'price'       => trim($_POST['price'] ?? ''),
        'available_from' => preg_match('/^\d{2}:\d{2}$/', $_POST['available_from'] ?? '') ? $_POST['available_from'] : '',
        'available_to'   => preg_match('/^\d{2}:\d{2}$/', $_POST['available_to'] ?? '') ? $_POST['available_to'] : '',
        'lines'       => array_values($lines),
    ]);
    if (($old['available_from'] === '') !== ($old['available_to'] === '')) {
        $error = 'Set both the "from" and "to" times, or leave both empty for all day.';
    } elseif ($old['available_from'] !== '' && $old['available_from'] === $old['available_to']) {
        $error = 'The "from" and "to" times can\'t be the same.';
    }

    $image = null;
    if ($error) {
        // availability error above
    } elseif ($old['name'] === '') {
        $error = 'Dish name is required.';
    } elseif (!is_numeric($old['price']) || $old['price'] < 0) {
        $error = 'Enter the dish price.';
    } else {
        [$image, $error] = rest_image('image', $tid);
    }
    if (!$error) {
        $catName = $old['category_id'] ? $catById[$old['category_id']]['name'] : null;
        $fields = [$old['name'], $old['category_id'], $catName, $old['description'] ?: null, round((float) $old['price'], 2),
                   $old['available_from'] ?: null, $old['available_to'] ?: null];
        $pdo->beginTransaction();
        if ($editId) {
            $pdo->prepare('UPDATE rest_dishes SET name=?, category_id=?, category=?, description=?, price=?, available_from=?, available_to=?' . ($image ? ', image_path=?' : '') . ' WHERE id=? AND tenant_id=?')
                ->execute(array_merge($fields, $image ? [$image] : [], [$editId, $tid]));
            $dishId = $editId;
        } else {
            $pdo->prepare('INSERT INTO rest_dishes (name, category_id, category, description, price, available_from, available_to, image_path, tenant_id) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute(array_merge($fields, [$image, $tid]));
            $dishId = (int) $pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM rest_dish_ingredients WHERE dish_id = ?')->execute([$dishId]);
        $ins = $pdo->prepare('INSERT INTO rest_dish_ingredients (dish_id, ingredient_id, quantity, measure, base_quantity, line_price) VALUES (?,?,?,?,?,?)');
        foreach ($old['lines'] as $l) {
            $ins->execute([$dishId, $l['id'], $l['qty'], $l['measure'], rest_to_base($l['qty'], $l['measure'], $ingById[$l['id']]), $l['price']]);
        }
        $pdo->commit();
        rest_flash('Dish "' . $old['name'] . '" ' . ($editId ? 'updated.' : 'added to the menu.'));
        header('Location: ' . $self);
        exit;
    }
}

$stmt = $pdo->prepare('SELECT d.*, c.name AS category_name FROM rest_dishes d LEFT JOIN rest_categories c ON c.id = d.category_id
                        WHERE d.tenant_id = ? ORDER BY c.sort_order IS NULL, c.sort_order, c.name, d.name');
$stmt->execute([$tid]);
$dishes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$recipes = $short = [];
if ($dishes) {
    $ids = implode(',', array_map('intval', array_column($dishes, 'id')));
    foreach ($pdo->query("SELECT di.*, i.name, i.unit, i.quantity AS stock FROM rest_dish_ingredients di
                            JOIN rest_ingredients i ON i.id = di.ingredient_id WHERE di.dish_id IN ($ids) ORDER BY i.name") as $r) {
        $d = (int) $r['dish_id'];
        $recipes[$d][] = ['id' => (int) $r['ingredient_id'], 'name' => $r['name'],
                          'qty' => rest_qty((float) $r['quantity']) . ' ' . ($r['measure'] === 'pc' || $r['measure'] === '' ? 'pcs' : $r['measure'])];
        if ((float) ($r['base_quantity'] ?? $r['quantity']) > (float) $r['stock']) {
            $short[$d][] = $r['name'];
        }
    }
}

$ingJs = array_map(fn($i) => [
    'id' => (int) $i['id'], 'name' => $i['name'], 'unit' => $i['unit'], 'ppu' => (float) $i['pieces_per_unit'],
    'stock' => (float) $i['quantity'], 'charge' => $i['charge_price'] === null ? null : (float) $i['charge_price'],
    'measures' => rest_measures($i),
], $ingredients);

ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-xl-5">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-1"><?php echo $editId ? 'Edit dish' : 'Add a dish'; ?></h2>
        <p class="text-muted small mb-3">Customers see the name, image and price. The ingredients are the default recipe; they show in the order details, where a customer can remove some.</p>
        <?php if ($error): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <form method="post" enctype="multipart/form-data" action="<?php echo $self . ($editId ? '?edit=' . $editId : ''); ?>">
          <?php echo csrf_field(); ?>
          <div class="mb-3">
            <label class="form-label">Dish name</label>
            <input name="name" class="form-control" placeholder="e.g. Burger" value="<?php echo htmlspecialchars($old['name']); ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Category</label>
            <select name="category_id" class="form-select">
              <option value="">— Uncategorised —</option>
              <?php foreach ($categories as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo (int) $old['category_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
            </select>
            <div class="form-text"><a href="<?php echo public_url('super/restaurant/categories.php'); ?>">Manage categories</a></div>
          </div>
          <div class="mb-3">
            <label class="form-label">Short description <span class="text-muted">(optional)</span></label>
            <input name="description" class="form-control" maxlength="255" placeholder="e.g. Beef patty, fresh veg, house sauce" value="<?php echo htmlspecialchars((string) $old['description']); ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Available <span class="text-muted">(optional — leave empty for all day)</span></label>
            <div class="d-flex gap-2 align-items-center">
              <input type="time" name="available_from" class="form-control" value="<?php echo htmlspecialchars(substr((string) $old['available_from'], 0, 5)); ?>">
              <span class="text-muted small">to</span>
              <input type="time" name="available_to" class="form-control" value="<?php echo htmlspecialchars(substr((string) $old['available_to'], 0, 5)); ?>">
            </div>
            <div class="form-text">E.g. tea 06:00 to 11:00. Outside these hours the dish can't be ordered — by staff or by customers.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Image <span class="text-muted">(optional)</span></label>
            <?php if (!empty($old['image_path'])): ?><img src="<?php echo htmlspecialchars(settings_asset_url($old['image_path'])); ?>" alt="" class="d-block mb-2" style="height:64px;border-radius:8px;"><?php endif; ?>
            <input name="image" type="file" accept="image/*" class="form-control">
          </div>

          <label class="form-label">Default ingredients <span class="text-muted small">(per one dish)</span></label>
          <?php if (!$ingredients): ?>
            <div class="small text-muted mb-3">No ingredients yet — <a href="<?php echo public_url('super/restaurant/ingredients.php'); ?>">add some</a> to build recipes. You can still save the dish without them.</div>
          <?php else: ?>
          <div id="ingLines"></div>
          <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="addIng"><i class="fas fa-plus me-1"></i>Add ingredient</button>
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Dish price</label>
            <div class="input-group"><span class="input-group-text"><?php echo $currency; ?></span>
              <input name="price" id="priceIn" type="number" step="0.01" min="0" class="form-control" value="<?php echo htmlspecialchars((string) $old['price']); ?>" required></div>
            <div class="form-text" id="suggest"></div>
          </div>
          <div>
            <button class="btn btn-primary"><?php echo $editId ? 'Save dish' : 'Add to menu'; ?></button>
            <?php if ($editId): ?><a class="btn btn-link" href="<?php echo $self; ?>">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-7">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-3">Menu <span class="badge bg-light text-dark"><?php echo count($dishes); ?></span></h2>
        <?php if (!$dishes): ?><div class="text-muted">No dishes yet. Add your first one on the left.</div><?php endif; ?>
        <div class="row g-3">
          <?php $lastCat = false; foreach ($dishes as $d): $img = settings_asset_url($d['image_path']); ?>
          <?php if ($d['category_name'] !== $lastCat): $lastCat = $d['category_name']; ?>
            <div class="col-12"><h3 class="h6 text-muted text-uppercase small mb-0 mt-1"><?php echo htmlspecialchars($lastCat ?? 'Uncategorised'); ?></h3></div>
          <?php endif; ?>
          <div class="col-6 col-md-4 col-xxl-3">
            <div class="dish-card <?php echo $d['is_active'] ? '' : 'off'; ?>" data-dish="<?php echo (int) $d['id']; ?>" role="button" tabindex="0">
              <div class="dish-img"><?php if ($img): ?><img src="<?php echo htmlspecialchars($img); ?>" alt=""><?php else: ?><i class="fas fa-utensils"></i><?php endif; ?></div>
              <div class="p-2">
                <div class="fw-semibold text-truncate"><?php echo htmlspecialchars($d['name']); ?></div>
                <?php if ($w = rest_window_label($d)): ?><div class="small <?php echo rest_dish_available($d) ? 'text-muted' : 'text-danger'; ?>"><i class="far fa-clock me-1"></i><?php echo $w; ?></div><?php endif; ?>
                <div class="d-flex justify-content-between align-items-center">
                  <span class="text-primary fw-bold small"><?php echo $currency . ' ' . number_format((float) $d['price'], 2); ?></span>
                  <?php if (!$d['is_active']): ?><span class="badge bg-secondary">Off</span>
                  <?php elseif (!empty($short[(int) $d['id']])): ?><span class="badge bg-warning text-dark" title="Not enough <?php echo htmlspecialchars(implode(', ', $short[(int) $d['id']])); ?>">Low stock</span><?php endif; ?>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Order details: tap a dish to see its ingredients and untick what the customer doesn't want. -->
<div class="modal fade" id="dishModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title" id="dmName"></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <img id="dmImg" alt="" class="w-100 mb-3" style="max-height:200px;object-fit:cover;border-radius:12px;">
        <div class="d-flex justify-content-between mb-2"><span class="text-muted small" id="dmCat"></span><strong id="dmPrice"></strong></div>
        <p class="small text-muted" id="dmDesc"></p>
        <div class="alert alert-warning small py-2" id="dmShort" style="display:none;"></div>
        <h6 class="mb-2">Ingredients <span class="small text-muted fw-normal">— untick what the customer doesn't want</span></h6>
        <div id="dmIngs"></div>
        <div class="alert alert-light border mt-3 mb-0 small"><i class="fas fa-receipt me-1"></i>On the order: <strong id="dmSummary"></strong></div>
      </div>
      <div class="modal-footer border-0">
        <form method="post" class="me-auto" onsubmit="return confirm('Delete this dish?');">
          <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="dmDelId">
          <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
        </form>
        <form method="post">
          <?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" id="dmTogId">
          <button class="btn btn-sm btn-outline-secondary" id="dmTogBtn"></button>
        </form>
        <a class="btn btn-sm btn-primary" id="dmEdit"><i class="fas fa-pen me-1"></i>Edit</a>
      </div>
    </div>
  </div>
</div>

<style>
.dish-card{background:#fff;border:1px solid #e9ecef;border-radius:12px;overflow:hidden;cursor:pointer;transition:box-shadow .15s,transform .15s;}
.dish-card:hover{box-shadow:0 6px 18px rgba(0,0,0,.08);transform:translateY(-2px);}
.dish-card.off{opacity:.55;}
.dish-img{aspect-ratio:4/3;background:#f1f3f5;display:flex;align-items:center;justify-content:center;color:#adb5bd;font-size:1.8rem;}
.dish-img img{width:100%;height:100%;object-fit:cover;}
.ing-line{border:1px solid #e9ecef;border-radius:10px;padding:8px;margin-bottom:8px;}
</style>
<script>
(function () {
  var CUR = <?php echo json_encode($currency); ?>;
  var dishes = <?php echo json_encode((object) array_column(array_map(fn($d) => [
      'id' => (int) $d['id'], 'name' => $d['name'], 'category' => $d['category_name'], 'description' => $d['description'],
      'price' => $currency . ' ' . number_format((float) $d['price'], 2), 'img' => settings_asset_url($d['image_path']),
      'active' => (bool) $d['is_active'], 'ings' => $recipes[(int) $d['id']] ?? [], 'short' => $short[(int) $d['id']] ?? [],
  ], $dishes), null, 'id')); ?>;
  var editBase = <?php echo json_encode($self . '?edit='); ?>;

  // ---- Order-details preview ----
  var modalEl = document.getElementById('dishModal'), modal;
  function summary(d) {
    var off = [].slice.call(document.querySelectorAll('#dmIngs input:not(:checked)')).map(function (c) { return c.dataset.name; });
    document.getElementById('dmSummary').textContent = d.name + (off.length ? ' — without ' + off.join(', ') : '');
  }
  function openDish(id) {
    var d = dishes[id]; if (!d) return;
    document.getElementById('dmName').textContent = d.name;
    document.getElementById('dmCat').textContent = d.category || '';
    document.getElementById('dmPrice').textContent = d.price;
    document.getElementById('dmDesc').textContent = d.description || '';
    var sh = document.getElementById('dmShort');
    sh.style.display = d.short.length ? '' : 'none';
    sh.textContent = 'Not enough stock for one portion: ' + d.short.join(', ');
    var img = document.getElementById('dmImg'); img.style.display = d.img ? '' : 'none'; if (d.img) img.src = d.img;
    var box = document.getElementById('dmIngs'); box.innerHTML = '';
    if (!d.ings.length) box.innerHTML = '<div class="small text-muted">No ingredients recorded for this dish.</div>';
    d.ings.forEach(function (i) {
      var row = document.createElement('label');
      row.className = 'd-flex align-items-center gap-2 border rounded px-2 py-1 mb-1';
      row.innerHTML = '<input type="checkbox" class="form-check-input m-0" checked><span class="flex-grow-1"></span><span class="small text-muted"></span>';
      row.querySelector('input').dataset.name = i.name;
      row.querySelector('span').textContent = i.name;
      row.querySelector('.text-muted').textContent = i.qty;
      row.querySelector('input').onchange = function () { summary(d); };
      box.appendChild(row);
    });
    summary(d);
    document.getElementById('dmDelId').value = d.id;
    document.getElementById('dmTogId').value = d.id;
    document.getElementById('dmTogBtn').textContent = d.active ? 'Mark unavailable' : 'Mark available';
    document.getElementById('dmEdit').href = editBase + d.id;
    (modal = modal || new bootstrap.Modal(modalEl)).show();
  }
  document.querySelectorAll('.dish-card').forEach(function (c) {
    c.onclick = function () { openDish(c.dataset.dish); };
    c.onkeydown = function (e) { if (e.key === 'Enter') openDish(c.dataset.dish); };
  });

  // ---- Recipe builder ----
  var box = document.getElementById('ingLines');
  var priceIn = document.getElementById('priceIn');
  var priceTouched = priceIn.value !== '';
  priceIn.addEventListener('input', function () { priceTouched = true; });
  if (!box) return;
  var ings = <?php echo json_encode($ingJs); ?>, byId = {};
  ings.forEach(function (i) { byId[i.id] = i; });
  var F = {kg: ['mass', 1000], g: ['mass', 1], l: ['vol', 1000], ml: ['vol', 1]};
  function toBase(q, m, ing) {
    if (m === 'pc' && ing.unit !== 'pcs') return ing.ppu > 0 ? q / ing.ppu : q;
    if (F[m] && F[ing.unit] && F[m][0] === F[ing.unit][0]) return q * F[m][1] / F[ing.unit][1];
    return q;
  }
  function fmt(n) { return (Math.round(n * 1000) / 1000).toString(); }
  function money(n) { return CUR + ' ' + n.toFixed(2); }

  function recalc() {
    var total = 0, any = false;
    box.querySelectorAll('.ing-line').forEach(function (l) {
      var v = l.querySelector('.lp').value;
      if (v !== '') { total += parseFloat(v) || 0; any = true; }
    });
    var s = document.getElementById('suggest');
    s.textContent = any ? 'Suggested from ingredient prices: ' + money(total) + (priceTouched ? ' — you can keep your own price.' : '') : 'Set any price you like.';
    if (any && !priceTouched) priceIn.value = total.toFixed(2);
  }

  function addLine(line) {
    line = line || {};
    var el = document.createElement('div');
    el.className = 'ing-line';
    el.innerHTML = '<div class="d-flex gap-2 mb-1"><select name="ing_id[]" class="form-select form-select-sm"><option value="">— Choose ingredient —</option></select>'
      + '<button type="button" class="btn btn-sm btn-link text-danger p-0 rm"><i class="fas fa-times"></i></button></div>'
      + '<div class="d-flex gap-2"><input name="ing_qty[]" type="number" step="0.001" min="0" class="form-control form-control-sm q" style="max-width:90px;" placeholder="Qty">'
      + '<select name="ing_measure[]" class="form-select form-select-sm m" style="max-width:90px;"></select>'
      + '<div class="input-group input-group-sm"><span class="input-group-text">' + CUR + '</span><input name="ing_price[]" type="number" step="0.01" min="0" class="form-control lp" placeholder="Price (optional)"></div></div>'
      + '<div class="small mt-1 info"></div>';
    var sel = el.querySelector('select'), q = el.querySelector('.q'), m = el.querySelector('.m'), lp = el.querySelector('.lp');
    ings.forEach(function (i) { var o = new Option(i.name, i.id); sel.add(o); });
    var lpTouched = line.price !== undefined && line.price !== '';
    lp.oninput = function () { lpTouched = true; recalc(); };

    function measures() {
      var ing = byId[sel.value]; m.innerHTML = '';
      if (!ing) return;
      Object.keys(ing.measures).forEach(function (k) { m.add(new Option(ing.measures[k], k)); });
    }
    function update() {
      var ing = byId[sel.value], info = el.querySelector('.info');
      if (!ing) { info.textContent = ''; return; }
      var qty = parseFloat(q.value) || 0, base = toBase(qty, m.value, ing);
      var left = ing.stock;
      var pcs = ing.ppu > 0 ? ' (≈' + fmt(left * ing.ppu) + ' pcs)' : '';
      if (left <= 0) {
        info.className = 'small mt-1 info text-danger';
        info.innerHTML = '<i class="fas fa-triangle-exclamation me-1"></i>' + ing.name + ' is out of stock.';
      } else if (base > left) {
        info.className = 'small mt-1 info text-danger';
        info.innerHTML = '<i class="fas fa-triangle-exclamation me-1"></i>Needs ' + fmt(base) + ' ' + ing.unit + ' but only ' + fmt(left) + ' ' + ing.unit + pcs + ' left.';
      } else {
        info.className = 'small mt-1 info text-muted';
        info.textContent = (m.value !== ing.unit ? 'Uses ' + fmt(base) + ' ' + ing.unit + ' · ' : '') + fmt(left) + ' ' + ing.unit + pcs + ' in stock'
          + (base > 0 ? ' · enough for ' + Math.floor(left / base) + ' dishes' : '');
      }
      if (!lpTouched && ing.charge !== null) lp.value = (ing.charge * base).toFixed(2);
      recalc();
    }
    sel.onchange = function () { measures(); lpTouched = false; update(); };
    q.oninput = m.onchange = update;
    el.querySelector('.rm').onclick = function () { el.remove(); recalc(); };

    if (line.id) { sel.value = line.id; }
    measures();
    if (line.measure) m.value = line.measure;
    q.value = line.qty || 1;
    if (lpTouched) lp.value = line.price;
    box.appendChild(el);
    update();
  }
  document.getElementById('addIng').onclick = function () { addLine(); };
  var lines = <?php echo json_encode($old['lines']); ?>;
  if (lines.length) lines.forEach(addLine); else addLine();
})();
</script>
<?php
rest_render('Menu', ob_get_clean());
