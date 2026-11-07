<?php
// public/super/import/photos.php — add product photos in bulk (a zip or many
// images). Each photo finds its product by itself — see PhotoImport.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::ownerCan(Capabilities::INVENTORY_EDIT);

$pdo = Database::pdo();
$tenantId = (int) TenantContext::tenantId();
$tenant = (new Models\TenantModel($pdo))->find($tenantId);
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$_SESSION['photo_import_csrf'] ??= bin2hex(random_bytes(16));
$csrf = $_SESSION['photo_import_csrf'];

$bytes = function (string $v): int {
    $n = (int) $v; $u = strtolower(substr(trim($v), -1));
    return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n));
};
$maxUpload = min($bytes(ini_get('upload_max_filesize') ?: '2M'), $bytes(ini_get('post_max_size') ?: '8M'));
$maxFiles = (int) (ini_get('max_file_uploads') ?: 20);

$errors = [];
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES) && !empty($_SERVER['CONTENT_LENGTH'])) {
        $errors[] = 'That upload is larger than this server accepts (' . round($maxUpload / 1048576) . ' MB). Split the photos into smaller zips and upload them one after another — each upload adds to the last.';
    } elseif (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'This form expired. Please try again.';
    } else {
        @set_time_limit(300);
        $replace = !empty($_POST['replace']);
        $zip = $_FILES['zip'] ?? null;
        $many = $_FILES['photos'] ?? null;
        try {
            if ($zip && ($zip['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $result = PhotoImport::run($pdo, PhotoImport::fromZip($zip['tmp_name']), $replace);
            } elseif ($many && array_filter((array) $many['name'])) {
                $result = PhotoImport::run($pdo, PhotoImport::fromUploads($many), $replace);
            } else {
                $errors[] = 'Choose a zip of photos, or select the photo files.';
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$st = $pdo->prepare("SELECT COUNT(*) total, SUM(image_path IS NULL OR image_path = '') missing FROM products WHERE tenant_id = ? AND status <> 'archived'");
$st->execute([$tenantId]);
$counts = $st->fetch();

$page_title = 'Import photos';
ob_start();
?>
<div class="mb-3">
  <a href="<?php echo public_url('super/inventory/'); ?>" class="text-decoration-none text-muted">&larr; Inventory</a>
  <h1 class="h4 mt-1 mb-1">Import product photos</h1>
  <p class="text-muted mb-0">Upload many photos at once and each one finds its product by itself.
    <strong><?php echo (int) $counts['missing']; ?></strong> of <?php echo (int) $counts['total']; ?> products in <?php echo $h($tenant['name'] ?? 'this shop'); ?> have no photo yet.</p>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?php echo $h($e); ?></div><?php endforeach; ?>

<?php if ($result): ?>
  <div class="alert alert-success mb-3">
    <strong><?php echo (int) $result['matched']; ?> product<?php echo $result['matched'] === 1 ? '' : 's'; ?> got a photo.</strong>
    <?php if ($result['skipped_has_photo']): ?><?php echo (int) $result['skipped_has_photo']; ?> already had one and were left as they were.<?php endif; ?>
    <?php echo (int) $result['still_missing']; ?> product<?php echo $result['still_missing'] === 1 ? ' is' : 's are'; ?> still without a photo.
    <a href="<?php echo public_url('super/inventory/'); ?>">Open inventory →</a>
  </div>
  <?php if ($result['unmatched']): ?>
    <div class="alert alert-warning small mb-3"><strong><?php echo count($result['unmatched']); ?> photo<?php echo count($result['unmatched']) === 1 ? '' : 's'; ?> matched no product</strong>
      (name the file after the product's name or barcode to match it):
      <?php echo $h(implode(', ', array_slice($result['unmatched'], 0, 30))) . (count($result['unmatched']) > 30 ? ' …' : ''); ?></div>
  <?php endif; ?>
  <?php if ($result['bad']): ?>
    <div class="alert alert-warning small mb-3">Not a readable image (or over 8 MB): <?php echo $h(implode(', ', array_slice($result['bad'], 0, 20))); ?></div>
  <?php endif; ?>
<?php endif; ?>

<div class="row g-4">
  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
        <div class="mb-3">
          <label class="form-label fw-semibold">A zip of photos</label>
          <input type="file" name="zip" accept=".zip" class="form-control">
          <div class="form-text">Folders inside the zip are fine (e.g. your online store's <code>uploads.zip</code>).</div>
        </div>
        <div class="text-center text-muted small my-2">— or —</div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Select photo files</label>
          <input type="file" name="photos[]" accept="image/*" multiple class="form-control">
          <div class="form-text">Up to <?php echo $maxFiles; ?> photos per upload.</div>
        </div>
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" name="replace" value="1" id="replace">
          <label class="form-check-label small" for="replace">Replace photos that products already have</label>
        </div>
        <button class="btn btn-primary"><i class="fas fa-images me-1"></i>Upload &amp; match photos</button>
        <div class="form-text mt-2">This server accepts up to <?php echo round($maxUpload / 1048576); ?> MB per upload. Bigger sets: upload them in several parts — each part adds to the last.</div>
      </form>
    </div></div>
  </div>
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4 small">
      <h2 class="h6 fw-bold">How photos find their product</h2>
      <ol class="mb-2 ps-3">
        <li><strong>Imported from your online store:</strong> the photo keeps its original file name, so it goes straight back to its product.</li>
        <li><strong>By barcode or code:</strong> <code>6161101234567.jpg</code>, <code>pentagon-001.png</code></li>
        <li><strong>By product name:</strong> <code>Maasai headgear.jpg</code> (capitals, spaces and dashes don't matter)</li>
      </ol>
      <div class="text-muted">Products that already have a photo keep it, unless you tick "Replace".</div>
    </div></div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
