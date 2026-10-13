<?php
// public/super/purchases/new.php — owner records a purchase: what came in,
// in what packages, how many units per package and the price per package.
// It goes to the purchases store (not on sale) until moved into inventory.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::tenant(); // owner only

$pdo = Database::pdo();
$PM = new Models\PurchaseModel($pdo);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = $PM->record($_POST, array_values((array) ($_POST['items'] ?? [])));
    if ($res['ok']) {
        $_SESSION['flash']['success'] = 'Purchase recorded — ' . $res['items'] . ' item' . ($res['items'] === 1 ? '' : 's') . ($res['total'] > 0 ? ', KES ' . number_format($res['total'], 0) : '') . '. Move them into inventory when you\'re ready to sell.';
        header('Location: ' . public_url('super/purchases/'));
        exit;
    }
    $error = $res['error'];
}
$cats = $pdo->prepare("SELECT DISTINCT name FROM categories WHERE tenant_id = ? ORDER BY name");
$cats->execute([TenantContext::tenantId()]);
$cats = $cats->fetchAll(PDO::FETCH_COLUMN);

$page_title = 'Record a purchase';
ob_start();
?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<form method="post" id="puForm" novalidate>
  <div class="card border-0 shadow-sm mb-3" style="border-radius:14px;"><div class="card-body p-4">
    <div class="row g-3">
      <div class="col-12 col-md-6"><label class="form-label small fw-semibold mb-1">Supplier <span class="text-muted fw-normal">(optional)</span></label><input name="supplier" class="form-control" placeholder="e.g. Samsung Distributors"></div>
      <div class="col-12 col-md-3"><label class="form-label small fw-semibold mb-1">Paid with</label>
        <select name="payment_method" class="form-select"><option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="bank">Bank</option></select></div>
      <div class="col-12 col-md-3"><label class="form-label small fw-semibold mb-1">Note <span class="text-muted fw-normal">(optional)</span></label><input name="notes" class="form-control" placeholder="e.g. invoice #"></div>
      <div class="col-12"><div class="small text-muted"><i class="fas fa-circle-info me-1"></i>The total cost is recorded as an expense (<em>Purchases (capital)</em>) and comes off your money at hand in Finances.</div></div>
    </div>
  </div></div>

  <div class="card border-0 shadow-sm mb-3" style="border-radius:14px;"><div class="card-body p-4">
    <h2 class="h5 mb-1">Items bought</h2>
    <p class="small text-muted mb-3">These go to your purchases store — not on sale until you move them into a shop's inventory.</p>
    <div id="puRows"></div>
    <button type="button" class="btn btn-outline-primary w-100" id="puAdd" style="border-style:dashed;"><i class="fas fa-plus me-1"></i>Add another item</button>
  </div></div>

  <div class="pu-bar">
    <div><div class="small text-muted"><span id="puCount">1</span> item(s) · <span id="puUnits">0</span> units</div><div class="fw-bold">Total <span id="puTotal">KES 0</span></div></div>
    <button class="btn btn-primary px-4"><i class="fas fa-cart-flatbed me-1"></i>Save purchase</button>
  </div>
</form>
<datalist id="puCats"><?php foreach ($cats as $c): ?><option value="<?php echo htmlspecialchars($c); ?>"><?php endforeach; ?></datalist>

<template id="puTpl">
  <div class="pu-row border rounded p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <span class="pu-num">__N__</span>
      <button type="button" class="btn btn-sm btn-link text-danger p-0 pu-del">Remove</button>
    </div>
    <div class="row g-2">
      <div class="col-12 col-md-7"><label class="form-label small mb-1">Item</label><input name="items[__I__][name]" class="form-control pu-name" placeholder="e.g. Samsung A35 5G, Maize flour 2kg"></div>
      <div class="col-12 col-md-5"><label class="form-label small mb-1">Category <span class="text-muted">(optional)</span></label><input name="items[__I__][category]" class="form-control" list="puCats" placeholder="e.g. Phones"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Packaged in</label>
        <select name="items[__I__][package_type]" class="form-select pu-type">
          <?php foreach (Models\PurchaseModel::PACKAGES as $p): ?><option value="<?php echo $p; ?>"><?php echo ucfirst($p); ?>s</option><?php endforeach; ?>
        </select></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">How many <span class="pu-tlabel">cartons</span></label><input type="number" name="items[__I__][packages]" class="form-control pu-pk" min="0" step="any" placeholder="e.g. 20"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Units in 1 <span class="pu-tsing">carton</span></label><input type="number" name="items[__I__][units_per_package]" class="form-control pu-per" min="1" step="1" value="1"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Price per <span class="pu-tsing">carton</span> <span class="text-muted">(optional)</span></label><input type="number" name="items[__I__][package_price]" class="form-control pu-price" min="0" step="any" placeholder="KES"></div>
      <div class="col-12"><div class="pu-math small"></div></div>
    </div>
  </div>
