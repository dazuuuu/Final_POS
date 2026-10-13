<?php
// public/super/services/catalog.php — owner: the price list of services,
// each with its price, duration, default commission and the shops offering it.
require_once __DIR__ . '/_common.php';
svc_require($canManage);

$selfUrl = public_url('super/services/catalog.php');
$shops = ShopContext::shops(true);
$multi = count($shops) > 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = $SD->saveService($_POST, (int) ($_POST['id'] ?? 0) ?: null);
    $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Service saved.' : $res['error'];
    header('Location: ' . $selfUrl);
    exit;
}

$services = $SD->services();
$editId = (int) ($_GET['edit'] ?? 0);
$edit = null;
foreach ($services as $s) { if ((int) $s['id'] === $editId) { $edit = $s; } }
$shopName = [];
foreach ($shops as $sh) { $shopName[(int) $sh['id']] = $sh['name']; }

ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-4">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3"><?php echo $edit ? 'Edit service' : 'Add a service'; ?></h2>
      <form method="post">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>"><?php endif; ?>
        <label class="form-label small mb-1">Service</label>
        <input name="name" class="form-control mb-2" required maxlength="150" placeholder="e.g. Haircut, Full service, Manicure" value="<?php echo svc_h($edit['name'] ?? ''); ?>">
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label small mb-1">Price (KES)</label><input type="number" step="1" min="0" name="price" class="form-control" required value="<?php echo $edit ? (float) $edit['price'] : ''; ?>"></div>
          <div class="col-6"><label class="form-label small mb-1">Takes (minutes)</label><input type="number" step="5" min="0" name="duration_min" class="form-control" placeholder="optional" value="<?php echo svc_h($edit['duration_min'] ?? ''); ?>"></div>
        </div>
        <label class="form-label small mb-1">Staff commission <span class="text-muted">(default %, optional)</span></label>
        <div class="input-group mb-1"><input type="number" step="0.5" min="0" max="100" name="commission_pct" class="form-control" placeholder="e.g. 30" value="<?php echo svc_h($edit['commission_pct'] ?? ''); ?>"><span class="input-group-text">%</span></div>
        <?php if ($isOwner): ?><div class="form-text mb-2">Day- or staff-specific rates (e.g. weekends 50%) go under <a href="<?php echo public_url('super/services/commissions.php'); ?>">Commissions</a>.</div><?php endif; ?>
        <input name="description" class="form-control mb-2" maxlength="255" placeholder="Short description (optional)" value="<?php echo svc_h($edit['description'] ?? ''); ?>">
        <?php if ($multi): ?>
          <label class="form-label small mb-1">Offered at</label>
          <div class="mb-2">
            <?php foreach ($shops as $sh): $checked = $edit ? in_array((int) $sh['id'], $edit['shop_ids'], true) : true; ?>
              <label class="d-block small"><input type="checkbox" class="form-check-input me-1" name="shops[]" value="<?php echo (int) $sh['id']; ?>" <?php echo $checked ? 'checked' : ''; ?>><?php echo svc_h($sh['name']); ?></label>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <input type="hidden" name="shops[]" value="<?php echo (int) TenantContext::tenantId(); ?>">
        <?php endif; ?>
        <?php if ($edit): ?>
          <label class="d-block small mb-2"><input type="checkbox" class="form-check-input me-1" name="inactive" value="1" <?php echo (int) $edit['active'] ? '' : 'checked'; ?>>Stopped offering this (hide it)</label>
        <?php endif; ?>
        <button class="btn btn-primary w-100"><?php echo $edit ? 'Save changes' : 'Add service'; ?></button>
        <?php if ($edit): ?><a class="btn btn-link w-100 mt-1" href="<?php echo $selfUrl; ?>">Cancel</a><?php endif; ?>
      </form>
    </div></div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Services &amp; prices <span class="text-muted fw-normal">(<?php echo count($services); ?>)</span></h2>
      <?php if (!$services): ?>
        <div class="text-muted small">No services yet — add the first one on the left.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr class="small text-muted text-uppercase"><th>Service</th><th class="text-end">Price</th><th class="text-center">Mins</th><th class="text-center">Commission</th><?php if ($multi): ?><th>Shops</th><?php endif; ?><th></th></tr></thead>
          <tbody>
          <?php foreach ($services as $s): ?>
            <tr class="small <?php echo (int) $s['active'] ? '' : 'text-muted'; ?>">
              <td class="fw-semibold"><?php echo svc_h($s['name']); ?><?php echo (int) $s['active'] ? '' : ' <span class="badge bg-secondary">hidden</span>'; ?><?php if ($s['description']): ?><div class="text-muted fw-normal"><?php echo svc_h($s['description']); ?></div><?php endif; ?></td>
              <td class="text-end"><?php echo svc_money($s['price']); ?></td>
              <td class="text-center"><?php echo $s['duration_min'] ?: '—'; ?></td>
              <td class="text-center"><?php echo $s['commission_pct'] !== null ? rtrim(rtrim((string) $s['commission_pct'], '0'), '.') . '%' : '—'; ?></td>
              <?php if ($multi): ?><td><?php echo svc_h(implode(', ', array_map(fn($t) => $shopName[$t] ?? '?', $s['shop_ids']))); ?></td><?php endif; ?>
              <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?php echo $selfUrl . '?edit=' . (int) $s['id']; ?>">Edit</a></td>
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
svc_render('Services & prices', ob_get_clean());
