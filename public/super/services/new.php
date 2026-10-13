<?php
// public/super/services/new.php — front desk: a walk-in client or a booking.
// Name is required (phone / email optional); pick the services and, for each,
// optionally the staff member who'll do it.
require_once __DIR__ . '/_common.php';
svc_require($isOwner || $canBook);

$services = $SD->servicesForShop();
$staff = $SD->staffForShop();
$products = $SD->sellableProducts();
$error = '';
$old = $_POST + ['customer_name' => '', 'customer_phone' => '', 'customer_email' => '', 'notes' => '', 'scheduled_at' => '', 'kind' => $_GET['kind'] ?? 'walkin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lines = [];
    foreach ((array) ($_POST['service_id'] ?? []) as $i => $sid) {
        $lines[] = ['service_id' => (int) $sid, 'staff_id' => (int) ($_POST['staff_id'][$i] ?? 0)];
    }
    $prodLines = [];
    foreach ((array) ($_POST['product_id'] ?? []) as $i => $pid) {
        if ((int) $pid > 0) { $prodLines[] = ['product_id' => (int) $pid, 'quantity' => (float) ($_POST['product_qty'][$i] ?? 1)]; }
    }
    $in = [
        'products' => $prodLines,
        'customer_name' => $_POST['customer_name'] ?? '', 'customer_phone' => $_POST['customer_phone'] ?? '',
        'customer_email' => $_POST['customer_email'] ?? '', 'notes' => $_POST['notes'] ?? '', 'lines' => $lines,
        'scheduled_at' => ($_POST['kind'] ?? '') === 'booking' ? ($_POST['scheduled_at'] ?? '') : '',
    ];
    if (($_POST['kind'] ?? '') === 'booking' && trim($in['scheduled_at']) === '') {
        $error = 'Pick the date and time of the booking.';
    } else {
        // Remember the client in the customer list when we have a way to reach them.
        if (trim($in['customer_phone']) !== '' || trim($in['customer_email']) !== '') {
            try {
                $cust = (new Models\CustomerModel($pdo))->findOrCreateByContact(trim($in['customer_name']), trim($in['customer_email']), trim($in['customer_phone']));
                $in['customer_id'] = $cust['id'] ?? null;
            } catch (Throwable $e) { /* the visit matters more than the address book */ }
        }
        $res = $SD->create($in);
        if ($res['ok']) {
            $_SESSION['flash']['success'] = ($in['scheduled_at'] ? 'Booking saved' : 'Client added') . ' — ' . $res['number'] . '.';
            header('Location: ' . public_url('super/services/view.php?id=' . $res['id']));
            exit;
        }
        $error = $res['error'];
    }
}

ob_start();
?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo svc_h($error); ?></div><?php endif; ?>
<?php if (!$services && !$products): ?>
  <div class="alert alert-warning">No services are offered in this shop yet.<?php if ($canManage): ?> <a href="<?php echo public_url('super/services/catalog.php'); ?>">Add your services and prices</a> first.<?php endif; ?></div>
<?php else: ?>
<form method="post" id="svcForm">
  <div class="row g-3">
    <div class="col-12 col-lg-5">
      <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
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
        <label class="form-label small mb-1">Name <span class="text-danger">*</span></label>
        <input name="customer_name" class="form-control mb-2" required maxlength="150" value="<?php echo svc_h($old['customer_name']); ?>" autofocus>
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label small mb-1">Phone <span class="text-muted">(optional)</span></label><input name="customer_phone" type="tel" class="form-control" placeholder="07…" value="<?php echo svc_h($old['customer_phone']); ?>"></div>
          <div class="col-6"><label class="form-label small mb-1">Email <span class="text-muted">(optional)</span></label><input name="customer_email" type="email" class="form-control" value="<?php echo svc_h($old['customer_email']); ?>"></div>
        </div>
        <input name="notes" class="form-control" maxlength="255" placeholder="Note (optional), e.g. prefers Mary, allergic to…" value="<?php echo svc_h($old['notes']); ?>">
      </div></div>
    </div>

    <div class="col-12 col-lg-7">
      <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3">Services</h2>
        <div id="lines"></div>
        <button type="button" class="btn btn-outline-primary w-100 mt-1" id="addLine" style="border-style:dashed;"><i class="fas fa-plus me-1"></i>Add another service</button>
      </div></div>
      <?php if ($products): ?>
      <div class="card border-0 shadow-sm mt-3" style="border-radius:14px;"><div class="card-body p-4">
        <h2 class="h6 fw-bold mb-1">Products <span class="text-muted fw-normal small">(optional — hair, gel… the client is buying)</span></h2>
        <div class="small text-muted mb-3">Taken from this shop's stock.</div>
        <div id="prodLines"></div>
        <button type="button" class="btn btn-outline-secondary w-100 mt-1" id="addProd" style="border-style:dashed;"><i class="fas fa-plus me-1"></i>Add a product</button>
      </div></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="svc-bar">
    <div><?php if ($canMoney): ?><span class="small text-muted">Total</span> <strong id="svcTotal">KES 0</strong><?php else: ?><span class="small text-muted"><span id="svcCount">0</span> service(s)</span><?php endif; ?></div>
    <button class="btn btn-primary px-4"><i class="fas fa-check me-1"></i><span id="saveLabel"><?php echo $old['kind'] === 'booking' ? 'Save booking' : 'Add client'; ?></span></button>
  </div>
</form>

