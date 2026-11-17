<?php
// public/super/services/new.php — take in a client, in three steps:
//   1. choose the service(s)   2. the client (any name, kept unique)   3. who serves them (+ products / extras)
// The owner — or anyone delegated the front desk — can assign each service to any staff member.
// A staff member taking their own client is assigned automatically and can't assign someone else.
require_once __DIR__ . '/_common.php';
require_once ROOT_PATH . '/app/helpers/uploads.php';

$canAssign = $isOwner || $canBook;
$me = (int) TenantContext::userId();
$services = $SD->servicesForShop();
$staff = $SD->staffForShop();
$products = $SD->sellableProducts();
$staffIds = array_map('intval', array_column($staff, 'id'));
$defaultStaff = in_array($me, $staffIds, true) ? $me : 0;
$error = '';
$old = $_POST + ['customer_name' => '', 'customer_phone' => '', 'customer_email' => '', 'notes' => '', 'scheduled_at' => '', 'kind' => $_GET['kind'] ?? 'walkin'];
$names = [];
try { // names already on file, so a returning client is easy to pick
    $st = $pdo->prepare('SELECT name, phone FROM customers WHERE tenant_id = ? ORDER BY name LIMIT 500');
    $st->execute([TenantContext::tenantId()]);
    $names = $st->fetchAll();
} catch (Throwable $e) { /* optional */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lines = [];
    foreach ((array) ($_POST['service_id'] ?? []) as $sid) {
        $sid = (int) $sid;
        if ($sid <= 0) { continue; }
        $who = $canAssign ? (int) ($_POST['staff_for'][$sid] ?? 0) : $me; // not allowed to assign: it is always you
        $lines[] = ['service_id' => $sid, 'staff_id' => $who];
    }
    $prodLines = [];
    foreach ((array) ($_POST['product_id'] ?? []) as $i => $pid) {
        if ((int) $pid > 0) { $prodLines[] = ['product_id' => (int) $pid, 'quantity' => (float) ($_POST['product_qty'][$i] ?? 1)]; }
    }
    $charges = [];
    foreach ((array) ($_POST['charge_name'] ?? []) as $i => $cn) {
        if (trim((string) $cn) !== '') { $charges[] = ['name' => $cn, 'amount' => (float) ($_POST['charge_amount'][$i] ?? 0)]; }
    }
    $in = [
        'products' => $prodLines, 'charges' => $charges,
        'customer_name' => $_POST['customer_name'] ?? '', 'customer_phone' => $_POST['customer_phone'] ?? '',
        'customer_email' => $_POST['customer_email'] ?? '', 'notes' => $_POST['notes'] ?? '', 'lines' => $lines,
        'scheduled_at' => ($_POST['kind'] ?? '') === 'booking' ? ($_POST['scheduled_at'] ?? '') : '',
    ];
    if (($_POST['kind'] ?? '') === 'booking' && trim($in['scheduled_at']) === '') {
        $error = 'Pick the date and time of the booking.';
    } else {
        if (trim($in['customer_phone']) !== '' || trim($in['customer_email']) !== '') {
            try {
                $cust = (new Models\CustomerModel($pdo))->findOrCreateByContact(trim($in['customer_name']), trim($in['customer_email']), trim($in['customer_phone']));
                $in['customer_id'] = $cust['id'] ?? null;
            } catch (Throwable $e) { /* the visit matters more than the address book */ }
        }
        $res = $SD->create($in);
        if ($res['ok']) {
            $_SESSION['flash']['success'] = ($in['scheduled_at'] ? 'Booking saved' : 'Client added — now serving') . ' · ' . $res['number'] . '.';
            header('Location: ' . public_url($in['scheduled_at'] ? 'super/services/view.php?id=' . $res['id'] : 'super/services/serving.php#t' . $res['id']));
            exit;
        }
        $error = $res['error'];
    }
}
$picked = array_map('intval', (array) ($_POST['service_id'] ?? []));

