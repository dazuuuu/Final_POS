<?php
// public/super/services/catalog.php — owner: the price list of services,
// each with its price, duration, default commission and the shops offering it.
require_once __DIR__ . '/_common.php';
svc_require($canManage);

$selfUrl = public_url('super/services/catalog.php');
$shops = ShopContext::shops(true);
$multi = count($shops) > 1;

require_once ROOT_PATH . '/app/helpers/uploads.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? 'service';
    if ($act === 'category') {
        $res = $SD->saveCategory((string) ($_POST['cat_name'] ?? ''), (int) ($_POST['cat_id'] ?? 0) ?: null);
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Category saved.' : $res['error'];
    } elseif ($act === 'delete_category') {
        $SD->deleteCategory((int) ($_POST['cat_id'] ?? 0));
        $_SESSION['flash']['success'] = 'Category removed — its services are kept.';
    } else {
        $in = $_POST; $err = null;
        if (!empty($_FILES['image']['name'])) {
            $tid = (int) TenantContext::tenantId();
            $up = upload_image($_FILES['image'], PUBLIC_ROOT . '/assets/uploads/services/' . $tid, 'assets/uploads/services/' . $tid, ['jpg', 'jpeg', 'png', 'webp']);
            if ($up['ok']) { $in['image_path'] = $up['path']; } else { $err = $up['error']; }
        }
        $res = $err ? ['ok' => false, 'error' => $err] : $SD->saveService($in, (int) ($_POST['id'] ?? 0) ?: null);
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Service saved.' : $res['error'];
    }
    header('Location: ' . $selfUrl . (!empty($_POST['id']) && ($res['ok'] ?? false) === false ? '?edit=' . (int) $_POST['id'] : ''));
    exit;
}
$cats = $SD->categories();

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
      <form method="post" enctype="multipart/form-data">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>"><?php endif; ?>
        <?php if (!empty($edit['image_path'])): ?><img src="<?php echo svc_h(settings_asset_url($edit['image_path'])); ?>" alt="" class="d-block mb-2" style="height:70px;border-radius:10px;object-fit:cover"><?php endif; ?>
        <label class="form-label small mb-1">Picture <span class="text-muted">(optional — shows on the client cards)</span></label>
        <input type="file" name="image" accept="image/*" class="form-control form-control-sm mb-2">
        <label class="form-label small mb-1">Category</label>
        <select name="category_id" class="form-select mb-2"><option value="0">No category</option>
          <?php foreach ($cats as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo (int) ($edit['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : ''; ?>><?php echo svc_h($c['name']); ?></option><?php endforeach; ?></select>
        <label class="form-label small mb-1">Service</label>
        <input name="name" class="form-control mb-2" required maxlength="150" placeholder="e.g. Haircut, Full service, Manicure" value="<?php echo svc_h($edit['name'] ?? ''); ?>">
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label small mb-1">Price (KES)</label><input type="number" step="1" min="0" name="price" class="form-control" required value="<?php echo $edit ? (float) $edit['price'] : ''; ?>"></div>
          <div class="col-6"><label class="form-label small mb-1">Takes (minutes)</label><input type="number" step="5" min="0" name="duration_min" class="form-control" placeholder="optional" value="<?php echo svc_h($edit['duration_min'] ?? ''); ?>"></div>
        </div>
        <label class="form-label small mb-1">Staff commission <span class="text-muted">(default %, optional)</span></label>
        <div class="input-group mb-1"><input type="number" step="0.5" min="0" max="100" name="commission_pct" class="form-control" placeholder="e.g. 30" value="<?php echo svc_h($edit['commission_pct'] ?? ''); ?>"><span class="input-group-text">%</span></div>
        <div class="input-group mb-1"><span class="input-group-text">or KES</span><input type="number" step="1" min="0" name="commission_fixed" class="form-control" placeholder="fixed amount per job" value="<?php echo svc_h($edit['commission_fixed'] ?? ''); ?>"></div>
        <div class="form-text mb-1">A fixed amount is paid per job instead of the %.</div>
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
    <div class="card border-0 shadow-sm mb-3" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-2">Categories <span class="text-muted fw-normal small">(e.g. Hair, Nails, Spa)</span></h2>
      <div class="d-flex flex-wrap gap-2 mb-2">
        <?php foreach ($cats as $c): ?>
          <form method="post" class="d-flex gap-1 align-items-center border rounded-pill ps-2 pe-1 py-1">
            <input type="hidden" name="action" value="category"><input type="hidden" name="cat_id" value="<?php echo (int) $c['id']; ?>">
            <input name="cat_name" value="<?php echo svc_h($c['name']); ?>" class="form-control form-control-sm border-0 p-0" style="width:<?php echo max(70, strlen($c['name']) * 9); ?>px" maxlength="100">
            <span class="badge bg-light text-dark border"><?php echo (int) $c['n']; ?></span>
            <button class="btn btn-sm btn-link p-0 px-1" title="Save name"><i class="fas fa-check"></i></button>
            <button class="btn btn-sm btn-link text-danger p-0 px-1" title="Delete" formaction="<?php echo $selfUrl; ?>" name="action" value="delete_category" onclick="return confirm('Delete this category? Its services are kept.');"><i class="fas fa-xmark"></i></button>
          </form>
        <?php endforeach; ?>
      </div>
      <form method="post" class="d-flex gap-2"><input type="hidden" name="action" value="category">
        <input name="cat_name" class="form-control form-control-sm" maxlength="100" placeholder="New category" required style="max-width:240px"><button class="btn btn-sm btn-outline-primary">Add category</button></form>
    </div></div>
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
              <td class="fw-semibold"><?php if (!empty($s['image_path'])): ?><img src="<?php echo svc_h(settings_asset_url($s['image_path'])); ?>" alt="" style="width:34px;height:34px;object-fit:cover;border-radius:8px" class="me-2 align-middle"><?php endif; ?><?php echo svc_h($s['name']); ?><?php if (!empty($s['category_name'])): ?> <span class="badge bg-light text-dark border fw-normal"><?php echo svc_h($s['category_name']); ?></span><?php endif; ?><?php echo (int) $s['active'] ? '' : ' <span class="badge bg-secondary">hidden</span>'; ?><?php if ($s['description']): ?><div class="text-muted fw-normal"><?php echo svc_h($s['description']); ?></div><?php endif; ?></td>
              <td class="text-end"><?php echo svc_money($s['price']); ?></td>
              <td class="text-center"><?php echo $s['duration_min'] ?: '—'; ?></td>
              <td class="text-center"><?php echo ($s['commission_fixed'] ?? null) !== null ? svc_money($s['commission_fixed']) : ($s['commission_pct'] !== null ? rtrim(rtrim((string) $s['commission_pct'], '0'), '.') . '%' : '—'); ?></td>
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
