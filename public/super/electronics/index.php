<?php
// public/super/electronics/index.php — Devices & serials: which products are
// serial-tracked, register serials for units already on the shelf, and find
// any device by serial / IMEI, product or customer.
require_once __DIR__ . '/../../../app/app.php';
define('ELEC_CAP', Capabilities::INVENTORY_VIEW);
require_once __DIR__ . '/_common.php';

$selfUrl = public_url('super/electronics/');
$canEdit = TenantContext::can(Capabilities::STOCK_ENTER);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $action = $_POST['action'] ?? '';
    if ($action === 'track') {
        $pid = (int) ($_POST['product_id'] ?? 0);
        if ($pid > 0) {
            $on = ($_POST['on'] ?? '1') === '1';
            $EL->setProductTracking($pid, $on, Models\ElectronicsModel::warrantyFromInput($_POST['warranty_years'] ?? 0, $_POST['warranty_months'] ?? 0),
                                    $on && isset($_POST['colors']) ? Models\ElectronicsModel::parseColors((string) $_POST['colors']) : null);
            $_SESSION['flash']['success'] = ($_POST['on'] ?? '1') === '1' ? 'Product is now serial-tracked.' : 'Serial tracking turned off for that product.';
        }
    } elseif ($action === 'register') {
        $res = $EL->addSerials((int) ($_POST['product_id'] ?? 0), Models\ElectronicsModel::parseList((string) ($_POST['serials'] ?? '')),
                               null, trim((string) ($_POST['serial2'] ?? '')) ?: null);
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? $res['added'] . ' serial(s) recorded.' : $res['error'];
    } elseif ($action === 'delete') {
        $_SESSION['flash'][$EL->deleteInStock((int) ($_POST['id'] ?? 0)) ? 'success' : 'error'] = 'Serial removed.';
    }
    header('Location: ' . $selfUrl . (isset($_POST['back']) ? '?' . $_POST['back'] : ''));
    exit;
}

$status = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');
$counts = $EL->counts();
$tracked = $EL->productsNeedingSerials();
$devices = $EL->devices($status, $q);
$untracked = [];
if ($canEdit) {
    $st = $pdo->prepare("SELECT id, name FROM products WHERE tenant_id = ? AND status <> 'archived' AND is_serialized = 0 ORDER BY name");
    $st->execute([TenantContext::tenantId()]);
    $untracked = $st->fetchAll();
}
$back = http_build_query(array_filter(['status' => $status, 'q' => $q]));