ob_start();
?>
<style>
.nc-steps{display:flex;gap:6px;margin-bottom:14px}
.nc-steps div{flex:1;text-align:center;font-size:.78rem;font-weight:600;color:#8a8f96;padding:8px 4px;border-bottom:3px solid #e5e7eb}
.nc-steps div.on{color:#1f9a5f;border-color:#1f9a5f}.nc-steps div.done{color:#1f9a5f;border-color:#bfe6d1}
.nc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px}
.nc-tile{position:relative;border:2px solid #e5e7eb;border-radius:12px;padding:12px;cursor:pointer;background:#fff;user-select:none;display:block}
.nc-img{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:8px;margin-bottom:8px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:1.6rem}
.nc-chips{display:flex;gap:6px;overflow-x:auto;padding-bottom:6px;margin-bottom:10px}
.nc-chip{white-space:nowrap;border:1px solid #d9dee5;background:#fff;border-radius:999px;padding:5px 14px;font-size:.82rem;cursor:pointer}
.nc-chip.on{background:#1f9a5f;border-color:#1f9a5f;color:#fff}
.nc-tile input{position:absolute;opacity:0;pointer-events:none}
.nc-tile b{display:block;font-size:.92rem}.nc-tile span{font-size:.78rem;color:#6b7280}
.nc-tile:has(input:checked){border-color:#1f9a5f;background:#f0faf5}
.nc-tile:has(input:checked)::after{content:'\2713';position:absolute;top:6px;right:9px;color:#1f9a5f;font-weight:800}
.nc-pane{display:none}.nc-pane.on{display:block}
.nc-nav{position:sticky;bottom:0;z-index:20;display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:16px;background:#fff;border:1px solid #e2e4ea;border-radius:14px;padding:12px 16px;box-shadow:0 -6px 20px rgba(16,24,40,.08)}
@media (max-width:768px){.nc-nav{bottom:calc(64px + env(safe-area-inset-bottom))}.svc-line{flex-wrap:wrap}.svc-line>div{width:100%!important}}
</style>
<?php if ($error): ?><div class="alert alert-danger"><?php echo svc_h($error); ?></div><?php endif; ?>
<?php if (!$services): ?>
  <div class="alert alert-warning">No services are offered in this shop yet.<?php if ($canManage): ?> <a href="<?php echo public_url('super/services/catalog.php'); ?>">Add your services and prices</a> first.<?php endif; ?></div>
<?php else: ?>
<form method="post" id="ncForm" autocomplete="off">
  <div class="nc-steps"><div data-s="1" class="on">1 · Service</div><div data-s="2">2 · Client</div><div data-s="3">3 · Serving</div></div>

  <div class="nc-pane on" data-p="1"><div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
    <div class="d-flex justify-content-between align-items-center gap-2 mb-2 flex-wrap"><h2 class="h6 fw-bold mb-0">What is the client here for? <span class="text-muted fw-normal small">(tap one or more)</span></h2>
      <input type="search" id="ncFind" class="form-control form-control-sm" style="max-width:200px" placeholder="Search services"></div>
    <?php $catNames = []; foreach ($services as $s) { $catNames[$s['category_name'] ?? ''] = true; } unset($catNames['']); ?>
    <?php if ($catNames): ?>
    <div class="nc-chips" id="ncChips"><span class="nc-chip on" data-c="*">All</span>
      <?php foreach (array_keys($catNames) as $cn): ?><span class="nc-chip" data-c="<?php echo svc_h($cn); ?>"><?php echo svc_h($cn); ?></span><?php endforeach; ?>
      <?php if (count(array_filter($services, fn($x) => empty($x['category_name'])))): ?><span class="nc-chip" data-c="">Other</span><?php endif; ?></div>
    <?php endif; ?>
    <div class="nc-grid" id="ncGrid">
      <?php foreach ($services as $s): $img = !empty($s['image_path']) ? settings_asset_url($s['image_path']) : null; ?>
        <label class="nc-tile" data-name="<?php echo svc_h(strtolower($s['name'])); ?>" data-cat="<?php echo svc_h($s['category_name'] ?? ''); ?>">
          <input type="checkbox" name="service_id[]" value="<?php echo (int) $s['id']; ?>" data-price="<?php echo (float) $s['price']; ?>" data-label="<?php echo svc_h($s['name']); ?>" <?php echo in_array((int) $s['id'], $picked, true) ? 'checked' : ''; ?>>
          <?php if ($img): ?><img class="nc-img" src="<?php echo svc_h($img); ?>" alt="" loading="lazy"><?php else: ?><div class="nc-img"><i class="fas fa-scissors"></i></div><?php endif; ?>
          <b><?php echo svc_h($s['name']); ?></b>
          <span><?php echo $canMoney ? svc_money($s['price']) : ''; ?><?php echo $s['duration_min'] ? ($canMoney ? ' · ' : '') . (int) $s['duration_min'] . ' min' : ''; ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </div></div></div>

  <div class="nc-pane" data-p="2"><div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
    <div class="btn-group w-100 mb-3" role="group">
      <input type="radio" class="btn-check" name="kind" id="kWalk" value="walkin" <?php echo $old['kind'] !== 'booking' ? 'checked' : ''; ?>>
      <label class="btn btn-outline-primary" for="kWalk"><i class="fas fa-person-walking me-1"></i>Walk-in now</label>
      <input type="radio" class="btn-check" name="kind" id="kBook" value="booking" <?php echo $old['kind'] === 'booking' ? 'checked' : ''; ?>>
      <label class="btn btn-outline-primary" for="kBook"><i class="fas fa-calendar-plus me-1"></i>Booking</label>
    </div>
    <div id="whenBox" class="mb-3" style="<?php echo $old['kind'] === 'booking' ? '' : 'display:none;'; ?>">
      <label class="form-label small fw-semibold mb-1">Date &amp; time</label>
      <input type="datetime-local" name="scheduled_at" class="form-control" value="<?php echo svc_h($old['scheduled_at']); ?>">
    </div>
    <h2 class="h6 fw-bold mb-2">Client</h2>
    <label class="form-label small mb-1">Name — any name, just keep it unique <span class="text-danger">*</span></label>
    <input name="customer_name" id="ncName" class="form-control mb-2" maxlength="150" list="ncNames" value="<?php echo svc_h($old['customer_name']); ?>" placeholder="e.g. Mary W.">
    <datalist id="ncNames"><?php foreach ($names as $n): ?><option value="<?php echo svc_h($n['name']); ?>"><?php echo svc_h($n['phone'] ?? ''); ?></option><?php endforeach; ?></datalist>
    <div class="row g-2 mb-2">
      <div class="col-6"><label class="form-label small mb-1">Phone <span class="text-muted">(optional)</span></label><input name="customer_phone" type="tel" class="form-control" placeholder="07…" value="<?php echo svc_h($old['customer_phone']); ?>"></div>
      <div class="col-6"><label class="form-label small mb-1">Email <span class="text-muted">(optional)</span></label><input name="customer_email" type="email" class="form-control" value="<?php echo svc_h($old['customer_email']); ?>"></div>
    </div>
    <input name="notes" class="form-control" maxlength="255" placeholder="Note (optional), e.g. prefers Mary, allergic to…" value="<?php echo svc_h($old['notes']); ?>">
  </div></div></div>

  <div class="nc-pane" data-p="3">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-1">Who is serving?</h2>
      <?php if ($canAssign): ?>
        <div class="small text-muted mb-2">Pick who does each service — you can serve the client yourself or give it to a staff member.</div>
        <div class="d-flex gap-2 align-items-center mb-2 flex-wrap"><span class="small">Everything:</span>
          <select id="ncAll" class="form-select form-select-sm" style="max-width:240px">
            <?php foreach ($staff as $u): ?><option value="<?php echo (int) $u['id']; ?>" <?php echo (int) $u['id'] === $defaultStaff ? 'selected' : ''; ?>><?php echo (int) $u['id'] === $me ? 'Me (' . svc_h($u['username']) . ')' : svc_h($u['username']) . ($u['position'] ? ' · ' . svc_h($u['position']) : ''); ?></option><?php endforeach; ?>
            <option value="0" <?php echo $defaultStaff ? '' : 'selected'; ?>>Assign later</option>
          </select></div>
        <div id="ncWho"></div>
      <?php else: ?>
        <div class="alert alert-success small mb-0"><i class="fas fa-user-check me-1"></i>This client is assigned to <strong>you</strong>. It will be under <em>My clients</em> and <em>Serving</em>, and the commission is yours.</div>
      <?php endif; ?>
    </div></div>

    <div class="card border-0 shadow-sm mt-3" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-1">Products &amp; extra charges <span class="text-muted fw-normal small">(optional — you can add them later under Serving)</span></h2>
      <?php if ($products): ?>
        <div class="small text-muted mb-1">Products the client is buying (dye, braids, gel…) — taken from your stock.</div>
        <div id="prodLines"></div>
        <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="addProd" style="border-style:dashed;"><i class="fas fa-plus me-1"></i>Add a product</button>
      <?php endif; ?>
      <div class="small text-muted mb-1">An extra charge, e.g. “Extra length”, “Home service”.</div>
      <div id="chLines"></div>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="addCh" style="border-style:dashed;"><i class="fas fa-plus me-1"></i>Add a charge</button>
    </div></div>
  </div>

  <div class="nc-nav">
    <button type="button" class="btn btn-outline-secondary" id="ncBack" style="visibility:hidden"><i class="fas fa-arrow-left me-1"></i>Back</button>
    <div><?php if ($canMoney): ?><span class="small text-muted">Total</span> <strong id="ncTotal">KES 0</strong><?php else: ?><span class="small text-muted"><span id="ncCount">0</span> service(s)</span><?php endif; ?></div>
    <button type="button" class="btn btn-primary px-4" id="ncNext">Next <i class="fas fa-arrow-right ms-1"></i></button>
    <button class="btn btn-success px-4" id="ncSave" style="display:none"><i class="fas fa-check me-1"></i><span id="ncSaveL">Start serving</span></button>
  </div>
</form>

<template id="prodTpl">
  <div class="svc-line d-flex gap-2 align-items-end mb-2">
    <div class="flex-grow-1"><select name="product_id[]" class="form-select form-select-sm prod-sel">
      <option value="">Choose a product…</option>
      <?php foreach ($products as $p): ?><option value="<?php echo (int) $p['id']; ?>" data-price="<?php echo (float) $p['sell_price']; ?>" data-stock="<?php echo (float) $p['quantity']; ?>"><?php echo svc_h($p['name']); ?><?php echo $canMoney ? ' — ' . svc_money($p['sell_price']) : ''; ?> · <?php echo rtrim(rtrim(number_format((float) $p['quantity'], 2), '0'), '.'); ?> in stock</option><?php endforeach; ?>
    </select></div>
    <div style="width:90px;"><input type="number" name="product_qty[]" class="form-control form-control-sm prod-qty" min="0.01" step="any" value="1" aria-label="Quantity"></div>
    <button type="button" class="btn btn-link text-danger px-1 row-del" title="Remove"><i class="fas fa-xmark"></i></button>
  </div>
</template>
<template id="chTpl">
  <div class="svc-line d-flex gap-2 align-items-end mb-2">
    <div class="flex-grow-1"><input name="charge_name[]" class="form-control form-control-sm" maxlength="150" placeholder="What is it for?"></div>
    <div style="width:130px;"><input type="number" name="charge_amount[]" class="form-control form-control-sm ch-amt" min="0" step="any" placeholder="Amount"></div>
    <button type="button" class="btn btn-link text-danger px-1 row-del" title="Remove"><i class="fas fa-xmark"></i></button>
  </div>
</template>
<script>
(function () {
  var f = document.getElementById('ncForm'); if (!f) return;
  var canAssign = <?php echo $canAssign ? 'true' : 'false'; ?>, step = 1;
  var staff = <?php echo json_encode(array_map(fn($u) => ['id' => (int) $u['id'], 'n' => ($u['username'] . ((int) $u['id'] === $me ? ' (me)' : ''))], $staff)); ?>;
  var defStaff = <?php echo (int) $defaultStaff; ?>;
  var panes = f.querySelectorAll('.nc-pane'), steps = f.querySelectorAll('.nc-steps div');
  var back = document.getElementById('ncBack'), next = document.getElementById('ncNext'), save = document.getElementById('ncSave');
  function picked() { return Array.prototype.slice.call(f.querySelectorAll('#ncGrid input:checked')); }
  function total() {
    var t = picked().reduce(function (a, c) { return a + (parseFloat(c.dataset.price) || 0); }, 0);
    f.querySelectorAll('#prodLines .svc-line').forEach(function (r) {
      var s = r.querySelector('.prod-sel'), q = r.querySelector('.prod-qty'), o = s.options[s.selectedIndex];
      if (s.value) { var max = parseFloat(o.dataset.stock); if ((parseFloat(q.value) || 0) > max) q.value = max; t += (parseFloat(o.dataset.price) || 0) * (parseFloat(q.value) || 0); }
    });
    f.querySelectorAll('.ch-amt').forEach(function (a) { t += parseFloat(a.value) || 0; });
    var el = document.getElementById('ncTotal'); if (el) el.textContent = 'KES ' + t.toLocaleString('en-KE');
    var c = document.getElementById('ncCount'); if (c) c.textContent = picked().length;
  }
  function who() {
    var box = document.getElementById('ncWho'); if (!box) return;
    var keep = {}; box.querySelectorAll('select').forEach(function (s) { keep[s.dataset.sid] = s.value; });
    box.innerHTML = '';
    picked().forEach(function (c) {
      var row = document.createElement('div'); row.className = 'd-flex justify-content-between align-items-center gap-2 border-bottom py-2';
      var opts = staff.map(function (u) { return '<option value="' + u.id + '">' + u.n.replace(/</g, '&lt;') + '</option>'; }).join('') + '<option value="0">Assign later</option>';
      row.innerHTML = '<span class="fw-semibold small"></span><select class="form-select form-select-sm" style="max-width:220px" name="staff_for[' + c.value + ']" data-sid="' + c.value + '">' + opts + '</select>';
      row.firstChild.textContent = c.dataset.label; box.appendChild(row);
      var s = row.querySelector('select'); s.value = keep[c.value] !== undefined ? keep[c.value] : document.getElementById('ncAll').value;
    });
  }
  var all = document.getElementById('ncAll');
  if (all) all.addEventListener('change', function () { f.querySelectorAll('#ncWho select').forEach(function (s) { s.value = all.value; }); });
  function show(n) {
    step = n;
    panes.forEach(function (p) { p.classList.toggle('on', +p.dataset.p === n); });
    steps.forEach(function (s) { var k = +s.dataset.s; s.classList.toggle('on', k === n); s.classList.toggle('done', k < n); });
    back.style.visibility = n > 1 ? 'visible' : 'hidden';
    next.style.display = n < 3 ? '' : 'none'; save.style.display = n === 3 ? '' : 'none';
    if (n === 3) who();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  next.addEventListener('click', function () {
    if (step === 1 && !picked().length) { alert('Choose at least one service.'); return; }
    if (step === 2) {
      var nm = document.getElementById('ncName'); if (!nm.value.trim()) { nm.focus(); alert('Enter the client\'s name — any name, just keep it unique.'); return; }
      if (f.querySelector('[name=kind]:checked').value === 'booking' && !f.querySelector('[name=scheduled_at]').value) { alert('Pick the date and time of the booking.'); return; }
    }
    show(step + 1);
  });
  back.addEventListener('click', function () { show(step - 1); });
  f.querySelectorAll('[name=kind]').forEach(function (r) { r.addEventListener('change', function () {
    var b = f.querySelector('[name=kind]:checked').value === 'booking';
    document.getElementById('whenBox').style.display = b ? '' : 'none'; document.getElementById('ncSaveL').textContent = b ? 'Save booking' : 'Start serving';
  }); });
  var curCat = '*';
  function filt() {
    var q = document.getElementById('ncFind').value.trim().toLowerCase();
    f.querySelectorAll('#ncGrid .nc-tile').forEach(function (t) {
      t.style.display = (!q || t.dataset.name.indexOf(q) > -1) && (curCat === '*' || t.dataset.cat === curCat) ? '' : 'none';
    });
  }
  document.getElementById('ncFind').addEventListener('input', filt);
  var chips = document.getElementById('ncChips');
  if (chips) chips.addEventListener('click', function (e) {
    var c = e.target.closest('.nc-chip'); if (!c) return;
    chips.querySelectorAll('.nc-chip').forEach(function (x) { x.classList.remove('on'); }); c.classList.add('on'); curCat = c.dataset.c; filt();
  });
  function adder(btn, tplId, boxId) {
    var b = document.getElementById(btn), tpl = document.getElementById(tplId), box = document.getElementById(boxId); if (!b || !tpl) return;
    b.addEventListener('click', function () { var w = document.createElement('div'); w.innerHTML = tpl.innerHTML; box.appendChild(w.firstElementChild); });
    box.addEventListener('click', function (e) { var d = e.target.closest('.row-del'); if (d) { d.closest('.svc-line').remove(); total(); } });
  }
  adder('addProd', 'prodTpl', 'prodLines'); adder('addCh', 'chTpl', 'chLines');
  f.addEventListener('change', total); f.addEventListener('input', total);
  f.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); if (step < 3) next.click(); } });
  f.addEventListener('submit', function (e) { if (!picked().length) { e.preventDefault(); show(1); alert('Choose at least one service.'); } });
  total();
  <?php if ($error && $picked): ?>show(2);<?php endif; ?>
})();
</script>
<?php endif; ?>
<?php
svc_render('New client', ob_get_clean());
