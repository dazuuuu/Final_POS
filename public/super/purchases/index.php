<?php
// public/super/purchases/index.php — owner: the purchases store (bought, not
// on sale yet) and moving stock from it into this shop's or a branch's
// inventory, by package or by unit, with the selling prices.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::tenant(); // owner only

$pdo = Database::pdo();
$PM = new Models\PurchaseModel($pdo);
$self = public_url('super/purchases/');
$shops = ShopContext::shops(true) ?: [['id' => TenantContext::tenantId(), 'name' => 'This shop']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = $PM->moveToInventory((int) ($_POST['item_id'] ?? 0), (int) ($_POST['shop_id'] ?? 0), (string) ($_POST['move_mode'] ?? 'unit'),
                                (float) ($_POST['quantity'] ?? 0), (string) ($_POST['sell_mode'] ?? 'unit'), $_POST['retail_price'] ?? '', $_POST['wholesale_price'] ?? '');
    if ($res['ok']) {
        $shop = '';
        foreach ($shops as $s) { if ((int) $s['id'] === (int) $_POST['shop_id']) { $shop = $s['name']; } }
        $_SESSION['flash']['success'] = 'Moved — ' . Models\PurchaseModel::fmt($res['shelf_qty']) . ' × ' . $res['product'] . ' now on sale' . (count($shops) > 1 ? ' at ' . $shop : '') . '.';
    } else {
        $_SESSION['flash']['error'] = $res['error'];
    }
    header('Location: ' . $self);
    exit;
}

$items = $PM->inStore();
$purchases = $PM->recentPurchases();
$moves = $PM->recentTransfers();
$f = fn($n) => Models\PurchaseModel::fmt($n);
$money = fn($n) => 'KES ' . number_format((float) $n, 0);

$page_title = 'Purchases';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div>
    <h1 class="h5 fw-bold mb-0"><i class="fas fa-cart-flatbed me-2 text-primary"></i>Purchases</h1>
    <div class="small text-muted">Bought stock waits here until you move it into a shop's inventory to sell.</div>
  </div>
  <a class="btn btn-primary" href="<?php echo public_url('super/purchases/new.php'); ?>"><i class="fas fa-plus me-1"></i>Record a purchase</a>
</div>

