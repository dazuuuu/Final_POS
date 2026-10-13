<?php
// public/super/updates/index.php — Admin portal → Updates. Shows what each
// update brings in plain words and lets the owner apply pending ones.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::tenant(); // owners only

$pdo = Database::pdo();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    $res = Updates::runPending($pdo);
    $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok']
        ? ($res['ran'] ? 'Updated to version ' . end($res['ran']) . '. Everything below is ready to use.' : 'You are already up to date.')
        : $res['error'];
    header('Location: ' . public_url('super/updates/'));
    exit;
}

$applied = Updates::applied($pdo);
$pending = Updates::pending($pdo);
$all = array_reverse(Updates::all()); // newest first
$current = null;
foreach (Updates::all() as $u) { if (isset($applied[$u['version']])) { $current = $u['version']; } }

$page_title = 'Updates';
ob_start();
?>
<div class="card mb-4"><div class="card-body p-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
  <div>
    <div class="small text-muted text-uppercase fw-semibold">Installed version</div>
    <div class="h4 mb-0 fw-bold"><?php echo htmlspecialchars($current ?? 'Not yet updated'); ?></div>
    <div class="small <?php echo $pending ? 'text-warning' : 'text-success'; ?> mt-1">
      <?php echo $pending
          ? count($pending) . ' update' . (count($pending) === 1 ? '' : 's') . ' ready to install'
          : '<i class="fas fa-circle-check me-1"></i>You are up to date'; ?>
    </div>
  </div>
  <?php if ($pending): ?>
  <form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Updating…';">
    <input type="hidden" name="action" value="run">
    <button class="btn btn-primary btn-lg"><i class="fas fa-download me-1"></i>Install updates</button>
  </form>
  <?php endif; ?>
</div></div>

<?php if ($pending): ?>
<div class="alert alert-info small"><i class="fas fa-circle-info me-1"></i>Updating only prepares the system for the new features. Your products, sales, staff and money records are not changed or deleted. It takes a few seconds.</div>
<?php endif; ?>

<?php foreach ($all as $u): $done = isset($applied[$u['version']]); ?>
<div class="card mb-3"><div class="card-body p-4">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
    <div>
      <span class="badge <?php echo $done ? 'bg-success' : 'bg-warning text-dark'; ?> me-1"><?php echo $done ? 'Installed' : 'New'; ?></span>
      <strong>v<?php echo htmlspecialchars($u['version']); ?> — <?php echo htmlspecialchars($u['title']); ?></strong>
    </div>
    <span class="small text-muted"><?php echo $done ? 'Installed ' . date('j M Y', strtotime($applied[$u['version']])) : 'Released ' . date('j M Y', strtotime($u['date'])); ?></span>
  </div>
  <ul class="mb-0 small up-notes">
    <?php foreach ($u['notes'] as $n): ?><li><?php echo htmlspecialchars($n); ?></li><?php endforeach; ?>
  </ul>
</div></div>
<?php endforeach; ?>
<p class="small text-muted">New to the system? Read the <a href="<?php echo public_url('super/guide/'); ?>">Guide</a>.</p>
<style>.up-notes li{margin-bottom:6px;}</style>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
