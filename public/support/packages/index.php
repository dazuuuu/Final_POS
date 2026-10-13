<?php
// public/support/packages/index.php — the package catalog and which accounts hold each.
require_once __DIR__ . '/../_boot.php';

$pdo = Database::pdo();
$accounts = support_accounts($pdo);
$holders = array_fill_keys(array_keys(Features::catalog()), []);
foreach ($accounts as $a) {
    foreach (Features::forAccount((int) $a['id']) as $k => $on) {
        if ($on) $holders[$k][] = $a;
    }
}

$page_title = 'Packages';
$nav = 'packages';
ob_start();
?>
<h1 class="h4 mb-4">Packages</h1>
<div class="row g-3">
<?php foreach (Features::catalog() as $k => [$label, $price, $desc, $icon]): ?>
  <div class="col-lg-6">
    <div class="s-card h-100">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h5 mb-0"><i class="fas <?php echo $icon; ?> me-2 text-primary"></i><?php echo $label; ?></h2>
        <span class="badge bg-dark fs-6">KES <?php echo number_format($price / 1000); ?>K</span>
      </div>
      <p class="small text-muted"><?php echo htmlspecialchars($desc); ?></p>
      <h3 class="h6 mt-3"><?php echo count($holders[$k]); ?> account(s)</h3>
      <?php foreach ($holders[$k] as $a): ?>
        <a class="d-block small py-1 text-decoration-none" href="<?php echo public_url('support/accounts/view.php?id=' . (int) $a['id']); ?>"><?php echo htmlspecialchars($a['name']); ?> <span class="text-muted">· <?php echo htmlspecialchars($a['owner_email'] ?? ''); ?></span></a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../_layout.php';