</template>

<style>
.pu-row{border-color:#e2e8f0!important;background:#fff;}
.pu-row:focus-within{box-shadow:0 0 0 3px rgba(13,110,253,.12);border-color:#93c5fd!important;}
.pu-num{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:#eef2ff;color:#3b5bdb;font-weight:700;font-size:.8rem;}
.pu-math{background:#f8fafc;border-radius:8px;padding:6px 10px;color:#334155;}
.pu-bar{position:sticky;bottom:0;z-index:30;display:flex;justify-content:space-between;align-items:center;gap:12px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px 16px;box-shadow:0 -6px 20px rgba(15,23,42,.08);}
@media (max-width:768px){ .pu-bar{bottom:calc(64px + env(safe-area-inset-bottom));} }
</style>
<script>
(function () {
  var tpl = document.getElementById('puTpl').innerHTML, rows = document.getElementById('puRows'), idx = 0;
  function money(n) { return 'KES ' + (Math.round(n * 100) / 100).toLocaleString('en-KE'); }
  function plural(t, n) { return n == 1 ? t : (t === 'box' ? 'boxes' : t + 's'); }
  function add(focus) {
    var w = document.createElement('div'); w.innerHTML = tpl.replace(/__I__/g, idx).replace(/__N__/g, rows.children.length + 1); idx++;
    var r = w.firstElementChild; rows.appendChild(r); upd();
    if (focus) { r.scrollIntoView({behavior: 'smooth', block: 'center'}); setTimeout(function () { r.querySelector('.pu-name').focus(); }, 250); }
  }
  function upd() {
    var total = 0, units = 0;
    Array.prototype.forEach.call(rows.children, function (r, i) {
      r.querySelector('.pu-num').textContent = i + 1;
      var t = r.querySelector('.pu-type').value, pk = parseFloat(r.querySelector('.pu-pk').value) || 0,
          per = Math.max(1, parseInt(r.querySelector('.pu-per').value, 10) || 1), pr = parseFloat(r.querySelector('.pu-price').value) || 0;
      r.querySelectorAll('.pu-tlabel').forEach(function (x) { x.textContent = plural(t, 2); });
      r.querySelectorAll('.pu-tsing').forEach(function (x) { x.textContent = t; });
      var u = pk * per, cost = pk * pr, m = r.querySelector('.pu-math');
      units += u; total += cost;
      m.innerHTML = pk > 0 ? '<strong>' + pk + ' ' + plural(t, pk) + '</strong> × ' + per + ' = <strong>' + (Math.round(u * 100) / 100) + ' units</strong>'
          + (pr > 0 ? ' · cost ' + money(cost) + (per > 1 ? ' (' + money(pr / per) + ' per unit)' : '') : '') : '<span class="text-muted">Enter how many ' + plural(t, 2) + ' came in.</span>';
    });
    document.getElementById('puCount').textContent = rows.children.length;
    document.getElementById('puUnits').textContent = Math.round(units * 100) / 100;
    document.getElementById('puTotal').textContent = money(total);
  }
  rows.addEventListener('input', upd); rows.addEventListener('change', upd);
  rows.addEventListener('click', function (e) { var b = e.target.closest('.pu-del'); if (b && rows.children.length > 1) { b.closest('.pu-row').remove(); upd(); } });
  document.getElementById('puAdd').addEventListener('click', function () { add(true); });
  document.getElementById('puForm').addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); } });
  add(false);
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