<template id="lineTpl">
  <div class="svc-line d-flex gap-2 align-items-end mb-2">
    <div class="flex-grow-1">
      <select name="service_id[]" class="form-select svc-sel">
        <option value="">Choose a service…</option>
        <?php foreach ($services as $s): ?>
          <option value="<?php echo (int) $s['id']; ?>" data-price="<?php echo (float) $s['price']; ?>"><?php echo svc_h($s['name']); ?><?php echo $canMoney ? ' — ' . svc_money($s['price']) : ''; ?><?php echo $s['duration_min'] ? ' · ' . (int) $s['duration_min'] . ' min' : ''; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="width:42%;">
      <select name="staff_id[]" class="form-select">
        <option value="0">Any staff (assign later)</option>
        <?php foreach ($staff as $u): ?>
          <option value="<?php echo (int) $u['id']; ?>"><?php echo svc_h($u['username']); ?><?php echo $u['role_name'] === 'tenant_owner' ? ' (owner)' : ($u['position'] ? ' · ' . svc_h($u['position']) : ''); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="button" class="btn btn-link text-danger px-1 svc-del" title="Remove"><i class="fas fa-xmark"></i></button>
  </div>
</template>
<template id="prodTpl">
  <div class="svc-line d-flex gap-2 align-items-end mb-2">
    <div class="flex-grow-1">
      <select name="product_id[]" class="form-select prod-sel">
        <option value="">Choose a product…</option>
        <?php foreach ($products as $p): ?>
          <option value="<?php echo (int) $p['id']; ?>" data-price="<?php echo (float) $p['sell_price']; ?>" data-stock="<?php echo (float) $p['quantity']; ?>"><?php echo svc_h($p['name']); ?><?php echo $canMoney ? ' — ' . svc_money($p['sell_price']) : ''; ?> · <?php echo rtrim(rtrim(number_format((float) $p['quantity'], 2), '0'), '.'); ?> in stock</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="width:90px;"><input type="number" name="product_qty[]" class="form-control prod-qty" min="0.01" step="any" value="1" aria-label="Quantity"></div>
    <button type="button" class="btn btn-link text-danger px-1 prod-del" title="Remove"><i class="fas fa-xmark"></i></button>
  </div>
</template>
<?php endif; ?>

<style>
.svc-bar{position:sticky;bottom:0;z-index:20;display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:16px;
         background:#fff;border:1px solid #e2e4ea;border-radius:14px;padding:12px 16px;box-shadow:0 -6px 20px rgba(16,24,40,.08);}
@media (max-width:768px){ .svc-bar{bottom:calc(64px + env(safe-area-inset-bottom));} .svc-line{flex-wrap:wrap;} .svc-line>div{width:100%!important;} }
</style>
<script>
(function () {
  var form = document.getElementById('svcForm'); if (!form) { return; }
  var tpl = document.getElementById('lineTpl').innerHTML, lines = document.getElementById('lines');
  function addLine(focus) {
    var w = document.createElement('div'); w.innerHTML = tpl; var row = w.firstElementChild; lines.appendChild(row);
    if (focus) { row.querySelector('.svc-sel').focus(); }
    sum();
  }
  var prodTpl = document.getElementById('prodTpl'), prods = document.getElementById('prodLines');
  function addProd(focus) {
    var w = document.createElement('div'); w.innerHTML = prodTpl.innerHTML; var row = w.firstElementChild; prods.appendChild(row);
    if (focus) { row.querySelector('.prod-sel').focus(); }
  }
  if (prods) {
    document.getElementById('addProd').addEventListener('click', function () { addProd(true); });
    prods.addEventListener('change', sum); prods.addEventListener('input', sum);
    prods.addEventListener('click', function (e) { var b = e.target.closest('.prod-del'); if (b) { b.closest('.svc-line').remove(); sum(); } });
  }
  function sum() {
    var t = 0, n = 0;
    lines.querySelectorAll('.svc-sel').forEach(function (s) { var o = s.options[s.selectedIndex]; if (s.value) { n++; t += parseFloat(o.dataset.price) || 0; } });
    if (prods) { prods.querySelectorAll('.svc-line').forEach(function (r) {
      var s = r.querySelector('.prod-sel'), q = r.querySelector('.prod-qty'), o = s.options[s.selectedIndex];
      if (s.value) { var max = parseFloat(o.dataset.stock); if ((parseFloat(q.value) || 0) > max) { q.value = max; } n++; t += (parseFloat(o.dataset.price) || 0) * (parseFloat(q.value) || 0); }
    }); }
    var tot = document.getElementById('svcTotal'); if (tot) { tot.textContent = 'KES ' + t.toLocaleString('en-KE'); }
    var c = document.getElementById('svcCount'); if (c) { c.textContent = n; }
  }
  lines.addEventListener('change', sum);
  lines.addEventListener('click', function (e) { var b = e.target.closest('.svc-del'); if (b && lines.children.length > 1) { b.closest('.svc-line').remove(); sum(); } });
  document.getElementById('addLine').addEventListener('click', function () { addLine(true); });
  form.querySelectorAll('[name=kind]').forEach(function (r) {
    r.addEventListener('change', function () {
      var book = form.querySelector('[name=kind]:checked').value === 'booking';
      document.getElementById('whenBox').style.display = book ? '' : 'none';
      document.getElementById('saveLabel').textContent = book ? 'Save booking' : 'Add client';
    });
  });
  form.addEventListener('submit', function (e) {
    var any = Array.prototype.some.call(form.querySelectorAll('.svc-sel, .prod-sel'), function (s) { return s.value; });
    if (!any) { e.preventDefault(); alert('Add at least one service or product.'); }
  });
  form.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); } });
  addLine(false);
})();
</script>
<?php
svc_render('New client', ob_get_clean());
