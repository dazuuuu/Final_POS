<?php
// public/super/import/store.php — bring products from an online-store
// database dump (.sql) and its photos (uploads.zip) into this shop.
// Step 1 uploads and shows a preview; step 2 imports. See StoreImport.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::ownerCan(Capabilities::INVENTORY_EDIT);

$pdo = Database::pdo();
$tenantId = (int) TenantContext::tenantId();
$tenant = (new Models\TenantModel($pdo))->find($tenantId);
$cur = $tenant['currency'] ?? 'KES';
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$base = public_url('super/import/store.php');
$_SESSION['store_import_csrf'] ??= bin2hex(random_bytes(16));
$csrf = $_SESSION['store_import_csrf'];
$work = ROOT_PATH . '/app/storage/imports';
if (!is_dir($work)) @mkdir($work, 0775, true);

$errors = [];
$result = null;
$job = $_SESSION['store_import'] ?? null; // ['dir' => …, 'rate' => …]
if ($job && !is_dir($job['dir'])) { $job = null; unset($_SESSION['store_import']); }

$bytes = function (string $v): int {
    $n = (int) $v; $u = strtolower(substr(trim($v), -1));
    return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n));
};
$maxUpload = min($bytes(ini_get('upload_max_filesize') ?: '2M'), $bytes(ini_get('post_max_size') ?: '8M'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'This form expired. Please try again.';
    } elseif (($_POST['step'] ?? '') === 'upload') {
        $sql = $_FILES['sql'] ?? null;
        if (!$sql || ($sql['error'] ?? 1) !== UPLOAD_ERR_OK) {
            $errors[] = empty($_FILES) && !empty($_SERVER['CONTENT_LENGTH'])
                ? 'The files are larger than this server accepts (' . round($maxUpload / 1048576) . ' MB). Upload the .sql alone, or ask your host to raise upload_max_filesize.'
                : 'Choose the store\'s .sql file.';
        } else {
            $dir = $work . '/store_' . $tenantId . '_' . bin2hex(random_bytes(6));
            @mkdir($dir, 0775, true);
            move_uploaded_file($sql['tmp_name'], $dir . '/dump.sql');
            $zip = $_FILES['zip'] ?? null;
            if ($zip && ($zip['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                move_uploaded_file($zip['tmp_name'], $dir . '/uploads.zip');
            } elseif ($zip && ($zip['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $errors[] = 'The photos zip did not upload (too large for this server?). You can still import without photos.';
            }
            $rate = max(0.0001, (float) ($_POST['rate'] ?? 1));
            $parsed = StoreImport::parse((string) file_get_contents($dir . '/dump.sql'));
            if (!$parsed['products']) {
                $errors[] = 'No products were found in that file. It needs the store\'s `products` table (INSERT INTO `products` …).';
                array_map('unlink', glob($dir . '/*') ?: []); @rmdir($dir);
            } else {
                $_SESSION['store_import'] = $job = ['dir' => $dir, 'rate' => $rate, 'reset' => !empty($_POST['reset_prices'])];
            }
        }
    } elseif (($_POST['step'] ?? '') === 'import' && $job) {
        @set_time_limit(300);
        $parsed = StoreImport::parse((string) file_get_contents($job['dir'] . '/dump.sql'));
        $zip = is_file($job['dir'] . '/uploads.zip') ? $job['dir'] . '/uploads.zip' : null;
        $result = StoreImport::run($pdo, $parsed, (float) $job['rate'], $zip, !empty($job['reset']));
        array_map('unlink', glob($job['dir'] . '/*') ?: []); @rmdir($job['dir']);
        unset($_SESSION['store_import']);
        $job = null;
    } elseif (($_POST['step'] ?? '') === 'cancel' && $job) {
        array_map('unlink', glob($job['dir'] . '/*') ?: []); @rmdir($job['dir']);
        unset($_SESSION['store_import']);
        header('Location: ' . $base); exit;
    }
}

$preview = $job ? StoreImport::parse((string) file_get_contents($job['dir'] . '/dump.sql')) : null;
$hasZip = $job && is_file($job['dir'] . '/uploads.zip');

$page_title = 'Import from online store';
ob_start();
?>
<div class="mb-3">
  <a href="<?php echo public_url('super/inventory/'); ?>" class="text-decoration-none text-muted">&larr; Inventory</a>
  <h1 class="h4 mt-1 mb-1">Import products from your online store</h1>
  <p class="text-muted mb-0">Upload the website's database file (<code>.sql</code>) and its photos (<code>uploads.zip</code>). Products, categories, subcategories, sizes, colours and photos come into <strong><?php echo $h($tenant['name'] ?? 'this shop'); ?></strong>.</p>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?php echo $h($e); ?></div><?php endforeach; ?>

<?php if ($result): ?>
  <div class="alert alert-success">
    <strong>Done.</strong> <?php echo (int) $result['created']; ?> product<?php echo $result['created'] === 1 ? '' : 's'; ?> added,
    <?php echo (int) $result['updated']; ?> updated<?php echo !empty($result['prices_reset']) ? ' (' . (int) $result['prices_reset'] . ' prices reset to the store\'s)' : ''; ?>, <?php echo (int) $result['photos']; ?> photo<?php echo $result['photos'] === 1 ? '' : 's'; ?> copied.
    Buying prices were not in the file — set them on each product to see profit.
    <?php if (!$result['photos']): ?>Now add the photos on <a href="<?php echo public_url('super/import/photos.php'); ?>">Import photos</a> — upload the store's uploads.zip and each photo goes to its product.<?php endif; ?>
    <a href="<?php echo public_url('super/inventory/'); ?>">Open inventory →</a>
  </div>
  <?php if ($result['errors']): ?>
    <div class="alert alert-warning"><strong>Not imported:</strong><ul class="mb-0"><?php foreach ($result['errors'] as $e): ?><li><?php echo $h($e); ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
<?php endif; ?>

<?php if (!$preview): ?>
<div class="card border-0 shadow-sm" style="border-radius:14px;max-width:680px;"><div class="card-body p-4">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
    <input type="hidden" name="step" value="upload">
    <div class="mb-3">
      <label class="form-label fw-semibold">1. Store database (.sql)</label>
      <input type="file" name="sql" accept=".sql,text/plain" class="form-control" required>
    </div>
    <div class="alert alert-light border small mb-3"><i class="fas fa-images me-1"></i><strong>Photos come next:</strong> after importing, open
      <a href="<?php echo public_url('super/import/photos.php'); ?>">Import photos</a> and upload the store's <code>uploads.zip</code> — any size; each photo goes to its product by itself.</div>
    <div class="mb-3" style="max-width:260px;">
      <label class="form-label fw-semibold">2. Price conversion</label>
      <div class="input-group"><span class="input-group-text">Store price ×</span><input type="number" step="0.0001" min="0.0001" name="rate" value="1" class="form-control"><span class="input-group-text">= <?php echo $h($cur); ?></span></div>
      <div class="form-text">Leave at <strong>1</strong> when the store already prices in <?php echo $h($cur); ?> (most shops). Only change it if the store prices in another currency, e.g. 129 for US dollars.</div>
    </div>
    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" name="reset_prices" value="1" id="resetPrices">
      <label class="form-check-label small" for="resetPrices"><strong>Reset prices to the store file's prices</strong> — for products already imported: their selling price is set to the file's price × the number above, replacing the price in the POS. Use this to undo a wrong conversion.</label>
    </div>
    <button class="btn btn-primary"><i class="fas fa-eye me-1"></i>Preview</button>
  </form>
</div></div>
<?php else: ?>
<div class="card border-0 shadow-sm mb-3" style="border-radius:14px;"><div class="card-body p-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
      <h2 class="h5 mb-0"><?php echo count($preview['products']); ?> products ready to import</h2>
      <div class="small text-muted"><?php echo count(array_unique(array_column($preview['products'], 'category'))); ?> categories ·
        <?php echo count(array_unique(array_filter(array_column($preview['products'], 'subcategory')))); ?> subcategories ·
        price × <?php echo $h($job['rate']); ?> = <?php echo $h($cur); ?><?php echo !empty($job['reset']) ? ' · <strong>prices of products already here will be reset</strong>' : ''; ?> · <?php echo $hasZip ? 'with photos' : '<span class="text-warning">no photos zip</span>'; ?>.
        Products already in this shop are updated, never duplicated: a missing price, code, category or photo is filled in, while POS stock and prices you changed in the POS stay as they are.</div>
    </div>
    <div class="d-flex gap-2">
      <form method="post"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="step" value="cancel"><button class="btn btn-outline-secondary">Cancel</button></form>
      <form method="post"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="step" value="import"><button class="btn btn-success"><i class="fas fa-file-import me-1"></i>Import <?php echo count($preview['products']); ?> products</button></form>
    </div>
  </div>
  <div class="table-responsive" style="max-height:520px;"><table class="table table-sm align-middle mb-0">
    <thead class="sticky-top bg-white"><tr class="text-muted small text-uppercase"><th>Code</th><th>Product</th><th>Category</th><th>Subcategory</th><th class="text-end">Store price</th><th class="text-end">Price (<?php echo $h($cur); ?>)</th><th class="text-end">Qty</th><th>Sizes / colours</th></tr></thead>
    <tbody>
    <?php foreach ($preview['products'] as $p): ?>
      <tr>
        <td class="small text-muted"><?php echo $h($p['code']); ?></td>
        <td class="small fw-semibold"><?php echo $h($p['name']); ?><?php echo $p['image'] ? '' : ' <span class="badge bg-light text-dark">no photo</span>'; ?></td>
        <td class="small"><?php echo $h($p['category']); ?></td>
        <td class="small"><?php echo $h($p['subcategory']) ?: '—'; ?></td>
        <td class="small text-end"><?php echo number_format($p['price'], 2); ?></td>
        <td class="small text-end fw-semibold"><?php echo number_format($p['price'] * $job['rate'], 2); ?></td>
        <td class="small text-end"><?php echo rtrim(rtrim(number_format($p['quantity'], 2), '0'), '.'); ?></td>
        <td class="small text-muted"><?php echo $h(implode(', ', array_merge($p['sizes'], $p['colors']))) ?: '—'; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div></div>
<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
