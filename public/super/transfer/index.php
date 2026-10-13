<?php
// public/super/transfer/index.php — Admin portal: send products from this
// shop's inventory to any of the owner's other shops. Pick a quantity for
// each product (serial-tracked phones are picked unit by unit), choose the
// shop, and transfer. New products are created in that shop automatically.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::tenant(); // owner only

$pdo = Database::pdo();
$T = new Models\ShopTransferModel($pdo);
$selfUrl = public_url('super/transfer/');
$fromTid = (int) TenantContext::tenantId();
$targets = array_values(array_filter(ShopContext::shops(true), fn($s) => (int) $s['id'] !== $fromTid));
$EL = Models\ElectronicsModel::enabled() ? new Models\ElectronicsModel($pdo) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = $T->transfer($fromTid, (int) ($_POST['to_shop'] ?? 0), (array) ($_POST['qty'] ?? []), (array) ($_POST['serial_ids'] ?? []), (string) ($_POST['notes'] ?? ''));
    if ($res['ok']) {
        $to = '';
        foreach ($targets as $t) { if ((int) $t['id'] === (int) $_POST['to_shop']) { $to = $t['name']; } }
        $_SESSION['flash']['success'] = 'Sent ' . $res['items'] . ' product' . ($res['items'] === 1 ? '' : 's') . ' (' . rtrim(rtrim(number_format($res['qty'], 2), '0'), '.') . ' units) to ' . $to . ' — on sale there now.';
    } else {
        $_SESSION['flash']['error'] = $res['error'];
    }
    header('Location: ' . $selfUrl);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT p.id, p.name, p.quantity, p.unit, p.buying_price, p.retail_price, p.image_path, c.name AS category_name
       FROM products p LEFT JOIN categories c ON c.id = p.category_id
      WHERE p.tenant_id = ? AND p.status <> 'archived' AND p.quantity > 0
   ORDER BY p.name"
);
$stmt->execute([$fromTid]);
$products = $stmt->fetchAll();
$serials = [];
if ($EL) {
    $s = $pdo->prepare("SELECT id, product_id, serial, color FROM device_serials WHERE tenant_id = ? AND status = 'in_stock' AND product_id IS NOT NULL ORDER BY id");
    $s->execute([$fromTid]);
    foreach ($s->fetchAll() as $r) { $serials[(int) $r['product_id']][] = $r; }
}
$history = $T->recent(array_column(ShopContext::shops(), 'id'), 20);
$__tenant = (new Models\TenantModel($pdo))->find($fromTid);
$qtyFmt = fn($q) => rtrim(rtrim(number_format((float) $q, 2), '0'), '.');

$page_title = 'Transfer to shops';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div>
    <h1 class="h5 fw-bold mb-0"><i class="fas fa-right-left me-2 text-success"></i>Transfer to shops</h1>
    <div class="small text-muted">From <strong><?php echo htmlspecialchars($__tenant['name'] ?? 'this shop'); ?></strong>'s inventory. Products new to that shop are created there with the same details and prices.</div>
  </div>
</div>

<?php if (!$targets): ?>
  <div class="alert alert-info">You don't have another active shop to send to yet. <a href="<?php echo public_url('super/shops/'); ?>">Open a shop</a> first.</div>
<?php elseif (!$products): ?>
  <div class="alert alert-info">Nothing in stock here to transfer. <a href="<?php echo public_url('super/stock/new.php'); ?>">Record stock</a> first.</div>