ob_start();
?>
<div class="row g-3 mb-3">
  <?php foreach ([['in_stock', 'In stock', 'fa-box', 'tk-greenbg'], ['sold', 'Sold', 'fa-receipt', 'tk-blue'], ['in_repair', 'In repair', 'fa-screwdriver-wrench', 'tk-orange'], ['returned', 'Ever returned', 'fa-rotate-left', 'tk-blue']] as [$k, $label, $icon, $cls]): ?>
  <div class="col-6 col-lg-3">
    <a class="text-decoration-none text-reset" href="<?php echo $selfUrl . '?status=' . $k; ?>">
      <div class="tk-stat"><div class="tk-stat-icon <?php echo $cls; ?>"><i class="fas <?php echo $icon; ?>"></i></div>
        <div class="tk-stat-body"><div class="tk-stat-num"><?php echo (int) $counts[$k]; ?></div><div class="tk-stat-label"><?php echo $label; ?></div></div></div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-4">
  <div class="col-12 col-lg-4">
    <?php if ($canEdit): ?>
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-1">Record serials for stock on the shelf</h2>
      <p class="small text-muted mb-3">For units already in stock. New deliveries can carry serials from Record stock. Anything left out is asked for when it's sold.</p>
      <?php $needs = array_filter($tracked, fn($t) => (int) floor((float) $t['quantity']) > (int) $t['registered']); ?>
      <?php if (!$tracked): ?>
        <div class="small text-muted">No product is serial-tracked yet. Turn tracking on for a product below.</div>
      <?php elseif (!$needs): ?>
        <div class="small text-success"><i class="fas fa-check me-1"></i>Every unit in stock has a serial.</div>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="register"><input type="hidden" name="back" value="<?php echo elec_h($back); ?>">
        <select name="product_id" class="form-select mb-2" required>
          <?php foreach ($needs as $t): ?>
            <option value="<?php echo (int) $t['id']; ?>"><?php echo elec_h($t['name']); ?> — <?php echo (int) floor((float) $t['quantity']) - (int) $t['registered']; ?> without serial</option>
          <?php endforeach; ?>
        </select>
        <textarea name="serials" class="form-control text-uppercase mb-2" rows="4" placeholder="One serial / IMEI per line — or scan them one after another" required></textarea>
        <input name="serial2" class="form-control form-control-sm text-uppercase mb-2" placeholder="Second IMEI (dual-SIM phone — only when entering one unit)">
        <button class="btn btn-success w-100"><i class="fas fa-floppy-disk me-1"></i>Save serials</button>
      </form>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Serial-tracked products</h2>
      <?php foreach ($tracked as $t): $units = (int) floor((float) $t['quantity']); ?>
        <div class="d-flex justify-content-between align-items-center border-bottom py-2 gap-2">
          <div class="min-w-0">
            <div class="small fw-semibold text-truncate"><?php echo elec_h($t['name']); ?></div>
            <div class="small text-muted"><?php echo (int) $t['registered']; ?>/<?php echo $units; ?> in stock have serials · <?php echo elec_h(Models\ElectronicsModel::warrantyLabel((int) $t['warranty_months'])); ?></div>
          </div>
          <?php if ($canEdit): ?>
          <form method="post" onsubmit="return confirm('Stop tracking serials for this product?');">
            <input type="hidden" name="action" value="track"><input type="hidden" name="on" value="0"><input type="hidden" name="product_id" value="<?php echo (int) $t['id']; ?>">
            <button class="btn btn-sm btn-link text-muted p-0" title="Stop tracking"><i class="fas fa-xmark"></i></button>
          </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($canEdit && $untracked): ?>
      <form method="post" class="mt-3">
        <input type="hidden" name="action" value="track"><input type="hidden" name="on" value="1">
        <label class="form-label small mb-1">Track another product</label>
        <select name="product_id" class="form-select form-select-sm mb-2" required>
          <option value="">Choose a product…</option>
          <?php foreach ($untracked as $u): ?><option value="<?php echo (int) $u['id']; ?>"><?php echo elec_h($u['name']); ?></option><?php endforeach; ?>
        </select>
        <div class="mb-2"><?php echo Models\ElectronicsModel::warrantyInputs('', 12); ?></div>
        <input name="colors" class="form-control form-control-sm mb-2" placeholder="Colours available, e.g. Black, Blue">
        <button class="btn btn-sm btn-outline-success w-100">Track</button>
      </form>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <form method="get" class="d-flex gap-2 mb-3 flex-wrap">
        <input name="q" value="<?php echo elec_h($q); ?>" class="form-control" style="flex:1;min-width:180px;" placeholder="Search serial / IMEI, product or customer">
        <select name="status" class="form-select" style="width:auto;">
          <?php foreach (['' => 'All', 'in_stock' => 'In stock', 'sold' => 'Sold', 'in_repair' => 'In repair', 'returned' => 'Ever returned'] as $k => $label): ?>
            <option value="<?php echo $k; ?>" <?php echo $status === $k ? 'selected' : ''; ?>><?php echo $label; ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-primary"><i class="fas fa-magnifying-glass"></i></button>
      </form>
      <?php if (!$devices): ?>
        <div class="text-muted small">No devices found.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr class="small text-muted text-uppercase"><th>Serial / IMEI</th><th>Product</th><th>Status</th><th>Customer · warranty</th><th class="text-center">Returns</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($devices as $d): ?>
            <tr class="small">
              <td class="fw-semibold font-monospace"><?php echo elec_h($d['serial']); ?><?php if ($d['serial2']): ?><div class="text-muted fw-normal"><?php echo elec_h($d['serial2']); ?></div><?php endif; ?></td>
              <td><?php echo elec_h($d['product_name'] ?? '—'); ?><?php if (!empty($d['color'])): ?><div class="text-muted"><?php echo elec_h($d['color']); ?></div><?php endif; ?></td>
              <td><?php echo elec_status_badge($d['status']); ?></td>
              <td>
                <?php if ($d['customer_name']): ?><?php echo elec_h($d['customer_name']); ?><?php endif; ?>
                <?php if ($d['sold_at']): ?>
                  <div class="text-muted">
                    <?php if ($d['warranty_ends_at']): ?>
                      <?php echo Models\ElectronicsModel::underWarranty($d) ? 'warranty to ' : '<span class="text-danger">expired</span> '; ?><?php echo date('j M Y', strtotime($d['warranty_ends_at'])); ?>
                    <?php else: ?>no warranty<?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td class="text-center"><?php echo (int) $d['return_count'] ?: '—'; ?></td>
              <td class="text-end text-nowrap">
                <?php if ($d['status'] !== 'in_stock' && TenantContext::can(Capabilities::SALES_RECORD)): ?>
                  <a class="btn btn-sm btn-outline-secondary" href="<?php echo public_url('super/electronics/repairs.php?serial=' . urlencode($d['serial'])); ?>">Open</a>
                <?php elseif ($d['status'] === 'in_stock' && $canEdit): ?>
                  <form method="post" class="d-inline" onsubmit="return confirm('Remove this serial? (the unit stays in stock without a serial)');">
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>"><input type="hidden" name="back" value="<?php echo elec_h($back); ?>">
                    <button class="btn btn-sm btn-link text-muted p-0" title="Remove"><i class="fas fa-trash"></i></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<?php
elec_render('Electronics', ob_get_clean());