<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;"><div class="card-body p-3 p-md-4">
  <h2 class="h6 fw-bold mb-3">In the purchases store <span class="badge bg-secondary"><?php echo count($items); ?></span></h2>
  <?php if (!$items): ?>
    <div class="text-muted small py-3 text-center">Nothing waiting. <a href="<?php echo public_url('super/purchases/new.php'); ?>">Record a purchase</a>.</div>
  <?php endif; ?>
  <?php foreach ($items as $it): $iid = (int) $it['id']; $per = (int) $it['units_per_package']; $pkgPrice = $it['package_price'] !== null ? (float) $it['package_price'] : null; ?>
    <div class="pu-item">
      <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <div class="min-w-0">
          <div class="fw-semibold"><?php echo htmlspecialchars($it['name']); ?> <?php if ($it['category']): ?><span class="badge bg-light text-dark border fw-normal"><?php echo htmlspecialchars($it['category']); ?></span><?php endif; ?></div>
          <div class="small text-muted">
            <strong class="text-dark"><?php echo htmlspecialchars(Models\PurchaseModel::describe((float) $it['units_remaining'], $per, $it['package_type'])); ?></strong>
            <?php if ($per > 1): ?>· <?php echo $f($it['units_remaining']); ?> units · <?php echo $per; ?> per <?php echo htmlspecialchars($it['package_type']); ?><?php endif; ?>
            <?php if ($pkgPrice !== null): ?>· cost <?php echo $money($pkgPrice); ?>/<?php echo htmlspecialchars($it['package_type']); ?><?php echo $per > 1 ? ' (' . $money($pkgPrice / $per) . '/unit)' : ''; ?><?php endif; ?>
            <?php echo $it['supplier'] ? '· ' . htmlspecialchars($it['supplier']) : ''; ?> · bought <?php echo date('j M', strtotime($it['purchased_at'])); ?>
          </div>
        </div>
        <button class="btn btn-sm btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#mv<?php echo $iid; ?>"><i class="fas fa-right-to-bracket me-1"></i>Move to inventory</button>
      </div>
      <form method="post" class="collapse mt-3 pu-move" id="mv<?php echo $iid; ?>"
            data-per="<?php echo $per; ?>" data-left="<?php echo (float) $it['units_remaining']; ?>" data-type="<?php echo htmlspecialchars($it['package_type']); ?>"
            data-name="<?php echo htmlspecialchars($it['name']); ?>" data-pkgprice="<?php echo $pkgPrice ?? ''; ?>">
        <input type="hidden" name="item_id" value="<?php echo $iid; ?>">
        <div class="row g-2">
          <?php if (count($shops) > 1): ?>
          <div class="col-12 col-md-4"><label class="form-label small mb-1">Into which shop</label>
            <select name="shop_id" class="form-select form-select-sm">
              <?php foreach ($shops as $s): ?><option value="<?php echo (int) $s['id']; ?>" <?php echo (int) $s['id'] === (int) TenantContext::tenantId() ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option><?php endforeach; ?>
            </select></div>
          <?php else: ?>
            <input type="hidden" name="shop_id" value="<?php echo (int) TenantContext::tenantId(); ?>">
          <?php endif; ?>
          <div class="col-6 col-md-4"><label class="form-label small mb-1">Move</label>
            <div class="input-group input-group-sm">
              <input type="number" name="quantity" class="form-control pu-q" min="0" step="any" placeholder="0" required>
              <?php if ($per > 1): ?>
              <select name="move_mode" class="form-select pu-mm" style="max-width:120px;"><option value="package"><?php echo htmlspecialchars(Models\PurchaseModel::packageLabel($it['package_type'])); ?></option><option value="unit">Units</option></select>
              <?php else: ?><input type="hidden" name="move_mode" value="unit"><span class="input-group-text">units</span><?php endif; ?>
            </div></div>
          <div class="col-6 col-md-4"><label class="form-label small mb-1">Sell it</label>
            <?php if ($per > 1): ?>
            <select name="sell_mode" class="form-select form-select-sm pu-sm"><option value="unit">Per unit</option><option value="package">Per <?php echo htmlspecialchars($it['package_type']); ?> (<?php echo $per; ?> units)</option></select>
            <?php else: ?><input type="hidden" name="sell_mode" value="unit"><div class="form-control form-control-sm bg-light">Per unit</div><?php endif; ?>
          </div>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">Retail price <span class="pu-per-l">per unit</span></label><input type="number" name="retail_price" class="form-control form-control-sm pu-r" min="0" step="any" placeholder="KES"></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">Wholesale price <span class="pu-per-l">per unit</span></label><input type="number" name="wholesale_price" class="form-control form-control-sm pu-w" min="0" step="any" placeholder="KES (optional)"></div>
          <div class="col-12 col-md-6 d-flex align-items-end"><button class="btn btn-sm btn-success w-100">Move to inventory</button></div>
          <div class="col-12"><div class="pu-explain small"></div></div>
        </div>
      </form>
    </div>
  <?php endforeach; ?>
</div></div>

<div class="row g-4">
  <div class="col-12 col-lg-6"><div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-3 p-md-4">
    <h2 class="h6 fw-bold mb-3">Recent purchases</h2>
    <?php if (!$purchases): ?><div class="small text-muted">None yet.</div><?php endif; ?>
    <?php foreach ($purchases as $p): ?>
      <div class="border-bottom py-2 small">
        <div class="d-flex justify-content-between"><strong><?php echo date('j M Y', strtotime($p['created_at'])); ?><?php echo $p['supplier'] ? ' · ' . htmlspecialchars($p['supplier']) : ''; ?></strong><span><?php echo (float) $p['total_cost'] > 0 ? $money($p['total_cost']) : ''; ?></span></div>
        <div class="text-muted"><?php echo htmlspecialchars($p['summary'] ?? ''); ?><?php echo $p['notes'] ? ' — ' . htmlspecialchars($p['notes']) : ''; ?></div>
      </div>
    <?php endforeach; ?>
  </div></div></div>
  <div class="col-12 col-lg-6"><div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-3 p-md-4">
    <h2 class="h6 fw-bold mb-3">Moved to inventory</h2>
    <?php if (!$moves): ?><div class="small text-muted">Nothing moved yet.</div><?php endif; ?>
    <?php foreach ($moves as $m): ?>
      <div class="border-bottom py-2 small">
        <div class="d-flex justify-content-between"><strong><?php echo htmlspecialchars($m['product_name']); ?></strong><span class="text-muted"><?php echo date('j M, g:i a', strtotime($m['created_at'])); ?></span></div>
        <div class="text-muted"><?php echo htmlspecialchars(Models\PurchaseModel::describe((float) $m['units'], (int) $m['units_per_package'], $m['package_type'])); ?>
          → <?php echo $m['sell_mode'] === 'package' ? $f($m['units'] / max(1, (int) $m['units_per_package'])) . ' on sale per ' . htmlspecialchars($m['package_type']) : $f($m['units']) . ' on sale per unit'; ?>
          <?php echo $m['retail_price'] ? '· retail ' . $money($m['retail_price']) : ''; ?><?php echo $m['wholesale_price'] ? ' · wholesale ' . $money($m['wholesale_price']) : ''; ?>
          <?php echo count($shops) > 1 ? '· ' . htmlspecialchars($m['shop_name'] ?? '') : ''; ?></div>
      </div>
    <?php endforeach; ?>
  </div></div></div>