<?php else: ?>
<form method="post" id="trForm">
  <div class="card border-0 shadow-sm mb-3" style="border-radius:14px;"><div class="card-body p-3 p-md-4">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-5">
        <label class="form-label small fw-semibold mb-1">Send to</label>
        <select name="to_shop" class="form-select" required>
          <?php foreach ($targets as $t): ?><option value="<?php echo (int) $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-7">
        <label class="form-label small fw-semibold mb-1">Note <span class="text-muted fw-normal">(optional)</span></label>
        <input name="notes" class="form-control" maxlength="255" placeholder="e.g. weekly restock, sent with Brian">
      </div>
    </div>
  </div></div>

  <div class="card border-0 shadow-sm mb-3" style="border-radius:14px;"><div class="card-body p-3 p-md-4">
    <div class="d-flex gap-2 mb-3 flex-wrap">
      <div class="position-relative flex-grow-1" style="min-width:200px;">
        <i class="fas fa-magnifying-glass position-absolute text-muted" style="left:12px;top:50%;transform:translateY(-50%);"></i>
        <input type="search" id="trSearch" class="form-control ps-5" placeholder="Search products…" autocomplete="off">
      </div>
      <button type="button" class="btn btn-outline-secondary btn-sm" id="trOnlyPicked">Show picked only</button>
    </div>
    <div class="tr-list">
      <?php foreach ($products as $p): $pid = (int) $p['id']; $units = $serials[$pid] ?? []; ?>
      <div class="tr-row" data-name="<?php echo htmlspecialchars(strtolower($p['name'] . ' ' . ($p['category_name'] ?? ''))); ?>">
        <div class="d-flex align-items-center gap-3">
          <?php if ($p['image_path']): ?><img src="<?php echo htmlspecialchars($p['image_path']); ?>" class="tr-img" alt=""><?php else: ?><div class="tr-img tr-ph"><i class="fas fa-box"></i></div><?php endif; ?>
          <div class="flex-grow-1 min-w-0">
            <div class="fw-semibold text-truncate"><?php echo htmlspecialchars($p['name']); ?></div>
            <div class="small text-muted"><?php echo htmlspecialchars($p['category_name'] ?: 'Uncategorised'); ?> · <strong><?php echo $qtyFmt($p['quantity']); ?></strong> <?php echo htmlspecialchars($p['unit']); ?> in stock</div>
          </div>
          <div class="tr-qty">
            <?php if ($units): ?>
              <input type="number" name="qty[<?php echo $pid; ?>]" class="form-control text-center tr-q" value="" placeholder="0" readonly title="Tick the units below">
            <?php else: ?>
              <button type="button" class="tr-step" data-step="-1" aria-label="One less">−</button>
              <input type="number" name="qty[<?php echo $pid; ?>]" class="form-control text-center tr-q" min="0" max="<?php echo (float) $p['quantity']; ?>" step="any" placeholder="0" inputmode="decimal">
              <button type="button" class="tr-step" data-step="1" aria-label="One more">+</button>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($units): ?>
          <div class="tr-units">
            <span class="small text-muted me-1"><i class="fas fa-mobile-screen me-1"></i>Tick the units to send:</span>
            <?php foreach ($units as $u): ?>
              <label class="tr-unit"><input type="checkbox" class="form-check-input me-1 tr-sn" name="serial_ids[<?php echo $pid; ?>][]" value="<?php echo (int) $u['id']; ?>"><?php echo htmlspecialchars($u['serial']); ?><?php echo $u['color'] ? ' · ' . htmlspecialchars($u['color']) : ''; ?></label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <div class="text-muted small text-center py-3" id="trEmpty" style="display:none;">No products match.</div>
    </div>
  </div></div>

  <div class="tr-bar">
    <div class="small"><strong id="trCount">0</strong> products · <strong id="trUnits">0</strong> units picked</div>
    <button class="btn btn-success px-4" id="trSubmit" disabled><i class="fas fa-right-left me-1"></i>Transfer</button>
  </div>
</form>
<?php endif; ?>

<div class="card border-0 shadow-sm mt-4" style="border-radius:14px;"><div class="card-body p-3 p-md-4">
  <h2 class="h6 fw-bold mb-3">Recent transfers</h2>
  <?php if (!$history): ?>
    <div class="text-muted small">No transfers yet.</div>
  <?php endif; ?>
  <?php foreach ($history as $h): ?>
    <div class="border-bottom py-2">
      <div class="d-flex justify-content-between flex-wrap gap-2 small">
        <div><strong><?php echo htmlspecialchars($h['from_name'] ?? '?'); ?></strong> <i class="fas fa-arrow-right mx-1 text-muted"></i> <strong><?php echo htmlspecialchars($h['to_name'] ?? '?'); ?></strong>
          <span class="text-muted">· <?php echo date('j M Y, g:i a', strtotime($h['created_at'])); ?> · by <?php echo htmlspecialchars($h['by_name'] ?? '—'); ?></span></div>
        <div><?php echo (int) $h['item_count']; ?> products · <?php echo $qtyFmt($h['total_qty']); ?> units · cost KES <?php echo number_format((float) $h['total_value'], 0); ?></div>
      </div>
      <div class="small text-muted mt-1">
        <?php echo htmlspecialchars(implode(', ', array_map(fn($l) => $qtyFmt($l['quantity']) . ' × ' . $l['product_name'] . ($l['serials'] ? ' [' . $l['serials'] . ']' : ''), $h['lines']))); ?>
        <?php if ($h['notes']): ?><div class="fst-italic"><?php echo htmlspecialchars($h['notes']); ?></div><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div></div>

