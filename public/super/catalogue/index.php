<?php
// public/super/catalogue/index.php — the owner's side of the public
// catalogue: copy / WhatsApp / email the link, see which products people
// like, and hide or delete comments.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::CATALOGUE_SEND);

$pdo = Database::pdo();
$F = new Models\CatalogueFeedbackModel($pdo);
$tid = (int) TenantContext::tenantId();
$selfUrl = public_url('super/catalogue/');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'hide' || $action === 'show') { $F->setHidden($tid, $id, $action === 'hide'); }
    elseif ($action === 'delete') { $F->delete($tid, $id); }
    header('Location: ' . $selfUrl);
    exit;
}

$tenant = (new Models\TenantModel($pdo))->find($tid);
$shopName = $tenant['name'] ?? 'Our Shop';
$catalogueUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
              . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
              . public_url('catalogue.php?shop=' . urlencode($tenant['slug'] ?? ''));
$products = (new Models\ProductModel($pdo))->catalogueForTenant($tid);
$withDesc = count(array_filter($products, fn($p) => trim((string) ($p['description'] ?? '')) !== ''));
$withPhoto = count(array_filter($products, fn($p) => !empty($p['image_path'])));
$comments = $F->recentForOwner($tid);
$top = $F->topLiked($tid);

$page_title = 'Share catalogue';
ob_start();
?>
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h1 class="h5 mb-0 fw-bold"><i class="fas fa-store me-2 text-primary"></i>Product catalogue</h1>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars($catalogueUrl); ?>" target="_blank" rel="noopener"><i class="fas fa-eye me-1"></i>Preview</a>
    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#shareCatalogueModal"><i class="fas fa-share-nodes me-1"></i>Share link</button>
  </div>
</div>

<div class="row g-4">
  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
      <div class="card-body p-4">
        <p class="small text-muted mb-2">Anyone with this link sees your logo, every product in stock with its photo, price, how many are left and its description. They can't change anything — they can only like a product or comment on it.</p>
        <div class="input-group">
          <input type="text" class="form-control" readonly value="<?php echo htmlspecialchars($catalogueUrl); ?>" id="catLink">
          <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('catLink').value);this.textContent='Copied';">Copy</button>
          <a class="btn btn-success" target="_blank" rel="noopener" href="https://wa.me/?text=<?php echo rawurlencode('See what ' . $shopName . ' has in stock: ' . $catalogueUrl); ?>"><i class="fab fa-whatsapp"></i></a>
        </div>
        <div class="d-flex gap-3 flex-wrap small mt-3">
          <span><strong><?php echo count($products); ?></strong> products showing</span>
          <span class="<?php echo $withPhoto < count($products) ? 'text-warning' : ''; ?>"><strong><?php echo $withPhoto; ?></strong> with photos</span>
          <span class="<?php echo $withDesc < count($products) ? 'text-warning' : ''; ?>"><strong><?php echo $withDesc; ?></strong> with descriptions</span>
        </div>
        <?php if ($withDesc < count($products) || $withPhoto < count($products)): ?>
          <div class="small text-muted mt-2">Add photos and descriptions from <a href="<?php echo public_url('super/products/'); ?>">Products</a> (or when recording stock) — they show up on the catalogue straight away.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:14px;">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3">Comments from customers</h2>
        <?php if (!$comments): ?>
          <div class="text-muted small">No comments yet.</div>
        <?php else: foreach ($comments as $c): ?>
          <div class="border-bottom py-2 <?php echo $c['is_hidden'] ? 'opacity-50' : ''; ?>">
            <div class="d-flex justify-content-between gap-2">
              <div class="small"><strong><?php echo htmlspecialchars($c['name'] ?: 'Customer'); ?></strong> on <strong><?php echo htmlspecialchars($c['product_name'] ?? 'a removed product'); ?></strong>
                <span class="text-muted">· <?php echo date('j M, g:i a', strtotime($c['created_at'])); ?></span><?php echo $c['is_hidden'] ? ' <span class="badge bg-secondary">hidden</span>' : ''; ?></div>
              <div class="d-flex gap-1 flex-shrink-0">
                <form method="post"><input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>"><input type="hidden" name="action" value="<?php echo $c['is_hidden'] ? 'show' : 'hide'; ?>"><button class="btn btn-sm btn-outline-secondary py-0"><?php echo $c['is_hidden'] ? 'Show' : 'Hide'; ?></button></form>
                <form method="post" onsubmit="return confirm('Delete this comment?');"><input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>"><input type="hidden" name="action" value="delete"><button class="btn btn-sm btn-outline-danger py-0"><i class="fas fa-trash"></i></button></form>
              </div>
            </div>
            <div class="small mt-1" style="white-space:pre-line;"><?php echo htmlspecialchars($c['body']); ?></div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3"><i class="fas fa-heart text-danger me-1"></i>Most liked</h2>
        <?php if (!$top): ?>
          <div class="text-muted small">No likes yet.</div>
        <?php else: foreach ($top as $t): ?>
          <div class="d-flex justify-content-between small border-bottom py-1"><span><?php echo htmlspecialchars($t['name']); ?></span><strong><?php echo (int) $t['likes']; ?></strong></div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/tenants/share_modal.php'; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