</div>

<style>
.pu-item{padding:14px 0;border-bottom:1px solid #f1f3f6;}
.pu-explain{background:#f0fdf4;border:1px solid #bbf0d1;border-radius:8px;padding:8px 10px;color:#14532d;}
.pu-explain.bad{background:#fef2f2;border-color:#fecaca;color:#991b1b;}
</style>
<script>
(function () {
  function money(n) { return 'KES ' + (Math.round(n * 100) / 100).toLocaleString('en-KE'); }
  function plural(t, n) { return n == 1 ? t : (t === 'box' ? 'boxes' : t + 's'); }
  function fmt(n) { return String(Math.round(n * 100) / 100); }
  function describe(units, per, t) {
    if (per <= 1) { return fmt(units) + ' units'; }
    var full = Math.floor(units / per + 1e-6), loose = Math.round((units - full * per) * 100) / 100, p = [];
    if (full) { p.push(full + ' ' + plural(t, full)); }
    if (loose > 0 || !full) { p.push(fmt(loose) + ' units'); }
    return p.join(' + ');
  }
  document.querySelectorAll('.pu-move').forEach(function (f) {
    var per = parseInt(f.dataset.per, 10), left = parseFloat(f.dataset.left), t = f.dataset.type, name = f.dataset.name, pkgPrice = parseFloat(f.dataset.pkgprice);
    function upd() {
      var q = parseFloat(f.querySelector('.pu-q').value) || 0, mm = f.querySelector('.pu-mm'), sm = f.querySelector('.pu-sm');
      var moveMode = mm ? mm.value : 'unit', sellMode = sm ? sm.value : 'unit';
      var units = moveMode === 'package' ? q * per : q, ex = f.querySelector('.pu-explain'), r = parseFloat(f.querySelector('.pu-r').value) || 0;
      f.querySelectorAll('.pu-per-l').forEach(function (x) { x.textContent = sellMode === 'package' ? 'per ' + t : 'per unit'; });
      ex.classList.remove('bad');
      if (!q) { ex.innerHTML = 'Available: <strong>' + describe(left, per, t) + '</strong>' + (per > 1 ? ' (' + fmt(left) + ' units)' : '') + '.'; return; }
      if (units > left + 1e-6) { ex.classList.add('bad'); ex.textContent = 'That\'s ' + fmt(units) + ' units — only ' + describe(left, per, t) + ' left.'; return; }
      var shelf = sellMode === 'package' ? units / per : units;
      if (sellMode === 'package' && Math.abs(shelf - Math.round(shelf)) > 1e-6) { ex.classList.add('bad'); ex.textContent = 'To sell per ' + t + ', move whole ' + plural(t, 2) + ' (' + per + ' units each).'; return; }
      var prod = sellMode === 'package' ? name + ' (' + t.charAt(0).toUpperCase() + t.slice(1) + ' of ' + per + ')' : name;
      var cost = isNaN(pkgPrice) ? null : (sellMode === 'package' ? pkgPrice : pkgPrice / per);
      ex.innerHTML = 'Moving <strong>' + describe(units, per, t) + '</strong>' + (per > 1 ? ' = ' + fmt(units) + ' units' : '')
        + ' → <strong>' + fmt(shelf) + ' × ' + prod + '</strong> on sale' + (r ? ' at <strong>' + money(r) + '</strong> each' : '')
        + (cost !== null ? ' (cost ' + money(cost) + ' each' + (r ? ', profit ' + money(r - cost) + ' each' : '') + ')' : '') + '.'
        + ' Left in purchases: ' + describe(left - units, per, t) + '.';
    }
    f.addEventListener('input', upd); f.addEventListener('change', upd); upd();
  });
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