<style>
.tr-row{padding:12px 0;border-bottom:1px solid #f1f3f6;}
.tr-row.picked{background:#f0fdf4;margin:0 -12px;padding:12px;border-radius:10px;}
.tr-img{width:44px;height:44px;border-radius:10px;object-fit:cover;flex-shrink:0;background:#f3f4f7;}
.tr-ph{display:flex;align-items:center;justify-content:center;color:#b7bac3;}
.tr-qty{display:flex;align-items:center;gap:4px;flex-shrink:0;}
.tr-qty .tr-q{width:70px;}
.tr-step{width:34px;height:38px;border:1px solid #e2e4ea;background:#fff;border-radius:8px;font-weight:700;}
.tr-units{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;padding-left:56px;align-items:center;}
.tr-unit{font-size:.8rem;border:1px solid #e2e4ea;border-radius:8px;padding:3px 8px;font-family:ui-monospace,monospace;background:#fff;cursor:pointer;}
.tr-bar{position:sticky;bottom:0;z-index:20;display:flex;justify-content:space-between;align-items:center;gap:12px;
        background:#fff;border:1px solid #e2e4ea;border-radius:14px;padding:12px 16px;box-shadow:0 -6px 20px rgba(16,24,40,.08);}
@media (max-width:768px){
  .tr-bar{bottom:calc(64px + env(safe-area-inset-bottom));}
  .tr-units{padding-left:0;}
  .tr-qty .tr-q{width:56px;}
}
</style>
<script>
(function () {
  var form = document.getElementById('trForm'); if (!form) { return; }
  var rows = Array.prototype.slice.call(document.querySelectorAll('.tr-row')), onlyPicked = false;
  function update() {
    var n = 0, units = 0;
    rows.forEach(function (r) {
      var q = r.querySelector('.tr-q'), sn = r.querySelectorAll('.tr-sn');
      if (sn.length) { var c = r.querySelectorAll('.tr-sn:checked').length; q.value = c || ''; }
      var v = parseFloat(q.value) || 0, max = parseFloat(q.max);
      if (!sn.length && max && v > max) { q.value = max; v = max; }
      r.classList.toggle('picked', v > 0);
      if (v > 0) { n++; units += v; }
    });
    document.getElementById('trCount').textContent = n;
    document.getElementById('trUnits').textContent = Math.round(units * 100) / 100;
    document.getElementById('trSubmit').disabled = n === 0;
    filter();
  }
  function filter() {
    var term = document.getElementById('trSearch').value.trim().toLowerCase(), shown = 0;
    rows.forEach(function (r) {
      var ok = (!term || r.dataset.name.indexOf(term) !== -1) && (!onlyPicked || r.classList.contains('picked'));
      r.style.display = ok ? '' : 'none'; if (ok) { shown++; }
    });
    document.getElementById('trEmpty').style.display = shown ? 'none' : '';
  }
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  form.addEventListener('click', function (e) {
    var b = e.target.closest('.tr-step'); if (!b) { return; }
    var q = b.parentNode.querySelector('.tr-q');
    q.value = Math.max(0, (parseFloat(q.value) || 0) + parseInt(b.dataset.step, 10)) || '';
    update();
  });
  document.getElementById('trSearch').addEventListener('input', filter);
  document.getElementById('trOnlyPicked').addEventListener('click', function () {
    onlyPicked = !onlyPicked; this.classList.toggle('active', onlyPicked); filter();
  });
  form.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); } });
  form.addEventListener('submit', function (e) {
    var shop = form.querySelector('[name=to_shop]');
    if (!confirm('Send ' + document.getElementById('trUnits').textContent + ' units to ' + shop.options[shop.selectedIndex].text + '?')) { e.preventDefault(); return; }
    document.getElementById('trSubmit').disabled = true;
  });
  update();
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
