<?php
// public/support/index.php — Support console overview.
require_once __DIR__ . '/_boot.php';

$pdo = Database::pdo();
$accounts = support_accounts($pdo);
$catalog = Features::catalog();
$perPackage = array_fill_keys(array_keys($catalog), 0);
$active = $suspended = $licensed = 0;
foreach ($accounts as $a) {
    $a['status'] === 'active' ? $active++ : $suspended++;
    foreach (Features::forAccount((int) $a['id']) as $k => $on) {
        if ($on) { $perPackage[$k]++; $licensed += $catalog[$k][1]; }
    }
}

$page_title = 'Overview';
$nav = 'overview';
ob_start();
?>
<h1 class="h4 mb-4">Overview</h1>
<div class="row g-3 mb-4">
  <?php foreach ([
      ['Accounts', count($accounts)], ['Active', $active], ['Suspended / cancelled', $suspended],
      ['Licensed value', 'KES ' . number_format($licensed)],
  ] as [$l, $n]): ?>
  <div class="col-6 col-lg-3"><div class="s-card s-stat"><div class="l"><?php echo $l; ?></div><div class="n"><?php echo $n; ?></div></div></div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="s-card h-100">
      <h2 class="h6 mb-3">Packages in use</h2>
      <?php foreach ($catalog as $k => [$label, $price, , $icon]): ?>
        <div class="d-flex justify-content-between border-bottom py-2">
          <span><i class="fas <?php echo $icon; ?> fa-fw me-2 text-primary"></i><?php echo $label; ?> <small class="text-muted">KES <?php echo number_format($price / 1000); ?>K</small></span>
          <strong><?php echo $perPackage[$k]; ?> accounts</strong>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="s-card h-100">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 mb-0">Latest accounts</h2>
        <a class="btn btn-sm btn-primary" href="<?php echo public_url('support/accounts/?new=1'); ?>"><i class="fas fa-plus me-1"></i>New account</a>
      </div>
      <?php if (!$accounts): ?><p class="text-muted mb-0">No accounts yet.</p><?php endif; ?>
      <?php foreach (array_slice($accounts, 0, 6) as $a): ?>
        <a class="d-flex justify-content-between align-items-center border-bottom py-2 text-decoration-none text-reset" href="<?php echo public_url('support/accounts/view.php?id=' . (int) $a['id']); ?>">
          <span><strong><?php echo htmlspecialchars($a['name']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($a['owner_email'] ?? '—'); ?></small></span>
          <span class="text-end"><?php echo support_package_badges((int) $a['id']); ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
